/* =========================================================
   Yükleme Planı - app.js (v3)
   Modal-tabanlı palet ekleme/düzenleme
   ========================================================= */
/* ── Alt çubuğun (bottomnav) kapladığı alan — sabit dok içeriğin/açılır
   listelerin arkasında kalmasın diye tüm "ekranın altı" hesaplarında
   kullanılır. Masaüstünde ve body.bn-yok'ta --bn-h 0'dır.
   ⚠ Bilerek IIFE'lerin DIŞINDA: dosya dört ayrı IIFE'den oluşur ve hem
   kebab (1.) hem öneri kutusu positionList() (2.) bunu çağırır. İlk IIFE'nin
   içinde tanımlıyken öneri kutusu her odaklanmada ReferenceError veriyordu
   (scripts/suggest_list_smoke.js). ── */
function bnAltPay() {
    try {
        return parseFloat(getComputedStyle(document.body).getPropertyValue('--bn-h')) || 0;
    } catch (_) { return 0; }
}
function ekranAlti() {
    return window.innerHeight - bnAltPay();
}

(function () {
    'use strict';

    /* ── Türkçe büyük harf — data-uppercase="tr" ── */
    // Sayfa yüklenince mevcut değerleri dönüştür (DB'den gelen eski title-case değerler için)
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-uppercase="tr"]').forEach(function(el) {
            if (el.value) el.value = el.value.toLocaleUpperCase('tr-TR');
        });
    });
    // Kullanıcı yazarken anlık dönüştür
    document.addEventListener('input', function(e) {
        const el = e.target;
        if (!el || !el.matches('[data-uppercase="tr"]')) return;
        const start = el.selectionStart;
        const end   = el.selectionEnd;
        el.value = el.value.toLocaleUpperCase('tr-TR');
        try { el.setSelectionRange(start, end); } catch (_) {}
    });

    /* ── Boşluksuz alan — data-nospace ──
       Plaka gibi alanlarda kullanıcı "34 ABC 123" diye yazar ama hedef sistem
       (HKS) boşluksuz bekler. Boşluk anında silinir ve imleç silinen karakter
       sayısı kadar geri çekilir — yoksa imleç metnin sonuna sıçrar.
       (halkayit/app.html'deki oPlaka/tkPlaka/etPlaka davranışının aynısı.) */
    function bosluksuzUygula(el) {
        if (!/\s/.test(el.value)) return;
        const imlec = el.selectionStart;
        const onceki = el.value.length;
        el.value = el.value.replace(/\s+/g, '');
        if (imlec !== null) {
            const yeni = Math.max(0, imlec - (onceki - el.value.length));
            try { el.setSelectionRange(yeni, yeni); } catch (_) {}
        }
    }
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-nospace]').forEach(bosluksuzUygula);
    });
    document.addEventListener('input', function(e) {
        const el = e.target;
        if (el && el.matches && el.matches('[data-nospace]')) bosluksuzUygula(el);
    });

    /* ── Yazarak aranabilir select — data-aramali ──────────────────────────
       Hal Kayıt panelindeki "İhracat Yapılan Ülke" kutusunun aynısı
       (halkayit/app.html: aramaliSelectYap / selectMetindenSec).

       ASIL <select> DOM'da KALIR, yalnız görsel olarak gizlenir. Değeri o
       taşır — yani form POST'u, sunucu doğrulaması ve mevcut okuma/yazma
       kodu hiç değişmeden çalışır. Önüne bir metin kutusu + <datalist>
       eklenir; yazılan metin option adlarıyla TAM eşleşince select'in değeri
       atanır ve 'change' yayılır.

       Eşleşme TAM addır — kullanıcı "şef" yazarken henüz seçim yapmamıştır;
       "ŞEFTALİ" tamamlanınca değer oluşur. Yazı hiçbir kayda denk gelmiyorsa
       kutu uyarı rengine döner, sessizce boş kalmaz. */
    const araNorm = (s) => String(s == null ? '' : s)
        .replace(/İ/g, 'i').replace(/I/g, 'ı')
        .toLocaleLowerCase('tr-TR')
        .replace(/ş/g, 's').replace(/ğ/g, 'g').replace(/ü/g, 'u')
        .replace(/ö/g, 'o').replace(/ç/g, 'c').replace(/ı/g, 'i')
        .trim();

    function araSelectEslestir(sel, metin) {
        const n = araNorm(metin);
        let bulunan = '', cakisma = false;
        if (n !== '') {
            for (const o of sel.options) {
                if (o.value === '') continue;
                if (araNorm(o.textContent) !== n) continue;
                if (bulunan === '') { bulunan = o.value; continue; }
                if (o.value !== bulunan) { cakisma = true; break; }
            }
        }
        const inp = sel._araInput;
        if (inp) {
            const yok = n !== '' && bulunan === '';
            inp.classList.toggle('ara-sec-bos', yok);
            inp.title = yok ? 'Listede böyle bir kayıt yok'
                      : (cakisma ? 'Aynı adla birden fazla kayıt var — ilki seçildi, kontrol edin' : '');
        }
        if (sel.value !== bulunan) {
            sel.value = bulunan;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    // Önerileri select'in GÜNCEL option'larından kurar ve görünen metni
    // select'in değeriyle eşitler (değer koddan atanmışsa da doğru görünür).
    function araSelectTazele(sel) {
        const dl = sel._araDl, inp = sel._araInput;
        if (!dl || !inp) return;
        dl.innerHTML = '';
        Array.from(sel.options).forEach(function (o) {
            if (o.value === '') return;          // "— seçilmedi —" öneri değildir
            const opt = document.createElement('option');
            opt.value = o.textContent;
            dl.appendChild(opt);
        });
        const secili = sel.options[sel.selectedIndex];
        const yeni = (secili && secili.value !== '') ? secili.textContent : '';
        if (inp.value !== yeni) { inp.value = yeni; inp.classList.remove('ara-sec-bos'); }
    }

    function araSelectKur(sel) {
        if (!sel || sel.dataset.aramaliKuruldu === '1') return;
        sel.dataset.aramaliKuruldu = '1';

        const dl = document.createElement('datalist');
        dl.id = (sel.id || sel.name || 'ara') + 'Liste';
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.className = (sel.className || '') + ' ara-sec';
        inp.setAttribute('list', dl.id);
        inp.autocomplete = 'off';
        inp.placeholder = sel.dataset.aramali || 'yazın veya listeden seçin';

        sel.parentNode.insertBefore(inp, sel);
        sel.parentNode.insertBefore(dl, sel);
        sel.classList.add('ara-sec-gizli');

        sel._araInput = inp;
        sel._araDl    = dl;
        sel._araTazele = function () { araSelectTazele(sel); };
        inp.addEventListener('input',  function () { araSelectEslestir(sel, inp.value); });
        inp.addEventListener('change', function () { araSelectEslestir(sel, inp.value); });
        araSelectTazele(sel);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('select[data-aramali]').forEach(araSelectKur);
    });

    /* ── Açılır/kapanır kart bölümleri ── */
    document.querySelectorAll('.collapsible-card').forEach(card => {
        const head = card.querySelector('.card-head-toggle');
        if (!head) return;
        head.addEventListener('click', e => {
            if (e.target.closest('input, select, textarea, button')) return;
            card.classList.toggle('collapsed');
        });
    });

    /* ── Kebab dropdown — tüm sayfalarda, event delegation ── */
    function closeAllDropdowns() {
        document.querySelectorAll('.pc-dropdown').forEach(d => {
            d.hidden = true;
            d.style.cssText = '';
        });
    }
    document.addEventListener('click', e => {
        // .dl-menu-btn: "⬇ Excel İndir ▾" menüsü (export_menu) — aynı altyapı
        const btn = e.target.closest('.pc-kebab, .dl-menu-btn');
        if (btn) {
            const dd = btn.nextElementSibling;
            const wasOpen = !dd.hidden;
            closeAllDropdowns();
            if (!wasOpen) {
                // Always use fixed positioning — avoids clipping by any overflow:auto ancestor
                const r = btn.getBoundingClientRect();
                dd.style.position = 'fixed';
                dd.style.top = (r.bottom + 4) + 'px';
                dd.style.right = (window.innerWidth - r.right) + 'px';
                dd.style.left = 'auto';
                dd.hidden = false;
                // Ekran dışına taşmasın: solda sola yasla, altta yukarı aç
                // (mobil alt çubuğun --bn-h kadar alanı da "ekran dışı" sayılır)
                const d = dd.getBoundingClientRect();
                if (d.left < 8) { dd.style.left = '8px'; dd.style.right = 'auto'; }
                if (d.bottom > ekranAlti() - 8 && r.top - d.height - 4 > 8) {
                    dd.style.top = (r.top - d.height - 4) + 'px';
                }
                // Buton alt çubuğa çok yakınsa yukarı açmak da yetmeyebilir —
                // son çare üst sınırı alt çubuğun hemen üstüne yasla
                const d2 = dd.getBoundingClientRect();
                if (d2.bottom > ekranAlti() - 8 && ekranAlti() - 8 - d2.height > 8) {
                    dd.style.top = (ekranAlti() - 8 - d2.height) + 'px';
                }
            }
        } else {
            closeAllDropdowns();
        }
    });
    // Close on scroll (fixed dropdowns stay in place otherwise)
    window.addEventListener('scroll', closeAllDropdowns, true);


    /* Progressive enhancement: without JS every section stays accessible. */
    (function () {
        var workspace = document.querySelector('[data-beyan-workspace]');
        var form = document.querySelector('[data-beyan-form]');
        if (!workspace || !form) return;
        var tabs = Array.from(workspace.querySelectorAll('[data-beyan-tab]'));
        var panels = Array.from(workspace.querySelectorAll('[data-beyan-panel]'));
        var tablist = workspace.querySelector('[data-beyan-tabs]');
        if (!tabs.length || !panels.length || !tablist) return;
        tablist.hidden = false;
        tablist.setAttribute('role', 'tablist');
        workspace.classList.add('beyan-workspace-ready');
        function activate(name, focus) {
            tabs.forEach(function (tab) {
                var active = tab.dataset.beyanTab === name;
                tab.setAttribute('role', 'tab');
                tab.setAttribute('aria-selected', String(active));
                tab.tabIndex = active ? 0 : -1;
                if (active && focus) tab.focus();
            });
            panels.forEach(function (panel) { panel.hidden = panel.dataset.beyanPanel !== name; });
        }
        panels.forEach(function (panel) {
            panel.id = panel.id || 'beyan-panel-' + panel.dataset.beyanPanel;
            panel.setAttribute('role', 'tabpanel');
            var tab = tabs.find(function (item) { return item.dataset.beyanTab === panel.dataset.beyanPanel; });
            if (tab) {
                tab.id = tab.id || 'beyan-tab-' + panel.dataset.beyanPanel;
                tab.setAttribute('aria-controls', panel.id);
                panel.setAttribute('aria-labelledby', tab.id);
            }
        });
        tabs.forEach(function (tab, index) {
            tab.addEventListener('click', function () { activate(tab.dataset.beyanTab); });
            tab.addEventListener('keydown', function (event) {
                var next = index;
                if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
                else if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
                else if (event.key === 'Home') next = 0;
                else if (event.key === 'End') next = tabs.length - 1;
                else return;
                event.preventDefault();
                activate(tabs[next].dataset.beyanTab, true);
            });
        });
        workspace.addEventListener('beyan:activate', function (event) { activate(event.detail || 'beyan'); });
        activate('beyan');
        var statusField = form.querySelector('[name="status"]');
        var analysisNote = form.querySelector('[name="analysis_note"]');
        function syncAnalysisNote() {
            if (statusField && analysisNote) analysisNote.required = statusField.value === 'red';
        }
        if (statusField) statusField.addEventListener('change', syncAnalysisNote);
        syncAnalysisNote();
        function reveal(target) {
            var panel = target.closest('[data-beyan-panel]');
            if (panel) activate(panel.dataset.beyanPanel);
            var ancestor = target.parentElement;
            while (ancestor && ancestor !== form) {
                if (ancestor.tagName === 'DETAILS') { ancestor.hidden = false; ancestor.open = true; }
                ancestor = ancestor.parentElement;
            }
        }
        var invalidPending = false;
        form.addEventListener('invalid', function (event) {
            reveal(event.target);
            if (invalidPending) return;
            invalidPending = true;
            setTimeout(function () {
                invalidPending = false;
                var first = form.querySelector(':invalid');
                if (first) { reveal(first); first.focus(); }
            }, 0);
        }, true);
        var dirty = false, submitting = false, baseline;
        var label = workspace.querySelector('[data-beyan-dirty]');
        function snapshot() {
            return JSON.stringify(Array.from(form.elements).filter(function (el) {
                return el.name && !['hidden', 'submit', 'button'].includes(el.type);
            }).map(function (el) { return [el.name, ['checkbox', 'radio'].includes(el.type) ? el.checked : el.value]; }));
        }
        function markDirty() {
            dirty = snapshot() !== baseline;
            if (label) { label.hidden = !dirty; label.textContent = 'Kaydedilmemiş değişiklikler'; }
        }
        baseline = snapshot();
        document.addEventListener('DOMContentLoaded', function () { baseline = snapshot(); });
        form.addEventListener('input', markDirty);
        form.addEventListener('change', markDirty);
        form.addEventListener('submit', function (event) { if (!event.defaultPrevented) submitting = true; });
        workspace.addEventListener('beyan:saved', function () { submitting = true; });
        window.addEventListener('beforeunload', function (event) {
            if (dirty && !submitting) { event.preventDefault(); event.returnValue = ''; }
        });
        function unmatchedVisibility() {
            var details = workspace.querySelector('[data-beyan-unmatched]');
            var field = form.querySelector('[name="unmatched_text"]');
            if (details && field) details.hidden = !field.value.trim();
        }
        form.addEventListener('input', unmatchedVisibility);
        unmatchedVisibility();
    })();

    /* WhatsApp parse review and selectable bulk import. */
    (function () {
        var parseBtn = document.querySelector('[data-beyan-parse-btn]');
        if (!parseBtn) return;
        var form = document.querySelector('[data-beyan-form]');
        var workspace = document.querySelector('[data-beyan-workspace]');
        var status = document.getElementById('beyanParseStatus');
        var bulk = document.getElementById('beyanBulkPreview');
        var baseUrl = parseBtn.dataset.baseUrl || '';
        var parseLabel = parseBtn.textContent.trim();
        var FIELDS = ['party_no', 'transport_type', 'line_type', 'company_name',
            'company_address', 'product_name', 'product_variety', 'pallet_count', 'gross_kg', 'net_kg',
            'crate_count', 'crate_type', 'exit_depot', 'buyer_name', 'contact_person', 'brand'];
        var LABELS = { party_no: 'Parti no', transport_type: 'Taşıma türü',
            line_type: 'Hat', company_name: 'Gümrük Adı', company_address: 'Gümrük Adresi', product_name: 'Ürün',
            product_variety: 'Çeşit', pallet_count: 'Palet', gross_kg: 'Brüt KG', net_kg: 'Net KG',
            crate_count: 'Kasa', crate_type: 'Kasa tipi', exit_depot: 'Çıkış deposu', buyer_name: 'Alıcı',
            contact_person: 'İlgili kişi', brand: 'Marka', raw_text: 'Kaynak metin', unmatched_text: 'Eşleşmeyen metin' };
        function activate(name) { if (workspace) workspace.dispatchEvent(new CustomEvent('beyan:activate', { detail: name })); }
        function show(type, text) {
            if (!status) return;
            status.className = 'beyan-parse-alert beyan-parse-alert-' + type;
            status.textContent = text;
            status.hidden = false;
        }
        function esc(value) { var el = document.createElement('div'); el.textContent = value == null ? '' : String(value); return el.innerHTML.replace(/"/g, '&quot;'); }
        function field(name) { return form ? form.querySelector('[name="' + name + '"]') : null; }
        function display(name, value) {
            if (['gross_kg', 'net_kg'].includes(name) && typeof value === 'number') {
                return value.toFixed(3).replace(/0+$/, '').replace(/\.$/, '').replace('.', ',');
            }
            return value == null ? '' : String(value);
        }
        function comparable(name, value) {
            value = String(value).trim();
            if (['gross_kg', 'net_kg'].includes(name)) return Number(value.replace(/\./g, '').replace(',', '.'));
            return value;
        }
        function apply(name, value) {
            var el = field(name);
            if (!el || el.matches(':disabled') || el.tagName === 'SELECT') return false;
            el.value = value;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        }
        function clearComparison() { var old = document.getElementById('beyanParseCompare'); if (old) old.remove(); }
        function fillSingle(decl, includeSource) {
            clearComparison();
            var values = Object.assign({}, (decl && decl.parsed) || {}), found = 0, applied = 0, conflicts = [];
            if (decl && decl.unmatched) values.unmatched_text = decl.unmatched;
            if (includeSource && decl && decl.raw_text) values.raw_text = decl.raw_text;
            FIELDS.concat(['unmatched_text', 'raw_text']).forEach(function (name) {
                if (values[name] === null || values[name] === undefined || values[name] === '') return;
                var el = field(name);
                if (!el || el.tagName === 'SELECT') return;
                var next = display(name, values[name]);
                found++;
                if (!el.value.trim()) { if (apply(name, next)) applied++; }
                else if (comparable(name, el.value) !== comparable(name, next)) conflicts.push({ name: name, current: el.value, next: next });
            });
            var message = found + ' alan bulundu; ' + applied + ' alan uygulandı; ' + conflicts.length + ' alan mevcut değerden farklı.';
            show(found ? 'ok' : 'warn', message);
            activate('beyan');
            var summary = workspace && workspace.querySelector('[data-beyan-parse-summary]');
            if (!summary && workspace) {
                summary = document.createElement('p'); summary.dataset.beyanParseSummary = '';
                summary.className = 'beyan-parse-alert beyan-parse-alert-warn';
                workspace.querySelector('[data-beyan-panel="beyan"]').prepend(summary);
            }
            if (summary) { summary.hidden = false; summary.textContent = message + (conflicts.length ? ' Kaynak metin sekmesinde karşılaştırabilirsiniz.' : ''); }
            if (!conflicts.length) return;
            var compare = document.createElement('div');
            compare.id = 'beyanParseCompare'; compare.className = 'beyan-parse-comparison';
            compare.innerHTML = '<strong>Mevcut değerleri karşılaştırın</strong><p>Yalnız işaretlediğiniz alanlar değiştirilecek.</p>' +
                conflicts.map(function (item, index) {
                    return '<label class="beyan-parse-compare-row"><input type="checkbox" data-beyan-compare="' + index + '"> <strong>' + LABELS[item.name] + '</strong><span>Mevcut: ' + esc(item.current) + '</span><span>Metinden: ' + esc(item.next) + '</span></label>';
                }).join('') + '<button type="button" class="btn btn-secondary" data-beyan-apply>Seçili alanları uygula</button>';
            (document.querySelector('[data-beyan-panel="kaynak"]') || status.parentNode).appendChild(compare);
            compare.querySelector('[data-beyan-apply]').addEventListener('click', function () {
                var count = 0;
                compare.querySelectorAll('[data-beyan-compare]:checked').forEach(function (checkbox) {
                    var item = conflicts[Number(checkbox.dataset.beyanCompare)];
                    if (apply(item.name, item.next)) count++;
                });
                var result = found + ' alan bulundu; ' + (applied + count) + ' alan uygulandı; ' + (conflicts.length - count) + ' farklı alan korundu.';
                show('ok', result); if (summary) summary.textContent = result;
                compare.remove(); activate('beyan');
            });
        }
        function manualSections() {
            var explicit = document.querySelectorAll('[data-beyan-single-section]');
            if (explicit.length) return Array.from(explicit);
            return Array.from(document.querySelectorAll('.beyan-section')).filter(function (section) { return !section.contains(parseBtn); });
        }
        function setSingleUI(visible) {
            if (workspace) {
                var singleSave = document.querySelector('#beyanSingleActions button[type=submit]');
                if (singleSave) singleSave.disabled = !visible;
                return;
            }
            manualSections().forEach(function (section) { section.style.display = visible ? '' : 'none'; });
            var actions = document.getElementById('beyanSingleActions');
            if (actions) actions.style.display = visible ? '' : 'none';
        }
        function restoreSingle() { if (bulk) { bulk.hidden = true; bulk.innerHTML = ''; } setSingleUI(true); }
        function requestId() {
            if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
            return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) + '-' + Math.random().toString(36).slice(2);
        }
        function renderBulk(declarations, csrf) {
            if (!bulk) return;
            clearComparison(); setSingleUI(false); activate('kaynak');
            bulk.innerHTML = '<div class="beyan-bulk-head"><strong>' + declarations.length + ' beyan algılandı</strong>' +
                '<button type="button" class="btn btn-sm btn-ghost" data-beyan-bulk-cancel>Önizlemeyi kapat</button></div>' +
                '<p class="muted">Kaydedilecek satırları seçin. Ayrıntıları açarak bilgileri düzeltebilirsiniz.</p><div class="beyan-bulk-list">' +
                declarations.map(function (decl, index) {
                    var parsed = decl.parsed || {};
                    var edits = FIELDS.map(function (name) {
                        return '<label class="form-group">' + LABELS[name] + '<input class="form-control" data-bulk-field="' + name + '" value="' + esc(display(name, parsed[name])) + '"></label>';
                    }).join('');
                    return '<div class="beyan-bulk-item" data-bulk-row="' + index + '"><div class="beyan-bulk-item-head"><label>' +
                        '<input type="checkbox" data-bulk-selected checked> #' + (index + 1) + ' ' + esc(parsed.party_no || '(parti no yok)') + '</label><span>' +
                        esc(parsed.product_name || '') + '</span></div><details><summary>Ayrıntılar ve düzeltme</summary><div class="beyan-form-grid beyan-bulk-edit-grid">' + edits + '</div>' +
                        '<label class="form-group">Eşleşmeyen metin<textarea class="form-control" data-bulk-field="unmatched_text">' + esc(decl.unmatched || '') + '</textarea></label>' +
                        '<details><summary>Kaynak metin</summary><pre>' + esc(decl.raw_text || '') + '</pre></details>' +
                        '<button type="button" class="btn btn-sm btn-secondary" data-bulk-single>Bu beyanı tekli düzenle</button></details></div>';
                }).join('') + '</div><div class="beyan-bulk-actions"><button type="button" class="btn btn-primary" data-beyan-bulk-save></button></div>' +
                '<div class="beyan-bulk-status" role="status" hidden></div>';
            bulk.hidden = false; bulk.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            var rows = Array.from(bulk.querySelectorAll('[data-bulk-row]'));
            var save = bulk.querySelector('[data-beyan-bulk-save]'), result = bulk.querySelector('.beyan-bulk-status');
            var frozenPayload = null, id = requestId(), locked = false;
            function record(row) {
                var decl = declarations[Number(row.dataset.bulkRow)], rec = Object.assign({}, decl.parsed || {});
                row.querySelectorAll('[data-bulk-field]').forEach(function (input) { rec[input.dataset.bulkField] = input.value; });
                rec.raw_text = decl.raw_text || ''; return rec;
            }
            function selected() { return rows.filter(function (row) { return row.querySelector('[data-bulk-selected]').checked; }); }
            function updateCount() { save.textContent = 'Seçilenleri kaydet (' + selected().length + ')'; save.disabled = !selected().length; }
            bulk.onchange = updateCount; updateCount();
            bulk.querySelector('[data-beyan-bulk-cancel]').addEventListener('click', restoreSingle);
            rows.forEach(function (row) { row.querySelector('[data-bulk-single]').addEventListener('click', function () {
                if (locked) return;
                var rec = record(row); restoreSingle(); fillSingle({ parsed: rec, raw_text: rec.raw_text, unmatched: rec.unmatched_text }, true);
            }); });
            save.addEventListener('click', function () {
                if (!selected().length) return;
                if (!frozenPayload) frozenPayload = selected().map(record);
                locked = true;
                bulk.querySelectorAll('input, textarea, button').forEach(function (el) { el.disabled = true; });
                parseBtn.disabled = true;
                save.textContent = 'Kaydediliyor…'; result.hidden = false; result.className = 'beyan-bulk-status'; result.textContent = '';
                fetch(baseUrl + 'beyan_bulk_save.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf: csrf, declarations: frozenPayload, request_id: id })
                }).then(function (response) { return response.json(); }).then(function (res) {
                    if (res.ok) {
                        if (workspace) workspace.dispatchEvent(new Event('beyan:saved'));
                        result.className = 'beyan-bulk-status ok'; result.textContent = res.created + ' beyan eklendi, yönlendiriliyor…';
                        window.location.href = baseUrl + 'beyanlar.php';
                    } else {
                        locked = false; frozenPayload = null; id = requestId(); parseBtn.disabled = false;
                        bulk.querySelectorAll('input, textarea, button').forEach(function (el) { el.disabled = false; });
                        updateCount(); result.className = 'beyan-bulk-status err';
                        result.textContent = 'Hata: ' + (res.error || 'Kayıt başarısız.');
                        (res.rows || []).forEach(function (item) {
                            var row = selected()[item.row - 1];
                            if (row) { row.querySelector('details').open = true; var error = document.createElement('p');
                                error.className = 'beyan-parse-alert beyan-parse-alert-error'; error.textContent = (item.errors || []).join(' '); row.appendChild(error); }
                        });
                    }
                }).catch(function () {
                    // Retry the identical payload/id after an uncertain transport failure.
                    save.disabled = false; save.textContent = 'Aynı kaydı yeniden dene'; result.className = 'beyan-bulk-status err';
                    result.textContent = 'Yanıt alınamadı. Aynı işlem kimliğiyle güvenli yeniden deneyebilirsiniz.';
                });
            });
        }
        parseBtn.addEventListener('click', function (event) {
            event.preventDefault(); var raw = field('raw_text');
            if (!raw || !raw.value.trim()) { show('warn', 'Lütfen önce WhatsApp metnini girin.'); return; }
            var csrf = field('csrf'); parseBtn.disabled = true; parseBtn.textContent = 'Ayrıştırılıyor…';
            if (status) status.hidden = true;
            restoreSingle(); clearComparison(); var body = new FormData();
            if (csrf) body.append('csrf', csrf.value); body.append('text', raw.value);
            fetch(baseUrl + 'beyan_parse.php', { method: 'POST', body: body }).then(function (response) { return response.json(); }).then(function (data) {
                if (!data.ok) { show('error', data.error || 'Ayrıştırma başarısız.'); return; }
                var declarations = data.declarations || [];
                if (bulk && declarations.length > 1) {
                    show('ok', declarations.length + ' beyan algılandı — seçerek toplu kaydedin.'); renderBulk(declarations, csrf ? csrf.value : '');
                } else { fillSingle(declarations[0] || { parsed: data.parsed, unmatched: data.unmatched }); }
            }).catch(function (error) { show('error', 'Bağlantı hatası: ' + error.message); }).then(function () {
                parseBtn.disabled = false; parseBtn.textContent = parseLabel;
            });
        });
    })();

    const formEl = document.getElementById('recordForm');
    if (!formEl) return;

    /* ── Statik veriler ── */
    const MATERIALS  = JSON.parse(document.getElementById('materialsData').textContent  || '{}');
    const KASA_LIST  = JSON.parse(document.getElementById('kasaCinsiData').textContent  || '[]');
    const PALET_LIST = JSON.parse(document.getElementById('paletTipiData').textContent  || '[]');
    const TYPE_LABELS = JSON.parse(document.getElementById('materialTypesData').textContent || '{}');
    /* Palete giydirilebilen sarf malzeme türleri — TEK KAYNAK PHP'de
       (config/helpers.php → pallet_material_types()). Önceden burada sabit bir
       liste vardı; yeni lookup türleri (alıcı, telefon, şoför, plaka…)
       eklendikçe malzeme listesine SIZIYORDU. */
    const PALLET_MAT_TYPES = JSON.parse(
        (document.getElementById('palletMaterialTypesData') || {}).textContent || '[]'
    );
    const palletsInit = JSON.parse(document.getElementById('palletsInit').textContent   || '[]');
    const DEPO_LIST  = JSON.parse((document.getElementById('depoListData') || {}).textContent || '[]');
    const URUN_LIST  = JSON.parse((document.getElementById('urunListData') || {}).textContent || '[]');

    /* Materials tip grupları */
    const matsByType = {};
    Object.keys(MATERIALS).forEach(id => {
        const m = MATERIALS[id];
        if (!matsByType[m.type]) matsByType[m.type] = [];
        matsByType[m.type].push({ id: parseInt(id, 10), name: m.name, unit: m.unit });
    });

    /* ── Yardımcı fonksiyonlar ── */
    function fmtKg(n) {
        if (!isFinite(n)) n = 0;
        n = Math.round(n);
        if (n < 0) return '-' + String(Math.abs(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }
    function fmtUnitKg(n) {
        n = parseFloat(n) || 0;
        const s = (Math.round(n * 1000) / 1000).toFixed(3).replace(/0+$/, '').replace(/\.$/, '');
        return s.replace('.', ',');
    }
    /* Sayıyı input-uyumlu formata çevir: virgül ondalık, binler ayracı yok ("1045,273")
       PHP num() noktayı binler ayracı sanır — hidden input'ta bu format gerekli */
    function fmtInput(n) {
        if (!n || !isFinite(n)) return '';
        const s = (Math.round(n * 1000) / 1000).toFixed(3).replace(/0+$/, '').replace(/\.$/, '');
        return s.replace('.', ',');
    }
    function parseNum(v) {
        if (v === null || v === undefined || v === '') return 0;
        if (typeof v === 'number') return isFinite(v) ? v : 0;
        let s = String(v).replace(/\s/g, '');
        const hasComma = s.includes(',');
        const hasDot   = s.includes('.');
        if (hasComma && hasDot) {
            if (s.lastIndexOf('.') > s.lastIndexOf(',')) {
                s = s.replaceAll(',', '');          // "13,960.5" → İngilizce: virgül=binler
            } else {
                s = s.replaceAll('.', '').replace(',', '.'); // "13.960,5" → Türkçe: nokta=binler
            }
        } else if (hasComma) {
            s = s.replace(',', '.');                // "13960,5" → Türkçe ondalık
        }
        const n = parseFloat(s);
        return isFinite(n) ? n : 0;
    }
    function parseInt2(v) {
        const n = parseInt(String(v || '0').replace(/[^\d-]/g, ''), 10);
        return isFinite(n) ? n : 0;
    }
    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* ── Pano (clipboard) yardımcıları — Excel'den kopyala/yapıştır ──
       Excel bir sütunu kopyalarken satırları \n, hücreleri \t ile ayırır.
       Türkçe CSV kopyalamalarında ayraç ";" olabilir. */
    function isNumericToken(s) {
        s = String(s == null ? '' : s).trim();
        if (s === '') return false;
        return /^-?[\d.,\s\u00a0]*\d[\d.,\s\u00a0]*$/.test(s);
    }
    /* Bilinen sütun başlıkları — "Size" gibi sayısal olmayan başlıkları
       gerçek veriden ayırmak için (yoksa ilk size değeri başlık sanılır). */
    const HEADER_WORDS = /^(#|s[ıi]ra|s[ıi]ra\s*no|palet|palet\s*no|palet\s*say[ıi]s[ıi]|no|kasa|kasa\s*adeti|kasa\s*cinsi|adet|br[üu]t|br[üu]t\s*kg|kg|kilo|a[ğg][ıi]rl[ıi]k|net|net\s*kg|dara|dara\s*kg|size|boy|boyut|[üu]r[üu]n|[üu]r[üu]n\s*cinsi|cins|depo|a[çc][ıi]klama|not)$/i;
    function looksLikeHeaderRow(cells) {
        const dolu = cells.filter(c => c !== '');
        return dolu.length > 0 && dolu.every(c => HEADER_WORDS.test(c));
    }
    /* Pano metnini satır/hücre ızgarasına çevirir.
       ARADAKİ boş satırlar KORUNUR — sütunlar ayrı ayrı yapıştırıldığında
       hizalama kaymasın diye. Yalnız baştaki/sondaki boş satırlar ve
       (varsa) başlık satırı atılır. */
    function parseClipGrid(text) {
        const out = [];
        String(text == null ? '' : text).replace(/\r\n?/g, '\n').split('\n').forEach(line => {
            let cells;
            if (line.indexOf('\t') !== -1)      cells = line.split('\t');
            else if (line.indexOf(';') !== -1)  cells = line.split(';');
            else                                cells = [line];
            out.push(cells.map(c => String(c).replace(/\u00a0/g, ' ').trim()));
        });
        const bos = r => r.every(c => c === '');
        while (out.length && bos(out[0]))              out.shift();
        while (out.length && bos(out[out.length - 1])) out.pop();
        // Başlık satırını at: ya bilinen bir başlık sözcüğü, ya da kendisi
        // sayı değilken hemen altındaki satır sayı olan bir satır.
        if (out.length > 1 &&
            (looksLikeHeaderRow(out[0]) ||
             (!out[0].some(isNumericToken) && out[1].some(isNumericToken)))) {
            out.shift();
        }
        return out;
    }

    /* ── Durum ── */
    let pallets = [];
    let editingIdx = -1; /* -1 = yeni, 0+ = mevcut */

    /* ── DOM referansları ── */
    const cardContainer = document.getElementById('palletList');
    const pmOverlay  = document.getElementById('pmOverlay');
    const pmTitle    = document.getElementById('pmTitle');
    const pmDara     = document.getElementById('pmDara');
    const pmNet      = document.getElementById('pmNet');
    const pmPaletNo  = document.getElementById('pmPaletNo');
    const pmKasaAdeti = document.getElementById('pmKasaAdeti');
    const pmSize     = document.getElementById('pmSize');
    const pmBrutKg   = document.getElementById('pmBrutKg');
    const pmKasaCinsi = document.getElementById('pmKasaCinsi');
    const pmPaletTipi = document.getElementById('pmPaletTipi');
    const pmUrunCinsi = document.getElementById('pmUrunCinsi');
    const pmDepo     = document.getElementById('pmDepo');
    const pmMatList  = document.getElementById('pmMaterialsList');

    /* ── Select seçenekleri oluştur (ID tabanlı — kasa/palet) ── */
    function buildOptions(items, selectedId, placeholder) {
        let html = `<option value="">${placeholder}</option>`;
        items.forEach(it => {
            const sel = String(it.id) === String(selectedId) ? ' selected' : '';
            html += `<option value="${it.id}" data-unit="${it.unit}"${sel}>${escHtml(it.name)} (${fmtUnitKg(it.unit)} kg)</option>`;
        });
        return html;
    }

    /* ── Select seçenekleri oluştur (metin tabanlı — depo/firma/bölge/ürün) ── */
    function buildTextOptions(names, selectedVal, placeholder) {
        let html = `<option value="">${placeholder}</option>`;
        if (selectedVal && !names.includes(selectedVal)) {
            html += `<option value="${escHtml(selectedVal)}" selected>${escHtml(selectedVal)}</option>`;
        }
        names.forEach(name => {
            const sel = name === selectedVal ? ' selected' : '';
            html += `<option value="${escHtml(name)}"${sel}>${escHtml(name)}</option>`;
        });
        return html;
    }

    /* ── Modal dara/net hesapla ── */
    /* Dara = yalnızca kasa + palet. Ek malzemeler (şapka/köşebent/şerit vb.)
       stok takibi içindir; dara/net hesabına DAHİL EDİLMEZ. */
    function calcModalDara() {
        const ka = parseInt2(pmKasaAdeti.value);
        const kasaOpt  = pmKasaCinsi.options[pmKasaCinsi.selectedIndex];
        const paletOpt = pmPaletTipi.options[pmPaletTipi.selectedIndex];
        const kasaUnit  = parseNum(kasaOpt?.dataset.unit  || 0);
        const paletUnit = parseNum(paletOpt?.dataset.unit || 0);
        return ka * kasaUnit + paletUnit;
    }
    function updateModalCalc() {
        const brut = parseNum(pmBrutKg.value);
        const dara = calcModalDara();
        const net  = Math.max(0, brut - dara);
        pmDara.textContent = fmtKg(dara);
        pmNet.textContent  = fmtKg(net);
    }

    /* ── Modal malzeme satırı ── */
    function addModalMaterial(data) {
        const d = data || {};
        const row = document.createElement('div');
        row.className = 'pm-mat-row material-row';

        let opts = '<option value="">-- malzeme seçiniz --</option>';
        Object.keys(matsByType).sort().forEach(t => {
            // İzinli sarf türü değilse gösterme. TEK İSTİSNA: bu satırda ZATEN
            // seçili olan malzeme — eski/hatalı veri sessizce kaybolmasın,
            // kullanıcı görüp değiştirebilsin.
            const izinli = PALLET_MAT_TYPES.length === 0 || PALLET_MAT_TYPES.includes(t);
            const seciliBurada = matsByType[t].some(it => String(it.id) === String(d.material_id));
            if (!izinli && !seciliBurada) return;
            const label = TYPE_LABELS[t] || t;
            opts += `<optgroup label="${escHtml(label)}">`;
            matsByType[t].forEach(it => {
                const sel = String(it.id) === String(d.material_id) ? ' selected' : '';
                // İzinsiz türde yalnız seçili olanı göster (liste kirlenmesin)
                if (!izinli && !sel) return;
                opts += `<option value="${it.id}" data-unit="${it.unit}"${sel}>${escHtml(it.name)} (${fmtUnitKg(it.unit)} kg)</option>`;
            });
            opts += '</optgroup>';
        });

        row.innerHTML = `
            <select class="mat-select">${opts}</select>
            <input type="text" inputmode="decimal" class="num mat-qty"
                   value="${escHtml(d.quantity || 1)}" placeholder="Adet">
            <button type="button" class="btn btn-sm btn-ghost btn-icon mat-remove" title="Sil">×</button>
        `;
        pmMatList.appendChild(row);
        row.querySelector('.mat-select').addEventListener('change', updateModalCalc);
        row.querySelector('.mat-qty').addEventListener('input', updateModalCalc);
        row.querySelector('.mat-remove').addEventListener('click', () => {
            row.remove();
            updateModalCalc();
        });
    }

    /* ── Modal aç ── */
    function openModal(idx) {
        editingIdx = idx;
        const isNew = idx === -1;
        pmTitle.textContent = isNew ? 'Yeni Palet Ekle' : `Palet ${idx + 1} Düzenle`;

        /* Alanları sıfırla */
        pmPaletNo.value  = '';
        pmKasaAdeti.value = '';
        pmSize.value     = '';
        pmBrutKg.value   = '';
        pmUrunCinsi.value = '';
        pmDepo.value     = '';
        pmMatList.innerHTML = '';
        [pmKasaAdeti, pmBrutKg, pmKasaCinsi, pmPaletTipi].forEach(el => el.classList.remove('error'));

        if (isNew) {
            const last = pallets[pallets.length - 1];
            const lsKasa  = localStorage.getItem('pm_kasa_cinsi_id') || '';
            const lsPalet = localStorage.getItem('pm_palet_tipi_id') || '';
            const lsDepo  = localStorage.getItem('pm_depo') || '';
            pmPaletNo.value = String(pallets.length + 1);
            pmKasaCinsi.innerHTML = buildOptions(KASA_LIST, last?.kasa_cinsi_id || lsKasa, '-- kasa cinsi seçiniz --');
            pmPaletTipi.innerHTML = buildOptions(PALET_LIST, last?.palet_tipi_id || lsPalet, '-- palet tipi seçiniz --');
            const depoDefault = (document.getElementById('genelDepo') || {}).value || last?.depo || lsDepo;
            pmDepo.innerHTML = buildTextOptions(DEPO_LIST, depoDefault, '-- Depo seçiniz --');
            const urunDefault = (document.getElementById('genelUrun') || {}).value || last?.urun_cinsi || '';
            pmUrunCinsi.innerHTML = buildTextOptions(URUN_LIST, urunDefault, '-- ürün cinsi seçiniz --');
            if (last) {
                pmSize.value = last.size || '';
                if (Array.isArray(last.materials)) last.materials.forEach(m => addModalMaterial(m));
            }
        } else {
            const p = pallets[idx];
            pmPaletNo.value  = p.palet_no || '';
            pmKasaAdeti.value = p.kasa_adeti !== '' && p.kasa_adeti != null ? p.kasa_adeti : '';
            pmSize.value     = p.size || '';
            pmBrutKg.value   = (p.brut_kg > 0) ? fmtInput(p.brut_kg) : '';
            pmKasaCinsi.innerHTML = buildOptions(KASA_LIST, p.kasa_cinsi_id, '-- kasa cinsi seçiniz --');
            pmPaletTipi.innerHTML = buildOptions(PALET_LIST, p.palet_tipi_id, '-- palet tipi seçiniz --');
            pmDepo.innerHTML = buildTextOptions(DEPO_LIST, p.depo || '', '-- Depo seçiniz --');
            pmUrunCinsi.innerHTML = buildTextOptions(URUN_LIST, p.urun_cinsi || '', '-- ürün cinsi seçiniz --');
            if (Array.isArray(p.materials)) p.materials.forEach(m => addModalMaterial(m));
        }

        updateModalCalc();
        pmOverlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        setTimeout(() => pmKasaAdeti.focus(), 80);
    }

    function closeModal() {
        pmOverlay.style.display = 'none';
        document.body.style.overflow = '';
    }

    /* ── Modal kaydet ── */
    function saveModal() {
        const ka = parseInt2(pmKasaAdeti.value);
        const br = parseNum(pmBrutKg.value);
        const kc = pmKasaCinsi.value;
        const pt = pmPaletTipi.value;

        [pmKasaAdeti, pmBrutKg, pmKasaCinsi, pmPaletTipi].forEach(el => el.classList.remove('error'));
        let err = false;
        if (!ka) { pmKasaAdeti.classList.add('error'); err = true; }
        if (!br) { pmBrutKg.classList.add('error');   err = true; }
        if (!kc) { pmKasaCinsi.classList.add('error'); err = true; }
        if (!pt) { pmPaletTipi.classList.add('error'); err = true; }
        if (err) { alert('Kasa adeti, Brüt KG, Kasa Cinsi ve Palet Tipi zorunludur.'); return; }

        const materials = [];
        pmMatList.querySelectorAll('.pm-mat-row').forEach(mr => {
            const mid = mr.querySelector('.mat-select').value;
            const qty = parseNum(mr.querySelector('.mat-qty').value);
            if (mid) materials.push({ material_id: mid, quantity: qty });
        });

        const p = {
            palet_no:     pmPaletNo.value.trim() || String(editingIdx === -1 ? pallets.length + 1 : editingIdx + 1),
            kasa_adeti:   ka,
            size:         pmSize.value.trim(),
            brut_kg:      br,
            kasa_cinsi_id: kc,
            palet_tipi_id: pt,
            urun_cinsi:   pmUrunCinsi.value.trim(),
            depo:         pmDepo.value.trim(),
            materials:    materials,
        };

        const isNew = editingIdx === -1;
        if (isNew) {
            pallets.push(p);
        } else {
            pallets[editingIdx] = p;
        }

        try {
            if (kc) localStorage.setItem('pm_kasa_cinsi_id', kc);
            if (pt) localStorage.setItem('pm_palet_tipi_id', pt);
            if (p.depo) localStorage.setItem('pm_depo', p.depo);
        } catch (_) {}

        renderCards();
        recomputeTotals();
        closeModal();

        if (isNew) {
            const foot = document.querySelector('.form-foot');
            if (foot) {
                setTimeout(() => foot.scrollIntoView({ behavior: 'smooth', block: 'end' }), 80);
            }
        }
    }

    /* ── Palet dara (kart ve toplam için) ── */
    function calcPalletDara(p) {
        const ka = parseInt2(p.kasa_adeti);
        const kasaItem  = KASA_LIST.find(k => String(k.id) === String(p.kasa_cinsi_id));
        const paletItem = PALET_LIST.find(k => String(k.id) === String(p.palet_tipi_id));
        // Malzeme daraları net hesabına dahil edilmez
        return ka * (kasaItem?.unit || 0) + (paletItem?.unit || 0);
    }

    /* ── Kart listesini render et ── */
    function renderCards() {
        if (!pallets.length) {
            cardContainer.innerHTML = `
                <div class="pc-empty" id="pcEmpty" role="button" tabindex="0">
                    <div class="pc-empty-icon">📦</div>
                    <div class="pc-empty-text">Henüz palet yok.<br>Eklemek için tıklayın veya <strong>+ Yeni Palet Ekle</strong> butonunu kullanın.</div>
                </div>`;
            document.getElementById('pcEmpty')?.addEventListener('click', () => openModal(-1));
            return;
        }

        cardContainer.innerHTML = '';

        // Anomaly detection: flag values >30% from average (only with 2+ pallets)
        const _n = pallets.length;
        let avgKasa = 0, avgBrut = 0;
        if (_n > 1) {
            pallets.forEach(p => { avgKasa += parseInt2(p.kasa_adeti); avgBrut += parseNum(p.brut_kg); });
            avgKasa /= _n;
            avgBrut /= _n;
        }

        pallets.forEach((p, i) => {
            const dara = calcPalletDara(p);
            const net  = Math.max(0, parseNum(p.brut_kg) - dara);
            const kasaName  = KASA_LIST.find(k => String(k.id) === String(p.kasa_cinsi_id))?.name || '';
            const paletName = PALET_LIST.find(k => String(k.id) === String(p.palet_tipi_id))?.name || '';

            const ka = parseInt2(p.kasa_adeti);
            const br = parseNum(p.brut_kg);
            const kasaWarn = avgKasa > 0 && Math.abs(ka - avgKasa) / avgKasa > 0.30;
            const brutWarn = avgBrut > 0 && Math.abs(br - avgBrut) / avgBrut > 0.30;

            const card = document.createElement('div');
            card.className = 'pallet-card';

            const metaParts = [kasaName, paletName].filter(Boolean);
            const matCount  = p.materials?.length || 0;

            const _done = !!p.islendi;
            card.innerHTML = `
                <div class="pc-num-wrap">
                    <div class="pc-num${_done ? ' pc-num-done' : ''}">${i + 1}</div>
                    <button type="button" class="pc-isle-btn${_done ? ' active' : ''}" data-isle="${i}" title="${_done ? 'Raporlandı — geri al' : 'Raporla'}">✓</button>
                </div>
                <div class="pc-body">
                    <div class="pc-title">Palet ${escHtml(p.palet_no || (i + 1))}${p.size ? ' · ' + escHtml(p.size) : ''}</div>
                    <div class="pc-stats">
                        <span><strong${kasaWarn ? ' class="pc-warn"' : ''}>${p.kasa_adeti}</strong> kasa</span>
                        <span>Brüt <strong${brutWarn ? ' class="pc-warn"' : ''}>${fmtKg(Math.round(br))}</strong></span>
                    </div>
                    <div class="pc-stats pc-stats-dara">
                        <span>Dara <strong>${fmtKg(Math.round(dara))}</strong></span>
                        <span>Net <strong class="strong">${fmtKg(Math.round(net))}</strong></span>
                    </div>
                    ${metaParts.length ? `<div class="pc-meta">${escHtml(metaParts.join(' · '))}${matCount ? ' · +' + matCount + ' malzeme' : ''}</div>` : ''}
                </div>
                <div class="pc-kebab-wrap">
                    <button type="button" class="pc-kebab" title="İşlemler">⋮</button>
                    <div class="pc-dropdown" hidden>
                        <button type="button" data-edit="${i}">✎ Düzenle</button>
                        <button type="button" class="pc-drop-danger" data-del="${i}">✕ Sil</button>
                    </div>
                </div>`;

            card.querySelector('[data-edit]').addEventListener('click', () => openModal(i));
            card.querySelector('[data-isle]').addEventListener('click', () => {
                pallets[i].islendi = !pallets[i].islendi;
                renderCards();
                recomputeTotals();
            });
            card.querySelector('[data-del]').addEventListener('click', () => {
                if (confirm(`Palet ${i + 1} silinecek. Emin misiniz?`)) {
                    pallets.splice(i, 1);
                    renderCards();
                    recomputeTotals();
                }
            });
            cardContainer.appendChild(card);
        });

        // Alt + yeni palet ekle butonu
        const addBottom = document.createElement('button');
        addBottom.type = 'button';
        addBottom.className = 'btn btn-primary pallet-add-bottom';
        addBottom.textContent = '+ Yeni Palet Ekle';
        addBottom.addEventListener('click', () => openModal(-1));
        cardContainer.appendChild(addBottom);
    }

    /* ── Alt toplamlar ── */
    function recomputeTotals() {
        let totKasa = 0, totBrut = 0, totDara = 0, totNet = 0;
        let isAdet=0,  isKasa=0,  isBrut=0,  isDara=0,  isNet=0;
        let nisAdet=0, nisKasa=0, nisBrut=0, nisDara=0, nisNet=0;
        pallets.forEach(p => {
            const br   = parseNum(p.brut_kg);
            const dara = calcPalletDara(p);
            const net  = Math.max(0, br - dara);
            const ka   = parseInt2(p.kasa_adeti);
            totKasa += ka;
            totBrut += br;
            totDara += dara;
            totNet  += net;
            if (p.islendi) {
                isAdet++; isKasa+=ka; isBrut+=br; isDara+=dara; isNet+=net;
            } else {
                nisAdet++; nisKasa+=ka; nisBrut+=br; nisDara+=dara; nisNet+=net;
            }
        });
        document.getElementById('totKasa').textContent = String(totKasa);
        document.getElementById('totBrut').textContent = fmtKg(Math.round(totBrut));
        document.getElementById('totDara').textContent = fmtKg(Math.round(totDara));
        document.getElementById('totNet').textContent  = fmtKg(Math.round(totNet));
        // İşaretli / İşaretsiz ayrım (sadece edit modunda mevcut)
        const elIsAdet = document.getElementById('isAdet');
        if (elIsAdet) {
            document.getElementById('isAdet').textContent  = String(isAdet);
            document.getElementById('isKasa').textContent  = String(isKasa);
            document.getElementById('isBrut').textContent  = fmtKg(Math.round(isBrut));
            document.getElementById('isDara').textContent  = fmtKg(Math.round(isDara));
            document.getElementById('isNet').textContent   = fmtKg(Math.round(isNet));
            document.getElementById('nisAdet').textContent = String(nisAdet);
            document.getElementById('nisKasa').textContent = String(nisKasa);
            document.getElementById('nisBrut').textContent = fmtKg(Math.round(nisBrut));
            document.getElementById('nisDara').textContent = fmtKg(Math.round(nisDara));
            document.getElementById('nisNet').textContent  = fmtKg(Math.round(nisNet));
        }
    }

    /* ── Hidden input'lar oluştur (form submit'te çağrılır) ── */
    function generateHiddenInputs() {
        formEl.querySelectorAll('.pallet-hidden').forEach(el => el.remove());
        const frag = document.createDocumentFragment();
        pallets.forEach((p, idx) => {
            const n = idx + 1;
            const scalar = {
                palet_no: p.palet_no, kasa_adeti: p.kasa_adeti, size: p.size,
                // fmtInput: PHP num() nokta=binler ayracı sayar; virgüllü gönder
                brut_kg: (p.brut_kg > 0) ? fmtInput(p.brut_kg) : '',
                kasa_cinsi_id: p.kasa_cinsi_id,
                palet_tipi_id: p.palet_tipi_id, urun_cinsi: p.urun_cinsi, depo: p.depo,
                islendi: p.islendi ? 1 : 0,
            };
            Object.keys(scalar).forEach(k => {
                const inp = document.createElement('input');
                inp.type = 'hidden'; inp.className = 'pallet-hidden';
                inp.name = `pallets[${n}][${k}]`;
                inp.value = scalar[k] ?? '';
                frag.appendChild(inp);
            });
            if (Array.isArray(p.materials)) {
                p.materials.forEach((m, mi) => {
                    ['material_id', 'quantity'].forEach(k => {
                        const inp = document.createElement('input');
                        inp.type = 'hidden'; inp.className = 'pallet-hidden';
                        // AÇIK index (mi) ŞART: "materials[][material_id]" + "materials[][quantity]"
                        // PHP'de AYRI elemanlara bölünür → quantity kaybolur, qty=1 default olur.
                        inp.name = `pallets[${n}][materials][${mi}][${k}]`;
                        let val = m[k] ?? '';
                        if (k === 'quantity') {
                            // num() '.'yi binlik ayırıcı sayar → tam sayı düz, ondalık virgüllü gönder
                            const q = parseNum(val);
                            val = Number.isInteger(q) ? String(q) : String(q).replace('.', ',');
                        }
                        inp.value = val;
                        frag.appendChild(inp);
                    });
                });
            }
        });
        formEl.appendChild(frag);
    }

    /* ── Event listeners ── */
    // Genel Depo değişince tüm paletlere yansıt
    document.getElementById('genelDepo')?.addEventListener('change', function () {
        const newDepo = this.value;
        pallets.forEach(p => { p.depo = newDepo; });
    });
    // Genel Ürün değişince tüm paletlere yansıt
    document.getElementById('genelUrun')?.addEventListener('change', function () {
        const newUrun = this.value;
        pallets.forEach(p => { p.urun_cinsi = newUrun; });
    });

    document.getElementById('addPalletBtn')?.addEventListener('click', () => openModal(-1));
    document.getElementById('pmClose')?.addEventListener('click', closeModal);
    document.getElementById('pmCancel')?.addEventListener('click', closeModal);
    document.getElementById('pmSave')?.addEventListener('click', saveModal);
    document.getElementById('pmAddMaterial')?.addEventListener('click', () => addModalMaterial());

    /* Canlı hesap */
    [pmKasaAdeti, pmBrutKg, pmKasaCinsi, pmPaletTipi].forEach(el => {
        if (!el) return;
        el.addEventListener('input',  updateModalCalc);
        el.addEventListener('change', updateModalCalc);
    });

    /* Overlay tıklama → kapat */
    pmOverlay?.addEventListener('click', e => { if (e.target === pmOverlay) closeModal(); });

    /* Escape → kapat */
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && pmOverlay?.style.display !== 'none') closeModal();
    });

    /* Modal içi Enter → sonraki alan */
    pmOverlay?.addEventListener('keydown', e => {
        if (e.key !== 'Enter') return;
        if (e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA') return;
        const focusable = Array.from(pmOverlay.querySelectorAll('input, select'));
        const i = focusable.indexOf(e.target);
        if (i >= 0 && i < focusable.length - 1) {
            e.preventDefault();
            focusable[i + 1].focus();
        }
    });

    /* Form gönder */
    formEl.addEventListener('submit', e => {
        if (!pallets.length) {
            if (!confirm('Hiç palet satırı yok. Yine de kaydedilsin mi?')) {
                e.preventDefault();
                return;
            }
        }
        generateHiddenInputs();
    });

    /* ── Toplu Düzenle — Excel Tablo ── */
    (function () {
        const panel          = document.getElementById('topluPanel');
        const acBtn          = document.getElementById('topluAcBtn');
        const kapatBtn       = document.getElementById('topluKapat');
        const satirEkleBtn   = document.getElementById('topluSatirEkle');
        const kaydetBtn      = document.getElementById('topluListeyeEkle');
        const tbody          = document.getElementById('topluTbody');
        const countEl        = document.getElementById('topluCount');
        if (!panel || !acBtn || !tbody) return;

        var origPallets = []; // panel açılırken anlık kopya

        /* Son tablodaki satırdan ya da son paletten veri al */
        function refData() {
            const rows = tbody.querySelectorAll('tr');
            if (rows.length) {
                const last = rows[rows.length - 1];
                return {
                    palet_sayisi:  last.querySelector('[data-key=palet_sayisi]').value,
                    kasa_adeti:    last.querySelector('[data-key=kasa_adeti]').value,
                    size:          last.querySelector('[data-key=size]').value,
                    kasa_cinsi_id: last.querySelector('[data-key=kasa_cinsi_id]').value,
                    palet_tipi_id: last.querySelector('[data-key=palet_tipi_id]').value,
                    depo:          last.querySelector('[data-key=depo]').value,
                    urun_cinsi:    last.querySelector('[data-key=urun_cinsi]').value,
                };
            }
            return pallets[pallets.length - 1] || {};
        }

        /* Satır dara/net hesapla */
        function calcRow(tr) {
            const ps       = Math.max(1, parseInt2(tr.querySelector('[data-key=palet_sayisi]').value) || 1);
            const ka       = parseInt2(tr.querySelector('[data-key=kasa_adeti]').value);
            const kcSel    = tr.querySelector('[data-key=kasa_cinsi_id]');
            const ptSel    = tr.querySelector('[data-key=palet_tipi_id]');
            const kasaUnit = parseNum(kcSel.options[kcSel.selectedIndex]?.dataset.unit || 0);
            const palUnit  = parseNum(ptSel.options[ptSel.selectedIndex]?.dataset.unit || 0);
            const dara     = ps * palUnit + ka * kasaUnit;
            const net      = Math.max(0, parseNum(tr.querySelector('[data-key=brut_kg]').value) - dara);
            tr.querySelector('.tp-dara').textContent = fmtKg(Math.round(dara));
            tr.querySelector('.tp-net').textContent  = fmtKg(Math.round(net));
        }

        /* Satır no güncelle (1'den başlar) */
        function updateNos() {
            tbody.querySelectorAll('tr').forEach((tr, i) => {
                tr.querySelector('.tp-no').textContent = i + 1;
            });
            snapshotFirstRow();
        }

        /* Satır oluştur — from: pallet objesi, origIdx: orijinal indeks (-1 = yeni) */
        function addRow(from, origIdx) {
            from = from || {};
            const rowCount = tbody.querySelectorAll('tr').length;
            const tr = document.createElement('tr');
            if (origIdx !== undefined && origIdx >= 0) tr.dataset.origIdx = String(origIdx);

            // #
            const noTd = document.createElement('td');
            noTd.className = 'tp-no-td';
            const noSpan = document.createElement('span');
            noSpan.className = 'tp-no';
            noSpan.textContent = rowCount + 1;
            noTd.appendChild(noSpan);
            tr.appendChild(noTd);

            // Palet Sayisi
            const psTd = document.createElement('td');
            const psInp = document.createElement('input');
            psInp.type = 'number'; psInp.inputMode = 'numeric'; psInp.min = '1';
            psInp.dataset.key = 'palet_sayisi';
            psInp.className = 'tp-cell';
            psInp.style.width = '52px';
            psInp.value = (from.palet_sayisi != null && from.palet_sayisi !== '') ? from.palet_sayisi : '1';
            psTd.appendChild(psInp);
            tr.appendChild(psTd);

            // Kasa Adeti
            const kaTd = document.createElement('td');
            const kaInp = document.createElement('input');
            kaInp.type = 'number'; kaInp.inputMode = 'numeric';
            kaInp.dataset.key = 'kasa_adeti';
            kaInp.className = 'tp-cell';
            kaInp.value = from.kasa_adeti != null ? from.kasa_adeti : '';
            kaTd.appendChild(kaInp);
            tr.appendChild(kaTd);

            // Brüt KG
            const brutTd = document.createElement('td');
            const brutInp = document.createElement('input');
            brutInp.type = 'text'; brutInp.inputMode = 'decimal';
            brutInp.dataset.key = 'brut_kg';
            brutInp.className = 'tp-cell';
            brutInp.value = (from.brut_kg > 0) ? fmtInput(from.brut_kg) : '';
            brutTd.appendChild(brutInp);
            tr.appendChild(brutTd);

            // Size
            const sizeTd = document.createElement('td');
            const sizeInp = document.createElement('input');
            sizeInp.type = 'text';
            sizeInp.dataset.key = 'size';
            sizeInp.className = 'tp-cell';
            sizeInp.value = from.size || '';
            sizeTd.appendChild(sizeInp);
            tr.appendChild(sizeTd);

            // Kasa Cinsi
            const kcTd = document.createElement('td');
            const kcSel = document.createElement('select');
            kcSel.dataset.key = 'kasa_cinsi_id';
            kcSel.className = 'tp-cell';
            kcSel.innerHTML = buildOptions(KASA_LIST, from.kasa_cinsi_id || '', '—');
            kcTd.appendChild(kcSel);
            tr.appendChild(kcTd);

            // Palet Tipi
            const ptTd = document.createElement('td');
            const ptSel = document.createElement('select');
            ptSel.dataset.key = 'palet_tipi_id';
            ptSel.className = 'tp-cell';
            ptSel.innerHTML = buildOptions(PALET_LIST, from.palet_tipi_id || '', '—');
            ptTd.appendChild(ptSel);
            tr.appendChild(ptTd);

            // Depo
            const depTd = document.createElement('td');
            const depSel = document.createElement('select');
            depSel.dataset.key = 'depo';
            depSel.className = 'tp-cell';
            depSel.innerHTML = buildTextOptions(DEPO_LIST, from.depo || (document.getElementById('genelDepo') || {}).value || '', '—');
            depTd.appendChild(depSel);
            tr.appendChild(depTd);

            // Ürün Cinsi
            const urunTd2 = document.createElement('td');
            const urunInp2 = document.createElement('select');
            urunInp2.dataset.key = 'urun_cinsi';
            urunInp2.className = 'tp-cell';
            urunInp2.innerHTML = buildTextOptions(URUN_LIST,
                from.urun_cinsi || (document.getElementById('genelUrun') || {}).value || '', '—');
            urunTd2.appendChild(urunInp2);
            tr.appendChild(urunTd2);

            // Dara
            const daraTd = document.createElement('td');
            daraTd.className = 'tp-dara tp-num';
            daraTd.textContent = '—';
            tr.appendChild(daraTd);

            // Net
            const netTd = document.createElement('td');
            netTd.className = 'tp-net tp-num';
            netTd.textContent = '—';
            tr.appendChild(netTd);

            // Sil
            const silTd = document.createElement('td');
            const silBtn = document.createElement('button');
            silBtn.type = 'button'; silBtn.className = 'btn btn-sm btn-ghost tp-del';
            silBtn.textContent = '✕';
            silBtn.addEventListener('click', () => { tr.remove(); updateNos(); });
            silTd.appendChild(silBtn);
            tr.appendChild(silTd);

            [psInp, kaInp, brutInp, kcSel, ptSel].forEach(el => {
                el.addEventListener('input',  () => calcRow(tr));
                el.addEventListener('change', () => calcRow(tr));
            });

            tbody.appendChild(tr);
            calcRow(tr);
            snapshotFirstRow();
            return tr;
        }

        /* Panel aç: mevcut paletleri yükle */
        acBtn.addEventListener('click', () => {
            if (panel.style.display !== 'none') { panel.style.display = 'none'; return; }
            ['excelPanel', 'yapistirPanel'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.style.display = 'none';
            });

            origPallets = pallets.map(p => Object.assign({}, p, {
                materials: Array.isArray(p.materials) ? p.materials.map(m => Object.assign({}, m)) : []
            }));
            tbody.innerHTML = '';
            if (pallets.length) {
                pallets.forEach((p, i) => addRow(p, i));
                countEl.textContent = pallets.length + ' palet';
            } else {
                addRow(refData(), -1);
                countEl.textContent = '';
            }
            panel.style.display = '';
            setTimeout(() => {
                const first = tbody.querySelector('[data-key=kasa_adeti]');
                if (first) first.focus();
            }, 60);
        });

        kapatBtn.addEventListener('click', () => { panel.style.display = 'none'; });

        /* + Satır */
        satirEkleBtn.addEventListener('click', () => {
            const tr = addRow(refData(), -1);
            setTimeout(() => tr.querySelector('[data-key=kasa_adeti]').focus(), 40);
        });

        /* ── 1. satır → aşağıya yayılım ──
           "Kasa cinsi / palet tipi / depo / ürün 1. satırda ne ise öyle olsun":
           1. satırdaki bir seçim değişince, o ana kadar AYNI değere sahip olan
           alt satırlar da güncellenir. Bilerek farklı yapılmış satırlara dokunulmaz. */
        const SYNC_KEYS  = ['kasa_cinsi_id', 'palet_tipi_id', 'depo', 'urun_cinsi'];
        const prevFirst  = {};

        function setSelectValue(sel, val) {
            sel.value = val;
            if (sel.value !== val) {            // seçenek listede yoksa ekle
                const o = document.createElement('option');
                o.value = val; o.textContent = val || '—';
                sel.appendChild(o);
                sel.value = val;
            }
        }

        function snapshotFirstRow() {
            const first = tbody.querySelector('tr');
            SYNC_KEYS.forEach(k => {
                const el = first ? first.querySelector('[data-key=' + k + ']') : null;
                prevFirst[k] = el ? el.value : '';
            });
        }

        tbody.addEventListener('change', function (e) {
            const el = e.target;
            if (!el || !el.dataset || SYNC_KEYS.indexOf(el.dataset.key) < 0) return;
            if (el.closest('tr') !== tbody.querySelector('tr')) { snapshotFirstRow(); return; }
            const key = el.dataset.key;
            const oldVal = prevFirst[key];
            const newVal = el.value;
            prevFirst[key] = newVal;
            if (oldVal === newVal) return;
            Array.from(tbody.querySelectorAll('tr')).slice(1).forEach(row => {
                const cell = row.querySelector('[data-key=' + key + ']');
                if (cell && cell.value === oldVal) {
                    setSelectValue(cell, newVal);
                    calcRow(row);
                }
            });
        });

        /* ── Yapıştır (fill-down) ──
           Excel'den kopyalanan çok satırlı veri bir hücreye yapıştırılınca
           sıra bozulmadan aşağıya doğru dolar; satır yetmezse yenisi açılır.
           Yeni satırların kasa cinsi / palet tipi / depo / ürün değerleri
           1. satırdan kopyalanır. */
        const PASTE_KEYS = ['palet_sayisi', 'kasa_adeti', 'brut_kg', 'size'];

        /* 1. satırın seçim alanları — yeni satırlar için şablon */
        function firstRowTemplate() {
            const first = tbody.querySelector('tr');
            if (first) {
                return {
                    palet_sayisi:  '1',
                    kasa_adeti:    '',
                    brut_kg:       '',
                    size:          '',
                    kasa_cinsi_id: first.querySelector('[data-key=kasa_cinsi_id]').value,
                    palet_tipi_id: first.querySelector('[data-key=palet_tipi_id]').value,
                    depo:          first.querySelector('[data-key=depo]').value,
                    urun_cinsi:    first.querySelector('[data-key=urun_cinsi]').value,
                };
            }
            const p = pallets[0] || {};
            return {
                palet_sayisi:  '1',
                kasa_adeti:    '',
                brut_kg:       '',
                size:          '',
                kasa_cinsi_id: p.kasa_cinsi_id || '',
                palet_tipi_id: p.palet_tipi_id || '',
                depo:          p.depo || '',
                urun_cinsi:    p.urun_cinsi || '',
            };
        }

        function setPasteCell(tr, key, raw) {
            const el = tr.querySelector('[data-key=' + key + ']');
            if (!el) return;
            const s = String(raw == null ? '' : raw).trim();
            if (s === '') return;                       // boş hücre → mevcut değeri koru
            if (key === 'brut_kg')      el.value = fmtInput(parseNum(s));
            else if (key === 'palet_sayisi') el.value = String(Math.max(1, parseInt2(s) || 1));
            else if (key === 'size')    el.value = s;
            else                        el.value = String(parseInt2(s));
        }

        /* grid'i startCell'den başlayarak aşağı + sağa doğru uygula */
        function applyPasteGrid(startCell, grid) {
            const kIdx = PASTE_KEYS.indexOf(startCell.dataset.key);
            if (kIdx < 0) return 0;
            const startRowIdx = Array.from(tbody.querySelectorAll('tr')).indexOf(startCell.closest('tr'));
            if (startRowIdx < 0) return 0;

            const tpl = firstRowTemplate();
            let lastTr = null;
            grid.forEach((cells, i) => {
                let tr = tbody.querySelectorAll('tr')[startRowIdx + i];
                if (!tr) tr = addRow(tpl, -1);
                cells.forEach((v, j) => {
                    const key = PASTE_KEYS[kIdx + j];
                    if (key) setPasteCell(tr, key, v);
                });
                calcRow(tr);
                lastTr = tr;
            });
            updateNos();
            const total = tbody.querySelectorAll('tr').length;
            countEl.textContent = total + ' palet';
            if (lastTr) lastTr.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            return grid.length;
        }

        /* clipboardData vermeyen tarayıcılar için yedek yol:
           input tek satıra düzleştirir → boşluklardan böl */
        function pasteFallback(cell) {
            const before = cell.value;
            setTimeout(() => {
                const after = cell.value;
                if (after === before) return;
                const toks = after.trim().split(/[\s\u00a0]+/).filter(Boolean);
                if (toks.length < 2 || !toks.every(isNumericToken)) return;
                cell.value = before;
                applyPasteGrid(cell, toks.map(t => [t]));
                flashPasteInfo(toks.length);
            }, 0);
        }

        function flashPasteInfo(n) {
            if (!countEl) return;
            const total = tbody.querySelectorAll('tr').length;
            countEl.textContent = '📋 ' + n + ' satır yapıştırıldı · ' + total + ' palet';
            countEl.classList.add('tp-paste-flash');
            setTimeout(() => {
                countEl.classList.remove('tp-paste-flash');
                countEl.textContent = tbody.querySelectorAll('tr').length + ' palet';
            }, 2500);
        }

        tbody.addEventListener('paste', function (e) {
            const cell = e.target;
            if (!cell || !cell.matches || !cell.matches('input[data-key]')) return;
            const cd = e.clipboardData || window.clipboardData;
            const text = cd ? cd.getData('text') : '';
            if (!text) { pasteFallback(cell); return; }
            const grid = parseClipGrid(text);
            if (!grid.length) return;
            // Tek hücre → tarayıcının normal yapıştırması
            if (grid.length === 1 && grid[0].length === 1) return;
            e.preventDefault();
            const n = applyPasteGrid(cell, grid);
            if (n) flashPasteInfo(n);
        });

        /* Enter → aynı sütunda alt satıra */
        tbody.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            const cell = e.target;
            const rows = Array.from(tbody.querySelectorAll('tr'));
            const curRow = cell.closest('tr');
            const rowIdx = rows.indexOf(curRow);
            const cells = Array.from(curRow.querySelectorAll('.tp-cell'));
            const colIdx = cells.indexOf(cell);

            let nextRow = rows[rowIdx + 1];
            if (!nextRow) nextRow = addRow(refData(), -1);

            const nextCells = Array.from(nextRow.querySelectorAll('.tp-cell'));
            setTimeout(() => (nextCells[colIdx] || nextCells[0]).focus(), 20);
        });

        /* Kaydet: pallets[] tablodaki verilerle güncelle */
        kaydetBtn.addEventListener('click', () => {
            pallets = [];
            tbody.querySelectorAll('tr').forEach((tr, _i) => {
                const oIdx = tr.dataset.origIdx !== undefined ? parseInt(tr.dataset.origIdx, 10) : -1;
                const origBase = (oIdx >= 0 && origPallets[oIdx])
                    ? Object.assign({}, origPallets[oIdx], {
                        materials: Array.isArray(origPallets[oIdx].materials)
                            ? origPallets[oIdx].materials.map(m => Object.assign({}, m)) : []
                      })
                    : { size: '', urun_cinsi: '', materials: [] };

                const ps            = Math.max(1, parseInt2(tr.querySelector('[data-key=palet_sayisi]').value) || 1);
                const totalKasa     = parseInt2(tr.querySelector('[data-key=kasa_adeti]').value);
                const totalBrut     = parseNum(tr.querySelector('[data-key=brut_kg]').value);
                const kasa_cinsi_id = tr.querySelector('[data-key=kasa_cinsi_id]').value;
                const palet_tipi_id = tr.querySelector('[data-key=palet_tipi_id]').value;
                const depo          = tr.querySelector('[data-key=depo]').value;
                const urun_cinsi    = tr.querySelector('[data-key=urun_cinsi]').value.trim() || origBase.urun_cinsi || '';
                const size          = tr.querySelector('[data-key=size]').value.trim();

                if (ps <= 1) {
                    origBase.palet_no      = String(pallets.length + 1);
                    origBase.kasa_adeti    = totalKasa;
                    origBase.brut_kg       = totalBrut;
                    origBase.kasa_cinsi_id = kasa_cinsi_id;
                    origBase.palet_tipi_id = palet_tipi_id;
                    origBase.depo          = depo;
                    origBase.urun_cinsi    = urun_cinsi;
                    origBase.size          = size;
                    pallets.push(origBase);
                } else {
                    // Palet sayısı > 1: kasa ve brütü N satıra dağıt
                    const baseKasa  = Math.floor(totalKasa / ps);
                    const extraRows = totalKasa - baseKasa * ps; // ilk N satır +1 kasa alır
                    let brutAccum   = 0;
                    const brutPerPalet = Math.round(totalBrut / ps * 1000) / 1000;
                    for (let j = 0; j < ps; j++) {
                        const isLast   = (j === ps - 1);
                        const thisKasa = (j < extraRows) ? baseKasa + 1 : baseKasa;
                        const thisBrut = isLast
                            ? Math.round((totalBrut - brutAccum) * 1000) / 1000
                            : brutPerPalet;
                        if (!isLast) brutAccum += brutPerPalet;
                        pallets.push({
                            palet_no:      String(pallets.length + 1),
                            size:          size,
                            urun_cinsi:    urun_cinsi,
                            materials:     [],
                            kasa_adeti:    thisKasa,
                            brut_kg:       thisBrut,
                            kasa_cinsi_id: kasa_cinsi_id,
                            palet_tipi_id: palet_tipi_id,
                            depo:          depo,
                        });
                    }
                }
            });
            renderCards();
            recomputeTotals();
            panel.style.display = 'none';
        });
    })();

    /* ── Excel İmport ── */
    (function () {
        const panel       = document.getElementById('excelPanel');
        const acBtn       = document.getElementById('excelAcBtn');
        const kapatBtn    = document.getElementById('excelKapat');
        const fileInp     = document.getElementById('excelFile');
        const dropZone    = document.getElementById('excelDropZone');
        const previewWrap = document.getElementById('excelPreviewWrap');
        const tbody       = document.getElementById('excelTbody');
        const rowCountEl  = document.getElementById('excelRowCount');
        const ekleBtn     = document.getElementById('excelListeyeEkle');
        const kcSel       = document.getElementById('excelKasaCinsi');
        const ptSel       = document.getElementById('excelPaletTipi');
        const depSel      = document.getElementById('excelDepo');
        const urunInp     = document.getElementById('excelUrunCinsi');
        if (!panel || !acBtn) return;

        // Toplu alanların seçeneklerini doldur
        function initSelects() {
            const lp = pallets[pallets.length - 1];
            kcSel.innerHTML  = buildOptions(KASA_LIST,  lp?.kasa_cinsi_id || '', '— seçiniz —');
            ptSel.innerHTML  = buildOptions(PALET_LIST, lp?.palet_tipi_id || '', '— seçiniz —');
            depSel.innerHTML = buildTextOptions(DEPO_LIST,
                lp?.depo || (document.getElementById('genelDepo') || {}).value || '', '— seçiniz —');
            if (urunInp) urunInp.innerHTML = buildTextOptions(URUN_LIST,
                lp?.urun_cinsi || (document.getElementById('genelUrun') || {}).value || '', '— seçiniz —');
        }

        // Sütun adı normalize
        function normKey(s) {
            return String(s).toLowerCase()
                .replace(/[\s_\-]/g, '')
                .replace(/ğ/g,'g').replace(/ü/g,'u').replace(/ş/g,'s')
                .replace(/ı/g,'i').replace(/ö/g,'o').replace(/ç/g,'c');
        }
        const KEY_MAP = {
            'paletno':'palet_no','no':'palet_no','#':'palet_no','sira':'palet_no',
            'kasaadeti':'kasa_adeti','kasa':'kasa_adeti','adet':'kasa_adeti','kasaadet':'kasa_adeti',
            'brutkg':'brut_kg','brut':'brut_kg','kg':'brut_kg','brutkilogram':'brut_kg',
            'size':'size','boyut':'size',
        };

        // Excel parse
        function parseWorkbook(wb) {
            const ws = wb.Sheets[wb.SheetNames[0]];
            const raw = window.XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });
            if (raw.length < 2) return [];
            const headers = raw[0].map(h => normKey(String(h)));
            const colOf = {};
            headers.forEach((h, i) => { if (KEY_MAP[h]) colOf[KEY_MAP[h]] = i; });
            const rows = [];
            for (let r = 1; r < raw.length; r++) {
                const row = raw[r];
                if (!row.some(v => v !== '' && v !== null && v !== undefined)) continue;
                const g = key => (colOf[key] !== undefined ? row[colOf[key]] : '') ?? '';
                rows.push({
                    palet_no:   String(g('palet_no') || rows.length + 1).trim(),
                    kasa_adeti: String(g('kasa_adeti')).replace(',', '.').trim(),
                    brut_kg:    String(g('brut_kg')).replace(',', '.').trim(),
                    size:       String(g('size') || '').trim(),
                });
            }
            return rows;
        }

        // Önizleme tablosunu çiz
        function renderPreview(rows) {
            tbody.innerHTML = '';
            rows.forEach((row, i) => {
                const tr = document.createElement('tr');
                tr.innerHTML =
                    '<td class="tp-no-td"><span class="tp-no">' + (pallets.length + i + 1) + '</span></td>' +
                    '<td><input type="text"   class="tp-cell" data-f="palet_no"   value="' + escHtml(row.palet_no)   + '" style="width:55px"></td>' +
                    '<td><input type="number" class="tp-cell" data-f="kasa_adeti" value="' + escHtml(row.kasa_adeti) + '" style="width:75px"></td>' +
                    '<td><input type="text"   class="tp-cell" data-f="brut_kg"    value="' + escHtml(row.brut_kg)    + '" inputmode="decimal" style="width:85px"></td>' +
                    '<td><button type="button" class="btn btn-sm btn-ghost tp-del">✕</button></td>';
                tr.querySelector('.tp-del').addEventListener('click', function () {
                    tr.remove();
                    rowCountEl.textContent = tbody.querySelectorAll('tr').length;
                });
                tbody.appendChild(tr);
            });
            rowCountEl.textContent = rows.length;
            previewWrap.style.display = rows.length ? '' : 'none';
        }

        function processFile(file) {
            if (!window.XLSX) { alert('Excel kütüphanesi yüklenmedi, sayfayı yenileyin.'); return; }
            const reader = new FileReader();
            reader.onload = function (e) {
                try {
                    const wb = window.XLSX.read(e.target.result, { type: 'array' });
                    const rows = parseWorkbook(wb);
                    if (!rows.length) { alert('Veri bulunamadı. Başlık satırlarını kontrol edin (Palet No, Kasa Adeti, Brüt KG).'); return; }
                    renderPreview(rows);
                } catch (err) { alert('Dosya okunamadı: ' + err.message); }
            };
            reader.readAsArrayBuffer(file);
        }

        acBtn.addEventListener('click', () => {
            if (panel.style.display !== 'none') { panel.style.display = 'none'; return; }
            // diğer panelleri kapat
            ['topluPanel', 'yapistirPanel'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.style.display = 'none';
            });
            initSelects();
            tbody.innerHTML = '';
            previewWrap.style.display = 'none';
            panel.style.display = '';
        });
        kapatBtn.addEventListener('click', () => { panel.style.display = 'none'; });

        dropZone.addEventListener('click', () => fileInp.click());
        dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
        dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
        dropZone.addEventListener('drop', e => {
            e.preventDefault(); dropZone.classList.remove('drag-over');
            if (e.dataTransfer.files[0]) processFile(e.dataTransfer.files[0]);
        });
        fileInp.addEventListener('change', () => {
            if (fileInp.files[0]) processFile(fileInp.files[0]);
            fileInp.value = '';
        });

        ekleBtn.addEventListener('click', () => {
            const trs = Array.from(tbody.querySelectorAll('tr'));
            if (!trs.length) return;
            const kc   = kcSel.value;
            const pt   = ptSel.value;
            const dep  = depSel.value;
            const urun = urunInp.value.trim();
            const lastP = pallets[pallets.length - 1];
            trs.forEach(tr => {
                const g = f => tr.querySelector('[data-f=' + f + ']').value;
                pallets.push({
                    palet_no:      g('palet_no') || String(pallets.length + 1),
                    kasa_adeti:    parseInt2(g('kasa_adeti')),
                    size:          '',
                    brut_kg:       parseNum(g('brut_kg')),
                    kasa_cinsi_id: kc,
                    palet_tipi_id: pt,
                    urun_cinsi:    urun,
                    depo:          dep,
                    materials:     Array.isArray(lastP?.materials) ? lastP.materials.map(m => ({...m})) : [],
                });
            });
            renderCards();
            recomputeTotals();
            panel.style.display = 'none';
        });
    })();

    /* ── Yapıştır Paneli — Excel sütunlarını kopyala/yapıştır ──
       Mobil öncelikli: hücreye uzun basıp yapıştırmak yerine büyük
       textarea'lara yapıştırılır, satır sırası korunur. */
    (function () {
        const panel    = document.getElementById('yapistirPanel');
        const acBtn    = document.getElementById('yapistirAcBtn');
        if (!panel || !acBtn) return;
        const kapatBtn = document.getElementById('ypKapat');
        const temizBtn = document.getElementById('ypTemizle');
        const taKasa   = document.getElementById('ypKasa');
        const taBrut   = document.getElementById('ypBrut');
        const taSize   = document.getElementById('ypSize');
        const infoKasa = document.getElementById('ypKasaInfo');
        const infoBrut = document.getElementById('ypBrutInfo');
        const infoSize = document.getElementById('ypSizeInfo');
        const BOXES    = [taKasa, taBrut, taSize];   // yapıştırmada soldan sağa sıra
        const kcSel    = document.getElementById('ypKasaCinsi');
        const ptSel    = document.getElementById('ypPaletTipi');
        const depSel   = document.getElementById('ypDepo');
        const urunSel  = document.getElementById('ypUrun');
        const uyariEl  = document.getElementById('ypUyari');
        const onizWrap = document.getElementById('ypOnizlemeWrap');
        const onizBody = document.getElementById('ypTbody');
        const ozetEl   = document.getElementById('ypOzet');
        const countEl  = document.getElementById('ypCount');
        const rowCntEl = document.getElementById('ypRowCount');
        const uygulaBtn = document.getElementById('ypUygula');

        const MAX_PREVIEW = 300;   // çok uzun listelerde DOM'u şişirme

        /* Referans palet = 1. palet (yoksa genel alanlar) */
        function refPallet() { return pallets[0] || {}; }

        function initSelects() {
            const rp = refPallet();
            kcSel.innerHTML  = buildOptions(KASA_LIST,  rp.kasa_cinsi_id || '', '— seçiniz —');
            ptSel.innerHTML  = buildOptions(PALET_LIST, rp.palet_tipi_id || '', '— seçiniz —');
            depSel.innerHTML = buildTextOptions(DEPO_LIST,
                rp.depo || (document.getElementById('genelDepo') || {}).value || '', '— seçiniz —');
            urunSel.innerHTML = buildTextOptions(URUN_LIST,
                rp.urun_cinsi || (document.getElementById('genelUrun') || {}).value || '', '— seçiniz —');
        }

        /* Textarea içeriğini satır dizisine çevir.
           Sadece SONDAKİ boş satırlar atılır — aradaki boşluklar korunur, yoksa
           sütunlar arasındaki hizalama kayar (ör. size bazı satırlarda boş). */
        function lines(ta) {
            const arr = String(ta.value || '').replace(/\r\n?/g, '\n').split('\n')
                .map(s => s.replace(/\u00a0/g, ' ').trim());
            while (arr.length && arr[arr.length - 1] === '') arr.pop();
            return arr;
        }
        const has = v => v != null && v !== '';

        /* Çok sütunlu veri yapıştırılırsa sütunlar soldan sağa kutulara dağıtılır:
           kasa kutusuna 3 sütun → kasa / brüt / size. */
        function attachPaste(ta) {
            ta.addEventListener('paste', function (e) {
                const cd = e.clipboardData || window.clipboardData;
                const text = cd ? cd.getData('text') : '';
                if (!text) { setTimeout(refresh, 0); return; }
                const grid = parseClipGrid(text);
                if (!grid.length) return;
                // Tek değer → normal yapıştırma (kutunun içeriğini silme)
                if (grid.length === 1 && grid[0].length === 1) { setTimeout(refresh, 0); return; }
                e.preventDefault();
                const idx = BOXES.indexOf(ta);
                const colCount = grid.reduce((m, r) => Math.max(m, r.length), 0);
                for (let c = 0; c < colCount; c++) {
                    const box = BOXES[idx + c];
                    if (!box) break;                                  // sağda kutu kalmadı
                    const col = grid.map(r => r[c] || '');
                    if (c > 0 && col.every(v => v === '')) continue;   // tamamen boş sütunu yazma
                    box.value = col.join('\n');
                }
                refresh();
            });
            ta.addEventListener('input', refresh);
        }

        function unitOf(sel) {
            const o = sel.options[sel.selectedIndex];
            return parseNum((o && o.dataset ? o.dataset.unit : 0) || 0);
        }

        /* Kutulardan hizalı satır listesi üret.
           Size tek değerse tüm satırlara uygulanır (ör. tüm paletler "60/70"). */
        function collect() {
            const kasa = lines(taKasa);
            const brut = lines(taBrut);
            const size = lines(taSize);
            const n = Math.max(kasa.length, brut.length, size.length);
            const tekSize = (size.length === 1 && n > 1) ? size[0] : null;
            const rows = [];
            for (let i = 0; i < n; i++) {
                rows.push({
                    kasa: kasa[i], brut: brut[i],
                    size: tekSize != null ? tekSize : size[i],
                    // Kasa ve brüt birlikte boşsa bu satır palet olmaz (araya kaçmış boş satır)
                    atla: !has(kasa[i]) && !has(brut[i]),
                });
            }
            return { rows, kasa, brut, size, tekSize };
        }

        function refresh() {
            const { rows, kasa, brut, size, tekSize } = collect();
            infoKasa.textContent = kasa.length + ' satır';
            infoBrut.textContent = brut.length + ' satır';
            infoSize.textContent = tekSize != null ? 'tümüne uygulanır'
                                 : (size.length ? size.length + ' satır' : 'boş');

            const gecerli = rows.filter(r => !r.atla);
            rowCntEl.textContent = gecerli.length;
            countEl.textContent  = gecerli.length ? gecerli.length + ' satır hazır' : '';

            /* Uyarılar */
            const uyarilar = [];
            if (kasa.length && brut.length && kasa.length !== brut.length) {
                uyarilar.push('⚠ Kasa adeti <b>' + kasa.length + '</b> satır, brüt KG <b>' + brut.length +
                              '</b> satır — eşleşmiyor. Eksik hücreler boş bırakılacak.');
            }
            if (tekSize == null && size.length && size.length !== rows.length) {
                uyarilar.push('⚠ Size <b>' + size.length + '</b> satır, diğer sütunlar <b>' + rows.length +
                              '</b> satır — eşleşmeyen satırların size değeri boş kalacak.');
            }
            const atlanan = rows.length - gecerli.length;
            if (atlanan > 0) {
                uyarilar.push('ℹ Kasa ve brütü boş olan <b>' + atlanan + '</b> satır atlanacak.');
            }
            const bozuk = [];
            rows.forEach((r, i) => {
                if (r.atla) return;
                if (has(r.kasa) && !isNumericToken(r.kasa)) bozuk.push((i + 1) + '. satır kasa: "' + escHtml(r.kasa) + '"');
                if (has(r.brut) && !isNumericToken(r.brut)) bozuk.push((i + 1) + '. satır brüt: "' + escHtml(r.brut) + '"');
            });
            if (bozuk.length) {
                uyarilar.push('⚠ Sayı olarak okunamayan değerler 0 kabul edilir: ' +
                              bozuk.slice(0, 5).join(', ') + (bozuk.length > 5 ? ' …' : ''));
            }
            uyariEl.innerHTML = uyarilar.join('<br>');
            uyariEl.style.display = uyarilar.length ? '' : 'none';

            /* Önizleme */
            onizBody.innerHTML = '';
            if (!gecerli.length) {
                onizWrap.style.display = 'none';
                uygulaBtn.disabled = true;
                return;
            }
            const kasaUnit = unitOf(kcSel);
            const palUnit  = unitOf(ptSel);
            let tKasa = 0, tBrut = 0, tDara = 0, tNet = 0, no = 0;
            const frag = document.createDocumentFragment();
            for (let i = 0; i < rows.length; i++) {
                const r = rows[i];
                const tr = document.createElement('tr');
                if (r.atla) {
                    if (no < MAX_PREVIEW) {
                        tr.className = 'yp-satir-atlandi';
                        tr.innerHTML = '<td class="tp-no-td"><span class="tp-no">·</span></td>' +
                                       '<td colspan="5" class="yp-bos">boş satır — atlanacak</td>';
                        frag.appendChild(tr);
                    }
                    continue;
                }
                no++;
                const ka = parseInt2(r.kasa || 0);
                const br = parseNum(r.brut || 0);
                const da = ka * kasaUnit + palUnit;
                const ne = Math.max(0, br - da);
                tKasa += ka; tBrut += br; tDara += da; tNet += ne;
                if (no > MAX_PREVIEW) continue;
                tr.innerHTML =
                    '<td class="tp-no-td"><span class="tp-no">' + no + '</span></td>' +
                    '<td>' + (has(r.kasa) ? fmtKg(ka)  : '<span class="yp-bos">—</span>') + '</td>' +
                    '<td>' + (has(r.brut) ? fmtKg(br)  : '<span class="yp-bos">—</span>') + '</td>' +
                    '<td class="yp-size-td">' + (has(r.size) ? escHtml(r.size) : '<span class="yp-bos">—</span>') + '</td>' +
                    '<td class="tp-num">' + fmtKg(da) + '</td>' +
                    '<td class="tp-num">' + fmtKg(ne) + '</td>';
                // Net ≤ 0 yalnızca brüt girilmişken hata sayılır
                if (has(r.brut) && ne <= 0) tr.classList.add('yp-satir-hata');
                frag.appendChild(tr);
            }
            onizBody.appendChild(frag);
            if (no > MAX_PREVIEW) {
                const tr = document.createElement('tr');
                tr.innerHTML = '<td colspan="6" class="muted" style="text-align:center;padding:6px">' +
                               '… ve ' + (no - MAX_PREVIEW) + ' satır daha</td>';
                onizBody.appendChild(tr);
            }
            ozetEl.innerHTML =
                '<span>Palet <strong>' + no + '</strong></span>' +
                '<span>Kasa <strong>' + fmtKg(tKasa) + '</strong></span>' +
                '<span>Brüt <strong>' + fmtKg(tBrut) + '</strong></span>' +
                '<span>Dara <strong>' + fmtKg(tDara) + '</strong></span>' +
                '<span>Net <strong>' + fmtKg(tNet) + '</strong></span>';
            onizWrap.style.display = '';
            uygulaBtn.disabled = false;
        }

        [kcSel, ptSel, depSel, urunSel].forEach(s => s.addEventListener('change', () => {
            s.classList.remove('error');
            refresh();
        }));
        BOXES.forEach(attachPaste);

        function temizle() {
            BOXES.forEach(b => { b.value = ''; });
            refresh();
        }

        acBtn.addEventListener('click', () => {
            if (panel.style.display !== 'none') { panel.style.display = 'none'; return; }
            ['topluPanel', 'excelPanel'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.style.display = 'none';
            });
            initSelects();
            temizle();
            panel.style.display = '';
            panel.scrollIntoView({ block: 'nearest' });
            setTimeout(() => taKasa.focus(), 80);
        });
        kapatBtn.addEventListener('click', () => { panel.style.display = 'none'; });
        temizBtn.addEventListener('click', temizle);

        uygulaBtn.addEventListener('click', () => {
            const gecerli = collect().rows.filter(r => !r.atla);
            const n = gecerli.length;
            if (!n) return;

            /* Zorunlu alanlar — kayıt sırasında sunucu da arıyor, burada uyar */
            let eksik = false;
            [[kcSel, 'Kasa Cinsi'], [ptSel, 'Palet Tipi'], [depSel, 'Depo']].forEach(([el]) => {
                if (!el.value) { el.classList.add('error'); eksik = true; }
                else el.classList.remove('error');
            });
            if (eksik) {
                alert('Kasa Cinsi, Palet Tipi ve Depo seçilmelidir.');
                return;
            }

            const mode = (panel.querySelector('input[name=ypMode]:checked') || {}).value || 'ekle';
            if (mode === 'degistir' && pallets.length) {
                if (!confirm('Mevcut ' + pallets.length + ' palet silinip ' + n + ' yeni palet oluşturulacak. Onaylıyor musunuz?')) return;
            }

            /* Malzemeler 1. paletten kopyalanır (Excel Yükle ile aynı davranış) */
            const rp   = refPallet();
            const mats = Array.isArray(rp.materials) ? rp.materials : [];

            if (mode === 'degistir') pallets.length = 0;

            gecerli.forEach(r => {
                pallets.push({
                    palet_no:      String(pallets.length + 1),
                    kasa_adeti:    parseInt2(r.kasa || 0),
                    size:          has(r.size) ? String(r.size) : '',
                    brut_kg:       parseNum(r.brut || 0),
                    kasa_cinsi_id: kcSel.value,
                    palet_tipi_id: ptSel.value,
                    urun_cinsi:    urunSel.value,
                    depo:          depSel.value,
                    islendi:       false,
                    materials:     mats.map(m => ({ material_id: m.material_id, quantity: m.quantity })),
                });
            });

            renderCards();
            recomputeTotals();
            temizle();
            panel.style.display = 'none';
        });
    })();

    /* ── Mevcut paletleri yükle ── */
    if (palletsInit && palletsInit.length) {
        palletsInit.forEach(p => {
            pallets.push({
                palet_no:      p.palet_no      || '',
                kasa_adeti:    p.kasa_adeti    || '',
                size:          p.size          || '',
                brut_kg:       p.brut_kg       != null ? p.brut_kg : '',
                kasa_cinsi_id: p.kasa_cinsi_id || '',
                palet_tipi_id: p.palet_tipi_id || '',
                urun_cinsi:    p.urun_cinsi    || '',
                depo:          p.depo          || '',
                islendi:       !!p.islendi,
                materials: Array.isArray(p.materials) ? p.materials.map(m => ({
                    material_id: m.material_id,
                    quantity:    m.quantity,
                })) : [],
            });
        });
    }

    /* ── Toplu İşle ── */
    document.getElementById('topluIsleBtn')?.addEventListener('click', () => {
        const allDone = pallets.length > 0 && pallets.every(p => p.islendi);
        pallets.forEach(p => { p.islendi = !allDone; });
        renderCards();
        recomputeTotals();
    });

    renderCards();
    recomputeTotals();
})();

