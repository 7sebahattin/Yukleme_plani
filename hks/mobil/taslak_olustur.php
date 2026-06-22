<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/hks_bootstrap.php';
$auth_user = require_login();

// hks.write required to create a draft
if (!can('hks.write') && !is_admin()) {
    http_response_code(403); die('Erişim yetkiniz yok (hks.write).');
}

// Only POST accepted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); die('Yalnızca POST kabul edilir.');
}

// CSRF check (JSON-aware: returns 403+JSON on failure)
csrf_check($_POST['csrf'] ?? null);

$token = trim($_POST['token'] ?? '');
$idx   = (int)($_POST['idx'] ?? -1);
$mode  = trim($_POST['mode'] ?? '');

if ($token === '' || $idx < 0 || !in_array($mode, ['satis', 'sevk'], true)) {
    http_response_code(400); die('Geçersiz istek parametreleri.');
}

// Read from session cache
$cache = $_SESSION['hks_mobile_kunye_results'][$token] ?? null;
if ($cache === null || ($cache['expires'] ?? 0) < time()) {
    // Token expired — send user back to search
    header('Location: index.php?err=token_expired#' . ($mode === 'satis' ? 'satis' : 'sevk-etme'));
    exit;
}

$records    = $cache['records'] ?? [];
$musteri_tc = $cache['musteri_tc'] ?? '';

if (!array_key_exists($idx, $records)) {
    http_response_code(400); die('Seçilen kayıt bulunamadı.');
}

$rec = (array)$records[$idx];

// Case-insensitive DTO field accessor
function mob_dto_get(array $rec, array $names): string {
    foreach ($names as $name) {
        foreach ($rec as $k => $v) {
            if (strtolower($k) === strtolower($name) && $v !== null && $v !== '') {
                return (string)$v;
            }
        }
    }
    return '';
}

// Extract fields from DTO
$kunye_no         = mob_dto_get($rec, ['KunyeNo','MalinKunyeNo','ReferansKunyeNo']);
$plaka            = mob_dto_get($rec, ['AracPlakaNo','AracPlaka','Plaka']);
$belge_no         = mob_dto_get($rec, ['BelgeNo']);
$belge_tipi       = mob_dto_get($rec, ['BelgeTipi','BelgeTipiAdi']); // ID code first
$tarih_raw        = mob_dto_get($rec, ['BildirimTarihi','KayitTarihi','Tarih']);
$urun_adi         = mob_dto_get($rec, ['MalinAdi','UrunAdi','Urun']);
$urun_kodu        = mob_dto_get($rec, ['MalinKodNo']);
$urun_cinsi_kodu  = mob_dto_get($rec, ['MalinCinsKodNo']);
$cins_adi         = mob_dto_get($rec, ['MalinCinsi','UrunCinsi','Cins']);
$turu_kodu        = mob_dto_get($rec, ['MalinTuruKodNo']);
$birim_id         = mob_dto_get($rec, ['MiktarBirimId']);
$birim_adi        = mob_dto_get($rec, ['MiktarBirimiAd','MiktarBirimAd','BirimAdi','Birim']);
$kalan            = mob_dto_get($rec, ['KalanMiktar','Kalan']);
$miktar_ham       = mob_dto_get($rec, ['MalinMiktari','MalinMiktar','Miktar']);
$mal_sahibi_tc    = mob_dto_get($rec, ['MalinSahibiTcKimlikVergiNo','MalinSahibiTcVergiNo']);
$uretici_tc       = mob_dto_get($rec, ['UreticiTcKimlikVergiNo']);
$sifat            = mob_dto_get($rec, ['Sifat','SifatAdi']);
$gidecek_id       = mob_dto_get($rec, ['GidecekIsyeriId']);
$gidecek_yer_turu = mob_dto_get($rec, ['GidecekYerTuruId','GidecekYerTuru']);
$bildirim_turu    = mob_dto_get($rec, ['BildirimTuru','BildirimTuruAdi']);

// Parse sevk_tarihi from HKS datetime string
$sevk_tarihi = null;
if ($tarih_raw !== '') {
    $td = DateTime::createFromFormat('Y-m-d\TH:i:s', $tarih_raw)
       ?: DateTime::createFromFormat('Y-m-d H:i:s', $tarih_raw)
       ?: DateTime::createFromFormat('Y-m-d', substr($tarih_raw, 0, 10));
    if ($td) $sevk_tarihi = $td->format('Y-m-d');
}

// Choose miktar: prefer KalanMiktar, fall back to MalinMiktari
$use_miktar_str = $kalan !== '' ? $kalan : $miktar_ham;
$use_miktar     = $use_miktar_str !== '' ? (float)str_replace(',', '.', $use_miktar_str) : 0.0;

// notification_type and direction based on mode
$notification_type = $mode === 'satis' ? 'Satış' : 'Sevk Etme';
$direction         = 'cikis';

