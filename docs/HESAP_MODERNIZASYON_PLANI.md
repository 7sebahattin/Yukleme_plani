# Hesap Modülü Modernizasyon Planı

**Durum:** Faz 0 ve Faz 4 **tamamlandı** (bkz. §4 Uygulama Kaydı) — Faz 1-3 (UI/UX) ve Faz 5 (PDF) bekliyor
**Branch:** `claude/modernize-expense-tracking-vtdg73`
**Kapsam:** `hesap.php` · `hesap_liste.php` · `hesap_kayit.php` · `hesap_yazdir.php` ·
`hesap_muhasebe.php` · `hesap_muhasebe_fis_pdf.php` · `hesap_export.php` · `hesap_config.php` ·
`hesap_sil.php` · `hesap_dosya.php` · `hesap_dosya_sil.php`

---

## 0. Mevcut Durum Analizi

### 0.1 Teknoloji yığını — brief'teki varsayım yanlış

Brief "PHP, MySQL, Bootstrap (eski sürüm), jQuery" diyor. Gerçek durum:

| Varsayım | Gerçek |
|---|---|
| Bootstrap (eski) | **Yok.** Tek dosya `assets/style.css` (221 KB), CSS custom property tabanlı kendi tasarım sistemi |
| jQuery | **Yok.** Saf vanilla JS, `assets/app.js` (102 KB) |
| PHP + MySQL | ✅ Doğru — PHP 8.1, PDO, çerçevesiz |

`render_header()` yalnızca `assets/style.css` + `assets/app.js` yüklüyor
(`config/helpers.php:406`, `:536`). Composer'da tek bağımlılık `phpoffice/phpspreadsheet`;
`vendor/` git'e commit'li.

