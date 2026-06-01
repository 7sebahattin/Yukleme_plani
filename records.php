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

function valid_date(string $d): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
}

// Tüm filtreleri koruyarak URL üret
function rec_url(array $override = [], array $drop = []): string {
    global $q, $durum_filter, $tarih_bas, $tarih_bit, $page;
    $p = [
        'q'         => $q,
        'durum'     => $durum_filter,
        'tarih_bas' => $tarih_bas,
        'tarih_bit' => $tarih_bit,
        'page'      => $page > 1 ? (string)$page : '',
    ];
    foreach ($override as $k => $v) $p[$k] = $v;
    foreach ($drop    as $k)       unset($p[$k]);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null);
    return 'records.php' . ($p ? '?' . http_build_query($p) : '');
}

const REC_PER_PAGE = 50;

// ── GET parametreleri ──────────────────────────────────────
$q            = trim((string)($_GET['q']         ?? ''));
$durum_filter = trim((string)($_GET['durum']     ?? ''));
$tarih_bas    = trim((string)($_GET['tarih_bas'] ?? ''));
$tarih_bit    = trim((string)($_GET['tarih_bit'] ?? ''));
$page         = max(1, (int)($_GET['page'] ?? 1));

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

if ($q !== '') {
    $where .= " AND (r.firma LIKE :q OR r.bolge LIKE :q OR r.alici LIKE :q
               OR r.parti_no LIKE :q OR r.on_plaka LIKE :q OR r.arka_plaka LIKE :q
               OR r.urun LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
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

// ── Toplam kayıt (sayfalama için) ──────────────────────────
$st_count = db()->prepare("SELECT COUNT(*) FROM loading_records r $where");
$st_count->execute($params);
$total       = (int)$st_count->fetchColumn();
$total_pages = max(1, (int)ceil($total / REC_PER_PAGE));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * REC_PER_PAGE;

// ── Ana sorgu — JOIN + GROUP BY (N+1 yok) ─────────────────
$order = "ORDER BY
    CASE WHEN COALESCE(r.durum,'')='' THEN 0 WHEN r.durum='islendi' THEN 1 ELSE 2 END ASC,
    COALESCE(r.tarih,'0000-00-00') DESC,
    r.id DESC";

$sql = "SELECT r.*,
               COUNT(p.id)                   AS toplam_palet,
               COALESCE(SUM(p.kasa_adeti),0) AS toplam_kasa,
               COALESCE(SUM(p.brut_kg),0)    AS toplam_brut,
               COALESCE(SUM(p.dara_kg),0)    AS toplam_dara,
               COALESCE(SUM(p.net_kg),0)     AS toplam_net
        FROM loading_records r
        LEFT JOIN loading_pallets p ON p.loading_record_id = r.id
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

<!-- ── Filtre formu ── -->
<form method="get" class="rec-filter-form">
    <?php if ($durum_filter !== ''): ?>
    <input type="hidden" name="durum" value="<?= h($durum_filter) ?>">
    <?php endif; ?>

    <div class="search-row">
        <input type="search" name="q" value="<?= h($q) ?>"
               placeholder="Firma, alıcı, parti no, plaka, ürün..." autocomplete="off">
        <button class="btn">Ara</button>
        <?php if ($q !== '' || $durum_filter !== '' || $tarih_bas !== '' || $tarih_bit !== ''): ?>
        <a href="records.php" class="btn btn-ghost">Temizle</a>
        <?php endif; ?>
    </div>

    <!-- Mobil: toggle chip (desktop'ta gizli) -->
    <?php $has_date = $tarih_bas !== '' || $tarih_bit !== ''; ?>
    <button type="button"
            class="rec-date-toggle<?= $has_date ? ' has-filter' : '' ?>"
            id="recDateToggle">
        <span>📅 <?php if ($has_date): ?><?= $tarih_bas ? h(date('d.m', strtotime($tarih_bas))) : '?' ?> — <?= $tarih_bit ? h(date('d.m', strtotime($tarih_bit))) : '?' ?><?php else: ?>Tarih filtresi<?php endif; ?></span>
        <span class="rec-date-toggle-chev">▾</span>
    </button>

    <!-- Tarih filtresi paneli: mobilde collapsible, desktop'ta her zaman görünür -->
    <div class="date-filter-panel<?= $has_date ? ' rec-open' : '' ?>" id="recDatePanel">
        <label class="date-filter-lbl">Tarih:</label>
        <div class="date-pair">
            <input type="date" name="tarih_bas" value="<?= h($tarih_bas) ?>" max="<?= $today ?>">
            <input type="date" name="tarih_bit" value="<?= h($tarih_bit) ?>" max="<?= $today ?>">
        </div>
        <button class="btn btn-sm">Filtrele</button>
        <?php if ($has_date): ?>
        <a href="records.php<?= $q !== '' ? '?q='.urlencode($q) : '' ?>" class="btn btn-sm btn-ghost">Tarihi Temizle</a>
        <?php endif; ?>
    </div>
</form>

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
        <?php if ($total === 0 && ($q !== '' || $tarih_bas !== '' || $tarih_bit !== '' || $durum_filter !== '')): ?>
            <p>Filtre kriterlerine uyan kayıt bulunamadı.</p>
            <a href="records.php" class="btn btn-ghost">Filtreleri temizle</a>
        <?php else: ?>
            <p>Henüz kayıt yok.</p>
            <a href="record_create.php" class="btn btn-primary">İlk kaydı oluştur</a>
        <?php endif; ?>
    </div>
<?php else: ?>

    <!-- PC: tablo -->
    <div class="table-wrap pc-only">
        <table class="data-table">
            <thead>
            <tr>
                <th>Tarih</th>
                <th>Oluşturma</th>
                <th>Son Düzenleme</th>
                <th>Firma</th>
                <th>Bölge</th>
                <th>Alıcı</th>
                <th>Ürün</th>
                <th>Parti No</th>
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
                <tr class="<?= $durum === 'islendi' ? 'tr-islendi' : ($durum === 'yuklendi' ? 'tr-yuklendi' : '') ?>"
                    data-record-id="<?= (int)$r['id'] ?>"
                    data-durum="<?= h($durum) ?>">
                    <td><?= $r['tarih'] ? h(date('d.m.Y', strtotime($r['tarih']))) : '—' ?></td>
                    <td class="muted"><?= h(fmt_datetime($r['created_at'])) ?></td>
                    <td class="muted"><?= $r['updated_at'] ? h(fmt_datetime($r['updated_at'])) : '—' ?></td>
                    <td>
                        <?= h($r['firma']) ?>
                        <?php if ($locked): ?><span class="badge-locked">🔒</span><?php endif; ?>
                        <?php $ft = fmt_tarih_tr($r['tarih']); if ($ft): ?>
                        <div class="muted" style="font-size:.75rem"><?= h($ft) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= h($r['bolge']) ?></td>
                    <td><?= h($r['alici']) ?></td>
                    <td><?= h($r['urun']) ?></td>
                    <td><?= h($r['parti_no']) ?></td>
                    <td><?= h(trim($r['on_plaka'] . ' / ' . $r['arka_plaka'], ' /')) ?></td>
                    <td class="num"><?= (int)$r['toplam_palet'] ?></td>
                    <td class="num"><?= (int)$r['toplam_kasa'] ?></td>
                    <td class="num"><?= fmt_kg($r['toplam_brut']) ?></td>
                    <td class="num"><?= fmt_kg($r['toplam_dara']) ?></td>
                    <td class="num strong"><?= fmt_kg($r['toplam_net']) ?></td>
                    <td class="actions-col">
                        <a class="btn btn-sm" href="record_view.php?id=<?= (int)$r['id'] ?>">Görüntüle</a>
                        <button type="button" class="btn btn-sm card-share-btn"
                                title="Paylaş"
                                data-share-title="Yükleme Kaydı"
                                data-share-text="Asya Fresh Yükleme Kaydı&#10;Firma: <?= h($r['firma'] ?: '—') ?><?= $r['urun'] ? '&#10;Ürün: ' . h($r['urun']) : '' ?>&#10;Net: <?= h(fmt_kg($r['toplam_net'])) ?> kg"
                                data-share-url="record_view.php?id=<?= (int)$r['id'] ?>">📤</button>
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
                        <?php if ($r['parti_no']): ?><div class="record-card-firma">Parti: <?= h($r['parti_no']) ?></div><?php endif; ?>
                        <div class="muted"><?= h(fmt_datetime($r['created_at'])) ?></div>
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
                    <?php if ($r['alici']): ?><div><span class="lbl">Alıcı:</span> <?= h($r['alici']) ?></div><?php endif; ?>
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
                    <?php
                        $_share_text = 'Asya Fresh Yükleme Kaydı'
                            . "\nFirma: " . ($r['firma'] ?: '—')
                            . ($r['urun'] ? "\nÜrün: " . $r['urun'] : '')
                            . ($r['tarih'] ? "\nTarih: " . fmt_tarih_tr($r['tarih']) : '')
                            . "\nToplam Kasa: " . (int)$r['toplam_kasa']
                            . "\nNet: " . fmt_kg($r['toplam_net']) . ' kg';
                    ?>
                    <button type="button" class="btn btn-sm card-share-btn"
                            data-share-title="Yükleme Kaydı"
                            data-share-text="<?= h($_share_text) ?>"
                            data-share-url="record_view.php?id=<?= (int)$r['id'] ?>">📤 Paylaş</button>
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

<script>
(function () {
    // Tarih filtresi toggle (mobil)
    var dateToggle = document.getElementById('recDateToggle');
    var datePanel  = document.getElementById('recDatePanel');
    if (dateToggle && datePanel) {
        if (datePanel.classList.contains('rec-open')) dateToggle.classList.add('panel-open');
        dateToggle.addEventListener('click', function () {
            var open = datePanel.classList.toggle('rec-open');
            dateToggle.classList.toggle('panel-open', open);
        });
    }
})();

(function () {
    var csrf       = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var canUnlock  = <?= $can_unlock ? 'true' : 'false' ?>;

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-durum-action]');
        if (!btn) return;
        var container = btn.closest('[data-record-id]');
        if (!container) return;

        var action       = btn.dataset.durumAction;
        var currentDurum = container.dataset.durum || '';
        var targetDurum, msg;

        if (action === 'islendi') {
            if (currentDurum === 'islendi') {
                msg = 'İşlendi iptal edilsin mi?';
                targetDurum = '';
            } else {
                msg = 'Ürün işlendi mi?';
                targetDurum = 'islendi';
            }
        } else if (action === 'yuklendi') {
            if (currentDurum === 'yuklendi') {
                msg = 'Yüklendi iptal edilsin mi?';
                targetDurum = 'islendi';
            } else {
                msg = 'Ürün yüklendi mi?';
                targetDurum = 'yuklendi';
            }
        } else { return; }

        if (!confirm(msg)) return;
        btn.disabled = true;

        var extraBody = '';
        if (action === 'yuklendi' && currentDurum === 'yuklendi') {
            var reason = prompt('Revizyon nedeni (zorunlu):');
            if (reason === null) { btn.disabled = false; return; }
            reason = reason.trim();
            if (!reason) { btn.disabled = false; alert('Revizyon nedeni boş bırakılamaz.'); return; }
            extraBody = '&revision_reason=' + encodeURIComponent(reason);
        }

        fetch('record_durum.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + encodeURIComponent(container.dataset.recordId)
                + '&durum=' + encodeURIComponent(targetDurum)
                + '&csrf='  + encodeURIComponent(csrf)
                + extraBody
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btn.disabled = false;
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
        })
        .catch(function () { btn.disabled = false; alert('Bağlantı hatası.'); });
    });
})();
</script>

<?php render_footer(); ?>
