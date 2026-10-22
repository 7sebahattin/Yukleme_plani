<?php
// =========================================================
// scripts/hks_csrf_kapsam_smoke.php — Hal Kayıt api.php merkezi CSRF kapısı (v418)
//
// SADECE CLI, statik. DB'ye ve ağa DOKUNMAZ: halkayit/api.php, halkayit/app.html ve
// halkayit/tani.php kaynak olarak okunur. Sözleşme: durum değiştiren HER eylem (bilinmeyen
// dahil) switch'ten ÖNCE csrf_check(hks_csrf_girdi($g)) ister; salt okunur eylemler
// hks_csrf_muaf_eylemler() listesindedir (fail-closed).
//
//   php scripts/hks_csrf_kapsam_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$KOK = dirname(__DIR__);
$fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail;
    if (!$c) $fail++;
    printf("%-90s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ipucu);
}

$api  = (string)file_get_contents("$KOK/halkayit/api.php");
$html = (string)file_get_contents("$KOK/halkayit/app.html");
$tani = (string)file_get_contents("$KOK/halkayit/tani.php");

// Yorumları atılmış kod (yorum içindeki "csrf_check(...)" sayımı yanıltmasın)
function yorumsuz(string $s): string {
    $s = preg_replace('#/\*.*?\*/#s', '', $s);
    return preg_replace('#(^|[^:\'"])//[^\n]*#', '$1', $s);
}
$kod = yorumsuz($api);

// --- muaf liste (gerçek fonksiyon, dosyadan ayıklanıp çalıştırılır) -------------------
echo "── muaf liste ──\n";
$muaf = [];
if (preg_match('/^function hks_csrf_muaf_eylemler\(\): array \{.*?^\}/ms', $api, $m)) {
    eval($m[0]);
    $muaf = hks_csrf_muaf_eylemler();
}
ok('hks_csrf_muaf_eylemler() dizi döndürür', is_array($muaf) && count($muaf) > 10);
ok("muaf liste 'csrf' (token tazeleme) içerir", in_array('csrf', $muaf, true));
$yazan = ['taslak_gonder', 'taslak_kaydet', 'taslak_sil', 'firma_kaydet', 'firma_sil', 'listeler_yenile',
          'kisi_kaydet', 'kisi_sil', 'belirsiz_isaret_kapat'];
foreach ($yazan as $e) ok("muaf listede DEĞİL (CSRF ister): $e", !in_array($e, $muaf, true));
ok('muaf liste tekrarsız', count($muaf) === count(array_unique($muaf)));

// --- case envanteri ------------------------------------------------------------------
echo "── case envanteri ──\n";
preg_match_all("/^    case '([a-z_]+)': \{/m", $api, $cm);
$caseler = $cm[1];
ok('case envanteri bulundu (>= 30)', count($caseler) >= 30, (string)count($caseler));
$eksik = array_diff($muaf, $caseler);
ok('muaf listedeki her eylemin case\'i var (ölü kayıt yok)', !$eksik, implode(',', $eksik));
$korunan = array_values(array_diff($caseler, $muaf));
foreach ($yazan as $e) ok("case kapıya tabi: $e", in_array($e, $korunan, true));
echo '   kapıya tabi case\'ler: ' . implode(', ', $korunan) . "\n";

// Her case gövdesi: muaf olanlarda yazma belirteci yok (kisiler = açık istisna)
$konumlar = [];
foreach ($caseler as $c) $konumlar[$c] = strpos($api, "    case '$c': {");
asort($konumlar);
$adlar = array_keys($konumlar); $pozlar = array_values($konumlar);
$govdeler = [];
foreach ($adlar as $i => $ad) {
    $son = $pozlar[$i + 1] ?? strpos($api, "    default:", $pozlar[$i]);
    $govdeler[$ad] = yorumsuz(substr($api, $pozlar[$i], ($son === false ? strlen($api) : $son) - $pozlar[$i]));
}
$yazmaDeseni = '/\b(INSERT|UPDATE|DELETE|REPLACE)\b|hks_kv_yaz|hks_kv_sil|hks_bildirim_kaydet|hks_taslak_olustur|hks_kisi_kaydet|hks_kisi_sil|hks_belirsiz_temizle|hks_belirsiz_isaretle|audit_log_event/';
foreach ($muaf as $e) {
    if ($e === 'kisiler' || !isset($govdeler[$e])) continue;
    $g = $govdeler[$e];
    // Salt okunur uçlar dosya yazan SOAP/okuma yapabilir; DB yazımı / gönderim belirteci olmamalı
    ok("muaf '$e' gövdesinde yazma/gönderim belirteci yok", !preg_match($yazmaDeseni, $g), (string)(preg_match($yazmaDeseni, $g, $mm) ? $mm[0] : ''));
}
ok("açık istisna 'kisiler': yalnız idempotent içe aktarma (hks_kisi_havuzu_ice_aktar) yazar",
    isset($govdeler['kisiler']) && strpos($govdeler['kisiler'], 'hks_kisi_havuzu_ice_aktar($db)') !== false
    && !preg_match('/\b(INSERT|UPDATE|DELETE)\b|hks_kv_yaz|hks_bildirim_kaydet|hks_taslak_olustur/', $govdeler['kisiler']));

// --- kapı yerleşimi -----------------------------------------------------------------
echo "── kapı yerleşimi ──\n";
$pSw  = strpos($kod, 'switch ($action)');
$pCk  = strpos($kod, 'csrf_check(hks_csrf_girdi($g))');
$pMu  = strpos($kod, 'in_array($action, hks_csrf_muaf_eylemler(), true)');
$pWc  = strpos($kod, 'session_write_close()');
$pUn  = strpos($kod, "unset(\$g['csrf'])");
ok('switch ($action) bulundu', $pSw !== false);
ok('kapı: muaf değilse csrf_check(hks_csrf_girdi($g)), switch\'ten ÖNCE',
    $pMu !== false && $pCk !== false && $pMu < $pCk && $pCk < $pSw);
