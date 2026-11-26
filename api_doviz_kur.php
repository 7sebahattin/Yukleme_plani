<?php
// =========================================================
// api_doviz_kur.php — Otonom kur sorgulama JSON ucu (yalnız GET, oturumlu)
//
// Kullanım:
//   ?kod=USD&tarih=2026-11-24[&tip=doviz_satis][&oto_cek=1]
//   ?liste=1[&tarih=2026-11-24][&yalniz_aktif=1]
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/doviz_core.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'hata' => 'Yalnızca GET.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_login();
doviz_no_store();
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
if (!doviz_hazir($pdo)) {
    echo json_encode(['ok' => false, 'hazir' => false, 'hata' => 'Kur tablosu kurulmamış'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ((string)($_GET['liste'] ?? '') === '1') {
        $tarihHam = trim((string)($_GET['tarih'] ?? ''));
        $tarih    = $tarihHam !== '' ? doviz_tarih_coz($tarihHam) : (doviz_son_tarih($pdo) ?? date('Y-m-d'));
        if ($tarih === null) {
            echo json_encode(['ok' => false, 'hata' => 'Geçersiz tarih.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $yalnizAktif = (string)($_GET['yalniz_aktif'] ?? '') === '1';
        $kurlar      = doviz_gunluk_liste($pdo, $tarih, $yalnizAktif);
        echo json_encode([
            'ok'     => true,
            'tarih'  => $tarih,
            'kurlar' => $kurlar,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $kod    = trim((string)($_GET['kod'] ?? ''));
    $tarih  = trim((string)($_GET['tarih'] ?? ''));
    $tip    = trim((string)($_GET['tip'] ?? ''));
    $otoCek = !isset($_GET['oto_cek']) || (string)$_GET['oto_cek'] !== '0';

    if ($kod === '') {
        echo json_encode(['ok' => false, 'hata' => 'Para birimi kodu (kod) zorunludur.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // B-42: Dış TCMB isteği (oto_cek) için tarih sınırı ve oturum başına hız sınırı (60 sn içinde en çok 15 çekim)
    if ($otoCek && $tarih !== '') {
        $cozTarih = doviz_tarih_coz($tarih);
        if ($cozTarih === null || $cozTarih < '2000-01-01' || $cozTarih > date('Y-m-d', strtotime('+7 days'))) {
            $otoCek = false;
        }
    }
    if ($otoCek) {
        app_session_start();
        $now = time();
        $gecmis = array_values(array_filter(
            (array)($_SESSION['_doviz_oto_ts'] ?? []),
            static fn($ts): bool => is_int($ts) && ($now - $ts) < 60
        ));
        if (count($gecmis) >= 15) {
            $otoCek = false;
        } else {
            $gecmis[] = $now;
            $_SESSION['_doviz_oto_ts'] = $gecmis;
        }
    }

    $sonuc = doviz_kur_bul($pdo, $kod, $tarih !== '' ? $tarih : null, $tip !== '' ? $tip : null, $otoCek);
    echo json_encode($sonuc, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api_doviz_kur] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'hata' => 'Kur sorgulanırken sunucu hatası oluştu.'], JSON_UNESCAPED_UNICODE);
}
