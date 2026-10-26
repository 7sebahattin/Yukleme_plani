<?php
// =========================================================
// config/siparis_invoice.php — OGL Invoice üretici (v442)
//
// OGL (UK alıcı) formatlı İngilizce invoice: sevkiyatın GÜNCEL PKL'inden palet / koli / brüt / net / kalibre
// özeti + kullanıcının girdiği invoice no, tarih, birim fiyat (€/kg), iskonto % → dompdf ile A4 dikey PDF.
// Üretilen PDF siparis_belgeler'e tur 'invoice' (kaynak 'uretim') olarak yazılır ve sevkiyata bağlanır
// (PKL üretiminin bağlama / eski sürümü gecerli=0 yapma mantığı). Yüklenen Türk e-faturası tur 'efatura'.
//
// Şemasız (migration YOK): ayar hks_kv 'siparis_invoice_ayar', girdi hks_kv 'siparis_invoice_<sevkiyat_id>'.
// Sayfa / çıktı basmaz; depo + yetki kapıları SAYFADA (siparis_invoice.php) — buradaki fonksiyonlar salt DB + dosya.
//
// ⚠ MENŞE BEYANI (origin declaration) HER invoice'ta koşulsuz basılır; REX no boşsa "______" yazılır.
//   Şablondan kaldırma / koşula bağlama (OGL AsyaDoc011'de bunun eksikliği yüzünden uyardı).
// ⚠ Hesap TEK yerde siparis_inv_hesapla() (sunucu her zaman yeniden hesaplar; JS yalnız önizleme).
// ⚠ IBAN / REX no kodda YOK (ayardan gelir) ve audit'e YAZILMAZ.
// ⚠ dompdf: hesap_pdf.php kuralları — isRemoteEnabled=false, isPhpEnabled=false, DejaVu, text-transform YOK.
// =========================================================
declare(strict_types=1);

require_once __DIR__ . '/siparis_core.php';
require_once __DIR__ . '/siparis_pkl.php';

const SIPARIS_INV_AYAR_KV = 'siparis_invoice_ayar';
const SIPARIS_INV_GIRDI_KV_ON = 'siparis_invoice_';
const SIPARIS_INV_HS_KODU = '8109075';
const SIPARIS_INV_MARKA = 'ASYA FRESH';
const SIPARIS_INV_TESLIM_VARSAYILAN = 'INCOTERMS 2020 DAP';

// ── AYAR (hks_kv) ───────────────────────────────────────────────────────

/** @return array{rex_no:string,banka_ad:string,banka_adres:string,sube:string,swift:string,iban_eur:string,teslim:string,iskonto_varsayilan:float} */
function siparis_inv_ayar_varsayilan(): array
{
    return ['rex_no' => '', 'banka_ad' => '', 'banka_adres' => '', 'sube' => '', 'swift' => '', 'iban_eur' => '',
            'teslim' => SIPARIS_INV_TESLIM_VARSAYILAN, 'iskonto_varsayilan' => 0.0];
}

/** hks_kv JSON okuması (tablo yok / bozuk JSON / hata → null; fail soft). */
function siparis_inv_kv_oku(PDO $pdo, string $anahtar): ?array
{
    try {
        $st = $pdo->prepare('SELECT deger FROM `' . siparis_evrak_mail_kv_tablo() . '` WHERE anahtar = ?');
        $st->execute([$anahtar]);
        $j = json_decode((string)($st->fetchColumn() ?: ''), true);
    } catch (Throwable $e) {
        return null;
    }
    return is_array($j) ? $j : null;
}

function siparis_inv_kv_yaz(PDO $pdo, string $anahtar, array $veri): bool
{
    try {
        $pdo->prepare('REPLACE INTO `' . siparis_evrak_mail_kv_tablo() . '` (anahtar, deger) VALUES (?, ?)')->execute([$anahtar, siparis_json($veri)]);
        return true;
    } catch (Throwable $e) {
        error_log('[siparis_invoice] hks_kv yazılamadı: ' . get_class($e));
        return false;
    }
}

