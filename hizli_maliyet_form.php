<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/cost_calc.php';
require_once __DIR__ . '/config/quick_cost_store.php';

$auth_user = require_login();
require_hizli_maliyet('read');   // aç = read; kaydet/kopyala = write (yönetici her zaman geçer; maliyet.* izni AÇMAZ)
// Bu controller eski maliyet şemasını veya kayıtlarını değiştirmez.
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
    $seen = array_fill_keys(array_column($safe['items'], 'code'), true);
    foreach ($rows as $row) {
        $code = is_array($row) ? ($row['code'] ?? null) : null;
        if (!is_string($code) || !preg_match('/^custom_[a-z0-9_]{1,40}$/D', $code) || isset($seen[$code]) || count($safe['items']) >= 50) continue;
        $extra = ['code' => $code, 'label' => '', 'unit' => 'kg', 'qty' => 0, 'unit_price' => 0, 'basis' => 'manual', 'group' => 'D'];
        foreach (['label', 'unit', 'qty', 'unit_price', 'basis'] as $key) {
            if (isset($row[$key]) && (is_string($row[$key]) || is_int($row[$key]) || is_float($row[$key]))) $extra[$key] = $row[$key];
        }
        $safe['items'][] = $extra;
        $seen[$code] = true;
    }
    return $safe;
}

$id_raw = $_GET['id'] ?? '0';
if (!is_string($id_raw) || !preg_match('/^\d{1,10}$/D', $id_raw)) {
    http_response_code(400);
    exit('Geçersiz kayıt numarası.');
}
$id = (int)$id_raw;
$is_copy = ($_GET['copy'] ?? '') === '1';
$can_write = can_hizli_maliyet('write');
$errors = [];
$record = null;
$version = 0;
$document = quick_cost_defaults();
$calculation = quick_cost_calculate($document);
$storage_ready = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_hizli_maliyet('write');
    csrf_check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null);
}

try {
    $storage_ready = quick_cost_storage_ready(db());
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
        if (($_POST['action'] ?? '') !== '') throw new InvalidArgumentException('Geçersiz form işlemi.');
        $payload = $_POST['payload'] ?? null;
        if (!is_string($payload) || strlen($payload) > 65536) {
            throw new InvalidArgumentException('Hesap verisi geçersiz veya çok büyük.');
        }
        try { $input = json_decode($payload, true, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new InvalidArgumentException('Hesap verisi okunamadı.'); }
        if (!is_array($input) || array_is_list($input)) throw new InvalidArgumentException('Hesap verisi geçersiz.');
        if ($record !== null) {
            // Görünmeyen eski metadata düzenlemede/kopyalamada değiştirilmez.
            $input['operation_type'] = $record['document']['operation_type'];
            $input['method'] = $record['document']['method'];
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
        header('Location: hizli_maliyet.php');
        exit;
    }
} catch (InvalidArgumentException $e) {
    http_response_code(422); $errors[] = $e->getMessage();
} catch (RuntimeException $e) {
    if ($e instanceof QuickCostAccessException) {
        forbidden('Bu kayda erişim yetkiniz yok.');
    } elseif ($e instanceof QuickCostConflictException) {
        http_response_code(409);
        $errors[] = 'Bu hesap başka bir oturumda değiştirildi. Girdiğiniz değerler aşağıda korunuyor. Güncel kaydı ayrı bir sekmede açıp karşılaştırın.';
    } else {
        error_log('[hizli_maliyet_form] ' . get_class($e));
        http_response_code(503);
        $errors[] = 'Kayıt hizmetine şu anda ulaşılamıyor. Girdiğiniz değerler aşağıda korunuyor.';
    }
} catch (JsonException $e) {
    http_response_code(503); $errors[] = 'Kayıt verisi şu anda okunamıyor. Lütfen daha sonra tekrar deneyin.';
}

$catalog = quick_cost_catalog();
render_header('Hızlı Maliyet');
render_flash();
?>
<link rel="stylesheet" href="assets/hizli-maliyet.css?v=<?= (int)filemtime(__DIR__ . '/assets/hizli-maliyet.css') ?>">
<div class="qc-page">
    <div class="page-head">
        <div><h1><?= $record && !$is_copy ? 'Maliyeti düzenle' : 'Yeni maliyet hesapla' ?></h1><p class="muted">Ürün KG üzerinden maliyet hesabı</p></div>
        <div class="page-head-actions"><a class="btn" href="hizli_maliyet.php">← Kayıtlar</a></div>
    </div>
    <?php if ($record): ?>
    <p class="muted"><?= $is_copy ? 'Kopyalanan hesap' : 'Hesap' ?> #<?= (int)$record['id'] ?> · Oluşturan kullanıcı #<?= (int)$record['created_by'] ?> · <?= h($record['created_at']) ?><?= !empty($record['updated_at']) ? ' · Güncelleme: ' . h($record['updated_at']) : '' ?></p>
    <?php if (!$is_copy && $can_write): ?><p><a class="btn" href="hizli_maliyet_form.php?id=<?= $id ?>&amp;copy=1">Kopyalayarak yeni hesap</a></p><?php endif; ?>
    <?php endif; ?>
    <?php if (!$storage_ready): ?><p class="qc-error">Kayıt altyapısı henüz hazır değil. <?= is_admin() ? 'Kurulum için <a href="hizli_maliyet.php">kayıt listesine dönün</a>.' : 'Kurulum sistem yöneticisinden beklenir.' ?></p><?php endif; ?>
    <?php require __DIR__ . '/_hizli_maliyet_form.php'; ?>
</div>
<script>window.QUICK_COST_CONFIG = <?= json_encode(['document' => $document, 'catalog' => $catalog, 'readOnly' => !$can_write], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;</script>
<script src="assets/hizli-maliyet.js?v=<?= (int)filemtime(__DIR__ . '/assets/hizli-maliyet.js') ?>" defer></script>
<?php render_footer(); ?>
