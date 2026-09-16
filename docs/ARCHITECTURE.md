# Ultimate Performance — Architecture

> Priority order: SECURITY > CORRECTNESS > RELIABILITY > COMPATIBILITY > PERFORMANCE > CONVENIENCE.
>
> Hard rule: a safe public Page Cache HIT is `HTTP Request → Web Server → Static HTML → Browser` with **0** WordPress bootstrap, **0** PHP execution, **0** MySQL query, **0** Redis/Memcached/RabbitMQ request.

## 1. Layer separation

| Layer | Responsibility | Never does |
|---|---|---|
| A. Page Cache | Complete rendered HTML responses for public anonymous GETs | Store personalized/authenticated HTML; touch external services on HIT |
| B. Object Cache | WP object cache backend (`wp_cache_*`) for queries/options/posts | Serve pages; participate in public HIT path |
| C. Queue | Async/background work (preload, bulk purge, warmup) | Block a page render; sit on the HIT path |
| D. Opcode Cache | PHP OPcache — owned by PHP/server | Reimplemented by this plugin |
| E. Browser/HTTP cache | `Cache-Control`/`ETag`/`Vary` headers | Mark private responses `public` |

## 2. Public Page Cache HIT lifecycle (Apache)

```
HTTP GET /
 → Apache mod_rewrite (.htaccess block "# BEGIN Ultimate Performance")
 → bypass checks evaluated purely by RewriteCond:
      method != GET/HEAD                     → skip to WP
      cookie matches auth/cart/nonce pattern → skip to WP
      query string non-empty & not allowlisted → skip to WP
      request path maps into excluded dirs    → skip to WP
 → deterministic mapping:
      https://example.com/product/iphone-15/
        → wp-content/cache/ultimate-performance/v/example.com/product/i/p/iphone-15/index.html
      (sharding: first+second char of last significant segment; caps depth)
 → RewriteCond -f succeeds → serve file directly (Laravel-style flag chain),
   append cached headers via mod_headers (X-Ultimate-Performance: HIT is NOT set here;
   static serving has no PHP — debug header only in WP-level paths)
 → MISS → WordPress boots normally
```

No PHP. No MySQL. No Redis. The mapping is pure filesystem arithmetic from the URL.

### MISS lifecycle
```
WP boots → advanced-cache.php (if installed & owned by us) re-classifies request
 → PUBLIC_CACHEABLE + enabled → PageCache\Store lookup (PHP-level)
   → HIT: emit stored headers + body, X-Ultimate-Performance: HIT, exit before theme load
   → MISS: continue; ob_start captures output at shutdown of template render
     → Engine content-type gate: only text/html + application/xhtml+xml cacheable;
       anything else (json/xml/js/binary/downloads/streams) BYPASS, never scanned
     → ResponseSanitizer decides CACHEABLE vs BYPASS (defense-in-depth backstop,
       fail-closed: strong indicator OR scanner malfunction ⇒ refuse write)
     → CACHEABLE: bounded needle+regex audit (never strips nonces — a page with
       tokens is simply not cached) → atomic write (.tmp + rename) → headers recorded
     → BYPASS: nothing written; reason logged to stats counter
```

### Stale-while-revalidate
Expired entry found → if SWR enabled and entry not past hard-TTL×grace: serve stale, schedule single background regeneration through Queue. Regeneration guarded by Lock (see §12).

## 3. Object Cache lifecycle

```
wp-settings.php loads object-cache.php drop-in ONLY if user installs it.
Plugin NEVER auto-installs the drop-in when another exists (conflict detect → report).
Drop-in = thin shim → Core\Bootstrapper\ObjectCacheBootstrap::instance($blog_id)
 → BackendSelector picks first healthy from configured chain:
     [existing-persistent] Redis → Memcached → APCu → SQLite → File → WP-internal
 → every backend implements ObjectCache\Backend interface:
     get/set/add/delete/deleteGroup/flushGroup/getMultiple(atomic where possible)/
     incr/decr/isHealthy/ping/stats
 → unhealthy backend (connect fail, corruption) → marked dead for remainder of request,
     selector falls to next; failure logged once per request (no retry storm)
 → group semantics preserved: non-persistent groups stay in-memory even on persistent backends
 → multisite: keys prefixed {site_key}::{group}::{key}; site_key = blog_id or network slug
```
Redis/Memcached/APCu/SQLite/File are all OPTIONAL. With none present, selector lands on WP internal (non-persistent) — site works normally.

