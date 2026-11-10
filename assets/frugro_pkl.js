/* Frugro PKL ekranı (frugro_pkl.php). Satır içi onclick YOK; öğe yoksa etkisiz.
   Sayı kuralları config/frugro_pkl.php (frgp_kg_coz / frgp_kutu_coz / frgp_kucuk_coz / frgp_yapistir_coz) ile AYNI — ikisini birlikte değiştir.
   Kg: nokta = BİNLİK, virgül = ONDALIK ("812.5" reddedilir). Canlı Σ yalnız önizleme; sunucu her kayıtta yeniden hesaplar. */
(function () {
    'use strict';
    var form = document.querySelector('[data-frp-form]');
    if (!form) return;
    var duz = form.getAttribute('data-frp-duz') === '1';
    var govde = form.querySelector('[data-frp-govde]');
    var sablon = document.getElementById('frpSatirSablon');
    var sayac = govde.querySelectorAll('tr').length + 1000;
    var MAX = 200;

    function q(s, k) { return (k || document).querySelector(s); }
    function qa(s, k) { return Array.prototype.slice.call((k || document).querySelectorAll(s)); }

    // ── sayı çözücüler: null = boş, NaN = geçersiz ──
    function kgCoz(v) {
        var s = String(v == null ? '' : v).replace(/[\s ]/g, '');
        if (s === '') return null;
        var f;
        if (/^\d+$/.test(s)) f = parseFloat(s);
        else if (/^\d{1,3}(\.\d{3})+$/.test(s)) f = parseFloat(s.replace(/\./g, ''));
        else if (/^(\d+|\d{1,3}(\.\d{3})+),\d{1,3}$/.test(s)) f = parseFloat(s.replace(/\./g, '').replace(',', '.'));
        else return NaN;
        return f < 10000000 ? f : NaN;
    }
    function kutuCoz(v) {
        var s = String(v == null ? '' : v).replace(/[\s ]/g, '');
        if (s === '') return null;
        var n;
        if (/^\d+$/.test(s)) n = parseInt(s, 10);
        else if (/^\d{1,3}(\.\d{3})+$/.test(s)) n = parseInt(s.replace(/\./g, ''), 10);
        else return NaN;
        return n > 0 && n <= 1000000 ? n : NaN;
    }
    function kucukCoz(v, ust) {
        var s = String(v == null ? '' : v).replace(/[\s ]/g, '');
        if (s === '') return null;
        if (!/^\d{1,4}(,\d{1,3})?$/.test(s)) return NaN;
        var f = parseFloat(s.replace(',', '.'));
        return f <= ust ? f : NaN;
    }
    function goster(n) { return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
    function alanKg(n) { return n === Math.floor(n) ? String(n) : (Math.round(n * 1000) / 1000).toString().replace('.', ','); }

    function satirlar() { return qa('tr[data-frp-satir]', govde); }
    function girdi(tr, ad) { return q('[data-frp-alan="' + ad + '"]', tr); }

    function topla() {
        var kutu = 0, brut = 0, net = 0, n = 0;
        satirlar().forEach(function (tr, i) {
            var k = kutuCoz(girdi(tr, 'kutu').value), b = kgCoz(girdi(tr, 'brut_kg').value), nt = kgCoz(girdi(tr, 'net_kg').value);
            var sira = q('[data-frp-sira]', tr); if (sira) sira.textContent = String(i + 1);
            [['kutu', k], ['brut_kg', b], ['net_kg', nt]].forEach(function (p) {
                var el = girdi(tr, p[0]); if (el) { if (p[1] !== p[1]) el.setAttribute('aria-invalid', 'true'); else el.removeAttribute('aria-invalid'); }
            });
            var bosSatir = ['palet_no', 'ggn_no', 'ggn_adi'].every(function (a) { return girdi(tr, a).value.trim() === ''; }) && k === null && b === null && nt === null;
            if (!bosSatir) n++;
            if (k === k && k !== null) kutu += k;
            if (b === b && b !== null) brut += b;
            if (nt === nt && nt !== null) net += nt;
        });
        var set = function (a, v) { var e = q('[data-frp-' + a + ']', form); if (e) e.textContent = v; };
        set('n', String(n)); set('tkutu', goster(kutu)); set('tbrut', goster(brut)); set('tnet', goster(net));
    }

    var kirli = false;
    function kirliyap() {
        if (kirli || !duz) return;
        kirli = true;
        var m = q('[data-frp-kirli]', form); if (m) m.hidden = false;
        qa('[data-frp-uretbtn]').forEach(function (b) { b.disabled = true; b.title = 'Önce kaydedin'; });
    }

    // ── net önerisi: brüt − (kutu × karton + palet diğer) ──
    function netOner(tr) {
        var kar = kucukCoz(q('[data-frp-karton]', form).value, 5), pd = kucukCoz(q('[data-frp-pdiger]', form).value, 500);
        var k = kutuCoz(girdi(tr, 'kutu').value), b = kgCoz(girdi(tr, 'brut_kg').value);
        if (kar === null || kar !== kar || pd !== pd || k === null || k !== k || b === null || b !== b) return false;
        girdi(tr, 'net_kg').value = alanKg(Math.max(0, Math.round((b - (k * kar + (pd || 0))) * 1000) / 1000));
        return true;
    }

    function satirEkle(deger) {
        if (satirlar().length >= MAX) return null;
        var html = sablon.innerHTML.replace(/__I__/g, String(sayac++));
        var kap = document.createElement('tbody'); kap.innerHTML = html;
        var tr = kap.firstElementChild;
        if (deger) Object.keys(deger).forEach(function (a) { var e = girdi(tr, a); if (e && deger[a] != null) e.value = deger[a]; });
        govde.appendChild(tr);
        return tr;
    }

    form.addEventListener('input', function () { kirliyap(); topla(); });
    form.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-frp-ekle],[data-frp-sil],[data-frp-oner],[data-frp-tum-oner],[data-frp-ac]') : null;
        if (!t) return;
        if (t.hasAttribute('data-frp-ekle')) { var tr = satirEkle(null); if (tr) { var son = satirlar().length > 1 ? satirlar()[satirlar().length - 2] : null; if (son) { girdi(tr, 'ggn_no').value = girdi(son, 'ggn_no').value; girdi(tr, 'ggn_adi').value = girdi(son, 'ggn_adi').value; girdi(tr, 'kutu').value = girdi(son, 'kutu').value; } kirliyap(); topla(); girdi(tr, 'palet_no').focus(); } return; }
        if (t.hasAttribute('data-frp-sil')) { var r = t.closest('tr'); if (r) r.parentNode.removeChild(r); kirliyap(); topla(); return; }
        if (t.hasAttribute('data-frp-oner')) { if (netOner(t.closest('tr'))) { kirliyap(); topla(); } else alert('Net önerisi için kutu, brüt ve karton kg gerekli (virgül kuralı).'); return; }
        if (t.hasAttribute('data-frp-tum-oner')) { var n = 0; satirlar().forEach(function (tr) { if (girdi(tr, 'net_kg').value.trim() === '' && netOner(tr)) n++; }); if (n) { kirliyap(); topla(); } return; }
        if (t.hasAttribute('data-frp-ac')) { e.preventDefault(); dlgAc(document.getElementById(t.getAttribute('data-frp-ac'))); }
    });

    // Enter / ↓ alt satır, Shift+Enter / ↑ üst satır (form ASLA gönderilmez; Enter textarea dışında)
    form.addEventListener('keydown', function (e) {
        var el = e.target;
        if (!el || !el.matches || !el.matches('input[data-frp-alan]')) { if (e.key === 'Enter' && el && el.matches && el.matches('input') && !el.matches('[type=submit]')) e.preventDefault(); return; }
        var yon = (e.key === 'Enter' && !e.shiftKey) || e.key === 'ArrowDown' ? 1 : ((e.key === 'Enter' && e.shiftKey) || e.key === 'ArrowUp' ? -1 : 0);
        if (!yon) return;
        e.preventDefault();
        var tr = el.closest('tr'), liste = satirlar(), i = liste.indexOf(tr) + yon;
        if (i >= 0 && i < liste.length) { var h = girdi(liste[i], el.getAttribute('data-frp-alan')); if (h) { h.focus(); h.select(); } }
    });

    // ── Excelden yapıştır (frgp_yapistir_coz aynası) ──
    function yapistirCoz(metin) {
        var ln = metin.replace(/\r\n?/g, '\n').split('\n').filter(function (x) { return x.replace(/[\s ]/g, '') !== ''; })
            .map(function (x) { return x.replace(/\t+$/, '').split('\t').map(function (c) { return c.trim(); }); });
        var h = [];
        if (!ln.length) return { ok: false, hatalar: ['Yapıştırılacak veri yok.'] };
        if (ln.length > MAX) return { ok: false, hatalar: ['En çok ' + MAX + ' satır yapıştırılabilir.'] };
        var n = ln[0].length;
        if (ln.some(function (x) { return x.length !== n; })) return { ok: false, hatalar: ['Satırların sütun sayısı aynı değil (1, 2 ya da 6 sütun olmalı).'] };
        var mod = { 1: 'brut', 2: 'brut_net', 6: 'tam' }[n];
        if (!mod) return { ok: false, hatalar: ['Sütun sayısı ' + n + ' desteklenmiyor — 1 (brüt), 2 (brüt | net) ya da 6 (palet no | GGN no | GGN adı | kutu | brüt | net).'] };
        var out = ln.map(function (c, i) {
            var r = {};
            if (mod === 'tam') { r.palet_no = c[0]; r.ggn_no = c[1]; r.ggn_adi = c[2]; r.kutu = c[3]; r.brut_kg = c[4]; r.net_kg = c[5]; if (c[3] !== '' && !(kutuCoz(c[3]) > 0)) h.push('Satır ' + (i + 1) + ': kutu geçersiz.'); }
            else { r.brut_kg = c[0]; r.net_kg = mod === 'brut_net' ? c[1] : ''; }
            ['brut_kg', 'net_kg'].forEach(function (a) { if (r[a] !== '' && kgCoz(r[a]) !== kgCoz(r[a])) h.push('Satır ' + (i + 1) + ': ' + (a === 'brut_kg' ? 'brüt' : 'net') + ' kg geçersiz (\'' + r[a].slice(0, 20) + '\') — ondalık için virgül kullanın, nokta binliktir.'); });
            return r;
        });
        return h.length ? { ok: false, hatalar: h.slice(0, 10) } : { ok: true, mod: mod, satirlar: out };
    }

    function dlgAc(d) { if (!d) return; if (typeof d.showModal === 'function') { if (!d.open) d.showModal(); } else d.setAttribute('open', ''); var t = q('textarea', d); if (t) t.focus(); }
    function dlgKapat(d) { if (!d) return; if (typeof d.close === 'function') d.close(); else d.removeAttribute('open'); }
    var dlg = document.getElementById('frpYapistirDlg');
    if (dlg) {
        dlg.addEventListener('click', function (e) {
            var t = e.target.closest ? e.target.closest('[data-frp-kapat],[data-frp-yapistir-uygula]') : null;
            if (!t) return;
            if (t.hasAttribute('data-frp-kapat')) { dlgKapat(dlg); return; }
            var hata = q('[data-frp-yapistir-hata]', dlg), r = yapistirCoz(q('[data-frp-yapistir-metin]', dlg).value);
            if (!r.ok) { hata.innerHTML = ''; r.hatalar.forEach(function (m) { var li = document.createElement('li'); li.textContent = m; hata.appendChild(li); }); hata.hidden = false; return; }
            hata.hidden = true;
            if (r.mod === 'tam') {
                var ilk = satirlar()[0], kal = ilk ? girdi(ilk, 'kalibre').value : '', lot = ilk ? girdi(ilk, 'lot').value : '';
                satirlar().forEach(function (tr) { tr.parentNode.removeChild(tr); });
                r.satirlar.forEach(function (d) { d.kalibre = kal; d.lot = lot; satirEkle(d); });
            } else {
                r.satirlar.forEach(function (d, i) { var tr = satirlar()[i] || satirEkle(null); if (!tr) return; girdi(tr, 'brut_kg').value = d.brut_kg; if (r.mod === 'brut_net') girdi(tr, 'net_kg').value = d.net_kg; });
            }
            q('[data-frp-yapistir-metin]', dlg).value = '';
            kirliyap(); topla(); dlgKapat(dlg);
        });
        dlg.addEventListener('submit', function (e) { e.preventDefault(); });
    }
    topla();
})();
