# Teknik Mimari (`docs/ARCHITECTURE.md`)

## 1. Temel Yığın

- **Arka Uç:** PHP 8.3+ (`strict_types=1`), PDO MySQL/MariaDB (`EMULATE_PREPARES=false`), çerçevesiz modüler çekirdekler (`config/*_core.php`).
- **Bağımlılıklar (`vendor/`):** `phpoffice/phpspreadsheet` (XLSX), `dompdf/dompdf` (PDF).
- **Ön Yüz:** Vanilla JS & CSS — ortak kabuk (`assets/style.css`, `assets/app.js`) + modül bazlı ayrıştırılmış CSS/JS dosyaları (`cari`, `stok`, `fatura`, `kasa`, `ceksenet`, `doviz`, `pdks`, `mail`, `hesap`, `maliyet`, `hizli-maliyet`, `frugro_pkl`, `palet-fisleri`, `kalan`).
- **PWA / Service Worker:** `manifest.json` (`orientation: any`) + `sw.js` (sıkı statik varlık allowlist'i: yalnız `/assets/*` ve `manifest.json` önbelleklenir; hiçbir kimlikli `.php` sayfası `CacheStorage`'a yazılmaz). Sürüm damgası `config/helpers.php` (`APP_SURUM`), `sw.js` (`CACHE_NAME`) ve `CLAUDE.md` arasında eşit tutulur.

## 2. Çekirdek Önyükleme ve Veritabanı

- **`config/db.php`:** Web isteklerinde `display_errors=0`, yapısal JSON `set_exception_handler`, `config/local.php` yükleme, `db_config_resolve()` (`DB_LOCAL_*` / `ASYA_DB_*` ezme desteği + canlı `deploy.php` `smart_merge_db` uyumu) ve tekil `db(): PDO` bağlantısı.
- **Tek PDO Paylaşımı:** `halkayit/db.php` (`hks_db()`) aynı DB hedeflendiğinde `db()` bağlantısını paylaşır; ek MySQL bağlantısı açmaz.
- **Facade ve Alt Yardımcılar:** `config/helpers.php` çekirdek kabuğu, `config/db_tx_helpers.php` (`db_tx`, `db_table_exists`, `db_for_update`), `config/cache_export_helpers.php` (`json_html`, `app_cache_*`, `export_prepare_runtime`), `config/role_permission_matrix.php`, `config/muhasebe_roller.php`, `config/muhasebe_referans.php` modüllerini birleştirir.
- **Sıfır Çalışma Zamanı DDL:** Tüm şema ve indeks güncellemeleri yalnızca yönetici tarafından `migrate.php` (54 idempotent kart) üzerinden yürütülür (`config/cekirdek_migrate.php` ve modül `*_migrate()` fonksiyonları; ERP Faz 1: `muhasebe_core.php`, `edonusum_core.php`, `erp_mutabakat_core.php`; ERP Faz 2: `wms_stok_core.php`, `yukleme_v2_core.php`, `satinalma_core.php`, `uretim_bom_core.php`; ERP Faz 3: `onay_core.php`, `banka_core.php`, `ik_bordro_core.php`).

## 3. Kimlik Doğrulama, Oturum ve Yetkilendirme

- **Kalıcı Oturum (`asya_session`):** `config/auth.php` token tabanlı `user_sessions` tablosunu kullanır (`HttpOnly`, `SameSite=Lax`, HTTPS'de `Secure`, 14 gün mutlak ömür, >300 sn aralıklı sliding güncelleme ve olasılıksal GC).
- **Brute-Force Koruması:** `login.php` IP + kullanıcı bazlı atomik `flock` artımlı bekleme (`storage/auth_rl`, HTTP 429).
- **PHP Oturumu (`PHPSESSID`):** `app_session_start()` (`Strict`, `HttpOnly`, `SameSite=Lax`), CSRF token ve flash mesajlar için kullanılır; girişte `app_session_regenerate()`.
- **Masaüstü Ekran Kilidi:** `config/ekran_kilit.php` + `ekran_kilit.php` — sunucu tarafı `hks_kv` kilidi (`HTTP 423 Locked`), PIN karma anahtarı `config/local.php` (`HKS_CRED_KEY`).
- **RBAC + Depo İzolasyonu:** `roles` / `role_permissions` + `config/role_data_scope.php` (aktif depo kapsamı `user_allowed_depots()` fail-closed) + `config/muhasebe_roller.php` (muhasebe sayfa/işlem kapıları).

## 4. Muhasebe ve Operasyon Çekirdekleri

- **Katman İzolasyonu:** `cari_core`, `stok_core`, `fatura_core`, `kasa_core`, `ceksenet_core`, `doviz_core` her biri kendi tablolarına tek yazıcıdır. Çapraz modül silme referans kontrolleri `config/muhasebe_referans.php` üzerinden yapılır.
- **INSERT-Only Defter ve Kilitler:** `stok_hareketler`, `cari_hareketler`, `kasa_hareketler`, `ceksenet_hareketler` değiştirilmez defter mantığıyla çalışır; iptaller ters hareketle işlenir. MySQL `REPEATABLE READ` altında eşzamanlı işlemlerde `FOR UPDATE` kilitleri (`stok_maliyet_durumu`, `fatura_kaydet`, `ceksenet_bordro_kaydet`, `pdks_hakedis_hesapla/finalize`) uygulanır.
- **Hal Kayıt (`halkayit/`):** `halkayit/index.php` ana panel kabuğunu çizer; `halkayit/app.php` / `app.html` ve `halkayit/api.php` (ana panel `asya_session` + `hks.access` + CSRF korumalı) SOAP servislerini çağırır. Firma şifreleri `HKS_CRED_KEY` ile `gcm1:` (AES-256-GCM, fail-closed) şifrelenir.

## 5. Gezinme ve Duyarlı Düzen

- **Mobil + Tablet (`<900px`):** `topbar` (sticky, Ana Sayfa + depo seçici + çıkış) + `bottomnav` (fixed alt çubuk + "Diğer" akordiyon alt sayfası).
- **Masaüstü (`≥900px`):** `desktop-sidebar` (sol sabit menü, tek `--sb-w` değişkeni: `180px` / `≥1280px` `210px`); topbar ve bottomnav gizlenir.
- **Yazdırma (`@media print` / `print_mode`):** Tüm gezinme kabuğu gizlenir.

## 6. Gözlemlenebilirlik, Yedekleme ve CI/CD

- **Denetim İzi (`audit_log`):** `_audit_sanitize()` hassas anahtarları (`password`, `pin`, `token`, `csrf`, `cookie`) maskeler. Eski kayıtlar `scripts/audit_retention_cron.php` ile `storage/audit_archive/audit_YYYY-MM.jsonl.gz` olarak arşivlenebilir.
- **Veritabanı Yedeği:** `scripts/db_backup_cron.php` (veya PHP-FPM altında `fastcgi_finish_request()` sonrası engellemesiz günlük tetikleme) gzip doğrulamalı yedek alır (`storage/backups/db`).
- **Sağlık Ucu:** `health.php` (`HEALTH_CHECK_TOKEN` veya `is_admin()` korumalı) DB ping JSON yanıtı döner.
- **CI/CD (`.github/workflows/testler.yml`):** `php` (230+ SQLite/statik smoke testi), `php-lint` (paralel `php -l`), `js` (Playwright) ve `mysql-integration` (gerçek MariaDB 10.11 üzerinde 2× migrasyon idempotency + `pcntl_fork` `FOR UPDATE` yarış testleri).
