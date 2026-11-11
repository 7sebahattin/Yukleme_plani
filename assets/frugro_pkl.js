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
    var kp = parseInt(form.getAttribute('data-frp-kp') || '0', 10) || 0;     // siparişten kutu/palet (>0 ise kutu salt okunur)
    var durumEl = q0('[data-frp-durum]');
    function q0(s) { return document.querySelector(s); }
    function durumYaz(tip, satirlar) {
        if (!durumEl) return;
        durumEl.textContent = '';
        satirlar.forEach(function (m) { var d = document.createElement('div'); d.textContent = m; durumEl.appendChild(d); });
        durumEl.className = 'frp-durum frp-durum--' + tip; durumEl.hidden = false;
    }

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
        var lpEl = q('[data-frp-logger]', form), lp = lpEl ? lpEl.value.trim() : '';
        satirlar().forEach(function (tr, i) {
            var k = kutuCoz(girdi(tr, 'kutu').value), b = kgCoz(girdi(tr, 'brut_kg').value), nt = kgCoz(girdi(tr, 'net_kg').value);
            var sira = q('[data-frp-sira]', tr); if (sira) sira.textContent = String(i + 1);
            var no = q('[data-frp-no]', tr); if (no) no.textContent = String(i + 1);
            var pn = girdi(tr, 'palet_no'); if (pn) pn.value = String(i + 1);           // sunucu da 1..N atar (POST değeri yok sayılır)
            tr.classList.toggle('frp-logger', lp !== '' && lp === String(i + 1));
            [['kutu', k], ['brut_kg', b], ['net_kg', nt]].forEach(function (p) {
                var el = girdi(tr, p[0]); if (el) { if (p[1] !== p[1]) el.setAttribute('aria-invalid', 'true'); else el.removeAttribute('aria-invalid'); }
            });
            var bosSatir = ['ggn_adi'].every(function (a) { return girdi(tr, a).value.trim() === ''; }) && k === null && b === null && nt === null;
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

    // ── GGN: ad select'ten seçilir, no ayardan gelir (salt okunur) ──
    function ggnYaz(tr, ad, no) {
        var sel = girdi(tr, 'ggn_adi'), nm = girdi(tr, 'ggn_no');
        if (!sel || !nm) return;
        var var_ = false;
        Array.prototype.forEach.call(sel.options, function (o) { if (o.value === ad) var_ = true; });
        if (!var_ && ad !== '') { var o = document.createElement('option'); o.value = ad; o.textContent = '(kayıtlı) ' + ad; o.setAttribute('data-ggn', no); sel.appendChild(o); }
        sel.value = ad; nm.value = ad === '' ? '' : no;
    }
    function asagiUygula(tr) {
        var liste = satirlar(), i = liste.indexOf(tr);
        if (i < 0) return;
        var ad = girdi(tr, 'ggn_adi').value, no = girdi(tr, 'ggn_no').value;
        for (var j = i + 1; j < liste.length; j++) ggnYaz(liste[j], ad, no);       // yalnız ALTTAKİLER değişir
        kirliyap(); topla();
    }
    form.addEventListener('change', function (e) {
        var el = e.target;
        if (el && el.matches && el.matches('select[data-frp-alan="ggn_adi"]')) {
            var o = el.options[el.selectedIndex], tr = el.closest('tr');
            girdi(tr, 'ggn_no').value = o ? (o.getAttribute('data-ggn') || '') : '';
            kirliyap(); topla();
        }
    });

    function satirEkle(deger) {
        if (satirlar().length >= MAX) return null;
        var html = sablon.innerHTML.replace(/__I__/g, String(sayac++));
        var kap = document.createElement('tbody'); kap.innerHTML = html;
        var tr = kap.firstElementChild;
        if (deger) Object.keys(deger).forEach(function (a) { var e = girdi(tr, a); if (e && deger[a] != null) e.value = deger[a]; });
        if (kp) { var kx = girdi(tr, 'kutu'); if (kx) kx.value = String(kp); }
        govde.appendChild(tr);
        return tr;
    }

    form.addEventListener('input', function () { kirliyap(); topla(); });
    form.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-frp-ekle],[data-frp-asagi],[data-frp-sil],[data-frp-oner],[data-frp-tum-oner],[data-frp-ac]') : null;
        if (!t) return;
        if (t.hasAttribute('data-frp-ekle')) { var tr = satirEkle(null); if (tr) { var son = satirlar().length > 1 ? satirlar()[satirlar().length - 2] : null; if (son) { ggnYaz(tr, girdi(son, 'ggn_adi').value, girdi(son, 'ggn_no').value); if (!kp) girdi(tr, 'kutu').value = girdi(son, 'kutu').value; } kirliyap(); topla(); girdi(tr, 'ggn_adi').focus(); } return; }
        if (t.hasAttribute('data-frp-asagi')) { asagiUygula(t.closest('tr')); return; }
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

    // ── Excelden yapıştır (frgp_yapistir_coz aynası; mesajlar PHP ile birebir) ──
    function yapistirCoz(metin, hedef) {
        var ln = metin.replace(/\r\n?/g, '\n').split('\n').filter(function (x) { return x.replace(/[\s ]/g, '') !== ''; })
            .map(function (x) { return x.replace(/\t+$/, '').split('\t').map(function (c) { return c.replace(/^[\s ]+|[\s ]+$/g, ''); }); });
        var h = [];
        hedef = hedef === 'net_kg' ? 'net_kg' : 'brut_kg';
        if (!ln.length) return { ok: false, hatalar: ['Yapıştırılacak veri yok.'] };
        if (ln.length > MAX) return { ok: false, hatalar: ['En çok ' + MAX + ' satır yapıştırılabilir.'] };
        var n = ln[0].length;
        if (ln.some(function (x) { return x.length !== n; })) return { ok: false, hatalar: ['Satırların sütun sayısı aynı değil (1 ya da 2 sütun olmalı).'] };
        if (n === 2 && hedef !== 'brut_kg') return { ok: false, hatalar: ['Hedef Net iken tek sütun yapıştırın (2 sütun yalnız Brüt hedefinde: brüt | net).'] };
        var mod = { 1: 'tek', 2: 'brut_net' }[n];
        if (!mod) return { ok: false, hatalar: ['Sütun sayısı ' + n + ' desteklenmiyor — 1 sütun (hedef sütun) ya da 2 sütun (brüt | net).'] };
        var out = ln.map(function (c, i) {
            var brut, net;
            if (mod === 'brut_net') { brut = c[0]; net = c[1]; } else if (hedef === 'net_kg') { brut = ''; net = c[0]; } else { brut = c[0]; net = ''; }
            [['brüt', brut], ['net', net]].forEach(function (p) { if (p[1] !== '' && kgCoz(p[1]) !== kgCoz(p[1])) h.push('Satır ' + (i + 1) + ': ' + p[0] + ' kg geçersiz (\'' + p[1].slice(0, 20) + '\') — ondalık için virgül kullanın, nokta binliktir.'); });
            return { brut_kg: brut, net_kg: net };
        });
        return h.length ? { ok: false, mod: mod, hatalar: h.slice(0, 10) } : { ok: true, mod: mod, satirlar: out };
    }
    // başlangıç satırından yaz; satır EKLENMEZ, fazlası atılır
    function yapistirUygula(r, bas, hedef) {
        var liste = satirlar(), yaz = 0;
        r.satirlar.forEach(function (d, i) {
            var tr = liste[bas + i]; if (!tr) return;
            if (r.mod === 'brut_net') { girdi(tr, 'brut_kg').value = d.brut_kg; girdi(tr, 'net_kg').value = d.net_kg; } else girdi(tr, hedef).value = d[hedef];
            yaz++;
        });
        var fazla = r.satirlar.length - yaz;
        kirliyap(); topla();
        var m = ['✓ ' + yaz + ' satır dolduruldu (' + (r.mod === 'brut_net' ? 'Brüt + Net' : (hedef === 'net_kg' ? 'Net' : 'Brüt')) + '). Kaydetmek için «Kaydet» düğmesine basın.'], tip = 'ok';
        if (fazla > 0) { m.push('Uyarı: ' + fazla + ' satır fazla geldi, yok sayıldı (tabloda yalnız ' + yaz + ' satır kalmıştı).'); tip = 'uyari'; }
        durumYaz(tip, m);
        return { yazilan: yaz, fazla: fazla };
    }
    // hücreye doğrudan yapıştırma (OGL eşi): Brüt/Net hücresinde HER yapıştırma yakalanır; hata → hiçbir hücre değişmez
    govde.addEventListener('paste', function (e) {
        var el = e.target;
        if (!duz || !el || !el.matches || !el.matches('input[data-frp-alan="brut_kg"],input[data-frp-alan="net_kg"]')) return;
        var cd = e.clipboardData || window.clipboardData, metin = cd ? cd.getData('text') : '';
        if (metin === '') return;
        e.preventDefault();
        var hedef = el.getAttribute('data-frp-alan'), r = yapistirCoz(metin, hedef);
        if (!r.ok) { durumYaz('hata', ['Yapıştırma uygulanmadı — hiçbir hücre değişmedi:'].concat(r.hatalar.slice(0, 4))); return; }
        yapistirUygula(r, satirlar().indexOf(el.closest('tr')), hedef);
    });

    function dlgAc(d) { if (!d) return; if (typeof d.showModal === 'function') { if (!d.open) d.showModal(); } else d.setAttribute('open', ''); var t = q('textarea', d); if (t) t.focus(); }
    function dlgKapat(d) { if (!d) return; if (typeof d.close === 'function') d.close(); else d.removeAttribute('open'); }
    var dlg = document.getElementById('frpYapistirDlg');
    if (dlg) {
        var ta = q('[data-frp-yapistir-metin]', dlg), hedSel = q('[data-frp-yap-hedef]', dlg), basSel = q('[data-frp-yap-satir]', dlg), onz = q('[data-frp-yap-onizleme]', dlg), hata = q('[data-frp-yapistir-hata]', dlg);
        var uygBtn = q('[data-frp-yapistir-uygula]', dlg);
        function onizle() {
            onz.textContent = ''; var ekle = function (t) { var d = document.createElement('div'); d.textContent = t; onz.appendChild(d); };
            if (ta.value.trim() === '') { ekle('Henüz bir şey yapıştırılmadı.'); uygBtn.disabled = true; return; }
            var r = yapistirCoz(ta.value, hedSel.value);
            if (!r.ok) { r.hatalar.slice(0, 4).forEach(function (m) { ekle('• ' + m); }); uygBtn.disabled = true; return; }
            var bas = parseInt(basSel.value, 10) || 0, uygun = satirlar().length - bas;
            ekle(r.satirlar.length + ' satır · ' + (r.mod === 'brut_net' ? 'Brüt | Net' : (hedSel.value === 'net_kg' ? 'Net' : 'Brüt')) + ' · ilk satır: ' + r.satirlar[0].brut_kg + (r.mod === 'brut_net' ? ' | ' + r.satirlar[0].net_kg : (hedSel.value === 'net_kg' ? r.satirlar[0].net_kg : '')));
            if (r.satirlar.length > uygun) ekle('⚠ Seçilen satırdan itibaren yalnız ' + uygun + ' palet satırı var: fazla ' + (r.satirlar.length - uygun) + ' satır yok sayılacak.');
            else if (r.satirlar.length < uygun) ekle('⚠ Kalan ' + (uygun - r.satirlar.length) + ' satıra dokunulmayacak.');
            uygBtn.disabled = false;
        }
        function dlgHazirla() {
            var mevcut = basSel.value; basSel.textContent = '';
            satirlar().forEach(function (tr, i) { var o = document.createElement('option'); o.value = String(i); o.textContent = (i + 1) + '. satır · Palet ' + (i + 1); basSel.appendChild(o); });
            if (mevcut && basSel.options[+mevcut]) basSel.value = mevcut;
            ta.value = ''; hata.hidden = true; onizle();
        }
        ta.addEventListener('input', onizle); hedSel.addEventListener('change', onizle); basSel.addEventListener('change', onizle);
        document.addEventListener('click', function (e) { var t = e.target.closest ? e.target.closest('[data-frp-ac="frpYapistirDlg"]') : null; if (t) dlgHazirla(); }, true);
        dlg.addEventListener('click', function (e) {
            var t = e.target.closest ? e.target.closest('[data-frp-kapat],[data-frp-yapistir-uygula]') : null;
            if (!t) return;
            if (t.hasAttribute('data-frp-kapat')) { dlgKapat(dlg); return; }
            var r = yapistirCoz(ta.value, hedSel.value);
            if (!r.ok) { hata.innerHTML = ''; r.hatalar.forEach(function (m) { var li = document.createElement('li'); li.textContent = m; hata.appendChild(li); }); hata.hidden = false; return; }
            hata.hidden = true;
            yapistirUygula(r, parseInt(basSel.value, 10) || 0, hedSel.value);
            ta.value = ''; dlgKapat(dlg);
        });
        dlg.addEventListener('submit', function (e) { e.preventDefault(); });
    }
    topla();
})();
