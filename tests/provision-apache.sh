#!/bin/bash
# Phase O — Provision a real Apache HTTPD (rootless, user-space).
#
# Strategy: download the Debian trixie apache2 packages, extract them,
# and start httpd on a loopback high port using a per-run minimal config.
# PHP is wired via the mod_php from our provisioned PHP CLI (CGI/FPM
# not used — we use `php -S` as a reverse-proxy origin for the cache
# audit, which is the same approach as tests/run-apache-live.sh).
#
# Idempotent: re-running detects an existing binary, only restarts the daemon.
set -u
CACHE="${UC_PROVISION_CACHE:-$HOME/.cache/uc-provision}"
AROOT="$CACHE/apache-root"
ADEBS="$CACHE/debs-apache"
APID="$CACHE/apache.pid"
ALOG="$CACHE/apache.log"
AERR="$CACHE/apache-error.log"
APORT="${UC_APACHE_PORT:-18080}"

mkdir -p "$AROOT" "$ADEBS" "$AROOT/var/log/apache2" "$AROOT/var/run" "$AROOT/var/lock"

# --- 1. provision (cached) ---------------------------------------------------
if [ ! -x "$AROOT/usr/sbin/apache2" ]; then
  echo "provision-apache: downloading Debian trixie closure (first run only)" >&2
  (
    cd "$ADEBS"
    for pkg in apache2 apache2-bin apache2-utils apache2-data apache2-doc \
      libapr1t64 libaprutil1t64 libaprutil1-dbd-sqlite3 libaprutil1-ldap \
      libldap-2.5-0 liblua5.3-0 libxml2 libnghttp2-14 libjansson4 \
      libbrotli1 libcurl4t64-gnutls libssl3t64 libpcre2-8-0; do
      apt-get download "$pkg" >/dev/null 2>&1 || true
    done
    for d in *.deb; do [ -e "$d" ] && dpkg -x "$d" "$AROOT"; done
  )
fi
[ -x "$AROOT/usr/sbin/apache2" ] || { echo "provision-apache: FAIL (no apache2 binary)" >&2; exit 1; }

export LD_LIBRARY_PATH="$AROOT/usr/lib/x86_64-linux-gnu:$AROOT/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"

# --- 2. minimal config -------------------------------------------------------
ACONF="$AROOT/etc/apache2/apache2.conf"
SRVROOT="$AROOT"
DOCROOT="$AROOT/var/www/html"
mkdir -p "$DOCROOT"
echo '<!DOCTYPE html><html><body><h1>Apache works</h1></body></html>' > "$DOCROOT/index.html"

# Build a minimal apache2.conf that loads just enough modules.
cat > "$ACONF" <<EOF
ServerRoot "$SRVROOT"
Listen 127.0.0.1:$APORT
User $(id -un)
Group $(id -gn)
PidFile "$APID"
ErrorLog "$AERR"
LogLevel info
Timeout 30
KeepAlive On
MaxKeepAliveRequests 100
KeepAliveTimeout 5

# Modules live in $SRVROOT/usr/lib/apache2/modules (Debian layout under extraction)
LoadModule mpm_event_module ${SRVROOT}/usr/lib/apache2/modules/mod_mpm_event.so
LoadModule authz_core_module ${SRVROOT}/usr/lib/apache2/modules/mod_authz_core.so
LoadModule authz_host_module ${SRVROOT}/usr/lib/apache2/modules/mod_authz_host.so
LoadModule mime_module ${SRVROOT}/usr/lib/apache2/modules/mod_mime.so
LoadModule dir_module ${SRVROOT}/usr/lib/apache2/modules/mod_dir.so
LoadModule headers_module ${SRVROOT}/usr/lib/apache2/modules/mod_headers.so
LoadModule setenvif_module ${SRVROOT}/usr/lib/apache2/modules/mod_setenvif.so
LoadModule alias_module ${SRVROOT}/usr/lib/apache2/modules/mod_alias.so
LoadModule rewrite_module ${SRVROOT}/usr/lib/apache2/modules/mod_rewrite.so

ServerName localhost
TypesConfig /etc/mime.types
DirectoryIndex index.html index.php
DocumentRoot "$DOCROOT"

<Directory "$DOCROOT">
  AllowOverride All
  Options -MultiViews +FollowSymLinks
  Require all granted
</Directory>
EOF

# --- 3. start ----------------------------------------------------------------
pkill -9 -f "$AROOT/usr/sbin/apache2" 2>/dev/null || true
sleep 0.3
rm -f "$APID" "$ALOG"

"$AROOT/usr/sbin/apache2" -f "$ACONF" -k start 2>&1 | head -5

# --- 4. wait for readiness --------------------------------------------------
up=0
for i in $(seq 1 30); do
  curl -s -o /dev/null -w "%{http_code}" --max-time 1 http://127.0.0.1:$APORT/ 2>/dev/null | grep -qE "^(200|301|302|404)$" && { up=1; break; }
  sleep 0.3
done
if [ "$up" != 1 ]; then
  echo "provision-apache: FAIL (HTTP never answered)" >&2
  tail -20 "$AERR" >&2
  exit 1
fi

APIDVAL=$(cat "$APID" 2>/dev/null || echo "")
AVER=$("$AROOT/usr/sbin/apache2" -v 2>&1 | head -1)

echo "APACHE_HOST=127.0.0.1"
echo "APACHE_PORT=$APORT"
echo "APACHE_PID=$APIDVAL"
echo "APACHE_DOCROOT=$DOCROOT"
echo "APACHE_CONF=$ACONF"
echo "APACHE_VERSION=$AVER"
echo "provision-apache: OK (Apache on 127.0.0.1:$APORT, pid=$APIDVAL)" >&2
