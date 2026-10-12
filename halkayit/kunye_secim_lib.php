<?php
// =============================================================================
// HAL KAYIT — REFERANS KÜNYE SEÇİM KÜTÜPHANESİ (include-only)
// =============================================================================
// Plan taslağı gönderilirken (api.php taslak_gonder → hks_plan_kunye_coz) hangi
// alış künyelerinden ne kadar düşüleceğine karar veren kurallar (kullanıcı kararı):
//
//   1) Satış birim fiyatı girildiyse, ALIŞ fiyatı (künyenin oluştuğu bildirimdeki
//      MalinSatisFiyati) satış fiyatına EN YAKIN künyeler önce. Fiyatı bilinmeyen
//      künye fiyatlıların ARKASINA düşer. Fiyat yoksa bu adım yok sayılır.
//   2) Sonra EN ESKİ tarihli künye önce (künye birikmesini önler; tarihi boş olan
//      en sonda).
//   3) Sonra EN KÜÇÜK kalanlı künye önce (künye yığılmasını önler).
//   4) Son olarak künye no (deterministik sıra).
//
//   Sıfat uyumsuzluğu: gönderimde bir künye bildirimci sıfatı (ortak.sifatId) ile
//   uyumsuz çıkarsa (HKS satır hatası, mesajda "sıfat") o (künye, sıfat) çifti
//   firma bazlı hks_kv'ye yazılır (30 gün); sonraki otomatik seçim o künyeyi o
//   sıfatla DENEMEZ. Kayıt yalnız bir İPUCUDUR — gönderim yolu değişmez: yeniden
//   deneme YOK, ikinci SOAP gönderimi YOK; tek gönderim yolu hks_bildirim_kaydet().
//
// Saf fonksiyonlar (sıralama, dağıtım, mesaj tespiti, künyeye-özgü ayrım, TTL
// temizliği, plan çekirdeği) DB'siz/ağsız çalışır; SOAP çağrıları çekirdeğe
// çağrılabilir (callable) olarak enjekte edilir. kv erişimi yalnız
// hks_kunye_sifat_uyumsuz_oku/_ekle ince sarmalayıcılarındadır (db.php'deki
// hks_kv_oku / hks_kv_yaz; test kendi taklidini tanımlar).
//
// Çıktı basmaz, exit etmez, oturum/yetki okumaz; halkayit/.htaccess web'e kapatır.
// Test: php scripts/hks_kunye_secim_test.php
// =============================================================================

// Fiyatı bilinmeyen künyenin fiyat farkı (fiyatlıların arkasına düşsün diye).
const HKS_KUNYE_FIYATSIZ_FARK = 1.0e12;
// Sıfat uyumsuzluğu kaydının ömrü (gün).
const HKS_KUNYE_SIFAT_TTL_GUN = 30;
// Firma başına en fazla bu kadar künye tutulur (kv satırı şişmesin).
const HKS_KUNYE_SIFAT_LIMIT = 2000;

// Künye no karşılaştırma biçimi: yalnız rakam (HKS künye no'ları rakamdır;
// hks_bildirim_xml de ReferansBildirimKunyeNo'yu böyle yazar).
function hks_kunye_no_norm($no): string {
  return preg_replace('/\D+/', '', (string)$no);
}

// Satış birim fiyatı: sayı ya da "12,5" / "12.5" metni. Geçersiz → 0.
function hks_kunye_fiyat_sayi($v): float {
  if (is_int($v) || is_float($v)) return (float)$v;
  $s = trim((string)$v);
  if ($s === '') return 0.0;
  if (strpos($s, ',') !== false) $s = str_replace(['.', ','], ['', '.'], $s);
  return is_numeric($s) ? (float)$s : 0.0;
}

