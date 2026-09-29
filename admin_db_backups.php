<?php
// =========================================================
// admin_db_backups.php — Veritabanı Yedek Yönetimi
// Sprint DB-Backup-01 · DB-Backup-02
// Yalnızca admin erişimine açık.
//
// İndirme bilerek GET'tir (URL biçimi sabit: ?action=download&id=N):
// çapraz köken yanıtı OKUYAMAZ; sahte bir istek yalnız downloaded_at/audit
// yazabilir. Silme ve manuel yedek POST + CSRF.
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

    // Path traversal koruması: yol yalnız dosya ADINDAN kurulur ve
    // gerçek yol backup klasörü içinde olmalı
    $bkp_dir   = realpath(db_backup_dir());
    $real_path = realpath(_bh_backup_path((string)$bkp['filename']));
    if (!$bkp_dir || !$real_path || !str_starts_with($real_path, $bkp_dir . DIRECTORY_SEPARATOR)) {
        set_flash('error', 'Yedek dosyası sunucuda bulunamadı.');
        header('Location: admin_db_backups.php');
        exit;
    }
    if (!is_file($real_path)) {
        set_flash('error', 'Yedek dosyası sunucuda bulunamadı.');
        header('Location: admin_db_backups.php');
        exit;
    }

    // İndirme kaydı
    try {
        $pdo->prepare("UPDATE database_backups SET downloaded_at=?, downloaded_by=? WHERE id=?")
            ->execute([date('Y-m-d H:i:s'), (int)($auth_user['id'] ?? 0), $bkp_id]);
    } catch (PDOException $e) {}

    // Audit
    audit_log_event('database_backup_downloaded', 'system', $bkp_id, null, [
        'filename' => $bkp['filename'],
    ]);

    // Uzun indirme admin'in diğer sekmelerini oturum kilidinde bekletmesin
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @ini_set('zlib.output_compression', '0');
    @set_time_limit(0);

    // Stream
    while (ob_get_level()) { ob_end_clean(); }
    $ct = str_ends_with((string)$bkp['filename'], '.gz') ? 'application/gzip' : 'application/octet-stream';
    header('Content-Type: ' . $ct);
    header('Content-Disposition: attachment; filename="' . basename((string)$bkp['filename']) . '"');
    header('Content-Length: ' . filesize($real_path));
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
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
    if (!empty($result['busy'])) {
        set_flash('info', 'Yedek zaten alınıyor, birkaç dakika sonra listeyi yenileyin.');
    } elseif ($result['ok']) {
        set_flash('success', "Yedek oluşturuldu: {$result['filename']} (" . _bh_fmt_size($result['size']) . ')'
            . (!empty($result['note']) ? ' — ' . $result['note'] : ''));
    } elseif ($result['file_ok'] && !$result['db_ok']) {
        set_flash('error', 'Dosya oluştu ama kayıt tablosuna yazılamadı: ' . ($result['db_error'] ?? 'Bilinmeyen DB hatası') . ' — Dosya: ' . $result['filename']);
    } else {
        set_flash('error', 'Yedek oluşturulamadı: ' . ($result['error'] ?? 'Bilinmeyen hata'));
    }
    header('Location: admin_db_backups.php');
    exit;
}

// ── POST: Tekil silme ──────────────────────────────────────
// Son başarılı yedek silinemez; dosya silinemezse satır da silinmez.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_check($_POST['csrf'] ?? null);
    $del_id = (int)($_POST['id'] ?? 0);

    $lock = _bh_lock_acquire();
    if ($lock === null) {
        set_flash('info', 'Şu anda yedek alınıyor; silme için birkaç dakika sonra tekrar deneyin.');
        header('Location: admin_db_backups.php');
        exit;
    }
    try {
        $st = $pdo->prepare("SELECT * FROM database_backups WHERE id=? LIMIT 1");
        $st->execute([$del_id]);
        $bkp = $st->fetch();
        if (!$bkp) {
            set_flash('error', 'Yedek bulunamadı.');
        } else {
            $engel = null;
            if ($bkp['status'] === 'success') {
                $ok_sayi = (int)$pdo->query("SELECT COUNT(*) FROM database_backups WHERE status='success'")->fetchColumn();
                if ($ok_sayi <= 1) $engel = 'Son başarılı yedek silinemez.';
            }
            $path = _bh_backup_path((string)$bkp['filename']);
            if ($engel === null && $bkp['status'] === 'success' && is_file($path) && !@unlink($path)) {
                $engel = 'Yedek dosyası silinemedi; kayıt korunuyor.';
            }
            if ($engel !== null) {
                set_flash('error', $engel);
            } else {
                $pdo->prepare("DELETE FROM database_backups WHERE id=?")->execute([$del_id]);
                audit_log_event('database_backup_deleted', 'system', $del_id, [
                    'filename'    => $bkp['filename'],
                    'size'        => $bkp['file_size'],
                    'backup_date' => $bkp['backup_date'],
                    'status'      => $bkp['status'],
                ], null);
                set_flash('success', 'Yedek silindi: ' . $bkp['filename']);
            }
        }
    } finally {
        if ($lock !== false) _bh_lock_release($lock);
    }
    header('Location: admin_db_backups.php');
    exit;
}

