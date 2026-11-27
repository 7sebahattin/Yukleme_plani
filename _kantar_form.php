<?php
// =========================================================
// _kantar_form.php
// Kantar create + edit ortak form partial.
// Beklenen değişkenler:
//   $fis          - fiş alanları (dizi); create'de boş dizi
//   $form_action  - form action URL
//   $title        - sayfa başlığı
//   $submit_label - submit buton etiketi
//   $is_edit      - bool (edit modunda resim + Sil butonu gösterilir)
//   $kasa_list    - get_definitions_by_type('kasa_cinsi') sonucu
//   $palet_list   - get_definitions_by_type('palet_tipi') sonucu
// =========================================================

require_once __DIR__ . '/config/kantar_alanlari_core.php';
$_kal = kantar_alanlari_durum(db());          // sofor / mal_sahibi / mustahsil / bahce kolonları var mı
$_kal_hazir = !in_array(false, $_kal, true);

$is_edit    = $is_edit    ?? false;
$is_window  = !empty($kantar_window_mode);
$fis        = $fis        ?? [];
$fis_id     = (int)($fis['id'] ?? 0);
$kasa_list  = $kasa_list  ?? [];
$palet_list = $palet_list ?? [];

$_uf_raw = $fis['urun_fiyati'] ?? '';
$urun_fiyati_val = ($_uf_raw !== '' && $_uf_raw !== null && (float)$_uf_raw > 0)
    ? number_format((float)$_uf_raw, 2, '.', '')
    : '';

// DB'den gelen DECIMAL değerlerini input-safe formata çevir: ondalık virgül, binlik ayırıcı YOK
// Örn: 13960.5 → "13960,5" | 13960.0 → "13960" | 0.48 → "0,48"
if (!function_exists('fmt_tartim')) {
    function fmt_tartim($v): string {
        $f = (float)$v;
        if ($f <= 0) return '';
        $s = number_format($f, 3, ',', '');  // "13960,000" — no thousands dot
        $s = rtrim($s, '0');                  // "13960,"
        if (str_ends_with($s, ',')) $s = rtrim($s, ','); // "13960"
        return $s;
    }
}

// Mevcut gruplar (edit modunda DB'den)
if ($fis_id > 0) {
    try {
        $kg_cols = array_column(db()->query("SHOW COLUMNS FROM `kantar_gruplar`")->fetchAll(), 'Field');
        $has_dara = in_array('kasa_dara_kg', $kg_cols);
        $has_brut = in_array('brut_kg', $kg_cols);
        $sel_extra = ($has_dara ? ', kasa_dara_kg, palet_dara_kg' : '') . ($has_brut ? ', brut_kg' : '');
        $_st_g = db()->prepare("SELECT grup_adi, palet_sayisi, kasa_adedi$sel_extra FROM kantar_gruplar WHERE fis_id = ? ORDER BY sira");
        $_st_g->execute([$fis_id]);
        $_grup_list = array_map(function($r) {
            $r['palet_sayisi']  = (int)  ($r['palet_sayisi']  ?? 0);
            $r['kasa_adedi']    = (int)  ($r['kasa_adedi']    ?? 0);
            $r['kasa_dara_kg']  = (float)($r['kasa_dara_kg']  ?? 0);
            $r['palet_dara_kg'] = (float)($r['palet_dara_kg'] ?? 0);
            $r['brut_kg']       = (float)($r['brut_kg']       ?? 0);
            return $r;
        }, $_st_g->fetchAll());
    } catch (PDOException $e) {
        $_grup_list = [];
    }
} else {
    $_grup_list = [];
}

// Mevcut kasa/palet satırları (edit: yeni tablodan; fallback: eski tek alanlar)
$_kp_rows = [];
if ($fis_id > 0) {
    try {
        $_kp_st = db()->prepare("SELECT tip, cinsi, sayisi, birim_dara_kg FROM kantar_kasa_palet_satir WHERE fis_id=? ORDER BY id");
        $_kp_st->execute([$fis_id]);
        $_kp_rows = array_map(fn($r) => ['tip' => $r['tip'], 'cinsi' => $r['cinsi'], 'sayisi' => (int)$r['sayisi'], 'birim_dara_kg' => (float)$r['birim_dara_kg']], $_kp_st->fetchAll());
    } catch (PDOException $e) { $_kp_rows = []; }
    if (empty($_kp_rows)) {
        if ((int)($fis['kasa_sayisi'] ?? 0) > 0 || !empty($fis['kasa_cinsi'])) {
            $_kp_rows[] = ['tip' => 'kasa', 'cinsi' => $fis['kasa_cinsi'] ?? '', 'sayisi' => (int)($fis['kasa_sayisi'] ?? 0), 'birim_dara_kg' => (float)($fis['kasa_dara'] ?? 0)];
        }
        if ((int)($fis['palet_sayisi'] ?? 0) > 0 || !empty($fis['palet_cinsi'])) {
            $_kp_rows[] = ['tip' => 'palet', 'cinsi' => $fis['palet_cinsi'] ?? '', 'sayisi' => (int)($fis['palet_sayisi'] ?? 0), 'birim_dara_kg' => (float)($fis['palet_dara'] ?? 0)];
        }
    }
} elseif (!empty($_POST['kp_rows']) && is_array($_POST['kp_rows'])) {
    foreach ($_POST['kp_rows'] as $row) {
        $t = in_array($row['tip'] ?? '', ['kasa', 'palet']) ? $row['tip'] : 'kasa';
        $_kp_rows[] = ['tip' => $t, 'cinsi' => trim((string)($row['cinsi'] ?? '')), 'sayisi' => (int)($row['sayisi'] ?? 0), 'birim_dara_kg' => (isset($row['birim_dara_kg']) && trim((string)$row['birim_dara_kg']) !== '') ? num($row['birim_dara_kg']) : null];
    }
}

// Otomatik tamamlama / seçim listeleri
$_kf_firma_hist    = array_column(db()->query("SELECT DISTINCT firma_adi FROM kantar_fisleri WHERE firma_adi!='' ORDER BY firma_adi")->fetchAll(), 'firma_adi');
$_kf_plaka_hist    = array_column(db()->query("SELECT DISTINCT plaka FROM kantar_fisleri WHERE plaka!='' ORDER BY plaka")->fetchAll(), 'plaka');
$_firma_def_names  = array_column(get_definitions_by_type('firma'),    'name');
$_urun_names       = array_column(get_definitions_by_type('urun'),     'name');
$_bolge_names      = array_column(get_definitions_by_type('bolge'),    'name');
$_depo_names_kf    = array_column(get_definitions_by_type('depo'),     'name');
$_lokasyon_names   = array_column(get_definitions_by_type('lokasyon'), 'name');
// Ek alanlar (şoför / malın sahibi / müstahsil / bahçe): geçmiş girişler + ilgili tanımlar birleşik öneri listesi
$_kal_liste = [];
foreach (['sofor' => 'sofor', 'mal_sahibi' => 'firma', 'mustahsil' => 'tedarikci', 'bahce' => null] as $_kc => $_kt) {
    $_l = [];
    if (!empty($_kal[$_kc])) {
        try { $_l = array_column(db()->query("SELECT DISTINCT `$_kc` AS v FROM kantar_fisleri WHERE `$_kc` != '' ORDER BY `$_kc`")->fetchAll(), 'v'); } catch (PDOException $e) { $_l = []; }
    }
    if ($_kt !== null) $_l = array_merge($_l, array_column(get_definitions_by_type($_kt), 'name'));
    $_l = array_values(array_unique(array_filter($_l, fn($x) => trim((string)$x) !== '')));
    sort($_l);
    $_kal_liste[$_kc] = $_l;
}
// Firma: tanımlar + geçmiş kantar girişleri birleşik
$_all_firma = array_values(array_unique(array_merge($_firma_def_names, $_kf_firma_hist)));
sort($_all_firma);

if (!function_exists('kf_sel_opt')) {
    function kf_sel_opt(string $stored, array $names, string $ph = '-- seçiniz --'): string {
        $out = '<option value="">' . htmlspecialchars($ph, ENT_QUOTES) . '</option>';
        if ($stored !== '' && !in_array($stored, $names, true)) {
            $out .= '<option value="' . htmlspecialchars($stored, ENT_QUOTES) . '" selected>' . htmlspecialchars($stored, ENT_QUOTES) . '</option>';
        }
        foreach ($names as $n) {
            $sel = $stored === $n ? ' selected' : '';
            $out .= '<option value="' . htmlspecialchars($n, ENT_QUOTES) . '"' . $sel . '>' . htmlspecialchars($n, ENT_QUOTES) . '</option>';
        }
        return $out;
    }
}
if (!function_exists('kf_datalist')) {
    function kf_datalist(string $id, array $items): string {
        $out = '<datalist id="' . htmlspecialchars($id, ENT_QUOTES) . '">';
        foreach ($items as $v) {
            $out .= '<option value="' . htmlspecialchars($v, ENT_QUOTES) . '">';
        }
        return $out . '</datalist>';
    }
}
?>
<form method="post" action="<?= h($form_action) ?>" id="kantarForm" class="mk-form<?= $is_window ? ' mk-form-win' : '' ?>">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
<?php if ($is_edit): ?>
<input type="hidden" name="id" value="<?= $fis_id ?>">
<?php /* Formdan kaldırılan alanlar: düzenlemede mevcut değerleri silinmesin */ ?>
<input type="hidden" name="operator_adi" value="<?= h($fis['operator_adi'] ?? '') ?>">
<input type="hidden" name="cikis_tarih" value="<?= h($fis['cikis_tarih'] ?? '') ?>">
<input type="hidden" name="gittigi_yer" value="<?= h($fis['gittigi_yer'] ?? '') ?>">
<?php endif; ?>

