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
// No cost_migrate(): this screen never changes the existing cost schema/data.
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

/** Recover ordinary invalid form values without rendering arrays as text. */
function quick_cost_form_input(array $input): array
{
    $safe = quick_cost_defaults();
    foreach (['product', 'sheet_date', 'operation_type', 'method', 'net_kg', 'notes'] as $key) {
        if (isset($input[$key]) && (is_string($input[$key]) || is_int($input[$key]) || is_float($input[$key]))) {
            $safe[$key] = $input[$key];
        }
    }
    $rows = is_array($input['items'] ?? null) ? $input['items'] : [];
    foreach ($safe['items'] as &$item) {
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['code'] ?? null) !== $item['code']) continue;
            foreach (['unit', 'qty', 'unit_price', 'basis'] as $key) {
                if (isset($row[$key]) && (is_string($row[$key]) || is_int($row[$key]) || is_float($row[$key]))) {
                    $item[$key] = $row[$key];
                }
            }
            break;
        }
    }
    unset($item);
    return $safe;
}

$id_raw = $_GET['id'] ?? '0';
if (!is_string($id_raw) || !preg_match('/^\d{1,10}$/D', $id_raw)) {
    http_response_code(400);
    exit('Geçersiz kayıt numarası.');
}
$id = (int)$id_raw;
$is_copy = ($_GET['copy'] ?? '') === '1';
$can_write = can_maliyet('write');
$errors = [];
$record = null;
$version = 0;
$document = quick_cost_defaults();
$calculation = quick_cost_calculate($document);
$storage_ready = false;
$list = ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
$query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 150) : '';
$page = max(1, min(100000, (int)(is_scalar($_GET['page'] ?? 1) ? ($_GET['page'] ?? 1) : 1)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_maliyet('write');
    csrf_check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null);
    if (($_POST['action'] ?? '') === 'install_schema' && !is_admin()) {
        forbidden('Kayıt altyapısını yalnız sistem yöneticisi kurabilir.');
    }
}

try {
    $storage_ready = quick_cost_storage_ready(db());
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install_schema') {
        $installed = quick_cost_install(db());
        set_flash('success', $installed['created'] ? 'Hızlı Maliyet kayıt altyapısı kuruldu.' : 'Hızlı Maliyet kayıt altyapısı zaten hazır.');
        header('Location: hizli_maliyet.php');
        exit;
    }
    if ($storage_ready && $id > 0) {
        $record = quick_cost_find(db(), $id);
        if ($record === null) {
            http_response_code(404);
            exit('Hızlı maliyet kaydı bulunamadı.');
        }
        $document = $record['document'];
        $version = $is_copy ? 0 : (int)$record['version'];
        if ($is_copy) $document['sheet_date'] = date('Y-m-d');
        $calculation = quick_cost_calculate($document);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = $_POST['payload'] ?? null;
        if (!is_string($payload) || strlen($payload) > 65536) {
            throw new InvalidArgumentException('Hesap verisi geçersiz veya çok büyük.');
        }
        try {
            $input = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Hesap verisi okunamadı.');
        }
        if (!is_array($input) || array_is_list($input)) {
            throw new InvalidArgumentException('Hesap verisi geçersiz.');
        }
        $document = quick_cost_form_input($input);
        $calculation = quick_cost_calculate($input);
        $document = $calculation;
        $posted_version = $_POST['version'] ?? '';
        if (!is_string($posted_version) || !preg_match('/^\d{1,10}$/D', $posted_version)) {
            throw new InvalidArgumentException('Kayıt sürümü geçersiz.');
        }
        $version = (int)$posted_version;
        if (!$storage_ready) throw new InvalidArgumentException('Kayıt altyapısı henüz kurulmadı. Hesabınız kaydedilmedi.');
        $saved = quick_cost_save(db(), $input, $is_copy ? 0 : $id, $version);
        set_flash('success', $is_copy ? 'Hesap yeni bir kayıt olarak kopyalandı.' : 'Hızlı maliyet hesabı kaydedildi.');
        header('Location: hizli_maliyet.php?id=' . (int)$saved['id']);
        exit;
    }
    if ($storage_ready) $list = quick_cost_list(db(), $page, 20, $query);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    $errors[] = $e->getMessage();
} catch (RuntimeException $e) {
    // Store access/conflict errors are handled below using their typed classes.
    if ($e instanceof QuickCostAccessException) {
        forbidden('Bu kayda erişim yetkiniz yok.');
    } elseif ($e instanceof QuickCostConflictException) {
        http_response_code(409);
        $errors[] = 'Bu hesap başka bir oturumda değiştirildi. Girdiğiniz değerler aşağıda korunuyor. Güncel kaydı ayrı bir sekmede açıp karşılaştırın.';
    } else {
        error_log('[hizli_maliyet] ' . get_class($e));
        http_response_code(503);
        $errors[] = ($_POST['action'] ?? '') === 'install_schema'
            ? 'Tablo kurulumunun tamamlandığı doğrulanamadı. Sayfayı yeniden açıp kontrol edin.'
            : 'Kayıt hizmetine şu anda ulaşılamıyor. Girdiğiniz değerler aşağıda korunuyor.';
    }
} catch (JsonException $e) {
    http_response_code(503);
    $errors[] = 'Kayıt verisi şu anda okunamıyor. Lütfen daha sonra tekrar deneyin.';
}