## 4. Queue lifecycle

```
enqueue(Job) → QueueManager → first available backend:
   RabbitMQ (php-amqplib present AND broker reachable AND configured)
   → ActionScheduler (function_exists('as_enqueue_async_action'))
   → WPCron (schedule single event now; processed on next cron tick / SPAI-nudge)
   → Local (in-process worker loop after shutdown for CLI/preload runner)
   → Sync (run inline — last resort, bounded count per request)
Job envelope: id(UUID7), type, payload(strict-typed array), attempts, max_attempts, run_after
Worker: claims job (backend lease), runs Handler registry, ack/retry/backoff/discard-poison.
RabbitMQ never required; never on page HIT path.
```

## 5. Cache key specification

Canonical key input (in order):
1. scheme — `https` or validated `http`; mismatched scheme never collapses with HTTPS variant unless admin merges them
2. host — from `$_SERVER['SERVER_NAME']` intersect allowlist of `wp_parse_url(home_url())` hosts; untrusted Host header → BYPASS (poisoning defense)
3. path — normalized: decode once, reject `..`, collapse `//`, lowercase trailing-slash-insensitive compare, strip known index files
4. query — allowlisted params only, sorted, values urldecoded-then-recanonicalized; ANY unknown param → classification BYPASS (default) or explicit admin opt-in list; tracking params (`utm_*`, `gclid`, `fbclid`, `msclkid`, …) stripped always
5. variants — ordered tuple, each only when admin-enabled AND actually changes HTML: `v=webp` (accept header), `v=mobile` (separate mobile theme), compression is transparent to key (files stored uncompressed; server compresses)

Key string: `GET https://host/path?k=v|v:webp` → hashed (xxh128-equivalent: hash('sha256')) only for filenames needing flattening; directory tree uses path segments (deterministic, human-browsable).

