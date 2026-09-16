# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 0.6.1   | ✅ Yes    |
| < 0.6.1 | ❌ No     |

## Reporting a Vulnerability

If you discover a security vulnerability in Ultimate Performance, please report it responsibly:

1. **Do NOT open a public GitHub issue** for security vulnerabilities.
2. Email: Report via GitHub's private vulnerability reporting feature at
   [https://github.com/alirezax5/ultimate-cache/security/advisories/new](https://github.com/alirezax5/ultimate-cache/security/advisories/new)
3. Include:
   - Description of the vulnerability
   - Steps to reproduce
   - Affected versions
   - Potential impact
   - Suggested fix (if any)

You will receive a response within 72 hours. If the vulnerability is confirmed,
a fix will be prioritized and a security advisory will be published.

## Security Architecture

Ultimate Performance implements multiple layers of defense:

### Cache Poisoning Protection

- **Host header validation**: the request classifier checks `HTTP_HOST` against
  the site's canonical `home_url`. A poisoned Host header cannot select another
  site's cache tree.
- **Query string policy**: unknown query parameters default to `bypass` (not cached).
  Only allowlisted parameters (`p`, `page_id`, `page`, `paged`, `feed`, `lang`) are
  cacheable.
- **Cookie bypass**: requests with WordPress authentication cookies, WooCommerce
  session/cart cookies, or PHP session IDs are never served from the public cache.
- **ResponseSanitizer**: a defense-in-depth HTML scanner audits every cached
  response before writing to disk and before serving. It detects:
  - WP nonces, REST nonces, CSRF tokens
  - Logged-in user markers (admin bar, `logged-in` class)
  - Personal greetings (`Howdy,`)
  - Rendered WooCommerce cart items and totals
  - Session identifiers in URLs

### Path Traversal Protection

- **SafeFs**: all filesystem operations go through a containment-checking layer
  that rejects `..` traversal, double-encoding, symlinks, NTFS ADS, UNC paths,
  and control characters.
- **Cache key sanitization**: URL path segments are validated against
  `^[a-z0-9][a-z0-9._\-]{0,63}$`. Unsafe segments are hashed deterministically.

### Atomic Writes

- Cache files are written via temp-file → `fsync` → atomic `rename`. Readers
  never see partially written HTML.
- Lock files use `flock` with inode re-validation to prevent dual-ownership races.

### Drop-in Ownership

- `advanced-cache.php` is installed with an `OWNERSHIP_MARKER` comment.
- The plugin never overwrites a foreign drop-in (e.g., W3TC, Cache Enabler).
- On deactivation, only the UC-owned drop-in is removed.

### Credential Policy

- No database credentials, passwords, or API tokens are stored in the source tree.
- The fallback settings reader uses parameterized queries (`mysqli::prepare`).
- `unserialize()` uses `allowed_classes => false` where compatible.
- DB connection failures fail open safely to normal WordPress execution.

### WooCommerce Safety

- Cart, checkout, my-account, and other private WooCommerce paths are bypassed
  by the request classifier.
- POST requests, AJAX cart mutations, and WooCommerce session cookies are never
  served from the public page cache.
- Product price/stock/variation mutations trigger cache invalidation through
  WooCommerce hooks (`woocommerce_update_product`).
