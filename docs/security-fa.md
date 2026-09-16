# نمای کلی امنیت

[English](security.md) | فارسی

این سند نمای کلی امنیتِ رو به کاربر Ultimate Performance است. برای مدل تهدید فنی کامل (STRIDE به تفکیک زیرسیستم، لایه‌بندی دفاع، چک‌لیست ممیزی)، به [docs/SECURITY.md](SECURITY.md) مراجعه کنید. برای سیاست افشای مسئول، به [SECURITY.md](../SECURITY.md) سطح مخزن مراجعه کنید.

Ultimate Performance در سراسر آن از اصل fail-closed پیروی می‌کند: اگر افزونه نتواند به‌طور مطمئن اثبات کند یک پاسخ برای کش عمومی ایمن است، آن را **BYPASS** می‌کند. ترتیب اولویت: **امنیت > درستی > قابلیت اطمینان > سازگاری > کارایی > راحتی**.

## محافظت‌ها در برابر مسمومیت کش

### مدیریت host

`UltimatePerformance\CacheKey\Key::canonical_host($host)` لایهٔ دفاعی host است:

- lower-case، trim، رد کاراکتر کنترلی / whitespace / `/ \ ? # @ % [ ]`، رد > 253 نویسه.
- حذف `:port` انتهایی **پیش از** شاخهٔ IPv6 بدون براکت (تا `example.com:8080` با یک IPv6 literal اشتباه گرفته نشود).
- پذیرش IPv6 با براکت و بدون براکت، نرمال‌سازی به یک فرم بدون کولون قطعی برای ایمنی FS.
- حذف نقطهٔ انتهایی FQDN.
- اعتبارسنجی نام host در برابر یک regex سختگیرانه برچسب.

`UltimatePerformance\Request\Classifier::host_allowed($canonical_host)` host را به allowlist سایت محدود می‌کند: `wp_parse_url(home_url(), PHP_URL_HOST)` به‌علاوه، در مالتی‌سایت، همهٔ سایت‌های شبکه از طریق `get_sites()`. `SERVER_NAME` **هرگز** قابل اعتماد نیست — می‌تواند روی سرورهای نادرست پیکربندی‌شده توسط مهاجم کنترل شود.

اگر host قانونی خالی یا در allowlist نباشد، درخواست `BYPASS` با علت `host-not-allowed:<host>` طبقه‌بندی می‌شود. یک هدر `Host:` مسموم نمی‌تواند درخت کش سایت دیگری را انتخاب کند.

### مدیریت کوئری‌استرینگ

`Classifier::check_query($query)` اعمال می‌کند:

- نام‌های پارامتر خالی ⇒ `BYPASS`.
- پارامترهای tracking (`utm_*`، `gclid`، `fbclid`، `msclkid`، `dclid`، `twclid`، `mc_eid`، `igshid`، `_ga`) همیشه از کلید کش حذف می‌شوند، فارغ از سیاست.
- پارامترهای ناشناخته از `query_unknown_policy` پیروی می‌کنند:
  - `bypass` (پیش‌فرض) ⇒ هر پارامتر ناشناخته BYPASS اجباری می‌کند.
  - `strip` ⇒ پارامترهای ناشناخته حذف، صفحه تحت کلید قانونی کش می‌شود.
  - `variant` ⇒ هر مقدار پارامتر ناشناخته پوشهٔ کش خود را می‌گیرد.
- پارامترهای allowlist به سقف 8 شمارش می‌شوند؛ بیش از 8 ⇒ `BYPASS` با علت `too-many-params`.

این کار از حملات farming کوئری‌استرینگ که در آن مهاجم برای پر کردن کش URLهای یکتای زیادی تولید می‌کند، جلوگیری می‌کند.

### عبور کوکی و نشست خصوصی

`Classifier::sensitive_cookie($cookies)` هر نام کوکی را در برابر `cookie_bypass_regex` تطبیق می‌دهد (پیش‌فرض شامل کوکی‌های auth `wordpress_*`، `wp-postpass`، `comment_author`، `woocommerce_*`، `wp_woocommerce_session_*`، `PHPSESSID`، `wp-settings-*`). هر تطابق ⇒ `BYPASS`.