Filesystem layout:
```
wp-content/cache/ultimate-performance/
  v/<host>/<seg1>/<seg2>/…/<index.html>          body
  v/<host>/…/<index.html.meta.json>              status, headers, ttl, tags[], uuid7 id, created
  meta/<uuid7>.json                               invalidation reverse-index (tag → path hashes)
```
Shard long paths: segments beyond depth 6 collapse into `<sha1(first 96 chars)>.d/` dir to cap depth. Filenames sanitized `[A-Za-z0-9._-]{1,64}`, anything else hashed. Traversal impossible: every segment passes `Sanitizer::segment()` which rejects `.`/`..`/`\`/null/leading dots.

UUID7 = object ID/tracing/invalidation/logging/admin ID only. Lookup stays deterministic path-mapping.

## 6. Security threat model (summary — full doc SECURITY.md)

| Threat | Defense |
|---|---|
| Host-header poisoning | Host must match site allowlist else BYPASS |
| Query poisoning / explosion | Unknown params bypass; allowlist; sorted canonicalization |
| Cookie-based poisoning | Cookies never enter key; presence of sensitive cookies → bypass |
| Cache deception (`/.json`, path confusion) | Extension denylist never cached; path normalized before mapping |
| Authenticated response leakage | Logged-in cookie regex → bypass BEFORE any lookup/write |
| Nonce leakage into public cache | ResponseSanitizer defense-in-depth scan (bounded needles + regex classes: hidden token fields, data-* token attrs, JSON/JS security keys incl. escaped/minified forms, logged-in/admin-bar markup, WC cart/checkout fragments, session ids) → refuse to cache; scanner malfunction also refuses (fail-closed); sanitizer NEVER strips nonces — page with tokens is simply not cached |
| Authenticated response leakage (server-side) | Classifier runs again inside advanced-cache.php — double gate |
| Path traversal | Segment sanitizer + realpath containment check (Windows junction-aware: accept XP junction resolution but verify final path prefix under cache root) |
| Symlink attack | Cache dir scanned: entries that are symlinks/junctions pointing outside root → quarantined+deleted by janitor; writes refuse symlink targets |
| Malicious cache file exec | `.html` served as static; `.meta.json` parsed as JSON only; cache dir protected by generated .htaccess (`php_flag engine off`, deny .php) + web.config for IIS parity |
| Response splitting | Header values sanitized `\r\n` strip before storing/re-emitting |
| Compression variant confusion | Single uncompressed representation; negotiation left to server |
| Content-negotiation confusion | Vary emitted correctly; AVIF/WebP variant only behind explicit flag keyed into filename |
| Race on write | tmp file in same dir + `rename()` atomicity; Windows: retry rename loop |
| Stampede | Lock (flock-based, TTL'd, owner-token) + stale-serve + single-flight regeneration |
| Amplification/exhaustion | Preload concurrency cap; queue max-per-run; lock wait bounds; disk quota janitor |
| Cross-site cache reads | host segment + blog namespace in every path/key |

## 7. Fallback matrix

Page cache: native LSCache (LiteSpeed/OpenLiteSpeed detected) > htaccess rewrite serving (Apache) > nginx config (template, admin-applied) > advanced-cache.php PHP serving > plain WP.
Object cache: existing drop-in respected → Redis → Memcached → APCu → SQLite → File → WP internal.
Queue: RabbitMQ → Action Scheduler → WP-Cron → Local loop → Sync inline.

Every arrow crossing is capability-tested at runtime, never assumed. Unavailable ≠ fatal.

## 8. Failure matrix (high level)

| Failure | Behavior |
|---|---|
| Redis down at runtime | object cache falls through next backend same-request; one warning log |
| RabbitMQ down | enqueue routes to ActionScheduler/WP-Cron; UI shows FALLBACK state |
| SQLite file corrupt | integrity_check on open fail → quarantine file, rebuild, fall through |
| htaccess unwritable | Apache integration shows NOT CONFIGURED; WP-level cache still works |
| cache dir unwritable | page cache auto-disables (BYPASS), diagnostics ERROR, site normal |
| lock contention timeout | serve stale if allowed else pass through to WP uncached |
| preload flood | concurrency semaphore + per-host rate limit + pause API |

## 9. Concurrency strategy

- All cache writes: unique tmp name (`.<uuid7>.tmp`) → write → `fflush` → `fclose` → `@rename` (retry ≤5 × 20ms on Windows sharing violation). Readers see old or new, never partial.
- Regeneration locks: flock exclusive non-blocking on `<path>.lock` with owner UUID + mtime TTL (default 30s); stale locks stolen after TTL; waiters either serve-stale or proceed-uncached (config).
- Stats counters: per-file increments with flock, batched flush.
- SQLite backend: WAL mode, `busy_timeout=250ms`, single writer OK, retries on busy.
- Queue claim leases with visibility timeout; crashed worker leases expire.

## 10. Invalidation strategy

Tags attached at cache-write time (post:N, term:N, post_type:T, product:N, front_page, url:<hash>, woocommerce:shop…). Reverse index `meta/tag-<md5(tag)>.json` lists object IDs. Purge by tag = read index → delete objects + their files (dirs GC'd bottom-up). Hook map:
- `save_post` → post tag + its terms tags + post_type tag (+ front/blog/shop when listed)
- `deleted_post` → same + delete object
- `transition_comment_status`, `edit_term`, `edited_term_taxonomy` → term tags
- `option:update` [active plugins, theme mods…] → site-wide (explicit)
- WooCommerce: `woocommerce_update_product` → product:N + product_type + shop page; order events → NO public purge (orders are private), but woocommerce:cartfrag marker bump.
Site-wide purge requires confirm dialog + capability `ultimate_performance_purge_all`.

## 11. Per-server strategy

**Apache**: first-class. Generated `# BEGIN/END Ultimate Performance` block inside .htaccess: RewriteCond bypass gauntlet + `-f` check + direct serving; mod_headers adds cached headers from a sidecar `.headers` file? — no: headers baked INTO the html file top? no — decision: store headers in sibling `.meta.json` is unreadable by Apache; instead we bake response headers as a leading JSON comment line `<!--uc-meta:{...}-->`? That pollutes bytes. FINAL: Apache serves body only; safe static headers (`Content-Type text/html; charset=UTF-8`, `Cache-Control` from settings, `Vary: Accept-Encoding,Cookie` omitted—cookie bypass already handles) come from mod_headers static rules in the block. Dynamic per-object headers unnecessary for safety.
**Nginx**: generate `ultimate-performance-nginx.conf` snippet (try_files against mapped path + map for bypass cookies) into plugin `config/out/`; admin copies include manually. Never claimed active until admin pastes "verify" token check passes (a probe URL served statically).
**LiteSpeed Enterprise**: use LSCache natively — plugin emits `X-LiteSpeed-Cache-Control: public,max-age=T` + `X-LiteSpeed-Tag: uc_post_123,…` on cacheable responses; purge via `X-LiteSpeed-Purge` on POST from admin; generic FS cache disabled when LSCache active.
**OpenLiteSpeed**: same LSCache API surface (public cache + tags; ESI/private limited) — capability-detected, degrade gracefully.

