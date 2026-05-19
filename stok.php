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
$f_parti     = trim($_GET['parti']     ?? '');
$sayim_kg    = isset($_GET['sayim_kg']) && $_GET['sayim_kg'] !== '' ? (float)$_GET['sayim_kg'] : null;
$is_csv      = isset($_GET['csv']);

// Kantar artık depo ve parti_no alanlarına sahip — filtreler uygulanabilir
$k_where  = [];
$k_params = [];
if ($f_tarih_bas !== '') { $k_where[] = "giris_tarih >= ?";   $k_params[] = $f_tarih_bas; }
if ($f_tarih_bit !== '') { $k_where[] = "giris_tarih <= ?";   $k_params[] = $f_tarih_bit . ' 23:59:59'; }
if ($f_firma     !== '') { $k_where[] = "firma_adi LIKE ?";   $k_params[] = '%' . $f_firma . '%'; }
if ($f_urun      !== '') { $k_where[] = "malin_cinsi LIKE ?"; $k_params[] = '%' . $f_urun . '%'; }
if ($f_depo      !== '') { $k_where[] = "depo LIKE ?";        $k_params[] = '%' . $f_depo . '%'; }
if ($f_parti     !== '') { $k_where[] = "parti_no LIKE ?";    $k_params[] = '%' . $f_parti . '%'; }
$k_where_sql  = $k_where ? 'WHERE ' . implode(' AND ', $k_where) : '';
$kantar_haric = false; // Artık hariç tutma yok — eski kayıtlar boş depo/parti ile kaldığı için uyarı gösterilir

if (!$kantar_haric) {
    $st = $pdo->prepare("SELECT COALESCE(SUM(net_kg),0) FROM kantar_fisleri $k_where_sql");
    $st->execute($k_params);
    $gelen_kg = (float)$st->fetchColumn();
} else {
    $gelen_kg = 0.0;
}

// ── Çıkan (loading_pallets + loading_records) ─────────────
$l_where  = ["lr.type IN ('yukleme','cikma')"];
$l_params = [];
if ($f_tarih_bas !== '') { $l_where[] = "lr.tarih >= ?";        $l_params[] = $f_tarih_bas; }
if ($f_tarih_bit !== '') { $l_where[] = "lr.tarih <= ?";        $l_params[] = $f_tarih_bit; }
if ($f_firma     !== '') { $l_where[] = "lr.firma LIKE ?";      $l_params[] = '%' . $f_firma . '%'; }
if ($f_urun      !== '') { $l_where[] = "lp.urun_cinsi LIKE ?"; $l_params[] = '%' . $f_urun . '%'; }
if ($f_depo      !== '') { $l_where[] = "lp.depo LIKE ?";       $l_params[] = '%' . $f_depo . '%'; }
if ($f_parti     !== '') { $l_where[] = "lr.parti_no LIKE ?";   $l_params[] = '%' . $f_parti . '%'; }
$l_where_sql = 'WHERE ' . implode(' AND ', $l_where);

