# اشکال‌زدایی

[English](troubleshooting.md) | فارسی

این راهنما رایج‌ترین مشکلاتی که اپراتورها با Ultimate Performance مواجه می‌شوند، نحوهٔ تشخیص آن‌ها و نحوهٔ رفعشان را پوشش می‌دهد.

## فهرست

1. [کش همیشه MISS](#1-کش-همیشه-miss)
2. [FALLBACK-HIT ظاهر نمی‌شود](#2-fallback-hit-ظاهر-نمی‌شود)
3. [SERVER_ACCELERATED تشخیص داده نشد](#3-server_accelerated-تشخیص-داده-نشد)
4. [پیکربندی Nginx مفقود یا اعمال‌نشده](#4-پیکربندی-nginx-مفقود-یا-اعمالنشده)
5. [تعارض advanced-cache.php](#5-تعارض-advanced-cachephp)
6. [WP_CACHE غیرفعال](#6-wp_cache-غیرفعال)
7. [پوشهٔ کش قابل‌نوشتن نیست](#7-پوشهٔ-کش-قابلنوشتن-نیست)
8. [صفحهٔ اصلی کش نمی‌شود](#8-صفحهٔ-اصلی-کش-نمی‌شود)
9. [سبد ووکامرس به‌اشتباه کش می‌شود](#9-سبد-ووکامرس-بهاشتباه-کش-می‌شود)
10. [کاربران لاگین‌شده صفحهٔ کش‌شده دریافت می‌کنند](#10-کاربران-لاگینشده-صفحهٔ-کششده-دریافت-میکنند)
11. [اشکال‌زدایی ابطال کش](#11-اشکالزدایی-ابطال-کش)
12. [Redis / Memcached در دسترس نیست](#12-redis--memcached-در-دسترس-نیست)
13. [افزونهٔ SQLite مفقود](#13-افزونهٔ-sqlite-مفقود)
14. [مشکلات دسترسی](#14-مشکلات-دسترسی)
15. [مسائل کلید کش host / port](#15-مسائل-کلید-کش-host--port)
16. [اجرای خودآزمایی](#16-اجرای-خودآزمایی)
17. [جمع‌آوری اطلاعات تشخیصی](#17-جمعآوری-اطلاعات-تشخیصی)

---

## 1. کش همیشه MISS

### نشانه‌ها

هر درخواست `X-Ultimate-Performance: MISS` برمی‌گرداند (یا در حالت شتاب‌دهی توسط سرور، هر پاسخ به PHP-FPM می‌رود بدون افزایش سرعت).

### علل و راه‌حل‌ها

| علت | نحوه بررسی | راه‌حل |
|---|---|---|
| کش صفحه غیرفعال | `تنظیمات ← Ultimate Performance ← Enable page cache` تیک نخورده | تیک بزنید و ذخیره کنید. |
| Classifier BYPASS | `debug_headers` را در تنظیمات فعال کنید؛ پاسخ `X-Ultimate-Performance-Reason: <reason>` را خواهد داشت. | علت را برطرف کنید (مثلاً کوکی لاگین، پارامتر کوئری، مسیر عبور). |
| کوکی لاگین | `X-Ultimate-Performance-Reason: cookie:wordpress_logged_in_*` | از وردپرس خارج شوید، یا برای تست از یک پنجرهٔ ناشناس استفاده کنید. |
| پارامتر کوئری در allowlist نیست | `X-Ultimate-Performance-Reason: query:<param>` | پارامتر را به `query_allowlist` اضافه کنید، یا `query_unknown_policy = strip` تنظیم کنید. |
| POST / متد غیر GET | `X-Ultimate-Performance-Reason: method:POST` | رفتار مورد انتظار — POST هرگز کش نمی‌شود. |
| مسیر یک slug عبور است | `X-Ultimate-Performance-Reason: bypass-path:cart` | رفتار مورد انتظار — cart/checkout/etc. هرگز کش نمی‌شوند. |
| ریشهٔ کش قابل‌نوشتن نیست | خودآزمایی `cache_writable: false` گزارش می‌دهد | به [#7](#7-پوشهٔ-کش-قابلنوشتن-نیست) مراجعه کنید. |
| کد وضعیت 2xx/3xx نیست | `X-Ultimate-Performance-Reason: status:404` | صفحه یک وضعیت غیرقابل‌کش برمی‌گرداند؛ بدنه هرگز ذخیره نمی‌شود. |
| ResponseSanitizer رد کرد | `X-Ultimate-Performance-Reason: unsafe-html:<reason>` | صفحه دارای nonce، markup لاگین یا قطعهٔ سبد ووکامرس رندرشده است. باید بررسی کنید چرا صفحه به sanitizer رسید. |

## 2. FALLBACK-HIT ظاهر نمی‌شود

### نشانه‌ها

حالت جایگزین PHP پیکربندی شده (درآپ‌این موجود، `WP_CACHE` true)، اما پاسخ‌های کش‌شده دارای `X-Ultimate-Performance: FALLBACK-HIT` نیستند.

### علل و راه‌حل‌ها

| علت | نحوه بررسی | راه‌حل |
|---|---|---|
| `WP_CACHE` تعریف نشده | `wp-config.php` را باز و به دنبال `define('WP_CACHE', true);` بگردید | خط را بالای کامنت `/* That's all, stop editing! */` اضافه کنید. |
| درآپ‌این بیگانه موجود | `EnvironmentDetector` `CONFLICTED` گزارش می‌دهد | درآپ‌این افزونهٔ کش دیگر را غیرفعال یا حذف کنید، سپس UC را غیرفعال و دوباره فعال کنید. |
| درآپ‌این متعلق به ما نیست | 256 بایت اول `wp-content/advanced-cache.php` را بخوانید؛ باید شامل `/* Ultimate Performance advanced-cache.php v1 */` باشد | UC را غیرفعال و دوباره فعال کنید. اگر هنوز نصب نشد، به [#5](#5-تعارض-advanced-cachephp) مراجعه کنید. |
| host با port حذف نشده (پیش از 0.6.1) | فایل کش در `v/127.0.0.1/index.html` نوشته شده اما درخواست به `v/127.0.0.18095/index.html` می‌گردد | به 0.6.1 ارتقا دهید — اصلاح حذف portِ `FallbackServer` این را حل می‌کند. |
| درخواست دارای کوئری‌استرینگ | جایگزین از هر کوئری‌استرینگ غیرخالی عبور می‌کند | رفتار مورد انتظار — اگر وردپرس بارگذاری شود Classifier کامل اجرا می‌شود. |
| درخواست دارای کوکی حساس | جایگزین `cookie_bypass_regex` را بررسی می‌کند | برای تست از وردپرس خارج شوید. |
| مسیر یک slug عبور است | جایگزین `bypass_paths` را بررسی می‌کند | رفتار مورد انتظار. |
| بدنهٔ کش < 64 بایت | جایگزین از سرو بدنه‌های ریز امتناع می‌کند | بررسی کنید چرا وردپرس پاسخ ریز تولید کرده. |

## 3. SERVER_ACCELERATED تشخیص داده نشد

### نشانه‌ها

`EnvironmentDetector` به جای `SERVER_ACCELERATED`، `MISCONFIGURED` گزارش می‌دهد.

### علل و راه‌حل‌ها

| علت | نحوه بررسی | راه‌حل |
|---|---|---|
| ریشهٔ کش قابل‌نوشتن نیست | خودآزمایی شکست می‌خورد | به [#7](#7-پوشهٔ-کش-قابلنوشتن-نیست) مراجعه کنید. |
| سرور در فهرست پشتیبانی نیست | `EnvironmentDetector::detect_server()` برمی‌گرداند `unknown` یا `iis` | سرور برای حالت شتاب‌دهی توسط سرور پشتیبانی نمی‌شود. به جای آن از جایگزین PHP استفاده کنید. |
| افزونه غیرفعال | `enabled = false` در تنظیمات | افزونه را فعال کنید. |
| کش صفحه غیرفعال | `page_cache_enabled = false` در تنظیمات | کش صفحه را فعال کنید. |

`detect_mode()` حالت **پیکربندی‌شده** را برمی‌گرداند، نه حالت تأییدشده. برگشت `SERVER_ACCELERATED` تنها به این معناست که پیکربندی موجود است — برای اطمینان از اینکه سرور واقعاً به‌صورت ایستا پاسخ می‌دهد، پروب تأیید Nginx را اجرا کنید. به [#4](#4-پیکربندی-nginx-مفقود-یا-اعمالنشده) مراجعه کنید.

## 4. پیکربندی Nginx مفقود یا اعمال‌نشده

### نشانه‌ها

پیشخوان قطعهٔ تولیدشده را نمایش می‌دهد، اما پروب تأیید قبول نمی‌شود — پاسخ توسط PHP سرو می‌شود، نه Nginx.

### علل و راه‌حل‌ها

| علت | نحوه بررسی | راه‌حل |
|---|---|---|
| قطعه در `nginx.conf` قرار نگرفته | `sudo nginx -T \| grep up-cache` چیزی برنمی‌گرداند | `include /absolute/path/to/ultimate-performance-nginx.conf;` را درون بلاک `http {}` در `nginx.conf` اضافه کنید. |
| Nginx پس از تغییر پیکربندی بازبارگذاری نشده | `sudo nginx -t` موفق می‌شود اما رفتار قدیمی باقی می‌ماند | `sudo nginx -s reload`. |
| مسیر cache_root اشتباه در قطعه | قطعهٔ تولیدشده را باز و خط `alias` را بررسی کنید | قطعه را از پیشخوان مجدداً تولید کنید (مسیر را به‌طور خودکار اصلاح می‌کند). |
| host اشتباه در قطعه | `server_name` با host سایت مطابقت ندارد | host را در پیشخوان دوباره وارد و قطعه را مجدداً تولید کنید. |
| `try_files` صفحهٔ اصلی را پیدا نمی‌کند | فایل کش در `v/<host>/uc-root/index.html` است اما شما `try_files /up-cache/$host//index.html` نوشته‌اید | از `try_files $uri $uri/ /up-cache/$host$uri/index.html /index.php?$args;` استفاده کنید — اسلش انتهایی روی `$uri/` مهم است. |
| درخواست‌های `.php` از دیسک سرو می‌شوند | `curl -I https://example.com/index.php` بسیار سریع و محتوای کش‌شده برمی‌گرداند | مسیر `.php` در قطعه همیشه به مبدا پروکسی می‌شود — تأیید کنید قطعه همان‌طور‌که‌هست اعمال شده. |

## 5. تعارض advanced-cache.php

### نشانه‌ها

`EnvironmentDetector` `CONFLICTED` را گزارش می‌دهد — یک درآپ‌این `advanced-cache.php` بیگانه وجود دارد.

### تشخیص

```bash
head -5 wp-content/advanced-cache.php
```

اگر 256 بایت اول شامل `/* Ultimate Performance advanced-cache.php v1 */` نباشد، درآپ‌این متعلق به افزونهٔ دیگری است.

### راه‌حل

1. مالک را شناسایی کنید (کامنت در فایل معمولاً نام افزونه را می‌آورد).
2. آن افزونهٔ کش را از طریق `wp-admin ← افزونه‌ها` غیرفعال یا حذف کنید.
3. اگر افزونهٔ دیگر `wp-content/advanced-cache.php` را پاک نکرده، آن را دستی حذف کنید.
4. Ultimate Performance را غیرفعال و سپس دوباره فعال کنید. درآپ‌این نصب خواهد شد.

UC **هرگز** درآپ‌این بیگانه را بازنویسی نمی‌کند — این یک قرارداد ایمنی نسخهٔ انتشار است.

## 6. WP_CACHE غیرفعال

### نشانه‌ها

`EnvironmentDetector` گزارش می‌دهد `PHP_FALLBACK` در دسترس نیست با وجود اینکه درآپ‌این وجود دارد.

### علت

وردپرس تنها هنگامی `wp-content/advanced-cache.php` را بارگذاری می‌کند که `define('WP_CACHE', true);` در `wp-config.php` موجود باشد.

### راه‌حل

`wp-config.php` را ویرایش و خط را **بالای** کامنت `/* That's all, stop editing! */` اضافه کنید:

```php
define('WP_CACHE', true);
```

ذخیره و سایت را بازبارگذاری کنید. `EnvironmentDetector` اکنون باید `PHP_FALLBACK` (یا `SERVER_ACCELERATED` اگر Nginx پیکربندی شده) گزارش دهد.

## 7. پوشهٔ کش قابل‌نوشتن نیست

### نشانه‌ها

خودآزمایی `cache_writable: false` گزارش می‌دهد. `EnvironmentDetector` `MISCONFIGURED` را گزارش می‌دهد. کش صفحه هرگز فایل نمی‌نویسد.

### تشخیص

```bash
ls -la wp-content/cache/ultimate-performance/
sudo -u www-data touch wp-content/cache/ultimate-performance/test-write
```

اگر `touch` شکست بخورد، کاربر وب‌سرور نمی‌تواند در ریشهٔ کش بنویسد.

### راه‌حل

```bash
# www-data را با کاربر وب‌سرور خود جایگزین کنید (apache، nginx و غیره)
sudo chown -R www-data:www-data wp-content/cache/ultimate-performance
sudo find wp-content/cache/ultimate-performance -type d -exec chmod 755 {} \;
sudo find wp-content/cache/ultimate-performance -type f -exec chmod 644 {} \;
```

اگر ریشهٔ کش وجود ندارد، UC را غیرفعال و دوباره فعال کنید — `Installer::ensure_cache_root()` آن را می‌سازد.

## 8. صفحهٔ اصلی کش نمی‌شود

### نشانه‌ها

همهٔ صفحات به‌جز `/` (صفحهٔ اصلی) درست کش می‌شوند. فایل کش در `v/<host>/uc-root/index.html` هرگز نوشته نمی‌شود، یا `try_files` هرگز آن را پیدا نمی‌کند.

### علل

| علت | نحوه بررسی | راه‌حل |
|---|---|---|
| باگ ROOT_SENTINEL پیش از 0.6.1 | فایل کش در `v/<host>/h<sha1[:20]>/index.html` است به جای `v/<host>/uc-root/index.html` | به 0.6.1 ارتقا دهید — `ROOT_SENTINEL = "uc-root"` تضمین می‌کند صفحهٔ اصلی از یک بخش بدون هش استفاده می‌کند. |
| `try_files` دست‌نویس با مسیر اشتباه | `try_files /up-cache/$host/index.html` (بدون بخش `uc-root`) | از `try_files $uri $uri/ /up-cache/$host$uri/index.html /index.php?$args;` استفاده کنید — فرم `$uri/` مسیر `/` را به‌درستی نگاشت می‌کند. |
| صفحهٔ اصلی دارای redirect | `curl -I https://example.com/` 301 به `https://example.com` (بدون اسلش انتهایی) نشان می‌دهد | redirect توسط وردپرس است (نرمال‌سازی `home_url()`). کلید کش از مسیر پس از redirect استفاده می‌کند — با خودآزمایی پیشخوان تأیید کنید. |
| صفحهٔ اصلی دارای کوئری‌استرینگ | سایت از `/?lang=en` به‌عنوان صفحهٔ اصلی استفاده می‌کند | `lang` را به `query_allowlist` اضافه کنید (در پیش‌فرض موجود) یا `query_unknown_policy = strip` تنظیم کنید. |

## 9. سبد ووکامرس به‌اشتباه کش می‌شود

### نشانه‌ها

آیتم‌های سبد یک کاربر روی یک صفحهٔ کش‌شده به کاربران دیگر نمایش داده می‌شود.

### علل

این یک نقص **نسخهٔ انتشار** است و نباید در 0.6.1 رخ دهد. اگر مشاهده کردید:

| علت | نحوه بررسی | راه‌حل |
|---|---|---|
| `bypass_paths` نادرست پیکربندی شده | `تنظیمات ← Ultimate Performance ← Bypass paths` شامل `cart`، `checkout` و غیره نیست | `bypass_paths` پیش‌فرض را بازگردانید (فهرست در `Settings::defaults()`). |
| `cookie_bypass_regex` شامل کوکی‌های وووکامرس نیست | regex با `woocommerce_*` یا `wp_woocommerce_session_*` تطبیق نمی‌دهد | `cookie_bypass_regex` پیش‌فرض را بازگردانید. |
| قطعهٔ سبد روی یک صفحهٔ غیر Woo | یک تم widget سبد را روی هر صفحه‌ای رندر می‌کند (header / sidebar) | ResponseSanitizer آیتم‌های سبد **رندرشده** را شکار می‌کند (یک `<li>` با `mini_cart_item` و `data-product_id`). اگر یک تم سفارشی آیتم‌های سبد را به شکلی متفاوت رندر می‌کند، یک الگوی سفارشی از طریق فیلتر `ultimate_performance_unsafe_html_regexes` اضافه کنید. |
| sanitizer broad-substring پیش از 0.6.1 بیش‌ازحد مسدود می‌کند | پیش از 0.6.1: ظرف سبد خالی هر صفحهٔ Woo را مسدود می‌کرد | به 0.6.1 ارتقا دهید — الگوهای معنایی اکنون تنها محتوای رندرشده را تطبیق می‌دهند. |

اگر نشت سبد مشاهده کردید، آن را به‌عنوان **مشکل امنیتی** از طریق GitHub Issues گزارش کنید (به [سیاست امنیتی](../SECURITY.md) مراجعه کنید).

## 10. کاربران لاگین‌شده صفحهٔ کش‌شده دریافت می‌کنند

### نشانه‌ها

یک مدیر لاگین‌شده صفحهٔ کش‌شدهٔ ناشناس را می‌بیند (بدون نوار مدیریت، بدون "Howdy, Name").

### علل

| علت | نحوه بررسی | راه‌حل |
|---|---|---|
| `cookie_bypass_regex` نادرست پیکربندی شده | regex با `wordpress_logged_in_*` تطبیق نمی‌دهد | `cookie_bypass_regex` پیش‌فرض را بازگردانید. |
| حالت شتاب‌دهی توسط سرور HTML کش‌شده را به کاربر لاگین‌شده سرو می‌کند | نقشهٔ `$up_cookieless` در قطعهٔ Nginx تنها با یک هدر `Cookie:` **خالی** مطابقت می‌دهد — هر کوکی (از جمله لاگین) درخواست را پویا می‌کند | تأیید کنید قطعهٔ Nginx همان‌طور‌که‌هست اعمال شده؛ بررسی کوکی را تغییر ندهید. |
| مرورگر صفحه را به‌صورت محلی کش کرده | URL با `X-Ultimate-Performance: BYPASS` پاسخ می‌دهد اما مرورگر هنوز محتوای قدیمی نشان می‌دهد | refresh سخت (Ctrl+F5) یا کش مرورگر را پاک کنید. |
| عدم تطابق مسیر کوکی لاگین | کوکی برای مسیر / دامنهٔ متفاوت تنظیم شده | کوکی‌ها را در ابزار توسعه‌دهندهٔ مرورگر بررسی کنید. |

## 11. اشکال‌زدایی ابطال کش

### نشانه‌ها

ویرایش یک پست، صفحهٔ کش‌شدهٔ آن را به‌روز نمی‌کند؛ محتوای قدیمی باقی می‌ماند.

### تشخیص

1. `debug_headers` را فعال کنید. یک پست را در `wp-admin` ویرایش کنید.
2. پیوند یکتای پست اکنون باید در درخواست بعدی `X-Ultimate-Performance: MISS` برگرداند (sync_purge_permalink ورودی کش‌شده را حذف کرد).
3. اگر پیوند یکتا هنوز `X-Ultimate-Performance: HIT` برمی‌گرداند (حالت شتاب‌دهی توسط سرور: بدون هدر، اما بدنه قدیمی است)، بررسی کنید:

| علت | نحوه بررسی | راه‌حل |
|---|---|---|
| `invalidation_enabled = false` | تنظیمات را بخوانید | ابطال را فعال کنید. |
| هوک ثبت نشده | `Hooks::register()` را بررسی کنید — اکشن `save_post` باید متصل باشد | افزونه را دوباره فعال کنید. |
| رویداد خوشه مصرف نشده (چندگره) | `wp ultimate-performance cluster events` رویدادهای معلق نشان می‌دهد | `wp ultimate-performance cluster reconcile` را اجرا کنید (فقط گره محلی). |
| رجیستر تگ out of sync | فایل `meta/tag-<md5>.json` rel_dir پست را فهرست نمی‌کند | یک purge-all اجرا کنید تا ایندکس بازسازی شود. |
| صفحه در لبهٔ CDN کش شده | پاسخ دارای هدر `CF-Cache-Status` یا `X-Cache` از یک CDN است | کش CDN را برای آن URL پاک کنید. |

### تأیید اجرای ابطال

اکشن `Hooks::purge_post()`، `ultimate_performance_after_purge_post` را با ID پست، تگ‌ها و تعداد پوشه‌های باطل‌شده شلیک می‌کند. برای تشخیص به آن هوک بزنید:

```php
add_action( 'ultimate_performance_after_purge_post', function( $post_id, $tags, $n ) {
    error_log( "UC purge_post: post=$post_id tags=" . implode( ',', $tags ) . " n=$n" );
}, 10, 3 );
```

## 12. Redis / Memcached در دسترس نیست

### نشانه‌ها

HITهای کش شیء به صفر می‌رسند؛ کوئری‌های `wp_options` به خواندن مستقیم DB بازمی‌گردند.

### تشخیص

`UltimatePerformance\ObjectCache\Manager` اولین پشتیبان سالم را در زنجیره انتخاب می‌کند. اگر Redis پیکربندی شده اما غیرقابل‌دسترس باشد:

- پشتیبان Redis برای باقی درخواست علامت‌گذاری می‌شود.
- انتخاب‌گر به پشتیبان بعدی (Memcached، APCu، SQLite، File، سپس memory داخلی WP) می‌رود.
- وردپرس هرگز fatal نمی‌شود — بدترین حالت کش runtime-only است.

### راه‌حل

```bash
# Redis
redis-cli -h 127.0.0.1 -p 6379 ping
# Memcached
echo stats | nc 127.0.0.1 11211
```

اگر دیمون پایین است، آن را بازراه‌اندازی کنید. اگر اعتبارنامه‌ها اشتباه‌اند، `UC_REDIS_AUTH` / صفحهٔ پیشخوان را اصلاح کنید.

برای تأیید اینکه پشتیبان به‌درستی متصل است:

```bash
wp eval 'var_dump( wp_cache_get( "test_key", "test_group" ) );'
```

این باید بدون خطا `false` برگرداند (کلید موجود نیست).

## 13. افزونهٔ SQLite مفقود

### نشانه‌ها

انتخاب پشتیبان کش شیء SQLite به‌صورت خاموش شکست می‌خورد؛ انتخاب‌گر به پشتیبان بعدی می‌رود.

### تشخیص

```bash
php -m | grep -i pdo
php -m | grep -i sqlite
```

پشتیبان SQLite نیازمند `pdo` و `pdo_sqlite` است.

### راه‌حل

افزونه‌ها را نصب کنید:

```bash
# Debian / Ubuntu
sudo apt-get install php8.3-sqlite3
sudo systemctl reload php8.3-fpm
```

اگر نمی‌توانید روی هاست اشتراکی افزونه نصب کنید، به جای آن از APCu یا پشتیبان File استفاده کنید.

## 14. مشکلات دسترسی

### نشانه‌ها

- پوشهٔ کش قابل‌نوشتن نیست (به [#7](#7-پوشهٔ-کش-قابلنوشتن-نیست) مراجعه کنید).
- درآپ‌این نمی‌تواند نصب شود (`AdvancedCacheDropin::install()` برمی‌گرداند false).
- پشتیبان file کش شیء نمی‌تواند بنویسد.

### تشخیص

```bash
# کاربر وب‌سرور (Debian/Ubuntu: www-data، RHEL: apache)
ps aux | grep -E 'nginx|apache|php-fpm' | grep -v grep
ls -la wp-content/cache/ultimate-performance/
ls -la wp-content/advanced-cache.php
```

### راه‌حل

```bash
# www-data را با کاربر وب‌سرور خود جایگزین کنید
sudo chown -R www-data:www-data wp-content/cache
sudo chown www-data:www-data wp-content/advanced-cache.php
sudo chmod 644 wp-content/advanced-cache.php
sudo find wp-content/cache/ultimate-performance -type d -exec chmod 755 {} \;
sudo find wp-content/cache/ultimate-performance -type f -exec chmod 644 {} \;
```

`.htaccess` سخت‌شده در ریشهٔ کش (`Installer::cache_htaccess()`) در صورت انحراف از خروجی مولد، به‌طور خودکار بازتولید می‌شود.

## 15. مسائل کلید کش host / port

### نشانه‌ها

- فایل کش برای host `example.com` نوشته شده اما درخواست به `example.com:443` می‌گردد.
- حالت جایگزین در `v/127.0.0.18095/` می‌نویسد به جای `v/127.0.0.1/`.

### علت

`Key::canonical_host()` پورت را از host حذف می‌کند. اصلاح حذف portِ `FallbackServer` در 0.6.1 تضمین می‌کند که جایگزین نیز پورت را حذف کند. نسخه‌های پیش از 0.6.1 دارای عدم تطابق بودند: `Key::canonical_host` پورت را حذف می‌کرد اما `FallbackServer` نه — بنابراین جایگزین به دنبال `v/127.0.0.18095/index.html` (پورت به host چسبیده) به جای `v/127.0.0.1/index.html` می‌گشت.

### تشخیص

```bash
# درخت کش را بررسی کنید
ls wp-content/cache/ultimate-performance/v/
# اگر پوشه‌ای مانند '127.0.0.18095' یا 'example.com:443' دیدید،
# عدم تطابق host/port دارید.
```

### راه‌حل

1. به 0.6.1 ارتقا دهید.
2. افزونه را غیرفعال و دوباره فعال کنید (یا روی **Purge entire cache** کلیک کنید).
3. با `curl -s -o /dev/null -D - https://example.com/ | grep -i x-ultimate-performance` تأیید کنید.

## 16. اجرای خودآزمایی

خودآزمایی پیشخوان (`EnvironmentDetector::run_self_test()`) یک ورودی پروب کش می‌نویسد، آن را می‌خواند و حذف می‌کند. این فروشگاه کش را آزمایش می‌کند، نه پیکربندی پاسخ ایستای وب‌سرور را.

### خودآزمایی دستی

```bash
wp eval '
$r = \UltimatePerformance\Core\EnvironmentDetector::run_self_test();
var_dump( $r );
'
```

خروجی مورد انتظار:

```
array(4) {
  ["cache_writable"]=> bool(true)
  ["probe_written"]=> bool(true)
  ["probe_readable"]=> bool(true)
  ["probe_token"]=> string(16) "..."
}
```

اگر `cache_writable` برابر false است، به [#7](#7-پوشهٔ-کش-قابلنوشتن-نیست) مراجعه کنید.

### پروب تأیید Nginx

پروب تأیید Nginx (`WebServer\Nginx\Rules::probe_uri()` / `probe_body()`) اثبات وضعیت فعال برای حالت شتاب‌دهی توسط سرور است. آن را از پیشخوان اجرا کنید (دکمهٔ **Run verify probe**). یک فایل پروب در `/uc-verify-<token>/` با بدنهٔ بایت‌به‌بایت می‌نویسد، سپس یک درخواست HTTP می‌فرستد؛ اگر بدنهٔ پاسخ دقیقاً با `probe_body($token)` مطابقت داشته باشد، قطعه به‌صورت ایستا پاسخ می‌دهد.

## 17. جمع‌آوری اطلاعات تشخیصی

هنگام گزارش یک مشکل، شامل کنید:

1. **نسخهٔ Ultimate Performance:** `wp eval 'echo ULTIMATE_PERFORMANCE_VERSION;'`
2. **وضعیت EnvironmentDetector:** از `تنظیمات ← Ultimate Performance`.
3. **نرم‌افزار سرور:** `curl -I https://example.com/ | grep -i server`.
4. **نسخهٔ PHP:** `php -v` و `wp cli info`.
5. **نسخهٔ وردپرس:** `wp core version`.
6. **dump تنظیمات (sanitized):** از پیشخوان (دکمهٔ **Export settings** اگر موجود، یا فیلدهای قابل‌مشاهده را کپی کنید).
7. **نتیجهٔ خودآزمایی:** از پیشخوان.
8. **ورودی‌های لاگ خطا:** از `wp-content/cache/ultimate-performance/stats.jsonl` (محدود، بدون اعتبارنامه).
9. **مراحل بازتولید:** URL دقیق، درخواست دقیق (`curl -I ...`).
10. **رفتار مورد انتظار در برابر واقعی.**

**هرگز** اعتبارنامه‌های پایگاه‌داده، رمزهای Redis/Memcached یا هر رازی را به اشتراک نگذارید. سیاست اعتبار افزونه آن‌ها را تنها از ثابت‌ها / محیط می‌خواند و هرگز لاگ نمی‌کند.

## همچنین ببینید

- [نصب](installation-fa.md) — رویه‌های تأیید و حذف.
- [راهنمای هاست](hosting-fa.md) — انتخاب حالت درست.
- [پیکربندی](configuration-fa.md) — مرجع کامل تنظیمات.
- [مرجع فنی](technical-fa.md) — چرخهٔ حیات درخواست و تولید کلید.
- [نمای کلی امنیت](security-fa.md) — سیاست اعتبارنامه و دفاع‌های مسمومیت کش.
