<?php
// =========================================================
// records.php - Yükleme kayıt listesi
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$q = trim((string)($_GET['q'] ?? ''));

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
        WHERE r.type = 'yukleme' ";
$params = [];
if ($q !== '') {
    $sql .= " AND (r.firma LIKE :q OR r.bolge LIKE :q OR r.alici LIKE :q
              OR r.parti_no LIKE :q OR r.on_plaka LIKE :q OR r.arka_plaka LIKE :q
              OR r.urun LIKE :q) ";
    $params[':q'] = '%' . $q . '%';
}
$sql .= " ORDER BY r.id DESC LIMIT 500";

$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

render_header('Kayıtlar');
?>
<?php render_flash(); ?>

<div class="page-head">
    <div>
        <h1>Yükleme Kayıtları</h1>
        <p class="muted">Toplam <?= count($rows) ?> kayıt</p>
    </div>
    <a href="record_create.php" class="btn btn-primary btn-lg">+ Yeni Kayıt</a>
</div>

<form method="get" class="search-row">
    <input type="search" name="q" value="<?= h($q) ?>"
           placeholder="Firma, alıcı, parti no, plaka, ürün..." autocomplete="off">
    <button class="btn">Ara</button>
    <?php if ($q !== ''): ?>
        <a href="records.php" class="btn btn-ghost">Temizle</a>
    <?php endif; ?>
</form>

<?php if (empty($rows)): ?>
    <div class="empty">
        <p>Henüz kayıt yok.</p>
        <a href="record_create.php" class="btn btn-primary">İlk kaydı oluştur</a>
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
                    data-record-id="<?= (int)$r['id'] ?>">
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
                        <button type="button"
                                class="btn btn-sm btn-durum-islendi<?= ($durum === 'islendi' || $durum === 'yuklendi') ? ' durum-done' : '' ?>"
                                data-durum-action="islendi"
                                <?= ($durum === 'islendi' || $durum === 'yuklendi') ? 'disabled' : '' ?>
                                style="<?= $durum === 'yuklendi' ? 'display:none' : '' ?>">
                            <?= ($durum === 'islendi' || $durum === 'yuklendi') ? '✓ İşlendi' : 'İşlendi' ?>
                        </button>
                        <button type="button"
                                class="btn btn-sm btn-durum-yuklendi<?= $durum === 'yuklendi' ? ' durum-done' : '' ?>"
                                data-durum-action="yuklendi"
                                <?= $durum === 'yuklendi' ? 'disabled' : '' ?>
                                style="<?= $durum !== 'islendi' ? 'display:none' : '' ?>">
                            <?= $durum === 'yuklendi' ? '✓ Yüklendi' : 'Yüklendi' ?>
                        </button>
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
                 data-record-id="<?= (int)$r['id'] ?>">
                <div class="record-card-head">
                    <div>
                        <strong>Parti No: <?= h($r['parti_no'] ?: '—') ?></strong>
                        <div class="muted"><?= h($r['firma'] ?: '—') ?> · <?= h(fmt_datetime($r['created_at'])) ?></div>
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
                    <button type="button"
                            class="btn btn-sm btn-durum-islendi<?= ($durum === 'islendi' || $durum === 'yuklendi') ? ' durum-done' : '' ?>"
                            data-durum-action="islendi"
                            <?= ($durum === 'islendi' || $durum === 'yuklendi') ? 'disabled' : '' ?>
                            style="<?= $durum === 'yuklendi' ? 'display:none' : '' ?>">
                        <?= ($durum === 'islendi' || $durum === 'yuklendi') ? '✓ İşlendi' : 'İşlendi' ?>
                    </button>
                    <?php if ($durum === 'islendi'): ?>
                    <button type="button"
                            class="btn btn-sm btn-durum-yuklendi"
                            data-durum-action="yuklendi">
                        Yüklendi
                    </button>
                    <?php elseif ($durum === 'yuklendi'): ?>
                    <button type="button"
                            class="btn btn-sm btn-durum-yuklendi durum-done"
                            data-durum-action="yuklendi" disabled>
                        ✓ Yüklendi
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

        var id     = container.dataset.recordId;
        var action = btn.dataset.durumAction;
        btn.disabled = true;

        fetch('record_durum.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + encodeURIComponent(id) + '&durum=' + encodeURIComponent(action) + '&csrf=' + encodeURIComponent(csrf)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.ok) { alert(data.msg || 'Hata oluştu.'); btn.disabled = false; return; }

            container.classList.remove('durum-islendi', 'durum-yuklendi', 'tr-islendi', 'tr-yuklendi');
            if (data.durum === 'islendi') {
                container.classList.add(container.tagName === 'TR' ? 'tr-islendi' : 'durum-islendi');
            } else if (data.durum === 'yuklendi') {
                container.classList.add(container.tagName === 'TR' ? 'tr-yuklendi' : 'durum-yuklendi');
            }

            var islendiBtn  = container.querySelector('[data-durum-action="islendi"]');
            var yuklendiBtn = container.querySelector('[data-durum-action="yuklendi"]');

            if (data.durum === 'islendi') {
                if (islendiBtn) {
                    islendiBtn.textContent = '✓ İşlendi';
                    islendiBtn.classList.add('durum-done');
                    islendiBtn.disabled = true;
                }
                if (yuklendiBtn) {
                    yuklendiBtn.style.display = '';
                } else {
                    var nb = document.createElement('button');
                    nb.type = 'button';
                    nb.className = 'btn btn-sm btn-durum-yuklendi';
                    nb.dataset.durumAction = 'yuklendi';
                    nb.textContent = 'Yüklendi';
                    (islendiBtn ? islendiBtn.parentNode : container).appendChild(nb);
                }
            } else if (data.durum === 'yuklendi') {
                if (islendiBtn) islendiBtn.style.display = 'none';
                if (yuklendiBtn) {
                    yuklendiBtn.textContent = '✓ Yüklendi';
                    yuklendiBtn.classList.add('durum-done');
                    yuklendiBtn.disabled = true;
                }
            }
        })
        .catch(function () { btn.disabled = false; alert('Bağlantı hatası.'); });
    });
})();
</script>

<?php render_footer(); ?>
