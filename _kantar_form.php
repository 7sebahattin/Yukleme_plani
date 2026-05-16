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

$is_edit    = $is_edit    ?? false;
$fis        = $fis        ?? [];
$fis_id     = (int)($fis['id'] ?? 0);
$kasa_list  = $kasa_list  ?? [];
$palet_list = $palet_list ?? [];

// DB'den gelen DECIMAL değerlerini Türkçe formata çevir (parseNum ile uyumlu)
function fmt_tartim($v): string {
    $f = (float)$v;
    return $f > 0 ? fmt_kg($f) : '';
}
?>
<form method="post" action="<?= h($form_action) ?>" id="kantarForm">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
<?php if ($is_edit): ?>
<input type="hidden" name="id" value="<?= $fis_id ?>">
<?php endif; ?>

<div class="page-head">
    <h1><?= h($title) ?></h1>
    <div class="page-head-actions">
        <a href="kantar.php" class="btn btn-ghost">İptal</a>
        <?php if ($is_edit): ?>
        <a href="kantar_view.php?id=<?= $fis_id ?>" class="btn btn-ghost">Görüntüle</a>
        <?php endif; ?>
        <button type="button" class="btn" id="hesaplaAcBtn">Palet / Kasa Hesabı</button>
        <?php if ($is_edit): ?>
        <a href="kantar_delete.php?id=<?= $fis_id ?>"
           class="btn btn-danger"
           onclick="return confirm('Bu fiş kalıcı olarak silinecek. Emin misiniz?')">Sil</a>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary btn-lg"><?= h($submit_label) ?></button>
    </div>
</div>

<!-- ══════════════ FİŞ BİLGİLERİ ══════════════ -->
<section class="card">
    <div class="card-head"><h2>⚖️ Kantar Fişi</h2></div>
    <div class="card-body">
        <div class="grid">
            <label>Fiş No
                <input type="text" name="fis_no" value="<?= h($fis['fis_no'] ?? '') ?>">
            </label>
            <label>Plaka No
                <input type="text" name="plaka" value="<?= h($fis['plaka'] ?? '') ?>"
                       style="text-transform:uppercase">
            </label>
            <label>Firma Adı
                <input type="text" name="firma_adi" value="<?= h($fis['firma_adi'] ?? '') ?>">
            </label>
            <label>Operatör
                <input type="text" name="operator_adi" value="<?= h($fis['operator_adi'] ?? '') ?>">
            </label>
            <label>Giriş Tarih / Saat
                <input type="datetime-local" name="giris_tarih" value="<?= h($fis['giris_tarih'] ?? '') ?>">
            </label>
            <label>Çıkış Tarih / Saat
                <input type="datetime-local" name="cikis_tarih" value="<?= h($fis['cikis_tarih'] ?? '') ?>">
            </label>
            <label>Malın Cinsi
                <input type="text" name="malin_cinsi" value="<?= h($fis['malin_cinsi'] ?? '') ?>">
            </label>
            <label>Geldiği Yer
                <input type="text" name="geldigi_yer" value="<?= h($fis['geldigi_yer'] ?? '') ?>">
            </label>
            <label>Gittiği Yer
                <input type="text" name="gittigi_yer" value="<?= h($fis['gittigi_yer'] ?? '') ?>">
            </label>
            <label>Palet Sayısı
                <input type="text" inputmode="numeric" name="palet_sayisi" id="kantarPaletSayisi"
                       class="num" value="<?= h($fis['palet_sayisi'] ?? '') ?>" placeholder="Palet">
            </label>
            <label>Palet Cinsi
                <select name="palet_cinsi" id="kantarPaletCinsi">
                    <option value="">— Seçin —</option>
                    <?php foreach ($palet_list as $kd): ?>
                    <option value="<?= h($kd['name']) ?>"
                            data-dara="<?= (float)$kd['unit_dara_kg'] ?>"
                            <?= ($fis['palet_cinsi'] ?? '') === $kd['name'] ? 'selected' : '' ?>>
                        <?= h($kd['name']) ?><?= $kd['unit_dara_kg'] > 0 ? ' (' . fmt_kg($kd['unit_dara_kg']) . ' kg)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Kasa Sayısı
                <input type="text" inputmode="numeric" name="kasa_sayisi" id="kantarKasaSayisi"
                       class="num" value="<?= h($fis['kasa_sayisi'] ?? '') ?>" placeholder="Kasa">
            </label>
            <label>Kasa Cinsi
                <select name="kasa_cinsi" id="kantarKasaCinsi">
                    <option value="">— Seçin —</option>
                    <?php foreach ($kasa_list as $kd): ?>
                    <option value="<?= h($kd['name']) ?>"
                            data-dara="<?= (float)$kd['unit_dara_kg'] ?>"
                            <?= ($fis['kasa_cinsi'] ?? '') === $kd['name'] ? 'selected' : '' ?>>
                        <?= h($kd['name']) ?><?= $kd['unit_dara_kg'] > 0 ? ' (' . fmt_kg($kd['unit_dara_kg']) . ' kg)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="span-2">Açıklama
                <input type="text" name="aciklama" value="<?= h($fis['aciklama'] ?? '') ?>">
            </label>
        </div>
    </div>
