<?php
// =========================================================
// mesai_degerlendirme.php — Faz 8B muhasebe mesai değerlendirmesi
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/auth.php';

$auth_user = require_login();
require_pdks_hakedis('entitlements_finalize');
$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_hakedis_sayfa_kapisi($pdo);
pdks_faz8b_sayfa_kapisi($pdo);

$sessionId = filter_var($_GET['session_id'] ?? $_POST['session_id'] ?? '', FILTER_VALIDATE_INT);
if (!$sessionId) { set_flash('error', 'Geçersiz mesai.'); header('Location: cavus_hakedis.php'); exit; }

$stS = $pdo->prepare("SELECT * FROM daily_work_sessions WHERE id=?");
$stS->execute([$sessionId]);
$oturum = $stS->fetch();
if (!$oturum) { set_flash('error', 'Mesai bulunamadı.'); header('Location: cavus_hakedis.php'); exit; }

$errors = [];
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_hakedis('entitlements_finalize');
    $periodId = filter_var($_POST['period_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
    $attendanceDecision = trim((string)($_POST['attendance_decision'] ?? '')) ?: null;
    $overtimeDecision = trim((string)($_POST['overtime_decision'] ?? '')) ?: null;
    if (!$periodId) {
        $errors[] = 'Mesai dönemi seçilemedi.';
    } else {
        $stOwn = $pdo->prepare("SELECT id FROM daily_worker_work_periods WHERE id=? AND session_id=?");
        $stOwn->execute([$periodId, $sessionId]);
        if (!$stOwn->fetchColumn()) {
            $errors[] = 'Mesai dönemi bu oturuma ait değil.';
        } else {
            $sonuc = pdks_faz8b_degerlendirme_kaydet($periodId, $attendanceDecision, $overtimeDecision, (int)$auth_user['id'], $pdo);
            if ($sonuc['ok']) {
                header('Location: mesai_degerlendirme.php?session_id=' . $sessionId . '&ok=' . urlencode('Değerlendirme kaydedildi.'));
                exit;
            }
            $errors[] = $sonuc['hata'] ?? 'Değerlendirme kaydedilemedi.';
        }
    }
}
if (!$errors && isset($_GET['ok'])) $success = trim((string)$_GET['ok']);

$donemler = pdks_faz8b_oturum_donemleri($sessionId, $pdo);
$ozet = pdks_faz8b_oturum_ozeti($sessionId, $pdo);

render_header('Mesai Değerlendirme');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>
<div class="page-head">
    <h1>🧮 Mesai Değerlendirme — <?= h($oturum['foreman_name_snapshot']) ?></h1>
    <div class="page-head-actions">
        <a href="cavus_hakedis.php?tarih=<?= h($oturum['work_date']) ?>" class="btn">← Hakediş</a>
        <a href="gunluk_isci_puantaj_detay.php?id=<?= (int)$sessionId ?>" class="btn btn-ghost">📅 Puantaj</a>
    </div>
</div>

<?php if ($success !== ''): ?><div class="flash flash-success"><?= h($success) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="flash flash-error"><?= h($e) ?></div><?php endforeach; ?>

<div class="card" style="padding:16px 18px;margin-bottom:18px">
    <strong><?= h(date('d.m.Y', strtotime($oturum['work_date']))) ?><?= $oturum['depo'] ? ' — ' . h($oturum['depo']) : '' ?></strong>
    <div class="pdks-row-sub" style="margin-top:6px">
        9 saat normal mesai · 15 dk tolerans · 8s45dk ve üzeri otomatik Tam · 9 saati aşan ilk 15 dk FM sayılmaz · 16–75 dk = 1 saat, 76–135 dk = 2 saat.
    </div>
    <div class="pdks-row-sub" style="margin-top:6px">
        Hazır: <strong><?= (int)$ozet['hazir'] ?>/<?= (int)$ozet['toplam'] ?></strong>
        · Kısa/eksik mesai kararı bekleyen: <strong><?= (int)$ozet['bekleyen_sinif'] ?></strong>
        · Fazla mesai onayı bekleyen: <strong><?= (int)$ozet['bekleyen_fazla_mesai'] ?></strong>
    </div>
</div>

<?php if (!$donemler): ?>
<div class="pdks-empty"><p>Bu oturumda mesai dönemi bulunamadı.</p></div>
<?php else: ?>
<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr>
    <th>Kart</th><th>Tip</th><th>Giriş</th><th>Çıkış</th><th>Süre</th><th>Giriş Beyanı</th><th>Sistem / Muhasebe</th><th>Fazla Mesai</th><th>İşlem</th>
</tr></thead>
<tbody>
<?php foreach ($donemler as $d): $f = $d['faz8b']; ?>
<tr>
    <td class="pdks-row-name"><?= h($d['card_no']) ?></td>
    <td><?= h($d['worker_type_name_snapshot']) ?></td>
    <td><?= h(date('H:i', strtotime($d['entry_time']))) ?></td>
    <td><?= $d['exit_time'] ? h(date('H:i', strtotime($d['exit_time']))) : '—' ?></td>
    <td><?= $f['toplam_dk'] === null ? '—' : h(sprintf('%ds %02ddk', intdiv((int)$f['toplam_dk'], 60), (int)$f['toplam_dk'] % 60)) ?></td>
    <td><?= h($d['declared_attendance_class'] === 'yarim' ? 'Yarım' : 'Tam') ?></td>
    <td>
        <?php if ($f['sinif_kaynak'] === 'otomatik'): ?>
            <span class="pdks-badge pdks-badge-tamamlandi">Otomatik Tam</span>
        <?php elseif ($f['etkin_sinif']): ?>
            <span class="pdks-badge pdks-badge-acik">Muhasebe: <?= h($f['etkin_sinif'] === 'yarim' ? 'Yarım' : 'Tam') ?></span>
        <?php else: ?>
            <span class="pdks-badge pdks-badge-eksik_cikis">Karar bekliyor</span>
        <?php endif; ?>
    </td>
    <td>
        <?php if ((int)$f['fazla_mesai_saat'] <= 0): ?>—
        <?php else: ?>
            <?= (int)$f['fazla_mesai_saat'] ?> saat aday ·
            <?php if ($f['fazla_mesai_durum'] === 'onayli'): ?><strong>Onaylı</strong>
            <?php elseif ($f['fazla_mesai_durum'] === 'reddedildi'): ?><strong>Reddedildi</strong>
            <?php else: ?><strong>Onay bekliyor</strong><?php endif; ?>
        <?php endif; ?>
    </td>
    <td>
        <form method="post" style="min-width:230px">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="session_id" value="<?= (int)$sessionId ?>">
            <input type="hidden" name="period_id" value="<?= (int)$d['id'] ?>">
            <?php if ($f['sinif_onayi_gerekli']): ?>
            <select name="attendance_decision" required style="margin-bottom:6px">
                <option value="">Tam / Yarım seç</option>
                <option value="tam" <?= ($d['approved_attendance_class'] ?? '') === 'tam' ? 'selected' : '' ?>>Tam Mesai</option>
                <option value="yarim" <?= ($d['approved_attendance_class'] ?? '') === 'yarim' ? 'selected' : '' ?>>Yarım Mesai</option>
            </select>
            <?php endif; ?>
            <?php if ((int)$f['fazla_mesai_saat'] > 0): ?>
            <select name="overtime_decision" required style="margin-bottom:6px">
                <option value="">Fazla mesai kararı</option>
                <option value="onayla" <?= ($d['overtime_approved'] ?? null) !== null && (int)$d['overtime_approved'] === 1 ? 'selected' : '' ?>>Onayla</option>
                <option value="reddet" <?= ($d['overtime_approved'] ?? null) !== null && (int)$d['overtime_approved'] === 0 ? 'selected' : '' ?>>Reddet</option>
            </select>
            <?php endif; ?>
            <button class="btn btn-sm btn-primary" type="submit">Kaydet</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="pdks-cards mobile-only">
<?php foreach ($donemler as $d): $f = $d['faz8b']; ?>
<div class="pdks-card-item">
    <div class="pdks-row-name"><?= h($d['card_no']) ?> · <?= h($d['worker_type_name_snapshot']) ?></div>
    <div class="pdks-row-sub">Giriş <?= h(date('H:i', strtotime($d['entry_time']))) ?> · Çıkış <?= $d['exit_time'] ? h(date('H:i', strtotime($d['exit_time']))) : '—' ?></div>
    <div class="pdks-row-sub">Süre: <?= $f['toplam_dk'] === null ? '—' : h(sprintf('%ds %02ddk', intdiv((int)$f['toplam_dk'], 60), (int)$f['toplam_dk'] % 60)) ?></div>
    <div class="pdks-row-sub">Beyan: <?= h($d['declared_attendance_class'] === 'yarim' ? 'Yarım' : 'Tam') ?></div>
    <div class="pdks-row-sub">Finans: <?= $f['etkin_sinif'] ? h($f['etkin_sinif'] === 'yarim' ? 'Yarım' : 'Tam') : 'Karar bekliyor' ?><?= (int)$f['fazla_mesai_saat'] > 0 ? ' · FM ' . (int)$f['fazla_mesai_saat'] . ' saat / ' . h($f['fazla_mesai_durum']) : '' ?></div>
    <form method="post" style="margin-top:8px">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="session_id" value="<?= (int)$sessionId ?>">
        <input type="hidden" name="period_id" value="<?= (int)$d['id'] ?>">
        <?php if ($f['sinif_onayi_gerekli']): ?>
        <select name="attendance_decision" required>
            <option value="">Tam / Yarım seç</option>
            <option value="tam" <?= ($d['approved_attendance_class'] ?? '') === 'tam' ? 'selected' : '' ?>>Tam Mesai</option>
            <option value="yarim" <?= ($d['approved_attendance_class'] ?? '') === 'yarim' ? 'selected' : '' ?>>Yarım Mesai</option>
        </select>
        <?php endif; ?>
        <?php if ((int)$f['fazla_mesai_saat'] > 0): ?>
        <select name="overtime_decision" required style="margin-top:6px">
            <option value="">Fazla mesai kararı</option>
            <option value="onayla" <?= ($d['overtime_approved'] ?? null) !== null && (int)$d['overtime_approved'] === 1 ? 'selected' : '' ?>>Onayla</option>
            <option value="reddet" <?= ($d['overtime_approved'] ?? null) !== null && (int)$d['overtime_approved'] === 0 ? 'selected' : '' ?>>Reddet</option>
        </select>
        <?php endif; ?>
        <button class="btn btn-sm btn-primary" type="submit" style="margin-top:8px">Kaydet</button>
    </form>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php render_footer(); ?>
