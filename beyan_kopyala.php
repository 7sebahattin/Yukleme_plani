<?php
// =========================================================
// beyan_kopyala.php — Beyanı TASLAK olarak kopyalar (v330)
// Benzer beyanlar için: tek tıkla kopya açılır, kullanıcı düzenleme ekranına
// düşer, gerekli yerleri değiştirip kaydeder.
//
// • YALNIZ POST + CSRF + beyan.write (GET veri değiştirmez).
// • Kopya her zaman `taslak` durumundadır; kaynak beyanın durumu ne olursa olsun.
// • Beyan başına DEĞİŞEN alanlar KOPYALANMAZ: parti no, araç plakası, ham
//   WhatsApp metni, analiz/numune bilgileri, yükleme planı bağı ve HKS bildirim
//   izi. Plaka/parti no'nun yanlışlıkla eski beyandan kalması, yanlış HKS
//   bildirimi demektir.
// • HKS eşleştirmesi (firma/ürün/ülke) KOPYALANIR — benzer beyanın asıl
//   kazancı budur.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!can_beyan('write')) forbidden();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: beyanlar.php');
    exit;
}
csrf_check($_POST['csrf'] ?? null);

$id = (int)($_POST['id'] ?? 0);
$st = db()->prepare("SELECT * FROM customs_declarations WHERE id = ?");
$st->execute([$id]);
$src = $id > 0 ? $st->fetch() : false;
if (!$src || !empty($src['deleted_at'])) {
    set_flash('error', 'Kopyalanacak beyan bulunamadı.');
    header('Location: beyanlar.php');
    exit;
}

$user_id = (int)($auth_user['id'] ?? 0);
$ins = db()->prepare("INSERT INTO customs_declarations
    (company_name, company_address, transport_type, line_type, pallet_count,
     product_name, product_variety, gross_kg, net_kg, crate_count, crate_type,
     exit_depot, contact_person, buyer_name, brand, status,
     hks_firma_id, hks_urun_id, hks_urun_ad, hks_ulke_id, hks_ulke_ad,
     created_by, updated_by, created_at, updated_at)
    VALUES (?,?,?,?,?, ?,?,?,?,?,?, ?,?,?,?,'taslak', ?,?,?,?,?, ?,?,NOW(),NOW())");
$ins->execute([
    $src['company_name'], $src['company_address'], $src['transport_type'], $src['line_type'], $src['pallet_count'],
    $src['product_name'], $src['product_variety'], $src['gross_kg'], $src['net_kg'], $src['crate_count'], $src['crate_type'],
    $src['exit_depot'], $src['contact_person'], $src['buyer_name'], $src['brand'],
    $src['hks_firma_id'] ?? null, $src['hks_urun_id'] ?? null, $src['hks_urun_ad'] ?? null,
    $src['hks_ulke_id'] ?? null, $src['hks_ulke_ad'] ?? null,
    $user_id, $user_id,
]);
$new_id = (int)db()->lastInsertId();

audit_log_event('beyan_kopyala', 'declarations', $new_id, null, [
    'kaynak_id' => $id, 'kaynak_parti_no' => $src['party_no'], 'status' => 'taslak',
]);

set_flash('success', 'Beyandan yeni TASLAK oluşturuldu. Parti no ve araç plakasını girip diğer alanları düzenleyin, sonra kaydedin.');
header('Location: beyan_edit.php?id=' . $new_id);
exit;
