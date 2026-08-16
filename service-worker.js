// ─────────────────────────────────────────────────────────────────────────────
// ALELTU POS — Service Worker (Offline-First Unified Cache)
// Version: v4 — caches all PHP pages so the web build works offline too.
// Strategy:
//   • HTML pages     → Network-First, cache on success, serve cache on failure
//   • Static assets  → Cache-First, update in background
//   • API/POST       → Network-Only (never cached; queued by OfflineJsBridge)
// ─────────────────────────────────────────────────────────────────────────────

const CACHE_VERSION  = 'aleltu-pos-v6';
const DYNAMIC_CACHE  = 'aleltu-dynamic-v6';

// PHP pages to pre-cache on install
const PHP_PAGES = [
  './index.php',
  './seller_pos.php',
  './admin_dashboard.php',
  './admin_view_stock.php',
  './super_admin.php',
  './history.php',
  './advanced_report.php',
  './daily_cashier.php',
  './receipt.php',
  './manage_users.php',
  './register_user.php',
  './seller_receive_stock.php',
  './boss_receive.php',
  './change_password.php',
  './conflict_center.php',
  './seller_notifications.php',
];

// Static assets to pre-cache on install
const STATIC_ASSETS = [
  './manifest.json',
  './assets/js/indexeddb-manager.js',
  './assets/js/offline-rules-engine.js',
  './assets/js/outbox-manager.js',
  './assets/js/sync-engine.js',
  './assets/js/device-manager.js',
  './assets/js/offline-ux.js',
  // PWA icons — real PNG files
  './image/icon-192.png',
  './image/icon-512.png',
];

// ── Install: pre-cache PHP shells & static assets ────────────────────────────
self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_VERSION).then((cache) => {
      console.log('[SW] Pre-caching PHP pages & static assets');
      // Cache PHP pages (non-critical — some may 302 on install)
      const phpCachePromise = Promise.allSettled(
        PHP_PAGES.map((url) =>
          fetch(url, { credentials: 'include' })
            .then((res) => { if (res.ok) cache.put(url, res); })
            .catch(() => {}) // Ignore individual failures at install time
        )
      );
      // Cache static assets (best-effort)
      const staticCachePromise = cache.addAll(STATIC_ASSETS).catch((e) => {
        console.warn('[SW] Static pre-cache warning:', e);
      });
      return Promise.all([phpCachePromise, staticCachePromise]);
    })
  );
});

// ── Activate: wipe stale caches ───────────────────────────────────────────────
self.addEventListener('activate', (event) => {
  const VALID = [CACHE_VERSION, DYNAMIC_CACHE];
  event.waitUntil(
    caches.keys().then((names) =>
      Promise.all(
        names.map((name) => {
          if (!VALID.includes(name)) {
            console.log('[SW] Removing old cache:', name);
            return caches.delete(name);
          }
        })
      )
    ).then(() => self.clients.claim())
  );
});

// ── Helpers ───────────────────────────────────────────────────────────────────
function isApiOrMutating(request, url) {
  if (request.method !== 'GET') return true;
  const skip = [
    '/api/', 'save_transaction', 'process_return',
    'logout.php', 'delete_record', 'delete_transaction',
    'export_', 'backup.php', 'update_stock_batch',
    'decrease_stock', 'restore_pending_stock',
  ];
  return skip.some((s) => url.pathname.includes(s));
}

function isHtmlRequest(request) {
  const accept = request.headers.get('accept') || '';
  return accept.includes('text/html');
}

function isStaticAsset(url) {
  return /\.(css|js|woff2?|ttf|eot|png|jpg|jpeg|gif|svg|ico|webp)(\?.*)?$/.test(url.pathname);
}

// ── Fetch: route each request through the right strategy ─────────────────────
self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  // 1. API calls & mutations → pass through to network (never cached)
  if (isApiOrMutating(request, url)) return;

  // 2. HTML pages (PHP) → Network-First with Cache Fallback
  if (isHtmlRequest(request)) {
    event.respondWith(
      fetch(request, { credentials: 'include' })
        .then((networkResponse) => {
          if (networkResponse && networkResponse.status === 200) {
            // Update cache with fresh response
            const clone = networkResponse.clone();
            caches.open(CACHE_VERSION).then((c) => c.put(request, clone));
          }
          return networkResponse;
        })
        .catch(async () => {
          // Offline → serve exact URL cache or fallback to seller_pos.php
          const cached = await caches.match(request, { ignoreSearch: false });
          if (cached) return cached;
          // Try without query string
          const cachedNoQs = await caches.match(url.pathname);
          if (cachedNoQs) return cachedNoQs;
          // Ultimate fallback — last seen seller POS shell
          return caches.match('./seller_pos.php');
        })
    );
    return;
  }

  // 3. Static assets → Cache-First with network fallback & background update
  if (isStaticAsset(url)) {
    event.respondWith(
      caches.match(request).then((cached) => {
        const networkFetch = fetch(request).then((networkResponse) => {
          if (networkResponse && networkResponse.status === 200) {
            const clone = networkResponse.clone();
            caches.open(DYNAMIC_CACHE).then((c) => c.put(request, clone));
          }
          return networkResponse;
        }).catch(() => cached);
        return cached || networkFetch;
      })
    );
    return;
  }

  // 4. Everything else → Network-First, cache on success
  event.respondWith(
    fetch(request)
      .then((networkResponse) => {
        if (
          networkResponse &&
          networkResponse.status === 200 &&
          request.url.startsWith(self.location.origin)
        ) {
          const clone = networkResponse.clone();
          caches.open(DYNAMIC_CACHE).then((c) => c.put(request, clone));
        }
        return networkResponse;
      })
      .catch(() => caches.match(request))
  );
});
