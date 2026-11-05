<?php
// =========================================================
// config/siparis_debit.php — OGL DEBIT NOTE (v492; include-only, çıktı basmaz)
//
// Siparişler › Mailden al › "Ödeme sorgula" artık OGL'nin "Invoice-Debit Note" PDF'lerini de okur (doc_type debit_note,
// config/siparis_pdf.php). OGL, fiyat farkını (ör. anlaşılan 4,58 € / kesilen 4,60 €) ayrıca tahsil etmez, ÖDEMEDEN DÜŞER; kesinti
// ödeme avizosunda "…DI/DII" satırları olarak görünür. Bu dosya o belgeleri sevkiyata bağlar ve Cari Döküm / Fiyat Dengeleme'nin
// "kalan fark" hesabına verir.
//
// Değişmezler (değiştirmeden önce oku):
//  • ŞEMA YOK, ÖDEME YAZMAZ. Debit note para hareketi DEĞİLDİR: tutar zaten avizodaki net ödemeden düşülmüştür; siparis_odemeler'e
//    SATIR EKLENMEZ (aksi çift düşülür). Kayıt hks_kv `siparis_debit_<belge no>` (JSON, belge numarası OGL'de tekil → tekrar = zaten).
//  • Belge satırı (siparis_belgeler) AÇILMAZ; siparis_dosya_yukle_isle çağrılmaz; IMAP'a gidilmez (mail_ek_hazirla çağrılmaz).
//  • Hesaba YALNIZ fiyat farkı debit'leri girer (`fiyat_farki`: neden "WRONG PRICE" + anlaşılan < kesilen + EUR). Başka nedenli
//    debit'ler (kalite vb.) listelenir ama kalan farkı DEĞİŞTİRMEZ.
//  • Sevkiyat eşlemesi OKUMA ANINDA yapılır (kaydedilmez): önce fatura no → üretilmiş invoice'ı olan sevkiyat (siparis_odeme_aviz_eslesmeler),
//    yoksa LKW/Container numarası → order_ref sonu (TC418932.10 ↔ 418932.10), yalnız TEK aday. Belirsiz/yok → eşleşmez, hesaba GİRMEZ.
//  • Kalan fark = (gelen − kesilen) + debit: kesilen fatura gelen fiyattan yüksekse fark eksidir, debit onu kapatır.
// =========================================================
declare(strict_types=1);
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/siparis_odeme_aviz.php';   // no_coz, eslesmeler, para biçimleyici, kv yardımcıları (siparis_inv_kv_*)

const SIPARIS_DEBIT_KV = 'siparis_debit_';
const SIPARIS_DEBIT_MAX = 5000;

function siparis_debit_anahtar(string $no): string { return SIPARIS_DEBIT_KV . $no; }

function siparis_debit_no_gecerli(string $no): bool { return (bool)preg_match('/^\d{4}-\d{4,8}$/', $no); }

function siparis_debit_kaydet(PDO $pdo, string $no, array $kv): bool
{
    return siparis_debit_no_gecerli($no) && siparis_inv_kv_yaz($pdo, siparis_debit_anahtar($no), $kv);
}

function siparis_debit_oku(PDO $pdo, string $no): ?array
{
    return siparis_debit_no_gecerli($no) ? siparis_inv_kv_oku($pdo, siparis_debit_anahtar($no)) : null;
}

/**
 * Kayıtlı tüm debit note'lar (tarih azalan, sonra belge no). Bozuk/eksik kayıt atlanır; tablo yoksa [] (fail soft).
 * @return list<array<string,mixed>>
 */
function siparis_debit_hepsi(PDO $pdo): array
{
    $out = [];
    try {
        $st = $pdo->prepare('SELECT anahtar, deger FROM `' . siparis_evrak_mail_kv_tablo() . '` WHERE anahtar LIKE ? LIMIT ' . SIPARIS_DEBIT_MAX);
        $st->execute([SIPARIS_DEBIT_KV . '%']);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $no = substr((string)$r['anahtar'], strlen(SIPARIS_DEBIT_KV));
            if (!siparis_debit_no_gecerli($no)) continue;
            $j = json_decode((string)($r['deger'] ?? ''), true);
            if (!is_array($j) || (int)($j['amount_kurus'] ?? 0) <= 0) continue;
            $j['no'] = $no;
            $out[] = $j;
        }
    } catch (Throwable $e) {
        return [];
    }
    usort($out, static fn(array $a, array $b): int => strcmp((string)($b['tarih'] ?? ''), (string)($a['tarih'] ?? '')) ?: strcmp((string)$a['no'], (string)$b['no']));
    return $out;
}

/**
 * Debit note → sevkiyat eşlemesi (okuma anında). Dönüş: belge no → ['durum' => eslesti|belirsiz|yok, 'sid' => ?int, 'yontem' => fatura|lkw|'', 'ref' => string].
 * @param list<array<string,mixed>> $notlar  kayıtlı debit kayıtları (invoice_no, lkw)
 * @return array<string,array{durum:string,sid:?int,yontem:string,ref:string}>
 */
