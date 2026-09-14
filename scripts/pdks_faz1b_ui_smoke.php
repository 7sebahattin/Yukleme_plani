<?php
// =========================================================
// scripts/pdks_faz1b_ui_smoke.php — PDKS Faz 1B arayüz (render) testi
//
// SADECE CLI. Canlı veritabanına HİÇ dokunmaz: bellek içi SQLite ve
// stub'lanmış auth/depo/render fonksiyonlarıyla personel_*.php sayfalarını
// GERÇEKTEN render eder, sonra HTML'i doğrular (hesap_ui_smoke.php deseni).
//
//   php scripts/pdks_faz1b_ui_smoke.php    → çıkış kodu 0 = tüm testler geçti
//
// ⚠ config/pdks.php burada STUB DEĞİL — gerçek dosya require edilir. Yalnız
// db()/current_user()/can()/h()/render_header() gibi ÇEVRE fonksiyonları
// stub'lanır. Yani buradaki testler config/pdks.php'nin GERÇEK kodunu,
// SQLite üzerinde, sayfa bağlamı içinde çalıştırır.
//
// Kapsam: PHP uyarı sızıntısı yok, form alanlarında çift `name=` yok
// (POST çakışması riski), yetki kapısı sayfa render anında GERÇEKTEN
// çalışıyor, kart bölümünün doğru koşullarda görünür/gizli olması.
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

$ROOT = dirname(__DIR__);

// ── Stub'lar (hesap_ui_smoke.php ile aynı desen) ──────────
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

$PERMS = ['attendance.read', 'attendance.employees', 'attendance.cards', 'attendance.report'];
$IS_ADMIN = false;
function current_user(): ?array { return ['id' => 1, 'username' => 'test', 'display_name' => 'Test Kullanıcı']; }
function can(string $p): bool { global $PERMS; return in_array($p, $PERMS, true); }
function is_admin(): bool { global $IS_ADMIN; return $IS_ADMIN; }
function depo_sql_in(string $c): array { return ['', []]; }
function depot_visible_to_user(?string $d): bool { return true; }
function audit_log_event(...$a): void {}
function active_depot(): ?string { return null; }
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function fmt_date(?string $d): string { if (!$d) return ''; $t = strtotime($d); return $t ? date('d.m.Y', $t) : h($d); }
function fmt_datetime(?string $d): string { if (!$d) return ''; $t = strtotime($d); return $t ? date('d.m.Y H:i', $t) : h($d); }
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

db()->exec("INSERT INTO users (username, display_name) VALUES ('gkullanici','Görev Kullanıcısı')");

// Personel A — aktif kart var
db()->exec("INSERT INTO employees (personnel_no, full_name, department, job_title, status) VALUES ('101','Ahmet Yılmaz','Depo','Sevkiyat','aktif')");
$empA = (int)db()->lastInsertId();
db()->exec("INSERT INTO employee_cards (employee_id, uid_hex, uid_decimal, status, label) VALUES ($empA, '25A87ED7', '631799511', 'aktif', 'Kart-01')");
$cardA = (int)db()->lastInsertId();
db()->exec("INSERT INTO employee_card_uids (card_id, uid_hex, kind, created_at) VALUES ($cardA, '25A87ED7', 'canonical', '2026-01-02 09:00:00')");

// Personel B — kartı yok
db()->exec("INSERT INTO employees (personnel_no, full_name, department, status) VALUES ('102','Zeynep Kaya','Ofis','pasif')");
$empB = (int)db()->lastInsertId();

// Personel C — geçmişte kartı olmuş ama artık iptal (aktif kart yok, GEÇMİŞ var)
db()->exec("INSERT INTO employees (full_name, status) VALUES ('Mehmet Can Öz','aktif')");
$empC = (int)db()->lastInsertId();
db()->exec("INSERT INTO employee_cards (employee_id, uid_hex, uid_decimal, status, revoke_reason, revoked_at) VALUES ($empC, 'D77EA825', '3615402021', 'iptal', 'Kart bulunamadı', '2026-01-05 12:00:00')");

