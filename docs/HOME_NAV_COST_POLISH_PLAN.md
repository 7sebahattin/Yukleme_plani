# Ana menü ve Hızlı Maliyet görünüm planı

31 Ekim 2026; temel main f2fea43 / v460. Dal:
`codex/home-navigation-cost-polish`. İki uygulayıcı ve bağımsız Codex inceleyici.

Analiz: sidebar'daki modül bağlantılarından Siparişler, Hızlı Maliyet ve
Roller ana sayfada eksik. On ortak muhasebe grubunda51 alt bağlantı var.
Ana Sayfa kendisine yeni kart açmaz; tema/depo/çıkış kontrolleri modül kartı
değildir. Mevcut sidebar modül hedefleri ana sayfa kartlarında bulunacak.

1. Mevcut ana sayfa kartları ve sayaç sorguları korunur. Üç eksik kart eklenir.
   Admin alt kartları `nav_muhasebe_gruplari()` kaynağından başlıkları altında
   üretilir;51 hedef kopyalanmaz. Normal kullanıcıya yeni admin erişimi verilmez.
   Beyan/HKS/stok/Hesap gibi tek yetkili profillerin bölüm görünürlüğü düzeltilir.
2. Birim seçicisinin ortak renderer'ına küçük kapsayıcı ve sağa aşağı üçgen
   eklenir. Üçgen tema rengini izler ve tıklamayı engellemez; native select,
   klavye erişimi,48px alan ve readonly davranışı korunur. Ek kart şablonu
   aynı renderer'ı kullanır. Yalnız ekleme düğmesi altındaki uzun malzeme
   açıklaması kaldırılır; Malzeme kalemi ve hesap açıklaması korunur.
3. Mevcut64px SVG ikon ailesine uygun kabartma/gradyan/gölge taşıyan yeşil
   karo ve altın dolar ikonu `assets/nav-icons/qcost.svg` oluşturulur. Aynı
   dosya ana sayfa, sidebar ve mobil menüde kullanılır; dış kaynak/font yoktur.
4. APP_SURUM ve SW v461; yeni ikon kabuk listesine eklenir. Mevcut Hızlı
   Maliyet HTML cache istisnası ve erişim kapıları değişmez.

Dosyalar: veri/ana sayfa uygulayıcısı `index.php` ve yeni
`scripts/dashboard_sidebar_parity_smoke.php`; arayüz uygulayıcısı
`_hizli_maliyet_form.php`, `assets/hizli-maliyet.css`, yeni `qcost.svg` ve
`scripts/quick_cost_browser_smoke.js`; ana ajan helpers/SW/sürüm/docs ve
ikon entegrasyonu testleri. Aynı dosyada paralel yazıcı yoktur.

Sıra: analiz → plan → iki uygulayıcı kod+lint → bağımsız statik inceleme →
ana ajanın sıralı fonksiyonel/görsel kabulü → yerel commit ve somut sonuç →
AGENTS.md gereği son açık yayın onayı → push/PR/merge ve yayın doğrulaması.

Yeni dashboard testi yalnız gerçek sunum bölümünü ve gerekli saf helpers
fonksiyonlarını çıkarır. Bootstrap, yedek, DB, mail, asıl sayaç sorguları
çalıştırılmaz. Farklı admin/normal/tek yetki profillerinde gerçek sidebar
hedefleri ana kartların alt kümesi olmalı; admin tüm yetkide69 benzersiz
kart, ortak katalogdaki51 hedef birer kez bulunmalı. Yetkisiz admin kartı yok.
Anonim HTML çıktı modu tarayıcı/görsel kabul için kullanılabilir.

Ana ajan dashboard yetki/kart testi, quick sidebar ikon/PWA kontrolü ve
güncellenen quick-cost browser/SW senaryolarını sırayla çalıştırır. Ana sayfa
ve ikon için mobil/masaüstü, iki tema, taşma, dokunma ve okunabilirlik bakılır.
Önceki hesap/store testleri motor/veri değişmediğinden gerekçesiz tekrarlanmaz;
mevcut browser670.000/33,50 ve ek palet regresyonları çalışmaya devam eder.

SQL/migration, hesap motoru, maliyet kayıtları ve Atlas aktarımı kapsam dışıdır.
Gerçek veri testlerde/görsellerde kullanılmaz. Canlı ana sayfa kontrolünde
mevcut günlük yedek akışının çalışabileceği dikkate alınır; ticari sayaç/kayıt
içeriği dışarı çıkarılmaz. Atlas otomasyonu PAUSED ve önceki kabuller aynı.
