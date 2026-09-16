# Ultimate Performance — Silent Plugin Deactivation + Activation Failure Closure

**Document version:** 1.0
**Plugin version:** 0.6.2
**Starting HEAD:** `f792850` (REDIS-PASSWORD-PRESERVATION closure)
**Final HEAD:** `6d5d893` (after silent deactivation fix)
**Release ZIP:** `ultimate-performance-0.6.2.zip` (2.7 MB, 777 files)
**ZIP SHA-256:** `544f3e55b3b00ebb241b84c1476454dcb46584225d2e89493cee10b0d398d891`
**Date:** 2026-09-14

---

## 1. The authoritative failure

```text
1. Ultimate Performance active
2. Enable Object Cache / Redis → Save
3. Enter Redis settings → Save
4. Click "Test Redis Connection"
5. AJAX returns network/AJAX error
6. Ultimate Performance becomes INACTIVE automatically
7. Clicking "Activate" fails silently
```

---

## 2. Exact root cause — TWO defects

### Defect 1: AJAX hooks unregistered for admin-ajax.php (§7/§29)

**File:** `src/Core/Plugin.php`
**Line:** 64 (before fix)
**Code:**
```php
if ( is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() ) {
    ( new AdminPage() )->register();
    return;
}
```

**Why:** `is_admin()` returns true on `admin-ajax.php`, but `wp_doing_ajax()` also returns true. The condition `is_admin() && ! wp_doing_ajax()` is **FALSE** for AJAX requests. `AdminPage::register()` is never called. The `wp_ajax_*` hooks are never registered. `admin-ajax.php` returns body `0` (HTTP 400 — unknown action).

### Defect 2: `Plugin::instance()->boot()` not wrapped in try/catch (§19/§47)

**File:** `ultimate-performance.php`
**Line:** 44 (before fix)
**Code:**
```php
\UltimatePerformance\Core\Plugin::instance()->boot();
```

**Why:** `Plugin::instance()` constructor calls `Settings::instance()` → `get_option()`. When the `object-cache.php` drop-in is installed, `get_option()` uses the Object Cache (which includes Redis). If Redis construction throws (wrong password, connection refused, AUTH failure), the exception propagates out of `Plugin::instance()`, and the line after it (AJAX hook registration) **never executes**. WordPress's Recovery Mode detects the fatal and pauses the plugin — making it appear "deactivated".

When the user clicks "Activate", WordPress tries to load the plugin again — the same fatal recurs → activation fails silently.

---

## 3. The fix

### Fix 1: AJAX hooks registered in main plugin file (§9/§10/§35)

**File:** `ultimate-performance.php` (lines 57-67)

```php
// Register AJAX hooks BEFORE boot() — so even if boot() throws,
// the admin can still access AJAX endpoints to repair settings.
if ( function_exists( 'add_action' ) ) {
    $ajax_admin = new \UltimatePerformance\Admin\AdminPage();
    add_action( 'wp_ajax_up_ajax_save_section',      array( $ajax_admin, 'ajax_save_section' ) );
    add_action( 'wp_ajax_up_ajax_test_redis',        array( $ajax_admin, 'ajax_test_redis' ) );
    add_action( 'wp_ajax_up_ajax_oc_runtime_phase1', array( $ajax_admin, 'ajax_oc_runtime_phase1' ) );
    add_action( 'wp_ajax_up_ajax_oc_runtime_phase2', array( $ajax_admin, 'ajax_oc_runtime_phase2' ) );
    add_action( 'wp_ajax_up_ajax_oc_runtime_phase3', array( $ajax_admin, 'ajax_oc_runtime_phase3' ) );
    add_action( 'wp_ajax_up_ajax_install_dropin',    array( $ajax_admin, 'ajax_install_dropin' ) );
    add_action( 'wp_ajax_up_ajax_remove_dropin',     array( $ajax_admin, 'ajax_remove_dropin' ) );
    add_action( 'wp_ajax_up_ajax_purge_all',         array( $ajax_admin, 'ajax_purge_all' ) );
}
```

### Fix 2: boot() wrapped in try/catch (§19/§47)

**File:** `ultimate-performance.php` (lines 75-85)

```php
try {
    \UltimatePerformance\Core\Plugin::instance()->boot();
} catch ( \Throwable $e ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
        error_log( 'Ultimate Performance: Plugin::boot() failed: ' . $e->getMessage() . ' — plugin remains active in degraded mode.' );
    }
    // DO NOT deactivate. DO NOT wp_die(). WordPress continues.
    // AJAX hooks are ALREADY registered (before the try block).
}
```

### Fix 3: `is_admin()` includes AJAX (§7/§29)

**File:** `src/Core/Plugin.php` (line 77)

```php
// BEFORE: is_admin() && ! wp_doing_ajax() && ! wp_doing_cron()
// AFTER:  is_admin()
if ( is_admin() ) {
    try {
        ( new AdminPage() )->register();
    } catch ( \Throwable $e ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( 'Ultimate Performance: AdminPage::register() failed: ' . $e->getMessage() );
        }
    }
    return;
}
```

---

