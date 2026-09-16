# Security overview

English | [فارسی](security-fa.md)

This document is the user-facing security overview of Ultimate Performance.
For the full technical threat model (STRIDE per subsystem, defense
layering, audit checklist), see [docs/SECURITY.md](SECURITY.md). For the
responsible disclosure policy, see the repository-level
[SECURITY.md](../SECURITY.md).

Ultimate Performance follows a fail-closed principle throughout: if the plugin
cannot confidently prove a response is safe for public caching, it
**BYPASS**es. Priority order: **SECURITY > CORRECTNESS > RELIABILITY >
COMPATIBILITY > PERFORMANCE > CONVENIENCE**.

## Cache poisoning protections

### Host handling

`UltimatePerformance\CacheKey\Key::canonical_host($host)` is the host defense
layer:

- Lowercases, trims, rejects control chars / whitespace / `/ \ ? # @ %
  [ ]`, rejects > 253 chars.
- Strips a trailing `:port` **before** the bare-IPv6 branch (so
  `example.com:8080` is not mistaken for an IPv6 literal).
- Accepts bracketed and bare IPv6, normalizing to a deterministic
  colon-free form for FS safety.
- Strips the trailing dot from an FQDN.
- Validates the hostname against a strict label regex.

`UltimatePerformance\Request\Classifier::host_allowed($canonical_host)`
further restricts the host to the site's allowlist:
`wp_parse_url(home_url(), PHP_URL_HOST)` plus, on Multisite, every site
in the network via `get_sites()`. `SERVER_NAME` is **never** trusted —
it can be attacker-controlled on misconfigured servers.

If the canonical host is empty or not on the allowlist, the request is
classified `BYPASS` with reason `host-not-allowed:<host>`. A poisoned
`Host:` header cannot select another site's cache tree.

### Query string handling

`Classifier::check_query($query)` enforces:

- Empty param names ⇒ `BYPASS`.
- Tracking params (`utm_*`, `gclid`, `fbclid`, `msclkid`, `dclid`,
  `twclid`, `mc_eid`, `igshid`, `_ga`) are stripped from the cache key
  always, regardless of policy.
- Unknown params follow `query_unknown_policy`:
  - `bypass` (default) ⇒ any unknown param forces BYPASS.
  - `strip` ⇒ unknown params dropped, page cached under the canonical
    key.
  - `variant` ⇒ each unknown param value gets its own cache dir.
- Allowlisted params count toward a cap of 8; more than 8 ⇒ `BYPASS`
  with reason `too-many-params`.

This prevents query-string farming attacks where an attacker generates
many unique URLs to flood the cache.

### Cookie and private-session bypasses

`Classifier::sensitive_cookie($cookies)` matches every cookie name
against `cookie_bypass_regex` (default covers `wordpress_*` auth
cookies, `wp-postpass`, `comment_author`, `woocommerce_*`,
`wp_woocommerce_session_*`, `PHPSESSID`, `wp-settings-*`). Any match ⇒
`BYPASS`.

In the Nginx snippet, the composite map `$up_cookieless` matches only
an empty `Cookie:` header. Any cookie (including logged-in) makes the
request dynamic and routes to the PHP origin.

`FallbackServer::is_cacheable_request()` performs the same cookie check
in the early-boot drop-in path.

### Bypass paths

`bypass_paths` (default: `cart`, `checkout`, `my-account`, `wc-api`,
`wishlist`, `compare`, `order-pay`, `order-received`, `orders`,
`view-order`, `edit-address`, `lost-password`, `customer-logout`) is
checked by both the `Classifier` and the `FallbackServer`. Any path
starting with one of these slugs (case-insensitive) is never cached.

### Reserved WordPress routes

`Classifier::reserved_prefixes()` returns the list of WordPress
reserved paths that are never publicly cached: `/wp-admin`,
`/wp-login.php`, `/wp-cron.php`, `/wp-json`, `/xmlrpc.php`,
`/wp-content/plugins`, `/wp-content/themes`,
`/wp-content/uploads/woocommerce_uploads`, `/feed/`, `/comments/feed`,
`/author/`, `/wp-login`.

The Classifier also rejects:

- Any `.php` in the path (PHP dispatch never publicly cached).
- Reserved dynamic files (`robots.txt`, `favicon.ico`, `wp-cron.php`,
  `wp-login.php`, `xmlrpc.php`, `wp-links-opml.php`, `wp-signup.php`,
  `wp-activate.php`, `wp-trackback.php`, `wp-comments-post.php`,
  `.env`, `wp-config.php`).
- Dotfiles (excluding `.well-known/`) and backup artifacts (`.bak`,
  `.old`, `.orig`, `.save`, `.swp`, `.sql`, `.log`, `.ini`, `.conf`).
