<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_faz11_mesai_fixed_ui_smoke.php — Fix 11 (Personel Takibi
// denetimi) odaklı doğrulama.
//
// SADECE CLI. Ağ yok, canlı DB'ye dokunmaz — bellek içi SQLite ile
// mesai_degerlendirme.php'yi GERÇEKTEN render eder (pdks_takip_ui_smoke.php
// İLE AYNI desen, ama KENDİ İZOLE fikstürüyle — mevcut büyük fikstürü
// bozmadan).
//
// Konu: FM oranı overtime_mode='fixed' iken, config/pdks_faz8b.php'nin
// hakediş hesabı $fmToplamKurus = $fmBirimKurus (SAAT SAYISI ÇARPANI YOK) —
// eski ekran hem sabit hem saatlik modda AYNI sayısal "Onaylanan FM Saati"
// girdisini gösteriyordu, muhasebeci tutarın saat sayısına göre değiştiğini
// zannedebiliyordu. Bu test, sabit moddaki bir dönemde artık Onayla/Reddet
// ikili seçimi göründüğünü, saatlik moddaki bir dönemde ESKİ sayısal
// girdinin AYNEN korunduğunu doğrular.
//
//   php scripts/pdks_faz11_mesai_fixed_ui_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

$ROOT = dirname(__DIR__);

$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

$IS_ADMIN = true;
$AKTIF_DEPO = 'Depo A';
function current_user(): ?array { return ['id' => 1, 'username' => 'test', 'display_name' => 'Test Kullanıcı']; }
function can(string $p): bool { return true; }
function is_admin(): bool { global $IS_ADMIN; return $IS_ADMIN; }
function active_depot(): ?string { global $AKTIF_DEPO; return $AKTIF_DEPO; }
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

require_once $ROOT . '/config/pdks.php';
require_once $ROOT . '/config/pdks_gunluk.php';
require_once $ROOT . '/config/pdks_hakedis.php';
require_once $ROOT . '/config/pdks_faz8b.php';

function pdks_faz11_ddl_sqlite(string $mysql): array
{
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m)) {
        throw new RuntimeException('DDL ayrıştırılamadı: ' . substr($mysql, 0, 60));
    }
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

db()->exec("CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `username` VARCHAR(60) NOT NULL, `display_name` VARCHAR(150) NULL, `is_active` INTEGER NOT NULL DEFAULT 1)");
db()->exec("INSERT INTO users (id, username, display_name) VALUES (1, 'test', 'Test Kullanıcı')");
foreach (pdks_gunluk_tablolar() as $ad => $sql) { [$create, $ix] = pdks_faz11_ddl_sqlite($sql); db()->exec($create); foreach ($ix as $i) db()->exec($i); }
foreach (pdks_gunluk_faz8a_tablolar() as $ad => $sql) { [$create, $ix] = pdks_faz11_ddl_sqlite($sql); db()->exec($create); foreach ($ix as $i) db()->exec($i); }
foreach (pdks_hakedis_tablolar() as $ad => $sql) { [$create, $ix] = pdks_faz11_ddl_sqlite($sql); db()->exec($create); foreach ($ix as $i) db()->exec($i); }
pdks_gunluk_migrate(db());
pdks_faz8b_migrate(db());

$kadinId = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkekId = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();

function pdks_faz11_donem_kur(int $foremanId, int $workerTypeId, string $uid, string $cardNo): array
{
    $o = pdks_gunluk_oturum_ac_veya_getir($foremanId, 1, db());
    $sid = (int)$o['session']['id'];
    $workDate = (string)$o['session']['work_date'];
    $kart = pdks_gunluk_kart_olustur(['card_no' => $cardNo, 'worker_type_id' => $workerTypeId, 'ham_uid' => $uid, 'kaynak' => 'usb_decimal'], 1, db());
    if (!$kart['ok']) throw new RuntimeException('kart oluşturulamadı: ' . json_encode($kart, JSON_UNESCAPED_UNICODE));
    $g = pdks_gunluk_faz8a_giris_kaydet($uid, 'usb_decimal', $sid, $workerTypeId, 'tam', 1, db());
    if (!$g['ok']) throw new RuntimeException('giriş kaydedilemedi: ' . json_encode($g, JSON_UNESCAPED_UNICODE));
    $c = pdks_gunluk_faz8a_cikis_kaydet($uid, 'usb_decimal', $sid, 1, db());
    if (!$c['ok']) throw new RuntimeException('çıkış kaydedilemedi: ' . json_encode($c, JSON_UNESCAPED_UNICODE));
    // Normal 9 saati (540 dk) aşacak şekilde SABİTLENİR — FM adayı deterministik >0 olsun diye.
    db()->exec("UPDATE daily_worker_work_periods SET entry_time='{$workDate} 08:00:00', exit_time='{$workDate} 19:00:00' WHERE session_id={$sid}");
    return ['session_id' => $sid];
}

