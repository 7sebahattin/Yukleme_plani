<?php
// =========================================================
// scripts/pdks_sifirlama_smoke.php — pdks_sifirla.php + config/pdks_sifirlama.php
//
// SADECE CLI. Canlı veritabanına DOKUNMAZ.
//   php scripts/pdks_sifirlama_smoke.php
//       → bellek içi SQLite, FK kısıtları AÇIK (silme sırası gerçekten sınanır)
//   PDKS_TEST_DSN='mysql:host=localhost;dbname=pdks_test' PDKS_TEST_USER=t PDKS_TEST_PASS=t \
//   php scripts/pdks_sifirlama_smoke.php
//       → gerçek MySQL/MariaDB (BOŞ bir test veritabanı verin — tablolar silinip kurulur)
// Çıkış kodu 0 = tüm testler geçti.
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') { http_response_code(403); die('Yalnız CLI.'); }

$ROOT  = dirname(__DIR__);
$DSN   = getenv('PDKS_TEST_DSN') ?: 'sqlite::memory:';
$MYSQL = str_starts_with($DSN, 'mysql:');

$PDO_TEST = new PDO($DSN, getenv('PDKS_TEST_USER') ?: null, getenv('PDKS_TEST_PASS') ?: null);
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

$IS_ADMIN = true;
$AUDIT = [];
$PERMS = [];
function current_user(): ?array { return ['id' => 1, 'username' => 'test', 'display_name' => 'Test']; }
function can(string $p): bool { global $IS_ADMIN; return $IS_ADMIN; }
function is_admin(): bool { global $IS_ADMIN; return $IS_ADMIN; }
function active_depot(): ?string { return 'Depo A'; }
function audit_log_event(string $a, string $m, ?int $r = null, ?array $o = null, ?array $n = null): void { global $AUDIT; $AUDIT[] = [$a, $m, $o, $n]; }
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return 'testcsrf'; }
function csrf_check($t): void { if ($t !== 'testcsrf') throw new RuntimeException('csrf'); }
function set_flash($a, $b): void { global $FLASH; $FLASH = [$a, $b]; }
function render_flash(): void {}
function forbidden($m = ''): void { throw new RuntimeException('forbidden: ' . $m); }
function base_url(): string { return '/'; }
function require_login(): array { return current_user(); }
function render_header(string $t, bool $p = false): void { echo "<!doctype html><html><body><main>"; }
function render_footer(bool $p = false): void { echo "</main></body></html>"; }

require_once $ROOT . '/config/pdks.php';
require_once $ROOT . '/config/pdks_gunluk.php';
require_once $ROOT . '/config/pdks_hakedis.php';
require_once $ROOT . '/config/pdks_cari.php';
require_once $ROOT . '/config/pdks_faz9d.php';
require_once $ROOT . '/config/pdks_faz8b.php';
require_once $ROOT . '/config/pdks_faz8j.php';
require_once $ROOT . '/config/pdks_sifirlama.php';

