<?php
// =========================================================
// config/pdks_faz8b_cavus_b.php — ÇAVUŞ ÜCRETİ YÖNTEM B (N kişi-gün = 1
// hakediş; N çavuş bazında, varsayılan 25) DÖNEM KAPANIŞ MOTORU.
//
// İş kuralı (GEREKSINIMLER.md, onaylı — tek otorite): Yöntem B seçili bir
// çavuşun altında çalışan işçilerin KESİNLEŞMİŞ (status='final') günlük
// hakedişlerindeki kişi-gün toplamı (SUM(worker_count), yalnız
// worker_type_id IS NOT NULL satırlar) her N kişi-günde 1 hakediş
// kazandırır. N (v297) = kapanış ANINDA geçerli yöntem geçmişi satırının
// unit_size'ı (NULL → PDKS_FAZ8B_CAVUS_B_BIRIM = 25); kapanışın kendi
// unit_size kolonuna DONDURULUR, sonradan birim değişse de o kapanış
// değişmez. Dönem ÖDEMEDEN ÖDEMEYE sayılır — kalan (N'ye tamamlanmayan)
// kişi-gün bir sonraki döneme KİŞİ-GÜN olarak DEVREDER (yeni birimle
// bölünür). Kapanış
// yalnız cavus_odeme.php'deki ödeme kaydıyla AYNI transaction'da,
// pdks_faz8b_cavus_ucret_odeme_kaydet() üzerinden tetiklenir — ikinci bir
// "kapanış yap" ekranı/yolu YOK.
//
// ⚠ Bir hakediş B havuzuna girer ⇔ status='final' VE finalized_at NOT NULL
//   VE finalized_at anında çavuşun yöntemi B'ydi (yontem_anda) VE hakedişte
//   "Çavuş Ücreti" satırı (worker_type_id IS NULL AND work_period_id IS NULL
//   AND worker_type_code_snapshot = '' — v299: servis satırları SERVIS_* kodlu, sayılmaz)
//   YOK VE hiçbir GEÇERLİ kapanışın kalemi DEĞİL. Bu, A'dayken kesinleşmiş
//   günlerin B'ye asla girmemesini (çift ödeme riski) ve B→A→B geçişinde
//   "A arasında" kesinleşen günlerin de asla sayılmamasını garanti eder.
//
// ⚠ Değişmezlik: foreman_period_closures/foreman_period_closure_items
//   satırları ASLA silinmez, asla UPDATE ile hesap alanları DEĞİŞTİRİLMEZ —
//   yalnız closures'ta TEK bir UPDATE vardır (iptal: status/chain_key/
//   cancelled_*). Kapanış anındaki kişi-gün/ücret/tutar SONSUZA DEK donar
//   (bkz. pdks_hakedis_yeniden_ac()'ın §4.4 eklentisi).
// =========================================================

declare(strict_types=1);

require_once __DIR__ . '/pdks_faz8b.php';
require_once __DIR__ . '/pdks_cari.php';

// =========================================================
// SAF FONKSİYONLAR
// =========================================================

/** Devir + dönem kişi-gününden hakediş adedi/yeni devir hesaplar. Yan etkisiz. */
function pdks_faz8b_cavus_ucret_b_hesap(int $devirGiren, int $donemKisiGun, int $birim = PDKS_FAZ8B_CAVUS_B_BIRIM): array
{
    if ($devirGiren < 0 || $donemKisiGun < 0 || $birim < 1) {
        throw new InvalidArgumentException('Geçersiz kişi-gün girdisi.');
    }
    $toplam = $devirGiren + $donemKisiGun;
    return [
        'devir_giren' => $devirGiren,
        'donem_kisi_gun' => $donemKisiGun,
        'toplam' => $toplam,
        'adet' => intdiv($toplam, $birim),
        'devir_cikan' => $toplam % $birim,
        'birim' => $birim,
    ];
}

