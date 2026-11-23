<?php
// =========================================================
// config/siparis_vade.php — OGL "Haftalık Ödeme" (v569; include-only, SALT OKUNUR, çıktı basmaz)
//
// Soru: "OGL bu hafta ne kadar ödemeli?" OGL kestiğimiz invoice'ları haftalık ödeme gününde, vadesi gelenleri toplu öder
// (ödeme avizosu; config/siparis_odeme_aviz.php). Bu dosya:
//  1) ÖDENENİ avizo kalemlerinden bulur: avizodaki IHR… fatura no → üretilmiş invoice'ı olan sevkiyat (siparis_odeme_aviz_eslesmeler,
//     OKUMA ANINDA — eski avizolar sonradan üretilen invoice'larla da eşleşir). Avizo kalemi olmayan (elle girilmiş) EUR ödemeler
//     en eski açık faturalara SIRAYLA (FIFO) mahsup edilir — tahmini, ekranda ayrıca işaretlenir.
//  2) VADEYİ geçmişten öğrenir: ödenen faturalarda gecikme = avizo tarihi − baz tarihi (varsayılan fatura tarihi); otomatik vade =
//     EN KÜÇÜK gecikme (gözlenen tüm ödemelerle tutarlı en geç vade → "bu hafta" tutarını şişirmez). Ödeme günü = avizo tarihlerinin
//     en sık haftanın günü. İkisi de GET ile elle değiştirilebilir (sayfa SALT OKUNUR; ayar kaydedilmez).
//  3) AÇIK faturaları ödeme haftasına dağıtır: ödeme günü = (baz + vade) gününden sonraki ilk ödeme günü; bu haftanın ödeme gününden
//     önceye düşen (gecikmiş) fatura BU HAFTAYA alınır.
//  Beklenen ödeme = invoice toplamı (iskonto SONRASI; üretilmiş invoice belgesinin toplam_kurus'u) − avizoda henüz düşülmemiş OGL
//  kesintileri (debit note) − tahmini fiyat farkı kesintisi (gelen < kesilen ve henüz fiyat farkı debit'i yoksa: fark × (1 − iskonto)).
//  Ödenmiş sevkiyatın avizoda görünmeyen kesintisi bu haftanın ödemesinden düşülür.
//
// Değişmezler: ŞEMA YOK, YAZMA YOK (yalnız SELECT; audit sayfada, yalnız SAYI). Para kuruş tam sayı; para birimleri ASLA toplanmaz
// (OGL invoice'ları EUR — takvim yalnız EUR; başka para birimindeki ödeme ayrı gösterilir). Hesap TEK yerde siparis_vade_hesapla() (saf).
// =========================================================
declare(strict_types=1);
// Doğrudan istek → 404. TAM YOL karşılaştırılır (basename değil — bkz. config/siparis_yazdir.php, v385).
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/siparis_cari.php';
require_once __DIR__ . '/siparis_odeme_aviz.php';
require_once __DIR__ . '/siparis_debit.php';

const SIPARIS_VADE_GUN_VARSAYILAN = 21;   // geçmiş ödeme yokken varsayılan vade (gün)
const SIPARIS_VADE_GUN_MAX = 120;
const SIPARIS_VADE_GECIKME_MAX = 180;     // bundan büyük gecikme (yanlış eşleşme / eski fatura) vade öğrenmesine katılmaz

/** @return array<string,string> baz anahtarı → etiket (GET beyaz listesi). */
function siparis_vade_bazlar(): array
{
    return ['fatura' => 'Fatura tarihi', 'yukleme' => 'Yükleme tarihi', 'fiyat' => 'Fiyat onayı (PC) tarihi'];
}