/** Tek satırlık metin: denetim karakterleri atılır, boşluk tekler, $n karaktere kırpılır. */
function siparis_inv_metin(mixed $v, int $n): string
{
    $s = trim((string)preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', (string)(is_scalar($v) ? $v : '')));
    return (string)siparis_kirp($s, $n);
}

/** Ondalık sayı metni → ?float (yalnız rakam . , ve baştaki -; ambiguous binlik çözümü $tutar ile). */
function siparis_inv_ondalik_coz(mixed $v, bool $tutar): ?float
{
    if (is_int($v) || is_float($v)) return is_finite((float)$v) ? (float)$v : null;
    $s = trim((string)preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', str_ireplace(['€', 'EUR'], '', (string)(is_scalar($v) ? $v : ''))));
    if ($s === '' || !preg_match('/^[0-9.,]+$/', $s) || !preg_match('/[0-9]/', $s)) return null;
    $nd = substr_count($s, '.'); $nc = substr_count($s, ',');
    if ($nd === 0 && $nc === 0) return (float)$s;
    if ($nd > 0 && $nc > 0) {                                 // son ayraç ondalık, öteki binlik
        $pos = max((int)strrpos($s, '.'), (int)strrpos($s, ','));
        $dec = $s[$pos]; $bin = $dec === '.' ? ',' : '.';
        $ic = substr($s, 0, $pos); $kuyruk = substr($s, $pos + 1);
        if (str_contains($ic, $dec) || ($kuyruk !== '' && !ctype_digit($kuyruk))) return null;
        $g = explode($bin, $ic);
        if (!preg_match('/^\d{1,3}$/', (string)array_shift($g))) return null;
        foreach ($g as $x) if (!preg_match('/^\d{3}$/', $x)) return null;
        return (float)(str_replace($bin, '', $ic) . '.' . ($kuyruk === '' ? '0' : $kuyruk));
    }
    $ayrac = $nd > 0 ? '.' : ',';
    $p = explode($ayrac, $s);
    if (count($p) > 2) {                                       // yalnız binlik gruplanabilir ("1.234.567")
        $g = $p;
        if (!preg_match('/^\d{1,3}$/', (string)array_shift($g))) return null;
        foreach ($g as $x) if (!preg_match('/^\d{3}$/', $x)) return null;
        return $tutar ? (float)implode('', $p) : null;
    }
    [$a, $b] = $p;
    if ($tutar && strlen($b) === 3 && preg_match('/^[1-9]\d{0,2}$/', $a)) return (float)($a . $b);   // "12.500" tutarda binlik
    return (float)(($a === '' ? '0' : $a) . '.' . ($b === '' ? '0' : $b));                            // birim fiyatta HEP ondalık
}

/** Ayar okuması — bozuk/eksik değer varsayılana düşer; TEK yerden (kaydet + sayfalar). */
function siparis_inv_ayar_oku(PDO $pdo): array
{
    $v = siparis_inv_ayar_varsayilan();
    $j = siparis_inv_kv_oku($pdo, SIPARIS_INV_AYAR_KV);
    if ($j === null) return $v;
    foreach (['rex_no' => 30, 'banka_ad' => 100, 'banka_adres' => 200, 'sube' => 100, 'swift' => 11, 'iban_eur' => 34, 'teslim' => 60] as $k => $n) {
        if (isset($j[$k]) && is_scalar($j[$k])) $v[$k] = siparis_inv_metin($j[$k], $n);
    }
    if ($v['teslim'] === '') $v['teslim'] = SIPARIS_INV_TESLIM_VARSAYILAN;
    $isk = isset($j['iskonto_varsayilan']) ? siparis_inv_ondalik_coz($j['iskonto_varsayilan'], false) : null;
    $v['iskonto_varsayilan'] = $isk !== null && $isk >= 0 && $isk <= 100 ? round($isk, 2) : 0.0;
    return $v;
}

/** Ayarda boş olan zorunlu alanların etiketleri (REX no + banka bilgileri). @return list<string> */
function siparis_inv_ayar_eksikler(array $ayar): array
{
    $e = [];
    foreach (['rex_no' => 'REX no', 'banka_ad' => 'Banka adı', 'banka_adres' => 'Banka adresi', 'sube' => 'Şube', 'swift' => 'SWIFT', 'iban_eur' => 'IBAN (EUR)'] as $k => $et) {
        if (trim((string)($ayar[$k] ?? '')) === '') $e[] = $et;
    }
    return $e;
}

/**
 * Ayarı doğrular ve yazar. Gönderilmeyen anahtar mevcut değerini korur. IBAN boşluksuz BÜYÜK harf, SWIFT 8/11, REX alfasayısal ≤ 30,
 * iskonto 0–100. Audit yalnız DEĞİŞEN alan ADLARINI yazar (IBAN / REX / banka değeri YAZILMAZ).
 * @return array{ok:bool,hata?:string,ayar:array}
 */
function siparis_inv_ayar_kaydet(PDO $pdo, array $girdi, int $uid): array
{
    $eski = siparis_inv_ayar_oku($pdo);
    $yeni = $eski;
    $hata = function (string $m) use ($eski): array { return ['ok' => false, 'hata' => $m, 'ayar' => $eski]; };
    if (!siparis_evrak_mail_kv_hazir($pdo)) return $hata('Ayar tablosu (hks_kv) yok; invoice ayarları kaydedilemedi.');
    foreach ($girdi as $v) if (!is_scalar($v) && $v !== null) return $hata('Ayar değerleri metin olmalıdır.');
    foreach (['banka_ad' => ['Banka adı', 100], 'banka_adres' => ['Banka adresi', 200], 'sube' => ['Şube', 100], 'teslim' => ['Teslim şekli', 60]] as $k => [$et, $n]) {
        if (!array_key_exists($k, $girdi)) continue;
        $s = siparis_inv_metin($girdi[$k], 1000);
        if (mb_strlen($s) > $n) return $hata($et . ' en çok ' . $n . ' karakter olabilir.');
        $yeni[$k] = $s;
    }
    if ($yeni['teslim'] === '') $yeni['teslim'] = SIPARIS_INV_TESLIM_VARSAYILAN;
    if (array_key_exists('iban_eur', $girdi)) {
        $s = strtoupper((string)preg_replace('/[\s\-]+/u', '', (string)$girdi['iban_eur']));
        if ($s !== '' && !preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $s)) return $hata('IBAN geçersiz (ülke kodu + 2 kontrol hanesi + hesap no).');
        $yeni['iban_eur'] = $s;
    }
    if (array_key_exists('swift', $girdi)) {
        $s = strtoupper((string)preg_replace('/\s+/u', '', (string)$girdi['swift']));
        if ($s !== '' && !preg_match('/^[A-Z0-9]{8}([A-Z0-9]{3})?$/', $s)) return $hata('SWIFT kodu 8 ya da 11 harf/rakam olmalıdır.');
        $yeni['swift'] = $s;
    }
    if (array_key_exists('rex_no', $girdi)) {
        $s = strtoupper((string)preg_replace('/\s+/u', '', (string)$girdi['rex_no']));
        if ($s !== '' && !preg_match('#^[A-Z0-9/\-]{1,30}$#', $s)) return $hata('REX no yalnız harf, rakam, "-" ve "/" içerebilir (en çok 30 karakter).');
        $yeni['rex_no'] = $s;
    }
    if (array_key_exists('iskonto_varsayilan', $girdi)) {
        $raw = trim((string)$girdi['iskonto_varsayilan']);
        $s = $raw === '' ? 0.0 : siparis_inv_ondalik_coz($raw, false);
        if ($s === null || $s < 0 || $s > 100) return $hata('Varsayılan iskonto 0 ile 100 arasında olmalıdır.');
        $yeni['iskonto_varsayilan'] = round($s, 2);
    }
    if (!siparis_inv_kv_yaz($pdo, SIPARIS_INV_AYAR_KV, $yeni)) return $hata('Ayar kaydedilemedi (hks_kv tablosu yazılamadı).');
    $degisen = [];
    foreach ($yeni as $k => $v) if ($v !== ($eski[$k] ?? null) && !(is_float($v) && abs($v - (float)($eski[$k] ?? 0)) < 1e-9)) $degisen[] = $k;
    if ($degisen) siparis_audit('siparis_invoice_ayar', null, null, ['degisen_alanlar' => $degisen], $uid);
    return ['ok' => true, 'ayar' => $yeni];
}

// ── GİRDİ + HESAP (saf) ─────────────────────────────────────────────────

/** Birim fiyat kanonik metni: ≤ 4 ondalık, en az 2 ("1.37", "2.00", "1.3750"). */
function siparis_inv_fiyat_metin(float $f): string
{
    $s = rtrim(number_format($f, 4, '.', ''), '0');
    $nokta = strpos($s, '.');
    return $nokta === false ? $s . '.00' : ($nokta !== false && strlen($s) - $nokta - 1 < 2 ? str_pad($s, $nokta + 3, '0') : $s);
}

/**
 * Formdan gelen girdiyi doğrular/normalleştirir. Tarih Y-m-d (gg.aa.yyyy de kabul); birim fiyat 0'dan büyük, < 1000, ≤ 4 ondalık
 * (TEK ayraç HEP ondalık — "1.370" 1,37 değil 1.370; çift ayraçta son ayraç ondalık); iskonto 0–100 (boş = 0); e-fatura toplamı
 * isteğe bağlı (tutar kuralı: "12.500" binlik). net_kg / brut_kg (v442b) isteğe bağlı TAM SAYI kg override'ıdır (boş = PKL toplamı;
 * "18.200" = 18200); PKL toplamı e-fatura / beyannameden farklıysa kullanıcı fatura değerini girer. Hatalar alan anahtarlı.
 * @return array{ok:bool,veri:array{no:string,tarih:string,fiyat:string,iskonto:float,efatura_toplam:string,net_kg:int|string,brut_kg:int|string},hata:array<string,string>}
 */
function siparis_inv_girdi_temizle(array $post): array
{
    foreach ($post as $k => $v) if (!is_scalar($v) && $v !== null) $post[$k] = '';          // dizi / nesne değerleri boş sayılır (uyarı üretmez)
    $hata = [];
    $no = siparis_inv_metin($post['no'] ?? '', 60);
    if ($no === '') $hata['no'] = 'Invoice numarası gerekli.';
    elseif (!preg_match('#^[A-Za-z0-9._/\- ]{1,40}$#', $no)) $hata['no'] = 'Invoice numarası yalnız harf, rakam ve . _ / - içerebilir (en çok 40 karakter).';

    $tarih = siparis_tarih_coz(is_scalar($post['tarih'] ?? null) ? trim((string)$post['tarih']) : null);
    if ($tarih === null) $hata['tarih'] = 'Geçerli bir invoice tarihi girin.';

    $fiyatS = '';
    $f = siparis_inv_ondalik_coz($post['fiyat'] ?? '', false);
    if (trim((string)($post['fiyat'] ?? '')) === '' || $f === null) $hata['fiyat'] = 'Birim fiyat (€/kg) gerekli — örn. 1,37.';
    elseif ($f <= 0 || $f >= 1000) $hata['fiyat'] = 'Birim fiyat 0 ile 1000 arasında olmalıdır.';
    elseif (abs($f * 10000 - round($f * 10000)) > 1e-6) $hata['fiyat'] = 'Birim fiyat en çok 4 ondalık içerebilir.';
    else $fiyatS = siparis_inv_fiyat_metin($f);

    $isk = 0.0;
    if (trim((string)($post['iskonto'] ?? '')) !== '') {
        $x = siparis_inv_ondalik_coz($post['iskonto'], false);
        if ($x === null || $x < 0 || $x > 100) $hata['iskonto'] = 'İskonto % 0 ile 100 arasında olmalıdır.';
        elseif (abs($x * 100 - round($x * 100)) > 1e-6) $hata['iskonto'] = 'İskonto en çok 2 ondalık içerebilir.';
        else $isk = round($x, 2);
    }

    $ef = '';
    if (trim((string)($post['efatura_toplam'] ?? '')) !== '') {
        $x = siparis_inv_ondalik_coz($post['efatura_toplam'], true);
        if ($x === null || $x < 0 || $x >= 1e10) $hata['efatura_toplam'] = 'E-fatura toplamı geçersiz.';
        else $ef = number_format($x, 2, '.', '');
    }
    $kg = ['net_kg' => '', 'brut_kg' => ''];
    foreach (['net_kg' => 'Net ağırlık', 'brut_kg' => 'Brüt ağırlık'] as $a => $et) {
        if (!isset($post[$a]) || trim((string)(is_scalar($post[$a]) ? $post[$a] : '')) === '') continue;
        $x = siparis_inv_ondalik_coz($post[$a], true);
        if ($x === null || $x < 1 || $x > 99999999 || abs($x - round($x)) > 1e-9) $hata[$a] = $et . ' tam sayı kg olmalıdır (boş bırakırsanız PKL toplamı kullanılır).';
        else $kg[$a] = (int)round($x);
    }
    return ['ok' => $hata === [], 'veri' => ['no' => $no, 'tarih' => $tarih ?? '', 'fiyat' => $fiyatS, 'iskonto' => $isk, 'efatura_toplam' => $ef] + $kg, 'hata' => $hata];
}

/** Kullanılan net kg: girdideki override (varsa) yoksa özetteki (PKL) net. */
function siparis_inv_net_kullanilan(array $veri, array $ozet): float
{
    $o = $veri['net_kg'] ?? '';
    return round($o !== '' && $o !== null && is_numeric($o) ? (float)$o : (float)($ozet['net_kg'] ?? 0));   // TAM sayı kg (PDF = hesap = kayıt)
}

/**
 * Özeti girdideki net / brüt override'ıyla efektif hâle getirir (HTML + hesap bunu kullanır). Aslî PKL değerleri net_pkl_kg / brut_pkl_kg'de kalır.
 * Idempotent: ikinci çağrı aynı sonucu verir.
 */
function siparis_inv_ozet_etkin(array $veri, array $ozet): array
{
    $ozet['net_pkl_kg'] = round((float)($ozet['net_pkl_kg'] ?? $ozet['net_kg'] ?? 0));          // v442c: tam sayı kg'a yuvarlanır;
    $ozet['brut_pkl_kg'] = round((float)($ozet['brut_pkl_kg'] ?? $ozet['brut_kg'] ?? 0));      // hesap, PDF ve kayıt AYNI değeri kullanır
    $ozet['net_kg'] = siparis_inv_net_kullanilan($veri, ['net_kg' => $ozet['net_pkl_kg']]);
    $b = $veri['brut_kg'] ?? '';
    $ozet['brut_kg'] = $b !== '' && $b !== null && is_numeric($b) ? round((float)$b) : $ozet['brut_pkl_kg'];
    return $ozet;
}

/**
 * Tutar / iskonto / toplam — TEK hesap yeri. Tamsayı aritmetiği: net (g) × fiyat (1e-4) → kuruşa yarım yukarı; iskonto = tutar × % (kuruş,
 * yarım yukarı); toplam = tutar − iskonto. Örnek: 18.200 kg × 1,37 = 24.934,00; %4 = 997,36; toplam 23.936,64.
 * @return array{tutar_kurus:int,iskonto_kurus:int,toplam_kurus:int,tutar:float,iskonto:float,toplam:float}
 */
function siparis_inv_hesapla(array $veri, array $ozet): array
{
    $netMg = (int)round(siparis_inv_net_kullanilan($veri, $ozet) * 1000);           // gram (girdideki net_kg override'ı varsa o)
    $fiyat = siparis_inv_ondalik_coz($veri['fiyat'] ?? 0, false) ?? 0.0;
    $fiyat1e4 = (int)round($fiyat * 10000);
    $tutarK = intdiv($netMg * $fiyat1e4 + 50000, 100000);                           // gram × (€/kg ×1e4) = €×1e7 → kuruş (÷1e5)
    $isk100 = (int)round(((float)($veri['iskonto'] ?? 0)) * 100);                   // % × 100
    $iskK = intdiv($tutarK * $isk100 + 5000, 10000);
    $topK = $tutarK - $iskK;
    return ['tutar_kurus' => $tutarK, 'iskonto_kurus' => $iskK, 'toplam_kurus' => $topK,
            'tutar' => $tutarK / 100.0, 'iskonto' => $iskK / 100.0, 'toplam' => $topK / 100.0];
}

// ── PKL'DEN ÖZET ────────────────────────────────────────────────────────

/** Kalibre listesinden açıklama aralığı: ["12","10"] → "10-12 PC.", tek → "10 PC."; sayısal değilse olduğu gibi birleştirilir. */
function siparis_inv_kalibre_aralik(array $kalibreler): string
{
    $l = [];
    foreach ($kalibreler as $k) { $k = trim((string)$k); if ($k !== '') $l[$k] = true; }
    $l = array_map('strval', array_keys($l));
    if (!$l) return '';
    $sayisal = !array_filter($l, fn($k) => !preg_match('/^\d+(?:[.,]\d+)?$/', $k));
    usort($l, fn($a, $b) => $sayisal ? (float)str_replace(',', '.', $a) <=> (float)str_replace(',', '.', $b) : strnatcasecmp($a, $b));
    return (count($l) === 1 ? $l[0] : $l[0] . '-' . $l[count($l) - 1]) . ' PC.';
}

/** "POMEGRANATE" → "FRESH POMEGRANATE" (zaten FRESH ile başlıyorsa dokunulmaz); boş → "". */
function siparis_inv_urun_ad(string $urun): string
{
    $u = trim((string)preg_replace('/\s+/u', ' ', mb_strtoupper($urun, 'UTF-8')));
    if ($u === '') return '';
    return str_starts_with($u, 'FRESH ') ? $u : 'FRESH ' . $u;
}

/** "3,5 KG" → 3.5 (koli içeriği, kg); çözülemezse null. */
function siparis_inv_koli_kg(?string $s): ?float
{
    if ($s === null || !preg_match('/(\d+(?:[.,]\d+)?)\s*kg/i', $s, $m)) return null;
    $f = (float)str_replace(',', '.', $m[1]);
    return $f > 0 ? $f : null;
}

/** PKL hücre sayısı (n tipi ham; metin ise TR biçimi). */
function siparis_inv_hucre_sayi(?array $h): float
{
    if ($h === null) return 0.0;
    $v = trim((string)$h['v']);
    if ($v === '') return 0.0;
    return $h['t'] === 'n' && is_numeric($v) ? (float)$v : num($v);
}

/**
 * Sevkiyatın GÜNCEL PKL xlsx'ini okur (yalnız üretilmiş/yüklenmiş + dosyası var). Yoksa null.
 * @return array{palet:int,koli:int,brut:float,net:float,kalibreler:list<string>,urun:string,cesit:string,paket:string,harvest:string,incoterms:string,plaka:string,belge_no:string}|null
 */
function siparis_inv_pkl_oku(PDO $pdo, int $sid): ?array
{
    $st = $pdo->prepare('SELECT * FROM siparis_pkl WHERE sevkiyat_id = ?');
    $st->execute([$sid]);
    $pkl = $st->fetch(PDO::FETCH_ASSOC);
    if (!$pkl || !in_array((string)$pkl['durum'], ['uretildi', 'yuklendi'], true) || (int)($pkl['dosya_belge_id'] ?? 0) <= 0) return null;
    $b = $pdo->prepare('SELECT * FROM siparis_belgeler WHERE id = ? AND deleted_at IS NULL');
    $b->execute([(int)$pkl['dosya_belge_id']]);
    $belge = $b->fetch(PDO::FETCH_ASSOC);
    if (!$belge) return null;
    $yol = siparis_dosya_yolu($belge);
    if ($yol === null) return null;
    $a = siparis_pkl_xlsx_ac($yol);
    if (!$a['ok']) return null;
    $h = siparis_pkl_hucreler($a['sheet'], $a['sst']);
    $o = ['palet' => 0, 'koli' => 0, 'brut' => 0.0, 'net' => 0.0, 'kalibreler' => [], 'urun' => '', 'cesit' => '', 'paket' => '', 'harvest' => '',
          'incoterms' => trim((string)($h['C6']['v'] ?? '')), 'plaka' => trim((string)($h['C8']['v'] ?? '')),
          'belge_no' => trim((string)($h['C4']['v'] ?? '')) !== '' ? trim((string)$h['C4']['v']) : (string)($pkl['belge_no'] ?? ''),
          'bos_net' => 0, 'bos_brut' => 0, 'eksik_hucre' => (int)($pkl['eksik_hucre'] ?? 0)];
    $kal = [];
    for ($r = SIPARIS_PKL_ILK_SATIR; $r < 10000; $r++) {
        if (trim((string)($h['B' . $r]['v'] ?? '')) === '') break;
        $o['palet']++;
        $o['koli'] += (int)round(siparis_inv_hucre_sayi($h['T' . $r] ?? null));
        $m = siparis_inv_hucre_sayi($h['M' . $r] ?? null); $n = siparis_inv_hucre_sayi($h['N' . $r] ?? null);
        if ($m <= 0) $o['bos_brut']++;                                      // M (brüt) / N (net) boş ya da 0 → toplam güvenilmez
        if ($n <= 0) $o['bos_net']++;
        $o['brut'] += $m;
        $o['net'] += $n;
        $g = trim((string)($h['G' . $r]['v'] ?? ''));
        if ($g !== '') $kal[$g] = true;
        foreach (['urun' => 'E', 'cesit' => 'F', 'paket' => 'I', 'harvest' => 'K'] as $k => $c) {
            if ($o[$k] === '' && trim((string)($h[$c . $r]['v'] ?? '')) !== '') $o[$k] = trim((string)$h[$c . $r]['v']);
        }
    }
    $o['kalibreler'] = array_map('strval', array_keys($kal));
    $o['brut'] = round($o['brut'], 3);
    $o['net'] = round($o['net'], 3);
    return $o['palet'] > 0 ? $o : null;
}

/** Sevkiyatın kalemlerinden koli toplamı (etkin_koli, yoksa palet × pf) ve beklenen net kg (koli × koli içeriği); biri çözülemezse net null. */
function siparis_inv_kalem_toplam(array $kalemler): array
{
    $palet = 0; $koli = 0; $net = 0.0; $netOk = true; $kal = []; $urun = ''; $cesit = '';
    foreach ($kalemler as $k) {
        $p = (int)($k['etkin_palet'] ?? 0);
        if ($p <= 0) continue;
        $kk = $k['etkin_koli'] !== null ? (int)$k['etkin_koli'] : ($k['pf'] !== null ? $p * (int)$k['pf'] : 0);
        $palet += $p; $koli += $kk;
        $kg = siparis_inv_koli_kg($k['koli_icerik'] ?? null);
        if ($kg === null) $netOk = false; else $net += $kk * $kg;
        if (trim((string)($k['kalibre'] ?? '')) !== '') $kal[(string)$k['kalibre']] = true;
        if ($urun === '') $urun = trim((string)($k['urun'] ?? '')) !== '' ? (string)$k['urun'] : trim((string)($k['aciklama'] ?? ''));
        if ($cesit === '') $cesit = trim((string)($k['cesit'] ?? ''));
    }
    return ['palet' => $palet, 'koli' => $koli, 'net' => $netOk && $koli > 0 ? round($net, 3) : null, 'kalibreler' => array_map('strval', array_keys($kal)), 'urun' => $urun, 'cesit' => $cesit];
}

/** Kayıtlı girdi (hks_kv) — yoksa []. */
function siparis_inv_girdi_oku(PDO $pdo, int $sid): array
{
    return siparis_inv_kv_oku($pdo, SIPARIS_INV_GIRDI_KV_ON . $sid) ?? [];
}

/**
 * Form varsayılanları + PKL özeti. girdi = kayıtlı girdi (varsa) yoksa boş form (tarih = bugün, iskonto = ayar varsayılanı).
 * ozet.kaynak 'pkl' (güncel PKL xlsx'i) ya da 'kalem' (PKL yok → sevkiyat kalemleri; brüt bilinmez, net koli × koli içeriğinden TAHMİN).
 * @return array{girdi:array,ozet:array,notlar:list<string>}
 */
function siparis_inv_varsayilan(PDO $pdo, array $sev): array
{
    $sid = (int)($sev['id'] ?? 0);
    $ayar = siparis_inv_ayar_oku($pdo);
    $girdi = ['no' => '', 'tarih' => date('Y-m-d'), 'fiyat' => '', 'iskonto' => $ayar['iskonto_varsayilan'], 'efatura_toplam' => '', 'net_kg' => '', 'brut_kg' => ''];
    $kayitli = $sid > 0 ? siparis_inv_girdi_oku($pdo, $sid) : [];
    foreach (array_keys($girdi) as $k) if (isset($kayitli[$k]) && is_scalar($kayitli[$k])) $girdi[$k] = $kayitli[$k];
    $notlar = [];
    $kalemler = $sid > 0 ? siparis_kalemler($pdo, $sid) : [];
    $kt = siparis_inv_kalem_toplam($kalemler);
    $pkl = $sid > 0 ? siparis_inv_pkl_oku($pdo, $sid) : null;
    $plakaSev = (string)($sev['plakalar'] ?? '');
    if ($pkl !== null) {
        $ozet = [
            'order_ref' => (string)($sev['order_ref'] ?? ''), 'belge_no' => $pkl['belge_no'], 'palet' => $pkl['palet'], 'koli' => $pkl['koli'],
            'brut_kg' => $pkl['brut'], 'net_kg' => $pkl['net'], 'kalibreler' => $pkl['kalibreler'],
            'aciklama' => (string)siparis_kirp(trim(siparis_inv_urun_ad($pkl['urun'] !== '' ? $pkl['urun'] : $kt['urun']) . ' ' . siparis_inv_kalibre_aralik($pkl['kalibreler'])), 120),
            'harvest' => $pkl['harvest'], 'incoterms_pkl' => $pkl['incoterms'], 'plaka' => (string)siparis_kirp($pkl['plaka'] !== '' ? $pkl['plaka'] : $plakaSev, 80),
            'paket' => $pkl['paket'] !== '' ? strtoupper($pkl['paket']) : 'CARTON BOXES', 'kaynak' => 'pkl',
            'net_nominal_kg' => (float)($kt['net'] ?? 0), 'bos_net' => $pkl['bos_net'], 'bos_brut' => $pkl['bos_brut'], 'pkl_eksik_hucre' => $pkl['eksik_hucre'],
        ];
    } else {
        $pr = $sid > 0 ? $pdo->prepare('SELECT belge_no, sezon FROM siparis_pkl WHERE sevkiyat_id = ?') : null;
        $pk = [];
        if ($pr) { $pr->execute([$sid]); $pk = $pr->fetch(PDO::FETCH_ASSOC) ?: []; }
        $notlar[] = 'Bu sevkiyat için PKL yok — değerler sevkiyat kalemlerinden alındı. Brüt kg bilinmiyor; net kg koli × koli içeriğinden tahmindir. Önce PKL üretin.';
        $ozet = [
            'order_ref' => (string)($sev['order_ref'] ?? ''), 'belge_no' => (string)($pk['belge_no'] ?? ''), 'palet' => $kt['palet'], 'koli' => $kt['koli'],
            'brut_kg' => 0.0, 'net_kg' => (float)($kt['net'] ?? 0), 'kalibreler' => $kt['kalibreler'],
            'aciklama' => (string)siparis_kirp(trim(siparis_inv_urun_ad($kt['urun']) . ' ' . siparis_inv_kalibre_aralik($kt['kalibreler'])), 120),
            'harvest' => (string)($pk['sezon'] ?? '') !== '' ? (string)$pk['sezon'] : siparis_sezon($sev['yukleme_tarihi'] ?? null),
            'incoterms_pkl' => (string)($sev['incoterm'] ?? ''), 'plaka' => (string)siparis_kirp($plakaSev, 80), 'paket' => 'CARTON BOXES', 'kaynak' => 'kalem',
            'net_nominal_kg' => (float)($kt['net'] ?? 0),
        ];
    }
    return ['girdi' => $girdi, 'ozet' => $ozet, 'notlar' => $notlar];
}

// ── ÇAPRAZ KONTROLLER ───────────────────────────────────────────────────

/** Incoterms metninden 3 harfli kodu çıkarır ("INCOTERMS 2020 DAP" → DAP). */
function siparis_inv_incoterm_kodu(string $s): string
{
    return preg_match('/\b(EXW|FCA|FAS|FOB|CFR|CIF|CPT|CIP|DAP|DPU|DDP|DAT)\b/i', $s, $m) ? strtoupper($m[1]) : '';
}

/**
 * Üretim öncesi denetim. seviye 'hata' → üretilmez; 'uyari' / 'bilgi' engel DEĞİL (REX/banka eksikliği ayrıca eksikIzin ister).
 * @return list<array{seviye:string,mesaj:string,kod:string}>
 */
function siparis_inv_kontrol(PDO $pdo, array $sev, array $veri, array $ozet, array $ayar): array
{
    $k = [];
    $ekle = function (string $sev_, string $kod, string $mesaj) use (&$k): void { $k[] = ['seviye' => $sev_, 'mesaj' => $mesaj, 'kod' => $kod]; };
    $t = siparis_inv_girdi_temizle($veri);
    foreach ($t['hata'] as $alan => $m) $ekle('hata', 'girdi_' . $alan, $m);
    if (!isset($t['hata']['no']) && $t['veri']['no'] !== '' && !preg_match('/^IHR\d{13}$/', $t['veri']['no'])) {
        $ekle('uyari', 'no_bicim', 'Invoice numarası e-fatura biçiminde değil (IHR + 13 rakam bekleniyor) — doğru yazıldığından emin olun.');
    }
    $e = siparis_inv_ozet_etkin($t['veri'], $ozet);                      // $ozet = PKL özeti; e = girdideki override'larla efektif
    $netPkl = (float)$e['net_pkl_kg']; $netKul = (float)$e['net_kg'];
    $kg = fn(float $x): string => number_format($x, 0, ',', '.');
    if ($netKul <= 0) $ekle('hata', 'net_yok', 'Net kg 0 — PKL\'de net ağırlık (N sütunu) doldurulmuş olmalıdır ya da net kg elle girilmelidir.');
    if (($ozet['kaynak'] ?? '') !== 'pkl') $ekle('uyari', 'pkl_yok', 'PKL yok: palet / koli / kalibre sevkiyat kalemlerinden, net kg tahmini alındı; brüt kg bilinmiyor. Önce PKL üretin.');
    if ((float)$e['brut_kg'] > 0 && (float)$e['brut_kg'] < $netKul) {
        $ekle('hata', 'brut_net', (($ozet['kaynak'] ?? '') === 'pkl' && $t['veri']['net_kg'] === '' && $t['veri']['brut_kg'] === '' ? 'PKL brüt ağırlığı net ağırlıktan küçük — PKL\'i kontrol edin.' : 'Brüt ağırlık (' . $kg((float)$e['brut_kg']) . ' kg) net ağırlıktan (' . $kg($netKul) . ' kg) küçük olamaz.'));
    }
    // PKL'de brüt/net ağırlığı boş ya da 0 palet satırı: toplam güvenilmez → override yoksa HATA, varsa belirgin UYARI
    foreach (['net' => ['bos_net', 'net_kg', 'net'], 'brut' => ['bos_brut', 'brut_kg', 'brüt']] as [$sayK, $ovK, $ad]) {
        $bos = (int)($ozet[$sayK] ?? 0);
        if ($bos > 0 && ($ozet['kaynak'] ?? '') === 'pkl') {
            $var = $t['veri'][$ovK] !== '';
            $ekle($var ? 'uyari' : 'hata', 'pkl_bos_agirlik', 'PKL\'de ' . $bos . ' palet satırının ' . $ad . ' ağırlığı boş ya da 0 — ' . $ad . ' toplamı güvenilmez'
                . ($var ? '; elle girilen ' . $ad . ' kg kullanılıyor.' : '. PKL\'i tamamlayın ya da ' . $ad . ' kg\'ı elle girin.'));
        }
    }
    if (($ozet['kaynak'] ?? '') === 'pkl' && (int)($ozet['pkl_eksik_hucre'] ?? 0) > 0 && (int)($ozet['bos_net'] ?? 0) === 0 && (int)($ozet['bos_brut'] ?? 0) === 0) {
        $ekle('bilgi', 'pkl_eksik_hucre', 'PKL kısmi üretilmiş (' . (int)$ozet['pkl_eksik_hucre'] . ' boş hücre); ağırlıklar dolu.');
    }
    if ($t['veri']['net_kg'] !== '') {                                      // yazım hatası koruması: PKL / nominalden > %50 sapma
        $ref = $netPkl > 0 ? $netPkl : (float)($ozet['net_nominal_kg'] ?? 0);
        if ($ref > 0 && abs($netKul - $ref) / $ref > 0.5) {
            $ekle('uyari', 'net_sapma', 'Elle girilen net ağırlık (' . $kg($netKul) . ' kg) ' . ($netPkl > 0 ? 'PKL' : 'nominal') . ' değerinden (' . $kg($ref) . ' kg) %50\'den fazla sapıyor — yazım hatası olabilir.');
        }
    }
    if ($t['ok'] && (float)$t['veri']['fiyat'] > 50) {
        $ekle('uyari', 'fiyat_yuksek', 'Birim fiyat (' . siparis_inv_fiyat_goster($t['veri']['fiyat']) . ' €/kg) 50 €/kg\'ın üstünde — doğru girildiğinden emin olun.');
    }
    if ($t['veri']['net_kg'] !== '' && abs($netKul - $netPkl) > 0.0005) {
        $ekle('bilgi', 'net_elle', 'Net ağırlık PKL\'den farklı: elle girildi (' . $kg($netKul) . ' kg; PKL ' . $kg($netPkl) . ' kg).');
    }
    if ($t['veri']['brut_kg'] !== '' && abs((float)$e['brut_kg'] - (float)$e['brut_pkl_kg']) > 0.0005) {
        $ekle('bilgi', 'brut_elle', 'Brüt ağırlık PKL\'den farklı: elle girildi (' . $kg((float)$e['brut_kg']) . ' kg; PKL ' . $kg((float)$e['brut_pkl_kg']) . ' kg).');
    }
    if ((int)($ozet['palet'] ?? 0) <= 0 || (int)($ozet['koli'] ?? 0) <= 0) $ekle('hata', 'palet_koli', 'Palet / koli sayısı bulunamadı.');
    if (trim((string)($ozet['aciklama'] ?? '')) === '') $ekle('uyari', 'aciklama_bos', 'Ürün açıklaması boş — PKL\'de Product sütunu dolu olmalıdır.');

    // PKL ↔ kalem tutarlılığı
    if (($ozet['kaynak'] ?? '') === 'pkl' && !empty($sev['id'])) {
        $kt = siparis_inv_kalem_toplam(siparis_kalemler($pdo, (int)$sev['id']));
        if ($kt['palet'] > 0 && $kt['palet'] !== (int)$ozet['palet']) $ekle('uyari', 'palet_fark', 'PKL palet sayısı (' . (int)$ozet['palet'] . ') sevkiyat kalemlerindeki toplamdan (' . $kt['palet'] . ') farklı.');
        if ($kt['koli'] > 0 && $kt['koli'] !== (int)$ozet['koli']) $ekle('uyari', 'koli_fark', 'PKL koli sayısı (' . (int)$ozet['koli'] . ') sevkiyat kalemlerindeki toplamdan (' . $kt['koli'] . ') farklı.');
        // PKL net ↔ nominal net (koli × koli içeriği): e-fatura / beyanname çoğu zaman nominali kullanır; fark > %0,5 ise uyar.
        // Kullanılan net zaten nominale (±%0,5) eşitse (kullanıcı bilinçli override etti) uyarılmaz.
        $nom = (float)($ozet['net_nominal_kg'] ?? ($kt['net'] ?? 0));
        if ($nom > 0 && $netPkl > 0 && abs($netPkl - $nom) / $nom > 0.005 && abs($netKul - $nom) / $nom > 0.005) {
            $ekle('uyari', 'net_nominal', 'PKL net ' . $kg($netPkl) . ' kg, nominal ' . $kg($nom) . ' kg (koli × koli içeriği); e-fatura/beyanname hangisini kullanıyorsa onu girin.');
        }
    }
    // e-fatura toplamı çapraz kontrolü
    if ($t['ok'] || (isset($t['veri']['fiyat']) && $t['veri']['fiyat'] !== '')) {
        $h = siparis_inv_hesapla($t['veri'], $ozet);                 // net override'ı veride
        if ($t['veri']['efatura_toplam'] !== '') {
            $ef = (int)round((float)$t['veri']['efatura_toplam'] * 100);
            if ($ef !== $h['toplam_kurus']) {
                $ekle('uyari', 'efatura_fark', 'E-fatura toplamı (€ ' . number_format($ef / 100, 2, ',', '.') . ') hesaplanan toplamdan (€ ' . number_format($h['toplam'], 2, ',', '.') . ') farklı.');
            }
        }
    }
    // Incoterms
    $pk = siparis_inv_incoterm_kodu((string)($ozet['incoterms_pkl'] ?? ''));
    $ay = siparis_inv_incoterm_kodu((string)($ayar['teslim'] ?? ''));
    if ($pk !== '' && $ay !== '' && $pk !== $ay) {
        $ekle('bilgi', 'incoterms', 'PKL\'deki Incoterms (' . $pk . ') ile invoice teslim şekli (' . trim((string)$ayar['teslim']) . ') farklı — invoice\'ta ayardaki teslim şekli basılır.');
    }
    if (trim((string)($ozet['plaka'] ?? '')) === '') $ekle('uyari', 'plaka_yok', 'Plaka bilgisi yok (TRUCK / TIR alanı boş basılacak).');
    $eksik = siparis_inv_ayar_eksikler($ayar);
    if ($eksik) {
        $rex = trim((string)($ayar['rex_no'] ?? '')) === '';
        $ekle('uyari', $rex ? 'rex_eksik' : 'banka_eksik', 'Invoice ayarında eksik: ' . implode(', ', $eksik) . ($rex ? ' — menşe beyanında "______" basılır.' : '.'));
    }
    return $k;
}

// ── DURUM ───────────────────────────────────────────────────────────────

/**
 * Sevkiyatın güncel invoice / e-fatura durumu (salt okunur).
 * @return array{var:bool,belge_id:?int,dosya_adi:?string,uretildi_at:?string,uretim_sayisi:int,girdi:array,efatura_belge_id:?int}
 */
function siparis_inv_durum(PDO $pdo, int $sid): array
{
    $son = function (string $tur) use ($pdo, $sid): ?array {
        $st = $pdo->prepare('SELECT b.id, b.orijinal_ad, b.created_at FROM siparis_belge_bag bb JOIN siparis_belgeler b ON b.id = bb.belge_id
            WHERE bb.sevkiyat_id = ? AND bb.gecerli = 1 AND b.tur = ? AND b.deleted_at IS NULL ORDER BY b.id DESC LIMIT 1');
        $st->execute([$sid, $tur]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $inv = $son('invoice');
    $ef = $son('efatura');
    $n = $pdo->prepare("SELECT COUNT(*) FROM siparis_belge_bag bb JOIN siparis_belgeler b ON b.id = bb.belge_id WHERE bb.sevkiyat_id = ? AND b.tur = 'invoice' AND b.deleted_at IS NULL");
    $n->execute([$sid]);
    return ['var' => $inv !== null, 'belge_id' => $inv ? (int)$inv['id'] : null, 'dosya_adi' => $inv ? (string)$inv['orijinal_ad'] : null,
            'uretildi_at' => $inv ? (string)$inv['created_at'] : null, 'uretim_sayisi' => (int)$n->fetchColumn(),
            'girdi' => siparis_inv_girdi_oku($pdo, $sid), 'efatura_belge_id' => $ef ? (int)$ef['id'] : null];
}

// ── HTML / PDF ──────────────────────────────────────────────────────────

/** Tutar gösterimi: 1.234,56 */
function siparis_inv_tutar_goster(float $n): string { return number_format($n, 2, ',', '.'); }
/** Birim fiyat gösterimi: en az 2, en çok 4 ondalık, virgüllü. */
function siparis_inv_fiyat_goster(string|float $f): string
{
    $x = siparis_inv_ondalik_coz($f, false) ?? 0.0;
    return str_replace('.', ',', siparis_inv_fiyat_metin($x));
}
/** gg.aa.yyyy */
function siparis_inv_tarih_goster(string $ymd): string
{
    $t = siparis_tarih_coz($ymd);
    return $t !== null ? date('d.m.Y', (int)strtotime($t . ' 12:00:00')) : '';
}

/**
 * Invoice şablonu (dompdf uyumlu, A4 dikey, tek sayfa). Her kullanıcı verisi h() ile kaçırılır.
 * ⚠ Menşe beyanı + COUNTRY OF ORIGIN: TURKEY koşulsuzdur; REX boşsa "______".
 */
function siparis_inv_html(array $veri, array $ozet, array $ayar, array $hesap): string
{
    $ozet = siparis_inv_ozet_etkin($veri, $ozet);                     // girdideki net / brüt override'ı
    $ozet['aciklama'] = (string)siparis_kirp((string)($ozet['aciklama'] ?? ''), 120);   // tek sayfa kalsın
    $ozet['plaka'] = (string)siparis_kirp((string)($ozet['plaka'] ?? ''), 80);
    $rex = trim((string)($ayar['rex_no'] ?? ''));
    $rexGoster = $rex !== '' ? h($rex) : '______';
    $kgG = fn($v): string => (float)$v > 0 ? number_format((float)$v, 0, ',', '.') . ' KG' : '—';
    $isk = (float)($veri['iskonto'] ?? 0);
    $iskG = number_format($isk, 2, ',', '.') . '%';
    $satirlar = [
        ['PO', (string)($ozet['order_ref'] ?? '')],
        ['HARVEST', (string)($ozet['harvest'] ?? '')],
        ['HS CODE', SIPARIS_INV_HS_KODU],
        ['TRADE MARK', SIPARIS_INV_MARKA],
        ['DELIVERY TERMS', (string)($ayar['teslim'] ?? '') !== '' ? (string)$ayar['teslim'] : SIPARIS_INV_TESLIM_VARSAYILAN],
        ['BANK DETAILS', (string)($ayar['banka_ad'] ?? '')],
        ['BANK ADDRESS', (string)($ayar['banka_adres'] ?? '')],
        ['BRANCH', (string)($ayar['sube'] ?? '')],
        ['SWIFT CODE', (string)($ayar['swift'] ?? '')],
        ['ACCOUNT IBAN Euro', (string)($ayar['iban_eur'] ?? '')],
        ['COUNTRY OF ORIGIN', 'TURKEY'],
        ['TRUCK / TIR', (string)($ozet['plaka'] ?? '')],
    ];
    ob_start();
    ?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Invoice</title>
<style>
@page { margin: 26pt 30pt 24pt 30pt; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 7.4pt; color: #000; margin: 0; }
table { border-collapse: collapse; }
.baslik { font-family: 'DejaVu Serif', serif; font-size: 20pt; font-weight: bold; text-align: center; margin: 8pt 0 6pt 0; }
.no { text-align: center; font-size: 8pt; margin-bottom: 7pt; }
.no b { font-size: 8pt; }
.cerceve { width: 100%; border: 0.9pt solid #000; }
.cerceve td { vertical-align: top; }
.exp { width: 49%; border-right: 0.9pt solid #000; padding: 3pt 4pt 6pt 4pt; font-size: 6.4pt; height: 118pt; }
.exp .et { font-size: 6.6pt; }
.exp .ad { font-size: 8pt; font-weight: bold; margin: 12pt 0 8pt 0; line-height: 1.25; }
.exp .adr { font-size: 6.2pt; line-height: 1.9; }
.sold { padding: 3pt 4pt 3pt 4pt; font-size: 8pt; }
.sold .b { font-weight: bold; line-height: 1.35; margin-bottom: 7pt; }
.kalem { width: 100%; border: 0.9pt solid #000; border-top: 0; }
.kalem th { font-size: 5.3pt; font-weight: normal; text-align: center; padding: 5pt 2pt; border-left: 0.5pt solid #000; border-bottom: 0.5pt solid #000; line-height: 1.25; }
.kalem td { font-size: 6.4pt; text-align: center; vertical-align: middle; padding: 6pt 3pt; border-left: 0.5pt solid #000; }
.kalem td.first, .kalem th.first { border-left: 0; }
.kalem .tanim { font-weight: bold; }
.kalem .sag { font-weight: bold; }
.kalem tr.ara td { border-top: 0.5pt solid #000; padding: 5pt 3pt; }
.kalem tr.tpl td { border-top: 0.5pt solid #000; padding: 7pt 3pt; font-size: 7pt; }
.kalem td.dg { height: 410pt; vertical-align: top; text-align: left; padding: 0; }
.dolgu .ic { padding: 26pt 4pt 0 6pt; }
.bosluk { height: 150pt; }
.alt { font-size: 4.4pt; line-height: 1.5; }
.meta { width: 100%; }
.kalem .meta td { font-size: 7.4pt; padding: 1.6pt 0; vertical-align: top; text-align: left; border: 0; }
.kalem .meta td.e { width: 92pt; }
.kalem .meta td.d { width: 8pt; }
.kalem .meta td.v { font-weight: bold; }
.notlar { margin-top: 20pt; font-size: 7pt; line-height: 1.4; }
.notlar .nb { font-weight: bold; margin-bottom: 2pt; }
</style></head><body>
<div class="baslik">INVOICE</div>
<div class="no">INVOICE NUMBER: <b><?= h($veri['no'] ?? '') ?></b> &nbsp;&nbsp;&nbsp;&nbsp; INVOICE DATE: <b><?= h(siparis_inv_tarih_goster((string)($veri['tarih'] ?? ''))) ?></b></div>
<table class="cerceve"><tr>
<td class="exp"><div class="et">EXPORTER</div>
<div class="ad">ASYA FRESH SEBZE MEYVE NAKLİYAT<br>VE TİCARET LİMİTED ŞİRKETİ</div>
<div class="adr"><b>Address</b><br>Hançerli Mahallesi Dervişzade Sk. No: 12 İç Kapı No:3<br>İlkadım / SAMSUN / TURKEY<br>Gaziler Vergi Dairesi VAT: 8330514103</div></td>
<td class="sold"><div>Sold to Party;</div>
<div class="b">OGL FOOD TRADE LEBENSMİTTEL VERTRIEB GMBH.<br>C/O INTEGRATED SERVICE SOLUTIONS LTD.<br>UNIT K EUROLINK BUSINESS PARK SITTINGBOURNE KENT<br>Great Britain VAT ID: GB 306 253 334</div>
<div>Ship to Party;</div>
<div class="b">C/O INTEGRATED SERVICE SOLUTIONS LTD.<br>UNIT K EUROLINK BUSINESS PARK SITTINGBOURNE KENT<br>Great Britain</div></td>
</tr></table>
<table class="kalem">
<tr><th class="first" style="width:22pt">NO</th><th style="width:44pt">Q-TY OF<br>PALLETS</th><th style="width:60pt">Q-TY OF<br>BOXES</th><th style="width:48pt">PACKING</th><th>DESCRIPTION OF GOODS</th><th style="width:44pt">GROSS<br>WEIGHT</th><th style="width:44pt">NET<br>WEIGHT</th><th style="width:36pt">PRICE KG<br>EURO</th><th style="width:60pt">TOTAL<br>EURO</th></tr>
<tr><td class="first">1</td><td><?= h((string)(int)($ozet['palet'] ?? 0)) ?></td><td class="sag"><?= h(number_format((int)($ozet['koli'] ?? 0), 0, ',', '.')) ?></td>
<td><?= str_replace('CARTON BOXES', 'CARTON<br>BOXES', h((string)($ozet['paket'] ?? 'CARTON BOXES'))) ?></td>
<td class="tanim"><?= h($ozet['aciklama'] ?? '') ?></td><td><?= h($kgG($ozet['brut_kg'] ?? 0)) ?></td><td><?= h($kgG($ozet['net_kg'] ?? 0)) ?></td>
<td class="sag">€ <?= h(siparis_inv_fiyat_goster((string)($veri['fiyat'] ?? '0'))) ?></td><td class="sag">€ <?= h(siparis_inv_tutar_goster((float)$hesap['tutar'])) ?></td></tr>
<tr class="ara"><td class="first" colspan="6"></td><td style="text-align:right">Discount</td><td class="sag"><?= h($iskG) ?></td><td class="sag">€ <?= h(siparis_inv_tutar_goster((float)$hesap['iskonto'])) ?></td></tr>
<tr class="tpl"><td class="first"></td><td></td><td colspan="5"></td><td style="text-align:right">Total</td><td class="sag">€ <?= h(siparis_inv_tutar_goster((float)$hesap['toplam'])) ?></td></tr>
<tr class="dolgu"><td class="first"></td><td></td><td class="dg" colspan="7"><div class="ic">
<table class="meta">
<?php foreach ($satirlar as [$e, $v]): ?>
<tr><td class="e"><?= h($e) ?></td><td class="d">:</td><td class="v"><?= h($v) ?></td></tr>
<?php endforeach; ?>
</table>
<div class="notlar"><div class="nb">NOTES:</div>
<b>“The exporter of the products covered by this document (Customs Authorisation No. <?= $rexGoster ?>) declares that, except where otherwise clearly indicated, these products are of Turkey preferential origin”</b></div>
<div class="bosluk"></div>
<div class="alt">The Importer shall discharge the goods immediately upon arrival to the delivery address. The shipment shall be immediately inspected upon arrival. The goods belong to <b>Asya Fresh</b> (the exporter) until the inspection results agreed between both parties. Out of this period without above mentioned conditions no claim can be accepted.</div>
</div></td></tr>
</table>
</body></html><?php
    return (string)ob_get_clean();
}

/** HTML → PDF baytları (hesap_pdf.php ile AYNI dompdf ayarları; uzak kaynak / PHP kapalı). dompdf yoksa RuntimeException. */
function siparis_inv_pdf(string $html): string
{
    if (!class_exists(\Dompdf\Dompdf::class)) {
        $oto = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($oto)) require_once $oto;
    }
    if (!class_exists(\Dompdf\Dompdf::class)) throw new RuntimeException('PDF bileşeni (dompdf) sunucuda yok.');
    $opt = new \Dompdf\Options();
    $opt->set('defaultFont', 'DejaVu Sans');
    $opt->set('isRemoteEnabled', false);
    $opt->set('isPhpEnabled', false);
    $opt->set('isHtml5ParserEnabled', true);
    $opt->set('chroot', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
    $d = new \Dompdf\Dompdf($opt);
    $d->loadHtml($html, 'UTF-8');
    $d->setPaper('A4', 'portrait');
    $d->render();
    return $d->output();
}

/** INV-<order_ref>-<AsyaDocNNN>-<gg.aa.yyyy>.pdf — parçalar [A-Za-z0-9.-] ile temizlenir, belge no yoksa atlanır. */
function siparis_inv_dosya_adi(string $orderRef, string $belgeNo, string $ymd): string
{
    $tem = fn(string $s): string => trim((string)preg_replace('/[^A-Za-z0-9.\-]/', '', $s), '._-');
    $t = siparis_tarih_coz($ymd);
    $p = array_values(array_filter(['INV', $tem($orderRef), $tem($belgeNo), $t !== null ? date('d.m.Y', (int)strtotime($t . ' 12:00:00')) : ''], fn($x) => $x !== ''));
    return implode('-', $p) . '.pdf';
}

// ── ÜRETİM ──────────────────────────────────────────────────────────────

/**
 * Invoice PDF'ini üretir, belge olarak saklar ve sevkiyata bağlar — TEK transaction. 'hata' seviyesinde kontrol varsa ÜRETMEZ;
 * REX / banka ayarı eksikse yalnız $eksikIzin=true ile üretir. Yeniden üretimde önceki invoice bağı gecerli=0 olur (olay belge_eski_surum).
 * Girdi hks_kv 'siparis_invoice_<sid>'e yazılır. Depo / yetki kapıları SAYFADA.
 * @return array{ok:bool,hata?:string,belge_id?:int,dosya_adi?:string,uyarilar:list<array>}
 */
function siparis_inv_uret(PDO $pdo, int $sid, int $uid, array $veri, bool $eksikIzin): array
{
    $sonuc = ['ok' => false, 'uyarilar' => []];
    $dosya = null;
    $sahip = !$pdo->inTransaction();
    if ($sahip) $pdo->beginTransaction();
    try {
        $kilit = $pdo->prepare('SELECT id FROM siparis_sevkiyatlar WHERE id = ? AND deleted_at IS NULL' . siparis_kilit_sql($pdo));   // MySQL: eşzamanlı üretim sırayla (PKL emsali)
        $kilit->execute([$sid]);
        $sev = $kilit->fetchColumn() !== false ? siparis_sevkiyat($pdo, $sid) : null;
        if (!$sev) throw new RuntimeException('Sevkiyat bulunamadı.');
        $t = siparis_inv_girdi_temizle($veri);
        if (!$t['ok']) throw new RuntimeException(implode(' ', $t['hata']));
        $veri = $t['veri'];
        $ayar = siparis_inv_ayar_oku($pdo);
        $v = siparis_inv_varsayilan($pdo, $sev);
        $ozet = $v['ozet'];                                          // PKL özeti (kontrol karşılaştırır)
        $kontrol = siparis_inv_kontrol($pdo, $sev, $veri, $ozet, $ayar);
        $sonuc['uyarilar'] = $kontrol;
        $hatalar = array_values(array_filter($kontrol, fn($x) => $x['seviye'] === 'hata'));
        if ($hatalar) throw new RuntimeException(implode(' ', array_column($hatalar, 'mesaj')));
        $eksik = siparis_inv_ayar_eksikler($ayar);
        if ($eksik && !$eksikIzin) throw new RuntimeException('Invoice ayarında eksik alan var (' . implode(', ', $eksik) . ') — ayarı tamamlayın ya da "eksik alanlarla oluştur"u onaylayın.');

        $ozet = siparis_inv_ozet_etkin($veri, $ozet);                 // net / brüt override'ı uygulanmış
        $hesap = siparis_inv_hesapla($veri, $ozet);
        $pdf = siparis_inv_pdf(siparis_inv_html($veri, $ozet, $ayar, $hesap));
        if (strncmp($pdf, '%PDF', 4) !== 0) throw new RuntimeException('PDF üretilemedi.');
        if (!siparis_depo_hazirla()) throw new RuntimeException('Belge klasörü hazırlanamadı.');
        $diskAdi = bin2hex(random_bytes(16)) . '.pdf';
        $dosya = rtrim(SIPARIS_DEPO_DIR, '/') . '/' . $diskAdi;
        if (file_put_contents($dosya, $pdf) === false) { $dosya = null; throw new RuntimeException('Invoice dosyası yazılamadı.'); }
        @chmod($dosya, 0640);
        $sha = (string)hash_file('sha256', $dosya);
        $dosyaAdi = siparis_inv_dosya_adi((string)$sev['order_ref'], (string)$ozet['belge_no'], $veri['tarih']);
        $bs = $pdo->prepare('SELECT id FROM siparis_belgeler WHERE sha256 = ?');
        $bs->execute([$sha]);
        $belgeId = (int)($bs->fetchColumn() ?: 0);
        $simdi = siparis_simdi();
        if ($belgeId > 0) {                                                     // aynı bayt: yeni satır açılmaz
            @unlink($dosya); $dosya = null;
            $pdo->prepare('UPDATE siparis_belgeler SET deleted_at = NULL, deleted_by = NULL WHERE id = ?')->execute([$belgeId]);
        } else {
            $ozetM = 'Invoice ' . $veri['no'] . ' · € ' . siparis_inv_tutar_goster((float)$hesap['toplam']);
            $pdo->prepare('INSERT INTO siparis_belgeler (tur, kaynak, durum, sha256, disk_adi, orijinal_ad, mime, boyut, belge_tarihi, tedarikci_no, ozet, parse_json, uyari_sayisi, depo, yukleyen, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(['invoice', 'uretim', 'islendi', $sha, $diskAdi, $dosyaAdi, 'application/pdf', (int)filesize($dosya), $simdi, $sev['tedarikci_no'], $ozetM,
                           siparis_json(['invoice_no' => $veri['no'], 'tarih' => $veri['tarih'], 'toplam_kurus' => $hesap['toplam_kurus'], 'para_birimi' => 'EUR', 'order_refs' => [$sev['order_ref']],
                                         'warnings' => array_map(fn($x) => ['level' => ['hata' => 'error', 'uyari' => 'warn'][$x['seviye']] ?? 'info', 'code' => $x['kod'], 'message' => $x['mesaj']], $kontrol)]),
                           count(array_filter($kontrol, fn($x) => $x['seviye'] === 'uyari')), (string)$sev['depo'], $uid, $simdi]);
            $belgeId = (int)$pdo->lastInsertId();
        }
        // — bağ: önceki invoice bağları eski sürüm —
        siparis_bag_yaz($pdo, $belgeId, $sid, $uid);
        $eb = $pdo->prepare("SELECT bb.id, bb.belge_id FROM siparis_belge_bag bb JOIN siparis_belgeler b ON b.id = bb.belge_id
            WHERE bb.sevkiyat_id = ? AND bb.gecerli = 1 AND b.tur = 'invoice' AND bb.belge_id <> ?");
        $eb->execute([$sid, $belgeId]);
        foreach ($eb->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $pdo->prepare('UPDATE siparis_belge_bag SET gecerli = 0 WHERE id = ?')->execute([(int)$x['id']]);
            siparis_olay($pdo, $sid, 'belge_eski_surum', null, null, 'Yeni invoice üretildi; önceki invoice eski sürüm', (int)$x['belge_id'], $uid);
        }
        $pdo->prepare('UPDATE siparis_belge_bag SET gecerli = 1 WHERE belge_id = ? AND sevkiyat_id = ?')->execute([$belgeId, $sid]);
        siparis_olay($pdo, $sid, 'invoice_uretildi', null, $veri['no'], $dosyaAdi . ($eksik ? ' (ayar eksik: ' . implode(', ', $eksik) . ')' : ''), $belgeId, $uid);
        siparis_inv_kv_yaz($pdo, SIPARIS_INV_GIRDI_KV_ON . $sid, $veri + ['uretildi_at' => $simdi, 'belge_id' => $belgeId]);
        siparis_audit('siparis_invoice_uret', $sid, null, ['sevkiyat_id' => $sid, 'invoice_no' => $veri['no'], 'toplam_kurus' => $hesap['toplam_kurus'],
            'iskonto' => $veri['iskonto'], 'rex_var' => trim((string)$ayar['rex_no']) !== '', 'ayar_eksik' => count($eksik)], $uid);
        if ($sahip) $pdo->commit();
        return ['ok' => true, 'belge_id' => $belgeId, 'dosya_adi' => $dosyaAdi, 'uyarilar' => $kontrol];
    } catch (Throwable $e) {
        if ($sahip && $pdo->inTransaction()) $pdo->rollBack();
        if ($dosya !== null && is_file($dosya)) @unlink($dosya);
        if ($e instanceof RuntimeException) {
            $sonuc['hata'] = $e->getMessage();
        } else {
            error_log('[siparis_inv_uret] ' . get_class($e) . ': ' . $e->getMessage());
            $sonuc['hata'] = 'Invoice oluşturulamadı.';
        }
        if (!$sahip) throw $e;
        return $sonuc;
    }
}

// ── E-FATURA (yükleme + best-effort metin çıkarımı) ─────────────────────

/** PDF'in metin katmanı (siparis_pdf_oku; OCR YOK). Okunamazsa ''. */
function siparis_inv_pdf_metin(string $yol): string
{
    try {
        $o = siparis_pdf_oku($yol);
    } catch (Throwable $e) {
        return '';
    }
    $s = [];
    foreach ($o['pages'] as $segs) {
        foreach ($segs as $seg) { $t = trim((string)preg_replace('/\s+/u', ' ', (string)($seg['text'] ?? ''))); if ($t !== '') $s[] = $t; }
    }
    return trim(implode(' ', $s));
}

/**
 * E-fatura metninden best-effort alan çıkarımı. Her alan YALNIZ tek ve belirsiz olmayan eşleşmede döner (birden çok farklı aday →
 * boş); uydurma / tahmin YOK. Fatura toplamı etiketli ("Ödenecek Tutar" / "Vergiler Dahil Toplam" / "Genel Toplam" / "Total") sayıdır.
 * @return array{no?:string,tarih?:string,fiyat?:string,iskonto?:string,efatura_toplam?:string}
 */
function siparis_inv_efatura_alanlar(string $metin): array
{
    $out = [];
    $tek = function (array $adaylar): ?string {
        $adaylar = array_values(array_unique(array_filter($adaylar, fn($x) => $x !== null && $x !== '')));
        return count($adaylar) === 1 ? $adaylar[0] : null;
    };
    if (preg_match_all('/\bIHR\d{13}\b/', $metin, $m)) { $x = $tek($m[0]); if ($x !== null) $out['no'] = $x; }
    if (preg_match_all('/(?:Fatura\s*Tarihi|D[üu]zenlenme\s*Tarihi|Invoice\s*Date)[^0-9]{0,12}(\d{1,2}\s*[.\/\-]\s*\d{1,2}\s*[.\/\-]\s*\d{4})/iu', $metin, $m)) {   // tarayıcı çıktısında "03- 10- 2026"
        $adaylar = array_map(fn($d) => siparis_tarih_coz(str_replace('-', '.', (string)preg_replace('/\s+/', '', $d))), $m[1]);
        $x = $tek($adaylar); if ($x !== null) $out['tarih'] = $x;
    }
    $say = '(\d[\d.,]*\d|\d)';                                   // sayı AYRAÇLARIYLA BÜTÜN yakalanır (kesilmez), sonra ondalik_coz ile çözülür
    $fiyatAd = [];
    $fiyatNet = [];
    if (preg_match_all('/Birim\s*Fiyat[ıi]?[^0-9]{0,25}' . $say . '/iu', $metin, $m)) array_push($fiyatAd, ...$m[1]);
    // e-fatura kalem satırı: "<miktar> KG <birim fiyat> EUR" (tarayıcı çıktısında etiketle sayı birbirinden uzak olabilir)
    if (preg_match_all('/(\d[\d.,]*)\s*KG\s+(\d+[.,]\d{2,4})\s*(?:EUR|USD|GBP|€)/iu', $metin, $m, PREG_SET_ORDER)) {
        foreach ($m as $r) { $fiyatNet[] = $r[1]; $fiyatAd[] = $r[2]; }
    }
    if ($fiyatAd) {
        $adaylar = array_map(function ($f) { $x = siparis_inv_ondalik_coz($f, false); return $x !== null && $x > 0 && $x < 1000 ? siparis_inv_fiyat_metin($x) : null; }, $fiyatAd);
        $x = $tek($adaylar); if ($x !== null) $out['fiyat'] = $x;
    }
    // Ağırlıklar: net = kalem miktarı (KG), brüt = "BRÜT 21300 KG" — invoice e-fatura/beyannameyle TUTMALI (PKL toplamından farklı olabilir)
    if ($fiyatNet) {
        $adaylar = array_map(function ($f) { $x = siparis_inv_ondalik_coz($f, true); return $x !== null && $x > 0 && $x < 1e7 && abs($x - round($x)) < 1e-9 ? (string)(int)round($x) : null; }, $fiyatNet);
        $x = $tek($adaylar); if ($x !== null) $out['net_kg'] = $x;
    }
    if (preg_match_all('/BR[ÜU]T\s*(\d[\d.,]*)\s*KG/iu', $metin, $m)) {
        $adaylar = array_map(function ($f) { $x = siparis_inv_ondalik_coz($f, true); return $x !== null && $x > 0 && $x < 1e7 && abs($x - round($x)) < 1e-9 ? (string)(int)round($x) : null; }, $m[1]);
        $x = $tek($adaylar); if ($x !== null) $out['brut_kg'] = $x;
    }
    // İskonto: YALNIZ "%" işaretli ya da "Oran" etiketli ve 0–100 aralığında net değer. "0,04" gibi belirsiz (oran mı yüzde mi) değer ÖN-DOLDURULMAZ.
    $isk = [];
    $et = '(?:[İI]skonto|Discount)';
    if (preg_match_all('/' . $et . '[^0-9%]{0,20}%\s*' . $say . '/iu', $metin, $m)) array_push($isk, ...$m[1]);                      // %4,00
    if (preg_match_all('/' . $et . '[^0-9%]{0,20}' . $say . '\s*%/iu', $metin, $m)) array_push($isk, ...$m[1]);                      // 4,00 %
    if (preg_match_all('/' . $et . '\s*Oran[ıi]?[^0-9%]{0,15}' . $say . '(?![\d.,]*\s*%)/iu', $metin, $m)) {                         // "İskonto Oranı 4,00" (% yok): yalnız ≥ 1
        foreach ($m[1] as $v) { $f = siparis_inv_ondalik_coz($v, false); if ($f !== null && $f >= 1) $isk[] = $v; }
    }
    if ($isk) {
        $adaylar = array_map(function ($f) { $x = siparis_inv_ondalik_coz($f, false); return $x !== null && $x >= 0 && $x <= 100 ? (string)round($x, 2) : null; }, $isk);
        $x = $tek($adaylar); if ($x !== null) $out['iskonto'] = $x;
    }
    // Oran yazmıyorsa (e-faturada yalnız "Toplam İskonto 997,36 EUR" tutarı var): oran = iskonto / mal tutarı — YALNIZ döviz (TL değil) tutarlardan ve sonuç
    // temiz (en çok 2 ondalıkta tam) çıkıyorsa; aksi hâlde ÖN-DOLDURULMAZ.
    if (!isset($out['iskonto'])
        && preg_match_all('/Mal\s*\/\s*Hizmet\s*Toplam\s*Tutar[ıi]\s*' . $say . '\s*(?:EUR|USD|GBP|€)/iu', $metin, $mt)
        && preg_match_all('/Toplam\s*[İI]skonto\s*' . $say . '\s*(?:EUR|USD|GBP|€)/iu', $metin, $mi)) {
        $t = array_values(array_unique(array_map(fn($f) => siparis_inv_ondalik_coz($f, true), $mt[1]), SORT_REGULAR));
        $i = array_values(array_unique(array_map(fn($f) => siparis_inv_ondalik_coz($f, true), $mi[1]), SORT_REGULAR));
        if (count($t) === 1 && count($i) === 1 && $t[0] > 0 && $i[0] > 0 && $i[0] < $t[0]) {
            $aday = round($i[0] / $t[0] * 100, 2);                                        // sonuç, iskonto TUTARINI kuruşu kuruşuna yeniden üretmeli
            if ($aday > 0 && $aday < 100 && abs(round($t[0] * $aday / 100, 2) - $i[0]) < 0.005) $out['iskonto'] = (string)$aday;
        }
    }
    if (preg_match_all('/(?:[ÖO]denecek\s*Tutar|Vergiler\s*Dahil\s*Toplam(?:\s*Tutar)?|Genel\s*Toplam|Total(?!\s*Pallet))[^0-9]{0,25}' . $say . '(?![\d.,]*\d)(?!\s*(?:TL|TRY|₺))/iu', $metin, $m)) {   // TL karşılığı (aynı etiket) dışlanır
        $adaylar = array_map(function ($f) { $x = siparis_inv_ondalik_coz($f, true); return $x !== null ? number_format($x, 2, '.', '') : null; }, $m[1]);
        $x = $tek($adaylar); if ($x !== null) $out['efatura_toplam'] = $x;
    }
    return $out;
}

/**
 * Türk e-fatura PDF'ini saklar (tur 'efatura') ve sevkiyata bağlar; metin katmanı varsa alanları çıkarır (forma ÖN-DOLDURMA için —
 * kullanıcı onaylar). Metin yoksa (görüntü-yalnız PDF; sunucuda OCR yok) alanlar boş, metin_var=false. Önceki e-fatura bağı eski sürüm olur.
 * Depo / yetki kapıları SAYFADA. $mover testler içindir (move_uploaded_file CLI'de çalışmaz).
 * @return array{ok:bool,belge_id?:int,metin_var:bool,alanlar:array,hata?:string}
 */
function siparis_inv_efatura_yukle(PDO $pdo, int $sid, array $file, int $uid, ?callable $mover = null): array
{
    $bos = ['ok' => false, 'metin_var' => false, 'alanlar' => []];
    $sev = siparis_sevkiyat($pdo, $sid);
    if (!$sev) return $bos + ['hata' => 'Sevkiyat bulunamadı.'];
    $d = siparis_dosya_dogrula($file);
    if (!$d['ok']) return $bos + ['hata' => (string)($d['hata'] ?? 'Dosya geçersiz.')];
    if (($d['cins'] ?? '') !== 'pdf') return $bos + ['hata' => 'E-fatura PDF olmalıdır.'];
    // Aynı baytlı belge zaten varsa TÜRÜ kaydetmeden ÖNCE denetlenir: siparis_dosya_kaydet silinmiş belgeyi sessizce geri getirir —
    // başka türde ise reddedilmeli ve hiçbir yan etki (deleted_at geri alma) kalmamalı.
    $sha = (string)hash_file('sha256', (string)$file['tmp_name']);
    $var = $pdo->prepare('SELECT id, tur FROM siparis_belgeler WHERE sha256 = ?');
    $var->execute([$sha]);
    $ev = $var->fetch(PDO::FETCH_ASSOC);
    if ($ev && !in_array((string)$ev['tur'], ['bilinmiyor', 'diger', 'efatura'], true)) {
        return $bos + ['hata' => 'Bu dosya sistemde zaten başka bir belge olarak kayıtlı (' . (siparis_belge_tur_etiketleri()[(string)$ev['tur']] ?? 'belge') . ') — e-fatura olarak yüklenemez.'];
    }
    $k = siparis_dosya_kaydet($pdo, $file, $uid, (string)$sev['depo'], $mover);
    if (!$k['ok']) return $bos + ['hata' => (string)($k['hata'] ?? 'Dosya kaydedilemedi.')];
    $belgeId = (int)$k['belge_id'];
    $b = $pdo->prepare('SELECT * FROM siparis_belgeler WHERE id = ?');
    $b->execute([$belgeId]);
    $belge = $b->fetch(PDO::FETCH_ASSOC);
    if (!$belge || !in_array((string)$belge['tur'], ['bilinmiyor', 'diger', 'efatura'], true)) return $bos + ['hata' => 'Belge kaydedilemedi.'];
    $yol = siparis_dosya_yolu($belge);
    $metin = $yol !== null ? siparis_inv_pdf_metin($yol) : '';
    $metinVar = mb_strlen($metin) >= 20;
    $alanlar = $metinVar ? siparis_inv_efatura_alanlar($metin) : [];
    $sahip = !$pdo->inTransaction();
    if ($sahip) $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE siparis_belgeler SET tur = 'efatura', durum = 'islendi', belge_tarihi = COALESCE(belge_tarihi, ?), tedarikci_no = COALESCE(tedarikci_no, ?), ozet = ?, deleted_at = NULL, deleted_by = NULL WHERE id = ?")
            ->execute([siparis_simdi(), $sev['tedarikci_no'], 'e-Fatura' . (isset($alanlar['no']) ? ' ' . $alanlar['no'] : '') . ($metinVar ? '' : ' (metin okunamadı)'), $belgeId]);
        siparis_bag_yaz($pdo, $belgeId, $sid, $uid);
        $eb = $pdo->prepare("SELECT bb.id, bb.belge_id FROM siparis_belge_bag bb JOIN siparis_belgeler b ON b.id = bb.belge_id
            WHERE bb.sevkiyat_id = ? AND bb.gecerli = 1 AND b.tur = 'efatura' AND bb.belge_id <> ?");
        $eb->execute([$sid, $belgeId]);
        foreach ($eb->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $pdo->prepare('UPDATE siparis_belge_bag SET gecerli = 0 WHERE id = ?')->execute([(int)$x['id']]);
            siparis_olay($pdo, $sid, 'belge_eski_surum', null, null, 'Yeni e-fatura yüklendi; önceki e-fatura eski sürüm', (int)$x['belge_id'], $uid);
        }
        $pdo->prepare('UPDATE siparis_belge_bag SET gecerli = 1 WHERE belge_id = ? AND sevkiyat_id = ?')->execute([$belgeId, $sid]);
        siparis_audit('siparis_efatura_yukle', $sid, null, ['sevkiyat_id' => $sid, 'belge_id' => $belgeId, 'metin_var' => $metinVar, 'alan_sayisi' => count($alanlar)], $uid);
        if ($sahip) $pdo->commit();
    } catch (Throwable $e) {
        if ($sahip && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[siparis_inv_efatura_yukle] ' . get_class($e) . ': ' . $e->getMessage());
        if (!$sahip) throw $e;
        return $bos + ['hata' => 'E-fatura kaydedilemedi.'];
    }
    return ['ok' => true, 'belge_id' => $belgeId, 'metin_var' => $metinVar, 'alanlar' => $alanlar];
}
