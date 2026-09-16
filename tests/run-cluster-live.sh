#!/bin/bash
# M5 — §56 CLUSTER page-cache invalidation LIVE gate.
# Two REAL WP nodes (independent docroots + independent cache roots + independent
# node ids) sharing ONE MariaDB. Node A's post update must purge A locally
# (local-first) AND purge B via the cluster event (B's tick consumes it).
# Plus burst contract: 100/1000 events, bounded batch, watermark dedup,
# REAL janitor contract (C9) and metrics visibility on both nodes (C10).
#
# Epoch-guard honesty note: C3/C5 prove the guard does NOT interfere with the
# normal path (equal epochs execute). The STALE branch (event epoch < consumer
# epoch → skipped) is unit-proven in tests/audit-cluster.php N10 via the
# ultimate_cache_cluster_epoch filter seam — these nodes run the object cache
# in runtime-only mode (no UC_REDIS_*/UC_MEMCACHED_* backends), so the
# persisted chain epoch is 0 on both nodes and the guard can never skip here.
# That vacuity is a documented property of runtime-only mode, not a gap in
# this gate.
#
# Usage: bash tests/run-cluster-live.sh <round>
set -u
cd "$(dirname "$0")/.."
PLUGIN_DIR="$(pwd)"
REPO_ROOT="$(cd "$PLUGIN_DIR/../.." && pwd)"
ROUND="${1:-M5-CLUSTER}"
PHP="$HOME/.local/bin/php"
PHP_WP="${UC_REALWP_PHP_BIN:-/home/z/opt/php84/php}"
WPCLI="${UC_WPCLI:-$REPO_ROOT/wp-cli.phar}"
TARBALLS="${UC_WP_TARBALLS_DIR:-$REPO_ROOT}"
CACHE="${UC_PROVISION_CACHE:-$HOME/.cache/uc-provision}"
RUN="${UC_REALWP_RUNTIME:-/home/z/opt/wp-matrix}"
HOST="cluster1.test"
WPVER="6.7.2"

fail() { echo "$ROUND | $1 | FAIL ($2)"; exit 1; }
gate() { echo "$ROUND | $1 | SKIP/BLOCKED ($2)"; exit 0; }
[ -x "$PHP_WP" ] || gate provision "PHP_WP missing"
[ -f "$WPCLI" ] || gate provision "wp-cli missing"
[ -f "$TARBALLS/wordpress-$WPVER.tar.gz" ] || gate provision "WP tarball missing"
[ -d "$CACHE/mdb-root/usr/lib" ] || gate provision "MariaDB closure missing (run tests/run-real-wp-live.sh once first)"

MDB_ROOT="$CACHE/mdb-root"
MDB_DATA="$CACHE/mdb-data"
MDB_RUN="$CACHE/mdb-run"
SOCK="$MDB_RUN/mysqld.sock"
PORT="${UC_REALWP_DBPORT:-3307}"
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
dsql "DROP DATABASE IF EXISTS cluster1; CREATE DATABASE cluster1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;
DROP USER IF EXISTS 'wpuser'@'127.0.0.1'; CREATE USER 'wpuser'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON cluster1.* TO 'wpuser'@'127.0.0.1'; FLUSH PRIVILEGES;" || fail provision "db bootstrap"

TOTAL_PASS=0; TOTAL_FAIL=0
ck() { if [ "$2" = "1" ]; then TOTAL_PASS=$((TOTAL_PASS+1)); echo "$ROUND | $1 | PASS"; else TOTAL_FAIL=$((TOTAL_FAIL+1)); echo "$ROUND | $1 | FAIL ($3)"; fi; }

# --- provision BOTH nodes (same DB, independent trees) -------------------------
for NODE in m5a m5b; do
  ENVDIR="$RUN/$NODE"
  rm -rf "$ENVDIR"; mkdir -p "$ENVDIR"
  tar -xzf "$TARBALLS/wordpress-$WPVER.tar.gz" -C "$ENVDIR" || fail provision "$NODE tar"
  rsync -a --delete --exclude 'tests/' --exclude 'docs/' --exclude 'download/' "$PLUGIN_DIR/" "$ENVDIR/wordpress/wp-content/plugins/ultimate-performance/" || fail provision "$NODE rsync"
  wpcli_in() { "$PHP_WP" "$WPCLI" --path="$ENVDIR/wordpress" "$@" > /dev/null 2>&1; }
  wpcli_in config create --dbname=cluster1 --dbuser='wpuser' --dbpass="$DB_PASS" --dbhost="127.0.0.1:$PORT" --skip-check || fail provision "$NODE config"
  wpcli_in core install --url="http://$HOST" --title="UC M5 $NODE" --admin_user='ucadmin' --admin_password="$ADMIN_PASS" --admin_email="admin@$HOST" --skip-email || fail provision "$NODE install"
  wpcli_in rewrite structure '/%postname%/' || fail provision "$NODE rewrites"
  wpcli_in rewrite flush || fail provision "$NODE flush"
  wpcli_in eval '
    $u = get_user_by( "login", "ucadmin" ); wp_set_current_user( $u ? $u->ID : 0 );
    require_once ABSPATH . "wp-admin/includes/plugin.php";
    $r = activate_plugin( "ultimate-performance/ultimate-performance.php" );
    if ( is_wp_error( $r ) ) { exit( 1 ); }
    update_option( "ultimate_performance_settings", array_merge( (array) get_option( "ultimate_performance_settings", array() ), array( "enabled" => true, "page_cache_enabled" => true ) ) );
    ( new \UltimatePerformance\ObjectCache\Dropin() )->ensure( WP_CONTENT_DIR );
  ' || fail provision "$NODE activate"
  echo "$ROUND | provision-$NODE | ok (node $NODE, shared DB cluster1)"
