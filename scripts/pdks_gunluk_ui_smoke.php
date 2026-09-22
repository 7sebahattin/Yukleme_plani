<?php
// =========================================================
// scripts/pdks_gunluk_ui_smoke.php — Günlük İşçi sayfaları arayüz (render) testi
//
// SADECE CLI. Canlı veritabanına HİÇ dokunmaz: bellek içi SQLite ve
// stub'lanmış auth/render fonksiyonlarıyla cavuslar.php / cavus_form.php /
// isci_kartlari.php / isci_tipleri.php'yi GERÇEKTEN render eder
// (pdks_giris_cikis_ui_smoke.php / pdks_personel_mobil_smoke.php ile aynı desen).
//
// ⚠ POST-sonrası header()+exit() akışları (kayıt/kaydet) BURADA test
// EDİLMEZ — pdks_faz1b_ui_smoke.php'nin kendi notundaki AYNI gerekçe: PHP'de
// exit() include içinden bile YAKALANAMAZ, tüm test sürecini sonlandırır.
// O mantık zaten scripts/pdks_gunluk_smoke.php'de FONKSİYON seviyesinde
// (pdks_gunluk_cavus_olustur/pdks_gunluk_kart_olustur…) kapsanıyor. Burada
// yalnız GET render + yetki kapısı doğrulanır.
//
//   php scripts/pdks_gunluk_ui_smoke.php   → çıkış kodu 0 = tüm testler geçti
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

$PERMS = ['attendance.foremen', 'attendance.worker_cards'];
$IS_ADMIN = false;
function current_user(): ?array { return ['id' => 1, 'username' => 'test', 'display_name' => 'Test Kullanıcı']; }
function can(string $p): bool { global $PERMS; return in_array($p, $PERMS, true); }
function is_admin(): bool { global $IS_ADMIN; return $IS_ADMIN; }
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

require_once $ROOT . '/config/pdks.php';           // GERÇEK — çapraz kontrol için gerekli
require_once $ROOT . '/config/pdks_gunluk.php';    // GERÇEK — test edilen budur

// ─────────────────────────────────────────────────────────
// MySQL DDL → SQLite çevirici — scripts/pdks_gunluk_smoke.php / pdks_db_smoke.php
// İLE BİREBİR AYNI (kasıtlı kopya). Önceki basitleştirilmiş kolon-eşleyici
// DEFAULT değerlerini (ör. is_active DEFAULT 1) KORUMUYORDU — seed'den gelen
// satırlar is_active boş kalıp filtre testlerini YANLIŞ ÇÜRÜTÜYORDU. Gerçek
// DDL çevirici DEFAULT'ları da taşır.
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
        if (preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY/i', $p)) {
            // SQLite: FK'yı kolon seviyesinde/PRAGMA ile YÖNETMEYE gerek yok bu
            // test için — REFERENCES bütünlüğü zaten UNIQUE/kod seviyesinde
            // test ediliyor; CONSTRAINT satırını sessizce ATLA (pdks_db_smoke.php
            // ile aynı basitleştirme kararı — o dosya da FK satırlarını
            // employees/employee_cards için AYRICA ele alır, burada da
            // worker_cards→worker_types FK'sı test amaçlı atlanır).
            continue;
        }

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

// ── Gerçek şema — HEM kalıcı personel (çapraz kontrol için) HEM Faz 1 ──
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

pdks_gunluk_migrate(db());   // GERÇEK migrate — seed'i de çalıştırır

// Test verisi
$kadinId = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
db()->prepare("INSERT INTO foremen (code, name, phone, is_active, created_at) VALUES (?,?,?,?,?)")
    ->execute(['C001', 'Ayşe Çavuş', '05551112233', 1, '2026-01-01 10:00:00']);
$cavusId = (int)db()->lastInsertId();
db()->prepare("INSERT INTO foremen (code, name, is_active, created_at) VALUES (?,?,?,?)")
    ->execute(['C002', 'Pasif Çavuş', 0, '2026-01-01 10:00:00']);

