<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('reports.export');

$q       = trim($_GET['q'] ?? '');
$type_f  = trim($_GET['type'] ?? '');
$tarih_b = trim($_GET['tarih_bas'] ?? '');
$tarih_s = trim($_GET['tarih_son'] ?? '');
$muh_f   = trim($_GET['muh'] ?? '');

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = "(category LIKE ? OR person_company LIKE ? OR description LIKE ?)";
    $params   = array_merge($params, ["%$q%", "%$q%", "%$q%"]);
}
if ($type_f) { $where[] = "type=?"; $params[] = $type_f; }
if ($tarih_b) { $where[] = "transaction_date>=?"; $params[] = $tarih_b; }
if ($tarih_s) { $where[] = "transaction_date<=?"; $params[] = $tarih_s; }
if ($muh_f === '1') { $where[] = "is_given_to_accountant=1"; }
if ($muh_f === '0') { $where[] = "is_given_to_accountant=0"; }
$wstr = implode(' AND ', $where);

$st = db()->prepare("SELECT * FROM account_transactions WHERE $wstr ORDER BY transaction_date ASC, id ASC");
$st->execute($params);
$rows = $st->fetchAll();

// Audit — dışa aktarma (içerik loglanmaz, sadece filtre ve kayıt sayısı)
audit_log_event('export', 'hesap', null, null, [
    'format'    => 'xls',
    'row_count' => count($rows),
    'filters'   => array_filter([
        'q'         => $q,
        'type'      => $type_f,
        'tarih_bas' => $tarih_b,
        'tarih_son' => $tarih_s,
        'muh'       => $muh_f,
    ], fn($v) => $v !== ''),
]);

$filename = 'hesap_' . date('Y-m-d') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache');
echo "\xEF\xBB\xBF"; // UTF-8 BOM
?>
<html><head><meta charset="utf-8">
<style>
body { font-family: Arial, sans-serif; font-size: 10pt; }
table { border-collapse: collapse; width: 100%; }
th { background: #1a56db; color: #fff; padding: 6px 8px; border: 1px solid #ccc; }
td { padding: 5px 8px; border: 1px solid #ddd; vertical-align: top; }
.gelir  { color: #166534; }
.gider  { color: #991b1b; }
.havale { color: #1e40af; }
.nakit  { color: #9a3412; }
.num    { text-align: right; }
.total  { background: #f0f4ff; font-weight: bold; }
h2      { margin: 16px 0 4px; font-size: 12pt; color: #1a56db; }
</style>
</head><body>
<h1 style="font-size:14pt;margin-bottom:4px">Asya Fresh — Hesap Kaydı</h1>
<p style="color:#666;font-size:9pt">
    Tarih aralığı: <?= $tarih_b ? h($tarih_b) . ' — ' . h($tarih_s ?: date('Y-m-d')) : 'Tümü' ?> &nbsp;|&nbsp;
    Hazırlanma: <?= date('d.m.Y H:i') ?> &nbsp;|&nbsp;
    Toplam <?= count($rows) ?> kayıt
</p>

<h2>Tüm Kayıtlar</h2>
<table>
<tr>
    <th>#</th>
    <th>Tarih</th>
    <th>Tür</th>
    <th>Kategori</th>
    <th>Kişi/Firma</th>
    <th>Açıklama</th>
    <th>Belge No</th>
    <th>Tutar</th>
    <th>Döviz</th>
    <th>Ödeme</th>
    <th>Fatura</th>
    <th>Şirket İçin</th>
    <th>Muh. Verildi</th>
    <th>Not</th>
</tr>
<?php
$totals = ['gelir' => 0, 'gider' => 0, 'havale' => 0, 'nakit' => 0];
foreach ($rows as $i => $r):
    $totals[$r['type']] += (float)$r['amount'];
?>
<tr>
    <td><?= $i + 1 ?></td>
    <td><?= h(date('d.m.Y', strtotime($r['transaction_date']))) ?></td>
    <td class="<?= $r['type'] ?>"><?= hesap_type_label($r['type']) ?></td>
    <td><?= h($r['category']) ?></td>
    <td><?= h($r['person_company']) ?></td>
    <td><?= h($r['description']) ?></td>
    <td><?= h($r['document_no']) ?></td>
    <td class="num"><?= number_format((float)$r['amount'], 2, ',', '.') ?></td>
    <td><?= h($r['currency']) ?></td>
    <td><?= hesap_payment_label($r['payment_method']) ?></td>
    <td><?= $r['has_invoice'] ? 'Evet' : 'Hayır' ?></td>
    <td><?= $r['is_for_company'] ? 'Evet' : 'Hayır' ?></td>
    <td><?= $r['is_given_to_accountant'] ? 'Evet' : 'Bekliyor' ?></td>
    <td><?= h($r['notes']) ?></td>
</tr>
<?php endforeach; ?>
<tr class="total">
    <td colspan="7">TOPLAM GELİR</td>
    <td class="num gelir"><?= number_format($totals['gelir'], 2, ',', '.') ?></td>
    <td colspan="6"></td>
</tr>
<tr class="total">
    <td colspan="7">TOPLAM GİDER</td>
    <td class="num gider"><?= number_format($totals['gider'] + $totals['havale'] + $totals['nakit'], 2, ',', '.') ?></td>
    <td colspan="6"></td>
</tr>
<tr class="total">
    <td colspan="7">NET BAKİYE</td>
    <td class="num"><?= number_format($totals['gelir'] - $totals['gider'] - $totals['havale'] - $totals['nakit'], 2, ',', '.') ?></td>
    <td colspan="6"></td>
</tr>
</table>
</body></html>
