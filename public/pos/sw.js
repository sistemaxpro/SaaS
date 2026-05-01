const CACHE_NAME = 'sistemax-pos-desktop-v1';
const APP_SHELL = [
  '/public/pos/index_desktop_dropdown.php',
  '/public/pos/index_desktop_dropdown.php?desktop=1',
  '/public/pos/manifest_desktop.json',
  '/public/pos/logo.png',
  '/public/offline.html',
  '/public/assets/tailwind.css',
  '/public/assets/vendor/alpine.min.js',
  '/public/pos/js/smx-printer.js?v=3'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL)).catch(() => undefined)
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.map((key) => (key !== CACHE_NAME ? caches.delete(key) : Promise.resolve()))
    ))
  );
  self.clients.claim();
});

async function networkFirst(request, fallbackUrl = '') {
  const cache = await caches.open(CACHE_NAME);
  try {
    const response = await fetch(request);
    if (response && response.ok && request.method === 'GET') {
      cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    const cached = await cache.match(request);
    if (cached) return cached;
    if (fallbackUrl) {
      const fallback = await cache.match(fallbackUrl);
      if (fallback) return fallback;
    }
    throw error;
  }
}

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  const isNavigation = request.mode === 'navigate';
  const isPosApi = url.pathname.startsWith('/public/pos/api/');
  const isPosAsset = url.pathname.startsWith('/public/pos/') || url.pathname.startsWith('/public/assets/');

  if (!isNavigation && !isPosApi && !isPosAsset) return;

  event.respondWith(networkFirst(request, isNavigation ? '/public/offline.html' : ''));
});
