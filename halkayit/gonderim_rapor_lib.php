<?php
// =============================================================================
// HAL KAYIT — GÖNDERİM RAPORU KÜTÜPHANESİ (include-only, v494)
// =============================================================================
// Gönderilenler kayıtlarını (hks_gonderilenler) firma + tarih aralığında okur,
// ülke / ürün / plaka / tür süzgeçlerinden geçirir, gruplar ve toplar.
// Sayfa: halkayit/gonderim_rapor.php (ekran + yazdırma + XLSX).
//
// KURALLAR:
//  • SALT OKUNUR — bu dosya hiçbir tabloya yazmaz, HKS'e bağlanmaz.
//  • Kilo/adet Gönderilenler listesiyle AYNI kuraldan: hks_gonderilen_gercek_toplam()
//    (yalnız başarılı satırlar); TC/VKN → ad yine hks_gonderilen_adlari() +
//    hks_gonderilen_ulke_isimle() (tekrar_gonder_lib.php — çağıran yükler).
//  • Tamamen başarısız gönderim (gerçek künye adedi 0) toplamlara ve grup
//    sayılarına GİRMEZ; ayrıntıda "hatalı" olarak görünür.
//  • SQL taşınabilir (MySQL + test SQLite): tarih 'Y-m-d H:i:s' dizgisiyle karşılaştırılır.
//  • Ülke metni önek ayrıştırması app.html gonderilenUlkeParcala() / G_ONEKLER aynasıdır —
//    ikisini birlikte değiştir.
// Çıktı basmaz, exit etmez, oturum/yetki okumaz; .htaccess web'e kapatır.
// =============================================================================

const HKS_GRAPOR_LIMIT = 5000;          // tek raporda en çok okunan gönderim
const HKS_GRAPOR_MAX_GUN = 366;         // tarih aralığı üst sınırı (gün)

/** Grup seçenekleri: anahtar => etiket. */
function hks_grapor_gruplar(): array {
  return ['ulke' => 'Ülke / Karşı taraf', 'urun' => 'Ürün', 'plaka' => 'Plaka', 'firma' => 'Firma'];
}

/** Tür kodları: kod => etiket (app.html gonderilenUlkeParcala etiketleriyle aynı). */
function hks_grapor_turler(): array {
  return [
    'ihracat' => 'İhracat',
    'uretici' => 'Üretici sevk',
    'alim'    => 'Satın alım',
    'sevk'    => 'Sevk etme',
    'satis'   => 'Yurt içi satış',
    'yurtici' => 'Yurt içi',
  ];
}

/** Plaka karşılaştırma biçimi: büyük harf, yalnız harf/rakam. */
function hks_grapor_plaka_norm($p): string {
  return (string)preg_replace('/[^0-9A-Z]/', '', strtoupper(trim((string)$p)));
}

/** Metin karşılaştırma anahtarı (harf duyarsız, TR i/İ). */
function hks_grapor_anahtar($s): string {
  $s = trim((string)preg_replace('/\s+/u', ' ', (string)$s));
  return mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $s), 'UTF-8');
}

