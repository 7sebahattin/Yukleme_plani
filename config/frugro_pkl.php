<?php
// =========================================================
// config/frugro_pkl.php — Frugro PACKING LIST (PKL), palet bazlı (include-only; önek frgp_)
//
// Çekirdek: config/frugro_core.php (frugro_siparisler / frugro_palet / frugro_olaylar; frg_getir, frg_palet_kaydet, frg_olay_yaz).
// Bu dosya: satır doğrulama, SAF toplamlar, taslak üretimi, Excel yapıştırma ayrıştırıcı, PKL başlığı kaydı, PDF (dompdf) + XLSX.
// Şema DEĞİŞMEZ (yeni kolon yok; "çıkış saati" kayda girmez, yalnız PDF/XLSX üretiminde parametre olarak taşınır).
//
// SAYI KURALI (OGL num() ile aynı, 100x/1000x hata riski): kg alanlarında nokta = BİNLİK, virgül = ONDALIK.
//   "1.234" = 1234 · "634,5" = 634,5 · "812.5" REDDEDİLİR (nokta-ondalık). Karton/palet-diğer kg (küçük sayılar) yalnız virgül kabul eder.
// KG ekranda TAM SAYI; hesap ham (yuvarlama yalnız gösterimde). Net HER ZAMAN elle girilebilir; frgp_net_oneri() yalnız ÖNERİDİR.
// Frugro kuralı: net/brüt tüm evraklarda AYNI → PKL kilidi: yalnız 'yeni' / 'hazirlik' / 'pkl_hazir' durumunda düzenlenir.
// Dosya diske YAZILMAZ: PDF/XLSX her istekte taze üretilir (frgp_uret).
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

const FRGP_MAX_SATIR = 200;
const FRGP_GGN_KV = 'frugro_ggn_uretici';          // hks_kv: {"liste":[{"ad","ggn"}]} — GGN adı + no YALNIZ frugro_ayarlar.php#ggn'de tanımlanır
const FRGP_EK_KV_ON = 'frugro_pkl_ek_';            // hks_kv: frugro_pkl_ek_<id> = {"logger_palet":"4","antet":true} (60 karakter sınırı içinde)
const FRGP_GGN_MAX = 100;
const FRGP_LOGO = 'assets/asyafresh-logo.png';
const FRGP_AYAK_ADRES = 'HANÇERLİ MAH. DERVİŞZADE SK.NO:12 İÇ KAPI NO:3 İLKADIM - SAMSUN - TÜRKİYE';   // frugro_invoice.php ayağıyla aynı metin (o dosyaya dokunulmaz)
const FRGP_AYAK_ILETISIM = 'Phone: +90 362 465 54 54 &nbsp;&nbsp; E-mail: contact@asyafresh.com &nbsp;&nbsp; Website: www.asyafresh.com';

/** PKL düzenlenebilir durumlar (sonrası kilitli — evrakların net/brüt'ü aynı kalmalı). */
function frgp_duzenlenebilir(array $s): bool
{
    return in_array((string)($s['durum'] ?? ''), ['yeni', 'hazirlik', 'pkl_hazir'], true);
}

// ── Sayı çözücüler ───────────────────────────────────────────────────────────

/**
 * Palet kg (brüt/net) — TR kuralı. @return float|null|false  null = boş, false = geçersiz.
 * Kabul: 634 · 1.234 (=1234) · 634,5 · 1.234,567 (en çok 3 ondalık). Nokta-ondalık ("812.5") ve işaretler REDDEDİLİR.
 */
function frgp_kg_coz($v)
{
    if ($v === null || $v === '') return null;
    if (is_int($v)) return $v >= 0 && $v < 10000000 ? (float)$v : false;
    if (is_float($v)) return is_finite($v) && $v >= 0 && $v < 10000000 ? $v : false;
    if (!is_string($v)) return false;
    $s = str_replace([' ', "\xc2\xa0", "\t"], '', $v);
    if ($s === '') return null;
    if (preg_match('/^\d+$/', $s)) $f = (float)$s;
    elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) $f = (float)str_replace('.', '', $s);
    elseif (preg_match('/^(\d+|\d{1,3}(\.\d{3})+),\d{1,3}$/', $s)) $f = (float)str_replace(',', '.', str_replace('.', '', $s));
    else return false;
    return $f < 10000000 ? $f : false;
}

/** Kutu adedi: pozitif TAM sayı ("160", "1.200"). @return int|null|false */
function frgp_kutu_coz($v)
{
    if ($v === null || $v === '') return null;
    if (is_int($v)) return $v > 0 && $v <= 1000000 ? $v : false;
    if (!is_string($v)) return false;
    $s = str_replace([' ', "\xc2\xa0", "\t"], '', $v);
    if ($s === '') return null;
    if (preg_match('/^\d+$/', $s)) $n = (int)$s;
    elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) $n = (int)str_replace('.', '', $s);
    else return false;
    return $n > 0 && $n <= 1000000 ? $n : false;
}

/** Küçük ondalık (karton kg, palet-diğer kg): YALNIZ virgül ondalık; nokta REDDEDİLİR (0.312 ≠ 312). @return float|null|false */
function frgp_kucuk_coz($v, int $maxOndalik, float $ust)
{
    if ($v === null || $v === '') return null;
    if (is_int($v) || is_float($v)) { $f = (float)$v; return is_finite($f) && $f >= 0 && $f <= $ust ? round($f, $maxOndalik) : false; }
    if (!is_string($v)) return false;
    $s = str_replace([' ', "\xc2\xa0", "\t"], '', $v);
    if ($s === '') return null;
    if (!preg_match('/^\d{1,4}(,\d{1,' . $maxOndalik . '})?$/', $s)) return false;
    $f = (float)str_replace(',', '.', $s);
    return $f <= $ust ? $f : false;
}

/** Düz metin alan: kontrol karakterleri atılır, kırpılır. */
function frgp_metin($v, int $uzun): ?string
{
    $m = frg_metin($v);
    return $m === null ? null : mb_substr($m, 0, $uzun);
}

// ── Gösterim ────────────────────────────────────────────────────────────────

/** KG ekranda TAM SAYI, binlik nokta ("20.995"); hesap ham kalır. */
function frgp_kg_goster($f): string
{
    return $f === null || $f === '' || !is_numeric($f) ? '' : number_format((int)round((float)$f), 0, ',', '.');
}

/** Küçük ondalık (0,312 / 25): sondaki sıfırlar atılır, virgül ondalık. */
function frgp_kucuk_goster($f): string
{
    if ($f === null || $f === '' || !is_numeric($f)) return '';
    $s = rtrim(rtrim(number_format((float)$f, 3, ',', ''), '0'), ',');
    return $s === '' ? '0' : $s;
}

