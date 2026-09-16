#!/bin/bash
# M2 — REAL WooCommerce matrix (live gate, self-provisioning).
#   woo1: WP 6.7.2 + WooCommerce 10.2.2 (newest combo available in this matrix)
#   woo2: WP 6.6.2 + WooCommerce  8.2.2 (previous combo; Woo 8.2 requires WP>=6.2)
#
# Real stack: same user-space MariaDB 11.8.6 closure as M1, real WP installs,
# real WooCommerce activation (real WC installer), real `wp wc` CLI surface
# with the --format=json contract, real page-cache lifecycle, release-blocking
# cross-session leakage canaries. NO mocks, NO shim.
#
# Credential policy identical to M1: per-run random wpuser password, destroyed
# at teardown, never printed; root only via the user-owned unix socket.
#
# Usage: bash tests/run-woo-live.sh <round>
set -u
cd "$(dirname "$0")/.."
PLUGIN_DIR="$(pwd)"
REPO_ROOT="$(cd "$PLUGIN_DIR/../.." && pwd)"
ROUND="${1:-M2-REAL-WOO}"
PHP="${PHP_BIN:-$HOME/.local/bin/php}"
[ -x "$PHP" ] || PHP="$(command -v php || true)"
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
[ -x "$PHP_WP" ] || wp_gate provision "PHP_WP binary missing at $PHP_WP (bulk static build, needs mysqli)"
"$PHP_WP" -m 2>/dev/null | grep -qi '^mysqli$' || wp_gate provision "mysqli not loadable in $PHP_WP"
[ -f "$WPCLI" ] || wp_gate provision "wp-cli.phar not found at $WPCLI"
[ -f "$TARBALLS/wordpress-6.7.2.tar.gz" ] || wp_gate provision "wordpress-6.7.2.tar.gz missing"
[ -f "$TARBALLS/wordpress-6.6.2.tar.gz" ] || wp_gate provision "wordpress-6.6.2.tar.gz missing"
[ -f "$TARBALLS/woocommerce-10.2.2.zip" ] || wp_gate provision "woocommerce-10.2.2.zip missing in $TARBALLS"
[ -f "$TARBALLS/woocommerce-8.2.2.zip" ] || wp_gate provision "woocommerce-8.2.2.zip missing in $TARBALLS"

# --- 1. MariaDB (same cached closure as M1) ------------------------------------
MDB_LIBS="$MDB_ROOT/usr/lib/x86_64-linux-gnu:$MDB_ROOT/lib/x86_64-linux-gnu"
[ -x "$MDB_ROOT/usr/sbin/mariadbd" ] || wp_gate provision "MariaDB closure missing at $MDB_ROOT (run tests/run-real-wp-live.sh once first)"

RUNNER_OWNS_DB=0
if [ ! -d "$MDB_DATA/mysql" ]; then
  mkdir -p "$MDB_DATA" "$MDB_RUN"
  LD_LIBRARY_PATH="$MDB_LIBS" "$MDB_ROOT/usr/bin/mariadb-install-db" --no-defaults \
    --basedir="$MDB_ROOT/usr" --datadir="$MDB_DATA" \
    --auth-root-authentication-method=normal --skip-test-db --skip-name-resolve \
    > "$CACHE/mdb-install.log" 2>&1 || wp_fail provision "mariadb-install-db failed"
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

dsql() {
  LD_LIBRARY_PATH="$MDB_LIBS" "$MDB_ROOT/usr/bin/mariadb" --no-defaults \
    --socket="$SOCK" -u root -N -B -e "$1"
}

# --- 2. per-run credentials + databases ----------------------------------------
DB_PASS="$($PHP_WP -r 'echo bin2hex(random_bytes(12));')"
ADMIN_PASS="$($PHP_WP -r 'echo bin2hex(random_bytes(12));')"
mkdir -p "$RUN"
umask 077
printf 'UC_REALWP_DBPASS=%s\nUC_REALWP_ADMINPASS=%s\n' "$DB_PASS" "$ADMIN_PASS" > "$RUN/wp-db.env"
. "$RUN/wp-db.env"

dsql "DROP DATABASE IF EXISTS woo1; DROP DATABASE IF EXISTS woo2;
CREATE DATABASE woo1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;
CREATE DATABASE woo2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;
DROP USER IF EXISTS 'wpuser'@'127.0.0.1';
CREATE USER 'wpuser'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON woo1.* TO 'wpuser'@'127.0.0.1';
GRANT ALL PRIVILEGES ON woo2.* TO 'wpuser'@'127.0.0.1';
FLUSH PRIVILEGES;" || wp_fail provision "database bootstrap SQL failed"

# --- 3. environment specs -------------------------------------------------------
# name | wp-version | woo-zip | woo-version | url | db
SPECS=(
  "woo1|6.7.2|woocommerce-10.2.2.zip|10.2.2|http://woo1.test|woo1"
  "woo2|6.6.2|woocommerce-8.2.2.zip|8.2.2|http://woo2.test|woo2"
)

