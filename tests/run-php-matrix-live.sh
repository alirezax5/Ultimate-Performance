#!/bin/bash
# N4D — run live cache suite under each provisioned PHP version.
# Phase N §9 requires real extension coverage for PHP 8.2 / 8.3 / 8.4.
set -u
cd "$(dirname "$0")/.."
CACHE="${UC_PROVISION_CACHE:-$HOME/.cache/uc-provision}"
PHP84="$CACHE/php84-root/usr/bin/uc-php84"
PHP83="$CACHE/php83-root/usr/bin/uc-php83"
PHP82="$CACHE/php82-root/usr/bin/uc-php82"

echo "=== N4D: PHP extension matrix runtime inventory ==="
for V in 84 83 82; do
  ROOT_VAR="PHP${V}_ROOT"
  BIN_VAR="PHP${V}"
  BIN="${!BIN_VAR}"
  if [ -x "$BIN" ]; then
    "$BIN" -r '
      $v = PHP_VERSION;
      $apcu = phpversion("apcu") ?: "n/a";
      $mc = phpversion("memcached") ?: "n/a";
      $exts = get_loaded_extensions();
      sort($exts);
      echo "PHP 8.'"$V"': php=$v apcu=$apcu memcached=$mc exts=".count($exts)." loaded=[".implode(",", $exts)."]\n";
    '
  else
    echo "PHP 8.$V: NOT BUILT — BLOCKED"
  fi
done

echo ""
echo "=== N4D: audit-apcu-live + audit-oc-backends under each PHP ==="
for V in 84 83 82; do
  ROOT_VAR="PHP${V}_ROOT"
  BIN_VAR="PHP${V}"
  BIN="${!BIN_VAR}"
  if [ ! -x "$BIN" ]; then
    echo "PHP 8.$V: BLOCKED (no uc-php8$V wrapper)"
    continue
  fi
  echo ""
  echo "--- PHP 8.$V: audit-apcu-live ---"
  out=$("$BIN" tests/audit-apcu-live.php 2>&1); ec=$?
  pass=$(echo "$out" | grep -cE '^\[PASS\]')
  fail=$(echo "$out" | grep -cE '^\[FAIL\]')
  skip=$(echo "$out" | grep -cE '^\[SKIP\]')
  echo "PHP8.$V | audit-apcu-live | pass=$pass | fail=$fail | skip=$skip | exit=$ec"
  [ "$fail" -gt 0 ] && echo "$out" | grep -E '^\[FAIL\]' | head -3

  echo "--- PHP 8.$V: audit-oc-backends (M + A rows) ---"
  UC_MEMCACHED_HOST=127.0.0.1 UC_MEMCACHED_PORT=11311 \
  out=$("$BIN" tests/audit-oc-backends.php 2>&1); ec=$?
  pass=$(echo "$out" | grep -cE '^\[PASS\]')
  fail=$(echo "$out" | grep -cE '^\[FAIL\]')
  skip=$(echo "$out" | grep -cE '^\[SKIP\]')
  echo "PHP8.$V | audit-oc-backends | pass=$pass | fail=$fail | skip=$skip | exit=$ec"
  [ "$fail" -gt 0 ] && echo "$out" | grep -E '^\[FAIL\]' | head -3
done
