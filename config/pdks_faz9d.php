<?php
// =========================================================
// config/pdks_faz9d.php — İLERİYE DÖNÜK HAKEDİŞ DÜZELTME / MAHSUP (Faz 9D)
//
// ⚠ v227 sistem audit'inin H-03 bulgusunu kapatır: "ödeme sonrası bir
// hakediş düzeltmesi gerekirse ne olur?" sorusuna KESİN (final) kaydı
// yeniden AÇMADAN/DEĞİŞTİRMEDEN cevap verir.
//
// İş kararı (kullanıcının açık talimatı — KESİN):
//   Zaten KESİNLEŞMİŞ bir hakedişi, ödeme sonrası bir düzeltme gerekiyor
//   diye yeniden AÇMA/DEĞİŞTİRME. Mevcut yeniden-açma/ödeme koruması
//   (pdks_hakedis_yeniden_ac / pdks_cari_odeme_var_mi çapraz kontrolü)
//   ZAYIFLATILMAZ. Bunun yerine düzeltme, orijinal KESİN hakedişe bağlı,
//   AYRI, İMZALI (+/-) bir finansal katmandır:
//     - foreman_daily_entitlements (total_amount/status/satırlar) HİÇ
//       DEĞİŞMEZ — bu tablo BU DOSYADAN asla UPDATE edilmez.
//     - daily_worker_work_periods / attendance HİÇ DOKUNULMAZ.
//     - Cari bakiye SU DOSYANIN TEK yeni tablosunu (foreman_entitlement_
//       adjustments) KESİN hakediş + GEÇERLİ ödeme formülüne EKLER (bkz.
//       config/pdks_cari.php / config/pdks_rapor.php — "TEK doğru formül"
//       ilkesi, ikinci bir mutasyona açık defter AÇILMAZ).
//
// ⚠ DEĞİŞMEZLİK (foreman_payments İLE AYNI ilke, kullanıcının açık
// talimatı: "There must never be a silent UPDATE from +2500 to +1500."):
// bir düzeltmenin signed_amount/reason'ı KAYDEDİLDİKTEN SONRA bir daha
// ASLA UPDATE edilmez — bu dosyada böyle bir yol YOK. Satır ASLA SİLİNMEZ.
// Yanlış bir düzeltme YENİ, ters işaretli bir TERS KAYIT (reversal) ile
// nötrlenir — orijinal satır (created_at/created_by_user_id/reason/
// signed_amount) DOKUNULMADAN kalır, yalnız reversed_at/reversed_by_user_id/
// reversal_reason METADATA'sı eklenir (bkz. pdks_faz9d_duzeltme_ters_kayit()).
// Bakiye formülü HER İKİ satırı da (orijinal + ters kayıt) `status='valid'`
// olarak toplar — ters işaretli oldukları için NET etkileri sıfırlanır,
// ama İKİSİ de kalıcı/denetlenebilir kalır (kullanıcının açık talimatı,
// madde 6/14E: "both historical events remain auditable").
//
// ⚠ BU DOSYA config/db.php / config/helpers.php TARAFINDAN YÜKLENMEZ —
//    config/pdks_cari.php'nin AYNI gerekçesi. Kendiliğinden migrate OLMAZ
//    — yalnız migrate.php'den AÇIKÇA çağrılır. config/pdks_hakedis.php'ye
//    TEK YÖNLÜ SERT bağımlılık (kuruş yardımcıları + entitlements_finalize
//    izni REUSE edilir — YENİ bir izin İCAT EDİLMEZ, görev talimatı).
// =========================================================

declare(strict_types=1);

require_once __DIR__ . '/pdks_hakedis.php';

defined('PDKS_FAZ9D_AKTIF') || define('PDKS_FAZ9D_AKTIF', true);

// =========================================================
// ŞEMA
// =========================================================

