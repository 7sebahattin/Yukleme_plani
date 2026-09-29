<?php
// =========================================================
// scripts/db_backup_smoke.php — Veritabanı Yedekleri testi (Sprint DB-Backup-02)
//
// SADECE CLI. Canlı veritabanına ve storage/backups/'a HİÇ dokunmaz:
// bellek içi SQLite (MySQL SHOW komutlarını taklit eden FakePDO) +
// DB_BACKUP_DIR_OVERRIDE ile geçici klasör + DB_BACKUP_DISABLE_MYSQLDUMP
// (PDO fallback yolu gerçekten çalışır).
//
//   php scripts/db_backup_smoke.php    → çıkış kodu 0 = tüm testler geçti
//
// Kapsam: [A] sw.js bypass + CACHE_NAME=APP_SURUM · [B] depolama (ad,
// 0600, .htaccess) · [C] doğrulama (.part→doğrula→rename) · [D] kilit ·
// [E] option dosyası tırnağı · [F] PDO döküm round-trip · [G] otomatik
// deneme freni + çöküş izi · [H] saklama · [I] tombstone · [J] ekran ·
// [K] indirme başlıkları · [O] tekil silme.
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Europe/Istanbul');

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$ROOT = dirname(__DIR__);
$TMP  = sys_get_temp_dir() . '/asya_bkp_smoke_' . getmypid() . '_' . bin2hex(random_bytes(3));
@mkdir($TMP, 0700, true);
define('DB_BACKUP_DIR_OVERRIDE', $TMP . '/db');
define('DB_BACKUP_DISABLE_MYSQLDUMP', true);
const DB_HOST = 'localhost'; const DB_NAME = 'test_db'; const DB_USER = 'u'; const DB_PASS = 'p#w';

// PHP uyarılarını say (ekran testinde "uyarı 0" için)
$UYARILAR = [];
set_error_handler(static function (int $no, string $msg, string $file, int $line) use (&$UYARILAR): bool {
    $UYARILAR[] = "$msg @ " . basename($file) . ":$line";
    return true;
});

// ── FakePDO: SQLite üstünde MySQL'in SHOW FULL TABLES / SHOW CREATE TABLE'ı ──
class FakePDO extends PDO {
    public bool $bozuk = false;      // true → SHOW FULL TABLES hata verir (başarısız yedek)
    public bool $olum  = false;      // true → döküm sırasında süreç ölür (exit — finally çalışmaz)
    public function exec(string $s): int|false {
        if (str_contains($s, 'ENGINE=InnoDB')) return 0;   // MySQL DDL — tablo elle kurulur
        return parent::exec($s);
    }
    public function query(string $q, ?int $m = null, mixed ...$a): PDOStatement|false {
        if ($q === 'SHOW FULL TABLES') {
            if ($this->bozuk) throw new PDOException('test: tablo listesi alınamadı');
            if ($this->olum) exit(3);
            return parent::query("SELECT name AS Tables_in_test,
                    CASE type WHEN 'view' THEN 'VIEW' ELSE 'BASE TABLE' END AS Table_type
                FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' ORDER BY name");
        }
        if (preg_match('/^SHOW CREATE TABLE `(.+)`$/', $q, $mm)) {
            $t = str_replace('``', '`', $mm[1]);
            $r = parent::query("SELECT sql FROM sqlite_master WHERE name=" . $this->quote($t))->fetch(PDO::FETCH_ASSOC);
            return parent::query("SELECT " . $this->quote($t) . " AS `Table`, " . $this->quote((string)$r['sql']) . " AS `Create Table`");
        }
        return $m === null ? parent::query($q) : parent::query($q, $m, ...$a);
    }
}
function yeni_pdo(): FakePDO {
    $p = new FakePDO('sqlite::memory:');
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $p->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, display_name TEXT)");
    $p->exec("INSERT INTO users VALUES (1, 'Test Admin')");
    $p->exec("CREATE TABLE database_backups (id INTEGER PRIMARY KEY AUTOINCREMENT, backup_date TEXT NOT NULL,
        filename TEXT NOT NULL, file_path TEXT NOT NULL, file_size INT, method TEXT, status TEXT NOT NULL DEFAULT 'success',
        error_message TEXT, created_by INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, downloaded_at TEXT, downloaded_by INT)");
    return $p;
}
$PDO_TEST = yeni_pdo();
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