<div class="mk-form-scroll">
<?php if ($is_window): ?>
<div class="flash flash-error" id="mkWinErrors" style="display:none"></div>
<?php else: ?>
<div class="page-head mk-head">
    <div class="mk-head-title">
        <img class="mk-head-ico" src="assets/nav-icons/kantar.svg" alt="" width="44" height="44">
        <div>
            <h1><?= h($title) ?></h1>
            <p class="mk-sub"><?= $is_edit ? 'Mal kabul fişi bilgilerini güncelleyin.' : 'Giriş ve çıkış tartım bilgilerini kaydedin.' ?></p>
        </div>
    </div>
    <div class="page-head-actions">
        <a href="kantar.php" class="btn btn-ghost">✕ İptal</a>
        <?php if ($is_edit): ?>
        <a href="kantar_view.php?id=<?= $fis_id ?>" class="btn btn-ghost">Görüntüle</a>
        <a href="kantar_delete.php?id=<?= $fis_id ?>"
           class="btn btn-danger"
           onclick="return confirm('Bu fiş kalıcı olarak silinecek. Emin misiniz?')">Sil</a>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary btn-lg">✓ <?= h($submit_label) ?></button>
    </div>
</div>
<?php endif; ?>

<?php if (!$_kal_hazir): ?>
<p class="muted mk-kal-uyari">Şoför, Malın Sahibi, Müstahsil, Bahçe ve Ürün Fiyatı alanları için veritabanı güncellemesi gerekli<?= is_admin() ? ': <a href="migrate.php#kantar-alanlari">migrate.php › Mal Kabul Alanları</a> kartını çalıştırın' : ' — yöneticiye bildirin' ?>.</p>
<?php endif; ?>

<!-- ══════════════ GENEL, ÜRÜN VE DEPO BİLGİLERİ ══════════════ -->
<section class="card mk-card mk-card-genel">
    <h2 class="mk-sec-title"><span aria-hidden="true">📋</span> Genel, Ürün ve Depo Bilgileri</h2>
    <div class="mk-g mk-g6">
        <label class="mk-lbl">Fiş No
            <input type="text" name="fis_no" value="<?= h($fis['fis_no'] ?? '') ?>">
        </label>
        <label class="mk-lbl">Plaka No
            <?= kf_datalist('kfPlakaDl', $_kf_plaka_hist) ?>
            <input type="text" name="plaka" list="kfPlakaDl"
                   value="<?= h($fis['plaka'] ?? '') ?>" style="text-transform:uppercase"
                   autocomplete="off">
        </label>
        <label class="mk-lbl">Şoför
            <input type="text" name="sofor" value="<?= h($fis['sofor'] ?? '') ?>" data-mkcb="sofor" placeholder="Yazarak arayın…" data-uppercase="tr"<?= empty($_kal['sofor']) ? ' disabled' : '' ?>>
        </label>
        <label class="mk-lbl">Firma Adı <span class="req">*</span>
            <input type="text" name="firma_adi" value="<?= h($fis['firma_adi'] ?? '') ?>" data-mkcb="firma" placeholder="Yazarak arayın…" data-uppercase="tr">
        </label>
        <label class="mk-lbl">Tarih <span class="req">*</span>
            <input type="date" name="giris_tarih" value="<?= h(substr((string)($fis['giris_tarih'] ?? ''), 0, 10)) ?>">
        </label>
        <label class="mk-lbl">Depo <span class="req">*</span>
            <input type="text" name="depo" value="<?= h($fis['depo'] ?? '') ?>" data-mkcb="depo" data-mkcb-ekle="0" placeholder="Yazarak arayın…">
        </label>

        <label class="mk-lbl">Ürün <span class="req">*</span>
            <input type="text" name="malin_cinsi" value="<?= h($fis['malin_cinsi'] ?? '') ?>" data-mkcb="urun" placeholder="Yazarak arayın…" data-uppercase="tr">
        </label>
        <label class="mk-lbl">Malın Sahibi
            <input type="text" name="mal_sahibi" value="<?= h($fis['mal_sahibi'] ?? '') ?>" data-mkcb="mal_sahibi" placeholder="Yazarak arayın…" data-uppercase="tr"<?= empty($_kal['mal_sahibi']) ? ' disabled' : '' ?>>
        </label>
        <label class="mk-lbl">Müstahsil
            <input type="text" name="mustahsil" value="<?= h($fis['mustahsil'] ?? '') ?>" data-mkcb="mustahsil" placeholder="Yazarak arayın…" data-uppercase="tr"<?= empty($_kal['mustahsil']) ? ' disabled' : '' ?>>
        </label>
        <label class="mk-lbl">Bahçe
            <input type="text" name="bahce" value="<?= h($fis['bahce'] ?? '') ?>" data-mkcb="bahce" placeholder="Yazarak arayın…" data-uppercase="tr"<?= empty($_kal['bahce']) ? ' disabled' : '' ?>>
        </label>
        <label class="mk-lbl">Geldiği Yer
            <input type="text" name="geldigi_yer" value="<?= h($fis['geldigi_yer'] ?? '') ?>" data-mkcb="lokasyon" placeholder="Yazarak arayın…" data-uppercase="tr">
        </label>
        <label class="mk-lbl">Açıklama
            <textarea name="aciklama" rows="1" class="mk-aciklama mk-aciklama-compact" placeholder="Açıklama, not..."><?= h($fis['aciklama'] ?? '') ?></textarea>
        </label>
    </div>
</section>

<!-- ══════════════ AMBALAJ + TARTIM & ÜRÜN FİYATI + FİŞ GÖRSELİ ══════════════ -->
<div class="mk-row-at">
    <!-- Ambalaj Bilgileri -->
    <section class="card mk-card mk-card-ambalaj">
        <h2 class="mk-sec-title"><span aria-hidden="true">📦</span> Ambalaj Bilgileri</h2>
        <div class="mk-ambalaj mk-ambalaj-stack">
            <!-- Palet Türleri -->
            <div class="kp-section">
                <div class="kp-section-head">
                    <span class="kp-section-title">Palet Türleri</span>
                    <button type="button" id="addPaletRowBtn" class="btn btn-sm mk-btn-add">+ Palet Ekle</button>
                </div>
                <div id="paletRowList" class="kp-row-list"></div>
                <div class="kp-total-row">Palet Dara Toplamı: <strong id="paletDaraTotal">—</strong></div>
            </div>
            <!-- Kasa Türleri -->
            <div class="kp-section">
                <div class="kp-section-head">
                    <span class="kp-section-title">Kasa Türleri</span>
                    <button type="button" id="addKasaRowBtn" class="btn btn-sm mk-btn-add">+ Kasa Ekle</button>
                </div>
                <div id="kasaRowList" class="kp-row-list"></div>
                <div class="kp-total-row">Kasa Dara Toplamı: <strong id="kasaDaraTotal">—</strong></div>
            </div>
        </div>
    </section>

    <!-- Tartım Bilgileri + Ürün Fiyatı -->
    <div class="mk-col-tartim">
        <section class="card mk-card mk-card-tartim">
            <h2 class="mk-sec-title mk-sec-title--tartim"><span aria-hidden="true">⚖️</span> Tartım Bilgileri</h2>
            <div class="kantar-tartim-wrap">
                <div class="kantar-tartim-row">
                    <div class="kantar-tartim-no">1. Tartım <span class="req">*</span></div>
                    <div class="kantar-tartim-fields">
                        <span class="mk-ico" aria-hidden="true">🚚</span>
                        <input type="text" name="tartim1" id="tartim1" inputmode="decimal"
                               class="num kantar-tartim-input"
                               value="<?= h(fmt_tartim($fis['tartim1'] ?? '')) ?>" placeholder="">
                        <span class="kantar-tartim-unit">kg</span>
                    </div>
                </div>
                <div class="kantar-tartim-row">
                    <div class="kantar-tartim-no">2. Tartım <span class="req">*</span></div>
                    <div class="kantar-tartim-fields">
                        <span class="mk-ico" aria-hidden="true">🚚</span>
                        <input type="text" name="tartim2" id="tartim2" inputmode="decimal"
                               class="num kantar-tartim-input"
                               value="<?= h(fmt_tartim($fis['tartim2'] ?? '')) ?>" placeholder="">
                        <span class="kantar-tartim-unit">kg</span>
                    </div>
                </div>
                <div class="kantar-net-satir">
                    <span class="kantar-net-lbl">Tartım Net</span>
                    <span class="kantar-net-val" id="fisNetKg">—</span>
                </div>
            </div>

            <!-- Brüt / Dara / Net hesap özeti (otomatik güncellenir) -->
            <div id="kantarNetHesap" class="kantar-net-hesap" style="display:none">
                <div class="knh-row"><span>Brüt (Tartım Neti)</span><strong id="knhBrut">—</strong></div>
                <div class="knh-row"><span>Dara (Kasa + Palet)</span><strong id="knhDara">—</strong></div>
                <div class="knh-row knh-net-row"><span>Net KG</span><strong id="knhNet">—</strong></div>
            </div>
        </section>

        <section class="card mk-card mk-fiyat-card" id="urunFiyatiCard">
            <h2 class="mk-sec-title"><span aria-hidden="true">💰</span> Ürün Fiyatı</h2>
            <div class="mk-fiyat-grid">
                <label class="mk-lbl">Birim Fiyat (₺/kg)
                    <input type="number" name="urun_fiyati" id="urunFiyati" step="0.01" min="0" inputmode="decimal" placeholder="0,00" value="<?= h($urun_fiyati_val) ?>">
                </label>
                <div class="mk-fiyat-summary">
                    <span class="mk-fiyat-summary-lbl">Toplam Tutar</span>
                    <strong id="urunToplamTutar" class="mk-fiyat-summary-val">—</strong>
                    <small class="mk-fiyat-summary-hint">Net kg × Birim Fiyat</small>
                </div>
            </div>
            <?php if (empty($_kal['urun_fiyati'])): ?>
            <div class="mk-fiyat-hint">Kaydetmek için migrate.php › Mal Kabul Alanları çalıştırın.</div>
            <?php endif; ?>
        </section>
    </div>

    <!-- Fiş Görseli (Ambalaj + Tartım & Ürün Fiyatı sağında) -->
    <section class="card mk-card mk-card-gorsel mk-card-gorsel-side">
        <h2 class="mk-sec-title"><span aria-hidden="true">🖼</span> Fiş Görseli</h2>
        <div class="kantar-img-area" id="kantarImgArea" role="button" tabindex="0">
            <img id="kantarImgDisplay" class="kantar-img-display" alt="" style="display:none">
            <div id="kantarImgPh" class="kantar-img-ph">
                <span class="kantar-img-ph-ico" aria-hidden="true">⬆️</span>
                Fotoğraf veya belge eklemek için tıklayın ya da sürükleyip bırakın
                <small>JPG, PNG (Maks. 10 MB)</small>
            </div>
        </div>
        <button type="button" id="kantarImgKaldir" class="btn btn-danger btn-sm" style="display:none">✕ Görseli Kaldır</button>
    </section>
