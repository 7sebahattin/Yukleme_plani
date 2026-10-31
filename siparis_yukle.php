<?php
// =========================================================
// siparis_yukle.php — Çoklu belge yükleme (POST; siparis.write)
//
// Akış: post_max_size koruması (CSRF'ten ÖNCE, hiçbir şey değiştirmez) → csrf_check → kapı →
// (varsa) hedef sevkiyat görünürlüğü → her dosya: siparis_dosya_yukle_isle()
// → oturuma özet + PRG. Belge türü DOSYA ADINDAN DEĞİL içerikten anlaşılır.
// Dosya başına iş siparis_dosya_yukle_isle() (kaydet + işle + audit) — mailden alma (siparis_mail.php) ile ORTAK.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/siparis_eslestir.php';
require_once __DIR__ . '/_siparis_ortak.php';
$auth_user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: siparisler.php'); exit; }

// Hedef sevkiyat (yalnız yönlendirme için sayı; yetki aşağıda doğrulanır). POST gövdesi kayıpsa query'den okunur.
$hedefId = (int)($_POST['sevkiyat_id'] ?? $_GET['sevkiyat_id'] ?? 0);
$donus = $hedefId > 0 ? 'siparis_view.php?id=' . $hedefId . '#belgeler' : 'siparisler.php#yukle';

// post_max_size aşıldıysa PHP $_POST/$_FILES'ı BOŞALTIR: csrf_check "güvenlik hatası" derdi — kullanıcıya gerçek nedeni söyle.
$icerikUzunluk = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$postMax = sipui_ini_bayt((string)ini_get('post_max_size'));
if (($postMax > 0 && $icerikUzunluk > $postMax) || ($icerikUzunluk > 0 && !$_POST && !$_FILES)) {
    $mb = $postMax > 0 ? max(1, (int)floor($postMax / 1048576)) : 20;
    set_flash('error', 'Seçilen dosyalar çok büyük (toplam en çok ' . $mb . ' MB). Daha az dosya seçin.');
    header('Location: ' . $donus);
    exit;
}

csrf_check($_POST['csrf'] ?? null);
require_siparis('write');
if (!role_matrix_page_can('siparis','ogl', $hedefId > 0 ? 'edit' : 'create')) forbidden('Bu işlem için yetkiniz yok.');
$pdo = db();
if (!siparis_sema_hazir($pdo)) { set_flash('error', 'Sipariş modülü henüz kurulmadı.'); header('Location: siparisler.php'); exit; }
$uid = (int)$auth_user['id'];

$hedef = null;
if ($hedefId > 0) {
    $hedef = siparis_sevkiyat($pdo, $hedefId);
    if ($hedef === null) { set_flash('error', 'Sevkiyat bulunamadı.'); header('Location: siparisler.php'); exit; }
    if (!siparis_gorunur($hedef)) forbidden('Bu sevkiyat aktif depoda görünmüyor.');
}

// $_FILES['belgeler'] düzleştirme
$dosyalar = [];
$f = $_FILES['belgeler'] ?? null;
if (is_array($f) && is_array($f['name'] ?? null)) {
    foreach (array_keys($f['name']) as $i) {
        if ((int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $dosyalar[] = ['name' => (string)$f['name'][$i], 'type' => (string)($f['type'][$i] ?? ''), 'tmp_name' => (string)($f['tmp_name'][$i] ?? ''),
                       'error' => (int)$f['error'][$i], 'size' => (int)($f['size'][$i] ?? 0)];
    }
} elseif (is_array($f) && isset($f['name']) && (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $dosyalar[] = $f;
}
if (!$dosyalar) { set_flash('error', 'Dosya seçilmedi.'); header('Location: ' . $donus); exit; }
if (count($dosyalar) > SIPARIS_MAX_DOSYA_ADET) {
    set_flash('error', 'Bir seferde en çok ' . SIPARIS_MAX_DOSYA_ADET . ' dosya yüklenebilir.');
    header('Location: ' . $donus);
    exit;
}
@set_time_limit(120);

$depo = $hedef !== null ? (string)$hedef['depo'] : (string)(active_depot() ?? '');
$moverFn = PHP_SAPI === 'cli' ? 'copy' : null;   // web'de move_uploaded_file; CLI (testler) move_uploaded_file'ı kullanamaz
$sonuclar = [];
$say = ['ok' => 0, 'hata' => 0];
foreach ($dosyalar as $dosya) {
    // v378: gövde ORTAK fonksiyonda (config/siparis_eslestir.php) — mailden alma yolu aynısını çağırır; burada kopya YOK
    $r = siparis_dosya_yukle_isle($pdo, $dosya, $uid, $depo, $hedef, $moverFn);
    if (in_array($r['durum'], ['islendi', 'zaten'], true)) $say['ok']++; else $say['hata']++;
    $sonuclar[] = $r;
}

$_SESSION['siparis_yukleme'] = $sonuclar;
if ($say['ok'] > 0 && $say['hata'] === 0) set_flash('success', $say['ok'] . ' dosya işlendi.');
elseif ($say['ok'] > 0) set_flash('success', $say['ok'] . ' dosya işlendi, ' . $say['hata'] . ' dosyada sorun var (aşağıya bakın).');
else set_flash('error', 'Hiçbir dosya işlenemedi (ayrıntı aşağıda).');
header('Location: ' . ($hedef !== null ? 'siparis_view.php?id=' . (int)$hedef['id'] . '#sonuc' : 'siparisler.php#sonuc'));
exit;
