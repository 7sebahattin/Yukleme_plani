<?php
// =============================================================================
// HKS PANEL — TASLAK ORTAK KÜTÜPHANESİ (include-only)
// =============================================================================
// Bu dosya, api.php içinde bulunan firma/doğrulama yardımcılarının TAŞINMIŞ
// hâlidir (davranış birebir aynıdır, tek satır mantık değişikliği yoktur).
//
// NEDEN AYRILDI: HKS taslağını artık iki yer oluşturuyor —
//   1) halkayit/api.php   → SPA'daki "Taslak Kaydet" / "Taslak Oluştur"
//   2) api_beyan_bildirim.php → Beyan ekranındaki "Bildirim Yap"
// Doğrulama kuralları (TC algoritması, KPS kimlik bütünlüğü, Üreticiden Sevk
// Alım kısıtları) CANLI SİSTEMDE ÖĞRENİLMİŞ kurallardır ve geri alınamaz,
// rüsum doğuran bir çağrının önündeki tek settir. İKİNCİ BİR KOPYA ÇIKARMAYIN —
// iki yol zamanla ayrışır ve ayrışan taraf sessizce hatalı bildirim gönderir.
//
// Bu dosya doğrudan çağrılamaz (.htaccess ile kapalıdır) ve HTTP'ye hiçbir şey
// yazmaz; yalnızca saf fonksiyon tanımlar.
// =============================================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/hks_soap.php';   // hks_tc_normalize / hks_tc_algoritma_gecerli / hks_dogum_tarihi_xml

// --- Firma yardımcıları ---
function hks_firma_bul($id) {
  $st = hks_db()->prepare('SELECT * FROM ' . hks_tablo('firmalar') . ' WHERE id = ?');
  $st->execute([$id]);
  $f = $st->fetch();
  if (!$f) return null;
  return [
    'id' => $f['id'], 'ad' => $f['ad'], 'renk' => $f['renk'],
    'userName' => $f['user_name'],
    'password' => hks_coz($f['password_enc']),
    'servicePassword' => hks_coz($f['service_password_enc']),
    'vergiNo' => $f['vergi_no'],
  ];
}

// Bildirim türü "Üreticiden Sevk Alım" mı? Tür ID'leri HKS'ten gelir ve sabit
// olduklarını varsayamayız; karar tür ADINDAN verilir. Arayüz ayrıca açık bir
// `uretSevk` bayrağı gönderir — eski taslaklarda yalnız `turAd` bulunduğu için
// ikisi birden desteklenir.
function hks_uret_sevk_mi($o) {
  if (!empty($o['uretSevk'])) return true;
  $ad = trim((string)($o['turAd'] ?? ''));
  return $ad !== '' && mb_stripos($ad, 'üretici', 0, 'UTF-8') !== false;
}

// P3: `gonderilenler` kaydı için yapısal bildirim türü kodu — metne (turAd/
// ulkeAd serbest metnine gömülü önek) değil, gönderim akışının ZATEN kullandığı
// güvenilir bayraklara (uret, referanssiz, yurtIci, fiyatGonder) dayanır.
// Legacy satırlarda bu bilgi YOKTUR ve tahmine dayalı backfill yapılmaz.
function hks_bildirim_turu_kodu($ortak) {
  if (hks_uret_sevk_mi($ortak)) return 'URETICIDEN_SEVK_ALIM';
  if (!empty($ortak['referanssiz'])) return 'SATIN_ALIM';
  if (!empty($ortak['yurtIci'])) return empty($ortak['fiyatGonder']) ? 'SEVK_ETME' : 'SATIS';
  return 'SATIS';   // yurt dışı (mevcut ihracat akışı)
}

// Cep telefonu: yalnız rakamlar. Kullanıcı "0532 123 45 67" / "+90 532..." gibi
// yazabilir; CANLI servise ham metin gitmesin.
function hks_cep_rakam($s) {
  return preg_replace('/\D+/', '', (string)$s);
}

