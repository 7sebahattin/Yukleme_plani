<?php
// =========================================================
// mail_ek.php — Mail eki indirme (GET; yalnız can_mail('read') + hesap ACL)
// Gövde diske yazılmaz; IMAP'tan o parça çekilip akıtılır. Mantık: config/mail_attach.php.
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
$auth_user = require_login();
require_mail('read');
mail_no_store();

$uid = (int)$auth_user['id'];
$pdo = db();
$hesapIds = mail_gorunur_hesap_idleri($uid, $pdo);
session_write_close();   // IMAP beklerken oturum kilidini tutma

$r = mail_ek_hazirla($pdo, (int)($_GET['m'] ?? 0), (string)($_GET['p'] ?? ''), $hesapIds);
if (!$r['ok']) {
    http_response_code($r['kod']);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $r['mesaj'];
    exit;
}
audit_log_event('mail_attachment_download', 'mail_messages', (int)($_GET['m'] ?? 0), null,
    ['parca' => (string)$_GET['p'], 'tehlikeli' => $r['tehlikeli'] ? 1 : 0]);

$ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $r['ad']) ?: 'ek';
header('Content-Type: ' . $r['mime']);
header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($r['ad']));
header('Content-Length: ' . strlen($r['veri']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header("Content-Security-Policy: sandbox; default-src 'none'");
echo $r['veri'];
