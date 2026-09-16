# PHASE M — BASELINE (M0)

Project: Ultimate Performance (WordPress caching platform)
Baseline date: 2026-09-09 (UTC)
Purpose: truthful starting state for Phase M ("real-evidence phase") per the
binding discipline established in `docs/PHASE-L-BASELINE.md` §1: **the
repository is authoritative; repository evidence wins over handoff narrative.**

---

## 1. Handoff claim vs. repository evidence

The Phase M handoff narrative claimed: "Phase L is CLOSED with a regression
baseline of 39 suites / 951 executed PASS / 0 FAIL". Repository evidence
contradicts this:

| # | Claimed | Repository finding | Evidence |
|---|---|---|---|
| 1 | Phase L CLOSED | **No Phase L closure commit exists.** History ends at `a0f3601` ("RECOVERY COMPLETE … Phase L not started"). | `git log --oneline` |
| 2 | 39 suites / 951 executed PASS | Fresh full regression this session: **28 suites, 823 executed PASS, 0 FAIL, 14 honest skip/blocked rows** — byte-identical to the recovery-track close. | `bash tests/run-all-regression.sh PHASE-M-HANDOFF`, this session (table in §5) |
| 3 | `download/PHASE-L-FINAL-REPORT.md` (handover doc) | **ABSENT** — `download/` contains Phase H/I/J/K final reports, the recovery report, and `PHASE-L-BASELINE.md` only. | directory listing |
| 4 | Apache 2.4.68 live at `/home/z/opt/httpd`, PHP 8.3.27, Redis 8.0.2 provisioned | **Environment wiped between sessions** (known recurring pattern, worklog). Re-provisioned this session: PHP 8.3.27 static binary (`~/.local/bin/php`, 50 extensions incl. redis/pdo_sqlite/sqlite3/sockets/curl/pdo_mysql/gd/mbstring/xml/zip), Redis 8.0.2 self-compiled from source (`~/.local/bin/redis-server`). | `php -v`, `redis-server --version`, this session |
| 5 | 951 − 823 = 128 PASS across 39 − 28 = 11 suites attributable to Phase L | **Zero repository evidence** for any of those suites (no commits, no files, no reports). Per §1 discipline they are not accepted and not carried into the Phase M baseline. | `git log`, `git fsck`, tree search |

Verdict: the handoff narrative's Phase L state is **not reproducible from the
repository**. This mirrors the previously documented pattern
(`docs/PHASE-L-BASELINE.md` §7): work produced in working trees that was never
committed does not survive session resets. Phase M therefore starts from the
**committed, re-verified Phase K recovery state**.

## 2. Starting HEAD and git state (verified this session)

- HEAD: `a0f3601` — "RECOVERY COMPLETE: fresh-checkout durability proven (clone
  re-run 823/0 identical); 20-section recovery final report; final HEAD
  47d3e5a; STOP per mission section 41 — Phase L not started"
- Branch: `main` (only branch). Stashes: none. Reflog: clean (only recovery
  commits).
- Working tree at session start: clean; after the fresh regression round the 5
  sandbox runtime residue files were surgically restored via the established
  `git cat-file blob` protocol (forbidden commands `git reset --hard` /
  `checkout --` / `restore` not used); tree clean again (0 porcelain lines).
- `git fsck --full`: one dangling blob (harmless, carried over from prior
  sessions). 594 tracked paths at HEAD.

## 3. What exists at HEAD (platform inventory)

- Page-cache platform (full directory cache, request classification, response
  locking, invalidation, tags/registry, queue platform incl. RabbitMQ +
  ActionScheduler backends, security canaries).
- Object-cache platform: `src/ObjectCache/` — `Backend` contract,
  Memory/Redis/Memcached/Apcu/Sqlite/File backends, `Manager`, `Dropin`
  ownership state machine; generation-counter O(1) invalidation; promotion
  fencing (chain epoch, reconcile-before-reuse, cross-process stale
  detection).
- Warmup platform (`src/Warmup/`), WP-Cron scheduler + bounded telemetry
  (`src/Core/Scheduler.php`, `src/Core/Telemetry.php`).
- 29 regression suite files + 5 live runners (redis, memcached, apache,
  rabbitmq, all-regression). WP compatibility shim (`tests/wp-shim/`) —
  **no real WordPress install exists yet** (that is precisely Phase M scope).
- WordPress.org readiness: `readme.txt` (Stable tag 0.4.0, honest shim-parity
  disclosure), headers (Requires PHP 8.3), `languages/ultimate-performance.pot`.

## 4. Environment (re-provisioned this session)

- PHP: **8.3.27** CLI static binary (same build as prior phases). Extensions
  loaded: redis, pdo_sqlite, sqlite3, sockets, curl, pdo_mysql, mysqlnd, gd,
  mbstring, libxml/SimpleXML/xml*, zip. **Not loaded: memcached, apcu** (the
  static build does not ship them; same honest skip rows as prior sessions,
  or user-space extension builds if a live gate requires them).
- Redis: **8.0.2** self-compiled (`~/.local/bin/redis-server`).
- Web servers: **none provisioned yet**. Phase M will self-build Nginx (M3)
  and Apache (re-run of prior live gates at M6) in user space at
  `/home/z/opt/` as before.
- WordPress / WooCommerce / WP-CLI: **not installed** — Phase M M1/M2 scope
  (real downloads from wordpress.org).
- Database for real-WP matrix: MariaDB generic binary (user space,
  `pdo_mysql` available) or real MySQL — SQLite remains non-authoritative for
  compatibility claims. Precise DB version will be recorded in M1 evidence.
