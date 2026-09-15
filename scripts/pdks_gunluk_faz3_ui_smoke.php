<?php
// =========================================================
// scripts/pdks_gunluk_faz3_ui_smoke.php — Günlük Puantaj sayfaları arayüz
// (render) testi
//
// SADECE CLI. Canlı veritabanına HİÇ dokunmaz: bellek içi SQLite ve
// stub'lanmış auth/render fonksiyonlarıyla gunluk_isci_puantaj.php /
// gunluk_isci_puantaj_detay.php'yi GERÇEKTEN render eder
// (pdks_gunluk_ui_smoke.php İLE AYNI desen).
//
//   php scripts/pdks_gunluk_faz3_ui_smoke.php   → çıkış kodu 0 = tüm testler geçti
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

$PERMS = ['attendance.daily_reports'];
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

// ─────────────────────────────────────────────────────────
// MySQL DDL → SQLite çevirici — pdks_gunluk_ui_smoke.php İLE BİREBİR AYNI.
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
pdks_gunluk_migrate(db());

// ── Test verisi: bugün Depo A'da Ayşe (açık, eksik kartlı) + Mehmet (tamamlanmış) ──
$kadinId = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkekId = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$ayseId  = (int)pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Ayşe Çavuş'], 1, db())['id'];
$mehmetId = (int)pdks_gunluk_cavus_olustur(['code' => 'C002', 'name' => 'Mehmet Çavuş'], 1, db())['id'];
$k1 = pdks_gunluk_kart_olustur(['card_no' => 'K001', 'worker_type_id' => $kadinId, 'ham_uid' => '631799511', 'kaynak' => 'usb_decimal'], 1, db());
$k2 = pdks_gunluk_kart_olustur(['card_no' => 'K002', 'worker_type_id' => $kadinId, 'ham_uid' => '111222333', 'kaynak' => 'usb_decimal'], 1, db());
$e1 = pdks_gunluk_kart_olustur(['card_no' => 'E001', 'worker_type_id' => $erkekId, 'ham_uid' => '444555666', 'kaynak' => 'usb_decimal'], 1, db());

$oAyse = pdks_gunluk_oturum_ac_veya_getir($ayseId, 1, db());
$ayseSessionId = (int)$oAyse['session']['id'];
pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $ayseSessionId, 'GIRIS', 1, db());
pdks_gunluk_oturum_kaydet('111222333', 'usb_decimal', $ayseSessionId, 'GIRIS', 1, db());   // K002 içeride kalır
pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $ayseSessionId, 'CIKIS', 1, db());

$oMehmet = pdks_gunluk_oturum_ac_veya_getir($mehmetId, 1, db());
$mehmetSessionId = (int)$oMehmet['session']['id'];
pdks_gunluk_oturum_kaydet('444555666', 'usb_decimal', $mehmetSessionId, 'GIRIS', 1, db());
pdks_gunluk_oturum_kaydet('444555666', 'usb_decimal', $mehmetSessionId, 'CIKIS', 1, db());
pdks_gunluk_oturum_kapat($mehmetSessionId, null, 1, db());