function pdks_faz8b_cavus_ucret_b_zincir_anahtari(int $foremanId, ?int $oncekiKapanisId): string
{
    return 'F' . $foremanId . ':P' . ($oncekiKapanisId ?? 0);
}

/**
 * Aday listesinden (aday_kalemler()'in ham satırları) B havuzuna GİRMEYEN
 * kayıtları eler: çavuş ücreti (A) satırı taşıyanlar, kişi-günü 0/negatif
 * olanlar, finalized_at boş olanlar, finalize ANINDA yöntemi B OLMAYANLAR.
 * Sıra korunur.
 */
function pdks_faz8b_cavus_ucret_b_havuz_sec(array $adaylar, array $gecmis): array
{
    $sonuc = [];
    foreach ($adaylar as $a) {
        if ((int)($a['cavus_satiri'] ?? 0) > 0) continue;
        if ((int)($a['kisi_gun'] ?? 0) <= 0) continue;
        $fz = (string)($a['finalized_at'] ?? '');
        if ($fz === '') continue;
        if (pdks_faz8b_cavus_ucret_yontem_anda($gecmis, $fz) !== 'B') continue;
        $sonuc[] = $a;
    }
    return $sonuc;
}

function pdks_faz8b_cavus_ucret_b_para(int $kurus): string
{
    return number_format($kurus / 100, 2, ',', '.');
}

function pdks_faz8b_cavus_ucret_b_onizleme_metni(array $o): string
{
    $d = (string)($o['durum'] ?? '');
    $devir = (int)($o['devir_giren'] ?? 0);
    $donem = (int)($o['donem_kisi_gun'] ?? 0);
    $cur = (string)($o['currency'] ?? '');
    $ek = ((int)($o['taslak_sayisi'] ?? 0)) > 0
        ? ' Not: ' . (int)$o['taslak_sayisi'] . ' hakediş henüz kesinleşmedi; kesinleşince sonraki kapanışa girer.'
        : '';

    $birim = (int)($o['birim'] ?? PDKS_FAZ8B_CAVUS_B_BIRIM);
    if ($d === 'kapanacak') {
        return sprintf(
            'Şu an kapanış yapılırsa (%d kişi-gün = 1 hakediş): %d kişi-gün + %d devir → %d hakediş, %d devir, tutar %s %s (%d × %s %s).',
            $birim, $donem, $devir, (int)$o['adet'], (int)$o['devir_cikan'],
            pdks_faz8b_cavus_ucret_b_para((int)$o['tutar_kurus']), $cur,
            (int)$o['adet'], pdks_faz8b_cavus_ucret_b_para((int)$o['birim_kurus']), $cur
        ) . $ek;
    }
    if ($d === 'ucret_yok') {
        return sprintf(
            'Çavuş ücreti tanımsız (%s) — bu ödemede dönem kapanışı YAPILMAZ; %d kişi-gün + %d devir açıkta bekler. Ücreti Çavuş Ücretleri ekranından tanımlayın.',
            date('d.m.Y', strtotime((string)$o['tarih'])), $donem, $devir
        );
    }
    if ($d === 'bos') {
        return sprintf('Kapanışa girecek yeni kesinleşmiş gün yok (%d kişi-gün devirde; %d kişi-gün = 1 hakediş). Bu ödemede kapanış yapılmaz.', $devir, $birim);
    }
    if ($d === 'yontem_a') {
        if ($donem > 0 || $devir > 0) {
            return sprintf("Çavuş Yöntem A'da (günlük sabit ücret). Yöntem B'den bekleyen %d kişi-gün + %d devir, çavuş tekrar Yöntem B'ye alınınca sayılır.", $donem, $devir);
        }
        return "Çavuş Yöntem A'da (günlük sabit ücret).";
    }
    return '';
}

// =========================================================
// DB OKUMA
// =========================================================

