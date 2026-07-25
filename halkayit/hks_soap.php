<?php
// =============================================================================
// HKS PANEL - SOAP İSTEMCİSİ
// HKS (Hal Kayıt Sistemi) web servisine cURL ile doğrudan bağlanır.
// Captcha yok; kimlik doğrulama UserName + Password + ServicePassword ile.
// Bu dosya, Node.js server.js'teki SOAP mantığının birebir PHP çevirisidir.
// =============================================================================

const HKS_SVC_NS = 'http://www.gtb.gov.tr//WebServices';
const HKS_NS_SC = 'http://schemas.datacontract.org/2004/07/GTB.HKS.Bildirim.ServiceContract';
const HKS_NS_MODEL = 'http://schemas.datacontract.org/2004/07/GTB.HKS.Bildirim.Model';
// Genel servisi (Iller/Ilceler/Beldeler/Depolar/Subeler/HalIciIsyeri) DTO'ları
// AYRI namespace kullanır — Bildirim.ServiceContract DEĞİL. İstek parametreleri
// (IlId, IlceId, TcKimlikVergiNo) bu namespace'te gönderilmezse sunucu 0 kabul
// edip boş liste döner. (Canlı İller yanıtından doğrulandı.)
const HKS_NS_GENEL_SC = 'http://schemas.datacontract.org/2004/07/GTB.HKS.Genel.ServiceContract';
const HKS_NS_GENEL_MODEL = 'http://schemas.datacontract.org/2004/07/GTB.HKS.Genel.Model';

function hks_endpoint($servis) {
  return 'https://hks.hal.gov.tr/WebServices/' . $servis . 'Service.svc';
}

$GLOBALS['HKS_HATA_ACIKLAMA'] = [
  'GTBGLB00000001' => 'Beklenmeyen hata oluştu (sunucu taraflı).',
  'GTBGLB00000011' => 'Kullanıcı bilgileri yanlış.',
  'GTBGLB00000012' => 'Kullanıcı belli bir süre bloklandı.',
  'GTBGLB00000013' => 'Kullanıcı bilgilerinden en az biri boş.',
  'GTBGLB00000014' => 'Servis şifresi geçici olarak iptal edilmiş.',
  'GTBGLB00000015' => 'Bu servis için yetkiniz yok.',
];

function hks_esc($s) {
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

// SOAP zarfını gönder, cevap gövdesini döndür
function hks_soap_cagir($servis, $metod, $icerikXml) {
  $zarf =
    '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>' .
    $icerikXml . '</s:Body></s:Envelope>';
  $action = HKS_SVC_NS . '/I' . $servis . 'Service/' . $metod;

  $ch = curl_init(hks_endpoint($servis));
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $zarf,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => [
      'Content-Type: text/xml; charset=utf-8',
      'SOAPAction: "' . $action . '"',
    ],
    // HKS üretim sertifikası geçerli; doğrulama açık kalsın.
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
  ]);
  $cevap = curl_exec($ch);
  if ($cevap === false) {
    $hata = curl_error($ch);
    curl_close($ch);
    throw new Exception('Bağlantı hatası: ' . $hata);
  }
  $GLOBALS['HKS_SON_HAM'] = is_string($cevap) ? $cevap : '';  // teşhis: son ham (namespace'li) yanıt
  $kod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($kod !== 200) {
    throw new Exception('HTTP ' . $kod . ': ' . substr($cevap, 0, 800));
  }
  return $cevap;
}

// Teşhis: en son SOAP çağrısının ham (namespace'li) yanıtı — kırpılmış.
function hks_son_ham($limit = 3500) {
  $h = $GLOBALS['HKS_SON_HAM'] ?? '';
  return preg_replace('/\s+/', ' ', substr((string)$h, 0, $limit));
}
// Teşhis: son yanıttaki tüm xmlns URI'lerini (benzersiz) listeler — namespace tespiti için.
function hks_ns_listesi() {
  $h = $GLOBALS['HKS_SON_HAM'] ?? '';
  preg_match_all('/xmlns(?::[A-Za-z0-9_]+)?="([^"]+)"/', (string)$h, $m);
  return array_values(array_unique($m[1]));
}
// Teşhis: son yanıtın ilk ~1200 karakterini XML etiketleri GÖRÜNÜR biçimde döndürür
// (< ve > yerine ‹ › konur — böylece tarayıcı innerHTML'de etiketleri yutmaz).
function hks_son_ham_gorunur($limit = 1600) {
  $h = preg_replace('/\s+/', ' ', substr((string)($GLOBALS['HKS_SON_HAM'] ?? ''), 0, $limit));
  return strtr($h, ['<' => '‹', '>' => '›']);
}

// Namespace öneklerini soy: <a:KunyeNo> -> <KunyeNo>
function hks_sadelestir($xml) {
  return preg_replace('/<(\/?)[A-Za-z0-9_]+:/', '<$1', $xml);
}
function hks_deger($xml, $etiket) {
  if (preg_match('/<' . $etiket . '(?:\s[^>]*)?>([\s\S]*?)<\/' . $etiket . '>/', $xml, $m)) {
    return $m[1];
  }
  return null;
}
function hks_bloklar($xml, $etiket) {
  preg_match_all('/<' . $etiket . '(?:\s[^>]*)?>([\s\S]*?)<\/' . $etiket . '>/', $xml, $m);
  return $m[1];
}
function hks_islem_kontrol($xml) {
  $kod = hks_deger($xml, 'IslemKodu');
  if ($kod === 'GTBWSRV0000001') return null;
  $hatalar = [];
  foreach (hks_bloklar($xml, 'ErrorModel') as $b) {
    $mesaj = trim((string)hks_deger($b, 'Mesaj'));
    $aciklama = isset($GLOBALS['HKS_HATA_ACIKLAMA'][$mesaj]) ? $GLOBALS['HKS_HATA_ACIKLAMA'][$mesaj] : null;
    $hatalar[] = $aciklama ? ($mesaj . ' — ' . $aciklama) : ($mesaj ?: ('Hata kodu: ' . hks_deger($b, 'HataKodu')));
  }
  return count($hatalar) ? implode(' | ', $hatalar) : ('İşlem başarısız (' . ($kod ?: 'kod yok') . ')');
}

// Ortak istek zarfı: Istek + Password + ServicePassword + UserName (şema sırası)
function hks_taban_istek($zarfAdi, $istekXml, $cfg) {
  return '<' . $zarfAdi . ' xmlns="' . HKS_SVC_NS . '">' .
    $istekXml .
    '<Password>' . hks_esc($cfg['password']) . '</Password>' .
    '<ServicePassword>' . hks_esc($cfg['servicePassword']) . '</ServicePassword>' .
    '<UserName>' . hks_esc($cfg['userName']) . '</UserName>' .
    '</' . $zarfAdi . '>';
}

