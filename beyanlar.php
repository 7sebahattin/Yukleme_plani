<?php
// =========================================================
// beyanlar.php — Gümrük beyanları listesi (Sprint Beyan-01)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!can_beyan('read')) forbidden();

function valid_date_beyan(string $d): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
}

function beyan_url(array $override = []): string {
    global $q, $f_status, $f_urun, $f_marka, $f_depo, $tarih_bas, $tarih_bit, $page;
    $p = [
        'q'         => $q,
        'status'    => $f_status,
        'urun'      => $f_urun,
        'marka'     => $f_marka,
        'depo'      => $f_depo,
        'tarih_bas' => $tarih_bas,
        'tarih_bit' => $tarih_bit,
        'page'      => $page > 1 ? (string)$page : '',
    ];
    foreach ($override as $k => $v) $p[$k] = $v;
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null);
    return 'beyanlar.php' . ($p ? '?' . http_build_query($p) : '');
}

const BEYAN_PER_PAGE = 50;

$q         = trim((string)($_GET['q']         ?? ''));
$f_status  = trim((string)($_GET['status']    ?? ''));
$f_urun    = trim((string)($_GET['urun']      ?? ''));
$f_marka   = trim((string)($_GET['marka']     ?? ''));
$f_depo    = trim((string)($_GET['depo']      ?? ''));
$tarih_bas = trim((string)($_GET['tarih_bas'] ?? ''));
$tarih_bit = trim((string)($_GET['tarih_bit'] ?? ''));
$page      = max(1, (int)($_GET['page'] ?? 1));

$valid_statuses = array_keys(beyan_statuses());
if (!in_array($f_status, $valid_statuses, true)) $f_status = '';
if ($tarih_bas !== '' && !valid_date_beyan($tarih_bas)) $tarih_bas = '';
if ($tarih_bit !== '' && !valid_date_beyan($tarih_bit)) $tarih_bit = '';

// ── WHERE koşulları ───────────────────────────────────────
$where  = "WHERE deleted_at IS NULL";
$params = [];