// ── Stub'lar ─────────────────────────────────────────────────────────
$AUDIT = [];
function audit_log_event(string $a, string $m, ?int $id = null, ?array $o = null, ?array $n = null): void {
    global $AUDIT; $AUDIT[] = ['action' => $a, 'module' => $m, 'id' => $id, 'old' => $o, 'new' => $n];
}
function current_user(): ?array { return ['id' => 1, 'display_name' => 'Test Admin']; }
function require_login(): array { return current_user(); }
function is_admin(): bool { return true; }
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return 'testcsrf'; }
function csrf_check($t): void {}
function forbidden($m = ''): void { throw new RuntimeException('forbidden'); }
$FLASH = [];
function set_flash(string $t, string $m): void { global $FLASH; $FLASH[] = [$t, $m]; }
function render_flash(): void {}
function render_header(string $t, bool $p = false): void { echo "<!doctype html><html><body><main>"; }
function render_footer(bool $p = false): void { echo "</main></body></html>"; }
function fmt_datetime(?string $d): string { if (!$d) return ''; $ts = strtotime($d); return $ts ? date('d.m.Y H:i', $ts) : h($d); }

require $ROOT . '/config/db_backup_helpers.php';

$ok_n = 0; $fail_n = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $ok_n, $fail_n;
    $c ? $ok_n++ : $fail_n++;
    printf("%-72s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : "  → $ipucu");
}
function satir_sayisi(): int { return (int)db()->query("SELECT COUNT(*) FROM database_backups")->fetchColumn(); }
function rrmdir(string $d): void {
    foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (in_array(basename($f), ['.', '..'], true)) continue;
        is_dir($f) ? rrmdir($f) : @unlink($f);
    }
    @rmdir($d);
}
$DIR = DB_BACKUP_DIR_OVERRIDE;

// ─────────────────────────────────────────────────────────────────────
echo "── [A] sw.js\n";
$sw = (string)file_get_contents($ROOT . '/sw.js');
$hp = (string)file_get_contents($ROOT . '/config/helpers.php');
preg_match("/CACHE_NAME = 'yukleme-plani-v(\d+)'/", $sw, $m1);
preg_match("/define\('APP_SURUM', 'v(\d+)'\)/", $hp, $m2);
ok('CACHE_NAME sayısı == APP_SURUM', isset($m1[1], $m2[1]) && $m1[1] === $m2[1], ($m1[1] ?? '?') . ' / ' . ($m2[1] ?? '?'));
ok('fetch: admin_db_backup yolu SW dışında (bypass)', str_contains($sw, "indexOf('admin_db_backup') !== -1"));
ok("fetch: action=download SW dışında", str_contains($sw, "searchParams.get('action') === 'download'"));
ok('cache.put guard: !ok / basic / attachment', str_contains($sw, '!response.ok') && str_contains($sw, "response.type !== 'basic'") && str_contains($sw, '/attachment/i'));
$bypass_pos = strpos($sw, "indexOf('admin_db_backup')");
ok('bypass respondWith\'den ÖNCE', $bypass_pos !== false && $bypass_pos < strpos($sw, 'e.respondWith('));
ok('nav-icons ignoreSearch yedeği korunuyor', str_contains($sw, 'ignoreSearch: true'));

// ─────────────────────────────────────────────────────────────────────
echo "── [B] depolama\n";
@mkdir($DIR, 0755, true);
file_put_contents($DIR . '/.htaccess', "Deny from all\n");  // eski tek sözdizimi
db()->exec("CREATE TABLE musteri (id INTEGER PRIMARY KEY, ad TEXT, notlar TEXT)");
$r1 = create_database_backup(db(), 1, 'manual_admin');
$r2 = create_database_backup(db(), 1, 'manual_admin');
ok('yedek 1 başarılı', $r1['ok'] === true, (string)$r1['error']);
ok('dosya adı rastgele ekli', (bool)preg_match('/^db_backup_\d{8}_\d{6}_[0-9a-f]{16}\.sql\.gz$/', $r1['filename']), $r1['filename']);
ok('ardışık iki yedek FARKLI dosya', $r1['filename'] !== $r2['filename'] && is_file($r1['file_path']) && is_file($r2['file_path']));
ok('dosya izni 0600', is_file($r1['file_path']) && (fileperms($r1['file_path']) & 0777) === 0600, decoct(@fileperms($r1['file_path']) & 0777));
ok('klasör izni 0750', (fileperms($DIR) & 0777) === 0750, decoct(fileperms($DIR) & 0777));
$ht = (string)file_get_contents($DIR . '/.htaccess');
ok('.htaccess yeniden yazıldı (Require all denied + 2.2 bloğu)', str_contains($ht, 'Require all denied') && str_contains($ht, '<IfModule !mod_authz_core.c>') && str_contains($ht, 'Deny from all'));
foreach (['storage/.htaccess', 'storage/backups/.htaccess', 'storage/backups/db/.htaccess'] as $f) {
    $c = (string)file_get_contents($ROOT . '/' . $f);
    ok("repo $f çift sözdizimi", $c === _bh_htaccess_icerik(), 'helper içeriğiyle birebir olmalı');
}
ok('_bh_backup_path basename kullanır', _bh_backup_path('../../etc/passwd') === $DIR . '/passwd');
ok('dönüş anahtarları korunuyor', array_diff(['ok', 'file_ok', 'db_ok', 'id', 'filename', 'file_path', 'size', 'method', 'error', 'db_error'], array_keys($r1)) === []);
ok('ek anahtarlar busy/note var', array_key_exists('busy', $r1) && array_key_exists('note', $r1));

