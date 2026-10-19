<?php
// =========================================================
// config/siparis_pkl.php — Packing List (PKL v1.4) üretimi ve okunması
//
// Müşterinin KENDİ şablonu (templates/excel/pkl_v14_blank.xlsx) DOĞRUDAN XML
// yamalamasıyla doldurulur: yalnız sheet1.xml'deki BOŞ hücreler
// (<c r=".." s=".."/>) değer alır, stil indeksi AYNEN kalır; sharedStrings
// yeniden yazılır, diğer tüm parçalar bayt bayt aynı kalır.
//
// ⚠ PhpSpreadsheet ile PKL ÜRETME: yük-kaydet döngüsü doğrulamaları/stilleri
//   bozar (14 sn / 320 MB). Bu dosya yalnız ZipArchive + PCRE kullanır.
// ⚠ Şablonu Excel'de açıp kaydetme — siparis_pkl_smoke stil karşılaştırması kırılır.
//
// Güvenlik: her metin siparis_pkl_esc()'ten geçer (XML 1.0'da geçersiz denetim
// karakterleri atılır), metinler sharedStrings'e gider — formül OLARAK ASLA
// yazılmaz ("=HYPERLINK(..)" metin kalır). 'n' tipli değer sayısal değilse
// 's' olarak yazılır (veri kaybı yok). Çıktı önce .part'a yazılır, sonra rename.
// =========================================================
declare(strict_types=1);

require_once __DIR__ . '/siparis_core.php';
require_once __DIR__ . '/siparis_eslestir.php';

const SIPARIS_PKL_ILK_SATIR = 17;
const SIPARIS_PKL_MAX_PALET = 300;
/** Şablonda SABİT duran başlık hücreleri (tedarikçi no/adı, EXW, TRUCK) — üretimde yazılmaz. */
const SIPARIS_PKL_HDR_KEEP = ['C2', 'C3', 'C6', 'C7'];
/** Veri satırı sütun tipleri: s=metin n=sayı d=tarih (Y-m-d → Excel seri günü). */
const SIPARIS_PKL_SUTUN_TIP = ['B' => 's', 'C' => 'n', 'D' => 'n', 'E' => 's', 'F' => 's', 'G' => 's', 'H' => 's', 'I' => 's', 'J' => 's',
    'K' => 'n', 'L' => 's', 'M' => 'n', 'N' => 'n', 'O' => 'd', 'P' => 'd', 'Q' => 's', 'R' => 's', 'S' => 'n', 'T' => 'n', 'U' => 'n',
    'V' => 's', 'W' => 'n', 'X' => 's', 'Y' => 's', 'Z' => 's', 'AA' => 'd', 'AB' => 's'];
const SIPARIS_PKL_BASLIK_TIP = ['C4' => 's', 'C5' => 'd', 'C8' => 's', 'C9' => 'n', 'C10' => 'n', 'C11' => 's', 'C12' => 'd', 'C13' => 'd'];

// ── DÜŞÜK SEVİYE XML / ZIP ──────────────────────────────────────────────

