<?php
// =========================================================
// records.php - Yükleme kayıt listesi
// Sprint 17: tarih filtresi, sayfalama, JOIN sorgusu, mobil net KG
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('records.read');

function fmt_tarih_tr(?string $d): string {
    if (!$d) return '';
    static $ay = ['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
    $ts = strtotime($d);
    return $ts ? (int)date('j', $ts) . ' ' . $ay[(int)date('n', $ts)] : '';
}

// Ürün sahibi firma adına göre belirgin, sabit renk üret.
// CİHAT → kırmızı, ASYA → mavi; diğer firmalar paletten otomatik (aynı ad hep aynı renk).
function urun_sahibi_renk(string $name): string {
    $norm = mb_strtoupper(trim($name), 'UTF-8');
    $norm = strtr($norm, ['İ'=>'I','I'=>'I','Ş'=>'S','Ğ'=>'G','Ü'=>'U','Ö'=>'O','Ç'=>'C']);
    if ($norm === '') return '#6b7280';
    if (strpos($norm, 'CIHAT') !== false) return '#dc2626'; // kırmızı
    if (strpos($norm, 'ASYA')  !== false) return '#2563eb'; // mavi
    $palette = ['#059669','#7c3aed','#d97706','#db2777','#0891b2','#65a30d','#9333ea','#ea580c','#0d9488','#c026d3'];
    return $palette[crc32($norm) % count($palette)];
}

function valid_date(string $d): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
}

function fmt_date_short(?string $d): string {
    if (!$d) return '';
    $ts = strtotime($d);
    return $ts ? date('d.m.y', $ts) : '';
}

function fmt_dt_short(?string $dt): string {
    if (!$dt) return '';
    $ts = strtotime($dt);
    return $ts ? date('d.m.y - H:i', $ts) : '';
}

// Tüm filtreleri koruyarak URL üret
function rec_url(array $override = [], array $drop = []): string {
    global $durum_filter, $tarih_bas, $tarih_bit, $page, $sort, $sort_dir_lc;
    $p = [
        'durum'     => $durum_filter,
        'tarih_bas' => $tarih_bas,
        'tarih_bit' => $tarih_bit,
        'page'      => $page > 1 ? (string)$page : '',
        'sort'      => $sort,
        'dir'       => $sort !== '' ? $sort_dir_lc : '',
    ];
    foreach ($override as $k => $v) $p[$k] = $v;
    foreach ($drop    as $k)       unset($p[$k]);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null);
    return 'records.php' . ($p ? '?' . http_build_query($p) : '');
}

const REC_PER_PAGE = 50;

// ── GET parametreleri ──────────────────────────────────────
$durum_filter = trim((string)($_GET['durum']     ?? ''));
$tarih_bas    = trim((string)($_GET['tarih_bas'] ?? ''));
$tarih_bit    = trim((string)($_GET['tarih_bit'] ?? ''));
$page         = max(1, (int)($_GET['page'] ?? 1));
$sort         = trim((string)($_GET['sort']      ?? ''));
$sort_dir_lc  = strtolower(trim((string)($_GET['dir'] ?? ''))) === 'asc' ? 'asc' : 'desc';

$_sort_cols = [
    'tarih'       => 'r.tarih',
    'urun_sahibi' => 'COALESCE(us.name, "")',
    'firma'       => 'r.firma',
    'alici'       => 'r.alici',
    'parti_no'    => 'r.parti_no',
    'casus_no'    => 'r.casus_no',
];
if (!array_key_exists($sort, $_sort_cols)) $sort = '';

if (!in_array($durum_filter, ['islendi', 'yuklendi'], true)) $durum_filter = '';
if ($tarih_bas !== '' && !valid_date($tarih_bas)) $tarih_bas = '';
if ($tarih_bit !== '' && !valid_date($tarih_bit)) $tarih_bit = '';

// Hızlı tarih sabitleri
$today       = date('Y-m-d');
$week_start  = date('Y-m-d', strtotime('monday this week'));
$month_start = date('Y-m-d', strtotime('first day of this month'));

// Hangi hızlı buton aktif?
$quick_active = '';
if ($tarih_bas === $today && $tarih_bit === $today)                           $quick_active = 'bugun';
elseif ($tarih_bas === $week_start && $tarih_bit === $today)                  $quick_active = 'hafta';
elseif ($tarih_bas === $month_start && $tarih_bit === $today)                 $quick_active = 'ay';
elseif ($tarih_bas === '' && $tarih_bit === '')                               $quick_active = 'tumü';

// ── WHERE koşulları ────────────────────────────────────────
$where  = "WHERE r.type = 'yukleme'";
$params = [];

if ($durum_filter !== '') {
    $where .= " AND r.durum = :durum";
    $params[':durum'] = $durum_filter;
}
if ($tarih_bas !== '') {
    $where .= " AND r.tarih >= :tarih_bas";
    $params[':tarih_bas'] = $tarih_bas;
}
if ($tarih_bit !== '') {
    $where .= " AND r.tarih <= :tarih_bit";
    $params[':tarih_bit'] = $tarih_bit;
}

