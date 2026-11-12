---
name: rol-yonetimi
description: Roller ekranı (roles.php), permission_catalog(), rol seed'i, kilitlenme kilidi, first_allowed_page, Personel Takibi görünürlüğü, hesap köprüleri ve v465 rol matrisi. Yeni yetki/sayfa kapısı eklerken ya da roles.php/users.php/auth.php'ye dokunurken kullan.
---

# Rol Yönetimi ve Rol Matrisi

> CLAUDE.md'den taşındı (v503). Çekirdek kurallar (Critical Rules, mobil, CSRF, migration, deploy) CLAUDE.md'de kalır.

**Rol matrisi (v465):** Roller ekranı sidebar sıralı sayfa/alt başlık satırları ve Gör/Ekle/Düzenle/Sil anahtarları kullanır. Yükleme/Paletleme ve OGL/Siparişler bağımsız; rapor türleri ayrı read/export kapılıdır. Eski roller rol başına genişletilir; ayrıntılı roller yalnız seçilen eylemleri alır. Veri kapsamı rol izinleri yanında seçili depo kodlarıyla saklanır, seçili rol sınırları ve kullanıcı depo atamaları kesişir; admin istisnası korunur. Beyan şirket genelinde, Mail hesap ataması, Hesap kendi/tümü kuralı sürer. Eski günlük snapshot arşivi seçili depo rolünde doğrulanamadığından kapalıdır. Şema/seed migration yok. Detay/test/sınırlar: `docs/ROLES_MATRIX_DELIVERY.md`. Kullanıcı 31 Ekimde bu somut teslimin merge ve canlı testini açıkça onayladı.


## Rol Yönetimi (Sprint Rol-01 / Rol-02)

Sabit 5 rol yerine **admin kendi rollerini tanımlar**. Altyapı (roles /
role_permissions / user_roles) zaten vardı; `roles.php` onun üzerine CRUD koyar.

**Dosyalar:** `roles.php` (liste + oluştur/düzenle/sil) · `config/auth.php`
(`permission_catalog()`, `protected_role_slugs()`, `any_active_user_has_permission()`) ·
`config/helpers.php` (`first_allowed_page()` + sidebar/topnav linki).
**Test:** `php scripts/roles_ui_smoke.php` (ekran + POST akışları) ·
`php scripts/rol_kapilari_smoke.php` (yetki mimarisi değişmezleri).

- **Yetki kataloğu `permission_catalog()`** — 11 modül grubu, 54 yetki, Türkçe
  etiketli. `config/helpers.php`'deki kurulum seed'iyle (`$all_p`/`$pdks_p`)
  AYNI string'ler; `rol_kapilari_smoke` ikisinin ayrışmadığını doğrular.
  Yeni yetki eklerken **üç yeri birden** güncelle: katalog + ilgili sayfadaki
  `can()` kapısı + seed listesi.
