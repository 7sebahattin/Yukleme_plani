<?php
// =========================================================
// config/frugro_invoice.php — Frugro INVOICE (İngiltere alıcı Frugro Ltd.; include-only, önek frgi_)
//
// OGL invoice'ının (config/siparis_invoice.php) eşi: A4 dikey, TEK kalem satırı (NO | PALLETS | BOXES | PACKING | DESCRIPTION | GROSS | NET |
// BOX PRICE | TOTAL), altında PO / HARVEST / HS CODE / banka / menşe notu. Veri: frugro_siparisler + frugro_palet (PKL toplamları tablodan).
// Şemasız (migration YOK): girdi hks_kv 'frugro_invoice_<sipariş id>', ayar hks_kv 'frugro_invoice_ayar'.
// Üretilen PDF DİSKE KAYDEDİLMEZ: her istekte kayıtlı girdiden taze üretilir (frgi_pdf_uret); kalıcı olan girdi + olaydır.
//
// ⚠ Menşe notu ("Unless specified otherwise, these goods are of Türkiye preferential origin") KOŞULSUZ — şablondan kaldırma.
// ⚠ Banka / IBAN / VAT no DEĞERİ kodda, audit'te, flash'ta, listelerde YOK (ayardan gelir; eksikse yer tutucu "______").
// ⚠ Hesap: kutu × birim fiyat (on-binde tamsayı) → kuruş, yarım yukarı, TAM SAYI (frg_hesap TEK yer; JS yalnız önizleme).
// ⚠ Frugro şartı: net/brüt ağırlık TÜM evraklarda aynı → varsayılan PKL toplamı (frugro_palet); elle farklıysa onay + uyarı.
// ⚠ Para birimi yalnız GBP | EUR (başlık: GBP → POUND, EUR → EURO; 2024 örneğindeki 'USD' başlığı hataydı).
// ⚠ dompdf: siparis_inv_pdf() (Hesap PDF kuralları: uzak kaynak/PHP kapalı, DejaVu, text-transform YOK).
// Depo + yetki kapıları SAYFADA (frugro_invoice.php); buradaki fonksiyonlar salt DB + hesap.
// =========================================================
declare(strict_types=1);

// Doğrudan istek koruması (tam yol; basename DEĞİL)
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

require_once __DIR__ . '/frugro_core.php';
require_once __DIR__ . '/siparis_invoice.php';   // yeniden kullanım: hks_kv okuma/yazma, ondalık çözümü, tutar gösterimi, dompdf, OGL ayarı (varsayılan)

const FRGI_AYAR_KV = 'frugro_invoice_ayar';
const FRGI_GIRDI_KV_ON = 'frugro_invoice_';
const FRGI_MENSE_NOTU = 'Unless specified otherwise, these goods are of Türkiye preferential origin';
const FRGI_ALICI_ADRES = 'Tingdene House, 21-24 Bradfield Rd, NN8 4HB - Wellingborough, Northamptonshire / ENGLAND';
const FRGI_INCOTERMLER = ['EXW', 'FCA', 'FAS', 'FOB', 'CFR', 'CIF', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP', 'DAT'];
const FRGI_YER_TUTUCU = '______';

/** @return array<string,array{sembol:string,baslik:string}> */
function frgi_para_birimleri(): array
{
    return ['GBP' => ['sembol' => '£', 'baslik' => 'POUND'], 'EUR' => ['sembol' => '€', 'baslik' => 'EURO']];
}

/** Faturanın yazılabildiği durumlar (kapalı / iptal salt okunur). */
function frgi_yazilabilir(array $s): bool
{
    return !in_array((string)($s['durum'] ?? ''), ['kapali', 'iptal'], true);
}

// ── AYAR (hks_kv) ───────────────────────────────────────────────────────

/** Ayar alanları: anahtar → [etiket, azami uzunluk]. */
function frgi_ayar_alanlari(): array
{
    return ['banka_ad' => ['Banka adı', 100], 'banka_adres' => ['Banka adresi', 200], 'sube' => ['Şube', 100], 'swift' => ['SWIFT', 11],
            'iban_gbp' => ['IBAN (GBP)', 34], 'iban_eur' => ['IBAN (EUR)', 34], 'vat_ihracatci' => ['İhracatçı VAT no', 40], 'vat_alici' => ['Alıcı (Frugro) VAT no', 40]];
}

/** Yalnız Frugro'ya özgü kayıtlı ayar (eksikler ''; OGL varsayılanı UYGULANMAZ — ayar ekranı bunu gösterir). @return array<string,string> */
function frgi_ayar_ham(PDO $pdo): array
{
    $v = array_fill_keys(array_keys(frgi_ayar_alanlari()), '');
    $j = siparis_inv_kv_oku($pdo, FRGI_AYAR_KV);
    if ($j === null) return $v;
    foreach (frgi_ayar_alanlari() as $k => [, $n]) if (isset($j[$k]) && is_scalar($j[$k])) $v[$k] = siparis_inv_metin($j[$k], $n);
    return $v;
}

/** Etkin ayar: Frugro'ya özgü değer doluysa o, boşsa mevcut OGL invoice ayarı (banka adı / adres / şube / SWIFT / IBAN-EUR). @return array<string,string> */
function frgi_ayar_etkin(PDO $pdo): array
{
    $f = frgi_ayar_ham($pdo);
    $o = siparis_inv_ayar_oku($pdo);
    foreach (['banka_ad', 'banka_adres', 'sube', 'swift', 'iban_eur'] as $k) {
        if ($f[$k] === '') $f[$k] = trim((string)($o[$k] ?? ''));
    }
    return $f;
}

/** Seçili para birimi için ayarda boş olan zorunlu alan etiketleri. @return list<string> */
function frgi_ayar_eksikler(array $ayar, string $pb): array
{
    $e = [];
    $iban = $pb === 'EUR' ? ['iban_eur', 'IBAN (EUR)'] : ['iban_gbp', 'IBAN (GBP)'];
    foreach (['banka_ad' => 'Banka adı', 'banka_adres' => 'Banka adresi', 'sube' => 'Şube', 'swift' => 'SWIFT', $iban[0] => $iban[1],
              'vat_ihracatci' => 'İhracatçı VAT no', 'vat_alici' => 'Alıcı VAT no'] as $k => $et) {
        if (trim((string)($ayar[$k] ?? '')) === '') $e[] = $et;
    }
    return $e;
}

/**
 * Frugro ayarını doğrular ve yazar (gönderilmeyen anahtar mevcut değerini korur). Kapı (siparis.delete) ÇAĞIRANDA.
 * Audit yalnız DEĞİŞEN alan ADLARINI yazar (IBAN / VAT / banka DEĞERİ yazılmaz).
 * @return array{ok:bool,hata?:string,degisen?:list<string>}
 */
function frgi_ayar_kaydet(PDO $pdo, array $girdi, int $uid): array
{
    // Alan BAZINDA doğrulama: geçerli alanlar KAYDEDİLİR, hatalı alan eski değerinde kalır ve 'hatalar' ile bildirilir
    // (eskiden tek hatalı alan tüm ayarı reddediyor, form boş dönüyordu).
    $eski = frgi_ayar_ham($pdo);
    $yeni = $eski;
    $hatalar = [];
    if (!siparis_evrak_mail_kv_hazir($pdo)) return ['ok' => false, 'hata' => 'Ayar tablosu (hks_kv) yok; Frugro invoice ayarı kaydedilemedi.', 'hatalar' => []];
    $A = frgi_ayar_alanlari();
    foreach ($A as $k => [$et, $n]) {
        if (!array_key_exists($k, $girdi)) continue;
        if (!is_scalar($girdi[$k]) && $girdi[$k] !== null) { $hatalar[$k] = $et . ' metin olmalıdır.'; continue; }
        if (in_array($k, ['banka_ad', 'banka_adres', 'sube'], true)) {
            $v = siparis_inv_metin($girdi[$k], 1000);
            if (mb_strlen($v) > $n) { $hatalar[$k] = $et . ' en çok ' . $n . ' karakter olabilir.'; continue; }
        } elseif ($k === 'iban_gbp' || $k === 'iban_eur') {
            $v = strtoupper((string)preg_replace('/[\s\-]+/u', '', (string)$girdi[$k]));
            if ($v !== '' && !preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $v)) { $hatalar[$k] = $et . ' geçersiz (ülke kodu + 2 kontrol hanesi + hesap no).'; continue; }
        } elseif ($k === 'swift') {
            $v = strtoupper((string)preg_replace('/\s+/u', '', (string)$girdi[$k]));
            if ($v !== '' && !preg_match('/^[A-Z0-9]{8,11}$/', $v)) { $hatalar[$k] = 'SWIFT kodu 8–11 harf/rakam olmalıdır.'; continue; }
        } else {
            $v = siparis_inv_metin($girdi[$k], 100);
            if ($v !== '' && !preg_match('/^[A-Za-z0-9 .\-\/]{1,40}$/', $v)) { $hatalar[$k] = $et . ' yalnız harf, rakam, boşluk ve . - / içerebilir (en çok 40 karakter).'; continue; }
        }
        $yeni[$k] = $v;
    }
    $degisen = [];
    foreach ($yeni as $k => $v) if ($v !== ($eski[$k] ?? '')) $degisen[] = $k;
    if ($degisen && !siparis_inv_kv_yaz($pdo, FRGI_AYAR_KV, $yeni)) return ['ok' => false, 'hata' => 'Ayar kaydedilemedi (hks_kv tablosu yazılamadı).', 'hatalar' => $hatalar];
    if ($degisen && function_exists('audit_log_event')) audit_log_event('frugro_invoice_ayar', 'frugro_siparis', null, null, ['degisen_alanlar' => $degisen], $uid);
    $r = ['ok' => !$hatalar, 'degisen' => $degisen, 'hatalar' => $hatalar];
    if ($hatalar) $r['hata'] = implode(' ', array_values($hatalar));
    return $r;
}

