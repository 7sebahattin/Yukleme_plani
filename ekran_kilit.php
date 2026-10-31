<?php
// =============================================================================
// ekran_kilit.php — Masaüstü ekran kilidi ucu (JSON, v455, PIN'li)
// =============================================================================
// POST JSON {islem: nabiz | kilitle | ac | kur | degistir, ...}. Mantık: config/ekran_kilit.php.
// PIN / şifre / hash ASLA audit'e/log'a yazılmaz. Oturum yoksa 401 (istemci login'e yönlendirir).
//
// BİLİNÇLİ SAPMA: bu uç projenin genel CSRF jeton kapısını KULLANMAZ. O kapı PHP oturumuna (PHPSESSID, ~24 dk GC)
// bağlıdır; giriş oturumu (asya_session, 24 sa) ise ondan uzun yaşar — uzun pasiflikte PHP oturumu
// silinince CSRF 403 verip doğru PIN'i bile reddediyordu. Yerine oturumsuz istek kapısı:
// POST + application/json + X-Requested-With + aynı köken (Origin/Referer) —
// ekran_kilit_istek_denetle(). Kimlik doğrulama asya_session ile (current_user()).
// =============================================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ekran_kilit_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ekran_kilit_hata_json(string $mesaj, string $kod, int $code, array $ek = []): void {
    ekran_kilit_json(['ok' => false, 'error' => $mesaj, 'kod' => $kod] + $ek, $code);
}

$kullanici = current_user();
if ($kullanici === null) {
    ekran_kilit_hata_json('Oturum sona ermiş. Lütfen tekrar giriş yapın.', 'oturum', 401, ['login' => true]);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ekran_kilit_hata_json('Geçersiz istek.', 'istek', 405);
$istekHata = ekran_kilit_istek_denetle($_SERVER);
if ($istekHata !== null) ekran_kilit_hata_json($istekHata, 'istek', 403);

$ham = (string)file_get_contents('php://input');
if (strlen($ham) > 2000) ekran_kilit_hata_json('İstek çok uzun.', 'istek', 413);
$in = json_decode($ham, true);
if (!is_array($in)) ekran_kilit_hata_json('Geçersiz istek.', 'istek', 400);

$uid   = (int)$kullanici['id'];
$pdo   = db();
$islem = is_string($in['islem'] ?? null) ? $in['islem'] : '';
$s     = fn($k) => is_string($in[$k] ?? null) ? $in[$k] : '';

// Sonuç dizisini HTTP yanıtına çevirir (başarı çağıranda işlenir). 10. yanlışta oturum sonlandırılır.
function ekran_kilit_hata_yanit(array $r, int $uid, string $kaynak): void {
    $kod = (string)($r['kod'] ?? '');
    if ($kod === 'cikis') {
        audit_log_event('pin_cikis', 'auth', null, null, null, $uid);
        logout_user();
        ekran_kilit_temizle();
        ekran_kilit_hata_json((string)$r['mesaj'], 'cikis', 401, ['cikis' => true, 'login' => true]);
    }
    if ($kod === 'pasif') ekran_kilit_hata_json((string)$r['mesaj'], 'pasif', 503);
    if ($kod === 'bekle') {
        if (empty($r['engelli'])) audit_log_event('pin_basarisiz', 'auth', null, null, ['kaynak' => $kaynak], $uid);   // bekleme sırasındaki denemeler audit'i şişirmesin
        ekran_kilit_hata_json((string)$r['mesaj'], 'bekle', 429, ['bekle' => (int)$r['bekle'], 'engelli' => !empty($r['engelli'])]);
    }
    if ($kod === 'yanlis') audit_log_event('pin_basarisiz', 'auth', null, null, ['kaynak' => $kaynak], $uid);
    $http = $kod === 'var' || $kod === 'yok' ? 409 : 422;
    ekran_kilit_hata_json((string)$r['mesaj'], $kod, $http);
}

if ($islem === 'nabiz') {
    $durum = ekran_pin_durum($pdo, $uid);
    $kilitli = $durum === 'var' && ekran_kilitli_mi();
    if ($kilitli) ekran_kilitle();   // çerez süresini tazeler
    ekran_kilit_json(['ok' => true, 'kilitli' => $kilitli, 'pin' => $durum]);
}

if ($islem === 'kilitle') {
    $durum = ekran_pin_durum($pdo, $uid);
    if ($durum === 'pasif') ekran_kilit_hata_json('PIN özelliği şu an kullanılamıyor.', 'pasif', 503);
    if ($durum === 'yok') ekran_kilit_hata_json('Önce bir PIN belirlemeniz gerekiyor.', 'yok', 409);
    if (!ekran_kilitle()) ekran_kilit_hata_json('PIN özelliği şu an kullanılamıyor.', 'pasif', 503);
    audit_log_event('ekran_kilit', 'auth', null, null, ['neden' => 'hareketsizlik']);
    ekran_kilit_json(['ok' => true, 'kilitli' => true]);
}

if ($islem === 'ac') {
    $r = ekran_kilit_ac($pdo, $uid, $s('pin'));
    if (!empty($r['ok'])) {
        audit_log_event('pin_ac', 'auth');
        ekran_kilit_json(['ok' => true]);
    }
    ekran_kilit_hata_yanit($r, $uid, 'kilit');
}

if ($islem === 'kur') {
    // Ekran kilitliyken (ör. yönetici PIN'i sıfırladı) PIN kurulamaz: kilitli ekran başındaki biri yeni PIN belirleyip içeri giremesin.
    if (ekran_kilitli_mi()) {
        ekran_kilit_json(['ok' => false, 'error' => 'Ekran kilitli. Çıkış yapıp şifrenizle tekrar giriş yapın, sonra yeni PIN belirleyin.', 'kod' => 'kilitli'], 409);
    }
    $r = ekran_pin_kur($pdo, $uid, $s('pin'), $s('pin2'));
    if (!empty($r['ok'])) {
        audit_log_event('pin_kur', 'auth');
        ekran_kilit_json(['ok' => true]);
    }
    ekran_kilit_hata_yanit($r, $uid, 'kur');
}

if ($islem === 'degistir') {
    $r = ekran_pin_degistir($pdo, $uid, [
        'mevcut' => $s('mevcut'), 'sifre' => $s('sifre'), 'pin' => $s('pin'), 'pin2' => $s('pin2'),
    ]);
    if (!empty($r['ok'])) {
        audit_log_event('pin_degistir', 'auth', null, null, ['yontem' => $r['yontem']]);
        ekran_kilit_json(['ok' => true]);
    }
    ekran_kilit_hata_yanit($r, $uid, 'degistir');
}

ekran_kilit_hata_json('Geçersiz istek.', 'istek', 400);
