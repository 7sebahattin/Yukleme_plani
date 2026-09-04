# Asya Fresh — Claude Code Proje Hafızası

## Project Summary

PHP 8 + MySQL tarım ihracat operasyon yönetim sistemi. Mobil öncelikli, PWA kurulabilir.
Çerçeve yok — saf PHP, vanilla JS, tek CSS (`assets/style.css`), tek JS (`assets/app.js`).

**Canlı:** `nuverna.derspros.com.tr`  
**Branch:** `claude/fix-records-print-mobile-WuKdT`  
**SW Cache:** `yukleme-plani-v196` (sw.js — değişiklikte artır; `config/helpers.php`'deki `APP_SURUM` ile aynı sayıda tut)

---

## Dosya Haritası (Kritik Dosyalar)

```
/
├── index.php              # Ana sayfa — kart grid
├── records.php / cikmalar.php   # Yükleme / Çıkma listesi
├── record_view.php        # Görüntüleme + yazdırma
├── record_create/edit.php # Form sayfaları
├── kantar.php / kantar_view.php / kantar_raporu.php
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
├── beyanlar.php / beyan_view.php / beyan_create.php / beyan_edit.php
├── api_beyan_bildirim.php # Beyan → Hal Kayıt köprüsü (JSON)
└── halkayit/              # Hal Kayıt (HKS) modülü
    ├── index.php          # panel çerçevesi + app.php'yi iframe'e gömer
    ├── app.php / app.html # SPA kabuğu + SPA (tek dosya)
    ├── api.php            # JSON yönlendirici (action=...)
    ├── taslak_lib.php     # TASLAK YAZMANIN TEK YOLU + bildirim doğrulama
    ├── hks_soap.php / config.php / db.php
    └── .htaccess          # include-only PHP dosyalarına web erişim kapalı
```

**docs/ referans:** `@docs/ARCHITECTURE.md` · `@docs/SECURITY_NOTES.md` · `@docs/NEXT_TASKS.md` · `@docs/DEPLOY_WORKFLOW.md`

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
- **"Canlıya al" = PR açıp `main`'e merge et** — bkz. `@docs/DEPLOY_WORKFLOW.md`. Bu, sunucuya OTOMATİK yansımaz; `scripts/deploy.php` SSH'dan elle çalıştırılmalı (Claude'un SSH erişimi yok, her seferinde kullanıcıya hatırlat).

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
| hesap.delete/admin | ✓ | — | — | — |
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

## Beyan → Hal Kayıt Bildirimi (Sprint Beyan-Bildirim-01)

Beyan ekranındaki **"🏛 Bildirim Yap"** butonu, beyandaki veriden bir **HKS taslağı**
açar. Gönderim yapmaz.

**Dosyalar:** `api_beyan_bildirim.php` (JSON uç: `hazirla` / `taslak_olustur`) ·
`halkayit/taslak_lib.php` (ortak kütüphane) · `beyan_view.php` (buton + modal + geçmiş).
**Tablolar:** `beyan_hks_bildirim` (bağ + geçmiş) · `hks_eslesme` (öğrenilen eşlemeler) ·
`customs_declarations.vehicle_plate` + `.hks_durum` (yeni kolonlar).
**Test:** `php scripts/beyan_bildirim_smoke.php` (ağsız, DB'ye yazmaz).

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
- **Buton kapısı:** plaka dolu + durum uygun + net KG > 0 + aktif bildirim yok +
  **çift yetki** (`beyan.write` **ve** `records.write`). Kapalıysa sebebi yazılır.
- **1 beyan = 1 ürün = 1 bildirim.** Aktif (`taslak`/`gonderildi`) bağ varsa ikincisi
  açılmaz. İki ürünlü gümrük beyanı sisteme **iki ayrı beyan** olarak girilir.
- **Net KG zorunlu, brüte düşülmez** — rüsum net üzerinden hesaplanır.
- **Modalde "alım" türleri YOK** (`bb_alim_turu_mu`). Satın Alım / Üreticiden Sevk
  Alım REFERANSSIZ bildirimdir — malın tam tanımını ister, plan taslağı bunu
  taşıyamaz; ayrıca HKS "İhracat" sıfatıyla Üreticiden Sevk Alım'ı reddediyor.
  Alım bildirimi Hal Kayıt panelinden yapılır; kuralların hepsi orada duruyor.
- **Sıfat/tür varsayılanı KURAL TABANLI** (`bb_varsayilanlar` — sıfat "İhracat",
  tür "Satış"): `app.html`'deki `listeleriUygula` kuralının aynasıdır, **ikisini
  birlikte değiştir**. "Son kullanılan"dan OKUNMAZ — `hks_kv.sonlar_<firmaId>`
  yalnız plaka/ülke/ürün/karşı taraf tutar, sıfat ve tür orada yoktur.
  Karşılığı bulunamazsa boş döner; **asla id uydurulmaz**.
- `beyan_view.php`'deki hızlı durum geçişi formu tüm alanları hidden gönderir;
  **yeni kolon eklersen o listeye de ekle**, yoksa her durum değişikliğinde silinir.

---

## Hesap Modülü — Durum Makinesi (Sprint Hesap-01)

Personel masraf takibi. **Çekirdek: `config/hesap_calc.php`** — şema migrasyonu, tutar
ayrıştırma, durum makinesi, bakiye hesabı, yetki kapısı.

**Sayfalar:** `hesap.php` (pano) · `hesap_liste.php` · `hesap_kayit.php` ·
`hesap_muhasebe.php` (onay kuyruğu) · `hesap_durum.php` (geçiş JSON uç noktası) ·
`hesap_yazdir.php` · `hesap_export.php` · `hesap_muhasebe_fis_pdf.php` · `hesap_sil.php`.

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
- **Görünürlük:** `hesap_row_visible()` / `hesap_owner_sql()` — normal kullanıcı yalnız
  kendi + sahipsiz kayıtları görür; `hesap.approve`/`hesap.admin` tümünü görür.
  Depo filtresi `depo_sql_in('depo')`.
- **Atanmamış veri:** `user_id IS NULL` ve `depo=''` eski kayıtlar herkese görünür kalır.
- Test: `php scripts/hesap_smoke.php` (bellek içi SQLite, canlı DB'ye dokunmaz).

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
- [ ] SW cache versiyonu artırıldı mı? (style.css veya kritik dosya değiştiyse) — `config/helpers.php`'deki `APP_SURUM` sabitini de AYNI sayıya çek (sidebar altında gösterilir).

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
| Sidebar görünmüyor | SW eski CSS'i cache'den sunuyor | Hard refresh (Ctrl+Shift+R) + SW versiyonu artır |
| CSRF JSON endpoint 400 dönüyor | Eski `csrf_check` plain-text die() | Güncel `csrf_check()` JSON-aware — 403+JSON döner |
