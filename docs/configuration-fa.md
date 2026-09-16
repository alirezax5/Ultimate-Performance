# پیکربندی

[English](configuration.md) | فارسی

این سند مرجع کامل تنظیمات Ultimate Performance است. پیش‌فرض‌ها از `UltimatePerformance\Core\Settings::defaults()` می‌آیند و در هر درخواست با گزینهٔ ذخیره‌شده ادغام می‌شوند. تنظیمات به‌صورت یک گزینهٔ واحد autoload شده با نام `ultimate_performance_settings` ذخیره می‌شوند.

پشتیبان‌ها (Redis / Memcached / SQLite) را می‌توان **یا** از طریق صفحهٔ پیشخوان **یا** از طریق ثابت‌ها / متغیرهای محیطی پیکربندی کرد. ثابت‌ها اولویت دارند و به شما اجازه می‌دهند اعتبارنامه‌ها را از پایگاه‌داده دور نگه دارید.

## کلیدهای اصلی

| تنظیم | نوع | پیش‌فرض | توضیح |
|---|---|---|---|
| `enabled` | bool | `true` | کلید اصلی افزونه. هنگام `false`، افزونه به‌طور کامل غیرفعال می‌شود. |
| `page_cache_enabled` | bool | `false` (opt-in) | کش صفحه (نوشتن + پاسخ‌دهی) را فعال می‌کند. پیش‌فرض خاموش — کش صفحه opt-in است. |
| `object_cache_enabled` | bool | `false` (opt-in) | درآپ‌این `object-cache.php` را نصب و فعال می‌کند. پیش‌فرض خاموش — opt-in. |
| `invalidation_enabled` | bool | `true` | هنگام `false`، باطل‌سازی مبتنی بر هوک تعلیق می‌شود (کاربرد دیباگ / مهاجرت). |
| `queue_enabled` | bool | `true` | صف دوام‌دار پاک‌سازی را فعال می‌کند. هنگام `false`، پاک‌سازی درون‌خطی (حالت sync) اجرا می‌شود. |
| `php_fallback_enabled` | bool | `true` | درآپ‌این `advanced-cache.php` را هنگام فعال‌سازی نصب می‌کند و حالت جایگزین PHP را روی هاست اشتراکی فعال می‌کند. درآپ‌این هرگز در صورت وجود درآپ‌این بیگانه بازنویسی نمی‌شود. |

## تنظیمات کش صفحه

| تنظیم | نوع | پیش‌فرض | بازه / اعتبارسنجی | توضیح |
|---|---|---|---|---|
| `ttl` | int | `3600` | 30 تا `MONTH_IN_SECONDS` (2 592 000) | زمان حیات صفحات کش‌شده، به ثانیه. |
| `swr_enabled` | bool | `true` | — | stale-while-revalidate: ارائهٔ محتوای stale پس از TTL درون `swr_grace`. |
| `swr_grace` | int | `300` | 0 تا `DAY_IN_SECONDS` (86 400) | پنجرهٔ مهرت برای SWR، به ثانیه. |
| `debug_headers` | bool | `false` | — | ارسال `X-Ultimate-Performance-Reason` هنگام BYPASS برای کمک به اشکال‌زدایی. در محیط تولید فعال نکنید — جزئیات داخلی Classifier را افشا می‌کند. |

## قفل بازتولید (محافظت از stampede)

این موارد در BENCH-D7 / HARDEN-1 برای جلوگیری از بازتولید کش thundering-herd اضافه شدند.

| تنظیم | نوع | پیش‌فرض | توضیح |
|---|---|---|---|
| `herd_protection` | bool | `true` | کلید اصلی تک‌پرواز. هنگام `false`، هر MISS مستقلاً رندر می‌شود (توصیه نمی‌شود). |
| `genlock_ttl` | int | `30` | TTL قفل به ثانیه. `flock` هنگام مرگ پروسهٔ PHP به‌طور خودکار آزاد می‌شود، اما TTL از حالت تخریب‌شده محافظت می‌کند. |
| `genlock_wait_budget_us` | int | `2 500 000` | حداکثر انتظار کل منتظرها به میکروثانیه (2.5 ثانیه). محدود؛ هرگز بی‌نهایت نیست. منتظرها هر 20 میلی‌ثانیه ± 0 تا 15 میلی‌ثانیه jitter نظرسنجی می‌کنند. |

هنگامی که `herd_protection = true` و کش سرد است:

1. یک درخواست قفل بازتولید را به‌دست می‌آورد (تبدیل به **generator** می‌شود).
2. درخواست‌های همزمان (**منتظرها**) `Store::lookup()` را با انتظار محدود + jitter نظرسنجی می‌کنند. اگر کش تازه‌ای ظاهر شود، آن را ارائه می‌کنند. اگر کش stale‌ای ظاهر شود و `swr_enabled = true` باشد، بدنهٔ stale را یک‌بار از طریق callback `$on_stale` ارائه کرده و خارج می‌شوند.
3. اگر بودجهٔ انتظار تمام شود، منتظر به رندر بازمی‌گردد (آخرین راه‌حل، اما هرگز بن‌بست نیست).

## سیاست کوئری‌استرینگ

| تنظیم | نوع | پیش‌فرض | توضیح |
|---|---|---|---|
| `query_allowlist` | آرایه | `['p', 'page_id', 'page', 'paged', 'feed', 'lang']` | پارامترهای کوئری که می‌توانند در کلید کش شرکت کنند (حداکثر 24 ورودی). کوئری‌های allowlist به‌صورت مرتب‌شده، URL-encoded و با یک پیشوند SHA1 16 کاراکتری در نام پوشهٔ کش متمایز می‌شوند. |
| `query_unknown_policy` | enum | `'bypass'` | یکی از `bypass`، `strip`، `variant`. `bypass` = هر پارامتر ناشناخته ⇒ کش نمی‌شود. `strip` = پارامترهای ناشناخته حذف، صفحه تحت کلید قانونی کش می‌شود. `variant` = هر مقدار پارامتر ناشناخته پوشهٔ کش خود را می‌گیرد. |
| `tracking_params_strip` | bool | `true` | همیشه پارامترهای tracking (`utm_*`، `gclid`، `fbclid`، `msclkid`، `dclid`، `twclid`، `mc_eid`، `igshid`، `_ga`) را از کلید کش حذف می‌کند، فارغ از سیاست بالا. |

## عبور کوکی و مسیر

| تنظیم | نوع | پیش‌فرض | توضیح |
|---|---|---|---|
| `cookie_bypass_regex` | رشته (PCRE) | `'wordpress_[a-f0-9]{32}\|wordpress_logged_in_[a-f0-9]{32}\|wordpress_sec_[a-f0-9]{32}\|wp-postpass\|comment_author\|woocommerce_\|wp_woocommerce_session_\|PHPSESSID\|wp-settings-[0-9]+'` | هر کوکی که نام آن با این regex مطابقت داشته باشد، یک BYPASS اجباری می‌کند. هنگام ذخیره اعتبارسنجی می‌شود — باید compile شود. |
| `bypass_paths` | آرایه | `['cart', 'checkout', 'my-account', 'wc-api', 'wishlist', 'compare', 'order-pay', 'order-received', 'orders', 'view-order', 'edit-address', 'lost-password', 'customer-logout']` | slugهای مسیری که BYPASS اجباری می‌کنند. هر مسیری که با یکی از این‌ها (حساس به حروف بزرگ و کوچک نیست) آغاز شود، هرگز کش نمی‌شود. حداکثر 64 ورودی. |
| `deny_extensions` | آرایه | `['php', 'json', 'xml', 'axd', 'aspx', 'jsp', 'cgi', 'phar', 'phtml', 'svg']` | پسوندهای فایلی که هرگز کش نمی‌شوند (دفاع در برابر فریب کش). |

## واریانت‌ها

| تنظیم | نوع | پیش‌فرض | توضیح |
|---|---|---|---|
| `variants.webp` | bool | `false` | هنگام `true` و وجود `image/webp` در هدر `Accept:` درخواست، کلید کش شامل `v=webp` است و پوشهٔ مجزایی نوشته می‌شود. |
| `variants.mobile` | bool | `false` | هنگام `true`، user agentهای موبایل در پوشهٔ مجزا کش می‌شوند. |

## کش شیء

| تنظیم | نوع | پیش‌فرض | توضیح |
|---|---|---|---|
| `object_cache_chain` | آرایه | `['redis', 'memcached', 'apcu', 'sqlite', 'file']` | فهرست مرتب از پشتیبان‌ها. اولین پشتیبانی که `configured()` آن `true` برمی‌گرداند، برنده می‌شود. **هیچ زنجیرهٔ بازگشتی بین پشتیبان‌ها در زمان اجرا وجود ندارد** — پشتیبانی که mid-request شکست بخورد، برای باقی درخواست علامت‌گذاری می‌شود و انتخاب‌گر در درخواست بعدی پشتیبان بعدی را برمی‌گزیند. |