در قطعهٔ Nginx، نقشهٔ مرکب `$up_cookieless` تنها با یک هدر `Cookie:` خالی مطابقت می‌دهد. هر کوکی (از جمله لاگین) درخواست را پویا کرده و به مبدا PHP هدایت می‌کند.

`FallbackServer::is_cacheable_request()` همان بررسی کوکی را در مسیر درآپ‌این boot زودهنگام انجام می‌دهد.

### مسیرهای عبور

`bypass_paths` (پیش‌فرض: `cart`، `checkout`، `my-account`، `wc-api`، `wishlist`، `compare`، `order-pay`، `order-received`، `orders`، `view-order`، `edit-address`، `lost-password`، `customer-logout`) توسط هم `Classifier` و هم `FallbackServer` بررسی می‌شود. هر مسیری که با یکی از این slugها (حساس به حروف بزرگ و کوچک نیست) آغاز شود، هرگز کش نمی‌شود.

### مسیرهای رزروشدهٔ وردپرس

`Classifier::reserved_prefixes()` فهرست مسیرهای رزروشدهٔ وردپرس که هرگز به‌صورت عمومی کش نمی‌شوند را برمی‌گرداند: `/wp-admin`، `/wp-login.php`، `/wp-cron.php`، `/wp-json`، `/xmlrpc.php`، `/wp-content/plugins`، `/wp-content/themes`، `/wp-content/uploads/woocommerce_uploads`، `/feed/`، `/comments/feed`، `/author/`، `/wp-login`.

همچنین Classifier رد می‌کند:

- هر `.php` در مسیر (PHP dispatch هرگز به‌صورت عمومی کش نمی‌شود).
- فایل‌های پویای رزروشده (`robots.txt`، `favicon.ico`، `wp-cron.php`، `wp-login.php`، `xmlrpc.php`، `wp-links-opml.php`، `wp-signup.php`، `wp-activate.php`، `wp-trackback.php`، `wp-comments-post.php`، `.env`، `wp-config.php`).
- فایل‌های نقطه‌دار (به‌جز `.well-known/`) و مصنوعات پشتیبان (`.bak`، `.old`، `.orig`، `.save`، `.swp`، `.sql`، `.log`، `.ini`، `.conf`).
- پسوندهای فریب کش (`deny_extensions`: `php`، `json`، `xml`، `axd`، `aspx`، `jsp`، `cgi`، `phar`، `phtml`، `svg`).
- جستجو، پیش‌نمایش و کوئری‌های `s=` / `preview=`.
- sitemapها و فیدها (`sitemap*`، `feed`، `rss2?`، `atom`).
- قطعه‌های مسیر (`#` در URI — قطعه‌ها هرگز به سرور نمی‌رسند؛ اگر یکی ظاهر شود، URI دشمن‌نما است).

## دفاع از پیمایش مسیر

### پالایندهٔ بخش

`Key::segment($seg)` `.` و `..` را رد می‌کند (به‌عنوان دفاع در عمق هش می‌کند)، بخش‌ها را برای NTFS/macOS با حساسیت به حروف بزرگ و کوچک case-fold می‌کند، و تنها بخش‌های منطبق با `^[a-z0-9][a-z0-9._\-]{0,63}$` را می‌پذیرد. هر چیز دیگر به `h<sha1[:20]>` هش می‌شود. پیمایش از طریق URL غیرممکن است: هر بخش sanitize می‌شود و نویسنده هرگز مسیری را اختراع نمی‌کند که خواننده نمی‌کرد.

### دربری‌گیری SafeFs

`UltimatePerformance\Core\SafeFs` تنها انتزاع filesystem است که افزونه استفاده می‌کند. هر عمل FS از آن عبور می‌کند. نامتغیر دربری‌گیری: هیچ عملی نمی‌تواند از ریشه‌های مجاز فرار کند — از جمله از طریق symlinkها، NTFS junctionها، نام‌های کوتاه 8.3، نام‌گذاری aliasing نقطه‌انتهایی/فاصله‌ای Win32، تغییر case، یا مسیرهای UNC.

