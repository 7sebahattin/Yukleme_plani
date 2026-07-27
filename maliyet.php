<?php
// =========================================================
// maliyet.php — Maliyet Hesabı listesi (Sprint Maliyet-01)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/cost_calc.php';

$auth_user = require_login();
cost_migrate();
require_maliyet('read');

const MALIYET_PER_PAGE = 40;

$q         = trim((string)($_GET['q'] ?? ''));
$f_urun    = trim((string)($_GET['urun'] ?? ''));
$f_status  = trim((string)($_GET['status'] ?? ''));
$tarih_bas = trim((string)($_GET['tarih_bas'] ?? ''));
$tarih_bit = trim((string)($_GET['tarih_bit'] ?? ''));
$page      = max(1, (int)($_GET['page'] ?? 1));

$valid_date = static fn(string $d): bool => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
if ($tarih_bas !== '' && !$valid_date($tarih_bas)) $tarih_bas = '';
if ($tarih_bit !== '' && !$valid_date($tarih_bit)) $tarih_bit = '';
if (!in_array($f_status, ['taslak', 'kesin'], true)) $f_status = '';

// cs. öneki zorunlu: aşağıdaki JOIN sonrası firma/alici gibi kolonlar
// loading_records'ta da var — önek olmadan "ambiguous column" hatası olur.
$where  = "WHERE cs.deleted_at IS NULL";
$params = [];