// ─────────────────────────────────────────────────────────────────────
echo "── [C] bütünlük\n";
$gzw = static function (string $path, string $body): void { $g = gzopen($path, 'wb6'); gzwrite($g, $body); gzclose($g); };
$govde = '';
for ($i = 0; $i < 4000; $i++) $govde .= "INSERT INTO t VALUES ($i, '" . md5((string)$i) . "');\n";
$gzw("$TMP/saglam.sql.gz", $govde . "-- Dump completed on 2026-09-29 17:00:00\n");
ok('sağlam gz → ok', _bh_verify_backup("$TMP/saglam.sql.gz")['ok'] === true);
$raw = (string)file_get_contents("$TMP/saglam.sql.gz");
file_put_contents("$TMP/kesik.sql.gz", substr($raw, 0, (int)(strlen($raw) / 2)));
$vk = _bh_verify_backup("$TMP/kesik.sql.gz");
ok('kesik gz → fail', $vk['ok'] === false, json_encode($vk));
file_put_contents("$TMP/kuyruksuz.sql.gz", substr($raw, 0, strlen($raw) - 4));
ok('gzip kuyruğu (CRC/boy) eksik → fail', _bh_verify_backup("$TMP/kuyruksuz.sql.gz")['ok'] === false);
$gzw("$TMP/footersiz.sql.gz", $govde);
$vf = _bh_verify_backup("$TMP/footersiz.sql.gz");
ok('footer yok → fail ("footer yok")', $vf['ok'] === false && str_contains((string)$vf['error'], 'footer yok'), json_encode($vf));
file_put_contents("$TMP/duz.sql", "SELECT 1;\n-- Dump completed on 2026-09-29\n");
ok('footer\'lı düz .sql → ok', _bh_verify_backup("$TMP/duz.sql")['ok'] === true);
file_put_contents("$TMP/bos.sql.gz", '');
ok('boş dosya → fail', _bh_verify_backup("$TMP/bos.sql.gz")['ok'] === false);
ok('.part dosyası kalmadı', glob($DIR . '/*.part') === []);
ok('yedek kendi doğrulamasından geçiyor', _bh_verify_backup($r1['file_path'])['ok'] === true);

$once = satir_sayisi();
db()->bozuk = true;
$rf = create_database_backup(db(), 1, 'manual_admin');
db()->bozuk = false;
$fr = db()->query("SELECT * FROM database_backups ORDER BY id DESC LIMIT 1")->fetch();
ok('döküm hatası → ok=false', $rf['ok'] === false && $rf['file_ok'] === false);
ok('döküm hatası → failed satırı + error_message', $fr && $fr['status'] === 'failed' && str_contains((string)$fr['error_message'], 'tablo listesi alınamadı'), json_encode($fr));
ok('döküm hatası → dosya/.part kalmadı', !is_file($rf['file_path']) && glob($DIR . '/*.part') === []);
ok('döküm hatası → satır sayısı +1', satir_sayisi() === $once + 1);

// ─────────────────────────────────────────────────────────────────────
echo "── [D] kilit\n";
$kilit = _bh_lock_acquire();
ok('kilit alındı', is_resource($kilit));
$once   = satir_sayisi();
$aud_on = count($AUDIT);
$rb = create_database_backup(db(), 1, 'manual_admin');
ok('kilit tutulurken → busy', $rb['busy'] === true && $rb['ok'] === false, json_encode($rb));
ok('busy → DB satırı yazılmadı', satir_sayisi() === $once);
ok('busy → audit yazılmadı', count($AUDIT) === $aud_on);
_bh_lock_release($kilit);
$rs = create_database_backup(db(), 1, 'manual_admin', true);
ok('skipIfDoneToday + bugün yedek var → skipped, satır yok', $rs['skipped'] === true && satir_sayisi() === $once);

// ─────────────────────────────────────────────────────────────────────
echo "── [E] mysqldump seçenek dosyası\n";
ok('_bh_cnf_value tırnak + kaçış', _bh_cnf_value('a#b"c\\d ') === '"a#b\\"c\\\\d "', _bh_cnf_value('a#b"c\\d '));
$hs = (string)file_get_contents($ROOT . '/config/db_backup_helpers.php');
ok('bayraklar: --no-tablespaces --default-character-set=utf8mb4', str_contains($hs, '--no-tablespaces --default-character-set=utf8mb4'));
ok('stderr yakalanıyor (2> dosya)', str_contains($hs, "' 2>' . escapeshellarg(\$err_file)"));
ok('--compact / --skip-comments YOK', !str_contains($hs, "--compact'") && !preg_match("/' --[a-z-]*skip-comments/", $hs));
ok('$err_msg = null sıfırlaması kaldırıldı', substr_count($hs, '$err_msg = null') <= 1);
ok('DISABLE sabiti mysqldump\'ı kapatıyor', _bh_can_mysqldump() === false);

