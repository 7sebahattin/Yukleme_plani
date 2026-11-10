<?php
// =========================================================
// config/pdks_toplu_onay.php — v507: Mesai Değerlendirme TOPLU ONAY (gün/çavuş/depo kapsamı).
//
// Sahip isteği: elle girilen (toplu ekleme / Fiş Excel / çalışma ekle) dönemler "Bekliyor N/M" kaldığı
// için hakediş hesaplanamıyordu; tek tek onay mantıksız. Bu dosya SEÇEREK toplu onay verir.
//
// ⚠ İKİNCİ YAZMA YOLU YOK: gerçek yazma YALNIZ pdks_faz8b_degerlendirme_kaydet() (tekli onay; kesin hakediş
//   reddi, Yarım ≥ 3 sa kuralı, taslağı bayat işaretleme ve audit orada). Burada yalnız "hangi dönem /
//   hangi karar" çözülür; gövdede UPDATE/INSERT YOK (test denetler). Süre/FM/sınıf hesabı da YAPILMAZ —
//   sınıflandırıcı (pdks_faz8b_donem_siniflandir) çıktısı okunur.
// ⚠ Seçili akışta HİÇBİR ŞEY otomatik onaylanmaz: çağıran açıkça dönem id'lerini + kararları verir; karar boşsa
//   o satır "karar gerekli" olur. TEK İSTİSNA (v508, sahip kararı): $otomatik=true "Elle girilenleri otomatik onayla"
//   — yalnız source='manual' ve kararı gerektirmeyen dönemler (bkz. pdks_toplu_onay_plan).
// =========================================================
declare(strict_types=1);

require_once __DIR__ . '/pdks_gunluk.php';
require_once __DIR__ . '/pdks_hakedis.php';
require_once __DIR__ . '/pdks_faz8b.php';

const PDKS_TOPLU_ONAY_LIMIT = 500;
/** Toplu ekran Yarım tabanı 08:00–12:30 = 270 dk; bunun üstündeki süre (Yarım + FM girişi) ücrete yansımaz — uyarı. */
const PDKS_TOPLU_ONAY_YARIM_TABAN_DK = 270;

/** Dönem kaynağı → etiket. Elle = kart okutulmadan yazılan (admin ekleme / toplu / Fiş Excel). */
function pdks_toplu_onay_kaynak_etiketi(?string $src): string
{
    return match ((string)$src) {
        'manual' => 'Elle',
        'scan' => 'Kart',
        'tanimli' => 'Kart (tanımlı)',
        'ters' => 'Kart (ters)',
        'legacy_backfill' => 'Eski kayıt',
        default => ($src === null || $src === '') ? '—' : (string)$src,
    };
}

/** "Elle girilmiş" sayılan kaynak(lar): yalnız admin ekleme yolunun yazdığı 'manual'. */
function pdks_toplu_onay_elle_mi(?string $src): bool
{
    return (string)$src === 'manual';
}

/**
 * Bir dönem için karar planı (SAF — DB yok; $d = pdks_faz8b_donem_sorgu satırı, $f = $d['faz8b']).
 *
 * v508 değişiklikleri (sahip kararları):
 *  - ÇIKIŞSIZ dönem artık "onaylanmaz" DEĞİL: eski tekli/toplu akış (pdks_faz8b_degerlendirme_kaydet) çıkışsız
 *    dönemde Tam/Yarım kararını kabul eder (süre bilinmez → Yarım ≥ 3 sa kuralı uygulanamaz, FM adayı yok; hakediş
 *    sınıfın sabit ücretini yazar). AYNISI burada: sınıf kararı seçilmişse onaylanır, uyarı gösterilir.
 *  - FİYATSIZ tip onayı ENGELLEMEZ: yalnız uyarı (hakediş fiyat girilene dek hesaplanamaz).
 *  - $otomatik (yalnız "Elle girilenleri otomatik onayla" yolu): yalnız source='manual' dönemler KARAR SEÇMEDEN onaylanır
 *    ve YALNIZ kararı gerektirmeyenler: süre Tam eşiğini karşılıyorsa (zaten otomatik Tam) + FM adayı varsa hesaplanan
 *    saatin tamamı. Tam/Yarım ayırt edilemeyen (3 sa altı, 3 sa–Tam eşiği arası, çıkışsız) → "karar" (sınıf TAHMİN
 *    EDİLMEZ). Kart okutmuş (manual dışı) dönem otomatik onaylanmaz → "karar".
 *
 * @return array{durum:string, sinif:?string, fm:?int, neden:string, etiket:string, uyari:string, sonuc_sinif:?string, fm_saat:int}
 *   durum: 'onay' (onaylanacak) | 'karar' (karar seçilmedi/verilemez) | 'uygunsuz' (onaylanmaz) | 'atla' (zaten hazır)
 */
