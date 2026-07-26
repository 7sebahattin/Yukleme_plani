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
// Yeni kayıtta depo varsayılanı = aktif depo (depo döngüsü: OLUŞTUR adımı)
$_cur_depo     = ($pallets[0]['depo'] ?? '') !== ''
    ? $pallets[0]['depo']
    : ((function_exists('active_depot') ? active_depot() : null) ?? '');
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

// Input'a data-suggest + maxlength ekler. Kolon uzunluğu maxlength olarak
// verilir; sunucu tarafı da clamp_loading_record_fields() ile kırpar.
if (!function_exists('suggest_attrs')) {
    function suggest_attrs(string $field): string {
        $m = record_suggest_fields()[$field] ?? null;
        if ($m === null) return '';
        return 'data-suggest="' . htmlspecialchars($field, ENT_QUOTES) . '"'
             . ' data-suggest-label="' . htmlspecialchars($m['label'], ENT_QUOTES) . '"'
             . ' maxlength="' . (int)$m['max'] . '"'
             . ' autocomplete="off"';
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

// ── Önerili serbest metin alanları (Sprint Öneri-01) ──────
// $form_is_cikma NORMALİZE EDİLDİKTEN SONRA hesaplanmalı. Çıkma formunda
// bu alanlar hiç render edilmiyor → boşuna sorgu atma.
$_suggest_lists = $form_is_cikma ? [] : record_suggest_lists();
?>
<form method="post" action="<?= h($form_action) ?>" id="recordForm" class="record-form" novalidate>
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
<?php if ($is_edit_mode): ?>
    <input type="hidden" name="id" value="<?= (int)$record['id'] ?>">
<?php endif; ?>

<div class="page-head">
    <h1><?= h($title) ?></h1>
    <div class="page-head-actions">
        <?php
        $_cu     = $cancel_url ?? 'records.php';
        $_flpage = (strpos($_cu, 'cikmalar') !== false) ? 'cikmalar' : 'records';
        ?>
        <a href="<?= h($_cu) ?>" class="btn btn-ghost"
           data-list-back="<?= $_flpage ?>"
           data-record-id="<?= (int)($record['id'] ?? 0) ?>">İptal</a>
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
            <label>Firma <span class="req">*</span>
                <select name="firma"><?= sel_opt('firma', $_cur_firma, $_firma_names) ?></select>
                <?php if (empty($_firma_names)): ?><small class="muted">Tanımlar → Firmalar'dan ekleyin</small><?php endif; ?>
            </label>
            <label>Bölge
                <select name="bolge"><?= sel_opt('bolge', $_cur_bolge, $_bolge_names) ?></select>
                <?php if (empty($_bolge_names)): ?><small class="muted">Tanımlar → Bölgeler'den ekleyin</small><?php endif; ?>
            </label>
            <?php if (!$form_is_cikma): ?>
            <label>Parti No
                <input type="text" name="parti_no" value="<?= h($record['parti_no'] ?? '') ?>" data-uppercase="tr">
            </label>
            <?php endif; ?>
            <label>Tarih <span class="req">*</span>
                <input type="date" name="tarih" value="<?= h($record['tarih'] ?? '') ?>">
            </label>
            <?php if ($form_is_cikma): ?>
            <label>Çıkış Nedeni <span class="req">*</span>
                <select name="cikis_nedeni">
                    <option value="">-- seçiniz --</option>
                    <?php foreach (cikis_nedeni_listesi() as $_cn): ?>
                    <option value="<?= h($_cn) ?>"
                        <?= ($record['cikis_nedeni'] ?? '') === $_cn ? 'selected' : '' ?>>
                        <?= h($_cn) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <?php if (!$form_is_cikma): ?>
            <label>Alıcı
                <input type="text" name="alici" value="<?= h($record['alici'] ?? '') ?>" data-uppercase="tr"
                       <?= suggest_attrs('alici') ?>>
            </label>
            <?php endif; ?>
            <label>Ürün <span class="req">*</span>
                <select name="urun" id="genelUrun"><?= sel_opt('urun', $_cur_urun, $_urun_names) ?></select>
                <?php if (empty($_urun_names)): ?><small class="muted">Tanımlar → Ürünler'den ekleyin</small><?php endif; ?>
            </label>
            <label>Depo
                <select name="depo_varsayilan" id="genelDepo"><?= sel_opt('depo', $_cur_depo, $_depo_names) ?></select>
                <?php if (empty($_depo_names)): ?><small class="muted">Tanımlar → Depolar'dan ekleyin</small><?php endif; ?>
            </label>
            <?php if (!$form_is_cikma): ?>
            <?php $_cur_urun_sahibi = (int)($record['urun_sahibi_id'] ?? 0); ?>
            <label class="span-2">Ürün Sahibi
                <select name="urun_sahibi_id" id="urunSahibiSel"
                        class="<?= $_cur_urun_sahibi > 0 ? 'urun-sahibi-selected' : '' ?>">
                    <option value="">Seçilmedi — Asya Fresh kabul edilir</option>
                    <?php foreach ($firma_list as $_us): ?>
                    <option value="<?= (int)$_us['id'] ?>"<?= $_cur_urun_sahibi === (int)$_us['id'] ? ' selected' : '' ?>>
                        <?= h($_us['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small class="muted">Boş bırakılırsa Asya Fresh malı sayılır.</small>
            </label>
            <?php $_brand_col_ok = !function_exists('db_has_column') || db_has_column('loading_records', 'brand'); ?>
            <?php if (!$_brand_col_ok): ?>
            <div class="span-2" style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:10px 12px;color:#991b1b;font-size:.83rem;line-height:1.5">
                ⚠ <strong>Marka kaydedilemiyor:</strong> veritabanında <code>loading_records.brand</code> kolonu yok.<br>
                <?php if (function_exists('is_admin') && is_admin()): ?>
                <a href="fix_brand.php" class="btn btn-sm btn-primary" style="margin-top:6px" target="_blank">🔧 Kolonu Otomatik Ekle</a>
                <span style="margin-left:6px">veya phpMyAdmin → SQL:
                <code style="background:#fff;padding:2px 6px;border-radius:4px;user-select:all">ALTER TABLE `loading_records` ADD COLUMN `brand` VARCHAR(20) NULL;</code></span>
                <?php else: ?>
                Lütfen bir yönetici ile iletişime geçin.
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <label class="span-2">Marka
                <?php
                $_cur_brand = strtoupper(trim((string)($record['brand'] ?? '')));
                try {
                    $_brand_opts = db()->query("SELECT name FROM material_definitions WHERE type='marka' AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
                } catch (Throwable $_be) {
                    $_brand_opts = ['ASYA', 'URAL', 'URAS', 'AGRO'];
                }
                ?>
                <div class="brand-seg" role="radiogroup" aria-label="Marka">
                    <?php foreach ($_brand_opts as $_bv): $_bv = strtoupper($_bv); ?>
                    <label class="brand-seg-opt<?= $_cur_brand === $_bv ? ' active' : '' ?>">
                        <input type="radio" name="brand" value="<?= h($_bv) ?>" <?= $_cur_brand === $_bv ? 'checked' : '' ?>>
                        <span><?= h($_bv) ?></span>
                    </label>
                    <?php endforeach; ?>
                    <label class="brand-seg-opt brand-seg-clear<?= $_cur_brand === '' ? ' active' : '' ?>">
                        <input type="radio" name="brand" value="" <?= $_cur_brand === '' ? 'checked' : '' ?>>
                        <span>—</span>
                    </label>
                </div>
                <small class="muted">Etiket çıktısında işaretlenecek marka. <a href="definitions.php?section=ticari" target="_blank" style="font-size:.85em">Markaları düzenle →</a></small>
            </label>
            <label class="span-2">Not
                <input type="text" name="etiket" value="<?= h($record['etiket'] ?? '') ?>" placeholder="Yazdırmada görünecek not" style="border:2px solid #c00000;">
            </label>
            <label>Gümrük
                <input type="text" name="gumruk" value="<?= h($record['gumruk'] ?? '') ?>" data-uppercase="tr"
                       <?= suggest_attrs('gumruk') ?>>
            </label>
            <label>Casus No
                <input type="text" name="casus_no" value="<?= h($record['casus_no'] ?? '') ?>" data-uppercase="tr">
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
                <input type="text" name="nakliye_sirketi" value="<?= h($record['nakliye_sirketi'] ?? '') ?>" data-uppercase="tr"
                       <?= suggest_attrs('nakliye_sirketi') ?>>
            </label>
            <label>Şoför Adı
                <input type="text" name="sofor_adi" value="<?= h($record['sofor_adi'] ?? '') ?>" data-uppercase="tr"
                       <?= suggest_attrs('sofor_adi') ?>>
            </label>
            <label>Telefon
                <input type="tel" name="telefon" value="<?= h($record['telefon'] ?? '') ?>" inputmode="tel"
                       <?= suggest_attrs('telefon') ?>>
            </label>
            <label>Ön Plaka No
                <input type="text" name="on_plaka" value="<?= h($record['on_plaka'] ?? '') ?>" data-uppercase="tr"
                       <?= suggest_attrs('on_plaka') ?>>
            </label>
            <label>Arka Plaka No
                <input type="text" name="arka_plaka" value="<?= h($record['arka_plaka'] ?? '') ?>" data-uppercase="tr"
                       <?= suggest_attrs('arka_plaka') ?>>
            </label>
            <label>Ulaşım
                <input type="text" name="ulasim" value="<?= h($record['ulasim'] ?? '') ?>" data-uppercase="tr"
                       <?= suggest_attrs('ulasim') ?>>
            </label>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ============== YÜKLEME PLANI / PALETLER ============== -->
<section class="card">
    <div class="card-head pallet-card-head">
        <h2>Yükleme Planı (Paletler)</h2>
        <div class="pallet-actions">
            <div class="pallet-sec-btns">
                <button type="button" class="btn btn-ghost btn-sm" id="excelAcBtn">📥 Excel Yükle</button>
                <button type="button" class="btn btn-ghost btn-sm" id="topluAcBtn">⊞ Toplu Düzenle</button>
                <button type="button" class="btn btn-ghost btn-sm" id="yapistirAcBtn">📋 Yapıştır</button>
                <button type="button" class="btn btn-ghost btn-sm" id="topluIsleBtn">✓ Toplu Raporla</button>
            </div>
            <button type="button" class="btn btn-primary pallet-add-main" id="addPalletBtn">+ Yeni Palet Ekle</button>
        </div>
    </div>

    <!-- Excel Yükleme Paneli -->
    <div id="excelPanel" class="toplu-panel" style="display:none">
        <div class="toplu-panel-head">
            <strong>📥 Excel'den Yükle</strong>
            <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
                <a href="excel_ornek_palet.php" class="btn btn-sm btn-ghost" download>⬇ Örnek İndir</a>
                <button type="button" id="excelKapat" class="btn btn-sm btn-ghost">✕</button>
            </div>
        </div>
        <label id="excelDropZone" class="excel-drop-zone" for="excelFile">
            <span>📂 Excel dosyasını buraya sürükleyin veya tıklayın</span>
            <small>.xlsx &nbsp;·&nbsp; .xls &nbsp;·&nbsp; .csv</small>
        </label>
        <input type="file" id="excelFile" accept=".xlsx,.xls,.csv" style="display:none">

        <div id="excelPreviewWrap" style="display:none">
            <div class="toplu-tablo-wrap" style="margin-top:10px">
                <table class="toplu-tablo">
                    <thead><tr>
                        <th>#</th><th>Palet No</th><th>Kasa Adeti</th><th>Brüt KG</th><th></th>
                    </tr></thead>
                    <tbody id="excelTbody"></tbody>
                </table>
            </div>

            <div class="excel-bulk-section">
                <strong style="font-size:.85rem">Tüm Satırlara Uygula:</strong>
                <div class="excel-bulk-grid">
                    <label>Kasa Cinsi<select id="excelKasaCinsi"></select></label>
                    <label>Palet Tipi<select id="excelPaletTipi"></select></label>
                    <label>Depo<select id="excelDepo"></select></label>
                    <label>Ürün Cinsi<select id="excelUrunCinsi"><option value="">—</option></select></label>
                </div>
            </div>

            <div class="toplu-actions" style="margin-top:10px">
                <button type="button" id="excelListeyeEkle" class="btn btn-primary">
                    ✓ Listeye Ekle (<span id="excelRowCount">0</span> palet)
                </button>
            </div>
        </div>
    </div>

    <!-- Toplu Giriş — Excel Tablo -->
    <div id="topluPanel" class="toplu-panel" style="display:none">
        <div class="toplu-panel-head">
            <strong>Toplu Palet Düzenleme</strong>
            <span class="muted" id="topluCount" style="font-size:.82rem"></span>
            <div style="margin-left:auto;display:flex;gap:8px">
                <button type="button" id="topluSatirEkle" class="btn btn-sm btn-ghost">+ Satır</button>
                <button type="button" id="topluListeyeEkle" class="btn btn-sm btn-primary">✓ Kaydet</button>
                <button type="button" id="topluKapat" class="btn btn-sm btn-ghost">✕</button>
            </div>
        </div>
        <div class="toplu-tablo-wrap">
            <table class="toplu-tablo">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Palet</th>
                        <th>Kasa Adeti</th>
                        <th>Brüt KG</th>
                        <th>Kasa Cinsi</th>
                        <th>Palet Tipi</th>
                        <th>Depo</th>
                        <th>Ürün</th>
                        <th class="tp-num">Dara</th>
                        <th class="tp-num">Net</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="topluTbody"></tbody>
            </table>
        </div>
        <p class="muted" style="font-size:.78rem;margin:6px 0 0">
            Enter → aynı sütunda alt satıra geç &nbsp;·&nbsp; Tab → sağ hücre &nbsp;·&nbsp;
            <strong>Bir hücreye çok satırlı veri yapıştırırsanız aşağıya doğru dolar.</strong>
        </p>
    </div>

    <!-- Yapıştır — Excel sütunu kopyala/yapıştır -->
    <div id="yapistirPanel" class="toplu-panel" style="display:none">
        <div class="toplu-panel-head">
            <strong>📋 Excel'den Yapıştır</strong>
            <span class="muted" id="ypCount" style="font-size:.82rem"></span>
            <div style="margin-left:auto;display:flex;gap:8px">
                <button type="button" id="ypTemizle" class="btn btn-sm btn-ghost">Temizle</button>
                <button type="button" id="ypKapat" class="btn btn-sm btn-ghost">✕</button>
            </div>
        </div>

        <div class="yp-cols">
            <div class="yp-col">
                <label class="yp-col-head" for="ypKasa">Kasa Adeti sütunu</label>
                <textarea id="ypKasa" class="yp-ta" rows="6" spellcheck="false" autocomplete="off"
                          inputmode="numeric" placeholder="150&#10;150&#10;380&#10;…"></textarea>
                <small class="yp-col-info" id="ypKasaInfo">0 satır</small>
            </div>
            <div class="yp-col">
                <label class="yp-col-head" for="ypBrut">Brüt KG sütunu</label>
                <textarea id="ypBrut" class="yp-ta" rows="6" spellcheck="false" autocomplete="off"
                          inputmode="decimal" placeholder="828&#10;823&#10;830&#10;…"></textarea>
                <small class="yp-col-info" id="ypBrutInfo">0 satır</small>
            </div>
            <div class="yp-col">
                <label class="yp-col-head" for="ypSize">Size sütunu <span class="yp-ops">opsiyonel</span></label>
                <textarea id="ypSize" class="yp-ta" rows="6" spellcheck="false" autocomplete="off"
                          placeholder="60/70&#10;60/70&#10;70/80&#10;…"></textarea>
                <small class="yp-col-info" id="ypSizeInfo">0 satır</small>
            </div>
        </div>
        <p class="yp-hint">
            Excel'de sütunu seçip kopyalayın, ilgili kutuya yapıştırın — satır sırası korunur.
            Birden fazla sütunu birlikte kopyalarsanız soldan sağa otomatik dağıtılır.
            Size boş bırakılabilir; tek bir değer yazarsanız tüm satırlara uygulanır.
        </p>

        <div class="excel-bulk-section">
            <strong style="font-size:.85rem">Tüm Satırlara Uygulanacak (1. satır değerleri):</strong>
            <div class="excel-bulk-grid">
                <label><span>Kasa Cinsi <span class="req">*</span></span><select id="ypKasaCinsi"></select></label>
                <label><span>Palet Tipi <span class="req">*</span></span><select id="ypPaletTipi"></select></label>
                <label><span>Depo <span class="req">*</span></span><select id="ypDepo"></select></label>
                <label><span>Ürün Cinsi</span><select id="ypUrun"></select></label>
            </div>
        </div>

        <div id="ypUyari" class="yp-uyari" style="display:none"></div>

        <div id="ypOnizlemeWrap" style="display:none">
            <div class="toplu-tablo-wrap" style="margin-top:10px">
                <table class="toplu-tablo yp-onizleme">
                    <thead><tr>
                        <th>#</th><th>Kasa Adeti</th><th>Brüt KG</th><th>Size</th>
                        <th class="tp-num">Dara</th><th class="tp-num">Net</th>
                    </tr></thead>
                    <tbody id="ypTbody"></tbody>
                </table>
            </div>
            <div class="yp-ozet" id="ypOzet"></div>
        </div>

        <div class="yp-mode-row">
            <label class="yp-mode"><input type="radio" name="ypMode" value="ekle" checked> Mevcut listeye ekle</label>
            <label class="yp-mode"><input type="radio" name="ypMode" value="degistir"> Listenin yerine geçsin</label>
        </div>

        <div class="toplu-actions">
            <button type="button" id="ypUygula" class="btn btn-primary yp-uygula" disabled>
                ✓ Paletleri Oluştur (<span id="ypRowCount">0</span>)
            </button>
        </div>
    </div>

    <div id="palletList" class="pallet-cards"></div>

    <!-- Toplamlar (mavi alan) -->
    <div class="totals">
        <div><span>Toplam Kasa</span><strong id="totKasa">0</strong></div>
        <div><span>Toplam Brüt</span><strong id="totBrut">0,000</strong></div>
        <div class="tot-orange"><span>Toplam Dara</span><strong id="totDara">0,000</strong></div>
        <div class="tot-orange"><span>Toplam Net</span><strong class="strong" id="totNet">0,000</strong></div>
    </div>

    <?php if ($is_edit_mode): ?>
    <!-- İşaretli / İşaretsiz ayrım özeti (sadece düzenleme modunda) -->
    <div id="islendiOzetWrap">
        <button type="button" id="islendiOzetToggle" class="islendi-ozet-toggle">▸ Raporlandı / Raporlanmadı Ayrım</button>
        <div id="islendiOzetPanel" class="islendi-ozet-panel" style="display:none">
            <div class="islendi-ozet-grid">
                <div class="islendi-ozet-section islendi-ozet-done">
                    <div class="islendi-ozet-head">✓ Raporlandı Paletler</div>
                    <div class="islendi-ozet-row"><span>Palet</span><strong id="isAdet">0</strong></div>
                    <div class="islendi-ozet-row"><span>Kasa</span><strong id="isKasa">0</strong></div>
                    <div class="islendi-ozet-row"><span>Brüt KG</span><strong id="isBrut">0,000</strong></div>
                    <div class="islendi-ozet-row"><span>Dara KG</span><strong id="isDara">0,000</strong></div>
                    <div class="islendi-ozet-row"><span>Net KG</span><strong id="isNet">0,000</strong></div>
                </div>
                <div class="islendi-ozet-section islendi-ozet-pending">
                    <div class="islendi-ozet-head">○ Raporlanmadı Paletler</div>
                    <div class="islendi-ozet-row"><span>Palet</span><strong id="nisAdet">0</strong></div>
                    <div class="islendi-ozet-row"><span>Kasa</span><strong id="nisKasa">0</strong></div>
                    <div class="islendi-ozet-row"><span>Brüt KG</span><strong id="nisBrut">0,000</strong></div>
                    <div class="islendi-ozet-row"><span>Dara KG</span><strong id="nisDara">0,000</strong></div>
                    <div class="islendi-ozet-row"><span>Net KG</span><strong id="nisNet">0,000</strong></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</section>

<div class="form-foot">
    <a href="<?= h($cancel_url ?? 'records.php') ?>" class="btn btn-ghost">İptal</a>
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
        'islendi'    => !empty($p['islendi'])   ? true                    : false,
        // Malzeme miktarlarını TEMİZ sayı olarak ver: DB "90.000" → 90.
        // (Aksi halde num() '.'yi binlik ayırıcı sanıp 90→90000 yapıyordu.)
        'materials'  => array_map(fn($m) => [
            'material_id' => (int)($m['material_id'] ?? 0),
            'quantity'    => (float)($m['quantity'] ?? 0),
        ], $p['materials'] ?? []),
    ]), $pallets),
    JSON_UNESCAPED_UNICODE) ?></script>
<script id="suggestData" type="application/json"><?= json_encode($_suggest_lists, JSON_UNESCAPED_UNICODE) ?></script>
<script id="depoListData" type="application/json"><?= json_encode($_depo_names, JSON_UNESCAPED_UNICODE) ?></script>
<script id="urunListData" type="application/json"><?= json_encode($_urun_names, JSON_UNESCAPED_UNICODE) ?></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

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
          <select id="pmUrunCinsi"><option value="">-- ürün cinsi seçiniz --</option></select>
        </label>
        <label class="pm-label pm-span2">
          <span>Depo <span class="req">*</span></span>
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
<script>
(function () {
    var btn   = document.getElementById('islendiOzetToggle');
    var panel = document.getElementById('islendiOzetPanel');
    if (!btn || !panel) return;
    btn.addEventListener('click', function () {
        var open = panel.style.display !== 'none';
        panel.style.display = open ? 'none' : 'block';
        btn.textContent = (open ? '▸' : '▾') + ' Raporlandı / Raporlanmadı Ayrım';
    });
})();
(function () {
    var sel = document.getElementById('urunSahibiSel');
    if (!sel) return;
    sel.addEventListener('change', function () {
        this.classList.toggle('urun-sahibi-selected', this.value !== '');
    });
})();
</script>