- Cache-deception extensions (`deny_extensions`: `php`, `json`, `xml`,
  `axd`, `aspx`, `jsp`, `cgi`, `phar`, `phtml`, `svg`).
- Search, preview, and `s=` / `preview=` query params.
- Sitemaps and feeds (`sitemap*`, `feed`, `rss2?`, `atom`).
- Path fragments (`#` in the URI — fragments never reach the server; if
  one appears, the URI is hostile).

## Path traversal protection

### Segment sanitizer

`Key::segment($seg)` rejects `.` and `..` (hashing them as
defense-in-depth), case-folds segments for NTFS/macOS
case-insensitivity, and accepts only segments matching
`^[a-z0-9][a-z0-9._\-]{0,63}$`. Anything else is hashed to
`h<sha1[:20]>`. Traversal via URL is impossible: every segment is
sanitized, and the writer never invents a path the reader wouldn't.

### SafeFs containment

`UltimatePerformance\Core\SafeFs` is the only filesystem abstraction the
plugin uses. Every FS op routes through it. Containment invariant: no
operation may escape the allowed roots — including via symlinks, NTFS
junctions, 8.3 short names, trailing-dot/space Win32 aliasing, case
variation, or UNC paths.

`SafeFs::validate_write($path)` walks the deepest existing ancestor,
`realpath()`s it (collapsing symlinks and junctions), and verifies the
resolved path sits inside an allowed root. Two valid geometries are
supported (normal: ancestor inside the root; fresh: root subtree
missing — accepted only when `realpath` proves the ancestor contains the
root). Junctions / symlinks in any existing component redirect the
resolved path and fail both clauses.

`SafeFs::write_atomic()` refuses to write if the deepest existing
ancestor is a symlink, and `read()` / `delete()` / `scandir()` always
check `is_link()` and refuse to follow.

## Atomic writes

`SafeFs::write_atomic($path, $data)`:

1. Validates the path lexically + root containment + resolved containment
   of the deepest existing ancestor.
2. Creates the parent directory if needed.
3. Writes to a unique temp file (`.<unique>.tmp`) in the same directory.
4. `fflush()` + `fclose()` to flush the OS buffer.
5. `rename()` to the target — atomic on POSIX, with a 5-retry × 20 ms
   delete-then-rename fallback on Windows sharing violations.
6. Returns `true` only on a successful rename. On failure, the temp file
   is unlinked.

Readers see old-or-new, never partial. `Store::write()` writes the body
via `write_atomic()`, then writes the sidecar `index.html.meta.json`
(also via `write_atomic()`); if the meta write fails, the body is
deleted — never leave a body without meta.

## Drop-in ownership (OWNERSHIP_MARKER)

`UltimatePerformance\Compatibility\AdvancedCacheDropin` manages the
`wp-content/advanced-cache.php` drop-in with strict ownership
semantics:

- `OWNERSHIP_MARKER = '/* Ultimate Performance advanced-cache.php v1 */'` is
  embedded as the second line of the generated drop-in.
- `is_owned_by_us()` reads the first 256 bytes of the file and checks
  for the marker.
- `is_foreign()` returns true if the file exists but does not contain
  the marker.
- `install()` returns false if `is_foreign()` is true — **never
  overwrites another plugin's drop-in**. This is a release-blocking
  safety contract.
- `remove()` only unlinks the file if `is_owned_by_us()` is true.
- The drop-in is installed on plugin activation (when
  `php_fallback_enabled = true`) only if no foreign drop-in exists; it
  is removed on deactivation only if we still own it.

The same pattern applies to the `object-cache.php` drop-in:
`Installer::deactivate()` reads the file contents and only removes it
if it contains `UltimateCache`.

## Foreign advanced-cache.php protection

When a foreign drop-in exists, `EnvironmentDetector::detect_mode()`
returns `MODE_CONFLICTED` and the admin UI displays a warning. UC will
not install its own drop-in, will not modify the existing one, and
will not serve from the PHP fallback path. The page cache still works
in Server Accelerated mode (if the web server is configured), but PHP
fallback is unavailable until the conflict is resolved.

## WooCommerce private routes

The `Classifier` bypasses WooCommerce's private routes via
`bypass_paths`:

- `cart`, `checkout`, `my-account`, `wc-api`, `wishlist`, `compare`,
  `order-pay`, `order-received`, `orders`, `view-order`, `edit-address`,
  `lost-password`, `customer-logout`.

WooCommerce cart / session cookies (`woocommerce_*`,
`wp_woocommerce_session_*`) match `cookie_bypass_regex` and force a
BYPASS — so a logged-in customer with an active cart never receives a
cached page.

