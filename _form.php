<?php
// =========================================================
// _form.php (v2)
// Kayıt formu (create ve edit ortak kullanır)
//   $record / $pallets / $form_action / $title / $submit_label
// =========================================================

$kasa_cinsi_list = get_definitions_by_type('kasa_cinsi');
$palet_tipi_list = get_definitions_by_type('palet_tipi');
$all_materials   = get_all_active_materials();
$type_labels     = definition_types();

$mat_js = [];
foreach ($all_materials as $m) {
    $mat_js[(int)$m['id']] = [
        'type' => $m['type'],
        'name' => $m['name'],
        'unit' => (float)$m['unit_dara_kg'],
    ];
}

// Düzenleme modunda kartları başlangıçta kapalı aç (özet daha önemli)
$is_edit_mode = !empty($record['id']);
$collapsed_class = $is_edit_mode ? ' collapsed' : '';
?>
<form method="post" action="<?= h($form_action) ?>" id="recordForm" class="record-form" novalidate>
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
<?php if ($is_edit_mode): ?>
    <input type="hidden" name="id" value="<?= (int)$record['id'] ?>">
<?php endif; ?>

<div class="page-head">
    <h1><?= h($title) ?></h1>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">İptal</a>
        <button class="btn btn-primary btn-lg" type="submit"><?= h($submit_label) ?></button>
    </div>
</div>

<!-- ============== GENEL BİLGİLER (açılır) ============== -->
<section class="card collapsible-card<?= $collapsed_class ?>">
    <div class="card-head card-head-toggle">
        <h2>Genel Bilgiler</h2>
        <button type="button" class="toggle-arrow" aria-label="Aç/Kapat">▾</button>
    </div>
    <div class="card-body">
        <div class="grid">
            <label>Firma
                <input type="text" name="firma" value="<?= h($record['firma'] ?? '') ?>">
            </label>
            <label>Bölge
                <input type="text" name="bolge" value="<?= h($record['bolge'] ?? '') ?>">
            </label>
            <label>Parti No
                <input type="text" name="parti_no" value="<?= h($record['parti_no'] ?? '') ?>">
            </label>
            <label>Tarih
                <input type="date" name="tarih" value="<?= h($record['tarih'] ?? '') ?>">
            </label>
            <label>Alıcı
                <input type="text" name="alici" value="<?= h($record['alici'] ?? '') ?>">
            </label>
            <label>Ürün
                <input type="text" name="urun" id="genelUrun" value="<?= h($record['urun'] ?? '') ?>">
            </label>
            <label>Depo <small class="muted">(palet varsayılanı)</small>
                <input type="text" name="depo_varsayilan" id="genelDepo"
                       value="<?= h($pallets[0]['depo'] ?? '') ?>" placeholder="Depo">
            </label>
            <label class="span-2">Etiket / Marka Bilgisi
                <input type="text" name="etiket" value="<?= h($record['etiket'] ?? '') ?>">
            </label>
            <label>Gümrük
                <input type="text" name="gumruk" value="<?= h($record['gumruk'] ?? '') ?>">
            </label>
            <label>Fatura No
                <input type="text" name="fatura_no" value="<?= h($record['fatura_no'] ?? '') ?>">
            </label>
            <label>Casus No
                <input type="text" name="casus_no" value="<?= h($record['casus_no'] ?? '') ?>">
            </label>
        </div>
    </div>
</section>

