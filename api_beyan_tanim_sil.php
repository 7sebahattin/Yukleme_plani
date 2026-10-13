<?php
// Beyan formu önerilerinden değer SİL (v344) — yalnız öneri listesinden gizler; beyan kayıtlarına dokunmaz.
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
header('Content-Type: application/json; charset=utf-8');
function beyan_tanim_sil_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
$auth_user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') beyan_tanim_sil_json(['ok'=>false,'error'=>'Geçersiz istek.'], 405);
if (!(can_beyan('create') || can_beyan('edit'))) beyan_tanim_sil_json(['ok'=>false,'error'=>'Bu işlem için yetkiniz yok.'], 403);
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 8000) beyan_tanim_sil_json(['ok'=>false,'error'=>'İstek çok uzun.'], 413);
$in = json_decode($raw, true);
if (!is_array($in)) beyan_tanim_sil_json(['ok'=>false,'error'=>'Geçersiz istek.'], 400);
csrf_check($in['csrf'] ?? null);
if (!is_string($in['field'] ?? null) || !is_string($in['name'] ?? null)) beyan_tanim_sil_json(['ok'=>false,'error'=>'Geçersiz alan değeri.'], 422);
$field = $in['field'];
if (in_array($field, ['company_address', 'contact_person', 'buyer_name'], true) && !can_beyan('contact.write')) forbidden();
try {
    $name = beyan_suggest_hide(db(), $field, $in['name']);
    audit_log_event('delete', 'definitions', null, ['source'=>'beyan_form','field'=>$field,'name'=>$name], null);
    beyan_tanim_sil_json(['ok'=>true, 'name'=>$name]);
} catch (InvalidArgumentException $e) {
    beyan_tanim_sil_json(['ok'=>false,'error'=>$e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[api_beyan_tanim_sil] ' . $e->getMessage());
    beyan_tanim_sil_json(['ok'=>false,'error'=>'Öneri silinemedi. Tekrar deneyin.'], 500);
}