`ResponseSanitizer` (BENCH-D4 / HARDEN-3) catches **rendered** Woo
artifacts that might appear on otherwise-cacheable pages:

- Rendered cart items: an `<li>` with `mini_cart_item` AND
  `data-product_id`.
- Rendered cart totals: `woocommerce-mini-cart__total` followed by
  `woocommerce-Price-amount`.
- Rendered cart counts: `cart-count` followed by
  `woocommerce-Price-amount`.
- Per-session nonces: `woocommerce-cart-nonce`, `data-cart-nonce`.
- Order confirmation / checkout form input:
  `woocommerce-order-overview`, `billing_email`.
- Customer-specific pricing: `customer-price`.

The previous broad-substring approach (e.g.
`woocommerce-mini-cart-item`) over-blocked every real Woo page because
the empty cart container is present on 100% of pages. The semantic
patterns now match only **rendered, session-bound** content.

`Hooks::purge_product()` synchronously purges the product's own
permalink, then enqueues the broader tag fanout (`post:<id>`,
`product:<id>`, `post_type:product`, `shop_archive`, `front_page`)
asynchronously.

## Credential policy

**Credentials are read from constants / environment only and are never
logged, persisted in plaintext, or exposed in diagnostics.**

- Redis: `UC_REDIS_HOST`, `UC_REDIS_PORT`, `UC_REDIS_AUTH`, `UC_REDIS_DB`,
  `UC_REDIS_TLS`, `UC_REDIS_SOCKET`.
- Memcached: `UC_MEMCACHED_HOST`, `UC_MEMCACHED_PORT`,
  `UC_MEMCACHED_SOCKET`.
- SQLite: `UC_SQLITE_FILE`.
- File: `UC_FILE_CACHE_DIR`.
- RabbitMQ: `UC_RABBITMQ_HOST`, `UC_RABBITMQ_PORT`, `UC_RABBITMQ_USER`,
  `UC_RABBITMQ_PASSWORD`, `UC_RABBITMQ_VHOST`.

Settings written via the admin screen are sanitized and stored in the
`ultimate_performance_settings` option; the `redis.auth` and `amqp.pass`
fields are stored but never echoed back to the admin form on save (the
form shows an empty password field; submitting it empty keeps the old
value).

When the RabbitMQ environment variables are absent, the RabbitMQ
regression suites self-gate into a clean BLOCKED / SKIP state — never
counted as PASS. This is a release-blocking honesty contract: never
fake a PASS.

The `tests/run-all-regression.sh` runner never sets RabbitMQ credentials
inline. They must come from the caller's environment.

Telemetry is bounded by construction: fixed schema, enum-only labels,
no URLs, no cache keys, no paths, no credentials. Rendered as JSON and
a Prometheus exposition.

## Audit checklist

The Phase E audit checklist is verified by the regression suites:

- [x] logged-in cookie ⇒ BYPASS before lookup AND before write.
- [x] nonce-bearing HTML refused at write time.
- [x] unknown query param ⇒ BYPASS.
- [x] Host not in allowlist ⇒ BYPASS.
- [x] `..` in path ⇒ BYPASS + segment reject.
- [x] denylist extensions (`.json` etc.) ⇒ BYPASS.
- [x] POST / AJAX / REST / XMLRPC / wp-admin / wp-cron / wp-login ⇒
      BYPASS.
- [x] cart / checkout / account cookies ⇒ BYPASS.
- [x] atomic rename; readers see complete docs only.
- [x] lock TTL prevents deadlock; owner token prevents cross-request
      steal.
- [x] symlink / junction outside root ⇒ quarantine on janitor run;
      writes verify containment.
- [x] tag purge touches only tagged objects.
- [x] multisite namespaces isolated.
- [x] foreign `advanced-cache.php` never overwritten.
- [x] foreign Redis / Memcached / APCu key survives every flush.

See [docs/SECURITY.md](SECURITY.md) for the full STRIDE threat model.

## Responsible disclosure

If you discover a security vulnerability in Ultimate Performance, please
report it responsibly. See the repository-level [SECURITY.md](../SECURITY.md)
for the disclosure policy and supported versions. Do not open a public
GitHub issue for security vulnerabilities — use the private disclosure
channel described in the policy.

## See also

- [docs/SECURITY.md](SECURITY.md) — full STRIDE threat model per subsystem.
- [docs/ARCHITECTURE.md](ARCHITECTURE.md) — defense layering, failure matrix.
- [Technical reference](technical.md) — request lifecycle and cache key generation.
- [Troubleshooting](troubleshooting.md) — security-related diagnostic steps.
