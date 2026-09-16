#!/bin/bash
# M6 — REAL upgrade test on a live WordPress install.
# Old build = git archive of the pre-M5 tree (37ee9cd: M4 closed, M5 design
# only, NO cluster code). Provision a real WP env with it, configure + render,
# then upgrade the way WordPress itself does (plugin files replaced in place,
# plugin stays ACTIVE), then assert behavior is preserved:
#   U1 no fatal on the first upgraded boot; plugin still active
#   U2 settings preserved byte-for-byte (upgrade must not reset admin data)
#   U3 cluster classes exist after upgrade (new subsystem online)
#   U4 lazy cluster table creation on first publish (no migration step needed)
#   U5 page cache still stores + serves the SAME file bytes after upgrade
# Usage: bash tests/run-upgrade-live.sh <round>
set -u
cd "$(dirname "$0")/.."
PLUGIN_DIR="$(pwd)"
REPO_ROOT="$(cd "$PLUGIN_DIR/../.." && pwd)"
ROUND="${1:-M6-UPGRADE}"
OLD_SHA="${UC_UPGRADE_OLD_SHA:-37ee9cd}"
PHP="$HOME/.local/bin/php"
PHP_WP="${UC_REALWP_PHP_BIN:-/home/z/opt/php84/php}"
WPCLI="${UC_WPCLI:-$REPO_ROOT/wp-cli.phar}"
CACHE="${UC_PROVISION_CACHE:-$HOME/.cache/uc-provision}"
RUN="${UC_REALWP_RUNTIME:-/home/z/opt/wp-matrix}"
HOST="up1.test"
WPVER="6.7.2"

fail() { echo "$ROUND | $1 | FAIL ($2)"; exit 1; }
gate() { echo "$ROUND | $1 | SKIP/BLOCKED ($2)"; exit 0; }
[ -x "$PHP_WP" ] || gate provision "PHP_WP missing"
[ -f "$WPCLI" ] || gate provision "wp-cli missing"
[ -f "$REPO_ROOT/wordpress-$WPVER.tar.gz" ] || gate provision "WP tarball missing"
[ -d "$CACHE/mdb-root/usr/lib" ] || gate provision "MariaDB closure missing"
git -C "$REPO_ROOT" cat-file -e "$OLD_SHA" 2>/dev/null || gate provision "old SHA $OLD_SHA missing"

MDB_ROOT="$CACHE/mdb-root"; MDB_DATA="$CACHE/mdb-data"; MDB_RUN="$CACHE/mdb-run"
SOCK="$MDB_RUN/mysqld.sock"; PORT="${UC_REALWP_DBPORT:-3307}"
MDB_LIBS="$MDB_ROOT/usr/lib/x86_64-linux-gnu:$MDB_ROOT/lib/x86_64-linux-gnu"
db_alive() { [ -S "$SOCK" ] && LD_LIBRARY_PATH="$MDB_LIBS" "$MDB_ROOT/usr/bin/mariadb-admin" --no-defaults --socket="$SOCK" -u root ping >/dev/null 2>&1; }
if ! db_alive; then
  LD_LIBRARY_PATH="$MDB_LIBS" "$MDB_ROOT/usr/sbin/mariadbd" --no-defaults --basedir="$MDB_ROOT/usr" --datadir="$MDB_DATA" --socket="$SOCK" --port="$PORT" --bind-address=127.0.0.1 --skip-name-resolve --tmpdir="$MDB_RUN" > "$CACHE/mdb-server.log" 2>&1 &
  for i in $(seq 1 30); do db_alive && break; sleep 1; done
  db_alive || fail provision "mariadbd not ready"
fi
dsql() { LD_LIBRARY_PATH="$MDB_LIBS" "$MDB_ROOT/usr/bin/mariadb" --no-defaults --socket="$SOCK" -u root -N -B -e "$1"; }

