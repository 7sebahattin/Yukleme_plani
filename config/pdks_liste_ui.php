<?php
declare(strict_types=1);

// =========================================================
// config/pdks_liste_ui.php — Personel Takibi liste ekranlarının ORTAK
// istemci davranışı (v296). Fonksiyon tanımlar, çıktı yalnız çağrılınca.
//
// İki opt-in desen, TEK script:
//
//  1) OTOMATİK FİLTRE — <form method="get" data-oto-filtre>
//     · select / date / month / checkbox / radio `change` → form hemen gönderilir
//       (date/month klavyeyle yazılıyorsa yarım tarihte gitmesin diye 900 ms
//       bekler; odak çıkınca ya da Enter'da hemen gider).
//     · metin/arama kutusu → `input`'ta 400 ms sonra, Enter'da hemen.
//     · Değer SAYFA AÇILIŞINDAKİ değerle aynıysa gönderilmez (döngü yok);
//       yalnız kullanıcı olayları dinlenir, programatik değişiklik tetiklemez.
//     · `data-oto-filtre-yok` taşıyan alan otomatik göndermez;
//       `data-oto-filtre-bekle="x"` taşıyan alan değeri x olunca göndermez
//       (raporlar.php "Özel" dönem: önce tarihler seçilir).
//     · Submit düğmesi <noscript> içinde kalır: JS kapalıyken form yine çalışır.
//     · POST formlarına UYGULANMAZ (method="get" değilse yok sayılır).
//     Sunucu filtre mantığı DEĞİŞMEZ — form eskisi gibi GET ile gider; dışa
//     aktarım bağlantıları sayfa yeniden çizildiği için güncel filtreyi taşır.
//
//  2) TIKLANABİLİR SATIR — <tr class="pdks-satir-link" data-href="…" tabindex="0">
//     (ya da aynı sınıf + data-href taşıyan herhangi bir kart).
//     · Tık → aynı sekmede açar; Ctrl/⌘/Shift-tık ve orta tık → yeni sekme.
//     · İçerideki a/button/input/select/textarea/label/summary tıkları YOK SAYILIR
//       (kendi işini yapar). Metin seçiliyken tık gezinmez.
//     · Satır odaktayken Enter açar (Ctrl/⌘+Enter → yeni sekme).
//     · Erişilebilirlik / JS kapalı: satırın birincil hücresi aynı URL'ye
//       gerçek bir <a href> taşır — onu KALDIRMA.
//     Görünüm assets/pdks.css "Tıklanabilir satır" bloğu.
//
//  3) BAŞLIKLA SIRALAMA (v299) — <table data-pdks-sirala [data-sirala-varsayilan="etiket"]>
//     · <th data-sirala="metin|sayi|zaman"> içinde GERÇEK <button type="button">
//       (klavye: Enter/Boşluk). Hücre ham değeri `data-sirala-deger` taşır
//       (zaman/sayı = epoch/saniye, metin = küçük harf); boş değer HER İKİ yönde SONA.
//     · Metin sıralaması localeCompare('tr', numeric + base): K002 < K010.
//     · Kararlı. Üç durum: artan → azalan → varsayılan (sunucu sırası).
//     · Yalnız aktif <th> `aria-sort` taşır; durum metni `aria-live` satırında.
//     · Mobil kart karşılığı: <select data-pdks-sirala-sec data-hedef="kapsayici-id">
//       (option value "" = varsayılan, "anahtar:asc|desc"); kapsayıcı çocukları
//       `data-sirala-oge` + `data-sd-<anahtar>` değerlerini taşır. AYNI karşılaştırıcı.
//     Tercih SAKLANMAZ. Görünüm assets/pdks.css "v299 — Sıralama" bloğu.
//
// Kullanım (sayfa sonunda, render_footer()'dan önce):  pdks_liste_ui_js();
// İki kez çağrılırsa ikinci çağrı hiçbir şey basmaz.
// =========================================================

