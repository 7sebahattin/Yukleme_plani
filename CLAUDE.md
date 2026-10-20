# Asya Fresh — Claude Code Proje Hafızası

## Project Summary

PHP 8 + MySQL tarım ihracat operasyon yönetim sistemi. Mobil öncelikli, PWA kurulabilir.
Çerçeve yok — saf PHP, vanilla JS, tek CSS (`assets/style.css`), tek JS (`assets/app.js`).

**Canlı:** `asya.scai.tr` (2026-09-27'den beri) · **Test:** `nuverna.derspros.com.tr` (ayrı DB; `derspros.com.tr` 25.12.2026'da bitiyor, yenilenmeyecek)  
**Branch:** `claude/fix-records-print-mobile-WuKdT`  
**SW Cache:** `yukleme-plani-v401` (sw.js — değişiklikte artır; `config/helpers.php`'deki `APP_SURUM` ile aynı sayıda tut)

**Mail Merkezi çevirisi YALNIZ istek üzerine (v369, sahip kararı — token):** gelen mail cron ile çevrilmez; kullanıcı mailde "Türkçeye çevir"e basar (`mail_ceviri_simdi`). Cron kuyruğu (`mail_ceviri_isle`) yalnız `config/local.php`'de `MAIL_TRANSLATE_AUTO=true` ise çalışır (`mail_ceviri_otomatik_acik()`). Otomatik çeviriyi varsayılana geri ALMA. Test: `php scripts/mail_translate_smoke.php` · `php scripts/mail_ui_smoke.php`. Ayrıntı: `docs/MAIL_OPERATIONS.md` §7.

**Mail Merkezi (v370, 3 dalga):** (1) Senkron sağlamlığı: MIME ayrıştırıcı gövdeyi kopyalamaz (`$bas/$son`), DOM'a en çok 512 KB HTML (`MAIL_HTML_DOM_MAX`); yakalanamayan fatal'a karşı kilit dizininde `.isleniyor_<hesap>.json`, aynı UID 2 kez süreci öldürürse "(işlenemedi …)" yer tutucusu (şema yok); UID SEARCH kendi satır sınırı 4 MB (`max_satir_ara`); bağlantı bayt bütçesi dolunca durmak HATA DEĞİL; adres başlığı ham bölünür, encoded-word yalnız adda çözülür (sahte gönderen); ilk tarama/geri dolum `needs_reply=0`; 'failed' giden kayıt `dedupe_key`'i bırakır ('unknown'/'sent' bırakmaz). (2) Arayüz: `$url()` `o`'yu varsayılan taşımaz, her POST sonrası PRG (`mail_yonlendir`), başarısız önizlemede metin oturum + localStorage'dan geri gelir, onay kutuları `.mail-onay-kap` kapsayıcı sorgusu (`.mail-okuyucu`'ya `container-type` VERME), `.mail-bilgi` blok / düğme satırı `.mail-bilgi-eylem`, geri alınamaz düğmelerde `data-onay`, toplu `cevaplandi_toplu`, hesap audit'i değişen şifresiz alanları yazar. (3) 3A: okunmamış rozeti `nav_mail_okunmamis()` (istek başına tek sorgu, hata → 0; PHP basar, app.js yalnız Diğer rozetini gösterir/gizler); "📝 Beyan Taslağı Oluştur" yalnız mail id'sini POST eder (`mail_beyan_taslak.php`, metin oturumda tek kullanımlık, kayıt yapmaz). 3B: yalnız-konu çevirisi (`ceviri_konu`, yalnız `subject_tr`), harcama sayacı `.translate_usage.json` + isteğe bağlı `MAIL_TRANSLATE_GUNLUK_LIMIT` (karakter; sağlayıcıdan ÖNCE reddeder), DeepSeek sözlüğü `MAIL_CEVIRI_SOZLUK`; Gönderilenler klasörü de okunur (ayrı bağlantı/durum satırı, salt okunur): satırlar gelen listelerinden `mail_gonderilen_disla_sql()` ile dışlanır — YENİ gelen-kutusu sorgusuna bu dışlamayı eklemeyi UNUTMA; cevaplar ebeveyni `replied_at` yapar. Test: `mail_*_smoke.php` · `mail_wave3a_smoke.php` · `node scripts/mail_ui_smoke.js` (önce `MAIL_UI_OUT=… php scripts/mail_ui_render.php`). Ayrıntı: `docs/MAIL_OPERATIONS.md`.

**Çavuş tarihli normal süre (v351):** `cavus_form.php` ayrı süre formu + aynı ekranda geçmiş. `config/pdks_cavus_sure.php`, mevcut `audit_log` üzerinde `normal_sure_donemi` olayları; bu kayıtlar işlevsel veri, silinmez. Dahil tarih aralığı / süresiz, son kayıt önceliği; geçmiş snapshot ve onay düzeltmesi, taslak yeniden hesaplama; final varsa tam RED. Fiyat Tam/FM saatlerinden öncelikli, parasal ücret değişmez. Migration yok. Ayrıntı: `docs/CAVUS_SURE_DONEMLERI.md`.

---

## Dosya Haritası (modül bazlı)

Kök `*.php` = sayfa; `config/` = çekirdek; URL'ler sabittir (sayfa taşınmaz/bölünmez).

| Modül | Sayfalar (kök) | Çekirdek (`config/`) | Varlıklar | Test (`scripts/`) |
|---|---|---|---|---|
| Çekirdek / altyapı | `index` · `login` · `logout` · `depo_sec` · `migrate` (admin şema paneli) · `sw.js` · `manifest.json` | `db` (PDO + auto-migration) · `auth` · `helpers` (render_header/footer, sidebar, bottomnav, csrf_check, audit_log_event, can) · `calc` · `calc_helper` · `print_helpers` · `xlsx_export` | `style.css` (TEK CSS) · `app.js` (TEK JS) · `nav-icons/` · `print_base.css` | `rol_kapilari_smoke` · `bottomnav_*` · `export_menu_*` · `suggest_list_smoke` |
| Yükleme / Çıkma | `records` · `cikmalar` · `record_create/edit/view/delete/durum` · `cikma_create` · `_form` · `print_loading` · `records_bulk_print` · `record_excel_template` · `excel_ornek_palet` · `api_kalan` · `api_templates` · `api_etiket_foto` · `api_tanim_ekle` · `cikma_report_toggle` | `calc` · `material_stock_helpers` | `kalan.js` · `templates/excel/` | `record_excel_smoke` |
| Günlük X/Z rapor | `daily_report_create/view/archive` · `print_daily` | — | — | — |
| Kantar | `kantar` · `kantar_create/edit/view/delete/foto` · `_kantar_form` · `kantar_raporu` · `kantar_report_toggle` | — | — | — |
| Stok / Malzeme | `stok` · `malzeme_stok` · `malzeme_stok_islem/import/rapor` · `malzeme_hareketleri` · `malzeme_stok_tehis` · `api_bulk_material` | `material_stock_helpers` | — | `test_material_stock_helpers` |
| Beyan + HKS köprüsü | `beyanlar` · `beyan_view/create/edit/delete/parse/eslestir/bulk_save` · `_beyan_liste` · `api_beyan_bildirim` · `beyan_bildirim_tani` | `helpers` (`beyan_*`, `bb_*`) | — | `beyan_bildirim_smoke` · `beyan_ui_smoke` · `beyan_js_smoke.js` |
| Hal Kayıt (HKS) | `halkayit/index.php` (panel, iframe) · `app.php`/`app.html` (SPA) · `api.php` (JSON) · `taslak_lib.php` (TASLAK YAZMANIN TEK YOLU) · `kisi_havuzu_lib.php` (karşı taraf havuzu) · `dogum_deney_lib.php` + `dogum_deney.php` (doğum tarihi biçim deneyi, admin) · `tekrar_gonder_lib.php` (Gönderilenler → Tekrar gönder, salt okunur) · `hks_soap.php` · `config.php` · `db.php` · teşhis: `tani.php` · `opcache_reset.php` · `endpoint_test.php` · `.htaccess` (include-only PHP kapalı) | — | `halkayit/*.js` (qrcode/jspdf/html2canvas) | `hks_*_test.php` · `hks_kisi_havuzu_smoke` · `hks_dogum_deney_smoke` · `hks_tekrar_gonder_smoke` · `hks_kisi_pencere_smoke.js` · `hks_gonderilenler_smoke.js` |
| Hesap | `hesap` · `hesap_liste/kayit/durum/muhasebe/personel/yazdir/export/sil/dosya/dosya_sil` · `hesap_muhasebe_fis_pdf` · `hesap_config` | `hesap_calc` · `hesap_pdf` | `hesap.css` · `hesap.js` | `hesap_smoke` · `hesap_ui_smoke` · `hesap_izolasyon_smoke` · `hesap_pdf_smoke` |
| Maliyet | `maliyet` · `maliyet_form/view/sil/alanlar/sablon/ambalaj` · `_maliyet_row` · `api_maliyet_link` | `cost_calc` · `cost_link` | `maliyet.css` · `maliyet.js` | `cost_link_smoke` |
| PDKS / Personel / Çavuş | `personel*` · `isci_kartlari` · `isci_tipleri` · `gunluk_*` · `cavus*` · `mesai_*` · `manuel_cikis` · `giris_cikis` · `pdks_nfc_test` · `ters_kart*` | `pdks*.php` (`pdks`, `pdks_gunluk`, `pdks_hakedis`, `pdks_cari`, `pdks_rapor`, `pdks_faz8*`, `pdks_faz9d`, `pdks_ters`) | `pdks.css` · `pdks.js` · `print_pdks.css` · `ters_kart.js` | `pdks_*_smoke` |
| Raporlar | `reports` · `raporlar` · `rapor_malzeme` · `rapor_yazdir` · `kantar_raporu` | `xlsx_export` | — | `rapor_malzeme_xlsx_smoke` |
| Siparişler | `siparisler` · `siparis_view/yukle/kaydet/pkl/belge/dosya/zip/beyan_bagla/ayarlar/yazdir/cari` · `_siparis_liste` | `siparis_core` · `siparis_pdf` · `siparis_eslestir` · `siparis_pkl` · `siparis_beyan` · `siparis_yazdir` · `siparis_cari` · `siparis_evrak` | `siparis.css` · `siparis.js` · `siparis_yazdir.js` · `nav-icons/siparis.svg` · `templates/excel/pkl_v14_blank.xlsx` | `siparis_core_smoke` · `siparis_pdf_smoke` · `siparis_pkl_smoke` · `siparis_ui_smoke`(`.js`) · `siparis_gate_static_smoke` · `siparis_yazdir_smoke`(`.js`) · `siparis_pkl_mail_smoke` · `siparis_cari_smoke` |
| Yönetim | `definitions` · `users` · `roles` · `audit` · `admin_db_backups` | `db_backup_helpers` | — | `roles_ui_smoke` · `db_backup_smoke` · `roles_modal_*` |
| **Kaldırılan / 410 tombstone** | `admin_db_backup_download` · `hesap_sahipsiz` · `depo_tasima` · `firma_eslestirme` · `fix_brand` · `repair_xz_tables` · `faz8b_migrate` · `record_new` | — | — | `rol_kapilari_smoke` (değişmezleri denetler) |
| Klasörler | `scripts/` (testler + `create_admin_user`, `deploy*`, `db_backup_cron`) · `scripts/arsiv/` (biten tek seferlik araçlar; çalıştırılmaz) · `docs/` · `docs/arsiv/` (biten faz/sprint belgeleri) · `tools/arsiv/` · `templates/excel/` · `storage/` (yedekler) · `uploads/` · `vendor/` | | | |

- **Tombstone politikası:** deploy dosya SİLMEZ. Web'den erişilen bir sayfayı kaldırırken dosyayı silme; içeriği 410 tombstone olur (DB/oturum/require YOK, ≤15 satır, kalıcı ekrana link). Tombstone'u SİLME, boş kalsın. Kök `SYSTEM_AUDIT_REPORT.md` de aynı sebeple 3 satırlık yer tutucudur (asıl rapor `docs/arsiv/`).
- `scripts/`, `tools/`, `docs/`, `storage/` alt klasörleriyle `.htaccess` ile web'e kapalıdır.