ok('kapı hks_tablolari_hazirla() / hks_db() çağrısından da ÖNCE (yetkisiz istek DB işi yapmaz)',
    $pCk < strpos($kod, 'hks_tablolari_hazirla();', $pMu) && $pCk < strpos($kod, '$db = hks_db();', $pMu));
ok('kapı: doğrulamadan sonra session_write_close() (uzun gönderimde oturum kilidi yok)', $pWc !== false && $pWc > $pCk && $pWc < $pSw);
ok("kapı: gövdedeki 'csrf' yedeği case'lere sızmasın diye unset edilir", $pUn !== false && $pUn > $pCk && $pUn < $pSw);
ok('api.php\'de TEK csrf_check(hks_csrf_girdi($g)) çağrısı (case\'lerde tekrar yok)', substr_count($kod, 'csrf_check(') === 1);
ok('eski csrf_check($_SERVER[HTTP_X_CSRF_TOKEN]) biçimi yok', strpos($api, "csrf_check(\$_SERVER['HTTP_X_CSRF_TOKEN']") === false);
$pGonder = strpos($kod, "case 'taslak_gonder': {");
$pSahiplen = strpos($kod, '$__claim = $db->prepare(\'DELETE FROM');
ok('taslak_gonder: CSRF kapısı atomik DELETE sahiplenmesinden ÖNCE', $pGonder !== false && $pSahiplen !== false && $pCk < $pGonder && $pGonder < $pSahiplen);
ok('oturum/yetki kapısı (401/403) CSRF kapısından ÖNCE', strpos($kod, "can('records.write')") !== false && strpos($kod, "can('records.write')") < $pCk);
ok('api.php\'de $_SESSION yazımı yok (session_write_close güvenli)', strpos($kod, '$_SESSION') === false && strpos($kod, 'set_flash') === false);
ok('firma uçları: yönetici kapısı CSRF\'i tekrarlamaz', (bool)preg_match('/function hks_firma_yonetici_kapisi\(array \$g\): void \{[^}]*\}[^}]*\}/s', $kod, $fk) && strpos($fk[0], 'csrf_check') === false);

// --- istemci (app.html) ---------------------------------------------------------------
echo "── istemci: API_GOVDE_CSRF ──\n";
$istemci = [];
if (preg_match('/const API_GOVDE_CSRF = \{(.*?)\};/s', $html, $m)) {
    preg_match_all('/([a-z_]+)\s*:\s*1/', $m[1], $im);
    $istemci = $im[1];
}
ok('API_GOVDE_CSRF bulundu', count($istemci) > 0);
foreach ($yazan as $e) ok("istemci gövde yedeği içerir: $e", in_array($e, $istemci, true));
$fazla = array_intersect($istemci, $muaf);
ok('API_GOVDE_CSRF salt okunur (muaf) eylem içermez', !$fazla, implode(',', $fazla));
// API_ROTA'daki her kapıya tabi eylem gövde yedeğinde olmalı
preg_match("/const API_ROTA = \{(.*?)\n\};/s", $html, $rm);
preg_match_all("/'(?:GET|POST) [^']+':\s*'([a-z_]+)'/", $rm[1] ?? '', $ra);
$rotaEylemler = array_unique($ra[1]);
$rotaKorunan = array_diff($rotaEylemler, $muaf);
$eksikIst = array_diff($rotaKorunan, $istemci);
ok('API_ROTA\'daki muaf olmayan her eylem API_GOVDE_CSRF\'te', !$eksikIst, implode(',', $eksikIst));
ok('api() tüm isteklerde X-CSRF-Token başlığı yollar', strpos($html, "'X-CSRF-Token': tok") !== false);
ok('api() 403 + code===403 → csrfTazele() + BİR kez tekrar', (bool)preg_match("/r\.status === 403 && j\.code === 403 && !tekrar && action !== 'csrf'/", $html)
    && strpos($html, 'api(yol, metod, govde, true)') !== false);
ok('kalıcı 403\'te kullanıcıya "Sayfayı yenileyip tekrar deneyin." ipucu', strpos($html, 'Sayfayı yenileyip tekrar deneyin.') !== false);
ok('meta yer tutucusu/boş token: ilk yazmadan önce /api/csrf', strpos($html, "if (action !== 'csrf' && !csrfToken())") !== false);

// --- tani.php (token yollamaz) ----------------------------------------------------------
echo "── halkayit/tani.php ──\n";
preg_match_all("/(?:cagir|post)\(\s*'([a-z_]+)'/", $tani, $tm);
$taniEylemler = array_unique($tm[1]);
ok('tani.php eylemleri bulundu', count($taniEylemler) > 0, implode(',', $taniEylemler));
$taniDisi = array_diff($taniEylemler, $muaf);
ok('tani.php\'nin çağırdığı her eylem muaf listede (token yollamıyor)', !$taniDisi, implode(',', $taniDisi));
foreach (['iller', 'ilceler', 'beldeler', 'isyerleri', 'kayitli_kisi', 'urun_listeleri', 'bildirim_onizle'] as $e) {
    ok("tani.php eylemi muaf: $e", in_array($e, $muaf, true));
}

echo $fail === 0 ? "\nTÜM TESTLER GEÇTİ\n" : "\n$fail TEST BAŞARISIZ\n";
exit($fail === 0 ? 0 : 1);