// Ad/Id listesi döndüren basit servisler
function hks_liste_getir($servis, $metod, $zarfAdi, $dtoEtiket, $adAlani, $cfg) {
  $xml = hks_soap_cagir($servis, $metod, hks_taban_istek($zarfAdi, '<Istek/>', $cfg));
  $duz = hks_sadelestir($xml);
  $hata = hks_islem_kontrol($duz);
  if ($hata) throw new Exception($metod . ': ' . $hata);
  $sonuc = [];
  foreach (hks_bloklar($duz, $dtoEtiket) as $b) {
    $ad = trim((string)hks_deger($b, $adAlani));
    if ($ad !== '') {
      $sonuc[] = ['id' => (int)hks_deger($b, 'Id'), 'ad' => $ad];
    }
  }
  return $sonuc;
}

// Tüm referans listeleri
function hks_tum_listeler($cfg) {
  return [
    'zaman' => date('c'),
    'urunler' => hks_liste_getir('Urun', 'UrunServiceUrunler', 'BaseRequestMessageOf_UrunlerIstek', 'UrunDTO', 'UrunAdi', $cfg),
    'ulkeler' => hks_liste_getir('Genel', 'GenelServisUlkeler', 'BaseRequestMessageOf_UlkelerIstek', 'UlkeDTO', 'UlkeAdi', $cfg),
    'isletmeTurleri' => hks_liste_getir('Genel', 'GenelServisIsletmeTurleri', 'BaseRequestMessageOf_IsletmeTurleriIstek', 'IsletmeTuruDTO', 'IsletmeTuruAdi', $cfg),
    'sifatlar' => hks_liste_getir('Bildirim', 'BildirimServisSifatListesi', 'BaseRequestMessageOf_SifatIstek', 'SifatDTO', 'SifatAdi', $cfg),
    'bildirimTurleri' => hks_liste_getir('Bildirim', 'BildirimServisBildirimTurleri', 'BaseRequestMessageOf_BildirimTurleriIstek', 'BildirimTuruDTO', 'BildirimTuruAdi', $cfg),
    'belgeTipleri' => hks_liste_getir('Bildirim', 'BildirimServisBelgeTipleriListesi', 'BaseRequestMessageOf_BelgeTipleriIstek', 'BelgeTipiDTO', 'BelgeTipiAdi', $cfg),
  ];
}

// ── Referanssız bildirim için ürün referansları (Satın Alım akışı) ────────
// HKS'te Satın Alım REFERANSSIZ bildirimdir: künye seçilmez, malın tam tanımı
// girilir. docx (BildirimMalBilgileriDTO) şu yardımcı servisleri işaret eder:
//   MalinNiteligi → UrunServiceMalinNiteligi
//   MalinKodNo    → UrunServiceUrunler        (zaten 'urunler' olarak var)
//   UretimSekli   → UrunServiceUretimSekilleri
//   MalinCinsiId  → UrunServiceUrunCinsleri
//   MiktarBirimId → UrunServiceUrunBirimleri
// Zarf/DTO adları mevcut desenden türetildi; KESİN DEĞİL. Bu yüzden her servis
// TEK TEK denenir — biri hata verse de diğerleri döner, hata metni raporlanır.
function hks_urun_listeleri($cfg) {
  $tanim = [
    'nitelikler'    => ['UrunServiceMalinNiteligi',    'BaseRequestMessageOf_MalinNiteligiIstek',   'MalinNiteligiDTO',  'MalinNiteligiAdi'],
    'uretimSekli'   => ['UrunServiceUretimSekilleri',  'BaseRequestMessageOf_UretimSekilleriIstek', 'UretimSekliDTO',    'UretimSekliAdi'],
    // DİKKAT: alan adı UrunBirimAdi (UrunBirim*i*Adi DEĞİL) — canlı yanıttan doğrulandı.
    'birimler'      => ['UrunServiceUrunBirimleri',    'BaseRequestMessageOf_UrunBirimleriIstek',   'UrunBirimiDTO',     'UrunBirimAdi'],
  ];
  $sonuc = ['zaman' => date('c'), 'hatalar' => []];
  foreach ($tanim as $anahtar => [$metod, $zarf, $dto, $adAlani]) {
    try {
      $sonuc[$anahtar] = hks_liste_getir('Urun', $metod, $zarf, $dto, $adAlani, $cfg);
      // Teşhis: DTO'nun TÜM alanlarını görmek için ilk bloğu ham olarak da ver
      // (UrunCinsiDTO'da hangi üst-kimlik alanı var — ürün/tür — bunu gösterir).
      $__h = hks_son_ham(4000);
      if (preg_match('/<[a-z]:' . $dto . '>.*?<\/[a-z]:' . $dto . '>/s', $__h, $__m)) {
        $sonuc['ornek'][$anahtar] = strtr($__m[0], ['<' => '‹', '>' => '›']);
      }
      if (!count($sonuc[$anahtar])) {
        $sonuc['hatalar'][$anahtar] = 'Boş liste döndü. HAM: ' . substr($__h, 0, 700);
      }
    } catch (Throwable $e) {
      $sonuc[$anahtar] = [];
      $sonuc['hatalar'][$anahtar] = $e->getMessage();
    }
  }
  return $sonuc;
}

// Ürün cinsleri — ÜRÜNE BAĞLIDIR (istek UrunId alır). DTO: UrunCinsiAdi, Id,
// UretimSekliId, UrunId, UrunKodu, Ithalmi. Aynı cins adı her ÜRETİM ŞEKLİ için
// ayrı kayıt olarak döner (Geleneksel/Organik/İyi Tarım) — bu yüzden seçim
// yapılırken üretim şekline göre de süzülmelidir.
// docx kuralı: İthalat işlemlerinde yalnız Ithalmi=true olan cinsler, ithalat
// dışı işlemlerde yalnız Ithalmi=false olanlar kullanılabilir.
function hks_urun_cinsleri($cfg, $urunId, &$ham = null) {
  $istek = '<Istek xmlns:a="' . HKS_NS_SC . '"><a:UrunId>' . (int)$urunId . '</a:UrunId></Istek>';
  $xml = hks_soap_cagir('Urun', 'UrunServiceUrunCinsleri',
    hks_taban_istek('BaseRequestMessageOf_UrunCinsleriIstek', $istek, $cfg));
  $duz  = hks_sadelestir($xml);
  $ham  = preg_replace('/\s+/', ' ', substr($duz, 0, 1200));
  $hata = hks_islem_kontrol($duz);
  if ($hata) throw new Exception('UrunCinsleri: ' . $hata . ' || HAM: ' . $ham);

  $sonuc = [];
  foreach (hks_bloklar($duz, 'UrunCinsiDTO') as $b) {
    $ad = trim((string)hks_deger($b, 'UrunCinsiAdi'));
    if ($ad === '') continue;
    $ith = (string)hks_deger($b, 'Ithalmi');
    $sonuc[] = [
      'id'            => (int)hks_deger($b, 'Id'),
      'ad'            => $ad,
      'uretimSekliId' => (int)hks_deger($b, 'UretimSekliId'),
      'urunId'        => (int)hks_deger($b, 'UrunId'),
      'urunKodu'      => trim((string)hks_deger($b, 'UrunKodu')),
      'ithal'         => (stripos($ith, 'true') !== false),
    ];
  }
  return $sonuc;
}

