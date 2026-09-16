#!/bin/bash
# O3 — Real Redis + Apache + Nginx live gate runner.
# Phase O §22-27 require real service evidence.
set -u
cd "$(dirname "$0")/.."
ROUND="${1:-O3-LIVE}"
PHP84="${UC_PHP84_BIN:-$HOME/.cache/uc-provision/php84-root/usr/bin/uc-php84}"

[ -x "$PHP84" ] || { echo "$ROUND | php84-provision | FAIL (uc-php84 missing)"; exit 1; }

run_suite() {
  local name="$1"; shift
  local out
  out=$(timeout 60 "$PHP84" "$@" 2>&1); local ec=$?
  local pass fail skip
  pass=$(echo "$out" | grep -cE '^\[PASS\]')
  fail=$(echo "$out" | grep -cE '^\[FAIL\]')
  skip=$(echo "$out" | grep -cE '^\[SKIP\]')
  echo "$ROUND | $name | pass=$pass | fail=$fail | skip=$skip | exit=$ec"
  [ "$fail" -gt 0 ] && echo "$out" | grep -E '^\[FAIL\]' | head -5
  return 0
}

# --- 1. Redis ----------------------------------------------------------------
echo "=== Redis ==="
bash tests/provision-redis.sh > /tmp/o3-redis.env 2>&1
set -a; . /tmp/o3-redis.env; set +a
UC_REDIS_HOST=127.0.0.1 UC_REDIS_PORT=16379 run_suite audit-redis-live    tests/audit-redis-live.php
pkill -9 -f "redis-root/usr/bin/redis-server" 2>/dev/null || true

# --- 2. Apache ---------------------------------------------------------------
echo "=== Apache ==="
bash tests/provision-apache.sh > /tmp/o3-apache.env 2>&1
set -a; . /tmp/o3-apache.env; set +a
UC_APACHE_HOST=127.0.0.1 UC_APACHE_PORT=18080 run_suite audit-apache-live   tests/audit-apache-live.php
pkill -9 -f "apache-root/usr/sbin/apache2" 2>/dev/null || true

# --- 3. Nginx ----------------------------------------------------------------
echo "=== Nginx ==="
bash tests/provision-nginx.sh > /tmp/o3-nginx.env 2>&1
set -a; . /tmp/o3-nginx.env; set +a
UC_NGINX_HOST=127.0.0.1 UC_NGINX_PORT=18081 run_suite audit-nginx-live    tests/audit-nginx-live.php
pkill -9 -f "nginx-root/usr/sbin/nginx" 2>/dev/null || true

echo "$ROUND | teardown | all real services stopped"
