<?php
// =========================================================
// config/pdks_toplu_hizli.php — Toplu İşlem "kişi sayısı" girişi + Fazla Mesai dağıtımı (SAF, DB yok)
//
// Toplu İşlem penceresi saat yerine "Tam" kişi sayısı alır: mesai 08:00–17:00 (9 sa) otomatik dolar.
// "Fazla mesai" alanı: N kişi × S saat → kadın/erkek sayısına ORANTILI dağıtılır, çıkış uzar.
// Yazma yolu DEĞİŞMEDİ: bu dosya yalnız pdks_faz8j_toplu_hazirla()'nın beklediği `gruplar`ı üretir.
// JS aynası: _puantaj_toplu.php içindeki fmDagit() — kural AYNI olmalı (test denetler).
// ÇÖZÜLMEMİŞ (sahip yanıtı bekleniyor): yarım mesai varsayılan saatleri ve kadın yarım — burada YOK.
// =========================================================
const PDKS_TOPLU_HIZLI_GIRIS = '08:00';
const PDKS_TOPLU_HIZLI_CIKIS = '17:00';
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

/**
 * "hizli" girdisinden toplu_hazirla `gruplar`ı üretir.
 * @param array $hizli ['tam'=>[tipId=>adet], 'fm'=>['kisi'=>n,'saat'=>s]]
 * @param array<int,string> $tipKod tipId => KOD (KADIN/ERKEK/SERITCI/RAMPACI)
 * @return array{gruplar:array, hata:?string, dagilim:array}
 * FM havuzu = yalnız KADIN + ERKEK Tam sayıları (şeritçi/rampacı/yarım havuza girmez — sahip onayı bekliyor).
 */
function pdks_toplu_hizli_gruplar(array $hizli, array $tipKod, string $workDate): array {
    $cik = ['gruplar' => [], 'hata' => null, 'dagilim' => []];
    $tam = [];
    foreach ((is_array($hizli['tam'] ?? null) ? $hizli['tam'] : []) as $tid => $n) {
        $tid = (int)$tid; $n = is_scalar($n) ? (int)$n : 0;
        if ($tid > 0 && isset($tipKod[$tid]) && $n > 0) $tam[$tid] = $n;
    }
    $fm = is_array($hizli['fm'] ?? null) ? $hizli['fm'] : [];
    $fmKisi = is_scalar($fm['kisi'] ?? null) ? (int)$fm['kisi'] : 0;
    $fmDk = 0; $fmTip = [];   // tipId => FM kişi
    if ($fmKisi > 0) {
        $fmDk = (int)pdks_toplu_fm_dakika($fm['saat'] ?? null);
        if ($fmDk === 0) { $cik['hata'] = 'Fazla mesai saati geçersiz (0,25 saat adımıyla, en çok 12 saat).'; return $cik; }
        $havuz = []; $kodTip = [];
        foreach ($tam as $tid => $n) { $kod = $tipKod[$tid]; if ($kod === 'KADIN' || $kod === 'ERKEK') { $havuz[$kod] = $n; $kodTip[$kod] = $tid; } }
        $d = pdks_toplu_fm_dagit($havuz, $fmKisi);
        if (!$d['ok']) { $cik['hata'] = $d['hata']; return $cik; }
        $cik['dagilim'] = $d['dagilim'];
        foreach ($d['dagilim'] as $kod => $n) if ($n > 0) $fmTip[$kodTip[$kod]] = $n;
    }
    $cikisSaat = static function (int $ekDk) use ($workDate): array {
        $t = new DateTimeImmutable($workDate . ' ' . PDKS_TOPLU_HIZLI_CIKIS . ':00');
        $t = $t->modify('+' . $ekDk . ' minutes');
        return [$t->format('Y-m-d'), $t->format('H:i')];
    };
    foreach ($tam as $tid => $n) {
        $fmN = $fmTip[$tid] ?? 0;
        $ad = $tipKod[$tid];
        if ($n - $fmN > 0) {
            [$cd, $cs] = $cikisSaat(0);
            $cik['gruplar'][] = ['worker_type_id' => $tid, 'entry_clock' => PDKS_TOPLU_HIZLI_GIRIS, 'exit_date' => $cd, 'exit_clock' => $cs,
                                 'kart_ids' => [], 'kartsiz_adet' => $n - $fmN, 'etiket' => $ad . ' Tam'];
        }
        if ($fmN > 0) {
            [$cd, $cs] = $cikisSaat($fmDk);
            $cik['gruplar'][] = ['worker_type_id' => $tid, 'entry_clock' => PDKS_TOPLU_HIZLI_GIRIS, 'exit_date' => $cd, 'exit_clock' => $cs,
                                 'kart_ids' => [], 'kartsiz_adet' => $fmN, 'etiket' => $ad . ' Tam + FM'];
        }
    }
    return $cik;
}