/** Fatura formunun KALICI taslağı (ham girilen değerler; doğrulanmamış) — hatalı gönderimde/yenilemede hiçbir alan kaybolmaz. */
function frgi_taslak_yaz(PDO $pdo, int $sid, array $deger): void
{
    if ($sid <= 0 || !siparis_evrak_mail_kv_hazir($pdo)) return;
    $t = [];
    foreach ($deger as $k => $v) if (is_string($k) && is_scalar($v)) $t[$k] = mb_substr((string)$v, 0, 120, 'UTF-8');
    siparis_inv_kv_yaz($pdo, 'frugro_invoice_taslak_' . $sid, $t);
}
/** @return array<string,string> */
function frgi_taslak_oku(PDO $pdo, int $sid): array
{
    if ($sid <= 0) return [];
    $j = siparis_inv_kv_oku($pdo, 'frugro_invoice_taslak_' . $sid);
    $t = [];
    if (is_array($j)) foreach ($j as $k => $v) if (is_string($k) && is_scalar($v)) $t[$k] = (string)$v;
    return $t;
}

// ── GİRDİ (doğrulama, SAF) ──────────────────────────────────────────────

/** Tam sayı alanı (boş = ''): 1..$ust; aksi hâlde null. */
function frgi_tam_alan(mixed $v, int $ust): int|string|null
{
    $s = trim((string)(is_scalar($v) ? $v : ''));
    if ($s === '') return '';
    $x = siparis_inv_ondalik_coz($s, true);
    return $x !== null && $x >= 1 && $x <= $ust && abs($x - round($x)) < 1e-9 ? (int)round($x) : null;
}

/**
 * Formdan gelen girdiyi doğrular/normalleştirir. Fiyat kutu başına, 0..1000, ≤ 4 ondalık, TEK ayraç HEP ondalık ("1.370" = 1,37).
 * Para birimi yalnız GBP | EUR. kutu / palet / net_kg / brut_kg isteğe bağlı TAM SAYI override (boş = PKL ya da sipariş).
 * @return array{ok:bool,veri:array<string,mixed>,hata:array<string,string>}
 */
