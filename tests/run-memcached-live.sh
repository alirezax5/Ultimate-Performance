#!/bin/bash
# MEMCACHED + APCu live integration runner (self-provisioning, rootless).
# Provisions a real memcached daemon (Debian closure, cached) + runs the
# backend capability/matrix suite and the multisite isolation suite under the
# provisioned php8.4 stack (ext-memcached + ext-apcu), against the real daemon.
#
# N4A-FIX (resumption): the previous runner passed both -p PORT and -s SOCK to
# a SINGLE memcached process. memcached(1) documents that -s disables TCP
# entirely — so the daemon silently bound only to the UNIX socket, the
# /dev/tcp readiness probe failed, and the runner reported "memcached-start
# FAIL" while the daemon was actually up (but unreachable via TCP). The
# sandbox /dev/tcp hypothesis was a red herring — fsockopen also fails
# because there is genuinely no TCP listener.
#
# Real fix: launch THREE independent daemons (TCP A, UNIX-socket A', TCP B)
# and replace the readiness gate with a real Memcached client set/get probe
# (the strongest protocol-level proof), with PID-alive + UNIX-socket stat
# as corroborating signals. /dev/tcp is no longer authoritative.
set -u
cd "$(dirname "$0")/.."
ROUND="${1:-MEMCACHED-LIVE}"
PHP84="${UC_PHP84_BIN:-$HOME/.cache/uc-provision/php84-root/usr/bin/uc-php84}"
CACHE="${UC_PROVISION_CACHE:-$HOME/.cache/uc-provision}"
MC_BIN="$CACHE/mc-root/usr/bin/memcached"
PORT="${UC_MEMCACHED_PORT:-11311}"

[ -x "$PHP84" ] || { echo "$ROUND | php84-provision | FAIL (uc-php84 missing; provision php8.4 stack first)"; exit 1; }

# --- provision memcached daemon ----------------------------------------------
if [ ! -x "$MC_BIN" ]; then
  echo "provisioning: extracting memcached (Debian closure, first run only)"
  ROOT="$CACHE/mc-root"; DEBS="$CACHE/debs-mc"
  mkdir -p "$ROOT" "$DEBS"
  ( cd "$DEBS"
    # N4A-FIX: download one package at a time so a single missing name does
    # not abort the whole apt-get invocation. Debian 13 (trixie) t64 renamed
    # libevent-2.1-7 → libevent-2.1-7t64 — try both.
    for pkg in memcached libevent-2.1-7 libevent-2.1-7t64 libsasl2-2 libsasl2-modules-db libseccomp2 ; do
      apt-get download "$pkg" >/dev/null 2>&1 || true
    done
    for d in *.deb; do [ -e "$d" ] && dpkg -x "$d" "$ROOT"; done
  )
  [ -x "$MC_BIN" ] || { echo "$ROUND | memcached-provision | FAIL"; exit 1; }
fi
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-}:$CACHE/mc-root/usr/lib/x86_64-linux-gnu"
"$MC_BIN" -h 2>&1 | head -1 || { echo "$ROUND | memcached-provision | FAIL (not runnable)"; exit 1; }

# --- start daemons (loopback, user space) --------------------------------------
# Three independent daemons:
#   A   — TCP only on PORT            (primary TCP keyspace)
#   A'  — UNIX socket only             (separate keyspace; memcached(1) -s
#                                       disables TCP, so a single process
#                                       cannot serve both — proven by
#                                       /home/z/my-project/work/audit-mc-probe.txt)
#   B   — TCP only on PORT+1           (F9b two-daemon weak-coordination)
SOCK="$CACHE/mc-$PORT.sock"

# N4 hardening: survivors CAN cross session boundaries (observed live:
# a daemon held 11311 across tool windows and made a fresh bind die
# silently). Kill by binary path (pattern-independent of args), -9, and
# clear stale pid/socket state before any launch. Idempotent.
pkill -9 -f "mc-root/usr/bin/memcached" 2>/dev/null || true
sleep 0.3
pgrep -f "mc-root/usr/bin/memcached" >/dev/null 2>&1 && { echo "$ROUND | memcached-cleanup | FAIL (daemon survived -9)"; exit 1; }
rm -f "$CACHE"/mc-*.pid "$CACHE"/mc-*.sock "$CACHE"/mc-*.log

