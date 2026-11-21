/* Mal Kabul Fişi formu: yazarak bulunan alan (combobox).
 * - Liste YALNIZ yazınca açılır (odaklanınca / boşken açılmaz).
 * - Karşılığı yoksa "+ “…” ekle" satırı çıkar (canAdd); kayıtta tanım olarak eklenir.
 * - canAdd=false (Depo): yalnız listedeki değer kabul edilir, aksi hâlde alan boşaltılır.
 * Kullanım: mkCombo(input, { items: () => [{name, dara?}], canAdd: true, onPick: (item, yeni) => {} }) */
(function () {
    'use strict';
    var up = function (s) { return String(s == null ? '' : s).trim().toLocaleUpperCase('tr-TR'); };

    function mkCombo(input, opt) {
        if (!input || input.dataset.mkcbHazir) return;
        input.dataset.mkcbHazir = '1';
        var canAdd = opt.canAdd !== false;
        var wrap = document.createElement('span');
        wrap.className = 'mk-cb';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        var box = document.createElement('ul');
        box.className = 'mk-cb-list'; box.hidden = true; box.setAttribute('role', 'listbox');
        wrap.appendChild(box);
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('role', 'combobox');
        var items = [], active = -1, kirli = false;

        function close() { box.hidden = true; active = -1; }
        function draw() {
            box.innerHTML = '';
            items.forEach(function (it, i) {
                var li = document.createElement('li');
                li.setAttribute('role', 'option');
                li.className = (it.ekle ? 'mk-cb-ekle ' : '') + (i === active ? 'mk-cb-aktif' : '');
                li.textContent = it.ekle ? '+ “' + it.ad + '” ekle' : it.x.name;
                li.addEventListener('pointerdown', function (e) { e.preventDefault(); choose(it); });
                box.appendChild(li);
            });
            box.hidden = !items.length;
        }
        function bul(q) { return opt.items().filter(function (x) { return up(x.name) === q; })[0] || null; }
        function render() {
            var q = up(input.value);
            if (!q) { items = []; close(); return; }
            var all = opt.items();
            var skor = function (x) { var n = up(x.name); return n === q ? 0 : n.indexOf(q) === 0 ? 1 : 2; };
            items = all.filter(function (x) { return up(x.name).indexOf(q) >= 0; })
                .sort(function (a, b) { return skor(a) - skor(b); }).slice(0, 8)
                .map(function (x) { return { x: x }; });
            if (canAdd && !bul(q)) items.push({ ekle: true, ad: input.value.trim() });
            active = items.length ? 0 : -1;
            draw();
        }
        function choose(it) {
            if (it.ekle) { input.value = it.ad; if (opt.onPick) opt.onPick(null, true); }
            else { input.value = it.x.name; if (opt.onPick) opt.onPick(it.x, false); }
            kirli = false; close();
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        input.addEventListener('input', function () { kirli = true; render(); });
        input.addEventListener('blur', function () {
            setTimeout(function () {
                close();
                if (!kirli) return;
                kirli = false;
                var m = bul(up(input.value));
                if (m) { input.value = m.name; if (opt.onPick) opt.onPick(m, false); }   // yazım kanonik adla düzelir
                else if (!canAdd) input.value = '';                                       // listede olmayan değer kabul edilmez
                else if (input.value.trim() !== '' && opt.onPick) opt.onPick(null, true);
            }, 130);
        });
        input.addEventListener('keydown', function (e) {
            if (box.hidden) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(items.length - 1, active + 1); draw(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(0, active - 1); draw(); }
            else if (e.key === 'Escape') { e.preventDefault(); close(); }
            else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); choose(items[active]); }
        });
    }
    window.mkCombo = mkCombo;

    // data-mkcb="<tür>" taşıyan alanlar (liste: #mkComboData JSON'u)
    function otomatik() {
        var kaynak = {};
        try { kaynak = JSON.parse((document.getElementById('mkComboData') || {}).textContent || '{}'); } catch (e) { kaynak = {}; }
        document.querySelectorAll('input[data-mkcb]').forEach(function (inp) {
            var tur = inp.dataset.mkcb;
            mkCombo(inp, {
                items: function () { return (kaynak[tur] || []).map(function (n) { return { name: n }; }); },
                canAdd: inp.dataset.mkcbEkle !== '0'
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', otomatik); else otomatik();
})();
