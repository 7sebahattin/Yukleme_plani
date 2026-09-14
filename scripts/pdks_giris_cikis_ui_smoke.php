<?php
// =========================================================
// scripts/pdks_giris_cikis_ui_smoke.php — Giriş/Çıkış sayfası arayüz (render) testi
//
// SADECE CLI. Canlı veritabanına HİÇ dokunmaz: bellek içi SQLite ve
// stub'lanmış auth/render fonksiyonlarıyla giris_cikis.php'yi GERÇEKTEN
// render eder (pdks_faz1b_ui_smoke.php ile aynı desen).
//
//   php scripts/pdks_giris_cikis_ui_smoke.php    → çıkış kodu 0 = tüm testler geçti
//
// ⚠ config/pdks.php burada STUB DEĞİL — gerçek dosya require edilir.
// ⚠ Sayfanın kendisi hiçbir header()+exit içermez (GET akışında) — bu
//    yüzden pdks_faz1b_ui_smoke.php'deki gibi bir hazard burada YOK.
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

$ROOT = dirname(__DIR__);

// ── Stub'lar (pdks_faz1b_ui_smoke.php ile aynı desen) ──────
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

$PERMS = ['attendance.scan'];
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

require_once $ROOT . '/config/pdks.php';   // GERÇEK dosya — test edilen budur

// ── Basitleştirilmiş şema (yalnız kolon adları; kısıtlar pdks_db_smoke.php'de) ──
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
db()->exec("CREATE TABLE employee_card_uids (id INTEGER PRIMARY KEY AUTOINCREMENT, card_id INTEGER, uid_hex TEXT, kind TEXT DEFAULT 'canonical', created_at TEXT)");
db()->exec("CREATE TABLE attendance_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER, card_id INTEGER, event_type TEXT,
    source TEXT, canonical_uid_snapshot TEXT, recorded_by_user_id INTEGER,
    server_event_time TEXT, created_at TEXT DEFAULT '2026-01-01 10:00:00')");
db()->exec("CREATE TABLE attendance_gates (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, depo TEXT DEFAULT '', is_active INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 0)");

db()->exec("INSERT INTO users (username, display_name) VALUES ('gkullanici','Görev Kullanıcısı')");
db()->exec("INSERT INTO employees (personnel_no, full_name, department, status) VALUES ('101','Ahmet Yılmaz','Depo','aktif')");
$empA = (int)db()->lastInsertId();
db()->exec("INSERT INTO employee_cards (employee_id, uid_hex, uid_decimal, status, label) VALUES ($empA, '25A87ED7', '631799511', 'aktif', 'Kart-01')");
$cardA = (int)db()->lastInsertId();
db()->exec("INSERT INTO employee_card_uids (card_id, uid_hex, kind, created_at) VALUES ($cardA, '25A87ED7', 'canonical', '2026-01-02 09:00:00')");

// ── Sayfayı require'ları sıyırarak çalıştır (hesap_ui_smoke.php deseni) ──
function renderPage(string $file, array $get = [], string $method = 'GET', ?string $body = null): string {
    global $ROOT;
    $_GET = $get; $_POST = []; $_FILES = []; $_SERVER['REQUEST_METHOD'] = $method;
    $src = file_get_contents($ROOT . '/' . $file);
    $src = preg_replace('/^\s*require_once __DIR__ \. \'\/(config\/db|config\/pdks|config\/auth)\.php\';\s*$/m', '', $src);
    $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
    $src = preg_replace('/^\s*pdks_migrate\(\);(?:\s*\/\/.*)?\s*$/m', '', $src);
    if ($body !== null) {
        // php://input testte gerçek istek gövdesini yansıtmaz — test amaçlı
        // bir sarmalayıcı fonksiyonla değiştiriyoruz (yalnız bu betikte).
        $src = str_replace("file_get_contents('php://input')", '$GLOBALS[\'__TEST_BODY__\']', $src);
    }
    // ⚠ HAZARD (bkz. pdks_faz1b_ui_smoke.php §192 notu): PHP exit()/die() bir
    // include() İÇİNDEN bile YAKALANAMAZ — TÜM test sürecini sonlandırırdı.
    // giris_cikis.php'nin ajax=kaydet ucu JSON yanıtını basıp exit; ile biter;
    // bu dosyada TÜM exit; çağrıları (kontrol edildi: hepsi üst seviye
    // ajax bloğunun içinde, bir fonksiyonun içinde DEĞİL) include edilen
    // bir dosya için GÜVENLİ eşdeğeri olan return;'e çevrilir — bu yalnız
    // include'u sonlandırır, test sürecini DEĞİL.
    $src = preg_replace('/\bexit;/', 'return;', $src);
    // header() CLI'da (bu test sürecinin kendisi zaten stdout'a metin yazdığı
    // için) her zaman "headers already sent" UYARISI verir — gerçek bir hata
    // DEĞİL, yalnız test ortamının bir eseri. JSON çıktısı zaten
    // Content-Type'a bakılmaksızın doğru üretiliyor; testte yalnız GÖVDEYİ
    // doğruluyoruz, bu yüzden çağrıyı burada kaldırıyoruz.
    $src = preg_replace("/^\s*header\('Content-Type: application\/json[^)]*\);\s*$/m", '', $src);
    $src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
    $src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
    $tmp = sys_get_temp_dir() . '/pdksgcui_' . md5($file . serialize($get) . $method . (string)$body) . '.php';
    file_put_contents($tmp, "<?php\n" . $src);
    $GLOBALS['__TEST_BODY__'] = $body;
    ob_start();
    try { include $tmp; } catch (Throwable $e) { ob_end_clean(); return '__ERROR__: ' . $e->getMessage(); }
    return ob_get_clean();
}

