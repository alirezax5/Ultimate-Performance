#!/bin/bash
# Phase O — Provision a real Nginx (rootless, user-space).
set -u
CACHE="${UC_PROVISION_CACHE:-$HOME/.cache/uc-provision}"
NROOT="$CACHE/nginx-root"
NDEBS="$CACHE/debs-nginx"
NPID="$CACHE/nginx.pid"
NLOG="$CACHE/nginx.log"
NERR="$CACHE/nginx-error.log"
NPORT="${UC_NGINX_PORT:-18081}"

mkdir -p "$NROOT" "$NDEBS" "$NROOT/var/log/nginx" "$NROOT/var/run" "$NROOT/var/cache/nginx" "$NROOT/etc/nginx" "$NROOT/html"

if [ ! -x "$NROOT/usr/sbin/nginx" ]; then
  echo "provision-nginx: downloading Debian trixie closure (first run only)" >&2
  (
    cd "$NDEBS"
    for pkg in nginx nginx-common nginx-core libpcre2-8-0 libssl3t64 libxml2 libgd3 libxpm4 libjbig0 libwebp7 libtiff6 libjpeg62-turbo libpng16-16 libfreetype6 libfontconfig1 libexpat1 libzstd1 liblzma5 libaio1t64 libbrotli1; do
      apt-get download "$pkg" >/dev/null 2>&1 || true
    done
    for d in *.deb; do [ -e "$d" ] && dpkg -x "$d" "$NROOT"; done
  )
fi
[ -x "$NROOT/usr/sbin/nginx" ] || { echo "provision-nginx: FAIL (no nginx binary)" >&2; exit 1; }

export LD_LIBRARY_PATH="$NROOT/usr/lib/x86_64-linux-gnu:$NROOT/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"

cat > "$NROOT/etc/nginx/nginx.conf" <<EOF
worker_processes 1;
error_log $NERR info;
pid $NPID;
events { worker_connections 256; }
http {
  client_body_temp_path $NROOT/var/cache/nginx/client_temp;
  proxy_temp_path $NROOT/var/cache/nginx/proxy_temp;
  fastcgi_temp_path $NROOT/var/cache/nginx/fastcgi_temp;
  uwsgi_temp_path $NROOT/var/cache/nginx/uwsgi_temp;
  scgi_temp_path $NROOT/var/cache/nginx/scgi_temp;
  access_log $NLOG;
  sendfile on;
  keepalive_timeout 65;
  types { }
  default_type application/octet-stream;
  server {
    listen 127.0.0.1:$NPORT;
    server_name localhost;
    root $NROOT/html;
    index index.html index.htm;
    location / { try_files \$uri \$uri/ =404; }
  }
}
EOF

echo '<!DOCTYPE html><html><body><h1>nginx works</h1></body></html>' > "$NROOT/html/index.html"

pkill -9 -f "$NROOT/usr/sbin/nginx" 2>/dev/null || true
sleep 0.3
rm -f "$NPID"

"$NROOT/usr/sbin/nginx" -c "$NROOT/etc/nginx/nginx.conf" -p "$NROOT" 2>&1 | head -5

up=0
for i in $(seq 1 30); do
  curl -s -o /dev/null -w "%{http_code}" --max-time 1 http://127.0.0.1:$NPORT/ 2>/dev/null | grep -qE "^(200|301|302|404)$" && { up=1; break; }
  sleep 0.3
done
if [ "$up" != 1 ]; then
  echo "provision-nginx: FAIL (HTTP never answered)" >&2
  tail -10 "$NERR" >&2
  exit 1
fi

NPIDVAL=$(cat "$NPID" 2>/dev/null || echo "")
NVER=$("$NROOT/usr/sbin/nginx" -v 2>&1 | head -1)

echo "NGINX_HOST=127.0.0.1"
echo "NGINX_PORT=$NPORT"
echo "NGINX_PID=$NPIDVAL"
echo "NGINX_DOCROOT=$NROOT/html"
echo "NGINX_CONF=$NROOT/etc/nginx/nginx.conf"
echo "NGINX_VERSION=$NVER"
echo "provision-nginx: OK (Nginx on 127.0.0.1:$NPORT, pid=$NPIDVAL)" >&2
