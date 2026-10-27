<?php
// =========================================================
// config/siparis_denge.php — "Fiyat Dengeleme" hesabı (v448; include-only, SAF: DB / çıktı / h() YOK)
//
// Ortalama fiyatla kesilen invoice'lar ile sevkiyat sonrası gelen OGL Price Confirmation (PC) fiyatı arasındaki farkı,
// gelecekteki N faturaya küçük düzeltmelerle yaymak için plan üretir. Girdi = siparis_cari_veri() çıktısı (`fatura` bağlı).
// TAM SAYI aritmetiği: fiyat on-binde (4 ondalık), tutar kuruş, ağırlık gram. Float yalnız GET ayrıştırmada (siparis_inv_ondalik_coz).
// İşaret: Fark = Fatura − PC. Negatif = PC'den DÜŞÜK kestik (ileride fazla keseriz); kapatilacak_kurus = −fark (+ = ileride bu kadar FAZLA faturalanmalı).
// Ağırlıklı ortalama kuralı: Σtutar / Σkg (basit ortalama DEĞİL). İskonto % karşılaştırmaya katılmaz (iki tarafta aynı oran varsayımı).
// =========================================================
declare(strict_types=1);
// Doğrudan istek → 404. TAM YOL karşılaştırılır (basename değil — bkz. config/siparis_yazdir.php, v385).
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/siparis_cari.php';   // fiyat_kisa / fark_metin + siparis_inv_ondalik_coz (siparis_invoice.php)

if (!defined('SIPARIS_DENGE_N_VARSAYILAN')) define('SIPARIS_DENGE_N_VARSAYILAN', 5);
if (!defined('SIPARIS_DENGE_N_MAX')) define('SIPARIS_DENGE_N_MAX', 12);
if (!defined('SIPARIS_DENGE_KG_MAX')) define('SIPARIS_DENGE_KG_MAX', 99999999);

/** Tamsayı bölme, yarım YUKARI (+∞ yönüne), işaretli pay için de doğru: floor((2a + b) / 2b). b > 0. */
function siparis_denge_bol(int $a, int $b): int
{
    if ($b <= 0) return 0;
    $n = 2 * $a + $b; $d = 2 * $b;
    $q = intdiv($n, $d);
    if ($n % $d !== 0 && $n < 0) $q--;
    return $q;
}

/**
 * Karşılaştırılan sevkiyatlar: `fiyatli` listesinden `karsilastirma !== null` olanlar; tarih artan (tarihsiz sonda), sonra id.
 * Satır: id, tarih, order_ref, gram, pc_kurus, fatura_kurus, fark_kurus, pc_fiyat (ağırlıklı, on-binde), fatura_fiyat (invoice birim fiyatı), fark_fiyat,
 * kumulatif_fark_kurus. @return list<array<string,mixed>>
 */
function siparis_denge_satirlar(array $cariVeri): array
{
    $liste = [];
    foreach ((array)($cariVeri['fiyatli'] ?? []) as $s) {
        if (!is_array($s) || !is_array($s['karsilastirma'] ?? null) || !is_array($s['fatura'] ?? null)) continue;
        $k = $s['karsilastirma'];
        $gram = (int)$k['gram'];
        if ($gram <= 0) continue;
        $pc = (int)$k['pc_kurus']; $fa = (int)$k['fatura_kurus'];
        $pcF = siparis_denge_bol($pc * 100000, $gram);
        $faF = (int)$s['fatura']['fiyat'];
        $liste[] = ['id' => (int)($s['id'] ?? 0), 'tarih' => ($s['tarih'] ?? null) !== null ? (string)$s['tarih'] : null, 'order_ref' => (string)($s['order_ref'] ?? ''),
            'parti' => array_values((array)($s['parti'] ?? [])), 'ulke' => (string)($s['ulke'] ?? ''), 'plaka' => (string)($s['plaka'] ?? ''),
            'gram' => $gram, 'pc_kurus' => $pc, 'fatura_kurus' => $fa, 'fark_kurus' => $fa - $pc,
            'pc_fiyat' => $pcF, 'fatura_fiyat' => $faF, 'fark_fiyat' => $faF - $pcF, 'kumulatif_fark_kurus' => 0];
    }
    usort($liste, static function (array $a, array $b): int {
        $x = $a['tarih'] === null ? 1 : 0; $y = $b['tarih'] === null ? 1 : 0;
        if ($x !== $y) return $x <=> $y;
        $c = strcmp((string)$a['tarih'], (string)$b['tarih']);
        return $c !== 0 ? $c : ($a['id'] <=> $b['id']);
    });
    $kum = 0;
    foreach ($liste as $i => $r) { $kum += $r['fark_kurus']; $liste[$i]['kumulatif_fark_kurus'] = $kum; }
    return $liste;
}

