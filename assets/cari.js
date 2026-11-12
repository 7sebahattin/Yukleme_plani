/* =========================================================
   assets/cari.js — Cari İşlemler modülü (cari_kartlari / cari_fisler /
   cari_dekontlar / cari_tanimlari)

   Kademeli iyileştirme: JS yokken her şey klasik link/GET/POST (+PRG) ile
   çalışır. JS varken: canlı filtre, master-detail (fetch + pushState),
   ekstre filtreleri, modallar, fetch ile kayıt, mobil menü/filtre sayfaları.

   Tek IIFE, global yok, bağımlılık yok. Sayfada `.cari` kökü yoksa hiçbir şey
   yapmaz. assets/app.js'e ve style.css'e dokunmaz / onlarla isim paylaşmaz.
   Sözleşme (uç adresleri, parametre adları, seçiciler) SOZLESME bloğunda;
   alan adları JS'e gömülü DEĞİL — formun kendisinden (FormData) okunur.
   ========================================================= */
(function () {
    'use strict';

    var KOK = document.querySelector('.cari');
    if (!KOK) return;

    /* ═════════ SOZLESME — entegrasyonda düzeltilecek tek yer ═════════ */
    var SOZLESME = {
        uc: { kaydet: 'cari_kaydet.php', kartlar: 'cari_kartlari.php' },
        /* Kısmi HTML / JSON isteklerinin başlıkları */
        baslikJson: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
        baslikParca: { 'X-Requested-With': 'fetch', 'Accept': 'text/html' },
        baslikSayfa: { 'Accept': 'text/html' },
        /* GET parametreleri (cari_kartlari.php) */
        p: { parca: 'parca', detay: 'detay', ekstre: 'ekstre', kart: 'kart', id: 'id', sekme: 'sekme', donem: 'donem' },
        /* POST `islem` değerleri (cari_kaydet.php) */
        islem: { hareket: 'hareket_ekle', virman: 'virman_ekle', tanimDurum: 'tanim_durum',
                 tanimKaydet: 'tanim_kaydet', tanimSil: 'tanim_sil', iceAktar: 'ice_aktar' },
        /* Virman için sunucunun beklediği ek kaynak alanı (cari_id'nin kopyası) */
        virmanKaynakAlan: 'kaynak_id',
        /* Oturum/anahtar alanı adları */
        alan: { csrf: 'csrf', islem: 'islem', istek: 'istek_anahtari' },
        /* Dönem "Tümü" için sınır tarihi (sunucu tarih doğrulamasına takılmasın) */
        tumuBas: '2000-01-01',
        tumuBitGun: 366,
        /* Yönlendirme beyaz listesi (sunucu yanıtındaki `yonlendir` için) */
        yonlendirRe: /^cari_[a-z]+\.php(\?[^\r\n]*)?$/,
        toastAnahtar: 'cari_toast',
        /* Metinler */
        m: {
            zorunlu: 'Bu alan zorunlu',
            minKarakter: 'En az {n} karakter',
            cariSec: 'Bir cari seçin',
            ayniCari: 'Aynı cari seçilemez',
            ondalik: 'En çok 2 ondalık: 1.234,56',
            yonSec: 'Borç ya da alacak seçin',
            kaydediliyor: 'Kaydediliyor…',
            kaydedildi: 'Kaydedildi',
            ag: 'Bağlantı hatası, tekrar deneyin. Girdiğiniz bilgiler korundu.',
            csrf: 'Güvenlik anahtarının süresi dolmuş olabilir. Sayfayı yenileyip işlemi tekrarlayın.',
            oturum: 'Oturumunuz sona ermiş görünüyor. Sayfayı yenileyip yeniden giriş yapın.',
            sunucu: 'Sunucu hatası ({kod}). Biraz sonra tekrar deneyin.',
            yuklenemedi: 'Yüklenemedi. Bağlantınızı kontrol edip tekrar deneyin.',
            tekrar: 'Tekrar dene',
            gerial: 'Geri al',
            kopyalandi: 'Kopyalandı',
            zorunluGenel: 'Zorunlu alanları doldurun',
            yeniCari: 'Yeni cari',
            duzenleCari: 'Cariyi düzenle'
        }
    };

    /* Belge türü → modal davranışı (tasarim_cari.md §B) */
    var TUR = {
        tahsilat:      { ad: 'Tahsilat Fişi',        gruplar: 'odeme',  acik: false, ipucu: '<b>Alacak</b> yazılır: cariden para aldık, bakiyesi <b>azalır</b>.' },
        odeme:         { ad: 'Ödeme Fişi',           gruplar: 'odeme',  acik: false, ipucu: '<b>Borç</b> yazılır: cariye para ödedik, bakiyesi <b>artar</b> (bize borçlu yönüne).' },
        acilis:        { ad: 'Açılış / Devir',       gruplar: 'yon',    acik: true,  ipucu: 'Önceki dönemden devreden bakiye. <b>Yönü siz seçersiniz</b>; açıklama zorunlu.' },
        borc_dekont:   { ad: 'Borç Dekontu',         gruplar: '',       acik: true,  ipucu: '<b>Borç</b> yazılır: cari bize borçlanır (ör. satış, fiyat farkı). Açıklama zorunlu.' },
        alacak_dekont: { ad: 'Alacak Dekontu',       gruplar: '',       acik: true,  ipucu: '<b>Alacak</b> yazılır: cari alacaklanır (ör. iade, iskonto). Açıklama zorunlu.' },
        virman:        { ad: 'Cariler Arası Virman', gruplar: 'virman', acik: true,  ipucu: 'Kaynak cari <b>alacaklanır</b>, hedef cari <b>borçlanır</b>. Aynı para birimi; açıklama zorunlu.' }
    };
    var SEMBOL = { TRY: '₺', USD: '$', EUR: '€' };
    var KISAYOL_TUR = { t: 'tahsilat', o: 'odeme', d: 'borc_dekont' };
    var ODAK = 'a[href],button:not([disabled]),input:not([disabled]):not([type=hidden]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

    /* ═════════ Yardımcılar ═════════ */
    function $(s, k) { return (k || document).querySelector(s); }
    function $$(s, k) { return Array.prototype.slice.call((k || document).querySelectorAll(s)); }
    function genis() { return window.matchMedia('(min-width:1024px)').matches; }
    function gorunur(e) { return !!(e && e.getClientRects().length); }
    function metin(s, v) { return String(s).replace(/\{(\w+)\}/g, function (_, k) { return v && v[k] != null ? v[k] : ''; }); }

    /* TR-duyarsız karşılaştırma: İ/I/ı/i → i, ş/ğ/ü/ö/ç → s/g/u/o/c, küçük harf */
    function norm(s) {
        return String(s == null ? '' : s)
            .replace(/̇/g, '')
            .replace(/[İIı]/g, 'i')
            .toLowerCase()
            .replace(/ş/g, 's').replace(/ğ/g, 'g').replace(/ü/g, 'u').replace(/ö/g, 'o').replace(/ç/g, 'c');
    }
    function depoOku(k) { try { return window.sessionStorage.getItem(k); } catch (x) { return null; } }
    function depoYaz(k, v) { try { if (v == null) window.sessionStorage.removeItem(k); else window.sessionStorage.setItem(k, v); } catch (x) { /* depolama yok: sorun değil */ } }

    function yeniAnahtar() {
        try {
            if (window.crypto && crypto.randomUUID) return crypto.randomUUID().replace(/-/g, '');
            if (window.crypto && crypto.getRandomValues) {
                var a = new Uint8Array(16); crypto.getRandomValues(a);
                return Array.prototype.map.call(a, function (b) { return (b < 16 ? '0' : '') + b.toString(16); }).join('');
            }
        } catch (x) { /* yedek aşağıda */ }
        var s = ''; while (s.length < 32) s += Math.floor(Math.random() * 16).toString(16);
        return s;
    }
    function iso(d) {
        function p(n) { return (n < 10 ? '0' : '') + n; }
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
    }
    function donemTarih(donem) {
        var b = new Date(), y = b.getFullYear(), a = b.getMonth();
        if (donem === 'ay') return [iso(new Date(y, a, 1)), iso(new Date(y, a + 1, 0))];
        if (donem === 'gecen') return [iso(new Date(y, a - 1, 1)), iso(new Date(y, a, 0))];
        if (donem === 'yil') return [iso(new Date(y, 0, 1)), iso(new Date(y, 11, 31))];
        if (donem === 'tumu') return [SOZLESME.tumuBas, iso(new Date(y, a, b.getDate() + SOZLESME.tumuBitGun))];
        return null;
    }
    var DONEM_METIN = { 'bu ay': 'ay', 'geçen ay': 'gecen', 'bu yıl': 'yil', 'tümü': 'tumu', 'özel…': 'ozel', 'özel': 'ozel' };
    function donemAdi(c) {
        return c.getAttribute('data-donem') || DONEM_METIN[c.textContent.trim().toLocaleLowerCase('tr')] || '';
    }
    function para(kurus, pb) {
        var neg = kurus < 0, k = Math.abs(Math.round(kurus)), t = Math.floor(k / 100), c = k % 100;
        return (neg ? '-' : '') + String(t).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + (c < 10 ? '0' : '') + c + ' ' + (SEMBOL[pb] || pb || '');
    }
    /* Yalnız ÖNİZLEME için gevşek ayrıştırma — sunucu asıl ayrıştırıcıdır, değer ASLA değiştirilmez */
    function tutarKurus(s) {
        s = String(s || '').replace(/\s/g, '');
        if (!s) return null;
        if (s.indexOf(',') > -1) s = s.replace(/\./g, '').replace(',', '.');
        else if (/^\d{1,3}(\.\d{3})+$/.test(s)) s = s.replace(/\./g, '');
        var n = parseFloat(s);
        return isFinite(n) && n > 0 ? Math.round(n * 100) : null;
    }
    function guvenliYol(u) { return typeof u === 'string' && SOZLESME.yonlendirRe.test(u); }
    function urlEkle(href, ekler) {
        var u = new URL(href, location.href);
        Object.keys(ekler).forEach(function (k) { if (ekler[k] == null) u.searchParams.delete(k); else u.searchParams.set(k, ekler[k]); });
        return u.pathname.replace(/^.*\//, '') + u.search;
    }
    function csrfAl(kaynak) {
        var f = kaynak && kaynak.closest ? kaynak.closest('form') : null;
        var el = (f && f.elements && f.elements[SOZLESME.alan.csrf]) || $('input[name="' + SOZLESME.alan.csrf + '"]', KOK) || $('input[name="' + SOZLESME.alan.csrf + '"]');
        if (el && el.value) return el.value;
        var meta = $('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    /* ═════════ Toast (+ sayfa yenilemesine taşınan) ═════════ */
    var toastZ = null;
    function toast(msg, o) {
        o = o || {};
        var t = $('.cari-toast');
        if (!t) { t = document.createElement('div'); t.className = 'cari-toast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
        t.textContent = '';
        var s = document.createElement('span'); s.textContent = msg; t.appendChild(s);
        if (o.eylem) {
            var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-sm'; b.style.marginLeft = '10px';
            b.textContent = o.eylem.ad;
            b.addEventListener('click', function () { t.hidden = true; o.eylem.fn(); });
            t.appendChild(b);
        }
        t.hidden = false;
        clearTimeout(toastZ);
        toastZ = setTimeout(function () { t.hidden = true; }, o.eylem ? 6000 : (o.hata ? 4500 : 2600));
    }
    function yenileToastla(msg, url) {
        if (msg) depoYaz(SOZLESME.toastAnahtar, msg);
        if (url && guvenliYol(url)) location.assign(url); else location.reload();
    }

    /* ═════════ Gövde kaydırma kilidi (sayaçlı) ═════════ */
    var kilitSay = 0;
    function govdeKilit(k) {
        kilitSay = Math.max(0, kilitSay + (k ? 1 : -1));
        document.body.style.overflow = kilitSay ? 'hidden' : '';
    }

    /* ═════════ Modal: aç/kapat, Esc, perde, odak tuzağı, inert ═════════ */
    var acik = null, acan = null, acanImza = null, mdownHedef = null, zorlaOdak = null;
    function disariKilitle(m, k) {
        var n = m;
        while (n && n !== KOK) {
            var p = n.parentNode; if (!p) break;
            Array.prototype.forEach.call(p.children, function (c) { if (c !== n && c.tagName !== 'SCRIPT') c.inert = k; });
            n = p;
        }
    }
    function imzaAl(e) {
        if (!e || !e.getAttribute) return null;
        var m = e.getAttribute('data-cr-modal');
        return m ? { modal: m, tur: e.getAttribute('data-tur') } : null;
    }
    function odakGeri() {
        var e = acan;
        if (!e || !document.contains(e) || !gorunur(e)) {
            e = null;
            if (acanImza) {
                var s = '[data-cr-modal="' + acanImza.modal + '"]' + (acanImza.tur ? '[data-tur="' + acanImza.tur + '"]' : '');
                e = $$(s, KOK).filter(function (x) { return gorunur(x) && !x.disabled; })[0] || null;
            }
        }
        if (e && e.focus) { try { e.focus({ preventScroll: true }); } catch (x) { e.focus(); } }
    }
    function modalAc(id, opener, geriOdak) {
        var m = document.getElementById(id); if (!m) return;
        if (acik) modalKapat(true);
        kapatMenuler();
        acik = m; acan = geriOdak || zorlaOdak || opener || document.activeElement; acanImza = imzaAl(acan);
        m.removeAttribute('data-kirli');
        m.hidden = false;
        disariKilitle(m, true);
        govdeKilit(true);
        var ilk = $$('[data-ilk-odak]', m).filter(function (x) { return !x.disabled && !x.closest('[hidden]'); })[0] ||
                  $$(ODAK, $('.pm-body', m) || m).filter(gorunur)[0];
        setTimeout(function () { if (ilk) ilk.focus(); }, 30);
    }
    function modalKapat(sessiz) {
        if (!acik) return;
        var m = acik; acik = null;
        m.hidden = true;
        disariKilitle(m, false);
        govdeKilit(false);
        if (!sessiz) odakGeri();
    }

    document.addEventListener('mousedown', function (e) { mdownHedef = e.target; });
    document.addEventListener('click', function (e) {
        var ov = e.target;
        if (ov.classList && ov.classList.contains('pm-overlay') && ov === mdownHedef && acik === ov && !ov.hasAttribute('data-kirli')) modalKapat();
    });
    document.addEventListener('input', function (e) { var ov = e.target.closest && e.target.closest('.pm-overlay'); if (ov) ov.setAttribute('data-kirli', '1'); });
    document.addEventListener('change', function (e) { var ov = e.target.closest && e.target.closest('.pm-overlay'); if (ov) ov.setAttribute('data-kirli', '1'); });

    /* ═════════ Menüler (Dekont ▾, ⋯) — liste .cari kökünün sonunda ═════════ */
    var menuAcik = null, sonMenuTetik = null;
    function kapatMenuler(odak) {
        if (!menuAcik) return;
        menuAcik.liste.hidden = true;
        if (menuAcik.ortu) menuAcik.ortu.hidden = true;
        menuAcik.dugme.setAttribute('aria-expanded', 'false');
        var d = menuAcik.dugme; menuAcik = null;
        if (odak) d.focus();
    }
    function menuAc(dugme) {
        var liste = document.getElementById(dugme.getAttribute('aria-controls')), ortu = $('.cari-menu-ortu', KOK);
        if (!liste) return;
        if (menuAcik && menuAcik.dugme === dugme) { kapatMenuler(true); return; }
        kapatMenuler();
        liste.hidden = false; if (ortu) ortu.hidden = false;
        dugme.setAttribute('aria-expanded', 'true');
        if (window.matchMedia('(min-width:768px)').matches) {
            var r = dugme.getBoundingClientRect(), w = liste.offsetWidth, h = liste.offsetHeight;
            liste.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, r.right - w)) + 'px';
            liste.style.top = (r.bottom + 4 + h > window.innerHeight ? Math.max(8, r.top - h - 4) : r.bottom + 4) + 'px';
        } else { liste.style.left = liste.style.top = ''; }
        menuAcik = { dugme: dugme, liste: liste, ortu: ortu };
        sonMenuTetik = dugme;
        var o = $$('[role=menuitem]:not([disabled])', liste).filter(gorunur)[0]; if (o) o.focus();
    }
    document.addEventListener('keydown', function (e) {
        if (!menuAcik) return;
        var o = $$('[role=menuitem]:not([disabled])', menuAcik.liste).filter(gorunur), i = o.indexOf(document.activeElement);
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); kapatMenuler(true); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); (o[i + 1] || o[0]).focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); (o[i - 1] || o[o.length - 1]).focus(); }
        else if (e.key === 'Tab') kapatMenuler();
    }, true);
    window.addEventListener('resize', function () { kapatMenuler(); });

    /* ═════════ Cari verisi (#crCariVeri) + seçici (combobox) ═════════ */
    var VERI = [];
    function veriOku() {
        var s = document.getElementById('crCariVeri'); VERI = [];
        try { VERI = JSON.parse((s && s.textContent) || '[]') || []; } catch (x) { VERI = []; }
    }
    function cariBul(id) { for (var i = 0; i < VERI.length; i++) if (String(VERI[i].id) === String(id)) return VERI[i]; return null; }
    function seciciId(w) { var h = $('input[type=hidden][name]', w); return h ? h.value : ''; }
    function seciliYap(w, c) {
        if (!w || !c) return;
        var h = $('input[type=hidden][name]', w); if (h) h.value = c.id;
        var ad = $('.cari-secili-ad', w);
        if (ad) {
            ad.textContent = '';
            var a = document.createElement('span'); a.textContent = c.unvan;
            var k = document.createElement('small'); k.textContent = (c.kod || '') + (c.bakiye ? ' · ' + c.bakiye : '');
            ad.appendChild(a); ad.appendChild(k);
        }
        var sc = $('.cari-secili', w); if (sc) sc.hidden = false;
        var cb = $('input[role=combobox]', w); if (cb) { var l = cb.closest('.pm-label'); if (l) l.hidden = true; cb.value = ''; cb.setAttribute('aria-expanded', 'false'); }
        var ul = $('.cari-secici-liste', w); if (ul) ul.hidden = true;
        seciciHataTemizle(w);
        var f = w.closest('form'); if (f) { pbVarsayilan(f, c); virmanOnizle(f.closest('.pm-overlay')); }
    }
    function seciliKaldir(w) {
        var h = $('input[type=hidden][name]', w); if (h) h.value = '';
        var sc = $('.cari-secili', w); if (sc) sc.hidden = true;
        var cb = $('input[role=combobox]', w);
        if (cb) { var l = cb.closest('.pm-label'); if (l) l.hidden = false; cb.value = ''; cb.focus(); }
        var f = w.closest('form'); if (f) virmanOnizle(f.closest('.pm-overlay'));
    }
    function pbVarsayilan(f, c) {
        if (!c || !c.pb || f.dataset.pbElle === '1') return;
        var r = $('input[name="para_birimi"][value="' + c.pb + '"]', f);
        if (r && !r.checked) { r.checked = true; kurUygula(f); }
    }
    function seciciHazirla(w) {
        if (w._hazir) return; w._hazir = true;
        var inp = $('input[role=combobox]', w), ul = $('.cari-secici-liste', w), idx = -1;
        if (!inp || !ul) return;
        function kapat() { ul.hidden = true; inp.setAttribute('aria-expanded', 'false'); inp.removeAttribute('aria-activedescendant'); idx = -1; }
        function ciz(q) {
            var f = w.closest('form'), haric = '';
            if (w.getAttribute('data-alan') === 'hedef_id' && f) { var k = $('[data-cari-secici] input[type=hidden][name="cari_id"]', f); haric = k ? k.value : ''; }
            var toks = norm(q).split(/\s+/).filter(Boolean);
            var s = VERI.filter(function (c) {
                if (haric && String(c.id) === String(haric)) return false;
                var hay = norm((c.unvan || '') + ' ' + (c.kod || ''));
                return toks.every(function (t) { return hay.indexOf(t) > -1; });
            }).slice(0, 8);
            ul.textContent = '';
            s.forEach(function (c, i) {
                var li = document.createElement('li'); li.className = 'cari-secici-oge'; li.setAttribute('role', 'option');
                li.id = ul.id + '-' + i; li.setAttribute('data-id', c.id); li.setAttribute('aria-selected', 'false');
                var a = document.createElement('span'); a.textContent = c.unvan;
                var b = document.createElement('small'); b.textContent = c.kod || '';
                li.appendChild(a); li.appendChild(b); ul.appendChild(li);
            });
            ul.hidden = !s.length; inp.setAttribute('aria-expanded', String(!!s.length)); idx = -1; inp.removeAttribute('aria-activedescendant');
        }
        inp.addEventListener('focus', function () { ciz(inp.value); });
        inp.addEventListener('input', function () { ciz(inp.value); });
        inp.addEventListener('blur', function () { setTimeout(kapat, 120); });
        inp.addEventListener('keydown', function (e) {
            var o = $$('.cari-secici-oge', ul);
            if (e.key === 'Escape' && !ul.hidden) { e.preventDefault(); e.stopPropagation(); kapat(); return; }
            if (!o.length) return;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (ul.hidden) { ul.hidden = false; inp.setAttribute('aria-expanded', 'true'); }
                idx = (idx + (e.key === 'ArrowDown' ? 1 : -1) + o.length) % o.length;
                o.forEach(function (x, i) { x.setAttribute('aria-selected', String(i === idx)); });
                inp.setAttribute('aria-activedescendant', o[idx].id);
                o[idx].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter') {
                e.preventDefault();
                var hedef = o[idx > -1 ? idx : 0]; if (hedef) seciliYap(w, cariBul(hedef.getAttribute('data-id')));
            }
        });
        ul.addEventListener('mousedown', function (e) {
            var li = e.target.closest('.cari-secici-oge'); if (li) { e.preventDefault(); seciliYap(w, cariBul(li.getAttribute('data-id'))); }
        });
        var deg = $('.cari-secili button', w);
        if (deg) deg.addEventListener('click', function () { seciliKaldir(w); });
    }

    /* ═════════ Form hataları ═════════ */
    function hataTemizle(f) {
        $$('.cari-hata[data-js]', f).forEach(function (x) { x.parentNode.removeChild(x); });
        $$('.error, [aria-invalid]', f).forEach(function (x) {
            x.classList.remove('error'); x.removeAttribute('aria-invalid');
            if (x.getAttribute('data-hata-id')) { x.removeAttribute('aria-describedby'); x.removeAttribute('data-hata-id'); }
        });
        var g = $('.cari-form-hata', f); if (g) { g.hidden = true; g.textContent = ''; }
    }
    function seciciHataTemizle(w) {
        $$('.cari-hata[data-js]', w).forEach(function (x) { x.parentNode.removeChild(x); });
        var cb = $('input[role=combobox]', w); if (cb) { cb.classList.remove('error'); cb.removeAttribute('aria-invalid'); }
    }
    function hataEkle(yer, el, msg, ad) {
        var s = document.createElement('span'); s.className = 'cari-hata'; s.setAttribute('data-js', '1');
        s.id = 'crH-' + ad + '-' + Math.floor(Math.random() * 1e6); s.textContent = msg;
        yer.appendChild(s);
        if (el) { el.setAttribute('aria-describedby', s.id); el.setAttribute('data-hata-id', s.id); }
        return s;
    }
    function alanHata(f, ad, msg) {
        var els = $$('[name="' + ad + '"]', f);
        var el = els.filter(function (x) { return x.type !== 'hidden' && !x.disabled && !x.closest('[hidden]'); })[0];
        if (!el) {
            var gizli = els.filter(function (x) { return x.type === 'hidden' && !x.disabled; })[0];
            var w = gizli && gizli.closest('[data-cari-secici]');
            if (w && !w.closest('[hidden]')) {
                var cb = $('input[role=combobox]', w) || $('.cari-secili', w);
                if (cb && cb.classList) cb.classList.add('error'); if (cb && cb.setAttribute) cb.setAttribute('aria-invalid', 'true');
                hataEkle(w, cb, msg, ad);
                return cb && cb.focus ? cb : w;
            }
            return null;
        }
        if (el.type === 'radio') {
            var grp = el.closest('[role=radiogroup]') || el.parentNode;
            grp.setAttribute('aria-invalid', 'true');
            hataEkle(grp.parentNode, grp, msg, ad);
            return el;
        }
        el.classList.add('error'); el.setAttribute('aria-invalid', 'true');
        var yer = el.closest('.pm-label') || el.parentNode;
        hataEkle(yer, el, msg, ad);
        return el;
    }
    function genelHata(f, msg) {
        var g = $('.cari-form-hata', f);
        if (!g) {
            var mod = f.closest('.pm-overlay');
            if (mod) {
                g = document.createElement('div'); g.className = 'flash flash-error cari-form-hata'; g.setAttribute('role', 'alert');
                var b = $('.pm-body', f) || f; b.insertBefore(g, b.firstChild);
            }
        }
        if (g) { g.textContent = msg; g.hidden = false; }
        else toast(msg, { hata: true });
    }
    function sunucuHatasi(f, j) {
        var ilk = null, genel = [];
        if (j && j.alanlar && typeof j.alanlar === 'object') {
            Object.keys(j.alanlar).forEach(function (ad) {
                var el = alanHata(f, ad, String(j.alanlar[ad]));
                if (el) { if (!ilk) ilk = el; } else genel.push(String(j.alanlar[ad]));
            });
        }
        var msg = (j && j.hata) ? String(j.hata) : '';
        if (genel.length) msg = (msg ? msg + ' ' : '') + genel.join(' ');
        if (!msg && !ilk) msg = 'Kayıt yapılamadı.';
        if (msg) genelHata(f, msg);
        if (ilk && ilk.focus) ilk.focus();
    }

    /* ═════════ İstemci doğrulaması (yalnız bariz eksikler; sunucu yetkili) ═════════ */
    function dogrula(f) {
        var hatalar = [];
        function ekle(ad, msg, el) { hatalar.push({ ad: ad, msg: msg, el: el }); }
        $$('input, select, textarea', f).forEach(function (x) {
            if (x.disabled || x.type === 'hidden' || x.type === 'radio' || x.type === 'checkbox' || x.getAttribute('role') === 'combobox') return;
            if (x.closest('[hidden]')) return;
            var v = (x.value || '').trim();
            if (x.required && !v) ekle(x.name, SOZLESME.m.zorunlu, x);
            else if (v && x.getAttribute('minlength') && v.length < parseInt(x.getAttribute('minlength'), 10)) ekle(x.name, metin(SOZLESME.m.minKarakter, { n: x.getAttribute('minlength') }), x);
        });
        $$('[data-cari-secici]', f).forEach(function (w) {
            if (w.closest('[hidden]')) return;
            var h = $('input[type=hidden][name]', w); if (!h || h.disabled) return;
            if (!h.value) ekle(h.name, SOZLESME.m.cariSec, h);
        });
        var tur = f.elements.tur && f.elements.tur.value;
        if (f.elements.islem && /^(hareket_ekle|virman_ekle)$/.test(f.elements.islem.value)) {
            if (TUR[tur] && TUR[tur].gruplar === 'yon' && !$('input[name="yon"]:checked', f)) ekle('yon', SOZLESME.m.yonSec, $('input[name="yon"]', f));
            if (tur === 'virman') {
                var k = f.elements.cari_id, hd = f.elements.hedef_id;
                if (k && hd && k.value && hd.value && k.value === hd.value) ekle('hedef_id', SOZLESME.m.ayniCari, hd);
            }
            var tt = f.elements.tutar;
            if (tt && tt.value && /,\d{3,}$/.test(tt.value.trim())) ekle('tutar', SOZLESME.m.ondalik, tt);
        }
        return hatalar;
    }

    /* ═════════ Gönderim (form[data-cr-ajax]) ═════════ */
    function kilitle(f, k, btnler) {
        f.dataset.kilit = k ? '1' : '';
        btnler.forEach(function (b) {
            if (k) { b._eski = b.textContent; b.disabled = true; if (b.type === 'submit') b.textContent = SOZLESME.m.kaydediliyor; }
            else { b.disabled = false; if (b._eski != null) b.textContent = b._eski; }
        });
    }
    function yanitCoz(r) {
        return r.text().then(function (t) { var j = null; try { j = JSON.parse(t); } catch (x) { /* JSON değil */ } return { r: r, j: j }; });
    }
    function yanitMesaji(s) {
        var r = s.r, j = s.j;
        if (j && j.hata) return String(j.hata) + (r.status === 403 && !/yenile/i.test(j.hata) ? ' ' + SOZLESME.m.csrf : '');
        if (r.status === 403) return SOZLESME.m.csrf;
        if (r.status === 401 || r.redirected) return SOZLESME.m.oturum;
        return metin(SOZLESME.m.sunucu, { kod: r.status });
    }
    function gonder(f) {
        if (f.dataset.kilit === '1') return;
        if (f.getAttribute('data-onay') && !window.confirm(f.getAttribute('data-onay'))) return;
        hataTemizle(f);
        var hatalar = dogrula(f);
        if (hatalar.length) {
            var ilk = null;
            hatalar.forEach(function (h) { var el = alanHata(f, h.ad, h.msg); if (el && !ilk) ilk = el; });
            if (ilk && ilk.focus) ilk.focus();
            if (!$('.cari-form-hata', f) || !f.closest('.pm-overlay')) toast(SOZLESME.m.zorunluGenel, { hata: true });
            return;
        }
        var btnler = $$('button[type=submit], button:not([type])', f);
        kilitle(f, true, btnler);
        var fd = new FormData(f);
        if (fd.get(SOZLESME.alan.islem) === SOZLESME.islem.virman && SOZLESME.virmanKaynakAlan) fd.set(SOZLESME.virmanKaynakAlan, fd.get('cari_id') || '');
        fetch(f.getAttribute('action') || SOZLESME.uc.kaydet, { method: 'POST', body: fd, headers: SOZLESME.baslikJson, credentials: 'same-origin' })
            .then(yanitCoz)
            .then(function (s) {
                kilitle(f, false, btnler);
                if (s.j && s.j.ok) basari(f, fd, s.j);
                else if (s.j && (s.j.alanlar || s.j.hata)) { sunucuHatasi(f, s.j); if (s.r.status === 403 && !s.j.alanlar) genelHata(f, yanitMesaji(s)); }
                else genelHata(f, yanitMesaji(s));
            })
            .catch(function () { kilitle(f, false, btnler); genelHata(f, SOZLESME.m.ag); });
    }
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f.matches || !f.matches('form[data-cr-ajax]')) return;
        e.preventDefault();
        gonder(f);
    });

    function kartlarSayfasi() { return !!document.getElementById('crListe'); }
    function basari(f, fd, j) {
        var islem = fd.get(SOZLESME.alan.islem) || '';
        var modal = f.closest('.pm-overlay');
        var anahtar = f.elements[SOZLESME.alan.istek]; if (anahtar) anahtar.value = yeniAnahtar();
        if (modal) modalKapat();
        var msg = f.getAttribute('data-ok') || SOZLESME.m.kaydedildi;
        if (j.belge_no && msg.indexOf(j.belge_no) < 0) msg += ' · ' + j.belge_no;
        if (j.uyari) msg += ' — ' + j.uyari;
        var kopya = j.kopya || null;
        if (!kopya && islem === 'hareket_iptal' && fd.get('duzelt') && f._kopya) kopya = f._kopya;

        if (kartlarSayfasi()) {
            if (islem === 'kart_sil') { yenileToastla(msg, SOZLESME.uc.kartlar); return; }
            if (islem === 'kart_kaydet' && !fd.get('id') && j.id) { yenileToastla(msg, SOZLESME.uc.kartlar + '?id=' + encodeURIComponent(j.id)); return; }
            toast(msg);
            tazele().then(function () { if (kopya) hareketAc(kopya.tur || 'tahsilat', null, kopya.cari_id, kopya); });
            return;
        }
        if (kopya && document.getElementById('crModalHareket')) { toast(msg); hareketAc(kopya.tur || 'tahsilat', null, kopya.cari_id, kopya); return; }
        yenileToastla(msg, guvenliYol(j.yonlendir) ? j.yonlendir : null);
    }

    /* Form dışı tek seferlik POST (menü eylemleri, tanım durum/sil, içe aktar) */
    function postAlanlar(alanlar, kaynak) {
        var fd = new FormData();
        fd.set(SOZLESME.alan.csrf, csrfAl(kaynak));
        Object.keys(alanlar).forEach(function (k) { if (alanlar[k] != null) fd.set(k, alanlar[k]); });
        return fetch(SOZLESME.uc.kaydet, { method: 'POST', body: fd, headers: SOZLESME.baslikJson, credentials: 'same-origin' }).then(yanitCoz);
    }
    function hizliIslem(btn, islem, ekstra) {
        if (btn.dataset.kilit === '1') return;
        var onay = btn.getAttribute('data-onay');
        if (onay && !window.confirm(onay)) return;
        var alanlar = {};
        alanlar[SOZLESME.alan.islem] = islem;
        ['id', 'tur', 'aktif'].forEach(function (k) { var v = btn.getAttribute('data-' + k); if (v != null) alanlar[k] = v; });
        if (alanlar.id == null && /^kart_/.test(islem)) { var d = detayId(); if (d) alanlar.id = d; }
        if (ekstra) Object.keys(ekstra).forEach(function (k) { alanlar[k] = ekstra[k]; });
        btn.dataset.kilit = '1'; btn.disabled = true;
        return postAlanlar(alanlar, btn).then(function (s) {
            btn.dataset.kilit = ''; btn.disabled = false;
            if (s.j && s.j.ok) {
                var msg = btn.getAttribute('data-ok') || s.j.mesaj || SOZLESME.m.kaydedildi;
                if (s.j.uyari) msg += ' — ' + s.j.uyari;
                if (kartlarSayfasi() && /^kart_(durum)$/.test(islem)) { toast(msg); return tazele(); }
                yenileToastla(msg, islem === 'kart_sil' ? SOZLESME.uc.kartlar : (guvenliYol(s.j.yonlendir) ? s.j.yonlendir : null));
            } else toast(yanitMesaji(s), { hata: true });
            return s;
        }).catch(function () { btn.dataset.kilit = ''; btn.disabled = false; toast(SOZLESME.m.ag, { hata: true }); });
    }

    /* ═════════ Hareket modalı ═════════ */
    function formSifirla(f) {
        f.reset(); hataTemizle(f);
        delete f.dataset.pbElle; delete f.dataset.kilit; f._kopya = null;
        var a = f.elements[SOZLESME.alan.istek]; if (a) a.value = yeniAnahtar();
        $$('[data-cari-secici]', f).forEach(function (w) {
            if (w.hasAttribute('data-kilitli')) return;
            var h = $('input[type=hidden][name]', w); if (h) h.value = '';
            var sc = $('.cari-secili', w); if (sc) sc.hidden = true;
            var cb = $('input[role=combobox]', w); if (cb) { var l = cb.closest('.pm-label'); if (l) l.hidden = false; cb.value = ''; }
        });
    }
    function doldur(f, v) {
        Object.keys(v || {}).forEach(function (k) {
            if (k === 'kayit_bilgi') return;
            var e = f.elements[k]; if (!e) return;
            var val = v[k] == null ? '' : v[k];
            if (e.type === 'checkbox') e.checked = (val === true || val === 1 || val === '1' || val === 'on');
            else e.value = String(val);
        });
    }
    function detayId() { var d = document.getElementById('crDetay'); return d ? (d.getAttribute('data-id') || '') : ''; }

    function turUygula(m) {
        if (!m) return;
        var f = $('form', m), r = $('input[name="tur"]:checked', m); if (!r || !f) return;
        var t = TUR[r.value] || TUR.tahsilat;
        var b = $('#crHareketBaslik', m); if (b) b.textContent = t.ad;
        var ip = $('.cari-tur-ipucu', m); if (ip) ip.innerHTML = t.ipucu;
        $$('[data-alan-grup]', m).forEach(function (el) {
            var g = el.getAttribute('data-alan-grup').split(/\s+/).filter(Boolean);
            var goster = g.some(function (x) { return t.gruplar.split(/\s+/).indexOf(x) > -1; });
            el.hidden = !goster;
            $$('input, select, textarea, button', el).forEach(function (x) { x.disabled = !goster; });
        });
        var z = $('[data-zorunlu-aciklama]', m); if (z) z.hidden = !t.acik;
        var ac = $('textarea[name="aciklama"]', m); if (ac) ac.required = t.acik;
        var isl = f.elements[SOZLESME.alan.islem]; if (isl) isl.value = r.value === 'virman' ? SOZLESME.islem.virman : SOZLESME.islem.hareket;
        $$('[data-cari-etiket]', m).forEach(function (x) { x.textContent = r.value === 'virman' ? 'Kaynak cari' : 'Cari'; });
        kurUygula(f); virmanOnizle(m);
    }
    function kurUygula(f) {
        var k = $('[data-kur]', f); if (!k) return;
        var pb = ($('input[name="para_birimi"]:checked', f) || {}).value;
        var tr = pb === 'TRY';
        k.hidden = tr;
        $$('input', k).forEach(function (i) { i.disabled = tr; if (tr) i.value = ''; });
    }
    function virmanOnizle(m) {
        var o = m && $('[data-virman-onizleme]', m); if (!o) return;
        var f = $('form', m); if (!f) return;
        if ((($('input[name="tur"]:checked', f) || {}).value) !== 'virman') return;
        var pb = ($('input[name="para_birimi"]:checked', f) || {}).value;
        var kc = cariBul((f.elements.cari_id || {}).value), hc = cariBul((f.elements.hedef_id || {}).value);
        var kb = kc && kc.bakiyeler && kc.bakiyeler[pb], hb = hc && hc.bakiyeler && hc.bakiyeler[pb];
        if (!kc || !hc || kb == null || hb == null || !isFinite(Number(kb)) || !isFinite(Number(hb))) return;   /* veri yoksa sunucu işaretlemesi kalır */
        var tk = tutarKurus((f.elements.tutar || {}).value);
        function yon(n) { return n > 0 ? 'b' : (n < 0 ? 'a' : 'd'); }
        function bk(n) {
            var s = document.createElement('span'); s.className = 'cari-bk cari-bk--' + yon(n);
            var a = document.createElement('span'); a.textContent = para(Math.abs(n), pb);
            var y = document.createElement('span'); y.className = 'cari-bk-yon'; y.setAttribute('aria-hidden', 'true'); y.textContent = yon(n).toUpperCase();
            s.appendChild(a); s.appendChild(y); return s;
        }
        function satir(ad, deger, soluk) {
            var d = document.createElement('div'); if (soluk) d.style.color = 'var(--muted)';
            var a = document.createElement('span'); a.textContent = ad; d.appendChild(a);
            if (typeof deger === 'string') { var s = document.createElement('span'); s.textContent = deger; d.appendChild(s); } else d.appendChild(deger);
            return d;
        }
        o.textContent = '';
        o.appendChild(satir('Kaynak (alacaklanır)', bk(Number(kb))));
        o.appendChild(satir('Hedef (borçlanır)', bk(Number(hb))));
        if (tk) {
            var s = document.createElement('span'); s.className = 'cari-bk-yon';
            var kon = Number(kb) - tk, hon = Number(hb) + tk;
            o.appendChild(satir('İşlem sonrası', 'Kaynak ' + para(Math.abs(kon), pb) + ' ' + yon(kon).toUpperCase() + ' · Hedef ' + para(Math.abs(hon), pb) + ' ' + yon(hon).toUpperCase()));
        } else o.appendChild(satir('İşlem sonrası (tutar girilince hesaplanır)', '—', true));
    }

    function hareketAc(tur, opener, cariId, dolgu) {
        var m = document.getElementById('crModalHareket'); if (!m) return;
        var f = $('form', m); if (!f) return;
        formSifirla(f);
        var r = $('input[name="tur"][value="' + tur + '"]', f); if (r) r.checked = true;
        $$('[data-cari-secici]', f).forEach(seciciHazirla);
        var id = cariId || detayId();
        var kaynakW = $('[data-cari-secici][data-alan="cari_id"]', f) || $('[data-cari-secici]', f);
        if (dolgu) { doldur(f, dolgu); if (dolgu.para_birimi || dolgu.pb) f.dataset.pbElle = '1'; }
        if (id && kaynakW) { var c = cariBul(id); if (c) { seciliYap(kaynakW, c); } }
        if (dolgu) {
            $$('[data-cari-secici]', f).forEach(function (w) { var v = seciciId(w); if (v && cariBul(v)) seciliYap(w, cariBul(v)); });
        }
        turUygula(m);
        if (dolgu) { var tt = f.elements.tutar; if (tt && dolgu.tutar != null) tt.value = String(dolgu.tutar); }
        modalAc('crModalHareket', opener);
    }

    /* Tutar/kur girişi: yalnız izinli karakterler (değer DEĞİŞTİRİLMEZ — sunucu ayrıştırır) */
    document.addEventListener('input', function (e) {
        var t = e.target, m = t.closest && t.closest('#crModalHareket'); if (!m) return;
        if (t.name === 'tutar' || t.name === 'kur') {
            var temiz = t.value.replace(/[^0-9.,]/g, '');
            if (temiz !== t.value) { var p = t.selectionStart - (t.value.length - temiz.length); t.value = temiz; try { t.setSelectionRange(p, p); } catch (x) { /* odak yok */ } }
            if (t.name === 'tutar') {
                var kotu = /,\d{3,}$/.test(t.value);
                t.classList.toggle('error', kotu); if (kotu) t.setAttribute('aria-invalid', 'true'); else if (!t.getAttribute('data-hata-id')) t.removeAttribute('aria-invalid');
                var ip = document.getElementById('crTutarIpucu'); if (ip) ip.classList.toggle('cari-tutar-uyari', kotu);
                virmanOnizle(m);
            }
        }
    });
    document.addEventListener('change', function (e) {
        var t = e.target, m = t.closest && t.closest('#crModalHareket'); if (!m) return;
        var f = $('form', m);
        if (t.name === 'tur') turUygula(m);
        if (t.name === 'para_birimi') { if (f) { f.dataset.pbElle = '1'; kurUygula(f); } virmanOnizle(m); }
    });

    /* ═════════ Kart modalı ═════════ */
    function kartVeriOku(opener, id) {
        var ham = opener && opener.getAttribute('data-kart');
        if (ham) { try { return JSON.parse(ham); } catch (x) { /* sıradakine geç */ } }
        var s = document.getElementById('crKartVeri');
        if (s) { try { var v = JSON.parse(s.textContent || 'null'); if (v && (!id || !v.id || String(v.id) === String(id))) return v; } catch (x) { /* sıradakine geç */ } }
        return null;
    }
    function kartAc(duzenle, opener) {
        var m = document.getElementById('crModalKart'); if (!m) return;
        var f = $('form', m); if (!f) return;
        var geriOdak = zorlaOdak;       /* fetch beklenirken sıfırlanır; menüden açıldıysa tetikleyiciye dön */
        function ac(v) {
            formSifirla(f);
            if (f.elements.id) f.elements.id.value = '';
            var b = $('#crKartBaslik', m); if (b) b.textContent = duzenle ? SOZLESME.m.duzenleCari : SOZLESME.m.yeniCari;
            var kb = $('.cari-kayit-bilgi', m); if (kb) { kb.hidden = !(duzenle && v && v.kayit_bilgi); if (!kb.hidden) kb.textContent = v.kayit_bilgi; }
            if (duzenle && v) { doldur(f, v); if (f.elements.id && v.id != null) f.elements.id.value = v.id; }
            modalAc('crModalKart', opener, geriOdak);
        }
        if (!duzenle) { ac(null); return; }
        var id = (opener && opener.getAttribute('data-id')) || detayId();
        var v = kartVeriOku(opener, id);
        if (v) { ac(v); return; }
        fetch(urlEkle(SOZLESME.uc.kartlar + '?' + SOZLESME.p.id + '=' + encodeURIComponent(id), (function () { var o = {}; o[SOZLESME.p.parca] = SOZLESME.p.kart; return o; })()),
              { headers: SOZLESME.baslikJson, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('http'); return r.json(); })
            .then(function (j) { var k = (j && j.kart) || j; if (!k || typeof k !== 'object') throw new Error('veri'); ac(k); })
            .catch(function () { toast(SOZLESME.m.yuklenemedi, { hata: true }); });
    }

    /* ═════════ İptal modalı ═════════ */
    function iptalAc(opener) {
        var m = document.getElementById('crModalIptal'); if (!m) return;
        var f = $('form', m); formSifirla(f);
        var id = opener.getAttribute('data-id'); if (f.elements.id && id) f.elements.id.value = id;
        var oz = $('.cari-iptal-ozet', m);
        if (oz) {
            oz.textContent = '';
            var ham = opener.getAttribute('data-ozet');
            if (ham) {
                ham.split('|').forEach(function (s, i) {
                    var e = document.createElement(i === 0 ? 'strong' : 'span'); e.textContent = s.trim(); oz.appendChild(e);
                });
            } else {
                var satir = opener.closest('tr, .cari-ekstre-satir, .cari-fis');
                var kopya = satir ? satir.cloneNode(true) : null;
                if (kopya) { $$('button, .cari-islem-hucre, .cari-fis-eylem', kopya).forEach(function (x) { x.parentNode && x.parentNode.removeChild(x); }); }
                var e2 = document.createElement('span'); e2.textContent = kopya ? kopya.textContent.replace(/\s+/g, ' ').trim().slice(0, 200) : ''; oz.appendChild(e2);
            }
        }
        var k = opener.getAttribute('data-kopya');
        if (k) { try { f._kopya = JSON.parse(k); } catch (x) { f._kopya = null; } }
        modalAc('crModalIptal', opener);
    }

    /* ═════════ Tıklama dağıtıcı (olay delegasyonu) ═════════ */
    document.addEventListener('click', function (e) {
        var t = e.target; if (!t.closest) return;

        var kp = t.closest('[data-cr-kapat]');
        if (kp && kp.closest('.pm-overlay')) { e.preventDefault(); modalKapat(); return; }

        var mg = t.closest('[data-cari-menu-ac]');
        if (mg) { e.preventDefault(); menuAc(mg); return; }

        var menuOge = t.closest('.cari-menu-liste [role=menuitem], .cari-menu-liste a');
        var menuOrtu = t.closest('.cari-menu-ortu');
        var geriOdak = null;
        if (menuAcik && (menuOge || menuOrtu)) { geriOdak = menuAcik.dugme; kapatMenuler(); if (menuOrtu) return; }

        var tn = t.closest('[data-cr-islem]');
        if (tn) { e.preventDefault(); hizliIslem(tn, tn.getAttribute('data-cr-islem')); return; }

        var mo = t.closest('[data-cr-modal]');
        if (mo) {
            var k = mo.getAttribute('data-cr-modal');
            if (k === 'tanim-sil' && mo.getAttribute('data-id')) { e.preventDefault(); hizliIslem(mo, SOZLESME.islem.tanimSil); return; }
            if (mo.disabled || mo.getAttribute('aria-disabled') === 'true') return;
            e.preventDefault();
            zorlaOdak = geriOdak;     /* menü öğesinden açılırsa odak menü tetikleyicisine döner */
            if (k === 'hareket') hareketAc(mo.getAttribute('data-tur') || 'tahsilat', mo, mo.getAttribute('data-cari'));
            else if (k === 'kart') kartAc(mo.getAttribute('data-duzenle') === '1', mo);
            else if (k === 'iptal') iptalAc(mo);
            zorlaOdak = null;
            return;
        }

        var ice = t.closest('[data-ice-aktar]');
        if (ice) { e.preventDefault(); hizliIslem(ice, SOZLESME.islem.iceAktar); return; }

        var kop = t.closest('[data-kopyala]');
        if (kop) { e.preventDefault(); kopyala(kop); return; }

        var tek = t.closest('[data-cr-tekrar]');
        if (tek && tekrarFn) { e.preventDefault(); tekrarFn(); return; }
    });

    function kopyala(b) {
        var v = b.getAttribute('data-kopyala') || '';
        if (!v) { var s = b.parentNode && $('span', b.parentNode); v = s ? s.textContent.trim() : ''; }
        if (!v) return;
        var tamam = function () { toast(SOZLESME.m.kopyalandi); };
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(v).then(tamam, yedek); } else yedek();
        function yedek() {
            var ta = document.createElement('textarea'); ta.value = v; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { if (document.execCommand('copy')) tamam(); } catch (x) { /* kopyalanamadı */ }
            document.body.removeChild(ta);
        }
    }

    /* ═════════ Klavye: Esc, Ctrl+Enter, odak tuzağı, kısayollar ═════════ */
    document.addEventListener('keydown', function (e) {
        if (acik) {
            if (e.key === 'Escape') { e.preventDefault(); modalKapat(); return; }
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); var f = $('form', acik); if (f) f.requestSubmit(); return; }
            if (e.key === 'Tab') {
                var o = $$(ODAK, acik).filter(gorunur); if (!o.length) return;
                var a = o[0], z = o[o.length - 1];
                if (e.shiftKey && (document.activeElement === a || !acik.contains(document.activeElement))) { e.preventDefault(); z.focus(); }
                else if (!e.shiftKey && (document.activeElement === z || !acik.contains(document.activeElement))) { e.preventDefault(); a.focus(); }
            }
            return;
        }
        if (filtreAcik && e.key === 'Escape') { e.preventDefault(); filtreKapat(); return; }
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        var ae = document.activeElement, yaz = ae && /^(INPUT|TEXTAREA|SELECT)$/.test(ae.tagName || '');
        if (yaz || (ae && ae.isContentEditable)) return;
        if (!genis()) return;
        if (e.key === '/') { var ar = document.getElementById('crArama'); if (ar) { e.preventDefault(); ar.focus(); ar.select(); } return; }
        var kk = e.key.length === 1 ? e.key.toLowerCase() : '';
        if (KISAYOL_TUR[kk] && kartlarSayfasi() && detayId()) {
            var dg = $('#crDetay .cari-islemler [data-cr-modal="hareket"][data-tur="' + KISAYOL_TUR[kk] + '"]');
            if (kk === 'd') dg = $('#crDetay .cari-islemler [data-cari-menu-ac]');
            if (dg && !dg.disabled) { e.preventDefault(); if (kk === 'd') hareketAc('borc_dekont', dg, detayId()); else hareketAc(KISAYOL_TUR[kk], dg, detayId()); }
        } else if (kk === 'n' && $('[data-cr-modal="kart"]:not([data-duzenle])')) { e.preventDefault(); kartAc(false, $('[data-cr-modal="kart"]:not([data-duzenle])')); }
    });

    /* ═════════ Kartlar: canlı filtre, master-detail, ekstre ═════════ */
    var liste = document.getElementById('crListe');
    var ana = $('.cari-ana');
    var tekrarFn = null, detaySeq = 0, ekstreSeq = 0, bosDetay = null;

    function filtrele() {
        if (!liste) return;
        var ar = document.getElementById('crArama');
        var q = norm(ar ? ar.value.trim() : ''), toks = q.split(/\s+/).filter(Boolean), qs = q.replace(/[\s.\-]/g, '');
        var bc = $('.cari-cip[data-filtre="bakiye"][aria-pressed="true"]'), bk = bc ? bc.getAttribute('data-deger') : '';
        var tip = ($('select[data-filtre="tip"]') || {}).value || '', grup = ($('select[data-filtre="grup"]') || {}).value || '';
        var n = 0;
        $$('.cari-kart', liste).forEach(function (a) {
            if (a._n == null) { a._n = norm(a.getAttribute('data-ara') || a.textContent); a._ns = a._n.replace(/[\s.\-]/g, ''); }
            var ok = !toks.length || toks.every(function (t) { return a._n.indexOf(t) > -1; }) || (qs.length > 2 && a._ns.indexOf(qs) > -1);
            var pasif = a.getAttribute('data-pasif') === '1';
            if (bk === 'pasif') ok = ok && pasif;
            else { ok = ok && !pasif; if (bk) ok = ok && (a.getAttribute('data-bakiye') || '').split(/\s+/).indexOf(bk) > -1; }
            if (tip) ok = ok && a.getAttribute('data-tip') === tip;
            if (grup) ok = ok && a.getAttribute('data-grup') === grup;
            if (a.parentNode) a.parentNode.hidden = !ok;
            if (ok) n++;
        });
        var yok = document.getElementById('crSonucYok'); if (yok) yok.hidden = n > 0;
        var sy = document.getElementById('crSayi'); if (sy) sy.textContent = n + ' cari';
    }
    var aramaZ = null;
    document.addEventListener('input', function (e) {
        if (e.target && e.target.id === 'crArama') { clearTimeout(aramaZ); aramaZ = setTimeout(filtrele, 120); }
    });
    document.addEventListener('change', function (e) { if (e.target.matches && e.target.matches('select[data-filtre]')) filtrele(); });
    document.addEventListener('click', function (e) {
        var c = e.target.closest && e.target.closest('.cari-cip[data-filtre]'); if (!c) return;
        $$('.cari-cip[data-filtre="' + c.getAttribute('data-filtre') + '"]').forEach(function (x) { x.setAttribute('aria-pressed', String(x === c)); });
        filtrele();
    });

    /* Liste klavyesi */
    document.addEventListener('keydown', function (e) {
        if (!liste || acik) return;
        var ar = document.getElementById('crArama');
        var gor = function () { return $$('.cari-kart', liste).filter(function (a) { return !a.parentNode.hidden; }); };
        if (e.target === ar) {
            if (e.key === 'ArrowDown') { var g = gor(); if (g.length) { e.preventDefault(); g[0].focus(); } }
            else if (e.key === 'Escape' && ar.value) { e.preventDefault(); ar.value = ''; filtrele(); }
            else if (e.key === 'Enter') { var g2 = gor(); if (g2.length === 1) { e.preventDefault(); g2[0].click(); } }
            return;
        }
        var g3 = gor(), i = g3.indexOf(document.activeElement); if (i < 0) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); (g3[i + 1] || g3[i]).focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); if (i === 0 && ar) ar.focus(); else g3[i - 1].focus(); }
    });

    function parcaCoz(html, sel) {
        var t = document.createElement('template'); t.innerHTML = html;
        var k = t.content.querySelector(sel);
        if (k) return { ic: k.innerHTML, k: k };
        if (/<html[\s>]|class="sidebar|id="bottomnav"/i.test(html)) return null;     /* tam sayfa geldi: parça değil */
        return { ic: html, k: null };
    }
    function yukleniyor(el, ac) {
        if (!el) return;
        if (ac) { el._yz = setTimeout(function () { el.classList.add('cari-yukleniyor'); el.setAttribute('aria-busy', 'true'); }, 150); }
        else { clearTimeout(el._yz); el.classList.remove('cari-yukleniyor'); el.removeAttribute('aria-busy'); }
    }
    function yukleHata(el, fn) {
        $$('.cari-yukle-hata', el).forEach(function (x) { x.parentNode.removeChild(x); });
        var d = document.createElement('div'); d.className = 'flash flash-error cari-yukle-hata'; d.setAttribute('role', 'alert');
        var s = document.createElement('span'); s.textContent = SOZLESME.m.yuklenemedi;
        var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-sm'; b.setAttribute('data-cr-tekrar', '1'); b.textContent = SOZLESME.m.tekrar;
        d.appendChild(s); d.appendChild(b); el.insertBefore(d, el.firstChild);
        tekrarFn = fn;
    }
    function parcaGetir(url) {
        return fetch(url, { headers: SOZLESME.baslikParca, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('http' + r.status); return r.text(); });
    }
    function seciliKartIsaretle(id) {
        $$('.cari-kart', liste).forEach(function (x) { if (String(x.getAttribute('data-id')) === String(id)) x.setAttribute('aria-current', 'true'); else x.removeAttribute('aria-current'); });
    }

    function detayYukle(id, href, push) {
        var det = document.getElementById('crDetay'); if (!det) return;
        var seq = ++detaySeq;
        var o = {}; o[SOZLESME.p.parca] = SOZLESME.p.detay;
        var url = urlEkle(href, o);
        yukleniyor(det, true);
        parcaGetir(url).then(function (html) {
            if (seq !== detaySeq) return;
            var p = parcaCoz(html, '#crDetay'); if (!p) throw new Error('parca');
            yukleniyor(det, false);
            det.innerHTML = p.ic; det.setAttribute('data-id', id);
            if (p.k && p.k.getAttribute('aria-label')) det.setAttribute('aria-label', p.k.getAttribute('aria-label'));
            seciliKartIsaretle(id);
            if (ana) ana.classList.add('cari-ana--detay');
            if (push) { try { history.pushState({ cariId: id }, '', href); } catch (x) { /* kritik değil */ } }
            if (!genis()) window.scrollTo(0, 0);
            det.setAttribute('tabindex', '-1'); try { det.focus({ preventScroll: true }); } catch (x) { /* odak yok */ }
            tekrarFn = null;
        }).catch(function () {
            if (seq !== detaySeq) return;
            yukleniyor(det, false);
            yukleHata(det, function () { detayYukle(id, href, push); });
            if (ana) ana.classList.add('cari-ana--detay');
        });
    }

    document.addEventListener('click', function (e) {
        if (!liste || e.defaultPrevented) return;
        var a = e.target.closest && e.target.closest('.cari-kart');
        if (!a || !liste.contains(a)) return;
        if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || a.target === '_blank') return;
        if (!document.getElementById('crDetay')) return;
        e.preventDefault();
        var id = a.getAttribute('data-id');
        if (String(id) === String(detayId()) && !$('#crDetay .cari-yukle-hata')) {
            seciliKartIsaretle(id); if (ana) ana.classList.add('cari-ana--detay'); if (!genis()) window.scrollTo(0, 0); return;
        }
        detayYukle(id, a.getAttribute('href'), true);
    });
    document.addEventListener('click', function (e) {
        var g = e.target.closest && e.target.closest('.cari-geri'); if (!g || !ana || e.defaultPrevented) return;
        e.preventDefault();
        ana.classList.remove('cari-ana--detay');
        if (history.state && history.state.cariId) history.back();
        else { try { history.replaceState({}, '', location.pathname); } catch (x) { /* kritik değil */ } }
        var ak = liste && $('.cari-kart[aria-current="true"]', liste); if (ak) ak.focus();
    });
    window.addEventListener('popstate', function () {
        if (!document.getElementById('crDetay') || !ana) return;
        var id = new URLSearchParams(location.search).get(SOZLESME.p.id);
        if (id) detayYukle(id, location.pathname.replace(/^.*\//, '') + location.search, false);
        else {
            ana.classList.remove('cari-ana--detay'); seciliKartIsaretle('');
            if (genis() && bosDetay != null) { var det = document.getElementById('crDetay'); det.innerHTML = bosDetay; det.removeAttribute('data-id'); }
        }
    });

    /* Alt sekmeler (Ekstre | Bilgiler) */
    function sekmeSec(t, odak) {
        var grup = t.parentNode;
        $$('[role=tab]', grup).forEach(function (x) { x.setAttribute('aria-selected', String(x === t)); x.setAttribute('tabindex', x === t ? '0' : '-1'); });
        $$('[role=tabpanel]', grup.parentNode).forEach(function (p) { p.hidden = p.id !== t.getAttribute('aria-controls'); });
        if (odak) t.focus();
    }
    document.addEventListener('click', function (e) {
        var t = e.target.closest && e.target.closest('.cari-altsekme'); if (!t) return;
        sekmeSec(t);
        try {
            var u = new URL(location.href);
            if (t.id === 'crSekmeBilgi') u.searchParams.set(SOZLESME.p.sekme, 'bilgiler'); else u.searchParams.delete(SOZLESME.p.sekme);
            history.replaceState(history.state, '', u.pathname.replace(/^.*\//, '') + u.search);
        } catch (x) { /* kritik değil */ }
    });
    document.addEventListener('keydown', function (e) {
        var t = e.target.closest && e.target.closest('.cari-altsekme, .cari-tanim-nav-oge'); if (!t) return;
        var yatay = t.classList.contains('cari-altsekme') || matchMedia('(max-width:767px)').matches;
        var ileri = yatay ? 'ArrowRight' : 'ArrowDown', geri = yatay ? 'ArrowLeft' : 'ArrowUp';
        if (e.key !== ileri && e.key !== geri && e.key !== 'ArrowRight' && e.key !== 'ArrowLeft' && e.key !== 'ArrowDown' && e.key !== 'ArrowUp' && e.key !== 'Home' && e.key !== 'End') return;
        var kardes = $$('[role=tab]', t.parentNode), i = kardes.indexOf(t), n;
        if (e.key === 'Home') n = kardes[0]; else if (e.key === 'End') n = kardes[kardes.length - 1];
        else n = kardes[(i + (/Right|Down/.test(e.key) ? 1 : -1) + kardes.length) % kardes.length];
        e.preventDefault(); if (t.classList.contains('cari-altsekme')) sekmeSec(n, true); else tanimSekme(n, true);
    });

    /* Ekstre filtreleri */
    function ekstreParams(form) {
        var p = new URLSearchParams(new FormData(form));
        var c = $('.cari-cip[data-donem][aria-pressed="true"]', form); if (c) p.set(SOZLESME.p.donem, donemAdi(c));
        return p;
    }
    function ekstreYukle(form) {
        var panel = document.getElementById('crPanelEkstre'); if (!panel) return;
        var seq = ++ekstreSeq;
        var p = ekstreParams(form); p.set(SOZLESME.p.parca, SOZLESME.p.ekstre);
        var url = (form.getAttribute('action') || SOZLESME.uc.kartlar).replace(/^.*\//, '') + '?' + p.toString();
        var ae = document.activeElement, imza = null;
        if (ae && panel.contains(ae)) imza = ae.getAttribute('data-donem') ? '[data-donem="' + ae.getAttribute('data-donem') + '"]'
            : (ae.name ? 'input[name="' + ae.name + '"][value="' + (ae.value || '').replace(/"/g, '') + '"], select[name="' + ae.name + '"], input[type=checkbox][name="' + ae.name + '"]' : null);
        yukleniyor(panel, true);
        parcaGetir(url).then(function (html) {
            if (seq !== ekstreSeq) return;
            var pc = parcaCoz(html, '#crPanelEkstre'); if (!pc) throw new Error('parca');
            yukleniyor(panel, false);
            panel.innerHTML = pc.ic; tekrarFn = null;
            if (imza) { var y = $(imza, panel); if (y) { try { y.focus({ preventScroll: true }); } catch (x) { y.focus(); } } }
        }).catch(function () {
            if (seq !== ekstreSeq) return;
            yukleniyor(panel, false); yukleHata(panel, function () { ekstreYukle(form); });
        });
    }
    function ekstreFormu(el) { return el && el.closest ? (el.closest('#crEkstreForm') || (el.form && el.form.id === 'crEkstreForm' ? el.form : null)) : null; }
    document.addEventListener('click', function (e) {
        var c = e.target.closest && e.target.closest('.cari-cip[data-donem], #crEkstreForm .cari-cip'); if (!c) return;
        var form = ekstreFormu(c); if (!form) return;
        var d = donemAdi(c);
        $$('.cari-cip', c.parentNode).forEach(function (x) { x.setAttribute('aria-pressed', String(x === c)); });
        var oz = $('.cari-ozel-tarih', form); if (oz) oz.hidden = d !== 'ozel';
        if (d === 'ozel') { var bi = $('input[name="bas"]', form); if (bi) bi.focus(); return; }
        var t = donemTarih(d); if (!t) return;
        if (form.elements.bas) form.elements.bas.value = t[0]; if (form.elements.bit) form.elements.bit.value = t[1];
        ekstreYukle(form);
    });
    document.addEventListener('change', function (e) {
        var form = ekstreFormu(e.target); if (!form) return;
        var n = e.target.name;
        if (n === 'pb' || n === 'tur' || n === 'iptal' || ((n === 'bas' || n === 'bit') && form.elements.bas.value && form.elements.bit.value)) ekstreYukle(form);
    });
    document.addEventListener('submit', function (e) {
        var form = e.target; if (form.id !== 'crEkstreForm' || !document.getElementById('crPanelEkstre')) return;
        e.preventDefault(); ekstreYukle(form);
    });

    /* Mutasyon sonrası: liste satırları + özet + detay'ı tek tam-sayfa GET ile tazele */
    function tazele() {
        if (!liste) return Promise.resolve();
        var id = detayId(), p = new URLSearchParams();
        var form = document.getElementById('crEkstreForm');
        if (form) ekstreParams(form).forEach(function (v, k) { p.set(k, v); });
        if (id) p.set(SOZLESME.p.id, id);
        var sb = $('#crSekmeBilgi'); if (sb && sb.getAttribute('aria-selected') === 'true') p.set(SOZLESME.p.sekme, 'bilgiler');
        var qs = p.toString();
        var det = document.getElementById('crDetay');
        yukleniyor(det, true);
        return fetch(SOZLESME.uc.kartlar + (qs ? '?' + qs : ''), { headers: SOZLESME.baslikSayfa, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('http'); return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var yl = doc.getElementById('crListe'); if (!yl) throw new Error('liste');
                liste.innerHTML = yl.innerHTML;
                var yo = $('.cari-ozet', doc), eo = $('.cari-ozet'); if (yo && eo) eo.innerHTML = yo.innerHTML;
                $$('.cari-cip[data-filtre][data-deger]', doc).forEach(function (yc) {
                    var ec = $('.cari-cip[data-filtre="' + yc.getAttribute('data-filtre') + '"][data-deger="' + yc.getAttribute('data-deger') + '"]');
                    var ys = $('.cari-cip-say', yc), es = ec && $('.cari-cip-say', ec); if (ys && es) es.textContent = ys.textContent;
                });
                var yv = doc.getElementById('crCariVeri'), ev = document.getElementById('crCariVeri');
                if (yv && ev) { ev.textContent = yv.textContent; veriOku(); }
                var yk = $('#crModalKart input[name="kod"]', doc), ek = $('#crModalKart input[name="kod"]');
                if (yk && ek) ek.defaultValue = yk.defaultValue;
                var yd = doc.getElementById('crDetay');
                if (yd && det) { det.innerHTML = yd.innerHTML; if (yd.getAttribute('data-id')) det.setAttribute('data-id', yd.getAttribute('data-id')); }
                yukleniyor(det, false);
                seciliKartIsaretle(id); filtrele();
                if (!document.activeElement || document.activeElement === document.body) odakGeri();
            })
            .catch(function () {
                yukleniyor(det, false);
                toast(SOZLESME.m.yuklenemedi, { hata: true });
            });
    }

    /* ═════════ Fişler / Dekontlar: dönem çipleri + mobil filtre sayfası ═════════ */
    var filtreAcik = false, filtreForm = $('.cari-filtre'), filtreDugme = $('.cari-filtre-ac');
    function filtreAc() {
        if (!filtreForm || !filtreDugme) return;
        filtreForm.classList.add('acik'); filtreDugme.setAttribute('aria-expanded', 'true');
        if (!filtreAcik) { filtreAcik = true; govdeKilit(true); }
        var ilk = $('input:not([type=hidden]), select', filtreForm); if (ilk && window.matchMedia('(max-width:767px)').matches) setTimeout(function () { ilk.focus(); }, 30);
    }
    function filtreKapat() {
        if (!filtreForm || !filtreDugme) return;
        filtreForm.classList.remove('acik'); filtreDugme.setAttribute('aria-expanded', 'false');
        if (filtreAcik) { filtreAcik = false; govdeKilit(false); }
        filtreDugme.focus();
    }
    document.addEventListener('click', function (e) {
        var t = e.target; if (!t.closest) return;
        if (t.closest('.cari-filtre-ac')) { e.preventDefault(); filtreAcik ? filtreKapat() : filtreAc(); return; }
        if (t.closest('.cari-filtre-ortu') || t.closest('[data-filtre-kapat]')) { e.preventDefault(); if (filtreAcik) filtreKapat(); return; }
        /* Filtre formundaki dönem çipleri (data-donem yoksa metinden çıkarılır) */
        var c = t.closest('.cari-filtre-cipler .cari-cip'); if (!c) return;
        var d = donemAdi(c), tar = donemTarih(d), fm = c.closest('form'); if (!tar || !fm) return;
        $$('.cari-cip', c.parentNode).forEach(function (x) { x.setAttribute('aria-pressed', String(x === c)); });
        if (fm.elements.bas) fm.elements.bas.value = tar[0]; if (fm.elements.bit) fm.elements.bit.value = tar[1];
    });
    window.addEventListener('resize', function () { if (filtreAcik && window.matchMedia('(min-width:768px)').matches) { filtreForm.classList.remove('acik'); filtreAcik = false; govdeKilit(false); if (filtreDugme) filtreDugme.setAttribute('aria-expanded', 'false'); } });

    /* ═════════ Tanımlar ═════════ */
    function tanimSekme(t, odak) {
        var grup = t.parentNode;
        $$('.cari-tanim-nav-oge', grup).forEach(function (x) { x.setAttribute('aria-selected', String(x === t)); x.setAttribute('tabindex', x === t ? '0' : '-1'); });
        $$('.cari-tanim-govde').forEach(function (p) { p.hidden = p.id !== t.getAttribute('aria-controls'); });
        try { history.replaceState(history.state, '', location.pathname.replace(/^.*\//, '') + location.search + '#' + (t.getAttribute('data-sekme') || t.id)); } catch (x) { /* kritik değil */ }
        if (odak) t.focus();
    }
    document.addEventListener('click', function (e) {
        var t = e.target; if (!t.closest) return;
        var nv = t.closest('.cari-tanim-nav-oge'); if (nv) { tanimSekme(nv); return; }
        var d = t.closest('[data-tanim-duzen]');
        if (d) {
            var s = d.closest('.cari-tanim-satir'), du = s && s.nextElementSibling;
            if (du && du.classList.contains('cari-tanim-satir--duzen')) { s.hidden = true; du.hidden = false; var i = $('input', du); if (i) i.focus(); }
            return;
        }
        var v = t.closest('[data-tanim-vazgec]');
        if (v) {
            var du2 = v.closest('.cari-tanim-satir--duzen'); if (!du2) return;
            $$('input', du2).forEach(function (x) { x.value = x.defaultValue; }); hataTemizle(du2);
            du2.hidden = true; var on = du2.previousElementSibling; if (on) { on.hidden = false; var b = $('[data-tanim-duzen]', on); if (b) b.focus(); }
            return;
        }
        var k = t.closest('[data-tanim-kaydet]');
        if (k) { e.preventDefault(); tanimSatirKaydet(k); }
    });
    function tanimSatirKaydet(b) {
        var satir = b.closest('.cari-tanim-satir--duzen'); if (!satir || b.dataset.kilit === '1') return;
        hataTemizle(satir);
        var ad = $('input[name="ad"]', satir) || $('input[type=text]', satir), sira = $('input[name="sira"]', satir) || $('input[type=number]', satir);
        if (!ad || !ad.value.trim()) { alanHata(satir, ad ? ad.name || 'ad' : 'ad', SOZLESME.m.zorunlu); if (ad) { ad.classList.add('error'); ad.focus(); } return; }
        var govde = satir.closest('[data-tur]');
        var alanlar = { id: satir.getAttribute('data-id') || b.getAttribute('data-id') || '', tur: (govde && govde.getAttribute('data-tur')) || satir.getAttribute('data-tur') || b.getAttribute('data-tur') || '', ad: ad.value.trim(), sira: sira ? sira.value : '' };
        alanlar[SOZLESME.alan.islem] = SOZLESME.islem.tanimKaydet;
        b.dataset.kilit = '1'; b.disabled = true;
        postAlanlar(alanlar, b).then(function (s) {
            b.dataset.kilit = ''; b.disabled = false;
            if (s.j && s.j.ok) yenileToastla(s.j.mesaj || 'Tanım güncellendi');
            else if (s.j && (s.j.alanlar || s.j.hata)) {
                if (s.j.alanlar && s.j.alanlar.ad) { ad.classList.add('error'); ad.setAttribute('aria-invalid', 'true'); var y = ad.closest('.pm-label') || ad.parentNode; hataEkle(y, ad, String(s.j.alanlar.ad), 'ad'); ad.focus(); }
                else toast(String(s.j.hata || yanitMesaji(s)), { hata: true });
            } else toast(yanitMesaji(s), { hata: true });
        }).catch(function () { b.dataset.kilit = ''; b.disabled = false; toast(SOZLESME.m.ag, { hata: true }); });
    }
    document.addEventListener('change', function (e) {
        var sw = e.target; if (!sw.matches || !sw.matches('input[role=switch][data-id]')) return;
        var alanlar = { id: sw.getAttribute('data-id'), aktif: sw.checked ? '1' : '0' };
        alanlar[SOZLESME.alan.islem] = SOZLESME.islem.tanimDurum;
        var satir = sw.closest('.cari-tanim-satir');
        function uygula(ac) { sw.checked = ac; if (satir) satir.classList.toggle('cari-tanim-satir--pasif', !ac); }
        sw.disabled = true;
        postAlanlar(alanlar, sw).then(function (s) {
            sw.disabled = false;
            if (s.j && s.j.ok) {
                uygula(sw.checked);
                toast(sw.checked ? 'Etkinleştirildi' : 'Pasifleştirildi', { eylem: { ad: SOZLESME.m.gerial, fn: function () {
                    var geri = { id: alanlar.id, aktif: sw.checked ? '0' : '1' }; geri[SOZLESME.alan.islem] = SOZLESME.islem.tanimDurum;
                    postAlanlar(geri, sw).then(function (g) { if (g.j && g.j.ok) uygula(geri.aktif === '1'); else toast(yanitMesaji(g), { hata: true }); });
                } } });
            } else { uygula(!sw.checked); toast(yanitMesaji(s), { hata: true }); }
        }).catch(function () { sw.disabled = false; uygula(!sw.checked); toast(SOZLESME.m.ag, { hata: true }); });
    });

    /* ═════════ Başlangıç ═════════ */
    function basla() {
        veriOku();
        $$('[data-cari-secici]').forEach(seciciHazirla);
        var m = document.getElementById('crModalHareket'); if (m) turUygula(m);
        var tk = depoOku(SOZLESME.toastAnahtar); if (tk) { depoYaz(SOZLESME.toastAnahtar, null); toast(tk); }
        var det = document.getElementById('crDetay'); if (det) bosDetay = det.getAttribute('data-id') ? null : det.innerHTML;
        if (liste) {
            filtrele();
            var id0 = detayId();
            if (id0) { try { history.replaceState({ cariId: id0 }, '', location.href); } catch (x) { /* kritik değil */ } }
        }
        /* Sunucudan açık gelen modal (yeni=… parametresi ya da hidden yok) */
        $$('.pm-overlay').forEach(function (o) {
            if (!o.hidden && o.id && /^crModal/.test(o.id)) {
                o.hidden = true;
                if (o.id === 'crModalHareket') hareketAc((($('input[name="tur"]:checked', o) || {}).value) || 'tahsilat', null);
                else modalAc(o.id, null);
            }
        });
        var q = new URLSearchParams(location.search), yeni = q.get('yeni');
        if (yeni) {
            if (document.getElementById('crModalHareket') && TUR[yeni]) hareketAc(yeni, null, q.get('cari'));
            else if (document.getElementById('crModalKart') && yeni === '1') kartAc(false, null);
            try { q.delete('yeni'); q.delete('cari'); var s = q.toString(); history.replaceState(history.state, '', location.pathname.replace(/^.*\//, '') + (s ? '?' + s : '') + location.hash); } catch (x) { /* kritik değil */ }
        }
        /* Tanımlar: #grup / #odeme_sekli / #ice_aktar derin bağı */
        var h = (location.hash || '').slice(1);
        if (h) {
            var hedef = $$('.cari-tanim-nav-oge').filter(function (t) { return t.id === h || t.getAttribute('data-sekme') === h || t.getAttribute('aria-controls') === h; })[0];
            if (hedef) { var ml = hedef.parentNode; $$('.cari-tanim-nav-oge', ml).forEach(function (x) { x.setAttribute('aria-selected', String(x === hedef)); x.setAttribute('tabindex', x === hedef ? '0' : '-1'); }); $$('.cari-tanim-govde').forEach(function (p) { p.hidden = p.id !== hedef.getAttribute('aria-controls'); }); }
        }
        $$('.cari-tanim-nav-oge').forEach(function (t) { t.setAttribute('tabindex', t.getAttribute('aria-selected') === 'true' ? '0' : '-1'); });
        $$('.cari-altsekme').forEach(function (t) { t.setAttribute('tabindex', t.getAttribute('aria-selected') === 'true' ? '0' : '-1'); });
    }
    basla();
})();
