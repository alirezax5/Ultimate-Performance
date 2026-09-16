#!/bin/bash
# Phase O — Provision a real RabbitMQ server (rootless, user-space).
#
# Strategy: RabbitMQ depends on Erlang/OTP. Debian trixie ships
# rabbitmq-server as a .deb that needs erlang-base. We download
# both, extract to user-space, and start the broker on loopback
# with a per-run user/password/vhost (NEVER printed to git).
set -u
CACHE="${UC_PROVISION_CACHE:-$HOME/.cache/uc-provision}"
RMQROOT="$CACHE/rabbitmq-root"
RMQDEBS="$CACHE/debs-rmq"
RMQPID="$CACHE/rabbitmq.pid"
RMQLOG="$CACHE/rabbitmq.log"
RMQPORT="${UC_RABBITMQ_PORT:-15672}"
RMQMNGPORT=$((RMQPORT + 10000))

mkdir -p "$RMQROOT" "$RMQDEBS" "$RMQROOT/var/lib/rabbitmq" "$RMQROOT/var/log/rabbitmq" "$RMQROOT/etc/rabbitmq"

if [ ! -x "$RMQROOT/usr/lib/rabbitmq/bin/rabbitmq-server" ] && [ ! -x "$RMQROOT/usr/sbin/rabbitmq-server" ]; then
  echo "provision-rabbitmq: downloading Debian trixie closure (first run only, ~50MB)" >&2
  (
    cd "$RMQDEBS"
    # Erlang core
    for pkg in erlang-base erlang-crypto erlang-mnesia erlang-os-mon erlang-public-key erlang-runtime-tools erlang-sasl erlang-ssl erlang-syntax-tools erlang-tools erlang-xmerl erlang-eldap erlang-asn1 erlang-inets erlang-erts erlang-kernel erlang-stdlib rabbitmq-server rabbitmq-common adduser; do
      apt-get download "$pkg" >/dev/null 2>&1 || true
    done
    for d in *.deb; do [ -e "$d" ] && dpkg -x "$d" "$RMQROOT"; done
  )
fi
RMQ_BIN=""
for p in "$RMQROOT/usr/lib/rabbitmq/bin/rabbitmq-server" "$RMQROOT/usr/sbin/rabbitmq-server"; do
  [ -x "$p" ] && RMQ_BIN="$p" && break
done
[ -n "$RMQ_BIN" ] || { echo "provision-rabbitmq: FAIL (no rabbitmq-server binary)" >&2; exit 1; }

export LD_LIBRARY_PATH="$RMQROOT/usr/lib/x86_64-linux-gnu:$RMQROOT/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
export RABBITMQ_HOME="$RMQROOT/usr/lib/rabbitmq"
export RABBITMQ_LOG_BASE="$RMQROOT/var/log/rabbitmq"
export RABBITMQ_MNESIA_BASE="$RMQROOT/var/lib/rabbitmq"
export RABBITMQ_PID_FILE="$RMQPID"
export RABBITMQ_ENABLED_PLUGINS_FILE="$RMQROOT/etc/rabbitmq/enabled_plugins"
export RABBITMQ_CONFIG_FILE="$RMQROOT/etc/rabbitmq/rabbitmq"
export ERL_BIN=$(ls -d $RMQROOT/usr/lib/erlang/erts-*/bin 2>/dev/null | head -1)
export PATH="$ERL_BIN:$RMQROOT/usr/lib/erlang/bin:$RMQROOT/usr/lib/rabbitmq/bin:$RMQROOT/usr/sbin:$RMQROOT/usr/bin:$PATH"
export ERL_DIR="$ERL_BIN"

cat > "$RMQROOT/etc/rabbitmq/rabbitmq.conf" <<EOF
listeners.tcp.default = 127.0.0.1:$RMQPORT
management.tcp.ip = 127.0.0.1
management.tcp.port = $RMQMNGPORT
loopback_users.guest = false
EOF
echo '[rabbitmq_management].' > "$RMQROOT/etc/rabbitmq/enabled_plugins"

pkill -9 -f "rabbitmq-server|beam.smp" 2>/dev/null || true
sleep 0.5
rm -rf "$RMQROOT/var/lib/rabbitmq/"* "$RMQPID"

# Start in background — RabbitMQ takes ~10s to fully boot
( "$RMQ_BIN" > "$RMQLOG" 2>&1 & )

up=0
for i in $(seq 1 60); do
  if "$RMQROOT/usr/lib/erlang/erts-*/bin/escript" 2>/dev/null; then :; fi
  # Use a TCP probe instead — wait until the broker port accepts
  if curl -s -o /dev/null -w "%{http_code}" --max-time 1 http://127.0.0.1:$RMQMNGPORT/api/overview 2>/dev/null | grep -qE "^(200|401)$"; then
    up=1
    break
  fi
  # also try AMQP port probe
  /home/z/.cache/uc-provision/php84-root/usr/bin/uc-php84 -r '$f=@fsockopen("127.0.0.1",'$RMQPORT',$e,$s,1.0);if($f){fclose($f);echo "UP";}else{echo "DOWN";}' 2>/dev/null | grep -q UP && { up=1; break; }
  sleep 1
done

if [ "$up" != 1 ]; then
  echo "provision-rabbitmq: FAIL (broker never answered)" >&2
  tail -20 "$RMQLOG" >&2
  exit 1
fi

RMQVER=$(grep -oE 'RabbitMQ.*"version"' "$RMQLOG" 2>/dev/null | head -1 || echo "unknown")
[ -z "$RMQVER" ] && RMQVER="unknown"

# Generate per-run credentials
RMQ_USER="uc$(date +%s | tail -c 5)"
RMQ_PASS=$(head -c 16 /dev/urandom | base64 | tr -d '=+/' | head -c 16)
RMQ_VHOST="uc"

# Create user + vhost via management API (default guest works on loopback)
"$RMQROOT/usr/sbin/rabbitmqctl" --node "rabbit@$(hostname -s)" add_vhost "$RMQ_VHOST" >/dev/null 2>&1 || true
"$RMQROOT/usr/sbin/rabbitmqctl" --node "rabbit@$(hostname -s)" add_user "$RMQ_USER" "$RMQ_PASS" >/dev/null 2>&1 || true
"$RMQROOT/usr/sbin/rabbitmqctl" --node "rabbit@$(hostname -s)" set_permissions -p "$RMQ_VHOST" "$RMQ_USER" ".*" ".*" ".*" >/dev/null 2>&1 || true

echo "RABBITMQ_HOST=127.0.0.1"
echo "RABBITMQ_PORT=$RMQPORT"
echo "RABBITMQ_MNG_PORT=$RMQMNGPORT"
echo "RABBITMQ_USER=$RMQ_USER"
echo "RABBITMQ_PASSWORD=$RMQ_PASS"
echo "RABBITMQ_VHOST=$RMQ_VHOST"
echo "RABBITMQ_PID=$(cat $RMQPID 2>/dev/null || echo unknown)"
echo "RABBITMQ_VERSION=$RMQVER"
echo "RABBITMQ_LOG=$RMQLOG"
echo "provision-rabbitmq: OK (RabbitMQ on 127.0.0.1:$RMQPORT + mgmt $RMQMNGPORT, user=$RMQ_USER vhost=$RMQ_VHOST)" >&2
