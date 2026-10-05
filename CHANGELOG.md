# Changelog

All notable changes to Ultimate Performance are documented in this file.

## [0.7.3] — 2026-09-22

### Fixed (Critical — Fail-Closed Lock Semantics)

- **CRITICAL FIX: Lock acquisition now fails closed when $wpdb is unavailable.**
  The 0.7.2 `cas_takeover()` had a fallback to `update_option()` when $wpdb
  was unavailable, returning $owner as if the lock was acquired. This was
  fail-open: a non-atomic fallback for a lock that exists to provide atomicity.
  The locking contract requires: acquire success PROVES exclusive ownership.
  Without $wpdb, we cannot perform the CAS UPDATE, so we cannot prove
  ownership, so we must FAIL (return false).

- **CRITICAL FIX: Lock release now fails closed when $wpdb is unavailable.**
  The 0.7.2 `release_lock()` had a fallback to `delete_option()` when $wpdb
  was unavailable. This was non-atomic and could delete a lock belonging to
  another process. The 0.7.3 fix leaves the lock in place — TTL/stale
  recovery will handle it later. Safety over eager cleanup.

### Rationale

For a concurrency lock, loss of the atomic primitive must FAIL CLOSED:
- **acquire**: if we cannot atomically claim ownership, we must return false
- **release**: if we cannot atomically prove we still own the lock, we must leave it

No non-atomic fallback exists in any lock ownership transition.

### Schema Version

CRON_SCHEMA_VERSION remains at 1. This change affects lock implementation only,
not persisted cron schema.

## [0.7.2] — 2026-09-22

### Fixed (Critical — Atomic Stale-Lock Takeover)

- **CRITICAL FIX: Stale-lock takeover is now truly atomic.** The 0.7.1 implementation
  used `update_option()` for stale-lock takeover, which is NOT a compare-and-swap
  operation. 50-process testing proved 44/50 processes could simultaneously "acquire"
  the same expired lock. The 0.7.2 fix uses a direct SQL UPDATE with a WHERE clause
  that checks the exact old value (`UPDATE wp_options SET ... WHERE option_value = OLD`),
  so only the FIRST process can succeed. All others affect 0 rows and correctly fail.

- **CRITICAL FIX: Lock release is now truly owner-safe.** The 0.7.1 release_lock()
  used `get_option() → check owner → delete_option()`, which is a check-then-delete
  TOCTOU race. A stale former owner could delete a new owner's lock if the lock
  expired and was taken over between the check and the delete. The 0.7.2 fix uses
  a conditional DELETE (`DELETE FROM wp_options WHERE option_name=X AND option_value=OLD`),
  so only the current owner can delete their own lock.

### Added

- `cas_takeover()` private method: encapsulates the CAS UPDATE logic with cache
  invalidation (`wp_cache_delete` for both individual option and alloptions caches).

### Object-Cache Coherency

