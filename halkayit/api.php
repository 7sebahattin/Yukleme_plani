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

// --- Ortak yardımcılar (firma bulma + bildirim doğrulama + taslak yazma) ---
// Bu fonksiyonlar halkayit/taslak_lib.php'ye TAŞINDI; Beyan ekranındaki
// "Bildirim Yap" akışı da aynı doğrulamayı kullansın diye. Davranış aynıdır.
require_once __DIR__ . '/taslak_lib.php';
// "Tekrar gönder": gönderimde taslağın beyaz listeli kopyası + Gönderilenler'den
// forma dolu tohum (salt okunur; taslak yazmanın tek yolu yine hks_taslak_olustur).
require_once __DIR__ . '/tekrar_gonder_lib.php';
// Referans künye SEÇİM kuralları (fiyat yakınlığı → eski tarih → küçük kalan) +
// künye/sıfat uyumsuzluğu kaydı. Gönderim yolu değişmez (hks_bildirim_kaydet tek yol).
require_once __DIR__ . '/kunye_secim_lib.php';
// Firma ekle/düzenle/sil: DB + audit mantığı (yetki/CSRF kapısı aşağıda, burada).
require_once __DIR__ . '/firma_yonetim_lib.php';


// =============================================================================
// PLAN TASLAĞI — künyeleri GÖNDERİM ANINDA canlı stoktan çöz
// =============================================================================
// Kullanıcı taslağı künye seçmeden, yalnız "toplam kilo + birim fiyat" ile
// kaydeder. Gönder'e basıldığında künyeler HKS'ten TAZE çekilir ve hedef kiloya
// ulaşana kadar greedy dağıtılır (her künyeden min(kalan, kalanHedef)).
//
// SEÇİM SIRASI (kullanıcı kararı — halkayit/kunye_secim_lib.php):
//   satış fiyatı girildiyse alış fiyatı ona EN YAKIN → EN ESKİ tarih → EN KÜÇÜK
//   kalan → künye no. Bu taslağın bildirimci sıfatıyla (ortak.sifatId) daha önce
//   uyumsuz çıkmış künyeler (firma bazlı kayıt, 30 gün) DENENMEZ.
// Alış fiyatları hks_kunye_detaylari_getir() ile BEST-EFFORT alınır: hata ya da
// kısmi hata → eksik fiyatlı künyeler fiyatsız sayılır (yalnız bellek, kv yok).
//
// Künyeler kaydetme anında DEĞİL gönderim anında çözülür; böylece aradaki
// sürede stok değişse bile bildirim her zaman O ANKİ gerçek kalanlarla gider.
//
// Dönüş: ['satirlar' => [...]] veya ['hata' => '...'] (taslak KORUNUR); ikisine
// de 'atlananSifatUyumsuz' => [kunyeNo...] ve 'fiyatSiralamaKullanildi' => bool eklenir.
function hks_plan_kunye_coz($cfg, $ortak, $firmaId = '') {
  $dislanan = [];
  try {
    $dislanan = hks_kunye_dislanan_set(hks_kunye_sifat_uyumsuz_oku($firmaId), $ortak['sifatId'] ?? 0);
  } catch (Throwable $e) {
    error_log('[hks] künye sıfat kaydı okunamadı: ' . $e->getMessage());   // dışlamasız devam
  }
  // Depo/Tasnif kaynak kuralı (v366): kaynağı başka kişi olan künyeler denenmez;
  // detay haritası fiyatsız sevkte de istenir (BildirimciTcKimlikVergiNo).
  $kaynak = null;
  if (hks_kaynak_sevk_mi($ortak)) {
    $ogr = [];
    try { $ogr = hks_kunye_kaynak_kokenler(hks_kunye_kaynak_oku($firmaId)); }
    catch (Throwable $e) { error_log('[hks] künye kaynak kaydı okunamadı: ' . $e->getMessage()); }
    $kaynak = ['tc' => hks_tc_normalize($ortak['ikinciTc'] ?? ''), 'kendi' => (string)($cfg['vergiNo'] ?? ''), 'ogrenilen' => $ogr];
  }
  return hks_plan_kunye_coz_cekirdek(
    (array)$ortak,
    fn(array $secenek) => hks_kunyeleri_getir($cfg, $secenek),
    fn(int $ay) => hks_kunye_detaylari_getir($cfg, $ay),
    $dislanan,
    $kaynak
  );
}

// "Son kullanılanlar" anahtarı FİRMA BAZLIDIR — firmalar arasında veri karışmaz.
function hks_sonlar_key($firmaId) {
  $firmaId = trim((string)$firmaId);
  return $firmaId !== '' ? ('sonlar_' . $firmaId) : 'sonlar';
}

// Son kullanılan KARŞI TARAF listesini güncelle — SAF fonksiyon (DB'ye dokunmaz).
// NOT: Kişi Havuzu'ndan (kisi_havuzu_lib.php) beri üretim akışı bunu ÇAĞIRMAZ;
// scripts/hks_uretici_sevk_test.php'deki senkron kopya bozulmasın diye duruyor.
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

// Son kullanılan plaka/ülke/ürün güncelle (yalnızca ilgili firma için).
// KARŞI TARAF artık burada TUTULMAZ: global Kişi Havuzu'na (hks_kisiler) yazılır —
// bkz. hks_kisi_havuzuna_isle(). Eski 'karsiTaraflar' anahtarı kv'de olduğu gibi
// kalır (tek seferlik içe aktarmanın kaynağı; silinmez, artık güncellenmez).
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

// Gönderim sonrası karşı tarafı Kişi Havuzu'na işle (tc eşleşmesi; boş gelen
// ad/cep/doğum eskiyi silmez; kullanım sayacı artar). Hata YUTULUR: buraya
// gelindiyse HKS'te künye GERİ ALINAMAZ şekilde oluştu — havuz sorunu yüzünden
// kullanıcıya hata dönmek (ve onu tekrar göndermeye itmek) mükerrer bildirim
// riskidir.
// Sıfat: ortak.ikinciSifatId yazılır (son kullanılan kazanır) — Üreticiden Sevk
// Alım'da YAZILMAZ (orada sıfat sabit "Üretici"; kişinin kendi sıfatı değil).
// Karar hks_uret_sevk_mi() (taslak_lib.php) ile BURADA verilir; lib onu require etmez.
function hks_kisi_havuzuna_isle(PDO $db, $ortak, $kullaniciId) {
  if (empty($ortak['ikinciTc'])) return;
  try {
    hks_kisi_upsert($db, hks_kisi_ortaktan((array)$ortak, hks_uret_sevk_mi($ortak)), $kullaniciId);
  } catch (Throwable $e) {
    error_log('[hks] kişi havuzu upsert hatası: ' . $e->getMessage());
  }
}

