=== Ultimate Performance ===
Contributors: alirezax5
Tags: cache, caching, performance, object-cache, page-cache, redis, memcached, sqlite
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.3
Stable tag: 0.6.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Production-grade WordPress caching platform: zero-PHP page-cache HITs via web-server integration plus a promotion-fenced object cache with automatic backend failover.

== Description ==

Ultimate Performance combines a page cache that answers public requests without
touching PHP (web-server-native HIT path, proven live on Apache; designed
for nginx and OpenLiteSpeed integrations) with a WordPress object cache that
supports five persistent backends and keeps serving traffic when one of them
dies.

**Object cache (five backends, one API).** Redis, Memcached, APCu, SQLite
(PDO) and the filesystem implement identical WordPress semantics: correct
`$found` flags for stored `false`/`null`/`0`/`''`, add/replace contracts,
never-auto-creating increment/decrement, per-group and network-wide flush
with O(1) invalidation (generation counters — no key scans, no
`FLUSHALL`/`FLUSHDB`/`flush_all`), global and non-persistent groups, and
Multisite blog-scope isolation via `switch_to_blog`.

**Promotion fencing.** A backend that failed and came back is reconciled
(flushed) once before it regains service rights, so data from an outage
window can never re-enter the chain; a healthy flush is never blocked by a
fenced backend. A monotonic chain epoch lets a fresh PHP process detect and
reconcile a backend that missed flushes while it was unreachable. The chain
is single-writer by default and honestly labelled eventual-consistency for
the standby layer.

**Page cache.** Cached pages are served by the web server itself. Request
classification keeps WooCommerce carts, checkout, admin, AJAX/REST and
logged-in traffic out of the cache. Invalidation is tag- and registry-based
with bounded concurrency, and purges run through a durable queue with a
fallback chain (RabbitMQ when available, local fallback otherwise).

**Operations.** An off-peak, timezone-aware, jittered WP-Cron scheduler runs
maintenance (SQLite janitor, warmup, telemetry snapshots) with dedup and
backpressure so runs never stack. Telemetry is bounded by construction:
fixed schema, enum-only labels, no URLs, cache keys, paths or credentials —
rendered as JSON and a Prometheus exposition.

**Warmup.** URL warmup plans come only from the plugin's own page-cache
tree (SSRF-safe by construction), reuse the one-and-only purge queue,
respect an epoch guard so a settings change aborts stale plans, and obey a
hard budget.

= Security =

Credentials are read from constants/environment only and are never logged,
persisted, or exposed in diagnostics. Database and state files live under an
HTTP-denied cache tree. Filenames are hash-based; symlinks are refused on
every read path. Cross-site request isolation and host-header poisoning are
covered by dedicated audit suites.

= Honest scope notes =

* APCu is a local (per-server) cache: shared across FPM workers on one
  machine, per-process under CLI. It is never advertised as distributed.
* File-backend `replace` is check-then-write (disclosed; not cross-process
  CAS). `add` is an atomic exclusive-create.
* Single-backend installs have no cross-process fence peer; staleness is
  bounded by TTLs and the in-process fence.
* Automated suites run against a WordPress compatibility shim plus real web
  servers, real Redis/Memcached/RabbitMQ daemons and real concurrent
  processes; full real-WP matrix coverage is a documented limitation.

== Installation ==

1. Upload the `ultimate-cache` directory to `/wp-content/plugins/` and
   activate the plugin.
2. Open the plugin admin page and enable the page cache and/or the object
   cache. Backends are configured explicitly via constants or environment
   variables (`UC_REDIS_HOST`/`UC_REDIS_SOCKET`, `UC_MEMCACHED_HOST`/
   `UC_MEMCACHED_SOCKET`, `UC_SQLITE_FILE`, `UC_FILE_CACHE_DIR`); a UNIX
   socket wins over TCP, with no fallback chain.
3. The object-cache drop-in is installed into `wp-content/object-cache.php`
   automatically when the object cache is enabled. Existing foreign drop-ins
   are never overwritten.
4. Optional: point the queue at RabbitMQ (`UC_RMQ_HOST` family); without a
   broker the local fallback queue is used.

== Frequently Asked Questions ==

= Does the page cache break WooCommerce? =

Cart, checkout, account, admin, AJAX/REST and logged-in requests are
classified and excluded from caching by dedicated suites.

= What happens when Redis goes down? =

Reads and writes fail over to the next backend in the chain (Memcached,
APCu, SQLite, file); the failed backend is fenced and reconciled before it
rejoins. WordPress never fatals; worst case is runtime-only caching.

= Does flush wipe other applications' data? =

