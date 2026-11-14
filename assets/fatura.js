/* =========================================================
   assets/fatura.js — Fatura modülü istemcisi (satis_faturalari / alis_faturalari / sevk_irsaliyeleri / alinan_siparis_fisleri / fatura_tanimlari)
   Yalnız #fatura kökü olan sayfada çalışır. Yazmalar YALNIZ fatura_kaydet.php'ye (fetch + X-Requested-With: fetch; JS kapalıyken
   aynı formlar klasik POST + 303 ile çalışır). app.js / stok.js / cari.js'e DOKUNMAZ. kalıcı tarayıcı deposu KULLANILMAZ (toast: yalnız sessionStorage).
   Canlı toplamlar YALNIZ önizlemedir (sunucu esas; plan §4 formülü: kuruş/milli/on-binde TAM SAYI, kayan noktalı ayrıştırma yok). JS hiçbir toplamı POST etmez.
   Satırların doğruluk kaynağı DOM'dur (#ftForm .ft-fsatir; paralel `name[]` dizileri) — ayrı satır dizisi tutulmaz.
   Kancalar: #ftForm[data-ft-fatura] · #ftVeri · #ftSatirSablon · [data-satir-ekle|sil|kopya|menu|ayrinti] · [data-son-fiyat-uygula] · [data-kalana-esitle] ·
             #ftEksik ([data-eksik-duzelt] / [data-eksik-kaydet]) · [data-kaynak-getir] · #ftModalKaynak · #ftSatirSheet · [data-ft-modal="iptal"|"kapat"] ·
             form[data-ft-ajax] · [data-oto-gonder] · [data-ft-cari-suz] · #ftListe a.ft-satir (≥1024 master-detail: ?parca=detay) · [data-liste-ac] ·
             .ft-tanim-nav-oge · [data-tanim-duzen]/[data-tanim-vazgec] · input[role=switch].
   Klavye: Enter sonraki alan · Shift+Enter önceki · Ctrl+S / Ctrl+Enter kaydet · Ctrl+↓ yeni satır · Ctrl+Shift+↓ satırı kopyala · Ctrl+Del sil ·
           Alt+F son fiyatı uygula · Alt+K kaynaktan getir · Alt+B başlığı daralt/aç · Esc · "/" arama · ↑/↓ liste.
   ========================================================= */