// CSRF token'ı: önce X-CSRF-Token başlığı, yoksa JSON gövdedeki 'csrf' alanı
// (sözleşme sırası). BOŞ başlık "yok" sayılır — meta yer tutucusu boş token
// üretirse gövdedeki geçerli token yine okunabilsin. Bazı proxy/sunucu
// yapılandırmaları özel başlığı düşürebildiği için gövde yedeği kisi_kaydet /
// kisi_sil'de istemci tarafından da gönderilir. csrf_check() paylaşılan
// fonksiyondur (config/helpers.php) — burada yalnız girdisi seçilir.
// Firma yönetimi (HKS kullanıcı adı/şifre/web servis şifresi dahil) YALNIZ yönetici:
// is_admin() ('admin' slug'ı — dogum_deney.php / tani.php ile aynı kapı; özel role
// devredilemez, bilinçli) + CSRF. 403 gövdesinde `code` alanı YOK: app.html api() yalnız
// code===403 görünce CSRF tazeleyip tekrar dener, yetki hatasında gereksiz istek olmasın.
function hks_firma_yonetici_kapisi(array $g): void {
  if (!(function_exists('is_admin') && is_admin())) {
    hks_json_cikti(['hata' => 'Firma yönetimi yalnız yöneticiye açıktır.'], 403);
  }
  csrf_check(hks_csrf_girdi($g));
}

