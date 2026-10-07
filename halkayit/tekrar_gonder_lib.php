<?php
// =============================================================================
// HAL KAYIT — "TEKRAR GÖNDER" KÜTÜPHANESİ (include-only)
// =============================================================================
// Gönderilenler listesindeki bir kaydı FORMA DOLU olarak yeniden açmak için
// gereken iki saf işi yapar:
//   1) hks_gonderim_kopyasi()  — gönderim anında taslağın BEYAZ LİSTELİ
//      kopyası (hks_gonderilenler.veri.kopya; migration YOK, JSON'a alan).
//   2) hks_gonderim_tohumu()   — bir gönderilenler satırından, taslakDuzenle()
//      (app.html) biçiminde bir "tohum" kurar: kopya varsa ondan, yoksa (ESKİ
//      kayıt) kolonlardan + referans kataloğundan + Kişi Havuzu'ndan.
//
// Ek (v306): hks_gonderilen_adlari() / hks_gonderilen_ulke_isimle() — Gönderilenler
// ve Taslaklar LİSTESİNDE "Ülke" metnine ad yerine TC/VKN yazılmış kayıtlarda
// (kayıtlı karşı tarafta form adı doldurmaz) adı Kişi Havuzu'ndan gösterir.
// Yalnız GÖRÜNTÜ: saklı kayıt değişmez, tohum/gönderim bu çıktıyı OKUMAZ.
//
// BU DOSYA HİÇBİR ŞEY YAZMAZ: taslak yazmanın TEK yolu hâlâ
// hks_taslak_olustur()'dur (taslak_lib.php → taslak_kaydet ucu). Tohum yalnız
// formu doldurur; kullanıcı plaka/kiloyu düzeltip "Taslağa Kaydet" der ve
// doğrulama oradan geçer. İkinci bir yazma yolu AÇMAYIN.
//
// Bağımlılıklar: kisi_havuzu_lib.php (→ hks_soap.php; saf, ağsız). Bunun
// DIŞINDA hks_tr_normalize() (taslak_lib.php) ve hks_kv_oku() (db.php)
// çağrılır; bunları çağıran api.php zaten yüklemiştir. taslak_lib.php burada
// BİLEREK require EDİLMEZ — config.php üzerinden MySQL bağlantısı ister, test
// (scripts/hks_tekrar_gonder_smoke.php) bellek içi SQLite ile çalışamazdı.
// Test gerçek hks_tr_normalize'ı kaynaktan ayıklar, hks_kv_oku'yu taklit eder.
//
// Çıktı basmaz, exit etmez, oturum/yetki okumaz; .htaccess web'e kapatır.
// =============================================================================

require_once __DIR__ . '/kisi_havuzu_lib.php';   // hks_kisi_tc_ile / hks_kisi_tablo / hks_tc_normalize

// Kopyanın şema sürümü — okuma yalnız bu sürümü tanır, gerisi ESKİ kayıt sayılır.
const HKS_KOPYA_SURUM = 1;

// ortak BEYAZ LİSTESİ (sözleşme §1a). BİLEREK DIŞARIDA kalanlar:
//   kaynak            → beyan bağı; kopyadan açılan taslak beyanı "gönderildi"
//                       saymasın / başka beyana bağlanmasın.
//   gidecekAdres      → gönderim anında işyerinden eklenir (taslak_gonder).
//   eskiTaslakId      → düzenleme izi; tekrar gönderimde eski taslak silinmez.
//   ikinciDogumTarihi / ikinciCep → KİŞİSEL VERİ; okumada Kişi Havuzu'ndan TC ile.
//   sonuç / uniqueId alanları → gönderimin sonucu, taslağın parçası değil.
function hks_kopya_ortak_anahtarlari(): array {
  return [
    'sifatId', 'bildirimTuruId', 'turAd', 'urunId', 'urunAd', 'plaka', 'belgeNo',
    'belgeTipiId', 'yurtIci', 'kayitZorunlu', 'fiyatGonder', 'fiyat', 'isletmeTuruId',
    'ikinciTc', 'ikinciSifatId', 'ikinciAd', 'hedefAdres', 'ilId', 'ilceId', 'beldeId',
    'ulkeAd', 'gidecekIsyeriId', 'gidecekIsyeriAd', 'ulkeId', 'referanssiz', 'uretSevk',
    'ikinciKayitsiz', 'malinNiteligi', 'malinKodNo', 'malinCinsiId', 'uretimSekli',
    'miktarBirimId', 'uretimIlId', 'uretimIlceId', 'uretimBeldeId', 'ithalat',
    'gelenUlkeId', 'analiz', 'planKg', 'planSorgu',
  ];
}

