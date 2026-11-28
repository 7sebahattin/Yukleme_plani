<?php
// =========================================================
// api_hizli_kart.php — Ortak "Hızlı Cari / Stok Ekle" TEK ucu (v611 Faz 2). Yanıt HER ZAMAN JSON, no-store (sw.js de önbelleğe almaz).
//   GET  ?islem=secenekler[&tur=cari|stok]  → pencere listeleri (kişisel veri YOK); okuma kapısı (cari_can/stok_can 'read')
//   POST JSON {csrf, tur: cari|stok, istek_id?, …alanlar}  → hızlı kart (hizli=1)
//        cari: unvan*, tip*, telefon, vergi_no, tckn · stok: ad*, tip*, ana_birim*, grup_id, ambalaj_tip_id, birim_dara_kg, hks_urun_id | hks_urun_ad
//   Diğer her istek → 405.
// Sıra: yöntem → oturum (require_login) → csrf_check → tür beyaz listesi → izin (cari_kaydet/stok_kaydet kart ekleme dalıyla AYNI: cari_can/stok_can 'create')
//        → şema (cari_ek_hazir / stok_ek_hazir; yoksa 503 kod=sema_yok) → istek_id tekrarı → yalnız config/hizli_kart.php → Faz 1 çekirdek fonksiyonu.
// Yanıt: {ok:true, tur, id, kod, ad, hizli:1, eksikler[], eksik_alanlar[], tekrar?} · {ok:false, hata, kod, alanlar?}
//   kod: yontem · oturum · gecersiz_istek · gecersiz_tur · yetki · sema_yok · dogrulama · kayit · sunucu (CSRF hatası csrf_check'in kendi JSON'u: 403)
// Bu dosya tabloya DOĞRUDAN yazmaz (SQL yok); audit çekirdek fonksiyonlarında.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/cari_core.php';
require_once __DIR__ . '/config/stok_core.php';
require_once __DIR__ . '/config/hizli_kart.php';

header('Content-Type: application/json; charset=utf-8');   // csrf_check başarısızlığı da JSON döner
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

/** JSON yanıtı yazar ve çıkar. */
function hizli_kart_yanit(array $r, int $http = 200): never
{
    http_response_code($http);
    unset($r['http']);
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

$yontem = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$secenekIstegi = $yontem === 'GET' && (string)($_GET['islem'] ?? '') === 'secenekler';
if ($yontem !== 'POST' && !$secenekIstegi) {
    header('Allow: POST, GET');
    hizli_kart_yanit(['ok' => false, 'hata' => 'Yalnızca POST (seçenekler için GET ?islem=secenekler).', 'kod' => 'yontem'], 405);
}
if (current_user() === null) hizli_kart_yanit(['ok' => false, 'hata' => 'Oturum sona erdi; yeniden giriş yapın.', 'kod' => 'oturum'], 401);
$auth_user = require_login();
$uid = (int)($auth_user['id'] ?? 0);
$pdo = db();

// ── GET: seçenek listeleri ──
if ($secenekIstegi) {
    $t = (string)($_GET['tur'] ?? '');
    if ($t !== '' && !isset(hizli_kart_turler()[$t])) hizli_kart_yanit(['ok' => false, 'hata' => 'Geçersiz tür.', 'kod' => 'gecersiz_tur'], 400);
    $istenen = $t !== '' ? [$t] : array_keys(hizli_kart_turler());
    try {
        $s = hizli_kart_secenekler($pdo, $istenen);
    } catch (PDOException $e) {
        error_log('[api_hizli_kart] secenekler: ' . $e->getMessage());
        hizli_kart_yanit(['ok' => false, 'hata' => 'İşlem sırasında sunucu hatası oluştu.', 'kod' => 'sunucu'], 500);
    }
    if (!$s) hizli_kart_yanit(['ok' => false, 'hata' => 'Bu işlem için yetkiniz yok.', 'kod' => 'yetki'], 403);
    hizli_kart_yanit(['ok' => true] + $s);
}

// ── POST: hızlı ekleme ──
$input = $_POST;
$ham = (string)file_get_contents('php://input');
if ($ham !== '' && str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    $j = json_decode($ham, true);
    if (!is_array($j)) hizli_kart_yanit(['ok' => false, 'hata' => 'Geçersiz istek gövdesi.', 'kod' => 'gecersiz_istek'], 400);
    $input = $j;
}
$csrf = $input['csrf'] ?? null;
csrf_check(is_string($csrf) ? $csrf : null);

$tur = is_string($input['tur'] ?? null) ? (string)$input['tur'] : '';
if (!isset(hizli_kart_turler()[$tur])) hizli_kart_yanit(['ok' => false, 'hata' => 'Geçersiz tür (cari ya da stok).', 'kod' => 'gecersiz_tur'], 400);
if (!hizli_kart_izin($tur, 'create')) hizli_kart_yanit(['ok' => false, 'hata' => 'Bu işlem için yetkiniz yok.', 'kod' => 'yetki'], 403);
try {
    if (!hizli_kart_sema_hazir($pdo, $tur)) hizli_kart_yanit(['ok' => false, 'hata' => hizli_kart_sema_mesaj($tur), 'kod' => 'sema_yok'], 503);
    $ik = hizli_kart_istek_id($input);
    if ($ik === false) hizli_kart_yanit(['ok' => false, 'hata' => 'Geçersiz istek anahtarı.', 'kod' => 'gecersiz_istek'], 400);
    $once = hizli_kart_istek_bul($ik, $tur, $uid);
    if ($once !== null) hizli_kart_yanit($once);
    $r = hizli_kart_ekle($pdo, $tur, $input, $uid);
} catch (PDOException $e) {
    error_log('[api_hizli_kart] ' . $tur . ': ' . $e->getMessage());
    hizli_kart_yanit(['ok' => false, 'hata' => 'İşlem sırasında sunucu hatası oluştu.', 'kod' => 'sunucu'], 500);
}
if (empty($r['ok'])) hizli_kart_yanit($r, (int)($r['http'] ?? 422));
hizli_kart_istek_sakla($ik, $tur, $uid, $r);
hizli_kart_yanit($r);
