// sw.js — Yükleme Planı PWA Service Worker
const CACHE_NAME = 'yukleme-plani-v289';

// Uygulama kabuğunu önbellekle
const SHELL = [
  './index.php',
  './assets/style.css',
  './assets/app.js',
  './assets/maliyet.css',
  './assets/maliyet.js',
  './assets/hesap.css',
  './assets/hesap.js',
  './assets/icon.svg',
  './assets/icon-192.png',
  './assets/icon-maskable-192.png',
  './manifest.json',
  // Mobil alt çubuk ikonları (config/helpers.php nav_alt_sayfalar()) — çevrimdışı
  './assets/nav-icons/home.svg',
  './assets/nav-icons/records.svg',
  './assets/nav-icons/cikma.svg',
  './assets/nav-icons/beyan.svg',
  './assets/nav-icons/kantar.svg',
  './assets/nav-icons/hks.svg',
  './assets/nav-icons/rapor.svg',
  './assets/nav-icons/mstok.svg',
  './assets/nav-icons/hesap.svg',
  './assets/nav-icons/ptak.svg',
  './assets/nav-icons/defs.svg',
  './assets/nav-icons/users.svg',
  './assets/nav-icons/roles.svg',
  './assets/nav-icons/audit.svg',
  './assets/nav-icons/backup.svg',
  './assets/nav-icons/more.svg'
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
  // Veritabanı yedeği ve her türlü indirme SW'ye HİÇ uğramaz (respondWith yok):
  // aksi hâlde tam DB dökümü CacheStorage'a kalıcı yazılıyordu (DB-Backup-02).
  var u = new URL(e.request.url);
  if (u.pathname.indexOf('admin_db_backup') !== -1 || u.searchParams.get('action') === 'download') return;
  e.respondWith(
    fetch(e.request).then(function(response) {
      // Dosya eki (attachment) ve başarısız aynı-köken yanıtı önbelleğe YAZILMAZ.
      // 'no-store' burada ÖLÇÜT DEĞİL: PHP oturumu (session.cache_limiter=nocache)
      // her sayfaya no-store basar; ölçüt olsaydı çevrimdışı sayfa yedeği biterdi.
      var cd = response.headers.get('Content-Disposition') || '';
      // Opaque (CDN, ör. xlsx betiği) yanıtlar eskisi gibi önbelleğe girer — durumları okunamaz.
      if (/attachment/i.test(cd) || (response.type === 'basic' && !response.ok)) {
        return response;
      }
      var clone = response.clone();
      caches.open(CACHE_NAME).then(function(cache) {
        cache.put(e.request, clone);
      });
      return response;
    }).catch(function() {
      // Alt çubuk ikonları sayfada ?v=<filemtime> ile istenir; SHELL'deki
      // sorgusuz kopya çevrimdışında YALNIZ bu klasör için yedek olarak sunulur
      // (başka sayfalarda sorgu farklı içerik demektir, yok sayılmaz).
      return caches.match(e.request).then(function(r) {
        if (r || e.request.url.indexOf('/assets/nav-icons/') === -1) return r;
        return caches.match(e.request, { ignoreSearch: true });
      });
    })
  );
});
