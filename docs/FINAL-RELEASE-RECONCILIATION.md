# Final Release Reconciliation — RELEASE-0

## Date: 2026-09-14

---

## Critical Finding

The repository at `/home/z/my-project/work/ultimate-cache-extract/` was found at HEAD `14d19b5` (Phase O closure). **All hardening work (HARDEN-0 through HARDEN-9, LIVE-Q-0 through LIVE-Q-9) was missing from the local tree.** The commits `a6f5324`, `c6b2291`, `297aa3f` referenced in the prompt do NOT exist in this repository.

### Root Cause

The reflog shows the repository was cloned from `/home/z/my-project/scripts/uc-filter-clone` at `bd7b1cf` and all subsequent commits are Phase N/O work. The hardening work was performed in previous conversation sessions but the repository state was reset to the pre-hardening Phase O closure state.

### Recovery

The hardened code survived on the VPS (65.109.178.1) at `/var/www/wp672/wp-content/plugins/ultimate-performance/`. All 27 modified/new files were downloaded back to the repository:

**New files recovered:**
- `src/PageCache/GenerationLock.php` (BENCH-D7 stampede protection)
- `src/Compatibility/AdvancedCacheDropin.php` (PHP fallback drop-in generator)
- `src/Compatibility/FallbackServer.php` (PHP-level cache serving, with `<?php` fix and no-WP-function dependency)
- `src/Core/EnvironmentDetector.php` (mode detection + self-test)
- `tests/audit-herd.php` (D7 regression)
- `tests/audit-homepage.php` (D5 regression)
- `tests/audit-woo-sanitizer.php` (D4 regression)
- `tests/audit-stale-exposure.php` (D6 regression)
- `tests/audit-fallback.php` (PHP fallback regression)
- `tests/audit-env-detect.php` (env detector regression)
- `tests/audit-shared-hosting.php` (shared hosting regression)

**Modified files recovered:**
- `src/CacheKey/Key.php` (ROOT_SENTINEL = 'uc-root')
- `src/Core/Settings.php` (herd_protection, genlock_ttl, php_fallback_enabled)
- `src/Core/Installer.php` (drop-in install/remove on activate/deactivate)
- `src/PageCache/Engine.php` (GenerationLock integration, maybe_release_genlock)
- `src/CacheInvalidation/Hooks.php` (sync_purge_permalink)
- `src/Security/ResponseSanitizer.php` (semantic Woo patterns)
- `src/WebServer/Nginx/Rules.php` (ROOT_SENTINEL in generated rules)
- `src/WebServer/Apache/Rules.php` (uc-root in Apache rules)
- `src/Warmup/Planner.php` (backward compat for old and new sentinel)
- `tests/audit-sanitizer.php` (updated test data)
- `tests/audit-response-lock.php` (updated test data)
- `tests/audit-nginx-rules.php` (ROOT_SENTINEL assertion)
- `tests/run-all-regression.sh` (7 new suites added)

## HEAD Reconciliation

| HEAD | What it represents |
|------|--------------------|
| `14d19b5` | Phase O closure (pre-hardening, was the state at session start) |
| `a6f5324` | Previously reported live-qualified HEAD — DOES NOT EXIST in this repo |
| `c6b2291` | Previously reported final HEAD — DOES NOT EXIST in this repo |
| **`<new commit>`** | **Recovery commit — all hardening code restored from VPS** |

## Previous Evidence Applicability

Since the repository was at the pre-hardening state and ALL hardening code was missing, **ALL previous live evidence from the hardening sessions is NOT applicable** — the code it tested doesn't exist in the repository.

The recovered code from the VPS IS the code that was previously tested live. The VPS still runs this code. Therefore:
- The live VPS evidence from the previous session IS applicable to the recovered code
- BUT the regression suite must be re-run to confirm the recovered code is complete and functional

## Environment Constraint

PHP is NOT available in the local sandbox. Regression tests must be run on the VPS (which has PHP 8.5.4).
