<?php
// =========================================================
// kantar.php - Kantar Fişi + Hesaplama Modalı
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
render_header('Kantar');
?>

<div class="page-head">
    <h1>Kantar</h1>
    <a href="index.php" class="btn btn-ghost">← Ana Sayfa</a>
</div>

<!-- ══════════════ KANTAR FİŞİ ══════════════ -->
<section class="card">
    <div class="card-head"><h2>⚖️ Kantar Fişi</h2></div>
    <div class="card-body">

        <div class="grid">
            <label>Fiş No
                <input type="text" id="fisNo" placeholder="">
            </label>
            <label>Plaka No
                <input type="text" id="plakaNo" style="text-transform:uppercase" placeholder="">
            </label>
            <label>Firma Adı
                <input type="text" id="firmaAdi" placeholder="">
            </label>
            <label>Giriş Tarih / Saat
                <input type="text" id="girisTarih" placeholder="">
            </label>
            <label>Çıkış Tarih / Saat
                <input type="text" id="cikisTarih" placeholder="">
            </label>
            <label>Operatör
                <input type="text" id="operator" placeholder="">
            </label>
            <label>Malın Cinsi
                <input type="text" id="malinCinsi" placeholder="">
            </label>
            <label>Geldiği Yer
                <input type="text" id="geldigiYer" placeholder="">
            </label>
            <label>Gittiği Yer
                <input type="text" id="gittigiYer" placeholder="">
            </label>
            <label class="span-2">Açıklama
                <input type="text" id="aciklama" placeholder="">
            </label>
        </div>

        <!-- Tartımlar -->
        <div class="kantar-tartim-wrap">
            <div class="kantar-tartim-row">
                <div class="kantar-tartim-no">1. Tartım</div>
                <div class="kantar-tartim-fields">
                    <input type="text" id="tartim1" inputmode="decimal" class="num kantar-tartim-input" placeholder="">
                    <span class="kantar-tartim-unit">kg</span>
                    <input type="text" id="alibi1" class="kantar-alibi" placeholder="Alibi No">
                </div>
            </div>
            <div class="kantar-tartim-row">
                <div class="kantar-tartim-no">2. Tartım</div>
                <div class="kantar-tartim-fields">
                    <input type="text" id="tartim2" inputmode="decimal" class="num kantar-tartim-input" placeholder="">
                    <span class="kantar-tartim-unit">kg</span>
                    <input type="text" id="alibi2" class="kantar-alibi" placeholder="Alibi No">
                </div>
            </div>
            <div class="kantar-net-satir">
                <span class="kantar-net-lbl">NET</span>
                <span class="kantar-net-val" id="fisNetKg">—</span>
            </div>
        </div>

        <!-- Hesaplama butonu -->
        <div style="margin-top:16px">
            <button type="button" class="btn btn-primary btn-lg" id="openHesaplaBtn" style="width:100%">
                Hesaplama Yap
            </button>
        </div>

    </div>
</section>

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
            <input type="text" id="toplamBrut" inputmode="decimal" class="num" placeholder="Brüt KG">
        </label>
        <label>Toplam Palet Sayısı
            <input type="text" id="toplamPalet" inputmode="numeric" class="num" placeholder="Palet">
        </label>
        <label>Kasa Darası <small class="muted">(kg/kasa)</small>
            <input type="text" id="kasaDaraKg" inputmode="decimal" class="num" placeholder="2">
        </label>
        <label>Palet Darası <small class="muted">(kg/palet)</small>
            <input type="text" id="paletDaraKg" inputmode="decimal" class="num" placeholder="30">
        </label>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <strong style="font-size:.9rem">Gruplar</strong>
        <button type="button" class="btn btn-sm btn-primary" id="addGrupBtn">+ Grup Ekle</button>
      </div>
      <div id="grupList"></div>
      <p id="paletUyari" class="kantar-uyari" style="display:none"></p>

      <!-- Sonuç -->
      <div id="sonucIcerik" style="margin-top:16px"></div>

    </div>
    <div class="pm-footer">
      <button type="button" class="btn btn-ghost" id="kantarModalIptal">Kapat</button>
      <button type="button" class="btn btn-primary" id="hesaplaBtn">Hesapla</button>
    </div>
  </div>
</div>

