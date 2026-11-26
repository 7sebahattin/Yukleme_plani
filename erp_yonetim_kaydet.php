<?php
// =========================================================
// erp_yonetim_kaydet.php — ERP Faz 3 TÜM yazmaların TEK ucu (yalnız POST; GET → 405).
// Kapsam: Onay Akışları (ERP-5) · Banka Mutabakat/Kredi/POS (ERP-6) · İK Özlük/İzin/Bordro (ERP-7)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/muhasebe_core.php';
require_once __DIR__ . '/config/onay_core.php';
require_once __DIR__ . '/config/banka_core.php';
require_once __DIR__ . '/config/ik_bordro_core.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Yalnızca POST.';
    exit;
}

$auth_user = require_login();
erp_yon_no_store();
$json = erp_yon_json_istek();
if ($json) header('Content-Type: application/json; charset=utf-8');
csrf_check($_POST['csrf'] ?? null);

$pdo     = db();
$P       = $_POST;
$islem   = trim((string)($P['islem'] ?? ''));
$geri    = erp_yon_geri_guvenli((string)($P['geri'] ?? 'onay_merkezi.php'));
$uid     = (int)($auth_user['id'] ?? 0);
$id      = (int)($P['id'] ?? 0);
$isAdmin = (string)($auth_user['role'] ?? '') === 'admin';

