<?php
// =========================================================
// scripts/yukleme_planlari_ui_smoke.php
// Yükleme Planları UI & İş Mantığı Kapsamlı Smoke Testi
// - Yalnız CLI; bellek içi SQLite kullanır, canlı DB'ye dokunmaz.
// - 7 gereksinimin tümünü (navigasyon, record_view aksiyonları,
//   çoklu malzeme tablosu + sync_malzeme_kullanim stok düşümü,
//   toplu malzeme modal + API, ürün birleştirme + urun_cesidi,
//   palet kartı tıklanabilirliği + PC/mobil malzeme görünürlüğü,
//   Excel yüklemenin kaldırılması + Yapıştır paneli genişletmesi)
//   doğrular.
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Yalnız CLI.');
}
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);

$PASS = 0;
$FAIL = 0;

function yp_assert(bool $cond, string $label, string $detail = ''): void {
    global $PASS, $FAIL;
    if ($cond) {
        $PASS++;
        echo "PASS  {$label}\n";
    } else {
        $FAIL++;
        echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function yp_assert_eq($expected, $actual, string $label): void {
    $ok = ($expected === $actual);
    $detail = $ok ? '' : 'beklenen=' . var_export($expected, true) . ' gerçek=' . var_export($actual, true);
    yp_assert($ok, $label, $detail);
}

/** Yalnız adlandırılmış gerçek fonksiyon gövdesini çıkarır; üst seviye PHP / DB bağlantısı çalışmaz. */
function yp_extract_function(string $source, string $name): string {
    $tokens = token_get_all($source);
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_FUNCTION) continue;
        $next = $i + 1;
        while (isset($tokens[$next]) && is_array($tokens[$next])
            && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $next++;
        }
        if (!isset($tokens[$next]) || !is_array($tokens[$next])
            || $tokens[$next][0] !== T_STRING || $tokens[$next][1] !== $name) {
            continue;
        }
        $body = '';
        $depth = 0;
        $opened = false;
        for ($j = $i; isset($tokens[$j]); $j++) {
            $part = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            $body .= $part;
            if ($part === '{') {
                $opened = true;
                $depth++;
            } elseif ($part === '}') {
                $depth--;
                if ($opened && $depth === 0) {
                    return $body;
                }
            }
        }
        throw new RuntimeException('Fonksiyon gövdesi kapanmadı: ' . $name);
    }
    throw new RuntimeException('Fonksiyon bulunamadı: ' . $name);
}

// ── Bellek içi SQLite kurulumu (canlı DB'ye sıfır erişim) ──
$TEST_PDO = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
function db(): PDO {
    global $TEST_PDO;
    return $TEST_PDO;
}

// ── Kaynak dosyaları oku ──
$srcHelpers   = (string)file_get_contents($ROOT . '/config/helpers.php');
$srcCalc      = (string)file_get_contents($ROOT . '/config/calc.php');
$srcCekirdek  = (string)file_get_contents($ROOT . '/config/cekirdek_migrate.php');
$srcMigrate   = (string)file_get_contents($ROOT . '/migrate.php');
$srcRecords   = (string)file_get_contents($ROOT . '/records.php');
$srcRecView   = (string)file_get_contents($ROOT . '/record_view.php');
$srcForm      = (string)file_get_contents($ROOT . '/_form.php');
$srcRecCreate = (string)file_get_contents($ROOT . '/record_create.php');
$srcRecEdit   = (string)file_get_contents($ROOT . '/record_edit.php');
$srcCikma     = (string)file_get_contents($ROOT . '/cikma_create.php');
$srcPrint     = (string)file_get_contents($ROOT . '/print_loading.php');
$srcBulkApi   = (string)file_get_contents($ROOT . '/api_bulk_material.php');
$srcAppJs     = (string)file_get_contents($ROOT . '/assets/app.js');
$srcStyleCss  = (string)file_get_contents($ROOT . '/assets/style.css');

