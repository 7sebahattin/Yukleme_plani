<?php
// =========================================================
// api_tanim_ekle.php — Kayıt formu öneri alanları için hızlı tanım ekleme
//
// Yükleme kaydı formunda Alıcı / Gümrük / Nakliye Şirketi / Şoför /
// Telefon / Ön-Arka Plaka / Ulaşım kutularına yazılan değer önerilerde
// yoksa, kullanıcı listedeki "+ ekle" satırına basar ve buraya POST edilir.
//
// GÜVENLİK NOTLARI
// - Sadece record_suggest_fields() içindeki alanlar kabul edilir (whitelist).
//   Böylece records.write yetkisi ile 'depo' / 'firma' / 'kasa_cinsi' gibi
//   iş kritiği tanımlar OLUŞTURULAMAZ (onlar defs.write işi).
// - Boş / yalnız noktalama / kolon uzunluğunu aşan değerler reddedilir —
//   veritabanına çöp veya kırpılmış kayıt gitmez.
// - ensure_definition() TR-duyarsız mükerrer kontrolü yapar.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

header('Content-Type: application/json; charset=utf-8');

function tanim_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$auth_user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tanim_json(['ok' => false, 'error' => 'Geçersiz istek.'], 405);
}

// Kayıt yazma yetkisi yeterli (malzeme_stok_islem'deki tedarikçi ekleme ile
// aynı desen: sayfa yetkisi neyse tanım ekleme de o yetkiyle yapılır).
if (!can('records.write')) {
    tanim_json(['ok' => false, 'error' => 'Bu işlem için yetkiniz yok.'], 403);
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];

csrf_check($in['csrf'] ?? null); // JSON-aware: hatada 403 + JSON döner

$field  = (string)($in['field'] ?? '');
$fields = record_suggest_fields();
if (!isset($fields[$field])) {
    tanim_json(['ok' => false, 'error' => 'Bu alan için tanım eklenemez.'], 400);
}
$meta = $fields[$field];

$raw = (string)($in['name'] ?? '');
// Aşırı uzun gövdeyi kırpmadan reddet — sessiz veri kaybı olmasın
if (mb_strlen(trim($raw), 'UTF-8') > $meta['max']) {
    tanim_json([
        'ok'    => false,
        'error' => 'En fazla ' . $meta['max'] . ' karakter olabilir.',
    ]);
}

$name = normalize_suggest_value($field, $raw);
if (!suggest_value_is_meaningful($name)) {
    tanim_json(['ok' => false, 'error' => 'Geçerli bir ' . $meta['label'] . ' değeri girin.']);
}

try {
    ensure_definition($meta['type'], $name);
} catch (Throwable $e) {
    error_log('[api_tanim_ekle] ' . $e->getMessage());
    tanim_json(['ok' => false, 'error' => 'Kaydedilemedi, tekrar deneyin.'], 500);
}

// ensure_definition TR-duyarsız eşleşmede mevcut kaydı korur; kullanıcıya
// ve öneri listesine tanımdaki KANONİK yazımı döndür.
$canonical = $name;
try {
    $st = db()->prepare("SELECT name FROM material_definitions WHERE type = ? AND is_active = 1");
    $st->execute([$meta['type']]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) {
        if (tr_upper((string)$n) === tr_upper($name)) { $canonical = (string)$n; break; }
    }
} catch (Throwable $e) { /* kanonik okunamazsa girilen değerle devam */ }

audit_log_event('create', 'definitions', null, null, [
    'type'   => $meta['type'],
    'name'   => $canonical,
    'source' => 'record_form',
    'field'  => $field,
]);

tanim_json(['ok' => true, 'name' => $canonical]);
