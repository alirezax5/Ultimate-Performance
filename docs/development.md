# Development

English | [فارسی](development-fa.md)

This document is for contributors. It covers repository setup, PHP
requirements, test execution, the most important audit suites, the
release philosophy, fresh-checkout validation, artifact build rules,
coding conventions, and how to contribute.

## Repository setup

```bash
git clone https://github.com/alirezax5/Ultimate-Performance.git
cd ultimate-performance
composer install        # installs php-amqplib (only runtime dependency)
```

The plugin ships with no Composer runtime dependencies beyond
`php-amqplib/php-amqplib ^3.6` (used only when RabbitMQ is configured).
The `vendor/` directory is committed so a WordPress install does not
need to run Composer.

## PHP requirements

- **Runtime:** PHP 8.3+. The plugin uses `readonly` properties,
  typed properties, named arguments, and other 8.x features.
- **Development / tests:** PHP 8.3+ with the extensions listed below.
  The Phase O benchmark and live gates were run on PHP 8.5.4-FPM; the
  matrix 8.2 / 8.3 / 8.4 is also green.

Required PHP extensions for full test coverage:

- `pdo`, `pdo_sqlite` (SQLite object cache backend)
- `redis` (Redis object cache backend — only if you test Redis)
- `memcached` (Memcached object cache backend — only if you test
  Memcached)
- `apcu` (APCu object cache backend; needs `apc.enable_cli=1` for CLI
  tests)
- `mbstring` (for `mb_strtolower` etc. — bundled with most PHP builds)
- `pcntl` (for the multi-process live gates)

The `tests/provision-php84.sh` script builds a user-local PHP 8.4 with
the required extensions; the `tests/provision-*.sh` family builds the
real daemons (MariaDB, Redis, Apache, Nginx, RabbitMQ, OLS).

## Test execution

The full regression runner:

```bash
bash tests/run-all-regression.sh R
```

The argument (`R` above) is a round label that appears in the per-suite
summary line. The runner:

1. Resets the WP-shim state (`tests/wp-shim/state/*.json`) and the cache
   tree between every suite, mirroring a fresh WordPress site.
2. Runs each audit suite under `timeout 590` (a 9-minute ceiling per
   suite).
3. Emits one summary line per suite: `<round> | <name> | pass=N | fail=N
   | skip/blocked=N | exit=<ec>`.
4. On any failure, prints the first 5 `[FAIL]` lines for that suite.

The 0.6.1 release shipped **48 suites / 1334 PASS / 0 FAIL / 15 honest
skips**. The skips are gated on missing real services (RabbitMQ without
credentials, MariaDB without the daemon, OLS without lsphp) and are
**never** counted as PASS — this is a release-blocking honesty contract.

### Important audit suites

| Suite | Purpose |
|---|---|
| `tests/audit-core.php` | The plugin's main behavior tests (98 checks). |
| `tests/audit-host-poison.php` | Host-header poisoning defenses. |
| `tests/audit-classifier.php` | Request classification — every bypass rule. |
| `tests/audit-sanitizer.php` | ResponseSanitizer patterns (85 checks). |
| `tests/audit-write-path.php` | Atomic write path + SafeFs containment. |
| `tests/audit-nginx-rules.php` | Nginx snippet generator (37 checks). |
| `tests/audit-apache-hit.php` | Apache `.htaccess` early-serve with execution counter. |
| `tests/audit-invalidation.php` | Tag-based invalidation, hook wiring. |
| `tests/audit-object-cache.php` | Object cache semantics (5 backends). |
| `tests/audit-oc-fencing.php` | Promotion fencing for recovered backends. |
| `tests/audit-multisite-uninstall.php` | Multisite uninstall removes 3 cluster tables, foreign tables survive. |
| `tests/audit-cluster.php` | Cluster event publish/consume/dedup. |
| `tests/audit-cluster-epoch.php` | Epoch gap detection + reconcile. |
| `tests/audit-herd.php` | BENCH-D7 / HARDEN-1: GenerationLock single-flight. |
| `tests/audit-homepage.php` | BENCH-D5 / HARDEN-2: ROOT_SENTINEL consistency. |
| `tests/audit-woo-sanitizer.php` | BENCH-D4 / HARDEN-3: semantic Woo sanitizer. |
| `tests/audit-stale-exposure.php` | BENCH-D6 / HARDEN-4: hybrid sync+async invalidation. |
| `tests/audit-fallback.php` | HARDEN-5: PHP fallback mode, drop-in ownership. |
| `tests/audit-env-detect.php` | HARDEN-6: EnvironmentDetector + self-test. |
| `tests/audit-shared-hosting.php` | HARDEN-7: shared-hosting compatibility (no-root). |
| `tests/audit-benchmark.php` | Performance benchmark (zero-PHP HIT invariant). |

