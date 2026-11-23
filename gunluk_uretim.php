<?php
// =========================================================
// gunluk_uretim.php — Günlük Üretim (v507, Faz 1)
//
// Seçili ay için gün gün: işlenen mal (net kg) + personel takibinden işçi sayısı / çalışma saati
// → işçi başına verim (kg/işçi, kg/saat, kasa/işçi). Etkileşimli grafik (Chart.js, yerel dosya) + tablo
// + son 12 ay özeti. SALT OKUNUR; veri girişi gunluk_uretim_yukle.php.
// Yetki: uretim.read (admin her zaman). Depo: aktif depo.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_cari.php';
require_once __DIR__ . '/config/pdks_rapor.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/uretim.php';

$auth_user = require_login();
if (!can_uretim('read')) forbidden('Günlük Üretim sayfası için yetkiniz yok. (Gerekli yetki: uretim.read)');

$pdo = db();
$depo = (string)(active_depot() ?? '');
$hazir = uretim_hazir($pdo);

$ay = uretim_ay_gecerli(trim((string)($_GET['ay'] ?? ''))) ?? date('Y-m');
if ($ay > date('Y-m')) $ay = date('Y-m');
$oncekiAy = date('Y-m', strtotime($ay . '-01 -1 month'));
$sonrakiAy = date('Y-m', strtotime($ay . '-01 +1 month'));

$veri = $hazir ? uretim_ay_verisi($pdo, $ay, $depo) : ['gunler' => [], 'ozet' => [], 'personel_var' => false, 'saat_var' => false];
$aylik = $hazir ? uretim_aylik_ozet($pdo, $depo, $ay, 12) : [];
$o = $veri['ozet'];

