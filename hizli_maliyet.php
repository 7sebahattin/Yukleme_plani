<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/cost_calc.php';
require_once __DIR__ . '/config/quick_cost_store.php';
require_once __DIR__ . '/config/quick_cost_schema.php';

$auth_user = require_login();
if (!is_admin()) forbidden('Hızlı Maliyet şu anda yalnız sistem yöneticisine açık.');
require_maliyet('read');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

// Eski kayıt bağlantıları yeni forma gider; kullanıcı girdisi URL hedefi olamaz.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && array_key_exists('id', $_GET)) {
    $legacy_id = $_GET['id'];
    if (!is_string($legacy_id) || !preg_match('/^\d{1,10}$/D', $legacy_id)) {
        http_response_code(400); exit('Geçersiz kayıt numarası.');
    }
    $target = 'hizli_maliyet_form.php';
    if ((int)$legacy_id > 0) {
        $target .= '?id=' . (int)$legacy_id;
        if (($_GET['copy'] ?? '') === '1') $target .= '&copy=1';
    }
    header('Location: ' . $target);
    exit;
}
$action = $_POST['action'] ?? '';
// Eski açık form aynı adrese POST edebilir. Gövde/id/copy/version korunur;
// redirect ile payload kaybedilmez. Bozuk payload da formda 422 olarak işlenir.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === '' && array_key_exists('payload', $_POST)) {
    require __DIR__ . '/hizli_maliyet_form.php';
    exit;
}

$can_write = can_maliyet('write');
$can_delete = $can_write && can_maliyet('delete');
$storage_ready = false;
$errors = [];
$list = ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
$query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 150) : '';
$page = max(1, min(100000, (int)(is_scalar($_GET['page'] ?? 1) ? ($_GET['page'] ?? 1) : 1)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_maliyet('write');
    if ($action === 'delete') require_maliyet('delete');
    csrf_check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null);
}

try {
    $storage_ready = quick_cost_storage_ready(db());
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($action === 'install_schema') {
            $installed = quick_cost_install(db());
            set_flash('success', $installed['created'] ? 'Hızlı Maliyet kayıt altyapısı kuruldu.' : 'Hızlı Maliyet kayıt altyapısı zaten hazır.');
        } elseif ($action === 'delete') {
            $delete_id = $_POST['id'] ?? null;
            $delete_version = $_POST['version'] ?? null;
            if (!is_string($delete_id) || !preg_match('/^[1-9]\d{0,9}$/D', $delete_id)
                || !is_string($delete_version) || !preg_match('/^[1-9]\d{0,9}$/D', $delete_version)) {
                throw new InvalidArgumentException('Kayıt numarası ve sürümü geçersiz.');
            }
            if (!$storage_ready) throw new InvalidArgumentException('Kayıt altyapısı henüz kurulmadı.');
            quick_cost_delete(db(), (int)$delete_id, (int)$delete_version);
            set_flash('success', 'Hızlı maliyet hesabı silindi.');
        } else {
            throw new InvalidArgumentException('Geçersiz liste işlemi.');
        }
        header('Location: hizli_maliyet.php');
        exit;
    }
} catch (InvalidArgumentException $e) {
    http_response_code(422); $errors[] = $e->getMessage();
} catch (RuntimeException $e) {
    if ($e instanceof QuickCostAccessException) {
        forbidden('Bu kayda erişim yetkiniz yok.');
    } elseif ($e instanceof QuickCostConflictException) {
        http_response_code(409); $errors[] = 'Bu kayıt başka bir oturumda değiştirildi. Güncel liste aşağıda; kayıt silinmedi.';
    } else {
        error_log('[hizli_maliyet_list] ' . get_class($e));
        http_response_code(503);
        $errors[] = $action === 'install_schema' ? 'Tablo kurulumunun tamamlandığı doğrulanamadı. Sayfayı yeniden açıp kontrol edin.' : 'Kayıt hizmetine şu anda ulaşılamıyor.';
    }
}
if ($storage_ready) {
    try { $list = quick_cost_list(db(), $page, 20, $query); }
    catch (InvalidArgumentException $e) { http_response_code(422); $errors[] = $e->getMessage(); }
    catch (RuntimeException $e) {
        error_log('[hizli_maliyet_list_read] ' . get_class($e));
        http_response_code(503); $errors[] = 'Kayıt listesine şu anda ulaşılamıyor.';
    }
}

