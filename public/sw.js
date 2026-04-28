const CACHE_VERSION = '0.8.2-ops-hardening';
const CACHE_NAME = 'cajeer-logs-static-' + CACHE_VERSION;
const STATIC_ASSETS = [
  '/assets/css/app.css?v=0.8.2-ops-hardening',
  '/assets/js/app.js?v=0.8.2-ops-hardening',
  '/assets/img/logo.png',
  '/assets/img/icon-192.png',
  '/assets/img/icon-512.png',
  '/manifest.json'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS)).catch(() => undefined)
  );
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))))
  );
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);

  if (url.origin !== self.location.origin) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request));
    return;
  }

  if (url.pathname.startsWith('/api/') || url.pathname === '/health' || url.pathname === '/health/cron' || url.pathname === '/login' || url.pathname === '/logout') {
    event.respondWith(fetch(request));
    return;
  }

  if (url.pathname.startsWith('/assets/') || url.pathname === '/manifest.json' || url.pathname === '/manifest.webmanifest') {
    event.respondWith(
      caches.match(request).then(cached => cached || fetch(request).then(response => {
        const copy = response.clone();
        caches.open(CACHE_NAME).then(cache => cache.put(request, copy)).catch(() => undefined);
        return response;
      }))
    );
  }
});

self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
