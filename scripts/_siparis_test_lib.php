<?php
// =========================================================
// scripts/_siparis_test_lib.php — Siparişler modülü testlerinin ORTAK harness'i
// (include-only; CLI dışında çalışmaz). Bellek içi SQLite + auth stub'ları +
// GERÇEK config/helpers.php ve config/siparis_*.php. Canlı DB'ye ve
// storage/siparis/'e HİÇ dokunmaz (SIPARIS_DEPO_DIR geçici klasör).
// _mail_test_lib.php deseni. Her test dosyası bunu require eder (tek süreç = tek dosya).
//
// Gerçek örnek belgeler (kişisel veri içerir, repoda YOK): SIPARIS_ORNEK_DIR ortam
// değişkeni verilirse o klasör özyinelemeli taranır; verilmezse ilgili testler ATLANIR.
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Europe/Istanbul');   // config/db.php ile aynı

$ROOT = dirname(__DIR__);
ini_set('log_errors', '1');
ini_set('error_log', sys_get_temp_dir() . '/siparis_test_' . getmypid() . '.log');

// Geçici belge klasörü — require'dan ÖNCE (siparis_core.php defined() korumalı).
$SIPARIS_TEST_DIR = sys_get_temp_dir() . '/siparis_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
@mkdir($SIPARIS_TEST_DIR, 0700, true);
define('SIPARIS_DEPO_DIR', $SIPARIS_TEST_DIR . '/depo/');
register_shutdown_function(function () use ($SIPARIS_TEST_DIR): void {
    if (getenv('SIPARIS_TEST_SAKLA')) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($SIPARIS_TEST_DIR, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($SIPARIS_TEST_DIR);
});

// SIPARIS_TEST_MYSQL_DSN verilirse (dbname 'test' içermeli; tablolar SİLİNİP yeniden kurulur) gerçek MySQL; yoksa SQLite.
$SIPARIS_TEST_MYSQL = (string)(getenv('SIPARIS_TEST_MYSQL_DSN') ?: '');
if ($SIPARIS_TEST_MYSQL !== '') {
    if (!preg_match('/dbname=[a-z0-9_]*test[a-z0-9_]*/i', $SIPARIS_TEST_MYSQL)) { fwrite(STDERR, "Güvenlik: SIPARIS_TEST_MYSQL_DSN veritabanı adı 'test' içermeli.\n"); exit(2); }
    $PDO_TEST = new PDO($SIPARIS_TEST_MYSQL, (string)getenv('SIPARIS_TEST_MYSQL_USER'), (string)getenv('SIPARIS_TEST_MYSQL_PASS'));
    $PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $PDO_TEST->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);   // üretimle (config/db.php) aynı
    $PDO_TEST->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
} else {
    $PDO_TEST = new PDO('sqlite::memory:');
    $PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
}
function siparis_test_mysql(): bool { global $SIPARIS_TEST_MYSQL; return $SIPARIS_TEST_MYSQL !== ''; }
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

