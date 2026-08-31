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

// ── Ürün cinsi listesi (kategori özet bloklarıyla ve yeşil kutuyla paylaşılır) ──
// Kayıttaki palet satırlarının CİNSİ (G) sütununda kaç farklı ürün varsa
// (ilk görülme sırasına göre) — hem O2:P6 yeşil kutuda hem L14/L18/L22/L26/L30
// kategori özet bloklarında aynı liste kullanılır.
$cats = [];
foreach ($pallets as $p) {
    $c = tr_upper($p['urun_cinsi'] ?? '');
    if ($c !== '' && !in_array($c, $cats, true)) { $cats[] = $c; }
}

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
$setS('J6', $record['ulasim'] ?? '');
$setS('J7', $record['alici'] ?? '');
$setS('N7', $record['gidecek_ulke'] ?? '');
// Yeşil kutu (O2:P6) — Sprint Excel-03: kayıttaki paletlerde kaç farklı CİNSİ
// varsa hepsi alt alta yazılır (tek ürün olan kayıtlarda eskisi gibi tek satır).
// $record['urun'] tek başlık alanı olduğundan, hiç palet/cinsi yoksa ona düşülür.
$_urun_kutu = $cats ?: [tr_upper($record['urun'] ?? '')];
$setS('O2', implode("\n", array_filter($_urun_kutu)));
// Şablonda zaten satır kaydırma (wrap text) açık — birden fazla cinsi \n ile
// alt alta düşer. 3+ farklı cinside kutuya taşmasın diye font küçültülür.
if (count($_urun_kutu) > 2) {
    $sh->getStyle('O2')->getFont()->setSize(max(11, 20 - (count($_urun_kutu) - 2) * 3));
}

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
    $setS("G$r", tr_upper($p['urun_cinsi'] ?? ''));
    // NET KG = BRÜT − DARA (canlı formül; brüt/dara düzenlenince otomatik güncellenir)
    $setF("H$r", "=ROUND(D$r-E$r,1)");
}

// ── Malzeme listesi (Sprint Excel-02/03: dinamik — yalnız gerçekten eklenen malzemeler) ──
// İHRACAT PALETİ (I10) satırı sabit kalır — bu bir "eklenen malzeme" değil, paletin
// kendi palet tipi kullanım toplamıdır (record_view'daki "Kasa Dağılımı" ile aynı köken).
// Kısa görünen isim (Sprint Excel-03): "İHRACAT PALETİ" yerine artık kısaca "PALET".
$setS('I10', 'PALET');
$setN('K10', $use_by_type['palet_tipi'] ?? 0);

// Malzeme türü → Excel'de gösterilecek kısa isim. Burada tanımlanmayan türler
// kendi sistem adıyla (material_definitions.name) yazılır — "burada tanımlama
// yapmadıklarım direkt sistem ismiyle yazabilir" talebi.
$MAT_ALIASES = [
    'sapka'         => 'ŞAPKA',
    'kosebent'      => 'KÖŞEBENT',
    'casus'         => 'CASUS',
    'serit'         => 'ŞERİT',
    'file'          => 'FİLE',
    'taban_kagidi'  => 'TABAN KAĞIDI',
    'kenar_kartonu' => 'KENAR KARTONU',
    'viyol'         => 'VİYOL',
];

// Gerçekten eklenmiş malzemeler (pallet_materials satırları) — record_view.php'deki
// "Ek Malzemeler" panelindeki $em_groups ile BİREBİR aynı gruplama/sıra/hesap mantığı;
// TEK FARK: "viyol" türü kendi içinde TEK satırda toplanır (birden fazla çeşit viyol
// stoğu eklenmiş olabilir — hepsi tek "VİYOL" toplamı olarak görünür). Diğer türler
// yine tanım (material_id) bazında ayrı satır olarak kalır.
// I11..I32 (22 satır) şablonun "mavi alan"ı — statik örnek isimler artık buraya değil,
// yalnız bu kayıtta gerçekten kullanılan malzemeler isim+adet olarak yazılır; kalan
// satırlar (kullanılmayan yuvalar) temizlenir, mavi biçim/hücre yapısı bozulmaz.
$added_materials = []; // grup anahtarı => ['name'=>.., 'type'=>.., 'adet'=>float]
foreach ($pallets as $p) {
    foreach (($p['materials'] ?? []) as $m) {
        $mtype = (string)$m['material_type'];
        $key   = ($mtype === 'viyol') ? 'type:viyol' : (int)$m['def_id'];
        $basis = material_calc_basis($mtype, (string)$m['material_name']);
        $eff   = ($basis === 'kasa')
            ? (float)$m['quantity'] * (int)$p['kasa_adeti']
            : (float)$m['quantity'];
        if (!isset($added_materials[$key])) {
            $added_materials[$key] = ['name' => $m['material_name'], 'type' => $mtype, 'adet' => 0.0];
        }
        $added_materials[$key]['adet'] += $eff;
    }
}
// Sıralama (Sprint Excel-04): bu türler HER ZAMAN bu sırada üstte görünür;
// listelenmeyen türler bu sabit sıradan SONRA, aralarındaki sıra önemli
// olmadan (kayıttaki oluşum sırasına göre) gelir. uasort PHP 8'de kararlı
// (stable) olduğundan "geri kalan" grup kendi arasında yer değiştirmez.
$MAT_ORDER_PRIORITY = ['sapka', 'kosebent', 'kasa_etiketi', 'casus', 'serit'];
$_mat_pri = array_flip($MAT_ORDER_PRIORITY);
uasort($added_materials, function ($a, $b) use ($_mat_pri) {
    $pa = $_mat_pri[$a['type']] ?? PHP_INT_MAX;
    $pb = $_mat_pri[$b['type']] ?? PHP_INT_MAX;
    return $pa <=> $pb;
});

