<?php
// =============================================================================
// HKS PANEL - API YÖNLENDİRİCİ
// Arayüzden gelen tüm istekler buraya POST edilir: api.php?action=XXX
// Her cevap JSON'dur. Node.js server.js'teki rotaların birebir karşılığıdır.
//
// ENTEGRASYON NOTU (diğer AI için): Ana paneliniz oturum kontrolü yapıyorsa,
// bu dosyanın başına kendi auth-guard'ınızı require edin. HKS_BASIT_GIRIS
// yerine mevcut login sisteminizi kullanmanız önerilir.
// =============================================================================

require_once __DIR__ . '/config.php';        // ../config/db.php -> helpers.php de yüklenir
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/hks_soap.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

// --- Ana panel oturum koruması (JSON-güvenli: redirect etmez, JSON döner) ---
// require_login() kullanmıyoruz çünkü depo seçili değilse HTML redirect yapardı;
// bu uç JSON döndürmeli. Bu yüzden current_user() + can() ile elle kontrol.
$__hks_user = function_exists('current_user') ? current_user() : null;
if ($__hks_user === null) {
  http_response_code(401);
  echo json_encode(['hata' => 'Oturum gerekli. Lütfen tekrar giriş yapın.'], JSON_UNESCAPED_UNICODE);
  exit;
}
if (!(can('records.write') || (function_exists('is_admin') && is_admin()))) {
  http_response_code(403);
  echo json_encode(['hata' => 'Bu işlem için yetkiniz yok (records.write).'], JSON_UNESCAPED_UNICODE);
  exit;
}

// Bağımsız (standalone) çalıştırma için geriye dönük opsiyonel Basic Auth desteği.
if (HKS_BASIT_GIRIS) {
  $u = $_SERVER['PHP_AUTH_USER'] ?? '';
  $p = $_SERVER['PHP_AUTH_PW'] ?? '';
  if ($u !== HKS_GIRIS_KULLANICI || $p !== HKS_GIRIS_SIFRE) {
    header('WWW-Authenticate: Basic realm="HKS Panel"');
    http_response_code(401);
    echo json_encode(['hata' => 'Giriş gerekli.']);
    exit;
  }
}