// ── Gerçek üretim fonksiyonlarını izole olarak yükle ──
$helperFns = [
    'intval_safe',
    'tr_upper',
    'get_definitions_by_type',
    'definition_types',
    'non_material_definition_types',
    'pallet_material_types',
    'is_pallet_material_type',
    'nav_muhasebe_gruplari',
    'nav_eski_esleri',
    'record_suggest_fields',
    'mevcut_urun_listesi',
    'loading_record_text_limits',
    'material_calc_basis',
    'sync_malzeme_kullanim',
];
foreach ($helperFns as $fn) {
    eval(yp_extract_function($srcHelpers, $fn));
}
foreach (['parse_decimal', 'compute_pallet_row'] as $fn) {
    eval(yp_extract_function($srcCalc, $fn));
}

// ── SQLite şeması ──
$TEST_PDO->exec("
    CREATE TABLE material_definitions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL,
        name TEXT NOT NULL,
        unit_dara_kg REAL DEFAULT 0,
        is_active INTEGER DEFAULT 1
    );
    CREATE TABLE stok_kartlar (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tip TEXT NOT NULL,
        ad TEXT NOT NULL,
        aktif INTEGER DEFAULT 1
    );
    CREATE TABLE kantar_fisleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        depo TEXT DEFAULT '',
        malin_cinsi TEXT DEFAULT ''
    );
    CREATE TABLE loading_records (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL DEFAULT 'yukleme',
        tarih TEXT DEFAULT '',
        urun TEXT DEFAULT '',
        urun_cesidi TEXT NOT NULL DEFAULT ''
    );
    CREATE TABLE loading_pallets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        loading_record_id INTEGER NOT NULL,
        palet_no TEXT DEFAULT '',
        kasa_adeti INTEGER DEFAULT 0,
        size TEXT DEFAULT '',
        brut_kg REAL DEFAULT 0,
        dara_kg REAL DEFAULT 0,
        net_kg REAL DEFAULT 0,
        kasa_cinsi_id INTEGER NULL,
        palet_tipi_id INTEGER NULL,
        urun_cinsi TEXT DEFAULT '',
        urun_cesidi TEXT NOT NULL DEFAULT '',
        depo TEXT DEFAULT '',
        sira_no INTEGER DEFAULT 0,
        islendi INTEGER DEFAULT 0
    );
    CREATE TABLE pallet_materials (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        loading_pallet_id INTEGER NOT NULL,
        material_id INTEGER NOT NULL,
        quantity REAL NOT NULL DEFAULT 0,
        total_dara_kg REAL NOT NULL DEFAULT 0
    );
    CREATE TABLE material_stock_movements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        movement_date TEXT NOT NULL,
        movement_type TEXT NOT NULL,
        material_id INTEGER NULL,
        material_name TEXT NOT NULL DEFAULT '',
        material_type TEXT NOT NULL DEFAULT '',
        depo TEXT NOT NULL DEFAULT '',
        quantity REAL NOT NULL DEFAULT 0,
        unit TEXT NOT NULL DEFAULT 'adet',
        unit_dara_kg REAL NOT NULL DEFAULT 0,
        total_dara_kg REAL NOT NULL DEFAULT 0,
        source_type TEXT NOT NULL DEFAULT '',
        source_id INTEGER NULL,
        source_detail_id INTEGER NULL,
        note TEXT NULL
    );
");

// =========================================================================
// REQ 1: Menü & sayfa başlığı 'Yükleme Planları', slug ve izinler korunur
// =========================================================================
$gruplar = nav_muhasebe_gruplari();
yp_assert(
    isset($gruplar['operasyon_fis'][2]['arac_yukleme_fisleri.php']),
    'Req1: nav_muhasebe_gruplari() operasyon_fis grubunda arac_yukleme_fisleri.php anahtarı korunuyor'
);
yp_assert_eq(
    'Yükleme Planları',
    $gruplar['operasyon_fis'][2]['arac_yukleme_fisleri.php'][0] ?? null,
    'Req1: arac_yukleme_fisleri.php etiketi Yükleme Planları'
);
$eskiEsler = nav_eski_esleri();
yp_assert_eq(
    'arac_yukleme_fisleri',
    $eskiEsler['records'] ?? null,
    'Req1: nav_eski_esleri() records => arac_yukleme_fisleri slug eşlemesi korunuyor'
);
yp_assert(
    str_contains($srcRecords, "render_header('Yükleme Planları')"),
    "Req1: records.php render_header('Yükleme Planları') kullanıyor"
);
yp_assert(
    (bool)preg_match('/<h1[^>]*>\s*Yükleme Planları\s*<\/h1>/u', $srcRecords),
    'Req1: records.php H1 başlığı Yükleme Planları'
);
yp_assert(
    str_contains($srcRecords, "muhasebe_sayfa_can('arac_yukleme_fisleri')")
    && str_contains($srcRecords, "require_perm('records.loading.read')"),
    'Req1: records.php izin kontrolleri (arac_yukleme_fisleri / records.loading.read) korunuyor'
);

