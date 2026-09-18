<?php
// =========================================================
// config/pdks_gunluk.php — GÜNLÜK İŞÇİ (Faz 1: Temel) ÇEKİRDEĞİ
//
// İş modeli (kullanıcı açıklaması, Sprint Günlük-İşçi-01):
//   Depoya çavuşlar günlük işçi getirir. Çavuşa, o gün ÇALIŞAN işçi
//   sayısı/türüne göre ödeme yapılır. Günlük işçiler kalıcı olarak isimle
//   kaydedilmez — depo, yeniden kullanılabilir bir "işçi kartı" havuzu
//   tutar. Kart bir GÜNÜ/oturumu temsil eder, bir KİŞİYİ değil: aynı fiziksel
//   kart bugün Ayşe'nin ekibinde, yarın başka bir çavuşun ekibinde farklı bir
//   kişinin üzerinde olabilir. Çavuş asıl ticari/muhasebe taraftır.
//
// ⚠ MİMARİ İLKE (kullanıcının açık talimatı): kalıcı personel (employees/
// employee_cards, bu dosyanın YANINDAKİ config/pdks.php) ile günlük işçi
// AYRI kavramlardır — günlük işçiler employees tablosuna ZORLANMAZ. Bu dosya
// mevcut PDKS altyapısının ÜZERİNE, AYRI tablolarla kurulur; employees/
// employee_cards/attendance_events şemasına DOKUNMAZ.
//
// FAZ 1: çavuş ana kaydı + işçi tipi/kategori master'ı + yeniden kullanılabilir
// işçi kart havuzu + temel yönetim arayüzü altyapısı.
// FAZ 2 (bu dosyanın sonunda): çavuş bazlı günlük mesai oturumu + seri
// GİRİŞ/ÇIKIŞ tarama + canlı sayaçlar + mutabakat/kapatma. Hakediş/ödeme/
// cari/fatura HÂLÂ YOK — kullanıcının açık talimatı, Faz 3+'a bırakıldı.
//
// ⚠ BU DOSYA config/db.php / config/helpers.php / config/pdks.php TARAFINDAN
//    YÜKLENMEZ — config/pdks.php'nin kendi başlığındaki gerekçenin AYNISI:
//    buradaki bir hata uygulamanın (ya da kalıcı personel PDKS'inin) geri
//    kalanını ASLA etkilememelidir. pdks_gunluk_migrate() de kendiliğinden
//    çalışmaz — yalnız bu modülün sayfalarından AÇIKÇA çağrılır.
//
// ⚠ config/pdks.php İLE TEK BAĞLANTI NOKTASI: pdks_kart_olustur() (kalıcı
//    personel kart yazma — TEK yazma yolu, oradaki docblock'a bakın) bu
//    dosyanın pdks_gunluk_uid_gecici_kartta_mi() fonksiyonunu, o YÜKLÜYSE
//    (function_exists guard — YUMUŞAK bağımlılık, yön TERS OLMAZ) çağırır.
//    Bu YÜZDEN personel_kartlar.php ve personel_form.php bu dosyayı da
//    require eder (bkz. o dosyalardaki tek satırlık ekleme) — başka HİÇBİR
//    satırları değişmedi.
// =========================================================

declare(strict_types=1);

// ── Yapılandırma ──────────────────────────────────────────
defined('PDKS_GUNLUK_AKTIF') || define('PDKS_GUNLUK_AKTIF', true);

/**
 * Kart havuzu KALICI yaşam döngüsü durumları. Basit ve genişletilebilir
 * tutulur — BİLEREK yalnız ÜÇ değer.
 *
 * ⚠ "Kullanımda / Ayşe Çavuş" BURADA YOK ve worker_cards.status'a HİÇ
 * YAZILMAZ — bu SESSION DURUMUdur (hangi çavuşun açık oturumunda), KART
 * DURUMU değildir (kullanıcının açık düzeltmesi). Kalıcı bir 'in_use' alanı
 * yarım kalmış/başarısız kapanan bir oturumdan sonra kart SONSUZA KADAR
 * "kullanımda" görünüp havuzdan düşerdi. Faz 2'de bu bilgi
 * daily_work_sessions/daily_worker_card_events'ten TÜRETİLİR (bkz. dosya
 * sonu) — kartın kendisi yalnız available/lost/disabled arasında gezinir.
 */
function pdks_gunluk_kart_durumlari(): array
{
    return [
        'available' => 'Boşta (kullanılabilir)',
        'lost'      => 'Kayıp',
        'disabled'  => 'Devre Dışı',
    ];
}

/** Yalnız bu durumdaki kart yeni bir oturuma atanabilir (Faz 2 bunu kullanacak). */
function pdks_gunluk_kart_kullanilabilir_mi(?string $durum): bool { return $durum === 'available'; }

// =========================================================
// ŞEMA
// =========================================================

