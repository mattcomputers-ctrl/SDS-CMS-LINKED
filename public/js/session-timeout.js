/**
 * session-timeout.js — client-side idle logout with a 1-minute warning.
 *
 * Config comes from window.SDS_SESSION (emitted by layouts/footer.php for
 * authenticated pages only):
 *   timeoutSeconds  idle window — mirrors the admin setting the server enforces
 *   warnSeconds     how long before logout the warning appears (60)
 *   heartbeatUrl    POST endpoint that refreshes server-side activity
 *   logoutUrl       where to send the browser when the window elapses
 *
 * How it stays in step with the server (AuthMiddleware):
 *   - Every page request already refreshes the server's _last_activity.
 *   - Between requests, browser activity (mouse / keys / scroll / touch)
 *     refreshes a localStorage timestamp — shared across tabs — and
 *     triggers a throttled heartbeat POST so the server sees it too.
 *   - The local window is shortened by one heartbeat interval so the
 *     client always expires *before* the server can. The user therefore
 *     never trips a surprise "session expired" on a click; they get the
 *     warning, then the redirect, and land on /login with a notice.
 *   - Multiple tabs: activity in any tab is visible to all via
 *     localStorage, so one active tab keeps them all alive and one
 *     tab timing out redirects only itself (the others follow within a
 *     second because the shared timestamp is now stale for them too).
 */
(function () {
    'use strict';

    var cfg = window.SDS_SESSION;
    if (!cfg || !cfg.timeoutSeconds) { return; }

    var TIMEOUT_MS    = cfg.timeoutSeconds * 1000;
    var HEARTBEAT_MS  = Math.min(15000, Math.floor(TIMEOUT_MS / 4));
    // Client expires HEARTBEAT_MS early so the server can't beat it.
    var EFFECTIVE_MS  = TIMEOUT_MS - HEARTBEAT_MS;
    var WARN_MS       = Math.min((cfg.warnSeconds || 60) * 1000, EFFECTIVE_MS - 1000);
    var STORAGE_KEY   = 'sds_last_activity';

    var csrfMeta  = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    var lastLocalEvent = 0;
    var lastHeartbeat  = Date.now();
    var activitySince  = false;   // browser activity seen since last heartbeat
    var warningVisible = false;
    var terminated     = false;

    function now() { return Date.now(); }

    function readActivity() {
        try {
            var v = parseInt(localStorage.getItem(STORAGE_KEY), 10);
            if (!isNaN(v) && v > 0) { return v; }
        } catch (e) { /* storage unavailable — fall back to this tab only */ }
        return lastLocalEvent || now();
    }

    function writeActivity(ts) {
        lastLocalEvent = ts;
        try { localStorage.setItem(STORAGE_KEY, String(ts)); } catch (e) { /* ignore */ }
    }

    // ── Activity capture ──────────────────────────────────────────
    function onActivity(e) {
        if (terminated) { return; }
        // While the warning is up, only deliberate input counts, so a
        // stray mouse nudge doesn't dismiss it. Clicking "Stay signed in"
        // or pressing a key does.
        if (warningVisible && e && (e.type === 'mousemove' || e.type === 'scroll' || e.type === 'wheel')) {
            return;
        }
        var t = now();
        if (t - lastLocalEvent < 1000) { return; }   // throttle to 1/sec
        writeActivity(t);
        activitySince = true;
        if (warningVisible) { hideWarning(); }
    }

    ['mousemove', 'mousedown', 'click', 'keydown', 'scroll', 'wheel', 'touchstart'].forEach(function (evt) {
        document.addEventListener(evt, onActivity, { passive: true, capture: true });
    });

    // The request that loaded this page already refreshed the server.
    writeActivity(now());

    // ── Heartbeat ─────────────────────────────────────────────────
    function sendHeartbeat(force) {
        if (terminated) { return; }
        var t = now();
        if (!force && (!activitySince || t - lastHeartbeat < HEARTBEAT_MS)) { return; }
        lastHeartbeat = t;
        activitySince = false;

        fetch(cfg.heartbeatUrl, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' }
        }).then(function (res) {
            if (res.status === 401) {
                // Server already ended the session (timeout elsewhere,
                // restart, or logout in another tab). Go to login now.
                terminate('/login?timeout=1');
            }
        }).catch(function () { /* transient network error — keep going */ });
    }

    // ── Warning dialog ────────────────────────────────────────────
    var overlay = null, countdownEl = null;

    function buildWarning() {
        overlay = document.createElement('div');
        overlay.id = 'sdsSessionWarning';
        overlay.setAttribute('role', 'alertdialog');
        overlay.setAttribute('aria-live', 'assertive');
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);' +
            'display:flex;align-items:center;justify-content:center;z-index:10000;';

        var box = document.createElement('div');
        box.style.cssText = 'background:#fff;color:#111;max-width:420px;width:90%;padding:1.5rem;' +
            'border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,.35);';
        box.innerHTML =
            '<h2 style="margin:0 0 .5rem;font-size:1.25rem;">Still there?</h2>' +
            '<p style="margin:0 0 1rem;">You’ll be signed out in ' +
            '<strong id="sdsSessionCountdown">60</strong> seconds due to inactivity.</p>' +
            '<div style="display:flex;gap:.5rem;justify-content:flex-end;">' +
            '<button type="button" id="sdsSessionLogout" class="btn btn-outline">Sign out now</button>' +
            '<button type="button" id="sdsSessionStay" class="btn btn-primary">Stay signed in</button>' +
            '</div>';
        overlay.appendChild(box);
        document.body.appendChild(overlay);

        countdownEl = box.querySelector('#sdsSessionCountdown');
        box.querySelector('#sdsSessionStay').addEventListener('click', function () {
            writeActivity(now());
            activitySince = true;
            hideWarning();
            sendHeartbeat(true);
        });
        box.querySelector('#sdsSessionLogout').addEventListener('click', function () {
            terminate(cfg.logoutUrl);
        });
    }

    function showWarning(remainingSec) {
        if (!overlay) { buildWarning(); }
        countdownEl.textContent = String(remainingSec);
        if (!warningVisible) {
            overlay.style.display = 'flex';
            warningVisible = true;
            var stay = overlay.querySelector('#sdsSessionStay');
            if (stay) { stay.focus(); }
        }
    }

    function hideWarning() {
        if (overlay) { overlay.style.display = 'none'; }
        warningVisible = false;
    }

    function terminate(url) {
        if (terminated) { return; }
        terminated = true;
        try { localStorage.removeItem(STORAGE_KEY); } catch (e) { /* ignore */ }
        window.location.href = url;
    }

    // ── Tick ──────────────────────────────────────────────────────
    function tick() {
        if (terminated) { return; }
        var idle = now() - readActivity();

        if (idle >= EFFECTIVE_MS) {
            terminate(cfg.logoutUrl);
            return;
        }
        if (idle >= EFFECTIVE_MS - WARN_MS) {
            showWarning(Math.ceil((EFFECTIVE_MS - idle) / 1000));
        } else if (warningVisible) {
            hideWarning();   // another tab saw activity
        }
        sendHeartbeat(false);
    }

    setInterval(tick, 1000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { tick(); }
    });
})();
