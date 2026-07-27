# Asya Fresh — Claude Code Proje Hafızası

## Project Summary

PHP 8 + MySQL tarım ihracat operasyon yönetim sistemi. Mobil öncelikli, PWA kurulabilir.
Çerçeve yok — saf PHP, vanilla JS, tek CSS (`assets/style.css`), tek JS (`assets/app.js`).

**Canlı:** `nuverna.derspros.com.tr`  
**Branch:** `claude/fix-records-print-mobile-WuKdT`  
**SW Cache:** `yukleme-plani-v161` (sw.js — değişiklikte artır)

---

## Dosya Haritası (Kritik Dosyalar)

```
/
├── index.php              # Ana sayfa — kart grid
├── records.php / cikmalar.php   # Yükleme / Çıkma listesi
├── record_view.php        # Görüntüleme + yazdırma
├── record_create/edit.php # Form sayfaları
├── kantar.php / kantar_view.php / kantar_raporu.php
├── tarama.php             # Görselden metin (OCR) — tek dosya, DB kullanmaz
│                          # assets/ocr/ = tarayıcı içi OCR motoru (tesseract.js)
├── stok.php / malzeme_stok.php
├── reports.php / hesap.php
├── definitions.php / users.php / audit.php
├── logout.php
├── sw.js / manifest.json  # PWA
├── config/
│   ├── db.php             # PDO + auto-migration
│   ├── helpers.php        # render_header/footer, render_desktop_sidebar,
│   │                      # csrf_check, audit_log_event, can(), is_admin()
│   ├── auth.php           # require_login, session
│   └── calc.php           # Dara/net hesaplama
├── assets/
│   ├── style.css          # TEK CSS — tüm stiller + sidebar + breakpoints
│   └── app.js             # TEK JS
└── hks/                   # Hal Bildirimi modülü
    ├── index.php / api_send.php
    ├── HksClient.php / HksRepository.php / helpers.php
    └── .htaccess          # include-only PHP dosyalarına web erişim kapalı
```

