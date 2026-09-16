# Benchmarks

English | [فارسی](benchmarks-fa.md)

This document presents **only verified Ultimate Performance data** measured
on the development environment described below. Ultimate Performance does
**not** publish competitor rankings and does not claim any relative
position against other caching plugins. Numbers below are
environment-specific — your results will vary with hardware, page size,
stack configuration, and load profile.

## Environment

| Component | Version / model |
|---|---|
| Operating system | Ubuntu 26.04 |
| CPU | 4 vCPU AMD EPYC |
| RAM | 7.6 GB |
| Web server | Nginx 1.28.3 |
| PHP | 8.5.4-FPM (fpm-fcgi SAPI) |
| Database | MariaDB 11.8.6 |
| WordPress | 6.7.2 |
| Page size | ~84 KB (real WordPress page) |

## Methodology

- Throughput: `wrk` HTTP benchmarking tool against a real WordPress page
  (~84 KB body).
- Cold-cache c100 timeout rate: count of timed-out requests during a
  cold-cache (every request is a MISS) `wrk` run at c100.
- TTFB: median `time_starttransfer` from `curl -w`.
- Zero-PHP HIT invariant: origin-side execution counter
  (`PHP_EXECUTIONS` env sentinel) on the PHP-FPM pool; read after a
  warm-cache `wrk` run.
- Mode A (Server Accelerated): Nginx `try_files` against
  `wp-content/cache/ultimate-performance/v/<host>/<path>/index.html`, PHP-FPM
  as the dynamic origin only.
- Mode B (PHP Fallback): `advanced-cache.php` drop-in loaded before
  WordPress; `FallbackServer::serve()` runs and exits on a HIT.

## Results

### Server Accelerated mode (Mode A)

| Concurrency | Throughput (req/s) | Notes |
|---|---|---|
| c10 | 16 358 | `wrk -c10 -d30s` |
| c50 | 17 365 | `wrk -c50 -d30s` |
| c100 | 17 672 | `wrk -c100 -d30s` |

Throughput is essentially flat across c10/c50/c100 — the bottleneck is
Nginx static file serving, not PHP-FPM. There is no PHP execution on a
HIT.

### PHP Fallback mode (Mode B)

| Concurrency | Throughput (req/s) | Notes |
|---|---|---|
| c10 | ~3 000 | `wrk -c10 -d30s`, `advanced-cache.php` drop-in serving before WP boots |

PHP fallback is roughly 1/5 the throughput of server-accelerated mode
on the same page — every HIT still loads the PHP runtime and runs
`FallbackServer::serve()` before exiting.

### TTFB

| Mode | TTFB | Notes |
|---|---|---|
| Server HIT (Mode A) | 0.28 ms | Nginx serves the file directly; no PHP. |
| Fallback HIT (Mode B) | 3.5 ms | PHP runtime boots enough to call `FallbackServer::serve()`, reads the cache file, exits. |
| MISS (dynamic) | 40–400 ms | Full WordPress render with a real MariaDB query log. |

### Cold-cache c100 timeout rate

A cold-cache run (every request is a MISS) under c100:

| Total requests | Timeouts | Timeout rate |
|---|---|---|
| 516 111 | 28 | 0.0054 % |

Cold-cache c100 represents the worst case: every single request needs
a full WordPress render, and the GenerationLock ensures one generator +
bounded waiters per cache key. The 0.0054 % timeout rate is consistent
with PHP-FPM worker pool saturation under c100, not a cache defect.

### Zero-PHP HIT invariant

The Server Accelerated HIT path runs **zero** PHP-FPM dispatches.
Verified by:

1. Setting an origin-side execution counter on the PHP-FPM pool.
2. Running a warm-cache `wrk -c100 -d30s` (every request must be a HIT).
3. Reading the counter after the run: zero executions.

A MISS, by contrast, executes the WordPress render exactly once per
unique cache key (the GenerationLock ensures single-flight). Under c100
against a cold cache, the first 100 unique URLs trigger 100 executions;
all subsequent requests against the same URLs are HITs with zero PHP.