/** Düzenleme alanı için kg: tam sayıysa "634", değilse virgüllü ("634,5"); binlik noktası YOK (geri okumada 1000x riski). */
function frgp_kg_alan($f): string
{
    if ($f === null || $f === '' || !is_numeric($f)) return '';
    $f = round((float)$f, 3);
    return $f == floor($f) ? (string)(int)$f : rtrim(number_format($f, 3, ',', ''), '0');
}

// ── Hesap (SAF) ─────────────────────────────────────────────────────────────

/**
 * Net ÖNERİSİ = brüt − (kutu × karton_kg + palet_diger_kg); alt sınır 0. Yalnız öneri — kayıtta net elle girilir.
 * 160 kutu × 0,312 + 25 = 74,92 → brüt 634 ⇒ 559,08 (≈ 559).
 */
function frgp_net_oneri(int $kutu, float $brut, float $kartonKg, float $paletDigerKg): float
{
    return round(max(0.0, $brut - ($kutu * $kartonKg + $paletDigerKg)), 3);
}

/**
 * Toplamlar (SAF; ham değerler). Satır: kutu, brut_kg|brut, net_kg|net. Tamamen boş satır sayılmaz.
 * @return array{kutu:int,brut:float,net:float,palet:int,eksik_brut:int,eksik_net:int}
 */
function frgp_toplam(array $satirlar): array
{
    $t = ['kutu' => 0, 'brut' => 0.0, 'net' => 0.0, 'palet' => 0, 'eksik_brut' => 0, 'eksik_net' => 0];
    foreach ($satirlar as $p) {
        $kutu = $p['kutu'] ?? null; $brut = $p['brut_kg'] ?? ($p['brut'] ?? null); $net = $p['net_kg'] ?? ($p['net'] ?? null);
        $bos = fn($x) => $x === null || $x === '';
        if ($bos($kutu) && $bos($brut) && $bos($net) && $bos($p['palet_no'] ?? null)) continue;
        $t['palet']++;
        $t['kutu'] += (int)($kutu ?? 0);
        if ($bos($brut)) $t['eksik_brut']++; else $t['brut'] += (float)$brut;
        if ($bos($net)) $t['eksik_net']++; else $t['net'] += (float)$net;
    }
    $t['brut'] = round($t['brut'], 3);
    $t['net'] = round($t['net'], 3);
    return $t;
}


// ── GGN listesi (genel) + sipariş başına PKL ekleri (logger palet, antet) — hks_kv; şema YOK ──

function frgp_kv_hazirla(): void
{
    if (!function_exists('siparis_inv_kv_oku')) require_once __DIR__ . '/siparis_invoice.php';
}

/** Ayardaki GGN listesi. Tablo yok / JSON bozuk → []. @return list<array{ad:string,ggn:string}> */
function frgp_ggn_liste(PDO $pdo): array
{
    try { frgp_kv_hazirla(); $j = siparis_inv_kv_oku($pdo, FRGP_GGN_KV); } catch (Throwable $e) { return []; }
    $out = [];
    foreach ((array)($j['liste'] ?? []) as $r) {
        if (!is_array($r)) continue;
        $ad = frgp_metin(is_scalar($r['ad'] ?? null) ? (string)$r['ad'] : '', 100); $no = frgp_metin(is_scalar($r['ggn'] ?? null) ? (string)$r['ggn'] : '', 20);
        if ($ad !== null && $no !== null) $out[] = ['ad' => $ad, 'ggn' => $no];
    }
    return array_slice($out, 0, FRGP_GGN_MAX);
}

/** @return array<string,string> ad => ggn */
function frgp_ggn_harita(array $liste): array
{
    $h = [];
    foreach ($liste as $r) $h[(string)$r['ad']] = (string)$r['ggn'];
    return $h;
}

/**
 * GGN listesini yazar (tümden değiştirir). Boş satır atlanır; ad + GGN zorunlu; aynı ad (büyük/küçük harf duyarsız) reddedilir.
 * Audit yalnız sayıyı yazar (ad / GGN DEĞERİ yazılmaz).
 * @return array{ok:bool,hata?:string}
 */
function frgp_ggn_kaydet(PDO $pdo, array $satirlar, int $uid): array
{
    $out = []; $gor = [];
    foreach (array_values($satirlar) as $i => $r) {
        if (!is_array($r)) continue;
        $ad = trim((string)(is_scalar($r['ad'] ?? null) ? $r['ad'] : '')); $no = trim((string)(is_scalar($r['no'] ?? ($r['ggn'] ?? null)) ? ($r['no'] ?? $r['ggn']) : ''));
        if ($ad === '' && $no === '') continue;
        $n = $i + 1;
        $adT = frgp_metin($ad, 100);
        if ($adT === null) return ['ok' => false, 'hata' => "Satır $n: GGN adı boş olamaz."];
        if (!preg_match('/^[A-Za-z0-9\-]{1,20}$/', $no)) return ['ok' => false, 'hata' => "Satır $n: GGN no yalnız harf, rakam ve tire içerebilir (en çok 20)."];
        $key = mb_strtolower($adT, 'UTF-8');
        if (isset($gor[$key])) return ['ok' => false, 'hata' => "Satır $n: aynı GGN adı ikinci kez girilemez."];
        $gor[$key] = true;
        $out[] = ['ad' => $adT, 'ggn' => $no];
        if (count($out) > FRGP_GGN_MAX) return ['ok' => false, 'hata' => 'En çok ' . FRGP_GGN_MAX . ' GGN kaydı girilebilir.'];
    }
    frgp_kv_hazirla();
    if (!siparis_inv_kv_yaz($pdo, FRGP_GGN_KV, ['liste' => $out])) return ['ok' => false, 'hata' => 'GGN listesi kaydedilemedi (ayar tablosu yok).'];
    audit_log_event('frugro_ggn_ayar', 'frugro_ayar', 0, null, ['sayi' => count($out)]);
    return ['ok' => true];
}

/** Sipariş PKL ekleri. Kayıt yoksa antet = true (varsayılan seçili). @return array{logger_palet:string,antet:bool} */
function frgp_ek_oku(PDO $pdo, int $sid): array
{
    $e = ['logger_palet' => '', 'antet' => true];
    try { frgp_kv_hazirla(); $j = siparis_inv_kv_oku($pdo, FRGP_EK_KV_ON . $sid); } catch (Throwable $x) { return $e; }
    if (!is_array($j)) return $e;
    $lp = is_scalar($j['logger_palet'] ?? null) ? trim((string)$j['logger_palet']) : '';
    if (preg_match('/^\d{1,3}$/', $lp)) $e['logger_palet'] = $lp;
    if (array_key_exists('antet', $j)) $e['antet'] = (bool)$j['antet'];
    return $e;
}

