# Phase N — Durable Epoch Authority (design)

Status: DESIGN — committed before implementation (milestone contract, §7).
Scope: closes the Phase M runtime-only epoch vacuity (`0 < 0`), gives the
cluster a durable purge-generation authority, and turns "lost event →
TTL-bounded staleness" into "lost event → checkpoint-bounded
reconciliation".

This document states the invariants FIRST, then chooses the mechanism,
then defines every operating semantic the implementation must honor.

---

## 1. Problem statement

Phase M's cluster epoch guard compares an event's `epoch` (chain epoch of
the producing node's object cache) with the consumer's `current_epoch()`
(max chain epoch across healthy PERSISTENT backends). On a runtime-only
node (no persistent object-cache backend) `current_epoch()` is always 0,
so the guard evaluates `event.epoch < 0` — never true — and the guard is
VACUOUS. A runtime-only node therefore has NO working generation guard and
NO recovery floor: if it misses or loses an event row, it serves the
stale page until TTL.

The same gap exists cluster-wide for ANY node when event rows are lost
(manual deletion, DB restore, crash between publish steps): the M5
durability model is "the event table is the only signal".

## 2. Invariants (documented before the mechanism choice)

Any epoch authority for this system MUST provide:

1. **I1 Monotonic** — the authority never decreases; observations of a
   smaller value than a previously PROVEN value indicate authority
   reset, not progress rollback.
2. **I2 Cluster-visible** — where cluster invalidation is enabled, every
   node sharing the DB observes the same value (eventually: at its next
   read; there is no push).
3. **I3 Generation-immune** — independent of object-cache chain
   generations and of the promotion-fencing state machine (those remain
   local correctness tools; this authority is about PURGE generations).
4. **I4 Bounded** — one integer, one row (no per-event, per-node-unbounded
   growth).
5. **I5 Restart-safe** — node restart changes nothing (state lives
   outside the node).
6. **I6 Root-deletion-safe** — deleting a node's local cache root must
   not create stale pages; loss of local derived state self-heals.
7. **I7 Request-state-independent** — no dependence on request-local
   Memory object-cache state.
8. **I8 Fail-closed** — when the authority cannot be proven, the node
   must not CLAIM coverage (must not advance its checkpoint); when
   authority regression is detected, the node must reconcile.
9. **I9 HIT-path cost = 0** — no DB/network round-trip may be added to
   the page-cache HIT path or the healthy object-cache `get` path.
10. **I10 Under-invalidation never happens by design** — every
    uncertainty resolves toward MORE invalidation (over-invalidation is
    safe and bounded; under-invalidation is the release-blocking sin).

## 3. Mechanism choice (considered, with rejection reasons)

| Candidate | Verdict | Reason |
|---|---|---|
| Shared DB single-row counter | **CHOSEN** | Atomic increment is one statement (`LAST_INSERT_ID` idiom) on the SAME coordination point the cluster already trusts (the shared DB, M5 §2); survives restart/root-deletion; monotonic; bounded (one row); no HIT cost (only touched on publish/consume ticks); works identically for runtime-only nodes. |
| Cluster event HWM (`MAX(id)`) | rejected | The event table is PRUNED (janitor) and can be emptied manually; `MAX(id)` of an empty/pruned table regresses or resets → violates I1/I8 without extra bookkeeping; conflates "sequence of surviving rows" with "purge generations". |
| `wp_options` / network option | rejected | Options API is read-modify-write (races across nodes); option caches (object cache) would need careful non-persistent-group handling; hot counter rows in the options table pollute autoload caches; violates I1 under concurrency. |
| Shared persistent coordination record via object cache | rejected | Unavailable exactly where needed — runtime-only nodes have no persistent backend (that is the problem being solved); Redis/Memcached weak coordination would overstate guarantees. |

## 4. Architecture

### 4.1 Authoritative shared purge epoch — `Cluster\Epoch`

New single-row table `{base_prefix}uc_cluster_epoch`:

```sql
CREATE TABLE {base_prefix}uc_cluster_epoch (
    name CHAR(32) NOT NULL DEFAULT 'cluster',
    epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (name)
)
```

- `current()` → `SELECT epoch` (0 when row/table missing). Per-request
  cache; consumed/published paths only (I9).
- `bump()` → lazy `ensure_table()` + row ensure, then the MySQL atomic
  idiom:
  `UPDATE ... SET epoch = LAST_INSERT_ID(epoch + 1), updated_ms = {now}`
  followed by `SELECT LAST_INSERT_ID()` on the SAME connection → the
  caller receives the UNIQUE value its own increment produced, even
  under concurrent bumps (connection-scoped; MariaDB/MySQL proven live;
  the SQLite unit double emulates the pair atomically — dialect split
  proven live by the cluster runner exactly like M5's dbDelta split).
- Bump failure (DB unavailable) → returns 0/false; callers treat as
  "no generation signal exists" (I8: no coverage may be claimed).
- The authority is ONLY exercised on: publish (producer), consume tick
  (consumer), reconcile, CLI/admin status. Never on HIT (I9).

### 4.2 Purge generation on events — event schema v2

`{base_prefix}uc_invalidation_events` gains a column (dbDelta-upgraded
lazily by `ensure_table()`; SCHEMA_VERSION 1 → 2):

```sql
gen_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0   -- shared purge generation of THIS event
```

- v2 event = produced by Phase N: `gen_epoch ≥ 1`, the value this
  producer's own bump returned.
- v1 event (Phase M row) = `gen_epoch 0`: structurally valid, executed
  targeted as today, NEVER advances the checkpoint and NEVER counts as a
  gap (pre-N generations are outside the authority's range; their loss
  keeps the documented M5 TTL bound — bounded, disclosed, upgrade-safe).
- The `epoch` column KEEPS its M5 meaning (chain epoch mirror) — two
  orthogonal guards coexist (§4.6 of the M5 design remains for
  resurrection protection where a persistent backend exists).

### 4.3 Per-node checkpoint — `Cluster\Checkpoint`

`meta/cluster-checkpoint.json` (INSIDE the cache root's meta dir, which
is OUTSIDE the purgeable `v/` tree — consumer purge-all deletes `v/`
only):

```json
{"checkpoint": 12, "updated_ms": 0, "node": "<node-id>"}
```

- `read()` → checkpoint int; 0 when missing/corrupt/node-mismatch
  (fail-closed: a cloned/restored node reconciles from zero).
- `write(v)` → atomic temp+rename 0640 (same discipline as State).
- Node-bound: a checkpoint belonging to a different node id is invalid
  (clone regeneration path → fresh checkpoint → one reconcile).
- Loss (root deletion, purge_site) → checkpoint 0 → next tick reconciles
  once → self-heals (I6). Bounded over-invalidation, never staleness.

### 4.4 Reconciliation — the correctness floor

`Propagator::reconcile(reason)`:

1. read shared epoch S (best effort);
2. delete own `v/` tree (local purge-all; idempotent; NO warmup
   scheduling — lazy repopulation, anti-herd option (c) unchanged);
3. write checkpoint = S when S was readable; when S was NOT readable,
   checkpoint = last successfully OBSERVED S (see 4.6);
4. counters: `epoch_reconciliations`++, reason recorded in bounded
   telemetry (enum reason, no data).

Reconciliation is always SAFE (over-invalidation) and is triggered only
by proven-or-suspected coverage gaps — never routinely (§32: no silent
full-purge every node; manual reconcile is explicit).

### 4.5 Consumer algorithm (`Propagator::consume()` v2)

Per tick, cluster mode only:

```text
C  = checkpoint.read()                    # proven-coverage floor
evs= store.pending_for(my, batch)         # id ASC, bounded
split: v1 rows (gen=0) → targeted execute, chain-guard only (as M5)
       v2 rows → sort by gen_epoch ASC
expected = C + 1
for ev in v2 (gen ASC):
    if ev.gen <= C            → covered: mark_consumed, stale++
    elif ev.gen == expected   → chain-guard; targeted execute; C = ev.gen; expected++
    else (ev.gen > expected)  → GAP: reconcile("gap"); C = ev.gen - 1;
                                 then execute ev (no-op deletes), C = ev.gen; expected++
S  = epoch.current()                      # fresh read
if S unavailable:
    if C < S_last_observed → reconcile("authority-outage"); C = S_last_observed
    (no new coverage claims; bumps are impossible while the authority is
     down — the last observation stays the true frontier)
else if S < C:
    AUTHORITY RESET/REGRESSION → reconcile("authority-reset"); C = S
    counters: epoch_authority_failures++
else if S > C:
    pending_future = COUNT(consumed=0 AND gen_epoch > C)
    if pending_future == 0 → the (C, S] generations have NO surviving
    rows → LOST → reconcile("lost-tail"); C = S; counters: event_gaps++
    else → normal backlog; next ticks drain it (burst contract intact)
checkpoint.write(C)   (once per round; only when changed)
watermark/metrics/janitor: unchanged (M5 semantics)
```

Soundness notes:

- **Epoch-sorted processing**: ids reflect INSERT order which can
  interleave with bump order under concurrency; gen order is the true
  generation order (bumps are unique). Contiguity from C+1 proves
  targeted coverage; the first discontinuity proves a lost row (or a
  crash between bump and insert) and downgrades to reconcile.
- **Mid-batch gap** with the missing row merely pending beyond the batch
  cap (rare stall window): reconcile fires once — safe
  over-invalidation, bounded, disclosed (I10).
- **End-of-round discriminator** (pending_future COUNT) keeps the
  100/1000-burst contract: a large backlog drains targeted WITHOUT
  reconciling; only a genuinely empty (C, S] range reconciles.
- **Skip rule `ev.gen <= C` is sound** because C only ever advances to
  PROVEN-covered values (contiguous targeted chain or reconcile).

### 4.6 Failure semantics (fail-closed matrix)

| Scenario | Behavior |
|---|---|
| Authority table missing (fresh install) | current()=0, bump() creates row at 1; no spurious reconcile (C=0=S) |
| Bump fails (DB down) at publish | local purge unaffected (local-first); NO generation signal exists; other nodes keep M5 TTL bound for that purge; `failures`++ observable (I8) |
| Crash: bump done → local purge not run | gen exists, no event → peers see gap → reconcile; producer's own tick also reconciles (self-heal) |
| Crash: local purge done → publish not run | gen exists (bump precedes purge, §4.7) → peers reconcile via gap — CLOSES the M5 crash window |
| DB down at consume | current() unavailable → no checkpoint advance; if last-observed S > C → reconcile("authority-outage") once; bounded retry next tick |
| Authority reset / DB restored from backup (S < C) | forced reconcile + rebaseline C=S; `epoch_authority_failures`++ (release-blocking scenario proven in tests) |
| Checkpoint file corrupt/cloned | read()=0 → one reconcile → rewrite |
| Cache root deleted | checkpoint gone → C=0 → reconcile once (tree already empty → no-op) |
| Two nodes purge simultaneously | bumps are unique+atomic; each event carries its own gen; consumers process gen-sorted; both local purges independent — no collision |
| Event row(s) manually deleted | gap (mid-batch) or lost-tail (end-of-round) → reconcile |
| Corrupted/unknown payload or schema | unchanged from M5: structural validation, skip + count, never execute |
| Delayed old event after newer purge | `ev.gen <= C` → stale++ (covered); if not yet covered → executes (over-invalidation, safe) |

### 4.7 Producer ordering contract (transaction boundary, §13)

Fixed order per invalidation operation:

```text
1. WP content update commits          (WordPress; outside plugin)
2. dirs decided (Registry)
3. do_action ultimate_performance_before_purge_tags|purge_all
   → Propagator bumps the authority ONCE (G) and remembers it
4. local purge executes (queue sync-execution or async tick)
5. do_action ultimate_performance_after_purge_tags|purge_all
   → Propagator publishes the event with the REMEMBERED G
     (fallback: bump at publish when no remembered G — legacy callers)
6. consumers (all nodes incl. producer) drain events; checkpoint floor
```

Crash windows, explicitly:

- **after 3, before 4**: generation G exists, no event → peers
  reconcile; producer self-heals at its own tick. SAFE.
- **after 4, before 5**: same signal — CLOSES the M5 "local purge done,
  publish lost" TTL-staleness window (now checkpoint-bounded, not
  TTL-bounded).
- **after 5**: normal targeted flow + the floor as backstop.
- **before 3**: no signal exists ANYWHERE (nothing durable was written);
  peers stay TTL-bounded-stale — M5's documented worst case, unchanged,
  disclosed (a purge whose intent never touched durable state cannot be
  recovered from durable state).

No DB transaction spans unrelated WordPress state (the bump and the
insert are deliberately SEPARATE statements — the gap between them is
recoverable by design, and wrapping WP content writes in plugin
transactions is forbidden).

### 4.8 Boot semantics

- Cluster code registers exactly as M5 (late_boot, cluster listeners).
  No boot-time DB write: the epoch table and row are created LAZILY on
  first bump/read that needs them.
- Fresh install: authority starts at 0; first purge → 1. Checkpoint
  absent → C=0 → first tick with S=0: no reconcile (0=0), first event
  consumes contiguously.
- Upgrade from Phase M (v1 events exist, no authority table): S=0, C=0
  → no reconcile; v1 events drain targeted exactly as before; the first
  Phase N publish introduces gen 1. Zero-downtime, no purge storm.
- Runtime-only node: identical behavior (the authority never touches
  the object cache) — the vacuity is closed because the guard/checkpoint
  no longer depends on backend persistence at all.

### 4.9 Reset semantics

- **Local** (checkpoint/watermark/state lost): one self-healing
  reconcile, then normal operation (I6).
- **Authority** (epoch table dropped/reset/restored): detection at the
  next consume tick (S < C) → forced reconcile + rebaseline.
  Documented one-way cost: every node reconciles exactly once after an
  authority reset.

### 4.10 Multi-node semantics

- Membership = shared DB (M5 §2, unchanged).
- Concurrent bumps: unique generations via atomic UPDATE; interleaved
  insert order is handled by gen-sorted consumption (§4.5).
- Simultaneous purge on two nodes: both bump, both purge locally, both
  events consumed everywhere; no locks, no coordinator.
- Reconcile is a LOCAL operation (purge own tree) — concurrent
  reconciles are independent and idempotent.

## 5. Node identity hardening (clone detection)

`node-id.json` gains a persistent `boot_secret` (32 hex, random) beside
the node id. In cluster mode, each node maintains a lease row in a new
bounded table `{base_prefix}uc_cluster_nodes`:

```sql
node_id CHAR(36) PRIMARY KEY, boot_secret CHAR(32) NOT NULL,
first_ms BIGINT UNSIGNED NOT NULL, last_ms BIGINT UNSIGNED NOT NULL
```

- **Restored-image clone** (root copied BEFORE first registration):
  both nodes carry the same (id, secret); detection via CONCURRENT
  INSTANCE PROBE: each process holds an ephemeral per-boot
  `instance_nonce` (memory-only). On every tick the node upserts its
  lease (secret, instance_nonce, last_ms). If the stored row shows a
  DIFFERENT instance_nonce with `last_ms` inside a bounded freshness
  window (default 10s, filterable) — another LIVE process is using this
  id → collision → regenerate identity (new uuid7 + secret), rewrite
  node-id.json, `node_id_collisions`++, continue as the new node (old
  events remain consumable; origin history is append-only).
- **Post-registration clone** (secret written to DB before copy): the
  clone's file secret vs the DB row secret mismatch → regenerate. (The
  original's secret wins: first registrar keeps.)
- Non-concurrent reuse (node restored while the original is OFF) is
  legitimate id reuse — harmless, documented.
- Bounded rows: janitor prunes lease rows with `last_ms` older than the
  retention window (default 30 days) — fleet-sized, never unbounded.
- Non-cluster deployments never touch the lease table (no new DB
  dependency).
- Simultaneous regeneration of both clones: each generates a fresh
  uuid7 — collision probability negligible and the NEXT probe round
  detects a (different-id) re-collision; documented, not probabilistic
  for the DETECTION itself (the probe is deterministic given overlap).

## 6. Observability v3 (§29) and admin/CLI (§30–32)

- `Cluster\State::COUNTERS` extended (schema-locked):
  `epoch_reconciliations`, `epoch_authority_failures`, `event_gaps`,
  `watermark_resets`, `node_id_collisions` (+ existing five).
  Telemetry snapshot/prometheus mapping extended; telemetry SCHEMA 2 → 3
  (fixed keys only; enum-only labels; no high-cardinality anything).
- `watermark_resets`: consumer detects watermark file absence with
  non-empty pending history for that origin (bounded heuristic,
  documented) — counter only, behavior unchanged (replay is harmless).
- Admin health panel (cluster section): authority epoch, local
  checkpoint, last reconciliation (time + reason enum), gap state
  (none/backlog/lost-tail), watermark age, node identity (id + lease
  age), counters. No filesystem paths, no credentials, no raw event
  payloads.
- WP-CLI (reusing service classes; JSON bounded and secret-free):
  `uc cluster status [--format=json]`, `uc cluster epoch`,
  `uc cluster events [--limit=N] [--format=json]`,
  `uc cluster reconcile [--force]` (explicit, idempotent, reports exact
  result: reconciled true/false + checkpoint before/after).
- Manual reconcile via admin action: capability `CAP_PURGE_ALL` + nonce,
  validates scope, idempotent, reports exact result; never a silent
  cluster-wide purge (each node reconciles ITSELF).

## 7. Performance contract (§37–38)

- Page-cache HIT path: **zero new queries** (unchanged code path).
- Object-cache healthy get: zero new queries (authority not consulted).
- Publish: +1 single-row UPDATE (+SELECT LAST_INSERT_ID) per event.
- Consume tick: +1 SELECT (authority) + 1 SELECT COUNT (pending_future,
  only when S > C) + 1 lease UPSERT pair + 1 checkpoint file write per
  round (not per event).
- Reconcile: one local tree delete; lazy repopulation.
- Bounds to be MEASURED in N6 (engineering numbers, not claims):
  epoch recheck bound (= tick cadence), reconcile duration,
  offline-node recovery latency, consume latency vs M5.

## 8. Security posture (§40–41)

- Epoch spoofing/rollback: the authority is writable only through the
  plugin's DB user via plugin code; any attacker with DB write access
  already owns the application (documented threat model — no
  cryptographic signing added; §41).
- Event authenticity: every row is re-validated structurally
  (schema/scope/payload/dirs re-sanitized consumer-side, M5 unchanged);
  v2 adds gen_epoch structural validation (positive integer).
- Event injection/replay: watermark + proven-coverage checkpoint make
  replay idempotent; injection executes only after full re-validation.
- Cross-site: base-prefix table + host-prefixed dirs (unchanged).
- Probe SSRF / proxy-host confusion / uninstall over-delete: existing
  M3/M6 controls; §27 diagnostics hardening is classification-only
  (bounded, secret-free) and grants no new trust to responses.

## 9. What this design deliberately does NOT claim

- No exactly-once delivery (at-least-once + idempotent consume,
  documented; §10).
- No zero-staleness under total authority unavailability BEFORE any
  signal was durably written (crash before the bump — §4.7 last bullet;
  TTL bound remains, disclosed).
- No downgrade path (Phase M consuming v2 rows: unknown schema → skip +
  count stale — safe; documented one-way state change, §44).
- No cluster signing/auth layer (threat model: DB write = full
  compromise; §41).

---

## 10. N2 addendum — event durability details (implemented)

### 10.1 Delivery model (binding)

AT-LEAST-ONCE + idempotent consume. No exactly-once claim is made
anywhere in code, docs, or metrics. Duplicates are harmless by
construction (two independent dedup layers — see 10.2); delayed events
are safe (worst case over-invalidation); lost events are recovered by
the checkpoint floor (§4.5).

### 10.2 Dedup layers (and the N2-D1 soundness fix)

- **v1 rows (Phase M, id-ordered pass)**: per-origin LAST-ID watermark
  (M5 semantics — the id-order assumption HOLDS there).
- **v2 rows (gen-ordered pass)**: dedup by GENERATION against the
  durable checkpoint (`gen ≤ C` = proven covered → stale-skip). The
  per-origin last-id watermark is deliberately NOT consulted for v2
  rows: id order ≠ gen order, so an id-based veto can flag a
  never-executed row as a duplicate (false duplicate = lost targeted
  purge). Discovered by the R8 reordered-rows test and fixed before any
  live run; recorded as defect N2-D1 in the Phase N final report. The
  watermark file is still advanced on v2 execution (observability and
  M5 file contract), but it never VETOES a v2 row.

### 10.3 Payload chunking (huge payload injection)

A single purge_dirs payload is capped at
`ultimate_cache_cluster_payload_max` (default 60000 bytes, hard bounds
1024..65000) — comfortably under the shared table's TEXT column, whose
overflow would fail the INSERT and lose the targeted signal. Oversized
dir lists are split into size-bounded chunks; the first chunk reuses
the operation's remembered generation, every further chunk bumps its
own (contiguity preserved). Crash between chunks → the unpublished
chunks' generations floor-reconcile (safe over-invalidation). Chunk
failure injection (R13) proves partial publication stays recoverable.

### 10.4 Producer transaction boundary — crash-point ledger (§13, verified)

| Crash point | Durable state left | Recovery |
|---|---|---|
| before any durable write | none | no signal exists; peers stay TTL-bounded (M5 worst case, documented); consumer rounds do nothing harmful |
| after bump, before local purge | generation S advanced, no event row, nothing deleted | peers + producer reconcile via lost-tail floor |
| after local purge, before publish | generation S advanced, local tree purged, no event row | peers reconcile via floor — the M5 TTL-staleness window is CLOSED |
| after publish of chunk k of n | gens k claimed, k+1..n missing | floor reconciles the unclaimed span |
| after publish | event row pending; producer claimed coverage | normal targeted flow |
| after epoch bump / authority ops only | normal | normal |

No database transaction spans unrelated WordPress state; bump and
insert are deliberately separate statements (the gap between them is
recoverable by design).

### 10.5 ensure_table caching discipline

Positive table-existence proofs are cached per instance for a bounded
TTL (60 s); negative results are NEVER cached (an outage must not
poison a long-lived worker — the next call re-probes). A dropped or
restored table self-heals within the TTL plus one round.

### 10.6 N2 verification matrix (tests/audit-event-recovery.php)

R1–R13: single/multi row deletion, DB loss during publish/consume,
crash-point ledger, DB-level duplicate rejection (UNIQUE event_id),
reordered rows (gen-order vs id-order), corrupted payload, huge payload
chunking, delayed-event covered-skip, redelivery idempotency, chunk
crash window. Local invalidation succeeds in every scenario; remote
correctness recovers through the floor. (MySQL dialect incl. the TEXT
ceiling proven live by the cluster runner.)
