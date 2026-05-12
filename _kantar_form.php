<?php
// =========================================================
// _kantar_form.php
// Kantar create + edit ortak form partial.
// Beklenen değişkenler:
//   $fis          - fiş alanları (dizi); create'de boş dizi
//   $gruplar      - kantar_gruplar satırları (dizi)
//   $form_action  - form action URL
//   $title        - sayfa başlığı
//   $submit_label - submit buton etiketi
//   $is_edit      - bool (edit modunda Sil butonu gösterilir)
// =========================================================

$is_edit = $is_edit ?? false;
$fis     = $fis ?? [];
$gruplar = $gruplar ?? [];
?>
<form method="post" action="<?= h($form_action) ?>" id="kantarForm">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
<?php if ($is_edit): ?>
<input type="hidden" name="id" value="<?= (int)$fis['id'] ?>">
<?php endif; ?>

<div class="page-head">
    <h1><?= h($title) ?></h1>
    <div class="page-head-actions">
        <a href="kantar.php" class="btn btn-ghost">İptal</a>
        <?php if ($is_edit): ?>
        <a href="kantar_delete.php?id=<?= (int)$fis['id'] ?>"
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
            <label>Giriş Tarih / Saat
                <input type="text" name="giris_tarih" value="<?= h($fis['giris_tarih'] ?? '') ?>">
            </label>
            <label>Çıkış Tarih / Saat
                <input type="text" name="cikis_tarih" value="<?= h($fis['cikis_tarih'] ?? '') ?>">
            </label>
            <label>Operatör
                <input type="text" name="operator_adi" value="<?= h($fis['operator_adi'] ?? '') ?>">
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
                           value="<?= h($fis['tartim1'] ?? '') ?>" placeholder="">
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
                           value="<?= h($fis['tartim2'] ?? '') ?>" placeholder="">
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
    </div>
</section>

<!-- ══════════════ HESAPLAMA PARAMETRELERİ ══════════════ -->
<section class="card">
    <div class="card-head">
        <h2>Hesaplama</h2>
        <button type="button" class="btn btn-primary" id="hesaplaAcBtn">⚖ Hesapla</button>
    </div>
    <div class="card-body">
        <div class="grid" style="margin-bottom:12px">
            <label>Toplam Palet Sayısı
                <input type="text" name="toplam_palet" id="fToplamPalet"
                       inputmode="numeric" class="num"
                       value="<?= h($fis['toplam_palet'] ?? '') ?>" placeholder="Palet">
            </label>
            <label>Kasa Darası <small class="muted">(kg/kasa)</small>
                <input type="text" name="kasa_dara" id="fKasaDara"
                       inputmode="decimal" class="num"
                       value="<?= h($fis['kasa_dara'] ?? '') ?>" placeholder="2">
            </label>
            <label>Palet Darası <small class="muted">(kg/palet)</small>
                <input type="text" name="palet_dara" id="fPaletDara"
                       inputmode="decimal" class="num"
                       value="<?= h($fis['palet_dara'] ?? '') ?>" placeholder="30">
            </label>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <strong style="font-size:.9rem">Gruplar</strong>
            <button type="button" class="btn btn-sm btn-primary" id="addFormGrupBtn">+ Grup Ekle</button>
        </div>
        <div id="formGruplar"></div>
        <p id="paletUyari" class="kantar-uyari" style="display:none"></p>
    </div>
</section>

<div class="form-foot">
    <a href="kantar.php" class="btn btn-ghost">İptal</a>
    <?php if ($is_edit): ?>
    <button type="button" class="btn btn-primary" id="hesaplaAcBtn2">⚖ Hesapla</button>
    <a href="kantar_delete.php?id=<?= (int)$fis['id'] ?>"
       class="btn btn-danger"
       onclick="return confirm('Bu fiş kalıcı olarak silinecek. Emin misiniz?')">Sil</a>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary btn-lg"><?= h($submit_label) ?></button>
</div>
</form>