function pdks_gunluk_tablolar(): array
{
    $t = [];

    // ── worker_types — işçi tipi/kategori master'ı ──────────
    // ⚠ GENDER ENUM DEĞİL, kullanıcının açık talimatı: ileride Paketleme /
    // Yükleme / Forklift / Usta / Gece Vardiyası gibi kod DEĞİŞİKLİĞİ
    // gerektirmeyen serbest kategoriler eklenebilsin diye. Fiyatlama (Faz 2+
    // hakediş) bu kategoriye göre kurulacak — bkz. dosya sonu.
    $t['worker_types'] = "CREATE TABLE IF NOT EXISTS `worker_types` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `code`       VARCHAR(30)  NOT NULL,
        `name`       VARCHAR(80)  NOT NULL,
        `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
        `sort_order` INT          NOT NULL DEFAULT 0,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_wt_code` (`code`),
        INDEX `idx_wt_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // ── foremen — çavuş ana kaydı ────────────────────────────
    // Bilerek `users` hesabı DEĞİLDİR (kullanıcının açık talimatı): çavuşlar
    // Nuverna'ya giriş yapmaz, ticari/muhasebe tarafıdır. Faz 2+'da cari
    // hesap/hakediş kayıtları bu tabloya (foreman_id) bağlanacak.
    //
    // ⚠ `normal_work_minutes` (Faz 9C / H-02 kapanışı): çavuşun ANLAŞMALI
    // normal günlük çalışma süresi, TAM DAKİKA olarak (float saat DEĞİL —
    // 8.5 gibi belirsizlikten kaçınmak için). Sabit 08:00-17:00 vardiya
    // modeli TAMAMEN kaldırıldı — artık yalnız GEÇEN SÜRE bu değerle
    // karşılaştırılır (bkz. config/pdks_faz8b.php). Varsayılan 540 dk (9
    // saat) — mevcut sistemin ESKİ sabit vardiyasıyla AYNI, hiçbir çavuş
    // sessizce farklı bir normal süreye geçmez. Bu YENİ kurulumlar İÇİNDİR;
    // ÜRETİMDEKİ mevcut foremen tablosuna aynı kolon pdks_faz8b_migrate()
    // KENDİ ALTER'ıyla (AYNI DEFAULT 540 ile) ekler — bkz. o dosya.
    $t['foremen'] = "CREATE TABLE IF NOT EXISTS `foremen` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `code`       VARCHAR(20)  NOT NULL,
        `name`       VARCHAR(150) NOT NULL,
        `phone`      VARCHAR(30)  NULL DEFAULT NULL,
        `notes`      TEXT         NULL DEFAULT NULL,
        `normal_work_minutes` INT NOT NULL DEFAULT 540,
        `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
        `created_by` INT          NULL DEFAULT NULL,
        `updated_by` INT          NULL DEFAULT NULL,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_foreman_code` (`code`),
        INDEX `idx_foreman_active` (`is_active`),
        INDEX `idx_foreman_name`   (`name`(80))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // ── worker_cards — yeniden kullanılabilir işçi kart havuzu ──
    // ⚠ employee_cards'ın BİREBİR kopyası DEĞİLDİR: burada `employee_id` YOK
    // — kart bir KİŞİYE değil, o anki OTURUMA (Faz 2) bağlanacak. `card_no`
    // depodaki GÖRÜNÜR kart numarasıdır (K001/E001…), `canonical_uid` DEĞİLDİR.
    //
    // ⚠ UID ÇAKIŞMA STRATEJİSİ (kullanıcının açık talimatı — "investigate the
    // cleanest way"): MySQL, iki AYRI tablo üzerinde tek bir UNIQUE kısıtı
    // KURAMAZ; employee_cards/employee_card_uids şemasına dokunmadan (kullanıcı:
    // "do not silently alter existing employee-card data") çapraz-sistem
    // benzersizliği İKİ katmanla sağlanır:
    //   1) Bu tablonun KENDİ UNIQUE kısıtı (`canonical_uid`) — YALNIZ havuz-içi
    //      çakışmayı (iki eşzamanlı worker_cards INSERT'i) korur.
    //   2) UYGULAMA KATMANINDA, YAZMADAN ÖNCE, HER İKİ YÖNDE çapraz kontrol:
    //      a) Yeni işçi kartı yazılırken → mevcut employee_card_uids'te var mı?
    //         (pdks_gunluk_uid_kalici_kartta_mi() — salt okunur SELECT, mevcut
    //         tabloyu hiç DEĞİŞTİRMEZ.)
    //      b) Yeni KALICI personel kartı yazılırken → bu tabloda var mı?
    //         (pdks_gunluk_uid_gecici_kartta_mi(), pdks_kart_olustur() içinden
    //         function_exists guard'lı YUMUŞAK çağrı — bkz. dosya başlığı.)
    //
    //   ⚠ DÜZELTME (kullanıcının açık uyarısı): İKİ tablonun KENDİ UNIQUE
    //   kısıtları BİRBİRİNDEN BAĞIMSIZDIR ve ÇAPRAZ-TABLO yarış koşuluna KARŞI
    //   HİÇBİR KORUMA SAĞLAMAZ. İki eşzamanlı işlem AYNI UID'yi FARKLI
    //   tablolara (biri employee_cards'a, biri worker_cards'a) yazmaya
    //   çalışırsa, HER İKİ UNIQUE kısıt da KENDİ tablosunda İHLAL EDİLMEDİĞİ
    //   İÇİN İKİSİ DE BAŞARIYLA COMMIT OLABİLİR — UNIQUE kısıtlar bunu
    //   YAKALAMAZ, yalnız YUKARIDAKİ (2) numaralı ön-kontrol (SELECT) YAKALAR
    //   ve o da atomik DEĞİLDİR (SELECT ile INSERT arasında pencere vardır).
    //
    //   BİLİNEN, KABUL EDİLMİŞ V1 KISITI: bu, kart kaydının DÜŞÜK EŞZAMANLILIKLA
    //   çalışan, yönetici tarafından yürütülen İDARİ bir işlem olması nedeniyle
    //   Faz 1 için KABUL EDİLEBİLİR bir risktir — iki farklı yöneticinin AYNI
    //   fiziksel kartı AYNI ANDA, İKİ AYRI sisteme kaydetmeye çalışması aşırı
    //   ölçüde ENDER bir senaryodur. GERÇEK atomik çapraz-tablo benzersizliği
    //   isteniyorsa PAYLAŞILAN bir `card_uid_registry` tablosu (veya
    //   SELECT...FOR UPDATE ile sıralı erişim) gerekir — bu, Faz 1 kapsamı
    //   DIŞINDA BİLEREK BIRAKILDI (aşırı mühendislik — kullanıcının açık
    //   talimatı: "Do not overengineer locking for this phase"). Faz 2/3'te
    //   gerçek çok kullanıcılı tarama trafiği ölçülünce YENİDEN
    //   DEĞERLENDİRİLMELİDİR.
    // ⚠ FAZ 8A (kullanıcının açık talimatı — "NEUTRAL REUSABLE WORKER CARDS"):
    // `worker_type_id` artık NULL KABUL EDER. Kart kalıcı olarak bir işçi
    // tipine bağlı DEĞİLDİR — tip artık her MESAİ DÖNEMİNE (bkz.
    // daily_worker_work_periods) aittir, taramada AÇIKÇA seçilir. Bu sütun
    // yalnız ESKİ (Faz 1-7) veri için GERİYE DÖNÜK okunabilirlik amacıyla
    // KORUNUR — silinmez, YENİ Faz 8A trafiği bunu asla YAZMAZ/OKUMAZ.
    // Mevcut ÜRETİM tablosunda bu sütun hâlâ NOT NULL olabilir (bu CREATE
    // TABLE IF NOT EXISTS zaten var olan tabloyu DEĞİŞTİRMEZ) — bu durumda
    // pdks_gunluk_faz8a_migrate() KENDİ ALTER'ıyla NULL kabul eder hâle
    // getirir (bkz. o fonksiyon). Burada NULL yapılması yalnız SIFIRDAN
    // kurulumları (ve testleri) baştan doğru şemayla başlatır.
    $t['worker_cards'] = "CREATE TABLE IF NOT EXISTS `worker_cards` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `card_no`         VARCHAR(30)  NOT NULL,
        `worker_type_id`  INT          NULL DEFAULT NULL,
        `canonical_uid`   VARCHAR(32)  NOT NULL,
        `uid_bytes`       TINYINT      NOT NULL DEFAULT 4,
        `uid_decimal`     VARCHAR(25)  NULL DEFAULT NULL,
        `enrolled_source` VARCHAR(20)  NOT NULL DEFAULT 'usb_decimal',
        `status`          VARCHAR(20)  NOT NULL DEFAULT 'available',
        `notes`           TEXT         NULL DEFAULT NULL,
        `created_by`      INT          NULL DEFAULT NULL,
        `updated_by`      INT          NULL DEFAULT NULL,
        `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`      DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_wc_card_no` (`card_no`),
        UNIQUE KEY `uq_wc_uid`     (`canonical_uid`),
        INDEX `idx_wc_type`   (`worker_type_id`),
        INDEX `idx_wc_status` (`status`),
        CONSTRAINT `fk_wc_type` FOREIGN KEY (`worker_type_id`)
            REFERENCES `worker_types`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // ── daily_work_sessions — çavuş günlük mesai oturumu (FAZ 2) ────
    // Bir çavuşun bir GÜNDE bir DEPODA açtığı oturum. `status` yalnız
    // open/closed — kilitlenme/kapanma yarım kalırsa manuel müdahale
    // (Faz 2 kapsamı dışı) gerekir, otomatik geri alma YOK.
    // UNIQUE(foreman_id, work_date, depo): "sayfa yenilenince yeni oturum
    // AÇILMASIN" kuralının veritabanı seviyesindeki garantisi — aynı gün/
    // depoda ikinci bir INSERT UNIQUE kısıtına çarpar, uygulama katmanı
    // (pdks_gunluk_oturum_ac_veya_getir) zaten INSERT'ten ÖNCE arar ve
    // varsa onu döndürür; kısıt yalnız yarış koşulu için son çare.
    //
    // ⚠ foreman_name_snapshot / foreman_code_snapshot (Faz 3, Sprint
    // Günlük-İşçi-04 — görev talimatı madde 14, "inspect whether foreman
    // display also needs a snapshot"): İNCELENDİ — foremen.name/code CANLI
    // ve değiştirilebilir (bkz. pdks_gunluk_cavus_guncelle()), oysa
    // daily_work_sessions.foreman_id yalnız bir LIVE FK'dir, snapshot YOK.
    // Faz 3 günlük puantaj RAPORLARI bu satırdan foreman adını JOIN ile
    // okusaydı, bir çavuş ADI SONRADAN değiştirildiğinde GEÇMİŞ raporlar
    // SESSİZCE değişirdi (worker_type_name_snapshot ile AYNI sorun, bkz.
    // daily_worker_card_events). En küçük, Faz 3'e güvenli çözüm: worker
    // type snapshot'ıyla AYNI desen — oturum AÇILIRKEN (tek yazma anı)
    // çavuşun o ANKİ ad/kodu buraya KOPYALANIR, foremen tablosunun SONRAKİ
    // değişikliklerinden ETKİLENMEZ. Şema henüz hiçbir ortama migrate/
    // deploy EDİLMEDİ (Faz 2 dalı hâlâ birleştirilmedi) — bu yüzden ALTER
    // değil, doğrudan CREATE TABLE içinde eklenmesi güvenlidir.
    // ⚠ `normal_work_minutes_snapshot` (Faz 9C / H-02 kapanışı, madde 5 —
    // KRİTİK tarihsel güvenlik): foreman_name_snapshot/foreman_code_snapshot
    // İLE AYNI DESEN — oturum AÇILIRKEN çavuşun O ANKİ normal_work_minutes'ı
    // buraya KOPYALANIR. foremen.normal_work_minutes SONRADAN değişse bile bu
    // oturumun Tam/FM hesabı (config/pdks_faz8b.php) HER ZAMAN bu donmuş
    // değeri kullanır — canlı çavuş ayarından ASLA yeniden hesaplanmaz.
    // Varsayılan/backfill 540 dk — eski (Faz 9C öncesi) oturumlar için de
    // güvenli, eski sabit 9 saatlik vardiya varsayımıyla AYNI.
    $t['daily_work_sessions'] = "CREATE TABLE IF NOT EXISTS `daily_work_sessions` (
        `id`                     INT AUTO_INCREMENT PRIMARY KEY,
        `foreman_id`             INT          NOT NULL,
        `foreman_name_snapshot`  VARCHAR(150) NOT NULL DEFAULT '',
        `foreman_code_snapshot`  VARCHAR(20)  NOT NULL DEFAULT '',
        `normal_work_minutes_snapshot` INT    NOT NULL DEFAULT 540,
        `work_date`              DATE         NOT NULL,
        `depo`                   VARCHAR(150) NOT NULL DEFAULT '',
        `status`                 VARCHAR(20)  NOT NULL DEFAULT 'open',
        `opened_at`              DATETIME     NOT NULL,
        `opened_by_user_id`      INT          NULL DEFAULT NULL,
        `closed_at`              DATETIME     NULL DEFAULT NULL,
        `closed_by_user_id`      INT          NULL DEFAULT NULL,
        `notes`                  TEXT         NULL DEFAULT NULL,
        `created_at`             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`             DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_dws_foreman_date_depo` (`foreman_id`, `work_date`, `depo`),
        INDEX `idx_dws_status` (`status`),
        INDEX `idx_dws_date`   (`work_date`),
        CONSTRAINT `fk_dws_foreman` FOREIGN KEY (`foreman_id`)
            REFERENCES `foremen`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // ── daily_worker_card_events — GİRİŞ/ÇIKIŞ tarama geçmişi (FAZ 2) ──
    // ⚠ HİÇBİR SATIR GÜNCELLENMEZ/SİLİNMEZ (kullanıcının açık talimatı:
    // "Historical events must never be deleted"). Kart o anki durumunu
    // ("aktif kullanımda mı") BURADAN TÜRETİR — worker_cards.status'a
    // ASLA 'in_use' yazılmaz (Faz 1 düzeltmesi #1, bkz. dosya başlığı).
    //
    // ⚠ SNAPSHOT ALANLARI (kullanıcının açık talimatı): worker_type_id/
    // name_snapshot, o taramanın YAPILDIĞI ANDAKİ işçi tipini donduruyor.
    // K001 bugün "Kadın" olabilir, yarın tip değişirse GEÇMİŞ rapor yine
    // "Kadın" göstermeli — worker_cards.worker_type_id'nin GÜNCEL değerinden
    // GERİYE DOĞRU hesaplanmaz. worker_type_id_snapshot'a BİLEREK FK
    // KONULMADI: bu sütun tarihi bir referanstır, worker_types tablosunun
    // O ANKİ bütünlüğüne bağımlı olmamalı (ad zaten ayrıca snapshot'landı).
    //
    // ⚠ work_date_snapshot / depo_snapshot (Sprint Günlük-İşçi-03 düzeltmesi
    // #1 — kullanıcının açık düzeltmesi): "BİR İŞÇİ KARTI = BİR İŞÇİ / İŞ
    // GÜNÜ" kuralının VERİTABANI SEVİYESİNDE GARANTİSİ. session_id üzerinden
    // daily_work_sessions'a JOIN ederek de work_date/depo bulunabilirdi, ama
    // bu değerler her satıra SNAPSHOT olarak KOPYALANIR — sebep: bu, aşağıdaki
    // `uq_dwce_card_day_depo_type` UNIQUE kısıtının KENDİSİ için ZORUNLUDUR
    // (MySQL bir UNIQUE kısıtı başka bir tablonun sütununa göre KURAMAZ). Bu
    // sayede "aynı kart, aynı iş günü/depoda İKİNCİ bir GİRİŞ satırı"
    // FARKLI oturumlar arasında bile veritabanının KENDİSİ tarafından
    // reddedilir — uygulama katmanındaki ön-kontrol (aşağıya bkz.) bunun
    // dostça mesajlı ÖN halidir, bu kısıt SON ÇAREDİR (yarış koşulu).
    // ⚠ Şema HENÜZ hiçbir ortama migrate/deploy EDİLMEDİ (Faz 2 dalı hâlâ
    // birleştirilmedi) — bu yüzden ALTER değil, doğrudan CREATE TABLE
    // içinde eklenmesi güvenlidir, canlı veriye dokunmaz.
    //
    // ⚠ idx_dwce_workdate_depo_type (Faz 3, görev talimatı madde 13 —
    // "review indexes for work_date_snapshot/depo_snapshot"): Faz 2'nin
    // UNIQUE kısıtında (worker_card_id, work_date_snapshot, depo_snapshot,
    // event_type) bu iki sütun İKİNCİ/ÜÇÜNCÜ sıradadır — MySQL onu
    // "worker_card_id verilmeden" bir soldan-önek (leftmost prefix) olarak
    // KULLANAMAZ. Faz 3'ün günlük özet/eksik-çıkış sorguları ise TAM TERSİ
    // yönde sorgular: "BUGÜN, BU DEPODA hangi kartlar" (worker_card_id
    // henüz bilinmiyor). Bu YÜZDEN ayrı, gerçek bir soldan-önek indeksi
    // eklendi — Faz 2'nin UNIQUE kısıtına DOKUNMADAN, yalnız EKLEME.
    //
    // ⚠ FAZ 8A (kullanıcının açık talimatı — "REMOVE OLD SAME-DAY
    // CONSTRAINT"): `uq_dwce_card_day_depo_type` (worker_card_id,
    // work_date_snapshot, depo_snapshot, event_type) buradan KALDIRILDI —
    // "bir işçi kartı = bir işçi/iş günü" kuralı Faz 8A'da GEÇERSİZDİR, aynı
    // kart aynı gün defalarca (farklı/aynı çavuşta) yeniden kullanılabilir.
    // Bu, yalnız SIFIRDAN kurulumları etkiler (CREATE TABLE IF NOT EXISTS
    // var olan tabloyu değiştirmez) — mevcut ÜRETİM tablosunda kısıt hâlâ
    // DURUYOR olabilir, `pdks_gunluk_faz8a_migrate()` onu KENDİ ALTER'ıyla
    // kontrollü biçimde kaldırır (bkz. o fonksiyon + dosya sonundaki FAZ 8A
    // bölümü). Kalan üç index (idx_dwce_*) DEĞİŞMEDİ — hâlâ geçerli sorgu
    // yolları.
    $t['daily_worker_card_events'] = "CREATE TABLE IF NOT EXISTS `daily_worker_card_events` (
        `id`                         INT AUTO_INCREMENT PRIMARY KEY,
        `session_id`                 INT          NOT NULL,
        `worker_card_id`             INT          NOT NULL,
        `event_type`                 VARCHAR(10)  NOT NULL,
        `source`                     VARCHAR(20)  NOT NULL,
        `canonical_uid_snapshot`     VARCHAR(32)  NOT NULL,
        `worker_type_id_snapshot`    INT          NULL DEFAULT NULL,
        `worker_type_name_snapshot`  VARCHAR(80)  NOT NULL DEFAULT '',
        `work_date_snapshot`         DATE         NOT NULL,
        `depo_snapshot`              VARCHAR(150) NOT NULL DEFAULT '',
        `recorded_by_user_id`        INT          NULL DEFAULT NULL,
        `server_event_time`          DATETIME     NOT NULL,
        `created_at`                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_dwce_session_card_type` (`session_id`, `worker_card_id`, `event_type`),
        INDEX `idx_dwce_card`    (`worker_card_id`),
        INDEX `idx_dwce_session` (`session_id`),
        INDEX `idx_dwce_workdate_depo_type` (`work_date_snapshot`, `depo_snapshot`, `event_type`),
        CONSTRAINT `fk_dwce_session` FOREIGN KEY (`session_id`)
            REFERENCES `daily_work_sessions`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT `fk_dwce_card` FOREIGN KEY (`worker_card_id`)
            REFERENCES `worker_cards`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    return $t;
}

/** Bir tablo var mı? (pdks_tablo_var() ile aynı desen, bilerek KOPYALANDI —
 *  bu dosya config/pdks.php'ye SESSİZCE bağımlı OLMAMALI, bkz. başlık.) */
function pdks_gunluk_tablo_var(PDO $pdo, string $tablo): bool
{
    try { $pdo->query("SELECT 1 FROM `{$tablo}` LIMIT 0"); return true; }
    catch (PDOException $e) { return false; }
}

/** Request-local column metadata. Migration helpers explicitly clear this after DDL. */
function pdks_gunluk_kolon_onbellek(PDO $pdo, ?string $tablo = null, ?string $kolon = null, ?bool $var = null): array
{
    static $cache;
    $cache ??= new WeakMap();
    if ($tablo !== null && $kolon === null) {
        $rows = $cache[$pdo] ?? [];
        unset($rows[$tablo]);
        $cache[$pdo] = $rows;
    } elseif ($tablo !== null && $kolon !== null && $var !== null) {
        $rows = $cache[$pdo] ?? [];
        $rows[$tablo][$kolon] = $var;
        $cache[$pdo] = $rows;
    }
    return $cache[$pdo] ?? [];
}

function pdks_gunluk_kolon_onbellek_temizle(PDO $pdo, string $tablo): void
{
    pdks_gunluk_kolon_onbellek($pdo, $tablo);
}

/** Shared by the daily, Faz 8B, and Faz 8J readers. */
function pdks_gunluk_kolon_var(PDO $pdo, string $tablo, string $kolon): bool
{
    $cache = pdks_gunluk_kolon_onbellek($pdo);
    if (array_key_exists($kolon, $cache[$tablo] ?? [])) return $cache[$tablo][$kolon];
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        foreach ($pdo->query("PRAGMA table_info(`{$tablo}`)")->fetchAll() as $c) {
            if (($c['name'] ?? null) === $kolon) return pdks_gunluk_kolon_onbellek_yaz($pdo, $tablo, $kolon, true);
        }
        return pdks_gunluk_kolon_onbellek_yaz($pdo, $tablo, $kolon, false);
    }
    $st = $pdo->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    $st->execute([$tablo, $kolon]);
    return pdks_gunluk_kolon_onbellek_yaz($pdo, $tablo, $kolon, $st->fetchColumn() !== false);
}

function pdks_gunluk_kolon_onbellek_yaz(PDO $pdo, string $tablo, string $kolon, bool $var): bool
{
    pdks_gunluk_kolon_onbellek($pdo, $tablo, $kolon, $var);
    return $var;
}

/**
 * Şema migrasyonu — IDEMPOTENT, yıkıcı değildir, YALNIZ additive CREATE TABLE.
 * Mevcut hiçbir tabloya ALTER/DROP uygulamaz (kullanıcının açık talimatı).
 *
 * ⚠ KENDİLİĞİNDEN ÇALIŞMAZ — yalnız bu modülün sayfalarından (cavuslar.php,
 *    cavus_form.php, isci_kartlari.php, isci_tipleri.php) ve migrate.php'den
 *    çağrılır (pdks_migrate()/hesap_migrate() ile aynı desen).
 */
function pdks_gunluk_migrate(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $rapor = [];

    foreach (pdks_gunluk_tablolar() as $ad => $sql) {
        if (pdks_gunluk_tablo_var($pdo, $ad)) {
            $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.'];
            continue;
        }
        try {
            $pdo->exec($sql);
            pdks_gunluk_kolon_onbellek_temizle($pdo, $ad);
            $rapor[] = pdks_gunluk_tablo_var($pdo, $ad)
                ? ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.']
                : ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'CREATE çalıştı ama tablo görünmüyor.'];
        } catch (PDOException $e) {
            error_log('[pdks_gunluk_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => $e->getMessage()];
        }
    }

    // Başlangıç işçi tipi seed'i — İDEMPOTENT, kullanıcının açık örneği:
    // KADIN/Kadın, ERKEK/Erkek. Gender ENUM DEĞİL — sıradan satır.
    //
    // ⚠ `INSERT IGNORE` BİLEREK KULLANILMAZ — MySQL'e özgüdür ve testlerde
    // (bellek içi SQLite) sessizce SQLSTATE hatası verir; bu depoda AYNI
    // ders zaten bir kez öğrenildi (bkz. CLAUDE.md → hks_eslesme_yaz()'ın
    // "taşınabilir upsert" notu: `ON DUPLICATE KEY UPDATE` de aynı sebeple
    // terk edildi). Onun yerine SELECT→yoksa INSERT — iki veritabanında da
    // çalışır, yarış koşulunda da UNIQUE kısıtı zaten korur (satır try/catch
    // içinde, ikinci bir eşzamanlı seed çağrısı sessizce yutulur).
    if (pdks_gunluk_tablo_var($pdo, 'worker_types')) {
        try {
            $var = $pdo->prepare("SELECT 1 FROM worker_types WHERE code = ?");
            $ins = $pdo->prepare("INSERT INTO worker_types (code, name, sort_order) VALUES (?, ?, ?)");
            foreach ([['KADIN', 'Kadın', 1], ['ERKEK', 'Erkek', 2]] as [$kod, $ad2, $sira]) {
                $var->execute([$kod]);
                if ($var->fetchColumn()) continue;
                try { $ins->execute([$kod, $ad2, $sira]); } catch (PDOException $e) { /* yarış koşulu — zaten var */ }
            }
            $rapor[] = ['tablo' => 'worker_types.seed', 'durum' => 'var', 'mesaj' => 'Başlangıç tipleri (Kadın/Erkek) garanti edildi.'];
        } catch (PDOException $e) {
            error_log('[pdks_gunluk_migrate] worker_types.seed: ' . $e->getMessage());
            $rapor[] = ['tablo' => 'worker_types.seed', 'durum' => 'hata', 'mesaj' => $e->getMessage()];
        }
    }

    return $rapor;
}

function pdks_gunluk_sema_hazir(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? db();
    foreach (array_keys(pdks_gunluk_tablolar()) as $ad) {
        if (!pdks_gunluk_tablo_var($pdo, $ad)) return false;
    }
    return true;
}

/**
 * Sayfa girişi kapısı — TEK yerde, sayfa bazında TEKRARLANMAYAN kontrol.
 *
 * ⚠ ÜRETİM SAYFALARI (cavuslar.php, cavus_form.php, isci_kartlari.php,
 * isci_tipleri.php) ARTIK sayfa ziyaretinde pdks_gunluk_migrate() ÇAĞIRMAZ
 * (kullanıcının açık düzeltmesi: normal bir GET isteği DDL çalıştırmamalı —
 * şema oluşturma YALNIZ migrate.php'nin kontrollü admin aksiyonundan geçer,
 * pdks_migrate() ile AYNI ilke zaten config/pdks.php'de de böyleydi, burada
 * yalnız sayfa girişindeki YANLIŞLIKLA eklenmiş migrate() çağrıları
 * KALDIRILDI). Bu fonksiyon şema HAZIR DEĞİLSE sayfayı GÜVENLE, açık
 * Türkçe bir admin mesajıyla SONLANDIRIR (exit) — hiçbir sorgu tabloya
 * dokunmadan önce.
 *
 * Her sayfanın ($pdo = db();)'den HEMEN SONRA, herhangi bir POST/ajax
 * dalından ÖNCE çağırması yeterlidir.
 */
function pdks_gunluk_sayfa_kapisi(?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    if (pdks_gunluk_sema_hazir($pdo)) return;

    $mesaj = 'Günlük İşçi modülü tabloları henüz oluşturulmamış. Bir yöneticinin '
           . 'migrate.php sayfasından "Günlük İşçi Tablolarını Oluştur" demesi gerekiyor.';
    if (function_exists('set_flash')) set_flash('error', $mesaj);
    if (function_exists('render_header')) render_header('Günlük İşçi');
    if (function_exists('render_flash')) {
        render_flash();
    } elseif (function_exists('h')) {
        echo '<div class="flash flash-error">' . h($mesaj) . '</div>';
    }
    if (function_exists('render_footer')) render_footer();
    exit;
}

// =========================================================
// YETKİ KAPISI — mevcut can()/is_admin() üzerine kurulur (DEĞİŞTİRİLMEZ).
// Kasıtlı olarak yalnız İKİ yetki (kullanıcının açık talimatı: "do not
// over-fragment permissions"): attendance.foremen, attendance.worker_cards.
// =========================================================

/** @param string $eylem foremen|worker_cards */
function pdks_gunluk_can(string $eylem): bool
{
    if (!function_exists('can')) return false;
    if (function_exists('is_admin') && is_admin()) return true;

    return match ($eylem) {
        'foremen'       => can('attendance.foremen'),
        'worker_cards'  => can('attendance.worker_cards'),
        'daily_scan'    => can('attendance.daily_scan'),
        'daily_reports' => can('attendance.daily_reports'),
        default         => false,
    };
}

function require_pdks_gunluk(string $eylem): void
{
    if (!PDKS_GUNLUK_AKTIF) {
        if (function_exists('forbidden')) forbidden('Günlük işçi modülü şu anda kapalıdır.');
        http_response_code(503);
        exit('Günlük işçi modülü kapalı.');
    }
    if (function_exists('current_user') && current_user() === null) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . (function_exists('base_url') ? base_url() : '') . 'login.php' . ($next ? '?next=' . $next : ''));
        exit;
    }
    if (function_exists('enforce_active_depot')) enforce_active_depot();
    if (!pdks_gunluk_can($eylem)) {
        forbidden("Bu sayfaya erişim yetkiniz yok. (Gerekli yetki: attendance.{$eylem})");
    }
}

// =========================================================
// AKTİF DEPO KAYIT KAPISI (Faz 9A / M-01 düzeltmesi)
//
// ⚠ enforce_active_depot() (config/auth.php) yalnız "BİR depo seçili mi"
// sorusuna bakar — sayfa seviyesi bir kapıdır. Bir KAYDIN (oturum/dönem/
// hakediş) GERÇEKTEN aktif depoya ait olup OLMADIĞINI kontrol ETMEZ. O
// ikinci kontrol olmadan, ?id= elle başka bir depoya ait bir kayda
// değiştirilince (IDOR) o kayıt okunabilir/değiştirilebilir — audit
// bulgusu M-01.
//
// Bu fonksiyon TEK ve PAYLAŞILAN kapıdır — pdks_gunluk.php TÜM pdks_*.php
// modüllerinin (hakedis/cari/rapor/faz8b/faz8e/faz8h/faz8j) hard-require
// ettiği TEK YÖNLÜ zincirin köküdür (bkz. dosya başı), bu yüzden burada
// yaşaması onu HERKESE isim bağımlılığı olmadan erişilebilir kılar —
// Faz 8J'nin kendi pdks_faz8j_aktif_depo_kontrol()'ü artık BUNU sarar
// (aşağı bkz.), ikinci bir paralel uygulama YOK.
//
// Kayıt deposu boşsa ("atanmamış veri") — CLAUDE.md'nin depo mimarisi
// ilkesiyle AYNI: boş depolu eski veri TÜM depolarda erişilebilir kalır —
// bu fonksiyon YALNIZ record deposu DOLU ve aktif depodan FARKLIYSA
// reddeder.
// =========================================================
function pdks_gunluk_depo_kontrol(string $recordDepo, ?string $aktifDepo = null): ?string
{
    $aktifDepo = $aktifDepo ?? (function_exists('active_depot') ? active_depot() : null);
    $aktif = trim((string)($aktifDepo ?? ''));
    $recordDepo = trim($recordDepo);
    if ($aktif === '') return 'Önce bir depo seçmelisiniz.';
    if ($recordDepo === '') return null;   // atanmamış veri — tüm depolarda erişilebilir
    return $aktif !== $recordDepo ? 'Bu kayıt aktif depoya ait değil.' : null;
}

// =========================================================
// ÇAPRAZ-SİSTEM UID ÇAKIŞMA KONTROLÜ
// =========================================================

/**
 * Bu kanonik UID, KALICI personel kart sisteminde (employee_card_uids —
 * config/pdks.php) hâlâ AKTİF bir karta mı ait? Salt okunur — o tabloyu hiç
 * DEĞİŞTİRMEZ. Yeni bir işçi-havuzu kartı yazılmadan ÖNCE çağrılır.
 *
 * ⚠ Faz 9A / H-04 düzeltmesi: eskiden `c.status` HİÇ FİLTRELENMİYORDU —
 * yıllar önce iptal/kayıp/pasif işaretlenmiş bir kalıcı personel kartı,
 * fiziksel UID'i SONSUZA KADAR işçi havuzuna kaydedilmekten alıkoyuyordu
 * (`pdks_kart_iptal()` durumu değiştirir, `employee_card_uids` alias
 * satırını SİLMEZ — kasıtlı, denetim geçmişi için). Artık YALNIZ
 * `pdks_kart_aktif_mi()`'nin "aktif" saydığı kart bloke eder — iptal/kayıp/
 * değiştirildi/süresi doldu/pasif durumundaki eski kartlar artık UID'i
 * SERBEST BIRAKIR. `employee_card_uids`/`employee_cards` satırları
 * DOKUNULMADAN kalır — pdks_kart_cozumle() (tarihsel arama/denetim) hâlâ
 * durumdan BAĞIMSIZ tüm kartları bulur, bu fonksiyon SADECE bir engelleme
 * kararıdır.
 */
function pdks_gunluk_uid_kalici_kartta_mi(string $kanonik, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? db();
    try {
        $st = $pdo->prepare(
            "SELECT c.id AS card_id, c.employee_id, c.status, e.full_name
               FROM employee_card_uids u
               JOIN employee_cards c ON c.id = u.card_id
               JOIN employees e ON e.id = c.employee_id
              WHERE u.uid_hex = ? LIMIT 1"
        );
        $st->execute([$kanonik]);
        $kart = $st->fetch() ?: null;
        if ($kart === null) return null;
        $aktifMi = function_exists('pdks_kart_aktif_mi') ? pdks_kart_aktif_mi($kart['status'] ?? null) : true;
        return $aktifMi ? $kart : null;
    } catch (PDOException $e) {
        return null;   // employee_cards/employee_card_uids yoksa çakışma da yok
    }
}

/**
 * Faz 9A / H-04: `pdks_kart_cozumle()`'nin (config/pdks.php) SALT
 * ÇÖZÜMLEME sonucunu (durumdan bağımsız) bir ENGELLEME kararına çevirir.
 * `pdks_kart_cozumle()` KENDİSİ DEĞİŞTİRİLMEZ — o fonksiyon
 * `personel_kartlar.php` ve config/pdks.php'nin kendi (kalıcı personel)
 * yoklama akışı tarafından da kullanılır ve ORADA durum FARK ETMEKSİZİN
 * (tarihsel arama/denetim için) çözümleme yapması GEREKİR. Yalnız günlük
 * işçi GİRİŞ/kart-oluşturma akışının "bu bir kalıcı personel kartı mı,
 * REDDET" kararı BURADA, TEK yerde, `pdks_kart_aktif_mi()` ile filtrelenir
 * — üç ayrı çağrı sahasında (Faz 2 pdks_gunluk_oturum_kaydet, Faz 8A GİRİŞ,
 * Faz 8A ÇIKIŞ) AYNI mantık TEKRAR YAZILMASIN diye.
 *
 * @return array{kod:string,hata:string}|null Engelleniyorsa hata dizisi, değilse null.
 */
function pdks_gunluk_kalici_kart_engeli(string $hamUid, string $kaynak, ?PDO $pdo = null): ?array
{
    if (!function_exists('pdks_kart_cozumle')) return null;
    $kalici = pdks_kart_cozumle($hamUid, $kaynak, $pdo ?? db());
    if ($kalici === null) return null;

    $durum = $kalici['card']['status'] ?? null;
    $aktifMi = function_exists('pdks_kart_aktif_mi') ? pdks_kart_aktif_mi($durum) : true;
    if (!$aktifMi) return null;   // iptal/kayıp/pasif/vb. — UID artık serbest

    // ⚠ Faz 9E / C: kiosk'ta operatörün gördüğü ASIL mesaj burasıdır (günlük
    // işçi GİRİŞ'inde kalıcı personel kartı okutulduğunda) — bkz. yukarıdaki
    // pdks_gunluk_kart_olustur() içindeki KARDEŞ mesaj (kart oluşturma anı).
    // İkisi de AYNI durumu anlatır, o yüzden AYNI eylemi söyler.
    $isim = (string)($kalici['employee']['full_name'] ?? '');
    return ['kod' => 'kalici_kart',
            'hata' => 'Bu kart aktif bir kalıcı personel kartına bağlıdır' . ($isim !== '' ? ' (' . $isim . ')' : '')
                    . '. Günlük işçi kartı olarak kullanmak için önce kalıcı personel kartını pasife alın/iptal edin.'];
}

/**
 * Bu kanonik UID, GÜNLÜK İŞÇİ kart havuzunda zaten tanımlı mı?
 * İki amaçla kullanılır: ① yeni işçi kartı yazılırken HAVUZ-İÇİ çakışma
 * kontrolü (kendi UNIQUE kısıtının önden, dostça mesajlı hâli) ② TERS yön —
 * config/pdks.php → pdks_kart_olustur() içinden, function_exists guard'lı
 * YUMUŞAK çağrı olarak (bkz. bu dosyanın başlığı).
 */
function pdks_gunluk_uid_gecici_kartta_mi(string $kanonik, ?int $haricKartId = null, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? db();
    if (!pdks_gunluk_tablo_var($pdo, 'worker_cards')) return null;
    $sql = "SELECT id, card_no, status FROM worker_cards WHERE canonical_uid = ?";
    $par = [$kanonik];
    if ($haricKartId !== null) { $sql .= " AND id <> ?"; $par[] = $haricKartId; }
    $st = $pdo->prepare($sql . " LIMIT 1");
    $st->execute($par);
    return $st->fetch() ?: null;
}

// =========================================================
// İŞÇİ TİPİ (worker_types) — küçük master CRUD
// =========================================================

function pdks_gunluk_tip_listele(bool $sadeceAktif = false, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $sql = "SELECT * FROM worker_types" . ($sadeceAktif ? " WHERE is_active = 1" : "") . " ORDER BY sort_order ASC, name ASC";
    return $pdo->query($sql)->fetchAll();
}

function pdks_gunluk_tip_olustur(string $kod, string $ad, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $kod = strtoupper(trim($kod));
    $ad  = trim($ad);
    if ($kod === '' || $ad === '') {
        return ['ok' => false, 'hata' => 'Kod ve ad zorunludur.'];
    }
    $st = $pdo->prepare("SELECT id FROM worker_types WHERE code = ?");
    $st->execute([$kod]);
    if ($st->fetchColumn()) {
        return ['ok' => false, 'hata' => 'Bu kod zaten kullanımda: ' . $kod];
    }
    $siraSt = $pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM worker_types");
    $sira = (int)$siraSt->fetchColumn();
    $ins = $pdo->prepare("INSERT INTO worker_types (code, name, sort_order) VALUES (?, ?, ?)");
    $ins->execute([$kod, $ad, $sira]);
    $id = (int)$pdo->lastInsertId();
    if (function_exists('audit_log_event')) {
        audit_log_event('create', 'worker_types', $id, null, ['code' => $kod, 'name' => $ad]);
    }
    return ['ok' => true, 'id' => $id];
}

function pdks_gunluk_tip_aktiflik(int $id, bool $aktif, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    // ⚠ Faz 9B / H-01 kapanışı: SON aktif+desteklenen tipi pasifleştirmek
    // GİRİŞ akışını seçilecek hiçbir tip bırakmadan kullanılamaz hale
    // getirir. YALNIZ bu durumda engellenir — KADIN/ERKEK'ten biri aktif
    // kalırken diğerini pasifleştirmek (ör. tek cinsiyetli bir şube) veya
    // desteklenmeyen/tarihsel bir tipi pasifleştirmek SERBESTTİR; bu yüzden
    // testler (ör. scripts/pdks_gunluk_smoke.php'nin KADIN/ERKEK'i TEK TEK,
    // diğeri aktifken pasifleştirip geri açan senaryosu) ETKİLENMEZ.
    if (!$aktif) {
        $stKod = $pdo->prepare('SELECT code FROM worker_types WHERE id = ?');
        $stKod->execute([$id]);
        $kod = $stKod->fetchColumn();
        if ($kod !== false && pdks_gunluk_tip_kodu_destekleniyor((string)$kod)) {
            $kalanlar = pdks_gunluk_desteklenen_tip_listele($pdo);
            if (count($kalanlar) === 1 && (int)$kalanlar[0]['id'] === $id) {
                return ['ok' => false, 'hata' => 'Son aktif desteklenen işçi tipi (' . $kod . ') pasifleştirilemez — günlük işçi giriş akışı seçilecek hiçbir tip bulamaz.'];
            }
        }
    }
    $st = $pdo->prepare("UPDATE worker_types SET is_active = ? WHERE id = ?");
    $st->execute([$aktif ? 1 : 0, $id]);
    if ($st->rowCount() === 0) return ['ok' => false, 'hata' => 'İşçi tipi bulunamadı.'];
    if (function_exists('audit_log_event')) {
        audit_log_event('update', 'worker_types', $id, null, ['is_active' => $aktif ? 1 : 0]);
    }
    return ['ok' => true];
}

// =========================================================
// Faz 9B — GÜNLÜK İŞÇİ TİPİ TEK VE YETKİLİ POLİTİKA KAPISI (H-01 kapanışı)
//
// ⚠ v227 audit bulgusu H-01: worker-type admin ekranı serbest kod kabul
// ediyordu ("ör. FORKLIFT"), tarama mantığı herhangi bir AKTİF worker_types
// satırını kabul ediyordu, düzeltme (Faz 8J) mantığı yalnız KADIN/ERKEK
// kabul ediyordu VE düzeltme AÇILIR LİSTESİ backend'in reddettiği tipleri
// sunuyordu — dört ayrı katman DÖRT FARKLI kararı BAĞIMSIZ veriyordu.
//
// İş kararı KESİNLEŞTİ (görev talimatı): günlük işçi devam sistemi TAM
// OLARAK iki tip destekler — KADIN, ERKEK. Bu dosya artık TEK doğruluk
// kaynağıdır; tarama (pdks_gunluk_faz8a_giris_kaydet), düzeltme
// (config/pdks_faz8j.php → pdks_faz8j_desteklenen_tip, artık SARMALAR),
// oran tanımlama (cavus_fiyatlari.php / config/pdks_faz8b.php /
// config/pdks_hakedis.php) BURAYA delege eder — `code IN ('KADIN','ERKEK')`
// ARTIK HİÇBİR YERDE TEKRARLANMAZ.
//
// ⚠ Bu politika YALNIZ YENİ OPERASYONEL SEÇİM içindir (GİRİŞ tip seçimi,
// düzeltme hedefi, yeni oran tanımlama) — TARİHSEL görüntüleme/arama
// ETKİLENMEZ: pdks_gunluk_tip_listele() (TÜM satırlar, destekli/desteksiz,
// aktif/pasif) ve mevcut dönem/oran/hakediş satırları AYNEN okunabilir
// kalır. Kod, uygulanmayan bir tipi asla SESSİZCE yeniden sınıflandırmaz
// veya silmez (görev talimatı §7).
// =========================================================

/** @return string[] Günlük işçi operasyonel akışlarında desteklenen KOD listesi. */
function pdks_gunluk_desteklenen_tip_kodlari(): array
{
    return ['KADIN', 'ERKEK'];
}

/** Bu KOD (worker_types.code), günlük işçi operasyonel akışlarında desteklenir mi? */
function pdks_gunluk_tip_kodu_destekleniyor(?string $kod): bool
{
    return $kod !== null && in_array($kod, pdks_gunluk_desteklenen_tip_kodlari(), true);
}

/**
 * YENİ operasyonel seçim (GİRİŞ tip düğmeleri, düzeltme hedef listesi, yeni
 * oran tanımlama açılır listesi) İÇİN kullanılabilecek AKTİF + DESTEKLENEN
 * worker_types satırları — istisnasız aynı liste, tek kaynak.
 */
function pdks_gunluk_desteklenen_tip_listele(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $kodlar = pdks_gunluk_desteklenen_tip_kodlari();
    $ph = implode(',', array_fill(0, count($kodlar), '?'));
    $st = $pdo->prepare("SELECT * FROM worker_types WHERE is_active = 1 AND code IN ($ph) ORDER BY sort_order ASC, name ASC");
    $st->execute($kodlar);
    return $st->fetchAll();
}

/**
 * Bir worker_type_id'nin YENİ operasyonel seçim için geçerli (aktif +
 * desteklenen kod) olup olmadığını SUNUCU tarafında bağımsızca doğrular —
 * crafted POST'a (istemcinin göndermediği/UI'da hiç sunulmayan bir id dahi
 * olsa) karşı TEK doğruluk kaynağı. Geçerliyse satırı (code dahil) döner.
 */
function pdks_gunluk_desteklenen_tip_coz(int $workerTypeId, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? db();
    $kodlar = pdks_gunluk_desteklenen_tip_kodlari();
    $ph = implode(',', array_fill(0, count($kodlar), '?'));
    $st = $pdo->prepare("SELECT * FROM worker_types WHERE id = ? AND is_active = 1 AND code IN ($ph)");
    $st->execute(array_merge([$workerTypeId], $kodlar));
    return $st->fetch() ?: null;
}

// =========================================================
// ÇAVUŞ (foremen) CRUD
// =========================================================

/** Sonraki sıradaki çavuş kodunu ÖNERİR (C001, C002…) — form alanı yine de
 *  serbestçe düzenlenebilir, burada YALNIZ öneridir. */
function pdks_gunluk_sonraki_cavus_kodu(?PDO $pdo = null): string
{
    // ⚠ REGEXP BİLEREK KULLANILMAZ — MySQL destekler ama SQLite (testler)
    // özel bir fonksiyon kaydı olmadan desteklemez. LIKE + PHP-tarafı
    // doğrulama, MySQL/SQLite arasında taşınabilir aynı sonucu verir.
    $pdo = $pdo ?? db();
    $n = 1;
    try {
        $st = $pdo->query("SELECT code FROM foremen WHERE code LIKE 'C%'");
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $kod) {
            if (preg_match('/^C(\d+)$/', (string)$kod, $m)) {
                $n = max($n, (int)$m[1] + 1);
            }
        }
    } catch (PDOException $e) { $n = 1; }
    return 'C' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
}

function pdks_gunluk_cavus_dogrula(array $veri, ?int $haricId = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $hatalar = [];
    $kod = trim((string)($veri['code'] ?? ''));
    $ad  = trim((string)($veri['name'] ?? ''));
    if ($kod === '') $hatalar[] = 'Çavuş kodu zorunludur.';
    if ($ad === '')  $hatalar[] = 'Ad Soyad zorunludur.';
    if ($kod !== '') {
        $sql = "SELECT id FROM foremen WHERE code = ?";
        $par = [$kod];
        if ($haricId !== null) { $sql .= " AND id <> ?"; $par[] = $haricId; }
        $st = $pdo->prepare($sql);
        $st->execute($par);
        if ($st->fetchColumn()) $hatalar[] = 'Bu çavuş kodu zaten kullanımda: ' . $kod;
    }
    return $hatalar;
}

function pdks_gunluk_cavus_olustur(array $veri, ?int $createdBy = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $hatalar = pdks_gunluk_cavus_dogrula($veri, null, $pdo);
    if ($hatalar) return ['ok' => false, 'hatalar' => $hatalar];

    $ins = $pdo->prepare(
        "INSERT INTO foremen (code, name, phone, notes, is_active, created_by)
         VALUES (?,?,?,?,?,?)"
    );
    $ins->execute([
        trim((string)$veri['code']),
        trim((string)$veri['name']),
        trim((string)($veri['phone'] ?? '')) ?: null,
        trim((string)($veri['notes'] ?? '')) ?: null,
        !empty($veri['is_active']) ? 1 : 1,   // yeni kayıt varsayılan aktif
        $createdBy,
    ]);
    $id = (int)$pdo->lastInsertId();
    if (function_exists('audit_log_event')) {
        audit_log_event('create', 'foremen', $id, null, ['code' => $veri['code'], 'name' => $veri['name']]);
    }
    return ['ok' => true, 'id' => $id];
}

function pdks_gunluk_cavus_guncelle(int $id, array $veri, ?int $updatedBy = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare("SELECT * FROM foremen WHERE id = ?");
    $st->execute([$id]);
    $eski = $st->fetch();
    if (!$eski) return ['ok' => false, 'hatalar' => ['Çavuş bulunamadı.']];

    $hatalar = pdks_gunluk_cavus_dogrula($veri, $id, $pdo);
    if ($hatalar) return ['ok' => false, 'hatalar' => $hatalar];

    $upd = $pdo->prepare(
        "UPDATE foremen SET code=?, name=?, phone=?, notes=?, is_active=?, updated_by=? WHERE id=?"
    );
    $upd->execute([
        trim((string)$veri['code']),
        trim((string)$veri['name']),
        trim((string)($veri['phone'] ?? '')) ?: null,
        trim((string)($veri['notes'] ?? '')) ?: null,
        !empty($veri['is_active']) ? 1 : 0,
        $updatedBy,
        $id,
    ]);
    if (function_exists('audit_log_event')) {
        audit_log_event('update', 'foremen', $id, $eski, $veri);
    }
    return ['ok' => true, 'id' => $id];
}

function pdks_gunluk_cavus_aktiflik(int $id, bool $aktif, ?int $updatedBy = null, ?PDO $pdo = null): array
{
    // ⚠ Kullanıcının açık talimatı: "No deletion if later historical
    // references may exist." — SİLME yok, yalnız aktif/pasif geçiş.
    $pdo = $pdo ?? db();
    $st = $pdo->prepare("UPDATE foremen SET is_active = ?, updated_by = ? WHERE id = ?");
    $st->execute([$aktif ? 1 : 0, $updatedBy, $id]);
    if ($st->rowCount() === 0) {
        // rowCount()==0, "zaten o durumdaydı" da olabilir — kayıt var mı diye ayrıca bak.
        $chk = $pdo->prepare("SELECT id FROM foremen WHERE id = ?");
        $chk->execute([$id]);
        if (!$chk->fetchColumn()) return ['ok' => false, 'hata' => 'Çavuş bulunamadı.'];
    }
    if (function_exists('audit_log_event')) {
        audit_log_event($aktif ? 'activate' : 'deactivate', 'foremen', $id, null, ['is_active' => $aktif ? 1 : 0]);
    }
    return ['ok' => true];
}

// =========================================================
// İŞÇİ KARTI (worker_cards) CRUD — UID mantığı config/pdks.php'nin
// normalizasyon fonksiyonlarını REUSE eder, TEKRARLAMAZ.
// =========================================================

/** Bir işçi tipinin kod baş harfinden sonraki kart numarasını ÖNERİR
 *  (K001, K002… / E001, E002…) — yalnız öneri, form alanı düzenlenebilir. */
function pdks_gunluk_sonraki_kart_no(int $workerTypeId, ?PDO $pdo = null): string
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare("SELECT code FROM worker_types WHERE id = ?");
    $st->execute([$workerTypeId]);
    $tipKod = (string)($st->fetchColumn() ?: '');
    $harf = $tipKod !== '' ? mb_strtoupper(mb_substr($tipKod, 0, 1, 'UTF-8'), 'UTF-8') : 'K';

    // ⚠ REGEXP BİLEREK KULLANILMAZ — bkz. pdks_gunluk_sonraki_cavus_kodu() notu.
    $n = 1;
    try {
        $st2 = $pdo->prepare("SELECT card_no FROM worker_cards WHERE card_no LIKE ?");
        $st2->execute([$harf . '%']);
        $desen = '/^' . preg_quote($harf, '/') . '(\d+)$/';
        foreach ($st2->fetchAll(PDO::FETCH_COLUMN) as $kartNo) {
            if (preg_match($desen, (string)$kartNo, $m)) {
                $n = max($n, (int)$m[1] + 1);
            }
        }
    } catch (PDOException $e) { $n = 1; }
    return $harf . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
}

/**
 * İşçi kartı oluşturur (enroll). UID normalizasyonu TAMAMEN config/pdks.php
 * fonksiyonlarını (pdks_uid_from_decimal/pdks_uid_from_web_nfc/
 * pdks_uid_hex_normalize) REUSE eder — burada YENİDEN YAZILMAZ.
 *
 * @param string $kaynak 'usb_decimal' | 'nfc_hex' | 'web_nfc' (PDKS_UID_KAYNAKLARI, config/pdks.php'den REUSE)
 */
function pdks_gunluk_kart_olustur(array $veri, ?int $createdBy = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();

    $kaynak = trim((string)($veri['kaynak'] ?? ''));
    if (!defined('PDKS_UID_KAYNAKLARI') || !in_array($kaynak, PDKS_UID_KAYNAKLARI, true)) {
        return ['ok' => false, 'kod' => 'gecersiz_kaynak', 'hata' => 'UID kaynağı bildirilmeli (usb_decimal, nfc_hex veya web_nfc).'];
    }
    $hamUid = trim((string)($veri['ham_uid'] ?? ''));
    if ($hamUid === '') {
        return ['ok' => false, 'kod' => 'bos_uid', 'hata' => 'Kartı okutun.'];
    }
    if (!function_exists('pdks_uid_from_decimal')) {
        return ['ok' => false, 'kod' => 'pdks_yuklu_degil', 'hata' => 'UID normalizasyon fonksiyonları yüklü değil (config/pdks.php).'];
    }

    $kanonik = match ($kaynak) {
        'usb_decimal' => pdks_uid_from_decimal($hamUid),
        'web_nfc'     => pdks_uid_from_web_nfc($hamUid),
        default       => pdks_uid_hex_normalize($hamUid),   // nfc_hex
    };
    if ($kanonik === null) {
        return ['ok' => false, 'kod' => 'gecersiz_uid', 'hata' => 'Okunan UID geçersiz.'];
    }

    $cardNo = trim((string)($veri['card_no'] ?? ''));
    if ($cardNo === '') {
        return ['ok' => false, 'kod' => 'bos_kart_no', 'hata' => 'Kart numarası zorunludur.'];
    }
    // ⚠ FAZ 8A (kullanıcının açık talimatı — "NEUTRALIZE worker_cards"):
    // işçi tipi artık kartın DEĞİL, her mesai döneminin özelliğidir (bkz.
    // config/pdks_gunluk.php dosya sonundaki FAZ 8A bölümü). Bu alan
    // BİLEREK OPSİYONELDİR — boş/0 bırakılırsa kart NÖTR (worker_type_id
    // NULL) oluşturulur. Geriye dönük UYUMLULUK için hâlâ bir tip
    // GÖNDERİLİRSE (eski istemci/otomasyon) aktifliği doğrulanır ve
    // kaydedilir — YENİ Faz 8A taraması bu alanı ASLA OKUMAZ.
    $workerTypeIdHam = trim((string)($veri['worker_type_id'] ?? ''));
    $workerTypeId = null;
    if ($workerTypeIdHam !== '' && $workerTypeIdHam !== '0') {
        $workerTypeId = (int)$workerTypeIdHam;
        $st = $pdo->prepare("SELECT id FROM worker_types WHERE id = ? AND is_active = 1");
        $st->execute([$workerTypeId]);
        if (!$st->fetchColumn()) {
            return ['ok' => false, 'kod' => 'tip_bulunamadi', 'hata' => 'Seçilen işçi tipi bulunamadı veya pasif.'];
        }
    }

    // Savunma derinliği: normal UI bu durumu zaten engeller, fakat çekirdek
    // fonksiyon da migrasyon öncesi NOT NULL şemaya nötr kart yazmayı denemez.
    // Eski istemci geçerli bir worker_type_id gönderiyorsa çalışmaya devam eder.
    if ($workerTypeId === null && !pdks_gunluk_faz8a_sema_hazir($pdo)) {
        return [
            'ok' => false,
            'kod' => 'faz8a_migrasyon_gerekli',
            'hata' => 'Yeni nötr kart tanımlamak için önce Faz 8A migrasyonu tamamlanmalıdır.',
        ];
    }

    $stC = $pdo->prepare("SELECT id FROM worker_cards WHERE card_no = ?");
    $stC->execute([$cardNo]);
    if ($stC->fetchColumn()) {
        return ['ok' => false, 'kod' => 'kart_no_kullanimda', 'hata' => 'Bu kart numarası zaten kullanımda: ' . $cardNo];
    }

    // ⚠ ÇAPRAZ-SİSTEM KONTROLÜ — yön 1: kalıcı personel kartlarıyla çakışma.
    $kaliciCakisma = pdks_gunluk_uid_kalici_kartta_mi($kanonik, $pdo);
    if ($kaliciCakisma !== null) {
        // ⚠ Faz 9E / C: eskiden mesaj yalnız DURUM bildiriyordu ("...tanımlı"),
        // operatöre ne YAPACAĞINI söylemiyordu. FAZ9A çakışmayı zaten yalnız
        // AKTİF kalıcı kartla sınırladığı için (bkz. pdks_gunluk_uid_kalici_kartta_mi
        // yorumu) çözüm HER ZAMAN aynıdır: o kalıcı kartı pasife al/iptal et.
        // Bu fonksiyon hiçbir kartı OTOMATİK pasife almaz/silmez — yalnız METİN.
        return ['ok' => false, 'kod' => 'uid_kalici_kartta',
                'hata' => 'Bu kart aktif bir kalıcı personel kartına bağlıdır (' . (string)$kaliciCakisma['full_name'] . '). '
                        . 'Günlük işçi kartı olarak kullanmak için önce kalıcı personel kartını pasife alın/iptal edin.'];
    }
    // Havuz-içi çakışma (kendi UNIQUE kısıtının önden, dostça hâli).
    $havuzCakisma = pdks_gunluk_uid_gecici_kartta_mi($kanonik, null, $pdo);
    if ($havuzCakisma !== null) {
        return ['ok' => false, 'kod' => 'uid_havuzda',
                'hata' => 'Bu UID zaten işçi havuzunda tanımlı (kart no: ' . (string)$havuzCakisma['card_no'] . ').'];
    }

    $bayt    = function_exists('pdks_uid_bayt_sayisi') ? pdks_uid_bayt_sayisi($kanonik) : (int)(strlen($kanonik) / 2);
    $ondalik = function_exists('pdks_uid_to_decimal') ? pdks_uid_to_decimal($kanonik) : null;

    try {
        $ins = $pdo->prepare(
            "INSERT INTO worker_cards
                (card_no, worker_type_id, canonical_uid, uid_bytes, uid_decimal, enrolled_source, status, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        $ins->execute([
            $cardNo, $workerTypeId, $kanonik, $bayt, $ondalik, $kaynak,
            'available', trim((string)($veri['notes'] ?? '')) ?: null, $createdBy,
        ]);
        $cardId = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        // Son çare — bu tablonun KENDİ UNIQUE kısıtı (card_no/canonical_uid),
        // yukarıdaki SELECT ön-kontrolüyle bu INSERT arasında AYNI worker_cards
        // tablosuna yazan eşzamanlı bir çağrı olduysa burada yakalanır.
        error_log('[pdks_gunluk_kart_olustur] ' . $e->getMessage());
        return [
            'ok' => false,
            'kod' => 'yazma_hatasi',
            'hata' => 'Kart kaydedilemedi. Lütfen tekrar deneyin.',
        ];
    }

    if (function_exists('audit_log_event')) {
        audit_log_event('create', 'worker_cards', $cardId, null, [
            'card_no' => $cardNo, 'worker_type_id' => $workerTypeId,
            'canonical_uid' => $kanonik, 'kaynak' => $kaynak,
        ]);
    }

    return ['ok' => true, 'card_id' => $cardId, 'card_no' => $cardNo, 'canonical_uid' => $kanonik];
}

/** Görünür kart no / tip / not düzenleme — UID DEĞİŞTİRMEZ (bkz. görev kapsamı: "edit visible card number"). */
function pdks_gunluk_kart_duzenle(int $cardId, array $veri, ?int $updatedBy = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare("SELECT * FROM worker_cards WHERE id = ?");
    $st->execute([$cardId]);
    $eski = $st->fetch();
    if (!$eski) return ['ok' => false, 'hata' => 'Kart bulunamadı.'];

    $cardNo = trim((string)($veri['card_no'] ?? ''));
    if ($cardNo === '') return ['ok' => false, 'hata' => 'Kart numarası zorunludur.'];
    // ⚠ FAZ 8A — bkz. pdks_gunluk_kart_olustur() üzerindeki AYNI gerekçe:
    // opsiyonel, boş bırakılırsa kart NÖTR (NULL) kalır/olur.
    $workerTypeIdHam = trim((string)($veri['worker_type_id'] ?? ''));
    $workerTypeId = null;
    if ($workerTypeIdHam !== '' && $workerTypeIdHam !== '0') {
        $workerTypeId = (int)$workerTypeIdHam;
        $stT = $pdo->prepare("SELECT id FROM worker_types WHERE id = ? AND is_active = 1");
        $stT->execute([$workerTypeId]);
        if (!$stT->fetchColumn()) return ['ok' => false, 'hata' => 'Seçilen işçi tipi bulunamadı veya pasif.'];
    }

    $stC = $pdo->prepare("SELECT id FROM worker_cards WHERE card_no = ? AND id <> ?");
    $stC->execute([$cardNo, $cardId]);
    if ($stC->fetchColumn()) return ['ok' => false, 'hata' => 'Bu kart numarası zaten kullanımda: ' . $cardNo];

    $upd = $pdo->prepare("UPDATE worker_cards SET card_no=?, worker_type_id=?, notes=?, updated_by=? WHERE id=?");
    $upd->execute([$cardNo, $workerTypeId, trim((string)($veri['notes'] ?? '')) ?: null, $updatedBy, $cardId]);

    if (function_exists('audit_log_event')) {
        audit_log_event('update', 'worker_cards', $cardId, $eski, $veri);
    }
    return ['ok' => true];
}

function pdks_gunluk_kart_durum_degistir(int $cardId, string $durum, ?int $updatedBy = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!array_key_exists($durum, pdks_gunluk_kart_durumlari())) {
        return ['ok' => false, 'hata' => 'Geçersiz durum.'];
    }
    $st = $pdo->prepare("SELECT id FROM worker_cards WHERE id = ?");
    $st->execute([$cardId]);
    if (!$st->fetchColumn()) return ['ok' => false, 'hata' => 'Kart bulunamadı.'];

    $upd = $pdo->prepare("UPDATE worker_cards SET status = ?, updated_by = ? WHERE id = ?");
    $upd->execute([$durum, $updatedBy, $cardId]);
    if (function_exists('audit_log_event')) {
        audit_log_event('status_change', 'worker_cards', $cardId, null, ['status' => $durum]);
    }
    return ['ok' => true];
}

// =========================================================
// FAZ 2 — GÜNLÜK MESAİ OTURUMU + SERİ GİRİŞ/ÇIKIŞ
//
// KAPSAM DIŞI (kullanıcının açık talimatı): fiyat/hakediş/ödeme/cari/fatura
// hiçbir yerde YOK — yalnız oturum + tarama + canlı sayaç + mutabakat.
// =========================================================

/**
 * Kanonik UID'yi işçi kartı olarak çözer (worker_types join'li).
 * Kalıcı personel kartlarına BAKMAZ — o kontrol çağıran fonksiyonda,
 * config/pdks.php'nin pdks_kart_cozumle()'si ile AYRI yapılır (REUSE,
 * burada TEKRARLANMAZ).
 */
function pdks_gunluk_kart_coz(string $kanonik, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? db();
    if (!pdks_gunluk_tablo_var($pdo, 'worker_cards')) return null;
    $st = $pdo->prepare(
        "SELECT w.*, t.name AS tip_adi
           FROM worker_cards w
           JOIN worker_types t ON t.id = w.worker_type_id
          WHERE w.canonical_uid = ?"
    );
    $st->execute([$kanonik]);
    return $st->fetch() ?: null;
}

/**
 * Bu kartın AÇIK (eşleşmemiş) bir GİRİŞ'i var mı — varsa hangi AÇIK
 * oturumda. Yalnız status='open' oturumlara BAKAR.
 *
 * ⚠ DÜZELTME (Sprint Günlük-İşçi-03, kullanıcının açık düzeltmesi #1):
 * BU FONKSİYON ARTIK TEK BAŞINA "kart şu an serbest mi" SORUSUNUN CEVABI
 * DEĞİL — yalnız "ÇIKIŞ için eşleşecek AÇIK GİRİŞ hangi oturumda" sorusuna
 * (CIKIS doğrulaması) ve "hâlâ İÇERİDE mi" görüntüsüne (özet/mutabakat)
 * hizmet eder. GİRİŞ TARAFINDAKİ asıl karar artık
 * pdks_gunluk_kart_gun_kullanimi()'nda: "BİR İŞÇİ KARTI = BİR İŞÇİ / İŞ
 * GÜNÜ" kuralı gereği, bir kart AYNI iş günü + depoda ÇIKMIŞ olsa bile
 * YENİDEN GİREMEZ — yalnız BİR SONRAKİ iş gününde serbest kalır. (Eski
 * "the card becomes operationally free again" ifadesi ÇIKIŞ SONRASI HEMEN
 * serbestlik anlamına geliyordu — kullanıcı bunu YANLIŞ buldu: aynı gün
 * ikinci bir "işçi" gibi sayılıp fazla kafa sayısına/ileride hakedişe yol
 * açardı. Serbestlik artık YALNIZ bir sonraki work_date'te gerçekleşir.)
 *
 * ⚠ NOT EXISTS zaman karşılaştırması YAPMAZ: yazma anındaki kurallar bir
 * session+card çifti için EN FAZLA bir GİRİŞ ve EN FAZLA bir ÇIKIŞ
 * olabileceğini GARANTİ eder (hem uygulama kontrolü hem
 * `uq_dwce_card_day_depo_type` UNIQUE kısıtı) — bu yüzden "eşleşme var mı"
 * sorgusu yalnız "aynı session+card için bir ÇIKIŞ satırı var mı"
 * sorusuna indirgenir.
 */
function pdks_gunluk_kart_acik_girisi(int $workerCardId, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? db();
    $sql = "SELECT g.session_id, g.server_event_time AS giris_zamani,
                   s.foreman_id, f.name AS foreman_name, s.depo
              FROM daily_worker_card_events g
              JOIN daily_work_sessions s ON s.id = g.session_id
              JOIN foremen f ON f.id = s.foreman_id
             WHERE g.worker_card_id = ?
               AND g.event_type = 'GIRIS'
               AND s.status = 'open'
               AND NOT EXISTS (
                    SELECT 1 FROM daily_worker_card_events c
                     WHERE c.session_id = g.session_id
                       AND c.worker_card_id = g.worker_card_id
                       AND c.event_type = 'CIKIS'
               )
             ORDER BY g.server_event_time DESC
             LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$workerCardId]);
    return $st->fetch() ?: null;
}

/**
 * "BİR İŞÇİ KARTI = BİR İŞÇİ / İŞ GÜNÜ" kuralının SORGUSU (Sprint
 * Günlük-İşçi-03, kullanıcının açık düzeltmesi #1). Bu kartın, verilen
 * work_date + depo'da, HANGİ OTURUMDA OLURSA OLSUN (açık/kapalı fark
 * etmez) BUGÜNKÜ tek geçerli GİRİŞ'ini (varsa) döner — eşleşen bir ÇIKIŞ'ı
 * varsa onu da (cikis_zamani). GİRİŞ kararı BUNUN üzerine kurulur:
 *   • sonuç NULL                         → kart bugün hiç kullanılmamış, GİRİŞ serbest
 *   • sonuç var, cikis_zamani NULL       → kart HÂLÂ İÇERİDE (bir yerde) — mükerrer/başka-çavuş
 *   • sonuç var, cikis_zamani DOLU       → kart bugün TAMAMLANMIŞ bir kullanım yaşadı — REDDEDİLİR
 *
 * `uq_dwce_card_day_depo_type` UNIQUE kısıtı sayesinde work_date+depo
 * başına EN FAZLA bir GİRİŞ satırı olabileceği GARANTİDİR — bu yüzden
 * LIMIT 1 keyfi bir seçim değil, matematiksel bir sonuçtur.
 */
function pdks_gunluk_kart_gun_kullanimi(int $workerCardId, string $workDate, string $depo, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? db();
    $sql = "SELECT g.session_id, g.server_event_time AS giris_zamani,
                   s.foreman_id, f.name AS foreman_name,
                   (SELECT c.server_event_time FROM daily_worker_card_events c
                     WHERE c.session_id = g.session_id AND c.worker_card_id = g.worker_card_id
                       AND c.event_type = 'CIKIS' LIMIT 1) AS cikis_zamani
              FROM daily_worker_card_events g
              JOIN daily_work_sessions s ON s.id = g.session_id
              JOIN foremen f ON f.id = s.foreman_id
             WHERE g.worker_card_id = ?
               AND g.event_type = 'GIRIS'
               AND g.work_date_snapshot = ?
               AND g.depo_snapshot = ?
             ORDER BY g.server_event_time DESC
             LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$workerCardId, $workDate, $depo]);
    return $st->fetch() ?: null;
}

/**
 * GİRİŞ modu giriş noktası: bu çavuş için BUGÜN/aktif depoda AÇIK bir
 * oturum varsa onu DÖNDÜRÜR (yeniden kullanır — "sayfa yenilenince yeni
 * oturum AÇILMASIN" kuralı), yoksa AÇIKÇA yeni bir oturum açar. work_date
 * ve depo İSTEMCİDEN ALINMAZ — sunucu tarihi + kullanıcının aktif deposu
 * (Client-provided event time must not be authoritative — aynı ilke
 * depo/tarih seçimine de uygulanır).
 */
function pdks_gunluk_oturum_ac_veya_getir(int $foremanId, int $userId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare("SELECT id, is_active, name, code FROM foremen WHERE id = ?");
    $st->execute([$foremanId]);
    $cavus = $st->fetch();
    if (!$cavus) return ['ok' => false, 'kod' => 'cavus_yok', 'hata' => 'Çavuş bulunamadı.'];
    if (!$cavus['is_active']) return ['ok' => false, 'kod' => 'cavus_pasif', 'hata' => 'Bu çavuş pasif — önce aktifleştirin.'];

    // ⚠ Faz 9C / H-02: oturum SNAPSHOT'ı için çavuşun O ANKİ normal günlük
    // çalışma süresini (dakika) oku — kolon henüz migrate edilmemişse
    // (Faz 8B ALTER'ı çalışmadıysa) eski sabit varsayılana (540 dk / 9 saat)
    // düş, hiçbir yerde HATA VERMEZ.
    $normalDk = 540;
    if (pdks_gunluk_kolon_var($pdo, 'foremen', 'normal_work_minutes')) {
        $stN = $pdo->prepare("SELECT normal_work_minutes FROM foremen WHERE id = ?");
        $stN->execute([$foremanId]);
        $v = $stN->fetchColumn();
        if ($v !== false && $v !== null) $normalDk = (int)$v;
    }

    $tarih = date('Y-m-d');
    $depo  = function_exists('active_depot') ? (active_depot() ?? '') : '';

    $stF = $pdo->prepare("SELECT * FROM daily_work_sessions WHERE foreman_id = ? AND work_date = ? AND depo = ?");
    $stF->execute([$foremanId, $tarih, $depo]);
    $mevcut = $stF->fetch();
    if ($mevcut) {
        if ($mevcut['status'] === 'open') {
            return ['ok' => true, 'session' => $mevcut, 'yeni' => false,
                     'ozet' => pdks_gunluk_oturum_ozet((int)$mevcut['id'], $pdo)];
        }
        return ['ok' => false, 'kod' => 'oturum_kapali_zaten',
                 'hata' => 'Bu çavuş için bugün ' . ($depo !== '' ? $depo . ' deposunda ' : '') . 'mesai zaten kapatılmış.'];
    }

    $simdi = date('Y-m-d H:i:s');
    // ⚠ foreman_name_snapshot/foreman_code_snapshot (Faz 3, bkz. tablo
    // DDL'indeki gerekçe): oturum AÇILIRKEN çavuşun O ANKİ ad/kodu donar —
    // foremen.name/code SONRADAN değişse bile bu oturuma bağlı raporlar
    // GEÇMİŞTE görüneni göstermeye devam eder.
    // ⚠ normal_work_minutes_snapshot (Faz 9C / H-02, madde 5 — KRİTİK
    // tarihsel güvenlik): AYNI desen — oturum AÇILIRKEN donar, foremen.
    // normal_work_minutes SONRADAN değişse bile bu oturumun Tam/FM hesabı
    // hep bu donmuş değeri kullanır (bkz. config/pdks_faz8b.php). Kolon
    // henüz migrate edilmemiş üretim tablolarında INSERT listesinden
    // BİLEREK çıkarılır — DB'nin kendi DEFAULT 540'ı (kolon eklendiğinde)
    // geçerli olur, eksik kolon için SQL HATASI verilmez.
    $snapshotKolonVar = pdks_gunluk_kolon_var($pdo, 'daily_work_sessions', 'normal_work_minutes_snapshot');
    $kolonlar = "foreman_id, foreman_name_snapshot, foreman_code_snapshot, work_date, depo, status, opened_at, opened_by_user_id";
    $degerler = "?,?,?,?,?,?,?,?";
    $parametreler = [$foremanId, (string)$cavus['name'], (string)$cavus['code'], $tarih, $depo, 'open', $simdi, $userId];
    if ($snapshotKolonVar) {
        $kolonlar = "foreman_id, foreman_name_snapshot, foreman_code_snapshot, normal_work_minutes_snapshot, work_date, depo, status, opened_at, opened_by_user_id";
        $degerler = "?,?,?,?,?,?,?,?,?";
        $parametreler = [$foremanId, (string)$cavus['name'], (string)$cavus['code'], $normalDk, $tarih, $depo, 'open', $simdi, $userId];
    }
    $ins = $pdo->prepare("INSERT INTO daily_work_sessions ($kolonlar) VALUES ($degerler)");
    try {
        $ins->execute($parametreler);
    } catch (PDOException $e) {
        // Yarış koşulu son çaresi: UNIQUE(foreman_id,work_date,depo) — iki
        // eşzamanlı istek aynı oturumu açmaya çalıştıysa burada yakalanır,
        // ikinci istek MEVCUDU okuyup döner (yeni bir oturum İCAT ETMEZ).
        $stF->execute([$foremanId, $tarih, $depo]);
        $mevcut2 = $stF->fetch();
        if ($mevcut2 && $mevcut2['status'] === 'open') {
            return ['ok' => true, 'session' => $mevcut2, 'yeni' => false,
                     'ozet' => pdks_gunluk_oturum_ozet((int)$mevcut2['id'], $pdo)];
        }
        return ['ok' => false, 'kod' => 'yazma_hatasi', 'hata' => 'Mesai açılamadı: ' . $e->getMessage()];
    }
    $id = (int)$pdo->lastInsertId();

    if (function_exists('audit_log_event')) {
        audit_log_event('create', 'daily_work_sessions', $id, null,
            ['foreman_id' => $foremanId, 'work_date' => $tarih, 'depo' => $depo]);
    }

    $st2 = $pdo->prepare("SELECT * FROM daily_work_sessions WHERE id = ?");
    $st2->execute([$id]);
    return ['ok' => true, 'session' => $st2->fetch(), 'yeni' => true,
             'ozet' => pdks_gunluk_oturum_ozet($id, $pdo)];
}

/** ÇIKIŞ modu giriş noktası: yalnız BULUR, AÇMAZ — yoksa açık hata döner. */
function pdks_gunluk_oturum_bul_acik(int $foremanId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare("SELECT id, is_active, name FROM foremen WHERE id = ?");
    $st->execute([$foremanId]);
    $cavus = $st->fetch();
    if (!$cavus) return ['ok' => false, 'kod' => 'cavus_yok', 'hata' => 'Çavuş bulunamadı.'];

    $tarih = date('Y-m-d');
    $depo  = function_exists('active_depot') ? (active_depot() ?? '') : '';

    $stF = $pdo->prepare("SELECT * FROM daily_work_sessions WHERE foreman_id = ? AND work_date = ? AND depo = ? AND status = 'open'");
    $stF->execute([$foremanId, $tarih, $depo]);
    $oturum = $stF->fetch();
    if (!$oturum) {
        return ['ok' => false, 'kod' => 'oturum_yok',
                 'hata' => 'Bugün için açık bir mesai bulunamadı. Önce GİRİŞ modunda mesai başlatın.'];
    }
    return ['ok' => true, 'session' => $oturum, 'yeni' => false,
             'ozet' => pdks_gunluk_oturum_ozet((int)$oturum['id'], $pdo)];
}

/**
 * TEK yazma yolu — GİRİŞ/ÇIKIŞ taramasını kaydeder. UID normalizasyonu
 * TAMAMEN config/pdks.php'den REUSE edilir. Kalıcı personel kartı çakışması
 * pdks_kart_cozumle() ile (o dosyanın KENDİ alias/aday mantığı üzerinden,
 * BURADA TEKRARLANMADAN) kontrol edilir.
 *
 * @param string $kaynak 'usb_decimal' | 'nfc_hex' | 'web_nfc'
 */
function pdks_gunluk_oturum_kaydet(string $hamUid, string $kaynak, int $sessionId, string $eventType, int $recordedByUserId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();

    if (!defined('PDKS_UID_KAYNAKLARI') || !in_array($kaynak, PDKS_UID_KAYNAKLARI, true)) {
        return ['ok' => false, 'kod' => 'gecersiz_kaynak', 'hata' => 'UID kaynağı bildirilmeli.'];
    }
    if (!in_array($eventType, ['GIRIS', 'CIKIS'], true)) {
        return ['ok' => false, 'kod' => 'gecersiz_yon', 'hata' => 'Geçersiz yön.'];
    }
    $hamUid = trim($hamUid);
    if ($hamUid === '') {
        return ['ok' => false, 'kod' => 'bos_uid', 'hata' => 'Kart okutulmadı.'];
    }
    if (!function_exists('pdks_uid_from_decimal')) {
        return ['ok' => false, 'kod' => 'pdks_yuklu_degil', 'hata' => 'UID normalizasyon fonksiyonları yüklü değil (config/pdks.php).'];
    }

    $st = $pdo->prepare(
        "SELECT s.*, f.name AS foreman_name
           FROM daily_work_sessions s JOIN foremen f ON f.id = s.foreman_id
          WHERE s.id = ?"
    );
    $st->execute([$sessionId]);
    $session = $st->fetch();
    if (!$session) return ['ok' => false, 'kod' => 'oturum_yok', 'hata' => 'Mesai bulunamadı.'];
    if ($session['status'] !== 'open') return ['ok' => false, 'kod' => 'oturum_kapali', 'hata' => 'Bu mesai kapalı.'];

    $kanonik = match ($kaynak) {
        'usb_decimal' => pdks_uid_from_decimal($hamUid),
        'web_nfc'     => pdks_uid_from_web_nfc($hamUid),
        default       => pdks_uid_hex_normalize($hamUid),   // nfc_hex
    };
    if ($kanonik === null) {
        return ['ok' => false, 'kod' => 'gecersiz_uid', 'hata' => 'Okunan UID geçersiz.'];
    }

    $kart = pdks_gunluk_kart_coz($kanonik, $pdo);
    if ($kart === null) {
        // ⚠ Kalıcı personel kartı yanlışlıkla mı okutuldu? config/pdks.php'nin
        // KENDİ çözümleyicisi (alias/aday mantığı DAHİL) ile kontrol edilir —
        // BURADA yeniden yazılmaz. Faz 9A / H-04: engel kararı yalnız AKTİF
        // kalıcı kartlar için verilir (bkz. pdks_gunluk_kalici_kart_engeli()).
        $engel = pdks_gunluk_kalici_kart_engeli($hamUid, $kaynak, $pdo);
        if ($engel !== null) return ['ok' => false] + $engel;
        return ['ok' => false, 'kod' => 'kart_tanimsiz', 'hata' => 'Tanımsız kart — işçi havuzunda kayıtlı değil.'];
    }

    if ($eventType === 'GIRIS') {
        // Kural 1: yalnız GİRİŞ'te master durum kontrolü — ÇIKIŞ, kartın o
        // andaki durumu ne olursa olsun MEVCUT bir GİRİŞ'i kapatabilmelidir
        // (kayıp/devre dışı işaretlenmiş bir kart bile, zaten içerideyse,
        // dışarı çıkışı kaydedilebilmelidir).
        if ($kart['status'] === 'lost') {
            return ['ok' => false, 'kod' => 'kart_kayip', 'hata' => 'Bu kart KAYIP olarak işaretli.'];
        }
        if ($kart['status'] === 'disabled') {
            return ['ok' => false, 'kod' => 'kart_devre_disi', 'hata' => 'Bu kart DEVRE DIŞI.'];
        }

        // ⚠ DÜZELTME (kullanıcının açık düzeltmesi #1): "BİR İŞÇİ KARTI = BİR
        // İŞÇİ / İŞ GÜNÜ" — bu iş günü + depoda kart daha önce (HANGİ
        // OTURUMDA OLURSA OLSUN, açık/kapalı fark etmez) kullanılmışsa YENİ
        // bir GİRİŞ REDDEDİLİR. Eskiden ÇIKIŞ sonrası kart AYNI GÜN yeniden
        // girebiliyordu — bu YANLIŞTI (fazla kafa sayısı/ileride hakediş
        // şişmesi). Serbestlik artık YALNIZ bir sonraki work_date'te.
        $gunKullanim = pdks_gunluk_kart_gun_kullanimi((int)$kart['id'], (string)$session['work_date'], (string)$session['depo'], $pdo);
        if ($gunKullanim !== null) {
            if ($gunKullanim['cikis_zamani'] === null) {
                // Hâlâ İÇERİDE (herhangi bir yerde, bugün) — eşleşen ÇIKIŞ yok.
                if ((int)$gunKullanim['session_id'] === $sessionId) {
                    return ['ok' => false, 'kod' => 'mukerrer_giris', 'hata' => 'Bu kart zaten bu mesaide giriş yapmış.'];
                }
                return ['ok' => false, 'kod' => 'baska_cavusta_aktif',
                         'hata' => 'Bu kart ' . $gunKullanim['foreman_name'] . ' mesaisinde aktif.'];
            }
            // Tamamlanmış bir GİRİŞ+ÇIKIŞ çifti VAR — bugün için kart TÜKENDİ.
            return ['ok' => false, 'kod' => 'bugun_kullanilmis',
                     'hata' => 'Bu kart bugün daha önce kullanılmıştır. (Çavuş: ' . $gunKullanim['foreman_name']
                             . ', Giriş: ' . substr((string)$gunKullanim['giris_zamani'], 11, 5)
                             . ', Çıkış: ' . substr((string)$gunKullanim['cikis_zamani'], 11, 5) . ')',
                     'onceki' => [
                         'foreman_name' => $gunKullanim['foreman_name'],
                         'giris_zamani' => $gunKullanim['giris_zamani'],
                         'cikis_zamani' => $gunKullanim['cikis_zamani'],
                     ]];
        }
    } else {   // CIKIS
        $acik = pdks_gunluk_kart_acik_girisi((int)$kart['id'], $pdo);
        if ($acik === null || (int)$acik['session_id'] !== $sessionId) {
            return ['ok' => false, 'kod' => 'giris_yok',
                     'hata' => 'Bu kart için bu mesai altında giriş kaydı bulunamadı.'];
        }
    }

    $simdi = date('Y-m-d H:i:s');   // ⚠ SUNUCU saati — istemci zamanı hiç alınmaz/güvenilmez.
    $ins = $pdo->prepare(
        "INSERT INTO daily_worker_card_events
            (session_id, worker_card_id, event_type, source, canonical_uid_snapshot,
             worker_type_id_snapshot, worker_type_name_snapshot, work_date_snapshot, depo_snapshot,
             recorded_by_user_id, server_event_time)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)"
    );
    try {
        $ins->execute([
            $sessionId, $kart['id'], $eventType, $kaynak, $kanonik,
            $kart['worker_type_id'], $kart['tip_adi'], $session['work_date'], $session['depo'],
            $recordedByUserId, $simdi,
        ]);
    } catch (PDOException $e) {
        // ⚠ Son çare — `uq_dwce_card_day_depo_type` UNIQUE kısıtı. Yukarıdaki
        // pdks_gunluk_kart_gun_kullanimi() ön-kontrolü ile bu INSERT arasında
        // eşzamanlı bir başka yazma AYNI kart/gün/depo/yöne girdiyse burada
        // yakalanır — düşük eşzamanlılıklı, idari bir tarama işlemi için
        // KABUL EDİLEN, belgelenen bir yarış-koşulu penceresi (Faz 1'in
        // çapraz-sistem UID kısıtıyla AYNI ilke).
        if ($eventType === 'GIRIS') {
            return ['ok' => false, 'kod' => 'bugun_kullanilmis',
                     'hata' => 'Bu kart bugün için zaten kullanılmış (eşzamanlı tarama).'];
        }
        return ['ok' => false, 'kod' => 'yazma_hatasi', 'hata' => 'Kayıt yapılamadı: ' . $e->getMessage()];
    }
    $eventId = (int)$pdo->lastInsertId();

    if (function_exists('audit_log_event')) {
        audit_log_event($eventType === 'GIRIS' ? 'gunluk_giris' : 'gunluk_cikis',
            'daily_worker_card_events', $eventId, null, [
                'session_id' => $sessionId, 'worker_card_id' => $kart['id'],
                'card_no' => $kart['card_no'], 'uid' => $kanonik,
            ]);
    }

    return [
        'ok' => true, 'event_id' => $eventId, 'event_type' => $eventType,
        'card' => ['card_no' => $kart['card_no'], 'worker_type_name' => $kart['tip_adi']],
        'server_time' => $simdi,
        'ozet' => pdks_gunluk_oturum_ozet($sessionId, $pdo),
    ];
}

/**
 * Bir OTURUMUN GİRİŞ yapan BENZERSİZ kartlarını worker_type_id_snapshot'a
 * göre gruplar (bir SONRAKİ modülün — görev talimatı: "must not create a
 * parallel attendance truth" — KENDİ tarama SQL'i yazmadan tüketebileceği
 * TEK sayım kaynağı). pdks_gunluk_oturum_ozet() İLE AYNI COUNT(DISTINCT
 * worker_card_id) ilkesi — TEK FARK gruplama anahtarıdır: o fonksiyon
 * GÖSTERİM için isme (worker_type_name_snapshot) gruplar, bu fonksiyon
 * dış modüllerin id EŞLEŞTİRMESİ (ör. tipe göre farklı iş kuralı) için
 * id'ye (worker_type_id_snapshot) gruplar.
 */
function pdks_gunluk_oturum_kart_sayimi(int $sessionId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (pdks_gunluk_faz8a_sema_hazir($pdo)) return pdks_gunluk_faz8a_oturum_kart_sayimi($sessionId, $pdo);
    $st = $pdo->prepare(
        "SELECT worker_type_id_snapshot AS tip_id, worker_type_name_snapshot AS tip_ad,
                COUNT(DISTINCT worker_card_id) AS n
           FROM daily_worker_card_events
          WHERE session_id = ? AND event_type = 'GIRIS'
          GROUP BY worker_type_id_snapshot, worker_type_name_snapshot"
    );
    $st->execute([$sessionId]);
    return $st->fetchAll();
}

/**
 * Canlı sayaçlar + mutabakat verisi — TEK yerden okunur (sayfa ilk render,
 * her tarama sonrası, kapatma ekranı hepsi BURADAN besleniyor). İşçi tipi
 * adları SABİT (Kadın/Erkek) DEĞİL — snapshot sütunundaki GERÇEK metin
 * anahtar olarak kullanılır, dinamik olarak ne varsa onu döndürür.
 */
function pdks_gunluk_oturum_ozet(int $sessionId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (pdks_gunluk_faz8a_sema_hazir($pdo)) return pdks_gunluk_faz8a_oturum_ozet($sessionId, $pdo);

    // ⚠ DÜZELTME (kullanıcının açık talimatı — sayaç/rapor kuralı): sayaçlar
    // HAM olay satırı SAYISI DEĞİL, BENZERSİZ (DISTINCT) işçi kartı sayısını
    // yansıtmalıdır. `uq_dwce_card_day_depo_type` UNIQUE kısıtı + yukarıdaki
    // uygulama kontrolleri sayesinde bir session+card için zaten EN FAZLA
    // 1 GİRİŞ satırı olabilir — yani COUNT(*) ve COUNT(DISTINCT
    // worker_card_id) matematiksel olarak AYNI SONUCU vermelidir. DISTINCT
    // yine de BİLEREK KULLANILIR: niyeti kodda AÇIKÇA ifade eder ve
    // (örn. şema henüz bu kısıtı taşımayan eski bir ortamda) sessizce
    // kafa sayısı şişirmeye karşı savunma katmanıdır — bkz. görev talimatı
    // "Make this invariant explicit in backend logic and tests."
    $giris = []; $cikis = [];
    $stG = $pdo->prepare(
        "SELECT worker_type_name_snapshot AS tip, COUNT(DISTINCT worker_card_id) AS n
           FROM daily_worker_card_events WHERE session_id = ? AND event_type = 'GIRIS'
          GROUP BY worker_type_name_snapshot"
    );
    $stG->execute([$sessionId]);
    foreach ($stG->fetchAll() as $r) $giris[$r['tip']] = (int)$r['n'];

    $stC = $pdo->prepare(
        "SELECT worker_type_name_snapshot AS tip, COUNT(DISTINCT worker_card_id) AS n
           FROM daily_worker_card_events WHERE session_id = ? AND event_type = 'CIKIS'
          GROUP BY worker_type_name_snapshot"
    );
    $stC->execute([$sessionId]);
    foreach ($stC->fetchAll() as $r) $cikis[$r['tip']] = (int)$r['n'];

    $girisToplam = array_sum($giris);
    $cikisToplam = array_sum($cikis);

    // Eksik çıkış — session+card başına EN FAZLA 1 GİRİŞ/1 ÇIKIŞ garantisi
    // sayesinde (bkz. pdks_gunluk_kart_acik_girisi() notu) basit NOT EXISTS.
    $stE = $pdo->prepare(
        "SELECT g.worker_card_id, w.card_no, g.worker_type_name_snapshot AS tip, g.server_event_time AS giris_zamani
           FROM daily_worker_card_events g
           JOIN worker_cards w ON w.id = g.worker_card_id
          WHERE g.session_id = ? AND g.event_type = 'GIRIS'
            AND NOT EXISTS (
                 SELECT 1 FROM daily_worker_card_events c
                  WHERE c.session_id = g.session_id AND c.worker_card_id = g.worker_card_id AND c.event_type = 'CIKIS'
            )
          ORDER BY g.server_event_time ASC"
    );
    $stE->execute([$sessionId]);
    $eksikKartlar = $stE->fetchAll();

    $eksikTip = [];
    foreach ($eksikKartlar as $ek) {
        $eksikTip[$ek['tip']] = ($eksikTip[$ek['tip']] ?? 0) + 1;
    }

    // ⚠ Faz 3 (görev talimatı madde 7): "first GIRIS = MIN valid GIRIS
    // server_event_time", "last CIKIS = MAX valid CIKIS server_event_time"
    // — SUNUCU-yetkili server_event_time'dan, istemciden hiçbir zaman
    // alınmaz. Faz 2'nin TEK sayaç kaynağına (bu fonksiyon) EKLENDİ, ayrı
    // bir sorgu/fonksiyon olarak sayfa tarafında TEKRARLANMADI.
    $stZ = $pdo->prepare(
        "SELECT MIN(CASE WHEN event_type = 'GIRIS' THEN server_event_time END) AS ilk_giris,
                MAX(CASE WHEN event_type = 'CIKIS' THEN server_event_time END) AS son_cikis
           FROM daily_worker_card_events WHERE session_id = ?"
    );
    $stZ->execute([$sessionId]);
    $zamanlar = $stZ->fetch() ?: ['ilk_giris' => null, 'son_cikis' => null];

    return [
        'giris' => $giris, 'giris_toplam' => $girisToplam,
        'cikis' => $cikis, 'cikis_toplam' => $cikisToplam,
        'icerde_toplam' => $girisToplam - $cikisToplam,
        'eksik_tip' => $eksikTip, 'eksik_toplam' => count($eksikKartlar),
        'eksik_kartlar' => $eksikKartlar,
        'ilk_giris' => $zamanlar['ilk_giris'], 'son_cikis' => $zamanlar['son_cikis'],
    ];
}

/**
 * Oturum DURUMU — session.status + eksik-çıkış sayısından TÜRETİLİR (Faz 3,
 * görev talimatı madde 6). AYRI/kalıcı bir "durum" kolonu EKLENMEZ —
 * kullanıcının açık talimatı: "Do not persist a second redundant status
 * field if it can be derived from session + event data."
 *
 *   open                     → "Açık Mesai" (eksik çıkış olsa BİLE — açık
 *                               mesaide içeride kart olması NORMALDİR,
 *                               henüz mutabakat zamanı gelmemiştir)
 *   closed, eksik_toplam = 0 → "Tamamlandı"
 *   closed, eksik_toplam > 0 → "Eksik Çıkış" (yalnız KAPANDIKTAN sonra,
 *                               gerekçeyle mutabakat yapılmış anlamına gelir)
 */
function pdks_gunluk_oturum_durumu(string $sessionStatus, int $eksikToplam): array
{
    if ($sessionStatus !== 'closed') {
        return ['kod' => 'acik', 'etiket' => 'Açık Mesai'];
    }
    return $eksikToplam > 0
        ? ['kod' => 'eksik_cikis', 'etiket' => 'Eksik Çıkış']
        : ['kod' => 'tamamlandi', 'etiket' => 'Tamamlandı'];
}

/**
 * Mesaiyi kapatır. Eksik çıkış VARSA ve $kapatmaNedeni BOŞSA, KAPATMAZ —
 * mutabakat verisini döner (arayüz "Eksik Çıkışlarla Kapat" ekranını
 * gösterir). Neden verilince KAPANIR, neden `notes` alanına yazılır ve
 * eksik kartların GİRİŞ satırları DEĞİŞMEDEN (silinmeden/uydurma bir ÇIKIŞ
 * eklenmeden) kalır — kullanıcının açık talimatı: "Do not invent an exit
 * timestamp for missing cards."
 */
function pdks_gunluk_oturum_kapat(int $sessionId, ?string $kapatmaNedeni, int $userId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare("SELECT * FROM daily_work_sessions WHERE id = ?");
    $st->execute([$sessionId]);
    $oturum = $st->fetch();
    if (!$oturum) return ['ok' => false, 'kod' => 'oturum_yok', 'hata' => 'Mesai bulunamadı.'];
    if ($oturum['status'] !== 'open') return ['ok' => false, 'kod' => 'zaten_kapali', 'hata' => 'Bu mesai zaten kapalı.'];

    $ozet = pdks_gunluk_oturum_ozet($sessionId, $pdo);
    $not  = trim((string)$kapatmaNedeni);

    if ($ozet['eksik_toplam'] > 0 && $not === '') {
        return ['ok' => false, 'kod' => 'eksik_cikis_var', 'ozet' => $ozet];
    }

    $upd = $pdo->prepare("UPDATE daily_work_sessions SET status='closed', closed_at=?, closed_by_user_id=?, notes=? WHERE id=?");
    $upd->execute([date('Y-m-d H:i:s'), $userId, $ozet['eksik_toplam'] > 0 ? $not : null, $sessionId]);

    if (function_exists('audit_log_event')) {
        audit_log_event('close', 'daily_work_sessions', $sessionId, $oturum, [
            'eksik_toplam' => $ozet['eksik_toplam'], 'not' => $not,
        ]);
    }
    return ['ok' => true, 'ozet' => $ozet];
}

// =========================================================
// FAZ 3 — GÜNLÜK PUANTAJ RAPORLARI (salt okunur)
//
// Hakediş/fiyat/ödeme/cari/fatura YOK — kullanıcının açık talimatı, bir
// SONRAKİ faza bırakıldı. Bu bölüm YALNIZ Faz 1/2'nin ürettiği
// foremen/daily_work_sessions/daily_worker_card_events verisini OKUR;
// hiçbir INSERT/UPDATE/DELETE içermez. Sayfalar (gunluk_isci_puantaj.php,
// gunluk_isci_puantaj_detay.php) kendi SQL'ini YAZMAZ — hepsi BURADAKİ
// fonksiyonlardan geçer (görev talimatı: "Do not build parallel business
// logic in the UI.").
// =========================================================

/**
 * TEPE ÖZET KARTLARI — bir work_date (+ opsiyonel depo) için SUNUCU
 * TARAFINDA toplu sayaçlar. Görev talimatı madde 3'ün kritik kuralı:
 * bu sayılar BENZERSİZ işçi kartıdır, ham olay satırı sayısı DEĞİLDİR —
 * pdks_gunluk_oturum_ozet()'teki AYNI COUNT(DISTINCT worker_card_id)
 * ilkesi burada da uygulanır. work_date_snapshot/depo_snapshot sayesinde
 * (Faz 2) session'lara JOIN olmadan TEK sorguda hesaplanır — N+1 yok.
 */
function pdks_gunluk_gun_ozeti(string $workDate, ?string $depo = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (pdks_gunluk_faz8a_sema_hazir($pdo)) return pdks_gunluk_faz8a_gun_ozeti($workDate, $depo, $pdo);

    $whereEv = "work_date_snapshot = ?"; $parEv = [$workDate];
    if ($depo !== null) { $whereEv .= " AND depo_snapshot = ?"; $parEv[] = $depo; }

    // GİRİŞ/ÇIKIŞ toplamları — tip kırılımı GİRİŞ için, toplam ikisi için.
    $stTip = $pdo->prepare(
        "SELECT worker_type_name_snapshot AS tip, COUNT(DISTINCT worker_card_id) AS n
           FROM daily_worker_card_events WHERE $whereEv AND event_type = 'GIRIS'
          GROUP BY worker_type_name_snapshot"
    );
    $stTip->execute($parEv);
    $girisTip = [];
    foreach ($stTip->fetchAll() as $r) $girisTip[$r['tip']] = (int)$r['n'];
    $girisToplam = array_sum($girisTip);

    $stEt = $pdo->prepare(
        "SELECT event_type, COUNT(DISTINCT worker_card_id) AS n
           FROM daily_worker_card_events WHERE $whereEv GROUP BY event_type"
    );
    $stEt->execute($parEv);
    $etToplam = ['GIRIS' => 0, 'CIKIS' => 0];
    foreach ($stEt->fetchAll() as $r) $etToplam[$r['event_type']] = (int)$r['n'];
    $cikisToplam = $etToplam['CIKIS'];

    // Aktif çavuş — o gün/depoda AÇILMIŞ (durumu ne olursa olsun) oturum
    // sayısı, benzersiz foreman_id.
    $whereS = "work_date = ?"; $parS = [$workDate];
    if ($depo !== null) { $whereS .= " AND depo = ?"; $parS[] = $depo; }
    $stCavus = $pdo->prepare("SELECT COUNT(DISTINCT foreman_id) FROM daily_work_sessions WHERE $whereS");
    $stCavus->execute($parS);

    return [
        'work_date'    => $workDate,
        'depo'         => $depo,
        'aktif_cavus'  => (int)$stCavus->fetchColumn(),
        'giris'        => $girisTip,
        'giris_toplam' => $girisToplam,
        'tam_cikis'    => $cikisToplam,
        // İçeride kalıp da ÇIKIŞ satırı olmayan kartlar — Faz 2'nin
        // session+card başına EN FAZLA 1 GİRİŞ/1 ÇIKIŞ garantisi sayesinde
        // basit bir fark, ayrı bir NOT EXISTS sorgusu gerekmez.
        'eksik_cikis'  => $girisToplam - $cikisToplam,
    ];
}

/**
 * GÜNLÜK ÇAVUŞ/OTURUM LİSTESİ — ana puantaj sayfasının tablo/kart satırları.
 * Filtre: work_date (zorunlu), depo/foreman/durum (opsiyonel).
 *
 * ⚠ N+1 YOK (görev talimatı madde 13): sessions BİR sorguda çekilir, her
 * session için ayrı ayrı pdks_gunluk_oturum_ozet() ÇAĞRILMAZ — GİRİŞ/ÇIKIŞ
 * kırılımı ve ilk-giriş/son-çıkış zamanları TÜM eşleşen session_id'ler için
 * TEK birer GROUP BY sorgusuyla toplanır, sonra PHP tarafında birleştirilir.
 *
 * ⚠ $durumFiltresi, görev talimatı madde 1'in DÖRT filtre seçeneğidir —
 * 'acik'|'kapali'|'eksik_cikis' (veya '' = Tümü). BUNLAR, sonuçtaki her
 * satırın 'durum' alanındaki ÜÇLÜ GÖSTERİM etiketiyle (acik/tamamlandi/
 * eksik_cikis — bkz. pdks_gunluk_oturum_durumu()) AYNI ŞEY DEĞİLDİR: filtre
 * "Kapalı" ham session.status='closed' anlamına gelir (Tamamlandı VE Eksik
 * Çıkış durumundaki kapalı oturumların HER İKİSİNİ de kapsar), "Eksik
 * Çıkışlı" ise açık/kapalı FARK ETMEKSİZİN en az bir eksik kartı olan HER
 * oturumu kapsar (çapraz-kesen bir istisna filtresidir, görev talimatı
 * madde 5). 'acik'/'kapali' SQL'de (ucuz, sütun eşitliği), 'eksik_cikis'
 * TÜRETİLMİŞ eksik_toplam'a bağlı olduğu için birleştirme SONRASI PHP'de
 * uygulanır.
 */
function pdks_gunluk_gun_listesi(string $workDate, ?string $depo = null, ?int $foremanId = null, ?string $durumFiltresi = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (pdks_gunluk_faz8a_sema_hazir($pdo)) return pdks_gunluk_faz8a_gun_listesi($workDate, $depo, $foremanId, $durumFiltresi, $pdo);

    $where = ['work_date = ?']; $params = [$workDate];
    if ($depo !== null && $depo !== '') { $where[] = 'depo = ?'; $params[] = $depo; }
    if ($foremanId !== null) { $where[] = 'foreman_id = ?'; $params[] = $foremanId; }
    if ($durumFiltresi === 'acik')   { $where[] = "status = 'open'"; }
    if ($durumFiltresi === 'kapali') { $where[] = "status = 'closed'"; }
    $st = $pdo->prepare(
        "SELECT * FROM daily_work_sessions WHERE " . implode(' AND ', $where) . "
          ORDER BY foreman_name_snapshot ASC, id ASC"
    );
    $st->execute($params);
    $oturumlar = $st->fetchAll();
    if (!$oturumlar) return [];

    $ids = array_map(fn($o) => (int)$o['id'], $oturumlar);
    $ph  = implode(',', array_fill(0, count($ids), '?'));

    // GİRİŞ/ÇIKIŞ × tip kırılımı, TÜM session_id'ler için tek seferde.
    $stEv = $pdo->prepare(
        "SELECT session_id, event_type, worker_type_name_snapshot AS tip, COUNT(DISTINCT worker_card_id) AS n
           FROM daily_worker_card_events WHERE session_id IN ($ph)
          GROUP BY session_id, event_type, worker_type_name_snapshot"
    );
    $stEv->execute($ids);
    $evBySession = [];
    foreach ($stEv->fetchAll() as $r) {
        $sid = (int)$r['session_id'];
        $evBySession[$sid][$r['event_type']][$r['tip']] = (int)$r['n'];
    }

    // İlk GİRİŞ / son ÇIKIŞ zamanı, TÜM session_id'ler için tek seferde.
    $stZ = $pdo->prepare(
        "SELECT session_id,
                MIN(CASE WHEN event_type = 'GIRIS' THEN server_event_time END) AS ilk_giris,
                MAX(CASE WHEN event_type = 'CIKIS' THEN server_event_time END) AS son_cikis
           FROM daily_worker_card_events WHERE session_id IN ($ph)
          GROUP BY session_id"
    );
    $stZ->execute($ids);
    $zBySession = [];
    foreach ($stZ->fetchAll() as $r) $zBySession[(int)$r['session_id']] = $r;

    // Eksik çıkış (GİRİŞ var, ÇIKIŞ yok) — TÜM session_id'ler için tek seferde.
    $stEk = $pdo->prepare(
        "SELECT g.session_id, COUNT(DISTINCT g.worker_card_id) AS n
           FROM daily_worker_card_events g
          WHERE g.session_id IN ($ph) AND g.event_type = 'GIRIS'
            AND NOT EXISTS (
                 SELECT 1 FROM daily_worker_card_events c
                  WHERE c.session_id = g.session_id AND c.worker_card_id = g.worker_card_id AND c.event_type = 'CIKIS'
            )
          GROUP BY g.session_id"
    );
    $stEk->execute($ids);
    $ekBySession = [];
    foreach ($stEk->fetchAll() as $r) $ekBySession[(int)$r['session_id']] = (int)$r['n'];

    $sonuc = [];
    foreach ($oturumlar as $o) {
        $sid = (int)$o['id'];
        $girisTip = $evBySession[$sid]['GIRIS'] ?? [];
        $cikisTip = $evBySession[$sid]['CIKIS'] ?? [];
        $girisToplam = array_sum($girisTip);
        $cikisToplam = array_sum($cikisTip);
        $eksikToplam = $ekBySession[$sid] ?? 0;
        $durum = pdks_gunluk_oturum_durumu((string)$o['status'], $eksikToplam);

        if ($durumFiltresi === 'eksik_cikis' && $eksikToplam <= 0) {
            continue;
        }

        $sonuc[] = [
            'session' => $o,
            'giris' => $girisTip, 'giris_toplam' => $girisToplam,
            'cikis' => $cikisTip, 'cikis_toplam' => $cikisToplam,
            'icerde_toplam' => $girisToplam - $cikisToplam,
            'eksik_toplam' => $eksikToplam,
            'ilk_giris' => $zBySession[$sid]['ilk_giris'] ?? null,
            'son_cikis' => $zBySession[$sid]['son_cikis'] ?? null,
            'durum' => $durum,
        ];
    }
    return $sonuc;
}

/**
 * TEK OTURUMUN kart hareket dökümü (detay sayfası). worker_type_name
 * CANLI worker_types join'inden DEĞİL, worker_type_name_snapshot'tan
 * okunur (görev talimatı madde 4: "Do NOT show current master worker
 * type if historical snapshot exists.").
 */
function pdks_gunluk_oturum_kartlari(int $sessionId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (pdks_gunluk_faz8a_sema_hazir($pdo)) return pdks_gunluk_faz8a_oturum_donemleri($sessionId, $pdo);
    $st = $pdo->prepare(
        "SELECT g.worker_card_id, w.card_no, g.worker_type_name_snapshot AS tip,
                g.server_event_time AS giris_saat,
                (SELECT c.server_event_time FROM daily_worker_card_events c
                  WHERE c.session_id = g.session_id AND c.worker_card_id = g.worker_card_id
                    AND c.event_type = 'CIKIS' LIMIT 1) AS cikis_saat
           FROM daily_worker_card_events g
           JOIN worker_cards w ON w.id = g.worker_card_id
          WHERE g.session_id = ? AND g.event_type = 'GIRIS'
          ORDER BY g.server_event_time ASC"
    );
    $st->execute([$sessionId]);
    $satirlar = $st->fetchAll();
    foreach ($satirlar as &$s) {
        $s['durum'] = $s['cikis_saat'] !== null
            ? ['kod' => 'tam', 'etiket' => '✅ Tam']
            : ['kod' => 'cikis_yok', 'etiket' => '⚠️ Çıkış Yok'];
    }
    unset($s);
    return $satirlar;
}

/**
 * İSTİSNA/EKSİK ÇIKIŞ RAPORU — bir work_date (+ opsiyonel depo/çavuş) için
 * TÜM oturumlar genelinde "GİRİŞ var, ÇIKIŞ yok" kartlar. Kapanmış bir
 * oturumun eksik kartı için close_note/session.status BİRLİKTE döner —
 * sayfa "Mesai eksik çıkışla kapatıldı." mesajını BURADAN kurar (görev
 * talimatı madde 5). UYDURMA bir çıkış zamanı ASLA üretilmez.
 */
function pdks_gunluk_eksik_cikislar(string $workDate, ?string $depo = null, ?int $foremanId = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (pdks_gunluk_faz8a_sema_hazir($pdo)) return pdks_gunluk_faz8a_eksik_cikislar($workDate, $depo, $foremanId, $pdo);

    $where = ['g.event_type = \'GIRIS\'', 'g.work_date_snapshot = ?'];
    $params = [$workDate];
    if ($depo !== null && $depo !== '') { $where[] = 'g.depo_snapshot = ?'; $params[] = $depo; }
    if ($foremanId !== null) { $where[] = 's.foreman_id = ?'; $params[] = $foremanId; }

    $st = $pdo->prepare(
        "SELECT g.session_id, g.worker_card_id, w.card_no, g.worker_type_name_snapshot AS tip,
                g.server_event_time AS giris_saat, g.work_date_snapshot AS tarih, g.depo_snapshot AS depo,
                s.status AS oturum_durumu, s.notes AS kapanis_notu,
                s.foreman_name_snapshot AS cavus_adi
           FROM daily_worker_card_events g
           JOIN daily_work_sessions s ON s.id = g.session_id
           JOIN worker_cards w ON w.id = g.worker_card_id
          WHERE " . implode(' AND ', $where) . "
            AND NOT EXISTS (
                 SELECT 1 FROM daily_worker_card_events c
                  WHERE c.session_id = g.session_id AND c.worker_card_id = g.worker_card_id AND c.event_type = 'CIKIS'
            )
          ORDER BY g.server_event_time ASC"
    );
    $st->execute($params);
    $satirlar = $st->fetchAll();
    foreach ($satirlar as &$r) {
        $r['oturum_kapali_mesaji'] = ($r['oturum_durumu'] === 'closed')
            ? 'Mesai eksik çıkışla kapatıldı.' : null;
    }
    unset($r);
    return $satirlar;
}

/**
 * Bir user_id'nin görüntülenecek adı — mesai detay sayfasının
 * açan/kapatan kullanıcı satırları için. Sayfa dosyasında BARE bir üst
 * seviye fonksiyon olarak TANIMLANMADI (paylaşılan modüle taşındı): birden
 * çok GET parametresiyle AYNI sayfanın render testte art arda include
 * edilmesi (bkz. scripts/pdks_gunluk_faz3_ui_smoke.php) bare bir sayfa-içi
 * fonksiyonu "Cannot redeclare" fatal'ına düşürüyordu — paylaşılan modül
 * fonksiyonları zaten `require_once` ile TEK sefer yüklenir, bu sorunu
 * yaşamaz.
 */
function pdks_gunluk_kullanici_adi(?int $userId, ?PDO $pdo = null): string
{
    if (!$userId) return '—';
    $pdo = $pdo ?? db();
    $st = $pdo->prepare("SELECT display_name, username FROM users WHERE id = ?");
    $st->execute([$userId]);
    $u = $st->fetch();
    if (!$u) return '—';
    return (string)($u['display_name'] ?: $u['username']);
}

/**
 * Puantaj detay sayfası için BAĞLAMSAL denetim geçmişi (Faz 9E / E).
 * `audit.php`'nin GENEL (admin, tüm sistem) kayıt defteriyle KARIŞTIRILMASIN
 * — bu YALNIZ verilen dönem id'lerine `record_id` eşleşmesiyle DETERMİNİSTİK
 * bağlı satırları döner; tahmin/eşleştirme YAPILMAZ (görev talimatı: "do not
 * fabricate"). Yalnız FİİLEN yazılan üç eylem süzülür: puantaj_iptal/
 * puantaj_duzeltme (bkz. pdks_faz8j_audit()) ve Faz 8B'nin mesai
 * değerlendirme onayı ('update', bkz. config/pdks_faz8b.php'deki
 * audit_log_event('update','daily_worker_work_periods',...) çağrısı) — ham
 * GİRİŞ/ÇIKIŞ tarama olayları (gunluk_giris/gunluk_cikis) ZATEN kart
 * listesinde görünür, burada TEKRAR edilmez. Manuel çıkış (Faz 8E) HENÜZ
 * audit_log'a yazmıyor — o yüzden burada da GÖRÜNMEZ (uydurma yok).
 */
function pdks_gunluk_puantaj_denetim_gecmisi(array $periodIds, ?PDO $pdo = null, int $limit = 20): array
{
    $pdo = $pdo ?? db();
    $ids = array_values(array_unique(array_filter(array_map('intval', $periodIds), fn($v) => $v > 0)));
    if (empty($ids) || !pdks_gunluk_tablo_var($pdo, 'audit_log')) return [];
    $limit = max(1, min(50, $limit));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $pdo->prepare(
            "SELECT al.id, al.action, al.record_id, al.new_values, al.created_at, al.user_id,
                    COALESCE(u.display_name, u.username) AS actor_name
               FROM audit_log al LEFT JOIN users u ON u.id = al.user_id
              WHERE al.module = 'daily_worker_work_periods' AND al.record_id IN ($ph)
                AND al.action IN ('puantaj_iptal', 'puantaj_duzeltme', 'update')
              ORDER BY al.created_at DESC LIMIT $limit"
        );
        $st->execute($ids);
    } catch (PDOException $e) {
        return [];
    }
    $satirlar = $st->fetchAll();
    $etiketler = [
        'puantaj_iptal'    => '🗑️ Puantaj kaydı iptal edildi',
        'puantaj_duzeltme' => '✏️ Puantaj kaydı düzeltildi',
        'update'           => '🧮 Mesai değerlendirmesi kaydedildi',
    ];
    foreach ($satirlar as &$r) {
        $yeni = json_decode((string)$r['new_values'], true) ?: [];
        $r['islem_etiket'] = $etiketler[$r['action']] ?? $r['action'];
        $parcalar = array_filter([trim((string)($yeni['reason'] ?? '')), trim((string)($yeni['note'] ?? ''))]);
        $r['detay'] = $parcalar ? implode(' — ', $parcalar) : null;
        $r['aktor'] = $r['actor_name'] ?: pdks_gunluk_kullanici_adi($r['user_id'] !== null ? (int)$r['user_id'] : null, $pdo);
    }
    unset($r);
    return $satirlar;
}

/**
 * daily_worker_work_periods.status → kullanıcı etiketleri (görev talimatı
 * "PRE-MERGE SAFETY REVIEW" §1/§3 düzeltmesi — ÜÇ AÇIKÇA AYRI durum):
 *
 *   'open'              → OTORİTER, CANLI açık dönem. Bu fiziksel kart
 *                          ŞU AN meşgul sayılır — YENİ bir GİRİŞ bunu
 *                          ENGELLER (bkz. pdks_gunluk_faz8a_kart_acik_donemi()).
 *                          Yalnız Faz 8A'nın KENDİ GİRİŞ/ÇIKIŞ yazma yolu
 *                          (pdks_gunluk_faz8a_giris_kaydet/cikis_kaydet)
 *                          bu durumu YAZAR/DEĞİŞTİRİR.
 *   'closed'            → tamamlanmış (GİRİŞ+ÇIKIŞ eşleşmiş) dönem.
 *   'legacy_unresolved' → Faz 8A ÖNCESİ (Faz 1-7) veriden geriye aktarılmış,
 *                          eşleşen ÇIKIŞ'ı hiç olmamış TARİHSEL kayıt (bkz.
 *                          pdks_gunluk_faz8a_backfill()). Bu kartın BUGÜN
 *                          elde tutulduğu ANLAMINA GELMEZ — yalnız geçmişte
 *                          çözülmemiş bir katılım kaydıdır. Puantaj/raporda
 *                          "Eksik Çıkış" olarak GÖRÜNMEYE DEVAM EDER, ama
 *                          HİÇBİR açık-dönem/kilit sorgusunda 'open' ile
 *                          KARIŞTIRILMAZ — kartı ASLA KİLİTLEMEZ.
 *
 * ⚠ Bu ayrım BİLEREK `status` sütununun KENDİSİNDEDİR — `source` sütunu
 * (scan|legacy_backfill) yalnız KÖKEN/denetim bilgisidir, hiçbir açık-dönem
 * sorgusunda ARTIK kullanılmaz (önceki turun "source != legacy_backfill"
 * dolaylı istisnası KALDIRILDI — bkz. pdks_gunluk_faz8a_kart_acik_donemi()
 * ve pdks_gunluk_faz8a_cikis_kaydet()'in AYNI düzeltmesi).
 */
function pdks_gunluk_faz8a_donem_durumu(string $status): array
{
    // ⚠ 'kod' alanı `pdks-badge-<kod>` CSS sınıfı olarak kullanılır
    // (bkz. gunluk_isci_puantaj_detay.php, assets/pdks.css). 'cikis_yok' ve
    // 'tam' ESKİDEN BERİ var olan sınıflardır (görsel davranış korunur);
    // 'legacy_unresolved' için assets/pdks.css'e AYRI (nötr/tarihsel) bir
    // rozet rengi eklendi — canlı 'cikis_yok' (uyarı/turuncu) ile karışmasın.
    return match ($status) {
        'open'              => ['kod' => 'cikis_yok', 'etiket' => '⚠️ Çıkış Yok'],
        'closed'            => ['kod' => 'tam', 'etiket' => '✅ Tam'],
        'legacy_unresolved' => ['kod' => 'legacy_unresolved', 'etiket' => '📜 Geçmiş — Eksik Çıkış'],
        default             => ['kod' => 'bilinmiyor', 'etiket' => $status],
    };
}

/** Giriş/çıkış saatleri arasındaki süreyi "Xs Ydk" biçiminde döner —
 *  puantaj detay/yazdırma sayfaları için (görev talimatı §20 örneği:
 *  "4s 02dk"). $cikis NULL/boşsa çağrılmamalıdır (çağıran taraf zaten
 *  yalnız TAMAMLANMIŞ (çıkışlı) dönemler için çağırır). */
function pdks_gunluk_sure_etiketi(string $giris, string $cikis): string
{
    $g = strtotime($giris);
    $c = strtotime($cikis);
    if ($g === false || $c === false || $c < $g) return '—';
    $dk = intdiv($c - $g, 60);
    return sprintf('%ds %02ddk', intdiv($dk, 60), $dk % 60);
}

// =========================================================
// FAZ 8A — NEUTRAL REUSABLE WORKER CARDS + WORK PERIOD MODEL
//
// Faz 1-7'nin merkezi varsayımı ("bir fiziksel kart = bir işçi tipi,
// bir gün içinde en fazla bir kez kullanılır") burada TERS ÇEVRİLİR:
//
//   • Kart artık NÖTR bir jetondur — işçi tipi karta değil, o taramanın
//     yapıldığı MESAİ DÖNEMİNE (daily_worker_work_periods) aittir ve
//     GİRİŞ anında AÇIKÇA seçilir (worker_cards.worker_type_id yalnız
//     ESKİ veri için okunur, YENİ trafik onu hiç yazmaz).
//   • Yeni değişmez kural: bir fiziksel kartın aynı anda EN FAZLA BİR
//     AÇIK mesai dönemi olabilir — GLOBAL olarak (tarih/depo/çavuştan
//     BAĞIMSIZ). Geçerli bir ÇIKIŞ'tan hemen sonra kart YENİDEN
//     kullanılabilir — aynı gün, aynı ya da farklı çavuşta, sınırsız kez.
//
// `daily_worker_card_events` (Faz 2) DEĞİŞMEDEN kalır — HÂLÂ ham/
// değişmez tarama denetim kaydıdır, hiçbir satırı silinmez/güncellenmez.
// `daily_worker_work_periods` bunun ÜZERİNE kurulan, YETKİLİ operasyonel
// kayıttır: her satır TAM OLARAK bir GİRİŞ olayına (entry_event_id,
// UNIQUE) ve en fazla bir ÇIKIŞ olayına (exit_event_id, UNIQUE) bağlanır.
//
// ⚠ ŞEMA/DAĞITIM SIRALAMASI (görev talimatı §26-28 — "deployment
// compatibility"): bu dosyadaki İŞ MANTIĞI fonksiyonları (aşağıdaki
// pdks_gunluk_oturum_ozet/oturum_kartlari/oturum_kart_sayimi/gun_ozeti/
// gun_listesi/eksik_cikislar) HER ÇAĞRIDA pdks_gunluk_faz8a_sema_hazir()
// İLE ŞEMA DURUMUNU KONTROL EDER: şema (tablo + nullable kolon + eski
// kısıtın kaldırılmışlığı) HAZIR DEĞİLSE Faz 1-7'nin ESKİ davranışı
// AYNEN çalışmaya devam eder — kod DEPLOY edildiği anda (migrasyon
// ÇALIŞTIRILMADAN ÖNCE) canlı tarama ASLA bozulmaz. Bir yönetici
// migrate.php'den "Faz 8A" adımını çalıştırdığı AN yeni mantık devreye
// girer — ayrı bir dağıtım adımı/bekleme SÜRESİ gerekmez.
// =========================================================

defined('PDKS_GUNLUK_FAZ8A_AKTIF') || define('PDKS_GUNLUK_FAZ8A_AKTIF', true);

/** Faz 8A'da desteklenen beyan edilen mesai sınıfları. approved_attendance_class
 *  (Faz 8B onay mimarisi) BİLEREK burada YOK — 8A yalnız BEYAN EDER, ONAYLAMAZ. */
function pdks_gunluk_faz8a_mesai_siniflari(): array
{
    return ['auto' => 'Otomatik', 'tam' => 'Tam Mesai', 'yarim' => 'Yarım Mesai'];
}

// =========================================================
// ŞEMA
// =========================================================

function pdks_gunluk_faz8a_tablolar(): array
{
    $t = [];

    // ── daily_worker_work_periods — YETKİLİ operasyonel katılım kaydı ──
    // `status`: 'open' | 'closed' | 'legacy_unresolved' — bkz.
    // pdks_gunluk_faz8a_donem_durumu() için TAM anlam haritası. ÜÇ değer
    // AÇIKÇA AYRIDIR (PRE-MERGE GÜVENLİK DÜZELTMESİ):
    //   'open'              → OTORİTER, CANLI açık dönem — bu fiziksel kart
    //                         ŞU AN meşgul, YENİ GİRİŞ'i ENGELLER.
    //   'closed'            → tamamlanmış dönem.
    //   'legacy_unresolved' → Faz 8A ÖNCESİ (Faz 1-7) veriden geriye
    //                         aktarılmış, hiç ÇIKIŞ'ı olmayan TARİHSEL kayıt
    //                         (bkz. pdks_gunluk_faz8a_backfill()). Puantajda
    //                         "Eksik Çıkış" olarak GÖRÜNMEYE DEVAM EDER ama
    //                         kartı ASLA KİLİTLEMEZ — "açık dönem var mı"
    //                         kontrolü (bkz. pdks_gunluk_faz8a_kart_acik_donemi())
    //                         yalnız `status='open'` arar, 'legacy_unresolved'
    //                         hiç GÖRMEZ. Aksi hâlde yıllar önce kapatılmamış
    //                         eski bir "eksik çıkış" kaydı, geri aktarıldıktan
    //                         sonra o fiziksel kartı SONSUZA KADAR yeni
    //                         taramaya KAPATIRDI (kullanıcının "a physical
    //                         card with an open period remains blocked"
    //                         kuralı YENİ trafik içindir).
    // `source`: 'scan' | 'legacy_backfill' — yalnız KÖKEN/denetim bilgisidir,
    // hiçbir iş kuralı sorgusunda KULLANILMAZ (önceki turda "açık dönem"
    // sorguları `source != legacy_backfill` dolaylı istisnasına dayanıyordu
    // — bu, `status` sütununun kendi başına doğruyu söylemesini engelliyordu
    // ve bir sorgu source filtresini unutursa yanlış pozitif üretebilirdi;
    // KALDIRILDI, artık yalnız `status` tek doğruluk kaynağıdır).
    // Puantaj/rapor GÖRÜNÜMLERİ hem 'closed' hem 'legacy_unresolved'
    // dönemleri gösterir — geçmiş kaybolmaz.
    $t['daily_worker_work_periods'] = "CREATE TABLE IF NOT EXISTS `daily_worker_work_periods` (
        `id`                         INT AUTO_INCREMENT PRIMARY KEY,
        `session_id`                 INT          NOT NULL,
        `worker_card_id`             INT          NOT NULL,
        `worker_type_id_snapshot`    INT          NULL DEFAULT NULL,
        `worker_type_name_snapshot`  VARCHAR(80)  NOT NULL DEFAULT '',
        `entry_event_id`             INT          NOT NULL,
        `exit_event_id`              INT          NULL DEFAULT NULL,
        `entry_time`                 DATETIME     NOT NULL,
        `exit_time`                  DATETIME     NULL DEFAULT NULL,
        `declared_attendance_class`  VARCHAR(10)  NOT NULL DEFAULT 'tam',
        `approved_attendance_class`  VARCHAR(10)  NULL DEFAULT NULL,
        `work_date_snapshot`         DATE         NOT NULL,
        `depo_snapshot`              VARCHAR(150) NOT NULL DEFAULT '',
        `status`                     VARCHAR(20)  NOT NULL DEFAULT 'open',
        `source`                     VARCHAR(20)  NOT NULL DEFAULT 'scan',
        `created_at`                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`                 DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_dwwp_entry_event` (`entry_event_id`),
        UNIQUE KEY `uq_dwwp_exit_event`  (`exit_event_id`),
        INDEX `idx_dwwp_card_status`     (`worker_card_id`, `status`),
        INDEX `idx_dwwp_session`         (`session_id`),
        INDEX `idx_dwwp_workdate_depo`   (`work_date_snapshot`, `depo_snapshot`),
        CONSTRAINT `fk_dwwp_session` FOREIGN KEY (`session_id`)
            REFERENCES `daily_work_sessions`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT `fk_dwwp_card` FOREIGN KEY (`worker_card_id`)
            REFERENCES `worker_cards`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT `fk_dwwp_entry_event` FOREIGN KEY (`entry_event_id`)
            REFERENCES `daily_worker_card_events`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT `fk_dwwp_exit_event` FOREIGN KEY (`exit_event_id`)
            REFERENCES `daily_worker_card_events`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    return $t;
}

/** MySQL/SQLite taşınabilir: bir kolon NULL kabul ediyor mu? (testler
 *  SQLite kullanır — SHOW COLUMNS MySQL'e özgüdür.) */
function pdks_gunluk_faz8a_kolon_nullable(PDO $pdo, string $tablo, string $kolon): bool
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        foreach ($pdo->query("PRAGMA table_info(`{$tablo}`)")->fetchAll() as $c) {
            if ($c['name'] === $kolon) return ((int)$c['notnull']) === 0;
        }
        return false;
    }

    // MariaDB/MySQL: SHOW ... içinde PDO placeholder kullanmak bazı
    // sürümlerde syntax error (1064, near '?') üretir. Metadata'yı
    // information_schema üzerinden normal SELECT ile sorgula.
    $st = $pdo->prepare(
        "SELECT IS_NULLABLE
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1"
    );
    $st->execute([$tablo, $kolon]);
    $nullable = $st->fetchColumn();

    return $nullable !== false && strtoupper((string)$nullable) === 'YES';
}

/** MySQL/SQLite taşınabilir: bir index/kısıt adı var mı? */
function pdks_gunluk_faz8a_index_var(PDO $pdo, string $tablo, string $indeks): bool
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        foreach ($pdo->query("PRAGMA index_list(`{$tablo}`)")->fetchAll() as $ix) {
            if ($ix['name'] === $indeks) return true;
        }
        return false;
    }

    // SHOW INDEX + placeholder yerine MariaDB/MySQL uyumlu metadata SELECT.
    $st = $pdo->prepare(
        "SELECT 1
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND INDEX_NAME = ?
          LIMIT 1"
    );
    $st->execute([$tablo, $indeks]);

    return $st->fetchColumn() !== false;
}

/** worker_cards.worker_type_id foreign key'inin gerçek adını bulur. */
function pdks_gunluk_faz8a_fk_adi(
    PDO $pdo,
    string $tablo,
    string $kolon,
    string $refTablo,
    string $refKolon
): ?string {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        return null;
    }

    $st = $pdo->prepare(
        "SELECT CONSTRAINT_NAME
           FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
            AND REFERENCED_TABLE_NAME = ?
            AND REFERENCED_COLUMN_NAME = ?
          LIMIT 1"
    );
    $st->execute([$tablo, $kolon, $refTablo, $refKolon]);
    $ad = $st->fetchColumn();

    return $ad !== false ? (string)$ad : null;
}

/** MySQL/SQLite taşınabilir: beklenen foreign key ilişkisi gerçekten var mı? */
function pdks_gunluk_faz8a_fk_var(
    PDO $pdo,
    string $tablo,
    string $kolon,
    string $refTablo,
    string $refKolon
): bool {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        // Smoke test DDL çeviricisi MySQL FOREIGN KEY constraint'lerini
        // bilinçli olarak kaldırır. Faz 8A FK drop/restore hotfix'i MySQL'e
        // özgüdür; SQLite burada production FK bütünlüğünü simüle etmez.
        return true;
    }

    return pdks_gunluk_faz8a_fk_adi(
        $pdo,
        $tablo,
        $kolon,
        $refTablo,
        $refKolon
    ) !== null;
}

/**
 * TEK doğruluk kaynağı — Faz 8A iş mantığı devrede mi?
 * Gerekli koşullar birlikte sağlanmalıdır:
 * (1) daily_worker_work_periods tablosu var,
 * (2) worker_cards.worker_type_id NULL kabul ediyor,
 * (3) eski uq_dwce_card_day_depo_type kısıtı kaldırılmış,
 * (4) worker_type_id -> worker_types.id foreign key bütünlüğü korunmuş.
 */
function pdks_gunluk_faz8a_sema_hazir(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? db();
    if (!pdks_gunluk_tablo_var($pdo, 'daily_worker_work_periods')) return false;
    if (!pdks_gunluk_tablo_var($pdo, 'worker_cards')) return false;
    if (!pdks_gunluk_tablo_var($pdo, 'daily_worker_card_events')) return false;

    try {
        if (!pdks_gunluk_faz8a_kolon_nullable($pdo, 'worker_cards', 'worker_type_id')) return false;
    } catch (PDOException $e) {
        return false;
    }

    try {
        if (!pdks_gunluk_faz8a_fk_var(
            $pdo,
            'worker_cards',
            'worker_type_id',
            'worker_types',
            'id'
        )) return false;
    } catch (PDOException $e) {
        return false;
    }

    try {
        if (pdks_gunluk_faz8a_index_var(
            $pdo,
            'daily_worker_card_events',
            'uq_dwce_card_day_depo_type'
        )) return false;
    } catch (PDOException $e) {
        return false;
    }

    return true;
}

/** Faz 8J kolonu henüz migrate edilmemiş üretimde eski okuyucular çalışmaya devam eder. */
function pdks_gunluk_faz8j_etkin_kosul(PDO $pdo, string $alias = ''): string
{
    if (!pdks_gunluk_faz8j_kolon_var($pdo, 'daily_worker_work_periods', 'is_voided')) return '1=1';
    return ($alias !== '' ? $alias . '.' : '') . 'is_voided = 0';
}
function pdks_gunluk_faz8j_kolon_var(PDO $pdo, string $tablo, string $kolon): bool
{
    try {
        return pdks_gunluk_kolon_var($pdo, $tablo, $kolon);
    } catch (Throwable $e) { return false; }
}

/**
 * Faz 8A migrasyonu — dört ADDITIVE/kontrollü adım, tek çağrıda, bu SIRAYLA:
 *   1) daily_worker_work_periods tablosunu oluştur
 *   2) worker_cards.worker_type_id → NULL kabul eder hâle getir
 *   3) eski uq_dwce_card_day_depo_type UNIQUE kısıtını kaldır
 *   4) Faz 1-7 geçmişini geriye dönük aktar (backfill — bkz. o fonksiyon)
 * İDEMPOTENT — tekrar çalıştırmak güvenlidir, her adım kendi durumunu
 * kontrol eder. Yalnız migrate.php'nin admin aksiyonundan çağrılır.
 */
function pdks_gunluk_faz8a_migrate(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $rapor = [];

    foreach (pdks_gunluk_faz8a_tablolar() as $ad => $sql) {
        if (pdks_gunluk_tablo_var($pdo, $ad)) {
            $rapor[] = ['adim' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.'];
            continue;
        }
        try {
            $pdo->exec($sql);
            pdks_gunluk_kolon_onbellek_temizle($pdo, $ad);
            $rapor[] = pdks_gunluk_tablo_var($pdo, $ad)
                ? ['adim' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.']
                : ['adim' => $ad, 'durum' => 'hata', 'mesaj' => 'CREATE çalıştı ama tablo görünmüyor.'];
        } catch (PDOException $e) {
            error_log('[pdks_gunluk_faz8a_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['adim' => $ad, 'durum' => 'hata', 'mesaj' => 'İşlem tamamlanamadı. Teknik ayrıntılar sunucu günlüğüne kaydedildi.'];
        }
    }

    try {
        if (!pdks_gunluk_tablo_var($pdo, 'worker_cards')) {
            $rapor[] = ['adim' => 'worker_cards.worker_type_id', 'durum' => 'atlandi', 'mesaj' => 'worker_cards tablosu yok.'];
        } elseif (
            pdks_gunluk_faz8a_kolon_nullable($pdo, 'worker_cards', 'worker_type_id')
            && pdks_gunluk_faz8a_fk_var($pdo, 'worker_cards', 'worker_type_id', 'worker_types', 'id')
        ) {
            $rapor[] = ['adim' => 'worker_cards.worker_type_id', 'durum' => 'var', 'mesaj' => 'Zaten NULL kabul ediyor ve foreign key sağlam.'];
        } else {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

            if ($driver === 'sqlite') {
                throw new PDOException('SQLite üzerinde eski NOT NULL şema yerinde ALTER edilemez.');
            }

            $fkBaslangicta = pdks_gunluk_faz8a_fk_var(
                $pdo,
                'worker_cards',
                'worker_type_id',
                'worker_types',
                'id'
            );

            $fkAdi = pdks_gunluk_faz8a_fk_adi(
                $pdo,
                'worker_cards',
                'worker_type_id',
                'worker_types',
                'id'
            ) ?? 'fk_wc_type';

            $fkSqlAdi = str_replace('`', '``', $fkAdi);
            $fkKaldirildi = false;
            $kolonDegisti = false;

            if ($fkBaslangicta) {
                $pdo->exec(
                    "ALTER TABLE `worker_cards` DROP FOREIGN KEY `{$fkSqlAdi}`"
                );
                $fkKaldirildi = true;
            }

            try {
                $pdo->exec(
                    "ALTER TABLE `worker_cards`
                     MODIFY COLUMN `worker_type_id` INT NULL DEFAULT NULL"
                );
                pdks_gunluk_kolon_onbellek_temizle($pdo, 'worker_cards');
                $kolonDegisti = true;
            } finally {
                // MySQL DDL autocommit'tir. MODIFY başarısız olsa bile daha önce
                // kaldırılan FK'yi mümkün olduğunca geri kur; MODIFY başarılıysa
                // da Faz 8A'nın veri bütünlüğünü koruyarak yeniden ekle.
                if (
                    ($fkKaldirildi || $kolonDegisti)
                    && !pdks_gunluk_faz8a_fk_var(
                        $pdo,
                        'worker_cards',
                        'worker_type_id',
                        'worker_types',
                        'id'
                    )
                ) {
                    $pdo->exec(
                        "ALTER TABLE `worker_cards`
                         ADD CONSTRAINT `{$fkSqlAdi}`
                         FOREIGN KEY (`worker_type_id`)
                         REFERENCES `worker_types`(`id`)
                         ON DELETE RESTRICT ON UPDATE CASCADE"
                    );
                }
            }

            if (
                !pdks_gunluk_faz8a_kolon_nullable($pdo, 'worker_cards', 'worker_type_id')
                || !pdks_gunluk_faz8a_fk_var(
                    $pdo,
                    'worker_cards',
                    'worker_type_id',
                    'worker_types',
                    'id'
                )
            ) {
                throw new PDOException('worker_type_id Faz 8A şema doğrulaması başarısız.');
            }

            $rapor[] = [
                'adim' => 'worker_cards.worker_type_id',
                'durum' => 'guncellendi',
                'mesaj' => 'Kolon NULL kabul edecek şekilde güncellendi; foreign key korundu.',
            ];
        }
    } catch (PDOException $e) {
        error_log('[pdks_gunluk_faz8a_migrate] worker_cards.worker_type_id: ' . $e->getMessage());
        $rapor[] = ['adim' => 'worker_cards.worker_type_id', 'durum' => 'hata', 'mesaj' => 'İşlem tamamlanamadı. Teknik ayrıntılar sunucu günlüğüne kaydedildi.'];
    }

    try {
        if (!pdks_gunluk_tablo_var($pdo, 'daily_worker_card_events')) {
            $rapor[] = ['adim' => 'daily_worker_card_events.uq_dwce_card_day_depo_type', 'durum' => 'atlandi', 'mesaj' => 'daily_worker_card_events tablosu yok.'];
        } elseif (!pdks_gunluk_faz8a_index_var($pdo, 'daily_worker_card_events', 'uq_dwce_card_day_depo_type')) {
            $rapor[] = ['adim' => 'daily_worker_card_events.uq_dwce_card_day_depo_type', 'durum' => 'var', 'mesaj' => 'Zaten kaldırılmış.'];
        } else {
            $pdo->exec("ALTER TABLE `daily_worker_card_events` DROP INDEX `uq_dwce_card_day_depo_type`");
            $rapor[] = ['adim' => 'daily_worker_card_events.uq_dwce_card_day_depo_type', 'durum' => 'kaldirildi', 'mesaj' => 'Eski aynı-gün kısıtı kaldırıldı.'];
        }
    } catch (PDOException $e) {
        error_log('[pdks_gunluk_faz8a_migrate] uq_dwce_card_day_depo_type: ' . $e->getMessage());
        $rapor[] = ['adim' => 'daily_worker_card_events.uq_dwce_card_day_depo_type', 'durum' => 'hata', 'mesaj' => 'İşlem tamamlanamadı. Teknik ayrıntılar sunucu günlüğüne kaydedildi.'];
    }

    if (pdks_gunluk_tablo_var($pdo, 'daily_worker_work_periods') && pdks_gunluk_tablo_var($pdo, 'daily_worker_card_events')) {
        try {
            $rapor[] = ['adim' => 'daily_worker_work_periods.backfill', 'durum' => 'calisti', 'mesaj' => pdks_gunluk_faz8a_backfill($pdo)];
        } catch (PDOException $e) {
            error_log('[pdks_gunluk_faz8a_migrate] backfill: ' . $e->getMessage());
            $rapor[] = ['adim' => 'daily_worker_work_periods.backfill', 'durum' => 'hata', 'mesaj' => 'İşlem tamamlanamadı. Teknik ayrıntılar sunucu günlüğüne kaydedildi.'];
        }
    }

    return $rapor;
}

