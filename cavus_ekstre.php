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
require_once __DIR__ . '/config/xlsx_export.php';
$auth_user = require_login();
require_pdks_cari('accounts');
// Dışa aktarım — tüm uç noktalarda ortak kapı (reports.export) + audit
if (isset($_GET['csv']) || isset($_GET['xlsx'])) { require_perm('reports.export'); }

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
// ⚠ Fix 3 (Personel Takibi denetimi): $baslangic filtreliyken koşan bakiye
// 0'dan başlar (pdks_cari_ekstre() içinde) — bu, o tarihten ÖNCEKİ hareketleri
// dışladığı için GERÇEK güncel bakiye DEĞİL, yalnız FİLTRELİ DÖNEMİN net
// hareketidir. Eskiden ikisi de "GÜNCEL BAKİYE" etiketiyle gösteriliyordu —
// filtre uygulayan kullanıcı yanlış bir bakiyeyi gerçek zannedebiliyordu.
// Filtre yokken (ya da yalnız bitiş filtreliyken, o zaman 0'dan başlamak
// zaten doğrudur) etiket AYNEN kalır.
$_ekstreBaslangicFiltreli = $baslangic !== '';
$_ekstreGercekBakiye = $_ekstreBaslangicFiltreli ? pdks_cari_bakiye($foremanId, $pdo) : [];

$ce_filtre = ['foreman_id' => $foremanId, 'baslangic' => $baslangic, 'bitis' => $bitis];

// ── XLSX export — para birimi başına AYRI sayfa (kurlar asla toplanmaz);
//    tutarlar sayı hücresi (CSV'de "1250.50" nokta ondalıklı metindi). ──
if (isset($_GET['xlsx'])) {
    $donem = ($baslangic !== '' ? date('d.m.Y', strtotime($baslangic)) : '…') . ' – ' . ($bitis !== '' ? date('d.m.Y', strtotime($bitis)) : '…');
    $sayfalar = [];
    $say = 0;
    foreach ($ekstre as $cur => $satirlar) {
        $say += count($satirlar);
        $son = $satirlar ? end($satirlar)['kosan_bakiye'] : 0;
        $bilgi = [['Çavuş', $cavus['name']], ['Dönem', ($baslangic === '' && $bitis === '') ? 'Tüm zamanlar' : $donem], ['Para Birimi', $cur],
                  [$_ekstreBaslangicFiltreli ? 'Bu Dönemin Net Hareketi' : 'Güncel Bakiye', $son, 'tutar']];
        if ($_ekstreBaslangicFiltreli) $bilgi[] = ['Güncel Bakiye (tüm zamanlar)', $_ekstreGercekBakiye[$cur]['bakiye'] ?? 0, 'tutar'];
        $sayfalar[] = [
            'ad' => 'Ekstre ' . $cur, 'baslik' => $cavus['name'] . ' — Ekstre (' . $cur . ')',
            'aciklama' => 'Dönem: ' . $donem . ($_ekstreBaslangicFiltreli ? ' · Koşan bakiye dönem başında 0\'dan başlar' : ''),
            'bilgi' => $bilgi, 'toplam' => true,
            'sutunlar' => [['baslik' => 'Tarih', 'tip' => 'tarih'], ['baslik' => 'İşlem Türü'], ['baslik' => 'Belge / Referans No'], ['baslik' => 'Açıklama'],
                           ['baslik' => 'Hakediş / Borç Artışı', 'tip' => 'tutar', 'topla' => true], ['baslik' => 'Ödeme / Azalış', 'tip' => 'tutar', 'topla' => true],
                           ['baslik' => 'Bakiye', 'tip' => 'tutar']],
            'satirlar' => array_map(fn($x) => [$x['tarih'], $x['tip_etiket'], $x['belge'], $x['aciklama'], $x['artis'] ?? '', $x['azalis'] ?? '', $x['kosan_bakiye']], $satirlar),
        ];
    }
    if (!$sayfalar) $sayfalar = [['ad' => 'Ekstre', 'baslik' => $cavus['name'] . ' — Ekstre', 'aciklama' => 'Dönem: ' . $donem,
                                  'sutunlar' => [['baslik' => 'Durum']], 'satirlar' => [['Bu dönemde hareket yok']]]];
    export_audit('pdks', 'cavus_ekstre', 'xlsx', $say, $ce_filtre);
    xlsx_indir('cavus_ekstre_' . $foremanId . '.xlsx', $sayfalar, '?' . http_build_query(array_filter($ce_filtre + ['csv' => '1'], fn($v) => $v !== '')));
}

