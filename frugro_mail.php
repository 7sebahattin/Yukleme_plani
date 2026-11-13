<?php
// =========================================================
// frugro_mail.php — Frugro "Mailden al" JSON ucu (POST; siparis.write + mail.read) — siparis_mail.php'nin Frugro eşi
//
// islem: baslat | devam {tarama_id} | vazgec {tarama_id}. Tek tık = tara + içe aktar; iş parçalı yürür (adım ≤ 20 sn / ≤ 6 ek)
// ve assets/frugro_mail.js döngüsüyle sürer. Mantık: config/frugro_mail.php (burada yalnız kapı + JSON).
// Dosya adı alt çizgili TEK ek olmalı: sw.js bypass regex'i (/siparis(ler|_[a-z]+)?\.php/) yalnız siparis* adlarını kapsar —
// bu uç POST olduğundan önbelleğe girmez; no-store başlığı yine de gönderilir.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/mail_core.php';
require_once __DIR__ . '/config/mail_imap.php';
require_once __DIR__ . '/config/mail_mime.php';
require_once __DIR__ . '/config/mail_sync.php';
require_once __DIR__ . '/config/mail_view.php';
require_once __DIR__ . '/config/mail_attach.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/siparis_mail.php';
require_once __DIR__ . '/config/frugro_core.php';
require_once __DIR__ . '/config/frugro_mail.php';
$auth_user = require_login();

mail_no_store();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
$json = static function (array $p, int $kod = 200): never {
    http_response_code($kod);
    unset($p['http']);
    if (!$p['ok'] && !isset($p['error'])) $p['error'] = (string)($p['mesaj'] ?? 'İşlem yapılamadı.');
    echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); $json(['ok' => false, 'mesaj' => 'Yalnız POST.'], 405); }

$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 4000) $json(['ok' => false, 'mesaj' => 'İstek çok uzun.'], 413);
$in = json_decode($raw, true);
if (!is_array($in)) $in = [];
csrf_check(is_string($in['csrf'] ?? null) ? $in['csrf'] : null);   // JSON-aware 403; ilk mutasyondan ÖNCE
require_siparis('write', 'frugro');
if (!frgm_yetkisi()) forbidden('Mail sorgusu için Mail Merkezi okuma yetkisi gerekir.');
$pdo = db();
if (!frgm_hazir($pdo)) $json(['ok' => false, 'kod' => 'kurulmadi', 'mesaj' => 'Frugro mail sorgusu kurulu değil (Frugro / Mail Merkezi şeması, MAIL_MASTER_KEY ya da hks_kv eksik).'], 409);

$uid = (int)$auth_user['id'];
$depo = (string)(active_depot() ?? '');
session_write_close();   // IMAP beklerken oturum kilidini tutma (mail_ek.php deseni)
ignore_user_abort(true); // sekme kapansa da başlamış adım biter
@set_time_limit(90);

$islem = (string)($in['islem'] ?? '');
$tid = is_string($in['tarama_id'] ?? null) ? (string)$in['tarama_id'] : '';
if ($islem === 'baslat') {
    $r = frgm_baslat($pdo, $uid, $depo);
} elseif ($islem === 'devam' && preg_match('/^[a-f0-9]{16}$/', $tid)) {
    $r = frgm_adim($pdo, $tid, $uid, $depo);
} elseif ($islem === 'vazgec' && preg_match('/^[a-f0-9]{16}$/', $tid)) {
    $r = frgm_vazgec($pdo, $tid, $uid);
} else {
    $json(['ok' => false, 'mesaj' => 'Geçersiz istek.'], 400);
}
if ($r['ok'] && !empty($r['bitti']) && isset($r['son'])) {
    // sonuç sunucuda çizilir (kullanıcı verisi h() ile kaçırılır); gönderen/konu yalnız izleyenin görünür hesaplarından
    $r['html'] = frgm_sonuc_html($pdo, $r['son'], mail_gorunur_hesap_idleri($uid, $pdo));
    unset($r['son']);
}
$json($r, (int)($r['http'] ?? 200));