function siparis_debit_esle(PDO $pdo, array $notlar): array
{
    $out = [];
    if (!$notlar) return $out;
    $govde = [];
    foreach ($notlar as $n) {
        $nc = siparis_odeme_aviz_no_coz((string)($n['invoice_no'] ?? ''));
        if ($nc['govde'] !== null) $govde[$nc['govde']] = true;
    }
    try { $inv = siparis_odeme_aviz_eslesmeler($pdo, array_keys($govde)); } catch (Throwable $e) { $inv = []; }
    // LKW adayları (tek sorgu / tekil LKW)
    $lkwAday = [];
    foreach ($notlar as $n) foreach ((array)($n['lkw'] ?? []) as $l) if (preg_match('/^\d{5,}(?:\.\d{1,3})?$/', (string)$l)) $lkwAday[(string)$l] = [];
    foreach (array_keys($lkwAday) as $l) {
        try {
            $st = $pdo->prepare('SELECT id, order_ref FROM siparis_sevkiyatlar WHERE deleted_at IS NULL AND order_ref LIKE ? LIMIT 5');
            $st->execute(['%' . $l]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (preg_match('/(?:^|\D)' . preg_quote($l, '/') . '$/', (string)$r['order_ref'])) $lkwAday[$l][] = ['id' => (int)$r['id'], 'ref' => (string)$r['order_ref']];
            }
        } catch (Throwable $e) {
            $lkwAday[$l] = [];
        }
    }
    foreach ($notlar as $n) {
        $no = (string)$n['no'];
        $e = ['durum' => 'yok', 'sid' => null, 'yontem' => '', 'ref' => ''];
        $nc = siparis_odeme_aviz_no_coz((string)($n['invoice_no'] ?? ''));
        $iv = $nc['govde'] !== null ? ($inv[$nc['govde']] ?? null) : null;
        if ($iv !== null && ($iv['durum'] ?? '') === 'eslesti' && !empty($iv['v'][0]['id'])) {
            $e = ['durum' => 'eslesti', 'sid' => (int)$iv['v'][0]['id'], 'yontem' => 'fatura', 'ref' => (string)($iv['v'][0]['ref'] ?? '')];
        } else {
            $adaylar = [];
            foreach ((array)($n['lkw'] ?? []) as $l) foreach ($lkwAday[(string)$l] ?? [] as $a) $adaylar[$a['id']] = $a;
            if (count($adaylar) === 1) { $a = reset($adaylar); $e = ['durum' => 'eslesti', 'sid' => (int)$a['id'], 'yontem' => 'lkw', 'ref' => (string)$a['ref']]; }
            elseif (count($adaylar) > 1 || ($iv !== null && ($iv['durum'] ?? '') === 'belirsiz')) $e['durum'] = 'belirsiz';
        }
        $out[$no] = $e;
    }
    return $out;
}

/**
 * Cari Döküm / Fiyat Dengeleme için: sevkiyat id → ['kurus' (fiyat farkı debit'leri, EUR), 'diger_kurus' (başka nedenli), 'liste' => list<{no,tarih,invoice_no,kurus,fiyat_farki,neden,anlasilan,kesilen}>].
 * Yalnız EŞLEŞMİŞ debit'ler; para birimi EUR dışındakiler hesaba girmez (liste dışı). Hata → [] (fail soft).
 * @return array<int,array{kurus:int,diger_kurus:int,liste:list<array<string,mixed>>}>
 */
function siparis_debit_harita(PDO $pdo): array
{
    $harita = [];
    try {
        $notlar = siparis_debit_hepsi($pdo);
        if (!$notlar) return [];
        $esl = siparis_debit_esle($pdo, $notlar);
    } catch (Throwable $e) {
        return [];
    }
    foreach ($notlar as $n) {
        $e = $esl[(string)$n['no']] ?? null;
        if ($e === null || $e['durum'] !== 'eslesti' || $e['sid'] === null || strtoupper((string)($n['pb'] ?? '')) !== 'EUR') continue;
        $sid = (int)$e['sid'];
        $k = (int)$n['amount_kurus'];
        $ff = !empty($n['fiyat_farki']);
        $harita[$sid] ??= ['kurus' => 0, 'diger_kurus' => 0, 'liste' => []];
        if ($ff) $harita[$sid]['kurus'] += $k; else $harita[$sid]['diger_kurus'] += $k;
        $harita[$sid]['liste'][] = ['no' => (string)$n['no'], 'tarih' => (string)($n['tarih'] ?? ''), 'invoice_no' => (string)($n['invoice_no'] ?? ''), 'kurus' => $k, 'fiyat_farki' => $ff,
            'neden' => (string)($n['neden'] ?? ''), 'anlasilan' => isset($n['anlasilan']) ? (int)$n['anlasilan'] : null, 'kesilen' => isset($n['kesilen']) ? (int)$n['kesilen'] : null];
    }
    return $harita;
}