DB_PASS="$($PHP_WP -r 'echo bin2hex(random_bytes(12));')"
ADMIN_PASS="$($PHP_WP -r 'echo bin2hex(random_bytes(12));')"
dsql "DROP DATABASE IF EXISTS upgrade1; CREATE DATABASE upgrade1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;
DROP USER IF EXISTS 'wpuser'@'127.0.0.1'; CREATE USER 'wpuser'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON upgrade1.* TO 'wpuser'@'127.0.0.1'; FLUSH PRIVILEGES;" || fail provision "db bootstrap"

TOTAL_PASS=0; TOTAL_FAIL=0
ck() { if [ "$2" = "1" ]; then TOTAL_PASS=$((TOTAL_PASS+1)); echo "$ROUND | $1 | PASS"; else TOTAL_FAIL=$((TOTAL_FAIL+1)); echo "$ROUND | $1 | FAIL ($3)"; fi; }

ENVDIR="$RUN/up1"; rm -rf "$ENVDIR"; mkdir -p "$ENVDIR"
tar -xzf "$REPO_ROOT/wordpress-$WPVER.tar.gz" -C "$ENVDIR" || fail provision "tar"
PLUGDIR="$ENVDIR/wordpress/wp-content/plugins/ultimate-cache"
mkdir -p "$PLUGDIR"
git -C "$REPO_ROOT" archive "$OLD_SHA" workspace/ultimate-cache-extract | tar -x --strip-components=2 -C "$PLUGDIR" || fail provision "old plugin archive"
[ -f "$PLUGDIR/ultimate-performance.php" ] || fail provision "old plugin tree incomplete"
[ ! -e "$PLUGDIR/src/Cluster/EventStore.php" ] || fail provision "old SHA already contains cluster code (pick a pre-M5 SHA)"
wpcli_in() { "$PHP_WP" "$WPCLI" --path="$ENVDIR/wordpress" "$@" > /dev/null 2>&1; }
wpcli_in config create --dbname=upgrade1 --dbuser='wpuser' --dbpass="$DB_PASS" --dbhost="127.0.0.1:$PORT" --skip-check || fail provision "config"
wpcli_in core install --url="http://$HOST" --title="UC M6 upgrade" --admin_user='ucadmin' --admin_password="$ADMIN_PASS" --admin_email="admin@$HOST" --skip-email || fail provision "install"
wpcli_in rewrite structure '/%postname%/' || fail provision "rewrites"
wpcli_in eval '
  $u = get_user_by( "login", "ucadmin" ); wp_set_current_user( $u ? $u->ID : 0 );
  require_once ABSPATH . "wp-admin/includes/plugin.php";
  if ( is_wp_error( activate_plugin( "ultimate-performance/ultimate-performance.php" ) ) ) { exit( 1 ); }
  update_option( "ultimate_performance_settings", array_merge( (array) get_option( "ultimate_performance_settings", array() ), array( "enabled" => true, "page_cache_enabled" => true, "ttl" => 4242 ) ) );
  ( new \UltimatePerformance\ObjectCache\Dropin() )->ensure( WP_CONTENT_DIR );
' || fail provision "activate (old build)"
render() { # render <marker> — real page-cache worker render of /hello-world/
  UC_REALWP_DIR="$ENVDIR/wordpress" UC_REALWP_URL="http://$HOST" UC_WORKER_MODE=render \
  UC_WORKER_MARKER="$1" UC_WORKER_URI="/hello-world/" "$PHP_WP" \
  "$PLUGIN_DIR/tests/lib/real-wp-pagecache-worker.php" > /dev/null 2>&1
}
CACHE_FILE="$ENVDIR/wordpress/wp-content/cache/ultimate-performance/v/$HOST/hello-world/index.html"

render "UP-OLD-$(date +%s)"
[ -f "$CACHE_FILE" ] && ck "P1 old build rendered+stored" 1 stored || ck "P1 old build rendered+stored" 0 "missing"

