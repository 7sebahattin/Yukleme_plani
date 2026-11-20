<?php
// =========================================================
// config/siparis_bildirim.php — OGL Siparişler 🔔 bildirim akışı (v529; include-only, çıktı basmaz)
//
// Mailden al taraması (elle düğme ya da sayfa açılışındaki OTOMATİK arka plan sorgusu) yeni bir şey aldığında (sonuç `alindi`:
// yeni sipariş / güncellenen sevkiyat / fiyat onayı / ödeme avizosu / debit note) olay buraya yazılır; siparisler.php sağ üstteki
// zil bunları listeler. Böylece arka planda gelen belge "sessizce kaybolmaz".
//
// Değişmezler (değiştirmeden önce oku):
//  • ŞEMA YOK: tek hks_kv anahtarı `siparis_bildirimler` = {seq:int, liste:[{id,t,tur,ikon,metin,link,depo,sid}]} (en yeni sonda, ≤ 100).
//    Yazım YALNIZ siparis_mail_bitir() içinden (mail kilidi altında → read-modify-write yarışı yok).
//  • Akış ORTAKTIR (aynı posta kutusunu tarayan herkes görür); "okundu" bilgisi YALNIZ cihazda (localStorage `asya_sip_zil_<uid>` = son görülen id).
//  • Görünürlük: ödeme/debit olayları yalnız siparis_cari_yetkili() olana; sevkiyat olayları yalnız taramanın yapıldığı depo aktifken.
//  • Kişisel veri (gönderen adresi, konu, şoför) YAZILMAZ — yalnız sipariş no, belge türü, tutar, sayılar.
// =========================================================
declare(strict_types=1);
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

const SIPARIS_BILDIRIM_KV = 'siparis_bildirimler';
const SIPARIS_BILDIRIM_MAX = 100;
const SIPARIS_MAIL_OTO_DK = 10;   // otomatik arka plan sorgusu: aynı tür en çok bu kadar dakikada bir (elle düğme sınırsız)

/** Belge tür kodu → okunur ad. */
function siparis_bildirim_belge_adi(?string $t): string
{
    return match ((string)$t) { 'po' => 'Sipariş (PO)', 'll' => 'Loading List', 'pc' => 'Fiyat onayı (PC)', 'odeme' => 'Ödeme', default => 'Belge' };
}

/**
 * Tarama satırlarından bildirim öğeleri (yalnız `alindi`). Saf fonksiyon.
 * @param list<array<string,mixed>> $satirlar  siparis_mail_ek_isle satırları
 * @return list<array{tur:string,ikon:string,metin:string,link:string,sid:int}>
 */
function siparis_bildirim_ogeleri(string $tarTur, array $satirlar): array
{
    $out = [];
    foreach ($satirlar as $s) {
        if (!is_array($s) || ($s['durum'] ?? '') !== 'alindi') continue;
        $tur = (string)($s['tur'] ?? '');
        if ($tur === 'odeme' || $tarTur === 'odeme') {
            $o = is_array($s['odeme'] ?? null) ? $s['odeme'] : [];
            if (!empty($o['debit'])) {
                $yeni = (int)($o['yeni'] ?? 0);
                $out[] = ['tur' => 'odeme', 'ikon' => '📉', 'sid' => 0, 'link' => 'siparis_odemeler.php#odDebit',
                    'metin' => 'OGL kesintisi (debit note): ' . $yeni . ' yeni belge · ' . siparis_odeme_aviz_para((int)($o['kurus'] ?? 0)) . ' ' . (string)($o['pb'] ?? 'EUR')
                        . ((int)($o['eslesmeyen'] ?? 0) > 0 ? ' · ' . (int)$o['eslesmeyen'] . ' sevkiyata eşleşmedi' : '')];
            } else {
                $id = (int)($o['id'] ?? 0);
                $out[] = ['tur' => 'odeme', 'ikon' => '💶', 'sid' => 0, 'link' => $id > 0 ? 'siparis_odemeler.php?aviz=' . $id . '#odAviz' : 'siparis_odemeler.php',
                    'metin' => 'Ödeme avizosu: ' . siparis_odeme_aviz_para((int)($o['tutar_kurus'] ?? 0)) . ' ' . (string)($o['pb'] ?? '')
                        . ((string)($o['tarih'] ?? '') !== '' ? ' · ' . date('d.m.Y', strtotime((string)$o['tarih']) ?: time()) : '')];
            }
            continue;
        }
        $ad = siparis_bildirim_belge_adi($tur);
        $sevler = array_values(array_filter((array)($s['sevkiyatlar'] ?? []), 'is_array'));
        $cat = (int)($s['catisma'] ?? 0);
        if (!$sevler) {
            $out[] = ['tur' => $tarTur, 'ikon' => '📄', 'sid' => 0, 'link' => (int)($s['belge_id'] ?? 0) > 0 ? 'siparis_belge.php?id=' . (int)$s['belge_id'] : 'siparisler.php',
                'metin' => 'Yeni belge: ' . $ad . ' (sevkiyata bağlanmadı)'];
            continue;
        }
        foreach (array_slice($sevler, 0, 12) as $v) {
            $yeni = !empty($v['yeni']);
            $out[] = ['tur' => $tarTur, 'ikon' => $yeni ? '🆕' : ($tur === 'pc' ? '💲' : '🔄'), 'sid' => (int)($v['id'] ?? 0),
                'link' => 'siparis_view.php?id=' . (int)($v['id'] ?? 0),
                'metin' => ($yeni ? 'Yeni sevkiyat: ' : ($tur === 'pc' ? 'Fiyat onayı geldi: ' : 'Güncellendi: ')) . (string)($v['order_ref'] ?? '') . ' · ' . $ad
                    . ($cat > 0 ? ' · ⚠ ' . $cat . ' çatışma' : '')];
        }
        if (count($sevler) > 12) $out[] = ['tur' => $tarTur, 'ikon' => '📄', 'sid' => 0, 'link' => 'siparisler.php', 'metin' => '… ' . $ad . ' ile ' . (count($sevler) - 12) . ' sevkiyat daha'];
    }
    return $out;
}

