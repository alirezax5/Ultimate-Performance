# Configuration

English | [فارسی](configuration-fa.md)

This document is the complete reference for Ultimate Performance settings. The
defaults come from `UltimatePerformance\Core\Settings::defaults()` and are
merged with the stored option on every request. Settings are stored as a
single autoloaded option, `ultimate_performance_settings`.

Backends (Redis / Memcached / SQLite) can be configured **either** via
the admin screen **or** via constants / environment variables. The
constants take precedence and let you keep credentials out of the
database.

## Master toggles

| Setting | Type | Default | Description |
|---|---|---|---|
| `enabled` | bool | `true` | Master plugin switch. When `false`, the plugin is fully disabled. |
| `page_cache_enabled` | bool | `false` (opt-in) | Enables the page cache (write + serve). Off by default — page cache is opt-in. |
| `object_cache_enabled` | bool | `false` (opt-in) | Installs and activates the `object-cache.php` drop-in. Off by default — opt-in. |
| `invalidation_enabled` | bool | `true` | When `false`, hook-driven invalidation is suspended (debug / migration use). |
| `queue_enabled` | bool | `true` | Enables the durable purge queue. When `false`, purges run inline (sync mode). |
| `php_fallback_enabled` | bool | `true` | Installs the `advanced-cache.php` drop-in on activation, enabling PHP fallback mode on shared hosting. The drop-in is never overwritten if a foreign one exists. |

## Page cache tuning

| Setting | Type | Default | Range / Validation | Description |
|---|---|---|---|---|
| `ttl` | int | `3600` | 30 to `MONTH_IN_SECONDS` (2 592 000) | Time-to-live for cached pages, in seconds. |
| `swr_enabled` | bool | `true` | — | Stale-while-revalidate: serve stale content past TTL within `swr_grace`. |
| `swr_grace` | int | `300` | 0 to `DAY_IN_SECONDS` (86 400) | Grace window for SWR, in seconds. |
| `debug_headers` | bool | `false` | — | Emit `X-Ultimate-Performance-Reason` on BYPASS to aid debugging. Do not enable in production — leaks classifier internals. |

## Generation lock (stampede protection)

These were added in BENCH-D7 / HARDEN-1 to prevent thundering-herd
cache regeneration.

| Setting | Type | Default | Description |
|---|---|---|---|
| `herd_protection` | bool | `true` | Master toggle for single-flight generation. When `false`, every MISS renders independently (not recommended). |
| `genlock_ttl` | int | `30` | Lock TTL in seconds. `flock` auto-releases on PHP process death, but the TTL guards degraded mode. |
| `genlock_wait_budget_us` | int | `2 500 000` | Maximum total waiter wait in microseconds (2.5 s). Bounded; never infinite. Waiters poll every 20 ms ± 0–15 ms jitter. |

When `herd_protection = true` and the cache is cold:

1. One request acquires the generation lock (becomes the **generator**).
2. Concurrent requests (**waiters**) poll `Store::lookup()` with bounded
   wait + jitter. If a fresh cache appears, they serve it. If a stale
   cache appears and `swr_enabled = true`, they serve the stale body
   once via the `$on_stale` callback and exit.
3. If the wait budget is exhausted, the waiter falls through to render
   (last resort, but never a deadlock).

## Query string policy

| Setting | Type | Default | Description |
|---|---|---|---|
| `query_allowlist` | array | `['p', 'page_id', 'page', 'paged', 'feed', 'lang']` | Query params that may participate in the cache key (max 24 entries). Allowlisted queries are sorted, URL-encoded, and discriminated by a 16-char SHA1 prefix in the cache dir name. |
| `query_unknown_policy` | enum | `'bypass'` | One of `bypass`, `strip`, `variant`. `bypass` = any unknown param ⇒ not cached. `strip` = unknown params dropped, page cached under the canonical key. `variant` = each unknown param value gets its own cache dir. |
| `tracking_params_strip` | bool | `true` | Always strip tracking params (`utm_*`, `gclid`, `fbclid`, `msclkid`, `dclid`, `twclid`, `mc_eid`, `igshid`, `_ga`) from the cache key, regardless of the policy above. |

## Cookie and path bypasses

