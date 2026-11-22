<?php
// =========================================================
// scripts/_puantaj_cizelgesi_harness.php — puantaj_cizelgesi smoke/render yardımcısı (SADECE CLI)
//
// Canlı DB'ye HİÇ dokunmaz: bellek içi SQLite + sahte auth/render fonksiyonlarıyla GERÇEK
// sayfa kodunu bir kez çalıştırır ve çıktısını stdout'a yazar (çıktı = HTML/CSV/XLSX baytı).
//   php scripts/_puantaj_cizelgesi_harness.php <sayfa.php> '<GET json>' '<seçenek json>'
// Seçenekler: perms[] · admin · depo (aktif depo) · allowed[] (kullanıcı depoları) · ay (tohum ayı) ·
//             css (true → stil dosyalarını file:// yoluyla bağlar; tarayıcı testi için)
//             v553: isaret_yok (true → puantaj_isaretler tablosu KURULMAZ) · isaretler [[emp, gün, kod, açıklama?],…] (tohum; gün = ayın günü sayısı) ·
//             post {alan:değer} (→ REQUEST_METHOD=POST, $_POST; Accept JSON) · dump (true → çıkışta tablo içeriği STDERR "ISARETLER: json")
// Çıkış kodu: 0 = sayfa çıktı verdi · 3 = forbidden() · 4 = sayfa istisna fırlattı. Denetim kayıtları STDERR'e "AUDIT: {json}".
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI'); }

$ROOT = dirname(__DIR__);
$SAYFA = (string)($argv[1] ?? 'puantaj_cizelgesi.php');
$GETJ = json_decode((string)($argv[2] ?? '{}'), true) ?: [];
$OPT = json_decode((string)($argv[3] ?? '{}'), true) ?: [];

$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

$PERMS = $OPT['perms'] ?? [];
$IS_ADMIN = (bool)($OPT['admin'] ?? false);
$AKTIF_DEPO = $OPT['depo'] ?? '';
$ALLOWED = $OPT['allowed'] ?? ['Depo A'];
function current_user(): ?array { return ['id' => 1, 'username' => 'test', 'display_name' => 'Test Kullanıcı']; }
function can(string $p): bool { global $PERMS, $IS_ADMIN; return $IS_ADMIN || in_array($p, $PERMS, true); }
function is_admin(): bool { global $IS_ADMIN; return $IS_ADMIN; }
function active_depot(): ?string { global $AKTIF_DEPO; return $AKTIF_DEPO === '' ? null : $AKTIF_DEPO; }
function depo_sql_in(string $col): array {
    global $ALLOWED;
    if (!$ALLOWED) return ['1=0', []];
    return ["($col IN (" . implode(',', array_fill(0, count($ALLOWED), '?')) . ") OR TRIM(COALESCE($col,'')) = '')", array_values($ALLOWED)];
}
$AUDIT_ON = false;
function audit_log_event(...$a): void { global $AUDIT_ON; if (!$AUDIT_ON) return; fwrite(STDERR, 'AUDIT: ' . json_encode($a, JSON_UNESCAPED_UNICODE) . "\n"); }
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return 'testcsrf'; }
function csrf_check($t): void {   // gerçek csrf_check gibi: geçersizse 403 + JSON, çıkış kodu 5
    if ($t === 'testcsrf') return;
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Güvenlik doğrulaması başarısız.', 'code' => 403], JSON_UNESCAPED_UNICODE);
    exit(5);
}
function depot_visible_to_user(?string $d): bool {   // user_allowed_depots() kalıbı: aktif depo seçiliyse yalnız o; boş depo her yerde görünür
    global $ALLOWED, $AKTIF_DEPO;
    if ($d === null || trim($d) === '') return true;
    $izinli = $AKTIF_DEPO !== '' ? [$AKTIF_DEPO] : $ALLOWED;
    return in_array($d, $izinli, true);
}
$FLASH = [];
function set_flash($a, $b): void { global $FLASH; $FLASH[] = [$a, $b]; }
function render_flash(): void { global $FLASH; foreach ($FLASH as [$a, $b]) echo '<div class="flash flash-' . h($a) . '">' . h($b) . '</div>'; $FLASH = []; }
function forbidden($m = ''): void {
    if (!empty($GLOBALS['OPT']['post'])) { echo json_encode(['ok' => false, 'error' => (string)$m, 'code' => 403], JSON_UNESCAPED_UNICODE); }
    else echo 'FORBIDDEN: ' . $m;
    exit(3);
}
function require_perm(string $p): void { if (!can($p) && !is_admin()) forbidden("perm $p"); }
function base_url(): string { return ''; }
function require_login(): array { return current_user(); }
function enforce_active_depot(): void {}
function render_header(string $t, bool $p = false): void {
    global $OPT, $ROOT;
    echo "<!doctype html><html lang=\"tr\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>" . h($t) . "</title>";
    if (!empty($OPT['css'])) echo '<link rel="stylesheet" href="file://' . $ROOT . '/assets/style.css">';
    echo "</head><body" . (($OPT['tema'] ?? '') === 'dark' ? ' ' : '') . "><main class=\"container\">";
}
function render_footer(bool $p = false): void { echo "</main></body></html>"; }