</div>

<!-- ══════════════ GRUPLANDIRMA ══════════════ -->
<div class="mk-grup-row">
    <div class="grup-toggle-bar">
        <button type="button" id="grupToggleBtn" class="btn btn-ghost btn-sm">🗂 Gruplandırma Ekle</button>
    </div>

    <section class="card" id="grupSection"<?= empty($_grup_list) ? ' style="display:none"' : '' ?>>
        <div class="card-head">
            <h2>🗂 Gruplandırma</h2>
            <button type="button" id="addGrupBtn" class="btn btn-sm btn-primary">+ Grup Ekle</button>
        </div>
        <div class="card-body">
            <p class="muted" style="margin-bottom:10px;font-size:.85rem">
                Her firma/grup için palet ve kasa adetini girin. Brüt, toplam palet oranına göre dağıtılır.
            </p>
            <div id="grupFormList"></div>
            <div id="grupSumRow" class="grup-sum-row" style="display:none">
                <span class="grup-sum-lbl">Toplam</span>
                <div class="grup-calc-item"><span class="grup-calc-lbl">Brüt</span><strong class="gc-brut grup-calc-val">—</strong></div>
                <span class="grup-calc-op">−</span>
                <div class="grup-calc-item"><span class="grup-calc-lbl">Dara</span><strong class="gc-dara grup-calc-val">—</strong></div>
                <span class="grup-calc-op">=</span>
                <div class="grup-calc-item"><span class="grup-calc-lbl">Net KG</span><strong class="gc-net grup-calc-val grup-calc-net-val">—</strong></div>
            </div>
            <div id="grupSapmaRow" class="grup-sapma-row" style="display:none"></div>
        </div>
    </section>
</div>
</div><!-- /.mk-form-scroll -->