// ── auth stub'ları ($PERMS / $IS_ADMIN / $UID / $DEPOLAR / $AKTIF_DEPO ile yönetilir) ──
$PERMS = []; $IS_ADMIN = false; $UID = 1;
$DEPOLAR = null;            // null = kısıtsız; dizi = izinli depolar (aktif depo kuralı)
$AKTIF_DEPO = 'DEPO1';
function current_user(): ?array { global $UID; return $UID ? ['id' => $UID, 'username' => 'u' . $UID, 'display_name' => 'Kullanıcı ' . $UID] : null; }
function can(string $p): bool { global $PERMS; return in_array($p, $PERMS, true); }
function is_admin(): bool { global $IS_ADMIN; return $IS_ADMIN; }
function require_login(): array { return current_user(); }
function forbidden($m = ''): void { throw new RuntimeException('forbidden: ' . $m); }
function active_depot(): ?string { global $AKTIF_DEPO; return $AKTIF_DEPO; }
function enforce_active_depot(): void {}
function depot_options(): array { global $AKTIF_DEPO; return [$AKTIF_DEPO]; }
function user_allowed_depots(): ?array { global $DEPOLAR; return $DEPOLAR; }
function depo_fold(string $s): string { return mb_strtolower(trim(str_replace(['İ', 'I'], ['i', 'ı'], $s)), 'UTF-8'); }
function depot_visible_to_user(?string $depo): bool {
    $allowed = user_allowed_depots();
    if ($allowed === null) return true;
    if ($depo === null || trim($depo) === '') return true;
    foreach ($allowed as $a) if (depo_fold((string)$a) === depo_fold($depo)) return true;
    return false;
}
function depo_sql_column(string $col, string $prefix = ':udc'): array {
    $allowed = user_allowed_depots();
    if ($allowed === null || count($allowed) === 0) return ['', []];
    $names = []; $params = [];
    foreach (array_values($allowed) as $i => $d) { $k = $prefix . $i; $names[] = $k; $params[$k] = $d; }
    return [" AND ($col IN (" . implode(',', $names) . ") OR TRIM(COALESCE($col,'')) = '') ", $params];
}
function depo_sql_in(string $col): array {
    $allowed = user_allowed_depots();
    if ($allowed === null || count($allowed) === 0) return ['', []];
    return ["($col IN (" . implode(',', array_fill(0, count($allowed), '?')) . ") OR TRIM(COALESCE($col,'')) = '')", array_values($allowed)];
}
function depo_sql_records(string $a = 'r'): array { return ['', []]; }
function depo_sql_records_in(string $a = 'r'): array { return ['', []]; }
function user_primary_role(): ?array { return ['slug' => 'operator', 'label' => 'Operatör']; }

require_once $ROOT . '/config/helpers.php';
require_once $ROOT . '/config/siparis_core.php';
require_once $ROOT . '/config/siparis_pdf.php';
require_once $ROOT . '/config/siparis_eslestir.php';
require_once $ROOT . '/config/siparis_pkl.php';
require_once $ROOT . '/config/siparis_beyan.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $v, string $detay = ''): void {
    global $pass, $fail;
    if ($v) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detay !== '' ? " :: $detay" : '') . "\n"; }
}
function siparis_test_bitir(): never {
    global $pass, $fail;
    echo "\n$pass geçti, $fail kaldı\n";
    exit($fail > 0 ? 1 : 0);
}

