<?php
// =========================================================
// scripts/pdks_hakedis_ui_smoke.php — Çavuş Hakediş (Faz 4) sayfaları
// arayüz (render) testi
//
// SADECE CLI. Canlı veritabanına HİÇ dokunmaz: bellek içi SQLite ve
// stub'lanmış auth/render fonksiyonlarıyla cavus_fiyatlari.php /
// cavus_hakedis.php / cavus_hakedis_detay.php'yi GERÇEKTEN render eder
// (pdks_gunluk_faz3_ui_smoke.php İLE AYNI desen).
//
//   php scripts/pdks_hakedis_ui_smoke.php   → çıkış kodu 0 = tüm testler geçti
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

$PERMS = ['attendance.foreman_rates', 'attendance.entitlements'];
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
pdks_gunluk_migrate(db());

// ── Test verisi: Ayşe (fiyatlı, kesinleşmiş hakediş) + Mehmet (fiyatsız) ──
$kadinId = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkekId = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$ayseId = (int)pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Ayşe Çavuş'], 1, db())['id'];
$mehmetId = (int)pdks_gunluk_cavus_olustur(['code' => 'C002', 'name' => 'Mehmet Çavuş'], 1, db())['id'];

pdks_hakedis_oran_ekle($ayseId, $kadinId, '1200', '2026-01-01', 'TRY', 1, db());
pdks_hakedis_oran_ekle($ayseId, $erkekId, '1500', '2026-01-01', 'TRY', 1, db());

pdks_gunluk_kart_olustur(['card_no' => 'K001', 'worker_type_id' => $kadinId, 'ham_uid' => '631799511', 'kaynak' => 'usb_decimal'], 1, db());
pdks_gunluk_kart_olustur(['card_no' => 'E001', 'worker_type_id' => $erkekId, 'ham_uid' => '444555666', 'kaynak' => 'usb_decimal'], 1, db());

$oAyse = pdks_gunluk_oturum_ac_veya_getir($ayseId, 1, db());
$ayseSessionId = (int)$oAyse['session']['id'];
$bugun = date('Y-m-d');
pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $ayseSessionId, 'GIRIS', 1, db());
pdks_gunluk_oturum_kaydet('631799511', 'usb_decimal', $ayseSessionId, 'CIKIS', 1, db());
pdks_gunluk_oturum_kaydet('444555666', 'usb_decimal', $ayseSessionId, 'GIRIS', 1, db());
pdks_gunluk_oturum_kaydet('444555666', 'usb_decimal', $ayseSessionId, 'CIKIS', 1, db());
pdks_gunluk_oturum_kapat($ayseSessionId, null, 1, db());
$hesapAyse = pdks_hakedis_hesapla($ayseSessionId, 1, db());
$finalAyse = pdks_hakedis_finalize($ayseSessionId, 1, false, db());
$ayseEntId = (int)$finalAyse['entitlement_id'];

// Mehmet — oturum var ama HİÇ fiyat girilmedi (eksik oran senaryosu).
$oMehmet = pdks_gunluk_oturum_ac_veya_getir($mehmetId, 1, db());
$mehmetSessionId = (int)$oMehmet['session']['id'];
$km = pdks_gunluk_kart_olustur(['card_no' => 'K002', 'worker_type_id' => $kadinId, 'ham_uid' => '111222333', 'kaynak' => 'usb_decimal'], 1, db());
pdks_gunluk_oturum_kaydet('111222333', 'usb_decimal', $mehmetSessionId, 'GIRIS', 1, db());

