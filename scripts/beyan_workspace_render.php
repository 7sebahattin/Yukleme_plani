<?php
// CLI-only visual fixture: php scripts/beyan_workspace_render.php OUTPUT_PATH [create|edit]
// SQLite bellek veritabanı ve gerçek helpers; canlı bağlantı/POST işlemi yok.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
// Migration gurultusunu (SQLite'ta SHOW COLUMNS calismaz) log dosyasina yonlendir.
$LOG = sys_get_temp_dir() . '/beyan_ui_smoke.log';
ini_set('log_errors', '1');
ini_set('error_log', $LOG);

// ── SQLite + auth stub'ları (helpers.php'den ÖNCE tanımlanmalı) ───────────
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

$PERMS = ['beyan.read', 'beyan.write', 'beyan.delete', 'records.write', 'maliyet.read'];
if (getenv('BEYAN_TEST_PROFILE') === 'restricted') $PERMS = ['beyan.read', 'beyan.edit', 'beyan.permissions.detailed'];
if (getenv('BEYAN_TEST_PROFILE') === 'readonly') $PERMS = ['beyan.read', 'beyan.edit', 'beyan.permissions.detailed', 'beyan.contact.read', 'beyan.analysis.read', 'beyan.hks.read', 'beyan.source.read'];
function current_user(): ?array { return ['id' => 1, 'username' => 'test', 'display_name' => 'Test']; }
function can(string $p): bool { global $PERMS; return in_array($p, $PERMS, true); }
function is_admin(): bool { return false; }
function require_login(): array { return current_user(); }
function forbidden($m = ''): void { throw new RuntimeException('forbidden: ' . $m); }
function active_depot(): ?string { return 'DEPO'; }
function enforce_active_depot(): void {}
function depot_options(): array { return ['DEPO']; }
function depot_visible_to_user(?string $d): bool { return true; }
function user_allowed_depots(): array { return ['DEPO']; }
function depo_sql_records(string $a = 'r'): array { return ['', []]; }
function depo_sql_records_in(string $a = 'r'): array { return ['', []]; }
function depo_sql_column(string $c): array { return ['', []]; }
function depo_sql_in(string $c): array { return ['', []]; }
function user_primary_role(): ?array { return ['slug' => 'operator', 'label' => 'Operatör']; }

require_once $ROOT . '/config/helpers.php';   // GERÇEK bb_* / beyan_hks_*

// ── Şema ──────────────────────────────────────────────────────────────────
db()->exec("CREATE TABLE customs_declarations (
  id INTEGER PRIMARY KEY AUTOINCREMENT, raw_text TEXT, unmatched_text TEXT,
  declaration_title TEXT, company_name TEXT, company_address TEXT,
  transport_type TEXT, vehicle_plate TEXT, line_type TEXT, party_no TEXT,
  pallet_count INT, product_name TEXT, product_variety TEXT,
  gross_kg REAL, net_kg REAL, crate_count INT, crate_type TEXT,
  exit_depot TEXT, contact_person TEXT, buyer_name TEXT, brand TEXT,
  status TEXT DEFAULT 'beyan_acildi', analysis_note TEXT,
  sample_taken_at TEXT, analysis_result_at TEXT, loading_record_id INT,
  note TEXT, sample_gross_kg REAL, sample_net_kg REAL, sample_crate_type TEXT, sample_crate_count INTEGER,
  hks_durum TEXT, hks_firma_id TEXT, hks_urun_id TEXT, hks_urun_ad TEXT,
  hks_ulke_id TEXT, hks_ulke_ad TEXT,
  created_by INT, updated_by INT, created_at TEXT DEFAULT '2026-07-01 10:00',
  updated_at TEXT, deleted_at TEXT)");
db()->exec("CREATE TABLE beyan_hks_bildirim (
  id INTEGER PRIMARY KEY AUTOINCREMENT, beyan_id INT, hks_firma_id TEXT,
  hks_firma_ad TEXT, taslak_id TEXT, gonderim_id TEXT, durum TEXT,
  urun_id TEXT, urun_ad TEXT, ulke_id TEXT, ulke_ad TEXT, plaka TEXT,
  kg REAL, fiyat REAL, hata_metni TEXT, created_by INT,
  created_at TEXT DEFAULT '2026-07-02 10:00', updated_at TEXT)");
db()->exec("CREATE TABLE hks_eslesme (id INTEGER PRIMARY KEY AUTOINCREMENT,
  tip TEXT, kaynak_norm TEXT, hks_id TEXT, hks_ad TEXT, kullanim INT, updated_at TEXT)");
db()->exec("CREATE TABLE hks_kv (anahtar TEXT PRIMARY KEY, deger TEXT)");
db()->exec("CREATE TABLE hks_firmalar (id TEXT PRIMARY KEY, ad TEXT, vergi_no TEXT)");
db()->exec("CREATE TABLE hks_gonderilenler (id TEXT PRIMARY KEY, zaman TEXT,
  firma_id TEXT, urun_ad TEXT, fiyat REAL)");