function pdks_toplu_onay_plan(array $d, bool $kesin, bool $fiyatVar, ?string $sinifKarari, ?string $fmKarar, bool $otomatik = false): array
{
    $f = $d['faz8b'];
    $uyari = [];
    if (!$fiyatVar) $uyari[] = 'Bu tip için o tarihte geçerli fiyat YOK — fiyat girilene dek hakediş hesaplanamaz';
    $r = static function (string $durum, ?string $sinif, ?int $fm, string $neden, string $etiket = '', ?string $sonucSinif = null, int $fmSaat = 0) use (&$uyari): array {
        // Uyarı yalnız ilerleyebilen (onay/karar) satırlarda gösterilir; engelli satırda gürültü olur.
        return ['durum' => $durum, 'sinif' => $sinif, 'fm' => $fm, 'neden' => $neden, 'etiket' => $etiket,
                'uyari' => in_array($durum, ['onay', 'karar'], true) ? implode(' · ', $uyari) : '', 'sonuc_sinif' => $sonucSinif, 'fm_saat' => $fmSaat];
    };

    if ($kesin) return $r('uygunsuz', null, null, 'Hakediş KESİN — önce yeniden açılmalı');
    if (!empty($f['finans_hazir'])) return $r('atla', null, null, 'Zaten hazır');
    if ((int)($f['yevmiye'] ?? 0) > 0) return $r('atla', null, null, 'Yevmiyeli kayıt (onay gerekmez)');
    if (($d['worker_type_name_snapshot'] ?? '') === PDKS_GUNLUK_KARISIK_AD) return $r('uygunsuz', null, null, 'Karışık — önce Otomatik Ata');

    if ($otomatik && !pdks_toplu_onay_elle_mi($d['source'] ?? null)) {
        return $r('karar', null, null, 'Kart okutulmuş kayıt — otomatik onaylanmaz (elle seçip karar verin)');
    }

    $toplamDk = $f['toplam_dk'];
    if ($toplamDk === null) $uyari[] = 'Çıkış yok — süre bilinmiyor' . ($sinifKarari === 'yarim' ? ' (Yarım için 3 sa kuralı doğrulanamaz)' : '');

    $sinif = null;
    $etiket = [];
    // v509 (sahip kararı): Toplu Ekle ekranının "Yarım" kutusundan gelen dönem = declared_attendance_class 'yarim'
    // (kalıcı niyet işareti). Otomatik yolda bu işaret Yarım kararı yerine geçer; işaretsiz (eski) kayıt "karar" kalır.
    $yarimIsaretli = $otomatik && (string)($d['declared_attendance_class'] ?? '') === 'yarim';
    if ($yarimIsaretli && empty($f['sinif_onayi_gerekli'])) {
        return $r('karar', null, null, 'Yarım işaretli ama süre Tam eşiğinde (saatler değiştirilmiş olabilir) — karar gerekli');
    }
    if (!empty($f['sinif_onayi_gerekli'])) {
        if ($yarimIsaretli) {
            if ($toplamDk === null) return $r('karar', null, null, 'Çıkış yok — Yarım işaretli ama süre bilinmiyor, karar gerekli');
            if ((int)$toplamDk < PDKS_FAZ8B_YARIM_MIN_DK) return $r('karar', null, null, 'Yarım işaretli ama süre ' . pdks_faz8b_dakika_etiket((int)$toplamDk) . ' (3 saatten az) — karar gerekli');
            if ((int)$toplamDk > PDKS_TOPLU_ONAY_YARIM_TABAN_DK) $uyari[] = 'Yarım + ek süre: fazla mesai yalnız Tam eşiği sonrası sayılır, ek süre ücrete yansımaz';
            $sinif = 'yarim';
            $etiket[] = 'Yarım (toplu ekranda girildi)';
        } elseif ($otomatik) {
            // Sınıf ASLA tahmin edilmez: bu noktada süre Tam eşiğinin altında ya da bilinmiyor.
            if ($toplamDk === null) return $r('karar', null, null, 'Çıkış yok — Tam/Yarım belirlenemez, karar gerekli');
            if ((int)$toplamDk < PDKS_FAZ8B_YARIM_MIN_DK) return $r('karar', null, null, 'Süre ' . pdks_faz8b_dakika_etiket((int)$toplamDk) . ' (3 saatten az) — karar gerekli');
            return $r('karar', null, null, 'Süre ' . pdks_faz8b_dakika_etiket((int)$toplamDk) . ' — Tam mı Yarım mı belirsiz, karar gerekli');
        } else {
            if (!in_array($sinifKarari, ['tam', 'yarim'], true)) return $r('karar', null, null, 'Tam/Yarım kararı seçilmeli');
            if ($sinifKarari === 'yarim' && $toplamDk !== null && (int)$toplamDk < PDKS_FAZ8B_YARIM_MIN_DK) {
                return $r('uygunsuz', null, null, 'Yarım için en az ' . intdiv(PDKS_FAZ8B_YARIM_MIN_DK, 60) . ' sa gerekir (' . pdks_faz8b_dakika_etiket((int)$toplamDk) . ')');
            }
            $sinif = $sinifKarari;
            $etiket[] = pdks_faz8b_sinif_etiketi($sinif);
        }
    }

    $fm = null;
    $aday = (int)$f['fazla_mesai_saat'];
    if ($aday > 0) {
        $mevcut = $f['fazla_mesai_onay_saat'];
        if ($mevcut !== null) {
            $fm = (int)$mevcut;                      // zaten karar verilmiş — AYNEN geçirilir
        } elseif ($otomatik || $fmKarar === 'onayla') {
            $fm = $aday;
            $etiket[] = 'FM ' . $aday . ' sa onay';
        } elseif ($fmKarar === 'reddet') {
            $fm = 0;
            $etiket[] = 'FM reddet';
        } else {
            return $r('karar', null, null, 'Fazla mesai (' . $aday . ' sa) kararı seçilmeli');
        }
    }
    if ($otomatik && $sinif === null) $etiket = array_merge(['Tam'], $etiket);
    return $r('onay', $sinif, $fm, '', implode(' + ', $etiket) ?: 'Onay', $sinif ?? ($f['etkin_sinif'] ?? null), (int)($fm ?? 0));
}