if ($q !== '') {
    $where .= " AND (cs.sheet_no LIKE :q OR cs.title LIKE :q OR cs.product LIKE :q OR cs.firma LIKE :q
                     OR cs.alici LIKE :q OR cs.plaka LIKE :q OR cs.gidecegi_yer LIKE :q OR cs.brand LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($f_urun !== '')   { $where .= " AND cs.product LIKE :urun";   $params[':urun']   = '%' . $f_urun . '%'; }
if ($f_status !== '') { $where .= " AND cs.status = :status";     $params[':status'] = $f_status; }
if ($tarih_bas !== '') { $where .= " AND cs.sheet_date >= :tb";   $params[':tb'] = $tarih_bas; }
if ($tarih_bit !== '') { $where .= " AND cs.sheet_date <= :tbt";  $params[':tbt'] = $tarih_bit; }

// Aktif depo kapsamı — deposu boş sayfalar tüm depolarda görünür
[$depo_sql, $depo_params] = depo_sql_column('cs.depo', ':udm');
$where .= $depo_sql;
$params = array_merge($params, $depo_params);

$total = 0;
$rows  = [];
try {
    $st = db()->prepare("SELECT COUNT(*) FROM cost_sheets cs $where");
    $st->execute($params);
    $total = (int)$st->fetchColumn();

    // Parti No CANLI okunur (LEFT JOIN) — cost_sheets'e kopyalanmış bir alan
    // değildir (bkz. config/cost_link.php madde 1: Belge No ≠ Parti No).
    // 1:1 ilişki (loading_records.id PRIMARY KEY) → satır sayısı çoğalmaz.
    $offset = ($page - 1) * MALIYET_PER_PAGE;
    $st = db()->prepare("SELECT cs.*, lr.parti_no AS linked_parti_no
                         FROM cost_sheets cs
                         LEFT JOIN loading_records lr ON lr.id = cs.record_id
                         $where
                         ORDER BY COALESCE(cs.sheet_date, DATE(cs.created_at)) DESC, cs.id DESC
                         LIMIT " . MALIYET_PER_PAGE . " OFFSET " . (int)$offset);
    $st->execute($params);
    $rows = $st->fetchAll();
} catch (PDOException $e) {
    error_log('[maliyet.php] ' . $e->getMessage());
}

$last_page = max(1, (int)ceil($total / MALIYET_PER_PAGE));

function maliyet_url(array $override = []): string {
    global $q, $f_urun, $f_status, $tarih_bas, $tarih_bit, $page;
    $p = ['q' => $q, 'urun' => $f_urun, 'status' => $f_status,
          'tarih_bas' => $tarih_bas, 'tarih_bit' => $tarih_bit,
          'page' => $page > 1 ? (string)$page : ''];
    foreach ($override as $k => $v) $p[$k] = $v;
    $p = array_filter($p, static fn($v) => $v !== '' && $v !== null);
    return 'maliyet.php' . ($p ? '?' . http_build_query($p) : '');
}

/** Liste için özet — totals_json snapshot'ı (kaydederken yazılır) */
function maliyet_totals(array $row): array {
    $t = [];
    if (!empty($row['totals_json'])) {
        $d = json_decode((string)$row['totals_json'], true);
        if (is_array($d)) $t = $d;
    }
    return $t + ['grand_total' => 0, 'unit_cost_tl' => 0, 'profit_fx' => 0,
                 'total_cost_fx' => 0, 'currency' => 'EUR', 'field_values' => []];
}

// Liste kolonu olarak işaretlenmiş kullanıcı alanları
$list_fields = array_values(array_filter(cost_fields(), static fn($f) => (int)$f['show_in_list'] === 1));

$urun_list = [];
try {
    $urun_list = db()->query("SELECT DISTINCT product FROM cost_sheets
                              WHERE deleted_at IS NULL AND product<>'' ORDER BY product")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

render_header('Maliyet Hesabı');
render_flash();
?>
<link rel="stylesheet" href="assets/maliyet.css?v=<?= @filemtime(__DIR__ . '/assets/maliyet.css') ?: time() ?>">

<div class="page-head">
    <div>
        <h1>🧮 Maliyet Hesabı</h1>
        <p class="muted">Parti bazlı maliyet · <?= number_format($total, 0, ',', '.') ?> hesap</p>
    </div>
    <div class="page-head-actions">
        <?php if (can_maliyet('write')): ?>
        <a href="maliyet_form.php" class="btn btn-primary btn-lg">+ Yeni Hesap</a>
        <?php endif; ?>
    </div>
</div>

<div class="mly-nav-links no-print">
    <a href="maliyet_ambalaj.php" class="btn btn-ghost">🏷️ Ambalaj Fiyatları</a>
    <?php if (can_maliyet('admin')): ?>
    <a href="maliyet_sablon.php" class="btn btn-ghost">📑 Şablonlar</a>
    <a href="maliyet_alanlar.php" class="btn btn-ghost">➕ Alan &amp; Formül Tanımları</a>
    <?php endif; ?>
</div>

<form method="get" class="rpt-filter-card no-print">
    <div class="rpt-filter-form">
        <div class="rpt-filter-group">
            <label for="f_q">Ara</label>
            <input type="search" id="f_q" name="q" value="<?= h($q) ?>" placeholder="Parti no, ürün, firma, plaka…">
        </div>
        <div class="rpt-filter-group">
            <label for="f_urun">Ürün</label>
            <select id="f_urun" name="urun">
                <option value="">Tümü</option>
                <?php foreach ($urun_list as $u): ?>
                <option value="<?= h($u) ?>" <?= $f_urun === $u ? 'selected' : '' ?>><?= h($u) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="rpt-filter-group">
            <label for="f_status">Durum</label>
            <select id="f_status" name="status">
                <option value="">Tümü</option>
                <option value="taslak" <?= $f_status === 'taslak' ? 'selected' : '' ?>>Taslak</option>
                <option value="kesin"  <?= $f_status === 'kesin'  ? 'selected' : '' ?>>Kesinleşti</option>
            </select>
        </div>
        <div class="rpt-filter-group">
            <label for="f_tb">Başlangıç</label>
            <input type="date" id="f_tb" name="tarih_bas" value="<?= h($tarih_bas) ?>">
        </div>
        <div class="rpt-filter-group">
            <label for="f_tbt">Bitiş</label>
            <input type="date" id="f_tbt" name="tarih_bit" value="<?= h($tarih_bit) ?>">
        </div>
        <div class="rpt-filter-actions">
            <button type="submit" class="btn btn-primary">Filtrele</button>
            <a href="maliyet.php" class="btn btn-ghost">Temizle</a>
        </div>
    </div>
</form>

<?php if (!$rows): ?>
<div class="empty">
    <p>Kayıtlı maliyet hesabı bulunamadı.</p>
    <?php if (can_maliyet('write')): ?>
    <a href="maliyet_form.php" class="btn btn-primary">İlk hesabı oluştur</a>
    <?php endif; ?>
</div>
<?php else: ?>

<!-- Masaüstü tablo -->
<div class="table-wrap pc-only">
    <table>
        <thead>
            <tr>
                <th>Tarih</th>
                <th>Belge No / Parti No</th>
                <th>Ürün / Marka</th>
                <th>Alıcı / Gideceği Yer</th>
                <th class="num">Net KG</th>
                <th class="num">TL/KG</th>
                <th class="num">Toplam (TL)</th>
                <th class="num">Kâr/Zarar</th>
                <?php foreach ($list_fields as $lf): ?>
                <th class="num"><?= h($lf['label']) ?></th>
                <?php endforeach; ?>
                <th>Durum</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $t     = maliyet_totals($r);
            $extra = json_decode((string)($r['extra_json'] ?? ''), true);
            if (!is_array($extra)) $extra = [];
        ?>
            <tr>
                <td><?= $r['sheet_date'] ? h(date('d.m.Y', strtotime((string)$r['sheet_date']))) : '—' ?></td>
                <td>
                    <a href="maliyet_view.php?id=<?= (int)$r['id'] ?>"><strong><?= h($r['sheet_no'] ?: ('#' . $r['id'])) ?></strong></a>
                    <?php if (!empty($r['linked_parti_no'])): ?>
                    <div class="muted" style="font-size:.78rem">Parti: <?= h($r['linked_parti_no']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= h($r['product']) ?><?= $r['brand'] ? ' <span class="muted">· ' . h($r['brand']) . '</span>' : '' ?></td>
                <td><?= h($r['alici'] ?: $r['firma']) ?><?= $r['gidecegi_yer'] ? ' <span class="muted">→ ' . h($r['gidecegi_yer']) . '</span>' : '' ?></td>
                <td class="num"><?= cost_fmt_qty($r['net_kg']) ?></td>
                <td class="num"><?= cost_fmt_unit($t['unit_cost_tl'], 2) ?></td>
                <td class="num"><?= cost_fmt_money($t['grand_total']) ?></td>
                <td class="num <?= (float)$t['profit_fx'] >= 0 ? 'mly-pos' : 'mly-neg' ?>">
                    <?= cost_fmt_money($t['profit_fx']) ?> <span class="muted"><?= h($t['currency']) ?></span>
                </td>
                <?php foreach ($list_fields as $lf):
                    $fv = $lf['field_type'] === 'formula'
                        ? ($t['field_values'][$lf['code']] ?? null)
                        : ($extra[$lf['code']] ?? '');
                ?>
                <td class="num"><?= $lf['field_type'] === 'formula' || $lf['field_type'] === 'number'
                        ? cost_fmt_money((float)$fv, (int)$lf['decimals']) : h((string)$fv) ?></td>
                <?php endforeach; ?>
                <td><span class="mly-badge mly-badge-<?= h($r['status']) ?>"><?= $r['status'] === 'kesin' ? 'Kesin' : 'Taslak' ?></span></td>
                <td class="right">
                    <a href="maliyet_view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm">Görüntüle</a>
                    <?php if (can_maliyet('write')): ?>
                    <a href="maliyet_form.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm">Düzenle</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Mobil kartlar -->
<div class="card-list mobile-only">
<?php foreach ($rows as $r): $t = maliyet_totals($r); ?>
    <a href="maliyet_view.php?id=<?= (int)$r['id'] ?>" class="mly-card">
        <div class="mly-card-top">
            <strong><?= h($r['sheet_no'] ?: ('#' . $r['id'])) ?></strong>
            <span class="mly-badge mly-badge-<?= h($r['status']) ?>"><?= $r['status'] === 'kesin' ? 'Kesin' : 'Taslak' ?></span>
        </div>
        <div class="mly-card-sub">
            <?= h($r['product'] ?: '—') ?>
            <?= $r['brand'] ? ' · ' . h($r['brand']) : '' ?>
            <?= $r['sheet_date'] ? ' · ' . h(date('d.m.Y', strtotime((string)$r['sheet_date']))) : '' ?>
            <?= !empty($r['linked_parti_no']) ? ' · Parti: ' . h($r['linked_parti_no']) : '' ?>
        </div>
        <div class="mly-card-stats">
            <span><em>Net KG</em><b><?= cost_fmt_qty($r['net_kg']) ?></b></span>
            <span><em>TL/KG</em><b><?= cost_fmt_unit($t['unit_cost_tl'], 2) ?></b></span>
            <span><em>Toplam</em><b><?= cost_fmt_money($t['grand_total'], 0) ?> ₺</b></span>
            <span class="<?= (float)$t['profit_fx'] >= 0 ? 'mly-pos' : 'mly-neg' ?>">
                <em>Kâr/Zarar</em><b><?= cost_fmt_money($t['profit_fx'], 0) ?> <?= h($t['currency']) ?></b>
            </span>
        </div>
    </a>
<?php endforeach; ?>
</div>

<?php if ($last_page > 1): ?>
<div class="mly-pager no-print">
    <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= h(maliyet_url(['page' => $page - 1])) ?>">‹ Önceki</a><?php endif; ?>
    <span class="muted"><?= $page ?> / <?= $last_page ?></span>
    <?php if ($page < $last_page): ?><a class="btn btn-sm" href="<?= h(maliyet_url(['page' => $page + 1])) ?>">Sonraki ›</a><?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>
<?php render_footer(); ?>
