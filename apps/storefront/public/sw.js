const SHELL = "siro-shell-v1";
const SHELL_URLS = ["/offline", "/icon-192.svg", "/icon-512.svg"];
const SENSITIVE_PATHS = ["/api", "/cart", "/checkout", "/account", "/order-result", "/quote"];

function isSensitive(url) {
  return url.origin === self.location.origin && SENSITIVE_PATHS.some((path) => url.pathname === path || url.pathname.startsWith(`${path}/`));
}

self.addEventListener("install", (event) => {
  event.waitUntil(caches.open(SHELL).then((cache) => cache.addAll(SHELL_URLS)).then(() => self.skipWaiting()));
});
self.addEventListener("activate", (event) => {
  event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key !== SHELL).map((key) => caches.delete(key)))).then(() => self.clients.claim()));
});
self.addEventListener("fetch", (event) => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== "GET" || url.origin !== self.location.origin || isSensitive(url)) return;
  if (request.mode === "navigate") {
    event.respondWith(fetch(request).catch(() => caches.match("/offline")));
    return;
  }
  if (url.pathname.startsWith("/_next/static/")) {
    event.respondWith(caches.match(request).then((cached) => cached || fetch(request).then((response) => {
      const copy = response.clone();
      void caches.open(SHELL).then((cache) => cache.put(request, copy));
      return response;
    })));
  }
});