After successful CAS UPDATE, WordPress option caches are explicitly invalidated:
- `wp_cache_delete($option, 'options')` — removes the individual option cache
- `wp_cache_delete('alloptions', 'options')` — forces alloptions reload (defensive,
  since non-autoload options shouldn't be in alloptions, but we invalidate anyway)

This ensures `get_option()` calls after CAS see the new owner, not the stale value.

### Schema Version

CRON_SCHEMA_VERSION remains at '1'. The lock implementation change does NOT change
persisted cron schema — only the locking mechanism used during repair. Production
sites with schema version '1' will NOT trigger a redundant migration.

## [0.7.1] — 2026-09-22

### Fixed (Critical — CronGuard follow-ups)

- **Issue #1: cron event args preserved during unscheduling.** `CronGuard::events_for()` previously captured only `(ts, key)` from each cron instance — but the `$key` is `md5(serialize($args))` and the `$instance` array also carries `args`. Without `args`, callers had no way to pass the correct args to `wp_unschedule_event()`, which identifies events by the `(timestamp, hook, args)` tuple. The fix:
  - `events_for()` now captures the `args` from `$instance['args']` (defaulting to `array()` when missing).
  - `repair()` passes `$ev['args']` to `wp_unschedule_event()`, so events scheduled WITH args are correctly matched and removed.
  - The `repair()` return shape now reports `attempted_removals`, `successful_removals`, and `failed_removals` separately. The `removed` key is kept as a back-compat alias for `successful_removals` so `AdminPage.php`, `WpCliCommands.php`, and the audit suite continue to read `$stats['removed']` without code changes.
- **Issue #2: atomic repair lock.** The previous transient check-then-set pattern (`if (false !== get_transient($lock)) return; set_transient($lock, 1, 60);`) was non-atomic — two concurrent admin requests could both see "no lock" and both run `repair()` on the same cron option. Replaced with a new `acquire_lock()` / `release_lock()` pair that uses `add_option()` for database-level atomicity on MySQL (INSERT ... UNIQUE-key-equivalent failure). Stale locks left by a crashed process are taken over after the 60s TTL expires. The release path is owner-safe: a process only deletes the option if its own owner token is still the stored owner, so a slow process that exceeded the TTL does not delete a lock that has been taken over by another process. The old `REPAIR_LOCK_TRANSIENT` and `REPAIR_LOCK_TTL` constants are removed; the new constants are `LOCK_OPTION_PREFIX = 'up_cron_lock_'` and `LOCK_TTL = 60`.
- **Issue #3: deterministic callback identity.** The daily canonical hooks (`HOOK_JANITOR`, `HOOK_WARMUP`, `HOOK_TELEMETRY`) were bound to a NEW anonymous Closure on every call to `register_callbacks()`. Because every Closure has a different `spl_object_hash`, `has_action($hook, $cb)` could NEVER match a previously-registered Closure, so the callback was registered repeatedly — once per request — slowly inflating the `wp_filter[hook]` table. Replaced with three new public static methods `run_janitor()`, `run_warmup()`, `run_telemetry()` and a deterministic `array(__CLASS__, 'method_name')` callback identity. `has_action()` correctly recognizes the prior registration and prevents duplicates.

### Added

- `CronGuard::run_janitor()`, `CronGuard::run_warmup()`, `CronGuard::run_telemetry()` — public static per-hook entry points bound to each canonical daily action. Used as deterministic callbacks by `register_callbacks()`.
- `CronGuard::acquire_lock($purpose = 'repair')` — private static atomic lock helper (returns the owner token on success, `false` if held).
- `CronGuard::release_lock($purpose, $owner)` — private static owner-safe lock releaser.

### Changed

- `CronGuard::events_for()` — return shape now includes `args` per event (`array{ts:int, key:string, args:array<mixed>}`).
- `CronGuard::repair()` — return shape now includes `attempted_removals`, `successful_removals`, `failed_removals` (the `removed` key is kept as a back-compat alias).
- `CronGuard::repair()` — calls `wp_unschedule_event($ev['ts'], $hook, $ev['args'])` instead of `wp_unschedule_event($ev['ts'], $hook)`.
- `CronGuard::register_callbacks()` — replaced anonymous Closure callbacks with deterministic `array(__CLASS__, 'method')` callbacks.
- `CronGuard::deactivate()` — releases the atomic repair lock option (`up_cron_lock_repair`) instead of the old transient.
- `ultimate-performance.php` and `readme.txt` — version bumped to 0.7.1.

### Tests

- `tests/audit-cron-dedup.php` — extended with new scenarios for:
  - `events_for()` returns the `args` field per event.
  - `wp_unschedule_event()` is called with the correct `args` for events scheduled with args.
  - `register_callbacks()` called 100 times still has exactly 1 callback per canonical hook (deterministic identity).
  - The atomic lock (acquire / release / stale-takeover / owner-safe-no-release).
  - The new `attempted_removals` / `successful_removals` / `failed_removals` stats counters in `repair()`.

## [0.7.0] — 2026-09-20


### Fixed (Critical — WP-Cron deduplication / CronGuard)

- **Eliminated the per-request cron-scheduling self-DoS.** Three independent code paths (`QueueManager::boot()`, `WPCron::enqueue()`, `Scheduler::register()`) used the non-atomic `if (!wp_next_scheduled()) { wp_schedule_event(); }` pattern on every WordPress request, producing ~5,660 duplicate cron events and a 1.26MB `cron` option that was rewritten on every ~3 seconds on production. The new `UltimatePerformance\Core\CronGuard` class is the SINGLE source of truth for all UP-owned cron scheduling; every operation is idempotent, deduplicated, and transient-gated so steady-state requests perform zero cron-option writes.
- **Bound the daily canonical cron hooks** (`ultimate_cache/janitor_tick`, `ultimate_cache/warmup_tick`, `ultimate_cache/telemetry_tick`) — these were scheduled by `Scheduler::register()` but had NO `add_action` callbacks registered, so they were dead hooks that fired into the void. `CronGuard::register_callbacks()` now binds each to a `CronGuard::run_single_job()` callback that runs the corresponding Scheduler job and self-perpetuates exactly one next off-peak occurrence.
- **Removed the dead `ultimate_performance_janitor` and `ultimate_performance_telemetry` hooks** scheduled by the legacy `migrate_uc_to_up_brand()` cron-rescheduling block. These hooks had no callbacks and were rebranded to the canonical daily hooks. `CronGuard::repair()` clears them entirely on the first post-upgrade request.
- **Idempotent activation.** `Installer::activate()` now calls `CronGuard::activate()`, which schedules every canonical hook exactly once (no DB write for hooks that are already correctly scheduled). Repeated activation is a no-op for scheduling.
- **Clean deactivation + uninstall.** `Installer::deactivate()` and `Installer::uninstall_data()` now delegate to `CronGuard::deactivate()`, which clears ALL six known UP hooks (4 canonical + 2 dead) so no scheduled work is left for an inactive plugin.
- **Schema-driven migration.** `CronGuard::maybe_migrate()` checks the `ultimate_performance_cron_schema_version` option and runs `repair()` once per schema bump. Sites that upgrade from 0.6.9 carrying thousands of duplicate cron events self-heal on the first request after upgrade — no admin interaction required.
- **Concurrency-safe repair.** `CronGuard::repair()` is gated by a 60-second transient lock so concurrent admin requests (or admin + WP-CLI) cannot race the same repair.

### Added

- `src/Core/CronGuard.php` — the single-source-of-truth cron scheduler. Public API: `activate()`, `deactivate()`, `ensure_scheduled()`, `ensure_single_event()`, `repair()`, `get_status()`, `maybe_migrate()`, `register_callbacks()`, `canonical_hooks()`, `owned_hooks()`.
- **WP-Cron Health panel on the Diagnostics admin tab.** Shows a per-hook table: hook name, expected count, actual scheduled count, next execution, recurrence, status (Healthy / Missing / Duplicate / Stray). Includes a "Repair Ultimate Performance Cron Events" button (POST + nonce + capability check) that runs `CronGuard::repair()` and shows before/after stats. Also surfaces the WP-Cron option byte-size so admins can verify repair reduced the option footprint.
- **WP-CLI commands:**
  - `wp ultimate-performance cron status [--format=table|json|csv|yaml]` — show cron health per UP-owned hook.
  - `wp ultimate-performance cron repair [--format=table|json]` — run `CronGuard::repair()` and print before/after stats.
- **`tests/audit-cron-dedup.php`** — 17-scenario regression suite covering fresh activation, repeated activation, repeated bootstrap, multiple simulated requests, callback rescheduling, settings saves, interval change, telemetry toggle, deactivation, reactivation, upgrade-from-0.6.9 with thousands of duplicates, repeated repair, unrelated-cron preservation, malformed-event cleanup, missing-event recreation, dead-hook removal, and cron-option size reduction.

### Changed

- `src/Queue/QueueManager.php` — `boot()` no longer schedules `ultimate_performance_tick`. It only registers the `cron_schedules` filter and the `ultimate_performance_tick` / `ultimate_performance_as_job` action callbacks (idempotent, no DB writes). CronGuard handles scheduling.
- `src/Queue/BackendImpl/WPCron.php` — `enqueue()` calls `CronGuard::ensure_single_event('ultimate_performance_tick', 30)` instead of the legacy `wp_next_scheduled()` + `wp_schedule_single_event()` pair. Falls back to the legacy pattern if CronGuard is unavailable.
- `src/Core/Scheduler.php` — `register()` no longer schedules the daily canonical hooks. It delegates to `CronGuard::ensure_scheduled()` (kept for back-compat with audit suites that called it directly). New public method `run_single_job($hook)` is the per-hook entry point bound by CronGuard to each canonical daily action — it runs the Scheduler job (backpressure-guarded, bounded runtime) and CronGuard handles the rescheduling.
- `src/Core/Plugin.php` — `late_boot()` now calls `CronGuard::register_callbacks()`, `CronGuard::maybe_migrate()`, and `CronGuard::ensure_scheduled()`. The legacy `(new Scheduler())->register()` block is replaced by these calls.
- `src/Core/Installer.php` — `activate()` calls `CronGuard::activate()`. `deactivate()` and `uninstall_data()` call `CronGuard::deactivate()`. `migrate_uc_to_up_brand()` no longer runs the cron rescheduling (STEP 3) — CronGuard handles canonical scheduling and dead-hook removal.
- `ultimate-performance.php` and `readme.txt` — version bumped to 0.7.0.

## [0.6.5] — 2026-09-16

### Fixed (Critical — Deactivation Cleanup)

- **Plugin deactivation now wipes the cache directory** (`wp-content/cache/ultimate-performance/`). Previously deactivation only removed the drop-in files (`object-cache.php` / `advanced-cache.php`) but left the entire cache tree on disk, which:
  - Wasted disk space (cached page bodies + metadata + tag registry + node identity + watermark + telemetry)
  - Confused admins investigating "where did my disk space go?"
  - Confused any caching reverse proxy still configured to read from this path
  - Left stale cache entries that could be served if the plugin were re-activated

- **Plugin deactivation now also deletes ALL plugin transients.** Previously only cron hooks and drop-in files were cleaned. Now deactivation removes:
  - `uc_env_snapshot` (activation environment snapshot)
  - `uc_oc_dropin_status` (object-cache drop-in status)
  - `uc_registry_overflow` (CacheTag registry overflow flag)
  - `uc_settings_errors` (last save validation errors)
  - `uc_save_summary` (0.6.4 — last save summary)
  - `uc_redis_test` (last Redis connection test result)
  - `uc_oc_runtime_test` (last Object Cache Runtime test result)
  - `uc_amqp_test` (last AMQP connection test result)
  - `uc_oc_action` (last drop-in install/remove action)

- **Plugin uninstall (delete) now removes the legacy `wp-content/cache/ultimate-performance/` directory** too — sites that upgraded from ultimate-cache 0.6.x previously had a leftover cache tree that was never cleaned.

- **Plugin uninstall now removes the drop-in files** (`object-cache.php` / `advanced-cache.php`) and the `WP_CACHE` line in `wp-config.php` even when the plugin was deleted via FTP without prior deactivation.

- **Plugin deactivation now clears the third cron hook** (`ultimate_performance_telemetry`) that was missed in previous versions.

### Added

- `Installer::delete_all_plugin_transients()` private static helper — single source of truth for the complete transient list. Called by both `deactivate()` and `uninstall_data()` so neither path can leak stale cached state. Adding a new transient in the future only requires updating one place.

### Changed

- `Installer::deactivate()` now: clears 3 cron hooks (was 2), removes object-cache drop-in (with `'Ultimate Performance'` marker in addition to `'UltimateCache'`), removes advanced-cache drop-in, disables WP_CACHE, **deletes the cache directory** (both `ultimate-performance` and legacy `ultimate-cache`), and deletes all plugin transients.
- `Installer::uninstall_data()` now: deletes all plugin transients (via the shared helper), drops 3 cluster tables, deletes both cache directories, removes drop-in files (even if deactivation was bypassed), disables WP_CACHE, removes admin capability.

### What is PRESERVED on deactivation (so reactivation restores state)

- Plugin settings (`ultimate_performance_settings` option in `wp_options`)
- Cluster tables (`uc_invalidation_events`, `uc_cluster_epoch`, `uc_cluster_nodes`)
- Admin capability (`ultimate_performance_purge_all`)

Only the runtime artifacts (cache directory, drop-ins, transients, cron jobs) are cleaned. Settings and DB schema are kept so the user can re-activate and pick up where they left off.

## [0.6.4] — 2026-09-16

### Fixed (Critical — Object-Cache Tab Save Visibility)

- **The Object Cache tab now shows a detailed "what changed" notice after every save.** The previous behavior emitted a generic "Settings saved." notice that gave the user no way to verify whether secret fields (Redis password, RabbitMQ password) had been preserved, replaced, or explicitly cleared. This was the root cause of the perceived "blank Redis password field deletes the stored password" report: the user had no UI feedback to confirm the password was kept.
- **Post-save admin notice now lists every changed field with its operation** (preserved / set / cleared / updated). For secrets, only the OPERATION is reported — never the value. The notice is color-coded (green ✓ preserved/set/updated, red ⚠ cleared) so the user can see at a glance whether the password was preserved.
- **The in-form "Password configured: yes/no" indicator is now bold and explains the contract explicitly**: "Password configured: yes (will be preserved when this field is left blank)".
- **JavaScript confirmation prompt added for the `Clear saved Redis password` / `Clear saved RabbitMQ password` checkboxes.** Before this fix, accidentally checking the box and clicking Save would silently erase the stored password. Now the browser shows an explicit `confirm()` dialog requiring the user to acknowledge the action before submission proceeds. If the user declines, the checkbox is automatically unchecked so a re-submit doesn't require declining again.
- **`Settings::last_save_summary()` API added.** Returns a structured `{section, subsection, changes[]}` array showing exactly which fields were modified by the most recent `save_from_admin()` call. Used internally by the admin notice; also exposed for programmatic consumers (REST API, WP-CLI, telemetry).

### Added

- `tests/audit-object-cache-save-flow.php` (8 scenarios): walks every form-ownership scenario through `Settings::save_from_admin()` and verifies the password-preservation contract. Pre-seeds the option with `redis.auth = 'mypassword123'`, submits each form (Object Cache general, Redis blank, Redis new password, Redis clear, Memcached, AMQP, Redis omitted auth field, Redis whitespace-only), then verifies the expected `redis.auth` value after save. All 8 scenarios PASS on 0.6.4. Registered in `tests/run-all-regression.sh` as `audit-oc-save-flow`.
- §33 contract documentation in `assets/js/admin.js`: `bindClearPasswordGuards()` walks every `<form>` and intercepts submission when a `uc[redis][auth_clear]` or `uc[amqp][pass_clear]` checkbox is checked. The guard is idempotent (uses `data-uc-clear-guard` attribute) so it can be safely re-bound.

### Historical context — the 0.6.0–0.6.2 password-clear-on-blank bug

- Versions 0.6.0, 0.6.1, 0.6.2 contained a real bug: `Settings::save_from_admin()` used the legacy `$d[$svc]['auth'] = isset( $blk['auth'] ) ? trim( $blk['auth'] ) : ''` pattern, which CLEARS the stored password when the submitted password field is blank (which it ALWAYS is, because password fields render with `value=""` for security). The user's report of "blank field deletes the password" matches the 0.6.2 behavior exactly.
- Version 0.6.3 introduced `Settings::resolve_secret()` with the explicit "blank → preserve, NEVER clear" contract. The 0.6.3 fix is verified by the new `tests/audit-object-cache-save-flow.php` audit (scenario 2: Redis form with blank password → `redis.auth` preserved).
- Version 0.6.4 adds the visibility layer so the user can SEE that the password was preserved — closing the perceived-bug gap between the corrected storage logic (0.6.3) and the user's mental model.

### Changed

- `src/Core/Settings.php`: added `$last_save_summary` property, `build_save_summary()` static method (compares prev vs new data, reports operation per dot-path key without leaking secret values), `last_save_summary()` public accessor.
- `src/Core/Settings.php`: `resolve_ownership()` now stamps `_section` and `_subsection` keys onto the returned group (diagnostic metadata only — not used by the ownership/preservation logic).
- `src/Admin/AdminPage.php`: `handle_save()` now stores the save summary in a `uc_save_summary` transient and redirects with `uc_saved=summary` query arg when changes were detected; `render_notices()` now renders a detailed change list when `uc_saved=summary`.
- `src/Admin/AdminPage.php`: added `format_change_message()` private static helper that turns a `(label, op)` pair into a localized, color-coded HTML string. Operations: `preserved` (green ✓), `set` (green ✓), `cleared` (red ⚠), `changed` (green ✓).
- `src/Admin/AdminPage.php`: the Redis and AMQP password field indicators now read "Password configured: yes (will be preserved when this field is left blank)" (bold) and the clear checkbox label now appends "(requires confirmation)" with a red ⚠.
- `assets/js/admin.js`: added `bindClearPasswordGuards()` and registered it on `DOMContentLoaded`.

## [0.6.3] — 2026-09-15

### Fixed (Critical — AJAX Runtime Restoration)

- **Test Redis Connection and Test Object Cache Runtime no longer trigger a full-page reload.** The previous closure (commit `599d9f7`) replaced the planned `wp_ajax_up_ajax_*` registrations with `admin_post_uc_*` legacy handlers because the AJAX methods did not exist. That fix closed the invalid-callback fatal but introduced the regression this release closes: every test button produced a page refresh + redirect, with the result rendered only after the redirect. Real AJAX transport is now restored.
- **All four `wp_ajax_up_ajax_*` hooks now point to implemented AJAX handlers** (`ajax_test_redis`, `ajax_oc_runtime_phase1`, `ajax_oc_runtime_phase2`, `ajax_oc_runtime_phase3`). `has_action('wp_ajax_up_ajax_test_redis') > 0` is verified at runtime by the callback audit.
- **`enqueue_assets()` is now implemented on AdminPage** (the previous fatal — registering `admin_enqueue_scripts` → `array($this, 'enqueue_assets')` when the method did not exist — is closed by writing the method). The callback is verified by `is_callable()` runtime check.
- **Object Cache Runtime Test is a real 3-phase state machine.** Phase 1 (write test object) → Phase 2 (cross-request `wp_cache_get` in a SEPARATE `admin-ajax.php` request) → Phase 3 (delete + verify). Each phase is a distinct HTTP request, not a single PHP call. The JS in `assets/js/admin.js` orchestrates the 3-phase chain.
- **Test buttons use `type="button"`** so they cannot trigger form submission even if JS is disabled. The previous `submit_button(... 'submit', false)` produced `<input type="submit">` which defaults to submitting the surrounding form when JS fails to intercept.

### Added

- `assets/js/admin.js` (real AJAX transport): click handlers for `#uc-test-redis-btn` and `#uc-test-oc-runtime-btn`; fetch-based POST to `/wp-admin/admin-ajax.php`; inline PASS/FAIL rendering; transport-failure block distinct from service-failure block; 3-phase state machine for Object Cache Runtime.
- `enqueue_assets($hook_suffix)` on AdminPage: gates to Ultimate Performance admin pages (`settings_page_ultimate-performance`, `toplevel_page_ultimate-performance`), registers `ultimate-performance-admin` script with `wp_register_script`, localizes `UC_ADMIN` config (ajaxUrl + per-action nonces + i18n strings) via `wp_localize_script`, cache-busts via `filemtime()`.
- `tests/audit-ajax-runtime.php` (73 checks): invokes each AJAX handler in-process, verifies JSON shape, nonce 403 + capability 403 contracts, internal `_value`/`_group` fields are stripped from the response, `enqueue_assets` gating on non-Ultimate-Cache pages, callback matrix.
- WP-shim additions: `wp_using_ext_object_cache()`, `wp_generate_password()`, `check_ajax_referer()`, `wp_send_json_success/error()`, `wp_register_script()`, `wp_enqueue_script()`, `wp_localize_script()`, `wp_add_inline_script()`, `plugin_dir_path()`, `get_bloginfo()`, `_e()`, `esc_html_e()`, `esc_attr_e()`, `esc_js()`, `load_plugin_textdomain()`, `get_current_screen()`, `ShimWpdb::$base_prefix`.

### Changed

- `tests/audit-adminpage-callbacks.php` expanded from 49 → 108 checks: now verifies existence + visibility + `is_callable()` runtime + `has_action()` runtime for every registered callback, including the 4 new AJAX handlers and `enqueue_assets`. Does NOT lower the bar to make the test pass (directive §36).
- `ultimate-performance.php`: registers `wp_ajax_up_ajax_test_redis`, `wp_ajax_up_ajax_oc_runtime_phase1/2/3`, `admin_enqueue_scripts` in addition to the legacy `admin_post_uc_*` hooks. Both transports coexist safely.
- Bumped version 0.6.2 → 0.6.3 in `ultimate-performance.php` (header + constant), `readme.txt` (stable tag + new changelog entry), `CHANGELOG.md`.
- `tests/run-all-regression.sh`: now runs `audit-ajax-runtime` alongside `audit-adminpage-callbacks`.

## [0.6.2] — 2026-09-14

### Fixed (Critical — Runtime Closure)

- **Test Redis Connection button**: now performs the full §9 sequence — connect, authenticate, `SELECT` the configured database, `PING`, write a random temporary key, read it back, delete it. A connection-only test is no longer accepted as a pass. Previously the handler called `$r->ping()` and stopped.
- **Redis configuration from admin UI never reached the runtime backend**: `RedisBackend::__construct()` read only `ULTIMATE_CACHE_REDIS_*` constants and `UC_REDIS_*` env vars — the admin UI's `redis.host` / `redis.port` / `redis.db` / `redis.auth` / `redis.tls` Settings were silently ignored at runtime. This was the root cause of "DB=3 was configured but no expected cache data appeared in Redis DB 3" — the runtime never SELECTed DB=3 because it never received that value.
  - Added `RedisBackend::from_settings()` factory — builds a backend from the plugin's Settings option when constants/env are absent.
  - Added `RedisBackend::configured_via_constants()` — bifurcates the legacy constructor path (constants/env) from the new factory path (Settings).
  - `Manager::instance()` now routes through the factory when constants are absent. Same pattern applied to `MemcachedBackend`.
- **§8.1 result rendering**: Test Redis Connection result now renders Host, Port, Database, TLS, classified failure Phase, and timestamp on the Object Cache page. Failure phases include: Configuration, PHP Extension, Connect, Authenticate, SELECT Database, PING, Write Test Key, Read Test Key, Verified, Exception. Failures are no longer collapsed into "Failed".
- **§45 result persistence**: result is stored as a transient and survives the post-action redirect; the Object Cache tab shows the most recent test result with the timestamp.

### Added

- `tests/audit-redis-test-closure.php` (39 checks) — validates the admin POST → Settings option → from_settings() → capture db=3 path end-to-end via the wp-shim. Covers section isolation regression (saving Object Cache does not disable Master switch or Page Cache), Redis DB=3 persistence, prefix generation rules (woolena.ir → woolena, www.example.com → example, etc.), and static verification that the handler source performs SELECT, write, read, delete on a temporary test key.

## [0.6.1] — 2026-09-14

### Added

- **PHP Fallback Mode** (`advanced-cache.php` drop-in): serves cached responses with minimal PHP before full WordPress bootstrap. Works on shared hosting without root access or server configuration changes.
- **Environment Detector**: automatically detects active cache mode (`SERVER_ACCELERATED`, `PHP_FALLBACK`, `MISCONFIGURED`, `DISABLED`, `CONFLICTED`) and reports it to the admin interface.
- **Self-test probe**: verifies cache writability and readability on activation and on demand.
- **Cache stampede protection** (GenerationLock): flock-based single-flight lock prevents thundering herd on cold cache. Bounded wait with jitter, SWR support, crash-safe recovery.
- **Hybrid invalidation**: synchronous direct purge of the affected page's cache (`sync_purge_permalink`) before asynchronous tag-based fanout. Stale exposure reduced to ~0 seconds for directly edited pages.
- **WP-CLI cluster commands**: `wp ultimate-performance cluster status/epoch/events/reconcile`.
- **OpenLiteSpeed rules generator**: OLS-compatible rewrite rules with security and cookie bypass.

### Fixed

- **Homepage cache path (BENCH-D5)**: root path `/` now uses `ROOT_SENTINEL = 'uc-root'` (a valid, unhashed segment) instead of `'(root)'` which was hashed and mismatched Nginx `try_files` rules. Homepage now gets zero-PHP HITs via Nginx.
- **WooCommerce sanitizer over-block (BENCH-D4)**: removed broad substring patterns (`woocommerce-mini-cart-item`, `cart-count`, `cart-subtotal`, etc.) that falsely matched structural WooCommerce markup on 100% of Woo pages. Replaced with semantic regexes that only block rendered personalized content (actual cart items with `data-product_id`, rendered cart totals with `Price-amount`).
- **Async stale exposure (BENCH-D6)**: default `queue_backend='auto'` no longer causes multi-second stale windows. The edited page's own cache is purged synchronously before the request returns.
- **FallbackServer missing `<?php` tag**: generated `advanced-cache.php` was missing the opening `<?php` tag, causing PHP to treat it as plain text output. Fixed.
- **FallbackServer WP function dependency**: `FallbackServer::serve()` was calling `get_option()`, `wp_parse_url()`, `maybe_unserialize()` which are not available at the `advanced-cache.php` loading stage. Rewritten to use PHP builtins and direct `mysqli` settings loading.
- **FallbackServer HTTP_HOST port stripping**: `$_SERVER['HTTP_HOST']` includes the port (e.g., `127.0.0.1:8095`) but `Key::canonical_host()` strips it. Fixed by stripping port before computing cache path.
- **Uninstall table leak**: cluster tables (`uc_cluster_epoch`, `uc_cluster_nodes`) were not dropped on uninstall, leaving stale identity state. Fixed with symmetric cleanup.

### Qualified

- **Non-root regression**: 48 suites, 1,334 PASS, 0 FAIL, 11 SKIP (credential-gated RabbitMQ).
- **Fresh WordPress ZIP lifecycle**: install → activate → fallback MISS/HIT → server-accelerated zero-PHP → deactivate → uninstall → reinstall — ALL PASS.
- **Cold c100 stampede**: 516,111 requests, 28 timeouts (0.0054%), cache successfully generated.
- **WooCommerce mutations**: title, regular price, sale price, stock, variable product price/stock, category move, unpublish — ALL PASS.
- **Mode transition**: PHP_FALLBACK ↔ SERVER_ACCELERATED — PASS, no duplicate cache, no broken drop-in ownership.

### Artifact

- Version: 0.6.1
- Source HEAD: `070cb28`
- ZIP SHA-256: `7b436caadfec787d0d0c1407b07b51d4c06a5e71ed25294bfa17be35343685fc`

---

## [0.6.0] — 2026-09-13

### Added

- Zero-PHP page cache via web-server integration (Nginx `try_files`).
- Object cache with five backends: Redis, Memcached, APCu, SQLite, File.
- Promotion fencing: failed backends are reconciled (flushed) once before regaining service.
- Multisite blog-scope isolation.
- Cache invalidation via WordPress hooks (`save_post`, `transition_post_status`, `edit_term`, etc.).
- WooCommerce-aware request classification (cart/checkout/account bypass).
- ResponseSanitizer: defense-in-depth HTML audit before cache write and serve.
- SafeFs: filesystem safety abstraction with symlink/traversal protection.
- Cluster coordination: epoch authority, event store, node identity, watermark dedup.
- Queue subsystem: RabbitMQ → Action Scheduler → WP-Cron → synchronous fallback chain.
- Admin settings page with Nginx verify probe.
- Comprehensive test suite (48 suites, 1,334+ checks).

### Known Limitations

- Apache: configuration support available, limited live validation.
- OpenLiteSpeed: configuration support available, limited live validation.
- LiteSpeed Enterprise: not qualified (commercial license unavailable).
