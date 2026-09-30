# Asya Fresh — Eksiksiz Teknik Devir Dokümantasyonu

**Hazırlayan:** Senior Software Architect (Teknik Audit)  
**Tarih:** 2026-05-18  
**Branch:** `claude/fix-records-print-mobile-WuKdT`  
**Canlı:** `nuverna.derspros.com.tr`

---

## 1. PROJE GENEL TANIMI

### Projenin Adı
**Asya Fresh** — Yükleme Planı ve Kayıt Yönetim Sistemi

### Ne İşe Yarıyor
Tarımsal ürün (başta kayısı) ihracatı yapan bir şirkete özel geliştirilmiş, **mobil öncelikli operasyon yönetim uygulaması**. Kamyon yüklemelerini, paket ağırlıklarını, dara hesaplamalarını, araç tartım işlemlerini ve temel finansal akışı dijital ortamda yönetir. Kâğıt formların yerini alır.

### Hangi Sektör İçin
Tarımsal ürün ihracatı / paketleme sektörü. Depo-kamyon arası yükleme operasyonu.

### Hedef Kullanıcı Tipi
- **Saha operatörü** (kamyon başında, tek elle telefon kullanan)
- **Depo sorumlusu** (tablet/PC)
- **Muhasebe/Ofis** (raporlar, hesap modülü)
- **İşletme sahibi** (genel görünüm, raporlar)

### Temel Kullanım Senaryoları
1. Kamyon gelir → Yeni Yükleme Kaydı açılır → Palet palet veri girilir → Fiş oluşturulur
2. Araç tartılır → Kantar Fişi oluşturulur → Tartım1/Tartım2 girilir → Net hesaplanır → Gruplandırma ile firma bazında dağıtım yapılır
3. Ürün çıkışı → Çıkma kaydı açılır → Kasa/palet detayları girilir
4. Periyodik → Raporlar ekranından firma/tarih bazlı özetler alınır, CSV export yapılır

### Sistemin Çözmeye Çalıştığı Problemler
- Kâğıt fiş ve Excel'deki manuel hata payı (özellikle dara hesabı)
- Saha → ofis bilgi akışındaki gecikme
- Farklı kasa/palet tiplerinin dara farklılığını el ile hesaplamak
- Araç tartım verilerini firma bazında bölmek (kantar gruplandırma)
- Temel nakliye masrafı takibi

### Temel İş Akışı

```
[Araç Gelir]
    ↓
[Yükleme Kaydı Aç] → record_create.php
    ↓
[Palet Ekle] → Modal / Toplu Düzenle (app.js)
    ↓                  ↓
[Her Palet]     [Her Paletin Materyalleri]
kasa_adeti       sapka, kosebent, serit...
brut_kg          (ek dara kalemleri)
kasa_cinsi_id
palet_tipi_id
    ↓
[Kaydet] → record_create.php → calc.php → DB
    ↓
[Kantar Fişi] → kantar_create.php
tartım1/tartım2 → net_kg
    ↓
[Gruplandırma] → hangi firma ne kadar aldı
    ↓
[Raporlar] → reports.php → CSV
```

### Şu Anki Geliştirme Durumu
**Production'da aktif kullanımda** — tek şirket, tek lokasyon. Özellik geliştirme devam ediyor. Kod hâlâ olgunlaşma sürecinde: migrasyon sistemi hâlâ çalışıyor, bazı utility PHP dosyaları production'da açık.

### MVP mi Production mı
Teknik olarak production'da ama **MVP olgunluk seviyesinde**. Authentication yok, test yok, izleme yok.

### Eksik Görülen Alanlar
- Kullanıcı kimlik doğrulaması / yetkilendirme (en kritik)
- Bildirim / uyarı sistemi
- Gelişmiş raporlama (grafikler)
- Offline data sync
- Hata izleme (Sentry vb.)
- Backup otomasyonu

---

## 2. TEKNOLOJİ MİMARİSİ

### Frontend Teknolojileri
- **Vanilla JavaScript (ES6+)** — framework yok, IIFE pattern
- **HTML5** (PHP template ile üretilir)
- **CSS3** — tek dosya (`assets/style.css`, 2862 satır), CSS custom properties (`var(--primary)` vb.)
- **PWA** — manifest.json + sw.js (service worker)
- **SheetJS (XLSX)** — Excel import için CDN'den yüklenir (`_form.php` içinde `<script src="...xlsx.full.min.js">`)

### Backend Teknolojileri
- **PHP 8.0+** (`declare(strict_types=1)` her dosyada)
- **PDO** (MySQL driver, ERRMODE_EXCEPTION, FETCH_ASSOC, emulate_prepares=false)
- **PHP Sessions** (CSRF token için)

### Frameworkler
**Hiçbiri.** Her şey sıfırdan yazılmış. MVC yok, routing yok, ORM yok.

### Kullanılan Kütüphaneler

| Kütüphane | Amaç | Yükleme |
|---|---|---|
| SheetJS (XLSX) | Excel dosyası parse | CDN (xlsx.full.min.js) |

Başka harici kütüphane yok. PDO, PHP built-in.