// Depo sorumluluğu: kullanıcı belirli depolarla sınırlıysa yalnız o depodaki paletleri olan kayıtlar
[$_depo_sql, $_depo_params] = depo_sql_records('r');
if ($_depo_sql !== '') {
    $where  .= $_depo_sql;
    $params  = array_merge($params, $_depo_params);
}

// ── Toplam kayıt (sayfalama için) ──────────────────────────
$st_count = db()->prepare("SELECT COUNT(*) FROM loading_records r $where");
$st_count->execute($params);
$total       = (int)$st_count->fetchColumn();
$total_pages = max(1, (int)ceil($total / REC_PER_PAGE));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * REC_PER_PAGE;

// ── Ana sorgu — JOIN + GROUP BY (N+1 yok) ─────────────────
if ($sort !== '') {
    $order = "ORDER BY " . $_sort_cols[$sort] . " " . strtoupper($sort_dir_lc) . ", r.id DESC";
} else {
    $order = "ORDER BY
        CASE WHEN COALESCE(r.durum,'')='' THEN 0 WHEN r.durum='islendi' THEN 1 ELSE 2 END ASC,
        COALESCE(r.tarih,'0000-00-00') DESC,
        r.id DESC";
}

$sql = "SELECT r.*,
               COUNT(p.id)                   AS toplam_palet,
               COALESCE(SUM(p.kasa_adeti),0) AS toplam_kasa,
               COALESCE(SUM(p.brut_kg),0)    AS toplam_brut,
               COALESCE(SUM(p.dara_kg),0)    AS toplam_dara,
               COALESCE(SUM(p.net_kg),0)     AS toplam_net,
               us.name                        AS urun_sahibi_adi
        FROM loading_records r
        LEFT JOIN loading_pallets p ON p.loading_record_id = r.id
        LEFT JOIN material_definitions us ON us.id = r.urun_sahibi_id AND us.type = 'firma'
        $where
        GROUP BY r.id
        $order
        LIMIT :lim OFFSET :off";