<!-- ══════════════ HESAPLAMA MODAL ══════════════ -->
<div id="kantarModal" class="pm-overlay" style="display:none" role="dialog" aria-modal="true">
  <div class="pm-dialog">
    <div class="pm-header">
      <h2 class="pm-title">Palet / Kasa Hesaplama</h2>
      <button type="button" class="pm-close" id="kantarModalKapat" aria-label="Kapat">✕</button>
    </div>
    <div class="pm-body">
      <div class="grid" style="margin-bottom:12px">
        <label>Toplam Brüt KG
            <input type="text" id="mToplamBrut" inputmode="decimal" class="num" placeholder="Brüt KG">
        </label>
        <label>Toplam Palet Sayısı
            <input type="text" id="mToplamPalet" inputmode="numeric" class="num" placeholder="Palet">
        </label>
        <label>Kasa Darası <small class="muted">(kg/kasa)</small>
            <input type="text" id="mKasaDara" inputmode="decimal" class="num" placeholder="2">
        </label>
        <label>Palet Darası <small class="muted">(kg/palet)</small>
            <input type="text" id="mPaletDara" inputmode="decimal" class="num" placeholder="30">
        </label>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <strong style="font-size:.9rem">Gruplar</strong>
        <button type="button" class="btn btn-sm btn-primary" id="mAddGrupBtn">+ Grup Ekle</button>
      </div>
      <div id="mGrupList"></div>
      <p id="mPaletUyari" class="kantar-uyari" style="display:none"></p>
      <div id="mSonuc" style="margin-top:16px"></div>
    </div>
    <div class="pm-footer">
      <button type="button" class="btn btn-ghost" id="kantarModalKapat2">Kapat</button>
      <button type="button" class="btn btn-primary" id="mHesaplaBtn">Hesapla</button>
    </div>
  </div>
</div>

<script id="kantarGruplarInit" type="application/json"><?= json_encode(array_values($gruplar), JSON_UNESCAPED_UNICODE) ?></script>

