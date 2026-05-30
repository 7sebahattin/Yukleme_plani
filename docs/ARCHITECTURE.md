# Mimari Notlar

## Core Stack

- PHP 8, PDO/MySQL, vanilla JS, tek CSS + tek JS dosyası
- PWA: `manifest.json` + `sw.js` (network-first, CACHE_NAME: `yukleme-plani-v8`)
- Çerçeve yok, ORM yok, build tool yok

## Giriş Noktaları

Her sayfa üst kısımda şunu çağırır:
```php
require_once __DIR__ . '/config/db.php';   // PDO + auto-migration + helpers.php yükler
require_once __DIR__ . '/config/auth.php'; // session, require_login, can(), is_admin()
$auth_user = require_login();              // oturum yoksa login'e yönlendirir
require_perm('records.read');              // yetki yoksa 403
```

## Auth Akışı

```
login.php → session_start → $_SESSION['user_id'] set
→ require_login() her sayfada session kontrolü
→ can('perm.name') yetki kontrolü
→ is_admin() admin özel işlemler
→ logout.php → session_destroy
```

## Render Sistemi

```php
render_header($title)           // HTML head + topbar + desktop sidebar (≥900px)
render_desktop_sidebar($base)   // config/helpers.php — permission'lı sidebar
render_footer()                 // bottomnav (<768px) + notlar modal
render_flash()                  // flash mesaj göster
```

Print sayfalarında: `render_header($title, true)` → sidebar/bottomnav/SW yok.

## Veritabanı Yapısı

```
loading_records     type='yukleme'|'cikma', durum='islendi'|'yuklendi'|''
  └─ loading_pallets
       └─ pallet_materials

material_definitions  kasa/palet/malzeme tanımları
material_templates / material_template_items

account_transactions / account_files   Hesap modülü

users / roles / role_permissions       Auth

audit_log    action, module, record_id, old_values(JSON), new_values(JSON)

kantar_gruplar / kantar_kayitlar
hks_notifications
material_stock_movements
```

## Kayıt Lifecycle

```
Oluştur → durum='' (taslak)
→ durum='islendi' (işlendi butonu)
→ durum='yuklendi' + locked_at/by set (yüklendi butonu → KİLİTLENİR)
→ Unlock: records.unlock yetkisi + revision_reason zorunlu → locked_at/by NULL, unlocked_at/by set
```

## Stok Mantığı

`stok.php` — `loading_pallets` üzerinden palet bazlı ürün stok hesabı.  
`malzeme_stok.php` — `material_stock_movements` (giriş/çıkış) üzerinden malzeme takibi.  
Negatif stok uyarısı gösterilir, fiziksel engel yok.

## Kantar Mantığı

`kantar.php` — tır/araç brüt/dara/net tartım. `kantar_gruplar` ile firma eşleme.  
`kantar_view.php` — detay + yazdırma.  
`kantar_raporu.php` — toplu rapor.

## HKS Modülü (`hks/`)

`hks/helpers.php` → `config/auth.php` + `require_login()` yükler (her HKS sayfasında).  
`HksClient::sendNotification()` → dış API çağrısı → `api_send.php` sonucunu audit'e yazar.  
`.htaccess` — `HksClient/HksRepository/HksConfig/helpers.php`'e doğrudan HTTP erişim kapalı.

## Hesaplama

`config/calc.php` — dara/net PHP hesabı.  
`assets/app.js` — dara/net JS hesabı (gerçek zamanlı form).  
**Kural:** Ham dara sakla, yalnızca toplamı yuvarla (per-palet yuvarlama birikimli hataya yol açar).

## Audit

```php
audit_log_event('action', 'module', $record_id, $old_vals, $new_vals);
// İç try/catch — asla exception fırlatmaz, asla caller'ı bozmaz
// _audit_sanitize() — password/token/csrf/cookie/foto_data filtreler, >1000 char truncate
```

## CSS/JS Mimarisi

- Tek CSS: `assets/style.css` (~4000 satır) — mobil-önce, Sprint 32'de sidebar eklendi
- Tek JS: `assets/app.js` — palet form, kebab menu, durum AJAX
- Ayrı JS: `assets/kalan.js` (kalan modal), `assets/app.js` CSRF header ekli
- CSS media queries: `<768` mobil, `768-899` tablet, `≥900` desktop sidebar, `≥1024` pc-only tablo, `≥1280` geniş

## Navigasyon

**Mobil (<900px):** `topbar` (sticky) + `bottomnav` (fixed bottom, 5 sekme)  
**Desktop (≥900px):** `desktop-sidebar` (fixed left, 220px) — topbar ve bottomnav gizlenir  
**Geniş (≥1280px):** sidebar 260px  
**Print:** topbar/sidebar/bottomnav gizli, container margin sıfır
