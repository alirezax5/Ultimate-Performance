#!/bin/bash
# O1 — Real MariaDB live gate runner.
# Phase O §8-16 require real MariaDB evidence.
set -u
cd "$(dirname "$0")/.."
ROUND="${1:-O1-LIVE}"
PHP84="${UC_PHP84_BIN:-$HOME/.cache/uc-provision/php84-root/usr/bin/uc-php84}"

[ -x "$PHP84" ] || { echo "$ROUND | php84-provision | FAIL (uc-php84 missing)"; exit 1; }

# --- 1. Provision MariaDB (idempotent) ----------------------------------------
echo "=== Provisioning MariaDB ==="
bash tests/provision-mariadb.sh > /tmp/o1-maria.env 2>/tmp/o1-maria.err || {
  echo "$ROUND | mariadb-provision | FAIL"
  cat /tmp/o1-maria.err
  exit 1
}
set -a; . /tmp/o1-maria.env; set +a
echo "$ROUND | MariaDB $MARIA_VERSION on 127.0.0.1:$MARIA_PORT (pid=$MARIA_PID)"

# --- 2. Run the cluster live audit -------------------------------------------
run_suite() {
  local name="$1"; shift
  local out
  out=$(timeout 120 "$PHP84" "$@" 2>&1); local ec=$?
  local pass fail skip
  pass=$(echo "$out" | grep -cE '^\[PASS\]')
  fail=$(echo "$out" | grep -cE '^\[FAIL\]')
  skip=$(echo "$out" | grep -cE '^\[SKIP\]')
  echo "$ROUND | $name | pass=$pass | fail=$fail | skip=$skip | exit=$ec"
  [ "$fail" -gt 0 ] && echo "$out" | grep -E '^\[FAIL\]' | head -6
  return 0
}

run_suite audit-mariadb-live    tests/audit-mariadb-live.php

# --- 3. 5000-event load test -------------------------------------------------
echo "=== 5000-event load test (Phase O §15) ==="
out=$(timeout 180 "$PHP84" tests/audit-mariadb-5000events.php 2>&1); ec=$?
echo "$out" | grep -E '^\[(PASS|FAIL)\]' | tail -20
pass=$(echo "$out" | grep -cE '^\[PASS\]')
fail=$(echo "$out" | grep -cE '^\[FAIL\]')
echo "$ROUND | audit-mariadb-5000events | pass=$pass | fail=$fail | exit=$ec"

# --- 4. Teardown ---------------------------------------------------------------
pkill -9 -f "$HOME/.cache/uc-provision/mariadb-root/usr/sbin/mariadbd" 2>/dev/null || true
sleep 0.5
rm -f /home/z/.cache/uc-provision/mariadb-root.sock /home/z/.cache/uc-provision/mariadb-root.pid /home/z/.cache/uc-provision/mariadb-root.log
rm -rf /home/z/.cache/uc-provision/mariadb-data
echo "$ROUND | teardown | MariaDB stopped"
