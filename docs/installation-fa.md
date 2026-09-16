# نصب

[English](installation.md) | فارسی

این راهنما به نصب Ultimate Performance، شناسایی حالت فعالِ پاسخ‌دهی، پیکربندی شتاب‌دهی توسط سرور روی Nginx، تأیید HITهای کش، پاک‌سازی کش و حذف افزونه می‌پردازد.

## پیش‌نیازها

| مؤلفه | حداقل | پیشنهادی |
|---|---|---|
| وردپرس | 6.0 | 6.7 به بالا |
| PHP | 8.3 | 8.3 به بالا (تا 8.5.4 آزمایش شده) |
| وب‌سرور (شتاب‌دهی توسط سرور) | Nginx 1.18 به بالا | Nginx 1.28 به بالا |
| وب‌سرور (جایگزین PHP) | هر سرور اجراکنندهٔ وردپرس | — |
| پشتیبان کش شیء (اختیاری) | Redis 5 به بالا، Memcached 1.5 به بالا، APCu 5 به بالا، SQLite 3 (PDO) یا سیستم فایل قابل‌نوشتن | Redis |
| مالتی‌سایت | پشتیبانی می‌شود (زیرشاخه + زیردامنه) | — |

افزونه‌های PHP موردنیاز خودِ افزونه: `pdo` (برای پشتیبان کش شیء SQLite، در صورت استفاده)، `redis` یا `memcached` (تنها در صورت استفاده از آن پشتیبان کش شیء). کش صفحه هیچ پیش‌نیاز افزونهٔ PHP به‌جز خود وردپرس ندارد.

## نصب ZIP

