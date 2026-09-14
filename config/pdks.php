<?php
// =========================================================
// config/pdks.php — PDKS (Personel Devam Kontrol Sistemi) ÇEKİRDEĞİ
//
// FAZ 1 KAPSAMI: şema + UID normalizasyonu + kart/personel alan mantığı + yetki kapısı.
// Giriş/çıkış hareketleri, API, Android istemci ve arayüz ekranları FAZ 2'dedir.
//
// Referans belgeler:
//   docs/PDKS_NFC_YOL_HARITASI.md        (mimari karar kaydı)
//   docs/PDKS_NFC_FAZ0_DOGRULAMA.md      (ölçüm ve kanıtlar)
//   docs/PDKS_FAZ1_SEMA.md               (bu dosyadaki şemanın belgesi)
//
// ⚠ BU DOSYA config/db.php VEYA config/helpers.php TARAFINDAN YÜKLENMEZ.
//    Yalnız PDKS kodu require eder. Sebep: buradaki bir hata uygulamanın
//    geri kalanını (yükleme, kantar, hesap, beyan) ASLA etkilememelidir.
//    pdks_migrate() de kendiliğinden çalışmaz — açıkça çağrılır.
// =========================================================

declare(strict_types=1);

// ── Yapılandırma ──────────────────────────────────────────
// defined() koruması: config/local.php (config/db.php'nin EN BAŞINDA yüklenir)
// bu sabitleri sunucuya özel değerlerle ezebilir — kod değişikliği gerekmez.
// Aynı desen hesap_config.php'de kullanılıyor.

/** Modül ana şalteri. false iken PDKS sayfaları/uçları kapalıdır. */
defined('PDKS_AKTIF')        || define('PDKS_AKTIF', true);

/** Mükerrer okuma bekleme süresi (saniye) — Faz 2'de kullanılır. Karar #5. */
defined('PDKS_COOLDOWN_SN')  || define('PDKS_COOLDOWN_SN', 20);

/** Personel fotoğraflarının dizini (Faz 1B/2). */
defined('PDKS_FOTO_DIR')     || define('PDKS_FOTO_DIR', __DIR__ . '/../uploads/personel/');

/** Desteklenen UID uzunlukları, bayt (ISO/IEC 14443-3: tek/çift/üçlü kaskad). */
const PDKS_UID_BAYT = [4, 7, 10];

/** Geçerli UID kaynakları — otomatik tespit YASAK (karar #10). */
const PDKS_UID_KAYNAKLARI = ['usb_decimal', 'nfc_hex'];

/** Kart yaşam döngüsü durumları. */
function pdks_kart_durumlari(): array
{
    return [
        'aktif'         => 'Aktif',
        'iptal'         => 'İptal Edildi',
        'kayip'         => 'Kayıp',
        'degistirildi'  => 'Değiştirildi',
        'suresi_doldu'  => 'Süresi Doldu',
        'pasif'         => 'Pasif',
    ];
}

/** Personel durumları. */
function pdks_personel_durumlari(): array
{
    return ['aktif' => 'Aktif', 'pasif' => 'Pasif', 'ayrildi' => 'Ayrıldı'];
}

/** Yalnız bu durumdaki kart bir hareketi tetikleyebilir (Faz 2). */
function pdks_kart_aktif_mi(?string $durum): bool { return $durum === 'aktif'; }

// =========================================================
// UID NORMALİZASYONU
//
// ⚠ FAZ 1 DÜZELTMESİ (bkz. docs/PDKS_FAZ1_SEMA.md §6a): İlk sürüm, bir kartın
// kanonik UID'sinin BAYT-TERSİNİ otomatik olarak "aynı fiziksel kartın başka
// bir gösterimi" sayıp ikinci bir alias olarak yazıyordu. BU YANLIŞTI: iki
// FARKLI fiziksel kartın kanonik UID'leri birbirinin bayt-tersi OLABİLİR
// (25A87ED7 ve D77EA825 gibi) ve otomatik ters-alias bu durumda ikinci,
// gerçek kartın kaydını KÖRÜKÖRÜNE REDDEDERDİ. Üçüncü parti bir NFC
// uygulamasının baytları ters sırada GÖSTERMESİ, o ters değerin aynı kartın
// başka bir kimliği olduğunu KANITLAMAZ.
//
// DÜZELTİLMİŞ MODEL — dört kavram net ayrılır:
//   • KANONİK UID           : kartın TEK gerçek kimliği (bu bölümün ürettiği değer)
//   • KAYNAK GÖSTERİMİ       : bir okuma kaynağının (usb_decimal | nfc_hex) HAM çıktısı
//   • GÖSTERİM (display)     : üçüncü parti bir uygulamanın ekranda seçtiği biçim
//                              (büyük/küçük harf, ayraç, bayt sırası) — KİMLİK DEĞİL
//   • BAYT SIRASI DÖNÜŞÜMÜ   : yalnız KAYNAK ADAPTÖRÜ içinde, Faz 0'ın GERÇEK Android
//                              ölçümüne dayanarak, HER ZAMAN uygulanan tek yönlü ve
//                              deterministik bir dönüşüm (aşağıda pdks_uid_hex_normalize
//                              docblock'unda) — kart bazında SPEKÜLATİF ikinci bir
//                              aday ÜRETMEZ.
//
// Her kaynak, her ham girdiyi TEK bir kanonik değere deterministik olarak
// eşler. Bu eşleme sonradan Android ölçümü "aslında ters" derse KAYNAK
// ADAPTÖRÜNÜN İÇİNDE değişir (tüm kartlar için tutarlı biçimde) — asla kart
// bazında "belki tersi de odur" varsayımıyla değil.
//
// BURASI TEK OTORİTEDİR — Android istemci ve USB tanımlama ekranı yalnız
// GÖSTERİM yapar, kanonik kararı her zaman sunucu verir.
//
// KANON: BÜYÜK HARF HEX · ayraçsız · baştaki sıfır baytları korunmuş ·
//        uzunluk bayt sayısıyla sabit (8 / 14 / 20 hane).
//
// ⚠ hexdec() / dechex() / (int) cast TAM UID ÜZERİNDE KULLANILMAZ.
//    10 baytlık UID 80 bittir; PHP tamsayısına sığmaz ve bu fonksiyonlar
//    sessizce float'a düşüp YANLIŞ UID üretir (hata vermeden). Faz 0'da
//    bu ortamda bcmath/gmp da bulunamadı → saf string aritmetiği zorunlu.
// =========================================================

/**
 * NFC KAYNAK ADAPTÖRÜ — ham HEX gösterimini kanona çevirir.
 * Kabul: "25A87ED7" · "25a87ed7" · "25:A8:7E:D7" · "25-A8-7E-D7" · "25 A8 7E D7" · "0x25A87ED7"
 * (Bunlar yalnız YAZIM farklarıdır — ayraç/büyük-küçük harf — bayt sırası DEĞİL.)
 *
 * ⚠ BAYT SIRASI VARSAYIMI: Şu an Android `Tag.getId()`'nin USB okuyucuyla AYNI
 * bayt sırasında olduğunu varsayar (Faz 0 §5.4 "Durum A" beklentisi — HENÜZ
 * gerçek cihazda doğrulanmadı, bkz. tools/nfc_uid_tani/). Gerçek ölçüm "Durum B"
 * (ters sıra) çıkarsa, dönüşüm BURAYA (bu fonksiyona, tüm kartlar için tutarlı
 * biçimde) eklenir — bir kartın kaydında "belki tersi de odur" diye ikinci bir
 * aday ÜRETİLMEZ. Ölçüm sonucu docs/PDKS_NFC_FAZ0_DOGRULAMA.md §5'e işlenecek.
 *
 * @return string|null Kanonik HEX veya geçersizse null.
 */
