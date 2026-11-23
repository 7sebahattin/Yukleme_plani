<?php
// =========================================================
// siparis_kesinti.php — OGL kesintisi (debit note) ELLE: ekle (PDF yükle ya da form) / sevkiyata bağla / iptal + PDF görüntüle (v529)
// POST (CSRF, multipart): islem = ekle | bagla | iptal → PRG siparis_odemeler.php#odDebit. Kapı ödeme girişiyle AYNI:
//   require_siparis('read') + siparis_cari_yetkili() + siparis_odeme_yetkili() (admin / hesap.pay).
// GET ?dosya=<belge no>: kesintiye yüklenmiş PDF (görüntüleme kapısı: require_siparis('read') + siparis_cari_yetkili()).
// Mantık config/siparis_debit.php (siparis_debit_elle_ekle / _bagla / _iptal); bu dosya fonksiyon TANIMLAMAZ.
// Ad TEK alt çizgili: sw.js bypass regex'i /siparis(ler|_[a-z]+)?\.php/ kapsar (finansal veri SW önbelleğine yazılmaz).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/_siparis_ortak.php';
require_once __DIR__ . '/config/siparis_cari.php';
require_once __DIR__ . '/config/siparis_debit.php';
$auth_user = require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    require_siparis('read');
    if (!siparis_cari_yetkili()) forbidden('Bu belgeye erişim yetkiniz yok.');
    icerik_kapi('siparis.odeme');
    $no = siparis_debit_no_coz((string)($_GET['dosya'] ?? ''));
    $d = $no !== null ? siparis_debit_dosya_yolu($pdo, $no) : null;
    if ($d === null) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: private, no-store'); echo 'Belge bulunamadı.'; exit; }
    $ascii = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '_', $d['ad']), '_') ?: 'kesinti.pdf';
    session_write_close();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($d['ad']));
    header('Content-Length: ' . (int)filesize($d['yol']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header("Content-Security-Policy: frame-ancestors 'self'");
    readfile($d['yol']);
    exit;
}

csrf_check($_POST['csrf'] ?? null);
require_siparis('read');
if (!siparis_cari_yetkili() || !siparis_odeme_yetkili()) forbidden('Kesinti girişi için yetkiniz yok.');
icerik_kapi('siparis.odeme');
siparis_no_store();

$uid = (int)$auth_user['id'];
$islem = (string)($_POST['islem'] ?? '');
$no = siparis_debit_no_coz((string)($_POST['no'] ?? '')) ?? '';
if ($islem === 'ekle') {
    $f = $_FILES['pdf'] ?? null;
    $r = siparis_debit_elle_ekle($pdo, $_POST, is_array($f) ? $f : null, $uid);
} elseif ($islem === 'bagla') {
    $r = $no !== '' ? siparis_debit_bagla($pdo, $no, (int)($_POST['sid'] ?? 0), $uid) : ['ok' => false, 'mesaj' => 'Belge no geçersiz.'];
} elseif ($islem === 'iptal') {
    $r = $no !== '' ? siparis_debit_iptal($pdo, $no, (string)($_POST['neden'] ?? ''), $uid) : ['ok' => false, 'mesaj' => 'Belge no geçersiz.'];
} else {
    $r = ['ok' => false, 'mesaj' => 'Geçersiz işlem.'];
}
set_flash($r['ok'] ? 'success' : 'error', (string)$r['mesaj']);
$qs = (string)($_POST['qs'] ?? '');
header('Location: siparis_odemeler.php' . (preg_match('/^[0-9&=A-Za-z._-]{1,200}$/', $qs) ? '?' . $qs : '') . '#odDebit');
exit;
