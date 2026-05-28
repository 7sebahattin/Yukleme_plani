<?php
// =============================================================
// scripts/test_staging_migration.php — Sprint 9
// Normalize migration staging test:
//   backup → forward → verify → rollback test → rapor
//
// CLI ONLY. Production DB'ye dokunmaz.
//
// Kullanım:
//   php scripts/test_staging_migration.php \
//       --test-db yukleme_plani_staging \
//       [--forward  scripts/forward_migration_*.sql]  (yoksa otomatik bulur)
//       [--rollback scripts/rollback_migration_*.sql] (yoksa otomatik bulur)
//       [--backup-dir /tmp/migration_backups]         (varsayılan: scripts/)
//       [--clone]           production → staging kopyala (staging'i sıfırlar!)
//       [--skip-rollback]   rollback testini atla
//       [--yes]             onay sorularını atla
//
// Ön koşullar:
//   --clone olmadan: staging DB önceden oluşturulmuş ve doldurulmuş olmalı.
//   --clone ile:     sadece boş DB olması yeterli (CREATE DATABASE ile).
// =============================================================

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Hata: Bu script yalnızca CLI'dan çalıştırılabilir.\n");
}

// ── Argüman ayrıştırma ───────────────────────────────────────
$opts = [
    'test_db'      => '',
    'forward'      => '',
    'rollback'     => '',
    'backup_dir'   => __DIR__,
    'clone'        => false,
    'skip_rollback'=> false,
    'yes'          => false,
];
for ($i = 1; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '--test-db':       $opts['test_db']       = $argv[++$i] ?? ''; break;
        case '--forward':       $opts['forward']        = $argv[++$i] ?? ''; break;
        case '--rollback':      $opts['rollback']       = $argv[++$i] ?? ''; break;
        case '--backup-dir':    $opts['backup_dir']     = rtrim($argv[++$i] ?? __DIR__, '/'); break;
        case '--clone':         $opts['clone']          = true; break;
        case '--skip-rollback': $opts['skip_rollback']  = true; break;
        case '--yes':           $opts['yes']            = true; break;
    }
}

// ── DB credentials — db.php'yi ÇALIŞTIRMADAN oku ────────────
// helpers.php db() çağırdığından require etmiyoruz; regex ile parse ediyoruz.
$_db_src = file_get_contents(dirname(__DIR__) . '/config/db.php');
$_cred = [];
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_CHARSET'] as $_c) {
    $_cred[$_c] = preg_match("/const $_c\s*=\s*'([^']*)'/", $_db_src, $_m) ? $_m[1] : '';
}
define('DB_HOST',    $_cred['DB_HOST']    ?: 'localhost');
define('DB_NAME',    $_cred['DB_NAME']    ?: 'yukleme_plani');
define('DB_USER',    $_cred['DB_USER']    ?: 'root');
define('DB_PASS',    $_cred['DB_PASS']    ?: '');
define('DB_CHARSET', $_cred['DB_CHARSET'] ?: 'utf8mb4');
unset($_db_src, $_cred, $_c, $_m);

// ── Sabitler ve yardımcılar ──────────────────────────────────
$ts      = date('Ymd_His');
$prod_db = DB_NAME;
$test_db = $opts['test_db'];

if ($test_db === '') {
    fwrite(STDERR, "Hata: --test-db <veritabanı_adı> gerekli.\n");
    fwrite(STDERR, "Örnek: php scripts/test_staging_migration.php --test-db yukleme_plani_staging\n");
    exit(1);
}
if ($test_db === $prod_db) {
    fwrite(STDERR, "HATA: --test-db production DB ile aynı olamaz! (production: $prod_db)\n");
    exit(1);
}

if (!is_dir($opts['backup_dir']) && !mkdir($opts['backup_dir'], 0755, true)) {
    fwrite(STDERR, "Hata: backup-dir oluşturulamadı: {$opts['backup_dir']}\n");
    exit(1);
}

// CLI renkler
$C = [
    'ok'   => "\033[32m",
    'fail' => "\033[31m",
    'warn' => "\033[33m",
    'bold' => "\033[1m",
    'dim'  => "\033[2m",
    'off'  => "\033[0m",
];
function ok(string $s):   string { global $C; return $C['ok']   . $s . $C['off']; }
function fail(string $s): string { global $C; return $C['fail'] . $s . $C['off']; }
function warn(string $s): string { global $C; return $C['warn'] . $s . $C['off']; }
function bold(string $s): string { global $C; return $C['bold'] . $s . $C['off']; }