// ── Şema ────────────────────────────────────────────────────
$tumTablolar = array_merge(pdks_tablolar(), pdks_gunluk_tablolar(), pdks_gunluk_faz8a_tablolar(), pdks_hakedis_tablolar(), pdks_cari_tablolar(), pdks_faz9d_tablolar());
if ($MYSQL) {
    db()->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) db()->exec("DROP TABLE `{$t}`");
    db()->exec('SET FOREIGN_KEY_CHECKS=1');
    db()->exec("CREATE TABLE `audit_log` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY, `user_id` INT NULL, `action` VARCHAR(40) NOT NULL, `module` VARCHAR(40) NOT NULL, `record_id` INT NULL, `old_values` LONGTEXT NULL, `new_values` LONGTEXT NULL, `ip` VARCHAR(45) NULL, `user_agent` VARCHAR(255) NULL, `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB");
    // Gerçek migrasyon zinciri — canlıdaki kolonlar/FK'ler (Faz 8A/8B/8J eklentileri dahil).
    pdks_migrate(db());
    pdks_gunluk_migrate(db());
    pdks_gunluk_faz8a_migrate(db());
    pdks_hakedis_migrate(db());
    pdks_faz8b_migrate(db());
    pdks_cari_migrate(db());
    pdks_faz9d_migrate(db());
    pdks_faz8j_migrate(db());
    foreach (array_keys($tumTablolar) as $t) {
        if ($t === 'attendance_events' || $t === 'attendance_gates') continue;
        db()->query("SELECT 1 FROM `{$t}` LIMIT 0"); // yoksa burada patlar
    }
} else {
    db()->exec('PRAGMA foreign_keys = ON');
    db()->exec("CREATE TABLE `audit_log` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INTEGER, `action` TEXT, `module` TEXT, `record_id` INTEGER, `old_values` TEXT, `new_values` TEXT, `ip` TEXT, `user_agent` TEXT, `created_at` TEXT DEFAULT CURRENT_TIMESTAMP)");
    // MySQL DDL → SQLite; FK kısıtları KORUNUR (diğer smoke'lardan farkı budur).
    foreach ($tumTablolar as $ad => $sql) {
        if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $sql, $m)) continue;
        [$parca, $buf, $d] = [[], '', 0];
        for ($i = 0, $n = strlen($m[2]); $i < $n; $i++) {
            $c = $m[2][$i];
            if ($c === '(') $d++;
            if ($c === ')') $d--;
            if ($c === ',' && $d === 0) { $parca[] = trim($buf); $buf = ''; continue; }
            $buf .= $c;
        }
        $parca[] = trim($buf);
        $kol = [];
        foreach ($parca as $p) {
            $p = preg_replace('/\s+/', ' ', $p);
            if (preg_match('/^(UNIQUE KEY|INDEX|KEY) /i', $p)) continue;
            $p = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
            $p = preg_replace('/\s*ON UPDATE CURRENT_TIMESTAMP\b/i', '', $p);
            $kol[] = $p;
        }
        db()->exec("CREATE TABLE `{$m[1]}` (" . implode(', ', $kol) . ')');
    }
}

// ── Test verisi: 2 çavuş + çavuşa bağlı her tabloda satır ────
function ekle(string $sql, array $p = []): int { $st = db()->prepare($sql); $st->execute($p); return (int)db()->lastInsertId(); }
if (!(int)db()->query('SELECT COUNT(*) FROM worker_types')->fetchColumn()) {
    ekle("INSERT INTO worker_types (code, name) VALUES ('KADIN','Kadın'),('ERKEK','Erkek')");
}
$tip = (int)db()->query("SELECT MIN(id) FROM worker_types")->fetchColumn();
$kart1 = ekle("INSERT INTO worker_cards (card_no, worker_type_id, canonical_uid) VALUES ('K001', ?, 'AA11')", [$tip]);
$kart2 = ekle("INSERT INTO worker_cards (card_no, canonical_uid) VALUES ('K002', 'BB22')");
ekle("INSERT INTO employees (full_name) VALUES ('Kalıcı Personel')");
db()->exec("INSERT INTO audit_log (action, module) VALUES ('create','foremen')");

