<?php
// =========================================================
// scripts/pdks_personel_mobil_smoke.php — Mobil canlı düzeltmeleri testi
//
// SADECE CLI. Canlı veritabanına HİÇ dokunmaz: bellek içi SQLite ve
// stub'lanmış auth/depo/render fonksiyonlarıyla personel.php'yi GERÇEKTEN
// render eder (pdks_faz1b_ui_smoke.php ile aynı desen, ama depo_sql_in()/
// active_depot() burada AYARLANABİLİR birer stub'tır — paylaşılan
// pdks_faz1b_ui_smoke.php'nin 63 testini bozmadan izole test etmek için).
//
// Kanıtlanan şey — canlı raporun §2 kökeni:
//   personel.php'nin listesi depo_sql_in('e.depo') ile aktif depoya
//   daraltılır (CLAUDE.md "Aktif Depo Sistemi"). Bir personel MASAÜSTÜNDE
//   aktif bir depo seçiliyken oluşturulduysa (personel_form.php'nin
//   damgalama kuralı: depo varsayılanı = active_depot()), o personel o
//   depoya damgalanır. MOBİLDE FARKLI bir depo aktifse (ayrı cihaz = ayrı
//   çerez), personel_form.php'de oluşturulan kayıt oradan GÖRÜNMEZ — bu
//   BİR HATA DEĞİL, depo izolasyonunun kendisidir. Asıl hata, eski
//   "Henüz personel kaydı yok." mesajının bunu YANLIŞ biçimde "kayıt hiç
//   yok" gibi göstermesiydi. Düzeltme: mesaj artık kayıt sayısını VE aktif
//   depoyu GÖSTERİR, depo filtresini ASLA zayıflatmaz/atlamaz.
//
//   php scripts/pdks_personel_mobil_smoke.php   → çıkış kodu 0 = geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

$ROOT = dirname(__DIR__);

$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

$PERMS = ['attendance.read', 'attendance.employees', 'attendance.cards', 'attendance.scan', 'attendance.report'];
$IS_ADMIN = false;
function current_user(): ?array { return ['id' => 1, 'username' => 'test', 'display_name' => 'Test Kullanıcı']; }
function can(string $p): bool { global $PERMS; return in_array($p, $PERMS, true); }
function is_admin(): bool { global $IS_ADMIN; return $IS_ADMIN; }
function audit_log_event(...$a): void {}
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return 'testcsrf'; }
function csrf_check($t): void {}
function set_flash($a, $b): void {}
function get_flash(): ?array { return null; }
function render_flash(): void {}
function forbidden($m = ''): void { throw new RuntimeException('forbidden: ' . $m); }
function base_url(): string { return '/'; }
function require_login(): array { return current_user(); }
function enforce_active_depot(): void {}
function render_header(string $t, bool $p = false): void { echo "<!doctype html><html><head><title>" . h($t) . "</title></head><body><main class=\"container\">"; }
function render_footer(bool $p = false): void { echo "</main></body></html>"; }

// ── Bu testte AYARLANABİLİR depo stub'ları — pdks_faz1b_ui_smoke.php'nin
// SABİT stub'larından bilerek FARKLI: oradaki 63 testi bozmamak için ayrı
// bir dosyada, kontrollü biçimde açılıyor.
$DEPO_FILTRE_SQL = ''; $DEPO_FILTRE_PARAMS = []; $DEPO_AKTIF = null;
function depo_sql_in(string $c): array { global $DEPO_FILTRE_SQL, $DEPO_FILTRE_PARAMS; return [$DEPO_FILTRE_SQL, $DEPO_FILTRE_PARAMS]; }
function active_depot(): ?string { global $DEPO_AKTIF; return $DEPO_AKTIF; }

require_once $ROOT . '/config/pdks.php';   // GERÇEK dosya

