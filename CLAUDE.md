# Asya Fresh — Claude Code Proje Hafızası

## Project Summary

PHP 8 + MySQL tarım ihracat operasyon yönetim sistemi. Mobil öncelikli, PWA kurulabilir.
Çerçeve yok — saf PHP, vanilla JS; ortak `assets/style.css` / `assets/app.js` ile gerektiğinde sayfaya özel CSS/JS dosyaları.

**Canlı:** `asya.scai.tr` (2026-09-27'den beri) · **Test:** `nuverna.derspros.com.tr` (ayrı DB; `derspros.com.tr` 25.12.2026'da bitiyor, yenilenmeyecek)  
**Branch:** `claude/fix-records-print-mobile-WuKdT`  
**SW Cache:** `yukleme-plani-v548` (sw.js — değişiklikte artır; `config/helpers.php`'deki `APP_SURUM` ile aynı sayıda tut)

## Modül Skill'leri (ayrıntılı kurallar)

Sürüm sürüm modül kuralları token tasarrufu için `.claude/skills/<ad>/SKILL.md` altına taşındı (içerik kelimesi kelimesine aynı). Bir modülün dosyasına dokunmadan ÖNCE ilgili skill'i yükle (Skill aracı) — içinde "Değiştirmeden önce oku" tuzakları ve "Test:" komutları var. Yeni modül kuralı eklerken ilgili skill'e yaz, CLAUDE.md'yi şişirme.

| Skill | Kapsam |
|---|---|
| `hizli-maliyet` | Hızlı Maliyet / Hızlı Hesapla |
| `mail-merkezi` | Mail Merkezi |
| `ekran-kilidi` | Masaüstü Ekran Kilidi / PIN |
| `rol-yonetimi` | Rol Yönetimi ve Rol Matrisi |
| `pdks-hakedis` | PDKS — Çavuş Ücreti, Hakediş, Mesai Özeti |
| `pdks-kiosk-kart` | PDKS — Kiosk, Kartlar, Mesai Detayı |
| `pdks-fis-excel` | PDKS — Fiş Excel Yükleme |
| `maliyet-modulu` | Maliyet Hesabı Modülü (detaylı) |
| `beyan-hks` | Beyanlar ve Beyan→HKS Bildirimi |
| `hal-kayit-hks` | Hal Kayıt (HKS) — halkayit/ |
| `siparis-sevkiyat` | Siparişler (OGL), Cari Döküm, Ürün Siparişleri |
| `hesap-modulu` | Hesap Modülü (personel masrafı) |
| `excel-export` | Excel İndir — CSV + XLSX |
| `db-yedekleri` | Veritabanı Yedekleri |
| `bottomnav` | Mobil Alt Çubuk (bottomnav) |
| `gunluk-uretim` | Günlük Üretim — işlenen mal + işçi başı verim (v507) |
| `cari-modulu` | Cari İşlemler (cari_*; ledger, tahsilat/virman, ekstre) |
| `stok-modulu` | Stok (muhasebe; stok_*; INSERT-only defter, ortalama maliyet, tanımlar) |
| `fatura-modulu` | Fatura (satış/alış faturası, müstahsil, irsaliye, sipariş fişi; fatura_*; dönüşüm, kalan, tanımlar, yazdır) |
| `kasa-modulu` | Kasa (kasa_*; nakit kasa + banka hesabı, INSERT-only defter, tahsilat/tediye/virman, cari bağı, yazdır) |
| `cek-senet` | Çek-Senet (cek_*/senet_*/ceksenet_*; alınan/verilen çek + senet, durum makinesi, bordro, ortalama vade) |

---

## Dosya Haritası (modül bazlı)

Kök `*.php` = sayfa; `config/` = çekirdek; URL'ler sabittir (sayfa taşınmaz/bölünmez).

| Modül | Sayfalar (kök) | Çekirdek (`config/`) | Varlıklar | Test (`scripts/`) |
|---|---|---|---|---|
| Çekirdek / altyapı | `index` · `login` · `logout` · `depo_sec` · `migrate` (admin şema paneli) · `sw.js` · `manifest.json` | `db` (PDO + auto-migration) · `auth` · `helpers` (render_header/footer, sidebar, bottomnav, csrf_check, audit_log_event, can) · `calc` · `calc_helper` · `print_helpers` · `xlsx_export` · `sifre_onay` (v464, giriş şifresiyle onay) | `style.css` (TEK CSS) · `app.js` (TEK JS) · `nav-icons/` · `print_base.css` | `rol_kapilari_smoke` · `bottomnav_*` · `export_menu_*` · `suggest_list_smoke` |
| Yükleme / Çıkma | `records` · `cikmalar` · `record_create/edit/view/delete/durum` · `cikma_create` · `_form` · `print_loading` · `records_bulk_print` · `record_excel_template` · `excel_ornek_palet` · `api_kalan` · `api_templates` · `api_etiket_foto` · `api_tanim_ekle` · `cikma_report_toggle` | `calc` · `material_stock_helpers` | `kalan.js` · `templates/excel/` | `record_excel_smoke` |
| Günlük X/Z rapor | `daily_report_create/view/archive` · `print_daily` | — | — | — |
| Kantar | `kantar` · `kantar_create/edit/view/delete/foto` · `_kantar_form` · `kantar_raporu` · `kantar_report_toggle` | — | — | — |
| Stok / Malzeme | `stok` · `malzeme_stok` · `malzeme_stok_islem/import/rapor` · `malzeme_hareketleri` · `malzeme_stok_tehis` · `api_bulk_material` | `material_stock_helpers` | — | `test_material_stock_helpers` |
| Beyan + HKS köprüsü | `beyanlar` · `beyan_view/create/edit/delete/parse/eslestir/bulk_save` · `_beyan_liste` · `api_beyan_bildirim` · `beyan_bildirim_tani` | `helpers` (`beyan_*`, `bb_*`) | — | `beyan_bildirim_smoke` · `beyan_ui_smoke` · `beyan_js_smoke.js` |
| Hal Kayıt (HKS) | `halkayit/index.php` (panel, iframe) · `app.php`/`app.html` (SPA) · `api.php` (JSON) · `taslak_lib.php` (TASLAK YAZMANIN TEK YOLU) · `kisi_havuzu_lib.php` (karşı taraf havuzu) · `dogum_deney_lib.php` + `dogum_deney.php` (doğum tarihi biçim deneyi, admin) · `tekrar_gonder_lib.php` (Gönderilenler → Tekrar gönder, salt okunur) · `hks_soap.php` · `config.php` · `db.php` · teşhis: `tani.php` · `opcache_reset.php` · `endpoint_test.php` · `.htaccess` (include-only PHP kapalı) | — | `halkayit/*.js` (qrcode/jspdf/html2canvas) | `hks_*_test.php` · `hks_kisi_havuzu_smoke` · `hks_dogum_deney_smoke` · `hks_tekrar_gonder_smoke` · `hks_kisi_pencere_smoke.js` · `hks_gonderilenler_smoke.js` |
| Hesap | `hesap` · `hesap_liste/kayit/durum/muhasebe/personel/yazdir/export/sil/dosya/dosya_sil` · `hesap_muhasebe_fis_pdf` · `hesap_config` | `hesap_calc` · `hesap_pdf` | `hesap.css` · `hesap.js` | `hesap_smoke` · `hesap_ui_smoke` · `hesap_izolasyon_smoke` · `hesap_pdf_smoke` |
| Maliyet | `maliyet` · `maliyet_form/view/sil/alanlar/sablon/ambalaj` · `_maliyet_row` · `api_maliyet_link` | `cost_calc` · `cost_link` | `maliyet.css` · `maliyet.js` | `cost_link_smoke` |
| PDKS / Personel / Çavuş | `personel*` · `isci_kartlari` · `isci_tipleri` · `gunluk_*` (+ `gunluk_fis_yukle` · `_fis_onizle`) · `cavus*` · `mesai_*` · `manuel_cikis` · `giris_cikis` · `pdks_nfc_test` · `ters_kart*` · `cavus_fis_geri_al` · `cavus_fis_rapor` (v464) | `pdks*.php` (`pdks`, `pdks_fis` (v446), `pdks_gunluk`, `pdks_hakedis`, `pdks_cari`, `pdks_rapor`, `pdks_faz8*`, `pdks_faz9d`, `pdks_ters`) | `pdks.css` · `pdks.js` · `print_pdks.css` · `ters_kart.js` | `pdks_*_smoke` |
| Günlük Üretim | `gunluk_uretim` · `gunluk_uretim_yukle` | `uretim` | `uretim.css` · `uretim.js` · `chart.umd.min.js` | `uretim_smoke` |
| Raporlar | `reports` · `raporlar` · `rapor_malzeme` · `rapor_yazdir` · `kantar_raporu` | `xlsx_export` | — | `rapor_malzeme_xlsx_smoke` |
| Siparişler | `siparisler` · `siparis_view/yukle/kaydet/pkl/belge/dosya/zip/beyan_bagla/ayarlar/yazdir/cari` · `_siparis_liste` | `siparis_core` · `siparis_pdf` · `siparis_eslestir` · `siparis_pkl` · `siparis_beyan` · `siparis_yazdir` · `siparis_cari` · `siparis_evrak` | `siparis.css` · `siparis.js` · `siparis_yazdir.js` · `nav-icons/siparis.svg` · `templates/excel/pkl_v14_blank.xlsx` | `siparis_core_smoke` · `siparis_pdf_smoke` · `siparis_pkl_smoke` · `siparis_ui_smoke`(`.js`) · `siparis_gate_static_smoke` · `siparis_yazdir_smoke`(`.js`) · `siparis_pkl_mail_smoke` · `siparis_cari_smoke` |
| Frugro Siparişler (v512 → v515 OGL eşi) | `frugro_siparis` (liste, 3 bölüm) · `frugro_view` (detay, 7 adım) · `frugro_pkl` · `frugro_invoice` · `frugro_yukle` (Order Log xlsx) · `frugro_kaydet` · `frugro_mail` · `frugro_ayarlar` · `frugro_yazdir` · `frugro_paylas` · `frugro_eml` · `frugro_zip` · `_frugro_liste` · `_frugro_mail_kart` · nav anahtarı `frugro` (Roller'de KENDİ satırı `siparis.frugro.*`; Frugro satırı öncesi kaydedilmiş rol OGL'den türetir — `role_frugro_derive`) | `frugro_core` (durum yazmanın TEK yolu `frg_durum_degistir`) · `frugro_ui` · `frugro_detay` · `frugro_pkl` · `frugro_invoice` · `frugro_mail` · `frugro_yazdir` · `frugro_wp` | `frugro*.css/js` · `nav-icons/frugro.svg` | `frugro_{core,ui,liste,detay,pkl,invoice,mail,gate_static}_smoke` · `role_frugro_matrix_smoke` · `frugro_liste_smoke.js` · `frugro_detay_smoke.js` · `frugro_ui_render` |
| Cari İşlemler (yalnız admin) | `cari_kartlari` · `cari_fisler` · `cari_dekontlar` · `cari_tanimlari` · `cari_ekstre` · `cari_kaydet` (TEK yazma ucu, POST) | `cari_core` · `cari_ui` | `cari.css` · `cari.js` | `cari_{core,gate_static,ui}_smoke` · `cari_ui_smoke.js` (gerçek PHP köprüsü) |
| Stok (muhasebe, yalnız admin; v524) | `stok_kartlari` · `stok_tanimlari` · `stok_kaydet` (TEK yazma ucu, POST) · fiş sayfaları `stok_giris/cikis/donusum_fisleri` + `transfer_fisleri` (giriş/çıkış/transfer/dönüşüm; cari dekont bağı `cari_hareket_ekle($kaynak)`) | `stok_core` · `stok_ui` | `stok.css` · `stok.js` | `stok_{core,gate_static,ui}_smoke` · `stok_ui_smoke.js` |
| Fatura (yalnız admin; v527) | `satis_faturalari` · `alis_faturalari` · `sevk_irsaliyeleri` · `alinan_siparis_fisleri` · `fatura_tanimlari` · `fatura_kaydet` (TEK yazma ucu, POST) · `fatura_cikti` (yazdır / liste xlsx-csv, GET) | `fatura_core` · `fatura_ui` | `fatura.css` · `fatura.js` | `fatura_{core,gate_static,ui}_smoke` · `fatura_ui_smoke.js` |
| Kasa (yalnız admin; v528) | `kasa_fisleri` · `kasa_tanimlari` · `kasa_kaydet` (TEK yazma ucu, POST) · `kasa_cikti` (kasa defteri yazdır / xlsx-csv, GET) | `kasa_core` · `kasa_ui` | `kasa.css` · `kasa.js` | `kasa_{core,gate_static,ui}_smoke` · `kasa_ui_smoke.js` |
| Çek-Senet (yalnız admin; v528) | `cek_dekontu` · `senet_dekontu` · `cek_tanimlari` · `cek_kaydet` (TEK yazma ucu, POST) · `cek_cikti` (bordro yazdır / liste xlsx-csv, GET) | `ceksenet_core` · `ceksenet_ui` | `ceksenet.css` · `ceksenet.js` | `ceksenet_{core,gate_static,ui}_smoke` · `ceksenet_ui_smoke.js` |
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
**Sıra + adlar (v391, sahip kararı):** Ana Sayfa · **OGL Siparişler** · Beyanlar · Hal Bildirimi · Mail Merkezi · **Yükleme Planları** (`records.php`) · **Paletleme** (`cikmalar.php`, eski "Çıkmalar") · **Mal Kabul** (`kantar.php`, eski "Kantar") · Raporlar · Malzeme Stok · Hesap · Personel Takibi; ardından Yönetim. Aynı sıra/adlar masaüstü sidebar, tablet üst menü (`topnav`) ve mobil alt çubuk + "Diğer" sayfasında (`nav_alt_sayfalar()` anahtar SIRASI = sidebar sırası; soğuk başlangıç `records, hks, ptak, rapor`'dan sonra bu sırayı izler) geçerlidir. Yalnız GEZİNME adları değişti — sayfa içi başlıkları, URL'ler ve yetki anahtarları aynı. **v393: "Yükleme Planları" (eski "Yüklemeler Planı") 🚚 ve "Paletleme" 📋 — sidebar + ana sayfa kartı emojileri, mobil alt çubuk ikonu/rengi (`records` → `cikma.svg` turuncu, `cikma` → `records.svg` mavi; kısa ad "Yük. Planı") takas edildi.** **v392: ana sayfa kartları (`index.php`) da AYNI sıra ve adlarla** (OGL Siparişler · Beyanlar · Hal Bildirimi · Mail Merkezi · Yükleme Planları 📋 · Paletleme 🚚 · Mal Kabul · Raporlar · Malzeme Stok · Hesap · Personel Takibi; "Hesabım" kartı "Hesap" oldu). **v548 (sahip kararı): sidebar yeniden düzeni.** "Ana Sayfa" Sipariş grubunun ÜSTÜNDE tek bağlantı (Operasyon'dan çıktı). Operasyon'daki Yükleme Planları / Paletleme / Mal Kabul / Raporlar adları **"Eski Yükleme Planı / Eski Paletleme / Eski Mal Kabul / Eski Raporlar"** oldu (sidebar, topnav, alt çubuk `nav_alt_sayfalar` etiket/kisa, `index.php` kartları — parite testi etiketlerin aynı olmasını ister). Taşınanlar: Malzeme Stok → Stok grubu, Eski Raporlar → Raporlar grubu, Hesap → "Kullanıcı Ayarları" grubu (eski "Kullanıcı Konsolu"); bu 3 grup yetkili admin OLMAYAN role de yalnız o bağlantı için çizilir (`$__ek`, `render_desktop_sidebar`). Araç Yükleme / Palet / Mal Kabul Fişleri `nav_muhasebe_gruplari()['operasyon_fis']` grubundadır ve sidebar'da Operasyon'un İÇİNDE çizilir (ana sayfada "Operasyon Fişleri" başlığı). Günlük Kurlar + Döviz Tanımları Kasa grubuna taşındı. "Genel Uygulamalar" grubu ve Şube/Şirket Değiştirme, Mesajlar, Program Tanımları, Diğer Uygulama Tanımları, Güncellemeyi Çalıştır, Lisans & Kayıt İşlemleri, Kayıt Bilgisi, WAAP Mesaj, Genel Duyurular, Güncelleme Bilgisi, Program Hakkında sayfaları kalktı (410 tombstone). Sidebar alt alanı (kullanıcı adı + sürüm + çıkış) SABİT: `.desktop-sidebar{overflow:hidden}`, kaydırma yalnız `.sidebar-nav`'da; sürüm damgası `.sidebar-user` içinde çıkışın yanında. Yeni sayfa eklerken sidebar + `nav_alt_sayfalar()` + `topnav` + `index.php` kartlarını birlikte güncelle. **v433: Operasyon / Yönetim başlıkları açılır-kapanır** (`<details class="sidebar-grup" data-sb-grup>` + `summary.sidebar-section`, ikonlu/belirgin). Etkin sayfanın grubu sunucuda `open` basılır (`$a_ynt`) ve JS onu hep açık tutar; diğer grubun tercihi yalnız cihazda (`localStorage['asya_sb_grup']`). Yeni sidebar grubu eklersen aynı kalıbı kullan. Test: `bottomnav_render/smoke` (`$SOGUK` sırası `bottomnav_render.php`'de bu sıranın aynasıdır).

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
cari_kartlar / cari_hareketler / cari_tanimlar  -- Cari İşlemler (hareketler DEĞİŞMEZ ledger; şema yalnız migrate.php kartı)
hks_eslesme         -- Serbest metin ↔ HKS katalog id eşlemeleri (öğrenilen)
stok_kartlar / stok_tanimlar / stok_fisler / stok_fis_satirlar / stok_hareketler  -- Stok (muhasebe; hareketler INSERT-only defter, stok.php/malzemeden bağımsız; şema yalnız migrate.php kartı)
fatura_belgeler / fatura_satirlar / fatura_tanimlar  -- Fatura (8 belge türü tek başlıkta; satırlar INSERT-only; kalan miktar türetilir; şema yalnız migrate.php "Fatura" kartı)
kasa_kartlar / kasa_hareketler / kasa_tanimlar  -- Kasa (nakit kasa + banka hesabı = tip='banka' kart; hareketler INSERT-only defter; cari bağı `cari_hareket_ekle`; şema yalnız migrate.php "Kasa" kartı)
ceksenet_kayitlar / ceksenet_hareketler / ceksenet_tanimlar  -- Çek-Senet (4 tür tek şemada; hareketler INSERT-only geçmiş; durum makinesi `ceksenet_islemler()`; cari + kasa bağı; şema yalnız migrate.php "Çek-Senet" kartı)
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

### Şema Migrasyon Paneli görünümü (v388)

`migrate.php` kartları (35 adet) `mg_kart_ac('<id>')` / `mg_kart_kapat()` ile sarılır: kartın KENDİ çıktısında `✓` var ve `✗` yoksa kart **hazır** sayılır → kapalı `<details>` tek satıra iner (başlık + "✓ Hazır" rozeti); eksik olan AÇIK kalır. Üstte "N / <toplam> kart hazır" özeti + "Tümünü aç" düğmesi; `migrate.php#siparis-sofor` gibi bağlantılar kapalı kartı açar. **POST sonrası hiçbir kart kapanmaz** (migrasyon sonuç satırları görünsün). **Hiçbir kart / POST dalı / migrasyon fonksiyonu KALDIRILMADI** — ayrı veritabanında (ör. test sitesi) eksik kalan şema yine buradan kurulur; birçok statik smoke (`ne=` dalları, tek çağıran = migrate.php) bunu sabitler. Yeni kart eklerken `<div class="card" …>` yerine `<?php mg_kart_ac('id'); ?>` … `<?php mg_kart_kapat(); ?>` kullan ve kartın çıktısına durum için `✓`/`✗` bas (hazırsa yalnız `✓`). Test: `php scripts/migrate_panel_smoke.php` (migrate.php'yi bellek içi SQLite'ta gerçekten çalıştırır).

## Muhasebe Yer Tutucu Sayfaları (v435 → v439)

> **Stok grubunun sayfaları (`stok_kartlari` / `stok_tanimlari` + 4 fiş sayfası `stok_giris/cikis/donusum_fisleri`, `transfer_fisleri`) çalışan modüldür (v524; `stok-modulu` skill'i)**; `kantar_fisleri` 302 kalır (kantar yalnız "Kantardan doldur" ile SALT okunur). `nav_muhasebe_static_smoke` `stok_core` içerenleri ayrı denetler.
> **Fatura grubunun 5 sayfası (`satis_faturalari` · `alis_faturalari` · `sevk_irsaliyeleri` · `alinan_siparis_fisleri` · `fatura_tanimlari`) çalışan modüldür (v527; `fatura-modulu` skill'i)**; `alinan_siparis_fisleri` artık 302 DEĞİL (Ürün Siparişleri `urun_siparis.php` ayrı kalır). `nav_muhasebe_static_smoke` `fatura_core` içerenleri ayrı denetler.
> **Kasa grubunun 2 sayfası (`kasa_fisleri` · `kasa_tanimlari`) ve Çek-Senet grubunun 3 sayfası (`cek_dekontu` · `senet_dekontu` · `cek_tanimlari`) çalışan modüldür (v528; `kasa-modulu` / `cek-senet` skill'leri)**; kapı yine `is_admin()`. `nav_muhasebe_static_smoke` bunları `kasa_core` / `ceksenet_core` içerenler olarak ayrı denetler.
> **Cari İşlemler grubunun 4 sayfası (`cari_kartlari/fisler/dekontlar/tanimlari`) artık ÇALIŞAN modüldür** (yer tutucu değil; `cari-modulu` skill'i) — kapı yine `is_admin()`; `nav_muhasebe_static_smoke` bunları `cari_core` içerenler olarak ayrı denetler.

Sidebar'daki Cari İşlemler vb. gruplar (yalnız `is_admin()`) `nav_muhasebe_gruplari()` (config/helpers.php) TEK kaynağından beslenir; yeni sayfa BURAYA eklenir. Her sayfa `require_login()` + `is_admin()` kapısı taşır; içerik yoksa "Bu bölüm hazırlanıyor." kartı. **v439: mevcut klonu olan 10 sayfa 302 ile o sayfaya yönlendirir** (kapı kalır): kantar_fisleri, mal_kabul_fisleri → `kantar.php` · veritabani_islemleri → `admin_db_backups.php` · kullanici_tanimlari → `users.php` · depo_tanimlari → `definitions.php?type=depo` · raporlar_listeler → `reports.php` · arac_yukleme_fisleri → `records.php` · ihracat_faturasi_maliyeti → `maliyet.php` · beyanname_siparis_proforma → `beyanlar.php` · sube_degistirme → `depo_sec.php`. **`palet_fisleri.php` v530'da yönetim tablosuna dönüşmüştür:** kayıtlar en yeniden eskiye listelenir, klavye ile seçilip düzenlenebilir/kopyalanabilir/silinebilir; açıklama alanları `loading_pallets.aciklama1/aciklama2`, müstahsil `mustahsil_id` (cari kartı) + `mustahsil_ad` (ünvan kopyası) kolonlarındadır. **v535: bu kolonlar yalnız `migrate.php` › "Palet Fişleri" kartından eklenir (`config/palet_fisleri_core.php`); sayfa ALTER ATMAZ, kolon yoksa ilgili alanı pasif gösterir.** F2 - Ekle penceresinde müstahsil cari kartlarından aranır; yeni müstahsil kartı Cari modülünün TEK yazma ucu `cari_kaydet.php` ile (tedarikçi tipi) açılır — sayfa cari tablolarına doğrudan yazmaz. **v536: araç çubuğu çalışır** — Çoğalt (Ctrl+Shift+D) · Sil (F3/Delete, onaylı) · Bul (listede süz) · Düzelt (F4, Ekle penceresi düzenleme modunda; müstahsil yalnız alan etkinse değişir) · Parti Özeti (partiye göre toplam, satıra git) · Yazdır (gizli çerçeve) · WhatsApp (seçili parti özeti, wa.me) · Excel (CSV+XLSX, `reports.export` + `export_audit`); filtre: `?baslangic=`/`?yuklenen=1` (varsayılan son 90 gün, `durum='yuklendi'` gizli). **v537: düzen** — tek mavi bant (tarih + yüklenen + butonlar; Getir yok, tarih/kutu değişince otomatik yenilenir); satıra çift tık = Düzelt; alttaki kartlara tıklayınca partinin palet listesi; WhatsApp/Parti Özeti düğmeleri kaldırıldı; sol üst köşeye çift tık = sütunları içeriğe göre daralt, başlık kenarından sürükleyerek genişlik. **Sütun genişliği + sırası KULLANICI BAZINDA sunucuda** (`kullanici_tercihleri`, `config/kullanici_tercih.php`; tablo yalnız migrate.php › "Kullanıcı Tercihleri" kartından, yoksa tarayıcıya düşer). **v538: Bul** — Boşluk tuşu ya da Bul düğmesi, SEÇİLİ HÜCRENİN sütununda filtre penceresi açar (Başlayan · İçinde geçen · Eşit Olan · İle Başlamayan · İçinde Geçmeyen · Akıllı arama · Boş Olan · Dolu Olan · Eşit Olmayan; her sütunda bir filtre, sütunlar birlikte; alttaki çipin × işareti kaldırır). Seçili hücre kendi sütununda mavi renklenir. **Ambalaj** sütunu (kasa cinsi adı). **Mobil (<768px): sayfa kaymaz** (yükseklik `fitPage` ile alt çubuğun üstünde biter; yalnız tablo kayar), özet kartları tek satır + kısa etiket (Parti/Palet/Amb./Brüt/Dara/Net/Ort.); alttaki ipucu yazısı kaldırıldı. **Koyu tema:** style.css'te `--surface` YOKTUR; sayfa kendi `--pf-*` belirteçlerini (açık + `html[data-theme="dark"]`) taşır, yeni renk eklerken `var(--surface,#fff)` KULLANMA. **v539: Sil iki aşamalı** — ilk Sil (düğme/F3/Delete) satırı kırmızı yapar, AYNI satırda ikinci Sil gerçekten siler (tarayıcı onay penceresi yok); başka satıra geçmek/Esc/Düzelt işareti kaldırır. **Çoğalt** seçili satırı hemen kopyalar (yeni belge no) ve kopyayı Düzelt penceresinde açar (değiştirip Güncelle; kapatılırsa kopya kalır). **v540: Sil** ikinci basışta ayrıca "silinsin mi?" onayı sorar (kırmızı satır + onay). **Ekle/Düzelt penceresi:** açıklama paragrafı ve yer tutucular kaldırıldı; açılır liste odaklanınca değil YAZINCA açılır; **Dara otomatik** = kasa adedi × kasa darası + palet darası (`material_definitions.unit_dara_kg`, Yükleme Planı ile aynı formül; alan salt okunur, Düzelt açılışında kayıtlı dara korunur, palet/kasa/adet değişince yeniden hesaplanır); "Kantardan al" düğmesi gizli (`hidden`); mobilde (<700px) pencere tam ekran, alanlar 6 sütunlu ızgarada (`pf-w2/w3/w4/w6`), alt çubuk sabit. **v541: yeni sütunlar** Tarih · İşlem (Eklendi/Çoğaltıldı/Düzeltildi) · İşlem Tarihi · Kullanıcı (işlemi yapan) · Şube (paletin `depo` damgası): İşlem/Tarih/Kullanıcı `audit_log`'tan (module=`palet_fisleri`, paletin SON create/update kaydı; `users.display_name`) okunur, şema değişmez; bu sayfadan hiç işlem görmemiş paletlerde (Yükleme Planı'ndan gelenler) boş kalır. Excel/CSV sonuna da eklenir. Pencerede seçilen palet/ambalajın birim darası kutunun yanında yazar; Brüt/Dara/Net kutuları belirgin zeminli. **v542: sütunlar** sol üst köşe artık tıklanmaz (çift tık işlevi kaldırıldı); başlık kenarındaki tutaca (sütun birleşimi) ÇİFT TIK tüm sütunları içeriğe göre daraltır, sürükleme genişliği ayarlar. **Sütun taşıma:** başlığı tutup sürükle (fare 6px, dokunmatik 380ms basılı tutma); yarı saydam hayalet başlık imleci izler, taşınan sütun soluklaşır, bırakılacak yer mavi çizgiyle gösterilir, sütun YALNIZ bırakınca taşınır (Esc: vazgeç; kenarda tablo yatay kayar). **Sil (v543):** ilk Sil satırı KIRMIZI işaretler ve başka satıra geçilse de kırmızı KALIR; işaretli satırda ikinci Sil (düğme/F3/Delete) Sil · Geri Al · İptal penceresini açar (Sil: siler · Geri Al: kırmızılık kalkar · İptal/Esc: kırmızı kalır; tarayıcı onay penceresi yok). **v543:** sol köşe/şerit sütunu tamamen kaldırıldı (tablo kenarda başlar). **Başlığa çift tık → Sütunlar penceresi:** tüm sütunlar listelenir, işaret kutusuyla gösterilir/gizlenir (anında uygulanır, en az bir sütun görünür kalır, 'Tümünü göster'); gizli sütunlar KULLANICI BAZINDA `kullanici_tercihleri` içinde `gizli` listesinde saklanır (`kt_dogrula` beyaz liste). Gizli sütunlar ok tuşu gezinmesi, yazdırma, parti listesi ve sütun taşımada atlanır; Excel/CSV tüm sütunları verir. Test: `php scripts/palet_fisleri_ekle_smoke.php`. Diğerleri yer tutucu kalır. `nav_aktif_anahtar()` bu sayfalarda dosya slug'ı döndürür. `sw.js` `SW_MUHASEBE_RE` bu adları önbelleğe almaz (stok.php/kantar.php vb. kapsanmaz). Test: `php scripts/nav_muhasebe_static_smoke.php`.

## Önemli Desenler

> **Fiş Excel'i (v446):** yüklenen dosyayı diske kaydetme; okuma `ReadDataOnly`, `getCalculatedValue` YOK; Excel'den gelen serbest metni `pdks_fis_metin()` ile temizle; fiş yazmak için `pdks_faz8j_toplu_ekle` dışında yol açma.

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
