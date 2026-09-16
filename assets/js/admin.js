/**
 * Ultimate Performance admin JavaScript — real AJAX transport.
 *
 * §11/§12/§15 — Replaces the legacy admin_post_* full-page-reload UX for the
 * Redis Test and Object Cache Runtime Test buttons. Click handlers are
 * attached via the id selectors below. The localized UP_ADMIN object
 * provides the AJAX URL + per-action nonces so this file does NOT hardcode
 * /wp-admin/admin-ajax.php.
 *
 * §18/§19 — Browser event handler contract:
 *   - On click: button disabled, status = "Testing..."
 *   - AJAX completes: render PASS/FAIL inline
 *   - Button re-enabled afterward
 *   - window.location MUST NOT change (no refresh)
 *
 * §21/§22/§23 — Object Cache Runtime Test is a 3-phase state machine:
 *   phase1 (write) → phase2 (cross-request read) → phase3 (delete + verify)
 *   Each phase is a SEPARATE admin-ajax.php request.
 *
 * §27 — Transport failure must be distinct from service failure:
 *   - AJAX transport failure (HTTP non-200 / parse error / network) shows
 *     "AJAX transport failure" with HTTP status and a safe response snippet
 *   - Service failure (Redis down, wrong password) shows the actual service
 *     message inline with FAIL
 *
 * selectors used (must match PHP markup in src/Admin/AdminPage.php):
 *   #up-test-redis-btn         — Test Redis Connection / Test Redis
 *   #up-test-oc-runtime-btn    — Test Object Cache Runtime
 *   #uc-redis-test-result-host — inline Redis result placeholder
 *   #uc-oc-runtime-test-result — inline OC runtime result placeholder
 */
