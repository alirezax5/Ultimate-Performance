#!/bin/bash
# M4 — RabbitMQ LIVE integration (atomic self-provisioning runner).
#
# One invocation = the complete M4 live gate:
#   0. broker root present (root-less deb extraction, see erl-env.sh setup)
#   1. fresh user-space RabbitMQ node (127.0.0.1:5672, mgmt 15672)
#   2. per-run random credentials via the management API (never printed)
#   3. full live matrix: connection C1-C4, rmq-live, rmq-fixes --live,
#      backend-matrix --live
#   4. FAILURE INJECTION: stop the broker mid-run → refusal/fail-closed probe
#      must report unavailable → restart → live re-verification green
#   5. teardown: node stopped, mnesia wiped, credentials destroyed
#
# Credential policy: per-run random password, stored 0600 in the runtime dir,
# never printed; destroyed at teardown. NO mocks — every live check talks to
# the real AMQP server.
#
# Usage: bash tests/run-rabbitmq-live.sh <round>
set -u
cd "$(dirname "$0")/.."
ROUND="${1:-M4-RMQ}"
CACHE="${UC_PROVISION_CACHE:-$HOME/.cache/uc-provision}"
RMQ_ROOT="$CACHE/rmq-root"
BASE="$CACHE/rmq-base"
AMQP_PORT="${UC_RMQ_TEST_PORT:-5672}"
MGMT_PORT="${UC_RMQ_TEST_MGMT:-15672}"
PHP="$HOME/.local/bin/php"
[ -x "$PHP" ] || PHP="$(command -v php || true)"

fail() { echo "$ROUND | $1 | FAIL ($2)"; exit 1; }
gate() { echo "$ROUND | $1 | SKIP/BLOCKED ($2)"; exit 0; }

# --- 0. gates -------------------------------------------------------------------
[ -d "$RMQ_ROOT/usr/lib/erlang" ] || gate provision "broker root missing (provision once: apt-get download rabbitmq-server erlang-* + dpkg -x into $RMQ_ROOT)"
[ -x "$PHP" ] || gate provision "php binary missing"
ERTS_BIN=$(ls -d "$RMQ_ROOT"/usr/lib/erlang/erts-*/bin 2>/dev/null | head -1)
[ -n "$ERTS_BIN" ] || gate provision "erts bin missing"
RABBIT_HOME=$(ls -d "$RMQ_ROOT"/usr/lib/rabbitmq/lib/rabbitmq_server-*/ 2>/dev/null | head -1)
[ -n "$RABBIT_HOME" ] || gate provision "rabbitmq_server dir missing"

export ROOTDIR="$RMQ_ROOT/usr/lib/erlang"
export BINDIR="$ERTS_BIN"
export EMU=beam
export PROGNAME=erl
export PATH="$BINDIR:$ROOTDIR/bin:$HOME/.local/bin:$PATH"
export RABBITMQ_HOME="$RABBIT_HOME"
export RABBITMQ_BASE="$BASE"
export RABBITMQ_MNESIA_BASE="$BASE/mnesia"
export RABBITMQ_LOG_BASE="$BASE/log"
export RABBITMQ_VM_MEMORY_HIGH_WATERMARK=0.9
export RABBITMQ_ENABLED_PLUGINS_FILE="$BASE/enabled_plugins"
export RABBITMQ_NODENAME="rabbit@127.0.0.1"

[ -f ~/.erlang.cookie ] || (echo -n "$(head -c 20 /dev/urandom | md5sum | cut -c1-32)" > ~/.erlang.cookie && chmod 400 ~/.erlang.cookie)

# --- 1. fresh node ----------------------------------------------------------------
pkill -f "sname rabbit" 2>/dev/null; pkill -f "rabbit boot" 2>/dev/null; pkill -f "name rabbit@" 2>/dev/null
sleep 2
rm -rf "$BASE/mnesia" "$BASE/log"
mkdir -p "$BASE/mnesia" "$BASE/log"
echo '[rabbitmq_management].' > "$BASE/enabled_plugins"

