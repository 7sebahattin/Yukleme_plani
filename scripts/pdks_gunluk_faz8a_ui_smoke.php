<?php
// =========================================================
// scripts/pdks_gunluk_faz8a_ui_smoke.php — Günlük İşçi Faz 8A sayfa
// (render) testi.
//
// SADECE CLI. Canlı veritabanına HİÇ dokunmaz: bellek içi SQLite ve
// stub'lanmış auth/render fonksiyonlarıyla gunluk_isci_giris_cikis.php +
// isci_kartlari.php'yi GERÇEKTEN render eder (pdks_gunluk_faz2_ui_smoke.php
// İLE AYNI desen — GERÇEK DDL çevirici, kısaltılmış şema YOK).
//
// ⚠ ajax=oturum/kaydet uçları POST + exit() içerir — GET render testi
// bunları TETİKLEMEZ. İş kuralları scripts/pdks_gunluk_faz8a_smoke.php'de
// KAPSAMLI test ediliyor; burada YALNIZ sayfaların ŞEMA DURUMUNA göre
// DOĞRU render ettiği doğrulanır (Faz 8A hazır → GİRİŞ öncesi tip seçimi;
// hazır değil → Faz 2'nin eski ekranı AYNEN görünür).
//
//   php scripts/pdks_gunluk_faz8a_ui_smoke.php   → çıkış kodu 0 = geçti
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

$PERMS = ['attendance.daily_scan', 'attendance.worker_cards'];
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

require_once $ROOT . '/config/pdks.php';
require_once $ROOT . '/config/pdks_gunluk.php';

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

$kadinId = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Ayşe Çavuş'], 1, db());

function renderPage(string $file, array $get = []): string {
    global $ROOT;
    $_GET = $get; $_POST = []; $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/' . $file;
    $src = file_get_contents($ROOT . '/' . $file);
    $src = preg_replace('/^\s*require_once __DIR__ \. \'\/(config\/db|config\/pdks|config\/pdks_gunluk|config\/auth)\.php\';.*$/m', '', $src);
    $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
    $src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
    $src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $tmp = sys_get_temp_dir() . '/pdksfaz8aui_' . md5($file . serialize($get)) . '.php';
    file_put_contents($tmp, "<?php\n" . $src);
    ob_start();
    try { include $tmp; } catch (Throwable $e) { ob_end_clean(); return '__ERROR__: ' . $e->getMessage(); }
    return ob_get_clean();
}

