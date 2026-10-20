<?php
// =========================================================
// siparis_odeme.php — OGL ödemesi (genel cari tahsilat) ekle / iptal: TEK POST ucu (PRG → siparis_odemeler.php; v399, v401)
// Kapı: require_siparis('read') + siparis_cari_yetkili() + siparis_odeme_yetkili() (admin / hesap.pay). Mantık config/siparis_odeme.php.
// Ad TEK alt çizgili: sw.js bypass regex'i kapsar.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/_siparis_ortak.php';
require_once __DIR__ . '/config/siparis_cari.php';
$auth_user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: siparis_odemeler.php'); exit; }
csrf_check($_POST['csrf'] ?? null);
require_siparis('read');
if (!siparis_cari_yetkili() || !siparis_odeme_yetkili()) forbidden('Ödeme girişi için yetkiniz yok.');
siparis_no_store();

$pdo = db();
$uid = (int)$auth_user['id'];
$islem = (string)($_POST['islem'] ?? '');
if ($islem === 'ekle') {
    $r = siparis_odeme_ekle($pdo, $_POST, $uid);
} elseif ($islem === 'iptal') {
    $r = siparis_odeme_iptal($pdo, (int)($_POST['id'] ?? 0), (string)($_POST['neden'] ?? ''), $uid);
} else {
    $r = ['ok' => false, 'mesaj' => 'Geçersiz işlem.'];
}
set_flash($r['ok'] ? 'success' : 'error', $r['mesaj']);
header('Location: siparis_odemeler.php' . (preg_match('/^[0-9&=A-Za-z._-]{0,200}$/', (string)($_POST['qs'] ?? '')) && ($_POST['qs'] ?? '') !== '' ? '?' . $_POST['qs'] : ''));
exit;
