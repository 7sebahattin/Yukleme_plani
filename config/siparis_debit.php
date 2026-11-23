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
//  • v529 ELLE KESİNTİ (Ödemeler sayfası › "OGL kesintisi ekle", uç siparis_kesinti.php): PDF yüklenirse önce ayrıştırıcı denenir (aynı
//    siparis_debit_isle yolu, kaynak=elle); okunamazsa/PDF yoksa form alanlarıyla kayıt açılır. Elle kayıtta sevkiyat AÇIKÇA seçilebilir
//    (`sid` → eşleme yöntemi 'elle', fatura/LKW'den ÖNCE gelir). PDF, siparis_belgeler AÇMADAN storage/siparis/<32hex>.pdf olarak saklanır
//    (kv `dosya`), yalnız siparis_kesinti.php?dosya=<no> sunar. İptal SOFT: kv `iptal_at` (hesap/listeden düşer, mailden gelen aynı belge
//    no yeniden DİRİLMEZ — kayıt var = zaten). Mailden gelmiş eşleşmemiş kayıt da elle sevkiyata bağlanabilir (`sid`).
// =========================================================
declare(strict_types=1);
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/siparis_odeme_aviz.php';   // no_coz, eslesmeler, para biçimleyici, kv yardımcıları (siparis_inv_kv_*)
require_once __DIR__ . '/siparis_odeme.php';        // v529 elle kesinti: tutar çözücü + para birimleri (yalnız fonksiyon tanımlar)
require_once __DIR__ . '/siparis_pdf.php';          // v529 elle kesinti: yüklenen PDF'i ayrıştırma (siparis_pdf_isle, sipdf_debit_fiyat)

const SIPARIS_DEBIT_KV = 'siparis_debit_';
const SIPARIS_DEBIT_MAX = 5000;

function siparis_debit_anahtar(string $no): string { return SIPARIS_DEBIT_KV . $no; }