// Plan sorgusunun yalnız künye sorgusunu kuran alanları (api.php hks_plan_kunye_coz).
function hks_kopya_plan_sorgu_anahtarlari(): array {
  return ['urunId', 'aySayisi', 'isletmeTuruId', 'sirala'];
}

// Skaler mi (string/int/float/bool/null)? Dizi/nesne değerler kopyaya girmez —
// beyaz listedeki her alan formda tek bir kutuya karşılık gelir.
function hks_kopya_skaler($v): bool {
  return $v === null || is_string($v) || is_int($v) || is_float($v) || is_bool($v);
}

// Gönderilen taslağın beyaz listeli kopyası. GÖNDERİMİ ASLA BOZMAZ: her hata
// yutulur ve null döner (taslak_gonder geri alınamaz akışın ortasında çağırır).
// Dönüş: ['v' => 1, 'ortak' => [...], 'satirlar' => [['kunyeNo','miktar'], ...]] | null.
function hks_gonderim_kopyasi(array $veri): ?array {
  try {
    $kaynakOrtak = $veri['ortak'] ?? null;
    if (!is_array($kaynakOrtak)) return null;

    $ortak = [];
    foreach (hks_kopya_ortak_anahtarlari() as $k) {
      if (!array_key_exists($k, $kaynakOrtak)) continue;
      $v = $kaynakOrtak[$k];
      if ($k === 'planSorgu') {
        if (!is_array($v)) continue;
        $ps = [];
        foreach (hks_kopya_plan_sorgu_anahtarlari() as $pk) {
          if (array_key_exists($pk, $v) && hks_kopya_skaler($v[$pk])) $ps[$pk] = $v[$pk];
        }
        if ($ps) $ortak['planSorgu'] = $ps;
        continue;
      }
      if (hks_kopya_skaler($v)) $ortak[$k] = $v;
    }
    if (!$ortak) return null;

    $satirlar = [];
    foreach ((is_array($veri['satirlar'] ?? null) ? $veri['satirlar'] : []) as $s) {
      if (!is_array($s)) continue;
      $no = $s['kunyeNo'] ?? null;
      $mk = $s['miktar'] ?? null;
      if (!hks_kopya_skaler($no) || is_bool($no) || $no === null || !is_numeric($mk)) continue;
      $satirlar[] = ['kunyeNo' => (string)$no, 'miktar' => round((float)$mk, 3)];
    }
    return ['v' => HKS_KOPYA_SURUM, 'ortak' => $ortak, 'satirlar' => $satirlar];
  } catch (Throwable $e) {
    return null;
  }
}

// ── Eski kayıt yardımcıları (saf) ───────────────────────────────────────────

// Ad karşılaştırma anahtarı: trim + iç boşlukları teke indir + hks_tr_normalize
// (Türkçe İ/I). Kişi Havuzu adları zaten tek boşlukla saklanır
// (hks_kisi_dogrula); bulanık/parçalı eşleşme YOK — yalnız TAM eşitlik.
function hks_tekrar_ad_anahtari($s): string {
  return hks_tr_normalize(trim((string)preg_replace('/\s+/u', ' ', (string)$s)));
}

// Katalog listesinde ($liste = [['id','ad'], ...]) adı TAM eşleşen TEK kaydı bulur.
// Hiç ya da birden çok eşleşme → null (id ASLA uydurulmaz).
function hks_katalog_tek_eslesme($liste, $ad): ?array {
  $hedef = hks_tekrar_ad_anahtari($ad);
  if ($hedef === '' || !is_array($liste)) return null;
  $bulunan = null;
  foreach ($liste as $x) {
    if (!is_array($x) || !isset($x['id'], $x['ad'])) continue;
    if (hks_tekrar_ad_anahtari($x['ad']) !== $hedef) continue;
    if ($bulunan !== null) return null;   // birden çok → belirsiz
    $bulunan = ['id' => $x['id'], 'ad' => (string)$x['ad']];
  }
  return $bulunan;
}

