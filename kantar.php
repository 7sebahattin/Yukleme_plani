<?php
// =========================================================
// kantar.php - Kantar / Palet-Kasa Kilo Hesaplama
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
render_header('Kantar Hesaplama');
?>

<div class="page-head">
    <h1>Kantar Hesaplama</h1>
    <a href="index.php" class="btn btn-ghost">← Ana Sayfa</a>
</div>

<!-- Genel Bilgiler -->
<section class="card">
    <div class="card-head"><h2>Genel Bilgiler</h2></div>
    <div class="card-body">
        <div class="grid">
            <label>Toplam Brüt KG
                <input type="text" id="toplamBrut" inputmode="decimal" placeholder="örn. 17240">
            </label>
            <label>Toplam Palet Sayısı
                <input type="text" id="toplamPalet" inputmode="numeric" placeholder="örn. 16">
            </label>
            <label>Kasa Darası <small class="muted">(kg / kasa)</small>
                <input type="text" id="kasaDaraKg" inputmode="decimal" placeholder="örn. 2">
            </label>
            <label>Palet Darası <small class="muted">(kg / palet)</small>
                <input type="text" id="paletDaraKg" inputmode="decimal" placeholder="örn. 30">
            </label>
        </div>
    </div>
</section>

<!-- Gruplar -->
<section class="card">
    <div class="card-head">
        <h2>Gruplar</h2>
        <button type="button" class="btn btn-primary" id="addGrupBtn">+ Grup Ekle</button>
    </div>
    <div class="card-body" style="padding-top:8px">
        <div id="grupList"></div>
        <p id="paletUyari" class="kantar-uyari" style="display:none"></p>
    </div>
</section>

<div class="kantar-hesapla-row">
    <button type="button" class="btn btn-primary btn-lg" id="hesaplaBtn" style="width:100%">Hesapla</button>
</div>

<!-- Sonuçlar -->
<section class="card" id="sonucCard" style="display:none">
    <div class="card-head"><h2>Sonuç</h2></div>
    <div id="sonucIcerik" class="card-body" style="padding-top:0"></div>
</section>

<script>
(function () {

/* ── yardımcılar ── */
function parseNum(s) {
    s = String(s ?? '').trim().replace(/\s/g, '').replace(/\./g, '').replace(',', '.');
    const n = parseFloat(s);
    return isNaN(n) ? 0 : n;
}

function fmt(n) {
    // En fazla 3 basamak, sondaki sıfırlar yok
    let s = (Math.round(n * 1000) / 1000).toFixed(3).replace(/\.?0+$/, '');
    const parts = s.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return parts.length > 1 ? parts[0] + ',' + parts[1] : parts[0];
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── palet toplamı uyarısı ── */
function checkPaletToplam() {
    const tp = parseNum(document.getElementById('toplamPalet').value);
    if (!tp) { document.getElementById('paletUyari').style.display = 'none'; return; }
    const grupToplam = [...document.querySelectorAll('.kantar-palet-input')]
        .reduce((s, el) => s + parseNum(el.value), 0);
    const el = document.getElementById('paletUyari');
    if (grupToplam > 0 && Math.abs(grupToplam - tp) > 0.001) {
        el.textContent = '⚠ Grup palet toplamı (' + fmt(grupToplam) + ') girilen toplam palet sayısıyla (' + fmt(tp) + ') eşleşmiyor.';
        el.style.display = '';
    } else {
        el.style.display = 'none';
    }
}

document.getElementById('toplamPalet').addEventListener('input', checkPaletToplam);

/* ── grup satırı ── */
let grupSay = 0;

function addGrup(ad, palet, kasa) {
    grupSay++;
    const div = document.createElement('div');
    div.className = 'kantar-grup-row';

    div.innerHTML =
        '<div class="kantar-grup-header">' +
            '<span class="kantar-grup-no">' + grupSay + '. Grup</span>' +
            '<button type="button" class="btn btn-ghost btn-sm kantar-del-btn" aria-label="Sil">✕ Sil</button>' +
        '</div>' +
        '<div class="kantar-grup-body">' +
            '<label class="kantar-lbl">' +
                '<span>Grup Adı</span>' +
                '<input type="text" class="kantar-ad-input" value="' + esc(ad ?? '') + '" placeholder="örn. CK">' +
            '</label>' +
            '<label class="kantar-lbl">' +
                '<span>Palet Sayısı</span>' +
                '<input type="text" class="kantar-palet-input num" inputmode="numeric" value="' + esc(palet ?? '') + '" placeholder="5">' +
            '</label>' +
            '<label class="kantar-lbl">' +
                '<span>Kasa Adedi</span>' +
                '<input type="text" class="kantar-kasa-input num" inputmode="numeric" value="' + esc(kasa ?? '') + '" placeholder="207">' +
            '</label>' +
        '</div>';

    div.querySelector('.kantar-del-btn').addEventListener('click', function () {
        div.remove();
        checkPaletToplam();
    });
    div.querySelector('.kantar-palet-input').addEventListener('input', checkPaletToplam);

    document.getElementById('grupList').appendChild(div);
}

document.getElementById('addGrupBtn').addEventListener('click', function () { addGrup(); });

/* başlangıçta 2 grup */
addGrup(); addGrup();

/* ── hesapla ── */
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

    let totBrut = 0, totKDara = 0, totPDara = 0, totDara = 0, totNet = 0, totPalet = 0, totKasa = 0;
    let satirlar = '';

    gruplar.forEach(function (g) {
        const brut  = g.palet * paletBasiOrt;
        const kDara = g.kasa  * kasaDara;
        const pDara = g.palet * paletDara;
        const tdara = kDara + pDara;
        const net   = brut - tdara;

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

    const html =
        '<div class="kantar-ozet">' +
            '<span class="kantar-ozet-lbl">Palet başı ortalama brüt</span>' +
            '<span class="kantar-ozet-val">' + fmt(paletBasiOrt) + ' kg</span>' +
        '</div>' +
        '<div class="table-wrap">' +
        '<table class="data-table">' +
        '<thead><tr>' +
            '<th>Grup</th>' +
            '<th class="num">Palet</th>' +
            '<th class="num">Kasa</th>' +
            '<th class="num">Brüt KG</th>' +
            '<th class="num">Kasa Dara</th>' +
            '<th class="num">Palet Dara</th>' +
            '<th class="num">Top. Dara</th>' +
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

    document.getElementById('sonucIcerik').innerHTML = html;
    const card = document.getElementById('sonucCard');
    card.style.display = '';
    setTimeout(function () { card.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
});

})();
</script>

<?php render_footer(); ?>