function hks_csrf_girdi(array $g): ?string {
  $baslik = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
  if (is_string($baslik) && $baslik !== '') return $baslik;
  $govde = $g['csrf'] ?? null;
  return is_string($govde) && $govde !== '' ? $govde : null;
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
      // `yonetici`: arayüz ekle/düzenle/sil düğmelerini gösterir. Asıl koruma firma_kaydet/firma_sil'de.
      hks_json_cikti(['firmalar' => $firmalar, 'yonetici' => (function_exists('is_admin') && is_admin())]);
    }

    case 'firma_kaydet': {
      hks_firma_yonetici_kapisi($g);
      [$kod, $yanit] = hks_firma_kaydet_uygula($db, hks_tablo('firmalar'), $g, 'hks_firma_bul', 'hks_sifrele',
        fn($a, $o, $n) => audit_log_event($a, 'hks_firma', null, $o, $n));
      hks_json_cikti($yanit, $kod);
    }

    case 'firma_sil': {
      hks_firma_yonetici_kapisi($g);
      [$kod, $yanit] = hks_firma_sil_uygula($db, hks_tablo('firmalar'), $g, 'hks_firma_bul',
        fn($a, $o, $n) => audit_log_event($a, 'hks_firma', null, $o, $n));
      hks_json_cikti($yanit, $kod);
    }

    // ---- SON KULLANILANLAR (firma bazlı) ----
    case 'sonlar': {
      $sonKey = hks_sonlar_key($g['firmaId'] ?? '');
      $varsayilan = ['plakalar' => [], 'ulkeler' => [], 'urunler' => [], 'karsiTaraflar' => []];
      // Eski kayıtlarda 'karsiTaraflar' yok — arayüz her zaman dizi görsün.
      hks_json_cikti(array_merge($varsayilan, (array)hks_kv_oku($sonKey, $varsayilan)));
    }

    // ---- KİŞİ HAVUZU (karşı taraf — GLOBAL, firma bazlı değil) ----
    // Okuma CSRF istemez (veri değiştirmez — tek seferlik içe aktarma dışında,
    // o da idempotenttir ve yalnız eski veriyi kopyalar).
    case 'kisiler': {
      try { hks_kisi_havuzu_ice_aktar($db); }
      catch (Throwable $e) { error_log('[hks] kişi havuzu içe aktarma hatası: ' . $e->getMessage()); }
      hks_json_cikti(['kisiler' => hks_kisi_liste($db)]);
    }

    // ---- CSRF token tazeleme (okuma ucu — CSRF İSTEMEZ) ----
    // Oturum (asya_session çerezi, DB) ile CSRF token'ı ($_SESSION, PHPSESSID)
    // AYRI yaşar: PHP oturumu çöp toplamayla (varsayılan 24 dk boşta) ya da
    // tarayıcı kapanınca düşer, giriş ise sürer → uzun açık kalan SPA'nın meta
    // token'ı bayatlar ve yazma 403 "Güvenlik doğrulaması başarısız" alır.
    // İstemci 403'te buradan taze token alıp isteği BİR KEZ tekrarlar; meta boş
    // ya da '__CSRF_TOKEN__' yer tutucusu kaldıysa (opcache eski app.php) ilk
    // yazmadan önce de çağırır. Yanıtı yalnız aynı köken okuyabilir (CORS yok),
    // dosya başındaki oturum + records.write kapısının arkasındadır.
    case 'csrf': {
      header('Cache-Control: no-store, private');
      hks_json_cikti(['csrf' => csrf_token()]);
    }

    case 'kisi_kaydet': {
      // Yeni yazma uçları CSRF ister (JSON-aware: 403 + JSON). Token başlıkta
      // (X-CSRF-Token), yoksa gövdede ('csrf') — bkz. hks_csrf_girdi().
      csrf_check(hks_csrf_girdi($g));
      $sonuc = hks_kisi_kaydet($db, $g, isset($__hks_user['id']) ? (int)$__hks_user['id'] : null);
      if ($sonuc['kod'] !== 200) hks_json_cikti(['hata' => $sonuc['hata']], $sonuc['kod']);
      audit_log_event($sonuc['islem'], 'hks_kisi', (int)$sonuc['satir']['id'],
        $sonuc['eski'] ? hks_kisi_audit_degerleri($sonuc['eski']) : null,
        hks_kisi_audit_degerleri($sonuc['satir'], $sonuc['eski']));
      hks_json_cikti(['tamam' => true, 'kisi' => $sonuc['kisi']]);
    }

    case 'kisi_sil': {
      csrf_check(hks_csrf_girdi($g));
      $sonuc = hks_kisi_sil($db, (int)($g['id'] ?? 0));
      if ($sonuc['kod'] !== 200) hks_json_cikti(['hata' => $sonuc['hata']], $sonuc['kod']);
      audit_log_event('delete', 'hks_kisi', (int)$sonuc['eski']['id'], hks_kisi_audit_degerleri($sonuc['eski']), null);
      hks_json_cikti(['tamam' => true]);
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
      // Opsiyonel 'sifatId': bu sıfatla uyumsuz kayıtlı künyelere sifatUyumsuz=true
      // (yalnız bilgi — liste süzülmez). sifatId yoksa alan HİÇ eklenmez.
      if (isset($g['sifatId']) && (int)$g['sifatId'] > 0) {
        try { $__sm = hks_kunye_sifat_uyumsuz_oku($g['firmaId'] ?? ''); }
        catch (Throwable $e) { $__sm = []; error_log('[hks] künye sıfat kaydı okunamadı: ' . $e->getMessage()); }
        $liste = hks_kunyelere_sifat_bayragi($liste, $__sm, $g['sifatId']);
      }
      // Depo/Tasnif kaynak kuralı (v366): HKS'in 108 ile reddettiği karşı taraf
      // TC/sıfatları öğrenildiyse künyeye kaynakTc / kaynakRedSifatlar / kaynakRed
      // eklenir (yalnız bilgi — arayüz karşı taraf sıfatını buna göre ayarlar).
      try { $liste = hks_kunyelere_kaynak_bilgisi($liste, hks_kunye_kaynak_oku($g['firmaId'] ?? '')); }
      catch (Throwable $e) { error_log('[hks] künye kaynak kaydı okunamadı: ' . $e->getMessage()); }
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
      // Gönderilenler listesiyle AYNI: ülke metnindeki çıplak TC/VKN yerine havuzdaki
      // ad gösterilir. YALNIZ yanıt kopyası — DB'deki taslak, düzenleme ve gönderim
      // (taslak_gonder DB'den okur) bu çıktıyı kullanmaz.
      $__adlar = hks_gonderilen_adlari($db, array_map(
        fn($r) => (json_decode($r['veri'], true)['ortak']['ulkeAd'] ?? ''), $rows));
      // v416: bugün aynı plakaya (plaka yoksa belge no'ya) yapılmış gönderim sayısı —
      // kartta uyarı için (salt okunur; aynı plaka bir kez sorgulanır, hata yutulur → 0).
      $__plakaSay = [];
      $__ayniPlaka = function (array $o, string $firmaAd) use ($db, $fid, &$__plakaSay): int {
        $k = hks_plaka_norm($o['plaka'] ?? '') . '|' . trim((string)($o['belgeNo'] ?? ''));
        if ($k === '|') return 0;
        if (!array_key_exists($k, $__plakaSay)) {
          try {
            $__plakaSay[$k] = count(hks_bugun_ayni_plaka($db, hks_tablo('gonderilenler'), $fid, $firmaAd,
              (string)($o['plaka'] ?? ''), (string)($o['belgeNo'] ?? '')));
          } catch (Throwable $e) { $__plakaSay[$k] = 0; }
        }
        return $__plakaSay[$k];
      };
      $taslaklar = array_map(function ($r) use ($__adlar, $__ayniPlaka) {
        $veri = json_decode($r['veri'], true);
        $__plakaBugun = $__ayniPlaka((array)($veri['ortak'] ?? []), (string)$r['firma_ad']);
        if (isset($veri['ortak']['ulkeAd'])) {
          $veri['ortak']['ulkeAd'] = hks_gonderilen_ulke_isimle($veri['ortak']['ulkeAd'], $__adlar);
        }
        return [
          'id' => $r['id'], 'zaman' => (new DateTime($r['zaman']))->format('c'),
          'firmaId' => $r['firma_id'], 'firmaAd' => $r['firma_ad'],
          'satirlar' => $veri['satirlar'], 'ortak' => $veri['ortak'],
          'ayniPlakaBugun' => $__plakaBugun,
        ];
      }, $rows);
      hks_json_cikti(['taslaklar' => $taslaklar]);
    }
    case 'taslak_kaydet': {
      // Yazma ve doğrulama taslak_lib.php'de; Beyan ekranındaki "Bildirim Yap"
      // akışı da AYNI fonksiyonu çağırır (kural ayrışmasın diye).
      //
      // DÜZENLEME (`eskiTaslakId`): SPA yeni taslağı kaydedip eskisini siler.
      // Eski taslağın beyan izi (`ortak.kaynak`) formda yoktur → buradan yeni
      // taslağa aktarılır ve beyan bağı yeni id'ye taşınır. Aksi hâlde silme
      // bağı kapatıyor, düzenlenip gönderilen taslak beyana hiç yansımıyordu.
      $eskiId = trim((string)($g['eskiTaslakId'] ?? ''));
      unset($g['eskiTaslakId']);
      if ($eskiId !== '' && is_array($g['ortak'] ?? null) && empty($g['ortak']['kaynak'])) {
        $es = $db->prepare('SELECT veri FROM ' . hks_tablo('taslaklar') . ' WHERE id = ? AND firma_id = ?');
        $es->execute([$eskiId, trim((string)($g['firmaId'] ?? ''))]);
        $eskiVeri = json_decode((string)$es->fetchColumn(), true);
        if (!empty($eskiVeri['ortak']['kaynak'])) $g['ortak']['kaynak'] = $eskiVeri['ortak']['kaynak'];
      }
      $sonuc = hks_taslak_olustur($g);
      if (!empty($sonuc['hata'])) hks_json_cikti(['hata' => $sonuc['hata']], $sonuc['kod'] ?? 400);
      if ($eskiId !== '' && function_exists('beyan_hks_taslak_tasi')) {
        beyan_hks_taslak_tasi($eskiId, (string)$sonuc['id']);
      }
      hks_json_cikti(['tamam' => true, 'id' => $sonuc['id']]);
    }
    case 'taslak_sil': {
      // Yalnızca seçili firmanın taslağı silinebilir (firma-izolasyonu).
      $fid = trim($g['firmaId'] ?? '');
      $st = $db->prepare('DELETE FROM ' . hks_tablo('taslaklar') . ' WHERE id = ? AND firma_id = ?');
      $st->execute([$g['id'] ?? '', $fid]);
      // BEYAN KÖPRÜSÜ: taslak gerçekten silindiyse bağı 'silindi'ye çek.
      // 'silindi' AKTİF sayılır (beyan_hks_aktif_durumlar) — taslağa atılan
      // beyan bildirilmiş kabul edilir, silme beyanı KENDİLİĞİNDEN yeniden
      // açmaz. Yeniden açmak: beyan_edit.php → "Tekrar Aktif Et".
      // Düzenlemede bağ önce yeni taslağa taşındığı için (taslak_kaydet →
      // beyan_hks_taslak_tasi) buradaki arama eşleşme bulmaz, dokunmaz.
      // Yalnız SİLME GERÇEKLEŞTİYSE yazılır (rowCount=0 → başka firmanın).
      if ($st->rowCount() > 0 && function_exists('beyan_hks_taslak_isaretle')) {
        beyan_hks_taslak_isaretle((string)($g['id'] ?? ''), 'silindi',
          ['hata_metni' => 'Taslak Hal Kayıt ekranından silindi.']);
      }
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
      // "Tekrar gönder" kopyası — plan çözümünden ve gidecekAdres eklenmesinden
      // ÖNCE: kullanıcının kaydettiği taslağın kendisi saklanır (plan taslağı plan
      // olarak kalır). Kişisel veri ve beyan bağı (kaynak) beyaz listede YOK.
      // Hata yutan saf fonksiyondur; null dönerse gönderim yine aynen sürer.
      $__kopya = hks_gonderim_kopyasi(is_array($veri) ? $veri : []);
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
        $__plan = hks_plan_kunye_coz($cfg, $ortak, $t['firma_id']);
        if (!empty($__plan['hata'])) {
          $taslagiGeriKoy();
          hks_json_cikti(['hata' => $__plan['hata'], 'taslakKorundu' => true], 400);
        }
        $satirlar = $__plan['satirlar'];
      }
      // Künye KAYNAKLARI (Depo/Tasnif kaynak kuralı, v366) — {künyeNorm: kaynakTc}.
      $__kokenHarita = $planMi ? (array)($__plan['kokenHarita'] ?? []) : [];

      // GÖNDERİM ÖNCESİ KURAL BÜTÜNLÜĞÜ (P1) — taslak kaydedildiğinde geçerliydi;
      // gönderim ANINDA da geçerli olduğu TEKRAR doğrulanır (referans künye,
      // zorunlu alanlar, vb. — hks_bildirim_dogrula() taslak_kaydet ile AYNI
      // fonksiyon). Eskiden bu adım yalnız taslak_kaydet'te çalışıyordu; DB'de
      // duran eski/bozuk bir taslak doğrudan hks_bildirim_kaydet()'e (CANLI,
      // geri alınamaz) gidebiliyordu.
      // ÜRETİCİ SIFATI + CEP YOK (eski taslak / kayıtlı kişi): cep Kişi Havuzu'ndan
      // TC ile tamamlanır — HKS bunsuz HataKodu 263 ile reddediyor. Havuzda da yoksa
      // aşağıdaki doğrulama gönderimi durdurur (taslak korunur).
      if (hks_uretici_cep_gerekli($ortak) && hks_cep_rakam($ortak['ikinciCep'] ?? '') === '') {
        try {
          $__k = hks_kisi_tc_ile($db, hks_tc_normalize($ortak['ikinciTc'] ?? ''));
          if ($__k && hks_cep_rakam($__k['cep'] ?? '') !== '') $ortak['ikinciCep'] = hks_cep_rakam($__k['cep']);
        } catch (Throwable $e) { /* havuz okunamadı — doğrulama durdurur */ }
      }
      $__revalHata = hks_bildirim_dogrula(['satirlar' => $satirlar, 'ortak' => $ortak]);
      if ($__revalHata) {
        $taslagiGeriKoy();
        hks_json_cikti(['hata' => 'Taslak artık geçerli kurallara uymuyor: ' . $__revalHata .
          ' Taslak silinmedi — "Düzenle" ile güncelleyip tekrar deneyin.'], 400);
      }

      // DEPO/TASNİF KAYNAK KURALI (v366) — HKS bu künyeyi bu karşı taraf TC +
      // sıfatıyla daha önce 108 ile reddettiyse aynı üçlü yine reddedilir:
      // HİÇBİR ŞEY gönderilmez, taslak geri konur. Kayıt okunamazsa engel yok.
      if (hks_kaynak_sevk_mi($ortak)) {
        // Elle seçilmiş künyeler: kaynakları (BildirimSorgu → BildirimciTcKimlikVergiNo)
        // gönderimden ÖNCE best-effort sorgulanır (salt okunur); öğrenilmiş kaynak da
        // eklenir. Kaynağı KESİN bilinip karşı taraftan farklı olan künye GÖNDERİLMEZ.
        if (!$planMi) {
          @set_time_limit(300);
          $__detayHam = [];
          try {
            $__ay = max(1, min(60, (int)($ortak['planSorgu']['aySayisi'] ?? 12)));
            $__detayHam = (array)(hks_kunye_detaylari_getir($cfg, $__ay)['harita'] ?? []);
          } catch (Throwable $e) { error_log('[hks] künye detayı alınamadı: ' . $e->getMessage()); }
          $__ogr = [];
          try { $__ogr = hks_kunye_kaynak_kokenler(hks_kunye_kaynak_oku($t['firma_id'])); }
          catch (Throwable $e) { /* öğrenilmiş kaynak yok sayılır */ }
          $__kokenHarita = hks_kunye_koken_haritasi($__detayHam, $cfg['vergiNo'] ?? '', $__ogr);
        }
        $__tcEngel = hks_kaynak_tc_engeli((array)$satirlar, $__kokenHarita, $ortak['ikinciTc'] ?? '', $cfg['vergiNo'] ?? '');
        if ($__tcEngel) {
          $taslagiGeriKoy();
          hks_json_cikti(['hata' => 'Depo/Tasnif sevkinde yalnız künyeyi size gönderen kişiye (ve o bildirimdeki ' .
            'sıfatına) geri sevk yapılabilir. ' . implode('; ', array_map(fn($x) => 'Künye ' . $x['kunyeNo'] .
            (!empty($x['kendi']) ? "'in bildirimini kendi firmanız yapmış (ör. alım)"
              : ' size TC ' . hks_tc_maske($x['kaynakTc']) . ' tarafından gönderilmiş'), $__tcEngel)) .
            ' — karşı taraf (' . hks_tc_maske($ortak['ikinciTc'] ?? '') . ') farklı. Bu künyeleri çıkarın ya da ' .
            'karşı tarafı değiştirin. Bildirim gönderilmedi, taslak silinmedi.',
            'taslakKorundu' => true, 'kaynakTcEngel' => array_map(fn($x) => ['kunyeNo' => $x['kunyeNo'],
              'kaynakTc' => hks_tc_maske($x['kaynakTc'])], $__tcEngel)], 400);
        }
        $__kaynakEngel = [];
        try {
          $__kaynakEngel = hks_kaynak_red_engeli((array)$satirlar, hks_kunye_kaynak_oku($t['firma_id']),
            $ortak['ikinciTc'] ?? '', $ortak['ikinciSifatId'] ?? 0);
        } catch (Throwable $e) { error_log('[hks] künye kaynak kaydı okunamadı: ' . $e->getMessage()); }
        if ($__kaynakEngel) {
          $taslagiGeriKoy();
          hks_json_cikti(['hata' => 'Bu künye (' . implode(', ', $__kaynakEngel) . ') daha önce bu karşı taraf ' .
            'sıfatıyla reddedildi; Depo/Tasnif sevkinde karşı taraf künyeyi size gönderen kişi ve onun sıfatı ' .
            'olmalı. Karşı taraf sıfatını (veya kişiyi) değiştirip tekrar deneyin. Bildirim gönderilmedi, ' .
            'taslak silinmedi.', 'taslakKorundu' => true, 'kaynakEngel' => $__kaynakEngel], 400);
        }
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
      // doğrulanıyor). Frontend bu türde `kayitZorunlu=false` gönderir, dolayısıyla
      // bu AYNA sorgusu çalışmaz; kayıtsız Satın Alım'ın kendi (engellemeyen)
      // durum sorgusu aşağıda, gönderimden hemen önce yapılır.
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
      // ── KAYITSIZ KİŞİLİ SATIN ALIM: hazırlık (docs/HKS_MERNIS_ILK_KAYIT_ANALIZ.md §10)
      // Doğum tarihi gönderilen, işyeri hedefli, referanssız yurt içi bildirim
      // (= kayıtsız satıcıdan Satın Alım). Gönderimden hemen önce kişinin kayıt
      // durumu sorulur — GÖNDERİMİ ENGELLEMEZ (Satın Alım kayıtlı kişi de kabul
      // eder), yalnız şunları belirler:
      //   • KAYITSIZ ise gidecek yer İl/İlçe/Belde işyerinin kaydından eklenir
      //     (kılavuz 1189-1193; GTB'nin resmi 195 örneği de böyle).
      //   • KAYITSIZ ise yöneticinin bu TC için kurduğu tek kullanımlık doğum
      //     tarihi biçim deneyi uygulanır ve TÜKETİLİR.
      //   • Künye KAYITSIZ kişiyle üretilirse kullanılan biçim öğrenilebilir.
      // Deney kuruluysa ama kişi KAYITSIZ doğrulanamazsa gönderim DURUR: sonuç
      // yorumlanamaz (kayıtlı kişide KPS hiç sorulmaz).
      $__secenek = [];
      $__dogumKaydi = null;
      $__satinKayitsiz = !empty($ortak['yurtIci']) && !empty($ortak['referanssiz'])
        && !$__uretSevk && empty($ortak['hedefAdres']) && !empty($ortak['gidecekIsyeriId'])
        && !empty($ortak['ikinciTc'])
        && hks_dogum_tarihi_xml($ortak['ikinciDogumTarihi'] ?? '') !== '';
      if ($__satinKayitsiz) {
        // Taslak zaten sahiplenildi (silindi): aşağıdaki en çok 4 salt-okunur
        // SOAP çağrısı PHP süre sınırına takılırsa taslak geri konamadan süreç
        // ölür. Sınır baştan genişletilir (her çağrı kendi 60 sn timeout'unda).
        @set_time_limit(300);
        $__kdHam = null; $__kdDetay = null;
        $__kd = hks_kayit_durumu($cfg, $ortak['ikinciTc'], $__kdHam, $__kdDetay);
        $__deneyBicim = hks_dogum_deney_bu_tc($ortak['ikinciTc']);
        if ($__deneyBicim !== null && $__kd !== HKS_DURUM_NOT_REGISTERED) {
          $taslagiGeriKoy();
          hks_json_cikti(['hata' => 'Doğum tarihi deneyi bu kişi için kurulu, ancak kişi şu an HKS\'te ' .
            ($__kd === HKS_DURUM_REGISTERED ? 'KAYITLI görünüyor' : 'kayıt durumu doğrulanamadı') .
            '. Kayıtlı kişide Mernis sorgusu yapılmadığı için deney sonucu yorumlanamaz — bildirim ' .
            'gönderilmedi, taslak silinmedi. Deneyi "Doğum Tarihi Deneyi" ekranından iptal edip ' .
            'normal gönderin ya da sitede hiç sorgulanmamış başka bir kişiyle deneyin.',
            'taslakKorundu' => true], 409);
        }
        $__adres = null;
        if ($__kd === HKS_DURUM_NOT_REGISTERED) {
          $__secenek['kayitsizDogrulandi'] = true;
          $__adres = hks_isyeri_adres_bul($cfg, $ortak['gidecekIsyeriId'], $cfg['vergiNo'] ?? '');
          if ($__adres) $ortak['gidecekAdres'] = $__adres;
          if ($__deneyBicim !== null) {
            $__secenek['bicim'] = $__deneyBicim;
            hks_dogum_deney_iptal();   // tek kullanımlık — gönderimden ÖNCE tüketilir
          }
        }
        $__dogumKaydi = [
          'firmaId' => (string)$t['firma_id'],
          'tc' => hks_tc_son4($ortak['ikinciTc']),
          'kayit' => $__kd,
          'deney' => isset($__secenek['bicim']),
          'adres' => (bool)$__adres,
          'dogumSinifi' => hks_dogum_sinifi($ortak['ikinciDogumTarihi'] ?? ''),
        ];
      }

      // GÖNDERİM AUDIT'İ (v417): niyet kaydı — süreç fatal/zaman aşımıyla ölürse
      // 'basla' var 'sonuc' yok = belirsiz. Kullanıcıyı audit_log_event ekler;
      // kişisel veri YOK (bkz. hks_gonderim_audit_payload). Hata yutulur.
      $__actx = [];
      try {
        $__aref = bin2hex(random_bytes(8));
        $__actx = hks_gonderim_audit_ctx($t, $ortak, (array)$satirlar, $planMi, $__aref, hks_bildirim_turu_kodu($ortak));
      } catch (Throwable $__ae) { error_log('[hks_gonderim_audit] ctx ' . get_class($__ae)); }
      hks_gonderim_audit_yaz('hks_gonderim_basla', 'basla', $__actx);

      try {
        $sonuc = hks_bildirim_kaydet($cfg, $satirlar, $ortak, $__secenek);
        // HKS'e GERÇEKTEN giden ikinci kişi kimliği — hata ekranında gösterilir.
        // "Mernis'te bulunamadı" hatasında operatörün karşılaştırabilmesi için
        // TC ile DOĞUM TARİHİ birlikte görünmelidir (KPS ikisini birlikte doğrular);
        // eskiden yalnız HKS'in mesajındaki TC görünüyor, doğum tarihi görünmüyordu.
        $sonuc['gonderilenKimlik'] = [
          'tc'    => hks_tc_normalize($ortak['ikinciTc'] ?? ''),
          'ad'    => (string)($ortak['ikinciAd'] ?? ''),
          'cep'   => hks_cep_rakam($ortak['ikinciCep'] ?? ''),
          'dogum' => (string)($ortak['ikinciDogumTarihi'] ?? ''),
          'dogumGonderildi' => hks_dogum_tarihi_xml($ortak['ikinciDogumTarihi'] ?? '') !== '',
          'dogumTel' => (string)($sonuc['dogumTel'] ?? ''),
          'gidecekAdresEklendi' => !empty($ortak['gidecekAdres']),
        ];
        $sonuc['kayitsizSatinAlim'] = $__dogumKaydi !== null;
        if ($__dogumKaydi !== null) {
          $sonuc['kayitDurumuOnce'] = $__dogumKaydi['kayit'];
          hks_dogum_deneme_kaydet($__dogumKaydi + [
            'bicim' => (string)($sonuc['dogumVaryant']['bicim'] ?? ''),
            'sonuc' => hks_dogum_sonuc_sinifi($sonuc),
            'hataKodu' => (int)($sonuc['sonuclar'][0]['hataKodu'] ?? 0),
            'ogrenildi' => !empty($sonuc['dogumDeneyGecti']),
          ]);
        }
      } catch (Throwable $__e) {
        $taslagiGeriKoy();
        error_log('[hks] bildirim_kaydet istisna: ' . $__e->getMessage());
        // Belirsiz sonuç: yalnız istisna SINIFI (mesaj kişisel veri taşıyabilir).
        hks_gonderim_audit_yaz('hks_gonderim_sonuc', 'belirsiz', $__actx + ['exception_sinifi' => get_class($__e)]);
        if ($__dogumKaydi !== null) {
          hks_dogum_deneme_kaydet($__dogumKaydi + ['bicim' => hks_dogum_varyant_coz(isset($__secenek['bicim']) ? ['bicim' => $__secenek['bicim']] : null)['bicim'],
            'sonuc' => 'belirsiz', 'hataKodu' => 0, 'ogrenildi' => false]);
        }
        hks_json_cikti([
          'hata' => 'Bildirim gönderilirken bağlantı kesildi: ' . $__e->getMessage() .
            ' — HKS\'in bildirimi ALIP ALMADIĞI BİLİNMİYOR. Taslak silinmedi. ' .
            'TEKRAR GÖNDERMEDEN ÖNCE hks.hal.gov.tr üzerinden veya "Bildirim Sorgulama" ' .
            'ekranından künyenin oluşup oluşmadığını KONTROL EDİN; oluştuysa taslağı silin.',
          'taslakKorundu' => true,
        ], 502);
      }

      // KÜNYE / SIFAT UYUMSUZLUĞU KAYDI — başarı/başarısızlık fark etmez, tam
      // başarısızlıkta taslak geri konmadan ÖNCE. Yalnız künyeye ÖZGÜ sıfat
      // hataları (bkz. hks_kunye_sifat_hatali_satirlar) firma bazlı kaydedilir;
      // sonraki otomatik seçim o künyeyi bu sıfatla denemez. Yeniden deneme /
      // ikinci gönderim YOK. Referanssız bildirimde künye yoktur. Hata YUTULUR.
      $sonuc['sifatUyumsuzKunyeler'] = [];
      if (empty($ortak['referanssiz'])) {
        try {
          $__sk = hks_kunye_sifat_hatali_satirlar((array)($sonuc['sonuclar'] ?? []), (array)$satirlar);
          if ($__sk) {
            $sonuc['sifatUyumsuzKunyeler'] = hks_kunye_sifat_uyumsuz_ekle($t['firma_id'], $__sk, $ortak['sifatId'] ?? 0);
          }
        } catch (Throwable $e) {
          error_log('[hks] künye sıfat kaydı yazılamadı: ' . $e->getMessage());
        }
      }
      // DEPO/TASNİF KAYNAK KURALI (v366): HataKodu 108 alan künyeler için "bu TC +
      // bu sıfat kaynak değil" öğrenilir (60 gün). Yeniden deneme YOK. Hata yutulur.
      $sonuc['kaynakHatalari'] = [];
      if (empty($ortak['referanssiz'])) {
        try {
          $__kh = hks_kaynak_hatali_kunyeler((array)($sonuc['sonuclar'] ?? []), (array)$satirlar);
          if ($__kh) {
            $__ktc = hks_tc_normalize($ortak['ikinciTc'] ?? '');
            $__ksf = (int)($ortak['ikinciSifatId'] ?? 0);
            // Künyenin kaynağı biliniyorsa (detay haritası) o da öğrenilir.
            $__kk = [];
            foreach ($__kh as $__kn) {
              $__n = hks_kunye_no_norm($__kn);
              if (isset($__kokenHarita[$__n])) $__kk[$__kn] = $__kokenHarita[$__n];
            }
            hks_kunye_kaynak_ekle($t['firma_id'], $__kh, $__ktc, $__ksf, $__kk);
            foreach ($__kh as $__kn) {
              $__koken = (string)($__kk[$__kn] ?? '');
              $sonuc['kaynakHatalari'][] = ['kunyeNo' => $__kn, 'tc' => $__ktc, 'sifatId' => $__ksf,
                'kaynakTc' => $__koken === '' ? '' : hks_tc_maske($__koken),
                'kaynakFarkli' => $__koken !== '' && $__koken !== hks_kaynak_tc_norm($__ktc)];
            }
          }
        } catch (Throwable $e) {
          error_log('[hks] künye kaynak kaydı yazılamadı: ' . $e->getMessage());
        }
      }

      // Gönderilenler kaydı (tek satır özet)
      $basarili = array_filter($sonuc['sonuclar'], fn($s) => $s['yeniKunyeNo'] && $s['yeniKunyeNo'] !== '0' && !$s['hataKodu']);

      // KRİTİK: HKS'e TEK KÜNYE bile gitmediyse (tam başarısızlık — ör. sıfat
      // uyumsuzluğu, GTBGLB00000001 vb.) taslak SİLİNMEZ ve "Gönderilenler"e
      // sahte bir kayıt atılmaz (orada hiçbir şey gönderilmedi). Kullanıcı
      // taslağa geri döner, hatalı alanı (ör. karşı taraf sıfatı) "Düzenle" ile
      // değiştirip AYNI taslağı tekrar gönderebilir — formu baştan doldurmaz.
      if (count($basarili) === 0) {
        // Audit taslak geri konmadan ÖNCE; 'yanit_bos' = sonuç satırı ve genel hata yok
        // (yanıt ayrıştırılamadı — HKS isteği almış olabilir). Davranış DEĞİŞMEZ.
        hks_gonderim_audit_yaz('hks_gonderim_sonuc', hks_gonderim_sonuc_turu($sonuc), $__actx, $sonuc);
        $taslagiGeriKoy();
        hks_json_cikti($sonuc + ['taslakKorundu' => true]);
      }

      // En az bir künye gerçekten oluştu → bu GERİ ALINAMAZ, kayıt tutulmalı.
      // Audit, aşağıdaki yazmalardan (kv / havuz / Gönderilenler INSERT) ÖNCE: bunlardan
      // biri istisna atsa bile "künye oluştu" izi kalır. $gid bu yüzden burada üretilir.
      $gid = 'g' . round(microtime(true) * 1000);
      hks_gonderim_audit_yaz('hks_gonderim_sonuc', hks_gonderim_sonuc_turu($sonuc), $__actx + ['gonderim_id' => $gid], $sonuc);
      hks_son_guncelle($ortak, $t['firma_id']);
      hks_kisi_havuzuna_isle($db, $ortak, isset($__hks_user['id']) ? (int)$__hks_user['id'] : null);
      // Toplam kg / adet YALNIZ başarılı satırlardan (hatalı satır "gönderildi" sayılmaz).
      [$__bkg, $__badet] = hks_basarili_ozet($sonuc['sonuclar'], $satirlar);
      $toplamKg = $__bkg ?? array_sum(array_map(fn($s) => (float)$s['miktar'], $satirlar));
      $rusum = array_sum(array_map(fn($s) => (float)$s['rusum'], $sonuc['sonuclar']));
      $yeniKunyeler = array_values(array_map(fn($s) => $s['yeniKunyeNo'], $basarili));
      $st = $db->prepare('INSERT INTO ' . hks_tablo('gonderilenler') . '
        (id, zaman, firma_id, firma_ad, plaka, belge_no, ulke_ad, urun_ad, adet, toplam_kg, fiyat, rusum, hata_sayisi, genel_hata, bildirim_turu, veri)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
      $st->execute([$gid, date('Y-m-d H:i:s'), $t['firma_id'], $t['firma_ad'],
        $ortak['plaka'] ?? '', $ortak['belgeNo'] ?? '', $ortak['ulkeAd'] ?? '', $ortak['urunAd'] ?? '',
        $__badet > 0 ? $__badet : count($satirlar), $toplamKg, $ortak['fiyat'] ?? 0, $rusum,
        count($sonuc['sonuclar']) - count($basarili), $sonuc['genelHata'], hks_bildirim_turu_kodu($ortak),
        json_encode(['yeniKunyeler' => $yeniKunyeler, 'sonuclar' => $sonuc['sonuclar'], 'kopya' => $__kopya, 'gonderen' => hks_gonderim_gonderen($__hks_user)], JSON_UNESCAPED_UNICODE)]);

      // BEYAN KÖPRÜSÜ: taslak Beyan ekranından açıldıysa bağ kaydını sonuçlandır.
      // Hata yutan yardımcıdır — köprü sorunu bu geri alınamaz akışı kesmez.
      if (function_exists('beyan_hks_taslak_isaretle')) {
        $kaynak = $ortak['kaynak'] ?? null;
        beyan_hks_taslak_isaretle((string)$t['id'], 'gonderildi', ['gonderim_id' => $gid,
          'beyanId' => (is_array($kaynak) && ($kaynak['tip'] ?? '') === 'beyan') ? (int)($kaynak['beyanId'] ?? 0) : 0]);
      }

      // Taslak zaten en başta ATOMİK olarak sahiplenilirken silinmişti (çift
      // gönderim koruması) ve buraya gelindiyse en az bir künye GERİ ALINAMAZ
      // şekilde gönderildi → geri konmaz. Kısmi başarısızlıkta da geri konmaz:
      // aynı taslağı tekrar göndermek BAŞARILI satırları MÜKERRER gönderirdi.
      // Hangi satırların başarısız olduğu $sonuc.sonuclar içinde döner.
      // 'gonderim': Gönderim Sonucu penceresindeki "🖨️ Yazdır" için (yalnız yanıt; Gönderilenler
      // satırıyla AYNI plaka/belge/gün/künyeler — yazdırma HKS'e yine canlı sorgu atar).
      hks_json_cikti($sonuc + ['gonderim' => ['id' => $gid, 'plaka' => (string)($ortak['plaka'] ?? ''),
        'belgeNo' => (string)($ortak['belgeNo'] ?? ''), 'tarih' => date('Y-m-d'), 'kunyeler' => $yeniKunyeler]]);
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
      // Kayıtlı karşı tarafta ülke metnine ad yerine TC/VKN düşmüş olabilir —
      // adı Kişi Havuzu'ndan göster (yalnız görüntü; saklı kayıt değişmez).
      $__adlar = hks_gonderilen_adlari($db, array_column($rows, 'ulke_ad'));
      $liste = array_map(function ($r) use ($__adlar) {
        $veri = json_decode($r['veri'], true) ?: [];
        $__ozet = hks_gonderilen_gercek_toplam($veri, (float)$r['toplam_kg'], (int)$r['adet']);
        return [
          'id' => $r['id'], 'zaman' => (new DateTime($r['zaman']))->format('c'),
          'firmaId' => $r['firma_id'] ?? '', 'firmaAd' => $r['firma_ad'],
          'plaka' => $r['plaka'], 'belgeNo' => $r['belge_no'],
          'ulkeAd' => hks_gonderilen_ulke_isimle($r['ulke_ad'], $__adlar), 'urunAd' => $r['urun_ad'], 'adet' => $__ozet[1],
          'toplamKg' => $__ozet[0], 'fiyat' => (float)$r['fiyat'], 'rusum' => (float)$r['rusum'],
          'hataSayisi' => (int)$r['hata_sayisi'], 'genelHata' => $r['genel_hata'],
          'bildirimTuru' => $r['bildirim_turu'] ?? null,   // P3 — NULL: legacy kayıt (backfill yapılmadı)
          'yeniKunyeler' => $veri['yeniKunyeler'] ?? [],
          // Kopyanın KENDİSİ listede dönmez (500 satır şişmesin) — yalnız var mı.
          // Tohum ayrıca 'gonderilen_tohum' ucundan istenir.
          'kopyaVar' => !empty($veri['kopya']),
          // v417: gönderen kullanıcının ad anlık görüntüsü (eski kayıtlarda boş).
          'gonderen' => is_string($veri['gonderen']['ad'] ?? null) ? $veri['gonderen']['ad'] : '',
        ];
      }, $rows);
      hks_json_cikti(['gonderilenler' => $liste]);
    }

    // ---- GÖNDERİLEN → FORMA DOLU TOHUM ("↻ Tekrar gönder") ----
    // SALT OKUNUR: tabloya yazmaz, taslak oluşturmaz, HKS'e bağlanmaz. Tohum
    // yalnız formu doldurur; kullanıcı "Taslağa Kaydet" deyince yazma yine TEK
    // yoldan (taslak_kaydet → hks_taslak_olustur) ve tam doğrulamayla yapılır.
    // Firma izolasyonu 'gonderilenler' listesiyle BİREBİR aynı: firma_id'si
    // olmayan eski kayıt yalnız aynı firma ADIYLA açılır.
    case 'gonderilen_tohum': {
      $fid = trim((string)($g['firmaId'] ?? ''));
      $fad = trim((string)($g['firmaAd'] ?? ''));
      $gid = trim((string)($g['id'] ?? ''));
      if ($fid === '') hks_json_cikti(['hata' => 'Firma seçilmedi.'], 400);
      $st = $db->prepare('SELECT * FROM ' . hks_tablo('gonderilenler') . '
        WHERE id = ? AND (firma_id = ? OR (COALESCE(firma_id, \'\') = \'\' AND firma_ad = ?))');
      $st->execute([$gid, $fid, $fad]);
      $row = $st->fetch();
      if (!$row) hks_json_cikti(['hata' => 'Gönderim bulunamadı (veya bu firmaya ait değil).'], 404);
      // Hedef firma (opsiyonel): aktif firmadan farklıysa kayıtlı olmalı — tohumda
      // firmaya özgü alanlar (sıfat, işyeri) boşaltılır, istemci firmayı değiştirir.
      $hedef = trim((string)($g['hedefFirmaId'] ?? ''));
      $firmaDegisti = $hedef !== '' && $hedef !== $fid;
      if ($firmaDegisti && !hks_firma_bul($hedef)) hks_json_cikti(['hata' => 'Hedef firma bulunamadı.'], 400);
      hks_json_cikti(hks_gonderim_tohumu($db, $row, $hedef !== '' ? $hedef : null, $firmaDegisti));
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

    // ---- AYNI GÜN AYNI PLAKA (v416) — Gönderim Onayı uyarısı. SALT OKUNUR ----
    // Gönderim ENGELLENMEZ; yalnız bugün bu firmadan aynı plakaya (plaka yoksa aynı
    // belge no'ya) yapılmış gönderimler listelenir. Firma izolasyonu 'gonderilenler' ile aynı.
    case 'ayni_plaka_bugun': {
      $fid = trim($g['firmaId'] ?? '');
      if ($fid === '') hks_json_cikti(['hata' => 'Firma seçilmedi.'], 400);
      $liste = hks_bugun_ayni_plaka($db, hks_tablo('gonderilenler'), $fid, trim($g['firmaAd'] ?? ''),
        (string)($g['plaka'] ?? ''), (string)($g['belgeNo'] ?? ''));
      $__adlar = hks_gonderilen_adlari($db, array_column($liste, 'ulkeAd'));
      foreach ($liste as &$__r) { $__r['ulkeAd'] = hks_gonderilen_ulke_isimle($__r['ulkeAd'], $__adlar); }
      unset($__r);
      hks_json_cikti(['gonderimler' => $liste, 'gun' => date('Y-m-d')]);
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
