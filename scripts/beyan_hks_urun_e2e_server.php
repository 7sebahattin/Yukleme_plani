<?php
// =========================================================
// scripts/beyan_hks_urun_e2e_server.php — HKS Ürün Adı UÇTAN UCA test sunucusu (v349)
//
// php -S 127.0.0.1:8765 scripts/beyan_hks_urun_e2e_server.php
// Gerçek beyan_edit.php / beyan_view.php / api_beyan_urun_esle.php'yi DOSYA tabanlı
// SQLite ile çalıştırır (istekler arası kalıcı). Canlı DB'ye dokunmaz. Yalnız CLI-server.
// Sürücü: node scripts/beyan_hks_urun_e2e.js (sunucuyu kendisi başlatır).
// =========================================================
declare(strict_types=1);
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--kur') {
    // Şema + tohum: KABAK kayıtlı beyan (HKS Ürün = Kabak), katalog Kabak/Acur/Biber Salçalık (Kapya)
    $f = getenv('E2E_DB') ?: (sys_get_temp_dir() . '/beyan_hks_urun_e2e.sqlite');
    if (is_file($f)) unlink($f);
    $p = new PDO('sqlite:' . $f);
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->exec("CREATE TABLE customs_declarations (
      id INTEGER PRIMARY KEY AUTOINCREMENT, raw_text TEXT, unmatched_text TEXT,
      declaration_title TEXT, company_name TEXT, company_address TEXT,
      transport_type TEXT, vehicle_plate TEXT, line_type TEXT, party_no TEXT,
      pallet_count INT, product_name TEXT, product_variety TEXT,
      gross_kg REAL, net_kg REAL, crate_count INT, crate_type TEXT,
      exit_depot TEXT, contact_person TEXT, buyer_name TEXT, brand TEXT,
      status TEXT DEFAULT 'beyan_acildi', analysis_note TEXT,
      sample_taken_at TEXT, analysis_result_at TEXT, loading_record_id INT,
      hks_durum TEXT, hks_firma_id TEXT, hks_urun_id TEXT, hks_urun_ad TEXT,
      hks_ulke_id TEXT, hks_ulke_ad TEXT, note TEXT, sample_gross_kg REAL, sample_net_kg REAL,
      sample_crate_type TEXT, sample_crate_count INTEGER, tractor_plate TEXT, sender_company_name TEXT,
      created_by INT, updated_by INT, created_at TEXT DEFAULT '2026-10-13 10:00',
      updated_at TEXT, deleted_at TEXT)");
    $p->exec("CREATE TABLE beyan_hks_bildirim (id INTEGER PRIMARY KEY AUTOINCREMENT, beyan_id INT, hks_firma_id TEXT,
      hks_firma_ad TEXT, taslak_id TEXT, gonderim_id TEXT, durum TEXT, urun_id TEXT, urun_ad TEXT, ulke_id TEXT,
      ulke_ad TEXT, plaka TEXT, kg REAL, fiyat REAL, hata_metni TEXT, created_by INT, created_at TEXT, updated_at TEXT)");
    $p->exec("CREATE TABLE hks_eslesme (id INTEGER PRIMARY KEY AUTOINCREMENT, tip TEXT, kaynak_norm TEXT, hks_id TEXT, hks_ad TEXT, kullanim INT, updated_at TEXT)");
    $p->exec("CREATE TABLE hks_kv (anahtar TEXT PRIMARY KEY, deger TEXT)");
    $p->exec("CREATE TABLE hks_firmalar (id TEXT PRIMARY KEY, ad TEXT, vergi_no TEXT)");
    $p->exec("CREATE TABLE hks_gonderilenler (id TEXT PRIMARY KEY, zaman TEXT, firma_id TEXT, urun_ad TEXT, fiyat REAL)");
    $p->exec("CREATE TABLE loading_records (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT DEFAULT 'yukleme', tarih TEXT, firma TEXT,
      parti_no TEXT, alici TEXT, ulasim TEXT, gumruk TEXT, urun TEXT, durum TEXT, locked_at TEXT, urun_sahibi_id INT,
      on_plaka TEXT, arka_plaka TEXT, gidecek_ulke TEXT)");
    $p->exec("CREATE TABLE material_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, type TEXT, is_active INT DEFAULT 1, color TEXT)");
    $p->exec("CREATE TABLE cost_sheets (id INTEGER PRIMARY KEY AUTOINCREMENT, record_id INT, sheet_no TEXT, sale_unit_price REAL,
      currency_code TEXT, currency_rate REAL, deleted_at TEXT)");
    $p->exec("CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, action TEXT, entity TEXT, entity_id INT,
      old_values TEXT, new_values TEXT, user_id INT, created_at TEXT)");
    $p->prepare("INSERT INTO hks_kv (anahtar, deger) VALUES ('listeler_cache', ?)")->execute([json_encode([
        'zaman' => '2026-10-13T10:00:00+03:00',
        'urunler' => [['id' => '30', 'ad' => 'Kabak'], ['id' => '31', 'ad' => 'Acur'], ['id' => '32', 'ad' => 'Biber Salçalık (Kapya)'],
                      ['id' => '33', 'ad' => 'Domates'], ['id' => '34', 'ad' => 'Domates Çeri'], ['id' => '35', 'ad' => 'Kabak Sakız'],
                      ['id' => '36', 'ad' => 'Biber Sivri'], ['id' => '37', 'ad' => 'Biber Dolmalık'], ['id' => '38', 'ad' => 'İncir'],
                      ['id' => '39', 'ad' => 'Isırgan Otu'], ['id' => '40', 'ad' => 'Elma Starking'], ['id' => '41', 'ad' => 'Elma Granny Smith'],
                      ['id' => '42', 'ad' => 'Üzüm (Sultani)']],   // >10 ürün → canlıdaki gibi aranabilir kutu
        'ulkeler' => [['id' => '20', 'ad' => 'Rusya']],
        'sifatlar' => [['id' => '7', 'ad' => 'İhracat']],
        'bildirimTurleri' => [['id' => '5', 'ad' => 'Satış']],
        'isletmeTurleri' => [['id' => '3', 'ad' => 'Yurt Dışı']],
    ], JSON_UNESCAPED_UNICODE)]);
    $p->exec("INSERT INTO hks_firmalar (id,ad,vergi_no) VALUES ('f1','TEST FİRMA','1234567890')");
    $p->exec("INSERT INTO customs_declarations (party_no,product_name,product_variety,net_kg,pallet_count,status,hks_firma_id,
      hks_urun_id,hks_urun_ad,hks_ulke_id,hks_ulke_ad,buyer_name,transport_type)
      VALUES ('09-21','KABAK','MALTEPE',11550,13,'beyan_acildi','f1','30','Kabak','20','Rusya','ALICI','KARAYOLU')");
    echo "kuruldu $f\n";
    exit(0);
}
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }

