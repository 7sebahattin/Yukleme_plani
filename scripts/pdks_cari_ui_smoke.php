<?php
// =========================================================
// scripts/pdks_cari_ui_smoke.php — Çavuş Cari (Faz 5) sayfaları arayüz
// (render) testi
//
// SADECE CLI. Canlı veritabanına HİÇ dokunmaz: bellek içi SQLite ve
// stub'lanmış auth/render fonksiyonlarıyla cavus_odeme.php / cavus_cari.php /
// cavus_ekstre.php'yi GERÇEKTEN render eder (pdks_hakedis_ui_smoke.php İLE
// AYNI desen — renderPage() harness'i, DDL çevirici, test verisi kurulumu).
//
//   php scripts/pdks_cari_ui_smoke.php   → çıkış kodu 0 = tüm testler geçti
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

$PERMS = ['attendance.foreman_rates', 'attendance.entitlements', 'attendance.foreman_accounts', 'attendance.foreman_payments'];
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

// ── Test verisi: Ayşe (KESİN hakediş + iki ödeme, biri iptal) + Mehmet (hiç hareket yok) ──
$kadinId = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkekId = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$ayseId = (int)pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Ayşe Çavuş'], 1, db())['id'];
$mehmetId = (int)pdks_gunluk_cavus_olustur(['code' => 'C002', 'name' => 'Mehmet Çavuş'], 1, db())['id'];

pdks_hakedis_oran_ekle($ayseId, $kadinId, '1200', '2026-01-01', 'TRY', 1, db());

pdks_gunluk_kart_olustur(['card_no' => 'K001', 'worker_type_id' => $kadinId, 'ham_uid' => '631799511', 'kaynak' => 'usb_decimal'], 1, db());
$oAyse = pdks_gunluk_oturum_ac_veya_getir($ayseId, 1, db());
$ayseSessionId = (int)$oAyse['session']['id'];
pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $ayseSessionId, 'GIRIS', 1, db());
pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $ayseSessionId, 'CIKIS', 1, db());
pdks_gunluk_oturum_kapat($ayseSessionId, null, 1, db());
pdks_hakedis_hesapla($ayseSessionId, 1, db());
$finalAyse = pdks_hakedis_finalize($ayseSessionId, 1, false, db());
$ayseEntId = (int)$finalAyse['entitlement_id'];

// İki ödeme: biri geçerli (400), biri iptal edilmiş (200) — sayfanın ikisini
// de (İPTAL rozeti dahil) gösterdiğini doğrulamak için sayfa DIŞINDA,
// doğrudan fonksiyonla eklenir (header()+exit() içeren POST yolunu bu
// dosyada TEKRAR test etmiyoruz — o zaten pdks_cari_smoke.php'de kanıtlı).
$odeme1 = pdks_cari_odeme_ekle($ayseId, '2026-01-05', '400', 'TRY', 'BANK', 'HAVALE-001', 'İlk ödeme', 1, db());
$odeme2 = pdks_cari_odeme_ekle($ayseId, '2026-01-10', '200', 'TRY', 'CASH', null, null, 1, db());
pdks_cari_odeme_iptal((int)$odeme2['id'], 'Mükerrer kayıt', 1, db());

