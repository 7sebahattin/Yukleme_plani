<?php
// =========================================================
// config/pdks_toplu_hizli.php — Toplu İşlem "kişi sayısı" girişi + Fazla Mesai dağıtımı (SAF, DB yok)
//
// Toplu İşlem penceresi saat yerine "Tam" kişi sayısı alır: mesai 08:00–17:00 (9 sa) otomatik dolar.
// "Fazla mesai" alanı: N kişi × S saat → kadın/erkek sayısına ORANTILI dağıtılır, çıkış uzar.
// Yazma yolu DEĞİŞMEDİ: bu dosya yalnız pdks_faz8j_toplu_hazirla()'nın beklediği `gruplar`ı üretir.
// JS aynası: _puantaj_toplu.php içindeki fmDagit() — kural AYNI olmalı (test denetler).
// Yarım mesai: 08:00–12:30 (Kadın/Erkek). FM havuzu: Kadın Tam, Erkek Tam, Kadın Yarım, Erkek Yarım, Şeritçi (Rampacı DEĞİL).
// Çift yevmiye: her Tam kategoride (Kadın/Erkek/Rampacı/Şeritçi) EK kişi sayısı (Tam'dan düşmez), 08:00–17:00,
// FM havuzuna GİRMEZ; grup `cift`=true → pdks_faz8j_satir_yaz() cift_yevmiye bayrağını yazar (kolon yoksa fail-closed).
// =========================================================
const PDKS_TOPLU_HIZLI_GIRIS = '08:00';
const PDKS_TOPLU_HIZLI_CIKIS = '17:00';
const PDKS_TOPLU_HIZLI_YARIM_CIKIS = '12:30';   // sahip kararı: yarım mesai 08:00–12:30
const PDKS_TOPLU_FM_UST_DK = 720;   // en çok 12 sa FM

/**
 * En büyük kalan yöntemiyle orantılı dağıtım. Tamamen tamsayı aritmetiği → deterministik.
 * @param array<string,int> $mevcut kategori => o kategorideki kişi sayısı (FM havuzu; sırası beraberlik sırasıdır)
 * @return array{ok:bool, dagilim:array<string,int>, hata:?string}
 * Beraberlikte: büyük kalan > büyük kategori mevcudu > $mevcut'taki sıra. Kategori mevcudunu AŞMAZ.
 */
function pdks_toplu_fm_dagit(array $mevcut, int $kisi): array {
    $dag = []; $toplam = 0; $sira = [];
    foreach ($mevcut as $k => $n) { $n = max(0, (int)$n); $mevcut[$k] = $n; $dag[$k] = 0; $toplam += $n; $sira[$k] = count($sira); }
    if ($kisi < 0) return ['ok' => false, 'dagilim' => $dag, 'hata' => 'Fazla mesai kişi sayısı geçersiz.'];
    if ($kisi === 0) return ['ok' => true, 'dagilim' => $dag, 'hata' => null];
    if ($kisi > $toplam) return ['ok' => false, 'dagilim' => $dag, 'hata' => 'Fazla mesai kişi sayısı (' . $kisi . ') çalışan sayısını (' . $toplam . ') aşıyor.'];
    $kalan = [];
    foreach ($mevcut as $k => $n) { $dag[$k] = intdiv($kisi * $n, $toplam); $kalan[$k] = ($kisi * $n) % $toplam; }
    $artan = $kisi - array_sum($dag);
    $adaylar = array_keys($mevcut);
    usort($adaylar, static fn($a, $b) => [$kalan[$b], $mevcut[$b], $sira[$a]] <=> [$kalan[$a], $mevcut[$a], $sira[$b]]);
    foreach ($adaylar as $k) {
        if ($artan <= 0) break;
        if ($dag[$k] < $mevcut[$k]) { $dag[$k]++; $artan--; }
    }
    return ['ok' => $artan === 0, 'dagilim' => $dag, 'hata' => $artan === 0 ? null : 'Dağıtım tamamlanamadı.'];
}

/** FM saati → dakika (0.25 adım, 0.25..12 sa). Geçersizse null. Virgül/nokta kabul. */
function pdks_toplu_fm_dakika($saat): ?int {
    if (!is_scalar($saat)) return null;
    $s = str_replace(',', '.', trim((string)$saat));
    if ($s === '' || !is_numeric($s)) return null;
    $dk = (int)round(((float)$s) * 60);
    if ($dk < 15 || $dk > PDKS_TOPLU_FM_UST_DK || $dk % 15 !== 0) return null;
    return $dk;
}