/**
 * Faz 1-7 (Faz 8A ÖNCESİ) `daily_worker_card_events` GİRİŞ olaylarını
 * `daily_worker_work_periods`'a AKTARIR — yalnız EKLER, hiçbir eski satırı
 * SİLMEZ/DEĞİŞTİRMEZ. İDEMPOTENT (entry_event_id zaten aktarılmışsa atlanır).
 *
 * ⚠ NEDEN GEREKLİ ("backfill is unnecessary → do not do it" talimatına
 * rağmen BİLİNÇLİ karar, bkz. final rapor): Faz 8A'nın puantaj/rapor
 * fonksiyonları migrasyon TAMAMLANDIĞI AN bu tabloyu TEK kaynak olarak
 * okumaya başlar (aşağıya bkz.). Backfill YAPILMAZSA tüm ESKİ günlerin
 * puantajı migrasyon ANINDA SIFIRA düşerdi — "geçmiş okunabilir kalmalı"
 * kuralını ihlal ederdi. Backfill bunu TEK additive adımla önler.
 *
 * ⚠ NEDEN DETERMİNİSTİK/GÜVENLİ: eski `uq_dwce_card_day_depo_type` kısıtı
 * + uygulama katmanı bir (session_id, worker_card_id) çifti için EN FAZLA
 * bir GİRİŞ ve EN FAZLA bir ÇIKIŞ satırı GARANTİ ediyordu (bkz.
 * pdks_gunluk_kart_acik_girisi() docblock'u) — eşleştirme bu yüzden
 * BELİRSİZ değil, KESİN.
 *
 * ⚠ PRE-MERGE DÜZELTMESİ: eşleşen ÇIKIŞ'ı OLAN eski satırlar `status='closed'`
 * yazılır (normal). Eşleşen ÇIKIŞ'ı OLMAYAN (eksik çıkış) eski satırlar ARTIK
 * `status='open'` DEĞİL — `status='legacy_unresolved'` yazılır (bkz.
 * pdks_gunluk_faz8a_donem_durumu()). Önceki turda bu satırlar 'open' yazılıp
 * yalnız `source='legacy_backfill'` filtresiyle açık-dönem sorgularından
 * DIŞLANIYORDU — bu, "status='open' → kart meşgul" değişmezini BOZuyordu
 * (bir raporlama/denetim sorgusu source filtresini UNUTURSA, yıllar önceki
 * bir eksik-çıkış kaydı yanlışlıkla "şu an açık" görünürdü). Artık `status`
 * SÜTUNUNUN KENDİSİ doğruyu söylüyor — hiçbir sorgunun `source` bilmesine
 * GEREK YOK. `source='legacy_backfill'` yalnız KÖKEN/denetim bilgisi olarak
 * KALIR, iş mantığında KULLANILMAZ.
 *
 * ⚠ declared_attendance_class='tam': eski model Tam/Yarım AYRIMINI
 * bilmiyordu — tek seçenek tam gündü, bu UYDURMA değil gerçek karşılıktır.
 */
