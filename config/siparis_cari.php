<?php
// =========================================================
// config/siparis_cari.php — "Sipariş Cari Dökümü" verisi (v397; include-only, salt okunur)
//
// siparis_cari.php (yazdırılabilir sayfa) çağırır; çıktı/yazma yok (audit sayfada). _siparis_ortak.php YÜKLÜ varsayılır
// (sipui_kalem_coz / sipui_tarih). Kalem süzgeci TEK yer sipui_kalem_coz() — kopyalama.
// Para: satır tutarı kuruşa (tam sayı) yuvarlanır; toplam = yuvarlanmış satırların toplamı; para birimleri ASLA toplanmaz.
// Yüklendi kuralı TEK yer: siparis_cari_yuklendi_mi(). Tahsilat 2. etapta: siparis_cari_tahsilat_toplam() şimdilik [].
// =========================================================
declare(strict_types=1);
// Doğrudan istek → 404. TAM YOL karşılaştırılır (basename değil — bkz. config/siparis_yazdir.php, v385).
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/siparis_yazdir.php';   // alıcı ülke = siparis_yazdir_ulke() (TEK kaynak)

if (!defined('SIPARIS_CARI_LIMIT')) define('SIPARIS_CARI_LIMIT', 1000);
if (!defined('SIPARIS_CARI_KOLI_KG_VARSAYILAN')) define('SIPARIS_CARI_KOLI_KG_VARSAYILAN', 3.5);

/** Sayfa + siparisler.php düğmesi TEK kapı. Fiyat finansal veridir → reports.export (izleyici tohumunda yok). */
function siparis_cari_yetkili(): bool
{
    return function_exists('can') && can('reports.export');
}

/** Yüklendi: iptal değil VE (yola_cikti/pkl_hazir/mail_gonderildi VEYA var_pkl=1 VEYA yukleme_tarihi < bugün). */
function siparis_cari_yuklendi_mi(array $s, string $bugun): bool
{
    $d = (string)($s['durum'] ?? '');
    if ($d === 'iptal') return false;
    return $d === 'mail_gonderildi';   // yükleme tarihi geçse de mail gönderilmedikçe yüklenmemiş sayılır
}

/** "3,5 KG" / "3.5kg" → 3.5; ilk sayı, 0 < x < 100; okunamazsa null. */
function siparis_cari_koli_kg(?string $icerik): ?float
{
    if ($icerik === null || !preg_match('/(\d+(?:[.,]\d+)?)/', $icerik, $m)) return null;
    $v = (float)str_replace(',', '.', $m[1]);
    return ($v > 0 && $v < 100) ? $v : null;
}

/** Para hesabı miktarı: pc_koli > 0 ise o, yoksa etkin_koli (yoksa 0). */
function siparis_cari_kalem_koli(array $k): int
{
    $pc = $k['pc_koli'] ?? null;
    if ($pc !== null && $pc !== '' && is_numeric($pc) && (int)$pc > 0) return (int)$pc;
    $ek = $k['etkin_koli'] ?? null;
    return ($ek !== null && $ek !== '' && is_numeric($ek)) ? max(0, (int)$ek) : 0;
}

/** fiyat_birim dolu ve CAR/KOLI/BOX değilse (ör. KG) fiyat kg başınadır. */
function siparis_cari_kg_bazli(?string $birim): bool
{
    $b = mb_strtoupper(trim((string)$birim), 'UTF-8');
    return $b !== '' && !in_array($b, ['CAR', 'KOLI', 'KOLİ', 'BOX'], true);
}

/**
 * Tek satır: fiyatlar on-binde (4 ondalık tam sayı), tutar kuruş; yarım yukarı, TAM SAYI aritmetiği.
 * @return array{baz:string,fiyat_koli:int,fiyat_kg:int,tutar:int}
 */
function siparis_cari_satir_tutar(int $koli, float $fiyat, int $koliGram, ?string $fiyatBirim): array
{
    $koli = max(0, $koli);
    $f = (int)round($fiyat * 10000);
    $g = max(1, $koliGram);
    if (siparis_cari_kg_bazli($fiyatBirim)) {
        $k = intdiv($f + 50, 100);   // kg fiyatı kuruşa (2 ondalık) yarım yukarı
        return ['baz' => 'kg', 'fiyat_koli' => intdiv($f * $g + 500, 1000), 'fiyat_kg' => $k * 100, 'tutar' => intdiv($koli * $g * $k + 500, 1000)];
    }
    // kg fiyatı = koli fiyatı / koli kg, 2 ondalığa yuvarlanır; tutar yuvarlanmış kg fiyatıyla hesaplanır
    $k = intdiv(intdiv($f * 1000 + intdiv($g, 2), $g) + 50, 100);
    return ['baz' => 'koli', 'fiyat_koli' => $f, 'fiyat_kg' => $k * 100, 'tutar' => intdiv($koli * $g * $k + 500, 1000)];
}

