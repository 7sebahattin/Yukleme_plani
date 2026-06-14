<?php
// =========================================================
// hesap_muhasebe_fis_pdf.php
// Muhasebeleşmemiş + fotoğraflı fişlerin yazdırılabilir / PDF dökümü.
// A4 portrait, sayfa başına 3×3 = 9 kutu. 10. fotoğraf 2. sayfaya geçer.
// Her kutu: üstte fiş bilgileri (tarih / tutar / not / kişi-kategori / fiş no),
//           altında dikdörtgen alanda fiş fotoğrafı.
// Filtre: muhasebeleşmemiş (is_given_to_accountant=0) + fotoğrafı olanlar.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('reports.read');

$tarih_b = trim($_GET['tarih_bas'] ?? '');
$tarih_s = trim($_GET['tarih_son'] ?? '');
$kat_f   = trim($_GET['kategori'] ?? '');
$kisi_f  = trim($_GET['kisi'] ?? '');

// Sadece muhasebeleşmemiş kayıtlar
$where  = ['is_given_to_accountant=0'];
$params = [];
if ($tarih_b !== '') { $where[] = "transaction_date>=?"; $params[] = $tarih_b; }
if ($tarih_s !== '') { $where[] = "transaction_date<=?"; $params[] = $tarih_s; }
if ($kat_f  !== '') { $where[] = "category=?";          $params[] = $kat_f; }
if ($kisi_f !== '') { $where[] = "person_company LIKE ?"; $params[] = "%$kisi_f%"; }
$wstr = implode(' AND ', $where);

$st = db()->prepare("SELECT * FROM account_transactions WHERE $wstr ORDER BY transaction_date ASC, id ASC");
$st->execute($params);
$rows = $st->fetchAll();

// Fotoğrafı olan/olmayan kayıtları ayır; fotoğraflı kayıtlardan kutu listesi (her foto bir kutu)
$boxes        = [];
$fisli_kayit  = 0;          // fotoğrafı olan kayıt (fiş) sayısı
$fissiz_kayit = 0;          // fotoğrafı olmayan muhasebeleşmemiş kayıt sayısı
$toplam_tutar = 0.0;        // fotoğraflı kayıtların tutar toplamı (kayıt başına 1 kez)
foreach ($rows as $r) {
    $imgs = hesap_get_image_files((int)$r['id']);
    if (empty($imgs)) { $fissiz_kayit++; continue; }
    $fisli_kayit++;
    if (($r['currency'] ?? 'TRY') === 'TRY') {
        $toplam_tutar += (float)$r['amount'];
    }
    foreach ($imgs as $img) {
        $boxes[] = ['t' => $r, 'file' => $img['file_name']];
    }
}

// Filtre etiketi
$filtre_parcalari = [];
if ($tarih_b !== '' || $tarih_s !== '') {
    $filtre_parcalari[] = 'Tarih: ' . ($tarih_b !== '' ? date('d.m.Y', strtotime($tarih_b)) : '…')
                        . ' — ' . ($tarih_s !== '' ? date('d.m.Y', strtotime($tarih_s)) : '…');
}
if ($kat_f  !== '') { $filtre_parcalari[] = 'Kategori: ' . $kat_f; }
if ($kisi_f !== '') { $filtre_parcalari[] = 'Kişi/Firma: ' . $kisi_f; }
$filtre_str = $filtre_parcalari ? implode(' · ', $filtre_parcalari) : 'Tüm muhasebeleşmemiş kayıtlar';