<script>
(function () {

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

/* ── NET hesapla ── */
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
document.getElementById('tartim1').addEventListener('input', calcNet);
document.getElementById('tartim2').addEventListener('input', calcNet);
calcNet();

/* ── Form gruplar ── */
var formGrupSay = 0;

function addFormGrup(ad, palet, kasa) {
    var idx = formGrupSay++;
    var div = document.createElement('div');
    div.className = 'kantar-grup-row';
    div.innerHTML =
        '<div class="kantar-grup-header">' +
            '<span class="kantar-grup-no">' + (formGrupSay) + '. Grup</span>' +
            '<button type="button" class="btn btn-ghost btn-sm kantar-del-btn">✕ Sil</button>' +
        '</div>' +
        '<div class="kantar-grup-body">' +
            '<label class="kantar-lbl"><span>Grup Adı</span>' +
                '<input type="text" name="gruplar[' + idx + '][grup_adi]"' +
                ' class="kantar-ad-input" value="' + esc(ad != null ? ad : '') + '" placeholder="Grup adı">' +
            '</label>' +
            '<label class="kantar-lbl"><span>Palet Sayısı</span>' +
                '<input type="text" name="gruplar[' + idx + '][palet_sayisi]"' +
                ' class="kantar-palet-input num" inputmode="numeric" value="' + esc(palet != null ? palet : '') + '" placeholder="Palet">' +
            '</label>' +
            '<label class="kantar-lbl"><span>Kasa Adedi</span>' +
                '<input type="text" name="gruplar[' + idx + '][kasa_adedi]"' +
                ' class="kantar-kasa-input num" inputmode="numeric" value="' + esc(kasa != null ? kasa : '') + '" placeholder="Kasa">' +
            '</label>' +
        '</div>';
    div.querySelector('.kantar-del-btn').addEventListener('click', function () {
        div.remove();
        checkFormPalet();
    });
    div.querySelector('.kantar-palet-input').addEventListener('input', checkFormPalet);
    document.getElementById('formGruplar').appendChild(div);
    checkFormPalet();
}

function checkFormPalet() {
    var tp = parseNum(document.getElementById('fToplamPalet').value);
    if (!tp) { document.getElementById('paletUyari').style.display = 'none'; return; }
    var grupToplam = Array.from(document.querySelectorAll('#formGruplar .kantar-palet-input'))
        .reduce(function(s, el) { return s + parseNum(el.value); }, 0);
    var el = document.getElementById('paletUyari');
    if (grupToplam > 0 && Math.abs(grupToplam - tp) > 0.001) {
        el.textContent = '⚠ Grup palet toplamı (' + fmt(grupToplam) + ') toplam palet sayısıyla (' + fmt(tp) + ') eşleşmiyor.';
        el.style.display = '';
    } else {
        el.style.display = 'none';
    }
}

document.getElementById('fToplamPalet').addEventListener('input', checkFormPalet);
document.getElementById('addFormGrupBtn').addEventListener('click', function () { addFormGrup(); });

/* Init gruplar */
var initGruplar = JSON.parse(document.getElementById('kantarGruplarInit').textContent);
if (initGruplar && initGruplar.length) {
    initGruplar.forEach(function(g) {
        addFormGrup(g.grup_adi, g.palet_sayisi, g.kasa_adedi);
    });
} else {
    addFormGrup(); addFormGrup();
}

/* ── Modal grup ── */
var mGrupSay = 0;

function addModalGrup(ad, palet, kasa) {
    mGrupSay++;
    var div = document.createElement('div');
    div.className = 'kantar-grup-row';
    div.innerHTML =
        '<div class="kantar-grup-header">' +
            '<span class="kantar-grup-no">' + mGrupSay + '. Grup</span>' +
            '<button type="button" class="btn btn-ghost btn-sm kantar-del-btn">✕ Sil</button>' +
        '</div>' +
        '<div class="kantar-grup-body">' +
            '<label class="kantar-lbl"><span>Grup Adı</span>' +
                '<input type="text" class="kantar-ad-input" value="' + esc(ad != null ? ad : '') + '" placeholder="Grup adı">' +
            '</label>' +
            '<label class="kantar-lbl"><span>Palet Sayısı</span>' +
                '<input type="text" class="kantar-palet-input num" inputmode="numeric" value="' + esc(palet != null ? palet : '') + '" placeholder="Palet">' +
            '</label>' +
            '<label class="kantar-lbl"><span>Kasa Adedi</span>' +
                '<input type="text" class="kantar-kasa-input num" inputmode="numeric" value="' + esc(kasa != null ? kasa : '') + '" placeholder="Kasa">' +
            '</label>' +
        '</div>';
    div.querySelector('.kantar-del-btn').addEventListener('click', function () {
        div.remove();
        checkModalPalet();
    });
    div.querySelector('.kantar-palet-input').addEventListener('input', checkModalPalet);
    document.getElementById('mGrupList').appendChild(div);
    checkModalPalet();
}

function checkModalPalet() {
    var tp = parseNum(document.getElementById('mToplamPalet').value);
    if (!tp) { document.getElementById('mPaletUyari').style.display = 'none'; return; }
    var grupToplam = Array.from(document.querySelectorAll('#mGrupList .kantar-palet-input'))
        .reduce(function(s, el) { return s + parseNum(el.value); }, 0);
    var el = document.getElementById('mPaletUyari');
    if (grupToplam > 0 && Math.abs(grupToplam - tp) > 0.001) {
        el.textContent = '⚠ Grup palet toplamı (' + fmt(grupToplam) + ') toplam palet sayısıyla (' + fmt(tp) + ') eşleşmiyor.';
        el.style.display = '';
    } else {
        el.style.display = 'none';
    }
}

document.getElementById('mToplamPalet').addEventListener('input', checkModalPalet);
document.getElementById('mAddGrupBtn').addEventListener('click', function () { addModalGrup(); });

/* ── Modal aç ── */
function openModal() {
    var t1  = parseNum(document.getElementById('tartim1').value);
    var t2  = parseNum(document.getElementById('tartim2').value);
    var net = t1 - t2;
    if (net > 0) document.getElementById('mToplamBrut').value = String(net);

    document.getElementById('mToplamPalet').value = document.getElementById('fToplamPalet').value;
    document.getElementById('mKasaDara').value    = document.getElementById('fKasaDara').value;
    document.getElementById('mPaletDara').value   = document.getElementById('fPaletDara').value;

    /* Formdaki grupları kopyala */
    document.getElementById('mGrupList').innerHTML = '';
    mGrupSay = 0;
    Array.from(document.querySelectorAll('#formGruplar .kantar-grup-row')).forEach(function(row) {
        var ad    = row.querySelector('.kantar-ad-input').value;
        var palet = row.querySelector('.kantar-palet-input').value;
        var kasa  = row.querySelector('.kantar-kasa-input').value;
        if (parseNum(palet) > 0 || parseNum(kasa) > 0 || ad.trim()) {
            addModalGrup(ad, palet, kasa);
        }
    });
    if (!document.getElementById('mGrupList').children.length) {
        addModalGrup(); addModalGrup();
    }

    document.getElementById('mSonuc').innerHTML = '';
    document.getElementById('mPaletUyari').style.display = 'none';
    document.getElementById('kantarModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('kantarModal').style.display = 'none';
    document.body.style.overflow = '';
}

document.getElementById('hesaplaAcBtn').addEventListener('click', openModal);
document.getElementById('kantarModalKapat').addEventListener('click', closeModal);
document.getElementById('kantarModalKapat2').addEventListener('click', closeModal);
document.getElementById('kantarModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
var btn2 = document.getElementById('hesaplaAcBtn2');
if (btn2) btn2.addEventListener('click', openModal);

/* ── Hesapla ── */
document.getElementById('mHesaplaBtn').addEventListener('click', function () {
    var toplamBrut  = parseNum(document.getElementById('mToplamBrut').value);
    var toplamPalet = parseNum(document.getElementById('mToplamPalet').value);
    var kasaDara    = parseNum(document.getElementById('mKasaDara').value);
    var paletDara   = parseNum(document.getElementById('mPaletDara').value);

    if (!toplamBrut || !toplamPalet) {
        alert('Lütfen toplam brüt kg ve toplam palet sayısını girin.');
        return;
    }

    var gruplar = Array.from(document.querySelectorAll('#mGrupList .kantar-grup-row')).map(function(r) {
        return {
            ad:    r.querySelector('.kantar-ad-input').value.trim() || '—',
            palet: parseNum(r.querySelector('.kantar-palet-input').value),
            kasa:  parseNum(r.querySelector('.kantar-kasa-input').value),
        };
    }).filter(function(g) { return g.palet > 0 || g.kasa > 0; });

    if (!gruplar.length) {
        alert('Lütfen en az bir grup için palet veya kasa adedi girin.');
        return;
    }

    var paletBasiOrt = toplamBrut / toplamPalet;
    var totBrut=0, totKDara=0, totPDara=0, totDara=0, totNet=0, totPalet=0, totKasa=0, satirlar='';

    gruplar.forEach(function(g) {
        var brut  = g.palet * paletBasiOrt;
        var kDara = g.kasa  * kasaDara;
        var pDara = g.palet * paletDara;
        var tdara = kDara + pDara;
        var net   = brut - tdara;
        totBrut  += brut;
        totKDara += kDara;
        totPDara += pDara;
        totDara  += tdara;
        totNet   += net;
        totPalet += g.palet;
        totKasa  += g.kasa;
        satirlar +=
            '<tr><td><strong>' + esc(g.ad) + '</strong></td>' +
            '<td class="num">' + fmt(g.palet) + '</td>' +
            '<td class="num">' + fmt(g.kasa)  + '</td>' +
            '<td class="num">' + fmt(brut)    + '</td>' +
            '<td class="num">' + fmt(kDara)   + '</td>' +
            '<td class="num">' + fmt(pDara)   + '</td>' +
            '<td class="num">' + fmt(tdara)   + '</td>' +
            '<td class="num kantar-net">' + fmt(net) + '</td></tr>';
    });

    document.getElementById('mSonuc').innerHTML =
        '<div class="kantar-ozet">' +
            '<span class="kantar-ozet-lbl">Palet başı ortalama brüt</span>' +
            '<span class="kantar-ozet-val">' + fmt(paletBasiOrt) + ' kg</span>' +
        '</div>' +
        '<div class="table-wrap" style="margin-top:10px">' +
        '<table class="data-table"><thead><tr>' +
            '<th>Grup</th><th class="num">Palet</th><th class="num">Kasa</th>' +
            '<th class="num">Brüt</th><th class="num">K.Dara</th>' +
            '<th class="num">P.Dara</th><th class="num">Top.Dara</th><th class="num">Net KG</th>' +
        '</tr></thead><tbody>' + satirlar + '</tbody>' +
        '<tfoot><tr class="kantar-toplam-row">' +
            '<td><strong>TOPLAM</strong></td>' +
            '<td class="num"><strong>' + fmt(totPalet) + '</strong></td>' +
            '<td class="num"><strong>' + fmt(totKasa)  + '</strong></td>' +
            '<td class="num"><strong>' + fmt(totBrut)  + '</strong></td>' +
            '<td class="num"><strong>' + fmt(totKDara) + '</strong></td>' +
            '<td class="num"><strong>' + fmt(totPDara) + '</strong></td>' +
            '<td class="num"><strong>' + fmt(totDara)  + '</strong></td>' +
            '<td class="num kantar-net"><strong>' + fmt(totNet) + '</strong></td>' +
        '</tr></tfoot></table></div>';
});

})();
</script>
