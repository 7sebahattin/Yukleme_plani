<?php
// =============================================================================
// HKS PANEL - BELİRSİZ GÖNDERİM (v417)
//
// SORUN: taslak_gonder'de hks_bildirim_kaydet() istisna fırlatırsa (zaman aşımı,
// ağ kopması; CURL 60 sn) HKS'in bildirimi ALIP ALMADIĞI BİLİNMEZ. Taslak geri
// konur ama kalıcı hiçbir iz kalmazdı; kullanıcı aynı taslağa tekrar "Gönder" derse
// MÜKERRER künye + rüsum oluşabilirdi.
//
// ÇÖZÜM (migration YOK): belirsiz kalan gönderim için hks_kv'de plaka+gün anahtarlı
// bir İŞARET tutulur. İşaretli plakanın tekrar gönderiminde, GERÇEK gönderimden
// ÖNCE HKS'e salt okunur sorulur (hks_toplu_kunye): Gönderilenler'de olmayan künye
// varsa gönderim 409 ile DURDURULUR; sorgu yapılamazsa da durdurulur (fail-closed).
// Bilinçli geçiş: kayıtlı işaret id'siyle birebir eşleşen açık onay + audit.
//
// BU DOSYA HİÇBİR YAZMA/GÖNDERİM YOLU DEĞİLDİR:
//   • HKS'e kendisi hiçbir şey göndermez (sorgu çağıran tarafından enjekte edilir),
//   • taslak / Gönderilenler tablolarına yazmaz; yalnız hks_kv_yaz / hks_kv_sil,
//   • çıktı basmaz, exit etmez (test doğrudan require eder).
// Gönderimin TEK yolu hks_bildirim_kaydet() olarak kalır, yeniden deneme YOKTUR.
//
// Anahtar-başına-kayıt: tek liste anahtarı REPLACE yarışında kayıp güncelleme yapardı.
// Anahtar (<= 60 karakter): bg_<firma md5[8]>_<plaka|belge md5[12]>_<YYYYMMDD>.
// İşarette TC / doğum tarihi / cep / şifre YOKTUR; hata metninden uzun rakam
// dizileri de temizlenir.
//
// Bağımlılıklar (api.php / db.php): hks_kv_oku, hks_kv_yaz, hks_kv_sil, hks_kv_onekli.
// Test bunların taklidini tanımlar. Dosya taslak_lib / tekrar_gonder_lib'i require ETMEZ.
// =============================================================================

const HKS_BELIRSIZ_ONEK = 'bg_';

// Plaka (norm) ya da — plaka yoksa — 'B' + belge no. Eşleşme kuralı Gönderilenler
// karşılaştırmasıyla aynıdır: plaka doluysa belge no'ya bakılmaz.
function hks_belirsiz_plaka_anahtari($plaka, $belgeNo): string {
  $p = preg_replace('/[^0-9A-Z]/', '', strtoupper(trim((string)$plaka)));
  if ($p !== '') return $p;
  $b = preg_replace('/[^0-9A-Z]/', '', strtoupper(trim((string)$belgeNo)));
  return $b === '' ? '' : 'B' . $b;
}

function hks_belirsiz_firma_onek($firmaId): string {
  return HKS_BELIRSIZ_ONEK . substr(md5((string)$firmaId), 0, 8) . '_';
}

function hks_belirsiz_anahtar($firmaId, $plaka, $belgeNo, string $gun): string {
  $pk = hks_belirsiz_plaka_anahtari($plaka, $belgeNo);
  if ($pk === '') return '';
  return hks_belirsiz_firma_onek($firmaId) . substr(md5($pk), 0, 12) . '_' . str_replace('-', '', $gun);
}

// İşaret, işaretlendiği günü izleyen günün sonuna kadar geçerlidir (HKS sorgusu
// tarih bağlıdır; gece yarısı geçişini kapsar).
function hks_belirsiz_gecerli_mi($isaret, string $bugun): bool {
  if (!is_array($isaret) || empty($isaret['gun']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$isaret['gun'])) return false;
  $son = date('Y-m-d', strtotime($isaret['gun'] . ' +1 day'));
  return $bugun <= $son;
}

function hks_belirsiz_gun_oncesi(string $gun, int $n = 1): string {
  return date('Y-m-d', strtotime($gun . ' -' . $n . ' day'));
}

// Hata metninden kişisel veri olabilecek uzun rakam dizilerini temizler, kısaltır.
function hks_belirsiz_metin($s, int $uzunluk = 200): string {
  $s = is_scalar($s) ? (string)$s : '';
  if (function_exists('mb_scrub')) $s = mb_scrub($s, 'UTF-8');
  $s = preg_replace('/\d{9,}/', '***', $s);
  $s = preg_replace('/\s+/', ' ', $s);
  return function_exists('mb_substr') ? mb_substr($s, 0, $uzunluk, 'UTF-8') : substr($s, 0, $uzunluk);
}