// Aday künyeleri seçim sırasına dizer. Aday = kalan > 0 ve künye no
// $dislanan'da değil. Anahtar (artan): [fiyatFarki, tarih, kalan, kunyeNo].
function hks_kunye_sirala(array $kunyeler, float $satisFiyat, array $dislanan): array {
  $dis = [];
  foreach ($dislanan as $d) { $n = hks_kunye_no_norm($d); if ($n !== '') $dis[$n] = true; }

  $adaylar = [];
  foreach ($kunyeler as $k) {
    if (!is_array($k)) continue;
    if ((float)($k['kalan'] ?? 0) <= 0) continue;
    if (isset($dis[hks_kunye_no_norm($k['kunyeNo'] ?? '')])) continue;
    $adaylar[] = $k;
  }

  $fark = function ($k) use ($satisFiyat): float {
    if ($satisFiyat <= 0) return 0.0;
    $f = (float)($k['fiyat'] ?? 0);
    if ($f <= 0) return HKS_KUNYE_FIYATSIZ_FARK;
    return round(abs($f - $satisFiyat), 2);
  };

  usort($adaylar, function ($a, $b) use ($fark) {
    $c = $fark($a) <=> $fark($b);
    if ($c !== 0) return $c;
    $ta = (string)($a['tarih'] ?? ''); $tb = (string)($b['tarih'] ?? '');
    if ($ta !== $tb) {
      if ($ta === '') return 1;          // boş tarih en sonda
      if ($tb === '') return -1;
      return strcmp($ta, $tb);           // 'YYYY-MM-DD' → eski önce
    }
    $c = (float)$a['kalan'] <=> (float)$b['kalan'];
    if ($c !== 0) return $c;
    return strcmp((string)($a['kunyeNo'] ?? ''), (string)($b['kunyeNo'] ?? ''));
  });
  return $adaylar;
}

// Sıralı adaylardan hedef kiloya greedy dağıtım (her künyeden min(kalan,
// kalanHedef), 3 ondalık — eski hks_plan_kunye_coz ile aynı aritmetik).
// Dönüş: ['satirlar' => [['kunyeNo','miktar'],...], 'acik' => kalan hedef].
function hks_kunye_dagit(array $siraliAdaylar, float $hedef): array {
  $satirlar = [];
  $kalanHedef = round($hedef, 3);
  foreach ($siraliAdaylar as $k) {
    if ($kalanHedef <= 0) break;
    $kalan = (float)($k['kalan'] ?? 0);
    if ($kalan <= 0) continue;
    $al = round(min($kalan, $kalanHedef), 3);
    if ($al <= 0) continue;
    $satirlar[] = ['kunyeNo' => $k['kunyeNo'], 'miktar' => $al];
    $kalanHedef = round($kalanHedef - $al, 3);
  }
  return ['satirlar' => $satirlar, 'acik' => max(0.0, $kalanHedef)];
}

function hks_kunye_kg_metni(float $kg): string {
  return rtrim(rtrim(number_format($kg, 3, ',', '.'), '0'), ',');
}