$catalog = quick_cost_catalog();
render_header('Hızlı Maliyet');
render_flash();
?>
<link rel="stylesheet" href="assets/hizli-maliyet.css?v=<?= (int)filemtime(__DIR__ . '/assets/hizli-maliyet.css') ?>">
<div class="qc-page">
    <div class="page-head">
        <div><h1>Hızlı Maliyet</h1><p class="muted">Satılacak net kilogram üzerinden maliyet hesabı</p></div>
        <div class="page-head-actions"><a class="btn" href="maliyet.php">Maliyet menüsü</a><a class="btn" href="hizli_maliyet.php">Yeni hesap</a><a class="btn" href="#qc-records">Kayıtlar</a></div>
    </div>
    <?php if ($record): ?>
    <p class="muted"><?= $is_copy ? 'Kopyalanan hesap' : 'Hesap' ?> #<?= (int)$record['id'] ?> · Oluşturan kullanıcı #<?= (int)$record['created_by'] ?> · <?= h($record['created_at']) ?><?= !empty($record['updated_at']) ? ' · Güncelleme: ' . h($record['updated_at']) : '' ?></p>
    <?php if (!$is_copy && $can_write): ?><p><a class="btn" href="hizli_maliyet.php?id=<?= $id ?>&amp;copy=1">Kopyalayarak yeni hesap</a></p><?php endif; ?>
    <?php endif; ?>
    <?php if (!$storage_ready && $can_write && is_admin()): ?>
    <section class="qc-panel" style="margin-bottom:18px" aria-labelledby="qc-install-heading">
        <h2 id="qc-install-heading">Hızlı Maliyet kayıt altyapısı</h2>
        <p>Kaydetmeyi açmak için yalnız yeni Hızlı Maliyet tablosu oluşturulur. Mevcut maliyet kayıtları korunur.</p>
        <form method="post" action="hizli_maliyet.php">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="install_schema">
            <button class="btn btn-primary" type="submit">Yeni maliyet tablosunu kur</button>
        </form>
    </section>
    <?php endif; ?>
    <?php require __DIR__ . '/_hizli_maliyet_form.php'; ?>
    <section id="qc-records" class="qc-panel">
        <h2>Hızlı maliyet kayıtları</h2>
        <p class="muted">Aktif depo: <?= h(active_depot() ?? '') ?> · <?= (int)$list['total'] ?> kayıt</p>
        <form method="get" class="qc-record-search"><label for="qc-search">Ürün ara</label><input id="qc-search" name="q" type="search" maxlength="150" value="<?= h($query) ?>"><button class="btn" type="submit">Ara</button></form>
        <?php if ($storage_ready && !$list['items']): ?><p>Henüz kayıt yok.</p><?php endif; ?>
        <div class="qc-record-list">
        <?php foreach ($list['items'] as $row): ?>
            <a class="qc-record" href="hizli_maliyet.php?id=<?= (int)$row['id'] ?>">
                <strong><?= h($row['product']) ?></strong>
                <span><?= h($row['sheet_date']) ?> · <?= ($row['operation_type'] === 'uretim') ? 'Üretim' : 'Fason' ?> · <?= ($row['method'] === 'detailed') ? 'Detaylı' : 'Hızlı' ?></span>
                <span><?= number_format((float)$row['net_kg'], 4, ',', '.') ?> kg · <?= number_format((float)$row['total'], 2, ',', '.') ?> TL · <?= number_format((float)$row['unit_cost'], 2, ',', '.') ?> TL/kg</span>
            </a>
        <?php endforeach; ?>
        </div>
        <?php if ($list['pages'] > 1): ?><nav aria-label="Kayıt sayfaları" class="qc-record-search">
            <?php if ($list['page'] > 1): ?><a class="btn" href="?<?= h(http_build_query(['q' => $query, 'page' => $list['page'] - 1])) ?>#qc-records">Önceki</a><?php endif; ?>
            <span><?= (int)$list['page'] ?> / <?= (int)$list['pages'] ?></span>
            <?php if ($list['page'] < $list['pages']): ?><a class="btn" href="?<?= h(http_build_query(['q' => $query, 'page' => $list['page'] + 1])) ?>#qc-records">Sonraki</a><?php endif; ?>
        </nav><?php endif; ?>
    </section>
</div>
<script>window.QUICK_COST_CONFIG = <?= json_encode(['document' => $document, 'catalog' => $catalog, 'readOnly' => !$can_write], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;</script>
<script src="assets/hizli-maliyet.js?v=<?= (int)filemtime(__DIR__ . '/assets/hizli-maliyet.js') ?>" defer></script>
<?php render_footer(); ?>
