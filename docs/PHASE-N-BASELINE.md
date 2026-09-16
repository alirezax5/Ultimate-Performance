# Phase N — Baseline (N0)

Starting durable state for Phase N, re-verified from scratch on the
re-provisioned environment. Counts are NOT copied from Phase M — they
were re-executed for this document.

## 1. Durable starting point

- HEAD: `dbdea11` (branch `main`, single branch, no tags), clean tree
  after the mode-drift normalization documented in
  `docs/PHASE-N-HANDOFF.md`.
- Phase M claim under test: 31 suites, 958 executed PASS / 0 FAIL /
  14 honest skips.

## 2. Environment (re-provisioned)

| Component | Value |
|---|---|
| OS | Debian 13 (user `z`, uid 1001, **no root**) |
| PHP | **8.3.32** static (static-php-cli), `~/.local/bin/php` |
| PHP extensions | Core, bcmath, bz2, calendar, ctype, curl, date, dom, exif, fileinfo, filter, ftp, gd, gmp, hash, iconv, json, libxml, mbstring, mysqlnd, openssl, pcntl, pcre, PDO, pdo_mysql, pdo_pgsql, pdo_sqlite, pgsql, Phar, posix, random, **redis**, Reflection, session, SimpleXML, soap, **sockets**, SPL, sqlite3, standard, tokenizer, xml, xmlreader, xmlwriter, zip, zlib |
| memcached / APCu | **NOT PRESENT** (static build has neither; honest constraint carried from Phase M — N4 attempts real provisioning) |
| Compiler | gcc 14.2.0 / make (user-space service builds possible) |
| MariaDB / Redis / nginx / httpd / RabbitMQ | to be self-provisioned per live milestone (as in M1–M4); the 31 non-live suites do not depend on them |
| Bundled in git (no untracked deps) | wordpress-{6.0.9,6.6.2,6.7.2}.tar.gz, woocommerce-{8.2.2,10.2.2}.zip, wp-cli.phar, vendor/ |

## 3. Full regression — main tree @ `dbdea11` (round PHASE-N-BASELINE)

31/31 suites exit 0, **0 FAIL** anywhere.

| suite | pass | skip/blocked | | suite | pass | skip/blocked |
|---|---|---|---|---|---|---|
| audit-core | 98 | 0 | | audit-object-cache | 47 | 0 |
| audit-host-poison | 16 | 0 | | audit-oc-backends | 2 | 1 |
| audit-classifier | 80 | 0 | | audit-oc-multisite | 23 | 0 |
| audit-response-lock | 25 | 0 | | audit-oc-sqlite-file | 37 | 0 |
| audit-wp-nonce | 9 | 1 | | audit-oc-fencing | 17 | 0 |
| audit-write-path | 27 | 0 | | audit-scheduler-tel | 27 | 0 |
| audit-nginx-rules | 37 | 0 | | audit-warmup | 12 | 0 |
| audit-sanitizer | 85 | 0 | | audit-cluster | 55 | 0 |
| audit-metadata | 0 | 2 | | audit-large-purge | 77 | 0 |
| audit-apache-hit | 0 | 2 | | audit-concurrency | 21 | 0 |
| audit-invalidation | 25 | 1 | | audit-backend-matrix | 64 | 0 |
| audit-registry-stress | 15 | 0 | | audit-rmq-connection | 3 | 2 |
| audit-registry-conc | 8 | 0 | | audit-rmq-live | 0 | 2 |
| audit-queue | 94 | 0 | | audit-rmq-fixes | 5 | 3 |
| audit-queue-callback | 11 | 0 | | audit-wporg-ready | 17 | 0 |
| audit-boot | 21 | 0 | | | | |

**Totals: 958 executed PASS / 0 FAIL / 14 honest skips.**
Skips are the live-gated suites (metadata, apache-hit, rmq-* need real
servers; wp-nonce + invalidation + oc-backends have environment-gated
checks) — all honest SKIP/BLOCKED, never counted as PASS.

## 4. Fresh-clone proof (round PHASE-N-FRESHCLONE)

`git clone` of `/home/z/my-project` @ `dbdea11` → clean clone
(0 dirty files) → full regression re-run inside the clone:
**958 executed PASS / 0 FAIL / 14 skips, 31/31 suites fail=0 —
byte-identical totals to the main tree.** The clone was removed after
the run (no residue; the closure gate §57 repeats this on the FINAL
Phase N commit).

## 5. Count preservation check (§52)

- Phase M close: 31 suites / 958 / 0 / 14.
- Phase N baseline: 31 suites / 958 / 0 / 14.
- **Delta: zero.** No count decreased; nothing needs investigation.

## 6. Phase N objectives (from the mission contract)

The five Phase M boundaries Phase N must harden:
1. runtime-only object-cache nodes have an epoch guard that can collapse
   to `0 < 0` (vacuous);
2. cluster invalidation durability depends entirely on the shared DB
   event table;
3. per-node watermark loss causes (harmless) replay; replay semantics
   can be stronger;
4. OpenLiteSpeed remains PARTIAL;
5. memcached/APCu extension coverage is incomplete.

Core question: *can a node that missed, lost, or replayed invalidation
history ever serve a stale page as authoritative?* — to be answered by
real multi-node evidence including a runtime-only node.