$MAT_ROW0 = 11; $MAT_CAP = 32 - $MAT_ROW0 + 1; // 22 yuva (I11..I32)
if (count($added_materials) > $MAT_CAP) {
    http_response_code(400);
    die('Şablonda en fazla ' . $MAT_CAP . ' farklı ek malzeme için satır var; '
        . 'bu kayıtta ' . count($added_materials) . ' farklı malzeme var.');
}
$mi = 0;
foreach ($added_materials as $am) {
    $r = $MAT_ROW0 + $mi;
    $label = $MAT_ALIASES[$am['type']] ?? tr_upper($am['name']);
    $setS("I$r", $label);
    $setN("K$r", round($am['adet'], 3));
    $mi++;
}
// "Plastik Kasa" gerçek bir malzeme tanımı değil — her zaman TOPLAM KASA ADETİ
// (O13) ile birebir aynı olan türetilmiş bir satır; malzeme listesinin hemen
// altına canlı formülle eklenir (kullanıcının kendi elle eklediği örnek: K17="=O13").
if ($MAT_ROW0 + $mi <= 32) {
    $r = $MAT_ROW0 + $mi;
    $setS("I$r", 'PLASTİK KASA');
    $setF("K$r", '=O13');
    $mi++;
}
for ($r = $MAT_ROW0 + $mi; $r <= 32; $r++) {
    $sh->setCellValue("I$r", null);
    $sh->setCellValue("K$r", null);
    $log[] = "I$r/K$r=(temizlendi — kullanılmayan yuva)";
}

// ── Genel toplam (O10..O13) — canlı formüller (palet satırları 10..35) ──
$ROWE = $ROW0 + $CAP - 1;  // 35
$setF('O10', "=ROUND(SUM(D$ROW0:D$ROWE),0)"); // TOPLAM BRÜT KG
$setF('O11', "=ROUND(SUM(E$ROW0:E$ROWE),0)"); // TOPLAM DARA KG
$setF('O12', "=ROUND(SUM(H$ROW0:H$ROWE),0)"); // TOPLAM NET KG
$setF('O13', "=SUM(B$ROW0:B$ROWE)");          // TOPLAM KASA ADETİ

// ── Kategori özet blokları (L14, L18, L22, L26, L30 — TOPLAM/L10 hariç) ──
// Şablonda 5 kategori yuvası var, her biri 4 satır: BRÜT KG/DARA KG/NET KG/KASA ADETİ.
// Etiketler şablonda sabit DEĞİL — kayıttaki palet satırlarının CİNSİ (G) sütununda kaç
// farklı ürün varsa (ilk görülme sırasına göre) otomatik yazılır ve SUMIF ile hesaplanır.
// Yeni bir ürün cinsi geldiğinde kod değişikliği gerekmez.
$cat_slots = [14, 18, 22, 26, 30];
// $cats yukarıda (yeşil kutu O2 ile paylaşımlı) zaten hesaplandı.
if (count($cats) > count($cat_slots)) {
    http_response_code(400);
    die('Şablonda en fazla ' . count($cat_slots) . ' farklı ürün cinsi özeti için yer var; '
        . 'bu kayıtta ' . count($cats) . ' farklı cinsi var (' . implode(', ', $cats) . ').');
}
foreach ($cat_slots as $i => $r0) {
    $r1 = $r0 + 1; $r2 = $r0 + 2; $r3 = $r0 + 3;
    $label = $cats[$i] ?? '';
    $cellL = $sh->getCell("L$r0");
    $cellL->setValueExplicit($label, DataType::TYPE_STRING);
    $cellL->getStyle()->getAlignment()->setShrinkToFit(true); // uzun cinsi adları hücreye sığsın
    $log[] = "L$r0=" . ($label !== '' ? $label : '(boş)');
    if ($label === '') continue; // kullanılmayan yuva — O sütunu şablonda zaten boş
    $setF("O$r0", "=ROUND(SUMIF(\$G\$$ROW0:\$G\$$ROWE,L$r0,\$D\$$ROW0:\$D\$$ROWE),0)"); // BRÜT KG
    $setF("O$r1", "=ROUND(SUMIF(\$G\$$ROW0:\$G\$$ROWE,L$r0,\$E\$$ROW0:\$E\$$ROWE),0)"); // DARA KG
    $setF("O$r2", "=O$r0-O$r1");                                                        // NET KG
    $setF("O$r3", "=SUMIF(\$G\$$ROW0:\$G\$$ROWE,L$r0,\$B\$$ROW0:\$B\$$ROWE)");           // KASA ADETİ
}
// TOPLAM etiketi (L10) sabit metin — dar dikey hücreye sığması için shrink-to-fit
$sh->getCell('L10')->getStyle()->getAlignment()->setShrinkToFit(true);