function hks_grapor_tarih_gecerli($s): bool {
  if (!is_string($s) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return false;
  return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/**
 * GET → filtre (beyaz liste). $bugun 'Y-m-d' (test için; null = bugün).
 * Döner: [firma, bas, bit, ulke, urun, plaka, tur, grup, notlar[]]
 */
function hks_grapor_filtre(array $get, ?string $bugun = null): array {
  $bugun = ($bugun !== null && hks_grapor_tarih_gecerli($bugun)) ? $bugun : date('Y-m-d');
  $metin = function ($k, int $max = 150) use ($get): string {
    $v = $get[$k] ?? '';
    if (!is_string($v)) return '';
    $v = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v));
    return mb_substr($v, 0, $max, 'UTF-8');
  };
  $notlar = [];
  $varBas = substr($bugun, 0, 8) . '01';
  $bas = $metin('bas', 10); $bit = $metin('bit', 10);
  if ($bas !== '' && !hks_grapor_tarih_gecerli($bas)) { $notlar[] = 'Başlangıç tarihi geçersiz — ayın başı kullanıldı.'; $bas = ''; }
  if ($bit !== '' && !hks_grapor_tarih_gecerli($bit)) { $notlar[] = 'Bitiş tarihi geçersiz — bugün kullanıldı.'; $bit = ''; }
  if ($bit === '') $bit = $bugun;
  if ($bit > $bugun) { $bit = $bugun; $notlar[] = 'Bitiş tarihi bugüne çekildi.'; }
  if ($bas === '') $bas = min($varBas, $bit);
  if ($bas > $bit) { [$bas, $bit] = [$bit, $bas]; $notlar[] = 'Başlangıç bitişten sonraydı — tarihler yer değiştirdi.'; }
  $gun = (int)((strtotime($bit . ' 00:00:00') - strtotime($bas . ' 00:00:00')) / 86400) + 1;
  if ($gun > HKS_GRAPOR_MAX_GUN) {
    $bas = date('Y-m-d', strtotime($bit . ' 00:00:00') - (HKS_GRAPOR_MAX_GUN - 1) * 86400);
    $notlar[] = 'Tarih aralığı en çok ' . HKS_GRAPOR_MAX_GUN . ' gün olabilir — başlangıç ' . date('d.m.Y', strtotime($bas)) . ' yapıldı.';
  }
  $tur = $metin('tur', 20);
  if (!isset(hks_grapor_turler()[$tur])) $tur = '';
  $grup = $metin('grup', 10);
  if (!isset(hks_grapor_gruplar()[$grup])) $grup = 'ulke';
  $firma = $metin('firma', 40);
  if (!preg_match('/^[A-Za-z0-9_\-.]*$/', $firma)) $firma = '';
  return [
    'firma' => $firma, 'bas' => $bas, 'bit' => $bit,
    'ulke' => $metin('ulke'), 'urun' => $metin('urun'),
    'plaka' => hks_grapor_plaka_norm($metin('plaka', 30)),
    'tur' => $tur, 'grup' => $grup, 'notlar' => $notlar,
  ];
}

/**
 * Ülke metni + bildirim_turu → [kod, etiket, ad]. app.html gonderilenUlkeParcala aynası:
 * 'Üreticiden Sevk Alım ← ' → uretici · 'Satın Alım ← ' → alim · 'Yurt içi → ' → sevk / satis / yurtici
 * (bildirim_turu'na göre) · önek yok → ihracat (ad = metin; boş metinde etiket boş).
 * Ad'daki " (İl/İlçe)" adres eki gruplama için atılır.
 */
function hks_grapor_tur_coz($ulkeAd, $bildirimTuru): array {
  $tam = (string)$ulkeAd;
  $onekler = [['Üreticiden Sevk Alım ← ', 'uretici'], ['Satın Alım ← ', 'alim'], ['Yurt içi → ', 'yurtici']];
  foreach ($onekler as [$onek, $tur]) {
    if (strncmp($tam, $onek, strlen($onek)) === 0) {
      $kod = $tur;
      if ($tur === 'yurtici') {
        if ($bildirimTuru === 'SEVK_ETME') $kod = 'sevk';
        elseif ($bildirimTuru === 'SATIS') $kod = 'satis';
      }
      $ad = trim(substr($tam, strlen($onek)));
      $ad = trim((string)preg_replace('#\s*\([^()/]*/[^()]*\)\s*$#u', '', $ad));
      return ['kod' => $kod, 'etiket' => hks_grapor_turler()[$kod], 'ad' => $ad];
    }
  }
  $ad = trim($tam);
  return ['kod' => 'ihracat', 'etiket' => $ad !== '' ? 'İhracat' : '', 'ad' => $ad];
}

/** Firmalar tablosundan [id => ad] (ada göre sıralı). Hata → []. */
function hks_grapor_firmalar(PDO $db, string $tablo): array {
  try {
    $st = $db->query('SELECT id, ad FROM ' . $tablo . ' ORDER BY ad');
    $o = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $o[(string)$r['id']] = (string)$r['ad'];
    return $o;
  } catch (Throwable $e) {
    return [];
  }
}