/**
 * Doğrulanmış debit note belgesini kaydeder (belge numarası başına bir kayıt). $kaynak = ['m'=>mail id,'h'=>hesap id,'p'=>parça].
 * Sonuç: alindi (≥1 yeni) | zaten (hepsi daha önce kayıtlı) | okunamadi (kalıcı: hiçbir sayfa okunamadı) | hata (geçici, yeniden denenir).
 * `odeme` anahtarı Ödeme sorgusu sonuç satırının taşıdığı bilgidir (debit=true ayırıcısı ile; para hareketi DEĞİL).
 * @return array{sonuc:string,mesaj:string,odeme:?array}
 */
function siparis_debit_isle(PDO $pdo, array $doc, array $kaynak, int $uid): array
{
    $r = static fn(string $s, string $m, ?array $o = null): array => ['sonuc' => $s, 'mesaj' => $m, 'odeme' => $o];
    if (($doc['ok'] ?? false) !== true || !($doc['notes'] ?? null)) return $r('okunamadi', (string)($doc['hata'] ?? 'Debit note okunamadı.'));
    $notlar = array_values(array_filter((array)$doc['notes'], 'is_array'));
    if (!$notlar) return $r('okunamadi', 'Debit note okunamadı.');
    if (!siparis_debit_kv_yazilabilir($pdo)) return $r('hata', 'Debit note kayıt alanı (hks_kv) kullanılamıyor — yeniden sorgulayın.');
    $yeni = 0; $zaten = 0; $kurus = 0; $ff = 0; $kayit = [];
    foreach ($notlar as $n) {
        $no = (string)($n['no'] ?? '');
        if (!siparis_debit_no_gecerli($no)) continue;
        $var = siparis_debit_oku($pdo, $no);
        $tutar = (int)($n['amount_kurus'] ?? 0);
        if ($var === null) {
            $kv = ['v' => 1, 'tarih' => (string)$n['date'], 'invoice_no' => (string)$n['invoice_no'], 'pb' => (string)$n['pb'], 'amount_kurus' => $tutar,
                'neden' => siparis_kirp((string)($n['reason'] ?? ''), 80), 'fiyat_farki' => !empty($n['fiyat_farki']),
                'anlasilan' => $n['agreed_price'] ?? null, 'kesilen' => $n['invoiced_price'] ?? null,
                'lkw' => array_values(array_map('strval', (array)($n['lkw'] ?? []))), 'lorry' => siparis_kirp((string)($n['lorry'] ?? ''), 60),
                'kalemler' => array_map(static fn(array $k): array => ['pos' => (int)$k['pos'], 'kod' => (string)$k['code'], 'ad' => (string)$k['desc'], 'pal' => (int)$k['pal'], 'koli' => (int)$k['colli'], 'kurus' => (int)$k['amount_kurus']], array_values((array)($n['items'] ?? []))),
                'mail_id' => (int)($kaynak['m'] ?? 0), 'parca' => (string)($kaynak['p'] ?? ''), 'hesap_id' => (int)($kaynak['h'] ?? 0), 'olusturuldu' => siparis_simdi()];
            if (!siparis_debit_kaydet($pdo, $no, $kv)) return $r('hata', 'Debit note kaydedilemedi (' . $no . ') — yeniden sorgulayınca tamamlanır.');
            $yeni++; $var = $kv;
        } else {
            $zaten++;
        }
        $kurus += $tutar; if (!empty($n['fiyat_farki'])) $ff++;
        $kayit[] = $var + ['no' => $no];
    }
    if ($yeni + $zaten === 0) return $r('okunamadi', 'Debit note kaydedilemedi.');
    $esl = siparis_debit_esle($pdo, $kayit);
    $eslesen = count(array_filter($esl, static fn(array $e): bool => $e['durum'] === 'eslesti'));
    $bilgi = ['debit' => true, 'adet' => $yeni + $zaten, 'yeni' => $yeni, 'zaten' => $zaten, 'kurus' => $kurus, 'pb' => 'EUR', 'fiyat_farki' => $ff,
        'eslesen' => $eslesen, 'eslesmeyen' => ($yeni + $zaten) - $eslesen, 'sayfa_hata' => (int)($doc['sayfa_hata'] ?? 0)];
    if ($yeni > 0) {
        siparis_audit('siparis_debit', 0, null, ['adet' => $yeni, 'zaten' => $zaten, 'eslesen' => $eslesen, 'sayfa_hata' => $bilgi['sayfa_hata'], 'mail_id' => (int)($kaynak['m'] ?? 0), 'parca' => (string)($kaynak['p'] ?? ''), 'kaynak' => 'mail'], $uid);
    }
    $ek = $bilgi['sayfa_hata'] > 0 ? ' · ' . $bilgi['sayfa_hata'] . ' sayfa okunamadı' : '';
    return $r($yeni > 0 ? 'alindi' : 'zaten', $yeni > 0 ? ($yeni . ' debit note eklendi' . ($zaten > 0 ? ', ' . $zaten . ' zaten vardı' : '') . $ek) : ('Debit note zaten kayıtlı (' . $zaten . ' belge)'), $bilgi);
}

/** hks_kv yazılabilir mi (tablo yoksa/yazma hatasında geçici hata dönmek için; sahte anahtar yazmaz — yalnız tablo varlığını yoklar). */
function siparis_debit_kv_yazilabilir(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM `' . siparis_evrak_mail_kv_tablo() . '` WHERE 1 = 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
