# Ultimate Performance

**Production-grade WordPress caching with zero-PHP page-cache HITs and a promotion-fenced object cache.**

`Status: 0.6.9 — final release · Persian (fa_IR) localization · Hybrid (Best) mode verified · 18/18 security tests PASS · 2.28× speedup vs PHP Compatibility`

English | [فارسی](#فارسی)

---

## English

### What is Ultimate Performance?

Ultimate Performance is a WordPress caching platform that combines a **page cache**
that answers public, anonymous requests without touching PHP — served directly
by the web server (Nginx `try_files`, Apache `.htaccess`, LiteSpeed) — with a
**PHP fallback** mode that works on shared hosting without any server
configuration, and a **promotion-fenced object cache** that supports Redis,
Memcached, APCu, SQLite and the filesystem behind one WordPress API.

It is built around a single hard rule:

> A safe public Page Cache HIT is `HTTP Request → Web Server → Static HTML →
> Browser` with **0** WordPress bootstrap, **0** PHP execution, **0** MySQL
> query, **0** Redis/Memcached/RabbitMQ request.

Priority order: **SECURITY > CORRECTNESS > RELIABILITY > COMPATIBILITY >
PERFORMANCE > CONVENIENCE.**

### Key features (0.6.9)

- **Zero-PHP page cache HIT** via Nginx direct-serve (Hybrid / Server Accelerated mode)
- **PHP fallback mode** via the `advanced-cache.php` drop-in — works on shared hosting with no root access; emits `X-Ultimate-Performance: FALLBACK-HIT` when serving from cache before WordPress finishes booting
- **Hybrid (Best) mode** — when both Nginx acceleration is verified AND the PHP fallback drop-in is active, the plugin reports "Hybrid (Best)" mode. Best of both worlds: zero-PHP HITs on cached pages, with PHP fallback as backup for edge cases
- **6 serving modes** with clear status indicators: Hybrid (Best), Server Accelerated, PHP Compatibility, Misconfigured, Disabled, Conflicted
- **Security headers on HIT responses** — HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, X-XSS-Protection
- **Object cache** with promotion-fenced failover: Redis → Memcached → APCu → SQLite → File
- **RabbitMQ queue** with fallback chain (RabbitMQ → Action Scheduler → WP-Cron → sync)
- **WooCommerce-safe** request classification (cart, checkout, account, session cookies all bypass cache)
- **Persian (fa_IR) localization** — 125+ strings translated
- **Complete uninstall** — deactivation wipes cache directory, drop-ins, transients, cron hooks; uninstall also drops DB tables and removes admin capabilities
- **Brand consistency** — all UC/UltimateCache references removed; consistently uses UP/Ultimate Performance

### Installation

1. Upload `ultimate-performance-0.6.9.zip` via WordPress admin → Plugins → Add New → Upload Plugin
2. Activate the plugin
3. Configure page cache TTL, object cache, and queue backends in **Settings → Ultimate Performance**
4. For best performance, configure Server Integration (Nginx) — see `examples/nginx.sample.conf` for a template

### Serving modes

| Mode | Label | Color | Description |
|------|-------|-------|-------------|
| `HYBRID` | Hybrid (Best) | green | Web server direct-serves cache + PHP fallback active as backup |
| `SERVER_ACCELERATED` | Server Accelerated | green | Web server direct-serves cache (no PHP fallback) |
| `PHP_FALLBACK` | PHP Compatibility | orange | PHP drop-in serves cache before WP bootstraps |
| `MISCONFIGURED` | Misconfigured | red | No cache serving path active |
| `DISABLED` | Disabled | gray | Plugin or page cache disabled |
| `CONFLICTED` | Conflicted | red | Foreign drop-in detected |

### Performance benchmarks (woolena.ir production)

| Page | PHP Compatibility | Hybrid (Best) | Speedup |
|------|------------------|---------------|---------|
| Homepage `/` | 32.94 ms | **14.43 ms** | **2.28×** |
| Shop `/shop/` | 26.47 ms | **13.71 ms** | **1.93×** |
| FAQ `/faq/` | 22.86 ms | **14.73 ms** | **1.55×** |

Throughput:

| Page | PHP Compatibility | Hybrid (Best) | Speedup |
|------|------------------|---------------|---------|
| Homepage `/` | 30.4 req/s | **69.3 req/s** | **2.28×** |
| Shop `/shop/` | 37.8 req/s | **72.9 req/s** | **1.93×** |
| FAQ `/faq/` | 43.7 req/s | **67.9 req/s** | **1.55×** |

### Security audit (18/18 PASS)

| # | Test | Result |
|---|------|--------|
| 1 | Direct `/up-cache/` access denied (404) | ✅ PASS |
| 2 | Cache `.meta.json` files denied (404) | ✅ PASS |
| 3 | Path traversal blocked (404) | ✅ PASS |
| 4 | wp-admin redirects to login (302) | ✅ PASS |
| 5 | X-Forwarded-Host does NOT poison cache | ✅ PASS |
| 6 | `/my-account/` bypasses cache for anonymous | ✅ PASS |
| 7 | Logged-in users bypass cache (no leak) | ✅ PASS |
| 8 | `.htaccess` in cache dir denied (404) | ✅ PASS |
| 9 | `/up-cache/<host>/<path>` direct access denied | ✅ PASS |
| 10 | Cache directory listing denied (404) | ✅ PASS |
| 11 | Cross-host `/up-cache/` access denied | ✅ PASS |
| 12 | Query string bypasses cache | ✅ PASS |
| 13 | Probe tokens are random (16 hex chars) | ✅ PASS |
| 14 | Cache content identical regardless of irrelevant cookies | ✅ PASS |
| 15 | Cache key includes Host header by design | ✅ PASS |
| 16 | HSTS header present on HIT responses | ✅ PASS |
| 17 | Security response headers present | ✅ PASS |
| 18 | Cache-Control max-age=3600 enforced | ✅ PASS |

### HTTP response headers

The plugin emits the following headers (note: `X-Ultimate-Performance`, not `X-Ultimate-Cache`):

| Header | Value | When |
|--------|-------|------|
| `X-Ultimate-Performance` | `HIT` | Web server served cached HTML directly (zero PHP) |
| `X-Ultimate-Performance` | `FALLBACK-HIT` | PHP fallback drop-in served cached HTML |
| `X-Ultimate-Performance` | `MISS` | Cache miss — WordPress generated the page |
| `X-Ultimate-Performance` | `BYPASS` | Request bypassed cache (logged-in, cart, POST, etc.) |
| `X-Ultimate-Performance-Woo` | `public` / `private` / `session` | WooCommerce cache classification |
| `X-Ultimate-Performance-Reason` | (debug) | Why the request was bypassed (when `debug_headers` is on) |

### Documentation

- [Installation guide](docs/installation.md) — Nginx server acceleration setup, verify probe
- [Benchmarks](docs/benchmarks.md) — production benchmarks + security audit
- [Configuration](docs/configuration.md) — all settings explained
- [Hosting guide](docs/hosting.md) — shared hosting vs VPS vs dedicated
- [Technical reference](docs/technical.md) — request lifecycle, why HITs are zero-PHP
- [Troubleshooting](docs/troubleshooting.md) — common issues and fixes
- [Security](docs/SECURITY.md) — security model and threat surface

### Changelog (recent)

**0.6.9** — Critical security fixes (cache poisoning, metadata disclosure, directory listing) + production benchmarks + security audit (18/18 PASS)

**0.6.8** — Added Hybrid (Best) serving mode + mode legend + performance comparison widget

**0.6.7** — Persian (fa_IR) localization + `load_plugin_textdomain()` + sample nginx config

**0.6.6** — Full UC→UP rebrand (HTML IDs, JS variables, query args, POST fields, transients, DB tables, cron, namespaces)

**0.6.5** — Deactivation wipes cache directory + drop-ins + transients + cron hooks

**0.6.4** — Object Cache tab save-flow visibility (preserved/set/cleared notices + JS confirmation before clearing passwords)

See [CHANGELOG.md](CHANGELOG.md) for the complete history.

---

## فارسی

<div dir="rtl" align="right">

### Ultimate Performance چیست؟

Ultimate Performance یک پلتفرم کشینگ وردپرس است که یک **کش صفحه** (که به درخواست‌های عمومی و ناشناس بدون اجرای PHP پاسخ می‌دهد — مستقیماً توسط وب‌سرور) را با حالت **PHP fallback** (که روی هاست اشتراکی بدون پیکربندی سرور کار می‌کند) و یک **کش آبجکت با failover محافظت‌شده** (که از Redis، Memcached، APCu، SQLite و فایل پشتیبانی می‌کند) ترکیب می‌کند.

قاعده‌ی سختِ اصلی:

> یک HIT امنِ کشِ صفحه‌ی عمومی به این شکل است: `HTTP Request → Web Server → Static HTML → Browser` با **۰** بوت‌استرپ وردپرس، **۰** اجرای PHP، **۰** کوئری MySQL، **۰** درخواست Redis/Memcached/RabbitMQ.

اولویت: **امنیت > صحت > قابلیت اطمینان > سازگاری > عملکرد > راحتی.**

### ویژگی‌های کلیدی (0.6.9)

- **HIT کش صفحه با بدون PHP** از طریق سرو مستقیم Nginx (حالت Hybrid / Server Accelerated)
- **حالت PHP fallback** از طریق drop-in `advanced-cache.php` — روی هاست اشتراکی بدون دسترسی root کار می‌کند؛ هدر `X-Ultimate-Performance: FALLBACK-HIT` را هنگام سرو از کش قبل از بوت کامل وردپرس صادر می‌کند
- **حالت Hybrid (Best)** — وقتی هم شتاب‌دهی Nginx تأیید شود و هم drop-in PHP fallback فعال باشد، پلاگین حالت «Hybrid (Best)» را گزارش می‌دهد. بهترین حالت: HITهای بدون PHP در صفحات کش‌شده، با PHP fallback به‌عنوان پشتیبان
- **۶ حالت سرو** با نشانگرهای وضعیت روشن: Hybrid (Best)، Server Accelerated، PHP Compatibility، Misconfigured، Disabled، Conflicted
- **هدرهای امنیتی در پاسخ‌های HIT** — HSTS، X-Frame-Options، X-Content-Type-Options، Referrer-Policy، X-XSS-Protection
- **کش آبجکت** با failover محافظت‌شده: Redis → Memcached → APCu → SQLite → File
- **صف RabbitMQ** با زنجیره fallback (RabbitMQ → Action Scheduler → WP-Cron → sync)
- **سازگار با WooCommerce** — طبقه‌بندی درخواست‌ها (cart، checkout، account، session cookies همگی از کش عبور می‌کنند)
- **بومی‌سازی فارسی (fa_IR)** — ۱۲۵+ رشته ترجمه شده
- **حذف کامل هنگام پاک‌سازی** — غیرفعال‌سازی پوشه کش، drop-in ها، transients و cron hooks را پاک می‌کند؛ حذف کامل جداول دیتابیس و قابلیت‌های ادمین را هم پاک می‌کند
- **یکپارچگی برند** — تمام ارجاعات UC/UltimateCache حذف شدند؛ به‌طور یکنواخت از UP/Ultimate Performance استفاده می‌شود

### نصب

1. فایل `ultimate-performance-0.6.9.zip` را از طریق پیشخوان وردپرس → افزونه‌ها → افزودن → بارگذاری افزونه آپلود کنید
2. افزونه را فعال کنید
3. TTL کش صفحه، کش آبجکت و backends صف را در **تنظیمات → Ultimate Performance** پیکربندی کنید
4. برای بهترین عملکرد، Server Integration (Nginx) را پیکربندی کنید — به `examples/nginx.sample.conf` برای الگو مراجعه کنید

### حالت‌های سرو

| حالت | برچسب | رنگ | توضیح |
|------|--------|------|--------|
| `HYBRID` | Hybrid (Best) | سبز | وب‌سرور مستقیماً کش را سرو می‌کند + PHP fallback فعال به‌عنوان پشتیبان |
| `SERVER_ACCELERATED` | Server Accelerated | سبز | وب‌سرور مستقیماً کش را سرو می‌کند (بدون PHP fallback) |
| `PHP_FALLBACK` | PHP Compatibility | نارنجی | drop-in PHP قبل از بوت وردپرس کش را سرو می‌کند |
| `MISCONFIGURED` | Misconfigured | قرمز | هیچ مسیر سرو کشی فعال نیست |
| `DISABLED` | Disabled | خاکستری | پلاگین یا کش صفحه غیرفعال است |
| `CONFLICTED` | Conflicted | قرمز | drop-in خارجی شناسایی شد |

### بنجمارک عملکرد (woolena.ir production)

| صفحه | PHP Compatibility | Hybrid (Best) | سرعت‌بخشی |
|------|-------------------|---------------|-----------|
| Homepage `/` | ۳۲.۹۴ ms | **۱۴.۴۳ ms** | **۲.۲۸×** |
| Shop `/shop/` | ۲۶.۴۷ ms | **۱۳.۷۱ ms** | **۱.۹۳×** |
| FAQ `/faq/` | ۲۲.۸۶ ms | **۱۴.۷۳ ms** | **۱.۵۵×** |

Throughput:

| صفحه | PHP Compatibility | Hybrid (Best) | سرعت‌بخشی |
|------|-------------------|---------------|-----------|
| Homepage `/` | ۳۰.۴ req/s | **۶۹.۳ req/s** | **۲.۲۸×** |
| Shop `/shop/` | ۳۷.۸ req/s | **۷۲.۹ req/s** | **۱.۹۳×** |
| FAQ `/faq/` | ۴۳.۷ req/s | **۶۷.۹ req/s** | **۱.۵۵×** |

### تست امنیتی (۱۸/۱۸ PASS)

| # | تست | نتیجه |
|---|------|--------|
| ۱ | دسترسی مستقیم `/up-cache/` رد شد (404) | ✅ PASS |
| ۲ | فایل‌های `.meta.json` کش رد شد (404) | ✅ PASS |
| ۳ | Path traversal مسدود شد (404) | ✅ PASS |
| ۴ | wp-admin به login redirect می‌شود (302) | ✅ PASS |
| ۵ | X-Forwarded-Host کش را مسموم نمی‌کند | ✅ PASS |
| ۶ | `/my-account/` برای کاربر ناشناس از کش عبور می‌کند | ✅ PASS |
| ۷ | کاربران واردشده از کش عبور می‌کنند (بدون نشت) | ✅ PASS |
| ۸ | `.htaccess` در cache dir رد شد (404) | ✅ PASS |
| ۹ | دسترسی مستقیم `/up-cache/<host>/<path>` رد شد | ✅ PASS |
| ۱۰ | لیست دایرکتوری کش رد شد (404) | ✅ PASS |
| ۱۱ | دسترسی cross-host `/up-cache/` رد شد | ✅ PASS |
| ۱۲ | Query string از کش عبور می‌کند | ✅ PASS |
| ۱۳ | توکن‌های probe تصادفی هستند (۱۶ hex) | ✅ PASS |
| ۱۴ | محتوای کش بدون توجه به cookies نامرتبط یکسان است | ✅ PASS |
| ۱۵ | کلید کش شامل Host header است (طراحی) | ✅ PASS |
| ۱۶ | هدر HSTS در پاسخ‌های HIT موجود است | ✅ PASS |
| ۱۷ | هدرهای امنیتی موجود هستند | ✅ PASS |
| ۱۸ | Cache-Control max-age=3600 اجرا می‌شود | ✅ PASS |

### هدرهای HTTP

پلاگین هدرهای زیر را صادر می‌کند (توجه: `X-Ultimate-Performance`، نه `X-Ultimate-Cache`):

| هدر | مقدار | هنگام |
|------|-------|-------|
| `X-Ultimate-Performance` | `HIT` | وب‌سرور مستقیماً HTML کش‌شده را سرو کرد (بدون PHP) |
| `X-Ultimate-Performance` | `FALLBACK-HIT` | drop-in PHP fallback HTML کش‌شده را سرو کرد |
| `X-Ultimate-Performance` | `MISS` | cache miss — وردپرس صفحه را تولید کرد |
| `X-Ultimate-Performance` | `BYPASS` | درخواست از کش عبور کرد (logged-in، cart، POST و غیره) |
| `X-Ultimate-Performance-Woo` | `public` / `private` / `session` | طبقه‌بندی کش WooCommerce |
| `X-Ultimate-Performance-Reason` | (debug) | چرا درخواست bypass شد (وقتی `debug_headers` روشن است) |

### مستندات

- [راهنمای نصب](docs/installation-fa.md) — پیکربندی شتاب‌دهی Nginx، verify probe
- [بنجمارک‌ها](docs/benchmarks-fa.md) — بنجمارک‌های production + تست امنیتی
- [پیکربندی](docs/configuration-fa.md) — تمام تنظیمات توضیح داده شده
- [راهنمای هاستینگ](docs/hosting-fa.md) — هاست اشتراکی vs VPS vs اختصاصی
- [مرجع فنی](docs/technical-fa.md) — چرخه‌ی درخواست، چرا HITها بدون PHP هستند
- [عیب‌یابی](docs/troubleshooting-fa.md) — مشکلات رایج و راه‌حل‌ها
- [امنیت](docs/security-fa.md) — مدل امنیتی و سطح تهدید

### تغییرات اخیر

**0.6.9** — رفع‌های امنیتی حیاتی (cache poisoning، نشت metadata، directory listing) + بنجمارک‌های production + تست امنیتی (۱۸/۱۸ PASS)

**0.6.8** — اضافه‌شدن حالت Hybrid (Best) + legend حالت + ویجت مقایسه‌ی عملکرد

**0.6.7** — بومی‌سازی فارسی (fa_IR) + `load_plugin_textdomain()` + نمونه کانفیگ nginx

**0.6.6** — rebrand کامل UC→UP (HTML IDs، متغیرهای JS، query args، فیلدهای POST، transients، جداول DB، cron، namespaces)

**0.6.5** — غیرفعال‌سازی پوشه کش + drop-ins + transients + cron hooks را پاک می‌کند

**0.6.4** — دیداری‌سازی جریان ذخیره‌سازی تب Object Cache (اطلاع‌رسانی preserved/set/cleared + تأیید JS قبل از پاک‌کردن رمزها)

برای تاریخچه‌ی کامل به [CHANGELOG.md](CHANGELOG.md) مراجعه کنید.

</div>

---

## License

GPL-2.0-or-later

## Author

alirezax5 — [GitHub](https://github.com/alirezax5/Ultimate-Performance)
