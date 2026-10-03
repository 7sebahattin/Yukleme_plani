<?php
// =========================================================
// cavus_donem_raporu.php — Çavuş Hakedişi (Yöntem B) Dönem Raporu
//
// Çavuş bazında TÜM dönem kapanışları — tarih, devreden, dönem kişi-gün,
// toplam, hakediş adedi, birim ücret, tutar, yeni devir, durum. SALT
// OKUNUR — kendi hesap mantığını yazmaz, foreman_period_closures'ı
// pdks_faz8b_cavus_b.php'nin liste fonksiyonlarından okur.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_cari.php';
require_once __DIR__ . '/config/pdks_rapor.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/pdks_faz8b_cavus_b.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/xlsx_export.php';

$auth_user = require_login();
require_pdks_rapor();
if (!pdks_rapor_can('financial')) {
    forbidden('Bu rapor finansal yetki gerektirir. (Gerekli yetki: attendance.foreman_accounts)');
}
if (isset($_GET['csv']) || isset($_GET['xlsx'])) { require_perm('reports.export'); }

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_hakedis_sayfa_kapisi($pdo);
pdks_cari_sayfa_kapisi($pdo);

$bHazir = pdks_faz8b_cavus_ucret_b_sema_hazir($pdo);

$cavuslar = $pdo->query("SELECT id, code, name, is_active FROM foremen ORDER BY is_active DESC, name ASC")->fetchAll();

$cavusId = filter_var($_GET['cavus'] ?? '', FILTER_VALIDATE_INT) ?: null;
$baslangic = trim((string)($_GET['baslangic'] ?? ''));
if ($baslangic !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $baslangic) || !strtotime($baslangic))) $baslangic = '';
$bitis = trim((string)($_GET['bitis'] ?? ''));
if ($bitis !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bitis) || !strtotime($bitis))) $bitis = '';
$durum = trim((string)($_GET['durum'] ?? ''));
if (!in_array($durum, ['', 'valid', 'cancelled'], true)) $durum = '';
$kapanisId = filter_var($_GET['kapanis'] ?? '', FILTER_VALIDATE_INT) ?: null;

$kapanislar = $bHazir ? pdks_faz8b_cavus_ucret_b_kapanis_listesi($cavusId, $baslangic ?: null, $bitis ?: null, $durum ?: null, $pdo) : [];

$acikDonem = null;
if ($bHazir && $cavusId !== null) {
    $acikDonem = pdks_faz8b_cavus_ucret_b_onizle($cavusId, date('Y-m-d'), $pdo);
}

$kalemler = [];
$kapanisSecili = null;
if ($bHazir && $kapanisId !== null) {
    foreach ($kapanislar as $k) { if ((int)$k['id'] === $kapanisId) { $kapanisSecili = $k; break; } }
    if ($kapanisSecili === null) {
        $tumu = pdks_faz8b_cavus_ucret_b_kapanis_listesi(null, null, null, null, $pdo);
        foreach ($tumu as $k) { if ((int)$k['id'] === $kapanisId) { $kapanisSecili = $k; break; } }
    }
    if ($kapanisSecili !== null) $kalemler = pdks_faz8b_cavus_ucret_b_kapanis_kalemleri($kapanisId, $pdo);
}

$filtre = ['cavus' => $cavusId, 'baslangic' => $baslangic, 'bitis' => $bitis, 'durum' => $durum];

function cdr_satirlar(array $kapanislar): array
{
    $satirlar = [];
    foreach ($kapanislar as $k) {
        $satirlar[] = [
            'CVH-' . str_pad((string)$k['id'], 6, '0', STR_PAD_LEFT),
            $k['closure_date'],
            $k['foreman_code'],
            $k['foreman_name'],
            (int)$k['carry_in'],
            (int)$k['period_person_days'],
            (int)$k['total_person_days'],
            (int)$k['earned_units'],
            $k['unit_rate'],
            $k['amount'],
            $k['currency'],
            (int)$k['carry_out'],
            pdks_faz8b_cavus_ucret_b_durum_etiketi((string)$k['status']),
            $k['reference_no'] ?: ('ODM-' . str_pad((string)$k['payment_id'], 6, '0', STR_PAD_LEFT)),
            $k['cancellation_reason'] ?? '',
        ];
    }
    return $satirlar;
}