/* =========================================================
   ÖNERİ KUTUSU — data-suggest'li serbest metin alanları
   (Alıcı · Gümrük · Nakliye Şirketi · Şoför · Telefon ·
    Ön/Arka Plaka · Ulaşım)

   Yazarken mevcut değerleri süzer; birebir eşleşme yoksa listenin
   sonuna "+ ekle" satırı koyar. Ekleme api_tanim_ekle.php'ye gider
   ve YALNIZCA kullanıcı bu satıra bastığında olur — kayıt kaydetmek
   tanım OLUŞTURMAZ, böylece yanlış yazımlar tanımları kirletmez.

   Serbest metin alanı olmayı sürdürür: listede olmayan bir değer
   yazılıp kaydedilebilir, tanıma eklenmesi zorunlu değildir.
   ========================================================= */
(function () {
    'use strict';

    var dataEl = document.getElementById('suggestData');
    if (!dataEl) return;
    var POOL;
    try { POOL = JSON.parse(dataEl.textContent || '{}'); } catch (_) { return; }
    if (!POOL || typeof POOL !== 'object') return;

    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var metaEl = document.getElementById('beyanSuggestMeta');
    var beyanMeta = {companies: {}, products: {}, drivers: {}};
    if (metaEl) {
        try { beyanMeta = JSON.parse(metaEl.textContent || '{}'); } catch (_) {}
    }

    /* TR-duyarsız katlama — malzeme_stok_islem ve depo_fold ile aynı kurallar */
    function fold(s) {
        return String(s == null ? '' : s)
            .replace(/İ/g, 'i').replace(/I/g, 'i').replace(/Ş/g, 's').replace(/Ğ/g, 'g')
            .replace(/Ü/g, 'u').replace(/Ö/g, 'o').replace(/Ç/g, 'c')
            .replace(/ı/g, 'i').replace(/ş/g, 's').replace(/ğ/g, 'g')
            .replace(/ü/g, 'u').replace(/ö/g, 'o').replace(/ç/g, 'c')
            .toLowerCase().replace(/\s+/g, ' ').trim();
    }
    /* En az bir harf/rakam — sunucudaki suggest_value_is_meaningful ile aynı kural */
    function anlamli(v) { return /[0-9A-Za-zÇĞİIÖŞÜçğıiöşü]/.test(String(v || '')); }
    function lookup(map, name) {
        var keys = Object.keys(map || {}).filter(function (key) { return fold(key) === fold(name); });
        return keys.length === 1 ? map[keys[0]] : null;
    }

    function attach(input) {
        var field = input.dataset.suggest;
        var label = input.dataset.suggestLabel || 'değer';
        var list0 = POOL[field];
        if (!Array.isArray(list0)) list0 = [];
        var items = list0.slice();

        var wrap = document.createElement('div');
        wrap.className = 'ms-sug-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        var list = document.createElement('div');
        list.className = 'ms-sug-list';
        list.hidden = true;
        wrap.appendChild(list);
        var active = -1;

        var suppress = false;   // programatik olay sırasında listeyi yeniden açma

        /* ── Kırpılmayı aşmak için sabit (fixed) konumlandırma ──
           .collapsible-card .card-body'de kapanma animasyonu için
           `overflow: hidden` var. Liste normal akışta (absolute) açılınca,
           kartın SON SATIRINDAKİ alanlarda (Ulaşım, Ön/Arka Plaka, Gümrük)
           kart gövdesinin dışına taştığı için TAMAMEN kırpılıyordu — kutu
           açılıyor ama hiç görünmüyordu. İlk satırdaki alanlar taşmadığı
           için sorunsuz çalışıyordu; bu yüzden hata yalnız bazı alanlarda
           görülüyordu.
           Çözüm, projede kebab menüsü için zaten kullanılan desen
           (bkz. CLAUDE.md "Dropdown overflow'da kesiyor"):
           position: fixed + getBoundingClientRect(). */
        function positionList() {
            if (list.hidden) return;
            var r = input.getBoundingClientRect();
            list.style.position = 'fixed';
            list.style.left     = r.left + 'px';
            list.style.width    = r.width + 'px';
            list.style.right    = 'auto';
            // Mobil alt çubuğun --bn-h kadar alanı da "ekran dışı" sayılır
            var altBosluk = ekranAlti() - r.bottom;
            var h = list.offsetHeight || 0;
            if (altBosluk < h + 8 && r.top > altBosluk) {
                // Aşağıda yer yok → yukarı aç
                list.style.top    = 'auto';
                // position:fixed'in bottom'u VİEWPORT'un altından ölçülür —
                // burada --bn-h düşülmez (düşülseydi liste girdinin üstüne binerdi)
                list.style.bottom = (window.innerHeight - r.top + 3) + 'px';
            } else {
                list.style.bottom = 'auto';
                list.style.top    = (r.bottom + 3) + 'px';
            }
        }
        // Açıkken sayfa/kapsayıcı kaydırılırsa liste input'la birlikte gitsin
        window.addEventListener('scroll', positionList, true);
        window.addEventListener('resize', positionList);

        function close() {
            list.hidden = true;
            active = -1;
            // Satır içi konum stillerini temizle — CSS varsayılanına dön
            list.style.position = '';
            list.style.top      = '';
            list.style.bottom   = '';
            list.style.left     = '';
            list.style.width    = '';
            list.style.right    = '';
        }
        function hasExact(v) {
            var f = fold(v);
            return items.some(function (s) { return fold(s) === f; });
        }
        function pick(name) {
            input.value = name;
            // data-uppercase ve bağımlı alanlar tetiklensin. Bu 'input' olayı
            // kendi render()'ımızı da çağırır ve listeyi yeniden açardı —
            // suppress bayrağı bunu engeller.
            suppress = true;
            input.dispatchEvent(new Event('input',  { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            suppress = false;
            close();
        }

        function ekle(name, row) {
            if (row.dataset.busy === '1') return;      // çift dokunuş koruması
            row.dataset.busy = '1';
            row.classList.add('busy');
            row.textContent = '⏳ Ekleniyor…';
            var payload = {csrf: csrf, field: field, name: name};
            if (input.dataset.suggestEndpoint) {
                var company = document.querySelector('[name="company_name"]');
                var address = document.querySelector('[name="company_address"]');
                payload.company_name = company ? company.value : '';
                payload.company_address = address ? address.value : '';
            }
            fetch(input.dataset.suggestEndpoint || 'api_tanim_ekle.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload)
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (!j || !j.ok) {
                    row.textContent = '⚠ ' + ((j && j.error) || 'Eklenemedi');
                    row.classList.remove('busy');
                    row.dataset.busy = '';
                    return;
                }
                if (!hasExact(j.name)) items.push(j.name);
                if (Array.isArray(POOL[field]) && POOL[field].indexOf(j.name) === -1) {
                    POOL[field].push(j.name);          // aynı alanın diğer örnekleri de görsün
                }
                if (metaEl && typeof j.address === 'string') {
                    var companyName = field === 'company_name' ? j.name : payload.company_name;
                    beyanMeta.companies[companyName] = j.address;
                }
                if (metaEl && field === 'product_name' && j.urun_id) {
                    beyanMeta.products[j.name] = {urun_id: j.urun_id};
                }
                pick(j.name);
            }).catch(function () {
                row.textContent = '⚠ Bağlantı hatası';
                row.classList.remove('busy');
                row.dataset.busy = '';
            });
        }

        function sil(name) {
            fetch('api_beyan_tanim_sil.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ csrf: csrf, field: field, name: name })
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (!j || !j.ok) { alert((j && j.error) || 'Öneri silinemedi.'); return; }
                var ayni = function (x) { return fold(x) !== fold(name); };
                items = items.filter(ayni);
                if (Array.isArray(POOL[field])) POOL[field] = POOL[field].filter(ayni);
                render();
            }).catch(function () { alert('Bağlantı hatası — öneri silinemedi.'); });
        }

        /* mousedown + touchstart: input blur'undan ÖNCE çalışır.
           İkisi birden tetiklenirse tek sefer çalışsın diye kilit var. */
        function onTap(el, fn) {
            var kilit = false;
            function handler(e) {
                if (kilit) return;
                kilit = true;
                setTimeout(function () { kilit = false; }, 400);
                e.preventDefault();
                fn();
            }
            el.addEventListener('mousedown', handler);
            el.addEventListener('touchstart', handler, { passive: false });
        }

        function render() {
            if (suppress) return;
            var v = input.value.trim();
            if (input.dataset.uppercase === 'tr') v = v.toLocaleUpperCase('tr-TR');
            var q = fold(v);
            var matches = items.filter(function (s) {
                return q === '' || fold(s).indexOf(q) !== -1;
            }).slice(0, 8);

            list.innerHTML = '';
            matches.forEach(function (name) {
                var row = document.createElement('div');
                row.className = 'ms-sug-row';
                row.textContent = name;
                onTap(row, function () { pick(name); });
                // Beyan formu: üzerine gelince sağda "×" — öneriyi listeden siler (kayıtlara dokunmaz).
                // HKS katalog ürünleri silinemez. Düğme METİNSİZdir (× CSS ile) — satır metni değişmez.
                if (metaEl && input.dataset.suggestEndpoint && !(field === 'product_name' && lookup(beyanMeta.products, name))) {
                    var del = document.createElement('button');
                    del.type = 'button';
                    del.className = 'ms-sug-del';
                    del.title = 'Öneriden sil';
                    del.setAttribute('aria-label', name + ' önerisini sil');
                    var delTap = function (e) { e.preventDefault(); e.stopPropagation(); sil(name); };
                    del.addEventListener('mousedown', delTap);
                    del.addEventListener('touchstart', delTap, { passive: false });
                    row.appendChild(del);
                }
                list.appendChild(row);
            });
            // Birebir eşleşme yoksa "+ ekle" satırı — boş/anlamsız değerde gösterme
            var needsAddressLink = false;
            if (metaEl && field === 'company_address') {
                var company = document.querySelector('[name="company_name"]');
                var linked = company ? lookup(beyanMeta.companies, company.value) : '';
                needsAddressLink = !!(company && company.value.trim()) && fold(linked) !== fold(v);
            }
            if (v !== '' && anlamli(v) && (!hasExact(v) || needsAddressLink)) {
                var add = document.createElement('div');
                add.className = 'ms-sug-row ms-sug-add';
                add.textContent = '➕ "' + v + '" ' + label + ' olarak ekle';
                onTap(add, function () { ekle(v, add); });
                list.appendChild(add);
            }
            list.hidden = list.childNodes.length === 0;
            active = -1;
            positionList();   // satırlar eklendikten SONRA — yükseklik ölçülebilsin
        }

        function rows() { return list.querySelectorAll('.ms-sug-row'); }

        input.addEventListener('input', render);
        input.addEventListener('focus', render);
        input.addEventListener('blur', function () { setTimeout(close, 180); });
        input.addEventListener('keydown', function (e) {
            if (list.hidden) return;
            var rs = rows();
            if (e.key === 'ArrowDown')      { e.preventDefault(); active = Math.min(active + 1, rs.length - 1); }
            else if (e.key === 'ArrowUp')   { e.preventDefault(); active = Math.max(active - 1, 0); }
            else if (e.key === 'Enter') {
                if (active >= 0 && rs[active]) {
                    e.preventDefault();
                    rs[active].dispatchEvent(new Event('mousedown'));
                }
                return;
            }
            else if (e.key === 'Escape')    { close(); return; }
            else return;
            rs.forEach(function (r, i) { r.classList.toggle('active', i === active); });
        });
    }

    // Her alanı AYRI ayrı dene: forEach callback'i throw ederse tüm iterasyon
    // durur — bir alanın kurulumu (ör. üretimdeki eski/beklenmedik veri)
    // patlarsa, DOM sırasında ONDAN SONRAKİ TÜM alanlar (Ulaşım genelde en
    // sonda) sessizce hiç kurulmamış olurdu. try/catch bunu izole eder.
    document.querySelectorAll('input[data-suggest], textarea[data-suggest]').forEach(function (input) {
        try {
            attach(input);
        } catch (err) {
            console.error('[öneri kutusu] "' + (input.name || input.dataset.suggest) + '" alanı kurulamadı:', err);
        }
    });

    // Gümrük adresi yalnız boşken veya önceki otomatik değerken değişir.
    // Elle düzeltilmiş bir adres başka gümrük seçildiğinde sessizce ezilmez.
    if (metaEl) {
        var companyInput = document.querySelector('[name="company_name"]');
        var addressInput = document.querySelector('[name="company_address"]');
        var productInput = document.querySelector('[name="product_name"]');
        var productSelect = document.querySelector('[name="hks_urun_id"]');
        var lastAutoAddress = null;
        if (companyInput && addressInput && fold(addressInput.value) === fold(lookup(beyanMeta.companies, companyInput.value))) {
            lastAutoAddress = addressInput.value;
        }
        if (companyInput && addressInput) {
            companyInput.addEventListener('change', function () {
                var address = lookup(beyanMeta.companies, companyInput.value);
                if (addressInput.value.trim() !== '' && addressInput.value !== lastAutoAddress) return;
                addressInput.value = address || '';
                lastAutoAddress = addressInput.value;
                addressInput.dispatchEvent(new Event('input', {bubbles: true}));
                addressInput.dispatchEvent(new Event('change', {bubbles: true}));
            });
            addressInput.addEventListener('input', function () {
                if (addressInput.value !== lastAutoAddress) lastAutoAddress = null;
            });
        }
        // Şoför Adı → Şoför Telefonu (v361): şoför seçilince/yazılıp çıkılınca bilinen telefonu gelir.
        // Gümrük adresiyle AYNI kural: telefon yalnız boşken ya da önceki OTOMATİK değerken değişir —
        // elle yazılan/düzeltilen telefon başka şoför seçildiğinde sessizce ezilmez.
        var driverInput = document.querySelector('[name="driver_name"]');
        var phoneInput = document.querySelector('[name="driver_phone"]');
        if (driverInput && phoneInput) {
            var drivers = beyanMeta.drivers || {};
            var lastAutoPhone = null;
            if (phoneInput.value.trim() !== '' && phoneInput.value === lookup(drivers, driverInput.value)) lastAutoPhone = phoneInput.value;
            driverInput.addEventListener('change', function () {
                var phone = lookup(drivers, driverInput.value);
                if (phoneInput.value.trim() !== '' && phoneInput.value !== lastAutoPhone) return;
                phoneInput.value = phone || '';
                lastAutoPhone = phone ? phoneInput.value : null;
                phoneInput.dispatchEvent(new Event('input', {bubbles: true}));
                phoneInput.dispatchEvent(new Event('change', {bubbles: true}));
            });
            phoneInput.addEventListener('input', function () {
                if (phoneInput.value !== lastAutoPhone) lastAutoPhone = null;
            });
        }
        // HKS Ürün Adı (v350): alan pasif başlar. Ürün Adı'ndan çıkılınca (change) KİLİT KARARI TARAYICIDA,
        // AĞA GİTMEDEN verilir — sunucunun kayıtta uyguladığı kuralın BİREBİR aynası (beyan_hks_urun_otomatik:
        // katalog tam ad → benzer ad). Böylece "Güncelle'de eşleşiyor ama ekranda açılıyor" ayrışması olmaz.
        // Eşleşirse değer gelir ve alan PASİF kalır (hiç açılmaz); eşleşmezse alan AÇILIR (turuncu) ve
        // api_beyan_urun_esle.php yalnız öğrenilmiş ÖNERİ için sorulur (hata verirse öneri yok, karar değişmez).
        // KAYDI SUNUCU BELİRLER (beyan_hks_urun_post_coz); PHP kuralını değiştirirsen urunNorm/urunBenzer'i de değiştir.
        if (productInput && productSelect) {
            var urunNorm = function (s) {   // hks_eslesme_norm(): İ→i, I→ı, küçük harf
                return String(s == null ? '' : s).trim().replace(/İ/g, 'i').replace(/I/g, 'ı').toLowerCase();
            };
            var urunTok = function (s) {
                return urunNorm(s).replace(/[^\p{L}\p{N}]+/gu, ' ').split(' ').filter(function (t) { return t !== ''; });
            };
            var katalog = function () {
                return Array.prototype.filter.call(productSelect.options, function (o) {
                    return o.value !== '' && !o.hasAttribute('data-katalog-disi');
                }).map(function (o) { return {id: o.value, ad: o.textContent}; });
            };
            var urunBenzer = function (metin, liste) {   // bb_urun_benzer() aynası
                var q = urunTok(metin).filter(function (t) { return Array.from(t).length >= 3; });
                if (!q.length) return '';
                var adaylar = [];
                liste.forEach(function (x) {
                    var ct = urunTok(x.ad);
                    if (!ct.length) return;
                    var tamam = q.every(function (qt) {
                        return ct.some(function (c) { return c === qt || (Array.from(qt).length >= 4 && c.indexOf(qt) === 0); });
                    });
                    if (tamam) adaylar.push({id: x.id, fazla: ct.length - q.length});
                });
                if (!adaylar.length) return '';
                adaylar.sort(function (a, b) { return a.fazla - b.fazla; });
                if (adaylar.length > 1 && adaylar[0].fazla === adaylar[1].fazla) return '';
                return adaylar[0].id;
            };
            var otomatik = function (ad) {   // beyan_hks_urun_otomatik() aynası
                var liste = katalog(), n = urunNorm(ad);
                if (n === '') return '';
                for (var i = 0; i < liste.length; i++) if (urunNorm(liste[i].ad) === n) return liste[i].id;
                return urunBenzer(ad, liste);
            };
            var durum = function (hal, id) {   // hal: 'kilit' | 'serbest'
                var proxy = productSelect._araInput;
                var kilitli = hal === 'kilit';
                productSelect.value = id || '';
                productSelect.disabled = kilitli;
                if (kilitli) { productSelect.dataset.hksKilit = '1'; delete productSelect.dataset.hksSerbest; }
                else { delete productSelect.dataset.hksKilit; productSelect.dataset.hksSerbest = '1'; }
                if (proxy) {
                    proxy.disabled = kilitli;
                    proxy.classList.toggle('hks-serbest', !kilitli);
                    if (typeof productSelect._araTazele === 'function') productSelect._araTazele();
                }
                productSelect.dispatchEvent(new Event('change', {bubbles: true}));
            };
            var secenekVar = function (id) {
                return !!id && Array.prototype.some.call(productSelect.options, function (o) { return o.value === id; });
            };
            var sayac = 0;
            var esle = function () {
                var ad = productInput.value.trim();
                var no = ++sayac;
                if (ad === '') { durum('kilit', ''); return; }
                var id = otomatik(ad);
                if (id) { durum('kilit', id); return; }          // eşleşti: alan hiç açılmaz
                durum('serbest', '');                              // eşleşmedi: hemen açılır
                fetch('api_beyan_urun_esle.php?ad=' + encodeURIComponent(ad), {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
                    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                    .then(function (j) {
                        if (no !== sayac || !j || !j.ok || productSelect.dataset.hksSerbest !== '1') return;
                        var sid = String(j.id || '');
                        if (secenekVar(sid)) { durum('kilit', sid); return; }   // sunucu daha bilgiliyse ona uy
                        var oneri = String(j.oneri || '');
                        if (productSelect.value === '' && secenekVar(oneri)) durum('serbest', oneri);
                    })
                    .catch(function () {});   // öneri alınamadı — alan açık ve boş kalır
            };
            // Yalnızca alandan çıkılınca (change) karar verilir; yazarken alan değişmez.
            productInput.addEventListener('change', esle);
            // İlk açılış: sunucunun verdiği durum uygulanır (aranabilir kutu kurulduktan sonra).
            var ilk = function () {
                if (productSelect.dataset.hksSerbest === '1' && productSelect._araInput) productSelect._araInput.classList.add('hks-serbest');
                if (productSelect.dataset.hksKilit === '1' && productSelect._araInput) productSelect._araInput.disabled = true;
            };
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ilk); else ilk();
        }
    }

    /* ── Şoför → Telefon otomatik doldurma ──
       Şoför adı bilinen bir şoföre eşitlenince telefon kutusu dolar.
       Kullanıcının ELLE yazdığı telefon EZİLMEZ: yalnızca kutu boşsa ya da
       içindeki değer bizim önceki otomatik doldurmamızsa değiştirilir. */
    (function () {
        var telEl   = document.querySelector('input[name="telefon"]');
        var soforEl = document.querySelector('input[name="sofor_adi"]');
        var mapEl   = document.getElementById('soforTelefonData');
        if (!telEl || !soforEl || !mapEl) return;

        var MAP;
        try { MAP = JSON.parse(mapEl.textContent || '{}'); } catch (_) { return; }
        if (!MAP || typeof MAP !== 'object') return;

        // ad → telefon (TR-duyarsız anahtar)
        var byFold = {};
        Object.keys(MAP).forEach(function (ad) { byFold[fold(ad)] = MAP[ad]; });

        var otoDolan = null;      // en son bizim yazdığımız değer
        var otoOnaylanmadi = false; // otomatik dolan değere kullanıcı hiç dokunmadı

        // Kullanıcı telefonu elle değiştirirse otomatik dolduruma son ver
        telEl.addEventListener('input', function () {
            if (telEl.value !== otoDolan) otoDolan = null;
        });

        /* Otomatik dolan değeri, kullanıcı yazmaya başlayınca TEMİZLE.
           Neden seçili bırakmak (select) tek başına YETMİYOR: fareyle telefon
           kutusuna tıklanınca sıra şöyle işliyor — mousedown şoförü blur eder,
           'change' tetiklenir, biz değeri yazıp seçeriz; ARDINDAN tarayıcı
           tıklama noktasına imleci koyar ve SEÇİMİ BOZAR. Sonraki tuş vuruşu
           da değerin SONUNA eklenir ("0545...05559...").
           beforeinput ile ilk yazımda alanı boşaltmak her iki akışta da
           (Tab ve fare) doğru davranışı garanti eder. */
        telEl.addEventListener('beforeinput', function (e) {
            if (!otoOnaylanmadi) return;
            if (!e.inputType || e.inputType.indexOf('insert') !== 0) return;
            if (telEl.value !== otoDolan) { otoOnaylanmadi = false; return; }
            otoOnaylanmadi = false;
            telEl.value = '';       // yazılan, otomatik değerin YERİNE geçsin
        });
        // Alandan çıkıldıysa değer kabul edilmiş sayılır; sonraki yazımlar normal düzenleme
        telEl.addEventListener('blur', function () { otoOnaylanmadi = false; });

        function uygula() {
            var tel = byFold[fold(soforEl.value)];
            if (!tel) return;
            var mevcut = telEl.value.trim();
            if (mevcut !== '' && mevcut !== otoDolan) return;   // elle girilmiş → dokunma
            if (mevcut === tel) return;
            telEl.value = tel;
            otoDolan = tel;
            otoOnaylanmadi = true;
            // Odaktaysa ayrıca seçili bırak — kullanıcı doldurulanı görsün
            // (asıl koruma yukarıdaki beforeinput; bu yalnız görsel ipucu).
            if (document.activeElement === telEl) {
                try { telEl.select(); } catch (_) {}
            }
            telEl.dispatchEvent(new Event('change', { bubbles: true }));
            telEl.classList.add('oto-dolan');
            setTimeout(function () { telEl.classList.remove('oto-dolan'); }, 1200);
        }

        // Yalnız 'change' yeterli: gerçek kullanıcı yazıp alandan çıkınca native
        // 'change' zaten ateşlenir; öneri listesinden seçim de pick() içinde
        // manuel 'change' dispatch eder. Ayrıca bir 'blur'+setTimeout tetikleyicisi
        // DAHA eklemek gereksiz ikinci bir tetikleyici yaratıp gecikmeli/iç içe
        // çağrılara (ve programatik doldurma sırasında yarış durumuna) yol açardı.
        soforEl.addEventListener('change', uygula);
    })();
})();

/* =========================================================
   Liste Scroll Koruma — records.php / cikmalar.php
   Kayıt linkine tıklanınca scroll kaydedilir; geri dönünce
   aynı karta scroll restore yapılır.
   Geri/İptal butonları: referrer listeyse history.back(),
   yoksa sessionStorage ile listeye doğrudan gidilir.
   ========================================================= */
(function () {
    'use strict';

    var TTL = 30 * 60 * 1000; // 30 dakika

    function sk(page, suffix) { return 'asya_' + page + '_' + suffix; }

    // Hangi liste sayfasındayız?
    var path = window.location.pathname;
    var currentPage = path.indexOf('cikmalar.php') !== -1 ? 'cikmalar'
                    : path.indexOf('records.php')  !== -1 ? 'records'
                    : null;

    if (currentPage) {
        // Kayıt linkine tıklanınca scroll + hedef kaydet
        document.addEventListener('click', function (e) {
            var link = e.target.closest('a[href]');
            if (!link) return;
            var href = link.getAttribute('href') || '';
            var m = href.match(/(?:record_view|record_edit)\.php.*?[?&]id=(\d+)/);
            if (!m) return;
            try {
                sessionStorage.setItem(sk(currentPage, 'scroll'), String(Math.round(window.scrollY)));
                sessionStorage.setItem(sk(currentPage, 'target'), m[1]);
                sessionStorage.setItem(sk(currentPage, 'ts'),     String(Date.now()));
            } catch (_) {}
        });

        // Sayfa yüklenince scroll restore (bfcache değilse)
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) return; // bfcache — tarayıcı scroll'ı zaten korur
            try {
                var ts = parseInt(sessionStorage.getItem(sk(currentPage, 'ts')) || '0', 10);
                if (!ts || Date.now() - ts > TTL) return;
                var target = sessionStorage.getItem(sk(currentPage, 'target')) || '';
                var savedY = parseInt(sessionStorage.getItem(sk(currentPage, 'scroll')) || '0', 10);
                if (target) {
                    // Her iki görünümdeki (pc tr + mobil div) data-record-id elementleri arasında
                    // offsetParent'ı null olmayan (display:none dışında) görünür olanı bul
                    var hits = document.querySelectorAll('[data-record-id="' + target + '"]');
                    for (var i = 0; i < hits.length; i++) {
                        if (hits[i].offsetParent !== null) {
                            hits[i].scrollIntoView({ block: 'center' });
                            return;
                        }
                    }
                }
                if (savedY > 0) window.scrollTo(0, savedY);
            } catch (_) {}
        });
    }

    // ── Geri / İptal butonları: data-list-back attribute ile hibrit davranış ──
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-list-back]');
        if (!btn) return;
        e.preventDefault();

        var listPage = btn.dataset.listBack;            // 'records' | 'cikmalar'
        var recordId = btn.dataset.recordId || '';
        var listUrl  = btn.getAttribute('href')
                    || (listPage === 'cikmalar' ? 'cikmalar.php' : 'records.php');

        // Referrer kontrol: aynı domain'den ve liste sayfasından mı gelindi?
        var ref = document.referrer || '';
        var isListRef = ref.length > 0 && ref.indexOf(listPage + '.php') !== -1;

        if (isListRef) {
            // Listeden gelindi → tarayıcı geçmişi ile dön (bfcache scroll koruması)
            history.back();
            return;
        }

        // Direkt link veya başka sayfa → sessionStorage'a hedef yaz, listeye git
        if (recordId) {
            try {
                sessionStorage.setItem(sk(listPage, 'target'), recordId);
                sessionStorage.setItem(sk(listPage, 'scroll'), '0');
                sessionStorage.setItem(sk(listPage, 'ts'),     String(Date.now()));
            } catch (_) {}
        }
        window.location.href = listUrl;
    });

})();

