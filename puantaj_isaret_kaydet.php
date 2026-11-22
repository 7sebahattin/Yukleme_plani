<?php
// =========================================================
// puantaj_isaret_kaydet.php — Aylık Puantaj: elle gün işareti (v553) — TEK YAZMA UCU
//
// Yalnız POST, JSON yanıt. Girdi: employee_id, tarih (YYYY-MM-DD), kod (D | İ | Ü | R | T; '' = temizle),
// aciklama (opsiyonel; verilmezse mevcut açıklama korunur, '' siler), csrf. Gövde form alanı ya da JSON olabilir.
// Kapılar: require_login → role_pdks_require_request() (rota kayıtlı; POST = monthly_timesheet.edit)
// → role_pdks_can('monthly_timesheet','edit') + role_pdks_can('employees') (kaynak) → csrf_check →
// tablo hazır (migrate.php › Puantaj İşaretleri) → çalışan var + aktif depo kuralı (depot_visible_to_user;
// boş depo geçer, başka depo 403). Her değişiklik audit_log_event('create'|'update'|'delete','puantaj_isaret').
// Şemayı BU DOSYA kurmaz; tablo yoksa 503 + nazik mesaj döner.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/puantaj_cizelgesi.php';

header('Content-Type: application/json; charset=utf-8');

function pi_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$auth_user = require_login();

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    pi_json(['ok' => false, 'error' => 'Geçersiz istek.'], 405);
}

role_pdks_require_request();   // rota kayıtlı + POST → monthly_timesheet.edit (JSON-aware 403)
if (!role_pdks_can('monthly_timesheet', 'edit') || !role_pdks_can('employees')) {
    pi_json(['ok' => false, 'error' => 'Bu işlem için yetkiniz yok.', 'code' => 403], 403);
}
if (defined('PDKS_AKTIF') && !PDKS_AKTIF) {
    pi_json(['ok' => false, 'error' => 'Personel modülü şu anda kapalıdır.'], 403);
}

$in = pi_girdi_coz((string)file_get_contents('php://input'), (string)($_SERVER['CONTENT_TYPE'] ?? ''), $_POST);
csrf_check(isset($in['csrf']) && is_string($in['csrf']) ? $in['csrf'] : null);   // JSON-aware: hatada 403 + JSON
if (function_exists('enforce_active_depot')) enforce_active_depot();

$empRaw = $in['employee_id'] ?? null;
$tarihRaw = $in['tarih'] ?? null;
$kodRaw = $in['kod'] ?? null;
if (!is_scalar($empRaw) || !ctype_digit((string)$empRaw) || (int)$empRaw <= 0) pi_json(['ok' => false, 'error' => 'Geçersiz personel.'], 400);
if (!is_string($tarihRaw) || pi_tarih_dogrula($tarihRaw) === null) pi_json(['ok' => false, 'error' => 'Geçersiz tarih.'], 400);
if (!is_string($kodRaw) || ($kodRaw !== '' && !pi_kod_gecerli($kodRaw))) pi_json(['ok' => false, 'error' => 'Geçersiz işaret kodu.'], 400);
$aciklama = array_key_exists('aciklama', $in) ? (is_string($in['aciklama']) ? $in['aciklama'] : '') : null;   // yok = koru

$pdo = db();
if (!pdks_tablo_var($pdo, 'employees')) pi_json(['ok' => false, 'error' => 'Personel tabloları henüz hazır değil.'], 503);
if (!pi_hazir($pdo)) {
    pi_json(['ok' => false, 'error' => 'İşaret tablosu kurulmamış. Bir yöneticinin migrate.php › Puantaj İşaretleri kartını çalıştırması gerekiyor.'], 503);
}
$emp = pi_calisan_bul($pdo, (int)$empRaw);
if ($emp === null) pi_json(['ok' => false, 'error' => 'Personel bulunamadı.'], 404);
if (!depot_visible_to_user((string)($emp['depo'] ?? ''))) {
    pi_json(['ok' => false, 'error' => 'Bu personel başka bir depoya ait.', 'code' => 403], 403);
}

$sonuc = pi_uygula($pdo, (int)$emp['id'], $tarihRaw, $kodRaw, $aciklama, (int)($auth_user['id'] ?? 0) ?: null);
if (!$sonuc['ok']) pi_json(['ok' => false, 'error' => (string)$sonuc['hata']], 400);
if ($sonuc['audit'] !== null) {
    [$aksiyon, $kayitId, $eski, $yeni] = $sonuc['audit'];
    audit_log_event($aksiyon, 'puantaj_isaret', $kayitId, $eski, $yeni);
}
pi_json(['ok' => true, 'islem' => $sonuc['islem'], 'kod' => $kodRaw, 'tarih' => $tarihRaw, 'employee_id' => (int)$emp['id']]);
