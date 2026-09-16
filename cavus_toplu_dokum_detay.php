<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_cari.php';
require_once __DIR__ . '/config/pdks_rapor.php';
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
                    Tamamlandı
                </span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php endif; ?>
<?php endif; ?>

<?php render_footer(); ?>