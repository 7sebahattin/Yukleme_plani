<?php
// =========================================================
// config/pdks_cari.php — ÇAVUŞ CARİ HESABI (Günlük İşçi, Faz 5) ÇEKİRDEĞİ
//
// İş modeli (kullanıcı açıklaması, Sprint Günlük-İşçi-06):
//   Bir çavuşun şirkete olan alacağı YALNIZ Faz 4'ün KESİN (final) hakediş
//   kayıtlarıyla OLUŞUR. Ödemeler bu alacağı AZALTIR. Bakiye = Σ KESİN
//   hakediş - Σ GEÇERLİ (iptal edilmemiş) ödeme, PARA BİRİMİ bazında.
//
// ⚠ MİMARİ İLKE (kullanıcının açık talimatı): "Phase 4 is the financial
// source of entitlement. Do NOT create another entitlement calculation."
// ve "Prefer deriving entitlement-side movements from immutable FINAL
// Phase 4 records" — bu dosya foreman_daily_entitlements'a İKİNCİ bir
// mutasyona açık finansal defter KOPYALAMAZ. Bakiye/ekstre HER ZAMAN
// foreman_daily_entitlements (yalnız status='final') + BU dosyanın TEK
// yeni tablosu (foreman_payments) üzerinden CANLI TÜRETİLİR.
//
// ⚠ BU DOSYA config/db.php / config/helpers.php TARAFINDAN YÜKLENMEZ —
//    config/pdks_gunluk.php/pdks_hakedis.php'nin AYNI gerekçesi. Kendiliğinden
//    migrate OLMAZ — yalnız migrate.php'den AÇIKÇA çağrılır.
//
// ⚠ config/pdks_hakedis.php'YE TEK YÖNLÜ, SERT BAĞIMLILIK (Faz 4'ün
//    pdks_gunluk.php'ye bağımlılığıyla AYNI ilke): hakediş verisi olmadan
//    cari hesap anlamsızdır. TERS yön YOKTUR — config/pdks_hakedis.php bu
//    dosyayı asla require ETMEZ; yalnız pdks_hakedis_yeniden_ac() içinde
//    YUMUŞAK (function_exists) bir çapraz kontrol çağırır (bkz. o dosyanın
//    docblock'u) — pdks.php↔pdks_gunluk.php İLE AYNI desen.
// =========================================================

declare(strict_types=1);

defined('PDKS_CARI_AKTIF') || define('PDKS_CARI_AKTIF', true);

// =========================================================
// ŞEMA
// =========================================================

