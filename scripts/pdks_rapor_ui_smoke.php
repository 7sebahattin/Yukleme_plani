<?php
// =========================================================
// scripts/pdks_rapor_ui_smoke.php — Yönetim Raporlama Merkezi (Faz 6)
// sayfası arayüz (render) testi
//
// SADECE CLI. Canlı veritabanına HİÇ dokunmaz: bellek içi SQLite ve
// stub'lanmış auth/render fonksiyonlarıyla raporlar.php'yi GERÇEKTEN
// render eder (pdks_cari_ui_smoke.php İLE AYNI desen — renderPage()
// harness'i, DDL çevirici, test verisi kurulumu).
//
//   php scripts/pdks_rapor_ui_smoke.php   → çıkış kodu 0 = tüm testler geçti
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

$PERMS = ['attendance.management_reports', 'attendance.foreman_accounts', 'attendance.foreman_payments'];
$IS_ADMIN = false;
$AKTIF_DEPO = 'Depo A';
function current_user(): ?array { return ['id' => 1, 'username' => 'test', 'display_name' => 'Test Kullanıcı']; }
function can(string $p): bool { global $PERMS; return in_array($p, $PERMS, true); }
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
require_once $ROOT . '/config/pdks_cari.php';
require_once $ROOT . '/config/pdks_rapor.php';

// ─────────────────────────────────────────────────────────
// MySQL DDL → SQLite çevirici — diğer *_ui_smoke.php dosyalarıyla BİREBİR AYNI.
// ─────────────────────────────────────────────────────────
function pdks_ddl_sqlite(string $mysql): array
{
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m)) {
        throw new RuntimeException('DDL ayrıştırılamadı: ' . substr($mysql, 0, 60));
    }
    $tablo = $m[1];
    $govde = $m[2];
    $parcalar = []; $buf = ''; $derinlik = 0;
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
        if (preg_match('/^UNIQUE KEY `([^`]+)` \((.+)\)$/i', $p, $mm)) {
            $indeksler[] = "CREATE UNIQUE INDEX `{$mm[1]}` ON `{$tablo}` (" . pdks_kolon_listesi($mm[2]) . ")";
            continue;
        }
        if (preg_match('/^(?:INDEX|KEY) `([^`]+)` \((.+)\)$/i', $p, $mm)) {
            $indeksler[] = "CREATE INDEX `{$mm[1]}` ON `{$tablo}` (" . pdks_kolon_listesi($mm[2]) . ")";
            continue;
        }
        if (preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY/i', $p)) continue;
        $p = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
        $p = preg_replace('/\s*ON UPDATE CURRENT_TIMESTAMP\b/i', '', $p);
        $kolonlar[] = $p;
    }
    $create = "CREATE TABLE `{$tablo}` (\n  " . implode(",\n  ", $kolonlar) . "\n)";
    return [$create, $indeksler];
}
function pdks_kolon_listesi(string $ham): string
{
    $ham = preg_replace('/`\s*\(\s*\d+\s*\)/', '`', $ham);
    return preg_replace('/\s+/', ' ', trim($ham));
}

db()->exec("CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `username` VARCHAR(60) NOT NULL, `display_name` VARCHAR(150) NULL, `is_active` INTEGER NOT NULL DEFAULT 1)");
db()->exec("INSERT INTO users (id, username, display_name) VALUES (1, 'test', 'Test Kullanıcı')");
foreach (pdks_tablolar() as $ad => $sql) {
    if (!in_array($ad, ['employees', 'employee_cards', 'employee_card_uids'], true)) continue;
    [$create, $indeksler] = pdks_ddl_sqlite($sql);
    db()->exec($create);
    foreach ($indeksler as $ix) db()->exec($ix);
}
foreach (pdks_gunluk_tablolar() as $ad => $sql) {
    [$create, $indeksler] = pdks_ddl_sqlite($sql);
    db()->exec($create);
    foreach ($indeksler as $ix) db()->exec($ix);
}
foreach (pdks_hakedis_tablolar() as $ad => $sql) {
    [$create, $indeksler] = pdks_ddl_sqlite($sql);
    db()->exec($create);
    foreach ($indeksler as $ix) db()->exec($ix);
}
foreach (pdks_cari_tablolar() as $ad => $sql) {
    [$create, $indeksler] = pdks_ddl_sqlite($sql);
    db()->exec($create);
    foreach ($indeksler as $ix) db()->exec($ix);
}
pdks_gunluk_migrate(db());

