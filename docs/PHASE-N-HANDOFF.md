# Phase N — Handoff Reconciliation (§0)

Evidence-first reconciliation of the Phase M → Phase N handoff. Git is
authoritative; no narrative below is trusted unless a command in this
document proves it. All commands executed at reconciliation time against
`/home/z/my-project` (branch `main`).

## 1. The two conflicting narratives

| Source | Claim |
|---|---|
| User handoff | final Phase M commit = `dbdea11` |
| Phase M final report | close/final HEAD = `d35dc7d` |

## 2. Git evidence

```text
$ git log --oneline --decorate -n 5
dbdea11 (HEAD -> main) Phase M FINAL: 41-section final report ...
d35dc7d Phase M M6: wrap-up real-green — uninstall + REAL upgrade test ...
e08f437 Phase M M5: cluster page-cache invalidation real-green ...

$ git show --stat --oneline HEAD | head -4
dbdea11
 download/PHASE-M-FINAL-REPORT.md | 253 +++++ (new file)
 worklog.md                       |  14 +++
 → the final report ITSELF was committed in dbdea11, i.e. AFTER d35dc7d

$ git fsck --full
dangling blob 60d00f9a57a0c26fb0b0441640155bc43ca3f944
→ one dangling blob (interrupted `git add`); ZERO missing/corrupt objects

$ git branch -avv   → single branch: main @ dbdea11
$ git tag -l        → (no tags)
```

## 3. Determinations

1. **Actual current HEAD**: `dbdea11` (branch `main`).
2. **`dbdea11` exists**: yes — it IS the current HEAD.
3. **`d35dc7d` exists**: yes — it is the direct parent of `dbdea11`.
4. **Descendant relationship**: `dbdea11` is a first-generation descendant
   of `d35dc7d` (parent→child proven by `git log`).
5. **Was the final report committed after `d35dc7d`?** YES — `dbdea11`
   adds exactly `download/PHASE-M-FINAL-REPORT.md` (new, 253 lines) and
   14 worklog lines on top of `d35dc7d`.
6. **Uncommitted Phase M closure artifacts**: NONE in content. 28 files
   showed as modified, ALL of them **mode-bit drift only**
   (`100644 → 100755`), with **zero content hunks**
   (`git diff --numstat` = `0 0` for every text file) and byte-identity
   proven by sha256 on the binaries, e.g.
   `woocommerce-8.2.2.zip: d000fc53fc5d4bb10b89fce3f24e5e7aac65b29377964b8ba59d9d67e688bb43`
   both in HEAD's blob and in the working tree. Diagnosis: permission
   drift from live-run extraction processes (tar/zip extraction under a
   permissive umask during Phase M live testing). **This is not Phase M
   work** — no source, test, or doc content differs from `dbdea11`.

## 4. Reconciliation verdict

**Both narratives are consistent and correct.** `d35dc7d` is the Phase M
close HEAD at which the final live sweep and regression totals were
produced; `dbdea11` is the report-commit ON TOP of `d35dc7d` (the user
handoff's "final commit"). Nothing was lost, nothing is stale.

Resolution of the mode drift: every drifted file was surgically
normalized to `0644` with explicit `chmod` (filesystem-only; content
provably identical, so no data could be lost; no `git restore` /
`checkout --` / `reset --hard` used — §2 forbidden-source-restoration
respected). Working tree after normalization: **clean** except the two
Phase N mission input files (see §6).

The dangling blob is harmless residue of an interrupted `git add` and is
left untouched (destructive pruning is forbidden by policy).

## 5. Environment disclosure (honest)

The machine was **reset** between Phase M and Phase N: the Phase M
user-space toolchain (Apache httpd 2.4.68 at `/home/z/opt/httpd`, PHP
8.3.27 at `~/.local/bin/php`, the MariaDB 11.8.6 server instance, Redis
8.0.2, user-space RabbitMQ 4.0.5/OTP27) no longer exists. Home
directories were re-initialized; only `git`-committed artifacts
survived — which is exactly what the durability rule protects.

Re-provisioning for Phase N (user `z`, uid 1001, **no root**, network
available): static PHP **8.3.32** (static-php-cli) installed at
`~/opt/php83/php` → `~/.local/bin/php`, extensions: redis, pdo_sqlite,
sqlite3, sockets, pdo_mysql, pdo_pgsql, mysqli, curl, mbstring, zip,
gd, bz2 (full list in docs/PHASE-N-BASELINE.md). **memcached/APCu are
NOT present** in this build (same honest constraint as Phase M; N4
handles this per §21–§23). MariaDB/Redis/nginx/httpd/RabbitMQ are
re-provisioned per live milestone by the self-provisioning runners.

## 6. Mission inputs committed

`upload/PHASE-N-PROMPT.md` (this phase's contract) and
`upload/PHASE-M-FINAL-REPORT.md` (user-provided copy of the Phase M
report) are committed to git for provenance, following the established
repository convention (prior phase reports live under `upload/`).

## 7. Phase N §3 reading list — actual state

Read and verified from committed source at `dbdea11`:
`download/PHASE-M-FINAL-REPORT.md`, `docs/PHASE-M-BASELINE.md`,
`docs/PHASE-M-CLUSTER-INVALIDATION.md`, `docs/ARCHITECTURE.md`,
`docs/SECURITY.md`, `readme.txt`, plus all cluster sources
(`src/Cluster/{EventStore,NodeIdentity,Propagator,State}.php`),
`src/ObjectCache/Manager.php::current_epoch()`, and the live runners.
**Discrepancy recorded**: `docs/OPERATIONS.md` is listed in the Phase N
prompt but does NOT exist in the repository (never created by any prior
phase). Phase N creates it during N5 (operations hardening) rather than
pretending to "read" a nonexistent file.