// ─────────────────────────────────────────────────────────────────────
echo "── [F] PDO döküm round-trip\n";
db()->exec("DELETE FROM musteri");
db()->exec("CREATE TABLE hareket (id INTEGER PRIMARY KEY, musteri_id INT, aciklama TEXT, kg INT)");
$zor = [
    [1, null, 'boş ad'],
    [2, "O'Brien", "tek ' tırnak"],
    [3, 'C:\\yol\\dosya', "ters \\ bölü \\\\ çift"],
    [4, 'Şırnak Iğdır ÇÖÜ ğış', "çok\nsatır;\n-- yorum gibi"],
    [5, 'emoji 😀🍅', ''],
];
$ins = db()->prepare("INSERT INTO musteri (id, ad, notlar) VALUES (?, ?, ?)");
foreach ($zor as $z) $ins->execute($z);
$ih = db()->prepare("INSERT INTO hareket (id, musteri_id, aciklama, kg) VALUES (?, ?, ?, ?)");
db()->beginTransaction();
for ($i = 1; $i <= 1200; $i++) $ih->execute([$i, ($i % 5) + 1, "satır $i — 'x' \\ ğ", $i * 7]);
db()->commit();
db()->exec("CREATE VIEW v_ozet AS SELECT musteri_id, SUM(kg) AS t FROM hareket GROUP BY musteri_id");

$rr = create_database_backup(db(), 1, 'manual_admin');
ok('round-trip yedeği başarılı', $rr['ok'] === true, (string)$rr['error']);
ok('yöntem pdo_fallback+gzopen', $rr['method'] === 'pdo_fallback+gzopen', $rr['method']);
ok('not: view atlandı', str_contains((string)$rr['note'], '1 view atlandı'), (string)$rr['note']);
$sql = (string)gzdecode((string)file_get_contents($rr['file_path']));
ok('footer "-- Dump completed ... (pdo_fallback)"', (bool)preg_match('/-- Dump completed on \d{4}-\d\d-\d\d \d\d:\d\d:\d\d \(pdo_fallback\)\n$/', $sql));
ok('view yorumla atlandı', str_contains($sql, '-- VIEW atlandı: `v_ozet`') && !str_contains($sql, 'CREATE VIEW'));
ok('çok satırlı INSERT (1200 satır → 3 ifade)', substr_count($sql, 'INSERT INTO `hareket`') === 3, (string)substr_count($sql, 'INSERT INTO `hareket`'));
ok('başlık SET satırları', str_contains($sql, "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET NAMES utf8mb4;"));
ok('geçici /tmp/asya_sql_* yolu yok', !str_contains($hs, 'asya_sql_'));

$hedef = new PDO('sqlite::memory:');
$hedef->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$yukle = (string)preg_replace('/^SET [^\n]*;\n/m', '', $sql);   // MySQL oturum ayarları SQLite'ta yok
$yerr = null;
try { $hedef->exec($yukle); } catch (Throwable $e) { $yerr = $e->getMessage(); }
ok('döküm yeni veritabanına yüklendi', $yerr === null, (string)$yerr);
$say = static fn(PDO $p, string $t): int => (int)$p->query("SELECT COUNT(*) FROM $t")->fetchColumn();
ok('musteri satır sayısı aynı', $yerr === null && $say($hedef, 'musteri') === 5);
ok('hareket satır sayısı aynı (1200)', $yerr === null && $say($hedef, 'hareket') === 1200);
$kaynak = db()->query("SELECT id, ad, notlar FROM musteri ORDER BY id")->fetchAll(PDO::FETCH_NUM);
$geri   = $yerr === null ? $hedef->query("SELECT id, ad, notlar FROM musteri ORDER BY id")->fetchAll(PDO::FETCH_NUM) : [];
ok('değerler birebir (NULL, \', \\, Türkçe, emoji, çok satır)', $kaynak == $geri && $geri[0][1] === null, json_encode($geri, JSON_UNESCAPED_UNICODE));
$h1 = db()->query("SELECT group_concat(id || '|' || aciklama || '|' || kg, '#') FROM (SELECT * FROM hareket ORDER BY id)")->fetchColumn();
$h2 = $yerr === null ? $hedef->query("SELECT group_concat(id || '|' || aciklama || '|' || kg, '#') FROM (SELECT * FROM hareket ORDER BY id)")->fetchColumn() : '';
ok('hareket içeriği birebir', $h1 === $h2);
ok('view hedefte yok', $yerr === null && (int)$hedef->query("SELECT COUNT(*) FROM sqlite_master WHERE type='view'")->fetchColumn() === 0);
$son = db()->query("SELECT error_message FROM database_backups WHERE id=" . (int)$rr['id'])->fetchColumn();
ok('başarılı satırda not (error_message) yazılı', str_contains((string)$son, 'view atlandı'), (string)$son);
db()->exec("DROP VIEW v_ozet");