function frgi_girdi_temizle(array $post): array
{
    foreach ($post as $k => $v) if (!is_scalar($v) && $v !== null) $post[$k] = '';
    $hata = [];
    $no = siparis_inv_metin($post['no'] ?? '', 60);
    if ($no === '') $hata['no'] = 'Fatura numarası gerekli.';
    elseif (!preg_match('#^[A-Za-z0-9._/\- ]{1,40}$#', $no)) $hata['no'] = 'Fatura numarası yalnız harf, rakam ve . _ / - içerebilir (en çok 40 karakter).';
    $tarih = siparis_tarih_coz(is_scalar($post['tarih'] ?? null) ? trim((string)$post['tarih']) : null);
    if ($tarih === null) $hata['tarih'] = 'Geçerli bir fatura tarihi girin.';
    $pb = strtoupper(trim((string)($post['para_birimi'] ?? '')));
    if (!isset(frgi_para_birimleri()[$pb])) { $hata['para_birimi'] = 'Para birimi GBP ya da EUR olmalıdır.'; $pb = ''; }
    $fiyatS = '';
    $f = siparis_inv_ondalik_coz($post['fiyat'] ?? '', false);
    if (trim((string)($post['fiyat'] ?? '')) === '' || $f === null) $hata['fiyat'] = 'Kutu başına fiyat gerekli — örn. 5,90.';
    elseif ($f <= 0 || $f >= 1000) $hata['fiyat'] = 'Kutu fiyatı 0 ile 1000 arasında olmalıdır.';
    elseif (abs($f * 10000 - round($f * 10000)) > 1e-6) $hata['fiyat'] = 'Kutu fiyatı en çok 4 ondalık içerebilir.';
    else $fiyatS = siparis_inv_fiyat_metin($f);
    $say = [];
    foreach (['kutu' => ['Kutu adedi', 99999999], 'palet' => ['Palet adedi', 9999], 'net_kg' => ['Net ağırlık', 99999999], 'brut_kg' => ['Brüt ağırlık', 99999999]] as $a => [$et, $ust]) {
        $x = frgi_tam_alan($post[$a] ?? '', $ust);
        if ($x === null) { $hata[$a] = $et . ' tam sayı olmalıdır (boş bırakırsanız PKL / sipariş değeri kullanılır).'; $x = ''; }
        $say[$a] = $x;
    }
    $inc = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($post['incoterm'] ?? '')));
    if ($inc !== '' && !in_array($inc, FRGI_INCOTERMLER, true)) { $hata['incoterm'] = 'Incoterm koşulu tanınmadı (' . implode(', ', FRGI_INCOTERMLER) . ').'; $inc = ''; }
    $veri = ['no' => $no, 'tarih' => $tarih ?? '', 'para_birimi' => $pb, 'fiyat' => $fiyatS] + $say + [
        'incoterm' => $inc, 'plaka' => siparis_inv_metin($post['plaka'] ?? '', 80), 'aciklama' => siparis_inv_metin($post['aciklama'] ?? '', 120),
        'agirlik_onay' => ($post['agirlik_onay'] ?? '') === '1' ? '1' : ''];
    return ['ok' => $hata === [], 'veri' => $veri, 'hata' => $hata];
}

// ── PKL ÖZETİ + VARSAYILANLAR ───────────────────────────────────────────

/** "x14" / "14" / "14 PC" → "14"; sayı yoksa olduğu gibi. */
function frgi_kalibre_say(string $k): string
{
    return preg_match('/(\d+(?:[.,]\d+)?)/', $k, $m) ? $m[1] : trim($k);
}

/** Ürün adı (İngilizce): NAR / POMEGRANATE → FRESH POMEGRANATE; diğerleri siparis_inv_urun_ad(). */
function frgi_urun_ad(string $urun): string
{
    $u = mb_strtoupper(trim($urun), 'UTF-8');
    if ($u === '') return '';
    if (preg_match('/(^|\s)NAR(\s|$)|POMEGRANATE/u', $u)) return 'FRESH POMEGRANATE';
    return siparis_inv_urun_ad($u);
}

/**
 * Siparişin palet satırlarından (frugro_palet; PKL ajanı yazar) toplamlar. Satır yoksa var=false.
 * @return array{var:bool,palet:int,kutu:int,brut:float,net:float,kalibreler:list<string>,bos_net:int,bos_brut:int}
 */
function frgi_pkl_ozet(array $s): array
{
    $o = ['var' => false, 'palet' => 0, 'kutu' => 0, 'brut' => 0.0, 'net' => 0.0, 'kalibreler' => [], 'bos_net' => 0, 'bos_brut' => 0];
    $kal = [];
    foreach ((array)($s['paletler'] ?? []) as $p) {
        $o['palet']++;
        $o['kutu'] += (int)($p['kutu'] ?? 0);
        $b = (float)($p['brut_kg'] ?? 0); $n = (float)($p['net_kg'] ?? 0);
        if ($b <= 0) $o['bos_brut']++;
        if ($n <= 0) $o['bos_net']++;
        $o['brut'] += $b; $o['net'] += $n;
        $k = trim((string)($p['kalibre'] ?? ''));
        if ($k !== '') $kal[frgi_kalibre_say($k)] = true;
    }
    $o['var'] = $o['palet'] > 0;
    $o['brut'] = round($o['brut'], 3); $o['net'] = round($o['net'], 3);
    $o['kalibreler'] = array_map('strval', array_keys($kal));
    return $o;
}

/** "FRESH POMEGRANATE 10 PC." — kalibre palet satırlarından, yoksa PO boy'undan (x14 → 14 PC.). */
function frgi_aciklama_varsayilan(array $s, array $pkl): string
{
    $kal = $pkl['kalibreler'] !== [] ? siparis_inv_kalibre_aralik($pkl['kalibreler']) : '';
    if ($kal === '') {
        $boy = frgi_kalibre_say((string)($s['boy'] ?? ''));
        $kal = $boy !== '' ? $boy . ' PC.' : '';
    }
    return (string)siparis_kirp(trim(frgi_urun_ad((string)($s['urun'] ?? '')) . ' ' . $kal), 120);
}

/**
 * Sipariş + PKL'den form varsayılanı ve salt okunur özet. Kayıtlı girdi (hks_kv) varsa form onunla dolar; fiyat boşsa siparişteki fiyat ön-dolar.
 * @return array{girdi:array<string,string>,ozet:array<string,mixed>}
 */