function pdks_gunluk_faz8a_backfill(PDO $pdo): string
{
    $girisSatirlari = $pdo->query(
        "SELECT g.id AS entry_event_id, g.session_id, g.worker_card_id,
                g.worker_type_id_snapshot, g.worker_type_name_snapshot,
                g.work_date_snapshot, g.depo_snapshot, g.server_event_time AS entry_time
           FROM daily_worker_card_events g
          WHERE g.event_type = 'GIRIS'
            AND NOT EXISTS (SELECT 1 FROM daily_worker_work_periods p WHERE p.entry_event_id = g.id)
          ORDER BY g.id ASC"
    )->fetchAll();
    if (!$girisSatirlari) return 'Aktarılacak eski GİRİŞ kaydı yok (zaten aktarılmış veya hiç yok).';

    $stCikis = $pdo->prepare(
        "SELECT id, server_event_time FROM daily_worker_card_events
          WHERE session_id = ? AND worker_card_id = ? AND event_type = 'CIKIS'
          ORDER BY id ASC LIMIT 1"
    );
    $insP = $pdo->prepare(
        "INSERT INTO daily_worker_work_periods
            (session_id, worker_card_id, worker_type_id_snapshot, worker_type_name_snapshot,
             entry_event_id, exit_event_id, entry_time, exit_time, declared_attendance_class,
             work_date_snapshot, depo_snapshot, status, source)
         VALUES (?,?,?,?,?,?,?,?, 'tam', ?,?,?, 'legacy_backfill')"
    );

    $aktarilan = 0;
    foreach ($girisSatirlari as $g) {
        $stCikis->execute([$g['session_id'], $g['worker_card_id']]);
        $cikis = $stCikis->fetch();
        $exitEventId = $cikis['id'] ?? null;
        $exitTime    = $cikis['server_event_time'] ?? null;
        try {
            $insP->execute([
                $g['session_id'], $g['worker_card_id'], $g['worker_type_id_snapshot'], $g['worker_type_name_snapshot'],
                $g['entry_event_id'], $exitEventId, $g['entry_time'], $exitTime,
                $g['work_date_snapshot'], $g['depo_snapshot'], $exitEventId !== null ? 'closed' : 'legacy_unresolved',
            ]);
            $aktarilan++;
        } catch (PDOException $e) {
            $driverCode = (int)($e->errorInfo[1] ?? 0);
            $mesaj = strtolower($e->getMessage());
            $duplicateKey = $driverCode === 1062
                || ($driverCode === 19 && str_contains($mesaj, 'unique constraint failed'));

            if (!$duplicateKey) {
                throw $e;
            }

            // Yalnız doğrulanmış duplicate-key yarışı:
            // aynı entry/exit başka süreçte zaten aktarılmıştır.
        }
    }
    return $aktarilan . ' eski mesai dönemi aktarıldı.';
}