/**
 * Parametre varsayılanlarının kaynağı. $hesapOzet: ya siparis_cari_veri() çıktısı (anahtar `fiyatli`) ya da hazır
 * ['kg_ort' => int (kg), 'son_pc_fiyat' => int (on-binde)] (eksik/[] → varsayılan yok: kg 0, hedef 0).
 * @return array{kg_ort:int,son_pc_fiyat:int}
 */
function siparis_denge_varsayilanlar(array $hesapOzet): array
{
    if (array_key_exists('fiyatli', $hesapOzet)) {
        $satirlar = siparis_denge_satirlar($hesapOzet);
        if ($satirlar === []) return ['kg_ort' => 0, 'son_pc_fiyat' => 0];
        $gram = 0; foreach ($satirlar as $r) $gram += $r['gram'];
        $son = null;   // "son" = tarihe göre en yeni; tarihli yoksa listedeki son
        foreach ($satirlar as $r) if ($r['tarih'] !== null) $son = $r;
        $son ??= $satirlar[count($satirlar) - 1];
        return ['kg_ort' => intdiv($gram + 500 * count($satirlar), 1000 * count($satirlar)), 'son_pc_fiyat' => (int)$son['pc_fiyat']];
    }
    return ['kg_ort' => max(0, (int)($hesapOzet['kg_ort'] ?? 0)), 'son_pc_fiyat' => max(0, (int)($hesapOzet['son_pc_fiyat'] ?? 0))];
}

/**
 * GET → plan parametreleri (saf). n 1..12 (varsayılan 5) · kg fatura başına net kg 1..99.999.999 (boş = ortalama kg, veri yoksa 0) ·
 * hedef beklenen PC fiyatı €/kg ≤4 ondalık 0<x<1000 (boş = son karşılaştırılan sevkiyatın ağırlıklı PC fiyatı; yoksa 0) · hane 2|3|4 (varsayılan 2).
 * Geçersiz değer → varsayılan + `hata` satırı. Dönüş: n, kg (tam kg), hedef (on-binde), hane, kg_girilen, hedef_girilen (bool: kullanıcı verdi), hata (list<string>).
 * @return array{n:int,kg:int,hedef:int,hane:int,kg_girilen:bool,hedef_girilen:bool,hata:list<string>}
 */