<script>
(function () {

function parseNum(s) {
    s = String(s ?? '').trim().replace(/\s/g, '').replace(/\./g, '').replace(',', '.');
    const n = parseFloat(s);
    return isNaN(n) ? 0 : n;
}

function fmt(n) {
    let s = (Math.round(n * 1000) / 1000).toFixed(3).replace(/\.?0+$/, '');
    const parts = s.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return parts.length > 1 ? parts[0] + ',' + parts[1] : parts[0];
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── NET: 1.tartım - 2.tartım ── */
function calcNet() {
    const t1  = parseNum(document.getElementById('tartim1').value);
    const t2  = parseNum(document.getElementById('tartim2').value);
    const net = t1 - t2;
    const el  = document.getElementById('fisNetKg');
    if (t1 > 0 && t2 > 0 && net > 0) {
        el.textContent = fmt(net) + ' kg';
        el.className = 'kantar-net-val kantar-net-pozitif';
    } else {
        el.textContent = (t1 > 0 || t2 > 0) && net > 0 ? fmt(net) + ' kg' : '—';
        el.className = 'kantar-net-val';
    }
}
document.getElementById('tartim1').addEventListener('input', calcNet);
document.getElementById('tartim2').addEventListener('input', calcNet);

/* ── Modal aç / kapat ── */
function openModal() {
    // NET'i Toplam Brüt alanına aktar
    const t1 = parseNum(document.getElementById('tartim1').value);
    const t2 = parseNum(document.getElementById('tartim2').value);
    const net = t1 - t2;
    if (net > 0) document.getElementById('toplamBrut').value = String(net);

    document.getElementById('kantarModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeModal() {
    document.getElementById('kantarModal').style.display = 'none';
    document.body.style.overflow = '';
}

document.getElementById('openHesaplaBtn').addEventListener('click', openModal);
document.getElementById('kantarModalKapat').addEventListener('click', closeModal);
document.getElementById('kantarModalIptal').addEventListener('click', closeModal);
document.getElementById('kantarModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

/* ── Palet toplamı uyarısı ── */
function checkPaletToplam() {
    const tp = parseNum(document.getElementById('toplamPalet').value);
    if (!tp) { document.getElementById('paletUyari').style.display = 'none'; return; }
    const grupToplam = [...document.querySelectorAll('.kantar-palet-input')]
        .reduce((s, el) => s + parseNum(el.value), 0);
    const el = document.getElementById('paletUyari');
    if (grupToplam > 0 && Math.abs(grupToplam - tp) > 0.001) {
        el.textContent = '⚠ Grup palet toplamı (' + fmt(grupToplam) + ') toplam palet sayısıyla (' + fmt(tp) + ') eşleşmiyor.';
        el.style.display = '';
    } else {
        el.style.display = 'none';
    }
}
document.getElementById('toplamPalet').addEventListener('input', checkPaletToplam);

/* ── Grup satırı ── */
let grupSay = 0;
function addGrup(ad, palet, kasa) {
    grupSay++;
    const div = document.createElement('div');
    div.className = 'kantar-grup-row';
    div.innerHTML =
        '<div class="kantar-grup-header">' +
            '<span class="kantar-grup-no">' + grupSay + '. Grup</span>' +
            '<button type="button" class="btn btn-ghost btn-sm kantar-del-btn">✕ Sil</button>' +
        '</div>' +
        '<div class="kantar-grup-body">' +
            '<label class="kantar-lbl"><span>Grup Adı</span>' +
                '<input type="text" class="kantar-ad-input" value="' + esc(ad ?? '') + '" placeholder="Grup adı">' +
            '</label>' +
            '<label class="kantar-lbl"><span>Palet Sayısı</span>' +
                '<input type="text" class="kantar-palet-input num" inputmode="numeric" value="' + esc(palet ?? '') + '" placeholder="Palet">' +
            '</label>' +
            '<label class="kantar-lbl"><span>Kasa Adedi</span>' +
                '<input type="text" class="kantar-kasa-input num" inputmode="numeric" value="' + esc(kasa ?? '') + '" placeholder="Kasa">' +
            '</label>' +
        '</div>';
    div.querySelector('.kantar-del-btn').addEventListener('click', function () { div.remove(); checkPaletToplam(); });
    div.querySelector('.kantar-palet-input').addEventListener('input', checkPaletToplam);
    document.getElementById('grupList').appendChild(div);
}
document.getElementById('addGrupBtn').addEventListener('click', function () { addGrup(); });
addGrup(); addGrup();

/* ── Hesapla ── */
document.getElementById('hesaplaBtn').addEventListener('click', function () {
    const toplamBrut  = parseNum(document.getElementById('toplamBrut').value);
    const toplamPalet = parseNum(document.getElementById('toplamPalet').value);
    const kasaDara    = parseNum(document.getElementById('kasaDaraKg').value);
    const paletDara   = parseNum(document.getElementById('paletDaraKg').value);

    if (!toplamBrut || !toplamPalet) {
        alert('Lütfen toplam brüt kg ve toplam palet sayısını girin.');
        return;
    }

    const gruplar = [...document.querySelectorAll('.kantar-grup-row')].map(function (r) {
        return {
            ad:    r.querySelector('.kantar-ad-input').value.trim() || '—',
            palet: parseNum(r.querySelector('.kantar-palet-input').value),
            kasa:  parseNum(r.querySelector('.kantar-kasa-input').value),
        };
    }).filter(function (g) { return g.palet > 0 || g.kasa > 0; });

    if (!gruplar.length) {
        alert('Lütfen en az bir grup için palet veya kasa adedi girin.');
        return;
    }

    const paletBasiOrt = toplamBrut / toplamPalet;
    let totBrut=0, totKDara=0, totPDara=0, totDara=0, totNet=0, totPalet=0, totKasa=0, satirlar='';

    gruplar.forEach(function (g) {
        const brut  = g.palet * paletBasiOrt;
        const kDara = g.kasa  * kasaDara;
        const pDara = g.palet * paletDara;
        const tdara = kDara + pDara;
        const net   = brut - tdara;
        totBrut+=brut; totKDara+=kDara; totPDara+=pDara; totDara+=tdara; totNet+=net; totPalet+=g.palet; totKasa+=g.kasa;
        satirlar +=
            '<tr><td><strong>' + esc(g.ad) + '</strong></td>' +
            '<td class="num">' + fmt(g.palet) + '</td><td class="num">' + fmt(g.kasa)  + '</td>' +
            '<td class="num">' + fmt(brut)    + '</td><td class="num">' + fmt(kDara)   + '</td>' +
            '<td class="num">' + fmt(pDara)   + '</td><td class="num">' + fmt(tdara)   + '</td>' +
            '<td class="num kantar-net">' + fmt(net) + '</td></tr>';
    });

    document.getElementById('sonucIcerik').innerHTML =
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

<?php render_footer(); ?>
