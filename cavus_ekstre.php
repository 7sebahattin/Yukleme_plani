<?php
// =========================================================
// cavus_ekstre.php — Çavuş Hesap Ekstresi (Günlük İşçi, Faz 5)
//
// SALT OKUNUR, KRONOLOJİK — pdks_cari_ekstre() REUSE (kendi SQL'ini
// YAZMAZ). Sahte/düzenlenebilir satır YOK — yalnız KESİN hakediş + GEÇERLİ
// ödemenin bir PROJEKSİYONU. Para birimleri AYRI tablolarda gösterilir,
// ASLA toplanmaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_cari.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_cari('accounts');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_hakedis_sayfa_kapisi($pdo);
pdks_cari_sayfa_kapisi($pdo);

$foremanId = filter_var($_GET['foreman_id'] ?? '', FILTER_VALIDATE_INT);
if (!$foremanId) { set_flash('error', 'Geçersiz çavuş.'); header('Location: cavus_cari.php'); exit; }

$stF = $pdo->prepare("SELECT id, code, name, is_active FROM foremen WHERE id = ?");
$stF->execute([$foremanId]);
$cavus = $stF->fetch();
if (!$cavus) { set_flash('error', 'Çavuş bulunamadı.'); header('Location: cavus_cari.php'); exit; }

$baslangic = trim($_GET['baslangic'] ?? '');
if ($baslangic !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $baslangic) || !strtotime($baslangic))) $baslangic = '';
$bitis = trim($_GET['bitis'] ?? '');
if ($bitis !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bitis) || !strtotime($bitis))) $bitis = '';

$ekstre = pdks_cari_ekstre($foremanId, $baslangic ?: null, $bitis ?: null, $pdo);

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cavus_ekstre_' . $foremanId . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Type', 'Reference', 'Description', 'Increase', 'Decrease', 'Running Balance', 'Currency'], ';', '"', '\\');
    foreach ($ekstre as $cur => $satirlar) {
        foreach ($satirlar as $s) {
            fputcsv($out, [
                $s['tarih'], $s['tip_etiket'], $s['belge'], $s['aciklama'],
                $s['artis'] ?? '', $s['azalis'] ?? '', $s['kosan_bakiye'], $cur,
            ], ';', '"', '\\');
        }
    }
    fclose($out);
    exit;
}

render_header('Çavuş Ekstresi');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head">
    <h1>📒 <?= h($cavus['name']) ?> — Ekstre</h1>
    <div class="page-head-actions">
        <a href="cavus_cari.php" class="btn">← Çavuş Cari</a>
    </div>
</div>

<form method="get" class="pdks-filter-bar">
    <input type="hidden" name="foreman_id" value="<?= (int)$foremanId ?>">
    <label class="muted" style="font-size:.82rem">Başlangıç<br><input type="date" name="baslangic" value="<?= h($baslangic) ?>"></label>
    <label class="muted" style="font-size:.82rem">Bitiş<br><input type="date" name="bitis" value="<?= h($bitis) ?>"></label>
    <button type="submit" class="btn" style="align-self:flex-end">Filtrele</button>
    <a href="?<?= h(http_build_query(array_filter(['foreman_id' => $foremanId, 'baslangic' => $baslangic, 'bitis' => $bitis, 'csv' => '1'], fn($v) => $v !== ''))) ?>" class="btn btn-ghost" style="align-self:flex-end">⬇ CSV</a>
    <?php if ($baslangic !== '' || $bitis !== ''): ?>
    <a href="cavus_ekstre.php?foreman_id=<?= (int)$foremanId ?>" class="btn btn-ghost" style="align-self:flex-end">Temizle</a>
    <?php endif; ?>
</form>

<?php if (empty($ekstre)): ?>
<div class="pdks-empty">
    <span class="pdks-empty-icon" aria-hidden="true">📒</span>
    <p>Bu tarih aralığında/hiçbir para biriminde hareket bulunamadı.</p>
</div>
<?php else: foreach ($ekstre as $cur => $satirlar): ?>

<h2 style="font-size:1.05rem"><?= h($cur) ?> Hareketleri</h2>
<?php if (empty($satirlar)): ?>
<div class="pdks-empty"><p>Bu para biriminde hareket yok.</p></div>
<?php else: $sonBakiye = end($satirlar)['kosan_bakiye']; ?>

<div class="table-wrap pc-only" style="margin-bottom:10px">
<table class="data-table">
<thead><tr><th>Tarih</th><th>Tip</th><th>Belge/Referans</th><th>Açıklama</th><th>Artış</th><th>Azalış</th><th>Koşan Bakiye</th></tr></thead>
<tbody>
<?php foreach ($satirlar as $s): ?>
<tr>
    <td class="muted"><?= h(date('d.m.Y', strtotime($s['tarih']))) ?></td>
    <td><span class="pdks-badge <?= $s['tip'] === 'HAKEDIS' ? 'pdks-badge-eksik_cikis' : 'pdks-badge-aktif' ?>"><?= h($s['tip_etiket']) ?></span></td>
    <td class="pdks-uid"><?= h($s['belge']) ?></td>
    <td><?= h($s['aciklama']) ?></td>
    <td><?= $s['artis'] !== null ? h(number_format((float)$s['artis'], 2, ',', '.')) : '—' ?></td>
    <td><?= $s['azalis'] !== null ? h(number_format((float)$s['azalis'], 2, ',', '.')) : '—' ?></td>
    <td><strong><?= h(number_format((float)$s['kosan_bakiye'], 2, ',', '.')) ?></strong></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr><td colspan="6" style="text-align:right;font-weight:700">GÜNCEL BAKİYE</td><td style="font-weight:800"><?= h(number_format((float)$sonBakiye, 2, ',', '.')) ?> <?= h($cur) ?></td></tr></tfoot>
</table>
</div>

<div class="pdks-cards mobile-only" style="margin-bottom:18px">
<?php foreach ($satirlar as $s): ?>
<div class="pdks-card-item">
    <div class="pdks-card-top">
        <div class="pdks-card-meta">
            <div class="pdks-row-name"><?= h($s['belge']) ?></div>
            <div class="pdks-row-sub"><?= h(date('d.m.Y', strtotime($s['tarih']))) ?> · <?= h($s['aciklama']) ?></div>
        </div>
        <span class="pdks-badge <?= $s['tip'] === 'HAKEDIS' ? 'pdks-badge-eksik_cikis' : 'pdks-badge-aktif' ?>"><?= h($s['tip_etiket']) ?></span>
    </div>
    <div class="pdks-kiosk-counter-row"><span>Artış</span><span class="n"><?= $s['artis'] !== null ? h(number_format((float)$s['artis'], 2, ',', '.')) : '—' ?></span></div>
    <div class="pdks-kiosk-counter-row"><span>Azalış</span><span class="n"><?= $s['azalis'] !== null ? h(number_format((float)$s['azalis'], 2, ',', '.')) : '—' ?></span></div>
    <div class="pdks-kiosk-counter-row"><span>Koşan Bakiye</span><span class="n"><strong><?= h(number_format((float)$s['kosan_bakiye'], 2, ',', '.')) ?></strong></span></div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>
<?php endforeach; endif; ?>

<?php render_footer(); ?>