function frgp_ek_yaz(PDO $pdo, int $sid, array $ek): bool
{
    try { frgp_kv_hazirla(); } catch (Throwable $e) { return false; }
    $lp = trim((string)($ek['logger_palet'] ?? ''));
    return siparis_inv_kv_yaz($pdo, FRGP_EK_KV_ON . $sid, ['logger_palet' => preg_match('/^\d{1,3}$/', $lp) ? $lp : '', 'antet' => (bool)($ek['antet'] ?? true)]);
}

/** Logo: base64 data URI (dompdf uzak kaynak kapalı). Dosya yoksa null. */
function frgp_logo_uri(): ?string
{
    static $uri = false;
    if ($uri === false) {
        $f = dirname(__DIR__) . '/' . FRGP_LOGO;
        $b = is_file($f) ? @file_get_contents($f) : false;
        $uri = ($b !== false && $b !== '') ? 'data:image/png;base64,' . base64_encode($b) : null;
    }
    return $uri;
}

// ── Satır doğrulama ─────────────────────────────────────────────────────────

/**
 * Ham palet satırlarını (form/yapıştırma) doğrular ve temizler. Tamamen boş satır atlanır.
 * Palet no POST'tan ALINMAZ: sunucu sırayla 1..N yazar. $bag: kutu_palet:int (>0 ise her satıra o kutu), ggn:array<ad,ggn> (ayar),
 * eski:array<"ad\x1fggn",true> (kayıtlı eski çiftler — korunur), palet:int (siparişteki palet adedi; farklıysa UYARI).
 * Hatalar engeller (kaydedilmez); uyarılar engellemez.
 * @return array{satirlar:list<array<string,mixed>>,hatalar:list<string>,uyarilar:list<string>}
 */
function frgp_satirlari_dogrula(array $ham, array $bag = []): array
{
    $satirlar = []; $hatalar = []; $uyarilar = [];
    $kp = (int)($bag['kutu_palet'] ?? 0); $ayar = (array)($bag['ggn'] ?? []); $eski = (array)($bag['eski'] ?? []);
    $i = 0;
    foreach ($ham as $p) {
        if (!is_array($p)) continue;
        $i++;
        $no = $i;
        $alan = fn(string $k): string => is_scalar($p[$k] ?? null) ? trim((string)$p[$k]) : '';
        if (implode('', array_map($alan, ['konum', 'kalibre', 'lot', 'ggn_adi', 'kutu', 'brut_kg', 'net_kg'])) === '') continue;
        if (count($satirlar) >= FRGP_MAX_SATIR) { $hatalar[] = 'En çok ' . FRGP_MAX_SATIR . ' palet satırı girilebilir.'; break; }

        $paletNo = (string)(count($satirlar) + 1);
        $ad = frgp_metin($alan('ggn_adi'), 100); $ggn = frgp_metin($alan('ggn_no'), 20);
        if ($ggn !== null && !preg_match('/^[A-Za-z0-9\-]{1,20}$/', $ggn)) { $hatalar[] = "Satır $no: GGN no yalnız harf, rakam ve tire içerebilir."; $ggn = null; }
        $eskiMi = $ggn !== null && isset($eski[(string)$ad . "\x1f" . $ggn]);
        if ($ad !== null) {
            if (isset($ayar[$ad])) {
                if ($eskiMi && $ggn !== $ayar[$ad]) $uyarilar[] = "Satır $no: ayardaki GGN farklı (kayıtlı değer korundu) — '↓' ya da yeniden seçimle güncelleyin.";
                elseif (!$eskiMi) $ggn = $ayar[$ad];
            } elseif (!$eskiMi) { $hatalar[] = "Satır $no: GGN adı ayarlarda yok (Frugro ayarları › GGN)."; }
        } elseif ($ggn !== null && !isset($eski["\x1f" . $ggn])) { $hatalar[] = "Satır $no: GGN adı seçilmeden GGN no girilemez."; $ggn = null; }

        $kutu = $kp > 0 ? $kp : frgp_kutu_coz($alan('kutu'));
        if ($kutu === false) { $hatalar[] = "Satır $no: kutu 0'dan büyük TAM sayı olmalı."; $kutu = null; }
        elseif ($kutu === null) $hatalar[] = "Satır $no: kutu adedi boş.";
        $brut = frgp_kg_coz($alan('brut_kg'));
        if ($brut === false) { $hatalar[] = "Satır $no: brüt kg geçersiz (ondalık için virgül kullanın; nokta binliktir: 1.234 = 1234)."; $brut = null; }
        $net = frgp_kg_coz($alan('net_kg'));
        if ($net === false) { $hatalar[] = "Satır $no: net kg geçersiz (ondalık için virgül kullanın; nokta binliktir: 1.234 = 1234)."; $net = null; }
        if ($brut !== null && $brut <= 0) { $hatalar[] = "Satır $no: brüt kg 0'dan büyük olmalı."; }
        if ($brut !== null && $net !== null && $brut < $net) $hatalar[] = "Satır $no: brüt kg ($brut) net kg'dan ($net) küçük olamaz.";
        if ($kutu !== null && $brut !== null && $brut > 0) {
            $kb = $brut / $kutu;
            if ($kb > 30 || $kb < 1) $uyarilar[] = "Satır $no: kutu başına brüt " . number_format($kb, 1, ',', '.') . " kg — sayıları kontrol edin.";
        }
        $satirlar[] = [
            'palet_no' => $paletNo, 'konum' => frgp_metin($alan('konum'), 30), 'kalibre' => frgp_metin($alan('kalibre'), 20),
            'lot' => frgp_metin($alan('lot'), 30), 'ggn_no' => $ggn, 'ggn_adi' => $ad,
            'kutu' => is_int($kutu) ? $kutu : null, 'brut_kg' => is_float($brut) ? $brut : null, 'net_kg' => is_float($net) ? $net : null,
        ];
    }
    $pl = (int)($bag['palet'] ?? 0);
    if ($pl > 0 && $satirlar && count($satirlar) !== $pl) $uyarilar[] = 'Satır sayısı (' . count($satirlar) . ') siparişteki palet sayısından (' . $pl . ') farklı.';
    return ['satirlar' => $satirlar, 'hatalar' => $hatalar, 'uyarilar' => $uyarilar];
}