/** Kuruş → "15.844,80" (negatif "-1.234,56"). */
function siparis_cari_para(int $kurus): string
{
    $a = abs($kurus);
    return ($kurus < 0 ? '-' : '') . number_format(intdiv($a, 100), 0, ',', '.') . ',' . str_pad((string)($a % 100), 2, '0', STR_PAD_LEFT);
}

/** On-binde → "4,1100". */
function siparis_cari_fiyat4(int $onbinde): string
{
    $a = abs($onbinde);
    return ($onbinde < 0 ? '-' : '') . number_format(intdiv($a, 10000), 0, ',', '.') . ',' . str_pad((string)($a % 10000), 4, '0', STR_PAD_LEFT);
}

/** Gram → ekranda tam kg ("12.880"; KG ekranda tam sayı kuralı). */
function siparis_cari_kg(int $gram): string
{
    return number_format((int)round($gram / 1000), 0, ',', '.');
}

/** Koli ağırlığı metni: 3.5 → "3,5", 10.0 → "10". */
function siparis_cari_kg_metin(float $kg): string
{
    $s = rtrim(rtrim(number_format($kg, 3, ',', '.'), '0'), ',');
    return $s === '' ? '0' : $s;
}

/** Para birimi anahtarı: 3 harf büyük; değilse '?'. */
function siparis_cari_pb(?string $pb): string
{
    $p = strtoupper(trim((string)$pb));
    return preg_match('/^[A-Z]{3}$/', $p) ? $p : '?';
}

/** Kalibre sayısal artan (sayısal değilse doğal), sonra sira, id. */
function siparis_cari_kalem_sirala(array $kalemler): array
{
    usort($kalemler, static function (array $a, array $b): int {
        $x = trim((string)($a['kalibre'] ?? '')); $y = trim((string)($b['kalibre'] ?? ''));
        $c = (is_numeric($x) && is_numeric($y)) ? ((float)$x <=> (float)$y) : 0;
        if ($c === 0) $c = strnatcmp($x, $y);
        if ($c === 0) $c = (int)($a['sira'] ?? 0) <=> (int)($b['sira'] ?? 0);
        return $c !== 0 ? $c : ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
    });
    return $kalemler;
}

/**
 * GET filtresi (saf). bas: anahtar YOKSA varsayılan = 2 ay önceki ayın 1'i; anahtar boşsa sınırsız; geçersizse varsayılan.
 * bit: boş/geçersiz → sınırsız. bas > bit → yer değiştirir. alici: [A-Za-z0-9._-]{1,20} değilse ''.
 */
function siparis_cari_filtre(array $get, string $bugun): array
{
    $varsayilan = (new DateTimeImmutable($bugun))->modify('first day of -2 months')->format('Y-m-d');
    $bas = $varsayilan;
    if (array_key_exists('bas', $get)) {
        $v = is_string($get['bas']) ? trim($get['bas']) : '';
        $bas = $v === '' ? '' : (siparis_tarih_coz($v) ?? $varsayilan);
    }
    $v = is_string($get['bit'] ?? null) ? trim((string)$get['bit']) : '';
    $bit = $v === '' ? '' : (siparis_tarih_coz($v) ?? '');
    if ($bas !== '' && $bit !== '' && $bas > $bit) [$bas, $bit] = [$bit, $bas];
    $a = is_string($get['alici'] ?? null) ? trim((string)$get['alici']) : '';
    $alici = preg_match('/^[A-Za-z0-9._-]{1,20}$/', $a) ? $a : '';
    $ozet = ['Yükleme tarihi: ' . ($bas !== '' ? sipui_tarih($bas) : 'başlangıç yok') . ' – ' . ($bit !== '' ? sipui_tarih($bit) : 'açık')];
    if ($alici !== '') {
        $ad = (string)(siparis_yazdir_ulke_haritasi()[$alici]['ad'] ?? '');
        $ozet[] = 'Alıcı: ' . ($ad !== '' ? $ad . ' (' . $alici . ')' : $alici);
    }
    $ozet[] = 'Tarihsiz siparişler her zaman dahil';
    return ['bas' => $bas, 'bit' => $bit, 'alici' => $alici, 'ozet' => $ozet];
}

/**
 * SALT OKUNUR. iptal + silinmiş hariç; tarih filtresi NULL tarihli satırı DIŞLAMAZ; aktif depo süzgeci.
 * En çok SIPARIS_CARI_LIMIT sevkiyat (LIMIT +1 → kesildi). Kalemler 500'lük parçalarla.
 */
