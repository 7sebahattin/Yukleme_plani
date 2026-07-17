<?php
// =========================================================
// depo_tasima.php — Deposu boş eski kayıtları hedef depoya taşı
// SADECE ADMIN. Toplu UPDATE onay kutusu işaretlenmeden çalışmaz
// (CLAUDE.md kuralı: migration yalnızca açık GO ile — GO = onaylı buton).
// =========================================================

declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

$auth_user = require_login();
if (!is_admin()) forbidden('Depo taşıma yalnızca yöneticiler içindir.');

$pdo = db();

// Taşınabilir tablolar: [tablo, depo kolonu, etiket]
$tables = [
    ['loading_pallets',          'depo',       'Yükleme / Çıkma paletleri'],
    ['kantar_fisleri',           'depo',       'Kantar fişleri'],
    ['material_stock_movements', 'depo',       'Malzeme stok hareketleri'],
    ['stock_counts',             'depo',       'Stok sayımları'],
    ['customs_declarations',     'exit_depot', 'Beyanlar (çıkış deposu)'],
];

// Boş depolu satır sayıları
$counts = [];
foreach ($tables as [$t, $col, $label]) {
    try {
        $counts[$t] = (int)$pdo->query(
            "SELECT COUNT(*) FROM `$t` WHERE TRIM(COALESCE(`$col`,'')) = ''"
        )->fetchColumn();
    } catch (PDOException $e) {
        $counts[$t] = null; // tablo yok
    }
}

// Hedef depo listesi — tanımlardaki aktif depolar (admin kısıtsız görür)
$depolar = depot_options();

$done = null; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $hedef = trim($_POST['hedef_depo'] ?? '');
    $onay  = ($_POST['onay'] ?? '') === '1';
    $secili = array_values(array_intersect(
        array_column($tables, 0),
        (array)($_POST['tablolar'] ?? [])
    ));

    if (!in_array($hedef, $depolar, true)) {
        $error = 'Geçerli bir hedef depo seçin.';
    } elseif (!$onay) {
        $error = 'Taşıma için onay kutusunu işaretlemelisiniz (GO).';
    } elseif (empty($secili)) {
        $error = 'En az bir tablo seçin.';
    } else {
        $done = [];
        foreach ($tables as [$t, $col, $label]) {
            if (!in_array($t, $secili, true)) continue;
            if (($counts[$t] ?? null) === null) continue;
            try {
                $st = $pdo->prepare(
                    "UPDATE `$t` SET `$col` = ? WHERE TRIM(COALESCE(`$col`,'')) = ''"
                );
                $st->execute([$hedef]);
                $n = $st->rowCount();
                $done[] = ['tablo' => $t, 'label' => $label, 'adet' => $n];
                audit_log_event('depot_migrate', 'admin', null, null, [
                    'tablo' => $t, 'kolon' => $col, 'hedef_depo' => $hedef, 'satir' => $n,
                ]);
            } catch (PDOException $e) {
                $done[] = ['tablo' => $t, 'label' => $label, 'adet' => -1, 'hata' => $e->getMessage()];
            }
        }
        // Sayaçları tazele
        foreach ($tables as [$t, $col, $label]) {
            try {
                $counts[$t] = (int)$pdo->query(
                    "SELECT COUNT(*) FROM `$t` WHERE TRIM(COALESCE(`$col`,'')) = ''"
                )->fetchColumn();
            } catch (PDOException $e) { $counts[$t] = null; }
        }
    }
}

$csrf = csrf_token();
render_header('Depo Taşıma');
?>
<div class="page-head">
    <h1>📦 Depo Taşıma</h1>
</div>

<div class="card" style="max-width:760px">
    <p class="muted" style="margin-top:0">
        Deposu <b>boş</b> olan eski kayıtları seçtiğiniz depoya taşır.
        Zorunlu depo seçimi devrede olduğu için deposu boş kayıtlar hiçbir depo
        görünümünde listelenmez — bu araçla bir kez taşıyın.
        <b>Bu işlem geri alınamaz</b>; işlem öncesi veritabanı yedeği önerilir.
    </p>

    <?php if ($error !== ''): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($done !== null && $error === ''): ?>
    <div class="alert alert-success">
        <b>Taşıma tamamlandı:</b>
        <ul style="margin:6px 0 0 18px">
            <?php foreach ($done as $d): ?>
            <li><?= h($d['label']) ?> —
                <?php if ($d['adet'] >= 0): ?><?= (int)$d['adet'] ?> satır taşındı
                <?php else: ?>HATA: <?= h($d['hata'] ?? '') ?><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="post" action="depo_tasima.php">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

        <h3 style="margin-bottom:8px">1. Taşınacak tablolar</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th></th><th>Veri</th><th>Deposu boş satır</th></tr></thead>
                <tbody>
                <?php foreach ($tables as [$t, $col, $label]): $c = $counts[$t]; ?>
                    <tr>
                        <td>
                            <?php if ($c !== null && $c > 0): ?>
                            <input type="checkbox" name="tablolar[]" value="<?= h($t) ?>" checked>
                            <?php endif; ?>
                        </td>
                        <td><?= h($label) ?> <small class="muted">(<?= h($t) ?>.<?= h($col) ?>)</small></td>
                        <td>
                            <?php if ($c === null): ?><span class="muted">tablo yok</span>
                            <?php elseif ($c === 0): ?><span style="color:#16a34a">✔ temiz</span>
                            <?php else: ?><b style="color:#dc2626"><?= $c ?></b>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <h3 style="margin-bottom:8px">2. Hedef depo</h3>
        <?php if (empty($depolar)): ?>
            <p class="muted">Önce <a href="definitions.php?type=depo">Tanımlar → Depolar</a>'dan depo ekleyin.</p>
        <?php else: ?>
            <select name="hedef_depo" required style="min-width:220px">
                <option value="">— seçin —</option>
                <?php foreach ($depolar as $d): ?>
                <option value="<?= h($d) ?>"><?= h($d) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <h3 style="margin:16px 0 8px">3. Onay (GO)</h3>
        <label style="display:flex;gap:8px;align-items:flex-start">
            <input type="checkbox" name="onay" value="1">
            <span>Deposu boş satırların yukarıda seçtiğim depoya <b>kalıcı olarak</b> taşınacağını
            anladım, veritabanı yedeğim var. <b>GO.</b></span>
        </label>

        <div style="margin-top:16px">
            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('Deposu boş satırlar seçilen depoya taşınacak. Emin misiniz?')">
                📦 Taşımayı Başlat
            </button>
            <a href="index.php" class="btn btn-ghost">Vazgeç</a>
        </div>
    </form>
</div>
<?php render_footer(); ?>
