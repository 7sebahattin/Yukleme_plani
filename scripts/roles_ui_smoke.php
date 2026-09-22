<?php
// =========================================================
// scripts/roles_ui_smoke.php — roles.php render + akış testi
//
// SADECE CLI. Canlı veritabanına HİÇ dokunmaz: bellek içi SQLite ve
// stub'lanmış auth/render fonksiyonlarıyla roles.php'yi gerçekten
// render eder (GET ve POST), sonra HTML/DB durumunu doğrular.
//
//   php scripts/roles_ui_smoke.php    → çıkış kodu 0 = tüm testler geçti
//
// Kapsam: liste render, sistem rolü silme kilidi, kullanıcı atanmış
// role silme kilidi, "en az bir aktif kullanıcıda users.admin kalmalı"
// kilitlenme koruması (any_active_user_has_permission), slug'ın
// düzenlemede DEĞİŞMEMESİ, tekrarlı isim reddi.
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

// ── Stub'lar (users.php/hesap.php smoke testleriyle aynı desen) ──────────
function current_user(): ?array { return ['id' => 1, 'username' => 'test', 'display_name' => 'Test Admin']; }
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return 'testcsrf'; }
function csrf_check($t): void {}
function forbidden($m = ''): void { throw new RuntimeException('forbidden: ' . $m); }
function base_url(): string { return '/'; }
function render_header(string $t, bool $p = false): void { echo "<!doctype html><html><head><title>" . h($t) . "</title></head><body><main>"; }
function render_footer(bool $p = false): void { echo "</main></body></html>"; }

$AUDIT_LOG = [];
function audit_log_event(string $action, string $module, ?int $id = null, ?array $old = null, ?array $new = null): void {
    global $AUDIT_LOG;
    $AUDIT_LOG[] = compact('action', 'module', 'id', 'old', 'new');
}

// Test kataloğu — gerçek permission_catalog()'un küçültülmüş bir aynası,
// yalnız grid render + gruplama davranışını sınamak için.
function permission_catalog(): array {
    return [
        'Operasyon' => [
            'records.read'  => 'Kayıtları görüntüle',
            'records.write' => 'Kayıt oluştur / düzenle',
        ],
        'Personel Takibi' => [
            'attendance.daily_scan'    => 'Günlük işçi giriş-çıkış taraması yap',
            'attendance.daily_reports' => 'Günlük puantaj raporlarını görüntüle',
        ],
    ];
}
function protected_role_slugs(): array { return ['admin', 'operator']; }

$ANY_ACTIVE_HAS_PERM = true;
function any_active_user_has_permission(string $permission): bool {
    global $ANY_ACTIVE_HAS_PERM;
    return $ANY_ACTIVE_HAS_PERM;
}

// ── Şema + örnek veri ──────────────────────────────────────────────────
db()->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT UNIQUE, label TEXT, created_at TEXT DEFAULT '2026-01-01')");
db()->exec("CREATE TABLE role_permissions (role_id INT, permission TEXT, PRIMARY KEY(role_id, permission))");
db()->exec("CREATE TABLE user_roles (user_id INT, role_id INT, PRIMARY KEY(user_id, role_id))");

$insR = db()->prepare("INSERT INTO roles (id, slug, label) VALUES (?, ?, ?)");
$insR->execute([1, 'admin', 'Sistem Yöneticisi']);
$insR->execute([2, 'operator', 'Operatör']);
$insR->execute([3, 'depo_sorumlusu', 'Depo Sorumlusu']); // özel, 0 kullanıcı → silinebilir
$insR->execute([4, 'eski_rol', 'Eski Rol']);             // özel, 1 kullanıcı → silinemez

$insP = db()->prepare("INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)");
$insP->execute([1, 'records.read']);
$insP->execute([1, 'records.write']);
$insP->execute([3, 'attendance.daily_scan']);

db()->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)")->execute([2, 4]);