// "Kesin gönderilmedi" = isteğin HKS'e hiç ulaşamadığı bağlantı hataları
// (cURL 6 DNS, 7 bağlanamadı, 35 TLS el sıkışması, 60 sertifika). Başka HER ŞEY
// (28 zaman aşımı, 52/56 yanıt kesildi, HTTP 5xx, bilinmeyen) BELİRSİZ sayılır.
function hks_belirsiz_kesin_gitmedi($e): bool {
  if (!($e instanceof Throwable)) return false;
  $no = isset($e->errno) ? (int)$e->errno : 0;
  return in_array($no, [6, 7, 35, 60], true);
}

// İşareti yazar. Aynı anahtar varsa ilk id/zaman KORUNUR (tekrarlayan belirsizlik
// yeni işaret açmaz). Döner: kayıt ya da plaka/belge yoksa null.
function hks_belirsiz_isaretle($firmaId, $taslakId, $plaka, $belgeNo, $toplamKg, $hata,
                               string $neden = 'istisna', ?string $gun = null, ?string $simdi = null): ?array {
  $gun = $gun ?: date('Y-m-d');
  $anahtar = hks_belirsiz_anahtar($firmaId, $plaka, $belgeNo, $gun);
  if ($anahtar === '') return null;
  $onceki = hks_kv_oku($anahtar, null);
  $kayit = [
    'id' => (is_array($onceki) && !empty($onceki['id'])) ? (string)$onceki['id'] : 'bg' . bin2hex(random_bytes(6)),
    'anahtar' => $anahtar,
    'pk' => hks_belirsiz_plaka_anahtari($plaka, $belgeNo),
    'taslakId' => hks_belirsiz_metin($taslakId, 40),
    'plaka' => hks_belirsiz_metin($plaka, 20),
    'belgeNo' => hks_belirsiz_metin($belgeNo, 40),
    'gun' => $gun,
    'zaman' => (is_array($onceki) && !empty($onceki['zaman'])) ? (string)$onceki['zaman'] : ($simdi ?: date('Y-m-d H:i:s')),
    'kg' => round((float)$toplamKg, 3),
    'neden' => hks_belirsiz_metin($neden, 20),
    'hata' => hks_belirsiz_metin($hata, 200),
  ];
  hks_kv_yaz($anahtar, $kayit);
  return $kayit;
}

// Taslak satırından ($t: id, veri JSON) işaret kurar (api.php catch + shutdown yolu).
function hks_belirsiz_isaretle_taslaktan(array $t, string $neden, $hata, ?string $gun = null): ?array {
  $veri = json_decode((string)($t['veri'] ?? ''), true);
  $ortak = is_array($veri['ortak'] ?? null) ? $veri['ortak'] : [];
  $kg = 0.0;
  foreach ((array)($veri['satirlar'] ?? []) as $s) $kg += (float)($s['miktar'] ?? 0);
  if ($kg <= 0) $kg = (float)($ortak['planKg'] ?? 0);
  return hks_belirsiz_isaretle($t['firma_id'] ?? '', $t['id'] ?? '', $ortak['plaka'] ?? '', $ortak['belgeNo'] ?? '',
    $kg, $hata, $neden, $gun);
}

// Bu plaka/belge için geçerli işaretler (bugün + dün anahtarları). Süresi dolanı siler.
function hks_belirsiz_oku_gunler($firmaId, $plaka, $belgeNo, string $bugun): array {
  $out = [];
  foreach ([$bugun, hks_belirsiz_gun_oncesi($bugun)] as $gun) {
    $a = hks_belirsiz_anahtar($firmaId, $plaka, $belgeNo, $gun);
    if ($a === '') continue;
    $k = hks_kv_oku($a, null);
    if ($k === null) continue;
    if (hks_belirsiz_gecerli_mi($k, $bugun)) $out[] = $k;
    else hks_kv_sil($a);
  }
  return $out;
}

// Firmanın tüm geçerli işaretleri (SALT OKUNUR — süresi dolanları yalnız atlar).
function hks_belirsiz_liste_firma($firmaId, string $bugun): array {
  $out = [];
  foreach (hks_kv_onekli(hks_belirsiz_firma_onek($firmaId)) as $k) {
    if (hks_belirsiz_gecerli_mi($k, $bugun)) $out[] = $k;
  }
  return $out;
}

// Kartta gösterilecek özet (kişisel/ayrıntılı alan yok).
function hks_belirsiz_ozet(array $m): array {
  return ['isaretId' => (string)($m['id'] ?? ''), 'gun' => (string)($m['gun'] ?? ''),
          'zaman' => (string)($m['zaman'] ?? ''), 'neden' => (string)($m['neden'] ?? '')];
}