render_header('Hızlı Maliyet');
render_flash();
?>
<link rel="stylesheet" href="assets/hizli-maliyet.css?v=<?= (int)filemtime(__DIR__ . '/assets/hizli-maliyet.css') ?>">
<div class="qc-page">
    <div class="page-head">
        <div><h1>Hızlı Maliyet</h1><p class="muted">Kaydedilen maliyet hesapları</p></div>
        <?php if ($can_write): ?><div class="page-head-actions"><a class="btn btn-primary" href="hizli_maliyet_form.php">Yeni maliyet hesapla</a></div><?php endif; ?>
    </div>
    <?php if ($errors): ?><div class="qc-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if (!$storage_ready && $can_write): ?>
    <section class="qc-panel" aria-labelledby="qc-install-heading">
        <h2 id="qc-install-heading">Hızlı Maliyet kayıt altyapısı</h2>
        <p>Kaydetmeyi açmak için yalnız yeni Hızlı Maliyet tablosu oluşturulur. Mevcut maliyet kayıtları korunur.</p>
        <form method="post" action="hizli_maliyet.php">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="install_schema">
            <button class="btn btn-primary" type="submit">Yeni maliyet tablosunu kur</button>
        </form>
    </section>
    <?php endif; ?>
    <section id="qc-records" class="qc-panel">
        <h2>Hızlı maliyet kayıtları</h2>
        <p class="muted">Aktif depo: <?= h(active_depot() ?? '') ?> · <?= (int)$list['total'] ?> kayıt</p>
        <form method="get" action="hizli_maliyet.php" class="qc-record-search"><label for="qc-search">Ürün ara</label><input id="qc-search" name="q" type="search" maxlength="150" value="<?= h($query) ?>"><button class="btn" type="submit">Ara</button></form>
        <?php if ($storage_ready && !$list['items']): ?><p>Henüz kayıt yok.</p><?php endif; ?>
        <div class="qc-record-list">
        <?php foreach ($list['items'] as $row): ?>
            <article class="qc-record">
                <a href="hizli_maliyet_form.php?id=<?= (int)$row['id'] ?>"><strong><?= h($row['product']) ?></strong></a>
                <span><?= h($row['sheet_date']) ?></span>
                <span><?= number_format((float)$row['net_kg'], 4, ',', '.') ?> kg · <?= number_format((float)$row['total'], 2, ',', '.') ?> TL · <?= number_format((float)$row['unit_cost'], 2, ',', '.') ?> TL/kg</span>
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                    <a class="btn" href="hizli_maliyet_form.php?id=<?= (int)$row['id'] ?>"><?= $can_write ? 'Düzenle' : 'Aç' ?></a>
                    <?php if ($can_write): ?><a class="btn" href="hizli_maliyet_form.php?id=<?= (int)$row['id'] ?>&amp;copy=1">Kopyala</a><?php endif; ?>
                    <?php if ($can_delete): ?><form method="post" action="hizli_maliyet.php" data-confirm="<?= h('#' . (int)$row['id'] . ' – ' . $row['product'] . ' kalıcı olarak silinecek. Devam edilsin mi?') ?>" onsubmit="return confirm(this.dataset.confirm);">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="version" value="<?= (int)$row['version'] ?>">
                        <button class="btn" type="submit">Sil</button>
                    </form><?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
        <?php if ($list['pages'] > 1): ?><nav aria-label="Kayıt sayfaları" class="qc-record-search">
            <?php if ($list['page'] > 1): ?><a class="btn" href="hizli_maliyet.php?<?= h(http_build_query(['q' => $query, 'page' => $list['page'] - 1])) ?>#qc-records">Önceki</a><?php endif; ?>
            <span><?= (int)$list['page'] ?> / <?= (int)$list['pages'] ?></span>
            <?php if ($list['page'] < $list['pages']): ?><a class="btn" href="hizli_maliyet.php?<?= h(http_build_query(['q' => $query, 'page' => $list['page'] + 1])) ?>#qc-records">Sonraki</a><?php endif; ?>
        </nav><?php endif; ?>
    </section>
</div>
<?php render_footer(); ?>