$fail = 0; $gecen = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail, $gecen;
    $c ? $gecen++ : $fail++;
    printf("%-95s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}

echo "\n=== 1. FAZ 8A ŞEMASI HENÜZ HAZIR DEĞİLKEN — ESKİ EKRAN AYNEN GÖRÜNÜR ===\n";
$s0 = renderPage('gunluk_isci_giris_cikis.php');
ok('sayfa hata sızdırmadan render edildi', !str_contains($s0, '__ERROR__'), $s0);
ok('PHP Warning/Notice yok', !preg_match('/Warning:|Notice:|Deprecated:/', $s0));
ok('İşçi tipi seçim penceresi RENDER EDİLMEDİ (şema hazır değil)', !str_contains($s0, 'id="giTipSec"'));
ok('Mod seçim ekranı (GİRİŞ/ÇIKIŞ butonları) hâlâ render ediliyor', str_contains($s0, 'GİRİŞ MODU') && str_contains($s0, 'ÇIKIŞ MODU'));

echo "\n=== 2. FAZ 8A MİGRASYONUNU ÇALIŞTIR ===\n";
// ⚠ RAW MySQL CREATE TABLE (AUTO_INCREMENT/ENGINE=/FOREIGN KEY...ON DELETE)
// SQLite'ta ÇALIŞTIRILAMAZ (bkz. scripts/pdks_gunluk_faz8a_smoke.php'nin
// AYNI notu — repodaki TÜM diğer pdks_*_migrate() smoke testlerinin ortak
// kısıtı). Tablo, migrate() ÇAĞRILMADAN ÖNCE DDL çeviriciyle ÖN-KURULUR —
// asıl test edilen, şema hazır olduktan SONRA sayfaların doğru render
// ettiğidir, CREATE TABLE'ın SQLite uyumluluğu değil (o DDL'in kendisi
// scripts/pdks_gunluk_faz8a_static_smoke.php'de statik doğrulanıyor).
[$dwwpCreate, $dwwpIdx] = pdks_ddl_sqlite(pdks_gunluk_faz8a_tablolar()['daily_worker_work_periods']);
db()->exec($dwwpCreate);
foreach ($dwwpIdx as $ix) db()->exec($ix);
$migRapor = pdks_gunluk_faz8a_migrate(db());
ok('migrasyon hatasız çalıştı (hiçbir adım "hata" durumunda değil)',
    !in_array('hata', array_column($migRapor, 'durum'), true), json_encode($migRapor));
ok('pdks_gunluk_faz8a_sema_hazir() artık true', pdks_gunluk_faz8a_sema_hazir(db()) === true);

// Nötr kart yalnız Faz 8A şeması hazır olduktan sonra oluşturulur.
// Çekirdek güvenlik kapısı migrasyon öncesinde bunu bilerek reddeder.
$k001Sonuc = pdks_gunluk_kart_olustur(
    ['card_no' => 'K001', 'ham_uid' => '631799511', 'kaynak' => 'usb_decimal'],
    1,
    db()
);
if (!($k001Sonuc['ok'] ?? false)) {
    throw new RuntimeException('K001 nötr test kartı oluşturulamadı: ' . ($k001Sonuc['hata'] ?? 'bilinmeyen hata'));
}

echo "\n=== 3. FAZ 8A ŞEMASI HAZIRKEN — GİRİŞ ÖNCESİ İŞÇİ TİPİ SEÇİMİ ===\n";
$s1 = renderPage('gunluk_isci_giris_cikis.php');
ok('sayfa hata sızdırmadan render edildi', !str_contains($s1, '__ERROR__'), $s1);
ok('PHP Warning/Notice yok', !preg_match('/Warning:|Notice:|Deprecated:/', $s1));
ok('İşçi tipi seçim penceresi başlangıçta gizli render edildi', (bool)preg_match('/id="giTipSec"[^>]*role="dialog"[^>]*hidden/', $s1));
$modeHtml = substr($s1, strpos($s1, 'id="giModeSec"'), strpos($s1, 'id="giTipSec"') - strpos($s1, 'id="giModeSec"'));
$tipHtml = substr($s1, strpos($s1, 'id="giTipSec"'), strpos($s1, 'id="giScanSec"') - strpos($s1, 'id="giTipSec"'));
$scanHtml = substr($s1, strpos($s1, 'id="giScanSec"'), strpos($s1, 'id="giReconSec"') - strpos($s1, 'id="giScanSec"'));
ok('Ana mod ekranında yalnız iki büyük mod butonu, işçi tipi/mesai seçimi yok', substr_count($modeHtml, 'data-gi-mode=') === 2 && !str_contains($modeHtml, 'data-gi-tip-id=') && !str_contains($modeHtml, 'data-gi-mesai-kod='));
ok('İşçi tipi penceresinde Kadın ve Erkek seçenekleri dinamik tip kimlikleriyle var', substr_count($tipHtml, 'data-gi-tip-id=') === 2 && str_contains($tipHtml, 'KADIN') && str_contains($tipHtml, 'ERKEK'));
ok('Tarama ekranında seçim butonları yerine kompakt mod ve tip rozetleri var', str_contains($scanHtml, 'id="giModeBadge"') && str_contains($scanHtml, 'id="giTipBadge"') && !str_contains($scanHtml, 'data-gi-tip-id='));
// ⚠ v240 (kullanıcı isteği): günün özeti (giSayaclar) + Mesaiyi Kapat artık
// kart okutmadan ÖNCE görünür — sıra: çavuş/mod → Bugün özeti+Kapat →
// okutma/NFC → yardımcı işlemler (eskiden özet en sonda, taramanın ALTINDAYDI).
ok('Tarama sırası: çavuş/mod → Bugün özeti+Kapat → okutma/NFC → yardımcı işlemler',
    strpos($scanHtml, 'id="giScanCavusAd"') < strpos($scanHtml, 'id="giModeBadge"')
    && strpos($scanHtml, 'id="giModeBadge"') < strpos($scanHtml, 'id="giSayaclar"')
    && strpos($scanHtml, 'id="giSayaclar"') < strpos($scanHtml, 'id="giKapatBtn"')
    && strpos($scanHtml, 'id="giKapatBtn"') < strpos($scanHtml, 'pdks-kiosk-scan-icon')
    && strpos($scanHtml, 'pdks-kiosk-scan-icon') < strpos($scanHtml, 'id="giNfcBtnWrap"')
    && strpos($scanHtml, 'id="giNfcBtnWrap"') < strpos($scanHtml, 'id="giCavusDegistir2"'));
ok('GİRİŞ tip seçimine gider; tip seçildikten sonra taramaya geçer; ÇIKIŞ tipi sormadan ilerler',
    (bool)preg_match('/if \(mod === \'GIRIS\' && tipSec\) \{\s*ekranGoster\(tipSec\);/s', $s1)
    && str_contains($s1, "modSec('GIRIS');") && str_contains($s1, 'modSec(mod);'));
ok('Tam/Yarım/Fazla Mesai seçim arayüzü yok', !str_contains($s1, 'data-gi-mesai-kod=') && !str_contains($s1, 'giTipMesaiSec'));
ok('Web Audio beep fonksiyonları (sesBasarili/sesHata) sayfada tanımlı', str_contains($s1, 'sesBasarili') && str_contains($s1, 'sesHata'));
ok('PdksNfcOku.baslat() ÇAĞRISI hâlâ AYNEN duruyor (NFC yaşam döngüsü BOZULMADI)', str_contains($s1, 'PdksNfcOku.baslat('));

echo "\n=== 4. İŞÇİ KARTLARI SAYFASI — NÖTR KART OLUŞTURMA + LİSTE ===\n";
$s2 = renderPage('isci_kartlari.php');
ok('sayfa hata sızdırmadan render edildi', !str_contains($s2, '__ERROR__'), $s2);
ok('PHP Warning/Notice yok', !preg_match('/Warning:|Notice:|Deprecated:/', $s2));
$s2OncekiModal = explode('id="iskKartModal"', $s2)[0];   // yalnız düzenleme modalinden ÖNCEKİ (create formu içeren) kısım
ok('Yeni kart formu İşçi Tipi seçici İÇERMİYOR (nötr oluşturma — düzenleme modalindeki opsiyonel alan HARİÇ)',
    !str_contains($s2OncekiModal, '<select name="worker_type_id"'));
ok('Mevcut nötr kart (K001, tip yok) listede "— (nötr)" olarak görünüyor', str_contains($s2, '— (nötr)'));
ok('Kart no K001 listede görünüyor (LEFT JOIN kartı düşürmedi)', str_contains($s2, 'K001'));
ok('Kart düzenleme modalı responsive sınıf kullanıyor',
    str_contains($s2, 'class="pm-dialog isk-card-modal"'));
ok('Kart düzenleme modalı, güvenli iç boşluk ve dikey kaydırma için ayrı gövde kullanıyor',
    str_contains($s2, 'class="isk-card-modal-body"'));
ok('Kart düzenleme form aksiyonları taşma-korumalı sınıf kullanıyor',
    str_contains($s2, 'class="isk-card-form-actions"'));
ok('Kart durum işlemleri responsive action grid kullanıyor',
    str_contains($s2, 'class="isk-card-status-actions"'));

printf("\n=== SONUÇ: %d geçti, %d hata ===\n", $gecen, $fail);
exit($fail > 0 ? 1 : 0);