/** PDF/XLSX üretimi için tam mı? (her satırda kutu + brüt + net; en az bir satır). @return list<string> hatalar */
function frgp_uretime_hazir(array $s): array
{
    $h = [];
    $pal = $s['paletler'] ?? [];
    if (!$pal) return ['Palet satırı yok — önce paletleri girin ya da taslak oluşturun.'];
    foreach (array_values($pal) as $i => $p) {
        $n = $i + 1;
        if (frgp_kutu_coz((string)($p['kutu'] ?? '')) === null) $h[] = "Satır $n: kutu eksik.";
        if ($p['brut_kg'] === null || $p['brut_kg'] === '' || (float)$p['brut_kg'] <= 0) $h[] = "Satır $n: brüt kg eksik.";
        if ($p['net_kg'] === null || $p['net_kg'] === '') $h[] = "Satır $n: net kg eksik (Net öner düğmesi yalnız öneridir).";
    }
    return array_slice($h, 0, 20);
}

// ── Taslak (boş palet satırları) ────────────────────────────────────────────

/** Boy metninden kalibre ("x14" → "14"; sayı yoksa olduğu gibi). */
function frgp_kalibre_coz(?string $boy): ?string
{
    $b = frg_metin($boy);
    if ($b === null) return null;
    return preg_match('/\d+/', $b, $m) ? $m[0] : mb_substr($b, 0, 20);
}

/**
 * PO'dan boş palet satırları: palet adedi = palet (yoksa ⌈kutu / kutu_palet⌉); kutu_palet > 0 ise HER satır kutu = kutu_palet
 * (sahip kararı: kutu siparişten gelir); kalibre = boy, lot = PO no ön-dolu; palet no 1..N.
 * @return list<array<string,mixed>>
 */
function frgp_taslak_satirlari(array $s): array
{
    $kp = (int)($s['kutu_palet'] ?? 0); $pl = (int)($s['palet'] ?? 0); $kutu = (int)($s['kutu'] ?? 0);
    if ($pl <= 0 && $kp > 0 && $kutu > 0) $pl = (int)ceil($kutu / $kp);
    $pl = max(0, min(FRGP_MAX_SATIR, $pl));
    $kal = frgp_kalibre_coz($s['boy'] ?? null);
    $lot = frgp_metin($s['po_no'] ?? null, 30);
    $out = [];
    for ($i = 1; $i <= $pl; $i++) {
        $out[] = ['palet_no' => (string)$i, 'konum' => null, 'kalibre' => $kal, 'lot' => $lot, 'ggn_no' => null, 'ggn_adi' => null,
            'kutu' => $kp > 0 ? $kp : null, 'brut_kg' => null, 'net_kg' => null];
    }
    return $out;
}

// ── Excel yapıştırma ayrıştırıcı ────────────────────────────────────────────

/**
 * Excel'den kopyalanan metni (satır = \n, hücre = \t) ayrıştırır (OGL siparis.js eşi). HEP-YA-HİÇ: tek hata → satır yok.
 *   1 sütun  = hedef sütunun değeri (brüt ya da net)        (mod 'tek')
 *   2 sütun  = brüt | net — YALNIZ hedef 'brut_kg' iken     (mod 'brut_net')
 * Kg'da nokta-ondalık REDDEDİLİR (frgp_kg_coz). Boş satırlar atlanır; en çok FRGP_MAX_SATIR. JS aynası: assets/frugro_pkl.js yapistirCoz().
 * @return array{ok:bool,mod:?string,satirlar:list<array<string,?string>>,hatalar:list<string>}
 */
function frgp_yapistir_coz(string $metin, string $hedef = 'brut_kg'): array
{
    $bos = ['ok' => false, 'mod' => null, 'satirlar' => [], 'hatalar' => []];
    $metin = str_replace(["\r\n", "\r"], "\n", $metin);
    $satirlar = [];
    foreach (explode("\n", $metin) as $ln) {
        if (trim($ln, " \t\xc2\xa0") === '') continue;
        $satirlar[] = array_map(fn($c) => trim($c, " \xc2\xa0"), explode("\t", rtrim($ln, "\t")));
    }
    if (!$satirlar) { $bos['hatalar'][] = 'Yapıştırılacak veri yok.'; return $bos; }
    if (count($satirlar) > FRGP_MAX_SATIR) { $bos['hatalar'][] = 'En çok ' . FRGP_MAX_SATIR . ' satır yapıştırılabilir.'; return $bos; }
    $sayilar = array_unique(array_map('count', $satirlar));
    if (count($sayilar) !== 1) { $bos['hatalar'][] = 'Satırların sütun sayısı aynı değil (1 ya da 2 sütun olmalı).'; return $bos; }
    $n = $sayilar[array_key_first($sayilar)];
    $hedef = $hedef === 'net_kg' ? 'net_kg' : 'brut_kg';
    if ($n === 2 && $hedef !== 'brut_kg') { $bos['hatalar'][] = 'Hedef Net iken tek sütun yapıştırın (2 sütun yalnız Brüt hedefinde: brüt | net).'; return $bos; }
    $mod = [1 => 'tek', 2 => 'brut_net'][$n] ?? null;
    if ($mod === null) { $bos['hatalar'][] = "Sütun sayısı $n desteklenmiyor — 1 sütun (hedef sütun) ya da 2 sütun (brüt | net)."; return $bos; }

    $hatalar = []; $out = [];
    foreach ($satirlar as $i => $h) {
        $no = $i + 1;
        if ($mod === 'brut_net') { [$brut, $net] = $h; } elseif ($hedef === 'net_kg') { $brut = ''; $net = $h[0]; } else { $brut = $h[0]; $net = ''; }
        foreach (['brut' => $brut, 'net' => $net] as $ad => $v) {
            if ($v !== '' && frgp_kg_coz($v) === false) $hatalar[] = "Satır $no: $ad kg geçersiz ('" . mb_substr($v, 0, 20) . "') — ondalık için virgül kullanın, nokta binliktir.";
        }
        $out[] = ['brut_kg' => $brut, 'net_kg' => $net];
    }
    if ($hatalar) { $bos['hatalar'] = array_slice($hatalar, 0, 10); $bos['mod'] = $mod; return $bos; }
    return ['ok' => true, 'mod' => $mod, 'satirlar' => $out, 'hatalar' => []];
}

// ── PKL başlığı ─────────────────────────────────────────────────────────────

/** Başlık alanları (yalnız bunlar yazılır): ad → [uzunluk]. Karton/palet-diğer ayrı çözülür. */
function frgp_baslik_alanlari(): array
{
    return ['parti_no' => 30, 'plaka' => 80, 'sofor_adi' => 100, 'sofor_telefon' => 40, 'nakliyeci' => 150, 'logger_id' => 60, 'sicaklik' => 30, 'bolge' => 100];
}

/** "HH:MM" (ya da "H.MM") → "HH:MM"; boş → ''; geçersiz → false. */
function frgp_saat_coz($v)
{
    $s = trim((string)$v);
    if ($s === '') return '';
    if (!preg_match('/^([01]?\d|2[0-3])[:.]([0-5]\d)$/', $s, $m)) return false;
    return sprintf('%02d:%s', (int)$m[1], $m[2]);
}