foreach ([['C001', 'Test Çavuş A'], ['C002', 'Test Çavuş B']] as [$kod, $ad]) {
    $f = ekle('INSERT INTO foremen (code, name) VALUES (?, ?)', [$kod, $ad]);
    ekle('INSERT INTO foreman_worker_rates (foreman_id, worker_type_id, daily_rate, valid_from) VALUES (?, ?, 1200, ?)', [$f, $tip, '2026-01-01']);
    foreach (['2026-09-01', '2026-09-02'] as $gun) {
        $s = ekle("INSERT INTO daily_work_sessions (foreman_id, work_date, opened_at) VALUES (?, ?, ?)", [$f, $gun, "$gun 08:00:00"]);
        foreach ([$kart1, $kart2] as $k) {
            $g = ekle("INSERT INTO daily_worker_card_events (session_id, worker_card_id, event_type, source, canonical_uid_snapshot, work_date_snapshot, server_event_time) VALUES (?, ?, 'GIRIS', 'scan', 'X', ?, ?)", [$s, $k, $gun, "$gun 08:00:00"]);
            $c = ekle("INSERT INTO daily_worker_card_events (session_id, worker_card_id, event_type, source, canonical_uid_snapshot, work_date_snapshot, server_event_time) VALUES (?, ?, 'CIKIS', 'scan', 'X', ?, ?)", [$s, $k, $gun, "$gun 17:00:00"]);
            ekle("INSERT INTO daily_worker_work_periods (session_id, worker_card_id, entry_event_id, exit_event_id, entry_time, exit_time, work_date_snapshot, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'closed')", [$s, $k, $g, $c, "$gun 08:00:00", "$gun 17:00:00", $gun]);
        }
        $e = ekle("INSERT INTO foreman_daily_entitlements (session_id, foreman_id, work_date, status, total_amount, calculated_at) VALUES (?, ?, ?, 'final', 2400, ?)", [$s, $f, $gun, "$gun 18:00:00"]);
        ekle('INSERT INTO foreman_daily_entitlement_lines (entitlement_id, worker_type_id, worker_count, unit_rate, line_total) VALUES (?, ?, 2, 1200, 2400)', [$e, $tip]);
        // Düzeltme + onun geri alınışı (kendine FK — silme sırasının en zor kısmı)
        $a = ekle("INSERT INTO foreman_entitlement_adjustments (entitlement_id, foreman_id, work_date, signed_amount, reason) VALUES (?, ?, ?, 100, 'test')", [$e, $f, $gun]);
        ekle("INSERT INTO foreman_entitlement_adjustments (entitlement_id, foreman_id, work_date, signed_amount, reason, reversal_of_adjustment_id) VALUES (?, ?, ?, -100, 'geri', ?)", [$e, $f, $gun, $a]);
    }
    ekle("INSERT INTO foreman_payments (foreman_id, payment_date, amount) VALUES (?, '2026-09-05', 1000)", [$f]);
}

// ── Yardımcılar ─────────────────────────────────────────────
function renderPage(array $post = []): string
{
    global $ROOT;
    $_GET = []; $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    $src = file_get_contents($ROOT . '/pdks_sifirla.php');
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace("__DIR__ . '/config/pdks_sifirlama.php'", var_export($ROOT . '/config/pdks_sifirlama.php', true), $src);
    $src = preg_replace("/header\('Location: pdks_sifirla\.php'\);\s*exit;/", "echo '__REDIRECT__'; return;", $src, 1, $say);
    if ($say !== 1) return '__ERROR__: yönlendirme bloğu bulunamadı';
    $src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
    $src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
    $tmp = sys_get_temp_dir() . '/pdks_sifirla_' . md5(serialize($post) . microtime()) . '.php';
    file_put_contents($tmp, "<?php\n" . $src);
    ob_start();
    try { include $tmp; } catch (Throwable $e) { ob_end_clean(); @unlink($tmp); return '__ERROR__: ' . $e->getMessage(); }
    @unlink($tmp);
    return (string)ob_get_clean();
}
function adet(string $t): int { return (int)db()->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn(); }
function yedek_tablolari(): array
{
    global $MYSQL;
    $sql = $MYSQL
        ? "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'pdks\\_yedek\\_%'"
        : "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'pdks_yedek_%'";
    return db()->query($sql)->fetchAll(PDO::FETCH_COLUMN);
}
function iz_al(string $html): string { preg_match('/name="iz" value="([0-9a-f]{64})"/', $html, $m); return $m[1] ?? ''; }

