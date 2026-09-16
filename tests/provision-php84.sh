#!/bin/bash
# N4 — provision a real PHP 8.4 user-space stack with ext-apcu + ext-memcached
# (Phase N §21). Self-provisioning, rootless, idempotent; all sources pinned.
# Fixes the Phase J/K durability gap: this provision was previously an
# UNTRACKED machine state — the script is now committed so no live runner
# depends on untracked machine state.
#
# Output: $UC_PROVISION_CACHE/php84-root/usr/bin/uc-php84 (wrapper)
# Cache:  $UC_PROVISION_CACHE (gitignored machine cache, re-creatable)
set -eu
CACHE="${UC_PROVISION_CACHE:-$HOME/.cache/uc-provision}"
ROOT="$CACHE/php84-root"
SRC="$CACHE/src-php84"
EXTROOT="$CACHE/ext-root"   # deb-extracted build deps (libmemcached, oniguruma)
DEBS="$CACHE/debs-ext"
PHPV="8.4.11"
APCUV="5.1.24"
MCV="3.3.0"

mkdir -p "$ROOT" "$SRC" "$EXTROOT" "$DEBS"

# --- 1. build deps via deb extraction (no root) ------------------------------
# N4-FIX: include the runtime .so packages too — the -dev packages ship
# symlinks pointing at versioned libs that the runtime packages actually
# provide. Without them the memcached.so link step fails with
# `cannot find -lmemcached`. Debian 13 (trixie) t64 transition renamed
# several packages; apt-get download fails atomically if ANY package is
# missing, so we download one package at a time and tolerate per-pkg
# failure (the corresponding -t64 variant will fill the gap).
if [ ! -f "$EXTROOT/.deps-ok" ]; then
  ( cd "$DEBS"
    for pkg in \
      libmemcached-dev libmemcached11 libmemcached11t64 \
      libmemcachedutil2 libmemcachedutil2t64 \
      libhashkit-dev libhashkit2 libhashkit2t64 \
      libonig-dev libonig5 \
      libsasl2-dev libsasl2-2 libsasl2-modules-db \
      libevent-2.1-7 libevent-2.1-7t64 \
    ; do
      apt-get download "$pkg" >/dev/null 2>&1 || true
    done
    for d in *.deb; do [ -e "$d" ] && dpkg -x "$d" "$EXTROOT"; done
  )
  touch "$EXTROOT/.deps-ok"
fi

# Corrected libmemcached.pc (Debian's ships system paths + a libsasl2
# Requires that breaks under user-space extraction) — idempotent.
for PCDIR in "$EXTROOT/usr/lib/x86_64-linux-gnu/pkgconfig" "$EXTROOT/usr/lib/pkgconfig"; do
  mkdir -p "$PCDIR"
  [ -d "$PCDIR" ] || continue
  cat > "$PCDIR/libmemcached.pc" << PCEOF
prefix=$EXTROOT/usr
exec_prefix=\${prefix}/bin
libdir=\${prefix}/lib/x86_64-linux-gnu
includedir=\${prefix}/include

Name: libmemcached
URL: https://awesomized.github.io/libmemcached/
Description: libmemcached C/C++ library (user-space provision)
Version: 1.1.4
Libs: -L\${libdir} -lmemcached -lmemcachedutil
Cflags: -I\${includedir}
PCEOF
done

export CPPFLAGS="-I$EXTROOT/usr/include -I$EXTROOT/usr/include/x86_64-linux-gnu"
export LDFLAGS="-L$EXTROOT/usr/lib/x86_64-linux-gnu"
export PKG_CONFIG_PATH="$EXTROOT/usr/lib/x86_64-linux-gnu/pkgconfig:$EXTROOT/usr/lib/pkgconfig"
export LD_LIBRARY_PATH="$EXTROOT/usr/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"

# --- 2. fetch pinned sources --------------------------------------------------
fetch() { # fetch <url> <dest>
  [ -f "$2" ] || curl -sL --retry 3 -o "$2" "$1"
}
fetch "https://www.php.net/distributions/php-$PHPV.tar.gz" "$SRC/php-$PHPV.tar.gz"
fetch "https://pecl.php.net/get/apcu-$APCUV.tgz" "$SRC/apcu-$APCUV.tgz"
fetch "https://pecl.php.net/get/memcached-$MCV.tgz" "$SRC/memcached-$MCV.tgz"

# --- 3. build PHP 8.4 CLI (lean) ----------------------------------------------
if [ ! -x "$ROOT/usr/bin/php" ]; then
  [ -d "$SRC/php-$PHPV" ] || tar -xzf "$SRC/php-$PHPV.tar.gz" -C "$SRC"
  ( cd "$SRC/php-$PHPV"
    ./configure \
      --prefix="$ROOT/usr" \
      --disable-cgi --disable-phpdbg --disable-all \
      --enable-cli --enable-pdo --with-pdo-sqlite --enable-sqlite3 \
      --enable-sockets --enable-mbstring --enable-posix \
      --enable-filter --enable-tokenizer --enable-session \
      --with-pcre-jit \
      >/dev/null
    true
    make install > /dev/null
  )
