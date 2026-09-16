# توسعه

[English](development.md) | فارسی

این سند برای مشارکت‌کنندگان است. به راه‌اندازی مخزن، پیش‌نیازهای PHP، اجرای آزمون، مهم‌ترین سوئیت‌های ممیزی، فلسفهٔ انتشار، اعتبارسنجی fresh-checkout، قوانین ساخت artifact، قراردادهای کدنویسی و نحوهٔ مشارکت می‌پردازد.

## راه‌اندازی مخزن

```bash
git clone https://github.com/alirezax5/ultimate-cache.git
cd ultimate-cache
composer install        # php-amqplib را نصب می‌کند (تنها وابستگی runtime)
```

افزونه هیچ وابستگی runtime در Composer به‌جز `php-amqplib/php-amqplib ^3.6` (که تنها هنگام پیکربندی RabbitMQ استفاده می‌شود) ندارد. پوشهٔ `vendor/` commit شده تا یک نصب وردپرس نیازی به اجرای Composer نداشته باشد.

## پیش‌نیازهای PHP

- **Runtime:** PHP 8.3 به بالا. افزونه از ویژگی‌های `readonly`، ویژگی‌های typed، آرگومان‌های نام‌دار و سایر ویژگی‌های 8.x استفاده می‌کند.
- **توسعه / آزمون‌ها:** PHP 8.3 به بالا با افزونه‌های زیر. بنچمارک و live gateهای Phase O روی PHP 8.5.4-FPM اجرا شدند؛ ماتریکس 8.2 / 8.3 / 8.4 نیز سبز است.

افزونه‌های PHP موردنیاز برای پوشش کامل آزمون:

- `pdo`, `pdo_sqlite` (پشتیبان کش شیء SQLite)
- `redis` (پشتیبان کش شیء Redis — تنها اگر Redis را آزمایش می‌کنید)
- `memcached` (پشتیبان کش شیء Memcached — تنها اگر Memcached را آزمایش می‌کنید)
- `apcu` (پشتیبان کش شیء APCu؛ برای آزمون‌های CLI به `apc.enable_cli=1` نیاز است)
- `mbstring` (برای `mb_strtolower` و غیره — همراه با اکثر buildهای PHP)
- `pcntl` (برای live gateهای چندپروسه‌ای)

اسکریپت `tests/provision-php84.sh` یک PHP 8.4 محلی-کاربر با افزونه‌های موردنیاز می‌سازد؛ خانوادهٔ `tests/provision-*.sh` دیمون‌های واقعی را می‌سازد (MariaDB، Redis، Apache، Nginx، RabbitMQ، OLS).

## اجرای آزمون

runner رگرسیون کامل:

```bash
bash tests/run-all-regression.sh R
```

آرگومان (`R` بالا) یک برچسب round است که در خط خلاصهٔ هر سوئیت ظاهر می‌شود. این runner:

1. وضعیت WP-shim (`tests/wp-shim/state/*.json`) و درخت کش را بین هر سوئیت بازنشانی می‌کند، که آینهٔ یک سایت تازهٔ وردپرس است.
2. هر سوئیت ممیزی را زیر `timeout 590` (سقف 9 دقیقه به ازای سوئیت) اجرا می‌کند.
3. یک خط خلاصه به ازای هر سوئیت emits می‌کند: `<round> | <name> | pass=N | fail=N | skip/blocked=N | exit=<ec>`.
4. هنگام هر شکست، 5 خط `[FAIL]` اول را برای آن سوئیت چاپ می‌کند.

انتشار 0.6.1 دارای **48 سوئیت / 1334 PASS / 0 FAIL / 15 honest skip** بود. skipها روی سرویس‌های واقعی مفقود گیت دارند (RabbitMQ بدون اعتبارنامه، MariaDB بدون دیمون، OLS بدون lsphp) و **هرگز** به‌عنوان PASS شمرده نمی‌شوند — این یک قرارداد صداقت نسخهٔ انتشار است.

### سوئیت‌های مهم ممیزی

