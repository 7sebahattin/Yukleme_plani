/* =========================================================
   assets/hizli_kart.js — Ortak "Hızlı Cari / Stok Ekle" penceresi (v611 Faz 2; işaretleme config/hizli_kart_ui.php, uç api_hizli_kart.php).
   API: window.HizliKart.ac({tur:'cari'|'stok', ad, tip, kartTip:'urun'|'malzeme'|'diger', ambalajKod:'kasa'|'palet', sonra: function (kart) {…}})
          → true (açıldı) / false (bu sayfada o tür kullanılamaz: izin ya da şema yok).
        window.HizliKart.kullanilabilir(tur) · .kapat() · .secenekleriYenile() (uçtan ?islem=secenekler ile listeleri tazeler, Promise).
   Düğme kancası: [data-hk-ac="cari|stok"] (+ data-hk-metin / data-hk-tip / data-hk-kart-tip / data-hk-ambalaj / data-hk-git="sayfa.php?id={id}").
   Kurallar: kullanıcı verisi YALNIZ textContent / value ile yazılır (HTML dizgisi olarak DOM'a yazılmaz); Esc/İptal kapatır, odak pencerede kalır, Enter gönderir;
   yinelenen tıklamaya karşı düğme kilidi + pencere başına istek_id (uç aynı anahtara saklı yanıtı döner, ikinci kart açılmaz).
   ========================================================= */