// ── Size özeti (H38..) — Sprint Excel-05: 4 sabit satır, gerekirse aşağı uzar ──
// Şablonda sabit H38=8,H39=9,H40=12,H41=14 idi; artık kayıttaki palet satırlarının
// SİZE (C) sütununda gerçekte hangi değerler varsa (doğal sırayla) o yazılır ve SUMIF/
// COUNTIF ile hesaplanır. 4 veya daha az farklı size'da yapı hiç değişmez (fazla yuvalar
// temizlenir). 4'ten FAZLA farklı size varsa, son satırın (41) stili/yüksekliği
// kopyalanarak altına yeni satır(lar) eklenir — tablo aşağı doğru büyür, daralmaz.
$size_slots_base = [38, 39, 40, 41];
$sizes = [];
foreach ($pallets as $p) {
    $sv = trim((string)($p['size'] ?? ''));
    if ($sv !== '' && !in_array($sv, $sizes, true)) { $sizes[] = $sv; }
}
natsort($sizes);
$sizes = array_values($sizes);

$size_slots = $size_slots_base;
if (count($sizes) > count($size_slots_base)) {
    // NOT: insertNewRowBefore() BİLEREK kullanılmıyor — PhpSpreadsheet'te tüm
    // sayfayı yeniden indeksler, bu şablon boyutunda tek istekte dakikalarca
    // sürüp web isteğini kilitleyebiliyor (test edildi). Şablonda 41. satırın
    // altında zaten hiçbir içerik yok (kontrol edildi), o yüzden satır
    // "eklemeye" hiç gerek yok — doğrudan yeni satır numaralarına, 41'in
    // stilini/yüksekliğini kopyalayarak yazmak yeterli ve çok daha hızlı.
    $extra    = count($sizes) - count($size_slots_base);
    $lastRow  = end($size_slots_base);       // 41 — stil/yükseklik kaynağı
    // getRowDimension() int İSTER (PhpSpreadsheet ^2.3 strict types) — buraya
    // (string) cast konulursa TypeError ile 500 döner. $lastRow/$newRow zaten
    // int; cast gereksizdi ve canlıda "excel indir"i kırıyordu.
    $rowHeight = $sh->getRowDimension($lastRow)->getRowHeight();
    for ($i = 0; $i < $extra; $i++) {
        $newRow = $lastRow + 1 + $i;
        foreach (range('A', 'P') as $col) {
            $sh->duplicateStyle($sh->getStyle($col . $lastRow), $col . $newRow);
        }
        if ($rowHeight > 0) { $sh->getRowDimension($newRow)->setRowHeight($rowHeight); }
        $size_slots[] = $newRow;
    }
}
foreach ($size_slots as $i => $r) {
    $label = $sizes[$i] ?? '';
    if ($label === '') {
        // Kullanılmayan yuva — şablonun eski sabit değerini de temizle, aksi halde
        // H sütunu eski (8/9/12/14) etiketi göstermeye devam eder.
        $sh->setCellValue("H$r", null);
        $sh->setCellValue("I$r", null);
        $sh->setCellValue("J$r", null);
        $sh->setCellValue("K$r", null);
        $sh->setCellValue("L$r", null);
        $log[] = "H$r=(boş)";
        continue;
    }
    if (is_numeric($label)) { $setN("H$r", $label); } else { $setS("H$r", $label); }
    $setF("I$r", "=SUMIF(\$C\$$ROW0:\$C\$$ROWE,H$r,\$B\$$ROW0:\$B\$$ROWE)");          // KASA
    $setF("J$r", "=ROUND(SUMIF(\$C\$$ROW0:\$C\$$ROWE,H$r,\$D\$$ROW0:\$D\$$ROWE),0)"); // BRÜT
    $setF("K$r", "=ROUND(SUMIF(\$C\$$ROW0:\$C\$$ROWE,H$r,\$H\$$ROW0:\$H\$$ROWE),0)"); // NET
    $setF("L$r", "=COUNTIF(\$C\$$ROW0:\$C\$$ROWE,H$r)");                             // ADET (kaç palet)
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