function pdks_faz8b_cavus_ucret_b_son_kapanis(int $foremanId, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare(
        "SELECT * FROM foreman_period_closures WHERE foreman_id = ? AND status = 'valid' ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$foremanId]);
    return $st->fetch() ?: null;
}

function pdks_faz8b_cavus_ucret_b_aday_kalemler(int $foremanId, array $gecmis, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $ilkB = null;
    foreach ($gecmis as $g) {
        if ((string)$g['method'] === 'B' && ($ilkB === null || (string)$g['effective_at'] < $ilkB)) {
            $ilkB = (string)$g['effective_at'];
        }
    }
    if ($ilkB === null) return [];

    $cavusExpr = pdks_faz8b_kolon_var($pdo, 'foreman_daily_entitlement_lines', 'work_period_id')
        ? "(SELECT COUNT(*) FROM foreman_daily_entitlement_lines l2 WHERE l2.entitlement_id = e.id AND l2.worker_type_id IS NULL AND l2.work_period_id IS NULL AND l2.worker_type_code_snapshot = '')"
        : "0";

    $sql = "SELECT e.id AS entitlement_id, e.work_date, e.depo, e.finalized_at,
                   (SELECT COALESCE(SUM(l.worker_count),0) FROM foreman_daily_entitlement_lines l
                     WHERE l.entitlement_id = e.id AND l.worker_type_id IS NOT NULL) AS kisi_gun,
                   {$cavusExpr} AS cavus_satiri
              FROM foreman_daily_entitlements e
             WHERE e.foreman_id = ? AND e.status = 'final' AND e.finalized_at IS NOT NULL
               AND e.finalized_at >= ?
               AND NOT EXISTS (SELECT 1 FROM foreman_period_closure_items i
                                 JOIN foreman_period_closures c ON c.id = i.closure_id
                                WHERE i.entitlement_id = e.id AND c.status = 'valid')
             ORDER BY e.work_date ASC, e.id ASC";
    $st = $pdo->prepare($sql);
    $st->execute([$foremanId, $ilkB]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['kisi_gun'] = (int)$r['kisi_gun'];
        $r['cavus_satiri'] = (int)$r['cavus_satiri'];
    }
    unset($r);
    return $rows;
}

function pdks_faz8b_cavus_ucret_b_onizle(int $foremanId, string $tarih, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_cavus_ucret_b_sema_hazir($pdo)) {
        return ['durum' => 'sema_yok', 'mesaj' => ''];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih) || !strtotime($tarih)) {
        $tarih = date('Y-m-d');
    }

    $yontem = pdks_faz8b_cavus_ucret_yontem($foremanId, $pdo);
    $gecmis = pdks_faz8b_cavus_ucret_yontem_gecmisi($foremanId, $pdo);
    $son = pdks_faz8b_cavus_ucret_b_son_kapanis($foremanId, $pdo);
    $devir = $son ? (int)$son['carry_out'] : 0;
    $kalemler = pdks_faz8b_cavus_ucret_b_havuz_sec(pdks_faz8b_cavus_ucret_b_aday_kalemler($foremanId, $gecmis, $pdo), $gecmis);
    $donem = 0;
    foreach ($kalemler as $k) $donem += (int)$k['kisi_gun'];
    // v297: birim = kapanış (şu) ANINDA geçerli yöntem geçmişi satırının
    // unit_size'ı (yontem_anda() ile aynı zaman kuralı); NULL → 25.
    $birim = pdks_faz8b_cavus_ucret_birim_anda($gecmis, date('Y-m-d H:i:s'));
    $h = pdks_faz8b_cavus_ucret_b_hesap($devir, $donem, $birim);

    $ucret = pdks_faz8b_cavus_ucret_gecerli($foremanId, $tarih, $pdo);
    $birimKurus = $ucret ? pdks_hakedis_tl_kurus((string)$ucret['daily_rate']) : null;
    $tutarKurus = $birimKurus !== null ? $h['adet'] * $birimKurus : null;
    $para = $ucret ? (trim((string)($ucret['currency'] ?? '')) ?: 'TRY') : null;

    if ($yontem !== 'B') {
        $durum = 'yontem_a';
    } elseif ($donem === 0) {
        $durum = 'bos';
    } elseif ($ucret === null) {
        $durum = 'ucret_yok';
    } else {
        $durum = 'kapanacak';
    }

    $stTaslak = $pdo->prepare("SELECT COUNT(*) FROM foreman_daily_entitlements WHERE foreman_id = ? AND status = 'draft'");
    $stTaslak->execute([$foremanId]);
    $taslakSayisi = (int)$stTaslak->fetchColumn();

    $o = [
        'durum' => $durum,
        'yontem' => $yontem,
        'tarih' => $tarih,
        'son_kapanis' => $son,
        'devir_giren' => $h['devir_giren'],
        'donem_kisi_gun' => $h['donem_kisi_gun'],
        'toplam' => $h['toplam'],
        'adet' => $h['adet'],
        'devir_cikan' => $h['devir_cikan'],
        'birim' => $h['birim'],
        'kalemler' => $kalemler,
        'ucret' => $ucret,
        'birim_kurus' => $birimKurus,
        'tutar_kurus' => $tutarKurus,
        'currency' => $para,
        'taslak_sayisi' => $taslakSayisi,
    ];
    $o['mesaj'] = pdks_faz8b_cavus_ucret_b_onizleme_metni($o);
    return $o;
}