/* =========================================================
   Mobil alt çubuk (bottomnav) — Sprint Alt-Menü-01
   ---------------------------------------------------------
   Sunucu (config/helpers.php nav_alt_ciz) çubuğu 'asya_nav' çerezindeki
   sırayla çizer; izinli ama slotta olmayan sayfalar da dokta GİZLİ öğe
   olarak durur. Bu modül YENİ işaretleme ÜRETMEZ — yalnız öğelerin yerini
   değiştirir, gösterir/gizler ve sınıf/aria durumunu günceller (tek
   işaretleme kaynağı PHP'dir, iki kopya ayrışamaz).

   Kullanım YALNIZ bu cihazda: localStorage 'asya_nav_kullanim_<uid>' =
   [[anahtar, ms], …] (en çok 90 gün / 200 kayıt). Aynı bölümde 30 dk
   içinde art arda açılan sayfalar TEK ziyaret sayılır (liste → detay →
   düzenle zinciri bir bölümü şişirip diğerlerini 200 kayıttan atmasın).
   Puan = Σ 0,5^(gün/14); Ana Sayfa sayılmaz. Dışarıdaki sayfa slottaki en
   zayıfın yerine ancak puanı > en zayıf × 1,25 + 0,5 ise geçer (histerezis),
   kalan slot yerini korur. Sabitlenenler ('asya_nav_sabit_<uid>', en çok 4)
   önce gelir, sıralanmaz. Sonuç çereze yazılır: u:<uid>;s:…;p:… (180 gün).
   Her depolama erişimi try/catch içinde — gizli sekmede sessizce çalışır.
   ========================================================= */