// ── Sayfayı require'ları sıyırarak çalıştır (hesap_ui_smoke.php ile aynı desen) ──
function renderPage(string $file, array $get = []): string {
    global $ROOT;
    $_GET = $get; $_POST = []; $_FILES = []; $_SERVER['REQUEST_METHOD'] = 'GET';
    $src = file_get_contents($ROOT . '/' . $file);
    // ⚠ Sprint Günlük-İşçi-01: personel_kartlar.php/personel_form.php artık
    // config/pdks_gunluk.php'yi de require ediyor (çapraz-sistem UID kontrolü
    // için — bkz. o dosyanın başlığı). Test ortamında BU DOSYA yok, o yüzden
    // diğerleriyle AYNI şekilde satır bazında ÇIKARILIR.
    $src = preg_replace('/^\s*require_once __DIR__ \. \'\/(config\/db|config\/pdks|config\/pdks_gunluk|config\/auth)\.php\';.*$/m', '', $src);
    $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
    $src = preg_replace('/^\s*pdks_migrate\(\);(?:\s*\/\/.*)?\s*$/m', '', $src);
    $src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
    $src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
    $tmp = sys_get_temp_dir() . '/pdksui_' . md5($file . serialize($get)) . '.php';
    file_put_contents($tmp, "<?php\n" . $src);
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
function cnt(string $h, string $needle): int { return substr_count($h, $needle); }

echo "\n=== personel.php — boş liste ===\n";
db()->exec('DELETE FROM employees'); db()->exec('DELETE FROM employee_cards'); db()->exec('DELETE FROM employee_card_uids');
$bos = renderPage('personel.php');
ok('hata/uyarı sızmadı',              !str_starts_with($bos, '__ERROR__'), $bos);
ok('PHP Warning/Notice yok',          !str_contains($bos, 'Warning:') && !str_contains($bos, 'Notice:') && !str_contains($bos, 'Deprecated:'));
ok('boş durum mesajı görünüyor',      str_contains($bos, 'Henüz personel kaydı yok'));
ok('+ Yeni Personel butonu var',      str_contains($bos, 'Yeni Personel'));

// Veriyi geri yükle
db()->exec("INSERT INTO employees (id, personnel_no, full_name, department, job_title, status) VALUES ($empA, '101','Ahmet Yılmaz','Depo','Sevkiyat','aktif')");
db()->exec("INSERT INTO employee_cards (id, employee_id, uid_hex, uid_decimal, status, label) VALUES ($cardA, $empA, '25A87ED7', '631799511', 'aktif', 'Kart-01')");
db()->exec("INSERT INTO employee_card_uids (card_id, uid_hex, kind, created_at) VALUES ($cardA, '25A87ED7', 'canonical', '2026-01-02 09:00:00')");
db()->exec("INSERT INTO employees (id, personnel_no, full_name, department, status) VALUES ($empB, '102','Zeynep Kaya','Ofis','pasif')");
db()->exec("INSERT INTO employees (id, full_name, status) VALUES ($empC, 'Mehmet Can Öz','aktif')");
db()->exec("INSERT INTO employee_cards (employee_id, uid_hex, uid_decimal, status, revoke_reason, revoked_at) VALUES ($empC, 'D77EA825', '3615402021', 'iptal', 'Kart bulunamadı', '2026-01-05 12:00:00')");

echo "\n=== personel.php — dolu liste ===\n";
$liste = renderPage('personel.php');
ok('hata sızmadı',                    !str_starts_with($liste, '__ERROR__'), $liste);
ok('PHP Warning/Notice yok',          !str_contains($liste, 'Warning:') && !str_contains($liste, 'Notice:'));
ok('Ahmet Yılmaz listede',            str_contains($liste, 'Ahmet Yılmaz'));
ok('aktif kart UID rozeti listede',   str_contains($liste, '25A87ED7'));
ok('kartsız personel için "Kart yok"',str_contains($liste, 'Kart yok'));
ok('fallback avatar (baş harf) görünüyor', str_contains($liste, 'pdks-avatar-bos'));
ok('durum rozeti (pasif)',            str_contains($liste, 'pdks-badge-pasif'));
ok('arama/filtre şeridi var',         str_contains($liste, 'pdks-filter-bar'));

echo "\n=== personel_form.php — yeni personel (id yok) ===\n";
$yeni = renderPage('personel_form.php');
ok('hata sızmadı',                    !str_starts_with($yeni, '__ERROR__'), $yeni);
ok('PHP Warning/Notice yok',          !str_contains($yeni, 'Warning:') && !str_contains($yeni, 'Notice:'));
ok('başlık "Yeni Personel"',          str_contains($yeni, 'Yeni Personel'));
ok('csrf token formda var',           str_contains($yeni, 'name="csrf"'));
ok('full_name alanı TEK (çift POST riski yok)', cnt($yeni, 'name="full_name"') === 1);
ok('status alanı TEK',                cnt($yeni, 'name="status"') === 1);
ok('user_id alanı TEK',               cnt($yeni, 'name="user_id"') === 1);
ok('action alanı save_employee',      str_contains($yeni, 'value="save_employee"'));
ok('TC kimlik alanı YOK (karar #2)',  !str_contains($yeni, 'tc_kimlik') && !str_contains($yeni, 'national_id') && !stripos($yeni, 'T.C. Kimlik'));
ok('yeni kayıtta Kart Yönetimi bölümü YOK', !str_contains($yeni, 'Kart Yönetimi'));
ok('yeni kayıtta Sil butonu YOK',     !str_contains($yeni, 'delete_employee'));

echo "\n=== personel_form.php — mevcut personel, AKTİF kartlı (Personel A) ===\n";
$duzenle = renderPage('personel_form.php', ['id' => (string)$empA]);
ok('hata sızmadı',                    !str_starts_with($duzenle, '__ERROR__'), $duzenle);
ok('PHP Warning/Notice yok',          !str_contains($duzenle, 'Warning:') && !str_contains($duzenle, 'Notice:'));
ok('ad soyad dolu geldi',             str_contains($duzenle, 'value="Ahmet Yılmaz"'));
ok('Kart Yönetimi bölümü VAR',        str_contains($duzenle, 'Kart Yönetimi'));
ok('aktif UID gösteriliyor',          str_contains($duzenle, '25A87ED7'));
ok('Değiştir/İptal/Kayıp butonları var', str_contains($duzenle, "pdksKartModalAc('degistir'") && str_contains($duzenle, "pdksKartModalAc('iptal'") && str_contains($duzenle, "pdksKartModalAc('kayip'"));
ok('"+ Kart Ata" butonu YOK (zaten aktif kartı var)', !str_contains($duzenle, "pdksKartModalAc('ata'"));
ok('kart eylem modalı işaretlemesi var', str_contains($duzenle, 'id="pdksKartModal"'));
ok('gerekçe alanı zorunlu işaretli',  str_contains($duzenle, 'id="pdksKartGerekce"'));
ok('Sil butonu YOK (kart geçmişi var)', !str_contains($duzenle, 'delete_employee'));

echo "\n=== personel_form.php — kartsız personel (Personel B) ===\n";
$kartsiz = renderPage('personel_form.php', ['id' => (string)$empB]);
ok('hata sızmadı',                    !str_starts_with($kartsiz, '__ERROR__'), $kartsiz);
ok('"Aktif kartı yok" mesajı',        str_contains($kartsiz, 'aktif kartı yok'));
ok('"+ Kart Ata" butonu VAR',         str_contains($kartsiz, "pdksKartModalAc('ata'"));
ok('Sil butonu VAR (hiç kartı olmadı)', str_contains($kartsiz, 'delete_employee'));

echo "\n=== personel_form.php — geçmişte kartı olmuş ama İPTAL edilmiş (Personel C) ===\n";
$gecmisli = renderPage('personel_form.php', ['id' => (string)$empC]);
ok('hata sızmadı',                    !str_starts_with($gecmisli, '__ERROR__'), $gecmisli);
ok('aktif kart yok mesajı (iptal edilmiş, aktif değil)', str_contains($gecmisli, 'aktif kartı yok'));
ok('kart geçmişi tablosu VAR (D77EA825 görünüyor)', str_contains($gecmisli, 'D77EA825'));
ok('iptal rozeti geçmişte görünüyor', str_contains($gecmisli, 'pdks-badge-iptal'));
ok('iptal gerekçesi görünüyor',       str_contains($gecmisli, 'Kart bulunamadı'));
ok('Sil butonu YOK (kart geçmişi var, iptal edilmiş olsa bile)', !str_contains($gecmisli, 'delete_employee'));

// ⚠ "olmayan personel" senaryosu BURADA test EDİLMEZ: personel_form.php o
// durumda header()+exit çağırır ve PHP'de exit() include içinden bile
// YAKALANAMAZ — tüm test sürecini sonlandırırdı. "Geçersiz personel ID"
// bunun yerine ALAN FONKSİYONU düzeyinde test edilir (bkz.
// scripts/pdks_faz1b_smoke.php — pdks_kart_ata()/pdks_personel_guncelle()
// var olmayan id ile 'personel_yok' döner), ki asıl mantık zaten orada.

echo "\n=== personel_kartlar.php — kart listesi + tanımlama formu ===\n";
$kartlar = renderPage('personel_kartlar.php');
ok('hata sızmadı',                    !str_starts_with($kartlar, '__ERROR__'), $kartlar);
ok('PHP Warning/Notice yok',          !str_contains($kartlar, 'Warning:') && !str_contains($kartlar, 'Notice:'));
ok('USB tarama kutusu var',           str_contains($kartlar, 'data-pdks-scan'));
ok('kaynak daima usb_decimal (gizli sabit)', str_contains($kartlar, "kaynak=usb_decimal") || str_contains($kartlar, 'usb_decimal'));
ok('aktif UID listede (Personel A)',  str_contains($kartlar, '25A87ED7'));
ok('iptal edilmiş kart da listede (geçmiş kaybolmuyor)', str_contains($kartlar, 'D77EA825'));
ok('atanabilir personel seçiminde YALNIZ kartsız+aktif olanlar', str_contains($kartlar, 'Zeynep Kaya') === false); // B pasif → listede yok
ok('employee_id alanı TEK',           cnt($kartlar, 'name="employee_id"') === 1);
ok('ham_uid alanı TEK',               cnt($kartlar, 'name="ham_uid"') === 1);

echo "\n=== YETKİ KAPISI — sayfa render anında GERÇEKTEN çalışıyor ===\n";
$PERMS = [];   // hiçbir attendance.* yetkisi yok
$red1 = renderPage('personel.php');
ok('personel.php yetkisiz erişimde REDDEDİLİYOR', str_starts_with($red1, '__ERROR__: forbidden'), $red1);
$red2 = renderPage('personel_form.php');
ok('personel_form.php yetkisiz erişimde REDDEDİLİYOR', str_starts_with($red2, '__ERROR__: forbidden'), $red2);
$red3 = renderPage('personel_kartlar.php');
ok('personel_kartlar.php yetkisiz erişimde REDDEDİLİYOR', str_starts_with($red3, '__ERROR__: forbidden'), $red3);

$PERMS = ['attendance.employees'];   // yalnız personel yetkisi, kart yetkisi YOK
$kismi = renderPage('personel_form.php', ['id' => (string)$empA]);
ok('yalnız employees yetkisiyle personel_form.php AÇILIYOR', !str_starts_with($kismi, '__ERROR__'), $kismi);
ok('  ama Kart Yönetimi bölümü GİZLİ (attendance.cards yok)', !str_contains($kismi, 'Kart Yönetimi'));
$redKart = renderPage('personel_kartlar.php');
ok('yalnız employees yetkisiyle personel_kartlar.php REDDEDİLİYOR (attendance.cards gerekir)',
    str_starts_with($redKart, '__ERROR__: forbidden'), $redKart);

$PERMS = ['attendance.read', 'attendance.employees', 'attendance.cards', 'attendance.report'];   // sıfırla

echo "\n=== FOTOĞRAF DOĞRULAMASI — gerçek dosya ile (pdks_foto_gecerli_mi) ===\n";
$gdVar = function_exists('imagecreatetruecolor');
ok('GD kurulu (bu ortamda)', $gdVar, 'GD yoksa foto testleri atlanır');
if ($gdVar) {
    $tmpImg = tempnam(sys_get_temp_dir(), 'pdksfoto');
    $im = imagecreatetruecolor(300, 200);
    imagejpeg($im, $tmpImg, 90);
    imagedestroy($im);
    $v = pdks_foto_gecerli_mi($tmpImg, filesize($tmpImg));
    ok('gerçek JPEG kabul edildi',        $v['ok'] === true, json_encode($v));
    ok('boyutlar doğru okundu',           ($v['genislik'] ?? 0) === 300 && ($v['yukseklik'] ?? 0) === 200);

    $v2 = pdks_foto_gecerli_mi($tmpImg, PDKS_FOTO_MAX_BOYUT + 1);
    ok('boyut sınırı aşımı reddedildi',   $v2['ok'] === false);

    $sahte = tempnam(sys_get_temp_dir(), 'pdksfake');
    file_put_contents($sahte, "<?php echo 'zararli'; ?>PNGdegil");
    $v3 = pdks_foto_gecerli_mi($sahte, filesize($sahte));
    ok('PHP dosyası (.jpg gibi maskelenmiş olsa bile) REDDEDİLDİ', $v3['ok'] === false);

    @unlink($tmpImg); @unlink($sahte);
}

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
