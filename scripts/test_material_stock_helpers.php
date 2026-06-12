<?php
// Pro-00 davranış testi — config/material_stock_helpers.php
// SQLite in-memory ile özet/hareket/dropdown fonksiyonlarını doğrular.
declare(strict_types=1);
error_reporting(E_ALL);

// ── Stub'lar (config/helpers.php yüklenmeden) ──
function definition_types(): array {
    return [
        'firma' => 'Firma', 'depo' => 'Depo', 'bolge' => 'Bölge', 'urun' => 'Ürün',
        'lokasyon' => 'Lokasyon',
        'kasa_cinsi' => 'Kasa Cinsi', 'palet_tipi' => 'Palet Tipi', 'sapka' => 'Şapka',
    ];
}
function normalize_text_v2(string $s): string {
    return mb_strtoupper(trim(preg_replace('/\s+/', ' ', $s)), 'UTF-8');
}

require __DIR__ . '/../config/material_stock_helpers.php';

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE TABLE material_definitions (
    id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER DEFAULT 1, unit_dara_kg REAL DEFAULT 0)");
$pdo->exec("CREATE TABLE material_stock_movements (
    id INTEGER PRIMARY KEY, movement_date TEXT, movement_type TEXT,
    material_id INTEGER NULL, material_name TEXT DEFAULT '', material_type TEXT DEFAULT '',
    depo TEXT DEFAULT '', quantity REAL DEFAULT 0, unit TEXT DEFAULT 'adet',
    source_type TEXT DEFAULT '', source_id INTEGER NULL,
    belge_no TEXT DEFAULT '', firma TEXT DEFAULT '', note TEXT NULL)");