/**
 * Başlık alanlarını doğrular (SAF). logger_palet AYRI döner (hks_kv eki; frugro_siparisler kolonu DEĞİL). @return array{degerler:array<string,mixed>,hatalar:list<string>,logger_palet:string}
 * Telefon: yalnız rakam ve + ( ) - . boşluk, 7–15 hane (OGL şoför kuralı).
 */
function frgp_baslik_dogrula(array $post): array
{
    $d = []; $h = [];
    foreach (frgp_baslik_alanlari() as $k => $uz) $d[$k] = frgp_metin($post[$k] ?? null, $uz);
    if ($d['sofor_telefon'] !== null) {
        $hane = strlen((string)preg_replace('/\D/', '', $d['sofor_telefon']));
        if (!preg_match('/^[0-9+()\-. ]+$/', $d['sofor_telefon']) || $hane < 7 || $hane > 15) { $h[] = 'Şoför telefonu 7–15 haneli olmalı (rakam ve + ( ) - . boşluk).'; $d['sofor_telefon'] = null; }
    }
    $kar = frgp_kucuk_coz($post['karton_kg'] ?? null, 3, 5.0);
    if ($kar === false) { $h[] = 'Karton kg 0–5 arası, ondalık için VİRGÜL olmalı (örn. 0,312; nokta kabul edilmez).'; $kar = null; }
    $pd = frgp_kucuk_coz($post['palet_diger_kg'] ?? null, 2, 500.0);
    if ($pd === false) { $h[] = 'Palet diğer kg 0–500 arası, ondalık için VİRGÜL olmalı (örn. 25).'; $pd = null; }
    $d['karton_kg'] = $kar; $d['palet_diger_kg'] = $pd;
    $lp = trim((string)(is_scalar($post['logger_palet'] ?? null) ? $post['logger_palet'] : ''));
    if ($lp !== '' && !preg_match('/^\d{1,3}$/', $lp)) { $h[] = 'Logger palet no 1–3 haneli bir sayı olmalı.'; $lp = ''; }
    return ['degerler' => $d, 'hatalar' => $h, 'logger_palet' => $lp];
}

/**
 * KAYDET: başlık alanları + palet satırları TEK işlemde (hepsi ya da hiçbiri). Yalnız 'yeni'/'pkl_hazir'.
 * Audit: yalnız değişen ALAN ADLARI ve satır sayısı (şoför/üretici adı, telefon, plaka yazılmaz).
 * @return array{ok:bool,mesaj:string,hatalar:list<string>,uyarilar:list<string>}
 */