// =========================================================================
// REQ 2: record_view.php üst-sağ .rv-actions — kebab yok, tüm ikonlu butonlar var
// =========================================================================
$rvActionsMatch = [];
$hasRvActions = (bool)preg_match('/<div class="rv-actions">(.*?)<\/div>\s*<\/div>/s', $srcRecView, $rvActionsMatch);
yp_assert($hasRvActions, 'Req2: record_view.php içinde .rv-actions bloğu mevcut');
$rvActionsHtml = $rvActionsMatch[1] ?? '';

yp_assert(
    !str_contains($rvActionsHtml, 'pc-kebab-wrap') && !str_contains($rvActionsHtml, 'pc-dropdown'),
    'Req2: .rv-actions içinde .pc-kebab-wrap ve .pc-dropdown yok'
);
yp_assert(
    str_contains($rvActionsHtml, 'rv-act-btn') && str_contains($rvActionsHtml, 'rv-ico'),
    'Req2: .rv-actions ikonlu buton sınıflarını (.rv-act-btn, .rv-ico) içeriyor'
);
$expectedRvButtons = [
    'Düzenle'             => '<span>Düzenle</span>',
    'Yazdır'              => '<span>Yazdır</span>',
    'Özet Yazdır'         => '<span>Özet Yazdır</span>',
    'Excel İndir'         => '<span>Excel İndir</span>',
    '#bmOpenBtn'          => 'id="bmOpenBtn"',
    'Malzeme Çıkışı'      => '<span>Malzeme Çıkışı</span>',
    '#kalanOpenBtn'       => 'id="kalanOpenBtn"',
    'Kalan Palet Hesapla' => '<span>Kalan Palet Hesapla</span>',
    'Maliyet'             => '<span>Maliyet',
    'Maliyet Oluştur'     => '<span>Maliyet Oluştur</span>',
];
foreach ($expectedRvButtons as $lbl => $needle) {
    yp_assert(
        str_contains($rvActionsHtml, $needle),
        "Req2: .rv-actions içinde '{$lbl}' butonu mevcut"
    );
}

// =========================================================================
// REQ 3: Palet modalı çoklu malzeme tablosu + sync_malzeme_kullanim() stok düşümü
// =========================================================================
yp_assert(str_contains($srcForm, 'id="pmAddMaterial"'), 'Req3: _form.php #pmAddMaterial mevcut');
yp_assert(str_contains($srcForm, 'id="pmMatTableWrap"'), 'Req3: _form.php #pmMatTableWrap mevcut');
yp_assert(
    (bool)preg_match('/<table[^>]*class="[^"]*mat-pick-table[^"]*"[^>]*id="pmMatTable"|<table[^>]*id="pmMatTable"[^>]*class="[^"]*mat-pick-table[^"]*"/', $srcForm),
    'Req3: _form.php #pmMatTable.mat-pick-table mevcut'
);
$pmTableMatch = [];
$hasPmTable = (bool)preg_match('/<table[^>]*id="pmMatTable"[^>]*>(.*?)<\/table>/s', $srcForm, $pmTableMatch);
$pmTableHtml = $pmTableMatch[1] ?? '';
yp_assert(
    $hasPmTable
    && str_contains($pmTableHtml, '<th>Malzeme Cinsi</th>')
    && str_contains($pmTableHtml, '<th>Malzeme Adı</th>')
    && str_contains($pmTableHtml, '<th>Adet</th>'),
    'Req3: #pmMatTable başlıkları Malzeme Cinsi, Malzeme Adı, Adet'
);
yp_assert(
    (bool)preg_match('/<div[^>]*id="pmMaterialsList"[^>]*class="[^"]*mat-selected-list[^"]*"|<div[^>]*class="[^"]*mat-selected-list[^"]*"[^>]*id="pmMaterialsList"/', $srcForm),
    'Req3: _form.php #pmMaterialsList.mat-selected-list mevcut'
);
yp_assert(
    str_contains($srcAppJs, "getElementById('pmAddMaterial')")
    && str_contains($srcAppJs, "getElementById('pmMatTableWrap')")
    && str_contains($srcAppJs, "getElementById('pmMatTbody')")
    && str_contains($srcAppJs, "getElementById('pmMaterialsList')")
    && str_contains($srcAppJs, 'createMaterialPickerTable'),
    'Req3: assets/app.js palet modal malzeme tablosu kontrollerini bağlıyor'
);