function frgi_varsayilan(PDO $pdo, array $s): array
{
    $sid = (int)($s['id'] ?? 0);
    $pkl = frgi_pkl_ozet($s);
    $sipKutu = frg_hesap($s)['kutu'];
    $fSip = isset($s['fiyat_kutu_birim']) && $s['fiyat_kutu_birim'] !== null && $s['fiyat_kutu_birim'] !== '' ? (int)$s['fiyat_kutu_birim'] : null;
    $pbSip = strtoupper(trim((string)($s['para_birimi'] ?? '')));
    $girdi = ['no' => '', 'tarih' => date('Y-m-d'), 'para_birimi' => isset(frgi_para_birimleri()[$pbSip]) ? $pbSip : '',
              'fiyat' => $fSip !== null ? siparis_inv_fiyat_metin($fSip / 10000) : '', 'kutu' => '', 'palet' => '', 'net_kg' => '', 'brut_kg' => '',
              'incoterm' => '', 'plaka' => '', 'aciklama' => '', 'agirlik_onay' => ''];
    $kayitli = $sid > 0 ? frgi_girdi_oku($pdo, $sid) : [];
    foreach (array_keys($girdi) as $k) if (isset($kayitli[$k]) && is_scalar($kayitli[$k])) $girdi[$k] = (string)$kayitli[$k];
    $hasat = trim((string)($s['hasat_yili'] ?? ''));
    if ($hasat === '' && !empty($s['cikis_tarihi'])) $hasat = substr((string)$s['cikis_tarihi'], 0, 4);
    $ozet = [
        'po_no' => (string)($s['po_no'] ?? ''), 'harvest' => $hasat, 'kaynak' => $pkl['var'] ? 'pkl' : 'siparis',
        'palet' => $pkl['var'] ? $pkl['palet'] : (int)($s['palet'] ?? 0), 'kutu' => $pkl['var'] ? $pkl['kutu'] : $sipKutu,
        'palet_pkl' => $pkl['palet'], 'kutu_pkl' => $pkl['kutu'], 'pkl_var' => $pkl['var'],
        'net_pkl_kg' => (int)round($pkl['net']), 'brut_pkl_kg' => (int)round($pkl['brut']), 'bos_net' => $pkl['bos_net'], 'bos_brut' => $pkl['bos_brut'],
        'kutu_siparis' => $sipKutu, 'palet_siparis' => (int)($s['palet'] ?? 0),
        'aciklama' => frgi_aciklama_varsayilan($s, $pkl), 'plaka' => trim((string)($s['plaka'] ?? '')),
        'incoterm' => strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($s['incoterm'] ?? ''))),
        'fiyat_siparis_1e4' => $fSip, 'son_musteri' => (string)($s['son_musteri'] ?? ''),
    ];
    return ['girdi' => $girdi, 'ozet' => $ozet];
}

/** Girdideki override'larla efektif değerler (HTML + hesap + kontrol bunu kullanır). */
function frgi_etkin(array $veri, array $ozet): array
{
    $sayi = static fn($o, $d): int => ($o !== '' && $o !== null && is_numeric($o)) ? (int)$o : (int)$d;
    $net = $sayi($veri['net_kg'] ?? '', $ozet['net_pkl_kg'] ?? 0);
    $brut = $sayi($veri['brut_kg'] ?? '', $ozet['brut_pkl_kg'] ?? 0);
    return [
        'kutu' => $sayi($veri['kutu'] ?? '', $ozet['kutu'] ?? 0), 'palet' => $sayi($veri['palet'] ?? '', $ozet['palet'] ?? 0),
        'net_kg' => $net, 'brut_kg' => $brut,
        'aciklama' => trim((string)($veri['aciklama'] ?? '')) !== '' ? (string)$veri['aciklama'] : (string)($ozet['aciklama'] ?? ''),
        'plaka' => trim((string)($veri['plaka'] ?? '')) !== '' ? (string)$veri['plaka'] : (string)($ozet['plaka'] ?? ''),
        'incoterm' => trim((string)($veri['incoterm'] ?? '')) !== '' ? (string)$veri['incoterm'] : (string)($ozet['incoterm'] ?? ''),
        'net_farkli' => ($ozet['pkl_var'] ?? false) && $net !== (int)($ozet['net_pkl_kg'] ?? 0),
        'brut_farkli' => ($ozet['pkl_var'] ?? false) && $brut !== (int)($ozet['brut_pkl_kg'] ?? 0),
    ];
}

/**
 * Tutar — TEK hesap yeri (frg_hesap): kutu × fiyat (on-binde) → kuruş, yarım yukarı. Frugro faturasında iskonto yoktur: toplam = tutar.
 * Örnekler: 5200 × 7,00 £ = 36.400,00; 5280 × 5,90 = 31.152,00; 5016 × 5,80 = 29.092,80.
 * @return array{tutar_kurus:int,toplam_kurus:int,tutar:float,toplam:float}
 */
function frgi_hesapla(array $veri, array $ozet): array
{
    $e = frgi_etkin($veri, $ozet);
    $f1e4 = (int)round((siparis_inv_ondalik_coz($veri['fiyat'] ?? 0, false) ?? 0.0) * 10000);
    $h = frg_hesap(['kutu' => $e['kutu'], 'fiyat_kutu_birim' => $f1e4]);
    return ['tutar_kurus' => $h['tutar_kurus'], 'toplam_kurus' => $h['tutar_kurus'], 'tutar' => $h['tutar_kurus'] / 100.0, 'toplam' => $h['tutar_kurus'] / 100.0];
}

// ── GİRDİ OKU / YAZ ─────────────────────────────────────────────────────

/** Kayıtlı girdi (hks_kv) — yoksa []. */
function frgi_girdi_oku(PDO $pdo, int $sid): array
{
    return siparis_inv_kv_oku($pdo, FRGI_GIRDI_KV_ON . $sid) ?? [];
}

/**
 * Fatura no başka bir Frugro faturasında (kayıtlı girdi ya da siparişin fatura_no'su) kullanılıyor mu? Büyük/küçük harf ve boşluk duyarsız.
 * @return ?int kullanan sipariş id'si (yoksa null)
 */