// ── Test verisi: bugünün TARİHİNE göre — sayfa varsayılan filtresi "Bugün". ──
$bugun = date('Y-m-d');
$kadinId = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$ayseId = (int)pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Ayşe Çavuş'], 1, db())['id'];
pdks_gunluk_kart_olustur(['card_no' => 'K001', 'worker_type_id' => $kadinId, 'ham_uid' => '631799511', 'kaynak' => 'usb_decimal'], 1, db());
$o = pdks_gunluk_oturum_ac_veya_getir($ayseId, 1, db());
$sid = (int)$o['session']['id'];
pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $sid, 'GIRIS', 1, db());
pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $sid, 'CIKIS', 1, db());
pdks_gunluk_oturum_kapat($sid, null, 1, db());
pdks_hakedis_oran_ekle($ayseId, $kadinId, '1200', '2020-01-01', 'TRY', 1, db());
$finalAyse = pdks_hakedis_finalize($sid, 1, false, db());
pdks_cari_odeme_ekle($ayseId, $bugun, '500', 'TRY', 'BANK', 'HAVALE-001', null, 1, db());

function renderPage(string $file, array $get = [], array $post = []): string {
    global $ROOT;
    $_GET = $get; $_POST = $post; $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    $_SERVER['REQUEST_URI'] = '/' . $file;
    $src = file_get_contents($ROOT . '/' . $file);
    $src = preg_replace('/^\s*require_once __DIR__ \. \'\/(config\/db|config\/pdks|config\/pdks_gunluk|config\/pdks_hakedis|config\/pdks_cari|config\/pdks_rapor|config\/auth)\.php\';.*$/m', '', $src);
    $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
    $src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
    $src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
    $tmp = sys_get_temp_dir() . '/pdksraporui_' . md5($file . serialize($get) . serialize($post)) . '.php';
    file_put_contents($tmp, "<?php\n" . $src);
    ob_start();
    try { include $tmp; } catch (Throwable $e) { ob_end_clean(); return '__ERROR__: ' . $e->getMessage(); }
    return ob_get_clean();
}