// ── GET: Liste ─────────────────────────────────────────────
$backups   = list_database_backups($pdo, 30, 'success');
$failures  = list_database_backups($pdo, 10, 'failed');
$last_ok   = last_successful_backup($pdo);
$last_age  = _bh_last_age_hours($last_ok);
$bkp_state = _bh_state_read();
$bkp_dir   = db_backup_dir();
$dir_ok    = is_dir($bkp_dir) && is_writable($bkp_dir);
$dump_ok   = _bh_can_mysqldump();
$ok_count  = (int)count($backups);

// Çöküş izi yalnız son başarılı yedekten SONRA olduysa gösterilir
$crash_goster = !empty($bkp_state['last_crash']) && !empty($bkp_state['crash_at'])
    && (!$last_ok || strtotime((string)$bkp_state['crash_at']) > strtotime((string)$last_ok['created_at']));

render_header('Veritabanı Yedekleri');
render_flash();
?>
<style>
.flash-info { background: var(--warn-soft); color: var(--text); border-color: var(--warn); }
.flash { overflow-wrap: anywhere; }   /* uzun yedek dosya adı mobilde taşmasın */
.bkp-uyari {
    background: var(--warn-soft); border: 1.5px solid var(--warn); border-radius: 6px;
    padding: 10px 14px; margin-bottom: 14px; font-size: .88rem; color: var(--text);
}
.bkp-uyari > div + div { margin-top: 4px; }
.bkp-stat-row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
.bkp-stat {
    background: var(--surface-2); border: 1px solid var(--border); border-radius: 6px;
    padding: 8px 14px; font-size: .85rem; color: var(--muted); min-width: 0;
}
.bkp-stat strong { display: block; font-size: 1.02rem; color: var(--text); overflow-wrap: anywhere; }
.bkp-ok   { color: var(--success); font-weight: 700; white-space: nowrap; }
.bkp-fail { color: var(--danger);  font-weight: 700; white-space: nowrap; }
.bkp-note { font-size: .78rem; color: var(--muted); margin-top: 3px; overflow-wrap: anywhere; min-width: 20ch; max-width: 42ch; }
.bkp-fail-box .bkp-note { color: var(--danger); }
.bkp-dosya { font-size: .78rem; color: var(--muted); overflow-wrap: anywhere; min-width: 14ch; }
.bkp-muted { font-size: .78rem; color: var(--muted); overflow-wrap: anywhere; }
.bkp-actions { display: flex; gap: 6px; justify-content: flex-end; flex-wrap: nowrap; align-items: center; }
.bkp-actions form { margin: 0; }
.bkp-fail-box { margin-top: 16px; }
.bkp-fail-box > summary { cursor: pointer; font-weight: 600; padding: 6px 0; color: var(--text); }
.bkp-fail-box[open] > summary { margin-bottom: 8px; }
.bkp-foot { margin-top: 14px; font-size: .78rem; color: var(--muted); }
.bkp-tarih { white-space: nowrap; }
@media (max-width: 767px) {
    .bkp-col-opt { display: none; }
    .bkp-table th, .bkp-table td { padding: 8px 8px; }
    .bkp-note { min-width: 0; max-width: none; }
    .bkp-tarih { white-space: normal; min-width: 5.5em; }
    .bkp-actions { flex-wrap: wrap; }
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

<?php if (!$last_ok || $last_age > DB_BACKUP_STALE_SAAT || $crash_goster): ?>
<div class="bkp-uyari" role="status">
    <?php if (!$last_ok): ?>
    <div>⚠️ Henüz başarılı bir veritabanı yedeği yok.</div>
    <?php elseif ($last_age > DB_BACKUP_STALE_SAAT): ?>
    <div>⚠️ Son başarılı yedek: <strong><?= h(_bh_yas_metni((float)$last_age)) ?> önce</strong> (<?= h(fmt_datetime((string)$last_ok['created_at'])) ?>).</div>
    <?php endif; ?>
    <?php if ($crash_goster): ?>
    <div>⚠️ Son otomatik deneme yarıda kesildi (<?= h(fmt_datetime((string)$bkp_state['crash_at'])) ?>): <?= h(mb_substr((string)$bkp_state['last_crash'], 0, 300)) ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$dir_ok): ?>
<div class="bkp-uyari">
    ⚠️ Yedek klasörü oluşturulamıyor veya yazılamıyor: <code><?= h(_bh_display_dir()) ?></code><br>
    Sunucu kullanıcısının bu klasöre yazma yetkisi olmalı.
</div>
<?php endif; ?>