/** Otomatik filtre formunun JS kapalıyken görünen gönder düğmesi. */
function pdks_oto_filtre_noscript(string $etiket = 'Filtrele'): string
{
    return '<noscript><button type="submit" class="btn">' . htmlspecialchars($etiket, ENT_QUOTES, 'UTF-8') . '</button></noscript>';
}

/** Ortak script'i bir kez basar. */
function pdks_liste_ui_js(): void
{
    static $basildi = false;
    if ($basildi) return;
    $basildi = true;
    echo "<script>\n" . pdks_liste_ui_js_kaynak() . "\n</script>\n";
}

/** Script kaynağı (test için ayrı). */
function pdks_liste_ui_js_kaynak(): string
{
    return <<<'JS'
(function () {
    'use strict';
    if (window.__pdksListeUi) return;
    window.__pdksListeUi = true;

    // ── 1) Otomatik filtre ────────────────────────────────
    var METIN_MS = 400, TARIH_KLAVYE_MS = 900;

    function gonder(form) {
        if (form.__otoGidiyor) return;
        form.__otoGidiyor = true;
        form.classList.add('pdks-oto-filtre-gidiyor');
        form.setAttribute('aria-busy', 'true');
        if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
    }

    function alanDegeri(el) {
        if (el.type === 'checkbox' || el.type === 'radio') return el.checked ? '1' : '0';
        return el.value;
    }

    function metinMi(el) {
        if (el.tagName === 'TEXTAREA') return true;
        if (el.tagName !== 'INPUT') return false;
        return ['search', 'text', 'tel', 'email', 'number', ''].indexOf(el.type) !== -1;
    }

    function tarihMi(el) {
        return el.tagName === 'INPUT' && ['date', 'month', 'week', 'time', 'datetime-local'].indexOf(el.type) !== -1;
    }

    function formuBagla(form) {
        if (form.__otoBagli) return;
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'get') return;
        form.__otoBagli = true;

        var ilk = new WeakMap();
        Array.prototype.forEach.call(form.elements, function (el) { ilk.set(el, alanDegeri(el)); });

        var zamanlayici = null, klavye = new WeakMap();

        function degistiMi() {
            return Array.prototype.some.call(form.elements, function (el) {
                if (!el.name || el.disabled || el.hasAttribute('data-oto-filtre-yok')) return false;
                return ilk.has(el) && ilk.get(el) !== alanDegeri(el);
            });
        }
        function simdiGonder() {
            clearTimeout(zamanlayici);
            if (degistiMi()) gonder(form);
        }
        function sonraGonder(ms) {
            clearTimeout(zamanlayici);
            zamanlayici = setTimeout(simdiGonder, ms);
        }
        function uygun(el) {
            return el && el.form === form && el.name && !el.disabled && !el.hasAttribute('data-oto-filtre-yok');
        }

        form.addEventListener('keydown', function (e) {
            var el = e.target;
            if (!uygun(el)) return;
            if (e.key === 'Enter' && (metinMi(el) || tarihMi(el))) {
                e.preventDefault();
                simdiGonder();
                return;
            }
            if (tarihMi(el)) klavye.set(el, true);
        });
        form.addEventListener('input', function (e) {
            var el = e.target;
            if (!uygun(el) || !metinMi(el) || e.isComposing) return;
            sonraGonder(METIN_MS);
        });
        form.addEventListener('change', function (e) {
            var el = e.target;
            if (!uygun(el)) return;
            // data-oto-filtre-bekle="deger": bu değer seçilince GÖNDERME — kullanıcı
            // önce ek alanları doldursun (ör. raporlar.php "Özel tarih aralığı").
            if (el.hasAttribute('data-oto-filtre-bekle') && el.getAttribute('data-oto-filtre-bekle') === el.value) {
                clearTimeout(zamanlayici);
                return;
            }
            if (metinMi(el)) { simdiGonder(); return; }
            if (tarihMi(el) && klavye.get(el)) { sonraGonder(TARIH_KLAVYE_MS); return; }
            simdiGonder();
        });
        form.addEventListener('focusout', function (e) {
            var el = e.target;
            if (uygun(el) && tarihMi(el) && klavye.get(el)) { klavye.set(el, false); simdiGonder(); }
        });
        form.addEventListener('mousedown', function (e) {
            if (tarihMi(e.target)) klavye.set(e.target, false);
        });
    }

    function filtreleriBagla() {
        document.querySelectorAll('form[data-oto-filtre]').forEach(formuBagla);
    }

    // ── 3) Başlıkla sıralama (v299) ───────────────────────
    var KARSILASTIR = (typeof Intl !== 'undefined' && Intl.Collator)
        ? new Intl.Collator('tr', { numeric: true, sensitivity: 'base' }).compare
        : function (a, b) { return a.localeCompare(b, 'tr', { numeric: true, sensitivity: 'base' }); };

    function sayiCoz(v) {
        var n = parseFloat(String(v).replace(',', '.'));
        return isNaN(n) ? null : n;
    }
    function bosDeger(tip, v) {
        if (v === null || v === undefined) return true;
        v = String(v).trim();
        if (v === '' || v === '—') return true;
        return tip !== 'metin' && sayiCoz(v) === null;
    }
    // ogeler: [{ilk, deger}] → yeni dizi. yon: 1 artan, -1 azalan. Boşlar her iki yönde sonda;
    // eşitlikte ilk sıra korunur (kararlı).
    function ogeSirala(ogeler, tip, yon) {
        return ogeler.slice().sort(function (a, b) {
            var ba = bosDeger(tip, a.deger), bb = bosDeger(tip, b.deger);
            if (ba || bb) return ba && bb ? a.ilk - b.ilk : (ba ? 1 : -1);
            var c = tip === 'metin'
                ? KARSILASTIR(String(a.deger), String(b.deger))
                : sayiCoz(a.deger) - sayiCoz(b.deger);
            return c !== 0 ? c * yon : a.ilk - b.ilk;
        });
    }
    function yerlestir(kap, siraliOgeler) {
        var par = document.createDocumentFragment();
        siraliOgeler.forEach(function (o) { par.appendChild(o.el); });
        kap.appendChild(par);
    }

    function tabloSiralaBagla(tablo) {
        if (tablo.__siralaBagli || !tablo.tBodies[0]) return;
        tablo.__siralaBagli = true;
        var govde = tablo.tBodies[0];
        var satirlar = Array.prototype.map.call(govde.rows, function (tr, i) { return { el: tr, ilk: i }; });
        var basliklar = Array.prototype.slice.call(tablo.querySelectorAll('thead th[data-sirala]'));
        var varsayilan = tablo.getAttribute('data-sirala-varsayilan') || 'varsayılan sıra';
        var durumEl = document.createElement('div');
        durumEl.className = 'pdks-sirala-durum';
        durumEl.setAttribute('role', 'status');
        durumEl.setAttribute('aria-live', 'polite');
        var kap = tablo.closest('.table-wrap') || tablo;
        kap.parentNode.insertBefore(durumEl, kap);
        durumEl.textContent = 'Sıralama: ' + varsayilan;
        var durum = { th: null, yon: 0 };

        function baslikMetni(th) {
            var b = th.querySelector('button');
            return ((b || th).textContent || '').replace(/\s+/g, ' ').trim();
        }
        function uygula() {
            basliklar.forEach(function (th) { th.removeAttribute('aria-sort'); });
            if (!durum.th) {
                yerlestir(govde, satirlar.slice().sort(function (a, b) { return a.ilk - b.ilk; }));
                durumEl.textContent = 'Sıralama: ' + varsayilan;
                return;
            }
            var idx = durum.th.cellIndex, tip = durum.th.getAttribute('data-sirala');
            var ogeler = satirlar.map(function (s) {
                var td = s.el.cells[idx], d = td ? td.getAttribute('data-sirala-deger') : null;
                if (d === null && td) d = td.textContent;
                return { el: s.el, ilk: s.ilk, deger: d };
            });
            yerlestir(govde, ogeSirala(ogeler, tip, durum.yon));
            durum.th.setAttribute('aria-sort', durum.yon > 0 ? 'ascending' : 'descending');
            durumEl.textContent = 'Sıralama: ' + baslikMetni(durum.th) + (durum.yon > 0 ? ' (artan)' : ' (azalan)');
        }
        basliklar.forEach(function (th) {
            var btn = th.querySelector('button');
            if (!btn) return;
            btn.addEventListener('click', function () {
                if (durum.th !== th) { durum.th = th; durum.yon = 1; }
                else if (durum.yon === 1) durum.yon = -1;
                else { durum.th = null; durum.yon = 0; }
                uygula();
            });
        });
    }

    function kartSiralaBagla(sec) {
        if (sec.__siralaBagli) return;
        var kap = document.getElementById(sec.getAttribute('data-hedef') || '');
        if (!kap) return;
        sec.__siralaBagli = true;
        var ogeler = Array.prototype.map.call(kap.querySelectorAll(':scope > [data-sirala-oge]'), function (el, i) { return { el: el, ilk: i }; });
        sec.addEventListener('change', function () {
            var v = sec.value;
            if (!v) { yerlestir(kap, ogeler.slice().sort(function (a, b) { return a.ilk - b.ilk; })); return; }
            var p = v.split(':'), anahtar = p[0], yon = p[1] === 'desc' ? -1 : 1;
            var opt = sec.options[sec.selectedIndex], tip = (opt && opt.getAttribute('data-tip')) || 'metin';
            yerlestir(kap, ogeSirala(ogeler.map(function (o) {
                return { el: o.el, ilk: o.ilk, deger: o.el.getAttribute('data-sd-' + anahtar) };
            }), tip, yon));
        });
    }

    function siralamalariBagla() {
        document.querySelectorAll('table[data-pdks-sirala]').forEach(tabloSiralaBagla);
        document.querySelectorAll('select[data-pdks-sirala-sec]').forEach(kartSiralaBagla);
    }

    // Geri tuşuyla önbellekten dönülürse "gidiyor" durumu takılı kalmasın.
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        document.querySelectorAll('form[data-oto-filtre]').forEach(function (f) {
            f.__otoGidiyor = false;
            f.classList.remove('pdks-oto-filtre-gidiyor');
            f.removeAttribute('aria-busy');
        });
    });

    // ── 2) Tıklanabilir satır ─────────────────────────────
    var IC_ETKILESIM = 'a,button,input,select,textarea,label,summary,details,[data-satir-yok]';

    function satirBul(e) {
        var t = e.target;
        if (!t || !t.closest) return null;
        var satir = t.closest('.pdks-satir-link[data-href]');
        if (!satir) return null;
        var ic = t.closest(IC_ETKILESIM);
        if (ic && satir.contains(ic) && ic !== satir) return null;
        return satir;
    }
    function yeniSekme(href) {
        var w = window.open(href, '_blank', 'noopener');
        if (w) w.opener = null;
    }
    function ac(satir, yeni) {
        var href = satir.getAttribute('data-href');
        if (!href) return;
        if (yeni) yeniSekme(href); else window.location.href = href;
    }

    document.addEventListener('click', function (e) {
        if (e.button !== 0 || e.defaultPrevented) return;
        var satir = satirBul(e);
        if (!satir) return;
        var sec = window.getSelection ? String(window.getSelection()) : '';
        if (sec !== '' && satir.contains(window.getSelection().anchorNode)) return;
        ac(satir, e.ctrlKey || e.metaKey || e.shiftKey);
    });
    document.addEventListener('auxclick', function (e) {
        if (e.button !== 1) return;
        var satir = satirBul(e);
        if (!satir) return;
        e.preventDefault();
        ac(satir, true);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        var t = e.target;
        if (!t || !t.classList || !t.classList.contains('pdks-satir-link') || !t.hasAttribute('data-href')) return;
        e.preventDefault();
        ac(t, e.ctrlKey || e.metaKey);
    });

    function hepsiniBagla() { filtreleriBagla(); siralamalariBagla(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', hepsiniBagla);
    else hepsiniBagla();
})();
JS;
}