// =========================================================
// YAZMA
// =========================================================

/** transaction'ın İLK sorgusu olmalı. */
function pdks_faz8b_cavus_ucret_b_kilit(int $foremanId, PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
    $st = $pdo->prepare("SELECT id FROM foremen WHERE id = ? FOR UPDATE");
    $st->execute([$foremanId]);
    $st->fetchAll();
}

function pdks_faz8b_cavus_ucret_b_kapat(int $foremanId, int $paymentId, string $tarih, int $userId, PDO $pdo): array
{
    if (!$pdo->inTransaction()) {
        return ['ok' => false, 'kod' => 'transaction_yok', 'hata' => 'Kapanış yalnız ödeme işlemiyle birlikte yapılabilir.'];
    }
    $o = pdks_faz8b_cavus_ucret_b_onizle($foremanId, $tarih, $pdo);

    if (($o['durum'] ?? '') === 'ucret_yok') {
        if (function_exists('audit_log_event')) {
            audit_log_event('closure_skipped', 'foreman_period_closures', null, null, [
                'foreman_id' => $foremanId, 'payment_id' => $paymentId, 'sebep' => 'ucret_tanimsiz',
                'donem_kisi_gun' => $o['donem_kisi_gun'], 'devir' => $o['devir_giren'],
            ]);
        }
        return ['ok' => true, 'kapanis_id' => null, 'onizleme' => $o];
    }
    if (($o['durum'] ?? '') !== 'kapanacak') {
        return ['ok' => true, 'kapanis_id' => null, 'onizleme' => $o];
    }

    $stF = $pdo->prepare("SELECT name FROM foremen WHERE id = ?");
    $stF->execute([$foremanId]);
    $adSnapshot = (string)($stF->fetchColumn() ?: '');

    $prevId = $o['son_kapanis'] ? (int)$o['son_kapanis']['id'] : null;
    $chainKey = pdks_faz8b_cavus_ucret_b_zincir_anahtari($foremanId, $prevId);

    $ins = $pdo->prepare(
        "INSERT INTO foreman_period_closures
            (foreman_id, foreman_name_snapshot, payment_id, closure_date, prev_closure_id, chain_key,
             unit_size, carry_in, period_person_days, total_person_days, earned_units, carry_out,
             rate_id, unit_rate, currency, amount, status, created_by_user_id)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'valid', ?)"
    );
    $ins->execute([
        $foremanId, $adSnapshot, $paymentId, $tarih, $prevId, $chainKey,
        $o['birim'], $o['devir_giren'], $o['donem_kisi_gun'], $o['toplam'], $o['adet'], $o['devir_cikan'],
        $o['ucret']['id'] ?? null, pdks_hakedis_kurus_tl((int)$o['birim_kurus']), $o['currency'],
        pdks_hakedis_kurus_tl((int)$o['tutar_kurus']), $userId,
    ]);
    $kapanisId = (int)$pdo->lastInsertId();

    $insI = $pdo->prepare(
        "INSERT INTO foreman_period_closure_items (closure_id, entitlement_id, work_date, depo, person_days)
         VALUES (?,?,?,?,?)"
    );
    foreach ($o['kalemler'] as $k) {
        $insI->execute([$kapanisId, (int)$k['entitlement_id'], (string)$k['work_date'], (string)($k['depo'] ?? ''), (int)$k['kisi_gun']]);
    }

    if (function_exists('audit_log_event')) {
        audit_log_event('create', 'foreman_period_closures', $kapanisId, null, [
            'foreman_id' => $foremanId, 'payment_id' => $paymentId, 'closure_date' => $tarih,
            'carry_in' => $o['devir_giren'], 'period_person_days' => $o['donem_kisi_gun'],
            'total_person_days' => $o['toplam'], 'earned_units' => $o['adet'], 'carry_out' => $o['devir_cikan'],
            'unit_rate' => pdks_hakedis_kurus_tl((int)$o['birim_kurus']), 'currency' => $o['currency'],
            'amount' => pdks_hakedis_kurus_tl((int)$o['tutar_kurus']), 'kalem_sayisi' => count($o['kalemler']),
        ]);
    }

    return ['ok' => true, 'kapanis_id' => $kapanisId, 'onizleme' => $o];
}