**docs/ referans:** `@docs/ARCHITECTURE.md` · `@docs/SECURITY_NOTES.md` · `@docs/NEXT_TASKS.md`

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
| users.admin | ✓ | — | — | — |
| is_admin() | ✓ | — | — | — |

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
| Kalan modal (#kalanModal) | 1000 |
| Etiket/Crop overlay | 3000 |

**Yeni modal eklerken z-index ≥ 600** kullan.

### Overflow Kuralı — KRİTİK

```css
html { overflow-x: clip; }   /* DOĞRU — iOS scroll korur */
/* html { overflow-x: hidden; }  YANLIŞ — iOS dikey scroll kilitler */
```

### iOS Safe Area

```css
.container { padding-bottom: calc(80px + env(safe-area-inset-bottom, 0px)); }
.bottomnav { padding-bottom: calc(6px + env(safe-area-inset-bottom, 0px)); }
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
hks_notifications   -- Hal Bildirimi taslakları
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
- **Depo değiştirme:** topbar `.depo-badge` + sidebar `.sidebar-depo` → depo_sec.php. Audit: `depot_switch`.
- **Atanmamış veri kuralı:** Deposu BOŞ kayıt/fiş/hareket TÜM depolarda görünür ve erişilebilir (filtreler `IN(aktif depo) OR depo=''`). Depo özelliği hiçbir eski veriyi kaybetmez/kilitlemez. Tekil görüntüleme guard'ları da boş depoyu geçirir; yalnız GERÇEK başka depoya ait kayıt 403 verir (mesaj hangi depo olduğunu söyler).
- **Eski depo'suz veri:** `depo_tasima.php` (yalnız admin, onaylı GO butonu) boş depolu satırları hedef depoya taşır — atanınca yalnız o depoda görünür.
- **Depo listesi kaynağı:** `material_definitions type='depo'` (`depot_options()` = tanımlar ∩ `user_depolar` ataması).
- **Harf-duyarsızlık:** Depo eşleşmeleri TR-duyarsız (`depo_fold`/`depo_in_allowed`/`depot_visible_to_user`). "KARAMAN CİHAT" == "Karaman Cihat" — liste (MySQL ci) ile tekil guard tutarlı.
- **Depo adı yayılımı:** Depo tanımı adı değişince `sync_depot_name_in_data()` tüm depo kolonlarını (loading_pallets/kantar_fisleri/material_stock_movements/stock_counts/customs_declarations.exit_depot) yeni yazıma çeker (definitions.php update + audit `depot_rename_sync`). Mevcut uyumsuzluk için: `depo_tasima.php` → "🔤 Depo Adlarını Eşitle" butonu (audit `depot_sync_all`).
- **Depo rengi (Sprint Depo-02):** `material_definitions.color` (VARCHAR7, nullable). `depot_color($name)` → admin renk seçtiyse onu, seçmediyse isimden türetilen sabit palet rengini döner (`depot_color_palette()`). `render_header()` aktif depo varsa `<body style="--depot-accent;--depot-accent-rgb;--depot-accent-text">` enjekte eder — sidebar sol şerit/marka alt çizgi/`.sidebar-depo`, topbar `.depo-badge` + alt şerit, mobil `.bottomnav` üst şerit hep bu değişkeni kullanır; depo değişince otomatik güncellenir. Renk seçici: `definitions.php` sol panelde depo türü seçiliyken görünür (`color_reset=1` → otomatik palete dön).

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

## Tarama (OCR) Modülü

Görselden metin çıkarma aracı. **Tek dosya:** `tarama.php` (PHP + HTML + CSS + JS içinde;
`style.css`/`app.js`'e dokunulmaz — CSS, style.css değişkenlerini kullanır → açık/koyu tema otomatik).

**Veritabanı kullanmaz** — tablo/migration yok, hiçbir şey kaydedilmez.
Yetki: `records.read` (`require_perm`), erişim depo seçimine tabidir (`require_login`).

**Akış:** dosya seç / sürükle-bırak / Ctrl+V yapıştır / mobilde kamera → `Tara` →
OCR → metin sayfada (düzenlenebilir) → `.txt` veya `.xlsx` indir.

### İki motor — otomatik seçim (`$engine`)

| Motor | Koşul | Davranış |
|---|---|---|
| `server` | `tesseract` binary'si var **ve** `shell_exec` açık | Görsel POST edilir, sunucu tarar (hızlı, indirme yok) |
| `browser` | Sunucu olmaz, `assets/ocr/` dosyaları yerinde | tesseract.js (WASM) tarayıcıda tarar — **görsel sunucuya hiç gitmez** |
| `none` | İkisi de yok | Kırmızı uyarı, `Tara` pasif |

**Canlı sunucu (paylaşımlı hosting) `browser` modunda çalışır** — `shell_exec`
`disable_functions` içinde ve tesseract kurulu değil. Sunucuya tesseract kurulursa
sayfa kod değişikliği olmadan `server` moduna geçer.

**Uç noktalar (aynı dosya, POST):**
- `action=ocr` → JSON (yalnız `server` modunda kullanılır; XHR ile ilerleme çubuğu)
- `action=export` + `format=txt|xlsx` → dosya indirme (her iki motorda da sunucu üretir)

**Tesseract (server modu):** yol sırası `TESSERACT_BIN` sabiti (config/local.php) → env →
`command -v` → `/usr/bin`, `/usr/local/bin`. Diller `--list-langs` ile okunur.
Sunucu kurulumu: `apt-get install tesseract-ocr tesseract-ocr-tur`.

**tesseract.js (browser modu):** dosyalar `assets/ocr/` altında, **CDN yok** —
bkz. `assets/ocr/README.md` (sürümler + güncelleme komutları). Dikkat edilecekler:
- `corePath` **dizin değil tam dosya yolu** verilir; SIMD desteği `WebAssembly.validate`
  ile ölçülüp `tesseract-core-simd-lstm.wasm.js` / `tesseract-core-lstm.wasm.js` seçilir.
  Dizin verilirse kütüphane repoda olmayan `relaxedsimd` varyantını ister.
- Ayrı `.js` + `.wasm` ikilisi **çalışmaz** (worker blob URL'den koştuğu için kardeş
  `.wasm` çözülemiyor) — gömülü `.wasm.js` varyantı kullanılır.
- Kütüphane dil indirme hatasını `createWorker` promise'ine iletmez → `errorHandler`
  + 90 sn ilerleme bekçisi (`tjsFail`/`tjsLastTick`) ile hata kendi zincirimize aktarılır.
  Bunları kaldırma: aksi hâlde eksik dosyada ilerleme çubuğu sonsuza kadar döner.
- Worker dil değişmedikçe yeniden kullanılır (2. tarama ~0,3 sn).
- `sw.js` `assets/ocr/` için **cache-first** — MB'lık dosyalar her taramada yeniden
  doğrulanmasın, çevrimdışı da çalışsın.

**Sınırlar / güvenlik:**
- 10 MB üst sınır; `server` modunda `upload_max_filesize`/`post_max_size` daha düşükse
  UI gerçek sınırı yazar (`browser` modunda görsel yüklenmediği için PHP sınırları geçmez).
- `post_max_size` aşımında PHP `$_POST`'u boşaltır → dosyanın başındaki 413 guard'ı JSON döner (silme).
- Tür doğrulaması uzantıdan değil `getimagesize()` ile: JPG/PNG/TIFF/BMP/WEBP.
- Dil ve `--psm` yalnız beyaz listeden; tüm yollar `escapeshellarg`; `timeout` varsa 120 sn sınır.
- Geçici dosya `sys_get_temp_dir()` (yazılamazsa `storage/tmp`), `finally` içinde **her durumda** silinir.
- Excel çıktısı `DataType::TYPE_STRING` ile yazılır (formül enjeksiyonu olmaz); PhpSpreadsheet
  yoksa CSV'ye düşer (BOM + `;`, formül karakteriyle başlayan satıra `'` öneki).

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
- [ ] Sidebar aktif link tespiti güncellendi mi? (`render_desktop_sidebar` içinde `$a_*` değişkenleri)
- [ ] Bottomnav aktif sekme güncellendi mi? (`render_footer` içinde `$is_*`)
- [ ] Input mobilde 16px font-size alıyor mu?
- [ ] Yeni tablo `.table-wrap` içinde mi?
- [ ] Print'te görünmemesi gerekenler `@media print { display:none }` içinde mi?
- [ ] Yeni DB kolonu/tablosu varsa migrasyon eklendi mi?
- [ ] Permission kontrolü var mı?
- [ ] Audit logu var mı?
- [ ] SW cache versiyonu artırıldı mı? (style.css veya kritik dosya değiştiyse)

---

## Yaygın Hatalar

| Hata | Sebep | Çözüm |
|---|---|---|
| Dikey scroll çalışmıyor | `overflow-x: hidden` html'de | `overflow-x: clip` kullan |
| Dropdown overflow'da kesiyor | `overflow: auto` stacking context | `position: fixed` + `getBoundingClientRect()` |
| Dara toplamı 1 eksik | Per-palet yuvarlama | Sadece toplamda yuvarla |
| `type` kolonu bulunamadı | Auto-migration çalışmadı | `migrate.php?run=1` veya phpMyAdmin ALTER TABLE |
| SQLSTATE[HY093] | PDO named param tekrar kullanıldı | Pozisyonel `?` kullan |
| Sidebar görünmüyor | SW eski CSS'i cache'den sunuyor | Hard refresh (Ctrl+Shift+R) + SW versiyonu artır |
| CSRF JSON endpoint 400 dönüyor | Eski `csrf_check` plain-text die() | Güncel `csrf_check()` JSON-aware — 403+JSON döner |
