<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_hesap('read');
hesap_migrate();

$tarih_b = trim($_GET['tarih_bas'] ?? date('Y-m-01'));
$tarih_s = trim($_GET['tarih_son'] ?? date('Y-m-t'));
$type_f  = trim($_GET['type'] ?? '');
$muh_f   = trim($_GET['muh'] ?? '');
$durum_f = trim($_GET['durum'] ?? '');

$where  = ['transaction_date>=?', 'transaction_date<=?'];
$params = [$tarih_b, $tarih_s];
if ($type_f) { $where[] = "type=?"; $params[] = $type_f; }
if ($muh_f === '0') { $where[] = "is_given_to_accountant=0"; }
if ($durum_f === 'bekleyen') {
    $ph = implode(',', array_fill(0, count(hesap_pending_statuses()), '?'));
    $where[] = "status IN ($ph)";
    $params  = array_merge($params, hesap_pending_statuses());
} elseif ($durum_f !== '' && hesap_status_valid($durum_f)) {
    $where[]  = "status=?";
    $params[] = $durum_f;
}
[$osql, $oparams] = hesap_owner_sql();
if ($osql !== '') { $where[] = $osql; $params = array_merge($params, $oparams); }
[$dsql, $dparams] = depo_sql_in('depo');
if ($dsql !== '') { $where[] = $dsql; $params = array_merge($params, $dparams); }
$wstr = implode(' AND ', $where);

$st = db()->prepare("SELECT * FROM account_transactions WHERE $wstr ORDER BY transaction_date ASC, id ASC");
$st->execute($params);
$rows = $st->fetchAll();

// B2 düzeltmesi: toplamlar para birimi bazında tutulur — USD ile TRY asla toplanmaz.
$toplamlar = [];   // currency => ['gelir'=>…, 'gider'=>…]
foreach ($rows as $r) {
    $cur = $r['currency'] ?: 'TRY';
    if (!isset($toplamlar[$cur])) $toplamlar[$cur] = ['gelir' => 0.0, 'gider' => 0.0];
    if ($r['type'] === 'gelir') $toplamlar[$cur]['gelir'] += (float)$r['amount'];
    else                        $toplamlar[$cur]['gider'] += (float)$r['amount'];
}
if (empty($toplamlar)) $toplamlar['TRY'] = ['gelir' => 0.0, 'gider' => 0.0];
// TRY önce
uksort($toplamlar, fn($a, $b) => ($b === 'TRY' ? 1 : 0) <=> ($a === 'TRY' ? 1 : 0) ?: strcmp($a, $b));
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
        <?php foreach ($toplamlar as $cur => $t): ?>
        <div style="margin-bottom:6px">
            <?php if (count($toplamlar) > 1): ?><div style="font-size:9pt;color:#666"><?= h($cur) ?></div><?php endif; ?>
            <div style="font-size:11pt"><strong>Toplam Gelir:</strong> <span class="gelir"><?= fmt_para($t['gelir'], $cur) ?></span></div>
            <div style="font-size:11pt"><strong>Toplam Gider:</strong> <span class="gider"><?= fmt_para($t['gider'], $cur) ?></span></div>
            <div style="font-size:12pt;font-weight:bold;border-top:2px solid #333;margin-top:4px;padding-top:4px">
                Net Bakiye: <?= fmt_para($t['gelir'] - $t['gider'], $cur) ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (count($toplamlar) > 1): ?>
        <div style="font-size:8pt;color:#666">Para birimleri ayrı toplanır.</div>
        <?php endif; ?>
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
    <th>Durum</th>
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
    <td><?= hesap_fis_durumu($r)['var'] ? '✓' : '⚠' ?></td>
    <td><?= h(hesap_status_label((string)($r['status'] ?? ''))) ?></td>
</tr>
<?php endforeach; ?>
<?php foreach ($toplamlar as $cur => $t): ?>
<tr class="total-row">
    <td colspan="7">TOPLAM GELİR<?= count($toplamlar) > 1 ? ' (' . h($cur) . ')' : '' ?></td>
    <td class="num gelir"><?= fmt_para($t['gelir'], $cur) ?></td>
    <td colspan="3"></td>
</tr>
<tr class="total-row">
    <td colspan="7">TOPLAM GİDER<?= count($toplamlar) > 1 ? ' (' . h($cur) . ')' : '' ?></td>
    <td class="num gider"><?= fmt_para($t['gider'], $cur) ?></td>
    <td colspan="3"></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</body></html>
