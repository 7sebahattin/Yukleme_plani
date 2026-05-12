# Yükleme Planı — Geliştirici Kılavuzu (CLAUDE.md)

## Proje Özeti

PHP 8 + MySQL web uygulaması. Mobil öncelikli, PWA olarak kurulabilir.
Çerçeve yok — saf PHP, vanilla JS, tek CSS dosyası.

**Canlı:** `nuverna.derspros.com.tr`  
**Branch:** `claude/fix-records-print-mobile-WuKdT`

---

## Dosya Haritası

```
/
├── index.php              # Ana sayfa (kart grid)
├── records.php            # Yükleme listesi
├── cikmalar.php           # Çıkma listesi
├── record_create.php      # Yeni yükleme formu
├── cikma_create.php       # Yeni çıkma formu
├── record_edit.php        # Düzenleme
├── record_view.php        # Görüntüleme + yazdırma
├── record_delete.php      # Silme onayı
├── record_new.php         # Kayıt türü seçim sayfası
├── kantar.php             # Kantar hesaplama modülü
├── definitions.php        # Malzeme tanımları
├── _form.php              # Kayıt formu (create+edit ortak)
├── manifest.json          # PWA manifest
├── sw.js                  # Service worker
├── migrate.php            # Tek seferlik DB migrasyonu (üretimde silin)
│
├── config/
│   ├── db.php             # PDO bağlantısı + otomatik migrasyon
│   ├── helpers.php        # render_header/footer, yardımcı fonksiyonlar
│   └── calc.php           # Dara/net hesaplama mantığı (PHP)
│
└── assets/
    ├── style.css          # TEK CSS dosyası — tüm stiller burada
    ├── app.js             # TEK JS dosyası — tüm client-side logic
    ├── icon.svg           # PWA ana ikon
    └── icon-maskable.svg  # PWA maskable ikon
```

---

## Mobil Mimari — BOZMA

### Bottom Navigation (≤767px)

`render_footer()` içinde üretilir (`config/helpers.php`).  
5 sekme: Ana Sayfa · Yüklemeler · ⊕ Yeni · Çıkmalar · Tanımlar

```
Aktif sekme tespiti: basename($_SERVER['PHP_SELF'])
CSS sınıfı: .bottomnav-item.active
```

**Yeni bir sayfa eklerken:**
- `render_footer()` içindeki `$is_*` değişkenlerini güncelle
- Yeni sayfanın hangi sekmeye ait olduğuna karar ver

### CSS Breakpoint'leri

| Breakpoint | Davranış |
|---|---|
| `< 768px` | Mobil: bottom nav görünür, top nav gizli |
| `≥ 720px` | Grid 3 kolon, bazı tablo başlıkları |
| `≥ 1024px` | PC: tablo görünümü, palet satır tablosu |

### Overflow Kuralı — KRİTİK

`overflow-x: hidden` → **html elementinde KULLANMA**.  
iOS Safari'de dikey scroll'u da kilitler.

```css
/* DOĞRU */
html { overflow-x: clip; }   /* clip: yatay keser, dikey scroll'a dokunmaz */
body { overflow-x: clip; position: relative; }

/* YANLIŞ — scroll kilitlenir */
html { overflow-x: hidden; }
```

### Safe Area (iPhone notch/home bar)

```css
/* Container alt padding: bottom nav yüksekliği + iPhone home bar */
.container { padding-bottom: calc(80px + env(safe-area-inset-bottom, 0px)); }

/* Bottom nav kendi padding'i */
.bottomnav { padding-bottom: calc(6px + env(safe-area-inset-bottom, 0px)); }
```

### iOS Zoom Önleme

Input font-size < 16px olursa iOS otomatik zoom yapar.

```css
@media (max-width: 767px) {
    input, select, textarea { font-size: 16px; }
}
```

---

## PWA Notları

- **manifest.json** değişirse → cache adını `sw.js` içinde güncelle (`yukleme-plani-v2` gibi)
- **sw.js** network-first stratejisi kullanır — ağ erişimi varsa daima güncel sürümü çeker
- Service worker `render_header()` içinden kaydedilir; print modda kaydedilmez
- Apple PWA meta tagları `render_header()` içinde — kaldırma