// =========================================================
// ORKESTRATÖRLER
// =========================================================

function pdks_faz8b_cavus_ucret_odeme_onizleme(int $foremanId, int $tutarKurus, string $currency, string $tarih, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $o = pdks_cari_odeme_onizleme($foremanId, $tutarKurus, $currency, $pdo);
    $b = pdks_faz8b_cavus_ucret_b_sema_hazir($pdo) ? pdks_faz8b_cavus_ucret_b_onizle($foremanId, $tarih, $pdo) : null;
    $o['kapanis'] = $b;
    if ($b !== null && ($b['durum'] ?? '') === 'kapanacak' && ($b['currency'] ?? null) === $currency) {
        $mevcut = $o['mevcut_bakiye_kurus'] + (int)$b['tutar_kurus'];
        $o['mevcut_bakiye_kurus'] = $mevcut;
        $o['yeni_bakiye_kurus'] = $mevcut - $tutarKurus;
        $o['asim'] = $tutarKurus > $mevcut;
    }
    return $o;
}

function pdks_faz8b_cavus_ucret_odeme_kaydet(
    int $foremanId, string $tarih, string $tutarHam, ?string $currency, string $yontem,
    ?string $referansNo, ?string $aciklama, int $userId, ?PDO $pdo = null
): array {
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_cavus_ucret_b_sema_hazir($pdo)) {
        $r = pdks_cari_odeme_ekle($foremanId, $tarih, $tutarHam, $currency, $yontem, $referansNo, $aciklama, $userId, $pdo);
        return $r + ['kapanis' => null];
    }

    $kendiTx = !$pdo->inTransaction();
    try {
        if ($kendiTx) $pdo->beginTransaction();
        pdks_faz8b_cavus_ucret_b_kilit($foremanId, $pdo);

        $sonuc = pdks_cari_odeme_ekle($foremanId, $tarih, $tutarHam, $currency, $yontem, $referansNo, $aciklama, $userId, $pdo);
        if (!($sonuc['ok'] ?? false)) {
            if ($kendiTx && $pdo->inTransaction()) $pdo->rollBack();
            return $sonuc + ['kapanis' => null];
        }

        $kapanis = null;
        if (pdks_faz8b_cavus_ucret_yontem($foremanId, $pdo) === 'B') {
            $r = pdks_faz8b_cavus_ucret_b_kapat($foremanId, (int)$sonuc['id'], $tarih, $userId, $pdo);
            if (!($r['ok'] ?? false)) {
                throw new RuntimeException($r['hata'] ?? 'Çavuş Hakedişi kapanışı yapılamadı.');
            }
            $kapanis = $r;
        }

        if ($kendiTx) $pdo->commit();
        return $sonuc + ['kapanis' => $kapanis];
    } catch (Throwable $e) {
        if ($kendiTx && $pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            return ['ok' => false, 'kod' => 'eszamanli_islem',
                'hata' => 'Bu çavuş için aynı anda başka bir ödeme/kapanış işlendi. Hiçbir kayıt yapılmadı — sayfayı yenileyip tekrar deneyin.'];
        }
        return ['ok' => false, 'kod' => 'yazim_hatasi', 'hata' => 'Ödeme kaydedilemedi (hiçbir kayıt yapılmadı): ' . $e->getMessage()];
    }
}