/** XML 1.0'da geçersiz denetim karakterlerini atar. */
function siparis_pkl_temiz(string $s): string
{
    return (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
}

/** Metin → XML (denetim karakterleri atılmış, ENT_XML1|ENT_QUOTES). */
function siparis_pkl_esc(string $s): string
{
    return htmlspecialchars(siparis_pkl_temiz($s), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Y-m-d → Excel 1900 tarih seri numarası (UTC, saat yok). */
function siparis_pkl_serial(string $ymd): int
{
    return (int)((strtotime($ymd . ' 00:00:00 UTC') - strtotime('1899-12-30 00:00:00 UTC')) / 86400);
}

/** Excel seri günü → Y-m-d. */
function siparis_pkl_serial_tarih(float $seri): string
{
    return gmdate('Y-m-d', (int)round(($seri - 25569) * 86400));
}

/** sharedStrings.xml → düz metin listesi (zengin metin parçaları birleştirilir, fonetik <rPh> atılır). */
function siparis_pkl_sst_oku(string $xml): array
{
    $xml = (string)preg_replace('#<rPh\b.*?</rPh>#s', '', $xml);
    preg_match_all('#<si>(.*?)</si>#s', $xml, $m);
    $out = [];
    foreach ($m[1] as $si) {
        preg_match_all('#<t(?:\s[^>]*)?>(.*?)</t>#s', $si, $t);
        $out[] = html_entity_decode(implode('', $t[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
    return $out;
}

/** Metin listesi → sharedStrings.xml. */
function siparis_pkl_sst_yaz(array $strings, int $refCount): string
{
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
       . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $refCount . '" uniqueCount="' . count($strings) . '">';
    foreach ($strings as $s) {
        $s = (string)$s;
        $x .= '<si><t' . (preg_match('/^\s|\s$|\s\s/', $s) ? ' xml:space="preserve"' : '') . '>' . siparis_pkl_esc($s) . '</t></si>';
    }
    return $x . '</sst>';
}

/** Zip'i girdi SIRASINI koruyarak yeniden yazar; $replace içindeki girdilerin içeriği değişir. */
function siparis_pkl_zip_yaz(string $src, string $dst, array $replace): void
{
    $in = new ZipArchive();
    if ($in->open($src) !== true) throw new RuntimeException('PKL şablonu açılamadı.');
    $out = new ZipArchive();
    @unlink($dst);
    if ($out->open($dst, ZipArchive::CREATE) !== true) { $in->close(); throw new RuntimeException('PKL dosyası oluşturulamadı.'); }
    for ($i = 0; $i < $in->numFiles; $i++) {
        $name = (string)$in->getNameIndex($i);
        $icerik = $replace[$name] ?? $in->getFromIndex($i);
        if ($icerik === false) { $in->close(); $out->close(); @unlink($dst); throw new RuntimeException('PKL şablonu okunamadı: ' . $name); }
        $out->addFromString($name, (string)$icerik);
        $out->setCompressionName($name, ZipArchive::CM_DEFLATE);
    }
    $in->close();
    if (!$out->close()) { @unlink($dst); throw new RuntimeException('PKL dosyası yazılamadı.'); }
}

/**
 * Şablonu doldurur. $hdr: ['C4'=>['s','AsyaDoc004'], 'C5'=>['d','2026-10-16'], ...] (null = atla),
 * $rows: satır listesi ['B'=>['s',..], 'C'=>['n',..], ...] → 17. satırdan itibaren.
 * Hücre şablonda BOŞ değilse ya da aralık dışındaysa RuntimeException. Çıktı .part → rename.
 */
function siparis_pkl_doldur(string $sablon, array $hdr, array $rows, string $hedef): void
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('Sunucuda ZipArchive yok.');
    if (count($rows) > SIPARIS_PKL_MAX_PALET) throw new RuntimeException('PKL en çok ' . SIPARIS_PKL_MAX_PALET . ' satır alabilir.');
    $z = new ZipArchive();
    if ($z->open($sablon) !== true) throw new RuntimeException('PKL şablonu açılamadı.');
    $sheet = $z->getFromName('xl/worksheets/sheet1.xml');
    $sstXml = $z->getFromName('xl/sharedStrings.xml');
    $z->close();
    if (!is_string($sheet) || !is_string($sstXml)) throw new RuntimeException('PKL şablonu eksik.');
    $sst = siparis_pkl_sst_oku($sstXml);
    $idx = [];
    foreach ($sst as $i => $s) if (!isset($idx[$s])) $idx[$s] = $i;
    $str = function (string $s) use (&$sst, &$idx): int {
        $s = siparis_pkl_temiz($s);
        if (!isset($idx[$s])) { $idx[$s] = count($sst); $sst[] = $s; }
        return $idx[$s];
    };

    $cells = [];                            // koordinat => [tip, değer]
    foreach ($hdr as $c => $v) if ($v !== null) $cells[(string)$c] = $v;
    foreach (array_values($rows) as $i => $r) foreach ($r as $col => $v) if ($v !== null) $cells[$col . (SIPARIS_PKL_ILK_SATIR + $i)] = $v;

    $apply = function (string $slice) use (&$cells, $str): string {
        return (string)preg_replace_callback('#<c r="([A-Z]+\d+)"( s="\d+")/>#', function (array $m) use (&$cells, $str): string {
            if (!isset($cells[$m[1]])) return $m[0];
            [$t, $v] = $cells[$m[1]];
            unset($cells[$m[1]]);
            if ($t === 'n' && !preg_match('/^-?\d+(\.\d+)?$/', (string)$v)) $t = 's';      // sayı değilse metin (veri kaybı yok)
            if ($t === 'd' && (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v))) $t = 's';
            return match ($t) {
                'n' => '<c r="' . $m[1] . '"' . $m[2] . '><v>' . $v . '</v></c>',
                'd' => '<c r="' . $m[1] . '"' . $m[2] . '><v>' . siparis_pkl_serial((string)$v) . '</v></c>',
                default => '<c r="' . $m[1] . '"' . $m[2] . ' t="s"><v>' . $str((string)$v) . '</v></c>',
            };
        }, $slice);
    };
    $a = strpos($sheet, '<row r="2"'); $b = strpos($sheet, '<row r="14"'); $c = strpos($sheet, '<row r="' . SIPARIS_PKL_ILK_SATIR . '"');
    if ($a === false || $b === false || $c === false) throw new RuntimeException('PKL şablonunun düzeni beklenenden farklı.');
    $sonSatir = SIPARIS_PKL_ILK_SATIR + max(0, count($rows) - 1) + 1;
    $d = strpos($sheet, '<row r="' . ($sonSatir + 1) . '"');
    if ($d === false) $d = strpos($sheet, '</sheetData>');
    if ($d === false) throw new RuntimeException('PKL şablonunun düzeni beklenenden farklı.');
    $sheet = substr($sheet, 0, $a) . $apply(substr($sheet, $a, $b - $a)) . substr($sheet, $b, $c - $b)
           . $apply(substr($sheet, $c, $d - $c)) . substr($sheet, $d);
    if ($cells) throw new RuntimeException('PKL hücreleri yazılamadı (şablonda dolu ya da aralık dışı): ' . implode(',', array_slice(array_keys($cells), 0, 10)));

    preg_match_all('# t="s"><v>#', $sheet, $x);   // başvuru sayısı: şablondakiler + yeniler
    $part = $hedef . '.part';
    try {
        siparis_pkl_zip_yaz($sablon, $part, ['xl/worksheets/sheet1.xml' => $sheet, 'xl/sharedStrings.xml' => siparis_pkl_sst_yaz($sst, count($x[0]))]);
        if (!@rename($part, $hedef)) throw new RuntimeException('PKL dosyası yerine taşınamadı.');
    } finally {
        if (is_file($part)) @unlink($part);
    }
}

// ── SAF YARDIMCILAR ─────────────────────────────────────────────────────

/** Lot no: "L " + ISO hafta + " - " + 2 haneli ISO gün (paketleme tarihi). CW'den TÜRETİLMEZ. */
function siparis_pkl_lot(string $ymd): string
{
    $t = strtotime($ymd . ' 12:00:00');
    if ($t === false) return '';
    return sprintf('L %s - %02d', date('W', $t), (int)date('N', $t));
}

/** PKL-<ref>-<no>-<gg.aa.yyyy>.xlsx — parçalar [A-Za-z0-9.-] ile temizlenir, sonda '_' yok. */
function siparis_pkl_dosya_adi(string $orderRef, string $belgeNo, string $belgeTarihiYmd): string
{
    $tem = fn(string $s): string => trim((string)preg_replace('/[^A-Za-z0-9.\-]/', '', $s), '._-');
    $tarih = siparis_tarih_coz($belgeTarihiYmd);
    $parcalar = array_values(array_filter(['PKL', $tem($orderRef), $tem($belgeNo), $tarih !== null ? date('d.m.Y', (int)strtotime($tarih)) : ''], fn($x) => $x !== ''));
    return implode('-', $parcalar) . '.xlsx';
}

/** Sayıyı PKL 'n' hücresine uygun kanonik metne çevirir (TR biçimi num() ile); boş/geçersiz → null. */
function siparis_pkl_sayi(mixed $v): ?string
{
    if ($v === null || $v === '' || is_bool($v)) return null;
    if (is_int($v)) return (string)$v;
    if (is_float($v)) {
        if (!is_finite($v)) return null;
        return $v == floor($v) && abs($v) < 1e15 ? (string)(int)$v : rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
    }
    $s = trim((string)$v);
    if ($s === '') return null;
    if (preg_match('/^-?\d+$/', $s)) return ltrim($s, '+');
    if (!preg_match('/^-?[\d.,\s]+$/', $s)) return null;
    return siparis_pkl_sayi(num($s));
}

/** GS1 (GLN/GGN, 13 hane) kontrol basamağı doğru mu. */
function siparis_pkl_gs1_ok(string $d): bool
{
    if (!preg_match('/^\d{13}$/', $d)) return false;
    $t = 0;
    for ($i = 0; $i < 12; $i++) $t += (int)$d[$i] * ($i % 2 === 0 ? 1 : 3);
    return (10 - $t % 10) % 10 === (int)$d[12];
}

/** Y-m-d'ye gün ekler; geçersiz → null. */
function siparis_pkl_gun_ekle(?string $ymd, int $gun): ?string
{
    $ymd = $ymd !== null ? siparis_tarih_coz($ymd) : null;
    if ($ymd === null) return null;
    return date('Y-m-d', (int)strtotime($ymd . ' 12:00:00 ' . ($gun >= 0 ? '+' : '') . $gun . ' day'));
}

// ── VERİ (veri_json) ────────────────────────────────────────────────────

/**
 * Varsayılan veri_json: etkin_palet > 0 olan her kalem için bir blok (kalibre artan),
 * ortak varsayılanlar (K sezon, P yükleme tarihi, O = P − 1, R/S paketevi sabitleri,
 * V..AB profilden). M/N (brüt/net) ELLE girilir → boş.
 */
function siparis_pkl_varsayilan(array $sev, array $kalemler, ?array $profil): array
{
    $bloklar = [];
    $sirali = array_values(array_filter($kalemler, fn($k) => (int)($k['etkin_palet'] ?? 0) > 0));
    usort($sirali, fn($a, $b) => [(float)$a['kalibre'], (string)$a['kalibre'], (int)$a['id']] <=> [(float)$b['kalibre'], (string)$b['kalibre'], (int)$b['id']]);
    foreach ($sirali as $k) {
        $bloklar[] = [
            'kalem_id' => (int)$k['id'],
            'kalibre'  => (string)$k['kalibre'],
            'palet'    => (int)$k['etkin_palet'],
            'D'        => $k['artikel_no'] !== null && $k['artikel_no'] !== '' ? (string)$k['artikel_no'] : null,
            'F'        => $k['cesit'] !== null && $k['cesit'] !== '' ? (string)$k['cesit'] : null,
            'H'        => $k['koli_icerik'] !== null && $k['koli_icerik'] !== '' ? mb_strtolower((string)$k['koli_icerik'], 'UTF-8') : null,
            'T'        => $k['pf'] !== null ? (int)$k['pf'] : null,
            'M'        => null,
            'N'        => null,
        ];
    }
    $p = siparis_tarih_coz((string)($sev['yukleme_tarihi'] ?? ''));
    $sezon = siparis_sezon($p);
    $pr = $profil ?? [];
    $pv = fn(string $k): string => isset($pr[$k]) && $pr[$k] !== null ? (string)$pr[$k] : '';
    return [
        'bloklar'    => $bloklar,
        'varsayilan' => [
            'J' => '', 'K' => (int)$sezon, 'L' => '', 'M' => null, 'N' => null,
            'O' => siparis_pkl_gun_ekle($p, -1) ?? '', 'P' => $p ?? '',
            'R' => SIPARIS_PAKETEVI_AD, 'S' => SIPARIS_PAKETEVI_GLN,
            'V' => $pv('uretici_adi'), 'W' => $pv('ggn'), 'X' => $pv('tarla_kodu'), 'Y' => $pv('izlenebilirlik_kodu'),
            'Z' => $pv('kalinti_rapor_no'), 'AA' => $pv('kalinti_tarihi'), 'AB' => $pv('lab_adi'),
        ],
        'satir'             => [],
        'belge_no_istenen'  => null,
        'palet_bas_istenen' => null,
        'profil_id'         => isset($pr['id']) ? (int)$pr['id'] : null,
    ];
}

/** Bir girdi değerini PKL sütun tipine göre temizler: sayı → kanonik metin, tarih → Y-m-d, metin ≤255. */
function siparis_pkl_deger_temizle(mixed $v, string $tip): mixed
{
    if (is_array($v)) return null;
    if ($tip === 'n') return siparis_pkl_sayi($v);
    if ($tip === 'd') return siparis_tarih_coz(is_scalar($v) ? (string)$v : '');
    $s = trim((string)preg_replace('/\s+/u', ' ', (string)$v));
    return $s === '' ? null : siparis_kirp($s, 255);
}

/**
 * Editör POST'unu (veri_json biçiminde) beyaz listeyle temizler. Blok kalem_id'si sevkiyatın
 * kalemlerinden biri olmalı; kalibre kalemden alınır. Boş girdi = "varsayılanı kullan".
 */
function siparis_pkl_girdi_temizle(array $post, array $kalemler): array
{
    $kmap = [];
    foreach ($kalemler as $k) $kmap[(int)$k['id']] = $k;
    $bloklar = [];
    foreach ((array)($post['bloklar'] ?? []) as $b) {
        if (!is_array($b)) continue;
        $kid = (int)($b['kalem_id'] ?? 0);
        if (!isset($kmap[$kid])) continue;
        $palet = siparis_pkl_sayi($b['palet'] ?? null);
        $bloklar[] = [
            'kalem_id' => $kid,
            'kalibre'  => (string)$kmap[$kid]['kalibre'],
            'palet'    => $palet !== null ? max(0, min(SIPARIS_PKL_MAX_PALET, (int)$palet)) : 0,
            'D'        => siparis_pkl_deger_temizle($b['D'] ?? null, 'n'),
            'F'        => siparis_pkl_deger_temizle($b['F'] ?? null, 's'),
            'H'        => siparis_pkl_deger_temizle($b['H'] ?? null, 's'),
            'T'        => siparis_pkl_deger_temizle($b['T'] ?? null, 'n'),
            'M'        => siparis_pkl_deger_temizle($b['M'] ?? null, 'n'),
            'N'        => siparis_pkl_deger_temizle($b['N'] ?? null, 'n'),
        ];
    }
    $vars = [];
    $vg = (array)($post['varsayilan'] ?? []);
    foreach (['J', 'K', 'L', 'M', 'N', 'O', 'P', 'R', 'S', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB'] as $col) {
        $v = siparis_pkl_deger_temizle($vg[$col] ?? null, SIPARIS_PKL_SUTUN_TIP[$col]);
        $vars[$col] = $col === 'K' ? ($v !== null ? (int)$v : null) : ($v ?? (in_array($col, ['M', 'N'], true) ? null : ''));
    }
    $satir = [];
    foreach ((array)($post['satir'] ?? []) as $i => $ov) {
        if (!is_array($ov) || !preg_match('/^\d{1,3}$/', (string)$i) || (int)$i >= SIPARIS_PKL_MAX_PALET) continue;
        $temiz = [];
        foreach (['M', 'N', 'O', 'P', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB'] as $col) {
            if (!array_key_exists($col, $ov)) continue;
            $v = siparis_pkl_deger_temizle($ov[$col], SIPARIS_PKL_SUTUN_TIP[$col]);
            if ($v !== null) $temiz[$col] = $v;
        }
        if ($temiz) $satir[(string)(int)$i] = $temiz;
    }
    $bno = trim((string)($post['belge_no_istenen'] ?? ''));
    $pb = siparis_pkl_sayi($post['palet_bas_istenen'] ?? null);
    $prof = (int)($post['profil_id'] ?? 0);
    return [
        'bloklar'           => $bloklar,
        'varsayilan'        => $vars,
        'satir'             => $satir,
        'belge_no_istenen'  => preg_match('/^[A-Za-z0-9\-]{1,20}$/', $bno) ? $bno : null,
        'palet_bas_istenen' => $pb !== null && (int)$pb >= 1 ? (int)$pb : null,
        'profil_id'         => $prof > 0 ? $prof : null,
    ];
}

/** Başlık hücreleri C4, C5, C8–C13 → [tip, değer] ya da null (boş). */
function siparis_pkl_baslik(array $sev, array $pkl, array $veri, string $belgeNo): array
{
    $sec = function (?string $a, ?string $b): ?string {
        foreach ([$a, $b] as $x) if ($x !== null && trim($x) !== '') return trim($x);
        return null;
    };
    $tar = fn(?string $x): ?string => $x !== null ? siparis_tarih_coz($x) : null;
    $deg = [
        'C4'  => trim($belgeNo) !== '' ? trim($belgeNo) : null,
        'C5'  => $tar($sec($pkl['belge_tarihi'] ?? null, $sev['yukleme_tarihi'] ?? null)),
        'C8'  => $sec($pkl['plakalar'] ?? null, $sev['plakalar'] ?? null),
        'C9'  => $sec($pkl['navlun_no'] ?? null, $sev['navlun_no'] ?? null),
        'C10' => $sec($pkl['alici_kodu'] ?? null, $sev['alici_kodu'] ?? null),
        'C11' => $sec($pkl['alici_adi'] ?? null, $sev['alici_adi'] ?? null),
        'C12' => $tar($sec($pkl['etd'] ?? null, $sev['yukleme_tarihi'] ?? null)),
        'C13' => $tar($pkl['eta'] ?? null),
    ];
    $out = [];
    foreach (SIPARIS_PKL_BASLIK_TIP as $c => $t) $out[$c] = $deg[$c] !== null ? [$t, $deg[$c]] : null;
    return $out;
}

/**
 * Palet satırları (palet başına bir satır, bloklar sırasıyla). Öncelik:
 * satir[i] > blok > varsayilan > türetilen. Türetilen: B=order_ref, C=paletBas+i,
 * E=ÜRÜN, G=kalibre, I='CARTON BOXES' (ambalaj "Carton…" ise), Q=lot(P), U=T, O=P−1.
 * Boş değer → null (hücre atlanır).
 * @return list<array<string, array{0:string,1:mixed}|null>>
 */
function siparis_pkl_satirlar(array $sev, array $kalemler, array $veri, int $paletBas): array
{
    $kmap = [];
    foreach ($kalemler as $k) $kmap[(int)$k['id']] = $k;
    $vars = (array)($veri['varsayilan'] ?? []);
    $satirOv = (array)($veri['satir'] ?? []);
    $bos = fn($v): bool => $v === null || (is_string($v) && trim($v) === '');
    $ilk = function (array $adaylar) use ($bos): mixed {
        foreach ($adaylar as $v) if (!$bos($v)) return is_string($v) ? trim($v) : $v;
        return null;
    };
    $hucre = function (string $col, mixed $v): ?array {
        if ($v === null || (is_string($v) && $v === '')) return null;
        $t = SIPARIS_PKL_SUTUN_TIP[$col];
        if ($t === 'n') { $n = siparis_pkl_sayi($v); return $n !== null ? ['n', $n] : ['s', (string)$v]; }
        if ($t === 'd') { $d = siparis_tarih_coz((string)$v); return $d !== null ? ['d', $d] : ['s', (string)$v]; }
        return ['s', (string)$v];
    };
    $yuk = siparis_tarih_coz((string)($sev['yukleme_tarihi'] ?? ''));
    $rows = [];
    $i = 0;
    foreach ((array)($veri['bloklar'] ?? []) as $b) {
        $k = $kmap[(int)($b['kalem_id'] ?? 0)] ?? [];
        $adet = max(0, (int)($b['palet'] ?? 0));
        for ($p = 0; $p < $adet && $i < SIPARIS_PKL_MAX_PALET + 1; $p++, $i++) {
            $ov = (array)($satirOv[(string)$i] ?? ($satirOv[$i] ?? []));
            $P = $ilk([$ov['P'] ?? null, $vars['P'] ?? null, $yuk]);
            $P = $P !== null ? (siparis_tarih_coz((string)$P) ?? (string)$P) : null;
            $T = $ilk([$ov['T'] ?? null, $b['T'] ?? null, $k['pf'] ?? null]);
            $amb = (string)($k['ambalaj'] ?? '');
            $r = [
                'B'  => $hucre('B', (string)($sev['order_ref'] ?? '')),
                'C'  => $hucre('C', $paletBas + $i),
                'D'  => $hucre('D', $ilk([$b['D'] ?? null, $k['artikel_no'] ?? null])),
                'E'  => $hucre('E', isset($k['urun']) && $k['urun'] !== null ? mb_strtoupper((string)$k['urun'], 'UTF-8') : null),
                'F'  => $hucre('F', $ilk([$b['F'] ?? null, $k['cesit'] ?? null])),
                'G'  => $hucre('G', (string)($b['kalibre'] ?? ($k['kalibre'] ?? ''))),
                'H'  => $hucre('H', $ilk([$b['H'] ?? null, isset($k['koli_icerik']) && $k['koli_icerik'] !== null ? mb_strtolower((string)$k['koli_icerik'], 'UTF-8') : null])),
                'I'  => $hucre('I', preg_match('/^\s*carton/i', $amb) ? 'CARTON BOXES' : null),
                'J'  => $hucre('J', $ilk([$vars['J'] ?? null])),
                'K'  => $hucre('K', $ilk([$vars['K'] ?? null])),
                'L'  => $hucre('L', $ilk([$vars['L'] ?? null])),
                'M'  => $hucre('M', $ilk([$ov['M'] ?? null, $b['M'] ?? null, $vars['M'] ?? null])),
                'N'  => $hucre('N', $ilk([$ov['N'] ?? null, $b['N'] ?? null, $vars['N'] ?? null])),
                'O'  => $hucre('O', $ilk([$ov['O'] ?? null, $vars['O'] ?? null, siparis_pkl_gun_ekle(is_string($P) ? $P : null, -1)])),
                'P'  => $hucre('P', $P),
                'Q'  => $hucre('Q', is_string($P) && siparis_tarih_coz($P) !== null ? siparis_pkl_lot($P) : null),
                'R'  => $hucre('R', $ilk([$vars['R'] ?? null])),
                'S'  => $hucre('S', $ilk([$vars['S'] ?? null])),
                'T'  => $hucre('T', $T),
                'U'  => $hucre('U', $ilk([$ov['U'] ?? null, $T])),
            ];
            foreach (['V', 'W', 'X', 'Y', 'Z', 'AA', 'AB'] as $col) $r[$col] = $hucre($col, $ilk([$ov[$col] ?? null, $vars[$col] ?? null]));
            $rows[] = $r;
        }
    }
    return $rows;
}

/**
 * Üretim öncesi denetim. Hata (üretimi durdurur): satır yok / sınır aşımı / C9 navlun yok.
 * Uyarı: plaka yok/biçim, GLN/GGN 13 hane + GS1, tarih ≤ 2023, lot biçimi, T < 1,
 * Σ blok paleti ≠ sevkiyat etkin paleti, blok çeşidi ≠ PO çeşidi, C10 7 hane değil.
 * @return list<array{seviye:'hata'|'uyari',kod:string,mesaj:string}>
 */
function siparis_pkl_on_kontrol(array $sev, array $kalemler, array $veri, array $baslik, array $satirlar): array
{
    $o = [];
    $ekle = function (string $sv, string $kod, string $m) use (&$o): void { $o[] = ['seviye' => $sv, 'kod' => $kod, 'mesaj' => $m]; };
    $n = count($satirlar);
    if ($n === 0) $ekle('hata', 'satir_yok', 'PKL satırı yok — etkin paleti olan kalem bulunmuyor.');
    if ($n > SIPARIS_PKL_MAX_PALET) $ekle('hata', 'satir_fazla', 'PKL en çok ' . SIPARIS_PKL_MAX_PALET . ' palet satırı alabilir.');
    if (($baslik['C9'] ?? null) === null) $ekle('hata', 'navlun_yok', 'Navlun (Freight Order) numarası yok — Loading List yüklenmeden PKL oluşturulamaz.');
    $plaka = $baslik['C8'][1] ?? null;
    if ($plaka === null) $ekle('uyari', 'plaka_yok', 'Araç plakası (C8) boş.');
    else {
        $pn = siparis_plaka_normalize((string)$plaka);
        if ($pn['uyari'] !== null || !str_contains((string)$pn['deger'], '-')) $ekle('uyari', 'plaka_bicim', 'Plaka "Çekici-Dorse" biçiminde değil (örn. 33AHU899-33AHU910).');
    }
    $alici = $baslik['C10'][1] ?? null;
    if ($alici !== null && !preg_match('/^\d{7}$/', (string)$alici)) $ekle('uyari', 'alici_kodu_bicim', 'Alıcı kodu (C10) 7 haneli değil.');
    $glnler = []; $ggnler = []; $eskiTarih = false; $lotKotu = false; $tKucuk = false;
    foreach ($baslik as $h) if ($h !== null && $h[0] === 'd' && (int)substr((string)$h[1], 0, 4) <= 2023) $eskiTarih = true;
    foreach ($satirlar as $r) {
        if (isset($r['S'])) $glnler[(string)$r['S'][1]] = true;
        if (isset($r['W'])) $ggnler[(string)$r['W'][1]] = true;
        foreach (['O', 'P', 'AA'] as $c) if (isset($r[$c]) && $r[$c][0] === 'd' && (int)substr((string)$r[$c][1], 0, 4) <= 2023) $eskiTarih = true;
        if (isset($r['Q']) && !preg_match('/^L \d{2} - \d{2}$/', (string)$r['Q'][1])) $lotKotu = true;
        if (isset($r['T']) && (!is_numeric($r['T'][1]) || (float)$r['T'][1] < 1)) $tKucuk = true;
    }
    foreach (array_keys($glnler) as $g) if (!siparis_pkl_gs1_ok((string)$g)) $ekle('uyari', 'gln_bicim', 'Paketevi GLN (S) 13 haneli değil ya da kontrol basamağı hatalı: ' . $g);
    foreach (array_keys($ggnler) as $g) if (!siparis_pkl_gs1_ok((string)$g)) $ekle('uyari', 'ggn_bicim', 'Üretici GGN (W) 13 haneli değil ya da kontrol basamağı hatalı.');
    if ($eskiTarih) $ekle('uyari', 'tarih_eski', '2023 ya da öncesine ait tarih var — şablon 2024 ve sonrasını kabul eder.');
    if ($lotKotu) $ekle('uyari', 'lot_bicim', 'Lot numarası "L 42 - 05" biçiminde değil.');
    if ($tKucuk) $ekle('uyari', 't_kucuk', 'Palet başına koli (T) 1\'den küçük.');
    $blokPalet = 0;
    $kmap = [];
    foreach ($kalemler as $k) $kmap[(int)$k['id']] = $k;
    foreach ((array)($veri['bloklar'] ?? []) as $b) {
        $blokPalet += max(0, (int)($b['palet'] ?? 0));
        $k = $kmap[(int)($b['kalem_id'] ?? 0)] ?? null;
        if ($k === null) continue;
        $poCesit = $k['_kaynak']['cesit']['po']['v'] ?? (siparis_json_coz($k['kaynaklar'] ?? null)['cesit']['po']['v'] ?? ($k['cesit'] ?? null));
        $f = $b['F'] ?? null;
        if ($f !== null && $f !== '' && $poCesit !== null && mb_strtoupper(trim((string)$f), 'UTF-8') !== mb_strtoupper(trim((string)$poCesit), 'UTF-8')) {
            $ekle('uyari', 'cesit_farkli', 'Kalibre ' . $b['kalibre'] . ': çeşit "' . $f . '" PO\'daki "' . $poCesit . '" ile aynı değil.');
        }
    }
    if (($sev['toplam_palet'] ?? null) !== null && $blokPalet !== (int)$sev['toplam_palet']) {
        $ekle('uyari', 'palet_toplam_farkli', "Bloklardaki palet toplamı ({$blokPalet}) sevkiyatın etkin paletinden ({$sev['toplam_palet']}) farklı.");
    }
    return $o;
}

/**
 * Zorunlu boş hücre sayımı. Başlık: C4, C5, C8, C9, C10, C11, C12; satır: B–I, K, M–U, V, W, Y, Z, AA, AB
 * (J, L, X, C13 isteğe bağlı). @return array{alanlar:array<string,int>, toplam:int}
 */
function siparis_pkl_eksikler(array $baslik, array $satirlar): array
{
    $alan = [];
    foreach (['C4', 'C5', 'C8', 'C9', 'C10', 'C11', 'C12'] as $c) if (($baslik[$c] ?? null) === null) $alan[$c] = 1;
    $zorunlu = ['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'K', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'Y', 'Z', 'AA', 'AB'];
    foreach ($satirlar as $r) foreach ($zorunlu as $c) if (($r[$c] ?? null) === null) $alan[$c] = ($alan[$c] ?? 0) + 1;
    return ['alanlar' => $alan, 'toplam' => array_sum($alan)];
}

// ── DURUM / TASLAK / ÜRETİM ─────────────────────────────────────────────

/**
 * PKL durumu: satır (yoksa null), veri (kayıtlı veri_json ya da varsayılan), öneri
 * (belge no / palet başlangıcı / sezon). Satır yoksa HİÇBİR ŞEY yazılmaz.
 * @return array{pkl:?array, veri:array, oneri:array{belge_no:string,palet_bas:int,sezon:string}}
 */
function siparis_pkl_durum(PDO $pdo, int $sid): array
{
    $sev = siparis_sevkiyat($pdo, $sid);
    $st = $pdo->prepare('SELECT * FROM siparis_pkl WHERE sevkiyat_id = ?');
    $st->execute([$sid]);
    $pkl = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $kalemler = $sev ? siparis_kalemler($pdo, $sid) : [];
    $veri = $pkl ? siparis_json_coz($pkl['veri_json']) : [];
    if (!$veri || !isset($veri['bloklar'])) {
        $profil = null;
        if ($pkl && $pkl['profil_id'] !== null) {
            $p = $pdo->prepare('SELECT * FROM siparis_profiller WHERE id = ? AND deleted_at IS NULL');
            $p->execute([(int)$pkl['profil_id']]);
            $profil = $p->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $veri = siparis_pkl_varsayilan($sev ?? [], $kalemler, $profil);
    }
    $sezon = ($pkl && $pkl['sezon'] !== '') ? (string)$pkl['sezon'] : siparis_sezon($sev['yukleme_tarihi'] ?? null);
    return [
        'pkl'   => $pkl,
        'veri'  => $veri,
        'oneri' => [
            'belge_no'  => $pkl && $pkl['belge_no'] !== null ? (string)$pkl['belge_no'] : siparis_belge_no_bicim(siparis_sayac_oku($pdo, 'belge_no_' . $sezon) + 1),
            'palet_bas' => $pkl && $pkl['palet_bas'] !== null ? (int)$pkl['palet_bas'] : siparis_sayac_oku($pdo, 'palet_' . $sezon) + 1,
            'sezon'     => $sezon,
        ],
    ];
}

// ── PALET NUMARASI DENETİMİ (v372) ──────────────────────────────────────

/**
 * Sezonun palet numaralandırma bağlamı (salt okunur): `son` = karşılaştırma ölçütü olan "önceki son palet no",
 * `diger` = AYNI sezondaki BAŞKA sevkiyatların PKL aralıkları, `kendi_bas` = bu sevkiyatın ayrılmış başlangıcı (yoksa null).
 *  - Bu sevkiyatın aralığı HENÜZ ayrılmadıysa (taslak) ölçüt sezon sayacının son değeridir (öneri = son + 1).
 *  - Ayrılmışsa (üretildi) ölçüt, kendi başlangıcından küçük olan en büyük "diğer" bitiştir (sayaç kendi aralığını da içerir).
 * Aralık çakışması sunucuda siparis_aralik_cakisma_denetle() ile de aranır (aynı küme: siparis_pkl satırları, kendi kaydı hariç).
 * @return array{son:int, diger:list<array{belge_no:string,order_ref:string,bas:int,bit:int}>, kendi_bas:?int}
 */
function siparis_pkl_palet_baglam(PDO $pdo, string $sezon, ?int $haricSevkiyatId): array
{
    $harc = $haricSevkiyatId ?? 0;
    $st = $pdo->prepare('SELECT p.belge_no, p.palet_bas, p.palet_bit, s.order_ref FROM siparis_pkl p
        LEFT JOIN siparis_sevkiyatlar s ON s.id = p.sevkiyat_id
        WHERE p.sezon = ? AND p.sevkiyat_id <> ? AND p.palet_bas IS NOT NULL AND p.palet_bit IS NOT NULL ORDER BY p.palet_bas, p.id');
    $st->execute([$sezon, $harc]);
    $diger = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
        $diger[] = ['belge_no' => (string)($x['belge_no'] ?? ''), 'order_ref' => (string)($x['order_ref'] ?? ''), 'bas' => (int)$x['palet_bas'], 'bit' => (int)$x['palet_bit']];
    }
    $kendi = null;
    if ($harc > 0) {
        $k = $pdo->prepare('SELECT palet_bas FROM siparis_pkl WHERE sevkiyat_id = ?');
        $k->execute([$harc]);
        $v = $k->fetchColumn();
        if ($v !== false && $v !== null) $kendi = (int)$v;
    }
    if ($kendi !== null) {
        $son = 0;
        foreach ($diger as $d) if ($d['bit'] < $kendi) $son = max($son, $d['bit']);
    } else {
        $son = siparis_sayac_oku($pdo, 'palet_' . $sezon);
    }
    return ['son' => $son, 'diger' => $diger, 'kendi_bas' => $kendi];
}

/**
 * Saf kural (DB yok; assets/siparis.js `paletUyarilari` aynasıdır — ikisini birlikte değiştir):
 * [bas, bas+adet-1] aralığı için ENGELLEMEYEN uyarılar.
 *  - palet_cakisma: başka bir PKL'in aralığıyla kesişiyor (PKL oluşturulurken sunucu bunu REDDEDER).
 *  - palet_geride:  başlangıç ≤ önceki son ama kesişme yok (boşta kalmış aralık kullanılıyor).
 *  - palet_bosluk:  başlangıç > önceki son + 1 → araya kalan numaralar boşta kalır.
 * @param list<array{belge_no:string,order_ref:string,bas:int,bit:int}> $diger
 * @return list<array{seviye:string,kod:string,mesaj:string}>
 */
function siparis_pkl_palet_uyarilari_hesapla(int $son, array $diger, int $bas, int $adet): array
{
    $o = [];
    if ($adet < 1 || $bas < 1) return $o;
    $bit = $bas + $adet - 1;
    $cakisan = [];
    foreach ($diger as $d) if ((int)$d['bas'] <= $bit && (int)$d['bit'] >= $bas) $cakisan[] = $d;
    if ($cakisan) {
        $p = [];
        foreach (array_slice($cakisan, 0, 3) as $d) {
            $ad = trim((string)$d['belge_no']) !== '' ? trim((string)$d['belge_no']) : 'başka bir PKL';
            $p[] = $ad . (trim((string)$d['order_ref']) !== '' ? ' (' . trim((string)$d['order_ref']) . ')' : '') . ' ' . (int)$d['bas'] . '–' . (int)$d['bit'];
        }
        $o[] = ['seviye' => 'uyari', 'kod' => 'palet_cakisma', 'mesaj' => "Palet aralığı {$bas}–{$bit}, " . implode(', ', $p) . (count($cakisan) > 3 ? ' ve ' . (count($cakisan) - 3) . ' PKL daha' : '')
            . ' ile çakışıyor — kaydedebilirsiniz, ancak PKL oluşturulurken sistem bunu reddeder; başlangıç numarasını değiştirin.'];
    } elseif ($bas <= $son) {
        $o[] = ['seviye' => 'uyari', 'kod' => 'palet_geride', 'mesaj' => "Başlangıç ({$bas}), önceki siparişin son palet no'sundan ({$son}) küçük; daha önce boşta kalmış bir aralık kullanılıyor."];
    }
    if ($bas > $son + 1) {
        $bos = $bas - $son - 1;
        $aralik = $bos === 1 ? ($son + 1) . ' numarası' : ($son + 1) . '–' . ($bas - 1) . ' arası ' . $bos . ' numara';
        $o[] = ['seviye' => 'uyari', 'kod' => 'palet_bosluk', 'mesaj' => $son < 1
            ? "Bu sezonda henüz palet numarası ayrılmadı; {$aralik} boşta kalıyor."
            : "Önceki siparişin son palet no'su {$son}; {$aralik} boşta kalıyor."];
    }
    return $o;
}

/**
 * Bir PKL taslağı için palet numarası uyarıları (bağlamı DB'den okur). $haricSevkiyatId = bu sevkiyat (kendi aralığı hariç).
 * @return list<array{seviye:string,kod:string,mesaj:string}>
 */
function siparis_pkl_palet_uyarilari(PDO $pdo, string $sezon, int $bas, int $adet, ?int $haricSevkiyatId): array
{
    $b = siparis_pkl_palet_baglam($pdo, $sezon, $haricSevkiyatId);
    return siparis_pkl_palet_uyarilari_hesapla($b['son'], $b['diger'], $bas, $adet);
}

/** Başlık girdisini temizler: belge_tarihi/etd/eta (tarih), plakalar (plaka), navlun_no/alici_kodu/alici_adi (metin), profil_id. */
function siparis_pkl_baslik_girdi_temizle(array $g): array
{
    $t = fn(string $k): ?string => isset($g[$k]) && is_scalar($g[$k]) && trim((string)$g[$k]) !== '' ? siparis_tarih_coz((string)$g[$k]) : null;
    $m = fn(string $k, int $n): ?string => isset($g[$k]) && is_scalar($g[$k]) && trim((string)$g[$k]) !== '' ? siparis_kirp(trim((string)preg_replace('/\s+/u', ' ', (string)$g[$k])), $n) : null;
    $plaka = isset($g['plakalar']) && is_scalar($g['plakalar']) ? siparis_plaka_normalize((string)$g['plakalar'])['deger'] : null;
    $prof = (int)($g['profil_id'] ?? 0);
    return [
        'belge_tarihi' => $t('belge_tarihi'), 'etd' => $t('etd'), 'eta' => $t('eta'),
        'plakalar'     => $plaka,
        'navlun_no'    => $m('navlun_no', 30), 'alici_kodu' => $m('alici_kodu', 20), 'alici_adi' => $m('alici_adi', 100),
        'profil_id'    => $prof > 0 ? $prof : null,
    ];
}

/** Sevkiyatın PKL satırını kilitler (MySQL), yoksa varsayılanlarla açar. Transaction İÇİNDE. */
function siparis_pkl_satir_kilitle(PDO $pdo, array $sev, int $uid): array
{
    $sid = (int)$sev['id'];
    $st = $pdo->prepare('SELECT * FROM siparis_pkl WHERE sevkiyat_id = ?' . siparis_kilit_sql($pdo));
    $st->execute([$sid]);
    $pkl = $st->fetch(PDO::FETCH_ASSOC);
    if ($pkl) return $pkl;
    $veri = siparis_pkl_varsayilan($sev, siparis_kalemler($pdo, $sid), null);
    try {
        $pdo->prepare('INSERT INTO siparis_pkl (sevkiyat_id, durum, sezon, veri_json, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$sid, 'taslak', siparis_sezon($sev['yukleme_tarihi'] ?? null), siparis_json($veri), $uid, siparis_simdi()]);
    } catch (PDOException $e) {
        if (!siparis_tekrar_mi($e)) throw $e;
    }
    $st->execute([$sid]);
    $pkl = $st->fetch(PDO::FETCH_ASSOC);
    if (!$pkl) throw new RuntimeException('PKL kaydı açılamadı.');
    return $pkl;
}

/**
 * Taslak kaydı (upsert): durum 'taslak' kalır (üretilmiş/yüklenmiş PKL'in durumu korunur).
 * Olay pkl_taslak + audit siparis_pkl_taslak. @return array{ok:bool,hata?:string,pkl_id?:int}
 */
function siparis_pkl_kaydet_taslak(PDO $pdo, int $sid, array $veri, array $baslikGirdi, int $uid): array
{
    try {
        return siparis_tx($pdo, function () use ($pdo, $sid, $veri, $baslikGirdi, $uid): array {
            $sev = siparis_sevkiyat($pdo, $sid);
            if (!$sev) return ['ok' => false, 'hata' => 'Sevkiyat bulunamadı.'];
            $pkl = siparis_pkl_satir_kilitle($pdo, $sev, $uid);
            $temiz = siparis_pkl_girdi_temizle($veri, siparis_kalemler($pdo, $sid));
            $b = siparis_pkl_baslik_girdi_temizle($baslikGirdi);
            if ($b['profil_id'] === null) $b['profil_id'] = $temiz['profil_id'];
            $pdo->prepare('UPDATE siparis_pkl SET veri_json = ?, profil_id = ?, belge_tarihi = ?, etd = ?, eta = ?, plakalar = ?, navlun_no = ?,
                    alici_kodu = ?, alici_adi = ?, updated_by = ?, updated_at = ? WHERE id = ?')
                ->execute([siparis_json($temiz), $b['profil_id'], $b['belge_tarihi'], $b['etd'], $b['eta'], $b['plakalar'], $b['navlun_no'],
                           $b['alici_kodu'], $b['alici_adi'], $uid, siparis_simdi(), (int)$pkl['id']]);
            siparis_olay($pdo, $sid, 'pkl_taslak', null, null, 'PKL taslağı kaydedildi', null, $uid);
            siparis_audit('siparis_pkl_taslak', $sid, null, ['sevkiyat_id' => $sid, 'pkl_id' => (int)$pkl['id']], $uid);
            return ['ok' => true, 'pkl_id' => (int)$pkl['id']];
        });
    } catch (PDOException $e) {
        error_log('[siparis_pkl_kaydet_taslak] ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'PKL taslağı kaydedilemedi.'];
    }
}

/**
 * PKL üretimi (tek transaction): satır kilidi → satırlar → sezon sayacından palet aralığı
 * ve belge numarası (yeniden üretimde AYNI aralık) → ön kontrol (hata = dur) → eksik hücre
 * kapısı ($eksikleIzin) → şablon doldurma → belge satırı + bağ → PKL satırı → var_pkl + durum.
 * Başarısızlıkta rollback (ayrılan numaralar geri döner) ve yeni dosya silinir.
 * @return array{ok:bool,hata?:string,belge_id?:int,dosya_adi?:string,eksik:int,uyarilar:list}
 */
function siparis_pkl_uret(PDO $pdo, int $sid, int $uid, bool $eksikleIzin): array
{
    $dosya = null;
    $sonuc = ['ok' => false, 'eksik' => 0, 'uyarilar' => []];
    $sahip = !$pdo->inTransaction();
    if ($sahip) $pdo->beginTransaction();
    try {
        $sev = siparis_sevkiyat($pdo, $sid);
        if (!$sev) throw new RuntimeException('Sevkiyat bulunamadı.');
        $pkl = siparis_pkl_satir_kilitle($pdo, $sev, $uid);
        $kalemler = siparis_kalemler($pdo, $sid);
        $veri = siparis_json_coz($pkl['veri_json']);
        if (!isset($veri['bloklar'])) $veri = siparis_pkl_varsayilan($sev, $kalemler, null);
        $n = count(siparis_pkl_satirlar($sev, $kalemler, $veri, 0));
        if ($n === 0) throw new RuntimeException('PKL satırı yok — etkin paleti olan kalem bulunmuyor.');
        if ($n > SIPARIS_PKL_MAX_PALET) throw new RuntimeException('PKL en çok ' . SIPARIS_PKL_MAX_PALET . ' palet satırı alabilir.');
        $sezon = (string)$pkl['sezon'] !== '' ? (string)$pkl['sezon'] : siparis_sezon($sev['yukleme_tarihi'] ?? null);
        $uyarilar = [];

        // — palet aralığı —
        $ad = 'palet_' . $sezon;
        $istenen = isset($veri['palet_bas_istenen']) && $veri['palet_bas_istenen'] !== null ? (int)$veri['palet_bas_istenen'] : null;
        if ($pkl['palet_bas'] === null) {
            $r = siparis_sayac_rezerve($pdo, $ad, $n, $istenen, $sezon, (int)$pkl['id']);
            $bas = $r['bas']; $bit = $r['bit'];
        } else {
            $bas = (int)$pkl['palet_bas']; $eskiBit = (int)$pkl['palet_bit']; $bit = $eskiBit;
            if ($eskiBit - $bas + 1 !== $n) {
                $son = siparis_sayac_kilitle($pdo, $ad);
                $yeniBit = $bas + $n - 1;
                if ($eskiBit === $son) {                          // en son aralık: aralık + sayaç birlikte kayar
                    siparis_aralik_cakisma_denetle($pdo, $ad, $bas, $yeniBit, $sezon, (int)$pkl['id']);
                    $pdo->prepare('UPDATE siparis_sayaclar SET son_deger = ?, updated_at = ? WHERE ad = ?')->execute([$yeniBit, siparis_simdi(), $ad]);
                } elseif ($yeniBit > $eskiBit) {
                    throw new RuntimeException("Palet aralığı sonraki PKL ile çakışır — başlangıcı Ayarlar'dan düzeltin.");
                } else {
                    $uyarilar[] = ['seviye' => 'uyari', 'kod' => 'aralik_bosluk', 'mesaj' => 'Palet sayısı azaldı; ' . ($yeniBit + 1) . '–' . $eskiBit . ' aralıkta boşluk kaldı.'];
                }
                $bit = $yeniBit;
            }
        }
        // — belge numarası —
        $belgeNo = $pkl['belge_no'] !== null ? (string)$pkl['belge_no'] : null;
        $belgeSayi = $pkl['belge_no_sayi'] !== null ? (int)$pkl['belge_no_sayi'] : null;
        if ($belgeNo === null) {
            $bni = isset($veri['belge_no_istenen']) && is_string($veri['belge_no_istenen']) && $veri['belge_no_istenen'] !== '' ? $veri['belge_no_istenen'] : null;
            if ($bni !== null) {
                $belgeNo = $bni;
                $belgeSayi = preg_match('/(\d+)\s*$/', $bni, $mm) ? (int)$mm[1] : null;
                if ($belgeSayi !== null) {
                    $bson = siparis_sayac_kilitle($pdo, 'belge_no_' . $sezon);
                    siparis_aralik_cakisma_denetle($pdo, 'belge_no_' . $sezon, $belgeSayi, $belgeSayi, $sezon, (int)$pkl['id']);
                    if ($belgeSayi > $bson) $pdo->prepare('UPDATE siparis_sayaclar SET son_deger = ?, updated_at = ? WHERE ad = ?')->execute([$belgeSayi, siparis_simdi(), 'belge_no_' . $sezon]);
                }
            } else {
                $r = siparis_sayac_rezerve($pdo, 'belge_no_' . $sezon, 1, null, $sezon, (int)$pkl['id']);
                $belgeSayi = $r['bas'];
                $belgeNo = siparis_belge_no_bicim($belgeSayi);
            }
        }
        $pkl['palet_bas'] = $bas; $pkl['palet_bit'] = $bit; $pkl['belge_no'] = $belgeNo;

        // — hücreler + denetim —
        $baslik = siparis_pkl_baslik($sev, $pkl, $veri, $belgeNo);
        $satirlar = siparis_pkl_satirlar($sev, $kalemler, $veri, $bas);
        $kontrol = array_merge(siparis_pkl_on_kontrol($sev, $kalemler, $veri, $baslik, $satirlar), $uyarilar);
        $sonuc['uyarilar'] = $kontrol;
        $hatalar = array_values(array_filter($kontrol, fn($x) => $x['seviye'] === 'hata'));
        if ($hatalar) throw new RuntimeException(implode(' ', array_column($hatalar, 'mesaj')));
        $eksik = siparis_pkl_eksikler($baslik, $satirlar);
        $sonuc['eksik'] = $eksik['toplam'];
        if ($eksik['toplam'] > 0 && !$eksikleIzin) throw new RuntimeException('PKL\'de ' . $eksik['toplam'] . ' zorunlu hücre boş — tamamlayın ya da "eksik alanlarla oluştur"u onaylayın.');

        // — dosya —
        if (!siparis_depo_hazirla()) throw new RuntimeException('Belge klasörü hazırlanamadı.');
        $diskAdi = bin2hex(random_bytes(16)) . '.xlsx';
        $dosya = rtrim(SIPARIS_DEPO_DIR, '/') . '/' . $diskAdi;
        siparis_pkl_doldur(SIPARIS_PKL_SABLON, $baslik, $satirlar, $dosya);
        @chmod($dosya, 0640);
        $sha = (string)hash_file('sha256', $dosya);
        $dosyaAdi = siparis_pkl_dosya_adi((string)$sev['order_ref'], $belgeNo, (string)($baslik['C5'][1] ?? ''));
        $bs = $pdo->prepare('SELECT id FROM siparis_belgeler WHERE sha256 = ?');
        $bs->execute([$sha]);
        $belgeId = (int)($bs->fetchColumn() ?: 0);
        $simdi = siparis_simdi();
        if ($belgeId > 0) {
            @unlink($dosya); $dosya = null;
            $pdo->prepare('UPDATE siparis_belgeler SET deleted_at = NULL, deleted_by = NULL WHERE id = ?')->execute([$belgeId]);
        } else {
            $ozet = 'PKL ' . $belgeNo . ' · ' . $n . ' palet (' . $bas . '–' . $bit . ')';
            $pdo->prepare('INSERT INTO siparis_belgeler (tur, kaynak, durum, sha256, disk_adi, orijinal_ad, mime, boyut, belge_tarihi, tedarikci_no, ozet, parse_json, uyari_sayisi, depo, yukleyen, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(['pkl', 'uretim', 'islendi', $sha, $diskAdi, $dosyaAdi, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                           (int)filesize($dosya), $simdi, $sev['tedarikci_no'], $ozet,
                           siparis_json(['belge_no' => $belgeNo, 'palet_bas' => $bas, 'palet_bit' => $bit, 'satir' => $n, 'order_refs' => [$sev['order_ref']]]),
                           count(array_filter($kontrol, fn($x) => $x['seviye'] === 'uyari')), (string)$sev['depo'], $uid, $simdi]);
            $belgeId = (int)$pdo->lastInsertId();
        }
        // — bağ: önceki PKL bağları eski sürüm —
        siparis_bag_yaz($pdo, $belgeId, $sid, $uid);
        $eb = $pdo->prepare("SELECT bb.id, bb.belge_id FROM siparis_belge_bag bb JOIN siparis_belgeler b ON b.id = bb.belge_id
            WHERE bb.sevkiyat_id = ? AND bb.gecerli = 1 AND b.tur = 'pkl' AND bb.belge_id <> ?");
        $eb->execute([$sid, $belgeId]);
        foreach ($eb->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $pdo->prepare('UPDATE siparis_belge_bag SET gecerli = 0 WHERE id = ?')->execute([(int)$x['id']]);
            siparis_olay($pdo, $sid, 'belge_eski_surum', null, null, 'Yeni PKL üretildi; önceki PKL eski sürüm', (int)$x['belge_id'], $uid);
        }
        $pdo->prepare('UPDATE siparis_belge_bag SET gecerli = 1 WHERE belge_id = ? AND sevkiyat_id = ?')->execute([$belgeId, $sid]);
        // — PKL satırı —
        $pdo->prepare("UPDATE siparis_pkl SET durum = 'uretildi', sezon = ?, belge_no = ?, belge_no_sayi = ?, palet_bas = ?, palet_bit = ?,
                dosya_belge_id = ?, dosya_adi = ?, eksik_hucre = ?, uretim_sayisi = uretim_sayisi + 1, uretildi_at = ?, uretildi_by = ?,
                updated_by = ?, updated_at = ? WHERE id = ?")
            ->execute([$sezon, $belgeNo, $belgeSayi, $bas, $bit, $belgeId, $dosyaAdi, $eksik['toplam'], $simdi, $uid, $uid, $simdi, (int)$pkl['id']]);
        siparis_sevkiyat_yenile($pdo, $sid);
        siparis_durum_ilerlet($pdo, $sid, 'pkl_hazir', $uid, 'PKL üretildi', $belgeId);
        siparis_olay($pdo, $sid, 'pkl_uretildi', null, $belgeNo, $dosyaAdi . ($eksik['toplam'] > 0 ? ' (kısmi: ' . $eksik['toplam'] . ' boş hücre)' : ''), $belgeId, $uid);
        siparis_audit('siparis_pkl_uret', $sid, null, ['sevkiyat_id' => $sid, 'belge_no' => $belgeNo, 'palet_bas' => $bas, 'palet_bit' => $bit, 'eksik' => $eksik['toplam']], $uid);
        if ($sahip) $pdo->commit();
        return ['ok' => true, 'belge_id' => $belgeId, 'dosya_adi' => $dosyaAdi, 'eksik' => $eksik['toplam'], 'uyarilar' => $kontrol];
    } catch (Throwable $e) {
        if ($sahip && $pdo->inTransaction()) $pdo->rollBack();
        if ($dosya !== null && is_file($dosya)) @unlink($dosya);
        if ($e instanceof PDOException) {
            error_log('[siparis_pkl_uret] ' . $e->getMessage());
            $sonuc['hata'] = siparis_tekrar_mi($e) ? 'Bu belge numarası başka bir PKL\'de kullanılıyor.' : 'PKL oluşturulamadı (veritabanı hatası).';
        } elseif ($e instanceof RuntimeException) {
            $sonuc['hata'] = $e->getMessage();
        } else {
            error_log('[siparis_pkl_uret] ' . $e->getMessage());
            $sonuc['hata'] = 'PKL oluşturulamadı.';
        }
        if (!$sahip) throw $e;
        return $sonuc;
    }
}

// ── OKUMA (yüklenen PKL + önizleme) ─────────────────────────────────────

/**
 * sheet1.xml'deki DOLU hücreleri çözer: koordinat → değer (metin; paylaşılan metin çözülmüş,
 * sayılar ham). Boş (kendinden kapanan) hücreler listelenmez.
 * @return array<string,array{t:string,v:string}>
 */
function siparis_pkl_hucreler(string $sheet, array $sst): array
{
    $out = [];
    if (!preg_match_all('#<c\b([^>]*?)(?<!/)>(.*?)</c>#s', $sheet, $m, PREG_SET_ORDER)) return $out;
    foreach ($m as $c) {
        if (!preg_match('/\br="([A-Z]{1,3}\d+)"/', $c[1], $r)) continue;
        $t = preg_match('/\bt="(\w+)"/', $c[1], $tt) ? $tt[1] : 'n';
        $v = null;
        if ($t === 'inlineStr') {
            preg_match_all('#<t(?:\s[^>]*)?>(.*?)</t>#s', $c[2], $ts);
            $v = html_entity_decode(implode('', $ts[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
            $t = 'str';
        } elseif (preg_match('#<v>(.*?)</v>#s', $c[2], $vv)) {
            $v = html_entity_decode($vv[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            if ($t === 's') { $v = (string)($sst[(int)$v] ?? ''); $t = 'str'; }
        }
        if ($v === null || $v === '') continue;
        $out[$r[1]] = ['t' => $t === 'str' ? 's' : $t, 'v' => (string)$v];
    }
    return $out;
}

/** Zip'ten sayfa + sharedStrings + çalışma kitabını okur (zip-bomba sınırları ile). */
function siparis_pkl_xlsx_ac(string $yol): array
{
    if (!class_exists('ZipArchive')) return ['ok' => false, 'hata' => 'Sunucuda ZipArchive yok.'];
    $z = new ZipArchive();
    if (!is_file($yol) || $z->open($yol) !== true) return ['ok' => false, 'hata' => 'Excel dosyası açılamadı.'];
    try {
        if ($z->numFiles >= 200) return ['ok' => false, 'hata' => 'Excel dosyası beklenmedik sayıda parça içeriyor.'];
        $s1 = $z->statName('xl/worksheets/sheet1.xml');
        if ($s1 === false) return ['ok' => false, 'hata' => 'Excel dosyasında çalışma sayfası yok.'];
        if ((int)$s1['size'] > 40 * 1024 * 1024) return ['ok' => false, 'hata' => 'Excel sayfası çok büyük.'];
        $ss = $z->statName('xl/sharedStrings.xml');
        if ($ss !== false && (int)$ss['size'] > 20 * 1024 * 1024) return ['ok' => false, 'hata' => 'Excel metin tablosu çok büyük.'];
        $sheet = (string)$z->getFromName('xl/worksheets/sheet1.xml');
        $sst = $ss !== false ? siparis_pkl_sst_oku((string)$z->getFromName('xl/sharedStrings.xml')) : [];
        $wb = (string)$z->getFromName('xl/workbook.xml');
    } finally {
        $z->close();
    }
    return ['ok' => true, 'sheet' => $sheet, 'sst' => $sst, 'wb' => $wb];
}

/** Hücre değerini görüntüye çevirir; $tarih ise seri gün → gg.aa.yyyy. */
function siparis_pkl_goster(?array $h, bool $tarih): string
{
    if ($h === null) return '';
    if ($tarih && $h['t'] === 'n' && is_numeric($h['v']) && (float)$h['v'] > 20000 && (float)$h['v'] < 80000) {
        return date('d.m.Y', (int)strtotime(siparis_pkl_serial_tarih((float)$h['v'])));
    }
    return $h['v'];
}

/**
 * Yüklenen PKL Excel'inin hafif okuması (sheet1 + sharedStrings). pkl = "Packing List" adlı
 * sayfa ya da "PKL Version" metni var. baslik: C2..C13 (hücre başvurusu → görüntü metni).
 * @return array{ok:bool,hata?:string,pkl:bool,belge_no:?string,order_refs:list<string>,palet_min:?int,palet_max:?int,satir:int,baslik:array<string,string>}
 */
function siparis_pkl_oku(string $xlsxYolu): array
{
    $bos = ['pkl' => false, 'belge_no' => null, 'order_refs' => [], 'palet_min' => null, 'palet_max' => null, 'satir' => 0, 'baslik' => []];
    $a = siparis_pkl_xlsx_ac($xlsxYolu);
    if (!$a['ok']) return ['ok' => false, 'hata' => $a['hata']] + $bos;
    $pkl = (bool)preg_match('/<sheet\b[^>]*\bname="Packing List"/', $a['wb']);
    if (!$pkl) foreach ($a['sst'] as $s) if (str_contains($s, 'PKL Version')) { $pkl = true; break; }
    $h = siparis_pkl_hucreler($a['sheet'], $a['sst']);
    $baslik = [];
    for ($r = 2; $r <= 13; $r++) {
        $v = siparis_pkl_goster($h['C' . $r] ?? null, in_array($r, [5, 12, 13], true));
        if ($v !== '') $baslik['C' . $r] = $v;
    }
    $refs = []; $pmin = null; $pmax = null; $n = 0;
    for ($r = SIPARIS_PKL_ILK_SATIR; $r < 10000; $r++) {
        $b = trim((string)($h['B' . $r]['v'] ?? ''));
        if ($b === '') break;
        $n++;
        if (!in_array($b, $refs, true)) $refs[] = $b;
        $c = $h['C' . $r]['v'] ?? null;
        if ($c !== null && preg_match('/^\d+(\.0+)?$/', $c)) { $pv = (int)$c; $pmin = $pmin === null ? $pv : min($pmin, $pv); $pmax = $pmax === null ? $pv : max($pmax, $pv); }
    }
    $bno = isset($baslik['C4']) ? siparis_kirp(trim($baslik['C4']), 20) : null;
    return ['ok' => true, 'pkl' => $pkl, 'belge_no' => $bno, 'order_refs' => $refs, 'palet_min' => $pmin, 'palet_max' => $pmax, 'satir' => $n, 'baslik' => $baslik];
}

/**
 * Önizleme: baslik = B etiketi → C değeri (satır 2–13), sutunlar = 16. satır etiketleri (B..AB;
 * etiket yoksa sütun harfi), satirlar = 17. satırdan B boş olana kadar (en çok $maxSatir) —
 * her satır sutunlar'daki etiketlerle anahtarlı. Tarih hücreleri (C5, C12, C13, O, P, AA) gg.aa.yyyy.
 * @return array{baslik:array<string,string>, sutunlar:list<string>, satirlar:list<array<string,string>>, satir_sayisi:int}
 */
function siparis_xlsx_onizleme(string $xlsxYolu, int $maxSatir = 120): array
{
    $out = ['baslik' => [], 'sutunlar' => [], 'satirlar' => [], 'satir_sayisi' => 0];
    $a = siparis_pkl_xlsx_ac($xlsxYolu);
    if (!$a['ok']) return $out;
    $h = siparis_pkl_hucreler($a['sheet'], $a['sst']);
    for ($r = 2; $r <= 13; $r++) {
        $et = trim((string)($h['B' . $r]['v'] ?? ''));
        $v = siparis_pkl_goster($h['C' . $r] ?? null, in_array($r, [5, 12, 13], true));
        if ($et === '' && $v === '') continue;
        $k = $et !== '' ? $et : 'C' . $r;
        if (isset($out['baslik'][$k])) $k .= ' (C' . $r . ')';
        $out['baslik'][$k] = $v;
    }
    $harfler = array_keys(SIPARIS_PKL_SUTUN_TIP);
    $etiket = [];
    foreach ($harfler as $col) {
        $e = trim((string)($h[$col . '16']['v'] ?? ''));
        $e = $e !== '' ? $e : $col;
        while (in_array($e, $etiket, true)) $e .= ' (' . $col . ')';
        $etiket[$col] = $e;
    }
    $out['sutunlar'] = array_values($etiket);
    $tarihSutun = ['O', 'P', 'AA'];
    for ($r = SIPARIS_PKL_ILK_SATIR; $r < 10000; $r++) {
        if (trim((string)($h['B' . $r]['v'] ?? '')) === '') break;
        $out['satir_sayisi']++;
        if (count($out['satirlar']) >= max(0, $maxSatir)) continue;
        $row = [];
        foreach ($harfler as $col) $row[$etiket[$col]] = siparis_pkl_goster($h[$col . $r] ?? null, in_array($col, $tarihSutun, true));
        $out['satirlar'][] = $row;
    }
    return $out;
}

// ── PKL MAİL TASLAĞI (v383) ─────────────────────────────────────────────
// Uygulama mail GÖNDERMEZ: yalnız kullanıcının mail programına taslak verir. İki yol: (1) mailto: bağlantısı — Kime/Bilgi/
// konu/gövde dolar ama mailto standardı EK TAŞIYAMAZ; (2) .eml taslağı (X-Unsent: 1) — PKL ekiyle birlikte; Outlook
// (klasik) bunu gönderilmeye hazır taslak olarak açar. İkisi de AYNI siparis_pkl_mail_taslak() çıktısından kurulur.

/** PO'daki "Packing List per E-Mail to:" adresleri (geçerli e-postalar, PO sırasıyla). @return list<string> */
function siparis_po_pkl_epostalar(PDO $pdo, array $sev): array
{
    if (empty($sev['po_belge_id'])) return [];
    $p = $pdo->prepare('SELECT parse_json FROM siparis_belgeler WHERE id = ? AND deleted_at IS NULL');
    $p->execute([(int)$sev['po_belge_id']]);
    $pj = siparis_json_coz($p->fetchColumn() ?: '');
    $out = [];
    foreach ((array)($pj['packing_list_emails'] ?? []) as $em) if (is_string($em) && filter_var($em, FILTER_VALIDATE_EMAIL)) $out[] = $em;
    return $out;
}

/**
 * Mail taslağı (saf, DB yok). "packinglist…" ile başlayan adres(ler) Bilgi (CC), diğerleri Kime; Kime boş kalırsa hepsi Kime.
 * Konu = PKL dosya adı uzantısız (yoksa order ref). Gövde sabit İngilizce kalıp + imza ("Asya Fresh" + gönderenin adı).
 * Konu/imzadaki satır sonu ve denetim karakterleri atılır (başlık enjeksiyonu yok).
 * @param list<string> $epostalar @return array{kime:list<string>,bilgi:list<string>,konu:string,govde:string}
 */
function siparis_pkl_mail_taslak(array $epostalar, string $pklAd, string $orderRef, string $imzaAd): array
{
    $temiz = static fn(string $s): string => trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s));
    $kime = []; $bilgi = []; $gor = [];
    foreach ($epostalar as $e) {
        $e = trim((string)$e);
        if ($e === '' || filter_var($e, FILTER_VALIDATE_EMAIL) === false) continue;
        $k = strtolower($e);
        if (isset($gor[$k])) continue;
        $gor[$k] = true;
        if (str_starts_with($k, 'packinglist')) $bilgi[] = $e; else $kime[] = $e;
    }
    if (!$kime) { $kime = $bilgi; $bilgi = []; }
    $konu = $temiz($pklAd !== '' ? (string)preg_replace('/\.xlsx?$/i', '', $pklAd) : $orderRef);
    $ad = $temiz($imzaAd);
    $govde = "Hi,\nPlease find attached the packing list.\n\nBest regards,\nAsya Fresh" . ($ad !== '' ? "\n" . $ad : '');
    return ['kime' => $kime, 'bilgi' => $bilgi, 'konu' => $konu, 'govde' => $govde];
}

/** mailto: bağlantısı (ek YOK — mailto standardı ek taşıyamaz). Çıktıyı HTML'e h() ile bas. */
function siparis_pkl_mailto(array $t): string
{
    $q = [];
    if ($t['bilgi']) $q[] = 'cc=' . implode(',', $t['bilgi']);
    $q[] = 'subject=' . rawurlencode((string)$t['konu']);
    $q[] = 'body=' . rawurlencode(str_replace("\n", "\r\n", (string)$t['govde']));
    return 'mailto:' . implode(',', $t['kime']) . '?' . implode('&', $q);
}

/**
 * Ekli .eml taslağı (RFC 5322 + MIME). `X-Unsent: 1` → Outlook dosyayı GÖNDERİLMEMİŞ taslak olarak açar (Kimden = kullanıcının
 * varsayılan hesabı; From başlığı bilerek YOK). Gövde ve ek base64; ASCII dışı konu/dosya adı RFC 2047 / RFC 2231 ile.
 */
function siparis_pkl_eml(array $t, string $ekAd, string $ekIcerik, ?int $zaman = null): string
{
    $sinir = '=_asya_' . bin2hex(random_bytes(12));
    $kodla = static fn(string $s): string => preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    $ascii = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '_', $ekAd), '_') ?: 'packing-list.xlsx';
    $b = [];
    $b[] = 'X-Unsent: 1';
    $b[] = 'To: ' . implode(', ', $t['kime']);
    if ($t['bilgi']) $b[] = 'Cc: ' . implode(', ', $t['bilgi']);
    $b[] = 'Subject: ' . $kodla((string)$t['konu']);
    $b[] = 'Date: ' . date(DATE_RFC2822, $zaman ?? time());
    $b[] = 'MIME-Version: 1.0';
    $b[] = 'Content-Type: multipart/mixed; boundary="' . $sinir . '"';
    $b[] = '';
    $b[] = 'This is a multi-part message in MIME format.';
    $b[] = '--' . $sinir;
    $b[] = 'Content-Type: text/plain; charset=UTF-8';
    $b[] = 'Content-Transfer-Encoding: base64';
    $b[] = '';
    $b[] = rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", (string)$t['govde'])), 76, "\r\n"));
    $b[] = '--' . $sinir;
    $b[] = 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; name="' . $ascii . '"';
    $b[] = 'Content-Transfer-Encoding: base64';
    $b[] = 'Content-Disposition: attachment; filename="' . $ascii . '"' . ($ascii !== $ekAd ? "; filename*=UTF-8''" . rawurlencode($ekAd) : '');
    $b[] = '';
    $b[] = rtrim(chunk_split(base64_encode($ekIcerik), 76, "\r\n"));
    $b[] = '--' . $sinir . '--';
    $b[] = '';
    return implode("\r\n", $b);
}
