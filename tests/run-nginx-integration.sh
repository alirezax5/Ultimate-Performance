#!/bin/bash
# M3 — REAL Nginx live integration (live gate).
#
# Real stack: user-space MariaDB closure (same as M1/M2), real WP 6.7.2 install,
# the plugin's REAL generated nginx snippet (src/WebServer/Nginx/Rules.php,
# applied by this suite exactly as an admin would), real nginx on 127.0.0.1:8097
# proxying misses to a real PHP origin (php -S, 127.0.0.1:8098) with an
# execution counter. NO mocks: every assertion below is real HTTP through the
# real server pair.
#
# HIT PROOF: the origin router increments an execution counter on every PHP
# request. A cache HIT must serve byte-identical bytes to the stored file with
# a counter DELTA OF ZERO — proving PHP never executed for that response.
#
# Security canary set (per milestone contract): wp-config.php, .env, .git,
# path traversal variants, NUL byte, direct cache-tree access (internal-only),
# Host-header poisoning (never an authorization input), query-string bypass.
#
# Usage: bash tests/run-nginx-integration.sh <round>
set -u
cd "$(dirname "$0")/.."
PLUGIN_DIR="$(pwd)"
REPO_ROOT="$(cd "$PLUGIN_DIR/../.." && pwd)"
ROUND="${1:-M3-NGINX}"
PHP_WP="${UC_REALWP_PHP_BIN:-/home/z/opt/php84/php}"
WPCLI="${UC_WPCLI:-$REPO_ROOT/wp-cli.phar}"
TARBALLS="${UC_WP_TARBALLS_DIR:-$REPO_ROOT}"
CACHE="${UC_PROVISION_CACHE:-$HOME/.cache/uc-provision}"
RUN="${UC_REALWP_RUNTIME:-/home/z/opt/wp-matrix}"
NGX_BIN="${UC_NGINX_BIN:-$CACHE/nginx-root/usr/sbin/nginx}"
NGX_PORT="${UC_NGINX_PORT:-8097}"
ORIGIN_PORT="${UC_NGINX_ORIGIN_PORT:-8098}"
HOST="nginx1.test"
ENVDIR="$RUN/nginx1"
DOCROOT="$ENVDIR/wordpress"
CURLOPTS=(-sS --max-time 20)

wp_fail() { echo "$ROUND | $1 | FAIL ($2)"; exit 1; }
wp_gate() { echo "$ROUND | $1 | SKIP/BLOCKED ($2)"; exit 0; }

# --- 0. tool gates -------------------------------------------------------------
[ -x "$PHP_WP" ] || wp_gate provision "PHP_WP binary missing at $PHP_WP"
"$PHP_WP" -m 2>/dev/null | grep -qi '^mysqli$' || wp_gate provision "mysqli not loadable"
[ -f "$WPCLI" ] || wp_gate provision "wp-cli.phar not found"
[ -f "$TARBALLS/wordpress-6.7.2.tar.gz" ] || wp_gate provision "wordpress tarball missing"
[ -f "$CACHE/mdb-root/usr/sbin/mariadbd" ] || wp_gate provision "MariaDB closure missing (run tests/run-real-wp-live.sh once first)"

# nginx self-provisioning (root-less deb extraction, same pattern as MariaDB)
if [ ! -x "$NGX_BIN" ]; then
  echo "$ROUND | provision: extracting nginx from debs (root-less)"
  DEBS="$CACHE/debs-nginx"; mkdir -p "$DEBS" "$CACHE/nginx-root"
  ( cd "$DEBS" && apt-get download nginx nginx-core nginx-common >/dev/null 2>&1 \
    || curl -sS --retry 2 --max-time 240 -O "https://deb.debian.org/debian/pool/main/n/nginx/nginx_1.26.3-3+deb13u7_amd64.deb" -O "https://deb.debian.org/debian/pool/main/n/nginx/nginx-common_1.26.3-3+deb13u7_all.deb" ) \
    || wp_gate provision "nginx deb acquisition failed"
  for d in "$DEBS"/nginx*.deb; do dpkg -x "$d" "$CACHE/nginx-root"; done
  [ -x "$NGX_BIN" ] || wp_gate provision "nginx binary still missing after extraction"
fi