/** Oturumların KESİN hakedişi var mı → [session_id => true]. */
function pdks_toplu_onay_kesin_oturumlar(array $sessionIds, PDO $pdo): array
{
    $sessionIds = array_values(array_unique(array_map('intval', $sessionIds)));
    if (!$sessionIds) return [];
    $in = implode(',', array_fill(0, count($sessionIds), '?'));
    $st = $pdo->prepare("SELECT DISTINCT session_id FROM foreman_daily_entitlements WHERE status = 'final' AND session_id IN ({$in})");
    $st->execute($sessionIds);
    $out = [];
    foreach ($st->fetchAll() as $row) $out[(int)$row['session_id']] = true;
    return $out;
}

/** Oturum satırları (çavuş adı, depo, tarih) → [session_id => satır]. */
function pdks_toplu_onay_oturumlar(array $sessionIds, PDO $pdo): array
{
    $sessionIds = array_values(array_unique(array_map('intval', $sessionIds)));
    if (!$sessionIds) return [];
    $in = implode(',', array_fill(0, count($sessionIds), '?'));
    $st = $pdo->prepare("SELECT id, foreman_id, foreman_name_snapshot, work_date, depo, status FROM daily_work_sessions WHERE id IN ({$in})");
    $st->execute($sessionIds);
    $out = [];
    foreach ($st->fetchAll() as $row) $out[(int)$row['id']] = $row;
    return $out;
}

/**
 * Onay BEKLEYEN dönemleri listeler (SALT OKUNUR): $tarih günü, aktif depo (boş depolu oturumlar dahil),
 * isteğe bağlı çavuş. Yalnız finans_hazir=false olanlar. Her satıra önceden bilinen engeller ve ham plan eklenir
 * ($satir['plan'] seçimsiz plan: "karar gerekli" gibi durumları gösterir).
 *
 * @return array<int,array> [['d' => dönem satırı, 'oturum' => oturum, 'kesin' => bool, 'fiyat_var' => bool, 'kaynak' => string, 'elle' => bool], ...]
 */
function pdks_toplu_onay_bekleyenler(string $tarih, string $depo, ?int $foremanId, bool $yalnizElle, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_sema_hazir($pdo)) return [];
    $kosul = 's.work_date = ? AND (s.depo = ? OR s.depo = \'\')';
    $param = [$tarih, $depo];
    if ($foremanId !== null) { $kosul .= ' AND s.foreman_id = ?'; $param[] = $foremanId; }

    $rows = [];
    foreach (pdks_faz8b_donem_sorgu($pdo, $kosul, $param) as $d) {
        if (!empty($d['faz8b']['finans_hazir'])) continue;
        if ($yalnizElle && !pdks_toplu_onay_elle_mi($d['source'] ?? null)) continue;
        $rows[] = $d;
    }
    return pdks_toplu_onay_zenginlestir($rows, $pdo);
}

