<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_cari.php';
require_once __DIR__ . '/config/pdks_rapor.php';
require_once __DIR__ . '/config/pdks_faz8b_cavus_b.php';
require_once __DIR__ . '/config/auth.php';

$auth_user = require_login();
require_pdks_rapor();

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);

$ay = trim((string)($_GET['ay'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ay)) {
    $ay = date('Y-m');
}
$start = $ay . '-01';
$end = date('Y-m-t', strtotime($start));

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

$bKapanislar = ($finansalGosterilebilir && pdks_faz8b_cavus_ucret_b_sema_hazir($pdo))
    ? pdks_faz8b_cavus_ucret_b_kapanis_listesi($cavusId, $start, $end, 'valid', $pdo)
    : [];

$toplamIsci = 0;
$toplamKadin = 0;
$toplamErkek = 0;
$toplamKarisik = 0;   // v295: atanmamış Karışık — Kadın+Erkek+Karışık = Toplam
$toplamEksik = 0;

foreach ($satirlar as $r) {
    $toplamIsci += (int)$r['toplam_isci'];
    $toplamKadin += (int)$r['kadin'];
    $toplamErkek += (int)$r['erkek'];
    $toplamKarisik += (int)($r['karisik'] ?? 0);
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
        <a href="<?= h('cavus_toplu_dokum_yazdir.php?' . http_build_query(array_filter(['ay' => $ay, 'cavus' => $cavusId], fn($v) => $v !== null && $v !== ''))) ?>" class="btn" target="_blank" rel="noopener">🖨️ Yazdır</a>
        <a href="personel_takip.php" class="btn btn-geri">← Personel Takibi</a>
    </div>
</div>

<form method="get" class="pdks-filter-bar" data-oto-filtre>
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

    <?= pdks_oto_filtre_noscript('Göster') ?>
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
        <?php if ($toplamKarisik > 0): ?>
        <div class="pdks-kiosk-counter-box">
            <div class="lbl">Karışık (atanmamış)</div>
            <div class="val"><?= $toplamKarisik ?></div>
        </div>
        <?php endif; ?>
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
    <?php $gSon = null; $gNo = 1; /* v296-B: gün grubu — aynı tarih aynı ton, tarih değişince ton ve üst çizgi değişir */
    foreach ($satirlar as $r):
        $gYeni = ($gSon !== $r['tarih']);
        if ($gYeni) { $gNo = 1 - $gNo; $gSon = $r['tarih']; }
        $gSinif = 'ctd-g' . $gNo . ($gYeni ? ' ctd-gyeni' : '');
        $detayUrl = 'cavus_toplu_dokum_detay.php?' . http_build_query([
            'session_id' => (int)$r['session_id'],
            'ay' => $ay,
            'cavus' => $cavusId,
        ]);

        $hakedis = $r['hakedis'];
    ?>
    <tr
        class="pdks-satir-link <?= $gSinif ?>"
        tabindex="0"
        data-href="<?= h($detayUrl) ?>"
        title="Kart dökümünü aç"
    >
        <td><a href="<?= h($detayUrl) ?>" class="pdks-satir-ana"><strong><?= h(date('d.m.Y', strtotime($r['tarih']))) ?></strong></a></td>
        <td>
            <strong><?= h($r['cavus_adi']) ?></strong>
            <div class="muted" style="font-size:.78rem"><?= h($r['cavus_kodu']) ?></div>
        </td>
        <td><?= (int)$r['kadin'] ?></td>
        <td><?= (int)$r['erkek'] ?></td>
        <td><strong><?= (int)$r['toplam_isci'] ?></strong><?php if ((int)($r['karisik'] ?? 0) > 0): ?><div class="muted" style="font-size:.78rem"><?= (int)$r['karisik'] ?> Karışık</div><?php endif; ?></td>

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


<?php endif; ?>

<?php if ($bKapanislar): ?>
<h2 style="font-size:1.05rem;margin-top:24px">🧮 Çavuş Hakedişi (Yöntem B) — <?= h(date('m/Y', strtotime($ay . '-01'))) ?> Kapanışları</h2>
<p class="muted" style="font-size:.82rem">Kapanış ödeme tarihine göre bu aya düşer; depo filtresi uygulanmaz (dönem birden çok gün/depoyu kapsar).</p>
<div class="table-wrap">
<table class="data-table">
<thead><tr><th>Kapanış Tarihi</th><th>Çavuş</th><th>Devreden</th><th>Dönem Kişi-Gün</th><th>Hakediş Adedi</th><th>Birim Ücret</th><th>Tutar</th><th>Yeni Devir</th></tr></thead>
<tbody>
<?php $bToplamlar = []; foreach ($bKapanislar as $k): $bToplamlar[$k['currency']] = ($bToplamlar[$k['currency']] ?? 0) + (float)$k['amount']; ?>
<tr>
    <td><?= h(date('d.m.Y', strtotime($k['closure_date']))) ?></td>
    <td><?= h($k['foreman_name']) ?></td>
    <td><?= (int)$k['carry_in'] ?></td>
    <td><?= (int)$k['period_person_days'] ?></td>
    <td><strong><?= (int)$k['earned_units'] ?></strong></td>
    <td><?= h(number_format((float)$k['unit_rate'], 2, ',', '.')) ?> <?= h($k['currency']) ?></td>
    <td><strong><?= h(number_format((float)$k['amount'], 2, ',', '.')) ?> <?= h($k['currency']) ?></strong></td>
    <td><?= (int)$k['carry_out'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p style="font-weight:700">
<?php foreach ($bToplamlar as $cur => $tp): ?>
Toplam: <?= h(number_format($tp, 2, ',', '.')) ?> <?= h($cur) ?><br>
<?php endforeach; ?>
</p>
<?php endif; ?>

<?php endif; ?>

<?php pdks_liste_ui_js(); ?>
<?php render_footer(); ?>