| Setting | Type | Default | Description |
|---|---|---|---|
| `cookie_bypass_regex` | string (PCRE) | `'wordpress_[a-f0-9]{32}\|wordpress_logged_in_[a-f0-9]{32}\|wordpress_sec_[a-f0-9]{32}\|wp-postpass\|comment_author\|woocommerce_\|wp_woocommerce_session_\|PHPSESSID\|wp-settings-[0-9]+'` | Any cookie whose name matches this regex forces a BYPASS. Validated at save time — must compile. |
| `bypass_paths` | array | `['cart', 'checkout', 'my-account', 'wc-api', 'wishlist', 'compare', 'order-pay', 'order-received', 'orders', 'view-order', 'edit-address', 'lost-password', 'customer-logout']` | Path slugs that force a BYPASS. Any path starting with one of these (case-insensitive) is never cached. Max 64 entries. |
| `deny_extensions` | array | `['php', 'json', 'xml', 'axd', 'aspx', 'jsp', 'cgi', 'phar', 'phtml', 'svg']` | File extensions that are never cached (cache deception defense). |

## Variants

| Setting | Type | Default | Description |
|---|---|---|---|---|
| `variants.webp` | bool | `false` | When `true` and the request `Accept:` header contains `image/webp`, the cache key includes `v=webp` and a separate directory is written. |
| `variants.mobile` | bool | `false` | When `true`, mobile user agents are cached in a separate directory. |

## Object cache

| Setting | Type | Default | Description |
|---|---|---|---|
| `object_cache_chain` | array | `['redis', 'memcached', 'apcu', 'sqlite', 'file']` | Ordered list of backends to try. The first one whose `configured()` returns `true` wins. **There is no fallback chain across backends at runtime** — a backend that fails mid-request is marked dead for the rest of the request and the selector picks the next on the next request. |

### Redis

| Setting | Type | Default | Description |
|---|---|---|---|
| `redis.host` | string | `'127.0.0.1'` | Redis TCP host. Override with `UC_REDIS_HOST` env / constant. |
| `redis.port` | int | `6379` | Redis TCP port. Override with `UC_REDIS_PORT`. |
| `redis.auth` | string | `''` | Redis AUTH password (optional). Override with `UC_REDIS_AUTH`. |
| `redis.db` | int | `0` | Redis database index. Override with `UC_REDIS_DB`. |
| `redis.tls` | bool | `false` | Connect via TLS. Override with `UC_REDIS_TLS`. |
| `redis.timeout` | float | `1.5` | Connect timeout in seconds. |

A UNIX socket (`UC_REDIS_SOCKET`) wins over TCP. No fallback between
socket and TCP.

### Memcached

| Setting | Type | Default | Description |
|---|---|---|---|
| `memcached.host` | string | `'127.0.0.1'` | Memcached TCP host. Override with `UC_MEMCACHED_HOST`. |
| `memcached.port` | int | `11211` | Memcached TCP port. Override with `UC_MEMCACHED_PORT`. |
| `memcached.timeout` | float | `1.5` | Connect timeout. |

A UNIX socket (`UC_MEMCACHED_SOCKET`) wins over TCP. No fallback.

### APCu

APCu is enabled automatically when `ext-apcu` is loaded and
`apcu_enabled()` returns `true`. No settings. APCu is **local per
server** (shared across FPM workers on one machine, per-process under
CLI); never advertised as distributed.

### SQLite

| Setting | Type | Default | Description |
|---|---|---|---|
| `sqlite.file` | string | `''` | Absolute path to the SQLite database file. Override with `UC_SQLITE_FILE`. When empty, the backend uses the default location under the cache root. |

SQLite uses WAL mode, `busy_timeout = 250 ms`, and `BEGIN IMMEDIATE`
transactional arithmetic for incr/decr (no lost updates under
multi-process contention).

### File

The file backend uses `UltimatePerformance\Core\SafeFs` atomic writes
(temp + rename) with hash-based filenames and symlink refusal. Override
the directory with `UC_FILE_CACHE_DIR`. When unset, the backend uses a
directory under the cache root.

## Queue backend