<script id="mkComboData" type="application/json"><?= json_encode([
    'firma' => array_values($_all_firma), 'urun' => array_values($_urun_names),
    'lokasyon' => array_values($_lokasyon_names), 'depo' => array_values($_depo_names_kf),
    'sofor' => $_kal_liste['sofor'], 'mal_sahibi' => $_kal_liste['mal_sahibi'], 'mustahsil' => $_kal_liste['mustahsil'], 'bahce' => $_kal_liste['bahce'],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script id="firmaListData" type="application/json"><?= json_encode(
    array_values($_all_firma), JSON_UNESCAPED_UNICODE) ?></script>
<script id="grupInitData" type="application/json"><?= json_encode(
    array_values($_grup_list), JSON_UNESCAPED_UNICODE) ?></script>
<script id="kasaListData"  type="application/json"><?= json_encode(array_map(fn($k) => ['name' => $k['name'], 'dara' => (float)$k['unit_dara_kg']], $kasa_list),  JSON_UNESCAPED_UNICODE) ?></script>
<script id="paletListData" type="application/json"><?= json_encode(array_map(fn($k) => ['name' => $k['name'], 'dara' => (float)$k['unit_dara_kg']], $palet_list), JSON_UNESCAPED_UNICODE) ?></script>
<script id="kpInitData"    type="application/json"><?= json_encode(array_values($_kp_rows), JSON_UNESCAPED_UNICODE) ?></script>

<input type="hidden" name="foto_data" id="kantarFotoDataInput" value="">

<div class="form-foot mk-foot">
    <div class="mk-foot-left">
        <label class="btn">
            📷 Kamera ile Çek
            <input type="file" id="kantarImgInput" accept="image/*" capture="environment" style="display:none">
        </label>
        <label class="btn">
            🖼 Galeriden Seç
            <input type="file" id="kantarImgInputGallery" accept="image/*" style="display:none">
        </label>
    </div>
    <div class="mk-foot-right">
        <?php if ($is_window): ?>
        <button type="button" class="btn btn-ghost" data-mk-win-close>✕ İptal</button>
        <?php else: ?>
        <a href="kantar.php" class="btn btn-ghost">✕ İptal</a>
        <?php endif; ?>
        <?php if ($is_edit): ?>
        <a href="kantar_delete.php?id=<?= $fis_id ?>"
           class="btn btn-danger"
           onclick="return confirm('Bu fiş kalıcı olarak silinecek. Emin misiniz?')">Sil</a>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary btn-lg">✓ <?= h($submit_label) ?></button>
    </div>
</div>
</form>

<!-- ══════════════ PALET/KASA HESAPLAMA MODAL ══════════════ -->
<div id="kantarModal" class="pm-overlay" style="display:none" role="dialog" aria-modal="true">
  <div class="pm-dialog" style="max-width:680px">
    <div class="pm-header">
      <h2 class="pm-title">Palet / Kasa Kilo Hesabı</h2>
      <button type="button" class="pm-close" id="kantarModalKapat" aria-label="Kapat">✕</button>
    </div>
    <div class="pm-body">
      <!-- Genel bilgiler -->
      <div class="kh-section">
        <div class="kh-section-title">Genel Bilgiler</div>
        <div class="grid">
          <label>Toplam Brüt KG
            <input type="text" id="mToplamBrut" inputmode="decimal" class="num" placeholder="17.240">
          </label>
          <label>Toplam Palet Sayısı
            <input type="text" id="mToplamPalet" inputmode="numeric" class="num" placeholder="16">
          </label>
          <label>Kasa Darası <small class="muted">(kg/kasa)</small>
            <input type="text" id="mKasaDara" inputmode="decimal" class="num" placeholder="2">
          </label>
          <label>Palet Darası <small class="muted">(kg/palet)</small>
            <input type="text" id="mPaletDara" inputmode="decimal" class="num" placeholder="30">
          </label>
        </div>
      </div>
      <!-- Gruplar -->
      <div class="kh-section">
        <div class="kh-gruplar-header">
          <div class="kh-section-title" style="margin-bottom:0">Gruplar</div>
          <button type="button" class="btn btn-sm btn-primary" id="mAddGrupBtn">+ Grup Ekle</button>
        </div>
        <div id="mGrupList" style="margin-top:10px"></div>
        <p id="mPaletUyari" class="kantar-uyari" style="display:none"></p>
      </div>
      <!-- Sonuç -->
      <div id="mSonucAlani" style="display:none"></div>
    </div>
    <div class="pm-footer" id="kantarModalFooter">
      <button type="button" class="btn btn-ghost" id="kantarModalKapat2">Kapat</button>
      <button type="button" class="btn btn-primary" id="mHesaplaBtn">Hesapla</button>
    </div>
    <div class="pm-footer" id="kantarPrintFooter" style="display:none">
      <button type="button" class="btn btn-ghost" id="kantarModalKapat3">Kapat</button>
      <button type="button" class="btn" id="mYenidenBtn">← Yeniden Hesapla</button>
      <button type="button" class="btn btn-success" id="mIsleBtn">✓ Mal Kabul Fişine İşle</button>
      <button type="button" class="btn btn-primary" id="mRaporYazdir">🖨 Raporu Yazdır</button>
    </div>
  </div>
</div>

<!-- ══════════════ CROP OVERLAY ══════════════ -->
<div id="kantarCropOverlay" style="display:none">
  <div class="crop-img-area">
    <div class="crop-img-wrap" id="kantarCropWrap">
      <img id="kantarCropSrc" class="crop-src-img" alt="">
      <div id="kantarCropBox" class="crop-box">
        <div class="crop-handle" data-c="tl"></div>
        <div class="crop-handle" data-c="tr"></div>
        <div class="crop-handle" data-c="bl"></div>
        <div class="crop-handle" data-c="br"></div>
      </div>
    </div>
  </div>
  <div class="crop-footer">
    <button id="kantarCropOk"  class="crop-btn crop-btn-ok">✓ Onayla</button>
    <button id="kantarCropNo"  class="crop-btn crop-btn-no">İptal</button>
  </div>
</div>

<script src="assets/kantar-combo.js?v=<?= (int)@filemtime(__DIR__ . '/assets/kantar-combo.js') ?>"></script>
<script>
(function () {

/* ─── Yardımcılar ─── */
function parseNum(s) {
    if (s == null || s === '') return 0;
    s = String(s).trim().replace(/[\s ]/g, '');
    if (!s) return 0;
    if (s.indexOf(',') >= 0) {
        // Turkish: comma = decimal separator, dots are thousands
        s = s.replace(/\./g, '').replace(',', '.');
    } else if (s.indexOf('.') >= 0) {
        var pts = s.split('.');
        if (pts.length > 2) {
            // Multiple dots → all thousands separators
            s = s.replace(/\./g, '');
        } else if (pts[1] && pts[1].length === 3) {
            // Single dot + exactly 3 digits → thousands separator ("13.960" → 13960)
            s = s.replace('.', '');
        }
        // else: decimal dot ("0.48", "13960.5") → leave as-is
    }
    var n = parseFloat(s);
    return isNaN(n) ? 0 : n;
}
function fmt(n) {
    var s = (Math.round(n * 1000) / 1000).toFixed(3).replace(/\.?0+$/, '');
    var parts = s.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return parts.length > 1 ? parts[0] + ',' + parts[1] : parts[0];
}
/* Input alanları için: ondalık virgül, binlik ayırıcı YOK ("13960,5", "0,48", "13960") */
function fmtInput(n) {
    if (!n) return '';
    var s = (Math.round(n * 1000) / 1000).toFixed(3).replace(/\.?0+$/, '');
    return s.replace('.', ',');
}
/* Gruplandırma için tam sayıya yuvarlama (≥0,5 → 1 yukarı) */
function fmtRound(n) {
    return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}
function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ─── Firma listesi (SELECT için) ─── */
var _firmaList = JSON.parse((document.getElementById('firmaListData') || {}).textContent || '[]');
function _buildFirmaSelect(fieldName, storedVal) {
    var sel = '<select name="' + fieldName + '" class="grup-ad">';
    sel += '<option value="">— firma seç —</option>';
    var found = false;
    _firmaList.forEach(function(f) {
        var isMatch = storedVal && storedVal.trim().toLowerCase() === f.toLowerCase();
        if (isMatch) found = true;
        sel += '<option value="' + esc(f) + '"' + (isMatch ? ' selected' : '') + '>' + esc(f) + '</option>';
    });
    if (storedVal && storedVal.trim() !== '' && !found) {
        sel += '<option value="' + esc(storedVal) + '" selected>' + esc(storedVal) + ' (tanımsız)</option>';
    }
    sel += '</select>';
    return sel;
}

/* ─── Gruplandırma (canlı hesap) ─── */
var _grupSay     = 0;
var _grupSection = document.getElementById('grupSection');
var _grupToggle  = document.getElementById('grupToggleBtn');
var _grupList    = document.getElementById('grupFormList');

var _effKasaDaraU  = 0;
var _effPaletDaraU = 0;
function _getKasaDaraUnit()  { return _effKasaDaraU; }
function _getPaletDaraUnit() { return _effPaletDaraU; }
function _getBrut() {
    var t1 = parseNum(document.getElementById('tartim1').value);
    var t2 = parseNum(document.getElementById('tartim2').value);
    return Math.max(0, t1 - t2);
}

function _updateGrupCalc() {
    if (!_grupList) return;
    var tartimNet = _getBrut();           // tartım1 − tartım2
    var rows = _grupList.querySelectorAll('.grup-form-row');

    // İki geçiş: önce manuel brütleri ve oto kasaları topla
    var manualBrutSum = 0, autoKasaSum = 0;
    rows.forEach(function(r) {
        var mb = parseNum(r.querySelector('.grup-brut').value);
        if (mb > 0) { manualBrutSum += mb; }
        else        { autoKasaSum  += parseNum(r.querySelector('.grup-kasa').value); }
    });
    var autoBrutPool = Math.max(0, tartimNet - manualBrutSum);
    var brutPerKasa  = autoKasaSum > 0 ? autoBrutPool / autoKasaSum : 0;

    var totBrut = 0, totDara = 0, totNet = 0;
    rows.forEach(function(r) {
        var palet      = parseNum(r.querySelector('.grup-palet').value);
        var kasa       = parseNum(r.querySelector('.grup-kasa').value);
        var manualBrut = parseNum(r.querySelector('.grup-brut').value);
        var ozelCb     = r.querySelector('.grup-ozel-cb');
        var isOzel     = ozelCb && ozelCb.checked;
        var kasaDaraU  = isOzel ? parseNum(r.querySelector('.grup-kasa-dara').value) : _getKasaDaraUnit();
        var paletDaraU = isOzel ? parseNum(r.querySelector('.grup-palet-dara').value) : _getPaletDaraUnit();
        var isManual   = manualBrut > 0;
        var brut       = isManual ? manualBrut : (kasa * brutPerKasa);
        var dara       = palet * paletDaraU + kasa * kasaDaraU;
        var net        = Math.max(0, brut - dara);
        totBrut += brut; totDara += dara; totNet += net;
        var brutEl = r.querySelector('.gc-brut');
        brutEl.textContent = fmtRound(brut) + ' kg';
        brutEl.style.color = isManual ? 'var(--primary,#1d6cf0)' : '';
        r.querySelector('.gc-dara').textContent = fmtRound(dara) + ' kg';
        r.querySelector('.gc-net').textContent  = fmtRound(net)  + ' kg';
    });

    var sumEl = document.getElementById('grupSumRow');
    if (sumEl && rows.length > 1) {
        sumEl.style.display = '';
        sumEl.querySelector('.gc-brut').textContent = fmtRound(totBrut) + ' kg';
        sumEl.querySelector('.gc-dara').textContent = fmtRound(totDara) + ' kg';
        sumEl.querySelector('.gc-net').textContent  = fmtRound(totNet)  + ' kg';
    } else if (sumEl) {
        sumEl.style.display = 'none';
    }

    // Sapma: toplam brüt vs tartım neti
    var sapmaEl = document.getElementById('grupSapmaRow');
    if (sapmaEl && rows.length > 0 && tartimNet > 0) {
        var sapma = Math.round(totBrut) - Math.round(tartimNet);
        if (Math.abs(sapma) >= 1) {
            var sign = sapma > 0 ? '+' : '';
            var cls  = sapma > 0 ? 'sapma-fazla' : 'sapma-eksik';
            sapmaEl.style.display = '';
            sapmaEl.innerHTML =
                '<span class="grup-sapma-lbl">Tartım Neti: <strong>' + fmt(tartimNet) + ' kg</strong></span>' +
                '<span class="grup-sapma-sep">·</span>' +
                '<span class="grup-sapma-lbl">Toplam Brüt: <strong>' + fmtRound(totBrut) + ' kg</strong></span>' +
                '<span class="grup-sapma-sep">·</span>' +
                '<span class="grup-sapma-badge ' + cls + '">' + sign + sapma + ' kg sapma</span>';
        } else {
            sapmaEl.style.display = 'none';
        }
    } else if (sapmaEl) {
        sapmaEl.style.display = 'none';
    }
}

function _addGrupRow(ad, palet, kasa, kasaDara, paletDara, brutKg) {
    _grupSay++;
    var n = _grupSay;
    var isOzel = (parseNum(kasaDara) > 0 || parseNum(paletDara) > 0);
    var brutVal = brutKg != null ? parseNum(brutKg) : 0;
    var adHtml = _firmaList.length
        ? _buildFirmaSelect('gruplar[' + n + '][grup_adi]', ad || '')
        : '<input type="text" name="gruplar[' + n + '][grup_adi]" class="grup-ad" placeholder="Firma / grup adı" value="' + esc(ad || '') + '">';
    var row = document.createElement('div');
    row.className = 'grup-form-row';
    row.innerHTML =
        '<div class="grup-row-inputs">' +
            adHtml +
            '<div class="grup-row-numfields">' +
                '<label>Palet<input type="text" inputmode="numeric" name="gruplar[' + n + '][palet_sayisi]" class="num grup-palet" placeholder="0" value="' + (palet != null ? palet : '') + '"></label>' +
                '<label>Kasa<input type="text" inputmode="numeric" name="gruplar[' + n + '][kasa_adedi]" class="num grup-kasa" placeholder="0" value="' + (kasa != null ? kasa : '') + '"></label>' +
                '<label class="grup-brut-lbl" title="Boş bırakılırsa toplam tartım netinden otomatik hesaplanır">Brüt<input type="text" inputmode="decimal" name="gruplar[' + n + '][brut_kg]" class="num grup-brut" placeholder="Otomatik" value="' + (brutVal > 0 ? fmtInput(brutVal) : '') + '"></label>' +
                '<label class="grup-ozel-lbl" title="Bu grup için farklı kasa/palet darası kullan">' +
                    '<input type="checkbox" class="grup-ozel-cb"' + (isOzel ? ' checked' : '') + '> Özel Dara' +
                '</label>' +
                '<button type="button" class="grup-del-btn" title="Sil">✕</button>' +
            '</div>' +
        '</div>' +
        '<div class="grup-ozel-inputs"' + (isOzel ? '' : ' style="display:none"') + '>' +
            '<label>K.Dara <small>(kg/kasa)</small>' +
                '<input type="text" inputmode="decimal" name="gruplar[' + n + '][kasa_dara_kg]" class="num grup-kasa-dara" placeholder="0" value="' + (parseNum(kasaDara) > 0 ? fmtInput(parseNum(kasaDara)) : '') + '">' +
            '</label>' +
            '<label>P.Dara <small>(kg/palet)</small>' +
                '<input type="text" inputmode="decimal" name="gruplar[' + n + '][palet_dara_kg]" class="num grup-palet-dara" placeholder="0" value="' + (parseNum(paletDara) > 0 ? fmtInput(parseNum(paletDara)) : '') + '">' +
            '</label>' +
        '</div>' +
        '<div class="grup-calc-bar">' +
            '<div class="grup-calc-item"><span class="grup-calc-lbl">Brüt</span><strong class="gc-brut grup-calc-val">—</strong></div>' +
            '<span class="grup-calc-op">−</span>' +
            '<div class="grup-calc-item"><span class="grup-calc-lbl">Dara</span><strong class="gc-dara grup-calc-val">—</strong></div>' +
            '<span class="grup-calc-op">=</span>' +
            '<div class="grup-calc-item grup-calc-net-item"><span class="grup-calc-lbl">Net KG</span><strong class="gc-net grup-calc-net-val">—</strong></div>' +
        '</div>';

    var ozelDiv = row.querySelector('.grup-ozel-inputs');
    var ozelCb  = row.querySelector('.grup-ozel-cb');
    var kdInp   = row.querySelector('.grup-kasa-dara');
    var pdInp   = row.querySelector('.grup-palet-dara');

    ozelCb.addEventListener('change', function() {
        if (this.checked) {
            ozelDiv.style.display = '';
            if (!kdInp.value) { var g = _getKasaDaraUnit();  if (g > 0) kdInp.value = fmtInput(g); }
            if (!pdInp.value) { var g2 = _getPaletDaraUnit(); if (g2 > 0) pdInp.value = fmtInput(g2); }
        } else {
            ozelDiv.style.display = 'none';
            kdInp.value = ''; pdInp.value = '';
        }
        _updateGrupCalc();
    });

    row.querySelector('.grup-del-btn').addEventListener('click', function() {
        row.remove();
        _updateGrupCalc();
        if (!_grupList.querySelectorAll('.grup-form-row').length) {
            _grupSection.style.display = 'none';
            _grupToggle.textContent = '🗂 Gruplandırma Ekle';
        }
    });
    row.querySelector('.grup-palet').addEventListener('input', _updateGrupCalc);
    row.querySelector('.grup-kasa').addEventListener('input', _updateGrupCalc);
    row.querySelector('.grup-brut').addEventListener('input', _updateGrupCalc);
    kdInp.addEventListener('input', _updateGrupCalc);
    pdInp.addEventListener('input', _updateGrupCalc);
    _grupList.appendChild(row);
    _updateGrupCalc();
}

if (_grupToggle) {
    _grupToggle.addEventListener('click', function() {
        var open = _grupSection.style.display !== 'none';
        if (!open) {
            _grupSection.style.display = '';
            _grupToggle.textContent = '🗂 Gruplandırmayı Kaldır';
            if (!_grupList.querySelectorAll('.grup-form-row').length) { _addGrupRow(); }
        } else {
            if (_grupList.querySelectorAll('.grup-form-row').length && !confirm('Grupları kaldırmak istediğinizden emin misiniz?')) return;
            _grupList.innerHTML = '';
            _grupSection.style.display = 'none';
            _grupToggle.textContent = '🗂 Gruplandırma Ekle';
        }
    });
}
document.getElementById('addGrupBtn')?.addEventListener('click', function() { _addGrupRow(); });

// Tartım değişince grupları da güncelle
['tartim1','tartim2'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', _updateGrupCalc);
});

// Edit modunda DB'den gelen grupları yükle
(function() {
    var init = JSON.parse((document.getElementById('grupInitData') || {}).textContent || '[]');
    if (init.length) {
        _grupSection.style.display = '';
        _grupToggle.textContent = '🗂 Gruplandırmayı Kaldır';
        init.forEach(function(g) { _addGrupRow(g.grup_adi, g.palet_sayisi, g.kasa_adedi, g.kasa_dara_kg, g.palet_dara_kg, g.brut_kg); });
    }
})();

/* ─── NET hesapla ─── */
function calcNet() {
    var t1  = parseNum(document.getElementById('tartim1').value);
    var t2  = parseNum(document.getElementById('tartim2').value);
    var net = t1 - t2;
    var el  = document.getElementById('fisNetKg');
    if (t1 > 0 && t2 > 0 && net > 0) {
        el.textContent = fmt(net) + ' kg';
        el.className = 'kantar-net-val kantar-net-pozitif';
    } else {
        el.textContent = '—';
        el.className = 'kantar-net-val';
    }
}
document.getElementById('tartim1').addEventListener('input', function() { calcNet(); updateLiveCalc(); });
document.getElementById('tartim2').addEventListener('input', function() { calcNet(); updateLiveCalc(); });
calcNet();

/* ─── Brüt / Dara / Net canlı hesap ─── */
var kantarKasaDaraTotal  = 0;
var kantarPaletDaraTotal = 0;
function updateFiyatCalc(netKg) {
    var tutarEl  = document.getElementById('urunToplamTutar');
    var fiyatInp = document.getElementById('urunFiyati');
    if (!tutarEl || !fiyatInp) return;
    if (netKg == null) {
        var t1   = parseNum(document.getElementById('tartim1').value);
        var t2   = parseNum(document.getElementById('tartim2').value);
        var brut = Math.max(0, t1 - t2);
        var dara = kantarKasaDaraTotal + kantarPaletDaraTotal;
        netKg    = Math.max(0, brut - dara);
    }
    var fiyat = parseNum(fiyatInp.value);
    if (fiyat > 0 && netKg > 0) {
        var toplam = netKg * fiyat;
        tutarEl.textContent = toplam.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺';
    } else {
        tutarEl.textContent = '—';
    }
}
function updateLiveCalc() {
    var t1  = parseNum(document.getElementById('tartim1').value);
    var t2  = parseNum(document.getElementById('tartim2').value);
    var brut = t1 - t2;
    var dara = kantarKasaDaraTotal + kantarPaletDaraTotal;
    var net  = brut - dara;
    var calcEl = document.getElementById('kantarNetHesap');
    if (brut > 0 && dara > 0) {
        document.getElementById('knhBrut').textContent = fmt(brut) + ' kg';
        document.getElementById('knhDara').textContent = fmt(dara) + ' kg';
        document.getElementById('knhNet').textContent  = fmt(net)  + ' kg';
        calcEl.style.display = '';
    } else {
        calcEl.style.display = 'none';
    }
    updateFiyatCalc(Math.max(0, net));
}
var _urunFiyatiInp = document.getElementById('urunFiyati');
if (_urunFiyatiInp) {
    _urunFiyatiInp.addEventListener('input',  function() { updateFiyatCalc(); });
    _urunFiyatiInp.addEventListener('change', function() { updateFiyatCalc(); });
}

/* ══════════════════════════════════════════
   FİŞ GÖRSELİ + CROP
   ══════════════════════════════════════════ */
var IMG_KEY      = 'kantar_img_<?= $fis_id ?>';
var DB_FOTO_URL  = <?= ($fis_id > 0 && !empty($fis['foto_data'])) ? json_encode('kantar_foto.php?id=' . $fis_id) : 'null' ?>;
var imgArea    = document.getElementById('kantarImgArea');
var imgDisplay = document.getElementById('kantarImgDisplay');
var imgPh      = document.getElementById('kantarImgPh');
var imgInput        = document.getElementById('kantarImgInput');
var imgInputGallery = document.getElementById('kantarImgInputGallery');
var imgKaldir       = document.getElementById('kantarImgKaldir');
var fotoInput       = document.getElementById('kantarFotoDataInput');

function showPhoto(src) {
    imgDisplay.src = src;
    imgDisplay.style.display = 'block';
    imgPh.style.display = 'none';
    imgKaldir.style.display = '';
}
function clearPhoto() {
    imgDisplay.src = '';
    imgDisplay.style.display = 'none';
    imgPh.style.display = '';
    imgKaldir.style.display = 'none';
    try { localStorage.removeItem(IMG_KEY); } catch(e) {}
}

/* Görsel yükleme: edit modunda her açılışta localStorage temizle → DB'den göster */
(function () {
    <?php if (!$is_edit): ?>
    /* Yeni fiş: önceki oturumdan kalan fotoğrafı temizle */
    try { localStorage.removeItem(IMG_KEY); } catch(e) {}
    <?php else: ?>
    /* Düzenle: stale cache engellemek için localStorage'ı temizle, DB'den yükle */
    try { localStorage.removeItem(IMG_KEY); } catch(e) {}
    if (DB_FOTO_URL) showPhoto(DB_FOTO_URL);
    <?php endif; ?>
})();

var _imgBusy = false;
var _imgBusyTimer = null;

/* Kamera iptal edilince (focus dönerse ama change gelmezse) kilidi aç */
window.addEventListener('focus', function() {
    if (_imgBusy && !imgInput.disabled) {
        clearTimeout(_imgBusyTimer);
        _imgBusyTimer = setTimeout(function() { _imgBusy = false; }, 2000);
    }
});

imgArea.addEventListener('click', function(e) {
    if (e.target === imgKaldir || imgKaldir.contains(e.target)) return;
    if (_imgBusy || imgInput.disabled) return;
    _imgBusy = true;
    clearTimeout(_imgBusyTimer);
    /* 2 dakika fallback — seçici çok uzun açık kaldıysa veya blur/focus gelmezse */
    _imgBusyTimer = setTimeout(function() { _imgBusy = false; }, 120000);
    /* Alana tıklama = dosya/galeri seçici; kamera için alttaki "Kamera ile Çek" düğmesi var */
    (imgInputGallery || imgInput).click();
});
imgArea.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); imgArea.click(); }
});
/* Sürükle-bırak */
['dragenter', 'dragover'].forEach(function(ev) {
    imgArea.addEventListener(ev, function(e) { e.preventDefault(); imgArea.classList.add('is-drag'); });
});
['dragleave', 'drop'].forEach(function(ev) {
    imgArea.addEventListener(ev, function(e) { e.preventDefault(); imgArea.classList.remove('is-drag'); });
});
imgArea.addEventListener('drop', function(e) {
    var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (f) handleFileSelect(null, f);
});
imgKaldir.addEventListener('click', function(e) {
    e.stopPropagation();
    clearPhoto();
    if (fotoInput) fotoInput.value = '__clear__';
});