// ── roles.php'yi require/exit'lerden arındırıp render eden yardımcı ──────
function renderPage(array $post = []): string {
    global $ROOT;
    $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    $_SERVER['REQUEST_URI'] = '/roles.php';

    $src = file_get_contents($ROOT . '/roles.php');
    // roles.php'nin üst-seviye yardımcı fonksiyonları (valid_role_label,
    // role_slug_from_label, render_permission_grid) her renderPage()
    // çağrısında YENİDEN dahil edilir; aynı process içinde ikinci çağrıda
    // "Cannot redeclare function" ile patlamasın diye function_exists
    // kapısına alınır (beyan_ui_smoke.php'deki aynı desen).
    $hStart = strpos($src, "// ── Yardımcılar");
    $hEnd   = strpos($src, "// ── POST Handler");
    if ($hStart !== false && $hEnd !== false) {
        $block = substr($src, $hStart, $hEnd - $hStart);
        $src = substr($src, 0, $hStart)
             . "if (!function_exists('valid_role_label')) {\n" . $block . "\n}\n"
             . substr($src, $hEnd);
    }
    $src = preg_replace('/^\s*require_once __DIR__ \. \'\/config\/(db|auth)\.php\';\s*$/m', '', $src);
    $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
    $src = preg_replace('/^\s*require_perm\([^)]*\);\s*$/m', '', $src);
    // Başarılı POST dallarındaki header()+exit çiftini kaldır: test aynı
    // process içinde çalıştığı için gerçek exit süreci öldürür. Kaldırılınca
    // akış listeyi yeniden sorgulayan alt bölüme düşer — güncel DB durumunu
    // aynı render'da doğrulamak için elverişli.
    $src = preg_replace('/header\(\'Location: roles\.php\?ok=\'[^;]*;\s*\n\s*exit;\n/', '', $src);
    // SQLite "INSERT IGNORE" söz dizimini tanımaz (MySQL'e özgü) — yalnız
    // bu TEST kopyasında SQLite eşdeğerine çevrilir, üretim dosyası dokunulmaz.
    $src = str_replace('INSERT IGNORE INTO', 'INSERT OR IGNORE INTO', $src);
    $src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
    $src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);

    $tmp = sys_get_temp_dir() . '/rolesui_' . md5(serialize($post)) . '.php';
    file_put_contents($tmp, "<?php\n" . $src);
    ob_start();
    try { include $tmp; } catch (Throwable $e) { ob_end_clean(); return '__ERROR__: ' . $e->getMessage(); }
    return ob_get_clean();
}

$fail = 0;
function ok(string $ad, bool $cond, string $ipucu = ''): void {
    global $fail;
    if (!$cond) $fail++;
    printf("%-70s %s%s\n", $ad, $cond ? 'OK' : '*** FAIL', $cond ? '' : "  → $ipucu");
}
function cnt(string $h, string $needle): int { return substr_count($h, $needle); }
// İlk "Sil" formunu (id değeri eşleşen) bulup içinde disabled var mı bakar.
function deleteFormDisabled(string $html, int $id): bool {
    if (!preg_match('/name="id" value="' . $id . '">(.*?)<\/form>/s', $html, $m)) return true;
    return str_contains($m[1], 'disabled');
}

// ── 1) Liste render ───────────────────────────────────────────────────
$list = renderPage();
ok('hata sızmadı', !str_starts_with($list, '__ERROR__'), $list);
ok('PHP Warning/Notice yok', !str_contains($list, 'Warning:') && !str_contains($list, 'Notice:'));
ok('"Yeni Rol" butonu var', str_contains($list, 'Yeni Rol'));
ok('4 rol de listede', cnt($list, 'rol-badge-slug') >= 4 * 2, 'her rol tablo+kart için 2 kez basılır');
ok('admin "Sistem" rozetiyle', (bool)preg_match('/Sistem Yöneticisi.*?rol-badge-system/s', $list));
ok('Depo Sorumlusu Sil AKTİF (0 kullanıcı)', !deleteFormDisabled($list, 3));
ok('Eski Rol Sil PASİF (1 kullanıcı atanmış)', deleteFormDisabled($list, 4));
ok('admin Sil PASİF (sistem rolü)', deleteFormDisabled($list, 1));
ok('yetki grubu "Operasyon" görünüyor', str_contains($list, 'Operasyon'));
ok('yetki grubu "Personel Takibi" görünüyor', str_contains($list, 'Personel Takibi'));
ok('"Günlük işçi giriş-çıkış taraması yap" checkbox etiketi var', str_contains($list, 'Günlük işçi giriş-çıkış taraması yap'));

// ── 2) Yeni rol oluştur ────────────────────────────────────────────────
$created = renderPage(['csrf' => 'x', 'action' => 'create_role', 'label' => 'Depo Kontrol',
    'permissions' => ['attendance.daily_scan']]);
ok('oluşturma hatasız', !str_starts_with($created, '__ERROR__'), $created);
ok('yeni rol listede görünüyor', str_contains($created, 'Depo Kontrol'));
ok('otomatik slug üretildi', str_contains($created, 'depo_kontrol'));
ok('audit "create" olayı yazıldı', !empty($AUDIT_LOG) && end($AUDIT_LOG)['action'] === 'create' && end($AUDIT_LOG)['module'] === 'roles');