| Setting | Type | Default | Description |
|---|---|---|---|
| `queue_backend` | enum | `'auto'` | One of `auto`, `rabbitmq`, `action-scheduler`, `wp-cron`, `local`, `sync`. `auto` selects the first available backend in the order RabbitMQ → Action Scheduler → WP-Cron → Local → Sync. |

### RabbitMQ (AMQP)

| Setting | Type | Default | Description |
|---|---|---|---|
| `amqp.host` | string | `'127.0.0.1'` | RabbitMQ host. Override with `UC_RABBITMQ_HOST`. |
| `amqp.port` | int | `5672` | RabbitMQ port. Override with `UC_RABBITMQ_PORT`. |
| `amqp.user` | string | `'guest'` | RabbitMQ user. Override with `UC_RABBITMQ_USER`. |
| `amqp.pass` | string | `''` | RabbitMQ password. Override with `UC_RABBITMQ_PASSWORD`. Blank keeps the old value on save. |
| `amqp.vhost` | string | `'/'` | RabbitMQ vhost. Override with `UC_RABBITMQ_VHOST`. |
| `amqp.exchange` | string | `'ultimate-performance'` | Exchange name. |

**Credentials are read from constants / environment only and are never
logged, persisted in plaintext, or exposed in diagnostics.** When the
RabbitMQ variables are absent, the RabbitMQ suites self-gate into a
clean BLOCKED / SKIP state — never counted as PASS.

## Preload

| Setting | Type | Default | Range | Description |
|---|---|---|---|---|
| `preload.concurrency` | int | `2` | 1 to 16 | Concurrent fetch workers for warmup. |
| `preload.batch` | int | `50` | 1 to 1000 | URLs per warmup batch. |

Warmup plans come **only** from the plugin's own page-cache tree
(SSRF-safe by construction), reuse the one-and-only purge queue, and
respect an epoch guard so a settings change aborts stale plans.

## Web server integration

| Setting | Type | Default | Description |
|---|---|---|---|
| `nginx.origin` | string (IP:port) | `''` | The PHP origin address for the generated Nginx snippet (e.g. `127.0.0.1:8098`). Max 21 chars. |
| `nginx.listen` | string (IP:port) | `''` | The `listen` directive for the generated Nginx snippet (e.g. `127.0.0.1:8097`). Max 21 chars. |
| `apache_integration` | bool | `false` | Enable the Apache `.htaccess` rules writer. |
| `lscache_mode` | enum | `'auto'` | One of `auto`, `native`, `generic`. `auto` = detect LiteSpeed and use native LSCache when available; `native` = always emit LSCache headers; `generic` = use the generic file cache only. |

## Settings API (for developers)

```php
$settings = \UltimatePerformance\Core\Settings::instance();
$ttl = $settings->get( 'ttl', 3600 );               // single key
$redis_host = $settings->get( 'redis.host', '127.0.0.1' );  // dot path
$raw = $settings->raw();                              // full array (admin re-population)
```

`Settings::save_from_admin( $input )` validates and persists a full
settings array from admin input. Returns a `map<field, error>` (empty =
success). On success, fires the `ultimate_performance_settings_saved` action
with the new data.

`Settings::reset()` deletes the option and reloads defaults. Called by
`Installer::uninstall_data()`.

### Constants / environment variables

| Backend | Variables |
|---|---|
| Redis | `UC_REDIS_HOST`, `UC_REDIS_PORT`, `UC_REDIS_AUTH`, `UC_REDIS_DB`, `UC_REDIS_TLS`, `UC_REDIS_SOCKET` (socket wins over TCP) |
| Memcached | `UC_MEMCACHED_HOST`, `UC_MEMCACHED_PORT`, `UC_MEMCACHED_SOCKET` |
| SQLite | `UC_SQLITE_FILE` |
| File | `UC_FILE_CACHE_DIR` |
| RabbitMQ | `UC_RABBITMQ_HOST`, `UC_RABBITMQ_PORT`, `UC_RABBITMQ_USER`, `UC_RABBITMQ_PASSWORD`, `UC_RABBITMQ_VHOST` |

## See also

- [Installation](installation.md) — quick start and activation flow.
- [Hosting guide](hosting.md) — mode selection per environment.
- [Technical reference](technical.md) — how these settings are used at request time.