function pdks_uid_hex_normalize(?string $ham): ?string
{
    if ($ham === null) return null;
    $s = strtoupper(trim($ham));
    $s = str_replace([':', '-', '.', ' ', "\t", "\xc2\xa0"], '', $s);
    if (str_starts_with($s, '0X')) $s = substr($s, 2);
    if ($s === '' || !preg_match('/^[0-9A-F]+$/', $s)) return null;
    if (strlen($s) % 2 !== 0) return null;                                  // tam bayt olmalı
    if (!in_array(intdiv(strlen($s), 2), PDKS_UID_BAYT, true)) return null; // 4/7/10 bayt
    return $s;
}

/** Ondalık STRING → 16'lık taban. bcmath/gmp GEREKTİRMEZ. */
function pdks_dec_to_hex_string(string $dec): string
{
    $dec = ltrim($dec, '0');
    if ($dec === '') return '0';
    $hex = '';
    while ($dec !== '') {
        $kalan = 0;
        $bolum = '';
        $n = strlen($dec);
        for ($i = 0; $i < $n; $i++) {
            $cur     = $kalan * 10 + (int)$dec[$i];
            $basamak = intdiv($cur, 16);
            $kalan   = $cur % 16;
            if ($bolum !== '' || $basamak !== 0) $bolum .= (string)$basamak;
        }
        $hex = strtoupper(dechex($kalan)) . $hex;   // yalnız TEK BASAMAK (0-15) çevrilir — güvenli
        $dec = $bolum;
    }
    return $hex;
}

/** Kanonik HEX → işaretsiz ondalık string (yalnız gösterim/teşhis). */
function pdks_uid_to_decimal(string $kanonik): string
{
    $dec = '0';
    $n   = strlen($kanonik);
    for ($i = 0; $i < $n; $i++) {
        $dec = pdks_dec_carpi_ekle($dec, 16, (int)hexdec($kanonik[$i]));  // tek hane — güvenli
    }
    return $dec;
}

/** Ondalık string × çarpan + ekle (string aritmetiği). */
function pdks_dec_carpi_ekle(string $dec, int $carpan, int $ekle): string
{
    $out  = '';
    $tasi = $ekle;
    for ($i = strlen($dec) - 1; $i >= 0; $i--) {
        $v    = (int)$dec[$i] * $carpan + $tasi;
        $out  = (string)($v % 10) . $out;
        $tasi = intdiv($v, 10);
    }
    while ($tasi > 0) { $out = (string)($tasi % 10) . $out; $tasi = intdiv($tasi, 10); }
    $out = ltrim($out, '0');
    return $out === '' ? '0' : $out;
}

/**
 * USB HID okuyucunun yazdığı ONDALIK değeri kanonik HEX'e çevirir.
 * $bayt verilmezse değere sığan en küçük desteklenen uzunluk seçilir.
 * Baştaki sıfır baytları ondalıkta KAYBOLDUĞU için sola sıfır doldurulur:
 *   2467966 → "0025A87E"   ("25A87E" DEĞİL)
 */
function pdks_uid_from_decimal(?string $ham, ?int $bayt = null): ?string
{
    if ($ham === null) return null;
    $s = str_replace([' ', '.', ',', "\t", "\xc2\xa0"], '', trim($ham));   // binlik ayracı tolere
    if ($s === '' || !preg_match('/^[0-9]+$/', $s)) return null;

    $hex = pdks_dec_to_hex_string($s);
    if ($hex === '0') $hex = '';

    if ($bayt === null) {
        $gereken = (int)ceil(max(1, strlen($hex)) / 2);
        foreach (PDKS_UID_BAYT as $b) { if ($gereken <= $b) { $bayt = $b; break; } }
        if ($bayt === null) return null;                                   // 10 bayttan uzun
    }
    if (!in_array($bayt, PDKS_UID_BAYT, true)) return null;
    if (strlen($hex) > $bayt * 2) return null;

    return str_pad($hex, $bayt * 2, '0', STR_PAD_LEFT);
}

/**
 * Kanonik HEX'i BAYT bazında ters çevirir (nibble değil).
 *
 * ⚠ YALNIZ TEŞHİS/GÖSTERİM AMAÇLIDIR — kimlik eşleştirmede KULLANILMAZ
 * (Faz 1 düzeltmesi, bkz. dosya başındaki "UID NORMALİZASYONU" bölümü).
 * "Bu kartın tersi böyle görünür" diye Android teşhis ekranında veya bir
 * çakışma uyarısında göstermek için kullanılabilir; ama pdks_kart_olustur()
 * ve pdks_kart_cozumle() artık BUNU kimlik eşitliği saymaz — iki farklı
 * fiziksel kartın kanonik UID'leri birbirinin bayt-tersi olabilir.
 */
function pdks_uid_reverse(string $kanonik): string
{
    $out = '';
    for ($i = strlen($kanonik) - 2; $i >= 0; $i -= 2) $out .= substr($kanonik, $i, 2);
    return $out;
}

/**
 * Bir okumanın KANONİK karşılığını (varsa) tek elemanlı bir dizi olarak döner.
 *
 * Her kaynak, her ham girdiyi TEK bir kanonik değere deterministik olarak
 * eşler — dizi biçimi `pdks_kart_cozumle()`'nin SQL `IN (...)` sorgusuyla
 * uyumlu kalması içindir, "birden çok olası kimlik" ANLAMINA GELMEZ.
 *
 * ⚠ FAZ 1 DÜZELTMESİ: Önceki sürüm burada [kanon, ters(kanon)] döndürüyordu
 * — yani bir kartın BAYT-TERSİNİ de "aynı kart" sayıyordu. Bu KALDIRILDI:
 * D77EA825, 25A87ED7'nin bir başka gösterimi DEĞİL, potansiyel olarak
 * TAMAMEN FARKLI bir fiziksel kartın kendi kanonik kimliğidir. Bkz.
 * scripts/pdks_db_smoke.php "İKİ FARKLI FİZİKSEL KART" testi.
 *
 * ⚠ $kaynak ZORUNLUDUR ve ASLA TAHMİN EDİLMEZ (onaylanan karar #10).
 * Gerekçe (Faz 0 §4.4): "12345678" hem geçerli 4 baytlık HEX (0x12345678)
 * hem geçerli ondalıktır (0x00BC614E) — otomatik tespit iki FARKLI kartı
 * sessizce birbirine karıştırırdı. Bilinmeyen kaynak → boş liste (fail-closed).
 *
 * @param string $kaynak 'usb_decimal' | 'nfc_hex'
 */
function pdks_uid_adaylari(string $ham, string $kaynak): array
{
    if (!in_array($kaynak, PDKS_UID_KAYNAKLARI, true)) return [];          // fail-closed
    $k = ($kaynak === 'usb_decimal')
        ? pdks_uid_from_decimal($ham)
        : pdks_uid_hex_normalize($ham);
    if ($k === null) return [];
    return [$k];                                                          // TEK aday — bkz. yukarıdaki not
}