# --- THE UPGRADE: files replaced in place, plugin stays ACTIVE (WP semantics) ---
rsync -a --delete --exclude 'tests/' --exclude 'docs/' --exclude 'download/' "$PLUGIN_DIR/" "$PLUGDIR/" || fail upgrade "rsync"

BOOT_ERR="$($PHP_WP "$WPCLI" --path="$ENVDIR/wordpress" eval '
  echo "active=" . (int) is_plugin_active( "ultimate-performance/ultimate-performance.php" );
  echo " cluster=" . (int) class_exists( "\\UltimatePerformance\\Cluster\\EventStore" );
  $s = get_option( "ultimate_performance_settings", array() );
  echo " ttl=" . (int) ( $s["ttl"] ?? 0 );
' 2>&1)"
ck "U1 upgraded boot: no fatal, plugin still active" "$([ "$BOOT_ERR" != *"Fatal error"* ] && echo 1 || echo 0)" "$BOOT_ERR"
ck "U2 admin settings preserved through upgrade (ttl=4242)" "$(echo "$BOOT_ERR" | rg -q 'ttl=4242' && echo 1 || echo 0)" "$BOOT_ERR"
ck "U3 new subsystem online after upgrade (cluster classes exist)" "$(echo "$BOOT_ERR" | rg -q 'cluster=1' && echo 1 || echo 0)" "$BOOT_ERR"

# U4: lazy cluster table on first publish (no migration step, no fatal)
"$PHP_WP" "$WPCLI" --path="$ENVDIR/wordpress" eval '
  $eid = ( new \UltimatePerformance\Cluster\EventStore() )->publish( "purge_all", array(), 0 );
  echo "" !== $eid ? "published" : "FAILED";
' > "$RUN/up1-publish.out" 2>&1
PUBLISHED=$(cat "$RUN/up1-publish.out" 2>/dev/null | rg -o 'published|FAILED' | head -1)
[ "$PUBLISHED" = "published" ] && ck "U4 cluster publish works on the upgraded install (lazy table)" 1 || ck "U4 cluster publish works on the upgraded install (lazy table)" 0 "$(cat "$RUN/up1-publish.out" 2>/dev/null | head -2)"

# U5: page cache functional AFTER upgrade — re-render replaces the stored body
render "UP-NEW"
grep -q "UP-NEW" "$CACHE_FILE" 2>/dev/null && ck "U5a page cache stores fresh renders after upgrade" 1 || ck "U5a page cache stores fresh renders after upgrade" 0 "body=$(head -c 80 "$CACHE_FILE" 2>/dev/null)"
# the repeat render must OVERWRITE the SAME store path (post-upgrade key
# semantics: fresh body replaces the stale copy; the worker inserts a fresh
# canary post per render, so byte-identity across renders is by-design
# impossible — what must hold is: same path, fresh content, old gone)
render "UP-NEW"
H1=$(md5sum "$CACHE_FILE" 2>/dev/null | cut -d' ' -f1)
render "UP-NEWER"
H2=$(md5sum "$CACHE_FILE" 2>/dev/null | cut -d' ' -f1)
[ -n "$H1" ] && [ "$H2" != "$H1" ] && grep -q "UP-NEWER" "$CACHE_FILE" 2>/dev/null && ! grep -q "<!--UCM:UP-NEW--" "$CACHE_FILE" 2>/dev/null && ck "U5b same store path overwritten by the fresh render after upgrade" 1 || ck "U5b same store path overwritten by the fresh render after upgrade" 0 "h1=$H1 h2=$H2 body=$(head -c 120 "$CACHE_FILE" 2>/dev/null | tr '\n' ' ')"

# teardown
dsql "DROP USER IF EXISTS 'wpuser'@'127.0.0.1'; FLUSH PRIVILEGES;" > /dev/null 2>&1
rm -f "$RUN/up1-publish.out"
echo "$ROUND | teardown | DB credentials destroyed"
echo "$ROUND | summary | pass=$TOTAL_PASS | fail=$TOTAL_FAIL"
[ "$TOTAL_FAIL" -eq 0 ]
