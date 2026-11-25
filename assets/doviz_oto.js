/* =========================================================
   assets/doviz_oto.js — Otonom TCMB döviz kuru istemci yardımcısı
   Sıfır bağımlılık, vanilla JS IIFE, global: window.DovizOto ({ cek, bagla }).
   Hata/çevrimdışı durumlarda asla yakalanmamış hata fırlatmaz, formu bloklamaz.
   Manuel kur koruması: otomatik doldurulan alan data-kur-oto="1" taşır;
   kullanıcı elle yazarsa (isTrusted input) işaret kalkar ve üzerine yazılmaz.
   ========================================================= */
(function () {
    'use strict';

    var onbellek = {};

    function bugun() {
        var d = new Date();
        var m = d.getMonth() + 1;
        var g = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (g < 10 ? '0' + g : g);
    }

    function normKod(k) {
        return String(k == null ? '' : k).trim().toUpperCase().replace(/[^A-Z]/g, '');
    }

    function normTarih(t) {
        var s = String(t == null ? '' : t).trim();
        return /^\d{4}-\d{2}-\d{2}$/.test(s) ? s : bugun();
    }

    /**
     * TCMB kurunu api_doviz_kur.php üzerinden çeker (in-memory önbellekli).
     * Her zaman çözümlenir (reject etmez); hata durumunda { ok: false } döner.
     */
    function cek(kod, tarih, secenekler) {
        var opt = secenekler || {};
        var k = normKod(kod);
        var t = normTarih(tarih);
        if (!k || k === 'TRY') {
            return Promise.resolve({ ok: false, kod: k, tarih: t });
        }
        var anahtar = k + '|' + t;
        if (!opt.zorla && Object.prototype.hasOwnProperty.call(onbellek, anahtar)) {
            return Promise.resolve(onbellek[anahtar]);
        }
        var url = 'api_doviz_kur.php?kod=' + encodeURIComponent(k)
            + '&tarih=' + encodeURIComponent(t)
            + '&oto_cek=1';
        try {
            return fetch(url, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (r) {
                return r.text().then(function (txt) {
                    var j = null;
                    try { j = JSON.parse(txt); } catch (e) { j = null; }
                    if (j && j.ok) {
                        onbellek[anahtar] = j;
                        return j;
                    }
                    return { ok: false, hata: (j && j.hata) ? String(j.hata) : '' };
                });
            }).catch(function () {
                return { ok: false };
            });
        } catch (e) {
            return Promise.resolve({ ok: false });
        }
    }

    /**
     * Bir formdaki kur alanına otomatik TCMB kur doldurma ve yenileme düğmesi bağlar.
     * cfg: { form, pbEl, tarihEl, kurEl, pbFn, onDoldur, bicim }
     */
    function bagla(cfg) {
        var o = cfg || {};
        var kurEl = o.kurEl;
        if (!kurEl) {
            return { guncelle: function () {}, temizle: function () {} };
        }
        var pbEl = o.pbEl || null;
        var tarihEl = o.tarihEl || null;
        var pbFn = typeof o.pbFn === 'function' ? o.pbFn : null;
        var onDoldur = typeof o.onDoldur === 'function' ? o.onDoldur : null;
        var bicim = o.bicim === 'nokta' ? 'nokta' : 'virgul';

        var bilgi = kurEl.nextElementSibling && kurEl.nextElementSibling.hasAttribute('data-dv-oto-bilgi')
            ? kurEl.nextElementSibling
            : null;
        var metinEl, btn;
        if (!bilgi) {
            bilgi = document.createElement('span');
            bilgi.className = 'dv-oto-bilgi';
            bilgi.setAttribute('data-dv-oto-bilgi', '');
            bilgi.hidden = true;
            bilgi.style.display = 'none';
            bilgi.style.alignItems = 'center';
            bilgi.style.gap = '4px';
            bilgi.style.marginTop = '3px';
            bilgi.style.fontSize = '11px';
            bilgi.style.lineHeight = '1.2';
            bilgi.style.color = 'var(--muted, #64748b)';

            metinEl = document.createElement('span');
            metinEl.setAttribute('data-dv-oto-metin', '');

            btn = document.createElement('button');
            btn.type = 'button';
            btn.setAttribute('data-dv-oto-yenile', '');
            btn.title = 'TCMB kurunu yeniden çek';
            btn.setAttribute('aria-label', 'TCMB kurunu yeniden çek');
            btn.textContent = '\u21BB';
            btn.style.background = 'transparent';
            btn.style.border = '1px solid currentColor';
            btn.style.borderRadius = '4px';
            btn.style.color = 'inherit';
            btn.style.cursor = 'pointer';
            btn.style.fontSize = '11px';
            btn.style.lineHeight = '1';
            btn.style.padding = '1px 5px';
            btn.style.opacity = '0.85';

            bilgi.appendChild(metinEl);
            bilgi.appendChild(btn);
            if (kurEl.parentNode) {
                kurEl.parentNode.insertBefore(bilgi, kurEl.nextSibling);
            }
        } else {
            metinEl = bilgi.querySelector('[data-dv-oto-metin]');
            btn = bilgi.querySelector('[data-dv-oto-yenile]');
        }

        var istekNo = 0;
        var sonAnahtar = '';

        function gosterBilgi(gorunur) {
            bilgi.hidden = !gorunur;
            bilgi.style.display = gorunur ? 'inline-flex' : 'none';
        }

        function kodAl() {
            return normKod(pbFn ? pbFn() : (pbEl ? pbEl.value : ''));
        }

        function tarihAl() {
            return normTarih(tarihEl ? tarihEl.value : '');
        }

        function temizle() {
            istekNo++;
            sonAnahtar = '';
            kurEl.dataset.kurOto = '';
            if (metinEl) metinEl.textContent = '';
            if (btn) btn.disabled = false;
            gosterBilgi(false);
        }

        function guncelle(zorla) {
            var kod = kodAl();
            var tarih = tarihAl();
            if (!kod || kod === 'TRY' || kurEl.disabled) {
                temizle();
                return;
            }
            var mevcut = String(kurEl.value || '').trim();
            var anahtar = kod + '|' + tarih;
            if (!zorla && mevcut !== '' && kurEl.dataset.kurOto !== '1') {
                if (metinEl && !metinEl.textContent) metinEl.textContent = 'Manuel kur';
                gosterBilgi(true);
                return;
            }
            if (!zorla && mevcut !== '' && kurEl.dataset.kurOto === '1' && sonAnahtar === anahtar) {
                gosterBilgi(true);
                return;
            }

            var no = ++istekNo;
            if (btn) btn.disabled = true;
            if (metinEl) metinEl.textContent = 'Kur çekiliyor\u2026';
            gosterBilgi(true);

            cek(kod, tarih, { zorla: !!zorla }).then(function (res) {
                if (no !== istekNo) return;
                if (btn) btn.disabled = false;
                var simdikiKod = kodAl();
                if (kurEl.disabled || !simdikiKod || simdikiKod === 'TRY' || simdikiKod !== kod) {
                    gosterBilgi(false);
                    return;
                }
                if (!res || !res.ok) {
                    if (metinEl) metinEl.textContent = zorla ? 'Kur alınamadı' : '';
                    gosterBilgi(true);
                    return;
                }
                if (!zorla && String(kurEl.value || '').trim() !== '' && kurEl.dataset.kurOto !== '1') {
                    return;
                }
                var deger = bicim === 'nokta'
                    ? (res.kur_nokta != null ? String(res.kur_nokta) : String(res.kur || '').replace(',', '.'))
                    : String(res.kur || '');
                if (!deger) {
                    if (metinEl) metinEl.textContent = '';
                    return;
                }
                kurEl.value = deger;
                kurEl.dataset.kurOto = '1';
                sonAnahtar = anahtar;
                if (metinEl) metinEl.textContent = res.etiket ? String(res.etiket) : ('TCMB ' + kod);
                gosterBilgi(true);
                try {
                    kurEl.dispatchEvent(new Event('input', { bubbles: true }));
                } catch (e) { /* eski tarayıcı */ }
                if (onDoldur) {
                    try { onDoldur(res); } catch (e) { /* sessiz */ }
                }
            });
        }

        kurEl.addEventListener('input', function (e) {
            if (e && e.isTrusted) {
                kurEl.dataset.kurOto = '';
                sonAnahtar = '';
                if (String(kurEl.value || '').trim() !== '') {
                    if (metinEl) metinEl.textContent = 'Manuel kur';
                    gosterBilgi(true);
                } else {
                    if (metinEl) metinEl.textContent = '';
                }
            }
        });

        if (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                guncelle(true);
            });
        }

        return { guncelle: guncelle, temizle: temizle };
    }

    window.DovizOto = { cek: cek, bagla: bagla };
})();
