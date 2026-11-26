<?php
// =========================================================
// edonusum_xml.php — UBL-TR 1.2 XML indirme ucu (yalnız GET, yetkili kullanıcı)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/muhasebe_core.php';
require_once __DIR__ . '/config/edonusum_core.php';

$auth_user = require_login();
if (!muhasebe_sayfa_can('edonusum_belgeler')) { forbidden(); }
muh_kapi('read');
muh_no_store();
$pdo = db();
edonusum_sayfa_kapisi($pdo);

$id = (int)($_GET['id'] ?? 0);
$zarf = edonusum_zarf_getir($pdo, $id);
if (!$zarf || trim((string)($zarf['ubl_xml'] ?? '')) === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'UBL-TR XML bulunamadı.';
    exit;
}

$safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)$zarf['ettn']);
if ($safeName === '') $safeName = 'belge_' . $id;

header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . $safeName . '.xml"');
echo (string)$zarf['ubl_xml'];
exit;
