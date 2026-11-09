<?php
// =========================================================
// config/pdks_toplu_hizli.php — Toplu İşlem "kişi sayısı" girişi + Fazla Mesai dağıtımı (SAF, DB yok)
//
// Toplu İşlem penceresi saat yerine "Tam" kişi sayısı alır: mesai 08:00–17:00 (9 sa) otomatik dolar.
// "Fazla mesai" alanı: N kişi × S saat → kadın/erkek sayısına ORANTILI dağıtılır, çıkış uzar.
// Yazma yolu DEĞİŞMEDİ: bu dosya yalnız pdks_faz8j_toplu_hazirla()'nın beklediği `gruplar`ı üretir.
// JS aynası: _puantaj_toplu.php içindeki fmDagit() — kural AYNI olmalı (test denetler).
// Yarım mesai: 08:00–12:30 (Kadın/Erkek). FM havuzu: Kadın Tam, Erkek Tam, Kadın Yarım, Erkek Yarım, Şeritçi (Rampacı DEĞİL).
// Çift yevmiye kişi sayısı: bayrak altyapısı başka ajanda — BURADA YOK.
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
    $cik = ['gruplar' => [], 'hata' => null, 'dagilim' => []];
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
    $fm = is_array($hizli['fm'] ?? null) ? $hizli['fm'] : [];
    $fmKisi = is_scalar($fm['kisi'] ?? null) ? (int)$fm['kisi'] : 0;
    $fmDk = 0; $fmKat = [];
    if ($fmKisi > 0) {
        $fmDk = (int)pdks_toplu_fm_dakika($fm['saat'] ?? null);
        if ($fmDk === 0) { $cik['hata'] = 'Fazla mesai saati geçersiz (0,25 saat adımıyla, en çok 12 saat).'; return $cik; }
        $mevcut = [];
        foreach (PDKS_TOPLU_FM_HAVUZ as $k) $mevcut[$k] = 0;
        foreach ($adetler as [$tid, $tur, $n]) { $k = $kat($tid, $tur); if ($k !== null) $mevcut[$k] += $n; }
        $d = pdks_toplu_fm_dagit($mevcut, $fmKisi);
        if (!$d['ok']) { $cik['hata'] = $d['hata']; return $cik; }
        $cik['dagilim'] = $fmKat = $d['dagilim'];
    }
    $cikisSaat = static function (string $saat, int $ekDk) use ($workDate): array {
        $t = (new DateTimeImmutable($workDate . ' ' . $saat . ':00'))->modify('+' . $ekDk . ' minutes');
        return [$t->format('Y-m-d'), $t->format('H:i')];
    };
    foreach ($adetler as [$tid, $tur, $n]) {
        $k = $kat($tid, $tur);
        $fmN = $k !== null ? ($fmKat[$k] ?? 0) : 0;
        $baz = $tur === 'YARIM' ? PDKS_TOPLU_HIZLI_YARIM_CIKIS : PDKS_TOPLU_HIZLI_CIKIS;
        $ad = $tipKod[$tid] . ($tur === 'YARIM' ? ' Yarım' : ' Tam');
        if ($n - $fmN > 0) {
            [$cd, $cs] = $cikisSaat($baz, 0);
            $cik['gruplar'][] = ['worker_type_id' => $tid, 'entry_clock' => PDKS_TOPLU_HIZLI_GIRIS, 'exit_date' => $cd, 'exit_clock' => $cs,
                                 'kart_ids' => [], 'kartsiz_adet' => $n - $fmN, 'etiket' => $ad];
        }
        if ($fmN > 0) {
            // Aynı kategoriyi paylaşan birden çok tip olamaz (kategori = tip+tür); FM'nin tamamı bu satıra yazılır.
            [$cd, $cs] = $cikisSaat($baz, $fmDk);
            $cik['gruplar'][] = ['worker_type_id' => $tid, 'entry_clock' => PDKS_TOPLU_HIZLI_GIRIS, 'exit_date' => $cd, 'exit_clock' => $cs,
                                 'kart_ids' => [], 'kartsiz_adet' => $fmN, 'etiket' => $ad . ' + FM'];
        }
    }
    return $cik;
}