---

## Veritabanı Şeması (özet)

```sql
loading_records   -- Kayıt başlıkları (type: 'yukleme' | 'cikma')
loading_pallets   -- Palet satırları (FK: loading_record_id)
pallet_materials  -- Palet malzemeleri (FK: loading_pallet_id)
material_definitions -- Malzeme/kasa/palet tanımları
material_templates      -- Şablon başlıkları
material_template_items -- Şablon kalemleri
```

### type Kolonu (ÖNEMLİ)

`loading_records.type` kolonu sonradan eklendi.  
`config/db.php` ve `config/helpers.php` içinde **otomatik migrasyon** var:

```php
// helpers.php sonunda çalışır — eski db.php versiyonu olan sunucularda da çalışır
$has = $pdo->query("SHOW COLUMNS FROM `loading_records` LIKE 'type'")->fetchColumn();
if (!$has) {
    $pdo->exec("ALTER TABLE `loading_records` ADD COLUMN `type` VARCHAR(20) NOT NULL DEFAULT 'yukleme'");
}
```

Üretim sunucusunda `config/db.php` farklı DB credentials içerebilir ve `git pull` ile güncellenmeyebilir. Bu yüzden migrasyon `helpers.php`'de de tekrarlanmıştır.

---

## Hesaplama Mantığı

### Dara Hesabı — YUVARLAMA

**Her palet için ham dara sakla, sadece toplamı yuvarla.**

```js
// app.js — YANLIŞ (birikimli hata oluşur)
dara = Math.round(kasaAdeti * kasaKg + paletKg + extra);  // ← YAPMA

// DOĞRU
dara = kasaAdeti * kasaKg + paletKg + extra;  // ham tut
// Ekranda toplam gösterirken:
totDara = Math.round(hammToplamDara);
```

```php
// calc.php — DOĞRU
$dara = round($kasa_total + $palet_total + $extra_total, 3); // 3 decimal, toplam
$net  = round(max(0, $brut - $dara), 3);
```

---

## Önemli Desenler

### Tür-Aware Yönlendirme

Kayıt düzenleme/silme sonrası doğru listeye yönlendir:

```php
$list_url = ($record['type'] ?? 'yukleme') === 'cikma' ? 'cikmalar.php' : 'records.php';
```

### render_header / render_footer

Her sayfada çift çağrı — print_mode=true olduğunda:
- Bottom nav çıkmaz
- JS dosyası yüklenmez
- Service worker kaydedilmez

### CSRF

Form → `<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"`  
İşlem → `csrf_check($_POST['csrf'] ?? null);`

---

## Geliştirme Kontrol Listesi

Yeni özellik eklerken şunları kontrol et:

- [ ] Mobilde taşma var mı? (yatay scroll kontrolü)
- [ ] Bottom nav'da hangi sekme aktif olmalı? (`render_footer` güncellendi mi?)
- [ ] Input'lar mobilde 16px font-size alıyor mu?
- [ ] Yeni tablo varsa `.table-wrap` içine alındı mı?
- [ ] Yazdırma modunda görünmemesi gerekenler `@media print { display:none }` içinde mi?
- [ ] Yeni DB kolonu/tablosu varsa migrasyon eklendi mi?
- [ ] `type` kolonu kullanan yeni sorgular için fallback var mı?

---

## Yaygın Hatalar

| Hata | Sebep | Çözüm |
|---|---|---|
| Dikey scroll çalışmıyor | `overflow-x: hidden` html'de | `overflow-x: clip` kullan |
| Dropdown overflow container'da kesiyor | `overflow: auto` stacking context | `position: fixed` + `getBoundingClientRect()` |
| Dara toplamı 1 eksik çıkıyor | Per-palet yuvarlama | Sadece toplamda yuvarla |
| `type` kolonu bulunamadı | DB migrasyonu çalışmadı | migrate.php?run=1 veya phpMyAdmin'de ALTER TABLE |
| SQLSTATE[HY093] | PDO named param aynı sorguda tekrar kullanıldı | Pozisyonel `?` parametreye geç |
