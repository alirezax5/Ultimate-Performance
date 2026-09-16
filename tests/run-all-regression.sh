#!/bin/bash
# Phase H — full regression runner (all permanent suites).
# Usage: bash tests/run-all-regression.sh <round>
# Emits one summary line per suite: name | pass | fail | skip/blocked | exit
export PATH="$HOME/.local/bin:$PATH"
PHP="$HOME/.local/bin/php -d output_buffering=4096"
# NOTE (credential policy): RabbitMQ credentials are NEVER set here.
# They must ONLY be provided by the caller's runtime environment via
# UC_RABBITMQ_HOST / UC_RABBITMQ_PORT / UC_RABBITMQ_VHOST /
# UC_RABBITMQ_USER / UC_RABBITMQ_PASSWORD. When the variables are
# absent, the RabbitMQ suites self-gate into a clean BLOCKED/SKIP
# state (exit 0) and are never counted as PASS.
cd "$(dirname "$0")/.."

ROUND="${1:-R}"
run_suite() {
  local name="$1"; shift
  local out
  out=$(timeout 590 $PHP "$@" 2>&1)
  local ec=$?
  local pass fail skipblk
  pass=$(echo "$out" | grep -cE '^\[PASS\]')
  fail=$(echo "$out" | grep -cE '^\[FAIL\]|^FAIL:')
  skipblk=$(echo "$out" | grep -cE '^\[SKIP\]|^\[BLOCKED\]|skipped cleanly|all skipped|all gated|BLOCKED on credentials')
  if [ "$fail" -gt 0 ]; then
    echo "$ROUND | $name | pass=$pass | FAIL=$fail | skip/blocked=$skipblk | exit=$ec"
    echo "$out" | grep -E '^\[FAIL\]|^FAIL:' | head -5
  else
    echo "$ROUND | $name | pass=$pass | fail=0 | skip/blocked=$skipblk | exit=$ec"
  fi
  # Reset ALL shared shim state between suites so runs stay independent —
  # mirrors a fresh WordPress site: fresh DB rows (posts/terms/comments/AS)
  # AND a fresh cache tree (v/ + meta/). Post IDs are monotonic within a
  # state epoch; resetting both together guarantees no tag-file collisions.
  rm -f tests/wp-shim/state/*.json
  rm -rf tests/wp-shim/wp-content/cache/ultimate-performance
}

run_suite audit-core            tests/audit-core.php
run_suite audit-host-poison     tests/audit-host-poison.php
run_suite audit-classifier      tests/audit-classifier.php
run_suite audit-response-lock   tests/audit-response-lock.php
run_suite audit-wp-nonce        tests/audit-wp-nonce.php
run_suite audit-write-path      tests/audit-write-path.php
run_suite audit-nginx-rules     tests/audit-nginx-rules.php
run_suite audit-sanitizer       tests/audit-sanitizer.php
run_suite audit-metadata        tests/audit-metadata.php
run_suite audit-apache-hit      tests/audit-apache-hit.php
run_suite audit-invalidation    tests/audit-invalidation.php
run_suite audit-registry-stress tests/audit-registry-stress.php
run_suite audit-registry-conc   tests/audit-registry-concurrency.php
run_suite audit-queue           tests/audit-queue.php
run_suite audit-queue-callback  tests/audit-queue-callback.php
run_suite audit-boot            tests/audit-boot.php
run_suite audit-object-cache    tests/audit-object-cache.php
run_suite audit-oc-backends     tests/audit-oc-backends.php
run_suite audit-oc-multisite    tests/audit-oc-multisite.php
run_suite audit-oc-sqlite-file  tests/audit-oc-sqlite-file.php
run_suite audit-oc-fencing      tests/audit-oc-fencing.php
run_suite audit-oc-save-flow    tests/audit-object-cache-save-flow.php
# N4C: real APCu validation — uses the provisioned uc-php84 stack with
# ext-apcu + apc.enable_cli=1. Skips cleanly when apcu is unavailable.
run_suite audit-apcu-live       tests/audit-apcu-live.php
# N5: multisite uninstall hardening (3 cluster tables DROP, foreign table SURVIVES)
run_suite audit-multisite-uninstall tests/audit-multisite-uninstall.php
# N5: probe diagnostics classification (8 distinct categories per §17)
run_suite audit-probe-diagnostics tests/audit-probe-diagnostics.php
# N5: cluster CLI command surface (status/epoch/events/reconcile, secret-free)
run_suite audit-cluster-cli     tests/audit-cluster-cli.php
# O4: WP-CLI command registration (WpCliCommands class + add_command + real wp binary)
run_suite audit-wpcli-live      tests/audit-wpcli-live.php
# O5: OpenLiteSpeed Rules writer (security + cookie bypass + config fuzzing)
run_suite audit-ols-rules       tests/audit-ols-rules.php
# O6: Performance benchmark suite (zero-PHP HIT invariant + methodology)
run_suite audit-benchmark       tests/audit-benchmark.php
run_suite audit-scheduler-tel   tests/audit-scheduler-telemetry.php
run_suite audit-warmup          tests/audit-warmup.php
run_suite audit-cluster         tests/audit-cluster.php
run_suite audit-cluster-epoch   tests/audit-cluster-epoch.php
run_suite audit-event-recovery  tests/audit-event-recovery.php
run_suite audit-node-identity   tests/audit-node-identity.php
run_suite audit-watermark       tests/audit-watermark.php
run_suite audit-large-purge     tests/audit-large-purge.php
run_suite audit-concurrency     tests/audit-concurrency.php
run_suite audit-backend-matrix  tests/audit-backend-matrix.php
run_suite audit-rmq-connection  tests/audit-rmq-connection.php
run_suite audit-rmq-live        tests/audit-rmq-live.php
run_suite audit-rmq-fixes       tests/audit-rmq-fixes.php
run_suite audit-wporg-ready    tests/audit-wporg-readiness.php
# Q-STABLE-D1: version metadata consistency (header == constant == Stable tag)
run_suite audit-version-consistency tests/audit-version-consistency.php
# HARDEN-1: BENCH-D7 stampede protection — single-flight generation lock
run_suite audit-herd             tests/audit-herd.php
# HARDEN-2: BENCH-D5 homepage path — ROOT_SENTINEL consistency
run_suite audit-homepage         tests/audit-homepage.php
# HARDEN-3: BENCH-D4 Woo sanitizer — semantic patterns, not broad substrings
run_suite audit-woo-sanitizer    tests/audit-woo-sanitizer.php
# HARDEN-4: BENCH-D6 invalidation — hybrid sync+async, stale exposure ≈ 0
run_suite audit-stale-exposure   tests/audit-stale-exposure.php
# HARDEN-5: PHP fallback mode — advanced-cache.php drop-in with ownership detection
run_suite audit-fallback          tests/audit-fallback.php
# HARDEN-6: Environment detection + self-test + admin health
run_suite audit-env-detect        tests/audit-env-detect.php
# HARDEN-7: Shared hosting compatibility — no-root Nginx, Apache, managed
run_suite audit-shared-hosting    tests/audit-shared-hosting.php
# UX-FIX: Nginx settings must be optional (empty = valid)
run_suite audit-nginx-optional    tests/audit-nginx-optional.php
# B+C: Constant guards + settings partial save
run_suite audit-bootstrap-settings tests/audit-bootstrap-settings.php
# Safe defaults: page_cache ON, object_cache OFF, RabbitMQ not probed
run_suite audit-safe-defaults      tests/audit-safe-defaults.php
# WooCommerce page cache: public cacheable, private bypass, cookie distinction
run_suite audit-woo-pagecache       tests/audit-woo-pagecache.php
# Section-aware save + Object Cache prefix
run_suite audit-section-save-prefix tests/audit-section-save-prefix.php
# Redis Test Connection closure: handle_test_redis full sequence + DB=3 reach
run_suite audit-redis-test-closure tests/audit-redis-test-closure.php
# Redis Object Cache emergency runtime closure (live Redis daemon required).
# Self-gates to SKIP when Redis is not provisioned.
if [ -x "$HOME/.cache/uc-provision/redis-root/usr/bin/redis-server" ]; then
  bash tests/provision-redis.sh > /tmp/uc-redis-provision.$$.env 2>&1
  REDIS_PID_VAL=$(grep '^REDIS_PID=' /tmp/uc-redis-provision.$$.env | cut -d= -f2)
  REDIS_PORT_VAL=$(grep '^REDIS_PORT=' /tmp/uc-redis-provision.$$.env | cut -d= -f2)
  UC_REDIS_HOST=127.0.0.1 UC_REDIS_PORT=${REDIS_PORT_VAL:-16379} REDIS_PID=${REDIS_PID_VAL:-0} \
    timeout 590 $PHP tests/audit-redis-runtime-live.php > /tmp/uc-redis-runtime.$$.out 2>&1
  ec=$?
  pass=$(grep -cE '^\[PASS\]' /tmp/uc-redis-runtime.$$.out)
  fail=$(grep -cE '^\[FAIL\]' /tmp/uc-redis-runtime.$$.out)
  echo "$ROUND | audit-redis-runtime-live | pass=$pass | fail=$fail | exit=$ec"
  if [ "$fail" -gt 0 ]; then
    grep -E '^\[FAIL\]' /tmp/uc-redis-runtime.$$.out | head -5
  fi
  rm -f /tmp/uc-redis-runtime.$$.out /tmp/uc-redis-provision.$$.env
else
  echo "$ROUND | audit-redis-runtime-live | pass=0 | fail=0 | skip/blocked=1 (Redis not provisioned)"
fi

# AdminPage callback audit (§5/§8 — all registered callbacks exist + callable)
run_suite audit-adminpage-callbacks tests/audit-adminpage-callbacks.php

# AJAX runtime contract (§8/§9/§21-§23/§54/§55 — JSON shape, nonce, capability)
run_suite audit-ajax-runtime tests/audit-ajax-runtime.php

# FINDING-E (Phase 15) — storefront visibility invalidation hooks
run_suite audit-storefront-invalidation tests/audit-storefront-invalidation.php

# WC-PROD-LIFECYCLE — product lifecycle cache invalidation
run_suite audit-woo-product-invalidation tests/audit-woo-product-invalidation.php