function frgp_kaydet(PDO $pdo, array $s, array $post, ?int $uid): array
{
    $sid = (int)$s['id'];
    if (!frgp_duzenlenebilir($s)) return ['ok' => false, 'mesaj' => '', 'hatalar' => ['PKL bu durumda düzenlenemez (net/brüt tüm evraklarda aynı kalmalı).'], 'uyarilar' => []];
    $b = frgp_baslik_dogrula(is_array($post['b'] ?? null) ? $post['b'] : []);
    $eskiCift = [];
    foreach (($s['paletler'] ?? []) as $x) $eskiCift[(string)($x['ggn_adi'] ?? '') . "\x1f" . (string)($x['ggn_no'] ?? '')] = true;
    $p = frgp_satirlari_dogrula(is_array($post['p'] ?? null) ? $post['p'] : [], [
        'kutu_palet' => (int)($s['kutu_palet'] ?? 0), 'palet' => (int)($s['palet'] ?? 0), 'eski' => $eskiCift, 'ggn' => frgp_ggn_harita(frgp_ggn_liste($pdo))]);
    $hatalar = array_merge($b['hatalar'], $p['hatalar']);
    $lp = (string)$b['logger_palet'];
    if ($lp !== '' && !in_array($lp, array_map(fn($x) => (string)$x['palet_no'], $p['satirlar']), true)) $hatalar[] = "Logger palet no ($lp) palet satırları arasında yok.";
    if ($hatalar) return ['ok' => false, 'mesaj' => '', 'hatalar' => $hatalar, 'uyarilar' => $p['uyarilar']];

    $degisen = [];
    foreach ($b['degerler'] as $k => $v) if (frg_norm_deger($v) !== frg_norm_deger($s[$k] ?? null)) $degisen[] = $k;
    $eskiPal = json_encode(array_map(fn($x) => [$x['palet_no'], $x['kalibre'], $x['lot'], $x['ggn_no'], $x['ggn_adi'], (int)$x['kutu'], (float)$x['brut_kg'], (float)$x['net_kg']], $s['paletler'] ?? []));
    $yeniPal = json_encode(array_map(fn($x) => [$x['palet_no'], $x['kalibre'], $x['lot'], $x['ggn_no'], $x['ggn_adi'], (int)$x['kutu'], (float)$x['brut_kg'], (float)$x['net_kg']], $p['satirlar']));
    $paletDegisti = $eskiPal !== $yeniPal;
    $ekEski = frgp_ek_oku($pdo, $sid);
    $loggerDegisti = $ekEski['logger_palet'] !== $lp;

    $pdo->beginTransaction();
    try {
        if ($degisen) {
            $set = []; $par = [];
            foreach ($degisen as $k) { $set[] = "`$k` = ?"; $par[] = $b['degerler'][$k]; }
            $set[] = 'updated_at = ?'; $par[] = date('Y-m-d H:i:s'); $par[] = $sid;
            $pdo->prepare('UPDATE frugro_siparisler SET ' . implode(', ', $set) . ' WHERE id = ? AND deleted_at IS NULL')->execute($par);
        }
        $n = null;
        if ($paletDegisti) $n = frg_palet_kaydet($pdo, $sid, $p['satirlar']);
        if ($loggerDegisti && !frgp_ek_yaz($pdo, $sid, ['logger_palet' => $lp, 'antet' => $ekEski['antet']])) throw new RuntimeException('logger palet yazılamadı');
        if ($degisen || $paletDegisti || $loggerDegisti) {
            frg_olay_yaz($pdo, $sid, 'pkl_kaydedildi', ($paletDegisti ? count($p['satirlar']) . ' palet satırı' : 'palet satırları değişmedi') . ($degisen ? '; başlık: ' . implode(', ', $degisen) : '') . ($loggerDegisti ? '; logger palet' : ''), $uid);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[frgp_kaydet] ' . $e->getMessage());
        return ['ok' => false, 'mesaj' => '', 'hatalar' => ['Kayıt sırasında hata oluştu; hiçbir şey kaydedilmedi.'], 'uyarilar' => []];
    }
    if ($degisen || $paletDegisti || $loggerDegisti) {
        audit_log_event('frugro_pkl_kaydet', 'frugro_siparis', $sid, null, ['alanlar' => $degisen, 'palet_satiri' => count($p['satirlar']), 'palet_degisti' => $paletDegisti, 'logger_palet_degisti' => $loggerDegisti]);
    }
    return ['ok' => true, 'mesaj' => ($degisen || $paletDegisti || $loggerDegisti) ? 'PKL kaydedildi.' : 'Değişiklik yok.', 'hatalar' => [], 'uyarilar' => $p['uyarilar']];
}

/** TASLAK: PO'dan boş palet satırları. Mevcut satır varsa yalnız $uzerineYaz ile. @return array{ok:bool,mesaj:string,hatalar:list<string>} */
function frgp_taslak_olustur(PDO $pdo, array $s, bool $uzerineYaz, ?int $uid): array
{
    if (!frgp_duzenlenebilir($s)) return ['ok' => false, 'mesaj' => '', 'hatalar' => ['PKL bu durumda düzenlenemez.']];
    if (!empty($s['paletler']) && !$uzerineYaz) return ['ok' => false, 'mesaj' => '', 'hatalar' => ['Mevcut palet satırları var — üzerine yazmak için onay kutusunu işaretleyin.']];
    $t = frgp_taslak_satirlari($s);
    if (!$t) return ['ok' => false, 'mesaj' => '', 'hatalar' => ['Siparişte palet / kutu-palet bilgisi yok; taslak çıkarılamadı.']];
    $pdo->beginTransaction();
    try {
        frg_palet_kaydet($pdo, (int)$s['id'], $t);
        frg_olay_yaz($pdo, (int)$s['id'], 'pkl_taslak', count($t) . ' boş palet satırı oluşturuldu', $uid);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[frgp_taslak] ' . $e->getMessage());
        return ['ok' => false, 'mesaj' => '', 'hatalar' => ['Taslak oluşturulamadı.']];
    }
    audit_log_event('frugro_pkl_taslak', 'frugro_siparis', (int)$s['id'], null, ['palet_satiri' => count($t), 'uzerine_yazildi' => $uzerineYaz]);
    return ['ok' => true, 'mesaj' => count($t) . ' boş palet satırı oluşturuldu.', 'hatalar' => []];
}

// ── Belge verisi, dosya adı ─────────────────────────────────────────────────

/** İngilizce ürün adı (PDF başlığı). */
function frgp_urun_ad(?string $urun): string
{
    $u = trim((string)$urun);
    return ['NAR' => 'POMEGRANATE'][mb_strtoupper($u, 'UTF-8')] ?? $u;
}

/** PO "26-1816-34726" → REF "26-1816" (yıl-sipariş; örnekte "25-1816"). */
function frgp_ref(string $po): string
{
    return preg_match('/^(\d{2}-\d{4})-\d{5}$/', $po, $m) ? $m[1] : $po;
}

/** PKL-<po_no>-<gg.aa.yyyy>.<uzantı>; parçalar [A-Za-z0-9.-]'e temizlenir. */
function frgp_dosya_adi(array $s, string $uzanti): string
{
    $po = trim((string)preg_replace('/[^A-Za-z0-9.\-]/', '', (string)($s['po_no'] ?? '')), '._-');
    $t = frg_tarih($s['cikis_tarihi'] ?? null);
    $tarih = $t !== null ? date('d.m.Y', (int)strtotime($t . ' 12:00:00')) : date('d.m.Y');
    return 'PKL-' . ($po !== '' ? $po : 'PO') . '-' . $tarih . '.' . $uzanti;
}

function frgp_tarih_goster(?string $t): string
{
    $d = frg_tarih($t);
    return $d !== null ? date('d.m.Y', (int)strtotime($d . ' 12:00:00')) : '';
}

/**
 * PDF/XLSX'in ortak verisi. $cikisSaati "HH:MM" (kayda girmez). $ek = frgp_ek_oku() sonucu (verilmezse antet=true, logger yok).
 * @return array<string,mixed>
 */
function frgp_belge_verisi(array $s, string $cikisSaati = '', ?array $ek = null): array
{
    $pal = array_values($s['paletler'] ?? []);
    $kalib = [];
    foreach ($pal as $p) if (($p['kalibre'] ?? '') !== '' && $p['kalibre'] !== null) $kalib[(string)$p['kalibre']] = true;
    $tek = count($kalib) === 1 ? array_key_first($kalib) : null;
    $urun = frgp_urun_ad($s['urun'] ?? null);
    $satirlar = [];
    $lp = (string)($ek['logger_palet'] ?? '');
    foreach ($pal as $p) {
        $k = (string)($p['kalibre'] ?? '');
        $satirlar[] = [
            'logger' => $lp !== '' && (string)($p['palet_no'] ?? '') === $lp,
            'ggn_no' => (string)($p['ggn_no'] ?? ''), 'ggn_adi' => (string)($p['ggn_adi'] ?? ''), 'palet_no' => (string)($p['palet_no'] ?? ''),
            'urun' => $urun . ($k !== '' ? ', CALIBER ' . $k : ''), 'lot' => (string)($p['lot'] ?? ''),
            'kutu' => (int)($p['kutu'] ?? 0), 'brut' => (float)($p['brut_kg'] ?? 0), 'net' => (float)($p['net_kg'] ?? 0),
        ];
    }
    $tp = frgp_toplam($pal);
    $cs = $cikisSaati !== '' ? $cikisSaati : '';
    return [
        'po_no' => (string)($s['po_no'] ?? ''), 'ref' => frgp_ref((string)($s['po_no'] ?? '')), 'tarih' => frgp_tarih_goster($s['cikis_tarihi'] ?? null),
        'saat' => $cs, 'bolge' => (string)($s['bolge'] ?? ''), 'parti_no' => (string)($s['parti_no'] ?? ''), 'nakliyeci' => (string)($s['nakliyeci'] ?? ''),
        'sicaklik' => (string)($s['sicaklik'] ?? ''), 'plaka' => (string)($s['plaka'] ?? ''), 'logger' => (string)($s['logger_id'] ?? ''),
        'sofor' => (string)($s['sofor_adi'] ?? ''), 'telefon' => (string)($s['sofor_telefon'] ?? ''),
        'karton_kg' => $s['karton_kg'] ?? null, 'palet_diger_kg' => $s['palet_diger_kg'] ?? null,
        'satirlar' => $satirlar, 'toplam' => $tp, 'tek_kalibre' => $tek, 'urun' => $urun,
        'antet' => (bool)($ek['antet'] ?? true), 'logger_palet' => $lp,
    ];
}

// ── PDF (dompdf; Hesap PDF kuralları: uzak kaynak kapalı, DejaVu, text-transform YOK) ──

/** @return string tam HTML belgesi (kullanıcı verisi h() ile kaçırılır) */
function frgp_pdf_html(array $v): string
{
    $e = fn($x): string => h((string)$x);
    $sat = $v['satirlar'];
    $tarih = $v['tarih'] . ($v['saat'] !== '' ? '   ' . $v['saat'] : '');
    $sol = [['SUPPLIER', 'ASYA FRESH - TURKIYE'], ['REGION', $v['bolge']], ['PARTI NO', $v['parti_no']], ['TRANSPORTER', $v['nakliyeci']],
        ['TEMPERATURE', $v['sicaklik']], ['DEPARTURE DATE', $tarih]];
    $antet = (bool)($v['antet'] ?? true);
    $logo = $antet ? frgp_logo_uri() : null;
    $lgDeger = (string)$v['logger'];
    $sag = [['DATE', $v['tarih']], ['LORRY NUMBER', $v['plaka']], ['ID LOGGER', $lgDeger], ['DRIVER', $v['sofor']],
        ['DRIVER PHONE', $v['telefon']], ['REF.', $v['ref']], ['POS. NR.', 'FRUGRO']];
    ob_start();
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><title>PKL <?= $e($v['po_no']) ?></title>
<style>
@page { margin: <?= $antet ? '86pt 28pt 78pt 28pt' : '28pt 28pt 40pt 28pt' ?>; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 8pt; color: #111; }
h1 { font-size: 13pt; margin: 0 0 6pt; letter-spacing: 1pt; }
table { border-collapse: collapse; }
.ust { width: 100%; margin-bottom: 8pt; }
.ust td { vertical-align: top; padding: 0; }
.kv td { padding: 1.5pt 4pt 1.5pt 0; font-size: 8pt; }
.kv td.k { font-weight: bold; white-space: nowrap; width: 82pt; }
.liste { width: 100%; }
.liste th { background: #e8eef4; border: 0.6pt solid #888; padding: 3pt 2pt; font-size: 7pt; text-align: center; white-space: nowrap; }
.liste td { border: 0.4pt solid #aaa; padding: 2pt 3pt; font-size: 7.4pt; white-space: nowrap; }
.liste thead { display: table-header-group; }
.liste tr { page-break-inside: avoid; }
.sag { text-align: right; }
.orta { text-align: center; }
.alt { width: 100%; margin-top: 6pt; }
.alt td { padding: 2pt 3pt; font-size: 8pt; border: 0.6pt solid #888; }
.alt td.b { font-weight: bold; }
.tp td { background: #f2f5f8; font-weight: bold; }
.lg td { background: #ffeb3b; }
.band { width: 100%; border-collapse: collapse; }
.band td { height: 2pt; padding: 0; border: 0; }
.ayak { text-align: center; font-size: 6.8pt; color: #333; line-height: 1.5; }
</style></head><body>
<?php if ($antet): ?>
<div style="position:fixed;top:-72pt;left:0;right:0;">
<?php if ($logo !== null): ?><div style="text-align:center;"><img src="<?= $logo ?>" alt="" style="height:60pt;"></div><?php endif; ?>
<table class="band" style="margin-top:3pt;"><tr><td style="width:62%;background:#3e8e2f"></td><td style="background:#c8202f"></td></tr></table>
</div>
<div style="position:fixed;bottom:-66pt;left:0;right:0;">
<table class="band" style="margin-bottom:4pt;"><tr><td style="width:62%;background:#3e8e2f"></td><td style="background:#c8202f"></td></tr></table>
<div class="ayak"><b>Address:</b> <?= FRGP_AYAK_ADRES ?><br><?= FRGP_AYAK_ILETISIM ?></div>
</div>
<?php endif; ?>
<table class="ust"><tr>
<td style="width:54%"><table class="kv"><?php foreach ($sol as [$k, $d]): ?><tr><td class="k"><?= $e($k) ?></td><td>: <?= $e($d) ?></td></tr><?php endforeach; ?></table></td>
<td><table class="kv"><?php foreach ($sag as [$k, $d]): ?><tr><td class="k"><?= $e($k) ?></td><td>: <?php if ($k === 'ID LOGGER' && $d !== ''): ?><span style="background:#ffeb3b;padding:0 3pt"><?= $e($d) ?></span><?php else: ?><?= $e($d) ?><?php endif; ?></td></tr><?php endforeach; ?></table></td>
</tr></table>
<table class="liste">
<thead><tr><th style="width:14%">GROWER-GGN</th><th style="width:16%">GGN NAME</th><th style="width:9%">PALET NO</th><th style="width:25%">PRODUCT</th><th style="width:15%">LOT NO</th><th style="width:7%">BOX</th><th style="width:8%">BRUT KG</th><th style="width:8%">NET KG</th></tr></thead>
<tbody>
<?php foreach ($sat as $r): $lot = $r['lot']; ?>
<tr<?= !empty($r['logger']) ? ' class="lg"' : '' ?>><td><?= $e($r['ggn_no']) ?></td><td><?= $e($r['ggn_adi']) ?></td><td class="orta"><?= $e($r['palet_no']) ?></td><td><?= $e($r['urun']) ?></td><td class="orta"><?= $e($lot) ?></td><td class="sag"><?= $e($r['kutu']) ?></td><td class="sag"><?= $e(frgp_kg_goster($r['brut'])) ?></td><td class="sag"><?= $e(frgp_kg_goster($r['net'])) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<table class="alt"><tr class="tp">
<td style="width:44%">CARTON BOX WEIGHT: <?= $e(frgp_kucuk_goster($v['karton_kg'])) ?> KG<br>PALLET OTHERS: <?= $e(frgp_kucuk_goster($v['palet_diger_kg'])) ?> KG</td>
<td class="sag"><span style="font-size:6.5pt;font-weight:normal">BOX TOTAL NUMBER</span><br><?= $e(frgp_kg_goster($v['toplam']['kutu'])) ?></td>
<td class="sag"><span style="font-size:6.5pt;font-weight:normal">BRUT WEIGHT KG</span><br><?= $e(frgp_kg_goster($v['toplam']['brut'])) ?></td>
<td class="sag"><span style="font-size:6.5pt;font-weight:normal">NET WEIGHT KG</span><br><?= $e(frgp_kg_goster($v['toplam']['net'])) ?></td>
</tr></table>
</body></html><?php
    return (string)ob_get_clean();
}

/** @return string|null PDF baytları; dompdf yoksa null */
function frgp_pdf(array $v): ?string
{
    if (!class_exists(\Dompdf\Dompdf::class)) {
        $oto = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($oto)) require_once $oto;
    }
    if (!class_exists(\Dompdf\Dompdf::class)) return null;
    $opt = new \Dompdf\Options();
    $opt->set('defaultFont', 'DejaVu Sans');
    $opt->set('isRemoteEnabled', false);
    $opt->set('isPhpEnabled', false);
    $opt->set('isHtml5ParserEnabled', true);
    $opt->set('chroot', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
    $d = new \Dompdf\Dompdf($opt);
    $d->loadHtml(frgp_pdf_html($v), 'UTF-8');
    $d->setPaper('A4', 'portrait');
    $d->render();
    $c = $d->getCanvas();
    $f = $d->getFontMetrics()->getFont('DejaVu Sans', 'normal');
    $antet = (bool)($v['antet'] ?? true);
    if (!$antet) $c->page_text(28, $c->get_height() - 26, 'ASYA FRESH · PKL ' . $v['po_no'], $f, 7, [0.42, 0.45, 0.52]);
    $c->page_text($c->get_width() - 110, $c->get_height() - ($antet ? 14 : 26), 'Page {PAGE_NUM} / {PAGE_COUNT}', $f, 7, [0.42, 0.45, 0.52]);
    return $d->output();
}

/** @return string|null XLSX baytları (config/xlsx_export.php motoru; yeni bağımlılık YOK) */
function frgp_xlsx(array $v): ?string
{
    if (!function_exists('xlsx_olustur')) require_once __DIR__ . '/xlsx_export.php';
    xlsx_motor_yukle();
    $bilgi = [['SUPPLIER', 'ASYA FRESH - TURKIYE'], ['REGION', $v['bolge']], ['PARTI NO', $v['parti_no']], ['TRANSPORTER', $v['nakliyeci']], ['TEMPERATURE', $v['sicaklik']],
        ['DEPARTURE DATE', trim($v['tarih'] . ' ' . $v['saat'])], ['LORRY NUMBER', $v['plaka']], ['ID LOGGER', $v['logger']], ['ID LOGGER PALET', $v['logger_palet'] ?? ''], ['DRIVER', $v['sofor']],
        ['DRIVER PHONE', $v['telefon']], ['REF.', $v['ref']], ['POS. NR.', 'FRUGRO'],
        ['CARTON BOX WEIGHT KG', frgp_kucuk_goster($v['karton_kg'])], ['PALLET OTHERS KG', frgp_kucuk_goster($v['palet_diger_kg'])]];
    $satirlar = [];
    foreach ($v['satirlar'] as $r) $satirlar[] = [$r['ggn_no'], $r['ggn_adi'], $r['palet_no'], $r['urun'], $r['lot'], $r['kutu'], $r['brut'], $r['net']];
    $ss = xlsx_olustur([[
        'ad' => 'PKL', 'baslik' => 'PACKING LIST ' . $v['po_no'], 'bilgi' => $bilgi,
        'sutunlar' => [['baslik' => 'GROWER-GGN', 'tip' => 'metin'], ['baslik' => 'GGN NAME', 'tip' => 'metin'], ['baslik' => 'PALET NO', 'tip' => 'metin'],
            ['baslik' => 'PRODUCT', 'tip' => 'metin'], ['baslik' => 'LOT NO', 'tip' => 'metin'], ['baslik' => 'BOX', 'tip' => 'tamsayi', 'topla' => true],
            ['baslik' => 'BRUT KG', 'tip' => 'kg', 'topla' => true], ['baslik' => 'NET KG', 'tip' => 'kg', 'topla' => true]],
        'satirlar' => $satirlar, 'toplam' => true,
    ]], ['baslik' => 'PKL ' . $v['po_no']]);
    ob_start();
    \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($ss, 'Xlsx')->save('php://output');
    $b = (string)ob_get_clean();
    $ss->disconnectWorksheets();
    return $b !== '' ? $b : null;
}

/**
 * ÜRET: PDF ya da XLSX'i taze üretir (diske YAZILMAZ). Eksik palet verisi → hata.
 * Başarıda: olay 'pkl_uretildi' + audit + durum yeni|hazirlik → pkl_hazir (frg_durum_degistir).
 * @return array{ok:bool,hatalar:list<string>,ad:string,tip:string,veri:string}
 */
function frgp_uret(PDO $pdo, array $s, string $bicim, string $cikisSaati, ?int $uid, ?bool $antet = null): array
{
    $hata = fn(array $h) => ['ok' => false, 'hatalar' => $h, 'ad' => '', 'tip' => '', 'veri' => ''];
    if (!in_array($bicim, ['pdf', 'xlsx'], true)) return $hata(['Geçersiz biçim.']);
    $saat = frgp_saat_coz($cikisSaati);
    if ($saat === false) return $hata(['Çıkış saati SS:DD olmalı (örn. 18:00).']);
    $eks = frgp_uretime_hazir($s);
    if ($eks) return $hata($eks);
    $ek = frgp_ek_oku($pdo, (int)$s['id']);
    if ($antet !== null && $antet !== $ek['antet']) {            // antet seçimi sipariş bazında saklanır; kilit ENGELLEMEZ (net/brüt değildir)
        $ek['antet'] = $antet;
        frgp_ek_yaz($pdo, (int)$s['id'], $ek);
    }
    $v = frgp_belge_verisi($s, (string)$saat, $ek);
    $veri = $bicim === 'pdf' ? frgp_pdf($v) : frgp_xlsx($v);
    if ($veri === null || $veri === '') return $hata(['Dosya üretilemedi (PDF/Excel motoru yok).']);
    $sid = (int)$s['id'];
    $gecti = false;
    try {
        frg_olay_yaz($pdo, $sid, 'pkl_uretildi', strtoupper($bicim) . ' · ' . count($v['satirlar']) . ' palet · ' . $v['toplam']['kutu'] . ' kutu', $uid);
        // yeni|hazirlik → pkl_hazir: durum yazmanın TEK yolu (koşullu UPDATE + olay + audit)
        if (frg_otomatik_gecis_mi((string)$s['durum'], 'pkl_hazir')) {
            $gecti = frg_durum_degistir($pdo, $s, 'pkl_hazir', '', $uid, 'otomatik')['ok'];
        }
    } catch (Throwable $e) {
        error_log('[frgp_uret] ' . $e->getMessage());
    }
    audit_log_event('frugro_pkl_uret', 'frugro_siparis', $sid, $gecti ? ['durum' => (string)$s['durum']] : null, ['bicim' => $bicim, 'palet_satiri' => count($v['satirlar']), 'durum_gecti' => $gecti] + ($gecti ? ['durum' => 'pkl_hazir'] : []));
    return ['ok' => true, 'hatalar' => [], 'ad' => frgp_dosya_adi($s, $bicim), 'veri' => $veri,
        'tip' => $bicim === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
}