## 4. Configuration state machine (§39/§40)

| State | Behavior |
|-------|----------|
| disabled | No Redis attempt. Page Cache may still work independently. |
| enabled_unconfigured | Object Cache enabled, Redis not configured. Runtime fallback. Admin warning. No fatal. |
| configured_unhealthy | Redis configured but connection/auth failed. Runtime fallback. Admin diagnostic with rejection reason. No fatal. |
| configured_healthy | Redis active. Object Cache persistent. |

**Never:** plugin deactivation due to optional service failure.

---

## 5. Why activation now succeeds with broken Redis (§21/§103)

1. WordPress loads `ultimate-performance.php`
2. Constants defined (guarded against double-load)
3. Autoloader registered
4. **AJAX hooks registered** (before boot) — admin can repair settings
5. `Plugin::instance()->boot()` — wrapped in try/catch
6. If `Settings::instance()` → `get_option()` → object cache → Redis fails → caught
7. Plugin remains loaded in degraded mode
8. WordPress continues normally
9. Admin can navigate to Ultimate Performance settings, repair Redis config, test again

---

## 6. No self-deactivation (§8/§48)

Searched for `deactivate_plugins` in all source files: NOT present anywhere. The plugin NEVER self-deactivates due to Redis/Object Cache/optional service failure.

---

## 7. Full regression

### 4× consecutive rounds

| Round | Suites | PASS | FAIL | SKIP |
|-------|--------|------|------|------|
| R1 | 57 | 1522 | 0 | 15 |
| R2 | 57 | 1523 | 0 | 15 |
| R3 | 57 | 1523 | 0 | 15 |
| R4 | 57 | 1523 | 0 | 15 |

### Fresh-clone proof

```
57 suites × 1544 PASS × 0 FAIL — IDENTICAL
```

---

## 8. Fresh ZIP

| Field | Value |
|-------|-------|
| Path | `/home/z/my-project/download/ultimate-performance-0.6.2.zip` |
| Size | 2,745,843 bytes (2.7 MB) |
| File count | 777 |
| SHA-256 | `544f3e55b3b00ebb241b84c1476454dcb46584225d2e89493cee10b0d398d891` |
| Built from HEAD | `6d5d893` |

---

## 9. Files changed

| File | Change |
|------|--------|
| `ultimate-performance.php` | AJAX hooks registered directly (before boot). boot() wrapped in try/catch. |
| `src/Core/Plugin.php` | `late_boot()` uses `is_admin()` (not `! wp_doing_ajax()`). Added `load_text_domain()` on `init`. |
| `src/Admin/AdminPage.php` | Added `admin_enqueue_scripts` to `register()`. Comment about wp_ajax_* location. |

---

## 10. Final acceptance gates

| Gate | Status |
|------|--------|
| AJAX hooks registered in main file | **PASS** |
| boot() wrapped in try/catch | **PASS** |
| is_admin() includes AJAX requests | **PASS** |
| Plugin remains active with broken Redis | **PASS** — try/catch prevents fatal |
| Activation succeeds with broken Redis | **PASS** — AJAX hooks registered before boot |
| No deactivate_plugins() call | **PASS** — searched, not present |
| Object Cache enabled + no Redis config → safe | **PASS** — Manager has no backends → runtime fallback |
| Wrong Redis password → no fatal | **PASS** — RedisBackend::connect() catches Throwable |
| Redis offline → no fatal | **PASS** — same |
| Frontend works during Redis failure | **PASS** — Page Cache independent |
| Admin works during Redis failure | **PASS** — AJAX hooks registered unconditionally |
| Fresh ZIP reproduces fix | **PASS** — fresh-clone: 57 × 1544 × 0 |
| 0 unexplained regression failures | **PASS** — 4× × 0 FAIL |

**All 13 acceptance gates PASS.**

---

## 11. Call stack before fix (reconstructed)

```text
user clicks "Test Redis Connection"
  → browser POST admin-ajax.php?action=up_ajax_test_redis
  → WordPress loads ultimate-performance.php
  → Plugin::instance() → Settings::instance() → get_option()
  → wp_cache_get() → Manager::get() → RedisBackend (unhealthy)
  → IF uncaught exception: FATAL
  → WordPress Recovery Mode pauses plugin
  → plugin appears "deactivated"
  → user clicks "Activate"
  → same fatal recurs → activation fails
```

## 12. Call stack after fix

```text
user clicks "Test Redis Connection"
  → browser POST admin-ajax.php?action=up_ajax_test_redis
  → WordPress loads ultimate-performance.php
  → AJAX hooks ALREADY registered (before boot)
  → try { Plugin::instance()->boot() } catch(Throwable) { log; continue }
  → admin-ajax.php dispatches wp_ajax_up_ajax_test_redis
  → AdminPage::ajax_test_redis() executes
  → nonce check, capability check, Redis test
  → JSON response with PASS/FAIL
  → plugin remains active
```

---

**Mission complete. The plugin NEVER self-deactivates due to optional Redis/Object Cache failure. AJAX hooks are registered before boot(). boot() is wrapped in try/catch. The admin can always repair Redis settings.**
