<?php
// =========================================================
// admin_db_backups.php — Veritabanı Yedek Yönetimi
// Sprint DB-Backup-01
// Yalnızca admin erişimine açık.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db_backup_helpers.php';
$auth_user = require_login();
if (!is_admin()) { forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.'); }

$pdo = db();

// ── GET: İndir (download stream) ──────────────────────────
if (($_GET['action'] ?? '') === 'download') {
    $bkp_id = (int)($_GET['id'] ?? 0);
    if ($bkp_id <= 0) {
        set_flash('error', 'Geçersiz yedek ID.');
        header('Location: admin_db_backups.php');
        exit;
    }

    $row = $pdo->prepare("SELECT * FROM database_backups WHERE id=? LIMIT 1");
    $row->execute([$bkp_id]);
    $bkp = $row->fetch();

    if (!$bkp) {
        set_flash('error', 'Yedek bulunamadı.');
        header('Location: admin_db_backups.php');
        exit;
    }
    if ($bkp['status'] !== 'success') {
        set_flash('error', 'Bu yedek başarısız durumda, indirilecek dosya yok.');
        header('Location: admin_db_backups.php');
        exit;
    }

    // Path traversal koruması: dosya yolu backup klasörü içinde olmalı
    $bkp_dir  = realpath(db_backup_dir());
    $real_path = realpath((string)$bkp['file_path']);
    if (!$bkp_dir || !$real_path || !str_starts_with($real_path, $bkp_dir . DIRECTORY_SEPARATOR)) {
        set_flash('error', 'Güvenlik hatası: geçersiz dosya yolu.');
        header('Location: admin_db_backups.php');
        exit;
    }
    if (!file_exists($real_path)) {
        set_flash('error', 'Yedek dosyası sunucuda bulunamadı.');
        header('Location: admin_db_backups.php');
        exit;
    }

    // İndirme kaydı
    try {
        $pdo->prepare("UPDATE database_backups SET downloaded_at=NOW(), downloaded_by=? WHERE id=?")
            ->execute([(int)($auth_user['id'] ?? 0), $bkp_id]);
    } catch (PDOException $e) {}

    // Audit
    audit_log_event('database_backup_downloaded', 'system', $bkp_id, null, [
        'filename' => $bkp['filename'],
    ]);

    // Stream
    while (ob_get_level()) { ob_end_clean(); }
    $ct = str_ends_with((string)$bkp['filename'], '.gz') ? 'application/gzip' : 'application/octet-stream';
    header('Content-Type: ' . $ct);
    header('Content-Disposition: attachment; filename="' . basename((string)$bkp['filename']) . '"');
    header('Content-Length: ' . filesize($real_path));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    readfile($real_path);
    exit;
}

// ── POST: Manuel backup ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backup_now') {
    csrf_check($_POST['csrf'] ?? null);

    if (empty($_POST['onay_cb'])) {
        set_flash('error', '"Yedek alınacağını onaylıyorum" kutusu işaretlenmedi.');
        header('Location: admin_db_backups.php');
        exit;
    }

    $result = create_database_backup($pdo, (int)($auth_user['id'] ?? 0), 'manual_admin');
    if ($result['ok']) {
        set_flash('success', "Yedek oluşturuldu: {$result['filename']} (" . number_format((int)$result['size'] / 1024, 1) . " KB)");
    } elseif ($result['file_ok'] && !$result['db_ok']) {
        set_flash('error', 'Dosya oluştu ama kayıt tablosuna yazılamadı: ' . ($result['db_error'] ?? 'Bilinmeyen DB hatası') . ' — Dosya: ' . $result['filename']);
    } else {
        set_flash('error', 'Yedek oluşturulamadı: ' . ($result['error'] ?? 'Bilinmeyen hata'));
    }
    header('Location: admin_db_backups.php');
    exit;
}

// ── GET: Liste ─────────────────────────────────────────────
$backups   = list_database_backups($pdo, 30);
$bkp_dir   = db_backup_dir();
$dir_ok    = is_dir($bkp_dir) && is_writable($bkp_dir);
$mysqldump = _bh_find_mysqldump();