function hks_json_cikti($veri, $kod = 200) {
  http_response_code($kod);
  echo json_encode($veri, JSON_UNESCAPED_UNICODE);
  exit;
}
function hks_govde() {
  $ham = file_get_contents('php://input');
  $j = json_decode($ham, true);
  return is_array($j) ? $j : [];
}

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
    // Yurt içi: karşı taraf + hedef zorunlu.
    if (empty($o['ikinciTc']))      return 'Karşı taraf TC/Vergi No gerekli.';
    // Hane denetimi: TC 11, VKN 10 hanedir. Bozuk bir değer eskiden sessizce
    // HKS'e gidip geri alınamaz çağrıda "Mernis sisteminde bulunamadı" ile
    // reddediliyordu — artık taslak kaydında/gönderim öncesi burada durur.
    $__tcHane = strlen(hks_tc_normalize($o['ikinciTc']));
    if ($__tcHane !== 10 && $__tcHane !== 11) {
      return 'Karşı taraf TC/Vergi No geçersiz — TC 11, Vergi No 10 rakam olmalıdır ' .
             '(girilen: ' . $__tcHane . ' rakam).';
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
// PLAN TASLAĞI — künyeleri GÖNDERİM ANINDA canlı stoktan çöz
// =============================================================================
// Kullanıcı taslağı künye seçmeden, yalnız "toplam kilo + birim fiyat" ile
// kaydeder. Gönder'e basıldığında künyeler HKS'ten TAZE çekilir ve hedef kiloya
// ulaşana kadar sırayla dağıtılır — tarayıcıdaki "⚡ Otomatik Dağıt" (app.html
// btnDagit) ile BİREBİR aynı greedy mantık: liste sırasına göre her künyeden
// min(kalan, kalanHedef) alınır.
//
// Künyeler kaydetme anında DEĞİL gönderim anında çözülür; böylece aradaki
// sürede stok değişse bile bildirim her zaman O ANKİ gerçek kalanlarla gider.
//
// Dönüş: ['satirlar' => [...]] veya ['hata' => '...'] (taslak KORUNUR).
function hks_plan_kunye_coz($cfg, $ortak) {
  $hedef = round((float)($ortak['planKg'] ?? 0), 3);
  if ($hedef <= 0) return ['hata' => 'Plan taslağında toplam kilo yok.'];

  $sorgu = (array)($ortak['planSorgu'] ?? []);
  if (empty($sorgu['urunId'])) return ['hata' => 'Plan taslağında ürün bilgisi yok.'];

  $kunyeler = hks_kunyeleri_getir($cfg, [
    'urunId'        => $sorgu['urunId'],
    'aySayisi'      => (int)($sorgu['aySayisi'] ?? 12),
    'isletmeTuruId' => (int)($sorgu['isletmeTuruId'] ?? 0),
    'sirala'        => (string)($sorgu['sirala'] ?? 'azalan'),
  ]);

  $toplamKalan = 0.0;
  foreach ($kunyeler as $k) $toplamKalan += max(0.0, (float)$k['kalan']);
  $toplamKalan = round($toplamKalan, 3);

  // "Eğer stok varsa" kuralı: yetmiyorsa HİÇBİR ŞEY gönderilmez. Kısmi gönderim
  // yapılmaz — geri alınamaz olduğu için kullanıcıya karar bırakılır.
  if ($toplamKalan < $hedef) {
    return ['hata' => 'Stok yetersiz: plan ' . rtrim(rtrim(number_format($hedef, 3, ',', '.'), '0'), ',') .
      ' KG istiyor, künyelerde toplam ' . rtrim(rtrim(number_format($toplamKalan, 3, ',', '.'), '0'), ',') .
      ' KG kalan var. Hiçbir bildirim gönderilmedi, taslak silinmedi — stok girişi ' .
      'yapıldıktan sonra tekrar deneyin veya taslağı düzenleyip kiloyu düşürün.'];
  }

  $satirlar = [];
  $kalanHedef = $hedef;
  foreach ($kunyeler as $k) {
    if ($kalanHedef <= 0) break;
    $kalan = (float)$k['kalan'];
    if ($kalan <= 0) continue;
    $al = round(min($kalan, $kalanHedef), 3);
    if ($al <= 0) continue;
    $satirlar[] = ['kunyeNo' => $k['kunyeNo'], 'miktar' => $al];
    $kalanHedef = round($kalanHedef - $al, 3);
  }

  if ($kalanHedef > 0) {
    return ['hata' => 'Künyeler hedef kiloyu karşılayamadı (' .
      rtrim(rtrim(number_format($kalanHedef, 3, ',', '.'), '0'), ',') .
      ' KG açık kaldı). Hiçbir bildirim gönderilmedi, taslak silinmedi.'];
  }
  // HKS tek istekte en fazla 100 bildirim kabul eder (bkz. hks_bildirim_dogrula).
  if (count($satirlar) > 100) {
    return ['hata' => 'Bu kilo ' . count($satirlar) . ' künyeye dağıldı; HKS tek seferde en fazla ' .
      '100 bildirime izin veriyor. Taslağı düzenleyip kiloyu bölün. Hiçbir bildirim gönderilmedi.'];
  }
  return ['satirlar' => $satirlar];
}

// "Son kullanılanlar" anahtarı FİRMA BAZLIDIR — firmalar arasında veri karışmaz.
function hks_sonlar_key($firmaId) {
  $firmaId = trim((string)$firmaId);
  return $firmaId !== '' ? ('sonlar_' . $firmaId) : 'sonlar';
}

// Son kullanılan KARŞI TARAF listesini güncelle — SAF fonksiyon (DB'ye dokunmaz).
// Aynı TC/VKN varsa çıkarılıp EN BAŞA alınır (böylece en son kullanılan üstte
// kalır ve bilgileri güncellenir), liste $limit ile sınırlanır.
//
// Saklanan alanlar: tc, ad, cep, dogum. Bunlar KİŞİSEL VERİDİR ve yalnızca
// formu hızlı doldurmak içindir — KARAR MERCİİ DEĞİLDİR. Kişinin HKS kayıt
// durumu zaman içinde değişebileceğinden, seçim yapıldığında arayüz HER ZAMAN
// canlı KayitliKisiSorgu çalıştırır (bkz. app.html karsiTarafSec).
//
// Ad/cep/doğum yalnız DOLU geldiğinde güncellenir: kayıtlı kişide bu alanlar
// gönderilmez (HKS kendi kaydından doldurur), o gönderim eski bilgiyi silmesin.
function hks_karsi_taraf_ekle($liste, $yeni, $limit = 10) {
  $tc = trim((string)($yeni['tc'] ?? ''));
  if ($tc === '') return array_values((array)$liste);

  $eski = null;
  $kalan = [];
  foreach ((array)$liste as $k) {
    if (hks_tc_esit($k['tc'] ?? '', $tc)) { $eski = $k; continue; }
    $kalan[] = $k;
  }
  // TEMİZ (yalnız rakam) hâliyle saklanır: kirli bir TC listeye girerse kişi her
  // seçildiğinde aynı hata tekrarlanırdı ("Mernis'te bulunamadı"). Eşleştirme zaten
  // rakam bazlı olduğundan eski kirli kayıtlar bu yazımla kendiliğinden düzelir.
  $kayit = ['tc' => hks_tc_normalize($tc)];
  foreach (['ad', 'cep', 'dogum'] as $alan) {
    $deger = trim((string)($yeni[$alan] ?? ''));
    $kayit[$alan] = $deger !== '' ? $deger : trim((string)($eski[$alan] ?? ''));
  }
  return array_slice(array_merge([$kayit], $kalan), 0, $limit);
}

// Son kullanılan plaka/ülke/ürün/karşı taraf güncelle (yalnızca ilgili firma için)
function hks_son_guncelle($ortak, $firmaId) {
  $key = hks_sonlar_key($firmaId);
  $son = hks_kv_oku($key, ['plakalar' => [], 'ulkeler' => [], 'urunler' => []]);
  // Eski kayıtlarda bu anahtar yok — varsayılanla tamamla.
  if (!isset($son['karsiTaraflar'])) $son['karsiTaraflar'] = [];
  if (!empty($ortak['ikinciTc'])) {
    $son['karsiTaraflar'] = hks_karsi_taraf_ekle($son['karsiTaraflar'], [
      'tc'    => $ortak['ikinciTc'],
      'ad'    => $ortak['ikinciAd'] ?? '',
      'cep'   => $ortak['ikinciCep'] ?? '',
      'dogum' => $ortak['ikinciDogumTarihi'] ?? '',
    ]);
  }
  if (!empty($ortak['plaka'])) {
    $son['plakalar'] = array_slice(array_merge([$ortak['plaka']],
      array_values(array_filter($son['plakalar'], fn($p) => $p !== $ortak['plaka']))), 0, 10);
  }
  if (!empty($ortak['ulkeId']) && !empty($ortak['ulkeAd'])) {
    $son['ulkeler'] = array_slice(array_merge([['id' => $ortak['ulkeId'], 'ad' => $ortak['ulkeAd']]],
      array_values(array_filter($son['ulkeler'], fn($u) => (string)$u['id'] !== (string)$ortak['ulkeId']))), 0, 3);
  }
  if (!empty($ortak['urunId']) && !empty($ortak['urunAd'])) {
    $son['urunler'] = array_slice(array_merge([['id' => $ortak['urunId'], 'ad' => $ortak['urunAd']]],
      array_values(array_filter($son['urunler'], fn($u) => (string)$u['id'] !== (string)$ortak['urunId']))), 0, 3);
  }
  hks_kv_yaz($key, $son);
}

// =============================================================================
try {
  hks_tablolari_hazirla();
  $action = $_GET['action'] ?? '';
  $g = hks_govde();
  $db = hks_db();

  switch ($action) {

    // ---- FİRMALAR ----
    case 'firmalar': {
      $rows = $db->query('SELECT id, ad, vergi_no, renk, user_name FROM ' . hks_tablo('firmalar') . ' ORDER BY olusturma')->fetchAll();
      $firmalar = array_map(fn($f) => [
        'id' => $f['id'], 'ad' => $f['ad'], 'vergiNo' => $f['vergi_no'],
        'renk' => $f['renk'] ?: 'teal', 'userName' => $f['user_name'],
      ], $rows);
      hks_json_cikti(['firmalar' => $firmalar]);
    }

    case 'firma_kaydet': {
      if (empty($g['ad']) || empty($g['vergiNo'])) hks_json_cikti(['hata' => 'Firma adı ve vergi no zorunludur.'], 400);
      if (!empty($g['id'])) {
        // Güncelle
        $mevcut = hks_firma_bul($g['id']);
        if (!$mevcut) hks_json_cikti(['hata' => 'Firma bulunamadı.'], 400);
        $pass = !empty($g['password']) ? $g['password'] : $mevcut['password'];
        $srv  = !empty($g['servicePassword']) ? $g['servicePassword'] : $mevcut['servicePassword'];
        $user = !empty($g['userName']) ? trim($g['userName']) : $mevcut['userName'];
        $st = $db->prepare('UPDATE ' . hks_tablo('firmalar') . '
          SET ad=?, renk=?, user_name=?, password_enc=?, service_password_enc=?, vergi_no=? WHERE id=?');
        $st->execute([trim($g['ad']), $g['renk'] ?? $mevcut['renk'], $user,
          hks_sifrele($pass), hks_sifrele($srv), trim($g['vergiNo']), $g['id']]);
      } else {
        // Yeni
        if (empty($g['userName']) || empty($g['password']) || empty($g['servicePassword'])) {
          hks_json_cikti(['hata' => 'Yeni firma için kullanıcı adı ve şifreler zorunludur.'], 400);
        }
        $id = 'f' . round(microtime(true) * 1000);
        $st = $db->prepare('INSERT INTO ' . hks_tablo('firmalar') . '
          (id, ad, renk, user_name, password_enc, service_password_enc, vergi_no)
          VALUES (?,?,?,?,?,?,?)');
        $st->execute([$id, trim($g['ad']), $g['renk'] ?? 'teal', trim($g['userName']),
          hks_sifrele($g['password']), hks_sifrele($g['servicePassword']), trim($g['vergiNo'])]);
      }
      hks_json_cikti(['tamam' => true]);
    }

    case 'firma_sil': {
      $st = $db->prepare('DELETE FROM ' . hks_tablo('firmalar') . ' WHERE id = ?');
      $st->execute([$g['id'] ?? '']);
      hks_json_cikti(['tamam' => true]);
    }

    // ---- SON KULLANILANLAR (firma bazlı) ----
    case 'sonlar': {
      $sonKey = hks_sonlar_key($g['firmaId'] ?? '');
      $varsayilan = ['plakalar' => [], 'ulkeler' => [], 'urunler' => [], 'karsiTaraflar' => []];
      // Eski kayıtlarda 'karsiTaraflar' yok — arayüz her zaman dizi görsün.
      hks_json_cikti(array_merge($varsayilan, (array)hks_kv_oku($sonKey, $varsayilan)));
    }

    // ---- REFERANS LİSTELERİ ----
    case 'listeler': {
      $cache = hks_kv_oku('listeler_cache', null);
      hks_json_cikti($cache ?: ['bos' => true]);
    }
    case 'listeler_yenile': {
      $cfg = hks_firma_bul($g['firmaId'] ?? '');
      if (!$cfg) hks_json_cikti(['hata' => 'Firma bulunamadı. Önce firma seçin.'], 400);
      $cache = hks_tum_listeler($cfg);
      hks_kv_yaz('listeler_cache', $cache);
      hks_json_cikti($cache);
    }

    // ---- KÜNYELER ----
    case 'kunyeler': {
      $cfg = hks_firma_bul($g['firmaId'] ?? '');
      if (!$cfg) hks_json_cikti(['hata' => 'Firma bulunamadı. Önce firma seçin.'], 400);
      @set_time_limit(300);   // 5 yıllık taramada 60 ardışık SOAP çağrısı olabilir
      $liste = hks_kunyeleri_getir($cfg, $g);
      hks_json_cikti(['kunyeler' => $liste]);
    }

    // ---- STOK ÖZETİ (referans künye kalanlarının ürün bazında toplamı) ----
    case 'stok': {
      $cfg = hks_firma_bul($g['firmaId'] ?? '');
      if (!$cfg) hks_json_cikti(['hata' => 'Firma bulunamadı. Önce firma seçin.'], 400);
      @set_time_limit(600);  // 5 yıl = 60 ardışık SOAP çağrısı
      $ay = isset($g['aySayisi']) ? max(1, min(60, (int)$g['aySayisi'])) : 60;
      $liste  = hks_stok_ozet($cfg, $ay);
      $toplam = array_sum(array_map(fn($s) => (float)$s['kalan'], $liste));
      hks_json_cikti(['stok' => $liste, 'toplam' => $toplam, 'zaman' => date('c')]);
    }

    // ---- KÜNYE DETAYLARI (fiyat / rüsum / plaka-belge — künye seçimi zenginleştirmesi) ----
    // Ayrı, OPSİYONEL uç nokta: 'kunyeler' eylemi çalışır durumda kalır, bu eylem
    // yalnız aynı KunyeNo'lar için ek alan getirir (bkz. hks_soap.php üstteki not —
    // ReferansKunyeDTO'da fiyat/rüsum hiç yok, yalnız BildirimSorguDTO'da var).
    case 'kunye_detay': {
      $cfg = hks_firma_bul($g['firmaId'] ?? '');
      if (!$cfg) hks_json_cikti(['hata' => 'Firma bulunamadı. Önce firma seçin.'], 400);
      @set_time_limit(300);   // künyeler eylemiyle aynı üst sınır (bkz. o eylemdeki yorum)
      $ay = isset($g['aySayisi']) ? max(1, min(60, (int)$g['aySayisi'])) : 12;
      $sonuc = hks_kunye_detaylari_getir($cfg, $ay);

      // id'leri isimlere çöz (önbellekten — liste indirilmemişse id ham kalır, hata değil)
      $cache = hks_kv_oku('listeler_cache', null);
      foreach ($sonuc['harita'] as &$d) {
        $d['bildirimTuru'] = hks_liste_ad($cache, 'bildirimTurleri', $d['bildirimTuruId'] ?? null);
        $d['belgeTipi']    = hks_liste_ad($cache, 'belgeTipleri', $d['belgeTipiId'] ?? null);
        // Malın sahibi TC'si sorgulayan firmanın kendisiyle eşleşiyorsa (künye
        // seçiminde çoğu zaman böyledir — bu bizim referans künyemiz) ad ekstra
        // sorgu gerektirmeden bilinir.
        if ($d['malinSahibiTc'] !== '' && $d['malinSahibiTc'] === (string)$cfg['vergiNo']) {
          $d['malinSahibiAd'] = $cfg['ad'];
        }
      }
      unset($d);

      hks_json_cikti([
        'detaylar'  => $sonuc['harita'],
        'kismiHata' => $sonuc['kismiHata'],
      ]);
    }

    // ---- TOPLU KÜNYE (Bildirim Çoklu Künye Basım) ----
    case 'toplu_kunye': {
      $cfg = hks_firma_bul($g['firmaId'] ?? '');
      if (!$cfg) hks_json_cikti(['hata' => 'Firma bulunamadı. Önce firma seçin.'], 400);
      @set_time_limit(120);
      $liste  = hks_toplu_kunye($cfg, trim($g['tarih'] ?? ''), trim($g['plaka'] ?? ''), trim($g['belgeNo'] ?? ''));
      $toplam = array_sum(array_map(fn($s) => (float)$s['miktar'], $liste));
      hks_json_cikti(['kunyeler' => $liste, 'toplam' => $toplam]);
    }

    // ---- TASLAKLAR (yalnızca seçili firmanın taslakları) ----
    case 'taslaklar': {
      $fid = trim($g['firmaId'] ?? '');
      if ($fid === '') hks_json_cikti(['hata' => 'Firma seçilmedi.'], 400);
      $st = $db->prepare('SELECT * FROM ' . hks_tablo('taslaklar') . ' WHERE firma_id = ? ORDER BY zaman DESC');
      $st->execute([$fid]);
      $rows = $st->fetchAll();
      $taslaklar = array_map(function ($r) {
        $veri = json_decode($r['veri'], true);
        return [
          'id' => $r['id'], 'zaman' => (new DateTime($r['zaman']))->format('c'),
          'firmaId' => $r['firma_id'], 'firmaAd' => $r['firma_ad'],
          'satirlar' => $veri['satirlar'], 'ortak' => $veri['ortak'],
        ];
      }, $rows);
      hks_json_cikti(['taslaklar' => $taslaklar]);
    }
    case 'taslak_kaydet': {
      $cfg = hks_firma_bul($g['firmaId'] ?? '');
      if (!$cfg) hks_json_cikti(['hata' => 'Firma bulunamadı.'], 400);
      // PLAN TASLAĞI: künye seçilmeden, yalnız toplam kilo + fiyat ile kaydedilir.
      // Künyeler gönderim anında canlı stoktan çözülür (hks_plan_kunye_coz).
      $planMi = (float)($g['ortak']['planKg'] ?? 0) > 0 && empty($g['satirlar']);
      $hata = hks_bildirim_dogrula($g, $planMi);
      if ($hata) hks_json_cikti(['hata' => $hata], 400);
      $id = 't' . round(microtime(true) * 1000);
      $st = $db->prepare('INSERT INTO ' . hks_tablo('taslaklar') . '
        (id, zaman, firma_id, firma_ad, veri) VALUES (?,?,?,?,?)');
      $st->execute([$id, date('Y-m-d H:i:s'), $g['firmaId'], $cfg['ad'],
        json_encode(['satirlar' => $planMi ? [] : $g['satirlar'], 'ortak' => $g['ortak']], JSON_UNESCAPED_UNICODE)]);
      hks_json_cikti(['tamam' => true]);
    }
    case 'taslak_sil': {
      // Yalnızca seçili firmanın taslağı silinebilir (firma-izolasyonu).
      $fid = trim($g['firmaId'] ?? '');
      $st = $db->prepare('DELETE FROM ' . hks_tablo('taslaklar') . ' WHERE id = ? AND firma_id = ?');
      $st->execute([$g['id'] ?? '', $fid]);
      hks_json_cikti(['tamam' => true]);
    }
    case 'taslak_gonder': {
      // Yalnızca seçili firmanın taslağı gönderilebilir (firma-izolasyonu).
      $fid = trim($g['firmaId'] ?? '');
      $st = $db->prepare('SELECT * FROM ' . hks_tablo('taslaklar') . ' WHERE id = ? AND firma_id = ?');
      $st->execute([$g['id'] ?? '', $fid]);
      $t = $st->fetch();
      if (!$t) hks_json_cikti(['hata' => 'Taslak bulunamadı (veya bu firmaya ait değil).'], 400);

      // ── ATOMİK SAHİPLENME (çift gönderim koruması) ───────────────────────
      // BildirimKaydet GERİ ALINAMAZ ve rüsum doğurur. Eskiden taslak yalnızca
      // gönderim BİTTİKTEN sonra siliniyordu; SELECT ile DELETE arasındaki
      // pencerede (plan taslağında SOAP turları yüzünden 300 sn'ye kadar) aynı
      // taslak için gelen İKİNCİ bir istek de SELECT'i geçip AYNI bildirimleri
      // TEKRAR gönderebiliyordu — mükerrer künye + mükerrer rüsum. Gerçekçi
      // tetikleyiciler: isteğin zaman aşımına uğrayıp kullanıcının tekrar
      // "Gönder"e basması, ikinci sekme/cihaz, tarayıcının isteği yeniden
      // denemesi. (Tarayıcıdaki buton kilidi yalnız o sekmeyi korur.)
      //
      // Çözüm — şema değişikliği GEREKTİRMEZ: satır ÖNCE silinerek sahiplenilir.
      // DELETE tek satırda atomiktir; yarışı yalnız BİR istek kazanır (rowCount=1),
      // diğeri 0 alır ve hiçbir şey göndermeden durur. Gönderim herhangi bir
      // nedenle durursa satır AYNI id ve AYNI zaman ile geri yazılır, böylece
      // "taslak silinmedi" güvencesi ve liste sırası korunur.
      $__claim = $db->prepare('DELETE FROM ' . hks_tablo('taslaklar') . ' WHERE id = ? AND firma_id = ?');
      $__claim->execute([$t['id'], $fid]);
      if ($__claim->rowCount() === 0) {
        hks_json_cikti(['hata' => 'Bu taslak için başka bir gönderim şu anda sürüyor ' .
          '(veya az önce tamamlandı). Mükerrer bildirim gönderilmesin diye işlem durduruldu. ' .
          'Sonucu görmek için "Gönderilenler" listesini kontrol edin.'], 409);
      }
      // Gönderim durursa taslağı olduğu gibi geri koyar (id + zaman korunur).
      $taslagiGeriKoy = function () use ($db, $t) {
        try {
          $db->prepare('INSERT INTO ' . hks_tablo('taslaklar') . '
            (id, zaman, firma_id, firma_ad, veri) VALUES (?,?,?,?,?)')
             ->execute([$t['id'], $t['zaman'], $t['firma_id'], $t['firma_ad'], $t['veri']]);
        } catch (Throwable $e) { error_log('[hks] taslak geri yazilamadi: ' . $e->getMessage()); }
      };

      $cfg = hks_firma_bul($t['firma_id']);
      if (!$cfg) {
        $taslagiGeriKoy();
        hks_json_cikti(['hata' => 'Taslağın firması artık kayıtlı değil.'], 400);
      }
      $veri = json_decode($t['veri'], true);
      $satirlar = $veri['satirlar'];
      $ortak = $veri['ortak'];

      // PLAN TASLAĞI — künyeler burada, GÖNDERİM ANINDA canlı stoktan çözülür.
      // Kullanıcı taslağı yalnız "toplam kilo + birim fiyat" ile kaydetmişti;
      // künye seçimi (tıpkı "Künyeleri Getir" + "⚡ Otomatik Dağıt" gibi) şimdi
      // TAZE veriyle yapılır. Stok yetmiyorsa HİÇBİR bildirim gönderilmez ve
      // taslak SİLİNMEZ — aşağıdaki tüm denetimler çözülmüş satırlarla normal
      // (plan olmayan) taslakla AYNI şekilde çalışmaya devam eder.
      $planMi = empty($satirlar) && (float)($ortak['planKg'] ?? 0) > 0;
      if ($planMi) {
        @set_time_limit(300);   // 'kunyeler' eylemiyle aynı üst sınır
        $__plan = hks_plan_kunye_coz($cfg, $ortak);
        if (!empty($__plan['hata'])) {
          $taslagiGeriKoy();
          hks_json_cikti(['hata' => $__plan['hata'], 'taslakKorundu' => true], 400);
        }
        $satirlar = $__plan['satirlar'];
      }

      // GÖNDERİM ÖNCESİ KURAL BÜTÜNLÜĞÜ (P1) — taslak kaydedildiğinde geçerliydi;
      // gönderim ANINDA da geçerli olduğu TEKRAR doğrulanır (referans künye,
      // zorunlu alanlar, vb. — hks_bildirim_dogrula() taslak_kaydet ile AYNI
      // fonksiyon). Eskiden bu adım yalnız taslak_kaydet'te çalışıyordu; DB'de
      // duran eski/bozuk bir taslak doğrudan hks_bildirim_kaydet()'e (CANLI,
      // geri alınamaz) gidebiliyordu.
      $__revalHata = hks_bildirim_dogrula(['satirlar' => $satirlar, 'ortak' => $ortak]);
      if ($__revalHata) {
        $taslagiGeriKoy();
        hks_json_cikti(['hata' => 'Taslak artık geçerli kurallara uymuyor: ' . $__revalHata .
          ' Taslak silinmedi — "Düzenle" ile güncelleyip tekrar deneyin.'], 400);
      }

      // GÖNDERİM ÖNCESİ SON GÜVENLİK (AYNA) — karşı tarafın GTB kayıt durumu,
      // taslak kaydedildikten sonra değişmiş olabilir. Geri alınamaz bildirimden
      // önce GTB'ye TEKRAR sorulur; hem gate kararı hem (varsa) teşhis kaydı AYNI
      // sorgu sonucuna dayanır (P2 TOCTOU: tek istek, tek doğrulama sonucu).
      // İki AYNA kural:
      //   • Sevk Etme            → karşı taraf KAYITLI olmalı (kılavuz 0.1.14).
      //   • Üreticiden Sevk Alım → üretici KAYITSIZ olmalı ("sadece kayıtsız
      //     üreticiden yapılan sevkiyat işlemlerinde kullanılacak bildirim türü").
      // SATIN ALIM ARTIK BU DENETİMİN DIŞINDA: GTB 12.03.2025 duyurusundan sonra
      // kayıtsız kişiden Satın Alım yapılabiliyor (kişi TC+doğum tarihiyle KPS'ten
      // doğrulanıyor; canlı sistemde künye üretildiği doğrulandı). Frontend bu
      // türde `kayitZorunlu=false` gönderir, dolayısıyla sorgu hiç çalışmaz.
      // P0: durum sorgusu tri-state (REGISTERED/NOT_REGISTERED/UNKNOWN).
      // UNKNOWN durumunda — kayıt durumu HANGİ yönde olursa olsun — gönderim
      // DURUR ve BildirimKaydet ÇAĞRILMAZ (eskiden "sorgu sonuçsuz → kayıtsız"
      // sayılıp Üreticiden Sevk Alım'da denetim sessizce atlanıyordu).
      $__uretSevk = hks_uret_sevk_mi($ortak);
      if (!empty($ortak['ikinciTc']) && ($__uretSevk || !empty($ortak['kayitZorunlu']))) {
        $__aynaHam = null; $__aynaDetay = null;
        $__durum = hks_kayit_durumu($cfg, $ortak['ikinciTc'], $__aynaHam, $__aynaDetay);
        if ($__durum === HKS_DURUM_UNKNOWN) {
          $taslagiGeriKoy();
          hks_json_cikti(['hata' => 'HKS kişi kayıt durumu doğrulanamadı. Bildirim gönderilmedi. ' .
            'Taslak silinmedi — birkaç dakika sonra tekrar deneyin.'], 400);
        }
        if ($__uretSevk && $__durum === HKS_DURUM_REGISTERED) {
          $taslagiGeriKoy();
          hks_json_cikti(['hata' => 'Bu kişi HKS sisteminde kayıtlıdır. Üreticiden Sevk Alım ' .
            'bildirimi yapılamaz. Kayıtlı kişiden alım için bildirim türünü "Satın Alım" yapın. ' .
            'Taslak silinmedi.'], 400);
        }
        if (!$__uretSevk && $__durum === HKS_DURUM_NOT_REGISTERED) {
          $taslagiGeriKoy();
          hks_json_cikti(['hata' => 'Gönderim DURDURULDU: karşı taraf (' . $ortak['ikinciTc'] .
            ') GTB sisteminde kayıtlı değil. Sevk Etme için karşı tarafın kayıtlı olması ' .
            'zorunludur. Kayıtsız müstahsilden alım için "Satın Alım" veya "Üreticiden Sevk ' .
            'Alım" türünü kullanın. Taslak silinmedi.'], 400);
        }
      }

      // Bu çağrı GERİ ALINAMAZ. İstisna fırlatırsa (SOAP zaman aşımı, ağ kopması)
      // HKS'in isteği ALIP ALMADIĞINI BİLEMEYİZ. Taslak sahiplenme sırasında
      // silindiği için burada geri konmazsa kullanıcının emeği kaybolur ve
      // gönderilip gönderilmediği de anlaşılamaz. Bu yüzden taslak GERİ KONUR
      // (istisna öncesi davranışla aynı) ama körlemesine tekrar göndermemesi
      // için AÇIKÇA uyarılır.
      try {
        $sonuc = hks_bildirim_kaydet($cfg, $satirlar, $ortak);
      } catch (Throwable $__e) {
        $taslagiGeriKoy();
        error_log('[hks] bildirim_kaydet istisna: ' . $__e->getMessage());
        hks_json_cikti([
          'hata' => 'Bildirim gönderilirken bağlantı kesildi: ' . $__e->getMessage() .
            ' — HKS\'in bildirimi ALIP ALMADIĞI BİLİNMİYOR. Taslak silinmedi. ' .
            'TEKRAR GÖNDERMEDEN ÖNCE hks.hal.gov.tr üzerinden veya "Bildirim Sorgulama" ' .
            'ekranından künyenin oluşup oluşmadığını KONTROL EDİN; oluştuysa taslağı silin.',
          'taslakKorundu' => true,
        ], 502);
      }

      // Gönderilenler kaydı (tek satır özet)
      $basarili = array_filter($sonuc['sonuclar'], fn($s) => $s['yeniKunyeNo'] && $s['yeniKunyeNo'] !== '0' && !$s['hataKodu']);

      // KRİTİK: HKS'e TEK KÜNYE bile gitmediyse (tam başarısızlık — ör. sıfat
      // uyumsuzluğu, GTBGLB00000001 vb.) taslak SİLİNMEZ ve "Gönderilenler"e
      // sahte bir kayıt atılmaz (orada hiçbir şey gönderilmedi). Kullanıcı
      // taslağa geri döner, hatalı alanı (ör. karşı taraf sıfatı) "Düzenle" ile
      // değiştirip AYNI taslağı tekrar gönderebilir — formu baştan doldurmaz.
      if (count($basarili) === 0) {
        $taslagiGeriKoy();
        hks_json_cikti($sonuc + ['taslakKorundu' => true]);
      }

      // En az bir künye gerçekten oluştu → bu GERİ ALINAMAZ, kayıt tutulmalı.
      hks_son_guncelle($ortak, $t['firma_id']);
      $gid = 'g' . round(microtime(true) * 1000);
      $toplamKg = array_sum(array_map(fn($s) => (float)$s['miktar'], $satirlar));
      $rusum = array_sum(array_map(fn($s) => (float)$s['rusum'], $sonuc['sonuclar']));
      $yeniKunyeler = array_values(array_map(fn($s) => $s['yeniKunyeNo'], $basarili));
      $st = $db->prepare('INSERT INTO ' . hks_tablo('gonderilenler') . '
        (id, zaman, firma_id, firma_ad, plaka, belge_no, ulke_ad, urun_ad, adet, toplam_kg, fiyat, rusum, hata_sayisi, genel_hata, bildirim_turu, veri)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
      $st->execute([$gid, date('Y-m-d H:i:s'), $t['firma_id'], $t['firma_ad'],
        $ortak['plaka'] ?? '', $ortak['belgeNo'] ?? '', $ortak['ulkeAd'] ?? '', $ortak['urunAd'] ?? '',
        count($satirlar), $toplamKg, $ortak['fiyat'] ?? 0, $rusum,
        count($sonuc['sonuclar']) - count($basarili), $sonuc['genelHata'], hks_bildirim_turu_kodu($ortak),
        json_encode(['yeniKunyeler' => $yeniKunyeler, 'sonuclar' => $sonuc['sonuclar']], JSON_UNESCAPED_UNICODE)]);

      // Taslak zaten en başta ATOMİK olarak sahiplenilirken silinmişti (çift
      // gönderim koruması) ve buraya gelindiyse en az bir künye GERİ ALINAMAZ
      // şekilde gönderildi → geri konmaz. Kısmi başarısızlıkta da geri konmaz:
      // aynı taslağı tekrar göndermek BAŞARILI satırları MÜKERRER gönderirdi.
      // Hangi satırların başarısız olduğu $sonuc.sonuclar içinde döner.
      hks_json_cikti($sonuc);
    }

    // ---- GÖNDERİLENLER (yalnızca seçili firma) ----
    case 'gonderilenler': {
      $fid = trim($g['firmaId'] ?? '');
      $fad = trim($g['firmaAd'] ?? '');
      if ($fid === '') hks_json_cikti(['hata' => 'Firma seçilmedi.'], 400);
      // firma_id ile eşleş; firma_id'si olmayan ESKİ kayıtlar için yalnızca
      // aynı firma ADINA sahip olanları göster (firma-izolasyonu korunur).
      $st = $db->prepare('SELECT * FROM ' . hks_tablo('gonderilenler') . '
        WHERE firma_id = ? OR (COALESCE(firma_id, \'\') = \'\' AND firma_ad = ?)
        ORDER BY zaman DESC LIMIT 500');
      $st->execute([$fid, $fad]);
      $rows = $st->fetchAll();
      $liste = array_map(function ($r) {
        $veri = json_decode($r['veri'], true) ?: [];
        return [
          'id' => $r['id'], 'zaman' => (new DateTime($r['zaman']))->format('c'),
          'firmaId' => $r['firma_id'] ?? '', 'firmaAd' => $r['firma_ad'],
          'plaka' => $r['plaka'], 'belgeNo' => $r['belge_no'],
          'ulkeAd' => $r['ulke_ad'], 'urunAd' => $r['urun_ad'], 'adet' => (int)$r['adet'],
          'toplamKg' => (float)$r['toplam_kg'], 'fiyat' => (float)$r['fiyat'], 'rusum' => (float)$r['rusum'],
          'hataSayisi' => (int)$r['hata_sayisi'], 'genelHata' => $r['genel_hata'],
          'bildirimTuru' => $r['bildirim_turu'] ?? null,   // P3 — NULL: legacy kayıt (backfill yapılmadı)
          'yeniKunyeler' => $veri['yeniKunyeler'] ?? [],
        ];
      }, $rows);
      hks_json_cikti(['gonderilenler' => $liste]);
    }

    // ---- ÜRÜN REFERANSLARI (referanssız bildirim / Satın Alım için) ----
    // Salt-okunur. Mevcut 'listeler' önbelleğine DOKUNMAZ — orası bozulmasın.
    case 'urun_listeleri': {
      $cfg = hks_firma_bul($g['firmaId'] ?? '');
      if (!$cfg) hks_json_cikti(['hata' => 'Firma bulunamadı. Önce firma seçin.'], 400);
      @set_time_limit(120);
      hks_json_cikti(hks_urun_listeleri($cfg));
    }

    // ---- ÜRÜN CİNSLERİ (ürüne bağlı; UrunId zorunlu) ----
    case 'urun_cinsleri': {
      $cfg = hks_firma_bul($g['firmaId'] ?? '');
      if (!$cfg) hks_json_cikti(['hata' => 'Firma bulunamadı.'], 400);
      if (empty($g['urunId'])) hks_json_cikti(['hata' => 'Ürün seçilmedi.'], 400);
      @set_time_limit(60);
      $ham = null;
      $liste = hks_urun_cinsleri($cfg, (int)$g['urunId'], $ham);
      $out = ['cinsler' => $liste];
      if (!count($liste)) $out['ham'] = $ham;
      hks_json_cikti($out);
    }

    // ---- BİLDİRİM SORGULAMA (yaptığım bildirimler) ----
    case 'sorgu': {
      $cfg = hks_firma_bul($g['firmaId'] ?? '');
      if (!$cfg) hks_json_cikti(['hata' => 'Firma bulunamadı. Önce firma seçin.'], 400);
      @set_time_limit(180);
      $ay = isset($g['aySayisi']) ? max(1, min(24, (int)$g['aySayisi'])) : 1;
      $ham = null;
      $liste = hks_yaptigim_bildirimler($cfg, $ay, $ham);
      $out = ['bildirimler' => $liste, 'zaman' => date('c')];
      if (!count($liste)) $out['ham'] = $ham;
      hks_json_cikti($out);
    }

    // ---- BİLDİRİM ETİKET (2'li künye etiketleri) ----
    case 'etiket': {
      $cfg = hks_firma_bul($g['firmaId'] ?? '');
      if (!$cfg) hks_json_cikti(['hata' => 'Firma bulunamadı. Önce firma seçin.'], 400);
      @set_time_limit(120);
      $ham = null;
      $liste = hks_etiketler($cfg, trim($g['tarih'] ?? ''), trim($g['plaka'] ?? ''), trim($g['belgeNo'] ?? ''), $ham);
      $out = ['etiketler' => $liste];
      if (!count($liste)) $out['ham'] = $ham;
      hks_json_cikti($out);
    }

    // ---- BİLDİRİM PAYLOAD ÖNİZLEME (dry-run: GÖNDERMEZ, sadece XML kurar) ----
    // İrreversible göndermeden önce yurt içi payload'ı gözle doğrulamak için.
    case 'bildirim_onizle': {
      $hata = hks_bildirim_dogrula($g);
      if ($hata) hks_json_cikti(['hata' => $hata], 400);
      // hks_bildirim_xml şifre İÇERMEZ (yalnızca Istek gövdesi) — güvenle döndürülür.
      $xml = hks_bildirim_xml($g['satirlar'] ?? [], $g['ortak'] ?? []);
      hks_json_cikti(['xml' => $xml, 'gonderilmedi' => true]);
    }

    // ---- YURT İÇİ ADRES / KİŞİ REFERANSLARI (Sevk Etme + yurt içi satış) ----
    // Tümü SALT-OKUNUR; bildirim oluşturmaz. Boş liste dönerse ham yanıt eklenir.
    case 'iller': {
      $cfg = hks_firma_bul($g['firmaId'] ?? '');
      if (!$cfg) hks_json_cikti(['hata' => 'Firma bulunamadı. Önce firma seçin.'], 400);
      @set_time_limit(60);
      $ham = null;
      $liste = hks_iller($cfg, $ham);
      $out = ['iller' => $liste] + (count($liste) ? [] : ['ham' => $ham]);
      if (!empty($g['debug'])) { $out['ns'] = hks_ns_listesi(); $out['hamXml'] = hks_son_ham_gorunur(); }
      hks_json_cikti($out);
    }
    case 'ilceler': {
      $cfg = hks_firma_bul($g['firmaId'] ?? '');
      if (!$cfg) hks_json_cikti(['hata' => 'Firma bulunamadı.'], 400);
      if (empty($g['ilId'])) hks_json_cikti(['hata' => 'İl seçilmedi.'], 400);
      @set_time_limit(60);
      $ham = null;
      $liste = hks_ilceler($cfg, (int)$g['ilId'], $ham);
      $out = ['ilceler' => $liste] + (count($liste) ? [] : ['ham' => $ham]);
      if (!empty($g['debug'])) { $out['ns'] = hks_ns_listesi(); $out['hamXml'] = hks_son_ham_gorunur(); }
      hks_json_cikti($out);
    }
    case 'beldeler': {
      $cfg = hks_firma_bul($g['firmaId'] ?? '');
      if (!$cfg) hks_json_cikti(['hata' => 'Firma bulunamadı.'], 400);
      if (empty($g['ilceId'])) hks_json_cikti(['hata' => 'İlçe seçilmedi.'], 400);
      @set_time_limit(60);
      $ham = null;
      $liste = hks_beldeler($cfg, (int)$g['ilceId'], $ham);
      $out = ['beldeler' => $liste] + (count($liste) ? [] : ['ham' => $ham]);
      if (!empty($g['debug'])) { $out['ns'] = hks_ns_listesi(); $out['hamXml'] = hks_son_ham_gorunur(); }
      hks_json_cikti($out);
    }
    case 'isyerleri': {
      $cfg = hks_firma_bul($g['firmaId'] ?? '');
      if (!$cfg) hks_json_cikti(['hata' => 'Firma bulunamadı.'], 400);
      $tur = trim($g['tur'] ?? '');
      $tc  = trim($g['tcVkn'] ?? '');
      if ($tc === '') hks_json_cikti(['hata' => 'TC/Vergi No gerekli.'], 400);
      @set_time_limit(60);
      $ham = null;
      $liste = hks_isyerleri($cfg, $tur, $tc, $ham);
      $out = ['isyerleri' => $liste] + (count($liste) ? [] : ['ham' => $ham]);
      if (!empty($g['debug'])) { $out['ns'] = hks_ns_listesi(); $out['hamXml'] = hks_son_ham_gorunur(); }
      hks_json_cikti($out);
    }
    case 'kayitli_kisi': {
      // P0 (kılavuz 0.1.14 uyum düzeltmesi): dönüş artık HER ZAMAN tri-state
      // "durum" alanı taşır — REGISTERED | NOT_REGISTERED | UNKNOWN. Eskiden
      // "liste boş → kayitliMi=false" çıkarımı yapılıyordu; bu KALDIRILDI.
      $cfg = hks_firma_bul($g['firmaId'] ?? '');
      if (!$cfg) hks_json_cikti(['hata' => 'Firma bulunamadı.'], 400);
      $tc = trim($g['tcVkn'] ?? '');
      if ($tc === '') hks_json_cikti(['hata' => 'TC/Vergi No gerekli.'], 400);
      @set_time_limit(60);
      $ham = null; $detay = null;
      $durum = hks_kayit_durumu($cfg, $tc, $ham, $detay);
      $out = [
        'kisi' => [
          'tcVkn'     => $tc,
          'durum'     => $durum,
          'kayitliMi' => $durum === HKS_DURUM_REGISTERED,   // geriye dönük kolaylık alanı
          'sifatlar'  => $detay['sifatIds'] ?? [],
        ],
      ];
      if ($durum === HKS_DURUM_UNKNOWN) $out['ham'] = $ham;
      if (!empty($g['debug'])) {
        $out['ns'] = hks_ns_listesi(); $out['hamXml'] = hks_son_ham_gorunur();
        // Teşhis metadata — parola/ServicePassword/tam credential/ham SOAP body İÇERMEZ.
        $out['detay'] = $detay;
      }
      hks_json_cikti($out);
    }

    default:
      hks_json_cikti(['hata' => 'Bilinmeyen işlem: ' . $action], 404);
  }
} catch (Throwable $e) {
  hks_json_cikti(['hata' => $e->getMessage()], 500);
}