MCUSER="$(id -un)"

# --- launch -------------------------------------------------------------------
( "$MC_BIN" -u "$MCUSER" -l 127.0.0.1 -p "$PORT" -m 64 -P "$CACHE/mc-$PORT.pid" >"$CACHE/mc-$PORT.log" 2>&1 & )
( "$MC_BIN" -u "$MCUSER" -s "$SOCK" -a 0700 -m 64 -P "$CACHE/mc-$PORT-sock.pid" >"$CACHE/mc-$PORT-sock.log" 2>&1 & )
( "$MC_BIN" -u "$MCUSER" -l 127.0.0.1 -p "$((PORT + 1))" -m 64 -P "$CACHE/mc-$((PORT + 1)).pid" >"$CACHE/mc-$((PORT + 1)).log" 2>&1 & )

# --- readiness probe (REAL, not /dev/tcp) -------------------------------------
# Strongest proof = real Memcached client set/get roundtrip on each daemon.
# Falls back to PHP fsockopen + PID-alive as corroborating signals. /dev/tcp
# is never used because it has been observed to fail in sandboxes where the
# daemon is genuinely reachable via PHP sockets — see N4A audit notes.
PROBE_PHP='
function probe_tcp($host, $port) {
  $m = new Memcached();
  $m->setOptions(array(
    Memcached::OPT_CONNECT_TIMEOUT => 800,
    Memcached::OPT_SEND_TIMEOUT     => 800,
    Memcached::OPT_RECV_TIMEOUT     => 800,
    Memcached::OPT_POLL_TIMEOUT     => 800,
  ));
  if (!$m->addServer($host, $port)) return array(false, "addServer failed");
  $k = "uc:probe:".bin2hex(random_bytes(4));
  if (!$m->set($k, "ok", 5)) return array(false, "set rc=".$m->getResultCode()." ".$m->getResultMessage());
  $v = $m->get($k);
  if ("ok" !== $v) return array(false, "get rc=".$m->getResultCode()." val=".var_export($v, true));
  $m->delete($k);
  return array(true, "set+get+del ok");
}
function probe_socket($path) {
  $m = new Memcached();
  $m->setOptions(array(
    Memcached::OPT_CONNECT_TIMEOUT => 800,
    Memcached::OPT_SEND_TIMEOUT     => 800,
    Memcached::OPT_RECV_TIMEOUT     => 800,
    Memcached::OPT_POLL_TIMEOUT     => 800,
  ));
  if (!$m->addServer($path, 0)) return array(false, "addServer(socket) failed");
  $k = "uc:probe:".bin2hex(random_bytes(4));
  if (!$m->set($k, "ok", 5)) return array(false, "set rc=".$m->getResultCode()." ".$m->getResultMessage());
  $v = $m->get($k);
  if ("ok" !== $v) return array(false, "get rc=".$m->getResultCode()." val=".var_export($v, true));
  $m->delete($k);
  return array(true, "set+get+del ok (socket)");
}
$pt = probe_tcp($argv[1], (int)$argv[2]);
$pu = probe_socket($argv[3]);
echo json_encode(array("tcp"=>$pt, "socket"=>$pu))."\n";
exit(($pt[0] && $pu[0]) ? 0 : 1);
'

probe() { timeout 8 "$PHP84" -r "$PROBE_PHP" -- 127.0.0.1 "$PORT" "$SOCK" 2>&1; }

up=0; upsock=0; up2=0
for i in $(seq 1 30); do
  out=$(probe 2>/dev/null) || true
  case "$out" in
    *"\"tcp\":[true,"* ) up=1 ;;
    * ) : ;;
  esac
  case "$out" in
    *"\"socket\":[true,"* ) upsock=1 ;;
    * ) : ;;
  esac
  # B (port+1) — fsockopen is enough since we do not probe it with the
  # real client (the F9b row in audit-memcached-failures does that).
  ( "$PHP84" -r '$f=@fsockopen("127.0.0.1",$argv[1],$e,$s,0.5);echo($f?"1":"0");fclose($f);' -- "$((PORT+1))" 2>/dev/null | grep -q 1 ) && up2=1
  [ "$up" = 1 ] && [ "$upsock" = 1 ] && [ "$up2" = 1 ] && break
  sleep 0.5
