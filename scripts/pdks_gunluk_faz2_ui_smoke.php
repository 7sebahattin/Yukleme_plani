<?php
// =========================================================
// scripts/pdks_gunluk_faz2_ui_smoke.php — Günlük İşçi Faz 2 sayfası arayüz testi
//
// SADECE CLI. Canlı veritabanına HİÇ dokunmaz: bellek içi SQLite ve
// stub'lanmış auth/render fonksiyonlarıyla gunluk_isci_giris_cikis.php'yi
// GERÇEKTEN render eder (pdks_gunluk_ui_smoke.php ile aynı desen — GERÇEK
// DDL çevirici, kısaltılmış şema YOK).
//
// ⚠ ajax=oturum/kaydet/kapat uçları POST + exit() içerir — GET render
// testi bunları TETİKLEMEZ (sayfa yalnız GET akışında render edilir).
// Fonksiyon seviyesindeki tüm iş kuralları zaten scripts/pdks_gunluk_faz2_smoke.php'de
// KAPSAMLI test ediliyor; burada YALNIZ sayfanın GERÇEKTEN doğru render
// ettiği ve yetki kapısının GERÇEKTEN çalıştığı doğrulanır.
//
//   php scripts/pdks_gunluk_faz2_ui_smoke.php   → çıkış kodu 0 = tüm testler geçti
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
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

$PERMS = ['attendance.daily_scan'];
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
function fmt_datetime(?string $d): string { return (string)$d; }
function render_header(string $t, bool $p = false): void { echo "<!doctype html><html><head><title>" . h($t) . "</title></head><body><main class=\"container\">"; }
function render_footer(bool $p = false): void { echo "</main></body></html>"; }

require_once $ROOT . '/config/pdks.php';           // GERÇEK — çapraz kontrol + PdksNfcOku için gerekli
require_once $ROOT . '/config/pdks_gunluk.php';    // GERÇEK — test edilen budur

// ── MySQL DDL → SQLite çevirici — scripts/pdks_gunluk_smoke.php/faz2_smoke.php İLE BİREBİR AYNI ──
function pdks_ddl_sqlite(string $mysql): array
{
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m)) {
        throw new RuntimeException('DDL ayrıştırılamadı: ' . substr($mysql, 0, 60));
    }
    $tablo = $m[1]; $govde = $m[2];
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
            $indeksler[] = "CREATE UNIQUE INDEX `{$mm[1]}` ON `{$tablo}` (" . pdks_kolon_listesi($mm[2]) . ")"; continue;
        }
        if (preg_match('/^(?:INDEX|KEY) `([^`]+)` \((.+)\)$/i', $p, $mm)) {
            $indeksler[] = "CREATE INDEX `{$mm[1]}` ON `{$tablo}` (" . pdks_kolon_listesi($mm[2]) . ")"; continue;
        }
        if (preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY/i', $p)) continue;
        $p = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
        $p = preg_replace('/\s*ON UPDATE CURRENT_TIMESTAMP\b/i', '', $p);
        $kolonlar[] = $p;
    }
    return ["CREATE TABLE `{$tablo}` (\n  " . implode(",\n  ", $kolonlar) . "\n)", $indeksler];
}
function pdks_kolon_listesi(string $ham): string
{
    $ham = preg_replace('/`\s*\(\s*\d+\s*\)/', '`', $ham);
    return preg_replace('/\s+/', ' ', trim($ham));
}

db()->exec("CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `username` VARCHAR(60) NOT NULL, `is_active` INTEGER NOT NULL DEFAULT 1)");
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
pdks_gunluk_migrate(db());

// ── Test verisi ──
$kadinId = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Ayşe Çavuş'], 1, db());
pdks_gunluk_cavus_olustur(['code' => 'C002', 'name' => 'Mehmet Çavuş'], 1, db());
$pasifId = pdks_gunluk_cavus_olustur(['code' => 'C003', 'name' => 'Pasif Çavuş'], 1, db())['id'];
pdks_gunluk_cavus_aktiflik((int)$pasifId, false, 1, db());
pdks_gunluk_kart_olustur(['card_no' => 'K001', 'worker_type_id' => $kadinId, 'ham_uid' => '631799511', 'kaynak' => 'usb_decimal'], 1, db());