function frgi_no_kullanan(PDO $pdo, string $no, int $haricSid): ?int
{
    $n = strtoupper(preg_replace('/\s+/', '', $no));
    if ($n === '') return null;
    try {
        $st = $pdo->query("SELECT anahtar, deger FROM `" . siparis_evrak_mail_kv_tablo() . "` WHERE anahtar LIKE 'frugro!_invoice!_%' ESCAPE '!'");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!preg_match('/^frugro_invoice_(\d+)$/', (string)$r['anahtar'], $m) || (int)$m[1] === $haricSid) continue;
            $j = json_decode((string)$r['deger'], true);
            if (is_array($j) && strtoupper(preg_replace('/\s+/', '', (string)($j['no'] ?? ''))) === $n) return (int)$m[1];
        }
    } catch (Throwable $e) { /* hks_kv yok → yalnız sipariş kolonuna bak */ }
    $st = $pdo->prepare('SELECT id, fatura_no FROM frugro_siparisler WHERE id <> ? AND fatura_no IS NOT NULL AND fatura_no <> \'\'');
    $st->execute([$haricSid]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (strtoupper(preg_replace('/\s+/', '', (string)$r['fatura_no'])) === $n) return (int)$r['id'];
    }
    return null;
}

// ── ÇAPRAZ KONTROLLER ───────────────────────────────────────────────────

/**
 * Üretim öncesi denetim. seviye 'hata' → üretilmez; kod 'girdi_*' = formda doldurulacak/düzeltilecek alan (Oluştur'u kilitlemez, sunucu yine reddeder);
 * 'uyari' / 'bilgi' engel DEĞİL (ayar eksikliği ayrıca "eksik bilgiyle oluştur" onayı ister).
 * @return list<array{seviye:string,mesaj:string,kod:string}>
 */
function frgi_kontrol(PDO $pdo, array $s, array $veri, array $ozet, array $ayar): array
{
    $k = [];
    $ekle = function (string $sv, string $kod, string $mesaj) use (&$k): void { $k[] = ['seviye' => $sv, 'mesaj' => $mesaj, 'kod' => $kod]; };
    $t = frgi_girdi_temizle($veri);
    foreach ($t['hata'] as $alan => $m) $ekle('hata', 'girdi_' . $alan, $m);
    $v = $t['veri'];
    if (!isset($t['hata']['no']) && $v['no'] !== '') {
        if (!preg_match('/^IHR\d{13}$/', $v['no'])) $ekle('uyari', 'no_bicim', 'Fatura numarası beklenen biçimde değil (IHR + 13 rakam, örn. IHR<yıl>000000451) — doğru yazıldığından emin olun.');
        $baska = frgi_no_kullanan($pdo, $v['no'], (int)($s['id'] ?? 0));
        if ($baska !== null) $ekle('hata', 'no_tekrar', 'Bu fatura numarası başka bir Frugro faturasında kullanılıyor.');
    }
    $e = frgi_etkin($v, $ozet);
    $kg = static fn(int|float $x): string => number_format((float)$x, 0, ',', '.');
    if ($e['net_kg'] <= 0) $ekle('hata', 'net_yok', 'Net ağırlık 0 — PKL palet satırlarında net kg dolu olmalı ya da net kg elle girilmelidir.');
    if ($e['brut_kg'] > 0 && $e['brut_kg'] < $e['net_kg']) $ekle('hata', 'brut_net', 'Brüt ağırlık (' . $kg($e['brut_kg']) . ' kg) net ağırlıktan (' . $kg($e['net_kg']) . ' kg) küçük olamaz.');
    if ($e['kutu'] <= 0) $ekle('hata', 'kutu_yok', 'Kutu adedi bulunamadı (PKL ya da sipariş).');
    if (!$ozet['pkl_var']) $ekle('uyari', 'pkl_yok', 'PKL (palet satırları) yok: kutu / palet sipariş değerinden alındı, net / brüt ağırlık PKL\'den gelmiyor. Önce PKL girin — Frugro şartı: net / brüt tüm evraklarda aynı olmalı.');
    foreach (['net' => ['bos_net', 'net'], 'brut' => ['bos_brut', 'brüt']] as [$sayK, $ad]) {
        if ($ozet['pkl_var'] && (int)($ozet[$sayK] ?? 0) > 0) $ekle('uyari', 'pkl_bos_' . substr($sayK, 4), 'PKL\'de ' . (int)$ozet[$sayK] . ' palet satırının ' . $ad . ' ağırlığı boş ya da 0 — ' . $ad . ' toplamı güvenilmez.');
    }
    if ($e['net_farkli'] || $e['brut_farkli']) {
        $ekle('uyari', 'agirlik_fark', 'PKL ile farklı: ' . ($e['net_farkli'] ? 'net ' . $kg($e['net_kg']) . ' kg (PKL ' . $kg($ozet['net_pkl_kg']) . ')' : '')
            . ($e['net_farkli'] && $e['brut_farkli'] ? ', ' : '') . ($e['brut_farkli'] ? 'brüt ' . $kg($e['brut_kg']) . ' kg (PKL ' . $kg($ozet['brut_pkl_kg']) . ')' : '')
            . '. Frugro şartı: net / brüt tüm evraklarda (PKL, fatura, beyanname) aynı olmalı.');
        if ($v['agirlik_onay'] !== '1') $ekle('hata', 'girdi_agirlik_onay', 'Net / brüt ağırlık PKL\'den farklı: bilinçli olduğunu "PKL\'den farklı ağırlığı onaylıyorum" kutusuyla onaylayın (ya da alanları boş bırakıp PKL toplamını kullanın).');
    }
    if ($ozet['pkl_var'] && $e['kutu'] !== (int)$ozet['kutu_pkl']) $ekle('uyari', 'kutu_fark', 'Fatura kutu adedi (' . $kg($e['kutu']) . ') PKL kutu toplamından (' . $kg($ozet['kutu_pkl']) . ') farklı.');
    if ($ozet['pkl_var'] && $e['palet'] !== (int)$ozet['palet_pkl']) $ekle('uyari', 'palet_fark', 'Fatura palet adedi (' . $kg($e['palet']) . ') PKL palet sayısından (' . $kg($ozet['palet_pkl']) . ') farklı.');
    if (trim($e['plaka']) === '') $ekle('uyari', 'plaka_yok', 'Plaka bilgisi yok (TRUCK / TIR alanı boş basılacak).');
    if ($e['incoterm'] === '') $ekle('uyari', 'incoterm_yok', 'Incoterm koşulu boş (siparişte ve faturada yok) — "INCOTERMS 2020 ______" basılır.');
    if (trim($e['aciklama']) === '') $ekle('uyari', 'aciklama_bos', 'Ürün açıklaması boş.');
    $eksik = frgi_ayar_eksikler($ayar, $v['para_birimi']);
    if ($eksik) $ekle('uyari', 'ayar_eksik', 'Frugro fatura ayarında eksik: ' . implode(', ', $eksik) . ' — PDF\'te "' . FRGI_YER_TUTUCU . '" basılır.');
    $f1 = $ozet['fiyat_siparis_1e4'];
    if ($t['ok'] || $v['fiyat'] !== '') {
        $fatura1e4 = (int)round((siparis_inv_ondalik_coz($v['fiyat'], false) ?? 0.0) * 10000);
        if ($v['fiyat'] !== '' && $f1 !== null && $f1 !== $fatura1e4) {
            $ekle('bilgi', 'fiyat_fark', 'Fatura fiyatı (' . frg_fiyat_metin($fatura1e4) . ') siparişteki fiyattan (' . frg_fiyat_metin((int)$f1) . ') farklı.');
        }
    }
    return $k;
}