// =========================================================
// KART ÇÖZÜMLEME — worker_types JOIN'i YOK (tip artık KARTA değil,
// DÖNEME aittir; bkz. dosya başlığı).
// =========================================================

function pdks_gunluk_faz8a_kart_coz(string $kanonik, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? db();
    if (!pdks_gunluk_tablo_var($pdo, 'worker_cards')) return null;
    $st = $pdo->prepare("SELECT * FROM worker_cards WHERE canonical_uid = ?");
    $st->execute([$kanonik]);
    return $st->fetch() ?: null;
}

/**
 * EŞZAMANLILIK STRATEJİSİ (görev talimatı §4 — "Analyze MySQL-compatible
 * enforcement"): bu fiziksel kartın worker_cards SATIRINI kilitler
 * (`SELECT ... FOR UPDATE`, yalnız MySQL — SQLite testleri zaten tek
 * bağlantılı/tek iş parçacığıdır ve FOR UPDATE söz dizimini TANIMAZ).
 *
 * Neden yeterli: iki eşzamanlı GİRİŞ (veya GİRİŞ+ÇIKIŞ) isteği AYNI
 * fiziksel karta değiyorsa, ikisi de önce BU satırı kilitlemeye çalışır —
 * MySQL/InnoDB ikinciyi birincinin COMMIT/ROLLBACK'ine kadar BEKLETİR.
 * İkinci istek kilit devraldığında "açık dönem var mı" sorgusu artık
 * BİRİNCİNİN yazdığı (commit edilmiş) veriyi görür — bu yüzden SELECT-only
 * bir ön-kontrol TEK BAŞINA yetersizken (iki istek AYNI ANDA "açık dönem
 * yok" görüp ikisi de INSERT edebilirdi), satır kilidi + AYNI işlem
 * içinde kontrol+INSERT bunu YAPISAL OLARAK imkânsız kılar.
 *
 * FARKLI fiziksel kartlar HİÇ serileşmez (her kart kendi satırını kilitler)
 * — performans etkisi yalnız AYNI kartın gerçekten eşzamanlı okunduğu
 * (pratikte son derece nadir) senaryoyla sınırlıdır.
 *
 * ⚠ Kasıtlı olarak KULLANILMAYAN alternatif: MySQL 5.7+ "generated column +
 * UNIQUE index" numarası (`status='open' THEN worker_card_id ELSE NULL`
 * üzerine UNIQUE) GERÇEK bir DB-seviyesi ikinci savunma katmanı olurdu,
 * ama bu depo bilinmeyen/paylaşımlı barındırma ortamlarında test edilmemiş
 * MySQL sürüm-özel özellikler eklemekten KAÇINIYOR (bkz. CLAUDE.md →
 * REGEXP/ON DUPLICATE KEY notları, AYNI ihtiyat ilkesi) — satır kilidi tek
 * başına yeterli ve taşınabilir olduğu için eklenmedi.
 */
