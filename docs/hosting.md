# Hosting guide

English | [فارسی](hosting-fa.md)

Ultimate Performance supports two serving modes for the page cache. The active
mode is auto-detected by `UltimatePerformance\Core\EnvironmentDetector` and
surfaced in the admin UI. This guide explains both modes, the detector
states, and the web-server support matrix.

## Mode A — Server Accelerated (best performance)

```
HTTP GET /
  → Nginx try_files (or Apache .htaccess, or LiteSpeed)
  → reads wp-content/cache/ultimate-performance/v/<host>/<path>/index.html
  → serves static HTML
  → PHP-FPM NEVER INVOKED on a HIT
```

Server Accelerated mode requires web-server configuration: a generated
`http{}` include (Nginx), an `.htaccess` block (Apache), or native
LiteSpeed cache headers. The plugin's `WebServer\Nginx\Rules`,
`WebServer\Apache\Rules`, and `WebServer\OpenLiteSpeed\Rules` classes
emit the configuration; the admin applies it.

**Best for:** dedicated servers, VPS, and managed hosts that allow
server-level config. **Performance:** measured ~17 000 req/s on a ~84 KB
real WordPress page (Nginx 1.28, PHP 8.5-FPM, Ubuntu 26.04). Zero PHP
executions on HIT (verified with an origin-side execution counter).

The plugin **never** claims active status without a verify probe: the
admin screen writes a probe file at `/uc-verify-<token>/` with a
byte-identical body, issues an HTTP request, and checks that the
response body matches `probe_body($token)`. The integration is
"configured" only when the probe passes.

## Mode B — PHP Fallback (shared hosting, no root)

```
HTTP GET /
  → wp-settings.php loads wp-content/advanced-cache.php (owned by UC)
  → FallbackServer::serve() runs BEFORE WordPress fully boots
  → if cache HIT and fresh: emit headers + body, X-Ultimate-Performance: FALLBACK-HIT, exit
  → on MISS: return control to WordPress; Engine captures and stores the response
```

PHP Fallback mode works on every server that runs WordPress and requires
no web-server integration. The `advanced-cache.php` drop-in is installed
on plugin activation (when `php_fallback_enabled = true`, the default)
**only if no foreign drop-in exists**. The drop-in starts with `<?php`
followed by the `OWNERSHIP_MARKER` comment (`/* Ultimate Performance
advanced-cache.php v1 */`); on deactivation, it is removed only if we
still own it.

**Best for:** shared hosting, environments without root access, sites
that cannot modify Nginx/Apache config. **Performance:** measured ~3 000
req/s on the same ~84 KB page; TTFB on a HIT is ~3.5 ms.

The drop-in loads **before** WordPress's `wp-includes/option.php` is
loaded, so it cannot call `get_option()`. Instead, it reads settings
directly from the database using the credentials in `wp-config.php`
(`DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_HOST`) and merges them with
`Settings::defaults()`. It uses only PHP built-ins and the plugin's own
namespaced classes — never WP functions like `wp_parse_url` or
`trailingslashit` that would conflict with the real versions loaded
later by `wp-includes`.

`FallbackServer::is_cacheable_request()` performs a simplified
cacheability check:

- Bypass any non-GET / non-HEAD method.
- Bypass any request whose cookies match `cookie_bypass_regex` (auth,
  cart, session, comment, nonce cookies).
- Bypass any request whose path starts with a `bypass_paths` slug
  (cart, checkout, my-account, wc-api, …).
- Bypass any request with a non-empty query string (the full Classifier
  runs later if WordPress boots).

On a HIT, the drop-in emits the stored headers, sets
`X-Ultimate-Performance: FALLBACK-HIT`, sets the HTTP status, echoes the body,
and exits — WordPress never finishes booting.

## EnvironmentDetector states

`EnvironmentDetector::detect_mode()` returns one of five constants:

| Constant | Label | Meaning | Trigger |
|---|---|---|---|
| `SERVER_ACCELERATED` | Server Accelerated | Page cache configured; the server is expected to serve cached bodies. Run the self-test probe to confirm. | Page cache enabled, cache root writable, no foreign drop-in, server in the supported list. |
| `PHP_FALLBACK` | PHP Compatibility | The `advanced-cache.php` drop-in owned by UC is present and `WP_CACHE` is `true`. | Our drop-in present, `WP_CACHE` true. |
| `MISCONFIGURED` | Misconfigured | The cache root is not writable, or the detected server is not in the supported list. | `Installer::ensure_cache_root()` returns false, or server is `unknown`/`iis`. |
| `DISABLED` | Disabled | The plugin or the page cache is disabled in settings. | `enabled = false` or `page_cache_enabled = false`. |
| `CONFLICTED` | Conflicted | A foreign `advanced-cache.php` drop-in is present. UC will not overwrite it. | Drop-in exists, header not our marker, not foreign-removed. |

`detect_server()` reads `$_SERVER['SERVER_SOFTWARE']` and returns one
of `nginx`, `apache`, `litespeed`, `ols`, `iis`, `unknown`. Note that
`SERVER_SOFTWARE` is the most reliable signal — `apache_get_modules()`
is used as a fallback only when `SERVER_SOFTWARE` is empty.

The admin UI calls `get_status_summary()` to render the mode label and
color. **True** server-accelerated detection requires a live verify
probe (the admin self-test); `detect_mode()` returns the *configured*
mode, not the *verified* mode.