// ── HTML / PDF ──────────────────────────────────────────────────────────

/** IBAN'ı 4'lü gruplar: "TR38 0013 …". */
function frgi_iban_goster(string $iban): string
{
    $s = strtoupper((string)preg_replace('/\s+/', '', $iban));
    return $s === '' ? '' : trim(chunk_split($s, 4, ' '));
}

/**
 * Üretimde kaydedilen, PDF'in TEK girdisi: sipariş + girdi + efektif değerler birleşimi (PKL sonradan değişse de PDF aynı kalır).
 * @return array<string,mixed>
 */
function frgi_snapshot(array $veri, array $ozet): array
{
    $e = frgi_etkin($veri, $ozet);
    return ['po_no' => (string)$ozet['po_no'], 'harvest' => (string)$ozet['harvest'], 'no' => (string)$veri['no'], 'tarih' => (string)$veri['tarih'],
            'para_birimi' => (string)$veri['para_birimi'], 'fiyat' => (string)$veri['fiyat'], 'kutu' => $e['kutu'], 'palet' => $e['palet'],
            'net_kg' => $e['net_kg'], 'brut_kg' => $e['brut_kg'], 'aciklama' => $e['aciklama'], 'plaka' => $e['plaka'], 'incoterm' => $e['incoterm']];
}

/**
 * Fatura şablonu (dompdf uyumlu, A4 dikey, tek sayfa). $snap = frgi_snapshot() çıktısı; her değer h() ile kaçırılır.
 * Eksik ayar değeri "______" basılır. Menşe notu KOŞULSUZ.
 */