### Redis

| تنظیم | نوع | پیش‌فرض | توضیح |
|---|---|---|---|
| `redis.host` | رشته | `'127.0.0.1'` | هاست TCP ردیس. با env / ثابت `UC_REDIS_HOST` بازنویسی کنید. |
| `redis.port` | int | `6379` | پورت TCP ردیس. با `UC_REDIS_PORT`. |
| `redis.auth` | رشته | `''` | رمز AUTH ردیس (اختیاری). با `UC_REDIS_AUTH`. |
| `redis.db` | int | `0` | شماره پایگاه‌دادهٔ ردیس. با `UC_REDIS_DB`. |
| `redis.tls` | bool | `false` | اتصال از طریق TLS. با `UC_REDIS_TLS`. |
| `redis.timeout` | float | `1.5` | timeout اتصال به ثانیه. |

سوکت UNIX (`UC_REDIS_SOCKET`) بر TCP ارجحیت دارد. هیچ بازگشتی بین سوکت و TCP نیست.

### Memcached

| تنظیم | نوع | پیش‌فرض | توضیح |
|---|---|---|---|
| `memcached.host` | رشته | `'127.0.0.1'` | هاست TCP. با `UC_MEMCACHED_HOST`. |
| `memcached.port` | int | `11211` | پورت TCP. با `UC_MEMCACHED_PORT`. |
| `memcached.timeout` | float | `1.5` | timeout اتصال. |

سوکت UNIX (`UC_MEMCACHED_SOCKET`) بر TCP ارجحیت دارد. هیچ بازگشتی نیست.

### APCu

APCu هنگامی که `ext-apcu` بارگذاری شده و `apcu_enabled()` برابر `true` باشد، به‌طور خودکار فعال می‌شود. بدون تنظیمات. APCu **محلی به ازای سرور** است (مشترک بین workerهای FPM روی یک ماشین، به‌ازای پروسه تحت CLI)؛ هرگز به‌عنوان توزیع‌شده تبلیغ نمی‌شود.

### SQLite

| تنظیم | نوع | پیش‌فرض | توضیح |
|---|---|---|---|
| `sqlite.file` | رشته | `''` | مسیر مطلق فایل پایگاه‌دادهٔ SQLite. با `UC_SQLITE_FILE`. هنگام خالی بودن، پشتیبان از مکان پیش‌فرض در ریشهٔ کش استفاده می‌کند. |

SQLite از حالت WAL، `busy_timeout = 250 ms` و حساب‌های تراکنشی `BEGIN IMMEDIATE` برای incr/decr استفاده می‌کند (بدون از دست رفتن به‌روزرسانی‌ها در contention چندپروسه‌ای).

### File

پشتیبان file از نوشتن‌های اتمیک `UltimatePerformance\Core\SafeFs` (temp + rename) با نام‌های فایل مبتنی بر هش و رد symlink استفاده می‌کند. مسیر را با `UC_FILE_CACHE_DIR` بازنویسی کنید. هنگام تنظیم‌نبودن، پشتیبان از یک مسیر در ریشهٔ کش استفاده می‌کند.

## پشتیبان صف

| تنظیم | نوع | پیش‌فرض | توضیح |
|---|---|---|---|
| `queue_backend` | enum | `'auto'` | یکی از `auto`, `rabbitmq`, `action-scheduler`, `wp-cron`, `local`, `sync`. `auto` اولین پشتیبان موجود را به ترتیب RabbitMQ ← Action Scheduler ← WP-Cron ← Local ← Sync انتخاب می‌کند. |

### RabbitMQ (AMQP)

| تنظیم | نوع | پیش‌فرض | توضیح |
|---|---|---|---|
| `amqp.host` | رشته | `'127.0.0.1'` | هاست RabbitMQ. با `UC_RABBITMQ_HOST`. |
| `amqp.port` | int | `5672` | پورت RabbitMQ. با `UC_RABBITMQ_PORT`. |
| `amqp.user` | رشته | `'guest'` | کاربر RabbitMQ. با `UC_RABBITMQ_USER`. |
| `amqp.pass` | رشته | `''` | رمز RabbitMQ. با `UC_RABBITMQ_PASSWORD`. خالی بودن مقدار قدیمی را هنگام ذخیره نگه می‌دارد. |
| `amqp.vhost` | رشته | `'/'` | vhost. با `UC_RABBITMQ_VHOST`. |
| `amqp.exchange` | رشته | `'ultimate-performance'` | نام exchange. |

