<?php
// =========================================================
// erp_plan_kaydet.php — ERP Faz 4 TÜM yazmaların TEK ucu (yalnız POST; GET → 405).
// Kapsam: CRM & Fiyat Listeleri (ERP-8) · Sabit Kıymet/Amortisman/Bakım (ERP-9) · Bütçe & Nakit Akış (ERP-10)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/muhasebe_core.php';
require_once __DIR__ . '/config/crm_core.php';
require_once __DIR__ . '/config/sabit_kiymet_core.php';
require_once __DIR__ . '/config/finans_plan_core.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Yalnızca POST.';
    exit;
}

$auth_user = require_login();
erp_plan_no_store();
$json = erp_plan_json_istek();
if ($json) header('Content-Type: application/json; charset=utf-8');
csrf_check($_POST['csrf'] ?? null);

$pdo   = db();
$P     = $_POST;
$islem = trim((string)($P['islem'] ?? ''));
$geri  = erp_plan_geri_guvenli((string)($P['geri'] ?? 'crm_yonetim.php'));
$uid   = (int)($auth_user['id'] ?? 0);
$id    = (int)($P['id'] ?? 0);

try {
    switch ($islem) {
        // ── ERP-8: CRM & Fiyat Listeleri ────────────────────────────────────
        case 'crm_firsat_kaydet':
            erp_plan_kapi('create');
            crm_sayfa_kapisi($pdo, $json);
            $s = crm_firsat_kaydet($pdo, $P, $uid);
            erp_plan_cevap($s, $geri, 'Satış fırsatı oluşturuldu.', $json);

        case 'crm_firsat_asama':
            erp_plan_kapi('edit');
            crm_sayfa_kapisi($pdo, $json);
            $s = crm_firsat_asama_guncelle($pdo, $id, (string)($P['asama'] ?? ''), $uid);
            erp_plan_cevap($s, $geri, 'Fırsat aşaması güncellendi.', $json);

        case 'crm_aktivite_ekle':
            erp_plan_kapi('create');
            crm_sayfa_kapisi($pdo, $json);
            $s = crm_aktivite_ekle($pdo, $P, $uid);
            erp_plan_cevap($s, $geri, 'Aktivite kaydedildi.', $json);

        case 'crm_fiyat_listesi_kaydet':
            erp_plan_kapi('create');
            crm_sayfa_kapisi($pdo, $json);
            $s = crm_fiyat_listesi_kaydet($pdo, $P, $uid);
            erp_plan_cevap($s, $geri, 'Fiyat listesi oluşturuldu.', $json);

        case 'crm_fiyat_satir_ekle':
            erp_plan_kapi('create');
            crm_sayfa_kapisi($pdo, $json);
            $s = crm_fiyat_satir_ekle($pdo, $P, $uid);
            erp_plan_cevap($s, $geri, 'Fiyat satırı eklendi.', $json);

        case 'crm_teklif_kaydet':
            erp_plan_kapi('create');
            crm_sayfa_kapisi($pdo, $json);
            $s = crm_teklif_kaydet($pdo, $P, $uid);
            erp_plan_cevap($s, $geri, 'Satış teklifi oluşturuldu.', $json);

        case 'crm_teklif_siparise':
            erp_plan_kapi('edit');
            crm_sayfa_kapisi($pdo, $json);
            $s = crm_teklif_siparise_donustur($pdo, $id, $uid);
            erp_plan_cevap($s, $geri, 'Teklif siparişe dönüştürüldü.', $json);

        case 'crm_claim_kaydet':
            erp_plan_kapi('create');
            crm_sayfa_kapisi($pdo, $json);
            $s = crm_claim_kaydet($pdo, $P, $uid);
            erp_plan_cevap($s, $geri, 'Kalite claim kaydı açıldı.', $json);

        case 'crm_claim_sonuclandir':
            erp_plan_kapi('edit');
            crm_sayfa_kapisi($pdo, $json);
            $s = crm_claim_sonuclandir(
                $pdo,
                $id,
                (string)($P['durum'] ?? ''),
                erp_plan_sayi($P['kabul_tutar'] ?? 0),
                (string)($P['cozum_notu'] ?? ''),
                $uid
            );
            erp_plan_cevap($s, $geri, 'Claim kaydı sonuçlandırıldı.', $json);

        // ── ERP-9: Sabit Kıymet, Amortisman & Bakım ─────────────────────────
        case 'sk_kart_kaydet':
            erp_plan_kapi('create');
            sabit_kiymet_sayfa_kapisi($pdo, $json);
            $s = sk_kart_kaydet($pdo, $P, $uid);
            erp_plan_cevap($s, $geri, 'Sabit kıymet ve amortisman planı kaydedildi.', $json);

        case 'sk_amortisman_muhasebelestir':
            erp_plan_kapi('edit');
            sabit_kiymet_sayfa_kapisi($pdo, $json);
            $s = sk_amortisman_muhasebelestir($pdo, $id, $uid);
            erp_plan_cevap($s, $geri, 'Amortisman yevmiye fişi kesildi.', $json);

        case 'sk_bakim_kaydet':
            erp_plan_kapi('create');
            sabit_kiymet_sayfa_kapisi($pdo, $json);
            $s = sk_bakim_kaydet($pdo, $P, $uid);
            erp_plan_cevap($s, $geri, 'Bakım kaydı eklendi.', $json);

        // ── ERP-10: Bütçe & Nakit Akış ──────────────────────────────────────
        case 'fp_butce_kaydet':
            erp_plan_kapi('create');
            finans_plan_sayfa_kapisi($pdo, $json);
            $s = fp_butce_kaydet($pdo, $P, $uid);
            erp_plan_cevap($s, $geri, 'Bütçe kalemi kaydedildi.', $json);

        case 'fp_nakit_kalem_kaydet':
            erp_plan_kapi('create');
            finans_plan_sayfa_kapisi($pdo, $json);
            $s = fp_nakit_kalem_kaydet($pdo, $P, $uid);
            erp_plan_cevap($s, $geri, 'Nakit akış kalemi kaydedildi.', $json);

        default:
            erp_plan_cevap(['ok' => false, 'hata' => 'Bilinmeyen işlem.', 'kod' => 422], $geri, '', $json);
    }
} catch (Throwable $e) {
    error_log('[erp_plan_kaydet] ' . $e->getMessage());
    erp_plan_cevap(['ok' => false, 'hata' => 'İşlem sırasında beklenmeyen bir hata oluştu.', 'kod' => 500], $geri, '', $json);
}
