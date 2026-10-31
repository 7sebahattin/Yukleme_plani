<?php
// Hızlı Maliyet v2 (ham mal / verimlilik / ihracat): yalnız çizim. Yetki, CSRF ve kayıt controller'dadır.
if (!isset($document, $can_write, $storage_ready)) return;
$qc2_catalog = quick_cost_v2_catalog();
$qc2_units = quick_cost_v2_units();
$qc2_options = quick_cost_v2_basis_options();
$qc2_groups = quick_cost_v2_groups();
$qc2_calc = (isset($calculation) && quick_cost_is_v2($calculation)) ? $calculation : null;
$qc2_dis = !$can_write ? ' disabled' : '';
$qc2_num = static function ($value): string {
    if ($value === null) return '';
    if (!is_int($value) && !is_float($value)) return (string)$value;
    return rtrim(rtrim(number_format((float)$value, 4, ',', '.'), '0'), ',');
};
$qc2_money = static fn($value): string => number_format((float)$value, 2, ',', '.');
$qc2_q = is_array($document['quantities'] ?? null) ? $document['quantities'] : [];
$qc2_credit = is_array($document['credit'] ?? null) ? $document['credit'] : [];
$qc2_sale = is_array($document['sale'] ?? null) ? $document['sale'] : [];
$qc2_md = is_array($document['material_detail'] ?? null) ? $document['material_detail'] : quick_cost_v2_material_defaults();
$qc2_matlabels = quick_cost_v2_material_lines();
$qc2_view = ($document['method'] ?? '') === 'detailed' ? 'detailed' : 'quick';
$qc2_yield = ($document['yield_mode'] ?? '') === 'target' ? 'target' : 'actual';
$qc2_out = static fn(string $key, string $fallback = '—') => '<strong data-qc2-out="' . h($key) . '">' . h($fallback) . '</strong>';
$qc2_input = static function (string $path, $value, string $label, array $opt = []) use ($qc2_num, $qc2_dis): string {
    $id = 'qc2-' . str_replace(['.', '_'], '-', $path);
    $attrs = ' data-qc2="' . h($path) . '" type="text" inputmode="decimal" autocomplete="off"'
        . (!empty($opt['optional']) ? ' data-qc2-optional="1"' : '')
        . (isset($opt['placeholder']) ? ' placeholder="' . h($opt['placeholder']) . '"' : '');
    return '<label for="' . h($id) . '"' . (isset($opt['class']) ? ' class="' . h($opt['class']) . '"' : '') . '>'
        . '<span>' . h($label) . '</span><input id="' . h($id) . '"' . $attrs . ' value="' . h($qc2_num($value)) . '"' . $qc2_dis . '></label>';
};
?>
<div class="qc-workspace qc2-workspace">
<?php if (!empty($errors)): ?>
<div class="qc-error" role="alert"><strong>Hesap kaydedilemedi.</strong><ul><?php foreach ($errors as $qc2_error): ?><li><?= h((string)$qc2_error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<noscript><p class="qc-error">Canlı hesaplama ve kaydetme için JavaScript açık olmalıdır.</p></noscript>
<form id="qc-form" method="post" class="qc-form qc2" data-model="2" data-view="<?= h($qc2_view) ?>" data-yield-mode="<?= h($qc2_yield) ?>" data-save-available="<?= $can_write && $storage_ready ? '1' : '0' ?>">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="version" value="<?= (int)($version ?? 0) ?>">
    <input type="hidden" name="payload" id="qc-payload" value="">

    <section class="qc-hero qc2-hero" aria-label="Canlı maliyet sonuçları">
        <div class="qc2-kpis">
            <div><span>İHRACATLIK NET KG</span><?= $qc2_out('export_kg', $qc2_calc ? $qc2_num($qc2_calc['quantities']['export_kg']) : '—') ?></div>
            <div><span>İHRACAT VERİMLİLİĞİ</span><?= $qc2_out('yield_pct', $qc2_calc ? '%' . number_format($qc2_calc['derived']['yield_pct'], 2, ',', '.') : '—') ?></div>
            <div class="qc2-kpi-main"><span>İHRACAT NET KG MALİYETİ (TL/kg)</span><?= $qc2_out('unit_cost', $qc2_calc ? number_format($qc2_calc['unit_cost'], 4, ',', '.') : '—') ?></div>
        </div>
        <p class="qc2-method" data-qc2-out="method_note">İhtiyatlı yöntem: tüm operasyon maliyeti ihracatlık net KG'ye yüklenir.</p>
        <details class="qc2-more">
            <summary>Genişletilmiş sonuçlar</summary>
            <dl>
                <div><dt>Ham mal alış toplamı</dt><dd data-qc2-out="group.raw">—</dd></div>
                <div><dt>Hasat ve ön nakliye</dt><dd data-qc2-out="group.supply">—</dd></div>
                <div><dt>Paketleme maliyeti</dt><dd data-qc2-out="group.packing">—</dd></div>
                <div><dt>Malzeme maliyeti</dt><dd data-qc2-out="group.material">—</dd></div>
                <div><dt>Nakliye ve ihracat giderleri</dt><dd data-qc2-out="group.export">—</dd></div>
                <div><dt>Genel ve diğer giderler</dt><dd data-qc2-out="group.other">—</dd></div>
                <div><dt>Çıkma / suluk net gelir kredisi</dt><dd data-qc2-out="credit_applied">—</dd></div>
                <div class="qc-grand-total"><dt>İhracata yüklenen tahmini maliyet</dt><dd data-qc2-out="export_cost">—</dd></div>
                <div><dt>İhtiyatlı maliyet (kredi yok)</dt><dd data-qc2-out="unit_cost_prudent">—</dd></div>
            </dl>
        </details>
        <span class="qc-sr-only" data-qc2-out="live" aria-live="polite" aria-atomic="true"></span>
    </section>

    <div class="qc2-switches">
        <div class="qc2-seg" role="group" aria-label="Görünüm">
            <button type="button" data-qc2-set="method" data-value="quick" aria-pressed="<?= $qc2_view === 'quick' ? 'true' : 'false' ?>">Hızlı Hesap</button>
            <button type="button" data-qc2-set="method" data-value="detailed" aria-pressed="<?= $qc2_view === 'detailed' ? 'true' : 'false' ?>">Detaylı Hesap</button>
        </div>
    </div>

    <section class="qc-panel"><h2>Temel bilgiler</h2><div class="qc-basics">
        <label for="qc2-product"><span>Ürün adı</span><input id="qc2-product" data-qc2="product" data-qc2-text="150" type="text" maxlength="150" required value="<?= h((string)($document['product'] ?? '')) ?>" autocomplete="off"<?= $qc2_dis ?>></label>
        <label for="qc2-date"><span>İşlem tarihi</span><input id="qc2-date" data-qc2="sheet_date" type="date" required value="<?= h((string)($document['sheet_date'] ?? '')) ?>"<?= $qc2_dis ?>></label>
        <label class="qc-note-field" for="qc2-notes" data-qc2-detail><span>Açıklama / not <small>(isteğe bağlı)</small></span><textarea id="qc2-notes" data-qc2="notes" data-qc2-text="2000" rows="2" maxlength="2000"<?= $qc2_dis ?>><?= h((string)($document['notes'] ?? '')) ?></textarea></label>
    </div></section>

    <section class="qc-panel qc2-qty" aria-labelledby="qc2-qty-heading">
        <div class="qc-section-heading"><h2 id="qc2-qty-heading">Miktar ve verimlilik</h2></div>
        <div class="qc2-seg qc2-seg-small" role="group" aria-label="Verimlilik yöntemi">
            <button type="button" data-qc2-set="yield_mode" data-value="actual" aria-pressed="<?= $qc2_yield === 'actual' ? 'true' : 'false' ?>"<?= $qc2_dis ?>>Ham KG + ihracat KG</button>
            <button type="button" data-qc2-set="yield_mode" data-value="target" aria-pressed="<?= $qc2_yield === 'target' ? 'true' : 'false' ?>"<?= $qc2_dis ?>>Hedef ihracat + verimlilik</button>
        </div>
        <div class="qc2-qty-grid">
            <?= $qc2_input('quantities.raw_kg', $qc2_q['raw_kg'] ?? 0, 'Ham mal KG (satın alınan)', ['class' => 'qc-net-field qc2-when-actual']) ?>
            <div class="qc2-readout qc2-when-target"><span>Gerekli ham mal KG</span><?= $qc2_out('required_raw') ?></div>
            <?= $qc2_input('quantities.export_kg', $qc2_q['export_kg'] ?? 0, 'İhracatlık net KG', ['class' => 'qc-net-field']) ?>
            <?= $qc2_input('target_yield', $document['target_yield'] ?? 0, 'Tahmini verimlilik (%)', ['class' => 'qc2-when-target']) ?>
            <div class="qc2-readout qc2-when-actual"><span>İhracat verimliliği</span><?= $qc2_out('yield_readout') ?></div>
        </div>
        <p class="qc-hint qc-unit-note" data-qc2-yield-note role="status" hidden></p>
        <p class="qc-hint qc2-when-actual"><strong>Verimlilik</strong> = 100 kg ham üründen kaç kg ihracatlık ürün çıktığı: ihracat KG ÷ ham mal KG × 100. Örnek: 25.000 kg ham → 20.000 kg ihracat = %80. Alım yapıldıysa iki miktarı da girin, verimlilik kendiliğinden çıkar. Ham mal KG: kasa ve palet darası hariç, ayıklanmamış ürün.</p>
        <p class="qc-hint qc2-when-target"><strong>Teklif / alım planı için:</strong> kaç kg ihracat yapmak istediğinizi ve tahmini verimliliği girin; kaç kg ham mal almanız gerektiği hesaplanır (hedef ihracat KG ÷ verimlilik). Sabit bir verimlilik varsayılmaz, kendi tahmininizi girin.</p>
        <div class="qc2-detail-block" data-qc2-detail>
            <h3>Ürün dağılımı</h3>
            <div class="qc2-qty-grid">
                <?= $qc2_input('quantities.processed_kg', $qc2_q['processed_kg'] ?? null, 'İşlenen ham KG', ['optional' => true, 'placeholder' => 'Boş = ham mal KG']) ?>
                <?= $qc2_input('quantities.domestic_kg', $qc2_q['domestic_kg'] ?? 0, 'Çıkma KG') ?>
                <?= $qc2_input('quantities.industry_kg', $qc2_q['industry_kg'] ?? 0, 'Suluk KG') ?>
                <?= $qc2_input('quantities.waste_kg', $qc2_q['waste_kg'] ?? 0, 'Çöp KG') ?>
                <div class="qc2-readout"><span>Henüz sınıflandırılmamış KG</span><?= $qc2_out('unclassified_kg') ?></div>
            </div>
            <p class="qc-hint">Çıkma ve suluk çöp sayılmaz; satılmamış ürünün değeri gelir sayılmaz. Çıkma + suluk + çöp + ihracat, ham mal KG'yi aşamaz. İşlenen ham KG boşsa ham mal KG alınır; yalnızca bir kalemi Ayar'dan "İşlenen ham KG" tabanına bağlarsanız etkisi olur.</p>
        </div>
    </section>

    <section class="qc-costs" aria-labelledby="qc2-costs-heading"><div class="qc-section-heading"><h2 id="qc2-costs-heading">Maliyet kalemleri</h2><span>TL</span></div>
        <p class="qc-hint">Her kalem kendi tabanıyla hesaplanır. Taban, birim ve miktar için kalemin <strong>Ayar</strong> bölümünü açın.</p>
        <div class="qc2-table-head" aria-hidden="true"><span>Kalem</span><span>Birim fiyat</span><span>Tutar</span></div>
        <div id="qc-rows" class="qc-rows qc2-rows">
        <?php $qc2_mline = static function (array $ml) use ($qc2_matlabels, $qc2_num, $qc2_dis, $can_write): void {
            $mcode = (string)($ml['code'] ?? '');
            $mcustom = !isset($qc2_matlabels[$mcode]);
            $mlabel = $qc2_matlabels[$mcode] ?? (string)($ml['label'] ?? '');
        ?>
        <div class="qc2-mat-line" data-qc2-mline="<?= h($mcode) ?>">
            <?php if ($mcustom): ?><label class="qc2-mat-name"><span>Malzeme adı</span><input data-qc2-mat="label" type="text" maxlength="80" autocomplete="off" value="<?= h($mlabel) ?>"<?= $qc2_dis ?>></label>
            <?php else: ?><strong class="qc2-mat-name"><?= h($mlabel) ?></strong><?php endif; ?>
            <label><span>Palet başına adet</span><input data-qc2-mat="qty" type="text" inputmode="decimal" autocomplete="off" aria-label="<?= h($mlabel ?: 'Yeni malzeme') ?> palet başına adet" value="<?= h($qc2_num($ml['qty'] ?? 0)) ?>"<?= $qc2_dis ?>></label>
            <label><span>Birim fiyat (TL)</span><input data-qc2-mat="price" type="text" inputmode="decimal" autocomplete="off" aria-label="<?= h($mlabel ?: 'Yeni malzeme') ?> birim fiyatı" value="<?= h($qc2_num($ml['price'] ?? 0)) ?>"<?= $qc2_dis ?>></label>
            <output data-qc2-mat-out aria-label="Palet başı tutar">0,00 TL</output>
            <?php if ($mcustom && $can_write): ?><button type="button" class="qc-remove-button" data-qc2-mat-remove>Kaldır</button><?php endif; ?>
        </div>
        <?php }; ?>
        <?php $qc2_row = static function (array $item) use ($qc2_catalog, $qc2_units, $qc2_options, $qc2_num, $qc2_dis, $can_write, $qc2_calc, $qc2_view, &$qc2_mline, $qc2_md): void {
            $code = (string)($item['code'] ?? '');
            $custom = (bool)preg_match('/^custom_[a-z0-9_]{1,40}$/D', $code);
            $meta = $qc2_catalog[$code] ?? ['label' => (string)($item['label'] ?? ''), 'quick' => false];
            $label = (string)$meta['label'] ?: 'Yeni kalem';
            $basis = (string)($item['basis'] ?? 'fixed'); $unit = (string)($item['unit'] ?? 'fixed');
            $amount = 0.0;
            foreach (($qc2_calc['items'] ?? []) as $calcRow) if (($calcRow['code'] ?? '') === $code) $amount = (float)$calcRow['amount'];
            $hidden = $qc2_view === 'quick' && !$custom && empty($meta['quick']) && $amount == 0.0;
            $sel = $basis . '|' . $unit;
        ?>
        <article class="qc-row qc2-row<?= $hidden ? ' qc2-row-hidden' : '' ?>" data-qc2-row="<?= h($code) ?>">
            <div class="qc-row-head"><h3 data-qc2-title><?= h($label) ?></h3><output data-qc2-amount aria-label="<?= h($label) ?> tutarı"><?= number_format($amount, 2, ',', '.') ?> TL</output><?php if ($custom && $can_write): ?><button type="button" class="qc-remove-button" data-qc2-remove aria-label="<?= h($label) ?> kalemini kaldır">Kaldır</button><?php endif; ?></div>
            <?php if ($custom): ?><label class="qc-custom-label"><span>Kalem adı</span><input data-qc2-item="label" data-qc2-text="150" type="text" maxlength="150" required value="<?= h((string)($item['label'] ?? '')) ?>" autocomplete="off"<?= $qc2_dis ?>></label><?php endif; ?>
            <label class="qc-main-price"><span data-qc2-price-label><?= h($unit === 'fixed' ? 'Sabit tutar (TL)' : ($qc2_units[$unit]['price'] ?? 'TL')) ?></span><input data-qc2-item="unit_price" type="text" inputmode="decimal" value="<?= h($qc2_num($item['unit_price'] ?? 0)) ?>" autocomplete="off" aria-label="<?= h($label) ?> birim fiyatı"<?= $qc2_dis ?>></label>
            <p class="qc-hint" data-qc2-hint></p>
            <details class="qc-row-settings" data-qc2-settings>
                <summary aria-label="<?= h($label) ?> ayarları">Ayar</summary>
                <div class="qc-row-fields qc2-row-fields">
                    <label class="qc2-basis-field"><span>Hesaplama tabanı</span><span class="qc-unit-select"><select data-qc2-item="basis_unit" aria-label="<?= h($label) ?> hesaplama tabanı"<?= $qc2_dis ?>><?php foreach ($qc2_options as $key => $text): ?><option value="<?= h($key) ?>"<?= $sel === $key ? ' selected' : '' ?>><?= h($text) ?></option><?php endforeach; ?></select></span></label>
                    <label data-qc2-qty-wrap><span data-qc2-qty-label>Miktar</span><input data-qc2-item="qty" type="text" inputmode="decimal" value="<?= h($qc2_num($basis === 'manual' ? ($item['qty'] ?? 0) : 0)) ?>" autocomplete="off"<?= $qc2_dis ?>></label>
                    <label data-qc2-qty2-wrap><span>Gün</span><input data-qc2-item="qty2" type="text" inputmode="decimal" value="<?= h($qc2_num($item['qty2'] ?? 0)) ?>" autocomplete="off"<?= $qc2_dis ?>></label>
                </div>
            </details>
            <?php if (!empty($meta['detail'])): ?>
            <button type="button" class="qc-text-button qc2-detail-btn" data-qc2-mat-toggle aria-expanded="false" aria-controls="qc2-mat-panel">Palet detayı</button>
            <div class="qc2-mat" id="qc2-mat-panel" data-qc2-matpanel hidden>
                <h4>Palet başına malzeme</h4>
                <p class="qc-hint">Satılan palet sayısını ve bir palette kullanılan her malzemenin adedini + birim fiyatını girin: palet başı maliyet çıkar, malzeme toplamı = palet başı × palet sayısı. Girişi yapınca Malzeme tutarı bu detaydan hesaplanır.</p>
                <div class="qc2-mat-top">
                    <label for="qc2-mat-pallets"><span>Satılan palet sayısı</span><input id="qc2-mat-pallets" data-qc2="material_detail.pallets" type="text" inputmode="decimal" autocomplete="off" value="<?= h($qc2_num($qc2_md['pallets'] ?? 0)) ?>"<?= $qc2_dis ?>></label>
                    <label class="qc2-check"><input type="checkbox" data-qc2-bool="material_detail.use"<?= !empty($qc2_md['use']) ? ' checked' : '' ?><?= $qc2_dis ?>> Malzeme maliyetini bu detaydan hesapla</label>
                </div>
                <div class="qc2-mat-lines" data-qc2-mat-lines>
                    <?php foreach (($qc2_md['lines'] ?? []) as $qc2_ml) if (is_array($qc2_ml)) $qc2_mline($qc2_ml); ?>
                </div>
                <?php if ($can_write): ?>
                <template id="qc2-mat-template"><?php $qc2_mline(['code' => 'mat_custom_template', 'label' => '', 'qty' => 0, 'price' => 0]); ?></template>
                <button type="button" class="btn qc2-mat-add" data-qc2-mat-add>+ Malzeme ekle</button>
                <?php endif; ?>
                <dl class="qc2-mat-total">
                    <div><dt>Palet başına malzeme</dt><dd data-qc2-out="mat.per_pallet">—</dd></div>
                    <div><dt>Toplam (<span data-qc2-out="mat.pallets">0</span> palet)</dt><dd data-qc2-out="mat.total">—</dd></div>
                </dl>
            </div>
            <?php endif; ?>
        </article>
        <?php }; foreach (($document['items'] ?? []) as $qc2_item) if (is_array($qc2_item)) $qc2_row($qc2_item); ?>
        </div>
        <?php if ($can_write): ?>
        <template id="qc2-custom-template"><?php $qc2_row(['code' => 'custom_template', 'label' => '', 'basis' => 'fixed', 'unit' => 'fixed', 'qty' => 0, 'qty2' => 0, 'unit_price' => 0]); ?></template>
        <button id="qc2-add-item" class="btn qc-add-item" type="button" data-qc2-detail>Yeni kalem ekle</button>
        <p id="qc2-custom-limit" class="qc-hint" hidden>En fazla kırk yeni kalem eklenebilir.</p>
        <p class="qc-hint qc2-quick-only">Diğer kalemler (hasat, DTS taksi, CASUS, ek giderler) Detaylı Hesapta; tutarı olan kalem her iki görünümde de gösterilir ve hesaba katılır.</p>
        <?php endif; ?>
    </section>

    <section class="qc-panel qc-breakdown" aria-labelledby="qc2-summary-heading"><h2 id="qc2-summary-heading">Grup toplamları</h2><dl>
        <?php foreach ($qc2_groups as $qc2_key => $qc2_label): ?><div><dt><?= h($qc2_label) ?></dt><dd data-qc2-out="group.<?= h($qc2_key) ?>">—</dd></div><?php endforeach; ?>
        <div class="qc-grand-total"><dt>Toplam operasyon maliyeti</dt><dd data-qc2-out="total_tl">—</dd></div>
    </dl></section>

    <section class="qc-panel qc2-credit" data-qc2-detail aria-labelledby="qc2-credit-heading">
        <h2 id="qc2-credit-heading">Çıkma / suluk net geliri</h2>
        <p class="qc-hint">Yalnız gerçekleşmiş net satış gelirini girin. Satılmamış ürün gelir sayılmaz; otomatik gelir varsayımı yapılmaz.</p>
        <div class="qc2-qty-grid">
            <?= $qc2_input('credit.domestic_sold_kg', $qc2_credit['domestic_sold_kg'] ?? 0, 'Çıkma satılan KG') ?>
            <?= $qc2_input('credit.domestic_price', $qc2_credit['domestic_price'] ?? 0, 'Çıkma net fiyatı (TL/kg)') ?>
            <?= $qc2_input('credit.industry_sold_kg', $qc2_credit['industry_sold_kg'] ?? 0, 'Suluk satılan KG') ?>
            <?= $qc2_input('credit.industry_price', $qc2_credit['industry_price'] ?? 0, 'Suluk net fiyatı (TL/kg)') ?>
            <div class="qc2-readout"><span>Toplam net yan ürün geliri</span><?= $qc2_out('credit_total') ?></div>
        </div>
        <label class="qc2-check"><input type="checkbox" data-qc2-bool="credit.apply"<?= !empty($qc2_credit['apply']) ? ' checked' : '' ?><?= $qc2_dis ?>> Net yan ürün gelirini toplam maliyetten düş (basitleştirilmiş yönetimsel yöntem — resmi muhasebe maliyeti değildir)</label>
    </section>

    <details class="qc-panel qc2-sale"<?= (float)(is_numeric($qc2_sale['price'] ?? null) ? $qc2_sale['price'] : 0) > 0 ? ' open' : '' ?>>
        <summary><h2>Satış karşılaştırması <small>(isteğe bağlı)</small></h2></summary>
        <div class="qc2-qty-grid">
            <?= $qc2_input('sale.price', $qc2_sale['price'] ?? 0, 'Satış fiyatı (ihracat net KG başına)') ?>
            <label for="qc2-sale-currency"><span>Para birimi</span><span class="qc-unit-select"><select id="qc2-sale-currency" data-qc2="sale.currency"<?= $qc2_dis ?>><?php foreach (quick_cost_v2_currencies() as $qc2_cur => $qc2_curl): ?><option value="<?= h($qc2_cur) ?>"<?= ($qc2_sale['currency'] ?? 'TL') === $qc2_cur ? ' selected' : '' ?>><?= h($qc2_curl) ?></option><?php endforeach; ?></select></span></label>
            <?= $qc2_input('sale.rate', $qc2_sale['rate'] ?? 1, 'Döviz kuru (1 birim = ? TL)', ['class' => 'qc2-rate']) ?>
            <?= $qc2_input('sale.target_margin', $qc2_sale['target_margin'] ?? null, 'Hedef kâr marjı (%)', ['optional' => true, 'placeholder' => 'İsteğe bağlı']) ?>
        </div>
        <dl class="qc2-sale-results">
            <div><dt>Tahmini ihracat satış tutarı</dt><dd data-qc2-out="sale.revenue">—</dd></div>
            <div><dt>İhracata yüklenen tahmini maliyet</dt><dd data-qc2-out="sale.cost">—</dd></div>
            <div class="qc-grand-total"><dt>Tahmini kâr / zarar</dt><dd data-qc2-out="sale.profit">—</dd></div>
            <div><dt>KG başına kâr / zarar</dt><dd data-qc2-out="sale.profit_per_kg">—</dd></div>
            <div><dt>Kâr marjı (kâr ÷ satış)</dt><dd data-qc2-out="sale.margin">—</dd></div>
            <div><dt>Başabaş satış fiyatı</dt><dd data-qc2-out="sale.break_even">—</dd></div>
            <div><dt>Hedef marj için gerekli fiyat</dt><dd data-qc2-out="sale.target_price">—</dd></div>
        </dl>
        <p class="qc-hint">Kâr marjı satış tutarına göredir (maliyet üzerine ekleme oranı değildir). Kur elle girilir; otomatik kur kullanılmaz. Satış fiyatı girmeden de hesap kaydedilebilir.</p>
    </details>

    <section class="qc-hero qc2-total-card" aria-label="Toplam operasyon maliyeti">
        <div class="qc-hero-total"><span>TOPLAM OPERASYON MALİYETİ (TL)</span><?= $qc2_out('total', $qc2_calc ? $qc2_money($qc2_calc['total']) : '—') ?></div>
    </section>

    <ul class="qc2-warnings" data-qc2-out="warnings" aria-live="polite"><?php foreach (($qc2_calc['warnings'] ?? []) as $qc2_w): ?><li data-code="<?= h($qc2_w['code']) ?>"><?= h($qc2_w['text']) ?></li><?php endforeach; ?></ul>
    <p id="qc-validation" class="qc-error" role="alert" hidden></p>
    <?php if ($can_write): ?>
    <div class="qc-save-bar" data-ready="<?= $storage_ready ? '1' : '0' ?>"><button id="qc-save" class="btn btn-primary" type="submit" disabled><?= !empty($is_copy) ? 'Kopyayı Yeni Hesap Olarak Kaydet' : 'Hesaplamayı Kaydet' ?></button><small><?= $storage_ready ? 'Kaydedilen sonuçlar sunucuda yeniden hesaplanır.' : 'Kaydetme henüz kullanılamıyor. Kurulumun tamamlanması gerekiyor.' ?></small></div>
    <?php else: ?><p class="qc-hint">Bu hesabı görüntülüyorsunuz. Düzenlemek için Hızlı Maliyet yazma yetkisi gerekir.</p><?php endif; ?>
</form>
</div>