if (isset($_GET['csv'])) {
    export_audit('pdks', 'cavus_ekstre', 'csv', array_sum(array_map('count', $ekstre)), $ce_filtre);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cavus_ekstre_' . $foremanId . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    // ⚠ Faz 7 (kullanıcının açık talimatı: "ALL downloaded report column
    // headings must be Turkish"): başlıklar Türkçe iş terminolojisiyle.
    fputcsv($out, ['Tarih', 'İşlem Türü', 'Belge / Referans No', 'Açıklama', 'Hakediş / Borç Artışı', 'Ödeme / Azalış', 'Bakiye', 'Para Birimi'], ';', '"', '\\');
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
        <a href="<?= h('cavus_ekstre_yazdir.php?' . http_build_query(array_filter(['foreman_id' => $foremanId, 'baslangic' => $baslangic, 'bitis' => $bitis], fn($v) => $v !== ''))) ?>" class="btn btn-ghost">🖨️ Yazdır</a>
    </div>
</div>

<form method="get" class="pdks-filter-bar">
    <input type="hidden" name="foreman_id" value="<?= (int)$foremanId ?>">
    <label class="muted" style="font-size:.82rem">Başlangıç<br><input type="date" name="baslangic" value="<?= h($baslangic) ?>"></label>
    <label class="muted" style="font-size:.82rem">Bitiş<br><input type="date" name="bitis" value="<?= h($bitis) ?>"></label>
    <button type="submit" class="btn" style="align-self:flex-end">Filtrele</button>
    <?= export_menu('?' . http_build_query(array_filter($ce_filtre + ['csv' => '1'], fn($v) => $v !== '')), '?' . http_build_query(array_filter($ce_filtre + ['xlsx' => '1'], fn($v) => $v !== '')), 'Excel İndir', 'btn btn-ghost') ?>
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
<tfoot>
<tr><td colspan="6" style="text-align:right;font-weight:700"><?= $_ekstreBaslangicFiltreli ? 'BU DÖNEMİN NET HAREKETİ' : 'GÜNCEL BAKİYE' ?></td><td style="font-weight:800"><?= h(number_format((float)$sonBakiye, 2, ',', '.')) ?> <?= h($cur) ?></td></tr>
<?php if ($_ekstreBaslangicFiltreli): ?>
<tr><td colspan="6" style="text-align:right;font-weight:700">GÜNCEL BAKİYE (tüm zamanlar)</td><td style="font-weight:800"><?= h(number_format((float)($_ekstreGercekBakiye[$cur]['bakiye'] ?? 0), 2, ',', '.')) ?> <?= h($cur) ?></td></tr>
<?php endif; ?>
</tfoot>
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
<?php if ($_ekstreBaslangicFiltreli): ?>
<div class="pdks-card-item">
    <div class="pdks-kiosk-counter-row"><span>Bu Dönemin Net Hareketi</span><span class="n"><strong><?= h(number_format((float)$sonBakiye, 2, ',', '.')) ?> <?= h($cur) ?></strong></span></div>
    <div class="pdks-kiosk-counter-row"><span>Güncel Bakiye (tüm zamanlar)</span><span class="n"><strong><?= h(number_format((float)($_ekstreGercekBakiye[$cur]['bakiye'] ?? 0), 2, ',', '.')) ?> <?= h($cur) ?></strong></span></div>
</div>
<?php endif; ?>
</div>

<?php endif; ?>
<?php endforeach; endif; ?>

<?php render_footer(); ?>