/** Bir toplu işlemde en çok kaç FM satırı (kişi + saat) girilebilir. */
const PDKS_TOPLU_FM_SATIR_UST = 20;

/**
 * FM girdisini satır listesine çevirir. Eski tek satır biçimi ['kisi'=>n,'saat'=>s] da kabul edilir;
 * yeni biçim [['kisi'=>n,'saat'=>s], ...]. Kişisi 0/boş satır YOK SAYILIR (boş "Ekle" satırı engel değil).
 * @return array{satirlar:array<int,array{kisi:int,dk:int}>, hata:?string}
 */
function pdks_toplu_fm_satirlari($fm): array {
    $cik = ['satirlar' => [], 'hata' => null];
    if (!is_array($fm)) return $cik;
    $liste = array_key_exists('kisi', $fm) || array_key_exists('saat', $fm) ? [$fm] : array_values($fm);
    if (count($liste) > PDKS_TOPLU_FM_SATIR_UST) { $cik['hata'] = 'En çok ' . PDKS_TOPLU_FM_SATIR_UST . ' fazla mesai satırı girilebilir.'; return $cik; }
    foreach ($liste as $i => $r) {
        if (!is_array($r)) continue;
        $kisi = is_scalar($r['kisi'] ?? null) ? (int)$r['kisi'] : 0;
        if ($kisi < 1) continue;
        $dk = pdks_toplu_fm_dakika($r['saat'] ?? null);
        if ($dk === null) { $cik['hata'] = 'Fazla mesai ' . ($i + 1) . '. satır: saat geçersiz (0,25 saat adımıyla, en çok 12 saat).'; return $cik; }
        $cik['satirlar'][] = ['kisi' => $kisi, 'dk' => $dk];
    }
    return $cik;
}

/**
 * ÇOKLU FM dağıtımı: satırlar GİRİŞ SIRASIYLA işlenir; her satır, öncekilerin düştüğü KALAN havuzdan
 * pdks_toplu_fm_dagit() ile (en büyük kalan) dağıtılır. Bir kişi tek satıra atanır: toplam kişi havuzu aşamaz.
 * Deterministik: aynı girdi + aynı satır sırası → aynı sonuç.
 * @param array<string,int> $mevcut kategori => kişi (havuz sırası beraberlik sırasıdır)
 * @param array<int,array{kisi:int,dk:int}> $satirlar
 * @return array{ok:bool, satirlar:array<int,array<string,int>>, toplam:array<string,int>, hata:?string}
 */
function pdks_toplu_fm_coklu_dagit(array $mevcut, array $satirlar): array {
    $toplam = []; foreach ($mevcut as $k => $n) { $mevcut[$k] = max(0, (int)$n); $toplam[$k] = 0; }
    $cik = ['ok' => false, 'satirlar' => [], 'toplam' => $toplam, 'hata' => null];
    $havuz = array_sum($mevcut); $istenen = 0;
    foreach ($satirlar as $r) $istenen += (int)$r['kisi'];
    if ($istenen > $havuz) { $cik['hata'] = 'Fazla mesai satırlarındaki toplam kişi (' . $istenen . ') fazla mesai havuzunu (' . $havuz . ' kişi) aşıyor; bir kişi yalnız tek satıra yazılabilir.'; return $cik; }
    $kalan = $mevcut;
    foreach (array_values($satirlar) as $i => $r) {
        $d = pdks_toplu_fm_dagit($kalan, (int)$r['kisi']);
        if (!$d['ok']) { $cik['hata'] = 'Fazla mesai ' . ($i + 1) . '. satır: ' . $d['hata']; return $cik; }
        foreach ($d['dagilim'] as $k => $n) { $kalan[$k] -= $n; $toplam[$k] += $n; }
        $cik['satirlar'][$i] = $d['dagilim'];
    }
    $cik['ok'] = true; $cik['toplam'] = $toplam;
    return $cik;
}

/** FM havuzu kategori sırası (beraberlik sırası). */
const PDKS_TOPLU_FM_HAVUZ = ['KADIN_TAM', 'ERKEK_TAM', 'KADIN_YARIM', 'ERKEK_YARIM', 'SERITCI'];