`SafeFs::validate_write($path)` عمیق‌ترین جدول موجود را پیمایش می‌کند، آن را `realpath()` می‌کند (سینک‌ها و junctionها را فرومی‌پاشد) و تأیید می‌کند که مسیر حل‌شده درون یک ریشهٔ مجاز قرار دارد. دو هندسهٔ معتبر پشتیبانی می‌شوند (عادی: جدول درون ریشه؛ تازه: زیردرخت ریشه مفقود — تنها هنگامی پذیرفته می‌شود که `realpath` ثابت کند جدول ریشه را در بر دارد). junctionها/سینک‌ها در هر مؤلفهٔ موجود، مسیر حل‌شده را تغییر می‌دهند و هر دو بند را رد می‌کنند.

`SafeFs::write_atomic()` از نوشتن امتناع می‌کند اگر عمیق‌ترین جدول موجود یک symlink باشد، و `read()` / `delete()` / `scandir()` همیشه `is_link()` را بررسی کرده و از پیروی امتناع می‌کنند.

## نوشتن‌های اتمیک

`SafeFs::write_atomic($path, $data)`:

1. مسیر را به‌صورت لغوی + دربری‌گیری ریشه + دربری‌گیری حل‌شدهٔ عمیق‌ترین جدول موجود اعتبارسنجی می‌کند.
2. در صورت نیاز پوشهٔ والد را می‌سازد.
3. در یک فایل temp یکتا (`.<unique>.tmp`) در همان پوشه می‌نویسد.
4. `fflush()` + `fclose()` برای flush بافر OS.
5. `rename()` به هدف — اتمیک روی POSIX، با یک بازگشت delete-then-rename با 5 retry × 20 ms روی نقض اشتراک ویندوز.
6. تنها هنگام rename موفق `true` برمی‌گرداند. هنگام شکست، فایل temp حذف می‌شود.

خوانندگان قدیمی یا جدید را می‌بینند، هرگز جزئی نه. `Store::write()` بدنه را از طریق `write_atomic()` می‌نویسد، سپس sidecar `index.html.meta.json` را (همچنین از طریق `write_atomic()`) می‌نویسد؛ اگر نوشتن متا شکست بخورد، بدنه حذف می‌شود — هرگز بدنه‌ای بدون متا باقی نگذارید.

## مالکیت درآپ‌این (OWNERSHIP_MARKER)

`UltimatePerformance\Compatibility\AdvancedCacheDropin` درآپ‌این `wp-content/advanced-cache.php` را با معناشناسی مالکیت سختگیرانه مدیریت می‌کند:

- `OWNERSHIP_MARKER = '/* Ultimate Performance advanced-cache.php v1 */'` به‌عنوان خط دوم درآپ‌این تولیدشده جای‌گذاری می‌شود.
- `is_owned_by_us()` 256 بایت اول فایل را می‌خواند و به دنبال نشانه می‌گردد.
- `is_foreign()` اگر فایل موجود اما بدون نشانه باشد، true برمی‌گرداند.
- `install()` اگر `is_foreign()` برابر true باشد، false برمی‌گرداند — **هرگز درآپ‌این افزونهٔ دیگر را بازنویسی نمی‌کند**. این یک قرارداد ایمنی نسخهٔ انتشار است.
- `remove()` تنها اگر `is_owned_by_us()` برابر true باشد، فایل را حذف می‌کند.
- درآپ‌این هنگام فعال‌سازی افزونه (هنگامی که `php_fallback_enabled = true` باشد) تنها در صورتی نصب می‌شود که درآپ‌این بیگانه‌ای وجود نداشته باشد؛ هنگام غیرفعال‌سازی تنها در صورتی حذف می‌شود که هنوز مالک آن باشیم.

همین الگو برای درآپ‌این `object-cache.php` اعمال می‌شود: `Installer::deactivate()` محتوای فایل را می‌خواند و تنها اگر حاوی `UltimateCache` باشد آن را حذف می‌کند.

