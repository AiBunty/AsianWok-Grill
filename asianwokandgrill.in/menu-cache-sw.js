const SW_VERSION = "menu-cache-v1";
const SHELL_CACHE = `${SW_VERSION}-shell`;
const RUNTIME_CACHE = `${SW_VERSION}-runtime`;

const SHELL_URLS = [
  "/",
  "/menu.html",
  "/namastemenu.html",
  "/namaste_chef.html",
  "/cocktail.html",
  "/assets/css/style.css"
];

self.addEventListener("install", (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(SHELL_CACHE);
    await Promise.all(
      SHELL_URLS.map(async (url) => {
        try {
          await cache.add(new Request(url, { cache: "reload" }));
        } catch (_) {
          // Ignore missing optional shell files.
        }
      })
    );
    await self.skipWaiting();
  })());
});

self.addEventListener("activate", (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(
      keys
        .filter((key) => key !== SHELL_CACHE && key !== RUNTIME_CACHE)
        .map((key) => caches.delete(key))
    );
    await self.clients.claim();
  })());
});

function shouldRuntimeCache(request) {
  const url = new URL(request.url);
  if (request.method !== "GET") return false;
  if (request.destination === "image") return true;
  if (request.destination === "style" || request.destination === "script" || request.destination === "font") return true;
  if (url.hostname === "docs.google.com" && url.pathname.includes("/gviz/tq")) return true;
  return false;
}

async function staleWhileRevalidate(request) {
  const cache = await caches.open(RUNTIME_CACHE);
  const cached = await cache.match(request);
  const networkPromise = fetch(request)
    .then((response) => {
      if (response && response.ok) {
        cache.put(request, response.clone()).catch(() => {});
      }
      return response;
    })
    .catch(() => null);

  if (cached) {
    networkPromise.catch(() => {});
    return cached;
  }

  const network = await networkPromise;
  if (network) return network;
  return Response.error();
}

self.addEventListener("fetch", (event) => {
  const request = event.request;
  const url = new URL(request.url);

  if (request.method !== "GET") return;

  if (url.origin === self.location.origin && SHELL_URLS.includes(url.pathname)) {
    event.respondWith((async () => {
      const cache = await caches.open(SHELL_CACHE);
      const cached = await cache.match(request);
      if (cached) return cached;
      try {
        const response = await fetch(request);
        if (response && response.ok) {
          cache.put(request, response.clone()).catch(() => {});
        }
        return response;
      } catch (_) {
        return Response.error();
      }
    })());
    return;
  }

  if (shouldRuntimeCache(request)) {
    event.respondWith(staleWhileRevalidate(request));
  }
});

self.addEventListener("message", (event) => {
  const data = event.data || {};
  if (data.type !== "WARM_CACHE" || !Array.isArray(data.urls)) return;

  event.waitUntil((async () => {
    const cache = await caches.open(RUNTIME_CACHE);
    await Promise.all(
      data.urls.map(async (url) => {
        try {
          const req = new Request(url, { mode: "no-cors" });
          const already = await cache.match(req);
          if (already) return;
          const response = await fetch(req);
          if (response) {
            await cache.put(req, response.clone());
          }
        } catch (_) {
          // Ignore single URL failures.
        }
      })
    );
  })());
});
