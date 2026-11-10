<?php
// =========================================================
// frugro_yukle.php — Frugro Siparişler: Order Log xlsx yükleme (POST multipart, JSON yanıt)
//
// POST dosya[] (xlsx, en çok 10 × 8 MB) + csrf      siparis.write (OGL ile aynı kapı)
// Dosya DİSKE KAYDEDİLMEZ: geçici yükleme dosyası frg_xlsx_oku() ile okunur (yalnız sha256 alınır), her PO
// frg_toplu_kaydet() ile TEK yoldan yazılır (yeni / zaten / fark). Yanıt: dosya başına sayaç + PO sonuçları + uyarılar.
// Ad TEK alt çizgili ek içerir: sw.js bypass regex'i (frugro(_[a-z]+)?.php) bunu önbelleğe almaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/frugro_core.php';
require_once __DIR__ . '/config/frugro_ui.php';

$auth_user = require_login();
siparis_no_store();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$yanit = function (int $kod, array $veri): void {
    http_response_code($kod);
    echo json_encode($veri, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { header('Allow: POST'); $yanit(405, ['ok' => false, 'hata' => 'Geçersiz istek.']); }
csrf_check($_POST['csrf'] ?? null);   // JSON-aware: hatada 403 + JSON
if (!frgui_yetki('create')) $yanit(403, ['ok' => false, 'hata' => 'Bu işlem için yetkiniz yok.']);
try { enforce_active_depot(); } catch (Throwable $e) { $yanit(403, ['ok' => false, 'hata' => 'Aktif depo seçilmeli.']); }
$pdo = db();
if (!frg_hazir($pdo)) $yanit(409, ['ok' => false, 'hata' => 'Frugro Siparişler henüz kurulmadı (Şema Migrasyon Paneli).']);

// $_FILES['dosya'] tek ya da çoklu (dosya[]) olabilir → düz liste
$liste = [];
$f = $_FILES['dosya'] ?? null;
if (is_array($f)) {
    if (is_array($f['name'] ?? null)) {
        foreach (array_keys($f['name']) as $i) {
            $liste[] = ['name' => (string)$f['name'][$i], 'tmp_name' => (string)$f['tmp_name'][$i], 'error' => (int)$f['error'][$i], 'size' => (int)$f['size'][$i]];
        }
    } else {
        $liste[] = ['name' => (string)($f['name'] ?? ''), 'tmp_name' => (string)($f['tmp_name'] ?? ''), 'error' => (int)($f['error'] ?? UPLOAD_ERR_NO_FILE), 'size' => (int)($f['size'] ?? 0)];
    }
}
if (!$liste) $yanit(400, ['ok' => false, 'hata' => 'Dosya seçilmedi.']);
if (count($liste) > 10) $yanit(400, ['ok' => false, 'hata' => 'Tek seferde en çok 10 dosya yüklenebilir.']);

$uid = (int)$auth_user['id'];
$ozet = ['yeni' => 0, 'zaten' => 0, 'fark' => 0, 'hata' => 0];
$dosyalar = [];
foreach ($liste as $d) {
    $ad = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $d['name']))) ?? '', 0, 120);
    $kayit = ['ad' => $ad, 'ok' => false, 'hata' => null, 'sayac' => [], 'sonuclar' => [], 'uyarilar' => []];
    $yuklendi = $d['error'] === UPLOAD_ERR_OK && $d['tmp_name'] !== ''
        && (PHP_SAPI === 'cli' ? is_file($d['tmp_name']) : is_uploaded_file($d['tmp_name']));
    if (!$yuklendi) { $kayit['hata'] = $d['error'] === UPLOAD_ERR_INI_SIZE || $d['error'] === UPLOAD_ERR_FORM_SIZE ? 'Dosya çok büyük.' : 'Dosya alınamadı.'; $ozet['hata']++; $dosyalar[] = $kayit; continue; }
    if (strtolower(pathinfo($ad, PATHINFO_EXTENSION)) !== 'xlsx') { $kayit['hata'] = 'Yalnız .xlsx dosyası yüklenebilir.'; $ozet['hata']++; $dosyalar[] = $kayit; continue; }
    if ($d['size'] > 8 * 1024 * 1024) { $kayit['hata'] = 'Dosya 8 MB sınırını aşıyor.'; $ozet['hata']++; $dosyalar[] = $kayit; continue; }
    try {
        $okuma = frg_xlsx_oku($d['tmp_name']);
        if (!$okuma['ok']) { $kayit['hata'] = (string)($okuma['hata'] ?? 'Dosya okunamadı.'); $ozet['hata']++; $dosyalar[] = $kayit; continue; }
        $sonuc = frg_toplu_kaydet($pdo, $okuma, ['kaynak' => 'yukleme', 'user_id' => $uid]);
    } catch (Throwable $e) {
        error_log('[frugro_yukle] ' . $e->getMessage());
        $kayit['hata'] = 'Dosya işlenirken hata oluştu (ayrıntı sunucu günlüğünde).'; $ozet['hata']++; $dosyalar[] = $kayit; continue;
    }
    $kayit['ok'] = true;
    $kayit['sayac'] = $sonuc['sayac'];
    foreach ($sonuc['sonuclar'] as $r) {
        $kayit['sonuclar'][] = ['po_no' => (string)$r['po_no'], 'id' => $r['id'], 'durum' => (string)$r['durum'], 'mesaj' => (string)$r['mesaj'],
            'farklar' => array_map(static fn($x) => ['alan' => (string)$x['alan'], 'eski' => (string)$x['eski'], 'yeni' => (string)$x['yeni']], $r['farklar'])];
        $k = (string)$r['durum'];
        if ($k === 'olustu') $ozet['yeni']++;
        elseif ($k === 'zaten' || $k === 'silinmis') $ozet['zaten']++;
        elseif ($k === 'fark' || $k === 'guncellendi') $ozet['fark']++;
        else $ozet['hata']++;
    }
    $kayit['uyarilar'] = array_values(array_map('strval', $okuma['uyarilar']));
    $dosyalar[] = $kayit;
}
audit_log_event('frugro_yukle', 'frugro_siparis', null, null, ['dosya_sayisi' => count($liste), 'yeni' => $ozet['yeni'], 'zaten' => $ozet['zaten'], 'fark' => $ozet['fark'], 'hata' => $ozet['hata']]);
$yanit(200, ['ok' => true, 'ozet' => $ozet, 'dosyalar' => $dosyalar]);