// Bir takvim ayı geri git (servis "1 ay" sınırını takvim ayı olarak sayıyor)
function hks_bir_ay_once(DateTime $t) {
  $x = clone $t;
  $gun = (int)$x->format('j');
  $x->modify('first day of this month');
  $x->modify('-1 month');
  $ayGun = (int)$x->format('t'); // önceki ayın gün sayısı
  $x->setDate((int)$x->format('Y'), (int)$x->format('n'), min($gun, $ayGun));
  return $x;
}

// Tek pencere için referans künye sorgusu.
// $baslangic/$bitis NULL ise tarih GÖNDERİLMEZ → HKS "son 50 künye" döndürür
// (docx: tarihler opsiyonel; verilirse 50 sınırı kalkar ama en fazla 1 ay aralık).
// HKS web sitesindeki künye ekranı da tarihsiz çalışır.
// Tek pencere için referans künye sorgusu. Tarih aralığı HER ZAMAN gönderilir
// (docx: iki tarih arası en fazla 1 ay) — bu yüzden çağıran taraf aylık
// pencerelere bölmek zorundadır (bkz. hks_kunyeleri_getir).
function hks_kunye_penceresi($cfg, $secenek, DateTime $baslangic, DateTime $bitis) {
  $p = ['<Istek xmlns:a="' . HKS_NS_SC . '">'];
  $p[] = '<a:BaslangicTarihi>' . $baslangic->format('Y-m-d\TH:i:s') . '</a:BaslangicTarihi>';
  $p[] = '<a:BitisTarihi>' . $bitis->format('Y-m-d\TH:i:s') . '</a:BitisTarihi>';
  $p[] = '<a:KalanMiktariSifirdanBuyukOlanlar>true</a:KalanMiktariSifirdanBuyukOlanlar>';
  // KisiSifat BİLEREK gönderilmiyor: gönderilince bazı künyeler listeden düşüyor.
  $p[] = '<a:KunyeNo>0</a:KunyeNo>';
  $p[] = '<a:MalinSahibiTcKimlikVergiNo>' . hks_esc($cfg['vergiNo']) . '</a:MalinSahibiTcKimlikVergiNo>';
  if (!empty($secenek['urunId'])) $p[] = '<a:UrunId>' . (int)$secenek['urunId'] . '</a:UrunId>';
  $p[] = '</Istek>';

  $xml = hks_soap_cagir('Bildirim', 'BildirimServisReferansKunyeler',
    hks_taban_istek('BaseRequestMessageOf_ReferansKunyeIstek', implode('', $p), $cfg));
  $duz = hks_sadelestir($xml);
  $hata = hks_islem_kontrol($duz);
  if ($hata) throw new Exception($hata);

  $sonuc = [];
  foreach (hks_bloklar($duz, 'ReferansKunyeDTO') as $b) {
    $sonuc[] = [
      'kunyeNo' => hks_deger($b, 'KunyeNo'),
      'tarih' => substr((string)hks_deger($b, 'BildirimTarihi'), 0, 10),
      'urun' => hks_deger($b, 'MalinAdi'),
      'cins' => hks_deger($b, 'MalinCinsi'),
      'tur' => hks_deger($b, 'MalinTuru'),
      'kalan' => (float)hks_deger($b, 'KalanMiktar'),
      'miktar' => (float)hks_deger($b, 'MalinMiktari'),
      'birim' => hks_deger($b, 'MiktarBirimiAd'),
      'uretici' => hks_deger($b, 'UreticiTcKimlikVergiNo'),
      // Malın GELDİĞİ yerin işletme türü / işyeri (docx: ReferansKunyeDTO'ya
      // GidecekYerTuruId + GidecekIsyeriId eklendi). İstek şemasında işyeri
      // türü FİLTRESİ yok; bu yüzden filtre yanıt üzerinden uygulanır.
      'isletmeTuruId' => (int)hks_deger($b, 'GidecekYerTuruId'),
      'isyeriId'      => (int)hks_deger($b, 'GidecekIsyeriId'),
    ];
  }
  return $sonuc;
}

// Aylık pencereleri sırayla sorgula, birleştir (mükerrer künyeleri ayıkla).
// Tarih HER ZAMAN gönderilir (docx: pencere başına en fazla 1 ay) — bu yüzden
// geniş bir aralık için ay sayısı kadar ardışık SOAP çağrısı gerekir.
function hks_kunyeleri_getir($cfg, $secenek) {
  $ay = max(1, min(60, (int)($secenek['aySayisi'] ?? 12)));  // varsayılan 1 yıl, azami 5 yıl
  $birlesik = [];
  $bitis = new DateTime();
  for ($i = 0; $i < $ay; $i++) {
    $baslangic = hks_bir_ay_once($bitis);
    foreach (hks_kunye_penceresi($cfg, $secenek, $baslangic, $bitis) as $k) $birlesik[$k['kunyeNo']] = $k;
    $bitis = $baslangic;
  }
  $sonuc = array_values($birlesik);

  // İşyeri türü filtresi — HKS istek şemasında böyle bir alan YOK; künyenin
  // GidecekYerTuruId'si (yanıttan) ile eşleşenler süzülür. 0/boş = tüm türler.
  $isyTur = (int)($secenek['isletmeTuruId'] ?? 0);
  if ($isyTur > 0) {
    $sonuc = array_values(array_filter($sonuc, fn($k) => (int)$k['isletmeTuruId'] === $isyTur));
  }

  // Sıralama: 'azalan' = yeni → eski (HKS sitesi varsayılanı), 'artan' = eski → yeni.
  $azalan = (($secenek['sirala'] ?? 'artan') === 'azalan');
  usort($sonuc, function ($a, $b) use ($azalan) {
    $c = strcmp((string)$a['tarih'], (string)$b['tarih']);
    return $azalan ? -$c : $c;
  });
  return $sonuc;
}

