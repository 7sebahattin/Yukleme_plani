<?php
// =========================================================
// stok.php — Depo Stok Takibi
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$pdo = db();

// ── Filtreler ─────────────────────────────────────────────
$f_tarih_bas = trim($_GET['tarih_bas'] ?? '');
$f_tarih_bit = trim($_GET['tarih_bit'] ?? '');
$f_firma     = trim($_GET['firma']     ?? '');
$f_urun      = trim($_GET['urun']      ?? '');
$f_depo      = trim($_GET['depo']      ?? '');
$sayim_kg    = isset($_GET['sayim_kg']) && $_GET['sayim_kg'] !== '' ? (float)$_GET['sayim_kg'] : null;
$is_csv      = isset($_GET['csv']);

// ── Gelen (kantar_fisleri) ────────────────────────────────
$k_where  = [];
$k_params = [];
if ($f_tarih_bas !== '') { $k_where[] = "giris_tarih >= ?";    $k_params[] = $f_tarih_bas; }
if ($f_tarih_bit !== '') { $k_where[] = "giris_tarih <= ?";    $k_params[] = $f_tarih_bit . ' 23:59:59'; }
if ($f_firma     !== '') { $k_where[] = "firma_adi LIKE ?";    $k_params[] = '%' . $f_firma . '%'; }
if ($f_urun      !== '') { $k_where[] = "malin_cinsi LIKE ?";  $k_params[] = '%' . $f_urun . '%'; }
$k_where_sql = $k_where ? 'WHERE ' . implode(' AND ', $k_where) : '';

$st = $pdo->prepare("SELECT COALESCE(SUM(net_kg),0) FROM kantar_fisleri $k_where_sql");
$st->execute($k_params);
$gelen_kg = (float)$st->fetchColumn();

// ── Çıkan (loading_pallets + loading_records) ─────────────
$l_where  = ["lr.type IN ('yukleme','cikma')"];
$l_params = [];
if ($f_tarih_bas !== '') { $l_where[] = "lr.tarih >= ?";          $l_params[] = $f_tarih_bas; }
if ($f_tarih_bit !== '') { $l_where[] = "lr.tarih <= ?";          $l_params[] = $f_tarih_bit; }
if ($f_firma     !== '') { $l_where[] = "lr.firma LIKE ?";        $l_params[] = '%' . $f_firma . '%'; }
if ($f_urun      !== '') { $l_where[] = "lp.urun_cinsi LIKE ?";   $l_params[] = '%' . $f_urun . '%'; }
if ($f_depo      !== '') { $l_where[] = "lp.depo LIKE ?";         $l_params[] = '%' . $f_depo . '%'; }
$l_where_sql = 'WHERE ' . implode(' AND ', $l_where);