**Sonuç:** "Bootstrap 5 / Tailwind'e geç" adımı bu projede *modernizasyon değil, riskli bir
yeniden yazım* olur — 90+ sayfa aynı `style.css`'i paylaşıyor ve mobil düzen
(`overflow-x: clip`, safe-area, 768/900/1024/1280 breakpoint'leri) bu dosyaya bağlı.
CLAUDE.md'nin "Mobil görünümü bozma" kuralı da bunu yasaklıyor.

**Öneri:** Maliyet modülünün kurduğu emsali izle — modüle özel `assets/hesap.css` +
`assets/hesap.js`, yalnız `hesap_*` sayfalarında yüklenir, `style.css`'in mevcut design
token'larını (`--primary`, `--card`, `--border`, `--depot-accent`…) miras alır. Böylece
modern bir tasarım dili elde ederiz, diğer 90 sayfaya hiç dokunmadan.

### 0.2 Veritabanı şeması (mevcut)

```sql
-- config/db.php:100 ve config/helpers.php:798 (iki yerde tanımlı, birebir aynı)
CREATE TABLE `account_transactions` (
  `id`                     INT AUTO_INCREMENT PRIMARY KEY,
  `transaction_date`       DATE NOT NULL,
  `transaction_time`       TIME NOT NULL DEFAULT '00:00:00',
  `type`                   ENUM('gelir','gider','havale','nakit') NOT NULL,
  `category`               VARCHAR(100) NOT NULL DEFAULT '',
  `amount`                 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `currency`               VARCHAR(5) NOT NULL DEFAULT 'TRY',
  `payment_method`         VARCHAR(30) NOT NULL DEFAULT 'nakit',
  `person_company`         VARCHAR(200) NOT NULL DEFAULT '',
  `description`            TEXT NOT NULL DEFAULT '',
  `document_no`            VARCHAR(100) NOT NULL DEFAULT '',
  `has_invoice`            TINYINT(1) NOT NULL DEFAULT 0,
  `is_for_company`         TINYINT(1) NOT NULL DEFAULT 1,
  `is_given_to_accountant` TINYINT(1) NOT NULL DEFAULT 0,
  `notes`                  TEXT NOT NULL DEFAULT '',
  `has_files`              TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_date` (`transaction_date`),
  INDEX `idx_type` (`type`),
  INDEX `idx_accountant` (`is_given_to_accountant`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `account_files` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `transaction_id` INT NOT NULL,
  `file_name`      VARCHAR(255) NOT NULL,   -- 32 hex + uzantı, disk adı
  `original_name`  VARCHAR(255) NOT NULL DEFAULT '',
  `file_type`      VARCHAR(50)  NOT NULL DEFAULT '',
  `file_size`      INT NOT NULL DEFAULT 0,
  `uploaded_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_tid` (`transaction_id`),
  CONSTRAINT `fk_af_tid` FOREIGN KEY (`transaction_id`)
      REFERENCES `account_transactions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 0.3 Şemadaki üç kritik eksik

**1. Personel kimliği yok.** `account_transactions` tablosunda `user_id` / `created_by`
kolonu **yok**. Kaydı kimin girdiği hiçbir yerde saklanmıyor. Yani "personel bakiyesi"
bugün teknik olarak hesaplanamaz — brief'teki *"borç/alacak takibi düzgün işlemiyor"*
şikâyetinin kök nedeni budur. `person_company` serbest metin bir alan, kullanıcı tablosuna
bağlı değil.

**2. Durum (status) yok.** Tüm iş akışı iki boolean'a sıkışmış: `is_given_to_accountant`
ve `has_invoice`. Onay / red / ödeme kavramı hiç yok; muhasebe bir fişi reddedemiyor,
personel de reddedildiğini göremiyor.

**3. Depo kolonu yok.** Sistemdeki tek modül olarak Hesap, zorunlu aktif-depo mimarisinin
dışında (CLAUDE.md → "Yeni sorgu yazarken … UNUTMA"). Her depodaki her kullanıcı tüm mali
kayıtları görüyor.

### 0.4 Yetki matrisindeki kırık

`config/helpers.php:950-955` — rol → yetki eşlemesi:

```php
'muhasebe' => ['dashboard.read','records.read','stok.read','reports.read',
               'reports.export','beyan.read','maliyet.read','maliyet.write'],
```

Buna karşılık:

| Dosya | İstediği yetki | Muhasebe rolünde var mı? |
|---|---|:---:|
| `hesap.php` | `reports.read` | ✓ |
| `hesap_liste.php` | `reports.read` | ✓ |
| `hesap_kayit.php` | `records.write` | ✗ |
| **`hesap_muhasebe.php`** | **`records.write`** | **✗** |
| `hesap_sil.php` | `records.delete` | ✗ |
| `hesap_dosya_sil.php` | `records.write` | ✗ |

**Muhasebe rolündeki kullanıcı, kendi sayfası olan Muhasebe Dökümü'ne giremiyor.**
İş akışının "işlemiyor" olmasının ikinci somut nedeni. Hesap modülünün kendine ait
`hesap.*` yetkisi hiç tanımlanmamış; `records.*` ve `reports.*` ödünç alınmış.

### 0.5 Analiz sırasında bulunan hatalar

| # | Dosya | Sorun | Etki |
|---|---|---|---|
| B1 | `hesap_kayit.php:80` | `str_replace(['.',','],['','.'])` — "1234.56" yazılırsa **123456** olur | **Yüksek** — 100× tutar hatası |
| B2 | `hesap_yazdir.php:24-25` | `array_sum` para birimini yok sayıyor; USD + TRY toplanıyor | **Yüksek** — rapor toplamı yanlış |
| B3 | `hesap.php:36-38` | `bekleyen`/`fissiz`/`toplam_kayit` alt sorgularında `currency='TRY'` ve tarih filtresi yok | Orta — sayaçlar tüm tarihi kapsıyor |
| B4 | `hesap_liste.php:35-36` | Filtre `has_invoice`'a bakıyor, rozet `hesap_fis_durumu()`'na (has_files OR has_invoice) | Orta — "Fiş yok" filtresi fotoğraflı kayıtları listeliyor |
| B5 | `hesap_dosya.php` | Dosya adı DB'de var mı diye bakıyor ama **hangi kaydın** dosyası olduğuna bakmıyor | Orta — kimlik eklenince yetki açığı olur |
| B6 | `hesap_liste.php:249` | Sayfalama tüm sayfa linklerini basıyor, üst sınır yok | Düşük — 5.000 kayıtta 100 link |
| B7 | `hesap.php:29-38` | `$ay_bas`/`$ay_son` sorguya string olarak gömülüyor | Düşük — regex ile doğrulanmış, sömürülemez ama kural ihlali |
| B8 | `hesap_muhasebe.php:15` | Toplu güncellemede kayıt bazlı audit yok, yalnız sayaç | Düşük — mali izlenebilirlik zayıf |

### 0.6 UX bulguları (ekran görüntüsüyle uyumlu)

- **Renk kakofonisi:** Tek ekranda 6 hızlı buton × 4 farklı dolgu rengi (turuncu/kırmızı/
  yeşil/mavi) + 6 renkli istatistik kutusu + mavi birincil buton. Görsel hiyerarşi yok;
  hiçbir şey "birincil eylem" gibi görünmüyor.
- **Bilgi yığını:** Üstte 6 istatistik kutusu; hangisinin "asıl bakiye" olduğu belirsiz.
  Güncel Bakiye kutusu −1.349,60 ₺ gösteriyor ama neyin borcu olduğu okunmuyor.
- **Form ağırlığı:** `hesap_kayit.php` mobilde 15 alan + 3 checkbox istiyor. Fiş fotoğrafı
  formun **en altında** — brief'in "receipt-first" hedefinin tam tersi.
- **Mobil:** Kart/tablo ayrımı (`pc-only` / `mobile-only`) zaten var, taban fena değil;
  asıl sorun alan sayısı ve renk gürültüsü.
- **Rapor:** `hesap_yazdir.php` ekrana basılan bir HTML tablo; logo yok, fiş fotoğrafı yok.
  (`hesap_muhasebe_fis_pdf.php` fotoğraf dökümünü print-CSS ile yapıyor ve aslında iyi
  çalışıyor — atılacak değil, PDF hattına taşınacak.)

---

## 1. Uygulama Planı

Sıra bağlayıcı: **Faz 0 → 4 → 1 → 2 → 3 → 5**. Şema ve yetki düzeltmesi (Faz 0/4) önce
gelmeli, çünkü Faz 2'deki "bakiye kartı" ve "durum rozetleri" onlar olmadan gösterilemez.

### Faz 0 — Şema, yetki ve hata düzeltmeleri (ön koşul)

> **DB migration yalnızca açık "GO" ile çalışır** (CLAUDE.md kuralı). Aşağıdaki migration
> `hesap_migrate()` içinde idempotent yazılacak, otomatik tetiklenmeyecek; `migrate.php?run=hesap`
> ile ve yalnız onay verildiğinde koşacak.

**0.1 Yeni kolonlar** — `account_transactions`:

```sql
ALTER TABLE `account_transactions`
  ADD COLUMN `user_id`       INT NULL AFTER `id`,              -- masrafın sahibi personel
  ADD COLUMN `created_by`    INT NULL AFTER `user_id`,         -- kaydı giren
  ADD COLUMN `status`        VARCHAR(20) NOT NULL DEFAULT 'submitted' AFTER `is_given_to_accountant`,
  ADD COLUMN `submitted_at`  DATETIME NULL,
  ADD COLUMN `reviewed_by`   INT NULL,
  ADD COLUMN `reviewed_at`   DATETIME NULL,
  ADD COLUMN `review_note`   VARCHAR(500) NOT NULL DEFAULT '', -- red gerekçesi
  ADD COLUMN `paid_at`       DATETIME NULL,
  ADD COLUMN `depo`          VARCHAR(150) NOT NULL DEFAULT '',
  ADD INDEX `idx_at_user`   (`user_id`),
  ADD INDEX `idx_at_status` (`status`),
  ADD INDEX `idx_at_depo`   (`depo`(80));
```

**0.2 Geri dolum (backfill) kuralları**

- `status` = `is_given_to_accountant ? 'approved' : 'submitted'` — hiçbir eski kayıt
  "taslak"a düşmez, bakiyeler bugünkü değerinde kalır.
- `user_id` = NULL bırakılır. Depo mimarisindeki "atanmamış veri" kuralının aynısı:
  **sahipsiz kayıt herkese görünür**, hiçbir eski veri kaybolmaz/kilitlenmez.
- `depo` = '' bırakılır → `depo_sql_column()` boş depoyu tüm depolarda gösterir.
- `is_given_to_accountant` **silinmez**; `status` değiştikçe senkron tutulur, böylece
  `hesap_export.php` / `hesap_muhasebe_fis_pdf.php` gibi eski sorgular bozulmaz.

**0.3 Yetkiler** — `config/helpers.php:950` kataloğuna ekle:

`hesap.read` · `hesap.write` · `hesap.delete` · `hesap.approve` · `hesap.pay` · `hesap.admin`

| Yetki | admin | operator | viewer | muhasebe |
|---|:---:|:---:|:---:|:---:|
| hesap.read | ✓ | ✓ | ✓ | ✓ |
| hesap.write | ✓ | ✓ | — | ✓ |
| hesap.delete | ✓ | — | — | — |
| hesap.approve | ✓ | — | — | ✓ |
| hesap.pay | ✓ | — | — | ✓ |
| hesap.admin | ✓ | — | — | — |

Tüm `hesap_*.php` dosyalarında `require_perm('records.write')` → `require_hesap('write')`.
`hesap.read` yetkisi olan ama `hesap.admin` olmayan kullanıcı **yalnız kendi kayıtlarını**
(+ sahipsiz kayıtları) görür.

**0.4 Hata düzeltmeleri** — B1…B8, her biri ayrı commit:
- B1: `hesap_parse_amount()` — son ayırıcıyı ondalık kabul eden tek fonksiyon,
  `hesap_config.php` içinde; hem TR ("1.234,56") hem EN ("1234.56") girdisini doğru okur.
- B2/B3: tüm toplamlar `GROUP BY currency`; ekranda yalnız TRY toplanır, diğer para
  birimleri ayrı satır olarak gösterilir.
- B4: filtre de `hesap_fis_durumu()` mantığına geçer (`has_files=1 OR has_invoice=1`).
- B5: `hesap_dosya.php` dosyayı kaydın sahibi/yetkisi üzerinden doğrular.
- B6: sayfalama pencereli (ilk/son + ±2).
- B7: `$ay_bas`/`$ay_son` bind parametresine taşınır.
- B8: toplu onayda kayıt başına `audit_log_event('approve','hesap',$id,…)`.

**Çıktı:** `config/hesap_calc.php` (yeni) — `hesap_migrate()`, `hesap_statuses()`,
`hesap_can_transition()`, `hesap_parse_amount()`, `hesap_balance()`.

---

### Faz 1 — Tasarım sistemi

Bootstrap/Tailwind **eklenmeyecek** (gerekçe §0.1). Bunun yerine:

- `assets/hesap.css` — yalnız `hesap_*` sayfalarında `<link>` ile yüklenir
  (`maliyet.css` emsali, `maliyet.php:112`).
- Token'lar `style.css`'ten miras: `--primary`, `--card`, `--border`, `--muted`,
  `--radius`, `--depot-accent`. Yeni: `--hesap-pos` (alacak), `--hesap-neg` (borç),
  `--hesap-pending`.
- **Tek birincil buton kuralı:** Sayfada yalnız bir dolu buton (yeni kayıt). Diğer her şey
  ghost/outline. Renk sadece iki yerde anlam taşır: tutar işareti ve durum rozeti.
- Durum rozeti bileşeni `.hs-badge--{draft|submitted|approved|pending|paid|rejected}`.
- Mobil öncelik: 44px dokunma hedefi, `font-size:16px` (iOS zoom), safe-area padding,
  yeni modal z-index ≥ 600.

**Dokunulmayacak:** `assets/style.css`'in `< 768px` blokları, `overflow-x: clip`.

---

### Faz 2 — `hesap.php` yeniden tasarımı

- **Bakiye kartı (tek, büyük):** "Şirket size **X ₺** borçlu" (yeşil) veya "Şirkete **X ₺**
  borçlusunuz" (kırmızı). Altında iki küçük satır: *Onay bekleyen* ve *Ödeme bekleyen*.
  Rakam yalnız `approved | pending_payment | paid` durumlarından hesaplanır (§Faz 4).
- **Birincil eylem:** Mobilde sabit alt FAB — `📷 Fiş Ekle`. Masaüstünde sağ üstte tek buton.
- 6 renkli hızlı buton → FAB'a basınca açılan **alt sayfa (bottom sheet)** içinde nötr
  listeye dönüşür; renk yok, ikon + etiket.
- 6 istatistik kutusu → bakiye kartı + katlanabilir "Ay özeti" bloğu.
- **Son 5 işlem** kart listesi: kategori, tarih, tutar, **durum rozeti**, fiş küçük resmi.
- 5 modül linki → "⋯ Daha fazla" menüsü (Tüm Kayıtlar / Muhasebe / Excel / PDF).
- Ay gezinme çubuğu korunur.

---

### Faz 3 — `hesap_kayit.php` sadeleştirme

Yeni sıralama (mobilde tek kolon, üstten alta):

1. **Fiş fotoğrafı** — sayfanın en üstü, büyük kamera hedefi. Mevcut `hesapPhoto*` /
   `hesapCrop*` JS'i korunur (iyi çalışıyor), `assets/hesap.js`'e taşınır.
2. **Tutar** — `inputmode="decimal"`, büyük punto, otomatik odak.
3. **Kategori** — açılır liste yerine chip ızgarası (son kullanılan 6 kategori önce).
4. **Kaydet.**

Geri kalan her şey — saat, para birimi, ödeme yöntemi, kişi/firma, açıklama, belge no,
3 checkbox, muhasebe notu — **"Detaylar" akordeonu** içinde, varsayılan kapalı.
Tarih/saat otomatik dolar (bugün/şimdi), akordeonda değiştirilebilir.

Hedef: fotoğraf + tutar + kategori + kaydet = **4 dokunuş**.

Ek: yeni kayıtta `user_id = current_user()['id']`, `depo = active_depot()` damgası;
`status = 'submitted'` (taslak olarak kaydet seçeneği ayrı buton).

---

### Faz 4 — Muhasebe iş akışı

**Durum makinesi** (DB'de kod, ekranda Türkçe etiket):

| Kod | Etiket | Kim geçirir | Sonraki |
|---|---|---|---|
| `draft` | Taslak | sahibi (`hesap.write`) | submitted, (silme) |
| `submitted` | Gönderildi | sahibi | draft, approved, rejected |
| `approved` | Muhasebe Onayladı | `hesap.approve` | pending_payment, paid, rejected |
| `pending_payment` | Ödeme Bekliyor | `hesap.approve` | paid, rejected |
| `paid` | Ödendi | `hesap.pay` | (kapalı — `hesap.admin` geri alabilir) |
| `rejected` | Reddedildi | `hesap.approve` (gerekçe **zorunlu**) | draft |

- Her geçiş `audit_log_event('status_change','hesap',$id,$old,$new)`.
- `paid` durumu kayıt kilidi: içerik değişikliği `hesap.admin` ister
  (yükleme modülündeki `yuklendi` kilidinin aynısı).
- **Bakiye formülü** (kullanıcı ve para birimi bazında, karıştırmadan):

  ```
  bakiye(user, cur) = Σ amount[type=gelir]
                    − Σ amount[type IN (gider, nakit, havale)]
    WHERE status IN ('approved','pending_payment','paid')
      AND currency = cur
      AND (user_id = :uid OR user_id IS NULL)
  bekleyen(user, cur) = aynı toplam, status IN ('draft','submitted')
  ```

- `hesap_muhasebe.php` → onay kuyruğu: satır satır **Onayla / Reddet (gerekçe) / Ödendi**,
  toplu onay korunur; grup başlıkları kategori bazında kalır.
- `hesap_liste.php` → durum filtresi pill'leri + her satır/kartta rozet.
- `hesap.php` → son işlemler listesinde rozet; reddedilenler en üste "Düzeltilmesi gereken
  N fiş" uyarısı olarak çıkar.

---

### Faz 5 — PDF raporlama

**Kütüphane seçimi:** `dompdf/dompdf` (~4 MB vendor). mPDF Türkçe/UTF-8 desteği daha
zengin ama vendor'a ~30 MB ekliyor ve bu repo `vendor/`'ı git'e commit'liyor. dompdf,
DejaVu Sans gömülü fontuyla Türkçe karakterleri sorunsuz basıyor ve bizim ihtiyacımız
(tablo + resim) için yeterli.

> Vendor ağırlığı kabul edilmezse alternatif: mevcut print-CSS hattını `@page` kuralları,
> logo ve fiş ekleriyle güçlendirip tarayıcı "PDF olarak kaydet" akışında bırakmak —
> sıfır bağımlılık, karşılığında sunucu tarafında PDF üretilemiyor (e-posta/arşiv yok).

**Yeni dosya:** `hesap_rapor_pdf.php`

1. Kapak: Asya Fresh logosu (`assets/logo.jpg`), tarih aralığı, depo, hazırlayan, rapor no.
2. Personel özeti: kişi başına gider / gelir / net bakiye + durum kırılımı.
3. Kategori kırılımı: kategori × tutar × yüzde (+ basit yatay bar).
4. Detay tablosu: tarih, kategori, kişi, tutar, durum rozeti, fiş var/yok.
5. **Ekler:** her fişin fotoğrafı, üstünde kayıt künyesi — `hesap_muhasebe_fis_pdf.php`'nin
   3×3 ızgara yerleşimi PDF'e taşınır.
6. Altbilgi: sayfa x/y, üretim zamanı, imza alanları (Personel / Muhasebe).

`hesap_yazdir.php` hızlı ekran-yazdırma olarak kalır (§B2 düzeltilmiş hâliyle);
PDF ayrı bir butondur. Her PDF üretimi `audit_log_event('export','hesap',…)` yazar.

---

## 2. Riskler ve Kurallar

| Risk | Önlem |
|---|---|
| Mobil düzenin bozulması | `style.css`'e dokunulmaz; her şey `assets/hesap.css` içinde |
| Eski verinin kaybolması | `user_id`/`depo` NULL/boş bırakılır → "atanmamış veri herkese görünür" kuralı |
| Eski sorguların kırılması | `is_given_to_accountant` korunur ve `status` ile senkron tutulur |
| Migration | Yalnız açık **GO** ile; idempotent; öncesinde `admin_db_backups.php` ile yedek |
| SW cache | Kritik dosya değişiminde `sw.js` sürümü artırılır (`yukleme-plani-v166` → v167) |
| Yetki genişlemesi | `hesap.*` yetkileri mevcut rollere §0.3 tablosuna göre atanır, admin dışında kimseye yeni ayrıcalık verilmez |

## 3. Teslim Sırası

| Adım | İçerik | Bağımlılık |
|---|---|---|
| 1 | Faz 0.4 — B1…B8 hata düzeltmeleri | yok (şemasız, hemen gidebilir) |
| 2 | Faz 0.1–0.3 — şema + yetki (**GO gerekli**) | 1 |
| 3 | Faz 4 — durum makinesi + bakiye mantığı | 2 |
| 4 | Faz 1 — `assets/hesap.css` tasarım sistemi | yok (paralel) |
| 5 | Faz 2 — `hesap.php` panosu | 3, 4 |
| 6 | Faz 3 — `hesap_kayit.php` formu | 3, 4 |
| 7 | Faz 5 — PDF | 3 |

Adım 1 bugün başlatılabilir ve tek başına ölçülebilir fayda verir (100× tutar hatası + yanlış
rapor toplamı). Adım 2 açık onay bekler.

---

## 4. Uygulama Kaydı — Faz 0 + Faz 4 (tamamlandı)

### Yeni dosyalar

| Dosya | İçerik |
|---|---|
| `config/hesap_calc.php` | Modül çekirdeği: `hesap_migrate()`, `hesap_parse_amount()`, durum makinesi, `hesap_balance()`, yetki kapısı |
| `hesap_durum.php` | Durum geçişi JSON uç noktası (tekil + toplu) |
| `assets/hesap.css` | Modüle özel stiller — şimdilik yalnız durum rozeti/eylem bileşenleri (Faz 1'de genişleyecek) |
| `scripts/hesap_smoke.php` | 43 assertion; bellek içi SQLite, canlı DB'ye dokunmaz |

### Şema

`account_transactions` + `user_id`, `created_by`, `status`, `submitted_at`, `reviewed_by`,
`reviewed_at`, `review_note`, `paid_at`, `depo` (+3 indeks).

Migrasyon üç yoldan da idempotent çalışır: `hesap_migrate()` (sayfa açılışında),
`migrate.php` paneli, ve yeni kurulumlar için `CREATE TABLE` tanımları (db.php + helpers.php).

**Geri dolum marker gerektirmez:**
`UPDATE … SET status='approved' WHERE status='submitted' AND is_given_to_accountant=1`
Koşul yalnız geri doldurulmamış eski satırlarla eşleşir — `hesap_transition()` iki alanı her
zaman senkron tuttuğu için gerçek bir `submitted` kaydında bayrak daima 0'dır. Bu sayede
kolonu `migrate.php` önce eklese bile (DEFAULT 'submitted') düzeltme yine de uygulanır.

### Yetkiler

`hesap.read` / `.write` / `.delete` / `.approve` / `.pay` / `.admin` eklendi ve rollere
işlendi. **Muhasebe rolü artık kendi sayfasına girebiliyor** (§0.4'teki kırık giderildi).
`hesap_can()` geçiş dönemi için eski yetkilere (`reports.read` / `records.write`) düşer,
böylece `role_permissions` seed'i çalışmadan önce de modül erişilebilir kalır.

### Hata düzeltmeleri

| # | Durum | Not |
|---|---|---|
| B1 | ✅ | `hesap_parse_amount()` — 13 vaka test edildi, "1234.56" artık 1234.56 |
| B2 | ✅ | `hesap_yazdir.php`, `hesap_export.php`, `hesap_liste.php`, `hesap_balance()` — hepsi `GROUP BY currency` |
| B3 | ✅ | Sayaçlar kullanıcı + depo kapsamında, tarih filtresi doğru bind ediliyor |
| B4 | ✅ | Fiş filtresi rozetle aynı mantıkta (`has_files=1 OR has_invoice=1`) |
| B5 | ✅ | `hesap_dosya.php` / `hesap_dosya_sil.php` — `hesap_row_visible()` ile kayıt sahipliği doğrulanıyor |
| B6 | ✅ | Sayfalama pencereli (ilk/son + geçerli ±2) |
| B7 | ✅ | `hesap.php` tarih parametreleri bind ediliyor, string interpolasyon kalmadı |
| B8 | ✅ | Toplu onay `hesap_transition()` üzerinden — kayıt başına audit |

### Test

```
php scripts/hesap_smoke.php     → 43/43 OK
php -l   (tüm dokunulan dosyalar) → temiz
```

Kapsam: bakiye kapsamı (personel/muhasebe), para birimi ayrımı, red/onay yetkileri,
gerekçe zorunluluğu, ödeme kilidi, legacy bayrak senkronu, geri dolum idempotanlığı,
tutar ayrıştırma regresyonu.

### Faz 1-3'e devredilen

Bu turda **UI'ya bilinçli olarak dokunulmadı** — mevcut düzen korunarak yalnız durum
rozetleri, bakiye etiketi ve eylem butonları eklendi. Renk kakofonisi, 6 istatistik kutusu,
15 alanlı form ve receipt-first akış Faz 1-3'ün konusu.