// ─────────────────────────────────────────────────────────────────────
echo "── [G] otomatik deneme freni\n";
db()->exec("DELETE FROM database_backups");
@unlink(_bh_state_path());
$bugun = date('Y-m-d');
$t18 = mktime(18, 0, 0);
$t16 = mktime(16, 0, 0);
ok('16:00 → null', create_daily_backup_if_needed(db(), 1, $t16) === null);
_bh_state_write(['date' => $bugun, 'attempts' => 3, 'last_ts' => $t18 - 7200]);
ok('18:00 + bugün 3 deneme → null', create_daily_backup_if_needed(db(), 1, $t18) === null);
_bh_state_write(['date' => '2000-01-01', 'attempts' => 0, 'last_ts' => $t18 - 600]);
ok('18:00 + son deneme 10 dk önce → null', create_daily_backup_if_needed(db(), 1, $t18) === null);
@unlink(_bh_state_path());
$fi = db()->prepare("INSERT INTO database_backups (backup_date, filename, file_path, status, error_message) VALUES (?, 'f', 'f', 'failed', 'x')");
for ($i = 0; $i < 3; $i++) $fi->execute([$bugun]);
ok('18:00 + bugün 3 failed satırı → null', create_daily_backup_if_needed(db(), 1, $t18) === null);
db()->exec("DELETE FROM database_backups");
$rg = create_daily_backup_if_needed(db(), 1, $t18);
$st = _bh_state_read();
ok('temiz → deneme yapıldı ve başarılı', is_array($rg) && $rg['ok'] === true, json_encode($rg));
ok('temiz → attempts=1, last_ts=now', ($st['attempts'] ?? 0) === 1 && ($st['last_ts'] ?? 0) === $t18 && ($st['date'] ?? '') === $bugun, json_encode($st));
ok('bugün başarılı var → null', create_daily_backup_if_needed(db(), 1, $t18 + 7200) === null);

// Çöküş izi: süreç döküm ortasında ölür (exit — finally çalışmaz) →
// shutdown handler .part'ı siler ve .auto_state.json'a last_crash yazar.
$cdir  = $TMP . '/crash';
$child = $TMP . '/crash_child.php';
file_put_contents($child, '<?php
declare(strict_types=1);
date_default_timezone_set("Europe/Istanbul");
define("DB_BACKUP_DIR_OVERRIDE", ' . var_export($cdir, true) . ');
define("DB_BACKUP_DISABLE_MYSQLDUMP", true);
const DB_HOST = "x"; const DB_NAME = "x"; const DB_USER = "x"; const DB_PASS = "x";
function audit_log_event(...$a): void {}
' . "\n" . substr((string)file_get_contents(__FILE__), (int)strpos((string)file_get_contents(__FILE__), 'class FakePDO'), (int)strpos((string)file_get_contents(__FILE__), 'function yeni_pdo') - (int)strpos((string)file_get_contents(__FILE__), 'class FakePDO')) . '
require ' . var_export($ROOT . '/config/db_backup_helpers.php', true) . ';
$p = new FakePDO("sqlite::memory:");
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$p->exec("CREATE TABLE database_backups (id INTEGER PRIMARY KEY, backup_date TEXT, status TEXT, file_size INT, created_at TEXT)");
$p->olum = true;
create_database_backup($p, 1, "auto_login");
');
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child) . ' 2>&1', $cout, $ccode);
$cst = json_decode((string)@file_get_contents($cdir . '/.auto_state.json'), true) ?: [];
ok('çöküş: alt süreç exit=3', $ccode === 3, implode("\n", $cout));
ok('çöküş: .part silindi', glob($cdir . '/*.part') === [] && glob($cdir . '/db_backup_*') === []);
ok('çöküş: state last_crash + crash_at', !empty($cst['last_crash']) && !empty($cst['crash_at']), json_encode($cst));