function siparis_cari_oku(PDO $pdo, array $f): array
{
    $w = ['s.deleted_at IS NULL', "s.durum <> 'iptal'"]; $p = [];
    if (($f['bas'] ?? '') !== '') { $w[] = '(s.yukleme_tarihi IS NULL OR s.yukleme_tarihi >= :cb)'; $p[':cb'] = (string)$f['bas']; }
    if (($f['bit'] ?? '') !== '') { $w[] = '(s.yukleme_tarihi IS NULL OR s.yukleme_tarihi <= :ct)'; $p[':ct'] = (string)$f['bit']; }
    if (($f['alici'] ?? '') !== '') { $w[] = 's.alici_kodu = :ca'; $p[':ca'] = (string)$f['alici']; }
    [$dsql, $dprm] = depo_sql_column('s.depo', ':cdm');
    $st = $pdo->prepare('SELECT s.id, s.order_ref, s.alici_kodu, s.alici_adi, s.yukleme_tarihi, s.plakalar, s.nakliyeci, s.durum, s.var_pkl, s.depo'
        . ' FROM siparis_sevkiyatlar s WHERE ' . implode(' AND ', $w) . $dsql
        . ' ORDER BY s.yukleme_tarihi IS NULL, s.yukleme_tarihi, s.id LIMIT ' . (SIPARIS_CARI_LIMIT + 1));
    $st->execute($p + $dprm);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $kesildi = count($rows) > SIPARIS_CARI_LIMIT;
    if ($kesildi) $rows = array_slice($rows, 0, SIPARIS_CARI_LIMIT);
    $kalem = [];
    foreach (array_chunk(array_map(static fn(array $r): int => (int)$r['id'], $rows), 500) as $ids) {
        $kq = $pdo->prepare('SELECT id, sevkiyat_id, kalibre, sira, urun, aciklama, cesit, koli_icerik, pf, po_palet, etkin_palet, etkin_koli, pc_koli,'
            . ' fiyat, para_birimi, fiyat_birim, fiyat_tarihi FROM siparis_kalemler WHERE sevkiyat_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
            . ' ORDER BY sevkiyat_id, sira, id');
        $kq->execute($ids);
        foreach ($kq->fetchAll(PDO::FETCH_ASSOC) as $k) $kalem[(int)$k['sevkiyat_id']][] = $k;
    }
    return ['rows' => $rows, 'kalem' => $kalem, 'kesildi' => $kesildi];
}

/** 2. etap kancası (OGL ödeme girişi): para birimi → tahsil edilen kuruş. Şimdilik tablo yok → []. İmza SABİT. */
function siparis_cari_tahsilat_toplam(PDO $pdo, array $f): array
{
    return [];
}

/**
 * Saf: sınıflama + hesap. $ctx: bugun (Y-m-d), tahsilat (array<string,int>).
 * Dönüş: yuklenmemis / yuklenen / fiyatli (sevkiyat listeleri, SQL sırası), ozet, cari (para birimi → faturalanan/tahsilat/bakiye),
 * varsayilan_kg, kesildi. Her DB değeri açıkça çevrilir (SQLite/MySQL tip farkı + strict_types).
 */