No. Invalidation bumps generation counters; only the plugin's own keys are
affected. A foreign-key sentinel is asserted in the audit suites.

== Changelog ==

= 0.6.9 =
* FIX: Nginx verify probe now correctly reports "active" instead of
  "body mismatch" on woolena.ir. Root cause: try_files $up_cache_file
  looks for the file inside $document_root, but the file was at
  /up-cache/<host><path>/index.html — an internal location handled
  by alias. Switched to the rewrite-based approach (rewrite ... last
  to /up-cache/ location with alias) which correctly serves cached
  files via the alias.
* IMPROVEMENT: examples/nginx.sample.conf now uses the rewrite-based
  approach (matches the production deployment). All woolena.ir
  references removed — file is now fully generic with <PLACEHOLDER>
  tokens.
* IMPROVEMENT: docs/installation.md "Nginx server acceleration setup"
  section completely rewritten. Now includes:
  - Quick setup with the full nginx snippet
  - Explanation of why rewrite-based approach is better than
    try_files $up_cache_file (hides cache path from logs, allows
    custom headers, handles MISS fallback)
  - Important note about nginx's "if is evil" gotcha — server-scope
    if blocks run in rewrite phase, location-scope if blocks don't
  - Skip-cache cookies reference (explains which cookies bypass cache)
* AUDIT: Negative-impact audit confirms no regressions:
  - All cacheable pages (homepage, /shop/, /faq/) serve as HIT
  - All uncacheable pages (/cart/, /checkout/, /wp-admin/) serve
    as BYPASS or PHP fallback
  - All bypass cookies (logged-in, cart-hash, session, comment-author)
    correctly skip cache
  - POST requests, query strings, wp-* URLs all bypass correctly

= 0.6.8 =
* IMPROVEMENT: Added new "Hybrid (Best)" serving mode. When Nginx server
  acceleration is verified AND the PHP fallback drop-in is also active,
  the plugin now reports "Hybrid (Best)" mode instead of "PHP Compatibility".
  This represents the best of both worlds: zero-PHP HITs on cached pages
  via the web server, with PHP fallback as backup for edge cases.
* IMPROVEMENT: The Serving mode widget on the Server Integration tab now
  shows: (1) the mode name with a colored badge, (2) a one-line reason
  explaining the mode, (3) a detailed legend describing what each mode
  means, and (4) a performance comparison (Speed / TTFB / PHP usage)
  so users can see at a glance what their current mode achieves.
* IMPROVEMENT: When the site is in "PHP Compatibility" mode, a tip is
  shown recommending to upgrade to "Hybrid (Best)" via the "Verify
  Nginx rules" button — estimated 5× speed improvement.
* IMPROVEMENT: examples/nginx.sample.conf is now fully generic — no
  woolena.ir references. All site-specific values use <PLACEHOLDER>
  tokens (e.g. <SITE_HOST>, <DOCROOT>, <CACHE_ROOT>, <PHP_FPM_SOCK>)
  that the user must replace with their own values.
* CODE: detect_mode() now reads the up_nginx_probe transient to detect
  server acceleration (instead of only checking drop-in state). This
  correctly distinguishes PHP_FALLBACK from SERVER_ACCELERATED from
  HYBRID based on the actual probe result.

= 0.6.7 =
* FIX: Persian (Farsi) localization now works. Added load_plugin_textdomain()
  call in the main plugin file (it was missing — only the "Text Domain"
  header was set but no load_plugin_textdomain() was ever called).
* ADDED: languages/ultimate-performance-fa_IR.mo — compiled Persian
  translation file (125 of 253 strings translated; untranslated strings
  fall back to English).
* ADDED: examples/nginx.sample.conf — sample Nginx configuration showing
  how to integrate Ultimate Performance with Nginx for direct web-server
  cache HITs (zero PHP on cached requests). Includes architecture diagram,
  map directives, location blocks, and security hardening.
* CONFIG: Server Integration tab now configured for woolena.ir with
  nginx.origin = 127.0.0.1:8080 and nginx.listen = 127.0.0.1:8097.