function pdks_faz8b_cavus_ucret_odeme_iptal(int $paymentId, string $sebep, int $userId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_cavus_ucret_b_sema_hazir($pdo)) {
        $r = pdks_cari_odeme_iptal($paymentId, $sebep, $userId, $pdo);
        return $r + ['kapanis_iptal' => null];
    }
    if (!pdks_cari_can('payments')) {
        return ['ok' => false, 'kod' => 'yetkisiz', 'hata' => 'Ödeme iptali için yetkiniz yok.'];
    }
    $sebep = trim($sebep);
    if ($sebep === '') {
        return ['ok' => false, 'kod' => 'gerekce_zorunlu', 'hata' => 'İptal gerekçesi zorunludur.'];
    }
    $stP = $pdo->prepare("SELECT * FROM foreman_payments WHERE id = ?");
    $stP->execute([$paymentId]);
    $odeme = $stP->fetch();
    if (!$odeme) return ['ok' => false, 'kod' => 'odeme_yok', 'hata' => 'Ödeme kaydı bulunamadı.'];

    $foremanId = (int)$odeme['foreman_id'];
    $kendiTx = !$pdo->inTransaction();
    try {
        if ($kendiTx) $pdo->beginTransaction();
        pdks_faz8b_cavus_ucret_b_kilit($foremanId, $pdo);

        $stK = $pdo->prepare("SELECT * FROM foreman_period_closures WHERE payment_id = ? AND status = 'valid'");
        $stK->execute([$paymentId]);
        $kapanis = $stK->fetch();

        $kapanisIptalId = null;
        if ($kapanis) {
            $sonKapanis = pdks_faz8b_cavus_ucret_b_son_kapanis($foremanId, $pdo);
            if (!$sonKapanis || (int)$sonKapanis['id'] !== (int)$kapanis['id']) {
                if ($kendiTx && $pdo->inTransaction()) $pdo->rollBack();
                $sonTarih = $sonKapanis ? date('d.m.Y', strtotime((string)$sonKapanis['closure_date'])) : '';
                return ['ok' => false, 'kod' => 'kapanis_en_son_degil', 'hata' => sprintf(
                    'Bu ödeme CVH-%06d numaralı Çavuş Hakedişi kapanışını yaptı; ondan sonra daha yeni bir kapanış (CVH-%06d, %s tarihli ödeme) var. '
                    . 'Yalnız en son kapanış geri alınabilir — önce daha yeni ödemeyi iptal edin.',
                    (int)$kapanis['id'], (int)($sonKapanis['id'] ?? 0), $sonTarih
                )];
            }
            $simdi = date('Y-m-d H:i:s');
            $upd = $pdo->prepare(
                "UPDATE foreman_period_closures SET status = 'cancelled', chain_key = NULL,
                    cancelled_at = ?, cancelled_by_user_id = ?, cancellation_reason = ?
                  WHERE id = ? AND status = 'valid'"
            );
            $upd->execute([$simdi, $userId, $sebep, (int)$kapanis['id']]);
            if ($upd->rowCount() !== 1) {
                throw new RuntimeException('Kapanış başka bir işlemle değişti.');
            }
            if (function_exists('audit_log_event')) {
                audit_log_event('cancel', 'foreman_period_closures', (int)$kapanis['id'], $kapanis, ['sebep' => $sebep]);
            }
            $kapanisIptalId = (int)$kapanis['id'];
        }

        $r = pdks_cari_odeme_iptal($paymentId, $sebep, $userId, $pdo);
        if (!($r['ok'] ?? false)) {
            throw new RuntimeException($r['hata'] ?? 'Ödeme iptal edilemedi.');
        }

        if ($kendiTx) $pdo->commit();
        return ['ok' => true, 'kapanis_iptal' => $kapanisIptalId];
    } catch (Throwable $e) {
        if ($kendiTx && $pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'kod' => 'yazim_hatasi', 'hata' => 'İptal edilemedi (hiçbir değişiklik yapılmadı): ' . $e->getMessage()];
    }
}

