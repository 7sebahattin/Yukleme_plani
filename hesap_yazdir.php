<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';

$tarih_b = trim($_GET['tarih_bas'] ?? date('Y-m-01'));
$tarih_s = trim($_GET['tarih_son'] ?? date('Y-m-t'));
$type_f  = trim($_GET['type'] ?? '');
$muh_f   = trim($_GET['muh'] ?? '');

$where  = ['transaction_date>=?', 'transaction_date<=?'];
$params = [$tarih_b, $tarih_s];
if ($type_f) { $where[] = "type=?"; $params[] = $type_f; }
if ($muh_f === '0') { $where[] = "is_given_to_accountant=0"; }
$wstr = implode(' AND ', $where);

$st = db()->prepare("SELECT * FROM account_transactions WHERE $wstr ORDER BY transaction_date ASC, id ASC");
$st->execute($params);
$rows = $st->fetchAll();

$toplam_gelir = (float)array_sum(array_column(array_filter($rows, fn($r) => $r['type'] === 'gelir'), 'amount'));
$toplam_gider = (float)array_sum(array_column(array_filter($rows, fn($r) => in_array($r['type'], ['gider','havale','nakit'])), 'amount'));
?><!doctype html>
<html lang="tr"><head><meta charset="utf-8">
<title>Hesap Raporu <?= h($tarih_b) ?> — <?= h($tarih_s) ?></title>
<style>
@media print {
    body { font-size: 10pt; }
    .no-print { display: none; }
}
body { font-family: Arial, sans-serif; max-width: 900px; margin: 20px auto; padding: 16px; color: #111; }
h1 { font-size: 16pt; margin-bottom: 4px; }
h2 { font-size: 12pt; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
table { border-collapse: collapse; width: 100%; margin-bottom: 24px; font-size: 9pt; }
th { background: #1a56db; color: #fff; padding: 5px 8px; text-align: left; border: 1px solid #ccc; }
td { padding: 4px 8px; border: 1px solid #ddd; }
.num { text-align: right; }
.gelir { color: #166534; }
.gider { color: #991b1b; }
.total-row { background: #f0f4ff; font-weight: bold; }
.badge { display: inline-block; padding: 2px 6px; border-radius: 12px; font-size: 8pt; color: #fff; }
</style>
</head><body>
<div class="no-print" style="margin-bottom:16px">
    <button onclick="window.print()" style="background:#1a56db;color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer">🖨️ Yazdır</button>
    <a href="hesap.php" style="margin-left:8px">← Geri</a>
</div>

<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px">
    <div>
        <h1>Asya Fresh — Hesap Raporu</h1>
        <p style="color:#666;margin:4px 0">Tarih: <?= h(date('d.m.Y', strtotime($tarih_b))) ?> — <?= h(date('d.m.Y', strtotime($tarih_s))) ?></p>
        <p style="color:#666;margin:4px 0">Hazırlanma: <?= date('d.m.Y H:i') ?> &nbsp;|&nbsp; <?= count($rows) ?> kayıt</p>
    </div>
    <div style="text-align:right">
        <div style="font-size:11pt"><strong>Toplam Gelir:</strong> <span class="gelir"><?= fmt_para($toplam_gelir) ?></span></div>
        <div style="font-size:11pt"><strong>Toplam Gider:</strong> <span class="gider"><?= fmt_para($toplam_gider) ?></span></div>
        <div style="font-size:12pt;font-weight:bold;border-top:2px solid #333;margin-top:4px;padding-top:4px">
            Net Bakiye: <?= fmt_para($toplam_gelir - $toplam_gider) ?>
        </div>
    </div>
</div>

<table>
<thead><tr>
    <th>#</th>
    <th>Tarih</th>
    <th>Tür</th>
    <th>Kategori</th>
    <th>Kişi/Firma</th>
    <th>Açıklama</th>
    <th>Belge</th>
    <th class="num">Tutar</th>
    <th>Ödeme</th>
    <th>Fiş</th>
</tr></thead>
<tbody>
<?php foreach ($rows as $i => $r): ?>
<tr>
    <td><?= $i + 1 ?></td>
    <td><?= h(date('d.m.Y', strtotime($r['transaction_date']))) ?></td>
    <td><span class="badge" style="background:<?= hesap_type_color($r['type']) ?>"><?= hesap_type_label($r['type']) ?></span></td>
    <td><?= h($r['category']) ?></td>
    <td><?= h($r['person_company']) ?></td>
    <td><?= h($r['description']) ?></td>
    <td><?= h($r['document_no']) ?></td>
    <td class="num <?= in_array($r['type'], ['gelir']) ? 'gelir' : 'gider' ?>"><?= fmt_para((float)$r['amount'], $r['currency']) ?></td>
    <td><?= hesap_payment_label($r['payment_method']) ?></td>
    <td><?= $r['has_invoice'] ? '✓' : '⚠' ?></td>
</tr>
<?php endforeach; ?>
<tr class="total-row">
    <td colspan="7">TOPLAM GELİR</td>
    <td class="num gelir"><?= fmt_para($toplam_gelir) ?></td>
    <td colspan="2"></td>
</tr>
<tr class="total-row">
    <td colspan="7">TOPLAM GİDER</td>
    <td class="num gider"><?= fmt_para($toplam_gider) ?></td>
    <td colspan="2"></td>
</tr>
</tbody>
</table>
</body></html>