## Support matrix

| Web server | Status | Notes |
|---|---|---|
| **Nginx** | Fully qualified | Generated `http{}` include (composite map gate for `GET/HEAD + cookieless + no query`, internal-only cache location with `alias`, `.php` never served from disk, `X-UP-Original-URI` propagation, fail-closed input gates, verify-probe contract). Recommended for Server Accelerated mode. Live-qualified on Nginx 1.28.3 with zero PHP executions on HIT (verified by an origin-side execution counter). |
| **Apache** | Limited validation | `.htaccess` rewrite gauntlet (`# BEGIN/END Ultimate Performance` block: RewriteCond bypass gauntlet + `-f` check + direct serving) verified on Apache 2.4.58+. Broader live-matrix coverage (full bypass matrix on real Woo, real WP) is partial. The plugin ships the rules writer (`src/WebServer/Apache/Rules.php`) and a hardened storage `.htaccess` that denies every filename except `index.html`. |
| **OpenLiteSpeed** | Limited validation | Rootless provisioning real (LiteSpeed/1.7.19 Open binary on high port 8088, real HTTP 200/404 responses, real `Server: LiteSpeed` header, real HEAD support). Rules writer shipped (`src/WebServer/OpenLiteSpeed/Rules.php`, 40 audit checks). PHP-via-LSAPI backend (real WP through lsphp) is **partial** — building `lsphp` is a separate build from CLI PHP and was not completed in this release. The full bypass matrix (POST/query/logged-in/WooCommerce/session/private-route/wp-config.php denial/.env/.git/encoded traversal) requires a real WP backend through LSAPI. |
| **LiteSpeed Enterprise** | Not qualified | Ultimate Performance does **not** claim PASS from OpenLiteSpeed evidence. The two have different binary builds, different feature flags, and different bug-for-bug compatibility. LiteSpeed Enterprise remains not-qualified until a real Enterprise license is provisioned and tested. |

The PHP fallback mode (`advanced-cache.php` drop-in) works on every
server that runs WordPress and requires no web-server integration; it
is the recommended mode for environments in the "Limited validation" and
"Not qualified" rows.

## Choosing a mode

| Environment | Recommended mode | Recommended backend |
|---|---|---|
| Dedicated server / VPS, Nginx, Redis available | Server Accelerated (Nginx include) | Redis object cache |
| Dedicated server / VPS, Apache only | Server Accelerated (.htaccess) | Memcached or APCu |
| Shared hosting, no root, cPanel/Plesk | PHP Fallback (`advanced-cache.php`) | APCu (single server) or SQLite (if PDO available) |
| Managed WordPress hosting (Kinsta/WP Engine/Flywheel) | Host's native cache + PHP Fallback | Use the host's Redis if available |
| LiteSpeed Enterprise | Not qualified — use PHP Fallback | APCu or Redis |
| OpenLiteSpeed | PHP Fallback (Rules writer ships, LSAPI backend partial) | APCu or SQLite |

## Per-server notes

### Nginx

The generated snippet uses **one** `http{}` include (not a per-server
block) so the composite map (`$up_method_ok`, `$up_cookieless`,
`$up_static`) is evaluated once at the http level, not per-request. Each
`location` carries exactly one `if` whose body is only a `rewrite-last`
— this avoids the Nginx "if is evil" trap (a matching `if` with a bare
`set` creates an implicit pseudo-location that swallows the request
before `try_files` ever runs).

The `.php` location always proxies to the PHP origin (never served from
disk) — this prevents source disclosure if a cached `.php` body ever
appeared in the cache tree (which it never should; the Classifier rejects
any `.php` in the path).

The `/up-cache/` location is `internal;` — direct external requests
receive a 404. Only the internal `rewrite` can land there.

### Apache

The `.htaccess` block generated by `WebServer\Apache\Rules` implements
the bypass gauntlet as RewriteCond lines (method, cookie regex, query
string, bypass paths), the deterministic `-f` check against the mapped
cache path, and the static serve via a `T=application/xhtml+xml` flag
chain. The cache root also contains a generated `.htaccess`
(`Installer::cache_htaccess()`) that denies every filename except
exactly `index.html` (case-sensitive lookahead), plus a case-insensitive
extension blacklist as belt-and-braces.

### OpenLiteSpeed / LiteSpeed Enterprise

For LiteSpeed Enterprise and OpenLiteSpeed with the LSAPI backend, the
plugin emits `X-LiteSpeed-Cache-Control` and `X-LiteSpeed-Tag` headers
via `WebServer\LSCacheHeaders` and queues purge URIs through
`LSCacheHeaders::queue_purge_uri()` / `queue_purge_all()`. LiteSpeed
native cache should be preferred when available; the plugin's own
file-based cache can be disabled to avoid double-caching.

## See also

- [Installation](installation.md) — ZIP install, activation, mode verification, uninstall.
- [Configuration](configuration.md) — full settings reference including `php_fallback_enabled`, `nginx.origin`, `nginx.listen`.
- [Technical reference](technical.md) — request lifecycle, cache key generation, atomic writes, GenerationLock.
- [Troubleshooting](troubleshooting.md) — `SERVER_ACCELERATED not detected`, `FALLBACK-HIT not appearing`, and other common issues.