// Kişi Havuzu'nda AD ile TAM ve TEK eşleşme (eski kayıtta yalnız ad elimizde).
// Aynı adda iki kişi varsa null — yanlış kişinin TC'siyle bildirim kurulmasın.
// Türkçe büyük/küçük harf (İ/ı) SQL harmanlamasına bırakılamayacağı için
// karşılaştırma PHP'de yapılır; havuz küçüktür (liste ucu da 2000 ile sınırlı).
function hks_kisi_ad_ile_tek(PDO $db, string $ad): ?array {
  $hedef = hks_tekrar_ad_anahtari($ad);
  if ($hedef === '') return null;
  $bulunan = null;
  $st = $db->query('SELECT * FROM ' . hks_kisi_tablo() . " WHERE ad <> ''");
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (hks_tekrar_ad_anahtari($r['ad'] ?? '') !== $hedef) continue;
    if ($bulunan !== null) return null;   // birden çok → belirsiz
    $bulunan = $r;
  }
  return $bulunan;
}

// Eski kaydın türünü çözer: önce yapısal bildirim_turu kodu (P3), yoksa
// ulke_ad'a gömülü yön öneki. Dönüş: ['tur' => katalog adı, 'yurtIci', 'referanssiz',
// 'uretSevk', 'sevk', 'karsi' => önekten sonraki ham metin | null].
// 'Yurt içi → ' öneki hem Sevk Etme hem yurt içi Satış'ta kullanılır; kod yoksa
// ayrım fiyattan yapılır (Sevk Etme fiyatsızdır → kayıtta fiyat 0) — aynı kural
// hks_bildirim_turu_kodu()'nda (fiyatGonder) da var.
function hks_eski_tur_coz(array $row): array {
  $ulke = (string)($row['ulke_ad'] ?? '');
  $onekler = [
    'Üreticiden Sevk Alım ← ' => 'URETICIDEN_SEVK_ALIM',
    'Satın Alım ← '           => 'SATIN_ALIM',
    'Yurt içi → '             => 'YURT_ICI',
  ];
  $onekKod = null; $karsi = null;
  foreach ($onekler as $onek => $kod) {
    if (strncmp($ulke, $onek, strlen($onek)) === 0) {
      $onekKod = $kod;
      $karsi = trim(substr($ulke, strlen($onek)));
      break;
    }
  }
  $kod = trim((string)($row['bildirim_turu'] ?? ''));
  if ($kod === '') {
    $kod = $onekKod ?? 'SATIS';
    if ($kod === 'YURT_ICI') $kod = (float)($row['fiyat'] ?? 0) > 0 ? 'SATIS' : 'SEVK_ETME';
  }
  switch ($kod) {
    case 'URETICIDEN_SEVK_ALIM':
      return ['tur' => 'Üreticiden Sevk Alım', 'yurtIci' => true, 'referanssiz' => true, 'uretSevk' => true, 'sevk' => false, 'karsi' => $karsi];
    case 'SATIN_ALIM':
      return ['tur' => 'Satın Alım', 'yurtIci' => true, 'referanssiz' => true, 'uretSevk' => false, 'sevk' => false, 'karsi' => $karsi];
    case 'SEVK_ETME':
      return ['tur' => 'Sevk Etme', 'yurtIci' => true, 'referanssiz' => false, 'uretSevk' => false, 'sevk' => true, 'karsi' => $karsi];
    default:
      // SATIS: yurt içi Satış ('Yurt içi → ' önekli) ya da yurt dışı (ihracat).
      $yurtIci = $onekKod === 'YURT_ICI';
      return ['tur' => 'Satış', 'yurtIci' => $yurtIci, 'referanssiz' => false, 'uretSevk' => false, 'sevk' => false,
              'karsi' => $yurtIci ? $karsi : null];
  }
}

// " (İl/İlçe)" son eki — Üreticiden Sevk Alım ve kayıtsız alıcılı yurt içi
// Satış'ta app.html ulkeAd'a ekler. Yalnız "/" içeren parantez atılır.
function hks_eski_adres_eki_at(string $s): string {
  return trim((string)preg_replace('#\s*\([^()/]*/[^()]*\)\s*$#u', '', $s));
}

// ── Listede karşı taraf adı (v306) ──────────────────────────────────────────

// app.html ulkeAd'ı şöyle yazar: '<önek>' + (ad || TC/VKN) [+ ' (İl/İlçe)'].
// Kayıtlı karşı tarafta ad boş kaldığından metne YALNIZ 10/11 haneli numara
// düşer. Kalıp tam o durumu yakalar: önek + numara + ops. parantezli adres eki.
const HKS_ULKE_AD_KALIBI = '/^(Üreticiden Sevk Alım ← |Satın Alım ← |Yurt içi → )(\d{10,11})(\s*\([^()]*\))?$/u';