(function () {
    'use strict';
    var nav = document.getElementById('bottomnav');
    if (!nav) return;
    var dock = nav.querySelector('.bn-dock');
    if (!dock) return;

    var uid    = nav.getAttribute('data-uid') || '0';
    var aktif  = nav.getAttribute('data-aktif') || '';
    var soguk  = (nav.getAttribute('data-soguk') || '').split(',').filter(Boolean);
    var more   = document.getElementById('bnMore');
    var ovl    = document.getElementById('bnSheetOvl');
    var sheet  = document.getElementById('bnSheet');
    var SLOT   = 4, GUN = 864e5, SIMDI = Date.now();
    var KUL    = 'asya_nav_kullanim_' + uid, SAB = 'asya_nav_sabit_' + uid;
    var dar    = window.matchMedia ? window.matchMedia('(max-width: 389px)') : { matches: false };

    function oku(k) { try { var s = localStorage.getItem(k); return s ? JSON.parse(s) : null; } catch (e) { return null; } }
    function yaz(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }

    // Aday öğeler (sunucunun izin verdiği sayfalar) — anahtar → öğe
    var ogeler = {};
    var izinli = [];
    dock.querySelectorAll('a.bn-slot[data-nav]').forEach(function (a) {
        ogeler[a.getAttribute('data-nav')] = a;
        izinli.push(a.getAttribute('data-nav'));
    });
    var mevcut = izinli.filter(function (k) { return !ogeler[k].hidden; });
    function izinliMi(k) { return izinli.indexOf(k) !== -1; }
    function oncelik(k) { var i = soguk.indexOf(k); return i === -1 ? 999 : i; }

    // ── Ziyaret kaydı ────────────────────────────────────────────────────
    var kul = oku(KUL);
    if (!Array.isArray(kul)) kul = [];
    kul = kul.filter(function (e) {
        return Array.isArray(e) && typeof e[0] === 'string' && typeof e[1] === 'number'
            && SIMDI - e[1] <= 90 * GUN && e[1] <= SIMDI + GUN;
    });
    if (aktif && aktif !== 'home' && izinliMi(aktif)) {
        var son = kul[kul.length - 1];
        if (!son || son[0] !== aktif || SIMDI - son[1] > 30 * 60 * 1000) kul.push([aktif, SIMDI]);
    }
    if (kul.length > 200) kul = kul.slice(-200);
    yaz(KUL, kul);

    var puanlar = {};
    kul.forEach(function (e) { puanlar[e[0]] = (puanlar[e[0]] || 0) + Math.pow(0.5, Math.max(0, SIMDI - e[1]) / GUN / 14); });
    function puan(k) { return puanlar[k] || 0; }

    // Sabitlenenler: localStorage asıl kaynak; okunamazsa çerezin aynası (data-sabit)
    var sab = oku(SAB);
    if (!Array.isArray(sab)) sab = (nav.getAttribute('data-sabit') || '').split(',').filter(Boolean);
    sab = sab.filter(function (k, i) { return typeof k === 'string' && izinliMi(k) && sab.indexOf(k) === i; }).slice(0, SLOT);

    // ── Sıralama (onaylı taslaktaki pickSlots'un aynısı) ────────────────
    function slotSec(onceki) {
        var pins = sab.slice(0, SLOT);
        var bos = SLOT - pins.length;
        var cur = onceki.filter(function (k) { return izinliMi(k) && pins.indexOf(k) === -1; }).slice(0, bos);
        var sira = izinli.filter(function (k) { return pins.indexOf(k) === -1; })
            .sort(function (a, b) { return (puan(b) - puan(a)) || (oncelik(a) - oncelik(b)); });
        sira.forEach(function (k) { if (cur.length < bos && cur.indexOf(k) === -1) cur.push(k); });
        for (var g = 0; g < 10; g++) {
            var disari = sira.filter(function (k) { return cur.indexOf(k) === -1; });
            if (!disari.length || !cur.length) break;
            var enIyi = disari[0];
            var enZayif = cur.slice().sort(function (a, b) { return (puan(a) - puan(b)) || (oncelik(b) - oncelik(a)); })[0];
            if (puan(enIyi) > puan(enZayif) * 1.25 + 0.5) cur[cur.indexOf(enZayif)] = enIyi;
            else break;
        }
        return pins.concat(cur);
    }

    function cerezYaz(slotlar) {
        var v = 'u:' + uid + ';s:' + slotlar.join(',') + ';p:' + sab.join(',');
        try {
            document.cookie = 'asya_nav=' + encodeURIComponent(v) + '; path=/; max-age=' + (180 * 86400)
                + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
        } catch (e) {}
    }

    // ── Uygulama: öğeleri yeni sıraya taşı, göster/gizle ─────────────────
    var slotlar = mevcut.slice();
    function uygula(yeni, animasyon) {
        var ref = more || null;   // öğeler "Diğer"in önüne dizilir (yoksa sona)
        yeni.forEach(function (k) {
            var a = ogeler[k];
            if (animasyon && slotlar.indexOf(k) === -1) {
                a.classList.remove('slot-new'); void a.offsetWidth; a.classList.add('slot-new');
            }
            a.hidden = false;
            var img = a.querySelector('img.ni');
            if (img) img.removeAttribute('loading');
            dock.insertBefore(a, ref);
        });
        izinli.forEach(function (k) {
            if (yeni.indexOf(k) !== -1) return;
            var a = ogeler[k];
            a.hidden = true;
            a.classList.remove('slot-new');
            dock.insertBefore(a, ref);
        });
        slotlar = yeni.slice();
        durum();
    }

    // Etiket sığdırma: önce 9.5px, yine taşarsa kısa hâl (data-xs; '' = gizle)
    function etiketSigdir() {
        dock.querySelectorAll('.bn-label').forEach(function (l) {
            var kisa = l.getAttribute('data-kisa');
            if (kisa !== null && l.textContent !== kisa) l.textContent = kisa;
            l.classList.remove('tight', 'xs-hide');
        });
        dock.querySelectorAll('.bn-label').forEach(function (l) {
            if (!l.offsetParent || l.scrollWidth <= l.clientWidth) return;
            l.classList.add('tight');
            if (l.scrollWidth <= l.clientWidth) return;
            var xs = l.getAttribute('data-xs');
            if (xs === '') l.classList.add('xs-hide'); else if (xs) l.textContent = xs;
        });
    }

    // Aktif öğe: güncel sayfa GÖRÜNEN bir slottaysa o; değilse (dar ekranda
    // 4. slot dahil) "Diğer" o sayfanın ikonunu/etiketini alır.
    function gorunenSlotlar() { return slotlar.slice(0, dar.matches ? SLOT - 1 : SLOT); }
    // Okunmamış rozeti (sunucu basar): "Diğer" yalnız güncel öğe Mail ise onunkini gösterir — burada yalnız hidden değişir.
    function mrz(hedef) {
        var r = more && more.querySelector('.nav-rozet-more');
        if (r) r.hidden = !(hedef && hedef.getAttribute('data-nav') === 'mail');
    }
    function durum() {
        var gorunen = gorunenSlotlar();
        izinli.forEach(function (k) {
            var a = ogeler[k], akt = k === aktif && gorunen.indexOf(k) !== -1;
            a.classList.toggle('bn-4', slotlar.indexOf(k) === SLOT - 1);   // dar ekranda CSS gizler
            a.classList.toggle('is-active', akt);
            if (akt) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
        });
        if (more) {
            var hedef = (aktif && aktif !== 'home' && izinliMi(aktif) && gorunen.indexOf(aktif) === -1) ? ogeler[aktif] : null;
            var img = more.querySelector('img.ni'), rozet = more.querySelector('.more-badge');
            var lab = more.querySelector('.bn-label');
            if (hedef) {
                var hl = hedef.querySelector('.bn-label');
                img.setAttribute('src', hedef.querySelector('img.ni').getAttribute('src'));
                rozet.hidden = false;
                lab.setAttribute('data-kisa', hl.getAttribute('data-kisa'));
                if (hl.hasAttribute('data-xs')) lab.setAttribute('data-xs', hl.getAttribute('data-xs')); else lab.removeAttribute('data-xs');
                more.style.setProperty('--c2', hedef.style.getPropertyValue('--c2'));
                more.classList.add('is-active');
                more.setAttribute('aria-current', 'page');
                more.setAttribute('aria-label', 'Diğer sayfalar, şu an: ' + hedef.getAttribute('aria-label'));
                mrz(hedef);
            } else {
                // Rozet her zaman "Diğer" ikonunu taşır — varsayılan ikon oradan
                img.setAttribute('src', rozet.querySelector('img').getAttribute('src'));
                rozet.hidden = true;
                lab.setAttribute('data-kisa', 'Diğer');
                lab.removeAttribute('data-xs');
                more.style.setProperty('--c2', '#6b788d');
                more.classList.remove('is-active');
                more.removeAttribute('aria-current');
                more.setAttribute('aria-label', 'Diğer sayfalar');
                mrz(null);
            }
        }
        if (sheet) {
            sheet.querySelectorAll('.bn-tile[data-nav]').forEach(function (t) {
                var k = t.getAttribute('data-nav');
                t.classList.toggle('in-bar', gorunen.indexOf(k) !== -1);
                t.classList.toggle('pinned', sab.indexOf(k) !== -1);
                var go = t.querySelector('.go');
                if (go && sheet.classList.contains('editing')) go.setAttribute('aria-pressed', sab.indexOf(k) !== -1 ? 'true' : 'false');
            });
            var foot = document.getElementById('bnSheetFoot');
            var n = gorunenSlotlar().length;
            if (foot) foot.textContent = sheet.classList.contains('editing')
                ? 'Alt menüde her zaman görünmesini istediğiniz sayfalara dokunun. En çok 4 sayfa sabitlenir; bu genişlikte ilk ' + (dar.matches ? SLOT - 1 : SLOT) + ' tanesi görünür. Sabitlenmeyen slotlar kullanıma göre dolmaya devam eder.'
                : 'Alt menüdeki ' + n + ' slot en çok açtığınız sayfalarla kendiliğinden dolar. "Sabitle" ile bir sayfayı kalıcı olarak alt menüye alabilirsiniz.';
        }
        etiketSigdir();
    }

    // İlk yükleme: yeniden sırala, değiştiyse öğeleri taşı; çerezi tazele
    var ilk = slotSec(mevcut);
    if (ilk.join(',') !== mevcut.join(',')) uygula(ilk, true); else durum();
    cerezYaz(slotlar);
    nav.setAttribute('data-hazir', '1');

    function genislikDegisti() { durum(); }
    try { dar.addEventListener('change', genislikDegisti); }
    catch (e) { try { dar.addListener(genislikDegisti); } catch (e2) {} }
    // Yazı tipi geç yüklenirse etiket ölçüsü değişir
    try { if (document.fonts && document.fonts.ready) document.fonts.ready.then(etiketSigdir); } catch (e) {}

    // ── Basma efekti ──────────────────────────────────────────────────────
    dock.addEventListener('pointerdown', function (e) {
        var a = e.target.closest('.bn-item');
        if (a) a.classList.add('is-press');
    });
    ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (ev) {
        document.addEventListener(ev, function () {
            dock.querySelectorAll('.bn-item.is-press').forEach(function (x) { x.classList.remove('is-press'); });
        }, true);
    });

    // ── "Diğer" alt sayfası ───────────────────────────────────────────────
    if (!more || !ovl || !sheet) return;
    var pinBtn = document.getElementById('bnPinBtn');
    var kapatBtn = document.getElementById('bnSheetClose');
    var mesaj = document.getElementById('bnSheetMsg');

    function duzenle(acik) {
        sheet.classList.toggle('editing', acik);
        pinBtn.setAttribute('aria-pressed', acik ? 'true' : 'false');
        pinBtn.textContent = acik ? 'Bitti' : 'Sabitle';
        sheet.querySelectorAll('.bn-tile .go').forEach(function (go) {
            if (acik) {
                go.setAttribute('role', 'button');
                go.setAttribute('aria-pressed', sab.indexOf(go.parentNode.getAttribute('data-nav')) !== -1 ? 'true' : 'false');
            } else {
                go.removeAttribute('role');
                go.removeAttribute('aria-pressed');
            }
        });
        if (!acik && mesaj) mesaj.textContent = '';
        durum();
    }
    // Sayfa arkası kaydırılmasın diye body sabitlenir (iOS'ta rubber-band
    // dahil en güvenilir yöntem); kapanışın HER yolunda (Esc/✕/arka plan/
    // karo navigasyonu/bfcache) geri açılır — bkz. pagehide/pageshow altta.
    var kilitliY = 0;
    function scrollKilitle() {
        kilitliY = window.scrollY || document.documentElement.scrollTop || 0;
        document.body.style.position = 'fixed';
        document.body.style.top = (-kilitliY) + 'px';
        document.body.style.left = '0';
        document.body.style.right = '0';
        document.body.style.width = '100%';
    }
    function scrollKilitAc() {
        if (document.body.style.position !== 'fixed') return;
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.left = '';
        document.body.style.right = '';
        document.body.style.width = '';
        window.scrollTo(0, kilitliY);
    }
    function ac() {
        scrollKilitle();
        ovl.hidden = false;
        more.setAttribute('aria-expanded', 'true');
        duzenle(false);
        kapatBtn.focus();
    }
    function kapat() {
        if (ovl.hidden) return;
        duzenle(false);
        ovl.hidden = true;
        more.setAttribute('aria-expanded', 'false');
        more.focus();
        scrollKilitAc();
    }
    // Karo navigasyonu (gerçek <a href>) kapat()'ı çağırmadan sayfadan
    // ayrılır — sayfayı burada TAMAMEN kapat (yalnız kilidi değil): yoksa
    // bfcache'ten geri dönüşte body serbest ama "Diğer" hâlâ açık görünür.
    // Odak taşınmaz (sayfa zaten ayrılıyor / geri dönüşte kullanıcı dokunmadı).
    function sessizKapat() {
        if (!ovl.hidden) {
            duzenle(false);
            ovl.hidden = true;
            more.setAttribute('aria-expanded', 'false');
        }
        scrollKilitAc();
    }
    window.addEventListener('pagehide', sessizKapat);
    window.addEventListener('pageshow', sessizKapat);
    more.addEventListener('click', ac);
    kapatBtn.addEventListener('click', kapat);
    ovl.addEventListener('click', function (e) { if (e.target === ovl) kapat(); });
    pinBtn.addEventListener('click', function () { duzenle(!sheet.classList.contains('editing')); pinBtn.focus(); });
    document.addEventListener('keydown', function (e) {
        if (ovl.hidden) return;
        if (e.key === 'Escape') { e.preventDefault(); kapat(); return; }
        if (e.key !== 'Tab') return;
        // Odak dialog içinde kalsın
        var od = [].slice.call(sheet.querySelectorAll('button, a[href]')).filter(function (x) { return x.offsetParent !== null; });
        if (!od.length) return;
        var i = od.indexOf(document.activeElement);
        if (e.shiftKey && (i <= 0)) { e.preventDefault(); od[od.length - 1].focus(); }
        else if (!e.shiftKey && (i === -1 || i === od.length - 1)) { e.preventDefault(); od[0].focus(); }
    });

    // Sabitle modu: karoya dokunmak gitmez, sabitlemeyi açar/kapatır
    sheet.addEventListener('click', function (e) {
        var go = e.target.closest('.bn-tile .go');
        if (!go || !sheet.classList.contains('editing')) return;
        e.preventDefault();
        var k = go.parentNode.getAttribute('data-nav');
        var ad = ogeler[k] ? ogeler[k].getAttribute('aria-label') : k;
        var i = sab.indexOf(k);
        if (i !== -1) { sab.splice(i, 1); mesaj.textContent = ad + ' sabitlemesi kaldırıldı.'; }
        else if (sab.length < SLOT) { sab.push(k); mesaj.textContent = ad + ' alt menüye sabitlendi.'; }
        else { mesaj.textContent = 'En çok 4 sayfa sabitlenebilir. Önce birinin sabitlemesini kaldırın.'; return; }
        yaz(SAB, sab);
        var yeni = slotSec(slotlar);
        if (yeni.join(',') !== slotlar.join(',')) uygula(yeni, true); else durum();
        cerezYaz(slotlar);
        go.focus();
    });
    // Karolar <a role="button"> — Enter native click tetikler, Space tetiklemez
    // (yalnız gerçek <button>'da otomatiktir). Sabitle modunda Space'i elle
    // click'e çevir, yoksa klavyeyle sabitleme yapılamaz.
    sheet.addEventListener('keydown', function (e) {
        if (e.key !== ' ' && e.key !== 'Spacebar') return;
        var go = e.target.closest('.bn-tile .go');
        if (!go || !sheet.classList.contains('editing')) return;
        e.preventDefault();
        go.click();
    });
})();