### Live runners

For tests that require real daemons, the `tests/run-*-live.sh` family
self-provisions rootless daemons:

- `tests/run-mariadb-live.sh` — MariaDB 11.8.6, InnoDB, REPEATABLE-READ,
  FOR UPDATE row lock, 5000-event burst.
- `tests/run-redis-live.sh` — Redis 8.0.2.
- `tests/run-apache-live.sh` — Apache 2.4.68 + real WP.
- `tests/run-nginx-integration.sh` — Nginx 1.26.3 + `php -S` origin with
  execution counter proving zero PHP on HITs.
- `tests/run-rabbitmq-live.sh` — RabbitMQ 4.0.5 (rootless; credentials
  from env).
- `tests/run-openlitespeed-live.sh` — OpenLiteSpeed 1.9.2 + real LSAPI.
- `tests/run-cluster-live.sh` — two-node cluster on real MariaDB.
- `tests/run-multi-node-live.sh` — multi-node matrix.

Live runners self-provision on the host (no Docker / systemd). They
require `apt-get download` (Debian closure), a writable `$HOME/.local`,
and (for some) `pcntl_fork`. The Phase N `tests/provision-php84.sh`
script builds PHP itself with the required extensions.

## Release philosophy

> **No release is qualified only because the generated code looks
> correct.**

Every release must be qualified by a non-root regression run with zero
failures. The full `tests/run-all-regression.sh` harness must finish
`0 FAIL` before a tag is published. AI-assisted code that fails the
regression does not ship.

Release qualification gates (0.6.1):

- **Non-root regression:** 48 suites, 1334 PASS, 0 FAIL, 15 honest skips.
- **Fresh-checkout validation:** a fresh `git clone` of the release tag,
  followed by `bash tests/run-all-regression.sh`, must produce the same
  1334 / 0 / 15 result. The fresh-clone proof rules out hidden
  environment dependencies in the developer's working tree.
- **Live-qualified benchmark evidence:** the performance numbers in
  [docs/benchmarks.md](benchmarks.md) were measured on the development
  environment and are reproducible.
- **Fresh WP ZIP lifecycle PASS:** a release ZIP installed on a fresh
  WordPress 6.7.2 must activate cleanly, install the drop-ins, serve
  cached pages, and uninstall completely. The `tests/run-real-wp-live.sh`
  runner automates this.

The hard rule from `docs/ARCHITECTURE.md`:

> A safe public Page Cache HIT is `HTTP Request → Web Server → Static
> HTML → Browser` with **0** WordPress bootstrap, **0** PHP execution,
> **0** MySQL query, **0** Redis/Memcached/RabbitMQ request.

Every release re-verifies this with an origin-side execution counter on
the PHP-FPM pool.

## Fresh-checkout validation

To reproduce the 0.6.1 qualification:

```bash
git clone https://github.com/alirezax5/Ultimate-Performance.git
cd ultimate-performance
git checkout 0.6.1
composer install
bash tests/run-all-regression.sh R1
```

Expected: 48 suites, 1334 PASS, 0 FAIL, 15 honest skips. The fresh
checkout must match the developer's working tree exactly — if it does
not, the release is broken.

## Artifact build rules

The release ZIP is built from a clean checkout, excluding developer-only
artifacts:

```bash
git clone https://github.com/alirezax5/Ultimate-Performance.git uc-release
cd uc-release
git checkout 0.6.1
composer install --no-dev --optimize-autoloader
zip -r ../ultimate-performance-0.6.1.zip . \
    -x '.git/*' \
    -x 'tests/*' \
    -x 'docs/*' \
    -x 'download/*' \
    -x 'worklog.md' \
    -x 'IDE.md' \
    -x 'composer.lock' \
    -x '.gitignore'
```