try {
    switch ($islem) {
        // ── ERP-5: Onay Akışları ─────────────────────────────────────────────
        case 'onay_kural_kaydet':
            erp_yon_kapi($id > 0 ? 'edit' : 'create');
            onay_sayfa_kapisi($pdo, $json);
            $s = onay_kural_kaydet($pdo, $P, $uid);
            erp_yon_cevap($s, $geri, 'Onay kuralı kaydedildi.', $json);

        case 'onay_vekalet_kaydet':
            erp_yon_kapi('create');
            onay_sayfa_kapisi($pdo, $json);
            $s = onay_vekalet_kaydet($pdo, $P, $uid);
            erp_yon_cevap($s, $geri, 'Vekalet kaydı oluşturuldu.', $json);

        case 'onay_talep_olustur':
            erp_yon_kapi('create');
            onay_sayfa_kapisi($pdo, $json);
            $s = onay_talep_olustur($pdo, $P, $uid);
            erp_yon_cevap($s, $geri, 'Onay talebi başlatıldı.', $json);

        case 'onay_karar_ver':
            erp_yon_kapi('edit');
            onay_sayfa_kapisi($pdo, $json);
            $s = onay_karar_ver(
                $pdo,
                $id,
                (string)($P['karar'] ?? ''),
                (string)($P['not'] ?? ''),
                $uid,
                $isAdmin
            );
            erp_yon_cevap($s, $geri, 'Onay kararı işlendi.', $json);

        // ── ERP-6: Banka Mutabakat, Kredi & POS ──────────────────────────────
        case 'banka_hesap_kaydet':
            erp_yon_kapi($id > 0 ? 'edit' : 'create');
            banka_sayfa_kapisi($pdo, $json);
            $s = banka_hesap_kaydet($pdo, $P, $uid);
            erp_yon_cevap($s, $geri, 'Banka hesabı kaydedildi.', $json);

        case 'banka_ekstre_ice_aktar':
            erp_yon_kapi('create');
            banka_sayfa_kapisi($pdo, $json);
            $hesapId = (int)($P['hesap_id'] ?? 0);
            $s = banka_ekstre_ice_aktar($pdo, $hesapId, (string)($P['ham_metin'] ?? ''), $uid);
            $msg = 'Banka ekstresi içe aktarıldı.';
            if (!empty($s['ok'])) {
                $es = banka_ekstre_otomatik_eslestir($pdo, $hesapId, $uid);
                $s['eslesen'] = (int)($es['eslesen'] ?? 0);
                $msg = sprintf(
                    'Ekstre aktarıldı: %d yeni, %d mükerrer atlandı, %d fatura ile eşleşti.',
                    (int)($s['eklenen'] ?? 0),
                    (int)($s['atlanan'] ?? 0),
                    (int)$s['eslesen']
                );
            }
            erp_yon_cevap($s, $geri, $msg, $json);

        case 'banka_kredi_olustur':
            erp_yon_kapi('create');
            banka_sayfa_kapisi($pdo, $json);
            $s = banka_kredi_olustur($pdo, $P, $uid);
            erp_yon_cevap($s, $geri, 'Kredi ödeme planı oluşturuldu.', $json);

        case 'banka_kredi_taksit_ode':
            erp_yon_kapi('edit');
            banka_sayfa_kapisi($pdo, $json);
            $s = banka_kredi_taksit_ode($pdo, $id, $uid);
            erp_yon_cevap($s, $geri, 'Kredi taksiti ödendi ve muhasebeleştirildi.', $json);

        case 'banka_pos_kaydet':
            erp_yon_kapi('create');
            banka_sayfa_kapisi($pdo, $json);
            $s = banka_pos_kaydet($pdo, $P, $uid);
            erp_yon_cevap($s, $geri, 'POS hareketi kaydedildi.', $json);

        case 'banka_pos_bloke_coz':
            erp_yon_kapi('edit');
            banka_sayfa_kapisi($pdo, $json);
            $s = banka_pos_bloke_coz($pdo, $id, $uid);
            erp_yon_cevap($s, $geri, 'POS blokesi çözüldü ve banka bakiyesine eklendi.', $json);

        // ── ERP-7: İK Özlük, İzin & Resmi Bordro ─────────────────────────────
        case 'ik_ozluk_kaydet':
            erp_yon_kapi($id > 0 ? 'edit' : 'create');
            ik_bordro_sayfa_kapisi($pdo, $json);
            $s = ik_ozluk_kaydet($pdo, $P, $uid);
            erp_yon_cevap($s, $geri, 'Personel özlük kartı kaydedildi.', $json);

        case 'ik_izin_talep_et':
            erp_yon_kapi('create');
            ik_bordro_sayfa_kapisi($pdo, $json);
            $s = ik_izin_talep_et($pdo, $P, $uid);
            erp_yon_cevap($s, $geri, 'İzin talebi oluşturuldu.', $json);

        case 'ik_izin_karar':
            erp_yon_kapi('edit');
            ik_bordro_sayfa_kapisi($pdo, $json);
            $s = ik_izin_karar($pdo, $id, (string)($P['karar'] ?? ''), $uid);
            erp_yon_cevap($s, $geri, 'İzin talebi güncellendi.', $json);

        case 'ik_bordro_donem_uret':
            erp_yon_kapi('create');
            ik_bordro_sayfa_kapisi($pdo, $json);
            $s = ik_bordro_donem_uret($pdo, (int)($P['yil'] ?? date('Y')), (int)($P['ay'] ?? date('n')), $uid);
            if (!empty($s['ok']) && !empty($s['id'])) {
                $geri = 'ik_bordro.php?sekme=bordro&donem_id=' . (int)$s['id'];
            }
            erp_yon_cevap($s, $geri, 'Resmi bordro dönemi üretildi.', $json);

        case 'ik_bordro_muhasebelestir':
            erp_yon_kapi('edit');
            ik_bordro_sayfa_kapisi($pdo, $json);
            $s = ik_bordro_muhasebelestir($pdo, $id, $uid);
            erp_yon_cevap($s, $geri, 'Bordro tahakkuku 770/335/360/361 yevmiye fişine aktarıldı.', $json);

        default:
            erp_yon_cevap(['ok' => false, 'hata' => 'Bilinmeyen işlem.', 'kod' => 422], $geri, '', $json);
    }
} catch (Throwable $e) {
    error_log('[erp_yonetim_kaydet] ' . $e->getMessage());
    erp_yon_cevap(['ok' => false, 'hata' => 'İşlem sırasında beklenmeyen bir hata oluştu.', 'kod' => 500], $geri, '', $json);
}