$st = db()->prepare($sql);
foreach ($params as $k => $v) {
    $st->bindValue($k, $v);
}
$st->bindValue(':lim', REC_PER_PAGE, PDO::PARAM_INT);
$st->bindValue(':off', $offset,      PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

// ── Detay satırı verisi: kasa dağılımı + ek malzemeler (batch, N+1 yok) ──
$kasa_bd_map = [];  // [rid][kasa_cinsi_adi] => toplam kasa_adeti
$em_map      = [];  // [rid][material_id]    => ['name','palet_count','total_qty']
if (!empty($rows)) {
    $rid_list = array_map(fn($r) => (int)$r['id'], $rows);
    $in_ph    = implode(',', array_fill(0, count($rid_list), '?'));

    // Kasa dağılımı
    $st_kbd = db()->prepare("
        SELECT p.loading_record_id AS rid, p.kasa_adeti,
               COALESCE(kc.name, '—') AS kasa_cinsi_adi
        FROM loading_pallets p
        LEFT JOIN material_definitions kc ON kc.id = p.kasa_cinsi_id
        WHERE p.loading_record_id IN ($in_ph) AND p.kasa_adeti > 0
    ");
    $st_kbd->execute($rid_list);
    foreach ($st_kbd->fetchAll() as $row) {
        $rid  = (int)$row['rid'];
        $name = trim((string)$row['kasa_cinsi_adi']) ?: '—';
        $kasa_bd_map[$rid][$name] = ($kasa_bd_map[$rid][$name] ?? 0) + (int)$row['kasa_adeti'];
    }

    // Ek malzemeler — kasa bazlı: quantity × kasa_adeti; palet bazlı: quantity
    $st_em = db()->prepare("
        SELECT p.loading_record_id AS rid, p.kasa_adeti,
               pm.material_id, pm.quantity,
               m.name AS material_name, m.type AS material_type
        FROM pallet_materials pm
        JOIN loading_pallets p ON p.id = pm.loading_pallet_id
        JOIN material_definitions m ON m.id = pm.material_id
        WHERE p.loading_record_id IN ($in_ph) AND pm.quantity > 0
    ");
    $st_em->execute($rid_list);
    foreach ($st_em->fetchAll() as $row) {
        $rid   = (int)$row['rid'];
        $kid   = (int)$row['material_id'];
        $basis = material_calc_basis((string)$row['material_type'], (string)$row['material_name']);
        $eff   = ($basis === 'kasa')
            ? (float)$row['quantity'] * (int)$row['kasa_adeti']
            : (float)$row['quantity'];
        if (!isset($em_map[$rid][$kid])) {
            $em_map[$rid][$kid] = ['name' => $row['material_name'], 'palet_count' => 0, 'total_qty' => 0.0];
        }
        $em_map[$rid][$kid]['palet_count']++;
        $em_map[$rid][$kid]['total_qty'] += $eff;
    }
}

$can_unlock = function_exists('can') && can('records.unlock');

render_header('Kayıtlar');
render_flash();
?>

<div class="page-head">
    <div>
        <h1 style="color:#166534">Yükleme Kayıtları</h1>
        <p class="muted">
            Toplam <?= $total ?> kayıt
            <?php if ($total_pages > 1): ?>
            · Sayfa <?= $page ?> / <?= $total_pages ?>
            <?php endif; ?>
        </p>
    </div>
    <a href="record_create.php" class="btn btn-primary btn-lg">+ Yeni Kayıt</a>
</div>

<!-- ── Hızlı tarih butonları ── -->
<div class="filter-pills">
    <a href="<?= rec_url(['tarih_bas' => '', 'tarih_bit' => '', 'page' => '']) ?>"
       class="pill<?= ($tarih_bas === '' && $tarih_bit === '') ? ' active' : '' ?>">Tümü</a>
    <a href="<?= rec_url(['tarih_bas' => $today,       'tarih_bit' => $today, 'page' => '']) ?>"
       class="pill<?= $quick_active === 'bugun' ? ' active' : '' ?>">Bugün</a>
    <a href="<?= rec_url(['tarih_bas' => $week_start,  'tarih_bit' => $today, 'page' => '']) ?>"
       class="pill<?= $quick_active === 'hafta' ? ' active' : '' ?>">Bu hafta</a>
    <a href="<?= rec_url(['tarih_bas' => $month_start, 'tarih_bit' => $today, 'page' => '']) ?>"
       class="pill<?= $quick_active === 'ay' ? ' active' : '' ?>">Bu ay</a>
    <span class="pill-sep"></span>
    <a href="<?= rec_url(['durum' => '',        'page' => '']) ?>"
       class="pill<?= $durum_filter === '' ? ' active' : '' ?>">Tümü</a>
    <a href="<?= rec_url(['durum' => 'islendi', 'page' => '']) ?>"
       class="pill<?= $durum_filter === 'islendi' ? ' active-islendi' : '' ?>">🟠 İşlendi</a>
    <a href="<?= rec_url(['durum' => 'yuklendi','page' => '']) ?>"
       class="pill<?= $durum_filter === 'yuklendi' ? ' active-yuklendi' : '' ?>">🟢 Yüklendi</a>
</div>

<?php if (empty($rows)): ?>
    <div class="empty">
        <?php if ($total === 0 && ($tarih_bas !== '' || $tarih_bit !== '' || $durum_filter !== '')): ?>
            <p>Filtre kriterlerine uyan kayıt bulunamadı.</p>
            <a href="records.php" class="btn btn-ghost">Filtreleri temizle</a>
        <?php else: ?>
            <p>Henüz kayıt yok.</p>
            <a href="record_create.php" class="btn btn-primary">İlk kaydı oluştur</a>
        <?php endif; ?>
    </div>
<?php else: ?>

    <?php
    $_sth = function(string $label, string $col) use ($sort, $sort_dir_lc): string {
        $is_active = $sort === $col;
        $next_dir  = ($is_active && $sort_dir_lc === 'desc') ? 'asc' : 'desc';
        $arrow     = $is_active ? ($sort_dir_lc === 'desc' ? ' ▼' : ' ▲') : '';
        $url       = rec_url(['sort' => $col, 'dir' => $next_dir, 'page' => '']);
        $cls       = 'th-sortable' . ($is_active ? ' th-sort-active' : '');
        return '<th class="' . $cls . '"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
             . htmlspecialchars($label, ENT_QUOTES) . $arrow . '</a></th>';
    };
    ?>
    <!-- PC: tablo -->
    <div class="table-wrap pc-only">
        <table class="data-table">
            <thead>
            <tr>
                <?= $_sth('Tarih', 'tarih') ?>
                <?= $_sth('Ürün Sahibi', 'urun_sahibi') ?>
                <?= $_sth('Firma', 'firma') ?>
                <?= $_sth('Parti No', 'parti_no') ?>
                <?= $_sth('Alıcı', 'alici') ?>
                <th>Bölge</th>
                <th>Ürün</th>
                <?= $_sth('Casus No', 'casus_no') ?>
                <th>Plaka</th>
                <th class="num">Palet</th>
                <th class="num">Kasa</th>
                <th class="num">Brüt</th>
                <th class="num">Dara</th>
                <th class="num">Net</th>
                <th class="actions-col">İşlemler</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $durum   = $r['durum'] ?? '';
                $locked  = !empty($r['locked_at']);
            ?>
                <tr class="rec-main-row <?= $durum === 'islendi' ? 'tr-islendi' : ($durum === 'yuklendi' ? 'tr-yuklendi' : '') ?>"
                    data-record-id="<?= (int)$r['id'] ?>"
                    data-durum="<?= h($durum) ?>"
                    data-tarih="<?= h($r['tarih'] ?? '') ?>"
                    title="Detay için tıklayın">
                    <td class="td-tarih-cell">
                        <div><?= $r['tarih'] ? h(fmt_date_short($r['tarih'])) : '—' ?></div>
                        <?php if (!empty($r['updated_at'])): ?>
                        <div class="td-son-duz"><?= h(fmt_dt_short($r['updated_at'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="td-urun-sahibi"><?php if ($r['urun_sahibi_adi']): ?><strong style="color:<?= urun_sahibi_renk($r['urun_sahibi_adi']) ?>"><?= h($r['urun_sahibi_adi']) ?></strong><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                    <td>
                        <?= h($r['firma']) ?>
                        <?php if ($locked): ?><span class="badge-locked">🔒</span><?php endif; ?>
                    </td>
                    <td><?= h($r['parti_no']) ?></td>
                    <td><?= h($r['alici']) ?></td>
                    <td><?= h($r['bolge']) ?></td>
                    <td><?= h($r['urun']) ?></td>
                    <td class="td-casus-no"><?= h($r['casus_no']) ?></td>
                    <td><?= h(trim($r['on_plaka'] . ' / ' . $r['arka_plaka'], ' /')) ?></td>
                    <td class="num"><?= (int)$r['toplam_palet'] ?></td>
                    <td class="num"><?= (int)$r['toplam_kasa'] ?></td>
                    <td class="num"><?= fmt_kg($r['toplam_brut']) ?></td>
                    <td class="num"><?= fmt_kg($r['toplam_dara']) ?></td>
                    <td class="num strong"><?= fmt_kg($r['toplam_net']) ?></td>
                    <td class="actions-col">
                        <a class="btn btn-sm" href="record_view.php?id=<?= (int)$r['id'] ?>">Görüntüle</a>
                        <?php if ($durum !== 'yuklendi' && !$locked): ?>
                        <button type="button"
                                class="btn btn-sm btn-durum-islendi<?= $durum === 'islendi' ? ' durum-done' : '' ?>"
                                data-durum-action="islendi">
                            <?= $durum === 'islendi' ? '✓ İşlendi' : 'İşle' ?>
                        </button>
                        <?php endif; ?>
                        <?php if (($durum === 'islendi' && !$locked) || ($durum === 'yuklendi' && $can_unlock)): ?>
                        <button type="button"
                                class="btn btn-sm btn-durum-yuklendi<?= $durum === 'yuklendi' ? ' durum-done' : '' ?>"
                                data-durum-action="yuklendi">
                            <?= $durum === 'yuklendi' ? '✓ Yüklendi' : 'Yükle' ?>
                        </button>
                        <?php endif; ?>
                        <div class="pc-kebab-wrap">
                            <button class="pc-kebab" type="button" title="İşlemler">⋮</button>
                            <div class="pc-dropdown" hidden>
                                <?php if (!$locked || $can_unlock): ?>
                                <a href="record_edit.php?id=<?= (int)$r['id'] ?>">✎ Düzenle</a>
                                <a href="record_delete.php?id=<?= (int)$r['id'] ?>" class="pc-drop-danger"
                                   onclick="return confirm('Bu kayıt ve tüm palet satırları silinecek. Emin misiniz?');">✕ Sil</a>
                                <?php else: ?>
                                <span class="pc-drop-disabled">🔒 Kilitli kayıt</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php
                // ── Açılır detay satırı: tabloda yer almayan tüm alanlar ──
                $_b = strtoupper(trim((string)($r['brand'] ?? '')));
                $_brand_names = ['ASYA'=>'ASYA FRESH','URAL'=>'URAL','URAS'=>'URAS ENERGY','AGRO'=>'AGRONATURAL'];
                $_brand_label = $_b !== '' ? ($_brand_names[$_b] ?? $_b) : '';
                $detail_pairs = [];
                $_add = function(string $label, $val) use (&$detail_pairs): void {
                    $val = trim((string)$val);
                    if ($val !== '') $detail_pairs[] = [$label, $val];
                };
                $_add('Gümrük',          $r['gumruk']          ?? '');
                $_add('Şoför Adı',       $r['sofor_adi']       ?? '');
                $_add('Nakliye Şirketi', $r['nakliye_sirketi'] ?? '');
                $_add('Ulaşım',          $r['ulasim']          ?? '');
                $_add('Telefon',         $r['telefon']         ?? '');
                $_add('Fatura No',       $r['fatura_no']       ?? '');
                if ((float)($r['nakliye_bedeli'] ?? 0) > 0) $_add('Nakliye Bedeli', fmt_money($r['nakliye_bedeli']));
                if ((float)($r['avans'] ?? 0) > 0)          $_add('Avans',         fmt_money($r['avans']));
                $_add('Marka',           $_brand_label);
                $_add('Not',             $r['etiket']     ?? '');
                $_add('Oluşturma',       fmt_dt_short($r['created_at'] ?? ''));
                $_add('Son Düzenleme',   fmt_dt_short($r['updated_at'] ?? ''));
                if ($locked && !empty($r['revision_reason'])) $_add('Revizyon Nedeni', $r['revision_reason']);
                ?>
                <tr class="rec-detail-row" hidden>
                    <td colspan="15">
                        <div class="rec-detail-wrap">
                            <?php if (empty($detail_pairs)): ?>
                            <span class="rec-detail-empty">Ek bilgi bulunmuyor.</span>
                            <?php else: foreach ($detail_pairs as [$lbl, $val]): ?>
                            <div class="rec-detail-item">
                                <span class="rdi-label"><?= h($lbl) ?></span>
                                <span class="rdi-value"><?= h($val) ?></span>
                            </div>
                            <?php endforeach; endif; ?>
                        </div>
                        <?php
                        $_kbd = $kasa_bd_map[(int)$r['id']] ?? [];
                        $_em  = $em_map[(int)$r['id']]      ?? [];
                        if (!empty($_kbd) || !empty($_em)):
                        ?>
                        <div class="rec-detail-stok">
                            <?php if (!empty($_kbd)): ?>
                            <div class="rds-block">
                                <div class="rds-title">Kasa Dağılımı</div>
                                <?php foreach ($_kbd as $kn => $ka): ?>
                                <div class="rds-row"><span><?= h($kn) ?></span><strong><?= (int)$ka ?></strong></div>
                                <?php endforeach; ?>
                                <div class="rds-row rds-total"><span>Toplam</span><strong><?= (int)$r['toplam_kasa'] ?></strong></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($_em)): ?>
                            <div class="rds-block rds-block-em">
                                <div class="rds-title">Ek Malzemeler</div>
                                <div class="rds-em-grid">
                                    <?php foreach ($_em as $eg): ?>
                                    <div class="rds-em-item">
                                        <span class="rds-em-name"><?= h($eg['name']) ?></span>
                                        <span class="rds-em-meta"><?= (int)$eg['palet_count'] ?> palet · <?= h(fmt_kg($eg['total_qty'])) ?> adet</span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobil: kart -->
    <div class="card-list mobile-only">
        <?php foreach ($rows as $r):
            $durum  = $r['durum'] ?? '';
            $locked = !empty($r['locked_at']);
        ?>
            <div class="record-card<?= $durum ? ' durum-' . h($durum) : '' ?>"
                 data-record-id="<?= (int)$r['id'] ?>"
                 data-durum="<?= h($durum) ?>">
                <div class="record-card-head">
                    <div>
                        <?php
                            $firma_label = $r['firma'] ?: ($r['parti_no'] ?: '—');
                            $ft = fmt_tarih_tr($r['tarih']);
                        ?>
                        <strong><?= h($firma_label) ?><?= $ft ? ' <span class="record-card-tarih">· ' . h($ft) . '</span>' : '' ?></strong>
                        <?php if ($locked): ?><span class="badge-locked">🔒 Kilitli</span><?php endif; ?>
                        <?php if ($r['parti_no'] || $r['casus_no']): ?><div class="record-card-firma"><?php
                            if ($r['parti_no']) echo 'Parti: ' . h($r['parti_no']);
                            if ($r['parti_no'] && $r['casus_no']) echo ' · ';
                            if ($r['casus_no']) echo 'Casus: ' . h($r['casus_no']);
                        ?></div><?php endif; ?>
                        <?php if ($r['etiket']): ?><div style="color:#2563eb;font-size:.82rem"><?= h($r['etiket']) ?></div><?php endif; ?>
                        <?php if ($r['urun_sahibi_adi']): ?><div style="font-size:.85rem">Ürün Sahibi: <strong style="color:<?= urun_sahibi_renk($r['urun_sahibi_adi']) ?>"><?= h($r['urun_sahibi_adi']) ?></strong></div><?php endif; ?>
                    </div>
                    <div class="pc-kebab-wrap">
                        <button class="pc-kebab" type="button" title="İşlemler">⋮</button>
                        <div class="pc-dropdown" hidden>
                            <?php if (!$locked || $can_unlock): ?>
                            <a href="record_edit.php?id=<?= (int)$r['id'] ?>">✎ Düzenle</a>
                            <a href="record_delete.php?id=<?= (int)$r['id'] ?>" class="pc-drop-danger"
                               onclick="return confirm('Bu kayıt ve tüm palet satırları silinecek. Emin misiniz?');">✕ Sil</a>
                            <?php else: ?>
                            <span class="pc-drop-disabled">🔒 Kilitli kayıt</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="record-card-body">
                    <?php if ($r['alici']): ?><div style="color:#c56a00;font-weight:600"><span class="lbl">Alıcı:</span> <?= h($r['alici']) ?></div><?php endif; ?>
                    <?php if ($r['bolge']): ?><div><span class="lbl">Bölge:</span> <?= h($r['bolge']) ?></div><?php endif; ?>
                    <?php if ($r['urun']): ?><div><span class="lbl">Ürün:</span> <?= h($r['urun']) ?></div><?php endif; ?>
                    <?php $plaka = trim($r['on_plaka'] . ' / ' . $r['arka_plaka'], ' /'); ?>
                    <?php if ($plaka): ?><div><span class="lbl">Plaka:</span> <?= h($plaka) ?></div><?php endif; ?>
                </div>
                <!-- Palet / Kasa / Brüt -->
                <div class="record-card-totals">
                    <div><span>Palet</span><strong><?= (int)$r['toplam_palet'] ?></strong></div>
                    <div><span>Kasa</span><strong><?= (int)$r['toplam_kasa'] ?></strong></div>
                    <div><span>Brüt kg</span><strong><?= fmt_kg($r['toplam_brut']) ?></strong></div>
                </div>
                <!-- Dara / Net — ayrı satır, net belirgin -->
                <div class="record-card-dara-net">
                    <div class="rcdn-item">
                        <span>Dara</span>
                        <strong><?= fmt_kg($r['toplam_dara']) ?> kg</strong>
                    </div>
                    <div class="rcdn-item rcdn-net">
                        <span>Net</span>
                        <strong><?= fmt_kg($r['toplam_net']) ?> kg</strong>
                    </div>
                </div>
                <div class="record-card-actions">
                    <a class="btn btn-sm" href="record_view.php?id=<?= (int)$r['id'] ?>">Görüntüle</a>
                    <?php if ($durum !== 'yuklendi' && !$locked): ?>
                    <button type="button"
                            class="btn btn-sm btn-durum-islendi<?= $durum === 'islendi' ? ' durum-done' : '' ?>"
                            data-durum-action="islendi">
                        <?= $durum === 'islendi' ? '✓ İşlendi' : 'İşle' ?>
                    </button>
                    <?php endif; ?>
                    <?php if (($durum === 'islendi' && !$locked) || ($durum === 'yuklendi' && $can_unlock)): ?>
                    <button type="button"
                            class="btn btn-sm btn-durum-yuklendi<?= $durum === 'yuklendi' ? ' durum-done' : '' ?>"
                            data-durum-action="yuklendi">
                        <?= $durum === 'yuklendi' ? '✓ Yüklendi' : 'Yükle' ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Sayfalama ── -->
    <?php if ($total_pages > 1): ?>
    <div class="rec-pagination">
        <span class="rec-pagination-info">
            Toplam <?= $total ?> kayıt · Sayfa <?= $page ?> / <?= $total_pages ?>
        </span>
        <div class="rec-pagination-btns">
            <?php if ($page > 1): ?>
            <a href="<?= rec_url(['page' => $page - 1]) ?>" class="btn btn-sm">← Önceki</a>
            <?php endif; ?>
            <?php
            // Sayfa numaraları: ilk, son, aktif etrafı
            $show_pages = [];
            for ($pg = 1; $pg <= $total_pages; $pg++) {
                if ($pg === 1 || $pg === $total_pages || abs($pg - $page) <= 2) {
                    $show_pages[] = $pg;
                }
            }
            $prev_pg = null;
            foreach ($show_pages as $pg):
                if ($prev_pg !== null && $pg - $prev_pg > 1): ?>
                <span class="rec-pg-ellipsis">…</span>
                <?php endif; ?>
                <a href="<?= rec_url(['page' => $pg]) ?>"
                   class="btn btn-sm<?= $pg === $page ? ' btn-primary' : '' ?>"><?= $pg ?></a>
            <?php $prev_pg = $pg; endforeach; ?>
            <?php if ($page < $total_pages): ?>
            <a href="<?= rec_url(['page' => $page + 1]) ?>" class="btn btn-sm">Sonraki →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

<?php endif; ?>

<!-- Yükleme Tarihi Onay Modalı -->
<div id="yukleModal" class="ym-overlay" hidden aria-modal="true" role="dialog">
    <div class="ym-box">
        <div class="ym-head">
            <span class="ym-icon">🚚</span>
            <strong>Yükleme Onayı</strong>
        </div>
        <p class="ym-desc">Yükleme tarihini doğrulayın veya güncelleyin.</p>
        <label class="ym-lbl">Yükleme Tarihi
            <input type="date" id="yukleModalTarih" class="ym-date-input">
        </label>
        <p class="ym-hint" id="yukleModalHint" hidden></p>
        <div class="ym-actions">
            <button id="yukleModalOnayla" class="btn btn-primary">Kaydet &amp; Yükle</button>
            <button id="yukleModalIptal"  class="btn btn-ghost">İptal</button>
        </div>
    </div>
</div>

<script>
(function () {
    var csrf      = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var canUnlock = <?= $can_unlock ? 'true' : 'false' ?>;

    /* ── Yükleme Modalı ── */
    var modal      = document.getElementById('yukleModal');
    var modalTarih = document.getElementById('yukleModalTarih');
    var modalHint  = document.getElementById('yukleModalHint');
    var modalOnayla= document.getElementById('yukleModalOnayla');
    var modalIptal = document.getElementById('yukleModalIptal');
    var _activeBtn = null, _activeContainer = null;

    function openYukleModal(btn, container) {
        _activeBtn       = btn;
        _activeContainer = container;
        var recTarih     = container.dataset.tarih || '';
        var today        = new Date().toISOString().slice(0, 10);
        modalTarih.value = recTarih;   // today fallback yok — kullanıcı seçmeli

        // Uyarı: kayıt tarihi varsa ve bugünden farklıysa göster
        if (recTarih && recTarih !== today) {
            var diff = Math.round((new Date(today) - new Date(recTarih)) / 86400000);
            if (diff > 0) {
                modalHint.textContent = '⚠ Kayıt tarihi ' + diff + ' gün önce. Yükleme tarihini seçin.';
                modalHint.hidden = false;
            } else {
                modalHint.hidden = true;
            }
        } else {
            modalHint.hidden = true;
        }
        modal.hidden = false;
        modalTarih.focus();
    }

    function closeYukleModal() {
        modal.hidden = true;
        if (_activeBtn) _activeBtn.disabled = false;
        _activeBtn = _activeContainer = null;
    }

    modalIptal.addEventListener('click', closeYukleModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeYukleModal(); });

    modalOnayla.addEventListener('click', function () {
        if (!_activeBtn || !_activeContainer) return;
        var tarih = modalTarih.value;
        if (!tarih) {
            modalHint.textContent = '⚠ Lütfen yükleme tarihini seçin.';
            modalHint.hidden = false;
            modalTarih.focus();
            return;
        }
        modalOnayla.disabled = true;
        submitDurum(_activeContainer, _activeBtn, 'yuklendi', '&tarih=' + encodeURIComponent(tarih), function () {
            // Tarihi güncelle tabloda
            _activeContainer.dataset.tarih = tarih;
            var tarihTd = _activeContainer.querySelector('.td-tarih-cell div:first-child');
            if (tarihTd) {
                // fmt_date_short: dd.mm.yy
                var p = tarih.split('-');
                tarihTd.textContent = p[2] + '.' + p[1] + '.' + p[0].slice(2);
            }
            modal.hidden = true;
            _activeBtn = _activeContainer = null;
        });
    });

    /* ── Durum güncelleme ── */
    function submitDurum(container, btn, targetDurum, extraBody, onSuccess) {
        fetch('record_durum.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id='    + encodeURIComponent(container.dataset.recordId)
                + '&durum='+ encodeURIComponent(targetDurum)
                + '&csrf=' + encodeURIComponent(csrf)
                + (extraBody || '')
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            if (modalOnayla) modalOnayla.disabled = false;
            if (!data.ok) { alert(data.msg || 'Hata oluştu.'); return; }

            container.dataset.durum = data.durum;
            var isTR = container.tagName === 'TR';
            container.classList.remove('durum-islendi', 'durum-yuklendi', 'tr-islendi', 'tr-yuklendi');
            if (data.durum === 'islendi') container.classList.add(isTR ? 'tr-islendi' : 'durum-islendi');
            if (data.durum === 'yuklendi') container.classList.add(isTR ? 'tr-yuklendi' : 'durum-yuklendi');

            var islendiBtn  = container.querySelector('[data-durum-action="islendi"]');
            var yuklendiBtn = container.querySelector('[data-durum-action="yuklendi"]');
            var actionsEl   = islendiBtn ? islendiBtn.parentNode : (yuklendiBtn ? yuklendiBtn.parentNode : null);

            if (data.durum === '') {
                if (islendiBtn) { islendiBtn.textContent = 'İşle'; islendiBtn.classList.remove('durum-done'); islendiBtn.style.display = ''; }
                if (yuklendiBtn) yuklendiBtn.remove();
            } else if (data.durum === 'islendi') {
                if (!islendiBtn && actionsEl) {
                    var nb = document.createElement('button');
                    nb.type = 'button'; nb.className = 'btn btn-sm btn-durum-islendi durum-done';
                    nb.dataset.durumAction = 'islendi'; nb.textContent = '✓ İşlendi';
                    actionsEl.appendChild(nb);
                } else if (islendiBtn) {
                    islendiBtn.textContent = '✓ İşlendi'; islendiBtn.classList.add('durum-done'); islendiBtn.style.display = '';
                }
                if (!yuklendiBtn && actionsEl) {
                    var ny = document.createElement('button');
                    ny.type = 'button'; ny.className = 'btn btn-sm btn-durum-yuklendi';
                    ny.dataset.durumAction = 'yuklendi'; ny.textContent = 'Yükle';
                    actionsEl.appendChild(ny);
                } else if (yuklendiBtn) {
                    yuklendiBtn.textContent = 'Yükle'; yuklendiBtn.classList.remove('durum-done'); yuklendiBtn.style.display = '';
                }
            } else if (data.durum === 'yuklendi') {
                if (islendiBtn) islendiBtn.remove();
                if (yuklendiBtn) {
                    if (canUnlock) {
                        yuklendiBtn.textContent = '✓ Yüklendi'; yuklendiBtn.classList.add('durum-done'); yuklendiBtn.style.display = '';
                    } else {
                        yuklendiBtn.remove();
                    }
                }
            }
            if (onSuccess) onSuccess();
        })
        .catch(function () { btn.disabled = false; if (modalOnayla) modalOnayla.disabled = false; alert('Bağlantı hatası.'); });
    }

    /* ── Click delegasyonu ── */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-durum-action]');
        if (!btn) return;
        var container = btn.closest('[data-record-id]');
        if (!container) return;

        var action       = btn.dataset.durumAction;
        var currentDurum = container.dataset.durum || '';

        if (action === 'islendi') {
            var msg = currentDurum === 'islendi' ? 'İşlendi iptal edilsin mi?' : 'Ürün işlendi mi?';
            var targetDurum = currentDurum === 'islendi' ? '' : 'islendi';
            if (!confirm(msg)) return;
            btn.disabled = true;
            submitDurum(container, btn, targetDurum, '');

        } else if (action === 'yuklendi') {
            if (currentDurum === 'yuklendi') {
                // Kilit açma — revizyon nedeni sor
                if (!confirm('Yüklendi iptal edilsin mi?')) return;
                var reason = prompt('Revizyon nedeni (zorunlu):');
                if (reason === null) return;
                reason = reason.trim();
                if (!reason) { alert('Revizyon nedeni boş bırakılamaz.'); return; }
                btn.disabled = true;
                submitDurum(container, btn, 'islendi', '&revision_reason=' + encodeURIComponent(reason));
            } else {
                // Yükleme — tarih onay modalı
                btn.disabled = true;
                openYukleModal(btn, container);
            }
        }
    });

    /* ── Satıra tıklayınca detay aç/kapa (PC tablo) ── */
    document.addEventListener('click', function (e) {
        // Buton/link/kebab/aksiyon sütunu tıklanınca detay açma
        if (e.target.closest('.actions-col') || e.target.closest('a') ||
            e.target.closest('button') || e.target.closest('input')) return;
        var row = e.target.closest('.rec-main-row');
        if (!row) return;
        var detail = row.nextElementSibling;
        if (!detail || !detail.classList.contains('rec-detail-row')) return;
        if (detail.hasAttribute('hidden')) {
            detail.removeAttribute('hidden');
            row.classList.add('rec-row-expanded');
        } else {
            detail.setAttribute('hidden', '');
            row.classList.remove('rec-row-expanded');
        }
    });
})();
</script>