// Bildirim kayıt XML'i (100 künyeye kadar tek istekte)
// İki mod:
//  • YURT DIŞI (mevcut/varsayılan): IkinciKisi.YurtDisiMi=true, GidecekUlkeId,
//    MalinSatisFiyat gönderilir. (Satış + yurt dışı ihracat)
//  • YURT İÇİ ($ortak['yurtIci']): IkinciKisi (KisiSifat+TcKimlikVergiNo+YurtDisiMi=false),
//    GidecekIsyeriId (işletme türüne göre depo/şube/hal içi), Sevk Etme'de fiyat YOK.
// DataContract alan sırası her karmaşık tipte ALFABETİK (case-sensitive).
function hks_bildirim_xml($satirlar, $ortak) {
  $yurtIci = !empty($ortak['yurtIci']);
  // Fiyat gönderilsin mi? Yurt dışı (mevcut) → her zaman. Yurt içi → frontend'in
  // 'fiyatGonder' bayrağı (Sevk Etme=false, yurt içi Satış=true). Geriye dönük:
  // bayrak yoksa (mevcut export) fiyat gönderilir.
  $fiyatGonder = $yurtIci ? !empty($ortak['fiyatGonder']) : true;

  $kayitlar = [];
  foreach ($satirlar as $i => $s) {
    $uid = 'HKSPHP-' . time() . '-' . $i . '-' . bin2hex(random_bytes(3));

    // — Mal bilgileri —
    // REFERANSSIZ ($ortak['referanssiz']): malın TAM tanımı zorunlu (docx
    // "Referanssız Bildirimlerde": MalinKodNo, UrunCinsi, UretimSekli,
    // UretimIl/Ilce/BeldeId boş olamaz; İthalat sıfatında GelenUlkeId de).
    // REFERANSLI: künye zaten malı tanımlar → miktar (+ Satış/Satın Alım'da fiyat).
    // DataContract alfabetik sıra korunur.
    if (!empty($ortak['referanssiz'])) {
      $m = [];
      $m[] = '<b:AnalizeGonderilecekMi>' . (!empty($ortak['analiz']) ? 'true' : 'false') . '</b:AnalizeGonderilecekMi>';
      if (!empty($ortak['gelenUlkeId'])) $m[] = '<b:GelenUlkeId>' . (int)$ortak['gelenUlkeId'] . '</b:GelenUlkeId>';
      $m[] = '<b:MalinCinsiId>' . (int)$ortak['malinCinsiId'] . '</b:MalinCinsiId>';
      $m[] = '<b:MalinKodNo>' . (int)$ortak['malinKodNo'] . '</b:MalinKodNo>';
      $m[] = '<b:MalinMiktari>' . (float)$s['miktar'] . '</b:MalinMiktari>';
      $m[] = '<b:MalinNiteligi>' . (int)$ortak['malinNiteligi'] . '</b:MalinNiteligi>';
      if ($fiyatGonder) $m[] = '<b:MalinSatisFiyat>' . (float)($ortak['fiyat'] ?? 0) . '</b:MalinSatisFiyat>';
      $m[] = '<b:MiktarBirimId>' . (int)$ortak['miktarBirimId'] . '</b:MiktarBirimId>';
      $m[] = '<b:UretimBeldeId>' . (int)$ortak['uretimBeldeId'] . '</b:UretimBeldeId>';
      $m[] = '<b:UretimIlId>' . (int)$ortak['uretimIlId'] . '</b:UretimIlId>';
      $m[] = '<b:UretimIlceId>' . (int)$ortak['uretimIlceId'] . '</b:UretimIlceId>';
      $m[] = '<b:UretimSekli>' . (int)$ortak['uretimSekli'] . '</b:UretimSekli>';
      $mal = implode('', $m);
    } else {
      $mal = '<b:MalinMiktari>' . (float)$s['miktar'] . '</b:MalinMiktari>';
      if ($fiyatGonder) $mal .= '<b:MalinSatisFiyat>' . (float)($ortak['fiyat'] ?? 0) . '</b:MalinSatisFiyat>';
    }

    // — İkinci kişi (b: alfabetik: AdSoyad, CepTel, KisiSifat, TcKimlikVergiNo, YurtDisiMi) —
    if ($yurtIci) {
      $ik = [];
      if (!empty($ortak['ikinciAd']))  $ik[] = '<b:AdSoyad>' . hks_esc($ortak['ikinciAd']) . '</b:AdSoyad>';
      if (!empty($ortak['ikinciCep'])) $ik[] = '<b:CepTel>' . hks_esc($ortak['ikinciCep']) . '</b:CepTel>';
      $ik[] = '<b:KisiSifat>' . (int)$ortak['ikinciSifatId'] . '</b:KisiSifat>';
      $ik[] = '<b:TcKimlikVergiNo>' . hks_esc($ortak['ikinciTc']) . '</b:TcKimlikVergiNo>';
      $ik[] = '<b:YurtDisiMi>false</b:YurtDisiMi>';
      $ikinci = implode('', $ik);
    } else {
      $ikinci = '<b:YurtDisiMi>true</b:YurtDisiMi>';
    }

    // — Gidecek yer (b: alfabetik) —
    $gidecek = [];
    if (!empty($ortak['plaka']))      $gidecek[] = '<b:AracPlakaNo>' . hks_esc($ortak['plaka']) . '</b:AracPlakaNo>';
    if (!empty($ortak['belgeNo']))    $gidecek[] = '<b:BelgeNo>' . hks_esc($ortak['belgeNo']) . '</b:BelgeNo>';
    if (!empty($ortak['belgeTipiId'])) $gidecek[] = '<b:BelgeTipi>' . (int)$ortak['belgeTipiId'] . '</b:BelgeTipi>';
    if ($yurtIci && !empty($ortak['hedefAdres'])) {
      // Kayıtsız alıcı (yurt içi Satış): il/ilçe/belde adresi.
      // Alfabetik: GidecekYerBeldeId, GidecekYerIlId, GidecekYerIlceId, GidecekYerIsletmeTuruId
      $gidecek[] = '<b:GidecekYerBeldeId>' . (int)$ortak['beldeId'] . '</b:GidecekYerBeldeId>';
      $gidecek[] = '<b:GidecekYerIlId>' . (int)$ortak['ilId'] . '</b:GidecekYerIlId>';
      $gidecek[] = '<b:GidecekYerIlceId>' . (int)$ortak['ilceId'] . '</b:GidecekYerIlceId>';
      $gidecek[] = '<b:GidecekYerIsletmeTuruId>' . (int)$ortak['isletmeTuruId'] . '</b:GidecekYerIsletmeTuruId>';
    } elseif ($yurtIci) {
      // Kayıtlı alıcı / Sevk Etme: GidecekIsyeriId (‹ GidecekYerIsletmeTuruId)
      $gidecek[] = '<b:GidecekIsyeriId>' . (int)$ortak['gidecekIsyeriId'] . '</b:GidecekIsyeriId>';
      $gidecek[] = '<b:GidecekYerIsletmeTuruId>' . (int)$ortak['isletmeTuruId'] . '</b:GidecekYerIsletmeTuruId>';
    } else {
      $gidecek[] = '<b:GidecekUlkeId>' . (int)$ortak['ulkeId'] . '</b:GidecekUlkeId>';
      $gidecek[] = '<b:GidecekYerIsletmeTuruId>' . (int)$ortak['isletmeTuruId'] . '</b:GidecekYerIsletmeTuruId>';
    }

    $kayitlar[] = '<a:BildirimKayitIstek>' .
      '<a:BildirimMalBilgileri>' . $mal . '</a:BildirimMalBilgileri>' .
      '<a:BildirimTuru>' . (int)$ortak['bildirimTuruId'] . '</a:BildirimTuru>' .
      '<a:BildirimciBilgileri><b:KisiSifat>' . (int)$ortak['sifatId'] . '</b:KisiSifat></a:BildirimciBilgileri>' .
      '<a:IkinciKisiBilgileri>' . $ikinci . '</a:IkinciKisiBilgileri>' .
      '<a:MalinGidecekYerBilgileri>' . implode('', $gidecek) . '</a:MalinGidecekYerBilgileri>' .
      // Referanssız bildirimlerde "0" gönderilir (docx).
      '<a:ReferansBildirimKunyeNo>' .
        (!empty($ortak['referanssiz']) ? '0' : preg_replace('/\D/', '', (string)$s['kunyeNo'])) .
      '</a:ReferansBildirimKunyeNo>' .
      '<a:UniqueId>' . $uid . '</a:UniqueId>' .
      '</a:BildirimKayitIstek>';
  }
  return '<Istek xmlns:a="' . HKS_NS_SC . '" xmlns:b="' . HKS_NS_MODEL . '">' . implode('', $kayitlar) . '</Istek>';
}