// ── MySQL DDL → SQLite çevirici (mail_ddl_sqlite kopyası) ──
function siparis_ddl_sqlite(string $mysql): array
{
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m)) {
        throw new RuntimeException('DDL ayrıştırılamadı');
    }
    $tablo = $m[1]; $govde = $m[2];
    $parcalar = []; $buf = ''; $d = 0;
    for ($i = 0, $n = strlen($govde); $i < $n; $i++) {
        $c = $govde[$i];
        if ($c === '(') $d++;
        if ($c === ')') $d--;
        if ($c === ',' && $d === 0) { $parcalar[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $parcalar[] = trim($buf);
    $kolonlar = []; $indeksler = [];
    foreach ($parcalar as $p) {
        $p = preg_replace('/\s+/', ' ', $p);
        if (preg_match('/^UNIQUE KEY `([^`]+)` \((.+)\)$/i', $p, $mm)) { $indeksler[] = "CREATE UNIQUE INDEX `{$mm[1]}` ON `{$tablo}` ({$mm[2]})"; continue; }
        if (preg_match('/^(?:INDEX|KEY) `([^`]+)` \((.+)\)$/i', $p, $mm)) { $indeksler[] = "CREATE INDEX `{$mm[1]}` ON `{$tablo}` ({$mm[2]})"; continue; }
        if (preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY/i', $p)) continue;
        $p = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
        $kolonlar[] = $p;
    }
    return ["CREATE TABLE `{$tablo}` (\n  " . implode(",\n  ", $kolonlar) . "\n)", $indeksler];
}

/** siparis_tablolar() şemasını test veritabanına kurar (MySQL kipinde önce siler). */
function siparis_test_sema_kur(PDO $db): void
{
    if (siparis_test_mysql()) {
        foreach (array_keys(siparis_tablolar()) as $t) $db->exec("DROP TABLE IF EXISTS `$t`");
        foreach (siparis_tablolar() as $sql) $db->exec($sql);
        return;
    }
    foreach (siparis_tablolar() as $sql) {
        [$create, $ix] = siparis_ddl_sqlite($sql);
        $db->exec($create);
        foreach ($ix as $x) $db->exec($x);
    }
}

/** users / audit_log / customs_declarations (beyan önerisi testleri için en küçük kolon seti). */
function siparis_test_diger_tablolar(PDO $db): void
{
    if (siparis_test_mysql()) {
        foreach (['audit_log', 'users', 'customs_declarations'] as $t) $db->exec("DROP TABLE IF EXISTS `$t`");
        $db->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(100), display_name VARCHAR(150), is_active TINYINT(1) DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE audit_log (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, action VARCHAR(100), module VARCHAR(100), record_id INT, old_values MEDIUMTEXT, new_values MEDIUMTEXT, ip VARCHAR(64), user_agent VARCHAR(255), created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE customs_declarations (id INT AUTO_INCREMENT PRIMARY KEY, status VARCHAR(50), party_no VARCHAR(100), vehicle_plate VARCHAR(30), tractor_plate VARCHAR(20), net_kg DECIMAL(12,3), pallet_count INT, product_name VARCHAR(150), buyer_name VARCHAR(150), contact_person VARCHAR(150), created_at DATETIME, deleted_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, display_name TEXT, is_active INT DEFAULT 1)");
        $db->exec("CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, action TEXT, module TEXT, record_id INT, old_values TEXT, new_values TEXT, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE TABLE customs_declarations (id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT, party_no TEXT, vehicle_plate TEXT, tractor_plate TEXT, net_kg REAL, pallet_count INT, product_name TEXT, buyer_name TEXT, contact_person TEXT, created_at TEXT, deleted_at TEXT NULL)");
    }
    $db->exec("INSERT INTO users (username, display_name) VALUES ('u1','Kullanıcı 1'),('u2','Kullanıcı 2'),('u3','Kullanıcı 3')");
}

/** Geçici dosya + $_FILES biçiminde dizi. */
function siparis_test_dosya(string $icerik, string $ad): array
{
    global $SIPARIS_TEST_DIR;
    @mkdir($SIPARIS_TEST_DIR . '/up', 0700, true);
    $tmp = $SIPARIS_TEST_DIR . '/up/' . bin2hex(random_bytes(6));
    file_put_contents($tmp, $icerik);
    return ['name' => $ad, 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($icerik)];
}

/** Var olan bir dosyayı yükleme dizisine çevirir (kopyalayarak; kaynak değişmez). */
function siparis_test_dosya_yol(string $yol, ?string $ad = null): array
{
    return siparis_test_dosya((string)file_get_contents($yol), $ad ?? basename($yol));
}

/** Sihirli baytı doğru, içeriği etikete göre benzersiz (sha256 farklı) en küçük "PDF". */
function siparis_test_pdf(string $etiket): string
{
    return "%PDF-1.4\n% siparis test " . $etiket . "\n%%EOF\n";
}

/** test mover'ı: move_uploaded_file CLI'de çalışmaz. */
function siparis_test_mover(): callable { return fn(string $a, string $b): bool => copy($a, $b); }

/** scripts/fixtures/siparis/<ad>.json (SENTETİK ayrıştırıcı çıktısı). */
function siparis_test_fixture(string $ad): array
{
    global $ROOT;
    $j = json_decode((string)file_get_contents($ROOT . '/scripts/fixtures/siparis/' . $ad . '.json'), true);
    if (!is_array($j)) throw new RuntimeException('fixture okunamadı: ' . $ad);
    unset($j['_aciklama']);
    return $j;
}

/**
 * Sentetik ayrıştırıcı çıktısını gerçek akıştan geçirir: benzersiz küçük PDF kaydedilir
 * (copy mover), test kancası ($GLOBALS['_siparis_parse_override']) $doc'u döndürür, belge işlenir.
 * @return array{kayit:array, isle:array}
 */
function siparis_test_isle(PDO $db, array $doc, string $etiket, int $uid = 1, ?int $hedef = null, string $depo = 'DEPO1'): array
{
    $k = siparis_dosya_kaydet($db, siparis_test_dosya(siparis_test_pdf($etiket), $etiket . '.pdf'), $uid, $depo, siparis_test_mover());
    if (!$k['ok']) return ['kayit' => $k, 'isle' => ['ok' => false, 'hata' => $k['hata'] ?? '']];
    $GLOBALS['_siparis_parse_override'] = fn(string $yol): array => $doc;
    try {
        $i = siparis_belge_isle($db, (int)$k['belge_id'], $uid, $hedef);
    } finally {
        unset($GLOBALS['_siparis_parse_override']);
    }
    return ['kayit' => $k, 'isle' => $i];
}

/** Tablo satır sayısı. */
function siparis_test_say(PDO $db, string $tablo, string $where = '1=1', array $par = []): int
{
    $st = $db->prepare("SELECT COUNT(*) FROM `$tablo` WHERE $where");
    $st->execute($par);
    return (int)$st->fetchColumn();
}

/** order_ref ile sevkiyat (siparis_sevkiyat() biçiminde). */
function siparis_test_sev(PDO $db, string $ref): ?array
{
    $st = $db->prepare('SELECT id FROM siparis_sevkiyatlar WHERE order_ref = ?');
    $st->execute([$ref]);
    $id = $st->fetchColumn();
    return $id === false ? null : siparis_sevkiyat($db, (int)$id);
}

/** Modül 'siparis' audit satırları. */
function siparis_test_audit(PDO $db): array
{
    return $db->query("SELECT * FROM audit_log WHERE module = 'siparis' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Gerçek örnek belgeleri bulur (SIPARIS_ORNEK_DIR, özyinelemeli). İçerikten sınıflanır; set,
 * bulunan order_ref'lerden tanınır (TC426865.10 → 1, TC418910.10 → 2). PKL Excel'i yalnız
 * müşteri adlandırmasıyla ("PKL-<ref>-…") aday olur (üretilmiş kopyalar karışmasın).
 * @return array<string,array<string,string>>|null  ['1'=>['po'=>yol,'ll'=>..,'pc'=>..,'pkl'=>..], '2'=>...]
 */
function siparis_test_ornekler(): ?array
{
    $dir = (string)(getenv('SIPARIS_ORNEK_DIR') ?: '');
    if ($dir === '' || !is_dir($dir)) return null;
    $setler = ['TC426865.10' => '1', 'TC418910.10' => '2', 'TC426877.10' => '3'];   // set 3: tek LL'de iki tır (v374)
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    $dosyalar = [];
    foreach ($it as $f) if ($f->isFile()) $dosyalar[] = $f->getPathname();
    sort($dosyalar);
    foreach ($dosyalar as $yol) {
        $ext = strtolower(pathinfo($yol, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            $d = siparis_pdf_isle($yol);
            $tur = match ($d['doc_type'] ?? '') { 'purchase_order' => 'po', 'loading_list' => 'll', 'price_confirmation' => 'pc', default => null };
            if ($tur === null) continue;
            $refs = array_merge(array_column((array)($d['loadings'] ?? []), 'order_ref'), array_column((array)($d['shipments'] ?? []), 'order_ref'), array_column((array)($d['rows'] ?? []), 'order_ref'));
        } elseif ($ext === 'xlsx' && preg_match('/PKL-([A-Z]{1,3}\d{5,}\.\d{1,3})-/', basename($yol))) {
            $p = siparis_pkl_oku($yol);
            if (empty($p['pkl'])) continue;
            $tur = 'pkl'; $refs = $p['order_refs'];
        } else continue;
        foreach ($setler as $ref => $set) {
            if (!in_array($ref, $refs, true)) continue;
            if ($tur === 'pc' && $set === '2' && in_array('TC426865.10', $refs, true)) continue;
            if (!isset($out[$set][$tur])) $out[$set][$tur] = $yol;
        }
    }
    return $out;
}