# --- 1. MariaDB (same cached closure as M1/M2) ---------------------------------
MDB_ROOT="$CACHE/mdb-root"
MDB_DATA="$CACHE/mdb-data"
MDB_RUN="$CACHE/mdb-run"
SOCK="$MDB_RUN/mysqld.sock"
PORT="${UC_REALWP_DBPORT:-3307}"
MDB_LIBS="$MDB_ROOT/usr/lib/x86_64-linux-gnu:$MDB_ROOT/lib/x86_64-linux-gnu"

db_alive() {
  [ -S "$SOCK" ] || return 1
  LD_LIBRARY_PATH="$MDB_LIBS" "$MDB_ROOT/usr/bin/mariadb-admin" --no-defaults \
    --socket="$SOCK" -u root ping > /dev/null 2>&1
}
if ! db_alive; then
  LD_LIBRARY_PATH="$MDB_LIBS" "$MDB_ROOT/usr/sbin/mariadbd" --no-defaults \
    --basedir="$MDB_ROOT/usr" --datadir="$MDB_DATA" --socket="$SOCK" \
    --port="$PORT" --bind-address=127.0.0.1 --skip-name-resolve \
    --tmpdir="$MDB_RUN" > "$CACHE/mdb-server.log" 2>&1 &
  echo $! > "$CACHE/mdb-server.pid"
  for i in $(seq 1 30); do db_alive && break; sleep 1; done
  db_alive || wp_fail provision "mariadbd did not become ready"
fi
dsql() {
  LD_LIBRARY_PATH="$MDB_LIBS" "$MDB_ROOT/usr/bin/mariadb" --no-defaults \
    --socket="$SOCK" -u root -N -B -e "$1"
}

# --- 2. per-run credentials + database -----------------------------------------
DB_PASS="$($PHP_WP -r 'echo bin2hex(random_bytes(12));')"
ADMIN_PASS="$($PHP_WP -r 'echo bin2hex(random_bytes(12));')"
umask 077
printf 'UC_REALWP_DBPASS=%s\nUC_REALWP_ADMINPASS=%s\n' "$DB_PASS" "$ADMIN_PASS" > "$ENVDIR.wp-db.env" 2>/dev/null || { mkdir -p "$RUN"; printf 'UC_REALWP_DBPASS=%s\nUC_REALWP_ADMINPASS=%s\n' "$DB_PASS" "$ADMIN_PASS" > "$ENVDIR.wp-db.env"; }
. "$ENVDIR.wp-db.env"

dsql "DROP DATABASE IF EXISTS nginx1; CREATE DATABASE nginx1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;
DROP USER IF EXISTS 'wpuser'@'127.0.0.1';
CREATE USER 'wpuser'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON nginx1.* TO 'wpuser'@'127.0.0.1';
FLUSH PRIVILEGES;" || wp_fail provision "database bootstrap SQL failed"

# --- 3. WP environment provision (real installer, real plugin activation) ------
rm -rf "$ENVDIR"
mkdir -p "$ENVDIR"
tar -xzf "$TARBALLS/wordpress-6.7.2.tar.gz" -C "$ENVDIR" || wp_fail provision "tar extract failed"
[ -f "$DOCROOT/wp-load.php" ] || wp_fail provision "wp-load missing"

rsync -a --delete \
  --exclude 'tests/' --exclude 'docs/' --exclude 'download/' \
  "$PLUGIN_DIR/" "$DOCROOT/wp-content/plugins/ultimate-performance/" || wp_fail provision "plugin rsync failed"

wpcli_in() { "$PHP_WP" "$WPCLI" --path="$DOCROOT" "$@" > /dev/null 2>&1; }
wpcli_in config create --dbname=nginx1 --dbuser='wpuser' --dbpass="$DB_PASS" --dbhost="127.0.0.1:$PORT" --skip-check || wp_fail provision "wp config create"
wpcli_in core install --url="http://$HOST" --title="UC M3 nginx1" --admin_user='ucadmin' --admin_password="$ADMIN_PASS" --admin_email="admin@$HOST" --skip-email || wp_fail provision "wp core install"
wpcli_in rewrite structure '/%postname%/' || wp_fail provision "rewrite structure"
wpcli_in rewrite flush || wp_fail provision "rewrite flush"
wpcli_in eval '
  $u = get_user_by( "login", "ucadmin" );
  wp_set_current_user( $u ? $u->ID : 0 );
  require_once ABSPATH . "wp-admin/includes/plugin.php";
  $r = activate_plugin( "ultimate-performance/ultimate-performance.php" );
  if ( is_wp_error( $r ) ) { fwrite( STDERR, $r->get_error_message() ); exit( 1 ); }