// Metindeki karşı taraf numarası (yalnız numara düşmüşse), yoksa null.
function hks_gonderilen_ulke_tc($ulkeAd): ?string {
  return preg_match(HKS_ULKE_AD_KALIBI, trim((string)$ulkeAd), $m) ? $m[2] : null;
}

// Numara yerine adı koyar: önek ve adres eki AYNEN korunur. Havuzda adı yoksa
// (ya da metin zaten ad taşıyorsa) metin DEĞİŞMEZ. $tcAd = [tc => ad].
function hks_gonderilen_ulke_isimle($ulkeAd, array $tcAd): string {
  $ham = (string)$ulkeAd;
  if (!preg_match(HKS_ULKE_AD_KALIBI, trim($ham), $m)) return $ham;
  $ad = trim((string)preg_replace('/\s+/u', ' ', (string)($tcAd[$m[2]] ?? '')));
  if ($ad === '') return $ham;
  return $m[1] . $ad . ($m[3] ?? '');
}

// Verilen ulkeAd metinlerindeki numaralar için Kişi Havuzu'ndan [tc => ad]
// haritası (adı boş olanlar yok). TEK sorgu, parça parça; hata YUTULUR — liste
// adsız da çizilebilir, adlandırma yüzünden liste ucu hiçbir zaman çökmez.
function hks_gonderilen_adlari(PDO $db, array $ulkeAdlari): array {
  $tcler = [];
  foreach ($ulkeAdlari as $u) {
    $tc = hks_gonderilen_ulke_tc($u);
    if ($tc !== null) $tcler[$tc] = true;
  }
  if (!$tcler) return [];
  $harita = [];
  try {
    // Anahtarlar sayısal diziyse PHP int'e çevirir — sorguya HEP metin olarak ver
    // (tc kolonu metindir; başı sıfırlı numara sayısal karşılaştırmayla yanlış eşleşirdi).
    $liste = array_map('strval', array_keys($tcler));
    foreach (array_chunk($liste, 200) as $parca) {
      $yer = implode(',', array_fill(0, count($parca), '?'));
      $st = $db->prepare('SELECT tc, ad FROM ' . hks_kisi_tablo() . " WHERE ad <> '' AND tc IN ($yer)");
      $st->execute($parca);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $harita[(string)$r['tc']] = (string)$r['ad'];
    }
  } catch (Throwable $e) {
    return [];
  }
  return $harita;
}

// ── Tohum ───────────────────────────────────────────────────────────────────