/** @return array<int,string> ISO gün no (1 = Pazartesi) → ad. */
function siparis_vade_gun_adlari(): array
{
    return [1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar'];
}

function siparis_vade_gun_ekle(string $ymd, int $n): string
{
    return (new DateTimeImmutable($ymd))->modify(($n >= 0 ? '+' : '') . $n . ' day')->format('Y-m-d');
}

/** $b − $a (gün). */
function siparis_vade_gun_farki(string $a, string $b): int
{
    return (int)round(((new DateTimeImmutable($b . ' 12:00:00'))->getTimestamp() - (new DateTimeImmutable($a . ' 12:00:00'))->getTimestamp()) / 86400);
}

function siparis_vade_iso_gun(string $ymd): int { return (int)(new DateTimeImmutable($ymd))->format('N'); }

/** Haftanın Pazartesi'si. */
function siparis_vade_hafta_basi(string $ymd): string { return siparis_vade_gun_ekle($ymd, 1 - siparis_vade_iso_gun($ymd)); }

/** $ymd ya da sonraki ilk $gun (ISO 1–7). */
function siparis_vade_sonraki_gun(string $ymd, int $gun): string
{
    return siparis_vade_gun_ekle($ymd, ($gun - siparis_vade_iso_gun($ymd) + 7) % 7);
}

/**
 * GET → parametre (saf). vade: boş = otomatik (geçmişten), aksi 0–120 tam sayı; gun: boş = otomatik, aksi 1–7; baz: beyaz liste (varsayılan fatura).
 * Geçersiz değer otomatiğe düşer + hata metni.
 * @return array{vade:?int,gun:?int,baz:string,hata:list<string>}
 */
function siparis_vade_param(array $get): array
{
    $hata = [];
    $ham = static fn(string $k): string => is_string($get[$k] ?? null) ? trim((string)$get[$k]) : '';
    $vade = null;
    if (($v = $ham('vade')) !== '') {
        if (preg_match('/^\d{1,3}$/', $v) && (int)$v <= SIPARIS_VADE_GUN_MAX) $vade = (int)$v;
        else $hata[] = 'Vade 0–' . SIPARIS_VADE_GUN_MAX . ' gün arasında tam sayı olmalı (otomatik kullanıldı).';
    }
    $gun = null;
    if (($v = $ham('gun')) !== '') {
        if (preg_match('/^[1-7]$/', $v)) $gun = (int)$v;
        else $hata[] = 'Ödeme günü geçersiz (otomatik kullanıldı).';
    }
    $baz = $ham('baz');
    if ($baz !== '' && !isset(siparis_vade_bazlar()[$baz])) { $hata[] = 'Vade başlangıcı geçersiz (fatura tarihi kullanıldı).'; $baz = ''; }
    return ['vade' => $vade, 'gun' => $gun, 'baz' => $baz !== '' ? $baz : 'fatura', 'hata' => $hata];
}

/** @param list<int> $l  sıralı değil olabilir @return array{n:int,min:?int,medyan:?int,max:?int} */
function siparis_vade_istatistik(array $l): array
{
    if ($l === []) return ['n' => 0, 'min' => null, 'medyan' => null, 'max' => null];
    sort($l);
    $n = count($l);
    $med = $n % 2 === 1 ? $l[intdiv($n, 2)] : intdiv($l[$n / 2 - 1] + $l[$n / 2], 2);
    return ['n' => $n, 'min' => $l[0], 'medyan' => $med, 'max' => $l[$n - 1]];
}

/** Sevkiyatın vade başlangıç tarihi (seçilen baz; yoksa fatura → yükleme → fiyat sırasıyla yedek). */
function siparis_vade_baz_tarihi(array $sev, string $baz): ?string
{
    $f = ($sev['fatura']['tarih'] ?? null) !== null && (string)$sev['fatura']['tarih'] !== '' ? (string)$sev['fatura']['tarih'] : null;
    $y = ($sev['tarih'] ?? null) !== null && (string)$sev['tarih'] !== '' ? (string)$sev['tarih'] : null;
    $p = ($sev['fiyat_tarihi'] ?? null) !== null && (string)$sev['fiyat_tarihi'] !== '' ? (string)$sev['fiyat_tarihi'] : null;
    $sira = ['fatura' => [$f, $y, $p], 'yukleme' => [$y, $f, $p], 'fiyat' => [$p, $f, $y]][$baz] ?? [$f, $y, $p];
    foreach ($sira as $t) if ($t !== null) return $t;
    return null;
}

/**
 * Invoice net tutarı (iskonto sonrası, kuruş): üretilmiş belgenin toplam_kurus'u; yoksa siparis_inv_hesapla ile aynı formül
 * (kg × fiyat → kuruş; iskonto kuruşa yarım yukarı). $iskonto = yüzde (4 = %4).
 */
function siparis_vade_fatura_net(array $sev, ?int $belgeToplam, float $iskonto): int
{
    if ($belgeToplam !== null && $belgeToplam > 0) return $belgeToplam;
    $brut = isset($sev['karsilastirma']['fatura_kurus']) ? (int)$sev['karsilastirma']['fatura_kurus']
        : intdiv((int)($sev['toplam']['gram'] ?? 0) * (int)($sev['fatura']['fiyat'] ?? 0) + 50000, 100000);
    $isk100 = (int)round($iskonto * 100);
    return $brut - intdiv($brut * $isk100 + 5000, 10000);
}

/**
 * SAF hesap. $g:
 *  sevkiyatlar  list<array>  siparis_cari_veri() sevkiyatları (yüklenen + yüklenmemiş; fatura / karsilastirma / debit alanlarıyla)
 *  fatura       array<int,array{iskonto:float,...}>  siparis_cari_fatura_fiyatlari()
 *  belge_toplam array<int,int>  sid → üretilmiş invoice toplam_kurus (iskonto sonrası)
 *  odemeler     list<array{id:int,tarih:string,kurus:int,pb:string,satirlar:?list<array>}>  aktif ödemeler (+ avizo kalemleri; yoksa null)
 *  harita       array<string,array{durum:string,v:list<array{id:int}>}>  IHR gövdesi → sevkiyat (siparis_odeme_aviz_eslesmeler)
 *  debitler     list<array{no:string,tarih:string,kurus:int,pb:string,invoice_no:string,sid:?int,ref:string,fiyat_farki:bool,neden:string}>
 * @return array<string,mixed>
 */
function siparis_vade_hesapla(array $g, array $param, string $bugun): array
{
    $faturaH = (array)($g['fatura'] ?? []);
    $belgeT = (array)($g['belge_toplam'] ?? []);
    $harita = (array)($g['harita'] ?? []);
    $uyarilar = [];

    // ── 1) ödemeler + avizo kalemleri ──────────────────────────────────
    $odenen = [];      // sid → {tarih (ilk ana satırın avizo tarihi), kurus (ana + düzeltme satırları)}
    $avizNo = [];      // avizoda geçen tüm belge numaraları (debit note no'su dahil)
    $duz = [];         // IHR gövdesi → düzeltme (…DI) satır tutarları (mutlak, kuruş)
    $elleHavuz = 0;    // avizo kalemi olmayan EUR ödemeler
    $odemeOzet = []; $tarihler = []; $digerPb = [];
    $odenenToplam = 0; $son = null; $eslesmeyenFatura = 0;
    foreach ((array)($g['odemeler'] ?? []) as $o) {
        $pb = strtoupper((string)($o['pb'] ?? ''));
        $k = (int)($o['kurus'] ?? 0);
        $t = (string)($o['tarih'] ?? '');
        if ($pb !== 'EUR') { $digerPb[$pb] = ($digerPb[$pb] ?? 0) + $k; continue; }
        $odenenToplam += $k;
        if ($t !== '') { $tarihler[] = $t; if ($son === null || $t > $son['tarih']) $son = ['tarih' => $t, 'kurus' => $k]; }
        $sat = $o['satirlar'] ?? null;
        $oz = ['id' => (int)($o['id'] ?? 0), 'tarih' => $t, 'kurus' => $k, 'avizolu' => is_array($sat), 'sevkiyat' => 0, 'eslesmeyen' => 0, 'gecikme' => []];
        if (!is_array($sat)) { $elleHavuz += $k; $odemeOzet[] = $oz; continue; }
        $buOdemeSid = [];
        foreach ($sat as $r) {
            $n = strtoupper(trim((string)($r['n'] ?? '')));
            if ($n !== '') $avizNo[$n] = true;
            $gv = (string)($r['g'] ?? '');
            $tur = (string)($r['t'] ?? '');
            if ($gv === '' || ($tur !== 'ana' && $tur !== 'duzeltme')) continue;
            $z = (int)($r['z'] ?? 0);
            if ($tur === 'duzeltme') $duz[$gv][] = abs($z);
            $h = $harita[$gv] ?? null;
            if ($h === null || ($h['durum'] ?? '') !== 'eslesti' || empty($h['v'][0]['id'])) { if ($tur === 'ana') { $oz['eslesmeyen']++; $eslesmeyenFatura++; } continue; }
            $sid = (int)$h['v'][0]['id'];
            $odenen[$sid] ??= ['tarih' => null, 'kurus' => 0, 'odeme_id' => 0];
            $odenen[$sid]['kurus'] += $z;
            if ($tur === 'ana' && $t !== '' && ($odenen[$sid]['tarih'] === null || $t < $odenen[$sid]['tarih'])) { $odenen[$sid]['tarih'] = $t; $odenen[$sid]['odeme_id'] = $oz['id']; }
            if ($tur === 'ana') $buOdemeSid[$sid] = true;
        }
        $oz['sevkiyat'] = count($buOdemeSid);
        $odemeOzet[] = $oz;
    }

    // ── 2) kesintiler (debit note): avizoda düşülmüş mü? ───────────────
    $bekleyenSid = [];   // sid → list<debit> (henüz hiçbir avizoda düşülmemiş)
    $bekleyenSahipsiz = [];
    $ffDebitSid = [];    // fiyat farkı debit'i (düşülmüş ya da değil) olan sevkiyatlar → tahmini kesinti hesaplanmaz
    $debitler = array_values(array_filter((array)($g['debitler'] ?? []), static fn($d): bool => is_array($d) && strtoupper((string)($d['pb'] ?? '')) === 'EUR' && (int)($d['kurus'] ?? 0) > 0));
    usort($debitler, static fn(array $a, array $b): int => strcmp((string)($a['tarih'] ?? ''), (string)($b['tarih'] ?? '')) ?: strcmp((string)$a['no'], (string)$b['no']));
    foreach ($debitler as $d) {
        $sid = (int)($d['sid'] ?? 0);
        if ($sid > 0 && !empty($d['fiyat_farki'])) $ffDebitSid[$sid] = true;
        $dusuldu = isset($avizNo[strtoupper((string)$d['no'])]);
        if (!$dusuldu) {
            $gv = siparis_odeme_aviz_no_coz((string)($d['invoice_no'] ?? ''))['govde'];
            if ($gv !== null && !empty($duz[$gv])) {
                $i = array_search((int)$d['kurus'], $duz[$gv], true);
                if ($i === false) $i = array_key_first($duz[$gv]);
                unset($duz[$gv][$i]);
                $dusuldu = true;
            }
        }
        if ($dusuldu) continue;
        $e = ['no' => (string)$d['no'], 'tarih' => (string)($d['tarih'] ?? ''), 'kurus' => (int)$d['kurus'], 'ref' => (string)($d['ref'] ?? ''),
              'fiyat_farki' => !empty($d['fiyat_farki']), 'neden' => (string)($d['neden'] ?? ''), 'sid' => $sid > 0 ? $sid : null];
        if ($sid > 0) $bekleyenSid[$sid][] = $e; else $bekleyenSahipsiz[] = $e;
    }

    // ── 3) sevkiyatlar: ödenmiş / açık / faturasız ─────────────────────
    $odenmis = []; $acik = []; $faturasiz = [];
    foreach ((array)($g['sevkiyatlar'] ?? []) as $sev) {
        $id = (int)($sev['id'] ?? 0);
        $fat = $sev['fatura'] ?? null;
        if (!is_array($fat) || (int)($fat['fiyat'] ?? 0) <= 0) {
            if (!empty($sev['yuklendi'])) $faturasiz[] = ['sev' => $sev, 'gelen_kurus' => isset($sev['net']['EUR']) ? (int)$sev['net']['EUR'] : (isset($sev['tutar']['EUR']) ? (int)$sev['tutar']['EUR'] : null)];   // iskonto sonrası
            continue;
        }
        $isk = (float)($faturaH[$id]['iskonto'] ?? 0.0);
        $net = siparis_vade_fatura_net($sev, isset($belgeT[$id]) ? (int)$belgeT[$id] : null, $isk);
        $baz = siparis_vade_baz_tarihi($sev, (string)$param['baz']);
        if (isset($odenen[$id]) && $odenen[$id]['tarih'] !== null) {
            $gun = $baz !== null ? siparis_vade_gun_farki($baz, (string)$odenen[$id]['tarih']) : null;
            $odenmis[] = ['sev' => $sev, 'net' => $net, 'baz' => $baz, 'odeme_tarih' => (string)$odenen[$id]['tarih'], 'odenen' => (int)$odenen[$id]['kurus'], 'gecikme' => $gun, 'elle' => false, 'odeme_id' => (int)$odenen[$id]['odeme_id'],
                          'kesinti' => array_sum(array_column($bekleyenSid[$id] ?? [], 'kurus'))];
            continue;
        }
        $kesinti = array_sum(array_column($bekleyenSid[$id] ?? [], 'kurus'));
        $tahmini = 0;
        $kk = $sev['karsilastirma'] ?? null;
        if (!isset($ffDebitSid[$id]) && is_array($kk) && (int)$kk['fark_kurus'] < 0) {
            $tahmini = intdiv(-(int)$kk['fark_kurus'] * (10000 - (int)round($isk * 100)) + 5000, 10000);
        }
        $acik[] = ['sev' => $sev, 'net' => $net, 'baz' => $baz, 'kesinti' => $kesinti, 'kesinti_liste' => $bekleyenSid[$id] ?? [], 'tahmini' => $tahmini,
                   'beklenen' => $net - $kesinti - $tahmini, 'pc_yok' => !is_array($kk), 'mahsup' => 0];
        unset($bekleyenSid[$id]);
    }
    // ödenmiş sevkiyatın avizoda düşülmemiş kesintisi → bu haftanın ödemesinden düşülür
    $bekleyen = $bekleyenSahipsiz;
    foreach ($bekleyenSid as $l) foreach ($l as $e) $bekleyen[] = $e;

    // ── 4) vade + ödeme günü (geçmişten ya da elle) ────────────────────
    $gecikmeler = [];
    foreach ($odenmis as $o) if ($o['gecikme'] !== null && $o['gecikme'] >= 0 && $o['gecikme'] <= SIPARIS_VADE_GECIKME_MAX) $gecikmeler[] = (int)$o['gecikme'];
    $ist = siparis_vade_istatistik($gecikmeler);
    $odGec = [];
    foreach ($odenmis as $o) if ($o['gecikme'] !== null && $o['odeme_id'] > 0) $odGec[$o['odeme_id']][] = (int)$o['gecikme'];
    foreach ($odemeOzet as &$oz) $oz['gecikme'] = siparis_vade_istatistik($odGec[$oz['id']] ?? []);
    unset($oz);
    $vadeOto = $param['vade'] === null;
    $vade = $vadeOto ? ($ist['min'] ?? SIPARIS_VADE_GUN_VARSAYILAN) : (int)$param['vade'];
    $gunSay = [];
    foreach ($tarihler as $t) { $w = siparis_vade_iso_gun($t); $gunSay[$w] = ['n' => ($gunSay[$w]['n'] ?? 0) + 1, 'son' => max($gunSay[$w]['son'] ?? '', $t)]; }
    $gunOto = $param['gun'] === null;
    $gun = 1;
    if (!$gunOto) $gun = (int)$param['gun'];
    elseif ($gunSay) {
        uasort($gunSay, static fn(array $a, array $b): int => ($b['n'] <=> $a['n']) ?: strcmp($b['son'], $a['son']));
        $gun = (int)array_key_first($gunSay);
    }
    if ($vadeOto && $ist['n'] === 0) $uyarilar[] = ['seviye' => 'uyari', 'mesaj' => 'Avizoyla eşleşmiş ödenmiş fatura yok — vade varsayılan ' . SIPARIS_VADE_GUN_VARSAYILAN . ' gün alındı. OGL\'nin gerçek vadesini "Vade (gün)" kutusuna yazın.'];
    if ($gunOto && !$gunSay) $uyarilar[] = ['seviye' => 'uyari', 'mesaj' => 'Kayıtlı OGL ödemesi yok — ödeme günü Pazartesi varsayıldı.'];

    $haftaBas = siparis_vade_hafta_basi($bugun);
    $buOdeme = siparis_vade_gun_ekle($haftaBas, $gun - 1);   // bu haftanın ödeme günü (geçmiş olabilir)

    // ── 5) elle (avizosuz) ödemeler: en eski açık faturalara FIFO mahsup (tahmini) ──
    usort($acik, static fn(array $a, array $b): int => strcmp((string)($a['baz'] ?? '9999'), (string)($b['baz'] ?? '9999')) ?: ((int)$a['sev']['id'] <=> (int)$b['sev']['id']));
    $havuz = $elleHavuz;
    foreach ($acik as $i => $a) {
        if ($havuz <= 0) break;
        if ($a['beklenen'] <= 0) continue;
        $m = min($havuz, $a['beklenen']);
        $acik[$i]['mahsup'] = $m; $acik[$i]['beklenen'] -= $m; $havuz -= $m;
    }
    $kalanAcik = [];
    foreach ($acik as $a) {
        if ($a['mahsup'] > 0 && $a['beklenen'] <= 0) {
            $odenmis[] = ['sev' => $a['sev'], 'net' => $a['net'], 'baz' => $a['baz'], 'odeme_tarih' => null, 'odenen' => $a['mahsup'], 'gecikme' => null, 'elle' => true, 'kesinti' => 0, 'odeme_id' => 0];
            continue;
        }
        $vt = $a['baz'] !== null ? siparis_vade_gun_ekle($a['baz'], $vade) : null;
        $od = $vt !== null ? siparis_vade_sonraki_gun($vt, $gun) : $buOdeme;
        $gecikmis = $vt !== null && $od < $buOdeme;
        if ($od < $buOdeme) $od = $buOdeme;
        $a['vade_tarih'] = $vt; $a['odeme_gunu'] = $od; $a['hafta'] = siparis_vade_hafta_basi($od); $a['gecikmis'] = $gecikmis; $a['tarihsiz'] = $vt === null;
        $kalanAcik[] = $a;
    }
    usort($kalanAcik, static fn(array $a, array $b): int => strcmp($a['odeme_gunu'], $b['odeme_gunu']) ?: strcmp((string)($a['baz'] ?? ''), (string)($b['baz'] ?? '')) ?: ((int)$a['sev']['id'] <=> (int)$b['sev']['id']));
    usort($odenmis, static fn(array $a, array $b): int => strcmp((string)($b['odeme_tarih'] ?? '0'), (string)($a['odeme_tarih'] ?? '0')) ?: strcmp((string)($b['baz'] ?? ''), (string)($a['baz'] ?? '')));

    // ── 6) haftalık takvim ─────────────────────────────────────────────
    $hafta = [];
    $yeniHafta = static fn(string $od): array => ['bas' => siparis_vade_hafta_basi($od), 'son' => siparis_vade_gun_ekle(siparis_vade_hafta_basi($od), 6), 'odeme_gunu' => $od,
        'sevkiyat' => 0, 'net' => 0, 'kesinti' => 0, 'beklenen' => 0, 'gecikmis_kurus' => 0, 'gecikmis_sevkiyat' => 0, 'pc_yok' => 0, 'bu_hafta' => false, 'kumulatif' => 0];
    foreach ($kalanAcik as $a) {
        $h = $a['hafta'];
        $hafta[$h] ??= $yeniHafta($a['odeme_gunu']);
        $hafta[$h]['sevkiyat']++;
        $hafta[$h]['net'] += $a['net'] - $a['mahsup'];
        $hafta[$h]['kesinti'] += $a['kesinti'] + $a['tahmini'];
        $hafta[$h]['beklenen'] += $a['beklenen'];
        if ($a['pc_yok']) $hafta[$h]['pc_yok']++;
        if ($a['gecikmis']) { $hafta[$h]['gecikmis_kurus'] += $a['beklenen']; $hafta[$h]['gecikmis_sevkiyat']++; }
    }
    $bekleyenToplam = array_sum(array_column($bekleyen, 'kurus'));
    if ($bekleyenToplam > 0) {
        $hafta[$haftaBas] ??= $yeniHafta($buOdeme);
        $hafta[$haftaBas]['kesinti'] += $bekleyenToplam;
        $hafta[$haftaBas]['beklenen'] -= $bekleyenToplam;
    }
    ksort($hafta);
    $kum = 0;
    foreach ($hafta as $h => &$w) { $kum += $w['beklenen']; $w['kumulatif'] = $kum; $w['bu_hafta'] = $h === $haftaBas; }
    unset($w);
    $bu = $hafta[$haftaBas] ?? $yeniHafta($buOdeme);
    $gelecekBas = siparis_vade_gun_ekle($haftaBas, 7);
    $gelecek = $hafta[$gelecekBas] ?? $yeniHafta(siparis_vade_gun_ekle($buOdeme, 7));

    if ($eslesmeyenFatura > 0) $uyarilar[] = ['seviye' => 'bilgi', 'mesaj' => $eslesmeyenFatura . ' avizo fatura kalemi sistemdeki bir invoice\'la eşleşmedi (invoice bu sistemde üretilmemiş olabilir) — o faturalar takvimde görünmez.'];
    if ($elleHavuz > 0) $uyarilar[] = ['seviye' => 'bilgi', 'mesaj' => 'Avizo kalemi olmayan (elle girilmiş) ' . siparis_cari_para($elleHavuz) . ' EUR ödeme, en eski açık faturalara sırayla mahsup edildi (tahmini).' . ($havuz > 0 ? ' Mahsup edilemeyen artık: ' . siparis_cari_para($havuz) . ' EUR.' : '')];
    $pcYok = count(array_filter($kalanAcik, static fn(array $a): bool => $a['pc_yok']));
    if ($pcYok > 0) $uyarilar[] = ['seviye' => 'bilgi', 'mesaj' => $pcYok . ' açık faturanın gelen (PC) fiyatı henüz yok — kesilen fiyat PC\'den yüksek çıkarsa OGL farkı ödemeden düşer; beklenen tutar o kadar azalabilir.'];
    if ($faturasiz) $uyarilar[] = ['seviye' => 'bilgi', 'mesaj' => count($faturasiz) . ' yüklenmiş sevkiyatın invoice\'u bu sistemde üretilmemiş — invoice üretilince takvime girer.'];
    if ($digerPb) $uyarilar[] = ['seviye' => 'bilgi', 'mesaj' => 'EUR dışı ödemeler takvime katılmadı: ' . implode(' · ', array_map(static fn($pb, $k): string => siparis_cari_para((int)$k) . ' ' . $pb, array_keys($digerPb), $digerPb)) . '.'];

    return [
        'param' => ['vade' => $vade, 'vade_oto' => $vadeOto, 'gun' => $gun, 'gun_oto' => $gunOto, 'baz' => (string)$param['baz']],
        'bu_hafta' => ['bas' => $haftaBas, 'son' => siparis_vade_gun_ekle($haftaBas, 6), 'odeme_gunu' => $buOdeme],
        'haftalar' => array_values($hafta),
        'acik' => $kalanAcik,
        'bekleyen_kesinti' => $bekleyen,
        'faturasiz' => $faturasiz,
        'odenmis' => $odenmis,
        'odemeler' => $odemeOzet,
        'ozet' => [
            'bu_hafta_kurus' => (int)$bu['beklenen'], 'bu_hafta_sevkiyat' => (int)$bu['sevkiyat'], 'bu_hafta_gecikmis_kurus' => (int)$bu['gecikmis_kurus'],
            'bu_hafta_gecikmis_sevkiyat' => (int)$bu['gecikmis_sevkiyat'], 'bu_hafta_kesinti' => (int)$bu['kesinti'], 'bekleyen_kesinti_kurus' => $bekleyenToplam,
            'gelecek_hafta_kurus' => (int)$gelecek['beklenen'], 'gelecek_hafta_sevkiyat' => (int)$gelecek['sevkiyat'], 'gelecek_odeme_gunu' => (string)$gelecek['odeme_gunu'],
            'acik_kurus' => array_sum(array_column($kalanAcik, 'beklenen')) - $bekleyenToplam, 'acik_sevkiyat' => count($kalanAcik),
            'odenen_kurus' => $odenenToplam, 'son_odeme' => $son, 'odeme_adet' => count($odemeOzet),
            'faturasiz_sevkiyat' => count($faturasiz), 'faturasiz_kurus' => array_sum(array_map(static fn(array $f): int => (int)($f['gelen_kurus'] ?? 0), $faturasiz)),
            'gecikme' => $ist, 'diger_pb' => $digerPb,
        ],
        'uyarilar' => $uyarilar,
    ];
}

// ── DB okuyucular (SALT OKUNUR, fail soft) ─────────────────────────────

/** sid → üretilmiş invoice belgesinin toplam_kurus'u (iskonto sonrası). @param list<int> $sids @return array<int,int> */
function siparis_vade_belge_toplamlari(PDO $pdo, array $sids): array
{
    $out = [];
    try {
        $ids = array_values(array_unique(array_filter(array_map('intval', $sids), static fn(int $i): bool => $i > 0)));
        foreach (array_chunk($ids, 500) as $parca) {
            $an = array_map(static fn(int $i): string => SIPARIS_INV_GIRDI_KV_ON . $i, $parca);
            $st = $pdo->prepare('SELECT anahtar, deger FROM `' . siparis_evrak_mail_kv_tablo() . '` WHERE anahtar IN (' . implode(',', array_fill(0, count($an), '?')) . ')');
            $st->execute($an);
            $belge = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $j = json_decode((string)($r['deger'] ?? ''), true);
                $b = is_array($j) ? (int)($j['belge_id'] ?? 0) : 0;
                if ($b > 0) $belge[$b] = (int)substr((string)$r['anahtar'], strlen(SIPARIS_INV_GIRDI_KV_ON));
            }
            if (!$belge) continue;
            $q = $pdo->prepare("SELECT id, parse_json FROM siparis_belgeler WHERE tur = 'invoice' AND deleted_at IS NULL AND id IN (" . implode(',', array_fill(0, count($belge), '?')) . ')');
            $q->execute(array_keys($belge));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $j = json_decode((string)($r['parse_json'] ?? ''), true);
                if (is_array($j) && isset($j['toplam_kurus']) && is_numeric($j['toplam_kurus']) && (int)$j['toplam_kurus'] > 0) $out[$belge[(int)$r['id']]] = (int)$j['toplam_kurus'];
            }
        }
    } catch (Throwable $e) {
        return $out;
    }
    return $out;
}