function hks_bildirim_kaydet($cfg, $satirlar, $ortak) {
  $istekXml = hks_bildirim_xml($satirlar, $ortak);
  $xml = hks_soap_cagir('Bildirim', 'BildirimServisBildirimKaydet',
    hks_taban_istek('BaseRequestMessageOf_ListOf_BildirimKayitIstek', $istekXml, $cfg));
  $duz = hks_sadelestir($xml);
  $genelHata = hks_islem_kontrol($duz);

  $sonuclar = [];
  foreach (hks_bloklar($duz, 'BildirimKayitCevap') as $b) {
    $sonuclar[] = [
      'yeniKunyeNo' => hks_deger($b, 'YeniKunyeNo'),
      'hataKodu' => (int)hks_deger($b, 'HataKodu'),
      'mesaj' => hks_deger($b, 'Mesaj'),
      'miktar' => (float)hks_deger($b, 'MalinMiktari'),
      'rusum' => (float)hks_deger($b, 'RusumMiktari'),
      'kayitTarihi' => hks_deger($b, 'KayitTarihi'),
      'uniqueId' => hks_deger($b, 'UniqueId'),
    ];
  }

  // TEŞHİS: hata varsa ham (şifresiz) yanıtı da döndür — kök nedeni görmek için.
  // ($duz namespace'siz yanıttır; şifreler yalnızca İSTEK'te bulunur, yanıtta değil.)
  $hataVar = $genelHata !== null || count(array_filter($sonuclar, fn($s) => (int)$s['hataKodu'] !== 0)) > 0;
  $ham = $hataVar ? preg_replace('/\s+/', ' ', substr($duz, 0, 1500)) : '';

  return ['genelHata' => $genelHata, 'sonuclar' => $sonuclar, 'ham' => $ham];
}

// ── Stok Özeti ────────────────────────────────────────────────────────────
// HKS'te ayrı bir "stok" web servisi yoktur. Sitedeki "Stok Sorgulama" raporu,
// firmaya YAPILAN bildirimlerin (gelen mal = referans künyeler) kalan miktar > 0
// olanlarının ürün / cins / tür bazında toplamıdır.
//
// Kaynak: BildirimServisBildirimciyeYapilanBildirimListesi — firmaya yapılan
// (gelen) bildirimleri KalanMiktariSifirdanBuyukOlanlar=true + tarih aralığı ile
// getirir. UrunId GEREKTİRMEZ (ReferansKunyeler ister). Dönen BildirimSorguDTO
// alanları: KalanMiktar, MalinAdi, MalinCinsi, MalinTuru, MiktarBirimiAd.
// (Not: generic BildirimServisBildirimSorgu üretimde ActionNotSupported veriyor;
// bu yüzden yönlü liste metodu kullanılır. Alan sırası DataContract için alfabetik.)
// Tek aylık pencere için firmaya yapılan (gelen) künyeleri getirir.
// Bu metod da "en fazla 1 ay" kuralına tabidir; bu yüzden pencere pencere çağrılır.
function hks_stok_penceresi($cfg, DateTime $baslangic, DateTime $bitis) {
  $p = ['<Istek xmlns:a="' . HKS_NS_SC . '">'];
  // Alfabetik alan sırası (DataContract serileştirmesi):
  $p[] = '<a:BaslangicTarihi>' . $baslangic->format('Y-m-d\TH:i:s') . '</a:BaslangicTarihi>';
  $p[] = '<a:BitisTarihi>' . $bitis->format('Y-m-d\TH:i:s') . '</a:BitisTarihi>';
  $p[] = '<a:KalanMiktariSifirdanBuyukOlanlar>true</a:KalanMiktariSifirdanBuyukOlanlar>';
  $p[] = '<a:KunyeNo>0</a:KunyeNo>';        // 0 → tüm bildirimler
  $p[] = '<a:KunyeTuru>1</a:KunyeTuru>';    // ZORUNLU: 1 = referans (stok), 2 = nihai tüketim
  $p[] = '</Istek>';

  $xml = hks_soap_cagir('Bildirim', 'BildirimServisBildirimciyeYapilanBildirimListesi',
    hks_taban_istek('BaseRequestMessageOf_BildirimSorguIstek', implode('', $p), $cfg));
  $duz  = hks_sadelestir($xml);
  $hata = hks_islem_kontrol($duz);
  if ($hata) throw new Exception($hata);

  $rows = [];
  foreach (hks_bloklar($duz, 'BildirimSorguDTO') as $b) {
    $kalan = (float)hks_deger($b, 'KalanMiktar');
    if ($kalan <= 0) continue;
    $rows[] = [
      'kunyeNo' => (string)hks_deger($b, 'KunyeNo'),
      'urun'    => trim((string)hks_deger($b, 'MalinAdi')),
      'cins'    => trim((string)hks_deger($b, 'MalinCinsi')),
      'tur'     => trim((string)hks_deger($b, 'MalinTuru')),
      'birim'   => trim((string)hks_deger($b, 'MiktarBirimiAd')) ?: 'Kg',
      'kalan'   => $kalan,
    ];
  }
  return $rows;
}

function hks_stok_ozet($cfg, $aySayisi = 60) {
  $ay = max(1, min(60, (int)$aySayisi));

  // Aylık (takvim ayı) pencereleri sırayla sorgula, künyeleri KunyeNo ile birleştir.
  $birlesik = [];
  $bitis = new DateTime();
  for ($i = 0; $i < $ay; $i++) {
    $baslangic = hks_bir_ay_once($bitis);
    foreach (hks_stok_penceresi($cfg, $baslangic, $bitis) as $r) {
      $k = $r['kunyeNo'] !== '' ? $r['kunyeNo'] : ('idx_' . $i . '_' . count($birlesik));
      $birlesik[$k] = $r;   // aynı künye tek kez sayılır
    }
    $bitis = $baslangic;
  }

  $grup = [];
  foreach ($birlesik as $r) {
    $urun  = $r['urun']; $cins = $r['cins']; $tur = $r['tur'];
    $birim = $r['birim'] !== '' ? $r['birim'] : 'Kg';
    $anahtar = mb_strtolower($urun . '|' . $cins . '|' . $tur . '|' . $birim, 'UTF-8');
    if (!isset($grup[$anahtar])) {
      $grup[$anahtar] = ['urun' => $urun, 'cins' => $cins, 'tur' => $tur,
                         'birim' => $birim, 'kalan' => 0.0, 'kunyeAdet' => 0];
    }
    $grup[$anahtar]['kalan']     += (float)$r['kalan'];
    $grup[$anahtar]['kunyeAdet'] += 1;
  }
  $liste = array_values($grup);
  usort($liste, function ($a, $b) {
    $c = strcmp((string)$a['urun'], (string)$b['urun']);
    return $c !== 0 ? $c : strcmp((string)$a['cins'], (string)$b['cins']);
  });
  return $liste;
}