// Bir hks_gonderilenler satırından taslakDuzenle() biçiminde tohum kurar.
// HİÇBİR ŞEY YAZMAZ. Firma izolasyonu ve hedef firmanın varlığı ÇAĞIRANDA
// (api.php gonderilen_tohum) denetlenir; $hedefFirmaId yalnız bilgi amaçlıdır,
// karar $firmaDegisti'dir.
// Dönüş: ['tohum' => ['satirlar' => [...], 'ortak' => [...]],
//         'kaynak' => 'kopya'|'eski', 'plana' => bool, 'notlar' => [string, ...]]
function hks_gonderim_tohumu(PDO $db, array $row, ?string $hedefFirmaId = null, bool $firmaDegisti = false): array {
  $notlar = [];
  $plana = false;

  $veri = json_decode((string)($row['veri'] ?? ''), true);
  $ham = is_array($veri) ? ($veri['kopya'] ?? null) : null;
  // Saklı kopya YENİDEN beyaz listeden geçirilir: DB'deki veri eski/bozuk ya da
  // elle değiştirilmiş olabilir; forma yalnız tanınan alanlar gitsin.
  $kopya = (is_array($ham) && (int)($ham['v'] ?? 0) === HKS_KOPYA_SURUM) ? hks_gonderim_kopyasi($ham) : null;

  if ($kopya !== null) {
    $kaynak = 'kopya';
    $ortak = $kopya['ortak'];
    $satirlar = $kopya['satirlar'];
    // Referanslı + künye satırlı: o künyeler kullanıldı (kalanı düştü) — aynı
    // künyelerle tekrar göndermek stok hatası verir. Plan taslağına çevrilir;
    // künyeler gönderim anında canlı stoktan seçilir (hks_plan_kunye_coz).
    if (empty($ortak['referanssiz']) && $satirlar) {
      $toplam = 0.0;
      foreach ($satirlar as $s) $toplam += (float)$s['miktar'];
      $satirlar = [];
      unset($ortak['planKg'], $ortak['planSorgu']);
      $urunId = $ortak['urunId'] ?? '';
      if ($urunId !== '' && $urunId !== null && (string)$urunId !== '0' && round($toplam, 3) > 0) {
        $ortak['planKg'] = round($toplam, 3);
        $ortak['planSorgu'] = ['urunId' => $urunId, 'aySayisi' => 12, 'isletmeTuruId' => 0, 'sirala' => 'azalan'];
        $plana = true;
        $notlar[] = 'Önceki künyeler kullanıldığı için plan taslağı olarak açıldı — künyeler gönderimde stoktan seçilir.';
      } else {
        $notlar[] = 'Ürün seçip künyeleri yeniden getirin.';
      }
    }
    // Plan kopyası (satır yok, planKg > 0) ve referanssız tek satır ('0') olduğu gibi kalır.
  } else {
    $kaynak = 'eski';
    [$ortak, $satirlar, $plana, $eskiNotlar] = hks_eski_tohum_kur($db, $row);
    $notlar = array_merge($notlar, $eskiNotlar);
  }

  // Kişisel veri kopyada YOK — karşı taraf TC'si varsa Kişi Havuzu'ndan doldurulur
  // (yalnız doluysa). Havuz yoksa/okunamazsa sessizce atlanır: alanlar formda girilir.
  $tc = hks_tc_normalize($ortak['ikinciTc'] ?? '');
  if ($tc !== '') {
    try {
      $kisi = hks_kisi_tc_ile($db, $tc);
      if ($kisi) {
        $dogum = substr(trim((string)($kisi['dogum'] ?? '')), 0, 10);
        if ($dogum !== '') $ortak['ikinciDogumTarihi'] = $dogum;
        $cep = trim((string)($kisi['cep'] ?? ''));
        if ($cep !== '') $ortak['ikinciCep'] = $cep;
      }
    } catch (Throwable $e) { /* havuz okunamadı — form elle doldurulur */ }
  }

  // Firma değişti: bildirimci sıfatı ve gidecek işyeri FİRMAYA ÖZGÜDÜR (başka
  // firmanın işyeri id'siyle bildirim kurulamaz) → boşaltılır, yeniden seçilir.
  if ($firmaDegisti) {
    unset($ortak['sifatId'], $ortak['gidecekIsyeriId'], $ortak['gidecekIsyeriAd']);
    $notlar[] = 'Firma değişti: bildirimci sıfatı ve işyeri/depo yeniden seçilmeli.';
  }

  return [
    'tohum'  => ['satirlar' => array_values($satirlar), 'ortak' => $ortak],
    'kaynak' => $kaynak,
    'plana'  => $plana,
    'notlar' => array_values($notlar),
  ];
}

