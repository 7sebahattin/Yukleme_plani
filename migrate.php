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

// Sprint PDKS-01: PDKS tablo migrasyonu buradan ELLE tetiklenir.
// config/pdks.php uygulamanın normal akışında YÜKLENMEZ (bkz. dosya başlığı);
// bu admin paneli, hiçbir PDKS sayfası yokken bile şemayı kurabilmek içindir.
require_once __DIR__ . '/config/pdks.php';
// Sprint Günlük-İşçi-01: çavuş/işçi-kart-havuzu tabloları da aynı sebeple
// (kendiliğinden yüklenmez) BURADAN elle tetiklenir.
require_once __DIR__ . '/config/pdks_gunluk.php';
// Sprint Günlük-İşçi-05, Faz 4: hakediş (çavuş fiyat + hakediş) tabloları
// da AYNI sebeple BURADAN elle tetiklenir. Faz 1-3 tablolarına DOKUNMAZ —
// yalnız KENDİ üç yeni tablosunu additive olarak ekler.
require_once __DIR__ . '/config/pdks_hakedis.php';
// Sprint Günlük-İşçi-06, Faz 5: cari hesap/ödeme tablosu da AYNI sebeple
// BURADAN elle tetiklenir. Faz 1-4 tablolarına DOKUNMAZ — yalnız KENDİ
// tek yeni tablosunu (foreman_payments) additive olarak ekler.
require_once __DIR__ . '/config/pdks_cari.php';