require_once $ROOT . '/config/pdks.php';
require_once $ROOT . '/config/pdks_gunluk.php';
require_once $ROOT . '/config/pdks_hakedis.php';
require_once $ROOT . '/config/pdks_cari.php';
require_once $ROOT . '/config/pdks_rapor.php';
require_once $ROOT . '/config/pdks_faz8b.php';
require_once $ROOT . '/config/pdks_faz8b_cavus_b.php';
require_once $ROOT . '/config/xlsx_export.php';
require_once $ROOT . '/config/print_helpers.php';
require_once $ROOT . '/config/puantaj_cizelgesi.php';

function pdks_ddl_sqlite(string $mysql): array
{
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m)) throw new RuntimeException('DDL ayrıştırılamadı');
    $tablo = $m[1]; $govde = $m[2]; $parcalar = []; $buf = ''; $derinlik = 0;
    for ($i = 0, $n = strlen($govde); $i < $n; $i++) {
        $c = $govde[$i];
        if ($c === '(') $derinlik++;
        if ($c === ')') $derinlik--;
        if ($c === ',' && $derinlik === 0) { $parcalar[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $parcalar[] = trim($buf);
    $kolonlar = []; $indeksler = [];
    foreach ($parcalar as $p) {
        $p = preg_replace('/\s+/', ' ', $p);
        if (preg_match('/^UNIQUE KEY `([^`]+)` \((.+)\)$/i', $p, $mm)) { $indeksler[] = "CREATE UNIQUE INDEX `{$mm[1]}` ON `{$tablo}` (" . preg_replace('/`\s*\(\s*\d+\s*\)/', '`', $mm[2]) . ")"; continue; }
        if (preg_match('/^(?:INDEX|KEY) `([^`]+)` \((.+)\)$/i', $p, $mm)) { $indeksler[] = "CREATE INDEX `{$mm[1]}` ON `{$tablo}` (" . preg_replace('/`\s*\(\s*\d+\s*\)/', '`', $mm[2]) . ")"; continue; }
        if (preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY/i', $p)) continue;
        $p = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
        $p = preg_replace('/\s*ON UPDATE CURRENT_TIMESTAMP\b/i', '', $p);
        $kolonlar[] = $p;
    }
    return ["CREATE TABLE `{$tablo}` (\n  " . implode(",\n  ", $kolonlar) . "\n)", $indeksler];
}

// ── Şema: SEÇENEK 'sema' = 'yok' → hiç tablo yok (nazik hata testi); 'personel' → yalnız personel; varsayılan hepsi ──
$SEMA = $OPT['sema'] ?? 'hepsi';
$TOHUM_AY = (string)($OPT['ay'] ?? date('Y-m'));
if ($SEMA !== 'yok') {
    db()->exec("CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `username` VARCHAR(60) NOT NULL, `display_name` VARCHAR(150) NULL, `is_active` INTEGER NOT NULL DEFAULT 1)");
    db()->exec("INSERT INTO users (id, username, display_name) VALUES (1, 'test', 'Test Kullanıcı')");
    foreach (pdks_tablolar() as $ad => $sql) {
        if (!in_array($ad, ['employees', 'employee_cards', 'employee_card_uids', 'attendance_events'], true)) continue;
        [$create, $ix] = pdks_ddl_sqlite($sql); db()->exec($create); foreach ($ix as $i) db()->exec($i);
    }
    // Personel tohumu: jenerik adlar; depo A / B / atanmamış / pasif
    $emp = db()->prepare("INSERT INTO employees (id, personnel_no, full_name, department, depo, status) VALUES (?,?,?,?,?,?)");
    foreach ([
        [1, 'P001', 'Örnek Personel A', 'Paketleme', 'Depo A', 'aktif'],
        [2, 'P002', 'Örnek Personel B', 'Yükleme', 'Depo A', 'aktif'],
        [3, 'P003', 'Örnek Personel C', '', '', 'aktif'],          // atanmamış depo → görünür
        [4, 'P004', 'Örnek Personel D', 'Depo B işi', 'Depo B', 'aktif'],   // başka depo → gizli
        [5, 'P005', 'Örnek Personel E', 'Ofis', 'Depo A', 'pasif'], // pasif → varsayılan gizli
        [6, 'P006', 'İsmail Örnek', 'Ofis', 'Depo A', 'aktif'],     // Türkçe arama testi
    ] as $r) $emp->execute($r);
    if (empty($OPT['isaret_yok'])) {
        foreach (pi_tablolar() as $ad => $sql) { [$create, $ix] = pdks_ddl_sqlite($sql); db()->exec($create); foreach ($ix as $i) db()->exec($i); }
        $isr = db()->prepare("INSERT INTO puantaj_isaretler (employee_id, tarih, kod, aciklama, user_id, created_at) VALUES (?,?,?,?,?,?)");
        foreach (($OPT['isaretler'] ?? []) as $r) $isr->execute([(int)$r[0], sprintf('%s-%02d', $TOHUM_AY, (int)$r[1]), $r[2], $r[3] ?? null, 1, date('Y-m-d H:i:s')]);
    }
    $ev = db()->prepare("INSERT INTO attendance_events (employee_id, card_id, event_type, source, canonical_uid_snapshot, server_event_time) VALUES (?,?,?,?,?,?)");
    $olay = function (int $e, string $t, string $z) use ($ev): void { $ev->execute([$e, 1, $t, 'usb_decimal', 'UID' . $e, $z]); };
    $m = $TOHUM_AY;
    $olay(1, 'GIRIS', "$m-03 08:00:00"); $olay(1, 'CIKIS', "$m-03 17:30:00");                       // 9,5 saat → X
    $olay(1, 'GIRIS', "$m-04 08:00:00"); $olay(1, 'CIKIS', "$m-04 12:00:00");                       // çift giriş/çıkış: 4 + 4 = 8
    $olay(1, 'GIRIS', "$m-04 13:00:00"); $olay(1, 'CIKIS', "$m-04 17:00:00");
    $olay(1, 'GIRIS', "$m-05 08:00:00");                                                            // çıkış eksik → !
    $olay(1, 'GIRIS', "$m-12 22:00:00"); $olay(1, 'CIKIS', "$m-13 06:00:00");                       // gece vardiyası → giriş gününe (12) 8 saat
    $olay(2, 'GIRIS', "$m-03 07:30:00"); $olay(2, 'CIKIS', "$m-03 16:00:00");                       // 8,5
    $olay(4, 'GIRIS', "$m-03 08:00:00"); $olay(4, 'CIKIS', "$m-03 16:00:00");                       // başka depo
    $olay(5, 'GIRIS', "$m-03 08:00:00"); $olay(5, 'CIKIS', "$m-03 16:00:00");                       // pasif
    $olay(6, 'GIRIS', "$m-03 09:00:00"); $olay(6, 'CIKIS', "$m-03 17:00:00");
}
if ($SEMA === 'hepsi') {
    foreach (pdks_gunluk_tablolar() as $ad => $sql) { [$create, $ix] = pdks_ddl_sqlite($sql); db()->exec($create); foreach ($ix as $i) db()->exec($i); }
    foreach (pdks_gunluk_faz8a_tablolar() as $ad => $sql) { [$create, $ix] = pdks_ddl_sqlite($sql); db()->exec($create); foreach ($ix as $i) db()->exec($i); }
    foreach (pdks_hakedis_tablolar() as $ad => $sql) { [$create, $ix] = pdks_ddl_sqlite($sql); db()->exec($create); foreach ($ix as $i) db()->exec($i); }
    foreach (pdks_cari_tablolar() as $ad => $sql) { [$create, $ix] = pdks_ddl_sqlite($sql); db()->exec($create); foreach ($ix as $i) db()->exec($i); }
    pdks_gunluk_migrate(db());
    // Günlük işçi tohumu: BUGÜNÜN oturumu (iki çavuş; birinde eksik çıkış kartı)
    $kadinId = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
    $erkekId = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
    $c1 = (int)pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Örnek Çavuş 1'], 1, db())['id'];
    $c2 = (int)pdks_gunluk_cavus_olustur(['code' => 'C002', 'name' => 'Örnek Çavuş 2'], 1, db())['id'];
    $s1 = (int)pdks_gunluk_oturum_ac_veya_getir($c1, 1, db())['session']['id'];
    $s2 = (int)pdks_gunluk_oturum_ac_veya_getir($c2, 1, db())['session']['id'];
    $kart = function (string $no, int $tip, string $uid) { pdks_gunluk_kart_olustur(['card_no' => $no, 'worker_type_id' => $tip, 'ham_uid' => $uid, 'kaynak' => 'usb_decimal'], 1, db()); };
    $kart('K001', $kadinId, '631799511'); $kart('K002', $kadinId, '631799512'); $kart('K003', $erkekId, '631799513'); $kart('K004', $erkekId, '631799514');
    pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $s1, 'GIRIS', 1, db()); pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $s1, 'CIKIS', 1, db());
    pdks_gunluk_oturum_kaydet('631799512', 'usb_decimal', $s1, 'GIRIS', 1, db());   // çıkışsız
    pdks_gunluk_oturum_kaydet('631799513', 'usb_decimal', $s2, 'GIRIS', 1, db()); pdks_gunluk_oturum_kaydet('631799513', 'usb_decimal', $s2, 'CIKIS', 1, db());
    pdks_gunluk_oturum_kaydet('631799514', 'usb_decimal', $s2, 'GIRIS', 1, db()); pdks_gunluk_oturum_kaydet('631799514', 'usb_decimal', $s2, 'CIKIS', 1, db());
    pdks_gunluk_faz8a_migrate(db());   // kart olaylarından work-period (katılım) satırlarını üretir
}

if (!empty($OPT['debug'])) { fwrite(STDERR, 'gunluk=' . var_export(pdks_gunluk_sema_hazir(db()), true) . ' faz8a=' . var_export(pdks_gunluk_faz8a_sema_hazir(db()), true) . "\n"); foreach (['daily_worker_work_periods','worker_cards','daily_worker_card_events'] as $t) fwrite(STDERR, $t . '=' . var_export(pdks_gunluk_tablo_var(db(), $t), true) . "\n"); }
$_GET = $GETJ; $_POST = []; $_FILES = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
if (!empty($OPT['post'])) {   // v553 yazma ucu: form alanları $_POST'ta (role_pdks_require_request JSON gövdesi okumaz); yanıt JSON
    $_POST = (array)$OPT['post'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
}
if (!empty($OPT['dump'])) register_shutdown_function(function () {
    try { fwrite(STDERR, 'ISARETLER: ' . json_encode(db()->query('SELECT employee_id, tarih, kod, aciklama, user_id FROM puantaj_isaretler ORDER BY employee_id, tarih')->fetchAll(), JSON_UNESCAPED_UNICODE) . "\n"); }
    catch (Throwable $e) { fwrite(STDERR, "ISARETLER: yok\n"); }
});
$_SERVER['SCRIPT_NAME'] = '/' . $SAYFA;
$_SERVER['REQUEST_URI'] = '/' . $SAYFA;
$src = file_get_contents($ROOT . '/' . $SAYFA);
$src = preg_replace('/^\s*require_once __DIR__ \. \'\/config\/[a-z0-9_]+\.php\';.*$/mi', '', $src);
$src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
$src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
$src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
if (!empty($OPT['css'])) {   // tarayıcı testi: göreli assets/ yolları file:// olur
    $src = str_replace("\$base = base_url();", "\$base = 'file://' . '$ROOT' . '/';", $src);
    $src = str_replace("print_pdks.css", "print_pdks.css", $src);
}
$AUDIT_ON = true;   // yalnız sayfanın kendi audit kayıtları
$tmp = sys_get_temp_dir() . '/pcz_' . getmypid() . '_' . md5($SAYFA) . '.php';
file_put_contents($tmp, "<?php\n" . $src);
try { include $tmp; }
catch (Throwable $e) { @unlink($tmp); fwrite(STDERR, 'ISTISNA: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n"); exit(4); }
@unlink($tmp);