// Sıfat id'sinin adı — önbellekteki referans listesinden (listeler_cache).
// Liste henüz indirilmemişse null döner (o zaman ad denetimi atlanır).
function hks_sifat_adi($sifatId) {
  $cache = hks_kv_oku('listeler_cache', null);
  if (!$cache || empty($cache['sifatlar'])) return null;
  foreach ($cache['sifatlar'] as $s) {
    if ((string)($s['id'] ?? '') === (string)$sifatId) return (string)($s['ad'] ?? '');
  }
  return null;
}

// Türkçe büyük/küçük harf normalizasyonu — PHP'nin mb_strtolower'ı Türkçe İ/I
// ayrımını (İ→i, I→ı) yapmaz. app.html'deki trNorm() ile AYNI dönüşüm.
function hks_tr_normalize($s) {
  $s = str_replace(['İ', 'I'], ['i', 'ı'], (string)$s);
  return mb_strtolower($s, 'UTF-8');
}

// HKS sıfat kataloğunda (listeler_cache) TAM olarak "Üretici" adına sahip
// sıfatın ID'sini bulur — ID HARD-CODE EDİLMEZ, kataloğun kendisinden aranır
// (sıfat ID'leri ortamdan ortama sabit olduğu varsayılamaz). Liste henüz
// indirilmemişse veya kataloğun tam "Üretici" adında bir sıfatı yoksa null
// döner (P1: "Üretici Birliği"/"Üretici Örgütü" gibi FARKLI sıfatlar kabul
// EDİLMEZ — önceki sürüm alt-dize eşleşmesi kullanıyordu).
function hks_uretici_sifat_id() {
  $cache = hks_kv_oku('listeler_cache', null);
  if (!$cache || empty($cache['sifatlar'])) return null;
  foreach ($cache['sifatlar'] as $s) {
    if (hks_tr_normalize((string)($s['ad'] ?? '')) === 'üretici') return (int)($s['id'] ?? 0);
  }
  return null;
}

// Künye detay zenginleştirmesinde id → ad çözümü (bildirim türü / belge tipi).
// hks_sifat_adi ile AYNI desen: liste yoksa/eşleşmezse null döner, arayüz id'yi
// ham gösterir — asla hata fırlatmaz (zenginleştirme opsiyonel).
function hks_liste_ad($cache, $listeAnahtari, $id) {
  if ($id === null || $id === '' || (int)$id <= 0) return null;
  if (!$cache || empty($cache[$listeAnahtari])) return null;
  foreach ($cache[$listeAnahtari] as $s) {
    if ((string)($s['id'] ?? '') === (string)$id) return (string)($s['ad'] ?? '');
  }
  return null;
}

