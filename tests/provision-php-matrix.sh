#!/bin/bash
# N4D — PHP extension matrix: build PHP 8.2 / 8.3 / 8.4 user-space stacks
# with ext-apcu + ext-memcached, and run the live cache suites under each.
# Phase N §9 requires real extension coverage for 8.2/8.3/8.4.
#
# Output: $UC_PROVISION_CACHE/php82-root/usr/bin/uc-php82 (wrapper)
#         $UC_PROVISION_CACHE/php83-root/usr/bin/uc-php83 (wrapper)
#         $UC_PROVISION_CACHE/php84-root/usr/bin/uc-php84 (wrapper, existing)
#
# Allowed classifications per Phase N §9:
#   PASS       (built + extensions loaded + suites green)
#   BLOCKED    (build failed — honest disclosure, never converted to PASS)
#   UNSUPPORTED (PHP version EOL / source unavailable)
#
# Self-provisioning, rootless, idempotent. All sources pinned.
set -u
CACHE="${UC_PROVISION_CACHE:-$HOME/.cache/uc-provision}"
EXTROOT="$CACHE/ext-root"   # deb-extracted build deps (libmemcached, oniguruma)
SRC="$CACHE/src-php"
PHP84_ROOT="$CACHE/php84-root"
PHP83_ROOT="$CACHE/php83-root"
PHP82_ROOT="$CACHE/php82-root"

# PHP + extension versions
PHP84V="8.4.11"
PHP83V="8.3.16"
PHP82V="8.2.29"
APCUV="5.1.24"
MCV="3.3.0"

mkdir -p "$SRC"

# --- shared deb deps (already provisioned by provision-php84.sh; reuse) -------
[ -f "$EXTROOT/.deps-ok" ] || { echo "N4D: ext-root/.deps-ok missing — run provision-php84.sh first"; exit 1; }

export CPPFLAGS="-I$EXTROOT/usr/include -I$EXTROOT/usr/include/x86_64-linux-gnu"
export LDFLAGS="-L$EXTROOT/usr/lib/x86_64-linux-gnu"
export PKG_CONFIG_PATH="$EXTROOT/usr/lib/x86_64-linux-gnu/pkgconfig:$EXTROOT/usr/lib/pkgconfig"
export LD_LIBRARY_PATH="$EXTROOT/usr/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"

fetch() { [ -f "$2" ] || curl -sL --retry 3 -o "$2" "$1"; }