The ZIP must contain only what WordPress needs at runtime:

- `ultimate-performance.php` (main plugin file)
- `uninstall.php`
- `src/**` (all plugin source)
- `vendor/**` (Composer dependencies)
- `readme.txt` (WordPress.org readme)
- `languages/ultimate-performance.pot`

The ZIP must **not** contain:

- `.git/` (version control)
- `tests/` (regression harness)
- `docs/` (developer documentation)
- `download/` (release artifacts)
- `worklog.md`, `IDE.md` (developer notes)
- `composer.lock` (regenerated on install)
- `.gitignore`

The 0.6.1 release ZIP is sealed — do not modify its contents. Any
change after release requires a new version tag.

## Coding conventions

- **PHP 8.3+ syntax**: readonly properties, typed properties, named
  arguments, constructor property promotion, match expressions.
- **Namespaces**: every class lives under `UltimatePerformance\<Subsystem>`,
  e.g. `UltimatePerformance\Core\Settings`,
  `UltimatePerformance\PageCache\Engine`. The autoloader
  (`src/Core/Autoloader.php`) maps `UltimatePerformance\A\B` to
  `src/A/B.php`.
- **Filesystem ops**: every FS op routes through `SafeFs`. No direct
  `file_put_contents`, `fopen`, `unlink`, `mkdir` outside `SafeFs`.
- **WordPress functions**: use WordPress functions where they exist
  (`wp_parse_url`, `wp_json_encode`, `wp_mkdir_p`, `wp_delete_file`,
  `wp_normalize_path`). The `FallbackServer` is the exception — it
  runs before `wp-includes` loads and uses only PHP built-ins + the
  plugin's own classes.
- **Failure mode**: fail-closed. If the plugin cannot confidently prove
  a response is safe for caching, BYPASS. If a backend is unreachable,
  fall through to the next backend. Never fatal.
- **Comments**: every non-trivial class has a docblock explaining the
  design contract and the priority order. Defect fixes reference the
  defect ID (`BENCH-D7`, `HARDEN-1`, etc.) and the audit suite that
  verifies them.
- **Indentation**: tabs (the WordPress coding standard). The
  `composer.json` does not ship a phpcs config; contributors should
  follow the WordPress standard informally.
- **Tests**: every new behavior must have an audit suite. Tests live in
  `tests/audit-<name>.php` and emit `[PASS]` / `[FAIL]` / `[SKIP]` /
  `[BLOCKED]` lines that the runner greps.

## How to contribute

See [CONTRIBUTING.md](../CONTRIBUTING.md) for the full guide. The
short version:

1. Fork the repository.
2. Create a branch: `git checkout -b feature/<short-description>`.
3. Make your changes. Add or update audit suites to cover them.
4. Run `bash tests/run-all-regression.sh R`. Keep `0 FAIL`.
5. Commit with a clear message. Reference any defect IDs.
6. Push and open a PR. Describe what changed, why, and how it was
   validated.
7. AI-assisted contributions are welcome — disclose AI assistance in
   the PR description. The contributor is responsible for validation.

## AI-assisted contributions

Ultimate Performance is developed with AI assistance. Contributors using AI
tools are expected to follow the same standard:

- Run the regression. AI-assisted code that fails the regression does
  not ship.
- Disclose AI assistance in the PR description.
- Take responsibility for the validation — do not rely on the reviewer
  to catch issues that the regression should have caught.
- Prefer small, focused PRs. Large AI-generated PRs are harder to
  review and more likely to contain subtle issues.

The reviewer's job is to verify the contributor's validation claim, not
to redo the validation. A PR that arrives without a regression run
will be sent back.

## See also

- [Architecture](ARCHITECTURE.md) — layer separation, threat model.
- [Operations](OPERATIONS.md) — single-node / multi-node deployment.
- [Benchmarks](benchmarks.md) — verified performance data.
- [CONTRIBUTING.md](../CONTRIBUTING.md) — fork / branch / PR workflow.
- [SECURITY.md](../SECURITY.md) — responsible disclosure.
