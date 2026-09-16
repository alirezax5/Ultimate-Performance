#!/bin/bash
# Phase O — Provision a real MariaDB server (rootless, user-space).
#
# Strategy: download the Debian trixie MariaDB packages, extract them to
# a user-space prefix, set up a fresh data directory owned by the
# current user, and start the daemon on a loopback high port with a
# per-run random password. NEVER commits the password to disk in the
# repo — it is printed once to stdout in a structured line that the
# caller captures.
#
# Idempotent: re-running detects an existing provisioned tree and only
# re-downloads missing packages or rebuilds the data dir if absent.
#
# Output:
#   $UC_PROVISION_CACHE/mariadb-root/       extraction target
#   $UC_PROVISION_CACHE/mariadb-data/       data directory (per-run, ephemeral)
#   $UC_PROVISION_CACHE/mariadb-root.sock   UNIX socket (per-run)
#   $UC_PROVISION_CACHE/mariadb-root.pid    PID file (per-run)
#   $UC_PROVISION_CACHE/mariadb-root.log    daemon log (per-run)
#
# Stdout:
#   MARIA_HOST=127.0.0.1
#   MARIA_PORT=13306
#   MARIA_SOCKET=<path>
#   MARIA_USER=root
#   MARIA_PASSWORD=<random>
#   MARIA_PID=<pid>
#   MARIA_VERSION=<version>
set -u
CACHE="${UC_PROVISION_CACHE:-$HOME/.cache/uc-provision}"
MROOT="$CACHE/mariadb-root"
MDATA="$CACHE/mariadb-data"
MSOCK="$CACHE/mariadb-root.sock"
MPID="$CACHE/mariadb-root.pid"
MLOG="$CACHE/mariadb-root.log"
MDEBS="$CACHE/debs-mariadb"
MPORT="${UC_MARIADB_PORT:-13306}"

mkdir -p "$MROOT" "$MDEBS"

# --- 1. provision (cached) ---------------------------------------------------
if [ ! -x "$MROOT/usr/sbin/mariadbd" ]; then
  echo "provision-mariadb: downloading Debian trixie closure (first run only)" >&2
  (
    cd "$MDEBS"
    # The package set MariaDB needs at runtime.
    for pkg in \
      mariadb-server mariadb-server-core mariadb-client mariadb-client-core mariadb-common \
      libmariadb3 libpcre2-8-0 libcrypt1 libzstd1 liblz4-1 libxml2 libgnutls30t64 \
      libcom-err2 libkeyutils1 libkrb5-3 libk5crypto3 libkrb5support0 libssl3t64 \
      libtirpc3t64 libgssapi-krb5-2 libmd0 libsystemd0 liblzma5 libgcrypt20 libgpg-error0 \
      liburing2 libaio1t64 libncurses6 libtinfo6 \
    ; do
      apt-get download "$pkg" >/dev/null 2>&1 || true
    done
    for d in *.deb; do [ -e "$d" ] && dpkg -x "$d" "$MROOT"; done
  )
fi
[ -x "$MROOT/usr/sbin/mariadbd" ] || { echo "provision-mariadb: FAIL (no mariadbd binary)" >&2; exit 1; }

# --- 2. paths + lib loaders ---------------------------------------------------
export LD_LIBRARY_PATH="$MROOT/usr/lib/x86_64-linux-gnu:$MROOT/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"

MARIADBD="$MROOT/usr/sbin/mariadbd"
MARIADB="$MROOT/usr/bin/mariadb"
MARIADB_INSTALL_DB="$MROOT/usr/bin/mariadb-install-db"

MVER=$("$MARIADBD" --version 2>&1 | head -1 | sed -E 's/.*Ver ([0-9][0-9.a-z-]+).*/\1/')

# --- 3. fresh data directory --------------------------------------------------
# Always reset: this is a per-run disposable daemon.
pkill -9 -f "$MARIADBD" 2>/dev/null || true
sleep 0.3
rm -rf "$MDATA" "$MSOCK" "$MPID" "$MLOG"
mkdir -p "$MDATA"

# Use mariadb-install-db to bootstrap a minimal datadir under our prefix.
# Percona/MariaDB's install-db creates the system tables + a root user
# that can connect from localhost without a password (then we set one).
export MARIADB_HOME="$MROOT/usr"
"$MARIADB_INSTALL_DB" \
  --basedir="$MROOT/usr" \
  --datadir="$MDATA" \
  --user="$(id -un)" \
  --auth-root-authentication-method=normal \
  >/dev/null 2>&1 || {
    echo "provision-mariadb: install-db failed, tail of stderr follows:" >&2
    "$MARIADB_INSTALL_DB" --basedir="$MROOT/usr" --datadir="$MDATA" --user="$(id -un)" 2>&1 | tail -10 >&2
    exit 1
  }

# --- 4. start the daemon ------------------------------------------------------
MPASS=$(head -c 24 /dev/urandom | base64 | tr -d '=+/' | head -c 24)

( "$MARIADBD" \
    --basedir="$MROOT/usr" \
    --datadir="$MDATA" \
    --socket="$MSOCK" \
    --port="$MPORT" \
    --bind-address=127.0.0.1 \
    --pid-file="$MPID" \
    --user="$(id -un)" \
    --skip-networking=false \
    --innodb-buffer-pool-size=64M \
    --innodb-flush-method=O_DIRECT \
    --max-connections=50 \
    --skip-name-resolve \
    --sql-mode=STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION \
    --character-set-server=utf8mb4 \
    --collation-server=utf8mb4_unicode_ci \
    --default-storage-engine=InnoDB \
    --innodb-flush-log-at-trx-commit=1 \
    >"$MLOG" 2>&1 & )

# --- 5. wait for readiness ----------------------------------------------------
up=0
for i in $(seq 1 60); do
  if "$MARIADB" --socket="$MSOCK" --user=root -e 'SELECT 1' >/dev/null 2>&1; then
    up=1
    break
  fi
  sleep 0.5
done
if [ "$up" != 1 ]; then
  echo "provision-mariadb: FAIL (daemon never answered)" >&2
  tail -20 "$MLOG" >&2
  exit 1
fi

# Set the root password (root@localhost has no password initially after install-db).
"$MARIADB" --socket="$MSOCK" --user=root <<SQL >/dev/null 2>&1
ALTER USER 'root'@'localhost' IDENTIFIED BY '$MPASS';
FLUSH PRIVILEGES;
SQL
[ $? -eq 0 ] || { echo "provision-mariadb: FAIL (could not set root password)" >&2; exit 1; }

# Capture the PID once the daemon is stable
MPIDVAL=$(cat "$MPID" 2>/dev/null || echo "")

echo "MARIA_HOST=127.0.0.1"
echo "MARIA_PORT=$MPORT"
echo "MARIA_SOCKET=$MSOCK"
echo "MARIA_USER=root"
echo "MARIA_PASSWORD=$MPASS"
echo "MARIA_PID=$MPIDVAL"
echo "MARIA_VERSION=$MVER"
echo "MARIA_DATA=$MDATA"
echo "MARIA_LOG=$MLOG"
echo "provision-mariadb: OK (MariaDB $MVER on 127.0.0.1:$MPORT + $MSOCK, pid=$MPIDVAL)" >&2
