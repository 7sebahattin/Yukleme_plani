/* Mal Kabul Fişleri listesi (kantar.php) — Excel görünümlü tablo.
 * Satıra tıklayınca fiş açılır · başlığa tık: sırala · başlık kenarından sürükle: genişlik · başlığa çift tık / "Sütunlar":
 * göster-gizle · süz satırı: sütunda geçen metne göre filtre. Genişlik/gizli tercihi KULLANICI BAZINDA sunucuda saklanır
 * (kullanici_tercihleri 'kantar.tablo'; tablo yoksa tarayıcıya düşer). Görünüm palet-fisleri.css ile ortaktır. */
(function () {
    'use strict';
    var cfg = window.KANTAR_LISTE || {};
    var page = document.getElementById('kfPage');
    var table = document.getElementById('kfTable');
    if (!page || !table) return;
    var wrap = document.getElementById('kfWrap');
    var body = document.getElementById('kfBody');
    var head = document.getElementById('kfHead');
    var filtreSatir = document.getElementById('kfFiltreSatiri');
    var bosEl = document.getElementById('kfEmpty');
    var durum = document.getElementById('kfStatus');
    var YEREL = 'kantar.tablo.v1';
    var VARSAYILAN = { sofor: 130, mal_sahibi: 140, mustahsil: 140, bahce: 120, fis_no: 80, tarih: 95, plaka: 100, firma: 150, malin: 120, geldigi: 130, depo: 130, palet: 70, palet_cinsi: 130, kasa: 70, kasa_cinsi: 130,
        t1: 95, t2: 95, brut: 100, dara: 80, net: 95, aciklama: 200, islem: 95, islem_yapan: 130, islem_zaman: 130 };
    var tr = function (s) { return String(s == null ? '' : s).toLocaleLowerCase('tr-TR'); };
    var satirlar = Array.prototype.slice.call(body.querySelectorAll('tr'));
    var sutunlar = Array.prototype.slice.call(head.children).map(function (th) { return th.dataset.col; });

    // ── Tercihler (genişlik + gizli sütunlar) ──
    var tercih = { genislik: {}, sira: [], gizli: [] };
    (function () {
        var ham = cfg.tercihSunucu ? cfg.tercih : null;
        if (!cfg.tercihSunucu) { try { ham = JSON.parse(localStorage.getItem(YEREL) || 'null'); } catch (e) { ham = null; } }
        if (ham && typeof ham === 'object') tercih = { genislik: ham.genislik || {}, sira: [], gizli: Array.isArray(ham.gizli) ? ham.gizli : [] };
    })();
    var gizli = new Set((tercih.gizli || []).filter(function (c) { return sutunlar.indexOf(c) >= 0; }));
    if (gizli.size >= sutunlar.length) gizli.clear();
    var zamanlayici = 0;
    function tercihKaydet() {
        clearTimeout(zamanlayici);
        zamanlayici = setTimeout(function () {
            tercih.gizli = Array.from(gizli);
            if (!cfg.tercihSunucu) { try { localStorage.setItem(YEREL, JSON.stringify(tercih)); } catch (e) { /* depolama kapalı */ } return; }
            var fd = new FormData();
            fd.append('action', 'tercih_kaydet'); fd.append('csrf', page.dataset.csrf || '');
            fd.append('anahtar', cfg.anahtar || 'kantar.tablo'); fd.append('deger', JSON.stringify(tercih));
            fetch('kantar.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (r) { if (!r.ok) durumYaz('Sütun ayarı kaydedilemedi.'); })
                .catch(function () { durumYaz('Sütun ayarı kaydedilemedi.'); });
        }, 500);
    }
    function durumYaz(t) { if (durum) durum.textContent = t || ''; }

    // ── Sütun genişliği / gizleme ──
    var gizliStil = document.head.appendChild(document.createElement('style'));
    function genislikUygula() {
        var toplam = 0;
        Array.prototype.forEach.call(head.children, function (th) {
            var c = th.dataset.col;
            if (gizli.has(c)) return;
            var w = tercih.genislik[c] || VARSAYILAN[c] || 120;
            [th, filtreSatir.querySelector('th[data-col="' + c + '"]')].forEach(function (el) {
                if (el) { el.style.width = el.style.minWidth = el.style.maxWidth = w + 'px'; }
            });
            toplam += w;
        });
        table.style.width = toplam + 'px';
    }
    function gizliUygula() {
        gizliStil.textContent = Array.from(gizli).map(function (c) { return '#kfTable [data-col="' + c + '"]{display:none}'; }).join('');
        genislikUygula();
    }
    function varsayilanGenislikler() {
        var gor = sutunlar.filter(function (c) { return !gizli.has(c); });
        var top = gor.reduce(function (s, c) { return s + (VARSAYILAN[c] || 120); }, 0);
        var oran = Math.max(1, (wrap.clientWidth - 24) / top);
        gor.forEach(function (c) { if (!tercih.genislik[c]) tercih.genislik[c] = Math.round((VARSAYILAN[c] || 120) * oran); });
    }
    function filtreSatiriKonum() {   // 2. başlık satırı, 1.nin altına yapışır
        var h = head.getBoundingClientRect().height;
        Array.prototype.forEach.call(filtreSatir.children, function (th) { th.style.top = h + 'px'; });
    }
    Array.prototype.forEach.call(head.children, function (th) {
        var tutac = document.createElement('i');
        tutac.className = 'pf-rs'; tutac.title = 'Sürükleyin: genişlik';
        th.appendChild(tutac);
        tutac.addEventListener('click', function (e) { e.stopPropagation(); });
        tutac.addEventListener('dblclick', function (e) { e.stopPropagation(); });
        tutac.addEventListener('pointerdown', function (e) {
            if (e.button !== 0) return;
            e.preventDefault(); e.stopPropagation();
            var x0 = e.clientX, w0 = th.getBoundingClientRect().width, c = th.dataset.col;
            tutac.setPointerCapture(e.pointerId); document.body.classList.add('pf-boyutlaniyor');
            var hareket = function (ev) { tercih.genislik[c] = Math.max(44, Math.min(1200, Math.round(w0 + ev.clientX - x0))); genislikUygula(); };
            var bitti = function () {
                tutac.removeEventListener('pointermove', hareket); tutac.removeEventListener('pointerup', bitti); tutac.removeEventListener('pointercancel', bitti);
                document.body.classList.remove('pf-boyutlaniyor'); tercihKaydet();
            };
            tutac.addEventListener('pointermove', hareket); tutac.addEventListener('pointerup', bitti); tutac.addEventListener('pointercancel', bitti);
        });
    });

    // ── Sütunlar penceresi ──
    var dlg = document.getElementById('kfSutunDialog');
    var liste = document.getElementById('kfSutunListe');
    function listeCiz() {
        liste.innerHTML = '';
        Array.prototype.forEach.call(head.children, function (th) {
            var c = th.dataset.col;
            var satir = document.createElement('label'); satir.className = 'pf-sutun-satir';
            var kutu = document.createElement('input'); kutu.type = 'checkbox'; kutu.checked = !gizli.has(c);
            kutu.disabled = kutu.checked && sutunlar.length - gizli.size <= 1;   // en az bir sütun görünür kalır
            kutu.addEventListener('change', function () {
                if (kutu.checked) gizli.delete(c); else gizli.add(c);
                gizliUygula(); tercihKaydet(); listeCiz(); sonucuGuncelle();
            });
            var ad = document.createElement('span'); ad.textContent = th.childNodes[0] ? th.childNodes[0].textContent.trim() : c;
            satir.appendChild(kutu); satir.appendChild(ad); liste.appendChild(satir);
        });
    }
    function sutunPenceresi() { if (!dlg || dlg.open) return; listeCiz(); dlg.showModal(); }
    head.addEventListener('dblclick', function (e) { if (e.target.closest('.pf-rs')) return; e.preventDefault(); clearTimeout(siralaZaman); sutunPenceresi(); });
    document.getElementById('kfSutunTamam').addEventListener('click', function () { dlg.close(); });
    document.getElementById('kfSutunKapat').addEventListener('click', function () { dlg.close(); });
    document.getElementById('kfSutunHepsi').addEventListener('click', function () { gizli.clear(); gizliUygula(); tercihKaydet(); listeCiz(); });

    // ── Sıralama (başlığa tık) ──
    var sirala = { col: null, yon: 1 };
    var siralaZaman = 0;
    function deger(tr_, c) { var td = tr_.querySelector('td[data-col="' + c + '"]'); return td ? (td.dataset.sort != null ? td.dataset.sort : td.dataset.value) : ''; }
    function siralaUygula() {
        if (!sirala.col) return;
        var sayisal = ['palet', 'kasa', 't1', 't2', 'brut', 'dara', 'net', 'tarih', 'islem_zaman'].indexOf(sirala.col) >= 0;
        var dizi = satirlar.slice().sort(function (a, b) {
            var x = deger(a, sirala.col), y = deger(b, sirala.col);
            var s = sayisal ? (parseFloat(x) || 0) - (parseFloat(y) || 0) : String(x).localeCompare(String(y), 'tr', { numeric: true });
            return s * sirala.yon;
        });
        dizi.forEach(function (r) { body.appendChild(r); });
        Array.prototype.forEach.call(head.children, function (th) { th.classList.remove('kf-sirali-art', 'kf-sirali-azal'); });
        var th = head.querySelector('th[data-col="' + sirala.col + '"]');
        if (th) th.classList.add(sirala.yon > 0 ? 'kf-sirali-art' : 'kf-sirali-azal');
    }
    head.addEventListener('click', function (e) {
        var th = e.target.closest('th[data-col]');
        if (!th || e.target.closest('.pf-rs')) return;
        clearTimeout(siralaZaman);
        siralaZaman = setTimeout(function () {      // çift tık (Sütunlar) ile karışmasın
            var c = th.dataset.col;
            sirala = sirala.col === c ? { col: c, yon: -sirala.yon } : { col: c, yon: 1 };
            siralaUygula(); durumYaz('');
        }, 230);
    });

    // ── Süzgeç ──
    var filtreler = {};
    function sonucuGuncelle() {
        var gorunen = 0, top = { palet: 0, kasa: 0, brut: 0, dara: 0, net: 0 };
        satirlar.forEach(function (r) {
            var ok = true;
            Object.keys(filtreler).forEach(function (c) {
                var q = filtreler[c];
                if (!q || !ok) return;
                var td = r.querySelector('td[data-col="' + c + '"]');
                if (!td || tr(td.dataset.value).indexOf(q) < 0) ok = false;
            });
            r.hidden = !ok;
            if (!ok) return;
            gorunen++;
            Object.keys(top).forEach(function (k) { var td = r.querySelector('td[data-col="' + k + '"]'); top[k] += td ? (parseFloat(td.dataset.sort) || 0) : 0; });
        });
        var fmt0 = function (n) { return Math.round(n).toLocaleString('tr-TR'); };
        document.getElementById('kfCount').textContent = gorunen;
        var ozet = document.getElementById('kfOzet');
        ozet.querySelector('[data-sum="fis"]').textContent = fmt0(gorunen);
        Object.keys(top).forEach(function (k) { var el = ozet.querySelector('[data-sum="' + k + '"]'); if (el) el.textContent = fmt0(top[k]); });
        bosEl.hidden = gorunen > 0 || !satirlar.length;
        var aktif = Object.keys(filtreler).some(function (c) { return filtreler[c]; });
        document.getElementById('kfFiltreTemizle').hidden = !aktif;
    }
    filtreSatir.addEventListener('input', function (e) {
        var inp = e.target.closest('input[data-f]');
        if (!inp) return;
        filtreler[inp.dataset.f] = tr(inp.value.trim());
        sonucuGuncelle();
    });
    document.getElementById('kfFiltreTemizle').addEventListener('click', function () {
        filtreler = {};
        filtreSatir.querySelectorAll('input[data-f]').forEach(function (i) { i.value = ''; });
        sonucuGuncelle();
    });

    // ── Satıra tıklayınca fiş açılır ──
    function ac(r) { if (r && r.dataset.id) window.location.href = 'kantar_view.php?id=' + encodeURIComponent(r.dataset.id); }
    body.addEventListener('click', function (e) {
        if (window.getSelection && String(window.getSelection()).length > 0) return;   // metin seçiliyorsa açma
        ac(e.target.closest('tr'));
    });
    body.addEventListener('keydown', function (e) {
        var r = e.target.closest('tr');
        if (!r) return;
        if (e.key === 'Enter') { e.preventDefault(); ac(r); }
        else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            var g = satirlar.filter(function (x) { return !x.hidden; });
            var n = g[g.indexOf(r) + (e.key === 'ArrowDown' ? 1 : -1)];
            if (n) n.focus();
        }
    });

    // ── Yazdır: GÖRÜNEN liste (süzgeç + sıralama + gizli sütunlar uygulanmış hâliyle), gizli çerçeve ile ──
    var kacis = function (v) { return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
    function yazdir() {
        var basliklar = Array.prototype.slice.call(head.children).filter(function (th) { return !gizli.has(th.dataset.col); });
        var gorunen = satirlar.filter(function (r) { return !r.hidden; });
        if (!gorunen.length) { durumYaz('Yazdırılacak kayıt yok.'); return; }
        var SAYISAL = ['palet', 'kasa', 't1', 't2', 'brut', 'dara', 'net'];      // sağa yaslı
        var TOPLANAN = ['palet', 'kasa', 'brut', 'dara', 'net'];                 // alt toplam (1./2. tartım toplanmaz)
        var toplam = {};
        var govde = gorunen.map(function (r) {
            return '<tr>' + basliklar.map(function (th) {
                var c = th.dataset.col, td = r.querySelector('td[data-col="' + c + '"]');
                if (TOPLANAN.indexOf(c) >= 0) toplam[c] = (toplam[c] || 0) + (td ? parseFloat(td.dataset.sort) || 0 : 0);
                return '<td class="' + (SAYISAL.indexOf(c) >= 0 ? 'n' : 'm') + '">' + kacis(td ? td.textContent : '') + '</td>';
            }).join('') + '</tr>';
        }).join('');
        var n0 = function (v) { return Math.round(v).toLocaleString('tr-TR'); };
        var alt = '<tr class="t">' + basliklar.map(function (th, i) {
            var c = th.dataset.col;
            if (TOPLANAN.indexOf(c) >= 0) return '<td class="n">' + n0(toplam[c] || 0) + '</td>';
            return '<td>' + (i === 0 ? 'TOPLAM' : '') + '</td>';
        }).join('') + '</tr>';
        // Süzgeç özeti (kullanıcı neyin yazdırıldığını görsün)
        var suz = Object.keys(filtreler).filter(function (c) { return filtreler[c]; }).map(function (c) {
            var th = head.querySelector('th[data-col="' + c + '"]');
            var inp = filtreSatir.querySelector('input[data-f="' + c + '"]');
            return (th ? th.childNodes[0].textContent.trim() : c) + ': ' + (inp ? inp.value.trim() : '');
        });
        var yogun = basliklar.length > 15 ? 8 : basliklar.length > 10 ? 9.5 : 11;   // sütun sayısına göre yazı boyu
        var html = '<!doctype html><html lang="tr"><head><meta charset="utf-8"><title>Mal Kabul Fişleri</title><style>'
            + '@page{size:A4 landscape;margin:9mm 8mm 11mm;@bottom-right{content:"Sayfa " counter(page) " / " counter(pages);font:9px system-ui,Arial,sans-serif;color:#555}}'
            + '*{box-sizing:border-box}body{margin:0;font:' + yogun + 'px/1.3 system-ui,Arial,sans-serif;color:#111;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
            + 'h1{font-size:' + (yogun + 5) + 'px;margin:0 0 2px}p{margin:0 0 7px;color:#555;font-size:' + Math.max(8, yogun - 1) + 'px}'
            + 'table{width:100%;border-collapse:collapse;table-layout:auto}th,td{border:1px solid #8a94a0;padding:2px 4px;vertical-align:top}'
            + 'th{background:#dfe8f2;font-weight:700;text-align:center}thead{display:table-header-group}tfoot{display:table-footer-group}tr{break-inside:avoid;page-break-inside:avoid}'
            + 'td.n{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}td.m{overflow-wrap:anywhere}tbody tr:nth-child(even) td{background:#f6f8fb}'
            + 'tr.t td{font-weight:700;background:#e9eef5!important;border-top:2px solid #444}</style></head><body>'
            + '<h1>Mal Kabul Fişleri</h1><p>' + n0(gorunen.length) + ' fiş' + (satirlar.length !== gorunen.length ? ' (toplam ' + n0(satirlar.length) + ' içinden süzülmüş)' : '')
            + (suz.length ? ' · Süzgeç: ' + kacis(suz.join('; ')) : '') + ' · ' + new Date().toLocaleString('tr-TR') + '</p>'
            + '<table><thead><tr>' + basliklar.map(function (th) { return '<th>' + kacis(th.childNodes[0].textContent.trim()) + '</th>'; }).join('') + '</tr></thead>'
            + '<tbody>' + govde + alt + '</tbody></table></body></html>';
        var cerceve = document.createElement('iframe');
        cerceve.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
        cerceve.srcdoc = html;
        cerceve.onload = function () {
            try { cerceve.contentWindow.focus(); cerceve.contentWindow.print(); }
            finally { setTimeout(function () { cerceve.remove(); }, 60000); }
        };
        document.body.appendChild(cerceve);
    }
    document.getElementById('kfYazdirBtn').addEventListener('click', yazdir);

    // ── Sayfa pencere yüksekliğine ölçeklenir: tablo kalan alanı doldurur, altta boşluk kalmaz (PC + mobil) ──
    function fitPage() {
        var ust = page.getBoundingClientRect().top + window.scrollY;
        if (window.innerWidth < 768) {
            // Mobil: sayfa kaymaz; alt çubuğun (--bn-h + güvenli alan) üstünde biter, yalnız tablo kayar
            var probe = document.createElement('div');
            probe.style.cssText = 'position:fixed;left:-9999px;bottom:0;height:0;padding-bottom:calc(var(--bn-h, 0px) + env(safe-area-inset-bottom, 0px))';
            document.body.appendChild(probe);
            var altBosluk = parseFloat(getComputedStyle(probe).paddingBottom) || 0;
            probe.remove();
            page.style.height = Math.max(240, window.innerHeight - ust - altBosluk - 8) + 'px';
        } else {
            page.style.height = Math.max(320, window.innerHeight - ust - 12) + 'px';
        }
        filtreSatiriKonum();
    }

    gizliUygula();
    varsayilanGenislikler();
    genislikUygula();
    filtreSatiriKonum();
    sonucuGuncelle();
    fitPage();
    window.addEventListener('resize', fitPage);
    window.addEventListener('load', fitPage);
})();
