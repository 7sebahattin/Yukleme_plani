<?php
// Hızlı Maliyet: render-only partial. The controller owns auth, CSRF and persistence.
if (!isset($document, $can_write, $storage_ready)) return;
$qc_catalog = $catalog ?? quick_cost_catalog();
$qc_units = ['kg'=>'Kg', 'ton'=>'Ton', 'piece'=>'Adet', 'box'=>'Koli / kasa', 'pallet'=>'Palet', 'vehicle'=>'Araç', 'fixed'=>'Sabit tutar'];
$qc_value = static function ($value): string {
    if (!is_int($value) && !is_float($value)) return (string)$value;
    return rtrim(rtrim(number_format((float)$value, 4, ',', '.'), '0'), ',');
};
$qc_money = static fn($value): string => number_format((float)$value, 2, ',', '.');
$qc_total = $calculation['total'] ?? 0;
$qc_unit = $calculation['unit_cost'] ?? 0;
?>
<div class="qc-workspace">
<?php if (!empty($errors)): ?>
<div class="qc-error" role="alert"><strong>Hesap kaydedilemedi.</strong><ul><?php foreach ($errors as $qc_error): ?><li><?= h((string)$qc_error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<noscript><p class="qc-error">Canlı hesaplama ve kaydetme için JavaScript açık olmalıdır.</p></noscript>
<form id="qc-form" method="post" class="qc-form" data-save-available="<?= $can_write && $storage_ready ? '1' : '0' ?>">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="version" value="<?= (int)($version ?? 0) ?>">
    <input type="hidden" name="payload" id="qc-payload" value="">

    <section class="qc-hero" aria-label="Canlı maliyet özeti">
        <div class="qc-hero-total"><span>TOPLAM MALİYET (TL)</span><strong id="qc-total"><?= h($qc_money($qc_total)) ?></strong></div>
        <div class="qc-hero-secondary"><div><span>NET KG MALİYETİ (TL/kg)</span><strong id="qc-unit-cost"><?= h($qc_money($qc_unit)) ?></strong></div><div><span>Ürün KG</span><strong id="qc-net-summary"><?= h($qc_value($document['net_kg'] ?? 0)) ?></strong></div></div>
        <span class="qc-sr-only" id="qc-live-total" aria-live="polite" aria-atomic="true"></span>
    </section>

    <section class="qc-panel"><h2>Temel bilgiler</h2><div class="qc-basics">
        <label for="qc-product">Ürün adı<input id="qc-product" data-qc-header="product" type="text" maxlength="150" required value="<?= h((string)($document['product'] ?? '')) ?>" <?= !$can_write ? 'disabled' : '' ?> autocomplete="off"></label>
        <label class="qc-net-field" for="qc-net-kg">Ürün KG<input id="qc-net-kg" data-qc-header="net_kg" type="text" inputmode="decimal" required value="<?= h($qc_value($document['net_kg'] ?? 0)) ?>" <?= !$can_write ? 'disabled' : '' ?> autocomplete="off" aria-describedby="qc-number-help"></label>
        <label for="qc-date">İşlem tarihi<input id="qc-date" data-qc-header="sheet_date" type="date" required value="<?= h((string)($document['sheet_date'] ?? '')) ?>" <?= !$can_write ? 'disabled' : '' ?>></label>
        <label class="qc-note-field" for="qc-notes">Açıklama / not <small>(isteğe bağlı)</small><textarea id="qc-notes" data-qc-header="notes" rows="2" maxlength="2000" <?= !$can_write ? 'disabled' : '' ?>><?= h((string)($document['notes'] ?? '')) ?></textarea></label>
    </div><p id="qc-number-help" class="qc-hint">Ondalık için virgül kullanın: 1,60. Örnek kilogram: 20.000.</p></section>

    <section class="qc-costs" aria-labelledby="qc-costs-heading"><div class="qc-section-heading"><h2 id="qc-costs-heading">Maliyet kalemleri</h2><span>TL</span></div><p class="qc-hint">Fiyatları girin. Birim, miktar veya birim fiyatı değiştirmek için kalemin Ayar bölümünü açın.</p>
        <div id="qc-rows" class="qc-rows">
        <?php $qc_render_row = static function (array $qc_item) use ($qc_catalog, $qc_units, $qc_value, $can_write): void {
            $qc_code = (string)$qc_item['code'];
            $qc_custom = (bool)preg_match('/^custom_[a-z0-9_]{1,40}$/D', $qc_code);
            $qc_meta = $qc_catalog[$qc_code] ?? ['label'=>(string)($qc_item['label'] ?? ''), 'group'=>'D'];
            $qc_label = (string)$qc_meta['label'] ?: 'Yeni kalem';
            $qc_fixed = ($qc_item['basis'] ?? '') === 'fixed';
        ?>
        <article class="qc-row" data-qc-row="<?= h($qc_code) ?>">
            <div class="qc-row-head"><h3 data-qc-row-title><?= h($qc_label) ?></h3><output data-qc-amount="<?= h($qc_code) ?>" aria-label="<?= h($qc_label) ?> tutarı">0,00 TL</output><?php if ($qc_custom && $can_write): ?><button type="button" class="qc-remove-button" data-qc-remove="<?= h($qc_code) ?>" aria-label="<?= h($qc_label) ?> kalemini kaldır">Kaldır</button><?php endif; ?></div>
            <?php if ($qc_custom): ?><label class="qc-custom-label" for="qc-label-<?= h($qc_code) ?>">Kalem adı<input id="qc-label-<?= h($qc_code) ?>" data-qc-code="<?= h($qc_code) ?>" data-qc-field="label" type="text" maxlength="150" required value="<?= h((string)($qc_item['label'] ?? '')) ?>" autocomplete="off" <?= !$can_write ? 'disabled' : '' ?>></label><?php endif; ?>
            <label class="qc-main-price" for="qc-price-<?= h($qc_code) ?>"><span data-qc-price-label="<?= h($qc_code) ?>"><?= $qc_fixed ? 'Sabit gider (TL)' : 'Birim fiyat (TL/kg)' ?></span><input id="qc-price-<?= h($qc_code) ?>" data-qc-code="<?= h($qc_code) ?>" data-qc-price="main" type="text" inputmode="decimal" aria-label="<?= h((string)$qc_meta['label']) ?> fiyatı" value="<?= h($qc_value($qc_item['unit_price'] ?? 0)) ?>" autocomplete="off" <?= !$can_write ? 'disabled' : '' ?>></label>
            <p data-qc-basis="<?= h($qc_code) ?>" class="qc-hint"></p>
            <details class="qc-row-settings" data-qc-settings="<?= h($qc_code) ?>">
                <summary aria-label="<?= h((string)$qc_meta['label']) ?> ayarları">Ayar</summary>
                <div class="qc-row-fields">
                    <label for="qc-unit-<?= h($qc_code) ?>">Birim<select id="qc-unit-<?= h($qc_code) ?>" data-qc-code="<?= h($qc_code) ?>" data-qc-field="unit" aria-label="<?= h((string)$qc_meta['label']) ?> birimi" <?= !$can_write ? 'disabled' : '' ?>><?php foreach ($qc_units as $qc_key=>$qc_label): ?><option value="<?= h($qc_key) ?>" <?= ($qc_item['unit'] ?? '') === $qc_key ? 'selected' : '' ?>><?= h($qc_label) ?></option><?php endforeach; ?></select></label>
                    <label for="qc-qty-<?= h($qc_code) ?>">Miktar<input id="qc-qty-<?= h($qc_code) ?>" data-qc-code="<?= h($qc_code) ?>" data-qc-field="qty" type="text" inputmode="decimal" aria-label="<?= h((string)$qc_meta['label']) ?> miktarı" value="<?= h($qc_value($qc_item['qty'] ?? 0)) ?>" autocomplete="off" <?= !$can_write || $qc_fixed ? 'disabled' : '' ?>></label>
                    <label class="qc-price-field" for="qc-settings-price-<?= h($qc_code) ?>">Birim fiyat (TL)<input id="qc-settings-price-<?= h($qc_code) ?>" data-qc-code="<?= h($qc_code) ?>" data-qc-field="unit_price" data-qc-price="settings" type="text" inputmode="decimal" aria-label="<?= h((string)$qc_meta['label']) ?> Ayar birim fiyatı (TL)" value="<?= h($qc_value($qc_item['unit_price'] ?? 0)) ?>" autocomplete="off" <?= !$can_write ? 'disabled' : '' ?>></label>
                </div>
                <button type="button" class="qc-text-button" data-qc-rebind="<?= h($qc_code) ?>" <?= !$can_write ? 'disabled' : '' ?>>Ürün KG kullan</button>
            </details>
        </article>
        <?php }; foreach (($document['items'] ?? []) as $qc_item) $qc_render_row($qc_item); ?>
        </div>
        <?php if ($can_write): ?>
        <template id="qc-custom-row-template"><?php $qc_render_row(['code'=>'custom_template','label'=>'','unit'=>'fixed','basis'=>'fixed','qty'=>1,'unit_price'=>0]); ?></template>
        <button id="qc-add-item" class="btn qc-add-item" type="button">Yeni kalem ekle</button>
        <p id="qc-custom-limit" class="qc-hint" hidden>En fazla kırk yeni kalem eklenebilir.</p>
        <?php endif; ?>
        <p class="qc-hint">Malzeme; kasa, viyol, koli, etiket, palet ve diğer ambalajların ortalama maliyetidir. İşçilik ve diğer giderler kendi satırlarında hesaplanır.</p>
    </section>

    <section class="qc-panel qc-breakdown" aria-labelledby="qc-summary-heading"><h2 id="qc-summary-heading">Maliyet özeti</h2><dl>
        <div><dt>Ürün Maliyetleri</dt><dd id="qc-group-A"><?= h($qc_money($calculation['groups']['A'] ?? 0)) ?> TL</dd></div>
        <div><dt>Malzeme Maliyetleri</dt><dd id="qc-group-B"><?= h($qc_money($calculation['groups']['B'] ?? 0)) ?> TL</dd></div>
        <div><dt>Ortak / Diğer Maliyetler</dt><dd id="qc-group-D"><?= h($qc_money($calculation['groups']['D'] ?? 0)) ?> TL</dd></div>
        <div class="qc-grand-total"><dt>Toplam maliyet</dt><dd id="qc-summary-total"><?= h($qc_money($qc_total)) ?> TL</dd></div>
        <div><dt>Net KG maliyeti</dt><dd id="qc-summary-unit"><?= h($qc_money($qc_unit)) ?> TL/kg</dd></div>
    </dl></section>

    <p id="qc-validation" class="qc-error" role="alert" hidden></p>
    <?php if ($can_write): ?>
    <div class="qc-save-bar"><button id="qc-save" class="btn btn-primary" type="submit" disabled><?= !empty($is_copy) ? 'Kopyayı Yeni Hesap Olarak Kaydet' : 'Hesaplamayı Kaydet' ?></button><small><?= $storage_ready ? 'Kaydedilen sonuçlar yeniden hesaplanarak doğrulanır.' : 'Kaydetme henüz kullanılamıyor. Kurulumun tamamlanması gerekiyor.' ?></small></div>
    <?php else: ?><p class="qc-hint">Bu hesabı görüntülüyorsunuz. Düzenlemek için maliyet yazma yetkisi gerekir.</p><?php endif; ?>
</form>
</div>
