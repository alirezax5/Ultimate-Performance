#!/bin/bash
# M1 — REAL WordPress three-version release matrix (live gate, self-provisioning).
# Provisioned environments (independent docroots + independent databases):
#   wp609    WP 6.0.9  (oldest) — subdirectory multisite (release-blocking canaries)
#   wp662    WP 6.6.2  (middle) — single site
#   wp672    WP 6.7.2  (newest) — subdirectory multisite (release-blocking canaries)
#   wp672sub WP 6.7.2           — subdomain multisite (host-isolation canaries)
#
# Real stack: MariaDB 11.8.6 extracted from the Debian 13 package closure into
# user space (cached), real wp-load boots, real wp-cli operations, real plugin
# activation via core activate_plugin(), real drop-in, real WP main loop
# workers for the page-cache lifecycle. NO mocks, NO shim.
#
# Credential policy: a per-run random DB password is generated, injected into
# the runtime wp-config.php files (outside the repository) and DESTROYED at
# teardown (wpuser dropped, runtime env file deleted, never printed).
# The MariaDB root account is empty-password, reachable ONLY via the user-owned
# unix socket inside the cache directory (documented in PHASE-M final report).
#
# Usage: bash tests/run-real-wp-live.sh <round>
set -u
cd "$(dirname "$0")/.."
PLUGIN_DIR="$(pwd)"
REPO_ROOT="$(cd "$PLUGIN_DIR/../.." && pwd)"
ROUND="${1:-M1-REAL-WP}"
PHP="${PHP_BIN:-$HOME/.local/bin/php}"
[ -x "$PHP" ] || PHP="$(command -v php || true)"
# The real-WP matrix runs on the bulk static build (PHP 8.4.23, ~90 exts incl.
# mysqli) because WordPress core CANNOT connect to MySQL via pdo_mysql alone
# (wp-db.php: no mysqli ext → falls back to removed mysql_* functions).
# The regression platform keeps PHP 8.3.27 (same build as all prior phases).
PHP_WP="${UC_REALWP_PHP_BIN:-/home/z/opt/php84/php}"
WPCLI="${UC_WPCLI:-$REPO_ROOT/wp-cli.phar}"
TARBALLS="${UC_WP_TARBALLS_DIR:-$REPO_ROOT}"
CACHE="${UC_PROVISION_CACHE:-$HOME/.cache/uc-provision}"
RUN="${UC_REALWP_RUNTIME:-/home/z/opt/wp-matrix}"
MDB_ROOT="$CACHE/mdb-root"
MDB_DATA="$CACHE/mdb-data"
MDB_RUN="$CACHE/mdb-run"
SOCK="$MDB_RUN/mysqld.sock"
PORT="${UC_REALWP_DBPORT:-3307}"

wp_fail() { echo "$ROUND | $1 | FAIL ($2)"; exit 1; }
wp_gate() { echo "$ROUND | $1 | SKIP/BLOCKED ($2)"; exit 0; }

# --- 0. tool gates ------------------------------------------------------------
[ -x "$PHP" ] || wp_gate provision "no PHP binary"
[ -x "$PHP_WP" ] || wp_gate provision "PHP_WP binary missing at $PHP_WP (bulk static build, needs mysqli)"
"$PHP_WP" -m 2>/dev/null | grep -qi '^mysqli$' || wp_gate provision "mysqli not loadable in $PHP_WP"
[ -f "$WPCLI" ] || wp_gate provision "wp-cli.phar not found at $WPCLI"
for v in 6.0.9 6.6.2 6.7.2; do
  [ -f "$TARBALLS/wordpress-$v.tar.gz" ] || wp_gate provision "wordpress-$v.tar.gz missing in $TARBALLS"
done

