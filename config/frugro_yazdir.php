<?php
// =========================================================
// config/frugro_yazdir.php — Frugro Siparişler YAZDIRMA / PDF / WP Resim verisi (v515 P1; include-only)
//
// frugro_yazdir.php (GET JSON ucu) çağırır; çıktı/exit/yazma YOK (audit uçta). assets/siparis_yazdir.js AYNEN kullanılır: sözleşme
// siparis_yazdir_veri() ile AYNI (ok, kapsam, baslik, dosya, basim, depo, filtre, adet, gecmis_say, kesildi, limit, notlar, kolonlar[{k,l,t,w}],
// satirlar[{h[],gecmis,bugun,gun}], toplam) + `birim` ({adet:'sipariş', kasa:'kutu'}: JS meta satırında "sevkiyat/kasa" yerine yazar).
// Hücreler HAM METİN (HTML yok; kaçış istemcide). Filtre ekranla AYNI fonksiyondan: frgui_filtre()/frgui_kume_where().
// Toplamlarda yalnız palet/kutu (sayı) var; para birimleri ASLA toplanmaz. Sarı zemin + " ✓" yalnız acik/tek kapsamında (çıkış < bugün).
// =========================================================
declare(strict_types=1);
// Doğrudan istek → 404. TAM YOL karşılaştırılır: uç dosyası kökte AYNI ADI taşır (frugro_yazdir.php) — basename kıyası ucu da 404'e düşürür (v385 dersi).
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

require_once __DIR__ . '/siparis_yazdir.php';   // siparis_yazdir_plakalar() (iki plaka tek hücrede ayrı satır) — TEK kaynak

if (!defined('FRGY_LIMIT')) define('FRGY_LIMIT', 500);

/**
 * Yazdırılabilir bölümler: başlık, dosya öneki, F bayrağı (ekranda görünür mü). Başlık/dosya adı TEK yer.
 * @return array<string,array{baslik:string,dosya:string,bayrak:string}>
 */
function frgy_bolumler(): array
{
    return [
        'acik'   => ['baslik' => 'İşlem Bekleyen Siparişler',        'dosya' => 'Frugro-Islem-Bekleyen-',        'bayrak' => 'bolumAcik'],
        'evrak'  => ['baslik' => 'Evrak Takımı Bekleyen Siparişler', 'dosya' => 'Frugro-Evrak-Bekleyen-',        'bayrak' => 'bolumEvrak'],
        'kapali' => ['baslik' => 'Kapanan Siparişler',               'dosya' => 'Frugro-Kapanan-',               'bayrak' => 'bolumKapali'],
    ];
}

/** Başlık: tek durum süzgeci → "Siparişler — <durum etiketi>"; değilse bölüm adı. */
function frgy_baslik(array $F, string $kapsam): string
{
    if (!empty($F['tek'])) {
        $d = (string)$F['durum'];
        return 'Siparişler — ' . (frgui_sanal_durumlar()[$d]['etiket'] ?? (frg_durumlar()[$d] ?? $d));
    }
    return frgy_bolumler()[$kapsam]['baslik'] ?? frgy_bolumler()['acik']['baslik'];
}

/**
 * Kalibre sütunları: listelenen siparişlerin PKL paletlerinde geçen kalibreler (sayısal AZALAN, sayısal olmayanlar sonda). Palet yoksa HİÇ kalibre sütunu yok.
 * @param array<int,array{kalibreler?:list<array{kalibre:string}>}> $paletHarita @return array<string,string> kalibre → sütun anahtarı ('kal_14')
 */
function frgy_kalibreler(array $paletHarita): array
{
    $set = [];
    foreach ($paletHarita as $o) foreach ((array)($o['kalibreler'] ?? []) as $k) { if ($k['kalibre'] !== '') $set[(string)$k['kalibre']] = true; }
    $liste = array_map('strval', array_keys($set));
    usort($liste, static function (string $a, string $b): int {
        $na = is_numeric($a); $nb = is_numeric($b);
        if ($na && $nb) return (float)$b <=> (float)$a ?: strnatcmp($a, $b);
        if ($na !== $nb) return $na ? -1 : 1;
        return strnatcmp($a, $b);
    });
    $out = []; $kul = [];
    foreach ($liste as $kal) {
        $t = (string)preg_replace('/[^0-9A-Za-z]/', '', $kal);
        $a = 'kal_' . ($t !== '' ? $t : 'x' . substr(md5($kal), 0, 8)); $temel = $a; $i = 2;
        while (isset($kul[$a])) $a = $temel . '_' . $i++;
        $kul[$a] = true;
        $out[$kal] = $a;
    }
    return $out;
}