function pdks_faz9d_tablolar(): array
{
    $t = [];

    // ── foreman_entitlement_adjustments — İLERİYE DÖNÜK, imzalı (+/-)
    // hakediş düzeltmesi. `status` şimdilik HER ZAMAN 'valid' kalır — bir
    // düzeltmenin "geri alınması" satırın kendisini geçersiz SAYMAZ
    // (SUM formülünden DIŞLANMAZ), ters işaretli YENİ bir satırla NET
    // olarak sıfırlanır (bkz. dosya başlığı). Bu yüzden bakiye formülü
    // basit kalır: SUM(signed_amount) WHERE status='valid' — özel bir
    // "reversed hariç tut" dalı GEREKMEZ.
    $t['foreman_entitlement_adjustments'] = "CREATE TABLE IF NOT EXISTS `foreman_entitlement_adjustments` (
        `id`                        INT AUTO_INCREMENT PRIMARY KEY,
        `entitlement_id`            INT           NOT NULL,
        `foreman_id`                INT           NOT NULL,
        `foreman_name_snapshot`     VARCHAR(150)  NOT NULL DEFAULT '',
        `foreman_code_snapshot`     VARCHAR(20)   NOT NULL DEFAULT '',
        `work_date`                 DATE          NOT NULL,
        `currency`                  VARCHAR(10)   NOT NULL DEFAULT 'TRY',
        `signed_amount`             DECIMAL(14,2) NOT NULL,
        `reason`                    TEXT          NOT NULL,
        `status`                    VARCHAR(20)   NOT NULL DEFAULT 'valid',
        `created_by_user_id`        INT           NULL DEFAULT NULL,
        `created_at`                DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `reversal_of_adjustment_id` INT           NULL DEFAULT NULL,
        `reversed_at`               DATETIME      NULL DEFAULT NULL,
        `reversed_by_user_id`       INT           NULL DEFAULT NULL,
        `reversal_reason`           TEXT          NULL DEFAULT NULL,
        INDEX `idx_fea_entitlement` (`entitlement_id`),
        INDEX `idx_fea_foreman`     (`foreman_id`),
        INDEX `idx_fea_status`      (`status`),
        INDEX `idx_fea_reversal_of` (`reversal_of_adjustment_id`),
        CONSTRAINT `fk_fea_entitlement` FOREIGN KEY (`entitlement_id`)
            REFERENCES `foreman_daily_entitlements`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT `fk_fea_foreman` FOREIGN KEY (`foreman_id`)
            REFERENCES `foremen`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT `fk_fea_reversal` FOREIGN KEY (`reversal_of_adjustment_id`)
            REFERENCES `foreman_entitlement_adjustments`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    return $t;
}

/** pdks_cari_tablo_var() ile AYNI desen, bilerek KOPYALANDI (bkz. başlık). */
function pdks_faz9d_tablo_var(PDO $pdo, string $tablo): bool
{
    try { $pdo->query("SELECT 1 FROM `{$tablo}` LIMIT 0"); return true; }
    catch (PDOException $e) { return false; }
}

/**
 * Şema migrasyonu — IDEMPOTENT, yıkıcı DEĞİL, YALNIZ additive CREATE TABLE.
 * Faz 1-8 tablolarına HİÇ DOKUNMAZ. KENDİLİĞİNDEN ÇALIŞMAZ — yalnız
 * migrate.php'nin kontrollü admin aksiyonundan çağrılır.
 */
function pdks_faz9d_migrate(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $rapor = [];
    foreach (pdks_faz9d_tablolar() as $ad => $sql) {
        if (pdks_faz9d_tablo_var($pdo, $ad)) {
            $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.'];
            continue;
        }
        try {
            $pdo->exec($sql);
            $rapor[] = pdks_faz9d_tablo_var($pdo, $ad)
                ? ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.']
                : ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'CREATE çalıştı ama tablo görünmüyor.'];
        } catch (PDOException $e) {
            error_log('[pdks_faz9d_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => $e->getMessage()];
        }
    }
    return $rapor;
}

function pdks_faz9d_sema_hazir(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? db();
    foreach (array_keys(pdks_faz9d_tablolar()) as $ad) {
        if (!pdks_faz9d_tablo_var($pdo, $ad)) return false;
    }
    return true;
}

/** pdks_cari_sayfa_kapisi() ile AYNI desen. */
function pdks_faz9d_sayfa_kapisi(?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    if (pdks_faz9d_sema_hazir($pdo)) return;

    $mesaj = 'Hakediş düzeltme/mahsup modülü tabloları henüz oluşturulmamış. Bir yöneticinin '
           . 'migrate.php sayfasından "Faz 9D Düzeltme Tablolarını Oluştur" demesi gerekiyor.';
    if (function_exists('set_flash')) set_flash('error', $mesaj);
    if (function_exists('render_header')) render_header('Hakediş Düzeltme');
    if (function_exists('render_flash')) {
        render_flash();
    } elseif (function_exists('h')) {
        echo '<div class="flash flash-error">' . h($mesaj) . '</div>';
    }
    if (function_exists('render_footer')) render_footer();
    exit;
}