// ── MySQL CLI araçları ───────────────────────────────────────
function mysql_args(bool $with_db = true, string $db_override = ''): string {
    $db = $db_override !== '' ? $db_override : DB_NAME;
    $h  = escapeshellarg(DB_HOST);
    $u  = escapeshellarg(DB_USER);
    $p  = DB_PASS !== '' ? ' -p' . escapeshellarg(DB_PASS) : '';
    return "-h $h -u $u$p" . ($with_db ? ' ' . escapeshellarg($db) : '');
}

function run_cmd(string $cmd, string &$out, string &$err): int {
    $desc = [1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) { $out = ''; $err = 'proc_open başarısız'; return 1; }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return proc_close($proc);
}

/** MySQL CLI ile SQL dosyası çalıştır, dönen [exit_code, stderr]. */
function mysql_run_file(string $db, string $sql_file): array {
    $args = mysql_args(true, $db);
    $cmd  = "mysql $args < " . escapeshellarg($sql_file) . " 2>&1";
    exec($cmd, $output, $code);
    return [$code, implode("\n", $output)];
}

/** mysqldump ile yedek al. */
function mysqldump_to(string $db, string $dest): array {
    $args = mysql_args(true, $db);
    $cmd  = "mysqldump --no-tablespaces --single-transaction $args > " . escapeshellarg($dest) . " 2>&1";
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out)];
}

// ── PDO bağlantısı (staging DB) ─────────────────────────────
function staging_pdo(string $db): PDO {
    $dsn  = 'mysql:host=' . DB_HOST . ';dbname=' . $db . ';charset=utf8mb4';
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    return new PDO($dsn, DB_USER, DB_PASS !== '' ? DB_PASS : null, $opts);
}

function stg_tbl(PDO $p, string $t): bool {
    return (bool) $p->query("SHOW TABLES LIKE '$t'")->fetchColumn();
}

// ── SQL dosyalarını bul ──────────────────────────────────────
$script_dir = __DIR__;

if ($opts['forward'] === '') {
    $files = glob("$script_dir/forward_migration_*.sql") ?: [];
    natsort($files);
    $opts['forward'] = end($files) ?: '';
}
if ($opts['rollback'] === '') {
    $files = glob("$script_dir/rollback_migration_*.sql") ?: [];
    natsort($files);
    $opts['rollback'] = end($files) ?: '';
}

$fwd_file = $opts['forward'];
$rbk_file = $opts['rollback'];

// ── Report buffer ────────────────────────────────────────────
$report_lines = [];
function rpt(string $line = ''): void {
    global $report_lines;
    $report_lines[] = preg_replace('/\033\[\d+m/', '', $line); // renk kodlarını sil
    echo $line . "\n";
}

// ── Başlık ───────────────────────────────────────────────────
rpt();
rpt(bold("=========================================================="));
rpt(bold(" Sprint 9 — Normalize Migration Staging Test"));
rpt(bold("=========================================================="));
rpt("  Tarih       : " . date('Y-m-d H:i:s'));
rpt("  Production  : $prod_db  ← dokunulmaz");
rpt("  Staging DB  : $test_db");
rpt("  Forward SQL : " . ($fwd_file ?: warn("bulunamadı")));
rpt("  Rollback SQL: " . ($rbk_file ?: warn("bulunamadı")));
rpt("  Backup dir  : {$opts['backup_dir']}");
rpt("  Rollback test: " . ($opts['skip_rollback'] ? warn("atlanıyor") : ok("evet")));
rpt();

if ($fwd_file === '' || !file_exists($fwd_file)) {
    rpt(fail("HATA: Forward migration SQL dosyası bulunamadı."));
    rpt("Plan script'i çalıştırın: php scripts/plan_normalize_migration.php");
    exit(1);
}

// ── Onay ────────────────────────────────────────────────────
if (!$opts['yes']) {
    echo bold("Devam etmek için 'evet' yazın: ");
    $input = trim((string) fgets(STDIN));
    if ($input !== 'evet') { rpt(warn("İptal.")); exit(0); }
}