function siparis_denge_param(array $get, array $hesapOzet): array
{
    $v = siparis_denge_varsayilanlar($hesapOzet);
    $hata = [];
    $oku = static function (string $anahtar) use ($get, &$hata): ?string {   // null = verilmedi / boş; false-benzeri hata ayrıca işaretlenir
        if (!array_key_exists($anahtar, $get)) return null;
        $x = $get[$anahtar];
        if (is_string($x)) { $x = trim($x); return $x === '' ? null : $x; }
        if (is_int($x)) return (string)$x;
        $hata[] = '"' . $anahtar . '" değeri geçersiz.';
        return null;
    };

    $n = SIPARIS_DENGE_N_VARSAYILAN;
    if (($s = $oku('n')) !== null) {
        if (preg_match('/^\d{1,3}$/', $s) && (int)$s >= 1 && (int)$s <= SIPARIS_DENGE_N_MAX) $n = (int)$s;
        else $hata[] = 'Fatura sayısı 1–' . SIPARIS_DENGE_N_MAX . ' arasında tam sayı olmalı; ' . SIPARIS_DENGE_N_VARSAYILAN . ' kullanıldı.';
    }

    $hane = 2;
    if (($s = $oku('hane')) !== null) {
        if (in_array($s, ['2', '3', '4'], true)) $hane = (int)$s;
        else $hata[] = 'Fiyat hassasiyeti 2, 3 ya da 4 ondalık olmalı; 2 kullanıldı.';
    }

    $kg = $v['kg_ort']; $kgGirilen = false;
    if (($s = $oku('kg')) !== null) {
        $t = preg_replace('/\s+/', '', $s);
        if (preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', (string)$t)) $t = str_replace(['.', ','], '', (string)$t);   // "18.200" → 18200 (binlik)
        if (preg_match('/^\d{1,8}$/', (string)$t) && (int)$t >= 1 && (int)$t <= SIPARIS_DENGE_KG_MAX) { $kg = (int)$t; $kgGirilen = true; }
        else $hata[] = 'Fatura başına kg 1–' . number_format(SIPARIS_DENGE_KG_MAX, 0, ',', '.') . ' arasında tam sayı olmalı; ortalama kg kullanıldı.';
    }

    $hedef = $v['son_pc_fiyat']; $hedefGirilen = false;
    if (($s = $oku('hedef')) !== null) {
        $f = siparis_inv_ondalik_coz($s, false);
        $f1e4 = $f !== null ? (int)round($f * 10000) : 0;
        if ($f !== null && $f > 0.0 && $f < 1000.0 && abs($f * 10000 - $f1e4) < 0.0001 && $f1e4 > 0 && $f1e4 < 10000000) { $hedef = $f1e4; $hedefGirilen = true; }
        else $hata[] = 'Beklenen PC fiyatı 0 ile 1000 arasında, en çok 4 ondalıklı olmalı; son sevkiyatın PC fiyatı kullanıldı.';
    }

    return ['n' => $n, 'kg' => $kg, 'hedef' => $hedef, 'hane' => $hane, 'kg_girilen' => $kgGirilen, 'hedef_girilen' => $hedefGirilen, 'hata' => $hata];
}

/** Bölüm başına boş iskelet — her anahtar HER durumda mevcuttur. */
function siparis_denge_bos(): array
{
    return [
        'sevkiyatlar' => [], 'satirlar' => [],
        'ozet' => ['adet' => 0, 'gram' => 0, 'pc_kurus' => 0, 'fatura_kurus' => 0, 'fark_kurus' => 0, 'pc_ort' => 0, 'fatura_ort' => 0, 'fark_ort' => 0, 'kapatilacak_kurus' => 0],
        'atlanan' => ['fatura_yok' => 0, 'para_birimi' => 0, 'pc_yok' => 0],
        'plan' => [],
        'plan_ozet' => ['n' => 0, 'gram' => 0, 'hedef_tutar_kurus' => 0, 'toplam_kapanan' => 0, 'kalan' => 0, 'duzeltme_ort_fiyat' => 0, 'duzeltme_oran_bp' => 0, 'duzeltme_oran_pct' => 0.0, 'duzeltme_oran_metin' => '0,00'],
        'uyarilar' => [],
    ];
}

/**
 * Saf hesap (hata fırlatmaz). $cariVeri = siparis_cari_veri() çıktısı (`fatura` bağlı); $param = siparis_denge_param() çıktısı.
 * Dönüş anahtarları: sevkiyatlar (= satirlar, aynı dizi), ozet, atlanan, plan, plan_ozet, uyarilar.
 */
function siparis_denge_hesapla(array $cariVeri, array $param): array
{
    $o = siparis_denge_bos();
    $n = max(1, min(SIPARIS_DENGE_N_MAX, (int)($param['n'] ?? SIPARIS_DENGE_N_VARSAYILAN)));
    $kg = max(0, (int)($param['kg'] ?? 0));
    $hedef = max(0, (int)($param['hedef'] ?? 0));
    $hane = (int)($param['hane'] ?? 2);
    if (!in_array($hane, [2, 3, 4], true)) $hane = 2;

    $sat = siparis_denge_satirlar($cariVeri);
    $o['sevkiyatlar'] = $sat; $o['satirlar'] = $sat;

    // ── atlananlar (yalnız bilgi) ──
    foreach ((array)($cariVeri['fiyatli'] ?? []) as $s) {
        if (!is_array($s)) continue;
        $eur = false;
        foreach ((array)($s['satirlar'] ?? []) as $l) if (!empty($l['fiyatli']) && ($l['pb'] ?? '') === 'EUR') { $eur = true; break; }
        if (!$eur) $o['atlanan']['para_birimi']++;
        elseif (($s['fatura'] ?? null) === null) $o['atlanan']['fatura_yok']++;
    }
    foreach ((array)($cariVeri['yuklenen'] ?? []) as $s) {
        if (is_array($s) && ($s['fatura'] ?? null) !== null && empty($s['fiyatli'])) $o['atlanan']['pc_yok']++;
    }

    // ── özet (kg-ağırlıklı) ──
    $z = &$o['ozet'];
    $z['adet'] = count($sat);
    $fGram = 0;
    foreach ($sat as $r) { $z['gram'] += $r['gram']; $z['pc_kurus'] += $r['pc_kurus']; $z['fatura_kurus'] += $r['fatura_kurus']; $fGram += $r['gram'] * $r['fatura_fiyat']; }
    $z['fark_kurus'] = $z['fatura_kurus'] - $z['pc_kurus'];
    if ($z['gram'] > 0) {
        $z['pc_ort'] = siparis_denge_bol($z['pc_kurus'] * 100000, $z['gram']);
        $z['fatura_ort'] = siparis_denge_bol($fGram, $z['gram']);
        $z['fark_ort'] = $z['fatura_ort'] - $z['pc_ort'];
    }
    $z['kapatilacak_kurus'] = -$z['fark_kurus'];
    unset($z);
    $adet = $o['ozet']['adet'];
    $kapat = $o['ozet']['kapatilacak_kurus'];

    // ── plan ──
    $gramF = $kg * 1000;
    $hedefTutar = $hedef > 0 ? intdiv($gramF * $hedef + 50000, 100000) : 0;
    $negatifFiyat = false;
    if ($adet > 0 && $kg > 0 && $hedef > 0) {
        $unit = 10 ** (4 - $hane);          // hane 2 → 100, 3 → 10, 4 → 1 (on-binde birimi)
        $rem = $kapat; $topKap = 0;
        for ($i = 1; $i <= $n; $i++) {
            $r = $n - $i + 1;
            $idealToplam = $hedefTutar + siparis_denge_bol($rem, $r);
            $fiyat = siparis_denge_bol($idealToplam * 100000, $gramF * $unit) * $unit;
            if ($fiyat < 0) { $fiyat = 0; $negatifFiyat = true; }
            $tutar = intdiv($gramF * $fiyat + 50000, 100000);
            $kap = $tutar - $hedefTutar;
            $rem -= $kap; $topKap += $kap;
            $o['plan'][] = ['sira' => $i, 'gram' => $gramF, 'hedef' => $hedef, 'duzeltme_fiyat' => $fiyat - $hedef, 'fiyat' => $fiyat,
                'tutar_kurus' => $tutar, 'hedef_tutar_kurus' => $hedefTutar, 'kapanan_kurus' => $kap, 'kalan_kurus' => $rem];
        }
        $dz = siparis_denge_bol($topKap * 100000, $gramF * $n);
        $bp = siparis_denge_bol($dz * 10000, $hedef);   // hedefe göre yüzde × 100
        $o['plan_ozet'] = ['n' => $n, 'gram' => $gramF, 'hedef_tutar_kurus' => $hedefTutar, 'toplam_kapanan' => $topKap, 'kalan' => $rem,
            'duzeltme_ort_fiyat' => $dz, 'duzeltme_oran_bp' => $bp, 'duzeltme_oran_pct' => $bp / 100,
            'duzeltme_oran_metin' => ($bp > 0 ? '+' : '') . number_format($bp / 100, 2, ',', '.')];
    }

    // ── uyarılar ──
    $u = [];
    if ($adet === 0) {
        $u[] = ['seviye' => 'bilgi', 'mesaj' => 'Karşılaştırılacak sevkiyat yok: hem PC fiyatı hem üretilmiş invoice (EUR) olan sevkiyat gerekli.'];
    } else {
        if ($kg <= 0 || $hedef <= 0) {
            $u[] = ['seviye' => 'bilgi', 'mesaj' => 'Plan için fatura başına net kg ve beklenen PC fiyatı gerekli; değerleri girip yeniden hesaplayın.'];
        }
        if ($kapat === 0) {
            $u[] = ['seviye' => 'bilgi', 'mesaj' => 'Fatura fiyatları PC ile dengede.'];
        }
        if ($o['plan'] !== [] && $hedefTutar > 0) {
            $dz = (int)$o['plan_ozet']['duzeltme_ort_fiyat'];
            if (abs($dz) * 20 > $hedef) {   // |düzeltme| > hedefin %5'i
                $gerek = intdiv(abs($kapat) * 100 + 3 * $hedefTutar - 1, 3 * $hedefTutar);   // ceil(|D| / (%3 × hedefTutar))
                $oner = $gerek > SIPARIS_DENGE_N_MAX ? SIPARIS_DENGE_N_MAX . "'den fazla" : (string)$gerek;
                $u[] = ['seviye' => 'uyari', 'mesaj' => 'Düzeltme ' . siparis_cari_fark_metin($dz) . ' €/kg (hedefin %' . ltrim($o['plan_ozet']['duzeltme_oran_metin'], '+')
                    . "'si) — büyük sıçrama; fatura sayısını " . $oner . ' yap.'];
            }
            if ($negatifFiyat) $u[] = ['seviye' => 'uyari', 'mesaj' => 'Bazı faturalarda hesaplanan fiyat negatif çıktı ve 0 olarak sınırlandı; fatura sayısını artırın.'];
        }
    }
    $a = $o['atlanan'];
    if ($a['fatura_yok'] > 0) $u[] = ['seviye' => 'bilgi', 'mesaj' => $a['fatura_yok'] . ' sevkiyat için üretilmiş invoice yok; karşılaştırmaya dahil değil.'];
    if ($a['para_birimi'] > 0) $u[] = ['seviye' => 'bilgi', 'mesaj' => $a['para_birimi'] . ' sevkiyat EUR dışı para biriminde; karşılaştırmaya dahil değil (invoice daima EUR).'];
    if ($a['pc_yok'] > 0) $u[] = ['seviye' => 'bilgi', 'mesaj' => $a['pc_yok'] . ' yüklenen sevkiyatın invoice\'u var ama PC fiyatı henüz gelmemiş; karşılaştırmaya dahil değil.'];
    $o['uyarilar'] = $u;
    return $o;
}
