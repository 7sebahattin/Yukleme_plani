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
// FAZ 1 KAPSAMI (bu dosya): çavuş ana kaydı + işçi tipi/kategori master'ı +
// yeniden kullanılabilir işçi kart havuzu + temel yönetim arayüzü altyapısı.
// Günlük iş oturumu / kart-giriş-çıkış eşleştirme/muhasebe FAZ 2+'DADIR —
// burada YOKTUR (bkz. dosya sonundaki "FAZ 2 ŞEMA ÖNERİSİ" notu — YALNIZ
// belge, migrate edilmez).
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

/** Kart havuzu yaşam döngüsü durumları. Basit ve genişletilebilir tutulur. */
function pdks_gunluk_kart_durumlari(): array
{
    return [
        'available' => 'Boşta (kullanılabilir)',
        'in_use'    => 'Kullanımda',
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
    $t['foremen'] = "CREATE TABLE IF NOT EXISTS `foremen` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `code`       VARCHAR(20)  NOT NULL,
        `name`       VARCHAR(150) NOT NULL,
        `phone`      VARCHAR(30)  NULL DEFAULT NULL,
        `notes`      TEXT         NULL DEFAULT NULL,
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
    // "do not silently alter existing employee-card data") gerçek çapraz-
    // sistem benzersizliği yalnız İKİ katmanla sağlanır:
    //   1) Bu tablonun KENDİ UNIQUE kısıtı (`canonical_uid`) — havuz-içi çakışma.
    //   2) UYGULAMA KATMANINDA, YAZMADAN ÖNCE, HER İKİ YÖNDE çapraz kontrol:
    //      a) Yeni işçi kartı yazılırken → mevcut employee_card_uids'te var mı?
    //         (pdks_gunluk_uid_kalici_kartta_mi() — salt okunur SELECT, mevcut
    //         tabloyu hiç DEĞİŞTİRMEZ.)
    //      b) Yeni KALICI personel kartı yazılırken → bu tabloda var mı?
    //         (pdks_gunluk_uid_gecici_kartta_mi(), pdks_kart_olustur() içinden
    //         function_exists guard'lı YUMUŞAK çağrı — bkz. dosya başlığı.)
    //   Bu, iki yazma anı arasında teorik bir yarış koşulunu (iki INSERT'in
    //   aynı anda geçmesi) tam ORTADAN KALDIRMAZ — ama iki tablonun kendi
    //   UNIQUE kısıtları + bu ön-kontrol, pratikte (tek yönetici arayüzü,
    //   düşük yazma sıklığı) çakışmayı YAKALAR ve HER İKİ tarafa da AÇIK,
    //   Türkçe bir hata mesajıyla REDDEDER. Gerçek atomik çapraz-tablo
    //   benzersizliği isteniyorsa ileride PAYLAŞILAN bir `card_uid_registry`
    //   tablosu gerekir — Faz 1 kapsamı dışında (aşağıdaki Faz 2 notuna bkz.).
    $t['worker_cards'] = "CREATE TABLE IF NOT EXISTS `worker_cards` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `card_no`         VARCHAR(30)  NOT NULL,
        `worker_type_id`  INT          NOT NULL,
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

    return $t;
}

/** Bir tablo var mı? (pdks_tablo_var() ile aynı desen, bilerek KOPYALANDI —
 *  bu dosya config/pdks.php'ye SESSİZCE bağımlı OLMAMALI, bkz. başlık.) */
function pdks_gunluk_tablo_var(PDO $pdo, string $tablo): bool
{
    try { $pdo->query("SELECT 1 FROM `{$tablo}` LIMIT 0"); return true; }
    catch (PDOException $e) { return false; }
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
        'foremen'      => can('attendance.foremen'),
        'worker_cards' => can('attendance.worker_cards'),
        default        => false,
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
// ÇAPRAZ-SİSTEM UID ÇAKIŞMA KONTROLÜ
// =========================================================

/**
 * Bu kanonik UID, KALICI personel kart sisteminde (employee_card_uids —
 * config/pdks.php) zaten tanımlı mı? Salt okunur — o tabloyu hiç DEĞİŞTİRMEZ.
 * Yeni bir işçi-havuzu kartı yazılmadan ÖNCE çağrılır.
 */
function pdks_gunluk_uid_kalici_kartta_mi(string $kanonik, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? db();
    try {
        $st = $pdo->prepare(
            "SELECT c.id AS card_id, c.employee_id, e.full_name
               FROM employee_card_uids u
               JOIN employee_cards c ON c.id = u.card_id
               JOIN employees e ON e.id = c.employee_id
              WHERE u.uid_hex = ? LIMIT 1"
        );
        $st->execute([$kanonik]);
        return $st->fetch() ?: null;
    } catch (PDOException $e) {
        return null;   // employee_cards/employee_card_uids yoksa çakışma da yok
    }
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
    $st = $pdo->prepare("UPDATE worker_types SET is_active = ? WHERE id = ?");
    $st->execute([$aktif ? 1 : 0, $id]);
    if ($st->rowCount() === 0) return ['ok' => false, 'hata' => 'İşçi tipi bulunamadı.'];
    if (function_exists('audit_log_event')) {
        audit_log_event('update', 'worker_types', $id, null, ['is_active' => $aktif ? 1 : 0]);
    }
    return ['ok' => true];
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
    $workerTypeId = (int)($veri['worker_type_id'] ?? 0);
    if ($workerTypeId <= 0) {
        return ['ok' => false, 'kod' => 'tip_yok', 'hata' => 'İşçi tipi seçmelisiniz.'];
    }
    $st = $pdo->prepare("SELECT id FROM worker_types WHERE id = ? AND is_active = 1");
    $st->execute([$workerTypeId]);
    if (!$st->fetchColumn()) {
        return ['ok' => false, 'kod' => 'tip_bulunamadi', 'hata' => 'Seçilen işçi tipi bulunamadı veya pasif.'];
    }

    $stC = $pdo->prepare("SELECT id FROM worker_cards WHERE card_no = ?");
    $stC->execute([$cardNo]);
    if ($stC->fetchColumn()) {
        return ['ok' => false, 'kod' => 'kart_no_kullanimda', 'hata' => 'Bu kart numarası zaten kullanımda: ' . $cardNo];
    }

    // ⚠ ÇAPRAZ-SİSTEM KONTROLÜ — yön 1: kalıcı personel kartlarıyla çakışma.
    $kaliciCakisma = pdks_gunluk_uid_kalici_kartta_mi($kanonik, $pdo);
    if ($kaliciCakisma !== null) {
        return ['ok' => false, 'kod' => 'uid_kalici_kartta',
                'hata' => 'Bu kart zaten KALICI PERSONEL kartı olarak tanımlı (' . (string)$kaliciCakisma['full_name'] . '). '
                        . 'Aynı fiziksel kart hem kalıcı personelde hem işçi havuzunda olamaz.'];
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
        // Son çare — UNIQUE kısıtı bir yarış koşulunda burada yakalanır.
        return ['ok' => false, 'kod' => 'yazma_hatasi', 'hata' => 'Kart kaydedilemedi: ' . $e->getMessage()];
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
    $workerTypeId = (int)($veri['worker_type_id'] ?? 0);
    if ($workerTypeId <= 0) return ['ok' => false, 'hata' => 'İşçi tipi seçmelisiniz.'];

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
// FAZ 2 ŞEMA ÖNERİSİ — YALNIZ BELGE, BURADA MIGRATE EDİLMEZ
// =========================================================
//
// Kullanıcının onayı olmadan bu bölümdeki hiçbir SQL çalıştırılmaz. Faz 1
// tabloları BUNLARI önceden karşılayacak biçimde tasarlandı (aşağıya bkz.):
//
// daily_work_sessions            -- foreman_id, work_date, status(open/closed),
//                                    opened_at, opened_by, closed_at, closed_by,
//                                    depo, notes
//                                    UNIQUE (foreman_id, work_date, depo) —
//                                    aynı çavuşun aynı gün/depoda İKİNCİ bir
//                                    açık oturumu olmasın diye.
//
// daily_worker_card_events       -- session_id (FK daily_work_sessions),
//                                    worker_card_id (FK worker_cards),
//                                    event_type ('GIRIS'|'CIKIS' — mevcut
//                                    attendance_events.event_type ile AYNI
//                                    sözlük, kod tekrarı değil KAVRAM ortaklığı),
//                                    source, canonical_uid_snapshot,
//                                    recorded_by_user_id, server_event_time
//                                    INDEX (session_id, worker_card_id, event_type)
//                                    — "hangi kartların çıkışı eksik" sorgusu
//                                    session_id + card bazında GIRIS var, CIKIS
//                                    yok satırlarını bulur (attendance_events'in
//                                    kendi mükerrer-kontrol desenine benzer).
//
// Neden Faz 1 şeması bunu zorlamadan karşılıyor:
//   • worker_cards employee_id TAŞIMAZ — Faz 2'de bir event doğrudan
//     worker_card_id + session_id'ye bağlanır, kart kişiye değil oturuma bağlıdır.
//   • foremen.id, daily_work_sessions.foreman_id için hazır FK hedefi.
//   • worker_cards.status ('in_use') Faz 2'nin GIRIS anında set edip CIKIS
//     anında 'available'a döndüreceği alan — Faz 1 bunu YAZMAZ, yalnız
//     sütunu barındırır.
//   • worker_types.id, Faz 2 hakediş/fiyatlama tablosunun (ör.
//     worker_type_rates: worker_type_id, foreman_id?, unit_price, valid_from)
//     doğal FK hedefi.
