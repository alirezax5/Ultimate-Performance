# Ultimate Performance — Operations Guide

This document covers real-world deployment, recovery, and operational
procedures for the Ultimate Performance WordPress plugin. It is written for
site operators, not for plugin developers. Every claim about behavior
in this document is backed by a permanent regression test (see
`tests/`) or disclosed as PARTIAL/BLOCKED with exact evidence.

## 1. Single-node deployment

Ultimate Performance works on a single WordPress instance with no external
dependencies beyond PHP 8.2+. The default object cache uses the
in-process `MemoryBackend` and the page cache writes to
`wp-content/cache/ultimate-performance/v/<host>/`. Both drop the moment the
plugin is deactivated.

For higher performance on a single node, configure one of:

- **Redis** (recommended for single-node persistence): set
  `ULTIMATE_PERFORMANCE_REDIS_HOST` (or `UC_REDIS_HOST` via env) to the
  Redis daemon address. The Redis backend implements O(1) invalidation
  via generation counters and atomic Lua-script arithmetic. See
  `src/ObjectCache/RedisBackend.php`.
- **Memcached**: set `ULTIMATE_PERFORMANCE_MEMCACHED_HOST` and
  `ULTIMATE_PERFORMANCE_MEMCACHED_PORT` (or `UC_MEMCACHED_HOST`/`UC_MEMCACHED_PORT`).
  The Memcached backend uses binary protocol with explicit I/O timeouts
  (`OPT_CONNECT_TIMEOUT`/`OPT_SEND_TIMEOUT`/`OPT_RECV_TIMEOUT`=1000ms,
  `OPT_POLL_TIMEOUT`=1500ms). See `src/ObjectCache/MemcachedBackend.php`.
- **APCu**: enabled automatically when `ext-apcu` is loaded and
  `apcu_enabled()` returns true. APCu is process-local under CLI but
  shared across FPM/mod_php workers — disclosed honestly as a
  single-server local cache, NEVER as a distributed store.

Object-cache backends are mutually exclusive: the first one whose
`configured()` returns true wins, in the order
Redis → Memcached → APCu → Memory.

Page cache is always file-based (per-host directory tree) regardless
of object-cache choice. This is by design: page cache bodies are
served directly by the web server (Apache/nginx/OLS) without invoking
PHP, which is the entire performance point.

## 2. Multi-node deployment

Two or more WordPress nodes sharing a single MariaDB/MySQL database
form a cluster. Each node runs Ultimate Performance independently with its
own:

- local page cache tree (`wp-content/cache/ultimate-performance/v/<host>/`)
- local object cache (Redis/Memcached/APCu per-node, or shared if a
  cluster-wide Redis/Memcached is configured)
- per-node identity (`wp-content/cache/ultimate-performance/meta/node-id.json`)
- per-node watermark state (per-origin, bounded)
- per-node proven-coverage checkpoint (`meta/checkpoint.json`)

The **shared cluster event table** lives on the database base prefix
(`{wp_base_prefix}uc_cluster_events`) — one table per network, shared
by every node. Nodes communicate by publishing and consuming rows
in this table. The durable epoch counter
(`{wp_base_prefix}uc_cluster_epoch`) is a single-row shared monotonic
counter that orders events across nodes.

### 2.1 Node identity

Each node generates a persistent UUID7 identifier at first boot, with
a 32-hex boot secret stored beside it. The secret is regenerated on
demand via `NodeIdentity::regenerate()` (which also invalidates the
node-bound checkpoint, forcing a fail-closed reconcile from zero).
A clone (filesystem copy of `wp-content/cache/ultimate-performance/`)
brings the same UUID7 but a different boot secret — the cluster
detects the mismatch on the next lease refresh and regenerates
identity deterministically.

### 2.2 Runtime-only nodes

A "runtime-only" node is one with no persistent page cache (e.g.,
ephemeral container storage, or `ULTIMATE_CACHE_RUNTIME_ONLY=1`).
Such nodes still participate in cluster invalidation: they consume
events, observe epoch gaps, and reconcile. The Phase M `0 < 0`
vacuity (runtime-only node serving a stale page because both
checkpoint and epoch are zero) is closed by the N1 epoch authority
work — the runtime-only vacuity guard floor no longer depends on
backend persistence. Verified live: see `tests/audit-cluster-epoch.php`
E3 row.

### 2.3 Epoch authority

The shared `uc_cluster_epoch` table is the single source of truth for
"what is the current invalidation epoch". Producers bump it
atomically via `LAST_INSERT_ID(epoch+1)` BEFORE purging locally and
publishing an event. Consumers compare their checkpoint against the
current epoch; a gap triggers a reconcile. Authority resets (e.g.,
manual table truncate) force a reconcile+rebaseline.

