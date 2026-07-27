// sw.js — Yükleme Planı PWA Service Worker
const CACHE_NAME = 'yukleme-plani-v161';

// Uygulama kabuğunu önbellekle
const SHELL = [
  './index.php',
  './assets/style.css',
  './assets/app.js',
  './assets/maliyet.css',
  './assets/maliyet.js',
  './assets/icon.svg',
  './manifest.json'
];

self.addEventListener('install', function(e) {
  e.waitUntil(
    caches.open(CACHE_NAME).then(function(cache) {
      return cache.addAll(SHELL).catch(function() {});
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', function(e) {
  e.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(
        keys.filter(function(k) { return k !== CACHE_NAME; })
            .map(function(k) { return caches.delete(k); })
      );
    })
  );
  self.clients.claim();
});

// Network-first: önce ağ dene, başarısız olursa önbellekten sun
self.addEventListener('fetch', function(e) {
  if (e.request.method !== 'GET') return;

  // OCR motoru + dil verisi (assets/ocr/): MB'larca, sürümle sabit dosyalar.
  // Cache-first — her taramada yeniden doğrulama için beklenmesin, çevrimdışı da çalışsın.
  if (e.request.url.indexOf('/assets/ocr/') !== -1) {
    e.respondWith(
      caches.match(e.request).then(function(hit) {
        if (hit) return hit;
        return fetch(e.request).then(function(response) {
          var clone = response.clone();
          caches.open(CACHE_NAME).then(function(cache) { cache.put(e.request, clone); });
          return response;
        });
      })
    );
    return;
  }

  e.respondWith(
    fetch(e.request).then(function(response) {
      var clone = response.clone();
      caches.open(CACHE_NAME).then(function(cache) {
        cache.put(e.request, clone);
      });
      return response;
    }).catch(function() {
      return caches.match(e.request);
    })
  );
});
