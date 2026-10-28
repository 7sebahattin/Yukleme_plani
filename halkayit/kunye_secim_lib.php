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
//   Depo/Tasnif kaynak kuralı (v366): HKS HataKodu 108 ("Depo/Tasnif sıfatından
//   sevk ederken, ancak referans künye bildirimini yapan kişiye ve sıfatına geri
//   bildirim yapabilirsiniz") künye×sıfat uyumsuzluğu DEĞİLDİR — karşı taraf
//   (TC + sıfat) yanlıştır. Bu satırlar uyumsuzluk haritasına YAZILMAZ; ayrı
//   kv 'kunye_kaynak_<firma>' haritasına {künye: {tc: {sıfat: tarih}}} olarak
//   öğrenilir (60 gün). Gönderim yolu yine değişmez.
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
// Depo/Tasnif kaynak kuralı (HataKodu 108) öğrenme kaydının ömrü (gün) ve sınırı.
const HKS_KUNYE_KAYNAK_TTL_GUN = 60;
const HKS_KUNYE_KAYNAK_LIMIT = 2000;
// v366 tek seferlik temizlik: 108 satırları eskiden yanlışlıkla "sıfat uyumsuz"
// yazılıyordu. Bu bayrak yoksa ilk okumada uyumsuzluk haritası boşaltılır.
const HKS_KUNYE_SIFAT_TEMIZ_ONEK = 'kunye_sifat_v366_';

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
// $kaynak (Depo/Tasnif kaynak kuralı, v366): null değilse ['tc' => karşı taraf,
// 'kendi' => firmanın VKN'si, 'ogrenilen' => {künye: kaynakTc}] — detay haritası
// FİYATSIZ sevkte de istenir; kaynağı KESİN bilinen ve karşı taraftan farklı
// künyeler denenmez ('atlananKaynakFarkli'). 'kokenHarita' yeniden kullanım içindir.
// $sifatliGetir (v-sıfat filtresi): $kunyeGetir ile AYNI $secenek dizisini alır; HKS'e
// KisiSifat filtresiyle gider (bildirimci sıfatıyla UYUMLU künyeler). ortak.sifatId>0
// ise ÖNCE o çağrılır: dolu → liste odur ('sifatFiltreKullanildi'); boş → 'sifatFiltreBos',
// istisna → 'sifatFiltreHata' (ikisinde de filtresiz eski yol, fail-open).
function hks_plan_kunye_coz_cekirdek(array $ortak, callable $kunyeGetir, ?callable $detayGetir, array $dislanan = [], ?array $kaynak = null, ?callable $sifatliGetir = null): array {
  $ek = ['atlananSifatUyumsuz' => [], 'fiyatSiralamaKullanildi' => false, 'atlananKaynakFarkli' => [], 'kokenHarita' => [], 'sifatFiltreKullanildi' => false];

  $hedef = round((float)($ortak['planKg'] ?? 0), 3);
  if ($hedef <= 0) return ['hata' => 'Plan taslağında toplam kilo yok.'] + $ek;

  $sorgu = (array)($ortak['planSorgu'] ?? []);
  if (empty($sorgu['urunId'])) return ['hata' => 'Plan taslağında ürün bilgisi yok.'] + $ek;

  $aySayisi = (int)($sorgu['aySayisi'] ?? 12);
  $secenek = [
    'urunId'        => $sorgu['urunId'],
    'aySayisi'      => $aySayisi,
    'isletmeTuruId' => (int)($sorgu['isletmeTuruId'] ?? 0),
    'sirala'        => (string)($sorgu['sirala'] ?? 'azalan'),
  ];
  $kunyeler = null;
  if ($sifatliGetir !== null && (int)($ortak['sifatId'] ?? 0) > 0) {
    try {
      $sl = (array)$sifatliGetir($secenek);
      if ($sl) { $kunyeler = $sl; $ek['sifatFiltreKullanildi'] = true; }
      else $ek['sifatFiltreBos'] = true;
    } catch (Throwable $e) {
      $ek['sifatFiltreHata'] = $e->getMessage();
    }
  }
  if ($kunyeler === null) $kunyeler = (array)$kunyeGetir($secenek);

  // (2) Alış fiyatları — yalnız satış fiyatı girildiyse.
  $satisFiyat = hks_kunye_fiyat_sayi($ortak['fiyat'] ?? 0);
  $detayHam = [];
  if (($satisFiyat > 0 || $kaynak !== null) && $detayGetir !== null) {
    try { $detayHam = (array)(($detayGetir($aySayisi))['harita'] ?? []); }
    catch (Throwable $e) { $detayHam = []; }   // best-effort: fiyatsız / kaynaksız devam
  }
  if ($satisFiyat > 0 && $detayGetir !== null) {
    $harita = [];
    try {
      foreach ($detayHam as $no => $det) {
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
  // (3b) Depo/Tasnif kaynak kuralı: kaynağı başka kişi olan künyeler denenmez.
  if ($kaynak !== null) {
    $ek['kokenHarita'] = hks_kunye_koken_haritasi($detayHam, $kaynak['kendi'] ?? '', (array)($kaynak['ogrenilen'] ?? []));
    $hedefTc = hks_kaynak_tc_norm($kaynak['tc'] ?? '');
    $kaynakKg = 0.0;
    foreach ($kunyeler as $k) {
      $n = hks_kunye_no_norm($k['kunyeNo'] ?? '');
      $kalan = (float)($k['kalan'] ?? 0);
      if ($n === '' || $kalan <= 0 || isset($dis[$n]) || $hedefTc === '') continue;
      if (isset($ek['kokenHarita'][$n]) && $ek['kokenHarita'][$n] !== $hedefTc) {
        $dis[$n] = true;
        $ek['atlananKaynakFarkli'][] = (string)$k['kunyeNo'];
        $kaynakKg += $kalan;
      }
    }
    if ($ek['atlananKaynakFarkli']) {
      $atlanNot .= ' ' . count($ek['atlananKaynakFarkli']) . ' künye (' . hks_kunye_kg_metni(round($kaynakKg, 3)) .
        ' KG) başka kişiden geldiği ya da bildirimini kendiniz yaptığınız için atlandı (Depo/Tasnif sevkinde yalnız künyeyi size gönderen kişiye geri sevk yapılabilir).';
    }
  }

  // (4) Sıralama + dağıtım.
  $adaylar = hks_kunye_sirala($kunyeler, $satisFiyat, array_keys($dis));
  $toplamKalan = 0.0;
  foreach ($adaylar as $k) $toplamKalan += (float)$k['kalan'];
  $toplamKalan = round($toplamKalan, 3);

  // "Eğer stok varsa" kuralı: yetmiyorsa HİÇBİR ŞEY gönderilmez (kısmi gönderim yok).
  if ($toplamKalan < $hedef) {
    // Filtre kullanıldıysa: filtrenin dışında kalan kalanlı künyeler (sıfat uyumsuz) bilgi notu.
    if ($ek['sifatFiltreKullanildi']) {
      try {
        $var = [];
        foreach ($kunyeler as $k) $var[hks_kunye_no_norm($k['kunyeNo'] ?? '')] = true;
        $disN = 0; $disKg = 0.0;
        foreach ((array)$kunyeGetir($secenek) as $k) {
          $kl = (float)($k['kalan'] ?? 0);
          if ($kl > 0 && !isset($var[hks_kunye_no_norm($k['kunyeNo'] ?? '')])) { $disN++; $disKg += $kl; }
        }
        if ($disN > 0) {
          $atlanNot .= ' ' . $disN . ' künye (' . hks_kunye_kg_metni(round($disKg, 3)) .
            ' KG) seçilen sıfatla uyumsuz olduğu için HKS listesinde yok.';
        }
      } catch (Throwable $e) { /* not atlanır */ }
    }
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

// HKS satır sonucu "Depo/Tasnif sıfatından sevk ederken, ancak referans künye
// bildirimini yapan kişiye ve sıfatına geri bildirim yapabilirsiniz" (HataKodu 108)
// mi? Bu, künyenin seçilen BİLDİRİMCİ sıfatıyla uyumsuzluğu DEĞİL — karşı taraf
// (TC/sıfat) künyenin kaynağı değil. Kod ya da metin (aksan/harf duyarsız) yeter.
function hks_kaynak_karsi_taraf_hatasi_mi($s): bool {
  if (!is_array($s)) return false;
  if ((int)($s['hataKodu'] ?? 0) === 108) return true;
  $m = (string)($s['mesaj'] ?? '');
  if ($m === '') return false;
  $m = str_replace(['İ', 'I', 'ı'], 'i', $m);
  $m = mb_strtolower($m, 'UTF-8');
  $m = str_replace(["\u{0307}", 'ü', 'ş', 'ğ', 'ç', 'ö'], ['', 'u', 's', 'g', 'c', 'o'], $m);
  return strpos($m, 'bildirimini yapan') !== false || strpos($m, 'referans kunye bildirimini') !== false;
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
    // 108 (Depo/Tasnif → yalnız kaynağa geri sevk) karşı taraf hatasıdır, künyeye ait değil.
    if (hks_kaynak_karsi_taraf_hatasi_mi($s)) continue;
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

function hks_kunye_sifat_temiz_anahtari($firmaId): string {
  return HKS_KUNYE_SIFAT_TEMIZ_ONEK . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$firmaId);
}

function hks_kunye_sifat_uyumsuz_oku($firmaId, ?string $bugun = null): array {
  if (trim((string)$firmaId) === '' || !function_exists('hks_kv_oku')) return [];
  // v366 tek seferlik temizlik (firma başına): 108 satırları eskiden bu haritaya
  // yanlışlıkla yazılıyordu; hangisinin gerçek sıfat hatası olduğu ayırt edilemediği
  // için harita bir kez BOŞALTILIR (değeri düşük, ömrü zaten 30 gün).
  if (function_exists('hks_kv_yaz') && !hks_kv_oku(hks_kunye_sifat_temiz_anahtari($firmaId), null)) {
    hks_kv_yaz(hks_kunye_sifat_kv_anahtari($firmaId), []);
    hks_kv_yaz(hks_kunye_sifat_temiz_anahtari($firmaId), $bugun ?? date('Y-m-d'));
    return [];
  }
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

// 'kunyeler' ucu, KisiSifat-filtreli (uyumlu) liste DOLUysa: ona girmeyen künyeler
// sifatUyumsuz=true olur (mevcut öğrenilmiş bayrak korunur — VEYA). Liste süzülmez.
function hks_kunyelere_sifat_filtre_bayragi(array $kunyeler, array $uyumlu): array {
  if (!$uyumlu) return $kunyeler;   // boş uyumlu liste = fail-open, hiçbir künye işaretlenmez
  $ok = [];
  foreach ($uyumlu as $u) { if (is_array($u)) $ok[hks_kunye_no_norm($u['kunyeNo'] ?? '')] = true; }
  foreach ($kunyeler as &$k) {
    if (!is_array($k)) continue;
    $uyumsuz = !isset($ok[hks_kunye_no_norm($k['kunyeNo'] ?? '')]);
    $k['sifatUyumsuz'] = $uyumsuz || !empty($k['sifatUyumsuz']);
  }
  unset($k);
  return $kunyeler;
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

// ---------------------------------------------------------------------------
// DEPO/TASNİF KAYNAK KURALI (HataKodu 108) — öğrenme kaydı (v366)
// ---------------------------------------------------------------------------
// Harita: {kunyeNo: {tc: {sifatId: 'YYYY-MM-DD'}}} — "bu künyeyi bu TC'ye bu
// sıfatla sevk etmek HKS'ten 108 aldı". Kayıt yalnız İPUCU + ön denetimdir;
// gönderim yolu değişmez (yeniden deneme / ikinci SOAP YOK).

// 108 alan satırların künye no'ları. Eşleme hks_kunye_sifat_hatali_satirlar ile
// aynı: mesajda künye no geçiyorsa o künye (108 mesajı "Referans künye no : …"
// içerir), değilse yalnız sonuç/satır sayısı eşitse sıra eşlemesi.
function hks_kaynak_hatali_kunyeler(array $sonuclar, array $gonderilenSatirlar): array {
  $sonuclar = array_values($sonuclar);
  $satirlar = array_values($gonderilenSatirlar);
  if (!$sonuclar || !$satirlar) return [];
  $siraEsit = count($sonuclar) === count($satirlar);
  $bulunan = [];
  foreach ($sonuclar as $i => $s) {
    if (!is_array($s) || !hks_kaynak_karsi_taraf_hatasi_mi($s)) continue;
    $mesajRakam = preg_replace('/\D+/', ' ', (string)($s['mesaj'] ?? ''));
    $adiGecen = [];
    foreach ($satirlar as $st) {
      $n = hks_kunye_no_norm($st['kunyeNo'] ?? '');
      if ($n !== '' && preg_match('/(^|\s)' . $n . '(\s|$)/', $mesajRakam)) $adiGecen[$n] = (string)$st['kunyeNo'];
    }
    if ($adiGecen) { $bulunan += $adiGecen; continue; }
    if (!$siraEsit) continue;
    $k = (string)($satirlar[$i]['kunyeNo'] ?? '');
    if (hks_kunye_no_norm($k) !== '') $bulunan[hks_kunye_no_norm($k)] = $k;
  }
  return array_values($bulunan);
}

// Harita girdisi: {kunyeNo: {red: {tc: {sifatId: 'YYYY-MM-DD'}}, koken: tc, kokenTarih: 'YYYY-MM-DD'}}
//   red   — "bu künyeyi bu TC'ye bu sıfatla sevk etmek 108 aldı"
//   koken — künyenin KAYNAĞI (bildirimi bize yapan kişi; BildirimSorgu'daki
//           BildirimciTcKimlikVergiNo), 108 anında detay haritasında biliniyorsa.
function hks_kunye_kaynak_tarih_gecerli($tarih, int $sinir, int $bugunTs): bool {
  if (!is_string($tarih) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih)) return false;
  $ts = strtotime($tarih . ' 00:00:00 UTC');
  return $ts !== false && $ts > $sinir && $ts <= $bugunTs;
}

function hks_kunye_kaynak_map_temizle($map, string $bugun, int $ttlGun = HKS_KUNYE_KAYNAK_TTL_GUN): array {
  if (!is_array($map)) return [];
  $bugunTs = strtotime($bugun . ' 00:00:00 UTC');
  if ($bugunTs === false) return [];
  $sinir = $bugunTs - $ttlGun * 86400;
  $temiz = [];
  foreach ($map as $no => $giris) {
    $n = hks_kunye_no_norm($no);
    if ($n === '' || !is_array($giris)) continue;
    $e = [];
    foreach ((array)($giris['red'] ?? []) as $tc => $sifatlar) {
      $t = hks_kunye_no_norm($tc);
      if ($t === '' || !is_array($sifatlar)) continue;
      foreach ($sifatlar as $sifat => $tarih) {
        $sf = (string)(int)$sifat;
        if ($sf !== '0' && hks_kunye_kaynak_tarih_gecerli($tarih, $sinir, $bugunTs)) $e['red'][$t][$sf] = $tarih;
      }
    }
    $k = hks_kunye_no_norm($giris['koken'] ?? '');
    if ($k !== '' && hks_kunye_kaynak_tarih_gecerli($giris['kokenTarih'] ?? null, $sinir, $bugunTs)) {
      $e['koken'] = $k;
      $e['kokenTarih'] = $giris['kokenTarih'];
    }
    if ($e) $temiz[$n] = $e;
  }
  return $temiz;
}

// Saf ekleme: (künye, tc, sıfat) red kayıtlarını ve biliniyorsa künyenin kaynağını
// ($koken: {kunyeNo: kaynakTc}) bugünün tarihiyle yazar, temizler, en yeni
// HKS_KUNYE_KAYNAK_LIMIT künyeyi tutar.
function hks_kunye_kaynak_map_ekle(array $map, array $kunyeNolar, $tc, $sifatId, string $bugun, array $koken = []): array {
  $map = hks_kunye_kaynak_map_temizle($map, $bugun);
  $t = hks_kunye_no_norm($tc);
  $sf = (string)(int)$sifatId;
  $kokenN = [];
  foreach ($koken as $no => $ktc) {
    $a = hks_kunye_no_norm($no); $b = hks_kunye_no_norm($ktc);
    if ($a !== '' && $b !== '') $kokenN[$a] = $b;
  }
  foreach ($kunyeNolar as $no) {
    $n = hks_kunye_no_norm($no);
    if ($n === '') continue;
    $giris = $map[$n] ?? [];
    unset($map[$n]);                                   // en yeni sona (sınırlamada korunur)
    if ($t !== '' && $sf !== '0') {
      $sifatlar = $giris['red'][$t] ?? [];
      unset($giris['red'][$t], $sifatlar[$sf]);         // en son denenen TC sona
      $sifatlar[$sf] = $bugun;
      $giris['red'][$t] = $sifatlar;
    }
    if (isset($kokenN[$n])) { $giris['koken'] = $kokenN[$n]; $giris['kokenTarih'] = $bugun; }
    if ($giris) $map[$n] = $giris;
  }
  if (count($map) > HKS_KUNYE_KAYNAK_LIMIT) $map = array_slice($map, -HKS_KUNYE_KAYNAK_LIMIT, null, true);
  return $map;
}

// Öğrenilmiş kaynaklar: {kunyeNoNorm: kaynakTc}.
function hks_kunye_kaynak_kokenler(array $map): array {
  $l = [];
  foreach ($map as $no => $g) {
    if (is_array($g) && !empty($g['koken'])) $l[(string)$no] = (string)$g['koken'];
  }
  return $l;
}

// Bir künyenin öğrenilmiş bilgisi. kaynakKoken = kesin kaynak (yoksa '');
// kaynakTc = kaynak, bilinmiyorsa en son denenen TC (yalnız öneri);
// kaynakRedSifatlar = kaynakTc için reddedilen sıfatlar; kaynakRed = TC bazında.
function hks_kunye_kaynak_bilgisi(array $map, $kunyeNo): ?array {
  $n = hks_kunye_no_norm($kunyeNo);
  if ($n === '' || empty($map[$n]) || !is_array($map[$n])) return null;
  $red = [];
  foreach ((array)($map[$n]['red'] ?? []) as $tc => $sifatlar) {
    $red[(string)$tc] = array_values(array_map('intval', array_keys((array)$sifatlar)));
  }
  $koken = (string)($map[$n]['koken'] ?? '');
  $tc = $koken !== '' ? $koken : (string)(array_key_last($red) ?? '');
  return ['kaynakTc' => $tc, 'kaynakKoken' => $koken, 'kaynakRedSifatlar' => $red[$tc] ?? [], 'kaynakRed' => (object)$red];
}

// 'kunyeler' ucu: öğrenilmiş kaynak bilgisini künyelere ekler (yalnız kaydı olanlara).
function hks_kunyelere_kaynak_bilgisi(array $kunyeler, array $map): array {
  if (!$map) return $kunyeler;
  foreach ($kunyeler as &$k) {
    if (!is_array($k)) continue;
    $b = hks_kunye_kaynak_bilgisi($map, $k['kunyeNo'] ?? '');
    if ($b) $k += $b;
  }
  unset($k);
  return $kunyeler;
}

// Künyelerin KESİN kaynakları: detay haritasındaki bildirimciTc önce, yoksa
// öğrenilmiş koken. Bildirimci KENDİ VKN'miz ise (künyeyi biz doğurduk — ör.
// müstahsilden Satın Alım) kaynak = kendimiz: Depo/Tasnif sıfatıyla bu künye
// başka kimseye "geri" sevk edilemez (HKS 108; canlı, 15.10.2026) → dışlanır.
// $detayHarita: hks_kunye_detaylari_getir()['harita'] biçimi. Dönüş {norm: tc}.
// Kaynak TC/VKN karşılaştırma biçimi: yalnız rakam; HKS VKN'yi baştaki sıfırları
// atılmış dönebilir ("91880872") → 10 haneye tamamlanır (TC 11 hane, 0'la başlamaz).
function hks_kaynak_tc_norm($tc): string {
  $d = hks_kunye_no_norm($tc);
  return ($d !== '' && strlen($d) < 10) ? str_pad($d, 10, '0', STR_PAD_LEFT) : $d;
}

function hks_kunye_koken_haritasi(array $detayHarita, $kendiVkn, array $ogrenilen = []): array {
  $kendi = hks_kaynak_tc_norm($kendiVkn);
  $l = [];
  foreach ($ogrenilen as $no => $tc) {
    $a = hks_kunye_no_norm($no); $b = hks_kaynak_tc_norm($tc);
    if ($a !== '' && $b !== '' && $b !== $kendi) $l[$a] = $b;
  }
  foreach ($detayHarita as $no => $d) {
    if (!is_array($d)) continue;
    $a = hks_kunye_no_norm($d['kunyeNo'] ?? $no);
    $b = hks_kaynak_tc_norm($d['bildirimciTc'] ?? '');
    if ($a !== '' && $b !== '') $l[$a] = $b;
  }
  return $l;
}

// GÖNDERİM ÖNCESİ ENGEL (saf): kaynağı KESİN bilinen ve karşı taraf TC'sinden
// FARKLI olan satırlar → [['kunyeNo' => …, 'kaynakTc' => …], …]. Kaynağı
// bilinmeyen künye engellenmez.
function hks_kaynak_tc_engeli(array $satirlar, array $kokenHarita, $tc, $kendiVkn = ''): array {
  $t = hks_kaynak_tc_norm($tc);
  $kendi = hks_kaynak_tc_norm($kendiVkn);
  if ($t === '' || !$kokenHarita) return [];
  $l = []; $gor = [];
  foreach ($satirlar as $st) {
    $no = is_array($st) ? (string)($st['kunyeNo'] ?? '') : '';
    $n = hks_kunye_no_norm($no);
    if ($n === '' || isset($gor[$n]) || !isset($kokenHarita[$n])) continue;
    $gor[$n] = true;
    if ($kokenHarita[$n] !== $t) $l[] = ['kunyeNo' => $no, 'kaynakTc' => $kokenHarita[$n],
      'kendi' => $kendi !== '' && $kokenHarita[$n] === $kendi];
  }
  return $l;
}

// GÖNDERİM ÖNCESİ ENGEL (saf): gönderilecek satırlardan hangileri bu TC + sıfat
// ile daha önce 108 aldı? Boş dizi = engel yok.
function hks_kaynak_red_engeli(array $satirlar, array $map, $tc, $sifatId): array {
  $t = hks_kunye_no_norm($tc);
  $sf = (string)(int)$sifatId;
  if ($t === '' || $sf === '0' || !$map) return [];
  $l = [];
  foreach ($satirlar as $st) {
    $n = hks_kunye_no_norm(is_array($st) ? ($st['kunyeNo'] ?? '') : '');
    if ($n !== '' && isset($map[$n]['red'][$t][$sf]) && !in_array((string)$st['kunyeNo'], $l, true)) $l[] = (string)$st['kunyeNo'];
  }
  return $l;
}

// Maskeli TC/VKN (ekran mesajları için): "***1234".
function hks_tc_maske($tc): string {
  $t = hks_kunye_no_norm($tc);
  return $t === '' ? '' : '***' . substr($t, -4);
}

function hks_kunye_kaynak_kv_anahtari($firmaId): string {
  return 'kunye_kaynak_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$firmaId);
}

function hks_kunye_kaynak_oku($firmaId, ?string $bugun = null): array {
  if (trim((string)$firmaId) === '' || !function_exists('hks_kv_oku')) return [];
  return hks_kunye_kaynak_map_temizle(hks_kv_oku(hks_kunye_kaynak_kv_anahtari($firmaId), []), $bugun ?? date('Y-m-d'));
}

function hks_kunye_kaynak_ekle($firmaId, array $kunyeNolar, $tc, $sifatId, array $koken = [], ?string $bugun = null): void {
  if (trim((string)$firmaId) === '' || !$kunyeNolar || !function_exists('hks_kv_yaz')) return;
  $bugun = $bugun ?? date('Y-m-d');
  hks_kv_yaz(hks_kunye_kaynak_kv_anahtari($firmaId),
    hks_kunye_kaynak_map_ekle(hks_kunye_kaynak_oku($firmaId, $bugun), $kunyeNolar, $tc, $sifatId, $bugun, $koken));
}