/* ── Crop ── */
var cropOverlay = document.getElementById('kantarCropOverlay');
var cropSrc     = document.getElementById('kantarCropSrc');
var cropBox     = document.getElementById('kantarCropBox');
var cs = { x:0, y:0, w:0, h:0 };
var MIN = 30;

function renderBox() {
    cropBox.style.left   = cs.x + 'px';
    cropBox.style.top    = cs.y + 'px';
    cropBox.style.width  = cs.w + 'px';
    cropBox.style.height = cs.h + 'px';
}
function initBox() {
    var pad = 20;
    var w = cropSrc.offsetWidth, h = cropSrc.offsetHeight;
    cs = { x: pad, y: pad, w: w - pad*2, h: h - pad*2 };
    renderBox();
}
function openCrop(dataUrl) {
    cropOverlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    cropSrc.onload = function() { requestAnimationFrame(initBox); };
    cropSrc.src = dataUrl;
}
function closeCrop() {
    cropOverlay.style.display = 'none';
    document.body.style.overflow = '';
}

var active = null, sx, sy, sc;
function xy(e) {
    var t = e.touches ? e.touches[0] : e;
    return { x: t.clientX, y: t.clientY };
}
function pointerDown(e) {
    var c = (e.target.dataset || {}).c;
    if (!c) return;
    active = c;
    var p = xy(e);
    sx = p.x; sy = p.y;
    sc = { x: cs.x, y: cs.y, w: cs.w, h: cs.h };
    e.preventDefault();
}
function pointerMove(e) {
    if (!active) return;
    var p = xy(e), iw = cropSrc.offsetWidth, ih = cropSrc.offsetHeight;
    var dx = p.x - sx, dy = p.y - sy;
    var c = { x: sc.x, y: sc.y, w: sc.w, h: sc.h };
    if (active.indexOf('l') >= 0) { var nx = Math.max(0, Math.min(c.x+c.w-MIN, c.x+dx)); c.w = c.x+c.w-nx; c.x = nx; }
    if (active.indexOf('r') >= 0) { c.w = Math.max(MIN, Math.min(iw-c.x, c.w+dx)); }
    if (active.indexOf('t') >= 0) { var ny = Math.max(0, Math.min(c.y+c.h-MIN, c.y+dy)); c.h = c.y+c.h-ny; c.y = ny; }
    if (active.indexOf('b') >= 0) { c.h = Math.max(MIN, Math.min(ih-c.y, c.h+dy)); }
    cs = c; renderBox();
    e.preventDefault();
}
function pointerUp() { active = null; }