**docs/ referans:** `@docs/ARCHITECTURE.md` · `@docs/SECURITY_NOTES.md` · `@docs/NEXT_TASKS.md` · `@docs/DEPLOY_WORKFLOW.md` · `@docs/EXCEL_EXPORT_ANALIZ.md` — biten faz/sprint belgeleri `docs/arsiv/` altındadır (`docs/` web'e kapalı, v281).

---

## Critical Rules

- **Mobil görünümü bozma** — `< 768px` kurallarına dokunurken çok dikkat et.
- **DB migration yalnızca açık GO ile** — "GO veriyorum" olmadan migration çalıştırma.
- **Rollback önce raporla**, otomatik yapma.
- **Her POST'ta CSRF** — `csrf_check()` artık JSON-aware (403 + JSON döner).
- **Her write/delete işleminde** uygun `can()` permission kontrolü.
- **Kritik write/delete/lock audit'e** — `audit_log_event()` kullan.
- **Hassas veri audit'e yazma** — password/token/csrf/cookie/foto_data filtrelenir.
- **`yuklendi` durumu = kilitli** — yalnızca `records.unlock` açabilir, `revision_reason` zorunlu.
- **KG ekranda tam sayı ve virgülsüz** — CSV decimal koruyabilir.
- **Kişisel isim/e-posta örneklerde kullanma.**
- **Kaldırılan web sayfası = 410 tombstone** (DB/oturum yok, ≤15 satır, kalıcı ekrana link; deploy dosya silmez) — `rol_kapilari_smoke` değişmezleri denetler; dosyayı `git rm` ETME.
- **"Canlıya al" = PR açıp `main`'e merge et** — bkz. `@docs/DEPLOY_WORKFLOW.md`. Merge sunucuya **OTOMATİK yansır**: GitHub push webhook'ları `https://asya.scai.tr/deploy.php` (canlı) ve `https://nuverna.derspros.com.tr/deploy.php` (test) adreslerini tetikler — `main`'e merge İKİ siteye birden iner, dosyalar ~dakikalar içinde iner (doğrulandı 2026-09-13). **Kullanıcıdan SSH'dan bir şey çalıştırmasını İSTEME** — doküman uzun süre yanlışlıkla bunu söylüyordu. Doğrulama: hard refresh → sidebar'daki `APP_SURUM`. Webhook'un **Secret'ı boş**; kökteki `deploy.php` repoda değil ve deploy onu bilerek atlar (koruma listesi).

---

## Role / Permission Matrix

| Yetki | Admin | Operator | Viewer | Muhasebe |
|---|:---:|:---:|:---:|:---:|
| records.read | ✓ | ✓ | ✓ | ✓ |
| records.write | ✓ | ✓ | — | — |
| records.unlock | ✓ | — | — | — |
| kantar.read/write | ✓ | ✓ | — | — |
| stok.read/write | ✓ | ✓ | — | ✓ read |
| reports.read/export | ✓ | ✓ | — | ✓ |
| defs.read/write | ✓ | read | — | — |
| hesap.read | ✓ | ✓ | ✓ | ✓ |
| hesap.write | ✓ | ✓ | — | ✓ |
| hesap.approve/pay | ✓ | — | — | ✓ |
| hesap.delete | ✓ | — | — | — |
| hesap.admin (tüm personeli görür) | ✓ | — | — | — |
| siparis.read | ✓ | ✓ | ✓ | ✓ |
| siparis.write | ✓ | ✓ | — | — |
| siparis.delete | ✓ | — | — | — |
| siparis.match | ✓ | ✓ | — | — |
| users.admin | ✓ | — | — | — |
| is_admin() | ✓ | — | — | — |

**Hesap:** her rol yalnız KENDİ hesabını görür. Başkasının hesabı + sahipsiz kayıt =
`hesap.admin` (seed'de yalnız Admin) ya da `is_admin()`. Muhasebe'nin approve/pay'i
yalnız kendi görebildiği (= kendi) kayıtlarda çalışır; başka personelin masrafını
yönetici onaylar. `hesap.admin`'i özel role vermek = o rol HERKESİ görür.

---

## UI / Breakpoint Mimarisi

### CSS Breakpoints (Sprint 32 sonrası)

| Genişlik | Davranış |
|---|---|
| `< 768px` | Mobil: bottomnav görünür, topbar görünür, sidebar gizli |
| `768–899px` | Tablet: topbar görünür, sidebar gizli, 2-kolon kart grid |
| `≥ 900px` | Desktop: **sol sidebar** (220px), topbar+bottomnav gizli |
| `≥ 1024px` | Desktop: `.pc-only` tablo görünür, `.mobile-only` kartlar gizli |
| `≥ 1280px` | Geniş: sidebar 260px, max-width 1400px, 4-kolon grid |

### Sidebar (Sprint 32)

`render_desktop_sidebar()` — `config/helpers.php` içinde, `render_header()` tarafından çağrılır.
`position: fixed; left:0; top:0; bottom:0;` — z-index 100.
Gruplar: Operasyon / Stok / Raporlama / Yönetim.
Permission'lar `can()` / `is_admin()` ile kontrol edilir.
**Sıra + adlar (v391, sahip kararı):** Ana Sayfa · **OGL Siparişler** · Beyanlar · Hal Bildirimi · Mail Merkezi · **Yükleme Planları** (`records.php`) · **Paletleme** (`cikmalar.php`, eski "Çıkmalar") · **Mal Kabul** (`kantar.php`, eski "Kantar") · Raporlar · Malzeme Stok · Hesap · Personel Takibi; ardından Yönetim. Aynı sıra/adlar masaüstü sidebar, tablet üst menü (`topnav`) ve mobil alt çubuk + "Diğer" sayfasında (`nav_alt_sayfalar()` anahtar SIRASI = sidebar sırası; soğuk başlangıç `records, hks, ptak, rapor`'dan sonra bu sırayı izler) geçerlidir. Yalnız GEZİNME adları değişti — sayfa içi başlıkları, URL'ler ve yetki anahtarları aynı. **v393: "Yükleme Planları" (eski "Yüklemeler Planı") 🚚 ve "Paletleme" 📋 — sidebar + ana sayfa kartı emojileri, mobil alt çubuk ikonu/rengi (`records` → `cikma.svg` turuncu, `cikma` → `records.svg` mavi; kısa ad "Yük. Planı") takas edildi.** **v392: ana sayfa kartları (`index.php`) da AYNI sıra ve adlarla** (OGL Siparişler · Beyanlar · Hal Bildirimi · Mail Merkezi · Yükleme Planları 📋 · Paletleme 🚚 · Mal Kabul · Raporlar · Malzeme Stok · Hesap · Personel Takibi; "Hesabım" kartı "Hesap" oldu). Yeni sayfa eklerken sidebar + `nav_alt_sayfalar()` + `topnav` + `index.php` kartlarını birlikte güncelle. Test: `bottomnav_render/smoke` (`$SOGUK` sırası `bottomnav_render.php`'de bu sıranın aynasıdır).

### Z-index Mimarisi

| Katman | z-index |
|---|---|
| Topbar / Sidebar | 100 |
| Kebab dropdown | 200 |
| Bottomnav | 500 |
| Palet modal (.pm-overlay) | 600 |
| Alt çubuk "Diğer" sayfası (.bn-sheet-ovl) | 600 |
| Kalan modal (#kalanModal) | 1000 |
| Etiket/Crop overlay | 3000 |

**Yeni modal eklerken z-index ≥ 600** kullan.

### Modal içinde `<form>` — KRİTİK

`.pm-dialog` bir **flex kolondur** (`max-height: 90vh` + `overflow: hidden`) ve
`.pm-body` `flex:1 + overflow-y:auto` ile kaydırılır. Araya `<form>` sarmalayıcı
girdiğinde (users.php, personel_form.php, roles.php deseni) bu zincir kırılır:
form normal blok olduğu için gövdeye dayanacak yükseklik kalmaz, içerik kadar
uzar ve dialog'u aşan kısım **sessizce kesilir** — gövde hiç kaydırılamaz,
alttaki alanlar ve Kaydet/İptal düğmeleri **erişilemez** olur.

```css
/* style.css — SİLME */
.pm-dialog > form { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; }
```

`min-height: 0` şart (flex öğesinin varsayılan `min-height:auto` değeri küçülmeyi
engeller). Kısa modallerde etkisizdir. **Uzun modal eklediğinde tarayıcı testini
çalıştır** — PHP/statik testler düzen (layout) hatasını GÖREMEZ; bu hata
`.pm-dialog`/`.pm-body` kuralları kaynakta doğru göründüğü hâlde aylarca fark
edilmedi:

```
php scripts/roles_modal_render.php > _test_roles.html
node scripts/roles_modal_smoke.js     # masaüstü + tablet + mobil ölçer
```

**Native `<dialog class="pm-dialog">`** (yalnız `gunluk_isci_puantaj_detay.php`):
`.pm-dialog{display:flex}` tarayıcının kapalı-dialog gizlemesini ezer — kapalı
dialog'lar sayfada üst üste görünür ve formları gönderilebilir (v287).
`dialog.pm-dialog:not([open]){display:none}` kuralını **SİLME**. Test:
`php scripts/pdks_puantaj_dialog_render.php > _test_puantaj_dialog.html` →
`node scripts/pdks_puantaj_dialog_smoke.js`.

Test; footer'ın ekran içinde olduğunu, gövdenin gerçekten kaydırıldığını, en
alttaki kutunun görünüp **tıklanabildiğini** ve yatay taşma olmadığını doğrular.
Ölçümden önce **400ms bekler** — açılış animasyonu (220ms) bitmeden alınan
ölçüm yanıltır.

### Overflow Kuralı — KRİTİK

```css
html { overflow-x: clip; }   /* DOĞRU — iOS scroll korur */
/* html { overflow-x: hidden; }  YANLIŞ — iOS dikey scroll kilitler */
```

### iOS Safe Area

```css
/* <768px: alt çubuğa göre yer bırakan HER kural --bn-h kullanır (sabit px YOK) */
.container { padding-bottom: calc(var(--bn-h) + 12px + env(safe-area-inset-bottom, 0px)); }
.bottomnav { padding: 0 8px calc(8px + env(safe-area-inset-bottom, 0px)); }
```

### iOS Zoom Önleme

```css
@media (max-width: 767px) { input, select, textarea { font-size: 16px; } }
```

---

## Veritabanı Şeması (Özet)

```sql
loading_records     -- type: 'yukleme'|'cikma', durum, locked_at/by, revision_reason
loading_pallets     -- palet satırları (FK: loading_record_id)
pallet_materials    -- malzeme satırları (FK: loading_pallet_id)
material_definitions
material_templates / material_template_items
account_transactions / account_files  -- Hesap modülü
audit_log           -- İşlem geçmişi
users / roles / role_permissions
kantar_gruplar / kantar_kayitlar
customs_declarations -- Beyanlar (+ vehicle_plate, hks_durum)
hks_firmalar / hks_taslaklar / hks_gonderilenler / hks_kv   -- Hal Kayıt modülü
beyan_hks_bildirim  -- Beyan ↔ HKS bildirim bağı ve geçmişi
hks_eslesme         -- Serbest metin ↔ HKS katalog id eşlemeleri (öğrenilen)
```

**Auto-migration:** `config/db.php` açılışta `type` kolonu ve index'leri ekler.

---

## Hesaplama Mantığı

**Dara — ham sakla, sadece toplamı yuvarla:**

```js
// DOĞRU — app.js
dara = kasaAdeti * kasaKg + paletKg + extra;  // ham, yuvarlama yok
totDara = Math.round(hammToplamDara);           // sadece toplamda
```

```php
// DOĞRU — calc.php
$dara = round($kasa_total + $palet_total + $extra_total, 3);
$net  = round(max(0, $brut - $dara), 3);
```

---

## Mobil Alt Çubuk — bottomnav (Sprint Alt-Menü-01)

Onaylı tasarım "Varyant A · Kabartma Karo": yüzen yuvarlak dok, her sekme renkli
3B karo (SVG). **Ana Sayfa solda sabit, "Diğer" sağda**, arada kullanıma göre
dolan slotlar: **390px altında 3, üstünde 4** (sunucu hep 4 çizer, CSS
`@media (max-width:389px)` 4.'yü gizler). Yalnız `<768px`'te görünür.

**Dosyalar:** `config/helpers.php` (`nav_aktif_anahtar` · `nav_alt_sayfalar` ·
`nav_alt_soguk_sira` · `nav_alt_izinler` · `nav_alt_cerez_oku` · `nav_alt_model` ·
`nav_alt_ciz`) · `assets/style.css` ("Bottom Navigation" bloğu) · `assets/app.js`
(sondaki bottomnav modülü) · `assets/nav-icons/*.svg` (16 ikon, sw.js SHELL'de).

- **Aktif bölüm TEK kaynak: `nav_aktif_anahtar()`** — sidebar da çubuk da onu
  okur. Hal Kayıt'ta yalnız `hks` (Ana Sayfa değil — `$a_home`'daki `!$in_hks`),
  `maliyet_*`'te `rapor`. Yeni bir sayfa ailesi eklerken ORAYA ekle.
- **Sayfa kaydı `nav_alt_sayfalar()`** saf veridir (DB/yetki yok): etiket,
  kısa etiket, renk, grup, **ikon yolu**. İkonu PNG/WebP'ye çevirmek yalnız
  `ikon` alanını değiştirmektir (`?v=<filemtime>` otomatik; sw.js SHELL'i güncelle).
- **Yetki kapısı `nav_alt_izinler()`** — her satır HEDEF SAYFANIN kendi
  kapısıyla aynı (`first_allowed_page()` ilkesi). Kapısı değişen sayfada
  burayı da güncelle. Stok (`stok.php`), maliyet, kantar raporu bilerek YOK.
- **Ana Sayfa hedefi:** `dashboard.read` → `index.php`, yoksa
  `first_allowed_page()`; o da null ise Ana Sayfa çizilmez. Ana Sayfa ASLA 403
  veren bir bağlantı olmaz; `index.php`'de aktiftir — `dashboard.read`'i
  olmayan kullanıcıda hedefi `first_allowed_page()` olduğu için O sayfa
  ailesinde (ör. Personel Takibi) de `aria-current` taşır (bottomnav_smoke
  bunu sabitliyor). Ne Ana Sayfa ne
  izinli sayfa varsa çubuk hiç basılmaz ve body `bn-yok` alır (`--bn-h: 0`).
- **"Diğer"** yalnız slotlara sığmayan sayfa varsa çıkar (tam 4 aday → yalnız
  dar ekranda, `.bn-more-dar`). Güncel sayfa görünen slotta değilse "Diğer"
  onun ikonunu + rozet + etiketini alır ve `aria-current="page"` taşır (dar
  ekranda 4. slot için bunu app.js yapar, genişlik değişince de). Alt sayfa
  sidebar gibi gruplu (Operasyon / Yönetim), aktif depo rozetini gösterir,
  Esc/✕/arka plan kapatır, odağı Diğer'e döndürür.
- **Kullanım YALNIZ cihazda:** `localStorage['asya_nav_kullanim_<uid>']` =
  `[[anahtar, ms], …]` (en çok 90 gün / 200 kayıt; aynı bölümde 30 dk içindeki
  art arda yüklemeler TEK ziyaret). Puan = Σ 0,5^(gün/14); Ana Sayfa sayılmaz.
  **Histerezis:** dışarıdaki sayfa en zayıf slotun yerine ancak
  `puan > en zayıf × 1,25 + 0,5` ise girer; kalan slot yerini korur. Soğuk
  başlangıç: Yüklemeler, Bildirim, Personel, Raporlar, sonra sidebar sırası.
- **Sabitle:** "Diğer" → "Sabitle" modu, en çok 4. Sabitler önce gelir ve
  sıralanmaz; `localStorage['asya_nav_sabit_<uid>']` + çerezde `p:`.
- **Çerez sözleşmesi `asya_nav`** (app.js yazar, `encodeURIComponent`, path=/,
  180 gün, SameSite=Lax): `u:<uid>;s:k1,k2,k3,k4;p:k1`. PHP `u` tutmazsa YOK
  SAYAR; her anahtar **beyaz liste + yetki kapısından** geçer — sahte çerez
  yalnız izinli sayfaları yeniden sıralayabilir. PHP sırayı çizer; app.js
  yüklemede yeniden sıralar ve **yeni işaretleme ÜRETMEZ**: izinli ama slotta
  olmayan sayfalar dokta `hidden` öğe olarak durur, JS yalnız yer değiştirir.
- **`--bn-h`** (style.css `:root`, 92px = dok ~84 + alt boşluk 8; güvenli alan
  HARİÇ; ≥768px ve `bn-yok`'ta 0): çubuğa göre yer bırakan HER kural
  `calc(var(--bn-h) + … + env(safe-area-inset-bottom, 0px))` kullanır —
  `.container` (mobil), `halkayit/index.php` iframe payı, `.bb-bar`,
  `hesap.css .hs-save`, `pdks.css` kiosk, `[data-record-id]` scroll-margin.
  **Sabit px yazma** — eski 58px HKS payı çubuğu büyütünce iframe'in altını örttü.
- `.bottomnav` tam genişlik şeffaf kapsayıcıdır (z-index 500, `pointer-events:none`),
  görünen dok `.bn-dock`; depo şeridi `.bottomnav::before` (`--depot-accent`).
  Koyu temada pasif ikon `saturate(.55) brightness(.86)`; aktif karo
  `translateY(-10px) scale(1.14)` + renkli alt çizgi, basınca `.9`;
  `prefers-reduced-motion`'da hareket yok. Etiketler sığmazsa 9.5px'e iner
  (`.tight`), yine sığmazsa `data-xs` kısa hâli.
- **Test:** `BOTTOMNAV_OUT=/tmp/bn php scripts/bottomnav_render.php` →
  `BOTTOMNAV_OUT=/tmp/bn node scripts/bottomnav_smoke.js` (Playwright; yoksa
  atlar). (A) tasarımdan bağımsız değişmezler, (B) bu tasarım: slot sayıları,
  tek `aria-current`, HKS/maliyet aktifliği, Ana Sayfa hedefi, JS kapalı sunucu
  sırası + sahte/başka kullanıcı çerezi, histerezis, Diğer aç/kapat, Sabitle,
  gerçek http kökeninde çerez yazımı, koyu tema, azaltılmış hareket. Çubuğa
  dokunduysan çalıştır.
- **`bnAltPay()` / `ekranAlti()` app.js'in EN ÜSTÜNDE, IIFE'lerin DIŞINDADIR** —
  dosya dört ayrı IIFE'dir; ilkinin içine konunca öneri kutusu (ikinci IIFE)
  her odaklanmada ReferenceError veriyordu. "Aşağıda yer var mı" kararı
  `ekranAlti()` ile verilir, ama `position:fixed` bir öğenin `bottom`'u
  viewport'un altından ölçüldüğü için `bottom` değerine `--bn-h` DÜŞÜLMEZ.
  Test: `node scripts/suggest_list_smoke.js`.

---

## Çavuş Ücreti (Faz 8B eki)

Çavuşun KENDİ günlük ücreti — **zorunlu değil**. Tablo `foreman_daily_rates`
(`foreman_worker_rates`'in worker_type_id'siz eşdeğeri, tarihli valid_from/valid_to).
Giriş: `cavus_fiyatlari.php`'de Tam/Yarım/FM kartının ALTINDA ayrı kart
(`form=cavus_ucret`; mevcut form `form=oran`). Kod: `config/pdks_faz8b.php`
`pdks_faz8b_cavus_ucret_*`. Test: `php scripts/pdks_cavus_ucret_smoke.php`.

- **Otomatik satır:** `pdks_faz8b_hakedis_hesapla()` o oturumda ≥1 işlenmiş (voided
  olmayan) dönem varsa ve o tarihte geçerli ücret tanımlıysa 1 satır ekler
  (`worker_type_id=NULL`, `work_period_id=NULL`, "Çavuş Ücreti"). Çavuş kart basmaz.
  Ücret yoksa satır da yoktur ve **eksik SAYILMAZ**. Tutar Tam/Yarım'dan bağımsız,
  günlük sabit. Cari/ekstre/rapor/toplu döküm `total_amount` okuduğu için oralara
  kendiliğinden yansır — tüketicilere satır bazlı kod EKLEME.
- **Aynı gün İKİ oturum olabilir** (foreman+work_date+depo UNIQUE): ücret YALNIZ o
  günün en küçük id'li, dönemi olan oturumuna ("ankor") yazılır; bir oturum ücreti
  FİNAL olarak içerdiyse başka oturum ASLA almaz (çift ödeme yapısal olarak engelli).
  Dönem değişince kardeş taslak da `needs_recalculation` alır
  (`pdks_faz8b_cavus_ucret_kardes_isaretle()` — faz8e/8h/8j çağırır).
- **Para birimi** işçi satırlarından farklıysa mevcut `karisik_para_birimi` kapısı
  hesaplamayı durdurur (ayrı mesaj yok).
- **Kendi migrasyonu var** (`pdks_faz8b_cavus_ucret_migrate()`; `cavus_fiyatlari.php`
  tablo yoksa açılışta çağırır, `migrate.php`'de de kart var). `pdks_faz8b_sema_hazir()`'e
  BİLEREK EKLENMEDİ — eklenseydi bu opsiyonel tablo yokken TÜM Faz 8B kilitlenirdi.
  Tablo yoksa hesap "ücret tanımsız" gibi davranır.

## Çavuş Ücreti — Yöntem B (25 kişi-gün = 1 hakediş)

Çavuşun kendi ücretini hesaplamanın **ikinci** yolu (varsayılan hâlâ Yöntem A —
yukarıdaki günlük sabit ücret). Yöntem **çavuş bazında** seçilir
(`cavus_fiyatlari.php`, "Hesaplama Yöntemi" kutusu), tarihe göre DEĞİL —
geçmişi zaman damgalı tutulur, geriye dönük **değildir**.

- **Dosyalar:** yöntem seçimi + 3 yeni tablo `config/pdks_faz8b.php`'nin
  "ÇAVUŞ ÜCRETİ — YÖNTEM SEÇİMİ" bloğunda; kapanış motoru/orkestratörler/
  listeler YENİ `config/pdks_faz8b_cavus_b.php`'de (`pdks_faz8b.php` +
  `pdks_cari.php`'yi require eder). `pdks_cari.php`/`pdks_rapor.php`/
  `pdks_hakedis.php` yeni tabloları yalnız ham SQL + "tablo yoksa atla"
  deseniyle okur — bu yeni dosyayı require ETMEZ. Sayfalar: `cavus_fiyatlari.php`
  (yöntem seçimi), `cavus_odeme.php` (kapanış tetiği + önizleme), YENİ
  `cavus_donem_raporu.php` (dönem raporu, yalnız `raporlar.php`'den link).
- **3 yeni tablo** (`pdks_faz8b_cavus_ucret_b_tablolar()`; kendi migrate/
  sema_hazir çifti, `pdks_faz8b_sema_hazir()`'e BİLEREK EKLENMEZ, önkoşulu
  Hakediş+Cari Hesap tabloları): `foreman_rate_method_log` (yöntem geçmişi,
  zaman damgalı), `foreman_period_closures` (dönem kapanışı = tahakkuk),
  `foreman_period_closure_items` (hangi KESİN hakediş hangi kapanışa dahil —
  kişi-gün DONMUŞ, ASLA yeniden hesaplanmaz).
- **Sayım kuralı:** `SUM(worker_count) WHERE worker_type_id IS NOT NULL`
  (Çavuş Ücreti satırını ve gelecekteki "worker olmayan" satırları güvenle
  dışlar). Bir final hakediş B havuzuna girer ⇔ `status='final'` VE
  `finalized_at` dolu VE **finalize ANINDA** çavuşun yöntemi B'ydi
  (`pdks_faz8b_cavus_ucret_yontem_anda()` — iş TARİHİNE değil, KESİNLEŞME
  ANINA bakar) VE hakedişte "Çavuş Ücreti" satırı YOK VE hiçbir GEÇERLİ
  kapanışın kalemi değil. A'dayken kesinleşen günler ASLA B'ye girmez (çift
  ödeme); B→A→B geçişinde ARADA (A iken) kesinleşen günler de asla sayılmaz.
- **Kapanış = ödeme, TEK transaction:** `pdks_faz8b_cavus_ucret_odeme_kaydet()`
  tx açar → `pdks_faz8b_cavus_ucret_b_kilit()` (tx'in İLK sorgusu,
  `SELECT ... FOR UPDATE`, yalnız MySQL) → `pdks_cari_odeme_ekle()` → yöntem
  B ise `pdks_faz8b_cavus_ucret_b_kapat()` → commit. Biri başarısızsa HİÇBİRİ
  yazılmaz. Eşzamanlılık AYRICA `chain_key` (`'F{çavuş}:P{önceki kapanış id
  ya da 0}'`) + `UNIQUE uq_fpc_chain` ile korunur — 23000 → "eşzamanlı işlem"
  Türkçe mesajı.
- **Ücret tanımsız:** ödeme YİNE DE kaydedilir, kapanış YAPILMAZ (kişi-gün/
  devir açıkta bekler, `audit closure_skipped`); sonraki ödemede (o tarihteki
  ücretle) kapanır. **Adet 0 ama kişi-gün var** (ör. 24): kapanış YAZILIR,
  tutar 0.00, devir 24. **Yeni kişi-gün YOK**: kapanış hiç YAZILMAZ.
- **İptal yalnız orkestratörden** (`pdks_faz8b_cavus_ucret_odeme_iptal()`) —
  `pdks_cari_odeme_iptal()` DOĞRUDAN çağrılırsa (bu ödemeye bağlı geçerli
  kapanış varsa) `kapanis_bagli` ile REDDEDİLİR. Yalnız EN SON GEÇERLİ
  kapanış geri alınabilir (`kapanis_en_son_degil` — daha yeni kapanış varsa
  önce o iptal edilmeli). İptal → kapanış `status='cancelled'` + `chain_key
  NULL` (satır SİLİNMEZ), kişi-günler sonraki kapanışta yeniden sayılır.
- **Cari:** `pdks_cari_bakiye()` / `pdks_rapor_bakiye_toplu()` /
  `pdks_rapor_cavus_bakiye_toplu()` ÜÇÜ de geçerli kapanışları `hakedis_kurus`'a
  (+ bilgi amaçlı `cavus_hakedis_kurus`) ekler. **Kullanıcı cevabı:**
  `pdks_rapor_finansal_kpi()` / `pdks_rapor_gunluk_trend()` /
  `pdks_rapor_cavus_ozeti()` dönem hakedişi DE B kapanışlarını içerir (tarih
  filtresi `closure_date`, **depo filtresi UYGULANMAZ** — bir kapanış birden
  çok gün/depoyu kapsayabilir). `pdks_hakedis_yeniden_ac()`'a YALNIZ EKLEME:
  geçerli bir B kapanışının kalemi olan hakediş `b_kapanisina_dahil` ile
  yeniden açılamaz.
- **Yeniden açma korumaları `function_exists()`'e GÜVENMEZ:** tek çağıran
  `cavus_hakedis_detay.php` `pdks_cari.php`'yi yüklemez; eski "geçerli ödemesi
  olan çavuş" kontrolü (`function_exists('pdks_cari_odeme_var_mi')`) o ekranda
  sessizce atlanıyordu. Artık aynı sorgu `pdks_hakedis_tablo_var($pdo,
  'foreman_payments')` ile doğrudan yapılır (tablo yoksa Faz 4 tek başına
  çalışır). Test: `php scripts/pdks_hakedis_yeniden_ac_guard_smoke.php`
  (her senaryo YALNIZ `pdks_hakedis.php` yüklü ayrı alt süreçte).
- **Ekstre:** `CAVUS_HAKEDIS` satırı `pdks_cari_ekstre()`'ye AYRI bir satır
  türü olarak eklenir, ödemeyle AYNI tarihte ama `siralama_oncelik=0` ile o
  ödeme satırından ÖNCE sıralanır. Açıklama biçimi sabit: "Çavuş Hakedişi —
  N kişi-gün (+D devir) → K hakediş × ücret, devir R".
- **Toplu döküm** (`cavus_toplu_dokum.php` + `_yazdir.php`): ay içindeki B
  kapanışları mevcut günlük tablonun ALTINDA ayrı bir bölümdür (kapanış
  ödeme tarihine göre o aya düşer, depo filtresiz).
- **Dönem raporu** yalnız `raporlar.php`'den link alır (`personel_takip.php`'ye
  kart EKLENMEZ — kullanıcı cevabı). Yetki: `require_pdks_rapor()` +
  `pdks_rapor_can('financial')` + export'ta `reports.export`.
- **Test:** `php scripts/pdks_cavus_b_smoke.php` (bellek içi SQLite, gerçek
  DDL çevirici + gerçek fonksiyonlar — canlı DB'ye dokunmaz). Yöntem B'ye
  dokunan HERHANGİ bir değişiklikten sonra çalıştır.

## Gün Sonu Kapanışı — "Mesaiyi Kapat" (v275)

"Mesaiyi Kapat" günlük işçi mesaisinin **Z raporudur**: sayımı kilitler (kart
okutma durur, yeniden açma yalnız admin) ve hakedişi kesinleştirilebilir kılar
(`pdks_hakedis_finalize()` KAPALI mesai ister). Kapatma yetkisi
`attendance.daily_scan` (güvenlik/operator) — parayla ilgili hiçbir şey görmez.

- **Önceki günden açık mesai YENİ GÜNÜ ENGELLER** — `pdks_gunluk_oturum_ac_veya_getir()`
  yalnız YENİ oturum açarken aynı çavuş + aynı depo için
  `pdks_gunluk_eski_acik_oturumlar()`'a bakar, varsa `kod=onceki_mesai_acik` +
  `eski_oturumlar` döner. Bugünkü açık mesaiye devam ENGELLENMEZ; başka depo etkilemez.
- **Tarama ekranı** (`gunluk_isci_giris_cikis.php`) böyle mesai varsa **pencereyle
  açılır** (`#giEskiSec`, veri `#giEskiVeri` JSON). Kapatma AYNI `?ajax=kapat` yolu
  ve aynı eksik-çıkış mutabakatıdır — **ikinci bir kapatma yolu AÇMA**. "Sonra"
  geçişe izin verir; sunucu kuralı yine geçerlidir.
- **Süre doldu uyarısı** — `pdks_gunluk_kapat_uyarisi()`: açılış +
  `normal_work_minutes_snapshot` (yoksa 540) + `PDKS_GUNLUK_KAPAT_UYARI_PAY_DK` (60).
  ENGEL DEĞİL; mod ekranında Kapat'ın üstünde (`#giKapatUyari`, `ajax=oturum`
  yanıtındaki `kapat_uyarisi`) ve çavuş listesinin üstünde
  (`pdks_gunluk_suresi_dolan_oturumlar()`) görünür. Hesap SUNUCU saatiyle.
- `personel_takip.php` Giriş/Çıkış kartında kapatılmamış mesai sayısı rozeti.
- **Eksik çıkışlı kart yeni girişi ENGELLEMEZ** (kullanıcı kararı): eksik çıkışla
  kapatılan mesaideki dönem `open` KALIR (raporda "Eksik Çıkış", çıkış saati
  UYDURULMAZ), ama kilit yalnız **açık mesaideki** dönemdir —
  `pdks_gunluk_faz8a_kart_acik_donemi()` `s.status='open'` ister (legacy
  `pdks_gunluk_kart_acik_girisi()` ile aynı kural). Giriş yapılır ve yanıtta
  `uyari` döner (`pdks_gunluk_faz8a_kart_eksik_cikisli_donemi()` +
  `pdks_gunluk_eksik_cikis_uyari_metni()`; sonuç kartında `.pdks-result-uyari`).
  Bir kartta böylece İKİ `open` dönem olabilir: **çıkış sorgusu yalnız açık
  mesaidekini seçer** — bu filtreyi kaldırma, yoksa eski dönem kapatılır/yanlış
  çavuş reddi gelir. Admin yeniden açması (`pdks_faz8h.php`) mesaideki çıkışsız
  kart başka açık mesaide içerideyse `kart_baska_mesaide` ile reddedilir.
- **İzin YALNIZ önceki günlere aittir** (v276, kullanıcı kararı — güvenlik): kart
  AYNI GÜN (`work_date` = yeni oturumun günü) eksik çıkışla kapatılmış bir mesaide
  kaldıysa o gün başka mesaiye giremez → `kod=bugun_eksik_cikis` (depo/çavuş fark
  etmez). Yoksa mesaiyi erken kapatıp kartı başka çavuşa geçirerek aynı gün iki
  katılım yazılabilirdi. **NORMAL çıkış yapmış kartın aynı gün yeniden kullanımı
  (Faz 8A "nötr kart") DEĞİŞMEDİ** — kullanıcı bunu açıkça korudu.
- Test: `php scripts/pdks_gun_sonu_kapanis_smoke.php`.

### Ortak Çıkış (v288, v289)

Çavuş listesinin üstündeki **"🚪 ORTAK ÇIKIŞ"** düğmesi: çavuş seçmeden tek
ekrandan çıkış. Giriş DEĞİŞMEDİ (çavuş + işçi tipi zorunlu).

- **İkinci yazma yolu DEĞİL.** `pdks_gunluk_ortak_cikis_kaydet()` kartın açık
  mesaisini `pdks_gunluk_faz8a_kart_acik_donemi()` ile BULUR, yazmayı
  değiştirilmemiş `pdks_gunluk_faz8a_cikis_kaydet()`'e devreder (o fonksiyon
  kartı kilitleyip yeniden doğrular — arada mesai kapanırsa reddeder). Ortak
  fonksiyona INSERT/UPDATE EKLEME; test bunu engelliyor.
- Uç `?ajax=ortak_cikis` istemciden **session_id ALMAZ**. Sayaç ucu
  `?ajax=ortak_mesailer` (salt okunur). İkisi de CSRF + `daily_scan`.
- **Tarih sınırı YOK** (kullanıcı kararı): dünden açık mesaideki kart da çıkar
  (gece vardiyası). **Depo sınırı VAR** (`pdks_gunluk_depo_kontrol`). Tanımsız
  kart otomatik KAYDEDİLMEZ.
- v289: tarama ekranındaki "çavuş çavuş açık mesailer" paneli KALDIRILDI.
  Çavuş listesinde "içeride N" rozeti kalır (`pdks_gunluk_ortak_cikis_mesailer()`,
  tek gruplu sorgu); kaydet yanıtındaki `mesailer` ile ve çavuş listesine
  dönüşte `?ajax=ortak_mesailer` ile tazelenir. Düğme ile liste arasında
  düz çizgi ayraç (yazısız) vardır. **Mesaiyi Kapat ortak modda YOK** —
  kapatma çavuş bazında kalır.
- v290: ÇIKIŞ sonuç kartında (normal + ortak) daire (top + hareketli
  çemberler) AYNEN korunur; içindeki rakam, okutulan kartın cinsiyetinden o
  mesaide hâlâ İÇERİDE kalan kişi sayısıdır (`data-sayi="kalan"`). Ayrı
  "KALAN" başlığı/kutu YOK (kullanıcı kararı). Kaynak `ozet.eksik_tip`; yoksa
  giris-cikis, negatife düşmez. Tip Kadın/Erkek değilse eski davranış. GİRİŞ
  sonucu (daire = o cinsiyetin toplam girişi) DEĞİŞMEDİ. Hedef tip BÜYÜK harfle
  karşılaştırılır — `'kadin'.toLocaleUpperCase('tr-TR')` = `'KADİN'` (noktalı İ).
- Test: `php scripts/pdks_ortak_cikis_smoke.php` ·
  `php scripts/pdks_ortak_cikis_render.php > _test_ortak_cikis.html` →
  `node scripts/pdks_ortak_cikis_smoke.js`.

### Kiosk düzeltmeleri (v293)

- **Mesaiyi Kapat = PENCERE** (`#giCloseConfirmSec.pdks-kapat-ovl`, z 600): bulunulan
  ekranın (mod ekranı / kapatılmamış mesai penceresi) ÜSTÜNDE açılır; mesai özeti +
  kontrol listesi, en altta **✅ Onayla**. `ekranGoster()` ile AÇILMAZ (`hidden=false`);
  ekran değişince yine kapanır. Odak düğmeye DEĞİL pencereye verilir — USB okuyucunun
  Enter'ı mesaiyi kapatmasın. Esc/dış tık/Vazgeç = `kapatmadanVazgec()`; istek sürerken
  (`kapatSuruyor`) vazgeç ve çift dokunma engelli. Kapatma yine yalnız `kapat()` → `?ajax=kapat`.
- **Kadın ⇄ Erkek hızlı geçiş** (`#giTipGecis`): GİRİŞ taramasında seçili tip KADIN ise
  "ERKEK GİRİŞ", ERKEK ise "KADIN GİRİŞ"; aynı `modSec('GIRIS')` yolu, yalnız tip değişir.
  Tip butonları `data-gi-tip-kod` taşır. ÇIKIŞ ve ortak modda gizli (`tipGecisGuncelle()`).
- **Sonuç süresi:** başarı 3 sn (`SONUC_OK_MS`), hata 5 sn (`SONUC_HATA_MS`), eksik-çıkış
  uyarılı başarı 8 sn.
- Test: `php scripts/pdks_ortak_cikis_render.php > _test_ortak_cikis.html` →
  `node scripts/pdks_kiosk_v293_smoke.js`.

### Geçmişe Dönük Çalışma Ekle (v291)

Unutulan girişi/çıkışı sonradan, raporda görünür biçimde eklemek. Mesai Detayı'nda
"➕ Çalışma Ekle", Günlük Puantaj listesinde "➕ Geçmişe Dönük Çalışma Ekle"
(bugünde dünün listesine `?ekle=1` bağlantısı).

- **YALNIZ admin** (`pdks_faz8j_yetki()`), aktif depo kapısı, zorunlu sebep, audit `puantaj_ekle`.
- **İkinci kart-okutma yolu DEĞİL:** `pdks_faz8j_gecmis_ekle()` (config/pdks_faz8j.php)
  `kaydet`/`cikis_kaydet` fonksiyonlarını ÇAĞIRMAZ; GİRİŞ+ÇIKIŞ olaylarını `source='manual'`,
  dönemi `status='closed'`, `source='manual'` yazar (raporda "✍ Elle eklendi"). Tek transaction.
- Çavuşun o gün mesaisi yoksa YALNIZ geçmiş gün için doğrudan KAPALI mesai oluşturur;
  bugün için oluşturmaz (kartı okutarak aç). Kesinleşmiş hakediş varsa reddeder (önce yeniden aç);
  taslak hakedişe `needs_recalculation=1`. Çıkarma = mevcut "Kaydı İptal Et".
- Kurallar: giriş günü = mesai günü, çıkış > giriş, ≤ 24 sa, gelecek yok, çakışan aktif dönem yok.
- Form partial'ı `_puantaj_ekle.php` (fonksiyon tanımlamaz). Migration YOK.
- Test: `php scripts/pdks_gecmis_ekle_smoke.php` · `node scripts/pdks_gecmis_ekle_smoke.js` ·
  `php scripts/pdks_puantaj_dialog_render.php > _test_puantaj_dialog.html` → `node scripts/pdks_puantaj_dialog_smoke.js`.

### Kartsız Mesai + Toplu İşlem (v294)

Tekli "Çalışma Ekle"ye **Kartsız mesai** seçeneği; Mesai Detayı ve Günlük Puantaj'a
**👥 Toplu İşlem** (Kadın/Erkek grupları: giriş/çıkış + boş kart seçimi + kartsız sayı).
YALNIZ admin, CSRF, audit. Migration YOK.

- **Kartsız = kayıt başına SANAL kart** (`pdks_faz8j_kartsiz_kart_olustur()`): `card_no`
  `KARTSIZ-` + 6 haneli **satır id'si** (sıra sayacı YOK — eşzamanlılıkta çakışırdı),
  okutulamaz `canonical_uid` (`KARTSIZ`+hex), `status='disabled'`, `enrolled_source='kartsiz'`
  (`pdks_faz8j_kartsiz_mi()`). Puantaj/hakediş/cari/rapor onu normal dönem gibi sayar —
  tüketicilere kartsız kodu EKLEME. Kart Havuzu'nda gizli, durum değiştirilemez,
  "fiziksel kart" metriğine girmez, `pdks_faz8j_duzelt()` kartını değiştirmez. Çıkış zorunlu.
- **Tek yazma yolu:** `pdks_faz8j_satir_kontrol()` (salt okunur) + `pdks_faz8j_satir_yaz()`
  (olay/dönem INSERT'ünün TEK yeri); oturum `pdks_faz8j_oturum_coz()`. Tekli ve toplu ikisi de
  bunları çağırır — kopya INSERT yazma.
- **Bugün kuralı (tekli + toplu):** açık mesai kullanılır; kapalıysa red; yoksa kiosk yolu
  `pdks_gunluk_oturum_ac_veya_getir()` ile AÇILIR (önceki gün açık mesai kuralı geçerli).
  Bugün kartlı satırda çıkış boş bırakılabilir → AÇIK dönem (kiosk kart kuralları). Geçmiş
  günde mesai yoksa KAPALI mesai yaratılır (pasif çavuş reddedilir).
- **Toplu:** `pdks_faz8j_toplu_onizle()` YAN ETKİSİZ (mesai/sanal kart yaratmaz);
  `pdks_faz8j_toplu_ekle()` HEP-YA-HİÇ, tek transaction, kartlar artan id sırasıyla kilitli,
  sınır `PDKS_FAZ8J_TOPLU_LIMIT` (250). Kaydet yalnız hatasız önizlemeden sonra açılır,
  girdi değişince kapanır. Önizlemedeki `uyarilar` (aynı saatlerde kartsız kayıt zaten var)
  ENGEL DEĞİL.
- **Çift gönderim koruması `istek_id`:** tekli form + toplu pencere tek kullanımlık anahtar
  gönderir; yazma transaction'ı önce mesai satırını kilitler (MySQL), sonra audit'te aynı
  `istek_id`'yi arar → varsa HİÇBİR ŞEY yazılmaz ("tekrar gönderim"). Kartlı satırı çakışma
  kontrolü korur ama KARTSIZI yalnız bu anahtar korur — kaldırma.
- **Toplu Geri Al:** `pdks_faz8j_toplu_listele()` / `pdks_faz8j_toplu_geri_al()` — batch
  (`toplu_id`, audit `puantaj_toplu_ekle`, modül `daily_work_sessions`, record_id = mesai id)
  içindeki aktif dönemleri tek seferde iptal eder (gerekçe zorunlu, kesin hakediş engeller).
  Sanal kartlar ve açılan mesai yerinde kalır.
- Dosyalar: `config/pdks_faz8j.php` · `_puantaj_ekle.php` · `_puantaj_toplu.php` ·
  `_puantaj_toplu_ajax.php` (`?ajax=toplu_onizle|toplu_ekle`, include-only, doğrudan 404).
- Test: `php scripts/pdks_toplu_ekle_smoke.php` · `php scripts/pdks_gecmis_ekle_smoke.php` ·
  `php scripts/pdks_puantaj_dialog_render.php > _test_puantaj_dialog.html` →
  `node scripts/pdks_puantaj_dialog_smoke.js` · `node scripts/pdks_gecmis_ekle_smoke.js` ·
  `node scripts/pdks_toplu_ekle_smoke.js`.

### Karışık Giriş + Otomatik Ata (v295)

Kiosk GİRİŞ tip ekranında **KADIN / ERKEK / KARIŞIK**. Karışık girenler sonra Mesai
Detayı'nda **🎲 Otomatik Ata** ile rastgele Kadın/Erkek'e atanır.

- **Tip satırı** `worker_types` KARISIK/"Karışık" (sahip GO verdi — YALNIZ veri satırı):
  `pdks_gunluk_migrate()` seed'i + `pdks_gunluk_karisik_tip_garanti()` (tembel, idempotent;
  pasifse yeniden AÇMAZ). Sabitler `PDKS_GUNLUK_KARISIK_KOD/_AD`.
- **Politika listesine EKLENMEZ:** `pdks_gunluk_desteklenen_tip_kodlari()` (fiyat, düzelt,
  tekli/toplu/kartsız ekleme) hâlâ yalnız KADIN/ERKEK. Kiosk GİRİŞ ayrı kapıdan geçer:
  `pdks_gunluk_giris_tip_kodlari()` / `_listele()` / `_coz()`. ÇIKIŞ tipi açık dönemden kopyalar.
- **Para kapısı (fail-closed):** `pdks_hakedis_karisik_engeli()` iki hakediş motorunda
  (8B + Faz 4) ve dolayısıyla finalize'da — mesaide atanmamış Karışık varsa hesap DURUR
  ("N Karışık kayıt atanmamış — önce Otomatik Ata"). `pdks_hakedis_karisik_oran_engeli()`
  iki `oran_ekle`'de Karışık fiyatını REDDEDER. Kaldırma: fiyat tanımlansa sessizce ödenirdi.
- **Atama** `pdks_faz8j_karisik_ata($sid,$kadin,$erkek,$reason,$istekId,$user)`: admin,
  aktif depo, `istek_id` + mesai kilidi, kesin hakediş red. Havuz = iptal edilmemiş Karışık
  dönemler (içeridekiler DAHİL); KISMİ serbest. `random_int` Fisher–Yates. YALNIZ dönemin
  `worker_type_id_snapshot`/`_name_snapshot` değişir — `pdks_faz8j_duzelt()` KULLANMA
  (Tam/Yarım + FM onayını sıfırlar); ham kart olayları DEĞİŞMEZ. Audit `karisik_ata`
  (modül `daily_work_sessions`, record_id = mesai id, `atama_id` KA…).
- **Geri Al** `pdks_faz8j_karisik_geri_al()`: yalnız hâlâ atandığı tipte olan dönemleri
  Karışık'a döndürür (elle değişeni/iptali atlar), bir kez; audit `karisik_geri_al`.
- Ekran: kiosk mor KARIŞIK düğmesi + rozet (Kadın⇄Erkek hızlı geçiş gizli), Mesai Detayı
  bildirim kartı + Otomatik Ata/Geri Al pencereleri + `.pdks-badge-karisik`, Günlük
  Puantaj'da "🎲 N Karışık" ipucu, toplu dökümde Karışık sayısı (Kadın+Erkek+Karışık=Toplam).
  Günlük Puantaj CSV/XLSX'e sütun EKLENMEDİ (CSV kuralı).
- Test: `php scripts/pdks_karisik_smoke.php` · `node scripts/pdks_karisik_smoke.js`.

### Liste Ergonomisi (v296)

- **Otomatik filtre:** Personel Takibi sayfalarındaki GET filtre formları `data-oto-filtre`
  taşır; seçim/tarih değişince kendiliğinden gönderilir (arama kutusu 400 ms gecikmeli /
  Enter; klavyeyle yazılan tarih 900 ms ya da odak kaybı). "Filtrele" düğmesi yalnız
  `<noscript>` içinde. POST formlarına uygulanmaz. Mekanizma TEK yerde:
  `config/pdks_liste_ui.php` (`pdks_gunluk.php` yükler) → sayfa `render_footer()`'dan önce
  `pdks_liste_ui_js()` çağırır. Yeni filtre formu eklerken `data-oto-filtre` ver, ayrı script YAZMA.
  "Özel" dönem seçimi tarihleri bekler (`data-oto-filtre-bekle`).
- **Tıklanabilir satır:** yalnız "Detay" içeren İşlem sütunu yerine `tr.pdks-satir-link[data-href]
  tabindex="0"` (tık / Enter; Ctrl/Cmd/orta tık yeni sekme; iç bağlantı/düğmeler etkilenmez) +
  birincil hücrede gerçek `<a>`. Başka eylemi olan satırlar (Düzenle, İptal…) düğmelerini korur.
- **Toplu döküm gün tonları:** `ctd-g0/ctd-g1` (tarihe göre dönüşümlü) + `ctd-gyeni` (grup başı
  kalın çizgi); yazdırmada `print_pdks.css`.
- **Çalışma Ekle penceresi** yeni görünüm (`_puantaj_ekle.php` + `pdks.css` v296-B bloğu): alan
  adları/id'ler/gizli alanlar DEĞİŞMEDİ.
- Test: `node scripts/pdks_oto_filtre_smoke.js` (önce `PUANTAJ_SAYFA=liste PUANTAJ_TARIH=bugun php
  scripts/pdks_puantaj_dialog_render.php > _test_puantaj_liste.html` ve `PUANTAJ_SAYFA=toplu …
  > _test_toplu_dokum.html`) · `php scripts/pdks_v296b_smoke.php` · `node scripts/pdks_v296b_smoke.js`.

### Görünüm + Değişken B Birimi (v297)

- **Yöntem B birimi çavuş bazında** (sahip GO verdi — TEK şema değişikliği):
  `foreman_rate_method_log.unit_size INT NULL` (NULL = 25, `PDKS_FAZ8B_CAVUS_B_BIRIM`).
  `cavus_fiyatlari.php` Yöntem kutusunda "Kaç kişi-gün = 1 hakediş" (1–1000); kaydetmek yeni
  zaman damgalı log satırı yazar (geriye dönük DEĞİL). Kapanış anındaki birim
  (`pdks_faz8b_cavus_ucret_birim_anda()`) `foreman_period_closures.unit_size`'a DONAR; eski
  kapanışlar değişmez, devir kişi-gün olarak kalır ve yeni birime bölünür. Kolon yoksa 25 dışı
  değer reddedilir (`birim_kolonu_yok`). Ekstre metnine birim yalnız ≠25 ise eklenir.
  Test: `php scripts/pdks_cavus_b_birim_smoke.php`.
- **Çavuş Ücretleri sayfası** yeni kart tasarımı (`.cf2-*`, pdks.css v297-A); form adları değişmedi.
  Test: `CAVUS_FIYAT_SENARYO=dolu|bos php scripts/pdks_cavus_fiyat_render.php > _test_cavus_fiyat[_bos].html`
  → `node scripts/pdks_cavus_fiyat_smoke.js`.
- **Toplu İşlem penceresi** yeni tasarım (`.toplu-v2`); kart listesi grup başına
  "Kartlı giriş ekle" ile açılır (kapatınca seçim temizlenir). Payload değişmedi.
- **Kiosk tip renkleri:** GİRİŞ taramasında `#giScanSec.pdks-tip-kadin|erkek|karisik` (pembe/mavi/mor
  çemberler, `tipRenkAyarla()` — tip KODU ile); başarı sonucu `pdks-sonuc-*` aynı renkte (bilinmeyen
  tip yeşil, hata kırmızı). Test: `node scripts/pdks_kiosk_renk_smoke.js`.
- **Gerçek düğme görünümü:** `.btn-ghost` artık dolgulu+çerçeveli; geri bağlantıları `.btn-geri`
  (mavi, sayfa başlıklarında). Zeminle aynı renkte düz yazı düğme YAZMA.
  Test: `node scripts/buton_gorunum_smoke.js`.

### Tanımlı Giriş (v298)

Kart Havuzu'nda (`isci_kartlari.php`, "🏷 Tanım") karta ÇAVUŞ + TİP (yalnız KADIN/ERKEK,
`pdks_gunluk_desteklenen_tip_coz`) + DEPO (= aktif depo, istemciden alınmaz) tanımlanır; kioskta
ORTAK ÇIKIŞ'ın üstündeki **"🏷 TANIMLI GİRİŞ"** ile okutulan kart tanımlı çavuşun BUGÜNKÜ mesaisine
o tiple girer. Çavuş → tip → kart akışı AYNEN kalır. Çıkış = mevcut ORTAK ÇIKIŞ (değişiklik yok).

- **Tablo `worker_card_assignments`** (sahip GO verdi — YALNIZ yeni tablo, ALTER YOK). Kart başına
  TEK aktif tanım: `aktif_kart_id` (aktifken = kart id, bitince NULL) üzerinde UNIQUE (chain_key
  deseni); geçmiş SİLİNMEZ (`valid_to`/`ended_by_user_id`/`end_reason`). Kendi üçlüsü
  `pdks_gunluk_kart_tanim_tablolar/_migrate/_sema_hazir` — `pdks_gunluk_tablolar()`'a,
  `pdks_gunluk_sema_hazir()`'e, `pdks_gunluk_faz8a_sema_hazir()`'e BİLEREK EKLENMEZ (Çavuş Ücreti
  emsali). Tablo yoksa özellik GİZLİ, tanım okuması null ("tanım yok"). Kurulum yalnız `migrate.php`
  kartı; ekranlarda migrate ÇAĞRILMAZ.
- **İkinci yazma yolu YOK:** `pdks_gunluk_tanimli_giris_kaydet()` (config/pdks_gunluk.php) tanımı +
  mesaiyi BULUR — gövdesinde INSERT/UPDATE/DELETE yok (test denetler). Tanımsız kart otomatik
  KAYDEDİLMEZ (`kart_tanimsiz`). Salt okunur ön kontroller (kart durumu, açık dönem, aynı gün eksik
  çıkış, tip) başarısız okutmanın boş mesai açmasını engeller; sonra kiosk yolu
  `pdks_gunluk_oturum_ac_veya_getir()` (önceki gün açık mesai / kapalı mesai / pasif çavuş kuralları
  aynen) ve `pdks_gunluk_faz8a_giris_kaydet(..., 'tanimli')`. Dönem `source='tanimli'` (yalnız köken;
  olay tablosu DEĞİŞMEZ) — hakediş/cari/rapor buna göre DALLANMAZ, dallanma EKLEME. Mesai Detayı'nda
  "🏷 Tanımlı" rozeti; CSV/XLSX'e sütun YOK.
- **Engel kuralı (normal ekran):** `pdks_gunluk_faz8a_giris_kaydet()` kart KİLİDİNDEN SONRA tanımı okur;
  mesainin çavuşu / seçilen tip / mesainin deposu tanımla uyuşmazsa `kart_baska_tanimli` ("Bu kart
  Çavuş A / Kadın'a tanımlı…"). Kural TEK yerde: `pdks_gunluk_kart_tanim_engeli()`. Admin'in elle/toplu
  eklemesi (Faz 8J) v303'ten beri BAŞKA çavuşa/depoya tanımlı kartı REDDEDER (`pdks_faz8j_tanim_kart_engeli()`);
  aynı çavuş farklı tip yalnız uyarı (`pdks_faz8j_tanim_uyarilari()`). Bkz. "Kart Havuzu (v303)".
- **Depo:** tanımlı ekranda kart başka depoya tanımlıysa `tanim_baska_depo`. Karşılaştırma TR-duyarsız
  (`pdks_gunluk_depo_fold`).
- **Tanım yazma** `pdks_gunluk_kart_tanim_kaydet()` / `_bitir()`: transaction + `pdks_gunluk_faz8a_kart_kilitle()`
  (girişle AYNI kilit), kartsız sanal kart / pasif çavuş / Karışık / boş depo reddi, UNIQUE 23000 →
  "eşzamanlı işlem". Kart içerideyse ENGEL DEĞİL, `uyari` (açık dönem eski çavuşta kalır). Audit
  `kart_tanim` / `kart_tanim_bitir` (modül `worker_cards`). Yeni yetki YOK: yazma = Kart Havuzu kapısı,
  okutma = `attendance.daily_scan`. Kayıp/devre dışı kart ve pasifleşen çavuşun tanımı kendiliğinden
  BİTMEZ — kiosk mevcut kodlarla reddeder, listede uyarı rozeti.
- **Kiosk** `?ajax=tanimli_giris` (CSRF + `daily_scan`; istemciden YALNIZ `ham_uid` + `kaynak`).
  `tanimliMod`: aynı UID 3 sn içinde istemcide yok sayılır, `mukerrer_giris` → "Zaten giriş yapıldı"
  bilgisi, `onceki_mesai_acik` → düğmeyle mevcut `eskiPencereAc()` (otomatik açma yok; kapatma yine
  `?ajax=kapat`), kapatınca/"Sonra" Tanımlı moda dönülür. Hızlı geçiş ve Mesaiyi Kapat bu modda yok.
- Test: `php scripts/pdks_tanimli_giris_smoke.php` · `php scripts/pdks_tanimli_giris_render.php >
  _test_tanimli_giris.html` → `node scripts/pdks_tanimli_giris_smoke.js` · `php
  scripts/pdks_kart_tanim_render.php > _test_kart_tanim.html` → `node scripts/pdks_kart_tanim_modal_smoke.js`.

### Seri Kart Tanımla (v299)
Kart Havuzu "⚡ Seri Kart Tanımla": çavuş + tip (`pdks_gunluk_desteklenen_tip_listele`) seçilir, kartlar art arda okutulur (USB / sürekli NFC), liste kontrol edilip TEK Kaydet ile hepsi tanımlanır. Yeni kart otomatik havuza (nötr, kart no sıradan). Tablo/Faz 8A/aktif depo yoksa düğme GİZLİ. Yeni yetki YOK (kapı `worker_cards`), migration YOK.
- **HEP-YA-HİÇ:** `pdks_gunluk_kart_tanim_toplu_kaydet()` tek tx; HATA satırı varsa hiçbir şey yazılmaz. Kilit altında `..._toplu_degerlendir()` yeniden çağrılır. Yazma yalnız MEVCUT `pdks_gunluk_kart_olustur()` + `pdks_gunluk_kart_tanim_kaydet()` — gövdede worker_cards/worker_card_assignments'a doğrudan INSERT/UPDATE YOK (test denetler); ikinci yazma yolu AÇMA.
- **Sınıflar** (`..._satir_sinifla`, saf): yeni · tanimlanacak · ayni (atlanır) · baska_cavus (ENGEL — "önce Tanımı Kaldır") · hata (geçersiz UID, aktif kalıcı kart, kayıp/devre dışı, kartsız sanal kart, yinelenen). Aynı çavuş farklı tip/depo → tanım güncellenir. Parti sınırı 100 (sunucu da denetler).
- **Uçlar:** GET `ajax=tanim_satir` (salt okunur, sınıf SUNUCUDAN döner, JS sınıflama kopyası YOK) · POST JSON `ajax=tanim_toplu_kaydet` (CSRF + `worker_cards`; depo aktif depodan, istemciden ALINMAZ).
- **Tekrar gönderim:** `istek_id` özet audit `kart_tanim_toplu`'da aranır (JSON_THROW doğrudan INSERT; `audit_log_event` hata yutar). MySQL'de `GET_LOCK('pdks_kart_tanim_toplu')` tx'in ilk işi — kaldırma. Kart içerideyse engel değil, `uyarilar`.
- **UI:** `#iskSeriModal` = başlık / kayan gövde / sabit alt çubuk, `<form>` SARILMAZ (`.pm-dialog > form` kuralı kalsın). pdks.css "v299" bloğu.
- Test: `php scripts/pdks_kart_toplu_tanim_smoke.php` · `php scripts/pdks_kart_toplu_render.php > _test_kart_toplu.html` → `node scripts/pdks_kart_toplu_smoke.js`.
- **v301 — tekli "Yeni Kart Tanımla" KALDIRILDI** (sahip kararı): kart ekleme/tanımlamanın TEK yolu Seri Kart Tanımla. Sayfadan `kart_ekle` POST dalı, `ajax=onizle` ucu ve `$onerilenKartNo` gitti; `pdks_gunluk_kart_olustur()` / `pdks_gunluk_sonraki_kart_no()` Seri Kart'ın toplu kaydı için KALIR, `assets/pdks.js` `data-pdks-scan` yardımcısı `personel_kartlar`/`personel_form` için KALIR — silme. `$seriHazir` false (tablo/Faz 8A/aktif depo yok) iken sayfa `#iskSeriYok` ile nedenini + migrate.php yönlendirmesini söyler; o durumda kart eklenemez. Düzenle/Durum/Tanım modalları ve Kart Sorgula DEĞİŞMEDİ. Testler bölümün YOKLUĞUNU denetler (kaynakta "Yeni Kart Tanımla" / `kart_ekle` geçmemeli — yorumda bile).

### Seri Tanım Kaldır (v364)
Kart Havuzu "🧹 Seri Tanım Kaldır" (Seri Kart Tanımla'nın yanında, aynı `$seriHazir` koşuluyla gizli): kartlar art arda okutulur
(USB / sürekli NFC), liste (kart no · çavuş · tip · depo) kontrol edilir, opsiyonel not, TEK "Tanımları Kaldır" (onay penceresi) ile
hepsinin AKTİF DEPODAKİ tanımı biter. Kart havuzda nötr kalır, hiçbir şey SİLİNMEZ (geçmiş satırı `valid_to` + `aktif_kart_id=NULL`).
Yeni yetki YOK (kapı `worker_cards`), migration YOK.
- **Sınıflar** (`pdks_gunluk_kart_tanim_kaldir_satir_sinifla()`, saf): kaldirilacak · tanimsiz (atlanır) · baska_depo (ENGEL) · hata
  (geçersiz UID, havuzda yok, kartsız sanal kart, yinelenen). Kayıp/devre dışı kartın tanımı da kaldırılır. İçerideki kart = `uyari`
  (engel değil). Sınıf SUNUCUDAN: GET `ajax=tanim_kaldir_satir` (`pdks_gunluk_kart_tanim_kaldir_satir()`, salt okunur) — JS kopyası YOK.
- **Yazma** POST JSON `ajax=tanim_kaldir_toplu` → `pdks_gunluk_kart_tanim_kaldir_toplu($satirlar, $reason, $istekId, $userId, $pdo)`:
  depo = `active_depot()` (istemciden ALINMAZ), HEP-YA-HİÇ tek tx, MySQL'de Seri Kart ile AYNI `GET_LOCK('pdks_kart_tanim_toplu')` tx'in ilk
  işi, kartlar artan id ile kilitli, kilit altında yeniden değerlendirme; engel varsa hiçbir şey yazılmaz. Her kart MEVCUT
  `pdks_gunluk_kart_tanim_bitir()` ile kapanır (dış tx'e katılır, erken commit yok; kart başına audit `kart_tanim_bitir`) — gövdede
  worker_card_assignments'a doğrudan UPDATE YOK (test denetler). Varsayılan gerekçe "Seri tanım kaldırma". `istek_id` özet audit
  `kart_tanim_kaldir_toplu`'da aranır (`..._toplu_istek_kayitli/_audit`'e `$action` parametresi eklendi). Sınır 100.
- **UI:** `#iskSeriKaldirModal` (`.isk-seri .isk-kaldir`; başlık / kayan gövde / sabit alt çubuk, `<form>` SARILMAZ), sayaçlar
  Kaldırılacak / Atlanacak / Engel; pdks.css "v364" bloğu.
- Test: `php scripts/pdks_kart_tanim_kaldir_smoke.php` · `php scripts/pdks_kart_toplu_render.php > _test_kart_toplu.html` →
  `node scripts/pdks_kart_toplu_smoke.js` (L bölümü).

### Kart Havuzu — Tanımlı/Tanımsız Ayrımı + Seçerek Silme (v303)
Sahip kararları: normal kiosk girişi DEĞİŞMEDİ (tanımlı kart kendi çavuşu+tipiyle normal ekrandan girebilir);
silme YALNIZ seçerek; geçmişsiz kart DELETE, geçmişli kart ARŞİV; elle eklemede tanımlı kart yalnız kendi çavuşunda.
Migration YOK, yeni yetki YOK.
- **Ekran** (`isci_kartlari.php`, pdks.css "v303"): Toplam · 🏷 Tanımlı · Tanımsız sayaçları (+ Arşiv, Kayıp) —
  `pdks_gunluk_kart_havuzu_ozet()` (TEK sayım kaynağı; kartsız HARİÇ; toplam = disabled olmayanlar). "Tanımlı Kartlar"
  özeti çavuş → tip → adet; çavuş adı `?tanim=tanimli&cavus=ID`, tip çipi `&ttip=TIPID`. Filtre: arama · tanim · cavus ·
  ttip (TANIM tipi) · durum. Eski legacy `tip` filtresi ve "Tip (eski)" sütunu KALDIRILDI. Varsayılan liste arşivi
  (`disabled`) GİZLER. Durum rozeti: tanımlı+available → "🏷 Tanımlı" ("Boşta" YALNIZ tanımsız kart).
- **Seçerek silme** (yalnız `is_admin()`, yalnız tanımsız satırda kutu): POST `action=tanimsiz_sil` →
  `pdks_gunluk_kart_tanimsiz_sil()`: HEP-YA-HİÇ tek tx, kartlar `pdks_gunluk_faz8a_kart_kilitle()` ile kilitli, istek_id
  (audit `kart_toplu_sil`), sınır `PDKS_GUNLUK_KART_SIL_LIMIT`. Ret: tanımlı ("önce Tanımı Kaldır") · içeride · kartsız ·
  yok. Geçmiş = `pdks_gunluk_kart_gecmis_tablolari()` (worker_cards'a FK veren TÜM tablolar — yeni FK eklersen BURAYA
  ekle; unutulursa MySQL 1451 → tüm işlem geri alınır). Geçmişli → `status='disabled'` + notes'a "[Arşiv tarih] gerekçe";
  geçmişsiz → DELETE. Sorgu hatası → arşiv (fail-closed). audit_log geçmiş SAYILMAZ. Audit `kart_sil`/`kart_arsiv`
  tx içinde doğrudan INSERT. Arşivlenen fiziksel kart Seri Kart'ta "devre dışı" hatası verir — Düzenle'den Boşta'ya alınır.
- **Elle ekleme kısıtı:** `pdks_faz8j_bos_kartlar($gun, $pdo, $foremanId, $depo)` satırlara `tanim_foreman_id`/`tanim_depo`
  ekler; Mesai Detayı çavuş+depo ile süzer (`pdks_faz8j_kart_tanim_suz()`; Düzenle listesinde dönemin mevcut kartı her
  zaman kalır), Günlük Puantaj listesi depo ile süzer ve `_puantaj_ekle.php`/`_puantaj_toplu.php` JS'i seçilen çavuşa göre
  `data-tanim-foreman` ile gizler. Sunucu: `pdks_faz8j_satir_kontrol(..., $hedef)` ve `pdks_faz8j_duzelt_satir()` (yalnız
  kart DEĞİŞİYORSA) → `pdks_faz8j_tanim_kart_engeli()` TEK kural. Toplu düzelt / saat-tip düzeltme engellenmez.
- Test: `php scripts/pdks_kart_havuzu_smoke.php` · `node scripts/pdks_kart_havuzu_smoke.js` (render'ı kendisi çağırır;
  `KH_QS`, `KH_NONADMIN=1`) · `php scripts/pdks_tanimli_giris_smoke.php` · `PUANTAJ_TANIM=1 php scripts/pdks_puantaj_dialog_render.php`.

### Saat Bazlı Yevmiye + Çift Yevmiye (v299)

- **Şema** (sahip GO verdi — YALNIZ ADD COLUMN): `foreman_worker_rates` + `full_day_minutes` ·
  `half_day_max_minutes` (yalnız bilgi) · `overtime_start_minutes` · `double_day_minutes` · `double_day_rate`,
  hepsi NULL. Kendi çifti `pdks_faz8b_saat_kolonlari_migrate/_hazir` — `pdks_faz8b_sema_hazir()`'e BİLEREK
  EKLENMEZ. Kurulum yalnız migrate.php kartı; `cavus_fiyatlari.php` migrate ÇAĞIRMAZ (kolon yoksa not gösterir).
- **NULL = bugünkü davranış birebir:** Tam eşiği = mesainin `normal_work_minutes_snapshot`'ı, FM Tam'dan 15 dk
  tolerans sonra, çift yok. `foremen.normal_work_minutes` kalır (formda Tam saatinin varsayılanı + kiosk uyarısı).
- **TEK sınıflandırıcı `pdks_faz8b_donem_siniflandir($donem, $oran)`** — Mesai Değerlendirme, tekli/toplu
  değerlendirme (`pdks_faz8b_donem_getir`), hakediş, Mesai Tanımı sütunu hepsi bunu kullanır. Süre/FM/çift
  hesabını başka yerde YAZMA; `pdks_faz8b_sure_karari` yalnız oradan çağrılır (test denetler).
- **Çift** (sahip kararı: çift Tam'ın YERİNE + sonrası FM; onay FM onayına bağlı): toplam ≥ çift eşiği VE onaylı
  süre (FM başı + onaylı FM × 60) ≥ eşik → sınıf `cift`, temel = çift ücret, FM = eşikten SONRAKİ onaylı saat;
  Tam–çift arası ayrıca ödenmez. Sabit FM'de çift gününe FM EKLENMEZ. FM red → Tam, bekliyor → hazır değil.
  Eşik değişince onaylı FM adaya KIRPILIR. Ayrı onay/şema YOK.
- Yarım saati sınıfı DEĞİŞTİRMEZ (sahip kararı: otomatik Yarım yok, karar muhasebede), yalnız ipucu. Satır
  `attendance_class_snapshot='cift'`, `worker_count=1` (Yöntem B'de 1 kişi-gün). Faz 4 motoru çift bilmez.
- **Mesai Detayı "Mesai Tanımı"** (DURUM'un yanında; MESAİ beyan sütunu KALIR): `pdks_faz8b_mesai_tanimi_etiketi()`
  — Tam/Yarım/Çift + FM; faz8b şeması yoksa gizli. DURUM "✅ Çıkış yapıldı" (CSS kodu `tam` aynı).
- Test: `php scripts/pdks_cift_yovmiye_smoke.php` · `PUANTAJ_FAZ8B=1 php scripts/pdks_puantaj_dialog_render.php
  > _test_puantaj_dialog.html` → `node scripts/pdks_puantaj_dialog_smoke.js` · `CAVUS_FIYAT_SENARYO=dolu|bos php
  scripts/pdks_cavus_fiyat_render.php > _test_cavus_fiyat[_bos].html` → `node scripts/pdks_cavus_fiyat_smoke.js`.

### Servis Ücreti (v299)
Mesai Detayı "🚌 Servis Ücreti" (Çalışma Ekle/Toplu İşlem yanında) → BÜYÜK/KÜÇÜK adet; fiyat çavuş bazında
`cavus_fiyatlari.php` "🚌 Servis Ücreti" kartında (tarihli, geçmişli; önceki dönem bir gün önce kapanır).
- **Tablolar** `foreman_service_rates` + `daily_session_services` (sahip GO — yalnız 2 yeni tablo, ALTER YOK); kendi
  `pdks_servis_tablolar/_migrate/_sema_hazir` (config/pdks_servis.php), genel sema_hazir'lere EKLENMEZ, kurulum yalnız
  migrate.php kartı. Tablo yoksa özellik GİZLİ, hesap servisi yok sayar. pdks_faz8b.php dosya sonunda yükler.
- **Hakediş** (sahip kararı: çavuş hakedişine ek satır): `pdks_faz8b_hakedis_hesapla` Çavuş Ücreti bloğunun ALTINDA tür
  başına 1 satır (worker_type_id/work_period_id NULL, kod `SERVIS_BUYUK|SERVIS_KUCUK`, worker_count=adet). Fiyat yoksa
  eksik → hesap DURUR; para birimi karışık para kapısına katılır; Yöntem A/B fark etmez. Tüketicilere satır kodu EKLEME
  (total_amount okurlar). Faz 4 motoru servisi bilmez.
- **Dedektör tuzağı:** Çavuş Ücreti satırı = NULL/NULL **ve kod ''**. `baska_final_var_mi` ve B `aday_kalemler.cavus_satiri`
  `worker_type_code_snapshot = ''` ister — kaldırma (servis A'da günlük ücreti engeller, B'de günü havuzdan düşürür).
- **Yazma** `pdks_servis_ekle/_iptal`: yalnız admin, aktif depo = mesai deposu, mesai kilidi tx'in İLK sorgusu, kesin
  hakediş red, taslağa needs_recalculation, istek_id (UNIQUE + ön kontrol), gelecek gün yok.
  Düzenleme YOK; iptal soft + gerekçe. Audit `servis_ekle/servis_iptal` (modül daily_work_sessions, record_id=mesai).
  Yalnız servisi olan (işçi dönemi olmayan) mesai hakediş hesaplanamaz (`kart_yok`, mevcut kural).
- Partial `_puantaj_servis.php` (fonksiyon tanımlamaz, native dialog#servis). Gün Sonu Fişi yalnız ADET basar.
- **Fiyat yokken de giriş YAPILIR (v300, sahip kararı: servis kalkıyor, fiyat sonradan girilir):** `pdks_servis_ekle()`
  fiyat kapısı YOK, yanıtta `fiyatsiz` (tür adları) döner; pencerede sayaçlar açık + mavi `.sv-bilgi-kutu` (kırmızı engel
  YOK). Hakediş KORUNUR: fiyat yoksa eksik → bu mesainin hakedişi hesaplanamaz (işçi fiyatı olmayan mesaiyle AYNI
  fail-closed kural) — servis sessizce ödenmemiş kalmaz. Fiyat tanımlanınca hesap kendiliğinden geçer.
  `pdks_servis_ucret_ekle()` o çavuşun, bu tarihten itibaren servisi olan TASLAK hakedişlerini
  (`pdks_servis_taslaklari_isaretle`) yeniden hesaplamaya işaretler (kesin hakedişe dokunmaz; yanıtta `isaretlenen`).
  Fiyat formunun "Geçerlilik Başlangıcı" varsayılanı = fiyatsız en eski servis günü (`pdks_servis_fiyatsiz_en_eski_tarih`);
  fiyat servis gününü KAPSAMALI (`valid_from <= work_date`), yoksa o gün hâlâ fiyatsız sayılır.
  Test: `php scripts/pdks_servis_smoke.php` (bölüm L) · `PUANTAJ_SERVIS=nofiyat php scripts/pdks_puantaj_dialog_render.php >
  _test_servis_fiyatsiz.html` → `node scripts/pdks_servis_fiyatsiz_smoke.js`.
- Test: `php scripts/pdks_servis_smoke.php` · `PUANTAJ_SERVIS=1 php scripts/pdks_puantaj_dialog_render.php >
  _test_servis_dialog.html` → `node scripts/pdks_servis_dialog_smoke.js` · `pdks_cavus_fiyat_smoke.js`.

### Rampacı İşçi Tipi (v299)
Kadın/Erkek yanında 3. SABİT sistem tipi: `worker_types` RAMPACI/"Rampacı" (sahip GO verdi — yalnız veri satırı, kolon yok).
- Politika `pdks_gunluk_desteklenen_tip_kodlari()` = KADIN, ERKEK, RAMPACI → fiyat, düzeltme, kartsız/toplu ekleme, tanımlı giriş, seri tanım, hakediş, Yöntem B havuzu (sahip kararı: sayılır) tip id ile OTOMATİK kapsar. Karışık politikada DEĞİL; Otomatik Ata yalnız Kadın/Erkek'e atar. Fiyatı olmayan çavuşta Rampacı'lı mesai hakedişi "geçerli fiyat yok" ile durur (fail-closed).
- Satır: migrate seed (sort_order 4, mevcut satırlara dokunulmaz) + tembel idempotent `pdks_gunluk_rampaci_tip_garanti()` (pasifse yeniden AÇMAZ; `desteklenen_tip_listele()` ve isci_tipleri çağırır). Görüntü sırası kodda: Kadın, Erkek, Rampacı, Karışık.
- TEK KAYIT: `pdks_gunluk_tip_kayit()` (kod → ad, kısa, sayaç etiketi, renk son eki kadin/erkek/rampaci/karisik, `sutun`, `sistem`). Ekran+yazdırma rapor sütunları/kutuları (`pdks_gunluk_tip_sistem_sutunlari()`), `pdks_rapor_cavus_toplu_dokum()` SQL sütunları ve kiosk renkleri (`TIP_KAYIT` JSON) bundan DÖNGÜYLE üretilir — elle 'Kadın'/'Erkek' sütunu YAZMA. Yeni tip = kayıt + CSS (`pdks-kiosk-typebtn-/pdks-tip-/pdks-sonuc-/pdks-kiosk-type-badge-/is-<renk>`, `tv-grup-<renk>`).
- Rampacı sütunu 0 olsa da görünür; Karışık yalnız atanmamış kayıt varsa. CSV/XLSX'e sütun EKLENMEZ (sahip kararı).
- Kiosk: turuncu RAMPACI düğmesi/çember/sonuç/rozet; Kadın⇄Erkek hızlı geçiş Rampacı'da gizli; ÇIKIŞ "kalan" dairesi Karışık hariç tüm sistem tipleri için.
- Test: `php scripts/pdks_rampaci_smoke.php` · `node scripts/pdks_kiosk_renk_smoke.js` (render: `php scripts/pdks_ortak_cikis_render.php > _test_ortak_cikis.html`).

### Görünüm (v301)

- **"← Personel Takibi" geri düğmesi TURUNCU** (tüm sayfalar, sahip kararı): `personel_takip.php`'ye giden bağlantı `class="btn btn-geri btn-geri-ptak"` taşır (`style.css`, açık + koyu tema; beyaz yazı AA için #c2410c). Diğer geri düğmeleri (`.btn-geri`) MAVİ kalır — turuncu yalnız bu hedefe. Yeni sayfa eklerken bu bağlantıya `btn-geri-ptak` ver (statik test denetler).
- **Filtre çubuğu tarih kutusu** (`.pdks-filter-bar input[type=date]`, pdks.css): ≥768px'te sabit genişlik (max 200px) — global `input[type=date]{width:100%}` Günlük Puantaj'da tarihi tüm satıra yayıyordu; mobilde tam genişlik kalır.
- Test: `php scripts/pdks_geri_ptak_smoke.php` · `PUANTAJ_SAYFA=liste PUANTAJ_TARIH=bugun php scripts/pdks_puantaj_dialog_render.php > _test_puantaj_liste.html` → `node scripts/pdks_geri_ptak_smoke.js`.

### Kart Hareketleri — Seçerek Toplu Düzenle / İptal (v302)
Mesai Detayı "Kart Hareketleri"nde satırlar seçilir (masaüstü: kutu **Kart No hücresinin İÇİNDE** — ayrı sütun
DEĞİL, sıralama ve testleri `cells[0]` = Kart No'ya bağlı; mobil: kart başında) → "N seçili" çubuğu →
**✏ Seçilenleri Düzenle** (Hepsine uygula satırı + satır satır Tip / Giriş / Çıkış listesi) ya da
**🗑 Seçilenleri İptal Et**. Kapı tekil Düzenle ile AYNI: `is_admin() && $faz8jHazir && mesai deposu = aktif depo`
(`$tdAjaxKapi`). Migration YOK, yeni yetki YOK.
- **İkinci yazma yolu YOK:** tekil `pdks_faz8j_duzelt()` satır mantığı `pdks_faz8j_duzelt_satir()` çekirdeğine
  çıkarıldı (dönem UPDATE'inin TEK yeri); `pdks_faz8j_toplu_duzelt()` onu, `pdks_faz8j_toplu_iptal()`
  `pdks_faz8j_void_uygula()`'yı çağırır — toplu gövdelerde `UPDATE daily_worker_work_periods` YAZMA (test denetler).
- HEP-YA-HİÇ tek tx, mesai kilidi İLK sorgu, `istek_id` (audit `puantaj_toplu_duzeltme`/`puantaj_toplu_iptal`,
  modül `daily_work_sessions`, record_id = mesai; satır audit'i `toplu_id` taşır), kesin hakediş red, sınır
  `PDKS_FAZ8J_TOPLU_LIMIT`. Tüm satırlar denetlenir, hatalar satır satır döner (`hatalar[{period_id,card_no,hata}]`),
  biri bile hatalıysa hiçbir şey yazılmaz. Kart DEĞİŞMEZ; giriş günü = mesai günü (istemciden tarih alınmaz);
  çıkış < giriş → `exit_date` = ertesi gün ("+1 gün"). **Değişmeyen satır ATLANIR** (dakika düzeyinde; saniye korunur)
  — Tam/Yarım/FM onayları sıfırlanmaz. Hiç değişiklik yoksa `ok:false`.
- Uçlar `?ajax=toplu_duzelt|toplu_iptal` (`_puantaj_toplu_duzelt_ajax.php`, include-only, mesai id'si sunucudan).
  Pencereler `_puantaj_toplu_duzelt.php` (native dialog, fonksiyon tanımlamaz, kullanıcı verisi textContent),
  pdks.css "v302" bloğu.
- Test: `php scripts/pdks_toplu_duzelt_smoke.php` · `node scripts/pdks_toplu_duzelt_smoke.js` (kendi sayfasını
  `PUANTAJ_TOPLU_DUZELT=1 php scripts/pdks_puantaj_dialog_render.php` ile basar).

### Mesai Özeti (v313 → v314 taşındı)
Çavuşun kendi mesai sayfası **Mesai Detayı** (`gunluk_isci_puantaj_detay.php`), Kart Hareketleri'nin üstünde **📊 Mesai Özeti** (v313'te yanlışlıkla Günlük Puantaj listesine konmuştu; v314'te oradan KALDIRILDI — geri ekleme). Migration YOK, yeni yetki YOK.
- **Mesai Tanımı tablosu** sütunları SABİT (sahip kararı): İşçi · Tam · Yarım · Çift · 1. Saat · 2. Saat · 3. Saat · 4. Saat · 5+ Saat · Bekliyor · İçeride · Toplam; satırlar sistem tipleri (Kadın/Erkek/Rampacı) + Toplam. "N. Saat" = o kadar saat fazla mesai yapan İŞÇİ SAYISI (`PDKS_FAZ8B_OZET_FM_SUTUN`=5, son sütun 5 ve üzeri). Ayrıca Servis (Büyük/Küçük adet) ve Çalışma Saatleri (toleranslı toplam çalışma + toplam FM).
- **v318 — sayaç kartı KALDIRILDI** (sahip kararı): Mesai Detayı'ndaki Kadın/Erkek/Rampacı/Toplam Giriş/Toplam Çıkış/Eksik Çıkış kutuları gitti (geri ekleme); yerine tabloya **Eksik Çıkış** sütunu geldi (sıra: … Bekliyor · İçeride · Eksik Çıkış · Toplam). Çıkışsız dönem: mesai AÇIK → "İçeride", mesai KAPALI → "Eksik Çıkış" (`_s_status`). Durum rozeti (Açık Mesai/Tamamlandı) sayfa başlığında.
- **v319 — Bir gün geri / ileri** (Mesai Detayı, başlığın altı `.pdks-gun-nav`): AYNI çavuş + AYNI depodaki önceki/sonraki mesai günü (başka çavuş/depo sayılmaz). Aradaki günde mesai yoksa en yakın mesaiye gider, etikette "(3 gün sonra)" yazar; yoksa düğme pasif. Salt okunur bağlantı — hedef sayfa kendi depo/yetki kapılarını uygular. Test: `php scripts/pdks_gun_nav_smoke.php` (render: `PUANTAJ_GUN_NAV=1`).
- **v320 — "11 sa → 1 FM" şikâyeti (5 ajanlı inceleme):** hesap DOĞRU; eşik 10 sa idi (fiyat döneminde saat değeri). Kök tuzak: `cavus_fiyatlari.php` "Tam Yevmiye Saati"ni çavuşun o anki süresiyle ÖN-DOLDURUYOR ve her fiyat kaydı bunu kalıcı yazıyordu → artık BOŞ gelir (boş = NULL = mesainin kendi süresi; placeholder "boş = çavuş süresi (9 sa)"). GERİ ALMA. Mesai Özeti eşiğin KAYNAĞINI yazar (`fm_kaynak`: fm_fiyat / tam_fiyat / mesai) + Çavuş Ücretleri bağlantısı; açıklama örneği eşikten üretilir; kapalı mesaide çıkışsız satır "Eksik çıkış". Saat girdisi: tek haneli ondalık = ondalık saat ("9,5"/"9.5" → 9:30), iki hane = dakika ("9.30" → 9:30). Testler: `pdks_fm_senaryo_smoke.php` (5 ayar × 9 dönem, sınıflandırıcı = özet = hakediş) · `pdks_fm_onay_zincir_smoke.php`.
- **v320 — Saatleri Düzelt (geçmiş fiyat dönemi):** Çavuş Ücretleri › Fiyat Geçmişi her satırda "✏ Saatleri Düzelt" (native `dialog#cfSaatDuzelt`, POST `form=saat_duzelt` + CSRF + `require_pdks_hakedis('rates')`, dönemin çavuşa ait olduğu denetlenir) → `pdks_faz8b_oran_saat_duzelt()`: YALNIZ 5 saat kolonu (ücretlere dokunmaz), gerekçe zorunlu, dönem aralığında KESİN hakediş varsa red, taslaklara `needs_recalculation=1`, audit `saat_duzelt` (modül foreman_worker_rates). Yeni dönem eklemek geçmişi düzeltmediği için (dönem yalnız ileri tarihli açılır) bu yol var. Test: `php scripts/pdks_saat_duzelt_smoke.php`.
- **FM kuralı:** (v315 saat başı toleransıyla etkin süre) çavuşun mesai süresi (ör. 9 sa; fiyat döneminde FM başlangıcı varsa o) aşıldıktan sonra 15 dk tolerans, başlayan her saat yukarı — 10:11 → Tam + 1 sa, 12:00 → 3 sa, 9:15 → 0. Reddedilen FM sayılmaz; Çift günde çift eşiğinden sonraki onaylı saat. Tam/Yarım/Çift sınıfı hakedişle AYNI kuraldır (Tam ≥ mesai süresi; alttan tolerans YOK).
- **Hesap TEK yerde:** `pdks_faz8b_gun_mesai_ozeti()` (config/pdks_faz8b.php) her dönemi `pdks_faz8b_donem_siniflandir()`'dan geçirir — sayfada kendi süre/FM hesabı YAZMA. Servis: `pdks_servis_toplamlar_toplu()`. Faz 8B şeması yoksa özet gizli. Çıkışsız (içeride) ve atanmamış Karışık süre/FM'ye KATILMAZ.
- CSV/XLSX'e eklenmedi. Test: `php scripts/pdks_mesai_ozeti_smoke.php` · `PUANTAJ_FAZ8B=1 PUANTAJ_SERVIS=1 php scripts/pdks_puantaj_dialog_render.php`.

### Saat Başı Toleransı (v315 → v317)
Sahip kararı (v317 kesin kural): `pdks_faz8b_sure_karari()` süreyi ham değil **etkin** saatlerle hesaplar
(`pdks_faz8b_saat_basi()` / `pdks_faz8b_etkin_saatler()`): giriş VE çıkış tam saate **±15 dk** (önce de sonra da) yakınsa
o tam saate çekilir — **07:57 → 08:00 · 08:14 → 08:00 · 16:46 → 17:00 · 17:13 → 17:00**; 15 dk'dan uzaksa (07:44, 08:16,
16:44, 17:16) dokunulmaz. 08:02–17:00 = 9 sa Tam; 07:57–17:16 = 9 sa 16 dk → FM 1; 08:16–16:44 = 8 sa 28 dk → karar bekler.
Saat KİLİDİ DEĞİL (08-17/09-18/12-21 aynı çalışır). Ham kart kayıtları DEĞİŞMEZ. (v315 yalnız erken giriş/geç çıkışı,
v316 ek bir kısaltma koruması yuvarlıyordu — v317'de ikisi de bu simetrik kurala indirildi, GERİ EKLEME.)
- TEK yer sınıflandırıcı olduğu için Tam/FM/Çift, hakediş, Mesai Değerlendirme, Mesai Tanımı, Mesai Özeti hepsi
  kendiliğinden etkilenir. `toplam_dk` = etkin süre; `ham_dk`, `giris_etkin`, `cikis_etkin` ayrıca döner. Kesin
  (final) hakedişler donmuştur, değişmez; taslaklar yeniden hesaplanınca yeni kuralı alır.
- Ekran süresi: `pdks_faz8b_sure_metni()` → "9s 00dk (ham 9s 16dk)"; Mesai Detayı Süre sütunu da etkin süre + `.pdks-sure-ham` notu.
- Test: `php scripts/pdks_saat_basi_tolerans_smoke.php`.

### Kart Hareketleri Sıralaması + Kapanış Notu (v299)

- **Sıralama:** Mesai Detayı "Kart Hareketleri" varsayılan sırası SUNUCUDA, yalnız `gunluk_isci_puantaj_detay.php`'de `usort`: son işlem (çıkış varsa çıkış, yoksa giriş) DESC, beraberlikte `period_id` DESC. Paylaşılan `pdks_gunluk_faz8a_oturum_donemleri()` SQL'i (ASC) Gün Sonu Fişi ile ortaktır, KRONOLOJİK kalır — ona sıralama ekleme.
- **Başlık sıralaması TEK yerde:** `config/pdks_liste_ui.php`. `<table data-pdks-sirala>` + `<th data-sirala="metin|sayi|zaman">` içinde GERÇEK `<button>` + hücrede ham `data-sirala-deger`; artan → azalan → varsayılan, boşlar her iki yönde sonda, metin `tr` doğal (K2 < K10), aktif `th`'de `aria-sort`. Mobil karşılığı `select[data-pdks-sirala-sec]` + `data-sd-*`. Yeni tabloda ayrı script YAZMA. Test: `PUANTAJ_FAZ8B=1 PUANTAJ_SIRALA=1 php scripts/pdks_puantaj_dialog_render.php > _test_sirala.html` + `PUANTAJ_SIRALA=1 PUANTAJ_KAPALI=1 … > _test_kapanis_notu.html` → `node scripts/pdks_sirala_smoke.js`.
- **Kapanış notu düzenleme:** `pdks_gunluk_oturum_not_guncelle()` (config/pdks_faz8h.php) — YALNIZ admin (sahip kararı), mesai aktif depoda, YALNIZ `status='closed'` (her tarih), gerekçe yok, boş → NULL, ≤1000 karakter, tek tx + `FOR UPDATE`, audit `daily_session_note_edit`. Hakediş/cari bu kolonu okumaz; hakediş tablolarına/`needs_recalculation`'a DOKUNMAZ (kesin hakedişte de çalışır) — gövdeye ekleme (test denetler). Ekran: kapalı mesaide "✏ Notu Düzenle" → native `<dialog id="kapanisNotu">`, POST `action=kapanis_notu` + CSRF, mesai id'si sunucudan. Test: `php scripts/pdks_kapanis_notu_smoke.php`.

### Ters Kart Takip (v354)
Bağımsız, paralel günlük işçi modeli: sabah yalnız çavuşun elinde KALAN kartlar okutulur, dağıtılanlar (UID bazında)
onayla birlikte SABİT giriş saatiyle açık dönem alır; akşam mevcut Ortak Çıkış. Normal kiosk/Tanımlı Giriş/Ortak Çıkış
AYNEN çalışır. Ayrıntı + sahip kararları: `docs/TERS_KART_TAKIP.md`.
- **Dosyalar:** `config/pdks_ters.php` (şema + tüm mantık) · `ters_kart.php` (hub) · `ters_kart_havuz.php` (A) ·
  `ters_kart_sayim.php` (B) · `ters_kart_cikis.php` (C) · `ters_kart_mutabakat.php` (D) · `assets/ters_kart.js` ·
  `pdks.css` "v354" `.tk-*` bloğu. Yeni yetki YOK (havuz `worker_cards`, sayım/çıkış `daily_scan`, mutabakat +`daily_reports`).
- **4 yeni tablo** (sahip GO — ALTER YOK): `ters_havuz_kart` (kart başına tek aktif üyelik, `aktif_kart_id` UNIQUE) ·
  `ters_cavus_ayar` (tarihli saat) · `ters_sayim` (`aktif_anahtar` UNIQUE = çavuş+gün+depo başına tek aktif sayım,
  `istek_id` UNIQUE) · `ters_sayim_kart` (onayda havuzun anlık görüntüsü). `pdks_ters_tablolar/_migrate/_sema_hazir`;
  genel sema_hazir'lere EKLENMEZ, kurulum yalnız migrate.php kartı. Tablo yoksa modül "kurulmamış" der, eski sistem aynen.
- **İkinci yazma yolu YOK:** giriş `pdks_faz8j_satir_kontrol` + `pdks_faz8j_satir_yaz` (meta `kaynak`='ters',
  `olay_zamani`=gerçek an, `audit_action`='ters_giris'; varsayılanlar eski davranış), mesai `pdks_faz8j_oturum_coz`,
  çıkış `pdks_gunluk_ortak_cikis_kaydet`, geri alma `pdks_faz8j_void_uygula`. pdks_ters.php dönem/olay/worker_cards
  tablolarına doğrudan yazmaz (test denetler).
- **Zamanlar:** dönem `entry_time` = sabit saat; GİRİŞ olayı `source='ters'`, `server_event_time` = gerçek kayıt anı;
  onay anı `ters_sayim.onay_zamani`. Sabit saat onaydan en çok 2 sa ileride (`PDKS_TERS_ILERI_DK`).
- **Onay HEP-YA-HİÇ**, kilit sırası: sayım FOR UPDATE → mesai FOR UPDATE → üyelik + kartlar (artan id) FOR UPDATE →
  `pdks_ters_sayim_degerlendir()` kilit altında yeniden. Bu kilitlerden ÖNCE kilitsiz okuma EKLEME (InnoDB anlık görüntüsü
  erken sabitlenir → kiosk girişini görmeyip çift açık dönem). Dönmemiş kart (son dönemi eksik çıkışlı) karar verilmeden
  onay ENGELLİ; bu çavuşun mesaisinde normal okutmayla içerideki kart `zaten_iceride` (ikinci dönem yok).
- **Kart sahipliği kapısı TEK yerde** `pdks_gunluk_ters_havuz_engeli()` (pdks_gunluk.php, tablo yoksa null): normal giriş
  (kart kilidi altında), tanım yazımı, Seri Kart sınıflaması, Kart Havuzu silme, `pdks_faz8j_tanim_kart_engeli`.
  `ters_havuz_kart` + `ters_sayim_kart` `pdks_gunluk_kart_gecmis_tablolari()`'nda.
- **Geri al** ilk çıkışa kadar (daily_scan, gerekçe); dönemler iptal (silinmez), okutmalar yeni taslağa kopyalanır.
- Mesai Detayı'nda `source='ters'` → "↩ Ters" rozeti. Hakediş/cari/rapor kaynağa göre DALLANMAZ — dallanma EKLEME.
- **v355 — TEK çıkış noktası = Ortak Çıkış** (sahip kararı): ayrı "Hızlı Çıkış" ekranı kalktı; `ters_kart_cikis.php`
  yalnız `gunluk_isci_giris_cikis.php?mod=ortak`'a yönlendirir (eski önbellekli sayfalar için POST `?ajax=cikis` aynı
  `pdks_gunluk_ortak_cikis_kaydet()`'i çağırır). Kiosk `?mod=ortak` ile doğrudan Ortak Çıkış modunda açılır.
  `pdks_gunluk_faz8a_cikis_kaydet()` yanıtı `card.entry_source` (scan/tanimli/manual/ters/legacy_backfill — yalnız bilgi)
  taşır; kiosk ÇIKIŞ sonucunda scan dışı kaynakta `.pdks-result-kaynak` satırı ("↩ Ters Kart · sabit giriş 08:00").
  Mutabakat çıkışı hangi ekrandan yapıldığına bakmaz (dönem tablosundan sayar). Ters sekmesi/hub "🚪 Ortak Çıkış".
  `?mod=ortak` + önceki günden açık mesai: ÖNCE #giEskiSec penceresi, "Sonra"/son mesai kapanınca Ortak Çıkış (`ortakDonus`, tek seferlik).
- **v355 — görünen ad "Giriş Tanımlı"** (sahip kararı; kod/URL/tablo adları `ters_*` KALDI). Personel Takibi kartı ikonu
  `assets/mobile-visual/giris-tanimli.svg` (`.pdks-icon-giris-tanimli`). ⚠ v298 "🏷 TANIMLI GİRİŞ" (kiosk, kart→çavuş tanımı)
  AYRI özelliktir — karıştırma.
- **"Çıkış girişten önce olamaz" kapısı YALNIZ `source='ters'`** (`pdks_gunluk_faz8a_cikis_kaydet`, kod `giris_saati_gelmedi`).
  Genelleştirme: v354'te tüm kaynaklara uygulanınca `pdks_faz8h_smoke` (sabit 08:00 girişli veri) sabah erken saatte düşüyordu.
- **v363 — Manuel Sayım** (şema YOK): taslakta "📇 Kartla Say" / "🔢 Manuel Sayı Gir". Manuel = elde kalan kart SAYISI;
  `ters_sayim.durum='manuel'` (onaylı mı → `pdks_ters_sayim_onayli_mi()`, `'onayli'` diye elle yazma), sabah DÖNEM YAZILMAZ.
  Akşam Ortak Çıkış `acik_donem_yok` dönerse `pdks_ters_ortak_cikis_manuel_yedek()` → `pdks_ters_manuel_cikis()` (satir_kontrol +
  satir_yaz, sabit giriş + gerçek çıkış, KAPALI dönem, `ters_sayim_kart.rol='manuel_cikis'`); en fazla limit = dağıtılan − zaten
  içeride, aşılırsa `manuel_limit`. `pdks_gunluk_ortak_cikis_kaydet()` DEĞİŞMEDİ. Geri al yalnız ilk çıkıştan önce. Okutulmayan
  kişi için dönem UYDURULMAZ. Ayrıntı: `docs/TERS_KART_TAKIP.md` "v363".
- Test: `php scripts/pdks_ters_kart_smoke.php` (A–M) · `node scripts/pdks_ters_kart_smoke.js` (render'ı kendisi üretir;
  tek render: `TK_SAYFA=<sayfa> php scripts/pdks_ters_kart_render.php`).

## Aktif Depo Sistemi (Sprint Depo-01)

- **Zorunlu tek depo:** Girişten sonra `depo_sec.php` depo seçtirir; seçilmeden hiçbir sayfa açılmaz ("Tüm Depolar" yok). Cookie: `asya_depo` (180 gün).
- **Tek kapı:** `user_allowed_depots()` (config/auth.php) aktif depo seçiliyse `[aktif_depo]` döner — TÜM filtre yardımcıları buradan beslenir.
- **Filtre yardımcıları:** `depo_sql_records()` (named), `depo_sql_column()` (named), `depo_sql_in()` (pozisyonel, kolon), `depo_sql_records_in()` (pozisyonel, EXISTS).
- **Yeni sorgu yazarken:** loading_records → `depo_sql_records[_in]`, depo kolonu olan tablo → `depo_sql_column`/`depo_sql_in`. UNUTMA!
- **Damgalama:** Yeni kayıt formlarında depo varsayılanı = `active_depot()` (_form.php, kantar_create, malzeme_stok_islem).
- **Tekil görüntüleme koruması:** record_view (palet depo kontrolü), kantar_view (`depot_visible_to_user`).
- **Depo değiştirme:** topbar `.brand-depot` + sidebar `.sidebar-depo` → depo_sec.php. Audit: `depot_switch`.
- **Atanmamış veri kuralı:** Deposu BOŞ kayıt/fiş/hareket TÜM depolarda görünür ve erişilebilir (filtreler `IN(aktif depo) OR depo=''`). Depo özelliği hiçbir eski veriyi kaybetmez/kilitlemez. Tekil görüntüleme guard'ları da boş depoyu geçirir; yalnız GERÇEK başka depoya ait kayıt 403 verir (mesaj hangi depo olduğunu söyler).
- **Bilinçli istisna — Hesap modülü:** kişisel cari depo filtresi KULLANMAZ
  (`depo_sql_in` / `depot_visible_to_user` yok); `depo` kolonu yalnız bilgi amaçlı
  damgadır. Bkz. "Hesap Modülü" bölümü.
- **Eski depo'suz veri:** geçiş tamamlandı; `depo_tasima.php` v281'de 410 tombstone. Boş depolu satır yine "Atanmamış veri kuralı" ile tüm depolarda görünür.
- **Depo listesi kaynağı:** `material_definitions type='depo'` (`depot_options()` = tanımlar ∩ `user_depolar` ataması).
- **Harf-duyarsızlık:** Depo eşleşmeleri TR-duyarsız (`depo_fold`/`depo_in_allowed`/`depot_visible_to_user`). "KARAMAN CİHAT" == "Karaman Cihat" — liste (MySQL ci) ile tekil guard tutarlı.
- **Depo adı yayılımı:** Depo tanımı adı değişince `sync_depot_name_in_data()` tüm depo kolonlarını (loading_pallets/kantar_fisleri/material_stock_movements/stock_counts/customs_declarations.exit_depot) yeni yazıma çeker (definitions.php update + audit `depot_rename_sync`).
- **Depo rengi (Sprint Depo-02):** `material_definitions.color` (VARCHAR7, nullable). `depot_color($name)` → admin renk seçtiyse onu, seçmediyse isimden türetilen sabit palet rengini döner (`depot_color_palette()`). `render_header()` aktif depo varsa `<body style="--depot-accent;--depot-accent-rgb;--depot-accent-text">` enjekte eder — sidebar sol şerit/marka alt çizgi/`.sidebar-depo`, topbar `.brand-depot` + alt şerit, mobil `.bottomnav` üst şerit hep bu değişkeni kullanır; depo değişince otomatik güncellenir. Renk seçici: `definitions.php` sol panelde depo türü seçiliyken görünür (`color_reset=1` → otomatik palete dön).

## Maliyet Hesabı Modülü (Sprint Maliyet-01)

Excel taslağının ("MALİYET ÇALIŞMA") sisteme taşınmış hâli. **Amaç: kullanıcı sonradan
kendi alanlarını ve hesaplama kurallarını ekleyebilsin** — kod değişikliği gerektirmez.

**Dosyalar:** `maliyet.php` (liste) · `maliyet_form.php` + `_maliyet_row.php` (form) ·
`maliyet_view.php` (görüntüle/yazdır) · `maliyet_sil.php` · `maliyet_alanlar.php` (alan/formül tanımları) ·
`maliyet_sablon.php` (şablonlar) · `maliyet_ambalaj.php` (fiyat listesi) ·
`config/cost_calc.php` (şema + formül motoru) · `assets/maliyet.css` + `assets/maliyet.js`.

> Modül kendi CSS/JS dosyasını kullanır (tek-CSS kuralının bilinçli istisnası): yalnız
> `maliyet_*` sayfalarında yüklenir, `style.css`/`app.js`'e hiç dokunmaz → mevcut mobil düzen etkilenmez.

**Tablolar:** `cost_sheets` · `cost_sheet_sections` · `cost_sheet_items` ·
`cost_field_defs` · `cost_templates` / `cost_template_sections` / `cost_template_items` ·
`cost_packaging_prices`. Hepsi `CREATE TABLE IF NOT EXISTS` (`cost_migrate()`), mevcut tabloya ALTER yok.

**Yetkiler:** `maliyet.read` / `.write` / `.delete` / `.unlock` / `.admin`
(admin hepsi, operator+muhasebe read+write). `require_maliyet('write')` kullan.

**Genişletme noktaları (kullanıcı tarafı, kod gerekmez):**
- Kalem ekle/sil/taşı — her bölümde serbest.
- Bölüm ekle — kendi bazı (Net KG / sabit / formül) ve kendi toplamı olur; `include_in_total=0` ile
  genel toplamdan hariç tutulur (Excel'deki depo ortalaması bloğu böyle).
- Başlık alanı ekle — `maliyet_alanlar.php`; tip `number` ise formüllerde `[kod]` olarak kullanılır,
  tip `formula` ise girdi almaz, hesaplar.
- Ambalaj fiyatı — `maliyet_ambalaj.php`; kalem adı yazılınca birim fiyat otomatik dolar.
- Şablon — mevcut bir hesaptan "Şablon Yap"; yeni hesaplar bu setle açılır.

**Kalem hesap tipleri** (`cost_calc_types()`): `qty_price` · `per_kg` · `fixed` · `percent` ·
`formula` · `subtotal` · `info`. Yeni tip eklerken üç yeri birden güncelle:
`cost_calc_types()` + `cost_compute_section_items()` (PHP) + `computeSection()`/`applyRowType()` (JS).

**Formül motoru — `eval()` YOK.** Kendi tokenizer + shunting-yard + RPN'i (`config/cost_calc.php`).
`assets/maliyet.js` aynı semantiğin aynası; **canlı önizleme sadece JS, kaydedilen tutarları
her zaman PHP yeniden hesaplar** (tek otorite). İkisini birlikte değiştir.

```
[kod]           → kalemin tutarı        [kod.miktar] [kod.fiyat] [kod.birim_maliyet]
[net_kg] [baz] [kur] [navlun] [satis_kg] [satis_fiyat]
[ust_toplam] [bolum_toplam] [genel_toplam]
yuvarla(x;n) min maks mutlak tavan taban topla ort eger(kosul;a;b)
```

- Bağımlılıklar topolojik sıralanır → ileri referans çalışır, **döngü tespit edilip uyarı verilir** (0 kabul edilir).
- Ondalık ayracı virgül de nokta da olur; fonksiyon argümanları **noktalı virgülle** ayrılır.
- `is_income=1` satır toplamdan **düşülür** (Excel'deki çıkma satırı).

**Dikkat:**
- `hidden` özniteliği `.mly-*` sınıflarının `display` kurallarını ezemez —
  `maliyet.css` başındaki `[hidden]{display:none!important}` kuralını **silme**.
- Kaydetme bölüm/kalemleri silip yeniden yazar (sıralama sadeliği için); id'ler değişir, dışarıdan referans verme.
- `status='kesin'` kilitler; açmak `maliyet.unlock` + revizyon nedeni ister.
- Depo damgası `cost_sheets.depo`; liste `depo_sql_column('depo')`, tekil erişim `depot_visible_to_user()`.

---

## Beyan → Hal Kayıt Bildirimi (Sprint Beyan-Bildirim-01)

Beyan ekranındaki **"🏛 Bildirim Yap"** butonu, beyandaki veriden bir **HKS taslağı**
açar. Gönderim yapmaz.

**Dosyalar:** `api_beyan_bildirim.php` (JSON uç: `hazirla` / `taslak_olustur`) ·
`halkayit/taslak_lib.php` (ortak kütüphane) · `beyan_view.php` (buton + modal + geçmiş).
**Tablolar:** `beyan_hks_bildirim` (bağ + geçmiş) · `hks_eslesme` (öğrenilen eşlemeler) ·
`customs_declarations.vehicle_plate` + `.hks_durum` (yeni kolonlar).
**Test — üç katman, üçü de ağsız ve canlı DB'ye dokunmaz:**
1. `php scripts/beyan_bildirim_smoke.php` — statik: kaynak kodda kural arar.
2. `php scripts/beyan_ui_smoke.php` — **render**: bellek içi SQLite ile
   `beyan_view.php` + `beyan_edit.php`'yi gerçekten çalıştırır.
3. `node scripts/beyan_js_smoke.js` — **tarayıcı**: Playwright + Chromium ile
   `assets/app.js` davranışını doğrular (yazarak arama, boşluksuz plaka, pasif
   buton görünümü). Playwright yoksa kendini ATLAR, hata vermez — PHP-only
   depoda zorunlu bağımlılık olmasın diye. **Arayüz davranışı değiştirdiysen
   bunu çalıştır:** ilk iki katman JS'i hiç çalıştırmaz, üst üste gözden kaçan
   sorunların hepsi bu katmandaydı.
**Sürüm görünürlüğü:** `APP_SURUM` masaüstü sidebar'ının altında, ayrıca
`index.php` sayfa sonunda ve `beyan_bildirim_tani.php` başlığında yazar —
mobilde sidebar görünmediği için "deploy yansıdı mı?" sorusu oradan cevaplanır.
**Ön kontrol:** `beyan_bildirim_tani.php` (admin, salt-okunur) — migration, katalog
(kodun ADA göre aradığı "İhracat" sıfatı / "Satış" türü / "Yurt Dışı" işletme türü),
ürün eşleşme oranı ve beyan hazırlık özeti. **Canlıya alınca İLK burayı aç.**
Kural KOPYALAMAZ, uygulamanın kendi fonksiyonlarını çağırır — "TAMAM" diyorsa
özellik de aynı kararı verir.

**Değiştirmeden önce oku:**
- **Taslak yazmanın TEK yolu `hks_taslak_olustur()`** (`halkayit/taslak_lib.php`).
  `hks_bildirim_dogrula()` canlı sistemde öğrenilmiş kuralları (TC algoritması,
  KPS kimlik bütünlüğü, Üreticiden Sevk Alım kısıtları) taşır. **İKİNCİ BİR YAZMA
  YOLU AÇMA** — iki yol ayrışır, ayrışan taraf sessizce hatalı bildirim gönderir.
- **Beyan ekranı HKS'e HİÇBİR ŞEY GÖNDERMEZ.** `BildirimKaydet` geri alınamaz ve
  rüsum doğurur; gönderim yalnız `taslak_gonder` yolunda, oradaki atomik
  mükerrer-gönderim koruması ile yapılır.
- **Canlı SOAP çağrısı yok** — katalog listeleri `hks_kv.listeler_cache`'ten okunur.
  Önbellek boşsa özellik **fail-closed** davranır (409 + "Listeleri Güncelle" yönlendirmesi).
- **Plan taslağı olarak yazılır** (`planKg` var, künye yok): künyeler gönderim anında
  canlı stoktan çözülür. Stok yetmezse hiçbir bildirim gitmez, taslak korunur.
- **Bağ, taslağın İÇİNDE taşınır** (`ortak.kaynak = {tip:'beyan', beyanId}`). Taslak
  gönderilince satır silinip yeni id ile doğduğu için dış anahtar işe yaramaz;
  `taslak_gonder` ve `taslak_sil` bu izi okuyup bağ kaydını sonuçlandırır
  (`beyan_hks_taslak_isaretle()` — hata yutar, HKS akışını asla kesmez).
- **Bağın yaşam döngüsü — "taslağa atılan beyan bildirilmiş sayılır"** (v273):
  aktif durumlar TEK listede `beyan_hks_aktif_durumlar()` = `taslak` /
  `gonderildi` / **`silindi`** — SQL'de `beyan_hks_aktif_durumlar_sql()`;
  `'taslak','gonderildi'` diye elle YAZMA. Hal Kayıt'ta **Düzenle** yeni id'li
  taslak kaydedip eskisini siler: SPA `eskiTaslakId` gönderir, `taslak_kaydet`
  eski taslağın `ortak.kaynak` izini yeni taslağa aktarır ve bağı
  `beyan_hks_taslak_tasi()` ile yeni id'ye TAŞIR (eskiden silme bağı `iptal`e
  çekiyordu → gönderilmiş beyan yeniden "uygun" görünüyordu). Taslak silinince
  bağ `silindi` olur ve beyan KENDİLİĞİNDEN açılmaz. Gönderimde taslak id'si
  tutmazsa `kaynak.beyanId` yedek yol olarak kullanılır (yalnız gönderimde).
  Elle düzeltme `beyan_edit.php` → "🏛 Hal Bildirim Durumu" (ana formun
  DIŞINDA ayrı form): **Gönderildi Olarak İşaretle** (aktif bağ yokken; tek
  eşleşen `hks_gonderilenler` satırı varsa `gonderim_id` bağlanır) ve
  **Tekrar Aktif Et** (YALNIZ `silindi` → `iptal`). `beyan_hks_bag_duzelt()`,
  audit `beyan_hks_bag_duzelt`. Test: `beyan_ui_smoke.php` `[bag]` satırları.
- **Eşleştirme BEYANIN kalıcı alanıdır** (`hks_firma_id` / `hks_urun_id` +`_ad` /
  `hks_ulke_id` +`_ad`), beyan formundaki **"🏛 Hal Bildirim Bilgileri"**
  bölümünde girilir (`beyan_hks_form_bolumu()` — iki formun ORTAK parçası).
  Bildirim ekranı bunları **salt okunur** gösterir ve `taslak_olustur` değerleri
  **beyandan** okur, istemciden ASLA — aksi hâlde beyanda görünenden başka bir
  bildirim kurulabilirdi. Öğrenme (`hks_eslesme`) beyan kaydında yapılır,
  uç noktada TEKRARLANMAZ (iki yazıcı aynı anahtarı ezerdi).
- **Buton kapısı:** plaka dolu + **eşleştirme tam** (`beyan_hks_eslesme_tam()`) +
  durum uygun + net KG > 0 + aktif bildirim yok + **çift yetki** (`beyan.write`
  **ve** `records.write`). Plaka dolu olsa bile eşleştirme boşsa geçilmez.
  Kapalıysa sebebi yazılır.
- **1 beyan = 1 ürün = 1 bildirim.** Aktif (`taslak`/`gonderildi`) bağ varsa ikincisi
  açılmaz. İki ürünlü gümrük beyanı sisteme **iki ayrı beyan** olarak girilir.
- **Net KG zorunlu, brüte düşülmez** — rüsum net üzerinden hesaplanır.
- **Birim fiyat ÖNERİLİR, otomatik DOLDURULMAZ** (`bb_fiyat_onerileri`). HKS'in
  `MalinSatisFiyat` alanında **para birimi yoktur** (hks_soap.php) ve rüsum bu
  sayıdan hesaplanır. Kaynaklar: ① aynı firma+ürüne yapılmış son bildirimin
  fiyatı (`hks_gonderilenler` — HKS'in kendi biriminde, belirsizlik yok)
  ② bağlı planın maliyet hesabı (`cost_sheets.sale_unit_price`, KENDİ para
  biriminde — ham hâli ve kurla çevrilmiş hâli AYRI öneri olarak sunulur;
  **sessizce çevrilmez**). Maliyet önerisi `maliyet.read` yetkisine bağlıdır.
- **Modalde "alım" türleri YOK** (`bb_alim_turu_mu`). Satın Alım / Üreticiden Sevk
  Alım REFERANSSIZ bildirimdir — malın tam tanımını ister, plan taslağı bunu
  taşıyamaz; ayrıca HKS "İhracat" sıfatıyla Üreticiden Sevk Alım'ı reddediyor.
  Alım bildirimi Hal Kayıt panelinden yapılır; kuralların hepsi orada duruyor.
- **Ülke ipucu zinciri** (`bb_ulke_adaylari` → `bb_ulke_tahmin`): beyanda ülke
  alanı YOK. Sırayla denenir — ① bağlı planın `gidecek_ulke`si ② **şirket adı**
  (`company_name`) ③ **adresin ülke parçası** (`bb_adres_ulke_parcasi` —
  WhatsApp metnindeki `RUSSIA, 108811, G.MOSKVA...` satırının ilk virgülden
  önceki kısmı) ④ alıcı adı ⑤ aynı alıcıya yapılmış en son yüklemenin ülkesi.
  ⑥ ham metin (`raw_text`/`unmatched_text`) satır/virgül parçaları — yalnız
  ARAMA için. İlk çözülen kazanır; hiçbiri çözülmezse alan **boş kalır**.
- **Ülke adı köprüsü** (`bb_ulke_adi_karsiliklari` / `bb_ulke_karsiligi`):
  katalog Türkçe ("Rusya"), beyan metni gümrük evrakından geldiği için
  İngilizce ("RUSSIA", "RUSSIAN FEDERATION") — tam ad eşleşmesi hiç
  tutmuyordu. Tablo **olgusal ad karşılığıdır, tahmin değil**; parçalı/bulanık
  eşleşme YOK (Niger/Nigeria sessizce karışırdı). Tablo **Türkçe AD** döndürür,
  HKS id'si DEĞİL — id her zaman canlı katalogdan tam ad eşleşmesiyle bulunur;
  katalogda karşılığı yoksa eşleşme olmaz. Bir karşılık için birden çok Türkçe
  aday verilebilir (ör. "Rusya" / "Rusya Federasyonu") — katalog yazımı
  bilinmediği için sırayla denenir.
- **`bb_ulke_ad_norm()` ayrı bir normalizasyondur** — `hks_eslesme_norm()`
  Türkçe metin için ASCII **"I" → "ı"** (noktasız) yapar, "RUSSIAN" böylece
  "russıan" olur ve tabloyla HİÇ eşleşmez. Yabancı adlarda bunu kullanma.
  Kaydederken YALNIZ ②③④ `beyan_hks_ulke_ogren()` ile öğrenilir. ⑤ ve ⑥
  **ÖĞRENİLMEZ**: ham metin parçası anahtar olsaydı "Yeni Beyan" gibi her
  beyanda geçen bir satır tüm beyanlara yanlış ülke ön-doldururdu. Tarama
  güvenlidir çünkü çözüm kanonik ad karşılığı ya da tam katalog eşleşmesi
  gerektirir — bir metin parçası kendiliğinden ülkeye dönüşemez. ⑥ adayları
  için `bb_tahmin(..., $ogrenilenAra=false)` çağrılır: onlarca metin parçası
  için `hks_eslesme` sorgusu açılmaz.
- **Beyan öğrenilebilir alanlar (v327):** oluşturma/düzenlemede öneri ve açık
  `+ ekle` işlemi kullanılır; kayıt kaydetmek otomatik tanım oluşturmaz.
  `beyan.write` ve CSRF ile ayrı beyan tanım havuzu, mevcut HKS ürün kataloğu
  ve geçmiş kayıt önerileri kullanılır. Yeni DB şeması yoktur. Gümrük Adı /
  Adresi mevcut `company_name` / `company_address` kolonlarında korunur;
  ad seçimi bağlı adresi doldurur. Gümrük ve iletişim bölümü sürekli görünür.
  Başlık/Beyan Tipi formdan kaldırılmıştır; eski değer sıradan düzenlemede
  silinmez. Bağlantı düzeltme alanı kullanıcı kararıyla gizlidir.
- **Öneri listeleri TEMİZ BAŞLANGIÇ (v331, sahip kararı):** beyan formu önerileri (`beyan_suggest_form_data()`)
  `BEYAN_ONERI_BASLANGIC` (helpers.php, `2026-10-12 00:00:00`) ÖNCESİ veriyi göstermez: geçmiş beyanlar
  (`created_at >=`), zaman damgasız eski KV tanımları (`added_at` yoksa gizli; `+ekle` yeniden ekleyince
  damgalanır) ve genel tanım havuzu (`material_definitions`) KULLANILMAZ. HKS ürün KATALOĞU kalır (ürün adı →
  HKS id eşlemesi ona dayanır). Tarihi ileri almak = yeni temiz başlangıç; geri ALMA.
- **Not alanı (v332, sahip GO verdi — TEK şema değişikliği):** `customs_declarations.note TEXT NULL`
  (auto-migration `config/helpers.php`, yalnız ADD COLUMN). Beyan formunun "Beyan" sekmesinde en altta
  "Not" bölümü (`name="note"`, ≤2000 karakter); önizlemede yalnız doluysa 📝 Not kartı. Büyük harfe
  ÇEVRİLMEZ (serbest metin), öneri havuzuna girmez, **Kopyala'da kopyalanmaz** (o sevkiyata özel). Düzenle
  POST'unda `note` gönderilmezse eski değer korunur.
- **WhatsApp metni + Kopyala (v333):** Önizleme (`beyan_view.php`) üst çubuğundaki **⧉ Kopyala** artık beyan
  verisinden üretilen WhatsApp metnini panoya kopyalar (`beyan_whatsapp_metin()` — config/helpers.php, saf, DB yok;
  `*kalın*` + simgeler; boş alan/bölüm atlanır; değerlerdeki `* _ ~ \`` temizlenir). Metin `<script type="application/json"
  id="waMetin">` içinde, kopyalama `navigator.clipboard` + `execCommand` yedeği. Giriş ayrıştırıcısı (`beyan_parse.php`) DEĞİŞMEDİ.
  Numune ayrı kolon değil: ham/eşleşmeyen metinde "NUMUNE" satırından sonra aranır (`beyan_whatsapp_numune()`); Önemli Not = `note`.
  TASLAK oluşturan eski "Kopyala" **"Taslak Oluştur"** adını aldı (📝) ve YALNIZ `beyanlar.php` listesindedir (`beyan_kopyala.php`
  dosyası/URL'si aynı). İkisini karıştırma: önizlemede taslak formu YOK. Test: `php scripts/beyan_whatsapp_smoke.php`.
- **Numune alanları (v334, sahip GO verdi — 4 nullable ADD COLUMN):** `customs_declarations.sample_gross_kg` / `sample_net_kg`
  (DECIMAL 12,3) · `sample_crate_type` (VARCHAR 100) · `sample_crate_count` (INT); auto-migration `config/helpers.php`. Formda
  **Analiz sekmesinde** "🧪 Numune" bölümü (`_beyan_form.php`, Analiz Bilgileri'nin altı), önizlemede yalnız doluysa kart. Kayıtta `beyan_veri_dogrula()` yalnız
  GÖNDERİLEN alanı işler (net ≤ brüt); `beyan_edit` gönderilmeyen numuneyi korur. WhatsApp metni (`beyan_whatsapp_numune()`) ÖNCE
  bu kolonlara bakar (tarih/başka alan gerekmez), hepsi boşsa eski ham-metin ayrıştırması yedek kalır. Kopyala (Taslak Oluştur)
  numuneyi KOPYALAMAZ (o sevkiyata özel).
- **Form sekmeleri + önizleme yerleşimi (v336):** Beyan formunda sekmeler **Beyan · Analiz · Hal Bildirim · Kaynak metin**.
  HKS bölümü (`beyan_hks_form_bolumu()`: firma/ürün/ülke/plaka) artık sağ `<aside>` DEĞİL, kendi sekme panelindedir
  (`#beyanPanel_hks`, `.beyan-workspace-hks` kabı, max 640px) — HKS verisinin girileceği TEK yer. Beyan veren (Beyan/Analiz/Kaynak)
  ile bildirim yapan (Hal Bildirim) alanları ayrı sekmelerde. Önizlemede Numune kartı Analiz / Durum kartının YANINDA
  (`.bv-yanyana`, numune doluysa `-2` ile ≥900px iki kolon; mobilde alt alta).
- **Beyan detay eylemleri (v341):** Masaüstünde (≥1024px) seçili durumun sağında seçenekler kartlar gibi animasyonla yayılır; fare ayrıldıktan 3 saniye sonra animasyonla kapanır, geri gelince kapanma iptal edilir. Tıklama ve klavye erişimi desteklenir; mobilde mevcut durum şeridi sürer. Seçim tek `status_only` formuyla sunucuya gider; RED notu, CSRF ve yetki kontrolleri korunur. WhatsApp kopyalama düğmesi parti numarasının altında, yeşil renktedir; altındaki tekrar durum rozeti ve kayıt numarası kaldırılmıştır. Başlangıç sürümü v340 / PR #713 hızlı plaka girişini içerir.
- **Hızlı plaka girişi + form sadeleştirme (v340):** Önizlemedeki Hal Kayıt Bildirimi kartında plaka EKSİKSE hücre tıklanabilir
  (`#plakaHizliBtn`, turuncu) ve küçük native `<dialog id="plakaHizliDlg">` açar; kayıt `api_beyan_plaka.php` (POST JSON + CSRF,
  kapı `beyan.write` + `hks.write`, yalnız `vehicle_plate` UPDATE'i, `beyan_plaka_normalize()`, 3-20 harf/rakam/tire, audit
  `beyan_plaka_hizli`, arşivlenmiş beyan 409) ve sayfayı yeniler (düğme kapıları kendiliğinden güncellenir). Plaka doluyken hücre
  düz kalır. Tam düzenleme gerekmez. Beyan oluştur/düzenle formundaki parantez içi ipuçları ve alt metinler (isteğe bağlı/zorunlu
  notları, HKS giriş paragrafı, "katalogdan eşleşti" etiketleri, durum ipucu, silme notu, "Değişiklik yok" vb.) KALDIRILDI — geri
  ekleme (görüntü kirliliği, sahip kararı). Test: `beyan_ui_smoke.php` `[plaka]`/`[temiz]` satırları.
- **Form düzeni + koyu tema (v342):** Beyan formunda ilk kart **"Parti/Sevkiyat"** (eski Beyan / Sevkiyat); **Durum seçimi
  "Gümrük ve İletişim" kartının sonunda**; "Marka" etiketi **"Ambalaj Marka"** (form + önizleme). Masaüstü kenar çubuğunun
  logo satırında tema düğmesi (`.tema-dongu.sidebar-tema`, `asyaTemaDondur()`; tema JS'i `.tema-dongu` sınıfıyla TÜM düğmeleri
  günceller — id'ye bağlama). Koyu tema düzeltmeleri: öneri kutusu "+ ekle" satırı (`.ms-sug-add` koyu kuralı), beyan liste
  sayaç rozeti kontrastı. Yeni açık renkli yüzey/rozet eklerken `html[data-theme="dark"]` karşılığını DA yaz.
- **Gönderici/Ülke/Çekici/Dorse + Numune taşıması + benzer ürün eşleme (v343, sahip GO verdi — 2 nullable ADD COLUMN):**
  `customs_declarations.tractor_plate` (VARCHAR 20) ve `sender_company_name` (VARCHAR 150), auto-migration helpers.php. **Parti/Sevkiyat**
  kartında: Gönderici Firma (HKS firmalarından datalist, listede olmayan ad da serbest — `beyan_gonderici_coz()` TR-duyarsız TAM ad
  eşleşirse `hks_firma_id`'yi çözer, değilse boş bırakır) · Ülke (`hks_ulke_id`, HKS kataloğu) · Çekici Plaka (`tractor_plate`, HKS'e GİTMEZ)
  · Dorse Plaka (= `vehicle_plate`, HKS bildirimindeki plaka). Hal Bildirim sekmesinde YALNIZ HKS Ürünü kaldı
  (`beyan_hks_form_bolumu(..., 'sevkiyat'|'urun')`). `sender_company_name` HKS izin grubundadır (`beyan_permission_fields`).
  Önizlemede Lojistik / Alıcı kartı bu 4 alanı listeler; **WhatsApp metnine EKLENMEZ** (test denetler). Kopyala (Taslak Oluştur) göndericiyi
  kopyalar, plakaları kopyalamaz. **Numune** formda Beyan sekmesine (Ürün ve Miktarlar'ın altı), önizlemede Ürün Bilgileri gibi tek satırlı 3B
  "Numune Bilgileri" kartına taşındı (Analiz yanındaki `.bv-yanyana` kalktı). **Benzer ürün eşleme** `bb_urun_benzer()`: elle yazılan kısa ad
  ("Kapya") katalogdaki "Biber Salçalık (Kapya)" ile eşleşir — sorgunun TÜM sözcükleri (≥3 harf) katalog sözcüğüne eşit/önek ise aday; TEK
  aday (ya da en az fazla sözcüklü benzersiz aday) seçilir, belirsizse boş; yalnız ÜRÜNDE (ülkede benzer eşleme YOK); form ön-doldurması
  "benzer isimle eşleşti" rozetiyle, Eşleştir düğmesi de aynı. Gönderim yine taslak üzerinden kullanıcı denetiminde.
- **Öneriden sil + listede durum menüsü (v344, migration YOK):** Beyan formu öneri kutusunda (Gümrük Adı vb.) satırın üzerine gelince
  sağda **×** çıkar (`.ms-sug-del`, METİNSİZ düğme — `textContent` değişmez; dokunmatikte hep görünür) → `api_beyan_tanim_sil.php`
  (POST JSON + CSRF, `beyan.create|edit`, adres/alıcı/ilgili için `contact.write`, audit `delete`/`definitions`) →
  `beyan_suggest_hide()`: tanımı KV'den (`beyan_defs_<alan>`) siler VE `beyan_defs_hidden`'a yazar; `beyan_suggest_form_data()` gizlileri
  önerilerden/`companies`'ten çıkarır. Beyan KAYITLARINA dokunmaz; açık "+ ekle" gizliyi geri getirir (`beyan_suggest_save`);
  **HKS katalog ürünleri silinemez**. Liste (`beyanlar.php` + `_beyan_liste.php`): masaüstünde (≥1024px) durum rozeti üzerine gelince
  seçenekler **aşağı doğru** açılır (`.bl-durum`, `position:fixed` menü — `.table-wrap` kırpmaz; fare ayrılınca 3 sn sonra kapanır,
  scroll/resize kapatır). Her satırda tek POST formu `beyan_edit.php` `status_only` + `return_to=liste` + `liste_url` (yalnız
  `beyanlar.php…` kabul edilir → aynı filtre/sayfaya döner); RED için `prompt` + `analysis_note`; yetki `beyan.status`.
  Test: `beyan_suggest_smoke.php` (sil/geri getir) · `beyan_ui_smoke.php` `[durum-menü]`.
- **WhatsApp metin düzeni (v345):** `beyan_whatsapp_metin()` sırası: 📝 YENİ BEYAN (en üstte) → 🏢 firma + adres satırları (adres için 📍/ADRES başlığı ve ayrı TIN/KPP satırı YOK, metin olduğu gibi) → 🚛 taşıma/🌿 hat → parti/ürün/yük → numune → çıkış/ilgili/alıcı/marka → not. Geri ekleme. Test: `beyan_whatsapp_smoke.php`.
- **Önizleme renk sadeleştirmesi (v346):** `beyan_view.php` bölümleri (Temel/Ürün/Numune/Lojistik/Analiz/Bağlantı) tek nötr zemin + başlık bandsız (Yeni Beyan formu gibi); simge karoları/alan simgeleri bölüm renginde KALIR. Hal Kayıt Bildirimi (`hks`) ve uyarı kartları renkli. style.css sonundaki v346 bloğu.
- **Hal Bildirim sekmesi KALDIRILDI + HKS Ürün Adı (v347):** formda sekmeler Beyan · Analiz · Kaynak metin. "HKS Ürün Adı" (`hks_urun_id`, `beyan_hks_form_bolumu($f,$beyan,$yazabilir)` — 'urun' kipi) Ürün ve Miktarlar kartında Ürün Adı'nın yanındadır. Ürün adı bir HKS ürünüyle OTOMATİK eşleşiyorsa (`bb_tahmin`: öğrenilen → tam ad → benzer) alan KİLİTLİ (select `disabled` + `data-hks-kilit` + POST'u taşıyan gizli `input[data-hks-ayna]`); eşleşmiyorsa kullanıcı listeden seçer. Canlı eşleşme `api_beyan_urun_esle.php` (GET, salt okunur, `hks.read`) + app.js (400 ms gecikmeli; eşleşme gidince eski otomatik değer temizlenir). Sunucu kaydı eskisi gibi katalogla doğrular. Katalog boşsa alan çizilmez. Önizlemede Gümrük Adı + Gümrük Adresi Temel Bilgiler'de AYNI satırda (`.bv-gumruk-ad/-adres`, ≥900px flex; ad sığmazsa kendi sütununda sarar, mobilde alt alta). Test: `beyan_ui_smoke.php` `[hks-ürün]`.
- **HKS Ürün Adı davranışı (v348, v347'yi günceller):** alan HER ZAMAN pasif başlar (gri, `data-hks-kilit`, POST'u taşıyan `input[data-hks-ayna]` — boşsa boş değer taşır). Ürün Adı'ndan ÇIKILINCA (`change`; yazarken sorgu YOK) `api_beyan_urun_esle.php` sorgulanır: eşleşirse değer gelir ve pasif kalır; eşleşmezse alan boş + AÇIK olur ve turuncu çerçeve alır (`data-hks-serbest`, `.hks-serbest`). Sayfa ilk açılışında aynı durum sunucuda çizilir (ad yazılı + eşleşme yok → serbest; ad boş/eşleşme var → pasif). Alan başlığı yalnız "HKS Ürün Adı" — kart/başlık çerçevesi YOK.
- **HKS Ürün Adı — SUNUCU KARAR VERİR (v349, hata düzeltmesi):** v348'de ürün adı KABAK→ACUR değiştirilince kilitli alanın gizli kopyasındaki ESKİ id (KABAK) kaydediliyor, kayıt bunu "ACUR→KABAK" diye `hks_eslesme`'ye ÖĞRENİYOR, öğrenilen eşleşme `bb_tahmin`'de önce geldiği için alan sonraki açılışta yine KABAK'a KİLİTLENİYORDU (kendini besleyen döngü; JS isteği gecikir/başarısız olur/eski app.js önbellekte kalırsa tetiklenir). Kurallar: (1) kilit kararı `beyan_hks_urun_otomatik()` = YALNIZ katalog tam ad + benzer ad (`bb_tahmin(..., false)`) — öğrenilen eşleşme KİLİTLEMEZ, yalnız açık alanda öneri; (2) kayıtta `beyan_hks_urun_post_coz($_POST, product_name)` (beyan_edit + beyan_create, `beyan_hks_form_oku`'dan ÖNCE): formda `hks_urun_alan=1` varsa otomatik eşleşme → o id (gönderilen yok sayılır), ad boş → boş, eşleşme yok → yalnız açık select'ten gelen değer (gelmediyse boş); (3) `hks_eslesme_yaz('urun',…)` YALNIZ elle seçimde (`$hks_urun_elle`); (4) gizli değer kopyası (`data-hks-ayna`) KALDIRILDI — geri ekleme; (5) JS uç hatasında alan AÇILIR. Uçtan uca test: `node scripts/beyan_hks_urun_e2e.js` (php -S + dosya SQLite, `scripts/beyan_hks_urun_e2e_server.php`; kullanıcı senaryosu, yarış, uç hatası, elle seçim, zehirli öğrenme, JS kapalı).
- **HKS Ürün Adı — kilit kararı TARAYICIDA, ağsız (v350):** v349'da kilit kararı `api_beyan_urun_esle.php` isteğine bağlıydı; canlıda istek başarısız olunca alan boş AÇILIYOR, Güncelle'de ise sunucu aynı adı eşleştiriyordu (ekran ≠ kayıt). Artık Ürün Adı'ndan çıkılınca (`change`) app.js kararı `otomatik()` ile AĞA GİTMEDEN verir: `urunNorm` = `hks_eslesme_norm` (İ→i, I→ı, küçük harf), tam ad → `urunBenzer` = `bb_urun_benzer` BİREBİR aynası (≥3 harf sözcük, ≥4 harfte önek, en az fazla sözcük, beraberlik → yok); katalog = select option'ları (`data-katalog-disi` "Mevcut seçim" hariç). Eşleşirse alan HİÇ açılmaz; eşleşmezse açılır ve uç YALNIZ öğrenilmiş öneri için sorulur (hata → öneri yok). **PHP kuralını (`beyan_hks_urun_otomatik`/`bb_urun_benzer`/`hks_eslesme_norm`) değiştirirsen JS aynasını da değiştir** — e2e `I` bölümü 14 adda "ekran = kayıt" eşitliğini sınar. Uç artık `ob_start` + `display_errors=0` ile ara çıktıyı JSON'dan atar.
- **Yeni Beyan'da analiz tarihleri + turuncu Düzenle (v353):** Analiz sekmesindeki Numune Alındı / Analiz Sonuç tarihleri artık oluşturma formunda da çizilir (eskiden `$is_edit` koşuluyla yalnız düzenlemede vardı; `beyan_create.php` bu alanları zaten okuyup kaydediyordu). Önizleme üst çubuğundaki Düzenle `.btn-duzenle` ile turuncu (açık + koyu). Test: e2e `J` bölümü.
- **Şoför Adı + Şoför Telefonu (v356, sahip GO verdi — 2 nullable ADD COLUMN):** `customs_declarations.driver_name VARCHAR(150)` ve
  `driver_phone VARCHAR(40)` (auto-migration `config/helpers.php`, v343 `foreach` kalıbı). Form: Parti/Sevkiyat kartının 3. satırında Dorse
  Plaka'dan sonra (`_beyan_form.php`; `$field(..., 'tel')` kipi YALNIZ telefonda `type="tel"` + `inputmode="tel"` verir, `data-uppercase` basmaz;
  `style.css` sonunda `form.bf` için YALNIZ-EKLEME `input[type="tel"]` girdi kuralı (mevcut satırlara dokunulmaz — `pdks_faz1b_static_smoke` silinen satırı reddeder); yeni `data-ic="telefon"` simgesi `form.bf, .bv` kapsamında, açık + koyu tema).
  **Şoför Adı = öneri alanı** (`beyan_suggest_fields()['driver_name']`, KV `beyan_defs_driver_name`, max 150): "+ ekle", × ile silme
  (`api_beyan_tanim_ekle/sil.php`, `contact.write` kapısında) ve temiz başlangıç (`BEYAN_ONERI_BASLANGIC`) aynen geçerli; yükleme kaydındaki
  `material_definitions` `sofor` tipiyle KARIŞMAZ (`type` bilerek boş). `beyan_suggest_form_data()` geçmiş önerileri yeni kolondan okur — kolon yoksa
  öneriler sessizce boş kalır, bu yüzden test şemalarına kolon eklendi. **Telefon öneri listesine GİRMEZ**: `beyan_telefon_normalize()` (saf; yalnız rakam
  ve `+ ( ) - .` boşluk, ardışık boşluk tekler, ≤40) + `beyan_veri_dogrula()` alan gönderildiyse 7–15 rakam ister ("Şoför telefonu 7–15 haneli olmalıdır.").
  İzin: `beyan_permission_fields()` **`contact`** grubu (kişisel veri) — `beyan_redact_fields`/`beyan_protect_input` önizleme + düzenlemede otomatik
  uygular. Edit'te alan HİÇ gönderilmezse eski değer korunur (`tractor_plate` listesinde). **Kopyala'ya (`beyan_kopyala.php`), WhatsApp metnine
  (`beyan_whatsapp_metin`), audit değerlerine ve toplu yola (`beyan_insert`) GİRMEZ**; önizlemede Lojistik / Alıcı kartında Dorse Plaka'dan sonra
  (boşsa '—', `tel:` bağlantısı yok). Test: `beyan_ui_smoke.php` `[şoför]` satırları (oluştur→DB→düzenle→önizleme, geçersiz telefon, yetkisiz kullanıcı,
  gönderilmeyen alan, durum şeridi) · `beyan_suggest_smoke.php` · `beyan_data_smoke.php` · `beyan_kopyala_smoke.php` · `beyan_permissions_smoke.php` ·
  `beyan_workspace_permissions_smoke.js`.
- **Şoför Adı → Şoför Telefonu bağı (v361, migration YOK):** beyan formunda şoför seçilince/yazılıp çıkılınca (`change`) bilinen telefonu kendiliğinden gelir.
  Veri `beyan_suggest_form_data()['drivers']` = `{ŞOFÖR ADI: son telefon}` (geçmiş beyanlardan, `BEYAN_ONERI_BASLANGIC` sonrası; aynı şoförün EN SON telefonu;
  ad `beyan_suggest_normalize` ile büyük harf/TR-duyarsız birleşir; öneriden silinen şoför haritadan da çıkar; telefon `beyan_telefon_normalize`; AYRI sorgu — kolon yoksa
  öneri listeleri etkilenmez) → `_beyan_form.php` `#beyanSuggestMeta.drivers` (**`contact.read` yoksa boş**). İstemci `assets/app.js` öneri bloğunda, gümrük adresi bağıyla
  AYNI kural: telefon yalnız BOŞKEN ya da önceki OTOMATİK değerken değişir (`lastAutoPhone`) — elle yazılan/düzeltilen telefon ezilmez; telefonu bilinmeyen şoförde önceki
  otomatik telefon temizlenir. Yükleme kaydındaki `record_sofor_phone_map()` KULLANILMAZ (temiz başlangıç kararı; geri ekleme istenirse ayrı karar). Yeni beyan kaydedildikçe harita kendiliğinden öğrenir.
  Test: `beyan_suggest_smoke.php` (drivers) · `beyan_ui_smoke.php` `[şoför-bağ]` + `[yetki]` · `node scripts/beyan_workspace_smoke.js` (şoför→telefon satırları).
- **Bekleyen Beyanlar — Yazdır / PDF Gönder (v357, migration YOK, yeni yetki YOK):** `beyanlar.php` "İşlem Bekleyen Beyanlar" kartının başlığında
  **🖨️ Yazdır** ve **📤 PDF Gönder** (masaüstünde "⬇️ PDF İndir") düğmeleri (`.bl-aksiyon`, `#bekleyenYazdirBtn` / `#bekleyenPdfBtn`; kartta satır
  yoksa YOK). Çalışma biçimi Hal Kayıt › Bildirim Çoklu Künye Basım ile AYNI (`halkayit/app.html` `topluYazdirPenceresiAc`/`topluPdfPaylas`): Yazdır = yeni
  pencerede rapor + yazdırma penceresi (A4 YATAY); PDF = `halkayit/html2canvas.min.js` + `jspdf.umd.min.js` YALNIZ ilk basışta yüklenir, **telefonda Web
  Share API ile dosya paylaşılır (WhatsApp paylaşım listesinde açılır), masaüstünde PDF iner**. Paylaşım dokunma izni zaman aşımına uğrarsa (iOS,
  `NotAllowedError`) PDF saklanır, düğme "PDF hazır — Paylaş" olur, sonraki dokunuş paylaşır. Sayfa kesimi satır sınırında, 2. sayfadan itibaren tablo başlığı
  tekrarlanır. **Kapsam = kartta o an görünen sayfa** (arama + sayfalama aynen; ekranda görüneni basar). Veri sunucudan hazır gelir:
  `beyan_bekleyen_yazdir_veri()` (config/helpers.php, saf) → `<script type="application/json" id="bekleyenYazdirVeri">` (JSON_HEX_* ile `</script>` kıramaz);
  sütunlar ekrandaki tablo (**tek "Plaka", "Ülke" ve "Durum" sütunları YOK — v358/v359**; ekran listesinde kalır) + sonda **Şoför Adı / Şoför Telefonu / Çekici Plaka (`tractor_plate`) / Dorse Plaka (`vehicle_plate`)**; `Alıcı` + şoför sütunları yalnız `contact.read`, `HKS Firma`/`Ülke` yalnız `hks.read` ile
  gelir (satırlar `beyan_redact_fields()`'ten geçmiş); TOPLAM satırı Palet/Kasa/Brüt/Net. **v359: aynı dorse plakalı (≥2 satır) beyanların Parti No hücresi aynı renkte** — sunucu `satir_grup` (satır başına grup no / null; plaka büyük harf + yalnız harf/rakam ile karşılaştırılır, tek kalan/plakasız null), renk paleti `assets/beyan_yazdir.js` `RENKLER` (açık tonlar + `print-color-adjust:exact`). İstemci `assets/beyan_yazdir.js` (yalnız beyanlar.php'de yüklenir;
  maliyet.js/hesap.js emsali — app.js'e dokunmaz); hücreler istemcide kaçışlanır. Yeni sütun = YALNIZ `beyan_bekleyen_yazdir_veri()`'ye ekle.
  Test: `php scripts/beyan_ui_smoke.php` (`[yazdır]` satırları) · `node scripts/beyan_yazdir_smoke.js` (kendi fixture'ını `--render-liste` ile üretir:
  masaüstü + telefon, yazdırma penceresi, gerçek PDF indirme, çok sayfa, Web Share, izin reddi).
- **Aynı plaka = aynı Parti No rengi (v365):** "İşlem Bekleyen" listesinde (masaüstü tablo + mobil kart; Kapanan bölümde YOK) dorse plakası aynı olan ≥2 satırın `.bn-parti` rozeti aynı renkte (`bn-g bn-g0..9`, style.css v365 bloğu, açık+koyu). Kural yazdırmayla ORTAK: `beyan_plaka_grup_listesi()` (helpers.php) — iki yerde ayrı yazma. Tek/boş plaka mavi kalır. Sayfa başına (50 satır) hesaplanır. Migration YOK.
- **HKS GÖNDERİLDİ → otomatik YÜKLENDİ (v390, sahip kararı; migration YOK):** bağ `gonderildi` olunca beyan durumu `yuklendi` olur — TEK yer `beyan_hks_gonderildi_yuklendi()` (helpers.php), iki çağıran: `beyan_hks_taslak_isaretle('gonderildi')` (Hal Kayıt gönderimi) ve `beyan_hks_bag_duzelt('gonderildi_isaretle')`. Yalnız BEKLEYEN durumlar taşınır; zaten kapalı (`beyan_kapali_durumlar()` = yüklendi/iptal/red) beyana DOKUNULMAZ (iptal/red HKS gönderimiyle ezilmez). Taslak silme / Tekrar Aktif Et durumu değiştirmez. Audit `beyan_status_change` + `otomatik='hks_gonderildi'`; hata yutulur (HKS akışını kesmez). Eski (v390 öncesi) gönderilmiş beyanlar kendiliğinden taşınmaz — toplu düzeltme ayrı GO ister. Test: `beyan_ui_smoke.php` `[yüklendi]` satırları.
- **Önizlemede "↺ Tekrar Aktif Et" (v389):** Düzenle'deki "Hal Bildirim Durumu" bölümü kullanıcı kararıyla GİZLİ (`hidden`, test sabitliyor) olduğundan taslağı silinmiş beyanda düğme `beyan_view.php` bildirim kartının içindedir (`silindi` bağ + `beyan.notify` + `hks.write`; POST `beyan_edit.php` `hks_bag_islem=tekrar_aktif`).
- **Beyan formunda plaka `beyan_hks_form_bolumu()` İÇİNDEDİR**, "Temel
  Bilgiler"de değil — bildirim için gereken dört alan tek yerde. Katalog boş
  olsa bile çizilir (plaka katalogdan bağımsız). **İkinci bir `vehicle_plate`
  alanı açma** — aynı `name` ile iki alan POST'ta çakışır.
  Bölüm geniş ekranda sağ panelde, küçük ekranda sekmelerin altındadır.
  İki form `_beyan_form.php` ortak şablonunu kullanır.
- **Yeni Beyan görsel katmanı** (`form.bf` — style.css "YENİ BEYAN FORMU" bloğu):
  oluşturma ve düzenleme formuna uygulanır; `beyan_view` ayrı görünümünü korur.
  Kancalar: bölümde `data-bf="wa|hks|temel|urun|lojistik|durum"`
  (renk), başlıkta ve her `.form-group`'ta `data-ic="<simge>"` (CSS mask — rengi
  temadan gelir; simge adları blok sonundaki listede). Başlık emojisi
  `.bf-emoji` içinde durur ve bu formda gizlenir — `beyan_hks_form_bolumu()`
  ortak olduğu için form dışında emoji yedeği korunur. **Yeni alan eklerken
  `.form-group`'a `data-ic` ver**, yoksa sol karo çizilmez (alan yine çalışır).
  Metni Ayrıştır etiketi JS'te sabit değildir, butonun kendi metninden geri yazılır.
- **Yazarak aranabilir select** (`data-aramali="ipucu"` — app.js): Hal Kayıt
  panelindeki "İhracat Yapılan Ülke" kutusunun aynısı. **Asıl `<select>` DOM'da
  KALIR**, yalnız görsel olarak gizlenir — değeri o taşır, dolayısıyla POST,
  sunucu doğrulaması ve mevcut okuma/yazma kodu değişmeden çalışır. Önüne
  `datalist` bağlı bir metin kutusu eklenir; yazılan metin option adlarıyla
  **TAM** eşleşince değer atanır ve `change` yayılır. Eşleşme yoksa kutu uyarı
  rengine döner (`.ara-sec-bos`), sessizce boş kalmaz. Select'i `<input>` ile
  DEĞİŞTİRME — öneri listesi option'lardan üretilir ve değer kaybolur.
  `beyan_hks_form_bolumu()` bunu **yalnız 10'dan uzun listelerde** açar; kısa
  listede arama kutusu fazladan tıklama demektir (halkayit/app.html'in kendi
  gerekçesiyle aynı).
- **Plaka BOŞLUKSUZ + büyük harf** — HKS böyle bekler.
  `beyan_plaka_normalize()` TEK doğruluk kaynağıdır ve ÜÇ kaydetme yolunda da
  çağrılır (`beyan_create` / `beyan_edit` / `bb_taslak_kur`). İstemcideki
  `data-nospace` (app.js, imleç korumalı) yalnız kolaylıktır — JS kapalıysa,
  otomatik doldurmada veya yapıştırmada sunucu yine boşluksuz kaydeder.
  Plaka alanında **örnek (placeholder) metin yok**.
- `hks_eslesme_yaz()` **taşınabilir upsert** kullanır (UPDATE→INSERT→UPDATE);
  `ON DUPLICATE KEY UPDATE` MySQL'e özgüydü ve testlerde sessizce başarısız
  oluyordu — öğrenme hiç doğrulanamıyordu.
- **Sıfat/tür varsayılanı KURAL TABANLI** (`bb_varsayilanlar` — sıfat "İhracat",
  tür "Satış"): `app.html`'deki `listeleriUygula` kuralının aynasıdır, **ikisini
  birlikte değiştir**. "Son kullanılan"dan OKUNMAZ — `hks_kv.sonlar_<firmaId>`
  yalnız plaka/ülke/ürün/karşı taraf tutar, sıfat ve tür orada yoktur.
  Karşılığı bulunamazsa boş döner; **asla id uydurulmaz**.
- **Toplu bildirim** (`beyanlar.php` → `toplu_hazirla` / `toplu_olustur`): seçilen
  her beyan için AYRI taslak. Tekil akışla **aynı** `bb_taslak_kur()`'dan geçer —
  ikinci bir kurulum yolu açma. O fonksiyon `bb_cikti()` ÇAĞIRMAZ, `return`
  eder: bir satırın hatası isteği sonlandırırsa diğer satırlar hiç denenmez.
  Sonuç satır satır döner ve başarısızlar kullanıcıya **yazılır**. Üst sınır
  `BB_TOPLU_LIMIT`. Aynı beyan hem tabloda hem mobil kartta seçilebildiği için
  id'ler istemcide tekilleştirilir.
- **Uygunluk kapısı ÜÇ yerde yaşıyor** — `beyan_view.php` (buton), `beyanlar.php`
  (toplu seçim), `index.php` (sayaç). Kuralı değiştirirken **üçünü birden**
  güncelle, yoksa listede uygun görünen satır uç noktada reddedilir.
- **Derin bağlantı:** `halkayit/index.php?ekran=taslaklar` → iframe'e `#taslaklar`
  olarak geçer (beyaz liste, serbest metin yazılmaz). Taslaklar firma bazlı izole
  olduğu için SPA istegi BEKLETİR ve firma seçilince bir kez tüketir — otomatik
  firma seçmez (yanlış firmanın taslaklarını açma riski).
- **"Yurt Dışı" işletme türü** (`bb_yurtdisi_isletme_turu` — helpers.php) taslağın
  zorunlu alanıdır ve katalogdan ADA göre bulunur. Bulunamazsa taslak
  OLUŞTURULAMAZ (409) — id uydurulmaz. Kural TEK yerdedir; kopyalama.
- **v330 — Beyan listesi + önizleme:** Liste (`beyanlar.php` + `_beyan_liste.php`) Plaka · HKS Firma · Ülke
  sütunlarını taşır (firma adı `beyan_hks_firma_adlari()` tek sorgu), parti no 3B rozet (`.bn-parti`),
  satır sonunda ⧉ Kopyala. **Kopyala** = `beyan_kopyala.php` (POST+CSRF+`beyan.write`): TASLAK kopya açar,
  `beyan_edit.php`'ye yönlendirir; parti no / plaka / ham metin / analiz / plan bağı / HKS bildirim izi
  KOPYALANMAZ (eski plakanın yeni beyanda kalması yanlış bildirimdir), HKS eşleştirmesi kopyalanır.
  **Eşleştir ↔ HKS Taslağı Oluştur DEĞİŞİMLİ düğme:** firma/ürün/ülke eksikse `#hksEslestirBtn`
  (`api_beyan_bildirim.php?action=eslestir` → `beyan_hks_otomatik_eslestir()`: YALNIZ boş alanları tahminle
  doldurur, kayıtlıyı ezmez, id uydurmaz), tamsa `#hksOpenBtn`. Taslak oluşunca doğrudan
  `halkayit/index.php?ekran=taslaklar`'a gidilir. Önizleme bildirim kartı 5 hücre: **HKS Firma · Ülke ·
  Ürün · Net KG · Plaka**; bölüm başlık bandı bir ton koyu (`.bv … > .beyan-section-title`).
- **Bildirim kartı** (`beyan_view.php`, `.bk-*`): sırası **Ülke · Ürün · Net KG ·
  Plaka** — HKS bildiriminin dört zorunlu bilgisi, tek bakışta "hazır mı"
  cevabı. Eksik alan sessiz `—` değil, **işaretli**. Net KG bilerek iki yerde
  (kart + Ürün Bilgileri). **Bildirim Yap butonu ve engel sebebi bu kartın
  İÇİNDE** (`.bk-alt` / `.bk-engel`) — buton etkilediği verinin yanında durur
  ve pasifse sebebi ekranda yazar (`title` ipucu mobilde görünmez). Bildirim
  bölümü sayfada **TEK**; alt bölümde tekrar etme.
- **Üst eylem çubuğu yalnız sayfa düzeyi eylemleri taşır** (Beyanlar / Düzenle /
  Sil). Bağlama ait butonlar kendi kartlarının içine konur — beş buton iki
  satıra sarıyordu ve hangisinin neyi yaptığı belli olmuyordu. Tek birincil
  buton (Düzenle); satır içi renk verme.
- **`.btn:disabled` GLOBAL kuralı** (`style.css`) — bu kural yokken pasif buton
  aktifle birebir aynı görünüyordu (uygulama genelinde: `_form.php` palet
  uygula, `beyanlar.php` toplu kaydet, `audit.php` onay butonları). Silme.
- **Silme POST'tur** — `beyan_delete.php` GET'i bilerek reddeder (GET veri
  değiştirmez). `beyan_edit.php`'deki Sil uzun süre `<a href="beyan_delete.php?id=">`
  idi: tıklanınca GET gidiyor, uç nokta sessizce listeye geri atıyor ve
  **hiçbir şey olmuyordu**. Artık `formaction`/`formmethod="post"` ile
  çevreleyen formu POST eder (iç içe `<form>` geçersizdir) ve form `id`'yi
  gizli alanda taşır — `beyan_delete` onu `$_POST`'tan okur.
  **`beyan_delete.php`'ye `<a href>` ile bağlanma**; test bunu engelliyor.
- **Düzenle formunda Güncelle DOM'da Sil'den ÖNCE** (`order` ile görsel sıra
  korunur). Enter tuşu formun İLK submit butonunu tetikler; Sil önce olsaydı
  metin kutusunda Enter silme başlatırdı.
- **Listede silme YOK** — bilinçli; silme detay ve düzenle ekranlarından yapılır.
- **Beyan listesi İKİ BÖLÜM** (`beyanlar.php`, Sprint Beyan-Liste-02): **ÜSTTE
  "⏳ İşlem Bekleyen Beyanlar"** (`.beyan-blok-acik`), **ALTTA "✅ Kapanan
  Beyanlar" + filtre şeridi** (`.beyan-blok-kapanmis`). Bölümü DURUM belirler:
  `beyan_kapali_durumlar()` (`yuklendi`/`iptal`/`red`) alta, geri kalan HER
  durum üste. Yeni bir durum eklersen o listeyi gözden geçir — listede
  olmayan durum ÜST bölüme düşer (güvenli varsayılan: gözden kaçmaz).
  - **Bölümlerin araması ve sayfalaması bağımsızdır.** Üst bölüm `bek_q` /
    `bek_page`, alt bölüm `q` / `page` kullanır. Diğer bölümün parametreleri
    geçişlerde korunur; eski bekleyen kayıtlar da aranabilir.
  - **Filtre + sayfalama ALT bölümün içinde yaşar** (`$w_kapali` / `$p_kapali`).
    Durum pilleri yalnız **kapalı** durumları listeler; bekleyen bir durum pili
    seçilse arşiv sebepsiz boş görünürdü. Eski yer imi (`?status=taslak`)
    **filtre olarak uygulanmaz** — `$valid_statuses = beyan_kapali_durumlar()`.
  - **Satır/kart biçimi `_beyan_liste.php` partial'ında** — iki bölüm × masaüstü/
    mobil = dört kopya olurdu, ayrışırdı. Partial **fonksiyon TANIMLAMAZ**
    (sayfa testte iki kez include edilir) ve `$sec_rows` / `$sec_secim` /
    `$bildirim_uygun` bekler. Satır düzenini orada değiştir, beyanlar.php'de değil.
  - **"Tümü" seçim kutusu `class="bb-tumu"`, id DEĞİL** — sayfada iki tane var.
    Her biri **yalnız kendi tablosunu** seçer (arşiv satırları istemeden
    işaretlenmesin); mobil kart eşleri değere göre eşitlenir.
  - Uygunluk kapısı (`$bildirim_uygun`) ve toplu bildirim **bölümden bağımsız** —
    `$rows` iki bölümün birleşimidir, aktif bağ sorgusu tek sorguda kalır.
  - İki bölüm de `BEYAN_PER_PAGE` (50) ile sayfalanır; 200 kayıt kesme sınırı yoktur.
- **Bölüm başlıklarında açıklama pili YOK (v360, sahip kararı):** "Onay ve yükleme bekleyen beyan kayıtlarıdır." / "Yükleme tamamlanan, iptal veya reddedilen kayıtlardır." yazıları (`.bl-bilgi`) kaldırıldı — gereksiz yer kaplıyordu; geri ekleme.
- **Filtre şeridi tek satır** (`beyanlar.php`): `[arama] [Ara] [▾ Filtre] [Temizle]`.
  Detay paneli **her genişlikte katlanır** — eskiden `.beyan-filter-toggle`
  yalnız `<768px`'de görünürdü, masaüstünde panel kalıcı açık kalıyor ve on
  durum pili üç satıra sarıp beş girdiyle birlikte liste üstünde ~200px
  kaplıyordu (şimdi kapalıyken 42px). **`@media (min-width:768px)` içine
  `.beyan-filter-toggle{display:none}` geri koyma** — test bunu engelliyor.
- **Durum pilleri panelin İÇİNDE, tek satır** (`.bff-durum`): `flex-wrap:nowrap`
  + `overflow-x:auto` — sarmaz, taşarsa yatay kayar. Bağlantı (link) olarak
  kalmaları bilinçli: tek tıkla filtreler, forma bağlı değiller, JS kapalıyken
  de çalışırlar. Detay filtresi etkinken panel **açık** gelir ve toggle'da
  etkin filtrenin adı rozet olarak yazar (`$detay_ozet` → `.bft-rozet`), böylece
  panel kapalıyken de listenin neye göre süzüldüğü görünür.
- **`scripts/beyan_ui_smoke.php` artık `beyanlar.php`'yi de render eder**
  (`render_liste()` — sayfa iki kez include edildiği için üst seviye
  `function`/`const` bildirimleri koşullu sarılır). Liste düzenini değiştirince
  çalıştır.
- **Durum şeridi — TÜM durumlar tıklanabilir** (`beyan_view.php`, Sprint
  Beyan-Durum-01): dokuz durumun hepsi pil olarak durur, **seçili olan çerçeve
  + halka + ✓ ile işaretli** (`.beyan-durum-secili`), akıştaki sıradaki
  durum(lar) **kesik çerçeve** (`.beyan-durum-onerilen` — ipucu, KAPI DEĞİL).
  Ara durumlara tek tek tıklamak gerekmez; **doğrudan sonuca gidilebilir** ve
  terminal durumdan geri dönülebilir (şerit artık terminal durumda da çizilir).
  - **Şerit TEK formdur**, her durum bir `<button type="submit" name="status"
    value="...">`. Eskiden her buton kendi formunu ve ~26 gizli alanını
    taşıyordu; dokuz durumla bu 230+ gizli alan demekti. **`status` için AYRI
    bir hidden alan AÇMA** — aynı `name` iki yerden gelirse hangisinin
    kazandığı belirsizdir; değeri butonun kendi `value`'su taşır.
  - **Şerit `status_only=1` kullanır.** Sunucu güncel satırı kilitleyip yalnız
    durum/not/analiz tarihlerini değiştirir; gönderilmeyen alanları korur.
    Serbest geçişler sürer. Tam düzenleme `edit_token` ile eşzamanlı değişikliği
    reddeder. Gizli tam satır kopyasını yeniden gönderme.
  - Görsel sıra `beyan_durum_akis_sirasi()` (helpers.php): ana akış soldan sağa,
    **red/iptal en sonda** (`beyan_statuses()` sırası değil — orada `iptal`
    akışın ortasında). Listede olmayan durum SONA eklenir, kaybolmaz.
  - **`beyan_edit.php`'deki durum `<select>`'i AYNI kurala tabi** — tüm durumlar,
    aynı akış sırası. Eskiden sonraki durumlarla sınırlıydı ve terminal durumda
    "değiştirilemez" yazıyordu; sunucu hiçbir zaman kısıtlamadığı için bu iddia
    **yanlıştı**. Kuralı değiştirirken **iki ekranı birden** güncelle; test
    ikisini de sabitliyor.
  - **RED hâlâ not ister** (`prompt_note` → `analysis_note`); sunucu notsuz
    red'i reddeder. `temiz`/`red` seçilince `analysis_result_at` otomatik dolar.
  - Şerit tüm alanları hidden gönderir (tam güncelleme dalı): **yeni kolon
    eklersen o listeye de ekle**, yoksa her durum değişikliğinde silinir.

---

## Hal Kayıt — Kayıtsız Kişi Doğum Tarihi (v291, kesinleşti v304)

Kayıtsız satıcıdan **Satın Alım**'ın İLK bildiriminde HKS "Tc kimlik numarası
Mernis sisteminde bulunamadı" (satır `HataKodu 21`, künye/rüsum YOK) döndürüyordu.
**KÖK NEDEN (canlı deneyle KESİN, 05.10.2026):** HKS `DogumTarihi` metnini
**SAATSİZ `GG.AA.YYYY`** (örn. `11.02.1959`) bekliyor. Aynı kişi (kayıtsız,
gün≤12) beş biçimle denendi: `GG.AA.YYYY 00:00:00` (GTB örneği!), `GG.AA.YYYY 12:00:00`,
`YYYY-AA-GGT12:00:00`, `YYYY-AA-GG` → Mernis; yalnız **`GG.AA.YYYY` → künye**.
GTB örneğindeki `00:00:00` ekli biçim yanıltıcıydı. Sitedeki Sorgula saatsiz
gönderdiği ve geçici bir Mernis sonucu bıraktığı için "sitede sorgula → geçer"
gözlemi de buydu. Rapor: `docs/HKS_MERNIS_ILK_KAYIT_ANALIZ.md` (§11).

- **Varsayılan biçim `gtb_tarih`** (`halkayit/config.php` `HKS_DOGUM_BICIMI`).
  Konum sabit alfabetik (CepTel < DogumTarihi < KisiSifat; canlı WSDL `xs:string`).
  Merdiven/otomatik yeniden gönderim YOK. Yeni uç (`ws.gtb.gov.tr:8443`) aynı sözleşme.
- **Biçim beyaz listesi** `hks_dogum_bicimleri()`: `gtb` · `gtb_oglen` · `gtb_tarih` ·
  `iso` · `iso_oglen` · `iso_tarih`. **`gtb` (00:00:00 ekli) ve ISO'yu varsayılan yapma.**
- **OTOMATİK ÖĞRENME YOK (v304).** Sitede yeni Sorgula'lanmış kişi yanlış biçimle de
  geçer; eski otomatik öğrenme bu yüzden yanlış biçimi kalıcı yazabiliyordu.
  Kalıcı değişiklik yalnız yöneticinin elle işlemidir (`dogum_deney.php` → "Kalıcı yap",
  `hks_kv.dogum_varyant`, `kanitli=true`). Eski kanıtsız kayıtlar (`konum='son'`) yok sayılır.
- **Gidecek yer adresi:** kayıtsız kişide kılavuz 1189-1193 İl/İlçe/Belde ister;
  `api.php taslak_gonder` kişiyi gönderimden hemen önce `hks_kayit_durumu()` ile sorar
  (ENGELLEMEZ), KAYITSIZ ise işyeri adresini (`hks_isyeri_adres_bul()`) `$ortak['gidecekAdres']`'e
  koyar; `hks_bildirim_xml()` işyeriyle BİRLİKTE yazar (GTB 195 örneği gibi).
- **Deney** (`halkayit/dogum_deney.php`, yalnız `is_admin()`, CSRF + audit
  `hks_dogum_deney`): bir TC için TEK KULLANIMLIK biçim (24 sa), yalnız kişi gönderim
  anında KAYITSIZ ise uygulanır ve gönderimden ÖNCE tüketilir; kayıtlı/belirsizse
  gönderim 409 ile DURUR. Ekran HKS'e hiçbir şey göndermez. **Deneyi sitede o gün
  Sorgula'lanmış kişiyle yapma** — geçici sonuç sonucu bozar.
- **Teşhis kaydı** `hks_kv.dogum_denemeleri` (son 200): TC yalnız `***son4`, doğum
  tarihi yalnız SINIF (`gun>12`/`gun<=12`/`gun=ay`). Kütüphane `dogum_deney_lib.php`
  (include-only, `.htaccess`'te kapalı).
- **Tek gönderim yolu** korunur: `hks_bildirim_kaydet()` → `hks_bildirim_kaydet_tek()`.
- Test: `php scripts/hks_uretici_sevk_test.php` · `php scripts/hks_dogum_deney_smoke.php`.

## Hal Kayıt — Karşı Taraf "Üretici" → Cep Zorunlu (v322)

Canlı HKS, ikinci kişi sıfatı TAM "Üretici" iken — kişi/firma GTB'de KAYITLI olsa da — `CepTel` yoksa satırı
**HataKodu 263** ("Lütfen üreticinin GSM numarasını doğru giriniz") ile reddeder (örn. kendi bahçesinden İhracat
sıfatıyla Satın Alım). Eskiden kayıtlı karşı tarafa cep hiç gönderilmiyordu.
- Kural TEK yerde: `hks_uretici_cep_gerekli($o)` (taslak_lib.php; yurt içi + sıfat = `hks_uretici_sifat_id()`;
  katalog yoksa false). `hks_bildirim_dogrula()` cep yoksa/geçersizse reddeder.
- Kayıtlı kişide YALNIZ `ikinciCep` gider (AdSoyad/DogumTarihi YOK); "ad/cep varsa doğum şart" kimlik bütünlüğü
  kuralı artık yalnız cep için KAYITSIZ kişide uygulanır (ad için her zaman).
- `taslak_gonder` yeniden doğrulamadan ÖNCE cep boşsa Kişi Havuzu'ndan TC ile tamamlar (eski taslaklar).
- Arayüz: `ureticiCepGerekli()` → kayıtlı + Üretici sıfatında `#cepTelBlok` görünür (not: "Üretici sıfatında HKS zorunlu
  tutar"), havuz seçiminde cep dolar, kayıtta zorunlu; sonuç ekranında 263 ipucu; kayıtlı kişide "Doğum: GÖNDERİLMEDİ"
  uyarısı yerine "gerekmez".
- **Gönderim Sonucu penceresi (v323):** köşedeki ✕ (`#btnModalX`) ve kutunun dışına (boşluğa) tıklamak YALNIZ kapatır (`sonucKapat()`, bulunulan ekranda kalır; taslak listesi açıksa tazelenir). Gönderilenler'e geçiş/Taslağa dönüş yalnız alttaki "Gönderilenlere Git"/"Taslağa Dön" düğmesiyle. CANLI "Gönderim Onayı" ve firma penceresinde ✕/boşluk kapatması YOK (`#modal.dataset.sonuc='1'` yalnız sonuç ekranında).
- Test: `php scripts/hks_uretici_cep_test.php` · `node scripts/hks_uretici_cep_smoke.js`.

## Hal Kayıt — Kişi Havuzu (v282, v283)

Karşı taraf (Satın Alım'da satıcı, Satış/Sevk'te alıcı) artık firma bazlı
"Son Kullanılanlar" açılır listesinden değil, **GLOBAL havuzdan** seçilir: tüm
firmalar aynı müstahsil/firma listesini görür.

- **Tablo `hks_kisiler`** (tc UNIQUE, yalnız rakam) — `hks_tablolari_hazirla()` →
  `hks_kisi_tablo_hazirla()` ile otomatik oluşur (halkayit deseni). Çekirdek
  mantık `halkayit/kisi_havuzu_lib.php` (include-only, `.htaccess`'te kapalı);
  çıktı basmaz/exit etmez — test onu doğrudan require eder.
- **Uçlar:** `kisiler` (liste + ilk çağrıda tek seferlik içe aktarma) ·
  `kisi_kaydet` · `kisi_sil`. Yazma uçları **CSRF** ister: `app.php`
  `app.html`'deki `__CSRF_TOKEN__` meta yer tutucusunu doldurur, `api()` her
  istekte `X-CSRF-Token` yollar. `app.html`'i `readfile` ile basmaya DÖNME.
- **Doğrulama sunucuda** (`hks_kisi_dogrula`): 10 hane VKN / 11 hane TC
  (algoritma zorunlu), cep boş ya da 10–13 hane, doğum boş ya da geçmiş tarih.
  Aynı TC → 409. **Ad: VKN'de OPSİYONEL (yalnız takip), TC'de zorunlu** (v283) —
  kayıtlı karşı tarafta HKS'e AdSoyad zaten GÖNDERİLMEZ (`hks_soap.php`, yalnız
  doluysa; `karsiTarafSec()` adı yalnız KAYITSIZ sonuçta forma yazar).
- **Sıfat** (`sifat_id`, v283): formda seçilir, kişi seçilince doğrulamadan SONRA
  `#sIkSifat`'a uygulanır (Üreticiden Sevk Alım'da dokunulmaz). Gönderim upsert'inde
  son kullanılan kazanır, Üreticiden Sevk Alım'da YAZILMAZ. Kolon eski tabloya
  `hks_kisi_sifat_kolonu_hazirla()` ile eklenir (ucuz yoklama, ALTER yalnız bir kez).
- **CSRF token ömrü ≠ giriş ömrü:** giriş `asya_session` (uzun), token PHP
  oturumunda (varsayılan GC ~24 dk). Uzun açık kalan SPA eski token'la 403 alıyordu
  (canlı, v282). Çözüm: `csrf` okuma ucu + `api()` 403'te token'ı tazeleyip BİR KEZ
  tekrarlar; meta boş/`__CSRF_TOKEN__` ise önce uçtan alır. Sunucu token'ı
  `hks_csrf_girdi()` ile başlık → gövde `csrf` sırasıyla okur; gövdeye `csrf`
  YALNIZ `kisi_*` çağrılarında eklenir (taslak verisine sızmasın).
- **Gönderim sonrası** `hks_kisi_havuzuna_isle()` upsert eder (boş gelen
  ad/cep/doğum eskiyi SİLMEZ; hata yutulur — künye zaten oluştu).
  `hks_son_guncelle()` artık `karsiTaraflar` YAZMAZ; eski `sonlar_*` kv verisi
  silinmez, bayrak `kisi_havuzu_aktarildi` ile bir kez havuza aktarılır.
- **Seçim canlı sorguya gider:** pencerede kişiye tıklamak `karsiTarafSec()`
  çağırır — saklı bilgi karar vermez, HKS KayitliKisiSorgu yine çalışır.
- **Audit** `module='hks_kisi'`: yalnız ad + maskeli TC; cep/doğum YAZILMAZ.
- Pencere `#kisiPencere` (z 600) + `#kisiForm` (z 650); flex kolon, gövde
  `min-height:0` ile kayar; mobilde alttan sayfa. Kullanıcı verisi yalnız
  `textContent` ile basılır.
- **Test:** `php scripts/hks_kisi_havuzu_smoke.php` (SQLite) ·
  `node scripts/hks_kisi_pencere_smoke.js` (Playwright; yoksa atlar).
- **Bildirim ekranı görsel katmanı (v285):** yalnız CSS (`app.html` "BİLDİRİM
  EKRANI — GÖRSEL KATMAN" bloğu). Kart rengi `.bk-ayar/.bk-sevk/.bk-mal/.bk-kunye`,
  numara rozeti `h2[data-no]` (başlık metninde numara YOK — `kart1Baslik`/
  `kart2Baslik`'ı JS'te yazarken numara EKLEME), alan ikonu `--bi-ikon` (id bazlı
  liste; aranabilir select'in görünen kutusu `<id>Ara`). Yeni alan eklersen
  ikon listesine ekle. Başlık `display:block` kalmalı — flex olursa firma adı
  span'i ayrı sütuna kırılır. Geri düğmeleri tüm ekranlarda ok + "Geri"
  (firma menüsünde "Firmalar").
- **Kilo/fiyat binlik ayırıcı (v286):** `sayiBicimle()` + `binlikGirisBagla()`
  (app.html, `fmt` altında) — yazarken "1.234.567,89", imleç korunur, tuşla
  yazılan "." ondalık virgüle çevrilir. Okuma HER ZAMAN `trSayi()` (noktaları
  siler); `parseFloat(el.value)` YAZMA. Programatik atama `sayiBicimle(n)` ile —
  `String(x).replace('.', ',')` KULLANMA. Yeni `inputmode="decimal"` kutu
  eklersen bağla (test hepsinin bağlı olduğunu denetler).
  Test: `node scripts/hks_binlik_smoke.js`.

---

## Hal Kayıt — Gönderilenler Tablosu + Tekrar Gönder (v305)

Gönderilenler ekranı masaüstünde (iframe ≥900px) kompakt TABLO: Tarih+durum · Firma · Plaka ·
Ülke/Müstahsil (yön öneki atılır, tür etiketi + `title` tam metin) · Ürün · Kilo (tam sayı) ·
Ücret (BİRİM fiyat `TL/KG`, 0 → —) · 🖨 Yazdır · ↻ Tekrar gönder ▾. Satıra tık/Enter/Boşluk →
altında bugünkü kart (`.g-kart-gomulu`, ilk açılışta kurulur). <900px kartlar AYNEN; bilgi satırlarının
altına tam genişlik "↻ Tekrar gönder" düğmesi eklendi (`.g-kart-eylem`, v307) — künye detayının DIŞINDA,
kart kapalıyken de görünür; gömülü masaüstü kartında çizilmez (satırın kendi düğmesi var). Kip `matchMedia('(min-width:900px)')` (`gonderilenCiz()`), eşik geçilince
veri yeniden istenmeden çizilir. Tek delegeli dinleyici `#gonderilenListe` üzerinde.

- **Amaç:** önceki gönderimi forma DOLU açmak (kullanıcı kararı: taslak doğrudan YAZILMAZ). Kullanıcı
  plaka/kilo düzeltip "Taslağa Kaydet" der → yazma yine TEK yol `taslak_kaydet` → `hks_taslak_olustur()`.
  **İkinci yazma yolu AÇMA.**
- **Kopya** (`hks_gonderim_kopyasi()`, `halkayit/tekrar_gonder_lib.php`): `taslak_gonder`'de
  `$veri` çözülür çözülmez (plan çözümünden ve `gidecekAdres`'ten ÖNCE) alınır, `hks_gonderilenler.veri.kopya`
  olarak saklanır (migration YOK). `ortak` BEYAZ LİSTE (`hks_kopya_ortak_anahtarlari()`); DIŞARIDA:
  `kaynak` (beyan bağı — kopyadan açılan taslak beyana bağlanmasın), `gidecekAdres`, `eskiTaslakId`,
  `ikinciDogumTarihi`/`ikinciCep` (kişisel veri). Hata yutar, null dönebilir — gönderimi asla bozmaz.
  Liste ucu yalnız `kopyaVar` döner (kopyanın kendisi DÖNMEZ).
- **Tohum** — salt okunur uç `gonderilen_tohum` (`id`, `firmaId`, `firmaAd`, ops. `hedefFirmaId`):
  firma izolasyonu `gonderilenler` ile BİREBİR; INSERT/UPDATE/DELETE/SOAP YOK (test denetler).
  `hks_gonderim_tohumu()` → `{tohum:{satirlar,ortak}, kaynak:'kopya'|'eski', plana, notlar[]}`.
  Referanslı + künye satırlı kopya → PLAN taslağı (`planKg` = Σmiktar, künyeler gönderimde stoktan;
  kullanılmış künyeler kopyalanmaz). Doğum/cep TC ile Kişi Havuzu'ndan. Firma değişince `sifatId`,
  `gidecekIsyeriId`, `gidecekIsyeriAd` SİLİNİR (firmaya özgü).
- **Eski kayıt (kopya yok):** kolonlardan kısmi kurulum; tür `bildirim_turu` (NULL ise `ulke_ad` öneki),
  ürün/ülke/tür katalogda (`listeler_cache`) yalnız TAM ad (`hks_tr_normalize`) ve TEK eşleşme; karşı
  taraf Kişi Havuzu'nda AD ile TAM ve TEK eşleşme (`hks_kisi_ad_ile_tek()`) — yoksa/birden çoksa BOŞ.
  id ASLA uydurulmaz; eksikler `notlar` ile formun üstünde (`#kopyaNotlar`, textContent) söylenir.
- **Listede karşı taraf adı (v306):** kayıtlı karşı tarafta form adı doldurmaz, `ulkeAd`'a yalnız TC/VKN düşer
  (`Yurt içi → 8330514103`). `gonderilenler` VE `taslaklar` uçları yanıtta bu numarayı Kişi Havuzu adıyla değiştirir
  (`hks_gonderilen_adlari()` tek sorgu + `hks_gonderilen_ulke_isimle()`; önek ve " (İl/İlçe)" eki korunur, havuzda
  yoksa/adı boşsa metin DEĞİŞMEZ). **Yalnız yanıt kopyası:** saklı kayıt değişmez; `taslak_gonder` ve `gonderilen_tohum`
  DB satırından okur, bu çıktıyı KULLANMAZ (test denetler). Hata yutulur — liste adsız da çizilir.
- **Toplam kilo/adet yalnız BAŞARILI satırlardan (v309):** `hks_gonderilenler.toplam_kg`/`adet` eskiden gönderilen TÜM
  satırları topluyordu → 3 künyeden 1'i hatalıysa liste 19.500 yerine hatalıyı da içeren toplamı gösteriyordu.
  Yeni kayıtta `taslak_gonder` `hks_basarili_ozet()` ile yalnız künyesi oluşan satırları yazar; ESKİ kayıtlar listede
  `hks_gonderilen_gercek_toplam()` ile `veri.sonuclar`'dan yeniden hesaplanır (yalnız hatalı satır varsa; DB değişmez,
  migration yok). Eski kayıttan "Tekrar gönder" tohumu da aynı kg'ı kullanır. Başarılı ölçüt `hks_sonuc_basarili_mi()`.
- **Arama süzgeci (v321):** Gönderilenler'de `#gonderilenAra` (liste DIŞINDA; ✕ + "N / M gönderim" sayacı, 150 ms debounce). `gonderilenVeri` HER ZAMAN tam liste; çizim `gonderilenGorunen` = `[{g, i}]` kullanır ve `data-i` / `gonderilenSatirHtml(g,i)` / `gonderilenKartIcerik(g,i)` **özgün indeksi** `i` taşır — süzülmüş dizinin sırasını (`map` indeksi) ASLA yazma, yoksa Yazdır / Tekrar gönder yanlış kaydı açar. Eşleşme `gAraNorm` (trNorm + ı→i), tüm sözcükler AND; alanlar plaka (alfasayısal biçimiyle de), ürün, gönderilen taraf (`gonderilenUlkeParcala().ad`) + tam `ulkeAd`, firma, belge no. Girdi DOM'a basılmaz; ekran her açılışta sıfırlanır; kip değişimi (`gonderilenKipDegisti`) süzgeci korur. Test: `node scripts/hks_gonderilenler_smoke.js` ("filtre:" satırları, 1280 + 390).
- **Künye + fiyat TEK render (v321):** `btnKunyeler.onclick` `/api/kunyeler` ve `/api/kunye_detay`'ı `Promise.allSettled` ile PARALEL ister, fiyat haritasını işleyip TEK `kunyeCiz()` çağırır (eski `kunyeFiyatlariEnjekte` + "fiyatlar yükleniyor…" ikinci çizimi KALDIRILDI — ikinci çizim taslaktan geri yüklenen seçimi silerdi). `kunye_detay` hata verirse künyeler fiyatsız basılır, `#kunyeSayi`'ya "fiyatlar alınamadı" yazılır. Sorgu kimliği `kunyeSorguNo` + istek anındaki firma id'si: eski cevap atılır; `formuTemizle()` de sayacı artırır. `taslakDuzenle`'nin `await onclick()`'i artık fiyatlı tam veriyi bekler. Test: `node scripts/hks_binlik_smoke.js`.
- **"Panel sürümü" damgası KALDIRILDI (v306):** ana menü altındaki `panel sürümü: …` yazısı ve `PANEL_SURUM` sabiti
  gitti; tek sürüm kaynağı sidebar'daki `APP_SURUM`. Geri ekleme (test ana menüde yazının YOKLUĞUNU denetler).
- **v324 görünüm:** Gönderilenler başlığı (Geri + ikon + başlık/alt yazı + illüstrasyon + `#gonderilenAraSay` sayaç hapı), büyük arama kutusu (Ctrl/⌘+K odaklar), lacivert başlık bandı, hap rozetler (tür renkleri), ürün emojisi `gonderilenUrunEmoji()` (kartlarda `urunIkonu()` kalır), split görünümlü "Tekrar gönder" (TEK düğme, iki yarım aynı menüyü açar). Harici görsel/font YOK (inline SVG + emoji). Özgün indeks `i` kuralı aynen geçerli.
- **Kişi Havuzu filtresi (v324):** `#kisiFiltre` Tümü/Firma(VKN)/Müstahsil(TC), `kisiVknMu(k)` tek ayrım kaynağı, arama ile AND, pencere her açılışta Tümü. Test: `hks_kisi_pencere_smoke.js`.
- **Kişi listesi sırası (v325):** `kisiListeCiz()` listeyi İSTEMCİDE A→Z sıralar (`KISI_SIRA` = `Intl.Collator('tr')`, adsız kişi sonda); sunucunun 'son kullanılan' sırası kullanılmaz. Test: `hks_kisi_pencere_smoke.js`.
- **Künye seçim kuralları (v338, kullanıcı kararı):** otomatik seçim (istemci `kunyeOncelikSirasi()` + sunucu `hks_kunye_sirala()` — `halkayit/kunye_secim_lib.php`, İKİSİ BİRLİKTE DEĞİŞİR) sıra anahtarı: [satış fiyatına |alış fiyatı farkı| (2 ondalık; fiyatsız künye sonda) → en eski tarih → en küçük kalan → künyeNo]. Alış fiyatı = künyeyi doğuran bildirimin `MalinSatisFiyati` (`hks_kunye_detaylari_getir`, best-effort; alınamazsa tarih→kalan). Plan taslağı çözümü (`hks_plan_kunye_coz`) aynı sırayı kullanır; `sirala` ayarı yalnız sorguya etki eder.
- **Künye × sıfat uyumsuzluğu (v338):** `taslak_gonder` sonrası künyeye özgü sıfat hatası (hataKodu≠0 + mesajda "sıfat" + [mesajda künye no VEYA aynı gönderimde başarılı satır VEYA tek satır]) `hks_kv` `kunye_sifat_uyumsuz_<firmaId>` içine {künye:{sıfat:tarih}} yazılır (30 gün TTL, firma başına 2000). Sonraki plan çözümü o künyeyi o sıfatla DIŞLAR; `/api/kunyeler` (+`sifatId`) her künyeye `sifatUyumsuz` ekler (rozet; elle seçilebilir, otomatik dağıtıma girmez). Yanıt `sifatUyumsuzKunyeler`. Gönderim yolu DEĞİŞMEDİ (tek SOAP, yeniden deneme YOK). Risk: sıfatın kendisi yanlışsa tek satırlık denemeler künyeleri tek tek işaretleyebilir (30 gün sonra düşer). Test: `php scripts/hks_kunye_secim_test.php` · `node scripts/hks_kunye_secim_smoke.js`.
- **Depo/Tasnif kaynak kuralı (v366):** HKS HataKodu 108 ("Depo/Tasnif sıfatından sevk ederken, ancak referans künye bildirimini yapan kişiye ve sıfatına geri bildirim yapabilirsiniz") künye×sıfat uyumsuzluğu DEĞİLDİR: `hks_kaynak_karsi_taraf_hatasi_mi()` (kunye_secim_lib.php; kod 108 ya da metin) bu satırları `hks_kunye_sifat_hatali_satirlar()`'dan DIŞLAR. Eski yanlış kayıtlar için `kunye_sifat_v366_<firma>` bayrağıyla uyumsuzluk haritası ilk okumada BİR KEZ boşaltılır. 108 → kv `kunye_kaynak_<firma>` = `{künye: {red: {tc: {sıfat: tarih}}, koken, kokenTarih}}` (60 gün, 2000 künye; `koken` = künyenin kaynağı, detay haritası biliniyorsa); yanıtta `kaynakHatalari` (kaynakTc maskeli, `kaynakFarkli`). Mod = `hks_kaynak_sevk_mi($o)` (taslak_lib: SEVK_ETME + bildirimci sıfatı = `hks_depo_tasnif_sifat_id()`, katalogda ADA göre "depo"+"tasnif", tek eşleşme; katalog yoksa kural YOK). Kaynak = BildirimSorgu `BildirimciTcKimlikVergiNo` (kendi VKN'miz değilse) ya da öğrenilen `koken` (`hks_kunye_koken_haritasi`). Bu modda: plan çözümü detay haritasını FİYATSIZ sevkte de ister ve kaynağı KESİN bilinip karşı taraftan farklı künyeyi DENEMEZ (`atlananKaynakFarkli`); `taslak_gonder` gönderimden ÖNCE (elle seçimde detay best-effort sorgulanır) `hks_kaynak_tc_engeli` (kaynak ≠ ikinciTc) ve `hks_kaynak_red_engeli` (aynı künye+TC+sıfat 108 almış) ile durur — taslak geri konur, 400, hiçbir şey gönderilmez; kaynağı bilinmeyen künye engellenmez. `bildirimciSifatId` (`hks_bildirimsorgu_sifat_id`: `Sifat`/`BildirimciSifat`/`BildirimciKisiSifat`/`BildirimciSifatId`) **DOĞRULANMAMIŞ alan adıdır** — canlı yanıtta görülmedi. İstemci (`kaynakUygula()`, `kaynakRozetGuncelle()`): künye seçilince TC boşsa kaynağın TC'si dolar + normal doğrulama; TC farklıysa/karışık kaynakta yalnız UYARI (`#ikKaynakNot`); sıfat `bildirimciSifatId` ya da (kayıtlı sıfatlar − red listesi) tek adayı (elle seçim ezilmez; yalnız boş/son otomatik/reddedilmiş değişir); başka kaynaklı künye "kaynak: başka kişi (TC ***1234)" rozetli ve `kunyeOncelikSirasi`'ndan DIŞLANIR (`k.kaynakBaska`, sunucudaki dışlamanın aynası). Sonuç penceresinde `#kaynakHataBilgi` (sıfat uyumsuz metni YOK). **v367 — bildirimci = KENDİ VKN'miz** (künyeyi biz doğurduk, ör. müstahsilden alım; canlı vaka 15.10.2026: 1204…133175 bildirimci ASYA FRESH) kaynak "bilinmiyor" DEĞİL, kaynak = kendimizdir: `hks_kunye_koken_haritasi` onu dışlamaz, plan çözümü atlar, `hks_kaynak_tc_engeli(..., $kendiVkn)` `kendi=true` ile durdurur; ekranda "kaynak: kendi bildiriminiz" rozeti (TC girilmeden de), dağıtım dışı, seçilince uyarı ve TC kendi VKN ile DOLDURULMAZ (`kaynakKendiMi`). Geri alma. Tek gönderim yolu değişmedi, yeniden deneme YOK. Test: `php scripts/hks_kunye_secim_test.php` · `php scripts/hks_depo_tasnif_kaynak_test.php` · `php scripts/hks_kunye_detay_test.php` · `node scripts/hks_depo_tasnif_kaynak_smoke.js`.
- **Satın Alım birim varsayılanı (v324):** `miktarBirimKgVarsayilan()` — Satın Alım/Üreticiden Sevk Alım'da `#sMiktarBirim` BOŞSA listeden ADA göre "Kg" seçer (id uydurmaz; elle/taslak değeri ezmez). Test: `node scripts/hks_kg_varsayilan_smoke.js`.
- **Taslak Kopyala (v368):** Taslaklar kartında **📋 Kopyala** (`data-kopyala`, sıra Gönder · Kopyala · Düzenle · Sil; <420px 2×2)
  → `taslakDuzenle(t, {kopya:true, toast:TASLAK_KOPYA_TOAST})` — Düzenle ile AYNI yükleme yolu, kopya kipi: `duzenlenenTaslakId=null`,
  kayıtta `eskiTaslakId` GİTMEZ, asıl taslak SİLİNMEZ, beyan bağı (`ortak.kaynak`) forma girmediği için kopyaya TAŞINMAZ. Yazma yine
  `taslak_kaydet` → `hks_taslak_olustur()`. Tekrar gönder metinleri (`KOPYA_TOAST`, "Önceki gönderimden") değişmedi.
  Test: `node scripts/hks_taslak_kopyala_smoke.js`.
- **İstemci akışı** (`gonderilenTekrar()`): tohum ÖNCE aktif firmayla istenir → başarılıysa ve firma
  değişiyorsa `firmaSec(hedef)` → `taslakDuzenle(tohum, {kopya:true, notlar})`. Kopya kipinde
  `duzenlenenTaslakId = null`: `eskiTaslakId` GİTMEZ, hiçbir taslak SİLİNMEZ. Karşı taraf yine
  "Doğrula"dan geçer. Menü `#gTekrarMenu` (position:fixed, z 620), firma penceresi `#firmaSecPencere` (z 600,
  aktif firma hariç). Lib `taslak_lib.php`'yi require ETMEZ (test edilebilirlik; api.php yükler).
- Test: `php scripts/hks_tekrar_gonder_smoke.php` · `node scripts/hks_gonderilenler_smoke.js`
  (`GSHOT=<klasör>` ekran görüntüsü kaydeder).

## Siparişler — Sevkiyat Takibi (v371)

Müşteri PDF'leri (PO / Loading List / Price Confirmation) yüklenir, içerikten sınıflanır ve
**sevkiyata** (= 1 order_ref = 1 tır) bağlanır; Packing List (PKL) müşterinin KENDİ şablonundan üretilir.
**Kapı TEK kaynak `can_siparis()`** (helpers.php, `can_mail` yanında, `rol_kapilari_smoke` eval bölgesinde): sidebar,
topnav, bottomnav, `first_allowed_page()`, index kartı ve sayfalardaki `require_siparis()` aynı fonksiyonu çağırır.
Yetkiler: `siparis.read/write/delete/match` (eşleştirme ayrıca `can_beyan('read')` ister); başka modülün yetkisinden
sessiz köprü YOK. Sayfa `require_siparis('read');` satırı birebir durur (bottomnav kapı iğnesi).

- **Şema yalnız migrate.php kartından** (`siparis_migrate()`, 10 tablo, ALTER/FK YOK, GO kuralı; kart `#siparis`).
  Tablo yoksa sayfalar "kurulmadı" kutusu gösterir. `db.php`/helpers IIFE'ye EKLEME. Depo adı değişince
  `sync_depot_name_in_data()` `siparis_sevkiyatlar.depo` + `siparis_belgeler.depo`'yu da çeker.
- **Ayrıştırıcı saf PHP** (`config/siparis_pdf.php`, `strict_types` YOK — gevşek tip dönüşümüne
  dayanır). Belge türü DOSYA ADINDAN DEĞİL içerikten. Ham sonuç `siparis_belgeler.parse_json`.
- **Elle girilen değer asla ezilmez:** `elle_alanlar` kilidi; sonraki belge yalnız
  `siparis_catismalar` satırı açar. Otorite: plan (PO) < gerçek (LL: araç/plaka/navlun/yükleme; PC:
  fiyat/boşaltma). Miktarlar kaynak başına ayrı kolonda (po/ll/pc); etkin = elle > PC > LL > PO;
  kalibre bölümü farklıysa `split_differs` uyarısı — "doğru" kaynak kullanıcı kararıdır.
- **Eski revizyon** (aynı tür, daha eski belge tarihi) bağlanır ama `gecerli=0`, değer uygulamaz.
- **PKL = doğrudan XML yamalama** (`config/siparis_pkl.php`, `templates/excel/pkl_v14_blank.xlsx`).
  PhpSpreadsheet ile PKL ÜRETME (doğrulamaları/stilleri bozar, 14 sn/320 MB) — dosyada `PhpOffice` YOK (test denetler).
  Şablonu Excel'de açıp kaydetme; değişirse `siparis_pkl_smoke` stil karşılaştırması kırılır. Dosya adı
  `PKL-<order_ref>-<AsyaDocNNN>-<gg.aa.yyyy (C5)>.xlsx`, sonda `_` yok.
- **Sayaçlar sezonluk** (sezon = düz yıl dizgisi, ör. `'2026'`; Temmuz'da döner — `siparis_sezon()`; sayaç adları
  `palet_<yıl>`, `belge_no_<yıl>`): numara PKL ilk üretildiğinde `FOR UPDATE` ile ayrılır, yeniden üretim aynı aralığı
  kullanır. Düzeltme yalnız Ayarlar'dan (`siparis.delete`, audit `siparis_sayac_duzelt`). Lot = "L " + ISO hafta + " - " +
  ISO gün (paketleme tarihi); CW'den TÜRETME.
- **"Mail gönderildi" ELLE bayraktır** (uygulama ekli mail göndermez); PKL yoksa basılamaz, kısmi
  PKL'de ek onay ister. **v400'den beri süreci KAPATMAZ** — sevkiyat "📦 Evrak takımı bekleyen" bölümüne geçer; süreci kapatan son adım (7.) ELLE işaretlenen "Evrak takımı" (`evrak_gonderildi`) adımıdır (aşağıda v400).
- **Dosyalar** `storage/siparis/` (web kapalı, `.htaccess` git'te, `.gitignore` `storage/siparis/*`), yalnız
  `siparis_dosya.php` / `siparis_zip.php` sunar (depo + yetki, nosniff, no-store). sw.js `siparis*.php`'yi HİÇ
  önbelleğe almaz (`siparis.css/js` SHELL'de de YOK, mail emsali). Gerçek örnek belgeler kişisel veri içerir — repoya
  KOYMA; testler `SIPARIS_ORNEK_DIR` yoksa atlar, commit'li fixture'lar sentetiktir.
- **Beyan bağı** yalnız `siparis_beyan` tablosuna yazılır; `customs_declarations`'a ve yükleme
  planına DOKUNMAZ (beyan_eslestir.php yan etkilerini tekrarlama).
- **Gezinme:** nav anahtarı `siparis` (sidebar Operasyon, `nav_alt_sayfalar` mail'den sonra, renk #b45309, ikon
  `assets/nav-icons/siparis.svg`); `nav_aktif_anahtar()` `$a_sip` listesine yeni sipariş sayfası eklersen oraya yaz.
- **v372 — liste + PKL ergonomisi (migration YOK, yeni yetki YOK):** Liste: Araç sütununda plakanın altında ince `Navlun <no>`; her sevkiyatın altında PO kalemleri alt satırı (`.sip-urun-tr`, mobil `.sip-card-urun`: Article specification = aciklama · cesit · koli_icerik, altında "Pal. N · PF N"; etkin palet > po palet; en çok 3 satır + "+N daha"); aktif beyan bağı varsa "Tur …" yerine **"Parti <party_no>"** (v386'dan beri sipariş no'nun üstünde rozet) (`siparis_beyan_parti_haritasi()`, TEK sorgu, `can_beyan('read')` yoksa/tablo yoksa Tur no'ya düşer, `beyan_redact_fields()`'ten geçer). **v373:** bağlı beyan BİRDEN ÇOKSA parti no'ların HEPSİ alt alta yazılır (`.sip-parti-satir`, sayı sınırı/"+N" kısaltması YOK; aynı parti no iki beyanda geçerse tek satır; `siparis_beyan_parti_haritasi()` yanıtında `liste`). Liste partial'ı sorgu ÇALIŞTIRMAZ (smoke denetler). PKL "Satır bazında düzenle": Brüt/Net hücresine Excel'den çok satırlı yapıştırma (1 sütun ya da Brüt|Net; hep-ya-hiç doğrulama; `812.5` gibi nokta-ondalık REDDEDİLİR çünkü `num()` noktayı binlik sayar), "📋 Excelden yapıştır" penceresi (`#sipYapistirModal`), Enter/↓ alt satır, Shift+Enter/↑ üst satır (form ASLA gönderilmez), canlı Σ Brüt/Net. **Palet ID:** 1. satır TEK `palet_bas_istenen` girdisidir, değişince alttakiler canlı +1 artar; öneri değerinde bırakılırsa SABİTLENMEZ (`palet_bas_varsayilan` karşılaştırması — aksi halde başka PKL o numarayı alınca taslak çakışma hatası verirdi). Boşluk/çakışma/geride UYARISI engellemez (`siparis_pkl_palet_uyarilari*()`, JS aynası `assets/siparis.js` — **ikisini birlikte değiştir**); `siparis_sayac_rezerve` üretimde çakışmayı HÂLÂ reddeder. Üretilmiş (`uretildi`) PKL'de başlangıç salt okunur (aralık yeniden üretimde korunur; düzeltme yalnız Ayarlar sayaç).
- **v374 — tek LL'de birden çok tır:** bir Loading List birden çok `Transport` bloğu taşıyabilir (tır başına navlun/nakliyeci/plaka/additional info). Ayrıştırıcı (`SIPARIS_PDF_SURUM` v3) her bloğu ayrı okur: `header.transports[]`, satır ve sevkiyatlarda `transport_no/transport_company/plates/additional_info`; başlık uyumluluk için İLK bloğu taşır. `siparis_ll_uygula()` taşıma bilgisini SEVKİYATIN kendi bloğundan alır (eski parse_json'da başlığa düşer) — başlığa geri DÖNME (eskiden son blok tüm tırlara yazılıyordu). "Transport/Additional/company/info" etiket satırları önceki satıra YAPIŞMAZ (sahte IAN/tur no doğuyordu); IAN ilk `\d{4,}`, tur no ilk `\d{6,}` belirteci. Aynı order ref belgede iki kez gelirse TEK birimde uygulanır. **Aynı belge yeniden işlenince** (`↻ Yeniden işle`) bu belgenin önceki okumasının yazıp artık gelmeyen nakliyeci/navlun/plaka değeri geri alınır (`siparis_alan_belge_geri_al`, elle kilide dokunmaz) ve bu belgeden kalan görülmeyen kalemin miktarı silinir; başka kaynakta miktarı / elle değeri / PKL kullanımı yoksa kalem kaldırılır (`siparis_kalem_belge_temizle`).
- **v375 — eski okuyucuyla okunmuş belge aynı dosya yeniden yüklenince kendiliğinden yeniden okunur:** sha256 tekilleştirmesi "Zaten yüklü" deyip `siparis_belge_isle`'yi atlıyordu; parser sürümü değişince eski okumanın sevkiyat değerleri (navlun/nakliyeci, bayat uyarılar) kalıyordu. Artık `siparis_yukle.php` aynı baytları yüklenen, `parser_surum ≠ SIPARIS_PDF_SURUM` ve `durum='islendi'` belgeyi (`siparis_belge_surum_eski()`; PKL/görselde `parser_surum` NULL → asla eski sayılmaz) AYNI belge id'siyle yeniden işler — ikinci `siparis_belgeler` satırı AÇILMAZ; depo kapısı `siparis_belge_gorunur()` (görünmüyorsa eskisi gibi "Zaten yüklü"); audit `siparis_belge_yukle` `islem=surum_yenile` + `eski_surum`; sonuç tablosunda "yeni okuyucuyla yeniden okundu" notu. `siparis_alan_uygula()`: bir belgenin aynı kaynaktan açtığı, yeniden okumada artık gelmeyen değerli AÇIK çatışma silinir (`siparis_catisma_bayat_sil`; yalnız `uygulandi`/`ayni` dallarında — elle-kilit, çatışma ve `saklandi` dallarında ve kabul/red kayıtlarında DOKUNMAZ).
- **v376 — liste görünümü (yalnız CSS/PHP, migration YOK):** sütun başlıkları **Lojistik** (eski "Araç") ve **Palet/Kasa** (eski "Palet / Koli"; mobil kartta "kasa"). Yükleme tarihi `sipui_tarih_html()` ile bugüne göre renkli: geçmiş → yeşil + kalın (`.sip-tar--gecmis`), bugün → turuncu + kalın (`.sip-tar--bugun`), henüz gelmemiş → normal metin rengi (`.sip-tar--gelecek`); `title` durumu da yazar, koyu temada açık tonlar. Ürün bilgileri (Article specification + Pal./PF) satırın altında kendi alt kartıdır: "Ürün bilgileri" başlığı, mavi vurgu şeridi, her ürün ayrı küçük kutu, Pal./PF hap rozet — işaretleme (`<td colspan="8"><div class="sip-urunler">`, `.sip-urun-meta` metni "Pal. N · PF N") DEĞİŞMEDİ, yalnız `assets/siparis.css` "LİSTE v376" bloğu. Test: `siparis_ui_smoke.php` (v376 satırları) · `siparis_ui_smoke.js`.
- **v377 — Şoför adı + telefonu (sevkiyat, elle not):** Sevkiyat bilgileri › "Araç ve navlun" grubunda plakaların altında. `siparis_sevkiyatlar.sofor_adi VARCHAR(100)` + `sofor_telefon VARCHAR(40)` (2 nullable kolon, **yalnız ADD COLUMN**). Şema `siparis_tablolar()`/`siparis_migrate()`'e EKLENMEZ ("ALTER yok" değişmezi) — kendi çifti `siparis_sofor_migrate()` / `siparis_sofor_hazir()` (config/siparis_sofor.php, siparis_core.php sonunda yüklenir — core dosyasında ALTER bulunmaz, `siparis_gate_static_smoke` denetler), kurulum yalnız migrate.php "Siparişler — Şoför alanları" kartı (`#siparis-sofor`, admin basar; GO'suz çalışmaz). Kolonlar yoksa alanlar GİZLİ, yönetici sevkiyat sayfasında kurulum notu görür, `siparis_kaydet.php` şoför yazımını "şema güncellemesi gerekli" diye reddeder; hiçbir sayfa kırılmaz. Alanlar YALNIZ-ELLE (`otorite []`, notlar gibi): belge kaynağı yok, ileride bir belge değer getirirse `kaynaklar` altında saklanır ama elle değeri ezmez/çatışma açmaz; Loading List yeniden okunsa da silinmez. Bu alanlarda "Elle/🔒" rozeti ve "Kilidi kaldır" GÖSTERİLMEZ (kilidi kaldırmak değeri silerdi; `alanDuzenleVeri` `kilitli` yalnız otoriteli alanda). Telefon tipi `telefon`: yalnız rakam ve `+ ( ) - .` boşluk, 7–15 hane, aksi "Telefon 7–15 haneli olmalı."; boş değer = temizle. Audit yalnız alan adı yazar (ad/telefon yazılmaz). Listeye/PKL'ye/arama kapsamına EKLENMEDİ. Test: `siparis_core_smoke.php` (bölüm 17) · `siparis_ui_smoke.php` (9z).
- **v378 — Mailden al (Sipariş / Lojistik / Fiyat sorgula; ŞEMASIZ, migration YOK):** `siparisler.php` `#yukle` kartında "📧 Mailden al": üç düğme = **gönderen filtresi + beklenen içerik türü** (`siparis`→PO, `lojistik`→LL, `fiyat`→PC); kesin tür her zaman PDF İÇERİĞİNDEN (Sipariş ve Fiyat aynı gönderenden gelir: Sipariş düğmesi PC'yi `baska_tur` diye atlar, defter türü önbelleğe alır, doğru düğmede alınır). Tek tık = tara + içe aktar (onay/önizleme adımı YOK); son 15 gün (`SIPARIS_MAIL_GUN`, sabit), DB'deki mailler (cron doldurur — cron durmuşsa ekranda "en son N dk önce senkronlandı" uyarısı çıkar, çözüm değil). Uç **`siparis_mail.php`** (POST JSON `baslat`/`devam`/`vazgec`, CSRF, `require_siparis('write')` + `siparis_mail_yetkisi()` = `can_siparis('write') && can_mail('read')`; adı TEK alt çizgili ek KALMALI — sw.js bypass regex'i `/siparis(ler|_[a-z]+)?\.php/` `siparis_mail_tara.php`'yi KAPSAMAZ). Mantık `config/siparis_mail.php`; parçalı yürütme (adım ≤ 20 sn / ≤ 6 ek, tarama ≤ 100 ek / 300 mail, `mail_kilit_al('siparis_mail')` + 120 sn kira, `assets/siparis.js` döngüsü, ağ kopunca "Devam et" aynı `tarama_id`).
  - **TEK yazma yolu `siparis_dosya_yukle_isle()`** (`config/siparis_eslestir.php`; `siparis_yukle.php` döngüsü oraya taşındı, davranış birebir) — yeni yükleme yolu AÇMA. `config/siparis_mail.php` `siparis_dosya_kaydet`/`siparis_belge_isle`'yi doğrudan çağırmaz; `siparis_dosya_kaydet(..., $kaynak)` beyaz liste `['yukleme','mail']`; `siparis_gate_static_smoke` `$MUT` listesine yeni ad EKLE (yoksa o dosyanın CSRF denetimi sessizce düşer). `siparis_pdf_tur_kodu()` tür eşlemesinin tek yeri.
  - **Mail yolu silinmiş belgeyi geri GETİRMEZ** (`silinmis` durumu; elle yükleme getirir) ve tanınmayan/başka tür/okunamayan PDF için **belge satırı AÇMAZ**. Adsız PDF parçasına `.pdf` eklenir; `inline` PDF de alınır; `message/rfc822` ("ek olarak iletilmiş") bu sürümde açılmaz → `iletilmis` satırı; kesik mail → `ek_eksik`.
  - **Eşleşme yalnız `mail_messages.from_addr` TAM adres** (alan adı / Reply-To YOK). Outlook'ta "X adına Y" görünen göndericide iki adres de listeye girer (ayar kartında yazar). Posta kutusuna YAZMA yok: IMAP'a yalnız `mail_ek_hazirla()` (EXAMINE + BODY.PEEK) ile gidilir, `is_read`/`\Seen` değişmez. YENİ gelen-kutusu sorgusu `mail_gonderilen_disla_sql()` içermeli (`siparis_mail_adaylar`).
  - **Ayar `hks_kv` anahtar `siparis_mail_ayar`** (posta kutusu id'leri + tür başına ≤ 10 gönderen; `siparis_ayarlar.php` › "📧 Mail sorgu ayarları", kapı `siparis.delete` + `mail.admin`, audit yalnız SAYILAR) — **adresler repoya GİRMEZ**; statik test kaynakta e-posta literali arar (yalnız `@ornek.test`). Çalışan tarama `siparis_mail_tarama`, son sonuç `siparis_mail_son_<tur>` (aynı tabloda); `hks_kv` yoksa/mail şeması yoksa düğmeler HİÇ çizilmez. **Defter = audit `siparis_mail_ek`** (module `siparis_mail`, record_id = mail id; kararlar: alindi/zaten/silinmis/gecersiz son karar, taninmadi yalnız aynı ayrıştırıcı sürümüyle, baska_tur yalnız farklı düğmede, hata YAZILMAZ → yeniden denenir); belge kökeni `siparis_belgeler.kaynak='mail'`, olay metni "(mailden)", rozet "📧 mailden". Önceki sonuçta atlanan satırlar (silinmis/gecersiz/taninmadi/baska_tur) tekrar indirilmeden durumuyla gösterilir; yalnız alindi/zaten "Daha önce alınmış" (varsayılan gizli).
  - **v379 — düzeltmeler:** (1) `siparisler.php` sonuç kartı fatal veriyordu (`mail_mime_mojibake_duzelt` tanımsız): `config/siparis_mail.php` artık `mail_mime.php`'yi de yükler — `mail_view.php` tek başına yüklenirse bu yardımcıyı bulamaz; smoke bunu AYRI süreçte denetler (harness hepsini yüklediği için fatal'ı göremezdi). (2) Sunucuda artık olmayan ek (`mail_ek_hazirla` 404) defterde `ek_yok` KALICI karar olur, her sorguda IMAP'a gidilmez; 409 (yeniden tarama sürüyor) / 502 / 503 geçicidir, defter YOK, yeniden denenir.
  - **v380 — uyarı içeriği:** mail sonuç satırındaki "Uyarı N" sayısının metni `<details class="sip-ms-uyari">` ile satırda açılır (`sipui_belge_uyari_metinleri()` = belge `parse_json.warnings`, yalnız warn/error — sayıyla AYNI süzgeç; yalnız görünür belge; `sipui_mail_uyari_detay_html()`), "Belgede aç" → `siparis_belge.php?id=…#sipBKontrol`. Kullanıcı verisi h() ile kaçırılır.
  - **v381 — Fiyat sorgula KALDIRILDI (sahip kararı; PO + PC aynı gönderenden gelir):** düğmeler yalnız **Sipariş** + **Lojistik**. `siparis_mail_turleri()` her türün `belgeler` listesi vardır (`siparis` = `['po','pc']`, `lojistik` = `['ll']`); PC, PO ile aynı yoldan alınır (içerikten tür, aynı `siparis_dosya_yukle_isle()`, order_ref eşleşmesi — ayrı eşleme kodu YOK). `siparis_mail_defter_son_mu($d, list $beklenenler)`: `baska_tur` yalnız defterdeki tür düğmenin aldığı türlerden BİRİ DEĞİLSE son karardır (v378-v380'de `baska_tur/pc` yazılmış PC'ler Sipariş taramasında bir kez yeniden indirilip alınır). Ayar `gonderen` yalnız `siparis` + `lojistik`; eski KV'deki `fiyat` listesi `siparis_mail_ayar_oku()` ile Sipariş listesine KATILIR (kaydedilince düşer). Yeni belge türü eklersen yalnız o düğmenin `belgeler` listesine yaz.
  - **v382 — Yükleme kartı açılır/kapanır + "Açık Sevkiyatlar" yazdırma / PDF (migration YOK, yeni yetki YOK, şema YOK):**
    - **Kart** (`siparisler.php`): native `<details class="card sip-yukle" id="yukle" data-sip-yukle data-sip-yukle-uid>` + `<summary class="sip-yukle-ozet">`; mail bloğu + yükleme formu DEĞİŞMEDEN `.sip-yukle-govde`'ye taşındı (siparis.js `basla()` bağlamaları aynen). Varsayılan KAPALI; sunucu `open` yazar YALNIZ yarım kalan mail taraması varsa (`$mD['yarim']` — "Devam et" şeridi gizlenmesin). Öncelik: sunucu `open` > URL hash (`#yukle` / `#mail`, yüklemede + `hashchange`'de; `siparis_yukle.php` hata dönüşü `siparisler.php#yukle` bu yüzden açık gelir) > localStorage `asya_sip_yukle_<uid>` `'1'` > kapalı. localStorage YALNIZ kullanıcı summary'ye TIKLADIĞINDA yazılır (`click`, `toggle` değil — hash/sunucu açılışı tercihi ezmez); `'0'` sunucu `open`'ı kapatmaz. Tarama sürerken (`[data-sip-mail-kap][aria-busy="true"]`) summary tıkı kartı KAPATMAZ ("Tarama sürüyor" notu 2 sn). Summary'de son sorgu özeti `sipui_yukle_ozet_html()` (`$mSon[]`'a `sayac` eklendi; "Son sorgu: Sipariş · gg.aa ss:dd · N alındı[ · K hata]", yarımda "⏸ Yarım tarama var"). Summary içinde `<h2>` YOK; seçici YALNIZ `details[data-sip-yukle] > summary.sip-yukle-ozet` (içte `details.sip-mail-onceki` / `details.sip-ms-uyari` var — genel `summary` seçici YASAK). Test: kartın İÇİNİ sınayan senaryolar `ac(..., { yukleAcik: true })` ile localStorage'ı önceden yazar; görünürlük `checkVisibility()` ile (kapalı `<details>` içeriği `getBoundingClientRect` ile boyut döndürebilir).
    - **Yazdırma** (`siparisler.php` Açık bölüm başlığı + tek durum "Sonuçlar" başlığı; satır varsa; Kapananda YOK; izleyiciye de açık): `🖨️ Yazdır` + `📤 PDF Gönder` (masaüstünde JS "⬇️ PDF İndir"). Veri GET JSON ucu **`siparis_yazdir.php`** (ad TEK alt çizgili — sw.js bypass regex'i `/siparis(ler|_[a-z]+)?\.php/` kapsar; `siparis_acik_yazdir.php` gibi iki ekli ad kişisel veriyi SW önbelleğine yazar, YASAK) → `config/siparis_yazdir.php`; her tıkta TAZE okunur (istemcide önbellek yok, şoför adı/telefonu HTML listeye girmez). Kapı `require_siparis('read')` (+ GET dışı 405, `session_write_close`). Kapsam = ekrandaki filtre/arama: TEK ortak `siparis_liste_filtre()` (`config/siparis_core.php`; liste + uç aynı fonksiyonu çağırır, WHERE kopyası YOK; `siparis_url($override, $sayfa = 'siparisler.php')` 2. parametresi anahtar beyaz listesini uca taşır, `acik_page/kapali_page` taşınmaz). `durum=kapali` → 400 `kapsam_yok`; en çok `SIPARIS_YAZDIR_LIMIT` = 500 (`LIMIT 501` → `kesildi` notu). Sıra ekranla aynı (tarih, boşlar sonda, id).
    - **Sütunlar** (sıra sahibinindir, DEĞİŞTİRME; ekleme = YALNIZ `siparis_yazdir_kolonlari()` + `siparis_yazdir_veri()`): ÇIKIŞ TARİHİ · PARTİ NO · NAVLUN NO · SİPARİŞ NO · ŞOFÖR ADI · TEL · PLAKA · LOJİSTİK FİRMA · ÜLKE · ÜRÜN BİLGİSİ · PALET SAYISI · KASA SAYISI · GRAMAJ. Boş hücre BOŞ (tire YOK). Şoför kaynağı YALNIZ `s.sofor_adi/sofor_telefon` (v377; kolon yoksa `siparis_sofor_hazir()` false → sütunlar boş + not; kolonlar yalnız hazırsa SELECT'e girer); beyan `driver_*` KULLANILMAZ. Plaka / lojistik firma YALNIZ gerçek `plakalar` / `nakliyeci` — `planlanan_*` BASILMAZ. Parti no = `siparis_beyan_parti_haritasi()['liste']` "/" ile (Tur no'ya DÜŞÜLMEZ; `can_beyan('read')` yoksa boş + not). Ürün = `sipui_kalem_satirlari($k, 1000)` (kısaltma yok). Gramaj = aynı süzgeçten geçen kalemlerin tekil kalibreleri sayısal artan "10--12--14" (`siparis_yazdir_gramaj()` süzgeci `sipui_kalem_satirlari` ile AYNI koşulun kopyasıdır — birini değiştirirsen ötekini de; smoke denetler). **Ülke** = `alici_kodu` (PO destination id = Mal alıcı ID) → `siparis_yazdir_ulke_haritasi()` 12 kayıt (`docs/OGL_ULKE_REHBERI.md`; YENİ OGL ülkesi buraya bir satır); yoksa `alici_adi` sonundaki ISO kodu ("LIDL RS"); aksi boş. Beyan ülkesi / PO dosya adı KULLANILMAZ. Ad Türkçe, doğru büyük harfle (`text-transform` YOK).
    - **Görünüm:** `yukleme_tarihi < bugün` → satır sarı (`#fff3b0`) + tarihin sonunda " ✓" (renksiz yazıcıda ✓ taşır; ekrandaki v376 yeşil yalnız ekran içindir); bugün → tarih kalın; gün değişince üst çizgi; TOPLAM satırı `<tbody>` SONUNDA (`<tfoot>` baskıda her sayfada tekrar eder). Yazdır = yeni pencere (`window.open` fetch'ten ÖNCE ve eşzamanlı — popup engelleyici) + `@page A4 landscape` + `print()`; PDF = html2canvas + jsPDF (halkayit/ dosyaları, yalnız ilk basışta), sabit 1200px tutucu, satır sınırında dilimleme + 2. sayfadan başlık tekrarı + "Sayfa i / n"; telefonda Web Share, masaüstünde indirme. Çıktı altında "Kişisel veri içerir" notu. Audit `siparis_yazdir` (yalnız adet/amac/kapsam/kesildi/arama BAYRAĞI — ad/telefon/arama metni YAZILMAZ).
    - **`assets/siparis_yazdir.js` `pdfUret()` dilimleme algoritması `assets/beyan_yazdir.js` ile AYNIDIR (bilinçli ince kopya: beyan sürümü gömülü JSON + tfoot'a bağlı, v357 testleri dosya yolunu sabitliyor)** — birinde hata düzeltirsen ötekinde de düzelt.
    - Test: `php scripts/siparis_yazdir_smoke.php` (bellek içi SQLite; ortak filtre eski WHERE ile birebir, ülke/gramaj/sarı/sıra/limit/toplam, uç JSON + 405 + audit + izolasyon; `--json` / `--json-buyuk` tarayıcı fixture'ı) · `node scripts/siparis_yazdir_smoke.js` (Playwright; render'ı kendisi üretir: düğme yerleri, popup 13 sütun + sarı satır + ✓ + toplam + A4 yatay + print, XSS kaçışı, hata yanıtları, gerçek PDF indirme/çok sayfa/sayfa no, telefonda Web Share + izin reddi) · `siparis_ui_smoke.php/.js` (açılır kart, düğme yerleri) · `siparis_gate_static_smoke.php`.
  - **v383 — PKL mail taslağı (Mail gönderildi penceresi; migration YOK):** Kime/Bilgi/konu/gövde TEK yerde `siparis_pkl_mail_taslak()` (config/siparis_pkl.php; PO "Packing List per E-Mail to:" adresleri `siparis_po_pkl_epostalar()`): `packinglist…` ile başlayan adres **Bilgi (CC)**, diğerleri **Kime** (Kime boş kalırsa hepsi Kime); konu = PKL dosya adı uzantısız; gövde sabit İngilizce kalıp + "Asya Fresh" + oturumdaki kullanıcının adı. İki çıktı AYNI taslaktan: (1) **✉ Mail programını aç** = `siparis_pkl_mailto()` — mailto standardı **EK TAŞIYAMAZ** (PKL elle eklenir); (2) **📎 Ekli mail taslağını indir** = `siparis_dosya.php?id=<PKL belge>&eml=1&sid=<sevkiyat>` → `siparis_pkl_eml()`: `X-Unsent: 1` + To/Cc/Subject + PKL eki (base64), From YOK (Outlook varsayılan hesabı kullanır); klasik Outlook gönderilmeye hazır taslak açar. Uç: belge sevkiyatın GÜNCEL PKL'i değilse 404, sevkiyat depo kapısı (`siparis_gorunur`), PO'da adres yoksa 409, audit `siparis_pkl_eml` (yalnız alıcı SAYISI). Konu/imzadaki satır sonları atılır (başlık enjeksiyonu yok). Test: `php scripts/siparis_pkl_mail_smoke.php`.
  - **v384 — liste dönüşümlü bant (Excel gibi; yalnız PHP sınıf + CSS):** `_siparis_liste.php` her bölümde sevkiyat sırasına göre `sip-grup sip-z0|sip-z1` verir — ana satır VE ürün alt satırı (`sip-urun-tr`) AYNI sınıfı taşır (iki `<tr>`'li grupta `nth-child` ÇALIŞMAZ, CSS'e çevirme); mobil kart `sip-card sip-z0|1`. Renkler `.sip-mod` `--sip-zebra` / `--sip-grup-cizgi` (açık + koyu), hover `--primary-soft` bandı ezer; z1 bantta ürün alt kartı `var(--card)` zemin. `assets/siparis.css` "LİSTE v384" bloğu. Test: `siparis_ui_smoke.php` (zebra satırları) · `siparis_ui_smoke.js` (ardışık grup farklı, grup içi aynı zemin).
  - **v385 — yazdırma ucu düzeltmesi:** `config/siparis_yazdir.php`'nin doğrudan-istek koruması `basename` karşılaştırıyordu; uç da kökte `siparis_yazdir.php` adını taşıdığı için koruma UÇTA tetiklenip boş 404 dönüyordu (canlıda "Oturum sona ermiş olabilir"). Artık `realpath(SCRIPT_FILENAME) === __FILE__`. **Kural:** basename korumalı include-only dosyaya başka bir sayfayla AYNI ad verme (ya da tam yol karşılaştır) — `siparis_yazdir_smoke.php` 7b çakışmayı tüm kök/config/halkayit dosyalarında arar ve ucu AYRI süreçte (gerçek SCRIPT_FILENAME ile) sınar; harness SCRIPT_FILENAME ayarlamadığı için bunu göremiyordu. İstemci HTML yanıtta yalnız 200/yönlendirmede "oturum" der, diğer kodlarda "HTTP N".
  - **v386 — parti rozeti + Gümrük sütunu (migration YOK):** bağlı beyanların parti no'ları sipariş no'nun ÜSTÜNDE 3B düğme görünümlü rozet (`sipui_parti_rozet_html()` → `.sip-parti-rozetler > .sip-parti-btn`, tıklanmaz; tablo + mobil kart); Tur no YALNIZ parti yoksa altında (`sipui_tur_html()`; eski `sipui_tur_parti_html` ve v373 `.sip-parti-satir` KALKTI). Listeye **Gümrük** sütunu (`s.gumruk`, PO "Customs"; Lojistik'ten sonra; yazılıysa görünür, boşsa hücre boş; mobil kartta "Gümrük: …"); ürün alt satırı `colspan="9"`. 1280'de tablo kaymasın diye `.sip-table` hücre yan boşluğu 9px ve Belgeler rozetleri 2×2 (`inline-grid`). Yazdırma sütunları DEĞİŞMEDİ. Test: `siparis_ui_smoke.php` (gümrük + v386 satırları) · `siparis_ui_smoke.js`.
  - **v387 — Yurt içi gümrük (1 nullable ADD COLUMN, kurulum migrate.php kartı):** PO "Customs" (`gumruk`) YURT DIŞI (sınır) gümrüğüdür — etiketi "Yurt dışı gümrük". Yeni `siparis_sevkiyatlar.yurtici_gumruk VARCHAR(150)` yalnız-elle alan (`otorite []`, belge yazmaz), sevkiyat sayfası Gümrük grubunda yurt dışının üstünde. Şema v377 şoför emsali: kendi çifti `siparis_yurtici_gumruk_migrate()`/`_hazir()` (`config/siparis_yurtici_gumruk.php`, siparis_core.php sonunda yüklenir; `siparis_tablolar()`/`siparis_migrate()`'e EKLENMEZ), kart `migrate.php#siparis-yurtici-gumruk`. Kolon yoksa alan gizli + yöneticiye kurulum notu, `siparis_kaydet.php` yazımı reddeder, liste kolonu SELECT'e girmez. **Liste (v386 Gümrük sütunu) artık YALNIZ yurt içi gümrüğü gösterir** (başlık "Yurt içi gümrük", mobil "Yurt içi gümrük: …"); yurt dışı gümrük listede YOK. Yazdırma sütunları DEĞİŞMEDİ. Test: `siparis_ui_smoke.php` (v387 satırları).
  - **v390 — Açık / Kapanan AYRI kartlar (yalnız PHP + CSS, migration YOK):** `siparisler.php` iki bölümü Beyanlar'daki gibi ayrı kart yapar: **⏳ İşlem Bekleyen Sevkiyatlar** (`section.sip-bolum.sip-bolum-acik`, turuncu sol şerit + dolgun sayaç) ve **✅ Kapanan Sevkiyatlar** (`.sip-bolum-kapali`, yeşil şerit; arada boşluk). Başlık div'i `#sipAcik/#sipKapali/#sipSonuclar` + yazdırma düğmeleri (yalnız bekleyen kartın başlığında) DEĞİŞMEDİ; `$bolumBas`'a ikon + not parametresi eklendi. Tek durum filtresinde "Sonuçlar" bölümü eskisi gibi düz. `assets/siparis.css` "v390" bloğu. Test: `siparis_ui_smoke.php` (v390 satırı) · `node scripts/siparis_yazdir_smoke.js`.
  - **v394 — "💬 WP Paylaş" (⋮ menüsü; migration YOK, yeni yetki YOK):** liste satırı + mobil kart menüsünde (Aç / Evrak ZIP / PKL altında, KAPANAN dahil) `<button data-sip-wp="<id>">`. Tıkta `siparis_paylas.php?id=` (GET JSON, `require_siparis('read')`, no-store, 405, ad TEK alt çizgili — sw bypass) → `config/siparis_wp.php` `siparis_wp_oku()` (tek sevkiyat, `depo_sql_column` + `siparis_gorunur`; silinmiş/yok/başka depo = aynı 404; kapalı/iptal 200; şoför/yurt içi gümrük kolonları yalnız `*_hazir()` ise SELECT'te) + saf `siparis_wp_veri()`. Alan sırası SABİT (`siparis_wp_alanlari()`): Parti No · Yükleme tarihi · Sipariş No · Navlun No · Nakliyeci · Plakalar · Şoför adı · Şoför telefonu · Yurt içi gümrük, ardından kalem tablosu Ürün · Kalibre · Palet/Koli + Toplam (sevkiyat toplamı). Boş = "—"; parti no'lar " / " ile, Tur no'ya DÜŞÜLMEZ, `can_beyan('read')` yoksa "—"; nakliyeci/plaka yalnız gerçek alan (planlı değer yok). Kalem süzgeci TEK yer `sipui_kalem_coz()` (`_siparis_ortak.php`; liste, yazdırma gramajı ve WP aynı fonksiyonu çağırır — kopyalama). İstemci `assets/siparis_wp.js` (sw SHELL'de değil): ekran dışı 440px BEYAZ kart (başlık 700, veri 300; temadan bağımsız) → html2canvas (halkayit/, ilk basışta) → PNG (×2). Telefon: Web Share (iOS `NotAllowedError` → bildirimde "📤 Paylaş"); masaüstü: `ClipboardItem` ile panoya PNG ("Panoya kopyalandı — WhatsApp'ta yapıştırın"), olmazsa PNG indir. Durum/eylemler sayfa düzeyi bildirimde (`.sip-wp-durum`, z 650; menü tıkta kapanır). Kişisel veri liste HTML'ine/`data-*`'ya/dosya adına (`WP-<sipariş no>.png`) GİRMEZ; audit `siparis_wp_paylas` yalnız `{kalem: N}`. Test: `siparis_yazdir_smoke.php` (§6b, §11) · `siparis_gate_static_smoke.php` (v394) · `siparis_ui_smoke.php` (v394 satırları) · `node scripts/siparis_yazdir_smoke.js` (WP bölümü).
  - **v395 — Alıcı ülke (migration YOK):** PO alıcısı (`alici_kodu` = Mal alıcı ID, yoksa `alici_adi` sonundaki ISO kodu "LIDL RO") → Türkçe ülke adı TEK kaynak `siparis_yazdir_ulke()` / `siparis_yazdir_ulke_haritasi()` (config/siparis_yazdir.php; sahibin OGL Excel'i = `docs/OGL_ULKE_REHBERI.md`, 12 ülke). (1) WP Paylaş kartında "Yurt içi gümrük"ün ALTINDA **Alıcı Ülke** (`siparis_wp_alanlari()` k=`ulke`; config/siparis_wp.php siparis_yazdir.php'yi yükler); (2) liste tablosunda **PO grubu ile Yükleme arasında "Alıcı ülke"** sütunu (`td.sip-ulke-hucre`, ürün alt satırı `colspan="10"`), mobil kartta "Alıcı ülke: …" satırı; siparisler.php `config/siparis_yazdir.php`'yi yükler (partial fonksiyon tanımlamaz). Eşlenmeyen alıcı → liste boş, kart "—". Yeni ülke = haritaya bir satır. Test: `siparis_yazdir_smoke.php` (10 alan) · `siparis_ui_smoke.php` (v395 satırı) · `node scripts/siparis_yazdir_smoke.js`.
  - **v396 — PKL dosya adı listede (migration YOK):** sevkiyatın güncel PKL'i (`siparis_pkl.dosya_belge_id` + `dosya_adi` dolu — oluşturulan ya da yüklenen) varsa dosya adı sipariş no + parti rozetlerinin ÜSTÜNDE yeşil `📄 PKL-…xlsx` bağlantısı (`sipui_pkl_ad_html()`, `_siparis_ortak.php`; tık = `siparis_dosya.php?id=…&dl=1` indirme, kapılar o uçta). Harita `$pklHarita` siparisler.php'de TEK sorgu → partial'a `$sec_pkl` (partial sorgu ÇALIŞTIRMAZ). Ad kısaltılmaz: `-`/`_` sonrası `<wbr>` ile dar sütunda kırılır (1280'de tablo kaymaz). Mobil kartta PKL adı + parti rozetleri TEK `.sip-card-etiket` şeridinde (⋮ düğmesine binmez). Taslak (dosyasız) PKL'de görünmez. CSS `assets/siparis.css` "LİSTE v396". Test: `siparis_ui_smoke.php` (v396 satırları) · `siparis_ui_smoke.js` (konum/hücre içi/⋮).
  - **v397 — Sipariş Cari Dökümü (yazdırılabilir; migration YOK, yeni yetki YOK):** `siparisler.php` başlığında `📒 Cari Döküm` → **`siparis_cari.php`** (TEK alt çizgi, sw bypass; `require_siparis('read')` + `siparis_cari_yetkili()` = `can('reports.export')` — fiyat finansal veri, izleyici tohumunda yok; düğme aynı kapıyla; no-store). Mantık `config/siparis_cari.php` (salt SELECT, realpath koruması): yüklendi kuralı TEK yer `siparis_cari_yuklendi_mi()` (iptal hariç; yola_cikti/pkl_hazir/mail_gonderildi ∨ var_pkl ∨ tarih < bugün); kalem süzgeci `sipui_kalem_coz()`; koli = pc_koli>0 ? pc_koli : etkin_koli; koli kg `siparis_cari_koli_kg()` (yoksa 3,5 + "*"); `fiyat_birim` CAR/KOLI/BOX dışı → kg fiyatı. Satır tutarı kuruşa yuvarlanır, toplam = yuvarlanmış satırlar (tam sayı); para birimleri ASLA toplanmaz. Bölümler A yüklenmemiş · B yüklenen · C fiyatlı (yüklenmemiş fiyatlı dahil, işaretli) · D Cari Özet (Faturalanan/Tahsilat/Bakiye; tahsilat kancası `siparis_cari_tahsilat_toplam()` 2. etapta). Filtre GET `bas`/`bit` (varsayılan bas = 2 ay önceki ayın 1'i; tarihsiz hep dahil) + `alici` + aktif depo; en çok 1000 sevkiyat. A4 yatay, `print_pdks.css` + sayfa içi `sc-` CSS, tfoot YOK, satır içi onclick YOK. Audit `siparis_cari_goruntule` yalnız sayılar. Test: `php scripts/siparis_cari_smoke.php` · `siparis_gate_static_smoke.php` (v397).
  - **v398 — Cari Döküm düzeltmeleri:** kg fiyatı 2 ondalığa yarım yukarı yuvarlanır ve tutar YUVARLANMIŞ kg fiyatıyla hesaplanır (kg × kg fiyatı); B bölümünde "Fiyat durumu" yerine satır bazında Fiyat/kg; yüklenmiş = YALNIZ `mail_gonderildi` (tarih geçse de mail yoksa yüklenmemiş; v400'den beri `evrak_gonderildi` de yüklenmiş sayılır — evrak adımı mail'den SONRA gelir); imza alanı ve tahsilat notu kaldırıldı; yazdırmada `@page margin:0` (tarayıcı URL altbilgisi çıkmaz).
  - **v399 — OGL ödemeleri (genel cari tahsilat; sahip GO verdi — 1 yeni tablo):** `siparis_odemeler` (tarih, tutar_kurus, para_birimi, aciklama, dekont_no, `istek_id` UNIQUE, soft iptal + gerekçe; siparişe/sevkiyata BAĞLANMAZ). Şema yalnız migrate.php `#siparis-odeme` kartından (`config/siparis_odeme.php`, core'a eklenmez). Giriş/iptal TEK uç `siparis_odeme.php` (POST+CSRF, PRG → `siparis_odemeler.php` (v401)); kapı `siparis_cari_yetkili()` + `siparis_odeme_yetkili()` = admin ya da `hesap.pay` (muhasebe); satır silinmez. Cari Döküm'de "Ödemeler" bölümü; Tahsilat = dönem (bas/bit) içindeki aktif ödemeler, para birimi başına (`siparis_odeme_toplam`), Bakiye = Faturalanan − Tahsilat. Tablo yoksa Tahsilat 0, form gizli. Test: `siparis_cari_smoke.php` (§6).
  - **v400 — Evrak takımı adımı (7. adım; migration YOK, şema DEĞİŞMEZ — yalnız tek seferlik VERİ geçişi):** durum `evrak_gonderildi` ("📦 Evrak takımı", sıra 7, css `evrak`) `mail_gonderildi` ile `iptal` arasında; `siparis_ilerleme()` toplamı `siparis_durumlar()`'dan (7; 5/7=71 · 6/7=86 · 7/7=100). **Kapalı = `siparis_kapali_durumlar()` = `evrak_gonderildi` + `iptal`** ("Mail gönderildi" artık KAPALI DEĞİL); `siparis_evrak_bekleyen_durumlar()` = `mail_gonderildi`; `siparis_kapanmamis_sql()` = kapanmamış koşulu. **Kapalı/kapanmamış listesini ASLA sabit yazma** (`'mail_gonderildi','iptal'` gibi) — yalnız bu iki fonksiyon (gate static smoke kök/config PHP'de sabit arar). Geçişler: evrak YALNIZ `mail_gonderildi`'den ve ELLE (`siparis_durum_ilerlet()` mail/evrak/iptal'e HİÇ geçmez; "Evrak takımı gönderildi" düğmesi + `#sipEvrakModal` onay kutusu `onay_evrak` zorunlu, audit `siparis_evrak_gonderildi`); evrak → mail geri alma (⇄ Durumu değiştir, gerekçe ZORUNLU) PKL kontrolü yapmaz, `mail_gonderildi_at/_by` DEĞİŞMEZ, `onay_gonderdim` istenmez; mail → başka durum gerekçesiz (artık kapalı değil), mail → iptal gerekçeli. **Liste (`siparisler.php`) ÜÇ bölüm:** ⏳ İşlem Bekleyen (`#sipAcik`, `acikSql` = kapalı + evrak bekleyen HARİÇ) · 📦 Evrak Takımı Bekleyen (`#sipEvrak`, `.sip-bolum-evrak`, `evrak_page`, `evrakSql`) · ✅ Kapanan (`#sipKapali`); 8. KPI çipi "Evrak bekleyen" (`durum=evrak_bekleyen`, bölüm filtresi — acik/kapali gibi `detayFiltre` sayılmaz); başlıkta "N açık · E evrak bekleyen · M kapanan sevkiyat". **Yazdırma kapsamı:** `acikSql` mail'i içermediği için Açık yazdırma/PDF'e evrak bekleyenler GİRMEZ; Evrak bölümünde yazdırma düğmesi YOK, `durum=evrak_bekleyen` → `kapsam_yok`; `durum=mail_gonderildi` tek durum olarak yazdırılabilir. `siparis_belge.php` açık sevkiyat seçicisi `siparis_kapanmamis_sql()` kullanır. **Tek seferlik geçiş (`config/siparis_evrak.php`, yalnız migrate.php kartı `#siparis-evrak-gecis`):** v400 ÖNCESİ "Mail gönderildi" ile kapanmış (artık "evrak bekleyen" görünecek) sevkiyatlar `evrak_gonderildi`ye taşınır (sahip kararı: eski = kapalı). Ayrım tarihten DEĞİL, v400 akışının mail audit'ine yazdığı **`"evrak_akisi":1`** işaretinden (`siparis_mail_gonderildi` new_values; işareti olmayan `mail_gonderildi` = eski; tarihsiz/silinmiş eski satırlar da dahil). `durum_at` DEĞİŞMEZ; her sevkiyata zaman çizelgesi satırı ("Toplu geçiş (v400)…") + TEK özet audit `siparis_evrak_gecis`; idempotent, parti 1000. **Canlıya alınca test sitesinde VE canlıda ayrı ayrı bir kez bas** (basılmazsa eski kapanmışlar "Evrak bekleyen"de görünür). Geri alma: sevkiyatta ⇄ gerekçeyle. Listede yönetici ipucu YOK (ağır audit sorgusu). Test: `php scripts/siparis_core_smoke.php` (7b/7c) · `siparis_ui_smoke.php/.js` · `siparis_yazdir_smoke.php/.js` · `siparis_gate_static_smoke.php` · `migrate_panel_smoke.php` · `siparis_cari_smoke.php`.
  - **v401 — Ödeme girişi ayrı sayfada (migration YOK):** Cari Döküm'de ödeme formu/iptal YOK (yalnız salt okunur liste + Tahsilat/Bakiye). `siparisler.php` başlığındaki mavi "+ Belge yükle" (#yukle) → **"💳 Ödeme ekle"** (`siparis_cari_yetkili()` kapılı; hesap.pay yoksa etiket "💳 Ödemeler") → yeni **`siparis_odemeler.php`** (TEK alt çizgi; GET; `require_siparis('read')` + `siparis_cari_yetkili()`; no-store; mutasyon/fonksiyon yok): bas/bit filtreli (varsayılan tümü) aktif ödemeler + para birimi başına toplam; "➕ Ödeme gir" / satır "İptal" native `<dialog class="pm-dialog od-dialog">` (yalnız `siparis_odeme_yetkili()`; POST `siparis_odeme.php`, PRG artık buraya, qs korunur); "🖨 Ödemeleri yazdır" yalnız `#odYazdirAlan` tablosunu basar (`body.od-yazdir-modu` + @media print, afterprint temizler). Satır içi olay YOK: `data-od-ac/-kapat/-iptal/-yazdir` (assets/siparis.js), CSS `assets/siparis.css` ("ÖDEMELER v401"). "Belge yükle · Mailden al" kartı (#yukle) değişmedi (summary ile açılır). Test: `siparis_cari_smoke.php` (§6) · `siparis_gate_static_smoke.php` (v401) · `siparis_ui_smoke.php`.
  - Testler: `php scripts/siparis_mail_smoke.php` (birleşik harness `scripts/_siparis_mail_test_lib.php` + `scripts/_mail_test_sema.php`; sahte IMAP) · `siparis_gate_static_smoke.php` · `mail_gate_static_smoke.php` (`mail_ek_hazirla` çağıran kaydı) · `SIPARIS_UI_OUT=/tmp/sip php scripts/siparis_ui_render.php && node scripts/siparis_ui_smoke.js` (mail senaryoları `SIPARIS_UI_MAIL=ayarsiz,ayarli,yetkisiz,son,yarim`; `page.route()` sayfa AÇILMADAN kurulmalı). Faz 2 fikirleri (iletilmiş mail, "Önce senkronla", Authentication-Results rozeti) ve defter tablosu (yalnız açık GO) kapsam dışı.
Test: `php scripts/siparis_core_smoke.php` · `siparis_pkl_smoke.php` · `siparis_pdf_smoke.php`
(`SIPARIS_ORNEK_DIR=…` ile gerçek örnekler) · `siparis_gate_static_smoke.php` ·
`SIPARIS_UI_OUT=/tmp/sip php scripts/siparis_ui_render.php && node scripts/siparis_ui_smoke.js`.

### Şema Migrasyon Paneli görünümü (v388)

`migrate.php` kartları (21 adet) `mg_kart_ac('<id>')` / `mg_kart_kapat()` ile sarılır: kartın KENDİ çıktısında `✓` var ve `✗` yoksa kart **hazır** sayılır → kapalı `<details>` tek satıra iner (başlık + "✓ Hazır" rozeti); eksik olan AÇIK kalır. Üstte "N / 21 kart hazır" özeti + "Tümünü aç" düğmesi; `migrate.php#siparis-sofor` gibi bağlantılar kapalı kartı açar. **POST sonrası hiçbir kart kapanmaz** (migrasyon sonuç satırları görünsün). **Hiçbir kart / POST dalı / migrasyon fonksiyonu KALDIRILMADI** — ayrı veritabanında (ör. test sitesi) eksik kalan şema yine buradan kurulur; birçok statik smoke (`ne=` dalları, tek çağıran = migrate.php) bunu sabitler. Yeni kart eklerken `<div class="card" …>` yerine `<?php mg_kart_ac('id'); ?>` … `<?php mg_kart_kapat(); ?>` kullan ve kartın çıktısına durum için `✓`/`✗` bas (hazırsa yalnız `✓`). Test: `php scripts/migrate_panel_smoke.php` (migrate.php'yi bellek içi SQLite'ta gerçekten çalıştırır).

## Excel İndir — CSV + XLSX (Sprint Excel-01)

Dışa aktarım butonları tek bir **"⬇ Excel İndir ▾"** menüsüdür; tıklanınca
**CSV İndir** ve **XLSX İndir** seçenekleri açılır. Envanter ve gerekçe:
`@docs/EXCEL_EXPORT_ANALIZ.md`.

**Dosyalar:** `config/xlsx_export.php` (`xlsx_olustur` / `xlsx_indir` / `export_menu` /
`export_audit`) · menü CSS'i `style.css` (`.dl-menu*`) · açılış `app.js` (kebab altyapısı).
**Test:** `node scripts/export_menu_smoke.js` (önce `php scripts/export_menu_render.php > _test_export_menu.html`) ·
PDKS XLSX kolları `pdks_*_ui_smoke.php` içinde alt süreçte (`scripts/_xlsx_altsurec.php`).

- **CSV'ye DOKUNMA.** CSV başka bir yazılıma aktarılabilir; biçim (`;`, BOM, ondalık
  yazımı, sütun sırası) bayt bayt korunur. Yeni XLSX kolu CSV kolunun **yanına**
  eklenir, aynı satır dizisini okur, CSV kodunu değiştirmez. Sorgu iki kola ortaksa
  kapanışa alınır (`reports.php` `$rpt_detay_veri`) — kopyalanmaz.
- **Yeni dışa aktarım eklerken:** `export_menu($csv_url, $xlsx_url, 'Etiket')` ile buton,
  uç noktada `if (csv || xlsx) require_perm('reports.export')` + her iki kolda
  `export_audit(...)`. **Ortak kapı `reports.export`** — sayfanın okuma yetkisine EK
  olarak istenir (kullanıcı kararı). Menü yetkisi olmayana hiç basılmaz.
- **`xlsx_olustur()` sütun tipleri:** `metin · tamsayi · kg · ondalik · tutar · sayi ·
  tarih · tarihsaat`. Sayılar SAYI, tarihler TARİH hücresi yazılır; çözülemeyen değer
  metin kalır (veri kaybolmaz). `kg` görünümü tam sayıdır, değer ondalığı korur.
- **Formül enjeksiyonu:** metin hücreleri `setCellValueExplicit(TYPE_STRING)` ile yazılır —
  `=HYPERLINK(...)` gibi bir firma adı/not formül olarak ÇALIŞMAZ. `setCellValue()` /
  `fromArray()` ile kullanıcı verisi YAZMA. Formül yalnız toplam satırındaki `SUBTOTAL(9,…)`
  (filtre uygulanınca yalnız görünen satırları toplar).
- **Toplam satırı** yalnız anlamlı sütunlarda (`'topla' => true`). Giriş/çıkış ya da
  farklı birimler/para birimleri aynı sütundaysa toplam KOYMA. **Para birimleri asla
  toplanmaz** — ayrı sütun (PDKS raporları) ya da ayrı sayfa (çavuş ekstresi, hesap özeti).
- **Bellek sınırı `XLSX_MAX_HUCRE` (150 bin hücre ≈ 130 MB / 7 sn, ölçüldü).** Aşılırsa
  yarım dosya yerine "filtreyi daraltın / CSV indirin" sayfası. Veri hücresi stili aralık
  (`getStyle('A5:J30000')`) ile DEĞİL, kayıtlı stil indeksiyle verilir — aralık çağrısı
  her hücreyi dolaşıyordu (300 bin hücrede +14 sn).
- **Hazır şablonlu yükleme Excel'i** (`record_excel_template.php` + `templates/excel/`)
  ve `rapor_malzeme.php`'nin kendi XLSX bloğu bu yardımcıyı KULLANMAZ; kendi testleri var
  (`record_excel_smoke.php`, `rapor_malzeme_xlsx_smoke.php`). Onlara yalnız yetki + audit eklendi.
- **`excel_ornek_palet.php` bir İÇE AKTARMA şablonudur:** başlık ilk sayfanın 1. SATIRINDA
  kalmalı (`app.js` `parseWorkbook` sözleşmesi) — `xlsx_olustur()` düzeni (A1'de başlık)
  orada KULLANILMAZ.
- **`hesap_export.php`** artık gerçek XLSX üretir (`?bicim=xlsx` varsayılan, `?bicim=csv`).
  Eskiden HTML tablosunu `.xls` uzantısıyla gönderiyordu.
- **Menü = kebab altyapısı** (`.pc-dropdown` + `app.js` `position:fixed`): mobilde
  `overflow-x:auto` olan `.rpt-actions` içinde KESİLMEZ. `position:absolute` bir listeye
  çevirme. Kebab menü içindeki dışa aktarımlar (günlük rapor) iki düz bağlantıdır.
- `fputcsv()` her çağrıda `';', '"', '\\'` ile çağrılır — PHP 8.4 varsayılan `$escape`'e
  güvenmeyi kullanımdan kaldırdı (değer aynı, çıktı değişmez).

---

## Hesap Modülü — Durum Makinesi (Sprint Hesap-01)

Personel masraf takibi. **Çekirdek: `config/hesap_calc.php`** — şema migrasyonu, tutar
ayrıştırma, durum makinesi, bakiye hesabı, yetki kapısı.

**Sayfalar:** `hesap.php` ("Hesabım" — kişisel pano) · `hesap_liste.php` · `hesap_kayit.php` ·
`hesap_muhasebe.php` (onay kuyruğu) · `hesap_durum.php` (geçiş JSON uç noktası) ·
`hesap_yazdir.php` · `hesap_export.php` · `hesap_muhasebe_fis_pdf.php` · `hesap_sil.php` ·
**yalnız yönetici:** `hesap_personel.php` ("Tüm Personel" — kişi × kur bakiye).
(`hesap_sahipsiz.php` v281'de 410 tombstone.) Yeni hesap sayfası eklerken
`nav_aktif_anahtar()` içindeki `$a_hes` listesine ekle.

### Arayüz (Faz 1-3)

Modül kendi CSS/JS'ini kullanır: **`assets/hesap.css` + `assets/hesap.js`** — yalnız
`hesap_*` sayfalarında yüklenir, `style.css`/`app.js`'e HİÇ dokunmaz (maliyet emsali).
`hesap_assets()` (header'dan sonra) ve `hesap_scripts()` (footer'dan önce) ile bağlanır.

- **Tüm sayfa gövdesi `<div class="hs">` içinde olmalı** — mobil 16px input kuralı buna bağlı.
  (Token'lar `:root`'ta tanımlı, o yüzden `.hs-badge`/`.hs-note` sarmalayıcısız da çalışır.)
- **Tek birincil eylem kuralı:** sayfada yalnız bir `.hs-cta`. Diğer her şey `.btn`.
  Renk yalnız iki yerde anlam taşır: tutar işareti ve durum rozeti. Gökkuşağı buton YOK.
- **Bakiye kartı** `.hs-balance--{alacak|borc|denk}` — `hesap_balance_label()` sınıfı belirler.
- **Alt sayfa (bottom sheet):** `data-hs-sheet="<id>"` ile açılır, `.hs-sheet-ovl` z-index 600.
- **Form sırası değişmez:** fiş fotoğrafı → tutar → kategori → Detaylar. İkincil alanların
  hepsi `<details id="hsDetails">` içinde, yeni kayıtta kapalı.
- **Tür/kategori için tek yetkili alan Detaylar içindeki `<select>`'lerdir**
  (`#hsTypeSelect` / `#hsCategorySelect`). Chip'ler ve tür butonları yalnız onları yazar —
  aynı `name` ile iki alan OLUŞTURMA, POST'ta çakışır ve JS kapalıyken form çalışmaz.
- **Durum geçiş butonu:** `data-hs-durum` + `data-hs-id` + `data-hs-not` (inline onclick yok).
- Test: `php scripts/hesap_ui_smoke.php` — sayfaları gerçekten render eder, PHP uyarısı ve
  HTML etiket dengesi dahil doğrular.

**Şema eklentileri** (`account_transactions`): `user_id` (masrafın sahibi) · `created_by` ·
`status` · `submitted_at` · `reviewed_by/at` · `review_note` · `paid_at` · `depo`.

**Durum akışı** — `hesap_transitions()` tek otoritedir, geçişi elle UPDATE ile yazma:

```
draft ⇄ submitted → approved → pending_payment → paid
          ↓            ↓             ↓
       rejected ────────┴─────────────┘  → draft (düzeltmeye al)
```

| Durum | Bakiyeye girer | Geçiren |
|---|:---:|---|
| `draft` / `submitted` | — | sahibi (`hesap.write`) |
| `approved` / `pending_payment` | ✓ | `hesap.approve` |
| `paid` | ✓ | `hesap.pay` — **kayıt kilitlenir**, açmak `hesap.admin` |
| `rejected` | — | `hesap.approve`, **gerekçe zorunlu** |

**Kurallar:**
- **Bakiye yalnız `approved`/`pending_payment`/`paid`'den** hesaplanır — `hesap_balance()`.
  Bekleyen tutar ayrı gösterilir, bakiyeye karışmaz.
- **Para birimleri ASLA toplanmaz.** `hesap_balance()` currency bazında döner; TRY dışı
  kurlar ekranda ayrı kart/satırdır. (Eski `array_sum` hatası — USD+TRY toplanıyordu.)
- **İşaret:** net < 0 → şirket personele borçlu (yeşil) · net > 0 → personel şirkete
  borçlu (kırmızı). `hesap_balance_label()` bunu etiketler.
- **Tutar girdisi `hesap_parse_amount()` ile ayrıştırılır** — `str_replace` KULLANMA;
  eski kod "1234.56"yı 123456 yapıyordu (100× hata).
- `is_given_to_accountant` **legacy bayrak olarak korunur**, `hesap_transition()` senkron
  tutar (bakiyeye giren durumlar = 1). Eski sorgular bozulmasın diye silinmedi.
- **Kişisel hesap (Sprint Hesap-Kişisel):** her ekran varsayılan olarak YALNIZ oturumdaki
  kullanıcının kayıtlarını gösterir — `hesap_kapsam_coz($_GET['personel'] ?? null, 'kendi')`
  + `hesap_kapsam_sql($k, $col)` (pozisyonel `?`; eski `hesap_owner_sql()` yalnız
  sarmalayıcıdır, yeni kodda kullanma). Başkasını YALNIZ yönetici görür:
  `hesap_sees_all()` = `is_admin() || can('hesap.admin')`; yönetici `?personel=<uid>|tum`
  ile kapsamı genişletir (yönetici değilse parametre SESSİZCE yok sayılır, 403 yok).
  Linkler parametreyi `hesap_kapsam_query()` ile taşır. Yönetici başkasına bakınca
  audit `view` yazılır (kendi hesabında yazılmaz).
- **hesap.approve / hesap.pay görünürlük VERMEZ:** onay/ödeme yalnız görülebilen satırda
  çalışır (`hesap_can_transition()` İLK satırı `hesap_row_visible()`). Görünmeyen kayıtta
  `hesap_transition()` "Kayıt bulunamadı." der ("yetkiniz yok" varlığı sızdırırdı).
  Muhasebe bu yüzden yalnız KENDİ kayıtlarını onaylar; başkasınınkini onaylaması
  gerekiyorsa rolüne `hesap.admin` verilir — o zaman herkesi görür. Onay kuyruğunun
  "bekleyen" filtresi yalnız `submitted` (taslak onay beklemez).
- **Kendi kaydını onaylama/ödeme SERBEST** (kullanıcı kararı). Sahip ≠ ben yasağı EKLEME.
- **Sahipsiz (`user_id IS NULL`):** yalnız yönetici görür, HİÇ KİMSENİN bakiyesine girmez
  (`hesap_balance_tum` dahil), sahibi yoktur (`hesap_is_owner` false). Eski sahipsiz
  kayıtların tümü atandı (canlı 0, 2026-09-30); "Sahipsiz Kayıtlar" ekranı ve sayaçları
  v281'de KALDIRILDI (`hesap_sahipsiz.php` = 410 tombstone). Sahip ataması/düzeltmesinin
  TEK yolu `hesap_kayit.php` "Kayıt sahibi" alanıdır (yönetici, düzenleme modu, audit
  `owner_change`): alan artık boş/"Sahipsiz" seçeneği SUNMAZ ve sahibi olan kaydın
  sahibini boşaltmayı REDDEDER — `user_id NULL` kayıt `tum` kapsamında da görünmez
  (`IS NOT NULL`). **Otomatik geri dolum / tahmin YOK.** NULL güvenlik kuralları
  (`hesap_row_visible`, `hesap_balance*`, `hesap_kapsam_sql`) korunur.
- **Depo YOK:** Hesap sorguları `depo_sql_in` / `depot_visible_to_user` kullanmaz — kişisel
  cari aktif depoya göre değişmemeli (DEPO2'de girilen masraf DEPO1'de kayboluyordu).
  `depo` kolonu yalnız bilgi amaçlı damgadır; `enforce_active_depot()` kapısı kalır.
- **Bakiye:** `hesap_balance(null)` = KENDİ bakiye (yönetici için de — global DEĞİL);
  `hesap_balance($uid)` başkası için yalnız yöneticide dolu, değilse sıfır (fail-closed).
  Global yalnız `hesap_balance_tum()` / `hesap_balance_by_user($from,$to,$kur)` —
  ikisi de yönetici değilse boş. Başkasına bakarken etiket üçüncü şahıs:
  `hesap_balance_label($net, true)`.
- **İçerik kilidi:** `hesap_icerik_kilitli()` — approved/pending_payment/paid kaydın
  içeriği ve silinmesi yönetici dışında KAPALI (tek kapı: `hesap_kayit`, `hesap_sil`,
  liste butonları). Düzeltme yolu durum makinesidir: "Reddet" → "Düzeltmeye Al" →
  düzenle → gönder (yeni geçiş EKLEME). Yönetici düzeltmesi/silmesi gerekçe ister
  (audit `update.duzeltme_nedeni` / `delete.gerekce`). Onaylı kaydın **fişi herkese**
  kilitli (yönetici dahil, `hesap_dosya_sil.php`). `hesap_is_locked()` eski kapıdır.
- **Biçim:** tutar — çoklu binlik ayırıcı ("1.234.567"), "0,xxx" ondalık, harf içeren
  girdi 0 (→ hata), en fazla 2 ondalık, < 1e10; para birimi `hesap_para_birimleri()`,
  ödeme yöntemi `hesap_odeme_yontemleri()` beyaz listesi, tarih `hesap_tarih_gecerli()`.
  `hesap_currency_sym()` bilinmeyen kodu `h()` ile kaçırır (eski serbest metin kayıtlar).
- **Toplamlar (O3):** liste / PDF / XLSX özetinde "bakiyeye giren" (onaylı + ödenen) ile
  "tüm durumlar / bekleyen" AYRI; reddedilen hiçbir bakiye toplamına girmez. CSV değişmedi.
- **Fiş dosyaları** `uploads/hesap/` altında web'e KAPALI (`Require all denied` —
  `.htaccess` hem depoda hem `HESAP_UPLOAD_HTACCESS` ile yeniden üretilir); yalnız
  `hesap_dosya.php` (sahiplik kontrolüyle) sunar. Statik URL VERME.
- **Ana sayfa kartı** (`index.php`) kişiseldir: bugün (yalnız TRY) + kendi bekleyen sayısı;
  yönetici ek olarak onay bekleyen sayısını görür.
- Test: `php scripts/hesap_smoke.php` (bellek içi SQLite, canlı DB'ye dokunmaz) ·
  `php scripts/hesap_izolasyon_smoke.php` — iki operator (A/B), muhasebe, izleyici,
  yönetici, süper admin ile gerçek sayfaları çalıştırır; her izolasyon bulgusu
  (sahipsiz sızıntı, PDF personel özeti, ana sayfa sayacı, onaylı kaydın düzenlenmesi,
  depo kaybı, muhasebe görünürlüğü, silme, biçim) regresyondur. **Görünürlük/kapsam
  kuralına dokunduysan çalıştır.**

### PDF Dönem Raporu (Faz 5)

**`config/hesap_pdf.php`** — veri toplama (`hesap_report_data`), HTML üretimi
(`hesap_report_html`), PDF üretimi (`hesap_report_pdf`). Uç nokta: **`hesap_yazdir.php`**
(varsayılan PDF · `?goruntule=html` hızlı yazdırma · `?indir=1` dosya indirme).

Bölümler: logo + kapsam başlığı → dönem özeti (para birimi başına) → personel özeti →
kategori kırılımı (yüzde çubuklu) → işlem listesi (durum rozetli) → imza alanları →
**3×3 fiş görselleri** (sonda, her kutuda kayıt künyesi).

**dompdf kuralları — değiştirme:**
- `isRemoteEnabled = false` · `isPhpEnabled = false`. Görseller `data:` URI olarak gömülür;
  uzak kaynak çekilmez, HTML içinde PHP çalışmaz. Bu ayarları açma.
- **`text-transform: uppercase` KULLANMA** — CSS Türkçe i/İ eşlemesini bilmez:
  "Gelir" → "GELIR", "Şirkete" → "ŞIRKETE" olur. Etiketi doğrudan istenen yazımda yaz.
- **Sayfa numarası `counter(pages)` ile çalışmaz** (dompdf 0 döner). Render sonrası
  `$canvas->page_text(... '{PAGE_NUM} / {PAGE_COUNT}' ...)` kullanılır.
- Yazı tipi **DejaVu Sans** — Türkçe glifleri ve ₺ (U+20BA) içerir. Değiştirirken glif
  kapsamını doğrula.
- Görseller `hesap_pdf_image_uri()` ile GD üzerinden küçültülür (uzun kenar 900 px, JPEG 72).
  Okunamayan dosya `null` döner ve rapor "[görsel okunamadı]" ile devam eder — çökmez.
- Rapora en çok `HESAP_PDF_MAX_FIS` (90) görsel eklenir; aşan sayı rapora not düşülür.
- Test: `php scripts/hesap_pdf_smoke.php` (geçici yükleme klasörü, canlı uploads/'a dokunmaz).

**Bağımlılık:** `dompdf/dompdf ^3.0`, `vendor/` içinde commit'li (depo pratiği).
`vendor/` ~22 MB; 7.6 MB'ı DejaVu font ailesi — Türkçe için gerekli, silme.

---

## Rol Yönetimi (Sprint Rol-01 / Rol-02)

Sabit 5 rol yerine **admin kendi rollerini tanımlar**. Altyapı (roles /
role_permissions / user_roles) zaten vardı; `roles.php` onun üzerine CRUD koyar.

**Dosyalar:** `roles.php` (liste + oluştur/düzenle/sil) · `config/auth.php`
(`permission_catalog()`, `protected_role_slugs()`, `any_active_user_has_permission()`) ·
`config/helpers.php` (`first_allowed_page()` + sidebar/topnav linki).
**Test:** `php scripts/roles_ui_smoke.php` (ekran + POST akışları) ·
`php scripts/rol_kapilari_smoke.php` (yetki mimarisi değişmezleri).

- **Yetki kataloğu `permission_catalog()`** — 10 modül grubu, 51 yetki, Türkçe
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
- **Hesap modülünün sessiz köprüleri kaldırıldı** (`hesap_can()`):
  `reports.read → hesap.read`, `records.write → hesap.write`,
  `records.delete → hesap.delete` eşlemeleri vardı; Roller ekranında Hesap
  kutuları boş bırakılan bir rol yalnız rapor/yükleme yetkisiyle masraf kaydı
  açabiliyordu — **yetki ekranı gerçeği söylemiyordu**. Sidebar `$p_hes` ve
  `index.php`'deki Hesap kartı da aynı anda `hesap.read`'e çekildi. Diğer modül
  yardımcıları (`can_beyan`, `can_maliyet`, `pdks_*_can`) 1:1'dir, köprü yok.

## Veritabanı Yedekleri (Sprint DB-Backup-02)

**Dosyalar:** `config/db_backup_helpers.php` (tüm mantık, `_bh_*` yardımcıları) ·
`admin_db_backups.php` (liste / manuel yedek / indir / sil) · `index.php` (17:00
sonrası ilk admin açılışında otomatik yedek + "yedek eski" şeridi) ·
`scripts/db_backup_cron.php` (opsiyonel CLI) · `storage/**/.htaccess`.
Kapı `is_admin()` (katalogda karşılığı yok — bilinçli, bkz. Rol Yönetimi).
**Test:** `php scripts/db_backup_smoke.php` (bellek içi SQLite + geçici klasör,
canlı DB'ye ve `storage/backups/`'a dokunmaz). Yedeğe dokunduysan çalıştır.

- **Şema DONDURULMUŞ:** `database_backups.status` yalnız `success`/`failed`.
  `running` gibi bir durum migration ister (GO olmadan YOK). "Çalışıyor / bugün
  kaç kez denendi / süreç öldü mü" bilgisi klasördeki `.backup.lock` (flock) ve
  `.auto_state.json` dosyalarındadır. `create_database_backup()` dönüş
  anahtarlarına yalnız EKLEME yapılır (`busy` / `skipped` / `note`).
- **Dosya:** `db_backup_YYYYMMDD_HHMMSS_<16 hex>.sql.gz` (rastgele ek — aynı
  saniyede çakışmaz, tahmin edilemez), izin **0600**, klasör 0750. Yol HER ZAMAN
  `_bh_backup_path(filename)` ile kurulur; `file_path` kolonu yazılır ama
  okunmaz (sunucu taşınınca bayatlar).
- **Yazma bütünlüğü:** `<ad>.part`'a yazılır → her `fwrite/gzwrite` dönüşü
  kontrol edilir → `_bh_verify_backup()` dosyayı sonuna kadar açar (gzip akış
  sonu + son 512 baytta `-- Dump completed`) → ancak o zaman `rename`. Başarı
  ölçütü dosya boyutu DEĞİL, doğrulamadır. **mysqldump'a `--compact` /
  `--skip-comments` EKLEME** — alt satır kaybolur, her yedek "kesik" sayılır.
  Başlamadan disk kontrolü: en az max(50 MB, 1,5 × son başarılı yedek).
- **mysqldump:** şifre komut satırında DEĞİL, geçici option dosyasında
  (`_bh_cnf_value()` — çift tırnak + yalnız `\` kaçışı; mysys `\"`'yi ÇÖZMEZ, içteki
  `"` kaçışsız kalır; tırnak içinde `#` yorum başlatmaz).
  `--no-tablespaces --default-character-set=utf8mb4`, stderr ayrı dosyaya alınır ve
  başarısızlıkta `mysqldump exit=N: <stderr>` olarak saklanır; PDO yedeği
  başarılı olsa bile satırın `error_message`'ında "Not: …" diye görünür
  (eskiden sessizce siliniyordu). `_bh_can_mysqldump()` TEK kaynaktır (ekran
  rozeti de onu okur).
- **PDO fallback** (`_bh_pdo_dump`): MySQL'de AYRI, **unbuffered** bağlantı
  (tablo belleğe inmez) + `START TRANSACTION WITH CONSISTENT SNAPSHOT`; çok
  satırlı INSERT (500 satır / 1 MB), doğrudan `.part` gz'ye akar (geçici tam
  döküm dosyası YOK). **View / trigger / routine dökülmez, hex-blob yok** —
  view'lar `-- VIEW atlandı` yorumuyla geçilir ve nota yazılır. Bugün şemada
  bunların hiçbiri yok; eklenirse mysqldump yolu gerekir.
- **Kilit + fren:** aynı anda tek yedek (`.backup.lock`, `LOCK_NB`). Kilit
  alınamazsa `busy` döner, DB'ye/audit'e HİÇBİR ŞEY yazılmaz; manuel POST "zaten
  alınıyor" der, index.php hiçbir şey göstermez. Otomatik yedek günde en çok
  **3 deneme**, denemeler arası **30 dk**, bugün 3 `failed` satırı varsa da
  durur; sayaç deneme ÖNCESİ artar (fatal'da da sayılır). Manuel yedek ve cron
  frenden etkilenmez (yalnız kilit). Fatal (bellek/zaman aşımı) olursa shutdown
  işleyicisi `.part`/geçici dosyaları siler ve `.auto_state.json`'a `last_crash`
  yazar — ekranda uyarı şeridinde görünür.
- **Saklama** (`cleanup_old_database_backups`, başarılı yedekten sonra, kilit
  içinde): 14 günden eski başarılılar silinir ama **en yeni 3 başarılı yedek
  HER ZAMAN korunur**; dosya silinemezse satır da KALIR. Eski `failed` satırları
  silinir. Yetim tarama: 6 saatten eski `.part` ve DB'de karşılığı olmayan
  14 günden eski `db_backup_*` dosyaları. Audit `database_backup_cleanup`.
- **Tekil silme** POST + CSRF + kilit; **son başarılı yedek silinemez**, audit
  `database_backup_deleted` (eski değerlerle). Audit olayları: `_created` /
  `_failed` / `_downloaded` / `_deleted` / `_cleanup` (`database_backup_` önekli).
- **İndirme bilerek GET** (`admin_db_backups.php?action=download&id=N` — URL
  biçimi SABİT, index.php ve eski yer imleri kullanır): çapraz köken yanıtı
  okuyamaz; sahte istek yalnız `downloaded_at`/audit yazabilir (Düşük).
  Başlıklardan önce `session_write_close()`; `Cache-Control: no-store` + `nosniff`.
- **sw.js kuralı:** yolunda `admin_db_backup` geçen ya da `?action=download`
  taşıyan istek SW'ye HİÇ girmez (`respondWith` yok); ayrıca `!ok`, `type !==
  'basic'` ve `Content-Disposition: attachment` yanıtları önbelleğe YAZILMAZ.
  Eskiden tam DB dökümü CacheStorage'a kalıcı yazılıyordu. `no-store` ölçüt
  DEĞİL — PHP oturumu her sayfaya no-store bastığı için çevrimdışı sayfa yedeği
  biterdi. Yeni bir indirme uç noktası eklersen `attachment` başlığını gönder.
- **Depolama:** klasör web kökünde; koruma `.htaccess` (halkayit/ ile aynı
  çift sözdizimi: `Require all denied` + `<IfModule !mod_authz_core.c>` içinde
  `Order/Deny`) + rastgele dosya adı + 0600. `ensure_db_backup_dir()` eksik ya
  da eski (`Require` içermeyen) `.htaccess`'i yeniden yazar.
- **Deploy dosya SİLMEZ:** eski geçici araç `admin_db_backup_download.php`
  repodan silinseydi canlıda kalırdı; yerine yalnız 410 döndüren bir tombstone
  kondu (DB/oturum/kabuk yok). Silme; boş kalsın. v281'de aynı politika 7 sayfaya daha uygulandı (bkz. Dosya Haritası → tombstone listesi).
- **Cron (opsiyonel):** otomatik yedek admin girişine bağlıdır — hiçbir admin
  17:00 sonrası ana sayfayı açmazsa o gün yedek olmaz. cPanel "Cron Jobs"
  ekranından eklenebilir (kullanıcıdan SSH ile komut çalıştırmasını İSTEME):
  `15 18 * * * php /home/<hesap>/public_html/scripts/db_backup_cron.php`
  (`--force` her durumda yeni yedek alır). Çıktı `OK …` / `BUSY` / `SKIP …` /
  `FAIL …`, yalnız FAIL'de çıkış kodu 1. Cron web sunucusundan farklı bir
  kullanıcıyla çalışıyorsa dosya sahipliği/izinleri (0600) farklılaşabilir.

---

## Önemli Desenler

```php
// Tür-aware yönlendirme
$list_url = ($record['type'] ?? 'yukleme') === 'cikma' ? 'cikmalar.php' : 'records.php';

// CSRF — form
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
// CSRF — işlem
csrf_check($_POST['csrf'] ?? null);    // JSON endpoint: JSON body'den
csrf_check($input['csrf'] ?? null);    // csrf_check() JSON-aware: 403+JSON döner

// Audit
audit_log_event('create', 'records', $id, null, $new_vals);
audit_log_event('lock',   'records', $id, $old, ['durum'=>'yuklendi']);

// Kilitli kayıt unlock
// durum=yuklendi → locked_at/locked_by set
// kilit açma → records.unlock gerekli + revision_reason zorunlu

// pc-only / mobile-only (Sprint 31B fix)
// ≥900px: .pc-only → sidebar var, .mobile-only cards gizli (1024px'de)
// <768px: .mobile-only kart, .pc-only gizli
```

---

## Geliştirme Kontrol Listesi

Yeni özellik eklerken:

- [ ] Mobilde taşma var mı? (`overflow-x: clip` korunuyor mu?)
- [ ] Sidebar aktif link tespiti güncellendi mi? (`nav_aktif_anahtar()` içindeki `$a_*` bayrakları — sidebar onu okur)
- [ ] Yeni sayfa bir bölüme mi ait? `nav_aktif_anahtar()` (sidebar + mobil alt çubuk TEK kaynak) listesine ekle.
- [ ] Input mobilde 16px font-size alıyor mu?
- [ ] Yeni tablo `.table-wrap` içinde mi?
- [ ] Print'te görünmemesi gerekenler `@media print { display:none }` içinde mi?
- [ ] Yeni DB kolonu/tablosu varsa migrasyon eklendi mi?
- [ ] Permission kontrolü var mı?
- [ ] Audit logu var mı?
- [ ] SW cache versiyonu artırıldı mı? (style.css veya kritik dosya değiştiyse) — `config/helpers.php`'deki `APP_SURUM` sabitini de AYNI sayıya çek (sidebar altında gösterilir).
- [ ] **Sürümü artırmadan önce `origin/main`'deki güncel `APP_SURUM`'a bak** — aynı anda iki oturum/PR açıksa ikisi de aynı numarayı alır (v291 iki ayrı özellikle iki kez çıktı, v292'ye çekildi). Aynı numara = Service Worker önbelleği ikinci deploy'da tazelenmez. İkinci PR merge edilmeden önce `git fetch origin main` + sürümü bir üste al.

---

## Yaygın Hatalar

| Hata | Sebep | Çözüm |
|---|---|---|
| Dikey scroll çalışmıyor | `overflow-x: hidden` html'de | `overflow-x: clip` kullan |
| Dropdown overflow'da kesiyor | `overflow: auto` stacking context | `position: fixed` + `getBoundingClientRect()` |
| Dara toplamı 1 eksik | Per-palet yuvarlama | Sadece toplamda yuvarla |
| `type` kolonu bulunamadı | Auto-migration çalışmadı | `migrate.php?run=1` veya phpMyAdmin ALTER TABLE |
| SQLSTATE[HY093] | PDO named param tekrar kullanıldı | Pozisyonel `?` kullan |
| Tutar 100× büyük kaydedildi | `str_replace(['.',','],['','.'])` | `hesap_parse_amount()` kullan |
| Rapor toplamı tutmuyor | Para birimleri toplanmış | `GROUP BY currency` — kurları ayır |
| Personel başkasının masrafını görüyor | `OR user_id IS NULL` ya da `hesap.approve`'u görünürlük sayma | `hesap_kapsam_coz()` + `hesap_kapsam_sql()`; görünürlük yalnız `hesap_sees_all()` |
| Sidebar görünmüyor | SW eski CSS'i cache'den sunuyor | Hard refresh (Ctrl+Shift+R) + SW versiyonu artır |
| CSRF JSON endpoint 400 dönüyor | Eski `csrf_check` plain-text die() | Güncel `csrf_check()` JSON-aware — 403+JSON döner |
| HKS "... doğum tarihi girilmelidir" | `DogumTarihi` sunucuya ulaşmadı (boş ya da yanlış konumda → DataContract sessizce atlar) | Konum SABİT: alfabetik (CepTel < DogumTarihi < KisiSifat), canlı WSDL'de `xs:string`. Konumu değiştirme; merdiven v291'de kaldırıldı |
| HKS "Tc kimlik numarası Mernis sisteminde bulunamadı" (satır HataKodu 21) | DogumTarihi saatli (`00:00:00`) ya da ISO gönderildi; HKS saatsiz `GG.AA.YYYY` bekliyor (kesinleşti 05.10.2026). Veri doğru olabilir | Varsayılan `gtb_tarih` olmalı (`HKS_DOGUM_BICIMI`, `dogum_deney.php`'de "yürürlükteki biçim" kontrol et). Künye/rüsum yok, taslak korunur |