render_header('Veritabanı Yedekleri');
render_flash();
?>
<style>
.bkp-page-warn {
    background: #fef3c7; border: 1.5px solid #f59e0b; border-radius: 6px;
    padding: 10px 14px; margin-bottom: 14px; font-size: .85rem; color: #92400e;
}
.bkp-stat-row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
.bkp-stat { background: #f1f5f9; border-radius: 6px; padding: 8px 14px; font-size: .85rem; }
.bkp-stat strong { display: block; font-size: 1.05rem; }
table.bkp-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
table.bkp-table th, table.bkp-table td { border: 1px solid #e2e8f0; padding: 6px 9px; text-align: left; }
table.bkp-table th { background: #f8fafc; font-weight: 700; }
.bkp-ok   { color: #16a34a; font-weight: 700; }
.bkp-fail { color: #dc2626; font-weight: 700; }
.bkp-table-wrap { overflow-x: auto; }
@media (max-width: 767px) {
    table.bkp-table { font-size: .78rem; }
    table.bkp-table th, table.bkp-table td { padding: 5px 6px; }
}
</style>

<div class="page-head">
    <div>
        <h2 class="page-title">🗄️ Veritabanı Yedekleri</h2>
        <p style="color:var(--text-muted);font-size:.85rem;margin-top:2px">
            Son yedekler — otomatik (17:00 sonrası) ve manuel.
        </p>
    </div>
    <a href="index.php" class="btn btn-sm btn-secondary">← Ana Sayfa</a>
</div>

<?php if (!$dir_ok): ?>
<div class="bkp-page-warn">
    ⚠️ Yedek klasörü oluşturulamıyor veya yazılamıyor: <code><?= h($bkp_dir) ?></code><br>
    Sunucu kullanıcısının bu klasöre yazma yetkisi olmalı.
</div>
<?php endif; ?>

<!-- Durum kartları -->
<div class="bkp-stat-row">
    <div class="bkp-stat">
        <strong><?= count($backups) ?></strong>
        Son kayıt (30 adet max)
    </div>
    <div class="bkp-stat">
        <strong><?= $mysqldump !== '' ? '✅ mysqldump' : '⚠️ PDO fallback' ?></strong>
        Yedek yöntemi
    </div>
    <div class="bkp-stat">
        <strong><?= h($bkp_dir) ?></strong>
        Klasör
    </div>
</div>

<!-- Manuel backup formu -->
<div class="card" style="margin-bottom:16px;padding:14px 16px">
    <form method="post" action="admin_db_backups.php" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="backup_now">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.9rem">
            <input type="checkbox" name="onay_cb" value="1" required>
            Şimdi yedek alınacağını onaylıyorum
        </label>
        <button type="submit" class="btn btn-sm btn-primary">📦 Şimdi Yedek Al</button>
    </form>
</div>

<!-- Yedek listesi -->
<?php if (empty($backups)): ?>
<p style="padding:24px;text-align:center;color:#64748b">Henüz yedek alınmamış.</p>
<?php else: ?>
<div class="bkp-table-wrap card" style="padding:0">
<table class="bkp-table">
    <thead>
        <tr>
            <th>#</th>
            <th>Tarih</th>
            <th>Dosya</th>
            <th>Boyut</th>
            <th>Yöntem</th>
            <th>Durum</th>
            <th>Oluşturan</th>
            <th>İndirildi</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($backups as $b):
        $file_exists = file_exists((string)$b['file_path']);
        $kb = $b['file_size'] > 0 ? number_format((int)$b['file_size'] / 1024, 1) . ' KB' : '—';
    ?>
        <tr>
            <td style="color:#9ca3af"><?= (int)$b['id'] ?></td>
            <td><?= h($b['backup_date']) ?></td>
            <td style="font-size:.8rem;color:#374151"><?= h($b['filename']) ?></td>
            <td style="white-space:nowrap"><?= h($kb) ?></td>
            <td style="font-size:.78rem;color:#6b7280"><?= h($b['method'] ?? '—') ?></td>
            <td class="<?= $b['status'] === 'success' ? 'bkp-ok' : 'bkp-fail' ?>">
                <?= $b['status'] === 'success' ? 'BAŞARILI' : 'BAŞARISIZ' ?>
                <?php if ($b['status'] === 'failed' && $b['error_message']): ?>
                <span title="<?= h($b['error_message']) ?>" style="cursor:help">ⓘ</span>
                <?php endif; ?>
            </td>
            <td style="font-size:.8rem"><?= h($b['created_by_name'] ?? '—') ?></td>
            <td style="font-size:.78rem;color:#6b7280">
                <?= $b['downloaded_at'] ? date('d.m H:i', strtotime($b['downloaded_at'])) . ' / ' . h($b['downloaded_by_name'] ?? '?') : '—' ?>
            </td>
            <td>
                <?php if ($b['status'] === 'success' && $file_exists): ?>
                <a href="admin_db_backups.php?action=download&id=<?= (int)$b['id'] ?>"
                   class="btn btn-sm btn-ghost" style="white-space:nowrap">⬇ İndir</a>
                <?php elseif ($b['status'] === 'success' && !$file_exists): ?>
                <span style="font-size:.75rem;color:#9ca3af">dosya yok</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<p style="margin-top:14px;font-size:.78rem;color:#9ca3af">
    14 günden eski yedekler otomatik temizlenir. Yedekler <code><?= h($bkp_dir) ?></code> klasöründe tutulur.
</p>

<?php render_footer(); ?>
