<?php
// =========================================================
// record_excel_template.php — Yükleme kaydını Excel ŞABLONA işler
// Şablon: templates/excel/yukleme_plani_template.xlsx (biçim korunur)
// Sistem verisi ilgili hücrelere yazılır → gerçek .xlsx indirilir.
// Dara = yalnız kasa + palet (record_view ile aynı); sarf hariç.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_login();
require_perm('records.read');

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) { http_response_code(500); die('Excel motoru (PhpSpreadsheet) bulunamadı.'); }
require_once $autoload;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

$id    = (int)($_GET['id'] ?? 0);
$debug = !empty($_GET['debug']) && function_exists('is_admin') && is_admin();
if ($id <= 0) { http_response_code(400); die('Geçersiz kayıt.'); }

$TPL = __DIR__ . '/templates/excel/yukleme_plani_template.xlsx';
if (!is_file($TPL)) { http_response_code(500); die('Excel şablonu bulunamadı.'); }

// ── Veri (record_view.php ile aynı sorgular) ──
$st = db()->prepare("SELECT * FROM loading_records WHERE id = :id");
$st->execute([':id' => $id]);
$record = $st->fetch();
if (!$record) { http_response_code(404); die('Kayıt bulunamadı.'); }

$st = db()->prepare("
    SELECT p.*, kc.name AS kasa_cinsi_adi, kc.unit_dara_kg AS kasa_unit_dara,
           pt.name AS palet_tipi_adi, pt.unit_dara_kg AS palet_unit_dara
    FROM loading_pallets p
    LEFT JOIN material_definitions kc ON kc.id = p.kasa_cinsi_id
    LEFT JOIN material_definitions pt ON pt.id = p.palet_tipi_id
    WHERE p.loading_record_id = :r ORDER BY p.sira_no, p.id
");
$st->execute([':r' => $id]);
$pallets = $st->fetchAll();

$st_pm = db()->prepare("
    SELECT pm.*, m.id AS def_id, m.name AS material_name, m.type AS material_type
    FROM pallet_materials pm JOIN material_definitions m ON m.id = pm.material_id
    WHERE pm.loading_pallet_id = :p
");
foreach ($pallets as &$p) { $st_pm->execute([':p' => $p['id']]); $p['materials'] = $st_pm->fetchAll(); }
unset($p);

$type_labels = definition_types();
$defs_by_id  = [];
foreach (db()->query("SELECT id, type, name FROM material_definitions")->fetchAll() as $d) {
    $defs_by_id[(int)$d['id']] = $d;
}

// ── Stok çıkışları (kasa+palet+sarf, ADET) — type bazında topla ──
$use_by_type = [];   // type => adet
$use_by_id   = [];   // def_id => ['name','type','adet']
foreach ($pallets as $p) {
    if ($p['kasa_cinsi_id']) { $kid=(int)$p['kasa_cinsi_id']; $use_by_id[$kid]['adet']=($use_by_id[$kid]['adet']??0)+(int)$p['kasa_adeti']; }
    if ($p['palet_tipi_id']) { $kid=(int)$p['palet_tipi_id']; $use_by_id[$kid]['adet']=($use_by_id[$kid]['adet']??0)+1; }
    // Ek malzeme — kasa bazlı: quantity × kasa_adeti, palet bazlı: quantity
    // (Sprint Malzeme-02 ile aynı mantık → record_view/record_excel ile tutarlı)
    foreach ($p['materials'] as $m) {
        $kid   = (int)$m['def_id'];
        $basis = function_exists('material_calc_basis')
            ? material_calc_basis((string)$m['material_type'], (string)$m['material_name'])
            : 'palet';
        $eff   = ($basis === 'kasa')
            ? (float)$m['quantity'] * (int)$p['kasa_adeti']
            : (float)$m['quantity'];
        $use_by_id[$kid]['adet'] = ($use_by_id[$kid]['adet'] ?? 0) + $eff;
    }
}
foreach ($use_by_id as $kid => &$u) {
    $d = $defs_by_id[$kid] ?? null; if (!$d) continue;
    $u['name'] = $d['name']; $u['type'] = $d['type'];
    $use_by_type[$d['type']] = ($use_by_type[$d['type']] ?? 0) + $u['adet'];
}
unset($u);

// ── Şablonu aç ──
try {
    $ss = IOFactory::load($TPL);
} catch (Throwable $e) {
    error_log('[excel] sablon acilamadi: ' . $e->getMessage());
    http_response_code(500); die('Excel şablonu açılamadı.');
}
$sh  = $ss->getActiveSheet();
$log = [];
$setS = function (string $cell, $val) use ($sh, &$log) {
    if ($val === null || $val === '') { $log[] = "$cell=(boş)"; return; }
    $sh->setCellValueExplicit($cell, (string)$val, DataType::TYPE_STRING);
    $log[] = "$cell=$val";
};
$setN = function (string $cell, $val) use ($sh, &$log) {
    $sh->setCellValueExplicit($cell, (float)$val, DataType::TYPE_NUMERIC);
    $log[] = "$cell=" . (float)$val;
};
// Formül yaz — Excel'de canlı hesaplar (kullanıcı girdi hücresini değiştirince otomatik güncellenir).
// PhpSpreadsheet kayıt sırasında formülü önceden hesaplayıp önbellek değerini de yazar,
// böylece dosya açılır açılmaz doğru değer görünür; düzenlenince yeniden hesaplanır.
$setF = function (string $cell, string $formula) use ($sh, &$log) {
    $sh->setCellValue($cell, $formula);
    $log[] = "$cell=$formula [F]";
};

// ── Marka başlığı (A1) ──
$brand = strtoupper(trim((string)($record['brand'] ?? '')));
$brand_title = ($brand === 'URAL') ? 'URAL FRESH'
            : (($brand === 'URAS') ? 'URAS FRESH'
            : (($brand === 'ASYA') ? 'ASYA FRESH'
            : (trim((string)($record['firma'] ?? '')) ?: 'ASYA FRESH')));
$setS('A1', $brand_title);

// ── Üst bilgi ──
$setS('D2', $record['sofor_adi'] ?? '');
$setS('D3', $record['on_plaka'] ?? '');
$setS('D4', $record['arka_plaka'] ?? '');
$setS('D5', $record['telefon'] ?? '');
$setS('D6', $record['nakliye_sirketi'] ?? '');
$setS('D7', $record['casus_no'] ?? '');
$setS('D8', '');                                   // KONTEYNER NO — sistemde yok
$setS('J2', fmt_date($record['tarih']));
$setS('J3', $record['parti_no'] ?? '');
$setS('J4', $record['bolge'] ?? '');
$setS('J5', $record['gumruk'] ?? '');
$setS('J6', '');                                   // ULAŞIM — sistemde yok
$setS('J7', $record['alici'] ?? '');
$setS('O2', mb_strtoupper(trim((string)($record['urun'] ?? '')), 'UTF-8'));

// ── Palet satırları (10..35 = 26 satır) ──
$ROW0 = 10; $CAP = 35 - $ROW0 + 1;  // 26
if (count($pallets) > $CAP) {
    http_response_code(400);
    die('Şablonda ' . $CAP . ' palet satırı var; bu kayıtta ' . count($pallets) . ' palet var. Daha uzun şablon gerekir.');
}
// Dara hesabı için birim dara kg'lar gizli yardımcı sütunlara yazılır (R=kasa birim, S=palet birim);
// E (DARA) bu sütunlara ve B'ye (kasa adeti) bağlı canlı formül olur — config/calc.php::compute_pallet_row
// ile aynı mantık: dara = kasa_adeti × kasa_birim_dara + palet_birim_dara.
$sh->getColumnDimension('R')->setVisible(false);
$sh->getColumnDimension('S')->setVisible(false);
$setS('R9', 'Kasa Br.Dara');
$setS('S9', 'Palet Br.Dara');
foreach ($pallets as $i => $p) {
    $r = $ROW0 + $i;
    $setS("A$r", $p['palet_no'] ?: (string)($i + 1));
    $setN("B$r", (int)$p['kasa_adeti']);
    // SİZE — sayısal ise sayı olarak yaz (aşağıdaki SUMIF özet satırları sayısal size etiketleriyle eşleşsin)
    $size_val = trim((string)($p['size'] ?? ''));
    if ($size_val !== '' && is_numeric($size_val)) { $setN("C$r", $size_val); }
    else { $setS("C$r", $size_val); }
    $setN("D$r", round((float)$p['brut_kg'], 1));
    $setN("R$r", (float)($p['kasa_unit_dara']  ?? 0));
    $setN("S$r", (float)($p['palet_unit_dara'] ?? 0));
    // DARA = KASA ADETİ × kasa birim dara + palet birim dara (canlı formül; kasa adeti değişince otomatik güncellenir)
    $setF("E$r", "=ROUND(B$r*R$r+S$r,1)");
    $setS("F$r", $p['kasa_cinsi_adi'] ?? '');
    $setS("G$r", mb_strtoupper(trim((string)($p['urun_cinsi'] ?? '')), 'UTF-8'));
    // NET KG = BRÜT − DARA (canlı formül; brüt/dara düzenlenince otomatik güncellenir)
    $setF("H$r", "=ROUND(D$r-E$r,1)");
}

// ── Malzeme listesi (sabit satır eşleştirme — Mod 1) ──
// Şablon I-sütunu sabit adları → sistem type'ları (K sütununa adet)
$mal_map = [
    11 => ['sapka'],
    12 => ['kosebent'],
    13 => ['serit'],
    14 => ['casus'],
    20 => ['kasa_etiketi'],
    21 => ['taban_kagidi'],
    22 => ['kenar_kartonu'],
    23 => ['sale'],
    31 => ['kose_karton'],
];
// İHRACAT PALETİ (I10) = palet_tipi toplamı
$setN('K10', $use_by_type['palet_tipi'] ?? 0);
foreach ($mal_map as $row => $types) {
    $sum = 0; foreach ($types as $t) $sum += ($use_by_type[$t] ?? 0);
    if ($sum > 0) $setN("K$row", $sum);
}
// Eşleşmeyen tipler (file, kraft_kagit, minti, viyol, kasa_cinsi vb.) → debug notu
$mapped = ['palet_tipi'];
foreach ($mal_map as $types) foreach ($types as $t) $mapped[] = $t;
foreach ($use_by_type as $t => $adet) {
    if ($adet > 0 && !in_array($t, $mapped, true)) {
        $log[] = "ESLENMEYEN malzeme tipi: $t = $adet (sablonda sabit satiri yok)";
    }
}

// ── Genel toplam (O10..O13) — canlı formüller (palet satırları 10..35) ──
$ROWE = $ROW0 + $CAP - 1;  // 35
$setF('O10', "=ROUND(SUM(D$ROW0:D$ROWE),0)"); // TOPLAM BRÜT KG
$setF('O11', "=ROUND(SUM(E$ROW0:E$ROWE),0)"); // TOPLAM DARA KG
$setF('O12', "=ROUND(SUM(H$ROW0:H$ROWE),0)"); // TOPLAM NET KG
$setF('O13', "=SUM(B$ROW0:B$ROWE)");          // TOPLAM KASA ADETİ

// ── Size özeti (H38..K41) — sablon sabit size satirlari: H38=8,H39=9,H40=12,H41=14 ──
// Canlı SUMIF formülleri: kriter = H sütunundaki size etiketi, aralık = palet satırları (10..35).
// Palet brüt/net/kasa hücreleri düzenlenince bu özet de otomatik güncellenir.
$size_rows = [38, 39, 40, 41];
foreach ($size_rows as $r) {
    $setF("I$r", "=SUMIF(\$C\$$ROW0:\$C\$$ROWE,H$r,\$B\$$ROW0:\$B\$$ROWE)");          // KASA
    $setF("J$r", "=ROUND(SUMIF(\$C\$$ROW0:\$C\$$ROWE,H$r,\$D\$$ROW0:\$D\$$ROWE),0)"); // BRÜT
    $setF("K$r", "=ROUND(SUMIF(\$C\$$ROW0:\$C\$$ROWE,H$r,\$H\$$ROW0:\$H\$$ROWE),0)"); // NET
}

// ── DEBUG modu ──
if ($debug) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "== EXCEL ŞABLON DEBUG · Kayıt #$id ==\n\n";
    echo "Şablon: $TPL (var)\n";
    echo "Palet sayısı: " . count($pallets) . " / kapasite $CAP\n";
    echo "Malzeme tipleri (adet):\n";
    foreach ($use_by_type as $t => $a) echo "  $t = $a\n";
    echo "\nYazılan hücreler:\n";
    foreach ($log as $l) echo "  $l\n";
    exit;
}

// ── İndir ──
$_parti  = trim((string)($record['parti_no'] ?? ''));
$_firma  = trim((string)($record['firma']   ?? ''));
$_tarih  = trim((string)($record['tarih']   ?? ''));
$_tarih_fmt = $_tarih ? date('Ymd', strtotime($_tarih)) : date('Ymd');

$_parts = array_filter([$_parti, $_firma, $_tarih_fmt]);
if ($_parts) {
    $fname = implode('-', array_map(
        fn($s) => mb_strtoupper(preg_replace('/[^A-Za-z0-9\-]+/', '_', $s), 'UTF-8'),
        $_parts
    )) . '.xlsx';
} else {
    $urun_slug = preg_replace('/[^A-Za-z0-9]+/', '_', $type_labels && isset($record['urun']) ? (string)$record['urun'] : 'KAYIT');
    $urun_slug = trim((string)$urun_slug, '_') ?: 'KAYIT';
    $fname = 'YUKLEME_PLANI_' . mb_strtoupper($urun_slug, 'UTF-8') . '_' . $id . '_' . date('Ymd') . '.xlsx';
}

try {
    $writer = IOFactory::createWriter($ss, 'Xlsx');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: max-age=0');
    $writer->save('php://output');
} catch (Throwable $e) {
    error_log('[excel] yazma hatasi: ' . $e->getMessage());
    http_response_code(500); die('Excel oluşturulamadı. Detay log\'a yazıldı.');
}
exit;