/**
 * "hizli" girdisinden toplu_hazirla `gruplar`ı üretir.
 * @param array $hizli ['tam'=>[tipId=>adet], 'yarim'=>[tipId=>adet] (yalnız KADIN/ERKEK), 'fm'=>['kisi'=>n,'saat'=>s]]
 * @param array<int,string> $tipKod tipId => KOD (KADIN/ERKEK/SERITCI/RAMPACI)
 * @return array{gruplar:array, hata:?string, dagilim:array}
 * FM havuzu = Kadın Tam, Erkek Tam, Kadın Yarım, Erkek Yarım, Şeritçi; en büyük kalanla orantılı.
 * FM alan yarım kişinin çıkışı 12:30 + FM olur. Rampacı havuza girmez (yalnız Tam).
 */
function pdks_toplu_hizli_gruplar(array $hizli, array $tipKod, string $workDate): array {
    $cik = ['gruplar' => [], 'hata' => null, 'dagilim' => [], 'fm_satirlar' => []];
    $adetler = [];   // [tipId, tur('TAM'|'YARIM'), adet]
    foreach (['tam' => 'TAM', 'yarim' => 'YARIM'] as $anahtar => $tur) {
        foreach ((is_array($hizli[$anahtar] ?? null) ? $hizli[$anahtar] : []) as $tid => $n) {
            $tid = (int)$tid; $n = is_scalar($n) ? (int)$n : 0;
            if ($tid < 1 || !isset($tipKod[$tid]) || $n < 1) continue;
            if ($tur === 'YARIM' && !in_array($tipKod[$tid], ['KADIN', 'ERKEK'], true)) continue;   // yarım yalnız Kadın/Erkek
            $adetler[] = [$tid, $tur, $n];
        }
    }
    $kat = static fn(int $tid, string $tur): ?string => in_array($tipKod[$tid], ['KADIN', 'ERKEK'], true) ? $tipKod[$tid] . '_' . $tur
        : ($tipKod[$tid] === 'SERITCI' ? 'SERITCI' : null);
    $fmSat = pdks_toplu_fm_satirlari($hizli['fm'] ?? null);
    if ($fmSat['hata'] !== null) { $cik['hata'] = $fmSat['hata']; return $cik; }
    $fmKat = [];   // satır index => kategori => kişi
    if ($fmSat['satirlar']) {
        $mevcut = [];
        foreach (PDKS_TOPLU_FM_HAVUZ as $k) $mevcut[$k] = 0;
        foreach ($adetler as [$tid, $tur, $n]) { $k = $kat($tid, $tur); if ($k !== null) $mevcut[$k] += $n; }
        $d = pdks_toplu_fm_coklu_dagit($mevcut, $fmSat['satirlar']);
        if (!$d['ok']) { $cik['hata'] = $d['hata']; return $cik; }
        $cik['dagilim'] = $d['toplam'];
        $fmKat = $d['satirlar'];
        $cik['fm_satirlar'] = [];
        foreach ($fmSat['satirlar'] as $i => $r) $cik['fm_satirlar'][] = ['kisi' => $r['kisi'], 'dk' => $r['dk'], 'dagilim' => $fmKat[$i]];
    }
    // Çift yevmiye (Tam kategorilere EK; yarım yok; FM'siz): 08:00–17:00 grubu, cift bayrağı.
    $ciftGruplar = [];
    foreach ((is_array($hizli['cift'] ?? null) ? $hizli['cift'] : []) as $tid => $n) {
        $tid = (int)$tid; $n = is_scalar($n) ? (int)$n : 0;
        if ($tid < 1 || !isset($tipKod[$tid]) || $n < 1) continue;
        if (!in_array($tipKod[$tid], ['KADIN', 'ERKEK', 'RAMPACI', 'SERITCI'], true)) continue;
        $ciftGruplar[] = ['worker_type_id' => $tid, 'entry_clock' => PDKS_TOPLU_HIZLI_GIRIS, 'exit_date' => $workDate, 'exit_clock' => PDKS_TOPLU_HIZLI_CIKIS,
                          'kart_ids' => [], 'kartsiz_adet' => $n, 'etiket' => $tipKod[$tid] . ' Çift', 'cift' => true];
    }
    $cikisSaat = static function (string $saat, int $ekDk) use ($workDate): array {
        $t = (new DateTimeImmutable($workDate . ' ' . $saat . ':00'))->modify('+' . $ekDk . ' minutes');
        return [$t->format('Y-m-d'), $t->format('H:i')];
    };
    foreach ($adetler as [$tid, $tur, $n]) {
        $k = $kat($tid, $tur);
        $fmSatirN = [];   // FM satır index => bu kategoriye düşen kişi
        if ($k !== null) foreach ($fmKat as $i => $dag) if (($dag[$k] ?? 0) > 0) $fmSatirN[$i] = (int)$dag[$k];
        $fmN = array_sum($fmSatirN);
        $baz = $tur === 'YARIM' ? PDKS_TOPLU_HIZLI_YARIM_CIKIS : PDKS_TOPLU_HIZLI_CIKIS;
        $ad = $tipKod[$tid] . ($tur === 'YARIM' ? ' Yarım' : ' Tam');
        if ($n - $fmN > 0) {
            [$cd, $cs] = $cikisSaat($baz, 0);
            $cik['gruplar'][] = ['worker_type_id' => $tid, 'entry_clock' => PDKS_TOPLU_HIZLI_GIRIS, 'exit_date' => $cd, 'exit_clock' => $cs,
                                 'kart_ids' => [], 'kartsiz_adet' => $n - $fmN, 'etiket' => $ad];
        }
        // Her FM satırı AYRI grup (farklı saat → farklı çıkış). Kategori = tip+tür tekil olduğundan kişi çift sayılmaz.
        foreach ($fmSatirN as $i => $fmKisi) {
            $dk = $fmSat['satirlar'][$i]['dk'];
            [$cd, $cs] = $cikisSaat($baz, $dk);
            $cik['gruplar'][] = ['worker_type_id' => $tid, 'entry_clock' => PDKS_TOPLU_HIZLI_GIRIS, 'exit_date' => $cd, 'exit_clock' => $cs,
                                 'kart_ids' => [], 'kartsiz_adet' => $fmKisi, 'etiket' => $ad . ' + FM ' . rtrim(rtrim(number_format($dk / 60, 2, ',', ''), '0'), ',') . ' sa'];
        }
    }
    foreach ($ciftGruplar as $cg) $cik['gruplar'][] = $cg;
    return $cik;
}