// ── Tıklanabilir tablo satırı: <tr class="row-link" data-href="..."> ──────
// Satırın içindeki bağlantı/buton/form öğesine yapılan tık kendi işini yapar
// (ör. toplu bildirim seçim kutusu). Ctrl/⌘/orta tık yeni sekmede açar.
// Seçili metin varsa (kopyalama) yönlendirmez.
(function () {
  function hedef(e) {
    if (e.target.closest('a, button, input, select, textarea, label, .bb-sec-col')) return null;
    var tr = e.target.closest('tr.row-link[data-href]');
    if (!tr) return null;
    var sel = window.getSelection && String(window.getSelection());
    return sel ? null : tr.getAttribute('data-href');
  }
  document.addEventListener('click', function (e) {
    var url = hedef(e);
    if (!url) return;
    if (e.ctrlKey || e.metaKey) window.open(url, '_blank');
    else window.location.href = url;
  });
  document.addEventListener('auxclick', function (e) {
    if (e.button !== 1) return;
    var url = hedef(e);
    if (url) window.open(url, '_blank');
  });
})();


/* Sidebar grup başlıkları: kullanıcı tercihi yalnız cihazda; etkin sayfanın grubu hep açık. */
(function () {
    var anahtar = 'asya_sb_grup';
    var kayit = {};
    try { kayit = JSON.parse(localStorage.getItem(anahtar) || '{}') || {}; } catch (e) { kayit = {}; }
    document.querySelectorAll('details.sidebar-grup[data-sb-grup]').forEach(function (d) {
        var ad = d.getAttribute('data-sb-grup');
        var aktif = !!d.querySelector('.sidebar-link.active');
        if (aktif) d.open = true;
        else if (ad in kayit) d.open = !!kayit[ad];
        d.addEventListener('toggle', function () {
            kayit[ad] = d.open ? 1 : 0;
            try { localStorage.setItem(anahtar, JSON.stringify(kayit)); } catch (e) {}
        });
    });
})();


