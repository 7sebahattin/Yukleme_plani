<?php
// =========================================================
// frugro_beyan.php — Frugro sipariş ↔ gümrük beyanı bağla / kaldır (yalnız POST, PRG → frugro_view.php#beyan)
// islem=bagla (id, beyan_id, tur=oneri|elle) · islem=kaldir (id, beyan_id)
// Kapı: CSRF + siparis.write + siparis.match + beyan.read + frgui_yetki('edit') + aktif depo. Kapalı/evrak/iptal siparişte de açık (v452).
// customs_declarations'a YALNIZ SELECT; audit'e yalnız id/bayrak (parti/plaka değeri YOK).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/frugro_core.php';
require_once __DIR__ . '/config/frugro_ui.php';
require_once __DIR__ . '/config/frugro_beyan.php';

$auth_user = require_login();
siparis_no_store();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { header('Allow: POST'); http_response_code(405); echo 'Geçersiz istek.'; exit; }
csrf_check($_POST['csrf'] ?? null);
require_siparis('write');
if (!can_siparis('match') || !can_beyan('read')) forbidden('Beyan bağı için siparis.match ve beyan.read yetkileri gerekir.');
if (!frgui_yetki('edit')) forbidden('Bu işlem için düzenleme yetkiniz yok.');
try { enforce_active_depot(); } catch (Throwable $e) { forbidden('Aktif depo seçilmeli.'); }
$pdo = db();
if (!frg_hazir($pdo)) { set_flash('error', 'Frugro Siparişler henüz kurulmadı.'); header('Location: frugro_siparis.php', true, 303); exit; }

$id = (int)($_POST['id'] ?? 0);
$s = $id > 0 ? frg_getir($pdo, $id) : null;
if ($s === null || !frg_gorunur($s)) { set_flash('error', 'Sipariş bulunamadı.'); header('Location: frugro_siparis.php', true, 303); exit; }
$uid = (int)$auth_user['id'];
$beyanId = (int)($_POST['beyan_id'] ?? 0);
$islem = (string)($_POST['islem'] ?? '');
if ($islem === 'bagla') {
    $tur = ($_POST['tur'] ?? '') === 'oneri' ? 'oneri' : 'elle';
    $r = frgb_bagla($pdo, $s, $beyanId, $uid, $tur);
    if ($r['ok']) audit_log_event('frugro_beyan_bagla', 'frugro_siparis', $id, null, ['beyan_id' => $beyanId, 'tur' => $tur, 'parti_dolduruldu' => !empty($r['parti_dolduruldu'])]);
    set_flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Beyan siparişe bağlandı.' . (!empty($r['parti_dolduruldu']) ? ' Parti no beyandan dolduruldu.' : '') : (string)($r['hata'] ?? 'Bağ kurulamadı.'));
} elseif ($islem === 'kaldir') {
    $r = frgb_kaldir($pdo, $s, $beyanId, $uid);
    if ($r['ok']) audit_log_event('frugro_beyan_kaldir', 'frugro_siparis', $id, null, ['beyan_id' => $beyanId, 'parti_temizlendi' => !empty($r['parti_temizlendi'])]);
    set_flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Beyan bağı kaldırıldı.' . (!empty($r['parti_temizlendi']) ? ' Beyandan gelen parti no temizlendi.' : '') : (string)($r['hata'] ?? 'Bağ kaldırılamadı.'));
} else {
    set_flash('error', 'Geçersiz işlem.');
}
header('Location: frugro_view.php?id=' . $id . '#beyan', true, 303);
exit;
