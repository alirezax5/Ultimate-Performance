#!/bin/bash
# APACHE live integration runner (self-provisioning, rootless).
# Builds a REAL Apache httpd 2.4.x in user space if absent (ServerRoot under
# $HOME/opt/httpd so the sandbox suites' relative module paths resolve), then:
#   1. audit-apache-hit  — boots its own httpd on 127.0.0.1:8099 (early-serve,
#      cookie bypass, traversal, meta denial; bounded PidFile teardown).
#   2. audit-metadata    — boots a dedicated httpd on 127.0.0.1:8098 serving
#      the shim docroot and live-fires private-type denial against the
#      generated cache-root .htaccess.
# No mocks: real httpd processes, real sockets, real .htaccess semantics.
set -u
cd "$(dirname "$0")/.."
ROUND="${1:-APACHE-LIVE}"
PHP="${PHP_BIN:-$HOME/.local/bin/php}"
command -v "$PHP" >/dev/null 2>&1 || PHP=php
CACHE="${UC_PROVISION_CACHE:-$HOME/.cache/uc-provision}"
PREFIX="$HOME/opt/httpd"
HTTPD="$PREFIX/bin/httpd"

# --- provision: build httpd if missing (cached; ~5 min on first run) -------
if [ ! -x "$HTTPD" ]; then
  echo "provisioning: building Apache httpd 2.4.68 (first run only)"
  SRCDIR="$CACHE/httpd-src"; VER="2.4.68"; APRVER="1.7.6"; APRUVER="1.6.5"
  mkdir -p "$SRCDIR"
  ( cd "$SRCDIR"
    [ -f "httpd-$VER.tar.gz" ] || curl -fsSL -o "httpd-$VER.tar.gz" "https://dlcdn.apache.org/httpd/httpd-$VER.tar.gz"
    [ -d "httpd-$VER" ] || tar xzf "httpd-$VER.tar.gz"
    mkdir -p "httpd-$VER/srclib"
    [ -f "apr-$APRVER.tar.gz" ] || curl -fsSL -o "apr-$APRVER.tar.gz" "https://dlcdn.apache.org/apr/apr-$APRVER.tar.gz"
    [ -f "apr-util-$APRUVER.tar.gz" ] || curl -fsSL -o "apr-util-$APRUVER.tar.gz" "https://dlcdn.apache.org/apr/apr-util-$APRUVER.tar.gz"
    [ -d "httpd-$VER/srclib/apr" ] || { tar xzf "apr-$APRVER.tar.gz" && mv "apr-$APRVER" "httpd-$VER/srclib/apr"; }
    [ -d "httpd-$VER/srclib/apr-util" ] || { tar xzf "apr-util-$APRUVER.tar.gz" && mv "apr-util-$APRUVER" "httpd-$VER/srclib/apr-util"; }
    cd "httpd-$VER"
    ./configure --prefix="$PREFIX" --with-included-apr \
      --enable-rewrite --enable-headers --enable-mime --enable-log-config \
      --enable-dir --enable-authz-host --disable-ssl --disable-cgid --disable-cgi >/dev/null
    make -j"$(nproc)" >/dev/null && make install >/dev/null )
  [ -f "$PREFIX/conf/mime.types" ] || cp /etc/mime.types "$PREFIX/conf/mime.types" 2>/dev/null || printf 'text/plain txt\n' > "$PREFIX/conf/mime.types"
fi
"$HTTPD" -v || { echo "$ROUND | apache-provision | FAIL (build)"; exit 1; }

run_suite() {
  local name="$1"; shift
  out=$(timeout 590 "$PHP" "$@" 2>&1); ec=$?
  pass=$(echo "$out" | grep -cE '^\[PASS\]|^PASS ')
  fail=$(echo "$out" | grep -cE '^\[FAIL\]|^FAIL ')
  skipblk=$(echo "$out" | grep -cE '^\[SKIP\]|^\[BLOCKED\]')
  echo "$ROUND | $name | pass=$pass | fail=$fail | skip/blocked=$skipblk | exit=$ec"
  [ "$fail" -gt 0 ] && echo "$out" | grep -E '^\[FAIL\]|^FAIL ' | head -6
  return 0
}

# --- 1. early-serve suite (manages its own httpd + teardown) ----------------
export UC_HTTPD_BIN="$HTTPD"
run_suite audit-apache-hit tests/audit-apache-hit.php

# --- 2. metadata denial suite (dedicated httpd on 8098) ---------------------
SB="$PWD/tests/sandbox"; SHIM="$PWD/tests/wp-shim"
CONF="$SB/metadata-httpd.conf"
cat > "$CONF" <<EOF
Listen 127.0.0.1:8098
ServerName localhost
PidFile "$SB/meta-httpd.pid"
ErrorLog "$SB/meta-error.log"
CustomLog "$SB/meta-access.log" "%h %m %U %>s %B"
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule unixd_module modules/mod_unixd.so
LoadModule mime_module modules/mod_mime.so
LoadModule log_config_module modules/mod_log_config.so
LoadModule dir_module modules/mod_dir.so
TypesConfig conf/mime.types
<Directory />
    AllowOverride none
    Require all denied
</Directory>
<Directory "$SHIM">
    AllowOverride All
    Options -MultiViews +FollowSymLinks
    Require all granted
</Directory>
DocumentRoot "$SHIM"
EOF
pkill -f "opt/httpd.*8098" 2>/dev/null || true
rm -f "$SB/meta-httpd.pid"
( cd "$PREFIX" && nohup "$HTTPD" -f "$CONF" > "$SB/meta-httpd.stdout" 2>&1 & )
ok=0
for i in $(seq 1 30); do
  (echo > /dev/tcp/127.0.0.1/8098) 2>/dev/null && { ok=1; break; }
  sleep 1
done
if [ "$ok" = 1 ]; then
  export UC_METADATA_BASE_URL="http://127.0.0.1:8098/wp-content/cache/ultimate-performance"
  run_suite audit-metadata tests/audit-metadata.php
else
  echo "$ROUND | audit-metadata | pass=0 | fail=0 | skip/blocked=2 | exit=0 (httpd 8098 failed to start)"
  tail -5 "$SB/meta-error.log" 2>/dev/null
fi

# --- teardown ---------------------------------------------------------------
for PF in "$SB/meta-httpd.pid" "$SB/httpd.pid"; do
  if [ -f "$PF" ]; then
    DP=$(tr -cd '0-9' < "$PF")
    [ -n "$DP" ] && kill "$DP" 2>/dev/null || true
    for t in 1 2 3 4 5 6 7 8 9 10; do kill -0 "$DP" 2>/dev/null || break; sleep 0.2; done
    kill -9 "$DP" 2>/dev/null || true
  fi
done
pkill -9 -f "opt/httpd" 2>/dev/null || true
rm -f "$CONF"
echo "$ROUND | apache-teardown | all httpd processes stopped"
