<?php
// =========================================================
// config/frugro_wp.php — Frugro Siparişler › ⋮ "WP Paylaş" sipariş kartı verisi (v515 P1; include-only)
//
// frugro_paylas.php (GET JSON ucu) çağırır; çıktı/exit/yazma YOK (audit uçta). assets/siparis_wp.js AYNEN kullanılır (`data-sip-wp-url="frugro_paylas.php"`):
// sözleşme siparis_wp_veri() ile aynı (ok, id, dosya, alanlar[{k,etiket,deger}], kalem_basliklar[4], kalemler[{urun,kalibre,pf,palet_koli}], kalem_say,
// adres_baslik, adresler[{ad,kod,adres,kalibreler}], toplam{etiket,palet_koli}, notlar). Değerler HAM METİN, boş → '—'. Kapalı/iptal sipariş de paylaşılır.
// =========================================================
declare(strict_types=1);
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

/** Kart alanları — sıra ve etiketler sahibindir. @return list<array{k:string,etiket:string}> */
function frgw_alanlari(): array
{
    return [
        ['k' => 'parti',     'etiket' => 'Parti No'],
        ['k' => 'tarih',     'etiket' => 'Çıkış tarihi'],
        ['k' => 'siparis',   'etiket' => 'PO No'],
        ['k' => 'nakliyeci', 'etiket' => 'Nakliyeci'],
        ['k' => 'plaka',     'etiket' => 'Plaka'],
        ['k' => 'sofor',     'etiket' => 'Şoför adı'],
        ['k' => 'tel',       'etiket' => 'Şoför telefonu'],
    ];
}

/** Kutu / ambalaj tipi (dış paket): sondaki/baştaki " /", "·", "-", "," gibi boş artıkları atar. Boşsa ''. (frugro_yazdir.php frgy_kutu_tipi() ile AYNI kural.) */
function frgw_kutu_tipi(mixed $v): string
{
    return trim((string)preg_replace('/^[\s\/·,;\-]+|[\s\/·,;\-]+$/u', '', (string)($v ?? '')));
}

function frgw_deger(mixed $v): string
{
    $t = trim((string)($v ?? ''));
    return $t !== '' ? $t : '—';
}

/** "N / M" (palet / kutu); ikisi de yoksa '—'. */
function frgw_palet_kutu(mixed $palet, mixed $kutu): string
{
    $var = static fn($x): bool => $x !== null && $x !== '' && is_numeric($x);
    if (!$var($palet) && !$var($kutu)) return '—';
    return sipui_sayi($palet) . ' / ' . sipui_sayi($kutu);
}

/** Paylaşılan PNG'nin dosya adı (uzantısız): "WP-<PO no>"; kişisel veri İÇERMEZ. */
function frgw_dosya_adi(string $po, int $sid): string
{
    $s = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($po)), '-.');
    return 'WP-FRG-' . ($s !== '' ? $s : 'siparis-' . $sid);
}

/**
 * Kart verisi. $s = frugro_siparisler satırı (frg_getir), $ozet = frg_palet_ozet_haritasi()[id] ?? [] (palet satırı varsa kalem = kalibre başına, yoksa siparişin kendisi).
 * @return array<string,mixed>
 */
function frgw_veri(array $s, array $ozet = []): array
{
    $sid = (int)($s['id'] ?? 0);
    $t = frg_tarih($s['cikis_tarihi'] ?? null);
    $deger = [
        'parti' => frgw_deger($s['parti_no'] ?? null),
        'tarih' => $t !== null ? date('d.m.Y', (int)strtotime($t . ' 12:00:00')) : '—',
        'siparis' => frgw_deger($s['po_no'] ?? null),
        'nakliyeci' => frgw_deger($s['nakliyeci'] ?? null),
        'plaka' => frgw_deger($s['plaka'] ?? null),
        'sofor' => frgw_deger($s['sofor_adi'] ?? null),
        'tel' => frgw_deger($s['sofor_telefon'] ?? null),
    ];
    $alanlar = [];
    foreach (frgw_alanlari() as $a) $alanlar[] = ['k' => $a['k'], 'etiket' => $a['etiket'], 'deger' => $deger[$a['k']]];
    $kalemler = [];
    $s['dis_paket'] = frgw_kutu_tipi($s['dis_paket'] ?? null);   // kart ürün satırında ham " /" artığı çıkmasın
    foreach (frgui_kalemler($s, $ozet) as $k) {
        $c = sipui_kalem_coz($k);
        if ($c === null) continue;
        $kalemler[] = ['urun' => $c['urun'] !== '' ? $c['urun'] : '—', 'kalibre' => $c['kalibre'] !== '' ? $c['kalibre'] : '—',
                       'pf' => (($k['pf'] ?? null) !== null && is_numeric($k['pf'])) ? sipui_sayi($k['pf']) : '—',   // PF = kutu / palet
                       'palet_koli' => frgw_palet_kutu($c['palet'], $c['koli'])];
    }
    $say = count($kalemler);
    if (!$kalemler) $kalemler[] = ['urun' => '—', 'kalibre' => '—', 'pf' => '—', 'palet_koli' => '—'];   // başlıklar her zaman görünür
    $h = frg_hesap($s);
    $adres = trim((string)($s['teslim_adresi'] ?? ''));
    $musteri = trim((string)($s['son_musteri'] ?? ''));
    return [
        'ok' => true,
        'id' => $sid,
        'dosya' => frgw_dosya_adi((string)($s['po_no'] ?? ''), $sid),
        'alanlar' => $alanlar,
        'kalem_basliklar' => ['Ürün', 'Kalibre', 'PF', 'Palet/Kutu'],
        'kalemler' => $kalemler,
        'kalem_say' => $say,
        'adres_baslik' => 'Teslim adresi',
        'adresler' => ($adres !== '' || $musteri !== '') ? [['ad' => $musteri, 'kod' => '', 'adres' => $adres, 'kalibreler' => []]] : [],
        'toplam' => ['etiket' => 'Toplam', 'palet_koli' => frgw_palet_kutu($ozet['palet'] ?? $h['palet'], ($ozet['kutu'] ?? null) !== null ? $ozet['kutu'] : $h['kutu'])],
        'notlar' => [],
    ];
}