// ── Toplu Künye (Bildirim Çoklu Künye Basım) ──────────────────────────────
// BildirimServisTopluKunye — bir plakaya/belgeye ait künyeleri tarih ile listeler.
// Kural: BildirimTarihi zorunlu; AracPlakaNo veya BelgeNo'dan en az biri dolu olmalı.
// Dönen TopluKunyeDTO: MalinKunyeNo, MalinAdi, MalinMiktar, MiktarBirimAd,
// Nereden, Nereye, Bildirimci, MalınSahibAdi, AracPlakaNo, KayitTarihi, BelgeNo...
function hks_toplu_kunye($cfg, $tarih, $plaka = '', $belgeNo = '') {
  $tarih   = trim((string)$tarih);
  $plaka   = trim((string)$plaka);
  $belgeNo = trim((string)$belgeNo);
  if ($tarih === '') throw new Exception('Bildirim tarihi zorunludur.');
  if ($plaka === '' && $belgeNo === '') throw new Exception('Araç plakası veya belge no girilmelidir.');

  $td = DateTime::createFromFormat('Y-m-d', substr($tarih, 0, 10)) ?: null;
  $tarihXml = $td ? $td->format('Y-m-d\T00:00:00') : $tarih;

  $p = ['<Istek xmlns:a="' . HKS_NS_SC . '">'];
  // Alfabetik alan sırası (DataContract): AracPlakaNo, BelgeNo, BildirimTarihi
  // ÖNEMLİ: string alanlar HER ZAMAN gönderilir (boşsa boş string). Alan atlanınca
  // sunucuda null gelip beklenmeyen hata (GTBGLB00000001) oluşuyor.
  $p[] = '<a:AracPlakaNo>' . hks_esc(strtoupper($plaka)) . '</a:AracPlakaNo>';
  $p[] = '<a:BelgeNo>' . hks_esc($belgeNo) . '</a:BelgeNo>';
  $p[] = '<a:BildirimTarihi>' . $tarihXml . '</a:BildirimTarihi>';
  $p[] = '</Istek>';

  $xml = hks_soap_cagir('Bildirim', 'BildirimServisTopluKunye',
    hks_taban_istek('BaseRequestMessageOf_TopluKunyeIstek', implode('', $p), $cfg));
  $duz  = hks_sadelestir($xml);
  $hata = hks_islem_kontrol($duz);
  if ($hata) throw new Exception($hata);

  $rows = [];
  foreach (hks_bloklar($duz, 'TopluKunyeDTO') as $b) {
    $malSahibi = hks_deger($b, 'MalinSahibAdi');
    if ($malSahibi === null || $malSahibi === '') $malSahibi = hks_deger($b, 'MalınSahibAdi');
    if ($malSahibi === null || $malSahibi === '') $malSahibi = hks_deger($b, 'MalinSahibiAdi');
    $rows[] = [
      'kunyeNo'    => (string)hks_deger($b, 'MalinKunyeNo'),
      'urun'       => trim((string)hks_deger($b, 'MalinAdi')),
      'miktar'     => (float)hks_deger($b, 'MalinMiktar'),
      'birim'      => trim((string)hks_deger($b, 'MiktarBirimAd')) ?: 'Kg',
      'nereden'    => trim((string)hks_deger($b, 'Nereden')),
      'nereye'     => trim((string)hks_deger($b, 'Nereye')),
      'bildirimci' => trim((string)hks_deger($b, 'Bildirimci')),
      'malSahibi'  => trim((string)$malSahibi),
      'plaka'      => trim((string)hks_deger($b, 'AracPlakaNo')),
      'belgeNo'    => trim((string)hks_deger($b, 'BelgeNo')),
      'tarih'      => substr((string)hks_deger($b, 'KayitTarihi'), 0, 10),
    ];
  }
  return $rows;
}

// ── Bildirim Sorgulama (yaptığım bildirimler) ─────────────────────────────
// BildirimServisBildirimcininYaptigiBildirimListesi — bildirimcinin YAPTIĞI
// bildirimleri tarih aralığıyla listeler (BildirimSorguIstek; 1 ay sınırına
// tabi → pencere pencere). NOT: HKS web servislerinde bildirim İPTAL metodu
// YOKTUR; iptal yalnız hks.hal.gov.tr sitesinden yapılabilir.
function hks_yaptigim_penceresi($cfg, DateTime $baslangic, DateTime $bitis) {
  $p = ['<Istek xmlns:a="' . HKS_NS_SC . '">'];
  // Alfabetik alan sırası (DataContract):
  $p[] = '<a:BaslangicTarihi>' . $baslangic->format('Y-m-d\TH:i:s') . '</a:BaslangicTarihi>';
  $p[] = '<a:BitisTarihi>' . $bitis->format('Y-m-d\TH:i:s') . '</a:BitisTarihi>';
  $p[] = '<a:KalanMiktariSifirdanBuyukOlanlar>false</a:KalanMiktariSifirdanBuyukOlanlar>';
  $p[] = '<a:KunyeNo>0</a:KunyeNo>';
  $p[] = '<a:KunyeTuru>1</a:KunyeTuru>';
  $p[] = '</Istek>';

  $xml = hks_soap_cagir('Bildirim', 'BildirimServisBildirimcininYaptigiBildirimListesi',
    hks_taban_istek('BaseRequestMessageOf_BildirimSorguIstek', implode('', $p), $cfg));
  $duz  = hks_sadelestir($xml);
  $hata = hks_islem_kontrol($duz);
  if ($hata) throw new Exception($hata);

  $rows = [];
  foreach (hks_bloklar($duz, 'BildirimSorguDTO') as $b) {
    $tarih = (string)hks_deger($b, 'BildirimTarihi');
    if ($tarih === '') $tarih = (string)hks_deger($b, 'KayitTarihi');
    $rows[] = [
      'kunyeNo' => (string)hks_deger($b, 'KunyeNo'),
      'tarih'   => substr($tarih, 0, 10),
      'urun'    => trim((string)hks_deger($b, 'MalinAdi')),
      'cins'    => trim((string)hks_deger($b, 'MalinCinsi')),
      'miktar'  => (float)hks_deger($b, 'MalinMiktari'),
      'kalan'   => (float)hks_deger($b, 'KalanMiktar'),
      'birim'   => trim((string)hks_deger($b, 'MiktarBirimiAd')) ?: 'Kg',
      'plaka'   => trim((string)hks_deger($b, 'AracPlakaNo')),
      'belgeNo' => trim((string)hks_deger($b, 'BelgeNo')),
    ];
  }
  return $rows;
}

