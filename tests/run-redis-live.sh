#!/bin/bash
# REDIS live integration runner (self-provisioning, rootless).
# Extracts a REAL redis-server from the Debian package closure into user space
# (cached), then runs audit-object-cache-live.php against it. The suite spawns,
# kills (fail-closed phase), restarts and stops its own server instance.
# No mocks: real server, real sockets, real concurrency, real shutdown.
set -u
cd "$(dirname "$0")/.."
ROUND="${1:-REDIS-LIVE}"
PHP="${PHP_BIN:-$HOME/.local/bin/php}"
command -v "$PHP" >/dev/null 2>&1 || PHP=php
CACHE="${UC_PROVISION_CACHE:-$HOME/.cache/uc-provision}"
REDIS_BIN="$CACHE/redis-root/usr/bin/redis-server"

# --- provision: extract redis-server + shared-library closure ----------------
if [ ! -x "$REDIS_BIN" ]; then
  echo "provisioning: extracting redis-server (Debian closure, first run only)"
  ROOT="$CACHE/redis-root"; DEBS="$CACHE/debs-redis"
  mkdir -p "$ROOT" "$DEBS"
  ( cd "$DEBS"
    # quiet download of the package closure (existing files are reused)
    # NOTE: trixie's redis-server package ships only the symlink; the real
    # binary lives in redis-tools. liblzf1 is a runtime dep.
    apt-get download redis-server redis-tools libjemalloc2 liblua5.1-0 liblzf1 libssl3 libatomic1 >/dev/null 2>&1 || \
      apt-get download redis-server redis-tools libjemalloc2 liblua5.1-0 liblzf1 >/dev/null 2>&1 || \
      apt-get download redis-server >/dev/null 2>&1
    for d in *.deb; do [ -e "$d" ] && dpkg -x "$d" "$ROOT"; done
  )
  [ -x "$REDIS_BIN" ] || { echo "$ROUND | redis-provision | FAIL (no redis-server extracted)"; exit 1; }
fi

echo "$ROUND | redis binary: $REDIS_BIN"
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-}:$CACHE/redis-root/usr/lib/x86_64-linux-gnu"
"$REDIS_BIN" --version | head -1 || { echo "$ROUND | redis-provision | FAIL (binary not runnable)"; exit 1; }

# --- run the live suite (manages its own server instance) --------------------
out=$(UC_REDIS_SERVER_BIN="$REDIS_BIN" timeout 560 "$PHP" tests/audit-object-cache-live.php 2>&1)
ec=$?
pass=$(echo "$out" | grep -cE '^\[PASS\]')
fail=$(echo "$out" | grep -cE '^\[FAIL\]')
skip=$(echo "$out" | grep -cE '^\[SKIP\]')
echo "$ROUND | audit-object-cache-live | pass=$pass | fail=$fail | skip=$skip | exit=$ec"
if [ "$fail" -gt 0 ]; then
  echo "$out" | grep -E '^\[FAIL\]|FAIL:' | head -8
  echo "--- suite tail ---"
  echo "$out" | tail -12
  exit 1
fi
echo "$out" | tail -4
echo "$ROUND | teardown | suite-owned server stopped; no stray redis processes:"
pgrep -f "redis-server 127.0.0.1" >/dev/null 2>&1 && { echo "STRAY PROCESS FOUND"; pgrep -af redis-server | head -3; } || echo "$ROUND | teardown | 0 strays"