## Interpretation

- **Server Accelerated mode** is the recommended production mode. The
  ~17 000 req/s ceiling on this 4-vCPU EPYC VM is far above typical
  WordPress workloads; real-world benefit depends on the mix of cached
  vs. dynamic requests, page size, and the PHP-FPM pool size.
- **PHP Fallback mode** is appropriate for shared hosting where Nginx
  `try_files` configuration is not possible. The ~3 000 req/s ceiling
  is bounded by PHP-FPM dispatch per request, not by the cache
  subsystem.
- **TTFB** numbers explain why Server Accelerated feels dramatically
  faster in interactive use: 0.28 ms vs. 3.5 ms vs. 40–400 ms.
- The **cold-cache c100 timeout rate** of 0.0054 % reflects the
  GenerationLock's behavior under sustained load against a cold cache
  — one generator per key, bounded waiters, no thundering herd.

## Disclaimer

The numbers in this document are **environment-specific**. They were
measured on a single Ubuntu 26.04 VM with a real WordPress 6.7.2
installation, real Nginx / PHP-FPM / MariaDB daemons, and a ~84 KB
real WordPress page. Your numbers will vary with:

- Hardware (CPU model, vCPU count, RAM, disk I/O).
- Page size (a 200 KB page is ~2.5× slower to serve than a 80 KB page).
- Stack configuration (PHP-FPM pool size, Nginx worker count, MariaDB
  tuning, OPcache settings, JIT).
- Load profile (cached vs. dynamic mix, request distribution,
  keep-alive usage).
- Network (loopback vs. real network latency).

Ultimate Performance makes **no** claim about relative performance against
other caching plugins. The published data is for transparency and
reproducibility of the 0.6.1 release qualification only.

## Reproducing

The benchmark methodology is captured in the regression harness:

- `tests/audit-benchmark.php` — the in-process benchmark suite (12
  checks measuring 5 object-cache backends + the page-cache HIT path).
- The full `tests/run-all-regression.sh` runner — 48 suites, 1334
  checks in 0.6.1, must finish `0 FAIL` before a release tag.

Reproducing the live HTTP numbers requires the environment described
above. The in-process numbers (MemoryBackend 5M ops/s, APCu 1.7M ops/s,
SQLite 70K ops/s, File 253–494K ops/s, PageCache HIT 354K ops/s mean
0.003 ms) are reproducible on any host with PHP 8.3+ and the required
extensions.

## Production benchmark — woolena.ir (Ultimate Performance 0.6.9)

This is a real-world benchmark of a production WooCommerce store running
on woolena.ir. The site is configured with Ultimate Performance 0.6.9 in
two modes:

- **Mode A: PHP Compatibility** — PHP fallback drop-in
  (`advanced-cache.php`) serves cached pages before WordPress fully
  boots. Works on shared hosting without Nginx config changes.
- **Mode B: Hybrid (Best)** — Nginx direct-serves cached HTML from disk
  via `rewrite ... last` + `location ^~ /up-cache/` with `alias`. Zero
  PHP on cache HITs. PHP fallback remains active as backup for edge
  cases.

### Production environment

| Component | Version / model |
|---|---|
| Operating system | Debian 13 (server) |
| Web server | Nginx (aaPanel-managed) |
| PHP | 8.4.11-FPM (unix socket) |
| Database | MariaDB |
| WordPress | 6.7.x + WooCommerce |
| Page size | ~62 KB (homepage), ~80 KB (shop), ~66 KB (FAQ) |
| CDN | ArvanCloud (bypassed for benchmark — direct server-side curl) |

### Methodology

- 10 sequential server-side `curl` requests per page (bypasses CDN for
  accurate origin measurement).
- TTFB measured via `curl -w '%{time_starttransfer}'`.
- Cache warmed with 5 hits per page before measurement.
- Mode A: nginx extension file temporarily emptied (cache served by PHP
  fallback drop-in only).
- Mode B: nginx extension file installed with rewrite-based approach +
  `internal;` location + security headers; verify probe confirmed active.