<!-- ============== NAKLİYE BİLGİLERİ (açılır) ============== -->
<section class="card collapsible-card<?= $collapsed_class ?>">
    <div class="card-head card-head-toggle">
        <h2>Nakliye Bilgileri</h2>
        <button type="button" class="toggle-arrow" aria-label="Aç/Kapat">▾</button>
    </div>
    <div class="card-body">
        <div class="grid">
            <label>Nakliye Şirketi
                <input type="text" name="nakliye_sirketi" value="<?= h($record['nakliye_sirketi'] ?? '') ?>">
            </label>
            <label>Şoför Adı
                <input type="text" name="sofor_adi" value="<?= h($record['sofor_adi'] ?? '') ?>">
            </label>
            <label>Telefon
                <input type="tel" name="telefon" value="<?= h($record['telefon'] ?? '') ?>" inputmode="tel">
            </label>
            <label>Ön Plaka No
                <input type="text" name="on_plaka" value="<?= h($record['on_plaka'] ?? '') ?>">
            </label>
            <label>Arka Plaka No
                <input type="text" name="arka_plaka" value="<?= h($record['arka_plaka'] ?? '') ?>">
            </label>
            <label>Nakliye Bedeli
                <input type="text" name="nakliye_bedeli" inputmode="decimal"
                       value="<?= h($record['nakliye_bedeli'] ?? '') ?>">
            </label>
            <label>Avans
                <input type="text" name="avans" inputmode="decimal"
                       value="<?= h($record['avans'] ?? '') ?>">
            </label>
        </div>
    </div>
</section>

<!-- ============== YÜKLEME PLANI / PALETLER ============== -->
<section class="card">
    <div class="card-head">
        <h2>Yükleme Planı (Paletler)</h2>
        <button type="button" class="btn btn-primary" id="addPalletBtn">+ Yeni Palet</button>
    </div>

    <p class="muted small-note">* işaretli alanlar zorunludur. Yeni palet eklendiğinde Ürün Cinsi ve Depo Genel Bilgilerden, diğer alanlar önceki paletten kopyalanır (kasa adeti ve brüt kg hariç).</p>

    <div class="pallets-table-head pc-only">
        <div>Palet No</div>
        <div>Kasa Adeti *</div>
        <div>Size</div>
        <div>Brüt KG *</div>
        <div>Kasa Cinsi *</div>
        <div>Palet Tipi *</div>
        <div>Ürün Cinsi</div>
        <div>Depo</div>
        <div class="num">Dara KG</div>
        <div class="num">Net KG</div>
        <div></div>
    </div>

    <div id="palletList" class="pallet-list"></div>

    <button type="button" class="btn btn-primary btn-block mobile-only" id="addPalletBtnBottom">
        + Yeni Palet Ekle
    </button>

    <!-- Toplamlar (mavi alan) -->
    <div class="totals">
        <div><span>Toplam Kasa</span><strong id="totKasa">0</strong></div>
        <div><span>Toplam Brüt</span><strong id="totBrut">0,000</strong></div>
        <div><span>Toplam Dara</span><strong id="totDara">0,000</strong></div>
        <div><span>Toplam Net</span><strong class="strong" id="totNet">0,000</strong></div>
    </div>
</section>

<div class="form-foot">
    <a href="index.php" class="btn btn-ghost">İptal</a>
    <button class="btn btn-primary btn-lg" type="submit"><?= h($submit_label) ?></button>
</div>

<script id="materialsData" type="application/json"><?= json_encode($mat_js, JSON_UNESCAPED_UNICODE) ?></script>
<script id="kasaCinsiData" type="application/json"><?= json_encode(array_map(fn($r)=>['id'=>(int)$r['id'],'name'=>$r['name'],'unit'=>(float)$r['unit_dara_kg']], $kasa_cinsi_list), JSON_UNESCAPED_UNICODE) ?></script>
<script id="paletTipiData" type="application/json"><?= json_encode(array_map(fn($r)=>['id'=>(int)$r['id'],'name'=>$r['name'],'unit'=>(float)$r['unit_dara_kg']], $palet_tipi_list), JSON_UNESCAPED_UNICODE) ?></script>
<script id="materialTypesData" type="application/json"><?= json_encode($type_labels, JSON_UNESCAPED_UNICODE) ?></script>
<script id="palletsInit" type="application/json"><?= json_encode($pallets, JSON_UNESCAPED_UNICODE) ?></script>

</form>
