<?php
declare(strict_types=1);
// Hızlı Hesapla: tek ekran ihracat maliyeti / satış fiyatı. Hesap sunucuda yeniden yapılır (config/hizli_hesapla.php).
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/cost_calc.php';
require_once __DIR__ . '/config/quick_cost_store.php';

$auth_user = require_login();
require_hizli_maliyet('read');   // açmak = read; kaydetmek/kopyalamak = write (maliyet.* izni AÇMAZ)
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$id_raw = $_GET['id'] ?? '0';
if (!is_string($id_raw) || !preg_match('/^\d{1,10}$/D', $id_raw)) { http_response_code(400); exit('Geçersiz kayıt numarası.'); }
$id = (int)$id_raw;
$donusum = ($_GET['yeni'] ?? '') === '1' && $id > 0;   // eski (model 3) hesabı AÇIKÇA yeni modelle kopyala
$is_copy = (($_GET['copy'] ?? '') === '1' || $donusum) && $id > 0;
$record_model = HH_MODEL_V4;
$can_write = can_hizli_maliyet('write');
$errors = [];
$record = null;
$version = 0;
$document = hh_defaults();
$storage_ready = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_hizli_maliyet('write');
    csrf_check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null);
}

try {
    $storage_ready = quick_cost_storage_ready(db());
    if ($storage_ready && $id > 0) {
        $record = quick_cost_find(db(), $id);
        if ($record === null) { http_response_code(404); exit('Hesap bulunamadı.'); }
        if (!hh_is_model($record['document'])) {
            // Eski Hızlı Maliyet v1/v2 kaydı kendi ekranında açılır; sessizce dönüştürülmez.
            header('Location: hizli_maliyet_form.php?id=' . $id . ($is_copy ? '&copy=1' : ''));
            exit;
        }
        $document = $record['document'];
        $record_model = hh_model_of($document);
        $version = $is_copy ? 0 : (int)$record['version'];
        if ($is_copy) $document['sheet_date'] = date('Y-m-d');
        if ($donusum) {
            // Kullanıcı seçti: değerler taşınır; fire aynı anlamdadır (ihracata kalan = 100 − fire), hesap aynı ham KG'yi verir.
            if ($record_model !== HH_MODEL) { header('Location: hizli_hesapla.php?id=' . $id . '&copy=1'); exit; }
            $document = hh_donustur_v3_v4($document);
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = $_POST['payload'] ?? null;
        if (!is_string($payload) || strlen($payload) > 16384) throw new InvalidArgumentException('Hesap verisi geçersiz veya çok büyük.');
        try { $input = json_decode($payload, true, 8, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new InvalidArgumentException('Hesap verisi okunamadı.'); }
        if (!is_array($input) || array_is_list($input) || !in_array($input['model'] ?? null, [HH_MODEL, HH_MODEL_V4], true)) throw new InvalidArgumentException('Hesap verisi geçersiz.');
        // Model sabittir: yeni/kopya kayıt YALNIZ model 4; mevcut kayıt kendi modelinde kalır (sessiz dönüşüm yok).
        $beklenen = ($record !== null && !$is_copy) ? $record_model : HH_MODEL_V4;
        if ($input['model'] !== $beklenen) throw new InvalidArgumentException($beklenen === HH_MODEL
            ? 'Bu hesap eski fire modelindedir; aynı modelde kaydedilir.' : 'Yeni hesaplar yeni modelle (Fire + Ham/Net masraf tabanı) kaydedilir.');
        $document = hh_form_input($input);   // hata olursa kullanıcının girdisi korunur
        $posted = $_POST['version'] ?? '';
        if (!is_string($posted) || !preg_match('/^\d{1,10}$/D', $posted)) throw new InvalidArgumentException('Kayıt sürümü geçersiz.');
        $version = (int)$posted;
        if (!$storage_ready) throw new InvalidArgumentException('Kayıt altyapısı henüz kurulmadı. Hesabınız kaydedilmedi.');
        if (trim((string)($input['product'] ?? '')) === '') throw new InvalidArgumentException('Hesap adı gerekli.');
        $saved = quick_cost_save(db(), hh_form_input($input), $is_copy ? 0 : $id, $version);
        set_flash('success', 'Hesap kaydedildi.');
        header('Location: hizli_hesapla.php?id=' . (int)$saved['id']);
        exit;
    }
} catch (InvalidArgumentException $e) {
    http_response_code(422); $errors[] = $e->getMessage();
} catch (RuntimeException $e) {
    if ($e instanceof QuickCostAccessException) { forbidden('Bu kayda erişim yetkiniz yok.'); }
    elseif ($e instanceof QuickCostConflictException) {
        http_response_code(409);
        $errors[] = 'Bu hesap başka bir oturumda değiştirildi. Girdiğiniz değerler korunuyor; güncel kaydı ayrı sekmede açıp karşılaştırın.';
    } else {
        error_log('[hizli_hesapla] ' . get_class($e));
        http_response_code(503);
        $errors[] = 'Kayıt hizmetine şu anda ulaşılamıyor. Girdiğiniz değerler korunuyor.';
    }
} catch (JsonException $e) {
    http_response_code(503); $errors[] = 'Kayıt verisi şu anda okunamıyor.';
}

render_header('Hızlı Hesapla');
render_flash();
$model = hh_model_of($document);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$donusum && $id === 0) $model = HH_MODEL_V4;
$cfg = ['model' => $model, 'donusum' => $donusum, 'document' => $document, 'readOnly' => !$can_write, 'saveAvailable' => $can_write && $storage_ready,
    'id' => ($record && !$is_copy) ? (int)$record['id'] : 0, 'version' => $version, 'copy' => $is_copy,
    'savedName' => ($record && !$is_copy) ? (string)($record['document']['product'] ?? '') : ''];
?>
<link rel="stylesheet" href="assets/hizli-hesapla.css?v=<?= (int)filemtime(__DIR__ . '/assets/hizli-hesapla.css') ?>">
<div class="hh" id="hh">
    <div class="hh-top"><a class="hh-back" href="hizli_maliyet.php">← Hızlı Maliyet</a><h1 class="hh-title">Hızlı Hesapla</h1><span class="hh-top-sp"></span></div>
    <?php if ($errors): ?><div class="hh-alert" role="alert"><?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?></div><?php endif; ?>
    <noscript><p class="hh-alert">Hesaplama için JavaScript açık olmalıdır.</p></noscript>

    <section class="hh-box hh-inputs" aria-label="Satış bilgileri">
        <div class="hh-grid2">
            <label class="hh-f"><span>Satış Net KG</span><input data-hh="sale_kg" type="text" inputmode="decimal" autocomplete="off"></label>
            <label class="hh-f"><span id="hhPriceLbl">Satış Fiyatı (TL/kg)</span><input data-hh="sale_price" type="text" inputmode="decimal" autocomplete="off"></label>
        </div>
        <div class="hh-grid2">
            <label class="hh-f"><span id="hhRateLabel">Kur (TL)</span><input data-hh="rate" type="text" inputmode="decimal" autocomplete="off"></label>
            <div class="hh-f hh-cur">
                <span>Döviz</span>
                <div class="hh-cur-row">
                    <select data-hh="currency" aria-label="Döviz"><option value="USD">USD</option><option value="EUR">EUR</option><option value="RUB">RUB</option><option value="GBP">GBP</option></select>
                    <label class="hh-chk"><input data-hh="price_in_fx" type="checkbox"><span>Fiyat döviz</span></label>
                </div>
            </div>
        </div>
        <p class="hh-hint" id="hhPriceHint"></p>
    </section>

    <section class="hh-box" aria-label="Maliyet kalemleri">
        <h2 class="hh-h">Maliyet Kalemleri</h2>
        <label class="hh-row"><span>Ham mal alış fiyatı (<?= $model === HH_MODEL ? 'TL/kg' : 'TL/ham kg' ?>)</span><input data-hh="raw_price" type="text" inputmode="decimal" autocomplete="off"></label>
        <?php if ($model === HH_MODEL): ?>
        <label class="hh-row"><span>Nakliye bedeli (TL)</span><input data-hh="freight" type="text" inputmode="decimal" autocomplete="off"></label>
        <?php else: ?>
        <div class="hh-row hh-row-basis"><label for="hhFreight"><span>Nakliye bedeli</span></label>
            <select data-hh="freight_cur" class="hh-basis" aria-label="Nakliye para birimi"><option value="TRY">TL</option><option value="USD">USD</option><option value="EUR">EUR</option><option value="RUB">RUB</option><option value="GBP">GBP</option></select>
            <input id="hhFreight" data-hh="freight" type="text" inputmode="decimal" autocomplete="off"></div>
        <label class="hh-row" id="hhFreightRateRow" hidden><span id="hhFreightRateLbl">Nakliye kuru (TL)</span><input data-hh="freight_rate" type="text" inputmode="decimal" autocomplete="off"></label>
        <?php endif; ?>
        <label class="hh-row"><span>Malzeme gideri (<?= $model === HH_MODEL ? 'TL/kg' : 'TL/net kg' ?>)</span><input data-hh="material_kg" type="text" inputmode="decimal" autocomplete="off"></label>
        <label class="hh-row"><span>Evrak masrafları (TL)</span><input data-hh="docs" type="text" inputmode="decimal" autocomplete="off"></label>
        <label class="hh-row"><span>Diğer masraf (TL/kg)<?= ($model === HH_MODEL_V4 && ($document['other_kg_basis'] ?? 'net') === 'raw') ? ' — ham kg (eski kayıt)' : '' ?></span><input data-hh="other_kg" type="text" inputmode="decimal" autocomplete="off"></label>
        <label class="hh-row"><span>Diğer sabit masraf (TL)</span><input data-hh="other_fixed" type="text" inputmode="decimal" autocomplete="off"></label>
        <?php if ($model === HH_MODEL): ?>
        <label class="hh-row"><span>Fire oranı (%)</span><input data-hh="waste_pct" type="text" inputmode="decimal" autocomplete="off"></label>
        <?php else: ?>
        <label class="hh-row"><span>Fire oranı (%)</span><input data-hh="fire_pct" type="text" inputmode="decimal" autocomplete="off"></label>
        <?php if ((float)($document['byproduct_income'] ?? 0) > 0): /* v481: alan kaldırıldı; eski v480 kaydındaki değer korunur */ ?>
        <label class="hh-row"><span>Çıkma / suluk satış geliri (TL) — eski kayıt</span><input data-hh="byproduct_income" type="text" inputmode="decimal" autocomplete="off"></label>
        <?php endif; ?>
        <label class="hh-row"><span>Komisyon / finansman (% satış)</span><input data-hh="commission_pct" type="text" inputmode="decimal" autocomplete="off"></label>
        <?php endif; ?>
        <p class="hh-hint" id="hhRawInfo"></p>
        <?php if ($model === HH_MODEL): ?>
        <p class="hh-hint hh-legacy">Eski model (Ham/Net masraf tabanı yok). <?= $cfg['id'] ? '<a href="hizli_hesapla.php?id=' . (int)$cfg['id'] . '&amp;yeni=1">Yeni modelle kopyala</a> (fire aynı kalır, yeni kayıt açılır).' : '' ?></p>
        <?php else: ?>
        <?php endif; ?>
    </section>

    <div class="hh-errs" id="hhErrs" role="alert" hidden></div>

    <section class="hh-box hh-result" aria-label="Hesaplama sonucu">
        <h2 class="hh-h">Hesaplama Sonucu</h2>
        <dl class="hh-res">
            <div class="hh-r"><dt>Toplam maliyet</dt><dd><strong data-hh-out="total">—</strong><small data-hh-out="total_fx"></small></dd></div>
            <div class="hh-r"><dt>Net kg maliyeti</dt><dd><strong data-hh-out="unit">—</strong><small data-hh-out="unit_fx"></small></dd></div>
            <div class="hh-r"><dt>Satış toplamı</dt><dd><strong data-hh-out="sale">—</strong><small data-hh-out="sale_fx"></small></dd></div>
            <div class="hh-r" id="hhProfitRow"><dt id="hhProfitLbl">Kâr / Zarar</dt><dd><strong data-hh-out="profit">—</strong><small data-hh-out="profit_fx"></small></dd></div>
            <div class="hh-r"><dt>Kâr marjı</dt><dd><strong data-hh-out="margin">—</strong></dd></div>
        </dl>
    </section>

    <section class="hh-box hh-price" aria-label="Kilogram satış fiyatı">
        <h2 class="hh-h">Kilogram Satış Fiyatı</h2>
        <dl class="hh-res">
            <div class="hh-r"><dt>Maliyet / Başabaş fiyatı</dt><dd><strong data-hh-out="breakeven">—</strong></dd></div>
            <div class="hh-r"><dt>Girilen satış fiyatı</dt><dd><strong data-hh-out="entered">—</strong></dd></div>
        </dl>
        <label class="hh-row hh-target"><span>Hedef kâr marjı (%)</span><input data-hh="target_margin" type="text" inputmode="decimal" autocomplete="off"></label>
        <div class="hh-sug"><span>Önerilen satış fiyatı</span><strong data-hh-out="suggested">—</strong></div>
    </section>

    <div class="hh-actions">
        <button type="button" class="hh-btn hh-btn-main" id="hhSave"<?= !$cfg['saveAvailable'] ? ' disabled' : '' ?>>Kaydet</button>
        <button type="button" class="hh-btn" id="hhShare">Paylaş</button>
    </div>
    <?php if (!$can_write): ?><p class="hh-hint">Kaydetme yetkiniz yok; hesap yalnız görüntülenir.</p>
    <?php elseif (!$storage_ready): ?><p class="hh-hint">Kayıt altyapısı henüz kurulmadı; hesaplama çalışır, kaydetme kapalıdır.</p><?php endif; ?>
    <p class="hh-hint hh-status" id="hhStatus" role="status"></p>

    <dialog id="hhDialog" class="hh-dialog" aria-labelledby="hhDialogT">
        <form method="post" id="hhForm" action="hizli_hesapla.php<?= $cfg['id'] ? '?id=' . $cfg['id'] : ($is_copy ? '?id=' . $id . '&copy=1' : '') ?>">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="version" value="<?= (int)$version ?>">
            <input type="hidden" name="payload" id="hhPayload" value="">
            <h2 id="hhDialogT">Hesabı kaydet</h2>
            <label class="hh-f"><span>Hesap adı</span><input id="hhName" type="text" maxlength="150" required autocomplete="off" placeholder="Örn. Nar - Litvanya Teklif"></label>
            <div class="hh-dialog-act"><button type="button" class="hh-btn" id="hhCancel">Vazgeç</button><button type="submit" class="hh-btn hh-btn-main"><?= $cfg['id'] ? 'Güncelle' : 'Kaydet' ?></button></div>
        </form>
    </dialog>
</div>
<script>window.HH_CONFIG = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;</script>
<script src="assets/hizli-hesapla.js?v=<?= (int)filemtime(__DIR__ . '/assets/hizli-hesapla.js') ?>" defer></script>
<?php render_footer(); ?>
