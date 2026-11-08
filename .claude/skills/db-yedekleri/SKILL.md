---
name: db-yedekleri
description: Veritabanı yedekleme (admin_db_backups.php, config/db_backup_helpers.php, sw.js indirme istisnası, cron). Yedek koduna dokunurken kullan.
---

# Veritabanı Yedekleri

> CLAUDE.md'den taşındı (v503). Çekirdek kurallar (Critical Rules, mobil, CSRF, migration, deploy) CLAUDE.md'de kalır.

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