/** Ham satır → rapor satırı. $tcAd: hks_gonderilen_adlari() çıktısı. */
function hks_grapor_satir_normalize(array $r, array $tcAd): array {
  $veri = json_decode((string)($r['veri'] ?? ''), true);
  if (!is_array($veri)) $veri = [];
  [$kg, $adet] = hks_gonderilen_gercek_toplam($veri, (float)($r['toplam_kg'] ?? 0), (int)($r['adet'] ?? 0));
  $ulke = hks_gonderilen_ulke_isimle((string)($r['ulke_ad'] ?? ''), $tcAd);
  $tur = hks_grapor_tur_coz($ulke, $r['bildirim_turu'] ?? null);
  $hataVar = (int)($r['hata_sayisi'] ?? 0) > 0 || trim((string)($r['genel_hata'] ?? '')) !== '';
  $durum = $adet <= 0 ? 'basarisiz' : ($hataVar ? 'kismi' : 'tam');
  return [
    'id' => (string)($r['id'] ?? ''),
    'zaman' => (string)($r['zaman'] ?? ''),
    'firma_id' => (string)($r['firma_id'] ?? ''),
    'firma' => (string)($r['firma_ad'] ?? ''),
    'plaka' => trim((string)($r['plaka'] ?? '')),
    'plaka_norm' => hks_grapor_plaka_norm($r['plaka'] ?? ''),
    'belge_no' => trim((string)($r['belge_no'] ?? '')),
    'tur' => $tur['kod'], 'tur_etiket' => $tur['etiket'],
    'ad' => $tur['ad'], 'ulke_tam' => $ulke,
    'urun' => trim((string)($r['urun_ad'] ?? '')),
    'adet' => max(0, (int)$adet),
    'kg' => $durum === 'basarisiz' ? 0.0 : (float)$kg,
    'rusum' => $durum === 'basarisiz' ? 0.0 : (float)($r['rusum'] ?? 0),
    'durum' => $durum,
  ];
}

/**
 * Firma + tarih kapsamındaki satırlar (SQL), normalize. Firma süzgeci Gönderilenler
 * listesiyle aynı izolasyon: firma_id eşleşir ya da firma_id'siz ESKİ kayıtta firma ADI.
 * Döner: ['satirlar' => [...], 'kesildi' => bool]
 */
function hks_grapor_oku(PDO $db, string $tablo, array $f, string $firmaAd = '', int $limit = HKS_GRAPOR_LIMIT): array {
  $w = ['zaman >= ?', 'zaman <= ?'];
  $p = [$f['bas'] . ' 00:00:00', $f['bit'] . ' 23:59:59'];
  if (($f['firma'] ?? '') !== '') {
    if ($firmaAd !== '') {
      $w[] = "(firma_id = ? OR (COALESCE(firma_id, '') = '' AND firma_ad = ?))";
      $p[] = $f['firma']; $p[] = $firmaAd;
    } else {
      $w[] = 'firma_id = ?';
      $p[] = $f['firma'];
    }
  }
  $limit = max(1, $limit);
  $st = $db->prepare('SELECT id, zaman, firma_id, firma_ad, plaka, belge_no, ulke_ad, urun_ad, adet, toplam_kg, fiyat, rusum,
      hata_sayisi, genel_hata, bildirim_turu, veri FROM ' . $tablo . ' WHERE ' . implode(' AND ', $w)
      . ' ORDER BY zaman DESC, id DESC LIMIT ' . ($limit + 1));
  $st->execute($p);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  $kesildi = count($rows) > $limit;
  if ($kesildi) $rows = array_slice($rows, 0, $limit);
  $tcAd = hks_gonderilen_adlari($db, array_column($rows, 'ulke_ad'));
  $o = [];
  foreach ($rows as $r) $o[] = hks_grapor_satir_normalize($r, $tcAd);
  return ['satirlar' => $o, 'kesildi' => $kesildi];
}

/** Ülke / ürün (tam, harf duyarsız) · plaka (normalize, içerir) · tür süzgeçleri. */
function hks_grapor_suz(array $satirlar, array $f): array {
  $u = hks_grapor_anahtar($f['ulke'] ?? '');
  $ur = hks_grapor_anahtar($f['urun'] ?? '');
  $pl = hks_grapor_plaka_norm($f['plaka'] ?? '');
  $tur = (string)($f['tur'] ?? '');
  return array_values(array_filter($satirlar, function ($s) use ($u, $ur, $pl, $tur) {
    if ($u !== '' && hks_grapor_anahtar($s['ad']) !== $u) return false;
    if ($ur !== '' && hks_grapor_anahtar($s['urun']) !== $ur) return false;
    if ($pl !== '' && strpos($s['plaka_norm'], $pl) === false) return false;
    if ($tur !== '' && $s['tur'] !== $tur) return false;
    return true;
  }));
}