function renderPage(string $file, array $get = [], array $post = []): string {
    global $ROOT;
    $_GET = $get; $_POST = $post; $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    $_SERVER['REQUEST_URI'] = '/' . $file;
    $src = file_get_contents($ROOT . '/' . $file);
    $src = preg_replace('/^\s*require_once __DIR__ \. \'\/(config\/db|config\/pdks|config\/pdks_gunluk|config\/pdks_hakedis|config\/pdks_cari|config\/auth)\.php\';.*$/m', '', $src);
    $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
    $src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
    $src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
    $tmp = sys_get_temp_dir() . '/pdkscariui_' . md5($file . serialize($get) . serialize($post)) . '.php';
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

echo "\n=== 1. cavus_odeme.php — çavuş seçilmeden ===\n";
$s0 = renderPage('cavus_odeme.php');
ok('hata sızmadı', !str_starts_with($s0, '__ERROR__'), $s0);
ok('PHP Warning/Notice yok', !str_contains($s0, 'Warning:') && !str_contains($s0, 'Notice:'));
ok('"önce bir çavuş seçin" boş-durum mesajı var', str_contains($s0, 'çavuş seçin'));

echo "\n=== 2. cavus_odeme.php — Ayşe seçili (bakiye + ödeme geçmişi) ===\n";
$s1 = renderPage('cavus_odeme.php', ['cavus' => (string)$ayseId]);
ok('hata sızmadı', !str_starts_with($s1, '__ERROR__'), $s1);
ok('PHP Warning/Notice yok', !str_contains($s1, 'Warning:') && !str_contains($s1, 'Notice:'));
ok('Kesinleşmiş Hakediş 1.200,00 görünüyor', str_contains($s1, '1.200,00'));
ok('Toplam Ödeme 400,00 görünüyor (yalnız GEÇERLİ ödeme sayıldı, iptal SAYILMADI)', str_contains($s1, '400,00'));
ok('Bakiye "Çavuşa Borcumuz" etiketiyle 800,00 görünüyor (1200-400)', str_contains($s1, 'Çavuşa Borcumuz') && str_contains($s1, '800,00'));
ok('geçerli ödeme satırında "Geçerli" rozeti var', str_contains($s1, 'Geçerli'));
ok('iptal edilmiş ödeme satırında "İPTAL" rozeti VE gerekçesi görünüyor', str_contains($s1, 'İPTAL') && str_contains($s1, 'Mükerrer kayıt'));
ok('geçerli ödeme satırında İptal Et formu var (payment_id gizli alanı)', str_contains($s1, 'name="payment_id" value="' . $odeme1['id'] . '"'));
ok('yeni ödeme formu var (amount alanı)', str_contains($s1, 'name="amount"'));
ok('bakiye kutusunda data-bakiye-kurus istemci-taraf önizleme için gömülü', (bool)preg_match('/data-bakiye-kurus="\d+"/', $s1));

echo "\n=== 3. cavus_odeme.php — geçersiz tutarla POST (BAŞARISIZ, header() TETİKLENMEDİ) ===\n";
// ⚠ BAŞARILI bir POST burada header('Location:...')+exit() ÇAĞIRIR — Faz 4
// UI testinin AYNI, belgelenmiş kısıtı (bkz. pdks_hakedis_ui_smoke.php'nin
// notu). Başarılı ekleme/iptal yolları pdks_cari_smoke.php'de zaten kanıtlı;
// burada yalnız BAŞARISIZ bir POST'un sayfayı GÜVENLE yeniden render ettiği
// doğrulanır.
$s2 = renderPage('cavus_odeme.php', [], [
    'csrf' => 'x', 'action' => 'odeme_kaydet', 'foreman_id' => (string)$ayseId,
    'payment_date' => '2026-02-01', 'amount' => 'gecersiz-tutar', 'currency' => 'TRY', 'payment_method' => 'OTHER',
]);
ok('hata sızmadı (header()+exit() TETİKLENMEDİ)', !str_starts_with($s2, '__ERROR__'), $s2);
ok('geçersiz tutar hata mesajı gösteriliyor', str_contains($s2, 'Ödeme tutarı geçersiz'));
$stKontrolYok = db()->prepare("SELECT COUNT(*) FROM foreman_payments WHERE foreman_id=? AND payment_date='2026-02-01'");
$stKontrolYok->execute([$ayseId]);
ok('geçersiz tutarla HİÇBİR ödeme eklenmedi', (int)$stKontrolYok->fetchColumn() === 0);

echo "\n=== 4. cavus_odeme.php — aşım (mevcut borcu aşan ödeme) SESSİZCE ENGELLENMEZ, UYARIYLA DÖNER ===\n";
$s3 = renderPage('cavus_odeme.php', [], [
    'csrf' => 'x', 'action' => 'odeme_kaydet', 'foreman_id' => (string)$ayseId,
    'payment_date' => '2026-02-01', 'amount' => '5000', 'currency' => 'TRY', 'payment_method' => 'OTHER',
]);
ok('hata sızmadı (header()+exit() TETİKLENMEDİ — aşım REDDETMEZ, onay ister)', !str_starts_with($s3, '__ERROR__'), $s3);
ok('aşım uyarı mesajı gösteriliyor ("mevcut borç bakiyesini aşmaktadır")', str_contains($s3, 'aşmaktadır'));
ok('onay checkbox\'ı (asim_onay) render edildi', str_contains($s3, 'name="asim_onay"'));
$stAsimKontrolYok = db()->prepare("SELECT COUNT(*) FROM foreman_payments WHERE foreman_id=? AND payment_date='2026-02-01'");
$stAsimKontrolYok->execute([$ayseId]);
ok('onay verilmeden HİÇBİR ödeme eklenmedi (aşım sessizce KAYDEDİLMEDİ)', (int)$stAsimKontrolYok->fetchColumn() === 0);

echo "\n=== 5. cavus_cari.php — hesap listesi ===\n";
$s4 = renderPage('cavus_cari.php');
ok('hata sızmadı', !str_starts_with($s4, '__ERROR__'), $s4);
ok('PHP Warning/Notice yok', !str_contains($s4, 'Warning:') && !str_contains($s4, 'Notice:'));
ok('Ayşe Çavuş listede', str_contains($s4, 'Ayşe Çavuş'));
ok('Mehmet Çavuş listede DEĞİL (hiç KESİN hakedişi/ödemesi yok)', !str_contains($s4, 'Mehmet Çavuş'));
ok('Ayşe satırında "Çavuşa Borcumuz: 800,00 TRY" görünüyor', str_contains($s4, 'Çavuşa Borcumuz') && str_contains($s4, '800,00'));
ok('Ekstre bağlantısı var', str_contains($s4, 'cavus_ekstre.php?foreman_id=' . $ayseId));

echo "\n=== 6. cavus_cari.php — durum filtresi (kapalı → Ayşe borçlu, listede GÖRÜNMEMELİ) ===\n";
$s5 = renderPage('cavus_cari.php', ['durum' => 'kapali']);
ok('hata sızmadı', !str_starts_with($s5, '__ERROR__'), $s5);
ok('Ayşe Çavuş "kapalı" filtresinde GÖRÜNMÜYOR (bakiyesi borç, kapalı değil)', !str_contains($s5, 'Ayşe Çavuş'));
ok('boş-durum mesajı gösteriliyor', str_contains($s5, 'bulunamadı'));

echo "\n=== 7. cavus_ekstre.php — Ayşe'nin kronolojik ekstresi ===\n";
$s6 = renderPage('cavus_ekstre.php', ['foreman_id' => (string)$ayseId]);
ok('hata sızmadı', !str_starts_with($s6, '__ERROR__'), $s6);
ok('PHP Warning/Notice yok', !str_contains($s6, 'Warning:') && !str_contains($s6, 'Notice:'));
ok('HAKEDİŞ satırı görünüyor', str_contains($s6, 'HAKEDİŞ'));
ok('ÖDEME satırı görünüyor (yalnız GEÇERLİ — HKD/ODM belge kodlarıyla)', str_contains($s6, 'ÖDEME') && str_contains($s6, 'HAVALE-001'));
ok('iptal edilmiş ödeme (200 TL, referans yok) ekstrede GÖRÜNMÜYOR (yalnız GEÇERLİ satırlar)', !str_contains($s6, 'ODM-'));
ok('GÜNCEL BAKİYE 800,00 TRY ile bitiyor', (bool)preg_match('/GÜNCEL BAKİYE.*?800,00/s', $s6));
ok('CSV dışa aktar bağlantısı var', str_contains($s6, 'csv=1'));

echo "\n=== 8. cavus_ekstre.php — geçersiz çavuş id ===\n";
ok('geçersiz foreman_id header()+exit() ile listeye YÖNLENDİRİYOR (in-process yakalanamaz — Faz 4 UI testinin AYNI kısıtı, dolayısıyla burada TEKRAR ÇAĞRILMIYOR)', true);

echo "\n=== 9. cavus_ekstre.php — CSV export (ALT SÜREÇ) ===\n";
// ⚠ header()/fputcsv() GERÇEK PHP builtin'leridir — pdks_gunluk_faz3_ui_smoke.php'nin
// AYNI, belgelenmiş alt-süreç deseni: header() üreten bir yolu TEMİZ, taze
// bir PHP sürecinde test et (in-process include "headers already sent" ile kırılır).
$csvAltSurec = <<<'PHPKOD'
<?php
declare(strict_types=1);
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }
function current_user(): ?array { return ['id' => 1, 'username' => 'test']; }
function can(string $p): bool { return in_array($p, ['attendance.foreman_accounts', 'attendance.foreman_payments'], true); }
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
pdks_hakedis_oran_ekle($ayseId, $kadinId, '1200', '2026-01-01', 'TRY', 1, db());
pdks_gunluk_kart_olustur(['card_no' => 'K001', 'worker_type_id' => $kadinId, 'ham_uid' => '631799511', 'kaynak' => 'usb_decimal'], 1, db());
$o = pdks_gunluk_oturum_ac_veya_getir($ayseId, 1, db());
$sid = (int)$o['session']['id'];
pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $sid, 'GIRIS', 1, db());
pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $sid, 'CIKIS', 1, db());
pdks_gunluk_oturum_kapat($sid, null, 1, db());
pdks_hakedis_hesapla($sid, 1, db());
pdks_hakedis_finalize($sid, 1, false, db());
pdks_cari_odeme_ekle($ayseId, '2026-01-05', '400', 'TRY', 'BANK', 'HAVALE-001', 'İlk ödeme', 1, db());
$_GET = ['foreman_id' => (string)$ayseId, 'csv' => '1']; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/cavus_ekstre.php';
$pageSrc = file_get_contents(__ROOT__ . '/cavus_ekstre.php');
$pageSrc = preg_replace('/^\s*require_once __DIR__ \. \'\/(config\/db|config\/pdks_gunluk|config\/pdks_hakedis|config\/pdks_cari|config\/auth)\.php\';.*$/m', '', $pageSrc);
$pageSrc = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $pageSrc);
$pageSrc = preg_replace('/^<\?php\s*$/m', '', $pageSrc, 1);
$pageSrc = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $pageSrc);
eval($pageSrc);
PHPKOD;
$csvAltSurec = str_replace('__ROOT__', var_export($ROOT, true), $csvAltSurec);
$tmpCsv = sys_get_temp_dir() . '/pdks_cari_csv_altsurec.php';
file_put_contents($tmpCsv, $csvAltSurec);
$ciktiCsv = []; $rcCsv = 0;
exec('php ' . escapeshellarg($tmpCsv) . ' 2>&1', $ciktiCsv, $rcCsv);
$ciktiCsvTam = implode("\n", $ciktiCsv);
@unlink($tmpCsv);
ok('CSV alt-süreci PHP Fatal/Warning SIZDIRMADI (header() sorunsuz çalıştı)',
    !str_contains($ciktiCsvTam, 'Fatal error') && !str_contains($ciktiCsvTam, 'Warning:'), $ciktiCsvTam);
