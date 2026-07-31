<?php
// =========================================================
// migrate.php — Manuel şema migrasyon paneli (yalnızca admin)
// Auto-migration bazı paylaşımlı sunucularda (web DB kullanıcısının
// ALTER/CREATE yetkisi yoksa) sessizce başarısız olur. Bu sayfa her
// migrasyonu tek tek dener ve TAM hata mesajını gösterir; başarısız
// olursa phpMyAdmin'de elle çalıştırılacak SQL'i de listeler.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!is_admin()) { forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.'); }

$pdo = db();

// Çalıştırılacak migrasyon tanımları: kolon eklemeleri (idempotent)
// her biri: [tablo, kolon, "ALTER ... SQL"]
$migrations = [
    ['loading_records', 'ulasim',         "ALTER TABLE `loading_records` ADD COLUMN `ulasim` VARCHAR(100) NOT NULL DEFAULT ''"],
    ['loading_records', 'brand',          "ALTER TABLE `loading_records` ADD COLUMN `brand` VARCHAR(20) NULL"],
    ['loading_records', 'urun_sahibi_id', "ALTER TABLE `loading_records` ADD COLUMN `urun_sahibi_id` INT NULL DEFAULT NULL"],
    ['loading_records', 'type',           "ALTER TABLE `loading_records` ADD COLUMN `type` VARCHAR(20) NOT NULL DEFAULT 'yukleme'"],
    ['loading_records', 'report_id',      "ALTER TABLE `loading_records` ADD COLUMN `report_id` INT NULL"],
    ['loading_records', 'reported_at',    "ALTER TABLE `loading_records` ADD COLUMN `reported_at` DATETIME NULL"],
    ['loading_records', 'reported_by',    "ALTER TABLE `loading_records` ADD COLUMN `reported_by` INT NULL"],
    ['material_definitions', 'color',     "ALTER TABLE `material_definitions` ADD COLUMN `color` VARCHAR(7) NULL"],
    // Hesap modülü — personel kimliği, durum makinesi, depo damgası
    ['account_transactions', 'user_id',      "ALTER TABLE `account_transactions` ADD COLUMN `user_id` INT NULL"],
    ['account_transactions', 'created_by',   "ALTER TABLE `account_transactions` ADD COLUMN `created_by` INT NULL"],
    ['account_transactions', 'status',       "ALTER TABLE `account_transactions` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'submitted'"],
    ['account_transactions', 'submitted_at', "ALTER TABLE `account_transactions` ADD COLUMN `submitted_at` DATETIME NULL"],
    ['account_transactions', 'reviewed_by',  "ALTER TABLE `account_transactions` ADD COLUMN `reviewed_by` INT NULL"],
    ['account_transactions', 'reviewed_at',  "ALTER TABLE `account_transactions` ADD COLUMN `reviewed_at` DATETIME NULL"],
    ['account_transactions', 'review_note',  "ALTER TABLE `account_transactions` ADD COLUMN `review_note` VARCHAR(500) NOT NULL DEFAULT ''"],
    ['account_transactions', 'paid_at',      "ALTER TABLE `account_transactions` ADD COLUMN `paid_at` DATETIME NULL"],
    ['account_transactions', 'depo',         "ALTER TABLE `account_transactions` ADD COLUMN `depo` VARCHAR(150) NOT NULL DEFAULT ''"],
];

function mig_table_exists(PDO $pdo, string $t): bool {
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 0"); return true; }
    catch (PDOException $e) { return false; }
}
function mig_col_exists(PDO $pdo, string $t, string $c): bool {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN);
        return in_array($c, $cols, true);
    } catch (PDOException $e) { return false; }
}

