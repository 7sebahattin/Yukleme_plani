<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
header('Content-Type: application/json; charset=utf-8');
function beyan_tanim_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
$auth_user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') beyan_tanim_json(['ok'=>false,'error'=>'Geçersiz istek.'], 405);
if (!(can_beyan('create') || can_beyan('edit'))) beyan_tanim_json(['ok'=>false,'error'=>'Bu işlem için yetkiniz yok.'], 403);
$raw_body = (string)file_get_contents('php://input');
if (strlen($raw_body) > 16000) beyan_tanim_json(['ok'=>false,'error'=>'İstek çok uzun.'], 413);
$in = json_decode($raw_body, true);
if (!is_array($in)) beyan_tanim_json(['ok'=>false,'error'=>'Geçersiz istek.'], 400);
csrf_check($in['csrf'] ?? null);
foreach (['field','name','company_name','company_address'] as $key) {
    if (isset($in[$key]) && !is_string($in[$key])) beyan_tanim_json(['ok'=>false,'error'=>'Geçersiz alan değeri.'], 422);
}
try {
    if (in_array($in['field'] ?? '', ['company_address','contact_person','buyer_name'], true) && !can_beyan('contact.write')) forbidden();
    if (!can_beyan('contact.write')) $in['company_address'] = '';
    $result = beyan_suggest_save(db(), $in['field'] ?? '', $in['name'] ?? '', $in['company_name'] ?? '', $in['company_address'] ?? '');
    audit_log_event('create', 'definitions', null, null, ['source'=>'beyan_form','field'=>$in['field'],'name'=>$result['name']]);
    if (!can_beyan('contact.read')) unset($result['address'], $result['company_address']);
    if (!can_beyan('hks.read')) unset($result['urun_id']);
    beyan_tanim_json($result);
} catch (InvalidArgumentException $e) {
    beyan_tanim_json(['ok'=>false,'error'=>$e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[api_beyan_tanim_ekle] ' . $e->getMessage());
    beyan_tanim_json(['ok'=>false,'error'=>'Tanım kaydedilemedi. Tekrar deneyin.'], 500);
}