## محافظت در برابر advanced-cache.php بیگانه

هنگامی که یک درآپ‌این بیگانه موجود باشد، `EnvironmentDetector::detect_mode()` برمی‌گرداند `MODE_CONFLICTED` و پیشخوان یک هشدار نمایش می‌دهد. UC درآپ‌این خود را نصب نمی‌کند، درآپ‌این موجود را تغییر نمی‌دهد و از مسیر جایگزین PHP پاسخ نمی‌دهد. کش صفحه همچنان در حالت شتاب‌دهی توسط سرور کار می‌کند (اگر وب‌سرور پیکربندی شده باشد)، اما جایگزین PHP تا زمان حل تعارض در دسترس نیست.

## مسیرهای خصوصی WooCommerce

`Classifier` مسیرهای خصوصی WooCommerce را از طریق `bypass_paths` عبور می‌دهد:

- `cart`، `checkout`، `my-account`، `wc-api`، `wishlist`، `compare`، `order-pay`، `order-received`، `orders`، `view-order`، `edit-address`، `lost-password`، `customer-logout`.

کوکی‌های سبد/نشست ووکامرس (`woocommerce_*`، `wp_woocommerce_session_*`) با `cookie_bypass_regex` تطبیق داشته و یک BYPASS اجباری می‌کنند — بنابراین یک مشتری لاگین‌شده با سبد فعال هرگز صفحهٔ کش‌شده دریافت نمی‌کند.

`ResponseSanitizer` (BENCH-D4 / HARDEN-3) مصنوعات **رندرشدهٔ** ووکامرس را که ممکن است در صفحات در غیر این‌صورت قابل‌کش ظاهر شوند، شکار می‌کند:

- آیتم‌های سبد رندرشده: یک `<li>` با `mini_cart_item` و `data-product_id`.
- جمع کل سبد رندرشده: `woocommerce-mini-cart__total` و به دنبال آن `woocommerce-Price-amount`.
- شمارش سبد رندرشده: `cart-count` و به دنبال آن `woocommerce-Price-amount`.
- nonceهای هر نشست: `woocommerce-cart-nonce`، `data-cart-nonce`.
- تأیید سفارش / ورودی فرم checkout: `woocommerce-order-overview`، `billing_email`.
- قیمت‌گذاری خاص مشتری: `customer-price`.

رویکرد قبلی broad-substring (مثلاً `woocommerce-mini-cart-item`) هر صفحهٔ واقعی ووکامرس را بیش‌ازحد مسدود می‌کرد، زیرا ظرف سبد خالی در 100٪ صفحات موجود است. الگوهای معنایی اکنون تنها محتوای **رندرشدهٔ وابسته به نشست** را تطبیق می‌دهند.

`Hooks::purge_product()` به‌صورت همگام پیوند یکتای محصول را باطل می‌کند، سپس فن‌اوت تگ گسترده‌تر (`post:<id>`، `product:<id>`، `post_type:product`، `shop_archive`، `front_page`) را ناهمگام در صف می‌گذارد.

## سیاست اعتبارنامه

**اعتبارنامه‌ها تنها از ثابت‌ها / محیط خوانده می‌شوند و هرگز لاگ نمی‌شوند، به‌صورت متن ساده ذخیره نمی‌شوند یا در تشخیص‌ها افشا نمی‌شوند.**

- Redis: `UC_REDIS_HOST`، `UC_REDIS_PORT`، `UC_REDIS_AUTH`، `UC_REDIS_DB`، `UC_REDIS_TLS`، `UC_REDIS_SOCKET`.
- Memcached: `UC_MEMCACHED_HOST`، `UC_MEMCACHED_PORT`، `UC_MEMCACHED_SOCKET`.
- SQLite: `UC_SQLITE_FILE`.
- File: `UC_FILE_CACHE_DIR`.
- RabbitMQ: `UC_RABBITMQ_HOST`، `UC_RABBITMQ_PORT`، `UC_RABBITMQ_USER`، `UC_RABBITMQ_PASSWORD`، `UC_RABBITMQ_VHOST`.