// =========================================================
// DÜZELTME (foreman_entitlement_adjustments) — TEK YAZMA YOLU + TERS KAYIT
//
// ⚠ YETKİ: YENİ bir izin İCAT EDİLMEDİ (görev talimatı) — mevcut
// 'entitlements_finalize' (Faz 4'ün KESİNLEŞTİRME izni) REUSE edilir. Bu
// zaten "ticari fiyat yönetimi + hakediş" kesişimidir (bkz. pdks_hakedis.php)
// — bir KESİN kaydı finansal olarak etkileyecek en yakın mevcut yetki katmanı.
//
// ⚠ DEPO: BİLEREK depo kontrolü YOK (görev talimatı: "do not create a new
// depot restriction that conflicts with current cross-depot foreman-account
// design") — foreman_payments/pdks_cari_* AYNI şekilde depo-bağımsızdır
// (bir çavuşun hesabı TÜM depoları kapsar). Bu fonksiyonu çağıran TEK sayfa
// (cavus_hakedis_detay.php) zaten KENDİ üst seviye depo kontrolünü yapar —
// bu, hangi hakedişin GÖRÜNTÜLENEBİLECEĞİNİ sınırlar, düzeltmenin kendisini
// İKİNCİ bir depo kısıtına TABİ TUTMAZ.
// =========================================================

/**
 * @param string $yon '+' (artır) veya '-' (azalt/mahsup) — kullanıcı elle
 *        eksi işareti YAZMAZ (görev talimatı), yön burada işarete çevrilir.
 * @param string $tutarHam Kullanıcının girdiği POZİTİF tutar (ör. "2500,50").
 */