db()->prepare("INSERT INTO worker_cards (card_no, worker_type_id, canonical_uid, status, enrolled_source, created_at) VALUES (?,?,?,?,?,?)")
    ->execute(['K001', $kadinId, '25A87ED7', 'available', 'usb_decimal', '2026-01-02 09:00:00']);
// Düzeltme testi için: 'lost' durumunda bir kart da eklenir — "in_use" ARTIK
// hiçbir durumda üretilmiyor (kullanıcının açık düzeltmesi), o yüzden
// available/lost/disabled'ın HEPSİNİN doğru rozetle render edildiği görülsün.
db()->prepare("INSERT INTO worker_cards (card_no, worker_type_id, canonical_uid, status, enrolled_source, created_at) VALUES (?,?,?,?,?,?)")
    ->execute(['K002', $kadinId, 'D77EA825', 'lost', 'usb_decimal', '2026-01-02 09:05:00']);

function renderPage(string $file, array $get = []): string {
    global $ROOT;
    $_GET = $get; $_POST = []; $_FILES = []; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/' . $file;
    $src = file_get_contents($ROOT . '/' . $file);
    $src = preg_replace('/^\s*require_once __DIR__ \. \'\/(config\/db|config\/pdks|config\/pdks_gunluk|config\/auth)\.php\';.*$/m', '', $src);
    $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
    $src = preg_replace('/^\s*pdks_gunluk_migrate\(\);(?:\s*\/\/.*)?\s*$/m', '', $src);
    $src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
    $src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $tmp = sys_get_temp_dir() . '/pdksgunlukui_' . md5($file . serialize($get)) . '.php';
    file_put_contents($tmp, "<?php\n" . $src);
    ob_start();
    try { include $tmp; } catch (Throwable $e) { ob_end_clean(); return '__ERROR__: ' . $e->getMessage(); }
    return ob_get_clean();
}

