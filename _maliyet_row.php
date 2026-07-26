<?php
// =========================================================
// _maliyet_row.php — Maliyet kalem satırı (maliyet_form.php partial)
// Beklenen değişkenler: $it (kalem), $ikey (form anahtarı), $skey (bölüm anahtarı),
//                       $calc_types, $pct_bases
// =========================================================
if (!isset($it)) return;
$ct     = (string)($it['calc_type'] ?? 'qty_price');
$is_pct = $ct === 'percent';
$is_fml = $ct === 'formula';
$is_ro  = in_array($ct, ['subtotal', 'info'], true);      // tutar girilmez
$has_qf = trim((string)($it['qty_formula'] ?? '')) !== '';
?>
<tr class="mly-row" data-key="<?= h($ikey) ?>" data-type="<?= h($ct) ?>">
    <td class="mly-c-drag">
        <input type="hidden" name="item[<?= h($ikey) ?>][sort]" value="<?= (int)($it['sort_order'] ?? 0) ?>" class="mly-row-sort">
        <input type="hidden" name="item[<?= h($ikey) ?>][section]" value="<?= h($skey) ?>" class="mly-row-sec">
        <span class="mly-move">
            <button type="button" class="mly-up"   title="Yukarı taşı">▲</button>
            <button type="button" class="mly-down" title="Aşağı taşı">▼</button>
        </span>
    </td>

    <td class="mly-c-label" data-label="Kalem">
        <input type="text" name="item[<?= h($ikey) ?>][label]" value="<?= h($it['label'] ?? '') ?>"
               class="mly-label" list="mlyPkgList" placeholder="Kalem adı">
        <button type="button" class="mly-row-more" title="Kod, formül ve seçenekler">⋯</button>
    </td>

    <td class="mly-c-type" data-label="Hesap Tipi">
        <select name="item[<?= h($ikey) ?>][calc_type]" class="mly-type">
            <?php foreach ($calc_types as $k => $meta): ?>
            <option value="<?= h($k) ?>" <?= $ct === $k ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </td>

    <td class="mly-c-qty num" data-label="Miktar">
        <input type="text" inputmode="decimal" name="item[<?= h($ikey) ?>][qty]" class="mly-qty"
               value="<?= fmt_edit_num($it['qty'] ?? 0, 3) ?>" <?= ($has_qf || $ct === 'per_kg' || $is_ro || $is_fml) ? 'readonly' : '' ?>>
    </td>

    <td class="mly-c-unit" data-label="Birim">
        <input type="text" name="item[<?= h($ikey) ?>][unit]" class="mly-unit"
               value="<?= h($it['unit'] ?? '') ?>" placeholder="adet" list="mlyUnitList">
    </td>

    <td class="mly-c-price num" data-label="Birim Fiyat">
        <input type="text" inputmode="decimal" name="item[<?= h($ikey) ?>][unit_price]" class="mly-price"
               value="<?= fmt_edit_num($it['unit_price'] ?? 0, 4) ?>" <?= ($is_pct || $is_fml || $is_ro) ? 'hidden' : '' ?>>
        <span class="mly-pct-wrap" <?= $is_pct ? '' : 'hidden' ?>>
            <input type="text" inputmode="decimal" name="item[<?= h($ikey) ?>][percent]" class="mly-percent"
                   value="<?= fmt_edit_num($it['percent'] ?? 0, 2) ?>"><span class="mly-pct-sign">%</span>
        </span>
        <input type="text" name="item[<?= h($ikey) ?>][formula]" class="mly-formula"
               value="<?= h($it['formula'] ?? '') ?>" placeholder="[kasa]*2+[net_kg]" <?= $is_fml ? '' : 'hidden' ?>>
    </td>

    <td class="mly-c-amount num" data-label="Tutar (TL)"><output class="mly-amount">0,00</output></td>
    <td class="mly-c-ucost num" data-label="TL/KG"><output class="mly-ucost">0,0000</output></td>

    <td class="mly-c-act">
        <button type="button" class="mly-row-del" title="Satırı sil">✕</button>
    </td>
</tr>

<tr class="mly-row-detail" data-for="<?= h($ikey) ?>" hidden>
    <td></td>
    <td colspan="8">
        <div class="mly-detail-grid">
            <label>Kod <small>formülde <code>[kod]</code></small>
                <input type="text" name="item[<?= h($ikey) ?>][code]" class="mly-code" value="<?= h($it['code'] ?? '') ?>"
                       placeholder="boş bırakılırsa addan üretilir">
            </label>
            <label>Miktar formülü <small>boşsa elle girilir</small>
                <input type="text" name="item[<?= h($ikey) ?>][qty_formula]" class="mly-qtyf"
                       value="<?= h($it['qty_formula'] ?? '') ?>" placeholder="[kasa.miktar]*2">
            </label>
            <label class="mly-detail-pct" <?= $is_pct ? '' : 'hidden' ?>>Yüzde bazı
                <input type="text" name="item[<?= h($ikey) ?>][percent_base]" class="mly-pctbase"
                       value="<?= h($it['percent_base'] ?? 'ust_toplam') ?>" list="mlyPctBaseList">
            </label>
            <label class="mly-check">
                <input type="checkbox" name="item[<?= h($ikey) ?>][is_income]" value="1"
                       class="mly-income" <?= (int)($it['is_income'] ?? 0) === 1 ? 'checked' : '' ?>>
                Gelir / düşülecek <small>(toplamdan çıkarılır)</small>
            </label>
            <label class="mly-detail-note">Not
                <input type="text" name="item[<?= h($ikey) ?>][note]" value="<?= h($it['note'] ?? '') ?>">
            </label>
        </div>
        <div class="mly-row-err" hidden></div>
    </td>
</tr>