cropBox.addEventListener('mousedown',  pointerDown);
cropBox.addEventListener('touchstart', pointerDown, { passive: false });
document.addEventListener('mousemove',  pointerMove);
document.addEventListener('mouseup',    pointerUp);
document.addEventListener('touchmove',  pointerMove, { passive: false });
document.addEventListener('touchend',   pointerUp);

document.getElementById('kantarCropOk').addEventListener('click', function() {
    var iw = cropSrc.offsetWidth,  ih = cropSrc.offsetHeight;
    var nw = cropSrc.naturalWidth, nh = cropSrc.naturalHeight;
    var ox = cs.x/iw*nw, oy = cs.y/ih*nh, ow = cs.w/iw*nw, oh = cs.h/ih*nh;
    var cv = document.createElement('canvas');
    cv.width = Math.round(ow); cv.height = Math.round(oh);
    cv.getContext('2d').drawImage(cropSrc, ox, oy, ow, oh, 0, 0, cv.width, cv.height);
    // Cap at 1600px to keep file size manageable
    var MAX_PX = 1600;
    if (cv.width > MAX_PX || cv.height > MAX_PX) {
        var ratio = Math.min(MAX_PX / cv.width, MAX_PX / cv.height);
        var cv2 = document.createElement('canvas');
        cv2.width = Math.round(cv.width * ratio);
        cv2.height = Math.round(cv.height * ratio);
        cv2.getContext('2d').drawImage(cv, 0, 0, cv2.width, cv2.height);
        cv = cv2;
    }
    var out = cv.toDataURL('image/jpeg', 0.75);
    try { localStorage.setItem(IMG_KEY, out); } catch(e) {}
    if (fotoInput) fotoInput.value = out;
    showPhoto(out);
    closeCrop();
});
document.getElementById('kantarCropNo').addEventListener('click', closeCrop);