$aylarTr = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
$ayEtiket = static fn(string $a): string => $aylarTr[(int)substr($a, 5, 2)] . ' ' . substr($a, 0, 4);
$sayi = static fn($n, int $d = 0): string => $n === null ? '—' : number_format((float)$n, $d, ',', '.');
$gunAd = [1 => 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'];
$durumEt = ['tamam' => ['Tamam', 'yeni'], 'personel_yok' => ['İşçi kaydı yok', 'uyari'], 'uretim_yok' => ['Mal girilmemiş', 'hata'], 'bos' => ['', '']];

// Grafik verisi (yalnız veri olan günler; boş günler eksen sürekliliği için null)
$grafik = ['etiket' => [], 'kg' => [], 'isci' => [], 'saat' => [], 'kg_isci' => [], 'kg_saat' => [], 'kasa_isci' => [], 'kasa' => []];
foreach ($veri['gunler'] as $g) {
    $grafik['etiket'][] = date('d.m', strtotime($g['tarih'])) . ' ' . $gunAd[(int)date('N', strtotime($g['tarih']))];
    $grafik['kg'][] = $g['net_kg'];
    $grafik['isci'][] = $g['isci'] > 0 ? $g['isci'] : null;
    $grafik['saat'][] = $g['saat'];
    $grafik['kg_isci'][] = $g['kg_isci'];
    $grafik['kg_saat'][] = $g['kg_saat'];
    $grafik['kasa_isci'][] = $g['kasa_isci'];
    $grafik['kasa'][] = $g['kasa'];
}
$aylikGrafik = ['etiket' => [], 'kg' => [], 'kg_isci' => [], 'gun' => []];
foreach ($aylik as $a) {
    $aylikGrafik['etiket'][] = mb_substr($aylarTr[(int)substr($a['ay'], 5, 2)], 0, 3, 'UTF-8') . ' ' . substr($a['ay'], 2, 2);
    $aylikGrafik['kg'][] = $a['gun'] > 0 ? $a['kg'] : null;
    $aylikGrafik['kg_isci'][] = $a['kg_isci'];
    $aylikGrafik['gun'][] = $a['gun'];
}
$json = json_encode(['gunluk' => $grafik, 'aylik' => $aylikGrafik, 'ort' => ['kg_isci' => $o['kg_isci'] ?? null, 'kg_saat' => $o['kg_saat'] ?? null, 'kasa_isci' => $o['kasa_isci'] ?? null, 'kg' => $o['gunluk_ort_kg'] ?? null]],
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR);

render_header('Günlük Üretim');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/uretim.css?v=' . @filemtime(__DIR__ . '/assets/uretim.css') . '">';
?>
<div class="page-head">
    <h1>🏭 Günlük Üretim</h1>
    <div class="page-head-actions">
        <?php if (can_uretim('write')): ?><a href="gunluk_uretim_yukle.php" class="btn btn-primary">＋ Veri Girişi</a><?php endif; ?>
        <a href="personel_takip.php" class="btn btn-geri btn-geri-ptak">← Personel Takibi</a>
    </div>
</div>
<?php render_flash(); ?>

<?php if (!$hazir): ?>
<div class="card"><p><strong>Günlük Üretim tabloları kurulu değil.</strong> Bir yönetici <a href="migrate.php#uretim">migrate.php › Günlük Üretim</a> kartından kurmalı.</p></div>
<?php else: ?>

<div class="uretim-ay">
    <a class="btn btn-sm" href="?ay=<?= h($oncekiAy) ?>" aria-label="Önceki ay">◀</a>
    <form method="get" class="uretim-ay-form">
        <input type="month" name="ay" value="<?= h($ay) ?>" max="<?= h(date('Y-m')) ?>" onchange="this.form.submit()" aria-label="Ay seç">
        <noscript><button class="btn btn-sm" type="submit">Göster</button></noscript>
    </form>
    <?php if ($ay < date('Y-m')): ?><a class="btn btn-sm" href="?ay=<?= h($sonrakiAy) ?>" aria-label="Sonraki ay">▶</a><?php else: ?><span class="btn btn-sm uretim-pasif">▶</span><?php endif; ?>
    <strong class="uretim-ay-ad"><?= h($ayEtiket($ay)) ?></strong>
    <span class="uretim-not">· <?= h($depo !== '' ? $depo : 'Depo seçilmemiş') ?></span>
</div>

<?php if (!$veri['personel_var']): ?>
<div class="flash flash-warning">Personel Takibi (günlük puantaj) verisi okunamadı — işçi başı değerler hesaplanamıyor; yalnız işlenen mal gösterilir.</div>
<?php endif; ?>

<div class="uretim-kpi">
    <div class="uretim-kpi-kart"><span>İşlenen mal</span><strong><?= $sayi($o['kg'] ?? 0) ?> kg</strong><small><?= (int)($o['gun'] ?? 0) ?> gün · günlük ort. <?= $sayi($o['gunluk_ort_kg'] ?? null) ?> kg</small></div>
    <div class="uretim-kpi-kart vurgu"><span>İşçi başı</span><strong><?= $sayi($o['kg_isci'] ?? null) ?> kg</strong><small>kg ÷ işçi-gün (<?= (int)($o['eslesen_gun'] ?? 0) ?> eşleşen gün)</small></div>
    <div class="uretim-kpi-kart"><span>İşçi-saat başı</span><strong><?= $sayi($o['kg_saat'] ?? null) ?> kg</strong><small><?= $veri['saat_var'] ? 'toleranslı çalışma saati' : 'saat verisi yok' ?></small></div>
    <div class="uretim-kpi-kart"><span>İşçi başı kasa</span><strong><?= $sayi($o['kasa_isci'] ?? null) ?></strong><small>kasa ÷ işçi-gün</small></div>
</div>
<?php if (($o['personelsiz_gun'] ?? 0) > 0 || ($o['uretimsiz_isci_gun'] ?? 0) > 0): ?>
<p class="uretim-not">⚠ Oranlar yalnız hem <b>işlenen malı</b> hem <b>işçi kaydı</b> olan günlerden hesaplanır.
    <?php if ($o['personelsiz_gun'] > 0): ?><?= (int)$o['personelsiz_gun'] ?> günde mal var ama işçi kaydı yok. <?php endif; ?>
    <?php if ($o['uretimsiz_isci_gun'] > 0): ?><?= (int)$o['uretimsiz_isci_gun'] ?> günde işçi çalışmış ama mal girilmemiş.<?php endif; ?></p>
<?php endif; ?>

<div class="card">
    <div class="uretim-grafik-bas">
        <h2>Günlük grafik</h2>
        <label class="uretim-sec">Çizgi
            <select id="uretimMetrik">
                <option value="kg_isci">İşçi başı kg</option>
                <option value="kg_saat">İşçi-saat başı kg</option>
                <option value="kasa_isci">İşçi başı kasa</option>
                <option value="isci">Çalışan işçi sayısı</option>
                <option value="saat">Çalışma saati</option>
            </select>
        </label>
    </div>
    <div class="uretim-canvas"><canvas id="uretimGunluk" role="img" aria-label="<?= h($ayEtiket($ay)) ?> günlük işlenen mal ve işçi başı verim grafiği; değerler aşağıdaki tabloda"></canvas></div>
    <p class="uretim-not">Çubuklar: işlenen net kg. Çizgi: seçtiğiniz ölçü; kesikli çizgi ay ağırlıklı ortalaması. Göstergedeki adlara dokunarak seri açıp kapatabilirsiniz.</p>
</div>

<div class="card">
    <div class="uretim-grafik-bas">
        <h2>Gün gün tablo</h2>
        <label class="uretim-onay"><input type="checkbox" id="uretimBosGizle" checked> Boş günleri gizle</label>
    </div>
    <div class="table-wrap">
        <table class="table uretim-tablo">
            <thead><tr><th>Tarih</th><th class="num">Net kg</th><th class="num">Kasa</th><th class="num">Palet</th><th class="num">Çavuş</th><th class="num">İşçi</th><th class="num">Saat</th><th class="num">kg/işçi</th><th class="num">kg/saat</th><th class="num">kasa/işçi</th><th>Durum</th></tr></thead>
            <tbody>
            <?php foreach ($veri['gunler'] as $g): [$dEt, $dSin] = $durumEt[$g['durum']]; $hf = in_array((int)date('N', strtotime($g['tarih'])), [6, 7], true); ?>
                <tr data-bos="<?= $g['durum'] === 'bos' ? '1' : '0' ?>"<?= $hf ? ' class="uretim-hf"' : '' ?>>
                    <td data-label="Tarih"><?= h(date('d.m.Y', strtotime($g['tarih']))) ?> <small><?= h($gunAd[(int)date('N', strtotime($g['tarih']))]) ?></small></td>
                    <td class="num" data-label="Net kg"><strong><?= $sayi($g['net_kg']) ?></strong></td>
                    <td class="num" data-label="Kasa"><?= $sayi($g['kasa']) ?></td>
                    <td class="num" data-label="Palet"><?= $sayi($g['palet']) ?></td>
                    <td class="num" data-label="Çavuş"><?= $g['cavus'] ?: '—' ?></td>
                    <td class="num" data-label="İşçi"><?= $g['isci'] ?: '—' ?></td>
                    <td class="num" data-label="Saat"><?= $sayi($g['saat'], 1) ?></td>
                    <td class="num" data-label="kg/işçi"><strong><?= $sayi($g['kg_isci']) ?></strong></td>
                    <td class="num" data-label="kg/saat"><?= $sayi($g['kg_saat']) ?></td>
                    <td class="num" data-label="kasa/işçi"><?= $sayi($g['kasa_isci']) ?></td>
                    <td data-label="Durum"><?= $dEt !== '' ? '<span class="uretim-rozet ' . h($dSin) . '">' . h($dEt) . '</span>' : '' ?><?= $g['notu'] !== '' ? ' <small title="' . h($g['notu']) . '">📝</small>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><th>Ay</th><th class="num"><?= $sayi($o['kg'] ?? 0) ?></th><th class="num"><?= $sayi($o['kasa'] ?? 0) ?></th><th class="num"><?= $sayi($o['palet'] ?? 0) ?></th><th></th>
                    <th class="num"><?= $sayi($o['isci'] ?? 0) ?></th><th></th><th class="num"><?= $sayi($o['kg_isci'] ?? null) ?></th><th class="num"><?= $sayi($o['kg_saat'] ?? null) ?></th><th class="num"><?= $sayi($o['kasa_isci'] ?? null) ?></th><th></th></tr>
            </tfoot>
        </table>
    </div>
    <p class="uretim-not">Ay satırındaki oranlar <b>ağırlıklı</b>dır (toplam kg ÷ toplam işçi-gün); günlük oranların ortalaması alınmaz. İşçi = o gün kart basan işçi sayısı; saat = hakedişteki toleranslı çalışma saati (fazla mesai dahil).</p>
</div>

<div class="card">
    <h2>Son 12 ay</h2>
    <div class="uretim-canvas uretim-canvas-kisa"><canvas id="uretimAylik" role="img" aria-label="Son 12 ayın işlenen mal ve işçi başı verim grafiği"></canvas></div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Ay</th><th class="num">Gün</th><th class="num">Net kg</th><th class="num">İşçi-gün</th><th class="num">kg/işçi</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($aylik) as $a): ?>
                <tr><td><a href="?ay=<?= h($a['ay']) ?>"><?= h($ayEtiket($a['ay'])) ?></a></td><td class="num"><?= $a['gun'] ?: '—' ?></td><td class="num"><?= $a['gun'] ? $sayi($a['kg']) : '—' ?></td><td class="num"><?= $a['isci'] ?: '—' ?></td><td class="num"><?= $sayi($a['kg_isci']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script type="application/json" id="uretimVeri"><?= $json ?: '{}' ?></script>
<script src="<?= h($base) ?>assets/chart.umd.min.js?v=<?= (int)@filemtime(__DIR__ . '/assets/chart.umd.min.js') ?>"></script>
<script src="<?= h($base) ?>assets/uretim.js?v=<?= (int)@filemtime(__DIR__ . '/assets/uretim.js') ?>"></script>
<?php endif; ?>
<?php render_footer(); ?>
