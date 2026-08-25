var V = 'dn-v7';
var SHELL = ['./index.html', './manifest.json', './icon.png', './icon-180.png'];
// CDN libraries to pre-cache after install (scanner libs)
var CDN_PRECACHE = [
  'https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js',
];
// CDN assets we cache on first use (too large to pre-cache)
var CDN_CACHE_ON_USE = [
  'cdn.jsdelivr.net',   // tesseract.js
  'tessdata.projectnaptha.com',  // OCR language data
  'cdnjs.cloudflare.com', // html5-qrcode fallback
];

self.addEventListener('install', function(e) {
  e.waitUntil(
    caches.open(V).then(function(cache) {
      return cache.addAll(SHELL).then(function() {
        // Pre-cache scanner lib in background (non-blocking)
        return Promise.allSettled(CDN_PRECACHE.map(function(url) {
          return cache.add(url).catch(function() {});
        }));
      });
    }).then(function() { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function(e) {
  e.waitUntil(
    caches.keys().then(function(ks) {
      return Promise.all(ks.filter(function(k) { return k !== V; }).map(function(k) { return caches.delete(k); }));
    }).then(function() { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function(e) {
  if (e.request.method !== 'GET') return;
  var url = new URL(e.request.url);

  // CDN scripts/assets: cache-first (they're versioned, won't change)
  var isCdnAsset = CDN_CACHE_ON_USE.some(function(d) { return url.hostname.indexOf(d) !== -1; });
  if (isCdnAsset) {
    e.respondWith(
      caches.match(e.request).then(function(cached) {
        if (cached) return cached;
        return fetch(e.request).then(function(r) {
          if (r.ok) {
            var rc = r.clone();
            caches.open(V).then(function(c) { c.put(e.request, rc); });
          }
          return r;
        });
      })
    );
    return;
  }

  // App shell: cache-first (icons, html)
  if (url.pathname.match(/\.(png|svg|html)$/) || url.pathname === '/') {
    e.respondWith(
      caches.match(e.request).then(function(cached) {
        return cached || fetch(e.request).then(function(r) {
          var rc = r.clone();
          caches.open(V).then(function(c) { c.put(e.request, rc); });
          return r;
        });
      })
    );
    return;
  }
  // API calls: network-first, cache fallback
  e.respondWith(fetch(e.request).catch(function() { return caches.match(e.request); }));
});
