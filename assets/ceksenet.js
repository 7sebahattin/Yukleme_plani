/* =========================================================
   assets/ceksenet.js — Çek-Senet modülü istemcisi (cek_dekontu / senet_dekontu / cek_tanimlari)
   Yalnız #ceksenet kökü olan sayfada çalışır. Yazmalar YALNIZ cek_kaydet.php'ye (fetch + X-Requested-With: fetch;
   JS kapalıyken data-cs-ajax formları klasik POST + 303 ile çalışır). app.js / kasa.js / stok.js'e DOKUNMAZ.
   Kancalar: form[data-cs-ajax] · [data-cs-kapat] · a[data-cs-detay] / tablo satırı (?parca=detay → #csPanel) · [data-cs-islem] (panel düğmesi) ·
             [data-cs-sec] (evrak seçimi → #csSecimBar: adet, toplam, ORTALAMA VADE, toplu işlem menüsü) · #csModalIslem / #csIslemForm (alanlar #csVeri.islemler = ceksenet_islemler() yapısından türetilir;
             409 yetersiz_bakiye → #csEksik + "Yine de kaydet"; 409 gecersiz_gecis → liste) · [data-cs-iptal-ac] (#csModalIptal; id = HAREKET id) ·
             #csBordroForm (paralel dizili satır tablosu, #csSatirSablon, #csFormVeri) · [data-cs-tanim-ac] (#csModalTanim) · .cs-tanim-nav-oge.
   Klavye: "/" aramaya odak · Ctrl+Enter / Ctrl+S kaydet · Esc kapat · Enter sonraki hücre (son hücrede yeni satır) · Ctrl+↓ yeni satır · Ctrl+Del satır sil.
   Tutar ve ortalama vade çözümü yalnız ÖNİZLEME içindir (tam sayı kuruş; sunucudaki ceksenet_ortalama_vade ile AYNI algoritma); esas doğrulama sunucudadır.
   ========================================================= */
