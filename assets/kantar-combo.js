/* Mal Kabul Fişi formu: öneri kutusu (malzeme_stok tedarikçi kutusu mantığı).
 * - İmleç gelince (focus / click) ve yazınca açılır.
 * - Boşken en son 3 işlem üstte, kalanı alfabetik sıralı gelir.
 * - TR-duyarsız katlama (İ/I/ı, Ş/ş, Ğ/ğ, Ü/ü, Ö/ö, Ç/ç) ile süzer.
 * - Karşılığı yoksa "+ “…” ekle" satırı çıkar (canAdd); kayıtta tanım olarak eklenir.
 * - canAdd=false (Depo): yalnız listedeki değer kabul edilir, aksi hâlde alan boşaltılır.
 * Kullanım: mkCombo(input, { items: () => [{name, dara?, recent?}], canAdd: true, onPick: (item, yeni) => {} }) */
(function () {
    'use strict';

    function fold(s) {
        return String(s == null ? '' : s)
            .replace(/İ/g, 'i').replace(/I/g, 'i').replace(/Ş/g, 's').replace(/Ğ/g, 'g')
            .replace(/Ü/g, 'u').replace(/Ö/g, 'o').replace(/Ç/g, 'c')
            .replace(/ı/g, 'i').replace(/ş/g, 's').replace(/ğ/g, 'g')
            .replace(/ü/g, 'u').replace(/ö/g, 'o').replace(/ç/g, 'c')
            .toLowerCase().trim();
    }

    function mkCombo(input, opt) {
        if (!input || input.dataset.mkcbHazir) return;
        input.dataset.mkcbHazir = '1';
        var canAdd = opt.canAdd !== false;
        var wrap = document.createElement('span');
        wrap.className = 'mk-cb';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        var box = document.createElement('ul');
        box.className = 'mk-cb-list';
        box.hidden = true;
        box.setAttribute('role', 'listbox');
        wrap.appendChild(box);
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('role', 'combobox');
        var items = [], active = -1, kirli = false;

        function close() { box.hidden = true; active = -1; }

        function updateActive() {
            var lis = box.querySelectorAll('li');
            lis.forEach(function (li, i) {
                var on = i === active;
                li.classList.toggle('mk-cb-aktif', on);
                if (on && typeof li.scrollIntoView === 'function') {
                    li.scrollIntoView({ block: 'nearest' });
                }
            });
        }

        function draw() {
            box.innerHTML = '';
            items.forEach(function (it, i) {
                var li = document.createElement('li');
                li.setAttribute('role', 'option');
                var cls = [];
                if (it.ekle) cls.push('mk-cb-ekle');
                if (it.sonBitis) cls.push('mk-cb-son-bitis');
                if (i === active) cls.push('mk-cb-aktif');
                li.className = cls.join(' ');
                li.textContent = it.ekle ? '+ “' + it.ad + '” ekle' : it.x.name;
                li.addEventListener('pointerdown', function (e) { e.preventDefault(); choose(it); });
                box.appendChild(li);
            });
            box.hidden = !items.length;
        }

        function bul(q) {
            if (!q) return null;
            var all = opt.items();
            for (var i = 0; i < all.length; i++) {
                if (fold(all[i].name) === q) return all[i];
            }
            return null;
        }

        function render() {
            if (input.disabled || input.readOnly) { close(); return; }
            var raw = input.value.trim();
            var q = fold(raw);
            var all = opt.items();
            items = [];

            if (!q) {
                // Boşken: en son 3 işlem üstte, kalanı alfabetik sıralı
                var lastRecentIdx = -1;
                for (var i = 0; i < all.length; i++) {
                    if (all[i].recent) lastRecentIdx = i;
                    items.push({ x: all[i] });
                }
                if (lastRecentIdx >= 0 && lastRecentIdx < items.length - 1) {
                    items[lastRecentIdx].sonBitis = true;
                }
                active = -1;
            } else {
                var skor = function (x) {
                    var n = fold(x.name);
                    return n === q ? 0 : n.indexOf(q) === 0 ? 1 : 2;
                };
                var matched = all.filter(function (x) {
                    return fold(x.name).indexOf(q) !== -1;
                }).map(function (x, idx) {
                    return { x: x, idx: idx, s: skor(x) };
                }).sort(function (a, b) {
                    return (a.s - b.s) || (a.idx - b.idx);
                });
                items = matched.map(function (m) { return { x: m.x }; });
                if (canAdd && !bul(q)) {
                    items.push({ ekle: true, ad: raw.toLocaleUpperCase('tr-TR') });
                }
                active = -1;
            }
            draw();
        }

        function choose(it) {
            if (it.ekle) {
                input.value = it.ad;
                if (opt.onPick) opt.onPick(null, true);
            } else {
                input.value = it.x.name;
                if (opt.onPick) opt.onPick(it.x, false);
            }
            kirli = false;
            close();
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        input.addEventListener('focus', render);
        input.addEventListener('click', function () { if (box.hidden) render(); });
        input.addEventListener('input', function () { kirli = true; render(); });
        input.addEventListener('blur', function () {
            setTimeout(function () {
                close();
                if (!kirli) return;
                kirli = false;
                var m = bul(fold(input.value));
                if (m) { input.value = m.name; if (opt.onPick) opt.onPick(m, false); }
                else if (!canAdd) input.value = '';
                else if (input.value.trim() !== '' && opt.onPick) opt.onPick(null, true);
            }, 150);
        });
        input.addEventListener('keydown', function (e) {
            if (box.hidden) {
                if (e.key === 'ArrowDown') { e.preventDefault(); render(); }
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                active = Math.min(items.length - 1, active + 1);
                updateActive();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                active = Math.max(0, active - 1);
                updateActive();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                close();
            } else if (e.key === 'Enter' && active >= 0 && items[active]) {
                e.preventDefault();
                choose(items[active]);
            }
        });
    }
    window.mkCombo = mkCombo;

    // data-mkcb="<tür>" taşıyan alanlar (liste: #mkComboData JSON'u)
    function otomatik() {
        var kaynak = {};
        try { kaynak = JSON.parse((document.getElementById('mkComboData') || {}).textContent || '{}'); } catch (e) { kaynak = {}; }
        var sonMap = kaynak._son || {};
        document.querySelectorAll('input[data-mkcb]').forEach(function (inp) {
            var tur = inp.dataset.mkcb;
            var sonSayi = parseInt(sonMap[tur] || 0, 10) || 0;
            mkCombo(inp, {
                items: function () {
                    return (kaynak[tur] || []).map(function (n, idx) {
                        return { name: n, recent: idx < sonSayi };
                    });
                },
                canAdd: inp.dataset.mkcbEkle !== '0'
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', otomatik); else otomatik();
})();