// 9'lu sayfalara böl
$sayfalar = array_chunk($boxes, 9);
?><!doctype html>
<html lang="tr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Muhasebeleşmemiş Fiş Fotoğrafları</title>
<style>
* { box-sizing: border-box; }
body { font-family: Arial, sans-serif; margin: 0; padding: 10mm; color: #111; background: #f5f5f5; }

.no-print { margin-bottom: 12px; }
.no-print button, .no-print a {
    background: #1a56db; color: #fff; border: none; padding: 8px 16px;
    border-radius: 6px; cursor: pointer; text-decoration: none; font-size: 14px;
    display: inline-block;
}
.no-print a.secondary { background: #64748b; }

.report-head {
    background: #fff; border: 1px solid #ccc; border-radius: 8px;
    padding: 12px 16px; margin-bottom: 12px;
}
.report-head h1 { font-size: 15pt; margin: 0 0 6px; }
.report-head .meta { font-size: 10pt; color: #444; line-height: 1.5; }
.report-head .meta strong { color: #111; }

.uyari {
    background: #fff7ed; border: 1px solid #fdba74; color: #9a3412;
    border-radius: 6px; padding: 8px 12px; margin-bottom: 12px; font-size: 10pt;
}

.empty-box {
    background: #fff; border: 1px solid #ccc; border-radius: 8px;
    padding: 40px; text-align: center; color: #666; font-size: 12pt;
}

.receipt-page {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    grid-template-rows: repeat(3, 1fr);
    gap: 6mm;
    background: #fff;
    padding: 6mm;
    margin: 0 auto 12px;
    width: 210mm;
    min-height: 297mm;
    border: 1px solid #ddd;
}

.receipt-card {
    border: 1px solid #333;
    border-radius: 4px;
    padding: 3mm;
    display: flex;
    flex-direction: column;
    break-inside: avoid;
    overflow: hidden;
}
.receipt-meta {
    font-size: 9px;
    line-height: 1.35;
    min-height: 16mm;
    margin-bottom: 2mm;
    border-bottom: 1px dashed #bbb;
    padding-bottom: 1.5mm;
}
.receipt-meta .r-amount { font-size: 12px; font-weight: 700; color: #111; }
.receipt-meta .r-date   { font-weight: 600; }
.receipt-meta .r-line   { display: block; color: #333; }
.receipt-meta .r-note   { color: #555; font-style: italic; }
.receipt-meta .r-fis    { color: #166534; font-weight: 600; }

.receipt-image-box {
    flex: 1;
    border: 1px solid #999;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    min-height: 50mm;
}
.receipt-image-box img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

@media print {
    body { padding: 0; background: #fff; }
    .no-print { display: none !important; }
    .report-head { border: none; border-radius: 0; padding: 0 0 6px; margin-bottom: 6px; }
    .receipt-page {
        width: auto; min-height: auto; border: none; margin: 0; padding: 0;
        page-break-after: always;
        height: 277mm;
    }
    .receipt-page:last-child { page-break-after: auto; }
    .receipt-card { break-inside: avoid; }
}
@page { size: A4 portrait; margin: 10mm; }
</style>
</head><body>

<div class="no-print">
    <button onclick="window.print()">🖨️ Yazdır / PDF Al</button>
    <a href="hesap_muhasebe.php" class="secondary">← Muhasebeye Dön</a>
</div>

<div class="report-head">
    <h1>MUHASEBELEŞMEMİŞ FİŞ FOTOĞRAFLARI</h1>
    <div class="meta">
        <strong>Rapor Tarihi:</strong> <?= date('d.m.Y H:i') ?> &nbsp;|&nbsp;
        <strong>Toplam Fiş:</strong> <?= (int)$fisli_kayit ?> kayıt
        <?php if (count($boxes) !== $fisli_kayit): ?>(<?= count($boxes) ?> fotoğraf)<?php endif; ?> &nbsp;|&nbsp;
        <strong>Toplam Tutar:</strong> <?= fmt_para($toplam_tutar) ?><br>
        <strong>Filtre:</strong> <?= h($filtre_str) ?>
    </div>
</div>

<?php if ($fissiz_kayit > 0): ?>
<div class="uyari">
    ⚠ Fotoğrafı olmayan <?= (int)$fissiz_kayit ?> muhasebeleşmemiş kayıt bu döküme dahil edilmedi.
</div>
<?php endif; ?>

<?php if (empty($boxes)): ?>
<div class="empty-box">Döküme uygun fiş bulunamadı.</div>
<?php else: ?>
<?php foreach ($sayfalar as $sayfa): ?>
<div class="receipt-page">
    <?php foreach ($sayfa as $box): $t = $box['t']; $fd = hesap_fis_durumu($t); ?>
    <div class="receipt-card">
        <div class="receipt-meta">
            <span class="r-line"><span class="r-date"><?= h(date('d.m.Y', strtotime($t['transaction_date']))) ?></span>
                · <span class="r-amount"><?= fmt_para((float)$t['amount'], $t['currency']) ?></span></span>
            <?php if ($t['category'] || $t['person_company']): ?>
            <span class="r-line"><?= h(trim(($t['category'] ?: hesap_type_label($t['type'])) . ($t['person_company'] ? ' · ' . $t['person_company'] : ''))) ?></span>
            <?php endif; ?>
            <span class="r-line r-fis"><?= h($fd['var'] ? ($fd['no'] !== '' ? 'Fiş No: ' . $fd['no'] : 'Fiş no yok') : '') ?></span>
            <?php if ($t['notes'] || $t['description']): ?>
            <span class="r-line r-note">📝 <?= h(mb_substr((string)($t['notes'] ?: $t['description']), 0, 120)) ?></span>
            <?php endif; ?>
        </div>
        <div class="receipt-image-box">
            <img src="hesap_dosya.php?f=<?= urlencode($box['file']) ?>" alt="Fiş fotoğrafı" loading="lazy">
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

</body></html>