// Firmanın işaretlerinden taslağın plaka/belgesine uyanın (en yenisi) özetini döner.
function hks_belirsiz_taslak_icin(array $liste, $plaka, $belgeNo): ?array {
  $pk = hks_belirsiz_plaka_anahtari($plaka, $belgeNo);
  if ($pk === '') return null;
  $en = null;
  foreach ($liste as $m) {
    if (($m['pk'] ?? '') !== $pk) continue;
    if ($en === null || strcmp((string)$m['zaman'], (string)$en['zaman']) > 0) $en = $m;
  }
  return $en === null ? null : hks_belirsiz_ozet($en);
}

// Id ile işaret bul (firma listesinden). Eşleşme birebirdir.
function hks_belirsiz_id_ile(array $liste, string $id): ?array {
  if ($id === '') return null;
  foreach ($liste as $m) if ((string)($m['id'] ?? '') === $id) return $m;
  return null;
}

function hks_belirsiz_onay_gecerli(array $isaretler, $onayId): bool {
  $o = trim((string)$onayId);
  if ($o === '') return false;
  foreach ($isaretler as $m) if ((string)($m['id'] ?? '') === $o) return true;
  return false;
}

function hks_belirsiz_temizle_anahtar(string $anahtar): void {
  if ($anahtar === '') return;
  try { hks_kv_sil($anahtar); } catch (Throwable $e) { error_log('[hks_belirsiz] temizlenemedi ' . get_class($e)); }
}

// Plaka/belge için bugün ve dünkü işaretleri siler (başarılı gönderimden sonra).
function hks_belirsiz_temizle_plaka($firmaId, $plaka, $belgeNo, string $bugun): int {
  $n = 0;
  foreach ([$bugun, hks_belirsiz_gun_oncesi($bugun)] as $gun) {
    $a = hks_belirsiz_anahtar($firmaId, $plaka, $belgeNo, $gun);
    if ($a === '') continue;
    if (hks_kv_oku($a, null) !== null) { hks_belirsiz_temizle_anahtar($a); $n++; }
  }
  return $n;
}

// Süresi dolmuş (kimsenin tekrar okumayacağı) işaretleri firma önekiyle bulup siler.
// Başarılı gönderim sonrası çağrılır; kv'de yetim kayıt birikmesin.
function hks_belirsiz_temizle_eskiler($firmaId, string $bugun): int {
  $n = 0;
  foreach (hks_kv_onekli(hks_belirsiz_firma_onek($firmaId)) as $anahtar => $k) {
    if (!hks_belirsiz_gecerli_mi($k, $bugun)) { hks_belirsiz_temizle_anahtar((string)$anahtar); $n++; }
  }
  return $n;
}

function hks_belirsiz_kunye_no_norm($n): string {
  return ltrim(preg_replace('/\D/', '', (string)$n), '0');
}

