#!/bin/bash
# N4E — OpenLiteSpeed rootless provisioning + live HTTP probe.
# Phase N §11-12 requires a real OpenLiteSpeed attempt. This runner:
#   1. Downloads the pinned OLS source tarball (cached)
#   2. Extracts to a user-space root
#   3. Configures the Example vhost to listen on a high port (8088)
#   4. Starts OLS rootlessly (LSWS_HOME env var)
#   5. Runs audit-ols-live.php against the running daemon
#   6. Tears down (PID-tracked)
#
# Phase N §13: LiteSpeed Enterprise is NOT claimed here. This is OLS Open.
set -u
REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TEST_FILE="$REPO_ROOT/tests/audit-ols-live.php"
cd "$REPO_ROOT"
ROUND="${1:-OLS-LIVE}"
CACHE="${UC_PROVISION_CACHE:-$HOME/.cache/uc-provision}"
SRC="$CACHE/src-ols"
OLS_ROOT="$CACHE/ols-root"
OLS_TGZ="$SRC/ols.tgz"
OLSV="1.7.19"
PORT="${UC_OLS_PORT:-8088}"
PHP84="${UC_PHP84_BIN:-$CACHE/php84-root/usr/bin/uc-php84}"

# --- 1. provision (cached) ---------------------------------------------------
if [ ! -x "$OLS_ROOT/bin/openlitespeed" ]; then
  echo "provisioning: downloading OpenLiteSpeed $OLSV (first run only)"
  mkdir -p "$SRC"
  if [ ! -f "$OLS_TGZ" ]; then
    curl -sL --retry 3 -o "$OLS_TGZ" "https://openlitespeed.org/packages/openlitespeed-$OLSV.tgz"
  fi
  mkdir -p "$OLS_ROOT"
  ( cd "$OLS_ROOT" && tar xzf "$OLS_TGZ" --strip-components=1 )
fi
[ -x "$OLS_ROOT/bin/openlitespeed" ] || { echo "$ROUND | ols-provision | FAIL"; exit 1; }

"$OLS_ROOT/bin/openlitespeed" -v 2>&1 | head -1 || { echo "$ROUND | ols-provision | FAIL (binary not runnable)"; exit 1; }

# --- 2. configure (idempotent) -----------------------------------------------
# Substitute the .in templates with user-space values (no root, no /usr/local).
cd "$OLS_ROOT"
MCUSER="$(id -un)"
MCGROUP="$(id -gn)"
[ -f conf/httpd_config.conf ] || sed \
  -e "s|%USER%|$MCUSER|g" -e "s|%GROUP%|$MCGROUP|g" \
  -e "s|%DEFAULT_TMP_DIR%|$OLS_ROOT/tmp|g" -e "s|%ADMIN_EMAIL%|root@localhost|g" \
  -e "s|%ADMINROOT%|$OLS_ROOT/admin|g" -e "s|%LSWS_HOME%|$OLS_ROOT|g" \
  -e "s|%OPENLSWS_EXAMPLEPORT%|$PORT|g" -e "s|%OPENLSWS_ADMINPORT%|7080|g" \
  -e "s|%OPENLSWS_ADMINSSL%|no|g" \
  -e "s|%HTTP_PORT%|$PORT|g" -e "s|%RUBY_BIN%|/usr/bin/ruby|g" \
  conf/httpd_config.conf.in > conf/httpd_config.conf

[ -f admin/conf/admin_config.conf ] || sed \
  -e "s|%ADMIN_PORT%|7080|g" -e "s|%SERVER_ROOT%|$OLS_ROOT|g" \
  admin/conf/admin_config.conf.in > admin/conf/admin_config.conf

# Symlink the PHP CLI as admin_php (OLS admin interface needs a PHP binary)
[ -f admin/fcgi-bin/admin_php ] || cp "$CACHE/php84-root/usr/bin/php" admin/fcgi-bin/admin_php

mkdir -p tmp/swap logs admin/conf admin/tmp admin/logs admin/cachedata \
  Example/logs Example/cachedata cgid cachedata autoupdate tmp/ocspcache

# --- 3. teardown any stale daemons -------------------------------------------
pkill -9 -f "$OLS_ROOT/bin/openlitespeed" 2>/dev/null || true
sleep 0.5
rm -f "$OLS_ROOT"/tmp/*.sock "$OLS_ROOT"/tmp/*.pid 2>/dev/null || true

# --- 4. start -----------------------------------------------------------------
LSWS_HOME="$OLS_ROOT" "$OLS_ROOT/bin/openlitespeed" 2>&1 | head -3
up=0
for i in $(seq 1 20); do
  if curl -s -o /dev/null -w "%{http_code}" --max-time 1 "http://127.0.0.1:$PORT/" | grep -qE "^(200|301|302|404)$"; then
    up=1; break
  fi
  sleep 0.5
done
if [ "$up" != 1 ]; then
  echo "$ROUND | ols-start | FAIL (HTTP never answered)"
  tail -10 "$OLS_ROOT/logs/error.log" 2>/dev/null
  exit 1
fi
echo "$ROUND | OLS up on http://127.0.0.1:$PORT (LiteSpeed/Open 1.7.$OLSV)"

# --- 5. run live audit --------------------------------------------------------
# TEST_FILE captured at top of script (absolute path) before any cd.
out=$("$PHP84" "$TEST_FILE" 2>&1); ec=$?
pass=$(echo "$out" | grep -cE '^\[PASS\]')
fail=$(echo "$out" | grep -cE '^\[FAIL\]')
skip=$(echo "$out" | grep -cE '^\[SKIP\]')
echo "$ROUND | audit-ols-live | pass=$pass | fail=$fail | skip=$skip | exit=$ec"
echo "$out" | grep -E '^\[FAIL\]' | head -3
echo "$out" | tail -2

# --- 6. teardown --------------------------------------------------------------
pkill -9 -f "$OLS_ROOT/bin/openlitespeed" 2>/dev/null || true
sleep 0.5
rm -f "$OLS_ROOT"/tmp/*.sock "$OLS_ROOT"/tmp/*.pid 2>/dev/null || true
echo "$ROUND | teardown | OLS stopped"
