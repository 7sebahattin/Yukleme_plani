<?php
// =========================================================
// siparis_yukle.php — Çoklu belge yükleme (POST; siparis.write)
//
// Akış: post_max_size koruması (CSRF'ten ÖNCE, hiçbir şey değiştirmez) → csrf_check → kapı →
// (varsa) hedef sevkiyat görünürlüğü → her dosya: siparis_dosya_kaydet() + siparis_belge_isle()
// → oturuma özet + PRG. Belge türü DOSYA ADINDAN DEĞİL içerikten anlaşılır.
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
    $r = ['dosya' => siparis_kirp(basename(str_replace('\\', '/', $dosya['name'])), 120), 'durum' => 'hata', 'tur' => null, 'sevkiyatlar' => [], 'catisma' => 0, 'uyari' => 0, 'mesajlar' => []];
    try {
        // Aynı içerik daha önce silinmiş miydi? (kaydet geri getirir; sevkiyat etkisi yeniden uygulanmalı)
        $silinmisti = false;
        if (is_file($dosya['tmp_name'])) {
            $sha = (string)hash_file('sha256', $dosya['tmp_name']);
            $o = $pdo->prepare('SELECT deleted_at FROM siparis_belgeler WHERE sha256 = ?');
            $o->execute([$sha]);
            $oncekiSatir = $o->fetch(PDO::FETCH_ASSOC);
            $silinmisti = $oncekiSatir && $oncekiSatir['deleted_at'] !== null;
        }
        $k = siparis_dosya_kaydet($pdo, $dosya, $uid, $depo, $moverFn);
        if (!$k['ok']) { $r['hata'] = (string)($k['hata'] ?? 'Dosya kaydedilemedi.'); $sonuclar[] = $r; $say['hata']++; continue; }
        $belgeId = (int)$k['belge_id'];
        $b = $pdo->prepare('SELECT id, depo, durum, tur, parser_surum FROM siparis_belgeler WHERE id = ?');
        $b->execute([$belgeId]);
        $bs = $b->fetch(PDO::FETCH_ASSOC) ?: ['id' => $belgeId, 'depo' => '', 'durum' => 'yuklendi', 'tur' => 'bilinmiyor', 'parser_surum' => null];
        $baglar = $pdo->prepare('SELECT s.id, s.order_ref FROM siparis_belge_bag bb JOIN siparis_sevkiyatlar s ON s.id = bb.sevkiyat_id WHERE bb.belge_id = ? AND s.deleted_at IS NULL');
        $baglar->execute([$belgeId]);
        $mevcutBaglar = $baglar->fetchAll(PDO::FETCH_ASSOC);
        $hedefeBagli = $hedef === null || in_array((int)$hedef['id'], array_map('intval', array_column($mevcutBaglar, 'id')), true);
        // v375: aynı dosya eski okuyucuyla okunmuşsa (parser_surum ≠ güncel) yeniden okunur — aynı belge id, ikinci satır YOK
        $yenidenOku = !$k['yeni'] && !$silinmisti && siparis_belge_surum_eski($bs) && siparis_belge_gorunur($pdo, $bs);
        $eskiSurum = $yenidenOku ? (string)$bs['parser_surum'] : null;
        $isle = $k['yeni'] || $silinmisti || $bs['durum'] !== 'islendi' || !$hedefeBagli || $yenidenOku;
        $r['tur'] = (string)$bs['tur'];
        if (!$isle) {
            $r['durum'] = 'zaten';
            foreach ($mevcutBaglar as $m) $r['sevkiyatlar'][] = ['id' => (int)$m['id'], 'order_ref' => (string)$m['order_ref'], 'yeni' => false];
            $say['ok']++;
            $sonuclar[] = $r;
            continue;
        }
        $x = siparis_belge_isle($pdo, $belgeId, $uid, $hedef !== null ? (int)$hedef['id'] : null);
        $r['tur'] = (string)$x['tur'];
        if (!$x['ok']) {
            $r['hata'] = (string)($x['hata'] ?? 'Belge işlenemedi.');
            $say['hata']++;
        } else {
            $r['durum'] = 'islendi';
            if ($yenidenOku) { $r['yeniden'] = 1; $r['eski_surum'] = $eskiSurum; }
            $r['sevkiyatlar'] = $x['sevkiyatlar'];
            $r['catisma'] = (int)$x['catisma'];
            $r['uyari'] = (int)$x['uyari'];
            $r['mesajlar'] = array_slice($x['mesajlar'], 0, 8);
            $say['ok']++;
        }
        audit_log_event('siparis_belge_yukle', 'siparis', $belgeId, null, [
            'belge_id' => $belgeId, 'tur' => $r['tur'], 'yeni' => $k['yeni'] ? 1 : 0, 'ok' => $x['ok'] ? 1 : 0,
            'sevkiyat_ids' => array_map(fn($s) => (int)$s['id'], $x['sevkiyatlar']),
        ] + ($yenidenOku ? ['islem' => 'surum_yenile', 'eski_surum' => $eskiSurum] : ['islem' => 'yukle']));
    } catch (Throwable $e) {
        error_log('[siparis_yukle] ' . get_class($e) . ': ' . $e->getMessage());
        $r['hata'] = 'Dosya işlenemedi. Teknik ayrıntılar sunucu günlüğüne kaydedildi.';
        $say['hata']++;
    }
    $sonuclar[] = $r;
}

$_SESSION['siparis_yukleme'] = $sonuclar;
if ($say['ok'] > 0 && $say['hata'] === 0) set_flash('success', $say['ok'] . ' dosya işlendi.');
elseif ($say['ok'] > 0) set_flash('success', $say['ok'] . ' dosya işlendi, ' . $say['hata'] . ' dosyada sorun var (aşağıya bakın).');
else set_flash('error', 'Hiçbir dosya işlenemedi (ayrıntı aşağıda).');
header('Location: ' . ($hedef !== null ? 'siparis_view.php?id=' . (int)$hedef['id'] . '#sonuc' : 'siparisler.php#sonuc'));
exit;