- **Seed YALNIZ yetkisi hiç olmayan role uygulanır** (helpers.php migrasyon
  IIFE'si). Eskiden koşulsuz `INSERT IGNORE` idi ve her istekte çalıştığı için
  roles.php'den kaldırılan yetkiyi **sessizce geri yazıyordu** — sistem
  rollerinin yetkisi hiç düzenlenemiyordu. **Koşulu kaldırma.** Bedeli: seed
  listesine sonradan eklenen yetki mevcut rollere kendiliğinden inmez,
  roles.php'den elle verilir.
- **Slug değişmez.** Yalnız oluşturmada üretilir (`role_slug_from_label`,
  TR karakter sadeleştirmesi + `_2` ile tekilleştirme). `is_admin()` ve rozet
  renkleri slug'a bakar; `update_role` `slug` kolonuna DOKUNMAZ.
- **5 sistem rolü silinemez** (`protected_role_slugs()`) — adı/yetkisi
  düzenlenebilir. Kullanıcı atanmış rol de silinemez (önce kullanıcıları taşı).
- **Kilitlenme kilidi:** yazma işlemi transaction içinde yapılır, commit'ten
  önce `any_active_user_has_permission('users.admin')` sorulur; false ise
  rollback. **`users.php` de aynı kilidi taşır** (update_user + toggle_active) —
  oradaki eski koruma yalnız `admin` SLUG'ına bakıyordu ve özel bir roldeki
  `users.admin`'i göremiyordu (üç adımda kalıcı kilitlenme mümkündü).
- **`PDOException`, `RuntimeException`'ın ALT SINIFIDIR** — `catch (PDOException)`
  bloğu her zaman `catch (RuntimeException)`'dan ÖNCE gelmeli, yoksa gerçek DB
  hatası kullanıcıya "kilitlenme" mesajı olarak görünür.
- **`users.admin` = ana anahtar.** Bu yetkiyi verdiğin rol, users.php'den
  kendisine admin rolü atayabilir → fiilen tam yönetici. Sınırlı yönetici
  rolü diye tanıtma.
- **`is_admin()` (slug) ≠ `users.admin` (yetki).** `audit.php` ve
  `admin_db_backups.php` `is_admin()` ile kapılıdır; katalogda karşılıkları
  YOKTUR, yani özel role devredilemez. Bilinçli.
- **Personel Takibi görünürlüğü TEK kaynak `nav_ptak_gorunur()`** (helpers.php):
  sidebar, bottomnav, `first_allowed_page()` ve `index.php`'deki kart + eksik
  çıkış rozeti onu çağırır; izin listesi `personel_takip.php`'nin kapısıyla
  birebir (`pdks_takip_static_smoke` karşılaştırır). Kalıcı PDKS izinleri
  (`attendance.employees/cards/scan`) bu merkezi AÇMAZ — index.php eskiden
  onları da sayıyordu: kart görünüyor, tıklanınca 403. Listeyi elle kopyalama.
- **`first_allowed_page()`** — giriş akışı login → depo_sec → `index.php`'dir ve
  index.php `dashboard.read` ister. Bu yetkisi olmayan rol girişte **403'e
  düşüp sistemi hiç kullanamıyordu** (403 sayfasının tek bağlantısı yine
  index.php; mobilde bottomnav'ın tek düğmesi de oraya gider). index.php artık
  403 basmaz, kullanıcının açabildiği ilk sayfaya yönlendirir. Fonksiyondaki
  her satır **hedef sayfanın KENDİ kapısıyla** birebir aynı koşulu taşır —
  sayfa kapısını değiştirirken burayı da güncelle.
- **Yükleme Bildir yetkisi (v498):** `beyan.yukleme_bildir` ayrı ve bağımsızdır (`beyan.write` onu VERMEZ; tek başına Beyanlar sayfasına girer, yalnız Beyansız Yüklemeler sekmesini görür); HKS'te göndermek için ayrıca `hks.access` gerekir. Kapı `can_yukleme_bildir()` / `beyan_sayfa_erisimi()` (helpers.php).
- **Hesap modülünün sessiz köprüleri kaldırıldı** (`hesap_can()`):
  `reports.read → hesap.read`, `records.write → hesap.write`,
  `records.delete → hesap.delete` eşlemeleri vardı; Roller ekranında Hesap
  kutuları boş bırakılan bir rol yalnız rapor/yükleme yetkisiyle masraf kaydı
  açabiliyordu — **yetki ekranı gerçeği söylemiyordu**. Sidebar `$p_hes` ve
  `index.php`'deki Hesap kartı da aynı anda `hesap.read`'e çekildi. Diğer modül
  yardımcıları (`can_beyan`, `can_maliyet`, `pdks_*_can`) 1:1'dir, köprü yok.

**v505 — Kart (bölüm) yetkileri: OGL · Beyan · Hal Bildirimi (sahip isteği, migration YOK):** Roller matrisine alt kartlar eklendi; yeni matristen kaydedilen rol gizli `role.sections.detailed` işaretleyicisini taşır (roles.php). Kurallar TEK dosyada: `config/role_permission_matrix.php` (`role_sections_derive` / `role_sections_fanout`, `role_ogl_can`, `role_ogl_any`, `role_ogl_section_of`, `role_hks_can`) + `config/helpers.php` (`can_beyan_kart`, `beyan_kart_of`, `can_bsz_gor`, `can_bsz_kart`).
- **OGL:** `siparis.ogl_acik|ogl_evrak|ogl_kapali.{read,create,edit,delete}` = İşlem Bekleyen / Evrak Takımı Bekleyen / Kapanan Sevkiyatlar. `siparis.ogl.read` sayfa girişidir. **Sevkiyat üzerindeki her işlem sevkiyatın DURUMUNA göre kartın yetkisine tabidir** (`siparis_sev_kapi($sev,$eylem)`, `siparis_belge_kapi()`, `siparis_pipeline_authorize`); durum değiştirirken HEDEF kartta da Düzenle gerekir. Yeni sevkiyat/belge = İşlem Bekleyen kartı Ekle. `siparis.ogl.create/edit/delete` işaretleyicili rolde kartlardan TÜRETİLİR (genel kapılar için); yeni sevkiyat uç/sayfası yazarken `role_matrix_page_can('siparis','ogl',…)` DEĞİL `role_ogl_can($durum,$eylem)` / `role_ogl_any()` kullan.
- **`role_matrix_page_can()` birleşim düzeyinde "detailed yoksa modül izni" yedeğini KAYBETTİ** (başka rolün/`siparis.urun.*`'dan türeyen `siparis.read` OGL'yi açıyordu). Eski rollerin sayfa anahtarlarına çevrilmesi yalnız rol başına `role_matrix_expand_permissions()` içinde yapılır — yedeği geri ekleme.
- **Beyan:** `beyan.bek.*` (İşlem Bekleyen) · `beyan.kap.*` (Kapanan = `beyan_kapali_durumlar()`) read/edit/delete (Ekle sayfa düzeyi: `beyan.create` = "+ Yeni Beyan"); `beyan.bsz.read` (Beyansız sekmesi) · `beyan.yukleme_bildir` (Yükleme Bildir = Ekle) · `beyan.bsz_bek|bsz_yap.{read,delete}`. Etkin yetki = sayfa `beyan.read` + kartın eylemi. `beyan.edit/delete/yukleme_sil` işaretleyicili rolde kartlardan türer. Durum başka karta taşıyorsa hedef kartta da Düzenle gerekir (beyan_edit, beyan_eslestir). `beyan.write` işaretleyicili rolde YOKTUR → `can_beyan('write')` kullanma (`edit`).
- **Hal Bildirimi:** `hks.read|create|edit|delete`; `hks.access` Gör'den TÜRETİLİR (sayfa girişi, eski kapılar bozulmaz). `halkayit/api.php` `hks_eylem_sinifi()` TEK harita (CSRF'den önce `hks_eylem_kapisi`): yazan yeni eylem eklersen haritaya yaz (`role_sections_smoke` haritasız yazan eylemi yakalar). `taslak_gonder` (geri alınamaz) = Ekle. Taslak düzenleme eski taslağı sunucuda siler (Düzenle izni yeter). Firma yönetimi yalnız admin.
- **Eski roller** (işaretleyicisiz): sayfa anahtarları kartlara genişler — davranış değişmez; Roller ekranında kaydedilince işaretleyici yazılır. Test: `php scripts/role_sections_smoke.php` · `role_permission_matrix_smoke` · `role_siparis_matrix_smoke` · `beyan_ui_smoke` · `beyansiz_sekme_ui_smoke` · `hks_csrf_kapsam_smoke`.
- **v506:** Roller matrisinde her sayfa satırı kendi renginde (`rol-tone-0..5`, roles.php `render_matrix_rows` + style.css sonu), alt başlıkları/kartları sayfayla AYNI renk. OGL **Ayarlar** (`siparis_ayarlar.php` bağlantısı + sayfa) yalnız Gör yetkisine kapalıdır: Ekle/Düzenle/Sil gerekir (sayfa `forbidden`). Listedeki "Salt okunur yetkiniz var…" notu kaldırıldı.
- **v507 — Roller yeniden tasarlandı + İÇERİK (kart/düğme) yetkileri (migration YOK, yeni tablo YOK):**
  - **İçerik kataloğu TEK yerde `config/role_content.php`** (`role_content_catalog()`): anahtar `ui.<sayfa>.<öğe>`; her öğe `ad · tur(kart|dugme) · gerek(ön koşul izin) · salt · since`. Kapı `icerik_can('siparis.mail_al')` (ui. öneksiz; admin geçer, bilinmeyen anahtar FAIL-CLOSED) ve uçlarda `icerik_kapi()`. **İçerik kapısı mevcut sayfa/kart/Ekle-Düzenle-Sil kapısının YERİNE değil, ona EK olarak (`&&`) kullanılır.** Bugün kapsam: Ana Sayfa (rozetler) · OGL (liste, sevkiyat sayfası kartları ve işlemleri) · Beyan (liste, beyan sayfası, form) · Hal Bildirimi (ekranlar + işlemler; `halkayit/api.php` `hks_eylem_icerigi()` + `firmalar.icerik` → app.html `body.icx-*` sınıfları, asıl koruma sunucuda).
  - **Sürümlü genişletme (yeni içerik anahtarı eklemek hiçbir rolü bozmaz):** `ROLE_CATALOG_VERSION` (şu an 2) + rolün kayıtlı sürümü (`role.catalog.N`; işaretleyicisiz=0, `role.sections.detailed`=1). `role_content_expand()` (expand'ın SON adımı) rolün sürümünden SONRA kataloğa giren (`since`) öğeleri, öğenin `gerek` izni rolde varsa KENDİLİĞİNDEN verir; güncel sürümde kaydedilmiş rolde yalnız açıkça seçilenler kalır. **Yeni içerik öğesi eklerken:** `ROLE_CATALOG_VERSION`'ı artır, öğeye `since` = yeni sürüm yaz, sayfada `icerik_can()` kapısını koy. Roller ekranında Kaydet'e basılınca rol güncel sürüme geçer.
  - **Yeni sayfaya içerik eklemek:** `role_content_catalog()`'a satır anahtarı altında grup + öğe ekle (satır anahtarı `role_permission_matrix()`'teki satır `key`'i olmalı); Roller ekranı, Kullanıcılar özeti ve katalog etiketleri bundan OTOMATİK üretilir. Test `role_content_smoke` ölü/bağlanmamış anahtarı ve ön koşulsuz öğeyi yakalar.
  - **Roller ekranı (roles.php):** liste = rol + erişebildiği sayfa çipleri (`role_ozet()`: Tam / Kısmi / Görüntüleme / Kapalı) + kullanıcı adları + Düzenle/Kopyala/Sil. Tek pencere (`#rolModal`; oluştur · düzenle · kopyala) iki panelli: solda sayfa listesi + durum rozeti (dar ekranda seçici), sağda seçili sayfanın "Sayfa ve bölümler" Gör/Ekle/Düzenle/Sil tablosu + "Ek işlemler" + "İçerik" öğeleri; hazır ayarlar Tam yetki / Yalnız görüntüleme / Kapat (`data-salt` = salt görüntüleme türü); ön koşulu kapalı içerik soluk + not; aynı izin birden çok yerde birlikte değişir; arama içerik adıyla da bulur; "Başka rolden kopyala"; kaydedilmemiş değişiklik uyarısı; `roles.php?duzenle=ID` doğrudan açar. Eski `render_matrix_*`/`.rol-matrix-*` kaldırıldı (CSS satırları dokunulmadan durur — `style.css` yalnız-ekleme kuralı). `roles_modal_render.php` artık modalı önceden açmaz (JS `rolOpenCreate/rolOpenEdit`).
  - **Kullanıcılar (users.php):** yetki yalnız rolden gelir (kişiye özel istisna YOK, şema yok). Satırda "Yetkiler" düğmesi + Yeni/Düzenle penceresindeki canlı özet = seçilen rollerin birleşimi sayfa sayfa (Gör/Ekle/Düzenle/Sil + düzey); özet `role_ozet_sema()` ile `can()` ile AYNI genişletmeden üretilir (eski rol → kart/içerik anahtarları).
  - Test: `role_content_smoke` · `role_sections_smoke` · `roles_ui_smoke` · `node scripts/roles_modal_smoke.js` (`roles_modal_render.php`) · `node scripts/users_ozet_smoke.js` (`users_ozet_render.php`) · `siparis_*` · `beyan_*` · `hks_*`.
- **Frugro Siparişler satırı (katalog sürümü 3, migration YOK):** siparis modülünün sayfaları `role_siparis_pages()` = `ogl, urun, frugro` (matris + katalog + genişletme döngüsü TEK listeden). `ROLE_CATALOG_VERSION` 3'e çıktı; `role_frugro_derive()` katalog sürümü < `ROLE_FRUGRO_SINCE` (3) olan rolde `siparis.ogl.X` → `siparis.frugro.X` türetir (detaylı/kartlı roller dahil; düz roller ayrıca modül izninden genişler) — DB'ye yazmaz, kimse erişim kaybetmez. Roller ekranı eski rolü genişletilmiş haliyle (Frugro işaretli) gösterir; Kaydet `role.catalog.3` yazar ve bundan sonra yalnız açıkça seçilen `siparis.frugro.*` geçerlidir. Sonraki sürüm artışında `role_frugro_derive` eşiği `ROLE_FRUGRO_SINCE` sabitine bağlı kalır (ROLE_CATALOG_VERSION'a DEĞİL). Kapılar: sidebar `$p_frg`, topnav, `nav_alt_izinler['frugro']`, `first_allowed_page`, index kartı = `role_matrix_page_can('siparis','frugro')`. Test: `role_frugro_matrix_smoke` · `frugro_gate_static_smoke` · `dashboard_sidebar_parity_smoke` (`ogl_only_v3` / `frugro_only`).