function renderPage(string $file, array $get = []): string {
    global $ROOT;
    $_GET = $get; $_POST = []; $_FILES = []; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/' . $file;
    $src = file_get_contents($ROOT . '/' . $file);
    $src = preg_replace('/^\s*require_once __DIR__ \. \'\/(config\/db|config\/pdks|config\/pdks_gunluk|config\/auth)\.php\';.*$/m', '', $src);
    $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
    $src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
    $src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $tmp = sys_get_temp_dir() . '/pdksgunlukfaz2ui_' . md5($file . serialize($get)) . '.php';
    file_put_contents($tmp, "<?php\n" . $src);
    ob_start();
    try { include $tmp; } catch (Throwable $e) { ob_end_clean(); return '__ERROR__: ' . $e->getMessage(); }
    return ob_get_clean();
}

$fail = 0; $gecen = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail, $gecen;
    $c ? $gecen++ : $fail++;
    printf("%-82s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}

echo "\n=== 1. gunluk_isci_giris_cikis.php — GET render ===\n";
$s = renderPage('gunluk_isci_giris_cikis.php');
ok('hata sızmadı', !str_starts_with($s, '__ERROR__'), $s);
ok('PHP Warning/Notice yok', !str_contains($s, 'Warning:') && !str_contains($s, 'Notice:'));
ok('başlık "GÜNLÜK İŞÇİ" içeriyor (büyük başlık)', (bool)preg_match('/Günlük İşçi/iu', $s));
ok('Ayşe Çavuş (aktif) listede', str_contains($s, 'Ayşe Çavuş'));
ok('Mehmet Çavuş (aktif) listede', str_contains($s, 'Mehmet Çavuş'));
ok('Pasif Çavuş LİSTEDE DEĞİL (yalnız aktif çavuşlar gösterilir)', !str_contains($s, 'Pasif Çavuş'));
ok('"ÇAVUŞ SEÇ" adımı var', (bool)preg_match('/ÇAVUŞ SEÇ/u', $s));
ok('GİRİŞ MODU butonu var', str_contains($s, 'GİRİŞ MODU'));
ok('ÇIKIŞ MODU butonu var', str_contains($s, 'ÇIKIŞ MODU'));
ok('"SEÇİLİ ÇAVUŞ" banner\'ı var (JS ile doldurulur)', (bool)preg_match('/SEÇİLİ ÇAVUŞ/u', $s));
ok('MESAİYİ KAPAT butonu var', (bool)preg_match('/MESAİYİ KAPAT/u', $s));
ok('Çavuşu Değiştir butonu var', str_contains($s, 'Çavuşu Değiştir'));
ok('canlı sayaç panosu (giSayaclar) DOM\'da var', str_contains($s, 'id="giSayaclar"'));
ok('İçeride sayacı DOM\'da var', str_contains($s, 'id="giIcerdeToplam"'));
ok('Eksik Çıkış sayacı DOM\'da var', str_contains($s, 'id="giEksikToplam"'));
ok('mutabakat/kapatma ekranı (giReconSec) DOM\'da var', str_contains($s, 'id="giReconSec"'));
ok('"Eksik Çıkışlarla Kapat" butonu var', str_contains($s, 'Eksik Çıkışlarla Kapat'));
ok('kapatma gerekçesi metin kutusu var (zorunlu)', (bool)preg_match('/id="giReconNot"[^>]*required/', $s));

echo "\n=== 2. NFC/USB — RENDER EDİLEN ÇIKTIDA DA REUSE doğrulanıyor ===\n";
$sKod = preg_replace('/^\s*\/\/.*$/m', '', $s);
ok('paylaşılan okuma yolu (PdksNfcOku) render edilen sayfada VAR', str_contains($s, 'window.PdksNfcOku'));
ok('helper TEK KOPYA basılıyor', substr_count($s, 'window.PdksNfcOku = ') === 1);
// ⚠ Render edilen çıktı, pdks_nfc_oku_js()'in BASTIĞI paylaşılan helper'ın
// GERÇEK `new NDEFReader()` satırını da İÇERİR (tek meşru kopya) — sayfanın
// KENDİ scriptinde İKİNCİ bir kopya OLMAMASI test edilir, TOPLAM SIFIR değil.
ok('render edilen sayfada TEK bir new NDEFReader() var (paylaşılan helper\'ın kendisi — sayfa İKİNCİ bir kopya AÇMIYOR)',
    substr_count($sKod, 'new NDEFReader()') === 1);
ok('render edilen GERÇEK KODDA AbortController YOK', !str_contains($sKod, 'new AbortController()'));
ok('USB hedef kutusu DOM\'da var (id=giScanInput)', str_contains($s, 'id="giScanInput"'));

echo "\n=== 3. YETKİ KAPISI — GERÇEKTEN çalışıyor ===\n";
$PERMS = [];
$red = renderPage('gunluk_isci_giris_cikis.php');
ok('attendance.daily_scan YOKKEN sayfa REDDEDİLİYOR', str_starts_with($red, '__ERROR__: forbidden'), $red);
$PERMS = ['attendance.foremen', 'attendance.worker_cards'];   // yalnız Faz 1 izinleri, daily_scan YOK
$red2 = renderPage('gunluk_isci_giris_cikis.php');
ok('yalnız Faz 1 izinleriyle (daily_scan OLMADAN) REDDEDİLİYOR', str_starts_with($red2, '__ERROR__: forbidden'), $red2);
$PERMS = ['attendance.daily_scan'];   // sıfırla

echo "\n=== 4. ŞEMA HAZIR DEĞİLKEN GÜVENLİ BAŞARISIZLIK (Faz 1 kapısı REUSE) ===\n";
// ⚠ pdks_gunluk_sayfa_kapisi() exit() çağırır — render testinde DEĞİL, ayrı
// bir alt-süreçte doğrulanır (pdks_gunluk_smoke.php §15 İLE AYNI gerekçe).
$altSurecKodu = "<?php\n"
    . "declare(strict_types=1);\n"
    . "\$PDO_TEST = new PDO('sqlite::memory:');\n"
    . "function db(): PDO { global \$PDO_TEST; return \$PDO_TEST; }\n"
    . "function set_flash(\$a, \$b): void { echo \"FLASH[\$a]: \$b\\n\"; }\n"
    . "function render_header(\$t, \$p = false): void { echo \"HEADER\\n\"; }\n"
    . "function render_flash(): void {}\n"
    . "function render_footer(\$p = false): void { echo \"FOOTER\\n\"; }\n"
    . "function h(\$v): string { return (string)\$v; }\n"
    . "require_once " . var_export($ROOT . '/config/pdks_gunluk.php', true) . ";\n"
    . "pdks_gunluk_sayfa_kapisi(db());\n"
    . "echo \"BURAYA HİÇ ULAŞILMAMALI\\n\";\n";
$tmpAltSurec = sys_get_temp_dir() . '/pdks_gunluk_faz2_kapisi_altsurec.php';
file_put_contents($tmpAltSurec, $altSurecKodu);
$cikti = []; $rc = 0;
exec('php ' . escapeshellarg($tmpAltSurec) . ' 2>&1', $cikti, $rc);
$ciktiTam = implode("\n", $cikti);
@unlink($tmpAltSurec);
ok('Faz 2 tabloları da EKSİKKEN (yalnız worker_types/foremen/worker_cards değil, daily_* da) güvenli mesaj basılıyor',
    str_contains($ciktiTam, 'henüz oluşturulmamış'), $ciktiTam);
ok('PHP Fatal/Warning SIZDIRMADI', !str_contains($ciktiTam, 'Fatal error') && !str_contains($ciktiTam, 'Warning:'), $ciktiTam);
ok('"BURAYA HİÇ ULAŞILMAMALI" çıktıda YOK (exit() çalıştı, DDL denenmedi)', !str_contains($ciktiTam, 'BURAYA HİÇ ULAŞILMAMALI'), $ciktiTam);
ok('alt-süreç çıkış kodu 0', $rc === 0, "rc=$rc\n$ciktiTam");

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
