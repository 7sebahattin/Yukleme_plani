<?php
// =========================================================
// mail_beyan_taslak.php — Mail → "Beyan Taslağı Oluştur" ucu (Dalga 3A)
//
// POST + CSRF, yalnız mail id'si alınır (mail metni URL'ye/forma TAŞINMAZ); mail hesap ACL'iyle sunucuda
// yeniden okunur, metin oturumda tek kullanımlık saklanır ve beyan_create.php?mail_taslak=1'e yönlendirilir.
// HİÇBİR ŞEY KAYDEDİLMEZ — kullanıcı beyan formunu gözden geçirip Kaydet'e kendisi basar.
// Ayrıntı: config/mail_beyan.php
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/mail_core.php';
require_once __DIR__ . '/config/mail_view.php';
require_once __DIR__ . '/config/mail_beyan.php';
$auth_user = require_login();
require_mail('read');
mail_no_store();
if (!mail_beyan_taslak_yetkisi()) forbidden('Beyan taslağı için beyan oluşturma ve kaynak metin yetkisi gerekir.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {   // GET veri taşımaz/değiştirmez: okuyucuya dön
    header('Location: mail.php');
    exit;
}
csrf_check($_POST['csrf'] ?? null);

$pdo = db();
$uid = (int)$auth_user['id'];
$mId = (int)($_POST['m'] ?? 0);
$sn  = mail_sema_hazir($pdo)
    ? mail_beyan_taslak_hazirla($pdo, $mId, $uid, mail_gorunur_hesap_idleri($uid, $pdo))
    : ['ok' => false, 'mesaj' => 'Mail Merkezi kurulu değil.'];

if (!$sn['ok']) {
    set_flash('error', $sn['mesaj']);
    header('Location: mail.php' . ($mId > 0 ? '?m=' . $mId : ''));
    exit;
}
header('Location: beyan_create.php?mail_taslak=1');
exit;