**اعتبارنامه‌ها فقط از ثابت‌ها / متغیرهای محیطی خوانده می‌شوند و هرگز لاگ نمی‌شوند، به‌صورت متن ساده ذخیره نمی‌شوند یا در تشخیص‌ها افشا نمی‌شوند.** هنگام نبود متغیرهای RabbitMQ، سوئیت‌های RabbitMQ به‌طور خودکار به حالت BLOCKED / SKIP تمیز درمی‌آیند — هرگز به‌عنوان PASS شمرده نمی‌شوند.

## پیش‌بارگذاری

| تنظیم | نوع | پیش‌فرض | بازه | توضیح |
|---|---|---|---|---|
| `preload.concurrency` | int | `2` | 1 تا 16 | workerهای همزمان fetch برای warmup. |
| `preload.batch` | int | `50` | 1 تا 1000 | URL به ازای دستهٔ warmup. |

برنامه‌های warmup **تنها** از درخت کش صفحهٔ خودِ افزونه می‌آیند (از نظر SSRF به‌ساختار ایمن)، از صف واحد پاک‌سازی استفاده می‌کنند و به یک نگهبان epoch احترام می‌گذارند تا یک تغییر تنظیمات، برنامه‌های stale را لغو کند.

## یکپارچگی وب‌سرور

| تنظیم | نوع | پیش‌فرض | توضیح |
|---|---|---|---|
| `nginx.origin` | رشته (IP:port) | `''` | آدرس مبدا PHP برای قطعهٔ Nginx تولیدشده (مثلاً `127.0.0.1:8098`). حداکثر 21 نویسه. |
| `nginx.listen` | رشته (IP:port) | `''` | دستور `listen` برای قطعهٔ Nginx تولیدشده (مثلاً `127.0.0.1:8097`). حداکثر 21 نویسه. |
| `apache_integration` | bool | `false` | نویسندهٔ قوانین `.htaccess` آپاچی را فعال کنید. |
| `lscache_mode` | enum | `'auto'` | یکی از `auto`, `native`, `generic`. `auto` = تشخیص LiteSpeed و استفاده از LSCache بومی هنگام موجود بودن؛ `native` = همیشه هدرهای LSCache را ارسال کن؛ `generic` = فقط از کش فایل عمومی استفاده کن. |

## API تنظیمات (برای توسعه‌دهندگان)

```php
$settings = \UltimatePerformance\Core\Settings::instance();
$ttl = $settings->get( 'ttl', 3600 );                       // تک کلید
$redis_host = $settings->get( 'redis.host', '127.0.0.1' );  // مسیر نقطه‌دار
$raw = $settings->raw();                                    // آرایهٔ کامل (بازنویسی پیشخوان)
```

`Settings::save_from_admin( $input )` یک آرایهٔ کامل تنظیمات از ورودی پیشخوان را اعتبارسنجی و ذخیره می‌کند. یک `map<field, error>` برمی‌گرداند (خالی = موفق). هنگام موفقیت، اکشن `ultimate_performance_settings_saved` را با دادهٔ جدید شلیک می‌کند.

`Settings::reset()` گزینه را حذف و پیش‌فرض‌ها را بازبارگذاری می‌کند. توسط `Installer::uninstall_data()` فراخوانی می‌شود.

### ثابت‌ها / متغیرهای محیطی

| پشتیبان | متغیرها |
|---|---|
| Redis | `UC_REDIS_HOST`, `UC_REDIS_PORT`, `UC_REDIS_AUTH`, `UC_REDIS_DB`, `UC_REDIS_TLS`, `UC_REDIS_SOCKET` (سوکت بر TCP ارجحیت دارد) |
| Memcached | `UC_MEMCACHED_HOST`, `UC_MEMCACHED_PORT`, `UC_MEMCACHED_SOCKET` |
| SQLite | `UC_SQLITE_FILE` |
| File | `UC_FILE_CACHE_DIR` |
| RabbitMQ | `UC_RABBITMQ_HOST`, `UC_RABBITMQ_PORT`, `UC_RABBITMQ_USER`, `UC_RABBITMQ_PASSWORD`, `UC_RABBITMQ_VHOST` |

## همچنین ببینید

- [نصب](installation-fa.md) — شروع سریع و گردش‌کار فعال‌سازی.
- [راهنمای هاست](hosting-fa.md) — انتخاب حالت به ازای محیط.
- [مرجع فنی](technical-fa.md) — نحوهٔ استفاده از این تنظیمات در زمان درخواست.
