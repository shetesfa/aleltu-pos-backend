/**
 * ALELTU POS — Offline UX v2.0
 * Full-screen animated offline banner + queue counter + sync toast.
 * Works with the new WiFi signal widget in seller_pos.php.
 */
(function () {
    'use strict';

    // ── 1. CSS ────────────────────────────────────────────────────────────────
    const style = document.createElement('style');
    style.textContent = `
        #aleltu-offline-banner {
            position: fixed; top: 0; left: 0; right: 0; z-index: 99999;
            background: linear-gradient(135deg,#1a0505 0%,#2d0f0f 50%,#180a30 100%);
            border-bottom: 3px solid #ef4444;
            box-shadow: 0 4px 30px rgba(239,68,68,.45);
            transform: translateY(-110%);
            transition: transform .42s cubic-bezier(.34,1.56,.64,1);
            display: flex; align-items: center;
            justify-content: space-between;
            padding: 5px 12px; gap: 8px; flex-wrap: nowrap;
            font-family: 'Segoe UI',Tahoma,sans-serif;
        }
        #aleltu-offline-banner.aob-visible { transform: translateY(0); }

        /* ── pulsing WiFi-off icon ── */
        .aob-wifi-wrap { position:relative; width:30px; height:30px; flex-shrink:0; }
        .aob-wifi-wrap svg { width:30px;height:30px;
            animation: aob-pulse 2s ease-in-out infinite; }
        @keyframes aob-pulse {
            0%,100%{ opacity:1; transform:scale(1); }
            45%    { opacity:.3; transform:scale(.86); }
            70%    { opacity:1; transform:scale(1.07); }
        }
        .aob-ring {
            position:absolute; top:50%; left:50%;
            width:30px; height:30px; border-radius:50%;
            border:2px solid rgba(239,68,68,.65);
            margin:-15px 0 0 -15px;
            animation: aob-ring 2s ease-out infinite;
            pointer-events:none;
        }
        .aob-ring:nth-child(2){ animation-delay:.66s; }
        .aob-ring:nth-child(3){ animation-delay:1.32s; }
        @keyframes aob-ring {
            0%  { transform:scale(.8); opacity:.6; }
            100%{ transform:scale(2);  opacity:0;  }
        }

        /* ── text ── */
        .aob-text { flex:1; min-width:0; }
        .aob-title { font-size:13px;font-weight:700;color:#fca5a5;margin-bottom:0; }
        .aob-sub   { font-size:11px;color:#94a3b8;line-height:1.25; }
        .aob-count {
            display:inline-block;
            background:rgba(239,68,68,.22);color:#fca5a5;font-weight:700;
            padding:1px 8px;border-radius:12px;
            border:1px solid rgba(239,68,68,.4);margin-right:4px;
            transition:all .25s;
        }
        .aob-count.aob-pop { animation:aob-pop .38s ease; }
        @keyframes aob-pop {
            0%  { transform:scale(1); }
            50% { transform:scale(1.4); background:rgba(239,68,68,.5); }
            100%{ transform:scale(1); }
        }

        /* ── view button ── */
        #aob-view-btn {
            background:rgba(239,68,68,.15);
            border:1.5px solid rgba(239,68,68,.5);
            color:#fca5a5;padding:5px 10px;border-radius:16px;
            font-size:11px;font-weight:600;cursor:pointer;
            transition:all .2s;white-space:nowrap;flex-shrink:0;
        }
        #aob-view-btn:hover {
            background:rgba(239,68,68,.3);transform:translateY(-1px);
            box-shadow:0 3px 12px rgba(239,68,68,.3);
        }

        /* ── toast ── */
        #aob-toast {
            position:fixed; bottom:22px; right:18px; z-index:99998;
            background:linear-gradient(135deg,#064e3b,#065f46);
            border:1.5px solid #10b981;border-radius:14px;
            padding:12px 18px;display:flex;align-items:center;gap:10px;
            box-shadow:0 8px 28px rgba(16,185,129,.35);
            color:#d1fae5;font-size:13px;font-weight:600;max-width:310px;
            transform:translateX(120%);pointer-events:none;
            transition:transform .38s cubic-bezier(.34,1.56,.64,1);
            font-family:'Segoe UI',Tahoma,sans-serif;
        }
        #aob-toast.aob-toast-show { transform:translateX(0); pointer-events:auto; }
        .aob-toast-icon { font-size:20px;flex-shrink:0; }
        .aob-toast-t { font-size:13px;font-weight:700;color:#a7f3d0;margin-bottom:2px; }
        .aob-toast-s { font-size:11px;color:#6ee7b7;font-weight:400; }

        /* ── back-online glow ── */
        @keyframes aob-glow {
            0%  { box-shadow:0 0 0 0 rgba(16,185,129,.7); }
            70% { box-shadow:0 0 0 14px rgba(16,185,129,0); }
            100%{ box-shadow:0 0 0 0 rgba(16,185,129,0); }
        }
        .aob-online-glow { animation:aob-glow .85s ease 1 !important; }

        /* ── body offset when banner visible ── */
        body.aob-mode { padding-top: 44px !important; }
        body.aob-mode .container { height:calc(100vh - 44px) !important; }
    `;
    document.head.appendChild(style);

    // ── 2. Banner HTML ────────────────────────────────────────────────────────
    const banner = document.createElement('div');
    banner.id = 'aleltu-offline-banner';
    banner.setAttribute('role','alert'); banner.setAttribute('aria-live','polite');
    banner.innerHTML = `
        <div class="aob-wifi-wrap" aria-hidden="true">
            <div class="aob-ring"></div><div class="aob-ring"></div><div class="aob-ring"></div>
            <svg viewBox="0 0 24 24" fill="none">
                <path d="M2 2L22 22" stroke="#ef4444" stroke-width="2.3" stroke-linecap="round"/>
                <path d="M8.5 16.5C9.9 15.1 11.9 14.7 13.7 15.3" stroke="#ef4444" stroke-width="2" stroke-linecap="round" opacity=".5"/>
                <path d="M5 13C7.2 10.8 10.3 9.8 13.3 10.2" stroke="#ef4444" stroke-width="2" stroke-linecap="round" opacity=".35"/>
                <path d="M1.5 9.5C4.3 6.7 8.1 5 12 5c1.3 0 2.5.2 3.7.5" stroke="#ef4444" stroke-width="2" stroke-linecap="round" opacity=".2"/>
                <circle cx="12" cy="20" r="1.5" fill="#ef4444"/>
            </svg>
        </div>
        <div class="aob-text">
            <div class="aob-title">📡 ኢንተርኔት ተቋርጧል — You are Offline</div>
            <div class="aob-sub">
                መሸጥ ይቀጥሉ — ሽያጭ ተቀምጧል &mdash;
                <span class="aob-count" id="aob-count">0</span> unsynced sale(s) waiting
            </div>
        </div>
        <button id="aob-view-btn" type="button">📋 ሽያጮች ይመልከቱ (View Queue)</button>
    `;
    document.body.appendChild(banner);

    // ── 3. Toast HTML ─────────────────────────────────────────────────────────
    const toast = document.createElement('div');
    toast.id = 'aob-toast';
    toast.innerHTML = `
        <div class="aob-toast-icon" id="aob-ti">✅</div>
        <div><div class="aob-toast-t" id="aob-tt">Done</div>
             <div class="aob-toast-s" id="aob-ts">—</div></div>
    `;
    document.body.appendChild(toast);

    // ── 4. State ──────────────────────────────────────────────────────────────
    let _visible   = false;
    let _prevPend  = 0;
    let _prevSync  = false;
    let _prevOnl   = navigator.onLine;
    let _toastTmr  = null;

    // ── 5. Show/hide banner ───────────────────────────────────────────────────
    function showBanner() {
        if (_visible) return; _visible = true;
        banner.classList.add('aob-visible');
        document.body.classList.add('aob-mode');
    }
    function hideBanner() {
        if (!_visible) return; _visible = false;
        banner.classList.remove('aob-visible');
        document.body.classList.remove('aob-mode');
    }

    // ── 6. Queue count badge ──────────────────────────────────────────────────
    function setCount(n) {
        const el = document.getElementById('aob-count');
        if (!el || el.textContent === String(n)) return;
        el.textContent = n;
        el.classList.remove('aob-pop');
        void el.offsetWidth;
        el.classList.add('aob-pop');
    }

    // ── 7. Toast ──────────────────────────────────────────────────────────────
    function showToast(title, sub, icon) {
        const ti = document.getElementById('aob-ti');
        const tt = document.getElementById('aob-tt');
        const ts = document.getElementById('aob-ts');
        if (ti) ti.textContent = icon  || '✅';
        if (tt) tt.textContent = title || '';
        if (ts) ts.textContent = sub   || '';
        toast.classList.add('aob-toast-show');
        clearTimeout(_toastTmr);
        _toastTmr = setTimeout(() => toast.classList.remove('aob-toast-show'), 4500);
    }

    // ── 8. Main handler wired to syncEngine ───────────────────────────────────
    function onStatus(s) {
        const online   = s.isOnline  || false;
        const syncing  = s.isSyncing || false;
        const pending  = s.pending   || 0;
        const conflicts= s.conflicts || 0;

        if (!online) {
            showBanner(); setCount(pending);
        } else {
            if (!_prevOnl && online) {
                // just came back online
                hideBanner();
                const w = document.getElementById('wifiSignalWidget');
                if (w) { w.classList.add('aob-online-glow'); setTimeout(()=>w.classList.remove('aob-online-glow'),900); }
                showToast('🌐 Connection Restored','Syncing queued sales now…','🌐');
            } else {
                hideBanner();
            }
        }

        // Completed a sync
        if (_prevSync && !syncing && online && pending === 0 && _prevPend > 0) {
            showToast('✅ ሽያጭ ተልኳል!',`${_prevPend} sale(s) uploaded to server`,'✅');
        }

        // New conflicts
        const prevC = window._aobPrevConflicts || 0;
        if (conflicts > prevC) {
            showToast('⚠️ Conflict Detected',`${conflicts} sale(s) need admin review`,'⚠️');
            window._aobPrevConflicts = conflicts;
        }

        _prevOnl  = online;
        _prevSync = syncing;
        _prevPend = pending;
    }

    // ── 9. View queue button ──────────────────────────────────────────────────
    document.getElementById('aob-view-btn').addEventListener('click', () => {
        if (typeof openOfflinePopup === 'function') openOfflinePopup();
    });

    // ── 10. Hook syncEngine (retry until ready) ───────────────────────────────
    function hookSync() {
        if (window.syncEngine && typeof window.syncEngine.onStatusChange === 'function') {
            window.syncEngine.onStatusChange(onStatus);
            (async () => {
                try {
                    if (window.aleltuDB) {
                        const st = await window.aleltuDB.getSyncStats();
                        onStatus({ isOnline: navigator.onLine, isSyncing: false,
                                   pending: st.pending||0, conflicts: st.conflicts||0 });
                    } else if (!navigator.onLine) {
                        showBanner(); setCount(0);
                    }
                } catch(e) { if (!navigator.onLine) { showBanner(); setCount(0); } }
            })();
        } else {
            setTimeout(hookSync, 500);
        }
    }

    // ── 11. Raw browser events (instant, before syncEngine wakes up) ──────────
    window.addEventListener('offline', () => { showBanner(); });
    window.addEventListener('online',  () => {
        hideBanner();
        const w = document.getElementById('wifiSignalWidget');
        if (w) { w.classList.add('aob-online-glow'); setTimeout(()=>w.classList.remove('aob-online-glow'),900); }
        showToast('🌐 Connection Restored','Syncing queued sales now…','🌐');
    });

    // ── 12. Boot ──────────────────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', hookSync);
    } else {
        hookSync();
    }
    // If already offline on page load — show banner immediately
    if (!navigator.onLine) { showBanner(); setCount(0); }

})();
