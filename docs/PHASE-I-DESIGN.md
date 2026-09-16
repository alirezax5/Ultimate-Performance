# PHASE I — DESIGN (Rebuild)

Locked design for the object cache runtime. Constraints inherited from the project
doctrine: fail-closed everywhere, no weakening of tests, mock ≠ live, honest reporting.

## 1. Layered architecture

```
WP API (wp_cache_* functions)              ← generated drop-in: wp-content/object-cache.php
        │
UltimatePerformance\ObjectCache\Manager          ← semantics: groups, blogs, found, supports
        │
UltimatePerformance\ObjectCache\Backend (iface)  ← storage contract
   ├── MemoryBackend   (always available; per-process; honest non-persistence)
   └── RedisBackend    (ext-redis; persistent; O(1) invalidation)
```

The drop-in file is a THIN bootstrapper, not a copy of the runtime: it defines the
`wp_cache_*` function set (guarded by `function_exists`, mirroring how core's cache.php is
replaced), requires the plugin's `Manager` when the plugin directory exists, and degrades
to a minimal in-memory array implementation when the plugin is missing — WordPress must
never fatal because of our drop-in.

## 2. Backend contract (`src/ObjectCache/Backend.php`)

Methods (all string-keyed, `$group` normalized non-empty `default`):

| Method | Contract |
|---|---|
| `get($key, $group, &$found)` | Returns value or null; `$found=true` iff the key exists (even if the stored value is `false`, `null`, `0`, `''`). |
| `getMultiple($keys, $group)` | `array<string, array{value:mixed, found:bool}>` keyed by original key. |
| `set($key, $value, $ttl, $group)` | Unconditional write. `$ttl` in seconds; `0` = no expiry. |
| `add($key, $value, $ttl, $group)` | Write only if NOT exists. Returns bool. |
| `replace($key, $value, $ttl, $group)` | Write only if EXISTS. Returns bool. |
| `delete($key, $group)` | Remove; true if removed or already absent (WP delete semantics). |
| `incr($key, $n, $group)` / `decr` | Atomic server-side arithmetic; false on missing key or non-integer. |
| `flushGroup($group)` | Invalidate all keys of one group — O(1) required. |
| `flush()` | Invalidate everything written by this runtime (this blog scope) — O(1). |
| `healthy()` | True iff the storage can serve reads and accept writes right now. |
| `close()` | Release resources; safe to call twice. |

Implementation notes enforced by review + audit suite:
- Values are stored serialized in an envelope so any PHP value (including `false`,
  `null`, `0`, `''`) round-trips with correct `$found`.