if ($q !== '') {
    $where .= " AND (party_no LIKE :q OR product_name LIKE :q OR product_variety LIKE :q
                OR buyer_name LIKE :q OR brand LIKE :q OR exit_depot LIKE :q
                OR company_name LIKE :q OR contact_person LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($f_status !== '') {
    $where .= " AND status = :status";
    $params[':status'] = $f_status;
}
if ($f_urun !== '') {
    $where .= " AND product_name LIKE :urun";
    $params[':urun'] = '%' . $f_urun . '%';
}
if ($f_marka !== '') {
    $where .= " AND brand LIKE :marka";
    $params[':marka'] = '%' . $f_marka . '%';
}
if ($f_depo !== '') {
    $where .= " AND exit_depot LIKE :depo";
    $params[':depo'] = '%' . $f_depo . '%';
}
// Beyanlar tüm kullanıcılara ve tüm depolarda görünür — depo kapsamı uygulanmaz.
if ($tarih_bas !== '') {
    $where .= " AND DATE(created_at) >= :tarih_bas";
    $params[':tarih_bas'] = $tarih_bas;
}
if ($tarih_bit !== '') {
    $where .= " AND DATE(created_at) <= :tarih_bit";
    $params[':tarih_bit'] = $tarih_bit;
}

$st_count = db()->prepare("SELECT COUNT(*) FROM customs_declarations $where");
$st_count->execute($params);
$total       = (int)$st_count->fetchColumn();
$total_pages = max(1, (int)ceil($total / BEYAN_PER_PAGE));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * BEYAN_PER_PAGE;

$st = db()->prepare("SELECT * FROM customs_declarations $where ORDER BY id DESC LIMIT :lim OFFSET :off");
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':lim', BEYAN_PER_PAGE, PDO::PARAM_INT);
$st->bindValue(':off', $offset,        PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

$statuses  = beyan_statuses();
$today     = date('Y-m-d');
$has_filter = $q !== '' || $f_status !== '' || $f_urun !== '' || $f_marka !== '' || $f_depo !== '' || $tarih_bas !== '' || $tarih_bit !== '';

render_header('Beyanlar');
render_flash();
?>

<div class="page-head">
    <div>
        <h1>🧾 Beyanlar</h1>
        <p class="muted">
            Toplam <?= $total ?> beyan
            <?php if ($total_pages > 1): ?> · Sayfa <?= $page ?> / <?= $total_pages ?><?php endif; ?>
        </p>
    </div>
    <?php if (can_beyan('write')): ?>
    <a href="beyan_create.php" class="btn btn-primary btn-lg">+ Yeni Beyan</a>
    <?php endif; ?>
</div>

<!-- ── Filtre formu ── -->
<form method="get" class="beyan-filter-form" id="beyanFilterForm">
    <div class="bff-main">
        <input type="search" name="q" value="<?= h($q) ?>"
               placeholder="Parti no, ürün, alıcı, marka, depo..." autocomplete="off">
        <button class="btn">Ara</button>
        <?php if ($has_filter): ?>
        <a href="beyanlar.php" class="btn btn-ghost">Temizle</a>
        <?php endif; ?>
    </div>

    <button type="button" class="beyan-filter-toggle" id="beyanFilterToggle">
        ▾ Detaylı Filtre<?php if ($tarih_bas !== '' || $tarih_bit !== '' || $f_urun !== '' || $f_marka !== '' || $f_depo !== ''): ?> <span style="color:var(--primary)">●</span><?php endif; ?>
    </button>

    <div class="bff-filters<?= ($tarih_bas !== '' || $tarih_bit !== '' || $f_urun !== '' || $f_marka !== '' || $f_depo !== '') ? ' bff-open' : '' ?>"
         id="beyanFilterPanel">
        <div>
            <label>Tarih (başlangıç)</label>
            <input type="date" name="tarih_bas" value="<?= h($tarih_bas) ?>" max="<?= $today ?>">
        </div>
        <div>
            <label>Tarih (bitiş)</label>
            <input type="date" name="tarih_bit" value="<?= h($tarih_bit) ?>" max="<?= $today ?>">
        </div>
        <div>
            <label>Ürün</label>
            <input type="text" name="urun" value="<?= h($f_urun) ?>" placeholder="KAYISI...">
        </div>
        <div>
            <label>Marka</label>
            <input type="text" name="marka" value="<?= h($f_marka) ?>" placeholder="URAS...">
        </div>
        <div>
            <label>Çıkış Depo</label>
            <input type="text" name="depo" value="<?= h($f_depo) ?>" placeholder="KARAMAN...">
        </div>
        <div style="align-self:flex-end;flex:0 0 auto;min-width:auto">
            <button class="btn btn-sm" style="white-space:nowrap">Filtrele</button>
        </div>
    </div>
</form>

<!-- ── Durum pilleri ── -->
<div class="filter-pills">
    <a href="<?= beyan_url(['status' => '', 'page' => '']) ?>"
       class="pill<?= $f_status === '' ? ' active' : '' ?>">Tümü</a>
    <?php foreach ($statuses as $sk => $sv): ?>
    <a href="<?= beyan_url(['status' => $sk, 'page' => '']) ?>"
       class="pill<?= $f_status === $sk ? ' active' : '' ?>">
        <?= h($sv['label']) ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if (empty($rows)): ?>
<div class="empty">
    <?php if ($has_filter): ?>
        <p>Filtre kriterlerine uyan beyan bulunamadı.</p>
        <a href="beyanlar.php" class="btn btn-ghost">Filtreleri temizle</a>
    <?php else: ?>
        <p>Henüz beyan kaydı yok.</p>
        <?php if (can_beyan('write')): ?>
        <a href="beyan_create.php" class="btn btn-primary">İlk beyanı oluştur</a>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php else: ?>

<!-- PC: tablo -->
<div class="table-wrap pc-only">
    <table class="data-table">
        <thead>
        <tr>
            <th>Tarih</th>
            <th>Parti No</th>
            <th>Ürün / Çeşit</th>
            <th class="num">Palet</th>
            <th class="num">Kasa</th>
            <th class="num">Brüt KG</th>
            <th class="num">Net KG</th>
            <th>Alıcı</th>
            <th>Marka</th>
            <th>Çıkış Depo</th>
            <th>Durum</th>
            <th class="actions-col">İşlem</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td class="muted" style="font-size:.82rem"><?= h(fmt_datetime($r['created_at'])) ?></td>
            <td><strong><?= h($r['party_no'] ?: '—') ?></strong></td>
            <td>
                <?= h($r['product_name'] ?: '—') ?>
                <?php if ($r['product_variety']): ?>
                <span class="muted"><?= h($r['product_variety']) ?></span>
                <?php endif; ?>
            </td>
            <td class="num"><?= $r['pallet_count'] !== null ? (int)$r['pallet_count'] : '—' ?></td>
            <td class="num"><?= $r['crate_count'] !== null ? number_format((int)$r['crate_count'], 0, ',', '.') : '—' ?></td>
            <td class="num"><?= $r['gross_kg'] !== null ? fmt_kg($r['gross_kg']) : '—' ?></td>
            <td class="num strong"><?= $r['net_kg'] !== null ? fmt_kg($r['net_kg']) : '—' ?></td>
            <td><?= h($r['buyer_name'] ?: '—') ?></td>
            <td><?= h($r['brand'] ?: '—') ?></td>
            <td><?= h($r['exit_depot'] ?: '—') ?></td>
            <td><?= beyan_badge_html($r['status']) ?><?php
                $hd = beyan_hks_durum_etiket($r['hks_durum'] ?? null);
                if ($hd !== '') echo ' <span class="beyan-badge" title="Hal Kayıt bildirimi">' . h($hd) . '</span>';
            ?></td>
            <td class="actions-col">
                <a class="btn btn-sm" href="beyan_view.php?id=<?= (int)$r['id'] ?>">Görüntüle</a>
                <?php if (can_beyan('write') && $r['status'] === 'yukleme_olustu'): ?>
                <form method="post" action="beyan_edit.php?id=<?= (int)$r['id'] ?>" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="status" value="yuklendi">
                    <input type="hidden" name="status_only" value="1">
                    <button type="submit" class="btn btn-sm btn-success"
                            onclick="return confirm('Bu beyanı YÜKLENDİ olarak işaretle?')">Yüklendi</button>
                </form>
                <?php endif; ?>
                <?php if (can_beyan('write')): ?>
                <a class="btn btn-sm btn-ghost" href="beyan_edit.php?id=<?= (int)$r['id'] ?>">Düzenle</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Mobil: kart listesi -->
<div class="card-list mobile-only">
    <?php foreach ($rows as $r): ?>
    <div class="beyan-card" style="cursor:default">
        <div class="beyan-card-head">
            <div>
                <div class="beyan-card-parti"><?= h($r['party_no'] ?: '(parti no yok)') ?></div>
                <div class="beyan-card-urun">
                    <?= h($r['product_name'] ?: '—') ?>
                    <?php if ($r['product_variety']): ?>
                    <span class="muted"><?= h($r['product_variety']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?= beyan_badge_html($r['status']) ?><?php
                $hd = beyan_hks_durum_etiket($r['hks_durum'] ?? null);
                if ($hd !== '') echo ' <span class="beyan-badge" title="Hal Kayıt bildirimi">' . h($hd) . '</span>';
            ?>
        </div>

        <div class="beyan-card-meta">
            <?php if ($r['pallet_count'] !== null): ?>
            <span><?= (int)$r['pallet_count'] ?> palet</span>
            <?php endif; ?>
            <?php if ($r['crate_count'] !== null): ?>
            <span><?= number_format((int)$r['crate_count'], 0, ',', '.') ?> kasa</span>
            <?php endif; ?>
            <?php if ($r['gross_kg'] !== null): ?>
            <span><?= fmt_kg($r['gross_kg']) ?> kg brüt</span>
            <?php endif; ?>
            <?php if ($r['net_kg'] !== null): ?>
            <span><?= fmt_kg($r['net_kg']) ?> kg net</span>
            <?php endif; ?>
            <?php if ($r['brand']): ?>
            <span><?= h($r['brand']) ?></span>
            <?php endif; ?>
            <?php if ($r['exit_depot']): ?>
            <span><?= h($r['exit_depot']) ?></span>
            <?php endif; ?>
        </div>

        <?php if ($r['buyer_name']): ?>
        <div class="beyan-card-alici muted">Alıcı: <?= h($r['buyer_name']) ?></div>
        <?php endif; ?>

        <div class="muted" style="font-size:.75rem;margin-top:4px">
            <?= h(fmt_datetime($r['created_at'])) ?>
        </div>

        <div class="beyan-card-actions">
            <a class="btn btn-sm" href="beyan_view.php?id=<?= (int)$r['id'] ?>">Görüntüle</a>
            <?php if (can_beyan('write') && $r['status'] === 'yukleme_olustu'): ?>
            <form method="post" action="beyan_edit.php?id=<?= (int)$r['id'] ?>" style="display:inline">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="status" value="yuklendi">
                <input type="hidden" name="status_only" value="1">
                <button type="submit" class="btn btn-sm btn-success"
                        onclick="return confirm('Bu beyanı YÜKLENDİ olarak işaretle?')">Yüklendi</button>
            </form>
            <?php endif; ?>
            <?php if (can_beyan('write')): ?>
            <a class="btn btn-sm btn-ghost" href="beyan_edit.php?id=<?= (int)$r['id'] ?>">Düzenle</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Sayfalama -->
<?php if ($total_pages > 1): ?>
<div class="pagination" style="margin-top:16px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap">
    <?php if ($page > 1): ?>
    <a href="<?= beyan_url(['page' => (string)($page - 1)]) ?>" class="btn btn-sm btn-ghost">← Önceki</a>
    <?php endif; ?>
    <span class="muted" style="line-height:32px;padding:0 8px">
        <?= $page ?> / <?= $total_pages ?>
    </span>
    <?php if ($page < $total_pages): ?>
    <a href="<?= beyan_url(['page' => (string)($page + 1)]) ?>" class="btn btn-sm btn-ghost">Sonraki →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<script>
(function () {
    var toggle = document.getElementById('beyanFilterToggle');
    var panel  = document.getElementById('beyanFilterPanel');
    if (toggle && panel) {
        toggle.addEventListener('click', function () {
            panel.classList.toggle('bff-open');
        });
    }
})();
</script>

<?php render_footer(); ?>