db()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, display_name TEXT, is_active INTEGER DEFAULT 1)");
db()->exec("CREATE TABLE employees (
    id INTEGER PRIMARY KEY AUTOINCREMENT, personnel_no TEXT, full_name TEXT, department TEXT DEFAULT '',
    job_title TEXT DEFAULT '', depo TEXT DEFAULT '', status TEXT DEFAULT 'aktif', user_id INTEGER,
    photo_file TEXT, photo_updated_at TEXT, phone TEXT, hire_date TEXT, leave_date TEXT, notes TEXT,
    created_by INTEGER, updated_by INTEGER, created_at TEXT DEFAULT '2026-01-01 10:00:00', updated_at TEXT)");
db()->exec("CREATE TABLE employee_cards (
    id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER, uid_hex TEXT, uid_bytes INTEGER DEFAULT 4,
    uid_decimal TEXT, card_type TEXT DEFAULT 'mifare_classic_1k', atqa TEXT, sak TEXT, label TEXT DEFAULT '',
    status TEXT DEFAULT 'aktif', issued_at TEXT, expires_at TEXT, replacement_card_id INTEGER,
    revoked_at TEXT, revoked_by INTEGER, revoke_reason TEXT DEFAULT '', enrolled_source TEXT DEFAULT 'usb_decimal',
    notes TEXT, created_by INTEGER, created_at TEXT DEFAULT '2026-01-02 09:00:00', updated_at TEXT)");

// "Masaüstünde Depo A aktifken oluşturulmuş" personel — depo='Depo A'
db()->exec("INSERT INTO employees (personnel_no, full_name, department, status, depo) VALUES ('101','Ahmet Yılmaz','Depo','aktif','Depo A')");
$empA = (int)db()->lastInsertId();
db()->exec("INSERT INTO employee_cards (employee_id, uid_hex, uid_decimal, status, label) VALUES ($empA, '25A87ED7', '631799511', 'aktif', 'Kart-01')");

function renderPage(string $file, array $get = []): string {
    global $ROOT;
    $_GET = $get; $_POST = []; $_FILES = []; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/' . $file;
    $src = file_get_contents($ROOT . '/' . $file);
    $src = preg_replace('/^\s*require_once __DIR__ \. \'\/(config\/db|config\/pdks|config\/auth)\.php\';\s*$/m', '', $src);
    $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
    $src = preg_replace('/^\s*pdks_migrate\(\);(?:\s*\/\/.*)?\s*$/m', '', $src);
    $src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
    $src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
    $tmp = sys_get_temp_dir() . '/pdksmobil_' . md5($file . serialize($get)) . '.php';
    file_put_contents($tmp, "<?php\n" . $src);
    ob_start();
    try { include $tmp; } catch (Throwable $e) { ob_end_clean(); return '__ERROR__: ' . $e->getMessage(); }
    return ob_get_clean();
}

$fail = 0; $gecen = 0;
function ok(string $ad, bool $cond, string $ipucu = ''): void {
    global $fail, $gecen;
    $cond ? $gecen++ : $fail++;
    printf("%-70s %s%s\n", $ad, $cond ? 'OK' : '*** HATA', ($cond ? '' : '  ' . $ipucu));
}

echo "\n=== 1. MOBİL ERİŞİM — Personeller sayfasında Giriş/Çıkış eylemi ===\n";
$PERMS = ['attendance.employees', 'attendance.cards', 'attendance.scan'];
$sayfa = renderPage('personel.php');
ok('hata sızmadı', !str_starts_with($sayfa, '__ERROR__'), $sayfa);
ok('attendance.scan varken "Giriş / Çıkış" eylemi VAR', str_contains($sayfa, 'Giriş / Çıkış') && str_contains($sayfa, 'giris_cikis.php'));
ok('link .page-head-actions İÇİNDE (masaüstü VE mobilde görünür — .page-head-actions gizli değil)',
    (bool)preg_match('/page-head-actions[\s\S]{0,400}giris_cikis\.php/', $sayfa));

echo "\n=== 2. YETKİ YOKKEN GİZLİ ===\n";
$PERMS = ['attendance.employees', 'attendance.cards'];   // scan YOK
$sayfaYetkisiz = renderPage('personel.php');
ok('hata sızmadı', !str_starts_with($sayfaYetkisiz, '__ERROR__'), $sayfaYetkisiz);
ok('attendance.scan YOKKEN "Giriş / Çıkış" eylemi GİZLİ', !str_contains($sayfaYetkisiz, 'giris_cikis.php'));
$PERMS = ['attendance.employees', 'attendance.cards', 'attendance.scan'];   // sıfırla