function pdks_cari_tablolar(): array
{
    $t = [];

    // ── foreman_payments — çavuşa yapılan ödeme kaydı ──────────
    // ⚠ DEĞİŞMEZLİK (kullanıcının açık talimatı): amount/foreman_id/
    // payment_date/currency KAYDEDİLDİKTEN SONRA SESSİZCE değiştirilmez —
    // bu tabloda BİLEREK bir UPDATE yolu YOK (yalnız durumu 'cancelled'
    // yapan pdks_cari_odeme_iptal(), bkz. aşağısı). SATIR ASLA SİLİNMEZ.
    // foreman_name/code_snapshot: Faz 3/4'ün AYNI ilkesi — çavuş adı
    // SONRADAN değişse bile eski ödeme kaydı tarihsel kalır.
    $t['foreman_payments'] = "CREATE TABLE IF NOT EXISTS `foreman_payments` (
        `id`                    INT           AUTO_INCREMENT PRIMARY KEY,
        `foreman_id`            INT           NOT NULL,
        `foreman_name_snapshot` VARCHAR(150)  NOT NULL DEFAULT '',
        `foreman_code_snapshot` VARCHAR(20)   NOT NULL DEFAULT '',
        `payment_date`          DATE          NOT NULL,
        `amount`                DECIMAL(14,2) NOT NULL,
        `currency`              VARCHAR(10)   NOT NULL DEFAULT 'TRY',
        `payment_method`        VARCHAR(20)   NOT NULL DEFAULT 'OTHER',
        `reference_no`          VARCHAR(100)  NULL DEFAULT NULL,
        `description`           TEXT          NULL DEFAULT NULL,
        `status`                VARCHAR(20)   NOT NULL DEFAULT 'valid',
        `created_by_user_id`    INT           NULL DEFAULT NULL,
        `created_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `cancelled_at`          DATETIME      NULL DEFAULT NULL,
        `cancelled_by_user_id`  INT           NULL DEFAULT NULL,
        `cancellation_reason`   TEXT          NULL DEFAULT NULL,
        INDEX `idx_fp_foreman` (`foreman_id`),
        INDEX `idx_fp_date`    (`payment_date`),
        INDEX `idx_fp_status`  (`status`),
        CONSTRAINT `fk_fp_foreman` FOREIGN KEY (`foreman_id`)
            REFERENCES `foremen`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    return $t;
}

/** pdks_gunluk_tablo_var()/pdks_hakedis_tablo_var() ile AYNI desen, bilerek KOPYALANDI. */
function pdks_cari_tablo_var(PDO $pdo, string $tablo): bool
{
    try { $pdo->query("SELECT 1 FROM `{$tablo}` LIMIT 0"); return true; }
    catch (PDOException $e) { return false; }
}

/**
 * Şema migrasyonu — IDEMPOTENT, yıkıcı DEĞİL, YALNIZ additive CREATE TABLE.
 * Faz 1-4 tablolarına HİÇ DOKUNMAZ (kullanıcının açık talimatı: "Phase 1-4
 * tables may already exist and contain production data. Do NOT drop/
 * recreate them."). KENDİLİĞİNDEN ÇALIŞMAZ — yalnız migrate.php'nin
 * kontrollü admin aksiyonundan çağrılır.
 */
function pdks_cari_migrate(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $rapor = [];
    foreach (pdks_cari_tablolar() as $ad => $sql) {
        if (pdks_cari_tablo_var($pdo, $ad)) {
            $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.'];
            continue;
        }
        try {
            $pdo->exec($sql);
            $rapor[] = pdks_cari_tablo_var($pdo, $ad)
                ? ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.']
                : ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'CREATE çalıştı ama tablo görünmüyor.'];
        } catch (PDOException $e) {
            error_log('[pdks_cari_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => $e->getMessage()];
        }
    }
    return $rapor;
}

function pdks_cari_sema_hazir(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? db();
    foreach (array_keys(pdks_cari_tablolar()) as $ad) {
        if (!pdks_cari_tablo_var($pdo, $ad)) return false;
    }
    return true;
}

/** pdks_gunluk_sayfa_kapisi()/pdks_hakedis_sayfa_kapisi() ile AYNI desen. */
function pdks_cari_sayfa_kapisi(?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    if (pdks_cari_sema_hazir($pdo)) return;

    $mesaj = 'Cari hesap modülü tabloları henüz oluşturulmamış. Bir yöneticinin '
           . 'migrate.php sayfasından "Cari Hesap Tablolarını Oluştur" demesi gerekiyor.';
    if (function_exists('set_flash')) set_flash('error', $mesaj);
    if (function_exists('render_header')) render_header('Cari Hesap');
    if (function_exists('render_flash')) {
        render_flash();
    } elseif (function_exists('h')) {
        echo '<div class="flash flash-error">' . h($mesaj) . '</div>';
    }
    if (function_exists('render_footer')) render_footer();
    exit;
}

// =========================================================
// YETKİ KAPISI — YALNIZ İKİ İZİN (kullanıcının açık talimatı örneği):
// attendance.foreman_accounts (görüntüleme), attendance.foreman_payments
// (ödeme kaydı/iptali). ik BİLİNÇLİ OLARAK İKİSİNİ de ALMAZ ("ik: NO
// payment management by default" — görev talimatı yalnız ÖDEME YÖNETİMİNİ
// açıkça YASAKLIYOR, hesap GÖRÜNTÜLEMEYİ AÇIKÇA İSTEMİYOR; belirsizlikte
// EN AZ yetki verilir, bkz. helpers.php'deki yorum).
// =========================================================

function pdks_cari_can(string $eylem): bool
{
    if (!function_exists('can')) return false;
    if (function_exists('is_admin') && is_admin()) return true;

    return match ($eylem) {
        'accounts' => can('attendance.foreman_accounts'),
        'payments' => can('attendance.foreman_payments'),
        default    => false,
    };
}

function require_pdks_cari(string $eylem): void
{
    if (!PDKS_CARI_AKTIF) {
        if (function_exists('forbidden')) forbidden('Cari hesap modülü şu anda kapalıdır.');
        http_response_code(503);
        exit('Cari hesap modülü kapalı.');
    }
    if (function_exists('current_user') && current_user() === null) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . (function_exists('base_url') ? base_url() : '') . 'login.php' . ($next ? '?next=' . $next : ''));
        exit;
    }
    if (function_exists('enforce_active_depot')) enforce_active_depot();
    if (!pdks_cari_can($eylem)) {
        forbidden("Bu sayfaya erişim yetkiniz yok. (Gerekli yetki: {$eylem})");
    }
}

// =========================================================
// ÖDEME (foreman_payments) — TEK YAZMA YOLU + KONTROLLÜ İPTAL
// =========================================================

/**
 * YENİ bir ödeme kaydeder — DEĞİŞMEZ alanlar (amount/foreman_id/
 * payment_date/currency) bir daha ASLA UPDATE edilmez (bu dosyada böyle
 * bir yol YOK). Faz 4'ün AYNI kuruş stratejisiyle doğrulanır — 0 veya
 * negatif tutar KABUL EDİLMEZ.
 */
function pdks_cari_odeme_ekle(int $foremanId, string $tarih, string $tutarHam, ?string $currency, string $yontem, ?string $referansNo, ?string $aciklama, int $userId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $currency = trim((string)$currency) ?: 'TRY';
    $yontem = strtoupper(trim($yontem));
    if (!in_array($yontem, ['BANK', 'CASH', 'OTHER'], true)) {
        return ['ok' => false, 'hata' => 'Geçersiz ödeme yöntemi.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih) || !strtotime($tarih)) {
        return ['ok' => false, 'hata' => 'Ödeme tarihi geçersiz.'];
    }
    $kurus = function_exists('pdks_hakedis_girdi_kurus') ? pdks_hakedis_girdi_kurus($tutarHam) : null;
    if ($kurus === null || $kurus <= 0) {
        return ['ok' => false, 'hata' => 'Ödeme tutarı geçersiz. Örnek: 20000 veya 20000,50'];
    }

    $stCavus = $pdo->prepare("SELECT id, name, code FROM foremen WHERE id = ?");
    $stCavus->execute([$foremanId]);
    $cavus = $stCavus->fetch();
    if (!$cavus) return ['ok' => false, 'hata' => 'Çavuş bulunamadı.'];

    $referansNo = trim((string)$referansNo);
    $aciklama   = trim((string)$aciklama);

    $ins = $pdo->prepare(
        "INSERT INTO foreman_payments
            (foreman_id, foreman_name_snapshot, foreman_code_snapshot, payment_date, amount, currency,
             payment_method, reference_no, description, status, created_by_user_id)
         VALUES (?,?,?,?,?,?,?,?,?, 'valid', ?)"
    );
    $ins->execute([
        $foremanId, (string)$cavus['name'], (string)$cavus['code'], $tarih,
        pdks_hakedis_kurus_tl($kurus), $currency, $yontem,
        $referansNo !== '' ? $referansNo : null, $aciklama !== '' ? $aciklama : null, $userId,
    ]);
    $id = (int)$pdo->lastInsertId();

    if (function_exists('audit_log_event')) {
        audit_log_event('create', 'foreman_payments', $id, null, [
            'foreman_id' => $foremanId, 'payment_date' => $tarih,
            'amount' => pdks_hakedis_kurus_tl($kurus), 'currency' => $currency, 'method' => $yontem,
        ]);
    }
    return ['ok' => true, 'id' => $id];
}

/**
 * Bir ödemeyi İPTAL eder — SATIR SİLİNMEZ, yalnız status='cancelled'
 * olur ve iptal eden/ne zaman/neden AYRI alanlara yazılır (kullanıcının
 * açık talimatı: "Cancellation requires: authorized user, mandatory
 * reason, server timestamp. ... Do NOT DELETE posted payment records.").
 * İptal edilen ödeme bakiyeyi bir daha ETKİLEMEZ (bkz. pdks_cari_bakiye()
 * — yalnız status='valid' satırlar toplanır) ama denetim geçmişinde
 * (pdks_cari_odeme_listesi()) HER ZAMAN görünür kalır.
 */
function pdks_cari_odeme_iptal(int $paymentId, string $sebep, int $userId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_cari_can('payments')) {
        return ['ok' => false, 'kod' => 'yetkisiz', 'hata' => 'Ödeme iptali için yetkiniz yok.'];
    }
    $sebep = trim($sebep);
    if ($sebep === '') {
        return ['ok' => false, 'kod' => 'gerekce_zorunlu', 'hata' => 'İptal gerekçesi zorunludur.'];
    }
    $st = $pdo->prepare("SELECT * FROM foreman_payments WHERE id = ?");
    $st->execute([$paymentId]);
    $odeme = $st->fetch();
    if (!$odeme) return ['ok' => false, 'kod' => 'odeme_yok', 'hata' => 'Ödeme kaydı bulunamadı.'];
    if ($odeme['status'] === 'cancelled') return ['ok' => false, 'kod' => 'zaten_iptal', 'hata' => 'Bu ödeme zaten iptal edilmiş.'];

    $simdi = date('Y-m-d H:i:s');
    $upd = $pdo->prepare(
        "UPDATE foreman_payments SET status='cancelled', cancelled_at=?, cancelled_by_user_id=?, cancellation_reason=? WHERE id=?"
    );
    $upd->execute([$simdi, $userId, $sebep, $paymentId]);

    if (function_exists('audit_log_event')) {
        audit_log_event('cancel', 'foreman_payments', $paymentId, $odeme, ['sebep' => $sebep]);
    }
    return ['ok' => true];
}

/** Faz 4'ün pdks_hakedis_yeniden_ac() YUMUŞAK çapraz kontrolü İÇİN —
 *  bu çavuşun EN AZ bir GEÇERLİ (iptal edilmemiş) ödemesi var mı? */
function pdks_cari_odeme_var_mi(int $foremanId, ?PDO $pdo = null): bool
{
    $pdo = $pdo ?? db();
    if (!pdks_cari_tablo_var($pdo, 'foreman_payments')) return false;
    $st = $pdo->prepare("SELECT 1 FROM foreman_payments WHERE foreman_id = ? AND status = 'valid' LIMIT 1");
    $st->execute([$foremanId]);
    return (bool)$st->fetchColumn();
}

/** cavus_odeme.php'nin denetim/geçmiş listesi için — bir çavuşun TÜM
 *  ödemeleri (GEÇERLİ + İPTAL, kullanıcının açık talimatı: "Never hide
 *  cancelled records from audit history."), en yeni ÖNCE. */
function pdks_cari_odeme_listesi(int $foremanId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare("SELECT * FROM foreman_payments WHERE foreman_id = ? ORDER BY payment_date DESC, id DESC");
    $st->execute([$foremanId]);
    return $st->fetchAll();
}

// =========================================================
// BAKİYE / EKSTRE — foreman_daily_entitlements (status='final') +
// foreman_payments (status='valid') ÜZERİNDEN CANLI TÜRETİLİR.
// =========================================================

/**
 * Bir çavuşun PARA BİRİMİ bazlı bakiyesi. TEK doğru işaret sözleşmesi
 * (kullanıcının açık talimatı, dosya başlığına da yazıldı):
 *   bakiye = Σ KESİN hakediş  -  Σ GEÇERLİ ödeme     (para birimi başına)
 *   pozitif → "Çavuşa Borcumuz", sıfır → "Hesap Kapalı",
 *   negatif → "Çavuş Avansı / Fazla Ödeme"
 * TAMAMEN TAM SAYI KURUŞ aritmetiği (Faz 4'ün AYNI stratejisi REUSE
 * edilir) — MySQL'in DECIMAL SUM()'ı da kendi başına kesin olurdu, ama
 * kullanıcının açık talimatı ("Running balances and totals must use
 * exact minor-unit arithmetic") gereği burada da PHP tarafında BİLEREK
 * kuruş üzerinden toplanır — iki katmanlı, açıkça ifade edilen garanti.
 */
function pdks_cari_bakiye(int $foremanId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $sonuc = [];   // currency => ['hakedis_kurus'=>, 'odeme_kurus'=>, 'bakiye_kurus'=>, 'son_hakedis_tarihi'=>, 'son_odeme_tarihi'=>]

    $stH = $pdo->prepare(
        "SELECT currency, total_amount, work_date FROM foreman_daily_entitlements
          WHERE foreman_id = ? AND status = 'final'"
    );
    $stH->execute([$foremanId]);
    foreach ($stH->fetchAll() as $h) {
        $cur = (string)$h['currency'];
        if (!isset($sonuc[$cur])) $sonuc[$cur] = ['hakedis_kurus' => 0, 'odeme_kurus' => 0, 'son_hakedis_tarihi' => null, 'son_odeme_tarihi' => null];
        $sonuc[$cur]['hakedis_kurus'] += pdks_hakedis_tl_kurus((string)$h['total_amount']);
        if ($sonuc[$cur]['son_hakedis_tarihi'] === null || $h['work_date'] > $sonuc[$cur]['son_hakedis_tarihi']) {
            $sonuc[$cur]['son_hakedis_tarihi'] = $h['work_date'];
        }
    }

    $stP = $pdo->prepare(
        "SELECT currency, amount, payment_date FROM foreman_payments
          WHERE foreman_id = ? AND status = 'valid'"
    );
    $stP->execute([$foremanId]);
    foreach ($stP->fetchAll() as $p) {
        $cur = (string)$p['currency'];
        if (!isset($sonuc[$cur])) $sonuc[$cur] = ['hakedis_kurus' => 0, 'odeme_kurus' => 0, 'son_hakedis_tarihi' => null, 'son_odeme_tarihi' => null];
        $sonuc[$cur]['odeme_kurus'] += pdks_hakedis_tl_kurus((string)$p['amount']);
        if ($sonuc[$cur]['son_odeme_tarihi'] === null || $p['payment_date'] > $sonuc[$cur]['son_odeme_tarihi']) {
            $sonuc[$cur]['son_odeme_tarihi'] = $p['payment_date'];
        }
    }

    foreach ($sonuc as $cur => &$s) {
        $s['bakiye_kurus'] = $s['hakedis_kurus'] - $s['odeme_kurus'];
        $s['hakedis_toplam'] = pdks_hakedis_kurus_tl($s['hakedis_kurus']);
        $s['odeme_toplam']   = pdks_hakedis_kurus_tl($s['odeme_kurus']);
        $s['bakiye']         = pdks_hakedis_kurus_tl($s['bakiye_kurus']);
        $s['durum'] = $s['bakiye_kurus'] > 0 ? 'borc' : ($s['bakiye_kurus'] < 0 ? 'avans' : 'kapali');
        $s['durum_etiket'] = match ($s['durum']) {
            'borc'  => 'Çavuşa Borcumuz',
            'avans' => 'Çavuş Avansı / Fazla Ödeme',
            default => 'Hesap Kapalı',
        };
        $s['currency'] = $cur;
    }
    unset($s);
    return $sonuc;
}

/**
 * Bir ödeme KAYDEDİLMEDEN ÖNCE aşım (mevcut borç bakiyesini aşma)
 * PROJEKSİYONU — sayfa (cavus_odeme.php) VE testler AYNI mantığı
 * PAYLAŞSIN diye ayrı bir fonksiyon (paralel/tekrarlanan mantık YOK).
 * Ödemeyi ENGELLEMEZ (kullanıcının açık talimatı: "Do NOT silently block
 * overpayment") — yalnız sonucu döner, KARAR çağırana aittir (bkz.
 * cavus_odeme.php'nin asim_onay onayı).
 */
function pdks_cari_odeme_onizleme(int $foremanId, int $tutarKurus, string $currency, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $bakiyeler = pdks_cari_bakiye($foremanId, $pdo);
    $mevcutKurus = isset($bakiyeler[$currency]) ? $bakiyeler[$currency]['bakiye_kurus'] : 0;
    $yeniKurus = $mevcutKurus - $tutarKurus;
    return [
        'mevcut_bakiye_kurus' => $mevcutKurus,
        'yeni_bakiye_kurus' => $yeniKurus,
        'asim' => $tutarKurus > $mevcutKurus,
    ];
}

/**
 * cavus_cari.php için — hakedişi veya ödemesi olan TÜM çavuşların bakiye
 * özeti (bkz. pdks_cari_bakiye() — aynı fonksiyon, çavuş listesi üzerinde
 * döngü). Küçük veri hacminde (çavuş sayısı en fazla birkaç düzine) N+1
 * kabul edilebilir düzeydedir — Faz 3'ün gün listesindeki toplu sorgu
 * gerekliliği kadar büyük bir veri kümesi burada YOKTUR.
 */
function pdks_cari_hesap_listesi(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $st = $pdo->query(
        "SELECT DISTINCT f.id, f.code, f.name, f.is_active FROM foremen f
          WHERE EXISTS (SELECT 1 FROM foreman_daily_entitlements e WHERE e.foreman_id = f.id AND e.status = 'final')
             OR EXISTS (SELECT 1 FROM foreman_payments p WHERE p.foreman_id = f.id)
          ORDER BY f.is_active DESC, f.name ASC"
    );
    $cavuslar = $st->fetchAll();
    $sonuc = [];
    foreach ($cavuslar as $c) {
        $sonuc[] = ['foreman' => $c, 'bakiyeler' => pdks_cari_bakiye((int)$c['id'], $pdo)];
    }
    return $sonuc;
}

/**
 * cavus_ekstre.php için — KRONOLOJİK hesap ekstresi, PARA BİRİMİ bazında
 * AYRI dizilerde (kullanıcının açık talimatı: "Do NOT sum different
 * currencies together"). Kaynak: YALNIZ KESİN hakediş + GEÇERLİ ödeme
 * (kullanıcının açık talimatı madde 12: "Do not create editable fake
 * statement rows. The statement is a projection of authoritative
 * financial records."). Aynı gün birden fazla hareket varsa sıralama
 * belirleyici bir zaman/id ile TIE-BREAK edilir (kullanıcının açık
 * talimatı: "Use authoritative timestamp/id ordering as tie-breaker.") —
 * hakediş için finalized_at+id, ödeme için created_at+id.
 */
function pdks_cari_ekstre(int $foremanId, ?string $baslangic = null, ?string $bitis = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $satirlar = [];   // currency => [ ham hareket satırları ]

    $whereH = ['foreman_id = ?', "status = 'final'"]; $parH = [$foremanId];
    if ($baslangic !== null && $baslangic !== '') { $whereH[] = 'work_date >= ?'; $parH[] = $baslangic; }
    if ($bitis !== null && $bitis !== '')       { $whereH[] = 'work_date <= ?'; $parH[] = $bitis; }
    $stH = $pdo->prepare("SELECT * FROM foreman_daily_entitlements WHERE " . implode(' AND ', $whereH));
    $stH->execute($parH);
    foreach ($stH->fetchAll() as $h) {
        $satirlar[(string)$h['currency']][] = [
            'tarih' => $h['work_date'], 'tip' => 'HAKEDIS', 'tip_etiket' => 'HAKEDİŞ',
            'belge' => 'HKD-' . str_pad((string)$h['id'], 6, '0', STR_PAD_LEFT),
            'aciklama' => 'Günlük Hakediş' . ($h['depo'] ? ' (' . $h['depo'] . ')' : ''),
            'artis_kurus' => pdks_hakedis_tl_kurus((string)$h['total_amount']), 'azalis_kurus' => 0,
            'siralama_zaman' => $h['finalized_at'] ?? $h['calculated_at'], 'siralama_id' => (int)$h['id'],
            'kaynak_id' => (int)$h['id'],
        ];
    }

    $whereP = ['foreman_id = ?', "status = 'valid'"]; $parP = [$foremanId];
    if ($baslangic !== null && $baslangic !== '') { $whereP[] = 'payment_date >= ?'; $parP[] = $baslangic; }
    if ($bitis !== null && $bitis !== '')       { $whereP[] = 'payment_date <= ?'; $parP[] = $bitis; }
    $stP = $pdo->prepare("SELECT * FROM foreman_payments WHERE " . implode(' AND ', $whereP));
    $stP->execute($parP);
    foreach ($stP->fetchAll() as $p) {
        $satirlar[(string)$p['currency']][] = [
            'tarih' => $p['payment_date'], 'tip' => 'ODEME', 'tip_etiket' => 'ÖDEME',
            'belge' => $p['reference_no'] ?: ('ODM-' . str_pad((string)$p['id'], 6, '0', STR_PAD_LEFT)),
            'aciklama' => $p['description'] ?: ucfirst(strtolower($p['payment_method'])),
            'artis_kurus' => 0, 'azalis_kurus' => pdks_hakedis_tl_kurus((string)$p['amount']),
            'siralama_zaman' => $p['created_at'], 'siralama_id' => (int)$p['id'],
            'kaynak_id' => (int)$p['id'],
        ];
    }

    $sonuc = [];
    foreach ($satirlar as $cur => $hareketler) {
        usort($hareketler, function ($a, $b) {
            $c = strcmp((string)$a['tarih'], (string)$b['tarih']);
            if ($c !== 0) return $c;
            $c = strcmp((string)$a['siralama_zaman'], (string)$b['siralama_zaman']);
            if ($c !== 0) return $c;
            return $a['siralama_id'] <=> $b['siralama_id'];
        });
        $kosanKurus = 0;
        foreach ($hareketler as &$hr) {
            $kosanKurus += $hr['artis_kurus'] - $hr['azalis_kurus'];
            $hr['artis'] = $hr['artis_kurus'] > 0 ? pdks_hakedis_kurus_tl($hr['artis_kurus']) : null;
            $hr['azalis'] = $hr['azalis_kurus'] > 0 ? pdks_hakedis_kurus_tl($hr['azalis_kurus']) : null;
            $hr['kosan_bakiye'] = pdks_hakedis_kurus_tl($kosanKurus);
            $hr['currency'] = $cur;
        }
        unset($hr);
        $sonuc[$cur] = $hareketler;
    }
    return $sonuc;
}

// =========================================================
// FAZ 7 — GÖRÜNTÜLEME/EXPORT ETİKETLERİ (SUNUM KATMANI)
//
// ⚠ Yalnız EKRANDA/CSV'de/yazdırmada gösterilecek Türkçe metni üretir —
// veritabanı enum DEĞERLERİ (foreman_payments.payment_method/.status)
// KESİNLİKLE değişmiyor, buradaki eşleme SADECE dönüş metnini etkiler
// (kullanıcının açık talimatı: "Do NOT change database enum values.
// Translation is presentation/export only."). cavus_odeme.php'nin KENDİ
// formundaki seçenek metinleriyle (Banka/Havale, Nakit, Diğer) AYNI
// sözlük — iki ayrı çeviri kaynağı AÇILMADI.
// =========================================================

function pdks_cari_odeme_yontem_etiketi(string $yontem): string
{
    return match (strtoupper($yontem)) {
        'BANK'  => 'Banka / Havale',
        'CASH'  => 'Nakit',
        'OTHER' => 'Diğer',
        default => $yontem,
    };
}

function pdks_cari_odeme_durum_etiketi(string $durum): string
{
    return match ($durum) {
        'valid'     => 'Geçerli',
        'cancelled' => 'İptal',
        default     => $durum,
    };
}