/* Ekran kilidi — ortak yardımcılar (v455: PIN modeli). Kilit durumu sunucuda imzalı çerezde
   (PHP oturumundan bağımsız); istemci yalnız YÜZEY: kapı = masaüstü (≥900px + fare), .pdks-kiosk muaf.
   Bu IIFE: ortak yardımcılar + keep-alive nabzı (kilitliyken de) + rakam süzgeci. PIN/şifre ASLA
   console'a ya da localStorage'a yazılmaz. */
(function () {
    var DLG_IDS = ['ekranKilit', 'ekranPinKur', 'ekranPinDegistir'];
    var MQ = window.matchMedia ? window.matchMedia('(min-width: 900px) and (hover: hover) and (pointer: fine)') : null;
    var O = window.__asyaKilitOrtak = {};
    O.masaustu = function () { return !!(MQ && MQ.matches); };
    O.kiosk = function () { return !!document.querySelector('.pdks-kiosk'); };
    O.yuzey = function () { return O.masaustu() && !O.kiosk(); };
    O.pinGecerli = function (s) { return /^\d{4,8}$/.test(s); };
    O.dlg = function () {
        for (var i = 0; i < DLG_IDS.length; i++) { var d = document.getElementById(DLG_IDS[i]); if (d) return d; }
        return null;
    };
    O.gonder = function (body) {
        var d = O.dlg();
        if (!d) return Promise.reject(new Error('dlg'));
        return fetch(d.getAttribute('data-uc'), {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) { j.durum = r.status; return j; });
        });
    };
    O.girise = function (j) {
        var d = O.dlg();
        location.href = d.getAttribute('data-login') + '?next=' + encodeURIComponent(location.pathname + location.search)
            + '&mesaj=' + (j && j.kod === 'cikis' ? 'pin_cikis' : 'kilit_oturum');
    };
    O.hataMetni = function (j) {
        if (j.error) return j.error;
        var s = j.durum || 0;
        if (s === 403) return 'İstek doğrulanamadı. Sayfayı yenileyip tekrar deneyin.';
        if (s === 405 || s === 413 || s === 400) return 'İstek kabul edilmedi. Sayfayı yenileyip tekrar deneyin.';
        if (s >= 500) return 'Sunucu şu an yanıt veremiyor. Biraz sonra tekrar deneyin.';
        return 'İşlem tamamlanamadı. Tekrar deneyin.';
    };
    O.bildir = function (metin) {
        var e = document.createElement('div');
        e.className = 'ekran-pin-toast'; e.setAttribute('role', 'status'); e.textContent = metin;
        document.body.appendChild(e);
        setTimeout(function () { if (e.parentNode) e.parentNode.removeChild(e); }, 4000);
    };

    // Rakam süzgeci (yalnız .ekran-pin-girdi; hesap şifresi alanına dokunmaz)
    document.addEventListener('input', function (e) {
        var t = e.target;
        if (!t || !t.classList || !t.classList.contains('ekran-pin-girdi')) return;
        var temiz = t.value.replace(/\D/g, '').slice(0, 8);
        if (temiz !== t.value) t.value = temiz;
    }, true);

    // Keep-alive nabzı: oturumu canlı tutar, çıkış yapılmışsa login'e götürür, kilitliyse pencereyi açtırır
    function nabiz() {
        if (!O.dlg() || !O.yuzey()) return Promise.resolve();
        return O.gonder({ islem: 'nabiz' }).then(function (j) {
            if (j.durum === 401) { O.girise(j); return; }
            if (j.ok) document.dispatchEvent(new CustomEvent('asya:nabiz', { detail: j }));
        }).catch(function () {});
    }
    var aralik = window.ASYA_NABIZ_MS > 0 ? window.ASYA_NABIZ_MS : 300000;
    setInterval(nabiz, aralik);
    window.__ekranNabiz = nabiz;   // test kancası
})();

