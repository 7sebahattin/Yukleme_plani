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
  $kod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($kod !== 200) {
    throw new Exception('HTTP ' . $kod . ': ' . substr($cevap, 0, 300));
  }
  return $cevap;
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

// Tek pencere için referans künye sorgusu
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
    ];
  }
  return $sonuc;
}

// Aylık pencereleri sırayla sorgula, birleştir (mükerrer künyeleri ayıkla)
function hks_kunyeleri_getir($cfg, $secenek) {
  $ay = max(1, min(24, (int)($secenek['aySayisi'] ?? 1)));
  $birlesik = [];
  $bitis = new DateTime();
  for ($i = 0; $i < $ay; $i++) {
    $baslangic = hks_bir_ay_once($bitis);
    $liste = hks_kunye_penceresi($cfg, $secenek, $baslangic, $bitis);
    foreach ($liste as $k) $birlesik[$k['kunyeNo']] = $k;
    $bitis = $baslangic;
  }
  $sonuc = array_values($birlesik);
  usort($sonuc, function ($a, $b) { return strcmp($a['tarih'], $b['tarih']); });
  return $sonuc;
}

// Bildirim kayıt XML'i (100 künyeye kadar tek istekte)
function hks_bildirim_xml($satirlar, $ortak) {
  $kayitlar = [];
  foreach ($satirlar as $i => $s) {
    $uid = 'HKSPHP-' . time() . '-' . $i . '-' . bin2hex(random_bytes(3));
    $gidecek = [];
    if (!empty($ortak['plaka'])) $gidecek[] = '<b:AracPlakaNo>' . hks_esc($ortak['plaka']) . '</b:AracPlakaNo>';
    if (!empty($ortak['belgeNo'])) $gidecek[] = '<b:BelgeNo>' . hks_esc($ortak['belgeNo']) . '</b:BelgeNo>';
    if (!empty($ortak['belgeTipiId'])) $gidecek[] = '<b:BelgeTipi>' . (int)$ortak['belgeTipiId'] . '</b:BelgeTipi>';
    $gidecek[] = '<b:GidecekUlkeId>' . (int)$ortak['ulkeId'] . '</b:GidecekUlkeId>';
    $gidecek[] = '<b:GidecekYerIsletmeTuruId>' . (int)$ortak['isletmeTuruId'] . '</b:GidecekYerIsletmeTuruId>';

    $kayitlar[] = '<a:BildirimKayitIstek>' .
      '<a:BildirimMalBilgileri>' .
      '<b:MalinMiktari>' . (float)$s['miktar'] . '</b:MalinMiktari>' .
      '<b:MalinSatisFiyat>' . (float)$ortak['fiyat'] . '</b:MalinSatisFiyat>' .
      '</a:BildirimMalBilgileri>' .
      '<a:BildirimTuru>' . (int)$ortak['bildirimTuruId'] . '</a:BildirimTuru>' .
      '<a:BildirimciBilgileri><b:KisiSifat>' . (int)$ortak['sifatId'] . '</b:KisiSifat></a:BildirimciBilgileri>' .
      '<a:IkinciKisiBilgileri><b:YurtDisiMi>true</b:YurtDisiMi></a:IkinciKisiBilgileri>' .
      '<a:MalinGidecekYerBilgileri>' . implode('', $gidecek) . '</a:MalinGidecekYerBilgileri>' .
      '<a:ReferansBildirimKunyeNo>' . preg_replace('/\D/', '', (string)$s['kunyeNo']) . '</a:ReferansBildirimKunyeNo>' .
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
  return ['genelHata' => $genelHata, 'sonuclar' => $sonuclar];
}
