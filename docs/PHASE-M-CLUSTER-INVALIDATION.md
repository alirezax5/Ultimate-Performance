# Phase M5 — Cluster Page-Cache Invalidation (design)

Status: DESIGN — committed before implementation (milestone contract).
Scope: multi-node WordPress installations sharing ONE database with
INDEPENDENT per-node page-cache roots (the standard scaled-WP shape: several
web nodes behind a load balancer, shared DB + shared wp-content or shared
uploads, per-node cache trees).

## 1. Problem

The page cache is per-node filesystem state. When Node A renders and caches
`/page/`, Node B holds its own copy. Any invalidation decision made on Node A
(a post update, a comment, a purge-all) must remove the stale entry on EVERY
node sharing the database — or Node B serves stale content indefinitely.

The plugin's existing invalidation hooks already decide WHAT to purge (tags →
dirs via the Registry). What is missing is the wire: how that decision reaches
the other nodes.

## 2. Cluster membership model

- **Membership = shared database.** Every node that serves requests against
  the same DB (same `siteurl` host) is a cluster member. No registration
  protocol, no coordinator: the DB *is* the coordination point.
- **Node identity is per-NODE, file-based**: a UUID7 stored in
  `wp-content/cache/ultimate-performance/meta/node-id.json` (inside the per-node
  cache root). Independent cache roots ⇒ independent identities. The node id
  is the event `origin` field. Two nodes never share an identity because they
  never share a cache root; one node keeps its identity across restarts.

## 3. Event schema (versioned, self-describing)

Table `{base_prefix}uc_invalidation_events` (network-wide: base prefix;
created on activation via dbDelta-compatible CREATE TABLE):

| column       | type                | meaning                                          |
|--------------|---------------------|--------------------------------------------------|
| `id`         | BIGINT UNSIGNED PK AUTO_INCREMENT | strict monotonic sequence per cluster |
| `event_id`   | CHAR(36) UNIQUE     | uuid7 (dedup identity; also time-ordered)        |
| `schema_version` | SMALLINT        | payload schema version (1)                       |
| `origin`     | CHAR(36)            | node id of the producer                          |
| `epoch`      | BIGINT UNSIGNED     | chain epoch at production (stale-generation guard, mirrors the promotion fencing epoch) |
| `scope`      | VARCHAR(24)         | `purge_dirs` \| `purge_all`                      |
| `payload`    | TEXT                | scope-dependent; dirs = list of rel dirs, each RE-SANITIZED by the consumer via `Key::segment` (consumer-side full validation — the producer is never trusted) |
| `created`    | BIGINT UNSIGNED     | unix ms (lag metric)                             |
| `consumed`   | TINYINT             | 0 pending / 1 consumed                           |

- `purge_dirs` payload: `{"dirs":["<host>/<seg>/...","..."],"tags":["..."]}` —
  the consumer re-derives deletions ONLY from the dirs list, and only after
  re-sanitizing every segment (defense against a hostile or buggy producer).
- `purge_all` payload: `{}` — the consumer wipes its own tree. Gated behind
  the same capability check as the local purge-all.

## 4. Propagation: local-first, remote-async

1. **Local-first**: the deciding node executes its LOCAL invalidation
   synchronously (Registry → queue → local purge), exactly as today. The local
   path never waits for the cluster.
2. **Event write**: the same request inserts one event row (event_id uuid7).
   Write failure is recorded (metric `failures`) and never blocks the local
   purge — a lost event degrades to "other nodes serve a stale page until TTL"
   which is the documented, bounded worst case for a DB-outage scenario.
3. **Remote consumption**: each node's existing `ultimate_performance_tick`
   (plus the queue worker boot) consumes pending events where
   `origin != my_node_id` AND `consumed = 0`, in `id` order, in a bounded
   batch (default 200/tick, filterable), executes the local purge for each,
   marks `consumed=1`.
4. **HIT path**: unchanged — a cache HIT reads the filesystem only. There is
   no queue round-trip, no DB read, no cross-node dependency on the HIT path.
5. **Dedup/idempotency**: the consumer keeps a per-origin watermark
   (`last_id` in the node meta dir): events with `id <= watermark` for a
   consumed state are skipped without re-executing (bounded dedup — no unbounded
   table reads; the watermark makes replay idempotent). Events are pruned by
   the janitor (bounded table: consumed rows older than the retention window
   are deleted; pending rows are never pruned).
6. **Epoch guard**: events whose `epoch` is older than the consumer's current
   chain epoch are counted (`stale` metric) and skipped — a partitioned node
   rejoining after a promotion must not resurrect a purged generation.

## 5. Anti-thundering-herd (chosen option)

Of the three candidates — (a) jittered consumer warmup, (b) single-flight
warmup lock, (c) origin-only warmup — we implement **(c) origin-only warmup**:
only the node whose action caused the invalidation schedules warmup for the
purged URLs (existing Warmup platform, epoch-guarded, budget-capped).
Consumer nodes re-populate lazily on first MISS. Rationale: (c) needs no new
coordination, cannot stampede (one warmup set per cluster event), and preserves
the existing budget semantics; (a)+(b) would add cross-node locks to solve a
problem (c) already bounds.

## 6. Metrics (fixed schema, added to telemetry)

`cluster_events_published`, `cluster_events_consumed`,
`cluster_events_duplicates`, `cluster_events_failures`,
`cluster_events_stale`, `cluster_lag_ms` (now − `created` of the last consumed
event, sampled at consumption). Enum-only labels, bounded cardinality, same
telemetry channel as Phase K.

## 7. Burst behavior (verified live)

100-event and 1000-event bursts: producer writes N events; consumer batches
per tick (cap 200) — 1000 events ⇒ ≤5 ticks; lag measured end-to-end per
batch; table stays bounded (consumed rows pruned by the janitor, watermark
advances monotonically). No unbounded memory: the consumer reads by `id`
window, never the whole table.

## 8. Live gate (§56)

Two REAL WordPress nodes (independent docroots, independent cache trees,
independent node ids) sharing ONE MariaDB:
1. Both render the same permalink → both store.
2. Node A updates the post (wp-cli) → Node A tick → **A's own entry purged
   (local-first)** → Node B tick → **B's entry purged (cluster event consumed)**.
3. End-to-end latency recorded (update → B's entry gone).
4. Burst 100/1000: published/consumed/duplicates/lag asserted bounded.