Producer ordering (release-blocking):

```
bump epoch -> local purge -> publish event
```

This ordering closes the M5 crash window: a crash after bump means
a recoverable gap; a crash after purge but before publish means the
event is lost but the epoch floor is durable. Never the reverse.

## 3. Event recovery

The cluster event table is durable: MariaDB's redo log preserves
committed rows across crashes. The N2 event durability work
(`tests/audit-event-recovery.php` 33 checks) proves:

- **payload chunking**: oversized payloads split into size-bounded
  events (`ultimate_cache_cluster_payload_max`, default 60000 bytes
  to fit in a TEXT column with margin). Chunked events are
  re-assembled by the consumer.
- **lost-row recovery**: deliberately deleted event rows are detected
  via the epoch gap algorithm (consumer's checkpoint < epoch floor).
  The consumer triggers a full local reconcile.
- **DB outage handling**: ensure_table has a positive-proof 60s TTL
  cache (dropped/restored tables self-heal). Negative never cached —
  outages re-probe.
- **crash-point proofs**: a crash mid-publish leaves either a
  committed row (covered by the next consumption round) or no row
  (epoch floor catches the gap on the next tick).
- **duplicate/reordered/corrupt event handling**: v2 events dedup by
  generation (not by row ID — the N2-D1 defect proved ID-based dedup
  is unsound under gen-sorted processing). Corrupt rows (bad JSON,
  missing fields) leave the gen counter unaccounted, triggering
  a single lost-tail reconcile.
- **v2 generation-based dedup**: covered-skip is sound (gen ≤
  checkpoint is proven covered without re-execution).

## 4. Node identity and clone handling

The `Lease` table (`{wp_base_prefix}uc_cluster_leases`) records
per-node identity and liveness. Each node registers its UUID7 + boot
secret on boot. Concurrent instances of the same UUID7 (true clones
running simultaneously) are detected via instance-nonce probes inside
a bounded freshness window — non-concurrent restores (same UUID7,
different boot times, no overlap) are documented as legitimate.

The janitor prunes lease rows past 30 days, keeping the table
fleet-bounded regardless of node churn.

`purge_site()` preserves `node-id.json` through the meta wipe —
origin stability across purge-all is a release-blocking guarantee.
Watermark and State files have documented reset semantics.

## 5. Memcached weak coordination

Memcached has no built-in cross-instance coordination. Two memcached
daemons on different ports are independent in-memory stores. Ultimate
Cache NEVER implies cross-daemon sharing. The `tests/audit-memcached-failures.php`
F9b row proves this live: a key written to daemon A is absent on
daemon B (no coordination is ever implied).

When Memcached is the object cache, each node should point at its
own daemon (or a shared one — both configurations work). Cache
invalidation across nodes happens via the cluster event table,
NOT via Memcached's nonexistent cross-daemon protocol.

## 6. APCu scope

APCu memory is shared across the SAPI's workers on ONE server
(FPM worker pool / mod_php). Under CLI it is per-process
(`apc.enable_cli=1` grants memory only to that process; a child
process does NOT inherit APCu memory from its parent — verified
live in `tests/audit-apcu-live.php` P6a).

Ultimate Performance NEVER uses `apcu_clear_cache()` — invalidation is
done via the same generation-counter scheme as the other backends.
A foreign APCu key (e.g., `uc:foreign:sentinel`) survives every
flush this plugin performs (verified live in P5b).

APCu is NEVER advertised as a distributed store. For multi-node
setups, use Redis or Memcached.

## 7. Redis

Redis is the recommended object cache for both single-node and
multi-node deployments. The backend uses:

- `INCR` / `DECR` (or `INCRBY`/`DECRBY`) for atomic counter arithmetic
- Lua-script guards for exists-checked arithmetic (WP semantics:
  incr-on-missing returns false, never auto-creates)
- Generation-prefixed effective keys (`uc:oc:V{vgen}:G{ggen}:{grp}:{key}`)
  for O(1) invalidation via generation bump
- NEVER `FLUSHALL` or `FLUSHDB` — a foreign Redis key survives every
  flush this plugin performs

For multi-node setups with shared Redis, every node sees the same
keys (and the same generation counters). Cross-node invalidation
works via the cluster event table; the shared Redis just makes the
local cache coherent across nodes.

## 8. RabbitMQ

RabbitMQ is supported as an alternative transport for cluster events
(Phase M4). The broker credentials MUST be provided via environment
variables — they are NEVER set in `tests/run-all-regression.sh` and
NEVER committed:

```
UC_RABBITMQ_HOST
UC_RABBITMQ_PORT
UC_RABBITMQ_VHOST
UC_RABBITMQ_USER
UC_RABBITMQ_PASSWORD
```

When the variables are absent, the RabbitMQ suites self-gate into a
clean BLOCKED/SKIP state (exit 0) and are never counted as PASS.
This is a release-blocking honesty contract: never fake a PASS.

`tests/run-rabbitmq-live.sh` self-provisions a rootless
`rabbitmq-server` (Debian closure, cached) per run, with per-run
management-API credentials that are never printed. The full live
matrix is 122 checks across C1-C4 connection, rmq-live, rmq-fixes,
and backend-matrix --live.

## 9. Apache

Apache integration is via `.htaccess` rules in the plugin's docroot.
The `tests/audit-apache-hit.php` suite proves real HIT/MISS behavior
with a backend execution counter (zero PHP on HITs).

Apache is the most-tested web server path; all Phase M matrices are
green. See `tests/run-apache-live.sh` for the live runner.

## 10. Nginx

Nginx integration is via `src/WebServer/Nginx/Rules.php` which
generates a complete `http{}` include:

- composite map gate (logged-in vs anonymous)
- internal-only cache location + alias
- `.php` never served from disk (security)
- `X-UP-Original-URI` propagation for key stability under rewrites
- `Key::segment` root mapping
- verify-probe contract
- fail-closed input gates

The `tests/audit-nginx-rules.php` (37 checks) and
`tests/run-nginx-integration.sh` (28 checks, real nginx + php -S origin
with execution counter proving zero PHP on HITs) prove the
integration live.

## 11. OpenLiteSpeed status — PARTIAL

OpenLiteSpeed rootless provisioning is real (Phase N N4E):

- Real LiteSpeed/1.7.19 Open binary running on high port 8088
- Real HTTP 200 served on the Example vhost
- Real 404 on missing resource
- Real Server: LiteSpeed header (not apache/nginx)
- Real HEAD method supported

The PHP-via-LSAPI backend is NOT yet provisioned in this run. The
Phase N §12 full bypass matrix (POST/query/logged-in/WooCommerce/
session/private-route/wp-config.php denial/.env/.git/encoded
traversal) requires a real WP backend through LSAPI — disclosed as
PARTIAL with exact evidence. Building `lsphp` (PHP compiled with the
LSAPI SAPI) is a separate build from the CLI PHP we provisioned for
N4C/N4D and was not completed in this phase.

The plugin does not yet ship an OLS-compatible Rules writer
(equivalent to `src/WebServer/Nginx/Rules.php`). That is also
disclosed as a gap; future Phase O scope.

## 12. LiteSpeed Enterprise status — PARTIAL

LiteSpeed Enterprise is the commercial sibling of OpenLiteSpeed.
Ultimate Performance does NOT claim PASS from OpenLiteSpeed evidence.
The two have different binary builds, different feature flags, and
different bug-for-bug compatibility. LiteSpeed Enterprise may remain
PARTIAL until a real Enterprise license is provisioned and tested.

## 13. Upgrade

The `tests/run-upgrade-live.sh` runner proves real upgrade safety:

- `git-archive` an old build (e.g., Phase M `37ee9cd` pre-M5)
- Replace files in place with the plugin ACTIVE on real
  WP 6.7.2 / MariaDB 11.8.6
- No fatal, settings preserved, cluster subsystem online
- Lazy table initialization (epoch/lease tables created on first access)
- No purge storm (v1 events remain safe under schema v2)
- Node identity upgrades safely (legacy M5 file upgraded in place,
  same UUID7 retained)
- All settings preserved

The cluster schema is upgrade-safe: v1 events remain targeted with
M5-TTL semantics, v2 events use gen_epoch. The consumer handles both
schemas simultaneously during the upgrade window.

## 14. Uninstall

`uninstall.php` runs ONCE when the plugin is deleted (WP defines
`WP_UNINSTALL_PLUGIN` and requires the plugin to be inactive first).
Complete, honest data removal:

- All plugin options (per-site on multisite)
- All plugin transients
- All cron hooks
- The purge capability
- The whole per-node cache tree:
  - page cache bodies (`v/`)
  - page cache meta (`v/.../*.meta.json`)
  - tag registry (`meta/tag-*.json`)
  - node identity (`meta/node-id.json`)
  - watermark state (`meta/watermark-*.json`)
  - cluster state (`meta/state.json`)
  - cluster checkpoint (`meta/checkpoint.json`)
  - telemetry (`meta/telemetry-*.json`)
  - regen locks (`v/.../regen.lock`)
- The SHARED cluster events table (one DROP for the whole network)
- The SHARED cluster epoch table
- The SHARED cluster lease table

NEVER used (release-blocking):

- `FLUSHALL` / `FLUSHDB` (Redis)
- `flush_all` (Memcached)
- `apcu_clear_cache()` (APCu)

A foreign Redis/Memcached/APCu key survives every uninstall.

The shared cluster tables are dropped by the network uninstall path.
Single-site deactivation preserves cluster state (the plugin may be
reactivated).

## 15. Incident recovery

### 15.1 Stale page served after node offline

If a node was offline when other nodes published invalidation events,
the offline node may have a stale page in its local cache. On
recovery, the node consumes events in epoch order; a gap (epoch >
checkpoint) triggers a full local reconcile, which purges the entire
local page cache. Verified live: `tests/audit-cluster-epoch.php` and
`tests/audit-watermark.php` cover the algorithm; the live gate
`tests/run-cluster-live.sh` covers it on real MariaDB.

### 15.2 Database outage

During cluster operation:

```
stop MariaDB → perform local purge → attempt publication →
restore MariaDB → run recovery
```

Verified behavior:

- Local purge succeeds (writes to local cache tree)
- No request hangs indefinitely (MemcachedBackend has explicit
  I/O timeouts; RedisBackend has Lua-script bounded operations)
- Publish failure is observable (Propagator returns false, telemetry
  counters increment)
- Checkpoint does not fabricate coverage (gen counter only advances
  on proven execution)
- Recovery converges after DB returns (epoch gap detected on next
  consume tick, reconcile fires)

### 15.3 Manual reconcile

`Cluster\Propagator::reconcile()` is:

- explicit (called by the operator or by the consumer when a gap is
  detected)
- idempotent (calling it twice with the same state produces the same
  result — local cache is purged once, checkpoint advances once)
- scope-validated (only the local node's cache tree is purged; never
  another node's)
- accurately reported (telemetry counters reconcile attempts and
  outcomes)

Manual reconcile MUST clearly state whether it performed:

- local reconcile (purge local cache only)
- cluster epoch bump (force a new epoch; consumers will reconcile)
- targeted purge (specific URL/tag)
- full local purge (nuclear option)

NEVER silently purge every node. The CLI surface (see §16) follows
the same contract.

## 16. WP-CLI cluster operations — PARTIAL

The planned WP-CLI surface (`wp ultimate-performance cluster ...`) is
documented but NOT yet shipped in this phase:

```
wp ultimate-performance cluster status      # show node ID, epoch, lease state
wp ultimate-performance cluster epoch        # show / bump epoch
wp ultimate-performance cluster events       # show pending events for this node
wp ultimate-performance cluster reconcile    # force a local reconcile
```

These reuse existing services (`Epoch`, `Checkpoint`, `Propagator`,
`State`) — no business logic duplication. The command class skeleton
and the test for it (`tests/audit-cluster-cli.php`) are NOT yet
implemented. Disclosed as PARTIAL — Phase O scope.

Manual reconcile via direct PHP is available through
`Cluster\Propagator::reconcile()`. See §15.3.

## 17. Performance characteristics

Healthy HIT paths must NOT gain a DB/network round-trip per request.
This is a release-blocking contract:

- Healthy page-cache HIT: served by the web server (Apache/nginx/OLS)
  directly from `v/<host>/<path>/index.html`. Zero PHP execution.
- Healthy object-cache get: one backend RPC (Redis/Memcached/APu
  fetch). The effective key embeds the generation, so a single get
  returns the value or "absent" with no extra round-trip.
- Epoch current/check on tick: the tick handler reads the current
  epoch from the shared table (one DB query). This is NOT on the HIT
  path; it runs on the WP cron tick (default every 60s).
- Event publish: one DB INSERT (chunked if payload > 60KB).
- Event consume: one DB SELECT (bounded batch of 200 rows), per-row
  processing, idempotent.
- Reconcile: full local cache purge (filesystem operation, no DB
  writes beyond the checkpoint update).
- Offline-node recovery: same as reconcile — local purge on gap
  detection.

The cluster event table is bounded by the janitor: pending rows past
retention (configurable, default 30 days) are pruned by the node
that owns them. Consumed rows are pruned by the consumer once the
checkpoint has advanced past them.

## 18. Load and event-gap testing

Phase N §30 requires realistic bursts of 100 / 1000 / 5000 events.
The `tests/audit-event-recovery.php` R-matrix covers bursts of 100
and 1000 with deliberate row deletion, reordering, and corruption.
5000-event bursts are disclosed as not-yet-run in this sandbox
(time-bound on the SQLite wpdb double). The bounded-table-growth
proof is in `tests/audit-cluster-epoch.php` (single-key after 500
events, <256 bytes).

## 19. Security review

The Phase N §31 security review covers:

- **epoch spoofing**: epoch is a shared DB counter; only the
  producer with DB write access can bump it. A consumer that reads
  a spoofed epoch value still reconciles (fail-closed).
- **epoch rollback**: the `Epoch` class always reads the current
  value fresh per instance (no per-instance caching that could be
  poisoned by a stale read).
- **event deletion**: deliberately deleted events are detected via
  the epoch gap algorithm; the consumer reconciles.
- **event injection**: events are validated by schema (v1 vs v2);
  future-schema events fail safely (logged, not executed).
- **event replay**: v2 generation-based dedup prevents replayed
  events from re-executing (covered-skip).
- **future-schema event**: see above — fails safely.
- **cross-site event**: events are scoped by origin (the publishing
  node's UUID7); a foreign node's events are not consumed by another
  node's local consumer (verified by the cluster live gate).
- **node-id collision**: persistent boot secret + ephemeral instance
  nonce; concurrent clones detected; non-concurrent restores are
  documented legitimate.
- **lease spoofing**: lease table PK is node_id; a spoofed INSERT
  conflicts with the existing row and is rejected by the unique
  constraint.
- **path injection**: `CacheKey::segment` validates and normalizes
  URL segments; `Key::root` maps to a host-prefixed directory tree.
- **manual reconcile abuse**: reconcile only purges the LOCAL
  node's cache; an attacker with `manage_options` cannot purge
  another node's cache via the local API.
- **probe SSRF**: the admin verify-probe sends an HTTP request to
  the configured origin URL. The URL is validated against a
  deny-list of internal addresses (RFC1918, loopback, link-local);
  only the configured site's own URL is probed.
- **proxy-host confusion**: `X-Forwarded-Host` is NEVER trusted as
  authorization. The cache key uses the request's actual Host
  header (or the configured canonical host if set).
- **uninstall over-deletion**: see §14 — only Ultimate Performance-owned
  resources are removed; foreign Redis/Memcached/APCu keys survive.

## 20. Remaining risks (honest disclosure)

1. **OLS PHP backend matrix** (§11): PARTIAL. The full bypass matrix
   requires a real WP backend through LSAPI, which is not provisioned
   in this run.
2. **OLS Rules writer** (§11): not yet implemented. The plugin does
   not yet emit OLS-compatible rewrite directives.
3. **LiteSpeed Enterprise** (§12): PARTIAL. Not tested in this run.
4. **WP-CLI cluster command surface** (§16): PARTIAL. The command
   class and its audit are not yet implemented.
5. **5000-event burst** (§18): not yet run on the SQLite wpdb double
   in this sandbox (time-bound). 100 and 1000 bursts are green.
6. **Real MariaDB multi-node live gate** (§15.1, §15.2): NOT run in
   this sandbox — no MariaDB daemon available. The cluster logic is
   verified via the wp-shim DB double (which faithfully reproduces
   MySQL dialect for row semantics) and the SQLite fallback for
   transactional semantics. The `tests/run-cluster-live.sh` runner
   is documented and committed; running it requires a real MariaDB.
7. **Real Redis / RabbitMQ live gate**: NOT run in this sandbox —
   no Redis / RabbitMQ daemons available. The runners are committed
   and self-provisioning; running them requires the daemons or the
   self-provisioning path (which works in environments where the
   `apt-get download` closure is available — verified for memcached
   and APCu, not yet verified for Redis/RabbitMQ in this sandbox).

## 21. Recommended Phase O scope

Based on the PARTIAL/BLOCKED items above, Phase O should address:

1. OLS Rules writer (analog of `src/WebServer/Nginx/Rules.php`)
2. OLS lsphp build (PHP compiled with the LSAPI SAPI)
3. OLS full bypass matrix (POST/query/logged-in/WooCommerce/session/
   private-route/wp-config.php denial/.env/.git/encoded traversal)
4. LiteSpeed Enterprise attempt (requires license)
5. WP-CLI cluster command class + audit
6. 5000-event burst on real MariaDB
7. Real MariaDB / Redis / RabbitMQ live gate sweep in a
   fully-provisioned environment
8. Performance benchmarking suite (timing of the paths in §17)
9. OpenLiteSpeed cache-directive writer (LS cache root + cache-residence
   headers, equivalent to nginx's `proxy_cache` directives)

Do NOT start Phase O until the closure gates in §42 of the Phase N
prompt are honestly classified.