$results = [];   // her migrasyon için sonuç
$ran     = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $ran = true;
    foreach ($migrations as [$tbl, $col, $sql]) {
        if (!mig_table_exists($pdo, $tbl)) {
            $results[] = ['tbl' => $tbl, 'col' => $col, 'status' => 'skip', 'msg' => 'Tablo yok — atlandı.', 'sql' => $sql];
            continue;
        }
        if (mig_col_exists($pdo, $tbl, $col)) {
            $results[] = ['tbl' => $tbl, 'col' => $col, 'status' => 'exists', 'msg' => 'Kolon zaten var.', 'sql' => $sql];
            continue;
        }
        try {
            $pdo->exec($sql);
            $ok = mig_col_exists($pdo, $tbl, $col);
            $results[] = ['tbl' => $tbl, 'col' => $col,
                'status' => $ok ? 'added' : 'fail',
                'msg' => $ok ? 'Kolon eklendi.' : 'ALTER çalıştı ama kolon görünmüyor.',
                'sql' => $sql];
        } catch (PDOException $e) {
            $results[] = ['tbl' => $tbl, 'col' => $col, 'status' => 'fail',
                'msg' => $e->getMessage(), 'sql' => $sql];
        }
    }
}

// Mevcut durum tablosu (her zaman göster)
$status = [];
foreach ($migrations as [$tbl, $col, $sql]) {
    $exists = mig_table_exists($pdo, $tbl) && mig_col_exists($pdo, $tbl, $col);
    $status[] = ['tbl' => $tbl, 'col' => $col, 'exists' => $exists, 'sql' => $sql];
}

render_header('Şema Migrasyon');
?>
<div class="container">
  <h1 style="margin:16px 0 8px;">Şema Migrasyon Paneli</h1>
  <p style="color:#555;max-width:760px;">
    Eksik veritabanı kolonlarını ekler. Auto-migration sunucuda
    çalışmadıysa (web DB kullanıcısının <b>ALTER</b> yetkisi yoksa) bu
    sayfa size tam hata mesajını gösterir; o durumda aşağıdaki SQL'i
    phpMyAdmin'den çalıştırın.
  </p>

  <?php if ($ran): ?>
    <div class="card" style="margin:16px 0;padding:16px;">
      <h2 style="margin-top:0;">Çalıştırma Sonucu</h2>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Tablo</th><th>Kolon</th><th>Durum</th><th>Mesaj</th></tr></thead>
          <tbody>
          <?php foreach ($results as $r):
            $color = ['added'=>'#1f9d55','exists'=>'#555','skip'=>'#888','fail'=>'#c0392b'][$r['status']] ?? '#333';
            $label = ['added'=>'✓ Eklendi','exists'=>'• Zaten var','skip'=>'– Atlandı','fail'=>'✗ HATA'][$r['status']] ?? $r['status'];
          ?>
            <tr>
              <td><?= h($r['tbl']) ?></td>
              <td><b><?= h($r['col']) ?></b></td>
              <td style="color:<?= $color ?>;font-weight:600;"><?= h($label) ?></td>
              <td style="color:<?= $r['status']==='fail' ? '#c0392b' : '#444' ?>;"><?= h($r['msg']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php
        $any_fail = array_filter($results, fn($r) => $r['status'] === 'fail');
        if ($any_fail):
      ?>
        <div style="margin-top:16px;padding:12px;background:#fdf0ef;border:1px solid #e8b9b3;border-radius:8px;">
          <p style="margin:0 0 8px;color:#c0392b;font-weight:600;">
            Bazı migrasyonlar başarısız oldu. Aşağıdaki SQL'i phpMyAdmin → SQL sekmesinden çalıştırın:
          </p>
          <pre style="white-space:pre-wrap;background:#fff;padding:10px;border-radius:6px;overflow:auto;"><?php
            foreach ($any_fail as $r) { echo h($r['sql']) . ";\n"; }
          ?></pre>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card" style="margin:16px 0;padding:16px;">
    <h2 style="margin-top:0;">Mevcut Durum</h2>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Tablo</th><th>Kolon</th><th>Durum</th></tr></thead>
        <tbody>
        <?php foreach ($status as $s): ?>
          <tr>
            <td><?= h($s['tbl']) ?></td>
            <td><b><?= h($s['col']) ?></b></td>
            <td style="color:<?= $s['exists'] ? '#1f9d55' : '#c0392b' ?>;font-weight:600;">
              <?= $s['exists'] ? '✓ Var' : '✗ Eksik' ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <form method="post" style="margin-top:16px;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <button type="submit" class="btn btn-primary">Eksik Kolonları Ekle</button>
    </form>
  </div>
</div>
<?php render_footer(); ?>