function renderPage(string $file, array $get = []): string {
    global $ROOT;
    $_GET = $get; $_POST = []; $_FILES = []; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/' . $file;
    $src = file_get_contents($ROOT . '/' . $file);
    $src = preg_replace('/^\s*require_once __DIR__ \. \'\/(config\/db|config\/pdks|config\/pdks_gunluk|config\/auth)\.php\';.*$/m', '', $src);
    $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
    $src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
    $src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
    $tmp = sys_get_temp_dir() . '/pdksgunlukfaz3ui_' . md5($file . serialize($get)) . '.php';
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

echo "\n=== 1. gunluk_isci_puantaj.php — varsayılan (bugün, filtresiz) ===\n";
$s = renderPage('gunluk_isci_puantaj.php');
ok('hata sızmadı', !str_starts_with($s, '__ERROR__'), $s);
ok('PHP Warning/Notice yok', !str_contains($s, 'Warning:') && !str_contains($s, 'Notice:'));
ok('Ayşe Çavuş listede', str_contains($s, 'Ayşe Çavuş'));
ok('Mehmet Çavuş listede', str_contains($s, 'Mehmet Çavuş'));
ok('tepe özet kartı "Toplam İşçi" var', str_contains($s, 'Toplam İşçi'));
ok('tepe özet kartı "Aktif Çavuş" var', str_contains($s, 'Aktif Çavuş'));
ok('Ayşe satırı "Açık Mesai" rozetiyle render ediliyor', (bool)preg_match('/Ayşe Çavuş.*?Açık Mesai/s', $s) || str_contains($s, 'pdks-badge-acik'));
ok('Mehmet satırı "Tamamlandı" rozetiyle render ediliyor', str_contains($s, 'Tamamlandı'));
ok('Detay bağlantısı var (gunluk_isci_puantaj_detay.php)', str_contains($s, 'gunluk_isci_puantaj_detay.php?id='));
ok('CSV dışa aktar bağlantısı var', str_contains($s, 'csv=1'));
ok('Eksik Çıkışlar bölümü var', str_contains($s, 'Eksik Çıkışlar'));
ok('K002 eksik çıkış listesinde görünüyor', str_contains($s, 'K002'));
ok('depo alanı SEÇİLEBİLİR değil (disabled — zorunlu tek depo mimarisi)', (bool)preg_match('/name="depo"[^>]*disabled/', $s));
ok('mobil kart görünümü DOM\'da var (pdks-cards mobile-only)', str_contains($s, 'pdks-cards mobile-only'));
ok('masaüstü tablo görünümü DOM\'da var (table-wrap pc-only)', str_contains($s, 'table-wrap pc-only'));

// ⚠ Çavuş FİLTRE dropdown'ı HER ZAMAN tüm çavuşları seçenek olarak listeler
// (filtreden bağımsız) — bu yüzden isim str_contains() testi yanıltıcıdır.
// Bunun yerine SONUÇ SATIRININ kendine özgü işareti (detay bağlantısındaki
// session id) aranır.
$ayseDetayLink   = 'gunluk_isci_puantaj_detay.php?id=' . $ayseSessionId;
$mehmetDetayLink = 'gunluk_isci_puantaj_detay.php?id=' . $mehmetSessionId;

echo "\n=== 2. gunluk_isci_puantaj.php — çavuş filtresi ===\n";
$sF = renderPage('gunluk_isci_puantaj.php', ['cavus' => (string)$ayseId]);
ok('hata sızmadı', !str_starts_with($sF, '__ERROR__'), $sF);
ok('yalnız Ayşe\'nin oturum satırı listede (Mehmet\'in DEĞİL — dropdown seçeneği SAYILMAZ)',
    str_contains($sF, $ayseDetayLink) && !str_contains($sF, $mehmetDetayLink), $sF);

echo "\n=== 3. gunluk_isci_puantaj.php — durum filtresi (kapalı) ===\n";
$sK = renderPage('gunluk_isci_puantaj.php', ['durum' => 'kapali']);
ok('hata sızmadı', !str_starts_with($sK, '__ERROR__'), $sK);
ok('yalnız Mehmet\'in oturum satırı listede (Ayşe açık, filtre dışı)',
    str_contains($sK, $mehmetDetayLink) && !str_contains($sK, $ayseDetayLink), $sK);

echo "\n=== 4. gunluk_isci_puantaj.php — CSV export (ALT SÜREÇ) ===\n";
// ⚠ header()/fputcsv() GERÇEK PHP builtin'leridir — bu test dosyasının
// KENDİ echo çıktıları CLI'da render'dan ÖNCE zaten stdout'a akmış olur,
// bu yüzden in-process include() header()'ı "headers already sent" ile
// KIRAR. AYNI sebep/çözüm: pdks_gunluk_smoke.php'nin exit() alt-süreç
// deseni — header()+çıktı üreten bir yolu TEMİZ, taze bir PHP sürecinde test et.
$csvAltSurec = <<<'PHPKOD'
<?php
declare(strict_types=1);
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }
function current_user(): ?array { return ['id' => 1, 'username' => 'test']; }
function can(string $p): bool { return $p === 'attendance.daily_reports'; }
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
foreach (pdks_gunluk_tablolar() as $ad => $sql) {
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $sql, $m)) continue;
    $govde = $m[2]; $parcalar = []; $buf = ''; $derinlik = 0;
    for ($i = 0, $n = strlen($govde); $i < $n; $i++) {
        $c = $govde[$i];
        if ($c === '(') $derinlik++;
        if ($c === ')') $derinlik--;
        if ($c === ',' && $derinlik === 0) { $parcalar[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $parcalar[] = trim($buf);
    $kolonlar = [];
    foreach ($parcalar as $p) {
        $p = preg_replace('/\s+/', ' ', $p);
        if (preg_match('/^(?:UNIQUE KEY|INDEX|KEY) `/i', $p)) continue;
        if (preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY/i', $p)) continue;
        $p = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
        $p = preg_replace('/\s*ON UPDATE CURRENT_TIMESTAMP\b/i', '', $p);
        $kolonlar[] = $p;
    }
    db()->exec("CREATE TABLE `{$m[1]}` (\n  " . implode(",\n  ", $kolonlar) . "\n)");
}
db()->exec("CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `username` VARCHAR(60) NOT NULL, `display_name` VARCHAR(150) NULL, `is_active` INTEGER NOT NULL DEFAULT 1)");
pdks_gunluk_migrate(db());
$kadinId = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$ayseId = (int)pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Ayşe Çavuş'], 1, db())['id'];
pdks_gunluk_kart_olustur(['card_no' => 'K001', 'worker_type_id' => $kadinId, 'ham_uid' => '631799511', 'kaynak' => 'usb_decimal'], 1, db());
$o = pdks_gunluk_oturum_ac_veya_getir($ayseId, 1, db());
pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', (int)$o['session']['id'], 'GIRIS', 1, db());
$_GET = ['csv' => '1']; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/gunluk_isci_puantaj.php';
// db()/can()/vb. ZATEN bu betikte tanımlı — gerçek sayfanın KENDİ
// require_once('config/db|pdks|pdks_gunluk|auth.php') satırları AYNI
// fonksiyonları YENİDEN tanımlamaya çalışırdı ("Cannot redeclare"). Bu
// yüzden diğer tüm render harness'lerinin (renderPage()) AYNI kaçınma
// deseni: kaynağı oku, o satırları BUDA, sonra include et.
$pageSrc = file_get_contents(__ROOT__ . '/gunluk_isci_puantaj.php');
$pageSrc = preg_replace('/^\s*require_once __DIR__ \. \'\/(config\/db|config\/pdks|config\/pdks_gunluk|config\/auth)\.php\';.*$/m', '', $pageSrc);
$pageSrc = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $pageSrc);
$pageSrc = preg_replace('/^<\?php\s*$/m', '', $pageSrc, 1);
$pageSrc = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $pageSrc);
eval($pageSrc);
PHPKOD;
$csvAltSurec = str_replace('__ROOT__', var_export($ROOT, true), $csvAltSurec);
// require_once __DIR__/config/*.php satırlarını gerçek dosya CANLI okunacağı
// için gunluk_isci_puantaj.php İÇİNDEKİ require_once'lar zaten doğru yolu
// buluyor (bkz. dosyanın kendi __DIR__ kullanımı) — yalnız bu alt-süreç
// betiğindeki require_once'lar için __ROOT__ değiştirildi.
$tmpCsv = sys_get_temp_dir() . '/pdks_gunluk_faz3_csv_altsurec.php';
file_put_contents($tmpCsv, $csvAltSurec);
$ciktiCsv = []; $rcCsv = 0;
exec('php ' . escapeshellarg($tmpCsv) . ' 2>&1', $ciktiCsv, $rcCsv);
$ciktiCsvTam = implode("\n", $ciktiCsv);
@unlink($tmpCsv);
ok('CSV alt-süreci PHP Fatal/Warning SIZDIRMADI (header() sorunsuz çalıştı)',
    !str_contains($ciktiCsvTam, 'Fatal error') && !str_contains($ciktiCsvTam, 'Warning:'), $ciktiCsvTam);
// Faz 7 (kullanıcının açık talimatı — Türkçe CSV/Excel dışa aktarım
// başlıkları): bu satır eskiden İngilizce başlık BEKLİYORDU ('Foreman',
// 'Missing Exit'); artık tam tersi doğrulanıyor — İngilizce başlık YOK,
// Türkçe karşılıkları VAR (bkz. pdks_takip_static_smoke.php'nin geniş
// yasaklı-İngilizce-başlık taraması).
ok('CSV başlık satırı Türkçe sütun adlarını taşıyor (Faz 7 — İngilizce başlık YOK)',
    !str_contains($ciktiCsvTam, 'Foreman') && !str_contains($ciktiCsvTam, 'Missing Exit')
    && str_contains($ciktiCsvTam, 'Çavuş') && str_contains($ciktiCsvTam, 'Eksik Çıkış'), $ciktiCsvTam);
ok('CSV içinde Ayşe Çavuş satırı var', str_contains($ciktiCsvTam, 'Ayşe Çavuş'), $ciktiCsvTam);

echo "\n=== 5. gunluk_isci_puantaj.php — geçersiz tarih GÜVENLE bugüne düşer ===\n";
$sBad = renderPage('gunluk_isci_puantaj.php', ['tarih' => 'not-a-date']);
ok('hata sızmadı (SQL enjeksiyonu/çökme yok)', !str_starts_with($sBad, '__ERROR__'), $sBad);
ok('bugünün verisiyle render edildi (Ayşe listede)', str_contains($sBad, 'Ayşe Çavuş'));

echo "\n=== 6. gunluk_isci_puantaj_detay.php — Ayşe'nin oturumu ===\n";
$sD = renderPage('gunluk_isci_puantaj_detay.php', ['id' => (string)$ayseSessionId]);
ok('hata sızmadı', !str_starts_with($sD, '__ERROR__'), $sD);
ok('PHP Warning/Notice yok', !str_contains($sD, 'Warning:') && !str_contains($sD, 'Notice:'));
ok('Ayşe Çavuş başlıkta', str_contains($sD, 'Ayşe Çavuş'));
ok('K001 satırı "✅ Tam" rozetiyle', (bool)preg_match('/K001.*?Tam/s', $sD));
ok('K002 satırı "⚠️ Çıkış Yok" rozetiyle', (bool)preg_match('/K002.*?Çıkış Yok/s', $sD));
ok('Açılış eden kullanıcı adı görünüyor (Test Kullanıcı)', str_contains($sD, 'Test Kullanıcı'));
ok('"Açık Mesai" rozeti başlıkta', str_contains($sD, 'Açık Mesai'));

echo "\n=== 7. gunluk_isci_puantaj_detay.php — geçersiz id ===\n";
// ⚠ Bu dal header('Location: ...') + exit() ÇAĞIRIYOR — pdks_gunluk_ui_smoke.php'nin
// "olmayan id" notundaki AYNI kısıt: bu render harness'inde çağrılırsa
// CLI'ın önceki echo çıktısı yüzünden "headers already sent" uyarısı verir
// (gerçek tarayıcıda SORUN DEĞİL — orada henüz hiçbir byte gönderilmemiştir).
// Bu yüzden BURADA renderPage() ile ÇAĞRILMIYOR; kod yolu zaten fonksiyon
// seviyesinde kanıtlanıyor: pdks_gunluk_gun_listesi()/pdks_gunluk_oturum_kartlari()
// olmayan bir session_id için boş sonuç döner (pdks_gunluk_faz3_smoke.php),
// sayfanın "$oturum yoksa yönlendir" dalı kısa ve doğrudan (bkz. kaynak).
ok('geçersiz id dalı statik olarak doğrulandı (bkz. yukarıdaki not)',
    str_contains(file_get_contents($ROOT . '/gunluk_isci_puantaj_detay.php'), "set_flash('error', 'Mesai bulunamadı.')"));

echo "\n=== 8. YETKİ KAPISI — GERÇEKTEN çalışıyor ===\n";
$PERMS = [];   // attendance.daily_reports YOK
$rNo = renderPage('gunluk_isci_puantaj.php');
ok('yetkisiz erişimde gunluk_isci_puantaj.php REDDEDİLİYOR', str_starts_with($rNo, '__ERROR__: forbidden'), $rNo);
$rNo2 = renderPage('gunluk_isci_puantaj_detay.php', ['id' => (string)$ayseSessionId]);
ok('yetkisiz erişimde gunluk_isci_puantaj_detay.php REDDEDİLİYOR', str_starts_with($rNo2, '__ERROR__: forbidden'), $rNo2);
$PERMS = ['attendance.daily_scan'];   // yalnız tarama yetkisi — rapor DEĞİL
$rScanOnly = renderPage('gunluk_isci_puantaj.php');
ok('YALNIZ attendance.daily_scan ile rapor sayfası REDDEDİLİYOR (operator senaryosu)', str_starts_with($rScanOnly, '__ERROR__: forbidden'), $rScanOnly);
$PERMS = ['attendance.daily_reports'];   // sıfırla

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
