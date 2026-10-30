/* Live preview only. PHP recalculates every persisted result. No local storage. */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else { root.QuickCost = api; if (root.document) api.init(root.document, root.QUICK_COST_CONFIG); }
}(typeof window !== 'undefined' ? window : globalThis, function () {
    'use strict';
    var NUMBER_MAX = 1000000, TOTAL_MAX = 9999999999.99;
    var UNITS = { kg: 'Kg', ton: 'Ton', piece: 'Adet', box: 'Koli / kasa', pallet: 'Palet', vehicle: 'Araç', fixed: 'Sabit tutar' };
    var CATALOG = {
        product: { label: 'Ürün maliyeti', group: 'A' }, material: { label: 'Malzeme', group: 'B' },
        transport: { label: 'Nakliye', group: 'D' }, customs: { label: 'Gümrükleme', group: 'D' },
        labor: { label: 'İşçilik', group: 'D' }, official_analysis: { label: 'Resmi analiz', group: 'D' },
        private_analysis: { label: 'Özel analiz', group: 'D' }, dts_taxi: { label: 'DTS taksi', group: 'D' },
        overhead: { label: 'İşletme gideri', group: 'D' }, casus: { label: 'CASUS', group: 'D' }
    };
    function clone(value) { return JSON.parse(JSON.stringify(value)); }
    function parseNumber(value) {
        var n;
        if (typeof value === 'number') n = value;
        else if (typeof value === 'string') {
            var s = value.trim();
            if (s === '') return 0;
            if (!/^(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d{1,4})?$/.test(s)) throw new Error('Sayı biçimi geçersiz. Ondalık için virgül kullanın.');
            n = Number(s.replace(/\./g, '').replace(',', '.'));
        } else throw new Error('Geçerli bir sayı girin.');
        if (!Number.isFinite(n) || n < 0 || n > NUMBER_MAX || Math.abs(n * 10000 - Math.round(n * 10000)) > 0.00001) {
            throw new Error('Sayı 0 ile 1.000.000 arasında ve en fazla 4 ondalık olmalıdır.');
        }
        return Math.round(n * 10000) / 10000;
    }
    // Integer decomposition keeps the cent decision exact without a large Q*P
    // product; every intermediate stays within JavaScript's safe integer range.
    function amountCents(quantity, price) {
        var q = Math.round(quantity * 10000), p = Math.round(price * 10000);
        var qi = Math.floor(q / 10000), qf = q % 10000, pi = Math.floor(p / 10000), pf = p % 10000;
        var cross = qi * pf + pi * qf;
        return qi * pi * 100 + Math.floor(cross / 100) + Math.floor(((cross % 100) * 10000 + qf * pf + 500000) / 1000000);
    }
    function unitCost(cents, net) {
        if (net <= 0) return 0;
        var numerator = cents * 100, denominator = Math.round(net * 10000);
        var whole = Math.floor(numerator / denominator), remainder = numerator % denominator;
        var first = Math.floor(remainder * 1000 / denominator), rest = remainder * 1000 % denominator;
        var last = Math.floor((rest * 1000 + Math.floor(denominator / 2)) / denominator);
        return whole + (first * 1000 + last) / 1000000;
    }
    function calculate(document) {
        var net = parseNumber(document.net_kg), cents = 0, groups = { A: 0, B: 0, D: 0 }, seen = {};
        if (!Array.isArray(document.items) || document.items.length !== Object.keys(CATALOG).length) throw new Error('Maliyet kalemleri eksik.');
        var items = document.items.map(function (item) {
            var meta = CATALOG[item.code];
            if (!meta || seen[item.code]) throw new Error('Maliyet kalemi geçersiz veya tekrarlı.');
            seen[item.code] = true;
            if (!Object.prototype.hasOwnProperty.call(UNITS, item.unit)) throw new Error('Birim geçersiz.');
            if (['net_kg', 'manual', 'fixed'].indexOf(item.basis) === -1) throw new Error('Miktar yöntemi geçersiz.');
            if (item.basis === 'net_kg' && item.unit !== 'kg') throw new Error('Net kg yalnız Kg birimi ile kullanılabilir.');
            if ((item.basis === 'fixed') !== (item.unit === 'fixed')) throw new Error('Sabit tutar miktarı 1 olmalıdır.');
            var quantity = item.basis === 'net_kg' ? net : (item.basis === 'fixed' ? 1 : parseNumber(item.qty));
            var price = parseNumber(item.unit_price), lineCents = amountCents(quantity, price), amount = lineCents / 100;
            if (amount > TOTAL_MAX) throw new Error('Kalem tutarı üst sınırı aşıyor.');
            cents += lineCents; groups[meta.group] += lineCents;
            return Object.assign({}, item, { qty: quantity, unit_price: price, amount: amount, group: meta.group, label: meta.label });
        });
        if (cents > Math.round(TOTAL_MAX * 100)) throw new Error('Toplam maliyet üst sınırı aşıyor.');
        Object.keys(groups).forEach(function (group) { groups[group] /= 100; });
        return { items: items, groups: groups, total: cents / 100, unit_cost: unitCost(cents, net), net_kg: net };
    }
    function setMethod(document, method) {
        if (['quick', 'detailed'].indexOf(method) === -1) throw new Error('Hesaplama yöntemi geçersiz.');
        var next = clone(document); next.method = method; return next;
    }
    function setNetKg(document, value) {
        var next = clone(document); next.net_kg = value;
        next.items.forEach(function (item) { if (item.basis === 'net_kg') item.qty = value; });
        return next;
    }
    function updateRow(document, code, field, value) {
        var next = clone(document), item = next.items.find(function (row) { return row.code === code; });
        if (!item || ['qty', 'unit_price', 'unit'].indexOf(field) === -1) throw new Error('Maliyet alanı geçersiz.');
        if (field === 'unit') {
            if (!Object.prototype.hasOwnProperty.call(UNITS, value)) throw new Error('Birim geçersiz.');
            item.unit = value; item.basis = value === 'fixed' ? 'fixed' : 'manual';
            if (value === 'fixed') item.qty = 1;
        } else if (field === 'qty') {
            if (item.unit === 'fixed') { item.qty = 1; item.basis = 'fixed'; }
            else { item.qty = value; item.basis = 'manual'; }
        } else item.unit_price = value;
        return next;
    }
    function format(value, decimals) {
        return Number(value).toLocaleString('tr-TR', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    }
    function editFormat(value) {
        return typeof value === 'number' ? value.toLocaleString('tr-TR', { maximumFractionDigits: 4 }) : String(value == null ? '' : value);
    }
    function canonicalPayload(document) {
        var result = calculate(document);
        return {
            product: document.product, sheet_date: document.sheet_date, operation_type: document.operation_type,
            method: document.method, net_kg: result.net_kg, notes: document.notes,
            items: result.items.map(function (item) { return { code: item.code, unit: item.unit, qty: item.qty, unit_price: item.unit_price, basis: item.basis }; })
        };
    }
    function init(dom, config) {
        var form = dom.getElementById('qc-form');
        if (!form || !config || !config.document || form.dataset.qcReady === '1') return;
        form.dataset.qcReady = '1';
        var state = clone(config.document), readOnly = !!config.readOnly, dirty = false, submitted = false, latest = null;
        var validation = dom.getElementById('qc-validation'), save = dom.getElementById('qc-save'), announceTimer;
        var rowNodes = {};
        form.querySelectorAll('[data-qc-row]').forEach(function (node) { rowNodes[node.dataset.qcRow] = node; });
        function setText(id, value) { var node = dom.getElementById(id); if (node) node.textContent = value; }
        function findItem(code) { return state.items.find(function (item) { return item.code === code; }); }
        function showError(message) { validation.textContent = message; validation.hidden = !message; }
        function syncValue(input, value, source) { if (input !== source && input.value !== value) input.value = value; }
        function paintInputs(source) {
            form.querySelectorAll('[data-qc-operation]').forEach(function (button) { button.setAttribute('aria-pressed', String(button.dataset.qcOperation === state.operation_type)); });
            form.querySelectorAll('[data-qc-header]').forEach(function (input) { syncValue(input, editFormat(state[input.dataset.qcHeader]), source); });
            state.items.forEach(function (item) {
                var node = rowNodes[item.code]; if (!node) return;
                syncValue(node.querySelector('[data-qc-field="unit"]'), item.unit, source);
                syncValue(node.querySelector('[data-qc-field="qty"]'), editFormat(item.basis === 'net_kg' ? state.net_kg : item.qty), source);
                syncValue(node.querySelector('[data-qc-price="settings"]'), editFormat(item.unit_price), source);
                if (item.basis !== 'manual') syncValue(node.querySelector('[data-qc-price="main"]'), editFormat(item.unit_price), source);
            });
            setText('qc-operation-hint', state.operation_type === 'uretim' ? 'Üretim için Ürün KG üzerinden maliyet hesabı.' : 'Fason işlem için Ürün KG üzerinden maliyet hesabı.');
        }
        function validateInputs() {
            form.querySelectorAll('input[inputmode="decimal"]').forEach(function (input) {
                if (input.readOnly || input.disabled) { input.removeAttribute('aria-invalid'); input.setCustomValidity(''); return; }
                try { parseNumber(input.value); input.removeAttribute('aria-invalid'); input.setCustomValidity(''); }
                catch (error) { input.setAttribute('aria-invalid', 'true'); input.setCustomValidity(error.message); }
            });
            state.items.forEach(function (item) {
                var node = rowNodes[item.code]; if (!node) return;
                var settings = node.querySelector('[data-qc-settings]'), invalid = !!node.querySelector('[aria-invalid="true"]');
                settings.classList.toggle('has-error', invalid);
                settings.querySelector('summary').title = invalid ? 'Geçersiz değer var. Ayar bölümünü kontrol edin.' : '';
            });
        }
        function paint(source) {
            var error = '';
            try { latest = calculate(state); } catch (failure) { latest = null; error = failure.message; }
            setText('qc-total', latest ? format(latest.total, 2) : '—');
            setText('qc-unit-cost', latest ? format(latest.unit_cost, 2) : '—');
            setText('qc-net-summary', latest ? editFormat(latest.net_kg) : '—');
            setText('qc-summary-total', latest ? format(latest.total, 2) + ' TL' : '—');
            setText('qc-summary-unit', latest ? format(latest.unit_cost, 2) + ' TL/kg' : '—');
            ['A', 'B', 'D'].forEach(function (group) { setText('qc-group-' + group, latest ? format(latest.groups[group], 2) + ' TL' : '—'); });
            paintInputs(source);
            state.items.forEach(function (item, index) {
                var node = rowNodes[item.code]; if (!node) return;
                var computed = latest && latest.items[index], manual = item.basis === 'manual';
                var price = node.querySelector('[data-qc-price="main"]'), qty = node.querySelector('[data-qc-field="qty"]');
                node.querySelector('[data-qc-amount]').textContent = computed ? format(computed.amount, 2) + ' TL' : '—';
                qty.disabled = readOnly || item.basis === 'fixed';
                price.readOnly = manual;
                if (manual) price.value = computed && latest.net_kg > 0 ? format(computed.amount / latest.net_kg, 4) : '—';
                var rebind = node.querySelector('[data-qc-rebind]');
                rebind.hidden = readOnly || item.unit !== 'kg' || item.basis === 'net_kg';
                var priceLabel = manual ? 'Eşdeğer fiyat (TL/Ürün KG)' : (item.basis === 'fixed' ? 'Sabit gider (TL)' : 'Birim fiyat (TL/kg)');
                node.querySelector('[data-qc-price-label]').textContent = priceLabel;
                price.setAttribute('aria-label', CATALOG[item.code].label + ': ' + priceLabel);
                node.querySelector('[data-qc-basis]').textContent = item.basis === 'net_kg' ? 'Miktar Ürün KG ile birlikte güncellenir.' : item.basis === 'fixed' ? 'Sabit gider · miktar 1' : 'Miktar: ' + editFormat(item.qty) + ' ' + UNITS[item.unit] + ' · düzenlemek için Ayar bölümünü açın.';
            });
            validateInputs();
            showError(error);
            if (save) save.disabled = form.dataset.saveAvailable !== '1' || !latest || readOnly;
            clearTimeout(announceTimer);
            announceTimer = setTimeout(function () { setText('qc-live-total', latest ? 'Toplam ' + format(latest.total, 2) + ' TL, net kilogram maliyeti ' + format(latest.unit_cost, 2) + ' TL.' : 'Hesap için sayısal girişleri kontrol edin.'); }, 350);
        }
        form.addEventListener('input', function (event) {
            var input = event.target; if (readOnly) return;
            if (input.dataset.qcHeader) {
                var field = input.dataset.qcHeader;
                state = field === 'net_kg' ? setNetKg(state, input.value) : Object.assign({}, state, { [field]: input.value });
            } else if (input.dataset.qcCode && input.dataset.qcPrice === 'main' && !input.readOnly) {
                state = updateRow(state, input.dataset.qcCode, 'unit_price', input.value);
            } else if (input.dataset.qcCode && input.dataset.qcField && input.dataset.qcField !== 'unit' && !input.readOnly) {
                state = updateRow(state, input.dataset.qcCode, input.dataset.qcField, input.value);
            } else return;
            dirty = true; paint(input);
        });
        form.addEventListener('change', function (event) {
            var input = event.target; if (readOnly || input.dataset.qcField !== 'unit') return;
            state = updateRow(state, input.dataset.qcCode, 'unit', input.value); dirty = true; paint(input);
        });
        form.addEventListener('click', function (event) {
            var button = event.target.closest('button'); if (!button) return;
            if (button.dataset.qcOperation && !readOnly) { state.operation_type = button.dataset.qcOperation; dirty = true; paint(); }
            else if (button.dataset.qcRebind && !readOnly) {
                var item = findItem(button.dataset.qcRebind);
                if (item.unit === 'kg') { item.basis = 'net_kg'; item.qty = state.net_kg; dirty = true; paint(); }
            }
        });
        form.addEventListener('submit', function (event) {
            try {
                if (readOnly || form.dataset.saveAvailable !== '1') throw new Error('Bu hesabı kaydetme yetkiniz veya kullanılabilir kayıt alanı yok.');
                var payload = canonicalPayload(state);
                if (payload.net_kg <= 0) throw new Error('Kaydetmek için Ürün KG sıfırdan büyük olmalıdır.');
                if (!String(payload.product || '').trim()) throw new Error('Ürün adı girin.');
                if (!form.checkValidity()) { event.preventDefault(); form.reportValidity(); return; }
                dom.getElementById('qc-payload').value = JSON.stringify(payload);
                submitted = true; if (save) { save.disabled = true; save.textContent = 'Kaydediliyor…'; }
            } catch (failure) { event.preventDefault(); showError(failure.message); validation.scrollIntoView({ block: 'nearest' }); }
        });
        if (!readOnly && dom.defaultView) dom.defaultView.addEventListener('beforeunload', function (event) {
            if (!dirty || submitted) return; event.preventDefault(); event.returnValue = '';
        });
        paint();
        return { getDocument: function () { return clone(state); }, calculate: function () { return calculate(state); } };
    }
    return { parseNumber: parseNumber, calculate: calculate, setMethod: setMethod, setNetKg: setNetKg, updateRow: updateRow, canonicalPayload: canonicalPayload, init: init, catalog: CATALOG };
}));