| سوئیت | هدف |
|---|---|
| `tests/audit-core.php` | تست‌های رفتار اصلی افزونه (98 بررسی). |
| `tests/audit-host-poison.php` | دفاع‌های مسمومیت هدر Host. |
| `tests/audit-classifier.php` | طبقه‌بندی درخواست — هر قانون عبور. |
| `tests/audit-sanitizer.php` | الگوهای ResponseSanitizer (85 بررسی). |
| `tests/audit-write-path.php` | مسیر نوشتن اتمیک + دربری‌گیری SafeFs. |
| `tests/audit-nginx-rules.php` | مولد قطعهٔ Nginx (37 بررسی). |
| `tests/audit-apache-hit.php` | پاسخ زودهنگام `.htaccess` آپاچی با شمارندهٔ اجرا. |
| `tests/audit-invalidation.php` | ابطال مبتنی بر تگ، سیم‌کشی هوک. |
| `tests/audit-object-cache.php` | semantic کش شیء (5 پشتیبان). |
| `tests/audit-oc-fencing.php` | فنس ارتقا برای پشتیبان‌های بازیابی‌شده. |
| `tests/audit-multisite-uninstall.php` | حذف مالتی‌سایت 3 جدول خوشه را برمی‌دارد، جدول‌های بیگانه جان سالم می‌برند. |
| `tests/audit-cluster.php` | publish/consume/dedup رویداد خوشه. |
| `tests/audit-cluster-epoch.php` | تشخیص فاصلهٔ epoch + reconcile. |
| `tests/audit-herd.php` | BENCH-D7 / HARDEN-1: تک‌پرواز GenerationLock. |
| `tests/audit-homepage.php` | BENCH-D5 / HARDEN-2: یکدستی ROOT_SENTINEL. |
| `tests/audit-woo-sanitizer.php` | BENCH-D4 / HARDEN-3: پالایندهٔ معنایی Woo. |
| `tests/audit-stale-exposure.php` | BENCH-D6 / HARDEN-4: ابطال ناهمگام+همگام ترکیبی. |
| `tests/audit-fallback.php` | HARDEN-5: حالت جایگزین PHP، مالکیت درآپ‌این. |
| `tests/audit-env-detect.php` | HARDEN-6: EnvironmentDetector + خودآزمایی. |
| `tests/audit-shared-hosting.php` | HARDEN-7: سازگاری هاست اشتراکی (بدون ریشه). |
| `tests/audit-benchmark.php` | بنچمارک کارایی (نامتغیر HIT صفر-PHP). |

### runnerهای زنده

برای آزمون‌هایی که نیازمند دیمون‌های واقعی هستند، خانوادهٔ `tests/run-*-live.sh` دیمون‌های rootless را خودکار تأمین می‌کند:

- `tests/run-mariadb-live.sh` — MariaDB 11.8.6، InnoDB، REPEATABLE-READ، FOR UPDATE row lock، burst 5000 رویداد.
- `tests/run-redis-live.sh` — Redis 8.0.2.
- `tests/run-apache-live.sh` — Apache 2.4.68 + WP واقعی.
- `tests/run-nginx-integration.sh` — Nginx 1.26.3 + مبدا `php -S` با شمارندهٔ اجرا اثبات صفر PHP هنگام HIT.
- `tests/run-rabbitmq-live.sh` — RabbitMQ 4.0.5 (rootless؛ اعتبارنامه از env).
- `tests/run-openlitespeed-live.sh` — OpenLiteSpeed 1.9.2 + LSAPI واقعی.
- `tests/run-cluster-live.sh` — خوشهٔ دوگره‌ای روی MariaDB واقعی.
- `tests/run-multi-node-live.sh` — ماتریکس چندگره.

runnerهای زنده روی میزبان خودکار تأمین می‌شوند (بدون Docker / systemd). آن‌ها نیازمند `apt-get download` (Debian closure)، یک `$HOME/.local` قابل‌نوشتن و (برای برخی) `pcntl_fork` هستند. اسکریپت `tests/provision-php84.sh` خود PHP را با افزونه‌های موردنیاز می‌سازد.

## فلسفهٔ انتشار

> **هیچ نسخه‌ای تنها به این دلیل که کد تولیدشده درست به‌نظر می‌رسد، دارای صلاحیت نمی‌شود.**

هر انتشار باید با یک اجرای رگرسیون غیرریشه با صفر شکست دارای صلاحیت شود. مجموعهٔ کامل `tests/run-all-regression.sh` باید با `0 FAIL` تمام شود پیش از آنکه تگ انتشار شود. کد دارای کمک هوش مصنوعی که رگرسیون را رد می‌کند، منتشر نمی‌شود.

گیت‌های صلاحیت‌بخشی انتشار (0.6.1):