1. آخرین نسخهٔ ZIP را از صفحهٔ [Releases](https://github.com/alirezax5/Ultimate-Performance/releases) دریافت کنید.
2. در `wp-admin ← افزونه‌ها ← افزودن جدید ← بارگذاری افزونه`، ZIP را انتخاب و **اکنون نصب کن** را بزنید.
3. روی **فعال‌سازی** کلیک کنید.

یا با WP-CLI:

```bash
wp plugin install ultimate-performance.zip --activate
```

## فعال‌سازی افزونه

هنگام فعال‌سازی، `UltimatePerformance\Core\Installer::activate()` اجرا می‌شود که:

1. آرایهٔ پیش‌فرض تنظیمات را می‌نویسد (fail-closed؛ همهٔ موارد حساس به‌جز `php_fallback_enabled = true` خاموش هستند).
2. توانایی `ultimate_performance_purge_all` را به نقش `administrator` می‌دهد.
3. درخت ریشهٔ کش را در `wp-content/cache/ultimate-performance/{v,meta,tmp}/` می‌سازد.
4. یک `.htaccess` سخت‌شده در ریشهٔ کش می‌نویسد که هر نام فایلی به‌جز `index.html` را رد می‌کند و `.php`، `.json`، `.lock`، `.tmp`، فایل‌های نقطه‌دار و فهرستی از پسوندهای خطرناک را مسدود می‌کند.
5. **درآپ‌این `advanced-cache.php` را** در `wp-content/advanced-cache.php` نصب می‌کند، در صورتی که `php_fallback_enabled` برابر `true` (پیش‌فرض) باشد و هیچ درآپ‌این بیگانه‌ای وجود نداشته باشد. درآپ‌این توسط `AdvancedCacheDropin::generate_code()` تولید می‌شود و با `<?php` آغاز شده و پس از آن کامنت `OWNERSHIP_MARKER` می‌آید.
6. یک تصویر از محیط (زمان + وضعیت درآپ‌این) را در ترانزینت `uc_env_snapshot` ذخیره می‌کند.

## شناسایی حالت فعال

به `تنظیمات ← Ultimate Performance` بروید. صفحهٔ پیشخوان `EnvironmentDetector::get_status_summary()` را فراخوانی می‌کند و یکی از موارد زیر را نمایش می‌دهد:

| برچسب حالت | ثابت حالت | معنا |
|---|---|---|
| شتاب‌دهی توسط سرور | `SERVER_ACCELERATED` | کش صفحه پیکربندی شده؛ انتظار می‌رود وب‌سرور بدنه‌های کش‌شده را مستقیماً ارائه دهد. برای اطمینان، پروب خودآزمایی را اجرا کنید. |
| سازگار با PHP | `PHP_FALLBACK` | درآپ‌این `advanced-cache.php` متعلق به UC وجود دارد و `WP_CACHE` برابر `true` است. HITهای جایگزین PHP توسط `FallbackServer::serve()` ارائه می‌شوند. |
| نادرست پیکربندی‌شده | `MISCONFIGURED` | ریشهٔ کش قابل‌نوشتن نیست، یا سرور شناسایی‌شده در فهرست پشتیبانی (Nginx / Apache / LiteSpeed / OLS) نیست. |
| غیرفعال | `DISABLED` | افزونه یا کش صفحه در تنظیمات غیرفعال است. |
| در تعارض | `CONFLICTED` | یک درآپ‌این `advanced-cache.php` بیگانه وجود دارد. UC آن را بازنویسی نمی‌کند. |

صفحه همچنین سرور شناسایی‌شده (`nginx`، `apache`، `litespeed`، `ols`، `iis`، `unknown`) و دکمهٔ **اجرای خودآزمایی** را نمایش می‌دهد. خودآزمایی یک پروب کش می‌نویسد، آن را می‌خواند و سپس پاک می‌کند؛ قبولیِ خودآزمایی نشان می‌دهد ریشهٔ کش قابل‌نوشتن و قابل‌خواندن است.

## راه‌اندازی جایگزین PHP (هاست اشتراکی، بدون ریشه)

اگر `php_fallback_enabled = true` (پیش‌فرض) باشد و درآپ‌این `advanced-cache.php` بیگانه‌ای وجود نداشته باشد، درآپ‌این هنگام فعال‌سازی افزونه نصب شده است. برای فعال‌سازی آن:

1. `wp-config.php` را باز کنید و مطمئن شوید `define('WP_CACHE', true);` وجود دارد. وردپرس تنها هنگامی `advanced-cache.php` را بارگذاری می‌کند که این ثابت `true` باشد.
2. `wp-config.php` را ذخیره کنید.
3. مالکیت درآپ‌این را تأیید کنید: `wp-content/advanced-cache.php` را باز کنید؛ 256 بایت اول باید شامل `/* Ultimate Performance advanced-cache.php v1 */` باشد.
4. تأیید کنید `EnvironmentDetector` حالت `PHP_FALLBACK` را گزارش می‌دهد.

اگر درآپ‌این بیگانه‌ای وجود داشته باشد (از افزونهٔ کش دیگری)، UC حالت `CONFLICTED` را گزارش می‌دهد و درآپ‌این خود را نصب نمی‌کند. ابتدا درآپ‌این افزونهٔ دیگر را غیرفعال یا حذف کنید، سپس UC را غیرفعال و دوباره فعال کنید تا درآپ‌این ما نصب شود.

## راه‌اندازی شتاب‌دهی توسط سرور روی Nginx

1. در `تنظیمات ← Ultimate Performance`، ورودی‌های **یکپارچگی Nginx** را پر کنید:
   - **Origin** — IP:port مبدا PHP (مثلاً `127.0.0.1:8098`).
   - **Listen** — IP:port برای شنود Nginx (مثلاً `127.0.0.1:8097`).
   تنظیمات را ذخیره کنید. افزونه هر دو را به‌عنوان تحت‌اللفظی `IP:port` اعتبارسنجی می‌کند (حداکثر 21 نویسه، fail-closed بر هر چیز دیگر).
2. قطعهٔ تولید‌شده (در صفحهٔ پیشخوان قابل‌مشاهده) را کپی کنید. این قطعه توسط `UltimatePerformance\WebServer\Nginx\Rules::generate()` تولید می‌شود و شامل یک بلاک کامل `http{}` است: نقشه‌های مرکب (`$up_method_ok`، `$up_cookieless`، `$up_static`)، بلاک `server { listen; server_name; root; ... }`، مسیر `^~ /up-cache/` فقط-داخلی با `alias` به ریشهٔ کش، بازگشت `@up_dynamic` که به مبدا PHP پروکسی می‌کند، و یک نگهبان عدم‌ارائهٔ `.php` از دیسک.
3. روی میزبان Nginx خود، **یک خط** درون بلاک `http {}` در `nginx.conf` اضافه کنید:

   ```nginx
   include /absolute/path/to/ultimate-performance-nginx.conf;
   ```

   از مسیری که قطعه را در آن ذخیره کرده‌اید استفاده کنید.
4. Nginx را بازبارگذاری کنید: `sudo nginx -t && sudo nginx -s reload`.
5. در صفحهٔ پیشخوان، روی **اجرای خودآزمایی / پروب تأیید** کلیک کنید. پروب تأیید یک فایل در URI پروب (`/uc-verify-<token>/`) با بدنهٔ بایت‌به‌بایت می‌نویسد، سپس یک درخواست HTTP می‌فرستد. اگر بدنهٔ پاسخ دقیقاً با `probe_body($token)` مطابقت داشته باشد، قطعه به‌صورت ایستا پاسخ می‌دهد — حالت شتاب‌دهی توسط سرور فعال است. افزونه بدون این اثبات، هرگز وضعیت فعال بودن را ادعا نمی‌کند.

### قوانین `try_files` دست‌نویس (پیشرفته)

اگر ترجیح می‌دهید از بلاک کامل `http{}` تولیدشده استفاده نکنید، حداقل قطعهٔ Nginx این است:

```nginx
location / {
    try_files $uri $uri/ /up-cache/$host$uri/index.html /index.php?$args;
}

location ^~ /up-cache/ {
    internal;
    alias /var/www/wp-content/cache/ultimate-performance/v/;
    try_files $uri @dynamic;
}
```

این **تنها به این دلیل** کار می‌کند که افزونه برای صفحهٔ اصلی از `ROOT_SENTINEL = "uc-root"` استفاده می‌کند — بنابراین `/` به `v/<host>/uc-root/index.html` نگاشت می‌شود که یک قانون سادهٔ `try_files` آن را پیدا می‌کند. پیش از 0.6.1، صفحهٔ اصلی در یک بخش هش‌شده ذخیره می‌شد و قوانین سادهٔ `try_files` هرگز آن را پیدا نمی‌کردند.

## تأیید کارکرد کش

### تأیید MISS (درخواست اول)

```bash
curl -I https://example.com/
```

هدر مورد انتظار (شتاب‌دهی توسط سرور یا جایگزین PHP):

```
X-Ultimate-Performance: MISS
```

(در حالت شتاب‌دهی توسط سرور، هدر توسط `Engine` در مسیر نوشتن MISS ارسال می‌شود؛ HITهای بعدی توسط Nginx بدون هدر سطح PHP ارائه می‌شوند.)

### تأیید FALLBACK-HIT (حالت جایگزین PHP)

```bash
curl -s -o /dev/null -D - https://example.com/ | grep -i 'x-ultimate-performance'
```

مورد انتظار:

```
X-Ultimate-Performance: FALLBACK-HIT
```

`FallbackServer::serve()` این هدر را پیش از خروج ارسال می‌کند؛ بدنه مستقیماً از `v/<host>/<path>/index.html` خوانده می‌شود.

### تأیید HIT سرور (حالت شتاب‌دهی توسط سرور)

HITهای شتاب‌دهی‌شده توسط سرور PHP را اجرا نمی‌کنند، بنابراین هدر `X-Ultimate-Performance: HIT` **وجود ندارد**. دو راه برای تأیید:

1. **سنجش TTFB:** یک HIT سرور در حدود 0.3 ms TTFB (خواندن فایل + سربار Nginx) تکمیل می‌شود؛ یک MISS بین 40 تا 400 ms (رندر کامل وردپرس) طول می‌کشد. از `curl -w "@-" -o /dev/null -s https://example.com/ <<<'%{time_starttransfer}\n'` استفاده کنید.
2. **بازرسی فایل کش:** پس از اولین درخواست، به دنبال `wp-content/cache/ultimate-performance/v/<host>/uc-root/index.html` بگردید. اگر فایل وجود دارد و درخواست دوم به‌طور قابل‌توجهی سریع‌تر است، سرور از دیسک پاسخ می‌دهد.

پروب خودآزماییِ پیشخوان ساده‌ترین اثبات سرتاسری است.

## پاک‌سازی کش

- **پاک‌سازی یک URL:** در صفحهٔ پیشخوان، URL را در فیلد **Purge URL** وارد و **Purge** را بزنید. این `Hooks::purge_url()` را فراخوانی می‌کند که هم `https://` و هم `http://` را امتحان می‌کند و بدنهٔ کش‌شده + متا + ورودی‌های رجیستر تگ را حذف می‌کند.
- **پاک‌سازی کل:** روی **Purge entire cache** کلیک کنید (تأیید دوگانه؛ نیاز به توانایی `ultimate_performance_purge_all` دارد). `Hooks::purge_site()` را فراخوانی می‌کند که کل درخت `v/`، درخت `meta/` را حذف و یک purge-all برای LSCache (در صورت استفاده از LiteSpeed) را صف می‌کند. هویت گره (`meta/node-id.json`) در یک purge-all حفظ می‌شود.
- **WP-CLI:** برای کنش‌های دارای دامنهٔ خوشه:
  ```bash
  wp ultimate-performance cluster status
  wp ultimate-performance cluster epoch --bump       # همهٔ گره‌ها را مجبور به reconcile می‌کند
  wp ultimate-performance cluster events
  wp ultimate-performance cluster reconcile          # فقط گره محلی
  ```

ویرایش‌های محتوا (`save_post`، `delete_post`، `edit_term`، `woocommerce_update_product`، `transition_comment_status` و غیره) اشیاء تأثیرپذیرفته را **همگام** برای پیوند یکتای ویرایش‌شده و **ناهمگام** برای فن‌اوت تگ گسترده‌تر، باطل می‌کنند.

## حذف

1. در `wp-admin ← افزونه‌ها`، Ultimate Performance را غیرفعال کنید. هنگام غیرفعال‌سازی:
   - هوک‌های WP-Cron (`ultimate_performance_tick`، `ultimate_performance_janitor`) پاک می‌شوند.
   - اگر `wp-content/object-cache.php` متعلق به ما باشد، حذف می‌شود.
   - اگر `wp-content/advanced-cache.php` متعلق به ما باشد، حذف می‌شود.
2. روی **حذف** کلیک کنید. وردپرس `WP_UNINSTALL_PLUGIN` را تعریف و `uninstall.php` را اجرا می‌کند که `Installer::uninstall_data()` را فراخوانی می‌کند. این کار:
   - گزینهٔ `ultimate_performance_settings` را به پیش‌فرض بازنشانی می‌کند.
   - هر ترانزینت متعلق به افزونه (`uc_env_snapshot`، `uc_oc_dropin_status`، `uc_registry_overflow`، `uc_settings_errors`) را حذف می‌کند.
   - همهٔ هوک‌های cron افزونه را پاک می‌کند.
   - توانایی `ultimate_performance_purge_all` را از `administrator` برمی‌دارد.
   - سه جدول مشترک خوشه (`uc_invalidation_events`، `uc_cluster_epoch`، `uc_cluster_nodes`) را روی پیشوند پایه حذف می‌کند — یک DROP برای کل شبکهٔ مالتی‌سایت.
   - کل درخت کش در `wp-content/cache/ultimate-performance/` را به‌طور بازگشتی حذف می‌کند.
3. **هرگز انجام نمی‌شود** (قرارداد صداقت نسخهٔ انتشار):
   - هیچ `FLUSHALL` / `FLUSHDB` روی Redis.
   - هیچ `flush_all` روی Memcached.
   - هیچ `apcu_clear_cache()` روی APCu.

   یک کلید بیگانهٔ Redis / Memcached / APCu از هر حذف جان سالم به‌در می‌برد.

## گام‌های بعدی

- [پیکربندی](configuration-fa.md) — مرجع کامل تنظیمات.
- [راهنمای هاست](hosting-fa.md) — ماتریس پشتیبانی و جزئیات راه‌اندازی به تفکیک سرور.
- [اشکال‌زدایی](troubleshooting-fa.md) — مشکلات رایج و تشخیص.