provision_env() {
  local name="$1" wpver="$2" woozip="$3" woover="$4" url="$5" db="$6"
  local docroot="$RUN/$name/wordpress"
  rm -rf "$RUN/$name"
  mkdir -p "$RUN/$name"
  tar -xzf "$TARBALLS/wordpress-$wpver.tar.gz" -C "$RUN/$name" || return 1
  [ -f "$docroot/wp-load.php" ] || return 1

  rsync -a --delete \
    --exclude 'tests/' --exclude 'docs/' --exclude 'download/' \
    "$PLUGIN_DIR/" "$docroot/wp-content/plugins/ultimate-performance/" || return 1
  unzip -q -o "$TARBALLS/$woozip" -d "$docroot/wp-content/plugins/" || return 1
  [ -f "$docroot/wp-content/plugins/woocommerce/woocommerce.php" ] || return 1

  "$PHP_WP" "$WPCLI" --path="$docroot" config create \
    --dbname="$db" --dbuser='wpuser' --dbpass="$DB_PASS" \
    --dbhost="127.0.0.1:$PORT" --skip-check > /dev/null 2>&1 || return 1

  "$PHP_WP" "$WPCLI" --path="$docroot" core install \
    --url="$url" --title="UC M2 $name" --admin_user='ucadmin' \
    --admin_password="$ADMIN_PASS" --admin_email="admin@$name.test" \
    --skip-email > /dev/null 2>&1 || return 1

  # Pretty permalinks (production reality). With WP plain permalinks every
  # Woo URL is a query string (?page_id=/?product=) and the keygen's unknown-
  # query policy bypasses ALL of them BY DESIGN (no cache explosion on query
  # strings). Pretty permalinks give the real path-based shop URLs.
  "$PHP_WP" "$WPCLI" --path="$docroot" rewrite structure '/%postname%/' > /dev/null 2>&1 || return 1
  "$PHP_WP" "$WPCLI" --path="$docroot" rewrite flush > /dev/null 2>&1 || return 1

  "$PHP_WP" "$WPCLI" --path="$docroot" eval '
    $u = get_user_by( "login", "ucadmin" );
    wp_set_current_user( $u ? $u->ID : 0 );
    require_once ABSPATH . "wp-admin/includes/plugin.php";
    $r1 = activate_plugin( "woocommerce/woocommerce.php" );
    if ( is_wp_error( $r1 ) ) { fwrite( STDERR, "woo: " . $r1->get_error_message() ); exit( 1 ); }
    $r2 = activate_plugin( "ultimate-performance/ultimate-performance.php" );
    if ( is_wp_error( $r2 ) ) { fwrite( STDERR, "uc: " . $r2->get_error_message() ); exit( 1 ); }
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
  return 0
}

# --- 4. run the per-env audit suite ---------------------------------------------
TOTAL_PASS=0; TOTAL_FAIL=0
for spec in "${SPECS[@]}"; do
  IFS='|' read -r name wpver woozip woover url db <<< "$spec"
  if ! provision_env "$name" "$wpver" "$woozip" "$woover" "$url" "$db"; then
    echo "$ROUND | provision-$name | FAIL (provisioning step failed)"
    TOTAL_FAIL=$((TOTAL_FAIL+1))
    continue
  fi
  echo "$ROUND | provision-$name | ok (WP $wpver + Woo $woover, MariaDB 11.8.6)"
  out=$(UC_REALWP_DIR="$RUN/$name/wordpress" \
        UC_REALWP_URL="$url" \
        UC_REALWP_WOO="$woover" \
        UC_REALWP_PHP="$PHP_WP" \
        UC_REALWP_WPCLI="$WPCLI" \
        UC_REALWP_WORKER="$PLUGIN_DIR/tests/lib/real-wp-pagecache-worker.php" \
        timeout 280 "$PHP_WP" -d display_errors=0 -d log_errors=1 tests/audit-real-woo.php 2>&1)
  ec=$?
  pass=$(echo "$out" | grep -cE '^\[PASS\]')
  fail=$(echo "$out" | grep -cE '^\[FAIL\]')
  skip=$(echo "$out" | grep -cE '^\[SKIP\]')
  echo "$ROUND | audit-real-woo-$name | pass=$pass | fail=$fail | skip=$skip | exit=$ec"
  if [ "$fail" -gt 0 ] || [ "$ec" -ne 0 ]; then
    echo "$out" | grep -E '^\[FAIL\]' | head -10
    echo "--- suite tail ($name) ---"
    echo "$out" | tail -12
    TOTAL_FAIL=$((TOTAL_FAIL+fail+1))
  else
    TOTAL_PASS=$((TOTAL_PASS+pass))
  fi
done

# --- 5. teardown -----------------------------------------------------------------
dsql "DROP USER IF EXISTS 'wpuser'@'127.0.0.1'; FLUSH PRIVILEGES;" > /dev/null 2>&1
rm -f "$RUN/wp-db.env"

if [ "$RUNNER_OWNS_DB" = "1" ]; then
  PID=$(cat "$CACHE/mdb-server.pid" 2>/dev/null || true)
  [ -n "$PID" ] && kill "$PID" 2>/dev/null
  for i in $(seq 1 15); do db_alive || break; sleep 1; done
  echo "$ROUND | teardown | runner-owned mariadbd stopped"
fi

STRAYS=$(pgrep -f 'real-wp-pagecache-worker' | wc -l)
[ "$STRAYS" -gt 0 ] && echo "$ROUND | teardown | WARN: $STRAYS stray worker processes" \
  || echo "$ROUND | teardown | 0 stray worker processes; DB credentials destroyed"

echo "$ROUND | summary | pass=$TOTAL_PASS | fail=$TOTAL_FAIL"
[ "$TOTAL_FAIL" -eq 0 ]