/** Kanoniğin bayt uzunluğu. */
function pdks_uid_bayt_sayisi(string $kanonik): int { return intdiv(strlen($kanonik), 2); }

// =========================================================
// ŞEMA
//
// Tamamı YENİ tablodur. MEVCUT HİÇBİR TABLOYA ALTER YOKTUR.
// Geri alma = kodu geri al; tablolar boş/kullanılmaz kalır.
// =========================================================

/**
 * Faz 1 tabloları — oluşturulma SIRASI önemlidir (FK bağımlılığı).
 * @return array<string,string> tablo adı => CREATE TABLE IF NOT EXISTS SQL
 */
function pdks_tablolar(): array
{
    $t = [];

    // ── employees — personel kartoteksi ──────────────────
    // Sistemde personel ana tablosu YOKTU (Faz 0 §3.1). `users` uygulamaya
    // GİREN kişilerdir; personelin çoğunun hesabı olmayacaktır.
    // KVKK (onaylanan karar #2): TC kimlik numarası HİÇ saklanmaz.
    $t['employees'] = "CREATE TABLE IF NOT EXISTS `employees` (
        `id`               INT AUTO_INCREMENT PRIMARY KEY,
        `personnel_no`     VARCHAR(30)  NULL DEFAULT NULL,
        `full_name`        VARCHAR(150) NOT NULL,
        `department`       VARCHAR(100) NOT NULL DEFAULT '',
        `job_title`        VARCHAR(100) NOT NULL DEFAULT '',
        `depo`             VARCHAR(150) NOT NULL DEFAULT '',
        `status`           VARCHAR(20)  NOT NULL DEFAULT 'aktif',
        `user_id`          INT          NULL DEFAULT NULL,
        `photo_file`       VARCHAR(64)  NULL DEFAULT NULL,
        `photo_updated_at` DATETIME     NULL DEFAULT NULL,
        `phone`            VARCHAR(30)  NULL DEFAULT NULL,
        `hire_date`        DATE         NULL DEFAULT NULL,
        `leave_date`       DATE         NULL DEFAULT NULL,
        `notes`            TEXT         NULL DEFAULT NULL,
        `created_by`       INT          NULL DEFAULT NULL,
        `updated_by`       INT          NULL DEFAULT NULL,
        `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`       DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_emp_pno`  (`personnel_no`),
        UNIQUE KEY `uq_emp_user` (`user_id`),
        INDEX `idx_emp_status` (`status`),
        INDEX `idx_emp_depo`   (`depo`(80)),
        INDEX `idx_emp_dept`   (`department`(80)),
        INDEX `idx_emp_name`   (`full_name`(80))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // ── employee_cards — fiziksel kart kaydı ─────────────
    // Onaylanan karar #1: iki katmanlı model. Bu tablo FİZİKSEL kartı temsil
    // eder; uid_hex UNIQUE olduğu için aynı kart iki satır olamaz ve bu
    // yüzden aynı anda iki personele atanamaz.
    $t['employee_cards'] = "CREATE TABLE IF NOT EXISTS `employee_cards` (
        `id`                  INT AUTO_INCREMENT PRIMARY KEY,
        `employee_id`         INT          NOT NULL,
        `uid_hex`             VARCHAR(32)  NOT NULL,
        `uid_bytes`           TINYINT      NOT NULL DEFAULT 4,
        `uid_decimal`         VARCHAR(25)  NULL DEFAULT NULL,
        `card_type`           VARCHAR(30)  NOT NULL DEFAULT 'mifare_classic_1k',
        `atqa`                VARCHAR(8)   NULL DEFAULT NULL,
        `sak`                 VARCHAR(8)   NULL DEFAULT NULL,
        `label`               VARCHAR(60)  NOT NULL DEFAULT '',
        `status`              VARCHAR(20)  NOT NULL DEFAULT 'aktif',
        `issued_at`           DATE         NULL DEFAULT NULL,
        `expires_at`          DATE         NULL DEFAULT NULL,
        `replacement_card_id` INT          NULL DEFAULT NULL,
        `revoked_at`          DATETIME     NULL DEFAULT NULL,
        `revoked_by`          INT          NULL DEFAULT NULL,
        `revoke_reason`       VARCHAR(200) NOT NULL DEFAULT '',
        `enrolled_source`     VARCHAR(20)  NOT NULL DEFAULT 'usb_decimal',
        `notes`               TEXT         NULL DEFAULT NULL,
        `created_by`          INT          NULL DEFAULT NULL,
        `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`          DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_ec_uid` (`uid_hex`),
        INDEX `idx_ec_emp`    (`employee_id`),
        INDEX `idx_ec_status` (`status`),
        INDEX `idx_ec_dec`    (`uid_decimal`),
        CONSTRAINT `fk_ec_emp` FOREIGN KEY (`employee_id`)
            REFERENCES `employees`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // ── employee_card_uids — UID takma adları ────────────
    // ⚠ FAZ 1 DÜZELTMESİ: Bu tablo ARTIK bir kartın kanonik UID'sinin
    // bayt-tersini OTOMATİK olarak ikinci bir alias yazmaz (önceki sürüm
    // yazıyordu — bkz. dosya başındaki "UID NORMALİZASYONU" bölümü). Şu an
    // her kart için TEK satır yazılır: kind='canonical', uid_hex = kartın
    // kendi kanonik değeri; bu, employee_cards.uid_hex ile 1:1 örtüşür.
    //
    // Tablo YİNE DE tutulur (silinmedi) — Faz 2+'da GERÇEKTEN meşru,
    // KAYNAĞA dayalı ek gösterimler için: ör. bir personel aynı fiziksel
    // kartı hem USB'den (usb_decimal) hem NFC'den (nfc_hex) tanımlarsa VE
    // iki kaynak farklı ama DOĞRULANMIŞ bir dönüşümle aynı fiziksel karta
    // işaret ediyorsa. Böyle bir satır YALNIZ açıkça öğrenilmiş/doğrulanmış
    // bir eşleme olarak eklenir — asla "tersi de olabilir" varsayımıyla
    // otomatik ÜRETİLMEZ. `kind` bu yüzden serbest bir metin alanıdır
    // ('canonical' dışında bir değer, o satırın nasıl doğrulandığını
    // açıklayan bir not taşımalıdır).
    $t['employee_card_uids'] = "CREATE TABLE IF NOT EXISTS `employee_card_uids` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `card_id`    INT         NOT NULL,
        `uid_hex`    VARCHAR(32) NOT NULL,
        `kind`       VARCHAR(20) NOT NULL DEFAULT 'canonical',
        `created_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_ecu_uid` (`uid_hex`),
        INDEX `idx_ecu_card` (`card_id`),
        CONSTRAINT `fk_ecu_card` FOREIGN KEY (`card_id`)
            REFERENCES `employee_cards`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // ── attendance_gates — kapı / lokasyon ───────────────
    // Faz 2'de cihaz→kapı→depo zinciri API'nin depo bağlamını buradan alır
    // (yol haritası §B.4). Faz 1'de yalnız temel atılır; arayüzü Faz 2'dedir.
    $t['attendance_gates'] = "CREATE TABLE IF NOT EXISTS `attendance_gates` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `name`       VARCHAR(80)  NOT NULL,
        `depo`       VARCHAR(150) NOT NULL DEFAULT '',
        `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
        `sort_order` INT          NOT NULL DEFAULT 0,
        `notes`      TEXT         NULL DEFAULT NULL,
        `created_by` INT          NULL DEFAULT NULL,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_gate_name` (`name`),
        INDEX `idx_gate_depo`   (`depo`(80)),
        INDEX `idx_gate_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    return $t;
}

/**
 * employees.user_id → users.id yabancı anahtarı.
 * AYRI tutulur çünkü `users` MEVCUT bir tablodur: FK, o tabloya dokunmaz ama
 * ona bağımlıdır. Ayrı ALTER olarak denenir ve BAŞARISIZ OLURSA MİGRASYON
 * DURMAZ — UNIQUE kısıtı ve uygulama mantığı FK olmadan da doğru çalışır.
 * ON DELETE SET NULL: users hiçbir zaman silinmiyor (pasifleştiriliyor), ama
 * biri phpMyAdmin'den silerse personel kaydı KAYBOLMAZ, yalnız bağı kopar.
 */
function pdks_users_fk_sql(): string
{
    return "ALTER TABLE `employees`
            ADD CONSTRAINT `fk_emp_user` FOREIGN KEY (`user_id`)
            REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE";
}

/** Bir tablo var mı? */
function pdks_tablo_var(PDO $pdo, string $tablo): bool
{
    try { $pdo->query("SELECT 1 FROM `{$tablo}` LIMIT 0"); return true; }
    catch (PDOException $e) { return false; }
}

/** Bir kısıt (constraint) zaten tanımlı mı? */
function pdks_kisit_var(PDO $pdo, string $tablo, string $kisit): bool
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                             WHERE TABLE_SCHEMA = DATABASE()
                               AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?");
        $st->execute([$tablo, $kisit]);
        return (int)$st->fetchColumn() > 0;
    } catch (PDOException $e) { return false; }
}

/**
 * Şema migrasyonu — IDEMPOTENT, tekrar çalıştırılabilir, yıkıcı değildir.
 *
 * ⚠ KENDİLİĞİNDEN ÇALIŞMAZ. config/db.php veya helpers.php'ye BİLEREK
 *    eklenmemiştir: oradaki bir hata TÜM uygulamayı etkilerdi. Çağıranlar:
 *    migrate.php (admin paneli) ve ileride PDKS sayfaları.
 *
 * @return array<int,array{tablo:string,durum:string,mesaj:string}>
 *         durum: 'var' | 'olusturuldu' | 'hata'
 */
function pdks_migrate(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $rapor = [];

    foreach (pdks_tablolar() as $ad => $sql) {
        if (pdks_tablo_var($pdo, $ad)) {
            $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.'];
            continue;
        }
        try {
            $pdo->exec($sql);
            $rapor[] = pdks_tablo_var($pdo, $ad)
                ? ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.']
                : ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'CREATE çalıştı ama tablo görünmüyor.'];
        } catch (PDOException $e) {
            // Sessizce yutulmaz: rapora yazılır VE error_log'a düşer.
            error_log('[pdks_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => $e->getMessage()];
        }
    }

    // users FK'sı — opsiyonel, başarısızlığı migrasyonu bozmaz.
    if (pdks_tablo_var($pdo, 'employees') && pdks_tablo_var($pdo, 'users')) {
        if (pdks_kisit_var($pdo, 'employees', 'fk_emp_user')) {
            $rapor[] = ['tablo' => 'employees.fk_emp_user', 'durum' => 'var', 'mesaj' => 'Yabancı anahtar zaten mevcut.'];
        } else {
            try {
                $pdo->exec(pdks_users_fk_sql());
                $rapor[] = ['tablo' => 'employees.fk_emp_user', 'durum' => 'olusturuldu', 'mesaj' => 'users FK eklendi.'];
            } catch (PDOException $e) {
                error_log('[pdks_migrate] fk_emp_user: ' . $e->getMessage());
                $rapor[] = ['tablo' => 'employees.fk_emp_user', 'durum' => 'hata',
                            'mesaj' => 'FK eklenemedi (kritik değil, UNIQUE kısıtı yeterli): ' . $e->getMessage()];
            }
        }
    }

    return $rapor;
}

/** Şema hazır mı? (arayüz "önce migration çalıştırın" diyebilsin diye) */
function pdks_sema_hazir(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? db();
    foreach (array_keys(pdks_tablolar()) as $ad) {
        if (!pdks_tablo_var($pdo, $ad)) return false;
    }
    return true;
}

// =========================================================
// YETKİ KAPISI
// Mevcut can() / is_admin() üzerine kurulur; yetki sistemi DEĞİŞTİRİLMEZ.
// =========================================================

/** @param string $eylem read|scan|manual|correct|report|employees|cards|devices|admin */
function pdks_can(string $eylem): bool
{
    if (!function_exists('can')) return false;
    if (function_exists('is_admin') && is_admin()) return true;

    return match ($eylem) {
        'read'      => can('attendance.read') || can('attendance.admin'),
        'scan'      => can('attendance.scan'),
        'manual'    => can('attendance.manual'),
        'correct'   => can('attendance.correct'),
        'report'    => can('attendance.report'),
        'employees' => can('attendance.employees'),
        'cards'     => can('attendance.cards'),
        'devices'   => can('attendance.devices') || can('attendance.admin'),
        'admin'     => can('attendance.admin'),
        default     => false,
    };
}

/** Sayfa kapısı — require_perm() emsali; require_hesap() ile aynı desen. */
function require_pdks(string $eylem): void
{
    if (!PDKS_AKTIF) {
        if (function_exists('forbidden')) forbidden('Personel modülü şu anda kapalıdır.');
        http_response_code(503);
        exit('Personel modülü kapalı.');
    }
    if (function_exists('current_user') && current_user() === null) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . (function_exists('base_url') ? base_url() : '') . 'login.php' . ($next ? '?next=' . $next : ''));
        exit;
    }
    if (function_exists('enforce_active_depot')) enforce_active_depot();
    if (!pdks_can($eylem)) {
        forbidden("Bu sayfaya erişim yetkiniz yok. (Gerekli yetki: attendance.{$eylem})");
    }
}

// =========================================================
// KART / PERSONEL ALAN MANTIĞI
// =========================================================

/**
 * Bir okumayı fiziksel karta çözer (alias tablosu üzerinden, kanonik eşleşme).
 *
 * @param string $kaynak 'usb_decimal' | 'nfc_hex' — ZORUNLU, tahmin edilmez.
 * @return array|null ['card'=>..., 'employee'=>..., 'eslesen_uid'=>...] veya null
 */
function pdks_kart_cozumle(string $ham, string $kaynak, ?PDO $pdo = null): ?array
{
    $adaylar = pdks_uid_adaylari($ham, $kaynak);
    if (count($adaylar) === 0) return null;

    $pdo = $pdo ?? db();
    $ph  = implode(',', array_fill(0, count($adaylar), '?'));
    $st  = $pdo->prepare(
        "SELECT c.*, u.uid_hex AS eslesen_uid
           FROM employee_card_uids u
           JOIN employee_cards c ON c.id = u.card_id
          WHERE u.uid_hex IN ($ph)
          LIMIT 1"
    );
    $st->execute($adaylar);
    $kart = $st->fetch();
    if (!$kart) return null;

    $es = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $es->execute([(int)$kart['employee_id']]);
    $personel = $es->fetch() ?: null;

    return ['card' => $kart, 'employee' => $personel, 'eslesen_uid' => $kart['eslesen_uid']];
}

/**
 * Bu KANONİK UID başka bir karta ait mi?
 *
 * ⚠ FAZ 1 DÜZELTMESİ: Yalnız TAM EŞLEŞME kontrol edilir. Önceki sürüm
 * `pdks_uid_reverse($kanonik)`'i de çakışma sayıyordu — yani 25A87ED7'yi
 * kaydederken D77EA825'in de "aynı kart" olduğunu varsayıp reddediyordu.
 * Bu YANLIŞTI: D77EA825 tamamen farklı, gerçek bir fiziksel kart olabilir
 * ve bu iki değerin AYNI ANDA, İKİ AYRI kart olarak var olabilmesi gerekir
 * (bkz. scripts/pdks_db_smoke.php "İKİ FARKLI FİZİKSEL KART" testi).
 *
 * @return array|null Çakışan kart satırı.
 */
function pdks_uid_cakismasi(string $kanonik, ?int $haricCardId = null, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? db();
    $sql = "SELECT c.*, u.uid_hex AS cakisan_uid
              FROM employee_card_uids u
              JOIN employee_cards c ON c.id = u.card_id
             WHERE u.uid_hex = ?";
    $par = [$kanonik];
    if ($haricCardId !== null) { $sql .= " AND c.id <> ?"; $par[] = $haricCardId; }
    $st = $pdo->prepare($sql . " LIMIT 1");
    $st->execute($par);
    return $st->fetch() ?: null;
}

/**
 * Kart oluşturur — kanonik kaydı + kanonik alias'ı TEK İŞLEMDE yazar.
 *
 * ⚠ KART YAZMANIN TEK YOLU BUDUR. İkinci bir yazma yolu açmayın:
 *    alias'sız yazılan bir kart, kendi kanonik değeriyle bile BULUNAMAZ.
 *    (halkayit/taslak_lib.php'deki "tek yazma yolu" kuralının aynısı.)
 *
 * ⚠ FAZ 1 DÜZELTMESİ: Artık kanoniğin bayt-tersini SPEKÜLATİF bir alias
 * olarak YAZMAZ. Sebep: D77EA825, 25A87ED7'nin "başka bir gösterimi" değil,
 * tamamen farklı bir fiziksel kartın olası kanonik kimliğidir — otomatik
 * ters-alias, o GERÇEK ikinci kartın kaydını reddederdi. Bkz. dosya başındaki
 * "UID NORMALİZASYONU" bölümü ve docs/PDKS_FAZ1_SEMA.md §6a.
 *
 * @param string $kaynak 'usb_decimal' | 'nfc_hex'
 * @return array{ok:bool, card_id?:int, uid_hex?:string, hata?:string, kod?:string}
 */
function pdks_kart_olustur(int $employeeId, string $hamUid, string $kaynak, array $ek = [], ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();

    if (!in_array($kaynak, PDKS_UID_KAYNAKLARI, true)) {
        return ['ok' => false, 'kod' => 'gecersiz_kaynak',
                'hata' => 'UID kaynağı bildirilmeli (usb_decimal veya nfc_hex).'];
    }

    $kanonik = ($kaynak === 'usb_decimal')
        ? pdks_uid_from_decimal($hamUid)
        : pdks_uid_hex_normalize($hamUid);
    if ($kanonik === null) {
        return ['ok' => false, 'kod' => 'gecersiz_uid', 'hata' => 'Okunan UID geçersiz.'];
    }

    $st = $pdo->prepare("SELECT id FROM employees WHERE id = ?");
    $st->execute([$employeeId]);
    if (!$st->fetchColumn()) {
        return ['ok' => false, 'kod' => 'personel_yok', 'hata' => 'Personel bulunamadı.'];
    }

    // Çakışma: yalnız TAM AYNI kanonik değer başka bir kartta olamaz.
    // (Bayt-tersi ARTIK çakışma SAYILMAZ — o başka bir fiziksel kart olabilir.)
    $cakisma = pdks_uid_cakismasi($kanonik, null, $pdo);
    if ($cakisma !== null) {
        return ['ok' => false, 'kod' => 'uid_kullanimda',
                'hata' => 'Bu UID zaten tanımlı (kart #' . (int)$cakisma['id'] . ').'];
    }

    $bayt    = pdks_uid_bayt_sayisi($kanonik);
    $ondalik = pdks_uid_to_decimal($kanonik);

    $disTx = $pdo->inTransaction();
    if (!$disTx) $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            "INSERT INTO employee_cards
                (employee_id, uid_hex, uid_bytes, uid_decimal, card_type, atqa, sak,
                 label, status, issued_at, expires_at, enrolled_source, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $ins->execute([
            $employeeId, $kanonik, $bayt, $ondalik,
            (string)($ek['card_type']  ?? 'mifare_classic_1k'),
            $ek['atqa'] ?? null, $ek['sak'] ?? null,
            (string)($ek['label'] ?? ''),
            (string)($ek['status'] ?? 'aktif'),
            $ek['issued_at']  ?? null,
            $ek['expires_at'] ?? null,
            $kaynak,
            $ek['notes'] ?? null,
            $ek['created_by'] ?? null,
        ]);
        $cardId = (int)$pdo->lastInsertId();

        // Yalnız KANONİK alias yazılır — bayt-tersi ARTIK otomatik yazılmaz (§ yukarısı).
        $ia = $pdo->prepare("INSERT INTO employee_card_uids (card_id, uid_hex, kind) VALUES (?,?,?)");
        $ia->execute([$cardId, $kanonik, 'canonical']);

        if (!$disTx) $pdo->commit();
    } catch (PDOException $e) {
        if (!$disTx && $pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'kod' => 'yazma_hatasi', 'hata' => $e->getMessage()];
    }

    if (function_exists('audit_log_event')) {
        audit_log_event('card_create', 'pdks', $cardId, null, [
            'employee_id' => $employeeId, 'uid_hex' => $kanonik,
            'uid_bytes' => $bayt, 'kaynak' => $kaynak,
        ]);
    }

    return ['ok' => true, 'card_id' => $cardId, 'uid_hex' => $kanonik,
            'uid_decimal' => $ondalik, 'uid_bytes' => $bayt];
}

/**
 * Kartı iptal eder. Kart satırı SİLİNMEZ — geçmiş korunur (yol haritası §D.2).
 * Alias'lar da korunur: iptal kart yine tanınır, ama pdks_kart_aktif_mi() false döner
 * (Faz 2'de "KART İPTAL EDİLMİŞ" ekranı bunu gösterecek — sessiz "tanımsız kart" değil).
 */
function pdks_kart_iptal(int $cardId, string $durum, string $gerekce, ?int $userId = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!array_key_exists($durum, pdks_kart_durumlari()) || $durum === 'aktif') {
        return ['ok' => false, 'hata' => 'Geçersiz kart durumu.'];
    }
    $st = $pdo->prepare("SELECT * FROM employee_cards WHERE id = ?");
    $st->execute([$cardId]);
    $eski = $st->fetch();
    if (!$eski) return ['ok' => false, 'hata' => 'Kart bulunamadı.'];

    $pdo->prepare("UPDATE employee_cards
                      SET status = ?, revoke_reason = ?, revoked_at = NOW(), revoked_by = ?
                    WHERE id = ?")
        ->execute([$durum, $gerekce, $userId, $cardId]);

    if (function_exists('audit_log_event')) {
        audit_log_event('card_revoke', 'pdks', $cardId,
            ['status' => $eski['status']],
            ['status' => $durum, 'revoke_reason' => $gerekce]);
    }
    return ['ok' => true];
}

// =========================================================
// FAZ 1B — PERSONEL CRUD + KART YAŞAM DÖNGÜSÜ SARMALAYICILARI
//
// Faz 1'in ham fonksiyonlarını (pdks_kart_olustur, pdks_kart_iptal) DEĞİŞTİRMEZ,
// yalnız üzerine iş kuralı ekler:
//   - pdks_kart_ata()      → pdks_kart_olustur() + "personelde zaten aktif kart
//                             var mı" kuralı + 'card_assigned' audit
//   - pdks_kart_durum_degistir() → pdks_kart_iptal() + adlandırılmış audit
//                             ('card_revoked' / 'card_lost')
//   - pdks_kart_degistir() → pdks_kart_olustur() (yeni kart) + eski kartı
//                             'degistirildi' işaretleme, TEK işlemde
// Bu üçü Faz 1'in "kart yazmanın tek yolu" kuralını bozmaz — hepsi sonunda
// pdks_kart_olustur()'a çıkar, UID mantığını asla tekrar YAZMAZ.
// =========================================================

/** Personel alanlarını kolon uzunluklarına kırpar (repo konvansiyonu: reddetmez, kırpar). */
function pdks_personel_alan_temizle(array $veri): array
{
    $bos_veya = function ($v) { $v = trim((string)($v ?? '')); return $v === '' ? null : $v; };
    $uid_raw = $veri['user_id'] ?? null;
    return [
        'personnel_no' => $bos_veya(mb_substr(trim((string)($veri['personnel_no'] ?? '')), 0, 30, 'UTF-8')),
        'full_name'    => mb_substr(trim((string)($veri['full_name'] ?? '')), 0, 150, 'UTF-8'),
        'department'   => mb_substr(trim((string)($veri['department'] ?? '')), 0, 100, 'UTF-8'),
        'job_title'    => mb_substr(trim((string)($veri['job_title']  ?? '')), 0, 100, 'UTF-8'),
        'depo'         => mb_substr(trim((string)($veri['depo']       ?? '')), 0, 150, 'UTF-8'),
        'status'       => (string)($veri['status'] ?? 'aktif'),
        'user_id'      => ($uid_raw === '' || $uid_raw === null) ? null : (int)$uid_raw,
        'phone'        => $bos_veya(mb_substr(trim((string)($veri['phone'] ?? '')), 0, 30, 'UTF-8')),
        'hire_date'    => $bos_veya($veri['hire_date']  ?? ''),
        'leave_date'   => $bos_veya($veri['leave_date'] ?? ''),
        'notes'        => $bos_veya($veri['notes'] ?? ''),
    ];
}

/**
 * Personel alanlarını doğrular. TC kimlik numarası ALANI YOK (onaylanan karar #2) —
 * eklenmesi istenmiyor, bu fonksiyon böyle bir alanı ne okur ne bekler.
 * @return string[] Hata mesajları — boşsa geçerli.
 */
function pdks_personel_dogrula(array $temiz, ?int $haricId = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $hatalar = [];

    if ($temiz['full_name'] === '') $hatalar[] = 'Ad soyad zorunludur.';
    if (!array_key_exists($temiz['status'], pdks_personel_durumlari())) $hatalar[] = 'Geçersiz personel durumu.';

    if ($temiz['personnel_no'] !== null) {
        $sql = "SELECT id FROM employees WHERE personnel_no = ?";
        $par = [$temiz['personnel_no']];
        if ($haricId !== null) { $sql .= " AND id <> ?"; $par[] = $haricId; }
        $st = $pdo->prepare($sql); $st->execute($par);
        if ($st->fetchColumn()) $hatalar[] = 'Bu sicil numarası başka bir personelde kayıtlı.';
    }

    if ($temiz['user_id'] !== null) {
        $st = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $st->execute([$temiz['user_id']]);
        if (!$st->fetchColumn()) {
            $hatalar[] = 'Seçilen kullanıcı hesabı bulunamadı.';
        } else {
            $sql = "SELECT id FROM employees WHERE user_id = ?";
            $par = [$temiz['user_id']];
            if ($haricId !== null) { $sql .= " AND id <> ?"; $par[] = $haricId; }
            $st = $pdo->prepare($sql); $st->execute($par);
            if ($st->fetchColumn()) $hatalar[] = 'Bu kullanıcı hesabı zaten başka bir personele bağlı.';
        }
    }

    return $hatalar;
}

/** @return array{ok:bool, id?:int, kod?:string, hata?:string} */
function pdks_personel_olustur(array $veri, ?int $createdBy = null, ?PDO $pdo = null): array
{
    $pdo   = $pdo ?? db();
    $temiz = pdks_personel_alan_temizle($veri);
    $hatalar = pdks_personel_dogrula($temiz, null, $pdo);
    if (!empty($hatalar)) return ['ok' => false, 'kod' => 'dogrulama', 'hata' => implode(' ', $hatalar)];

    try {
        $st = $pdo->prepare(
            "INSERT INTO employees
                (personnel_no, full_name, department, job_title, depo, status, user_id, phone, hire_date, leave_date, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $st->execute([
            $temiz['personnel_no'], $temiz['full_name'], $temiz['department'], $temiz['job_title'],
            $temiz['depo'], $temiz['status'], $temiz['user_id'], $temiz['phone'],
            $temiz['hire_date'], $temiz['leave_date'], $temiz['notes'], $createdBy,
        ]);
        $id = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        // Yarış durumu: iki eşzamanlı istek aynı sicil/user_id'yi aynı anda doğrulamış olabilir.
        // UNIQUE kısıtı son sözü söyler — sessizce yutulmaz, kullanıcıya döner.
        return ['ok' => false, 'kod' => 'cakisma', 'hata' => 'Kayıt eklenemedi (sicil no veya kullanıcı bağı çakışıyor olabilir).'];
    }

    if (function_exists('audit_log_event')) {
        audit_log_event('employee_created', 'pdks', $id, null, $temiz);
    }
    return ['ok' => true, 'id' => $id];
}

/** @return array{ok:bool, kod?:string, hata?:string} */
function pdks_personel_guncelle(int $id, array $veri, ?int $updatedBy = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $st  = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $st->execute([$id]);
    $eski = $st->fetch();
    if (!$eski) return ['ok' => false, 'kod' => 'personel_yok', 'hata' => 'Personel bulunamadı.'];

    $temiz = pdks_personel_alan_temizle($veri);
    $hatalar = pdks_personel_dogrula($temiz, $id, $pdo);
    if (!empty($hatalar)) return ['ok' => false, 'kod' => 'dogrulama', 'hata' => implode(' ', $hatalar)];

    try {
        $pdo->prepare(
            "UPDATE employees SET
                personnel_no=?, full_name=?, department=?, job_title=?, depo=?, status=?,
                user_id=?, phone=?, hire_date=?, leave_date=?, notes=?, updated_by=?
             WHERE id=?"
        )->execute([
            $temiz['personnel_no'], $temiz['full_name'], $temiz['department'], $temiz['job_title'],
            $temiz['depo'], $temiz['status'], $temiz['user_id'], $temiz['phone'],
            $temiz['hire_date'], $temiz['leave_date'], $temiz['notes'], $updatedBy, $id,
        ]);
    } catch (PDOException $e) {
        return ['ok' => false, 'kod' => 'cakisma', 'hata' => 'Kayıt güncellenemedi (sicil no veya kullanıcı bağı çakışıyor olabilir).'];
    }

    if (function_exists('audit_log_event')) {
        $eskiOzet = [
            'personnel_no' => $eski['personnel_no'], 'full_name' => $eski['full_name'],
            'department'   => $eski['department'],   'job_title' => $eski['job_title'],
            'status'       => $eski['status'],        'user_id'   => $eski['user_id'],
        ];
        audit_log_event('employee_updated', 'pdks', $id, $eskiOzet, $temiz);
        // Durum değişikliği ayrıca kendi adıyla loglanır — puantaj/erişim
        // açısından anlamlı bir olaydır, genel güncellemenin içinde kaybolmasın.
        if ((string)$eski['status'] !== $temiz['status']) {
            audit_log_event('employee_status_changed', 'pdks', $id,
                ['status' => $eski['status']], ['status' => $temiz['status']]);
        }
    }
    return ['ok' => true];
}

/** Bir personelin şu anki AKTİF kartı (varsa). */
function pdks_personel_aktif_kart(int $employeeId, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare("SELECT * FROM employee_cards WHERE employee_id = ? AND status = 'aktif' ORDER BY id DESC LIMIT 1");
    $st->execute([$employeeId]);
    return $st->fetch() ?: null;
}

/** Bir personelin TÜM kart geçmişi (aktif + iptal + kayıp + değiştirilmiş…), en yeni önce. Hiçbiri silinmez. */
function pdks_personel_kart_gecmisi(int $employeeId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare("SELECT * FROM employee_cards WHERE employee_id = ? ORDER BY created_at DESC, id DESC");
    $st->execute([$employeeId]);
    return $st->fetchAll();
}

/**
 * Personele YENİ kart atar — yalnız personelin hâlihazırda AKTİF kartı yoksa.
 *
 * Şema bunu bir UNIQUE kısıtla zorlamaz (bir personelin iki satırı olabilir,
 * biri aktif diğeri iptal); "aynı anda yalnız bir aktif kart" kuralı burada,
 * uygulama katmanında uygulanır (Faz 1B §5 gereği).
 *
 * @return array{ok:bool, card_id?:int, uid_hex?:string, kod?:string, hata?:string}
 */
function pdks_kart_ata(int $employeeId, string $hamUid, string $kaynak, array $ek = [], ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (pdks_personel_aktif_kart($employeeId, $pdo) !== null) {
        return ['ok' => false, 'kod' => 'zaten_aktif_kart_var',
                'hata' => 'Bu personelin zaten aktif bir kartı var. Önce iptal edin veya "Değiştir" kullanın.'];
    }
    $sonuc = pdks_kart_olustur($employeeId, $hamUid, $kaynak, $ek, $pdo);
    if ($sonuc['ok'] && function_exists('audit_log_event')) {
        audit_log_event('card_assigned', 'pdks', (int)$sonuc['card_id'], null,
            ['employee_id' => $employeeId, 'uid_hex' => $sonuc['uid_hex']]);
    }
    return $sonuc;
}

/**
 * Kartı iptal eder / kayıp bildirir — pdks_kart_iptal()'i sarar, yalnız
 * adlandırılmış audit olayı ekler (`card_revoked` / `card_lost`) ve
 * gerekçenin boş olmadığını sunucu tarafında da zorunlu kılar.
 */
function pdks_kart_durum_degistir(int $cardId, string $durum, string $gerekce, ?int $userId = null, ?PDO $pdo = null): array
{
    $gerekce = trim($gerekce);
    if ($gerekce === '') {
        return ['ok' => false, 'kod' => 'gerekce_zorunlu', 'hata' => 'Gerekçe zorunludur.'];
    }
    $sonuc = pdks_kart_iptal($cardId, $durum, $gerekce, $userId, $pdo);
    if ($sonuc['ok'] && function_exists('audit_log_event')) {
        $eylem = match ($durum) {
            'iptal' => 'card_revoked',
            'kayip' => 'card_lost',
            default => 'card_status_changed',
        };
        audit_log_event($eylem, 'pdks', $cardId, null, ['status' => $durum, 'reason' => $gerekce]);
    }
    return $sonuc;
}

/**
 * Eski kartı YENİ bir fiziksel kartla değiştirir — TEK işlemde:
 *   1) yeni kart pdks_kart_olustur() ile yazılır (UID mantığı burada TEKRARLANMAZ)
 *   2) yeni kart başarılıysa eski kart 'degistirildi' işaretlenir,
 *      replacement_card_id yeni karta bağlanır
 * Yeni kart oluşturma başarısız olursa (ör. UID zaten kullanımda) eski kart
 * HİÇ değişmez — kısmi/tutarsız durum oluşmaz.
 *
 * ⚠ Eski kart SİLİNMEZ (yol haritası §D.2, Faz 1B §5 "geçmiş kaybolmaz" kuralı).
 */
function pdks_kart_degistir(int $eskiCardId, string $hamUid, string $kaynak, string $gerekce, ?int $userId = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $gerekce = trim($gerekce);
    if ($gerekce === '') {
        return ['ok' => false, 'kod' => 'gerekce_zorunlu', 'hata' => 'Gerekçe zorunludur.'];
    }

    $st = $pdo->prepare("SELECT * FROM employee_cards WHERE id = ?");
    $st->execute([$eskiCardId]);
    $eski = $st->fetch();
    if (!$eski) return ['ok' => false, 'kod' => 'kart_yok', 'hata' => 'Değiştirilecek kart bulunamadı.'];

    $disTx = $pdo->inTransaction();
    if (!$disTx) $pdo->beginTransaction();
    try {
        $yeni = pdks_kart_olustur((int)$eski['employee_id'], $hamUid, $kaynak,
            ['created_by' => $userId], $pdo);
        if (!$yeni['ok']) {
            if (!$disTx) $pdo->rollBack();
            return $yeni;   // hata kodu/mesajı zaten uygun (ör. uid_kullanimda) — eski kart dokunulmadı
        }
        $pdo->prepare(
            "UPDATE employee_cards
                SET status='degistirildi', revoke_reason=?, revoked_at=NOW(), revoked_by=?, replacement_card_id=?
              WHERE id=?"
        )->execute([$gerekce, $userId, $yeni['card_id'], $eskiCardId]);
        if (!$disTx) $pdo->commit();
    } catch (PDOException $e) {
        if (!$disTx && $pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'kod' => 'yazma_hatasi', 'hata' => $e->getMessage()];
    }

    if (function_exists('audit_log_event')) {
        audit_log_event('card_replaced', 'pdks', (int)$yeni['card_id'],
            ['eski_card_id' => $eskiCardId, 'eski_uid' => $eski['uid_hex']],
            ['yeni_card_id' => $yeni['card_id'], 'yeni_uid' => $yeni['uid_hex'], 'gerekce' => $gerekce]);
    }
    return ['ok' => true, 'card_id' => $yeni['card_id'], 'uid_hex' => $yeni['uid_hex'], 'eski_card_id' => $eskiCardId];
}

// =========================================================
// FAZ 1B — PERSONEL FOTOĞRAFI
//
// hesap_upload_file() (hesap_config.php) ile AYNI güvenlik desenini izler:
// finfo MIME doğrulaması, rastgele ad, .htaccess ile PHP çalıştırma kapalı.
// FARKI: personel fotoğrafı GD ile YENİDEN KODLANIR (piksel verisi yeniden
// çizilir, orijinal bayt akışı asla diske yazılmaz) — bir görsel dosyasının
// içine gömülmüş herhangi bir şey (kötü amaçlı EXIF, polyglot dosya) bu
// adımda düşer. GD yoksa yükleme reddedilir; ham baytlar ASLA saklanmaz.
// =========================================================

defined('PDKS_FOTO_MAX_BOYUT') || define('PDKS_FOTO_MAX_BOYUT', 5 * 1024 * 1024); // 5 MB
defined('PDKS_FOTO_MIME')      || define('PDKS_FOTO_MIME', ['image/jpeg', 'image/png', 'image/webp']);
defined('PDKS_FOTO_MAX_KENAR') || define('PDKS_FOTO_MAX_KENAR', 640); // uzun kenar, px

/**
 * Saf doğrulama — diskteki bir dosyanın gerçekten güvenli bir görsel olup
 * olmadığını kontrol eder. HTTP upload'tan BAĞIMSIZDIR — testte gerçek bir
 * geçici dosya yazıp doğrudan çağırabilirsiniz.
 */
function pdks_foto_gecerli_mi(string $tmpPath, int $boyut): array
{
    if (!is_file($tmpPath) || !is_readable($tmpPath)) {
        return ['ok' => false, 'hata' => 'Dosya okunamadı.'];
    }
    if ($boyut <= 0 || $boyut > PDKS_FOTO_MAX_BOYUT) {
        return ['ok' => false, 'hata' => 'Fotoğraf ' . (int)(PDKS_FOTO_MAX_BOYUT / 1024 / 1024) . " MB'ı aşamaz."];
    }
    $info = @getimagesize($tmpPath);
    if ($info === false || (int)$info[0] < 1 || (int)$info[1] < 1) {
        return ['ok' => false, 'hata' => 'Geçersiz görsel dosyası.'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($tmpPath);
    if (!in_array($mime, PDKS_FOTO_MIME, true)) {
        return ['ok' => false, 'hata' => 'Desteklenmeyen görsel türü (yalnız JPG, PNG, WEBP kabul edilir).'];
    }
    return ['ok' => true, 'mime' => $mime, 'genislik' => (int)$info[0], 'yukseklik' => (int)$info[1]];
}

/**
 * Yüklenen $_FILES['...'] girdisini doğrular, yeniden kodlar (her zaman JPEG)
 * ve PDKS_FOTO_DIR'a kaydeder. Dosya adı ASLA kullanıcıdan gelmez — bin2hex
 * ile rastgele üretilir (path traversal yapısal olarak imkânsız).
 * @return array{ok:bool, file_name?:string, kod?:string, hata?:string}
 */
function pdks_foto_kaydet(array $dosya): array
{
    if (($dosya['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'kod' => 'yok', 'hata' => 'Dosya seçilmedi.'];
    }
    if (($dosya['error'] ?? -1) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'kod' => 'yukleme_hatasi', 'hata' => 'Yükleme sırasında hata oluştu.'];
    }

    $gecerli = pdks_foto_gecerli_mi((string)$dosya['tmp_name'], (int)($dosya['size'] ?? 0));
    if (!$gecerli['ok']) return ['ok' => false, 'kod' => 'gecersiz', 'hata' => $gecerli['hata']];

    if (!function_exists('imagecreatetruecolor')) {
        return ['ok' => false, 'kod' => 'gd_yok', 'hata' => 'Sunucuda görsel işleme kütüphanesi (GD) yok.'];
    }

    if (!is_dir(PDKS_FOTO_DIR)) @mkdir(PDKS_FOTO_DIR, 0755, true);
    $htaccess = PDKS_FOTO_DIR . '.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.php$\">\n  Require all denied\n</FilesMatch>\n");
    }

    $src = match ($gecerli['mime']) {
        'image/jpeg' => @imagecreatefromjpeg($dosya['tmp_name']),
        'image/png'  => @imagecreatefrompng($dosya['tmp_name']),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($dosya['tmp_name']) : false,
        default      => false,
    };
    if (!$src) return ['ok' => false, 'kod' => 'islenemedi', 'hata' => 'Fotoğraf işlenemedi.'];

    $w = $gecerli['genislik']; $h = $gecerli['yukseklik'];
    $olcek = min(1.0, PDKS_FOTO_MAX_KENAR / max($w, $h));
    $nw = max(1, (int)round($w * $olcek));
    $nh = max(1, (int)round($h * $olcek));

    $dst = imagecreatetruecolor($nw, $nh);
    $beyaz = imagecolorallocate($dst, 255, 255, 255);   // şeffaflığı beyaza düzleştir (JPEG'e çevrilecek)
    imagefilledrectangle($dst, 0, 0, $nw, $nh, $beyaz);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    $adSafe = bin2hex(random_bytes(16)) . '.jpg';
    $basarili = imagejpeg($dst, PDKS_FOTO_DIR . $adSafe, 85);
    imagedestroy($dst);

    if (!$basarili) return ['ok' => false, 'kod' => 'yazilamadi', 'hata' => 'Fotoğraf kaydedilemedi.'];
    return ['ok' => true, 'file_name' => $adSafe];
}

/**
 * Bir personel fotoğrafını diskten siler — YALNIZ PDKS'in kendi ürettiği
 * güvenli ada (32 hex + .jpg) uyan dosyalar silinir; başka hiçbir girdi
 * kabul edilmez (path traversal'a yapısal olarak kapalı).
 */
function pdks_foto_sil(?string $fileName): void
{
    if ($fileName === null || $fileName === '') return;
    if (!preg_match('/^[a-f0-9]{32}\.jpg$/', $fileName)) return;
    $yol = PDKS_FOTO_DIR . $fileName;
    if (is_file($yol)) @unlink($yol);
}

/**
 * Personel fotoğrafı <img> veya, foto yoksa, ad-soyaddan baş harflerle
 * oluşan yuvarlak bir "fallback avatar" döndürür — hiçbir zaman kırık
 * resim ikonu göstermez.
 */
function pdks_avatar_html(string $adSoyad, ?string $fotoFile, ?string $fotoGuncelleme, string $base = '', string $sinif = 'pdks-avatar'): string
{
    if ($fotoFile) {
        $v = $fotoGuncelleme !== null ? (string)strtotime($fotoGuncelleme) : '0';
        return '<img src="' . h($base) . 'personel_foto.php?f=' . h($fotoFile) . '&v=' . h($v) . '"'
             . ' class="' . h($sinif) . '" alt="" loading="lazy">';
    }
    $parcalar = preg_split('/\s+/', trim($adSoyad)) ?: [];
    $harfler = '';
    foreach (array_slice($parcalar, 0, 2) as $p) {
        if ($p !== '') $harfler .= mb_strtoupper(mb_substr($p, 0, 1, 'UTF-8'), 'UTF-8');
    }
    if ($harfler === '') $harfler = '?';
    return '<span class="' . h($sinif) . ' ' . h($sinif) . '-bos" aria-hidden="true">' . h($harfler) . '</span>';
}