/* Ekran kilidi (v454 → v455 PIN): 5 dk hareketsizlikte PIN penceresi açılır (sayfa yenilenmez → form/sekme
   durumu korunur); durum sunucuda imzalı çerezde, yenileme / yeni sekme kilitli gelir. Son hareket zamanı
   sekmeler arası localStorage'da paylaşılır; kiosk ekranlarında otomatik kilit YOK. */
(function () {
    var dlg = document.getElementById('ekranKilit');
    var O = window.__asyaKilitOrtak;
    if (!dlg || !O || typeof dlg.showModal !== 'function') return;
    var form = document.getElementById('ekranKilitForm');
    var girdi = document.getElementById('ekranKilitPin');
    var hataEl = document.getElementById('ekranKilitHata');
    var btn = document.getElementById('ekranKilitBtn');
    var SURE = window.ASYA_KILIT_MS > 0 ? window.ASYA_KILIT_MS : 300000;   // test kancası
    var HAREKET = 'asya_son_hareket', DURUM = 'asya_kilit_durum';
    var yerel = Date.now(), sonYazim = 0, bekleZ = null, kayitBekliyor = false, kayitSuruyor = false;
    var KAYIT_NOTU = 'Kilit sunucuya kaydedilemedi; bağlantı yeniden denenecek.';

    function depoOku(k) { try { return localStorage.getItem(k) || ''; } catch (e) { return ''; } }
    function depoYaz(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
    function son() { return Math.max(yerel, parseInt(depoOku(HAREKET), 10) || 0); }
    function hareket() {
        if (dlg.open) return;
        var t = Date.now();
        yerel = t;
        if (t - sonYazim < 2000) return;
        sonYazim = t;
        depoYaz(HAREKET, String(t));
    }
    function hataGoster(m) { hataEl.textContent = m || ''; hataEl.hidden = !m; }
    function girdiAc() { clearTimeout(bekleZ); btn.disabled = false; girdi.disabled = false; }

    function ac(yayinla) {
        if (dlg.open) return;
        document.body.classList.add('ekran-kilitli');
        try { dlg.showModal(); } catch (e) { dlg.setAttribute('open', ''); }
        girdi.value = ''; hataGoster('');
        setTimeout(function () { try { girdi.focus(); } catch (e) {} }, 30);
        if (yayinla) depoYaz(DURUM, 'k:' + Date.now());
    }
    function kapat(yayinla) {
        var pd = document.getElementById('ekranPinDegistir');
        if (pd && pd.open) pd.close();
        kayitBekliyor = false;
        girdiAc();
        if (!dlg.open) { document.body.classList.remove('ekran-kilitli'); return; }
        dlg.close();
        document.body.classList.remove('ekran-kilitli');
        girdi.value = ''; hataGoster('');
        yerel = Date.now(); sonYazim = 0; hareket();
        if (yayinla) depoYaz(DURUM, 'a:' + Date.now());
    }
    function sunucuyaKilitle() {
        if (kayitSuruyor) return;
        kayitSuruyor = true;
        O.gonder({ islem: 'kilitle' }).then(function (j) {
            kayitSuruyor = false;
            if (j.durum === 401) { O.girise(j); return; }
            if (j.ok) { if (kayitBekliyor) hataGoster(''); kayitBekliyor = false; return; }
            kayitBekliyor = true;
            if (dlg.open && hataEl.hidden) hataGoster(KAYIT_NOTU);
        }).catch(function () {
            kayitSuruyor = false; kayitBekliyor = true;
            if (dlg.open && hataEl.hidden) hataGoster(KAYIT_NOTU);
        });
    }
    function kilitle() {
        ac(true);
        kayitBekliyor = true;
        sunucuyaKilitle();
    }
    function kontrol() {
        if (!O.yuzey()) return;
        if (dlg.open) { if (kayitBekliyor) sunucuyaKilitle(); return; }   // kayıt başarısızsa yeniden dener
        if (Date.now() - son() >= SURE) kilitle();
    }

    dlg.addEventListener('cancel', function (e) { e.preventDefault(); });   // Esc ile kapanmaz
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (btn.disabled) return;
        if (!O.pinGecerli(girdi.value)) { hataGoster('PIN 4–8 haneli rakamlardan oluşmalı.'); girdi.focus(); return; }
        btn.disabled = true;
        O.gonder({ islem: 'ac', pin: girdi.value }).then(function (j) {
            if (j.ok) { btn.disabled = false; kapat(true); return; }
            if (j.durum === 401) { O.girise(j); return; }
            hataGoster(j.durum === 422 ? (j.error || 'PIN hatalı.') : O.hataMetni(j));
            girdi.value = '';
            var bekle = parseInt(j.bekle, 10) || 0;
            if (bekle > 0) {
                girdi.disabled = true;
                clearTimeout(bekleZ);
                bekleZ = setTimeout(function () { girdiAc(); hataGoster(''); try { girdi.focus(); } catch (e) {} }, bekle * 1000);
            } else { btn.disabled = false; girdi.focus(); }
        }).catch(function () { btn.disabled = false; hataGoster('Bağlantı hatası. Tekrar deneyin.'); });
    });

    ['mousemove', 'mousedown', 'keydown', 'wheel', 'scroll', 'touchstart', 'pointerdown'].forEach(function (ad) {
        document.addEventListener(ad, hareket, { passive: true, capture: true });
    });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) kontrol(); });
    window.addEventListener('focus', kontrol);
    window.addEventListener('storage', function (e) {   // başka sekmede kilit / açma
        if (e.key !== DURUM || !e.newValue) return;
        if (e.newValue.charAt(0) === 'k') { if (O.yuzey()) ac(false); }
        else kapat(false);
    });
    document.addEventListener('asya:nabiz', function (e) {   // sunucu kilitli diyorsa pencere açılır
        if (e.detail && e.detail.kilitli && !dlg.open && O.masaustu()) ac(false);
    });
    setInterval(kontrol, 10000);
    window.__ekranKilitKontrol = kontrol;   // test kancası

    // Sayfa açılışı: sunucu kilitliyse (masaüstünde) pencereyi aç; değilse bu yükleme bir hareket sayılır.
    if (dlg.hasAttribute('data-kilitli') && O.masaustu()) ac(false);
    else { yerel = Date.now(); depoYaz(HAREKET, String(yerel)); sonYazim = yerel; }
})();