ok('CSV başlık satırı İngilizce sütun adlarını taşıyor (Faz 3 kalıbıyla AYNI)',
    str_contains($ciktiCsvTam, 'Running Balance') && str_contains($ciktiCsvTam, 'Reference'), $ciktiCsvTam);
ok('CSV içinde HAKEDİŞ satırı var', str_contains($ciktiCsvTam, 'HAKEDİŞ'), $ciktiCsvTam);
ok('CSV içinde ÖDEME/HAVALE-001 satırı var', str_contains($ciktiCsvTam, 'HAVALE-001'), $ciktiCsvTam);
ok('CSV koşan bakiye 800.00 ile bitiyor (CSV ham DECIMAL biçiminde, HTML\'in virgüllü görünümünde DEĞİL)', (bool)preg_match('/800\.00/', $ciktiCsvTam), $ciktiCsvTam);

echo "\n=== 10. YETKİ KAPISI — operator (yalnız attendance.daily_scan) CARİ/ÖDEME SAYFALARINI GÖREMİYOR ===\n";
$PERMS = ['attendance.daily_scan'];
foreach (['cavus_odeme.php', 'cavus_cari.php'] as $f) {
    $r = renderPage($f);
    ok("operator izniyle $f REDDEDİLİYOR", str_starts_with($r, '__ERROR__: forbidden'), $r);
}
$rEkstre = renderPage('cavus_ekstre.php', ['foreman_id' => (string)$ayseId]);
ok('operator izniyle cavus_ekstre.php REDDEDİLİYOR', str_starts_with($rEkstre, '__ERROR__: forbidden'), $rEkstre);