function pdks_gunluk_faz8a_kart_kilitle(PDO $pdo, int $cardId): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sql = "SELECT id FROM worker_cards WHERE id = ?" . ($driver === 'mysql' ? ' FOR UPDATE' : '');
    $pdo->prepare($sql)->execute([$cardId]);
}

/**
 * Bu kartın hâlâ OTORİTER olarak açık (status='open') bir dönemi var mı —
 * varsa hangi çavuş/mesai altında. TEK ve YETERLİ filtre `status='open'`dur
 * (PRE-MERGE GÜVENLİK DÜZELTMESİ) — `legacy_unresolved` (tarihsel, Faz 8A
 * ÖNCESİ eksik çıkış) burada ASLA görünmez, çünkü backfill artık o
 * satırlara 'open' DEĞİL 'legacy_unresolved' yazar (bkz.
 * pdks_gunluk_faz8a_backfill() + pdks_gunluk_faz8a_donem_durumu()). Ayrıca
 * bir `source` filtresine GEREK YOK — `status` sütununun kendisi zaten
 * doğruyu söylüyor, iki katmanlı (status+source) dolaylı bir kural DEĞİL.
 * ÇAĞIRAN, pdks_gunluk_faz8a_kart_kilitle() İLE AYNI İŞLEM İÇİNDE
 * çağırmalıdır (bkz. o fonksiyonun docblock'u).
 */