(function () {
    'use strict';
    var KOK = document.getElementById('ceksenet');
    if (!KOK) return;

    var S = {
        kaydet: 'cek_kaydet.php',
        sayfa: (location.pathname.replace(/^.*\//, '') || 'cek_dekontu.php'),
        toastAnahtar: 'cek_toast',
        yonRe: /^(cek_dekontu|senet_dekontu|cek_tanimlari)\.php(\?[^\r\n]*)?$/,
        baslik: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
        m: { ag: 'Bağlantı hatası; tekrar deneyin.', kaydedildi: 'Kaydedildi', zorunlu: 'Zorunlu alan.' }
    };
    var VERI = (function () { try { var s = document.getElementById('csVeri'); return s ? JSON.parse(s.textContent) : null; } catch (x) { return null; } })();
    var FVERI = (function () { try { var s = document.getElementById('csFormVeri'); return s ? JSON.parse(s.textContent) : null; } catch (x) { return null; } })();

    function $(s, k) { return (k || document).querySelector(s); }
    function $$(s, k) { return Array.prototype.slice.call((k || document).querySelectorAll(s)); }
    function oku(k) { try { return window.sessionStorage.getItem(k); } catch (x) { return null; } }
    function yaz(k, v) { try { if (v == null) window.sessionStorage.removeItem(k); else window.sessionStorage.setItem(k, v); } catch (x) { /* yok: sorun değil */ } }
    function guvenliYol(u) { return typeof u === 'string' && S.yonRe.test(u); }
    function trKucuk(s) { return String(s || '').replace(/İ/g, 'i').replace(/I/g, 'ı').toLocaleLowerCase('tr-TR'); }

    /* ═════════ Para / tarih / ortalama vade (yalnız önizleme) ═════════ */
    /* Sunucudaki kasa_tutar_coz (cari_tutar_coz → hesap_parse_amount) ile BİREBİR aynı: {k: kuruş|null, belirsiz, mesaj} */
    function tutarCoz(ham) {
        var s = String(ham == null ? '' : ham).trim();
        ['₺', '$', '€', 'TRY', 'TL', 'USD', 'EUR', 'AED'].forEach(function (t) { s = s.replace(new RegExp(t.replace('$', '\\$'), 'gi'), ''); });
        s = s.replace(/[\s  ]+/g, '');
        var bos = { k: null, belirsiz: false, mesaj: '' };
        var bm = /^([1-9]\d{0,2}),(\d{3})$/.exec(s);
        if (bm) { var a = bm[1] + '.' + bm[2]; return { k: null, belirsiz: true, mesaj: 'Tutar belirsiz: ' + a + ' için nokta kullanın (' + a + '), ' + (a === '1.500' ? 'bir buçuk için 1,50 yazın.' : 'ondalık için ' + bm[1] + ',50 yazın.') }; }
        if (s === '') return bos;
        var neg = false;
        if (s.charAt(0) === '-') { neg = true; s = s.slice(1); }
        if (s === '' || !/^[0-9.,]+$/.test(s) || !/[0-9]/.test(s)) return bos;
        function gruplarGecerli(g) {
            if (!/^[0-9]{1,3}$/.test(g[0])) return false;
            for (var i = 1; i < g.length; i++) if (!/^[0-9]{3}$/.test(g[i])) return false;
            return true;
        }
        var nd = s.split('.').length - 1, nc = s.split(',').length - 1, val;
        if (nd === 0 && nc === 0) val = Number(s);
        else if (nd > 0 && nc > 0) {
            var dp = Math.max(s.lastIndexOf('.'), s.lastIndexOf(',')), ds = s.charAt(dp), bs = ds === '.' ? ',' : '.';
            var ip = s.slice(0, dp), tail = s.slice(dp + 1);
            if (ip.indexOf(ds) >= 0 || !/^[0-9]+$/.test(tail === '' ? '0' : tail)) return bos;
            if (!gruplarGecerli(ip.split(bs))) return bos;
            val = Number(ip.split(bs).join('') + '.' + (tail === '' ? '0' : tail));
        } else {
            var sep = nd > 0 ? '.' : ',', p = s.split(sep);
            if (p.length > 2) { if (!gruplarGecerli(p)) return bos; val = Number(p.join('')); }
            else if (p[1].length === 3 && /^[1-9][0-9]{0,2}$/.test(p[0])) val = Number(p[0] + p[1]);
            else val = Number((p[0] === '' ? '0' : p[0]) + '.' + (p[1] === '' ? '0' : p[1]));
        }
        if (neg) val = -val;
        if (!isFinite(val) || val <= 0) return bos;
        var k = Math.round(val * 100);
        if (k <= 0 || k > 99999999999999 || Math.abs(val * 100 - k) > 1e-6) return bos;
        return { k: k, belirsiz: false, mesaj: '' };
    }
    function kurusCoz(ham) { return tutarCoz(ham).k; }
    function paraYaz(kurus, pb) {
        var m = Math.abs(kurus), t = Math.floor(m / 100), k = String(m % 100);
        var sym = { TRY: '₺', USD: '$', EUR: '€' }[pb] || pb || '';
        return (kurus < 0 ? '-' : '') + String(t).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + (k.length < 2 ? '0' + k : k) + (sym ? ' ' + sym : '');
    }
    function gunSayisi(iso) {   // 'YYYY-MM-DD' → UTC gün numarası (geçersizse null)
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
        if (!m) return null;
        var y = +m[1], a = +m[2], g = +m[3], d = new Date(Date.UTC(y, a - 1, g));
        if (d.getUTCFullYear() !== y || d.getUTCMonth() !== a - 1 || d.getUTCDate() !== g) return null;
        return Math.round(d.getTime() / 86400000);
    }
    function trTarih(iso) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || ''); return m ? m[3] + '.' + m[2] + '.' + m[1] : (iso || ''); }
    /** ceksenet_ortalama_vade ile AYNI: gün = round-half-away(Σ(kuruş×gün)/Σkuruş), gün = vade − baz; pb başına AYRI. satirlar: [{vade, kurus, pb}] */
    function ortVade(satirlar, baz) {
        var b = gunSayisi(baz), acc = {};
        if (b === null) return {};
        satirlar.forEach(function (s) {
            var v = gunSayisi(s.vade);
            if (v === null || !(s.kurus > 0)) return;
            var a = acc[s.pb] = acc[s.pb] || { s: 0, t: 0, n: 0 };
            a.s += s.kurus * (v - b); a.t += s.kurus; a.n++;
        });
        var out = {};
        Object.keys(acc).forEach(function (pb) {
            var a = acc[pb], g = a.s >= 0 ? Math.floor((2 * a.s + a.t) / (2 * a.t)) : -Math.floor((-2 * a.s + a.t) / (2 * a.t));
            out[pb] = { gun: g, tarih: new Date((b + g) * 86400000).toISOString().slice(0, 10), toplam: a.t, adet: a.n };
        });
        return out;
    }
    function pbSirala(o) { var s = { TRY: 1, USD: 2, EUR: 3 }; return Object.keys(o).sort(function (a, b) { return (s[a] || 99) - (s[b] || 99) || (a < b ? -1 : 1); }); }
    function ortMetin(ort) {
        var k = pbSirala(ort);
        if (!k.length) return '—';
        return k.map(function (pb) { return ort[pb].gun + ' gün · ' + trTarih(ort[pb].tarih) + (k.length > 1 ? ' (' + pb + ')' : ''); }).join(' | ');
    }
    function toplamMetin(tp) {
        var k = pbSirala(tp);
        return k.length ? k.map(function (pb) { return paraYaz(tp[pb], pb); }).join(' · ') : '—';
    }

    /* ═════════ Toast ═════════ */
    var toastZ = null;
    var BORDRO_RE = /^BRD-\d{4}-\d{6}$/;
    function toast(msg, hata, bordro) {
        var t = $('.cs-toast');
        if (!t) { t = document.createElement('div'); t.className = 'cs-toast'; t.setAttribute('role', 'status'); t.setAttribute('aria-live', 'polite'); document.body.appendChild(t); }
        t.textContent = msg;
        if (bordro && BORDRO_RE.test(bordro)) {   // işlem bordrosuna yeni sekmede bağlantı (cek_cikti.php?bordro=)
            var a = document.createElement('a');
            a.href = 'cek_cikti.php?bordro=' + encodeURIComponent(bordro); a.target = '_blank'; a.rel = 'noopener'; a.className = 'cs-toast-bag'; a.textContent = bordro;
            t.appendChild(document.createTextNode(' · ')); t.appendChild(a);
        }
        t.classList.toggle('cs-toast--hata', !!hata);
        /* Mobilde görünür alt eylem çubuğunun ÜSTÜNE otur (çubuğu / Kaydet düğmesini örtmesin) */
        var ek = 0;
        if (window.matchMedia && window.matchMedia('(max-width: 767px)').matches) {
            $$('.cs-eylem-bar, .cs-secim-bar').forEach(function (b) { if (!b.hidden && b.getClientRects().length) ek = Math.max(ek, b.offsetHeight + 8); });
        }
        t.style.setProperty('--cs-toast-ek', ek + 'px');
        t.hidden = false;
        clearTimeout(toastZ);
        toastZ = setTimeout(function () { t.hidden = true; }, hata ? 4500 : (bordro ? 9000 : 3600));
    }
    function yenileToastla(msg, url, bordro) {
        if (msg) yaz(S.toastAnahtar, JSON.stringify({ m: msg, b: bordro || '' }));
        var simdiki = location.pathname.replace(/^.*\//, '') + location.search;
        if (url && guvenliYol(url) && url !== simdiki) location.assign(url); else location.reload();
    }
    (function () {
        var m = oku(S.toastAnahtar), b = '';
        if (!m) return;
        yaz(S.toastAnahtar, null);
        try { var o = JSON.parse(m); if (o && typeof o === 'object') { m = String(o.m || ''); b = String(o.b || ''); } } catch (x) { /* eski düz metin */ }
        if (m) toast(m, false, b);
    })();

    /* ═════════ Form hataları ═════════ */
    function hataTemizle(f) {
        $$('.cs-hata', f).forEach(function (e) { e.remove(); });
        $$('[aria-invalid="true"]', f).forEach(function (e) { e.removeAttribute('aria-invalid'); });
        $$('.cs-fsatir--hata', f).forEach(function (e) { e.classList.remove('cs-fsatir--hata'); });
        var g = $('.cs-form-hata', f); if (g) { g.hidden = true; g.textContent = ''; }
    }
    function alanHata(f, ad, msg, kok) {
        if (ad === 'cari_id') ad = 'cari_ara';   // gizli alan → görünen arama kutusu
        var el = $$('[name="' + ad + '"]', kok || f).filter(function (x) { return x.type !== 'hidden' && !x.disabled; })[0];
        if (!el) return null;
        el.setAttribute('aria-invalid', 'true');
        var s = document.createElement('span'); s.className = 'cs-hata'; s.textContent = msg;
        var id = 'csH' + Math.random().toString(36).slice(2, 8); s.id = id; el.setAttribute('aria-describedby', id);
        (el.closest('.pm-label') || el.closest('td') || el.parentNode).appendChild(s);
        return el;
    }
    function genelHata(f, msg) {
        var g = $('.cs-form-hata', f);
        if (g) { g.textContent = msg; g.hidden = false; try { g.scrollIntoView({ block: 'nearest' }); } catch (x) { /* eski tarayıcı */ } } else toast(msg, true);
    }
    /** Sunucu kısmi yanıtını (kendi ?parca=… çıktımız) DOM'a çevirir — modüldeki TEK HTML ayrıştırma noktası. */
    function htmlParca(h) { var t = document.createElement('div'); t.innerHTML = h; return t; }
    function yanitCoz(r) {
        return r.text().then(function (t) { var j = null; try { j = JSON.parse(t); } catch (x) { j = null; } return { r: r, j: j }; });
    }
    function rastgele() {
        var a = new Uint8Array(12), s = '';
        try { window.crypto.getRandomValues(a); } catch (x) { for (var i = 0; i < 12; i++) a[i] = Math.floor(Math.random() * 256); }
        a.forEach(function (b) { s += (b % 36).toString(36); });
        return s + Date.now().toString(36);
    }

    /* ═════════ AJAX form gönderimi (basit formlar: iptal, tanım) ═════════ */
    function gonder(f, sw) {
        if (f.dataset.kilit === '1') return;
        if (f.getAttribute('data-onay') && !window.confirm(f.getAttribute('data-onay'))) return;
        hataTemizle(f);
        var eksik = $$('[required]', f).filter(function (e) { return !e.disabled && !e.closest('[hidden]') && !String(e.value || '').trim(); });
        if (eksik.length) { eksik.forEach(function (e) { alanHata(f, e.name, S.m.zorunlu); }); eksik[0].focus(); return; }
        var btn = $$('button[type=submit], button:not([type])', f);
        f.dataset.kilit = '1'; btn.forEach(function (b) { b.disabled = true; });
        var fd = new FormData(f);   // anahtar kutusu devre dışı bırakılmadan ÖNCE okunur (disabled alan gönderilmez)
        if (sw) sw.disabled = true;
        // Hata dönerse anahtar eski hâline: kutu devre dışı kalmaz, işaret geri alınır
        function anahtarGeri() { if (sw) { sw.checked = !sw.checked; sw.disabled = false; } }
        fetch(f.getAttribute('action') || S.kaydet, { method: 'POST', body: fd, headers: S.baslik, credentials: 'same-origin' })
            .then(yanitCoz)
            .then(function (s) {
                f.dataset.kilit = ''; btn.forEach(function (b) { b.disabled = false; });
                if (s.j && s.j.ok) {
                    var msg = f.getAttribute('data-ok') || S.m.kaydedildi;
                    if (s.j.uyari) msg += ' — ' + s.j.uyari;
                    yenileToastla(msg, s.j.yonlendir);
                    return;
                }
                anahtarGeri();
                if (s.j && s.j.alanlar) {
                    var ilk = null;
                    Object.keys(s.j.alanlar).forEach(function (ad) { var el = alanHata(f, ad, s.j.alanlar[ad]); if (el && !ilk) ilk = el; });
                    if (ilk && ilk.focus) ilk.focus();
                }
                genelHata(f, (s.j && s.j.hata) || ('İşlem yapılamadı (HTTP ' + s.r.status + ').'));
            })
            .catch(function () { f.dataset.kilit = ''; btn.forEach(function (b) { b.disabled = false; }); anahtarGeri(); genelHata(f, S.m.ag); });
    }
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f.matches) return;
        if (f.matches('form[data-cs-ajax]')) { e.preventDefault(); gonder(f); return; }
        if (f.matches('form#csIslemForm')) { e.preventDefault(); islemGonder(false); return; }
        if (f.matches('form#csBordroForm')) { e.preventDefault(); bordroGonder(); }
    });
    document.addEventListener('change', function (e) {
        var c = e.target;
        if (!c.matches) return;
        if (c.matches('input[role="switch"]') && c.form && c.form.matches('form[data-cs-ajax]')) { gonder(c.form, c); return; }
        if (c.matches('select[data-oto-gonder]') && c.form) {
            if (c.matches('[data-cs-donem]')) {
                var oz = $('.cs-ozel-tarih', c.form);
                if (oz) { oz.hidden = c.value !== 'ozel'; if (c.value === 'ozel') { var b = $('input[name="bas"]', oz); if (b) b.focus(); return; } }
            }
            c.form.submit();
        }
    });

    /* Kullanıcı düzeltmeye başlayınca o alanın hata işareti kalkar */
    function alanHataKaldir(e) {
        var t = e.target;
        if (!t || !t.getAttribute || t.getAttribute('aria-invalid') !== 'true') return;
        if (t.getAttribute('data-belirsiz') !== '1') t.removeAttribute('aria-invalid');
        var k = t.closest('.pm-label') || t.closest('td') || t.parentNode, h = k && k.querySelector('.cs-hata');
        if (h) h.remove();
    }
    document.addEventListener('input', alanHataKaldir);
    document.addEventListener('change', alanHataKaldir);

    /* ═════════ Modal ═════════ */
    var acik = null, acan = null;
    function modalAc(id, tetik) {
        var m = document.getElementById(id); if (!m) return null;
        acik = m; acan = tetik || document.activeElement;
        m.hidden = false; document.body.style.overflow = 'hidden';
        setTimeout(function () { var o = $('[data-ilk-odak]', m) || $('input:not([type=hidden]), select, textarea', m); if (o && o.focus) o.focus(); }, 30);
        return m;
    }
    function modalKapat() {
        if (!acik) return;
        acik.hidden = true; document.body.style.overflow = '';
        var a = acan; acik = null; acan = null;
        if (a && document.contains(a) && a.focus) a.focus();
    }
    var panel = document.getElementById('csPanel');
    function panelDar() { return window.matchMedia('(max-width: 1099px)').matches; }
    function panelKapat() {
        if (!panel) return;
        panel.classList.remove('cs-panel--acik');
        document.body.style.overflow = '';
        $$('tr[aria-current="true"], .cs-hk[aria-current="true"]').forEach(function (e) { e.removeAttribute('aria-current'); });
        urlId(null);
    }
    function urlId(id) {
        try {
            var u = new URL(location.href);
            if (id) u.searchParams.set('id', id); else u.searchParams.delete('id');
            history.replaceState(history.state, '', u.pathname.replace(/^.*\//, '') + u.search);
        } catch (x) { /* kritik değil */ }
    }
    if (panel && panel.classList.contains('cs-panel--acik') && panelDar()) document.body.style.overflow = 'hidden';

    document.addEventListener('click', function (e) {
        var hedef = e.target.closest ? e.target : null; if (!hedef) return;
        var t = hedef.closest('[data-cs-kapat], [data-cs-islem], [data-cs-iptal-ac], [data-cs-panel-kapat], [data-cs-toplu-ac], [data-cs-sec-temizle], [data-cs-tanim-ac], a[data-cs-detay], [data-eksik-duzelt], [data-eksik-kaydet], [data-cs-toplu-islem]');
        if (t) {
            if (t.hasAttribute('data-cs-kapat')) { e.preventDefault(); modalKapat(); return; }
            if (t.hasAttribute('data-cs-panel-kapat')) { e.preventDefault(); panelKapat(); return; }
            if (t.hasAttribute('data-cs-islem')) { e.preventDefault(); var d = $('#csDetay'); if (d) islemAc(t.getAttribute('data-cs-islem'), [d.getAttribute('data-id')], t); return; }
            if (t.hasAttribute('data-cs-toplu-islem')) { e.preventDefault(); topluMenu(false); islemAc(t.getAttribute('data-cs-toplu-islem'), seciliIdler(), t); return; }
            if (t.hasAttribute('data-cs-toplu-ac')) { e.preventDefault(); topluMenu(); return; }
            if (t.hasAttribute('data-cs-sec-temizle')) { e.preventDefault(); secimTemizle(); return; }
            if (t.hasAttribute('data-cs-iptal-ac')) { e.preventDefault(); iptalAc(t); return; }
            if (t.hasAttribute('data-cs-tanim-ac')) { e.preventDefault(); tanimAc(t); return; }
            if (t.hasAttribute('data-eksik-duzelt')) { e.preventDefault(); eksikGizle(); return; }
            if (t.hasAttribute('data-eksik-kaydet')) { e.preventDefault(); var ez = $('#csIslemForm [name="eksiye_izin"]'); if (ez) ez.value = '1'; islemGonder(true); return; }
            if (t.matches('a[data-cs-detay]')) {
                if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
                e.preventDefault(); detayAc(t.getAttribute('data-id'), t); return;
            }
        }
        var tr = hedef.closest('.cs-tablo tbody tr[data-id]');
        if (tr && !hedef.closest('a, button, input, label')) { detayAc(tr.getAttribute('data-id'), tr); return; }
        if (hedef.classList && hedef.classList.contains('pm-overlay') && hedef === acik) modalKapat();
        var tm = $('#csTopluMenu');
        if (tm && !tm.hidden && !hedef.closest('#csTopluMenu, [data-cs-toplu-ac]')) topluMenu(false);
    });
    document.addEventListener('keydown', function (e) {
        if (acik) {
            if (e.key === 'Escape') { e.preventDefault(); modalKapat(); return; }
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                var f = $('form', acik);
                if (f) { e.preventDefault(); if (f.matches('form#csIslemForm')) islemGonder(false); else if (f.matches('form[data-cs-ajax]')) gonder(f); }
                return;
            }
            if (e.key === 'Tab') {   // odak tuzağı
                var od = $$('button, input:not([type=hidden]), select, textarea, a[href]', acik).filter(function (x) { return !x.disabled && x.getClientRects().length; });
                if (!od.length) return;
                var ilk = od[0], son = od[od.length - 1];
                if (e.shiftKey && document.activeElement === ilk) { e.preventDefault(); son.focus(); }
                else if (!e.shiftKey && document.activeElement === son) { e.preventDefault(); ilk.focus(); }
            }
            return;
        }
        var ar = document.getElementById('csArama');
        var yazim = /^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName || '');
        if (ar && e.key === '/' && !yazim) { e.preventDefault(); ar.focus(); ar.select(); return; }
        if (ar && document.activeElement === ar && e.key === 'Escape' && ar.value) { ar.value = ''; if (ar.form) ar.form.submit(); return; }
        if (e.key === 'Escape' && panel && panel.classList.contains('cs-panel--acik') && panelDar()) panelKapat();
    });

    /* ═════════ Cari seçici (ortak) ═════════ */
    function cariCoz(f, liste) {
        var v = f.elements.cari_ara.value.trim(), ip = $('[data-cari-ipucu]', f), bul = null;
        (liste || []).forEach(function (c) { if (c.kod + ' — ' + c.unvan === v) bul = c; });
        if (!bul && v) {
            var q = trKucuk(v), es = (liste || []).filter(function (c) { return trKucuk(c.kod) === q || trKucuk(c.unvan) === q; });
            if (es.length === 1) bul = es[0];
        }
        f.elements.cari_id.value = bul ? String(bul.id) : '';
        if (ip) ip.textContent = bul ? 'Seçildi: ' + bul.unvan : (v ? 'Listeden bir cari seçin' : '');
        return bul;
    }

    /* ═════════ LİSTE: detay paneli ═════════ */
    function detayAc(id, tetik) {
        if (!panel || !id) return;
        var kap = document.getElementById('csPanelGovde');
        fetch(S.sayfa.replace(/\?.*$/, '') + '?id=' + encodeURIComponent(id) + '&parca=detay', { headers: { 'X-Requested-With': 'fetch', 'Accept': 'text/html' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(function (h) {
                var d = $('#csDetay', htmlParca(h));
                if (!d) throw new Error('detay yok');
                while (kap.firstChild) kap.removeChild(kap.firstChild);
                kap.appendChild(d);
                $$('tr[aria-current="true"], .cs-hk[aria-current="true"]').forEach(function (x) { x.removeAttribute('aria-current'); });
                $$('[data-id="' + id + '"]').forEach(function (x) { if (x.matches('tr, .cs-hk')) x.setAttribute('aria-current', 'true'); });
                panel.classList.add('cs-panel--acik');
                if (panelDar()) { document.body.style.overflow = 'hidden'; kap.scrollTop = 0; }
                urlId(id);
            })
            .catch(function () { toast('Evrak ayrıntısı yüklenemedi.', true); });
    }

    /* ═════════ LİSTE: seçim çubuğu ═════════ */
    var secimBar = document.getElementById('csSecimBar');
    function seciliIdler() {
        var s = {}, o = [];
        $$('[data-cs-sec]:checked', KOK).forEach(function (c) { if (c.value && !s[c.value]) { s[c.value] = 1; o.push(c.value); } });
        return o;
    }
    function satirBilgi(id) {
        var tr = $('.cs-tablo tbody tr[data-id="' + id + '"]', KOK);
        if (!tr) return null;
        return { id: id, vade: tr.getAttribute('data-vade'), kurus: +tr.getAttribute('data-kurus'), pb: tr.getAttribute('data-pb'), izinli: (tr.getAttribute('data-izinli') || '').split(' ').filter(Boolean),
            portfoy: tr.getAttribute('data-portfoy'), karne: +tr.getAttribute('data-karne') || 0 };
    }
    function secimGuncelle() {
        if (!secimBar) return;
        var ids = seciliIdler(), bilgi = ids.map(satirBilgi).filter(Boolean);
        $$('[data-cs-sec]', KOK).forEach(function (c) { if (c.value) { var k = c.closest('tr, .cs-hk'); if (k) k.classList.toggle('cs-secili', ids.indexOf(c.value) >= 0); } });
        KOK.classList.toggle('cs--secili', ids.length > 0);
        secimBar.hidden = ids.length === 0;
        if (!ids.length) { topluMenu(false); return; }
        var tp = {};
        bilgi.forEach(function (b) { tp[b.pb] = (tp[b.pb] || 0) + b.kurus; });
        $('[data-secim="adet"]', secimBar).textContent = String(ids.length);
        $('[data-secim="toplam"]', secimBar).textContent = toplamMetin(tp);
        var ort = ortVade(bilgi.map(function (b) { return { vade: b.vade, kurus: b.kurus, pb: b.pb }; }), KOK.getAttribute('data-bugun'));
        $('[data-secim="ort"]', secimBar).textContent = 'Ort. vade: ' + ortMetin(ort);
        // toplu işlem menüsü: hepsinde izinli + toplu
        var ortak = null;
        bilgi.forEach(function (b) { ortak = ortak === null ? b.izinli.slice() : ortak.filter(function (x) { return b.izinli.indexOf(x) >= 0; }); });
        var menu = $('#csTopluMenu'), sinif = KOK.getAttribute('data-sinif');
        while (menu.firstChild) menu.removeChild(menu.firstChild);
        (ortak || []).forEach(function (ad) {
            var m = VERI && VERI.islemler[ad]; if (!m || !m.toplu) return;
            var b = document.createElement('button'); b.type = 'button'; b.className = 'btn'; b.setAttribute('data-cs-toplu-islem', ad);
            b.textContent = sinif === 'senet' ? m.etiket_senet : m.etiket; menu.appendChild(b);
        });
        if (!menu.firstChild) { var p = document.createElement('p'); p.className = 'cs-toplu-bos'; p.textContent = 'Seçilenler için ortak bir toplu işlem yok (durumları farklı).'; menu.appendChild(p); }
    }
    function secimTemizle() { $$('[data-cs-sec]', KOK).forEach(function (c) { c.checked = false; }); secimGuncelle(); }
    function topluMenu(ac) {
        var m = $('#csTopluMenu'), b = $('[data-cs-toplu-ac]'); if (!m) return;
        var yeni = ac === undefined ? m.hidden : ac;
        m.hidden = !yeni;
        if (b) b.setAttribute('aria-expanded', yeni ? 'true' : 'false');
    }
    KOK.addEventListener('change', function (e) {
        var c = e.target;
        if (!c.matches || !c.matches('[data-cs-sec], [data-cs-hepsi]')) return;
        if (c.hasAttribute('data-cs-hepsi')) {
            $$('.cs-tablo tbody [data-cs-sec]', KOK).forEach(function (x) { x.checked = c.checked; });
            $$('.cs-hk [data-cs-sec]', KOK).forEach(function (x) { var t = $('.cs-tablo tbody [data-cs-sec][value="' + x.value + '"]', KOK); x.checked = t ? t.checked : c.checked; });
        } else {
            $$('[data-cs-sec][value="' + c.value + '"]', KOK).forEach(function (x) { x.checked = c.checked; });
        }
        secimGuncelle();
    });

    /* ═════════ LİSTE: işlem modalı (alanlar ceksenet_islemler() yapısından) ═════════ */
    var iForm = document.getElementById('csIslemForm');
    var iIds = [], iPb = '', iKarne = 0;
    function islemAc(islem, ids, tetik) {
        if (!iForm || !VERI || !VERI.islemler[islem]) return;
        var m = VERI.islemler[islem], sinif = KOK.getAttribute('data-sinif');
        hataTemizle(iForm); eksikGizle();
        iForm.reset();
        iForm.elements.gecis.value = islem; iForm.elements.istek_anahtari.value = rastgele(); iForm.elements.eksiye_izin.value = '0';
        $$('input[name="ids[]"]', iForm).forEach(function (x) { x.remove(); });
        iIds = ids.slice();
        ids.forEach(function (id) { var h = document.createElement('input'); h.type = 'hidden'; h.name = 'ids[]'; h.value = String(id); iForm.appendChild(h); });
        var bilgi = ids.map(satirBilgi).filter(Boolean);
        var d = $('#csDetay');
        iPb = bilgi.length ? bilgi[0].pb : (d ? d.getAttribute('data-pb') : '');
        var karmaPb = bilgi.some(function (b) { return b.pb !== iPb; });
        iKarne = bilgi.length === 1 ? bilgi[0].karne : (d && ids.length === 1 ? +d.getAttribute('data-karne') || 0 : 0);
        var etiket = sinif === 'senet' ? m.etiket_senet : m.etiket;
        $('#csIslemBaslik').textContent = etiket + ' — ' + ids.length + ' evrak';
        var tp = {};
        bilgi.forEach(function (b) { tp[b.pb] = (tp[b.pb] || 0) + b.kurus; });
        var ozet = $('[data-islem-ozet]', iForm);
        ozet.textContent = (bilgi.length ? bilgi.slice(0, 4).map(function (b) { return b.portfoy; }).join(', ') + (bilgi.length > 4 ? ' +' + (bilgi.length - 4) : '') + ' · ' + toplamMetin(tp)
            : (d ? d.getAttribute('data-portfoy') : ''));
        $('[data-islem-etki]', iForm).textContent = m.etki;
        var gec = $('[data-gecersiz]', iForm); gec.hidden = true; while (gec.firstChild) gec.removeChild(gec.firstChild);
        // alanlar
        var cari = $('[data-alan="cari"]', iForm), kasa = $('[data-alan="kasa"]', iForm);
        cari.hidden = !m.cari_zorunlu; $$('input', cari).forEach(function (i) { i.disabled = !m.cari_zorunlu; i.required = false; });
        if (m.cari_zorunlu) { $('[name="cari_ara"]', cari).required = true; $('[data-cari-ipucu]', cari).textContent = ''; }
        var kasaGoster = !!m.kasa_sec;
        kasa.hidden = !kasaGoster;
        var sel = iForm.elements.kasa_id; sel.disabled = !kasaGoster; sel.required = kasaGoster && !!m.kasa_sec.zorunlu;
        var ip = $('[data-kasa-ipucu]', kasa); ip.textContent = '';
        if (kasaGoster) {
            $('[data-kasa-etiket]', kasa).textContent = (m.kasa_sec.tip === 'banka' ? 'Banka hesabı' : 'Kasa / banka hesabı') + (m.kasa_sec.zorunlu ? ' *' : ' (isteğe bağlı)') + (iPb ? ' · ' + iPb : '');
            var uygun = 0, tekId = '';
            Array.prototype.forEach.call(sel.options, function (o) {
                if (!o.value) return;
                var ok = (!iPb || o.getAttribute('data-pb') === iPb) && (!m.kasa_sec.tip || o.getAttribute('data-tip') === m.kasa_sec.tip);
                o.disabled = !ok; o.hidden = !ok;
                if (ok) { uygun++; tekId = o.value; }
            });
            sel.value = '';
            if (iKarne && VERI.karneKasa && VERI.karneKasa[iKarne]) { var vk = String(VERI.karneKasa[iKarne]), op = $('option[value="' + vk + '"]', sel); if (op && !op.disabled) sel.value = vk; }
            if (!sel.value && uygun === 1) sel.value = tekId;
            if (!uygun) {
                ip.textContent = m.kasa_sec.tip === 'banka' ? 'Bu para birimi için banka hesabı yok. Kasa Tanımları\'ndan ekleyin.' : 'Bu para birimi için kasa yok. Kasa Tanımları\'ndan ekleyin.';
            }
        }
        if (karmaPb && kasaGoster) genelHata(iForm, 'Seçili evrakların para birimleri farklı; kasa gerektiren işlem para birimi başına yapılmalıdır.');
        modalAc('csModalIslem', tetik);
    }
    function eksikGizle() { var e = $('#csEksik'); if (e) e.hidden = true; var ez = $('#csIslemForm [name="eksiye_izin"]'); if (ez) ez.value = '0'; }
    function islemGonder(yineDe) {
        var f = iForm; if (!f || f.dataset.kilit === '1') return;
        hataTemizle(f);
        if (!yineDe) eksikGizle();
        if (!f.elements.cari_id.disabled) cariCoz(f, VERI && VERI.cariler);
        var eksik = $$('[required]', f).filter(function (e) { return !e.disabled && !e.closest('[hidden]') && !String(e.value || '').trim(); });
        if (eksik.length) { eksik.forEach(function (e) { alanHata(f, e.name, S.m.zorunlu); }); eksik[0].focus(); return; }
        var btn = $$('button[type=submit]', f).concat($$('[data-eksik-kaydet]', f));
        f.dataset.kilit = '1'; btn.forEach(function (b) { b.disabled = true; });
        fetch(S.kaydet, { method: 'POST', body: new FormData(f), headers: S.baslik, credentials: 'same-origin' })
            .then(yanitCoz)
            .then(function (s) {
                f.dataset.kilit = ''; btn.forEach(function (b) { b.disabled = false; });
                if (s.j && s.j.ok) {
                    var m = VERI.islemler[f.elements.gecis.value], et = KOK.getAttribute('data-sinif') === 'senet' ? m.etiket_senet : m.etiket;
                    var msg = (s.j.tekrar ? 'İşlem zaten kaydedilmişti' : et + ' kaydedildi');
                    if (s.j.uyari) msg += ' — ' + s.j.uyari;
                    yenileToastla(msg, location.pathname.replace(/^.*\//, '') + location.search.replace(/([?&])id=\d+&?/, '$1').replace(/[?&]$/, ''), s.j.bordro_no);
                    return;
                }
                if (s.j && s.j.kod === 'yetersiz_bakiye') {
                    var ek = $('#csEksik', f);
                    $('[data-eksik="bakiye"]', ek).textContent = paraYaz(s.j.bakiye, iPb || 'TRY');
                    $('[data-eksik="eksik"]', ek).textContent = paraYaz(s.j.eksik, iPb || 'TRY');
                    $('[data-eksik="istenen"]', ek).textContent = paraYaz(s.j.bakiye + s.j.eksik, iPb || 'TRY');
                    ek.hidden = false;
                    try { ek.scrollIntoView({ block: 'nearest' }); } catch (x) { /* eski tarayıcı */ }
                    return;
                }
                if (s.j && s.j.kod === 'gecersiz_gecis' && s.j.kayitlar && s.j.kayitlar.length) {
                    var ul = $('[data-gecersiz]', f); while (ul.firstChild) ul.removeChild(ul.firstChild);
                    s.j.kayitlar.forEach(function (k) { var li = document.createElement('li'); li.textContent = (k.portfoy_no || ('#' + k.id)) + ': ' + (k.neden || 'uygun değil'); ul.appendChild(li); });
                    ul.hidden = false;
                }
                if (s.j && s.j.alanlar) {
                    var ilk = null;
                    Object.keys(s.j.alanlar).forEach(function (ad) { var el = alanHata(f, ad === 'gecis' || ad === 'ids' ? 'tarih' : ad, s.j.alanlar[ad]); if (el && !ilk) ilk = el; });
                    if (ilk && ilk.focus) ilk.focus();
                }
                genelHata(f, (s.j && s.j.hata) || ('İşlem yapılamadı (HTTP ' + s.r.status + ').'));
            })
            .catch(function () { f.dataset.kilit = ''; btn.forEach(function (b) { b.disabled = false; }); genelHata(f, S.m.ag); });
    }
    if (iForm) {
        iForm.addEventListener('input', function (e) { if (e.target.name === 'cari_ara') cariCoz(iForm, VERI && VERI.cariler); });
        iForm.addEventListener('change', function (e) { if (e.target.name === 'cari_ara') cariCoz(iForm, VERI && VERI.cariler); });
    }

    /* ═════════ LİSTE: hareket iptali ═════════ */
    function iptalAc(t) {
        var m = document.getElementById('csModalIptal'); if (!m) return;
        var f = $('form', m);
        f.reset(); hataTemizle(f);
        f.elements.id.value = t.getAttribute('data-hareket') || '';
        var g = (location.pathname.replace(/^.*\//, '') + location.search);
        if (guvenliYol(g)) f.elements.geri.value = g;
        var no = $('[data-iptal-no]', m); if (no) no.textContent = t.getAttribute('data-belge') || '';
        iptalSayac(f);
        modalAc('csModalIptal', t);
    }
    function iptalSayac(f) {
        var ta = $('[data-iptal-neden]', f), n = ta ? ta.value.trim().length : 0;
        var b = $('[data-iptal-onay]', f); if (b) b.disabled = n < 3;
        var sy = $('[data-sayac]', f); if (sy) sy.textContent = n < 3 ? 'En az 3 karakter' : n + ' / 500';
    }
    document.addEventListener('input', function (e) {
        var t = e.target;
        if (t && t.matches && t.matches('[data-iptal-neden]')) iptalSayac(t.form);
    });

    /* ═════════ BORDRO FORMU ═════════ */
    var bForm = document.getElementById('csBordroForm');
    if (bForm && FVERI) (function () {
        var f = bForm, tbody = $('.cs-fsatirlar', f), sablon = document.getElementById('csSatirSablon');
        var sinif = FVERI.sinif, yon = FVERI.yon, degisti = false;
        function satirlar() { return $$('.cs-fsatir', f); }
        function al(tr, ad) { return $('[name="' + ad + '[]"]', tr); }
        function dolu(tr) { return ['seri_no', 'tutar', 'vade', 'borclu'].some(function (a) { var e = al(tr, a); return e && !e.readOnly && String(e.value || '').trim() !== ''; }); }
        function numarala() { satirlar().forEach(function (tr, i) { var n = $('.cs-fs-no', tr); if (n) n.textContent = String(i + 1); }); }
        function karneBul(id) { var r = null; (FVERI.karneler || []).forEach(function (k) { if (String(k.id) === String(id)) r = k; }); return r; }

        /* Tutar alanının altında çözülen değer ("= 1.500,00 ₺") ya da belirsizlik hatası (PC satırı ve mobil kart aynı DOM) */
        function tutarOnizle(tr) {
            var gi = al(tr, 'tutar'), onz = $('[data-tutar-onizleme]', tr); if (!gi || !onz) return;
            var tc = tutarCoz(gi.value), pb = al(tr, 'para_birimi').value || 'TRY';
            onz.textContent = tc.belirsiz ? tc.mesaj : (tc.k !== null ? '= ' + paraYaz(tc.k, pb) : '');
            onz.classList.toggle('cs-tutar-onizleme--hata', tc.belirsiz);
            if (tc.belirsiz) { gi.setAttribute('aria-invalid', 'true'); gi.setAttribute('data-belirsiz', '1'); }
            else if (gi.getAttribute('data-belirsiz') === '1') { gi.removeAttribute('data-belirsiz'); gi.removeAttribute('aria-invalid'); var eh = $('.cs-hata', gi.closest('td')); if (eh) eh.remove(); }
        }
        function toplamlar() {
            var tp = {}, adet = 0, ort = [];
            satirlar().forEach(function (tr) {
                tutarOnizle(tr);
                if (!dolu(tr)) return;
                adet++;
                var k = kurusCoz(al(tr, 'tutar').value), pb = al(tr, 'para_birimi').value || 'TRY';
                if (k !== null) { tp[pb] = (tp[pb] || 0) + k; ort.push({ vade: al(tr, 'vade').value, kurus: k, pb: pb }); }
            });
            $('[data-top="adet"]', f).textContent = String(adet);
            $('[data-top-pb] b', f).textContent = toplamMetin(tp);
            $('[data-top-ort] b', f).textContent = ortMetin(ortVade(ort, f.elements.tarih.value));
        }
        function karneUygula(tr, oto) {
            var sel = $('[data-cs-karne]', tr); if (!sel) return;
            var k = karneBul(sel.value), banka = al(tr, 'banka_ad'), pb = al(tr, 'para_birimi'), seri = al(tr, 'seri_no'), ip = $('.cs-fs-ipucu', tr);
            Array.prototype.forEach.call(pb.options, function (o) { o.disabled = !!k && o.value !== k.pb; });
            if (k) {
                pb.value = k.pb; banka.value = k.banka; banka.readOnly = true;
                // sıradaki numara önerisi: karnenin sıradakisi + bu satırdan ÖNCEKİ aynı karneli satır sayısı
                if (k.siradaki && /^\d+$/.test(k.siradaki) && (oto || seri.value === '' || seri.getAttribute('data-oto') === '1')) {
                    var once = 0;
                    for (var tr2 of satirlar()) { if (tr2 === tr) break; var s2 = $('[data-cs-karne]', tr2); if (s2 && s2.value === sel.value) once++; }
                    var n = String(Number(k.siradaki) + once);
                    while (n.length < k.siradaki.length) n = '0' + n;
                    seri.value = n; seri.setAttribute('data-oto', '1');
                }
                if (ip) ip.textContent = 'Karne: ' + k.ad + ' · kalan ' + k.kalan + (k.siradaki ? ' · sıradaki ' + k.siradaki : ' · karne bitti');
            } else {
                banka.readOnly = false;
                if (seri.getAttribute('data-oto') === '1') { seri.value = ''; seri.removeAttribute('data-oto'); }
                if (ip) ip.textContent = '';
            }
        }
        function satirEkle(odak) {
            if (satirlar().length >= FVERI.satirMax) { toast('Bir bordroda en çok ' + FVERI.satirMax + ' satır girilebilir.', true); return null; }
            var tr = sablon.content.firstElementChild.cloneNode(true);
            tbody.appendChild(tr); numarala(); degisti = true;
            if (odak) { var o = $('input:not([type=hidden]), select', tr); if (o) o.focus(); }
            return tr;
        }
        function satirSil(tr) {
            var kardes = satirlar(), i = kardes.indexOf(tr);
            if (kardes.length > 1) { tr.parentNode.removeChild(tr); var y = satirlar(); var o = $('input:not([type=hidden]), select', y[Math.min(i, y.length - 1)]); if (o) o.focus(); }
            else { $$('input:not([type=hidden])', tr).forEach(function (x) { x.value = ''; x.readOnly = false; x.removeAttribute('data-oto'); }); $$('select', tr).forEach(function (x) { x.selectedIndex = 0; }); karneUygula(tr); }
            numarala(); toplamlar(); degisti = true;
        }
        function odaklanabilir(kok) { return $$('input:not([type=hidden]), select', kok).filter(function (x) { return !x.disabled && !x.readOnly && x.getClientRects().length; }); }
        function sonraki(t) {
            var tr = t.closest('.cs-fsatir');
            if (tr) {
                var h = odaklanabilir(tr), i = h.indexOf(t);
                if (i >= 0 && i < h.length - 1) { h[i + 1].focus(); if (h[i + 1].select && h[i + 1].tagName === 'INPUT') h[i + 1].select(); return; }
                var ss = satirlar();
                if (tr === ss[ss.length - 1]) { if (dolu(tr)) satirEkle(true); return; }
                var n = odaklanabilir(tr.nextElementSibling)[0]; if (n) n.focus();
                return;
            }
            var tum = odaklanabilir($('.cs-form-govde', f)), j = tum.indexOf(t);
            if (j >= 0 && j < tum.length - 1) tum[j + 1].focus();
        }
        f.addEventListener('keydown', function (e) {
            var t = e.target;
            if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S' || e.key === 'Enter')) { e.preventDefault(); bordroGonder(); return; }
            var tr = t.closest ? t.closest('.cs-fsatir') : null;
            if (e.ctrlKey && e.key === 'ArrowDown') { e.preventDefault(); satirEkle(true); return; }
            if (e.ctrlKey && e.key === 'Delete' && tr) { e.preventDefault(); satirSil(tr); return; }
            if (e.key === 'Escape') {
                if (degisti && window.confirm('Kaydedilmemiş değişiklikler var. Formdan çıkılsın mı?')) { degisti = false; location.assign($('.cs-vazgec', f).getAttribute('href')); }
                return;
            }
            if (e.key === 'Enter' && !e.shiftKey && !e.altKey && !e.ctrlKey && !e.metaKey && t.matches && t.matches('input:not([type=submit]):not([type=checkbox]), select')) { e.preventDefault(); sonraki(t); }
        });
        f.addEventListener('input', function (e) {
            var t = e.target; degisti = true;
            if (t.name === 'seri_no[]') t.removeAttribute('data-oto');
            if (t.name === 'cari_ara') { cariCoz(f, FVERI.cariler); cariOzet(); }
            toplamlar();
        });
        f.addEventListener('change', function (e) {
            var t = e.target; degisti = true;
            if (t.name === 'cari_ara') { cariCoz(f, FVERI.cariler); cariOzet(); }
            if (t.matches && t.matches('[data-cs-karne]')) karneUygula(t.closest('.cs-fsatir'), true);
            if (t.name === 'tarih' || t.name === 'tutar[]' || t.name === 'vade[]' || t.name === 'para_birimi[]') toplamlar();
        });
        f.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('[data-satir-ekle], [data-satir-sil]') : null; if (!b) return;
            e.preventDefault();
            if (b.hasAttribute('data-satir-ekle')) satirEkle(true); else satirSil(b.closest('.cs-fsatir'));
        });
        window.addEventListener('beforeunload', function (e) { if (degisti && f.dataset.kilit !== '1' && !f.dataset.bitti) { e.preventDefault(); e.returnValue = ''; } });

        var ozetIstek = 0;
        function cariOzet() {
            var kap = $('[data-cari-ozet-kap]', f); if (!kap) return;
            var id = f.elements.cari_id.value, n = ++ozetIstek;
            while (kap.firstChild) kap.removeChild(kap.firstChild);
            if (!id) return;
            fetch(S.sayfa.replace(/\?.*$/, '') + '?parca=cari_ozet&cari=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'fetch', 'Accept': 'text/html' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.text() : ''; })
                .then(function (h) { if (n !== ozetIstek || !h) return; var d = $('.cs-cari-ozet', htmlParca(h)); if (d) kap.appendChild(d); })
                .catch(function () { /* ipucu: sessiz */ });
        }

        function bordroGonder() {
            if (f.dataset.kilit === '1') return;
            hataTemizle(f);
            cariCoz(f, FVERI.cariler);
            var gen = [];
            if (!f.elements.cari_id.value) gen.push(['cari_ara', f.elements.cari_ara.value.trim() ? 'Listeden bir cari seçin.' : S.m.zorunlu]);
            if (!f.elements.tarih.value) gen.push(['tarih', S.m.zorunlu]);
            if (gen.length) { gen.forEach(function (g) { alanHata(f, g[0], g[1]); }); var e0 = $('[aria-invalid="true"]', f); if (e0) e0.focus(); return; }
            if (!satirlar().some(dolu)) { genelHata(f, 'En az bir ' + (sinif === 'cek' ? 'çek' : 'senet') + ' satırı girin.'); return; }
            var btn = $$('button[type=submit]', f);
            f.dataset.kilit = '1'; btn.forEach(function (b) { b.disabled = true; });
            fetch(S.kaydet, { method: 'POST', body: new FormData(f), headers: S.baslik, credentials: 'same-origin' })
                .then(yanitCoz)
                .then(function (s) {
                    f.dataset.kilit = ''; btn.forEach(function (b) { b.disabled = false; });
                    if (s.j && s.j.ok) {
                        f.dataset.bitti = '1';
                        var msg = (s.j.tekrar ? 'Bordro zaten kaydedilmişti' : 'Bordro kaydedildi') + (s.j.idler && s.j.idler.length ? ' · ' + s.j.idler.length + ' evrak' : '');
                        if (s.j.uyari) msg += ' — ' + s.j.uyari;
                        yenileToastla(msg, s.j.yonlendir, s.j.bordro_no);
                        return;
                    }
                    var ilk = null;
                    if (s.j && s.j.satir_hatalari) {
                        var trs = satirlar();
                        Object.keys(s.j.satir_hatalari).forEach(function (i) {
                            var tr = trs[+i]; if (!tr) return;
                            tr.classList.add('cs-fsatir--hata');
                            var eh = s.j.satir_hatalari[i];
                            Object.keys(eh).forEach(function (a) {
                                var el = $('[name="' + a + '[]"]', tr);
                                if (el && el.type !== 'hidden') {
                                    el.setAttribute('aria-invalid', 'true');
                                    if (!(a === 'tutar' && el.getAttribute('data-belirsiz') === '1')) { var sp = document.createElement('span'); sp.className = 'cs-hata'; sp.textContent = eh[a]; (el.closest('td') || el.parentNode).appendChild(sp); }   /* belirsiz tutarda önizleme aynı metni zaten gösterir */
                                    if (!ilk) ilk = el;
                                } else if (!ilk) { ilk = $('input:not([type=hidden])', tr); }
                            });
                        });
                    }
                    if (s.j && s.j.alanlar) Object.keys(s.j.alanlar).forEach(function (ad) { var el = alanHata(f, ad, s.j.alanlar[ad]); if (el && !ilk) ilk = el; });
                    if (ilk && ilk.focus) { try { ilk.scrollIntoView({ block: 'center' }); } catch (x) { /* eski tarayıcı */ } ilk.focus(); }
                    genelHata(f, (s.j && s.j.hata) || ('Kaydedilemedi (HTTP ' + s.r.status + ').'));
                })
                .catch(function () { f.dataset.kilit = ''; btn.forEach(function (b) { b.disabled = false; }); genelHata(f, S.m.ag); });
        }
        window.csBordroGonder = bordroGonder;
        numarala(); toplamlar();
    })();
    function bordroGonder() { if (window.csBordroGonder) window.csBordroGonder(); }

    /* ═════════ TANIMLAR ═════════ */
    function tanimTurGoster(f, tur) {
        f.elements.tur.value = tur;
        $$('[data-tur]', f).forEach(function (el) {
            var on = el.getAttribute('data-tur') === tur;
            el.hidden = !on;
            $$('input, select', el).forEach(function (i) { i.disabled = !on; });
        });
    }
    function tanimAc(t) {
        var m = document.getElementById('csModalTanim'); if (!m) return;
        var f = $('form', m), tur = t.getAttribute('data-cs-tanim-ac'), v = null;
        f.reset(); hataTemizle(f);
        try { v = t.getAttribute('data-tanim') ? JSON.parse(t.getAttribute('data-tanim')) : null; } catch (x) { v = null; }
        tanimTurGoster(f, tur);
        f.elements.id.value = v ? String(v.id) : '';
        $('#csTanimBaslik', m).textContent = (v ? 'Düzenle — ' : 'Yeni ') + (tur === 'banka' ? 'banka' : 'çek karnesi');
        if (v) ['ad', 'sube', 'kasa_id', 'ilk_no', 'yaprak', 'sira'].forEach(function (k) { var el = f.elements[k]; if (el && !el.disabled && v[k] != null) el.value = String(v[k]); });
        modalAc('csModalTanim', t);
    }
    var nav = $$('.cs-tanim-nav-oge', KOK);
    nav.forEach(function (t) {
        t.addEventListener('click', function (e) {
            if (e.ctrlKey || e.metaKey) return;
            e.preventDefault();
            nav.forEach(function (x) {
                var on = x === t;
                x.setAttribute('aria-selected', on ? 'true' : 'false');
                var p = document.getElementById(x.getAttribute('aria-controls')); if (p) p.hidden = !on;
            });
            try { history.replaceState(history.state, '', t.getAttribute('href')); } catch (x) { /* kritik değil */ }
        });
    });
})();