db()->exec("CREATE TABLE loading_records (id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT DEFAULT 'yukleme', tarih TEXT, firma TEXT, parti_no TEXT, alici TEXT,
  ulasim TEXT, gumruk TEXT, urun TEXT, durum TEXT, locked_at TEXT,
  urun_sahibi_id INT, on_plaka TEXT, arka_plaka TEXT, gidecek_ulke TEXT)");
db()->exec("CREATE TABLE material_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT, type TEXT, is_active INT DEFAULT 1, color TEXT)");
db()->exec("CREATE TABLE cost_sheets (id INTEGER PRIMARY KEY AUTOINCREMENT,
  record_id INT, sheet_no TEXT, sale_unit_price REAL, currency_code TEXT,
  currency_rate REAL, deleted_at TEXT)");
db()->exec("CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT,
  action TEXT, entity TEXT, entity_id INT, old_values TEXT, new_values TEXT,
  user_id INT, created_at TEXT)");

// Katalog önbelleği — gerçek yapısıyla
db()->prepare("INSERT INTO hks_kv (anahtar, deger) VALUES ('listeler_cache', ?)")
   ->execute([json_encode([
        'zaman'   => '2026-07-01T10:00:00+03:00',
        'urunler' => [['id' => '10', 'ad' => 'Kayısı'], ['id' => '11', 'ad' => 'Kiraz']],
        'ulkeler' => [['id' => '20', 'ad' => 'Rusya']],
        'sifatlar' => [['id' => '7', 'ad' => 'İhracat']],
        'bildirimTurleri' => [['id' => '5', 'ad' => 'Satış'], ['id' => '6', 'ad' => 'Satın Alım']],
        'isletmeTurleri' => [['id' => '3', 'ad' => 'Yurt Dışı']],
   ], JSON_UNESCAPED_UNICODE)]);
db()->exec("INSERT INTO hks_firmalar (id,ad,vergi_no) VALUES ('f1','TEST FİRMA','1234567890')");

$outputPath = $argv[1] ?? '';
$mode = $argv[2] ?? 'edit';
if ($outputPath === '' || !in_array($mode, ['create', 'edit'], true)) {
    fwrite(STDERR, "Usage: php scripts/beyan_workspace_render.php OUTPUT_PATH [create|edit]\n");
    exit(1);
}
db()->exec("INSERT INTO customs_declarations
    (party_no, product_name, product_variety, net_kg, gross_kg, pallet_count, crate_count,
     crate_type, status, vehicle_plate, hks_firma_id, hks_urun_id, hks_urun_ad,
     hks_ulke_id, hks_ulke_ad, buyer_name, transport_type, exit_depot, brand,
     company_name, company_address, contact_person, raw_text, unmatched_text, updated_at)
    VALUES ('13-41', 'KAYISI', 'PRICIA', 19800.45, 21100.75, 26, 1800, 'PLASTİK',
     'analiz_bekliyor', '34ABC123', 'f1', '10', 'Kayısı', '20', 'Rusya',
     'ÖRNEK ALICI', 'DENİZYOLU', 'DEPO', 'ÖRNEK MARKA', 'ÖRNEK FİRMA',
     'ÖRNEK ADRES', 'İLGİLİ BİRİM', 'Parti: 13-41\nÜrün: Kayısı\nNet KG: 19.800,450',
     'Kontrol edilecek örnek satır', '2026-10-11 10:00:00')");
$_GET = $mode === 'edit' ? ['id' => '1'] : [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/beyan_' . $mode . '.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['HTTP_HOST'] = 'localhost';
$source = file_get_contents($ROOT . '/beyan_' . $mode . '.php');
$source = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $source);
// Eval kapsamı içinde kaynak dosyanın __DIR__ değeri yerine proje kökü kullanılır.
$source = str_replace('__DIR__', var_export($ROOT, true), $source);
$source = preg_replace('/declare\(strict_types=1\);/', '', $source);
ob_start();
try {
    eval('?>' . $source);
    $html = (string)ob_get_clean();
    // Windows dirname('/sayfa.php') '\\' döndürür; fixture kökte servis edilir.
    if (DIRECTORY_SEPARATOR === '\\') {
        $html = str_replace(['href="../', 'src="../', 'data-base-url="../'], ['href="', 'src="', 'data-base-url="'], $html);
    }
    if (preg_match('/(?:Warning|Notice|Fatal error):/', $html)) {
        throw new RuntimeException('Fixture render PHP uyarısı üretti.');
    }
    if (file_put_contents($outputPath, $html) === false) throw new RuntimeException('Çıktı yazılamadı.');
    fwrite(STDOUT, $outputPath . "\n");
} catch (Throwable $error) {
    if (ob_get_level()) ob_end_clean();
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