### State Management Sistemi
**Client-side:** Tek global `pallets[]` dizisi (`app.js`'de `let pallets = []`). Hiç framework state yok. Durum `renderCards()` çağrısıyla DOM'a yansır. Form submit'te `generateHiddenInputs()` ile hidden input'lara dönüştürülür.

**Server-side:** PHP `$_SESSION` sadece CSRF token ve flash mesaj için kullanılır. Uygulama state'i DB'de.

### Authentication Yapısı
**YOK.** Uygulamanın hiçbir sayfasında login kontrolü bulunmuyor. URL'yi bilen herkes tüm verilere erişebilir, yazabilir, silebilir.

### API Yapısı
Gerçek anlamda REST API yok. Mini JSON endpoint'ler:

| Dosya | Method | Amaç |
|---|---|---|
| `api_bulk_material.php` | POST | Toplu malzeme ekleme |
| `api_kalan.php` | GET/POST | Kalan stok hesabı |
| `api_templates.php` | GET | Malzeme şablonları |
| `note_save.php` | GET/POST | Not listesi + kaydetme |
| `note_update.php` | POST | Not toggle/silme |
| `kantar_foto.php` | GET | Base64 fotoğraf endpoint |
| `record_durum.php` | POST | Durum güncelleme |

Tüm JSON endpoint'ler CSRF kontrolü yapar. Content-type negotiation yok.

### Hosting Yapısı
Paylaşımlı hosting (Apache/LiteSpeed, `nuverna.derspros.com.tr`). `.htaccess` mevcut. PHP dosyaları doğrudan web root altında.

### CDN Kullanımı
SheetJS CDN'den çekiliyor (`_form.php` içinde). Bu tek harici bağımlılık. Diğer tüm asset'ler local.

### Cache Sistemi
**PWA Service Worker** — `yukleme-plani-v5` cache. Network-first strateji: ağ varsa her zaman güncel, yoksa cache. Shell (index.php, style.css, app.js, icon.svg, manifest.json) install'da önbelleğe alınır. CSS versiyonlama için PHP `filemtime()` query string kullanılır (`style.css?v=1234567890`).

**Bilinen sorun:** SW cache, PHP sayfalarını da cache'ler. Kantar foto endpoint'i (`kantar_foto.php?id=X`) SW cache'e düşebilir → stale fotoğraf gösterimi. Edit modunda `localStorage` temizlenerek kısmen çözüldü.

### Queue / File Upload / OCR / AI
- Queue sistemi: **Yok**
- File upload: **Base64 text olarak MySQL'de** (kantar fotoğrafı). Hesap modülünde gerçek dosya upload var (`account_files`).
- OCR / AI: **Yok**

### Mobil Uyumluluk Yaklaşımı
- Breakpoint'ler: 768px (mobil/PC geçişi), 720px (grid), 1024px (tablo görünümü)
- Bottom navigation (≤767px) — 5 sekme
- iOS zoom önleme: mobilde input `font-size ≥ 16px`
- `overflow-x: clip` (iOS dikey scroll sorununu önlemek için — `hidden` kullanılmaz)
- Safe area desteği: `env(safe-area-inset-bottom)`
- PWA: `standalone` display mode, Apple PWA meta tag'leri

---

## 3. DOSYA VE KLASÖR YAPISI

```
/home/user/Yukleme_plani/           (web root)
│
├── index.php                       # Ana sayfa — kart grid, istatistikler
├── records.php                     # Yükleme listesi (filtreleme, arama)
├── cikmalar.php                    # Çıkma listesi
├── record_new.php                  # Yükleme/Çıkma tipi seçim sayfası
├── record_create.php               # Yeni yükleme formu + kayıt işlemi
├── cikma_create.php                # Yeni çıkma formu + kayıt işlemi
├── record_edit.php                 # Kayıt düzenleme
├── record_view.php                 # Görüntüleme + yazdırma (1290 satır)
├── record_delete.php               # Silme onayı + işlem
├── record_durum.php                # AJAX durum güncelleme endpoint
│
├── kantar.php                      # Kantar fişleri liste
├── kantar_create.php               # Yeni kantar fişi
├── kantar_edit.php                 # Kantar fişi düzenleme
├── kantar_delete.php               # Kantar fişi silme
├── kantar_view.php                 # Kantar fişi görüntüleme/yazdırma (412 satır)
├── kantar_foto.php                 # Fotoğraf base64 endpoint
│
├── definitions.php                 # Malzeme tanımları CRUD
├── reports.php                     # Raporlar + CSV export (585 satır)
│
├── hesap.php                       # Finansal hesap ana sayfası
├── hesap_config.php                # Hesap modülü kategori konfigürasyonu
├── hesap_dosya.php                 # Belge upload
├── hesap_dosya_sil.php             # Belge silme
├── hesap_export.php                # Hesap CSV export
├── hesap_kayit.php                 # Gelir/gider kayıt formu
├── hesap_liste.php                 # Hesap listesi
├── hesap_muhasebe.php              # Muhasebe görünümü
├── hesap_sil.php                   # İşlem silme
├── hesap_yazdir.php                # Yazdırma görünümü
│
├── notes.php                       # Geliştirici not listesi sayfası
├── note_save.php                   # Not CRUD JSON endpoint (GET=liste, POST=kaydet)
├── note_update.php                 # Not toggle/delete JSON endpoint
│
├── api_bulk_material.php           # Toplu malzeme ekleme JSON API
├── api_kalan.php                   # Kalan stok hesabı JSON API
├── api_templates.php               # Şablon listesi JSON API
│
├── _form.php                       # record_create + record_edit ortak form partial (379 satır)
├── _kantar_form.php                # kantar_create + kantar_edit ortak form partial (973 satır)
│
├── import_cikmalar.php             # CSV'den çıkma import aracı
├── excel_ornek_palet.php           # Örnek Excel şablonu indirme
├── repair_kasa_ids.php             # Kasa ID veri onarım aracı (295 satır) ⚠️
├── diagnostik.php                  # Sistem diagnostiği ⚠️
├── deploy.php                      # Deployment yardımcı aracı ⚠️
├── migrate.php                     # Manuel migrasyon çalıştırıcı ⚠️
│
├── database.sql                    # Referans şema (fresh install için)
├── manifest.json                   # PWA manifest
├── sw.js                           # Service Worker (network-first)
├── .htaccess                       # Apache config
├── CLAUDE.md                       # Geliştirici kılavuzu
│
├── config/
│   ├── db.php                      # PDO bağlantısı + kısmi oto-migrasyon (100 satır)
│   ├── helpers.php                 # Tüm yardımcı fonksiyonlar + tam oto-migrasyon (649 satır)
│   ├── calc.php                    # Sunucu taraflı dara/net hesabı (102 satır)
│   └── calc_helper.php             # Hesap modülü için ek hesap yardımcıları (78 satır)
│
└── assets/
    ├── style.css                   # TEK CSS dosyası (2862 satır)
    ├── app.js                      # TEK JS dosyası — palet/form logic (883 satır)
    ├── kalan.js                    # Kalan stok modülü JS (285 satır)
    ├── icon.svg                    # PWA ikon
    ├── icon-maskable.svg           # PWA maskable ikon
    └── logo.jpg                    # Şirket logosu (PWA ikon olarak da kullanılıyor)
```

**⚠️ Web root'ta açık utility dosyaları:** `repair_kasa_ids.php`, `diagnostik.php`, `deploy.php`, `migrate.php` — production'da erişime kapalı olmalı.

**Toplam kod:** ~13.500 satır (PHP: ~11.000 + JS: ~1.168 + CSS: ~2.862)

---

## 4. DATABASE MİMARİSİ

### Kullanılan Sistem
**MySQL 5.7+ / MariaDB 10.2+**, InnoDB engine, utf8mb4 charset, utf8mb4_unicode_ci collation.

---

### Tablo: `loading_records`
Kayıt başlıkları. Hem yükleme hem çıkma kayıtlarını tutar (`type` kolonu ile ayrılır).

```sql
CREATE TABLE loading_records (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    firma           VARCHAR(150) NOT NULL DEFAULT '',
    bolge           VARCHAR(150) NOT NULL DEFAULT '',
    parti_no        VARCHAR(80)  NOT NULL DEFAULT '',
    gumruk          VARCHAR(150) NOT NULL DEFAULT '',
    nakliye_bedeli  DECIMAL(12,2) NOT NULL DEFAULT 0,
    avans           DECIMAL(12,2) NOT NULL DEFAULT 0,
    sofor_adi       VARCHAR(150) NOT NULL DEFAULT '',
    fatura_no       VARCHAR(80)  NOT NULL DEFAULT '',
    casus_no        VARCHAR(80)  NOT NULL DEFAULT '',
    on_plaka        VARCHAR(30)  NOT NULL DEFAULT '',
    arka_plaka      VARCHAR(30)  NOT NULL DEFAULT '',
    nakliye_sirketi VARCHAR(150) NOT NULL DEFAULT '',
    telefon         VARCHAR(40)  NOT NULL DEFAULT '',
    type            VARCHAR(20)  NOT NULL DEFAULT 'yukleme',  -- 'yukleme' | 'cikma'
    tarih           DATE         NULL,
    alici           VARCHAR(150) NOT NULL DEFAULT '',
    urun            VARCHAR(150) NOT NULL DEFAULT '',
    etiket          VARCHAR(255) NOT NULL DEFAULT '',
    durum           VARCHAR(20)  NOT NULL DEFAULT '',         -- migration ile eklendi
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tarih (tarih),
    INDEX idx_firma (firma),
    INDEX idx_parti (parti_no)
);
```

**İlişki:** 1:N → `loading_pallets`  
**Dikkat:** `type` kolonu sonradan eklendi, hem `db.php` hem `helpers.php` migration IIFE'sinde kontrol ediliyor.

---

### Tablo: `loading_pallets`
Her kayıda ait palet satırları. Her satır bir palet birimini temsil eder.

```sql
CREATE TABLE loading_pallets (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    loading_record_id  INT NOT NULL,                          -- FK → loading_records.id CASCADE
    palet_no           VARCHAR(40)  NOT NULL DEFAULT '',
    kasa_adeti         INT          NOT NULL DEFAULT 0,
    size               VARCHAR(60)  NOT NULL DEFAULT '',       -- kalibr (ör. "90-100")
    brut_kg            DECIMAL(10,3) NOT NULL DEFAULT 0,
    dara_kg            DECIMAL(10,3) NOT NULL DEFAULT 0,       -- hesaplanmış, sunucu taraflı
    net_kg             DECIMAL(10,3) NOT NULL DEFAULT 0,       -- hesaplanmış
    kasa_cinsi_id      INT NULL,                              -- FK → material_definitions.id SET NULL
    palet_tipi_id      INT NULL,                              -- FK → material_definitions.id SET NULL
    urun_cinsi         VARCHAR(150) NOT NULL DEFAULT '',
    depo               VARCHAR(150) NOT NULL DEFAULT '',
    sira_no            INT NOT NULL DEFAULT 0,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pallet_record FOREIGN KEY (loading_record_id)
        REFERENCES loading_records(id) ON DELETE CASCADE,
    CONSTRAINT fk_pallet_kasa FOREIGN KEY (kasa_cinsi_id)
        REFERENCES material_definitions(id) ON DELETE SET NULL,
    CONSTRAINT fk_pallet_palet FOREIGN KEY (palet_tipi_id)
        REFERENCES material_definitions(id) ON DELETE SET NULL,
    INDEX idx_pallet_record (loading_record_id)
);
```

**Hesap mantığı:** `dara_kg` ve `net_kg` sunucuda `calc.php::compute_pallet_row()` ile hesaplanır ve saklanır. Client JS da aynı mantığı uygular (görsel önizleme için). İkisi tutarsız kalırsa DB değeri yetkilidir.

---

### Tablo: `pallet_materials`
Bir palete eklenen ekstra dara kalemleri (şapka, köşebent, şerit vb.).

```sql
CREATE TABLE pallet_materials (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    loading_pallet_id  INT NOT NULL,                          -- FK → loading_pallets.id CASCADE
    material_id        INT NOT NULL,                          -- FK → material_definitions.id CASCADE
    quantity           DECIMAL(10,3) NOT NULL DEFAULT 1,
    total_dara_kg      DECIMAL(10,3) NOT NULL DEFAULT 0,      -- unit_dara_kg × quantity
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pm_pallet FOREIGN KEY (loading_pallet_id)
        REFERENCES loading_pallets(id) ON DELETE CASCADE,
    CONSTRAINT fk_pm_material FOREIGN KEY (material_id)
        REFERENCES material_definitions(id) ON DELETE CASCADE,
    INDEX idx_pm_pallet (loading_pallet_id)
);
```

**Risk:** `material_definitions` silinirse bu satır da CASCADE ile silinir — dara geçmişi kaybolur. ON DELETE SET NULL veya soft delete daha güvenli olurdu.

---

### Tablo: `material_definitions`
Sistem genelinde kullanılan tüm malzeme/dara tanımları.

```sql
CREATE TABLE material_definitions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    type            VARCHAR(40)  NOT NULL,
    name            VARCHAR(150) NOT NULL,
    unit_dara_kg    DECIMAL(10,3) NOT NULL DEFAULT 0,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type (type),
    INDEX idx_active (is_active)
);
```

**`type` değerleri:**
```
-- Lookup / dropdown (unit_dara_kg her zaman 0)
firma, depo, bolge, urun

-- Dara hesabında ana kalemler
kasa_cinsi, palet_tipi

-- Ekstra dara kalemleri
sapka, kosebent, serit, casus, kasa_etiketi, minti,
kenar_kartonu, taban_kagidi, sale, viyol, kose_karton,
kraft_kagit, file, diger
```

**Tasarım sorunu:** Tek tabloda hem dara-hesaplayan veriler (`kasa_cinsi`, `palet_tipi`) hem saf lookup verileri (`firma`, `depo`, `urun`) barındırılıyor. İki ayrı tablo daha temiz olurdu.

---

### Tablo: `material_templates` / `material_template_items`

```sql
CREATE TABLE material_templates (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(150) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE material_template_items (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,   -- FK → material_templates.id CASCADE
    material_id INT NOT NULL,   -- FK → material_definitions.id CASCADE
    quantity    DECIMAL(10,3) NOT NULL DEFAULT 1
);
```

---

### Tablo: `kantar_fisleri`
Araç tartım fişleri.

```sql
CREATE TABLE kantar_fisleri (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    fis_no       VARCHAR(50)   NOT NULL DEFAULT '',
    giris_tarih  VARCHAR(40)   NOT NULL DEFAULT '',    -- ⚠️ DATE değil VARCHAR
    cikis_tarih  VARCHAR(40)   NOT NULL DEFAULT '',    -- ⚠️ DATE değil VARCHAR
    plaka        VARCHAR(30)   NOT NULL DEFAULT '',
    firma_adi    VARCHAR(120)  NOT NULL DEFAULT '',
    malin_cinsi  VARCHAR(200)  NOT NULL DEFAULT '',
    geldigi_yer  VARCHAR(200)  NOT NULL DEFAULT '',
    gittigi_yer  VARCHAR(100)  NOT NULL DEFAULT '',
    aciklama     TEXT,
    aciklama2    TEXT,                                  -- migration ile eklendi
    operator_adi VARCHAR(100)  NOT NULL DEFAULT '',
    tartim1      DECIMAL(12,3) NOT NULL DEFAULT 0,
    alibi1       VARCHAR(30)   NOT NULL DEFAULT '',
    tartim2      DECIMAL(12,3) NOT NULL DEFAULT 0,
    alibi2       VARCHAR(30)   NOT NULL DEFAULT '',
    net_kg       DECIMAL(12,3) NOT NULL DEFAULT 0,      -- tartim1 - tartim2
    toplam_palet INT           NOT NULL DEFAULT 0,
    palet_sayisi INT           NOT NULL DEFAULT 0,      -- migration ile eklendi
    kasa_cinsi   VARCHAR(200)  NOT NULL DEFAULT '',     -- ⚠️ text, FK değil
    kasa_sayisi  INT           NOT NULL DEFAULT 0,
    palet_cinsi  VARCHAR(200)  NOT NULL DEFAULT '',     -- ⚠️ text, FK değil
    kasa_dara    DECIMAL(10,3) NOT NULL DEFAULT 0,
    palet_dara   DECIMAL(10,3) NOT NULL DEFAULT 0,
    foto_data    MEDIUMTEXT    NULL DEFAULT NULL,        -- ⚠️ BASE64 JPEG
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

**Kritik sorunlar:**
1. `giris_tarih` / `cikis_tarih` DATE değil VARCHAR — tarih bazlı sıralama/filtreleme bozulur
2. `kasa_cinsi` / `palet_cinsi` text olarak saklanıyor — tanım değişirse senkron bozulur
3. `foto_data MEDIUMTEXT` — base64 JPEG satır başına ~300-600 KB. 1000 fiş = ~500 MB salt fotoğraf

---

### Tablo: `kantar_gruplar`

```sql
CREATE TABLE kantar_gruplar (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    fis_id        INT NOT NULL,           -- FK → kantar_fisleri.id CASCADE
    sira          INT NOT NULL DEFAULT 0,
    grup_adi      VARCHAR(100) NOT NULL DEFAULT '',
    palet_sayisi  INT NOT NULL DEFAULT 0,
    kasa_adedi    INT NOT NULL DEFAULT 0,
    kasa_dara_kg  DECIMAL(10,3) NOT NULL DEFAULT 0,    -- migration ile eklendi
    palet_dara_kg DECIMAL(10,3) NOT NULL DEFAULT 0,    -- migration ile eklendi
    FOREIGN KEY (fis_id) REFERENCES kantar_fisleri(id) ON DELETE CASCADE
);
```

---

### Tablo: `account_transactions`

```sql
CREATE TABLE account_transactions (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    transaction_date       DATE NOT NULL,
    transaction_time       TIME NOT NULL DEFAULT '00:00:00',
    type                   ENUM('gelir','gider','havale','nakit') NOT NULL,
    category               VARCHAR(100) NOT NULL DEFAULT '',
    amount                 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency               VARCHAR(5) NOT NULL DEFAULT 'TRY',
    payment_method         VARCHAR(30) NOT NULL DEFAULT 'nakit',
    person_company         VARCHAR(200) NOT NULL DEFAULT '',
    description            TEXT NOT NULL DEFAULT '',
    document_no            VARCHAR(100) NOT NULL DEFAULT '',
    has_invoice            TINYINT(1) NOT NULL DEFAULT 0,
    is_for_company         TINYINT(1) NOT NULL DEFAULT 1,
    is_given_to_accountant TINYINT(1) NOT NULL DEFAULT 0,
    notes                  TEXT NOT NULL DEFAULT '',
    has_files              TINYINT(1) NOT NULL DEFAULT 0,
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_date (transaction_date),
    INDEX idx_type (type),
    INDEX idx_accountant (is_given_to_accountant)
);
```

---

### Tablo: `account_files`

```sql
CREATE TABLE account_files (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id  INT NOT NULL,    -- FK → account_transactions.id CASCADE
    file_name       VARCHAR(255) NOT NULL,
    original_name   VARCHAR(255) NOT NULL DEFAULT '',
    file_type       VARCHAR(50) NOT NULL DEFAULT '',
    file_size       INT NOT NULL DEFAULT 0,
    uploaded_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tid (transaction_id)
);
```

---

### Tablo: `dev_notes`

```sql
CREATE TABLE dev_notes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    page_url   VARCHAR(255) NOT NULL DEFAULT '',
    page_name  VARCHAR(100) NOT NULL DEFAULT '',
    note       TEXT NOT NULL,
    done       TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

---

### İlişki Diyagramı (Metinsel)

```
loading_records ─────────────────────────── 1:N ─── loading_pallets
                                                          │
                                          ┌───────────────┼───────────────┐
                                          │               │               │
                              N:1 ─── material_definitions    N:M ─── pallet_materials
                         (kasa_cinsi_id)  (palet_tipi_id)           │
                                                              N:1 ─── material_definitions

kantar_fisleri ─── 1:N ─── kantar_gruplar

account_transactions ─── 1:N ─── account_files

material_templates ─── 1:N ─── material_template_items ─── N:1 ─── material_definitions
```

---

### Örnek Veri Akışı

```sql
-- 1. Başlık kaydı
INSERT INTO loading_records (firma, tarih, type, on_plaka)
VALUES ('Acme Gıda', '2026-05-18', 'yukleme', '34ABC123');
-- → $record_id = lastInsertId() = 42

-- 2. Her palet (sunucuda compute_pallet_row() çalışır)
-- kasa_cinsi_id=5 → unit_dara_kg=0.48, kasa_adeti=90
-- palet_tipi_id=3 → unit_dara_kg=18
-- dara = 90×0.48 + 18 = 43.2 + 18 = 61.2
-- net  = 934 - 61.2 = 872.8
INSERT INTO loading_pallets
    (loading_record_id, palet_no, kasa_adeti, brut_kg, dara_kg, net_kg, kasa_cinsi_id, palet_tipi_id)
VALUES (42, '1', 90, 934.000, 61.200, 872.800, 5, 3);
-- → $pallet_id = 101

-- 3. Ekstra dara (4 köşebent, unit=0.400 kg)
INSERT INTO pallet_materials (loading_pallet_id, material_id, quantity, total_dara_kg)
VALUES (101, 12, 4, 1.600);
```

### Örnek Rapor Sorgusu

```sql
SELECT
    r.id, r.firma, r.tarih, r.on_plaka,
    COUNT(p.id)          AS palet_sayisi,
    SUM(p.kasa_adeti)    AS toplam_kasa,
    SUM(p.brut_kg)       AS toplam_brut,
    SUM(p.dara_kg)       AS toplam_dara,
    SUM(p.net_kg)        AS toplam_net
FROM loading_records r
LEFT JOIN loading_pallets p ON p.loading_record_id = r.id
WHERE r.type = 'yukleme'
  AND r.tarih BETWEEN '2026-01-01' AND '2026-05-18'
GROUP BY r.id
ORDER BY r.tarih DESC, r.id DESC
LIMIT 2000;
```

### Olası Performans Problemleri

1. **`foto_data` içeren SELECT:** `kantar.php` listesinde `SELECT *` varsa her satır için ~500 KB transfer. Kolon bazlı SELECT zorunlu.
2. **LIMIT 2000 sabit, pagination yok:** 2000 kayıt tek HTML'de render → mobil bellek sorunu.
3. **Migration IIFE her request'te çalışır:** `SHOW COLUMNS` sorguları her sayfa yüklemesinde tetiklenir. Düşük trafikte kabul edilebilir.
4. **`pallet_materials` N+1 riski:** `record_view.php`'de palet listesi çekilirken her paletin materyalleri ayrı sorguda çekiliyorsa N+1 oluşur. JOIN ile tek sorguya indirilmeli.
5. **Composite index eksikliği:** `WHERE type=? AND is_active=1` sorgusu için `(type, is_active)` composite index yok.

---

## 5. MODÜLLER

### 5.1 Yükleme Modülü

**Dosyalar:** `records.php`, `record_create.php`, `record_edit.php`, `record_view.php`, `_form.php`

**Ne işe yarıyor:** Kamyon yüklemelerini kayıt altına alır. Her kayıt N palet satırı içerir, her palet dara hesabı yapılır.

**Veri akışı:**
```
Form submit → record_create.php
    → foreach $_POST['pallets'] as $row
    → compute_pallet_row($row)  ← calc.php
    → INSERT loading_records
    → INSERT loading_pallets × N
    → INSERT pallet_materials × M
    → redirect → record_view.php?id=X
```

**Kullanıcı akışı:**
1. `record_new.php` → Yükleme/Çıkma seçimi
2. `record_create.php` (GET) → Form yüklenir, palet listesi boş
3. Modal'dan palet eklenir (app.js) veya Toplu Düzenle tablosundan
4. Excel import ile toplu palet yüklenebilir (SheetJS)
5. Form submit → server hesabı → redirect → görüntüleme

**Eksikler:**
- JS state (`pallets[]`) sayfa yenilenmesinde kaybolur (taslak kaydetme yok)
- Kayıt durumu (`durum` alanı) var ama tam iş akışı tanımlı değil
- `generateHiddenInputs()` büyük paletts[] dizisinde DOM'a yüzlerce hidden input ekler

**Büyüme potansiyeli:** Durum makinesi (Taslak → Onaylı → İrsaliye Kesildi), PDF export, QR palet takibi.

---

### 5.2 Çıkma Modülü

**Dosyalar:** `cikmalar.php`, `cikma_create.php`

**Ne işe yarıyor:** Aynı `loading_records` tablosunu kullanır, `type = 'cikma'` ile ayrılır. `cikma_create.php` ile `record_create.php` kod yapısı ~%80 aynıdır — tekrar kod (DRY ihlali). `$type` parametresiyle birleştirilebilir.

**Yönlendirme kuralı:**
```php
$list_url = ($record['type'] ?? 'yukleme') === 'cikma' ? 'cikmalar.php' : 'records.php';
```

---

### 5.3 Kantar Modülü

**Dosyalar:** `kantar.php`, `kantar_create.php`, `kantar_edit.php`, `kantar_view.php`, `_kantar_form.php`

**Ne işe yarıyor:** Araç tartım fişi yönetimi. İki tartım değerinden net hesaplar. Gruplandırma ile firma bazında dağıtım yapar.

**Hesap mantığı:**
```
net_kg = max(0, tartim1 - tartim2)

Gruplandırma:
  brutPerKasa = net_kg / toplam_kasa
  Her grup:
    brut = grup.kasa × brutPerKasa
    Özel Dara checkbox işaretsiz → cinsi dara değerleri kullan
    Özel Dara checkbox işaretli  → satıra özgü kasa_dara_kg / palet_dara_kg
    dara = grup.palet × paletDaraUnit + grup.kasa × kasaDaraUnit
    net  = max(0, brut - dara)
```

**Fotoğraf sistemi:**
```
Canvas crop → data:image/jpeg;base64,... → POST
    → kantar_fisleri.foto_data (MEDIUMTEXT)
    → kantar_foto.php?id=X → Content-Type: image/jpeg (base64_decode)
Edit modda localStorage temizlenir → DB'den yüklenir (stale cache fix)
```

**Kritik eksikler:**
- `giris_tarih` / `cikis_tarih` VARCHAR → DATE'e migrate edilmeli
- `kasa_cinsi` / `palet_cinsi` text → material_definitions FK'ya bağlanmalı
- `foto_data MEDIUMTEXT` → dosya sistemi veya S3'e taşınmalı

---

### 5.4 Tanımlar Modülü

**Dosya:** `definitions.php`

**Ne işe yarıyor:** `material_definitions` tablosunu yönetir. Kasa cinsi, palet tipi ve tüm dara kalemlerinin ad/kg değerleri burada tanımlanır.

**Kritik önem:** Burada yapılan değişiklikler tüm sistemde anlık etkili. Bir kalem silinirse `loading_pallets.kasa_cinsi_id` NULL olur (FK SET NULL), dara hesabı bozulur.

**Eksikler:** Soft delete yok (silme fiziksel), audit log yok (kim ne zaman değiştirdi).

---

### 5.5 Raporlar Modülü

**Dosya:** `reports.php` (585 satır)

**Sorgu yapısı:**
```sql
SELECT r.*, COUNT(p.id) AS palet_sayisi,
       SUM(p.kasa_adeti) AS toplam_kasa,
       SUM(p.brut_kg)    AS toplam_brut,
       SUM(p.dara_kg)    AS toplam_dara,
       SUM(p.net_kg)     AS toplam_net
FROM loading_records r
LEFT JOIN loading_pallets p ON p.loading_record_id = r.id
WHERE r.type = ?  AND (filtreler...)
GROUP BY r.id
ORDER BY {sort_map whitelist}
LIMIT 2000;
```

Sıralama değerleri whitelist'ten gelir (`sort_map`), SQL injection riski yok. Toplam dara/net sütunları `round()` ile gösterilir (0.5 üzeri yuvarlanır).

**Eksikler:** Pagination yok (LIMIT 2000 sabit), grafik/chart yok.

---

### 5.6 Hesap Modülü

**Dosyalar:** `hesap*.php` (10 dosya)

**Ne işe yarıyor:** Temel gelir/gider takibi. Muhasebeciye aktarılacak belge işaretleme. Dosya ekleme.

**Durum:** Mobil alt bardan kaldırıldı. Yükleme kayıtlarındaki `nakliye_bedeli` alanı ile `account_transactions` arasında bağlantı yok — iki sistem bağımsız çalışıyor. `hesap_config.php` kategori listesini hard-code eder.

---

### 5.7 Notlar Modülü

**Dosyalar:** `notes.php`, `note_save.php`, `note_update.php`

**Ne işe yarıyor:** Bottom nav'daki "Not" butonu ile her sayfadan not eklenebilir. Sayfa adı/URL ile etiketlenir. "Claude için Kopyala" butonu notları Markdown formatında panoya kopyalar — bu geliştirici workflow aracı, son kullanıcı özelliği değil.

---

### 5.8 API Endpoint'leri

| Endpoint | İşlev |
|---|---|
| `api_bulk_material.php` | Birden fazla malzemeyi tek request'te bir palete ekler |
| `api_kalan.php` | Palet bazlı kalan ürün miktarı hesaplar (`kalan.js` kullanır) |
| `api_templates.php` | Malzeme şablonlarını listeler veya detay döner |

---

## 6. UI/UX ANALİZİ

### Mobil Kullanım (≤767px)
- Bottom navigation 5 sekme: Ana Sayfa · Yüklemeler · Not · Çıkmalar · Raporlar
- Palet ekleme modal ile yapılır (full-screen overlay, z-index: 600)
- Toplu Düzenle: yatay scroll'lu Excel tablosu
- Input'lar 16px font (iOS zoom engellendi)
- `overflow-x: clip` (iOS dikey scroll fix — `hidden` kullanılmaz)
- Safe area inset desteği (`env(safe-area-inset-bottom)`)

**Sorunlar:**
- Toplu Düzenle tablosunda 5+ kolon — tek elle yatay kaydırma zor
- Modal'da çok fazla alan (kasa, brüt, kasa cinsi, palet tipi, ürün, depo, malzemeler)
- Rapor tablosu geniş — yatay scroll zorunlu

### Tablet (768-1023px)
Grid 3 sütun. Tablo başlıkları gösterilir. Bottom nav görünmez, top nav aktif.

### Masaüstü (≥1024px)
Palet listesi tablo görünümüne geçer. Yazdırma modu (`print_mode=true`) için özel CSS kurallar.

### Z-index Mimarisi

| Katman | z-index |
|---|---|
| Topbar | 100 |
| Kebab dropdown | 200 |
| Bottom nav | 500 |
| Palet modal (.pm-overlay) | 600 |
| Kalan modal (#kalanModal) | 1000 |
| Etiket crop overlay | 3000 |

### Kullanıcı Deneyimi Eksikleri
1. **Taslak kaydetme yok** — sayfa yenilenince form kaybı
2. **Geri alma yok** — palet silinince kurtarılamaz
3. **Toplu işlem yok** — kayıtları seçip toplu sil/güncelle yok
4. **Klavye kısayolları yok** — masaüstünde verimsiz
5. **Hata mesajları** — bazı yerlerde `alert()` kullanılıyor

### Anomali Tespiti (iyi özellik)
`app.js` palet kartlarında kasa adeti ve brüt kg değerlerini ortalamadan %30'dan fazla sapma için uyarır (`pc-warn` class). Saha veri giriş hatalarını azaltır.

---

## 7. GÜVENLİK ANALİZİ

### 🔴 KRİTİK: Authentication Yok
Hiçbir sayfada login kontrolü yok. URL'yi bilen herkes:
- Tüm yükleme/çıkma/kantar verilerini görür, değiştirir, siler
- Finansal işlemlere erişir
- `definitions.php` üzerinden tüm dara değerlerini bozar

**Acil aksiyon gerektirir.**

### 🔴 KRİTİK: Açık Utility Dosyaları

```
/migrate.php          → DB migrasyonu çalıştırır
/diagnostik.php       → Sistem bilgisi döker
/deploy.php           → Deployment komutları (205 satır)
/repair_kasa_ids.php  → Toplu DB güncelleme
```

`.htaccess` ile bloklanmalı veya web root dışına taşınmalı:

```apache
<FilesMatch "^(migrate|diagnostik|deploy|repair_kasa_ids)\.php$">
    Require all denied
</FilesMatch>
```

### 🔴 KRİTİK: DB Credentials
`config/db.php`: `DB_USER = 'root'`, `DB_PASS = ''` — production'da kabul edilemez.

### CSRF — İyi Durumda ✅
`csrf_token()` / `csrf_check()` her form ve JSON endpoint'te kullanılıyor. `hash_equals()` ile timing-safe karşılaştırma. `random_bytes(24)` = 192 bit entropy.

### SQL Injection — İyi Durumda ✅
PDO prepared statements her yerde kullanılıyor. `PDO::ATTR_EMULATE_PREPARES => false` ile gerçek hazırlanmış sorgu. Dinamik `ORDER BY` için whitelist kontrol var.

### XSS — Büyük Ölçüde Korumalı ✅
`h()` fonksiyonu (`htmlspecialchars` wrapper) HTML çıkışlarında tutarlı. JS tarafında `escHtml()` DOM injection için kullanılıyor.

### Rate Limiting — Yok ⚠️
Sınırsız istek atılabilir. Özellikle kantar foto upload endpoint'i için sorun.

### Session Güvenliği ⚠️
`session.cookie_httponly`, `session.cookie_secure`, `session.cookie_samesite` açıkça set edilmemiş.

---

## 8. PERFORMANS ANALİZİ

### Ağır Sorgular

**`foto_data` içeren SELECT:**
```sql
-- YANLIŞ (her satırda 300-600 KB çekilir):
SELECT * FROM kantar_fisleri ORDER BY id DESC LIMIT 500;

-- DOĞRU:
SELECT id, fis_no, giris_tarih, plaka, firma_adi, net_kg, ... FROM kantar_fisleri ...;
-- foto_data sadece kantar_foto.php'de:
SELECT foto_data FROM kantar_fisleri WHERE id = ?;
```

**Migration IIFE:**
Her request'te `SHOW COLUMNS FROM kantar_fisleri` vb. sorgular çalışır. Düşük trafikte sorun yok ama ölçekte kaldırılmalı.

### N+1 Riski
`record_view.php` palet listesinde her paletin `pallet_materials` verisini ayrı sorguda çekiyorsa N+1 oluşur. JOIN ile tek sorguya indirilmeli.

### Büyük Veri Riski
`foto_data MEDIUMTEXT` — 1000 fişte ~500 MB DB boyutu. MySQL dump şişer, backup yavaşlar.

### Cache Önerileri
- `get_definitions_by_type()` aynı request içinde birden çok çağrılıyorsa PHP array cache eklenebilir
- SW cache shell stratejisi iyi, PHP sayfalar için uygun değil

### Lazy Loading İhtiyacı
Tanımlar (`MATERIALS`, `KASA_LIST` vb.) form sayfalarında JSON olarak inline edilir. Binlerce tanım eklenirse sayfa ağırlığı artar.

---

## 9. TEST ALTYAPISI

**Şu an test sistemi: TAMAMEN YOK.**

Unit test, integration test, E2E test, load test — hiçbiri yok.

### Kritik Test Senaryoları (Öncelik Sırasıyla)

**1. Dara Hesabı Doğruluğu**
- PHP `compute_pallet_row()` ve JS `calcPalletDara()` aynı sonucu vermeli
- Türkçe format: `"40.960"` → 40960 kg (nokta = binler ayracı)
- Edge: `kasa_cinsi_id = NULL` → dara = 0
- Edge: materials boş → extra_total = 0

**2. Yuvarlama Tutarlılığı**
- Per-palet yuvarlama YOK, sadece toplam yuvarlama
- `round($kasa_dara + $palet_dara + $extra, 3)`
- Floating point: `0.1 + 0.2 ≠ 0.3` — `round()` ile kapatılıyor

**3. Kantar Net**
- `net_kg = max(0, tartim1 - tartim2)` — negatif olamaz

**4. Type Kolonu Yönlendirmesi**
- Çıkma → `cikmalar.php`'ye yönlendirmeli
- Yükleme → `records.php`'ye

**5. Migration Idempotency**
- İkinci kez çalıştırılınca hiçbir şey değişmemeli

**6. CSRF Geçerliliği**
- Yanlış token → 400 die()
- Doğru token → işlem geçer

---

## 10. DEVOPS VE CANLI ORTAM

### Deployment
`deploy.php` (205 satır) mevcut — `git pull` + post-deploy işlemler. **Web root'ta açık — güvence altına alınmalı.**

### CI/CD
Yok. Manuel deployment.

### Git Workflow
Tek geliştirici + Claude Code asistanı. Feature branch: `claude/fix-records-print-mobile-WuKdT`. Pull request süreci net değil.

### Backup
Belirsiz. Paylaşımlı hosting otomatik backup'ı güvenilmez. `foto_data` yüzünden MySQL dump boyutu şişkin.

### Monitoring / Log / Error Tracking
Yok. PHP hata logu nereye gittiği belirsiz. Sentry veya benzeri entegre edilmeli.

### SSL
`nuverna.derspros.com.tr` — subdomain SSL durumu doğrulanmalı. Session cookie'lerin `secure` flag için HTTPS zorunlu.

### Hosting Riskleri
- Paylaşımlı hosting'de komşu site güvenlik açıkları
- `config/db.php` production'da farklı credentials — git pull bu dosyayı ezmemeli (CLAUDE.md'de belgelenmiş)

---

## 11. TEKNİK BORÇLAR

### 🔴 Kritik Borçlar

**1. Authentication yokluğu**
Her şeyden önce giderilmeli. Session-based login minimum gereksinim.

**2. `foto_data MEDIUMTEXT` mimarisi**
Base64 resim MySQL'de saklamak yanlış. Her `SELECT *` performans kaybı, backup şişmesi.

Doğrusu:
```php
// Mevcut (yanlış):
INSERT INTO kantar_fisleri (foto_data) VALUES (base64_jpeg_string);

// Doğru:
$filename = uniqid('kantar_', true) . '.jpg';
file_put_contents(__DIR__ . '/uploads/kantar/' . $filename, base64_decode($b64));
INSERT INTO kantar_fisleri (foto_path) VALUES (?);  -- sadece path sakla
// Görüntülemede: <img src="uploads/kantar/<?= h($fis['foto_path']) ?>">
```

**3. DB credentials hard-coded**
```php
// Mevcut:
const DB_USER = 'root';
const DB_PASS = '';

// Olması gereken:
const DB_USER = getenv('DB_USER') ?: 'app_user';
const DB_PASS = getenv('DB_PASS') ?: '';
```

**4. Utility dosyaları web root'ta açık**
`migrate.php`, `diagnostik.php`, `deploy.php`, `repair_kasa_ids.php`

### 🟡 Önemli Borçlar

**5. Duplicate kod: record_create.php vs cikma_create.php**
~%80 aynı. `$type` parametresiyle birleştirilebilir veya ortak `record_save()` fonksiyonu çıkarılabilir.

**6. `kantar_fisleri.giris_tarih/cikis_tarih` VARCHAR**
DATE tipine çevrilmeli. Data migration gerekli.

**7. `kantar_fisleri.kasa_cinsi/palet_cinsi` text**
`material_definitions.id` FK'ya bağlanmalı. Text eşleştirme kırılgan.

**8. Migration IIFE her request'te çalışıyor**
`schema_versions` tablosu ile one-shot migration'a geçilmeli.

**9. Tek CSS dosyası 2862 satır**
Print, kantar, hesap, records stilleri tek dosyada. Değişiklikte istem dışı etkilenme riski.

**10. `helpers.php` IIFE içinde veri normalizasyon kodu**
Depo adı normalizasyonu, duplicate temizleme her request'te çalışıyor. Tek seferlik data fix olmalıydı.

### 🟢 Küçük Borçlar

**11. `material_definitions` tek tabloda farklı amaçlar**
Lookup verisi (`firma`, `depo`) ile dara-hesaplayan veri (`kasa_cinsi`) ayrı tablolara bölünmeli.

**12. `record_view.php` 1290 satır**
Görüntüleme + yazdırma + stok analizi tek dosyada. Partial include'lara bölünmeli.

**13. `_kantar_form.php` 973 satır**
Hem PHP render hem JS logic aynı dosyada. JS ayrı `kantar.js` dosyasına taşınmalı.

---

## 12. GELECEK YOL HARİTASI

### Kısa Vadeli (0-3 Ay)

**Authentication & Authorization**
```php
// config/auth.php
function require_auth(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['logged_in'])) {
        header('Location: /login.php');
        exit;
    }
}
// Her sayfaya ekle:
require_once __DIR__ . '/config/auth.php';
require_auth();
```

Roller: Admin · Operator · Muhasebe · Readonly

**Fotoğraf Storage Refaktoru**
```
Mevcut: MEDIUMTEXT → base64 (~500 KB/satır)
Yeni:   uploads/kantar/{uniqid}.jpg → DB'de sadece dosya adı
Fayda:  DB ~%90 küçülür, SELECT hızlanır, backup küçülür
```

**DB Credentials**
`.env` dosyası veya server environment variables.

**Utility Dosyalarını Kapat**
`.htaccess` ile `Require all denied`.

### Orta Vadeli (3-12 Ay)

**OCR / Otomatik Fiş Okuma**
- Kantar fişi fotoğrafından plaka, tartım değerlerini çıkar
- Google Vision API veya Tesseract
- Çıkarılan veri formu pre-fill eder, kullanıcı onaylar

**Bildirim Sistemi**
- Kamyon gelişinde WhatsApp webhook veya email
- Yükleme tamamlanınca muhasebeciye bildirim
- Kantar fişi oluşturulunca SMS (plaka, net kg)

**Offline Çalışma**
- SW'ye IndexedDB tabanlı queue ekle
- Ağ kesilince formlar yerel yazılır, bağlantı gelince sync

**Gelişmiş Raporlama**
- Firma bazında haftalık/aylık özet grafik (Chart.js)
- Net kg trend grafiği
- Dashboard: bugün/bu hafta/bu ay özeti

**E-fatura Entegrasyonu**
- GİB e-arşiv API
- Yükleme kaydından fatura taslağı oluşturma

### Uzun Vadeli (12+ Ay)

**Çok Şirket / Çok Lokasyon**
```sql
CREATE TABLE companies (id INT PK, name VARCHAR, ...);
ALTER TABLE loading_records  ADD COLUMN company_id INT;
ALTER TABLE kantar_fisleri   ADD COLUMN company_id INT;
ALTER TABLE account_transactions ADD COLUMN company_id INT;
```

**Mobil Native App**
React Native veya Flutter. Offline-first, native OCR, push notification.

**Banka Entegrasyonu**
OFX/MT940 import → nakliye ödemeleri otomatik eşleşme.

**RBAC**
- Operator: sadece kendi kayıtları
- Muhasebe: raporlar + hesap modülü
- Admin: tüm sistem

---

## 13. MÜHENDİS DEVİR RAPORU

### Sistemin Güçlü Yönleri

1. **Sıfır bağımlılık:** Framework, paket yöneticisi yok. Herhangi bir PHP 8 + MySQL sunucuda çalışır. Deploy tek `git pull`.
2. **Otomatik migrasyon:** DB şeması otomatik güncellenir. Production'da kolon eksik olsa bile uygulama kendini düzeltir.
3. **Dara hesabı tutarlılığı:** PHP (`calc.php`) ve JS (`app.js`) aynı mantığı uygular. Yuvarlama sadece toplamda yapılır (birikimli hata yok).
4. **Mobil öncelik doğru yapılmış:** iOS scroll bug fix, zoom önleme, safe area, PWA manifest — detaylara dikkat edilmiş.
5. **CSRF ve SQL injection koruması:** PDO prepared statements tutarlı, CSRF token her formda.
6. **Anomali tespiti:** Ortalamadan %30 sapan palet değerleri renkli uyarıyla gösterilir. Saha hatalarını azaltır.

### Kritik Riskler

| Risk | Seviye | Etki |
|---|---|---|
| Authentication yok | 🔴 Kritik | Tüm veri açıkta |
| DB root/boş şifre | 🔴 Kritik | DB ele geçirilebilir |
| foto_data MEDIUMTEXT | 🔴 Kritik | DB büyümesi + performans çöküşü |
| Utility dosyaları açık | 🔴 Kritik | deploy.php, migrate.php vb. |
| Test yok | 🟡 Yüksek | Değişiklikler regresyon yaratabilir |
| Pagination yok | 🟡 Yüksek | Büyük veri setinde browser çöker |
| Backup tanımsız | 🟡 Yüksek | Veri kaybı riski |

### İlk Düzeltilmesi Gereken Alanlar (Öncelik Sırası)

**1. [BUGÜN] Utility dosyalarını kapat**
```apache
# .htaccess
<FilesMatch "^(migrate|diagnostik|deploy|repair_kasa_ids)\.php$">
    Require all denied
</FilesMatch>
```

**2. [BUGÜN] DB şifresini değiştir**
`root:''` → dedicated kullanıcı, güçlü şifre. Config'i environment variable'a taşı.

**3. [BU HAFTA] Authentication ekle**
```php
// config/auth.php — her sayfanın başına require_auth() ekle
function require_auth(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['logged_in'])) {
        header('Location: /login.php'); exit;
    }
}
```

**4. [BU AY] Foto storage refaktoru**
Base64'ü dosya sistemine taşı. MySQL row boyutu dramatik düşer.

**5. [BU AY] Kantar tarih alanları**
`giris_tarih/cikis_tarih` VARCHAR → DATE. Data migration gerekli.

### Production'a Çıkmadan Önce Kontrol Listesi

| Madde | Durum |
|---|---|
| CSRF koruması | ✅ Var |
| SQL injection koruması | ✅ Var |
| Authentication | ❌ Yok |
| Authorization | ❌ Yok |
| DB credentials güvenliği | ❌ root / boş şifre |
| Utility dosyaları kapalı | ❌ Açık |
| Error tracking | ❌ Yok |
| Backup otomasyonu | ❌ Belirsiz |
| SSL doğrulaması | ❌ Kontrol edilmeli |
| Rate limiting | ❌ Yok |

### Acil Teknik Borçlar (İlk Sprint)

1. Auth sistemi (session-based minimum)
2. DB credentials → `.env`
3. `foto_data` → dosya sistemi
4. Utility dosyaları web'den kapat
5. `kantar_fisleri.giris_tarih` → DATE tipi
6. Migration IIFE → `schema_versions` tablosu

### Ölçeklenme İçin Öneriler

**Mevcut mimaride (kısa vade):**
- `foto_data` kaldırılırsa DB ~%90 küçülür
- Raporlara pagination ekle (50-100 kayıt/sayfa)
- `get_definitions_by_type()` için PHP-level array cache

**Mimari değişiklik (orta vade):**
- Fotoğraflar → CDN veya object storage
- MySQL read replica (raporlar için)
- Redis / APCu — tanım verileri cache

**Framework geçişi (uzun vade):**
- Laravel veya Symfony — mevcut tablo şeması büyük ölçüde korunabilir
- API-first: backend JSON API, frontend Vue/React veya native app
- Multi-tenant: `company_id` her tabloya eklenir

---

> **Not:** Bu sistem tek kullanıcılı, single-tenant, küçük veri setli operasyon için işlevsel çalışıyor. Kritik güvenlik açıkları kapatılırsa ve foto storage refaktoru yapılırsa orta vadeli büyümeyi kaldırabilir. Framework geçişine gerek yok — mevcut mimari yeterince temiz, sorunlar çözülebilir seviyede.