function hks_yaptigim_bildirimler($cfg, $aySayisi = 1, &$ham = null) {
  $ay = max(1, min(36, (int)$aySayisi));
  $birlesik = [];
  $bitis = new DateTime();
  for ($i = 0; $i < $ay; $i++) {
    $baslangic = hks_bir_ay_once($bitis);
    foreach (hks_yaptigim_penceresi($cfg, $baslangic, $bitis) as $r) {
      $k = $r['kunyeNo'] !== '' ? $r['kunyeNo'] : ('x' . $i . '_' . count($birlesik));
      $birlesik[$k] = $r;
    }
    $bitis = $baslangic;
  }
  $ham = hks_son_ham(1200);
  $sonuc = array_values($birlesik);
  usort($sonuc, function ($a, $b) { return strcmp($b['tarih'], $a['tarih']); }); // yeni → eski
  return $sonuc;
}

// ── Bildirim Etiket (2'li künye etiketleri) ───────────────────────────────
// BildirimServisBildirimEtiket — daha önce girilmiş 2'li künyeleri etiket
// formunda toplu basım için sorgular. Kural (docx): MalinSahibiTcKimlikVergiNo
// ve BildirimTarihi zorunlu; AracPlakaNo/BelgeNo'dan en az biri dolu olmalı.
function hks_etiketler($cfg, $tarih, $plaka = '', $belgeNo = '', &$ham = null) {
  $tarih   = trim((string)$tarih);
  $plaka   = trim((string)$plaka);
  $belgeNo = trim((string)$belgeNo);
  if ($tarih === '') throw new Exception('Bildirim tarihi zorunludur.');
  if ($plaka === '' && $belgeNo === '') throw new Exception('Araç plakası veya belge no girilmelidir.');

  $td = DateTime::createFromFormat('Y-m-d', substr($tarih, 0, 10)) ?: null;
  $tarihXml = $td ? $td->format('Y-m-d\T00:00:00') : $tarih;

  $p = ['<Istek xmlns:a="' . HKS_NS_SC . '">'];
  // Alfabetik: AracPlakaNo, BelgeNo, BildirimTarihi, MalinSahibiTcKimlikVergiNo.
  // String alanlar boş da olsa gönderilir (TopluKunye'deki null-ref dersinden).
  $p[] = '<a:AracPlakaNo>' . hks_esc(strtoupper($plaka)) . '</a:AracPlakaNo>';
  $p[] = '<a:BelgeNo>' . hks_esc($belgeNo) . '</a:BelgeNo>';
  $p[] = '<a:BildirimTarihi>' . $tarihXml . '</a:BildirimTarihi>';
  $p[] = '<a:MalinSahibiTcKimlikVergiNo>' . hks_esc($cfg['vergiNo']) . '</a:MalinSahibiTcKimlikVergiNo>';
  $p[] = '</Istek>';

  $xml = hks_soap_cagir('Bildirim', 'BildirimServisBildirimEtiket',
    hks_taban_istek('BaseRequestMessageOf_BildirimEtiketIstek', implode('', $p), $cfg));
  $duz  = hks_sadelestir($xml);
  $ham  = preg_replace('/\s+/', ' ', substr($duz, 0, 1600));
  $hata = hks_islem_kontrol($duz);
  if ($hata) throw new Exception('BildirimEtiket: ' . $hata . ' || HAM: ' . $ham);

  // DTO etiketi belirsiz olabilir → önce BildirimEtiketDTO, yoksa EtiketDTO dene.
  $bloklar = hks_bloklar($duz, 'BildirimEtiketDTO');
  if (!count($bloklar)) $bloklar = hks_bloklar($duz, 'EtiketDTO');

  $rows = [];
  foreach ($bloklar as $b) {
    $kunye = (string)hks_deger($b, 'MalinKunyeNo');
    if ($kunye === '') $kunye = (string)hks_deger($b, 'KunyeNo');
    $miktar = hks_deger($b, 'MalinMiktar');
    if ($miktar === null) $miktar = hks_deger($b, 'MalinMiktari');
    $birim = trim((string)hks_deger($b, 'MiktarBirimAd'));
    if ($birim === '') $birim = trim((string)hks_deger($b, 'MiktarBirimiAd'));
    $sahibi = hks_deger($b, 'MalinSahibAdi');
    if ($sahibi === null || $sahibi === '') $sahibi = hks_deger($b, 'MalinSahibiAdi');
    $rows[] = [
      'kunyeNo'   => $kunye,
      'urun'      => trim((string)hks_deger($b, 'MalinAdi')),
      'miktar'    => (float)$miktar,
      'birim'     => $birim !== '' ? $birim : 'Kg',
      'sahibi'    => trim((string)$sahibi),
      'bildirimci'=> trim((string)hks_deger($b, 'Bildirimci')),
      'plaka'     => trim((string)hks_deger($b, 'AracPlakaNo')),
      'belgeNo'   => trim((string)hks_deger($b, 'BelgeNo')),
      'tarih'     => substr((string)hks_deger($b, 'KayitTarihi'), 0, 10),
    ];
  }
  return $rows;
}

// ── Yurt İçi Adres / Kişi Referansları (Sevk Etme + yurt içi satış için) ────
// Bu servislerin tamamı SALT-OKUNUR'dur: bildirim OLUŞTURMAZ, rüsum doğurmaz.
// Yalnızca il/ilçe/belde/işyeri listeler ve TC/VKN doğrular. Bu yüzden canlı
// sistemde güvenle test edilebilir. Alan/etiket adları docx'ten türetildiği için
// KESİN değildir; her fonksiyon hata VEYA boş liste durumunda HAM yanıtı da
// döndürür ($ham referansı) — böylece doğru etiketleri görüp ayarlayabiliriz.

// Genel Ad/Id listesi (opsiyonel parametreli Istek). $istekIc boşsa <Istek/> kullanılır.
// Genel servisi istek parametreleri HKS_NS_GENEL_SC namespace'inde (a: prefix) gider.
function hks_genel_liste($servis, $metod, $zarfAdi, $istekIc, $dtoEtiket, $alanlar, $cfg, &$ham = null) {
  $istek = ($istekIc === '' || $istekIc === null)
    ? '<Istek/>'
    : ('<Istek xmlns:a="' . HKS_NS_GENEL_SC . '">' . $istekIc . '</Istek>');
  $xml = hks_soap_cagir($servis, $metod, hks_taban_istek($zarfAdi, $istek, $cfg));
  $duz = hks_sadelestir($xml);
  $ham = preg_replace('/\s+/', ' ', substr($duz, 0, 1400));
  $hata = hks_islem_kontrol($duz);
  if ($hata) throw new Exception($metod . ': ' . $hata . ' || HAM: ' . $ham);
  $sonuc = [];
  foreach (hks_bloklar($duz, $dtoEtiket) as $b) {
    $satir = [];
    foreach ($alanlar as $anahtar => $etiket) {
      $v = trim((string)hks_deger($b, $etiket));
      $satir[$anahtar] = ($anahtar === 'id') ? (int)$v : $v;
    }
    if (($satir['ad'] ?? '') !== '' || ($satir['id'] ?? 0) > 0) $sonuc[] = $satir;
  }
  return $sonuc;
}