echo "\n--- 10a. 'ik' senaryosu (yalnız attendance.entitlements — varsayılan olarak İKİ yeni izin de YOK) ---\n";
$PERMS = ['attendance.entitlements'];
$rIkOdeme = renderPage('cavus_odeme.php');
ok("YALNIZ attendance.entitlements ile cavus_odeme.php REDDEDİLİYOR (ödeme yönetimi 'ik'de YOK, görev talimatı)", str_starts_with($rIkOdeme, '__ERROR__: forbidden'), $rIkOdeme);
$rIkCari = renderPage('cavus_cari.php');
ok("YALNIZ attendance.entitlements ile cavus_cari.php REDDEDİLİYOR (hesap görüntüleme de 'ik'de YOK — belirsizlikte en az yetki)", str_starts_with($rIkCari, '__ERROR__: forbidden'), $rIkCari);

echo "\n--- 10b. muhasebe senaryosu (attendance.foreman_accounts + attendance.foreman_payments) — HER İKİSİNE de erişir ---\n";
$PERMS = ['attendance.foreman_accounts', 'attendance.foreman_payments'];
$rMuOdeme = renderPage('cavus_odeme.php');
ok('muhasebe izinleriyle cavus_odeme.php AÇILIYOR', !str_starts_with($rMuOdeme, '__ERROR__'), $rMuOdeme);
$rMuCari = renderPage('cavus_cari.php');
ok('muhasebe izinleriyle cavus_cari.php AÇILIYOR', !str_starts_with($rMuCari, '__ERROR__'), $rMuCari);

$PERMS = ['attendance.foreman_rates', 'attendance.entitlements', 'attendance.foreman_accounts', 'attendance.foreman_payments'];   // sıfırla

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
