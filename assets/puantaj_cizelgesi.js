/* puantaj_cizelgesi.js — Aylık Puantaj Çizelgesi (v552 + v553). Küçük ergonomi:
   ay / "pasifleri göster" değişince formu gönder, mobilde gösterge aç-kapa,
   bu aysa bugünün sütununu ızgarada görünür yap. Hesap/filtre sunucudadır.
   v553: elle gün işareti — hücreye tıkla/dokun → küçük menü (masaüstü) / alt sayfa (<768px);
   seçim fetch POST (puantaj_isaret_kaydet.php, CSRF) → sayfa aynı ay/sekme/arama ile yenilenir
   (toplamlar sunucuda yeniden hesaplanır). <form> KULLANILMAZ. Yetki/tablo yoksa hücrelerde
   data-pi YOK → hiçbir etkileşim bağlanmaz. */
(function () {
    'use strict';
    var form = document.getElementById('pcForm');
    if (form) {
        form.querySelectorAll('[data-oto]').forEach(function (el) {
            el.addEventListener('change', function () {
                if (el.type === 'month' && !/^\d{4}-(0[1-9]|1[0-2])$/.test(el.value)) return;   // yarım yazılmış ay gönderilmez
                form.submit();
            });
        });
    }
    var lej = document.getElementById('pcLejant');
    if (lej) {
        var dugme = lej.querySelector('.pc-lejant-dugme');
        if (dugme) dugme.addEventListener('click', function () {
            var acik = lej.classList.toggle('acik');
            dugme.setAttribute('aria-expanded', acik ? 'true' : 'false');
        });
    }
    var wrap = document.getElementById('pcGridWrap');
    var bugun = wrap && wrap.querySelector('th[data-bugun]');
    if (wrap && bugun && wrap.offsetParent !== null) {
        wrap.scrollLeft = Math.max(0, bugun.offsetLeft - wrap.clientWidth / 2);
    }

    /* ── Elle gün işareti (v553) ── */
    var sayfa = document.querySelector('.pc-page[data-pi-url]');
    var menu = document.getElementById('piMenu');
    var ovl = document.getElementById('piOvl');
    if (!sayfa || !menu || !ovl) return;
    var URL_ = sayfa.getAttribute('data-pi-url');
    var CSRF = sayfa.getAttribute('data-pi-csrf') || '';
    var baslik = document.getElementById('piBaslik');
    var hataEl = document.getElementById('piHata');
    var HAFIZA = 'asya_pc_isaret_durum';
    var hedef = null;      // açık menünün hücresi
    var mesgul = false;

    function mobil() { return window.matchMedia('(max-width: 767px)').matches; }
    function depoyaz(v) { try { sessionStorage.setItem(HAFIZA, JSON.stringify(v)); } catch (e) { /* özel pencere: sorun değil */ } }
    function depooku() { try { var v = sessionStorage.getItem(HAFIZA); sessionStorage.removeItem(HAFIZA); return v ? JSON.parse(v) : null; } catch (e) { return null; } }

    function kisiAdi(hucre) {
        var det = hucre.closest('details.pc-kisi');
        var el = det ? det.querySelector('.pc-ad-ad') : null;
        if (!el) { var tr = hucre.closest('tr'); el = tr ? tr.querySelector('th .pc-ad-ad') : null; }
        return el ? el.textContent.trim() : '';
    }
    function gunMetni(tarih) {
        var p = tarih.split('-');
        var d = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
        try { return d.toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', weekday: 'long' }); } catch (e) { return tarih; }
    }
    function hata(m) { hataEl.textContent = m || ''; hataEl.hidden = !m; }

    function kapat() {
        if (menu.hidden) return;
        menu.hidden = true; ovl.hidden = true; hata('');
        menu.classList.remove('pi-yukleniyor');
        if (hedef) { hedef.classList.remove('pi-sec'); try { hedef.focus && hedef.focus({ preventScroll: true }); } catch (e) { /* */ } }
        hedef = null; mesgul = false;
    }
    function konumla(hucre) {
        if (mobil()) { menu.style.left = menu.style.top = menu.style.right = ''; return; }
        var r = hucre.getBoundingClientRect();
        var w = menu.offsetWidth, h = menu.offsetHeight;
        var x = r.right + 6;
        if (x + w > window.innerWidth - 8) x = Math.max(8, r.left - w - 6);
        var y = Math.min(Math.max(8, r.top), Math.max(8, window.innerHeight - h - 8));
        menu.style.left = x + 'px'; menu.style.top = y + 'px';
    }
    function ac(hucre) {
        if (hedef) hedef.classList.remove('pi-sec');
        hedef = hucre; hucre.classList.add('pi-sec');
        hata('');
        var kod = hucre.getAttribute('data-kod') || '';
        baslik.textContent = kisiAdi(hucre) + ' · ' + gunMetni(hucre.getAttribute('data-tarih') || '');
        menu.querySelectorAll('[data-pi-kod]').forEach(function (b) {
            var k = b.getAttribute('data-pi-kod');
            if (k === '') b.disabled = kod === '';
            else b.setAttribute('aria-pressed', k === kod ? 'true' : 'false');
        });
        ovl.hidden = false; menu.hidden = false;
        konumla(hucre);
        var ilk = menu.querySelector('[data-pi-kod]:not(:disabled)');
        if (ilk && !mobil()) ilk.focus({ preventScroll: true });
    }

    function gonder(kod) {
        if (mesgul || !hedef) return;
        mesgul = true; hata(''); menu.classList.add('pi-yukleniyor');
        var emp = hedef.getAttribute('data-emp'), tarih = hedef.getAttribute('data-tarih');
        var det = hedef.closest('details.pc-kisi');
        var govde = new URLSearchParams();
        govde.set('csrf', CSRF); govde.set('employee_id', emp); govde.set('tarih', tarih); govde.set('kod', kod);
        fetch(URL_, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: govde.toString()
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, error: 'Sunucudan beklenmeyen yanıt geldi.' }; });
        }).then(function (j) {
            if (j && j.ok) {
                depoyaz({ emp: det ? det.getAttribute('data-emp-kisi') : '', sl: wrap ? wrap.scrollLeft : 0, st: wrap ? wrap.scrollTop : 0, y: window.pageYOffset || 0 });
                window.location.reload();
                return;
            }
            mesgul = false; menu.classList.remove('pi-yukleniyor');
            hata('Kaydedilemedi: ' + ((j && j.error) || 'bilinmeyen hata') + ' Lütfen tekrar deneyin.');
        }).catch(function () {
            mesgul = false; menu.classList.remove('pi-yukleniyor');
            hata('Kaydedilemedi: bağlantı kurulamadı. Lütfen tekrar deneyin.');
        });
    }

    sayfa.addEventListener('click', function (e) {
        var h = e.target.closest ? e.target.closest('[data-pi]') : null;
        if (!h || !sayfa.contains(h)) return;
        if (hedef === h && !menu.hidden) { kapat(); return; }
        ac(h);
    });
    ovl.addEventListener('click', kapat);
    menu.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('button') : null;
        if (!b) return;
        if (b.hasAttribute('data-pi-kapat')) { kapat(); return; }
        if (b.hasAttribute('data-pi-kod') && !b.disabled) gonder(b.getAttribute('data-pi-kod') || '');
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !menu.hidden) { e.preventDefault(); kapat(); }
    });
    window.addEventListener('resize', function () { if (!menu.hidden && hedef) konumla(hedef); });
    // Izgara kayarsa menü hücreyi izler; hücre görünür alanın dışına çıkarsa kapanır (menü başka hücreye yapışık kalmasın)
    function izle() {
        if (menu.hidden || !hedef || mobil()) return;
        var r = hedef.getBoundingClientRect(), w = wrap ? wrap.getBoundingClientRect() : { left: 0, right: window.innerWidth, top: 0, bottom: window.innerHeight };
        if (r.right < w.left || r.left > w.right || r.bottom < w.top || r.top > w.bottom) { kapat(); return; }
        konumla(hedef);
    }
    if (wrap) wrap.addEventListener('scroll', izle, { passive: true });
    window.addEventListener('scroll', izle, { passive: true });

    // Yenilemeden sonra: kaydırma konumunu ve (mobilde) açık kişiyi geri getir
    var d = depooku();
    if (d) {
        if (wrap) { wrap.scrollLeft = d.sl || 0; wrap.scrollTop = d.st || 0; }
        if (d.y) window.scrollTo(0, d.y);
        if (d.emp) {
            var kd = document.querySelector('details.pc-kisi[data-emp-kisi="' + String(d.emp).replace(/[^0-9]/g, '') + '"]');
            if (kd) kd.open = true;
        }
    }
})();