$fail = 0; $gecen = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail, $gecen;
    $c ? $gecen++ : $fail++;
    printf("%-90s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}

function renderPage(string $file, array $get = [], array $post = []): string {
    global $ROOT;
    $_GET = $get; $_POST = $post; $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    $_SERVER['REQUEST_URI'] = '/' . $file;
    $src = file_get_contents($ROOT . '/' . $file);
    $src = preg_replace('/^\s*require_once __DIR__ \. \'\/(config\/db|config\/pdks|config\/pdks_gunluk|config\/pdks_hakedis|config\/pdks_faz8b|config\/auth)\.php\';.*$/m', '', $src);
    $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
    $src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
    $src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
    $tmp = sys_get_temp_dir() . '/pdksfaz11_' . md5($file . serialize($get) . serialize($post)) . '.php';
    file_put_contents($tmp, "<?php\n" . $src);
    ob_start();
    try { include $tmp; } catch (Throwable $e) { ob_end_clean(); return '__ERROR__: ' . $e->getMessage(); }
    return ob_get_clean();
}

echo "\n=== A. FM 'Sabit Toplam' modu — sayısal girdi YERİNE Onayla/Reddet ikili seçimi ===\n";
$fix11Id = (int)pdks_gunluk_cavus_olustur(['code' => 'C011', 'name' => 'Fix11 Çavuş'], 1, db())['id'];
$don1 = pdks_faz11_donem_kur($fix11Id, $kadinId, '631799530', 'K011');
pdks_faz8b_oran_ekle($fix11Id, $kadinId, '1200', '700', 'fixed', '300', '2020-01-01', 'TRY', 1, db());

$sFix11 = renderPage('mesai_degerlendirme.php', ['session_id' => (string)$don1['session_id']]);
ok('hata sızmadı', !str_starts_with($sFix11, '__ERROR__'), $sFix11);
ok('PHP Warning/Notice yok', !str_contains($sFix11, 'Warning:') && !str_contains($sFix11, 'Notice:'), $sFix11);
ok('FM Sabit Tutar uyarı metni görünüyor ("saat sayısı tutarı DEĞİŞTİRMEZ")', str_contains($sFix11, 'saat sayısı tutarı DEĞİŞTİRMEZ'), $sFix11);
ok('Onayla/Reddet radio çifti render edildi (overtime_approved_hours adında en az İKİ radio)',
    substr_count($sFix11, 'type="radio" name="overtime_approved_hours"') >= 2, $sFix11);
ok('eski sayısal "Onaylanan FM Saati" girdisi BU DÖNEM İÇİN YOK (sabit modda kafa karıştırıcıydı)',
    !str_contains($sFix11, 'type="number" name="overtime_approved_hours"'), $sFix11);

echo "\n=== B. FM 'Saatlik' modu — ESKİ sayısal girdi AYNEN KORUNUYOR (regresyon değil) ===\n";
$fix11bId = (int)pdks_gunluk_cavus_olustur(['code' => 'C012', 'name' => 'Fix11b Çavuş'], 1, db())['id'];
$don2 = pdks_faz11_donem_kur($fix11bId, $erkekId, '631799531', 'K012');
pdks_faz8b_oran_ekle($fix11bId, $erkekId, '1200', '700', 'hourly', '150', '2020-01-01', 'TRY', 1, db());

$sFix11b = renderPage('mesai_degerlendirme.php', ['session_id' => (string)$don2['session_id']]);
ok('hata sızmadı', !str_starts_with($sFix11b, '__ERROR__'), $sFix11b);
ok('PHP Warning/Notice yok', !str_contains($sFix11b, 'Warning:') && !str_contains($sFix11b, 'Notice:'), $sFix11b);
ok('Saatlik modda eski sayısal "Onaylanan FM Saati" girdisi HÂLÂ VAR', str_contains($sFix11b, 'type="number" name="overtime_approved_hours"'), $sFix11b);
ok('Saatlik modda Onayla/Reddet radio ikilisi YOK (yalnız sabit modun UI\'sı)',
    !str_contains($sFix11b, 'type="radio" name="overtime_approved_hours"'), $sFix11b);

echo "\n=== C. Kaydetme — sabit moddaki radio da AYNI backend alanını (overtime_approved_hours) besliyor ===\n";
$periodIdC = (int)db()->query("SELECT id FROM daily_worker_work_periods WHERE session_id={$don1['session_id']}")->fetchColumn();
$fmAdayC = (int)pdks_faz8b_donem_finans_durumu(db()->query("SELECT * FROM daily_worker_work_periods WHERE id={$periodIdC}")->fetch())['fazla_mesai_saat'];
ok('FM adayı >0 (fikstür 11 saatlik çalışmayla FM üretti)', $fmAdayC > 0, (string)$fmAdayC);
$sonuc = pdks_faz8b_degerlendirme_kaydet($periodIdC, null, $fmAdayC, 1, db());
ok('sabit moddaki dönem için overtime_approved_hours=' . $fmAdayC . ' (Onayla radio\'sunun taşıdığı computed saat) kabul edildi', $sonuc['ok'] === true, json_encode($sonuc, JSON_UNESCAPED_UNICODE));

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