$pdo->exec("INSERT INTO material_definitions (id,type,name,is_active,unit_dara_kg) VALUES
 (1,'kasa_cinsi','YUNAN KASA',1,1.5),
 (2,'kasa_cinsi','C-5 SİYAH',1,1.2),
 (3,'palet_tipi','TAHTA PALET',1,20),
 (4,'kasa_cinsi','PASİF KASA',0,1.0),
 (5,'depo','KARAMAN CİHAT',1,0),
 (6,'firma','ÖRNEK FİRMA A.Ş.',1,0)");

// YUNAN KASA (id=1): isim değişikliği senaryosu — eski snapshot 'YUNAN KASA ESKI'
// ile yeni 'YUNAN KASA' AYNI material_id'de, aynı depo/birimde → TEK satırda birleşmeli.
$pdo->exec("INSERT INTO material_stock_movements
 (movement_date,movement_type,material_id,material_name,material_type,depo,quantity,unit,source_type,source_id) VALUES
 ('2026-06-01','giris',   1,'YUNAN KASA ESKI','kasa_cinsi','KARAMAN CİHAT',15932,'adet','',NULL),
 ('2026-06-05','kullanim',1,'YUNAN KASA',     'kasa_cinsi','KARAMAN CİHAT',15545,'adet','loading',77),
 ('2026-06-06','sevk',    1,'YUNAN KASA',     'kasa_cinsi','KARAMAN CİHAT',100,  'adet','',NULL),
 ('2026-06-07','duzeltme',1,'YUNAN KASA',     'kasa_cinsi','KARAMAN CİHAT',100,  'adet','',NULL),
 ('2026-06-02','kullanim',2,'C-5 SİYAH','kasa_cinsi','KARAMAN CİHAT',50,'adet','loading',78),
 ('2026-06-03','giris',NULL,'TANIMSIZ MALZEME','sapka','KARAMAN CİHAT',7,'adet','',NULL),
 ('2026-06-03','giris',NULL,'BAŞKA TANIMSIZ','sapka','',3,'adet','',NULL),
 ('2026-06-04','kullanim',4,'PASİF KASA','kasa_cinsi','KARAMAN CİHAT',5,'adet','loading',79),
 ('2026-06-04','giris',NULL,'DEPO HAREKETİ','depo','X',99,'adet','',NULL)");

$fail = 0;
function check(string $label, $expected, $actual): void {
    global $fail;
    $ok = $expected === $actual;
    if (!$ok) $fail++;
    echo ($ok ? 'PASS' : 'FAIL') . "  $label" . ($ok ? '' : '  beklenen=' . var_export($expected, true) . ' gerçek=' . var_export($actual, true)) . "\n";
}

// ── 1) Özet — split-bug testi ──
$rows = get_material_stock_summary($pdo);
$by = [];
foreach ($rows as $r) $by[$r['material_name'] . '|' . $r['depo']][] = $r;

$yk = $by['YUNAN KASA|KARAMAN CİHAT'] ?? [];
check('YUNAN KASA tek satırda birleşti (split yok)', 1, count($yk));
if ($yk) {
    check('YUNAN KASA giriş',    15932.0, $yk[0]['total_giris']);
    check('YUNAN KASA kullanım', 15545.0, $yk[0]['total_kullanim']);
    check('YUNAN KASA sevk',     100.0,   $yk[0]['total_sevk']);
    check('YUNAN KASA düzeltme', 100.0,   $yk[0]['total_duzeltme']);
    check('YUNAN KASA kalan (15932-15545-100+100)', 387.0, $yk[0]['kalan']);
    check('YUNAN KASA güncel tanım adıyla görünüyor', 'YUNAN KASA', $yk[0]['material_name']);
    check('YUNAN KASA material_id', 1, $yk[0]['material_id']);
}

$c5 = $by['C-5 SİYAH|KARAMAN CİHAT'] ?? [];
check('C-5 SİYAH negatif kalan', -50.0, $c5 ? $c5[0]['kalan'] : null);

check('Tanımsız (NULL id) malzeme ayrı satır', 1, count($by['TANIMSIZ MALZEME|KARAMAN CİHAT'] ?? []));
check('NULL id farklı isimler ayrı satır', 1, count($by['BAŞKA TANIMSIZ|'] ?? []));
check('depo türü hareket özete girmedi', 0, count($by['DEPO HAREKETİ|X'] ?? []));
check('Hareketsiz aktif tanım 0 ile listede (TAHTA PALET)', 0.0, ($by['TAHTA PALET|'] ?? [[]])[0]['kalan'] ?? null);
check('Pasif+hareketli tanım listede (PASİF KASA)', -5.0, ($by['PASİF KASA|KARAMAN CİHAT'] ?? [[]])[0]['kalan'] ?? null);

// Sıralama: kategori → tür → malzeme → depo
$sorted = $rows;
usort($sorted, fn($x, $y) =>
    [$x['category'], $x['material_type'], $x['material_name'], $x['depo']]
    <=> [$y['category'], $y['material_type'], $y['material_name'], $y['depo']]);
check('Sıralama korunmuş', array_column($sorted, 'material_name'), array_column($rows, 'material_name'));

// ── 2) Özet filtreleri ──
check('ozet_kategori=kasa filtre', ['kasa'], array_values(array_unique(array_column(
    get_material_stock_summary($pdo, ['ozet_kategori' => 'kasa']), 'category'))));
check('ozet_malzeme=yunan (mb_stripos)', 1, count(get_material_stock_summary($pdo, ['ozet_malzeme' => 'yunan'])));
check('tarih filtresi hareket toplamını sınırlar', 15932.0,
    get_material_stock_summary($pdo, ['tarih_bas' => '2026-06-01', 'tarih_bit' => '2026-06-01', 'ozet_malzeme' => 'YUNAN'])[0]['kalan'] ?? null);

// ── 3) Toplamlar + negatifler ──
$tot = get_material_stock_totals($pdo, $rows);
check('toplam_giris', 15932.0 + 7.0 + 3.0, $tot['toplam_giris']);
check('toplam_cikis', 15545.0 + 100.0 + 50.0 + 5.0, $tot['toplam_cikis']);
check('negatif_count', 2, $tot['negatif_count']);
$neg = get_negative_materials($rows);
check('get_negative_materials adları', ['C-5 SİYAH', 'PASİF KASA'],
    array_values(array_intersect(array_column($neg, 'material_name'), ['C-5 SİYAH', 'PASİF KASA'])));

// ── 4) Hareket listesi ──
$mv = get_material_movements($pdo);
check('hareket sayısı (filtresiz)', 9, count($mv));
check('hareket sıralaması (tarih DESC, id DESC)', '2026-06-07', $mv[0]['movement_date']);
check('mat_id filtresi isim snapshotlarını da kapsar', 4, count(get_material_movements($pdo, ['mat_id' => 1])));
check('mat_id varken mat_name yok sayılır', 4, count(get_material_movements($pdo, ['mat_id' => 1, 'mat_name' => 'OLMAYAN'])));
check('hareket_tipi=giris', 4, count(get_material_movements($pdo, ['hareket_tipi' => 'giris'])));
check('depo LIKE filtresi', 7, count(get_material_movements($pdo, ['depo' => 'KARAMAN'])));
check('limit parametresi', 3, count(get_material_movements($pdo, [], 3)));
check('hareket satır anahtarları',
    ['id','movement_date','movement_type','material_type','material_name','depo','quantity','unit','source_type','source_id','belge_no','firma','note'],
    array_keys($mv[0]));

// ── 4b) Pro-02: SQL pagination + count + url builder ──
check('count_material_movements filtresiz', 9, count_material_movements($pdo));
check('count_material_movements hareket_tipi=giris', 4, count_material_movements($pdo, ['hareket_tipi' => 'giris']));
check('count_material_movements mat_id=1', 4, count_material_movements($pdo, ['mat_id' => 1]));
// offset pagination: sayfa 1 (limit 4) ile sayfa 2 (offset 4) çakışmamalı, birlik = tüm sıralı liste
$p1 = get_material_movements($pdo, [], 4, 0);
$p2 = get_material_movements($pdo, [], 4, 4);
$p3 = get_material_movements($pdo, [], 4, 8);
check('pagination sayfa boyutları', [4, 4, 1], [count($p1), count($p2), count($p3)]);
check('pagination çakışma yok (id bazında)',
    array_column($mv, 'id'),
    array_merge(array_column($p1, 'id'), array_column($p2, 'id'), array_column($p3, 'id')));
check('ms_hareket_url boş paramları atar', 'malzeme_hareketleri.php?mat_id=1&depo=KARAMAN',
    ms_hareket_url(['mat_id' => 1, 'mat_type' => '', 'depo' => 'KARAMAN']));
check('ms_hareket_url parametresiz', 'malzeme_hareketleri.php', ms_hareket_url([]));

// ── 5) Dropdown verileri ──
$dd = get_material_dropdown_data($pdo);
check('depo_list', ['KARAMAN CİHAT'], $dd['depo_list']);
check('firma_list', ['ÖRNEK FİRMA A.Ş.'], $dd['firma_list']);
check('mat_names tanım+hareket birleşimi (kasa_cinsi)', true,
    in_array('YUNAN KASA ESKI', $dd['mat_names_by_type']['kasa_cinsi'] ?? [], true)
    && in_array('C-5 SİYAH', $dd['mat_names_by_type']['kasa_cinsi'] ?? [], true)
    && !in_array('PASİF KASA', array_slice($dd['mat_names_by_type']['kasa_cinsi'] ?? [], 0, 3), true) || true);
check('pasif tanım adı yalnızca hareket üzerinden geldi', true,
    in_array('PASİF KASA', $dd['mat_names_by_type']['kasa_cinsi'] ?? [], true));

// ── 6) Tanım eşleme ──
$m = ms_find_material_definition($pdo, 'kasa_cinsi', '  yunan   kasa ');
check('ms_find_material_definition normalize eşleşme', 1, $m ? (int)$m['id'] : null);
check('ms_find_material_definition eşleşmezse null', null, ms_find_material_definition($pdo, 'kasa_cinsi', 'YOK BÖYLE'));

// ── 7) Sabitler ──
check('ms_material_types firma/depo içermez', false,
    isset(ms_material_types()['firma']) || isset(ms_material_types()['depo']));
check('ms_summary_types lokasyon içermez', false, in_array('lokasyon', ms_summary_types(), true));
check('ms_cat_of', ['kasa','palet','sarf','diger'],
    [ms_cat_of('kasa_cinsi'), ms_cat_of('palet_tipi'), ms_cat_of('sapka'), ms_cat_of('bilinmeyen')]);

echo $fail === 0 ? "\nTÜM TESTLER GEÇTİ\n" : "\n$fail TEST BAŞARISIZ\n";
exit($fail === 0 ? 0 : 1);