// Doğrulama (taslak/bildirim ortak) — yurt dışı (mevcut export) ve yurt içi (Sevk
// Etme / yurt içi Satış / Satın Alım / Üreticiden Sevk Alım) için ayrı kurallar.
// Yurt dışı yolu birebir korunur.
//
// NOT: Bu denetimler tarayıcıdaki denetimlerin KOPYASI değil, SON SÖZÜdür.
// `app.html` statik dosyadır ve tarayıcı/SW önbelleğinden eski sürümü sunulabilir;
// CANLI ve geri alınamaz bir sisteme giden alanların doğruluğu sunucuda da
// güvence altına alınmalıdır.
//
// $planMi = PLAN TASLAĞI modu (künyeler gönderim anında stoktan çözülecek).
// Bu modda YALNIZCA "künye satırı var mı" kontrolü atlanır; onun yerine hedef
// kilo (planKg) zorunlu olur. Diğer TÜM kurallar (sıfat/tür, plaka-belge,
// fiyat, karşı taraf, yurt içi/dışı, hedef) DEĞİŞMEDEN çalışır. Gönderim
// anında künyeler çözüldükten sonra bu fonksiyon $planMi=false ile TEKRAR
// çağrılır — yani canlıya giden bildirim her zaman tam denetimden geçer.
function hks_bildirim_dogrula($g, $planMi = false) {
  $o = $g['ortak'] ?? [];
  if ($planMi) {
    // Plan taslağı referanssız türlerde (Satın Alım / Üreticiden Sevk Alım)
    // anlamsızdır: o türlerde künye zaten kullanılmaz, malın tanımı girilir.
    if (!empty($o['referanssiz'])) {
      return 'Plan taslağı yalnızca künyeli (referanslı) bildirimlerde kullanılabilir.';
    }
    if ((float)($o['planKg'] ?? 0) <= 0) return 'Plan taslağı için toplam kilo gerekli.';
    if (empty($o['planSorgu']['urunId'])) return 'Plan taslağı için ürün seçilmelidir.';
  } else {
    if (empty($g['satirlar']) || !is_array($g['satirlar'])) return 'En az bir künye satırı gerekli.';
    if (count($g['satirlar']) > 100) return 'Tek seferde en fazla 100 bildirim.';
  }
  // Her iki modda zorunlu ortak alanlar
  foreach (['sifatId', 'bildirimTuruId', 'isletmeTuruId'] as $alan) {
    if (empty($o[$alan])) return 'Eksik alan: ' . $alan;
  }
  if (empty($o['plaka']) && !(!empty($o['belgeNo']) && !empty($o['belgeTipiId']))) {
    return 'Araç plakası veya belge no + belge tipi girilmelidir.';
  }
  // REFERANSSIZ (Satın Alım): malın tam tanımı zorunlu — docx "Referanssız
  // Bildirimlerde" kuralları.
  if (!empty($o['referanssiz'])) {
    foreach ([
      'malinNiteligi' => 'Malın niteliği',
      'malinKodNo'    => 'Ürün (malın adı)',
      'malinCinsiId'  => 'Ürün cinsi',
      'uretimSekli'   => 'Üretim şekli',
      'miktarBirimId' => 'Miktar birimi',
      'uretimIlId'    => 'Üretim ili',
      'uretimIlceId'  => 'Üretim ilçesi',
      'uretimBeldeId' => 'Üretim beldesi',
    ] as $alan => $etiket) {
      if (empty($o[$alan])) return $etiket . ' seçilmeli (referanssız bildirim).';
    }
    if (!empty($o['ithalat']) && empty($o['gelenUlkeId'])) {
      return 'İthalat işleminde gelen ülke seçilmeli.';
    }
  }
  if (!empty($o['yurtIci'])) {
    $uret = hks_uret_sevk_mi($o);
    // KİMLİK BÜTÜNLÜĞÜ: karşı taraf için Ad/Ünvan veya Cep gönderiyorsak KPS'e EKSİK
    // kimlik gitmemelidir. hks_bildirim_xml doğum tarihi boş/geçersizse onu SESSİZCE
    // atlar; o zaman KPS yalnız TC ile sorgulanır ve "Tc kimlik numarası Mernis
    // sisteminde bulunamadı" döner — üstelik sebebi ekranda görünmez. Bu yüzden
    // kısmi kimlik, geri alınamaz gönderimden ÖNCE burada durdurulur.
    if ((!empty($o['ikinciAd']) || !empty($o['ikinciCep']))
        && hks_dogum_tarihi_xml($o['ikinciDogumTarihi'] ?? '') === '') {
      return 'Karşı taraf için Ad/Ünvan veya Cep gönderiliyor ama Doğum Tarihi yok/geçersiz. ' .
             'KPS, TC ile doğum tarihini BİRLİKTE doğrular; eksik kimlikle gönderilen ' .
             'bildirim "Mernis sisteminde bulunamadı" hatası verir.';
    }
    // Yurt içi: karşı taraf + hedef zorunlu.
    if (empty($o['ikinciTc']))      return 'Karşı taraf TC/Vergi No gerekli.';
    // Hane denetimi: TC 11, VKN 10 hanedir. Bozuk bir değer eskiden sessizce
    // HKS'e gidip geri alınamaz çağrıda "Mernis sisteminde bulunamadı" ile
    // reddediliyordu — artık taslak kaydında/gönderim öncesi burada durur.
    $__tcTemiz = hks_tc_normalize($o['ikinciTc']);
    $__tcHane  = strlen($__tcTemiz);
    if ($__tcHane !== 10 && $__tcHane !== 11) {
      return 'Karşı taraf TC/Vergi No geçersiz — TC 11, Vergi No 10 rakam olmalıdır ' .
             '(girilen: ' . $__tcHane . ' rakam).';
    }
    // TC ALGORİTMA DENETİMİ (yalnız 11 hanede): rakamı yanlış/yer değiştirmiş bir TC
    // biçimsel olarak doğru görünür ama MERNİS'te BULUNMAZ. Geri alınamaz çağrıdan
    // önce burada yakalanır. VKN (10 hane) bu algoritmaya tabi değildir.
    if ($__tcHane === 11 && !hks_tc_algoritma_gecerli($__tcTemiz)) {
      return 'Karşı taraf TC Kimlik No (' . $__tcTemiz . ') geçersiz — kimlik numarası ' .
             'doğrulama algoritmasından geçmiyor. Rakamları kişinin kimliğiyle ' .
             'karşılaştırın (en sık neden: iki rakamın yer değiştirmesi).';
    }
    if (empty($o['ikinciSifatId'])) return 'Karşı taraf sıfatı gerekli.';

    // ── ÜRETİCİDEN SEVK ALIM (docx kuralları) ──────────────────────────────
    // • "Sadece kayıtsız üreticiden yapılan sevkiyat işlemlerinde kullanılacak
    //    bildirim türüdür."   → karşı tarafın KAYITLI olması yasak (gönderim
    //    anında `taslak_gonder` içinde GTB'ye tekrar sorulur).
    // • "Bildirim türü 'Üreticiden Sevk Alım'sa referanslı bildirim yapılamaz."
    // • "Bildirim türü 'Üreticiden Sevk Alım'sa, İkinci kişi sıfat bilgisi
    //    'Üretici' olmalıdır."
    if ($uret) {
      // CANLI SİSTEMDE GÖZLENEN KISIT (kılavuz 0.1.14'te YAZMIYOR): HKS,
      // bildirimci sıfatı "İhracat" iken bu türü reddediyor —
      //   GTBWSRV0000002 · "İhracat Üreticiden Sevk Alım bildirimi yapamaz"
      // Yalnız bu KANITLANMIŞ kombinasyon engellenir; başka sıfatlar hakkında
      // varsayımda bulunulmaz (aksi hâlde geçerli işlemler bloklanabilirdi).
      // Bu tür eski taslaklarda da saklı olabileceğinden denetim sunucudadır.
      // DİKKAT: mb_stripos() Türkçe "İ" ile "i"yi EŞLEŞTİREMEZ (İ'nin Unicode
      // küçük hâli noktalı bir bileşimdir) — "İhracat" sessizce kaçardı.
      // Bu yüzden karşılaştırma hks_tr_normalize() üzerinden yapılır.
      $bildirimciSifatAd = hks_sifat_adi($o['sifatId'] ?? 0);
      if ($bildirimciSifatAd !== null &&
          strpos(hks_tr_normalize($bildirimciSifatAd), 'ihracat') !== false) {
        return 'HKS, "İhracat" sıfatıyla "Üreticiden Sevk Alım" bildirimine izin vermiyor ' .
               '("İhracat Üreticiden Sevk Alım bildirimi yapamaz"). Kayıtsız üreticiden alım ' .
               'için bildirim türünü "Satın Alım" seçin.';
      }
      if (empty($o['referanssiz'])) {
        return '"Üreticiden Sevk Alım" referanslı yapılamaz (kılavuz kuralı) — künye seçilemez.';
      }
      foreach ((array)($g['satirlar'] ?? []) as $s) {
        // Yalnız sıfırlardan (ya da hiç rakamdan) oluşmayan künye no = referanslı.
        if (ltrim(preg_replace('/\D/', '', (string)($s['kunyeNo'] ?? '0')), '0') !== '') {
          return '"Üreticiden Sevk Alım" referanslı yapılamaz — referans künye no "0" olmalıdır.';
        }
      }
      if (!empty($o['kayitZorunlu'])) {
        return '"Üreticiden Sevk Alım" yalnızca GTB\'de KAYITSIZ üretici içindir.';
      }
      // P1: substring ("üretici" alt-dize) kontrolü KALDIRILDI — "Üretici Birliği"/
      // "Üretici Örgütü" FARKLI sıfatlardır. Katalogdan TAM "Üretici" adına sahip
      // sıfatın ID'si bulunur; gönderilen ikinciSifatId bununla KESİN eşleşmelidir.
      // Katalog henüz indirilmemişse (ID bulunamazsa) de REDDEDİLİR — eski davranış
      // bu durumda denetimi sessizce ATLIYORDU, bu artık fail-closed'dır.
      $ureticiId = hks_uretici_sifat_id();
      if ($ureticiId === null) {
        return '"Üreticiden Sevk Alım" için HKS sıfat kataloğunda tam "Üretici" adlı bir sıfat ' .
               'bulunamadı — listeleri güncelleyin.';
      }
      if ((int)($o['ikinciSifatId'] ?? 0) !== $ureticiId) {
        $sifatAd = hks_sifat_adi($o['ikinciSifatId']);
        return '"Üreticiden Sevk Alım"da karşı taraf sıfatı tam olarak "Üretici" olmalıdır ' .
               '(gönderilen: ' . ($sifatAd ?? ('id ' . $o['ikinciSifatId'])) . ').';
      }
    }

    // ── KAYITSIZ İKİNCİ KİŞİ (kılavuz 0.1.14) ───────────────────────────────
    // "İkinci kişi 'TcKimlikVergiNo' alanı GTB sisteminde kayıtlı değilse
    //  'Eposta' bilgisi hariç diğer bilgilerinde gönderilmesi gerekir."
    // → AdSoyad + CepTel zorunlu. Üreticiden Sevk Alım'da ikinci kişi HER ZAMAN
    // kayıtsızdır ve artık `hedefAdres` de HER ZAMAN true gönderilir (P1
    // düzeltmesi — bkz. app.html), bu yüzden `$uret ||` koşulu teknik olarak
    // gereksiz hâle geldi ama açıklık için (ve eski/tampered taslaklara karşı
    // savunma amacıyla) korunur.
    // `ikinciKayitsiz`: Satın Alım'da karşı taraf kayıtlı DA olabilir kayıtsız DA
    // (GTB 12.03.2025). Kayıtsız olduğunda hedef işyeridir (hedefAdres=false),
    // dolayısıyla kimlik zorunluluğu bu ayrı bayrakla yakalanır.
    if ($uret || !empty($o['hedefAdres']) || !empty($o['ikinciKayitsiz'])) {
      $kim = $uret ? 'üretici' : 'alıcı';
      if (empty($o['ikinciAd'])) return 'Kayıtsız ' . $kim . ' için Ad/Ünvan gerekli.';
      $cep = hks_cep_rakam($o['ikinciCep'] ?? '');
      if ($cep === '') return 'Kayıtsız ' . $kim . ' için Cep Telefonu gerekli.';
      if (strlen($cep) < 10 || strlen($cep) > 13) {
        return 'Cep telefonu geçersiz — en az 10 rakam olmalıdır (örn. 05001112233).';
      }
      // GTB 12.03.2025 duyurusu: "Sistemde kayıtlı olmayan kişi bildirimleri için
      // T.C. kimlik numarası ve doğum tarihi bilgilerinin girilmesi gerekmektedir."
      // (KPS sorgulaması artık TC + doğum tarihi ile yapılıyor.)
      if (empty($o['ikinciDogumTarihi'])) {
        return 'Kayıtsız ' . $kim . ' için Doğum Tarihi gerekli (GTB 12.03.2025 duyurusu).';
      }
      if (hks_dogum_tarihi_xml($o['ikinciDogumTarihi']) === '') {
        return 'Doğum tarihi geçersiz — GG.AA.YYYY biçiminde geçerli bir tarih girin.';
      }
    }

    if (!empty($o['hedefAdres'])) {
      // Kayıtsız ikinci kişi (yurt içi Satış'ta kayıtsız alıcı VEYA Üreticiden
      // Sevk Alım'da kayıtsız üretici — P1 düzeltmesi): il/ilçe/belde.
      if (empty($o['ilId']) || empty($o['ilceId']) || empty($o['beldeId'])) return 'İl / İlçe / Belde seçilmeli.';
    } else {
      // Kayıtlı ikinci kişi (yurt içi Satış / Sevk Etme) veya Satın Alım
      // (kendi işyerimiz): gidecek işyeri.
      if (empty($o['gidecekIsyeriId'])) return 'Gidecek işyeri (depo/şube/hal içi) seçilmeli.';
    }
    // Fiyat yalnızca Sevk Etme dışında (fiyatGonder=true) zorunlu.
    if (!empty($o['fiyatGonder']) && empty($o['fiyat'])) return 'Birim fiyat gerekli.';
  } else {
    // Yurt dışı (mevcut export): ülke + fiyat zorunlu.
    if (empty($o['ulkeId'])) return 'Eksik alan: ulkeId';
    if (empty($o['fiyat']))  return 'Eksik alan: fiyat';
  }
  return null;
}