# --- 1. MariaDB provisioning (cached; Debian 13 closure, same host distro) ----
MDB_DEBS=(
  "https://deb.debian.org/debian/pool/main/m/mariadb/mariadb-server-core_11.8.6-0+deb13u1_amd64.deb"
  "https://deb.debian.org/debian/pool/main/m/mariadb/mariadb-server_11.8.6-0+deb13u1_amd64.deb"
  "https://deb.debian.org/debian/pool/main/m/mariadb/mariadb-client-core_11.8.6-0+deb13u1_amd64.deb"
  "https://deb.debian.org/debian/pool/main/m/mariadb/mariadb-client_11.8.6-0+deb13u1_amd64.deb"
  "https://deb.debian.org/debian/pool/main/m/mariadb/libmariadb3_11.8.6-0+deb13u1_amd64.deb"
  "https://deb.debian.org/debian/pool/main/liba/libaio/libaio1t64_0.3.113-8+b1_amd64.deb"
  "https://deb.debian.org/debian/pool/main/libu/liburing/liburing2_2.9-1_amd64.deb"
  "https://deb.debian.org/debian/pool/main/n/ncurses/libncurses6_6.5%2B20250216-2_amd64.deb"
  "https://deb.debian.org/debian/pool/main/n/ncurses/libtinfo6_6.5%2B20250216-2_amd64.deb"
)
if [ ! -x "$MDB_ROOT/usr/sbin/mariadbd" ]; then
  echo "$ROUND | provision: extracting MariaDB 11.8.6 (Debian closure, first run only)"
  mkdir -p "$MDB_ROOT" "$CACHE/debs-mdb"
  ( cd "$CACHE/debs-mdb"
    for u in "${MDB_DEBS[@]}"; do
      f="$(basename "${u//%2B/+}")"
      [ -s "$f" ] || curl -sS --retry 2 --max-time 240 -o "$f" "$u" || wp_fail provision "deb download failed: $u"
    done
    for d in *.deb; do [ -e "$d" ] && dpkg -x "$d" "$MDB_ROOT"; done
  )
  [ -x "$MDB_ROOT/usr/sbin/mariadbd" ] || wp_fail provision "mariadbd not extracted"
fi
MDB_LIBS="$MDB_ROOT/usr/lib/x86_64-linux-gnu:$MDB_ROOT/lib/x86_64-linux-gnu"

# --- 2. datadir + server (runner-owned if started here) -----------------------
RUNNER_OWNS_DB=0
if [ ! -f "$MDB_DATA/mysql/aria_log_control" ] && [ ! -d "$MDB_DATA/mysql" ]; then
  echo "$ROUND | provision: mariadb-install-db (first run only)"
  mkdir -p "$MDB_DATA" "$MDB_RUN"
  LD_LIBRARY_PATH="$MDB_LIBS" "$MDB_ROOT/usr/bin/mariadb-install-db" --no-defaults \
    --basedir="$MDB_ROOT/usr" --datadir="$MDB_DATA" \
    --auth-root-authentication-method=normal --skip-test-db --skip-name-resolve \
    > "$CACHE/mdb-install.log" 2>&1 || wp_fail provision "mariadb-install-db failed (see $CACHE/mdb-install.log)"
fi

db_alive() {
  [ -S "$SOCK" ] || return 1
  LD_LIBRARY_PATH="$MDB_LIBS" "$MDB_ROOT/usr/bin/mariadb-admin" --no-defaults \
    --socket="$SOCK" -u root ping > /dev/null 2>&1
}

if ! db_alive; then
  echo "$ROUND | provision: starting mariadbd (127.0.0.1:$PORT + user-owned socket)"
  LD_LIBRARY_PATH="$MDB_LIBS" "$MDB_ROOT/usr/sbin/mariadbd" --no-defaults \
    --basedir="$MDB_ROOT/usr" --datadir="$MDB_DATA" --socket="$SOCK" \
    --port="$PORT" --bind-address=127.0.0.1 --skip-name-resolve \
    --tmpdir="$MDB_RUN" > "$CACHE/mdb-server.log" 2>&1 &
  echo $! > "$CACHE/mdb-server.pid"
  RUNNER_OWNS_DB=1
  for i in $(seq 1 30); do db_alive && break; sleep 1; done
  db_alive || wp_fail provision "mariadbd did not become ready (see $CACHE/mdb-server.log)"
fi

# SQL helper: root via the user-owned socket (empty password, no TCP).
dsql() {
  LD_LIBRARY_PATH="$MDB_LIBS" "$MDB_ROOT/usr/bin/mariadb" --no-defaults \
    --socket="$SOCK" -u root -N -B -e "$1"
}

# --- 3. per-run credentials + databases (fresh every run) ----------------------
DB_PASS="$("$PHP_WP" -r 'echo bin2hex(random_bytes(12));')"
ADMIN_PASS="$("$PHP_WP" -r 'echo bin2hex(random_bytes(12));')"
mkdir -p "$RUN"
umask 077
printf 'UC_REALWP_DBPASS=%s\nUC_REALWP_ADMINPASS=%s\n' "$DB_PASS" "$ADMIN_PASS" > "$RUN/wp-db.env"
# never printed; only sourced into this shell
. "$RUN/wp-db.env"

dsql "DROP DATABASE IF EXISTS wp609; DROP DATABASE IF EXISTS wp662; DROP DATABASE IF EXISTS wp672; DROP DATABASE IF EXISTS wp672sub;
CREATE DATABASE wp609 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;
CREATE DATABASE wp662 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;
CREATE DATABASE wp672 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;
CREATE DATABASE wp672sub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;
DROP USER IF EXISTS 'wpuser'@'127.0.0.1';
CREATE USER 'wpuser'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON wp609.* TO 'wpuser'@'127.0.0.1';
GRANT ALL PRIVILEGES ON wp662.* TO 'wpuser'@'127.0.0.1';
GRANT ALL PRIVILEGES ON wp672.* TO 'wpuser'@'127.0.0.1';
GRANT ALL PRIVILEGES ON wp672sub.* TO 'wpuser'@'127.0.0.1';
FLUSH PRIVILEGES;" || wp_fail provision "database bootstrap SQL failed"

# --- 4. environment specs ------------------------------------------------------
# name | version | url | db | multisite-mode
SPECS=(
  "wp609|6.0.9|http://wp609.test|wp609|subdir"
  "wp662|6.6.2|http://wp662.test|wp662|"
  "wp672|6.7.2|http://wp672.test|wp672|subdir"
  "wp672sub|6.7.2|http://wp672sub.test|wp672sub|subdomain"
)

provision_env() {
  local name="$1" ver="$2" url="$3" db="$4" ms="$5"
  local docroot="$RUN/$name/wordpress"
  rm -rf "$RUN/$name"
  mkdir -p "$RUN/$name"
  tar -xzf "$TARBALLS/wordpress-$ver.tar.gz" -C "$RUN/$name" || return 1
  [ -f "$docroot/wp-load.php" ] || return 1

  rsync -a --delete \
    --exclude 'tests/' --exclude 'docs/' --exclude 'download/' \
    "$PLUGIN_DIR/" "$docroot/wp-content/plugins/ultimate-performance/" || return 1

  "$PHP_WP" "$WPCLI" --path="$docroot" config create \
    --dbname="$db" --dbuser='wpuser' --dbpass="$DB_PASS" \
    --dbhost="127.0.0.1:$PORT" --skip-check > /dev/null 2>&1 || return 1

  "$PHP_WP" "$WPCLI" --path="$docroot" core install \
    --url="$url" --title="UC M1 $name" --admin_user='ucadmin' \
    --admin_password="$ADMIN_PASS" --admin_email="admin@$name.test" \
    --skip-email > /dev/null 2>&1 || return 1

  # REAL activation path: core activate_plugin() as a real administrator user.
  "$PHP_WP" "$WPCLI" --path="$docroot" eval '
    $u = get_user_by( "login", "ucadmin" );
    wp_set_current_user( $u ? $u->ID : 0 );
    require_once ABSPATH . "wp-admin/includes/plugin.php";
    $r = activate_plugin( "ultimate-performance/ultimate-performance.php" );
    if ( is_wp_error( $r ) ) { fwrite( STDERR, $r->get_error_message() ); exit( 1 ); }
  ' > /dev/null 2>&1 || return 1

  "$PHP_WP" "$WPCLI" --path="$docroot" eval '
    update_option( "ultimate_performance_settings", array_merge(
      (array) get_option( "ultimate_performance_settings", array() ),
      array( "enabled" => true, "page_cache_enabled" => true )
    ) );
  ' > /dev/null 2>&1 || return 1

  "$PHP_WP" "$WPCLI" --path="$docroot" eval '
    ( new \UltimatePerformance\ObjectCache\Dropin() )->ensure( WP_CONTENT_DIR );
  ' > /dev/null 2>&1 || return 1

  if [ "$ms" = "subdir" ]; then
    "$PHP_WP" "$WPCLI" --path="$docroot" core multisite-convert > /dev/null 2>&1 || return 1
    "$PHP_WP" "$WPCLI" --path="$docroot" site create --slug='site2' > /dev/null 2>&1 || return 1
  elif [ "$ms" = "subdomain" ]; then
    # single install already done; convert AND flip to subdomain constants
    "$PHP_WP" "$WPCLI" --path="$docroot" core multisite-convert > /dev/null 2>&1 || return 1
    "$PHP_WP" "$WPCLI" --path="$docroot" config set SUBDOMAIN_INSTALL true --raw > /dev/null 2>&1 || return 1
    "$PHP_WP" "$WPCLI" --path="$docroot" site create --slug='site2' > /dev/null 2>&1 || return 1
  fi
  return 0
}

# --- 5. run the per-env audit suite ---------------------------------------------
TOTAL_PASS=0; TOTAL_FAIL=0
for spec in "${SPECS[@]}"; do
  IFS='|' read -r name ver url db ms <<< "$spec"
  if ! provision_env "$name" "$ver" "$url" "$db" "$ms"; then
    echo "$ROUND | provision-$name | FAIL (provisioning step failed)"
    TOTAL_FAIL=$((TOTAL_FAIL+1))
    continue
  fi
  echo "$ROUND | provision-$name | ok (WP $ver, ms=${ms:-single}, MariaDB 11.8.6)"
  out=$(UC_REALWP_DIR="$RUN/$name/wordpress" \
        UC_REALWP_URL="$url" \
        UC_REALWP_VERSION="$ver" \
        UC_REALWP_MULTISITE="$ms" \
        UC_REALWP_PHP="$PHP_WP" \
        UC_REALWP_WORKER="$PLUGIN_DIR/tests/lib/real-wp-pagecache-worker.php" \
        timeout 280 "$PHP_WP" -d display_errors=0 -d log_errors=1 tests/audit-real-wp.php 2>&1)
  ec=$?
  pass=$(echo "$out" | grep -cE '^\[PASS\]')
  fail=$(echo "$out" | grep -cE '^\[FAIL\]')
  skip=$(echo "$out" | grep -cE '^\[SKIP\]')
  echo "$ROUND | audit-real-wp-$name | pass=$pass | fail=$fail | skip=$skip | exit=$ec"
  if [ "$fail" -gt 0 ] || [ "$ec" -ne 0 ]; then
    echo "$out" | grep -E '^\[FAIL\]' | head -10
    echo "--- suite tail ($name) ---"
    echo "$out" | tail -12
    TOTAL_FAIL=$((TOTAL_FAIL+fail+1))
  else
    TOTAL_PASS=$((TOTAL_PASS+pass))
  fi
done

# --- 6. teardown: credential destruction + process hygiene -----------------------
dsql "DROP USER IF EXISTS 'wpuser'@'127.0.0.1'; FLUSH PRIVILEGES;" > /dev/null 2>&1
rm -f "$RUN/wp-db.env"

if [ "$RUNNER_OWNS_DB" = "1" ]; then
  PID=$(cat "$CACHE/mdb-server.pid" 2>/dev/null || true)
  [ -n "$PID" ] && kill "$PID" 2>/dev/null
  for i in $(seq 1 15); do db_alive || break; sleep 1; done
  echo "$ROUND | teardown | runner-owned mariadbd stopped"
fi

STRAYS=$(pgrep -f 'real-wp-pagecache-worker' | wc -l)
if [ "$STRAYS" -gt 0 ]; then
  echo "$ROUND | teardown | WARN: $STRAYS stray worker processes"
  pgrep -af 'real-wp-pagecache-worker' | head -3
else
  echo "$ROUND | teardown | 0 stray worker processes; DB credentials destroyed (wpuser dropped, env file removed)"
fi

echo "$ROUND | summary | pass=$TOTAL_PASS | fail=$TOTAL_FAIL"
[ "$TOTAL_FAIL" -eq 0 ]