/* PIN belirle (v455): PIN'i olmayan kullanıcıya masaüstünde ZORUNLU pencere (kiosk ve mobil muaf). */
(function () {
    var dlg = document.getElementById('ekranPinKur');
    var O = window.__asyaKilitOrtak;
    if (!dlg || !O || typeof dlg.showModal !== 'function') return;
    var form = document.getElementById('ekranPinKurForm');
    var p1 = document.getElementById('ekranPinKurPin');
    var p2 = document.getElementById('ekranPinKurPin2');
    var hataEl = document.getElementById('ekranPinKurHata');
    var btn = document.getElementById('ekranPinKurBtn');
    function hataGoster(m) { hataEl.textContent = m || ''; hataEl.hidden = !m; }
    function kapat() { btn.disabled = false; if (dlg.open) dlg.close(); p1.value = ''; p2.value = ''; }

    dlg.addEventListener('cancel', function (e) { e.preventDefault(); });   // kaçış yalnız çıkış linki
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (btn.disabled) return;
        if (!O.pinGecerli(p1.value)) { hataGoster('PIN 4–8 haneli rakamlardan oluşmalı.'); p1.focus(); return; }
        if (p1.value !== p2.value) { hataGoster('PIN\'ler eşleşmiyor.'); p2.focus(); return; }
        btn.disabled = true;
        O.gonder({ islem: 'kur', pin: p1.value, pin2: p2.value }).then(function (j) {
            if (j.ok) { kapat(); O.bildir('PIN belirlendi.'); return; }
            if (j.durum === 401) { O.girise(j); return; }
            if (j.durum === 409) { kapat(); O.bildir('PIN zaten belirlenmiş.'); return; }
            if (j.durum === 503) { kapat(); O.bildir('PIN özelliği şu an kullanılamıyor.'); return; }
            btn.disabled = false;
            hataGoster(O.hataMetni(j));
        }).catch(function () { btn.disabled = false; hataGoster('Bağlantı hatası. Tekrar deneyin.'); });
    });

    function ilkAcilis() {
        if (dlg.open || !O.yuzey()) return;
        var kilit = document.getElementById('ekranKilit');
        if (kilit && (kilit.open || kilit.hasAttribute('data-kilitli'))) return;
        p1.value = ''; p2.value = ''; hataGoster('');
        try { dlg.showModal(); } catch (e) { dlg.setAttribute('open', ''); }
        setTimeout(function () { try { p1.focus(); } catch (e) {} }, 30);
    }
    ilkAcilis();
})();

/* PIN değiştir (v455): sidebar düğmesi; mevcut PIN ile ya da "PIN'imi unuttum" → hesap şifresiyle. */
(function () {
    var dlg = document.getElementById('ekranPinDegistir');
    var O = window.__asyaKilitOrtak;
    if (!dlg || !O || typeof dlg.showModal !== 'function') return;
    var form = document.getElementById('ekranPinDegistirForm');
    var mevcutKap = document.getElementById('ekranPinMevcutKap');
    var sifreKap = document.getElementById('ekranPinSifreKap');
    var mevcut = document.getElementById('ekranPinDegistirMevcut');
    var sifre = document.getElementById('ekranPinDegistirSifre');
    var unuttum = document.getElementById('ekranPinUnuttum');
    var y1 = document.getElementById('ekranPinDegistirYeni');
    var y2 = document.getElementById('ekranPinDegistirYeni2');
    var hataEl = document.getElementById('ekranPinDegistirHata');
    var btn = document.getElementById('ekranPinDegistirBtn');
    var iptal = document.getElementById('ekranPinDegistirIptal');
    var sifreYolu = false, bekleZ = null;
    var ETIKET_UNUTTUM = unuttum.textContent.trim() || 'PIN\'imi unuttum';
    var ETIKET_PIN = 'PIN ile doğrula';

    function hataGoster(m) { hataEl.textContent = m || ''; hataEl.hidden = !m; }
    function yontem(sifreMi) {
        sifreYolu = sifreMi;
        mevcutKap.hidden = sifreMi; sifreKap.hidden = !sifreMi;
        mevcut.value = ''; sifre.value = '';   // gizlenen alan temizlenir
        unuttum.textContent = sifreMi ? ETIKET_PIN : ETIKET_UNUTTUM;
    }
    function sifirla() {
        clearTimeout(bekleZ); btn.disabled = false;
        yontem(false); y1.value = ''; y2.value = ''; hataGoster('');
    }
    function ac() {
        if (!O.yuzey() || dlg.open) return;
        var kilit = document.getElementById('ekranKilit');
        if (kilit && kilit.open) return;
        sifirla();
        try { dlg.showModal(); } catch (e) { dlg.setAttribute('open', ''); }
        setTimeout(function () { try { mevcut.focus(); } catch (e) {} }, 30);
    }
    function kapat() { sifirla(); if (dlg.open) dlg.close(); }

    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-pin-degistir]') : null;
        if (b) { e.preventDefault(); ac(); }
    });
    unuttum.addEventListener('click', function () {
        yontem(!sifreYolu); hataGoster('');
        try { (sifreYolu ? sifre : mevcut).focus(); } catch (e) {}
    });
    iptal.addEventListener('click', kapat);
    dlg.addEventListener('close', sifirla);   // Esc dahil her kapanışta alanlar temizlenir

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (btn.disabled) return;
        var g = { islem: 'degistir' };
        if (sifreYolu) {
            if (!sifre.value) { hataGoster('Hesap şifrenizi girin.'); sifre.focus(); return; }
            g.sifre = sifre.value;
        } else {
            if (!O.pinGecerli(mevcut.value)) { hataGoster('Mevcut PIN 4–8 haneli rakamlardan oluşmalı.'); mevcut.focus(); return; }
            g.mevcut = mevcut.value;
        }
        if (!O.pinGecerli(y1.value)) { hataGoster('PIN 4–8 haneli rakamlardan oluşmalı.'); y1.focus(); return; }
        if (y1.value !== y2.value) { hataGoster('PIN\'ler eşleşmiyor.'); y2.focus(); return; }
        g.pin = y1.value; g.pin2 = y2.value;
        btn.disabled = true;
        O.gonder(g).then(function (j) {
            if (j.ok) { kapat(); O.bildir('PIN değiştirildi.'); return; }
            if (j.durum === 401) { O.girise(j); return; }
            hataGoster(j.durum === 422 && j.error ? j.error : O.hataMetni(j));
            var bekle = parseInt(j.bekle, 10) || 0;
            if (bekle > 0) {
                clearTimeout(bekleZ);
                bekleZ = setTimeout(function () { btn.disabled = false; hataGoster(''); }, bekle * 1000);
            } else btn.disabled = false;
        }).catch(function () { btn.disabled = false; hataGoster('Bağlantı hatası. Tekrar deneyin.'); });
    });
})();
