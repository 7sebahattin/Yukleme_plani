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
    var CUSTOM_CODE = /^custom_[a-z0-9_]{1,40}$(?![\s\S])/;
    function clone(value) { return JSON.parse(JSON.stringify(value)); }
    function upperText(value) { return String(value == null ? '' : value).toLocaleUpperCase('tr-TR'); }
    function validatedUpper(value, label, limit, required) {
        if (typeof value !== 'string' || Array.from(value).length > limit || /[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/.test(value)) throw new Error(label + ': geçersiz veya çok uzun metin.');
        var result = upperText(value.trim());
        if (Array.from(result).length > limit || (required && !result)) throw new Error(label + ': ad girin ve metin sınırını aşmayın.');
        return result;
    }
    function customCode(code) { return typeof code === 'string' && CUSTOM_CODE.test(code); }
    function rowLabel(item) { return Object.prototype.hasOwnProperty.call(CATALOG, item.code) ? CATALOG[item.code].label : upperText(item.label || '').trim(); }
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
        var net = parseNumber(document.net_kg), cents = 0, groups = { A: 0, B: 0, D: 0 }, seen = Object.create(null);
        if (!Array.isArray(document.items) || document.items.length < 10 || document.items.length > 50) throw new Error('On temel kalem ve en fazla kırk yeni kalem kullanılabilir.');
        var items = document.items.map(function (item) {
            if (!item || typeof item !== 'object' || seen[item.code]) throw new Error('Maliyet kalemi geçersiz veya tekrarlı.');
            var builtin = Object.prototype.hasOwnProperty.call(CATALOG, item.code), meta;
            if (builtin) meta = CATALOG[item.code];
            else {
                if (!customCode(item.code)) throw new Error('Yeni kalem kodu geçersiz.');
                var label = validatedUpper(item.label, 'Yeni kalem adı', 150, true);
                meta = { label: label, group: 'D' };
            }
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
        if (!Object.keys(CATALOG).every(function (code) { return seen[code]; })) throw new Error('On temel maliyet kalemi korunmalıdır.');
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
        if (!item || ['qty', 'unit_price', 'unit', 'label'].indexOf(field) === -1) throw new Error('Maliyet alanı geçersiz.');
        if (field === 'label') {
            if (!customCode(code)) throw new Error('Temel kalemlerin adı değiştirilemez.');
            item.label = upperText(value); return next;
        }
        if (field === 'unit') {
            if (!Object.prototype.hasOwnProperty.call(UNITS, value)) throw new Error('Birim geçersiz.');
            if (value === item.unit) return next;
            // Eski miktar eski birime aittir (ör. 20.000 kg → 20.000 ton): yeni birimde 1'den başlar.
            item.unit = value; item.basis = value === 'fixed' ? 'fixed' : 'manual';
            item.qty = 1;
        } else if (field === 'qty') {
            if (item.unit === 'fixed') { item.qty = 1; item.basis = 'fixed'; }
            else { item.qty = value; item.basis = 'manual'; }
        } else item.unit_price = value;
        return next;
    }
    function addCustomRow(document, code) {
        if (!customCode(code) || document.items.some(function (item) { return item.code === code; })) throw new Error('Yeni kalem kodu geçersiz veya tekrarlı.');
        if (document.items.filter(function (item) { return customCode(item.code); }).length >= 40 || document.items.length >= 50) throw new Error('En fazla kırk yeni kalem eklenebilir.');
        var next = clone(document);
        next.items.push({ code: code, label: '', unit: 'fixed', basis: 'fixed', qty: 1, unit_price: 0 });
        return next;
    }
    function removeCustomRow(document, code) {
        if (!customCode(code) || !document.items.some(function (item) { return item.code === code; })) throw new Error('Yalnız eklenen kalemler kaldırılabilir.');
        var next = clone(document); next.items = next.items.filter(function (item) { return item.code !== code; }); return next;
    }
    function format(value, decimals) {
        return Number(value).toLocaleString('tr-TR', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    }
    function editFormat(value) {
        return typeof value === 'number' ? value.toLocaleString('tr-TR', { maximumFractionDigits: 4 }) : String(value == null ? '' : value);
    }
    // "1.234" Türkçede binlik ayraçlıdır (1234); kullanıcı 1,234 kastetmiş olabilir.
    function numberReadNote(value) {
        var text = String(value == null ? '' : value).trim();
        if (!/^\d{1,3}(?:\.\d{3})+$/.test(text)) return '';
        try { return '"' + text + '" sayısı ' + String(parseNumber(text)) + ' olarak okunur. Ondalık için virgül kullanın: ' + text.replace('.', ',') + '.'; }
        catch (_) { return ''; }
    }
    function isZeroInput(value) {
        if (typeof value !== 'string' || value.trim() === '') return false;
        try { return parseNumber(value) === 0; } catch (_) { return false; }
    }
    function normalizeInput(value) {
        var text = String(value);
        if (text.trim() === '') return '0';
        try {
            var number = parseNumber(text);
            return /^0\d/.test(text.trim()) ? editFormat(number) : text;
        } catch (_) { return text; }
    }
    // Explain the row's calculation in words, independently of numeric drafts.
    function formulaText(item) {
        if (item && item.basis === 'net_kg' && item.unit === 'kg') return 'Ürün KG × fiyat = toplam maliyet';
        var explanations = {
            kg: 'Kg miktarı × kg fiyatı = toplam maliyet',
            ton: 'Ton miktarı × ton fiyatı = toplam maliyet',
            piece: 'Adet × adet fiyatı = toplam maliyet',
            box: 'Koli / kasa adedi × birim fiyat = toplam maliyet',
            pallet: 'Palet adedi × palet fiyatı = toplam maliyet',
            vehicle: 'Araç sayısı × araç fiyatı = toplam maliyet',
            fixed: 'Sabit tutar = toplam maliyet'
        };
        return item && Object.prototype.hasOwnProperty.call(explanations, item.unit) ? explanations[item.unit] : 'Miktar × birim fiyat = toplam maliyet';
    }
    function canonicalPayload(document) {
        var result = calculate(document);
        return {
            product: validatedUpper(document.product, 'Ürün', 150, false), sheet_date: document.sheet_date, operation_type: document.operation_type,
            method: document.method, net_kg: result.net_kg, notes: validatedUpper(document.notes, 'Açıklama', 2000, false),
            items: result.items.map(function (item) {
                var row = { code: item.code, unit: item.unit, qty: item.qty, unit_price: item.unit_price, basis: item.basis };
                if (customCode(item.code)) row.label = item.label;
                return row;
            })
        };
    }
    function init(dom, config) {
        var form = dom.getElementById('qc-form');
        if (!form || !config || !config.document || form.dataset.qcReady === '1') return;
        form.dataset.qcReady = '1';
        var state = clone(config.document), readOnly = !!config.readOnly, dirty = false, submitted = false, latest = null, zeroClearedInput = null;
        var composingInputs = new Set(), customSequence = 0;
        state.product = upperText(state.product); state.notes = upperText(state.notes);
        state.items.forEach(function (item) { if (customCode(item.code)) item.label = upperText(item.label); });
        var validation = dom.getElementById('qc-validation'), save = dom.getElementById('qc-save'), announceTimer;
        var rowNodes = {};
        form.querySelectorAll('[data-qc-row]').forEach(function (node) { rowNodes[node.dataset.qcRow] = node; });
        function setText(id, value) { var node = dom.getElementById(id); if (node) node.textContent = value; }
        function findItem(code) { return state.items.find(function (item) { return item.code === code; }); }
        function noteHost(input) { return input.parentNode; }
        function findChild(host, cls) { return Array.prototype.find.call(host.children, function (child) { return child.classList.contains(cls); }); }
        function showReadNote(input) {
            var host = noteHost(input), note = findChild(host, 'qc-read-note'), text = numberReadNote(input.value);
            if (!text) { if (note) note.remove(); return; }
            if (!note) { note = dom.createElement('small'); note.className = 'qc-read-note'; note.setAttribute('role', 'status'); host.appendChild(note); }
            note.textContent = text;
        }
        function hideReadNote(input) { var note = findChild(noteHost(input), 'qc-read-note'); if (note) note.remove(); }
        function rowNote(node, text) {
            var settings = node.querySelector('[data-qc-settings]'), note = findChild(settings, 'qc-unit-note');
            if (!text) { if (note) note.remove(); return; }
            if (!note) { note = dom.createElement('p'); note.className = 'qc-hint qc-unit-note'; note.setAttribute('role', 'status'); settings.appendChild(note); }
            note.textContent = text;
        }
        var kbdTimer;
        function showError(message) { validation.textContent = message; validation.hidden = !message; }
        function syncValue(input, value, source) {
            if (composingInputs.has(input)) return;
            if (input === zeroClearedInput && dom.activeElement === input && input.value === '') return;
            if (input !== source && input.value !== value) input.value = value;
        }
        function paintInputs(source) {
            form.querySelectorAll('[data-qc-header]').forEach(function (input) { syncValue(input, editFormat(state[input.dataset.qcHeader]), source); });
            state.items.forEach(function (item) {
                var node = rowNodes[item.code]; if (!node) return;
                syncValue(node.querySelector('[data-qc-field="unit"]'), item.unit, source);
                syncValue(node.querySelector('[data-qc-field="qty"]'), editFormat(item.basis === 'net_kg' ? state.net_kg : item.qty), source);
                syncValue(node.querySelector('[data-qc-price="settings"]'), editFormat(item.unit_price), source);
                if (item.basis !== 'manual') syncValue(node.querySelector('[data-qc-price="main"]'), editFormat(item.unit_price), source);
                var labelInput = node.querySelector('[data-qc-field="label"]');
                if (labelInput) syncValue(labelInput, item.label || '', source);
            });
        }
        function validateInputs() {
            form.querySelectorAll('input[inputmode="decimal"]').forEach(function (input) {
                if (input.readOnly || input.disabled) { input.removeAttribute('aria-invalid'); input.setCustomValidity(''); return; }
                try { parseNumber(input.value); input.removeAttribute('aria-invalid'); input.setCustomValidity(''); }
                catch (error) { input.setAttribute('aria-invalid', 'true'); input.setCustomValidity(error.message); }
            });
            form.querySelectorAll('[data-qc-header="product"], [data-qc-header="notes"], [data-qc-field="label"]').forEach(function (input) {
                if (input.disabled || composingInputs.has(input)) { input.removeAttribute('aria-invalid'); input.setCustomValidity(''); return; }
                try {
                    validatedUpper(input.value, input.dataset.qcHeader === 'notes' ? 'Açıklama' : 'Ad', input.dataset.qcHeader === 'notes' ? 2000 : 150, input.dataset.qcField === 'label');
                    input.removeAttribute('aria-invalid'); input.setCustomValidity('');
                } catch (error) { input.setAttribute('aria-invalid', 'true'); input.setCustomValidity(error.message); }
            });
            state.items.forEach(function (item) {
                var node = rowNodes[item.code]; if (!node) return;
                var settings = node.querySelector('[data-qc-settings]'), invalid = !!node.querySelector('[aria-invalid="true"]');
                settings.classList.toggle('has-error', invalid);
                settings.querySelector('summary').title = invalid ? 'Kalemde geçersiz değer var.' : '';
            });
        }
        function paint(source) {
            var error = '';
            try { latest = calculate(state); } catch (failure) { latest = null; error = failure.message; }
            setText('qc-total', latest ? format(latest.total, 2) : '—');
            setText('qc-unit-cost', latest ? format(latest.unit_cost, 4) : '—');
            setText('qc-net-summary', latest ? editFormat(latest.net_kg) : '—');
            setText('qc-summary-total', latest ? format(latest.total, 2) + ' TL' : '—');
            setText('qc-summary-unit', latest ? format(latest.unit_cost, 4) + ' TL/kg' : '—');
            ['A', 'B', 'D'].forEach(function (group) { setText('qc-group-' + group, latest ? format(latest.groups[group], 2) + ' TL' : '—'); });
            paintInputs(source);
            state.items.forEach(function (item, index) {
                var node = rowNodes[item.code]; if (!node) return;
                var computed = latest && latest.items[index], manual = item.basis === 'manual';
                var label = rowLabel(item) || 'Yeni kalem';
                node.querySelector('[data-qc-row-title]').textContent = label;
                var price = node.querySelector('[data-qc-price="main"]'), qty = node.querySelector('[data-qc-field="qty"]');
                node.querySelector('[data-qc-amount]').textContent = computed ? format(computed.amount, 2) + ' TL' : '—';
                qty.disabled = readOnly || item.basis === 'fixed';
                price.readOnly = manual;
                if (manual) price.value = computed && latest.net_kg > 0 ? format(computed.amount / latest.net_kg, 4) : '—';
                var rebind = node.querySelector('[data-qc-rebind]');
                rebind.hidden = readOnly || item.unit !== 'kg' || item.basis === 'net_kg';
                var priceLabel = manual ? 'Eşdeğer fiyat (TL/Ürün KG)' : (item.basis === 'fixed' ? 'Sabit gider (TL)' : 'Birim fiyat (TL/kg)');
                node.querySelector('[data-qc-price-label]').textContent = priceLabel;
                price.setAttribute('aria-label', label + ': ' + priceLabel);
                qty.setAttribute('aria-label', label + ' miktarı');
                node.querySelector('[data-qc-field="unit"]').setAttribute('aria-label', label + ' birimi');
                node.querySelector('[data-qc-price="settings"]').setAttribute('aria-label', label + ' Ayar birim fiyatı (TL)');
                node.querySelector('summary').setAttribute('aria-label', label + ' ayarları');
                node.querySelector('[data-qc-amount]').setAttribute('aria-label', label + ' tutarı');
                var remove = node.querySelector('[data-qc-remove]');
                if (remove) remove.setAttribute('aria-label', label + ' kalemini kaldır');
                node.querySelector('[data-qc-basis]').textContent = formulaText(item);
            });
            validateInputs();
            showError(error);
            if (save) save.disabled = form.dataset.saveAvailable !== '1' || !latest || readOnly || composingInputs.size > 0 || !!form.querySelector('[aria-invalid="true"]');
            var addButton = dom.getElementById('qc-add-item'), limit = state.items.filter(function (item) { return customCode(item.code); }).length >= 40;
            if (addButton) addButton.disabled = readOnly || limit;
            var limitHint = dom.getElementById('qc-custom-limit'); if (limitHint) limitHint.hidden = !limit;
            clearTimeout(announceTimer);
            announceTimer = setTimeout(function () { setText('qc-live-total', latest ? 'Toplam ' + format(latest.total, 2) + ' TL, net kilogram maliyeti ' + format(latest.unit_cost, 4) + ' TL.' : 'Hesap için sayısal girişleri kontrol edin.'); }, 350);
        }
        function editableNumber(input) {
            return !readOnly && input && input.matches('input[inputmode="decimal"]') && !input.disabled && !input.readOnly;
        }
        function keyboardField(el) { return !!el && el.matches && el.matches('input:not([type="hidden"]):not([type="date"]), textarea'); }
        form.addEventListener('focusin', function (event) {
            var input = event.target;
            if (keyboardField(input)) { clearTimeout(kbdTimer); form.classList.add('qc-kbd'); }
            if (!readOnly && input.matches && input.matches('input[inputmode="decimal"]')) showReadNote(input);
            if (editableNumber(input) && isZeroInput(input.value)) {
                zeroClearedInput = input;
                input.value = '';
            }
        });
        form.addEventListener('focusout', function (event) {
            var input = event.target;
            if (keyboardField(input)) { clearTimeout(kbdTimer); kbdTimer = setTimeout(function () { if (!keyboardField(dom.activeElement)) form.classList.remove('qc-kbd'); }, 150); }
            if (input && input.matches && input.matches('input[inputmode="decimal"]')) hideReadNote(input);
            if (!editableNumber(input)) return;
            var normalized = normalizeInput(input.value);
            if (input === zeroClearedInput) zeroClearedInput = null;
            if (normalized === input.value) return;
            input.value = normalized;
            // Display-only normalization must not turn a linked quantity into
            // an independent one or mark an untouched zero as an unsaved edit.
            if (input.dataset.qcHeader === 'net_kg') state = setNetKg(state, normalized);
            else {
                var item = findItem(input.dataset.qcCode);
                if (!item) return;
                if (input.dataset.qcPrice) item.unit_price = normalized;
                else if (input.dataset.qcField === 'qty') {
                    if (item.basis === 'net_kg') state = setNetKg(state, normalized);
                    else item.qty = normalized;
                }
            }
            paint(input);
        });
        function uppercaseInput(input) {
            if (!input.matches('[data-qc-header="product"], [data-qc-header="notes"], [data-qc-field="label"]')) return;
            var before = input.value, upper = upperText(before);
            if (upper === before) return;
            var start = input.selectionStart, end = input.selectionEnd, direction = input.selectionDirection;
            input.value = upper;
            if (start !== null && end !== null) input.setSelectionRange(upperText(before.slice(0, start)).length, upperText(before.slice(0, end)).length, direction || 'none');
        }
        function handleInput(event) {
            var input = event.target; if (readOnly) return;
            if (input.matches && input.matches('input[inputmode="decimal"]')) {
                showReadNote(input);
                if (input.dataset.qcField === 'qty' && rowNodes[input.dataset.qcCode]) rowNote(rowNodes[input.dataset.qcCode], '');
            }
            var composing = event.isComposing || composingInputs.has(input);
            if (!composing) uppercaseInput(input);
            if (input === zeroClearedInput) zeroClearedInput = null;
            if (input.dataset.qcHeader) {
                var field = input.dataset.qcHeader;
                state = field === 'net_kg' ? setNetKg(state, input.value) : Object.assign({}, state, { [field]: input.value });
            } else if (input.dataset.qcCode && input.dataset.qcPrice === 'main' && !input.readOnly) {
                state = updateRow(state, input.dataset.qcCode, 'unit_price', input.value);
            } else if (input.dataset.qcCode && input.dataset.qcField && input.dataset.qcField !== 'unit' && !input.readOnly) {
                if (input.dataset.qcField === 'label' && composing) findItem(input.dataset.qcCode).label = input.value;
                else state = updateRow(state, input.dataset.qcCode, input.dataset.qcField, input.value);
            } else return;
            dirty = true; paint(input);
        }
        form.addEventListener('input', handleInput);
        form.addEventListener('compositionstart', function (event) {
            if (readOnly) return; composingInputs.add(event.target); if (save) save.disabled = true;
        });
        form.addEventListener('compositionend', function (event) {
            if (readOnly) return; composingInputs.delete(event.target); handleInput({ target: event.target, isComposing: false });
        });
        form.addEventListener('change', function (event) {
            var input = event.target; if (readOnly || input.dataset.qcField !== 'unit') return;
            var changed = findItem(input.dataset.qcCode);
            var previousUnit = changed && changed.unit;
            state = updateRow(state, input.dataset.qcCode, 'unit', input.value); dirty = true; paint(input);
            if (previousUnit && previousUnit !== input.value && rowNodes[input.dataset.qcCode]) rowNote(rowNodes[input.dataset.qcCode], input.value === 'fixed' ? '' : 'Birim değişti; miktar 1 olarak sıfırlandı. Yeni miktarı girin.');
        });
        form.addEventListener('click', function (event) {
            var button = event.target.closest('button'); if (!button) return;
            if (button.id === 'qc-add-item' && !readOnly) {
                try {
                    var win = dom.defaultView, suffix = win && win.crypto && typeof win.crypto.randomUUID === 'function' ? win.crypto.randomUUID().replace(/-/g, '') : Date.now().toString(36) + (++customSequence).toString(36);
                    var code = 'custom_' + suffix;
                    while (state.items.some(function (item) { return item.code === code; })) code = 'custom_' + Date.now().toString(36) + (++customSequence).toString(36);
                    state = addCustomRow(state, code);
                    var node = dom.getElementById('qc-custom-row-template').content.firstElementChild.cloneNode(true);
                    node.dataset.qcRow = code;
                    node.querySelectorAll('[id]').forEach(function (element) { element.id = element.id.replace(/custom_template$/, code); });
                    node.querySelectorAll('label[for]').forEach(function (element) { element.htmlFor = element.htmlFor.replace(/custom_template$/, code); });
                    node.querySelectorAll('[data-qc-code]').forEach(function (element) { element.dataset.qcCode = code; });
                    ['qcAmount','qcPriceLabel','qcBasis','qcSettings','qcRebind','qcRemove'].forEach(function (key) {
                        node.querySelectorAll('[data-' + key.replace(/[A-Z]/g, function (letter) { return '-' + letter.toLowerCase(); }) + ']').forEach(function (element) { element.dataset[key] = code; });
                    });
                    dom.getElementById('qc-rows').appendChild(node); rowNodes[code] = node;
                    dirty = true; paint(); node.querySelector('[data-qc-field="label"]').focus();
                } catch (failure) { showError(failure.message); }
            } else if (button.dataset.qcRemove && !readOnly) {
                var codeToRemove = button.dataset.qcRemove, removed = rowNodes[codeToRemove];
                state = removeCustomRow(state, codeToRemove);
                if (zeroClearedInput && removed.contains(zeroClearedInput)) zeroClearedInput = null;
                composingInputs.forEach(function (input) { if (removed.contains(input)) composingInputs.delete(input); });
                removed.remove(); delete rowNodes[codeToRemove]; dirty = true; paint();
            } else if (button.dataset.qcRebind && !readOnly) {
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
    return { parseNumber: parseNumber, calculate: calculate, setMethod: setMethod, setNetKg: setNetKg, updateRow: updateRow, addCustomRow: addCustomRow, removeCustomRow: removeCustomRow, upperText: upperText, canonicalPayload: canonicalPayload, isZeroInput: isZeroInput, numberReadNote: numberReadNote, normalizeInput: normalizeInput, formulaText: formulaText, init: init, catalog: CATALOG };
}));