/**
 * Gruplar: [{ad, gonderim, kunye, kg, rusum, hatali}] — kg azalan, sonra ad.
 * Tamamen başarısız gönderim yalnız 'hatali' sayılır (gönderim/künye/kg/rüsum'a girmez).
 */
function hks_grapor_grupla(array $satirlar, string $grup): array {
  $g = [];
  foreach ($satirlar as $s) {
    switch ($grup) {
      case 'urun':  $ad = $s['urun']; $k = hks_grapor_anahtar($ad); break;
      case 'plaka': $ad = $s['plaka_norm']; $k = $ad; break;
      case 'firma': $ad = $s['firma']; $k = hks_grapor_anahtar($ad); break;
      default:      $ad = $s['ad']; $k = hks_grapor_anahtar($ad); break;
    }
    if ($k === '') { $k = "\0"; $ad = '(boş)'; }
    if (!isset($g[$k])) $g[$k] = ['ad' => $ad, 'gonderim' => 0, 'kunye' => 0, 'kg' => 0.0, 'rusum' => 0.0, 'hatali' => 0];
    if ($s['durum'] === 'basarisiz') { $g[$k]['hatali']++; continue; }
    $g[$k]['gonderim']++;
    $g[$k]['kunye'] += $s['adet'];
    $g[$k]['kg'] += $s['kg'];
    $g[$k]['rusum'] += $s['rusum'];
  }
  $g = array_values($g);
  foreach ($g as &$x) { $x['kg'] = round($x['kg'], 3); $x['rusum'] = round($x['rusum'], 2); }
  unset($x);
  usort($g, function ($a, $b) {
    if ($a['kg'] != $b['kg']) return $a['kg'] < $b['kg'] ? 1 : -1;
    return strcmp(hks_grapor_anahtar($a['ad']), hks_grapor_anahtar($b['ad']));
  });
  return $g;
}

/** Genel toplam (gruplamayla aynı kural). */
function hks_grapor_toplam(array $satirlar): array {
  $t = ['gonderim' => 0, 'kunye' => 0, 'kg' => 0.0, 'rusum' => 0.0, 'hatali' => 0];
  foreach ($satirlar as $s) {
    if ($s['durum'] === 'basarisiz') { $t['hatali']++; continue; }
    $t['gonderim']++;
    $t['kunye'] += $s['adet'];
    $t['kg'] += $s['kg'];
    $t['rusum'] += $s['rusum'];
  }
  $t['kg'] = round($t['kg'], 3);
  $t['rusum'] = round($t['rusum'], 2);
  return $t;
}

/** Select seçenekleri (firma + tarih kapsamı): tekil ülke/ürün/plaka/tür, sıralı. */
function hks_grapor_secenekler(array $satirlar): array {
  $ulke = []; $urun = []; $plaka = []; $tur = [];
  foreach ($satirlar as $s) {
    if ($s['ad'] !== '') $ulke[hks_grapor_anahtar($s['ad'])] ??= $s['ad'];
    if ($s['urun'] !== '') $urun[hks_grapor_anahtar($s['urun'])] ??= $s['urun'];
    if ($s['plaka_norm'] !== '') $plaka[$s['plaka_norm']] = $s['plaka_norm'];
    $tur[$s['tur']] = true;
  }
  $sirala = function (array $a): array {
    $v = array_values($a);
    usort($v, fn($x, $y) => strcmp(hks_grapor_anahtar($x), hks_grapor_anahtar($y)));
    return $v;
  };
  $turler = [];
  foreach (hks_grapor_turler() as $k => $e) if (isset($tur[$k])) $turler[$k] = $e;
  return ['ulke' => $sirala($ulke), 'urun' => $sirala($urun), 'plaka' => $sirala($plaka), 'tur' => $turler];
}

/** Tüm rapor: oku → seçenekler → süz → grupla → toplam. */
function hks_grapor_rapor(PDO $db, string $tablo, array $f, string $firmaAd = '', int $limit = HKS_GRAPOR_LIMIT): array {
  $ok = hks_grapor_oku($db, $tablo, $f, $firmaAd, $limit);
  $sec = hks_grapor_secenekler($ok['satirlar']);
  $s = hks_grapor_suz($ok['satirlar'], $f);
  return [
    'satirlar' => $s,
    'gruplar' => hks_grapor_grupla($s, $f['grup']),
    'toplam' => hks_grapor_toplam($s),
    'secenekler' => $sec,
    'kesildi' => $ok['kesildi'],
  ];
}