/**
 * Sütun sözleşmesi (k anahtar, l başlık, t metin|sayi, w genişlik % — toplam 100). Toplam anahtarları 'palet' ve 'kasa' (JS toplam satırı buradan okur;
 * başlık "Toplam kutu").
 * @param array<string,string> $kalibreler @return list<array{k:string,l:string,t:string,w:float}>
 */
function frgy_kolonlari(array $kalibreler = []): array
{
    $n = count($kalibreler);
    $kalW = $n > 0 ? max(3.0, min(5.5, 22.0 / $n)) : 0.0;
    $urunW = max(8.0, 100 - 71 - $kalW * $n);
    $bas = [
        ['k' => 'tarih',    'l' => 'Çıkış tarihi', 't' => 'metin', 'w' => 7.0],
        ['k' => 'parti',    'l' => 'Parti no',     't' => 'metin', 'w' => 8.0],
        ['k' => 'siparis',  'l' => 'PO no',        't' => 'metin', 'w' => 10.0],
        ['k' => 'musteri',  'l' => 'Müşteri',      't' => 'metin', 'w' => 11.0],
        ['k' => 'liman',    'l' => 'Liman',        't' => 'metin', 'w' => 9.0],
        ['k' => 'tel',      'l' => 'Şoför Tel',    't' => 'metin', 'w' => 8.0],
        ['k' => 'plaka',    'l' => 'Plaka',        't' => 'metin', 'w' => 8.0],
        ['k' => 'nakliyeci', 'l' => 'Nakliyeci',   't' => 'metin', 'w' => 8.0],
        ['k' => 'urun',     'l' => 'Ürün',         't' => 'metin', 'w' => $urunW],
    ];
    foreach ($kalibreler as $kal => $a) $bas[] = ['k' => $a, 'l' => 'Kalibre ' . $kal . ' adet', 't' => 'sayi', 'w' => $kalW];
    $bas[] = ['k' => 'palet', 'l' => 'Toplam palet', 't' => 'sayi', 'w' => 5.0];
    $bas[] = ['k' => 'kasa',  'l' => 'Toplam kutu',  't' => 'sayi', 'w' => 5.0];
    $top = array_sum(array_column($bas, 'w'));
    foreach ($bas as &$c) $c['w'] = round($c['w'] * 100 / $top, 2);
    unset($c);
    return $bas;
}

/**
 * Yazdırılacak siparişler + palet özeti (SALT OKUNUR). $F = frgui_filtre() sonucu. $bolum: acik|evrak|kapali; tek durum süzgeci varsa yok sayılır ('tek').
 * Bölüm bu süzgeçle ekranda görünmüyorsa RuntimeException('kapsam_yok'). En çok FRGY_LIMIT satır (LIMIT +1 → kesildi).
 * @return array{rows:list<array<string,mixed>>,kesildi:bool,palet:array<int,array<string,mixed>>,kapsam:string}
 */
function frgy_oku(PDO $pdo, array $F, string $bolum = 'acik'): array
{
    $bolumler = frgy_bolumler();
    if (!empty($F['tek'])) $kapsam = 'tek';
    else {
        if (!isset($bolumler[$bolum])) $bolum = 'acik';
        if (empty($F[$bolumler[$bolum]['bayrak']])) throw new RuntimeException('kapsam_yok');
        $kapsam = $bolum;
    }
    [$w, $p] = frgui_kume_where($F, $kapsam);
    $st = $pdo->prepare('SELECT ' . frgui_kolonlar() . " FROM frugro_siparisler WHERE $w ORDER BY " . frgui_siralama($kapsam) . ' LIMIT ' . (FRGY_LIMIT + 1));
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $kesildi = count($rows) > FRGY_LIMIT;
    if ($kesildi) $rows = array_slice($rows, 0, FRGY_LIMIT);
    $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
    return ['rows' => $rows, 'kesildi' => $kesildi, 'palet' => frg_palet_ozet_haritasi($pdo, $ids), 'kapsam' => $kapsam];
}

/**
 * Saf: okunan veriyi yazdırma JSON sözleşmesine çevirir (DB/oturum yok). $ctx: bugun (Y-m-d), basim (d.m.Y H:i), depo, baslik, filtre_ozet.
 * @return array<string,mixed>
 */