/** ödeme id → avizo kalem satırları (hks_kv `siparis_odeme_aviz_<id>`). @return array<int,list<array>> */
function siparis_vade_avizo_satirlari(PDO $pdo): array
{
    $out = [];
    try {
        $st = $pdo->prepare('SELECT anahtar, deger FROM `' . siparis_evrak_mail_kv_tablo() . '` WHERE anahtar LIKE ? LIMIT 20000');
        $st->execute([SIPARIS_ODEME_AVIZ_KV . '%']);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!preg_match('~^' . preg_quote(SIPARIS_ODEME_AVIZ_KV, '~') . '(\d+)$~', (string)$r['anahtar'], $m)) continue;
            $j = json_decode((string)($r['deger'] ?? ''), true);
            if (is_array($j) && is_array($j['satirlar'] ?? null)) $out[(int)$m[1]] = array_values(array_filter($j['satirlar'], 'is_array'));
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

/**
 * Sayfa girdisi: tüm (aktif depo) sevkiyatlar + ödemeler + avizo eşlemesi + debit'ler → siparis_vade_hesapla().
 * @return array{veri:array,cari:array}
 */
function siparis_vade_oku(PDO $pdo, array $param, string $bugun): array
{
    $okunan = siparis_cari_oku($pdo, ['bas' => '', 'bit' => '', 'alici' => '']);
    $sevIdler = array_map(static fn(array $r): int => (int)$r['id'], $okunan['rows']);
    $fatura = siparis_cari_fatura_fiyatlari($pdo, $sevIdler);
    $notlar = siparis_debit_hepsi($pdo);
    $esl = [];
    try { $esl = $notlar ? siparis_debit_esle($pdo, $notlar) : []; } catch (Throwable $e) { $esl = []; }
    $debitH = siparis_debit_harita($pdo);
    $cari = siparis_cari_veri($okunan, ['bugun' => $bugun, 'tahsilat' => [], 'fatura' => $fatura, 'parti' => siparis_cari_parti_haritasi($pdo, $sevIdler), 'debit' => $debitH,
        'iskonto_varsayilan' => (float)(siparis_inv_ayar_oku($pdo)['iskonto_varsayilan'] ?? 0.0)]);
    $sevler = array_merge($cari['yuklenen'], $cari['yuklenmemis']);
    $refH = [];
    foreach ($sevler as $s) $refH[(int)$s['id']] = (string)$s['order_ref'];

    $aviz = siparis_vade_avizo_satirlari($pdo);
    $odemeler = [];
    foreach (siparis_odeme_oku($pdo, ['bas' => '', 'bit' => '']) as $o) {
        $odemeler[] = ['id' => (int)$o['id'], 'tarih' => (string)$o['odeme_tarihi'], 'kurus' => (int)$o['tutar_kurus'], 'pb' => (string)$o['para_birimi'], 'satirlar' => $aviz[(int)$o['id']] ?? null];
    }
    $govde = [];
    foreach ($odemeler as $o) foreach ((array)($o['satirlar'] ?? []) as $r) if ((string)($r['g'] ?? '') !== '') $govde[(string)$r['g']] = true;
    $harita = [];
    try { $harita = $govde ? siparis_odeme_aviz_eslesmeler($pdo, array_keys($govde)) : []; } catch (Throwable $e) { $harita = []; }

    $debitler = [];
    foreach ($notlar as $n) {
        $e = $esl[(string)$n['no']] ?? null;
        $sid = ($e !== null && $e['durum'] === 'eslesti' && $e['sid'] !== null && isset($refH[(int)$e['sid']])) ? (int)$e['sid'] : null;
        $debitler[] = ['no' => (string)$n['no'], 'tarih' => (string)($n['tarih'] ?? ''), 'kurus' => (int)($n['amount_kurus'] ?? 0), 'pb' => (string)($n['pb'] ?? ''),
            'invoice_no' => (string)($n['invoice_no'] ?? ''), 'sid' => $sid, 'ref' => $sid !== null ? $refH[$sid] : (string)($e['ref'] ?? ''),
            'fiyat_farki' => !empty($n['fiyat_farki']), 'neden' => (string)($n['neden'] ?? '')];
    }
    $veri = siparis_vade_hesapla([
        'sevkiyatlar' => $sevler, 'fatura' => $fatura, 'belge_toplam' => siparis_vade_belge_toplamlari($pdo, array_keys($fatura)),
        'odemeler' => $odemeler, 'harita' => $harita, 'debitler' => $debitler,
    ], $param, $bugun);
    return ['veri' => $veri, 'cari' => $cari];
}