' || wp_fail provision "plugin activation"
wpcli_in eval '
  update_option( "ultimate_performance_settings", array_merge(
    (array) get_option( "ultimate_performance_settings", array() ),
    array( "enabled" => true, "page_cache_enabled" => true )
  ) );
  ( new \UltimatePerformance\ObjectCache\Dropin() )->ensure( WP_CONTENT_DIR );
' || wp_fail provision "settings + drop-in"

# optional main-query instrumentation (UC_M3_DEBUG=1 bash tests/run-nginx-integration.sh)
if [ "${UC_M3_DEBUG:-0}" = "1" ]; then
  mkdir -p "$DOCROOT/wp-content/mu-plugins"
  cat > "$DOCROOT/wp-content/mu-plugins/uc-m3-debug.php" <<'MUEOF'
<?php
$lg = '/home/z/opt/wp-matrix/nginx1/debug-query.log';
file_put_contents( $lg, sprintf( "%s URI=%s HOST=%s SCRIPT=%s QS=%s\n", date('H:i:s'), $_SERVER['REQUEST_URI'] ?? '?', $_SERVER['HTTP_HOST'] ?? '?', $_SERVER['SCRIPT_NAME'] ?? '?', $_SERVER['QUERY_STRING'] ?? '' ), FILE_APPEND );
add_action( 'parse_request', function ( $wp ) use ( $lg ) {
        global $wp_rewrite;
        $rules = $wp_rewrite->rules;
        file_put_contents( $lg, sprintf( "  PARSE matched_rule=%s error=%s rules_count=%s qv=%s\n", var_export( $wp->matched_rule ?? null, true ), var_export( $wp->query_vars['error'] ?? null, true ), is_array( $rules ) ? count( $rules ) : 'n/a', substr( wp_json_encode( $wp->query_vars ), 0, 200 ) ), FILE_APPEND );
}, 10, 1 );
add_action( 'wp', function () use ( $lg ) {
        global $wp_query;
        file_put_contents( $lg, sprintf( "  WP is_404=%s is_home=%s found=%s\n", var_export( $wp_query->is_404(), true ), var_export( $wp_query->is_home(), true ), $wp_query->found_posts ), FILE_APPEND );
} );
add_action( 'posts_request', function ( $sql ) use ( $lg ) {
        global $wpdb;
        $cnt = 0; $rules_len = -1; $site = '?';
        try {
                $r = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->posts . " WHERE post_status='publish' AND post_type='post'" );
                if ( is_numeric( $r ) ) { $cnt = (int) $r; }
                $rules_len = strlen( (string) get_option( 'rewrite_rules', '' ) );
                $site = (string) get_option( 'siteurl', '?' );
        } catch ( \Throwable $t ) {}
        file_put_contents( $lg, sprintf( "  DB[h=%s db=%s pre=%s u=%s] publish_posts=%d rules_len=%d siteurl=%s SQL: %s\n", $wpdb->dbhost ?? '?', $wpdb->dbname ?? '?', $wpdb->prefix ?? '?', ( defined( 'DB_USER' ) ? DB_USER : '?' ), $cnt, $rules_len, $site, substr( (string) $sql, 0, 120 ) ), FILE_APPEND );
        return $sql;
} );
MUEOF
  : > "$DOCROOT/../debug-query.log"
fi