<!-- Durum kartları -->
<div class="bkp-stat-row">
    <div class="bkp-stat">
        <strong><?= h($last_ok ? fmt_datetime((string)$last_ok['created_at']) : '—') ?></strong>
        Son başarılı yedek
    </div>
    <div class="bkp-stat">
        <strong><?= $ok_count ?></strong>
        Başarılı yedek (son 30)
    </div>
    <div class="bkp-stat">
        <strong><?= $dump_ok ? '✅ mysqldump' : '⚠️ PDO fallback' ?></strong>
        Yedek yöntemi
    </div>
    <div class="bkp-stat">
        <strong><?= h(_bh_display_dir()) ?></strong>
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

<!-- Başarılı yedekler -->
<?php if (empty($backups)): ?>
<p style="padding:24px;text-align:center;color:var(--muted)">Henüz başarılı yedek yok.</p>
<?php else: ?>
<div class="table-wrap">
<table class="data-table bkp-table">
    <thead>
        <tr>
            <th class="bkp-col-opt">#</th>
            <th>Tarih</th>
            <th class="bkp-col-opt">Dosya</th>
            <th>Boyut</th>
            <th class="bkp-col-opt">Yöntem</th>
            <th>Durum</th>
            <th class="bkp-col-opt">Oluşturan</th>
            <th class="bkp-col-opt">İndirildi</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($backups as $b):
        $file_exists = is_file(_bh_backup_path((string)$b['filename']));
    ?>
        <tr>
            <td class="bkp-col-opt bkp-muted"><?= (int)$b['id'] ?></td>
            <td class="bkp-tarih"><?= h(fmt_datetime((string)$b['created_at'])) ?></td>
            <td class="bkp-col-opt bkp-dosya"><?= h($b['filename']) ?></td>
            <td style="white-space:nowrap"><?= h(_bh_fmt_size($b['file_size'] !== null ? (int)$b['file_size'] : null)) ?></td>
            <td class="bkp-col-opt bkp-muted"><?= h($b['method'] ?? '—') ?></td>
            <td>
                <span class="bkp-ok">BAŞARILI</span>
                <?php if (!empty($b['error_message'])): ?>
                <div class="bkp-note"><?= h(mb_substr((string)$b['error_message'], 0, 300)) ?></div>
                <?php endif; ?>
            </td>
            <td class="bkp-col-opt" style="font-size:.8rem"><?= h($b['created_by_name'] ?? '—') ?></td>
            <td class="bkp-col-opt bkp-muted">
                <?= $b['downloaded_at'] ? h(fmt_datetime((string)$b['downloaded_at'])) . ' / ' . h($b['downloaded_by_name'] ?? '?') : '—' ?>
            </td>
            <td>
                <div class="bkp-actions">
                <?php if ($file_exists): ?>
                <a href="admin_db_backups.php?action=download&id=<?= (int)$b['id'] ?>"
                   class="btn btn-sm btn-ghost" style="white-space:nowrap">⬇ İndir</a>
                <?php else: ?>
                <span class="bkp-muted">dosya yok</span>
                <?php endif; ?>
                <?php if ($ok_count > 1): ?>
                <form method="post" action="admin_db_backups.php"
                      onsubmit="return confirm('Bu yedek kalıcı olarak silinsin mi?');">
                    <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id"     value="<?= (int)$b['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-ghost" aria-label="Yedeği sil">🗑</button>
                </form>
                <?php endif; ?>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<!-- Başarısız denemeler (ayrı — başarılıları ekrandan itmesin) -->
<?php if (!empty($failures)): ?>
<details class="bkp-fail-box">
    <summary>Son başarısız denemeler (<?= count($failures) ?>)</summary>
    <div class="table-wrap">
    <table class="data-table bkp-table">
        <thead>
            <tr>
                <th>Tarih</th>
                <th class="bkp-col-opt">Yöntem</th>
                <th>Hata</th>
                <th class="bkp-col-opt">Oluşturan</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($failures as $f): ?>
            <tr>
                <td class="bkp-tarih"><?= h(fmt_datetime((string)$f['created_at'])) ?></td>
                <td class="bkp-col-opt bkp-muted"><?= h($f['method'] ?? '—') ?></td>
                <td>
                    <span class="bkp-fail">BAŞARISIZ</span>
                    <div class="bkp-note"><?= h(mb_substr((string)($f['error_message'] ?? 'Sebep kaydedilmemiş'), 0, 300)) ?></div>
                </td>
                <td class="bkp-col-opt" style="font-size:.8rem"><?= h($f['created_by_name'] ?? '—') ?></td>
                <td>
                    <form method="post" action="admin_db_backups.php" style="margin:0"
                          onsubmit="return confirm('Bu başarısız deneme kaydı silinsin mi?');">
                        <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id"     value="<?= (int)$f['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-ghost" aria-label="Kaydı sil">🗑</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</details>
<?php endif; ?>

<p class="bkp-foot">
    <?= (int)DB_BACKUP_KEEP_DAYS ?> günden eski yedekler otomatik temizlenir; en yeni <?= (int)DB_BACKUP_MIN_KEEP ?> başarılı yedek her zaman korunur.
    Yedekler <code><?= h(_bh_display_dir()) ?></code> klasöründe tutulur.
</p>

<?php render_footer(); ?>