function frgi_html(array $snap, array $ayar): string
{
    $pb = isset(frgi_para_birimleri()[(string)($snap['para_birimi'] ?? '')]) ? (string)$snap['para_birimi'] : 'GBP';
    $sem = frgi_para_birimleri()[$pb]['sembol']; $bas = frgi_para_birimleri()[$pb]['baslik'];
    $hes = frgi_hesapla(['fiyat' => (string)($snap['fiyat'] ?? '0')], ['kutu' => (int)($snap['kutu'] ?? 0)] + ['palet' => 0, 'net_pkl_kg' => 0, 'brut_pkl_kg' => 0, 'pkl_var' => false]);
    $yt = static fn(string $v): string => trim($v) !== '' ? h($v) : FRGI_YER_TUTUCU;
    $kgG = static fn($v): string => (int)$v > 0 ? number_format((int)$v, 0, ',', '.') . ' KG' : '—';
    $inc = (string)($snap['incoterm'] ?? '');
    $iban = $pb === 'EUR' ? (string)($ayar['iban_eur'] ?? '') : (string)($ayar['iban_gbp'] ?? '');
    $satirlar = [
        ['PO', $yt((string)($snap['po_no'] ?? ''))],
        ['HARVEST', $yt((string)($snap['harvest'] ?? ''))],
        ['HS CODE', h(SIPARIS_INV_HS_KODU)],
        ['TRADE MARK', h(SIPARIS_INV_MARKA)],
        ['DELIVERY TERMS', 'INCOTERMS 2020 ' . ($inc !== '' ? h($inc) : FRGI_YER_TUTUCU)],
        ['BANK DETAILS', $yt((string)($ayar['banka_ad'] ?? ''))],
        ['BANK ADDRESS', $yt((string)($ayar['banka_adres'] ?? ''))],
        ['BRANCH', $yt((string)($ayar['sube'] ?? ''))],
        ['SWIFT CODE', $yt((string)($ayar['swift'] ?? ''))],
        ['ACCOUNT IBAN ' . $pb, $yt(frgi_iban_goster($iban))],
        ['COUNTRY OF ORIGIN', 'TURKEY'],
        ['TRUCK / TIR', $yt((string)($snap['plaka'] ?? ''))],
    ];
    ob_start();
    ?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Invoice</title>
<style>
@page { margin: 26pt 30pt 24pt 30pt; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 7.6pt; color: #000; margin: 0; }
table { border-collapse: collapse; }
.baslik { font-family: 'DejaVu Serif', serif; font-size: 20pt; font-weight: bold; text-align: center; margin: 8pt 0 6pt 0; }
.no { width: 100%; font-size: 8pt; margin-bottom: 7pt; }
.cerceve { width: 100%; border: 0.9pt solid #000; }
.cerceve td { vertical-align: top; width: 50%; padding: 4pt 6pt 6pt 6pt; height: 92pt; }
.cerceve td.sol { border-right: 0.9pt solid #000; }
.et { font-size: 6.8pt; }
.ad { font-size: 8.4pt; font-weight: bold; margin: 8pt 0 6pt 0; line-height: 1.25; }
.adr { font-size: 6.6pt; line-height: 1.8; }
.kalem { width: 100%; border: 0.9pt solid #000; border-top: 0; }
.kalem th { font-size: 5.6pt; font-weight: normal; text-align: center; padding: 5pt 2pt; border-left: 0.5pt solid #000; border-bottom: 0.5pt solid #000; line-height: 1.25; }
.kalem td { font-size: 6.8pt; text-align: center; vertical-align: middle; padding: 7pt 3pt; border-left: 0.5pt solid #000; }
.kalem .first { border-left: 0; }
.kalem .b { font-weight: bold; }
.kalem tr.tpl td { border-top: 0.5pt solid #000; padding: 6pt 3pt; font-size: 7.2pt; }
.meta { width: 100%; margin-top: 16pt; }
.meta td { font-size: 7.6pt; padding: 3.2pt 0; vertical-align: top; text-align: left; }
.meta td.e { width: 130pt; padding-left: 20pt; }
.meta td.d { width: 10pt; }
.meta td.v { font-weight: bold; }
.notlar { margin: 16pt 20pt 0 20pt; font-size: 7.4pt; line-height: 1.4; }
.alt { margin: 18pt 20pt 0 20pt; font-size: 5.4pt; line-height: 1.5; }
.ayak { position: fixed; bottom: 0; left: 0; right: 0; text-align: center; font-size: 5.8pt; line-height: 1.6; }
</style></head><body>
<div class="baslik">INVOICE</div>
<table class="no"><tr><td>INVOICE NUMBER: <b><?= h((string)($snap['no'] ?? '')) ?></b></td><td style="text-align:right">INVOICE DATE: <b><?= h(siparis_inv_tarih_goster((string)($snap['tarih'] ?? ''))) ?></b></td></tr></table>
<table class="cerceve"><tr>
<td class="sol"><div class="et">EXPORTER</div>
<div class="ad">ASYA FRESH SEBZE MEYVE NAKLİYAT<br>VE TİCARET LİMİTED ŞİRKETİ</div>
<div class="adr"><b>Address</b><br>Hançerli Mahallesi Dervişzade Sk. No: 12 İç Kapı No:3<br>İlkadım / SAMSUN / TURKEY<br>Gaziler Vergi Dairesi VAT: <?= $yt((string)($ayar['vat_ihracatci'] ?? '')) ?></div></td>
<td><div class="et">Buyer / Consignee</div>
<div class="ad">FRUGRO LTD.</div>
<div class="adr"><b>Address</b><br><?= h(FRGI_ALICI_ADRES) ?><br>VAT nº: <?= $yt((string)($ayar['vat_alici'] ?? '')) ?></div></td>
</tr></table>
<table class="kalem">
<tr><th class="first" style="width:22pt">NO</th><th style="width:44pt">Q-TY OF<br>PALLETS</th><th style="width:52pt">Q-TY OF<br>BOXES</th><th style="width:44pt">PACKING</th><th>DESCRIPTION OF GOODS</th><th style="width:50pt">GROSS<br>WEIGHT</th><th style="width:50pt">NET<br>WEIGHT</th><th style="width:44pt">BOX PRICE<br><?= h($bas) ?></th><th style="width:58pt">TOTAL<br><?= h($bas) ?></th></tr>
<tr><td class="first">1</td><td><?= h((string)(int)($snap['palet'] ?? 0)) ?></td><td class="b"><?= h(number_format((int)($snap['kutu'] ?? 0), 0, ',', '.')) ?></td>
<td>CARTON<br>BOXES</td>
<td class="b"><?= h((string)($snap['aciklama'] ?? '')) ?></td><td><?= h($kgG($snap['brut_kg'] ?? 0)) ?></td><td><?= h($kgG($snap['net_kg'] ?? 0)) ?></td>
<td class="b"><?= h($sem . siparis_inv_fiyat_goster((string)($snap['fiyat'] ?? '0'))) ?></td><td class="b"><?= h($sem . siparis_inv_tutar_goster((float)$hes['tutar'])) ?></td></tr>
<tr class="tpl"><td class="first" colspan="7"></td><td style="text-align:right">Total</td><td class="b"><?= h($sem . siparis_inv_tutar_goster((float)$hes['toplam'])) ?></td></tr>
</table>
<table class="meta">
<?php foreach ($satirlar as [$e, $v]): ?>
<tr><td class="e"><?= h($e) ?></td><td class="d">:</td><td class="v"><?= $v ?></td></tr>
<?php endforeach; ?>
</table>
<div class="notlar"><b>NOTES:</b> "<?= h(FRGI_MENSE_NOTU) ?>"</div>
<div class="alt">The Importer shall discharge the goods immediately upon arrival to the delivery address. The shipment shall be immediately inspected upon arrival. The goods belong to <b>Asya Fresh</b> (the exporter) until the inspection results agreed between both parties. Out of this period without above mentioned conditions no claim can be accepted.</div>
<div class="ayak">Address: HANÇERLİ MAH. DERVİŞZADE SK.NO:12 İÇ KAPI NO:3 İLKADIM - SAMSUN - TÜRKİYE<br>Phone: +90 362 465 54 54 &nbsp;&nbsp;&nbsp; Website: www.asyafresh.com</div>
</body></html><?php
    return (string)ob_get_clean();
}

/** INV-<fatura no>-<PO>.pdf (parçalar [A-Za-z0-9.-] ile temizlenir). */
function frgi_dosya_adi(string $no, string $po): string
{
    $tem = static fn(string $x): string => trim((string)preg_replace('/[^A-Za-z0-9.\-]/', '', $x), '._-');
    return implode('-', array_values(array_filter(['INV', $tem($no), $tem($po)], static fn($x) => $x !== ''))) . '.pdf';
}

// ── ÜRETİM / İNDİRME ────────────────────────────────────────────────────

/** Kayıtlı (üretilmiş) faturadan taze PDF — dosya SAKLANMAZ. Henüz üretilmediyse null. @return array{pdf:string,dosya_adi:string}|null */
function frgi_pdf_uret(PDO $pdo, int $sid): ?array
{
    $g = frgi_girdi_oku($pdo, $sid);
    if (empty($g['uretildi_at']) || !is_array($g['snap'] ?? null)) return null;
    $pdf = siparis_inv_pdf(frgi_html((array)$g['snap'], frgi_ayar_etkin($pdo)));
    if (strncmp($pdf, '%PDF', 4) !== 0) throw new RuntimeException('PDF üretilemedi.');
    return ['pdf' => $pdf, 'dosya_adi' => frgi_dosya_adi((string)($g['snap']['no'] ?? ''), (string)($g['snap']['po_no'] ?? ''))];
}

/** Üretim yapmadan yalnız girdiyi kaydeder (taslak). Üretim izlerini (snap / uretildi_at) KORUR. @return array{ok:bool,hata?:string,hatalar?:array<string,string>} */
function frgi_girdi_kaydet(PDO $pdo, int $sid, int $uid, array $veri): array
{
    $t = frgi_girdi_temizle($veri);
    if (!$t['ok']) return ['ok' => false, 'hata' => 'Fatura bilgilerinde eksik ya da hatalı alan var.', 'hatalar' => $t['hata']];
    $kayitli = frgi_girdi_oku($pdo, $sid);
    $baska = frgi_no_kullanan($pdo, $t['veri']['no'], $sid);
    if ($baska !== null) return ['ok' => false, 'hata' => 'Bu fatura numarası başka bir Frugro faturasında kullanılıyor.', 'hatalar' => ['no' => 'Bu fatura numarası başka bir Frugro faturasında kullanılıyor.']];
    $yeni = $t['veri'];
    foreach (['uretildi_at', 'snap', 'toplam_kurus'] as $k) if (array_key_exists($k, $kayitli)) $yeni[$k] = $kayitli[$k];
    if (!siparis_inv_kv_yaz($pdo, FRGI_GIRDI_KV_ON . $sid, $yeni)) return ['ok' => false, 'hata' => 'Fatura bilgileri kaydedilemedi (hks_kv).'];
    if (function_exists('audit_log_event')) audit_log_event('frugro_invoice_kaydet', 'frugro_siparis', $sid, null, ['invoice_no' => $t['veri']['no']], $uid);
    return ['ok' => true];
}

/**
 * Faturayı üretir (girdi + anlık görüntü kaydı, olay, durum, audit) — TEK transaction. PDF diske YAZILMAZ (frgi_pdf_uret taze üretir).
 * 'hata' seviyesinde kontrol varsa ÜRETMEZ; ayar eksikse yalnız $eksikIzin=true ile üretir. Olay 'invoice_uretildi'; durum yalnız
 * pkl_hazir → invoice_hazir. Depo / yetki kapıları SAYFADA.
 * @return array{ok:bool,hata?:string,dosya_adi?:string,uyarilar:list<array>,toplam_kurus?:int}
 */
function frgi_uret(PDO $pdo, int $sid, int $uid, array $veri, bool $eksikIzin): array
{
    $sonuc = ['ok' => false, 'uyarilar' => []];
    $sahip = !$pdo->inTransaction();
    if ($sahip) $pdo->beginTransaction();
    try {
        $s = frg_getir($pdo, $sid);
        if ($s === null) throw new RuntimeException('Sipariş bulunamadı.');
        if (!frgi_yazilabilir($s)) throw new RuntimeException('Bu siparişin durumu (' . (frg_durumlar()[(string)$s['durum']] ?? (string)$s['durum']) . ') fatura üretimine kapalı.');
        $t = frgi_girdi_temizle($veri);
        if (!$t['ok']) throw new RuntimeException(implode(' ', $t['hata']));
        $veri = $t['veri'];
        $ayar = frgi_ayar_etkin($pdo);
        $ozet = frgi_varsayilan($pdo, $s)['ozet'];
        $kontrol = frgi_kontrol($pdo, $s, $veri, $ozet, $ayar);
        $sonuc['uyarilar'] = $kontrol;
        $hatalar = array_values(array_filter($kontrol, static fn($x) => $x['seviye'] === 'hata'));
        if ($hatalar) throw new RuntimeException(implode(' ', array_column($hatalar, 'mesaj')));
        $eksik = frgi_ayar_eksikler($ayar, $veri['para_birimi']);
        if ($eksik && !$eksikIzin) throw new RuntimeException('Fatura ayarında eksik alan var (' . implode(', ', $eksik) . ') — ayarı tamamlayın ya da "eksik bilgiyle oluştur"u onaylayın.');

        $snap = frgi_snapshot($veri, $ozet);
        $hes = frgi_hesapla($veri, $ozet);
        $pdf = siparis_inv_pdf(frgi_html($snap, $ayar));                    // üretilebildiğini doğrular; saklanmaz
        if (strncmp($pdf, '%PDF', 4) !== 0) throw new RuntimeException('PDF üretilemedi.');
        $simdi = date('Y-m-d H:i:s');
        if (!siparis_inv_kv_yaz($pdo, FRGI_GIRDI_KV_ON . $sid, $veri + ['uretildi_at' => $simdi, 'snap' => $snap, 'toplam_kurus' => $hes['toplam_kurus']])) {
            throw new RuntimeException('Fatura bilgileri kaydedilemedi (hks_kv).');
        }
        $pdo->prepare('UPDATE frugro_siparisler SET fatura_no = ?, para_birimi = ?, updated_at = ? WHERE id = ?')->execute([$veri['no'], $veri['para_birimi'], $simdi, $sid]);
        $terfi = (string)$s['durum'] === 'pkl_hazir';
        if ($terfi) $pdo->prepare("UPDATE frugro_siparisler SET durum = 'invoice_hazir' WHERE id = ? AND durum = 'pkl_hazir'")->execute([$sid]);
        $sem = frgi_para_birimleri()[$veri['para_birimi']]['sembol'];
        $dosyaAdi = frgi_dosya_adi($veri['no'], (string)$s['po_no']);
        frg_olay_yaz($pdo, $sid, 'invoice_uretildi', $veri['no'] . ' · ' . $sem . ' ' . siparis_inv_tutar_goster((float)$hes['toplam']) . ($eksik ? ' (ayar eksik: ' . implode(', ', $eksik) . ')' : ''), $uid);
        if ($terfi) frg_olay_yaz($pdo, $sid, 'durum', 'PKL hazır → Invoice hazır', $uid);
        if (function_exists('audit_log_event')) {
            audit_log_event('frugro_invoice_uret', 'frugro_siparis', $sid, null, ['invoice_no' => $veri['no'], 'toplam_kurus' => $hes['toplam_kurus'], 'para_birimi' => $veri['para_birimi']], $uid);
        }
        if ($sahip) $pdo->commit();
        return ['ok' => true, 'dosya_adi' => $dosyaAdi, 'uyarilar' => $kontrol, 'toplam_kurus' => $hes['toplam_kurus']];
    } catch (Throwable $e) {
        if ($sahip && $pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) {
            $sonuc['hata'] = $e->getMessage();
        } else {
            error_log('[frgi_uret] ' . get_class($e) . ': ' . $e->getMessage());
            $sonuc['hata'] = 'Fatura oluşturulamadı.';
        }
        if (!$sahip) throw $e;
        return $sonuc;
    }
}
