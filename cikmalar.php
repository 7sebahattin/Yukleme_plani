<?php
// =========================================================
// cikmalar.php - Çıkma kayıt listesi
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$q            = trim((string)($_GET['q'] ?? ''));
$durum_filter = trim((string)($_GET['durum'] ?? ''));
if (!in_array($durum_filter, ['islendi', 'yuklendi'], true)) $durum_filter = '';

$sql = "SELECT r.*,
               (SELECT COUNT(*)                          FROM loading_pallets p WHERE p.loading_record_id = r.id) AS toplam_palet,
               (SELECT COALESCE(SUM(p.kasa_adeti),0)    FROM loading_pallets p WHERE p.loading_record_id = r.id) AS toplam_kasa,
               (SELECT COALESCE(SUM(p.brut_kg),0)       FROM loading_pallets p WHERE p.loading_record_id = r.id) AS toplam_brut,
               (SELECT COALESCE(SUM(p.dara_kg),0)       FROM loading_pallets p WHERE p.loading_record_id = r.id) AS toplam_dara,
               (SELECT COALESCE(SUM(p.net_kg),0)        FROM loading_pallets p WHERE p.loading_record_id = r.id) AS toplam_net,
               COALESCE((SELECT m.name FROM loading_pallets p2
                          LEFT JOIN material_definitions m ON m.id = p2.kasa_cinsi_id
                          WHERE p2.loading_record_id = r.id LIMIT 1), '') AS ilk_kasa_cinsi
        FROM loading_records r
        WHERE r.type = 'cikma' ";
$params = [];
if ($q !== '') {
    $sql .= " AND (r.firma LIKE :q OR r.bolge LIKE :q OR r.alici LIKE :q
              OR r.parti_no LIKE :q OR r.on_plaka LIKE :q OR r.arka_plaka LIKE :q
              OR r.urun LIKE :q) ";
    $params[':q'] = '%' . $q . '%';
}
if ($durum_filter !== '') {
    $sql .= " AND r.durum = :durum ";
    $params[':durum'] = $durum_filter;
}
$sql .= " ORDER BY r.id DESC LIMIT 500";

$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

render_header('Çıkmalar');
?>
<?php render_flash(); ?>

<div class="page-head">
    <div>
        <h1>Çıkmalar</h1>
        <p class="muted">Toplam <?= count($rows) ?> kayıt</p>
    </div>
    <a href="cikma_create.php" class="btn btn-primary btn-lg">+ Yeni Çıkma</a>
</div>

<form method="get" class="search-row">
    <?php if ($durum_filter !== ''): ?>
    <input type="hidden" name="durum" value="<?= h($durum_filter) ?>">
    <?php endif; ?>
    <input type="search" name="q" value="<?= h($q) ?>"
           placeholder="Firma, alıcı, parti no, plaka, ürün..." autocomplete="off">
    <button class="btn">Ara</button>
    <?php if ($q !== '' || $durum_filter !== ''): ?>
        <a href="cikmalar.php" class="btn btn-ghost">Temizle</a>
    <?php endif; ?>
</form>

<?php
$q_part = $q !== '' ? '&q=' . urlencode($q) : '';
?>
<div class="filter-pills">
    <a href="cikmalar.php<?= $q_part ? '?' . ltrim($q_part, '&') : '' ?>"
       class="pill<?= $durum_filter === '' ? ' active' : '' ?>">Tümü</a>
    <a href="cikmalar.php?durum=islendi<?= $q_part ?>"
       class="pill<?= $durum_filter === 'islendi' ? ' active-islendi' : '' ?>">🟠 İşlendi</a>
    <a href="cikmalar.php?durum=yuklendi<?= $q_part ?>"
       class="pill<?= $durum_filter === 'yuklendi' ? ' active-yuklendi' : '' ?>">🟢 Yüklendi</a>
</div>

<?php if (empty($rows)): ?>
    <div class="empty">
        <p>Henüz çıkma kaydı yok.</p>
        <a href="cikma_create.php" class="btn btn-primary">İlk kaydı oluştur</a>
    </div>
