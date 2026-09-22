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

// ── index.php'nin KENDİ kaynağından hesaplama bloğunu çıkar ──
$indexSrc = (string)file_get_contents($ROOT . '/index.php');
if (!preg_match(
    '/\$personel_eksik_cikis = 0;.*?\n\}\n/s',
    $indexSrc, $mBlok
)) {
    fwrite(STDERR, "index.php'de \$personel_eksik_cikis hesaplama bloğu bulunamadı — kod taşınmış/yeniden adlandırılmış olabilir.\n");
    exit(1);
}
// __DIR__ eval() içinde bu test dosyasının klasörüne çözülür (index.php'nin
// DEĞİL) — require_once yolu bu yüzden GERÇEK kök dizine sabitlenir. Kod
// SATIRLARI değişmiyor, yalnız bu TEK göreli referans çözülüyor.
$hesaplaBlok = str_replace('__DIR__', var_export($ROOT, true), $mBlok[0]);

function pdks_idx_hesapla(string $blok): int {
    global $personel_eksik_cikis;
    eval($blok);
    return $personel_eksik_cikis;
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
$v = pdks_idx_hesapla($hesaplaBlok);
ok('hiçbir attendance.* izni/yönetici YOKKEN $personel_eksik_cikis = 0', $v === 0, (string)$v);

echo "\n=== B. İzinli kullanıcı, EKSİK ÇIKIŞ YOK — rozet 0 ===\n";
$PERMS = ['attendance.daily_reports'];
$v = pdks_idx_hesapla($hesaplaBlok);
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
$v = pdks_idx_hesapla($hesaplaBlok);
ok('yetkisiz kullanıcı için HÂLÂ 0 (izin kapısı veri varlığından BAĞIMSIZ)', $v === 0, (string)$v);

$PERMS = ['attendance.daily_scan'];
$v = pdks_idx_hesapla($hesaplaBlok);
ok('izinli kullanıcı için rozet = 2 (iki açık/eksik çıkışlı kart)', $v === 2, (string)$v);

echo "\n=== D. Başka depodaki eksik çıkış SAYILMAZ (aktif depo kapsamı) ===\n";
global $AKTIF_DEPO;
$AKTIF_DEPO = 'Depo B';
$v = pdks_idx_hesapla($hesaplaBlok);
ok('Depo B aktifken Depo A\'nın eksik çıkışları GÖRÜNMÜYOR (rozet 0)', $v === 0, (string)$v);
$AKTIF_DEPO = 'Depo A';

echo "\n=== E. Çıkış yapılınca rozet düşer ===\n";
$c1 = pdks_gunluk_faz8a_cikis_kaydet('111111', 'usb_decimal', $sid, 1, db());
if (!$c1['ok']) { fwrite(STDERR, 'çıkış hata: ' . json_encode($c1, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
$v = pdks_idx_hesapla($hesaplaBlok);
ok('bir kart çıkış yapınca rozet 2\'den 1\'e düşer', $v === 1, (string)$v);

echo "\n=== F. index.php içindeki rozet MARKUP'ı doğru yapılandırılmış ===\n";
ok('badge markup $personel_eksik_cikis değişkenini kullanıyor', str_contains($indexSrc, 'if ($personel_eksik_cikis > 0)'));
ok('badge (int) ile yazdırılıyor (XSS/tip güvenliği)', str_contains($indexSrc, '<?= (int)$personel_eksik_cikis ?>'));
ok('badge home-card-badge sınıfını kullanıyor (mevcut kart rozeti deseniyle AYNI)',
    (bool)preg_match('/if \(\$personel_eksik_cikis > 0\): \?>\s*<div class="home-card-badge"/', $indexSrc));

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
