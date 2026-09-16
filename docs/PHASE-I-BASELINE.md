# PHASE I — BASELINE (Recovery Track)

Recorded: 2026-09-08. Session 4 (recovery mission). This document is the verified starting
state for Phase I reconstruction. Evidence discipline: only executed checks are claimed;
numbers below were re-run this session, not copied from any prior report.

## 1. Starting commit

- Branch `main`, HEAD `89b917c` ("Phase H CLOSED: Session-4 addendum …"), tree clean.
- Phase H is CLOSED (Session-4 addendum §22 of `download/PHASE-H-FINAL-REPORT.md`):
  - RabbitMQ live gate: 118 live checks PASS on a real self-provisioned broker (loopback-only, ephemeral runtime credentials).
  - Apache live gate: 43 live checks PASS on a real self-built httpd 2.4.68 (`tests/run-apache-live.sh`).
  - Two test defects (TB-2 channel seam, TB-4 undefined `fskip`) were found and fixed by live execution; zero production defects.

## 2. Regression baseline (re-run this session)

`bash tests/run-all-regression.sh PHASE-H-CLOSURE` → **21/21 suites, 642 executed PASS,
0 FAIL, 13 honest skip/blocked rows** (wp-nonce ×1, metadata ×2, apache-hit ×2,
invalidation ×1, rmq-connection ×2, rmq-live ×2, rmq-fixes ×3). Identical to all five
recorded rounds of sessions 3–4.

## 3. Environment (verified this session)

| Component | State |
|---|---|
| PHP | 8.3.27 static (cli), ext: redis, pdo_sqlite, sqlite3, sockets, posix, pcntl |
| ext-memcached / ext-apcu | NOT loaded (Phase J concern; must be provisioned then, honestly gated) |
| Redis server | Not present — Phase I live gate will self-provision a real `redis-server` in user space |
| Apache httpd | Self-built 2.4.68 at `~/opt/httpd` (cached provision, reusable) |
| RabbitMQ | Self-provisionable via committed `tests/run-rabbitmq-live.sh` (cached closure) |
| WordPress / WooCommerce | Real installs not present; the established doctrine of this project applies: the portable `tests/wp-shim/` WordPress API shim is the WP stand-in for suites, and "live" means real daemons for the semantics that live inside them (broker, httpd, redis-server). All claims name the runtime explicitly. |
| Sessions are reset between conversations | Uncommitted work is destroyed; therefore every completed work unit is committed immediately. |

## 4. Prior Phase I attempts

The pre-recovery Phase I session produced working-tree artifacts that were never committed
and were destroyed by an environment reset (see `docs/PHASE-L-BASELINE.md`). Nothing from
that session survives; this phase is a REBUILD from the Phase H state, using the historical
phase mandate as the specification only. No numbers, files, or claims from the lost session
are reused.

## 5. Phase I scope (specification for this rebuild)

1. Object cache runtime under `src/ObjectCache/`:
   - `Backend` contract (get/getMultiple/set/add/replace/delete/incr/decr/flush/flushGroup/healthy/close).
   - `MemoryBackend` (per-process, always available, honest non-persistence).
   - `RedisBackend` (ext-redis; O(1) generation-based invalidation; forbidden: KEYS/KEYS*/SCAN-based invalidation, FLUSHALL, FLUSHDB).
2. Full WordPress object-cache semantics via `Manager` + generated `object-cache.php` drop-in:
   `$found` reference semantics, storing `false`/`null`/`0`/`''`, TTL, `add`/`replace` CAS,
   atomic `incr`/`decr`, `get_multiple`, global groups (blog-independent), non-persistent
   groups (runtime only), `switch_to_blog` isolation, `wp_cache_supports()` truthfulness,
   `flush` vs `flush_group` vs `flush_runtime`.
3. Drop-in ownership safety: install when absent; atomic in-place update when OURS
   (marker-verified); refuse + notify when FOREIGN (never overwrite); deactivate removes
   only our own drop-in.
4. Live gate: real `redis-server` (user-space provision) — full semantic matrix, TTL,
   multi-process atomicity, fail-closed on server death, recovery; page-cache integration
   regression on real Apache must stay green.
5. Closure: `download/PHASE-I-FINAL-REPORT.md`, full regression, per-unit commits throughout.
