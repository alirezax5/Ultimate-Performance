#!/bin/bash
# Phase O — Provision a real Redis server (rootless, user-space).
#
# Strategy: download the Debian trixie redis-server package, extract it,
# and start the daemon on a loopback high port with no TCP auth (loopback
# only). Redis does not need a data dir for our tests; AOF/RDB disabled
# (no persistence across runs).
#
# Idempotent: re-running detects an existing binary, only restarts the daemon.
set -u
CACHE="${UC_PROVISION_CACHE:-$HOME/.cache/uc-provision}"
RROOT="$CACHE/redis-root"
RDEBS="$CACHE/debs-redis"
RSOCK="$CACHE/redis.sock"
RPID="$CACHE/redis.pid"
RLOG="$CACHE/redis.log"
RPORT="${UC_REDIS_PORT:-16379}"

mkdir -p "$RROOT" "$RDEBS"

# --- 1. provision (cached) ---------------------------------------------------
if [ ! -x "$RROOT/usr/bin/redis-server" ]; then
  echo "provision-redis: downloading Debian trixie closure (first run only)" >&2
  (
    cd "$RDEBS"
    for pkg in redis-server redis-tools libjemalloc2 libssl3t64 libsystemd0 libgcc-s1 libstdc++6 liblzf1; do
      apt-get download "$pkg" >/dev/null 2>&1 || true
    done
    for d in *.deb; do [ -e "$d" ] && dpkg -x "$d" "$RROOT"; done
  )
fi
[ -x "$RROOT/usr/bin/redis-server" ] || { echo "provision-redis: FAIL (no redis-server binary)" >&2; exit 1; }

export LD_LIBRARY_PATH="$RROOT/usr/lib/x86_64-linux-gnu:$RROOT/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"

# --- 2. start (fresh each run) -----------------------------------------------
pkill -9 -f "$RROOT/usr/bin/redis-server" 2>/dev/null || true
sleep 0.3
rm -f "$RSOCK" "$RPID" "$RLOG"

"$RROOT/usr/bin/redis-server" \
  --port "$RPORT" \
  --bind 127.0.0.1 \
  --protected-mode no \
  --save "" \
  --appendonly no \
  --maxmemory 64mb \
  --maxmemory-policy allkeys-lru \
  --logfile "$RLOG" \
  --daemonize yes \
  --pidfile "$RPID" \
  --dir "$RROOT"

# --- 3. wait for readiness ---------------------------------------------------
up=0
for i in $(seq 1 30); do
  "$RROOT/usr/bin/redis-cli" -h 127.0.0.1 -p "$RPORT" PING 2>/dev/null | grep -q PONG && { up=1; break; }
  sleep 0.3
done
if [ "$up" != 1 ]; then
  echo "provision-redis: FAIL (daemon never answered PING)" >&2
  tail -10 "$RLOG" >&2
  exit 1
fi

RPIDVAL=$(cat "$RPID" 2>/dev/null || echo "")
RVER=$("$RROOT/usr/bin/redis-server" --version 2>&1 | head -1 | sed -E 's/.*v=([0-9.]+).*/\1/')

echo "REDIS_HOST=127.0.0.1"
echo "REDIS_PORT=$RPORT"
echo "REDIS_PID=$RPIDVAL"
echo "REDIS_VERSION=$RVER"
echo "REDIS_LOG=$RLOG"
echo "provision-redis: OK (Redis $RVER on 127.0.0.1:$RPORT, pid=$RPIDVAL)" >&2
