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

// Doğrulama (taslak/bildirim ortak)
function hks_bildirim_dogrula($g) {
  if (empty($g['satirlar']) || !is_array($g['satirlar'])) return 'En az bir künye satırı gerekli.';
  if (count($g['satirlar']) > 100) return 'Tek seferde en fazla 100 bildirim.';
  $o = $g['ortak'] ?? [];
  foreach (['fiyat', 'sifatId', 'bildirimTuruId', 'isletmeTuruId', 'ulkeId'] as $alan) {
    if (empty($o[$alan])) return 'Eksik alan: ' . $alan;
  }
  if (empty($o['plaka']) && !(!empty($o['belgeNo']) && !empty($o['belgeTipiId']))) {
    return 'Araç plakası veya belge no + belge tipi girilmelidir.';
  }
  return null;
}

// Son kullanılan plaka/ülke/ürün güncelle
function hks_son_guncelle($ortak) {
  $son = hks_kv_oku('sonlar', ['plakalar' => [], 'ulkeler' => [], 'urunler' => []]);
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
  hks_kv_yaz('sonlar', $son);
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

    // ---- SON KULLANILANLAR ----
    case 'sonlar': {
      hks_json_cikti(hks_kv_oku('sonlar', ['plakalar' => [], 'ulkeler' => [], 'urunler' => []]));
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
      $liste = hks_kunyeleri_getir($cfg, $g);
      hks_json_cikti(['kunyeler' => $liste]);
    }

    // ---- STOK ÖZETİ (referans künye kalanlarının ürün bazında toplamı) ----
    case 'stok': {
      $cfg = hks_firma_bul($g['firmaId'] ?? '');
      if (!$cfg) hks_json_cikti(['hata' => 'Firma bulunamadı. Önce firma seçin.'], 400);
      @set_time_limit(180);  // 12 aylık pencere = 12 ardışık SOAP çağrısı
      $ay = isset($g['aySayisi']) ? max(1, min(24, (int)$g['aySayisi'])) : 12;
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

    // ---- TASLAKLAR ----
    case 'taslaklar': {
      $rows = $db->query('SELECT * FROM ' . hks_tablo('taslaklar') . ' ORDER BY zaman DESC')->fetchAll();
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
      $st = $db->prepare('DELETE FROM ' . hks_tablo('taslaklar') . ' WHERE id = ?');
      $st->execute([$g['id'] ?? '']);
      hks_json_cikti(['tamam' => true]);
    }
    case 'taslak_gonder': {
      $st = $db->prepare('SELECT * FROM ' . hks_tablo('taslaklar') . ' WHERE id = ?');
      $st->execute([$g['id'] ?? '']);
      $t = $st->fetch();
      if (!$t) hks_json_cikti(['hata' => 'Taslak bulunamadı.'], 400);
      $cfg = hks_firma_bul($t['firma_id']);
      if (!$cfg) hks_json_cikti(['hata' => 'Taslağın firması artık kayıtlı değil.'], 400);
      $veri = json_decode($t['veri'], true);
      $satirlar = $veri['satirlar'];
      $ortak = $veri['ortak'];

      $sonuc = hks_bildirim_kaydet($cfg, $satirlar, $ortak);
      hks_son_guncelle($ortak);

      // Gönderilenler kaydı (tek satır özet)
      $basarili = array_filter($sonuc['sonuclar'], fn($s) => $s['yeniKunyeNo'] && $s['yeniKunyeNo'] !== '0' && !$s['hataKodu']);
      $gid = 'g' . round(microtime(true) * 1000);
      $toplamKg = array_sum(array_map(fn($s) => (float)$s['miktar'], $satirlar));
      $rusum = array_sum(array_map(fn($s) => (float)$s['rusum'], $sonuc['sonuclar']));
      $yeniKunyeler = array_values(array_map(fn($s) => $s['yeniKunyeNo'], $basarili));
      $st = $db->prepare('INSERT INTO ' . hks_tablo('gonderilenler') . '
        (id, zaman, firma_ad, plaka, belge_no, ulke_ad, urun_ad, adet, toplam_kg, fiyat, rusum, hata_sayisi, genel_hata, veri)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
      $st->execute([$gid, date('Y-m-d H:i:s'), $t['firma_ad'],
        $ortak['plaka'] ?? '', $ortak['belgeNo'] ?? '', $ortak['ulkeAd'] ?? '', $ortak['urunAd'] ?? '',
        count($satirlar), $toplamKg, $ortak['fiyat'], $rusum,
        count($sonuc['sonuclar']) - count($basarili), $sonuc['genelHata'],
        json_encode(['yeniKunyeler' => $yeniKunyeler, 'sonuclar' => $sonuc['sonuclar']], JSON_UNESCAPED_UNICODE)]);

      // Taslağı sil
      $db->prepare('DELETE FROM ' . hks_tablo('taslaklar') . ' WHERE id = ?')->execute([$t['id']]);
      hks_json_cikti($sonuc);
    }

    // ---- GÖNDERİLENLER ----
    case 'gonderilenler': {
      $rows = $db->query('SELECT * FROM ' . hks_tablo('gonderilenler') . ' ORDER BY zaman DESC LIMIT 500')->fetchAll();
      $liste = array_map(function ($r) {
        $veri = json_decode($r['veri'], true) ?: [];
        return [
          'id' => $r['id'], 'zaman' => (new DateTime($r['zaman']))->format('c'),
          'firmaAd' => $r['firma_ad'], 'plaka' => $r['plaka'], 'belgeNo' => $r['belge_no'],
          'ulkeAd' => $r['ulke_ad'], 'urunAd' => $r['urun_ad'], 'adet' => (int)$r['adet'],
          'toplamKg' => (float)$r['toplam_kg'], 'fiyat' => (float)$r['fiyat'], 'rusum' => (float)$r['rusum'],
          'hataSayisi' => (int)$r['hata_sayisi'], 'genelHata' => $r['genel_hata'],
          'yeniKunyeler' => $veri['yeniKunyeler'] ?? [],
        ];
      }, $rows);
      hks_json_cikti(['gonderilenler' => $liste]);
    }

    default:
      hks_json_cikti(['hata' => 'Bilinmeyen işlem: ' . $action], 404);
  }
} catch (Throwable $e) {
  hks_json_cikti(['hata' => $e->getMessage()], 500);
}