// ─────────────────────────────────────────────────────────────────────
echo "── [H] saklama\n";
db()->exec("DELETE FROM database_backups");
foreach (glob($DIR . '/db_backup_*') ?: [] as $f) @unlink($f);
$eski_tarih = date('Y-m-d', strtotime('-30 days'));
$eski_ts    = strtotime('-30 days');
$ins = db()->prepare("INSERT INTO database_backups (backup_date, filename, file_path, file_size, method, status, created_at) VALUES (?, ?, ?, 10, 'pdo_fallback', 'success', ?)");
$eski_adlar = [];
for ($i = 1; $i <= 5; $i++) {
    $ad = sprintf('db_backup_20260801_10000%d_%016x.sql.gz', $i, $i);
    file_put_contents($DIR . '/' . $ad, 'x');
    $ins->execute([$eski_tarih, $ad, '/eski/sunucu/yolu/' . $ad, date('Y-m-d H:i:s', $eski_ts + $i * 60)]);
    $eski_adlar[$i] = $ad;
}
db()->prepare("INSERT INTO database_backups (backup_date, filename, file_path, status, error_message) VALUES (?, 'f', 'f', 'failed', 'eski hata')")->execute([$eski_tarih]);
file_put_contents($DIR . '/db_backup_20260101_000000_aaaaaaaaaaaaaaaa.sql.gz', 'eski yetim'); touch($DIR . '/db_backup_20260101_000000_aaaaaaaaaaaaaaaa.sql.gz', $eski_ts);
file_put_contents($DIR . '/db_backup_20260929_000000_bbbbbbbbbbbbbbbb.sql.gz', 'yeni yetim');
file_put_contents($DIR . '/db_backup_20260101_000000_cccccccccccccccc.sql.gz.part', 'eski part'); touch($DIR . '/db_backup_20260101_000000_cccccccccccccccc.sql.gz.part', time() - 7 * 3600);
file_put_contents($DIR . '/db_backup_20260929_000000_dddddddddddddddd.sql.gz.part', 'yeni part');
$AUDIT = [];
cleanup_old_database_backups(db(), 14, 3);
$kalan = db()->query("SELECT filename FROM database_backups WHERE status='success' ORDER BY created_at DESC")->fetchAll(PDO::FETCH_COLUMN);
ok('5 eski başarılı → en yeni 3 kaldı', $kalan === [$eski_adlar[5], $eski_adlar[4], $eski_adlar[3]], json_encode($kalan));
ok('silinen 2 yedeğin dosyası silindi (basename ile)', !is_file($DIR . '/' . $eski_adlar[1]) && !is_file($DIR . '/' . $eski_adlar[2]));
ok('korunan 3 yedeğin dosyası duruyor', is_file($DIR . '/' . $eski_adlar[3]) && is_file($DIR . '/' . $eski_adlar[5]));
ok('eski failed satırı silindi', (int)db()->query("SELECT COUNT(*) FROM database_backups WHERE status='failed'")->fetchColumn() === 0);
ok('eski yetim dosya silindi', !is_file($DIR . '/db_backup_20260101_000000_aaaaaaaaaaaaaaaa.sql.gz'));
ok('yeni yetim dosya kaldı', is_file($DIR . '/db_backup_20260929_000000_bbbbbbbbbbbbbbbb.sql.gz'));
ok('6 saatten eski .part silindi', !is_file($DIR . '/db_backup_20260101_000000_cccccccccccccccc.sql.gz.part'));
ok('yeni .part kaldı', is_file($DIR . '/db_backup_20260929_000000_dddddddddddddddd.sql.gz.part'));
$ca = end($AUDIT);
ok('audit database_backup_cleanup ayrıntılı', $ca && $ca['action'] === 'database_backup_cleanup'
    && $ca['new']['deleted_rows'] === 2 && $ca['new']['orphan_files'] === 2 && $ca['new']['kept_min'] === 3, json_encode($ca, JSON_UNESCAPED_UNICODE));
@unlink($DIR . '/db_backup_20260929_000000_dddddddddddddddd.sql.gz.part');
@unlink($DIR . '/db_backup_20260929_000000_bbbbbbbbbbbbbbbb.sql.gz');

// ─────────────────────────────────────────────────────────────────────
echo "── [I] tombstone\n";
$tb = (string)file_get_contents($ROOT . '/admin_db_backup_download.php');
ok('tombstone: popen/mysqldump/gzencode/require yok', !preg_match('/popen|mysqldump|gzencode|require|db\(\)/', $tb));
ok('tombstone: 410 + kalıcı ekran linki', str_contains($tb, 'http_response_code(410)') && str_contains($tb, 'admin_db_backups.php'));
ok('tombstone kısa (≤ 15 satır)', substr_count($tb, "\n") <= 15);

