// Service Worker para SistemaX PWA
const CACHE_NAME = "sistemax-v6";
const OFFLINE_URL = "/public/offline.html";

// En localhost/dev no forzar cache agresivo; en producción sí.
const DEV_MODE = /(^localhost$|^127\.0\.0\.1$|^dev\.|^staging\.|desarrollo|sistemaxpro-dev)/i.test(self.location.hostname);

// Archivos a cachear (solo en producción)
const STATIC_ASSETS = [
  "/public/login.php",
  "/public/menu/menu.php",
  "/public/offline.html",
  "/public/manifest.json",
  "/public/menu/manifest_menu.json",
  "https://cdn.tailwindcss.com",
  "https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js",
  "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css",
];

// Instalar Service Worker
self.addEventListener("install", (event) => {
  console.log("[SW] Instalando..." + (DEV_MODE ? " (DEV_MODE: sin cache)" : ""));
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      const installAssets = DEV_MODE
        ? [OFFLINE_URL, "/public/manifest.json"]
        : STATIC_ASSETS;
      console.log("[SW] Cacheando assets estáticos");
      return cache.addAll(installAssets).catch((err) => {
        console.log("[SW] Error cacheando algunos assets:", err);
      });
    }),
  );
  self.skipWaiting();
});

// Activar Service Worker — en DEV_MODE limpiar todos los caches existentes
self.addEventListener("activate", (event) => {
  console.log("[SW] Activando..." + (DEV_MODE ? " (limpiando todos los caches)" : ""));
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (DEV_MODE || cacheName !== CACHE_NAME) {
            console.log("[SW] Eliminando cache:", cacheName);
            return caches.delete(cacheName);
          }
        }),
      );
    }),
  );
  self.clients.claim();
});

// Interceptar peticiones
self.addEventListener("fetch", (event) => {
  // Solo interceptar GET requests
  if (event.request.method !== "GET") return;

  const reqUrl = new URL(event.request.url);

  // No tocar requests cross-origin (incluye localhost/127.0.0.1 del Agent)
  if (reqUrl.origin !== self.location.origin) return;

  // No cachear APIs
  if (event.request.url.includes("/api/")) return;

  event.respondWith(
    fetch(event.request)
      .then((response) => {
        // Clonar respuesta para cache
        if (response.status === 200 && response.type !== "opaque") {
          const responseClone = response.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(event.request, responseClone);
          });
        }
        return response;
      })
      .catch(() => {
        // Si falla, buscar en cache
        return caches.match(event.request).then((cachedResponse) => {
          if (cachedResponse) {
            return cachedResponse;
          }
          // Si es una navegación, mostrar página offline
          if (event.request.mode === "navigate") {
            return caches.match(OFFLINE_URL);
          }
          return Response.error();
        });
      }),
  );
});

async function smxPrecacheUrls(urls) {
  const cache = await caches.open(CACHE_NAME);
  const unique = Array.from(new Set((urls || [])
    .map((url) => String(url || "").trim())
    .filter((url) => url.startsWith("/public/"))));

  await Promise.all(unique.map(async (url) => {
    try {
      const response = await fetch(url, {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
      });
      if (response && response.ok) {
        await cache.put(url, response.clone());
      }
    } catch (_) {
      // Ignorar fallos puntuales de precache.
    }
  }));
}

self.addEventListener("message", (event) => {
  const data = event.data || {};
  if (data.type === "PRECACHE_URLS") {
    const task = smxPrecacheUrls(Array.isArray(data.urls) ? data.urls : []);
    if (typeof event.waitUntil === "function") {
      event.waitUntil(task);
    }
  }
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const targetUrl =
    (event.notification && event.notification.data && event.notification.data.url) ||
    "/public/menu/suscripciones_inbox.php";

  event.waitUntil(
    self.clients.matchAll({ type: "window", includeUncontrolled: true }).then((clients) => {
      for (const client of clients) {
        try {
          const clientUrl = new URL(client.url);
          if (clientUrl.pathname === new URL(targetUrl, self.location.origin).pathname) {
            if ("focus" in client) {
              return client.focus();
            }
          }
        } catch (e) {
        }
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow(targetUrl);
      }
      return null;
    }),
  );
});

self.addEventListener("push", (event) => {
  let payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch (error) {
    payload = {
      title: "SistemaX",
      body: event.data ? event.data.text() : "Nueva notificación",
    };
  }

  const title = payload.title || "SistemaX";
  const options = {
    body: payload.body || "Nueva notificación",
    icon: payload.icon || "/public/assets/logo-192.png",
    badge: payload.badge || "/public/assets/logo-192.png",
    tag: payload.tag || "sistemax-push",
    silent: false,
    renotify: true,
    vibrate: [120, 60, 120],
    data: {
      url: payload.url || "/public/menu/suscripciones_inbox.php",
      peer_login: payload.peer_login || 0,
      channel: payload.channel || "",
    },
  };

  event.waitUntil(self.registration.showNotification(title, options));
});