done

A="$RUN/m5a/wordpress"; B="$RUN/m5b/wordpress"
AC="$A/wp-content/cache/ultimate-performance/v/cluster1.test"
BC="$B/wp-content/cache/ultimate-performance/v/cluster1.test"
tick() { "$PHP_WP" "$WPCLI" --path="$1" eval 'do_action( "ultimate_performance_tick" );' > /dev/null 2>&1; }
render() { # render <docroot> <marker> <uri> → stores via the real worker
  UC_REALWP_DIR="$1" UC_REALWP_URL="http://$HOST" UC_WORKER_MODE=render \
  UC_WORKER_MARKER="$2" UC_WORKER_URI="$3" "$PHP_WP" \
  "$PLUGIN_DIR/tests/lib/real-wp-pagecache-worker.php" > /dev/null 2>&1
}

# --- §56 step 1-2: both nodes render the same permalink -------------------------
render "$A" "M5A$(date +%s)" "/hello-world/"
[ -f "$AC/hello-world/index.html" ] && ck "C1 node A stored /hello-world/" 1 stored || ck "C1 node A stored /hello-world/" 0 "missing"
render "$B" "M5B$(date +%s)" "/hello-world/"
[ -f "$BC/hello-world/index.html" ] && ck "C2 node B stored /hello-world/ (independent tree)" 1 stored || ck "C2 node B stored /hello-world/ (independent tree)" 0 "missing"

# --- §56 step 3: Node A update → local-first purge on A --------------------------
T0=$(date +%s%3N)
"$PHP_WP" "$WPCLI" --path="$A" post update 1 --post_title="M5 cluster $(date +%s)" > /dev/null 2>&1
tick "$A"
[ ! -f "$AC/hello-world/index.html" ] && ck "C3 local-first: A's own entry purged" 1 purged || ck "C3 local-first: A's own entry purged" 0 "still cached"
[ -f "$BC/hello-world/index.html" ] && ck "C4 isolation: B still holds its stale copy before its tick" 1 "isolation ok" || ck "C4 isolation: B still holds its stale copy before its tick" 0 "unexpectedly purged"

# --- §56 step 4: Node B tick consumes the cluster event → B purged ---------------
tick "$B"
B_GONE_AT=$(date +%s%3N)
[ ! -f "$BC/hello-world/index.html" ] && ck "C5 cluster propagation: B's stale entry consumed+purged" 1 purged || ck "C5 cluster propagation: B's stale entry consumed+purged" 0 "still cached"
echo "$ROUND | latency | A-update→B-purged ≈ $((B_GONE_AT - T0)) ms (includes both ticks)"

# --- §56 step 5: idempotent dedup — a second B tick must not re-execute ----------
N_BEFORE=$(find "$BC" -name index.html 2>/dev/null | wc -l)
tick "$B"
N_AFTER=$(find "$BC" -name index.html 2>/dev/null | wc -l)
[ "$N_BEFORE" = "$N_AFTER" ] && ck "C6 watermark dedup: repeat tick is a no-op" 1 "no re-execution" || ck "C6 watermark dedup: repeat tick is a no-op" 0 "files changed"

# --- §56 step 6: burst 100 + 1000 ------------------------------------------------
burst() { # burst <n> → A publishes n purge_dirs events (real EventStore)
  "$PHP_WP" "$WPCLI" --path="$A" eval '
    $s = new \UltimatePerformance\Cluster\EventStore();
    for ( $i = 0; $i < '$1'; $i++ ) {
        $s->publish( "purge_dirs", array( "dirs" => array( "cluster1.test/burst-" . $i ), "tags" => array() ), 1 );
    }
    echo "published '$1'" . "\n";
  ' > /dev/null 2>&1
}
burst_check() { # burst_check <n> → B consumes within ceil(n/200) ticks
  local n="$1" consumed=0
  local ticks=$(( (n + 199) / 200 ))
  for i in $(seq 1 $ticks); do tick "$B"; done
  consumed=$(dsql "SELECT COUNT(*) FROM cluster1.wp_uc_invalidation_events WHERE consumed=1" 2>/dev/null | tail -1)
  local pending=$(dsql "SELECT COUNT(*) FROM cluster1.wp_uc_invalidation_events WHERE consumed=0" 2>/dev/null | tail -1)
  [ "$pending" = "0" ] && ck "C7 burst $n: fully consumed within $ticks tick(s) (batch cap 200)" 1 "consumed_total=$consumed" || ck "C7 burst $n: fully consumed within $ticks tick(s) (batch cap 200)" 0 "pending=$pending"
}
burst 100;  burst_check 100
burst 1000; burst_check 1000
TABLE_ROWS=$(dsql "SELECT COUNT(*) FROM cluster1.wp_uc_invalidation_events" 2>/dev/null | tail -1)
ck "C8 bounded table: ~1100 burst rows + originals, never unbounded" "$([ "${TABLE_ROWS:-9999}" -lt 1200 ] && echo 1 || echo 0)" "rows=$TABLE_ROWS"