// ---------------------------------------------------------------------------
// PLAN ÇEKİRDEĞİ — api.php hks_plan_kunye_coz() buraya devreder.
//   $kunyeGetir(array $secenek): array        → hks_kunyeleri_getir($cfg, …)
//   $detayGetir(int $aySayisi): array|null    → hks_kunye_detaylari_getir($cfg, …)
//                                               (['harita' => [kunyeNo => ['fiyat'..]]])
//   $dislanan: bu sıfatla uyumsuz kayıtlı künye no'ları.
// Detay (fiyat) BEST-EFFORT: istisna / kısmi hata → eksik fiyatlar "fiyatsız"
// sayılır, seçim yine yapılır. Fiyatlar yalnız bellekte tutulur (kv'ye yazılmaz).
// Dönüş: ['satirlar'=>…] ya da ['hata'=>…]; ikisinde de 'atlananSifatUyumsuz'
// ve 'fiyatSiralamaKullanildi' bulunur.
// ---------------------------------------------------------------------------
function hks_plan_kunye_coz_cekirdek(array $ortak, callable $kunyeGetir, ?callable $detayGetir, array $dislanan = []): array {
  $ek = ['atlananSifatUyumsuz' => [], 'fiyatSiralamaKullanildi' => false];

  $hedef = round((float)($ortak['planKg'] ?? 0), 3);
  if ($hedef <= 0) return ['hata' => 'Plan taslağında toplam kilo yok.'] + $ek;

  $sorgu = (array)($ortak['planSorgu'] ?? []);
  if (empty($sorgu['urunId'])) return ['hata' => 'Plan taslağında ürün bilgisi yok.'] + $ek;

  $aySayisi = (int)($sorgu['aySayisi'] ?? 12);
  $kunyeler = (array)$kunyeGetir([
    'urunId'        => $sorgu['urunId'],
    'aySayisi'      => $aySayisi,
    'isletmeTuruId' => (int)($sorgu['isletmeTuruId'] ?? 0),
    'sirala'        => (string)($sorgu['sirala'] ?? 'azalan'),
  ]);

  // (2) Alış fiyatları — yalnız satış fiyatı girildiyse.
  $satisFiyat = hks_kunye_fiyat_sayi($ortak['fiyat'] ?? 0);
  if ($satisFiyat > 0 && $detayGetir !== null) {
    $harita = [];
    try {
      $d = $detayGetir($aySayisi);
      foreach ((array)($d['harita'] ?? []) as $no => $det) {
        $f = (float)(is_array($det) ? ($det['fiyat'] ?? 0) : 0);
        if ($f > 0) $harita[hks_kunye_no_norm(is_array($det) && isset($det['kunyeNo']) ? $det['kunyeNo'] : $no)] = $f;
      }
    } catch (Throwable $e) {
      $harita = [];   // fiyatsız devam
    }
    foreach ($kunyeler as &$k) {
      $n = hks_kunye_no_norm($k['kunyeNo'] ?? '');
      if (isset($harita[$n])) { $k['fiyat'] = $harita[$n]; $ek['fiyatSiralamaKullanildi'] = true; }
    }
    unset($k);
  }

  // (3) Sıfat uyumsuz künyeler — yalnız stokta kalanı olanlar raporlanır.
  $dis = [];
  foreach ($dislanan as $d) { $n = hks_kunye_no_norm($d); if ($n !== '') $dis[$n] = true; }
  $atlanKg = 0.0;
  foreach ($kunyeler as $k) {
    $kalan = (float)($k['kalan'] ?? 0);
    if ($kalan > 0 && isset($dis[hks_kunye_no_norm($k['kunyeNo'] ?? '')])) {
      $ek['atlananSifatUyumsuz'][] = (string)$k['kunyeNo'];
      $atlanKg += $kalan;
    }
  }
  $atlanNot = $ek['atlananSifatUyumsuz']
    ? ' ' . count($ek['atlananSifatUyumsuz']) . ' künye (' . hks_kunye_kg_metni(round($atlanKg, 3)) .
      ' KG) bu sıfatla uyumsuz olduğu için atlandı.'
    : '';

  // (4) Sıralama + dağıtım.
  $adaylar = hks_kunye_sirala($kunyeler, $satisFiyat, array_keys($dis));
  $toplamKalan = 0.0;
  foreach ($adaylar as $k) $toplamKalan += (float)$k['kalan'];
  $toplamKalan = round($toplamKalan, 3);

  // "Eğer stok varsa" kuralı: yetmiyorsa HİÇBİR ŞEY gönderilmez (kısmi gönderim yok).
  if ($toplamKalan < $hedef) {
    return ['hata' => 'Stok yetersiz: plan ' . hks_kunye_kg_metni($hedef) .
      ' KG istiyor, künyelerde toplam ' . hks_kunye_kg_metni($toplamKalan) .
      ' KG kalan var.' . $atlanNot . ' Hiçbir bildirim gönderilmedi, taslak silinmedi — stok girişi ' .
      'yapıldıktan sonra tekrar deneyin veya taslağı düzenleyip kiloyu düşürün.'] + $ek;
  }

  $dag = hks_kunye_dagit($adaylar, $hedef);
  if ($dag['acik'] > 0) {
    return ['hata' => 'Künyeler hedef kiloyu karşılayamadı (' . hks_kunye_kg_metni($dag['acik']) .
      ' KG açık kaldı).' . $atlanNot . ' Hiçbir bildirim gönderilmedi, taslak silinmedi.'] + $ek;
  }
  // HKS tek istekte en fazla 100 bildirim kabul eder (bkz. hks_bildirim_dogrula).
  if (count($dag['satirlar']) > 100) {
    return ['hata' => 'Bu kilo ' . count($dag['satirlar']) . ' künyeye dağıldı; HKS tek seferde en fazla ' .
      '100 bildirime izin veriyor. Taslağı düzenleyip kiloyu bölün. Hiçbir bildirim gönderilmedi.'] + $ek;
  }
  return ['satirlar' => $dag['satirlar']] + $ek;
}

// ---------------------------------------------------------------------------
// SIFAT UYUMSUZLUĞU
// ---------------------------------------------------------------------------

// Satır mesajı bir SIFAT uyumsuzluğundan mı söz ediyor? Türkçe büyük/küçük ve
// noktalı/noktasız i farkları yok sayılır ("SIFAT", "SİFAT", "sıfat", "Sifat").
// Sıfatla ilgisiz hatalar (ör. 263 "Lütfen üreticinin GSM numarasını doğru
// giriniz", 21 Mernis) false kalır.
function hks_sifat_uyumsuz_mesaji_mi($mesaj): bool {
  $s = (string)$mesaj;
  if ($s === '') return false;
  $s = str_replace(['İ', 'I', 'ı'], 'i', $s);
  $s = mb_strtolower($s, 'UTF-8');
  $s = str_replace("\u{0307}", '', $s);   // ayrık noktalı i artığı
  return strpos($s, 'sifat') !== false;
}