function handleFileSelect(input, dropped) {
    clearTimeout(_imgBusyTimer);
    var file = dropped || (input && input.files[0]);
    if (!file) { _imgBusy = false; return; }
    if (!/^image\//.test(file.type || '')) { _imgBusy = false; alert('Yalnızca görsel dosyası (JPG, PNG) eklenebilir.'); return; }
    if (file.size > 10 * 1024 * 1024) { _imgBusy = false; alert('Dosya 10 MB sınırını aşıyor.'); return; }
    /* Fotoğraf seçildi — inputları devre dışı bırak; kameranın
       kapanış anındaki sahte dokunuşların kamerayı yeniden açmasını engelle */
    imgInput.disabled = true;
    if (imgInputGallery) imgInputGallery.disabled = true;
    var rd = new FileReader();
    rd.onload = function(ev) {
        if (input) input.value = '';
        _imgBusy = false;
        openCrop(ev.target.result);
        /* 1 saniye sonra inputları tekrar etkinleştir */
        setTimeout(function() {
            imgInput.disabled = false;
            if (imgInputGallery) imgInputGallery.disabled = false;
        }, 1000);
    };
    rd.onerror = function() {
        _imgBusy = false;
        imgInput.disabled = false;
        if (imgInputGallery) imgInputGallery.disabled = false;
    };
    rd.readAsDataURL(file);
}
imgInput.addEventListener('change', function() { handleFileSelect(this); });
if (imgInputGallery) imgInputGallery.addEventListener('change', function() { handleFileSelect(this); });

/* ══════════════════════════════════════════
   KASA / PALET ÇOK-SATIR
   ══════════════════════════════════════════ */
var _kpCount = 0;
var _kasaTotSayisi = 0, _paletTotSayisi = 0;
var _kasaListData  = JSON.parse((document.getElementById('kasaListData')  || {}).textContent || '[]');
var _paletListData = JSON.parse((document.getElementById('paletListData') || {}).textContent || '[]');

function _kpListe(tip) { return tip === 'kasa' ? _kasaListData : _paletListData; }
function _kpTanimDara(tip, ad) {
    var q = String(ad || '').trim().toLocaleUpperCase('tr-TR'), r = null;
    _kpListe(tip).forEach(function(x) { if (String(x.name).toLocaleUpperCase('tr-TR') === q) r = x; });
    return r ? (parseFloat(r.dara) || 0) : 0;
}

/* Satırın birim darası: kutu doluysa kullanıcının değeri, boşsa seçili cinsin tanımlı darası */
function _kpRowDara(row) {
    var inp = row.querySelector('.kp-dara');
    if (inp && inp.value.trim() !== '') return Math.max(0, parseNum(inp.value));
    var cin = row.querySelector('.kp-cinsi');
    return cin ? _kpTanimDara(row.dataset.tip, cin.value) : 0;
}

function _addKpRow(tip, cinsi, sayisi, birimDara) {
    _kpCount++;
    var n = _kpCount;
    var div = document.createElement('div');
    div.className = 'kp-row';
    div.dataset.tip = tip;
    div.innerHTML =
        '<input type="hidden" name="kp_rows[' + n + '][tip]" value="' + esc(tip) + '">' +
        '<input type="text" name="kp_rows[' + n + '][cinsi]" class="kp-cinsi" placeholder="Yazarak arayın…" value="' + esc(cinsi || '') + '">' +
        '<input type="text" inputmode="numeric" name="kp_rows[' + n + '][sayisi]" class="num kp-sayisi" placeholder="Adet" value="' + esc(sayisi != null ? String(sayisi) : '') + '">' +
        '<label class="kp-dara-lbl" title="Birim dara (kg/adet) — seçimle gelir, değiştirilebilir">Dara <input type="text" inputmode="decimal" name="kp_rows[' + n + '][birim_dara_kg]" class="num kp-dara" placeholder="0" value="' + (birimDara != null ? esc(fmtInput(birimDara)) : '') + '"> kg</label>' +
        '<span class="kp-calc">= <strong class="kp-toplam-val">0 kg</strong></span>' +
        '<button type="button" class="kp-del-btn" title="Sil">✕</button>';
    var targetList = tip === 'palet' ? document.getElementById('paletRowList') : document.getElementById('kasaRowList');
    if (!targetList) return;
    targetList.appendChild(div);
    var cinInp  = div.querySelector('.kp-cinsi');
    var sayInp  = div.querySelector('.kp-sayisi');
    var daraInp = div.querySelector('.kp-dara');
    var totEl   = div.querySelector('.kp-toplam-val');
    function updateKpRow() {
        var say = parseInt(sayInp.value, 10) || 0;
        totEl.textContent = fmtRound(say * _kpRowDara(div)) + ' kg';
        _computeKpTotals();
    }
    /* Cins seçilince tanımdaki dara kutuya gelir (yeni cinste boş kalır); kullanıcı elle değiştirebilir */
    if (window.mkCombo) mkCombo(cinInp, {
        items: function() { return _kpListe(tip); },
        canAdd: true,
        onPick: function(it) { var d = it ? (parseFloat(it.dara) || 0) : 0; daraInp.value = d > 0 ? fmtInput(d) : ''; updateKpRow(); }
    });
    sayInp.addEventListener('input', updateKpRow);
    daraInp.addEventListener('input', updateKpRow);
    /* İlk açılış: kayıtlı dara yoksa (yeni satır / eski kayıt) cinsin tanımdaki darasını göster */
    if (!daraInp.value) {
        var d0 = _kpTanimDara(tip, cinInp.value);
        if (d0 > 0) daraInp.value = fmtInput(d0);
    }
    div.querySelector('.kp-del-btn').addEventListener('click', function() { div.remove(); _computeKpTotals(); });
    updateKpRow();
}

function _computeKpTotals() {
    var kd = 0, ks = 0, pd = 0, ps = 0;
    document.querySelectorAll('#kasaRowList .kp-row').forEach(function(row) {
        var dara = _kpRowDara(row);
        var say  = parseInt(row.querySelector('.kp-sayisi').value, 10) || 0;
        kd += say * dara; ks += say;
    });
    document.querySelectorAll('#paletRowList .kp-row').forEach(function(row) {
        var dara = _kpRowDara(row);
        var say  = parseInt(row.querySelector('.kp-sayisi').value, 10) || 0;
        pd += say * dara; ps += say;
    });
    kantarKasaDaraTotal  = kd; kantarPaletDaraTotal = pd;
    _kasaTotSayisi = ks;  _paletTotSayisi = ps;
    _effKasaDaraU  = ks > 0 ? kd / ks : 0;
    _effPaletDaraU = ps > 0 ? pd / ps : 0;
    var kEl = document.getElementById('kasaDaraTotal');
    var pEl = document.getElementById('paletDaraTotal');
    if (kEl) kEl.textContent = fmtRound(kd) + ' kg';
    if (pEl) pEl.textContent = fmtRound(pd) + ' kg';
    updateLiveCalc();
    _updateGrupCalc();
}

document.getElementById('addKasaRowBtn')?.addEventListener('click',  function() { _addKpRow('kasa'); });
document.getElementById('addPaletRowBtn')?.addEventListener('click', function() { _addKpRow('palet'); });

(function() {
    var init = JSON.parse((document.getElementById('kpInitData') || {}).textContent || '[]');
    init.forEach(function(r) { _addKpRow(r.tip, r.cinsi, r.sayisi, r.birim_dara_kg); });
    if (!document.querySelectorAll('#kasaRowList  .kp-row').length) _addKpRow('kasa');
    if (!document.querySelectorAll('#paletRowList .kp-row').length) _addKpRow('palet');
    _computeKpTotals();
})();

/* ══════════════════════════════════════════
   PALET/KASA HESAPLAMA MODAL
   ══════════════════════════════════════════ */
var mGrupSay = 0;
var mLastCalc = { palet: 0, kasa: 0 };
updateLiveCalc();

function addModalGrup(ad, palet, kasa) {
    mGrupSay++;
    var no = mGrupSay;
    var div = document.createElement('div');
    div.className = 'kantar-grup-row';
    div.innerHTML =
        '<div class="kantar-grup-header">' +
            '<span class="kantar-grup-no">' + no + '. Grup</span>' +
            '<button type="button" class="btn btn-ghost btn-sm kantar-del-btn">✕ Sil</button>' +
        '</div>' +
        '<div class="kantar-grup-body">' +
            '<label class="kantar-lbl"><span>Grup Adı</span>' +
                '<input type="text" class="kantar-ad-input" value="' + esc(ad != null ? ad : '') + '" placeholder="Örn: CK">' +
            '</label>' +
            '<label class="kantar-lbl"><span>Palet Sayısı</span>' +
                '<input type="text" class="kantar-palet-input num" inputmode="numeric" value="' + esc(palet != null ? palet : '') + '" placeholder="0">' +
            '</label>' +
            '<label class="kantar-lbl"><span>Kasa Adedi</span>' +
                '<input type="text" class="kantar-kasa-input num" inputmode="numeric" value="' + esc(kasa != null ? kasa : '') + '" placeholder="0">' +
            '</label>' +
        '</div>';
    div.querySelector('.kantar-del-btn').addEventListener('click', function() { div.remove(); checkModalPalet(); });
    div.querySelector('.kantar-palet-input').addEventListener('input', checkModalPalet);
    document.getElementById('mGrupList').appendChild(div);
    checkModalPalet();
}

function checkModalPalet() {
    var tp = parseNum(document.getElementById('mToplamPalet').value);
    var el = document.getElementById('mPaletUyari');
    if (!tp) { el.style.display = 'none'; return; }
    var gt = Array.from(document.querySelectorAll('#mGrupList .kantar-palet-input'))
                  .reduce(function(s, inp) { return s + parseNum(inp.value); }, 0);
    if (gt > 0 && Math.abs(gt - tp) > 0.001) {
        el.textContent = '⚠ Grup palet toplamı (' + fmt(gt) + ') toplam palet sayısıyla (' + fmt(tp) + ') eşleşmiyor.';
        el.style.display = '';
    } else {
        el.style.display = 'none';
    }
}

document.getElementById('mToplamPalet').addEventListener('input', checkModalPalet);
document.getElementById('mAddGrupBtn').addEventListener('click', function() { addModalGrup(); });

function openHesaplaModal() {
    var t1  = parseNum(document.getElementById('tartim1').value);
    var t2  = parseNum(document.getElementById('tartim2').value);
    var net = t1 - t2;
    if (net > 0) document.getElementById('mToplamBrut').value = fmt(net);

    if (_paletTotSayisi > 0) document.getElementById('mToplamPalet').value = String(_paletTotSayisi);
    if (_effKasaDaraU  > 0) document.getElementById('mKasaDara').value  = fmt(_effKasaDaraU);
    if (_effPaletDaraU > 0) document.getElementById('mPaletDara').value = fmt(_effPaletDaraU);

    if (!document.getElementById('mGrupList').children.length) {
        addModalGrup(); addModalGrup();
    }
    document.getElementById('mSonucAlani').innerHTML = '';
    document.getElementById('mSonucAlani').style.display = 'none';
    document.getElementById('mPaletUyari').style.display = 'none';
    document.getElementById('kantarModalFooter').style.display = '';
    document.getElementById('kantarPrintFooter').style.display = 'none';
    document.getElementById('kantarModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeHesaplaModal() {
    document.getElementById('kantarModal').style.display = 'none';
    document.body.style.overflow = '';
}

document.getElementById('hesaplaAcBtn')?.addEventListener('click',  openHesaplaModal);
document.getElementById('hesaplaAcBtn2')?.addEventListener('click', openHesaplaModal);
document.getElementById('kantarModalKapat').addEventListener('click',  closeHesaplaModal);
document.getElementById('kantarModalKapat2').addEventListener('click', closeHesaplaModal);
document.getElementById('kantarModalKapat3').addEventListener('click', closeHesaplaModal);
document.getElementById('kantarModal').addEventListener('click', function(e) {
    if (e.target === this) closeHesaplaModal();
});

document.getElementById('mYenidenBtn').addEventListener('click', function() {
    document.getElementById('mSonucAlani').style.display       = 'none';
    document.getElementById('kantarModalFooter').style.display  = '';
    document.getElementById('kantarPrintFooter').style.display  = 'none';
});

document.getElementById('mHesaplaBtn').addEventListener('click', function() {
    var toplamBrut  = parseNum(document.getElementById('mToplamBrut').value);
    var toplamPalet = parseNum(document.getElementById('mToplamPalet').value);
    var kasaDara    = parseNum(document.getElementById('mKasaDara').value);
    var paletDara   = parseNum(document.getElementById('mPaletDara').value);

    if (!toplamBrut)  { alert('Lütfen toplam brüt KG değerini girin.'); return; }
    if (!toplamPalet) { alert('Lütfen toplam palet sayısını girin.'); return; }
    if (kasaDara  < 0) { alert('Kasa darası negatif olamaz.'); return; }
    if (paletDara < 0) { alert('Palet darası negatif olamaz.'); return; }

    var gruplar = Array.from(document.querySelectorAll('#mGrupList .kantar-grup-row')).map(function(r) {
        return {
            ad:    r.querySelector('.kantar-ad-input').value.trim() || '—',
            palet: parseNum(r.querySelector('.kantar-palet-input').value),
            kasa:  parseNum(r.querySelector('.kantar-kasa-input').value),
        };
    }).filter(function(g) { return g.palet > 0 || g.kasa > 0; });

    if (!gruplar.length) { alert('Lütfen en az bir grup için palet veya kasa adedi girin.'); return; }

    var paletBasiOrt = toplamBrut / toplamPalet;
    var totBrut = 0, totKDara = 0, totPDara = 0, totDara = 0, totNet = 0, totPalet = 0, totKasa = 0;
    var satirlar = '';

    gruplar.forEach(function(g) {
        var brut  = g.palet * paletBasiOrt;
        var kDara = g.kasa  * kasaDara;
        var pDara = g.palet * paletDara;
        var tdara = kDara + pDara;
        var net   = brut - tdara;
        totBrut  += brut;  totKDara += kDara; totPDara += pDara;
        totDara  += tdara; totNet   += net;
        totPalet += g.palet; totKasa += g.kasa;
        satirlar +=
            '<tr>' +
            '<td><strong>' + esc(g.ad) + '</strong></td>' +
            '<td class="num">' + fmt(g.palet)    + '</td>' +
            '<td class="num">' + fmt(g.kasa)     + '</td>' +
            '<td class="num">' + fmtRound(brut)  + '</td>' +
            '<td class="num">' + fmtRound(kDara) + '</td>' +
            '<td class="num">' + fmtRound(pDara) + '</td>' +
            '<td class="num">' + fmtRound(tdara) + '</td>' +
            '<td class="num kantar-net">' + fmtRound(net) + '</td>' +
            '</tr>';
    });

    var html =
        '<div class="kantar-ozet">' +
            '<span class="kantar-ozet-lbl">Palet başı ortalama brüt</span>' +
            '<span class="kantar-ozet-val">' + fmt(paletBasiOrt) + ' kg</span>' +
        '</div>' +
        '<div class="table-wrap" style="margin-top:10px">' +
        '<table class="data-table">' +
        '<thead><tr>' +
            '<th>Grup</th>' +
            '<th class="num">Palet</th>' +
            '<th class="num">Kasa</th>' +
            '<th class="num">Brüt KG</th>' +
            '<th class="num">K.Dara</th>' +
            '<th class="num">P.Dara</th>' +
            '<th class="num">Top.Dara</th>' +
            '<th class="num">Net KG</th>' +
        '</tr></thead>' +
        '<tbody>' + satirlar + '</tbody>' +
        '<tfoot><tr class="kantar-toplam-row">' +
            '<td><strong>TOPLAM</strong></td>' +
            '<td class="num"><strong>' + fmt(totPalet)      + '</strong></td>' +
            '<td class="num"><strong>' + fmt(totKasa)       + '</strong></td>' +
            '<td class="num"><strong>' + fmtRound(totBrut)  + '</strong></td>' +
            '<td class="num"><strong>' + fmtRound(totKDara) + '</strong></td>' +
            '<td class="num"><strong>' + fmtRound(totPDara) + '</strong></td>' +
            '<td class="num"><strong>' + fmtRound(totDara)  + '</strong></td>' +
            '<td class="num kantar-net"><strong>' + fmtRound(totNet) + '</strong></td>' +
        '</tr></tfoot>' +
        '</table></div>';

    mLastCalc = { palet: totPalet, kasa: totKasa };

    var sonuc = document.getElementById('mSonucAlani');
    sonuc.innerHTML = html;
    sonuc.style.display = '';
    document.getElementById('kantarModalFooter').style.display = 'none';
    document.getElementById('kantarPrintFooter').style.display = '';

    setTimeout(function() {
        document.querySelector('#kantarModal .pm-body').scrollTo({ top: 9999, behavior: 'smooth' });
    }, 50);
});

document.getElementById('mIsleBtn').addEventListener('click', function() {
    closeHesaplaModal();
});

document.getElementById('mRaporYazdir').addEventListener('click', function() {
    document.body.classList.add('kh-print');
    window.print();
    window.addEventListener('afterprint', function() {
        document.body.classList.remove('kh-print');
    }, { once: true });
});

<?php if ($is_window): ?>
/* ══════════════════════════════════════════
   PENCERE MODU — AJAX KAYIT
   ══════════════════════════════════════════ */
(function() {
    var form   = document.getElementById('kantarForm');
    var errBox = document.getElementById('mkWinErrors');
    if (!form) return;
    var submitting = false;

    function showWinErrors(list) {
        if (!errBox) return;
        if (!list || !list.length) {
            errBox.style.display = 'none';
            errBox.innerHTML = '';
            return;
        }
        if (list.length === 1) {
            errBox.innerHTML = esc(list[0]);
        } else {
            errBox.innerHTML = '<ul style="margin:0;padding-left:18px">' +
                list.map(function(m) { return '<li>' + esc(m) + '</li>'; }).join('') +
                '</ul>';
        }
        errBox.style.display = '';
        var scrollEl = form.querySelector('.mk-form-scroll') || form.closest('.mk-win-body');
        if (scrollEl) {
            scrollEl.scrollTop = 0;
        } else {
            errBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        if (submitting) return;
        if (typeof form.reportValidity === 'function' && !form.reportValidity()) return;

        submitting = true;
        showWinErrors([]);

        var submitBtns = Array.from(form.querySelectorAll('button[type="submit"]'));
        var origLabels = submitBtns.map(function(b) { return b.innerHTML; });
        submitBtns.forEach(function(b) {
            b.disabled = true;
            b.textContent = 'Kaydediliyor…';
        });

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(function(res) {
            return res.json().catch(function() {
                return { ok: false, errors: ['Sunucu yanıtı okunamadı (HTTP ' + res.status + ').'] };
            });
        })
        .then(function(data) {
            if (data && data.ok) {
                window.dispatchEvent(new CustomEvent('mk-win-saved', { detail: data }));
            } else {
                var errs = [];
                if (data && Array.isArray(data.errors) && data.errors.length) {
                    errs = data.errors;
                } else if (data && (data.error || data.message)) {
                    errs = [data.error || data.message];
                } else {
                    errs = ['Kayıt sırasında bir hata oluştu.'];
                }
                showWinErrors(errs);
            }
        })
        .catch(function() {
            showWinErrors(['Sunucuya bağlanılamadı. Lütfen tekrar deneyin.']);
        })
        .finally(function() {
            submitting = false;
            submitBtns.forEach(function(b, i) {
                b.disabled = false;
                b.innerHTML = origLabels[i];
            });
        });
    });
})();
<?php endif; ?>

})();
</script>