### Results — TTFB (ms, lower is better)

| Page | Mode A (PHP Compatibility) | Mode B (Hybrid Best) | Speedup |
|---|---|---|---|
| Homepage `/` | 32.94 ms | **14.43 ms** | **2.28×** |
| Shop `/shop/` | 26.47 ms | **13.71 ms** | **1.93×** |
| FAQ `/faq/` | 22.86 ms | **14.73 ms** | **1.55×** |

### Results — Throughput (req/s, higher is better)

| Page | Mode A (PHP Compatibility) | Mode B (Hybrid Best) | Speedup |
|---|---|---|---|
| Homepage `/` | 30.4 req/s | **69.3 req/s** | **2.28×** |
| Shop `/shop/` | 37.8 req/s | **72.9 req/s** | **1.93×** |
| FAQ `/faq/` | 43.7 req/s | **67.9 req/s** | **1.55×** |

### Key findings

1. **Hybrid mode is ~2× faster** than PHP Compatibility mode. The
   speedup comes from nginx serving cached HTML directly from disk
   (zero PHP), instead of running the `advanced-cache.php` drop-in.
2. **TTFB drops from ~30 ms to ~14 ms** on cache HITs — well below the
   100 ms "fast" threshold.
3. **Throughput doubles** from ~30–44 req/s to ~68–73 req/s per page.
4. **No regressions**: all uncacheable pages (cart, checkout, wp-admin,
   logged-in users, query strings) correctly bypass cache.
5. **Security headers preserved**: HSTS, X-Frame-Options,
   X-Content-Type-Options, Referrer-Policy, X-XSS-Protection all
   present on HIT responses (set via `add_header` in the
   `/up-cache/` location block).

### Security audit (18 tests, all passed)

| # | Test | Result |
|---|---|---|
| 1 | Direct `/up-cache/` access denied (404) | PASS |
| 2 | Cache `.meta.json` files denied (404) | PASS |
| 3 | Path traversal blocked (404) | PASS |
| 4 | wp-admin redirects to login (302) | PASS |
| 5 | X-Forwarded-Host does NOT poison cache | PASS |
| 6 | `/my-account/` bypasses cache for anonymous | PASS |
| 7 | Logged-in users bypass cache (no leak) | PASS |
| 8 | `.htaccess` in cache dir denied (404) | PASS |
| 9 | `/up-cache/<host>/<path>` direct access denied | PASS |
| 10 | Cache directory listing denied (404) | PASS |
| 11 | Cross-host `/up-cache/` access denied | PASS |
| 12 | Query string bypasses cache | PASS |
| 13 | Probe tokens are random (16 hex chars) | PASS |
| 14 | Cache content identical regardless of irrelevant cookies | PASS |
| 15 | Cache key includes Host header by design | PASS |
| 16 | HSTS header present on HIT responses | PASS |
| 17 | Security response headers present (X-Frame-Options, etc.) | PASS |
| 18 | Cache-Control max-age=3600 enforced | PASS |

### Reproducing this benchmark

To reproduce on your own site:

1. Install Ultimate Performance 0.6.9+.
2. Configure Server Integration (Settings → Ultimate Performance →
   Server Integration) with your Nginx origin/listen.
3. Install the nginx extension file (see `examples/nginx.sample.conf`
   for a template).
4. Run the verify probe (admin → Server Integration → "Verify Nginx
   rules"). Mode should flip to "Hybrid (Best)".
5. Run the benchmark from your server:
   ```bash
   for i in {1..10}; do
       curl -sk -o /dev/null -w '%{time_starttransfer}\n' \
           -H 'Host: yoursite.com' https://127.0.0.1/
   done
   ```
6. Compare to Mode A by temporarily emptying the nginx extension file
   and re-running.

## See also

- [Technical reference](technical.md) — request lifecycle and why HITs are zero-PHP.
- [Hosting guide](hosting.md) — how to choose between Server Accelerated and PHP Fallback.
- [Installation](installation.md) — how to verify your own HITs.