if (isset($_GET['csv'])) {
    export_audit('pdks', 'cavus_donem_raporu', 'csv', count($kapanislar), $filtre);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cavus_donem_raporu.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Kapanış No', 'Kapanış Tarihi', 'Çavuş Kodu', 'Çavuş', 'Devreden Kişi-Gün', 'Dönem Kişi-Gün', 'Toplam Kişi-Gün',
        'Hakediş Adedi', 'Birim Ücret', 'Tutar', 'Para Birimi', 'Yeni Devir', 'Durum', 'Ödeme Belgesi', 'İptal Gerekçesi'], ';', '"', '\\');
    foreach (cdr_satirlar($kapanislar) as $s) fputcsv($out, $s, ';', '"', '\\');
    fclose($out);
    exit;
}

if (isset($_GET['xlsx'])) {
    $sayfa = [
        'ad' => 'Dönem Raporu', 'baslik' => 'Çavuş Hakedişi — Dönem Raporu',
        'aciklama' => 'Yöntem B (25 kişi-gün = 1 hakediş) dönem kapanışları',
        'sutunlar' => [
            ['baslik' => 'Kapanış No'], ['baslik' => 'Kapanış Tarihi', 'tip' => 'tarih'],
            ['baslik' => 'Çavuş Kodu'], ['baslik' => 'Çavuş'],
            ['baslik' => 'Devreden Kişi-Gün', 'tip' => 'tamsayi'], ['baslik' => 'Dönem Kişi-Gün', 'tip' => 'tamsayi'],
            ['baslik' => 'Toplam Kişi-Gün', 'tip' => 'tamsayi'], ['baslik' => 'Hakediş Adedi', 'tip' => 'tamsayi'],
            ['baslik' => 'Birim Ücret', 'tip' => 'tutar'], ['baslik' => 'Tutar', 'tip' => 'tutar'],
            ['baslik' => 'Para Birimi'], ['baslik' => 'Yeni Devir', 'tip' => 'tamsayi'],
            ['baslik' => 'Durum'], ['baslik' => 'Ödeme Belgesi'], ['baslik' => 'İptal Gerekçesi'],
        ],
        'satirlar' => cdr_satirlar($kapanislar),
    ];
    export_audit('pdks', 'cavus_donem_raporu', 'xlsx', count($kapanislar), $filtre);
    xlsx_indir('cavus_donem_raporu.xlsx', [$sayfa], '?' . http_build_query(array_filter($filtre + ['csv' => '1'], fn($v) => $v !== '' && $v !== null)));
}

render_header('Çavuş Hakedişi Dönem Raporu');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head">
    <h1>🧮 Çavuş Hakedişi — Dönem Raporu</h1>
    <div class="page-head-actions">
        <a href="raporlar.php" class="btn btn-ghost">← Yönetim Raporları</a>
    </div>
</div>

<?php if (!$bHazir): ?>
<div class="pdks-empty"><p>Yöntem B tabloları henüz oluşturulmadı. Yönetici migrate.php sayfasından oluşturabilir.</p></div>
<?php else: ?>