done

if [ "$up" != 1 ]; then
  echo "$ROUND | memcached-start | FAIL (TCP daemon never answered Memcached protocol probe)"
  echo "probe output last: $out"
  echo "daemon log:"; cat "$CACHE/mc-$PORT.log" 2>/dev/null
  exit 1
fi
if [ "$upsock" != 1 ]; then
  echo "$ROUND | memcached-start-sock | FAIL (socket daemon never answered Memcached protocol probe)"
  echo "probe output last: $out"
  echo "socket daemon log:"; cat "$CACHE/mc-$PORT-sock.log" 2>/dev/null
  exit 1
fi
if [ "$up2" != 1 ]; then
  echo "$ROUND | memcached-start-B | FAIL (daemon B TCP never accepted)"
  cat "$CACHE/mc-$((PORT+1)).log" 2>/dev/null
  exit 1
fi
echo "$ROUND | memcached daemons up:"
echo "  A (TCP)   : 127.0.0.1:$PORT"
echo "  A' (UNIX) : $SOCK"
echo "  B (TCP)   : 127.0.0.1:$((PORT+1))"
echo "  probe out : $out"

run_suite() {
  local name="$1"; shift
  out=$(timeout 560 "$PHP84" "$@" 2>&1); ec=$?
  pass=$(echo "$out" | grep -cE '^\[PASS\]')
  fail=$(echo "$out" | grep -cE '^\[FAIL\]')
  skip=$(echo "$out" | grep -cE '^\[SKIP\]')
  echo "$ROUND | $name | pass=$pass | fail=$fail | skip=$skip | exit=$ec"
  if [ "$fail" -gt 0 ]; then
    echo "$out" | grep -E '^\[FAIL\]' | head -6; echo "$out" | tail -6
  elif [ "$ec" -ne 0 ]; then
    echo "$ROUND | $name | ERROR: nonzero exit with zero FAIL rows (fatal/truncation?)"; echo "$out" | tail -8
  fi
  return 0
}

# --- 1. backend capability/matrix suite (M rows full + A rows) ---------------
UC_MEMCACHED_HOST=127.0.0.1 UC_MEMCACHED_PORT="$PORT" run_suite audit-oc-backends tests/audit-oc-backends.php

# --- 2. multisite isolation against the real daemon --------------------------
UC_MS_LIVE_BACKEND=memcached UC_MEMCACHED_HOST=127.0.0.1 UC_MEMCACHED_PORT="$PORT" run_suite audit-oc-ms-live tests/audit-oc-ms-live.php

# --- 3. N4 §23 failure matrix (kill/restart/socket/stall/weak-coordination) ---
# UC_MEMCACHED_SOCKET_PIDFILE points to the UNIX-socket-only daemon so the
# audit can kill/restart it independently of the TCP daemon.
UC_MEMCACHED_HOST=127.0.0.1 UC_MEMCACHED_PORT="$PORT" UC_MEMCACHED_SOCKET="$SOCK" \
UC_MEMCACHED_PIDFILE="$CACHE/mc-$PORT.pid" UC_MEMCACHED_BIN="$MC_BIN" \
UC_MEMCACHED_RUNNER_USER="$MCUSER" UC_MEMCACHED_PORT2="$((PORT + 1))" \
UC_MEMCACHED_SOCKET_PIDFILE="$CACHE/mc-$PORT-sock.pid" \
run_suite audit-memcached-failures tests/audit-memcached-failures.php

# --- teardown ------------------------------------------------------------------
for P in "$PORT" "$((PORT + 1))"; do
  if [ -f "$CACHE/mc-$P.pid" ]; then
    DP=$(tr -cd '0-9' < "$CACHE/mc-$P.pid"); kill "$DP" 2>/dev/null || true
  fi
done
[ -f "$CACHE/mc-$PORT-sock.pid" ] && { DP=$(tr -cd '0-9' < "$CACHE/mc-$PORT-sock.pid"); kill "$DP" 2>/dev/null || true; }
sleep 0.5
pkill -9 -f "mc-root/usr/bin/memcached" 2>/dev/null || true
rm -f "$CACHE"/mc-*.pid "$CACHE"/mc-*.sock "$CACHE"/mc-*.log
echo "$ROUND | teardown | memcached daemons stopped"