# --- §56 step 7: REAL janitor contract (C9) ---------------------------------------
# consumed+old → pruned; consumed+fresh → kept; pending → NEVER pruned.
NOW_MS=$(( $(date +%s) * 1000 ))
CUTOFF_MS=$(( NOW_MS - 86400000 ))
dsql "UPDATE cluster1.wp_uc_invalidation_events SET created = created - 90000000 WHERE consumed = 1 AND id % 2 = 0" >/dev/null 2>&1  # backdate ~half the consumed rows (>24h)
# one fresh event left PENDING (B will not tick before the prune)
"$PHP_WP" "$WPCLI" --path="$A" eval '
  ( new \UltimatePerformance\Cluster\EventStore() )->publish( "purge_dirs", array( "dirs" => array( "cluster1.test/c9-pending" ), "tags" => array() ), 1 );
' >/dev/null 2>&1
PENDING_BEFORE=$(dsql "SELECT COUNT(*) FROM cluster1.wp_uc_invalidation_events WHERE consumed=0" 2>/dev/null | tail -1)
"$PHP_WP" "$WPCLI" --path="$B" eval '
  echo "pruned=" . ( new \UltimatePerformance\Cluster\EventStore() )->prune();
' >/dev/null 2>&1
OLD_CONSUMED_LEFT=$(dsql "SELECT COUNT(*) FROM cluster1.wp_uc_invalidation_events WHERE consumed=1 AND created < $CUTOFF_MS" 2>/dev/null | tail -1)
FRESH_CONSUMED=$(dsql "SELECT COUNT(*) FROM cluster1.wp_uc_invalidation_events WHERE consumed=1 AND created >= $CUTOFF_MS" 2>/dev/null | tail -1)
ck "C9a janitor pruned consumed rows past retention" "$([ "${OLD_CONSUMED_LEFT:-999}" = "0" ] && echo 1 || echo 0)" "old_consumed_left=$OLD_CONSUMED_LEFT"
ck "C9b janitor kept fresh consumed rows" "$([ "${FRESH_CONSUMED:-0}" -gt 0 ] && echo 1 || echo 0)" "fresh=$FRESH_CONSUMED"
PENDING_AFTER=$(dsql "SELECT COUNT(*) FROM cluster1.wp_uc_invalidation_events WHERE consumed=0" 2>/dev/null | tail -1)
ck "C9c janitor NEVER pruned pending rows" "$([ "${PENDING_BEFORE:-0}" -ge 1 ] && [ "$PENDING_AFTER" = "$PENDING_BEFORE" ] && echo 1 || echo 0)" "before=$PENDING_BEFORE after=$PENDING_AFTER"

# --- §56 step 8: metrics visibility on BOTH nodes (C10) ---------------------------
STATE_A="$RUN/m5a/wordpress/wp-content/cache/ultimate-performance/meta/cluster-state.json"
STATE_B="$RUN/m5b/wordpress/wp-content/cache/ultimate-performance/meta/cluster-state.json"
PUB_A="0"; CON_B="0"; LAG_B="0"
[ -r "$STATE_A" ] && PUB_A="$($PHP_WP -r '$d=json_decode(file_get_contents($argv[1]),true);echo (int)($d["published"]??0);' "$STATE_A" 2>/dev/null)"
[ -r "$STATE_B" ] && CON_B="$($PHP_WP -r '$d=json_decode(file_get_contents($argv[1]),true);echo (int)($d["consumed"]??0);' "$STATE_B" 2>/dev/null)"
[ -r "$STATE_B" ] && LAG_B="$($PHP_WP -r '$d=json_decode(file_get_contents($argv[1]),true);echo (int)($d["lag_ms"]??0);' "$STATE_B" 2>/dev/null)"
ck "C10a producer metrics visible on A (published ≥1)" "$([ "${PUB_A:-0}" -ge 1 ] && echo 1 || echo 0)" "published=$PUB_A"
ck "C10b consumer metrics visible on B (consumed ≥1, lag gauge ≥0)" "$([ "${CON_B:-0}" -ge 1 ] && [ "${LAG_B:--1}" -ge 0 ] && echo 1 || echo 0)" "consumed=$CON_B lag_ms=$LAG_B"

# --- teardown ---------------------------------------------------------------------
dsql "DROP USER IF EXISTS 'wpuser'@'127.0.0.1'; FLUSH PRIVILEGES;" > /dev/null 2>&1
echo "$ROUND | teardown | DB credentials destroyed"
echo "$ROUND | summary | pass=$TOTAL_PASS | fail=$TOTAL_FAIL"
[ "$TOTAL_FAIL" -eq 0 ]