// Hangi künyeler KÜNYEYE ÖZGÜ sıfat hatası aldı?
//
// Eşleme: $sonuclar[i] ↔ $gonderilenSatirlar[i] (hks_bildirim_xml satırları
// sırayla yazar, hks_bildirim_kaydet_tek BildirimKayitCevap bloklarını sırayla
// okur). UniqueId istemcide (hks_bildirim_xml) üretilip dışarı verilmediği için
// onunla eşleme YAPILAMAZ; bu yüzden sıra eşlemesi YALNIZ iki dizinin boyu
// eşitse kullanılır. Boylar farklıysa yalnız mesajında künye no geçen satırlar
// (o künyeye) atanır.
//
// Bir satır künyeye özgü sayılır ⇔ hataKodu != 0 VE mesaj sıfat uyumsuzluğu VE
//   (mesaj o künyenin numarasını içeriyor
//    VEYA aynı gönderimde en az bir satır BAŞARILI (künye oluştu)
//    VEYA tek satırlık gönderim).
// Aksi hâlde (çok satırın TÜMÜ sıfat hatasıyla düştü ve mesaj künye no
// içermiyor) hata taslak / karşı taraf düzeyindedir (ör. sıfatın kendisi bu
// bildirim türüne uymuyor) → hiçbir künye kaydedilmez; aksi hâlde tek yanlış
// sıfat seçimi firmanın bütün künyelerini 30 gün karartırdı.
// Dönüş: benzersiz künye no listesi (gönderilen satırdaki yazımıyla).
function hks_kunye_sifat_hatali_satirlar(array $sonuclar, array $gonderilenSatirlar): array {
  $sonuclar = array_values($sonuclar);
  $satirlar = array_values($gonderilenSatirlar);
  if (!$sonuclar || !$satirlar) return [];

  $basariVar = false;
  foreach ($sonuclar as $s) {
    if (!is_array($s)) continue;
    $no = (string)($s['yeniKunyeNo'] ?? '');
    if ($no !== '' && $no !== '0' && empty($s['hataKodu'])) { $basariVar = true; break; }
  }
  $tekSatir = count($satirlar) === 1;
  $siraEsit = count($sonuclar) === count($satirlar);

  $bulunan = [];
  foreach ($sonuclar as $i => $s) {
    if (!is_array($s) || (int)($s['hataKodu'] ?? 0) === 0) continue;
    $mesaj = (string)($s['mesaj'] ?? '');
    if (!hks_sifat_uyumsuz_mesaji_mi($mesaj)) continue;
    $mesajRakam = preg_replace('/\D+/', ' ', $mesaj);

    // Mesajda adı geçen gönderilmiş künye(ler) — sıradan bağımsız, kesin kanıt.
    $adiGecen = [];
    foreach ($satirlar as $st) {
      $n = hks_kunye_no_norm($st['kunyeNo'] ?? '');
      if ($n !== '' && preg_match('/(^|\s)' . $n . '(\s|$)/', $mesajRakam)) $adiGecen[] = (string)$st['kunyeNo'];
    }
    if ($adiGecen) {
      foreach ($adiGecen as $k) $bulunan[hks_kunye_no_norm($k)] = $k;
      continue;
    }
    if (!$siraEsit) continue;                       // kime ait olduğu bilinemez
    if (!$basariVar && !$tekSatir) continue;        // taslak düzeyi hata
    $k = (string)($satirlar[$i]['kunyeNo'] ?? '');
    if (hks_kunye_no_norm($k) !== '') $bulunan[hks_kunye_no_norm($k)] = $k;
  }
  return array_values($bulunan);
}