## 12. Shared hosting constraints honored
No shell_exec required anywhere. No wp-config edit required (advanced-cache.php optional and conflict-checked). Works with only .htaccess write OR even zero integration (WP-level). All storage under `wp-content/cache/ultimate-performance` (created on demand). PHP-safe: no proc_open needed for core paths.

## 13. Self-review findings (addressed pre-implementation)
1. `.meta.json` readable over HTTP → moved meta outside webroot assumption impossible (wp-content IS webroot) → mitigate: generated deny rules + meta filename starts `.` + random suffix; Apache block includes `RedirectMatch 404 \.meta\.json$`. Risk residual on servers ignoring .htaccess — accepted, data is non-sensitive (no PII; contains URLs+tags only).
2. Windows `rename()` overwrite works (unlike POSIX when target exists) — both directions covered by delete-then-rename fallback with lock held.
3. flock on FAT/exFAT absent — XAMPP uses NTFS; guard `flock===false` → degrade to lock-free best-effort + log.
4. `advanced-cache.php` loads before `wp_salt()` exists → shim must be dependency-free pure PHP; config snapshot written to `wp-content/cache/ultimate-performance/uc-boot.json` at settings-save time, read by shim.
5. Multisite: per-blog subdirs keyed by blog ID; switch_to_blog purges scoped.

## 14. Cluster page-cache invalidation (M5, shipped)

Design contract: docs/PHASE-M-CLUSTER-INVALIDATION.md (committed before code).
Shipped exactly as designed, with the following concrete shape:

- **Membership = shared database.** Every node serving the same DB is a
  member. Node identity is a uuid7 persisted INSIDE the per-node cache root
  (`meta/node-id.json`) — independent roots, independent identities, stable
  across restarts. The node id is the event `origin`.
- **Wire:** table `{base_prefix}uc_invalidation_events` (network-wide, lazy
  `CREATE TABLE IF NOT EXISTS` — no migration step on upgrade, proven live).
  Versioned events: `id` (monotonic), `event_id` (uuid7, unique),
  `schema_version`, `origin`, `epoch`, `scope` (`purge_dirs`|`purge_all`),
  `payload` (JSON object; consumer re-validates EVERY dir), `created` (ms),
  `consumed`.
- **Propagation:** local-first (the deciding node purges synchronously,
  exactly as a single-node install); the DECIDED dirs are passed to
  `ultimate_performance_after_purge_tags` as a third argument and published as one
  event (M5-D5: re-deriving from the registry inside the listener races with
  the local purge — synchronous-queue installs detached the dirs BEFORE the
  listener ran and silently lost every event). Consumers drain pending
  foreign events on the existing `ultimate_performance_tick` in `id` order,
  bounded batch (200, filterable), executing the same validated local purge.
  The HIT path is untouched: filesystem only, no queue/DB round-trip.
- **Idempotency & boundedness:** per-origin watermark file (monotonic ids)
  makes replay a no-op (`duplicates` counter); unknown schema/scope skipped
  and marked consumed (no poison loops); janitor deletes consumed rows past
  a 24h retention and NEVER pending rows (proven live, C9).
- **Epoch guard:** events whose `epoch` is older than the consumer's chain
  epoch (from the object-cache backends — `Manager::current_epoch()`) are
  counted (`stale`) and skipped. Runtime-only object cache (no persistent
  backend) yields epoch 0 → the guard never skips there (documented vacuity;
  the negative case is unit-proven via the `ultimate_cache_cluster_epoch`
  filter seam).
- **Anti-thundering-herd:** origin-only warmup (design option (c)) — only
  the deciding node warms; consumers repopulate lazily on first MISS.
- **Metrics (telemetry schema 2):** `cluster_events_published/consumed/
  duplicates/failures/stale` counters + `cluster_lag_ms` gauge, schema-locked
  in `Cluster\State`, aggregated once per consumption round.
- **Live gate:** tests/run-cluster-live.sh — two REAL WP nodes sharing one
  MariaDB, independent cache roots: A's edit purges A locally (C3), B keeps
  its stale copy until its own tick (C4 isolation) and then loses it via the
  cluster event (C5); 100/1000-event bursts fully consumed within
  ceil(n/200) ticks (C7); real janitor contract (C9); metrics visible on
  both nodes (C10). End-to-end latency recorded (~1.9 s including both
  manual ticks; production schedules both ticks independently).