function pdks_faz9d_duzeltme_ekle(
    int $entitlementId,
    string $yon,
    string $tutarHam,
    string $sebep,
    int $userId,
    ?PDO $pdo = null
): array {
    $pdo = $pdo ?? db();
    if (!pdks_hakedis_can('entitlements_finalize')) {
        return ['ok' => false, 'kod' => 'yetkisiz', 'hata' => 'Hakediş düzeltmesi için yetkiniz yok.'];
    }
    if (!pdks_faz9d_sema_hazir($pdo)) {
        return ['ok' => false, 'kod' => 'sema_yok', 'hata' => 'Faz 9D şeması hazır değil.'];
    }
    $yon = trim($yon);
    if (!in_array($yon, ['+', '-'], true)) {
        return ['ok' => false, 'kod' => 'gecersiz_yon', 'hata' => 'Yön Artır (+) veya Azalt/Mahsup (-) olmalıdır.'];
    }
    $sebep = trim($sebep);
    if ($sebep === '') {
        return ['ok' => false, 'kod' => 'gerekce_zorunlu', 'hata' => 'Düzeltme gerekçesi zorunludur.'];
    }
    // Kullanıcı HER ZAMAN pozitif bir tutar girer (görev talimatı: "Do NOT
    // ask the user to type a negative sign manually") — 0 veya negatif
    // GİRDİ reddedilir, işaret yalnız $yon'dan gelir.
    $kurus = pdks_hakedis_girdi_kurus($tutarHam);
    if ($kurus === null || $kurus <= 0) {
        return ['ok' => false, 'kod' => 'gecersiz_tutar', 'hata' => 'Tutar sıfırdan büyük olmalıdır. Örnek: 2500 veya 2500,50'];
    }
    $signedKurus = $yon === '-' ? -$kurus : $kurus;

    // ⚠ Çavuş/para birimi İSTEMCİDEN ASLA ALINMAZ — YALNIZ referans verilen
    // KESİN hakedişin KENDİ dondurulmuş satırından okunur (görev talimatı
    // madde 4/8: "Referenced foreman must come from the final entitlement
    // snapshot... Currency comes from entitlement... client cannot change
    // foreman/currency"). Bu fonksiyonun imzasında foreman_id/currency
    // parametresi YOKTUR — istemci onu HİÇBİR ŞEKİLDE gönderemez.
    $st = $pdo->prepare("SELECT * FROM foreman_daily_entitlements WHERE id = ?");
    $st->execute([$entitlementId]);
    $ent = $st->fetch();
    if (!$ent) return ['ok' => false, 'kod' => 'hakedis_yok', 'hata' => 'Hakediş kaydı bulunamadı.'];
    if ((string)$ent['status'] !== 'final') {
        return ['ok' => false, 'kod' => 'kesin_degil', 'hata' => 'Düzeltme yalnız KESİNLEŞMİŞ (final) hakedişlere eklenebilir.'];
    }

    $simdi = date('Y-m-d H:i:s');
    if ($pdo->inTransaction()) {
        return ['ok' => false, 'kod' => 'ic_ice_islem', 'hata' => 'Düzeltme ayrı bir işlem olarak kaydedilmelidir.'];
    }
    try {
        $pdo->beginTransaction();
        $ins = $pdo->prepare(
            "INSERT INTO foreman_entitlement_adjustments
                (entitlement_id, foreman_id, foreman_name_snapshot, foreman_code_snapshot,
                 work_date, currency, signed_amount, reason, status, created_by_user_id, created_at)
             VALUES (?,?,?,?,?,?,?,?,'valid',?,?)"
        );
        $ins->execute([
            $entitlementId, (int)$ent['foreman_id'], (string)$ent['foreman_name_snapshot'], (string)$ent['foreman_code_snapshot'],
            (string)$ent['work_date'], (string)$ent['currency'], pdks_hakedis_kurus_tl($signedKurus), $sebep, $userId, $simdi,
        ]);
        $id = (int)$pdo->lastInsertId();

        // ⚠ Faz 8E/8J İLE AYNI "denetim zorunlu" desenİ (bkz. o dosyaların
        // docblock'u): finansal bir mutasyon için audit_log_event() (hata
        // YUTAR, bkz. helpers.php) DEĞİL, AYNI transaction içinde DOĞRUDAN
        // INSERT — denetim kaydı başarısız olursa düzeltme de GERİ ALINIR
        // (görev talimatı madde 12).
        $pdo->prepare(
            'INSERT INTO audit_log (user_id, action, module, record_id, old_values, new_values, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, 'create', 'foreman_entitlement_adjustments', $id, null,
            json_encode([
                'entitlement_id' => $entitlementId, 'foreman_id' => (int)$ent['foreman_id'],
                'signed_amount' => pdks_hakedis_kurus_tl($signedKurus), 'currency' => (string)$ent['currency'],
                'reason' => $sebep, 'created_at' => $simdi,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $_SERVER['REMOTE_ADDR'] ?? null, substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'kod' => 'yazim_hatasi', 'hata' => 'Düzeltme kaydedilemedi: ' . $e->getMessage()];
    }

    return ['ok' => true, 'id' => $id, 'signed_amount' => pdks_hakedis_kurus_tl($signedKurus), 'currency' => (string)$ent['currency']];
}

/**
 * Yanlış bir düzeltmeyi NÖTRLER — SATIRI SİLMEZ/DEĞİŞTİRMEZ (görev
 * talimatı: "Do NOT allow editing... Do NOT physical DELETE"). Orijinalin
 * TAM ters işaretli YENİ bir satırı yazılır (reversal_of_adjustment_id ile
 * bağlanır) ve orijinal satıra YALNIZ reversed_at/reversed_by_user_id/
 * reversal_reason METADATA'sı eklenir — signed_amount/reason/created_at
 * ASLA dokunulmaz.
 */
function pdks_faz9d_duzeltme_ters_kayit(int $adjustmentId, string $sebep, int $userId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_hakedis_can('entitlements_finalize')) {
        return ['ok' => false, 'kod' => 'yetkisiz', 'hata' => 'Düzeltme ters kaydı için yetkiniz yok.'];
    }
    if (!pdks_faz9d_sema_hazir($pdo)) {
        return ['ok' => false, 'kod' => 'sema_yok', 'hata' => 'Faz 9D şeması hazır değil.'];
    }
    $sebep = trim($sebep);
    if ($sebep === '') {
        return ['ok' => false, 'kod' => 'gerekce_zorunlu', 'hata' => 'Ters kayıt gerekçesi zorunludur.'];
    }

    $st = $pdo->prepare("SELECT * FROM foreman_entitlement_adjustments WHERE id = ?");
    $st->execute([$adjustmentId]);
    $orig = $st->fetch();
    if (!$orig) return ['ok' => false, 'kod' => 'duzeltme_yok', 'hata' => 'Düzeltme kaydı bulunamadı.'];
    if ($orig['reversal_of_adjustment_id'] !== null) {
        return ['ok' => false, 'kod' => 'ters_kaydin_tersi', 'hata' => 'Bir ters kayıt tekrar ters kayda alınamaz.'];
    }
    if ($orig['reversed_at'] !== null) {
        return ['ok' => false, 'kod' => 'zaten_ters_kayitli', 'hata' => 'Bu düzeltme zaten ters kayıtla nötrlenmiş.'];
    }

    $simdi = date('Y-m-d H:i:s');
    $tersKurus = -pdks_hakedis_tl_kurus((string)$orig['signed_amount']);
    if ($pdo->inTransaction()) {
        return ['ok' => false, 'kod' => 'ic_ice_islem', 'hata' => 'Ters kayıt ayrı bir işlem olarak kaydedilmelidir.'];
    }
    try {
        $pdo->beginTransaction();
        $ins = $pdo->prepare(
            "INSERT INTO foreman_entitlement_adjustments
                (entitlement_id, foreman_id, foreman_name_snapshot, foreman_code_snapshot,
                 work_date, currency, signed_amount, reason, status, created_by_user_id, created_at,
                 reversal_of_adjustment_id)
             VALUES (?,?,?,?,?,?,?,?,'valid',?,?,?)"
        );
        $ins->execute([
            (int)$orig['entitlement_id'], (int)$orig['foreman_id'], (string)$orig['foreman_name_snapshot'], (string)$orig['foreman_code_snapshot'],
            (string)$orig['work_date'], (string)$orig['currency'], pdks_hakedis_kurus_tl($tersKurus),
            'Ters kayıt — ' . $sebep, $userId, $simdi, $adjustmentId,
        ]);
        $tersId = (int)$pdo->lastInsertId();

        $upd = $pdo->prepare(
            "UPDATE foreman_entitlement_adjustments
                SET reversed_at = ?, reversed_by_user_id = ?, reversal_reason = ?
              WHERE id = ? AND reversed_at IS NULL"
        );
        $upd->execute([$simdi, $userId, $sebep, $adjustmentId]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            return ['ok' => false, 'kod' => 'yarisma', 'hata' => 'Bu düzeltme başka bir işlemle zaten ters kayda alınmış. Sayfayı yenileyin.'];
        }

        $pdo->prepare(
            'INSERT INTO audit_log (user_id, action, module, record_id, old_values, new_values, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, 'reverse', 'foreman_entitlement_adjustments', $adjustmentId,
            json_encode(['signed_amount' => $orig['signed_amount'], 'reversed_at' => null], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode([
                'reversal_adjustment_id' => $tersId, 'reversal_signed_amount' => pdks_hakedis_kurus_tl($tersKurus),
                'currency' => (string)$orig['currency'], 'reason' => $sebep, 'reversed_at' => $simdi,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $_SERVER['REMOTE_ADDR'] ?? null, substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'kod' => 'yazim_hatasi', 'hata' => 'Ters kayıt yazılamadı: ' . $e->getMessage()];
    }

    return ['ok' => true, 'reversal_id' => $tersId, 'signed_amount' => pdks_hakedis_kurus_tl($tersKurus)];
}

/** cavus_hakedis_detay.php için — TEK bir hakedişe bağlı TÜM düzeltmeler
 *  (orijinal + ters kayıt satırları), kronolojik (en eski önce). */
function pdks_faz9d_duzeltmeler(int $entitlementId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_faz9d_sema_hazir($pdo)) return [];
    $st = $pdo->prepare(
        "SELECT * FROM foreman_entitlement_adjustments WHERE entitlement_id = ? ORDER BY created_at ASC, id ASC"
    );
    $st->execute([$entitlementId]);
    return $st->fetchAll();
}

/** Bir hakedişe bağlı NET (geçerli) düzeltme toplamı, kuruş — UI'da
 *  "Net Düzeltilmiş Hakediş Tutarı" için (bkz. cavus_hakedis_detay.php). */
function pdks_faz9d_entitlement_net_kurus(int $entitlementId, ?PDO $pdo = null): int
{
    $pdo = $pdo ?? db();
    if (!pdks_faz9d_sema_hazir($pdo)) return 0;
    $st = $pdo->prepare("SELECT signed_amount FROM foreman_entitlement_adjustments WHERE entitlement_id = ? AND status = 'valid'");
    $st->execute([$entitlementId]);
    $toplam = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tutar) {
        $toplam += pdks_hakedis_tl_kurus((string)$tutar);
    }
    return $toplam;
}
