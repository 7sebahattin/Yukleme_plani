<?php
// =========================================================
// _form.php (v2)
// Kayıt formu (create ve edit ortak kullanır)
//   $record / $pallets / $form_action / $title / $submit_label
// =========================================================

$kasa_cinsi_list = get_definitions_by_type('kasa_cinsi');
$palet_tipi_list = get_definitions_by_type('palet_tipi');
$all_materials   = get_all_active_materials();
$firma_list      = get_definitions_by_type('firma');
$urun_list       = get_definitions_by_type('urun');
$depo_list       = get_definitions_by_type('depo');
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
        <a href="<?= h($cancel_url ?? 'records.php') ?>" class="btn btn-ghost">İptal</a>
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
                <input type="text" name="firma" value="<?= h($record['firma'] ?? '') ?>" list="firmaList" autocomplete="off">
                <datalist id="firmaList"><?php foreach ($firma_list as $f): ?><option value="<?= h($f['name']) ?>"><?php endforeach; ?></datalist>
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
                <input type="text" name="urun" id="genelUrun" value="<?= h($record['urun'] ?? '') ?>" list="urunList" autocomplete="off">
                <datalist id="urunList"><?php foreach ($urun_list as $u): ?><option value="<?= h($u['name']) ?>"><?php endforeach; ?></datalist>
            </label>
            <label>Depo <small class="muted">(palet varsayılanı)</small>
                <input type="text" name="depo_varsayilan" id="genelDepo"
                       value="<?= h($pallets[0]['depo'] ?? '') ?>" placeholder="Depo" list="depoList" autocomplete="off">
                <datalist id="depoList"><?php foreach ($depo_list as $d): ?><option value="<?= h($d['name']) ?>"><?php endforeach; ?></datalist>
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
        <button type="button" class="btn btn-primary" id="addPalletBtn">+ Yeni Palet Ekle</button>
    </div>

    <div id="palletList" class="pallet-cards"></div>

    <!-- Toplamlar (mavi alan) -->
    <div class="totals">
        <div><span>Toplam Kasa</span><strong id="totKasa">0</strong></div>
        <div><span>Toplam Brüt</span><strong id="totBrut">0,000</strong></div>
        <div class="tot-orange"><span>Toplam Dara</span><strong id="totDara">0,000</strong></div>
        <div class="tot-orange"><span>Toplam Net</span><strong class="strong" id="totNet">0,000</strong></div>
    </div>
</section>

<div class="form-foot">
    <a href="records.php" class="btn btn-ghost">İptal</a>
    <button class="btn btn-primary btn-lg" type="submit"><?= h($submit_label) ?></button>
</div>

<script id="materialsData" type="application/json"><?= json_encode($mat_js, JSON_UNESCAPED_UNICODE) ?></script>
<script id="kasaCinsiData" type="application/json"><?= json_encode(array_map(fn($r)=>['id'=>(int)$r['id'],'name'=>$r['name'],'unit'=>(float)$r['unit_dara_kg']], $kasa_cinsi_list), JSON_UNESCAPED_UNICODE) ?></script>
<script id="paletTipiData" type="application/json"><?= json_encode(array_map(fn($r)=>['id'=>(int)$r['id'],'name'=>$r['name'],'unit'=>(float)$r['unit_dara_kg']], $palet_tipi_list), JSON_UNESCAPED_UNICODE) ?></script>
<script id="materialTypesData" type="application/json"><?= json_encode($type_labels, JSON_UNESCAPED_UNICODE) ?></script>
<script id="palletsInit" type="application/json"><?= json_encode($pallets, JSON_UNESCAPED_UNICODE) ?></script>

</form>

<!-- ── PALET EKLEME / DÜZENLEME MODAL ── -->
<div id="pmOverlay" class="pm-overlay" style="display:none" role="dialog" aria-modal="true" aria-labelledby="pmTitle">
  <div class="pm-dialog">
    <div class="pm-header">
      <h2 class="pm-title" id="pmTitle">Yeni Palet Ekle</h2>
      <button type="button" class="pm-close" id="pmClose" aria-label="Kapat">✕</button>
    </div>
    <div class="pm-body">

      <!-- Canlı hesap özeti -->
      <div class="pm-calc">
        <div class="pm-calc-item">
          <div class="pm-calc-label">Dara KG</div>
          <div class="pm-calc-val" id="pmDara">0</div>
        </div>
        <div class="pm-calc-item">
          <div class="pm-calc-label">Net KG</div>
          <div class="pm-calc-val pm-calc-net" id="pmNet">0,000</div>
        </div>
      </div>

      <!-- Alanlar: 2 kolonlu grid -->
      <div class="pm-grid">
        <label class="pm-label">
          <span>Palet No</span>
          <input type="text" id="pmPaletNo" placeholder="Palet No">
        </label>
        <label class="pm-label">
          <span>Kasa Adeti *</span>
          <input type="text" inputmode="numeric" id="pmKasaAdeti" class="num kasa-adeti" placeholder="Kasa">
        </label>
        <label class="pm-label">
          <span>Size</span>
          <input type="text" id="pmSize" placeholder="Size">
        </label>
        <label class="pm-label">
          <span>Brüt KG *</span>
          <input type="text" inputmode="decimal" id="pmBrutKg" class="num brut-kg" placeholder="Brüt">
        </label>
        <label class="pm-label pm-span2">
          <span>Kasa Cinsi *</span>
          <select id="pmKasaCinsi"><option value="">-- kasa cinsi seçiniz --</option></select>
        </label>
        <label class="pm-label pm-span2">
          <span>Palet Tipi *</span>
          <select id="pmPaletTipi"><option value="">-- palet tipi seçiniz --</option></select>
        </label>
        <label class="pm-label">
          <span>Ürün Cinsi</span>
          <input type="text" id="pmUrunCinsi" placeholder="Ürün cinsi">
        </label>
        <label class="pm-label">
          <span>Depo</span>
          <input type="text" id="pmDepo" placeholder="Depo">
        </label>
      </div>

      <!-- Ek malzemeler -->
      <div class="materials-block" style="margin-top:16px">
        <div class="materials-block-head">
          <span>Ek malzemeler / dara kalemleri</span>
          <button type="button" class="btn btn-sm" id="pmAddMaterial">+ Malzeme</button>
        </div>
        <div id="pmMaterialsList"></div>
      </div>
    </div>
    <div class="pm-footer">
      <button type="button" class="btn btn-ghost" id="pmCancel">İptal</button>
      <button type="button" class="btn btn-primary" id="pmSave">Kaydet</button>
    </div>
  </div>
</div>

<script>
(function () {
    var dirty = false;
    var form = document.querySelector('form[method="post"]');
    if (!form) return;

    form.addEventListener('input',  function () { dirty = true; });
    form.addEventListener('change', function () { dirty = true; });
    form.addEventListener('submit', function () { dirty = false; });

    document.addEventListener('click', function (e) {
        if (e.target.closest('#pmSave') ||
            e.target.closest('#addPalletBtn') ||
            e.target.closest('[data-del]')) {
            dirty = true;
        }
    });

    window.addEventListener('beforeunload', function (e) {
        if (!dirty) return;
        e.preventDefault();
        e.returnValue = '';
    });
})();
</script>
