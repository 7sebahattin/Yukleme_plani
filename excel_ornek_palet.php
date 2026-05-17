<?php
// Örnek palet şablonu — Excel (.xls) olarak indir
declare(strict_types=1);
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="ornek_palet_sablonu.xls"');
header('Cache-Control: no-cache, no-store');
echo "\xEF\xBB\xBF"; // UTF-8 BOM
?>
<html><head><meta charset="utf-8"></head><body>
<table>
<tr>
    <th>Palet No</th>
    <th>Kasa Adeti</th>
    <th>Brüt KG</th>
</tr>
<tr><td>1</td><td>120</td><td>2400</td></tr>
<tr><td>2</td><td>120</td><td>2385</td></tr>
<tr><td>3</td><td>100</td><td>2000</td></tr>
</table>
</body></html>