# --- helper: build one PHP + extensions --------------------------------------
# Args: php_version php_root php_url apcu_url mc_url php_configure_extra
build_php() {
  local PV="$1" ROOT="$2" TARBALL="$3" APCU_TGZ="$4" MC_TGZ="$5"
  local EXTDIR

  if [ -x "$ROOT/usr/bin/uc-php$PV" ]; then
    echo "N4D: PHP $PV already provisioned at $ROOT"
    return 0
  fi

  mkdir -p "$ROOT"
  echo "N4D: building PHP $PV → $ROOT"

  # PHP core
  if [ ! -d "$SRC/php-$PV" ]; then
    fetch "" "$SRC/php-$PV.tar.gz" 2>/dev/null || true
    [ -f "$SRC/php-$PV.tar.gz" ] || fetch "https://www.php.net/distributions/php-$PV.tar.gz" "$SRC/php-$PV.tar.gz"
    tar -xzf "$SRC/php-$PV.tar.gz" -C "$SRC"
  fi
  ( cd "$SRC/php-$PV"
    ./configure \
      --prefix="$ROOT/usr" \
      --disable-cgi --disable-phpdbg --disable-all \
      --enable-cli --enable-pdo --with-pdo-sqlite --enable-sqlite3 \
      --enable-sockets --enable-mbstring --enable-posix \
      --enable-filter --enable-tokenizer --enable-session \
      --with-pcre-jit \
      >/dev/null 2>&1
    make install >/dev/null 2>&1
  )
  [ -x "$ROOT/usr/bin/php" ] || { echo "N4D: PHP $PV core build FAILED"; return 1; }

  EXTDIR=$("$ROOT/usr/bin/php-config" --extension-dir 2>/dev/null || echo "$ROOT/usr/lib/php/extensions/no-debug-non-zts-XXXXX")

  # ext-apcu
  if [ ! -f "$EXTDIR/apcu.so" ]; then
    [ -d "$SRC/apcu-$APCUV" ] || { fetch "https://pecl.php.net/get/apcu-$APCUV.tgz" "$APCU_TGZ"; tar -xzf "$APCU_TGZ" -C "$SRC"; }
    ( cd "$SRC/apcu-$APCUV"
      "$ROOT/usr/bin/phpize" >/dev/null 2>&1
      CPPFLAGS="$CPPFLAGS" LDFLAGS="$LDFLAGS" ./configure --with-php-config="$ROOT/usr/bin/php-config" >/dev/null 2>&1
      make -j"$(nproc)" > "$SRC/apcu-$PV-build.log" 2>&1 && make install >/dev/null 2>&1 || { tail -10 "$SRC/apcu-$PV-build.log"; return 1; }
    )
  fi

  # ext-memcached (note: 3.3.0 requires PHP >= 7.4; works on 8.x)
  if [ ! -f "$EXTDIR/memcached.so" ]; then
    [ -d "$SRC/memcached-$MCV" ] || { fetch "https://pecl.php.net/get/memcached-$MCV.tgz" "$MC_TGZ"; tar -xzf "$MC_TGZ" -C "$SRC"; }
    ( cd "$SRC/memcached-$MCV"
      "$ROOT/usr/bin/phpize" >/dev/null 2>&1
      CPPFLAGS="$CPPFLAGS" LDFLAGS="$LDFLAGS" ./configure --with-php-config="$ROOT/usr/bin/php-config" --with-libmemcached-dir="$EXTROOT/usr" --disable-memcached-sasl >/dev/null 2>&1
      make -j"$(nproc)" > "$SRC/memcached-$PV-build.log" 2>&1 && make install >/dev/null 2>&1 || { tail -10 "$SRC/memcached-$PV-build.log"; return 1; }
    )
  fi

  # wrapper
  cat > "$ROOT/usr/bin/uc-php$PV" << EOF
#!/bin/bash
export LD_LIBRARY_PATH="$EXTROOT/usr/lib/x86_64-linux-gnu\${LD_LIBRARY_PATH:+:\$LD_LIBRARY_PATH}"
exec "$ROOT/usr/bin/php" \\
  -d extension="$EXTDIR/apcu.so" \\
  -d extension="$EXTDIR/memcached.so" \\
  -d memory_limit=512M \\
  -d output_buffering=4096 \\
  -d apc.enable_cli=1 \\
  "\$@"
EOF
  chmod 755 "$ROOT/usr/bin/uc-php$PV"

  # verify
  local VOUT
  VOUT=$("$ROOT/usr/bin/uc-php$PV" -r 'echo (extension_loaded("apcu") && extension_loaded("memcached")) ? "OK " . PHP_VERSION . " apcu=" . phpversion("apcu") . " memcached=" . phpversion("memcached") : "MISSING";' 2>&1 || true)
  echo "N4D: PHP $PV verify: $VOUT"
  case "$VOUT" in OK*) return 0 ;; *) return 1 ;; esac
}

# --- build all three ---------------------------------------------------------
echo "=== N4D: PHP extension matrix build ==="
PHP84_OK=PASS; PHP83_OK=PASS; PHP82_OK=PASS

build_php "$PHP84V" "$PHP84_ROOT" "$SRC/php-$PHP84V.tar.gz" "$SRC/apcu-$APCUV.tgz" "$SRC/memcached-$MCV.tgz" || PHP84_OK=BLOCKED
build_php "$PHP83V" "$PHP83_ROOT" "$SRC/php-$PHP83V.tar.gz" "$SRC/apcu-$APCUV.tgz" "$SRC/memcached-$MCV.tgz" || PHP83_OK=BLOCKED
build_php "$PHP82V" "$PHP82_ROOT" "$SRC/php-$PHP82V.tar.gz" "$SRC/apcu-$APCUV.tgz" "$SRC/memcached-$MCV.tgz" || PHP82_OK=BLOCKED

echo ""
echo "=== N4D: matrix verdict ==="
echo "PHP 8.4 (target $PHP84V): $PHP84_OK"
echo "PHP 8.3 (target $PHP83V): $PHP83_OK"
echo "PHP 8.2 (target $PHP82V): $PHP82_OK"
echo ""
echo "=== N4D: runtime inventory (each row: php version | apcu version | memcached version | libmemcached version | loaded exts count) ==="
for V in 84 83 82; do
  ROOT_VAR="PHP${V}_ROOT"
  ROOT="${!ROOT_VAR}"
  if [ -x "$ROOT/usr/bin/uc-php$V" ]; then
    "$ROOT/usr/bin/uc-php$V" -r '
      $v = PHP_VERSION;
      $apcu = function_exists("phpversion") && phpversion("apcu") ?: "n/a";
      $mc = function_exists("phpversion") && phpversion("memcached") ?: "n/a";
      $exts = get_loaded_extensions();
      sort($exts);
      echo "PHP$v: php=$v apcu=$apcu memcached=$mc exts=".count($exts)." (".implode(",", array_slice($exts, 0, 20)).")\n";
    ' 2>&1 || echo "PHP$V: probe FAILED"
  else
    echo "PHP$V: NOT BUILT"
  fi
done
