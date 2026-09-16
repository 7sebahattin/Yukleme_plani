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

$ay = trim((string)($_GET['ay'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ay)) {
    $ay = date('Y-m');
}

$cavusId = filter_var(
    $_GET['cavus'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$cavusId = $cavusId === false ? null : (int)$cavusId;

$depo = function_exists('active_depot')
    ? (active_depot() ?? '')
    : '';

$cavuslar = $pdo->query(
    "SELECT id, code, name, is_active
       FROM foremen
      ORDER BY is_active DESC, name ASC"
)->fetchAll();

$faz8aHazir = pdks_gunluk_faz8a_sema_hazir($pdo);

$finansalGosterilebilir =
    pdks_rapor_can('financial')
    && function_exists('pdks_hakedis_sema_hazir')
    && pdks_hakedis_sema_hazir($pdo);

$satirlar = $faz8aHazir
    ? pdks_rapor_cavus_toplu_dokum(
        $ay,
        $depo !== '' ? $depo : null,
        $cavusId,
        $finansalGosterilebilir,
        $pdo
    )
    : [];

$toplamIsci = 0;
$toplamKadin = 0;
$toplamErkek = 0;
$toplamEksik = 0;

foreach ($satirlar as $r) {
    $toplamIsci += (int)$r['toplam_isci'];
    $toplamKadin += (int)$r['kadin'];
    $toplamErkek += (int)$r['erkek'];
    $toplamEksik += (int)$r['eksik_cikis'];
}

render_header('Çavuş Toplu Döküm');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v='
    . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head">
    <div>
        <h1>👷 Çavuş Toplu Döküm</h1>
        <p class="muted" style="margin:4px 0 0">
            Aylık günlük işçi, giriş/çıkış ve hakediş özeti
        </p>
    </div>
    <div class="page-head-actions">
        <a href="raporlar.php" class="btn btn-ghost">← Raporlara Dön</a>
    </div>
</div>

<form method="get" class="pdks-filter-bar">
    <label>
        <span class="form-label">Ay</span>
        <input type="month" name="ay" value="<?= h($ay) ?>">
    </label>

    <label>
        <span class="form-label">Çavuş</span>
        <select name="cavus">
            <option value="">Tüm çavuşlar</option>
            <?php foreach ($cavuslar as $c): ?>
            <option
                value="<?= (int)$c['id'] ?>"
                <?= $cavusId === (int)$c['id'] ? 'selected' : '' ?>
            >
                <?= h($c['name']) ?><?= $c['is_active'] ? '' : ' (pasif)' ?>
            </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>
        <span class="form-label">Depo</span>
        <select disabled>
            <option><?= h($depo !== '' ? $depo : 'Tüm depolar') ?></option>
        </select>
    </label>

    <button class="btn btn-primary" type="submit">Göster</button>
</form>

<?php if (!$faz8aHazir): ?>
<div class="card" style="padding:18px;margin:16px 0">
    <strong>Çavuş Toplu Döküm henüz kullanılamıyor.</strong>
    <p class="muted" style="margin-bottom:0">
        Günlük işçi work-period şeması hazır değil.
    </p>
</div>
<?php else: ?>

<div class="pdks-kiosk-counters rapor-genis" style="margin-bottom:16px">
    <h3><?= h(date('m/Y', strtotime($ay . '-01'))) ?> Özeti</h3>
    <div class="pdks-kiosk-counter-totals">
        <div class="pdks-kiosk-counter-box">
            <div class="lbl">Kadın</div>
            <div class="val"><?= $toplamKadin ?></div>
        </div>
        <div class="pdks-kiosk-counter-box">
            <div class="lbl">Erkek</div>
            <div class="val"><?= $toplamErkek ?></div>
        </div>
        <div class="pdks-kiosk-counter-box">
            <div class="lbl">Toplam İşçi</div>
            <div class="val"><?= $toplamIsci ?></div>
        </div>
        <div class="pdks-kiosk-counter-box eksik">
            <div class="lbl">Eksik Çıkış</div>
            <div class="val"><?= $toplamEksik ?></div>
        </div>
    </div>
</div>

<?php if (!$satirlar): ?>
<div class="pdks-empty">
    <span class="pdks-empty-icon">📭</span>
    <p>Seçilen ay için kayıt bulunamadı.</p>
</div>
<?php else: ?>

<div class="table-wrap">
<table class="data-table" id="ctdTable">
    <thead>
    <tr>
        <th>Tarih</th>
        <th>Çavuş</th>
        <th>Kadın</th>
        <th>Erkek</th>
        <th>Toplam İşçi</th>
        <th>Hakediş</th>
        <th>İlk Giriş</th>
        <th>Son Çıkış</th>
        <th>Eksik Çıkış</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($satirlar as $r):
        $detayUrl = 'cavus_toplu_dokum_detay.php?' . http_build_query([
            'session_id' => (int)$r['session_id'],
            'ay' => $ay,
            'cavus' => $cavusId,
        ]);

        $hakedis = $r['hakedis'];
    ?>
    <tr
        class="ctd-click-row"
        tabindex="0"
        data-href="<?= h($detayUrl) ?>"
        title="Kart dökümünü aç"
        style="cursor:pointer"
    >
        <td><strong><?= h(date('d.m.Y', strtotime($r['tarih']))) ?></strong></td>
        <td>
            <strong><?= h($r['cavus_adi']) ?></strong>
            <div class="muted" style="font-size:.78rem"><?= h($r['cavus_kodu']) ?></div>
        </td>
        <td><?= (int)$r['kadin'] ?></td>
        <td><?= (int)$r['erkek'] ?></td>
        <td><strong><?= (int)$r['toplam_isci'] ?></strong></td>

        <td>
            <?php if (!$finansalGosterilebilir): ?>
                <span class="muted">—</span>
            <?php elseif ($hakedis === null): ?>
                <span class="muted">Hesaplanmadı</span>
            <?php else: ?>
                <strong>
                    <?= h(pdks_rapor_para_formatla($hakedis['total_amount'])) ?>
                    <?= h($hakedis['currency']) ?>
                </strong>
                <div class="muted" style="font-size:.78rem">
                    <?= $hakedis['status'] === 'final' ? 'Kesin' : 'Taslak' ?>
                </div>
            <?php endif; ?>
        </td>

        <td>
            <?= $r['ilk_giris']
                ? h(date('H:i', strtotime($r['ilk_giris'])))
                : '—' ?>
        </td>

        <td>
            <?= $r['son_cikis']
                ? h(date('H:i', strtotime($r['son_cikis'])))
                : '—' ?>
        </td>

        <td>
            <?php if ((int)$r['eksik_cikis'] > 0): ?>
                <span class="pdks-badge pdks-badge-eksik_cikis">
                    <?= (int)$r['eksik_cikis'] ?>
                </span>
            <?php else: ?>
                <span class="pdks-badge pdks-badge-tamamlandi">0</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<p class="muted" style="font-size:.82rem">
    Satıra tıklayarak o günün kart dökümünü açabilirsiniz.
</p>

<script>
(function () {
    document.querySelectorAll('.ctd-click-row').forEach(function (row) {
        function ac() {
            var href = row.getAttribute('data-href');
            if (href) window.location.href = href;
        }

        row.addEventListener('click', ac);
        row.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                ac();
            }
        });
    });
})();
</script>

<?php endif; ?>
<?php endif; ?>

<?php render_footer(); ?>