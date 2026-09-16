# Phase O Baseline

## Starting HEAD

```text
3f80e5cf3c75b719231868a3be3b69c2abfa7d9e
```

Phase N closure commit. Clean tree, `git fsck --full` clean.

## Phase N baseline reproduction

```bash
bash tests/run-all-regression.sh PHASE-O-BASE
```

Reproduced exactly:

```text
39 suites
1203 PASS
0 FAIL
15 honest skips
```

## Environment

| Component | Version | Source |
|-----------|---------|--------|
| OS | Debian 13 (trixie) sandbox | uname -r: 5.10.134-013.15.kangaroo.al8.x86_64 |
| User | `z` (uid 1001), no sudo | cannot install system packages via apt-get install |
| Existing PHP | none (re-provisioned) | tests/provision-php84.sh |
| Build tools | gcc 14, make, curl, dpkg | apt list --installed |
| Memcached closure | cached at ~/.cache/uc-provision/mc-root | tests/run-memcached-live.sh |
| APCu | ext-apcu 5.1.24 | built into uc-php84 wrapper |
| Memcached ext | ext-memcached 3.3.0 | built into uc-php84 wrapper |
| Phar + OpenSSL | enabled (rebuild for wp-cli) | added --enable-phar --with-openssl to provision-php84.sh |

## Services provisioned in O0

| Service | Version | Address | Binary path | Status |
|---------|---------|---------|-------------|--------|
| MariaDB | 11.8.6-MariaDB (Debian) | 127.0.0.1:13306 + /home/z/.cache/uc-provision/mariadb-root.sock | /home/z/.cache/uc-provision/mariadb-root/usr/sbin/mariadbd | PASS — InnoDB, REPEATABLE-READ, STRICT_TRANS_TABLES, utf8mb4_unicode_ci |
| Redis | 8.0.2 (Debian) | 127.0.0.1:16379 | /home/z/.cache/uc-provision/redis-root/usr/bin/redis-server | PASS — protected-mode off (loopback only), no persistence |
| Apache | 2.4.68 (Debian) | 127.0.0.1:18080 | /home/z/.cache/uc-provision/apache-root/usr/sbin/apache2 | PASS — minimal config, mod_rewrite + mod_headers loaded |
| Nginx | 1.26.3 (Debian) | 127.0.0.1:18081 | /home/z/.cache/uc-provision/nginx-root/usr/sbin/nginx | PASS — minimal config |
| OpenLiteSpeed | 1.7.19 Open | 127.0.0.1:8088 | /home/z/.cache/uc-provision/ols-root/bin/openlitespeed | PASS (from Phase N N4E) |
| Memcached | 1.6.38 (Debian) | 127.0.0.1:11311 + 11312 + .sock | /home/z/.cache/uc-provision/mc-root/usr/bin/memcached | PASS (from Phase N N4A) |
| APCu | 5.1.24 | in-process PHP ext | uc-php84 wrapper | PASS (from Phase N N4C) |
| WP-CLI | 2.x (phar) | /home/z/.local/bin/wp | wp-cli.phar | PASS — uses uc-php84 |
| PHP 8.2 | 8.2.29 | /home/z/.cache/uc-provision/php82-root/usr/bin/uc-php82 | PASS (from Phase N N4D) |
| PHP 8.3 | 8.3.16 | /home/z/.cache/uc-provision/php83-root/usr/bin/uc-php83 | PASS (from Phase N N4D) |
| PHP 8.4 | 8.4.11 | /home/z/.cache/uc-provision/php84-root/usr/bin/uc-php84 | PASS (from Phase N N4A) |

## Services BLOCKED in this sandbox