/** Akışa ekler (mail kilidi altında çağrılır). Hata → false (tarama sonucu etkilenmez). */
function siparis_bildirim_ekle(PDO $pdo, array $ogeler, string $depo, int $ts): bool
{
    if (!$ogeler) return true;
    try {
        $kv = siparis_mail_kv_oku($pdo, SIPARIS_BILDIRIM_KV) ?? [];
        $seq = (int)($kv['seq'] ?? 0);
        $liste = array_values(array_filter((array)($kv['liste'] ?? []), 'is_array'));
        foreach ($ogeler as $o) {
            $liste[] = ['id' => ++$seq, 't' => date('Y-m-d H:i:s', $ts), 'tur' => (string)$o['tur'], 'ikon' => (string)$o['ikon'], 'metin' => siparis_kirp((string)$o['metin'], 200),
                'link' => (string)$o['link'], 'depo' => $depo, 'sid' => (int)($o['sid'] ?? 0)];
        }
        if (count($liste) > SIPARIS_BILDIRIM_MAX) $liste = array_slice($liste, -SIPARIS_BILDIRIM_MAX);
        return siparis_mail_kv_yaz($pdo, SIPARIS_BILDIRIM_KV, ['seq' => $seq, 'liste' => $liste]);
    } catch (Throwable $e) {
        error_log('[siparis_bildirim] yazılamadı: ' . get_class($e));
        return false;
    }
}

/**
 * Bu kullanıcıya görünen son bildirimler (en yeni ÖNCE). Bağlantılar yalnız beyaz listeli iç sayfalar.
 * @return list<array{id:int,t:string,ikon:string,metin:string,link:string}>
 */
function siparis_bildirim_liste(PDO $pdo, string $aktifDepo, bool $odemeGor, int $en = 40): array
{
    try { $kv = siparis_mail_kv_oku($pdo, SIPARIS_BILDIRIM_KV) ?? []; } catch (Throwable $e) { return []; }
    $out = [];
    foreach (array_reverse(array_values(array_filter((array)($kv['liste'] ?? []), 'is_array'))) as $b) {
        $tur = (string)($b['tur'] ?? '');
        if ($tur === 'odeme' ? !$odemeGor : !siparis_bildirim_depo_uygun((string)($b['depo'] ?? ''), $aktifDepo)) continue;
        $link = (string)($b['link'] ?? '');
        if (!preg_match('~^(siparis_view\.php\?id=\d+|siparis_belge\.php\?id=\d+|siparis_odemeler\.php(\?aviz=\d+)?(#od(Debit|Aviz))?|siparisler\.php)$~', $link)) $link = 'siparisler.php';
        $out[] = ['id' => (int)($b['id'] ?? 0), 't' => (string)($b['t'] ?? ''), 'ikon' => (string)($b['ikon'] ?? '📄'), 'metin' => (string)($b['metin'] ?? ''), 'link' => $link];
        if (count($out) >= $en) break;
    }
    return $out;
}

/** Sevkiyat olayı bu depoda görünür mü (boş depo = her yerde; harf duyarsız). */
function siparis_bildirim_depo_uygun(string $olayDepo, string $aktif): bool
{
    if ($olayDepo === '' || $aktif === '') return true;
    return function_exists('depo_fold') ? depo_fold($olayDepo) === depo_fold($aktif) : mb_strtolower($olayDepo) === mb_strtolower($aktif);
}

/** Otomatik sorgu zamanı gelen türler: ayarlı + son bitişi SIPARIS_MAIL_OTO_DK'dan eski (depo fark etmez; tarama ortak posta kutusu). */
function siparis_mail_oto_turler(PDO $pdo, array $ayarliTurler, int $simdiTs): array
{
    $out = [];
    foreach ($ayarliTurler as $t) {
        $s = siparis_mail_son($pdo, $t);
        if ($s === null || $simdiTs - (int)($s['bitis'] ?? 0) >= SIPARIS_MAIL_OTO_DK * 60) $out[] = $t;
    }
    return $out;
}