function siparis_cari_veri(array $okunan, array $ctx): array
{
    $bugun = (string)($ctx['bugun'] ?? date('Y-m-d'));
    $tahsilat = is_array($ctx['tahsilat'] ?? null) ? $ctx['tahsilat'] : [];
    $out = ['yuklenmemis' => [], 'yuklenen' => [], 'fiyatli' => [], 'ozet' => [
        'yuklenmemis' => ['sevkiyat' => 0, 'palet' => 0, 'koli' => 0, 'gram' => 0],
        'yuklenen'    => ['sevkiyat' => 0, 'koli' => 0, 'gram' => 0],
        'bekleyen'    => ['sevkiyat' => 0, 'koli' => 0, 'gram' => 0],
    ], 'cari' => [], 'varsayilan_kg' => false, 'kesildi' => (bool)($okunan['kesildi'] ?? false)];
    $fatura = [];
    foreach ((array)($okunan['rows'] ?? []) as $r) {
        $id = (int)$r['id'];
        $yuklendi = siparis_cari_yuklendi_mi($r, $bugun);
        $tarih = ($r['yukleme_tarihi'] ?? null) !== null ? siparis_tarih_coz((string)$r['yukleme_tarihi']) : null;
        $sev = ['id' => $id, 'order_ref' => (string)($r['order_ref'] ?? ''), 'tarih' => $tarih,
            'ulke' => siparis_yazdir_ulke(($r['alici_kodu'] ?? null) !== null ? (string)$r['alici_kodu'] : null, ($r['alici_adi'] ?? null) !== null ? (string)$r['alici_adi'] : null),
            'plaka' => (string)($r['plakalar'] ?? ''), 'nakliyeci' => (string)($r['nakliyeci'] ?? ''),
            'yuklendi' => $yuklendi, 'fiyatli' => false, 'fiyat_tarihi' => null,
            'satirlar' => [], 'toplam' => ['palet' => 0, 'koli' => 0, 'gram' => 0], 'tutar' => []];
        $fiyatTarihi = null;
        foreach (siparis_cari_kalem_sirala((array)($okunan['kalem'][$id] ?? [])) as $k) {
            $c = sipui_kalem_coz($k);
            if ($c === null) continue;
            $koli = siparis_cari_kalem_koli($k);
            $kgOk = siparis_cari_koli_kg(($k['koli_icerik'] ?? null) !== null ? (string)$k['koli_icerik'] : null);
            $koliKg = $kgOk ?? (float)SIPARIS_CARI_KOLI_KG_VARSAYILAN;
            $koliGram = (int)round($koliKg * 1000);
            $gram = $koli * $koliGram;
            $palet = is_numeric($c['palet']) ? (int)$c['palet'] : null;
            $fiyat = is_numeric($k['fiyat'] ?? null) ? (float)$k['fiyat'] : 0.0;
            $sat = ['kalibre' => $c['kalibre'], 'urun' => $c['urun'], 'palet' => $palet, 'koli' => $koli, 'koli_kg' => $koliKg,
                'kg_varsayilan' => $kgOk === null, 'gram' => $gram, 'fiyatli' => false, 'pb' => '', 'baz' => '',
                'fiyat_koli' => null, 'fiyat_kg' => null, 'tutar' => null];
            if ($kgOk === null) $out['varsayilan_kg'] = true;
            if ($fiyat > 0) {
                $t = siparis_cari_satir_tutar($koli, $fiyat, $koliGram, ($k['fiyat_birim'] ?? null) !== null ? (string)$k['fiyat_birim'] : null);
                $pb = siparis_cari_pb(($k['para_birimi'] ?? null) !== null ? (string)$k['para_birimi'] : null);
                $sat['fiyatli'] = true; $sat['pb'] = $pb; $sat['baz'] = $t['baz'];
                $sat['fiyat_koli'] = $t['fiyat_koli']; $sat['fiyat_kg'] = $t['fiyat_kg']; $sat['tutar'] = $t['tutar'];
                $sev['tutar'][$pb] = ($sev['tutar'][$pb] ?? 0) + $t['tutar'];
                $ft = ($k['fiyat_tarihi'] ?? null) !== null ? siparis_tarih_coz((string)$k['fiyat_tarihi']) : null;
                if ($ft !== null && ($fiyatTarihi === null || $ft > $fiyatTarihi)) $fiyatTarihi = $ft;
            }
            $sev['toplam']['koli'] += $koli; $sev['toplam']['gram'] += $gram;
            if ($palet !== null) $sev['toplam']['palet'] += $palet;
            $sev['satirlar'][] = $sat;
        }
        ksort($sev['tutar']);
        $sev['fiyatli'] = $sev['tutar'] !== [];
        $sev['fiyat_tarihi'] = $fiyatTarihi;
        if ($yuklendi) {
            $out['yuklenen'][] = $sev;
            $out['ozet']['yuklenen']['sevkiyat']++;
            $out['ozet']['yuklenen']['koli'] += $sev['toplam']['koli'];
            $out['ozet']['yuklenen']['gram'] += $sev['toplam']['gram'];
            if (!$sev['fiyatli']) $out['ozet']['bekleyen']['sevkiyat']++;
            foreach ($sev['satirlar'] as $s) {
                if ($s['fiyatli']) continue;
                $out['ozet']['bekleyen']['koli'] += $s['koli'];
                $out['ozet']['bekleyen']['gram'] += $s['gram'];
            }
        } else {
            $out['yuklenmemis'][] = $sev;
            $out['ozet']['yuklenmemis']['sevkiyat']++;
            $out['ozet']['yuklenmemis']['palet'] += $sev['toplam']['palet'];
            $out['ozet']['yuklenmemis']['koli'] += $sev['toplam']['koli'];
            $out['ozet']['yuklenmemis']['gram'] += $sev['toplam']['gram'];
        }
        if ($sev['fiyatli']) {
            $out['fiyatli'][] = $sev;
            foreach ($sev['tutar'] as $pb => $kurus) $fatura[$pb] = ($fatura[$pb] ?? 0) + $kurus;
        }
    }
    foreach (array_unique(array_merge(array_keys($fatura), array_keys($tahsilat))) as $pb) {
        $fa = (int)($fatura[$pb] ?? 0); $ta = (int)($tahsilat[$pb] ?? 0);
        $out['cari'][(string)$pb] = ['faturalanan' => $fa, 'tahsilat' => $ta, 'bakiye' => $fa - $ta];
    }
    ksort($out['cari']);
    return $out;
}
