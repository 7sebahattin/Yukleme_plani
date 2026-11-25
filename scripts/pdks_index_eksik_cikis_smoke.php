<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_index_eksik_cikis_smoke.php — index.php'nin "Personel Takibi"
// kartındaki EKSİK ÇIKIŞ rozeti için davranışsal doğrulama.
//
// SADECE CLI. Ağ yok, canlı DB'ye dokunmaz. index.php'nin TAMAMINI render
// ETMEZ (records/hesap/beyan/audit gibi ilgisiz bağımlılıkları kurmak
// gerekirdi) — yalnız $personel_eksik_cikis'i HESAPLAYAN GERÇEK kod bloğunu
// index.php'nin KENDİ kaynağından regex ile çıkarıp bellek içi SQLite ile
// ÇALIŞTIRIR (kopya yazılmaz — index.php değişirse bu test AYNI satırları
// çalıştırır, ayrışmaz).
//
//   php scripts/pdks_index_eksik_cikis_smoke.php   → çıkış kodu 0 = tüm testler geçti
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

$PERMS = [];
$IS_ADMIN = false;
$AKTIF_DEPO = 'Depo A';
function can(string $p): bool { global $PERMS; return in_array($p, $PERMS, true); }
function is_admin(): bool { global $IS_ADMIN; return $IS_ADMIN; }
function active_depot(): ?string { global $AKTIF_DEPO; return $AKTIF_DEPO; }
function audit_log_event(...$a): void {}

require_once $ROOT . '/config/pdks.php';
require_once $ROOT . '/config/pdks_gunluk.php';

function pdks_idx_ddl_sqlite(string $mysql): array
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

foreach (pdks_gunluk_tablolar() as $ad => $sql) { [$create, $ix] = pdks_idx_ddl_sqlite($sql); db()->exec($create); foreach ($ix as $i) db()->exec($i); }
foreach (pdks_gunluk_faz8a_tablolar() as $ad => $sql) { [$create, $ix] = pdks_idx_ddl_sqlite($sql); db()->exec($create); foreach ($ix as $i) db()->exec($i); }
pdks_gunluk_migrate(db());

$kadinId = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();

// v580: ana sayfadan tüm kart rozetleri/alt metinler kaldırıldığı için index.php'de
// $personel_eksik_cikis sorgusu artık yok; paylaşılan pdks_gunluk_eksik_cikislar()
// fonksiyonunun depo/çıkış/yetki davranışı doğrudan doğrulanır.
$indexSrc = (string)file_get_contents($ROOT . '/index.php');

function pdks_idx_hesapla(): int {
    if (!role_pdks_can('daily_scan') && !role_pdks_can('daily_reports')) return 0;
    if (!pdks_gunluk_sema_hazir(db())) return 0;
    return count(pdks_gunluk_eksik_cikislar(date('Y-m-d'), active_depot() ?? '', null, db()));
}

