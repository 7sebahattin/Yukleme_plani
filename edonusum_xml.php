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
$kaynak = (string)($_GET['kaynak'] ?? 'giden');
$format = strtolower((string)($_GET['format'] ?? 'xml'));

if ($kaynak === 'gelen') {
    $stG = $pdo->prepare('SELECT * FROM `edonusum_gelen` WHERE id = ?');
    $stG->execute([$id]);
    $zarf = $stG->fetch(PDO::FETCH_ASSOC) ?: null;
} else {
    $zarf = edonusum_zarf_getir($pdo, $id);
}

if (!$zarf) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'e-Dönüşüm belgesi bulunamadı.';
    exit;
}

$safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)$zarf['ettn']);
if ($safeName === '') $safeName = 'belge_' . $id;

if ($format === 'pdf') {
    $prov = edonusum_provider(null, $pdo);
    if (!($prov instanceof EdonusumElogoProvider)) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'PDF indirme yalnızca e-Logo sağlayıcısı aktifken kullanılabilir.';
        exit;
    }
    $pdfRes = $prov->belgePdfGetir((string)$zarf['ettn'], (string)($zarf['belge_turu'] ?? 'efatura'));
    if (empty($pdfRes['ok']) || empty($pdfRes['pdf'])) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo (string)($pdfRes['hata'] ?? 'PDF e-Logo üzerinden indirilemedi.');
        exit;
    }
    header('Content-Type: application/pdf');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . $safeName . '.pdf"');
    echo (string)$pdfRes['pdf'];
    exit;
}

if (trim((string)($zarf['ubl_xml'] ?? '')) === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'UBL-TR XML bulunamadı.';
    exit;
}

header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . $safeName . '.xml"');
echo (string)$zarf['ubl_xml'];
exit;