// İller (parametresiz) → IlDTO { Id, IlAdi }
function hks_iller($cfg, &$ham = null) {
  return hks_genel_liste('Genel', 'GenelServisIller', 'BaseRequestMessageOf_IllerIstek',
    '', 'IlDTO', ['id' => 'Id', 'ad' => 'IlAdi'], $cfg, $ham);
}

// İlçeler (IlId) → IlceDTO { Id, IlceAdi }
function hks_ilceler($cfg, $ilId, &$ham = null) {
  $ic = '<a:IlId>' . (int)$ilId . '</a:IlId>';
  return hks_genel_liste('Genel', 'GenelServisIlceler', 'BaseRequestMessageOf_IlcelerIstek',
    $ic, 'IlceDTO', ['id' => 'Id', 'ad' => 'IlceAdi'], $cfg, $ham);
}

// Beldeler (IlceId) → BeldeDTO { Id, BeldeAdi }
function hks_beldeler($cfg, $ilceId, &$ham = null) {
  $ic = '<a:IlceId>' . (int)$ilceId . '</a:IlceId>';
  return hks_genel_liste('Genel', 'GenelServisBeldeler', 'BaseRequestMessageOf_BeldelerIstek',
    $ic, 'BeldeDTO', ['id' => 'Id', 'ad' => 'BeldeAdi'], $cfg, $ham);
}

// İşyerleri: karşı tarafın TC/VKN'sine göre depo / şube / hal içi işyeri listesi.
// $tur: 'depo' | 'sube' | 'halici'. GidecekIsyeriId için kaynak liste budur.
function hks_isyerleri($cfg, $tur, $tcVkn, &$ham = null) {
  $map = [
    'depo'   => ['GenelServisDepolar',      'BaseRequestMessageOf_DepolarIstek',      'DepoDTO'],
    'sube'   => ['GenelServisSubeler',      'BaseRequestMessageOf_SubelerIstek',      'SubeDTO'],
    'halici' => ['GenelServisHalIciIsyeri', 'BaseRequestMessageOf_HalIciIsyeriIstek', 'HalIciIsyeriDTO'],
  ];
  if (!isset($map[$tur])) throw new Exception('Geçersiz işyeri türü: ' . $tur);
  [$metod, $zarf, $dto] = $map[$tur];
  // Genel servisi: parametre Genel.ServiceContract namespace'inde gönderilmeli.
  $ic = '<a:TcKimlikVergiNo>' . hks_esc(trim((string)$tcVkn)) . '</a:TcKimlikVergiNo>';
  $istek = '<Istek xmlns:a="' . HKS_NS_GENEL_SC . '">' . $ic . '</Istek>';
  $xml = hks_soap_cagir('Genel', $metod, hks_taban_istek($zarf, $istek, $cfg));
  $duz = hks_sadelestir($xml);
  $ham = preg_replace('/\s+/', ' ', substr($duz, 0, 1600));
  $hata = hks_islem_kontrol($duz);
  if ($hata) throw new Exception($metod . ': ' . $hata . ' || HAM: ' . $ham);
  $sonuc = [];
  foreach (hks_bloklar($duz, $dto) as $b) {
    $ad = '';
    foreach (['DepoAdi','SubeAdi','IsyeriAdi','HalIciIsyeriAdi','Adi','Ad','ad'] as $et) {
      $v = trim((string)hks_deger($b, $et));
      if ($v !== '') { $ad = $v; break; }
    }
    $hi = (string)hks_deger($b, 'Halicimi');
    if ($hi === '') $hi = (string)hks_deger($b, 'HalIciMi');
    $sonuc[] = [
      'id'      => (int)hks_deger($b, 'Id'),
      'ad'      => $ad,
      'adres'   => trim((string)hks_deger($b, 'Adres')),
      'ilId'    => (int)hks_deger($b, 'IlId'),
      'ilceId'  => (int)hks_deger($b, 'IlceId'),
      'beldeId' => (int)hks_deger($b, 'BeldeId'),
      'halIci'  => (stripos($hi, 'true') !== false),
    ];
  }
  return $sonuc;
}

// Kayıtlı Kişi Sorgu: karşı tarafın (firma/şahıs) HKS'te kayıtlı olup olmadığını
// ve sahip olduğu sıfatları döndürür. Ad/soyad DÖNMEZ (elle girilir).
// İstek: TcKimlikVergiNolar = List<string> (Microsoft Arrays serileştirmesi).
function hks_kayitli_kisi_sorgu($cfg, $tcVknList, &$ham = null) {
  $arr = '';
  foreach ((array)$tcVknList as $tc) {
    $tc = trim((string)$tc);
    if ($tc === '') continue;
    $arr .= '<c:string>' . hks_esc($tc) . '</c:string>';
  }
  $ic = '<a:TcKimlikVergiNolar xmlns:c="http://schemas.microsoft.com/2003/10/Serialization/Arrays">'
      . $arr . '</a:TcKimlikVergiNolar>';
  $istek = '<Istek xmlns:a="' . HKS_NS_SC . '">' . $ic . '</Istek>';
  $xml = hks_soap_cagir('Bildirim', 'BildirimServisKayitliKisiSorgu',
    hks_taban_istek('BaseRequestMessageOf_KayitliKisiSorguIstek', $istek, $cfg));
  $duz = hks_sadelestir($xml);
  $ham = preg_replace('/\s+/', ' ', substr($duz, 0, 1400));
  $hata = hks_islem_kontrol($duz);
  if ($hata) throw new Exception('KayitliKisiSorgu: ' . $hata . ' || HAM: ' . $ham);
  $sonuc = [];
  foreach (hks_bloklar($duz, 'KayitliKisiSorguDTO') as $b) {
    $sifatlar = [];
    foreach (hks_bloklar($b, 'int') as $iv) {
      $n = (int)trim((string)$iv);
      if ($n > 0) $sifatlar[] = $n;
    }
    $km = (string)hks_deger($b, 'KayitliKisiMi');
    $sonuc[] = [
      'tcVkn'     => trim((string)hks_deger($b, 'TcKimlikVergiNo')),
      'kayitliMi' => (stripos($km, 'true') !== false),
      'sifatlar'  => $sifatlar,
    ];
  }
  return $sonuc;
}