| Service | Reason | Honest disclosure |
|---------|--------|-------------------|
| RabbitMQ | erl wrapper script hard-codes `/usr/lib/erlang` path; user-space extraction cannot satisfy this without root symlink. Broker does not start. | BLOCKED — runner committed (tests/provision-rabbitmq.sh + tests/run-rabbitmq-live.sh) and would PASS in environments where erlang can run from a non-standard prefix (e.g. via HOME=/path override or system install). |
| LiteSpeed Enterprise | No commercial license available | PARTIAL allowed per Phase N §13 — OLS Open tested as the closest open-source sibling. |
| Real WordPress | No `wp` install in sandbox — wp-cli is available but needs a real WP install to test against. | Phase O O2 will provision a real WP install via wp-cli + MariaDB. |
| Real WooCommerce | Same as above — needs WP install first. | Phase O O2 will install WooCommerce. |
| Real Multisite | Same as above. | Phase O O2 will configure Multisite. |
| Real upgrade test | Needs real WP install. | Phase O O2 will use git-archive to test upgrade. |

## Provisioning strategy

All services use the same approach:

1. `apt-get download` the Debian trixie closure (one package at a time to handle t64 renames)
2. `dpkg -x` extract to a user-space prefix (`~/.cache/uc-provision/<service>-root/`)
3. Set `LD_LIBRARY_PATH` to the extraction's lib dirs
4. Start the daemon on a loopback high port (13306, 16379, 18080, 18081, 8088, 11311)
5. Wait for readiness using a REAL protocol-level probe (not just TCP port-accept)
6. Print credentials (where applicable) to stdout in `KEY=value` format for the caller to capture
7. PID-tracked teardown via `pkill -9 -f <binary-path>`

## Credential strategy

- MariaDB: per-run random root password (24 chars from /dev/urandom). NEVER committed.
- Redis: no auth (loopback only, protected-mode off).
- RabbitMQ: per-run user + password + vhost (would be generated IF the broker started).
- Apache/Nginx/OLS: no credentials (static HTTP serving on loopback).
- WP-CLI: no credentials (reads from wp-config.php at run time).

## Cleanup plan

Each provisioner has a teardown section that:

1. `pkill -9 -f <binary-path>` (PID-tracked, never broad `pkill -9 memcached` etc.)
2. Remove PID file, socket file, log file
3. For MariaDB: remove the per-run data directory (`mariadb-data/`)
4. Verify no stray processes remain via `pgrep`

The provision cache at `~/.cache/uc-provision/` is persistent across runs (re-creatable from `tests/provision-*.sh`). It contains NO credentials — only extracted binaries.

## Ports in use

| Port | Service | Loopback only |
|------|---------|---------------|
| 13306 | MariaDB | YES (127.0.0.1) |
| 16379 | Redis | YES (127.0.0.1) |
| 18080 | Apache | YES (127.0.0.1) |
| 18081 | Nginx | YES (127.0.0.1) |
| 8088 | OpenLiteSpeed | YES (127.0.0.1) |
| 11311 | Memcached TCP A | YES (127.0.0.1) |
| 11312 | Memcached TCP B | YES (127.0.0.1) |
| /home/z/.cache/uc-provision/mc-11311.sock | Memcached UNIX socket | YES (file perms 0700) |
| /home/z/.cache/uc-provision/mariadb-root.sock | MariaDB UNIX socket | YES (file perms 0755) |

## Temporary paths

All under `~/.cache/uc-provision/`:

- `php84-root/`, `php83-root/`, `php82-root/` — PHP builds
- `ext-root/` — deb-extracted build deps for PHP extensions
- `mc-root/` — memcached closure
- `mariadb-root/` — MariaDB closure
- `mariadb-data/` — per-run MariaDB data dir (ephemeral)
- `redis-root/` — Redis closure
- `apache-root/` — Apache closure
- `nginx-root/` — Nginx closure
- `rabbitmq-root/` — RabbitMQ closure (provisioned but broker does not start — see BLOCKED)
- `ols-root/` — OpenLiteSpeed source tree
- `*.pid`, `*.sock`, `*.log` — per-run daemon state

## Phase O milestone plan

```text
O0  baseline + provisioning            (this commit)
O1  MariaDB cluster closure            (real two-node WP cluster, runtime-only node, offline/lost-event, concurrent epoch, DB outage, 5000-event)
O2  real WP / Multisite / Woo / upgrade
O3  Redis / RabbitMQ / Apache / Nginx
O4  WP-CLI wiring
O5  OpenLiteSpeed + LSAPI + Rules
O6  performance / load / security
O7  final closure
```
