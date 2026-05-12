<?php
// =========================================================
// kantar.php - Kantar Fişi + Palet/Kasa Hesaplama
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
                <input type="text" id="fisNo" placeholder="59">
            </label>
            <label>Plaka No
                <input type="text" id="plakaNo" placeholder="70ADC134" style="text-transform:uppercase">
            </label>
            <label>Firma Adı
                <input type="text" id="firmaAdi" placeholder="ASYA-KARAKÖSE">
            </label>
            <label>Giriş Tarih / Saat
                <input type="text" id="girisTarih" placeholder="12/05/2026  09:21">
            </label>
            <label>Çıkış Tarih / Saat
                <input type="text" id="cikisTarih" placeholder="12/05/2026  10:11">
            </label>
            <label>Operatör
                <input type="text" id="operator" placeholder="BÜŞRA">
            </label>
            <label>Malın Cinsi
                <input type="text" id="malinCinsi" placeholder="207 K65 5PLT CK">
            </label>
            <label>Geldiği Yer
                <input type="text" id="geldigiYer" placeholder="462 K65 11 PLT">
            </label>
            <label>Gittiği Yer
                <input type="text" id="gittigiYer" placeholder="ASYA">
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
                    <input type="text" id="tartim1" inputmode="decimal" class="num kantar-tartim-input" placeholder="31520">
                    <span class="kantar-tartim-unit">kg</span>
                    <input type="text" id="alibi1" class="kantar-alibi" placeholder="Alibi No">
                </div>
            </div>
            <div class="kantar-tartim-row">
                <div class="kantar-tartim-no">2. Tartım</div>
                <div class="kantar-tartim-fields">
                    <input type="text" id="tartim2" inputmode="decimal" class="num kantar-tartim-input" placeholder="14280">
                    <span class="kantar-tartim-unit">kg</span>
                    <input type="text" id="alibi2" class="kantar-alibi" placeholder="Alibi No">
                </div>
            </div>
            <div class="kantar-net-satir">
                <span class="kantar-net-lbl">NET</span>
                <span class="kantar-net-val" id="fisNetKg">—</span>
            </div>
        </div>

    </div>
</section>

<!-- ══════════════ HESAPLAMA ══════════════ -->
<section class="card">
    <div class="card-head"><h2>Palet / Kasa Hesaplama</h2></div>
    <div class="card-body">
        <div class="grid">
            <label>Toplam Brüt KG
                <input type="text" id="toplamBrut" inputmode="decimal" placeholder="NET'ten otomatik" class="num">
            </label>
            <label>Toplam Palet Sayısı
                <input type="text" id="toplamPalet" inputmode="numeric" placeholder="Palet" class="num">
            </label>
            <label>Kasa Darası <small class="muted">(kg / kasa)</small>
                <input type="text" id="kasaDaraKg" inputmode="decimal" placeholder="2" class="num">
            </label>
            <label>Palet Darası <small class="muted">(kg / palet)</small>
                <input type="text" id="paletDaraKg" inputmode="decimal" placeholder="30" class="num">
            </label>
        </div>
    </div>
</section>

<!-- ══════════════ GRUPLAR ══════════════ -->
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

<!-- ══════════════ SONUÇ ══════════════ -->
<section class="card" id="sonucCard" style="display:none">
    <div class="card-head"><h2>Sonuç</h2></div>
    <div id="sonucIcerik" class="card-body" style="padding-top:0"></div>
</section>

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

/* ── NET hesabı: 1.tartım - 2.tartım ── */
function calcNet() {
    const t1 = parseNum(document.getElementById('tartim1').value);
    const t2 = parseNum(document.getElementById('tartim2').value);
    const net = t1 - t2;
    const el  = document.getElementById('fisNetKg');
    if (t1 > 0 && t2 > 0 && net > 0) {
        el.textContent = fmt(net) + ' kg';
        el.className = 'kantar-net-val kantar-net-pozitif';
        document.getElementById('toplamBrut').value = String(net);
    } else if (t1 > 0 || t2 > 0) {
        el.textContent = net > 0 ? fmt(net) + ' kg' : '—';
        el.className = 'kantar-net-val';
        if (net > 0) document.getElementById('toplamBrut').value = String(net);
    } else {
        el.textContent = '—';
        el.className = 'kantar-net-val';
    }
}

document.getElementById('tartim1').addEventListener('input', calcNet);
document.getElementById('tartim2').addEventListener('input', calcNet);

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
            '<button type="button" class="btn btn-ghost btn-sm kantar-del-btn">✕ Sil</button>' +
        '</div>' +
        '<div class="kantar-grup-body">' +
            '<label class="kantar-lbl"><span>Grup Adı</span>' +
                '<input type="text" class="kantar-ad-input" value="' + esc(ad ?? '') + '" placeholder="CK">' +
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
    let totBrut=0, totKDara=0, totPDara=0, totDara=0, totNet=0, totPalet=0, totKasa=0, satirlar='';

    gruplar.forEach(function (g) {
        const brut  = g.palet * paletBasiOrt;
        const kDara = g.kasa  * kasaDara;
        const pDara = g.palet * paletDara;
        const tdara = kDara + pDara;
        const net   = brut - tdara;
        totBrut += brut; totKDara += kDara; totPDara += pDara;
        totDara += tdara; totNet += net; totPalet += g.palet; totKasa += g.kasa;
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

    // Fiş bilgilerini özete ekle
    const fisNo    = document.getElementById('fisNo').value.trim();
    const plaka    = document.getElementById('plakaNo').value.trim();
    const firma    = document.getElementById('firmaAdi').value.trim();
    const t1       = parseNum(document.getElementById('tartim1').value);
    const t2       = parseNum(document.getElementById('tartim2').value);

    let fisBilgi = '';
    if (fisNo || plaka || firma) {
        fisBilgi = '<div class="kantar-fis-ozet">';
        if (fisNo)  fisBilgi += '<span><em>Fiş:</em> ' + esc(fisNo) + '</span>';
        if (plaka)  fisBilgi += '<span><em>Plaka:</em> ' + esc(plaka) + '</span>';
        if (firma)  fisBilgi += '<span><em>Firma:</em> ' + esc(firma) + '</span>';
        if (t1 && t2) fisBilgi += '<span><em>1.Tartım:</em> ' + fmt(t1) + ' kg</span>'
            + '<span><em>2.Tartım:</em> ' + fmt(t2) + ' kg</span>';
        fisBilgi += '</div>';
    }

    const html = fisBilgi +
        '<div class="kantar-ozet">' +
            '<span class="kantar-ozet-lbl">Palet başı ortalama brüt</span>' +
            '<span class="kantar-ozet-val">' + fmt(paletBasiOrt) + ' kg</span>' +
        '</div>' +
        '<div class="table-wrap">' +
        '<table class="data-table"><thead><tr>' +
            '<th>Grup</th><th class="num">Palet</th><th class="num">Kasa</th>' +
            '<th class="num">Brüt KG</th><th class="num">Kasa Dara</th>' +
            '<th class="num">Palet Dara</th><th class="num">Top. Dara</th><th class="num">Net KG</th>' +
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

    document.getElementById('sonucIcerik').innerHTML = html;
    const card = document.getElementById('sonucCard');
    card.style.display = '';
    setTimeout(function () { card.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
});

})();
</script>

<?php render_footer(); ?>