/**
 * HTTP ucu için: "hizli" girdisini sunucuda gruplara çevirir ve toplu_hazirla'ya EK anahtarları (aynı tip çok grup,
 * denetim özeti) SUNUCU tarafında kurar — istemci bu anahtarları gönderemez. SAF (DB yok): tip kodu çözümü $tipKodu ile.
 * @param callable $tipKodu fn(int $tipId): ?string  (KADIN/ERKEK/RAMPACI/SERITCI ya da null)
 * @return array{hata:?string, gruplar:array, ek:array}  gruplar = üretilenler + istemci grupları; ek = toplu girdiye eklenecek anahtarlar
 */
function pdks_toplu_hizli_isle($hizli, string $workDate, array $istemciGruplar, callable $tipKodu): array {
    $cik = ['hata' => null, 'gruplar' => $istemciGruplar, 'ek' => []];
    if (!is_array($hizli)) return $cik;
    $tipKod = [];
    foreach (['tam', 'yarim', 'cift'] as $a) {
        foreach (array_keys(is_array($hizli[$a] ?? null) ? $hizli[$a] : []) as $tid) {
            $kod = $tipKodu((int)$tid);
            if ($kod !== null && $kod !== '') $tipKod[(int)$tid] = $kod;
        }
    }
    $h = pdks_toplu_hizli_gruplar($hizli, $tipKod, $workDate);
    if ($h['hata'] !== null) { $cik['hata'] = $h['hata']; return $cik; }
    if ($h['gruplar']) {
        $cik['gruplar'] = array_merge($h['gruplar'], $istemciGruplar);
        $cik['ek'] = [
            'coklu_grup' => true,
            'audit_ek' => ['hizli_giris' => [
                'gruplar' => array_map(static fn($g) => ['etiket' => $g['etiket'], 'adet' => $g['kartsiz_adet'], 'cikis' => $g['exit_date'] . ' ' . $g['exit_clock'], 'cift' => !empty($g['cift'])], $h['gruplar']),
                'fm_dagilim' => $h['dagilim'],
                'fm_satirlar' => $h['fm_satirlar'] ?? [],
            ]],
        ];
    }
    return $cik;
}