// ESKİ kayıt (kopya yok): yalnız kolonlardan kısmi kurulum. Katalog eşleşmesi
// yalnız TAM ad ile; bulunamayan alan BOŞ kalır, kullanıcı formda seçer.
// Dönüş: [$ortak, $satirlar, $plana, $notlar].
function hks_eski_tohum_kur(PDO $db, array $row): array {
  $notlar = [];
  $plana = false;
  try { $katalog = hks_kv_oku('listeler_cache', null); } catch (Throwable $e) { $katalog = null; }
  if (!is_array($katalog)) $katalog = [];

  $tur = hks_eski_tur_coz($row);
  $fiyat = (float)($row['fiyat'] ?? 0);
  $kg = round((float)($row['toplam_kg'] ?? 0), 3);
  $urunAd = trim((string)($row['urun_ad'] ?? ''));

  $ortak = [
    'plaka'   => (string)($row['plaka'] ?? ''),
    'belgeNo' => (string)($row['belge_no'] ?? ''),
  ];
  $t = hks_katalog_tek_eslesme($katalog['bildirimTurleri'] ?? null, $tur['tur']);
  if ($t) { $ortak['bildirimTuruId'] = $t['id']; $ortak['turAd'] = $t['ad']; }
  if ($urunAd !== '') $ortak['urunAd'] = $urunAd;
  $urun = $urunAd !== '' ? hks_katalog_tek_eslesme($katalog['urunler'] ?? null, $urunAd) : null;

  $satirlar = [];
  if ($tur['referanssiz']) {
    // Satın Alım / Üreticiden Sevk Alım: malın adı = ürün kataloğu (sMalUrun);
    // cins / üretim yeri / birim kayıtta yok → formda seçilir.
    $ortak += ['yurtIci' => true, 'referanssiz' => true, 'kayitZorunlu' => false,
               'uretSevk' => $tur['uretSevk'], 'fiyatGonder' => true, 'fiyat' => $fiyat];
    if ($tur['uretSevk']) $ortak['hedefAdres'] = true;
    if ($urun) $ortak['malinKodNo'] = $urun['id'];
    if ($kg > 0) $satirlar = [['kunyeNo' => '0', 'miktar' => $kg]];
  } else {
    if ($tur['yurtIci']) {
      $ortak += ['yurtIci' => true, 'kayitZorunlu' => $tur['sevk'], 'fiyatGonder' => !$tur['sevk'],
                 'fiyat' => $tur['sevk'] ? 0 : $fiyat];
    } else {
      $ortak['fiyat'] = $fiyat;
      $ulkeAd = trim((string)($row['ulke_ad'] ?? ''));
      if ($ulkeAd !== '') $ortak['ulkeAd'] = $ulkeAd;
      $ulke = $ulkeAd !== '' ? hks_katalog_tek_eslesme($katalog['ulkeler'] ?? null, $ulkeAd) : null;
      if ($ulke) $ortak['ulkeId'] = $ulke['id'];
    }
    // Referanslı: eski künyeler kullanıldı → plan taslağı (künyeler gönderimde).
    if ($urun) $ortak['urunId'] = $urun['id'];
    if ($urun && $kg > 0) {
      $ortak['planKg'] = $kg;
      $ortak['planSorgu'] = ['urunId' => $urun['id'], 'aySayisi' => 12, 'isletmeTuruId' => 0, 'sirala' => 'azalan'];
      $plana = true;
      $notlar[] = 'Önceki künyeler kullanıldığı için plan taslağı olarak açıldı — künyeler gönderimde stoktan seçilir.';
    } else {
      $notlar[] = 'Ürün seçip künyeleri yeniden getirin.';
    }
  }

  // Karşı taraf (yurt içi): önekten sonraki ad. Ad boşken app.html TC'yi yazar
  // ("Satın Alım ← <TC>") → 10/11 hane rakamsa doğrudan TC. Değilse Kişi
  // Havuzu'nda AD ile TAM ve TEK eşleşme; yoksa/birden çoksa BOŞ.
  // " (İl/İlçe)" eki: Üreticiden Sevk Alım'da HEP vardır → atılır. 'Yurt içi → '
  // önekinde yalnız kayıtsız alıcıda vardır → önce tam ad, eşleşmezse eksiz ad.
  // Satın Alım'da app.html ek koymaz → yalnız tam ad.
  if ($tur['yurtIci'] && $tur['karsi'] !== null && $tur['karsi'] !== '') {
    $ad = $tur['karsi'];
    if ($tur['uretSevk'])        $adaylar = [hks_eski_adres_eki_at($ad)];
    elseif ($tur['referanssiz']) $adaylar = [$ad];
    else                         $adaylar = [$ad, hks_eski_adres_eki_at($ad)];
    $bulundu = false;
    foreach (array_unique($adaylar) as $aday) {
      if ($aday === '') continue;
      if (preg_match('/^\d{10,11}$/', $aday)) {
        $ortak['ikinciTc'] = $aday;
        $bulundu = true;
        break;
      }
      try { $kisi = hks_kisi_ad_ile_tek($db, $aday); } catch (Throwable $e) { $kisi = null; }
      if ($kisi) {
        $ortak['ikinciTc'] = (string)$kisi['tc'];
        $ortak['ikinciAd'] = (string)$kisi['ad'];
        if ((int)($kisi['sifat_id'] ?? 0) > 0) $ortak['ikinciSifatId'] = (int)$kisi['sifat_id'];
        $bulundu = true;
        break;
      }
    }
    if (!$bulundu) {
      $notlar[] = 'Karşı taraf Kişi Havuzu\'nda tek kayıtla eşleşmedi — karşı tarafı seçin.';
    }
  }

  if (!$katalog) {
    $notlar[] = 'Referans listeleri yüklü değil — tür/ürün/ülke eşleştirilemedi; listeleri güncelleyip seçin.';
  }
  $notlar[] = 'Eski kayıt: ürün cinsi, üretim yeri, işyeri gibi alanları kontrol edip seçin.';
  return [$ortak, $satirlar, $plana, $notlar];
}