تنظیمات نوشته‌شده از طریق پیشخوان sanitize و در گزینهٔ `ultimate_performance_settings` ذخیره می‌شوند؛ فیلدهای `redis.auth` و `amqp.pass` ذخیره می‌شوند اما هرگز هنگام ذخیره به فرم پیشخوان بازگردانده نمی‌شوند (فرم یک فیلد رمز خالی نشان می‌دهد؛ ارسال خالی مقدار قدیمی را نگه می‌دارد).

هنگامی که متغیرهای محیطی RabbitMQ موجود نباشند، سوئیت‌های رگرسیون RabbitMQ به‌طور خودکار به حالت BLOCKED / SKIP تمیز درمی‌آیند — هرگز به‌عنوان PASS شمرده نمی‌شوند. این یک قرارداد صداقت نسخهٔ انتشار است: هرگز PASS جعلی نسازید.

runner `tests/run-all-regression.sh` هرگز اعتبارنامه‌های RabbitMQ را درون‌خطی تنظیم نمی‌کند. آن‌ها باید از محیط فراخواننده بیایند.

تله‌متری به‌ساختار محدود است: schema ثابت، برچسب‌های فقط enum، بدون URL، بدون کلید کش، بدون مسیر، بدون اعتبارنامه. به‌صورت JSON و یک نمایش Prometheus رندر می‌شود.

## چک‌لیست ممیزی

چک‌لیست ممیزی Phase E توسط سوئیت‌های رگرسیون تأیید شده است:

- [x] کوکی لاگین ⇒ BYPASS پیش از lookup و پیش از write.
- [x] HTML دارای nonce در زمان write رد می‌شود.
- [x] پارامتر کوئری ناشناخته ⇒ BYPASS.
- [x] host در allowlist نیست ⇒ BYPASS.
- [x] `..` در مسیر ⇒ BYPASS + رد بخش.
- [x] پسوندهای denylist (`.json` و غیره) ⇒ BYPASS.
- [x] POST / AJAX / REST / XMLRPC / wp-admin / wp-cron / wp-login ⇒ BYPASS.
- [x] کوکی‌های cart / checkout / account ⇒ BYPASS.
- [x] rename اتمیک؛ خوانندگان فقط سندهای کامل را می‌بینند.
- [x] TTL قفل از بن‌بست جلوگیری می‌کند؛ token مالک از سرقت بین‌درخواستی جلوگیری می‌کند.
- [x] symlink / junction خارج از ریشه ⇒ قرنطینه در اجرای janitor؛ writeها دربری‌گیری را تأیید می‌کنند.
- [x] purge تگ تنها اشیاء تگ‌شده را لمس می‌کند.
- [x] namespaceهای مالتی‌سایت ایزوله هستند.
- [x] `advanced-cache.php` بیگانه هرگز بازنویسی نمی‌شود.
- [x] کلید بیگانه Redis / Memcached / APCu از هر flush جان سالم به‌در می‌برد.

برای مدل تهدید STRIDE کامل به [docs/SECURITY.md](SECURITY.md) مراجعه کنید.

## افشای مسئول

اگر یک آسیب‌پذیری امنیتی در Ultimate Performance کشف کردید، لطفاً به‌صورت مسئول آن را گزارش کنید. به [SECURITY.md](../SECURITY.md) سطح مخزن برای سیاست افشا و نسخه‌های پشتیبانی‌شده مراجعه کنید. برای آسیب‌پذیری‌های امنیتی یک issue عمومی GitHub باز نکنید — از کانال افشای خصوصی توضیح‌داده‌شده در سیاست استفاده کنید.

## همچنین ببینید

- [docs/SECURITY.md](SECURITY.md) — مدل تهدید STRIDE کامل به تفکیک زیرسیستم.
- [docs/ARCHITECTURE.md](ARCHITECTURE.md) — لایه‌بندی دفاع، ماتریس شکست.
- [مرجع فنی](technical-fa.md) — چرخهٔ حیات درخواست و تولید کلید کش.
- [اشکال‌زدایی](troubleshooting-fa.md) — مراحل تشخیص مرتبط با امنیت.