- No use of KEYS/KEYS*/SCAN for invalidation; no FLUSHALL/FLUSHDB ever (asserted by
  source grep in the audit suite and by a live sentinel check: keys outside the
  runtime's namespace MUST survive `flush()`).

## 3. Redis key layout & O(1) invalidation

```
uc:oc:{scope}:g{gen}:{group}:{key}      value keys (EX = ttl when ttl > 0)
uc:oc:{scope}:gen:{group}               per-group generation counter (no expiry)
uc:oc:{scope}:bgen                      scope-wide generation counter (no expiry)
```

- `{scope}` = `b{blog_id}` for blog-scoped groups, `global` for global groups.
- Effective key embeds `g{gen}` where `gen = gen:{group}` — flushing a group = `INCR
  gen:{group}` (O(1), never scans); flushing a scope = `INCR bgen`, and the scope
  generation is prepended to the group generation namespace:
  effective key = `uc:oc:{scope}:B{bgen}:g{gen}:{group}:{key}`.
- Reads of stale generations simply MISS (old keys expire by TTL or are overwritten);
  no deletion storms, no scans, constant-time invalidation at any key count.
- `incr/decr` use `INCRBY`/`DECRBY` on the effective key (server-side atomic).
  A fresh counter key is only created via `add`/`set` of an integer through the envelope;
  arithmetic on a missing key returns false (WP semantics), never auto-vivifies.
- `add` = `SET NX`; `replace` = `SET XX` (with EX when ttl>0). Both single round-trips.
- Multi-key ops use pipelines where ext-redis supports them.
- A per-runtime namespace salt is NOT used (generation counters already isolate flushes);
  keys are deterministic so cross-process coherence holds.

## 4. Manager semantics (the WP surface)

- Group registries, both filterable (`uc_object_cache_global_groups`,
  `uc_object_cache_non_persistent_groups`):
  - **Global groups**: `users`, `userlogins`, `usermeta`, `user_meta`, `site-transient`,
    `site-options`, `networks`, `sites`, `blog-details`, `blog-lookup`, `global-cache` etc.
    Keys live in the `global` scope and are unaffected by `switch_to_blog`.
  - **Non-persistent groups**: `counts`, `plugins`… served ONLY from the per-process
    runtime layer; never sent to the persistent backend; `flush()` clears them.
- `switch_to_blog($id)` / `restore_current_blog()`: Manager keeps a scope stack; all
  blog-scoped groups read/write the new scope; global groups untouched. `flush()` inside
  a switched scope invalidates ONLY that scope.
- `wp_cache_supports()` reports exactly: `add`, `add_multiple`, `set_multiple`,
  `get_multiple`, `incr`, `decr`, `flush`, `flush_runtime`, `flush_group`, `group` —
  intersection of what the ACTIVE backend truly provides; Memory reports no persistence,
  Redis reports all of the above.
- `get` with `$force=true` bypasses the per-request runtime cache but still consults the
  backend; `$found` is a BY-REFERENCE boolean in every path.
- Failure handling: every backend exception is caught at the Manager boundary → the call
  fails closed (get → `found=false`; set/add → false; incr/decr → false) and the runtime
  marks the backend unhealthy (recheck window) — no fatals, ever. If the persistent
  backend is down, non-persistent groups keep working in-process.

## 5. Drop-in ownership (`src/ObjectCache/Dropin.php`)

State machine for `wp-content/object-cache.php`:
1. **Absent** → install (temp file + rename via SafeFs; 0640).
2. **Exists, starts with our marker** (`<?php // Ultimate Performance object cache drop-in vN`) →
   atomic update (write temp + rename over; never truncate-in-place).
3. **Exists, foreign content** → REFUSE: leave the file byte-identical, record status
   transient `uc_oc_dropin_status = {state:'foreign', …}`, surface an admin notice; never
   overwrite another plugin's/core's drop-in.
- Deactivation removes the drop-in ONLY when the marker proves ownership (existing
  `Installer::deactivate()` behavior — kept, now backed by the same Dropin class).
- The drop-in source is generated from a versioned template constant; `Manager` verifies
  the marker version at boot and reports drift.

## 6. Shim upgrade (test infrastructure, mirrors real WP)

`tests/wp-shim` gains the real WP load order for object caches:
- `wp-load.php` requires `WP_CONTENT_DIR/object-cache.php` when present and sets
  `$_wp_using_ext_object_cache = true` (exactly like wp-settings.php), BEFORE any
  `wp_cache_*` use.
- The shim's own array cache functions are now guarded with `function_exists` so a
  drop-in replaces them exactly like core's cache.php would be replaced. Without a
  drop-in the shim behaves byte-for-byte as before (all 21 existing suites must stay
  green — this is asserted by the full regression round in the live gate stage).

## 7. Test plan

| Suite | Mode | What it proves |
|---|---|---|
| `tests/audit-object-cache.php` | non-live (regression member) | Memory + Manager semantics matrix: found-flags, false/null/0/'' storage, add/replace CAS, incr/decr atomicity incl. negative, TTL expiry (short real sleeps), multi-ops, global vs blog scopes, non-persistent groups, supports truthfulness, drop-in ownership state machine (install/update/foreign-refuse/deactivate), forbidden-command source grep, fail-closed with a broken backend. |
| `tests/audit-object-cache-live.php` | live (real redis-server) | The same semantic matrix against `RedisBackend` + multi-process INCRBY atomicity (children spawned via `php` + `proc_open`), TTL measured against the real server, scope/flush O(1) sentinel (foreign namespace keys survive), kill-server fail-closed + recovery, switch_to_blog isolation. |
| `tests/run-redis-live.sh` | runner | Self-provisions real `redis-server` from the Debian package closure (user space, loopback, ephemeral port, `--save '' --appendonly no`), runs the live suite, stops the server, scrubs state. |

Page-cache integration: the existing Apache live suites (`run-apache-live.sh`) must stay
green after the shim change (drop-in NOT installed there — proves the shim upgrade is
backward compatible), and the object cache must not alter page-cache behavior (asserted in
the audit suite via Engine smoke rows).

## 8. Commit plan (per completed work unit)

1. `docs/PHASE-I-{BASELINE,DESIGN}.md`.
2. `src/ObjectCache/{Backend,MemoryBackend,Manager}.php` + `tests/audit-object-cache.php` (Memory+Manager matrix green).
3. `src/ObjectCache/RedisBackend.php` + `src/ObjectCache/Dropin.php` (+ drop-in rows in audit suite).
4. Shim drop-in mechanism + Redis live suite + runner; full regression round.
5. Live gate execution + `download/PHASE-I-FINAL-REPORT.md` + worklog.