// Süresi geçen girdileri ayıklar. $map: {kunyeNo: {sifatId: 'YYYY-MM-DD'}}.
// Biçimsiz / gelecek tarihli / TTL'i geçen girdi atılır. $bugun 'YYYY-MM-DD'.
function hks_kunye_sifat_map_temizle($map, string $bugun, int $ttlGun = HKS_KUNYE_SIFAT_TTL_GUN): array {
  if (!is_array($map)) return [];
  $bugunTs = strtotime($bugun . ' 00:00:00 UTC');
  if ($bugunTs === false) return [];
  $sinir = $bugunTs - $ttlGun * 86400;
  $temiz = [];
  foreach ($map as $no => $sifatlar) {
    $n = hks_kunye_no_norm($no);
    if ($n === '' || !is_array($sifatlar)) continue;
    foreach ($sifatlar as $sifat => $tarih) {
      $sf = (string)(int)$sifat;
      if ($sf === '0' || !is_string($tarih) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih)) continue;
      $ts = strtotime($tarih . ' 00:00:00 UTC');
      if ($ts === false || $ts <= $sinir || $ts > $bugunTs) continue;   // 30 gün dolunca düşer
      $temiz[$n][$sf] = $tarih;
    }
  }
  return $temiz;
}

// Haritada bu sıfatla uyumsuz kayıtlı künye no'ları.
function hks_kunye_dislanan_set(array $map, $sifatId): array {
  $sf = (string)(int)$sifatId;
  if ($sf === '0') return [];
  $l = [];
  foreach ($map as $no => $sifatlar) {
    if (is_array($sifatlar) && isset($sifatlar[$sf])) $l[] = (string)$no;
  }
  return $l;
}

// Saf ekleme: haritaya (künye, sıfat) çiftlerini bugünün tarihiyle yazar,
// temizler ve en yeni HKS_KUNYE_SIFAT_LIMIT künyeyi tutar.
function hks_kunye_sifat_map_ekle(array $map, array $kunyeNolar, $sifatId, string $bugun): array {
  $map = hks_kunye_sifat_map_temizle($map, $bugun);
  $sf = (string)(int)$sifatId;
  if ($sf === '0') return $map;
  foreach ($kunyeNolar as $no) {
    $n = hks_kunye_no_norm($no);
    if ($n === '') continue;
    unset($map[$n][$sf]);          // en yeni sona gelsin (sınırlamada korunur)
    $sifatlar = $map[$n] ?? [];
    unset($map[$n]);
    $sifatlar[$sf] = $bugun;
    $map[$n] = $sifatlar;
  }
  if (count($map) > HKS_KUNYE_SIFAT_LIMIT) $map = array_slice($map, -HKS_KUNYE_SIFAT_LIMIT, null, true);
  return $map;
}

function hks_kunye_sifat_kv_anahtari($firmaId): string {
  return 'kunye_sifat_uyumsuz_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$firmaId);
}

// --- İnce kv sarmalayıcıları (db.php hks_kv_oku / hks_kv_yaz) ---------------

function hks_kunye_sifat_uyumsuz_oku($firmaId, ?string $bugun = null): array {
  if (trim((string)$firmaId) === '' || !function_exists('hks_kv_oku')) return [];
  $ham = hks_kv_oku(hks_kunye_sifat_kv_anahtari($firmaId), []);
  return hks_kunye_sifat_map_temizle($ham, $bugun ?? date('Y-m-d'));
}

// Kaydeder; yeni eklenen (önceden bu sıfatla kayıtlı OLMAYAN) künye no'larını döner.
function hks_kunye_sifat_uyumsuz_ekle($firmaId, array $kunyeNolar, $sifatId, ?string $bugun = null): array {
  $sf = (string)(int)$sifatId;
  if (trim((string)$firmaId) === '' || $sf === '0' || !$kunyeNolar || !function_exists('hks_kv_yaz')) return [];
  $bugun = $bugun ?? date('Y-m-d');
  $map = hks_kunye_sifat_uyumsuz_oku($firmaId, $bugun);
  $yeni = [];
  foreach ($kunyeNolar as $no) {
    $n = hks_kunye_no_norm($no);
    if ($n !== '' && !isset($map[$n][$sf]) && !in_array((string)$no, $yeni, true)) $yeni[] = (string)$no;
  }
  hks_kv_yaz(hks_kunye_sifat_kv_anahtari($firmaId), hks_kunye_sifat_map_ekle($map, $kunyeNolar, $sf, $bugun));
  return $yeni;
}

// 'kunyeler' ucu: sifatId verildiyse her künyeye sifatUyumsuz bayrağı ekler.
function hks_kunyelere_sifat_bayragi(array $kunyeler, array $map, $sifatId): array {
  $dis = array_flip(array_map('hks_kunye_no_norm', hks_kunye_dislanan_set($map, $sifatId)));
  foreach ($kunyeler as &$k) {
    if (is_array($k)) $k['sifatUyumsuz'] = isset($dis[hks_kunye_no_norm($k['kunyeNo'] ?? '')]);
  }
  unset($k);
  return $kunyeler;
}