// In-memory SQLite sync_malzeme_kullanim() testi (palet bazlı + kasa bazlı)
$TEST_PDO->exec("
    INSERT INTO material_definitions (id, type, name, unit_dara_kg, is_active) VALUES
    (1, 'kasa_cinsi',   'H-9 YEŞİL KASA',   1.5, 1),
    (2, 'palet_tipi',   'İHRACAT PALETİ',  20.0, 1),
    (3, 'sapka',        'KARTON ŞAPKA',     0.5, 1),
    (4, 'kasa_etiketi', '30X50 ETİKET',     0.0, 1),
    (5, 'kenar_kartonu','KENAR KARTONU 50', 0.1, 1);
");
$TEST_PDO->exec("
    INSERT INTO loading_records (id, type, tarih, urun, urun_cesidi) VALUES
    (100, 'yukleme', '2026-07-10', 'NEKTARİN', 'BIG TOP');
");
$TEST_PDO->exec("
    INSERT INTO loading_pallets
        (id, loading_record_id, palet_no, kasa_adeti, size, brut_kg, dara_kg, net_kg,
         kasa_cinsi_id, palet_tipi_id, urun_cinsi, urun_cesidi, depo, sira_no, islendi)
    VALUES
        (201, 100, '1', 80, 'AA', 1000, 140, 860, 1, 2, 'NEKTARİN', 'BIG TOP', 'MERKEZ DEPO', 0, 0);
");
// 3 = sapka (palet bazlı: qty=2 -> 2), 4 = kasa_etiketi (kasa bazlı: qty=1 * 80 = 80),
// 5 = kenar_kartonu (isim bazlı kasa: qty=0.5 * 80 = 40)
$TEST_PDO->exec("
    INSERT INTO pallet_materials (loading_pallet_id, material_id, quantity, total_dara_kg) VALUES
    (201, 3, 2,   0),
    (201, 4, 1,   0),
    (201, 5, 0.5, 0);
");

// İki kez çağırarak idempotency de doğrulanır
sync_malzeme_kullanim(100);
sync_malzeme_kullanim(100);

$mvRows = $TEST_PDO->query("
    SELECT material_id, material_type, movement_type, quantity, depo, source_type, source_id
    FROM material_stock_movements
    WHERE source_type = 'loading' AND source_id = 100
    ORDER BY material_id ASC
")->fetchAll();

yp_assert_eq(5, count($mvRows), 'Req3: sync_malzeme_kullanim() idempotent 5 hareket üretti (kasa + palet + 3 sarf)');
$mvByMat = [];
foreach ($mvRows as $r) {
    $mvByMat[(int)$r['material_id']] = $r;
}
yp_assert_eq('kullanim', $mvByMat[1]['movement_type'] ?? null, 'Req3: kasa hareketi movement_type=kullanim');
yp_assert_eq(80.0, (float)($mvByMat[1]['quantity'] ?? 0), 'Req3: kasa miktarı kasa_adeti (80)');
yp_assert_eq('kullanim', $mvByMat[2]['movement_type'] ?? null, 'Req3: palet hareketi movement_type=kullanim');
yp_assert_eq(1.0, (float)($mvByMat[2]['quantity'] ?? 0), 'Req3: palet miktarı 1');
yp_assert_eq('kullanim', $mvByMat[3]['movement_type'] ?? null, 'Req3: palet bazlı sarf (sapka) movement_type=kullanim');
yp_assert_eq(2.0, (float)($mvByMat[3]['quantity'] ?? 0), 'Req3: palet bazlı sarf miktarı doğrudan 2');
yp_assert_eq('kullanim', $mvByMat[4]['movement_type'] ?? null, 'Req3: kasa bazlı sarf (kasa_etiketi) movement_type=kullanim');
yp_assert_eq(80.0, (float)($mvByMat[4]['quantity'] ?? 0), 'Req3: kasa bazlı sarf miktarı 1 * 80 = 80');
yp_assert_eq('kullanim', $mvByMat[5]['movement_type'] ?? null, 'Req3: kasa bazlı sarf (kenar_kartonu) movement_type=kullanim');
yp_assert_eq(40.0, (float)($mvByMat[5]['quantity'] ?? 0), 'Req3: kasa bazlı sarf miktarı 0.5 * 80 = 40');

// =========================================================================
// REQ 4: record_view.php #bmPanelSingle çoklu malzeme tablosu + api_bulk_material.php
// =========================================================================
$posBmSingle   = strpos($srcRecView, 'id="bmPanelSingle"');
$posBmTable    = strpos($srcRecView, 'id="bmMatTable"');
$posBmPalHead  = strpos($srcRecView, 'class="bm-pallets-head"');
$posBmPalList  = strpos($srcRecView, 'class="bm-pallet-list"');

yp_assert($posBmSingle !== false, 'Req4: record_view.php #bmPanelSingle mevcut');
yp_assert(
    (bool)preg_match('/<table[^>]*class="[^"]*mat-pick-table[^"]*"[^>]*id="bmMatTable"|<table[^>]*id="bmMatTable"[^>]*class="[^"]*mat-pick-table[^"]*"/', $srcRecView),
    'Req4: record_view.php #bmMatTable.mat-pick-table mevcut'
);
$bmTableMatch = [];
$hasBmTable = (bool)preg_match('/<table[^>]*id="bmMatTable"[^>]*>(.*?)<\/table>/s', $srcRecView, $bmTableMatch);
$bmTableHtml = $bmTableMatch[1] ?? '';
yp_assert(
    $hasBmTable
    && str_contains($bmTableHtml, '<th>Malzeme Cinsi</th>')
    && str_contains($bmTableHtml, '<th>Malzeme Adı</th>')
    && str_contains($bmTableHtml, '<th>Adet</th>'),
    'Req4: #bmMatTable başlıkları Malzeme Cinsi, Malzeme Adı, Adet'
);
yp_assert(
    $posBmSingle !== false && $posBmTable !== false && $posBmPalHead !== false && $posBmPalList !== false
    && $posBmSingle < $posBmTable && $posBmTable < $posBmPalHead && $posBmPalHead < $posBmPalList,
    'Req4: #bmMatTable #bmPanelSingle içinde ve .bm-pallets-head / .bm-pallet-list bunun altında listeleniyor'
);
yp_assert(
    str_contains($srcBulkApi, "\$body['materials']") && str_contains($srcBulkApi, 'materials_input[]'),
    'Req4: api_bulk_material.php çoklu materials[] dizi girişini destekliyor'
);
yp_assert(
    str_contains($srcBulkApi, 'sync_malzeme_kullanim($record_id)'),
    'Req4: api_bulk_material.php sync_malzeme_kullanim($record_id) çağırıyor'
);
yp_assert(
    str_contains($srcBulkApi, "audit_log_event('bulk_add_materials'"),
    "Req4: api_bulk_material.php audit_log_event('bulk_add_materials', ...) kaydı yazıyor"
);

// =========================================================================
// REQ 5: mevcut_urun_listesi() birleşimi + urun_cesidi uçtan uca kapsamı
// =========================================================================
$TEST_PDO->exec("
    INSERT INTO material_definitions (type, name, unit_dara_kg, is_active) VALUES
    ('urun',  'şeftali',  0, 1),
    ('urun',  'elma',     0, 1),
    ('urun',  'pasif erik', 0, 0),
    ('firma', 'ACME TARIM', 0, 1);
    INSERT INTO stok_kartlar (tip, ad, aktif) VALUES
    ('urun',    'ŞEFTALİ',     1),
    ('urun',    'nektarin',    1),
    ('urun',    'pasif kayısı',0),
    ('malzeme', 'STREÇ FİLM',  1);
    INSERT INTO kantar_fisleri (depo, malin_cinsi) VALUES
    ('MERKEZ', 'üzüm'),
    ('MERKEZ', '  Elma  '),
    ('MERKEZ', '   '),
    ('MERKEZ', NULL);
");
$urunList = mevcut_urun_listesi();
yp_assert_eq(4, count($urunList), 'Req5: mevcut_urun_listesi() 3 kaynaktan tekilleştirilmiş 4 aktif ürün döndürdü');
foreach (['ELMA', 'NEKTARİN', 'ŞEFTALİ', 'ÜZÜM'] as $u) {
    yp_assert(in_array($u, $urunList, true), "Req5: mevcut_urun_listesi() '{$u}' ürününü içeriyor");
}
foreach (['PASİF ERİK', 'PASİF KAYISI', 'ACME TARIM', 'STREÇ FİLM'] as $excluded) {
    yp_assert(!in_array($excluded, $urunList, true), "Req5: mevcut_urun_listesi() '{$excluded}' değerini hariç tuttu");
}

$defTypes    = definition_types();
$nonMatTypes = non_material_definition_types();
$palMatTypes = pallet_material_types();
$suggestFlds = record_suggest_fields();
$textLimits  = loading_record_text_limits();

yp_assert(array_key_exists('urun_cesidi', $defTypes), 'Req5: urun_cesidi definition_types() içinde');
yp_assert(in_array('urun_cesidi', $nonMatTypes, true), 'Req5: urun_cesidi non_material_definition_types() içinde');
yp_assert(
    !in_array('urun_cesidi', $palMatTypes, true) && !is_pallet_material_type('urun_cesidi'),
    'Req5: urun_cesidi pallet_material_types() içinde DEĞİL'
);
yp_assert(isset($suggestFlds['urun_cesidi']), 'Req5: urun_cesidi record_suggest_fields() içinde');
yp_assert_eq(150, $textLimits['urun_cesidi'] ?? null, 'Req5: urun_cesidi loading_record_text_limits() içinde (150)');

$computedRow = compute_pallet_row([
    'palet_no'      => '7',
    'kasa_adeti'    => 50,
    'size'          => 'AAA',
    'brut_kg'       => '600,5',
    'kasa_cinsi_id' => 1,
    'palet_tipi_id' => 2,
    'urun_cinsi'    => 'NEKTARİN',
    'urun_cesidi'   => '  BIG TOP  ',
    'depo'          => 'MERKEZ DEPO',
    'materials'     => [['material_id' => 3, 'quantity' => 1]],
]);
yp_assert_eq('BIG TOP', $computedRow['urun_cesidi'] ?? null, 'Req5: compute_pallet_row() urun_cesidi alanını kırparak koruyor');
yp_assert_eq(95.0, $computedRow['dara_kg'], 'Req5: compute_pallet_row() dara hesabı doğru (50*1.5 + 20 = 95)');
yp_assert_eq(505.5, $computedRow['net_kg'], 'Req5: compute_pallet_row() net hesabı doğru (600.5 - 95 = 505.5)');

yp_assert(
    str_contains($srcCekirdek, "['loading_records', 'urun_cesidi']")
    && str_contains($srcCekirdek, "['loading_pallets', 'urun_cesidi']"),
    'Req5: config/cekirdek_migrate.php loading_records & loading_pallets urun_cesidi migrasyonunu içeriyor'
);
yp_assert(
    str_contains($srcMigrate, "['loading_records', 'urun_cesidi'")
    && str_contains($srcMigrate, "['loading_pallets', 'urun_cesidi'"),
    'Req5: migrate.php loading_records & loading_pallets urun_cesidi migrasyonunu içeriyor'
);
yp_assert(
    str_contains($srcForm, 'id="genelUrunCesidi"')
    && str_contains($srcForm, 'id="pmUrunCesidi"')
    && str_contains($srcForm, 'id="ypUrunCesidi"'),
    'Req5: _form.php #genelUrunCesidi, #pmUrunCesidi ve #ypUrunCesidi alanlarını içeriyor'
);
foreach ([
    'record_create.php' => $srcRecCreate,
    'record_edit.php'   => $srcRecEdit,
    'cikma_create.php'  => $srcCikma,
    'record_view.php'   => $srcRecView,
    'print_loading.php' => $srcPrint,
] as $fname => $fsrc) {
    yp_assert(str_contains($fsrc, 'urun_cesidi'), "Req5: {$fname} urun_cesidi alanını destekliyor");
}

// =========================================================================
// REQ 6: Palet kartları tıklanabilir, kebab yok, PC/mobil malzeme görünürlüğü
// =========================================================================
$rcStart = strpos($srcAppJs, 'function renderCards()');
$rcEnd   = $rcStart !== false ? strpos($srcAppJs, 'function renderTotals(', $rcStart) : false;
$rcBody  = ($rcStart !== false && $rcEnd !== false)
    ? substr($srcAppJs, $rcStart, $rcEnd - $rcStart)
    : ($rcStart !== false ? substr($srcAppJs, $rcStart, 5000) : '');

yp_assert($rcStart !== false, 'Req6: assets/app.js içinde renderCards() mevcut');
yp_assert(str_contains($rcBody, 'pallet-card pc-clickable'), 'Req6: renderCards() .pallet-card.pc-clickable sınıfı atıyor');
yp_assert(!str_contains($rcBody, 'pc-kebab-wrap'), 'Req6: renderCards() içinde .pc-kebab-wrap yok');
yp_assert(str_contains($srcForm, 'id="pmDelete"'), 'Req6: _form.php #pmDelete butonunu içeriyor');
yp_assert(str_contains($srcAppJs, "getElementById('pmDelete')"), 'Req6: assets/app.js #pmDelete olayını bağlıyor');
yp_assert(
    str_contains($rcBody, 'pc-mat-desktop') && str_contains($rcBody, 'pc-mat-tag'),
    'Req6: renderCards() masaüstü tam malzeme listesi (.pc-mat-desktop + .pc-mat-tag) üretiyor'
);
yp_assert(
    str_contains($rcBody, 'pc-mat-mobile'),
    'Req6: renderCards() mobil özet malzeme sayacı (.pc-mat-mobile) üretiyor'
);
yp_assert(
    (bool)preg_match('/\.pc-mat-mobile\s*\{\s*display\s*:\s*none\s*;/s', $srcStyleCss),
    'Req6: assets/style.css masaüstünde .pc-mat-mobile gizliyor'
);
yp_assert(
    (bool)preg_match('/@media\s*\(max-width:\s*767px\)\s*\{[^}]*\.pc-mat-desktop\s*\{\s*display\s*:\s*none\s*;\s*\}[^}]*\.pc-mat-mobile\s*\{\s*display\s*:\s*inline\s*;\s*\}/s', $srcStyleCss),
    'Req6: assets/style.css <=767px mobilde .pc-mat-desktop gizleyip .pc-mat-mobile gösteriyor'
);

// =========================================================================
// REQ 7: Excel yükleme kaldırıldı, Yapıştır paneli genişletildi
// =========================================================================
foreach (['excelAcBtn', 'excelPanel', 'xlsx.full.min.js', 'cdn.jsdelivr.net/npm/xlsx'] as $removed) {
    yp_assert(!str_contains($srcForm, $removed), "Req7: _form.php içinde '{$removed}' yok");
    yp_assert(!str_contains($srcAppJs, $removed), "Req7: assets/app.js içinde '{$removed}' yok");
}
yp_assert(str_contains($srcForm, 'id="yapistirPanel"'), 'Req7: _form.php #yapistirPanel mevcut');
foreach (['ypPaletNo', 'ypKasa', 'ypBrut', 'ypSize', 'ypUrun', 'ypUrunCesidi', 'ypMatToggle', 'ypMatTbody'] as $ypId) {
    yp_assert(str_contains($srcForm, 'id="' . $ypId . '"'), "Req7: _form.php #yapistirPanel içinde #{$ypId} mevcut");
    yp_assert(str_contains($srcAppJs, "getElementById('{$ypId}')"), "Req7: assets/app.js #{$ypId} elemanını bağlıyor");
}

echo "\nToplam: " . ($PASS + $FAIL) . " kontrol, {$PASS} geçti, {$FAIL} başarısız.\n";
exit($FAIL === 0 ? 0 : 1);
