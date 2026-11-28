// sw.js — Yükleme Planı PWA Service Worker
const CACHE_NAME = 'yukleme-plani-v611';

// Uygulama kabuğunu önbellekle (B-30: yalnız kimliksiz statik varlıklar; .php HTML asla önbelleğe alınmaz)
const SHELL = [
  './assets/style.css',
  './assets/app.js',
  './assets/maliyet.css',
  './assets/maliyet.js',
  './assets/hizli-maliyet.css',
  './assets/hizli-maliyet.js',
  './assets/hizli-maliyet-v2.js',
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
  './assets/nav-icons/qcost.svg',
  './assets/nav-icons/mstok.svg',
  './assets/nav-icons/hesap.svg',
  './assets/nav-icons/mail.svg',
  './assets/nav-icons/siparis.svg',
  './assets/nav-icons/frugro.svg',
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

// Muhasebe sayfa adları (stok.php, kantar.php, malzeme_stok*.php, records.php vb. KAPSANMAZ: ön ek + '_' ya da tam ad)
var SW_MUHASEBE_RE = /\/(cari|kasa|cek|senet|fatura|alis_fat|satis_fat|sevk_irs|dovizli|doviz_kaydet|api_doviz_kur|transfer_fis|ihracat_|stok_|muh_|edonusum_|rapor_merkezi|bi_rapor_|kantar_fisleri|palet_fisleri|mal_kabul_fisleri|arac_yukleme|alinan_siparis|beyanname_siparis|rapor_tanimlari|raporlar_listeler|sifre_deg|sube_|sirket_deg|kullanici_(tanimlari|tercihleri)|depo_tanimlari|veritabani_islemleri|ajanda|mesajlar|gunluk_kurlar|doviz_tanimlari|kayit_bilgisi|waap_|genel_duyurular|guncelleme|program_|diger_uygulama|lisans_)[a-z0-9_]*\.php(\/|$)/;

// B-30 Beyaz Liste (Allowlist): Yalnız aynı-köken /assets/* ve /manifest.json önbelleklenebilir.
// Hiçbir kimlikli .php sayfası, API yanıtı veya indirme CacheStorage'a yazılmaz.
var SW_STATIK_IZIN_RE = /\/(assets\/.+\.(?:css|js|svg|png|jpe?g|webp|ico|woff2?|ttf)|manifest\.json)$/i;

// Network-first (yalnız statik allowlist varlıkları için): önce ağ dene, başarısız olursa önbellekten sun
self.addEventListener('fetch', function(e) {
  if (e.request.method !== 'GET') return;
  // Veritabanı yedeği ve her türlü indirme SW'ye HİÇ uğramaz (respondWith yok):
  // aksi hâlde tam DB dökümü CacheStorage'a kalıcı yazılıyordu (DB-Backup-02).
  var u = new URL(e.request.url);
  // Saved quick costs are authenticated business data; never retain their HTML.
  if (/\/hizli_maliyet(?:_form)?\.php(\/|$)/.test(u.pathname)) return;
  // Hızlı Hesapla (v478): kaydedilmiş fiyat/maliyet hesapları da cihaz önbelleğine yazılmaz.
  if (/\/hizli_hesapla\.php(\/|$)/.test(u.pathname)) return;
  // Hal Kayıt Gönderim Raporu (v494): ticari gönderim verisi — HİÇ önbelleğe alınmaz
  if (/\/halkayit\/gonderim_rapor\.php(\/|$)/.test(u.pathname)) return;
  if (/\/halkayit\/rusum_borc\.php(\/|$)/.test(u.pathname)) return;
  if (u.pathname.indexOf('admin_db_backup') !== -1 || u.searchParams.get('action') === 'download') return;
  // Mail Merkezi: posta içeriği (sayfa, JSON, ek) cihazın CacheStorage'ına HİÇ yazılmaz.
  if (/\/mail(_[a-z]+)?\.php(\/|$)/.test(u.pathname)) return;   // PATH_INFO ('/mail.php/x') da atlanır
  // Siparişler: sayfa, belge/PDF/XLSX ve ZIP uçları HİÇ önbelleğe alınmaz (kişisel/ticari veri; PATH_INFO da atlanır)
  if (/\/siparis(ler|_[a-z]+)?\.php(\/|$)/.test(u.pathname)) return;
  // Fiş düzeltme (v464): cavus_fis_geri_al.php / cavus_fis_rapor.php çavuş + fiş + hakediş verisi taşır — HİÇ önbelleğe alınmaz
  if (/\/cavus_fis_[a-z_]+\.php(\/|$)/.test(u.pathname)) return;
  // Kayıt seçici (v468): çavuş adı + mesai listesi taşır — HİÇ önbelleğe alınmaz
  if (/\/personel_kayit_sec\.php(\/|$)/.test(u.pathname)) return;
  // Aylık Puantaj Çizelgesi (v552): çalışan/çavuş listesi taşır — sayfa + yazdırma HİÇ önbelleğe alınmaz
  if (/\/puantaj_cizelgesi(_yazdir)?\.php(\/|$)/.test(u.pathname)) return;
  // Ürün Siparişleri (urun_siparis*.php): firma/şoför verisi taşır, aynı kural
  if (/\/urun_siparis(_[a-z]+)?\.php(\/|$)/.test(u.pathname)) return;
  // Frugro Siparişler (v512): frugro_siparis/view/pkl/invoice/yukle/kaydet… müşteri/ticari veri taşır — HİÇ önbelleğe alınmaz (assets/frugro_*.js/css SHELL'de DEĞİL)
  if (/\/frugro(_[a-z]+)?\.php(\/|$)/.test(u.pathname)) return;
  // WhatsApp Yükleme Planı (v582): grup mesajları + şoför telefonu taşır — sayfa ve webhook HİÇ önbelleğe alınmaz
  if (/\/(whatsapp_yonetim|api_whatsapp_webhook)\.php(\/|$)/.test(u.pathname)) return;
  // Yükleme Bildir (v498): kişi/firma ad listesi döndürür, HİÇ önbelleğe alınmaz
  if (/\/api_beyansiz_yukleme\.php(\/|$)/.test(u.pathname)) return;
  // Hızlı Cari / Stok Ekle ucu (v611 Faz 2): kart seçenekleri + yeni kart yanıtı — HİÇ önbelleğe alınmaz (B-30 zaten .php'yi dışlar; açık kural)
  if (/\/api_hizli_kart\.php(\/|$)/.test(u.pathname)) return;
  // Muhasebe yer tutucu/gelecek sayfaları (nav_muhasebe_gruplari, v439): cari/kasa/çek-senet/fatura/ihracat/ayarlar — önbelleğe alınmaz
  if (SW_MUHASEBE_RE.test(u.pathname)) return;
  // B-30 Kesin Beyaz Liste Kapısı: aynı-köken statik varlıklar dışındaki TÜM istekler (.php vb.) ağdan geçer, önbelleğe yazılmaz.
  if (u.origin !== self.location.origin || !SW_STATIK_IZIN_RE.test(u.pathname)) return;
  e.respondWith(
    fetch(e.request).then(function(response) {
      // Dosya eki (attachment) ve başarısız aynı-köken yanıtı önbelleğe YAZILMAZ.
      var cd = response.headers.get('Content-Disposition') || '';
      if (/attachment/i.test(cd) || (response.type === 'basic' && !response.ok)) {
        return response;
      }
      var clone = response.clone();
      caches.open(CACHE_NAME).then(function(cache) {
        cache.put(e.request, clone);
      });
      return response;
    }).catch(function() {
      // B-42: /assets/ altındaki tüm varlıklar ?v=<filemtime> ile istenir; çevrimdışında sorgusuz SHELL kopyasına düş.
      return caches.match(e.request).then(function(r) {
        if (r || u.pathname.indexOf('/assets/') === -1) return r;
        return caches.match(e.request, { ignoreSearch: true });
      });
    })
  );
});