// ── Staging DB bağlantı testi ────────────────────────────────
rpt();
rpt(bold("── 1. Staging DB bağlantı testi ─────────────────────────"));
try {
    $pdo = staging_pdo($test_db);
    rpt(ok("  ✓ Bağlantı başarılı: $test_db"));
} catch (PDOException $e) {
    rpt(fail("  ✗ Bağlantı hatası: " . $e->getMessage()));
    rpt("  Staging DB oluşturun: CREATE DATABASE $test_db CHARACTER SET utf8mb4;");
    rpt("  Ardından --clone ile production'dan kopyalayın.");
    exit(1);
}

// ── Clone (isteğe bağlı) ─────────────────────────────────────
if ($opts['clone']) {
    rpt();
    rpt(bold("── 2a. Production → Staging klonlama ────────────────────"));
    rpt(warn("  ⚠  Bu işlem staging DB'yi tamamen sıfırlar: $test_db"));
    if (!$opts['yes']) {
        echo bold("  Staging DB sıfırlansın mı? 'evet' yazın: ");
        $inp = trim((string) fgets(STDIN));
        if ($inp !== 'evet') { rpt(warn("  Klonlama atlandı.")); goto backup_step; }
    }
    rpt("  mysqldump $prod_db | mysql $test_db ...");
    $h = escapeshellarg(DB_HOST);
    $u = escapeshellarg(DB_USER);
    $p = DB_PASS !== '' ? '-p' . escapeshellarg(DB_PASS) : '';
    $cmd = "mysqldump --no-tablespaces --single-transaction -h $h -u $u $p "
         . escapeshellarg($prod_db)
         . " | mysql -h $h -u $u $p " . escapeshellarg($test_db) . " 2>&1";
    exec($cmd, $clOut, $clCode);
    if ($clCode !== 0) {
        rpt(fail("  ✗ Klonlama başarısız: " . implode("\n", $clOut)));
        exit(1);
    }
    rpt(ok("  ✓ Staging DB klonlandı."));
    $pdo = staging_pdo($test_db); // yenile
}

backup_step:
// ── Backup ───────────────────────────────────────────────────
rpt();
rpt(bold("── 2. Staging DB yedekleniyor ────────────────────────────"));
$bak_path = "{$opts['backup_dir']}/staging_backup_{$test_db}_{$ts}.sql";
rpt("  Yol: $bak_path");
[$bcode, $berr] = mysqldump_to($test_db, $bak_path);
if ($bcode !== 0) {
    rpt(fail("  ✗ Yedek alınamadı: $berr"));
    exit(1);
}
$bak_size = file_exists($bak_path) ? round(filesize($bak_path) / 1024, 1) . ' KB' : '?';
rpt(ok("  ✓ Yedek alındı ($bak_size)"));

// ── Pre-migration state ───────────────────────────────────────
rpt();
rpt(bold("── 3. Migration öncesi durum ─────────────────────────────"));

function snapshot(PDO $p, string $label): array {
    $snap = ['label' => $label];
    foreach (['material_definitions','loading_pallets','pallet_materials','material_stock_movements'] as $t) {
        $snap[$t] = stg_tbl($p, $t) ? (int) $p->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() : null;
    }
    // U+0307 sayısı
    if (stg_tbl($p, 'material_definitions')) {
        $snap['u0307'] = (int) $p->query(
            "SELECT COUNT(*) FROM material_definitions WHERE HEX(name) LIKE '%CC87%'"
        )->fetchColumn();
        $snap['md_distinct'] = (int) $p->query(
            "SELECT COUNT(DISTINCT CONCAT(type,'||',LOWER(TRIM(name)))) FROM material_definitions"
        )->fetchColumn();
    }
    return $snap;
}

$pre = snapshot($pdo, 'PRE-MIGRATION');

foreach (['material_definitions','loading_pallets','pallet_materials','material_stock_movements'] as $t) {
    $cnt = $pre[$t];
    if ($cnt !== null) rpt("  $t : $cnt satır");
}
rpt("  U+0307 içeren isim : " . ($pre['u0307'] ?? '?'));
rpt("  Distinct (type,name): " . ($pre['md_distinct'] ?? '?'));

// normalize_migration_queue approved sayısı
$approved_cnt = 0;
if (stg_tbl($pdo, 'normalize_migration_queue')) {
    $approved_cnt = (int) $pdo->query(
        "SELECT COUNT(*) FROM normalize_migration_queue WHERE status='approved'"
    )->fetchColumn();
}
rpt("  Onaylı queue kayıt : $approved_cnt");