function siparis_debit_no_gecerli(string $no): bool { return (bool)preg_match('/^\d{4}-\d{4,8}$/', $no) || (bool)preg_match('/^[0-9A-Z][0-9A-Z\-]{2,29}$/', $no); }   // v529: elle girilen numara da

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
function siparis_debit_hepsi(PDO $pdo, bool $iptalDahil = false): array
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
            if (!$iptalDahil && !empty($j['iptal_at'])) continue;   // v529: elle iptal edilen kesinti hesaba/listeye girmez
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
    $elleRef = [];   // v529: elle bağlanan sevkiyatlar (silinmişse eşleşmez)
    $sidler = array_values(array_unique(array_filter(array_map(static fn(array $n): int => (int)($n['sid'] ?? 0), $notlar))));
    if ($sidler) {
        try {
            $st = $pdo->prepare('SELECT id, order_ref FROM siparis_sevkiyatlar WHERE deleted_at IS NULL AND id IN (' . implode(',', array_fill(0, count($sidler), '?')) . ')');
            $st->execute($sidler);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $elleRef[(int)$r['id']] = (string)$r['order_ref'];
        } catch (Throwable $e) {
            $elleRef = [];
        }
    }
    foreach ($notlar as $n) {
        $no = (string)$n['no'];
        $e = ['durum' => 'yok', 'sid' => null, 'yontem' => '', 'ref' => ''];
        $nc = siparis_odeme_aviz_no_coz((string)($n['invoice_no'] ?? ''));
        $iv = $nc['govde'] !== null ? ($inv[$nc['govde']] ?? null) : null;
        if ((int)($n['sid'] ?? 0) > 0 && ($elleRef[(int)$n['sid']] ?? null) !== null) {   // v529: elle seçilen sevkiyat her şeyden önce gelir
            $e = ['durum' => 'eslesti', 'sid' => (int)$n['sid'], 'yontem' => 'elle', 'ref' => (string)$elleRef[(int)$n['sid']]];
        } elseif ($iv !== null && ($iv['durum'] ?? '') === 'eslesti' && !empty($iv['v'][0]['id'])) {
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
                'mail_id' => (int)($kaynak['m'] ?? 0), 'parca' => (string)($kaynak['p'] ?? ''), 'hesap_id' => (int)($kaynak['h'] ?? 0), 'olusturuldu' => siparis_simdi(),
                'kaynak' => (string)($kaynak['tur'] ?? 'mail'), 'audit_report' => siparis_kirp((string)($n['audit_report'] ?? ''), 30), 'hizmet' => siparis_kirp((string)($n['service_date'] ?? ''), 12)]
                + (array)($kaynak['ek'] ?? []);   // v529: elle yüklemede dosya / sevkiyat / yükleyen
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
        siparis_audit('siparis_debit', 0, null, ['adet' => $yeni, 'zaten' => $zaten, 'eslesen' => $eslesen, 'sayfa_hata' => $bilgi['sayfa_hata'], 'mail_id' => (int)($kaynak['m'] ?? 0), 'parca' => (string)($kaynak['p'] ?? ''), 'kaynak' => (string)($kaynak['tur'] ?? 'mail')], $uid);
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

// ── v529: ELLE KESİNTİ (Ödemeler › "OGL kesintisi ekle"; uç siparis_kesinti.php) ─────────────────────────────

/** Elle kesinti nedenleri (beyaz liste): kod → etiket. Yalnız `fiyat` hesaba (kalan farka) girer. */
function siparis_debit_nedenleri(): array
{
    return ['fiyat' => 'Fiyat farkı (WRONG PRICE)', 'kalite' => 'Kalite / analiz (non-compliance)', 'diger' => 'Diğer kesinti'];
}

/** Elle girilen belge no: OGL biçimi (2028-107735) ya da 3–30 karakter harf/rakam/tire (büyük harfe çevrilir); geçersiz → null. */
function siparis_debit_no_coz(string $no): ?string
{
    $no = strtoupper(trim($no));
    return preg_match('/^[0-9A-Z][0-9A-Z\-]{2,29}$/', $no) ? $no : null;
}

/** Yüklenen PDF'i siparis_belgeler AÇMADAN depoya yazar → ['ok', 'disk' (32hex.pdf), 'ad' (orijinal ad), 'hata']. Yalnız PDF. */
function siparis_debit_dosya_sakla(array $file, ?callable $mover = null): array
{
    $d = siparis_dosya_dogrula($file);
    if (!$d['ok']) return ['ok' => false, 'hata' => (string)($d['hata'] ?? 'Dosya geçersiz.')];
    if (($d['ext'] ?? '') !== 'pdf') return ['ok' => false, 'hata' => 'Kesinti belgesi yalnız PDF olabilir.'];
    if (!siparis_depo_hazirla()) return ['ok' => false, 'hata' => 'Belge klasörü hazırlanamadı.'];
    $ad = bin2hex(random_bytes(16)) . '.pdf';
    $hedef = rtrim(SIPARIS_DEPO_DIR, '/') . '/' . $ad;
    $mover = $mover ?? 'move_uploaded_file';
    if (!$mover((string)$file['tmp_name'], $hedef) || !is_file($hedef)) return ['ok' => false, 'hata' => 'Dosya kaydedilemedi.'];
    @chmod($hedef, 0640);
    $orj = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', (string)($file['name'] ?? 'kesinti.pdf'))));
    return ['ok' => true, 'disk' => $ad, 'ad' => siparis_kirp($orj !== '' ? $orj : 'kesinti.pdf', 120)];
}

/** Sevkiyat id geçerli mi (silinmemiş + bu kullanıcıya görünür) → order_ref ya da null. */
function siparis_debit_sevkiyat_ref(PDO $pdo, int $sid): ?string
{
    if ($sid <= 0) return null;
    try {
        $st = $pdo->prepare('SELECT id, order_ref, depo FROM siparis_sevkiyatlar WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$sid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    return ($r && (!function_exists('siparis_gorunur') || siparis_gorunur($r))) ? (string)$r['order_ref'] : null;
}

/**
 * Elle kesinti ekle. $in: no, tarih (Y-m-d|gg.aa.yyyy), tutar ("1.234,56"), pb, neden (fiyat|kalite|diger), aciklama, invoice_no, sid, anlasilan, kesilen.
 * $file: isteğe bağlı PDF ($_FILES öğesi). PDF OGL debit note olarak okunursa (bir ya da çok belge) form alanları YOK SAYILIR, belgeler
 * siparis_debit_isle ile kaydedilir (seçilen sevkiyat yalnız tek belgeli PDF'e uygulanır). Okunamazsa form alanları zorunludur.
 * $parse: test kancası (dosya yolu → siparis_pdf_isle sonucu). @return array{ok:bool,mesaj:string,no?:list<string>}
 */
function siparis_debit_elle_ekle(PDO $pdo, array $in, ?array $file, int $uid, ?callable $mover = null, ?callable $parse = null): array
{
    if (!siparis_debit_kv_yazilabilir($pdo)) return ['ok' => false, 'mesaj' => 'Kesinti kayıt alanı (hks_kv) kullanılamıyor.'];
    $sid = (int)($in['sid'] ?? 0);
    $sidRef = $sid > 0 ? siparis_debit_sevkiyat_ref($pdo, $sid) : null;
    if ($sid > 0 && $sidRef === null) return ['ok' => false, 'mesaj' => 'Seçilen sevkiyat bulunamadı.'];
    $dosya = null;
    $pdfVar = $file !== null && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($pdfVar) {
        $tmp = (string)($file['tmp_name'] ?? '');
        $doc = null;
        if ($tmp !== '' && is_file($tmp)) {
            try { $doc = $parse !== null ? $parse($tmp) : siparis_pdf_isle($tmp); } catch (Throwable $e) { $doc = null; }
        }
        $dosya = siparis_debit_dosya_sakla($file, $mover);
        if (!$dosya['ok']) return ['ok' => false, 'mesaj' => (string)$dosya['hata']];
        if (is_array($doc) && ($doc['doc_type'] ?? '') === 'debit_note' && ($doc['ok'] ?? false) === true) {
            $tek = count((array)$doc['notes']) === 1;
            $ek = ['dosya' => $dosya['disk'], 'dosya_ad' => $dosya['ad'], 'yukleyen' => $uid] + ($tek && $sid > 0 ? ['sid' => $sid] : []);
            $x = siparis_debit_isle($pdo, $doc, ['tur' => 'elle', 'ek' => $ek], $uid);
            // Belge zaten kayıtlıysa (ör. mailden geldi) ama dosyası yoksa: yüklenen PDF eklenir; seçilen sevkiyat boş eşlemeye yazılır
            $nolar = [];
            foreach ((array)$doc['notes'] as $n) {
                $no = (string)($n['no'] ?? ''); $nolar[] = $no;
                $kv = siparis_debit_oku($pdo, $no);
                if ($kv === null) continue;
                $degis = false;
                if (empty($kv['dosya'])) { $kv['dosya'] = $dosya['disk']; $kv['dosya_ad'] = $dosya['ad']; $degis = true; }
                if ($tek && $sid > 0 && (int)($kv['sid'] ?? 0) !== $sid) { $kv['sid'] = $sid; $degis = true; }
                if ($degis) siparis_debit_kaydet($pdo, $no, $kv);
            }
            if (!in_array($x['sonuc'], ['alindi', 'zaten'], true)) return ['ok' => false, 'mesaj' => (string)$x['mesaj']];
            $esl = (int)($x['odeme']['eslesen'] ?? 0);
            return ['ok' => true, 'no' => $nolar, 'mesaj' => 'PDF okundu: ' . $x['mesaj'] . ' · ' . $esl . ' / ' . count($nolar) . ' sevkiyata eşleşti.'
                . ((int)($x['odeme']['sayfa_hata'] ?? 0) > 0 ? ' Okunamayan sayfaları formdan elle girin.' : '')];
        }
    }
    // Form alanlarıyla kayıt
    $hata = static function (string $m) use ($dosya): array {
        if ($dosya !== null && !empty($dosya['ok'])) @unlink(rtrim(SIPARIS_DEPO_DIR, '/') . '/' . $dosya['disk']);
        return ['ok' => false, 'mesaj' => $m];
    };
    $no = siparis_debit_no_coz((string)($in['no'] ?? ''));
    if ($no === null) return $hata($pdfVar ? 'PDF bir OGL debit note olarak okunamadı — belge no, tarih ve tutarı elle girin.' : 'Belge no geçersiz (ör. 2028-107735).');
    if (siparis_debit_oku($pdo, $no) !== null) return $hata('Bu belge no zaten kayıtlı (' . $no . ').');
    $tarih = siparis_tarih_coz((string)($in['tarih'] ?? ''));
    if ($tarih === null) return $hata('Belge tarihi geçersiz.');
    if ($tarih > date('Y-m-d')) return $hata('Belge tarihi gelecekte olamaz.');
    $tutar = siparis_odeme_tutar_coz((string)($in['tutar'] ?? ''));
    if ($tutar === null || $tutar <= 0) return $hata('Tutar geçersiz (ör. 300,00).');
    $pb = strtoupper(trim((string)($in['pb'] ?? 'EUR')));
    if (!in_array($pb, siparis_odeme_para_birimleri(), true)) return $hata('Para birimi geçersiz.');
    $neden = (string)($in['neden'] ?? '');
    if (!isset(siparis_debit_nedenleri()[$neden])) return $hata('Kesinti nedeni seçin.');
    $fatura = strtoupper(trim((string)($in['invoice_no'] ?? '')));
    if ($fatura !== '' && !preg_match('/^IHR\d{13}(?:D[IVX]{1,6})?$/', $fatura)) return $hata('Fatura no IHR + 13 rakam biçiminde olmalı (ör. IHR2026000000340DI) ya da boş bırakılmalı.');
    $anl = sipdf_debit_fiyat(str_replace('.', ',', trim((string)($in['anlasilan'] ?? ''))));
    $kes = sipdf_debit_fiyat(str_replace('.', ',', trim((string)($in['kesilen'] ?? ''))));
    $ff = $neden === 'fiyat' && $pb === 'EUR';
    if ($neden === 'fiyat' && $anl !== null && $kes !== null && $kes <= $anl) return $hata('Fiyat farkında kesilen fiyat anlaşılandan yüksek olmalı.');
    $aciklama = siparis_inv_metin($in['aciklama'] ?? '', 120);
    $kv = ['v' => 1, 'tarih' => $tarih, 'invoice_no' => $fatura, 'pb' => $pb, 'amount_kurus' => $tutar,
        'neden' => siparis_kirp($aciklama !== '' ? $aciklama : siparis_debit_nedenleri()[$neden], 80), 'neden_kod' => $neden, 'fiyat_farki' => $ff,
        'anlasilan' => $anl, 'kesilen' => $kes, 'lkw' => [], 'lorry' => '', 'kalemler' => [],
        'mail_id' => 0, 'parca' => '', 'hesap_id' => 0, 'olusturuldu' => siparis_simdi(), 'kaynak' => 'elle', 'yukleyen' => $uid]
        + ($sid > 0 ? ['sid' => $sid] : [])
        + ($dosya !== null && !empty($dosya['ok']) ? ['dosya' => $dosya['disk'], 'dosya_ad' => $dosya['ad']] : []);
    if (!siparis_debit_kaydet($pdo, $no, $kv)) return $hata('Kesinti kaydedilemedi.');
    siparis_audit('siparis_debit', 0, null, ['adet' => 1, 'kaynak' => 'elle', 'neden' => $neden, 'sevkiyat' => $sid > 0 ? 1 : 0, 'dosya' => isset($kv['dosya']) ? 1 : 0], $uid);
    return ['ok' => true, 'no' => [$no], 'mesaj' => 'Kesinti kaydedildi (' . $no . ' · ' . siparis_odeme_aviz_para($tutar) . ' ' . $pb . ')'
        . ($ff ? ($sid > 0 || $fatura !== '' ? '' : ' — sevkiyat seçilmediği için kalan farka henüz girmez.') : ' — fiyat farkı olmadığı için kalan farkı değiştirmez.')];
}

/** Kesintiyi sevkiyata bağla / bağı kaldır (sid=0). Mailden gelen eşleşmemiş kayıtlar için de. */
function siparis_debit_bagla(PDO $pdo, string $no, int $sid, int $uid): array
{
    $kv = siparis_debit_oku($pdo, $no);
    if ($kv === null || !empty($kv['iptal_at'])) return ['ok' => false, 'mesaj' => 'Kesinti bulunamadı.'];
    if ($sid > 0 && siparis_debit_sevkiyat_ref($pdo, $sid) === null) return ['ok' => false, 'mesaj' => 'Seçilen sevkiyat bulunamadı.'];
    if ($sid > 0) $kv['sid'] = $sid; else unset($kv['sid']);
    if (!siparis_debit_kaydet($pdo, $no, $kv)) return ['ok' => false, 'mesaj' => 'Kaydedilemedi.'];
    siparis_audit('siparis_debit_bagla', 0, null, ['no' => $no, 'sevkiyat' => $sid], $uid);
    return ['ok' => true, 'mesaj' => $sid > 0 ? 'Kesinti sevkiyata bağlandı (' . $no . ').' : 'Sevkiyat bağı kaldırıldı (' . $no . ').'];
}

/** Kesintiyi iptal et (soft; gerekçe zorunlu). Kayıt silinmez: mailden aynı belge no yeniden gelirse "zaten" sayılır, dirilmez. */
function siparis_debit_iptal(PDO $pdo, string $no, string $neden, int $uid): array
{
    $neden = siparis_inv_metin($neden, 255);
    if ($neden === '') return ['ok' => false, 'mesaj' => 'İptal gerekçesi zorunlu.'];
    $kv = siparis_debit_oku($pdo, $no);
    if ($kv === null) return ['ok' => false, 'mesaj' => 'Kesinti bulunamadı.'];
    if (!empty($kv['iptal_at'])) return ['ok' => false, 'mesaj' => 'Kesinti zaten iptal edilmiş.'];
    $kv['iptal_at'] = siparis_simdi(); $kv['iptal_by'] = $uid; $kv['iptal_neden'] = $neden;
    if (!siparis_debit_kaydet($pdo, $no, $kv)) return ['ok' => false, 'mesaj' => 'Kaydedilemedi.'];
    siparis_audit('siparis_debit_iptal', 0, null, ['no' => $no], $uid);
    return ['ok' => true, 'mesaj' => 'Kesinti iptal edildi (' . $no . ').'];
}

/** Kesintinin PDF yolu (kv `dosya`, siparis_dosya_yolu kuralı) ya da null. */
function siparis_debit_dosya_yolu(PDO $pdo, string $no): ?array
{
    $kv = siparis_debit_oku($pdo, $no);
    if ($kv === null || empty($kv['dosya'])) return null;
    $yol = siparis_dosya_yolu(['disk_adi' => (string)$kv['dosya']]);
    return $yol === null ? null : ['yol' => $yol, 'ad' => (string)($kv['dosya_ad'] ?? ($no . '.pdf'))];
}

/** Elle bağlama seçicisi için son sevkiyatlar (görünür depo, silinmemiş, iptal hariç; yükleme tarihi azalan). Hata → []. @return list<array{id:int,ref:string,tarih:string}> */
function siparis_debit_sevkiyat_secenekleri(PDO $pdo, int $en = 400): array
{
    try {
        [$dsql, $dprm] = depo_sql_column('s.depo', ':kdm');
        $st = $pdo->prepare("SELECT s.id, s.order_ref, s.yukleme_tarihi FROM siparis_sevkiyatlar s WHERE s.deleted_at IS NULL AND s.durum <> 'iptal'" . $dsql
            . ' ORDER BY s.yukleme_tarihi IS NULL, s.yukleme_tarihi DESC, s.id DESC LIMIT ' . max(1, min(2000, $en)));
        $st->execute($dprm);
        return array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'ref' => (string)$r['order_ref'], 'tarih' => (string)($r['yukleme_tarihi'] ?? '')], $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        return [];
    }
}