</section>

<!-- ══════════════ TARTIMLAR ══════════════ -->
<section class="card">
    <div class="card-head"><h2>Tartımlar</h2></div>
    <div class="card-body">
        <div class="kantar-tartim-wrap">
            <div class="kantar-tartim-row">
                <div class="kantar-tartim-no">1. Tartım</div>
                <div class="kantar-tartim-fields">
                    <input type="text" name="tartim1" id="tartim1" inputmode="decimal"
                           class="num kantar-tartim-input"
                           value="<?= h(fmt_tartim($fis['tartim1'] ?? '')) ?>" placeholder="">
                    <span class="kantar-tartim-unit">kg</span>
                    <input type="text" name="alibi1" class="kantar-alibi"
                           placeholder="Alibi No"
                           value="<?= h($fis['alibi1'] ?? '') ?>">
                </div>
            </div>
            <div class="kantar-tartim-row">
                <div class="kantar-tartim-no">2. Tartım</div>
                <div class="kantar-tartim-fields">
                    <input type="text" name="tartim2" id="tartim2" inputmode="decimal"
                           class="num kantar-tartim-input"
                           value="<?= h(fmt_tartim($fis['tartim2'] ?? '')) ?>" placeholder="">
                    <span class="kantar-tartim-unit">kg</span>
                    <input type="text" name="alibi2" class="kantar-alibi"
                           placeholder="Alibi No"
                           value="<?= h($fis['alibi2'] ?? '') ?>">
                </div>
            </div>
            <div class="kantar-net-satir">
                <span class="kantar-net-lbl">NET</span>
                <span class="kantar-net-val" id="fisNetKg">—</span>
            </div>
        </div>

        <!-- Brüt / Dara / Net hesap özeti (otomatik güncellenir) -->
        <div id="kantarNetHesap" class="kantar-net-hesap" style="display:none">
            <div class="knh-row"><span>Brüt (Tartım Neti)</span><strong id="knhBrut">—</strong></div>
            <div class="knh-row"><span>Dara (Kasa + Palet)</span><strong id="knhDara">—</strong></div>
            <div class="knh-row knh-net-row"><span>Net KG</span><strong id="knhNet">—</strong></div>
        </div>
    </div>
</section>

<!-- ══════════════ FİŞ GÖRSELİ ══════════════ -->
<section class="card">
    <div class="card-head"><h2>📷 Fiş Görseli</h2></div>
    <div class="card-body">
        <div class="kantar-img-area" id="kantarImgArea">
            <img id="kantarImgDisplay" class="kantar-img-display" alt="" style="display:none">
            <div id="kantarImgPh" class="kantar-img-ph">
                📷<br>Fotoğraf veya belge eklemek için tıklayın
            </div>
        </div>
        <div class="kantar-img-actions">
            <label class="btn">
                📷 Kamera
                <input type="file" id="kantarImgInput" accept="image/*" capture="environment" style="display:none">
            </label>
            <label class="btn">
                🖼 Galeriden Seç
                <input type="file" id="kantarImgInputGallery" accept="image/*" style="display:none">
            </label>
            <button type="button" id="kantarImgKaldir" class="btn btn-danger" style="display:none">✕ Kaldır</button>
        </div>
    </div>
</section>
<input type="hidden" name="foto_data" id="kantarFotoDataInput" value="">

<div class="form-foot">
    <a href="kantar.php" class="btn btn-ghost">İptal</a>
    <button type="button" class="btn" id="hesaplaAcBtn2">Palet / Kasa Hesabı</button>
    <?php if ($is_edit): ?>
    <a href="kantar_delete.php?id=<?= $fis_id ?>"
       class="btn btn-danger"
       onclick="return confirm('Bu fiş kalıcı olarak silinecek. Emin misiniz?')">Sil</a>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary btn-lg"><?= h($submit_label) ?></button>
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
      <button type="button" class="btn btn-success" id="mIsleBtn">✓ Kantar Fişine İşle</button>
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