(function () {
    'use strict';
    var KOK = document.getElementById('fatura');
    if (!KOK) return;

    var S = {
        kaydet: 'fatura_kaydet.php',
        sayfa: (location.pathname.replace(/^.*\//, '') || 'satis_faturalari.php'),
        yonRe: /^(alis_faturalari|satis_faturalari|sevk_irsaliyeleri|alinan_siparis_fisleri|fatura_tanimlari)\.php(\?[^\r\n]*)?$/,
        toastAnahtar: 'fatura_toast',
        baslik: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
        turSayfa: { satis: 'satis_faturalari.php', satis_ihracat: 'satis_faturalari.php', satis_iade: 'satis_faturalari.php', alis: 'alis_faturalari.php', alis_mustahsil: 'alis_faturalari.php',
            alis_iade: 'alis_faturalari.php', irsaliye: 'sevk_irsaliyeleri.php', siparis: 'alinan_siparis_fisleri.php' },
        m: { ag: 'Bağlantı kesildi. Tekrar Kaydet\'e basın; belge iki kez yazılmaz.', zorunlu: 'Zorunlu alan.' }
    };
    var SYM = { TRY: '₺', USD: '$', EUR: '€' };
    function $(s, k) { return (k || document).querySelector(s); }
    function $$(s, k) { return Array.prototype.slice.call((k || document).querySelectorAll(s)); }
    function yap(etiket, sinif, metin) { var e = document.createElement(etiket); if (sinif) e.className = sinif; if (metin != null) e.textContent = metin; return e; }
    function bosalt(e) { while (e && e.firstChild) e.removeChild(e.firstChild); }
    function katla(s) { return String(s || '').replace(/İ/g, 'i').replace(/I/g, 'ı').toLocaleLowerCase('tr-TR').trim(); }
    function depoOku(k) { try { return window.sessionStorage.getItem(k); } catch (x) { return null; } }
    function depoYaz(k, v) { try { if (v == null) window.sessionStorage.removeItem(k); else window.sessionStorage.setItem(k, v); } catch (x) { /* yok: sorun değil */ } }
    function guvenliYol(u) { return typeof u === 'string' && S.yonRe.test(u); }
    function masaustu() { return window.matchMedia('(min-width: 1024px)').matches; }
    function mobil() { return window.matchMedia('(max-width: 767px)').matches; }

    /* ═════════ Toast ═════════ */
    var toastZ = null;
    function toast(msg, hata) {
        var t = $('.ft-toast');
        if (!t) { t = yap('div', 'ft-toast'); t.setAttribute('role', 'status'); t.setAttribute('aria-live', 'polite'); document.body.appendChild(t); }
        t.textContent = msg; t.classList.toggle('ft-toast--hata', !!hata); t.hidden = false;
        clearTimeout(toastZ); toastZ = setTimeout(function () { t.hidden = true; }, hata ? 4500 : 2600);
    }
    function yenileToastla(msg, url) {
        if (msg) depoYaz(S.toastAnahtar, msg);
        if (url && guvenliYol(url) && url !== S.sayfa + location.search) location.assign(url); else location.reload();
    }
    (function () { var m = depoOku(S.toastAnahtar); if (m) { depoYaz(S.toastAnahtar, null); toast(m); } })();

    /** Sunucu kısmi yanıtını (kendi sayfamızın ?parca=detay çıktısı) DOM'a çevirir — modüldeki TEK HTML ayrıştırma noktası. */
    function htmlParca(h) { var t = document.createElement('div'); t.innerHTML = h; return t; }
    function yanitCoz(r) { return r.text().then(function (t) { var j = null; try { j = JSON.parse(t); } catch (x) { j = null; } return { r: r, j: j }; }); }
    function jsonAl(url) {
        return fetch(url, { headers: S.baslik, credentials: 'same-origin' }).then(yanitCoz).then(function (s) { if (!s.j || !s.j.ok) throw new Error((s.j && s.j.hata) || ('HTTP ' + s.r.status)); return s.j; });
    }

    /* ═════════ Sayı biçimleri (tam sayı) ═════════ */
    /** "1.250,5" → basamaklı tam sayı (1250500 @3). Basamaktan fazla ondalık / geçersiz → NaN. */
    function ondalikInt(v, basamak) {
        var s = String(v == null ? '' : v).replace(/[\s ₺$€%]/g, '');
        if (!s) return NaN;
        if (s.indexOf(',') >= 0) s = s.replace(/\./g, '').replace(',', '.');
        else if (/^\d{1,3}(\.\d{3})+$/.test(s)) s = s.replace(/\./g, '');
        var m = /^(\d{1,13})(?:\.(\d+))?$/.exec(s);
        if (!m) return NaN;
        var k = m[2] || '';
        if (k.length > basamak) return NaN;
        while (k.length < basamak) k += '0';
        return Number(m[1]) * Math.pow(10, basamak) + (k ? Number(k) : 0);
    }
    /** round(a*b/d), pozitifte yarımı yukarı, taşma güvenli. */
    function carpBol(a, b, d) {
        var p = a * b;
        if (Math.abs(p) <= Number.MAX_SAFE_INTEGER) { var q = Math.floor(p / d), r = p - q * d; return 2 * r >= d ? q + 1 : q; }
        var A = BigInt(a), B = BigInt(b), D = BigInt(d);
        return Number((A * B * 2n + D) / (2n * D));
    }
    function para(k, pb) { var s = (Math.abs(k) / 100).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); s = (k < 0 ? '-' : '') + s; return pb ? s + ' ' + (SYM[pb] || pb) : s; }
    function tam(b) { return b !== 'lt' && b !== 'm'; }
    function milliBicim(mil, b) {
        var neg = mil < 0; mil = Math.abs(mil);
        if (tam(b)) return (neg ? '-' : '') + Math.floor((mil + 500) / 1000).toLocaleString('tr-TR');
        var k = String(mil % 1000); while (k.length < 3) k = '0' + k; k = k.replace(/0+$/, '');
        return (neg ? '-' : '') + Math.floor(mil / 1000).toLocaleString('tr-TR') + (k ? ',' + k : '');
    }
    function f4Bicim(f4) { var k = String(f4 % 10000); while (k.length < 4) k = '0' + k; k = k.replace(/0+$/, ''); while (k.length < 2) k += '0'; return Math.floor(f4 / 10000).toLocaleString('tr-TR') + ',' + k; }
    function bpBicim(bp) { var k = String(bp % 100); if (k.length < 2) k = '0' + k; k = k.replace(/0+$/, ''); return Math.floor(bp / 100) + (k ? ',' + k : ''); }
    function trTarih(d) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(d || '')); return m ? m[3] + '.' + m[2] + (arguments[1] ? '.' + m[1] : '') : String(d || ''); }

    /* ═════════ Form hataları (genel) ═════════ */
    function hataTemizle(f) {
        $$('.ft-hata', f).forEach(function (e) { e.remove(); });
        $$('[aria-invalid="true"]', f).forEach(function (e) { e.removeAttribute('aria-invalid'); });
        $$('.ft-form-hata', f).forEach(function (g) { g.hidden = true; bosalt(g); });
    }
    function hataSpan(el, msg, kap) {
        el.setAttribute('aria-invalid', 'true');
        var s = yap('span', 'ft-hata', msg), id = 'ftH' + Math.random().toString(36).slice(2, 8);
        s.id = id; el.setAttribute('aria-describedby', id);
        (kap || el.closest('.pm-label') || el.parentNode).appendChild(s);
        return el;
    }
    function alanHata(f, ad, msg) {
        var el = $$('[name="' + ad + '"]', f).filter(function (x) { return x.type !== 'hidden' && !x.disabled; })[0];
        if (!el) return null;
        var d = el.closest('details'); if (d) d.open = true;
        return hataSpan(el, msg);
    }
    function genelHata(f, msg) { var g = $('.ft-form-hata', f); if (g) { bosalt(g); g.appendChild(document.createTextNode(msg)); g.hidden = false; } else toast(msg, true); return g; }

    /* ═════════ AJAX formlar (iptal / kapat / tanımlar) ═════════ */
    function gonder(f) {
        if (f.dataset.kilit === '1') return;
        if (f.getAttribute('data-onay') && !window.confirm(f.getAttribute('data-onay'))) return;
        hataTemizle(f);
        var eksik = $$('[required]', f).filter(function (e) { return !e.disabled && !String(e.value || '').trim(); });
        if (eksik.length) { eksik.forEach(function (e) { alanHata(f, e.name, S.m.zorunlu); }); eksik[0].focus(); return; }
        var btn = $$('button[type=submit]', f);
        f.dataset.kilit = '1'; btn.forEach(function (b) { b.disabled = true; });
        var fd = new FormData(f);
        fetch(f.getAttribute('action') || S.kaydet, { method: 'POST', body: fd, headers: S.baslik, credentials: 'same-origin' })
            .then(yanitCoz)
            .then(function (s) {
                f.dataset.kilit = ''; btn.forEach(function (b) { b.disabled = false; });
                var j = s.j;
                if (j && j.ok) { yenileToastla((f.getAttribute('data-ok') || 'Kaydedildi') + (j.belge_no ? ' · ' + j.belge_no : '') + (j.uyari ? ' — ' + j.uyari : ''), j.yonlendir); return; }
                var ilk = null;
                if (j && j.alanlar) Object.keys(j.alanlar).forEach(function (ad) { var el = alanHata(f, ad, j.alanlar[ad]); if (el && !ilk) ilk = el; });
                var g = genelHata(f, (j && j.hata) || ('İşlem yapılamadı (HTTP ' + s.r.status + ').'));
                if (j && j.kod === 'bagli_belge' && g && j.bagli && j.bagli.length) {
                    var ul = yap('ul', 'ft-bagli-liste');
                    j.bagli.forEach(function (b) { var li = yap('li'), a = yap('a', '', b.belge_no); a.href = (S.turSayfa[b.tur] || S.sayfa) + '?id=' + b.id; li.appendChild(a); ul.appendChild(li); });
                    g.appendChild(ul); g.appendChild(yap('small', '', 'İptal sırası: en alttaki belge önce.'));
                }
                if (ilk && ilk.focus) ilk.focus();
            })
            .catch(function () { f.dataset.kilit = ''; btn.forEach(function (b) { b.disabled = false; }); genelHata(f, 'Bağlantı hatası; tekrar deneyin.'); });
    }
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f.matches || !f.matches('form[data-ft-ajax]')) return;
        e.preventDefault(); gonder(f);
    });
    document.addEventListener('change', function (e) {
        var c = e.target;
        if (c.matches && c.matches('input[role="switch"]') && c.form && c.form.matches('form[data-ft-ajax]')) gonder(c.form);
        if (c.matches && c.matches('select[data-oto-gonder]') && c.form) {
            if (c.name === 'donem' && c.value === 'ozel') { var oz = $('.ft-ozel-tarih', c.form); if (oz) { oz.hidden = false; var b0 = $('input', oz); if (b0) b0.focus(); } return; }
            c.form.submit();
        }
        if (c.matches && c.matches('.ft-ozel-tarih input') && c.form) { var bb = c.form.elements.bas, bt = c.form.elements.bit; if (bb.value && bt.value) c.form.submit(); }
        if (c.matches && c.matches('[data-ft-cari-suz]')) cariSuz(c);
    });
    function cariSuz(inp) {
        var V0 = cariListesi(), c = cariBul(V0, inp.value);
        inp.form.elements.cari.value = c ? String(c.id) : '';
        if (c || !inp.value.trim()) inp.form.submit();
    }
    function cariListesi() { var o = $$('#ftCariListe option').map(function (op) { var p = op.value.split(' — '); return { kod: p[0], unvan: p.slice(1).join(' — '), metin: op.value }; }); return o; }
    function cariBul(liste, metin) {
        metin = String(metin || '').trim(); if (!metin) return null;
        var kod = katla(metin.split(' — ')[0]);
        for (var i = 0; i < liste.length; i++) if (katla(liste[i].kod) === kod || katla(liste[i].unvan) === katla(metin)) return liste[i];
        return null;
    }

    /* ═════════ Modal ═════════ */
    var acik = null, acan = null, kapatKanca = null;
    function modalAc(id, tetik, kapaninca) {
        var m = document.getElementById(id); if (!m) return null;
        if (acik && acik !== m) modalKapat(true);
        acik = m; acan = tetik || document.activeElement; kapatKanca = kapaninca || null;
        m.hidden = false; document.body.style.overflow = 'hidden';
        setTimeout(function () { var o = $('[data-ilk-odak]:not([hidden])', m) || $('input:not([type=hidden]):not([disabled]), select, textarea', m); if (o && o.getClientRects().length) o.focus(); }, 30);
        return m;
    }
    function modalKapat(sessiz) {
        if (!acik) return;
        var k = kapatKanca; kapatKanca = null;
        acik.hidden = true; document.body.style.overflow = '';
        var a = acan; acik = null; acan = null;
        if (k && !sessiz) k();
        if (a && document.contains(a) && a.focus) a.focus();
    }
    function iptalModalAc(t) {
        var m = document.getElementById('ftModalIptal'); if (!m) return;
        var f = $('form', m); f.reset(); hataTemizle(f);
        f.elements.id.value = t.getAttribute('data-id') || '';
        f.elements.geri.value = t.getAttribute('data-geri') || S.sayfa;
        var no = $('[data-iptal-no]', m); if (no) no.textContent = t.getAttribute('data-belge-no') || '';
        var tur = t.getAttribute('data-tur') || '', u = $('[data-iptal-uyari]', m);
        if (u) u.textContent = tur === 'irsaliye' ? 'Stok çıkışı tersine yazılır. Bu işlem geri alınamaz.' : (tur === 'siparis' ? 'Hareket yok; kalan miktarlar kapanır. Bu işlem geri alınamaz.' : 'Cari dekontu ve (varsa) stok fişi tersine yazılır. Bu işlem geri alınamaz; düzeltme için iptalden sonra Kopyala ile yeni belge girin.');
        iptalSayac(f);
        modalAc('ftModalIptal', t);
    }
    function iptalSayac(f) {
        var ta = $('[data-iptal-neden]', f), n = ta ? ta.value.trim().length : 0;
        var b = $('[data-iptal-onay]', f); if (b) b.disabled = n < 3;
        var sy = $('[data-sayac]', f); if (sy) sy.textContent = n < 3 ? 'En az 3 karakter' : n + ' / 500';
    }
    function kapatModalAc(t) {
        var m = document.getElementById('ftModalKapat'); if (!m) return;
        var f = $('form', m); f.reset(); hataTemizle(f);
        var ac = t.getAttribute('data-kapali') === '1';
        f.elements.id.value = t.getAttribute('data-id') || '';
        f.elements.kapali.value = ac ? '0' : '1';
        f.elements.geri.value = t.getAttribute('data-geri') || S.sayfa;
        $('[data-kapat-no]', m).textContent = t.getAttribute('data-belge-no') || '';
        $('[data-kapat-baslik]', m).textContent = ac ? 'Siparişi yeniden aç' : 'Siparişi kapat';
        $('[data-kapat-ack]', m).textContent = ac ? 'Sipariş yeniden açılacak; kalan miktarlardan yeni belge oluşturulabilir.' : 'Kapalı siparişten irsaliye/fatura oluşturulamaz; mevcut bağlı belgeler etkilenmez.';
        $('[data-kapat-neden-kap]', m).hidden = ac;
        f.elements.neden.required = !ac;
        modalAc('ftModalKapat', t);
    }
    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-ft-modal], [data-ft-kapat]') : null;
        if (t && t.hasAttribute('data-ft-kapat')) { e.preventDefault(); modalKapat(); return; }
        if (t && t.getAttribute('data-ft-modal') === 'iptal') { e.preventDefault(); kapatDiger(); iptalModalAc(t); return; }
        if (t && t.getAttribute('data-ft-modal') === 'kapat') { e.preventDefault(); kapatDiger(); kapatModalAc(t); return; }
        var ov = e.target;
        if (ov && ov.classList && ov.classList.contains('pm-overlay') && ov === acik) modalKapat();
        // açık details menüleri dışarı tıklanınca kapanır
        $$('details.ft-hizli[open], details.ft-diger-menu[open]').forEach(function (d) { if (!d.contains(e.target)) d.open = false; });
    });
    function kapatDiger() { $$('details.ft-diger-menu[open], details.ft-hizli[open]').forEach(function (d) { d.open = false; }); }
    document.addEventListener('input', function (e) { var t = e.target; if (t && t.matches && t.matches('[data-iptal-neden]')) iptalSayac(t.form); });
    document.addEventListener('keydown', function (e) {
        if (acik) {
            if (e.key === 'Escape') { e.preventDefault(); if (acik.id === 'ftSatirSheet' && SH) SH.vazgec(); else modalKapat(); return; }
            if (e.key === 'Tab') {
                var od = $$('button, input:not([type=hidden]), select, textarea, a[href], summary', acik).filter(function (x) { return !x.disabled && x.getClientRects().length; });
                if (!od.length) return;
                var ilk = od[0], son = od[od.length - 1];
                if (e.shiftKey && document.activeElement === ilk) { e.preventDefault(); son.focus(); }
                else if (!e.shiftKey && document.activeElement === son) { e.preventDefault(); ilk.focus(); }
            }
            return;
        }
        if (MENU && !MENU.hidden && e.key === 'Escape') { menuKapat(true); e.preventDefault(); return; }
        var ar = document.getElementById('ftArama');
        var yazim = /^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName || '');
        if (ar && e.key === '/' && !yazim && !FF) { e.preventDefault(); ar.focus(); ar.select(); return; }
        if (ar && document.activeElement === ar && e.key === 'Escape') { ar.value = ''; return; }
        var a = document.activeElement;
        if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && (a === ar || (a && a.classList && a.classList.contains('ft-satir')))) {
            var gor = $$('#ftListe a.ft-satir').filter(function (x) { return x.getClientRects().length; });
            if (!gor.length) return;
            var i = gor.indexOf(a), j = e.key === 'ArrowDown' ? (i < 0 ? 0 : Math.min(gor.length - 1, i + 1)) : (i <= 0 ? 0 : i - 1);
            e.preventDefault(); gor[j].focus();
        }
    });

    /* ═════════ Liste: master-detail (≥1024: sayfa yenilemeden detay) + ray ═════════ */
    var liste = document.getElementById('ftListe');
    function detayYukle(id, href, push) {
        var d = document.getElementById('ftDetay');
        if (d) d.classList.add('ft-yukleniyor');
        return fetch(S.sayfa + '?id=' + encodeURIComponent(id) + '&parca=detay', { headers: { 'X-Requested-With': 'fetch', 'Accept': 'text/html' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(function (h) {
                var yeni = $('#ftDetay', htmlParca(h));
                if (!yeni) throw new Error('detay yok');
                var eski = document.getElementById('ftDetay');
                eski.parentNode.replaceChild(yeni, eski);
                if (FF) { formBitir(); }
                var ana = $('.ft-ana', KOK); if (ana) { ana.classList.add('ft-ana--detay'); ana.classList.remove('ft-ana--form'); ana.classList.remove('ft-liste--acik'); }
                KOK.setAttribute('data-sayfa', 'detay');
                $$('#ftListe a.ft-satir').forEach(function (a) { if (a.getAttribute('data-id') === String(id)) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current'); });
                if (push) { try { history.pushState({ ftId: id }, '', href); } catch (x) { /* kritik değil */ } }
            })
            .catch(function () { location.assign(href); });
    }
    if (liste) {
        liste.addEventListener('click', function (e) {
            var a = e.target.closest ? e.target.closest('a.ft-satir') : null;
            if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0 || !masaustu()) return;
            if (FF && degisti && !window.confirm('Kaydedilmemiş değişiklikler var. Form kapanacak; devam edilsin mi?')) { e.preventDefault(); return; }
            e.preventDefault();
            detayYukle(a.getAttribute('data-id'), a.getAttribute('href'), true);
        });
        window.addEventListener('popstate', function () {
            var id = new URLSearchParams(location.search).get('id');
            if (id && masaustu() && !FF) detayYukle(id, location.href, false); else location.reload();
        });
        var sec = $('#ftListe a.ft-satir[aria-current="true"]');
        if (sec && masaustu()) { try { sec.scrollIntoView({ block: 'nearest' }); } catch (x) { /* eski tarayıcı */ } }
    }
    KOK.addEventListener('click', function (e) {
        var r = e.target.closest ? e.target.closest('[data-liste-ac]') : null;
        if (!r) return;
        var ana = $('.ft-ana', KOK), ac = !ana.classList.contains('ft-liste--acik');
        ana.classList.toggle('ft-liste--acik', ac); r.setAttribute('aria-expanded', ac ? 'true' : 'false');
        if (ac) { var ar = document.getElementById('ftArama'); if (ar) setTimeout(function () { ar.focus(); }, 30); }
    });

    /* ═════════ Tanımlar: sekme + satır içi düzenleme ═════════ */
    var nav = $$('.ft-tanim-nav-oge', KOK);
    nav.forEach(function (t) {
        t.addEventListener('click', function (e) {
            if (e.ctrlKey || e.metaKey) return;
            e.preventDefault();
            nav.forEach(function (x) { var on = x === t; x.setAttribute('aria-selected', on ? 'true' : 'false'); var p = document.getElementById(x.getAttribute('aria-controls')); if (p) p.hidden = !on; });
            try { history.replaceState(history.state, '', t.getAttribute('href')); } catch (x) { /* kritik değil */ }
        });
    });
    KOK.addEventListener('click', function (e) {
        var d = e.target.closest ? e.target.closest('[data-tanim-duzen], [data-tanim-vazgec]') : null;
        if (!d) return;
        var li = d.closest('li');
        if (d.hasAttribute('data-tanim-duzen')) {
            var ed = li.nextElementSibling;
            if (ed && ed.classList.contains('ft-tanim-satir--duzen')) { li.hidden = true; ed.hidden = false; var i = $('input:not([type=hidden]):not([readonly])', ed); if (i) i.focus(); }
        } else { var on = li.previousElementSibling; li.hidden = true; if (on) { on.hidden = false; var b = $('[data-tanim-duzen]', on); if (b) b.focus(); } }
    });
    document.addEventListener('input', function (e) { var t = e.target; if (t && t.classList && t.classList.contains('ft-buyuk')) { var p = t.selectionStart; t.value = t.value.toLocaleUpperCase('tr-TR').replace(/İ/g, 'I'); try { t.setSelectionRange(p, p); } catch (x) { /* yok */ } } });

    /* ═════════ Satır menüsü (⋯) — position:fixed (kaydırma kabında kesilmez) ═════════ */
    var MENU = null, menuSatir = null, menuTetik = null;
    function menuKapat(odak) { if (!MENU) return; MENU.hidden = true; if (odak && menuTetik && document.contains(menuTetik)) menuTetik.focus(); menuSatir = null; menuTetik = null; }
    function menuAc(btn) {
        if (!MENU) {
            MENU = yap('div', 'ft-menu'); MENU.setAttribute('role', 'menu'); MENU.hidden = true;
            [['data-satir-ayrinti', 'Ayrıntı (lot, açıklama…)'], ['data-satir-kopya', 'Satırı kopyala'], ['data-satir-sil', 'Satırı sil']].forEach(function (x) {
                var b = yap('button', x[0] === 'data-satir-sil' ? 'ft-tehlike' : '', x[1]); b.type = 'button'; b.setAttribute(x[0], ''); b.setAttribute('role', 'menuitem'); MENU.appendChild(b);
            });
            document.body.appendChild(MENU);
            MENU.addEventListener('click', function (e) {
                var b = e.target.closest('button'); if (!b || !menuSatir || !SF) return;
                var tr = menuSatir; menuKapat(false);
                if (b.hasAttribute('data-satir-sil')) SF.satirSil(tr);
                else if (b.hasAttribute('data-satir-kopya')) SF.satirKopya(tr);
                else { var d = $('.ft-sx', tr); if (mobil()) SF.sheetAc(tr, 'lot'); else if (d) { d.open = true; var i = $('input', d); if (i) i.focus(); } }
            });
            document.addEventListener('click', function (e) { if (MENU && !MENU.hidden && !MENU.contains(e.target) && !(e.target.closest && e.target.closest('[data-satir-menu]'))) menuKapat(false); });
            window.addEventListener('scroll', function () { menuKapat(false); }, true);
            window.addEventListener('resize', function () { menuKapat(false); });
        }
        menuSatir = btn.closest('.ft-fsatir'); menuTetik = btn;
        $('[data-satir-ayrinti]', MENU).hidden = false;
        MENU.hidden = false;
        var r = btn.getBoundingClientRect(), w = MENU.offsetWidth, h = MENU.offsetHeight;
        var alt = window.innerHeight - r.bottom;
        MENU.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, r.right - w)) + 'px';
        MENU.style.top = (alt > h + 8 ? r.bottom + 4 : Math.max(8, r.top - h - 4)) + 'px';
        var ilk = $('button', MENU); if (ilk) ilk.focus();
    }

    /* ═════════════════════════ Belge formu ═════════════════════════ */
    var FF = document.getElementById('ftForm');
    var SF = null, SH = null, degisti = false;
    function formBitir() { FF = null; SF = null; degisti = false; }
    window.addEventListener('beforeunload', function (e) { if (FF && degisti && FF.dataset.kilit !== '1' && !FF.dataset.bitti) { e.preventDefault(); e.returnValue = ''; } });
    if (FF) SF = formKur(FF);

    function formKur(f) {
        var V = {};
        try { V = JSON.parse((document.getElementById('ftVeri') || {}).textContent || '{}'); } catch (x) { V = {}; }
        var kartlar = V.kartlar || [], kartId = {};
        kartlar.forEach(function (k) { kartId[String(k.id)] = k; });
        var cariler = V.cariler || [];
        var FT = { tur: V.tur, cari: null, ozet: null, ozetNo: 0 };
        function bilgi() { return (V.turler || {})[FT.tur] || {}; }
        function el(tr, ad) { return $('[name="' + ad + '[]"]', tr); }
        function satirlar() { return $$('.ft-fsatir', $('.ft-fsatirlar', f)); }
        function satirKart(tr) { return kartId[el(tr, 'kart_id').value] || null; }
        function satirMetin(tr) { return el(tr, 'kart_ara').value.trim(); }
        function satirDolu(tr) { return !!(el(tr, 'kart_id').value || satirMetin(tr) || el(tr, 'miktar').value.trim() || el(tr, 'birim_fiyat').value.trim()); }
        function kaynakli(tr) { return !!tr.getAttribute('data-kaynak-satir'); }
        function pb() { return f.elements.para_birimi ? f.elements.para_birimi.value : 'TRY'; }
        function depo() { return f.elements.depo ? f.elements.depo.value : ''; }
        function degis() { degisti = true; }
        function kartBul(metin) {
            metin = String(metin || '').trim(); if (!metin) return null;
            var kod = katla(metin.split(' — ')[0]), i;
            for (i = 0; i < kartlar.length; i++) if (katla(kartlar[i].kod) === kod) return kartlar[i];
            for (i = 0; i < kartlar.length; i++) if (kartlar[i].barkod && String(kartlar[i].barkod) === metin) return kartlar[i];
            var ad = kartlar.filter(function (k) { return katla(k.ad) === katla(metin); });
            return ad.length === 1 ? ad[0] : null;
        }
        function cariBulV(metin) {
            metin = String(metin || '').trim(); if (!metin) return null;
            var kod = katla(metin.split(' — ')[0]);
            for (var i = 0; i < cariler.length; i++) if (katla(cariler[i].kod) === kod || katla(cariler[i].unvan) === katla(metin)) return cariler[i];
            return null;
        }

        /* — birim / KDV seçenekleri — */
        function secenek(s, deger, metin, secili) { var o = document.createElement('option'); o.value = deger; o.textContent = metin; if (secili) o.selected = true; s.appendChild(o); }
        function birimKur(tr, k, secili) {
            var s = el(tr, 'birim'); bosalt(s);
            if (k) {
                secenek(s, '', k.birim, !secili);
                if (k.cevrim_birim && k.cevrim_katsayi) secenek(s, k.cevrim_birim, k.cevrim_birim + ' (×' + milliBicim(Math.round(k.cevrim_katsayi * 1000), 'lt') + ' ' + k.birim + ')', secili === k.cevrim_birim);
            } else {
                secenek(s, '', '—', !secili);
                (V.birimler || []).forEach(function (b) { secenek(s, b, b, secili === b); });
            }
            el(tr, 'miktar').setAttribute('inputmode', k && !tam(k.birim) ? 'decimal' : 'numeric');
        }
        function kdvKur(tr, secili) {
            var s = el(tr, 'kdv_orani'), sifir = !!bilgi().kdv_sifir;
            if (secili == null || secili === '') secili = s.value;
            bosalt(s);
            if (sifir) { secenek(s, '0', '%0', true); s.setAttribute('aria-readonly', 'true'); return; }
            s.removeAttribute('aria-readonly');
            var or = (V.kdv && V.kdv.oranlar) || [], ss = String(secili);
            if (or.map(String).indexOf(ss) < 0) ss = V.kdv && V.kdv.varsayilan != null ? String(V.kdv.varsayilan) : (or.length ? String(or[0]) : '');
            or.forEach(function (o) { secenek(s, String(o), '%' + o, String(o) === ss); });
        }
        function kartVarsayilanKdv(k) { var or = ((V.kdv && V.kdv.oranlar) || []).map(Number); return k && or.indexOf(Number(k.kdv)) >= 0 ? k.kdv : (V.kdv ? V.kdv.varsayilan : ''); }
        function kartSec(tr, k, yaz) {
            var onceki = el(tr, 'kart_id').value;
            el(tr, 'kart_id').value = k ? String(k.id) : '';
            if (String(onceki) !== (k ? String(k.id) : '') || !el(tr, 'birim').options.length) birimKur(tr, k, k ? '' : el(tr, 'birim').value);
            if (k && String(onceki) !== String(k.id)) kdvKur(tr, kartVarsayilanKdv(k));
            if (k && yaz !== false) el(tr, 'kart_ara').value = k.kod + ' — ' + k.ad;
        }

        /* — satır hesabı (plan §4) — */
        function satirMilli(tr) {
            var k = satirKart(tr), b = el(tr, 'birim').value, m;
            if (k) {
                if (!b || b === k.birim) { m = ondalikInt(el(tr, 'miktar').value, 3); if (isNaN(m) || m <= 0 || (tam(k.birim) && m % 1000)) return NaN; return m; }
                if (k.cevrim_birim && b === k.cevrim_birim) {
                    var cm = ondalikInt(el(tr, 'miktar').value, 3), kat = Math.round(Number(k.cevrim_katsayi) * 1000);
                    if (isNaN(cm) || cm <= 0 || (tam(b) && cm % 1000) || kat <= 0) return NaN;
                    m = carpBol(cm, kat, 1000);
                    return tam(k.birim) && m % 1000 ? NaN : m;
                }
                return NaN;
            }
            var bb = b || 'adet'; m = ondalikInt(el(tr, 'miktar').value, 3);
            return isNaN(m) || m <= 0 || (tam(bb) && m % 1000) ? NaN : m;
        }
        function satirBirim(tr) { var k = satirKart(tr); return k ? k.birim : (el(tr, 'birim').value || 'adet'); }
        function satirHesap(tr) {
            var mil = satirMilli(tr), fh = el(tr, 'birim_fiyat').value.trim(), f4 = fh ? ondalikInt(fh, 4) : null;
            var ih = el(tr, 'iskonto').value.trim(), bp = ih ? ondalikInt(ih, 2) : 0;
            var kdv = bilgi().kdv_sifir ? 0 : Number(el(tr, 'kdv_orani').value || 0);
            var r = { milli: mil, f4: f4, bp: bp, kdv: kdv, gecerli: !isNaN(mil) && (f4 === null || (!isNaN(f4) && f4 > 0)) && !isNaN(bp) && bp < 10000 };
            if (r.gecerli && f4 !== null) {
                r.brut = carpBol(mil, f4, 100000); r.isk = carpBol(r.brut, bp, 10000); r.net = r.brut - r.isk; r.kdvT = carpBol(r.net, kdv, 100);
            }
            return r;
        }
        function sonFiyat(k) { return FT.ozet && FT.ozet.son_fiyatlar && k ? FT.ozet.son_fiyatlar[String(k.id)] || null : null; }
        function stokDusen() { var b = bilgi(); return b.stok === 'cikis' && (FT.tur === 'irsaliye' || (f.elements.stok_etkisi && f.elements.stok_etkisi.checked)); }
        function satirGuncelle(tr, istenen) {
            var k = satirKart(tr), h = satirHesap(tr), ip = $('.ft-fs-ipucu', tr), cv = $('.ft-cevrim', tr), kl = $('.ft-kalan', tr);
            tr.classList.remove('ft-fsatir--eksik', 'ft-fsatir--kalan');
            var metin = satirMetin(tr);
            if (cv) cv.textContent = k && el(tr, 'birim').value && !isNaN(h.milli) ? '= ' + milliBicim(h.milli, k.birim) + ' ' + k.birim : '';
            var kalan = tr.getAttribute('data-kalan-milli');
            if (kl) {
                bosalt(kl);
                if (kalan !== '' && kalan != null) {
                    var km = Number(kalan); kl.textContent = 'kalan ' + milliBicim(km, satirBirim(tr)) + ' ' + satirBirim(tr);
                    if (!isNaN(h.milli) && h.milli > km) { tr.classList.add('ft-fsatir--kalan'); kl.textContent += ' — kalanı ' + milliBicim(h.milli - km, satirBirim(tr)) + ' aşıyor'; }
                }
            }
            if (ip) {
                bosalt(ip);
                if (!k) {
                    if (metin) ip.textContent = bilgi().stok_zorunlu ? 'Kart bulunamadı — listeden seçin' : 'Kart bulunamadı — serbest satır olarak kaydedilecek (stoğa girmez)';
                } else {
                    var bk = ((V.bakiye || {})[k.id] || {})[depo()] || 0;
                    var t = 'Mevcut ' + milliBicim(bk, k.birim) + ' ' + k.birim;
                    var sf = sonFiyat(k);
                    if (sf) t += ' · son ' + f4Bicim(sf.f4) + (sf.pb !== 'TRY' ? ' ' + sf.pb : '') + ' · ' + trTarih(sf.tarih);
                    if (stokDusen() && !(kaynakli(tr) && /^IRS-/.test(tr.getAttribute('data-kaynak-no') || '')) && istenen && istenen[k.id] > bk) { tr.classList.add('ft-fsatir--eksik'); t += ' — yetersiz'; }
                    ip.appendChild(document.createTextNode(t));
                    if (sf) {
                        var b = yap('button', '', 'Uygula'); b.type = 'button'; b.setAttribute('data-son-fiyat-uygula', ''); b.setAttribute('data-f4', String(sf.f4));
                        if (sf.pb !== pb()) { b.disabled = true; b.title = 'Son fiyat ' + sf.pb; } else b.title = 'Son fiyatı uygula (Alt+F) · ' + sf.belge_no;
                        ip.appendChild(b);
                        if (!el(tr, 'birim_fiyat').value) el(tr, 'birim_fiyat').placeholder = f4Bicim(sf.f4);
                    } else el(tr, 'birim_fiyat').placeholder = '';
                }
            }
            var out = $('.ft-fs-tutar-deger', tr);
            if (out) { out.textContent = h.net != null ? para(h.net) : '—'; out.title = h.net != null ? 'Brüt ' + para(h.brut) + ' · isk ' + para(h.isk) + ' · KDV ' + para(h.kdvT) : ''; }
            ozetYaz(tr, k, h);
            return h;
        }
        function ozetYaz(tr, k, h) {
            var oz = $('.ft-fs-ozet', tr); if (!oz) return;
            bosalt(oz);
            var ad = k ? k.ad : satirMetin(tr);
            var b = yap('b', '', ad || 'Yeni satır — ürün seçin'); oz.appendChild(b);
            if (!k && ad) { oz.appendChild(yap('span', 'ft-rozet', 'Kartsız')); }
            var mk = el(tr, 'miktar').value.trim(), fy = el(tr, 'birim_fiyat').value.trim();
            var bm = k ? (el(tr, 'birim').value || k.birim) : (el(tr, 'birim').value || 'adet');
            var p = [];
            if (mk) p.push(mk + ' ' + bm + (fy ? ' × ' + fy + ' ' + (SYM[pb()] || pb()) : ''));
            var ih = el(tr, 'iskonto').value.trim(); if (ih) p.push('İsk %' + ih);
            p.push('KDV %' + (el(tr, 'kdv_orani').value || '0'));
            oz.appendChild(yap('span', '', ' ' + p.join(' · ')));
            var kn = tr.getAttribute('data-kaynak-no'); if (kn) oz.appendChild(yap('span', 'ft-rozet ft-rozet--kaynak', kn));
        }
        function istenenHarita() {
            var o = {};
            if (!stokDusen()) return o;
            satirlar().forEach(function (tr) {
                var k = satirKart(tr); if (!k || (kaynakli(tr) && /^IRS-/.test(tr.getAttribute('data-kaynak-no') || ''))) return;
                var m = satirMilli(tr); if (!isNaN(m)) o[k.id] = (o[k.id] || 0) + m;
            });
            return o;
        }
        function tanimSec(ad) { var s = f.elements[ad]; if (!s || s.disabled || !s.value) return null; var o = s.options[s.selectedIndex]; return { ad: o.textContent, d1: Number(o.getAttribute('data-d1') || 0), d2: Number(o.getAttribute('data-d2') || 0) }; }
        var rafId = 0;
        function hesapla() { if (rafId) return; rafId = requestAnimationFrame(function () { rafId = 0; hepsi(); }); }
        var duyuruZ = null;
        function hepsi() {
            var ist = istenenHarita();
            var T = { brut: 0, isk: 0, net: 0, kdv: 0 }, gr = {}, n = 0;
            satirlar().forEach(function (tr) {
                var h = satirGuncelle(tr, ist);
                if (satirDolu(tr)) n++;
                if (h.net == null) return;
                T.brut += h.brut; T.isk += h.isk; T.net += h.net; T.kdv += h.kdvT;
                gr[h.kdv] = gr[h.kdv] || { net: 0, kdv: 0 }; gr[h.kdv].net += h.net; gr[h.kdv].kdv += h.kdvT;
            });
            var b = bilgi(), tev = b.tevkifat_izin ? tanimSec('tevkifat_id') : null, sto = b.mustahsil ? tanimSec('stopaj_id') : null, bag = b.mustahsil ? tanimSec('bagkur_id') : null;
            T.genel = T.net + T.kdv;
            T.tevkifat = tev && tev.d1 > 0 && tev.d2 > 0 ? carpBol(T.kdv, tev.d1, tev.d2) : 0;
            T.stopaj = sto ? carpBol(T.net, sto.d1, 10000) : 0;
            T.bagkur = bag ? carpBol(T.net, bag.d1, 10000) : 0;
            T.cari = T.genel - T.tevkifat - T.stopaj - T.bagkur;
            var kur6 = pb() !== 'TRY' && f.elements.kur && f.elements.kur.value.trim() ? ondalikInt(f.elements.kur.value, 6) : NaN;
            T.tl = !isNaN(kur6) && b.cari ? carpBol(T.genel, kur6, 1000000) : null;
            FT.toplam = T;
            var top = $('#ftToplam', f);
            var set = function (ad, v) { var e = $('[data-top="' + ad + '"]', top); if (e) e.textContent = v; };
            ['brut', 'isk', 'net', 'kdv'].forEach(function (a) { set(a, para(T[a])); });
            var g = $('[data-top="genel"]', top); if (g) { bosalt(g); g.appendChild(document.createTextNode(para(T.genel) + ' ')); g.appendChild(yap('small', '', pb())).setAttribute('data-top-pb', ''); }
            ['tevkifat', 'stopaj', 'bagkur'].forEach(function (a) { set(a, '−' + para(T[a])); });
            set('cari', para(T.cari)); set('tl', T.tl != null ? para(T.tl, 'TRY') : '—');
            var vis = function (ad, v) { var e = $('[data-satir="' + ad + '"]', top); if (e) e.hidden = !v; };
            vis('tevkifat', !!tev); vis('stopaj', !!b.mustahsil); vis('bagkur', !!b.mustahsil); vis('cari', !!b.cari); vis('tl', T.tl != null);
            var et = function (ad, v) { var e = $('[data-et="' + ad + '"]', top); if (e) e.textContent = v; };
            et('tevkifat', tev ? 'Tevkifat (' + tev.d1 + '/' + tev.d2 + ')' : 'Tevkifat');
            et('stopaj', sto ? 'GV stopajı %' + bpBicim(sto.d1) : 'GV stopajı'); et('bagkur', bag ? 'Bağ-Kur %' + bpBicim(bag.d1) : 'Bağ-Kur');
            if (T.tl != null) et('tl', 'TL karşılığı (kur ' + f.elements.kur.value.trim() + ')');
            var ce = $('[data-cari-etiket]', top); if (ce) ce.textContent = b.mustahsil ? 'Net ödenecek (üreticiye)' : 'Cariye yansıyan';
            var tb = $('[data-kdv-gruplar]', top);
            if (tb) {
                bosalt(tb);
                Object.keys(gr).map(Number).sort(function (a, c) { return a - c; }).forEach(function (o) {
                    var r = yap('tr'); r.setAttribute('data-kdv-grup', String(o));
                    r.appendChild(yap('td', '', '%' + o)); r.appendChild(yap('td', 'ft-sayi', para(gr[o].net))); r.appendChild(yap('td', 'ft-sayi', para(gr[o].kdv))); tb.appendChild(r);
                });
            }
            var ozG = $('[data-ozet="genel"]', f), ozC = $('[data-ozet="cari"]', f);
            if (ozG) ozG.textContent = para(T.genel, pb());
            if (ozC) ozC.textContent = para(T.cari, pb());
            var kp = $('[data-ozet-cari-kap]', f); if (kp) { kp.hidden = !b.cari; if (kp.firstChild) kp.firstChild.nodeValue = (b.mustahsil ? 'Net ödenecek' : 'Cariye') + ' '; }
            var sy = $('[data-satir-say]', f); if (sy) sy.textContent = '(' + n + ')';
            var ek = $('[data-satir-ekle]', f), mx = $('[data-satir-max]', f), cok = satirlar().length >= (V.satir_max || 60);
            if (ek) ek.disabled = cok; if (mx) mx.hidden = !cok;
            clearTimeout(duyuruZ);
            duyuruZ = setTimeout(function () { var d = $('[data-top-duyuru]', f); if (d) d.textContent = 'Genel toplam ' + para(T.genel) + ' ' + pb() + (b.cari ? ', cariye ' + para(T.cari) + ' ' + pb() : ''); }, 600);
            basOzet();
        }

        /* — tür — */
        function goster() {
            var t = FT.tur;
            $$('[data-ft-goster]', f).forEach(function (w) { w.hidden = (w.getAttribute('data-ft-goster') || '').split(' ').indexOf(t) < 0; });
            $$('input, select, textarea', f).forEach(function (x) {
                if (x.type === 'radio' && x.name === 'tur') return;
                var gizli = false, p = x.parentElement;
                while (p && p !== f) { if (p.hasAttribute && p.hasAttribute('data-ft-goster') && (p.getAttribute('data-ft-goster') || '').split(' ').indexOf(t) < 0) { gizli = true; break; } p = p.parentElement; }
                if (x.hasAttribute('data-ft-goster') && (x.getAttribute('data-ft-goster') || '').split(' ').indexOf(t) < 0) gizli = true;
                x.disabled = gizli;
            });
            pbGuncelle(true);
        }
        function turUygula(t, ilk) {
            var onceki = FT.tur; FT.tur = t;
            f.setAttribute('data-tur', t); KOK.setAttribute('data-tur', t);
            goster();
            var b = bilgi();
            var bas = $('[data-form-baslik]', f); if (bas && b.etiket) bas.textContent = 'Yeni ' + b.etiket;
            if (!ilk) {
                var oS = ((V.turler || {})[onceki] || {}).kdv_sifir, nS = !!b.kdv_sifir;
                satirlar().forEach(function (tr) {
                    var s = el(tr, 'kdv_orani');
                    if (nS && !oS) { tr.setAttribute('data-onceki', s.value); kdvKur(tr, '0'); }
                    else if (!nS && oS) { kdvKur(tr, tr.getAttribute('data-onceki') || kartVarsayilanKdv(satirKart(tr))); }
                    else kdvKur(tr, s.value);
                });
                var sb = document.getElementById('ftSatirSablon');
                if (sb) { var str = sb.content.firstElementChild; kdvSablon(str); }
                var ac = f.elements.aciklama;
                if (b.ihracat && ac && (!ac.value.trim() || ac.getAttribute('data-oto') === '1')) {
                    var sab = ((V.tanimlar || {}).sablon || []).filter(function (x) { return x.belge_turu === 'satis_ihracat'; })[0];
                    if (sab) { ac.value = sab.metin; ac.setAttribute('data-oto', '1'); }
                } else if (!b.ihracat && ac && ac.getAttribute('data-oto') === '1') { ac.value = ''; ac.removeAttribute('data-oto'); }
                if (f.elements.stok_etkisi && !f.elements.stok_etkisi.getAttribute('data-dokundu')) f.elements.stok_etkisi.checked = V.sinif === 'alis';
            }
            seriOnizle(); kaynakIpucu(); diSay();
            hepsi();
        }
        function kdvSablon(tr) {   // şablon satırındaki KDV seçenekleri tür ile güncel kalsın
            var s = $('[name="kdv_orani[]"]', tr); if (!s) return;
            bosalt(s);
            if (bilgi().kdv_sifir) { secenek(s, '0', '%0', true); s.setAttribute('aria-readonly', 'true'); return; }
            s.removeAttribute('aria-readonly');
            ((V.kdv && V.kdv.oranlar) || []).forEach(function (o) { secenek(s, String(o), '%' + o, V.kdv && String(o) === String(V.kdv.varsayilan)); });
        }
        var turSec = $('.ft-tur-sec', f);
        if (turSec) turSec.addEventListener('change', function (e) {
            var r = e.target; if (!r || r.name !== 'tur') return;
            if (satirlar().some(kaynakli)) {
                var esk = $('input[name="tur"][value="' + FT.tur + '"]', f); if (esk) esk.checked = true;
                toast('Kaynaklı satırlar var; türü değiştirmek için satırları kaldırın.', true); return;
            }
            degis(); turUygula(r.value, false);
        });
        function turKilit() { if (turSec) { var k = satirlar().some(kaynakli); turSec.setAttribute('aria-disabled', k ? 'true' : 'false'); turSec.title = k ? 'Kaynaklı satırlar var; türü değiştirmek için satırları kaldırın' : ''; } }

        /* — başlık: cari / pb / seri / özet — */
        function pbGuncelle(sessiz) {
            var p = f.elements.para_birimi, kur = f.elements.kur; if (!p || !kur) return;
            kur.disabled = p.value === 'TRY'; if (kur.disabled) kur.value = '';
            var et = $('[data-pb-etiket]', f); if (et) et.textContent = p.value;
            if (!sessiz) hesapla();
        }
        function cariGirdi(degisim) {
            var inp = f.elements.cari_ara; if (!inp) return;
            var c = cariBulV(inp.value), eski = FT.cari;
            if (eski && (!c || c.id !== eski.id) && satirlar().some(kaynakli)) {
                var n = satirlar().filter(kaynakli).length;
                if (!window.confirm('Kaynaklı ' + n + ' satır kaldırılacak. Devam edilsin mi?')) { inp.value = eski.kod + ' — ' + eski.unvan; return; }
                satirlar().filter(kaynakli).forEach(function (tr) { tr.remove(); });
                if (!satirlar().length) satirEkle(false);
                numarala();
            }
            FT.cari = c; f.elements.cari_id.value = c ? String(c.id) : '';
            if (!c) { FT.ozet = null; ozetCiz(); hesapla(); return; }
            if (FT.uyg !== c.id) {   // yeni cari ilk kez çözüldü (yazarken ya da seçerken): pb + vade bir kez uygulanır
                FT.uyg = c.id;
                if (f.elements.para_birimi && c.pb && !satirlar().some(satirDolu) && f.elements.para_birimi.value !== c.pb) { f.elements.para_birimi.value = c.pb; pbGuncelle(true); }
                vadeOto();
            }
            if (!eski || eski.id !== c.id || !FT.ozet) cariOzetAl(c);
            if (degisim && (!eski || eski.id !== c.id)) inp.value = c.kod + ' — ' + c.unvan;
        }
        function vadeOto() {
            var v = f.elements.vade_tarihi, t = f.elements.tarih; if (!v || v.disabled || !t || !t.value || !FT.cari) return;
            if (v.value && v.getAttribute('data-oto') !== '1') return;
            if (FT.cari.vade_gun == null) { if (v.getAttribute('data-oto') === '1') { v.value = ''; } return; }
            var d = new Date(t.value + 'T00:00:00'); d.setDate(d.getDate() + Number(FT.cari.vade_gun));
            v.value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); v.setAttribute('data-oto', '1');
        }
        function cariOzetAl(c) {
            var no = ++FT.ozetNo;
            jsonAl(V.sayfa + '?parca=cari_ozet&cari_id=' + encodeURIComponent(c.id)).then(function (j) {
                if (no !== FT.ozetNo) return;   // eski yanıt atılır (yarış)
                FT.ozet = j; ozetCiz(); kaynakIpucu(); hesapla();
            }).catch(function () { /* özet bilgi amaçlı: sessiz */ });
        }
        function ozetCiz() {
            var k = document.getElementById('ftCariOzet'); if (!k) return;
            bosalt(k);
            if (!FT.ozet) return;
            var bk = FT.ozet.bakiyeler || {};
            Object.keys(bk).forEach(function (p) {
                var v = Number(bk[p]), x = v > 0 ? 'b' : (v < 0 ? 'a' : 'd');
                var s = yap('span', 'ft-bk ft-bk--' + x); s.setAttribute('data-pb', p);
                s.setAttribute('aria-label', para(Math.abs(v)) + ' ' + p + ', ' + ({ b: 'borçlu', a: 'alacaklı', d: 'denk' })[x]);
                s.appendChild(yap('span', '', para(Math.abs(v), p))); var y = yap('span', 'ft-bk-yon', x.toUpperCase()); y.setAttribute('aria-hidden', 'true'); s.appendChild(y);
                k.appendChild(s);
            });
            if (!Object.keys(bk).length) k.appendChild(yap('span', 'ft-ipucu', 'Bakiye yok'));
        }
        function kaynakIpucu() {
            var say = FT.ozet ? Number(FT.ozet.acik_kaynak_say || 0) : 0;
            var hedefVar = !!(V.gecisler || {})[FT.tur];
            var ip = $('.ft-kaynak-ipucu', f), r = $('.ft-kaynak-say', f);
            var goster = say > 0 && hedefVar && !satirlar().some(kaynakli) && (FT.tur === 'satis' || FT.tur === 'satis_ihracat' || FT.tur === 'irsaliye');
            if (ip) { ip.hidden = !goster; var m = $('[data-kaynak-ipucu-metin]', ip); if (m) m.textContent = 'ⓘ Bu carinin kalanı olan ' + say + ' ' + (FT.tur === 'irsaliye' ? 'siparişi' : 'irsaliye/siparişi') + ' var.'; }
            if (r) { r.hidden = !(say > 0 && hedefVar); r.textContent = String(say); }
        }
        function seriOnizle() {
            $$('[data-seri-onizleme]', f).forEach(function (sp) {
                var s = sp.parentNode.querySelector('select[name="seri_id"]');
                if (!s || s.disabled) { sp.textContent = ''; return; }
                var yil = (f.elements.tarih && f.elements.tarih.value || '').slice(0, 4);
                var on = ((V.seri_onizleme || {})[s.value] || {})[yil];
                sp.textContent = on ? '→ ' + on + ' (tahmini)' : (s.value ? 'Numara kayıtta verilir' : ''); sp.title = 'Kesin numara kayıtta verilir';
            });
            var kay = $('[data-kaydet]', f), seriYok = $$('[data-ft-on-sart="seri"]', f).some(function (x) { return !x.hidden; });
            var cariYok = !!$('[data-ft-on-sart="cari"]', f);
            if (kay) { kay.disabled = seriYok || cariYok; kay.title = seriYok ? 'Bu tür için seri tanımlı değil' : (cariYok ? 'Önce cari kartı oluşturun' : ''); }
        }
        function basOzet() {
            var o = $('[data-bas-ozet]', f); if (!o) return;
            var seri = $$('select[name="seri_id"]', f).filter(function (x) { return !x.disabled; })[0];
            var p = [FT.cari ? FT.cari.unvan : 'Cari seçilmedi', f.elements.tarih ? trTarih(f.elements.tarih.value, true) : '', seri && seri.value ? seri.options[seri.selectedIndex].textContent.split(' — ')[0] : '', pb(), depo()];
            o.textContent = p.filter(Boolean).join(' · ');
        }
        function basDaralt(ac) {
            var b = $('#ftBas', f), btn = $('[data-bas-daralt]', f); if (!b) return;
            if (ac == null) ac = b.classList.contains('ft-bas--ozet');
            b.classList.toggle('ft-bas--ozet', !ac); b.setAttribute('aria-expanded', ac ? 'true' : 'false'); if (btn) btn.setAttribute('aria-expanded', ac ? 'true' : 'false');
            basOzet();
        }
        function diSay() {
            var d = $('#ftDiger', f); if (!d) return;
            var n = $$('input, select, textarea', d).filter(function (x) { return !x.disabled && !x.hasAttribute('data-sablon') && String(x.value || '').trim(); }).length;
            var s = $('[data-diger-say]', d); if (s) s.textContent = n > 0 ? '(' + n + ' dolu)' : '';
        }

        /* — satır ekle / sil / kopya — */
        function numarala() {
            satirlar().forEach(function (tr, i) {
                tr.setAttribute('data-i', String(i));
                var n = $('.ft-fs-no', tr); if (n) n.textContent = String(i + 1);
                var L = 'Satır ' + (i + 1) + ' ';
                [['kart_ara', 'ürün'], ['miktar', 'miktar'], ['birim', 'birim'], ['birim_fiyat', 'birim fiyat (KDV hariç)'], ['iskonto', 'iskonto yüzde'], ['kdv_orani', 'KDV oranı']].forEach(function (x) { var e = el(tr, x[0]); if (e) e.setAttribute('aria-label', L + x[1]); });
                var ac = $('.ft-fs-ac', tr); if (ac) ac.setAttribute('aria-label', L + 'düzenle');
                var mb = $('[data-satir-menu]', tr); if (mb) mb.setAttribute('aria-label', L + 'işlemleri');
            });
            turKilit();
        }
        function satirEkle(odak) {
            var max = V.satir_max || 60;
            if (satirlar().length >= max) { toast('Bir belgede en çok ' + max + ' satır olabilir.', true); return null; }
            var sb = document.getElementById('ftSatirSablon');
            var tr = sb.content.firstElementChild.cloneNode(true);
            $('.ft-fsatirlar', f).appendChild(tr);
            kdvKur(tr, bilgi().kdv_sifir ? '0' : (V.kdv ? V.kdv.varsayilan : ''));
            numarala(); goster(); degis();
            if (odak) { var k = $('.ft-kart-ara', tr); if (k && k.getClientRects().length) k.focus(); }
            hesapla();
            return tr;
        }
        function satirSil(tr) {
            var ss = satirlar(), i = ss.indexOf(tr);
            if (ss.length > 1) { tr.remove(); var y = satirlar(); var o = y[Math.min(i, y.length - 1)]; if (o && !mobil()) { var k = $('.ft-kart-ara', o); if (k) k.focus(); } }
            else {
                $$('input:not([type=hidden])', tr).forEach(function (x) { x.value = ''; });
                ['kart_id', 'urun_adi', 'kaynak_satir_id'].forEach(function (a) { el(tr, a).value = ''; });
                tr.setAttribute('data-kaynak-satir', ''); tr.setAttribute('data-kalan-milli', ''); tr.removeAttribute('data-kaynak-no'); tr.classList.remove('ft-fsatir--kaynakli');
                var rz = $('.ft-rozet--kaynak', tr); if (rz) rz.remove();
                birimKur(tr, null, ''); if (!mobil()) $('.ft-kart-ara', tr).focus();
            }
            numarala(); degis(); kaynakIpucu(); hesapla();
        }
        function satirKopya(tr) {
            var y = tr.cloneNode(true);
            ['kaynak_satir_id'].forEach(function (a) { el(y, a).value = ''; });
            y.setAttribute('data-kaynak-satir', ''); y.setAttribute('data-kalan-milli', ''); y.removeAttribute('data-kaynak-no'); y.classList.remove('ft-fsatir--kaynakli', 'ft-fsatir--hata');
            $$('.ft-rozet--kaynak, .ft-hata', y).forEach(function (x) { x.remove(); });
            $$('select', tr).forEach(function (s, i) { $$('select', y)[i].value = s.value; });
            if (satirlar().length >= (V.satir_max || 60)) { toast('Bir belgede en çok ' + (V.satir_max || 60) + ' satır olabilir.', true); return; }
            tr.parentNode.insertBefore(y, tr.nextSibling);
            numarala(); degis(); hesapla();
            if (!mobil()) { var k = el(y, 'miktar'); if (k) k.focus(); }
        }
        function sonFiyatUygula(tr) {
            var k = satirKart(tr), sf = sonFiyat(k);
            if (!sf) return;
            if (sf.pb !== pb()) { toast('Son fiyat ' + sf.pb + '; belge ' + pb() + '.', true); return; }
            el(tr, 'birim_fiyat').value = f4Bicim(sf.f4); degis(); hesapla();
        }

        /* — olaylar — */
        function kartGirdi(inp, yaz) {
            var tr = inp.closest('.ft-fsatir'), k = kartBul(inp.value);
            if (kaynakli(tr) && satirKart(tr) && k !== satirKart(tr)) { var kk = satirKart(tr); if (yaz) { inp.value = kk.kod + ' — ' + kk.ad; toast('Kaynak satırdaki stok kartı değiştirilemez.', true); } return; }
            if (k !== satirKart(tr)) kartSec(tr, k, false);
            if (!k) el(tr, 'urun_adi').value = inp.value.trim();
            if (k && yaz) inp.value = k.kod + ' — ' + k.ad;
            hesapla();
        }
        f.addEventListener('input', function (e) {
            var t = e.target; degis();
            if (t.classList.contains('ft-kart-ara')) { kartGirdi(t, false); return; }
            if (t.name === 'cari_ara') { cariGirdi(false); return; }
            if (t.name === 'aciklama') t.removeAttribute('data-oto');
            if (t.name === 'vade_tarihi') t.removeAttribute('data-oto');
            hesapla();
        });
        f.addEventListener('change', function (e) {
            var t = e.target; degis();
            if (t.classList.contains('ft-kart-ara')) { kartGirdi(t, true); return; }
            if (t.name === 'cari_ara') { cariGirdi(true); return; }
            if (t.name === 'para_birimi') { pbGuncelle(); if (t.value !== 'TRY' && f.elements.kur) f.elements.kur.focus(); }
            if (t.name === 'tarih') { vadeOto(); seriOnizle(); }
            if (t.name === 'seri_id') seriOnizle();
            if (t.name === 'stok_etkisi') t.setAttribute('data-dokundu', '1');
            if (t.hasAttribute('data-sablon')) {
                var o = t.options[t.selectedIndex], ac = f.elements.aciklama;
                if (o && o.value && ac) { ac.value = (ac.value.trim() ? ac.value.trim() + '\n' : '') + o.getAttribute('data-metin'); ac.removeAttribute('data-oto'); }
                t.value = '';
            }
            diSay(); hesapla();
        });
        f.addEventListener('focusout', function (e) {
            var t = e.target;
            if (t.classList && t.classList.contains('ft-kart-ara')) { var k = satirKart(t.closest('.ft-fsatir')); if (k) t.value = k.kod + ' — ' + k.ad; }
        });
        f.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('[data-satir-ekle], [data-satir-menu], [data-son-fiyat-uygula], [data-kalana-esitle], [data-eksik-duzelt], [data-eksik-kaydet], [data-bas-daralt], [data-kaynak-getir], [data-yeniden], [data-odak-ad], .ft-fs-ac') : null;
            if (!b) return;
            e.preventDefault();
            if (b.hasAttribute('data-satir-ekle')) { var tr0 = satirEkle(true); if (tr0 && mobil()) sheetAc(tr0, 'ara', true); }
            else if (b.hasAttribute('data-satir-menu')) menuAc(b);
            else if (b.hasAttribute('data-son-fiyat-uygula')) sonFiyatUygula(b.closest('.ft-fsatir'));
            else if (b.hasAttribute('data-kalana-esitle')) { var tr = b.closest('.ft-fsatir'); el(tr, 'miktar').value = milliBicim(Number(tr.getAttribute('data-kalan-milli')), satirBirim(tr)); b.remove(); degis(); hesapla(); }
            else if (b.hasAttribute('data-eksik-duzelt')) { eksikGizle(); var ek = $('.ft-fsatir--eksik', f) || satirlar()[0]; if (ek) { if (mobil()) sheetAc(ek, 'miktar'); else el(ek, 'miktar').focus(); } }
            else if (b.hasAttribute('data-eksik-kaydet') || b.hasAttribute('data-yeniden')) kaydet(b.hasAttribute('data-eksik-kaydet'));
            else if (b.hasAttribute('data-bas-daralt')) basDaralt();
            else if (b.hasAttribute('data-kaynak-getir')) kaynakAc(b);
            else if (b.hasAttribute('data-odak-ad')) odakAd(b.getAttribute('data-odak-ad'), b.getAttribute('data-odak-i'));
            else if (b.classList.contains('ft-fs-ac')) sheetAc(b.closest('.ft-fsatir'), satirKart(b.closest('.ft-fsatir')) ? 'miktar' : 'ara');
        });
        function odaklanabilir(kok) { return $$('input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select', kok).filter(function (x) { return !x.disabled && x.getClientRects().length && !x.closest('[hidden]') && !x.closest('details:not([open]) > .ft-sx-ic, details:not([open]) > .ft-bas-grid'); }); }
        function sonraki(t, geri) {
            var tr = t.closest('.ft-fsatir');
            if (tr) {
                var h = odaklanabilir(tr).filter(function (x) { return !x.closest('.ft-sx'); }), i = h.indexOf(t);
                if (geri) { if (i > 0) h[i - 1].focus(); return; }
                if (i >= 0 && i < h.length - 1) { h[i + 1].focus(); if (h[i + 1].select && h[i + 1].tagName === 'INPUT') h[i + 1].select(); return; }
                var ss = satirlar();
                if (tr === ss[ss.length - 1]) { if (satirDolu(tr)) satirEkle(true); return; }
                var n = odaklanabilir(tr.nextElementSibling)[0]; if (n) n.focus();
                return;
            }
            var tum = odaklanabilir($('.ft-form-govde', f)), j = tum.indexOf(t);
            if (geri) { if (j > 0) tum[j - 1].focus(); return; }
            if (j >= 0 && j < tum.length - 1) tum[j + 1].focus();
        }
        f.addEventListener('keydown', function (e) {
            var t = e.target, tr = t.closest ? t.closest('.ft-fsatir') : null;
            if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S' || e.key === 'Enter')) { e.preventDefault(); kaydet(false); return; }
            if (e.ctrlKey && e.shiftKey && e.key === 'ArrowDown' && tr) { e.preventDefault(); satirKopya(tr); return; }
            if (e.ctrlKey && e.key === 'ArrowDown') { e.preventDefault(); satirEkle(true); return; }
            if (e.ctrlKey && e.key === 'Delete' && tr) { e.preventDefault(); satirSil(tr); return; }
            if (e.altKey && (e.key === 'f' || e.key === 'F') && tr) { e.preventDefault(); sonFiyatUygula(tr); return; }
            if (e.altKey && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); kaynakAc($('[data-kaynak-getir]', f)); return; }
            if (e.altKey && (e.key === 'b' || e.key === 'B')) { e.preventDefault(); basDaralt(); return; }
            if (e.key === 'Escape') {
                if (!$('#ftEksik', f).hidden) { e.preventDefault(); eksikGizle(); return; }
                if (degisti && window.confirm('Kaydedilmemiş değişiklikler var. Formdan çıkılsın mı?')) { degisti = false; location.assign(V.sayfa); }
                return;
            }
            if (e.key === 'Tab' && !e.shiftKey && tr && t.name === 'kdv_orani[]') {
                var ss = satirlar();
                if (tr === ss[ss.length - 1] && satirDolu(tr)) { e.preventDefault(); satirEkle(true); }
                return;
            }
            if (e.key === 'Enter' && !e.altKey && !e.ctrlKey && !e.metaKey && t.matches && t.matches('input:not([type=submit]):not([type=checkbox]):not([type=radio]), select')) {
                e.preventDefault();
                if (t.classList.contains('ft-kart-ara')) kartGirdi(t, true);
                if (t.name === 'cari_ara') cariGirdi(true);
                sonraki(t, e.shiftKey);
            }
        });
        f.addEventListener('submit', function (e) { e.preventDefault(); kaydet(false); });

        /* — yetersiz stok / kaydet — */
        function eksikGizle() { $('#ftEksik', f).hidden = true; }
        function eksikGoster(list) {
            var kutu = $('#ftEksik', f), tb = $('tbody', kutu); bosalt(tb);
            var idler = {};
            (list || []).forEach(function (x) {
                idler[String(x.kart_id)] = true;
                var r = yap('tr');
                [x.kod + ' — ' + x.ad, x.depo || '—', milliBicim(x.istenen_milli, x.birim) + ' ' + x.birim, milliBicim(x.mevcut_milli, x.birim) + ' ' + x.birim, milliBicim(x.eksik_milli, x.birim) + ' ' + x.birim]
                    .forEach(function (v, i) { r.appendChild(yap('td', i > 1 ? 'ft-sayi' : '', v)); });
                tb.appendChild(r);
            });
            satirlar().forEach(function (tr) { if (idler[el(tr, 'kart_id').value]) tr.classList.add('ft-fsatir--eksik'); });
            kutu.hidden = false;
            try { kutu.scrollIntoView({ block: 'nearest' }); } catch (x) { /* eski tarayıcı */ }
            var d = $('[data-eksik-kaydet]', kutu); if (d) d.focus();
        }
        var alanEsle = { cari_id: 'cari_ara', satir: '' };
        var satirEsle = { kart_id: 'kart_ara', urun_adi: 'kart_ara', kaynak_satir_id: 'kart_ara', brut_kg: 'satir_brut_kg', net_kg: 'satir_net_kg', kap_adedi: 'satir_kap', aciklama: 'satir_aciklama' };
        function satirHata(i, alan, msg, kod) {
            var tr = satirlar()[Number(i)]; if (!tr) return null;
            tr.classList.add('ft-fsatir--hata');
            var x = el(tr, satirEsle[alan] || alan);
            if (!x || x.type === 'hidden') x = el(tr, 'kart_ara');
            var d = x.closest('details'); if (d) d.open = true;
            var kap = mobil() ? $('.ft-fs-kart', tr) : x.closest('td');
            hataSpan(x, msg, kap);
            if (alan === 'miktar' && kod === 'kalan_asimi' && tr.getAttribute('data-kalan-milli')) {
                var b = yap('button', 'btn btn-sm', 'Kalana eşitle'); b.type = 'button'; b.setAttribute('data-kalana-esitle', ''); kap.appendChild(b);
            }
            return x;
        }
        function odakAd(ad, i) {
            if (i != null && i !== '') { var tr = satirlar()[Number(i)]; if (tr) { if (mobil()) sheetAc(tr, 'miktar'); else { var x = $('[aria-invalid="true"]', tr) || el(tr, 'kart_ara'); x.focus(); } } return; }
            var e2 = $$('[name="' + ad + '"]', f).filter(function (x) { return !x.disabled && x.type !== 'hidden'; })[0];
            if (e2) { var b = $('#ftBas', f); if (b && b.classList.contains('ft-bas--ozet') && b.contains(e2)) basDaralt(true); var d = e2.closest('details'); if (d) d.open = true; e2.focus(); }
        }
        function hataOzet(msg, liste2, ekDugme) {
            var g = $('#ftHataOzet', f); bosalt(g); g.hidden = false;
            g.appendChild(yap('b', '', msg));
            if (liste2.length > 1) {
                var ul = yap('ul');
                liste2.slice(0, 8).forEach(function (x) { var li = yap('li'), a = yap('a', '', x.metin); a.href = '#'; a.setAttribute('data-odak-ad', x.ad || ''); if (x.i != null) a.setAttribute('data-odak-i', String(x.i)); li.appendChild(a); ul.appendChild(li); });
                g.appendChild(yap('span', '', ' (' + liste2.length + ' hata)')); g.appendChild(ul);
            }
            if (ekDugme) g.appendChild(ekDugme);
            try { g.scrollIntoView({ block: 'nearest' }); } catch (x) { /* eski tarayıcı */ }
        }
        function formVerisi() {
            var fd = new FormData(f);
            fd.delete('kart_ara[]'); fd.delete('urun_adi[]');
            var zor = !!bilgi().stok_zorunlu;
            satirlar().forEach(function (tr) {
                var kid = el(tr, 'kart_id').value, m = satirMetin(tr);
                if (kid) { fd.append('kart_ara[]', ''); fd.append('urun_adi[]', ''); }
                else if (m && !zor) { fd.append('kart_ara[]', ''); fd.append('urun_adi[]', m); }
                else { fd.append('kart_ara[]', m); fd.append('urun_adi[]', ''); }
            });
            return fd;
        }
        function kaydet(eksiye) {
            if (f.dataset.kilit === '1') return;
            hataTemizle(f); var g0 = $('#ftHataOzet', f); g0.hidden = true; bosalt(g0);
            satirlar().forEach(function (tr) { tr.classList.remove('ft-fsatir--hata'); });
            $$('[data-kalana-esitle]', f).forEach(function (x) { x.remove(); });
            $('#ftToplam', f).removeAttribute('aria-invalid');
            if (!eksiye) eksikGizle();
            if (!FT.cari) { var cA = f.elements.cari_ara; basDaralt(true); hataSpan(cA, 'Cari seçin.'); hataOzet('Cari seçin.', []); cA.focus(); return; }
            if (!satirlar().some(satirDolu)) { hataOzet('En az bir satır girin.', []); var k0 = $('.ft-kart-ara', f); if (k0 && k0.getClientRects().length) k0.focus(); return; }
            f.elements.eksiye_izin.value = eksiye ? '1' : '0';
            var btn = $('[data-kaydet]', f), etiket = btn.textContent;
            f.dataset.kilit = '1'; f.setAttribute('data-kilit', '1'); btn.disabled = true; btn.textContent = 'Kaydediliyor…';
            var bitir = function () { f.dataset.kilit = ''; f.removeAttribute('data-kilit'); btn.disabled = false; btn.textContent = etiket; };
            fetch(S.kaydet, { method: 'POST', body: formVerisi(), headers: S.baslik, credentials: 'same-origin' })
                .then(yanitCoz)
                .then(function (s) {
                    var j = s.j;
                    if (j && j.ok) {
                        f.dataset.bitti = '1'; degisti = false;
                        yenileToastla((j.tekrar ? 'Belge zaten kaydedilmişti' : 'Kaydedildi') + (j.belge_no ? ' · ' + j.belge_no : '') + (j.fatura_no ? ' · ' + j.fatura_no : '') + (j.uyari ? ' — ' + j.uyari : ''), j.yonlendir);
                        return;
                    }
                    bitir();
                    if (j && j.kod === 'yetersiz_stok') { eksikGoster(j.eksikler); return; }
                    var liste2 = [], ilk = null;
                    if (j && j.alanlar) Object.keys(j.alanlar).forEach(function (ad) {
                        var hedef = alanEsle[ad] != null ? alanEsle[ad] : ad;
                        if (ad === 'satir' && /tutar/i.test(j.alanlar[ad])) $('#ftToplam', f).setAttribute('aria-invalid', 'true');
                        if (!hedef) return;
                        var x = $$('[name="' + hedef + '"]', f).filter(function (y) { return !y.disabled && y.type !== 'hidden'; })[0];
                        if (x) { var b = $('#ftBas', f); if (b && b.contains(x) && b.classList.contains('ft-bas--ozet')) basDaralt(true); }
                        var el2 = alanHata(f, hedef, j.alanlar[ad]); if (el2) { liste2.push({ ad: hedef, metin: j.alanlar[ad] }); if (!ilk) ilk = el2; }
                    });
                    if (j && j.satir_hatalari) Object.keys(j.satir_hatalari).forEach(function (i) {
                        Object.keys(j.satir_hatalari[i]).forEach(function (a) { var x = satirHata(i, a, j.satir_hatalari[i][a], j.kod); if (x) { liste2.push({ i: i, metin: 'Satır ' + (Number(i) + 1) + ': ' + j.satir_hatalari[i][a] }); if (!ilk) ilk = x; } });
                    });
                    var ek = null;
                    if (j && /Numara çakışması/.test(j.hata || '')) { ek = yap('button', 'btn btn-sm', 'Yeniden kaydet'); ek.type = 'button'; ek.setAttribute('data-yeniden', ''); }
                    if (!j && s.r.status === 503) hataOzet('Fatura tabloları kurulmamış — Şema Migrasyon › Fatura.', []);
                    else hataOzet((j && j.hata) || ('Kaydedilemedi (HTTP ' + s.r.status + ').'), liste2, ek);
                    if (ilk && ilk.focus && ilk.getClientRects().length) ilk.focus();
                })
                .catch(function () { bitir(); hataOzet(S.m.ag, []); });
        }

        /* — Kaynaktan getir (2 adım) — */
        var KM = document.getElementById('ftModalKaynak');
        var KA = { adaylar: [], satirlar: [] };
        function kaynakAdim(n) {
            $$('[data-kaynak-adim]', KM).forEach(function (d) { d.hidden = d.getAttribute('data-kaynak-adim') !== String(n); });
            $$('[data-adim-etiket]', KM).forEach(function (l) { if (l.getAttribute('data-adim-etiket') === String(n)) l.setAttribute('aria-current', 'step'); else l.removeAttribute('aria-current'); });
            $('[data-kaynak-ileri]', KM).hidden = n !== 1; $('[data-kaynak-ekle]', KM).hidden = n !== 2; $('[data-kaynak-geri]', KM).hidden = n !== 2;
        }
        function kaynakAc(t) {
            if (!KM) return;
            if (!(V.gecisler || {})[FT.tur]) { toast('Bu belge türüne kaynaktan aktarım yapılmaz.', true); return; }
            if (!FT.cari) { toast('Önce cari seçin.', true); var cA = f.elements.cari_ara; basDaralt(true); if (cA) cA.focus(); return; }
            var fm = $('form', KM); hataTemizle(fm);
            fm.elements.tur.value = FT.tur; fm.setAttribute('action', V.sayfa);
            $('#ftKaynakBaslik').textContent = 'Kaynaktan getir — ' + (bilgi().etiket || '');
            $('[data-kaynak-cari]', KM).textContent = 'Cari: ' + FT.cari.unvan;
            var ul = $('[data-kaynak-liste]', KM); bosalt(ul); ul.setAttribute('aria-busy', 'true'); ul.appendChild(yap('li', 'ft-ipucu', 'Yükleniyor…'));
            kaynakAdim(1); $('[data-kaynak-ozet]', KM).textContent = '';
            modalAc('ftModalKaynak', t);
            jsonAl(V.sayfa + '?parca=kaynaklar&tur=' + encodeURIComponent(FT.tur) + '&cari_id=' + encodeURIComponent(FT.cari.id)).then(function (j) {
                KA.adaylar = j.adaylar || []; bosalt(ul); ul.removeAttribute('aria-busy');
                var faturaHedef = !!bilgi().cari;
                if (!KA.adaylar.length) { ul.appendChild(yap('li', 'ft-liste-bos', 'Bu cari için kalanı olan kaynak belge yok.')); return; }
                KA.adaylar.forEach(function (a) {
                    var li = yap('li'), lb = yap('label', 'ft-kaynak-satir'), cb = document.createElement('input');
                    cb.type = 'checkbox'; cb.name = 'kaynak[]'; cb.value = String(a.id);
                    var pbFarkli = faturaHedef && a.para_birimi !== pb() && satirlar().some(satirDolu);
                    if (pbFarkli) { cb.disabled = true; lb.classList.add('ft-kaynak-satir--pasif'); }
                    lb.appendChild(cb);
                    var u = yap('span', 'ft-kaynak-ust'); u.appendChild(yap('span', '', a.belge_no)); u.appendChild(yap('span', 'ft-ipucu', trTarih(a.tarih, true))); lb.appendChild(u);
                    lb.appendChild(yap('span', 'ft-kaynak-alt', [a.fatura_no || '', a.satir_say + ' satır', a.depo, a.para_birimi, pbFarkli ? 'Para birimi farklı (' + a.para_birimi + ')' : ''].filter(Boolean).join(' · ')));
                    lb.appendChild(yap('span', 'ft-kaynak-sag', 'kalan ' + milliBicim(a.kalan_milli, 'lt')));
                    li.appendChild(lb); ul.appendChild(li);
                });
            }).catch(function (x) { bosalt(ul); ul.removeAttribute('aria-busy'); genelHata($('form', KM), 'Kaynaklar yüklenemedi: ' + x.message); });
        }
        if (KM) {
            KM.addEventListener('change', function () { var n = $$('input[name="kaynak[]"]:checked', KM).length; if (!$('[data-kaynak-adim="1"]', KM).hidden) $('[data-kaynak-ozet]', KM).textContent = n + ' belge seçili'; });
            KM.addEventListener('click', function (e) {
                var b = e.target.closest ? e.target.closest('[data-kaynak-ileri], [data-kaynak-geri], [data-kaynak-ekle]') : null;
                if (!b) return;
                e.preventDefault();
                var fm = $('form', KM); hataTemizle(fm);
                if (b.hasAttribute('data-kaynak-geri')) { kaynakAdim(1); return; }
                if (b.hasAttribute('data-kaynak-ileri')) {
                    var ids = $$('input[name="kaynak[]"]:checked', KM).map(function (x) { return x.value; });
                    if (!ids.length) { genelHata(fm, 'En az bir belge seçin.'); return; }
                    var tb = $('[data-kaynak-satirlar]', KM); bosalt(tb); kaynakAdim(2);
                    var yk = yap('tr'); yk.appendChild(yap('td', 'ft-ipucu', 'Yükleniyor…')).colSpan = 5; tb.appendChild(yk);
                    jsonAl(V.sayfa + '?parca=kaynak_satirlari&tur=' + encodeURIComponent(FT.tur) + '&ids=' + encodeURIComponent(ids.join(','))).then(function (j) {
                        KA.satirlar = j.satirlar || []; bosalt(tb);
                        if (!KA.satirlar.length) { var r0 = yap('tr'); r0.appendChild(yap('td', 'ft-liste-bos', 'Aktarılacak kalan satır yok.')).colSpan = 5; tb.appendChild(r0); return; }
                        KA.satirlar.forEach(function (s, i) {
                            var r = yap('tr'), c0 = yap('td'), cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = true; cb.setAttribute('data-kaynak-sec', String(i)); cb.setAttribute('aria-label', s.urun_adi + ' aktar'); c0.appendChild(cb); r.appendChild(c0);
                            r.appendChild(yap('td', '', s.urun_adi)); r.appendChild(yap('td', 'ft-ipucu', s.kaynak_belge_no));
                            r.appendChild(yap('td', 'ft-sayi', milliBicim(s.kalan_milli, s.birim) + ' ' + s.birim));
                            var c4 = yap('td', 'ft-sayi'), inp = document.createElement('input'); inp.type = 'text'; inp.inputMode = 'decimal'; inp.value = s.miktar; inp.setAttribute('data-aktar-milli', String(s.kalan_milli)); inp.setAttribute('data-i', String(i)); inp.setAttribute('aria-label', s.urun_adi + ' aktarılacak miktar');
                            c4.appendChild(inp); r.appendChild(c4); tb.appendChild(r);
                        });
                        $('[data-kaynak-ozet]', KM).textContent = KA.satirlar.length + ' satır';
                        var i0 = $('input[data-aktar-milli]', tb); if (i0) i0.focus();
                    }).catch(function (x) { bosalt(tb); genelHata(fm, 'Satırlar yüklenemedi: ' + x.message); });
                    return;
                }
                // Forma ekle
                var sec = [], hata = false;
                $$('[data-kaynak-satirlar] tr', KM).forEach(function (r) {
                    var cb = $('input[data-kaynak-sec]', r), inp = $('input[data-aktar-milli]', r); if (!cb || !cb.checked || !inp) return;
                    var s = KA.satirlar[Number(cb.getAttribute('data-kaynak-sec'))], m = ondalikInt(inp.value, 3), mx = Number(inp.getAttribute('data-aktar-milli'));
                    if (isNaN(m) || m <= 0 || (tam(s.birim) && m % 1000)) { hataSpan(inp, 'Geçerli miktar girin.', inp.parentNode); hata = true; return; }
                    if (m > mx) { hataSpan(inp, 'Kalanı aşıyor (en çok ' + milliBicim(mx, s.birim) + ').', inp.parentNode); hata = true; return; }
                    sec.push({ s: s, miktar: milliBicim(m, s.birim) });
                });
                if (hata) return;
                if (!sec.length) { genelHata(fm, 'En az bir satır seçin.'); return; }
                if (satirlar().length - satirlar().filter(function (x) { return !satirDolu(x); }).length + sec.length > (V.satir_max || 60)) { genelHata(fm, 'Bir belgede en çok ' + (V.satir_max || 60) + ' satır olabilir.'); return; }
                kaynakEkle(sec);
                modalKapat();
            });
        }
        function kaynakEkle(sec) {
            satirlar().filter(function (x) { return !satirDolu(x); }).forEach(function (x) { x.remove(); });
            var aday = {}; KA.adaylar.forEach(function (a) { aday[a.id] = a; });
            var sb = document.getElementById('ftSatirSablon'), tb = $('.ft-fsatirlar', f), nos = {};
            sec.forEach(function (x) {
                var s = x.s, tr = sb.content.firstElementChild.cloneNode(true);
                tb.appendChild(tr);
                var k = s.kart_id ? kartId[String(s.kart_id)] : null;
                if (k) kartSec(tr, k, true); else { el(tr, 'kart_ara').value = s.urun_adi; el(tr, 'urun_adi').value = s.urun_adi; birimKur(tr, null, s.birim); }
                if (s.kart_id && !k) { el(tr, 'kart_id').value = String(s.kart_id); el(tr, 'kart_ara').value = (s.kart_kod ? s.kart_kod + ' — ' : '') + s.urun_adi; }
                el(tr, 'miktar').value = x.miktar; el(tr, 'birim_fiyat').value = s.birim_fiyat || ''; el(tr, 'iskonto').value = s.iskonto || '';
                kdvKur(tr, s.kdv_orani !== '' ? s.kdv_orani : kartVarsayilanKdv(k));
                el(tr, 'gtip').value = s.gtip || ''; el(tr, 'lot').value = s.lot || ''; el(tr, 'satir_aciklama').value = s.satir_aciklama || '';
                el(tr, 'kaynak_satir_id').value = String(s.kaynak_satir_id);
                tr.setAttribute('data-kaynak-satir', String(s.kaynak_satir_id)); tr.setAttribute('data-kalan-milli', String(s.kalan_milli)); tr.setAttribute('data-kaynak-no', s.kaynak_belge_no);
                tr.classList.add('ft-fsatir--kaynakli');
                var rz = yap('span', 'ft-rozet ft-rozet--kaynak', s.kaynak_belge_no); $('.ft-fs-gir', tr).insertBefore(rz, $('.ft-sx', tr));
                nos[s.kaynak_belge_no] = true;
                var a = aday[s.kaynak_belge_id];
                if (a && bilgi().cari && f.elements.para_birimi && f.elements.para_birimi.value !== a.para_birimi) { f.elements.para_birimi.value = a.para_birimi; pbGuncelle(true); }
            });
            if (!satirlar().length) satirEkle(false);
            var bil = $('[data-kaynak-bilgi]', f);
            if (bil) { bil.textContent = Object.keys(nos).join(', ') + ' belgesinden ' + sec.length + ' satır getirildi'; bil.hidden = false; }
            numarala(); goster(); degis(); kaynakIpucu(); hesapla();
            if (f.elements.kur && !f.elements.kur.disabled && !f.elements.kur.value) setTimeout(function () { f.elements.kur.focus(); }, 40);
        }

        /* — Mobil satır alt sayfası — */
        var SM = document.getElementById('ftSatirSheet');
        SH = { tr: null, yeni: false, kart: null };
        function sh(ad) { return $('[data-sh="' + ad + '"]', SM); }
        function sheetAc(tr, odak, yeni) {
            if (!SM || !tr) return;
            if (!mobil()) { var x = odak === 'miktar' ? el(tr, 'miktar') : el(tr, 'kart_ara'); if (x) x.focus(); return; }
            SH.tr = tr; SH.yeni = !!yeni; SH.kart = satirKart(tr);
            $('#ftSheetBaslik').textContent = 'Satır ' + (satirlar().indexOf(tr) + 1);
            sh('ara').value = SH.kart ? SH.kart.kod + ' — ' + SH.kart.ad : satirMetin(tr);
            sh('ara').readOnly = kaynakli(tr) && !!SH.kart;
            sh('miktar').value = el(tr, 'miktar').value; sh('fiyat').value = el(tr, 'birim_fiyat').value; sh('iskonto').value = el(tr, 'iskonto').value;
            sh('lot').value = el(tr, 'lot').value; sh('aciklama').value = el(tr, 'satir_aciklama').value;
            sh('gtip').value = el(tr, 'gtip').value; sh('brut_kg').value = el(tr, 'satir_brut_kg').value; sh('net_kg').value = el(tr, 'satir_net_kg').value; sh('kap').value = el(tr, 'satir_kap').value;
            $('[data-sh-ihracat]', SM).hidden = !bilgi().ihracat;
            $('[data-sh-pb]', SM).textContent = '(' + pb() + ')';
            shSecenek(el(tr, 'birim'), sh('birim')); shSecenek(el(tr, 'kdv_orani'), sh('kdv'));
            sh('kdv').disabled = !!bilgi().kdv_sifir;
            bosalt($('[data-sh-sonuc]', SM)); shSeciliYaz(); shSonCipler(); shHesap();
            modalAc('ftSatirSheet', $('.ft-fs-ac', tr));
            setTimeout(function () { var o = sh(odak === 'lot' ? 'lot' : (odak === 'miktar' && SH.kart ? 'miktar' : 'ara')); if (odak === 'lot') { var d = $('.ft-sx', SM); if (d) d.open = true; } if (o) o.focus(); }, 30);
        }
        function shSecenek(kaynak, hedef) { bosalt(hedef); $$('option', kaynak).forEach(function (o) { secenek(hedef, o.value, o.textContent, o.selected); }); }
        function shSeciliYaz() {
            var p = $('[data-sh-secili]', SM), k = SH.kart;
            if (k) { var bk = ((V.bakiye || {})[k.id] || {})[depo()] || 0; p.textContent = 'Seçili: ' + k.ad + ' · Mevcut ' + milliBicim(bk, k.birim) + ' ' + k.birim; }
            else p.textContent = sh('ara').value.trim() ? (bilgi().stok_zorunlu ? 'Listeden bir stok kartı seçin' : 'Kartsız (serbest) satır — stoğa girmez') : '';
            var sf = sonFiyat(k), t = $('[data-sh-son-fiyat]', SM); bosalt(t);
            if (sf) {
                t.appendChild(document.createTextNode('son ' + f4Bicim(sf.f4) + ' · ' + trTarih(sf.tarih) + ' '));
                var b = yap('button', 'btn btn-sm', 'Uygula'); b.type = 'button'; b.setAttribute('data-sh-son-uygula', String(sf.f4)); if (sf.pb !== pb()) b.disabled = true; t.appendChild(b);
            }
        }
        function shSonCipler() {
            var k = $('[data-sh-son]', SM); bosalt(k);
            var sf = FT.ozet && FT.ozet.son_fiyatlar ? Object.keys(FT.ozet.son_fiyatlar).slice(0, 6) : [];
            sf.forEach(function (id) { var kr = kartId[id]; if (!kr) return; var b = yap('button', 'ft-son-cip', kr.ad + ' ' + f4Bicim(FT.ozet.son_fiyatlar[id].f4)); b.type = 'button'; b.setAttribute('data-kart-id', id); k.appendChild(b); });
            k.hidden = !k.firstChild || sh('ara').readOnly;
            if (!k.hidden) k.insertBefore(yap('span', 'ft-ipucu', 'Bu cariye son:'), k.firstChild);
        }
        function shAra() {
            var q = katla(sh('ara').value), kap = $('[data-sh-sonuc]', SM); bosalt(kap);
            if (!q || sh('ara').readOnly) return;
            var n = 0;
            kartlar.forEach(function (k) {
                if (n >= 30) return;
                if ((katla(k.kod + ' ' + k.ad + ' ' + (k.barkod || ''))).indexOf(q) < 0) return;
                n++;
                var b = yap('button'); b.type = 'button'; b.setAttribute('data-kart-id', String(k.id)); if (SH.kart && SH.kart.id === k.id) b.setAttribute('aria-pressed', 'true');
                var bk = ((V.bakiye || {})[k.id] || {})[depo()] || 0;
                b.appendChild(yap('span', '', k.ad)); b.appendChild(yap('small', '', k.kod + ' · ' + milliBicim(bk, k.birim) + ' ' + k.birim)); kap.appendChild(b);
            });
            if (!bilgi().stok_zorunlu) { var s = yap('button'); s.type = 'button'; s.setAttribute('data-sh-serbest', ''); s.appendChild(yap('span', '', '+ "' + sh('ara').value.trim() + '" serbest satır olarak')); kap.appendChild(s); }
        }
        function shKartSec(k) {
            SH.kart = k; sh('ara').value = k ? k.kod + ' — ' + k.ad : sh('ara').value;
            var tmp = document.createElement('select');
            if (k) { secenek(tmp, '', k.birim, true); if (k.cevrim_birim && k.cevrim_katsayi) secenek(tmp, k.cevrim_birim, k.cevrim_birim, false); }
            else { secenek(tmp, '', '—', true); (V.birimler || []).forEach(function (b) { secenek(tmp, b, b, false); }); }
            shSecenek(tmp, sh('birim'));
            if (k && !bilgi().kdv_sifir) sh('kdv').value = String(kartVarsayilanKdv(k));
            sh('miktar').setAttribute('inputmode', k && !tam(k.birim) ? 'decimal' : 'numeric');
            bosalt($('[data-sh-sonuc]', SM)); shSeciliYaz(); shHesap();
            sh('miktar').focus();
        }
        function shHesap() {
            var mil = ondalikInt(sh('miktar').value, 3), f4 = sh('fiyat').value.trim() ? ondalikInt(sh('fiyat').value, 4) : null, bp = sh('iskonto').value.trim() ? ondalikInt(sh('iskonto').value, 2) : 0;
            var k = SH.kart, b = sh('birim').value;
            if (k && b && b === k.cevrim_birim) mil = isNaN(mil) ? NaN : carpBol(mil, Math.round(Number(k.cevrim_katsayi) * 1000), 1000);
            var kd = bilgi().kdv_sifir ? 0 : Number(sh('kdv').value || 0), c = $('[data-sh-hesap]', SM);
            if (isNaN(mil) || f4 === null || isNaN(f4) || isNaN(bp)) { c.textContent = 'Tutar —'; return; }
            var brut = carpBol(mil, f4, 100000), isk = carpBol(brut, bp, 10000), net = brut - isk;
            c.textContent = 'Tutar ' + para(net, pb()) + ' · KDV ' + para(carpBol(net, kd, 100), pb());
        }
        function shUygula() {
            var tr = SH.tr; if (!tr) return false;
            var k = SH.kart, metin = sh('ara').value.trim();
            if (!k && !metin) { sh('ara').focus(); toast('Ürün seçin ya da yazın.', true); return false; }
            if (!k && bilgi().stok_zorunlu) { sh('ara').focus(); toast('Bu belgede her satırda stok kartı zorunlu.', true); return false; }
            if (!kaynakli(tr)) { kartSec(tr, k, true); if (!k) { el(tr, 'kart_ara').value = metin; el(tr, 'urun_adi').value = metin; } }
            birimKur(tr, satirKart(tr), sh('birim').value);
            el(tr, 'miktar').value = sh('miktar').value; el(tr, 'birim_fiyat').value = sh('fiyat').value; el(tr, 'iskonto').value = sh('iskonto').value;
            kdvKur(tr, bilgi().kdv_sifir ? '0' : sh('kdv').value);
            el(tr, 'lot').value = sh('lot').value; el(tr, 'satir_aciklama').value = sh('aciklama').value;
            el(tr, 'gtip').value = sh('gtip').value; el(tr, 'satir_brut_kg').value = sh('brut_kg').value; el(tr, 'satir_net_kg').value = sh('net_kg').value; el(tr, 'satir_kap').value = sh('kap').value;
            tr.classList.remove('ft-fsatir--hata'); $$('.ft-hata', tr).forEach(function (x) { x.remove(); });
            degis(); SH.yeni = false; hesapla();
            return true;
        }
        SH.vazgec = function () {
            var tr = SH.tr;
            if (SH.yeni && tr && !satirDolu(tr) && satirlar().length > 1) { tr.remove(); numarala(); hesapla(); }
            SH.tr = null; modalKapat(true);
        };
        if (SM) {
            SM.addEventListener('input', function (e) { var t = e.target; if (t === sh('ara')) { if (SH.kart && t.value !== SH.kart.kod + ' — ' + SH.kart.ad) { SH.kart = null; shSeciliYaz(); } shAra(); } shHesap(); });
            SM.addEventListener('change', function () { shHesap(); });
            SM.addEventListener('click', function (e) {
                var b = e.target.closest ? e.target.closest('button') : null; if (!b) return;
                if (b.hasAttribute('data-kart-id')) { e.preventDefault(); shKartSec(kartId[b.getAttribute('data-kart-id')] || null); return; }
                if (b.hasAttribute('data-sh-serbest')) { e.preventDefault(); SH.kart = null; bosalt($('[data-sh-sonuc]', SM)); shKartSec(null); return; }
                if (b.hasAttribute('data-sh-son-uygula')) { e.preventDefault(); sh('fiyat').value = f4Bicim(Number(b.getAttribute('data-sh-son-uygula'))); shHesap(); return; }
                if (b.hasAttribute('data-sh-adim')) {
                    e.preventDefault();
                    var m = ondalikInt(sh('miktar').value || '0', 3); if (isNaN(m)) m = 0;
                    m = Math.max(0, m + Number(b.getAttribute('data-sh-adim')) * 1000);
                    sh('miktar').value = m > 0 ? milliBicim(m, SH.kart ? SH.kart.birim : 'lt') : ''; shHesap(); return;
                }
                if (b.hasAttribute('data-sh-vazgec')) { e.preventDefault(); SH.vazgec(); return; }
                if (b.hasAttribute('data-sh-sil')) { e.preventDefault(); var tr = SH.tr; SH.tr = null; modalKapat(true); if (tr) satirSil(tr); return; }
                if (b.hasAttribute('data-sh-uygula')) { e.preventDefault(); if (shUygula()) { SH.tr = null; modalKapat(); } return; }
                if (b.hasAttribute('data-sh-uygula-yeni')) { e.preventDefault(); if (shUygula()) { SH.tr = null; modalKapat(true); var y = satirEkle(false); if (y) setTimeout(function () { sheetAc(y, 'ara', true); }, 20); } }
            });
            SM.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter' || e.target.tagName === 'BUTTON') return;
                e.preventDefault();
                var od = $$('input:not([readonly]), select', SM).filter(function (x) { return !x.disabled && x.getClientRects().length; }), i = od.indexOf(e.target);
                if (e.target === sh('ara')) { var ilk = $('[data-sh-sonuc] button', SM); if (ilk) { ilk.click(); return; } }
                if (i >= 0 && i < od.length - 1) od[i + 1].focus(); else if (shUygula()) { SH.tr = null; modalKapat(); }
            });
        }

        /* — açılış — */
        satirlar().forEach(function (tr) {
            var k = satirKart(tr);
            if (k) { var sec2 = el(tr, 'birim').value; birimKur(tr, k, sec2); }
            else if (satirMetin(tr) && !el(tr, 'kaynak_satir_id').value) { var b = kartBul(satirMetin(tr)); if (b) kartSec(tr, b, true); }
            if (kaynakli(tr)) { var rz = $('.ft-rozet--kaynak', tr); if (rz) tr.setAttribute('data-kaynak-no', rz.textContent); }
        });
        turUygula(FT.tur, true);
        numarala();
        if (f.elements.cari_ara && f.elements.cari_ara.value.trim()) { var c0 = cariBulV(f.elements.cari_ara.value); if (c0) FT.uyg = c0.id; cariGirdi(false); }
        if (window.matchMedia('(min-width: 1024px) and (max-height: 760px)').matches && !$('#ftBas', f).classList.contains('ft-bas--ozet') && FT.cari) basDaralt(false);
        hepsi();
        setTimeout(function () {
            if (!masaustu()) return;
            var o = !FT.cari ? f.elements.cari_ara : ($$('.ft-kart-ara', f).filter(function (x) { return !satirDolu(x.closest('.ft-fsatir')); })[0] || null);
            if (o) o.focus();
        }, 30);
        degisti = false;
        return { satirSil: satirSil, satirKopya: satirKopya, sheetAc: sheetAc, hepsi: hepsi, toplam: function () { return FT.toplam; } };
    }
})();