// ─────────────────────────────────────────────────────────────────────
echo "── [J/O] ekran + silme\n";
function renderPage(array $post = [], array $get = []): string {
    global $ROOT;
    $_POST = $post; $_GET = $get;
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    $src = (string)file_get_contents($ROOT . '/admin_db_backups.php');
    $src = (string)preg_replace('/^\s*require_once __DIR__ \. \'\/config\/(db|auth|db_backup_helpers)\.php\';\s*$/m', '', $src);
    $src = str_replace('exit;', 'return;', $src);
    $src = (string)preg_replace('/^<\?php\s*$/m', '', $src, 1);
    $src = (string)preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
    $tmp = sys_get_temp_dir() . '/bkpui_' . getmypid() . '.php';
    file_put_contents($tmp, "<?php\n" . $src);
    ob_start();
    try { include $tmp; } catch (Throwable $e) { ob_end_clean(); @unlink($tmp); return '__ERROR__: ' . $e->getMessage(); }
    @unlink($tmp);
    return (string)ob_get_clean();
}
db()->exec("DELETE FROM database_backups");
@unlink(_bh_state_path());
$ins = db()->prepare("INSERT INTO database_backups (backup_date, filename, file_path, file_size, method, status, error_message, created_by, created_at, downloaded_at, downloaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)");
$ts3 = strtotime('-3 days');
$ad_a = 'db_backup_20260926_180000_1111111111111111.sql.gz';
$ad_b = 'db_backup_20260925_180000_2222222222222222.sql.gz';
file_put_contents($DIR . '/' . $ad_a, 'a'); file_put_contents($DIR . '/' . $ad_b, 'b');
$ins->execute([date('Y-m-d', $ts3), $ad_a, $DIR . '/' . $ad_a, 5 * 1024 * 1024, 'pdo_fallback+gzopen', 'success', 'Not: mysqldump exit=2: Access denied', date('Y-m-d H:i:s', $ts3), date('Y-m-d H:i:s', $ts3 + 60), 1]);
$ins->execute([date('Y-m-d', $ts3 - 86400), $ad_b, $DIR . '/' . $ad_b, 512, 'mysqldump+gzopen', 'success', null, date('Y-m-d H:i:s', $ts3 - 86400), null, null]);
$ins->execute([date('Y-m-d'), 'db_backup_x.sql.gz', 'x', null, 'pdo_fallback+gzopen', 'failed', 'PDO dump hatası: <b>Allowed memory</b>', date('Y-m-d H:i:s'), null, null]);
_bh_state_write(['date' => date('Y-m-d'), 'attempts' => 1, 'last_ts' => time(), 'last_crash' => 'Allowed memory size exhausted', 'crash_at' => date('Y-m-d H:i:s')]);
$UYARILAR = [];
$html = renderPage();
ok('render hatasız', !str_starts_with($html, '__ERROR__'), substr($html, 0, 200));
ok('PHP uyarısı 0', $UYARILAR === [], implode(' | ', $UYARILAR));
ok('mutlak yol basılmıyor (proje kökü / geçici klasör)', !str_contains($html, $ROOT) && !str_contains($html, $TMP));
ok('hata ipucu title=" YOK, metin görünür', !str_contains($html, 'title="') && str_contains($html, 'Access denied'));
ok('hata metni kaçışlı', str_contains($html, '&lt;b&gt;Allowed memory&lt;/b&gt;') && !str_contains($html, '<b>Allowed'));
ok('boyut "5,0 MB" ve "512 B"', str_contains($html, '5,0 MB') && str_contains($html, '512 B'));
ok('tarih yıllı (d.m.Y H:i)', str_contains($html, date('d.m.Y H:i', $ts3)));
ok('başarısızlar <details> içinde', (bool)preg_match('/<details class="bkp-fail-box">.*Son başarısız denemeler \(1\).*BAŞARISIZ.*<\/details>/s', $html));
ok('başarısız satır başarılı tabloda değil', substr_count(explode('<details', $html)[0], 'BAŞARISIZ') === 0);
ok('uyarı şeridi: son başarılı yedek 3 gün önce', str_contains($html, 'Son başarılı yedek: <strong>3 gün önce</strong>'));
ok('uyarı şeridi: çöküş izi', str_contains($html, 'Allowed memory size exhausted'));
ok('yöntem rozeti _bh_can_mysqldump (PDO fallback)', str_contains($html, '⚠️ PDO fallback'));
ok('indirme URL biçimi korunuyor', str_contains($html, 'admin_db_backups.php?action=download&id='));
ok('mobil gizli sütunlar (.bkp-col-opt)', str_contains($html, '@media (max-width: 767px)') && substr_count($html, 'bkp-col-opt') >= 8);
ok('.table-wrap + .data-table', str_contains($html, 'class="table-wrap"') && str_contains($html, 'class="data-table bkp-table"'));
ok('sabit renk yok (#hex)', !preg_match('/#[0-9a-fA-F]{6}\b/', (string)preg_replace('/&#\d+;/', '', explode('</style>', $html)[0])));
ok('silme formu POST + csrf + action=delete', (bool)preg_match('/<form method="post"[^>]*>\s*<input type="hidden" name="csrf"\s+value="testcsrf">\s*<input type="hidden" name="action" value="delete">/', $html));