$st = $pdo->prepare("SELECT COALESCE(SUM(lp.net_kg),0)
    FROM loading_pallets lp
    JOIN loading_records lr ON lp.loading_record_id = lr.id
    $l_where_sql");
$st->execute($l_params);
$cikan_kg = (float)$st->fetchColumn();

// Yükleme / Çıkma kırılımı
$st = $pdo->prepare("SELECT lr.type, COALESCE(SUM(lp.net_kg),0) AS kg
    FROM loading_pallets lp
    JOIN loading_records lr ON lp.loading_record_id = lr.id
    $l_where_sql GROUP BY lr.type");
$st->execute($l_params);
$cikan_kirilim = ['yukleme' => 0.0, 'cikma' => 0.0];
foreach ($st->fetchAll() as $row) {
    if (isset($cikan_kirilim[$row['type']])) {
        $cikan_kirilim[$row['type']] = (float)$row['kg'];
    }
}

$kalan_kg   = $gelen_kg - $cikan_kg;
$sayim_fark = $sayim_kg !== null ? ($sayim_kg - $kalan_kg) : null;

// ── Hareket satırları ─────────────────────────────────────
$st = $pdo->prepare("SELECT
    giris_tarih AS tarih, 'gelen' AS yon, firma_adi AS firma,
    malin_cinsi AS urun, depo AS depo, '' AS bolge, parti_no AS parti,
    net_kg, CONCAT('KNT-', fis_no) AS ref_no
  FROM kantar_fisleri $k_where_sql
  ORDER BY giris_tarih DESC, id DESC LIMIT 300");
$st->execute($k_params);
$kantar_rows = $st->fetchAll();

$st = $pdo->prepare("SELECT
    lr.tarih AS tarih, lr.type AS yon, lr.firma AS firma,
    lp.urun_cinsi AS urun, lp.depo AS depo, lr.bolge AS bolge,
    lr.parti_no AS parti, lp.net_kg, lr.parti_no AS ref_no, lr.id AS lr_id
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

// ── Eksik veri kontrolü (uyarılar) ───────────────────────
$uyari_data = [];
try {
    $chk = [
        ['SELECT COUNT(*) FROM kantar_fisleri WHERE net_kg = 0',
         'kantar fişinin net KG değeri sıfır.',
         'kantar.php'],
        ["SELECT COUNT(*) FROM kantar_fisleri WHERE giris_tarih NOT REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}'",
         "kantar fişinin giriş tarihi YYYY-AA-GG formatında değil — tarih filtresi bu kayıtları atlayabilir.",
         'kantar.php'],
        ["SELECT COUNT(*) FROM kantar_fisleri WHERE firma_adi = ''",
         'kantar fişinde firma alanı boş.',
         'kantar.php'],
        ["SELECT COUNT(*) FROM kantar_fisleri WHERE malin_cinsi = ''",
         'kantar fişinde mal cinsi boş.',
         'kantar.php'],
        ["SELECT COUNT(*) FROM kantar_fisleri WHERE depo = ''",
         'kantar fişinde depo bilgisi boş — depo filtresiyle bu girişler eşleşmez. Düzenleyerek depo ekleyin.',
         'kantar.php'],
        ["SELECT COUNT(*) FROM kantar_fisleri WHERE parti_no = ''",
         'kantar fişinde parti no boş — parti filtresiyle bu girişler eşleşmez. Düzenleyerek parti no ekleyin.',
         'kantar.php'],
        ['SELECT COUNT(*) FROM loading_pallets WHERE net_kg = 0',
         'palet satırının net KG değeri sıfır.',
         'records.php'],
        ["SELECT COUNT(*) FROM loading_pallets WHERE urun_cinsi = ''",
         'palet satırında ürün cinsi boş.',
         'records.php'],
        ["SELECT COUNT(*) FROM loading_pallets WHERE depo = ''",
         'palet satırında depo bilgisi boş.',
         'records.php'],
        ["SELECT COUNT(*) FROM loading_records lr
            WHERE lr.type IN ('yukleme','cikma')
              AND NOT EXISTS (SELECT 1 FROM loading_pallets lp WHERE lp.loading_record_id = lr.id)",
         'yükleme/çıkma kaydının palet satırı yok — bu kayıtlar çıkanda görünmez.',
         'records.php'],
    ];
    foreach ($chk as [$sql, $msg, $url]) {
        $n = (int)$pdo->query($sql)->fetchColumn();
        if ($n > 0) $uyari_data[] = ['count' => $n, 'msg' => $msg, 'url' => $url];
    }
} catch (PDOException $e) {}

// ── Dropdown listeleri ────────────────────────────────────
try {
    $firma_kantar  = $pdo->query("SELECT DISTINCT firma_adi FROM kantar_fisleri WHERE firma_adi != '' ORDER BY firma_adi")->fetchAll(PDO::FETCH_COLUMN);
    $firma_loading = $pdo->query("SELECT DISTINCT firma FROM loading_records WHERE firma != '' ORDER BY firma")->fetchAll(PDO::FETCH_COLUMN);
    $firma_list    = array_values(array_unique(array_merge($firma_kantar, $firma_loading)));
    sort($firma_list);
} catch (PDOException $e) { $firma_list = []; }

try {
    $urun_kantar  = $pdo->query("SELECT DISTINCT malin_cinsi FROM kantar_fisleri WHERE malin_cinsi != '' ORDER BY malin_cinsi")->fetchAll(PDO::FETCH_COLUMN);
    $urun_loading = $pdo->query("SELECT DISTINCT urun_cinsi FROM loading_pallets WHERE urun_cinsi != '' ORDER BY urun_cinsi")->fetchAll(PDO::FETCH_COLUMN);
    $urun_list    = array_values(array_unique(array_merge($urun_kantar, $urun_loading)));
    sort($urun_list);
} catch (PDOException $e) { $urun_list = []; }

try {
    $depo_list = $pdo->query("SELECT name FROM material_definitions WHERE type='depo' AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) { $depo_list = []; }

try {
    $parti_list = $pdo->query("SELECT DISTINCT parti_no FROM loading_records WHERE parti_no != '' ORDER BY parti_no DESC LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) { $parti_list = []; }

// ── CSV export ────────────────────────────────────────────
if ($is_csv) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="stok_hareket_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM

    // Filtre bilgisi
    $filtre_satirlari = [
        ['# Rapor', 'Depo Stok Hareketi'],
        ['# Tarih', date('d.m.Y H:i')],
        ['# Başlangıç', $f_tarih_bas ?: '—'],
        ['# Bitiş',     $f_tarih_bit ?: '—'],
        ['# Firma',     $f_firma     ?: 'Hepsi'],
        ['# Ürün',      $f_urun      ?: 'Hepsi'],
        ['# Depo',      $f_depo      ?: 'Hepsi'],
        ['# Parti No',  $f_parti     ?: 'Hepsi'],
        ['# Gelen KG',  number_format($gelen_kg, 3, ',', '.')],
        ['# Çıkan KG',  number_format($cikan_kg, 3, ',', '.')],
        ['# Kalan KG',  number_format($kalan_kg, 3, ',', '.')],
        ['#', ''],
    ];
    foreach ($filtre_satirlari as $sat) {
        fputcsv($out, $sat, ';', '"', '\\');
    }

    fputcsv($out, ['Tarih', 'Yön', 'Firma', 'Ürün', 'Depo', 'Bölge', 'Parti No', 'Net KG', 'Ref No'], ';', '"', '\\');
    foreach ($hareket_rows as $r) {
        $yon = match ($r['yon']) {
            'gelen'  => 'Gelen',
            'cikma'  => 'Çıkma',
            default  => 'Yükleme',
        };
        fputcsv($out, [
            $r['tarih'], $yon, $r['firma'], $r['urun'],
            $r['depo'], $r['bolge'], $r['parti'] ?? '',
            number_format((float)$r['net_kg'], 3, ',', '.'),
            $r['ref_no'],
        ], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

// Aktif filtre var mı?
$herhangi_filtre = $f_tarih_bas || $f_tarih_bit || $f_firma || $f_urun || $f_depo || $f_parti;

render_header('Stok Takip');
?>

<div class="page-head">
    <h2 class="page-title">📦 Depo Stok Takibi</h2>
    <a href="stok.php?<?= h(http_build_query(array_filter([
        'tarih_bas' => $f_tarih_bas, 'tarih_bit' => $f_tarih_bit,
        'firma' => $f_firma, 'urun' => $f_urun, 'depo' => $f_depo,
        'parti' => $f_parti,
        'sayim_kg' => $sayim_kg !== null ? (string)$sayim_kg : '',
        'csv' => '1',
    ], fn($v) => $v !== ''))) ?>" class="btn btn-sm btn-ghost">⬇ CSV</a>
</div>

<!-- ── Filtre Eşleşme Notu ───────────────────────────────── -->
<div class="stok-info-box">
    <span class="stok-info-icon">ℹ️</span>
    <span>Stok hesabı seçilen filtrelere göre hesaplanır.
    Kantar girişleri artık <strong>depo</strong> ve <strong>parti no</strong> alanlarına sahiptir.
    Eski kayıtlarda bu alanlar boş olabilir — depo/parti filtresi aktifken bu eski girişler hesaba <em>dahil edilmez</em>.
    Eski kayıtları güncellemek için <a href="kantar.php">Kantar Fişleri</a> listesinden düzenleyin.</span>
</div>

<!-- ── Filtre Formu ──────────────────────────────────────── -->
<div class="card" style="margin-bottom:14px">
    <form method="get" action="stok.php" class="stok-filter-form">
        <div class="stok-filter-row">
            <div class="form-group stok-fg">
                <label class="form-label">Başlangıç</label>
                <input type="date" name="tarih_bas" class="form-control" value="<?= h($f_tarih_bas) ?>">
            </div>
            <div class="form-group stok-fg">
                <label class="form-label">Bitiş</label>
                <input type="date" name="tarih_bit" class="form-control" value="<?= h($f_tarih_bit) ?>">
            </div>
            <div class="form-group stok-fg stok-fg-wide">
                <label class="form-label">Firma</label>
                <input type="text" name="firma" class="form-control" value="<?= h($f_firma) ?>"
                    list="stok-firma-list" placeholder="Hepsi" autocomplete="off">
                <datalist id="stok-firma-list">
                    <?php foreach ($firma_list as $fv): ?>
                    <option value="<?= h($fv) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group stok-fg stok-fg-wide">
                <label class="form-label">Ürün</label>
                <input type="text" name="urun" class="form-control" value="<?= h($f_urun) ?>"
                    list="stok-urun-list" placeholder="Hepsi" autocomplete="off">
                <datalist id="stok-urun-list">
                    <?php foreach ($urun_list as $uv): ?>
                    <option value="<?= h($uv) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group stok-fg stok-fg-wide">
                <label class="form-label">Depo</label>
                <select name="depo" class="form-control">
                    <option value="">Hepsi</option>
                    <?php foreach ($depo_list as $dv): ?>
                    <option value="<?= h($dv) ?>" <?= $f_depo === $dv ? 'selected' : '' ?>><?= h($dv) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group stok-fg stok-fg-wide">
                <label class="form-label">Parti No <small style="color:var(--muted)">(yükleme)</small></label>
                <input type="text" name="parti" class="form-control" value="<?= h($f_parti) ?>"
                    list="stok-parti-list" placeholder="Hepsi" autocomplete="off">
                <datalist id="stok-parti-list">
                    <?php foreach ($parti_list as $pv): ?>
                    <option value="<?= h($pv) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
        </div>
        <div class="stok-filter-actions">
            <button type="submit" class="btn btn-primary">Filtrele</button>
            <?php if ($herhangi_filtre): ?>
            <a href="stok.php" class="btn btn-ghost">Temizle</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if (!empty($uyari_data)): ?>
<!-- ── Eksik Veri Uyarıları ──────────────────────────────── -->
<details class="stok-uyari-box stok-uyari-detay">
    <summary style="cursor:pointer;font-weight:700;font-size:.88rem;color:#92400e">
        ⚠️ <?= count($uyari_data) ?> eksik veri uyarısı — tıklayın
    </summary>
    <div style="margin-top:8px;display:flex;flex-direction:column;gap:5px">
    <?php foreach ($uyari_data as $u): ?>
        <div class="stok-uyari-row">
            <a href="<?= h($u['url']) ?>" style="font-weight:700;color:inherit;text-decoration:underline"><?= (int)$u['count'] ?></a>
            <?= h($u['msg']) ?>
        </div>
    <?php endforeach; ?>
    </div>
</details>
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
        <div class="stok-kart-sub" style="display:flex;flex-direction:column;gap:2px">
            <?php if ($cikan_kirilim['yukleme'] > 0): ?>
            <span>↳ Yükleme: <?= fmt_kg($cikan_kirilim['yukleme']) ?> kg</span>
            <?php endif; ?>
            <?php if ($cikan_kirilim['cikma'] > 0): ?>
            <span>↳ Çıkma: <?= fmt_kg($cikan_kirilim['cikma']) ?> kg</span>
            <?php endif; ?>
            <?php if ($cikan_kirilim['yukleme'] == 0 && $cikan_kirilim['cikma'] == 0): ?>
            <span>Yükleme + Çıkma</span>
            <?php endif; ?>
        </div>
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
            <form method="get" action="stok.php" class="stok-sayim-form">
                <input type="hidden" name="tarih_bas" value="<?= h($f_tarih_bas) ?>">
                <input type="hidden" name="tarih_bit" value="<?= h($f_tarih_bit) ?>">
                <input type="hidden" name="firma"     value="<?= h($f_firma) ?>">
                <input type="hidden" name="urun"      value="<?= h($f_urun) ?>">
                <input type="hidden" name="depo"      value="<?= h($f_depo) ?>">
                <input type="hidden" name="parti"     value="<?= h($f_parti) ?>">
                <label class="stok-sayim-label">
                    <span>Sayım:</span>
                    <input type="number" name="sayim_kg" id="stokSayimInput" step="0.001"
                        class="form-control stok-sayim-input"
                        value="<?= $sayim_kg !== null ? h((string)$sayim_kg) : '' ?>"
                        placeholder="kg">
                    <button type="submit" class="btn btn-sm btn-primary" style="padding:4px 10px;flex-shrink:0">✓</button>
                </label>
            </form>
        </div>
    </div>
</div>

<!-- ── Hareket Tablosu ───────────────────────────────────── -->
<div class="card" style="margin-top:16px;padding:0">
    <div class="stok-table-head">
        <span style="font-weight:700;font-size:.97rem">Hareketler</span>
        <span style="font-size:.82rem;color:var(--muted)"><?= count($hareket_rows) ?> kayıt<?= count($hareket_rows) >= 300 ? ' (ilk 300)' : '' ?></span>
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
                    <th class="stok-hide-sm">Depo / Bölge</th>
                    <th class="stok-hide-sm">Parti / Ref</th>
                    <th style="text-align:right">Net KG</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($hareket_rows as $r):
                $yon         = $r['yon'];
                $badge_class = $yon === 'gelen' ? 'badge-gelen' : 'badge-cikan';
                $badge_label = match ($yon) {
                    'gelen'  => 'Gelen',
                    'cikma'  => 'Çıkma',
                    default  => 'Yükleme',
                };
                $depo_bolge = trim(implode(' / ', array_filter([(string)$r['depo'], (string)$r['bolge']])));
                $kg_color   = $yon === 'gelen' ? 'var(--success)' : 'var(--danger)';
            ?>
                <tr>
                    <td style="white-space:nowrap"><?= h(fmt_date($r['tarih'])) ?></td>
                    <td><span class="stok-badge <?= $badge_class ?>"><?= $badge_label ?></span></td>
                    <td><?= h($r['firma']) ?></td>
                    <td><?= h($r['urun']) ?></td>
                    <td class="stok-hide-sm"><?= h($depo_bolge) ?></td>
                    <td class="stok-hide-sm" style="font-size:.8rem;color:var(--muted)"><?= h($r['ref_no']) ?></td>
                    <td style="text-align:right;font-weight:700;color:<?= $kg_color ?>;white-space:nowrap">
                        <?= $yon !== 'gelen' ? '−' : '+' ?><?= h(fmt_kg($r['net_kg'])) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php render_footer(); ?>