$fail = 0; $gecen = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail, $gecen;
    $c ? $gecen++ : $fail++;
    printf("%-78s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}

echo "\n=== 1. cavuslar.php — liste ===\n";
$s = renderPage('cavuslar.php');
ok('hata sızmadı', !str_starts_with($s, '__ERROR__'), $s);
ok('PHP Warning/Notice yok', !str_contains($s, 'Warning:') && !str_contains($s, 'Notice:'));
ok('Ayşe Çavuş listede', str_contains($s, 'Ayşe Çavuş'));
ok('C001 kodu görünüyor', str_contains($s, 'C001'));
ok('Pasif Çavuş da listede (varsayılan filtre: tümü)', str_contains($s, 'Pasif Çavuş'));
ok('"+ Yeni Çavuş" butonu var', str_contains($s, 'Yeni Çavuş'));
// ⚠ Sprint Navigasyon-05 (kullanıcı isteği): personel_takip.php'den açılan
// sayfalar arasındaki çapraz bağlantılar kaldırıldı (Kart Havuzu ARTIK
// buradan değil, personel_takip.php'nin kendi kartından açılıyor); yerine
// standart "← Personel Takibi" dönüş butonu geldi.
ok('İşçi Kartları çapraz bağlantısı KALDIRILDI, standart dönüş butonu var',
    !str_contains($s, 'isci_kartlari.php') && str_contains($s, 'personel_takip.php'));

echo "\n=== 2. cavuslar.php — yalnız aktif filtre ===\n";
$sAktif = renderPage('cavuslar.php', ['durum' => 'aktif']);
ok('hata sızmadı', !str_starts_with($sAktif, '__ERROR__'), $sAktif);
ok('Ayşe Çavuş görünüyor', str_contains($sAktif, 'Ayşe Çavuş'));
ok('Pasif Çavuş GİZLİ (aktif filtre)', !str_contains($sAktif, 'Pasif Çavuş'));

echo "\n=== 3. cavus_form.php — yeni kayıt (kod önerisi) ===\n";
$sYeni = renderPage('cavus_form.php');
ok('hata sızmadı', !str_starts_with($sYeni, '__ERROR__'), $sYeni);
ok('önerilen kod C003 (C001+C002 sonrası)', (bool)preg_match('/value="C003"/', $sYeni));
ok('Ad Soyad alanı zorunlu', (bool)preg_match('/name="name"[^>]*required/', $sYeni));

echo "\n=== 4. cavus_form.php — mevcut kayıt düzenleme ===\n";
$sDuz = renderPage('cavus_form.php', ['id' => (string)$cavusId]);
ok('hata sızmadı', !str_starts_with($sDuz, '__ERROR__'), $sDuz);
ok('mevcut isim formda dolu', str_contains($sDuz, 'value="Ayşe Çavuş"'));
ok('Pasifleştir butonu var (şu an aktif)', str_contains($sDuz, 'Pasifleştir'));
ok('"silinemez" notu var (kullanıcının açık talimatı: silme yok)', str_contains($sDuz, 'silinemez'));

echo "\n=== 5. cavus_form.php — olmayan id ===\n";
// header()+exit çağırdığı için burada throw EDİLMEZ (renderPage exit'i
// yakalayamaz) — bu yüzden pdks_faz1b_ui_smoke.php'nin notundaki AYNI
// sebeple bu senaryo BURADA test edilmiyor; kod yolu backend testte
// (pdks_gunluk_cavus_guncelle 999999 → 'ok'=>false) zaten kanıtlanıyor.

echo "\n=== 6. isci_kartlari.php — liste + tanımlama formu ===\n";
$sIk = renderPage('isci_kartlari.php');
ok('hata sızmadı', !str_starts_with($sIk, '__ERROR__'), $sIk);
ok('PHP Warning/Notice yok', !str_contains($sIk, 'Warning:') && !str_contains($sIk, 'Notice:'));
ok('K001 kartı listede', str_contains($sIk, 'K001'));
ok('kanonik UID listede (25A87ED7)', str_contains($sIk, '25A87ED7'));
ok('USB tarama kutusu var (data-pdks-scan REUSE)', str_contains($sIk, 'data-pdks-scan'));
ok('NFC buton hedefi var (data-pdks-nfc-target REUSE)', str_contains($sIk, 'data-pdks-nfc-target'));
ok('assets/pdks.js yükleniyor', str_contains($sIk, 'assets/pdks.js'));
ok('Kadın işçi tipi seçeneği var', str_contains($sIk, 'Kadın'));
ok('sonraki kart no önerisi K003 (K001+K002 sonrası)', (bool)preg_match('/value="K003"/', $sIk));
ok('Düzenle modalı DOM\'da var', str_contains($sIk, 'iskKartModal'));
ok('kart_no alanı TEK enroll formunda', substr_count($sIk, 'name="card_no"') >= 1);

echo "\n--- 6a. DÜZELTME (kullanıcının açık talimatı): 'in_use' HİÇBİR YERDE render edilmiyor ---\n";
ok('K002 (lost) kartı listede', str_contains($sIk, 'K002'));
ok('"Kullanımda" etiketi SAYFADA HİÇ YOK (in_use kaldırıldı)', !str_contains($sIk, 'Kullanımda'));
ok('durum filtre açılır listesi TAM ÜÇ seçenek sunuyor (Tümü + available + lost + disabled)',
    substr_count($sIk, '<option value="') >= 1
    && (bool)preg_match('/<select name="durum">.*?<\/select>/s', $sIk, $durumSelM)
    && substr_count($durumSelM[0], '<option') === 4);   // "Tüm durumlar" + 3 durum
ok('pdks-badge-degistirildi (eski in_use rengi) ARTIK render edilmiyor', !str_contains($sIk, 'pdks-badge-degistirildi'));
ok('lost kart doğru rozetle render ediliyor (pdks-badge-kayip)', str_contains($sIk, 'pdks-badge-kayip'));
ok('available kart doğru rozetle render ediliyor (pdks-badge-aktif)', str_contains($sIk, 'pdks-badge-aktif'));

echo "\n=== 7. isci_tipleri.php — sabit KADIN/ERKEK listesi (Faz 9B — H-01 kapanışı, ekleme formu KALDIRILDI) ===\n";
$sIt = renderPage('isci_tipleri.php');
ok('hata sızmadı', !str_starts_with($sIt, '__ERROR__'), $sIt);
ok('Kadın tipi listede', str_contains($sIt, 'Kadın'));
ok('Erkek tipi listede', str_contains($sIt, 'Erkek'));
ok('sabit sistem tipi modeli AÇIKÇA anlatılıyor', str_contains($sIt, 'Sabit Sistem Tipleri') && str_contains($sIt, 'sabit'));
ok('rastgele yeni tip oluşturma FORMU artık YOK', !str_contains($sIt, 'name="action" value="ekle"') && !str_contains($sIt, 'ör. FORKLIFT'));
ok('pdks_gunluk_tip_olustur() sayfadan HİÇ ÇAĞRILMIYOR (rastgele tip oluşturma yolu YOK — yorumdaki isim-anma boş parantezle "()" ayrılır, GERÇEK çağrı DEĞİLDİR)',
    !preg_match('/pdks_gunluk_tip_olustur\s*\(\s*[^)\s]/', (string)file_get_contents(dirname(__DIR__) . '/isci_tipleri.php')));

echo "\n=== 8. YETKİ KAPISI — her sayfada GERÇEKTEN çalışıyor ===\n";
$PERMS = [];   // hiçbir attendance.* yetkisi yok
foreach (['cavuslar.php', 'cavus_form.php', 'isci_kartlari.php', 'isci_tipleri.php'] as $f) {
    $r = renderPage($f);
    ok("$f yetkisiz erişimde REDDEDİLİYOR", str_starts_with($r, '__ERROR__: forbidden'), $r);
}

$PERMS = ['attendance.foremen'];   // yalnız çavuş yetkisi
$rF = renderPage('cavuslar.php');
ok('yalnız attendance.foremen ile cavuslar.php AÇILIYOR', !str_starts_with($rF, '__ERROR__'), $rF);
$rK = renderPage('isci_kartlari.php');
ok('yalnız attendance.foremen ile isci_kartlari.php REDDEDİLİYOR (attendance.worker_cards gerekir)',
    str_starts_with($rK, '__ERROR__: forbidden'), $rK);

$PERMS = ['attendance.worker_cards'];   // yalnız işçi kartı yetkisi
$rK2 = renderPage('isci_kartlari.php');
ok('yalnız attendance.worker_cards ile isci_kartlari.php AÇILIYOR', !str_starts_with($rK2, '__ERROR__'), $rK2);
$rF2 = renderPage('cavuslar.php');
ok('yalnız attendance.worker_cards ile cavuslar.php REDDEDİLİYOR (attendance.foremen gerekir)',
    str_starts_with($rF2, '__ERROR__: forbidden'), $rF2);

$PERMS = ['attendance.foremen', 'attendance.worker_cards'];   // sıfırla

// Not: personel_kartlar.php/personel_form.php'nin KENDİ tam render testleri
// (yeni config/pdks_gunluk.php require satırıyla birlikte, TAM şema ile)
// scripts/pdks_faz1b_ui_smoke.php'de zaten kapsamlı — burada TEKRARLANMIYOR
// (kısaltılmış bir mini-şema burada YANLIŞ GÜVENCE verirdi). Bu görevin
// düzenli regresyon kanıtı, o gerçek paket bu değişiklikten SONRA da
// yeşil kalarak sağlanır (bkz. rapor).

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
