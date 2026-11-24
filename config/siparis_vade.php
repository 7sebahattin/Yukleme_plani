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
 * Sevkiyatın net tutarı (iskonto sonrası, kuruş) = EN AZ sevkiyatın TAMAMI (kg × invoice fiyatı, siparis_inv_hesapla ile aynı formül; iskonto kuruşa yarım yukarı).
 * v570: üretilmiş invoice belgesi sevkiyatın yalnız bir kısmını (ör. iki araçlı sevkiyatta tek araç) kapsayabilir → belge tutarı tek başına
 * KULLANILMAZ; belge tamamdan BÜYÜKSE (PKL gerçek kg) belge tutarı alınır. $iskonto = yüzde (4 = %4).
 */
function siparis_vade_fatura_net(array $sev, ?int $belgeToplam, float $iskonto): int
{
    $brut = isset($sev['karsilastirma']['fatura_kurus']) ? (int)$sev['karsilastirma']['fatura_kurus']
        : intdiv((int)($sev['toplam']['gram'] ?? 0) * (int)($sev['fatura']['fiyat'] ?? 0) + 50000, 100000);
    $isk100 = (int)round($iskonto * 100);
    $tum = $brut - intdiv($brut * $isk100 + 5000, 10000);
    return max($tum, (int)($belgeToplam ?? 0));
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
    $grup = [];        // v570: ORTAK FATURA — aynı fatura no birden çok sevkiyatta (tek invoice iki sevkiyatı kapsıyor); avizo satırı sevkiyatlara dağıtılır
    $avizHavuz = 0; $avizHavuzKalem = 0;   // v570: avizoda geçen ama sistemdeki hiçbir invoice'la (tekil) eşleşmeyen fatura kalemleri
    $avizNet = 0;      // v571: hiçbir sevkiyata yazılmayan TÜM avizo satırlarının NET toplamı (eşleşmeyen fatura + iade/düzeltme/iptal satırları; bilinen debit note satırları hariç)
    $debitNo = [];     // bilinen OGL debit note numaraları (avizoda kendi numarasıyla eksi satır olarak geçer; zaten debit olarak hesaplanır → havuzdan düşülmez)
    foreach ((array)($g['debitler'] ?? []) as $d) if (is_array($d) && trim((string)($d['no'] ?? '')) !== '') $debitNo[strtoupper(trim((string)$d['no']))] = true;
    $odemeOzet = []; $tarihler = []; $digerPb = [];
    $odenenToplam = 0; $son = null; $eslesmeyenFatura = 0;
    // v572: faturası (fiyatı) olan sevkiyatlar — avizo tutarı yalnız bunlara yazılabilir; başka sid'e eşleşen tutar eşleşmeyen havuzuna gider (kuruş kaybolmasın)
    $fatSet = [];
    foreach ((array)($g['sevkiyatlar'] ?? []) as $sv) if (is_array($sv['fatura'] ?? null) && (int)($sv['fatura']['fiyat'] ?? 0) > 0) $fatSet[(int)($sv['id'] ?? 0)] = true;
    $debitSid = [];    // v572: debit note no → sevkiyat (yalnız faturası olan sevkiyatlar; avizoda KENDİ numarasıyla geçen eksi satır o sevkiyatın kesintisidir)
    foreach ((array)($g['debitler'] ?? []) as $d) {
        $dn = strtoupper(trim((string)($d['no'] ?? '')));
        if ($dn !== '' && (int)($d['sid'] ?? 0) > 0 && isset($fatSet[(int)$d['sid']])) $debitSid[$dn] = (int)$d['sid'];
    }
    $debitKes = 0;     // v572: avizoda kendi numarasıyla düşülen ama faturası olan bir sevkiyata bağlanamayan debit tutarı (mutlak)
    $kalemFark = 0;    // v572: Σ (ödeme tutarı − avizo kalemleri toplamı) — 0 olmalı (kalemler eksik okunmuşsa sapma verir)
    $avizGunToplam = [];   // v572: gün → o gün avizoyla gelen EUR toplam (geçmiş ödeme kıyası)
    foreach ((array)($g['odemeler'] ?? []) as $o) {
        $pb = strtoupper((string)($o['pb'] ?? ''));
        $k = (int)($o['kurus'] ?? 0);
        $t = (string)($o['tarih'] ?? '');
        if ($pb !== 'EUR') { $digerPb[$pb] = ($digerPb[$pb] ?? 0) + $k; continue; }
        $odenenToplam += $k;
        if ($t !== '') { $tarihler[] = $t; if ($son === null || $t > $son['tarih']) $son = ['tarih' => $t, 'kurus' => $k]; }
        $sat = $o['satirlar'] ?? null;
        $avizolu = is_array($sat) && $sat !== [];   // v572: kalemi olmayan (boş liste) avizo da elle girilmiş gibi sayılır — kuruş havuza girer
        $oz = ['id' => (int)($o['id'] ?? 0), 'tarih' => $t, 'kurus' => $k, 'avizolu' => $avizolu, 'sevkiyat' => 0, 'eslesmeyen' => 0, 'gecikme' => []];
        if (!$avizolu) { $elleHavuz += $k; $odemeOzet[] = $oz; continue; }
        if ($t !== '') $avizGunToplam[$t] = ($avizGunToplam[$t] ?? 0) + $k;
        $buOdemeSid = [];
        $satToplam = 0;
        foreach ($sat as $r) {
            $n = strtoupper(trim((string)($r['n'] ?? '')));
            if ($n !== '') $avizNo[$n] = true;
            $gv = (string)($r['g'] ?? '');
            $tur = (string)($r['t'] ?? '');
            $z = (int)($r['z'] ?? 0);
            $satToplam += $z;
            $yazildi = false;   // bu satır bir sevkiyata (ya da ortak faturaya) yazıldı mı
            if ($gv !== '' && ($tur === 'ana' || $tur === 'duzeltme')) {
                if ($tur === 'duzeltme') $duz[$gv][] = abs($z);
                $h = $harita[$gv] ?? null;
                if ($h !== null && ($h['durum'] ?? '') === 'belirsiz' && $tur === 'ana' && $z > 0 && count((array)($h['v'] ?? [])) >= 2) {
                    $grup[] = ['z' => $z, 'sids' => array_values(array_map('intval', array_column((array)$h['v'], 'id'))), 'tarih' => $t, 'odeme_id' => $oz['id'], 'ozi' => count($odemeOzet), 'no' => $gv];
                    $yazildi = true;
                } elseif ($h === null || ($h['durum'] ?? '') !== 'eslesti' || empty($h['v'][0]['id'])) {
                    if ($tur === 'ana') { $oz['eslesmeyen']++; $eslesmeyenFatura++; if ($z > 0) $avizHavuzKalem++; }
                } else {
                    $sid = (int)$h['v'][0]['id'];
                    $odenen[$sid] ??= ['tarih' => null, 'kurus' => 0, 'odeme_id' => 0, 'ana' => 0];
                    $odenen[$sid]['kurus'] += $z;
                    if ($tur === 'ana') $odenen[$sid]['ana'] += $z;   // faturayı kapatan tutar (düzeltme/debit satırları HARİÇ)
                    if ($tur === 'ana' && $t !== '' && ($odenen[$sid]['tarih'] === null || $t < $odenen[$sid]['tarih'])) { $odenen[$sid]['tarih'] = $t; $odenen[$sid]['odeme_id'] = $oz['id']; }
                    if ($tur === 'ana') $buOdemeSid[$sid] = true;
                    $yazildi = true;
                }
            }
            // v571: eşleşmeyen satırlar NET sayılır — eksi satırlar (iade / düzeltme / iptal) pozitif eşleşmeyen faturaları götürür; bilinen debit note satırı (eksi) hariç
            // v572: bilinen debit note'un KENDİ numarasıyla düşülen eksi satırı, debit'in sevkiyatı faturalıysa o sevkiyatın kesintisi olarak yazılır; değilse debitKes'e (hiçbir kuruş kaybolmaz)
            if (!$yazildi) {
                if ($z < 0 && isset($debitNo[$n])) {
                    $ds = $debitSid[$n] ?? 0;
                    if ($ds > 0) { $odenen[$ds] ??= ['tarih' => null, 'kurus' => 0, 'odeme_id' => 0, 'ana' => 0]; $odenen[$ds]['kurus'] += $z; }
                    else $debitKes += -$z;
                } else $avizNet += $z;
            }
        }
        $kalemFark += $k - $satToplam;
        $oz['sevkiyat'] = count($buOdemeSid);
        $odemeOzet[] = $oz;
    }
    // v572: faturası olmayan (listede yok / fiyatsız) sevkiyata eşleşen avizo tutarı = eşleşmeyen sayılır (havuza net girer); kasadan düşmez, kaybolmaz
    foreach ($odenen as $sid => $e) {
        if (isset($fatSet[(int)$sid])) continue;
        $avizNet += (int)$e['kurus'];
        unset($odenen[$sid]);
    }

    $avizHavuz = max(0, $avizNet);   // v571: pozitif eşleşmeyen kalemler YETMEZ — eksi satırlar düşülmüş NET tutar (ödenen > kasaya giren olamaz)
    $avizNetEksi = max(0, -$avizNet);   // v572: eşleşmeyen satırların NET'i eksiyse = hiçbir faturaya bağlanamayan kesinti (OGL düşmüş)

    // ── 2a) ortak fatura: avizodaki tutar, aynı numarayı taşıyan sevkiyatlara EN ESKİDEN başlayarak (her biri kendi net tutarına kadar) dağıtılır ──
    if ($grup) {
        $netOn = []; $bazOn = [];
        foreach ((array)($g['sevkiyatlar'] ?? []) as $sv) {
            $i = (int)($sv['id'] ?? 0);
            if (!is_array($sv['fatura'] ?? null) || (int)($sv['fatura']['fiyat'] ?? 0) <= 0) continue;
            $netOn[$i] = siparis_vade_fatura_net($sv, isset($belgeT[$i]) ? (int)$belgeT[$i] : null, (float)($faturaH[$i]['iskonto'] ?? 0.0));
            $bazOn[$i] = (string)(siparis_vade_baz_tarihi($sv, (string)$param['baz']) ?? '9999');
        }
        foreach ($grup as $gp) {
            $sids = array_values(array_filter($gp['sids'], static fn(int $i): bool => isset($netOn[$i])));
            usort($sids, static fn(int $a, int $b): int => strcmp($bazOn[$a], $bazOn[$b]) ?: ($a <=> $b));
            $kz = $gp['z']; $dagitilan = 0;
            foreach ($sids as $i) {
                if ($kz <= 0) break;
                $odenen[$i] ??= ['tarih' => null, 'kurus' => 0, 'odeme_id' => 0, 'ana' => 0];
                $ihtiyac = $netOn[$i] - (int)$odenen[$i]['ana'];
                if ($ihtiyac <= 0) continue;
                $pay = min($kz, $ihtiyac);
                $odenen[$i]['kurus'] += $pay; $odenen[$i]['ana'] += $pay; $kz -= $pay; $dagitilan++;
                if ($gp['tarih'] !== '' && ($odenen[$i]['tarih'] === null || $gp['tarih'] < $odenen[$i]['tarih'])) { $odenen[$i]['tarih'] = $gp['tarih']; $odenen[$i]['odeme_id'] = $gp['odeme_id']; }
            }
            if (isset($odemeOzet[$gp['ozi']])) $odemeOzet[$gp['ozi']]['sevkiyat'] += $dagitilan;
            if ($kz > 0) { $avizHavuz += $kz; $avizHavuzKalem++; }   // dağıtılamayan artık (sevkiyatlar tamamen ödendi / invoice yok) → mahsup havuzu
        }
    }

    // ── 2) kesintiler (debit note): avizoda düşülmüş mü? ───────────────
    $bekleyenSid = [];   // sid → list<debit> (henüz hiçbir avizoda düşülmemiş)
    $bekleyenSahipsiz = [];
    $debitVarSid = [];
    $ffDebitSid = [];    // fiyat farkı debit'i (düşülmüş ya da değil) olan sevkiyatlar → tahmini kesinti hesaplanmaz
    $debitler = array_values(array_filter((array)($g['debitler'] ?? []), static fn($d): bool => is_array($d) && strtoupper((string)($d['pb'] ?? '')) === 'EUR' && (int)($d['kurus'] ?? 0) > 0));
    usort($debitler, static fn(array $a, array $b): int => strcmp((string)($a['tarih'] ?? ''), (string)($b['tarih'] ?? '')) ?: strcmp((string)$a['no'], (string)$b['no']));
    foreach ($debitler as $d) {
        $sid = (int)($d['sid'] ?? 0);
        if ($sid > 0 && !empty($d['fiyat_farki'])) $ffDebitSid[$sid] = true;
        if ($sid > 0) $debitVarSid[$sid] = true;   // v573: bu sevkiyata bağlı HERHANGİ bir debit note — avizodaki eksik ödemeyi açıklar (iskonto şüphesi değil)
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

    // ── 3) sevkiyatlar: ödenmiş / kısmen ödenmiş / açık / faturasız ──────
    $odenmis = []; $acik = []; $faturasiz = [];
    $faturaToplam = 0; $kismiInvSay = 0; $kismiInvEksik = 0; $noSay = [];
    $farkDiger = 0;   // v572: satıra yazılamayan kesintiler (henüz ödenmemiş sevkiyatta avizoda önceden düşülen) — avizo_fark'a girer
    foreach ((array)($g['sevkiyatlar'] ?? []) as $sev) {
        $id = (int)($sev['id'] ?? 0);
        $fat = $sev['fatura'] ?? null;
        if (!is_array($fat) || (int)($fat['fiyat'] ?? 0) <= 0) {
            if (!empty($sev['yuklendi'])) $faturasiz[] = ['sev' => $sev, 'gelen_kurus' => isset($sev['net']['EUR']) ? (int)$sev['net']['EUR'] : (isset($sev['tutar']['EUR']) ? (int)$sev['tutar']['EUR'] : null)];   // iskonto sonrası
            continue;
        }
        $isk = (float)($faturaH[$id]['iskonto'] ?? 0.0);
        $belge = isset($belgeT[$id]) ? (int)$belgeT[$id] : null;
        $net = siparis_vade_fatura_net($sev, $belge, $isk);
        $tol = max(100, intdiv($net, 200));   // %0,5 (en az 1 €) — kuruş yuvarlama / kg farkı payı
        $faturaToplam += $net;
        $invKismi = $belge !== null && $belge > 0 && $belge < $net - $tol;   // sistemdeki invoice sevkiyatın yalnız bir kısmını kapsıyor
        if ($invKismi) { $kismiInvSay++; $kismiInvEksik += $net - $belge; }
        $no = trim((string)($fat['no'] ?? ''));
        if ($no !== '') $noSay[$no][] = (string)($sev['order_ref'] ?? '');
        $baz = siparis_vade_baz_tarihi($sev, (string)$param['baz']);
        $ana = isset($odenen[$id]) ? (int)$odenen[$id]['ana'] : 0;
        $dKes = isset($odenen[$id]) ? (int)$odenen[$id]['kurus'] - $ana : 0;   // v572: avizoda bu sevkiyattan düşülen düzeltme/debit satırları (eksi)
        $odendi = isset($odenen[$id]) && ($odenen[$id]['tarih'] !== null || $ana !== 0);
        $kalan = $net - $ana;
        $kismen = $odendi && $kalan > $tol;   // bir kısmı avizoda ödenmiş, kalanı yok (ikinci invoice/ödeme eşleşmemiş)
        if ($odendi) {
            $odTarih = $odenen[$id]['tarih'];
            $gun = $baz !== null && $odTarih !== null ? siparis_vade_gun_farki($baz, (string)$odTarih) : null;
            $rowNet = $kismen ? $ana : $net;
            $rowOdenen = (int)$odenen[$id]['kurus'];
            $fark = $rowNet - $rowOdenen;   // v572: net − avizoda ödenen (≥ 0 = OGL'nin düştüğü, tahsil edilmeyecek fark)
            // iskonto şüphesi: sevkiyatın TAM net tutarı ile avizoda ödenen (kısmi satırda da) arasındaki eksik tam %4 mü? (kısmi ödemede kalan tutar açık listede kalır — işaretlenir, tutar DEĞİŞTİRİLMEZ)
            $tamFark = $net - $rowOdenen;
            // v573: fark bir debit note'la (ör. 61-237: 887,04 fiyat farkı debit'i) ya da gelen fiyat (PC) < kesilen fiyat ile açıklanıyorsa iskonto şüphesi DEĞİL
            //       (0,05 / 1,25 = tam %4 gibi tesadüfler yanlış alarm veriyordu)
            $kkO = $sev['karsilastirma'] ?? null;
            $farkAciklanir = isset($debitVarSid[$id]) || (is_array($kkO) && (int)($kkO['fark_kurus'] ?? 0) < 0);
            $iskSup = !$farkAciklanir && $tamFark > 0 && $net > 0 && abs($tamFark - intdiv($net * 4 + 50, 100)) <= max(100, intdiv($net, 1000));
            $odenmis[] = ['sev' => $sev, 'net' => $rowNet, 'baz' => $baz, 'odeme_tarih' => (string)($odTarih ?? ''), 'odenen' => $rowOdenen, 'gecikme' => $gun, 'elle' => false, 'odeme_id' => (int)$odenen[$id]['odeme_id'],
                          'kesinti' => array_sum(array_column($bekleyenSid[$id] ?? [], 'kurus')), 'kismi' => $kismen, 'fark_kurus' => $fark, 'iskonto_suphesi' => $iskSup, 'net_tam' => $net, 'tam_fark_kurus' => $tamFark];
            if (!$kismen) continue;
        } elseif ($dKes !== 0) {
            $farkDiger += -$dKes;   // ödenmemiş sevkiyatın avizoda önceden düşülmüş kesintisi — satır yok, toplamda tutulur
        }
        $kesinti = $odendi ? 0 : array_sum(array_column($bekleyenSid[$id] ?? [], 'kurus'));
        $tahmini = 0;
        $kk = $sev['karsilastirma'] ?? null;
        if (!isset($ffDebitSid[$id]) && is_array($kk) && (int)$kk['fark_kurus'] < 0) {
            $tahmini = intdiv(-(int)$kk['fark_kurus'] * (10000 - (int)round($isk * 100)) + 5000, 10000);
            if ($odendi) $tahmini = intdiv($tahmini * $kalan + intdiv($net, 2), max(1, $net));   // yalnız kalan kısmın payı
        }
        // v572: faturası SİSTEMDE olmayan kısım = sevkiyat net tutarının, sistemdeki invoice'ın kapsamadığı ve henüz ödenmemiş bölümü
        $kalanHam = $invKismi ? max(0, min($kalan, $net - max((int)$belge, $ana))) : 0;
        $acik[] = ['sev' => $sev, 'net' => $kalan, 'baz' => $baz, 'kesinti' => $kesinti, 'kesinti_liste' => $odendi ? [] : ($bekleyenSid[$id] ?? []), 'tahmini' => $tahmini,
                   'beklenen' => $kalan - $kesinti - $tahmini, 'pc_yok' => !is_array($kk), 'mahsup' => 0, 'kismi' => $kismen, 'invoice_kismi' => $invKismi, 'kalan_ham' => $kalanHam];
        if (!$odendi) unset($bekleyenSid[$id]);
    }
    // ödenmiş sevkiyatın avizoda düşülmemiş kesintisi → bu haftanın ödemesinden düşülür
    $bekleyen = $bekleyenSahipsiz;
    foreach ($bekleyenSid as $l) foreach ($l as $e) $bekleyen[] = $e;

    // ── 4) vade + ödeme günü (geçmişten ya da elle) ────────────────────
    $gecikmeler = [];
    foreach ($odenmis as $o) if ($o['gecikme'] !== null && $o['gecikme'] >= 1 && $o['gecikme'] <= SIPARIS_VADE_GECIKME_MAX) $gecikmeler[] = (int)$o['gecikme'];
    // v570: fatura tarihi ödeme tarihinden SONRA (ya da aynı gün) olan faturalar vade öğrenmesine katılmaz — invoice tarihi sonradan girilmiş demektir (aksi hâlde vade 0 çıkar ve her şey "bu hafta" olur)
    $gecikmeBozuk = count(array_filter($odenmis, static fn(array $o): bool => $o['gecikme'] !== null && $o['gecikme'] < 1));
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

    // ── 5) elle (avizosuz) ödemeler + avizoda eşleşmeyen faturalar: en eski açık faturalara FIFO mahsup (TAHMİNİ) ──
    usort($acik, static fn(array $a, array $b): int => strcmp((string)($a['baz'] ?? '9999'), (string)($b['baz'] ?? '9999')) ?: ((int)$a['sev']['id'] <=> (int)$b['sev']['id']));
    $havuzToplam = $elleHavuz + $avizHavuz;
    $havuz = $havuzToplam;
    foreach ($acik as $i => $a) {
        if ($havuz <= 0) break;
        if ($a['beklenen'] <= 0) continue;
        $m = min($havuz, $a['beklenen']);
        $acik[$i]['mahsup'] = $m; $acik[$i]['beklenen'] -= $m; $havuz -= $m;
    }
    $kalanAcik = [];
    $kesintiMahsupKapanan = 0;   // v572: tahmini mahsupla kapanan sevkiyatların henüz düşülmemiş kesintisi (fiyat farkı + debit) — "bekleyen kesinti"ye girer
    foreach ($acik as $a) {
        if ($a['mahsup'] > 0 && $a['beklenen'] <= 0) {
            $kesintiMahsupKapanan += $a['kesinti'] + $a['tahmini'];
            $odenmis[] = ['sev' => $a['sev'], 'net' => $a['net'], 'baz' => $a['baz'], 'odeme_tarih' => null, 'odenen' => $a['mahsup'], 'gecikme' => null, 'elle' => true, 'kesinti' => 0, 'odeme_id' => 0,
                          'fark_kurus' => 0, 'iskonto_suphesi' => false];
            continue;
        }
        $a['kalan_kisim_kurus'] = min(max(0, (int)$a['beklenen']), (int)$a['kalan_ham']);   // kesintiden/mahsuptan SONRAKİ beklenen tutarın faturasız bölümü (kesintiler önce faturalı kısımdan düşülür)
        $a['kalan_kisim'] = $a['kalan_kisim_kurus'] > 0;
        $vt = $a['baz'] !== null ? siparis_vade_gun_ekle($a['baz'], $vade) : null;
        $od = $vt !== null ? siparis_vade_sonraki_gun($vt, $gun) : $buOdeme;
        $gecikmis = $vt !== null && $od < $buOdeme;
        if ($od < $buOdeme) $od = $buOdeme;
        $yk = ($a['sev']['tarih'] ?? null) !== null && (string)$a['sev']['tarih'] !== '' ? siparis_vade_gun_farki((string)$a['sev']['tarih'], $bugun) : null;   // yüklemeden bu yana geçen gün
        $a['yukleme_gun'] = $yk;
        $a['vade_tarih'] = $vt; $a['odeme_gunu'] = $od; $a['hafta'] = siparis_vade_hafta_basi($od); $a['gecikmis'] = $gecikmis; $a['tarihsiz'] = $vt === null;
        $kalanAcik[] = $a;
    }
    usort($kalanAcik, static fn(array $a, array $b): int => strcmp($a['odeme_gunu'], $b['odeme_gunu']) ?: strcmp((string)($a['baz'] ?? ''), (string)($b['baz'] ?? '')) ?: ((int)$a['sev']['id'] <=> (int)$b['sev']['id']));
    usort($odenmis, static fn(array $a, array $b): int => strcmp((string)($b['odeme_tarih'] ?? '0'), (string)($a['odeme_tarih'] ?? '0')) ?: strcmp((string)($b['baz'] ?? ''), (string)($a['baz'] ?? '')));

    // ── 6) haftalık takvim ─────────────────────────────────────────────
    $hafta = [];
    $yeniHafta = static fn(string $od): array => ['bas' => siparis_vade_hafta_basi($od), 'son' => siparis_vade_gun_ekle(siparis_vade_hafta_basi($od), 6), 'odeme_gunu' => $od,
        'sevkiyat' => 0, 'net' => 0, 'kesinti' => 0, 'beklenen' => 0, 'gecikmis_kurus' => 0, 'gecikmis_sevkiyat' => 0, 'pc_yok' => 0, 'bu_hafta' => false, 'kumulatif' => 0, 'kalan_kisim_kurus' => 0, 'kalan_kisim_sevkiyat' => 0, 'kesin_kurus' => 0];
    foreach ($kalanAcik as $a) {
        $h = $a['hafta'];
        $hafta[$h] ??= $yeniHafta($a['odeme_gunu']);
        $hafta[$h]['sevkiyat']++;
        $hafta[$h]['net'] += $a['net'] - $a['mahsup'];
        $hafta[$h]['kesinti'] += $a['kesinti'] + $a['tahmini'];
        $hafta[$h]['beklenen'] += $a['beklenen'];
        if ($a['pc_yok']) $hafta[$h]['pc_yok']++;
        $hafta[$h]['kalan_kisim_kurus'] += $a['kalan_kisim_kurus'];
        if ($a['kalan_kisim']) $hafta[$h]['kalan_kisim_sevkiyat']++;
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
    foreach ($hafta as $h => &$w) { $kum += $w['beklenen']; $w['kumulatif'] = $kum; $w['bu_hafta'] = $h === $haftaBas; if ($w['beklenen'] - $w['kalan_kisim_kurus'] < 0) $w['kalan_kisim_kurus'] = max(0, $w['beklenen']);   // bekleyen kesinti kesin kısmı aşarsa fazlası kalan kısımdan düşer (kesin ASLA eksiye düşmez)
        $w['kesin_kurus'] = $w['beklenen'] - $w['kalan_kisim_kurus']; }   // v572: kesin + kalan kısım = beklenen
    unset($w);
    $bu = $hafta[$haftaBas] ?? $yeniHafta($buOdeme);
    $gelecekBas = siparis_vade_gun_ekle($haftaBas, 7);
    $gelecek = $hafta[$gelecekBas] ?? $yeniHafta(siparis_vade_gun_ekle($buOdeme, 7));

    // ── v572: Cari bakiye uyumu (TAM eşitlik; tolerans YOK) ─────────────
    // Cari bakiye = Σ invoice net − kasaya giren. Açık alacak + bekleyen kesinti + avizoda düşülen fark bunu EKSİKSİZ açıklamalı:
    //   avizo_fark  = kapanan/kısmi faturalarda (net − avizoda ödenen) + henüz ödenmemiş sevkiyatta avizoda önceden düşülen kesinti
    //                 + hiçbir faturaya bağlanamayan (eşleşmeyen net eksi / faturasız debit) kesinti
    //   bekleyen    = açık faturalardaki (kesinti + tahmini fiyat farkı) + tahmini mahsupla kapananların kesintisi + ödenmiş faturaların düşülmemiş kesintisi
    $kasa = $odenenToplam;
    $farkSatir = 0;
    foreach ($odenmis as $od) $farkSatir += (int)($od['fark_kurus'] ?? 0);
    $avizoFark = $farkSatir + $farkDiger + $avizNetEksi + $debitKes;
    $bekleyenKesintiTop = $kesintiMahsupKapanan + $bekleyenToplam;
    foreach ($kalanAcik as $a) $bekleyenKesintiTop += (int)$a['kesinti'] + (int)$a['tahmini'];
    $acikKurus = array_sum(array_column($kalanAcik, 'beklenen')) - $bekleyenToplam;
    $cariBakiye = $faturaToplam - $kasa;
    $sapma = $cariBakiye - $avizoFark - $bekleyenKesintiTop - $acikKurus;
    $uyum = ['cari_bakiye_kurus' => $cariBakiye, 'avizo_fark_kurus' => $avizoFark, 'bekleyen_kesinti_kurus' => $bekleyenKesintiTop, 'acik_kurus' => $acikKurus,
        'sapma_kurus' => $sapma, 'tutarli' => $sapma === 0,
        // teşhis (formüle girmez): sapmanın bilinen kaynakları — ödeme artığı (açık fatura kalmadı) ve avizo kalemleri toplamı ≠ ödeme tutarı
        'havuz_artik_kurus' => $havuz, 'kalem_fark_kurus' => $kalemFark, 'avizo_fark_satir_kurus' => $farkSatir, 'avizo_fark_diger_kurus' => $farkDiger + $avizNetEksi + $debitKes];
    if ($sapma !== 0) {
        $neden = [];
        if ($havuz > 0) $neden[] = 'açık faturaya mahsup edilemeyen ödeme artığı ' . siparis_cari_para($havuz) . ' EUR';
        if ($kalemFark !== 0) $neden[] = 'avizo kalemleri toplamı ödeme tutarından ' . siparis_cari_para(abs($kalemFark)) . ' EUR ' . ($kalemFark > 0 ? 'eksik' : 'fazla');
        array_unshift($uyarilar, ['seviye' => 'hata', 'mesaj' => 'Rakamlar Cari bakiyeyle tutmuyor (sapma ' . siparis_cari_para_isaretli($sapma) . ' EUR) — patrona vermeden önce kontrol edin.'
            . ($neden ? ' Bilinen kaynak: ' . implode('; ', $neden) . '.' : '')]);
    }
    // iskonto şüphesi: avizoda ödenen, fatura net tutarından TAM %4 eksik (fatura iskontosu girilmemiş / çift uygulanmış olabilir) — net tutar DEĞİŞTİRİLMEZ, yalnız işaretlenir
    foreach ($odenmis as $od) {
        if (empty($od['iskonto_suphesi'])) continue;
        $uyarilar[] = ['seviye' => 'uyari', 'mesaj' => 'Avizoda ödenen, fatura net tutarından tam %4 eksik: ' . (trim((string)($od['sev']['fatura']['no'] ?? '')) !== '' ? (string)$od['sev']['fatura']['no'] : (string)($od['sev']['order_ref'] ?? ''))
            . ' — fatura iskontosu girilmemiş/çift uygulanmış olabilir (fark ' . siparis_cari_para((int)($od['tam_fark_kurus'] ?? $od['fark_kurus'])) . ' EUR; fatura ' . siparis_cari_para((int)($od['net_tam'] ?? $od['net'])) . ', avizoda ödenen ' . siparis_cari_para((int)$od['odenen']) . ').'];
    }
    // bu hafta rakamı geçmiş ödemelerin (gün bazında) en büyüğünden çok mu?
    $gecmisMax = 0;
    foreach ($avizGunToplam as $t => $k) if ($t <= $bugun && $k > $gecmisMax) $gecmisMax = $k;
    $buAsiri = $gecmisMax > 0 && (int)$bu['beklenen'] * 4 > $gecmisMax * 5;
    if ($buAsiri) $uyarilar[] = ['seviye' => 'uyari', 'mesaj' => 'Bu hafta rakamı geçmiş ödemelerin en büyüğünün (' . siparis_cari_para($gecmisMax) . ' EUR) 1,25 katından fazla (' . siparis_cari_para((int)$bu['beklenen']) . ' EUR): OGL birikmiş gecikmişleri tek seferde ödemeyebilir.'];
    $kalanKisimKurus = array_sum(array_column($kalanAcik, 'kalan_kisim_kurus'));
    $kalanKisimSev = count(array_filter($kalanAcik, static fn(array $a): bool => $a['kalan_kisim']));
    if ($kalanKisimSev > 0) $uyarilar[] = ['seviye' => 'uyari', 'mesaj' => $kalanKisimSev . ' sevkiyatta ikinci araç invoice\'ı sisteme girilmemiş (' . siparis_cari_para($kalanKisimKurus) . ' EUR): tutar sevkiyatın net tutarından hesaplandı (muhasebede kesilmiş olabilir); invoice girilirse avizodaki numarasıyla birebir eşleşir ve tahmini mahsup azalır.'];

    $dupNo = array_filter($noSay, static fn(array $l): bool => count($l) > 1);
    if ($dupNo) $uyarilar[] = ['seviye' => 'bilgi', 'mesaj' => 'Ortak fatura (aynı fatura no birden çok sevkiyatta): ' . implode(' · ', array_map(static fn($no, $l): string => $no . ' (' . implode(', ', $l) . ')', array_keys($dupNo), $dupNo))
        . ' — avizodaki bu faturanın tutarı sevkiyatlara en eskiden başlayarak dağıtıldı (her biri kendi tutarına kadar); tutar yetmezse sonraki sevkiyat açık kalır.'];
    if ($kismiInvSay > 0) $uyarilar[] = ['seviye' => 'uyari', 'mesaj' => $kismiInvSay . ' sevkiyatta sistemdeki invoice sevkiyatın yalnız bir kısmını kapsıyor (toplam ' . siparis_cari_para($kismiInvEksik) . ' EUR eksik; iki araçlı sevkiyatta ikinci invoice ayrı kesilmiş olabilir). Beklenen tutar sevkiyatın TAMAMI üzerinden hesaplandı.'];
    if ($avizHavuz > 0 || $avizHavuzKalem > 0) $uyarilar[] = ['seviye' => 'uyari', 'mesaj' => 'Avizolarda sistemdeki bir invoice\'la eşleşmeyen ' . $avizHavuzKalem . ' fatura kalemi var; eşleşmeyen iade/düzeltme satırları düşüldükten sonra NET ' . siparis_cari_para($avizHavuz) . ' EUR ödeme en eski açık faturalara sırayla mahsup edildi (TAHMİNİ; hangi faturaya ait olduğu kesin bilinmiyor).'];
    if ($elleHavuz > 0) $uyarilar[] = ['seviye' => 'bilgi', 'mesaj' => 'Avizo kalemi olmayan (elle girilmiş) ' . siparis_cari_para($elleHavuz) . ' EUR ödeme de en eski açık faturalara sırayla mahsup edildi (tahmini).'];
    if ($havuz > 0) $uyarilar[] = ['seviye' => 'bilgi', 'mesaj' => 'Mahsup edilemeyen ödeme artığı: ' . siparis_cari_para($havuz) . ' EUR (açık fatura kalmadı).'];
    if ($gecikmeBozuk > 0) $uyarilar[] = ['seviye' => 'bilgi', 'mesaj' => $gecikmeBozuk . ' ödenmiş faturada fatura tarihi ödeme tarihinden sonra (ya da aynı gün) — invoice tarihi sonradan girilmiş görünüyor; bunlar vade öğrenmesine katılmadı.'];
    $pcYok = count(array_filter($kalanAcik, static fn(array $a): bool => $a['pc_yok']));
    if ($pcYok > 0) $uyarilar[] = ['seviye' => 'bilgi', 'mesaj' => $pcYok . ' açık faturanın gelen (PC) fiyatı henüz yok — kesilen fiyat PC\'den yüksek çıkarsa OGL farkı ödemeden düşer; beklenen tutar o kadar azalabilir.'];
    if ($faturasiz) $uyarilar[] = ['seviye' => 'bilgi', 'mesaj' => count($faturasiz) . ' yüklenmiş sevkiyatın invoice\'u bu sistemde üretilmemiş — invoice üretilince takvime girer.'];
    if ($digerPb) $uyarilar[] = ['seviye' => 'bilgi', 'mesaj' => 'EUR dışı ödemeler takvime katılmadı: ' . implode(' · ', array_map(static fn($pb, $k): string => siparis_cari_para((int)$k) . ' ' . $pb, array_keys($digerPb), $digerPb)) . '.'];

    return [
        'param' => ['vade' => $vade, 'vade_oto' => $vadeOto, 'gun' => $gun, 'gun_oto' => $gunOto, 'baz' => (string)$param['baz']],
        'bu_hafta' => ['bas' => $haftaBas, 'son' => siparis_vade_gun_ekle($haftaBas, 6), 'odeme_gunu' => $buOdeme,
            'beklenen_kurus' => (int)$bu['beklenen'], 'kesin_kurus' => (int)$bu['kesin_kurus'], 'kalan_kisim_kurus' => (int)$bu['kalan_kisim_kurus']],
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
            'acik_kurus' => $acikKurus, 'acik_sevkiyat' => count($kalanAcik),
            'odenen_kurus' => $odenenToplam, 'son_odeme' => $son, 'odeme_adet' => count($odemeOzet),
            'faturasiz_sevkiyat' => count($faturasiz), 'faturasiz_kurus' => array_sum(array_map(static fn(array $f): int => (int)($f['gelen_kurus'] ?? 0), $faturasiz)),
            'gecikme' => $ist, 'diger_pb' => $digerPb,
            'fatura_toplam_kurus' => $faturaToplam, 'odenen_fatura_kurus' => $faturaToplam - array_sum(array_map(static fn(array $a): int => (int)$a['net'] - (int)$a['mahsup'], $kalanAcik)),
            'mahsup_kurus' => $havuzToplam - $havuz, 'havuz_kurus' => $havuzToplam, 'invoice_kismi_sevkiyat' => $kismiInvSay,
            // v572 — Cari bakiye uyumu + faturasız kalan kısım + geçmiş ödeme kıyası
            'kasa_kurus' => $kasa, 'avizo_fark_kurus' => $avizoFark, 'uyum' => $uyum,
            'kalan_kisim_kurus' => $kalanKisimKurus, 'kalan_kisim_sevkiyat' => $kalanKisimSev,
            'bu_hafta_kesin_kurus' => (int)$bu['kesin_kurus'], 'bu_hafta_kalan_kisim_kurus' => (int)$bu['kalan_kisim_kurus'],
            'gecmis_odeme_max_kurus' => $gecmisMax, 'bu_hafta_asiri' => $buAsiri,
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