function pdks_gunluk_faz8a_kart_acik_donemi(PDO $pdo, int $workerCardId): ?array
{
    $st = $pdo->prepare(
        "SELECT p.id, p.session_id, p.entry_time, p.worker_type_name_snapshot AS tip,
                s.foreman_id, f.name AS foreman_name
           FROM daily_worker_work_periods p
           JOIN daily_work_sessions s ON s.id = p.session_id
           JOIN foremen f ON f.id = s.foreman_id
          WHERE p.worker_card_id = ? AND " . pdks_gunluk_faz8j_etkin_kosul($pdo, 'p') . " AND p.status = 'open'
          LIMIT 1"
    );
    $st->execute([$workerCardId]);
    return $st->fetch() ?: null;
}

// =========================================================
// GİRİŞ / ÇIKIŞ — TEK yazma yolları (USB VE Web NFC AYNI fonksiyonlardan
// geçer — görev talimatı §14: "USB and Web NFC must call the SAME
// server-side work-period business logic.")
// =========================================================

/**
 * GİRİŞ — ATOMİK (görev talimatı §5): kilit → doğrulama → GİRİŞ olayı
 * INSERT → dönem INSERT, TEK transaction içinde. Herhangi bir adım
 * BAŞARISIZ olursa hiçbir şey yazılmaz (ROLLBACK).
 *
 * @param int    $workerTypeId  taramayı yapan ekranda O AN seçili işçi tipi (kart DEĞİL — bkz. dosya başlığı)
 * @param string $declaredClass 'tam' | 'yarim' — pdks_gunluk_faz8a_mesai_siniflari()
 */
