<?php
// =========================================================
// record_excel.php — Tek yükleme kaydını Excel (.xls) indir
// Yazdır ekranıyla (record_view.php) AYNI veri/hesap mantığı.
// HTML-tablo tabanlı .xls (composer/PhpSpreadsheet YOK).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_login();
require_perm('records.read');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); die('Geçersiz kayıt.'); }

$st = db()->prepare("SELECT * FROM loading_records WHERE id = :id");
$st->execute([':id' => $id]);
$record = $st->fetch();
if (!$record) { http_response_code(404); die('Kayıt bulunamadı.'); }

// Paletler + kasa/palet tanımları (record_view.php ile aynı sorgu)
$st = db()->prepare("
    SELECT p.*,
           kc.name AS kasa_cinsi_adi, kc.unit_dara_kg AS kasa_cinsi_kg,
           pt.name AS palet_tipi_adi, pt.unit_dara_kg AS palet_tipi_kg
    FROM loading_pallets p
    LEFT JOIN material_definitions kc ON kc.id = p.kasa_cinsi_id
    LEFT JOIN material_definitions pt ON pt.id = p.palet_tipi_id
    WHERE p.loading_record_id = :r
    ORDER BY p.sira_no, p.id
");
$st->execute([':r' => $id]);
$pallets = $st->fetchAll();

$st_pm = db()->prepare("
    SELECT pm.*, m.id AS def_id, m.name AS material_name, m.type AS material_type
    FROM pallet_materials pm
    JOIN material_definitions m ON m.id = pm.material_id
    WHERE pm.loading_pallet_id = :p
");
foreach ($pallets as &$p) {
    $st_pm->execute([':p' => $p['id']]);
    $p['materials'] = $st_pm->fetchAll();
}
unset($p);

$tot         = record_totals($id);          // toplam_kasa/brut/dara/net — kasa+palet bazlı
$type_labels = definition_types();

// Tüm tanımlar (tip etiketi + stok çıkış grubu için)
$defs_by_id = [];
foreach (db()->query("SELECT id, type, name FROM material_definitions")->fetchAll() as $d) {
    $defs_by_id[(int)$d['id']] = $d;
}

// ── Stok çıkışları (record_view.php ile aynı: kasa + palet + sarf, ADET) ──
$stok_use = []; // def_id => adet
foreach ($pallets as $p) {
    if ($p['kasa_cinsi_id']) {
        $kid = (int)$p['kasa_cinsi_id'];
        $stok_use[$kid] = ($stok_use[$kid] ?? 0) + (int)$p['kasa_adeti'];
    }
    if ($p['palet_tipi_id']) {
        $kid = (int)$p['palet_tipi_id'];
        $stok_use[$kid] = ($stok_use[$kid] ?? 0) + 1; // her palet satırı = 1 palet
    }
    foreach ($p['materials'] as $m) {
        $kid = (int)$m['def_id'];
        $stok_use[$kid] = ($stok_use[$kid] ?? 0) + (float)$m['quantity'];
    }
}
// Tür sırasına göre sırala
$stok_list = [];
foreach ($stok_use as $def_id => $adet) {
    if ($adet <= 0) continue;
    $d = $defs_by_id[$def_id] ?? null;
    if (!$d) continue;
    $stok_list[] = [
        'name' => $d['name'],
        'type' => $type_labels[$d['type']] ?? $d['type'],
        'adet' => $adet,
    ];
}
usort($stok_list, fn($a, $b) => [$a['type'], $a['name']] <=> [$b['type'], $b['name']]);

// ── Yardımcılar ──
function xls_int($v): string { return number_format((float)$v, 0, ',', '.'); }
$brand = strtoupper(trim((string)($record['brand'] ?? '')));
$plaka = trim(($record['on_plaka'] ?? '') . ' / ' . ($record['arka_plaka'] ?? ''), ' /');

// ── Çıktı başlıkları ──
$fname = 'yukleme_plani_' . $id . '_' . date('Ymd') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-cache');
echo "\xEF\xBB\xBF"; // UTF-8 BOM

$BLUE = '#0f2f5f';
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
<head><meta charset="utf-8">
<style>
    table { border-collapse: collapse; font-family: Calibri, Arial, sans-serif; font-size: 11pt; }
    td, th { border: 0.5pt solid #94a3b8; padding: 4px 8px; vertical-align: middle; }
    .title { background: <?= $BLUE ?>; color: #fff; font-size: 15pt; font-weight: bold; text-align: center; }
    .sec   { background: #dbeafe; font-weight: bold; color: #1e3a5f; }
    .lbl   { background: #f1f5f9; font-weight: bold; white-space: nowrap; }
    .val   { color: <?= $BLUE ?>; font-weight: bold; }
    .th    { background: #e2e8f0; font-weight: bold; text-align: center; }
    .num   { text-align: right; color: <?= $BLUE ?>; mso-number-format: "\#\,\#\#0"; }
    .tot   { background: #fff3cd; font-weight: bold; font-size: 12pt; }
    .tot .num, .totval { background: #fff3cd; font-weight: bold; font-size: 12pt; color: <?= $BLUE ?>; text-align: right; }
    .nowrap { white-space: nowrap; }
</style></head>
<body>
<table>
    <tr><td class="title" colspan="6">ASYA FRESH YÜKLEME PLANI</td></tr>

    <!-- BÖLÜM 1 — Genel Bilgiler -->
    <tr><td class="sec" colspan="6">Genel Bilgiler</td></tr>
    <tr><td class="lbl">Kayıt No</td><td class="val">#<?= $id ?></td>
        <td class="lbl">Tarih</td><td class="val"><?= h(fmt_date($record['tarih'])) ?></td>
        <td class="lbl">Marka</td><td class="val"><?= h($brand !== '' ? $brand : '—') ?></td></tr>
    <tr><td class="lbl">Firma</td><td class="val"><?= h($record['firma'] ?? '') ?></td>
        <td class="lbl">Ürün</td><td class="val"><?= h($record['urun'] ?? '') ?></td>
        <td class="lbl">Bölge</td><td class="val"><?= h($record['bolge'] ?? '') ?></td></tr>
    <tr><td class="lbl">Alıcı</td><td class="val"><?= h($record['alici'] ?? '') ?></td>
        <td class="lbl">Parti No</td><td class="val"><?= h($record['parti_no'] ?? '') ?></td>
        <td class="lbl">Gümrük</td><td class="val"><?= h($record['gumruk'] ?? '') ?></td></tr>
    <tr><td class="lbl">Plaka</td><td class="val"><?= h($plaka) ?></td>
        <td class="lbl">Şoför</td><td class="val"><?= h($record['sofor_adi'] ?? '') ?></td>
        <td class="lbl">Telefon</td><td class="val"><?= h($record['telefon'] ?? '') ?></td></tr>
    <tr><td class="lbl">Nakliye Şirketi</td><td class="val"><?= h($record['nakliye_sirketi'] ?? '') ?></td>
        <td class="lbl">Nakliye Bedeli</td><td class="val"><?= $record['nakliye_bedeli'] > 0 ? h(fmt_money($record['nakliye_bedeli'])) : '' ?></td>
        <td class="lbl">Avans</td><td class="val"><?= $record['avans'] > 0 ? h(fmt_money($record['avans'])) : '' ?></td></tr>
    <tr><td class="lbl">Fatura No</td><td class="val"><?= h($record['fatura_no'] ?? '') ?></td>
        <td class="lbl">Casus No</td><td class="val"><?= h($record['casus_no'] ?? '') ?></td>
        <td class="lbl">Etiket</td><td class="val"><?= h($record['etiket'] ?? '') ?></td></tr>

    <tr><td colspan="6"></td></tr>

    <!-- BÖLÜM 2 — Palet Detayları -->
    <tr><td class="sec" colspan="6">Palet Detayları</td></tr>
</table>

<table>
    <tr>
        <th class="th">Palet No</th>
        <th class="th">Depo</th>
        <th class="th">Ürün</th>
        <th class="th">Kasa Cinsi</th>
        <th class="th">Kasa Adedi</th>
        <th class="th">Palet Tipi</th>
        <th class="th">Size</th>
        <th class="th">Brüt KG</th>
        <th class="th">Dara KG</th>
        <th class="th">Net KG</th>
    </tr>
    <?php foreach ($pallets as $i => $p): ?>
    <tr>
        <td class="nowrap"><?= h($p['palet_no'] ?: (string)($i + 1)) ?></td>
        <td><?= h($p['depo'] ?? '') ?></td>
        <td><?= h($p['urun_cinsi'] ?? '') ?></td>
        <td><?= h($p['kasa_cinsi_adi'] ?? '') ?></td>
        <td class="num"><?= xls_int($p['kasa_adeti']) ?></td>
        <td><?= h($p['palet_tipi_adi'] ?? '') ?></td>
        <td><?= h($p['size'] ?? '') ?></td>
        <td class="num"><?= xls_int($p['brut_kg']) ?></td>
        <td class="num"><?= xls_int($p['dara_kg']) ?></td>
        <td class="num"><?= xls_int($p['net_kg']) ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="tot">
        <td colspan="4">GENEL TOPLAM</td>
        <td class="totval"><?= xls_int($tot['toplam_kasa']) ?></td>
        <td colspan="2"></td>
        <td class="totval"><?= xls_int($tot['toplam_brut']) ?></td>
        <td class="totval"><?= xls_int(round((float)$tot['toplam_dara'])) ?></td>
        <td class="totval"><?= xls_int(round((float)$tot['toplam_net'])) ?></td>
    </tr>
    <tr><td colspan="10" style="border:none">Toplam Palet: <b><?= (int)$tot['palet_count'] ?></b> &nbsp; (Dara yalnız kasa + palet darasıdır; sarf malzemeler dahil değildir.)</td></tr>
</table>

<?php if (!empty($stok_list)): ?>
<table>
    <tr><td class="sec" colspan="3">Stok Çıkışları</td></tr>
    <tr>
        <th class="th">Malzeme</th>
        <th class="th">Tür</th>
        <th class="th">Adet</th>
    </tr>
    <?php foreach ($stok_list as $s): ?>
    <tr>
        <td><?= h($s['name']) ?></td>
        <td><?= h($s['type']) ?></td>
        <td class="num"><?= xls_int($s['adet']) ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

</body>
</html>
<?php
exit;