* CONFIG: Nginx per-site extension file installed at
  /www/server/panel/vhost/nginx/extension/woolena.ir/up-cache.conf
  (does NOT modify main nginx.conf — only adds cache-serving rules
  to woolena.ir's server{} block via the existing extension include).

= 0.6.6 =
* REBRAND: Removed all leftover "UC" / "UltimateCache" / "Ultimate Cache"
  references throughout the codebase. The plugin now consistently uses
  "UP" / "Ultimate Performance" everywhere — HTML IDs, JS variables,
  query args, POST field names, transients, DB tables, cron schedule,
  global vars, function names, class names, nginx config variables,
  telemetry metric names, drop-in file comments, config file markers,
  and source code comments.
* MIGRATION: On activation, the plugin automatically migrates data from
  pre-0.6.6 installations:
  - DB tables: uc_cluster_epoch → up_cluster_epoch,
    uc_cluster_nodes → up_cluster_nodes,
    uc_invalidation_events → up_invalidation_events
    (via RENAME TABLE — preserves all rows, no data loss)
  - Transients: every uc_* transient is read, copied to the new up_*
    name, then deleted (12 transient pairs migrated)
  - Cron schedule: events scheduled with the 'uc_every_minute'
    recurrence are rescheduled with 'up_every_minute' (hook names
    ultimate_performance_tick / _janitor / _telemetry unchanged)
* KEEP: Backward-compat for drop-in ownership markers — the plugin still
  recognizes both 'UltimateCache' and 'Ultimate Performance' as ownership
  markers when checking whether to remove object-cache.php and
  advanced-cache.php drop-ins. This ensures old drop-ins installed by
  pre-0.6.6 versions still get cleaned up on deactivation/uninstall.
* KEEP: Backward-compat for '# BEGIN Ultimate Cache' config file markers
  in user's .htaccess / nginx.conf / wp-config.php — the plugin's
  managed-block detector still recognizes both old and new marker names
  so existing config files don't lose their managed blocks on upgrade.

= 0.6.5 =
* FIX: Plugin deactivation now wipes the cache directory
  (wp-content/cache/ultimate-performance/) so the host is left clean.
  Previously deactivation only removed the drop-in files
  (object-cache.php / advanced-cache.php) but left the cache tree on disk,
  which confused admins looking at "where did my disk space go?" and any
  caching reverse proxy that might still be configured to read from
  this path.
* FIX: Plugin deactivation now also deletes ALL plugin transients
  (uc_env_snapshot, uc_oc_dropin_status, uc_registry_overflow,
  uc_settings_errors, uc_save_summary, uc_redis_test, uc_oc_runtime_test,
  uc_amqp_test, uc_oc_action). Previously deactivation only cleared
  cron hooks and drop-ins.
* FIX: Plugin uninstall (delete) now removes the legacy
  wp-content/cache/ultimate-cache/ directory too — sites that upgraded
  from ultimate-cache 0.6.x previously had a leftover cache tree.
* FIX: Plugin uninstall now removes the drop-in files
  (object-cache.php / advanced-cache.php) and the WP_CACHE line in
  wp-config.php even when the plugin was deleted via FTP without
  prior deactivation. Single source of truth via
  Installer::delete_all_plugin_transients() helper.
* FIX: Plugin deactivation now clears the third cron hook
  (ultimate_performance_telemetry) that was missed in previous versions.
* IMPROVEMENT: Installer::deactivate() and Installer::uninstall_data()
  now share a single delete_all_plugin_transients() helper so adding a
  new transient in the future only requires updating one place.

= 0.6.4 =
* FIX: Object Cache tab save now shows a detailed "what changed" notice. For
  Redis password and RabbitMQ password, the notice explicitly states
  "preserved (no change)" when the field was left blank — closing the
  perceived "blank field deletes the password" gap. The actual preservation
  logic (resolve_secret) has been correct since 0.6.3; 0.6.4 adds the
  visibility layer so the user can verify it.
* FIX: JavaScript confirm() dialog now intercepts the "Clear saved Redis
  password" and "Clear saved RabbitMQ password" checkboxes. Accidentally
  checking either box no longer silently erases the stored password on the
  next Save click.
* FIX: The in-form "Password configured: yes" indicator is now bold and
  reads "(will be preserved when this field is left blank)" so the contract
  is explicit in the UI.
* ADDED: tests/audit-object-cache-save-flow.php — 8-scenario audit walking
  every form-ownership case through Settings::save_from_admin(). All 8 PASS:
  OC-general preserves redis.auth; Redis-blank preserves; Redis-new replaces;
  Redis-clear clears; Memcached preserves; AMQP preserves; Redis-omitted
  preserves; Redis-whitespace-only preserves.
* ADDED: Settings::last_save_summary() public API returning a structured
  {section, subsection, changes[]} map of what the most recent save did.

= 0.6.3 =
* CRITICAL FIX: Test Redis Connection and Test Object Cache Runtime buttons
  no longer trigger a full-page reload. Real AJAX transport via
  assets/js/admin.js posts to /wp-admin/admin-ajax.php and renders the
  result inline. Window.location does not change. The previous
  admin_post_* page-refresh regression is closed.
* CRITICAL FIX: Object Cache Runtime Test is now a real 3-phase state
  machine — Phase 1 (write test object) → Phase 2 (cross-request
  wp_cache_get in a SEPARATE admin-ajax.php request) → Phase 3 (delete +
  verify). Each phase is a distinct HTTP request, not a single PHP call.
* CRITICAL FIX: the previous invalid-callback fatal (enqueue_assets
  registered but not implemented) is closed by IMPLEMENTING enqueue_assets()
  on AdminPage. The method gates to Ultimate Performance admin pages, registers
  assets/js/admin.js, and localizes UP_ADMIN config (ajaxUrl + per-action
  nonces + i18n strings). The callback audit verifies is_callable() at
  runtime.
* CRITICAL FIX: all 4 wp_ajax_up_ajax_* hooks now point to implemented
  AJAX handlers (ajax_test_redis, ajax_oc_runtime_phase1/2/3). has_action()
  proof > 0 at runtime. The previous closure that replaced them with
  admin_post_* (causing page refresh) is reverted in favor of the real
  AJAX architecture.
* §16/§17/§30: Test Redis and Test Object Cache Runtime buttons now use
  type="button" (not the default "submit") so they cannot trigger form
  submission even if JS is disabled.
* §14: AJAX config localized via wp_localize_script() — JS does NOT
  hardcode /wp-admin/admin-ajax.php.
* §15: admin.js cache-bust via filemtime() so browsers never retain stale
  JS after a deployment.
* §8/§9: Redis AJAX contract — success=true (transport), data.ok (Redis
  verdict), data has host/port/db/tls/phase/msg/timestamp, NEVER returns
  password.
* §27: AJAX transport failure is distinct from Redis service failure —
  transport failures render HTTP status + raw snippet; service failures
  render the actual Redis error inline with FAIL.
* New audit: tests/audit-ajax-runtime.php (73 checks) — invokes each
  AJAX handler in-process, verifies JSON shape, nonce/capability 403s,
  internal _value/_group fields are stripped, enqueue_assets gating,
  callback matrix.
* Updated audit: tests/audit-adminpage-callbacks.php (108 checks, was 49)
  — verifies existence + visibility + is_callable() runtime + has_action()
  runtime for every registered callback including enqueue_assets and the
  4 new AJAX handlers.

= 0.6.2 =
* CRITICAL FIX: "Test Redis Connection" button now performs the full
  sequence — SELECT configured DB, write a random temporary test key,
  read it back, delete it. A connection-only test is no longer accepted.
* CRITICAL FIX: Redis configuration entered in the admin UI (host, port,
  DB, password, TLS) now actually reaches the runtime backend. Previously
  RedisBackend read only constants/env vars, so admin-configured DB=3 was
  silently ignored at runtime. Added RedisBackend::from_settings() factory
  + configured_via_constants() bifurcation; Manager::instance() now routes
  through the factory when constants are absent. Same pattern applied to
  MemcachedBackend for consistency.
* §8.1: Test Redis Connection result now renders host, port, db, TLS,
  timestamp, and a classified failure phase (Connect / Authenticate /
  SELECT Database / PING / Write Test Key / Read Test Key / Verified /
  Exception). Failures are no longer collapsed into "Failed".
* §45: Result persists visibly on the Object Cache page after redirect,
  with separate success/failed query-arg suffix.
* New audit: tests/audit-redis-test-closure.php (39 checks) — validates
  the admin POST → Settings option → from_settings() → SELECT DB=3 path
  end-to-end via the wp-shim. All 39 PASS.

= 0.6.1 =
* Hardening release: PHP fallback mode (advanced-cache.php drop-in),
  environment detector, cache stampede protection (GenerationLock),
  hybrid sync+async invalidation, OpenLiteSpeed rules writer,
  WP-CLI cluster commands. Real WordPress 6.0.9/6.4.5/6.7.2 HTTP matrix
  with zero-PHP HIT proven at HTTP level.
* Multisite subdirectory + subdomain: cross-site isolation proven with
  unique canaries (0 leakage). WooCommerce 9.5.2 cart/checkout/account
  bypassed correctly; product/shop/category cacheable.
* Non-root regression: 48 suites, 1334 PASS, 0 FAIL.

= 0.6.0 =
* Phase Q stable release: real WordPress 6.0.9/6.4.5/6.7.2 HTTP matrix
  with zero-PHP HIT proven at HTTP level (execution counter = 0 on HIT,
  = 1 on MISS after purge). PHP-FPM production runtime (fpm-fcgi SAPI).
* Multisite subdirectory + subdomain: cross-site isolation proven with
  unique canaries (0 leakage). WooCommerce 9.5.2 cart/checkout/account
  pages with cookie bypass. Cross-user isolation verified.
* Two-node HTTP cluster: real Nginx+FPM WordPress nodes sharing one
  MariaDB, separate node identities, separate page-cache roots.
* RabbitMQ 4.0.5: connect/publish/consume/ack + wrong auth/vhost
  rejection + broker kill/restart/recovery.
* OpenLiteSpeed 1.9.2 + real LSAPI (litespeed SAPI): lsphp 8.5.4
  serving real WordPress via OLS.
* Performance: 83,220 req/sec cached (wrk benchmark), 840,493 cached
  HTTP requests with 0 PHP executions.
* MariaDB 11.8.6: 10000-event load test (publish 5000 ev/s, drain
  13000 ev/s), FOR UPDATE row lock, 5 concurrent producers × 20 bumps
  = 100 unique epoch values.
* Memcached -s TCP disable fix + 3-daemon runner (Phase N N4A-D1).
* Cluster table leak on uninstall fix (Phase N N5-D1).
* WP-CLI cluster commands: status/epoch/events/reconcile registered
  via WP_CLI::add_command.
* OLS Rules writer: security rules + cookie bypass + config fuzzing
  (40 PASS).

= 0.5.0 =
* Real-matrix hardening: the page cache and its invalidation hooks are now
  verified live on real WordPress 6.0/6.6/6.7 and WooCommerce 8.2/10.2
  installations with a real MariaDB, including the WP-CLI command surface
  and the `--format=json` contract.
* Nginx integration: production snippet generator (composite map gate,
  internal-only cache location, .php never served from disk, full canary
  denials) with a live integration gate proving zero PHP execution on
  cache HITs; admin page now renders the snippet and a verify-probe button.
* Cluster invalidation (multi-node WP sharing one database, per-node cache
  roots): versioned invalidation events in a shared table, local-first
  propagation, bounded batched consumption via the existing tick,
  per-origin watermark dedup, chain-epoch stale-generation guard, janitor
  pruning, fixed-schema cluster metrics (published/consumed/duplicates/
  failures/stale/lag). Proven live on a two-node gate (Node A edit →
  Node B stale copy removed) with 100/1000-event bursts.
* RabbitMQ queue transport verified against a real broker (connection,
  publish/consume, receipts, failover matrix, broker-kill failure
  injection with fail-closed recovery).
* Uninstall: dedicated uninstall.php removes settings, transients, cron
  hooks, the purge capability, the whole cache tree and the shared cluster
  events table.
* Telemetry schema 2: adds cluster counters. Defects fixed: node identity
  never persisted (unstable cluster origin), purge payload corrupted
  (booleans instead of directories), empty payload JSON shape, monotonic
  counter regression, and a producer race that could silently drop
  cluster invalidation events when the local purge ran synchronously.

= 0.4.0 =
* Object cache: SQLite (PDO, WAL, BEGIN IMMEDIATE transactional arithmetic,
  bounded janitor) and File (SafeFs atomic writes, hash names, symlink
  refusal) backends.
* Promotion fencing: reconcile-before-reuse for recovered backends; chain
  epoch for cross-process stale detection; single-writer chain with honest
  eventual-consistency disclosure.
* WP-Cron scheduler: off-peak, timezone-aware, jittered, deduplicated,
  backpressure-guarded, budget-capped maintenance hooks.
* Telemetry: fixed-schema JSON + Prometheus exposition, enum-only labels.
* WordPress.org readiness: readme.txt, honest tested-version headers, POT
  template.
* Defects fixed: SQLite lost updates under multi-process contention
  (BEGIN IMMEDIATE); Redis backend default-constructor config regression;
  APCu auto-creating counters.

= 0.3.0 =
* Memcached and APCu object-cache backends with a capability matrix;
  backend-chain failover isolation; Multisite semantics; warmup platform
  (SSRF-safe planner, queue reuse, epoch guard, budget); memcached live
  gate.

= 0.2.0 =
* Object cache platform: semantics layer, Redis backend, drop-in ownership
  state machine, O(1) invalidation, live gate on real Redis.

= 0.1.0 =
* Page cache engine, web-server HIT path (Apache live-proven), request
  classification, tag invalidation, queue platform, RabbitMQ integration
  with fallback chain, sandboxed audit harness.

== Upgrade Notice ==

= 0.4.0 =
Adds SQLite and file object-cache backends, promotion fencing for recovered
backends, scheduled maintenance and bounded telemetry. Requires PHP 8.3+
(tested floor).