$fail = 0; $gecen = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void
{
    global $fail, $gecen;
    $c ? $gecen++ : $fail++;
    printf("%-78s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}

$beklenen = [
    'foremen' => 2, 'foreman_worker_rates' => 2, 'daily_work_sessions' => 4,
    'daily_worker_card_events' => 16, 'daily_worker_work_periods' => 8,
    'foreman_daily_entitlements' => 4, 'foreman_daily_entitlement_lines' => 4,
    'foreman_entitlement_adjustments' => 8, 'foreman_payments' => 2,
];
$hepsiDolu = function () use ($beklenen): bool { foreach ($beklenen as $t => $n) if (adet($t) !== $n) return false; return true; };

echo "\n=== Sürücü: " . ($MYSQL ? 'MySQL/MariaDB' : 'SQLite (FK açık)') . " ===\n";
ok('test verisi beklenen satır sayılarıyla kuruldu', $hepsiDolu());

echo "\n=== 1. Yetki ===\n";
$IS_ADMIN = false;
ok('admin olmayan 403 alır', str_contains(renderPage(), 'forbidden'));
ok('admin olmayan POST ile de silemez', str_contains(renderPage(['csrf' => 'testcsrf', 'onay' => '1', 'onay_yazi' => 'SIFIRLA']), 'forbidden') && $hepsiDolu());
$IS_ADMIN = true;

echo "\n=== 2. Önizleme (GET hiçbir şeyi değiştirmez) ===\n";
$g = renderPage();
ok('hata yok', !str_starts_with($g, '__ERROR__') && !str_contains($g, 'Warning:'), substr($g, 0, 300));
ok('iki çavuş listeleniyor', str_contains($g, 'Test Çavuş A') && str_contains($g, 'Test Çavuş B'));
ok('toplam satır (50) onay metninde', str_contains($g, '<b>50</b> satırın'));
ok('form + parmak izi var', iz_al($g) !== '');
ok('GET sonrası veri aynen duruyor, yedek yok', $hepsiDolu() && yedek_tablolari() === []);
$iz = iz_al($g);

echo "\n=== 3. Eksik onay → hiçbir şey olmaz ===\n";
$r = renderPage(['csrf' => 'testcsrf', 'iz' => $iz, 'onay_yazi' => 'SIFIRLA']);
ok('kutu işaretsiz → hata mesajı', str_contains($r, 'SIFIRLA yazmalısınız'));
$r = renderPage(['csrf' => 'testcsrf', 'iz' => $iz, 'onay' => '1', 'onay_yazi' => 'evet']);
ok('yanlış onay yazısı → hata mesajı', str_contains($r, 'SIFIRLA yazmalısınız'));
ok('CSRF yanlışsa işlem yok', str_contains(renderPage(['csrf' => 'x', 'iz' => $iz, 'onay' => '1', 'onay_yazi' => 'SIFIRLA']), 'csrf'));
ok('veri aynen duruyor, yedek yok', $hepsiDolu() && yedek_tablolari() === []);

echo "\n=== 4. Önizlemeden sonra veri değişti → hiçbir şey olmaz ===\n";
$pid = ekle("INSERT INTO foreman_payments (foreman_id, payment_date, amount) VALUES ((SELECT MIN(id) FROM foremen), '2026-09-06', 5)");
$r = renderPage(['csrf' => 'testcsrf', 'iz' => $iz, 'onay' => '1', 'onay_yazi' => 'SIFIRLA']);
ok('eski parmak izi reddedildi', str_contains($r, 'veriler değişti'));
ok('hiçbir şey silinmedi, yedek açılmadı', adet('foreman_payments') === 3 && yedek_tablolari() === []);
db()->exec("DELETE FROM foreman_payments WHERE id = {$pid}");
// Silip geri ekleme: sayı aynı ama MAX(id) farklı → yine yakalanmalı
$iz2 = iz_al(renderPage());
$eski = (int)db()->query('SELECT MAX(id) FROM foreman_payments')->fetchColumn();
db()->exec("DELETE FROM foreman_payments WHERE id = {$eski}");
ekle("INSERT INTO foreman_payments (foreman_id, payment_date, amount) VALUES ((SELECT MAX(id) FROM foremen), '2026-09-05', 1000)");
$r = renderPage(['csrf' => 'testcsrf', 'iz' => $iz2, 'onay' => '1', 'onay_yazi' => 'SIFIRLA']);
ok('sayı aynı, satır farklı → yine reddedildi', str_contains($r, 'veriler değişti'));

echo "\n=== 5. Yedekle silinen sayı tutmazsa ROLLBACK ===\n";
$sahteYedek = [];
foreach (pdks_sifir_sayim(db()) as $t => $s) $sahteYedek[$t] = ['yedek' => '-', 'adet' => $s['adet']];
$sahteYedek['foremen']['adet'] = 1; // yedekte 1 çavuş varmış gibi
try { pdks_sifir_sil(db(), $sahteYedek); $patladi = false; } catch (RuntimeException $e) { $patladi = str_contains($e->getMessage(), 'geri alındı'); }
ok('uyuşmazlık hatası verildi', $patladi);
ok('transaction geri alındı — tüm veriler yerinde', $hepsiDolu() && !db()->inTransaction());

echo "\n=== 6. Gerçek sıfırlama ===\n";
$AUDIT = []; $FLASH = null;
$iz = iz_al(renderPage());
$r = renderPage(['csrf' => 'testcsrf', 'iz' => $iz, 'onay' => '1', 'onay_yazi' => ' sıfırla ']);
ok('başarılı → yönlendirme', str_contains($r, '__REDIRECT__'), substr($r, 0, 400));
foreach ($beklenen as $t => $n) ok("  {$t} boş", adet($t) === 0);
ok('Kart Havuzu (worker_cards) korundu', adet('worker_cards') === 2);
ok('işçi tipleri korundu', adet('worker_types') >= 2);
ok('kalıcı personel (employees) korundu', adet('employees') === 1);
ok('audit_log korundu', adet('audit_log') >= 1);
$yedekler = yedek_tablolari();
ok('9 yedek tablo oluştu', count($yedekler) === 9, implode(', ', $yedekler));
$onek = preg_replace('/foremen$/', '', (string)current(array_filter($yedekler, fn($y) => str_ends_with($y, '_foremen'))));
foreach ($beklenen as $t => $n) ok("  yedek {$t} = {$n} satır", adet($onek . $t) === $n);
ok('audit: pdks_reset kaydı, silinen toplam 50', ($AUDIT[0][0] ?? '') === 'pdks_reset' && array_sum($AUDIT[0][3]['silinen'] ?? []) === 50);
ok('flash mesajı yedek önekini söylüyor', str_contains((string)($FLASH[1] ?? ''), $onek));

echo "\n=== 7. İkinci çalıştırma ===\n";
$g = renderPage();
ok('"temiz" mesajı, form yok', str_contains($g, 'Personel Takibi temiz') && !str_contains($g, 'name="onay_yazi"'));
$r = renderPage(['csrf' => 'testcsrf', 'iz' => $iz, 'onay' => '1', 'onay_yazi' => 'SIFIRLA']);
ok('tekrar POST → "silinecek veri yok", yeni yedek açılmadı', str_contains($r, 'Silinecek veri yok') && count(yedek_tablolari()) === 9);

echo "\n=== 8. Geri yükleme (yedekten, ters sıra) ===\n";
try {
    db()->beginTransaction();
    foreach (array_reverse(array_keys(pdks_sifir_tablolar())) as $t) {
        db()->exec("INSERT INTO `{$t}` SELECT * FROM `{$onek}{$t}` ORDER BY id");
    }
    db()->commit();
    $geri = true;
} catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $geri = $e->getMessage(); }
ok('yedekten geri yükleme FK hatası vermeden çalıştı', $geri === true, (string)$geri);
ok('tüm satırlar geri geldi', $hepsiDolu());
ok('geri alınmış düzeltmenin bağı (reversal_of) geri geldi', (int)db()->query('SELECT COUNT(*) FROM foreman_entitlement_adjustments WHERE reversal_of_adjustment_id IS NOT NULL')->fetchColumn() === 4);

echo "\n{$gecen} geçti, {$fail} hata\n";
exit($fail === 0 ? 0 : 1);