$st = $pdo->prepare("SELECT COALESCE(SUM(lp.net_kg),0)
    FROM loading_pallets lp
    JOIN loading_records lr ON lp.loading_record_id = lr.id
    $l_where_sql");
$st->execute($l_params);
$cikan_kg = (float)$st->fetchColumn();

$kalan_kg   = $gelen_kg - $cikan_kg;
$sayim_fark = $sayim_kg !== null ? ($sayim_kg - $kalan_kg) : null;

// ── Hareket satırları ─────────────────────────────────────
$st = $pdo->prepare("SELECT
    giris_tarih AS tarih, 'gelen' AS yon, firma_adi AS firma,
    malin_cinsi AS urun, '' AS depo, '' AS bolge,
    net_kg, CONCAT('KNT-', fis_no) AS ref_no
  FROM kantar_fisleri $k_where_sql
  ORDER BY giris_tarih DESC, id DESC LIMIT 300");
$st->execute($k_params);
$kantar_rows = $st->fetchAll();

$st = $pdo->prepare("SELECT
    lr.tarih AS tarih, lr.type AS yon, lr.firma AS firma,
    lp.urun_cinsi AS urun, lp.depo AS depo, lr.bolge AS bolge,
    lp.net_kg, lr.parti_no AS ref_no, lr.id AS lr_id
  FROM loading_pallets lp
  JOIN loading_records lr ON lp.loading_record_id = lr.id
  $l_where_sql
  ORDER BY lr.tarih DESC, lr.id DESC LIMIT 300");
$st->execute($l_params);
$loading_rows = $st->fetchAll();

// Birleştir ve tarihe göre sırala (yeniden eskiye)
$hareket_rows = array_merge($kantar_rows, $loading_rows);
usort($hareket_rows, function ($a, $b) {
    $cmp = strcmp((string)($b['tarih'] ?? ''), (string)($a['tarih'] ?? ''));
    return $cmp !== 0 ? $cmp : strcmp($b['yon'] ?? '', $a['yon'] ?? '');
});

// ── CSV export ────────────────────────────────────────────
if ($is_csv) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="stok_hareket_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM (Excel için)
    $cols = ['Tarih', 'Yön', 'Firma', 'Ürün', 'Depo', 'Bölge', 'Net KG', 'Ref No'];
    echo implode(';', $cols) . "\r\n";
    foreach ($hareket_rows as $r) {
        $yon = match ($r['yon']) {
            'gelen'  => 'Gelen',
            'cikma'  => 'Çıkma',
            default  => 'Yükleme',
        };
        $line = [
            $r['tarih'], $yon, $r['firma'], $r['urun'],
            $r['depo'], $r['bolge'],
            number_format((float)$r['net_kg'], 3, ',', '.'),
            $r['ref_no'],
        ];
        echo implode(';', array_map(
            fn($v) => '"' . str_replace('"', '""', (string)$v) . '"',
            $line
        )) . "\r\n";
    }
    exit;
}

// ── Dropdown listeleri ────────────────────────────────────
try {
    $firma_kantar  = $pdo->query("SELECT DISTINCT firma_adi FROM kantar_fisleri WHERE firma_adi != '' ORDER BY firma_adi")->fetchAll(PDO::FETCH_COLUMN);
    $firma_loading = $pdo->query("SELECT DISTINCT firma FROM loading_records WHERE firma != '' ORDER BY firma")->fetchAll(PDO::FETCH_COLUMN);
    $firma_list    = array_values(array_unique(array_merge($firma_kantar, $firma_loading)));
    sort($firma_list);
} catch (PDOException $e) { $firma_list = []; }

try {
    $urun_list = $pdo->query("SELECT DISTINCT malin_cinsi FROM kantar_fisleri WHERE malin_cinsi != '' ORDER BY malin_cinsi")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) { $urun_list = []; }

try {
    $depo_list = $pdo->query("SELECT name FROM material_definitions WHERE type='depo' AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) { $depo_list = []; }

// ── Uyarı kontrolleri ────────────────────────────────────
$uyarilar = [];
try {
    $sifir_net = (int)$pdo->query("SELECT COUNT(*) FROM kantar_fisleri WHERE net_kg = 0")->fetchColumn();
    if ($sifir_net > 0) $uyarilar[] = "Kantar'da $sifir_net fişin net KG değeri sıfır.";

    $sifir_palet = (int)$pdo->query("SELECT COUNT(*) FROM loading_pallets WHERE net_kg = 0")->fetchColumn();
    if ($sifir_palet > 0) $uyarilar[] = "$sifir_palet palet satırının net KG değeri sıfır.";

    $bad_date = (int)$pdo->query("SELECT COUNT(*) FROM kantar_fisleri WHERE giris_tarih NOT REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}'")->fetchColumn();
    if ($bad_date > 0) $uyarilar[] = "Kantarda $bad_date fişin giriş tarihi YYYY-AA-GG formatında değil — tarih filtresi bu kayıtları atlayabilir.";

    $no_pallet = (int)$pdo->query("SELECT COUNT(*) FROM loading_records lr
        WHERE lr.type IN ('yukleme','cikma')
          AND NOT EXISTS (SELECT 1 FROM loading_pallets lp WHERE lp.loading_record_id = lr.id)")->fetchColumn();
    if ($no_pallet > 0) $uyarilar[] = "$no_pallet yükleme/çıkma kaydının palet satırı yok — bu kayıtlar çıkanda görünmez.";
} catch (PDOException $e) {}

render_header('Stok Takip');
?>

<div class="page-head">
    <h2 class="page-title">📦 Depo Stok Takibi</h2>
    <a href="stok.php?<?= h(http_build_query(array_filter([
        'tarih_bas' => $f_tarih_bas, 'tarih_bit' => $f_tarih_bit,
        'firma' => $f_firma, 'urun' => $f_urun, 'depo' => $f_depo,
        'sayim_kg' => $sayim_kg !== null ? $sayim_kg : '',
        'csv' => '1',
    ]))) ?>" class="btn btn-sm btn-ghost">⬇ CSV</a>
</div>

<!-- ── Filtre Formu ──────────────────────────────────────── -->
<div class="card" style="margin-bottom:16px">
    <form method="get" action="stok.php" class="stok-filter-form">
        <div class="stok-filter-row">
            <div class="form-group" style="flex:1;min-width:140px">
                <label class="form-label">Başlangıç</label>
                <input type="date" name="tarih_bas" class="form-control" value="<?= h($f_tarih_bas) ?>">
            </div>
            <div class="form-group" style="flex:1;min-width:140px">
                <label class="form-label">Bitiş</label>
                <input type="date" name="tarih_bit" class="form-control" value="<?= h($f_tarih_bit) ?>">
            </div>
            <div class="form-group" style="flex:1.5;min-width:140px">
                <label class="form-label">Firma</label>
                <input type="text" name="firma" class="form-control" value="<?= h($f_firma) ?>"
                    list="stok-firma-list" placeholder="Hepsi">
                <datalist id="stok-firma-list">
                    <?php foreach ($firma_list as $f): ?>
                    <option value="<?= h($f) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group" style="flex:1.5;min-width:140px">
                <label class="form-label">Ürün</label>
                <input type="text" name="urun" class="form-control" value="<?= h($f_urun) ?>"
                    list="stok-urun-list" placeholder="Hepsi">
                <datalist id="stok-urun-list">
                    <?php foreach ($urun_list as $u): ?>
                    <option value="<?= h($u) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group" style="flex:1.5;min-width:140px">
                <label class="form-label">Depo</label>
                <select name="depo" class="form-control">
                    <option value="">Hepsi</option>
                    <?php foreach ($depo_list as $d): ?>
                    <option value="<?= h($d) ?>" <?= $f_depo === $d ? 'selected' : '' ?>><?= h($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="stok-filter-actions">
            <button type="submit" class="btn btn-primary">Filtrele</button>
            <?php if ($f_tarih_bas || $f_tarih_bit || $f_firma || $f_urun || $f_depo): ?>
            <a href="stok.php" class="btn btn-ghost">Temizle</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($uyarilar): ?>
<!-- ── Uyarılar ─────────────────────────────────────────── -->
<div class="stok-uyari-box">
    <?php foreach ($uyarilar as $u): ?>
    <div class="stok-uyari-row">⚠️ <?= h($u) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Özet Kartları ─────────────────────────────────────── -->
<div class="stok-ozet-grid">
    <div class="stok-kart stok-kart-gelen">
        <div class="stok-kart-label">Gelen</div>
        <div class="stok-kart-val"><?= fmt_kg($gelen_kg) ?> <small>kg</small></div>
        <div class="stok-kart-sub">Kantar girişi</div>
    </div>
    <div class="stok-kart stok-kart-cikan">
        <div class="stok-kart-label">Çıkan</div>
        <div class="stok-kart-val"><?= fmt_kg($cikan_kg) ?> <small>kg</small></div>
        <div class="stok-kart-sub">Yükleme + Çıkma</div>
    </div>
    <div class="stok-kart stok-kart-kalan">
        <div class="stok-kart-label">Kalan</div>
        <div class="stok-kart-val <?= $kalan_kg < 0 ? 'stok-negatif' : '' ?>"><?= fmt_kg($kalan_kg) ?> <small>kg</small></div>
        <div class="stok-kart-sub">Gelen − Çıkan</div>
    </div>
    <div class="stok-kart stok-kart-sayim">
        <div class="stok-kart-label">Sayım Farkı</div>
        <div class="stok-kart-val" id="stok-sayim-fark-val">
            <?php if ($sayim_fark !== null): ?>
            <span class="<?= $sayim_fark > 0 ? 'stok-pozitif' : ($sayim_fark < 0 ? 'stok-negatif' : '') ?>">
                <?= ($sayim_fark >= 0 ? '+' : '') . fmt_kg($sayim_fark) ?>
            </span>
            <small>kg</small>
            <?php else: ?>
            <span class="stok-sayim-gir">—</span>
            <?php endif; ?>
        </div>
        <div class="stok-kart-sub">
            <form method="get" action="stok.php" class="stok-sayim-form" id="stokSayimForm">
                <input type="hidden" name="tarih_bas" value="<?= h($f_tarih_bas) ?>">
                <input type="hidden" name="tarih_bit" value="<?= h($f_tarih_bit) ?>">
                <input type="hidden" name="firma"     value="<?= h($f_firma) ?>">
                <input type="hidden" name="urun"      value="<?= h($f_urun) ?>">
                <input type="hidden" name="depo"      value="<?= h($f_depo) ?>">
                <label style="display:flex;align-items:center;gap:5px;white-space:nowrap">
                    <span>Sayım:</span>
                    <input type="number" name="sayim_kg" id="stokSayimInput" step="0.001"
                        class="form-control stok-sayim-input"
                        value="<?= $sayim_kg !== null ? h($sayim_kg) : '' ?>"
                        placeholder="kg gir">
                    <button type="submit" class="btn btn-sm btn-primary" style="padding:4px 8px">✓</button>
                </label>
            </form>
        </div>
    </div>
</div>

<!-- ── Hareket Tablosu ───────────────────────────────────── -->
<div class="card" style="margin-top:20px;padding:0">
    <div style="padding:14px 16px 10px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <span style="font-weight:700;font-size:.97rem">Hareketler</span>
        <span style="font-size:.82rem;color:var(--muted)"><?= count($hareket_rows) ?> kayıt</span>
    </div>
    <?php if (empty($hareket_rows)): ?>
    <div style="padding:32px;text-align:center;color:var(--muted)">Filtre kriterlerine uygun hareket yok.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table stok-table">
            <thead>
                <tr>
                    <th>Tarih</th>
                    <th>Yön</th>
                    <th>Firma</th>
                    <th>Ürün</th>
                    <th class="hide-xs">Depo / Bölge</th>
                    <th class="hide-xs">Ref</th>
                    <th style="text-align:right">Net KG</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($hareket_rows as $r):
                $yon = $r['yon'];
                $badge_class = $yon === 'gelen' ? 'badge-gelen' : 'badge-cikan';
                $badge_label = match ($yon) {
                    'gelen'  => 'Gelen',
                    'cikma'  => 'Çıkma',
                    default  => 'Yükleme',
                };
                $depo_bolge = trim(implode(' / ', array_filter([(string)$r['depo'], (string)$r['bolge']])));
            ?>
                <tr>
                    <td><?= h(fmt_date($r['tarih'])) ?></td>
                    <td><span class="stok-badge <?= $badge_class ?>"><?= $badge_label ?></span></td>
                    <td><?= h($r['firma']) ?></td>
                    <td><?= h($r['urun']) ?></td>
                    <td class="hide-xs"><?= h($depo_bolge) ?></td>
                    <td class="hide-xs" style="font-size:.8rem;color:var(--muted)"><?= h($r['ref_no']) ?></td>
                    <td style="text-align:right;font-weight:600;<?= $yon !== 'gelen' ? 'color:var(--danger)' : 'color:var(--success)' ?>"><?= h(fmt_kg($r['net_kg'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php render_footer(); ?>