<?php else: ?>

    <!-- PC: tablo -->
    <div class="table-wrap pc-only">
        <table class="data-table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Tarih/Saat</th>
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
                $durum = $r['durum'] ?? '';
            ?>
                <tr class="<?= $durum === 'islendi' ? 'tr-islendi' : ($durum === 'yuklendi' ? 'tr-yuklendi' : '') ?>"
                    data-record-id="<?= (int)$r['id'] ?>"
                    data-durum="<?= h($durum) ?>">
                    <td><strong>#<?= (int)$r['id'] ?></strong></td>
                    <td><?= h(fmt_datetime($r['created_at'])) ?></td>
                    <td><?= h($r['firma']) ?></td>
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
                        <?php if ($durum !== 'yuklendi'): ?>
                        <button type="button"
                                class="btn btn-sm btn-durum-islendi<?= $durum === 'islendi' ? ' durum-done' : '' ?>"
                                data-durum-action="islendi">
                            <?= $durum === 'islendi' ? '✓ İşlendi' : 'İşlendi' ?>
                        </button>
                        <?php endif; ?>
                        <?php if ($durum === 'islendi' || $durum === 'yuklendi'): ?>
                        <button type="button"
                                class="btn btn-sm btn-durum-yuklendi<?= $durum === 'yuklendi' ? ' durum-done' : '' ?>"
                                data-durum-action="yuklendi">
                            <?= $durum === 'yuklendi' ? '✓ Yüklendi' : 'Yüklendi' ?>
                        </button>
                        <?php endif; ?>
                        <div class="pc-kebab-wrap">
                            <button class="pc-kebab" type="button" title="İşlemler">⋮</button>
                            <div class="pc-dropdown" hidden>
                                <a href="record_edit.php?id=<?= (int)$r['id'] ?>">✎ Düzenle</a>
                                <a href="record_delete.php?id=<?= (int)$r['id'] ?>" class="pc-drop-danger"
                                   onclick="return confirm('Bu kayıt ve tüm palet satırları silinecek. Emin misiniz?');">✕ Sil</a>
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
            $durum = $r['durum'] ?? '';
        ?>
            <div class="record-card<?= $durum ? ' durum-' . h($durum) : '' ?>"
                 data-record-id="<?= (int)$r['id'] ?>"
                 data-durum="<?= h($durum) ?>">
                <div class="record-card-head">
                    <div>
                        <strong>Parti No: <?= h($r['parti_no'] ?: '—') ?></strong>
                        <div class="record-card-firma"><?= h($r['firma'] ?: '—') ?></div>
                        <div class="muted"><?= h(fmt_datetime($r['created_at'])) ?></div>
                    </div>
                    <div class="pc-kebab-wrap">
                        <button class="pc-kebab" type="button" title="İşlemler">⋮</button>
                        <div class="pc-dropdown" hidden>
                            <a href="record_edit.php?id=<?= (int)$r['id'] ?>">✎ Düzenle</a>
                            <a href="record_delete.php?id=<?= (int)$r['id'] ?>" class="pc-drop-danger"
                               onclick="return confirm('Bu kayıt ve tüm palet satırları silinecek. Emin misiniz?');">✕ Sil</a>
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
                <div class="record-card-totals">
                    <div><span>Palet</span><strong><?= (int)$r['toplam_palet'] ?></strong></div>
                    <div><span>Kasa</span><strong><?= (int)$r['toplam_kasa'] ?></strong></div>
                    <div><span>Brüt</span><strong><?= fmt_kg($r['toplam_brut']) ?></strong></div>
                </div>
                <div class="record-card-actions">
                    <a class="btn btn-sm" href="record_view.php?id=<?= (int)$r['id'] ?>">Görüntüle</a>
                    <?php if ($durum !== 'yuklendi'): ?>
                    <button type="button"
                            class="btn btn-sm btn-durum-islendi<?= $durum === 'islendi' ? ' durum-done' : '' ?>"
                            data-durum-action="islendi">
                        <?= $durum === 'islendi' ? '✓ İşlendi' : 'İşlendi' ?>
                    </button>
                    <?php endif; ?>
                    <?php if ($durum === 'islendi' || $durum === 'yuklendi'): ?>
                    <button type="button"
                            class="btn btn-sm btn-durum-yuklendi<?= $durum === 'yuklendi' ? ' durum-done' : '' ?>"
                            data-durum-action="yuklendi">
                        <?= $durum === 'yuklendi' ? '✓ Yüklendi' : 'Yüklendi' ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<script>
(function () {
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

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

        fetch('record_durum.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + encodeURIComponent(container.dataset.recordId)
                + '&durum=' + encodeURIComponent(targetDurum)
                + '&csrf='  + encodeURIComponent(csrf)
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
                if (islendiBtn) { islendiBtn.textContent = 'İşlendi'; islendiBtn.classList.remove('durum-done'); islendiBtn.style.display = ''; }
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
                    ny.dataset.durumAction = 'yuklendi'; ny.textContent = 'Yüklendi';
                    actionsEl.appendChild(ny);
                } else if (yuklendiBtn) {
                    yuklendiBtn.textContent = 'Yüklendi'; yuklendiBtn.classList.remove('durum-done'); yuklendiBtn.style.display = '';
                }
            } else if (data.durum === 'yuklendi') {
                if (islendiBtn) islendiBtn.remove();
                if (yuklendiBtn) { yuklendiBtn.textContent = '✓ Yüklendi'; yuklendiBtn.classList.add('durum-done'); yuklendiBtn.style.display = ''; }
            }
        })
        .catch(function () { btn.disabled = false; alert('Bağlantı hatası.'); });
    });
})();
</script>

<?php render_footer(); ?>