(function () {
    'use strict';
    if (window.HizliKart) return;

    var ov = document.getElementById('hkModal');
    var form = ov ? ov.querySelector('#hkForm') : null;
    var API = (ov && ov.getAttribute('data-hk-api')) || 'api_hizli_kart.php';
    var BILDIRIM_ANAHTAR = 'hk_bildirim';
    var ODAK = 'button:not([disabled]), input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])';
    var secenek = {};
    var acikTur = null, sonraFn = null, gitSablon = '', acan = null, kilit = false, istekId = '', mdown = null, kirli = false;
    var M = {
        baslik: { cari: 'Hızlı cari ekle', stok: 'Hızlı stok kartı ekle' },
        basari: { cari: 'Hızlı eklendi — eksikleri Cari Kartları\'ndan tamamlayın', stok: 'Hızlı eklendi — eksikleri Stok Kartları\'ndan tamamlayın' },
        zorunlu: 'Zorunlu alan.', secin: 'Seçin…', yok: '— yok —', ag: 'Bağlantı hatası; tekrar deneyin.',
        sunucu: 'Kaydedilemedi; tekrar deneyin.', csrf: 'Oturum doğrulaması başarısız; sayfayı yenileyip tekrar deneyin.',
        dara: 'Ambalaj tipi seçiliyse birim dara zorunlu (0 kg\'dan büyük).', hksBos: 'Eşleşen HKS ürünü yok', hksYok: 'HKS ürün kataloğu okunamadı; alan şimdilik pasif.'
    };

    function $(s, k) { return (k || document).querySelector(s); }
    function $$(s, k) { return Array.prototype.slice.call((k || document).querySelectorAll(s)); }
    function gorunur(e) { return !!(e && e.getClientRects && e.getClientRects().length); }
    function katla(s) { return String(s || '').toLocaleLowerCase('tr').replace(/\s+/g, ' ').trim(); }

    /* ═════════ Bildirim ═════════ */
    var toastZ = null;
    function toast(msg, hata) {
        var t = $('.hk-toast');
        if (!t) { t = document.createElement('div'); t.className = 'hk-toast'; t.setAttribute('role', 'status'); t.setAttribute('aria-live', 'polite'); document.body.appendChild(t); }
        t.textContent = msg;
        t.classList.toggle('hk-toast--hata', !!hata);
        t.hidden = false;
        clearTimeout(toastZ);
        toastZ = setTimeout(function () { t.hidden = true; }, hata ? 5000 : 4200);
    }
    try {   // önceki sayfadan (data-hk-git yönlendirmesi) taşınan bildirim
        var tb = window.sessionStorage.getItem(BILDIRIM_ANAHTAR);
        if (tb) { window.sessionStorage.removeItem(BILDIRIM_ANAHTAR); toast(tb); }
    } catch (x) { /* depolama kapalı */ }

    /* ═════════ Seçenekler ═════════ */
    (function () {
        var el = document.getElementById('hkSecenek');
        if (!el) return;
        try { secenek = JSON.parse(el.textContent || '{}') || {}; } catch (x) { secenek = {}; }
    })();
    function liste(yol) {
        var p = String(yol).split('.'), o = secenek[p[0]];
        return o && Array.isArray(o[p[1]]) ? o[p[1]] : [];
    }
    function secenekEkle(sel, deger, metin, ek) {
        var o = document.createElement('option');
        o.value = deger; o.textContent = metin;
        if (ek) Object.keys(ek).forEach(function (k) { o.setAttribute(k, ek[k]); });
        sel.appendChild(o);
    }
    function listeleriKur() {
        if (!form) return;
        $$('select[data-hk-liste]', form).forEach(function (sel) {
            var yol = sel.getAttribute('data-hk-liste'), eski = sel.value;
            while (sel.firstChild) sel.removeChild(sel.firstChild);
            if (yol === 'cari.tipler' || yol === 'stok.kart_tipleri') secenekEkle(sel, '', M.secin);
            if (yol === 'stok.ambalaj_tipleri' || yol === 'stok.gruplar') secenekEkle(sel, '', M.yok);
            liste(yol).forEach(function (x) {
                if (yol === 'stok.ambalaj_tipleri') secenekEkle(sel, String(x.id), x.ad, { 'data-kod': x.kod || '' });
                else if (yol === 'stok.gruplar') secenekEkle(sel, String(x.id), x.ad);
                else secenekEkle(sel, x.kod, x.ad);
            });
            if (eski) sel.value = eski;
        });
    }
    listeleriKur();
    function kullanilabilir(tur) {
        return !!(form && secenek[tur] && secenek[tur].hazir && secenek[tur].izin && $('[data-hk-bolum="' + tur + '"]', form));
    }
    function secenekleriYenile() {
        return fetch(API + '?islem=secenekler', { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.ok) return false;
                ['cari', 'stok'].forEach(function (t) { if (j[t] && secenek[t]) secenek[t] = j[t]; });
                listeleriKur();
                return true;
            });
    }

    /* ═════════ Alan yardımcıları ═════════ */
    function bolum(tur) { return form ? $('[data-hk-bolum="' + tur + '"]', form) : null; }
    function alanKabi(ad) { var b = bolum(acikTur); return b ? b.querySelector('[data-hk-alan="' + ad + '"]') : null; }
    function hatalariTemizle() {
        if (!form) return;
        $$('.hk-alan--hata', form).forEach(function (k) { k.classList.remove('hk-alan--hata'); });
        $$('.hk-alan-hata', form).forEach(function (s) { s.textContent = ''; s.hidden = true; });
        $$('[aria-invalid]', form).forEach(function (e) { e.removeAttribute('aria-invalid'); });
        var g = $('.hk-hata-genel', form); if (g) { g.textContent = ''; g.hidden = true; }
    }
    function alanHata(ad, msg) {
        var k = alanKabi(ad);
        if (!k) return false;
        k.classList.add('hk-alan--hata');
        var s = k.querySelector('.hk-alan-hata'); if (s) { s.textContent = msg; s.hidden = false; }
        var g = k.querySelector('input:not([type=hidden]), select'); if (g) g.setAttribute('aria-invalid', 'true');
        return true;
    }
    function genelHata(msg) { var g = $('.hk-hata-genel', form); if (g) { g.textContent = msg; g.hidden = false; } }
    function ilkHataOdak() {
        var k = $('.hk-alan--hata', bolum(acikTur));
        var g = k && k.querySelector('input:not([type=hidden]), select');
        if (g) g.focus();
    }

    /* Müstahsil vurgusu (cari) */
    function mustahsilUygula() {
        var b = bolum('cari'); if (!b) return;
        var tip = (b.querySelector('[name="tip"]') || {}).value || '';
        var m = tip === ((secenek.cari && secenek.cari.mustahsil) || 'mustahsil');
        $$('[data-hk-vurgu]', b).forEach(function (k) { k.classList.toggle('hk-vurgu', m); });
        $$('[data-hk-vurgu-ipucu]', b).forEach(function (s) { s.hidden = !m; });
        var n = $('[data-hk-mustahsil-not]', b); if (n) n.hidden = !m;
    }
    /* Stok: tip → HKS alanı, ambalaj → dara zorunlu işareti */
    function stokUygula() {
        var b = bolum('stok'); if (!b) return;
        var tip = (b.querySelector('[name="tip"]') || {}).value || '';
        var hk = $('[data-hk-hks-alan]', b); if (hk) hk.hidden = tip !== 'urun';
        var amb = (b.querySelector('[name="ambalaj_tip_id"]') || {}).value || '';
        var z = $('[data-hk-dara-zorunlu]', b); if (z) z.hidden = amb === '';
        var inp = b.querySelector('[name="birim_dara_kg"]'); if (inp) { if (amb) inp.setAttribute('aria-required', 'true'); else inp.removeAttribute('aria-required'); }
        var ara = $('#hkHksAra', b), ip = $('[data-hk-hks-ipucu]', b), kat = secenek.stok && secenek.stok.hks;
        if (ara && !(kat && kat.var)) { ara.disabled = true; ara.placeholder = 'Katalog okunamadı'; if (ip) ip.textContent = M.hksYok; }
    }

    /* ═════════ HKS ürün: yazınca süzülen liste ═════════ */
    var hksAktif = -1;
    function hksListe() { return $('#hkHksListe', form); }
    function hksKapat() { var l = hksListe(), a = $('#hkHksAra', form); if (l) l.hidden = true; if (a) { a.setAttribute('aria-expanded', 'false'); a.removeAttribute('aria-activedescendant'); } hksAktif = -1; }
    function hksSec(u) {
        var a = $('#hkHksAra', form), gz = form.querySelector('[name="hks_urun_id"]');
        if (a) a.value = u.ad;
        if (gz) gz.value = u.id;
        hksKapat();
    }
    function hksCiz() {
        var a = $('#hkHksAra', form), l = hksListe();
        if (!a || !l) return;
        var q = katla(a.value), kat = (secenek.stok && secenek.stok.hks && secenek.stok.hks.urunler) || [];
        while (l.firstChild) l.removeChild(l.firstChild);
        hksAktif = -1;
        if (q === '') { hksKapat(); return; }
        var es = kat.filter(function (u) { return katla(u.ad).indexOf(q) !== -1; }).slice(0, 30);
        if (!es.length) {
            var b = document.createElement('li'); b.className = 'hk-hks-bos'; b.textContent = M.hksBos; b.setAttribute('aria-disabled', 'true'); l.appendChild(b);
        }
        es.forEach(function (u, i) {
            var li = document.createElement('li');
            li.id = 'hkHksSecenek' + i; li.setAttribute('role', 'option'); li.setAttribute('aria-selected', 'false');
            var s = document.createElement('span'); s.textContent = u.ad; li.appendChild(s);
            var k = document.createElement('small'); k.textContent = '#' + u.id; li.appendChild(k);
            li.addEventListener('mousedown', function (e) { e.preventDefault(); hksSec(u); });
            li._hk = u;
            l.appendChild(li);
        });
        l.hidden = false; a.setAttribute('aria-expanded', 'true');
    }
    function hksIsaretle(i) {
        var o = $$('li[role=option]', hksListe());
        if (!o.length) return;
        hksAktif = (i + o.length) % o.length;
        o.forEach(function (x, j) { x.setAttribute('aria-selected', j === hksAktif ? 'true' : 'false'); });
        $('#hkHksAra', form).setAttribute('aria-activedescendant', o[hksAktif].id);
        if (o[hksAktif].scrollIntoView) o[hksAktif].scrollIntoView({ block: 'nearest' });
    }

    /* ═════════ Aç / kapat ═════════ */
    function rastgeleId() {
        var a = new Uint8Array(12);
        if (window.crypto && window.crypto.getRandomValues) window.crypto.getRandomValues(a); else for (var i = 0; i < a.length; i++) a[i] = Math.floor(Math.random() * 256);
        return 'hk' + Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }
    function bolumSifirla(b) {
        $$('input:not([type=hidden]), select', b).forEach(function (e) { if (e.tagName === 'SELECT') e.selectedIndex = 0; else e.value = ''; });
        $$('input[type=hidden]', b).forEach(function (e) { e.value = ''; });
    }
    function secimYap(sel, deger) {
        if (!sel || !deger) return;
        for (var i = 0; i < sel.options.length; i++) if (sel.options[i].value === deger) { sel.selectedIndex = i; return; }
    }
    function ac(o) {
        o = o || {};
        var tur = o.tur === 'stok' ? 'stok' : (o.tur === 'cari' ? 'cari' : '');
        if (!tur || !kullanilabilir(tur)) { if (window.console) console.warn('HizliKart: bu sayfada kullanılamaz:', o.tur); return false; }
        if (acikTur) kapat(true);
        acikTur = tur; sonraFn = typeof o.sonra === 'function' ? o.sonra : null; gitSablon = typeof o.git === 'string' ? o.git : '';
        acan = o.acan || document.activeElement; istekId = rastgeleId(); kilit = false; kirli = false;
        ['cari', 'stok'].forEach(function (t) { var b = bolum(t); if (b) { b.hidden = t !== tur; b.disabled = t !== tur; } });
        var b = bolum(tur);
        bolumSifirla(b);
        hatalariTemizle();
        $('#hkBaslik', ov).textContent = M.baslik[tur];
        var ad = typeof o.ad === 'string' ? o.ad.trim().slice(0, 200) : '';
        if (tur === 'cari') {
            b.querySelector('[name="unvan"]').value = ad;
            secimYap(b.querySelector('[name="tip"]'), o.tip ? String(o.tip) : '');
            mustahsilUygula();
        } else {
            b.querySelector('[name="ad"]').value = ad;
            var kt = o.kartTip ? String(o.kartTip) : 'urun';
            secimYap(b.querySelector('[name="tip"]'), kt);
            secimYap(b.querySelector('[name="ana_birim"]'), kt === 'urun' ? 'kg' : 'adet');
            if (o.ambalajKod) {
                var sel = b.querySelector('[name="ambalaj_tip_id"]');
                for (var i = 0; sel && i < sel.options.length; i++) if (sel.options[i].getAttribute('data-kod') === String(o.ambalajKod)) { sel.selectedIndex = i; break; }
            }
            hksKapat();
            stokUygula();
        }
        var kd = $('[data-hk-kaydet]', ov); if (kd) { kd.disabled = false; kd.removeAttribute('aria-busy'); }
        ov.hidden = false;
        document.documentElement.classList.add('hk-acik');
        setTimeout(function () {
            var ilk = ad !== '' ? (tur === 'cari' ? b.querySelector('[name="tip"]') : b.querySelector('[name="ana_birim"]')) : b.querySelector('[data-hk-ilk]');
            if (tur === 'cari' && ad !== '' && o.tip && b.querySelector('[name="tip"]').value) ilk = b.querySelector('[name="telefon"]');
            if (ilk) ilk.focus();
        }, 30);
        return true;
    }
    function kapat(sessiz) {
        if (!acikTur || !ov) return;
        acikTur = null; sonraFn = null; kilit = false;
        hksKapat();
        ov.hidden = true;
        document.documentElement.classList.remove('hk-acik');
        if (!sessiz && acan && document.contains(acan) && acan.focus) { try { acan.focus({ preventScroll: true }); } catch (x) { acan.focus(); } }
    }

    /* ═════════ Gönder ═════════ */
    function veri() {
        var g = { csrf: (form.querySelector('[name="csrf"]') || {}).value || '', tur: acikTur, istek_id: istekId };
        var b = bolum(acikTur);
        $$('input[name], select[name]', b).forEach(function (e) { if (!e.disabled) g[e.name] = String(e.value || '').trim(); });
        if (acikTur === 'stok') {
            if (g.tip !== 'urun') { delete g.hks_urun_id; delete g.hks_urun_ad; }
            else if (g.hks_urun_id) delete g.hks_urun_ad;
        }
        return g;
    }
    function istemciDogrula(g) {
        var h = {};
        if (acikTur === 'cari') {
            if (!g.unvan) h.unvan = M.zorunlu;
            if (!g.tip) h.tip = M.zorunlu;
        } else {
            if (!g.ad) h.ad = M.zorunlu;
            if (!g.tip) h.tip = M.zorunlu;
            if (!g.ana_birim) h.ana_birim = M.zorunlu;
            if (g.ambalaj_tip_id && !g.birim_dara_kg) h.birim_dara_kg = M.dara;
        }
        return h;
    }
    function gonder() {
        if (!acikTur || kilit) return;
        hatalariTemizle();
        var g = veri(), h = istemciDogrula(g), adlar = Object.keys(h);
        if (adlar.length) { adlar.forEach(function (a) { alanHata(a, h[a]); }); ilkHataOdak(); return; }
        kilit = true;
        var kd = $('[data-hk-kaydet]', ov); if (kd) { kd.disabled = true; kd.setAttribute('aria-busy', 'true'); }
        var tur = acikTur;
        fetch(API, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
            body: JSON.stringify(g)
        }).then(function (r) {
            return r.text().then(function (t) { var j = null; try { j = JSON.parse(t); } catch (x) { j = null; } return { kod: r.status, j: j }; });
        }).then(function (s) {
            kilit = false;
            if (kd) { kd.disabled = false; kd.removeAttribute('aria-busy'); }
            if (acikTur !== tur) return;
            var j = s.j;
            if (j && j.ok) { basari(j); return; }
            if (!j) { genelHata(M.sunucu); return; }
            if (s.kod === 403 && j.code === 403) { genelHata(M.csrf); return; }
            var al = j.alanlar && typeof j.alanlar === 'object' ? j.alanlar : {}, herhangi = false;
            Object.keys(al).forEach(function (a) { if (alanHata(a, String(al[a]))) herhangi = true; });
            if (!herhangi) genelHata(String(j.hata || j.error || M.sunucu)); else ilkHataOdak();
        }).catch(function () {
            kilit = false;
            if (kd) { kd.disabled = false; kd.removeAttribute('aria-busy'); }
            genelHata(M.ag);   // istek_id aynı kalır: yanıt kaybolduysa yeniden gönderim ikinci kart açmaz
        });
    }
    function basari(kart) {
        var tur = acikTur, fn = sonraFn, git = gitSablon;
        kapat(true);
        var msg = M.basari[tur] || M.basari.cari;
        if (git && /^[a-z_]+\.php(\?[A-Za-z0-9_=&{}%.-]*)?$/.test(git)) {
            try { window.sessionStorage.setItem(BILDIRIM_ANAHTAR, msg); } catch (x) { /* yok */ }
            if (fn) { try { fn(kart); } catch (x) { if (window.console) console.error(x); } }
            window.location.assign(git.replace('{id}', encodeURIComponent(String(kart.id))));
            return;
        }
        toast(msg);
        if (fn) { try { fn(kart); } catch (x) { if (window.console) console.error(x); } }
        if (acan && document.contains(acan) && acan.focus) { try { acan.focus({ preventScroll: true }); } catch (x) { acan.focus(); } }
    }

    /* ═════════ Olaylar ═════════ */
    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('[data-hk-ac]') : null;
        if (!t) return;
        e.preventDefault();
        ac({
            tur: t.getAttribute('data-hk-ac'), ad: t.getAttribute('data-hk-metin') || '', tip: t.getAttribute('data-hk-tip') || '',
            kartTip: t.getAttribute('data-hk-kart-tip') || '', ambalajKod: t.getAttribute('data-hk-ambalaj') || '',
            git: t.getAttribute('data-hk-git') || '', acan: t
        });
    });
    if (ov && form) {
        ov.addEventListener('mousedown', function (e) { mdown = e.target; });
        ov.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('[data-hk-kapat]')) { e.preventDefault(); kapat(); return; }
            if (e.target === ov && mdown === ov && !kirli) kapat();
        });
        form.addEventListener('input', function (e) {
            kirli = true;
            if (e.target && e.target.id === 'hkHksAra') { var gz = form.querySelector('[name="hks_urun_id"]'); if (gz) gz.value = ''; hksCiz(); }
        });
        form.addEventListener('change', function (e) {
            kirli = true;
            var n = e.target && e.target.name;
            if (acikTur === 'cari' && n === 'tip') mustahsilUygula();
            if (acikTur === 'stok' && (n === 'tip' || n === 'ambalaj_tip_id')) stokUygula();
        });
        form.addEventListener('focusout', function (e) { if (e.target && e.target.id === 'hkHksAra') setTimeout(hksKapat, 120); });
        form.addEventListener('submit', function (e) { e.preventDefault(); gonder(); });
        // Klavye: pencere açıkken olay SAYFAYA (cari.js / stok.js kısayolları) ulaşmaz
        ov.addEventListener('keydown', function (e) {
            if (!acikTur) return;
            e.stopPropagation();
            var hl = hksListe();
            if (e.target && e.target.id === 'hkHksAra' && hl && !hl.hidden) {
                if (e.key === 'ArrowDown') { e.preventDefault(); hksIsaretle(hksAktif + 1); return; }
                if (e.key === 'ArrowUp') { e.preventDefault(); hksIsaretle(hksAktif - 1); return; }
                if (e.key === 'Escape') { e.preventDefault(); hksKapat(); return; }
                if (e.key === 'Enter' && hksAktif >= 0) {
                    e.preventDefault();
                    var o = $$('li[role=option]', hl)[hksAktif]; if (o && o._hk) hksSec(o._hk);
                    return;
                }
            }
            if (e.key === 'Escape') { e.preventDefault(); kapat(); return; }
            if (e.key === 'Enter' && !e.isComposing) {
                var tg = e.target && e.target.tagName;
                if (tg === 'SELECT' || (tg === 'INPUT' && e.target.type !== 'button') || ((e.ctrlKey || e.metaKey) && tg !== 'BUTTON')) { e.preventDefault(); gonder(); }
                return;
            }
            if (e.key === 'Tab') {
                var od = $$(ODAK, ov).filter(gorunur);
                if (!od.length) return;
                var a = od[0], z = od[od.length - 1];
                if (e.shiftKey && (document.activeElement === a || !ov.contains(document.activeElement))) { e.preventDefault(); z.focus(); }
                else if (!e.shiftKey && (document.activeElement === z || !ov.contains(document.activeElement))) { e.preventDefault(); a.focus(); }
            }
        });
        // Odak pencereden dışarı kaçarsa (ör. fareyle sayfaya tıklama) geri getir
        document.addEventListener('focusin', function (e) {
            if (acikTur && !ov.contains(e.target)) { var ilk = $$(ODAK, ov).filter(gorunur)[0]; if (ilk) ilk.focus(); }
        });
    }

    window.HizliKart = { ac: ac, kapat: function () { kapat(); }, kullanilabilir: kullanilabilir, secenekleriYenile: secenekleriYenile };
})();