$id_a = (int)db()->query("SELECT id FROM database_backups WHERE filename=" . db()->quote($ad_a))->fetchColumn();
$id_b = (int)db()->query("SELECT id FROM database_backups WHERE filename=" . db()->quote($ad_b))->fetchColumn();
$id_f = (int)db()->query("SELECT id FROM database_backups WHERE status='failed'")->fetchColumn();
$AUDIT = []; $FLASH = [];
renderPage(['csrf' => 'x', 'action' => 'delete', 'id' => (string)$id_b]);
ok('sil: 2 başarılıdan biri silindi (satır + dosya)', !is_file($DIR . '/' . $ad_b) && (int)db()->query("SELECT COUNT(*) FROM database_backups WHERE id=$id_b")->fetchColumn() === 0);
ok('sil: audit database_backup_deleted (old değerlerle)', ($AUDIT[0]['action'] ?? '') === 'database_backup_deleted' && ($AUDIT[0]['old']['filename'] ?? '') === $ad_b && $AUDIT[0]['id'] === $id_b);
$AUDIT = []; $FLASH = [];
renderPage(['csrf' => 'x', 'action' => 'delete', 'id' => (string)$id_a]);
ok('sil: SON başarılı yedek silinemez', is_file($DIR . '/' . $ad_a) && (int)db()->query("SELECT COUNT(*) FROM database_backups WHERE id=$id_a")->fetchColumn() === 1 && $AUDIT === []);
ok('sil: red mesajı flash', ($FLASH[0][0] ?? '') === 'error' && str_contains($FLASH[0][1] ?? '', 'Son başarılı yedek silinemez'));
renderPage(['csrf' => 'x', 'action' => 'delete', 'id' => (string)$id_f]);
ok('sil: başarısız deneme kaydı silinebilir', (int)db()->query("SELECT COUNT(*) FROM database_backups WHERE id=$id_f")->fetchColumn() === 0);
$html1 = renderPage();
ok('tek başarılı kaldığında sil düğmesi basılmıyor', !str_contains($html1, 'aria-label="Yedeği sil"'));

$kilit = _bh_lock_acquire();
$FLASH = [];
renderPage(['csrf' => 'x', 'action' => 'backup_now', 'onay_cb' => '1']);
ok('manuel yedek kilitliyken → info flash "zaten alınıyor"', ($FLASH[0][0] ?? '') === 'info' && str_contains($FLASH[0][1] ?? '', 'zaten alınıyor'), json_encode($FLASH, JSON_UNESCAPED_UNICODE));
_bh_lock_release($kilit);
$FLASH = [];
renderPage(['csrf' => 'x', 'action' => 'backup_now', 'onay_cb' => '1']);
ok('manuel yedek → success flash, boyut B/KB biçimli', ($FLASH[0][0] ?? '') === 'success' && (bool)preg_match('/\((\d+ B|[\d.]+,\d (KB|MB))\)/', $FLASH[0][1] ?? ''), json_encode($FLASH, JSON_UNESCAPED_UNICODE));

// ─────────────────────────────────────────────────────────────────────
echo "── [K] indirme + index.php\n";
$ap = (string)file_get_contents($ROOT . '/admin_db_backups.php');
$dl = substr($ap, (int)strpos($ap, "=== 'download'"), (int)strpos($ap, 'readfile(') - (int)strpos($ap, "=== 'download'"));
ok('indirme: session_write_close + zlib kapalı + set_time_limit(0)', str_contains($dl, 'session_write_close()') && str_contains($dl, "zlib.output_compression', '0'") && str_contains($dl, 'set_time_limit(0)'));
ok('indirme: Cache-Control no-store + nosniff', str_contains($dl, "Cache-Control: no-store, private, max-age=0") && str_contains($dl, 'X-Content-Type-Options: nosniff'));
ok('indirme: yol _bh_backup_path + realpath + str_starts_with', str_contains($dl, '_bh_backup_path(') && str_contains($dl, 'realpath(') && str_contains($dl, 'str_starts_with('));
ok('indirme GET kalıyor (URL biçimi)', str_contains($ap, "(\$_GET['action'] ?? '') === 'download'"));
$ix = (string)file_get_contents($ROOT . '/index.php');
ok('index: busy iken kutu yok', str_contains($ix, "empty(\$db_backup_result['busy'])"));
ok('index: başarısızlık sebebi (160 kr)', str_contains($ix, "mb_substr((string)\$db_backup_result['error'], 0, 160)"));
ok('index: eski/yok yedek şeridi', str_contains($ix, 'last_successful_backup(db())') && str_contains($ix, 'DB_BACKUP_STALE_SAAT'));
$cr = (string)file_get_contents($ROOT . '/scripts/db_backup_cron.php');
ok('cron: CLI kapısı + skipIfDoneToday', str_contains($cr, "PHP_SAPI !== 'cli'") && str_contains($cr, "create_database_backup(db(), 0, 'cron', !\$force)"));

// ── Temizlik ─────────────────────────────────────────────────────────
restore_error_handler();
rrmdir($TMP);
echo "\n" . ($fail_n === 0 ? "TÜM TESTLER GEÇTİ" : "BAŞARISIZ") . " — ok: $ok_n, fail: $fail_n\n";
exit($fail_n === 0 ? 0 : 1);