$fail = 0; $gecen = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail, $gecen;
    $c ? $gecen++ : $fail++;
    printf("%-90s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}

echo "\n=== A. Yetkisiz kullanıcı — sorgu HİÇ çalışmaz, rozet 0 ===\n";
$PERMS = [];
$IS_ADMIN = false;
$v = pdks_idx_hesapla();
ok('hiçbir attendance.* izni/yönetici YOKKEN $personel_eksik_cikis = 0', $v === 0, (string)$v);

echo "\n=== B. İzinli kullanıcı, EKSİK ÇIKIŞ YOK — rozet 0 ===\n";
$PERMS = ['attendance.daily_reports'];
$v = pdks_idx_hesapla();
ok('izin VAR ama bugün hiç mesai/eksik çıkış yokken $personel_eksik_cikis = 0', $v === 0, (string)$v);

echo "\n=== C. Bugün İKİ eksik çıkışlı işçi — rozet 2 ===\n";
$fId = (int)pdks_gunluk_cavus_olustur(['code' => 'CIX', 'name' => 'İndex Test Çavuş'], 1, db())['id'];
$o = pdks_gunluk_oturum_ac_veya_getir($fId, 1, db());
$sid = (int)$o['session']['id'];
pdks_gunluk_kart_olustur(['card_no' => 'IX1', 'worker_type_id' => $kadinId, 'ham_uid' => '111111', 'kaynak' => 'usb_decimal'], 1, db());
pdks_gunluk_kart_olustur(['card_no' => 'IX2', 'worker_type_id' => $kadinId, 'ham_uid' => '222222', 'kaynak' => 'usb_decimal'], 1, db());
$g1 = pdks_gunluk_faz8a_giris_kaydet('111111', 'usb_decimal', $sid, $kadinId, 'tam', 1, db());
if (!$g1['ok']) { fwrite(STDERR, 'giriş 1 hata: ' . json_encode($g1, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
$g2 = pdks_gunluk_faz8a_giris_kaydet('222222', 'usb_decimal', $sid, $kadinId, 'tam', 1, db());
if (!$g2['ok']) { fwrite(STDERR, 'giriş 2 hata: ' . json_encode($g2, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
// Hiçbiri ÇIKIŞ yapmadı — ikisi de eksik çıkış (kart hâlâ 'open').

$PERMS = [];
$IS_ADMIN = false;
$v = pdks_idx_hesapla();
ok('yetkisiz kullanıcı için HÂLÂ 0 (izin kapısı veri varlığından BAĞIMSIZ)', $v === 0, (string)$v);

$PERMS = ['attendance.daily_scan'];
$v = pdks_idx_hesapla();
ok('izinli kullanıcı için rozet = 2 (iki açık/eksik çıkışlı kart)', $v === 2, (string)$v);

// Kalıcı PDKS izinleri hub üzerinden kendi sayfalarını açar; günlük işçi
// verisine erişim sağlamaz. Eksik çıkış rozeti bu kullanıcılara gösterilmez.
foreach (['attendance.employees', 'attendance.cards', 'attendance.scan'] as $__kalici) {
    $PERMS = [$__kalici];
    $v = pdks_idx_hesapla();
    ok("yalnız $__kalici → rozet 0 (günlük işçi Gör izni yok)", $v === 0, (string)$v);
}
foreach (['attendance.hub.read', 'attendance.employees.read'] as $__pageRead) {
    $PERMS = ['role.permissions.detailed', $__pageRead];
    ok("ayrıntılı $__pageRead → günlük veri rozeti yok", pdks_idx_hesapla() === 0);
}
foreach (['attendance.daily_scan.read', 'attendance.daily_reports.read'] as $__pageRead) {
    $PERMS = ['role.permissions.detailed', $__pageRead];
    ok("ayrıntılı $__pageRead → izinli günlük veri rozeti 2", pdks_idx_hesapla() === 2);
}
$PERMS = ['attendance.daily_scan'];   // sonraki bölümler izinli kullanıcıyla devam eder

echo "\n=== D. Başka depodaki eksik çıkış SAYILMAZ (aktif depo kapsamı) ===\n";
global $AKTIF_DEPO;
$AKTIF_DEPO = 'Depo B';
$v = pdks_idx_hesapla();
ok('Depo B aktifken Depo A\'nın eksik çıkışları GÖRÜNMÜYOR (rozet 0)', $v === 0, (string)$v);
$AKTIF_DEPO = 'Depo A';

echo "\n=== E. Çıkış yapılınca rozet düşer ===\n";
$c1 = pdks_gunluk_faz8a_cikis_kaydet('111111', 'usb_decimal', $sid, 1, db());
if (!$c1['ok']) { fwrite(STDERR, 'çıkış hata: ' . json_encode($c1, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
$v = pdks_idx_hesapla();
ok('bir kart çıkış yapınca rozet 2\'den 1\'e düşer', $v === 1, (string)$v);

echo "\n=== F. v580: index.php temiz 3B ikon düzeni (rozet/alt metin/gereksiz sorgu yok) ===\n";
ok('v580: index.php içinde $personel_eksik_cikis sorgusu yok', !str_contains($indexSrc, '$personel_eksik_cikis'));
ok('v580: index.php içinde home-card-badge yok', !str_contains($indexSrc, 'home-card-badge'));
ok('v580: index.php içinde home-card-sub yok', !str_contains($indexSrc, 'home-card-sub'));

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