// Gönderilenler'de bu firmanın $gun günü kayıtlı künye numaraları (SADECE SELECT).
// Plakaya bakılmaz: belirsiz denemeden SONRA kaydedilen bir künye zaten kayıtlıdır;
// ne kadar çok künye "bilinen" sayılırsa yanlış pozitif o kadar azalır, gerçek bir
// mükerrer ise (kayıtta olmayan künye) yine yakalanır.
function hks_belirsiz_bilinen_kunyeler(PDO $db, string $tablo, string $firmaId, string $firmaAd, string $gun): array {
  $bas = $gun . ' 00:00:00';
  $son = date('Y-m-d', strtotime($gun . ' +1 day')) . ' 00:00:00';
  $st = $db->prepare('SELECT veri FROM ' . $tablo . '
    WHERE (firma_id = ? OR (COALESCE(firma_id, \'\') = \'\' AND firma_ad = ?))
      AND zaman >= ? AND zaman < ?');
  $st->execute([$firmaId, $firmaAd, $bas, $son]);
  $set = [];
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ham) {
    $v = json_decode((string)$ham, true);
    if (!is_array($v)) continue;
    foreach ((array)($v['yeniKunyeler'] ?? []) as $k) {
      $n = hks_belirsiz_kunye_no_norm($k);
      if ($n !== '') $set[$n] = true;
    }
    foreach ((array)($v['sonuclar'] ?? []) as $s) {
      $n = hks_belirsiz_kunye_no_norm(is_array($s) ? ($s['yeniKunyeNo'] ?? '') : '');
      if ($n !== '') $set[$n] = true;
    }
  }
  return array_map('strval', array_keys($set));
}

// Bilinmeyenleri ayıklar (saf). $hksKunyeler: hks_toplu_kunye satırları.
function hks_belirsiz_fazla_kunyeler(array $hksKunyeler, array $bilinenNolari): array {
  $bil = [];
  foreach ($bilinenNolari as $n) { $x = hks_belirsiz_kunye_no_norm($n); if ($x !== '') $bil[$x] = true; }
  $out = []; $gorulen = [];
  foreach ($hksKunyeler as $r) {
    if (!is_array($r)) continue;
    $n = hks_belirsiz_kunye_no_norm($r['kunyeNo'] ?? '');
    if ($n === '' || isset($bil[$n]) || isset($gorulen[$n])) continue;
    $gorulen[$n] = true;
    $out[] = [
      'kunyeNo' => hks_belirsiz_metin($r['kunyeNo'] ?? '', 30),
      'urun' => hks_belirsiz_metin($r['urun'] ?? '', 60),
      'miktar' => (float)($r['miktar'] ?? 0),
      'birim' => hks_belirsiz_metin($r['birim'] ?? 'Kg', 10),
      'tarih' => hks_belirsiz_metin($r['tarih'] ?? '', 10),
      'bildirimci' => hks_belirsiz_metin($r['bildirimci'] ?? '', 60),
    ];
  }
  return $out;
}

// HKS'e SALT OKUNUR sorar. $hksSorgu(gun, plaka, belgeNo) → hks_toplu_kunye satırları
// (api.php'de closure hks_toplu_kunye'yi sarar; test ağsız enjekte eder).
// Sorulan günler: işaret günleri + bugün. Plaka doluysa yalnız plaka sorulur (daha geniş).
// Döner: ['durum' => temiz | kunye_var | kontrol_edilemedi, 'bulunan' => [...], 'hata' => '', 'gunler' => [...]].
// İstisna → kontrol_edilemedi (FAIL-CLOSED: çağıran gönderimi durdurur).
function hks_belirsiz_kontrol(array $isaretler, callable $hksSorgu, array $bilinenNolari, string $bugun): array {
  if (!$isaretler) return ['durum' => 'temiz', 'bulunan' => [], 'hata' => '', 'gunler' => []];
  $plaka = ''; $belge = '';
  foreach ($isaretler as $m) {
    if ($plaka === '' && trim((string)($m['plaka'] ?? '')) !== '') $plaka = trim((string)$m['plaka']);
    if ($belge === '' && trim((string)($m['belgeNo'] ?? '')) !== '') $belge = trim((string)$m['belgeNo']);
  }
  if ($plaka !== '') $belge = '';
  $gunler = [$bugun];
  foreach ($isaretler as $m) if (!empty($m['gun'])) $gunler[] = (string)$m['gun'];
  $gunler = array_values(array_unique($gunler));
  sort($gunler);
  $hepsi = [];
  foreach ($gunler as $gun) {
    try {
      $satirlar = $hksSorgu($gun, $plaka, $belge);
    } catch (Throwable $e) {
      return ['durum' => 'kontrol_edilemedi', 'bulunan' => [], 'gunler' => $gunler,
              'hata' => hks_belirsiz_metin($e->getMessage(), 200)];
    }
    foreach ((array)$satirlar as $r) $hepsi[] = $r;
  }
  $fazla = hks_belirsiz_fazla_kunyeler($hepsi, $bilinenNolari);
  return ['durum' => $fazla ? 'kunye_var' : 'temiz', 'bulunan' => $fazla, 'hata' => '', 'gunler' => $gunler];
}

// Audit (bilinçli geçiş / elle kapatma). Kişisel veri YOK: yalnız işaret id'si, sayı,
// plakanın SON 3 karakteri. Throwable yutulur — HKS akışını kesmez.
function hks_belirsiz_audit(string $action, string $firmaId, array $isaretler, array $ek = []): void {
  try {
    if (!function_exists('audit_log_event')) return;
    $m = $isaretler[0] ?? [];
    $p = (string)($m['plaka'] ?? '');
    $payload = [
      'isaret_id' => hks_belirsiz_metin($m['id'] ?? '', 30),
      'firma_id' => hks_belirsiz_metin($firmaId, 60),
      'plaka_son3' => substr(preg_replace('/[^0-9A-Z]/', '', strtoupper($p)), -3),
      'gun' => hks_belirsiz_metin($m['gun'] ?? '', 10),
    ];
    foreach (['bulunan_sayisi', 'kontrol', 'sonuc'] as $k) {
      if (isset($ek[$k])) $payload[$k] = is_int($ek[$k]) ? $ek[$k] : hks_belirsiz_metin($ek[$k], 30);
    }
    audit_log_event($action, 'hks_belirsiz', null, null, $payload);
  } catch (Throwable $e) {
    error_log('[hks_belirsiz] audit ' . get_class($e));
  }
}
