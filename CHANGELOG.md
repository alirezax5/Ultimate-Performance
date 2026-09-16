# Changelog

All notable changes to Ultimate Performance are documented in this file.

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

- **Plugin uninstall (delete) now removes the legacy `wp-content/cache/ultimate-cache/` directory** too — sites that upgraded from ultimate-cache 0.6.x previously had a leftover cache tree that was never cleaned.

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
- `enqueue_assets($hook_suffix)` on AdminPage: gates to Ultimate Performance admin pages (`settings_page_ultimate-cache`, `toplevel_page_ultimate-cache`), registers `ultimate-performance-admin` script with `wp_register_script`, localizes `UC_ADMIN` config (ajaxUrl + per-action nonces + i18n strings) via `wp_localize_script`, cache-busts via `filemtime()`.
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