(function () {
    'use strict';

    /**
     * Safe escape: encode <, >, &, ", ' so untrusted content never injects HTML.
     * Falls back to jQuery(text) if document is not available.
     */
    function esc(s) {
        if (typeof s !== 'string') {
            s = String(s);
        }
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    /**
     * Render the Redis test result inline. §26 contract:
     *   PASS:
     *     Redis Connection: PASS
     *     Host / Port / Database / TLS / Phase / Tested-at
     *   FAIL: same shape with explicit failure message
     */
    function renderRedisResult(target, data) {
        if (!target) { return; }
        var ok = !!data.ok;
        var mark = ok ? '&#10003;' : '&#10007;';
        var color = ok ? 'green' : 'red';
        var html = '';
        html += '<table class="form-table" role="presentation" style="background:#f9f9f9;border:1px solid #e0e0e0;padding:6px;margin-top:6px;">';
        html += '<tr><th scope="row" style="width:160px;">Test Result</th><td><span style="color:' + color + ';font-weight:600;">' + mark + ' ' + esc(data.msg || '') + '</span></td></tr>';
        html += '<tr><th scope="row">Host</th><td><code>' + esc(data.host || '') + '</code></td></tr>';
        html += '<tr><th scope="row">Port</th><td><code>' + esc(data.port || '') + '</code></td></tr>';
        html += '<tr><th scope="row">Database</th><td><code>' + esc(data.db || '') + '</code></td></tr>';
        html += '<tr><th scope="row">TLS</th><td>' + (data.tls ? 'Enabled' : 'Disabled') + '</td></tr>';
        html += '<tr><th scope="row">Phase</th><td>' + esc(data.phase || '') + '</td></tr>';
        if (data.timestamp) {
            html += '<tr><th scope="row">Tested at</th><td>' + esc(data.timestamp) + '</td></tr>';
        }
        html += '</table>';
        target.innerHTML = html;
    }

    /**
     * Render the Object Cache Runtime test result inline. §25 contract:
     *   PASS:
     *     Object Cache Runtime: PASS
     *     wp_using_ext_object_cache: Yes
     *     object-cache.php drop-in: ours
     *     Preferred / Active backend
     *     Object Cache Prefix
     *     wp_cache_set / wp_cache_get (cross-request) / wp_cache_delete
     */
    function renderOcRuntimeResult(target, data) {
        if (!target) { return; }
        var ok = !!data.ok || data.complete && data.delete_ok;
        var mark = ok ? '&#10003;' : '&#10007;';
        var color = ok ? 'green' : 'red';
        var yes = '<span style="color:green;">&#10003;</span>';
        var no = '<span style="color:red;">&#10007;</span>';
        var setOk = !!data.set_ok;
        var getOk = !!data.get_ok;
        var crossOk = !!data.cross_ok;
        var deleteOk = !!data.delete_ok;
        var html = '';
        html += '<table class="form-table" role="presentation" style="background:#f9f9f9;border:1px solid #e0e0e0;padding:6px;margin-top:12px;">';
        html += '<tr><th scope="row" style="width:240px;">Runtime Test Result</th><td><span style="color:' + color + ';font-weight:600;">' + mark + ' ' + esc(data.msg || '') + '</span></td></tr>';
        if (typeof data.ext_oc !== 'undefined') {
            html += '<tr><th scope="row">wp_using_ext_object_cache</th><td>' + (data.ext_oc ? yes + ' true' : no + ' false') + '</td></tr>';
        }
        if (data.dropin) {
            html += '<tr><th scope="row">object-cache.php drop-in</th><td><code>' + esc(data.dropin) + '</code></td></tr>';
        }
        if (data.preferred) {
            html += '<tr><th scope="row">Preferred backend</th><td>' + esc(data.preferred) + '</td></tr>';
        }
        if (data.active) {
            html += '<tr><th scope="row">Active backend</th><td>' + esc(data.active) + '</td></tr>';
        }
        if (data.prefix) {
            html += '<tr><th scope="row">Object Cache Prefix</th><td><code>' + esc(data.prefix) + '</code></td></tr>';
        }
        html += '<tr><th scope="row">wp_cache_set</th><td>' + (setOk ? yes + ' PASS' : no + ' FAIL') + '</td></tr>';
        html += '<tr><th scope="row">wp_cache_get (cross-request)</th><td>' + (crossOk ? yes + ' PASS' : no + ' FAIL') + '</td></tr>';
        html += '<tr><th scope="row">wp_cache_delete</th><td>' + (deleteOk ? yes + ' PASS' : no + ' FAIL') + '</td></tr>';
        if (data.phase) {
            html += '<tr><th scope="row">Phase</th><td>' + esc(data.phase) + '</td></tr>';
        }
        if (data.timestamp) {
            html += '<tr><th scope="row">Tested at</th><td>' + esc(data.timestamp) + '</td></tr>';
        }
        html += '</table>';
        target.innerHTML = html;
    }

    /**
     * Render a transport-failure result inline. §27 contract:
     *   AJAX transport failure
     *   HTTP status
     *   raw safe response snippet
     *   action
     */
    function renderTransportFailure(target, action, httpStatus, snippet) {
        if (!target) { return; }
        var no = '<span style="color:red;">&#10007;</span>';
        var html = '';
        html += '<table class="form-table" role="presentation" style="background:#fff3f3;border:1px solid #e0a0a0;padding:6px;margin-top:6px;">';
        html += '<tr><th scope="row" style="width:240px;">' + no + ' AJAX transport failure</th><td></td></tr>';
        html += '<tr><th scope="row">action</th><td><code>' + esc(action) + '</code></td></tr>';
        html += '<tr><th scope="row">HTTP status</th><td><code>' + esc(httpStatus || '') + '</code></td></tr>';
        html += '<tr><th scope="row">raw response snippet</th><td><pre style="margin:0;max-height:120px;overflow:auto;">' + esc(snippet || '') + '</pre></td></tr>';
        html += '</table>';
        target.innerHTML = html;
    }

    /**
     * Find the result container that follows a button.
     *
     * The PHP render layout puts the result block either:
     *   - immediately after the button's <form>, OR
     *   - in a sibling table (legacy transient rendering).
     *
     * We search by id first, then by class.
     */
    function findResultContainer(btn, idHint) {
        if (idHint && document.getElementById(idHint)) {
            return document.getElementById(idHint);
        }
        // Walk siblings looking for a table.uc-result-host after the form.
        var form = btn.closest('form');
        if (form) {
            var sib = form.nextElementSibling;
            while (sib) {
                if (sib.querySelector && sib.querySelector('table')) {
                    return sib;
                }
                sib = sib.nextElementSibling;
            }
        }
        // Fallback: create a fresh container right after the button.
        var div = document.createElement('div');
        div.className = 'uc-ajax-result';
        if (form && form.parentNode) {
            form.parentNode.insertBefore(div, form.nextSibling);
        } else {
            btn.parentNode.appendChild(div);
        }
        return div;
    }

    /**
     * §18/§19 — Click handler state contract: disable button, status=Testing,
     * perform AJAX, render result, re-enable button.
     */
    function withButtonState(btn, statusText, fn) {
        var done = function () {
            btn.disabled = false;
            btn.innerHTML = btn.getAttribute('data-uc-original-label') || btn.innerHTML;
        };
        btn.setAttribute('data-uc-original-label', btn.innerHTML);
        btn.disabled = true;
        btn.innerHTML = esc(statusText);
        // §20 — Capture window.location before AJAX so we can assert it later.
        var beforeUrl = window.location.href;
        Promise.resolve()
            .then(fn)
            .then(function () {
                done();
                // §20 — Assert no navigation occurred.
                if (window.location.href !== beforeUrl) {
                    // Should not happen; log to console so audit can detect.
                    if (window.console && console.warn) {
                        console.warn('[ultimate-performance] navigation detected after AJAX — refresh regression');
                    }
                }
            })
            .catch(function () {
                done();
            });
    }

    /**
     * §14 — AJAX helper. Sends POST to admin-ajax.php with action + nonce.
     * Returns a Promise that resolves with { httpStatus, json, raw }.
     *
     * §27 — Transport failures (non-200, network error, JSON parse error)
     * are reported via the catch path so the caller can render a transport-
     * failure block distinct from service-failure JSON.
     */
    function ucAjax(action, nonce, extra) {
        var url = (window.UP_ADMIN && window.UP_ADMIN.ajaxUrl) || '/wp-admin/admin-ajax.php';
        var body = 'action=' + encodeURIComponent(action) + '&nonce=' + encodeURIComponent(nonce);
        if (extra) {
            Object.keys(extra).forEach(function (k) {
                body += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(String(extra[k]));
            });
        }
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body
        }).then(function (resp) {
            return resp.text().then(function (text) {
                var json = null;
                try {
                    json = JSON.parse(text);
                } catch (e) {
                    json = null;
                }
                return {
                    httpStatus: resp.status,
                    raw: text,
                    json: json
                };
            });
        });
    }

    /**
     * §5/§8 — Test Redis Connection click handler.
     * Single-phase AJAX. Renders result inline.
     */
    function handleTestRedis(btn) {
        var cfg = window.UP_ADMIN || {};
        var nonces = cfg.nonces || {};
        var i18n = cfg.i18n || {};
        var target = findResultContainer(btn, 'uc-redis-test-result-host');
        withButtonState(btn, i18n.testing || 'Testing...', function () {
            return ucAjax('up_ajax_test_redis', nonces.testRedis || '')
                .then(function (r) {
                    if (r.httpStatus === 200 && r.json && r.json.success && r.json.data) {
                        renderRedisResult(target, r.json.data);
                    } else if (r.httpStatus === 403 && r.json && r.json.data) {
                        // §10 — explicit JSON 403 for invalid nonce/capability.
                        renderTransportFailure(target, 'up_ajax_test_redis', '403 Forbidden', r.json.data.message || r.raw);
                    } else if (r.httpStatus === 200 && r.json && r.json.success === false && r.json.data) {
                        // §9 — Service failure (Redis down, wrong password) reported
                        // via the normal success-transport contract.
                        renderRedisResult(target, r.json.data);
                    } else {
                        // §27 — true transport failure.
                        renderTransportFailure(target, 'up_ajax_test_redis', r.httpStatus, r.raw);
                    }
                })
                .catch(function (err) {
                    renderTransportFailure(target, 'up_ajax_test_redis', 'network-error', String(err && err.message || err));
                });
        });
    }

    /**
     * §7/§21/§22/§23 — Object Cache Runtime Test click handler.
     * 3-phase state machine. Each phase is a SEPARATE admin-ajax.php request.
     *
     * phase1 (write) → token → phase2 (cross-request read) → phase3 (delete + verify)
     *
     * §44 — On Phase 2 failure, still attempt Phase 3 cleanup.
     */
    function handleTestOcRuntime(btn) {
        var cfg = window.UP_ADMIN || {};
        var nonces = cfg.nonces || {};
        var i18n = cfg.i18n || {};
        var target = findResultContainer(btn, 'uc-oc-runtime-test-result');
        var token = null;
        var lastResult = null;
        withButtonState(btn, i18n.phase1 || 'Phase 1: write test object...', function () {
            // Phase 1 — write test object.
            return ucAjax('up_ajax_oc_runtime_phase1', nonces.ocRuntimePhase1 || '')
                .then(function (r1) {
                    if (r1.httpStatus !== 200 || !r1.json || !r1.json.success || !r1.json.data) {
                        // Transport failure on phase 1.
                        renderTransportFailure(target, 'up_ajax_oc_runtime_phase1', r1.httpStatus, r1.raw);
                        return null;
                    }
                    var d1 = r1.json.data;
                    lastResult = d1;
                    renderOcRuntimeResult(target, d1);
                    if (!d1.ok || d1.complete || !d1.token) {
                        return null; // Phase 1 failed — stop.
                    }
                    token = d1.token;
                    // Phase 2 — cross-request read.
                    btn.innerHTML = esc(i18n.phase2 || 'Phase 2: verify cross-request read...');
                    return ucAjax('up_ajax_oc_runtime_phase2', nonces.ocRuntimePhase2 || '', { token: token });
                })
                .then(function (r2) {
                    if (!r2) { return null; }
                    if (r2.httpStatus !== 200 || !r2.json || !r2.json.success || !r2.json.data) {
                        renderTransportFailure(target, 'up_ajax_oc_runtime_phase2', r2.httpStatus, r2.raw);
                        // §44 — still attempt Phase 3 cleanup.
                        btn.innerHTML = esc(i18n.phase3 || 'Phase 3: delete test object...');
                        return ucAjax('up_ajax_oc_runtime_phase3', nonces.ocRuntimePhase3 || '', { token: token });
                    }
                    var d2 = r2.json.data;
                    lastResult = Object.assign({}, lastResult, d2);
                    renderOcRuntimeResult(target, lastResult);
                    if (!d2.ok || d2.complete) {
                        // Phase 2 failed — still attempt Phase 3 cleanup (§44).
                        btn.innerHTML = esc(i18n.phase3 || 'Phase 3: delete test object...');
                        return ucAjax('up_ajax_oc_runtime_phase3', nonces.ocRuntimePhase3 || '', { token: token });
                    }
                    // Phase 3 — delete + verify.
                    btn.innerHTML = esc(i18n.phase3 || 'Phase 3: delete test object...');
                    return ucAjax('up_ajax_oc_runtime_phase3', nonces.ocRuntimePhase3 || '', { token: token });
                })
                .then(function (r3) {
                    if (!r3) { return; }
                    if (r3.httpStatus !== 200 || !r3.json || !r3.json.success || !r3.json.data) {
                        renderTransportFailure(target, 'up_ajax_oc_runtime_phase3', r3.httpStatus, r3.raw);
                        return;
                    }
                    var d3 = r3.json.data;
                    lastResult = Object.assign({}, lastResult, d3);
                    renderOcRuntimeResult(target, lastResult);
                })
                .catch(function (err) {
                    renderTransportFailure(target, 'up_ajax_oc_runtime', 'network-error', String(err && err.message || err));
                });
        });
    }

    /**
     * §31 — Attach handlers via id selectors matching the PHP markup.
     */
    function bindButtons() {
        var redisBtn = document.getElementById('up-test-redis-btn');
        var ocRuntimeBtn = document.getElementById('up-test-oc-runtime-btn');
        if (redisBtn && !redisBtn.getAttribute('data-uc-bound')) {
            redisBtn.setAttribute('data-uc-bound', '1');
            // §16 — preventDefault in case type=button missed.
            redisBtn.addEventListener('click', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                handleTestRedis(redisBtn);
            });
        }
        if (ocRuntimeBtn && !ocRuntimeBtn.getAttribute('data-uc-bound')) {
            ocRuntimeBtn.setAttribute('data-uc-bound', '1');
            ocRuntimeBtn.addEventListener('click', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                handleTestOcRuntime(ocRuntimeBtn);
            });
        }
    }

        /**
         * §33 — Guard the secret-clear checkboxes (Redis auth_clear, AMQP pass_clear).
         *
         * These checkboxes, when checked AND the form submitted, PERMANENTLY erase
         * the stored password. To prevent accidental data loss from a stray click
         * or browser autofill, intercept form submission and require explicit JS
         * confirmation before allowing the clear to proceed.
         *
         * Behavior:
         *   - Find every <form> that contains a checkbox named uc[redis][auth_clear]
         *     or uc[amqp][pass_clear].
         *   - On submit, if the clear checkbox is checked, call confirm() with a
         *     clear warning message. If user declines, preventDefault + stop.
         */
        function bindClearPasswordGuards() {
            var forms = document.querySelectorAll('form');
            Array.prototype.forEach.call(forms, function (form) {
                if (form.getAttribute('data-uc-clear-guard')) { return; }
                form.setAttribute('data-uc-clear-guard', '1');
                form.addEventListener('submit', function (ev) {
                    var authClear = form.querySelector('input[name="up[redis][auth_clear]"]');
                    var passClear = form.querySelector('input[name="up[amqp][pass_clear]"]');
                    var needsConfirm = (authClear && authClear.checked) || (passClear && passClear.checked);
                    if (!needsConfirm) { return; }
                    // §33 — explicit user confirmation required.
                    var msg = '';
                    if (authClear && authClear.checked) {
                        msg = 'You are about to CLEAR the saved Redis password.\n\nThis action cannot be undone. The password will be removed from the database.\n\nContinue?';
                    } else if (passClear && passClear.checked) {
                        msg = 'You are about to CLEAR the saved RabbitMQ password.\n\nThis action cannot be undone. The password will be removed from the database.\n\nContinue?';
                    }
                    if (!window.confirm(msg)) {
                        ev.preventDefault();
                        ev.stopPropagation();
                        // Uncheck the box so a re-submit doesn't require declining again.
                        if (authClear) { authClear.checked = false; }
                        if (passClear) { passClear.checked = false; }
                    }
                });
            });
        }

    // §32 — Bind on DOMContentLoaded so all PHP-rendered buttons are present.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            bindButtons();
            bindClearPasswordGuards();
        });
    } else {
        bindButtons();
        bindClearPasswordGuards();
    }

    // Expose for audit/test page introspection.
    window.UltimatePerformanceAdmin = {
        bindButtons: bindButtons,
        bindClearPasswordGuards: bindClearPasswordGuards,
        handleTestRedis: handleTestRedis,
        handleTestOcRuntime: handleTestOcRuntime
    };
})();
