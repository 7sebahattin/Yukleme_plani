<?php
declare(strict_types=1);
// HKS Operasyon — ortak başlatma. ÇIKTIDAN ÖNCE include edilmeli (redirect yapabilir).
// Sağladıkları: $op_repo, $op_company_id, $op_settings, $op_company_name, $op_env, $op_queries_enabled
if (!defined('HKS_MODULE_VERSION')) {
    http_response_code(403); exit('Direct access not allowed.');
}

$op_repo = new HksRepository(db());

// Çağıran sayfa konumuna göre yol önekleri (operasyon/ içi varsayılan).
// teknik.php gibi hks/ kökündeki sayfalar bunları include'tan ÖNCE override edebilir.
$op_dir  = $op_dir  ?? '';     // operasyon/ dizinine göreli
$op_root = $op_root ?? '../';  // hks/ köküne göreli

// Firma seçili değilse ana giriş ekranına dön (firma seçimi orada yapılır)
$op_company_id = isset($_SESSION['hks_company_id']) ? (int)$_SESSION['hks_company_id'] : null;
$op_valid_ids  = array_column($op_repo->getAllCompanies(), 'id');
if ($op_company_id === null || !in_array($op_company_id, $op_valid_ids, false)) {
    header('Location: ' . $op_root . 'index.php'); exit;
}

$op_settings      = $op_repo->getSettings();
$op_company_name  = $op_settings['firma_adi'] ?? 'Firma';
$op_env           = $op_settings['environment'] ?? 'test';

// HKS canlı sorgular için ön koşul
$op_client          = new HksClient($op_repo);
$op_queries_enabled = $op_client->hasSettings() && hks_check_soap();