// Company context
$op_repo     = new HksRepository(db());
$op_settings = $op_repo->getSettings();
$company_id  = isset($_SESSION['hks_company_id']) ? (int)$_SESSION['hks_company_id'] : 0;
if ($company_id === 0) {
    $all_cos    = $op_repo->getAllCompanies();
    $company_id = !empty($all_cos) ? (int)$all_cos[0]['id'] : 0;
}
$firma = $op_settings['firma_adi'] ?? '';

// Duplicate guard: same KunyeNo + notification_type + active status
$existing_id = null;
if ($kunye_no !== '') {
    try {
        $dup = db()->prepare(
            "SELECT id FROM hks_notifications
             WHERE reference_kunye_no = ? AND notification_type = ?
               AND status IN ('draft','ready','checked')
             LIMIT 1"
        );
        $dup->execute([$kunye_no, $notification_type]);
        $row = $dup->fetch();
        if ($row) $existing_id = (int)$row['id'];
    } catch (Throwable $e) {
        error_log('[taslak_olustur] duplicate check: ' . $e->getMessage());
    }
}

if ($existing_id !== null) {
    // Consume token and redirect to existing draft
    unset($_SESSION['hks_mobile_kunye_results'][$token]);
    header('Location: taslak_detay.php?id=' . $existing_id . '&msg=duplicate');
    exit;
}

// Alici info for Satış mode
$alici_tc_vkn = '';
$alici_ad     = '';
if ($mode === 'satis') {
    if ($musteri_tc !== '') {
        $alici_tc_vkn = $musteri_tc;
        $contact = $op_repo->findMobileContactByTc('customer', $musteri_tc, $company_id);
        if ($contact) $alici_ad = (string)($contact['display_name'] ?? '');
    } elseif ($mal_sahibi_tc !== '') {
        $alici_tc_vkn = $mal_sahibi_tc;
    }
}

// Mask sensitive data before storing raw DTO
$ref_query_json = json_encode(
    hks_mask_sensitive($rec),
    JSON_UNESCAPED_UNICODE
);

// Build notification data array
$data = [
    'source_type'                  => 'hks_mobile',
    'direction'                    => $direction,
    'notification_type'            => $notification_type,
    'firma'                        => $firma,
    'urun'                         => $urun_kodu !== '' ? $urun_kodu : $urun_adi,
    'urun_cinsi'                   => $urun_cinsi_kodu,
    'miktar'                       => $use_miktar,
    'birim'                        => $birim_id !== '' ? $birim_id : $birim_adi,
    'malin_turu'                   => $turu_kodu,
    'arac_plaka'                   => $plaka,
    'belge_no'                     => $belge_no,
    'belge_tipi'                   => $belge_tipi,   // BelgeTipi (ID/kod) — BildirimTuru buraya yazılmaz
    'sevk_tarihi'                  => $sevk_tarihi,
    'reference_kunye_no'           => $kunye_no,
    'uretici_tc_vkn'               => $uretici_tc,
    'alici_tc_vkn'                 => $alici_tc_vkn,
    'alici_ad'                     => $alici_ad,
    'sifat'                        => $sifat,
    'gidecek_isyeri_id'            => $gidecek_id !== '' ? $gidecek_id : null,
    'reference_kunye_verified'     => 1,
    'reference_kunye_verified_at'  => date('Y-m-d H:i:s'),
    'reference_kunye_query_json'   => $ref_query_json,
    'reference_kunye_kalan_miktar' => $kalan !== '' ? (float)str_replace(',', '.', $kalan) : null,
    'reference_kunye_urun'         => $urun_adi,
    'reference_kunye_urun_cinsi'   => $cins_adi,
    'reference_kunye_birim'        => $birim_adi,
    'created_by'                   => (int)($auth_user['id'] ?? 0),
];

// Create draft (createNotification always inserts status='draft')
try {
    $notif_id = $op_repo->createNotification($data);
} catch (Throwable $e) {
    error_log('[taslak_olustur] createNotification: ' . $e->getMessage());
    http_response_code(500); die('Taslak oluşturulamadı. Lütfen tekrar deneyin.');
}

// Validate — promote to 'ready' if all required fields are present
$notif = $op_repo->getNotification($notif_id);
$validation_errors = $notif ? hks_validate_notification($notif) : ['Kayıt alınamadı.'];

$new_status = empty($validation_errors) ? 'ready' : 'draft';
try {
    db()->prepare(
        "UPDATE hks_notifications
         SET status=?, validation_errors_json=?, updated_at=NOW()
         WHERE id=?"
    )->execute([
        $new_status,
        json_encode($validation_errors, JSON_UNESCAPED_UNICODE),
        $notif_id,
    ]);
} catch (Throwable $e) {
    error_log('[taslak_olustur] status update: ' . $e->getMessage());
}

// Audit log
audit_log_event('create', 'hks_notifications', $notif_id, null, [
    'source'             => 'hks_mobile_kunye_select',
    'mode'               => $mode,
    'reference_kunye_no' => $kunye_no,
    'notification_type'  => $notification_type,
    'status'             => $new_status,
]);

// Consume session token (single use)
unset($_SESSION['hks_mobile_kunye_results'][$token]);

header('Location: taslak_detay.php?id=' . $notif_id . '&msg=created');
exit;