// Çalıştırılacak migrasyon tanımları: kolon eklemeleri (idempotent)
// her biri: [tablo, kolon, "ALTER ... SQL"]
$migrations = [
    ['loading_records', 'ulasim',         "ALTER TABLE `loading_records` ADD COLUMN `ulasim` VARCHAR(100) NOT NULL DEFAULT ''"],
    ['loading_records', 'gidecek_ulke',   "ALTER TABLE `loading_records` ADD COLUMN `gidecek_ulke` VARCHAR(100) NOT NULL DEFAULT ''"],
    ['loading_records', 'brand',          "ALTER TABLE `loading_records` ADD COLUMN `brand` VARCHAR(20) NULL"],
    ['loading_records', 'urun_sahibi_id', "ALTER TABLE `loading_records` ADD COLUMN `urun_sahibi_id` INT NULL DEFAULT NULL"],
    ['loading_records', 'type',           "ALTER TABLE `loading_records` ADD COLUMN `type` VARCHAR(20) NOT NULL DEFAULT 'yukleme'"],
    ['loading_records', 'report_id',      "ALTER TABLE `loading_records` ADD COLUMN `report_id` INT NULL"],
    ['loading_records', 'reported_at',    "ALTER TABLE `loading_records` ADD COLUMN `reported_at` DATETIME NULL"],
    ['loading_records', 'reported_by',    "ALTER TABLE `loading_records` ADD COLUMN `reported_by` INT NULL"],
    ['material_definitions', 'color',     "ALTER TABLE `material_definitions` ADD COLUMN `color` VARCHAR(7) NULL"],
    ['material_definitions', 'max_pallet_count', "ALTER TABLE `material_definitions` ADD COLUMN `max_pallet_count` INT NULL"],
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

$pdks_results = [];   // PDKS tablo migrasyonu sonucu
$pdks_ran     = false;

$pdks_gunluk_results = [];   // Günlük İşçi (çavuş/işçi kartı) tablo migrasyonu sonucu
$pdks_gunluk_ran     = false;

$pdks_hakedis_results = [];   // Hakediş (çavuş fiyat + hakediş) tablo migrasyonu sonucu
$pdks_hakedis_ran     = false;

$pdks_cari_results = [];   // Cari hesap/ödeme tablo migrasyonu sonucu
$pdks_cari_ran     = false;

$pdks_faz8a_results = [];   // Faz 8A (nötr kart / mesai dönemi) migrasyonu sonucu
$pdks_faz8a_ran     = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ne'] ?? '') === 'pdks') {
    csrf_check($_POST['csrf'] ?? null);
    $pdks_ran     = true;
    $pdks_results = pdks_migrate($pdo);
    foreach ($pdks_results as $pr) {
        if ($pr['durum'] === 'olusturuldu') {
            audit_log_event('migrate', 'pdks', null, null,
                ['operation' => 'create_table', 'table' => $pr['tablo']]);
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ne'] ?? '') === 'pdks_gunluk') {
    csrf_check($_POST['csrf'] ?? null);
    $pdks_gunluk_ran     = true;
    $pdks_gunluk_results = pdks_gunluk_migrate($pdo);
    foreach ($pdks_gunluk_results as $pr) {
        if ($pr['durum'] === 'olusturuldu') {
            audit_log_event('migrate', 'pdks_gunluk', null, null,
                ['operation' => 'create_table', 'table' => $pr['tablo']]);
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ne'] ?? '') === 'pdks_hakedis') {
    csrf_check($_POST['csrf'] ?? null);
    $pdks_hakedis_ran     = true;
    $pdks_hakedis_results = pdks_hakedis_migrate($pdo);
    foreach ($pdks_hakedis_results as $pr) {
        if ($pr['durum'] === 'olusturuldu') {
            audit_log_event('migrate', 'pdks_hakedis', null, null,
                ['operation' => 'create_table', 'table' => $pr['tablo']]);
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ne'] ?? '') === 'pdks_cari') {
    csrf_check($_POST['csrf'] ?? null);
    $pdks_cari_ran     = true;
    $pdks_cari_results = pdks_cari_migrate($pdo);
    foreach ($pdks_cari_results as $pr) {
        if ($pr['durum'] === 'olusturuldu') {
            audit_log_event('migrate', 'pdks_cari', null, null,
                ['operation' => 'create_table', 'table' => $pr['tablo']]);
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ne'] ?? '') === 'pdks_gunluk_faz8a') {
    csrf_check($_POST['csrf'] ?? null);
    $pdks_faz8a_ran     = true;
    $pdks_faz8a_results = pdks_gunluk_faz8a_migrate($pdo);
    foreach ($pdks_faz8a_results as $pr) {
        if (in_array($pr['durum'], ['olusturuldu', 'guncellendi', 'kaldirildi', 'calisti'], true)) {
            audit_log_event('migrate', 'pdks_gunluk_faz8a', null, null,
                ['operation' => $pr['adim'], 'durum' => $pr['durum'], 'mesaj' => $pr['mesaj']]);
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

  <?php if ($pdks_ran): ?>
    <div class="card" style="margin:16px 0;padding:16px;">
      <h2 style="margin-top:0;">PDKS Migrasyon Sonucu</h2>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Tablo</th><th>Durum</th><th>Mesaj</th></tr></thead>
          <tbody>
          <?php foreach ($pdks_results as $r):
            $c2 = ['olusturuldu'=>'#1f9d55','var'=>'#555','hata'=>'#c0392b'][$r['durum']] ?? '#333';
            $l2 = ['olusturuldu'=>'✓ Oluşturuldu','var'=>'• Zaten var','hata'=>'✗ HATA'][$r['durum']] ?? $r['durum'];
          ?>
            <tr>
              <td><?= h($r['tablo']) ?></td>
              <td style="color:<?= $c2 ?>;font-weight:600;"><?= h($l2) ?></td>
              <td style="color:<?= $r['durum']==='hata' ? '#c0392b' : '#444' ?>;"><?= h($r['mesaj']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <div class="card" style="margin:16px 0;padding:16px;">
    <h2 style="margin-top:0;">PDKS (Personel) Tabloları</h2>
    <p style="color:#555;max-width:760px;margin-top:0;">
      Personel giriş/çıkış modülünün Faz 1 tabloları. <b>Yalnız yeni tablo oluşturur;
      mevcut hiçbir tabloyu değiştirmez, hiçbir veriyi silmez.</b> Tekrar
      çalıştırmak güvenlidir (idempotent).
    </p>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Tablo</th><th>Durum</th></tr></thead>
        <tbody>
        <?php foreach (array_keys(pdks_tablolar()) as $pt):
          $pe = pdks_tablo_var($pdo, $pt); ?>
          <tr>
            <td><?= h($pt) ?></td>
            <td style="color:<?= $pe ? '#1f9d55' : '#c0392b' ?>;font-weight:600;">
              <?= $pe ? '✓ Var' : '✗ Eksik' ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <form method="post" style="margin-top:16px;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="ne" value="pdks">
      <button type="submit" class="btn btn-primary">PDKS Tablolarını Oluştur</button>
    </form>
    <details style="margin-top:12px;">
      <summary style="cursor:pointer;color:#555;">CREATE TABLE SQL'lerini göster (phpMyAdmin için)</summary>
      <pre style="white-space:pre-wrap;background:#fff;padding:10px;border-radius:6px;overflow:auto;"><?php
        foreach (pdks_tablolar() as $psql) { echo h($psql) . ";\n\n"; }
        echo h(pdks_users_fk_sql()) . ";\n";
      ?></pre>
    </details>
  </div>

  <div class="card" style="margin:16px 0;padding:16px;">
    <h2 style="margin-top:0;">Günlük İşçi Tabloları (Çavuş / İşçi Kart Havuzu — Faz 1)</h2>
    <p style="color:#555;font-size:.9em;">
      Kalıcı personel PDKS'inden AYRI: <code>foremen</code>, <code>worker_types</code>, <code>worker_cards</code>.
      Additive-only — mevcut hiçbir tabloya ALTER/DROP uygulamaz.
      <?php if ($pdks_gunluk_ran): ?>
      <br><strong>Son çalıştırma sonucu:</strong>
        <?php foreach ($pdks_gunluk_results as $pgr): ?>
        <br>&nbsp;&nbsp;<?= h($pgr['tablo']) ?>: <?= h($pgr['durum']) ?> — <?= h($pgr['mesaj']) ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </p>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Tablo</th><th>Durum</th></tr></thead>
        <tbody>
        <?php foreach (array_keys(pdks_gunluk_tablolar()) as $pgt):
          $pge = pdks_gunluk_tablo_var($pdo, $pgt); ?>
          <tr>
            <td><?= h($pgt) ?></td>
            <td style="color:<?= $pge ? '#1f9d55' : '#c0392b' ?>;font-weight:600;">
              <?= $pge ? '✓ Var' : '✗ Eksik' ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <form method="post" style="margin-top:16px;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="ne" value="pdks_gunluk">
      <button type="submit" class="btn btn-primary">Günlük İşçi Tablolarını Oluştur</button>
    </form>
    <details style="margin-top:12px;">
      <summary style="cursor:pointer;color:#555;">CREATE TABLE SQL'lerini göster (phpMyAdmin için)</summary>
      <pre style="white-space:pre-wrap;background:#fff;padding:10px;border-radius:6px;overflow:auto;"><?php
        foreach (pdks_gunluk_tablolar() as $pgsql) { echo h($pgsql) . ";\n\n"; }
      ?></pre>
    </details>
  </div>

  <div class="card" style="margin:16px 0;padding:16px;">
    <h2 style="margin-top:0;">Hakediş Tabloları (Çavuş Fiyatları / Hakediş — Faz 4)</h2>
    <p style="color:#555;font-size:.9em;">
      <code>foreman_worker_rates</code>, <code>foreman_daily_entitlements</code>,
      <code>foreman_daily_entitlement_lines</code>. Additive-only — Faz 1-3
      tablolarına (foremen/worker_types/daily_work_sessions/daily_worker_card_events)
      HİÇ DOKUNMAZ, yalnız KENDİ üç yeni tablosunu ekler.
      <?php if ($pdks_hakedis_ran): ?>
      <br><strong>Son çalıştırma sonucu:</strong>
        <?php foreach ($pdks_hakedis_results as $phr): ?>
        <br>&nbsp;&nbsp;<?= h($phr['tablo']) ?>: <?= h($phr['durum']) ?> — <?= h($phr['mesaj']) ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </p>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Tablo</th><th>Durum</th></tr></thead>
        <tbody>
        <?php foreach (array_keys(pdks_hakedis_tablolar()) as $pht):
          $phe = pdks_hakedis_tablo_var($pdo, $pht); ?>
          <tr>
            <td><?= h($pht) ?></td>
            <td style="color:<?= $phe ? '#1f9d55' : '#c0392b' ?>;font-weight:600;">
              <?= $phe ? '✓ Var' : '✗ Eksik' ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <form method="post" style="margin-top:16px;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="ne" value="pdks_hakedis">
      <button type="submit" class="btn btn-primary">Hakediş Tablolarını Oluştur</button>
    </form>
    <details style="margin-top:12px;">
      <summary style="cursor:pointer;color:#555;">CREATE TABLE SQL'lerini göster (phpMyAdmin için)</summary>
      <pre style="white-space:pre-wrap;background:#fff;padding:10px;border-radius:6px;overflow:auto;"><?php
        foreach (pdks_hakedis_tablolar() as $phsql) { echo h($phsql) . ";\n\n"; }
      ?></pre>
    </details>
  </div>

  <div class="card" style="margin:16px 0;padding:16px;">
    <h2 style="margin-top:0;">Cari Hesap Tabloları (Çavuş Ödeme / Cari — Faz 5)</h2>
    <p style="color:#555;font-size:.9em;">
      <code>foreman_payments</code>. Additive-only — Faz 1-4 tablolarına
      (foremen/.../foreman_daily_entitlements) HİÇ DOKUNMAZ, yalnız KENDİ
      tek yeni tablosunu ekler. Bakiye/ekstre bu tablodan ve KESİN
      hakedişten CANLI türetilir — ikinci bir mutasyona açık defter YOK.
      <?php if ($pdks_cari_ran): ?>
      <br><strong>Son çalıştırma sonucu:</strong>
        <?php foreach ($pdks_cari_results as $pcr): ?>
        <br>&nbsp;&nbsp;<?= h($pcr['tablo']) ?>: <?= h($pcr['durum']) ?> — <?= h($pcr['mesaj']) ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </p>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Tablo</th><th>Durum</th></tr></thead>
        <tbody>
        <?php foreach (array_keys(pdks_cari_tablolar()) as $pct):
          $pce = pdks_cari_tablo_var($pdo, $pct); ?>
          <tr>
            <td><?= h($pct) ?></td>
            <td style="color:<?= $pce ? '#1f9d55' : '#c0392b' ?>;font-weight:600;">
              <?= $pce ? '✓ Var' : '✗ Eksik' ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <form method="post" style="margin-top:16px;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="ne" value="pdks_cari">
      <button type="submit" class="btn btn-primary">Cari Hesap Tablolarını Oluştur</button>
    </form>
    <details style="margin-top:12px;">
      <summary style="cursor:pointer;color:#555;">CREATE TABLE SQL'lerini göster (phpMyAdmin için)</summary>
      <pre style="white-space:pre-wrap;background:#fff;padding:10px;border-radius:6px;overflow:auto;"><?php
        foreach (pdks_cari_tablolar() as $pcsql) { echo h($pcsql) . ";\n\n"; }
      ?></pre>
    </details>
  </div>

  <div class="card" style="margin:16px 0;padding:16px;">
    <h2 style="margin-top:0;">Faz 8A — Nötr İşçi Kartı / Mesai Dönemi</h2>
    <p style="color:#555;font-size:.9em;">
      Dört adım tek çağrıda: ① <code>daily_worker_work_periods</code> tablosunu oluşturur
      (yeni YETKİLİ katılım kaydı) ② <code>worker_cards.worker_type_id</code>'yi NULL kabul
      eder hâle getirir (kart artık nötr) ③ eski <code>uq_dwce_card_day_depo_type</code>
      "aynı kart aynı gün bir kez" kısıtını kaldırır (Faz 8A'nın "aynı gün defalarca
      kullanılabilir" kuralıyla çakışıyordu) ④ Faz 1-7'nin geçmiş GİRİŞ/ÇIKIŞ çiftlerini
      yeni tabloya AKTARIR (backfill — yalnız EKLER, eski satırlara DOKUNMAZ; eski puantaj
      geçmişinin migrasyon SONRASI da okunabilir kalması için gerekli).
      <br><strong>Çalıştırılmadan önce:</strong> mevcut Giriş/Çıkış tarama sayfası ESKİ
      kurallarla (aynı kart/gün/depoda bir kez) çalışmaya devam eder — kod ile migrasyon
      arasında tarama BOZULMAZ. Çalıştırıldıktan HEMEN SONRA yeni "tek açık dönem" kuralı
      devreye girer.
      <?php if ($pdks_faz8a_ran): ?>
      <br><strong>Son çalıştırma sonucu:</strong>
        <?php foreach ($pdks_faz8a_results as $p8r): ?>
        <br>&nbsp;&nbsp;<?= h($p8r['adim']) ?>: <?= h($p8r['durum']) ?> — <?= h($p8r['mesaj']) ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </p>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Kontrol</th><th>Durum</th></tr></thead>
        <tbody>
          <tr>
            <td>daily_worker_work_periods tablosu</td>
            <td style="color:<?= pdks_gunluk_tablo_var($pdo, 'daily_worker_work_periods') ? '#1f9d55' : '#c0392b' ?>;font-weight:600;">
              <?= pdks_gunluk_tablo_var($pdo, 'daily_worker_work_periods') ? '✓ Var' : '✗ Eksik' ?>
            </td>
          </tr>
          <tr>
            <td>Faz 8A şeması TAM HAZIR (yeni tarama mantığı aktif mi)</td>
            <td style="color:<?= pdks_gunluk_faz8a_sema_hazir($pdo) ? '#1f9d55' : '#c0392b' ?>;font-weight:600;">
              <?= pdks_gunluk_faz8a_sema_hazir($pdo) ? '✓ Aktif — yeni tek-açık-dönem kuralı çalışıyor' : '✗ Henüz değil — eski kurallar çalışıyor' ?>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <form method="post" style="margin-top:16px;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="ne" value="pdks_gunluk_faz8a">
      <button type="submit" class="btn btn-primary">Faz 8A Migrasyonunu Çalıştır</button>
    </form>
  </div>

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
