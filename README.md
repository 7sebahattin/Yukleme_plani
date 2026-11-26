# Asya Fresh — Operasyon ve ERP Yönetim Paneli (`Yukleme_plani`)

PHP 8.3 + MySQL/MariaDB ile geliştirilmiş, çerçevesiz (vanilla PHP + vanilla JS/CSS), mobil öncelikli ve PWA destekli tarım ihracat operasyon ve ön muhasebe yönetim sistemi.

- **Canlı:** `https://asya.scai.tr`
- **Test:** `https://test.scai.tr`
- **Ana Dal:** `main` (GitHub Push Webhook → otomatik deploy; bkz. `docs/DEPLOY_WORKFLOW.md`)

## Ana Modüller

- **Siparişler & Sevkiyat:** OGL Siparişler (`siparisler.php`), Frugro Siparişler (`frugro_siparis.php`, PKL/Fatura PDF & XLSX), Ürün Siparişleri (`urun_siparis.php`), Beyanlar (`beyanlar.php`), Palet Fişleri (`palet_fisleri.php`), Mal Kabul / Kantar (`kantar.php`).
- **Hal Kayıt Sistemi (HKS):** `halkayit/` altında gömülü SPA + SOAP entegrasyonu (`hks_firmalar`, taslaklar, gönderilenler, rüsum borç, kişi havuzu; şifreler AES-256-GCM ile şifrelenir).
- **Personel Takibi (PDKS):** Kart/NFC giriş-çıkış kiosku (`giris_cikis.php`), Günlük İşçi & Çavuş Hakediş (`config/pdks_hakedis.php`), Ters Kart Takip, Aylık Puantaj Çizelgesi, Günlük Üretim.
- **Muhasebe Çekirdeği (INSERT-Only Defter):** Cari (`cari_*`), Stok (`stok_*`), Fatura (`fatura_*`), Kasa (`kasa_*`), Çek-Senet (`cek_*`), Döviz & Günlük Kurlar (`gunluk_kurlar.php`, TCMB XML).
- **Maliyet & Hesap:** Maliyet Hesabı (`maliyet_*.php`), Hızlı Maliyet / Hızlı Hesapla, Personel Hesap/Avans (`hesap_*.php`).
- **Mail Merkezi:** Çok hesaplı IMAP/SMTP senkronizasyonu (`mail.php`), isteğe bağlı çeviri ve onaylı giden kutusu.
- **Yönetim & Güvenlik:** Rol/izin matrisi (`roles.php`, `users.php`), depo kapsamı (`depo_sec.php`), denetim izi (`audit_log`), masaüstü PIN ekran kilidi (`ekran_kilit.php`), şifreli DB yedekleme (`admin_db_backups.php`), sağlık kontrolü (`health.php`).

## Kurulum ve Yapılandırma

1. **Gereksinimler:** PHP 8.3+ (`pdo_mysql`, `mbstring`, `openssl`, `gd`, `zip`, `intl`, `fileinfo`, `xml`, `curl`), MySQL 8 / MariaDB 10.11+.
2. **Yerel Sırlar (`config/local.php`, `.gitignore`'da):**
   ```php
   <?php
   define('HKS_CRED_KEY', '<en-az-32-karakter-rastgele-anahtar>');
   define('MAIL_MASTER_KEY', '<base64-32-byte-anahtar>');       // opsiyonel (Mail Merkezi)
   define('HEALTH_CHECK_TOKEN', '<en-az-16-karakter-token>');   // opsiyonel (health.php)
   // Yerel/CI ortamında DB bilgilerini ezmek için (opsiyonel):
   // define('DB_LOCAL_HOST', '127.0.0.1');
   // define('DB_LOCAL_NAME', 'asya_dev');
   // define('DB_LOCAL_USER', 'root');
   // define('DB_LOCAL_PASS', '');
   ```
   İzinler: `chmod 600 config/local.php`.
3. **Veritabanı Şema Migrasyonu:**
   Çalışma zamanında otomatik DDL çalıştırılmaz (B-01). Yönetici oturumu ile `migrate.php` üzerinden ilgili kartlar (44 idempotent kart) kontrollü olarak çalıştırılır.

## Testler ve CI/CD

Tüm testler bellek içi SQLite veya ayrı MariaDB test veritabanı üzerinde çalışır; canlı veritabanına veya dış servislere asla bağlanmaz.

```bash
# Tüm PHP smoke ve birim testleri
bash scripts/tum_testler.sh

# Tarayıcı testleri (Playwright)
bash scripts/tum_testler.sh --yalniz-js

# MariaDB 10.11 entegrasyon ve eşzamanlılık (FOR UPDATE) testleri
ASYA_TEST_MYSQL_DSN='mysql:host=127.0.0.1;dbname=asya_test;charset=utf8mb4' \
ASYA_TEST_MYSQL_USER=root ASYA_TEST_MYSQL_PASS=secret \
  php scripts/migrate_mysql_smoke.php && php scripts/mysql_finans_race_smoke.php
```

GitHub Actions (`.github/workflows/testler.yml`) her PR ve `main` push'unda `php`, `php-lint`, `js` (Playwright) ve `mysql-integration` (MariaDB 10.11) işlerini çalıştırır.

## Ek Dokümantasyon

- `docs/ARCHITECTURE.md` — Gerçek teknik mimari, oturum, yetki, defter ve önbellek tasarımı
- `docs/DEPLOY_WORKFLOW.md` — `asya.scai.tr` / `test.scai.tr` webhook deploy akışı
- `docs/MAIL_OPERATIONS.md` — Mail Merkezi kurulum, anahtar rotasyonu ve cron ayarları
- `CLAUDE.md` — Modül kuralları ve skill dizini
