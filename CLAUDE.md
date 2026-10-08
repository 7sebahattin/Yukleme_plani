# Asya Fresh — Claude Code Proje Hafızası

## Project Summary

PHP 8 + MySQL tarım ihracat operasyon yönetim sistemi. Mobil öncelikli, PWA kurulabilir.
Çerçeve yok — saf PHP, vanilla JS, tek CSS (`assets/style.css`), tek JS (`assets/app.js`).

**Canlı:** `asya.scai.tr` (2026-09-27'den beri) · **Test:** `nuverna.derspros.com.tr` (ayrı DB; `derspros.com.tr` 25.12.2026'da bitiyor, yenilenmeyecek)  
**Branch:** `claude/fix-records-print-mobile-WuKdT`  
**SW Cache:** `yukleme-plani-v308` (sw.js — değişiklikte artır; `config/helpers.php`'deki `APP_SURUM` ile aynı sayıda tut)

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
| PDKS / Personel / Çavuş | `personel*` · `isci_kartlari` · `isci_tipleri` · `gunluk_*` · `cavus*` · `mesai_*` · `manuel_cikis` · `giris_cikis` · `pdks_nfc_test` | `pdks*.php` (`pdks`, `pdks_gunluk`, `pdks_hakedis`, `pdks_cari`, `pdks_rapor`, `pdks_faz8*`, `pdks_faz9d`) | `pdks.css` · `pdks.js` · `print_pdks.css` | `pdks_*_smoke` |
| Raporlar | `reports` · `raporlar` · `rapor_malzeme` · `rapor_yazdir` · `kantar_raporu` | `xlsx_export` | — | `rapor_malzeme_xlsx_smoke` |
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

### Kart Hareketleri Sıralaması + Kapanış Notu (v299)

- **Sıralama:** Mesai Detayı "Kart Hareketleri" varsayılan sırası SUNUCUDA, yalnız `gunluk_isci_puantaj_detay.php`'de `usort`: son işlem (çıkış varsa çıkış, yoksa giriş) DESC, beraberlikte `period_id` DESC. Paylaşılan `pdks_gunluk_faz8a_oturum_donemleri()` SQL'i (ASC) Gün Sonu Fişi ile ortaktır, KRONOLOJİK kalır — ona sıralama ekleme.
- **Başlık sıralaması TEK yerde:** `config/pdks_liste_ui.php`. `<table data-pdks-sirala>` + `<th data-sirala="metin|sayi|zaman">` içinde GERÇEK `<button>` + hücrede ham `data-sirala-deger`; artan → azalan → varsayılan, boşlar her iki yönde sonda, metin `tr` doğal (K2 < K10), aktif `th`'de `aria-sort`. Mobil karşılığı `select[data-pdks-sirala-sec]` + `data-sd-*`. Yeni tabloda ayrı script YAZMA. Test: `PUANTAJ_FAZ8B=1 PUANTAJ_SIRALA=1 php scripts/pdks_puantaj_dialog_render.php > _test_sirala.html` + `PUANTAJ_SIRALA=1 PUANTAJ_KAPALI=1 … > _test_kapanis_notu.html` → `node scripts/pdks_sirala_smoke.js`.
- **Kapanış notu düzenleme:** `pdks_gunluk_oturum_not_guncelle()` (config/pdks_faz8h.php) — YALNIZ admin (sahip kararı), mesai aktif depoda, YALNIZ `status='closed'` (her tarih), gerekçe yok, boş → NULL, ≤1000 karakter, tek tx + `FOR UPDATE`, audit `daily_session_note_edit`. Hakediş/cari bu kolonu okumaz; hakediş tablolarına/`needs_recalculation`'a DOKUNMAZ (kesin hakedişte de çalışır) — gövdeye ekleme (test denetler). Ekran: kapalı mesaide "✏ Notu Düzenle" → native `<dialog id="kapanisNotu">`, POST `action=kapanis_notu` + CSRF, mesai id'si sunucudan. Test: `php scripts/pdks_kapanis_notu_smoke.php`.

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
- **Beyan formunda plaka `beyan_hks_form_bolumu()` İÇİNDEDİR**, "Temel
  Bilgiler"de değil — bildirim için gereken dört alan tek yerde. Katalog boş
  olsa bile çizilir (plaka katalogdan bağımsız). **İkinci bir `vehicle_plate`
  alanı açma** — aynı `name` ile iki alan POST'ta çakışır.
  Bölüm **WhatsApp Metni'nin hemen altındadır** (iki formda da aynı sıra).
- **Yeni Beyan görsel katmanı** (`form.bf` — style.css "YENİ BEYAN FORMU" bloğu):
  yalnız `beyan_create.php`'ye uygulanır; Düzenle formu ve `beyan_view` eski
  görünümde kalır. Kancalar: bölümde `data-bf="wa|hks|temel|urun|lojistik|durum"`
  (renk), başlıkta ve her `.form-group`'ta `data-ic="<simge>"` (CSS mask — rengi
  temadan gelir; simge adları blok sonundaki listede). Başlık emojisi
  `.bf-emoji` içinde durur ve bu formda gizlenir — `beyan_hks_form_bolumu()`
  ortak olduğu için Düzenle'de emoji aynen görünür. **Yeni alan eklerken
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
  "⏳ Yüklenmeyen Beyanlar"** (`.beyan-blok-acik`), **ALTTA "✅ Yüklenen
  Beyanlar" + filtre şeridi** (`.beyan-blok-kapanmis`). Bölümü DURUM belirler:
  `beyan_kapali_durumlar()` (`yuklendi`/`iptal`/`red`) alta, geri kalan HER
  durum üste. Yeni bir durum eklersen o listeyi gözden geçir — listede
  olmayan durum ÜST bölüme düşer (güvenli varsayılan: gözden kaçmaz).
  - **Üst bölüm filtreden ve sayfalamadan BAĞIMSIZ.** Orası işlem bekleyenlerin
    TAM listesi; bir arama ya da sayfa geçişi onu eksiltirse kullanıcı yapılacak
    işi göremez. Filtreye bağlama.
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
  - Üst bölümün emniyet supabı `BEYAN_BEKLEYEN_LIMIT` (200); aşılırsa ekrana not
    düşer. Alt bölüm `BEYAN_PER_PAGE` ile sayfalanır.
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
  - **`status_only` KULLANMA.** Şerit `beyan_edit.php`'nin **tam güncelleme**
    dalına POST eder; oradaki doğrulama yalnız `beyan_statuses()` anahtarlarına
    bakar, dolayısıyla doğrudan geçiş kabul edilir. `status_only` dalında
    `beyan_next_statuses()` kapısı var (liste ekranının "Yüklendi" butonu onu
    kullanır) ve doğrudan geçişleri reddeder. O dala kapı EKLEME.
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
- **Toplam kilo/adet yalnız BAŞARILI satırlardan (v308):** `hks_gonderilenler.toplam_kg`/`adet` eskiden gönderilen TÜM
  satırları topluyordu → 3 künyeden 1'i hatalıysa liste 19.500 yerine hatalıyı da içeren toplamı gösteriyordu.
  Yeni kayıtta `taslak_gonder` `hks_basarili_ozet()` ile yalnız künyesi oluşan satırları yazar; ESKİ kayıtlar listede
  `hks_gonderilen_gercek_toplam()` ile `veri.sonuclar`'dan yeniden hesaplanır (yalnız hatalı satır varsa; DB değişmez,
  migration yok). Eski kayıttan "Tekrar gönder" tohumu da aynı kg'ı kullanır. Başarılı ölçüt `hks_sonuc_basarili_mi()`.
- **"Panel sürümü" damgası KALDIRILDI (v306):** ana menü altındaki `panel sürümü: …` yazısı ve `PANEL_SURUM` sabiti
  gitti; tek sürüm kaynağı sidebar'daki `APP_SURUM`. Geri ekleme (test ana menüde yazının YOKLUĞUNU denetler).
- **İstemci akışı** (`gonderilenTekrar()`): tohum ÖNCE aktif firmayla istenir → başarılıysa ve firma
  değişiyorsa `firmaSec(hedef)` → `taslakDuzenle(tohum, {kopya:true, notlar})`. Kopya kipinde
  `duzenlenenTaslakId = null`: `eskiTaslakId` GİTMEZ, hiçbir taslak SİLİNMEZ. Karşı taraf yine
  "Doğrula"dan geçer. Menü `#gTekrarMenu` (position:fixed, z 620), firma penceresi `#firmaSecPencere` (z 600,
  aktif firma hariç). Lib `taslak_lib.php`'yi require ETMEZ (test edilebilirlik; api.php yükler).
- Test: `php scripts/hks_tekrar_gonder_smoke.php` · `node scripts/hks_gonderilenler_smoke.js`
  (`GSHOT=<klasör>` ekran görüntüsü kaydeder).

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
