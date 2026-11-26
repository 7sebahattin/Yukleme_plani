<?php
// =========================================================
// bi_rapor_kaydet.php — ERP Faz 5 BI Şablon & Zamanlama yazma ucu (yalnız POST; GET → 405).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/bi_rapor_core.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Yalnızca POST.';
    exit;
}

$auth_user = require_login();
bi_no_store();
$json = bi_json_istek();
if ($json) header('Content-Type: application/json; charset=utf-8');
csrf_check($_POST['csrf'] ?? null);

$pdo   = db();
$P     = $_POST;
$islem = trim((string)($P['islem'] ?? ''));
$geri  = bi_geri_guvenli((string)($P['geri'] ?? 'rapor_tanimlari.php'));
$uid   = (int)($auth_user['id'] ?? 0);
$id    = (int)($P['id'] ?? 0);

try {
    switch ($islem) {
        case 'bi_sablon_kaydet':
            bi_kapi('create');
            bi_rapor_sayfa_kapisi($pdo, $json);
            $s = bi_sablon_kaydet($pdo, $P, $uid);
            bi_cevap($s, $geri, 'BI rapor şablonu kaydedildi.', $json);

        case 'bi_sablon_sil':
            bi_kapi('delete');
            bi_rapor_sayfa_kapisi($pdo, $json);
            $s = bi_sablon_sil($pdo, $id, $uid);
            bi_cevap($s, $geri, 'BI rapor şablonu silindi.', $json);

        case 'bi_zamanlama_kaydet':
            bi_kapi('create');
            bi_rapor_sayfa_kapisi($pdo, $json);
            $s = bi_zamanlama_kaydet($pdo, $P, $uid);
            bi_cevap($s, $geri, 'Zamanlanmış rapor dağıtımı eklendi.', $json);

        default:
            bi_cevap(['ok' => false, 'hata' => 'Bilinmeyen işlem.', 'kod' => 422], $geri, '', $json);
    }
} catch (Throwable $e) {
    error_log('[bi_rapor_kaydet] ' . $e->getMessage());
    bi_cevap(['ok' => false, 'hata' => 'İşlem sırasında beklenmeyen bir hata oluştu.', 'kod' => 500], $geri, '', $json);
}