$fail = 0; $gecen = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail, $gecen;
    $c ? $gecen++ : $fail++;
    printf("%-84s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}

echo "\n=== 1. raporlar.php — varsayılan (Bugün), admin/muhasebe izinleri ===\n";
$s0 = renderPage('raporlar.php');
ok('hata sızmadı', !str_starts_with($s0, '__ERROR__'), $s0);
ok('PHP Warning/Notice yok', !str_contains($s0, 'Warning:') && !str_contains($s0, 'Notice:'));
ok('Toplam Çalışan kartı var', str_contains($s0, 'Toplam Çalışan'));
ok('Ayşe Çavuş katılımı 1 olarak görünüyor', (bool)preg_match('/Toplam Çalışan.*?<div class="val">1<\/div>/s', $s0), $s0);
ok('finansal bölüm (Kesinleşmiş Hakediş) görünüyor — attendance.foreman_accounts VAR', str_contains($s0, 'Kesinleşmiş Hakediş'));
ok('Güncel bakiye 700,00 TRY görünüyor (1200 hakediş - 500 ödeme)', str_contains($s0, '700,00'));
ok('Çavuş Cari Durumu bölümü görünüyor (finansal yetki var)', str_contains($s0, 'Çavuş Cari Durumu'));
ok('Dönem Net Hareket ile Güncel bakiye AYRI etiketlerle gösteriliyor (karıştırılmadı)', str_contains($s0, 'Dönem Net Hareket') && str_contains($s0, 'FARKLI kavramlardır'));

echo "\n=== 2. raporlar.php — 'ik' senaryosu (yalnız attendance.management_reports) ===\n";
$PERMS = ['attendance.management_reports'];
$s1 = renderPage('raporlar.php');
ok('hata sızmadı (sayfa AÇILIYOR — yalnız finansal bölümler gizli)', !str_starts_with($s1, '__ERROR__'), $s1);
ok('PHP Warning/Notice yok', !str_contains($s1, 'Warning:') && !str_contains($s1, 'Notice:'));
ok('operasyonel KPI (Toplam Çalışan) HÂLÂ görünüyor', str_contains($s1, 'Toplam Çalışan'));
ok('"Kesinleşmiş Hakediş" GÖRÜNMÜYOR (attendance.foreman_accounts YOK)', !str_contains($s1, 'Kesinleşmiş Hakediş'));
ok('"700,00" (bakiye rakamı) SIZMADI', !str_contains($s1, '700,00'));
ok('"Çavuş Cari Durumu" bölüm başlığı GÖRÜNMÜYOR', !str_contains($s1, 'Çavuş Cari Durumu'));
ok('"finansal veriler için gerekli yetkiniz yok" notu gösteriliyor', str_contains($s1, 'Finansal veriler için gerekli yetkiniz yok'));
$PERMS = ['attendance.management_reports', 'attendance.foreman_accounts', 'attendance.foreman_payments'];   // sıfırla

echo "\n=== 3. YETKİ KAPISI — operator (yalnız attendance.daily_scan) SAYFAYI HİÇ AÇAMIYOR ===\n";
$PERMS = ['attendance.daily_scan'];
$rOperator = renderPage('raporlar.php');
ok('operator izniyle raporlar.php REDDEDİLİYOR', str_starts_with($rOperator, '__ERROR__: forbidden'), $rOperator);
$PERMS = ['attendance.management_reports', 'attendance.foreman_accounts', 'attendance.foreman_payments'];   // sıfırla

echo "\n=== 4. raporlar.php — özel tarih aralığı filtresi ===\n";
$s2 = renderPage('raporlar.php', ['donem' => 'ozel', 'baslangic' => $bugun, 'bitis' => $bugun]);
ok('hata sızmadı', !str_starts_with($s2, '__ERROR__'), $s2);
ok('Özel Tarih Aralığı seçili', (bool)preg_match('/value="ozel"\s+selected/', $s2), $s2);

echo "\n=== 5. raporlar.php — veri OLMAYAN bir tarih (boş durum, hata GİBİ GÖRÜNMÜYOR) ===\n";
$s3 = renderPage('raporlar.php', ['donem' => 'ozel', 'baslangic' => '2019-01-01', 'bitis' => '2019-01-01']);
ok('hata sızmadı', !str_starts_with($s3, '__ERROR__'), $s3);
ok('PHP Warning/Notice yok (boş gün ÇÖKMEDİ)', !str_contains($s3, 'Warning:') && !str_contains($s3, 'Notice:'));
ok('Toplam Çalışan 0 gösteriliyor', (bool)preg_match('/Toplam Çalışan.*?<div class="val">0<\/div>/s', $s3), $s3);
ok('boş-durum mesajları görünüyor (İşçi Tipi Dağılımı / Çavuş Bazlı Özet)', str_contains($s3, 'bulunamadı') || str_contains($s3, 'yok'));

echo "\n=== 6. CSV DIŞA AKTARIM (görev madde 31, ALT SÜREÇ) — filtreler UI ile TUTARLI ===\n";
// ⚠ header()/fputcsv() GERÇEK PHP builtin'leridir — diğer *_ui_smoke.php
// dosyalarıyla AYNI, belgelenen alt-süreç deseni (in-process include
// "headers already sent" ile kırılır).
$csvAltSurec = <<<'PHPKOD'
<?php
declare(strict_types=1);
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }
function current_user(): ?array { return ['id' => 1, 'username' => 'test']; }
function can(string $p): bool { return in_array($p, ['attendance.management_reports', 'attendance.foreman_accounts'], true); }
function is_admin(): bool { return false; }
function active_depot(): ?string { return 'Depo A'; }
function audit_log_event(...$a): void {}
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function set_flash($a, $b): void {}
function render_flash(): void {}
function forbidden($m = ''): void { http_response_code(403); exit('forbidden'); }
function base_url(): string { return '/'; }
function require_login(): array { return current_user(); }
function enforce_active_depot(): void {}
function render_header($t, $p = false): void { echo "HEADER\n"; }
function render_footer($p = false): void { echo "FOOTER\n"; }
require_once __ROOT__ . '/config/pdks.php';
require_once __ROOT__ . '/config/pdks_gunluk.php';
require_once __ROOT__ . '/config/pdks_hakedis.php';
require_once __ROOT__ . '/config/pdks_cari.php';
require_once __ROOT__ . '/config/pdks_rapor.php';
function pdks_ddl_sqlite_alt(string $mysql): array {
    preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m);
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
foreach (pdks_tablolar() as $ad => $sql) {
    if (!in_array($ad, ['employees', 'employee_cards', 'employee_card_uids'], true)) continue;
    [$create, $ix] = pdks_ddl_sqlite_alt($sql); db()->exec($create); foreach ($ix as $i) db()->exec($i);
}
foreach (pdks_gunluk_tablolar() as $ad => $sql) { [$create, $ix] = pdks_ddl_sqlite_alt($sql); db()->exec($create); foreach ($ix as $i) db()->exec($i); }
foreach (pdks_hakedis_tablolar() as $ad => $sql) { [$create, $ix] = pdks_ddl_sqlite_alt($sql); db()->exec($create); foreach ($ix as $i) db()->exec($i); }
foreach (pdks_cari_tablolar() as $ad => $sql) { [$create, $ix] = pdks_ddl_sqlite_alt($sql); db()->exec($create); foreach ($ix as $i) db()->exec($i); }
pdks_gunluk_migrate(db());
$kadinId = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$ayseId = (int)pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Ayşe Çavuş'], 1, db())['id'];
pdks_hakedis_oran_ekle($ayseId, $kadinId, '1200', '2020-01-01', 'TRY', 1, db());
pdks_gunluk_kart_olustur(['card_no' => 'K001', 'worker_type_id' => $kadinId, 'ham_uid' => '631799511', 'kaynak' => 'usb_decimal'], 1, db());
$o = pdks_gunluk_oturum_ac_veya_getir($ayseId, 1, db());
$sid = (int)$o['session']['id'];
pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $sid, 'GIRIS', 1, db());
pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $sid, 'CIKIS', 1, db());
pdks_gunluk_oturum_kapat($sid, null, 1, db());
pdks_hakedis_finalize($sid, 1, false, db());
pdks_cari_odeme_ekle($ayseId, date('Y-m-d'), '500', 'TRY', 'BANK', 'HAVALE-001', null, 1, db());
$bugun = date('Y-m-d');
$_GET = ['donem' => 'ozel', 'baslangic' => $bugun, 'bitis' => $bugun, 'cavus' => (string)$ayseId, 'csv' => 'gunluk'];
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/raporlar.php';
$pageSrc = file_get_contents(__ROOT__ . '/raporlar.php');
$pageSrc = preg_replace('/^\s*require_once __DIR__ \. \'\/(config\/db|config\/pdks_gunluk|config\/pdks_hakedis|config\/pdks_cari|config\/pdks_rapor|config\/auth)\.php\';.*$/m', '', $pageSrc);
$pageSrc = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $pageSrc);
$pageSrc = preg_replace('/^<\?php\s*$/m', '', $pageSrc, 1);
$pageSrc = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $pageSrc);
eval($pageSrc);
PHPKOD;
$csvAltSurec = str_replace('__ROOT__', var_export($ROOT, true), $csvAltSurec);
$tmpCsv = sys_get_temp_dir() . '/pdks_rapor_csv_gunluk_altsurec.php';
file_put_contents($tmpCsv, $csvAltSurec);
$ciktiCsv = []; $rcCsv = 0;
exec('php ' . escapeshellarg($tmpCsv) . ' 2>&1', $ciktiCsv, $rcCsv);
$ciktiCsvTam = implode("\n", $ciktiCsv);
@unlink($tmpCsv);
ok('Günlük CSV alt-süreci PHP Fatal/Warning SIZDIRMADI (header() sorunsuz çalıştı)',
    !str_contains($ciktiCsvTam, 'Fatal error') && !str_contains($ciktiCsvTam, 'Warning:'), $ciktiCsvTam);
ok('CSV başlık satırı İngilizce sütun adlarını taşıyor + finansal sütunlar dahil (foreman_accounts izniyle)',
    str_contains($ciktiCsvTam, 'Worker Count') && str_contains($ciktiCsvTam, 'Entitlement'), $ciktiCsvTam);
ok('CSV\'de UI ile AYNI filtreyle (bugün, Ayşe) 1200.00 hakediş satırı var', str_contains($ciktiCsvTam, '1200.00'), $ciktiCsvTam);
ok('CSV\'de 500.00 ödeme satırı var', str_contains($ciktiCsvTam, '500.00'), $ciktiCsvTam);

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