- **رگرسیون غیرریشه:** 48 سوئیت، 1334 PASS، 0 FAIL، 15 honest skip.
- **اعتبارسنجی fresh-checkout:** یک `git clone` تازه از تگ انتشار، به دنبال آن `bash tests/run-all-regression.sh`، باید همان نتیجهٔ 1334 / 0 / 15 را تولید کند. اثبات fresh-clone وابستگی‌های محیطی پنهان در درخت کار توسعه‌دهنده را رد می‌کند.
- **شواهد بنچمارک تأییدشدهٔ زنده:** اعداد کارایی در [docs/benchmarks-fa.md](benchmarks-fa.md) روی محیط توسعه اندازه‌گیری شده و قابل بازتولید هستند.
- **PASS چرخهٔ حیات WP ZIP تازه:** یک ZIP انتشار نصب‌شده روی یک وردپرس تازه 6.7.2 باید به‌طور تمیز فعال شود، درآپ‌این‌ها را نصب کند، صفحات کش‌شده را سرو کند و به‌طور کامل حذف شود. runner `tests/run-real-wp-live.sh` این کار را خودکار می‌کند.

قانون سخت از `docs/ARCHITECTURE.md`:

> یک HIT امن کش صفحه عمومی برابر است با `درخواست HTTP ← وب‌سرور ← HTML ایستا ← مرورگر` با **0** بارگذاری وردپرس، **0** اجرای PHP، **0** پرس‌وجوی MySQL، **0** درخواست به Redis/Memcached/RabbitMQ.

هر انتشار این را با یک شمارندهٔ اجرا در مبدا روی pool برنامهٔ PHP-FPM دوباره تأیید می‌کند.

## اعتبارسنجی fresh-checkout

برای بازتولید صلاحیت‌بخشی 0.6.1:

```bash
git clone https://github.com/alirezax5/ultimate-cache.git
cd ultimate-cache
git checkout 0.6.1
composer install
bash tests/run-all-regression.sh R1
```

مورد انتظار: 48 سوئیت، 1334 PASS، 0 FAIL، 15 honest skip. checkout تازه باید دقیقاً با درخت کار توسعه‌دهنده مطابقت کند — اگر مطابقت نداشته باشد، انتشار خراب است.

## قوانین ساخت artifact

ZIP انتشار از یک checkout تمیز ساخته می‌شود، بدون artifactهای فقط-توسعه:

```bash
git clone https://github.com/alirezax5/ultimate-cache.git uc-release
cd uc-release
git checkout 0.6.1
composer install --no-dev --optimize-autoloader
zip -r ../ultimate-cache-0.6.1.zip . \
    -x '.git/*' \
    -x 'tests/*' \
    -x 'docs/*' \
    -x 'download/*' \
    -x 'worklog.md' \
    -x 'IDE.md' \
    -x 'composer.lock' \
    -x '.gitignore'
```

ZIP باید تنها شامل آنچه وردپرس در زمان اجرا نیاز دارد باشد:

- `ultimate-performance.php` (فایل اصلی افزونه)
- `uninstall.php`
- `src/**` (تمام منبع افزونه)
- `vendor/**` (وابستگی‌های Composer)
- `readme.txt` (readme وردپرس.org)
- `languages/ultimate-performance.pot`

ZIP نباید شامل باشد:

- `.git/` (کنترل نسخه)
- `tests/` (مجموعهٔ رگرسیون)
- `docs/` (مستندات توسعه‌دهنده)
- `download/` (artifactهای انتشار)
- `worklog.md`, `IDE.md` (یادداشت‌های توسعه‌دهنده)
- `composer.lock` (هنگام install بازتولید می‌شود)
- `.gitignore`

ZIP انتشار 0.6.1 مهر_and شده است — محتویات آن را تغییر ندهید. هر تغییری پس از انتشار نیازمند یک تگ نسخهٔ جدید است.

## قراردادهای کدنویسی

