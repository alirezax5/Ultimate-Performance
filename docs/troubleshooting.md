# Troubleshooting

English | [فارسی](troubleshooting-fa.md)

This guide covers the most common issues operators encounter with
Ultimate Performance, how to diagnose them, and how to fix them.

## Index

1. [Cache always MISS](#1-cache-always-miss)
2. [FALLBACK-HIT not appearing](#2-fallback-hit-not-appearing)
3. [SERVER_ACCELERATED not detected](#3-server_accelerated-not-detected)
4. [Nginx config missing or not applied](#4-nginx-config-missing-or-not-applied)
5. [advanced-cache.php conflict](#5-advanced-cachephp-conflict)
6. [WP_CACHE disabled](#6-wp_cache-disabled)
7. [Cache directory not writable](#7-cache-directory-not-writable)
8. [Homepage not cached](#8-homepage-not-cached)
9. [WooCommerce cart incorrectly cached](#9-woocommerce-cart-incorrectly-cached)
10. [Logged-in users getting cached pages](#10-logged-in-users-getting-cached-pages)
11. [Cache invalidation debugging](#11-cache-invalidation-debugging)
12. [Redis / Memcached unavailable](#12-redis--memcached-unavailable)
13. [SQLite extension missing](#13-sqlite-extension-missing)
14. [Permission problems](#14-permission-problems)
15. [Host / port cache key issues](#15-host--port-cache-key-issues)
16. [Running the self-test](#16-running-the-self-test)
17. [Collecting diagnostic information](#17-collecting-diagnostic-information)

---

## 1. Cache always MISS

### Symptoms

Every request returns `X-Ultimate-Performance: MISS` (or, in Server
Accelerated mode, every response goes to PHP-FPM with no speedup).

### Causes and fixes

| Cause | How to check | Fix |
|---|---|---|
| Page cache disabled | `Settings → Ultimate Performance → Enable page cache` is unchecked | Tick the box and save. |
| Classifier BYPASS | Enable `debug_headers` in settings; the response will carry `X-Ultimate-Performance-Reason: <reason>`. | Address the reason (e.g. logged-in cookie, query param, bypass path). |
| Logged-in cookie | `X-Ultimate-Performance-Reason: cookie:wordpress_logged_in_*` | Log out of WordPress, or use a private window for testing. |
| Query param not allowlisted | `X-Ultimate-Performance-Reason: query:<param>` | Add the param to `query_allowlist`, or set `query_unknown_policy = strip` (drops unknown params before keying). |
| POST / non-GET method | `X-Ultimate-Performance-Reason: method:POST` | Expected behavior — POST is never cached. |
| Path is a bypass slug | `X-Ultimate-Performance-Reason: bypass-path:cart` | Expected behavior — cart/checkout/etc. are never cached. |
| Cache root not writable | Self-test reports `cache_writable: false` | See [#7](#7-cache-directory-not-writable). |
| Status code not 2xx/3xx | `X-Ultimate-Performance-Reason: status:404` | The page returns a non-cacheable status; the body is never stored. |
| ResponseSanitizer refused | `X-Ultimate-Performance-Reason: unsafe-html:<reason>` | The page contains a nonce, logged-in markup, or a rendered Woo cart fragment. The Classifier should already BYPASS these — investigate why the page reached the sanitizer. |

## 2. FALLBACK-HIT not appearing

### Symptoms

PHP fallback mode is configured (drop-in present, `WP_CACHE` true), but
cached responses do not carry `X-Ultimate-Performance: FALLBACK-HIT`.

### Causes and fixes

| Cause | How to check | Fix |
|---|---|---|
| `WP_CACHE` not defined | Open `wp-config.php` and look for `define('WP_CACHE', true);` | Add the line above the `/* That's all, stop editing! */` comment. |
| Foreign drop-in present | `EnvironmentDetector` reports `CONFLICTED` | Disable or remove the other caching plugin's drop-in, then deactivate + reactivate UC. |
| Drop-in not ours | Read first 256 bytes of `wp-content/advanced-cache.php`; should contain `/* Ultimate Performance advanced-cache.php v1 */` | Deactivate + reactivate UC. If it still does not install, see [#5](#5-advanced-cachephp-conflict). |
| Host with port not stripped (pre-0.6.1) | Cache file written at `v/127.0.0.1/index.html` but request looks at `v/127.0.0.18095/index.html` | Upgrade to 0.6.1 — the `FallbackServer` port-stripping fix resolves this. |
| Request has a query string | Fallback bypasses any non-empty query string | Expected behavior — the full Classifier runs in WordPress if it boots. |
| Request has a sensitive cookie | Fallback checks `cookie_bypass_regex` | Log out of WordPress for testing. |
| Path is a bypass slug | Fallback checks `bypass_paths` | Expected behavior. |
| Cache body < 64 bytes | Fallback refuses to serve tiny bodies | Investigate why WordPress produced a tiny response. |

## 3. SERVER_ACCELERATED not detected

### Symptoms

`EnvironmentDetector` reports `MISCONFIGURED` instead of
`SERVER_ACCELERATED`.

### Causes and fixes

| Cause | How to check | Fix |
|---|---|---|
| Cache root not writable | Self-test fails | See [#7](#7-cache-directory-not-writable). |
| Server not in supported list | `EnvironmentDetector::detect_server()` returns `unknown` or `iis` | The server is not supported for Server Accelerated mode. Use PHP fallback mode instead. |
| Plugin disabled | `enabled = false` in settings | Enable the plugin. |
| Page cache disabled | `page_cache_enabled = false` in settings | Enable the page cache. |

`detect_mode()` returns the **configured** mode, not the verified mode.
A returned `SERVER_ACCELERATED` only means the configuration is present
— run the Nginx verify probe to confirm the server is actually serving
statically. See [#4](#4-nginx-config-missing-or-not-applied).

## 4. Nginx config missing or not applied

### Symptoms

The admin screen shows the generated snippet, but the verify probe does
not pass — the response is served by PHP, not by Nginx.

### Causes and fixes

| Cause | How to check | Fix |
|---|---|---|
| Snippet not included in `nginx.conf` | `sudo nginx -T \| grep up-cache` returns nothing | Add `include /absolute/path/to/ultimate-performance-nginx.conf;` inside the `http {}` block of `nginx.conf`. |
| Nginx not reloaded after config change | `sudo nginx -t` succeeds but old behavior persists | `sudo nginx -s reload`. |
| Wrong cache_root path in the snippet | Open the generated snippet and check the `alias` line | Regenerate the snippet from the admin screen (corrects the path automatically). |
| Wrong host in the snippet | The `server_name` does not match the site host | Re-enter the host in the admin screen and regenerate. |
| `try_files` does not find the homepage | The cache file is at `v/<host>/uc-root/index.html` but `try_files /up-cache/$host//index.html` is what you wrote | Use `try_files $uri $uri/ /up-cache/$host$uri/index.html /index.php?$args;` — the trailing slash on `$uri/` is important. |
| `.php` requests served from disk | `curl -I https://example.com/index.php` is very fast and returns cached content | The `.php` location in the snippet always proxies to the origin — verify the snippet was applied as-is. |

## 5. advanced-cache.php conflict

### Symptoms

`EnvironmentDetector` reports `CONFLICTED` — a foreign
`advanced-cache.php` exists.

### Diagnosis

```bash
head -5 wp-content/advanced-cache.php
```

If the first 256 bytes do not contain
`/* Ultimate Performance advanced-cache.php v1 */`, the drop-in is owned by
another plugin.

### Fix

1. Identify the owner (the comment in the file usually names the
   plugin).
2. Disable or remove that caching plugin via `wp-admin → Plugins`.
3. Delete `wp-content/advanced-cache.php` manually if the other plugin
   did not clean it up.
4. Deactivate Ultimate Performance, then reactivate. The drop-in will be
   installed.

UC **never** overwrites a foreign drop-in — this is a release-blocking
safety contract.

## 6. WP_CACHE disabled

### Symptoms

`EnvironmentDetector` reports `PHP_FALLBACK` is unavailable even
though the drop-in exists.

### Cause

WordPress loads `wp-content/advanced-cache.php` only when
`define('WP_CACHE', true);` is present in `wp-config.php`.

### Fix

Edit `wp-config.php` and add the line **above** the
`/* That's all, stop editing! */` comment:

```php
define('WP_CACHE', true);
```

Save and reload the site. `EnvironmentDetector` should now report
`PHP_FALLBACK` (or `SERVER_ACCELERATED` if Nginx is configured).

## 7. Cache directory not writable

### Symptoms

Self-test reports `cache_writable: false`. `EnvironmentDetector`
reports `MISCONFIGURED`. Page cache never writes files.

### Diagnosis

```bash
ls -la wp-content/cache/ultimate-performance/
sudo -u www-data touch wp-content/cache/ultimate-performance/test-write
```

If the `touch` fails, the web server user cannot write to the cache
root.

### Fix

```bash
# Replace www-data with your web server user (apache, nginx, etc.)
sudo chown -R www-data:www-data wp-content/cache/ultimate-performance
sudo find wp-content/cache/ultimate-performance -type d -exec chmod 755 {} \;
sudo find wp-content/cache/ultimate-performance -type f -exec chmod 644 {} \;
```

If the cache root does not exist, deactivate and reactivate UC —
`Installer::ensure_cache_root()` creates it.

## 8. Homepage not cached

### Symptoms

All pages cache correctly except `/` (the homepage). Cached file at
`v/<host>/uc-root/index.html` is never written, or `try_files` never
finds it.

### Causes

| Cause | How to check | Fix |
|---|---|---|
| Pre-0.6.1 ROOT_SENTINEL bug | Cache file is at `v/<host>/h<sha1[:20]>/index.html` instead of `v/<host>/uc-root/index.html` | Upgrade to 0.6.1 — `ROOT_SENTINEL = "uc-root"` ensures the homepage uses an unhashed segment. |
| Hand-written `try_files` with wrong path | `try_files /up-cache/$host/index.html` (no `uc-root` segment) | Use `try_files $uri $uri/ /up-cache/$host$uri/index.html /index.php?$args;` — the `$uri/` form maps `/` correctly. |
| Homepage has a redirect | `curl -I https://example.com/` shows 301 to `https://example.com` (no trailing slash) | The redirect is by WordPress (`home_url()` normalization). The cache key uses the post-redirect path — verify with the admin self-test. |
| Homepage has a query string | The site uses `/?lang=en` as the homepage | Add `lang` to `query_allowlist` (already in default) or set `query_unknown_policy = strip`. |

## 9. WooCommerce cart incorrectly cached

### Symptoms

A user's cart items appear on a cached page served to other users.

### Causes

This is a **release-blocking** defect and should never happen in 0.6.1.
If you observe it:

| Cause | How to check | Fix |
|---|---|---|
| `bypass_paths` misconfigured | `Settings → Ultimate Performance → Bypass paths` does not include `cart`, `checkout`, etc. | Restore the default `bypass_paths` (the list is in `Settings::defaults()`). |
| `cookie_bypass_regex` does not include Woo cookies | The regex does not match `woocommerce_*` or `wp_woocommerce_session_*` | Restore the default `cookie_bypass_regex`. |
| Cart fragment on a non-Woo page | A theme renders a cart widget on every page (header / sidebar) | The ResponseSanitizer catches **rendered** cart items (an `<li>` with `mini_cart_item` AND `data-product_id`). If a custom theme renders cart items in a different shape, add a custom pattern via the `ultimate_performance_unsafe_html_regexes` filter. |
| Pre-0.6.1 broad-substring sanitizer over-block | Pre-0.6.1: empty cart container blocked every Woo page | Upgrade to 0.6.1 — semantic patterns now match only rendered content. |

If you observe a cart leak, **report it as a security issue** via
GitHub Issues (see [Security policy](../SECURITY.md)).

## 10. Logged-in users getting cached pages

### Symptoms

A logged-in admin sees the anonymous cached page (no admin bar, no
"Howdy, Name").

### Causes

| Cause | How to check | Fix |
|---|---|---|
| `cookie_bypass_regex` misconfigured | The regex does not match `wordpress_logged_in_*` | Restore the default `cookie_bypass_regex`. |
| Server Accelerated mode serves cached HTML to a logged-in user | The Nginx snippet's `$up_cookieless` map only matches an **empty** `Cookie:` header — any cookie (including logged-in) makes the request dynamic | Verify the Nginx snippet was applied as-is; do not modify the cookie check. |
| Browser cached the page locally | The URL responds with `X-Ultimate-Performance: BYPASS` but the browser still shows old content | Hard-refresh (Ctrl+F5) or clear the browser cache. |
| Logged-in cookie path mismatch | Cookie is set for a different path / domain | Inspect cookies in the browser dev tools. |

## 11. Cache invalidation debugging

### Symptoms

Editing a post does not update its cached page; the old content
remains.

### Diagnosis

1. Enable `debug_headers`. Edit a post in `wp-admin`.
2. The post's permalink should now return `X-Ultimate-Performance: MISS` on
   the next request (the synchronous `sync_purge_permalink` removed the
   cached entry).
3. If the permalink still returns `X-Ultimate-Performance: HIT` (Server
   Accelerated mode: no header, but body is old), check:

| Cause | How to check | Fix |
|---|---|---|
| `invalidation_enabled = false` | Read settings | Enable invalidation. |
| Hook not registered | Inspect `Hooks::register()` — the `save_post` action should be wired | Re-activate the plugin. |
| Cluster event not consumed (multi-node) | `wp ultimate-performance cluster events` shows pending events | Run `wp ultimate-performance cluster reconcile` (local node only). |
| Tag registry out of sync | The `meta/tag-<md5>.json` file does not list the post's rel_dir | Run a purge-all to rebuild the index. |
| Page is cached at a CDN edge | The response has a `CF-Cache-Status` or `X-Cache` header from a CDN | Purge the CDN cache for that URL. |

### Verifying invalidation ran

The `Hooks::purge_post()` action fires `ultimate_performance_after_purge_post`
with the post ID, the tags, and the count of purged dirs. Hook into it
for diagnostics:

```php
add_action( 'ultimate_performance_after_purge_post', function( $post_id, $tags, $n ) {
    error_log( "UC purge_post: post=$post_id tags=" . implode( ',', $tags ) . " n=$n" );
}, 10, 3 );
```

## 12. Redis / Memcached unavailable

### Symptoms

Object cache hits drop to zero; `wp_options` queries return to direct
DB reads.

### Diagnosis

`UltimatePerformance\ObjectCache\Manager` selects the first healthy backend
in the chain. If Redis is configured but unreachable:

- The Redis backend is marked dead for the remainder of the request.
- The selector falls through to the next backend (Memcached, APCu,
  SQLite, File, then WP-internal memory).
- WordPress never fatals — worst case is runtime-only caching.

### Fix

```bash
# Redis
redis-cli -h 127.0.0.1 -p 6379 ping
# Memcached
echo stats | nc 127.0.0.1 11211
```

If the daemon is down, restart it. If the credentials are wrong, fix
`UC_REDIS_AUTH` / the admin screen.

To verify the backend is wired correctly:

```bash
wp eval 'var_dump( wp_cache_get( "test_key", "test_group" ) );'
```

This should return `false` (the key does not exist) without error.

## 13. SQLite extension missing

### Symptoms

Selecting the SQLite object cache backend fails silently; the selector
falls through to the next backend.

### Diagnosis

```bash
php -m | grep -i pdo
php -m | grep -i sqlite
```

The SQLite backend requires `pdo` and `pdo_sqlite`.

### Fix

Install the extensions:

```bash
# Debian / Ubuntu
sudo apt-get install php8.3-sqlite3
sudo systemctl reload php8.3-fpm
```

If you cannot install extensions on shared hosting, use APCu or the
File backend instead.

## 14. Permission problems

### Symptoms

- Cache directory not writable (see [#7](#7-cache-directory-not-writable)).
- Drop-in cannot be installed (`AdvancedCacheDropin::install()` returns
  false).
- Object cache file backend fails to write.

### Diagnosis

```bash
# Web server user (Debian/Ubuntu: www-data, RHEL: apache)
ps aux | grep -E 'nginx|apache|php-fpm' | grep -v grep
ls -la wp-content/cache/ultimate-performance/
ls -la wp-content/advanced-cache.php
```

### Fix

```bash
# Replace www-data with your web server user
sudo chown -R www-data:www-data wp-content/cache
sudo chown www-data:www-data wp-content/advanced-cache.php
sudo chmod 644 wp-content/advanced-cache.php
sudo find wp-content/cache/ultimate-performance -type d -exec chmod 755 {} \;
sudo find wp-content/cache/ultimate-performance -type f -exec chmod 644 {} \;
```

The hardened `.htaccess` in the cache root (`Installer::cache_htaccess()`)
is regenerated automatically if it drifts from the generator output.

## 15. Host / port cache key issues

### Symptoms

- Cache file written for host `example.com` but request looks for
  `example.com:443`.
- Fallback mode writes to `v/127.0.0.18095/` instead of
  `v/127.0.0.1/`.

### Cause

`Key::canonical_host()` strips the port from the host. The 0.6.1
`FallbackServer` port-stripping fix ensures the fallback also strips the
port. Pre-0.6.1 builds had a mismatch: `Key::canonical_host` stripped
the port, but `FallbackServer` did not — so the fallback looked for
`v/127.0.0.18095/index.html` (the port concatenated to the host)
instead of `v/127.0.0.1/index.html`.

### Diagnosis

```bash
# Check the cache tree
ls wp-content/cache/ultimate-performance/v/
# If you see a directory like '127.0.0.18095' or 'example.com:443',
# you have a host/port mismatch.
```

### Fix

1. Upgrade to 0.6.1.
2. Deactivate + reactivate the plugin (or just click **Purge entire
   cache**).
3. Verify with `curl -s -o /dev/null -D - https://example.com/ | grep -i x-ultimate-performance`.

## 16. Running the self-test

The admin self-test (`EnvironmentDetector::run_self_test()`) writes a
probe cache entry, reads it back, and deletes it. It tests the cache
store, not the web server's static-serve configuration.

### Manual self-test

```bash
wp eval '
$r = \UltimatePerformance\Core\EnvironmentDetector::run_self_test();
var_dump( $r );
'
```

Expected output:

```
array(4) {
  ["cache_writable"]=> bool(true)
  ["probe_written"]=> bool(true)
  ["probe_readable"]=> bool(true)
  ["probe_token"]=> string(16) "..."
}
```

If `cache_writable` is false, see [#7](#7-cache-directory-not-writable).

### Nginx verify probe

The Nginx verify probe (`WebServer\Nginx\Rules::probe_uri()` /
`probe_body()`) is the active-status proof for Server Accelerated mode.
Run it from the admin screen (the **Run verify probe** button). It
writes a probe file at `/uc-verify-<token>/` with a byte-identical body,
then issues an HTTP request; if the response body matches
`probe_body($token)` exactly, the snippet is serving statically.

## 17. Collecting diagnostic information

When reporting an issue, include:

1. **Ultimate Performance version:** `wp eval 'echo ULTIMATE_PERFORMANCE_VERSION;'`
2. **EnvironmentDetector status:** from `Settings → Ultimate Performance`.
3. **Server software:** `curl -I https://example.com/ | grep -i server`.
4. **PHP version:** `php -v` and `wp cli info`.
5. **WordPress version:** `wp core version`.
6. **Settings dump (sanitized):** from the admin screen (the **Export
   settings** button if available, or copy the visible fields).
7. **Self-test result:** from the admin screen.
8. **Error log entries:** from `wp-content/cache/ultimate-performance/stats.jsonl`
   (bounded, no credentials).
9. **Reproduction steps:** exact URL, exact request (`curl -I ...`).
10. **Expected vs. actual behavior.**

**Never** share database credentials, Redis/Memcached passwords, or any
secret. The plugin's credential policy reads them from constants /
environment only and never logs them.

## See also

- [Installation](installation.md) — verify and uninstall procedures.
- [Hosting guide](hosting.md) — choosing the right mode.
- [Configuration](configuration.md) — full settings reference.
- [Technical reference](technical.md) — request lifecycle and key generation.
- [Security overview](security.md) — credential policy and cache poisoning defenses.
