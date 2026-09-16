<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_cari.php';
require_once __DIR__ . '/config/pdks_rapor.php';
require_once __DIR__ . '/config/pdks_faz8e.php';
require_once __DIR__ . '/config/auth.php';

$auth_user = require_login();
require_pdks_rapor();

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);

$sessionId = filter_var(
    $_GET['session_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$sessionId = $sessionId === false ? 0 : (int)$sessionId;

$depo = function_exists('active_depot')
    ? (active_depot() ?? '')
    : '';

$data = pdks_rapor_cavus_kart_dokumu(
    $sessionId,
    $depo !== '' ? $depo : null,
    $pdo
);

$session = $data['session'];
$cards = $data['cards'];
$manuelCikisYetkili = pdks_hakedis_can('entitlements_finalize') && $depo !== '';
$manuelGecmis = [];
$manuelIds = array_column(array_filter($cards, fn($c) => ($c['exit_source'] ?? '') === 'manual'), 'period_id');
if ($manuelIds) {
    $ph = implode(',', array_fill(0, count($manuelIds), '?'));
    $stAudit = $pdo->prepare(
        "SELECT al.record_id, al.new_values, al.created_at,
                COALESCE(u.display_name, u.username, '—') AS actor
           FROM audit_log al
           LEFT JOIN users u ON u.id = al.user_id
          WHERE al.module = 'daily_worker_work_periods' AND al.action = 'manuel_cikis'
            AND al.record_id IN ($ph)
          ORDER BY al.id DESC"
    );
    $stAudit->execute($manuelIds);
    foreach ($stAudit->fetchAll() as $row) {
        $id = (int)$row['record_id'];
        if (!isset($manuelGecmis[$id])) {
            $manuelGecmis[$id] = ['data' => json_decode((string)$row['new_values'], true) ?: [],
                'actor' => $row['actor'], 'created_at' => $row['created_at']];
        }
    }
}

$ay = trim((string)($_GET['ay'] ?? ''));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ay)) {
    $ay = $session
        ? date('Y-m', strtotime($session['tarih']))
        : date('Y-m');
}

$cavusId = filter_var(
    $_GET['cavus'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$cavusId = $cavusId === false ? null : (int)$cavusId;

$geriUrl = 'cavus_toplu_dokum.php?' . http_build_query(
    array_filter([
        'ay' => $ay,
        'cavus' => $cavusId,
    ], fn($v) => $v !== null && $v !== '')
);

render_header('Çavuş Kart Dökümü');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v='
    . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head">
    <div>
        <h1>📇 Kart Dökümü</h1>
        <?php if ($session): ?>
        <p class="muted" style="margin:4px 0 0">
            <?= h($session['cavus_adi']) ?>
            · <?= h(date('d.m.Y', strtotime($session['tarih']))) ?>
            · <?= h($session['depo']) ?>
        </p>
        <?php endif; ?>
    </div>
    <div class="page-head-actions">
        <a href="<?= h($geriUrl) ?>" class="btn btn-ghost">← Aylık Döküme Dön</a>
    </div>
</div>

<?php if (!$session): ?>
<div class="pdks-empty">
    <span class="pdks-empty-icon">⚠️</span>
    <p>Oturum bulunamadı veya seçili depoya ait değil.</p>
</div>
<?php else: ?>

<?php
$eksik = 0;
foreach ($cards as $c) {
    if ($c['eksik_cikis']) $eksik++;
}
?>

<div class="pdks-kiosk-counters rapor-genis" style="margin-bottom:16px">
    <h3><?= h($session['cavus_adi']) ?></h3>
    <div class="pdks-kiosk-counter-totals">
        <div class="pdks-kiosk-counter-box">
            <div class="lbl">Kart / Katılım</div>
            <div class="val"><?= count($cards) ?></div>
        </div>
        <div class="pdks-kiosk-counter-box eksik">
            <div class="lbl">Eksik Çıkış</div>
            <div class="val"><?= $eksik ?></div>
        </div>
        <div class="pdks-kiosk-counter-box">
            <div class="lbl">Oturum</div>
            <div class="val" style="font-size:1rem">
                <?= h($session['oturum_durumu'] === 'closed' ? 'Kapalı' : 'Açık') ?>
            </div>
        </div>
    </div>
</div>

<?php if (!$cards): ?>
<div class="pdks-empty">
    <span class="pdks-empty-icon">📭</span>
    <p>Bu oturumda kart kaydı bulunamadı.</p>
</div>
<?php else: ?>

<div class="table-wrap">
<table class="data-table">
    <thead>
    <tr>
        <th>Kart No</th>
        <th>Okunan UID</th>
        <th>İşçi Tipi</th>
        <th>Giriş Tarih / Saat</th>
        <th>Çıkış Tarih / Saat</th>
        <th>Durum</th>
        <?php if ($manuelCikisYetkili): ?><th>İşlem</th><?php endif; ?>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($cards as $c): ?>
    <tr>
        <td><strong><?= h($c['card_no']) ?></strong></td>
        <td><code><?= h($c['okunan_uid']) ?></code></td>
        <td><?= h($c['isci_tipi']) ?></td>
        <td>
            <?= h(date('d.m.Y H:i:s', strtotime($c['entry_time']))) ?>
        </td>
        <td>
            <?= $c['exit_time']
                ? h(date('d.m.Y H:i:s', strtotime($c['exit_time'])))
                : '—' ?>
        </td>
        <td>
            <?php if ($c['eksik_cikis']): ?>
                <span class="pdks-badge pdks-badge-eksik_cikis">
                    Eksik Çıkış
                </span>
            <?php else: ?>
                <span class="pdks-badge pdks-badge-tamamlandi">
                    <?= $c['exit_source'] === 'manual' ? 'Tamamlandı · Manuel' : 'Tamamlandı' ?>
                </span>
                <?php if ($c['exit_source'] === 'manual' && isset($manuelGecmis[$c['period_id']])):
                    $gecmis = $manuelGecmis[$c['period_id']]; ?>
                    <div class="muted" style="font-size:.78rem;margin-top:4px">
                        <?= h($gecmis['data']['reason'] ?? '') ?>
                        <?php if (($gecmis['data']['note'] ?? '') !== ''): ?> · <?= h($gecmis['data']['note']) ?><?php endif; ?>
                        · <?= h($gecmis['actor']) ?>
                        · <?= h(date('d.m.Y H:i', strtotime($gecmis['created_at']))) ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </td>
        <?php if ($manuelCikisYetkili): ?>
        <td>
            <?php if ($c['eksik_cikis'] && $c['exit_time'] === null): ?>
                <a class="btn btn-sm btn-primary" href="manuel_cikis.php?<?= h(http_build_query([
                    'period_id' => $c['period_id'], 'session_id' => $sessionId,
                    'ay' => $ay, 'cavus' => $cavusId,
                ])) ?>">Manuel Çıkış Yap</a>
            <?php else: ?>—<?php endif; ?>
        </td>
        <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php endif; ?>
<?php endif; ?>

<?php render_footer(); ?>
