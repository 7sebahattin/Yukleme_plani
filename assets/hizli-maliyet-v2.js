/* Hızlı Maliyet v2 — config/quick_cost_v2.php motorunun BİREBİR aynası (yalnız canlı önizleme).
   Kaydedilen sonuçları PHP yeniden hesaplar. scripts/quick_cost_v2_parity_smoke.js iki motoru karşılaştırır. */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else { root.QuickCostV2 = api; if (root.document) api.init(root.document, root.QUICK_COST_CONFIG); }
}(typeof window !== 'undefined' ? window : globalThis, function () {
    'use strict';
    var NUMBER_MAX = 1000000, TOTAL_MAX_CENTS = 999999999999, RATE_MAX = 1000, CUSTOM_MAX = 40;
    var CATALOG = {
        raw_purchase: { label: 'Ham mal alış bedeli', group: 'raw', basis: 'raw_kg', unit: 'kg', quick: true },
        harvest: { label: 'Hasat / toplama', group: 'supply', basis: 'raw_kg', unit: 'kg', quick: false },
        field_transport: { label: 'Tarladan fabrikaya nakliye', group: 'supply', basis: 'fixed', unit: 'fixed', quick: true },
        packing_labor: { label: 'Paketleme işçiliği', group: 'packing', basis: 'raw_kg', unit: 'kg', quick: true },
        material: { label: 'Malzeme', group: 'material', basis: 'export_kg', unit: 'kg', quick: true, detail: true },
        export_freight: { label: 'İhracat nakliyesi', group: 'export', basis: 'fixed', unit: 'fixed', quick: true },
        customs: { label: 'Gümrükleme', group: 'export', basis: 'fixed', unit: 'fixed', quick: true },
        official_analysis: { label: 'Resmi analiz', group: 'export', basis: 'fixed', unit: 'fixed', quick: true },
        private_analysis: { label: 'Özel analiz', group: 'export', basis: 'fixed', unit: 'fixed', quick: true },
        dts_taxi: { label: 'DTS taksi', group: 'export', basis: 'fixed', unit: 'fixed', quick: false },
        overhead: { label: 'İşletme gideri', group: 'other', basis: 'export_kg', unit: 'kg', quick: true },
        casus: { label: 'CASUS', group: 'export', basis: 'fixed', unit: 'fixed', quick: false }
    };
    var GROUPS = ['raw', 'supply', 'packing', 'material', 'export', 'other'];
    var MAT_LINES = { mat_pallet: 'PALET', mat_hat: 'ŞAPKA', mat_corner: 'KÖŞEBENT', mat_crate: 'KASA', mat_divider: 'VİYOL',
        mat_edge: 'KENAR KARTONU', mat_base: 'TABAN KAĞIDI', mat_strap: 'ŞERİT', mat_net: 'FİLE' };
    var MAT_CUSTOM_MAX = 20, PER_PALLET_MAX_CENTS = 100000000;
    var LEGACY_CODES = ['custom_eski_ayiklama', 'custom_eski_boylama', 'custom_eski_yukleme', 'custom_eski_soguk_hava', 'custom_eski_fason', 'custom_eski_fabrika'];
    function userCustomCount(items) { return items.filter(function (x) { return customCode(x.code) && LEGACY_CODES.indexOf(x.code) < 0; }).length; }
    var MAT_CUSTOM_CODE = /^mat_custom_[a-z0-9_]{1,30}$(?![\s\S])/;
    var UNITS = {
        kg: { label: 'Kg', price: 'TL/kg' }, ton: { label: 'Ton', price: 'TL/ton' },
        box: { label: 'Koli / kasa', price: 'TL/koli' }, pallet: { label: 'Palet', price: 'TL/palet' },
        vehicle: { label: 'Araç', price: 'TL/araç' }, piece: { label: 'Adet', price: 'TL/adet' },
        day: { label: 'Çalışılan gün', price: 'TL/yevmiye' }, worker_day: { label: 'İşçi × gün', price: 'TL/yevmiye', second: 'Gün' },
        hour: { label: 'Çalışma saati', price: 'TL/saat' }, pallet_day: { label: 'Palet × gün', price: 'TL/palet-gün', second: 'Gün' },
        ton_day: { label: 'Ton × gün', price: 'TL/ton-gün', second: 'Gün' }, fixed: { label: 'Sabit tutar', price: 'TL' }
    };
    var BASES = ['raw_kg', 'processed_kg', 'export_kg', 'manual', 'fixed'];
    var LINKED = { raw_kg: 'Satın alınan ham KG', processed_kg: 'İşlenen ham KG', export_kg: 'İhracat net KG' };
    var LINKED_PRICE = { raw_kg: 'TL/ham kg', processed_kg: 'TL/işlenen kg', export_kg: 'TL/ihracat kg' };
    var QTY_LABEL = { kg: 'Kg', ton: 'Ton', box: 'Koli / kasa adedi', pallet: 'Palet adedi', vehicle: 'Araç sayısı', piece: 'Adet',
        day: 'Çalışılan gün', worker_day: 'İşçi sayısı', hour: 'Toplam çalışma saati', pallet_day: 'Palet adedi', ton_day: 'Ton' };
    var CUSTOM_CODE = /^custom_[a-z0-9_]{1,40}$(?![\s\S])/;
    var has = function (o, k) { return Object.prototype.hasOwnProperty.call(o, k); };

    function clone(v) { return JSON.parse(JSON.stringify(v)); }
    function intdiv(a, b) { return (a - a % b) / b; }
    function i4(v) { return Math.round(v * 10000); }
    function upperText(v) { return String(v == null ? '' : v).toLocaleUpperCase('tr-TR'); }
    function customCode(c) { return typeof c === 'string' && CUSTOM_CODE.test(c); }
    function fail(m) { throw new Error(m); }
    function text(value, label, limit) {
        if (typeof value !== 'string' || Array.from(value).length > limit || /[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/.test(value)) fail(label + ': geçersiz veya çok uzun metin.');
        var up = upperText(value.trim());
        if (Array.from(up).length > limit) fail(label + ': geçersiz veya çok uzun metin.');
        return up;
    }
    function parseNumber(value, label) {
        var n; label = label || 'Değer';
        if (typeof value === 'number') n = value;
        else if (typeof value === 'string') {
            var s = value.trim();
            if (s === '') return 0;
            if (!/^(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d{1,4})?$/.test(s)) fail(label + ': geçerli, negatif olmayan Türkçe sayı girin (ondalık için virgül).');
            n = Number(s.replace(/\./g, '').replace(',', '.'));
        } else fail(label + ': sayı bekleniyor.');
        if (!Number.isFinite(n) || n < 0 || n > NUMBER_MAX || Math.abs(n * 10000 - Math.round(n * 10000)) > 0.00001) fail(label + ': 0–1.000.000 arasında, en fazla dört ondalıklı sayı girin.');
        return Math.round(n * 10000) / 10000;
    }
    function amountCents(qty, price) {
        var q = i4(qty), p = i4(price), qi = intdiv(q, 10000), qf = q % 10000, pi = intdiv(p, 10000), pf = p % 10000;
        var cross = qi * pf + pi * qf;
        return qi * pi * 100 + intdiv(cross, 100) + intdiv((cross % 100) * 10000 + qf * pf + 500000, 1000000);
    }
    function tonCents(kg, price) {
        var q = i4(kg), p = i4(price), qi = intdiv(q, 10000), qf = q % 10000, pi = intdiv(p, 10000), pf = p % 10000;
        var a = qi * pi, cross = qi * pf + pi * qf, f = qf * pf;
        var a1 = intdiv(a, 10), a0 = a % 10, c1 = intdiv(cross, 100000), c0 = cross % 100000;
        return a1 + c1 + intdiv(a0 * 100000000 + c0 * 10000 + f + 500000000, 1000000000);
    }
    function unitCost(cents, net) {
        if (net <= 0) return 0;
        var q = i4(net), num = cents * 100, whole = intdiv(num, q), rem = num % q;
        var first = rem * 1000, hi = intdiv(first, q), rest = first % q;
        var lo = intdiv(rest * 1000 + intdiv(q, 2), q);
        return whole + (hi * 1000 + lo) / 1000000;
    }
    function round(v, d) { var f = Math.pow(10, d), s = v < 0 ? -1 : 1; return s * Math.floor(Math.abs(v) * f + 0.5) / f; }
    function productQty(a, b, label) {
        if (a * b > NUMBER_MAX + 1) fail(label + ': miktar çarpımı 1.000.000 sınırını aşıyor.');
        var v = intdiv(i4(a) * i4(b) + 5000, 10000);
        if (v > NUMBER_MAX * 10000) fail(label + ': miktar çarpımı 1.000.000 sınırını aşıyor.');
        return v / 10000;
    }
    function percent(value, label, allowHundred) {
        var n = parseNumber(value, label);
        if (i4(n) % 100 !== 0) fail(label + ': en fazla iki ondalık girin.');
        if (n > 100 || (!allowHundred && n >= 100)) fail(label + (allowHundred ? ": %100'den büyük olamaz." : ": %100'den küçük olmalı."));
        return n;
    }
    function bool(v, label) { if (v == null) return false; if (typeof v !== 'boolean') fail(label + ': evet/hayır bekleniyor.'); return v; }
    function fmt(v, d) { return Number(v).toLocaleString('tr-TR', { minimumFractionDigits: d, maximumFractionDigits: d }); }
    function fmtKg(v) { return Number(v).toLocaleString('tr-TR', { maximumFractionDigits: 4 }); }

    function materialDetail(raw) {
        var d = raw && typeof raw === 'object' ? raw : {};
        var use = bool(d.use, 'Malzeme detayı seçimi'), pallets = parseNumber(d.pallets == null ? 0 : d.pallets, 'Satılan palet sayısı');
        var rawLines = d.lines == null ? [] : d.lines;
        if (!Array.isArray(rawLines) || rawLines.length > Object.keys(MAT_LINES).length + MAT_CUSTOM_MAX) fail('Malzeme detayı satırları geçersiz ya da en fazla yirmi ek satır aşıldı.');
        var by = Object.create(null), order = Object.keys(MAT_LINES);
        rawLines.forEach(function (line) {
            var c = line && typeof line === 'object' ? line.code : null;
            if (typeof c !== 'string' || (!has(MAT_LINES, c) && !MAT_CUSTOM_CODE.test(c)) || by[c]) fail('Malzeme detayında bilinmeyen veya tekrarlanan satır var.');
            by[c] = line; if (!has(MAT_LINES, c)) order.push(c);
        });
        var lines = [], perPallet = 0;
        order.forEach(function (code) {
            var line = by[code] || {};
            var label = has(MAT_LINES, code) ? MAT_LINES[code] : text(line.label == null ? '' : line.label, 'Malzeme satırı adı', 80);
            var qty = parseNumber(line.qty == null ? 0 : line.qty, (label || 'Ek malzeme') + ' palet başına adet');
            var price = parseNumber(line.price == null ? 0 : line.price, (label || 'Ek malzeme') + ' birim fiyatı');
            if (!label) { if (qty === 0 && price === 0) return; fail('Malzeme satırının adı gerekli.'); }
            var cents = amountCents(qty, price); perPallet += cents;
            lines.push({ code: code, label: label, qty: qty, price: price, per_pallet: cents / 100 });
        });
        if (perPallet > PER_PALLET_MAX_CENTS) fail('Palet başına malzeme maliyeti 1.000.000 TL sınırını aşıyor.');
        var total = amountCents(pallets, perPallet / 100);
        if (total > TOTAL_MAX_CENTS) fail('Malzeme detayı toplamı tutar sınırını aşıyor.');
        return { use: use, pallets: pallets, lines: lines, per_pallet: perPallet / 100, total: total / 100, per_pallet_cents: perPallet, total_cents: total };
    }

    function calculate(input) {
        if (!input || input.model !== 2) fail('Hesap modeli geçersiz.');
        var product = text(input.product == null ? '' : input.product, 'Ürün', 150);
        var notes = text(input.notes == null ? '' : input.notes, 'Açıklama', 2000);
        var date = input.sheet_date, m = typeof date === 'string' && /^(\d{4})-(\d{2})-(\d{2})$/.exec(date);
        if (!m) fail('Geçerli bir işlem tarihi girin.');
        var dt = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
        if (dt.getUTCFullYear() !== +m[1] || dt.getUTCMonth() !== +m[2] - 1 || dt.getUTCDate() !== +m[3]) fail('Geçerli bir işlem tarihi girin.');
        if (['fason', 'uretim'].indexOf(input.operation_type) < 0) fail('İşlem türü Fason veya Üretim olmalı.');
        if (['quick', 'detailed'].indexOf(input.method) < 0) fail('Görünüm Hızlı veya Detaylı olmalı.');
        if (['actual', 'target'].indexOf(input.yield_mode) < 0) fail('Verimlilik yöntemi geçersiz.');
        var q = input.quantities; if (!q || typeof q !== 'object') fail('Miktar bilgileri eksik.');
        var exp = parseNumber(q.export_kg, 'İhracatlık net KG'), dom = parseNumber(q.domestic_kg, 'Çıkma KG');
        var ind = parseNumber(q.industry_kg, 'Suluk KG'), waste = parseNumber(q.waste_kg, 'Çöp KG');
        var procIn = (q.processed_kg == null || q.processed_kg === '') ? null : parseNumber(q.processed_kg, 'İşlenen KG');
        if (procIn !== null && procIn <= 0) procIn = null;
        var exp4 = i4(exp), raw4, raw, ty = 0;
        if (input.yield_mode === 'target') {
            ty = percent(input.target_yield, 'Tahmini verimlilik', true);
            if (exp4 > 0 && ty <= 0) fail('Hedef hesap için tahmini verimliliği (%) girin.');
            var ty100 = Math.round(ty * 100);
            raw4 = exp4 > 0 ? intdiv(2 * exp4 * 10000 + ty100, 2 * ty100) : 0;
            if (raw4 > NUMBER_MAX * 10000) fail('Gerekli ham mal KG 1.000.000 sınırını aşıyor; verimliliği veya hedefi kontrol edin.');
            raw = raw4 / 10000;
        } else {
            raw = parseNumber(q.raw_kg, 'Ham mal KG'); raw4 = i4(raw);
            if (input.target_yield) ty = percent(input.target_yield, 'Tahmini verimlilik', true);
        }
        var assumed = procIn === null, proc4 = assumed ? raw4 : i4(procIn);
        if (exp4 > raw4) fail("İhracatlık net KG, ham mal KG'den büyük olamaz.");
        if (proc4 > raw4) fail("İşlenen KG, satın alınan ham mal KG'den büyük olamaz.");
        if (exp4 > proc4) fail("İhracatlık net KG, işlenen KG'den büyük olamaz.");
        var cls4 = exp4 + i4(dom) + i4(ind) + i4(waste);
        if (cls4 > raw4) fail("Ürün dağılımı (ihracat + çıkma + suluk + çöp) ham mal KG'yi aşıyor.");
        var uncls4 = raw4 - cls4, yield100 = raw4 > 0 ? intdiv(2 * exp4 * 10000 + raw4, 2 * raw4) : 0;
        var linked = { raw_kg: raw4 / 10000, processed_kg: proc4 / 10000, export_kg: exp };

        var catalog = clone(CATALOG), codes = Object.keys(CATALOG), rows = input.items, byCode = Object.create(null);
        var legacyExtra = Array.isArray(rows) ? rows.filter(function (x) { return x && LEGACY_CODES.indexOf(x.code) >= 0; }).length : 0;
        if (!Array.isArray(rows) || rows.length < codes.length || rows.length > codes.length + CUSTOM_MAX + legacyExtra) fail('Temel maliyet kalemleri bir kez, en fazla kırk ek kalemle gönderilmeli.');
        rows.forEach(function (it) {
            var c = it && typeof it === 'object' ? it.code : null;
            if (typeof c !== 'string' || (!has(CATALOG, c) && !customCode(c)) || byCode[c]) fail('Eksik, tekrarlanan veya bilinmeyen maliyet kalemi.');
            byCode[c] = it;
        });
        codes.forEach(function (c) { if (!byCode[c]) fail('Temel maliyet kalemlerinin tamamı gerekli.'); });
        rows.forEach(function (it) {
            if (has(CATALOG, it.code)) return;
            var l = text(it.label == null ? '' : it.label, 'Ek kalem adı', 150);
            if (!l) fail('Ek maliyet kaleminin adı gerekli.');
            catalog[it.code] = { label: l, group: 'other', quick: false }; codes.push(it.code);
        });
        var mat = materialDetail(input.material_detail);
        var groups = {}, items = [], total = 0;
        GROUPS.forEach(function (g) { groups[g] = 0; });
        codes.forEach(function (code) {
            var def = catalog[code], it = byCode[code], label = def.label, basis = it.basis, unit = it.unit;
            if (BASES.indexOf(basis) < 0 || typeof unit !== 'string' || !has(UNITS, unit)
                || (has(LINKED, basis) && unit !== 'kg' && unit !== 'ton') || ((basis === 'fixed') !== (unit === 'fixed'))) fail(label + ': hesaplama tabanı ve birim uyumsuz.');
            var postedQty = parseNumber(it.qty == null ? 0 : it.qty, label + ' miktarı');
            var postedQty2 = parseNumber(it.qty2 == null ? 0 : it.qty2, label + ' gün sayısı');
            var price = parseNumber(it.unit_price == null ? 0 : it.unit_price, label + ' fiyatı'), qty, qty2 = 0, cents;
            if (has(LINKED, basis)) { qty = linked[basis]; cents = unit === 'ton' ? tonCents(qty, price) : amountCents(qty, price); }
            else if (basis === 'fixed') { qty = 1; cents = amountCents(1, price); }
            else {
                qty = postedQty; var eff = qty;
                if (UNITS[unit].second) { qty2 = postedQty2; eff = productQty(qty, qty2, label); }
                cents = amountCents(eff, price);
            }
            var fromDetail = code === 'material' && mat.use;
            if (fromDetail) cents = mat.total_cents;
            if (cents > TOTAL_MAX_CENTS) fail(label + ': tutar sınırı aşıldı.');
            total += cents; groups[def.group] += cents;
            items.push({ code: code, label: label, group: def.group, basis: basis, unit: unit, qty: qty, qty2: qty2, unit_price: price, amount: cents / 100, from_detail: fromDetail });
        });
        if (total > TOTAL_MAX_CENTS) fail('Toplam maliyet sınırı aşıldı.');

        var c = input.credit && typeof input.credit === 'object' ? input.credit : {};
        var apply = bool(c.apply, 'Yan ürün kredisi');
        var domSold = parseNumber(c.domestic_sold_kg == null ? 0 : c.domestic_sold_kg, 'Çıkma satılan KG'), domPrice = parseNumber(c.domestic_price == null ? 0 : c.domestic_price, 'Çıkma net satış fiyatı');
        var indSold = parseNumber(c.industry_sold_kg == null ? 0 : c.industry_sold_kg, 'Suluk satılan KG'), indPrice = parseNumber(c.industry_price == null ? 0 : c.industry_price, 'Suluk net satış fiyatı');
        if (i4(domSold) > i4(dom)) fail("Çıkma satılan KG, Çıkma KG'yi aşamaz.");
        if (i4(indSold) > i4(ind)) fail("Suluk satılan KG, Suluk KG'yi aşamaz.");
        var credit = amountCents(domSold, domPrice) + amountCents(indSold, indPrice), applied = apply ? credit : 0;
        if (applied > total) fail('Yan ürün geliri toplam operasyon maliyetini aşamaz.');
        var exportCost = total - applied;

        var s = input.sale && typeof input.sale === 'object' ? input.sale : {};
        var cur = s.currency == null ? 'TL' : s.currency;
        if (['TL', 'USD', 'EUR', 'RUB', 'GBP'].indexOf(cur) < 0) fail('Para birimi TL, USD, EUR, RUB veya GBP olmalı.');
        var sp = parseNumber(s.price == null ? 0 : s.price, 'Satış fiyatı');
        var rate = cur === 'TL' ? 1 : parseNumber(s.rate == null ? 0 : s.rate, 'Döviz kuru');
        var margin = (s.target_margin == null || s.target_margin === '') ? null : percent(s.target_margin, 'Hedef kâr marjı', false);
        var sale = null;
        if (sp > 0) {
            if (rate <= 0 || rate > RATE_MAX) fail("Döviz kuru 0'dan büyük ve en fazla 1.000 olmalı.");
            if (exp4 > 0) {
                var fx = amountCents(exp, sp); if (fx > TOTAL_MAX_CENTS) fail('Satış tutarı sınırı aşıldı.');
                var tl = cur === 'TL' ? fx : amountCents(fx / 100, rate); if (tl > TOTAL_MAX_CENTS) fail('Satış tutarı sınırı aşıldı.');
                var profit = tl - exportCost, be = unitCost(exportCost, exp);
                var tp = margin === null ? null : round(be / (1 - margin / 100), 4);
                sale = { revenue_fx: fx / 100, revenue_tl: tl / 100, profit: profit / 100,
                    profit_per_kg: (profit < 0 ? -1 : 1) * unitCost(Math.abs(profit), exp),
                    margin_pct: tl > 0 ? round(profit / tl * 100, 2) : null,
                    break_even_tl: be, break_even_fx: round(be / rate, 4), target_price_tl: tp,
                    target_price_fx: tp === null ? null : round(tp / rate, 4) };
            }
        }
        var warnings = [];
        if (assumed && raw4 > 0) warnings.push({ code: 'processed_assumed', text: 'İşlenen KG girilmedi; ham mal KG ile aynı kabul edildi (varsayım).' });
        if (uncls4 > 0 && exp4 > 0) warnings.push({ code: 'unclassified', text: fmtKg(uncls4 / 10000) + ' KG ham ürün henüz sınıflandırılmamış (Çıkma / Suluk / Çöp ayrımı Detaylı Hesapta).' });
        if (mat.use && mat.total_cents === 0) warnings.push({ code: 'material_detail_empty', text: 'Malzeme detayından hesaplama açık ama tutar 0: satılan palet sayısını ve malzeme satırlarını girin.' });
        if (apply && credit > 0) warnings.push({ code: 'credit_applied', text: 'Yan ürün net geliri maliyetten düşüldü: yönetimsel tahmin, resmi muhasebe maliyeti değildir.' });
        if (!apply && credit > 0) warnings.push({ code: 'credit_not_applied', text: 'Yan ürün geliri girildi fakat maliyetten düşülmedi (ihtiyatlı yöntem).' });
        var groupsTl = {}; GROUPS.forEach(function (g) { groupsTl[g] = groups[g] / 100; });
        return {
            model: 2, product: product, sheet_date: date, operation_type: input.operation_type, method: input.method, notes: notes,
            yield_mode: input.yield_mode, target_yield: ty,
            quantities: { raw_kg: raw4 / 10000, processed_kg: procIn, export_kg: exp, domestic_kg: dom, industry_kg: ind, waste_kg: waste },
            material_detail: { use: mat.use, pallets: mat.pallets, lines: mat.lines, per_pallet: mat.per_pallet, total: mat.total },
            credit: { apply: apply, domestic_sold_kg: domSold, domestic_price: domPrice, industry_sold_kg: indSold, industry_price: indPrice },
            sale: { price: sp, currency: cur, rate: rate, target_margin: margin },
            items: items,
            derived: { raw_kg: raw4 / 10000, processed_kg: proc4 / 10000, processed_assumed: assumed, unclassified_kg: uncls4 / 10000, yield_pct: yield100 / 100 },
            groups: groupsTl, total: total / 100, credit_total: credit / 100, credit_applied: applied / 100,
            export_cost: exportCost / 100, unit_cost: unitCost(exportCost, exp), unit_cost_prudent: unitCost(total, exp), net_kg: exp,
            sale_result: sale, warnings: warnings
        };
    }

    /** Kaydedilecek kanonik gövde: yalnız girdiler, JSON sayıları; hesaplanan alanlar gönderilmez. */
    function canonicalPayload(doc) {
        var r = calculate(doc);
        return {
            model: 2, product: r.product, sheet_date: r.sheet_date, operation_type: r.operation_type, method: r.method, notes: r.notes,
            yield_mode: r.yield_mode, target_yield: r.target_yield, quantities: r.quantities,
            material_detail: { use: r.material_detail.use, pallets: r.material_detail.pallets, lines: r.material_detail.lines.map(function (l) {
                var row = { code: l.code, qty: l.qty, price: l.price }; if (!has(MAT_LINES, l.code)) row.label = l.label; return row; }) },
            credit: r.credit, sale: r.sale,
            items: r.items.map(function (it) {
                var row = { code: it.code, basis: it.basis, unit: it.unit, qty: it.qty, qty2: it.qty2, unit_price: it.unit_price };
                if (customCode(it.code)) row.label = it.label;
                return row;
            })
        };
    }
    /** Taban/birim değişince eski miktar ve fiyat yeni birime TAŞINMAZ (20.000 kg → 20.000 palet hatası). */
    function setBasis(doc, code, basis, unit) {
        var next = clone(doc), it = next.items.find(function (r) { return r.code === code; });
        if (!it || BASES.indexOf(basis) < 0 || !has(UNITS, unit)) fail('Hesaplama tabanı geçersiz.');
        var unitChanged = it.unit !== unit;
        it.basis = basis; it.unit = unit; it.qty = 0; it.qty2 = 0;
        if (unitChanged) it.unit_price = 0;
        return next;
    }
    /** Yöntem değişince diğer yöntemin girdisi mevcut sonuçtan doldurulur; döngü yoktur. */
    function setYieldMode(doc, mode) {
        if (mode !== 'actual' && mode !== 'target') fail('Verimlilik yöntemi geçersiz.');
        var next = clone(doc), result = null;
        if (next.yield_mode === mode) return next;
        try { result = calculate(doc); } catch (_) { result = null; }
        if (result && mode === 'target' && result.derived.yield_pct > 0) next.target_yield = result.derived.yield_pct;
        if (result && mode === 'actual' && result.derived.raw_kg > 0) next.quantities.raw_kg = result.derived.raw_kg;
        next.yield_mode = mode;
        return next;
    }
    function rowVisible(code, view, op, amount) {
        if (view === 'detailed') return true;
        if (amount !== 0) return true;
        if (customCode(code)) return true;
        return !!(CATALOG[code] && CATALOG[code].quick);
    }
    function hintText(item, fromDetail) {
        var t;
        if (has(LINKED, item.basis)) t = LINKED[item.basis] + (item.unit === 'ton' ? ' ÷ 1.000 × TL/ton' : ' × TL/kg') + ' = tutar';
        else if (item.basis === 'fixed') t = 'Sabit toplam tutar';
        else if (item.unit === 'worker_day') t = 'İşçi sayısı × gün × yevmiye = tutar';
        else if (has(UNITS, item.unit) && UNITS[item.unit].second) t = QTY_LABEL[item.unit] + ' × gün × ' + UNITS[item.unit].price + ' = tutar';
        else t = (QTY_LABEL[item.unit] || 'Miktar') + ' × ' + ((UNITS[item.unit] || {}).price || 'birim fiyat') + ' = tutar';
        return fromDetail ? 'Palet detayından hesaplanıyor (Palet detayı düğmesi)' : t;
    }
    function priceLabel(item) {
        if (item.basis === 'fixed') return 'Sabit tutar (TL)';
        if (has(LINKED, item.basis)) return item.unit === 'ton' ? 'TL/ton' : LINKED_PRICE[item.basis];
        return (UNITS[item.unit] || {}).price || 'Birim fiyat';
    }
    function getPath(obj, path) { return path.split('.').reduce(function (o, k) { return o == null ? undefined : o[k]; }, obj); }
    function setPath(obj, path, value) {
        var keys = path.split('.'), last = keys.pop(), target = keys.reduce(function (o, k) { if (!o[k] || typeof o[k] !== 'object') o[k] = {}; return o[k]; }, obj);
        target[last] = value;
    }
    function editFormat(v) { return typeof v === 'number' ? fmtKg(v) : (v == null ? '' : String(v)); }
    function numberReadNote(value) {
        var t = String(value == null ? '' : value).trim();
        if (!/^\d{1,3}(?:\.\d{3})+$/.test(t)) return '';
        try { return '"' + t + '" sayısı ' + String(parseNumber(t)) + ' olarak okunur. Ondalık için virgül kullanın: ' + t.replace('.', ',') + '.'; } catch (_) { return ''; }
    }
    function isZero(v) { if (typeof v !== 'string' || v.trim() === '') return false; try { return parseNumber(v) === 0; } catch (_) { return false; } }

    function init(dom, config) {
        var form = dom.getElementById('qc-form');
        if (!form || form.dataset.model !== '2' || !config || !config.document || form.dataset.qcReady === '1') return null;
        form.dataset.qcReady = '1';
        var state = clone(config.document), readOnly = !!config.readOnly, dirty = false, submitted = false, latest = null, seq = 0, cleared = null;
        var validation = dom.getElementById('qc-validation'), save = dom.getElementById('qc-save'), announce, kbdTimer;
        state.product = upperText(state.product); state.notes = upperText(state.notes);
        function rowNode(code) { return form.querySelector('[data-qc2-row="' + code + '"]'); }
        function item(code) { return state.items.find(function (r) { return r.code === code; }); }
        function out(key, value) { form.querySelectorAll('[data-qc2-out="' + key + '"]').forEach(function (n) { n.textContent = value; }); }
        function tl(v) { return fmt(v, 2) + ' TL'; }
        function paintInputs(source) {
            form.querySelectorAll('[data-qc2]').forEach(function (input) {
                if (input === source || (input === cleared && dom.activeElement === input)) return;
                var v = getPath(state, input.dataset.qc2), shown = input.tagName === 'SELECT' || input.type === 'date' || input.dataset.qc2Text ? (v == null ? '' : String(v)) : editFormat(v);
                if (input.value !== shown) input.value = shown;
            });
            form.querySelectorAll('[data-qc2-bool]').forEach(function (input) { input.checked = !!getPath(state, input.dataset.qc2Bool); });
            form.querySelectorAll('[data-qc2-mline]').forEach(function (ln) {
                var ml = state.material_detail.lines.find(function (x) { return x.code === ln.dataset.qc2Mline; }); if (!ml) return;
                ln.querySelectorAll('[data-qc2-mat]').forEach(function (input) {
                    if (input === source || (input === cleared && dom.activeElement === input)) return;
                    var f = input.dataset.qc2Mat, shown = f === 'label' ? (ml.label || '') : editFormat(ml[f]);
                    if (input.value !== shown) input.value = shown;
                });
            });
            state.items.forEach(function (it) {
                var node = rowNode(it.code); if (!node) return;
                node.querySelectorAll('[data-qc2-item]').forEach(function (input) {
                    if (input === source || (input === cleared && dom.activeElement === input)) return;
                    var f = input.dataset.qc2Item, shown = f === 'basis_unit' ? it.basis + '|' + it.unit : (f === 'label' ? (it.label || '') : editFormat(it[f]));
                    if (input.value !== shown) input.value = shown;
                });
            });
        }
        function validateInputs() {
            form.querySelectorAll('input[inputmode="decimal"]').forEach(function (input) {
                if (input.disabled || input.closest('[hidden]')) { input.removeAttribute('aria-invalid'); input.setCustomValidity(''); return; }
                try { parseNumber(input.value); input.removeAttribute('aria-invalid'); input.setCustomValidity(''); }
                catch (e) { input.setAttribute('aria-invalid', 'true'); input.setCustomValidity(e.message); }
            });
            form.querySelectorAll('[data-qc2-row]').forEach(function (node) {
                var s = node.querySelector('[data-qc2-settings]'); if (s) s.classList.toggle('has-error', !!s.querySelector('[aria-invalid="true"]'));
            });
        }
        function paint(source) {
            var error = '';
            try { latest = calculate(state); } catch (e) { latest = null; error = e.message; }
            var r = latest;
            form.dataset.view = state.method; form.dataset.yieldMode = state.yield_mode;
            form.querySelectorAll('[data-qc2-set]').forEach(function (b) { b.setAttribute('aria-pressed', String(state[b.dataset.qc2Set] === b.dataset.value)); });
            out('total', r ? fmt(r.total, 2) : '—'); out('total_tl', r ? tl(r.total) : '—');
            out('export_kg', r ? fmtKg(r.quantities.export_kg) : '—');
            out('yield_pct', r && r.derived.raw_kg > 0 ? '%' + fmt(r.derived.yield_pct, 2) : '—');
            out('yield_readout', r && r.derived.raw_kg > 0 ? '%' + fmt(r.derived.yield_pct, 2) : '—');
            out('required_raw', r ? fmtKg(r.derived.raw_kg) + ' KG' : '—');
            out('unit_cost', r && r.net_kg > 0 ? fmt(r.unit_cost, 4) : '—');
            out('unit_cost_prudent', r && r.net_kg > 0 ? fmt(r.unit_cost_prudent, 4) + ' TL/kg' : '—');
            out('unclassified_kg', r ? fmtKg(r.derived.unclassified_kg) + ' KG' : '—');
            var md = r && r.material_detail;
            out('mat.per_pallet', md ? tl(md.per_pallet) : '—'); out('mat.total', md ? tl(md.total) : '—');
            out('mat.pallets', md ? fmtKg(md.pallets) : '—');
            state.material_detail.lines.forEach(function (ml) {
                var ln = form.querySelector('[data-qc2-mline="' + ml.code + '"]'); if (!ln) return;
                var calcLine = md && md.lines.find(function (x) { return x.code === ml.code; });
                ln.querySelector('[data-qc2-mat-out]').textContent = calcLine ? tl(calcLine.per_pallet) : (md ? tl(0) : '—');
            });
            out('credit_applied', r ? (r.credit_applied > 0 ? '− ' + tl(r.credit_applied) : tl(0)) : '—');
            out('credit_total', r ? tl(r.credit_total) : '—');
            out('export_cost', r ? tl(r.export_cost) : '—');
            out('method_note', r && r.credit_applied > 0
                ? 'Yan ürün kredisi uygulandı: iç piyasa/sanayi net geliri düşülerek ihracata kalan TAHMİNİ maliyet gösterilir (yönetimsel; resmi muhasebe maliyeti değildir).'
                : "İhtiyatlı yöntem: tüm operasyon maliyeti ihracatlık net KG'ye yüklenir (yan ürün geliri düşülmez).");
            GROUPS.forEach(function (g) { out('group.' + g, r ? tl(r.groups[g]) : '—'); });
            var sr = r && r.sale_result, cur = state.sale && state.sale.currency || 'TL';
            out('sale.revenue', sr ? tl(sr.revenue_tl) + (cur !== 'TL' ? ' (' + fmt(sr.revenue_fx, 2) + ' ' + cur + ')' : '') : '—');
            out('sale.cost', sr ? tl(r.export_cost) : '—');
            out('sale.profit', sr ? tl(sr.profit) : '—');
            out('sale.profit_per_kg', sr ? fmt(sr.profit_per_kg, 4) + ' TL/kg' : '—');
            out('sale.margin', sr && sr.margin_pct !== null ? '%' + fmt(sr.margin_pct, 2) : '—');
            out('sale.break_even', sr ? fmt(sr.break_even_tl, 4) + ' TL/kg' + (cur !== 'TL' ? ' (' + fmt(sr.break_even_fx, 4) + ' ' + cur + '/kg)' : '') : '—');
            out('sale.target_price', sr && sr.target_price_tl !== null ? fmt(sr.target_price_tl, 4) + ' TL/kg' + (cur !== 'TL' ? ' (' + fmt(sr.target_price_fx, 4) + ' ' + cur + '/kg)' : '') : '—');
            var sale = form.querySelector('.qc2-sale'); if (sale) sale.classList.toggle('qc2-loss', !!(sr && sr.profit < 0));
            var rate = form.querySelector('.qc2-rate'); if (rate) rate.hidden = cur === 'TL';
            state.items.forEach(function (it) {
                var node = rowNode(it.code); if (!node) return;
                var calc = r && r.items.find(function (x) { return x.code === it.code; }), label = has(CATALOG, it.code) ? CATALOG[it.code].label : (upperText(it.label || '').trim() || 'Yeni kalem');
                node.querySelector('[data-qc2-title]').textContent = label;
                node.querySelector('[data-qc2-amount]').textContent = calc ? tl(calc.amount) : '—';
                var fd = !!(calc && calc.from_detail);
                node.classList.toggle('qc2-from-detail', fd);
                node.querySelector('[data-qc2-hint]').textContent = hintText(it, fd);
                if (!readOnly) { var mainPrice = node.querySelector('[data-qc2-item="unit_price"]'); if (mainPrice) mainPrice.disabled = fd; }
                node.querySelector('[data-qc2-price-label]').textContent = priceLabel(it);
                var manual = it.basis === 'manual', second = manual && has(UNITS, it.unit) && !!UNITS[it.unit].second;
                node.querySelector('[data-qc2-qty-wrap]').hidden = !manual;
                node.querySelector('[data-qc2-qty2-wrap]').hidden = !second;
                node.querySelector('[data-qc2-qty-label]').textContent = QTY_LABEL[it.unit] || 'Miktar';
                node.classList.toggle('qc2-row-hidden', !rowVisible(it.code, state.method, state.operation_type, calc ? calc.amount : 0));
            });
            var list = form.querySelector('[data-qc2-out="warnings"]');
            if (list) {
                list.textContent = '';
                (r ? r.warnings : []).forEach(function (w) { var li = dom.createElement('li'); li.dataset.code = w.code; li.textContent = w.text; list.appendChild(li); });
            }
            paintInputs(source);
            validateInputs();
            validation.textContent = error; validation.hidden = !error;
            var customs = userCustomCount(state.items);
            var add = dom.getElementById('qc2-add-item'); if (add) add.disabled = readOnly || customs >= CUSTOM_MAX;
            var lim = dom.getElementById('qc2-custom-limit'); if (lim) lim.hidden = customs < CUSTOM_MAX;
            if (save) save.disabled = readOnly || form.dataset.saveAvailable !== '1' || !r || !!form.querySelector('[aria-invalid="true"]');
            clearTimeout(announce);
            announce = setTimeout(function () { out('live', r ? 'Toplam ' + fmt(r.total, 2) + ' TL, ihracat net kilogram maliyeti ' + fmt(r.unit_cost, 4) + ' TL.' : 'Hesap için girişleri kontrol edin.'); }, 350);
        }
        function readNote(input, show) {
            var host = input.parentNode, note = host.querySelector('.qc-read-note'), t = show ? numberReadNote(input.value) : '';
            if (!t) { if (note) note.remove(); return; }
            if (!note) { note = dom.createElement('small'); note.className = 'qc-read-note'; note.setAttribute('role', 'status'); host.appendChild(note); }
            note.textContent = t;
        }
        // Verimlilik iki ondalığa yuvarlandığı için hedef yöntemine geçiş ham KG'yi değiştirebilir: sessiz kalma, söyle.
        function yieldNote(onceki, mode) {
            var note = form.querySelector('[data-qc2-yield-note]'); if (!note) return;
            var t = '';
            if (onceki !== null && mode === 'target') {
                var r = null; try { r = calculate(state); } catch (_) { r = null; }
                if (!r || i4(r.derived.raw_kg) !== i4(onceki)) {
                    t = 'Verimlilik %' + fmt(state.target_yield, 2) + ' olarak yuvarlandı; gerekli ham mal KG ' + fmtKg(onceki)
                        + (r ? ' yerine ' + fmtKg(r.derived.raw_kg) + ' oldu' : ' ile tutarlı hesaplanamadı') + '. Kesin ham KG için "Ham KG + ihracat KG" yöntemini kullanın.';
                }
            }
            note.textContent = t; note.hidden = !t;
        }
        function rowNote(node, t) {
            var host = node.querySelector('[data-qc2-settings]'), note = host.querySelector('.qc-unit-note');
            if (!t) { if (note) note.remove(); return; }
            if (!note) { note = dom.createElement('p'); note.className = 'qc-hint qc-unit-note'; note.setAttribute('role', 'status'); host.appendChild(note); }
            note.textContent = t;
        }
        var matAuto = false;
        function matToggle(btn) {
            var panel = form.querySelector('[data-qc2-matpanel]'); if (!panel) return;
            var open = panel.hidden;
            panel.hidden = !open; btn.setAttribute('aria-expanded', String(open));
            paint();
        }
        // İlk anlamlı girişte "detaydan hesapla" kendiliğinden açılır (bir kez); kullanıcı sonra kapatabilir.
        function matAutoUse() {
            if (matAuto || state.material_detail.use) return;
            var d = null; try { d = materialDetail(state.material_detail); } catch (_) { d = null; }
            if (d && d.total_cents > 0) { state.material_detail.use = true; matAuto = true; }
        }
        if (readOnly) {
            // Salt okunur: yalnız görünüm (Hızlı/Detaylı) değişir; belge değişmez, kaydedilmez.
            form.addEventListener('click', function (e) {
                var t = e.target.closest('button[data-qc2-mat-toggle]'); if (t) { matToggle(t); return; }
                var b = e.target.closest('button[data-qc2-set="method"]'); if (!b) return;
                state.method = b.dataset.value; paint();
            });
            paint(); return { getDocument: function () { return clone(state); } };
        }
        function onInput(event) {
            var input = event.target;
            if (input.matches('input[inputmode="decimal"]')) readNote(input, true);
            if (input === cleared) cleared = null;
            if (input.dataset.qc2) {
                var v = input.value;
                if (input.dataset.qc2Text && !event.isComposing) {
                    var up = upperText(v);
                    if (up !== v) { var a = input.selectionStart, b = input.selectionEnd; input.value = up; if (a !== null) input.setSelectionRange(upperText(v.slice(0, a)).length, upperText(v.slice(0, b)).length); v = up; }
                }
                if (input.dataset.qc2Optional && v.trim() === '') v = input.dataset.qc2 === 'quantities.processed_kg' ? null : '';
                setPath(state, input.dataset.qc2, v);
                if (input.dataset.qc2 === 'material_detail.pallets') matAutoUse();
            } else if (input.dataset.qc2Bool) {
                setPath(state, input.dataset.qc2Bool, input.checked);
                if (input.dataset.qc2Bool === 'material_detail.use') matAuto = true;
            } else if (input.dataset.qc2Mat) {
                var mline = input.closest('[data-qc2-mline]'), mcode = mline.dataset.qc2Mline, field = input.dataset.qc2Mat;
                var ml = state.material_detail.lines.find(function (x) { return x.code === mcode; });
                if (!ml) return;
                ml[field] = field === 'label' ? upperText(input.value) : input.value;
                if (field === 'label' && !event.isComposing && input.value !== ml.label) input.value = ml.label;
                if (field !== 'label') matAutoUse();
            } else if (input.dataset.qc2Item) {
                var node = input.closest('[data-qc2-row]'), code = node.dataset.qc2Row, f = input.dataset.qc2Item;
                if (f === 'basis_unit') {
                    var parts = input.value.split('|'), prev = item(code), unitChanged = prev.unit !== parts[1];
                    state = setBasis(state, code, parts[0], parts[1]);
                    rowNote(node, parts[0] === 'manual' || unitChanged ? 'Taban değişti; ' + (unitChanged ? 'miktar ve birim fiyat sıfırlandı' : 'miktar sıfırlandı') + '. Yeni değerleri girin.' : '');
                } else {
                    var it = item(code);
                    it[f] = f === 'label' ? upperText(input.value) : input.value;
                    if (f === 'label' && !event.isComposing && input.value !== it.label) input.value = it.label;
                    if (f === 'qty') rowNote(node, '');
                }
            } else return;
            dirty = true; paint(input);
        }
        form.addEventListener('input', onInput);
        form.addEventListener('change', function (e) { if (e.target.tagName === 'SELECT' || e.target.type === 'checkbox') onInput(e); });
        form.addEventListener('focusin', function (e) {
            var input = e.target;
            if (input.matches && input.matches('input:not([type="hidden"]):not([type="date"]):not([type="checkbox"]), textarea')) { clearTimeout(kbdTimer); form.classList.add('qc-kbd'); }
            if (input.matches && input.matches('input[inputmode="decimal"]') && !input.disabled) {
                readNote(input, true);
                if (isZero(input.value)) { cleared = input; input.value = ''; }
            }
        });
        form.addEventListener('focusout', function (e) {
            var input = e.target;
            clearTimeout(kbdTimer); kbdTimer = setTimeout(function () { var a = dom.activeElement; if (!a || !a.matches || !a.matches('input, textarea')) form.classList.remove('qc-kbd'); }, 150);
            if (!input.matches || !input.matches('input[inputmode="decimal"]')) return;
            readNote(input, false);
            if (input === cleared) cleared = null;
            if (input.value.trim() === '' && !input.dataset.qc2Optional) { input.value = '0'; onInput({ target: input }); }
        });
        form.addEventListener('click', function (e) {
            var b = e.target.closest('button'); if (!b) return;
            if (b.hasAttribute('data-qc2-mat-toggle')) { matToggle(b); return; }
            if (b.hasAttribute('data-qc2-mat-add')) {
                var mw = dom.defaultView, mcode2 = 'mat_custom_' + (mw && mw.crypto && mw.crypto.randomUUID ? mw.crypto.randomUUID().replace(/-/g, '').slice(0, 20) : Date.now().toString(36) + (++seq).toString(36));
                if (state.material_detail.lines.filter(function (x) { return !has(MAT_LINES, x.code); }).length >= MAT_CUSTOM_MAX) return;
                state.material_detail.lines.push({ code: mcode2, label: '', qty: 0, price: 0 });
                var lnode = dom.getElementById('qc2-mat-template').content.firstElementChild.cloneNode(true);
                lnode.dataset.qc2Mline = mcode2; form.querySelector('[data-qc2-mat-lines]').appendChild(lnode);
                dirty = true; paint(); var ll = lnode.querySelector('[data-qc2-mat="label"]'); if (ll) ll.focus();
                return;
            }
            if (b.hasAttribute('data-qc2-mat-remove')) {
                var rl = b.closest('[data-qc2-mline]'), rcode = rl.dataset.qc2Mline;
                if (has(MAT_LINES, rcode)) return;
                state.material_detail.lines = state.material_detail.lines.filter(function (x) { return x.code !== rcode; });
                rl.remove(); dirty = true; paint(); return;
            }
            if (b.dataset.qc2Set) {
                var key = b.dataset.qc2Set, val = b.dataset.value;
                if (key === 'yield_mode') {
                    var onceki = latest && state.yield_mode !== val ? latest.derived.raw_kg : null;
                    state = setYieldMode(state, val);
                    yieldNote(onceki, val);
                } else state[key] = val;
                if (key !== 'method') dirty = true;
                paint();
            } else if (b.id === 'qc2-add-item') {
                var win = dom.defaultView, code = 'custom_' + (win && win.crypto && win.crypto.randomUUID ? win.crypto.randomUUID().replace(/-/g, '').slice(0, 24) : Date.now().toString(36) + (++seq).toString(36));
                if (userCustomCount(state.items) >= CUSTOM_MAX) return;
                state.items.push({ code: code, label: '', basis: 'fixed', unit: 'fixed', qty: 0, qty2: 0, unit_price: 0 });
                var node = dom.getElementById('qc2-custom-template').content.firstElementChild.cloneNode(true);
                node.dataset.qc2Row = code; dom.getElementById('qc-rows').appendChild(node);
                dirty = true; paint(); var l = node.querySelector('[data-qc2-item="label"]'); if (l) l.focus();
            } else if (b.hasAttribute('data-qc2-remove')) {
                var n = b.closest('[data-qc2-row]'), c = n.dataset.qc2Row;
                if (!customCode(c)) return;
                state.items = state.items.filter(function (x) { return x.code !== c; }); n.remove(); dirty = true; paint();
            }
        });
        form.addEventListener('submit', function (e) {
            try {
                if (form.dataset.saveAvailable !== '1') fail('Kaydetme kullanılamıyor.');
                var payload = canonicalPayload(state);
                if (!payload.product) fail('Ürün adı girin.');
                if (!(payload.quantities.export_kg > 0)) fail('Kaydetmek için ihracatlık net KG sıfırdan büyük olmalı.');
                if (!form.checkValidity()) { e.preventDefault(); form.reportValidity(); return; }
                dom.getElementById('qc-payload').value = JSON.stringify(payload);
                submitted = true; if (save) { save.disabled = true; save.textContent = 'Kaydediliyor…'; }
            } catch (err) { e.preventDefault(); validation.textContent = err.message; validation.hidden = false; validation.scrollIntoView({ block: 'nearest' }); }
        });
        if (dom.defaultView) dom.defaultView.addEventListener('beforeunload', function (e) { if (!dirty || submitted) return; e.preventDefault(); e.returnValue = ''; });
        paint();
        return { getDocument: function () { return clone(state); }, calculate: function () { return calculate(state); } };
    }
    return { parseNumber: parseNumber, calculate: calculate, canonicalPayload: canonicalPayload, setBasis: setBasis, setYieldMode: setYieldMode,
        rowVisible: rowVisible, hintText: hintText, amountCents: amountCents, tonCents: tonCents, unitCost: unitCost, init: init,
        catalog: CATALOG, units: UNITS, groups: GROUPS, materialLines: MAT_LINES };
}));