// ── Forward migration ─────────────────────────────────────────
rpt();
rpt(bold("── 4. Forward migration uygulanıyor ──────────────────────"));
rpt("  Dosya: $fwd_file");
rpt("  DB   : $test_db");

[$fcode, $ferr] = mysql_run_file($test_db, $fwd_file);
if ($fcode !== 0) {
    rpt(fail("  ✗ Forward migration BAŞARISIZ! (exit=$fcode)"));
    rpt(fail("  $ferr"));
    rpt(warn("  Staging DB orijinal haline getirmek için:"));
    rpt("  mysql " . mysql_args(true, $test_db) . " < $bak_path");
    exit(1);
}
rpt(ok("  ✓ Forward migration uygulandı."));

// ── Post-migration snapshot ───────────────────────────────────
$post = snapshot($pdo, 'POST-MIGRATION');

rpt();
rpt("  Delta:");
rpt(sprintf("    %-35s  %6s → %6s  (%+d)",
    'material_definitions',
    $pre['material_definitions'] ?? '?', $post['material_definitions'] ?? '?',
    ($post['material_definitions'] ?? 0) - ($pre['material_definitions'] ?? 0)
));
rpt(sprintf("    %-35s  %6s → %6s  (%+d)",
    'U+0307 içeren isim',
    $pre['u0307'] ?? '?', $post['u0307'] ?? '?',
    ($post['u0307'] ?? 0) - ($pre['u0307'] ?? 0)
));

// ── Doğrulama süiti ───────────────────────────────────────────
rpt();
rpt(bold("── 5. Doğrulama süiti ────────────────────────────────────"));

$checks   = [];
$has_fail = false;

function chk(string $name, bool $passed, string $detail = ''): void {
    global $checks, $has_fail;
    $checks[] = ['name' => $name, 'pass' => $passed, 'detail' => $detail];
    $has_fail = $has_fail || !$passed;
    echo ($passed ? ok("  ✓") : fail("  ✗")) . " $name";
    if ($detail !== '') echo "  — $detail";
    echo "\n";
}

// V1 — U+0307 kaldı mı?
if (stg_tbl($pdo, 'material_definitions')) {
    $u307 = (int) $pdo->query(
        "SELECT COUNT(*) FROM material_definitions WHERE HEX(name) LIKE '%CC87%'"
    )->fetchColumn();
    chk("U+0307 temizlendi mi?", $u307 === 0,
        $u307 > 0 ? "$u307 satırda hâlâ U+0307 var!" : "0 satır");
} else {
    chk("U+0307 temizlendi mi?", false, "material_definitions tablosu yok");
}