echo "\n=== 3. DEPO FİLTRESİ YOKKEN — kayıt normal görünür (regresyon) ===\n";
$DEPO_FILTRE_SQL = ''; $DEPO_FILTRE_PARAMS = []; $DEPO_AKTIF = null;
$normal = renderPage('personel.php');
ok('hata sızmadı', !str_starts_with($normal, '__ERROR__'), $normal);
ok('Ahmet Yılmaz listede görünüyor', str_contains($normal, 'Ahmet Yılmaz'));
ok('yanıltıcı "hiçbiri aktif depoda" notu YOK (filtre yok, gerek yok)', !str_contains($normal, 'aktif depo'));

echo "\n=== 4. DEPO FİLTRESİ AKTİF, KAYIT BAŞKA DEPODA — eski hata KANIT: root cause ===\n";
// Masaüstünde "Depo A" aktifken oluşturulan personel; şimdi (mobil gibi)
// "Depo B" aktif — depo_sql_in tam olarak canlıdaki gibi filtreler.
$DEPO_FILTRE_SQL = '(e.depo = ?)'; $DEPO_FILTRE_PARAMS = ['Depo B']; $DEPO_AKTIF = 'Depo B';
$farkliDepo = renderPage('personel.php');
ok('hata sızmadı', !str_starts_with($farkliDepo, '__ERROR__'), $farkliDepo);
ok('PHP Warning/Notice yok', !str_contains($farkliDepo, 'Warning:') && !str_contains($farkliDepo, 'Notice:'));
ok('YANILTICI "Henüz personel kaydı yok." ARTIK GÖSTERİLMİYOR (kayıt var, yalnız başka depoda)',
    !str_contains($farkliDepo, 'Henüz personel kaydı yok.'));
ok('Doğru teşhis mesajı VAR: kayıt sayısı görünüyor', (bool)preg_match('/Sistemde\s+1\s+personel kaydı var/u', $farkliDepo));
ok('Aktif depo adı ekranda ("Depo B")', str_contains($farkliDepo, 'Depo B'));
ok('"depo değiştir" bağlantısı depo_sec.php\'ye gidiyor', (bool)preg_match('/depo_sec\.php[^"]*"[^>]*>\s*depo değiştir/u', $farkliDepo));
ok('Ahmet Yılmaz (başka depoya ait) listede GÖRÜNMÜYOR (filtre ZAYIFLATILMADI)', !str_contains($farkliDepo, 'Ahmet Yılmaz'));

echo "\n=== 5. DEPO FİLTRESİ AKTİF, KAYIT AYNI DEPODA — normal görünür ===\n";
$DEPO_FILTRE_SQL = '(e.depo = ?)'; $DEPO_FILTRE_PARAMS = ['Depo A']; $DEPO_AKTIF = 'Depo A';
$ayniDepo = renderPage('personel.php');
ok('hata sızmadı', !str_starts_with($ayniDepo, '__ERROR__'), $ayniDepo);
ok('Ahmet Yılmaz (aynı depo) listede GÖRÜNÜYOR', str_contains($ayniDepo, 'Ahmet Yılmaz'));
ok('teşhis notu YOK (liste zaten dolu, gerek yok)', !str_contains($ayniDepo, 'Sistemde'));

echo "\n=== 6. GERÇEKTEN BOŞ TABLO — orijinal mesaj hâlâ doğru (regresyon) ===\n";
db()->exec('DELETE FROM employee_cards'); db()->exec('DELETE FROM employees');
$DEPO_FILTRE_SQL = ''; $DEPO_FILTRE_PARAMS = []; $DEPO_AKTIF = null;
$bosTablo = renderPage('personel.php');
ok('hata sızmadı', !str_starts_with($bosTablo, '__ERROR__'), $bosTablo);
ok('"Henüz personel kaydı yok." doğru şekilde gösteriliyor (gerçekten boş)', str_contains($bosTablo, 'Henüz personel kaydı yok.'));

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