// =========================================================
// LİSTELER
// =========================================================

function pdks_faz8b_cavus_ucret_b_kapanis_listesi(?int $foremanId, ?string $baslangic, ?string $bitis, ?string $durum, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_cavus_ucret_b_sema_hazir($pdo)) return [];
    $where = []; $par = [];
    if ($foremanId !== null) { $where[] = 'k.foreman_id = ?'; $par[] = $foremanId; }
    if ($baslangic !== null && $baslangic !== '') { $where[] = 'k.closure_date >= ?'; $par[] = $baslangic; }
    if ($bitis !== null && $bitis !== '') { $where[] = 'k.closure_date <= ?'; $par[] = $bitis; }
    if ($durum !== null && in_array($durum, ['valid', 'cancelled'], true)) { $where[] = 'k.status = ?'; $par[] = $durum; }
    $sql = "SELECT k.*, f.code AS foreman_code, f.name AS foreman_name,
                   p.payment_date, p.amount AS payment_amount, p.currency AS payment_currency,
                   p.status AS payment_status, p.reference_no
              FROM foreman_period_closures k
              JOIN foremen f ON f.id = k.foreman_id
              JOIN foreman_payments p ON p.id = k.payment_id"
          . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
          . " ORDER BY k.closure_date DESC, k.id DESC";
    $st = $pdo->prepare($sql);
    $st->execute($par);
    return $st->fetchAll();
}

function pdks_faz8b_cavus_ucret_b_kapanis_kalemleri(int $kapanisId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare("SELECT * FROM foreman_period_closure_items WHERE closure_id = ? ORDER BY work_date ASC, entitlement_id ASC");
    $st->execute([$kapanisId]);
    return $st->fetchAll();
}

function pdks_faz8b_cavus_ucret_b_odeme_kapanis_haritasi(int $foremanId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_cavus_ucret_b_sema_hazir($pdo)) return [];
    $st = $pdo->prepare("SELECT * FROM foreman_period_closures WHERE foreman_id = ?");
    $st->execute([$foremanId]);
    $sonuc = [];
    foreach ($st->fetchAll() as $r) $sonuc[(int)$r['payment_id']] = $r;
    return $sonuc;
}

function pdks_faz8b_cavus_ucret_b_durum_etiketi(string $s): string
{
    return match ($s) {
        'valid' => 'Geçerli',
        'cancelled' => 'İptal (geri alındı)',
        default => $s,
    };
}