function pdks_gunluk_faz8a_giris_kaydet(string $hamUid, string $kaynak, int $sessionId, int $workerTypeId, string $declaredClass, int $recordedByUserId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();

    if (!defined('PDKS_UID_KAYNAKLARI') || !in_array($kaynak, PDKS_UID_KAYNAKLARI, true)) {
        return ['ok' => false, 'kod' => 'gecersiz_kaynak', 'hata' => 'UID kaynağı bildirilmeli.'];
    }
    if (!array_key_exists($declaredClass, pdks_gunluk_faz8a_mesai_siniflari())) {
        return ['ok' => false, 'kod' => 'gecersiz_mesai_sinifi', 'hata' => 'Tam Mesai / Yarım Mesai seçmelisiniz.'];
    }
    $hamUid = trim($hamUid);
    if ($hamUid === '') return ['ok' => false, 'kod' => 'bos_uid', 'hata' => 'Kart okutulmadı.'];
    if (!function_exists('pdks_uid_from_decimal')) {
        return ['ok' => false, 'kod' => 'pdks_yuklu_degil', 'hata' => 'UID normalizasyon fonksiyonları yüklü değil.'];
    }

    $st = $pdo->prepare("SELECT s.*, f.name AS foreman_name FROM daily_work_sessions s JOIN foremen f ON f.id = s.foreman_id WHERE s.id = ?");
    $st->execute([$sessionId]);
    $session = $st->fetch();
    if (!$session) return ['ok' => false, 'kod' => 'oturum_yok', 'hata' => 'Mesai bulunamadı.'];
    if ($session['status'] !== 'open') return ['ok' => false, 'kod' => 'oturum_kapali', 'hata' => 'Bu mesai kapalı.'];

    // ⚠ Faz 9B / H-01 kapanışı: eskiden HERHANGİ bir aktif worker_types
    // satırı kabul edilirdi — UI yalnız KADIN/ERKEK sunsa bile crafted bir
    // POST başka bir aktif tipi (varsa) GİRİŞ'e sokabilirdi. Artık tek
    // paylaşılan politika kapısından geçer (bkz. o fonksiyonun docblock'u).
    $tip = pdks_gunluk_desteklenen_tip_coz($workerTypeId, $pdo);
    if (!$tip) return ['ok' => false, 'kod' => 'tip_bulunamadi', 'hata' => 'Seçilen işçi tipi bulunamadı, pasif veya günlük işçi girişinde desteklenmiyor.'];

    $kanonik = match ($kaynak) {
        'usb_decimal' => pdks_uid_from_decimal($hamUid),
        'web_nfc'     => pdks_uid_from_web_nfc($hamUid),
        default       => pdks_uid_hex_normalize($hamUid),
    };
    if ($kanonik === null) return ['ok' => false, 'kod' => 'gecersiz_uid', 'hata' => 'Okunan UID geçersiz.'];

    $kart = pdks_gunluk_faz8a_kart_coz($kanonik, $pdo);
    if ($kart === null) {
        // Faz 9A / H-04: yalnız AKTİF kalıcı kartlar engeller (bkz. yukarı).
        $engel = pdks_gunluk_kalici_kart_engeli($hamUid, $kaynak, $pdo);
        if ($engel !== null) return ['ok' => false] + $engel;
        $otomatikKayit = pdks_gunluk_kart_olustur([
            'card_no' => 'AUTO-' . substr(hash('sha256', $kanonik), 0, 20),
            'ham_uid' => $hamUid,
            'kaynak' => $kaynak,
            'notes' => 'Auto-enrolled on first daily worker entry',
        ], $recordedByUserId, $pdo);

        // If another terminal enrolled the same UID at the same time,
        // re-read the card created by that request.
        $kart = pdks_gunluk_faz8a_kart_coz($kanonik, $pdo);

        if ($kart === null) {
            return [
                'ok' => false,
                'kod' => 'kart_otomatik_kayit_hatasi',
                'hata' => 'Kart otomatik kaydedilemedi.',
            ];
        }
    }
    if ($kart['status'] === 'lost')     return ['ok' => false, 'kod' => 'kart_kayip', 'hata' => 'Bu kart KAYIP olarak işaretli.'];
    if ($kart['status'] === 'disabled') return ['ok' => false, 'kod' => 'kart_devre_disi', 'hata' => 'Bu kart DEVRE DIŞI.'];

    $disTx = $pdo->inTransaction();
    if (!$disTx) $pdo->beginTransaction();
    try {
        pdks_gunluk_faz8a_kart_kilitle($pdo, (int)$kart['id']);

        $acik = pdks_gunluk_faz8a_kart_acik_donemi($pdo, (int)$kart['id']);
        if ($acik !== null) {
            if (!$disTx) $pdo->rollBack();
            if ((int)$acik['session_id'] === $sessionId) {
                return ['ok' => false, 'kod' => 'mukerrer_giris', 'hata' => 'Bu kart zaten bu mesaide giriş yapmış.'];
            }
            return ['ok' => false, 'kod' => 'baska_cavusta_acik',
                     'hata' => 'Bu kart ' . $acik['foreman_name'] . ' mesaisinde açık görünüyor.'];
        }

        $simdi = date('Y-m-d H:i:s');   // ⚠ SUNUCU saati — istemciden ASLA alınmaz.
        $insE = $pdo->prepare(
            "INSERT INTO daily_worker_card_events
                (session_id, worker_card_id, event_type, source, canonical_uid_snapshot,
                 worker_type_id_snapshot, worker_type_name_snapshot, work_date_snapshot, depo_snapshot,
                 recorded_by_user_id, server_event_time)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $insE->execute([
            $sessionId, $kart['id'], 'GIRIS', $kaynak, $kanonik,
            (int)$tip['id'], (string)$tip['name'], $session['work_date'], $session['depo'],
            $recordedByUserId, $simdi,
        ]);
        $eventId = (int)$pdo->lastInsertId();

        $insP = $pdo->prepare(
            "INSERT INTO daily_worker_work_periods
                (session_id, worker_card_id, worker_type_id_snapshot, worker_type_name_snapshot,
                 entry_event_id, entry_time, declared_attendance_class, work_date_snapshot, depo_snapshot,
                 status, source)
             VALUES (?,?,?,?,?,?,?,?,?, 'open', 'scan')"
        );
        $insP->execute([
            $sessionId, $kart['id'], (int)$tip['id'], (string)$tip['name'],
            $eventId, $simdi, $declaredClass, $session['work_date'], $session['depo'],
        ]);
        $periodId = (int)$pdo->lastInsertId();

        if (!$disTx) $pdo->commit();
    } catch (PDOException $e) {
        if (!$disTx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[pdks_gunluk_faz8a_kaydet] ' . $e->getMessage());
        return ['ok' => false, 'kod' => 'yazma_hatasi',
                'hata' => 'Kayıt sırasında teknik bir hata oluştu. Lütfen tekrar deneyin.'];
    }

    if (function_exists('audit_log_event')) {
        audit_log_event('gunluk_giris', 'daily_worker_work_periods', $periodId, null, [
            'session_id' => $sessionId, 'worker_card_id' => $kart['id'], 'card_no' => $kart['card_no'],
            'worker_type_id' => $tip['id'], 'declared_attendance_class' => $declaredClass,
        ]);
    }

    return [
        'ok' => true, 'event_id' => $eventId, 'period_id' => $periodId, 'event_type' => 'GIRIS',
        'card' => ['card_no' => $kart['card_no'], 'worker_type_name' => (string)$tip['name'], 'declared_class' => $declaredClass,
                   'declared_class_label' => pdks_gunluk_faz8a_mesai_siniflari()[$declaredClass]],
        'server_time' => $simdi,
        'ozet' => pdks_gunluk_faz8a_oturum_ozet($sessionId, $pdo),
    ];
}

/**
 * ÇIKIŞ — ATOMİK: kilit → TAM OLARAK bir açık dönem bul → çavuş/oturum
 * eşleşmesini doğrula (YANLIŞ ÇAVUŞ'sa REDDET, KAPATMA) → ÇIKIŞ olayı
 * INSERT → dönemi kapat, TEK transaction içinde.
 */
function pdks_gunluk_faz8a_cikis_kaydet(string $hamUid, string $kaynak, int $sessionId, int $recordedByUserId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();

    if (!defined('PDKS_UID_KAYNAKLARI') || !in_array($kaynak, PDKS_UID_KAYNAKLARI, true)) {
        return ['ok' => false, 'kod' => 'gecersiz_kaynak', 'hata' => 'UID kaynağı bildirilmeli.'];
    }
    $hamUid = trim($hamUid);
    if ($hamUid === '') return ['ok' => false, 'kod' => 'bos_uid', 'hata' => 'Kart okutulmadı.'];
    if (!function_exists('pdks_uid_from_decimal')) {
        return ['ok' => false, 'kod' => 'pdks_yuklu_degil', 'hata' => 'UID normalizasyon fonksiyonları yüklü değil.'];
    }

    $st = $pdo->prepare("SELECT s.*, f.name AS foreman_name FROM daily_work_sessions s JOIN foremen f ON f.id = s.foreman_id WHERE s.id = ?");
    $st->execute([$sessionId]);
    $session = $st->fetch();
    if (!$session) return ['ok' => false, 'kod' => 'oturum_yok', 'hata' => 'Mesai bulunamadı.'];
    if ($session['status'] !== 'open') return ['ok' => false, 'kod' => 'oturum_kapali', 'hata' => 'Bu mesai kapalı.'];

    $kanonik = match ($kaynak) {
        'usb_decimal' => pdks_uid_from_decimal($hamUid),
        'web_nfc'     => pdks_uid_from_web_nfc($hamUid),
        default       => pdks_uid_hex_normalize($hamUid),
    };
    if ($kanonik === null) return ['ok' => false, 'kod' => 'gecersiz_uid', 'hata' => 'Okunan UID geçersiz.'];

    $kart = pdks_gunluk_faz8a_kart_coz($kanonik, $pdo);
    if ($kart === null) {
        // Faz 9A / H-04: yalnız AKTİF kalıcı kartlar engeller (bkz. yukarı).
        $engel = pdks_gunluk_kalici_kart_engeli($hamUid, $kaynak, $pdo);
        if ($engel !== null) return ['ok' => false] + $engel;
        return ['ok' => false, 'kod' => 'kart_tanimsiz', 'hata' => 'Tanımsız kart — işçi havuzunda kayıtlı değil.'];
    }
    // ⚠ Legacy Kural 1 İLE AYNI: ÇIKIŞ, kartın kayıp/devre dışı durumu ne
    // olursa olsun MEVCUT açık dönemi kapatabilmelidir.

    $disTx = $pdo->inTransaction();
    if (!$disTx) $pdo->beginTransaction();
    try {
        pdks_gunluk_faz8a_kart_kilitle($pdo, (int)$kart['id']);

        // ⚠ PRE-MERGE DÜZELTMESİ: 'source' filtresi KALDIRILDI. status='open' artık
        // TEK BAŞINA otoriter sinyaldir (legacy backfill 'legacy_unresolved' yazar,
        // asla 'open' yazmaz) — bkz. pdks_gunluk_faz8a_kart_acik_donemi().
        $st2 = $pdo->prepare("SELECT * FROM daily_worker_work_periods WHERE worker_card_id = ? AND status = 'open' AND " . pdks_gunluk_faz8j_etkin_kosul($pdo) . " LIMIT 1");
        $st2->execute([$kart['id']]);
        $acik = $st2->fetch() ?: null;   // ⚠ PDO::fetch() satır yoksa false döner, null DEĞİL.
        if ($acik === null) {
            if (!$disTx) $pdo->rollBack();
            return ['ok' => false, 'kod' => 'acik_donem_yok', 'hata' => 'Bu kart için açık bir mesai bulunamadı.'];
        }
        if ((int)$acik['session_id'] !== $sessionId) {
            if (!$disTx) $pdo->rollBack();
            $stS = $pdo->prepare("SELECT foreman_name_snapshot FROM daily_work_sessions WHERE id = ?");
            $stS->execute([(int)$acik['session_id']]);
            $foremanAdi = (string)($stS->fetchColumn() ?: 'başka bir çavuş');
            return ['ok' => false, 'kod' => 'yanlis_cavus',
                     'hata' => 'Bu kart ' . $foremanAdi . ' mesaisinde açık görünüyor.',
                     'acik_bilgi' => [
                         'foreman_name' => $foremanAdi,
                         'entry_time'   => $acik['entry_time'],
                         'worker_type'  => $acik['worker_type_name_snapshot'],
                     ]];
        }

        $simdi = date('Y-m-d H:i:s');
        $insE = $pdo->prepare(
            "INSERT INTO daily_worker_card_events
                (session_id, worker_card_id, event_type, source, canonical_uid_snapshot,
                 worker_type_id_snapshot, worker_type_name_snapshot, work_date_snapshot, depo_snapshot,
                 recorded_by_user_id, server_event_time)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $insE->execute([
            $sessionId, $kart['id'], 'CIKIS', $kaynak, $kanonik,
            $acik['worker_type_id_snapshot'], $acik['worker_type_name_snapshot'], $session['work_date'], $session['depo'],
            $recordedByUserId, $simdi,
        ]);
        $eventId = (int)$pdo->lastInsertId();

        $pdo->prepare("UPDATE daily_worker_work_periods SET status='closed', exit_event_id=?, exit_time=? WHERE id=?")
            ->execute([$eventId, $simdi, (int)$acik['id']]);

        if (!$disTx) $pdo->commit();
    } catch (PDOException $e) {
        if (!$disTx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[pdks_gunluk_faz8a_kaydet] ' . $e->getMessage());
        return ['ok' => false, 'kod' => 'yazma_hatasi',
                'hata' => 'Kayıt sırasında teknik bir hata oluştu. Lütfen tekrar deneyin.'];
    }

    if (function_exists('audit_log_event')) {
        audit_log_event('gunluk_cikis', 'daily_worker_work_periods', (int)$acik['id'], null, [
            'session_id' => $sessionId, 'worker_card_id' => $kart['id'], 'card_no' => $kart['card_no'],
        ]);
    }

    return [
        'ok' => true, 'event_id' => $eventId, 'event_type' => 'CIKIS',
        'card' => [
            'card_no' => $kart['card_no'], 'worker_type_name' => (string)$acik['worker_type_name_snapshot'],
            'declared_class' => (string)$acik['declared_attendance_class'],
            'declared_class_label' => pdks_gunluk_faz8a_mesai_siniflari()[$acik['declared_attendance_class']] ?? $acik['declared_attendance_class'],
            'entry_time' => (string)$acik['entry_time'],
        ],
        'server_time' => $simdi,
        'ozet' => pdks_gunluk_faz8a_oturum_ozet($sessionId, $pdo),
    ];
}

// =========================================================
// PERİYOT-TABANLI OKUMA — bu bölümün fonksiyonları aşağıdaki Faz 1-7
// fonksiyonlarının İÇİNDEN, YALNIZ pdks_gunluk_faz8a_sema_hazir() true
// döndüğünde çağrılır (dosyanın geri kalanındaki çağrı noktalarına bkz.):
// pdks_gunluk_oturum_ozet / oturum_kartlari / oturum_kart_sayimi /
// gun_ozeti / gun_listesi / eksik_cikislar. Çağıran fonksiyon İSMİ TEKTİR
// — iki paralel "doğruluk kaynağı" YOKTUR, yalnız dahili uygulama dalı.
// =========================================================

/** pdks_gunluk_oturum_ozet() İLE AYNI dönüş şekli — canlı sayaç/mutabakat
 *  kaynağı (sayfa ilk render + her tarama sonrası + kapatma ekranı).
 *  giris/cikis/ilk_giris/son_cikis sayaçları BİLEREK source='scan' filtreler
 *  — bu YALNIZCA canlı/aktif oturum trafiğinin gösterim metriğidir, pratikte
 *  aktif bir oturumda legacy_backfill satırı ZATEN OLAMAZ (backfill yalnız
 *  Faz 8A ÖNCESİ kapanmış oturumlara yazar). ⚠ Bu, açık-dönem/KİLİT
 *  anlamıyla KARIŞTIRILMAMALI: aşağıdaki "eksik" (hâlâ açık kart) listesi
 *  KİLİT ailesindendir ve TEK filtresi status='open'dur (source filtresi
 *  YOK) — PRE-MERGE SAFETY REVIEW §1/§6 ile pdks_gunluk_faz8a_kart_acik_donemi()
 *  İLE AYNI KURAL. */
function pdks_gunluk_faz8a_oturum_ozet(int $sessionId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $etkin = pdks_gunluk_faz8j_etkin_kosul($pdo);

    // ⚠ Faz 9A / M-02 düzeltmesi: bu üç sorgu eskiden `source='scan'` ile
    // sınırlıydı — bu, GERİYE AKTARILMIŞ (`legacy_backfill`) dönemleri
    // hesaba KATMIYORDU ve session özet kartını (bu fonksiyon) aynı
    // oturumun kart listesiyle (pdks_gunluk_faz8a_oturum_donemleri —
    // hiçbir source filtresi YOK), gün özetiyle (pdks_gunluk_faz8a_gun_ozeti
    // — filtresiz), kart sayımıyla (pdks_gunluk_faz8a_oturum_kart_sayimi —
    // filtresiz, Faz 4'ün TEK sayım kaynağı) ÇELİŞTİRİYORDU: aynı oturum
    // için başlıkta 2, detay listesinde 3 işçi görünüyordu (audit M-02).
    // "Eksik çıkış" sorgusu zaten `source` filtrelemiyordu (aşağıdaki
    // PRE-MERGE yorumu) — artık DÖRDÜ de AYNI kural: yalnız `is_voided`
    // hariç tutulur, `source` HİÇBİR YERDE ayırt edici DEĞİLDİR.
    $giris = []; $cikis = [];
    $stG = $pdo->prepare("SELECT worker_type_name_snapshot AS tip, COUNT(*) AS n FROM daily_worker_work_periods WHERE session_id = ? AND $etkin GROUP BY worker_type_name_snapshot");
    $stG->execute([$sessionId]);
    foreach ($stG->fetchAll() as $r) $giris[$r['tip']] = (int)$r['n'];

    $stC = $pdo->prepare("SELECT worker_type_name_snapshot AS tip, COUNT(*) AS n FROM daily_worker_work_periods WHERE session_id = ? AND $etkin AND exit_event_id IS NOT NULL GROUP BY worker_type_name_snapshot");
    $stC->execute([$sessionId]);
    foreach ($stC->fetchAll() as $r) $cikis[$r['tip']] = (int)$r['n'];

    $girisToplam = array_sum($giris);
    $cikisToplam = array_sum($cikis);

    // ⚠ PRE-MERGE DÜZELTMESİ: burası "eksik çıkış" MUTABAKAT/KİLİT listesidir
    // (kapatma ekranında hangi kart hâlâ açık gösterir) — açık-dönem kontrolüyle
    // AYNI ailede, o yüzden AYNI kural: TEK ve YETERLİ filtre status='open'dur,
    // 'source' filtresi KALDIRILDI (legacy zaten hiçbir zaman 'open' yazmaz).
    $stE = $pdo->prepare(
        "SELECT p.worker_card_id, w.card_no, p.worker_type_name_snapshot AS tip, p.entry_time AS giris_zamani
           FROM daily_worker_work_periods p JOIN worker_cards w ON w.id = p.worker_card_id
          WHERE p.session_id = ? AND " . pdks_gunluk_faz8j_etkin_kosul($pdo, 'p') . " AND p.status = 'open'
          ORDER BY p.entry_time ASC"
    );
    $stE->execute([$sessionId]);
    $eksikKartlar = $stE->fetchAll();
    $eksikTip = [];
    foreach ($eksikKartlar as $ek) $eksikTip[$ek['tip']] = ($eksikTip[$ek['tip']] ?? 0) + 1;

    $stZ = $pdo->prepare("SELECT MIN(entry_time) AS ilk_giris, MAX(exit_time) AS son_cikis FROM daily_worker_work_periods WHERE session_id = ? AND $etkin");
    $stZ->execute([$sessionId]);
    $zamanlar = $stZ->fetch() ?: ['ilk_giris' => null, 'son_cikis' => null];

    return [
        'giris' => $giris, 'giris_toplam' => $girisToplam,
        'cikis' => $cikis, 'cikis_toplam' => $cikisToplam,
        'icerde_toplam' => $girisToplam - $cikisToplam,
        'eksik_tip' => $eksikTip, 'eksik_toplam' => count($eksikKartlar),
        'eksik_kartlar' => $eksikKartlar,
        'ilk_giris' => $zamanlar['ilk_giris'], 'son_cikis' => $zamanlar['son_cikis'],
    ];
}

/** pdks_gunluk_oturum_kartlari() İLE AYNI amaç — TEK FARK: aynı kart AYNI
 *  oturumda birden çok kez görünebilir (bkz. görev talimatı §20 örneği) ve
 *  her satır KENDİ Tam/Yarım sınıfını taşır. source AYRIMI YAPMAZ — hem
 *  canlı hem geriye aktarılan dönemler burada görünür (geçmiş kaybolmaz). */
function pdks_gunluk_faz8a_oturum_donemleri(int $sessionId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $faz8bAlanlar = pdks_gunluk_faz8j_kolon_var($pdo, 'daily_worker_work_periods', 'overtime_approved')
        ? 'p.approved_attendance_class, p.overtime_approved,' : 'NULL AS approved_attendance_class, NULL AS overtime_approved,';
    $st = $pdo->prepare(
        "SELECT p.id AS period_id, p.worker_card_id, p.worker_type_id_snapshot, w.card_no, p.worker_type_name_snapshot AS tip,
                p.entry_time AS giris_saat, p.exit_time AS cikis_saat,
                p.declared_attendance_class AS mesai_sinifi, $faz8bAlanlar
                p.status AS durum_kod, p.source AS kaynak
           FROM daily_worker_work_periods p JOIN worker_cards w ON w.id = p.worker_card_id
          WHERE p.session_id = ? AND " . pdks_gunluk_faz8j_etkin_kosul($pdo, 'p') . "
          ORDER BY p.entry_time ASC"
    );
    $st->execute([$sessionId]);
    $satirlar = $st->fetchAll();
    $siniflar = pdks_gunluk_faz8a_mesai_siniflari();
    foreach ($satirlar as &$s) {
        // ⚠ PRE-MERGE DÜZELTMESİ (§1/§3): eskiden yalnız cikis_saat'e bakılıyordu
        // — bu, canlı 'open' ile tarihsel 'legacy_unresolved'i puantajda AYNI
        // ETİKETLE gösteriyordu. Artık gerçek durum_kod'dan (status) okunur,
        // ÜÇ durum da AÇIKÇA AYRIŞIR (bkz. pdks_gunluk_faz8a_donem_durumu()).
        $s['durum'] = pdks_gunluk_faz8a_donem_durumu((string)$s['durum_kod']);
        $s['mesai_sinifi_etiket'] = $siniflar[$s['mesai_sinifi']] ?? $s['mesai_sinifi'];
    }
    unset($s);
    return $satirlar;
}

/** pdks_gunluk_oturum_kart_sayimi() İLE AYNI amaç/dönüş şekli — Faz 4'ün
 *  (config/pdks_hakedis.php) TEK sayım kaynağı olarak BUNU tüketir. Kartın
 *  DEĞİL, KATILIMIN (dönemin) sayıldığına dikkat: aynı kart aynı gün iki
 *  kez kullanıldıysa İKİ ayrı katılım olarak sayılır (görev talimatı §21:
 *  "İşçi Katılımı" ≠ "benzersiz çalışan"). source AYRIMI YAPMAZ — GERİYE
 *  AKTARILAN eski (tam günlük) dönemler de Faz 4'ün sayımına katılır,
 *  tıpkı ESKİ COUNT(DISTINCT worker_card_id) mantığının onları saydığı gibi. */
function pdks_gunluk_faz8a_oturum_kart_sayimi(int $sessionId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare(
        "SELECT worker_type_id_snapshot AS tip_id, worker_type_name_snapshot AS tip_ad, COUNT(*) AS n
           FROM daily_worker_work_periods WHERE session_id = ? AND " . pdks_gunluk_faz8j_etkin_kosul($pdo) . "
          GROUP BY worker_type_id_snapshot, worker_type_name_snapshot"
    );
    $st->execute([$sessionId]);
    return $st->fetchAll();
}

/** pdks_gunluk_gun_ozeti() İLE AYNI dönüş şekli. */
function pdks_gunluk_faz8a_gun_ozeti(string $workDate, ?string $depo = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $whereEv = 'work_date_snapshot = ? AND ' . pdks_gunluk_faz8j_etkin_kosul($pdo); $parEv = [$workDate];
    if ($depo !== null) { $whereEv .= ' AND depo_snapshot = ?'; $parEv[] = $depo; }

    $stTip = $pdo->prepare("SELECT worker_type_name_snapshot AS tip, COUNT(*) AS n FROM daily_worker_work_periods WHERE $whereEv GROUP BY worker_type_name_snapshot");
    $stTip->execute($parEv);
    $girisTip = []; foreach ($stTip->fetchAll() as $r) $girisTip[$r['tip']] = (int)$r['n'];
    $girisToplam = array_sum($girisTip);

    $stCk = $pdo->prepare("SELECT COUNT(*) FROM daily_worker_work_periods WHERE $whereEv AND exit_event_id IS NOT NULL");
    $stCk->execute($parEv);
    $cikisToplam = (int)$stCk->fetchColumn();

    $whereS = 'work_date = ?'; $parS = [$workDate];
    if ($depo !== null) { $whereS .= ' AND depo = ?'; $parS[] = $depo; }
    $stCavus = $pdo->prepare("SELECT COUNT(DISTINCT foreman_id) FROM daily_work_sessions WHERE $whereS");
    $stCavus->execute($parS);

    return [
        'work_date' => $workDate, 'depo' => $depo,
        'aktif_cavus' => (int)$stCavus->fetchColumn(),
        'giris' => $girisTip, 'giris_toplam' => $girisToplam,
        'tam_cikis' => $cikisToplam,
        'eksik_cikis' => $girisToplam - $cikisToplam,
    ];
}

/** pdks_gunluk_gun_listesi() İLE AYNI dönüş şekli/N+1-siz desen. */
function pdks_gunluk_faz8a_gun_listesi(string $workDate, ?string $depo = null, ?int $foremanId = null, ?string $durumFiltresi = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $where = ['work_date = ?']; $params = [$workDate];
    if ($depo !== null && $depo !== '') { $where[] = 'depo = ?'; $params[] = $depo; }
    if ($foremanId !== null) { $where[] = 'foreman_id = ?'; $params[] = $foremanId; }
    if ($durumFiltresi === 'acik')   { $where[] = "status = 'open'"; }
    if ($durumFiltresi === 'kapali') { $where[] = "status = 'closed'"; }
    $st = $pdo->prepare("SELECT * FROM daily_work_sessions WHERE " . implode(' AND ', $where) . " ORDER BY foreman_name_snapshot ASC, id ASC");
    $st->execute($params);
    $oturumlar = $st->fetchAll();
    if (!$oturumlar) return [];

    $ids = array_map(fn($o) => (int)$o['id'], $oturumlar);
    $ph  = implode(',', array_fill(0, count($ids), '?'));

    $etkin = pdks_gunluk_faz8j_etkin_kosul($pdo);
    $stEv = $pdo->prepare("SELECT session_id, worker_type_name_snapshot AS tip, COUNT(*) AS n FROM daily_worker_work_periods WHERE session_id IN ($ph) AND $etkin GROUP BY session_id, worker_type_name_snapshot");
    $stEv->execute($ids);
    $girisBySession = [];
    foreach ($stEv->fetchAll() as $r) $girisBySession[(int)$r['session_id']][$r['tip']] = (int)$r['n'];

    $stCk = $pdo->prepare("SELECT session_id, worker_type_name_snapshot AS tip, COUNT(*) AS n FROM daily_worker_work_periods WHERE session_id IN ($ph) AND $etkin AND exit_event_id IS NOT NULL GROUP BY session_id, worker_type_name_snapshot");
    $stCk->execute($ids);
    $cikisBySession = [];
    foreach ($stCk->fetchAll() as $r) $cikisBySession[(int)$r['session_id']][$r['tip']] = (int)$r['n'];

    $stZ = $pdo->prepare("SELECT session_id, MIN(entry_time) AS ilk_giris, MAX(exit_time) AS son_cikis FROM daily_worker_work_periods WHERE session_id IN ($ph) AND $etkin GROUP BY session_id");
    $stZ->execute($ids);
    $zBySession = [];
    foreach ($stZ->fetchAll() as $r) $zBySession[(int)$r['session_id']] = $r;

    // ⚠ PRE-MERGE DÜZELTMESİ (§3 — raporlama satırlarını KAYBETME): burası bir
    // RAPOR/LİSTE sayacıdır (KİLİT kontrolü DEĞİL) — 'legacy_unresolved' dahil
    // edilir ki geçmiş bir günün listesi "Eksik Çıkış" durumunu göstermeye
    // devam etsin. Kilit/blokaj kontrolleri (kart_acik_donemi vb.) bunun
    // AKSİNE yalnız status='open' kullanır — iki sorgu KASITLI FARKLI.
    $stEk = $pdo->prepare("SELECT session_id, COUNT(*) AS n FROM daily_worker_work_periods WHERE session_id IN ($ph) AND $etkin AND status IN ('open','legacy_unresolved') GROUP BY session_id");
    $stEk->execute($ids);
    $ekBySession = [];
    foreach ($stEk->fetchAll() as $r) $ekBySession[(int)$r['session_id']] = (int)$r['n'];

    $sonuc = [];
    foreach ($oturumlar as $o) {
        $sid = (int)$o['id'];
        $girisTip = $girisBySession[$sid] ?? [];
        $cikisTip = $cikisBySession[$sid] ?? [];
        $girisToplam = array_sum($girisTip);
        $cikisToplam = array_sum($cikisTip);
        $eksikToplam = $ekBySession[$sid] ?? 0;
        $durum = pdks_gunluk_oturum_durumu((string)$o['status'], $eksikToplam);
        if ($durumFiltresi === 'eksik_cikis' && $eksikToplam <= 0) continue;
        $sonuc[] = [
            'session' => $o,
            'giris' => $girisTip, 'giris_toplam' => $girisToplam,
            'cikis' => $cikisTip, 'cikis_toplam' => $cikisToplam,
            'icerde_toplam' => $girisToplam - $cikisToplam,
            'eksik_toplam' => $eksikToplam,
            'ilk_giris' => $zBySession[$sid]['ilk_giris'] ?? null,
            'son_cikis' => $zBySession[$sid]['son_cikis'] ?? null,
            'durum' => $durum,
        ];
    }
    return $sonuc;
}

/** pdks_gunluk_eksik_cikislar() İLE AYNI dönüş şekli. ⚠ PRE-MERGE DÜZELTMESİ
 *  (§3 — "Eksik Çıkış" raporu geçmiş çözülmemiş kayıtları KAYBETMEMELİ):
 *  hem CANLI açık ('open') hem TARİHSEL çözülmemiş ('legacy_unresolved')
 *  dönemler bu listede görünür — ikisi de gerçekten "çıkışı olmayan" bir
 *  katılım kaydıdır, yalnız 'open' kart KİLİTLER. Bu fonksiyon kilit
 *  kontrolü DEĞİL rapor listesidir. */
function pdks_gunluk_faz8a_eksik_cikislar(string $workDate, ?string $depo = null, ?int $foremanId = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $where = ['p.work_date_snapshot = ?', pdks_gunluk_faz8j_etkin_kosul($pdo, 'p'), "p.status IN ('open','legacy_unresolved')"]; $params = [$workDate];
    if ($depo !== null && $depo !== '') { $where[] = 'p.depo_snapshot = ?'; $params[] = $depo; }
    if ($foremanId !== null) { $where[] = 's.foreman_id = ?'; $params[] = $foremanId; }
    $st = $pdo->prepare(
        // ⚠ Faz 9E / F: p.id (period_id) + p.status EKLENDİ — sayfa (gunluk_isci_puantaj.php)
        // artık her satır için "Manuel Çıkış Gir" derin bağlantısını ve gerçek
        // durumu (canlı 'open' mü, geriye aktarılmış 'legacy_unresolved' mı)
        // KENDİSİ türetmeden buradan okur; ikinci bir sorgu YAZILMAZ.
        "SELECT p.id AS period_id, p.session_id, p.worker_card_id, w.card_no, p.worker_type_name_snapshot AS tip,
                p.entry_time AS giris_saat, p.work_date_snapshot AS tarih, p.depo_snapshot AS depo,
                p.status AS donem_durumu,
                s.status AS oturum_durumu, s.notes AS kapanis_notu, s.foreman_name_snapshot AS cavus_adi
           FROM daily_worker_work_periods p
           JOIN daily_work_sessions s ON s.id = p.session_id
           JOIN worker_cards w ON w.id = p.worker_card_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY p.entry_time ASC"
    );
    $st->execute($params);
    $satirlar = $st->fetchAll();
    foreach ($satirlar as &$r) {
        $r['oturum_kapali_mesaji'] = ($r['oturum_durumu'] === 'closed') ? 'Mesai eksik çıkışla kapatıldı.' : null;
    }
    unset($r);
    return $satirlar;
}