$row = db()->query("SELECT id, slug FROM roles WHERE label = 'Depo Kontrol'")->fetch();
ok('DB\'de rol satırı oluştu', $row !== false);
$permRows = db()->prepare("SELECT permission FROM role_permissions WHERE role_id = ?");
$permRows->execute([(int)$row['id']]);
ok('seçilen yetki role_permissions\'a yazıldı',
    in_array('attendance.daily_scan', $permRows->fetchAll(PDO::FETCH_COLUMN), true));

// ── 3) Aynı isimle ikinci rol reddedilir ────────────────────────────────
$dup = renderPage(['csrf' => 'x', 'action' => 'create_role', 'label' => 'Depo Kontrol', 'permissions' => []]);
ok('mükerrer isim reddedildi', str_contains($dup, 'zaten var'));
$dupCount = (int)db()->query("SELECT COUNT(*) FROM roles WHERE label = 'Depo Kontrol'")->fetchColumn();
ok('DB\'de hâlâ tek satır var', $dupCount === 1);

// ── 4) Kilitlenme koruması: son users.admin kaybolacaksa geri al ────────
$ANY_ACTIVE_HAS_PERM = false;
$before = db()->query("SELECT permission FROM role_permissions WHERE role_id = 1")->fetchAll(PDO::FETCH_COLUMN);
$lockout = renderPage(['csrf' => 'x', 'action' => 'update_role', 'id' => '1', 'label' => 'Sistem Yöneticisi',
    'permissions' => []]);
ok('kilitlenme hatası gösterildi', str_contains($lockout, 'aktif kullanıcı kalmaz'));
$after = db()->query("SELECT permission FROM role_permissions WHERE role_id = 1")->fetchAll(PDO::FETCH_COLUMN);
sort($before); sort($after);
ok('rollback: admin yetkileri DEĞİŞMEDİ', $before === $after);

// ── 5) Normal düzenleme: ad değişir, slug SABİT kalır ────────────────────
$ANY_ACTIVE_HAS_PERM = true;
$edited = renderPage(['csrf' => 'x', 'action' => 'update_role', 'id' => '3', 'label' => 'Depo Sorumlusu (Vardiya)',
    'permissions' => ['records.read']]);
ok('düzenleme hatasız', !str_starts_with($edited, '__ERROR__'), $edited);
ok('yeni ad listede', str_contains($edited, 'Depo Sorumlusu (Vardiya)'));
$slugRow = db()->query("SELECT slug FROM roles WHERE id = 3")->fetch();
ok('slug değişmedi', $slugRow['slug'] === 'depo_sorumlusu');
$newPerms = db()->prepare("SELECT permission FROM role_permissions WHERE role_id = 3");
$newPerms->execute();
ok('yetki seti değişti (records.read)', in_array('records.read', $newPerms->fetchAll(PDO::FETCH_COLUMN), true));

// ── 6) Silme kilitleri ────────────────────────────────────────────────
$delSystem = renderPage(['csrf' => 'x', 'action' => 'delete_role', 'id' => '1']);
ok('sistem rolü silinemedi (hata)', str_contains($delSystem, 'sistem rolü silinemez'));
ok('admin DB\'de hâlâ var', (int)db()->query("SELECT COUNT(*) FROM roles WHERE id = 1")->fetchColumn() === 1);

$delAssigned = renderPage(['csrf' => 'x', 'action' => 'delete_role', 'id' => '4']);
ok('kullanıcı atanmış rol silinemedi (hata)', str_contains($delAssigned, 'kullanıcı var'));
ok('Eski Rol DB\'de hâlâ var', (int)db()->query("SELECT COUNT(*) FROM roles WHERE id = 4")->fetchColumn() === 1);

$delOk = renderPage(['csrf' => 'x', 'action' => 'delete_role', 'id' => '3']);
ok('kullanıcısız özel rol silindi', !str_starts_with($delOk, '__ERROR__'), $delOk);
ok('rol DB\'den kalktı', (int)db()->query("SELECT COUNT(*) FROM roles WHERE id = 3")->fetchColumn() === 0);
ok('role_permissions da temizlendi', (int)db()->query("SELECT COUNT(*) FROM role_permissions WHERE role_id = 3")->fetchColumn() === 0);
ok('silinen rol listede artık yok', !str_contains($delOk, 'Depo Sorumlusu (Vardiya)'));

echo "\n" . ($fail === 0 ? "TÜMÜ GEÇTİ" : "$fail TEST BAŞARISIZ") . "\n";
exit($fail === 0 ? 0 : 1);