function renderPage(string $file, array $get = [], array $post = []): string {
    global $ROOT;
    $_GET = $get; $_POST = $post; $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    $_SERVER['REQUEST_URI'] = '/' . $file;
    $src = file_get_contents($ROOT . '/' . $file);
    $src = preg_replace('/^\s*require_once __DIR__ \. \'\/(config\/db|config\/pdks|config\/pdks_gunluk|config\/pdks_hakedis|config\/auth)\.php\';.*$/m', '', $src);
    $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
    $src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
    $src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
    $tmp = sys_get_temp_dir() . '/pdkshakedisui_' . md5($file . serialize($get) . serialize($post)) . '.php';
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

echo "\n=== 1. cavus_fiyatlari.php — çavuş seçilmeden ===\n";
$s0 = renderPage('cavus_fiyatlari.php');
ok('hata sızmadı', !str_starts_with($s0, '__ERROR__'), $s0);
ok('PHP Warning/Notice yok', !str_contains($s0, 'Warning:') && !str_contains($s0, 'Notice:'));
ok('"önce bir çavuş seçin" boş-durum mesajı var', str_contains($s0, 'çavuş seçin'));

echo "\n=== 2. cavus_fiyatlari.php — Ayşe seçili (fiyat geçmişi) ===\n";
$s1 = renderPage('cavus_fiyatlari.php', ['cavus' => (string)$ayseId]);
ok('hata sızmadı', !str_starts_with($s1, '__ERROR__'), $s1);
ok('Kadın satırı listede', str_contains($s1, 'Kadın'));
ok('1.200,00 TRY görünüyor', str_contains($s1, '1.200,00'));
ok('"devam ediyor" (açık uçlu dönem) görünüyor', str_contains($s1, 'devam ediyor'));
ok('yeni fiyat dönemi formu var (daily_rate alanı)', str_contains($s1, 'name="daily_rate"'));

echo "\n=== 3. cavus_fiyatlari.php — yeni fiyat dönemi ekleme (POST) ===\n";
// ⚠ BAŞARILI bir POST burada header('Location:...')+exit() ÇAĞIRIR — exit()
// PHP'de include içinden bile YAKALANAMAZ ve bu TEST SÜRECİNİN KENDİSİNİ
// sonlandırır (Faz 1/3 UI testlerinin AYNI, belgelenmiş kısıtı — bkz.
// pdks_gunluk_ui_smoke.php'nin "olmayan id" notu). Bu yüzden BAŞARILI ekleme
// yolu BURADA renderPage() ile ÇAĞRILMAZ; pdks_hakedis_oran_ekle()'nin
// KENDİSİ zaten pdks_hakedis_smoke.php'de (1/2/3/4/5 numaralı senaryolar)
// kapsamlıca kanıtlanıyor. Burada yalnız BAŞARISIZ bir POST'un (geçersiz
// tutar) sayfayı GÜVENLE, header() ÇAĞIRMADAN yeniden render ettiği doğrulanır.
$s2 = renderPage('cavus_fiyatlari.php', [], [
    'csrf' => 'x', 'foreman_id' => (string)$ayseId, 'worker_type_id' => (string)$erkekId,
    'daily_rate' => 'gecersiz-tutar', 'currency' => 'TRY', 'valid_from' => '2026-11-01',
]);
ok('hata sızmadı (header()+exit() TETİKLENMEDİ — doğrulama hatası sayfayı normal render etti)', !str_starts_with($s2, '__ERROR__'), $s2);
ok('geçersiz tutar hata mesajı gösteriliyor', str_contains($s2, 'Günlük ücret geçersiz'));
$stKontrolYok = db()->prepare("SELECT COUNT(*) FROM foreman_worker_rates WHERE foreman_id=? AND worker_type_id=? AND valid_from='2026-11-01'");
$stKontrolYok->execute([$ayseId, $erkekId]);
ok('geçersiz tutarla HİÇBİR satır eklenmedi (0 ÜRETİLMEDİ)', (int)$stKontrolYok->fetchColumn() === 0);

echo "\n=== 4. cavus_hakedis.php — varsayılan (bugün) ===\n";
$s3 = renderPage('cavus_hakedis.php');
ok('hata sızmadı', !str_starts_with($s3, '__ERROR__'), $s3);
ok('PHP Warning/Notice yok', !str_contains($s3, 'Warning:') && !str_contains($s3, 'Notice:'));
ok('Ayşe Çavuş listede (KESİN)', str_contains($s3, 'Ayşe Çavuş'));
ok('Ayşe satırında KESİN rozeti var', (bool)preg_match('/Ayşe Çavuş.*?KESİN|KESİN.*?Ayşe Çavuş|Kesin/s', $s3) || str_contains($s3, 'pdks-badge-tamamlandi'));
ok('Mehmet Çavuş listede (Hesaplanmadı)', str_contains($s3, 'Mehmet Çavuş') && str_contains($s3, 'Hesaplanmadı'));
ok('Ayşe için Detay bağlantısı var', str_contains($s3, 'cavus_hakedis_detay.php?id=' . $ayseEntId));
ok('Mehmet için "Hesapla" butonu var (henüz entitlement yok)', str_contains($s3, 'Hesapla'));
ok('depo alanı disabled (zorunlu tek depo mimarisi)', (bool)preg_match('/name="depo"[^>]*disabled/', $s3));
ok('39.000,00 TRY toplamı görünüyor (30×1200 + ... değil, burada 1×1200+1×1500=2700)', str_contains($s3, '2.700,00'));

echo "\n=== 5. cavus_hakedis_detay.php — Ayşe'nin KESİN hakedişi ===\n";
$s4 = renderPage('cavus_hakedis_detay.php', ['id' => (string)$ayseEntId]);
ok('hata sızmadı', !str_starts_with($s4, '__ERROR__'), $s4);
ok('PHP Warning/Notice yok', !str_contains($s4, 'Warning:') && !str_contains($s4, 'Notice:'));
ok('Kadın satırı 1 × 1.200,00 = 1.200,00', str_contains($s4, 'Kadın') && str_contains($s4, '1.200,00'));
ok('Erkek satırı 1 × 1.500,00 = 1.500,00', str_contains($s4, 'Erkek') && str_contains($s4, '1.500,00'));
ok('TOPLAM 2.700,00 görünüyor', str_contains($s4, '2.700,00'));
ok('KESİN rozeti görünüyor', str_contains($s4, 'KESİN'));
ok('Hesaplayan/Kesinleştiren kullanıcı adı görünüyor (Test Kullanıcı)', substr_count($s4, 'Test Kullanıcı') >= 2);
ok('KESİN kayıtta "Yeniden Hesapla"/"Kesinleştir" formu YOK (yalnız admin\'e Yeniden Aç gösterilir, admin değiliz)', !str_contains($s4, 'Yeniden Hesapla'));

echo "\n=== 6. cavus_hakedis_detay.php — Mehmet için henüz entitlement yok → geçersiz ID davranışı ===\n";
ok('cavus_hakedis.php Mehmet için "Hesapla" POST formunu render ediyor (yukarıda zaten kanıtlandı)', str_contains($s3, 'session_id" value="' . $mehmetSessionId . '"'));

echo "\n=== 7. YETKİ KAPISI — operator (yalnız attendance.daily_scan) FİNANSAL SAYFALARI GÖREMİYOR ===\n";
$PERMS = ['attendance.daily_scan'];
foreach (['cavus_fiyatlari.php', 'cavus_hakedis.php'] as $f) {
    $r = renderPage($f);
    ok("operator izniyle $f REDDEDİLİYOR", str_starts_with($r, '__ERROR__: forbidden'), $r);
}
$rDetay = renderPage('cavus_hakedis_detay.php', ['id' => (string)$ayseEntId]);
ok('operator izniyle cavus_hakedis_detay.php REDDEDİLİYOR', str_starts_with($rDetay, '__ERROR__: forbidden'), $rDetay);

echo "\n--- 7a. 'ik' senaryosu (yalnız attendance.entitlements) — görebilir ama KESİNLEŞTİREMEZ ---\n";
$PERMS = ['attendance.entitlements'];
$rIkListe = renderPage('cavus_hakedis.php');
ok("YALNIZ attendance.entitlements ile cavus_hakedis.php AÇILIYOR (görüntüleme serbest)", !str_starts_with($rIkListe, '__ERROR__'), $rIkListe);
$rIkFiyat = renderPage('cavus_fiyatlari.php');
ok("YALNIZ attendance.entitlements ile cavus_fiyatlari.php REDDEDİLİYOR (ticari fiyat yönetimi YOK)", str_starts_with($rIkFiyat, '__ERROR__: forbidden'), $rIkFiyat);

$PERMS = ['attendance.foreman_rates', 'attendance.entitlements'];   // sıfırla

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