- **سینتکس PHP 8.3 به بالا**: ویژگی‌های readonly، ویژگی‌های typed، آرگومان‌های نام‌دار، constructor property promotion، عبارات match.
- **Namespaceها**: هر کلاس زیر `UltimatePerformance\<Subsystem>` قرار دارد، مثلاً `UltimatePerformance\Core\Settings`، `UltimatePerformance\PageCache\Engine`. autoloader (`src/Core/Autoloader.php`) `UltimatePerformance\A\B` را به `src/A/B.php` نگاشت می‌کند.
- **عملیات filesystem**: هر عمل FS از `SafeFs` عبور می‌کند. هیچ `file_put_contents`، `fopen`، `unlink`، `mkdir` مستقیم خارج از `SafeFs` نیست.
- **توابع وردپرس**: از توابع وردپرس استفاده کنید آنجا که موجودند (`wp_parse_url`، `wp_json_encode`، `wp_mkdir_p`، `wp_delete_file`، `wp_normalize_path`). `FallbackServer` استثناست — پیش از بارگذاری `wp-includes` اجرا می‌شود و تنها از توابع داخلی PHP + کلاس‌های خودِ افزونه استفاده می‌کند.
- **حالت شکست**: fail-closed. اگر افزونه نتواند به‌طور مطمئن اثبات کند یک پاسخ برای کش ایمن است، BYPASS. اگر یک پشتیبان غیرقابل‌دسترس باشد، به پشتیبان بعدی برو. هرگز fatal نشو.
- **کامنت‌ها**: هر کلاس غیر بدیهی یک docblock دارد که قرارداد طراحی و ترتیب اولویت را توضیح می‌دهد. اصلاحات نقص به ID نقص (`BENCH-D7`، `HARDEN-1` و غیره) و سوئیت ممیزی که آن‌ها را تأیید می‌کند ارجاع می‌دهند.
- **توگذاری**: tab (استاندارد کدنویسی وردپرس). `composer.json` یک پیکربندی phpcs ارائه نمی‌کند؛ مشارکت‌کنندگان باید به‌طور غیررسمی از استاندارد وردپرس پیروی کنند.
- **آزمون‌ها**: هر رفتار جدید باید یک سوئیت ممیزی داشته باشد. آزمون‌ها در `tests/audit-<name>.php` قرار دارند و خطوط `[PASS]` / `[FAIL]` / `[SKIP]` / `[BLOCKED]` را emits می‌کنند که runner آن‌ها را grep می‌کند.

## نحوهٔ مشارکت

برای راهنمای کامل به [CONTRIBUTING.md](../CONTRIBUTING.md) مراجعه کنید. نسخهٔ کوتاه:

1. مخزن را فورک کنید.
2. یک شاخه بسازید: `git checkout -b feature/<short-description>`.
3. تغییرات خود را اعمال کنید. سوئیت‌های ممیزی اضافه یا به‌روز کنید تا آن‌ها را پوشش دهد.
4. `bash tests/run-all-regression.sh R` را اجرا کنید. `0 FAIL` را نگه دارید.
5. با یک پیام روشن commit کنید. به ID نقص‌ها ارجاع دهید.
6. push کنید و یک PR باز کنید. توضیح دهید چه چیزی تغییر کرد، چرا و چگونه تأیید شد.
7. مشارکت‌های دارای کمک هوش مصنوعی پذیرفته می‌شوند — کمک هوش مصنوعی را در توضیحات PR افشا کنید. مشارکت‌کننده مسئول اعتبارسنجی است.

## مشارکت‌های دارای کمک هوش مصنوعی

Ultimate Performance با کمک هوش مصنوعی توسعه می‌یابد. از مشارکت‌کنندگانی که از ابزارهای هوش مصنوعی استفاده می‌کنند انتظار می‌رود همان استاندارد را رعایت کنند:

- رگرسیون را اجرا کنید. کد دارای کمک هوش مصنوعی که رگرسیون را رد می‌کند، منتشر نمی‌شود.
- کمک هوش مصنوعی را در توضیحات PR افشا کنید.
- مسئولیت اعتبارسنجی را بپذیرید — به بازبین تکیه نکنید که مسائلی را که رگرسیون باید گرفته بود، بگیرد.
- PRهای کوچک و متمرکز را ترجیح دهید. PRهای بزرگ تولیدشده با هوش مصنوعی بازبینی سخت‌ترند و احتمال بیشتری دارد مسائل ظریف داشته باشند.

کار بازبین تأیید ادعای اعتبارسنجی مشارکت‌کننده است، نه انجام دوبارهٔ اعتبارسنجی. یک PR که بدون اجرای رگرسیون می‌رسد، بازگردانده خواهد شد.

## همچنین ببینید

- [معماری](ARCHITECTURE.md) — جداسازی لایه، مدل تهدید.
- [عملیات](OPERATIONS.md) — استقرار تک‌گره / چندگره.
- [بنچمارک](benchmarks-fa.md) — دادهٔ کارایی تأییدشده.
- [CONTRIBUTING.md](../CONTRIBUTING.md) — گردش‌کار fork / branch / PR.
- [SECURITY.md](../SECURITY.md) — افشای مسئول.