/** Ekran KG: tam sayı, binlik nokta, virgülsüz (1.234). */
function hks_grapor_kg($n): string {
  return number_format((float)round((float)$n), 0, ',', '.');
}

/** Ekran tutar: 1.234,56 */
function hks_grapor_tl($n): string {
  return number_format((float)$n, 2, ',', '.');
}

/** Filtre özeti (başlık/yazdırma/XLSX açıklaması). */
function hks_grapor_ozet_metin(array $f, string $firmaAd): string {
  $p = [date('d.m.Y', strtotime($f['bas'])) . ' – ' . date('d.m.Y', strtotime($f['bit']))];
  $p[] = $f['firma'] !== '' ? 'Firma: ' . ($firmaAd !== '' ? $firmaAd : $f['firma']) : 'Tüm firmalar';
  if ($f['ulke'] !== '') $p[] = 'Ülke/karşı taraf: ' . $f['ulke'];
  if ($f['urun'] !== '') $p[] = 'Ürün: ' . $f['urun'];
  if ($f['plaka'] !== '') $p[] = 'Plaka: ' . $f['plaka'];
  if ($f['tur'] !== '') $p[] = 'Tür: ' . hks_grapor_turler()[$f['tur']];
  $p[] = 'Gruplama: ' . hks_grapor_gruplar()[$f['grup']];
  return implode(' · ', $p);
}

/** XLSX sayfaları (xlsx_indir sözleşmesi): Özet + Ayrıntı. */
function hks_grapor_xlsx_sayfalar(array $rapor, array $f, string $firmaAd): array {
  $ozet = hks_grapor_ozet_metin($f, $firmaAd);
  $gs = [];
  foreach ($rapor['gruplar'] as $g) $gs[] = [$g['ad'], $g['gonderim'], $g['kunye'], $g['kg'], $g['rusum'], $g['hatali']];
  $ds = [];
  foreach ($rapor['satirlar'] as $s) {
    $ds[] = [$s['zaman'], $s['firma'], $s['plaka'], $s['belge_no'], $s['tur_etiket'], $s['ad'], $s['urun'],
             $s['adet'], $s['kg'], $s['rusum'],
             $s['durum'] === 'basarisiz' ? 'Hatalı' : ($s['durum'] === 'kismi' ? 'Kısmi' : 'Başarılı')];
  }
  return [
    [
      'ad' => 'Özet', 'baslik' => 'Gönderim Raporu — Özet', 'aciklama' => $ozet,
      'sutunlar' => [
        ['baslik' => hks_grapor_gruplar()[$f['grup']], 'tip' => 'metin'],
        ['baslik' => 'Gönderim', 'tip' => 'tamsayi', 'topla' => true],
        ['baslik' => 'Künye', 'tip' => 'tamsayi', 'topla' => true],
        ['baslik' => 'Toplam KG', 'tip' => 'kg', 'topla' => true],
        ['baslik' => 'Rüsum (TL)', 'tip' => 'tutar', 'topla' => true],
        ['baslik' => 'Hatalı gönderim', 'tip' => 'tamsayi', 'topla' => true],
      ],
      'satirlar' => $gs, 'toplam' => true,
    ],
    [
      'ad' => 'Ayrıntı', 'baslik' => 'Gönderim Raporu — Ayrıntı', 'aciklama' => $ozet,
      'sutunlar' => [
        ['baslik' => 'Tarih', 'tip' => 'tarihsaat'],
        ['baslik' => 'Firma', 'tip' => 'metin'],
        ['baslik' => 'Plaka', 'tip' => 'metin'],
        ['baslik' => 'Belge No', 'tip' => 'metin'],
        ['baslik' => 'Tür', 'tip' => 'metin'],
        ['baslik' => 'Ülke / Karşı taraf', 'tip' => 'metin'],
        ['baslik' => 'Ürün', 'tip' => 'metin'],
        ['baslik' => 'Künye', 'tip' => 'tamsayi', 'topla' => true],
        ['baslik' => 'KG', 'tip' => 'kg', 'topla' => true],
        ['baslik' => 'Rüsum (TL)', 'tip' => 'tutar', 'topla' => true],
        ['baslik' => 'Durum', 'tip' => 'metin'],
      ],
      'satirlar' => $ds, 'toplam' => true,
    ],
  ];
}