<form method="get" class="pdks-filter-bar" data-oto-filtre>
    <select name="cavus">
        <option value="">Tüm çavuşlar</option>
        <?php foreach ($cavuslar as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $cavusId === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?> (<?= h($c['code']) ?>)</option>
        <?php endforeach; ?>
    </select>
    <label class="muted" style="font-size:.82rem">Başlangıç<br><input type="date" name="baslangic" value="<?= h($baslangic) ?>"></label>
    <label class="muted" style="font-size:.82rem">Bitiş<br><input type="date" name="bitis" value="<?= h($bitis) ?>"></label>
    <select name="durum">
        <option value="">Tüm durumlar</option>
        <option value="valid" <?= $durum === 'valid' ? 'selected' : '' ?>>Geçerli</option>
        <option value="cancelled" <?= $durum === 'cancelled' ? 'selected' : '' ?>>İptal (geri alındı)</option>
    </select>
    <?= pdks_oto_filtre_noscript() ?>
    <?= export_menu('?' . http_build_query(array_filter($filtre + ['csv' => '1'], fn($v) => $v !== '' && $v !== null)), '?' . http_build_query(array_filter($filtre + ['xlsx' => '1'], fn($v) => $v !== '' && $v !== null)), 'Excel İndir', 'btn btn-ghost') ?>
    <?php if ($cavusId !== null || $baslangic !== '' || $bitis !== '' || $durum !== ''): ?>
    <a href="cavus_donem_raporu.php" class="btn btn-ghost" style="align-self:flex-end">Temizle</a>
    <?php endif; ?>
</form>

<?php if ($cavusId !== null && $acikDonem !== null): ?>
<div class="pdks-kiosk-counters" style="margin:0 0 18px">
    <h3>Açık Dönem</h3>
    <div class="pdks-kiosk-counter-totals">
        <div class="pdks-kiosk-counter-box"><div class="lbl">Yöntem</div><div class="val"><?= h(pdks_faz8b_cavus_ucret_yontem_etiketi((string)$acikDonem['yontem'])) ?></div></div>
        <div class="pdks-kiosk-counter-box"><div class="lbl">Devreden</div><div class="val"><?= (int)$acikDonem['devir_giren'] ?></div></div>
        <div class="pdks-kiosk-counter-box"><div class="lbl">Bekleyen Kişi-Gün</div><div class="val"><?= (int)$acikDonem['donem_kisi_gun'] ?></div></div>
        <?php if (($acikDonem['durum'] ?? '') === 'kapanacak'): ?>
        <div class="pdks-kiosk-counter-box"><div class="lbl">Şu An Kapanırsa</div><div class="val"><?= (int)$acikDonem['adet'] ?> hakediş / <?= (int)$acikDonem['devir_cikan'] ?> devir</div></div>
        <div class="pdks-kiosk-counter-box"><div class="lbl">Tutar</div><div class="val"><?= h(pdks_faz8b_cavus_ucret_b_para((int)$acikDonem['tutar_kurus'])) ?> <?= h((string)$acikDonem['currency']) ?></div></div>
        <?php elseif (($acikDonem['durum'] ?? '') === 'ucret_yok'): ?>
        <div class="pdks-kiosk-counter-box eksik"><div class="lbl">Tutar</div><div class="val">Ücret tanımsız</div></div>
        <?php endif; ?>
    </div>
    <p class="muted" style="font-size:.82rem;margin:8px 0 0"><?= h((string)$acikDonem['mesaj']) ?></p>
</div>
<?php endif; ?>

<?php if (empty($kapanislar)): ?>
<div class="pdks-empty"><span class="pdks-empty-icon" aria-hidden="true">🧮</span><p>Bu filtrelerde Çavuş Hakedişi kapanışı bulunamadı.</p></div>
<?php else: ?>

<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr><th>Kapanış Tarihi</th><th>Çavuş</th><th>Devreden</th><th>Dönem Kişi-Gün</th><th>Toplam</th><th>Hakediş Adedi</th><th>Birim Ücret</th><th>Tutar</th><th>Yeni Devir</th><th>Durum</th><th class="actions-col">İşlem</th></tr></thead>
<tbody>
<?php foreach ($kapanislar as $k): ?>
<tr>
    <td class="muted"><?= h(date('d.m.Y', strtotime($k['closure_date']))) ?></td>
    <td class="pdks-row-name"><?= h($k['foreman_name']) ?></td>
    <td><?= (int)$k['carry_in'] ?></td>
    <td><?= (int)$k['period_person_days'] ?></td>
    <td><?= (int)$k['total_person_days'] ?></td>
    <td><strong><?= (int)$k['earned_units'] ?></strong></td>
    <td><?= h(number_format((float)$k['unit_rate'], 2, ',', '.')) ?> <?= h($k['currency']) ?></td>
    <td><strong><?= h(number_format((float)$k['amount'], 2, ',', '.')) ?> <?= h($k['currency']) ?></strong></td>
    <td><?= (int)$k['carry_out'] ?></td>
    <td><span class="pdks-badge <?= $k['status'] === 'valid' ? 'pdks-badge-aktif' : 'pdks-badge-pasif' ?>"><?= h(pdks_faz8b_cavus_ucret_b_durum_etiketi((string)$k['status'])) ?></span></td>
    <td class="actions-col"><a href="<?= h('cavus_donem_raporu.php?' . http_build_query(array_filter($filtre + ['kapanis' => (int)$k['id']], fn($v) => $v !== '' && $v !== null))) ?>" class="btn btn-sm">Günler</a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="pdks-cards mobile-only">
<?php foreach ($kapanislar as $k): ?>
<a href="<?= h('cavus_donem_raporu.php?' . http_build_query(array_filter($filtre + ['kapanis' => (int)$k['id']], fn($v) => $v !== '' && $v !== null))) ?>" class="pdks-card-item" style="text-decoration:none;color:inherit">
    <div class="pdks-card-top">
        <div class="pdks-card-meta">
            <div class="pdks-row-name"><?= h($k['foreman_name']) ?></div>
            <div class="pdks-row-sub"><?= h(date('d.m.Y', strtotime($k['closure_date']))) ?> · <?= (int)$k['earned_units'] ?> hakediş</div>
        </div>
        <span class="pdks-badge <?= $k['status'] === 'valid' ? 'pdks-badge-aktif' : 'pdks-badge-pasif' ?>"><?= h(pdks_faz8b_cavus_ucret_b_durum_etiketi((string)$k['status'])) ?></span>
    </div>
    <div class="pdks-kiosk-counter-row"><span>Tutar</span><span class="n"><strong><?= h(number_format((float)$k['amount'], 2, ',', '.')) ?> <?= h($k['currency']) ?></strong></span></div>
    <div class="pdks-row-sub">Devreden <?= (int)$k['carry_in'] ?> · Dönem <?= (int)$k['period_person_days'] ?> · Yeni devir <?= (int)$k['carry_out'] ?></div>
</a>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php if ($kapanisSecili !== null): ?>
<h2 style="font-size:1.05rem;margin-top:24px">CVH-<?= str_pad((string)$kapanisSecili['id'], 6, '0', STR_PAD_LEFT) ?> — Kapsanan Günler</h2>
<?php if (empty($kalemler)): ?>
<div class="pdks-empty"><p>Bu kapanışta kayıtlı gün bulunamadı.</p></div>
<?php else: ?>
<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr><th>İş Tarihi</th><th>Depo</th><th>Kişi-Gün</th><th>Hakediş</th></tr></thead>
<tbody>
<?php foreach ($kalemler as $kl): ?>
<tr>
    <td><?= h(date('d.m.Y', strtotime($kl['work_date']))) ?></td>
    <td><?= h($kl['depo'] ?: '—') ?></td>
    <td><?= (int)$kl['person_days'] ?></td>
    <td>HKD-<?= str_pad((string)$kl['entitlement_id'], 6, '0', STR_PAD_LEFT) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>

<?php endif; ?>

<?php pdks_liste_ui_js(); ?>
<?php render_footer(); ?>