$ROOT = dirname(__DIR__);
$yol  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if (preg_match('#^/(assets|nav-icons)/#', $yol) || $yol === '/sw.js' || $yol === '/manifest.json') return false;

$DBF = getenv('E2E_DB') ?: (sys_get_temp_dir() . '/beyan_hks_urun_e2e.sqlite');
ini_set('log_errors', '1');
ini_set('error_log', sys_get_temp_dir() . '/beyan_hks_urun_e2e.log');
$PDO_TEST = new PDO('sqlite:' . $DBF);
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

session_start();
$PERMS = ['beyan.read', 'beyan.write', 'records.write'];
function current_user(): ?array { return ['id' => 1, 'username' => 'test', 'display_name' => 'Test']; }
function can(string $p): bool { global $PERMS; return in_array($p, $PERMS, true); }
function is_admin(): bool { return false; }
function require_login(): array { return current_user(); }
function forbidden($m = ''): void { http_response_code(403); echo 'forbidden ' . $m; exit; }
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

require_once $ROOT . '/config/helpers.php';

// Sayfayı require'ları sıyırarak çalıştır (beyan_ui_smoke.php ile aynı desen).
$sayfalar = ['/beyan_edit.php', '/beyan_view.php', '/api_beyan_urun_esle.php'];
if (!in_array($yol, $sayfalar, true)) { http_response_code(404); echo 'yok'; exit; }
$src = (string)file_get_contents($ROOT . $yol);
$src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
$src = str_replace("__DIR__ . '/", var_export($ROOT . '/', true) . " . '", $src);
$tmp = sys_get_temp_dir() . '/e2e_' . md5($yol . getmypid()) . '.php';
file_put_contents($tmp, $src);
chdir($ROOT);
include $tmp;