/** Dönem satırlarına oturum/kesin/fiyat bilgisini ekler. */
function pdks_toplu_onay_zenginlestir(array $donemler, PDO $pdo): array
{
    $oturumlar = pdks_toplu_onay_oturumlar(array_column($donemler, 'session_id'), $pdo);
    $kesin = pdks_toplu_onay_kesin_oturumlar(array_column($donemler, 'session_id'), $pdo);
    $fiyatOnb = [];
    $out = [];
    foreach ($donemler as $d) {
        $sid = (int)$d['session_id'];
        $o = $oturumlar[$sid] ?? null;
        if (!$o) continue;
        $tip = (int)($d['worker_type_id_snapshot'] ?? 0);
        $k = (int)$o['foreman_id'] . ':' . $tip . ':' . $o['work_date'];
        if (!array_key_exists($k, $fiyatOnb)) {
            $fiyatOnb[$k] = $tip > 0 && pdks_hakedis_oran_gecerli((int)$o['foreman_id'], $tip, (string)$o['work_date'], $pdo) !== null;
        }
        $out[] = [
            'd' => $d, 'oturum' => $o, 'kesin' => isset($kesin[$sid]), 'fiyat_var' => $fiyatOnb[$k],
            'kaynak' => pdks_toplu_onay_kaynak_etiketi($d['source'] ?? null), 'elle' => pdks_toplu_onay_elle_mi($d['source'] ?? null),
        ];
    }
    return $out;
}

/**
 * Önizle / uygula (TEK fonksiyon: önizleme ile uygulama AYNI planı kullanır; uygulamada sunucu yeniden hesaplar,
 * istemciden plan ALINMAZ). Yalnız verilen id'ler işlenir.
 *
 * Kapılar: dönemin oturumu aktif depoya ait olmalı (pdks_gunluk_depo_kontrol); başka depodaki id SATIR olarak
 * 'uygunsuz' döner ve yazılmaz. Kesin hakediş / Karışık / Yarım<3sa → 'uygunsuz' (v508: çıkışsız ve fiyatsız artık engel DEĞİL — bkz. pdks_toplu_onay_plan).
 *
 * $uygula=true: tek transaction; planı 'onay' olanların HEPSİ pdks_faz8b_degerlendirme_kaydet()'ten geçer; biri
 * beklenmedik biçimde hata verirse HEPSİ geri alınır (hiçbiri yazılmaz). Sonda tek özet audit (toplu_onay + adet).
 *
 * v508: $otomatik=true → "Elle girilenleri otomatik onayla" (bkz. pdks_toplu_onay_plan); sınıf/FM seçicileri yok sayılır.
 *       $beklenenOnay: önizlemede gösterilen onay adedi; uygulamada sunucunun yeniden hesabı farklıysa HİÇBİR ŞEY yazılmaz.
 * @param int[] $periodIds
 * @return array{ok:bool, hata:?string, uygulandi:bool, satirlar:array, sayac:array{onay:int,karar:int,uygunsuz:int,atla:int,yok:int,tam:int,yarim:int,fm_saat:int,fiyatsiz:int}}
 */