<script>
(function () {

/* ─── Yardımcılar ─── */
function parseNum(s) {
    s = String(s == null ? '' : s).trim().replace(/\s/g, '').replace(/\./g, '').replace(',', '.');
    var n = parseFloat(s);
    return isNaN(n) ? 0 : n;
}
function fmt(n) {
    var s = (Math.round(n * 1000) / 1000).toFixed(3).replace(/\.?0+$/, '');
    var parts = s.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return parts.length > 1 ? parts[0] + ',' + parts[1] : parts[0];
}
function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

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
function updateLiveCalc() {
    var t1  = parseNum(document.getElementById('tartim1').value);
    var t2  = parseNum(document.getElementById('tartim2').value);
    var brut = t1 - t2;
    var kasaSay  = parseNum((document.getElementById('kantarKasaSayisi')  || {}).value || 0);
    var paletSay = parseNum((document.getElementById('kantarPaletSayisi') || {}).value || 0);
    var dara = kasaSay * kantarKasaDaraKg + paletSay * kantarPaletDaraKg;
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

/* localStorage önce, yoksa DB endpoint'ten yükle */
(function () {
    try {
        var raw = localStorage.getItem(IMG_KEY);
        if (raw) {
            var src = (raw.charAt(0) === '{') ? JSON.parse(raw).src : raw;
            if (src && src.indexOf('data:') === 0) { showPhoto(src); return; }
        }
    } catch(e) {}
    if (DB_FOTO_URL) showPhoto(DB_FOTO_URL);
})();

imgArea.addEventListener('click', function(e) {
    if (e.target === imgKaldir || imgKaldir.contains(e.target)) return;
    imgInput.click();
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

function handleFileSelect(input) {
    var file = input.files[0];
    if (!file) return;
    var rd = new FileReader();
    rd.onload = function(ev) { openCrop(ev.target.result); };
    rd.readAsDataURL(file);
    input.value = '';
}
imgInput.addEventListener('change', function() { handleFileSelect(this); });
if (imgInputGallery) imgInputGallery.addEventListener('change', function() { handleFileSelect(this); });

/* ══════════════════════════════════════════
   PALET/KASA HESAPLAMA MODAL
   ══════════════════════════════════════════ */
var kantarKasaDaraKg  = 0;
var kantarPaletDaraKg = 0;
var mGrupSay = 0;
var mLastCalc = { palet: 0, kasa: 0 };

var kasaCinsiSel  = document.getElementById('kantarKasaCinsi');
var paletCinsiSel = document.getElementById('kantarPaletCinsi');

function getOptDara(sel) {
    if (!sel || sel.selectedIndex < 0) return 0;
    return parseFloat(sel.options[sel.selectedIndex].getAttribute('data-dara') || 0) || 0;
}
if (kasaCinsiSel) {
    kasaCinsiSel.addEventListener('change', function() { kantarKasaDaraKg = getOptDara(this); updateLiveCalc(); });
    kantarKasaDaraKg = getOptDara(kasaCinsiSel);
}
if (paletCinsiSel) {
    paletCinsiSel.addEventListener('change', function() { kantarPaletDaraKg = getOptDara(this); updateLiveCalc(); });
    kantarPaletDaraKg = getOptDara(paletCinsiSel);
}
var _kasaSayEl  = document.getElementById('kantarKasaSayisi');
var _paletSayEl = document.getElementById('kantarPaletSayisi');
if (_kasaSayEl)  _kasaSayEl.addEventListener('input',  updateLiveCalc);
if (_paletSayEl) _paletSayEl.addEventListener('input', updateLiveCalc);
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

    var paletEl = document.getElementById('kantarPaletSayisi');
    var kasaEl  = document.getElementById('kantarKasaSayisi');
    if (paletEl && paletEl.value) document.getElementById('mToplamPalet').value = paletEl.value;

    if (kantarKasaDaraKg  > 0) document.getElementById('mKasaDara').value  = fmt(kantarKasaDaraKg);
    if (kantarPaletDaraKg > 0) document.getElementById('mPaletDara').value = fmt(kantarPaletDaraKg);

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

document.getElementById('hesaplaAcBtn').addEventListener('click',  openHesaplaModal);
document.getElementById('hesaplaAcBtn2').addEventListener('click', openHesaplaModal);
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
            '<td class="num">' + fmt(g.palet) + '</td>' +
            '<td class="num">' + fmt(g.kasa)  + '</td>' +
            '<td class="num">' + fmt(brut)    + '</td>' +
            '<td class="num">' + fmt(kDara)   + '</td>' +
            '<td class="num">' + fmt(pDara)   + '</td>' +
            '<td class="num">' + fmt(tdara)   + '</td>' +
            '<td class="num kantar-net">' + fmt(net) + '</td>' +
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
            '<td class="num"><strong>' + fmt(totPalet) + '</strong></td>' +
            '<td class="num"><strong>' + fmt(totKasa)  + '</strong></td>' +
            '<td class="num"><strong>' + fmt(totBrut)  + '</strong></td>' +
            '<td class="num"><strong>' + fmt(totKDara) + '</strong></td>' +
            '<td class="num"><strong>' + fmt(totPDara) + '</strong></td>' +
            '<td class="num"><strong>' + fmt(totDara)  + '</strong></td>' +
            '<td class="num kantar-net"><strong>' + fmt(totNet) + '</strong></td>' +
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
    var paletEl = document.getElementById('kantarPaletSayisi');
    var kasaEl  = document.getElementById('kantarKasaSayisi');
    if (paletEl && mLastCalc.palet > 0) paletEl.value = Math.round(mLastCalc.palet);
    if (kasaEl  && mLastCalc.kasa  > 0) kasaEl.value  = Math.round(mLastCalc.kasa);
    updateLiveCalc();
    closeHesaplaModal();
});

document.getElementById('mRaporYazdir').addEventListener('click', function() {
    document.body.classList.add('kh-print');
    window.print();
    window.addEventListener('afterprint', function() {
        document.body.classList.remove('kh-print');
    }, { once: true });
});

})();
</script>