fi

# --- 4. build ext-apcu --------------------------------------------------------
if [ ! -f "$ROOT/usr/lib/php/extensions/"*/apcu.so ] && [ ! -f "$ROOT/usr/lib/php/extensions/apcu.so" ]; then
  [ -d "$SRC/apcu-$APCUV" ] || tar -xzf "$SRC/apcu-$APCUV.tgz" -C "$SRC"
  ( cd "$SRC/apcu-$APCUV"
    "$ROOT/usr/bin/phpize" >/dev/null
    CPPFLAGS="$CPPFLAGS" LDFLAGS="$LDFLAGS" ./configure --with-php-config="$ROOT/usr/bin/php-config" >/dev/null
    make -j"$(nproc)" > "$SRC/apcu-build.log" 2>&1 && make install >/dev/null || { tail -20 "$SRC/apcu-build.log"; exit 1; }
  )
fi

# --- 5. build ext-memcached ---------------------------------------------------
if [ ! -f "$ROOT/usr/lib/php/extensions/"*/memcached.so ] && [ ! -f "$ROOT/usr/lib/php/extensions/memcached.so" ]; then
  [ -d "$SRC/memcached-$MCV" ] || tar -xzf "$SRC/memcached-$MCV.tgz" -C "$SRC"
  ( cd "$SRC/memcached-$MCV"
    "$ROOT/usr/bin/phpize" >/dev/null
    CPPFLAGS="$CPPFLAGS" LDFLAGS="$LDFLAGS" ./configure --with-php-config="$ROOT/usr/bin/php-config" --with-libmemcached-dir="$EXTROOT/usr" --disable-memcached-sasl >/dev/null
    make -j"$(nproc)" > "$SRC/memcached-build.log" 2>&1 && make install >/dev/null || { tail -20 "$SRC/memcached-build.log"; exit 1; }
  )
fi

# --- 5b. build ext-redis ------------------------------------------------------
REDISV="6.2.0"
if [ ! -f "$ROOT/usr/lib/php/extensions/"*/redis.so ] && [ ! -f "$ROOT/usr/lib/php/extensions/redis.so" ]; then
  fetch "https://pecl.php.net/get/redis-$REDISV.tgz" "$SRC/redis-$REDISV.tgz"
  [ -d "$SRC/redis-$REDISV" ] || tar -xzf "$SRC/redis-$REDISV.tgz" -C "$SRC"
  ( cd "$SRC/redis-$REDISV"
    "$ROOT/usr/bin/phpize" >/dev/null
    CPPFLAGS="$CPPFLAGS" LDFLAGS="$LDFLAGS" ./configure --with-php-config="$ROOT/usr/bin/php-config" --enable-redis --enable-redis-session --enable-redis-igbinary=no >/dev/null
    make -j"$(nproc)" > "$SRC/redis-build.log" 2>&1 && make install >/dev/null || { tail -20 "$SRC/redis-build.log"; exit 1; }
  )
fi

# --- 6. wrapper (binary the runners exec) -------------------------------------
EXTDIR=$("$ROOT/usr/bin/php-config" --extension-dir 2>/dev/null || echo "$ROOT/usr/lib/php/extensions/no-debug-non-zts-20240924")
cat > "$ROOT/usr/bin/uc-php84" << EOF
#!/bin/bash
export LD_LIBRARY_PATH="$EXTROOT/usr/lib/x86_64-linux-gnu\${LD_LIBRARY_PATH:+:\$LD_LIBRARY_PATH}"
exec "$ROOT/usr/bin/php" \\
  -d extension="$EXTDIR/apcu.so" \\
  -d extension="$EXTDIR/memcached.so" \\
  -d extension="$EXTDIR/redis.so" \\
  -d memory_limit=512M \\
  -d output_buffering=4096 \\
  -d apc.enable_cli=1 \\
  "\$@"
EOF
chmod 755 "$ROOT/usr/bin/uc-php84"

# --- 7. verify ------------------------------------------------------------------
VOUT=$("$ROOT/usr/bin/uc-php84" -r 'echo (extension_loaded("apcu") && extension_loaded("memcached") && extension_loaded("redis")) ? "OK" : "MISSING";' 2>&1 || true)
echo "provision-php84: verify output: $VOUT"
case "$VOUT" in
  OK) : ;;
  *) echo "provision-php84: VERIFY FAILED (apcu/memcached/redis not loaded)"; exit 1 ;;
esac
echo "provision-php84: OK $( "$ROOT/usr/bin/uc-php84" -r 'echo PHP_VERSION . " apcu=" . phpversion("apcu") . " memcached=" . phpversion("memcached") . " redis=" . phpversion("redis");' )"