// =============================================================================
// TASLAK OLUŞTURMA — TEK YAZMA YOLU
// =============================================================================
// api.php'deki `taslak_kaydet` bloğundan çıkarıldı. Girdi ve doğrulama sırası
// birebir korunmuştur; tek ekleme, aşağıda açıklanan id çakışma denemesidir.
//
// $g = ['firmaId' => ..., 'satirlar' => [...], 'ortak' => [...]]
// Dönüş: ['id' => 't173...', 'firmaAd' => '...', 'planMi' => bool]
//     veya ['hata' => '...', 'kod' => 400]
//
// DİKKAT: Bu fonksiyon SADECE taslak yazar. HKS'e HİÇBİR ŞEY GÖNDERMEZ —
// gönderim (BildirimKaydet) geri alınamaz ve rüsum doğurur; o karar yalnızca
// api.php'deki `taslak_gonder` yolunda, mükerrer gönderimi engelleyen atomik
// sahiplenme ile birlikte verilir.
function hks_taslak_olustur(array $g): array {
  $cfg = hks_firma_bul($g['firmaId'] ?? '');
  if (!$cfg) return ['hata' => 'Firma bulunamadı.', 'kod' => 400];

  // PLAN TASLAĞI: künye seçilmeden, yalnız toplam kilo + fiyat ile kaydedilir.
  // Künyeler gönderim anında canlı stoktan çözülür (hks_plan_kunye_coz).
  $planMi = (float)($g['ortak']['planKg'] ?? 0) > 0 && empty($g['satirlar']);
  $hata = hks_bildirim_dogrula($g, $planMi);
  if ($hata) return ['hata' => $hata, 'kod' => 400];

  $veri = json_encode([
    'satirlar' => $planMi ? [] : $g['satirlar'],
    'ortak'    => $g['ortak'],
  ], JSON_UNESCAPED_UNICODE);

  // Taslak id'si milisaniye damgasıdır. Tek yazıcı varken çakışma pratikte
  // imkânsızdı; ARTIK İKİ YAZICI VAR (SPA + Beyan ekranı), bu yüzden aynı
  // milisaniyeye denk gelen ikinci INSERT birincil anahtar hatasıyla 500
  // verirdi. Kısa bir yeniden deneme bunu sessizce çözer — id biçimi ve
  // sıralaması (zaman damgası) korunur.
  $db = hks_db();
  $st = $db->prepare('INSERT INTO ' . hks_tablo('taslaklar') . '
    (id, zaman, firma_id, firma_ad, veri) VALUES (?,?,?,?,?)');
  for ($deneme = 0; $deneme < 5; $deneme++) {
    $id = 't' . round(microtime(true) * 1000);
    try {
      $st->execute([$id, date('Y-m-d H:i:s'), $g['firmaId'], $cfg['ad'], $veri]);
      return ['id' => $id, 'firmaAd' => $cfg['ad'], 'planMi' => $planMi];
    } catch (PDOException $e) {
      if ($e->getCode() !== '23000') throw $e;   // yalnız çakışmada tekrar dene
      usleep(1500);
    }
  }
  return ['hata' => 'Taslak id çakışması çözülemedi, lütfen tekrar deneyin.', 'kod' => 500];
}