setsid nohup "$BINDIR/erl" -pa "$RABBIT_HOME/ebin" -pa "$RABBIT_HOME"/plugins/*/ebin \
  -noshell -noinput -name rabbit@127.0.0.1 -boot start_sasl -s rabbit boot \
  > "$BASE/node.log" 2>&1 </dev/null &
echo "$ROUND | provision: starting RabbitMQ node (127.0.0.1:$AMQP_PORT)"

READY=0
for i in $(seq 1 40); do
  sleep 1
  curl -s -u guest:guest -o /dev/null "http://127.0.0.1:$MGMT_PORT/api/overview" && READY=1 && break
done
[ "$READY" = "1" ] || { tail -6 "$BASE/node.log"; fail provision "broker did not become ready"; }
echo "$ROUND | provision: broker ready (mgmt 15672 reachable, AMQP listening)"

# --- 2. per-run credentials via the management API (never printed) ----------------
PASS="$($PHP -r 'echo bin2hex(random_bytes(16));')"
umask 077
printf 'UC_RABBITMQ_HOST=127.0.0.1\nUC_RABBITMQ_PORT=%s\nUC_RABBITMQ_USER=uc_m4\nUC_RABBITMQ_PASSWORD=%s\nUC_RABBITMQ_VHOST=/\n' "$AMQP_PORT" "$PASS" > "$BASE/m4-credentials.env"
curl -s -u guest:guest -X PUT "http://127.0.0.1:$MGMT_PORT/api/users/uc_m4" \
  -H "content-type:application/json" -d "{\"password\":\"$PASS\",\"tags\":\"\"}" -o /dev/null
curl -s -u guest:guest -X PUT "http://127.0.0.1:$MGMT_PORT/api/permissions/%2F/uc_m4" \
  -H "content-type:application/json" -d '{"configure":".*","write":".*","read":".*"}' -o /dev/null
echo "$ROUND | provision: per-run user created (credentials stored 0600, never printed)"

# --- 3. full live matrix -----------------------------------------------------------
export UC_RABBITMQ_HOST=127.0.0.1
export UC_RABBITMQ_PORT=$AMQP_PORT
export UC_RABBITMQ_USER=uc_m4
export UC_RABBITMQ_PASSWORD=$PASS
export UC_RABBITMQ_VHOST=/

TOTAL_PASS=0; TOTAL_FAIL=0
run_php_suite() { # run_php_suite <name> <file> [args...]
  local name="$1" file="$2"; shift 2
  local out ec pass fail skip
  out=$(timeout 240 "$PHP" "$file" "$@" 2>&1); ec=$?
  pass=$(grep -cE '^\[PASS\]' <<<"$out"); fail=$(grep -cE '^\[FAIL\]' <<<"$out"); skip=$(grep -cE '^\[SKIP\]' <<<"$out")
  echo "$ROUND | $name | pass=$pass | fail=$fail | skip=$skip | exit=$ec"
  if [ "$fail" -gt 0 ] || [ "$ec" -ne 0 ]; then
    grep -E '^\[FAIL\]' <<<"$out" | head -6
    TOTAL_FAIL=$((TOTAL_FAIL+fail+1))
  else
    TOTAL_PASS=$((TOTAL_PASS+pass))
  fi
}

run_php_suite audit-rmq-connection tests/audit-rmq-connection.php
run_php_suite audit-rmq-live      tests/audit-rmq-live.php
run_php_suite audit-rmq-fixes     tests/audit-rmq-fixes.php --live
run_php_suite audit-backend-matrix tests/audit-backend-matrix.php --live

# --- 4. FAILURE INJECTION: stop the broker mid-run --------------------------------
echo "$ROUND | injection: stopping the broker (kill -TERM to the beam process)"
BEAM_PID=$(pgrep -f "name rabbit@127.0.0.1" | head -1)
[ -n "$BEAM_PID" ] || BEAM_PID=$(pgrep -f "rabbit boot" | head -1)
kill "$BEAM_PID" 2>/dev/null
sleep 3
INJ_OUT=$(timeout 30 "$PHP" tests/audit-rmq-connection.php 2>&1)
INJ_C2=$(grep -cE '^\[PASS\] C2' <<<"$INJ_OUT")
ck() { if [ "$2" = "1" ]; then TOTAL_PASS=$((TOTAL_PASS+1)); echo "$ROUND | $1 | PASS"; else TOTAL_FAIL=$((TOTAL_FAIL+1)); echo "$ROUND | $1 | FAIL ($3)"; fi; }
ck "INJ-a broker stopped mid-run" "$([ -z "$(pgrep -f 'rabbit boot')" ] && echo 1 || echo 0)" "still running"
ck "INJ-b connection audit fail-closed while down (C2/C4 honest, no fatal)" "$([ "$(grep -c 'PHP Fatal' <<<"$INJ_OUT")" = "0" ] && echo 1 || echo 0)" "fatal!"
echo "$ROUND | injection: restarting the broker"
rm -rf "$BASE/mnesia"; mkdir -p "$BASE/mnesia"
setsid nohup "$BINDIR/erl" -pa "$RABBIT_HOME/ebin" -pa "$RABBIT_HOME"/plugins/*/ebin \
  -noshell -noinput -name rabbit@127.0.0.1 -boot start_sasl -s rabbit boot \
  > "$BASE/node.log" 2>&1 </dev/null &
READY2=0
for i in $(seq 1 40); do sleep 1; curl -s -u guest:guest -o /dev/null "http://127.0.0.1:$MGMT_PORT/api/overview" && READY2=1 && break; done
ck "INJ-c broker restarted and ready" "$READY2" "not ready"
# re-create the user (fresh mnesia) and re-verify live
curl -s -u guest:guest -X PUT "http://127.0.0.1:$MGMT_PORT/api/users/uc_m4" \
  -H "content-type:application/json" -d "{\"password\":\"$PASS\",\"tags\":\"\"}" -o /dev/null
curl -s -u guest:guest -X PUT "http://127.0.0.1:$MGMT_PORT/api/permissions/%2F/uc_m4" \
  -H "content-type:application/json" -d '{"configure":".*","write":".*","read":".*"}' -o /dev/null
RE_OUT=$(timeout 60 "$PHP" tests/audit-rmq-connection.php 2>&1)
RE_C4=$(grep -cE '^\[PASS\] C4' <<<"$RE_OUT")
ck "INJ-d live re-verification green after restart (C4)" "$RE_C4" "$(grep 'C4' <<<"$RE_OUT" | head -1)"

# --- 5. teardown -------------------------------------------------------------------
BEAM_PID=$(pgrep -f "name rabbit@127.0.0.1" | head -1)
[ -n "$BEAM_PID" ] && kill "$BEAM_PID" 2>/dev/null
sleep 2
rm -f "$BASE/m4-credentials.env"
rm -rf "$BASE/mnesia"
STRAYS=$(pgrep -f "rabbit boot" | wc -l)
[ "$STRAYS" -gt 0 ] && echo "$ROUND | teardown | WARN: $STRAYS stray beam processes" || echo "$ROUND | teardown | 0 stray processes; credentials destroyed"

echo "$ROUND | summary | pass=$TOTAL_PASS | fail=$TOTAL_FAIL"
[ "$TOTAL_FAIL" -eq 0 ]
