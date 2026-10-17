<?php
// =========================================================
// siparis_beyan_bagla.php — Sevkiyat ↔ gümrük beyanı bağı kur / kaldır (POST)
// Kapı: siparis.match VE can_beyan('read') (beyan görmeyen biri bağ kuramaz). customs_declarations'a YAZMAZ.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/siparis_beyan.php';
require_once __DIR__ . '/_siparis_ortak.php';
$auth_user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: siparisler.php'); exit; }
csrf_check($_POST['csrf'] ?? null);
require_siparis('match');
if (!can_beyan('read')) forbidden('Beyan bağı için beyan görüntüleme yetkisi de gerekir. (Gerekli yetki: beyan.read)');

$pdo = db();
$uid = (int)$auth_user['id'];
$git = function (string $url): void { header('Location: ' . $url); exit; };
if (!siparis_sema_hazir($pdo)) { set_flash('error', 'Sipariş modülü henüz kurulmadı.'); $git('siparisler.php'); }

$islem = (string)($_POST['islem'] ?? '');
if ($islem === 'bagla') {
    $sid = (int)($_POST['sid'] ?? 0);
    $sev = siparis_sevkiyat($pdo, $sid);
    if ($sev === null) { set_flash('error', 'Sevkiyat bulunamadı.'); $git('siparisler.php'); }
    if (!siparis_gorunur($sev)) forbidden('Bu sevkiyat aktif depoda görünmüyor.');
    $tur = ($_POST['tur'] ?? '') === 'oneri' ? 'oneri' : 'elle';
    $r = siparis_beyan_bagla($pdo, $sid, (int)($_POST['beyan_id'] ?? 0), $uid, $tur, (string)($_POST['gerekce'] ?? ''));
    set_flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Beyan sevkiyata bağlandı.' : (string)($r['hata'] ?? 'Bağ kurulamadı.'));
    $git('siparis_view.php?id=' . $sid . '#beyan');
}
if ($islem === 'kaldir') {
    $bagId = (int)($_POST['bag_id'] ?? 0);
    $st = $pdo->prepare('SELECT sevkiyat_id FROM siparis_beyan WHERE id = ?');
    $st->execute([$bagId]);
    $sid = (int)($st->fetchColumn() ?: 0);
    $sev = $sid > 0 ? siparis_sevkiyat($pdo, $sid) : null;
    if ($sev === null) { set_flash('error', 'Bağ bulunamadı.'); $git('siparisler.php'); }
    if (!siparis_gorunur($sev)) forbidden('Bu sevkiyat aktif depoda görünmüyor.');
    $r = siparis_beyan_bag_kaldir($pdo, $bagId, $uid);
    set_flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Beyan bağı kaldırıldı.' : (string)($r['hata'] ?? 'Bağ kaldırılamadı.'));
    $git('siparis_view.php?id=' . $sid . '#beyan');
}
set_flash('error', 'Geçersiz işlem.');
$git('siparisler.php');