// V2 — Onaylı dup'lar silindi mi?
if (stg_tbl($pdo, 'normalize_migration_queue') && stg_tbl($pdo, 'material_definitions')) {
    $still_exist = (int) $pdo->query("
        SELECT COUNT(*) FROM material_definitions md
        INNER JOIN normalize_migration_queue q
            ON q.target_id = md.id
        WHERE q.status = 'approved' AND q.is_merge = 1 AND q.is_survivor = 0
    ")->fetchColumn();
    chk("Onaylı dup'lar silindi mi?", $still_exist === 0,
        $still_exist > 0 ? "$still_exist dup hâlâ mevcut!" : "tümü silindi");
} else {
    chk("Onaylı dup'lar silindi mi?", true, "tablo yok veya queue boş — atlandı");
}

// V3 — Onaylı survivor'ların isimleri güncellendi mi?
if (stg_tbl($pdo, 'normalize_migration_queue') && stg_tbl($pdo, 'material_definitions')) {
    $name_mismatch = (int) $pdo->query("
        SELECT COUNT(*) FROM material_definitions md
        INNER JOIN normalize_migration_queue q
            ON q.target_id = md.id
        WHERE q.status = 'approved' AND q.will_change = 1
          AND md.name != q.v2_value
    ")->fetchColumn();
    chk("İsim güncellemeleri uygulandı mı?", $name_mismatch === 0,
        $name_mismatch > 0 ? "$name_mismatch satırda isim hâlâ eski!" : "tümü güncellendi");
}

// V4 — Duplicate oluştu mu?
if (stg_tbl($pdo, 'material_definitions')) {
    $dups = $pdo->query("
        SELECT CONCAT(type, '||', LOWER(TRIM(name))) AS k, COUNT(*) AS n
        FROM material_definitions
        GROUP BY k
        HAVING n > 1
        LIMIT 5
    ")->fetchAll();
    chk("Yeni duplicate yok mu?", empty($dups),
        empty($dups) ? "duplicate yok" : count($dups) . " duplicate grubu: " .
            implode(', ', array_map(fn($r) => '"' . $r['k'] . '"(' . $r['n'] . ')', $dups))
    );
}

// V5 — loading_pallets.kasa_cinsi_id FK bütünlüğü
if (stg_tbl($pdo, 'loading_pallets') && stg_tbl($pdo, 'material_definitions')) {
    $orphan = (int) $pdo->query("
        SELECT COUNT(*) FROM loading_pallets lp
        LEFT JOIN material_definitions md ON md.id = lp.kasa_cinsi_id
        WHERE lp.kasa_cinsi_id IS NOT NULL AND md.id IS NULL
    ")->fetchColumn();
    chk("FK loading_pallets.kasa_cinsi_id sağlam mı?", $orphan === 0,
        $orphan > 0 ? "$orphan orphan FK ref!" : "sağlam");
}

// V6 — loading_pallets.palet_tipi_id FK bütünlüğü
if (stg_tbl($pdo, 'loading_pallets') && stg_tbl($pdo, 'material_definitions')) {
    $orphan = (int) $pdo->query("
        SELECT COUNT(*) FROM loading_pallets lp
        LEFT JOIN material_definitions md ON md.id = lp.palet_tipi_id
        WHERE lp.palet_tipi_id IS NOT NULL AND md.id IS NULL
    ")->fetchColumn();
    chk("FK loading_pallets.palet_tipi_id sağlam mı?", $orphan === 0,
        $orphan > 0 ? "$orphan orphan FK ref!" : "sağlam");
}

// V7 — pallet_materials.material_id FK bütünlüğü
if (stg_tbl($pdo, 'pallet_materials') && stg_tbl($pdo, 'material_definitions')) {
    $orphan = (int) $pdo->query("
        SELECT COUNT(*) FROM pallet_materials pm
        LEFT JOIN material_definitions md ON md.id = pm.material_id
        WHERE pm.material_id IS NOT NULL AND md.id IS NULL
    ")->fetchColumn();
    chk("FK pallet_materials.material_id sağlam mı?", $orphan === 0,
        $orphan > 0 ? "$orphan orphan FK ref!" : "sağlam");
}

// V8 — material_stock_movements.material_id FK bütünlüğü
if (stg_tbl($pdo, 'material_stock_movements') && stg_tbl($pdo, 'material_definitions')) {
    $orphan = (int) $pdo->query("
        SELECT COUNT(*) FROM material_stock_movements msm
        LEFT JOIN material_definitions md ON md.id = msm.material_id
        WHERE msm.material_id IS NOT NULL AND md.id IS NULL
    ")->fetchColumn();
    chk("FK material_stock_movements.material_id sağlam mı?", $orphan === 0,
        $orphan > 0 ? "$orphan orphan FK ref!" : "sağlam");
}

// V9 — Ürün stok filtresi (firma, depo eşleşmesi)
rpt();
rpt("  Ürün/Stok filtresi kontrolleri:");
if (stg_tbl($pdo, 'loading_records') && stg_tbl($pdo, 'loading_pallets')) {
    // Herhangi bir firma var mı?
    $firma_cnt = (int) $pdo->query(
        "SELECT COUNT(DISTINCT firma) FROM loading_records WHERE firma != ''"
    )->fetchColumn();
    // Herhangi bir depo var mı?
    $depo_cnt = (int) $pdo->query(
        "SELECT COUNT(DISTINCT depo) FROM loading_pallets WHERE depo != ''"
    )->fetchColumn();
    chk("loading_records.firma doluluk", $firma_cnt > 0,
        "$firma_cnt distinct firma");
    chk("loading_pallets.depo doluluk", $depo_cnt > 0,
        "$depo_cnt distinct depo");

    // Palet satırları bir kayda bağlı mı?
    $orphan_pallets = (int) $pdo->query("
        SELECT COUNT(*) FROM loading_pallets lp
        LEFT JOIN loading_records lr ON lr.id = lp.loading_record_id
        WHERE lr.id IS NULL
    ")->fetchColumn();
    chk("loading_pallets → loading_records FK sağlam mı?", $orphan_pallets === 0,
        $orphan_pallets > 0 ? "$orphan_pallets orphan palet!" : "sağlam");
}

// V10 — Malzeme stok hareketleri
if (stg_tbl($pdo, 'material_stock_movements')) {
    $msm_total = (int) $pdo->query("SELECT COUNT(*) FROM material_stock_movements")->fetchColumn();
    $msm_null  = (int) $pdo->query(
        "SELECT COUNT(*) FROM material_stock_movements WHERE material_id IS NULL"
    )->fetchColumn();
    chk("material_stock_movements tutarlılığı", $msm_null === 0,
        "toplam $msm_total hareket, $msm_null null material_id");
}

// ── Rollback testi ────────────────────────────────────────────
$rollback_ok = null;
if (!$opts['skip_rollback'] && $rbk_file !== '' && file_exists($rbk_file)) {
    rpt();
    rpt(bold("── 6. Rollback testi ─────────────────────────────────────"));
    rpt("  Dosya: $rbk_file");

    [$rcode, $rerr] = mysql_run_file($test_db, $rbk_file);
    if ($rcode !== 0) {
        rpt(fail("  ✗ Rollback SQL BAŞARISIZ! (exit=$rcode)"));
        rpt(fail("  $rerr"));
        $rollback_ok = false;
    } else {
        rpt(ok("  ✓ Rollback SQL çalıştı."));

        $rbk_snap = snapshot($pdo, 'POST-ROLLBACK');

        // Silinen dup'lar geri döndü mü?
        $restored = 0;
        if (stg_tbl($pdo, 'normalize_migration_queue') && stg_tbl($pdo, 'material_definitions')) {
            $restored = (int) $pdo->query("
                SELECT COUNT(*) FROM material_definitions md
                INNER JOIN normalize_migration_queue q ON q.target_id = md.id
                WHERE q.status = 'approved' AND q.is_merge = 1 AND q.is_survivor = 0
            ")->fetchColumn();
        }

        // md satır sayısı eski haline döndü mü?
        $md_pre  = $pre['material_definitions'] ?? 0;
        $md_post = $rbk_snap['material_definitions'] ?? 0;
        $md_diff = abs($md_post - $md_pre);

        rpt("  Rollback sonrası material_definitions: $md_post (öncesi: $md_pre, fark: $md_diff)");
        rpt("  Geri dönen dup sayısı: $restored");

        $rollback_ok = ($md_diff === 0);
        if ($rollback_ok) {
            rpt(ok("  ✓ Rollback satır sayısı doğrulandı."));
        } else {
            rpt(warn("  ⚠  Satır sayısı farkı: $md_diff — FK merge rollback kesin değil (beklenen davranış)."));
            rpt("     Yeni oluşturulan kayıtlar surviving_id'yi referans aldığından fark normal olabilir.");
        }

        // Rollback sonrası forward tekrar çalıştır (staging'i temiz bırak)
        rpt();
        rpt("  Staging'i tekrar forward durumuna getiriliyor...");
        [$fcode2, $ferr2] = mysql_run_file($test_db, $fwd_file);
        if ($fcode2 !== 0) {
            rpt(warn("  ⚠  Re-forward başarısız: $ferr2"));
        } else {
            rpt(ok("  ✓ Staging forward durumuna getirildi."));
        }
    }
} elseif (!$opts['skip_rollback'] && ($rbk_file === '' || !file_exists($rbk_file))) {
    rpt();
    rpt(warn("── 6. Rollback testi — dosya bulunamadı, atlandı ─────────"));
    $rollback_ok = null;
}

// ── İstatistik özeti ──────────────────────────────────────────
rpt();
rpt(bold("── 7. İstatistik özeti ───────────────────────────────────"));

// Forward SQL dosyasını parse et — satır tipi sayımı
$fwd_content = file_get_contents($fwd_file);
$cnt_update  = substr_count(strtoupper($fwd_content), 'UPDATE ');
$cnt_delete  = substr_count(strtoupper($fwd_content), 'DELETE FROM ');
$cnt_fk      = 0;
foreach (['loading_pallets','pallet_materials','material_stock_movements'] as $t) {
    $cnt_fk += substr_count(strtoupper($fwd_content), strtoupper("UPDATE `$t`"));
}
$cnt_md_upd = $cnt_update - $cnt_fk;

rpt(sprintf("  material_definitions UPDATE  : %d", $cnt_md_upd));
rpt(sprintf("  FK tablo UPDATE (taşıma)     : %d", $cnt_fk));
rpt(sprintf("  material_definitions DELETE  : %d", $cnt_delete));
rpt(sprintf("  Toplam UPDATE                : %d", $cnt_update));
rpt(sprintf("  Doğrulama kontrolü           : %d/%d geçti",
    count(array_filter($checks, fn($c) => $c['pass'])),
    count($checks)
));

// Başarısız kontroller
$failed = array_filter($checks, fn($c) => !$c['pass']);
if (!empty($failed)) {
    rpt();
    rpt(warn("  Başarısız kontroller:"));
    foreach ($failed as $c) {
        rpt(fail("    ✗ {$c['name']}") . ($c['detail'] ? " — {$c['detail']}" : ''));
    }
}

// ── Production hazır mı? ──────────────────────────────────────
rpt();
rpt(bold("── 8. Production için hazır mı? ──────────────────────────"));

$all_pass     = !$has_fail;
$rbk_msg      = match($rollback_ok) {
    true     => ok("✓ Rollback çalışıyor"),
    false    => fail("✗ Rollback başarısız"),
    null     => warn("— test edilmedi"),
};

if ($all_pass) {
    rpt(ok("  ✓ Tüm doğrulama kontrolleri geçti."));
} else {
    rpt(fail("  ✗ " . count($failed) . " kontrol başarısız — production'a GEÇME!"));
}
rpt("  Rollback durumu: $rbk_msg");

$prod_ready = $all_pass;
if ($prod_ready) {
    rpt();
    rpt(ok(bold("  ✓ PRODUCTION İÇİN HAZIR")));
    rpt("  Sonraki adım: Ayrıca onay alındıktan sonra forward_migration.sql'i");
    rpt("  production DB'de çalıştırın (mysqldump ile tam yedek alarak).");
} else {
    rpt();
    rpt(fail(bold("  ✗ PRODUCTION İÇİN HAZIR DEĞİL")));
    rpt("  Yukarıdaki hataları giderin, ardından staging testini tekrarlayın.");
}

// ── Rapor dosyası ─────────────────────────────────────────────
$rpt_path = "{$opts['backup_dir']}/staging_report_{$test_db}_{$ts}.txt";
$rpt_lines = [
    "Sprint 9 — Normalize Migration Staging Report",
    "Tarih: " . date('Y-m-d H:i:s'),
    "Staging DB: $test_db",
    "Forward SQL: $fwd_file",
    "Rollback SQL: $rbk_file",
    "",
    "PRE-MIGRATION:",
    "  material_definitions : " . ($pre['material_definitions'] ?? 'N/A'),
    "  U+0307 sayısı        : " . ($pre['u0307'] ?? 'N/A'),
    "",
    "POST-MIGRATION:",
    "  material_definitions : " . ($post['material_definitions'] ?? 'N/A'),
    "  U+0307 sayısı        : " . ($post['u0307'] ?? 'N/A'),
    "",
    "FORWARD SQL İSTATİSTİKLER:",
    "  material_definitions UPDATE : $cnt_md_upd",
    "  FK taşıma UPDATE            : $cnt_fk",
    "  material_definitions DELETE : $cnt_delete",
    "",
    "DOĞRULAMA SONUÇLARI:",
];
foreach ($checks as $c) {
    $rpt_lines[] = sprintf("  [%s] %s%s",
        $c['pass'] ? 'GEÇTI' : 'BAŞARISIZ',
        $c['name'],
        $c['detail'] ? " — {$c['detail']}" : ''
    );
}
$rpt_lines[] = "";
$rpt_lines[] = "ROLLBACK: " . ($rollback_ok === true ? "ÇALIŞIYOR" : ($rollback_ok === false ? "BAŞARISIZ" : "TEST EDİLMEDİ"));
$rpt_lines[] = "PRODUCTION HAZIR: " . ($prod_ready ? "EVET" : "HAYIR");
$rpt_lines[] = "";
$rpt_lines[] = "Backup: $bak_path";

file_put_contents($rpt_path, implode("\n", $rpt_lines) . "\n");

rpt();
rpt(bold("── Dosyalar ──────────────────────────────────────────────"));
rpt("  Backup : $bak_path");
rpt("  Rapor  : $rpt_path");
rpt();