$fail = 0; $gecen = 0;
function ok(string $ad, bool $cond, string $ipucu = ''): void {
    global $fail, $gecen;
    $cond ? $gecen++ : $fail++;
    printf("%-64s %s%s\n", $ad, $cond ? 'OK' : '*** HATA', ($cond ? '' : '  ' . $ipucu));
}

echo "\n=== giris_cikis.php — GET, yetkili kullanıcı (attendance.scan) ===\n";
$sayfa = renderPage('giris_cikis.php');
ok('hata/uyarı sızmadı',           !str_starts_with($sayfa, '__ERROR__'), $sayfa);
ok('PHP Warning/Notice/Deprecated yok',
    !str_contains($sayfa, 'Warning:') && !str_contains($sayfa, 'Notice:') && !str_contains($sayfa, 'Deprecated:'));
ok('sayfa başlığı doğru',          str_contains($sayfa, 'Giriş / Çıkış'));
ok('mod seçim ekranı var (GİRİŞ/ÇIKIŞ butonları)', str_contains($sayfa, 'GİRİŞ MODU') && str_contains($sayfa, 'ÇIKIŞ MODU'));
ok('tarama ekranı işaretlemesi var (başlangıçta gizli)', (bool)preg_match('/id="gcScanSec"[^>]*hidden/', $sayfa));
ok('USB girişi (erişilebilir gizli input) var', str_contains($sayfa, 'id="gcScanInput"'));
ok('csrf token gömülü',            str_contains($sayfa, 'id="gcCsrf"') && str_contains($sayfa, 'testcsrf'));
ok('pdks.css yüklendi',            str_contains($sayfa, 'assets/pdks.css'));
ok('NFC butonu DOM\'da var (JS ile gösterilir/gizlenir)', str_contains($sayfa, 'id="gcNfcBtn"'));

// ── Paylaşılan Web NFC okuma yolu GERÇEKTEN basılıyor mu (render düzeyi) ──
// Statik test kaynakta arar; burada ÇIKTIDA doğrulanır: helper sayfada VAR,
// TEK KOPYA ve kendisini KULLANAN script'ten ÖNCE geliyor (aksi hâlde
// PdksNfcOku tanımsız olurdu ve NFC butonu canlıda sessizce ölürdü).
$helperPoz  = strpos($sayfa, 'window.PdksNfcOku');
$kullanPoz  = strpos($sayfa, 'PdksNfcOku.destekli()');
ok('paylaşılan okuma yolu (PdksNfcOku) render edilen sayfada VAR', $helperPoz !== false);
ok('sayfa onu KULLANIYOR (PdksNfcOku.destekli)', $kullanPoz !== false);
ok('helper, kullanıldığı script\'ten ÖNCE basılıyor (tanımsız olamaz)',
    $helperPoz !== false && $kullanPoz !== false && $helperPoz < $kullanPoz);
ok('helper TEK KOPYA basılıyor (pdks_nfc_oku_js static guard)',
    substr_count($sayfa, 'window.PdksNfcOku = ') === 1);
// Açıklayıcı JS yorumları da tarayıcıya gider ve "AbortController" /
// "new NDEFReader()" kelimelerini METİN olarak taşır — GERÇEK KODA bakmak
// için yorum satırları çıkarılır (statik testteki $srcKod ile aynı desen).
$sayfaKod = preg_replace('/^\s*\/\/.*$/m', '', $sayfa);
ok('sayfa KENDİ new NDEFReader()ını basmıyor — yalnız paylaşılan yolda',
    substr_count($sayfaKod, 'new NDEFReader()') === 1);
ok('render edilen GERÇEK KODDA AbortController/signal YOK (canlı hatanın kaynağı)',
    !str_contains($sayfaKod, 'new AbortController()') && !preg_match('/\.scan\(\s*\{/', $sayfaKod));
ok('"KARTINIZI OKUTUN" metni var', str_contains($sayfa, 'KARTINIZI OKUTUN'));

echo "\n=== YETKİ KAPISI — sayfa render anında GERÇEKTEN çalışıyor ===\n";
$PERMS = [];   // attendance.scan YOK
$red = renderPage('giris_cikis.php');
ok('attendance.scan yokken REDDEDİLİYOR', str_starts_with($red, '__ERROR__: forbidden'), $red);
$PERMS = ['attendance.employees', 'attendance.cards'];   // Faz 1B yetkisi var ama scan YOK
$red2 = renderPage('giris_cikis.php');
ok('yalnız employees/cards yetkisiyle (scan YOK) REDDEDİLİYOR', str_starts_with($red2, '__ERROR__: forbidden'), $red2);
$PERMS = ['attendance.scan'];   // sıfırla

