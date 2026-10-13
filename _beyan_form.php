<?php
// Oluşturma ve düzenlemenin ortak görünümü. İşlemler sayfalarda kalır.
$is_edit = isset($beyan) && is_array($beyan);
$form_action = $is_edit ? 'beyan_edit.php?id=' . (int)$id : 'beyan_create.php';
$return_url = $is_edit ? 'beyan_view.php?id=' . (int)$id : 'beyanlar.php';
$suggest_data = beyan_suggest_form_data();
foreach (beyan_permission_fields() as $section => $names) {
    if (!can_beyan($section . '.read')) foreach ($names as $name) unset($suggest_data['values'][$name]);
}
if (!can_beyan('contact.read')) $suggest_data['companies'] = [];
if (!can_beyan('hks.read')) $suggest_data['products'] = [];
$f = beyan_redact_fields($f);
$tabs = ['beyan' => 'Beyan'];
if (can_beyan('analysis.read')) $tabs['analiz'] = 'Analiz';
if (can_beyan('source.read')) $tabs['kaynak'] = 'Kaynak metin';
$field = static function (string $name, string $label, string $icon, string $mode = '') use ($f): void {
    $section = null;
    foreach (beyan_permission_fields() as $key => $names) if (in_array($name, $names, true)) $section = $key;
    if ($section !== null && !can_beyan($section . '.read')) return;
    $field_id = 'bf_' . $name;
    ?>
    <div class="form-group" data-ic="<?= h($icon) ?>">
        <label class="form-label" for="<?= h($field_id) ?>"><?= h($label) ?></label>
        <input type="text" id="<?= h($field_id) ?>" name="<?= h($name) ?>" class="form-control"
               value="<?= h((string)($f[$name] ?? '')) ?>"<?= $section !== null && !can_beyan($section . '.write') ? ' disabled' : '' ?><?= $mode !== '' ? ' inputmode="' . h($mode) . '"' : ' data-uppercase="tr"' ?><?= $name === 'vehicle_plate' ? ' data-nospace autocomplete="off"' : '' ?><?php if (isset(beyan_suggest_fields()[$name])): ?> data-suggest="<?= h($name) ?>" data-suggest-label="<?= h($label) ?>" data-suggest-endpoint="api_beyan_tanim_ekle.php" autocomplete="off"<?php endif; ?>>
    </div>
    <?php
};
?>
<div class="beyan-workspace" data-beyan-workspace>
<form id="beyanForm" method="post" action="<?= h($form_action) ?>" class="bf beyan-workspace-form" data-beyan-form>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <?php if ($is_edit): ?>
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <input type="hidden" name="edit_token" value="<?= h($edit_token ?? '') ?>">
    <?php endif; ?>

    <div class="page-head beyan-workspace-head">
        <div class="beyan-workspace-heading">
            <h1><?= $is_edit ? '✏️ Beyan Düzenle' : '🧾 Yeni Beyan' ?></h1>
            <?php if ($is_edit): ?><p class="muted"><?= h((string)$beyan['party_no']) ?> <?= beyan_badge_html($cur_status) ?></p><?php endif; ?>
        </div>
        <div id="beyanSingleActions" class="beyan-workspace-actions">
            <span class="muted beyan-dirty" data-beyan-dirty aria-live="polite" hidden></span>
            <a href="<?= h($return_url) ?>" class="btn btn-ghost bf-iptal" data-ic="carpi"><?= $is_edit ? 'İptal' : 'Beyanlar' ?></a>
            <!-- İlk submit her zaman kaydet: Enter tuşu silmeyi başlatmamalı. -->
            <button type="submit" form="beyanForm" class="btn btn-primary bf-kaydet" data-ic="kaydet"><?= $is_edit ? 'Güncelle' : 'Kaydet' ?></button>
        </div>
    </div>

    <?php if ($errors): ?>
    <div class="flash flash-error" role="alert" data-beyan-errors tabindex="-1">
        <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!can_beyan('analysis.write') || !can_beyan('contact.write') || !can_beyan('hks.write') || !can_beyan('source.write')): ?>
    <p class="beyan-permission-note">Yalnız yetkili olduğunuz alanları düzenleyebilirsiniz. Soluk alanlar salt okunurdur; kayıtta korunur.</p>
    <?php endif; ?>
    <div class="beyan-workspace-grid">
        <div class="beyan-workspace-main">
            <!-- JS yokken bütün bölümler erişilebilir. JS sekmeleri etkinleştirir. -->
            <div class="beyan-workspace-tabs" data-beyan-tabs role="tablist" aria-label="Beyan bölümleri" hidden>
                <?php foreach ($tabs as $key => $label): ?>
                <button type="button" class="btn btn-ghost" id="beyanTab_<?= h($key) ?>" role="tab"
                        aria-controls="beyanPanel_<?= h($key) ?>" aria-selected="<?= $key === 'beyan' ? 'true' : 'false' ?>"
                        data-beyan-tab="<?= h($key) ?>"<?= $key !== 'beyan' ? ' tabindex="-1"' : '' ?>><?= h($label) ?></button>
                <?php endforeach; ?>
            </div>

            <div id="beyanPanel_beyan" data-beyan-panel="beyan" role="tabpanel" aria-labelledby="beyanTab_beyan">
                <section class="beyan-section" data-bf="temel" data-beyan-single-section>
                    <h2 class="beyan-section-title" data-ic="pano"><span class="bf-emoji" aria-hidden="true">📋</span> Parti/Sevkiyat</h2>
                    <div class="beyan-form-grid beyan-workspace-fields">
                        <?php $field('party_no', 'Parti No', 'diyez'); ?>
                        <?php $field('buyer_name', 'Alıcı', 'kisi'); ?>
                        <?php $field('exit_depot', 'Çıkış Depo', 'depo'); ?>
                        <?php $field('transport_type', 'Nakliye Türü', 'tir'); ?>
                        <?php $field('line_type', 'Hat / Güzergah', 'rota'); ?>
                    </div>
                    <div class="beyan-form-grid beyan-workspace-fields">
                        <?php if (can_beyan('hks.read')): beyan_hks_form_bolumu($f, $is_edit ? $beyan : null, true, 'sevkiyat'); endif; ?>
                        <?php $field('tractor_plate', 'Çekici Plaka', 'arac'); ?>
                        <?php $field('vehicle_plate', 'Dorse Plaka', 'arac'); ?>
                    </div>
                </section>

                <section class="beyan-section" data-bf="urun" data-beyan-single-section>
                    <h2 class="beyan-section-title" data-ic="paket"><span class="bf-emoji" aria-hidden="true">🍎</span> Ürün ve Miktarlar</h2>
                    <div class="beyan-form-grid beyan-workspace-fields">
                        <?php $field('product_name', 'Ürün Adı', 'elma'); ?>
                        <?php if (can_beyan('hks.read')): beyan_hks_form_bolumu($f, $is_edit ? $beyan : null, can_beyan('hks.write')); endif; ?>
                        <?php $field('product_variety', 'Ürün Çeşidi', 'etiket'); ?>
                        <?php $field('brand', 'Ambalaj Marka', 'rozet'); ?>
                    </div>
                    <div class="beyan-form-grid beyan-workspace-quantities">
                        <?php $field('pallet_count', 'Palet Adedi', 'katman', 'numeric'); ?>
                        <?php $field('crate_count', 'Kasa Adedi', 'kutu', 'numeric'); ?>
                        <?php $field('crate_type', 'Kasa Cinsi', 'kasa'); ?>
                        <?php $field('gross_kg', 'Brüt KG', 'agirlik', 'decimal'); ?>
                        <?php $field('net_kg', 'Net KG', 'terazi', 'decimal'); ?>
                    </div>
                </section>

                <?php if (can_beyan('analysis.read')): ?>
                <section class="beyan-section" data-bf="urun" data-beyan-single-section>
                    <h2 class="beyan-section-title" data-ic="tup"><span class="bf-emoji" aria-hidden="true">🧪</span> Numune</h2>
                    <div class="beyan-form-grid beyan-workspace-quantities">
                        <?php $field('sample_crate_count', 'Numune Kasa Adedi', 'kutu', 'numeric'); ?>
                        <?php $field('sample_crate_type', 'Numune Kasa Cinsi', 'kasa'); ?>
                        <?php $field('sample_gross_kg', 'Numune Brüt KG', 'agirlik', 'decimal'); ?>
                        <?php $field('sample_net_kg', 'Numune Net KG', 'terazi', 'decimal'); ?>
                    </div>
                </section>
                <?php endif; ?>

                <section class="beyan-section" data-bf="lojistik" data-beyan-single-section>
                    <h2 class="beyan-section-title" data-ic="bina"><span class="bf-emoji" aria-hidden="true">🏢</span> Gümrük ve İletişim</h2>
                    <div class="beyan-form-grid">
                        <?php $field('company_name', 'Gümrük Adı', 'bina'); ?>
                        <?php $field('contact_person', 'İlgili Kişi', 'rehber'); ?>
                    </div>
                    <?php if (can_beyan('contact.read')): ?>
                    <div class="form-group" data-ic="konum">
                        <label class="form-label" for="bf_company_address">Gümrük Adresi</label>
                        <textarea id="bf_company_address"<?= !can_beyan('contact.write') ? ' disabled' : '' ?> name="company_address" rows="2" class="form-control" data-uppercase="tr" data-suggest="company_address" data-suggest-label="Gümrük Adresi" data-suggest-endpoint="api_beyan_tanim_ekle.php" autocomplete="off"><?= h((string)$f['company_address']) ?></textarea>
                    </div>
                    <?php endif; ?>
                    <div class="form-group" data-ic="liste">
                        <label class="form-label" for="bf_status">Durum</label>
                        <select id="bf_status" name="status" class="form-control"<?= !can_beyan('status') ? ' disabled' : '' ?>>
                            <?php foreach (beyan_durum_akis_sirasi() as $sk): $sv = $statuses[$sk] ?? null; if (!$sv) continue; ?>
                            <option value="<?= h($sk) ?>"<?= $f['status'] === $sk ? ' selected' : '' ?>><?= h($sv['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </section>

                <section class="beyan-section" data-bf="uyari" data-beyan-single-section>
                    <h2 class="beyan-section-title" data-ic="kalem"><span class="bf-emoji" aria-hidden="true">📝</span> Not</h2>
                    <div class="form-group" data-ic="metin">
                        <label class="form-label" for="bf_note">Not</label>
                        <textarea id="bf_note" name="note" rows="3" maxlength="2000" class="form-control"><?= h($f['note'] ?? '') ?></textarea>
                    </div>
                </section>
            </div>

            <?php if (can_beyan('analysis.read')): ?>
            <div id="beyanPanel_analiz" data-beyan-panel="analiz" role="tabpanel" aria-labelledby="beyanTab_analiz">
                <fieldset <?= !can_beyan('analysis.write') ? 'disabled' : '' ?> class="beyan-section" data-bf="durum" data-beyan-single-section>
                    <h2 class="beyan-section-title" data-ic="grafik"><span class="bf-emoji" aria-hidden="true">📊</span> Analiz Bilgileri</h2>
                    <div class="beyan-form-grid">
                        <div class="form-group" data-ic="tup">
                            <label class="form-label" for="bf_sample_taken_at">Numune Alındı Tarihi</label>
                            <input type="datetime-local" id="bf_sample_taken_at" name="sample_taken_at" class="form-control" value="<?= h($f['sample_taken_at']) ?>">
                        </div>
                        <div class="form-group" data-ic="takvim">
                            <label class="form-label" for="bf_analysis_result_at">Analiz Sonuç Tarihi</label>
                            <input type="datetime-local" id="bf_analysis_result_at" name="analysis_result_at" class="form-control" value="<?= h($f['analysis_result_at']) ?>">
                        </div>
                    </div>
                    <div class="form-group" data-ic="kalem">
                        <label class="form-label" for="analysisNote">Analiz Notu</label>
                        <textarea name="analysis_note" id="analysisNote" rows="3" class="form-control"<?= $f['status'] === 'red' ? ' required' : '' ?>><?= h((string)$f['analysis_note']) ?></textarea>
                    </div>
                </fieldset>

            </div>

            <?php endif; ?>
            <?php if (can_beyan('source.read')): ?>
            <div id="beyanPanel_kaynak" data-beyan-panel="kaynak" role="tabpanel" aria-labelledby="beyanTab_kaynak">
                <fieldset <?= !can_beyan('source.write') ? 'disabled' : '' ?> class="beyan-section" data-bf="wa">
                    <h2 class="beyan-section-title" data-ic="mesaj"><span class="bf-emoji" aria-hidden="true">📱</span> WhatsApp Metni</h2>
                    <div class="form-group" data-ic="metin">
                        <label class="form-label" for="bf_raw_text">Kaynak metin</label>
                        <textarea id="bf_raw_text" name="raw_text" rows="6" class="form-control beyan-source-text" placeholder="WhatsApp beyan mesaj(lar)ını buraya yapıştırın."><?= h((string)$f['raw_text']) ?></textarea>
                    </div>
                    <div>
                        <button type="button" class="btn btn-secondary bf-ayristir" data-ic="sihir" data-beyan-parse-btn data-base-url="<?= h(base_url()) ?>">Metni Ayrıştır</button>
                    </div>
                    <div id="beyanParseStatus" aria-live="polite" hidden></div>
                    <?php if (!$is_edit): ?><div id="beyanBulkPreview" hidden></div><?php endif; ?>
                    <details class="beyan-unmatched" data-beyan-unmatched<?= trim((string)$f['unmatched_text']) === '' ? ' hidden' : '' ?>>
                        <summary>Eşleşmeyen / Dikkat Edilecek Satırlar</summary>
                        <div class="form-group" data-ic="uyari">
                            <label class="form-label" for="bf_unmatched_text">Kontrol edilecek metin</label>
                            <textarea id="bf_unmatched_text" name="unmatched_text" rows="3" class="form-control"><?= h((string)$f['unmatched_text']) ?></textarea>
                        </div>
                    </details>
                </fieldset>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <?php if ($is_edit && can_beyan('delete')): ?>
    <details class="beyan-workspace-delete" data-beyan-single-section>
        <summary>Diğer işlemler</summary>
        <button type="submit" class="btn btn-ghost" formaction="beyan_delete.php" formmethod="post" formnovalidate
                onclick="return confirm('Bu beyan arşivden gizlenecek. Devam edilsin mi?')">Sil</button>
    </details>
    <?php endif; ?>
</form>
</div>
<script type="application/json" id="suggestData"><?= json_encode($suggest_data['values'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script type="application/json" id="beyanSuggestMeta"><?= json_encode(['companies' => $suggest_data['companies'], 'products' => $suggest_data['products']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
