#!/bin/bash
# Build the final release ZIP for Ultimate Performance 0.6.2 from a clean tree.
# Mirrors directive #52: commit → fresh checkout → build ZIP → install.
set -euo pipefail

REPO="/home/z/my-project/work/ultimate-cache-extract"
WORK="/tmp/uc-zip-build"
OUT="/home/z/my-project/download"
ZIP_NAME="ultimate-cache-0.6.3.zip"

# Fresh work dir
rm -rf "$WORK"
mkdir -p "$WORK" "$OUT"
cd "$WORK"

# Clone the current HEAD (clean tree, no .git, no test artifacts)
git clone --depth 1 "file://$REPO" ultimate-cache
cd ultimate-cache
rm -rf .git .gitignore

# Verify no stray artifacts
ls -la | head -20
echo "---"
echo "HEAD commit: $(cd "$REPO" && git rev-parse HEAD)"
echo "HEAD subject: $(cd "$REPO" && git log -1 --pretty=%s)"
echo "Tagged version: $(grep -E '^\s*\*\s*Version:' ultimate-performance.php)"
echo "Constant version: $(grep -E "define\(\s*'ULTIMATE_PERFORMANCE_VERSION'" ultimate-performance.php)"

# Verify SHA of all critical files
echo "---"
echo "Critical file SHAs:"
sha256sum \
  ultimate-performance.php \
  src/Core/Settings.php \
  src/Admin/AdminPage.php \
  src/ObjectCache/Manager.php \
  src/ObjectCache/RedisBackend.php \
  src/ObjectCache/MemcachedBackend.php \
  src/ObjectCache/Dropin.php \
  src/PageCache/Engine.php \
  src/Compatibility/FallbackServer.php \
  2>&1 | head -20

# Remove tests/sandbox (not shipped in production ZIP — keeps ZIP small
# and prevents test infrastructure from leaking onto production servers).
# tests/, scripts/, docs/dev-only/*, *.tar.gz already absent (fresh clone).
rm -rf tests/sandbox
# Keep tests/ in the ZIP — Phase O §32 directive: tests must remain
# auditable alongside the plugin code. Production users who don't need
# them simply won't run them.

# Build the ZIP
cd "$WORK"
zip -rq "$OUT/$ZIP_NAME" ultimate-cache/

# Verify ZIP
cd "$OUT"
echo "---"
echo "Final ZIP:"
ls -la "$ZIP_NAME"
unzip -l "$ZIP_NAME" | tail -5
echo "---"
echo "SHA-256:"
sha256sum "$ZIP_NAME"
echo "File count:"
unzip -l "$ZIP_NAME" | tail -1