function frgy_bicimle(array $okunan, array $ctx): array
{
    $bugun = (string)($ctx['bugun'] ?? date('Y-m-d'));
    $basim = (string)($ctx['basim'] ?? date('d.m.Y H:i'));
    $kapsam = (string)($okunan['kapsam'] ?? 'acik');
    $isaretle = $kapsam === 'tek' || $kapsam === 'acik';
    $paletH = (array)($okunan['palet'] ?? []);
    $kalibreler = frgy_kalibreler($paletH);
    $satirlar = []; $gecmisSay = 0; $topPalet = 0; $topKutu = 0; $topKal = [];
    foreach ($okunan['rows'] as $r) {
        $sid = (int)$r['id'];
        $gun = frg_tarih($r['cikis_tarihi'] ?? null);
        $gecmis = $isaretle && $gun !== null && $gun < $bugun;
        if ($gecmis) $gecmisSay++;
        $tarih = $gun !== null ? date('d.m.Y', (int)strtotime($gun . ' 12:00:00')) . ($gecmis ? ' ✓' : '') : '';
        $hs = frg_hesap($r);
        $topPalet += $hs['palet']; $topKutu += $hs['kutu'];
        $kp = [];
        foreach ((array)($paletH[$sid]['kalibreler'] ?? []) as $k) $kp[(string)$k['kalibre']] = ($kp[(string)$k['kalibre']] ?? 0) + (int)$k['palet'];
        $urun = implode(' · ', array_filter([trim((string)($r['urun'] ?? '')), trim((string)($r['cesit'] ?? ''))], static fn(string $x): bool => $x !== ''));
        $h = [
            $tarih,
            trim((string)($r['parti_no'] ?? '')),
            trim((string)($r['po_no'] ?? '')),
            trim((string)($r['son_musteri'] ?? '')),
            trim((string)($r['giris_limani'] ?? '')),
            trim((string)($r['sofor_telefon'] ?? '')),
            function_exists('siparis_yazdir_plakalar') ? siparis_yazdir_plakalar((string)($r['plaka'] ?? '')) : trim((string)($r['plaka'] ?? '')),
            trim((string)($r['nakliyeci'] ?? '')),
            $urun,
        ];
        foreach ($kalibreler as $kal => $a) {
            if (isset($kp[$kal])) { $h[] = sipui_sayi($kp[$kal]); $topKal[$a] = ($topKal[$a] ?? 0) + $kp[$kal]; }
            else $h[] = '-';
        }
        $h[] = sipui_sayi($hs['palet']);
        $h[] = sipui_sayi($hs['kutu']);
        $satirlar[] = ['h' => $h, 'gecmis' => $gecmis, 'bugun' => $gun !== null && $gun === $bugun, 'gun' => $gun];
    }
    $notlar = [];
    if (!empty($okunan['kesildi'])) $notlar[] = 'İlk ' . FRGY_LIMIT . ' sipariş basıldı — süzgeci daraltın.';
    $bolumler = frgy_bolumler();
    $toplam = ['palet' => sipui_sayi($topPalet), 'kasa' => sipui_sayi($topKutu)];
    foreach ($kalibreler as $a) $toplam[$a] = isset($topKal[$a]) ? sipui_sayi($topKal[$a]) : '';
    return [
        'ok' => true,
        'kapsam' => $kapsam,
        'baslik' => (string)($ctx['baslik'] ?? ($bolumler[$kapsam]['baslik'] ?? 'Frugro Siparişler')),
        'dosya' => ($bolumler[$kapsam]['dosya'] ?? 'Frugro-Siparisler-') . substr($basim, 6, 4) . '-' . substr($basim, 3, 2) . '-' . substr($basim, 0, 2),
        'basim' => $basim,
        'depo' => (string)($ctx['depo'] ?? ''),
        'filtre' => (string)($ctx['filtre_ozet'] ?? ''),
        'adet' => count($satirlar),
        'gecmis_say' => $gecmisSay,
        'kesildi' => !empty($okunan['kesildi']),
        'limit' => FRGY_LIMIT,
        'notlar' => $notlar,
        'birim' => ['adet' => 'sipariş', 'kasa' => 'kutu'],
        'kolonlar' => frgy_kolonlari($kalibreler),
        'satirlar' => $satirlar,
        'toplam' => $toplam,
    ];
}

/** Plan imzası: oku + biçimle tek çağrıda. @return array<string,mixed> */
function frgy_veri(PDO $pdo, array $F, string $bolum, array $ctx): array
{
    return frgy_bicimle(frgy_oku($pdo, $F, $bolum), $ctx);
}
