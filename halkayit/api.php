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

// Doğrulama (taslak/bildirim ortak) — yurt dışı (mevcut export) ve yurt içi (Sevk
// Etme / yurt içi Satış / Satın Alım / Üreticiden Sevk Alım) için ayrı kurallar.
// Yurt dışı yolu birebir korunur.
//
// NOT: Bu denetimler tarayıcıdaki denetimlerin KOPYASI değil, SON SÖZÜdür.
// `app.html` statik dosyadır ve tarayıcı/SW önbelleğinden eski sürümü sunulabilir;
// CANLI ve geri alınamaz bir sisteme giden alanların doğruluğu sunucuda da
// güvence altına alınmalıdır.
function hks_bildirim_dogrula($g) {
  if (empty($g['satirlar']) || !is_array($g['satirlar'])) return 'En az bir künye satırı gerekli.';
  if (count($g['satirlar']) > 100) return 'Tek seferde en fazla 100 bildirim.';
  $o = $g['ortak'] ?? [];
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
    if (empty($o['ikinciSifatId'])) return 'Karşı taraf sıfatı gerekli.';

    // ── ÜRETİCİDEN SEVK ALIM (docx kuralları) ──────────────────────────────
    // • "Sadece kayıtsız üreticiden yapılan sevkiyat işlemlerinde kullanılacak
    //    bildirim türüdür."   → karşı tarafın KAYITLI olması yasak (gönderim
    //    anında `taslak_gonder` içinde GTB'ye tekrar sorulur).
    // • "Bildirim türü 'Üreticiden Sevk Alım'sa referanslı bildirim yapılamaz."
    // • "Bildirim türü 'Üreticiden Sevk Alım'sa, İkinci kişi sıfat bilgisi
    //    'Üretici' olmalıdır."
    if ($uret) {
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
      $sifatAd = hks_sifat_adi($o['ikinciSifatId']);
      if ($sifatAd !== null && mb_stripos($sifatAd, 'üretici', 0, 'UTF-8') === false) {
        return '"Üreticiden Sevk Alım"da karşı taraf sıfatı "Üretici" olmalıdır (gönderilen: ' .
               $sifatAd . ').';
      }
    }

    // ── KAYITSIZ İKİNCİ KİŞİ (docx) ────────────────────────────────────────
    // "İkinci kişi 'TcKimlikVergiNo' alanı GTB sisteminde kayıtlı değilse
    //  'Eposta' bilgisi hariç diğer bilgilerinde gönderilmesi gerekir."
    // → AdSoyad + CepTel zorunlu. İki durumda karşı taraf kayıtsızdır:
    //   yurt içi Satış'ta adres hedefi (hedefAdres) ve Üreticiden Sevk Alım.
    // Üretici sevkte hedef işyeri seçimiyle bildirildiği için bu denetim
    // eskiden yalnız `hedefAdres` dalındaydı ve üretici sevkte hiç çalışmıyordu.
    if ($uret || !empty($o['hedefAdres'])) {
      $kim = $uret ? 'üretici' : 'alıcı';
      if (empty($o['ikinciAd'])) return 'Kayıtsız ' . $kim . ' için Ad/Ünvan gerekli.';
      $cep = hks_cep_rakam($o['ikinciCep'] ?? '');
      if ($cep === '') return 'Kayıtsız ' . $kim . ' için Cep Telefonu gerekli.';
      if (strlen($cep) < 10 || strlen($cep) > 13) {
        return 'Cep telefonu geçersiz — en az 10 rakam olmalıdır (örn. 05001112233).';
      }
    }

    if (!empty($o['hedefAdres'])) {
      // Kayıtsız alıcı (yurt içi Satış): il/ilçe/belde.
      if (empty($o['ilId']) || empty($o['ilceId']) || empty($o['beldeId'])) return 'İl / İlçe / Belde seçilmeli.';
    } else {
      // Kayıtlı alıcı / Sevk Etme / Satın Alım / Üreticiden Sevk Alım: gidecek işyeri.
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

// "Son kullanılanlar" anahtarı FİRMA BAZLIDIR — firmalar arasında veri karışmaz.
function hks_sonlar_key($firmaId) {
  $firmaId = trim((string)$firmaId);
  return $firmaId !== '' ? ('sonlar_' . $firmaId) : 'sonlar';
}

// Son kullanılan plaka/ülke/ürün güncelle (yalnızca ilgili firma için)
function hks_son_guncelle($ortak, $firmaId) {
  $key = hks_sonlar_key($firmaId);
  $son = hks_kv_oku($key, ['plakalar' => [], 'ulkeler' => [], 'urunler' => []]);
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
      hks_json_cikti(hks_kv_oku($sonKey, ['plakalar' => [], 'ulkeler' => [], 'urunler' => []]));
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
      $hata = hks_bildirim_dogrula($g);
      if ($hata) hks_json_cikti(['hata' => $hata], 400);
      $id = 't' . round(microtime(true) * 1000);
      $st = $db->prepare('INSERT INTO ' . hks_tablo('taslaklar') . '
        (id, zaman, firma_id, firma_ad, veri) VALUES (?,?,?,?,?)');
      $st->execute([$id, date('Y-m-d H:i:s'), $g['firmaId'], $cfg['ad'],
        json_encode(['satirlar' => $g['satirlar'], 'ortak' => $g['ortak']], JSON_UNESCAPED_UNICODE)]);
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
      $cfg = hks_firma_bul($t['firma_id']);
      if (!$cfg) hks_json_cikti(['hata' => 'Taslağın firması artık kayıtlı değil.'], 400);
      $veri = json_decode($t['veri'], true);
      $satirlar = $veri['satirlar'];
      $ortak = $veri['ortak'];

      // GÖNDERİM ÖNCESİ SON GÜVENLİK — karşı tarafın GTB kayıt durumu, taslak
      // kaydedildikten sonra değişmiş olabilir. Geri alınamaz bildirimden önce
      // GTB'ye tekrar sorulur. İki AYNA kural (docx):
      //   • Sevk Etme / Satın Alım  → karşı taraf KAYITLI olmalı.
      //   • Üreticiden Sevk Alım    → üretici KAYITSIZ olmalı ("sadece kayıtsız
      //     üreticiden yapılan sevkiyat işlemlerinde kullanılacak bildirim türü").
      // Üretici sevk `kayitZorunlu=false` gönderdiği için eskiden bu türde hiçbir
      // ön denetim çalışmıyordu; arada GTB'ye kaydolan bir üretici fark edilmeden
      // geçiyordu.
      $__uretSevk = hks_uret_sevk_mi($ortak);
      if (!empty($ortak['ikinciTc']) && ($__uretSevk || !empty($ortak['kayitZorunlu']))) {
        $__kkHam = null;
        $__kk = hks_kayitli_kisi_sorgu($cfg, [$ortak['ikinciTc']], $__kkHam);
        $__k0 = $__kk[0] ?? null;
        $__kayitli = $__k0 && !empty($__k0['kayitliMi']);
        if ($__uretSevk && $__kayitli) {
          hks_json_cikti(['hata' => 'Gönderim DURDURULDU: üretici (' . $ortak['ikinciTc'] .
            ') GTB sisteminde KAYITLI görünüyor. "Üreticiden Sevk Alım" yalnızca kayıtsız ' .
            'üretici içindir; kayıtlı kişiden alım için bildirim türünü "Satın Alım" yapın. ' .
            'Taslak silinmedi.'], 400);
        }
        if (!$__uretSevk && !$__kayitli) {
          hks_json_cikti(['hata' => 'Gönderim DURDURULDU: karşı taraf (' . $ortak['ikinciTc'] .
            ') GTB sisteminde kayıtlı değil. Sevk Etme / Satın Alım için karşı tarafın ' .
            'kayıtlı olması zorunludur. Kayıtsız müstahsilden alım için "Üreticiden Sevk Alım" ' .
            'türünü kullanın. Taslak silinmedi.'], 400);
        }
      }

      $sonuc = hks_bildirim_kaydet($cfg, $satirlar, $ortak);

      // Gönderilenler kaydı (tek satır özet)
      $basarili = array_filter($sonuc['sonuclar'], fn($s) => $s['yeniKunyeNo'] && $s['yeniKunyeNo'] !== '0' && !$s['hataKodu']);

      // KRİTİK: HKS'e TEK KÜNYE bile gitmediyse (tam başarısızlık — ör. sıfat
      // uyumsuzluğu, GTBGLB00000001 vb.) taslak SİLİNMEZ ve "Gönderilenler"e
      // sahte bir kayıt atılmaz (orada hiçbir şey gönderilmedi). Kullanıcı
      // taslağa geri döner, hatalı alanı (ör. karşı taraf sıfatı) "Düzenle" ile
      // değiştirip AYNI taslağı tekrar gönderebilir — formu baştan doldurmaz.
      if (count($basarili) === 0) {
        hks_json_cikti($sonuc + ['taslakKorundu' => true]);
      }

      // En az bir künye gerçekten oluştu → bu GERİ ALINAMAZ, kayıt tutulmalı.
      hks_son_guncelle($ortak, $t['firma_id']);
      $gid = 'g' . round(microtime(true) * 1000);
      $toplamKg = array_sum(array_map(fn($s) => (float)$s['miktar'], $satirlar));
      $rusum = array_sum(array_map(fn($s) => (float)$s['rusum'], $sonuc['sonuclar']));
      $yeniKunyeler = array_values(array_map(fn($s) => $s['yeniKunyeNo'], $basarili));
      $st = $db->prepare('INSERT INTO ' . hks_tablo('gonderilenler') . '
        (id, zaman, firma_id, firma_ad, plaka, belge_no, ulke_ad, urun_ad, adet, toplam_kg, fiyat, rusum, hata_sayisi, genel_hata, veri)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
      $st->execute([$gid, date('Y-m-d H:i:s'), $t['firma_id'], $t['firma_ad'],
        $ortak['plaka'] ?? '', $ortak['belgeNo'] ?? '', $ortak['ulkeAd'] ?? '', $ortak['urunAd'] ?? '',
        count($satirlar), $toplamKg, $ortak['fiyat'] ?? 0, $rusum,
        count($sonuc['sonuclar']) - count($basarili), $sonuc['genelHata'],
        json_encode(['yeniKunyeler' => $yeniKunyeler, 'sonuclar' => $sonuc['sonuclar']], JSON_UNESCAPED_UNICODE)]);

      // Kısmi başarısızlık varsa (bazı satırlar hata verdi) taslak yine silinir
      // çünkü BAŞARILI satırlar zaten geri alınamaz şekilde gönderildi; aynı
      // taslağı tekrar göndermek o satırları MÜKERRER göndermeye çalışırdı.
      // Hangi satırların başarısız olduğu $sonuc.sonuclar içinde döner.
      $db->prepare('DELETE FROM ' . hks_tablo('taslaklar') . ' WHERE id = ?')->execute([$t['id']]);
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
      $cfg = hks_firma_bul($g['firmaId'] ?? '');
      if (!$cfg) hks_json_cikti(['hata' => 'Firma bulunamadı.'], 400);
      $tc = trim($g['tcVkn'] ?? '');
      if ($tc === '') hks_json_cikti(['hata' => 'TC/Vergi No gerekli.'], 400);
      @set_time_limit(60);
      $ham = null;
      $liste = hks_kayitli_kisi_sorgu($cfg, [$tc], $ham);
      $kisi = $liste[0] ?? null;
      $out = ['kisi' => $kisi] + ($kisi ? [] : ['ham' => $ham]);
      if (!empty($g['debug'])) { $out['ns'] = hks_ns_listesi(); $out['hamXml'] = hks_son_ham_gorunur(); }
      hks_json_cikti($out);
    }

    default:
      hks_json_cikti(['hata' => 'Bilinmeyen işlem: ' . $action], 404);
  }
} catch (Throwable $e) {
  hks_json_cikti(['hata' => $e->getMessage()], 500);
}