<style>
/* ── Açılır detay satırı (PC tablo) ─────────────────────── */
.rec-main-row { cursor: pointer; }
.rec-main-row:hover { background: #f1f5f9; }
.rec-row-expanded { background: #eef2ff !important; }
.rec-detail-row > td {
    background: #f8fafc;
    padding: 10px 16px !important;
    border-bottom: 2px solid #e2e8f0;
}
.rec-detail-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: 9px 22px;
    align-items: flex-start;
}
.rec-detail-item { display: flex; flex-direction: column; min-width: 90px; }
.rdi-label {
    font-size: .66rem; text-transform: uppercase; letter-spacing: .4px;
    color: #94a3b8; font-weight: 700;
}
.rdi-value { font-size: .88rem; color: #1e293b; margin-top: 1px; }
.rec-detail-empty { color: #94a3b8; font-style: italic; font-size: .85rem; }

/* Kasa Dağılımı + Ek Malzemeler */
.rec-detail-stok {
    display: flex; flex-wrap: wrap; gap: 14px 30px;
    margin-top: 10px; padding-top: 10px; border-top: 1px dashed #cbd5e1;
}
.rds-block { min-width: 200px; }
.rds-block-em { flex: 1; }
.rds-title {
    font-size: .66rem; text-transform: uppercase; letter-spacing: .4px;
    color: #64748b; font-weight: 700; margin-bottom: 6px;
}
.rds-row {
    display: flex; justify-content: space-between; gap: 24px;
    font-size: .84rem; color: #1e293b; padding: 2px 0; max-width: 280px;
}
.rds-row.rds-total {
    border-top: 1px solid #e2e8f0; margin-top: 3px; padding-top: 4px; font-weight: 700;
}
.rds-em-grid { display: flex; flex-wrap: wrap; gap: 6px 12px; }
.rds-em-item {
    display: flex; flex-direction: column;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 6px;
    padding: 4px 10px; min-width: 110px;
}
.rds-em-name { font-size: .82rem; color: #1e293b; font-weight: 600; }
.rds-em-meta { font-size: .72rem; color: #64748b; margin-top: 1px; }
</style>

<?php render_footer(); ?>