function pdks_toplu_onay_calistir(array $periodIds, ?string $sinifKarari, ?string $fmKarar, int $userId, ?string $aktifDepo, bool $uygula, ?PDO $pdo = null, bool $otomatik = false, ?int $beklenenOnay = null): array
{
    $pdo = $pdo ?? db();
    $bos = ['onay' => 0, 'karar' => 0, 'uygunsuz' => 0, 'atla' => 0, 'yok' => 0, 'tam' => 0, 'yarim' => 0, 'fm_saat' => 0, 'fiyatsiz' => 0];
    $sonuc = static fn(bool $ok, ?string $hata, bool $uyg, array $satirlar, array $sayac): array =>
        ['ok' => $ok, 'hata' => $hata, 'uygulandi' => $uyg, 'satirlar' => $satirlar, 'sayac' => $sayac];

    if (!pdks_faz8b_sema_hazir($pdo)) return $sonuc(false, 'Faz 8B şeması hazır değil.', false, [], $bos);
    $sinifKarari = in_array($sinifKarari, ['tam', 'yarim'], true) ? $sinifKarari : null;
    $fmKarar = in_array($fmKarar, ['onayla', 'reddet'], true) ? $fmKarar : null;
    $periodIds = array_values(array_unique(array_filter(array_map('intval', $periodIds), static fn($i) => $i > 0)));
    if (!$periodIds) return $sonuc(false, 'En az bir satır seçmelisiniz.', false, [], $bos);
    if (count($periodIds) > PDKS_TOPLU_ONAY_LIMIT) return $sonuc(false, 'En çok ' . PDKS_TOPLU_ONAY_LIMIT . ' satır işlenebilir.', false, [], $bos);

    $donemler = [];
    $yok = [];
    foreach ($periodIds as $pid) {
        $d = pdks_faz8b_donem_getir($pid, $pdo);
        if ($d) $donemler[] = $d; else $yok[] = $pid;
    }
    $z = pdks_toplu_onay_zenginlestir($donemler, $pdo);

    $satirlar = [];
    $sayac = $bos;
    $sayac['yok'] = count($yok);
    foreach ($z as $s) {
        $depoHata = pdks_gunluk_depo_kontrol((string)$s['oturum']['depo'], $aktifDepo);
        $plan = $depoHata !== null
            ? ['durum' => 'uygunsuz', 'sinif' => null, 'fm' => null, 'neden' => $depoHata, 'etiket' => '', 'uyari' => '', 'sonuc_sinif' => null, 'fm_saat' => 0]
            : pdks_toplu_onay_plan($s['d'], $s['kesin'], $s['fiyat_var'], $otomatik ? null : $sinifKarari, $otomatik ? null : $fmKarar, $otomatik);
        $sayac[$plan['durum']]++;
        if ($plan['durum'] === 'onay') {
            if ($plan['sonuc_sinif'] === 'yarim') $sayac['yarim']++; else $sayac['tam']++;
            $sayac['fm_saat'] += (int)$plan['fm_saat'];
            if ($plan['uyari'] !== '' && !$s['fiyat_var']) $sayac['fiyatsiz']++;
        }
        $satirlar[] = $s + ['plan' => $plan];
    }

    if (!$uygula) return $sonuc(true, null, false, $satirlar, $sayac);
    if ($sayac['onay'] === 0) return $sonuc(false, 'Onaylanacak uygun satır yok.', false, $satirlar, $sayac);
    if ($otomatik && $beklenenOnay !== null && $sayac['onay'] !== $beklenenOnay) {
        return $sonuc(false, 'Liste önizlemeden sonra değişti (' . $beklenenOnay . ' → ' . $sayac['onay'] . ' kayıt). Hiçbir şey yazılmadı — önizlemeyi yenileyin.', false, $satirlar, $sayac);
    }

    $kendiTx = !$pdo->inTransaction();
    if ($kendiTx) $pdo->beginTransaction();
    $onaylanan = [];
    try {
        foreach ($satirlar as $s) {
            if ($s['plan']['durum'] !== 'onay') continue;
            $pid = (int)$s['d']['id'];
            $k = pdks_faz8b_degerlendirme_kaydet($pid, $s['plan']['sinif'], $s['plan']['fm'], $userId, $pdo);
            if (empty($k['ok'])) throw new RuntimeException("Dönem #{$pid}: " . ($k['hata'] ?? 'kaydedilemedi'));
            $onaylanan[] = $pid;
        }
        if ($kendiTx) $pdo->commit();
    } catch (Throwable $e) {
        if ($kendiTx && $pdo->inTransaction()) $pdo->rollBack();
        return $sonuc(false, 'Hiçbir kayıt yazılmadı (geri alındı): ' . $e->getMessage(), false, $satirlar, $sayac);
    }

    if (function_exists('audit_log_event')) {
        audit_log_event('toplu_onay', 'daily_worker_work_periods', null, null, [
            'adet' => count($onaylanan), 'mod' => $otomatik ? 'otomatik_elle' : 'secili', 'tam' => $sayac['tam'], 'yarim' => $sayac['yarim'], 'fm_saat' => $sayac['fm_saat'],
            'fiyatsiz' => $sayac['fiyatsiz'], 'sinif_karari' => $sinifKarari, 'fm_karar' => $fmKarar,
            'uygunsuz' => $sayac['uygunsuz'], 'karar_gerekli' => $sayac['karar'], 'period_ids' => $onaylanan,
        ]);
    }
    return $sonuc(true, null, true, $satirlar, $sayac);
}