echo "\n=== ajax=kaydet — GEÇERLİ USB okuması GİRİŞ yazar ===\n";
$govde = json_encode(['csrf' => 'testcsrf', 'ham_uid' => '631799511', 'kaynak' => 'usb_decimal', 'event_type' => 'GIRIS']);
$cevap = renderPage('giris_cikis.php', ['ajax' => 'kaydet'], 'POST', $govde);
ok('hata sızmadı',      !str_starts_with($cevap, '__ERROR__'), $cevap);
$d = json_decode($cevap, true);
ok('JSON geçerli',      is_array($d), $cevap);
ok('ok=true',            ($d['ok'] ?? null) === true, $cevap);
ok('event_type=GIRIS yansıdı', ($d['event_type'] ?? null) === 'GIRIS');
ok('personel adı yanıtta',      ($d['employee']['full_name'] ?? null) === 'Ahmet Yılmaz');
ok('personnel_no yanıtta',      ($d['employee']['personnel_no'] ?? null) === '101');
ok('avatar/foto HTML\'i yanıtta', str_contains((string)($d['employee']['photo_html'] ?? ''), 'pdks-avatar'));
ok('DB\'ye gerçekten yazıldı (attendance_events)',
    (int)db()->query("SELECT COUNT(*) FROM attendance_events WHERE employee_id = $empA AND event_type = 'GIRIS'")->fetchColumn() === 1);

echo "\n=== ajax=kaydet — HEMEN tekrar aynı yön → mükerrer reddi ===\n";
$cevap2 = renderPage('giris_cikis.php', ['ajax' => 'kaydet'], 'POST', $govde);
$d2 = json_decode($cevap2, true);
ok('ok=false (mükerrer)', ($d2['ok'] ?? null) === false);
ok('kod=mukerrer',        ($d2['kod'] ?? null) === 'mukerrer');

echo "\n=== ajax=kaydet — tanımsız kart ===\n";
$govdeBil = json_encode(['csrf' => 'testcsrf', 'ham_uid' => '999888777', 'kaynak' => 'usb_decimal', 'event_type' => 'GIRIS']);
$cevapBil = renderPage('giris_cikis.php', ['ajax' => 'kaydet'], 'POST', $govdeBil);
$dBil = json_decode($cevapBil, true);
ok('tanımsız kart ok=false', ($dBil['ok'] ?? null) === false);
ok('kod=kart_tanimsiz',      ($dBil['kod'] ?? null) === 'kart_tanimsiz');

echo "\n=== ajax=kaydet — Web NFC ile AYNI fiziksel kart (d7:7e:a8:25 → 25A87ED7) ===\n";
// Cooldown'a takılmaması için farklı bir yön (ÇIKIŞ) kullanılıyor.
$govdeNfc = json_encode(['csrf' => 'testcsrf', 'ham_uid' => 'd7:7e:a8:25', 'kaynak' => 'web_nfc', 'event_type' => 'CIKIS']);
$cevapNfc = renderPage('giris_cikis.php', ['ajax' => 'kaydet'], 'POST', $govdeNfc);
$dNfc = json_decode($cevapNfc, true);
ok('Web NFC okuması AYNI kartı/employeeı buluyor', ($dNfc['ok'] ?? null) === true, $cevapNfc);
ok('event_type=CIKIS',                              ($dNfc['event_type'] ?? null) === 'CIKIS');
ok('personel adı Ahmet Yılmaz (USB ile atanan kartla AYNI kart)', ($dNfc['employee']['full_name'] ?? null) === 'Ahmet Yılmaz');

echo "\n=== ajax=kaydet — geçersiz kaynak reddedilir ===\n";
$govdeKotu = json_encode(['csrf' => 'testcsrf', 'ham_uid' => '631799511', 'kaynak' => 'bilinmiyor', 'event_type' => 'GIRIS']);
$cevapKotu = renderPage('giris_cikis.php', ['ajax' => 'kaydet'], 'POST', $govdeKotu);
$dKotu = json_decode($cevapKotu, true);
ok('geçersiz kaynak ok=false', ($dKotu['ok'] ?? null) === false);
ok('kod=gecersiz_kaynak',      ($dKotu['kod'] ?? null) === 'gecersiz_kaynak');

echo "\n=== ajax=kaydet — mod seçilmeden (event_type boş) reddedilir ===\n";
$govdeYon = json_encode(['csrf' => 'testcsrf', 'ham_uid' => '631799511', 'kaynak' => 'usb_decimal', 'event_type' => '']);
$cevapYon = renderPage('giris_cikis.php', ['ajax' => 'kaydet'], 'POST', $govdeYon);
$dYon = json_decode($cevapYon, true);
ok('boş yön ok=false',   ($dYon['ok'] ?? null) === false);
ok('kod=gecersiz_yon',   ($dYon['kod'] ?? null) === 'gecersiz_yon');

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