- RabbitMQ: no broker provisioned yet; credentials policy unchanged (never
  stored, never guessed, never printed; suites self-gate on absent
  `UC_RABBITMQ_*`).

## 5. Fresh baseline regression (this session, round `PHASE-M-HANDOFF`)

28 suites — all exit=0:

| Suite | Pass | Fail | Skip/Blocked | | Suite | Pass | Fail | Skip/Blocked |
|---|---|---|---|---|---|---|---|---|
| audit-core | 98 | 0 | 0 | | audit-object-cache | 46 | 0 | 0 |
| audit-host-poison | 16 | 0 | 0 | | audit-oc-backends | 2 | 0 | 1 |
| audit-classifier | 80 | 0 | 0 | | audit-oc-multisite | 23 | 0 | 0 |
| audit-response-lock | 25 | 0 | 0 | | audit-oc-sqlite-file | 37 | 0 | 0 |
| audit-wp-nonce | 9 | 0 | 1 | | audit-oc-fencing | 17 | 0 | 0 |
| audit-write-path | 17 | 0 | 0 | | audit-scheduler-tel | 27 | 0 | 0 |
| audit-sanitizer | 53 | 0 | 0 | | audit-warmup | 12 | 0 | 0 |
| audit-metadata | 0 | 0 | 2 | | audit-large-purge | 77 | 0 | 0 |
| audit-apache-hit | 0 | 0 | 2 | | audit-concurrency | 21 | 0 | 0 |
| audit-invalidation | 25 | 0 | 1 | | audit-backend-matrix | 64 | 0 | 0 |
| audit-registry-stress | 15 | 0 | 0 | | audit-rmq-connection | 3 | 0 | 2 |
| audit-registry-conc | 8 | 0 | 0 | | audit-rmq-live | 0 | 0 | 2 |
| audit-queue | 94 | 0 | 0 | | audit-rmq-fixes | 5 | 0 | 3 |
| audit-queue-callback | 11 | 0 | 0 | | audit-wporg-ready | 17 | 0 | 0 |
| audit-boot | 21 | 0 | 0 | | **Total** | **823** | **0** | **14** |

Skip/blocked rows are the same honest gates as the recovery close: RabbitMQ
suites self-gate on absent credentials (7 rows), Apache live rows self-gate on
absent httpd (2 rows: re-provisioned at M6), APCu extension absent (1 row),
plus wp-nonce/invalidation environment-conditioned rows (2 rows). **Any
decrease in executed PASS from 823 must be investigated, never silently
absorbed.**

## 6. Phase M scope (unchanged from directive; re-based on true state)

- **M1** — real WordPress three-version release matrix (versions chosen from
  the plugin's `readme.txt` "Tested up to" reality: newest, previous, oldest
  still-supported; each version in an independent environment; full
  activation / drop-in / lifecycle; release-blocking: Multisite cross-site
  canary leakage on oldest+newest).
- **M2** — real WooCommerce ≥2 versions + WP-CLI full command surface with
  `--format=json` contract; release-blocking: cross-session leakage between
  two Woo sessions.
- **M3** — `tests/run-nginx-integration.sh`: real nginx config + real HTTP;
  HIT must prove PHP did not execute; full security canary set (wp-config,
  .env, .git, path traversal variants, NUL, host poisoning; Host header never
  authoritative).
- **M4** — RabbitMQ live on a self-provisioned real broker: full matrix +
  failure injection (broker down mid-flight, reconnect, credential refusal).
- **M5** — cluster page-cache invalidation (§56): **design document committed
  before any code**; reuse QueueManager; events carry schema_version / event
  id / scope / epoch / origin; idempotent bounded dedup; local-before-remote
  propagation; strongest live gate: two real WP nodes sharing one DB with
  independent page-cache roots — Node A mutation makes Node B's stale page
  disappear; end-to-end invalidation latency recorded; 100/1000-event burst
  stress; warmup stampede policy (one of three) documented; new metrics
  published/consumed/duplicates/failures/lag; consumer validates full payload;
  no queue round-trip on the HIT path.
- **M6** — closure: upgrade/uninstall regression, WordPress.org readiness
  re-check, all live gates re-run green, full regression ≥ 823 executed PASS
  with every delta explained, fresh-clone durability proof, 41-section
  `download/PHASE-M-FINAL-REPORT.md`. **STOP after Phase M — Phase N must not
  be started.**

Standing rules re-affirmed: no mock impersonating live; no FAIL→SKIP; no test
weakening; atomic commit per completed unit (test green → worklog → scoped
`git add` → commit → `git status` verified); forbidden git commands respected;
credentials never stored/printed; PHP×WP cross-matrix documented honestly
(live where real, marked otherwise); OpenLiteSpeed Enterprise remains honestly
PARTIAL (not in scope to fix unless trivially provable).

## 7. Risks

1. Session resets recur and wipe uncommitted work — mitigated by the atomic
   commit discipline (every unit committed immediately).
2. Tool-gateway `broken session: 403` flakiness — mitigated by executing each
   milestone as one atomic window (tests + worklog + immediate commit).
3. Real-WP matrix needs a real DB (MariaDB user-space bintar) and per-version
   isolation; time-boxed, but no simulation shortcut is permitted.
4. RabbitMQ requires user-space Erlang + generic-unix broker; if provisioning
   proves impossible in this environment, M4 records an honest BLOCKED with
   the exact blocker — never a mock.
5. memcached/apcu PHP extensions absent from the static build; live gates that
   need them either get user-space extension builds or keep their honest skip
   rows.

## 8. Credential statement

No credentials exist in this document. None were used, stored, or persisted
during verification. RabbitMQ suites self-gated on absent `UC_RABBITMQ_*`
variables; nothing was guessed.
