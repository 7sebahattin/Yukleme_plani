<?php
// =========================================================
// _form.php (v2)
// Kayıt formu (create ve edit ortak kullanır)
//   $record / $pallets / $form_action / $title / $submit_label
//   $form_is_cikma — true ise nakliye + bazı genel alanlar gizlenir
// =========================================================

$kasa_cinsi_list = get_definitions_by_type('kasa_cinsi');
$palet_tipi_list = get_definitions_by_type('palet_tipi');
$all_materials   = get_all_active_materials();
$firma_list      = get_definitions_by_type('firma');
$urun_list       = get_definitions_by_type('urun');
$depo_list       = get_definitions_by_type('depo');
$bolge_list      = get_definitions_by_type('bolge');
$type_labels     = definition_types();

// Stored values for selects (may not be in definitions list)
$_firma_names  = array_column($firma_list,  'name');
$_urun_names   = array_column($urun_list,   'name');
$_depo_names   = array_column($depo_list,   'name');
$_bolge_names  = array_column($bolge_list,  'name');
$_cur_firma    = $record['firma']         ?? '';
$_cur_urun     = $record['urun']          ?? '';
$_cur_depo     = $pallets[0]['depo']      ?? '';
$_cur_bolge    = $record['bolge']         ?? '';

// Helper: render select options with optional legacy value fallback
if (!function_exists('sel_opt')) {
    function sel_opt(string $name, string $stored, array $names): string {
        $out = '<option value="">-- seçiniz --</option>';
        if ($stored !== '' && !in_array($stored, $names, true)) {
            $out .= '<option value="'.htmlspecialchars($stored, ENT_QUOTES).'" selected>'.htmlspecialchars($stored, ENT_QUOTES).'</option>';
        }
        foreach ($names as $n) {
            $sel = $stored === $n ? ' selected' : '';
            $out .= '<option value="'.htmlspecialchars($n, ENT_QUOTES).'"'.$sel.'>'.htmlspecialchars($n, ENT_QUOTES).'</option>';
        }
        return $out;
    }
}

$mat_js = [];
foreach ($all_materials as $m) {
    $mat_js[(int)$m['id']] = [
        'type' => $m['type'],
        'name' => $m['name'],
        'unit' => (float)$m['unit_dara_kg'],
    ];
}

// Düzenleme modunda kartları başlangıçta kapalı aç (özet daha önemli)
$is_edit_mode  = !empty($record['id']);
$collapsed_class = $is_edit_mode ? ' collapsed' : '';
$form_is_cikma = $form_is_cikma ?? false;
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
                <select name="firma"><?= sel_opt('firma', $_cur_firma, $_firma_names) ?></select>
                <?php if (empty($_firma_names)): ?><small class="muted">Tanımlar → Firmalar'dan ekleyin</small><?php endif; ?>
            </label>
            <label>Bölge
                <select name="bolge"><?= sel_opt('bolge', $_cur_bolge, $_bolge_names) ?></select>
                <?php if (empty($_bolge_names)): ?><small class="muted">Tanımlar → Bölgeler'den ekleyin</small><?php endif; ?>
            </label>
            <?php if (!$form_is_cikma): ?>
            <label>Parti No
                <input type="text" name="parti_no" value="<?= h($record['parti_no'] ?? '') ?>">
            </label>
            <?php endif; ?>
            <label>Tarih
                <input type="date" name="tarih" value="<?= h($record['tarih'] ?? '') ?>">
            </label>
            <?php if (!$form_is_cikma): ?>
            <label>Alıcı
                <input type="text" name="alici" value="<?= h($record['alici'] ?? '') ?>">
            </label>
            <?php endif; ?>
            <label>Ürün
                <select name="urun" id="genelUrun"><?= sel_opt('urun', $_cur_urun, $_urun_names) ?></select>
                <?php if (empty($_urun_names)): ?><small class="muted">Tanımlar → Ürünler'den ekleyin</small><?php endif; ?>
            </label>
            <label>Depo
                <select name="depo_varsayilan" id="genelDepo"><?= sel_opt('depo', $_cur_depo, $_depo_names) ?></select>
                <?php if (empty($_depo_names)): ?><small class="muted">Tanımlar → Depolar'dan ekleyin</small><?php endif; ?>
            </label>
            <?php if (!$form_is_cikma): ?>
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
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ============== NAKLİYE BİLGİLERİ (açılır) — yükleme'de göster ============== -->
<?php if (!$form_is_cikma): ?>
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
<?php endif; ?>

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
<script id="palletsInit" type="application/json"><?= json_encode(
    array_map(fn($p) => array_merge($p, [
        'brut_kg'    => isset($p['brut_kg'])    ? (float)$p['brut_kg']    : null,
        'dara_kg'    => isset($p['dara_kg'])    ? (float)$p['dara_kg']    : null,
        'net_kg'     => isset($p['net_kg'])     ? (float)$p['net_kg']     : null,
        'kasa_adeti' => isset($p['kasa_adeti']) ? (int)  $p['kasa_adeti'] : null,
    ]), $pallets),
    JSON_UNESCAPED_UNICODE) ?></script>
<script id="depoListData" type="application/json"><?= json_encode($_depo_names, JSON_UNESCAPED_UNICODE) ?></script>

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
        <label class="pm-label pm-span2">
          <span>Depo</span>
          <select id="pmDepo"><option value="">-- Depo seçiniz --</option></select>
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