# --- 4. generate the snippet via the REAL generator, assemble nginx config -----
SNIPPET=$("$PHP_WP" -d error_reporting=E_ALL -r '
  define( "ABSPATH", "/tmp/" );
  define( "WP_CONTENT_DIR", "/tmp/wp-content" );
  define( "ULTIMATE_PERFORMANCE_TESTING", true );
  define( "ULTIMATE_PERFORMANCE_DIR", "'"$PLUGIN_DIR"'/" );
  require ULTIMATE_PERFORMANCE_DIR . "src/Core/Autoloader.php";
  \UltimatePerformance\Core\Autoloader::register();
  echo \UltimatePerformance\WebServer\Nginx\Rules::generate( array(
    "host"       => "'"$HOST"'",
    "cache_root" => "'"$DOCROOT"'/wp-content/cache/ultimate-performance",
    "origin"     => "127.0.0.1:'"$ORIGIN_PORT"'",
    "listen"     => "127.0.0.1:'"$NGX_PORT"'",
    "docroot"    => "'"$DOCROOT"'",
  ) );
  $t = "probe" . substr( sha1( "'"$ROUND"'.'"$RANDOM"'" ), 0, 12 );
  file_put_contents( "'"$ENVDIR"'/probe-token.txt", $t );
')
[ -n "$SNIPPET" ] || wp_fail provision "snippet generation returned empty (generator refused inputs)"
grep -q "BEGIN Ultimate Performance (nginx)" <<<"$SNIPPET" || wp_fail provision "snippet missing managed markers"

ROOT_PHYS=$("$PHP_WP" -r '
  define( "ABSPATH", "/tmp/" );
  define( "ULTIMATE_PERFORMANCE_DIR", "'"$PLUGIN_DIR"'/" );
  require ULTIMATE_PERFORMANCE_DIR . "src/Core/Autoloader.php";
  \UltimatePerformance\Core\Autoloader::register();
  echo \UltimatePerformance\CacheKey\Key::segment( "(root)" );
')
PROBE_TOKEN=$(cat "$ENVDIR/probe-token.txt")
PROBE_BODY="ultimate-performance-nginx-probe:$PROBE_TOKEN"

# verify probe entry — written into the cache tree exactly where the mapping
# expects it (segment shape asserted by audit-nginx-rules G7).
mkdir -p "$DOCROOT/wp-content/cache/ultimate-performance/v/$HOST/uc-verify-$PROBE_TOKEN"
printf '%s' "$PROBE_BODY" > "$DOCROOT/wp-content/cache/ultimate-performance/v/$HOST/uc-verify-$PROBE_TOKEN/index.html"

mkdir -p "$ENVDIR/ngx"
# The snippet is a complete http{} include (maps + server{}): the suite applies
# it exactly as the admin would — ONE include line inside http{}.
cat > "$ENVDIR/nginx.conf" <<EOF
worker_processes 1;
error_log $ENVDIR/ngx/error.log warn;
pid $ENVDIR/ngx/nginx.pid;
events { worker_connections 64; }
http {
    access_log $ENVDIR/ngx/access.log;
    client_body_temp_path $ENVDIR/ngx/t-body;
    proxy_temp_path $ENVDIR/ngx/t-proxy;
    fastcgi_temp_path $ENVDIR/ngx/t-fcgi;
    uwsgi_temp_path $ENVDIR/ngx/t-uwsgi;
    scgi_temp_path $ENVDIR/ngx/t-scgi;
    include $ENVDIR/uc-nginx-snippet.conf;
}
EOF
printf '%s\n' "$SNIPPET" > "$ENVDIR/uc-nginx-snippet.conf"

"$NGX_BIN" -t -c "$ENVDIR/nginx.conf" > "$ENVDIR/ngx/test.log" 2>&1 || {
  cat "$ENVDIR/ngx/test.log"
  wp_fail provision "nginx config test failed"
}

# --- 5. PHP origin (real WP execution + execution counter) ---------------------
COUNTER="$ENVDIR/origin-counter"
echo 0 > "$COUNTER"
cat > "$ENVDIR/router.php" <<EOF
<?php
// M3 origin router: (1) execution counter — every WP-booting request bumps it,
// (2) X-UC-Original-URI propagation so WP routes the ORIGINAL request,
// (3) static assets pass through, everything else boots WordPress.
\$counter = '$COUNTER';
\$orig = isset( \$_SERVER['HTTP_X_UC_ORIGINAL_URI'] ) ? (string) \$_SERVER['HTTP_X_UC_ORIGINAL_URI'] : '';
if ( '' !== \$orig ) {
    \$_SERVER['REQUEST_URI'] = \$orig;
    \$u = parse_url( \$orig );
    // M3-T4: WP's parse_request prefers PATH_INFO over REQUEST_URI when set.
    // php -S sets PATH_INFO to the INTERNALLY REWRITTEN path (the /uc-cache/…
    // alias path), so WP parsed the wrong request and 404'd every proxied
    // cache-miss. Override PATH_INFO with the ORIGINAL path.
    \$_SERVER['PATH_INFO']    = isset( \$u['path'] ) ? (string) \$u['path'] : '';
    \$_SERVER['QUERY_STRING'] = isset( \$u['query'] ) ? \$u['query'] : '';
    parse_str( \$_SERVER['QUERY_STRING'], \$_GET );
    \$_SERVER['SCRIPT_NAME']  = '/index.php';
    \$_SERVER['PHP_SELF']     = '/index.php';
}
\$path = parse_url( \$_SERVER['REQUEST_URI'], PHP_URL_PATH );
\$file = \$_SERVER['DOCUMENT_ROOT'] . \$path;
if ( \$path !== '/' && \$path !== '' && is_file( \$file ) && substr( \$path, -4 ) !== '.php' ) {
    return false; // static asset — served by php -S, NOT counted as WP execution
}
file_put_contents( \$counter, (string) ( (int) file_get_contents( \$counter ) + 1 ) );
// WP_USE_THEMES intentionally NOT defined here: WP 6.7's index.php defines it
// unconditionally and a duplicate define emits a warning that corrupts the
// response before the Engine's template_redirect intercept (M3 finding).
require \$_SERVER['DOCUMENT_ROOT'] . '/index.php';
EOF

"$PHP_WP" -S "127.0.0.1:$ORIGIN_PORT" -t "$DOCROOT" "$ENVDIR/router.php" > "$ENVDIR/origin.log" 2>&1 &
ORIGIN_PID=$!
for i in $(seq 1 20); do curl -s -o /dev/null "http://127.0.0.1:$ORIGIN_PORT/wp-login.php" && break; sleep 0.5; done

"$NGX_BIN" -c "$ENVDIR/nginx.conf" || wp_fail provision "nginx failed to start"
NGX_UP=1
for i in $(seq 1 20); do curl -s -o /dev/null "http://127.0.0.1:$NGX_PORT/" && NGX_UP=1 && break; sleep 0.5; done

# --- 6. live checks ------------------------------------------------------------
TOTAL_PASS=0; TOTAL_FAIL=0
ck() { # ck <name> <cond(0/1)> <detail>
  if [ "$2" = "1" ]; then TOTAL_PASS=$((TOTAL_PASS+1)); echo "$ROUND | $1 | PASS"; else TOTAL_FAIL=$((TOTAL_FAIL+1)); echo "$ROUND | $1 | FAIL ($3)"; fi
}
counter() { cat "$COUNTER"; }
# Every request carries the virtual host's Host header (a real DNS lookup
# maps $HOST to this server; curl to 127.0.0.1 would otherwise send a wrong
# Host and WP's canonical-redirect/lookup layer would 301/404 — M3 finding).
get() { # get <path> [extra curl args...] → sets BODY/STATUS
  local p="$1"; shift
  BODY=$(curl "${CURLOPTS[@]}" -H "Host: $HOST" -w $'\n%{http_code}' "$@" "http://127.0.0.1:$NGX_PORT$p")
  STATUS=$(tail -n1 <<<"$BODY"); BODY=$(head -n -1 <<<"$BODY")
}

# N1 — verify probe served statically (integration ACTIVE proof)
C0=$(counter)
get "/uc-verify-$PROBE_TOKEN/"
ck "N1a probe served byte-identical" "$([ "$STATUS" = "200" ] && [ "$BODY" = "$PROBE_BODY" ] && echo 1 || echo 0)" "status=$STATUS body=$BODY"
ck "N1b probe executed NO PHP (counter frozen)" "$([ "$(counter)" = "$C0" ] && echo 1 || echo 0)" "counter=$(counter) was=$C0"

# N2 — MISS then STATIC HIT on a real permalink
C0=$(counter)
get "/hello-world/"
MISS_BODY="$BODY"
ck "N2a first request 200 via origin (MISS)" "$([ "$STATUS" = "200" ] && [ "$(( $(counter) - C0 ))" -ge 1 ] && echo 1 || echo 0)" "status=$STATUS delta=$(( $(counter) - C0 ))"
STORED="$DOCROOT/wp-content/cache/ultimate-performance/v/$HOST/hello-world/index.html"
[ -f "$STORED" ] && ck "N2b entry stored in the real tree" 1 "stored" || ck "N2b entry stored in the real tree" 0 "missing $STORED"
C0=$(counter)
get "/hello-world/"
ck "N2c second request served STATICALLY (counter delta 0)" "$([ "$STATUS" = "200" ] && [ "$(counter)" = "$C0" ] && echo 1 || echo 0)" "status=$STATUS delta=$(( $(counter) - C0 ))"
# Byte comparison: $( ) strips trailing newlines from the captured body, so
# compare the captured body as a PREFIX of the stored file (the file may end
# with newlines the shell variable cannot hold).
printf '%s' "$BODY" > "$ENVDIR/serve-check.tmp"
BSIZE=$(stat -c%s "$ENVDIR/serve-check.tmp")
cmp -s "$ENVDIR/serve-check.tmp" <(head -c "$BSIZE" "$STORED") && ck "N2d HIT bytes == stored file bytes (prefix+newline tolerance)" 1 "identical" || ck "N2d HIT bytes == stored file bytes (prefix+newline tolerance)" 0 "differs"

# N3 — root page through location = /
get "/" > /dev/null          # MISS: first render stores the entry
C0=$(counter)
get "/"
ck "N3a root HIT static (counter frozen)" "$([ "$STATUS" = "200" ] && [ "$(counter)" = "$C0" ] && echo 1 || echo 0)" "status=$STATUS delta=$(( $(counter) - C0 ))"
RSTORED="$DOCROOT/wp-content/cache/ultimate-performance/v/$HOST/$ROOT_PHYS/index.html"
printf '%s' "$BODY" > "$ENVDIR/serve-check.tmp"
BSIZE=$(stat -c%s "$ENVDIR/serve-check.tmp")
cmp -s "$ENVDIR/serve-check.tmp" <(head -c "$BSIZE" "$RSTORED") && ck "N3b root bytes == stored (root_phys mapping)" 1 "identical" || ck "N3b root bytes == stored (root_phys mapping)" 0 "missing/differs $RSTORED"

# N4 — secret canaries
get "/wp-config.php"
ck "N4a wp-config.php denied" "$([ "$STATUS" = "403" ] || [ "$STATUS" = "404" ] && echo 1 || echo 0)" "status=$STATUS"
ck "N4b wp-config body leaks NO credentials" "$(grep -q 'DB_PASSWORD' <<<"$BODY" && echo 0 || echo 1)" "leak!"
get "/.env"
ck "N4c .env denied" "$([ "$STATUS" = "403" ] || [ "$STATUS" = "404" ] && echo 1 || echo 0)" "status=$STATUS"
ck "N4d .env body leaks NO credentials" "$(grep -q 'DB_' <<<"$BODY" && echo 0 || echo 1)" "leak!"
get "/.git/config"
ck "N4e .git/config denied" "$([ "$STATUS" = "403" ] || [ "$STATUS" = "404" ] && echo 1 || echo 0)" "status=$STATUS"
ck "N4f .git body leaks NO repo config" "$(grep -q '\[core\]' <<<"$BODY" && echo 0 || echo 1)" "leak!"

# N5 — traversal + NUL
get "/..%2f..%2f..%2fwp-config.php"
ck "N5a encoded traversal denied/contained" "$([ "$STATUS" != "200" ] || ! grep -q 'DB_PASSWORD' <<<"$BODY" && echo 1 || echo 0)" "status=$STATUS"
get "/hello-world/..%2f..%2fwp-config.php"
ck "N5b mixed traversal denied/contained" "$([ "$STATUS" != "200" ] || ! grep -q 'DB_PASSWORD' <<<"$BODY" && echo 1 || echo 0)" "status=$STATUS"
get "/%00"
ck "N5c NUL byte rejected" "$([ "$STATUS" = "400" ] || [ "$STATUS" = "404" ] || [ "$STATUS" = "403" ] && echo 1 || echo 0)" "status=$STATUS"

# N6 — direct cache-tree access must 404 (internal-only)
get "/uc-cache/$HOST/hello-world/index.html"
ck "N6a cached body not directly fetchable" "$([ "$STATUS" = "404" ] && echo 1 || echo 0)" "status=$STATUS"
get "/uc-cache/$HOST/hello-world/index.html.meta.json"
ck "N6b cache metadata not directly fetchable" "$([ "$STATUS" = "404" ] && echo 1 || echo 0)" "status=$STATUS"
get "/uc-cache/$HOST/$ROOT_PHYS/index.html.meta.json"
ck "N6c root cache metadata not directly fetchable" "$([ "$STATUS" = "404" ] && echo 1 || echo 0)" "status=$STATUS"

# N7 — Host poisoning: never an authorization input, no cross-host writes
C0=$(counter)
BODY=$(curl "${CURLOPTS[@]}" -H "Host: evil.test" -w $'\n%{http_code}' "http://127.0.0.1:$NGX_PORT/hello-world/")
STATUS=$(tail -n1 <<<"$BODY"); BODY=$(head -n -1 <<<"$BODY")
ck "N7a poisoned Host gets the same anonymous site bytes (per-site mapping)" "$([ "$STATUS" = "200" ] && echo 1 || echo 0)" "status=$STATUS"
ck "N7b no cache entry created under evil host" "$([ ! -d "$DOCROOT/wp-content/cache/ultimate-performance/v/evil.test" ] && echo 1 || echo 0)" "created!"
C0=$(counter)
BODY=$(curl "${CURLOPTS[@]}" -H "Host: evil.test" -w $'\n%{http_code}' "http://127.0.0.1:$NGX_PORT/wp-admin/")
STATUS=$(tail -n1 <<<"$BODY"); BODY=$(head -n -1 <<<"$BODY")
ck "N7c /wp-admin NOT authorized by Host header" "$([ "$STATUS" = "302" ] || [ "$STATUS" = "200" ] && echo 1 || echo 0)" "status=$STATUS"
ck "N7d /wp-admin never served from static cache" "$([ "$(counter)" -gt "$C0" ] && echo 1 || echo 0)" "counter frozen = static"

# N8 — query strings bypass the static layer (unknown-query policy)
C0=$(counter); get "/hello-world/?utm_source=x"; D1=$(( $(counter) - C0 ))
C0=$(counter); get "/hello-world/?utm_source=x"; D2=$(( $(counter) - C0 ))
ck "N8a query request proxied (counter +1)" "$([ "$D1" -ge 1 ] && echo 1 || echo 0)" "delta=$D1"
ck "N8b query request NEVER static (counter +1 again)" "$([ "$D2" -ge 1 ] && echo 1 || echo 0)" "delta=$D2"

# N9 — purge propagation over the live server (wp-cli update + real cron tick)
NEW_TITLE="M3 purged $(date +%s)"
"$PHP_WP" "$WPCLI" --path="$DOCROOT" post update 1 --post_title="$NEW_TITLE" > /dev/null 2>&1 \
  && "$PHP_WP" "$WPCLI" --path="$DOCROOT" cron event run ultimate_performance_tick > /dev/null 2>&1
PROPAGATED=0
for i in $(seq 1 5); do
  sleep 1
  C0=$(counter); get "/hello-world/"
  if [ "$STATUS" = "200" ] && grep -q "$NEW_TITLE" <<<"$BODY" && [ "$(( $(counter) - C0 ))" -ge 1 ]; then PROPAGATED=1; break; fi
done
ck "N9a content update propagates: purge → MISS → fresh render" "$PROPAGATED" "title=$NEW_TITLE"

# N10 — HEAD served statically
C0=$(counter)
curl ${CURLOPTS[@]} -I -H "Host: $HOST" "http://127.0.0.1:$NGX_PORT/hello-world/" > /dev/null 2>&1
ck "N10 HEAD from static cache (counter frozen)" "$([ "$(counter)" = "$C0" ] && echo 1 || echo 0)" "delta=$(( $(counter) - C0 ))"

# --- 7. teardown ---------------------------------------------------------------
[ -n "${NGX_UP:-}" ] && "$NGX_BIN" -s quit -c "$ENVDIR/nginx.conf" 2>/dev/null
sleep 1
kill "$ORIGIN_PID" 2>/dev/null
wait "$ORIGIN_PID" 2>/dev/null
dsql "DROP USER IF EXISTS 'wpuser'@'127.0.0.1'; FLUSH PRIVILEGES;" > /dev/null 2>&1
rm -f "$ENVDIR.wp-db.env" "$ENVDIR/probe-token.txt"

STRAYS=$(pgrep -f "nginx: master\|nginx: worker" | wc -l)
[ "$STRAYS" -gt 0 ] && echo "$ROUND | teardown | WARN: $STRAYS stray nginx processes" \
  || echo "$ROUND | teardown | 0 stray nginx/origin processes; DB credentials destroyed"

echo "$ROUND | summary | pass=$TOTAL_PASS | fail=$TOTAL_FAIL"
[ "$TOTAL_FAIL" -eq 0 ]
