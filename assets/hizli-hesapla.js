'use strict';
// Hızlı Hesapla (model 3) — JS önizleme motoru. Otorite PHP'dir (config/hizli_hesapla.php); kaydedilen
// sonuçları sunucu yeniden hesaplar. 'compute' işlem sırası PHP hh_compute ile BİREBİR aynıdır.
(function (root) {
    var MAX = 1000000;
    var NUM_RE = /^(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d{1,4})?$/;

    /** Türkçe sayı; boş → {value:0, empty:true}; geçersiz → {error}. Sessizce 0 yapılmaz. */
    function parseNum(text, label) {
        var s = String(text == null ? '' : text).trim();
        if (s === '') return { value: 0, empty: true };
        if (!NUM_RE.test(s)) return { error: label + ': geçerli bir sayı girin (örn. 1.250,50).' };
        var v = parseFloat(s.replace(/\./g, '').replace(',', '.'));
        if (!isFinite(v) || v < 0 || v > MAX) return { error: label + ': sayı çok büyük veya geçersiz.' };
        return { value: v };
    }

    function compute(n) {
        var s = n.sale_kg;
        var rawKg = s / (1 - n.waste_pct / 100);
        var rawCost = rawKg * n.raw_price;
        var material = s * n.material_kg;
        var otherKg = s * n.other_kg;
        var fixed = n.freight + n.docs + n.other_fixed;
        var total = rawCost + material + otherKg + fixed;
        var unit = total / s;
        var unitFx = unit / n.rate;
        var priceTl = n.price_in_fx ? n.sale_price * n.rate : n.sale_price;
        var priceFx = n.price_in_fx ? n.sale_price : n.sale_price / n.rate;
        var saleTl = n.price_in_fx ? s * n.sale_price * n.rate : s * n.sale_price;
        var profit = saleTl - total;
        var margin = saleTl > 0 ? profit / saleTl * 100 : null;
        var tm = n.target_margin;
        var sugTl = tm === null ? null : unit / (1 - tm / 100);
        return { raw_kg: rawKg, raw_cost: rawCost, material_cost: material, other_kg_cost: otherKg, fixed_cost: fixed,
            total: total, unit_cost: unit, unit_cost_fx: unitFx, price_tl: priceTl, price_fx: priceFx, sale_tl: saleTl,
            sale_fx: saleTl / n.rate, profit: profit, profit_fx: profit / n.rate, margin_pct: margin, breakeven_fx: unitFx,
            suggested_tl: sugTl, suggested_fx: sugTl === null ? null : sugTl / n.rate };
    }

    var LABELS = { sale_kg: 'Satış net KG', sale_price: 'Satış fiyatı', rate: 'Kur', raw_price: 'Ham mal alış fiyatı',
        freight: 'Nakliye bedeli', material_kg: 'Malzeme gideri', docs: 'Evrak masrafları', other_kg: 'Diğer masraf',
        other_fixed: 'Diğer sabit masraf', waste_pct: 'Fire oranı', target_margin: 'Hedef kâr marjı' };

    /** Ham alan metinlerinden doğrulanmış hesap. raw: {alan: metin, currency, price_in_fx}. */
    function calculate(raw) {
        var errors = [], n = {};
        ['sale_kg', 'sale_price', 'rate', 'raw_price', 'freight', 'material_kg', 'docs', 'other_kg', 'other_fixed', 'waste_pct'].forEach(function (k) {
            var label = k === 'rate' ? raw.currency + ' kuru' : LABELS[k];
            var p = parseNum(raw[k], label);
            if (p.error) { errors.push(p.error); n[k] = 0; return; }
            n[k] = p.value;
            if (p.empty && (k === 'sale_kg' || k === 'rate')) errors.push(label + ': değer girin.');
        });
        var tmP = parseNum(raw.target_margin, LABELS.target_margin);
        if (tmP.error) { errors.push(tmP.error); n.target_margin = null; }
        else n.target_margin = tmP.empty ? null : tmP.value;
        n.price_in_fx = !!raw.price_in_fx;
        if (!errors.length) {
            if (!(n.sale_kg > 0)) errors.push('Satış net KG sıfırdan büyük olmalı.');
            if (!(n.rate > 0)) errors.push(raw.currency + ' kuru sıfırdan büyük olmalı.');
            if (n.waste_pct >= 100) errors.push('Fire oranı %100\'ün altında olmalı.');
            if (n.target_margin !== null && n.target_margin >= 100) errors.push('Hedef kâr marjı %100\'ün altında olmalı.');
        }
        if (errors.length) return { ok: false, errors: errors };
        return { ok: true, n: n, r: compute(n) };
    }

    function group(intStr) { return intStr.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
    function fmt(x, d) {
        var neg = x < 0 && Math.abs(x) >= 0.5 * Math.pow(10, -d);
        var s = Math.abs(x).toFixed(d), parts = s.split('.');
        return (neg ? '-' : '') + group(parts[0]) + (d ? ',' + parts[1] : '');
    }
    /** Girdi kutusu için: en çok 4 ondalık, sondaki sıfırlar atılır. */
    function fmtInput(x, minDec) {
        if (typeof x !== 'number') return String(x == null ? '' : x);
        var s = fmt(x, 4), m = minDec || 0;
        if (s.indexOf(',') < 0) return m ? s + ',' + new Array(m + 1).join('0') : s;
        var frac = s.split(',')[1].replace(/0+$/, '');
        if (frac === '') return m ? s.split(',')[0] + ',' + new Array(m + 1).join('0') : s.split(',')[0];
        while (frac.length < Math.max(m, 2)) frac += '0';   // ondalıklı değer en az iki hane: 1,10 · 0,50
        return s.split(',')[0] + ',' + frac;
    }

    /** Ekran + paylaşım metni için TEK biçimlendirme. */
    function view(n, r, cur, name) {
        var v = {};
        v.total = fmt(r.total, 2) + ' TL';
        v.total_fx = '≈ ' + fmt(r.total / n.rate, 2) + ' ' + cur;
        v.unit = fmt(r.unit_cost, 4) + ' TL/kg';
        v.unit_fx = '≈ ' + fmt(r.unit_cost_fx, 4) + ' ' + cur + '/kg';
        v.sale = fmt(r.sale_tl, 2) + ' TL';
        v.sale_fx = '≈ ' + fmt(r.sale_fx, 2) + ' ' + cur;
        v.profit = fmt(r.profit, 2) + ' TL';
        v.profit_fx = '≈ ' + fmt(r.profit_fx, 2) + ' ' + cur;
        v.loss = r.profit < 0;
        v.margin = r.margin_pct === null ? '—' : '%' + fmt(r.margin_pct, 2);
        v.breakeven = fmt(r.breakeven_fx, 4) + ' ' + cur + '/kg';
        v.entered = fmt(r.price_fx, 4) + ' ' + cur + '/kg (' + fmt(r.price_tl, 4) + ' TL/kg)';
        v.suggested = r.suggested_fx === null ? 'Hedef marj girin'
            : fmt(r.suggested_fx, 4) + ' ' + cur + '/kg (' + fmt(r.suggested_tl, 4) + ' TL/kg)';
        v.rawInfo = 'Gerekli ham mal: ' + fmt(r.raw_kg, 0) + ' kg';
        v.share = shareText(n, r, cur, name, v);
        return v;
    }

    function shareText(n, r, cur, name, v) {
        var L = ['HIZLI HESAPLA' + (name ? ' — ' + name : '')];
        L.push('Satış net KG: ' + fmt(n.sale_kg, 0) + ' kg');
        L.push('Döviz / kur: ' + cur + ' · 1 ' + cur + ' = ' + fmt(n.rate, 2) + ' TL');
        L.push('Satış fiyatı: ' + v.entered);
        L.push('Toplam maliyet: ' + v.total + ' ' + v.total_fx);
        L.push('Net kg maliyeti: ' + v.unit + ' ' + v.unit_fx);
        L.push('Satış toplamı: ' + v.sale + ' ' + v.sale_fx);
        L.push((v.loss ? 'Zarar: ' : 'Kâr: ') + v.profit + ' ' + v.profit_fx);
        L.push('Kâr marjı: ' + v.margin);
        L.push(n.target_margin === null ? 'Önerilen satış fiyatı: hedef marj girilmedi'
            : 'Hedef marj %' + fmt(n.target_margin, 2) + ' → önerilen satış fiyatı: ' + v.suggested);
        return L.join('\n');
    }

    function trUpper(s) { return String(s).trim().toLocaleUpperCase('tr-TR'); }

    var api = { parseNum: parseNum, compute: compute, calculate: calculate, fmt: fmt, fmtInput: fmtInput, view: view, trUpper: trUpper };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.HizliHesapla = api;

    /* ---------------------------------------------------------------- arayüz */
    if (typeof document === 'undefined' || !document.getElementById('hh')) return;
    var cfg = root.HH_CONFIG || {}, doc = cfg.document || {};
    var el = {}; document.querySelectorAll('[data-hh]').forEach(function (e) { el[e.getAttribute('data-hh')] = e; });
    var out = {}; document.querySelectorAll('[data-hh-out]').forEach(function (e) { out[e.getAttribute('data-hh-out')] = e; });
    var $ = function (id) { return document.getElementById(id); };
    var rates = { USD: '', EUR: '' };
    var currency = doc.currency === 'EUR' ? 'EUR' : 'USD';
    var last = null;

    ['sale_kg', 'sale_price', 'raw_price', 'freight', 'material_kg', 'docs', 'other_kg', 'other_fixed', 'waste_pct', 'target_margin'].forEach(function (k) {
        el[k].value = doc[k] === null || doc[k] === undefined ? '' : fmtInput(doc[k]);
    });
    rates[currency] = fmtInput(doc.rate, 2);
    el.currency.value = currency;
    el.price_in_fx.checked = doc.price_in_fx === undefined ? true : (doc.price_in_fx === true || doc.price_in_fx === 1 || doc.price_in_fx === '1');
    el.rate.value = rates[currency];
    if (cfg.readOnly) Object.keys(el).forEach(function (k) { el[k].disabled = true; });

    function raw() {
        var r = { currency: currency, price_in_fx: el.price_in_fx.checked };
        Object.keys(el).forEach(function (k) { if (k !== 'currency' && k !== 'price_in_fx') r[k] = el[k].value; });
        return r;
    }
    function setText(key, text) { if (out[key]) out[key].textContent = text; }

    function render() {
        $('hhRateLabel').textContent = currency + ' kuru (TL)';
        $('hhPriceHint').textContent = 'Satış fiyatı ' + (el.price_in_fx.checked ? currency + '/kg' : 'TL/kg') + ' olarak yorumlanır.';
        var c = calculate(raw()), errs = $('hhErrs');
        last = c;
        if (!c.ok) {
            errs.hidden = false; errs.innerHTML = '';
            c.errors.forEach(function (m) { var d = document.createElement('div'); d.textContent = m; errs.appendChild(d); });
            Object.keys(out).forEach(function (k) { out[k].textContent = (k.slice(-3) === '_fx') ? '' : '—'; });
            $('hhRawInfo').textContent = '';
            $('hhProfitRow').classList.remove('hh-loss'); $('hhProfitLbl').textContent = 'Kâr / Zarar';
            return;
        }
        errs.hidden = true; errs.innerHTML = '';
        var v = view(c.n, c.r, currency, cfg.savedName || '');
        ['total', 'total_fx', 'unit', 'unit_fx', 'sale', 'sale_fx', 'profit', 'profit_fx', 'margin', 'breakeven', 'entered', 'suggested'].forEach(function (k) { setText(k, v[k]); });
        $('hhRawInfo').textContent = v.rawInfo;
        $('hhProfitRow').classList.toggle('hh-loss', v.loss);
        $('hhProfitLbl').textContent = v.loss ? 'Zarar' : 'Kâr';
        c.v = v;
    }

    document.querySelectorAll('[data-hh]').forEach(function (e) {
        if (e.type === 'text') {
            e.addEventListener('input', render);
            e.addEventListener('blur', function () {   // geçerli değeri düzgün biçimle; imleç yalnız odak kaybında etkilenir
                var p = parseNum(e.value, '');
                if (!p.error && !p.empty) e.value = fmtInput(p.value, e === el.rate ? 2 : 0);
            });
        }
    });
    el.price_in_fx.addEventListener('change', render);
    el.currency.addEventListener('change', function () {
        rates[currency] = el.rate.value;          // eski dövizin kuru saklanır, yenisine ASLA taşınmaz
        currency = el.currency.value === 'EUR' ? 'EUR' : 'USD';
        el.rate.value = rates[currency];
        render();
    });

    function status(msg, bad) { var s = $('hhStatus'); s.textContent = msg; s.className = 'hh-hint hh-status' + (bad ? ' hh-bad' : ''); }

    var dlg = $('hhDialog');
    $('hhSave').addEventListener('click', function () {
        if (!last || !last.ok) { status('Önce hesabı hatasız tamamlayın.', true); return; }
        $('hhName').value = cfg.savedName || '';
        if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
        $('hhName').focus();
    });
    $('hhCancel').addEventListener('click', function () { if (dlg.close) dlg.close(); else dlg.removeAttribute('open'); });
    $('hhForm').addEventListener('submit', function (ev) {
        if (!last || !last.ok) { ev.preventDefault(); status('Hesap hatalı; kaydedilmedi.', true); return; }
        var name = trUpper($('hhName').value);
        if (!name) { ev.preventDefault(); $('hhName').focus(); return; }
        var n = last.n, d = new Date(), p2 = function (x) { return (x < 10 ? '0' : '') + x; };
        $('hhPayload').value = JSON.stringify({ model: 3, product: name,
            sheet_date: cfg.id && doc.sheet_date ? doc.sheet_date : d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate()),
            sale_kg: n.sale_kg, sale_price: n.sale_price, currency: currency, rate: n.rate, price_in_fx: n.price_in_fx,
            raw_price: n.raw_price, freight: n.freight, material_kg: n.material_kg, docs: n.docs, other_kg: n.other_kg,
            other_fixed: n.other_fixed, waste_pct: n.waste_pct, target_margin: n.target_margin });
    });

    $('hhShare').addEventListener('click', function () {
        if (!last || !last.ok) { status('Paylaşmak için hesabı hatasız tamamlayın.', true); return; }
        var text = shareText(last.n, last.r, currency, cfg.savedName || '', last.v);
        if (navigator.share) {
            navigator.share({ title: 'Hızlı Hesapla', text: text }).then(function () { status('Paylaşıldı.'); })
                .catch(function (e) { if (!e || e.name !== 'AbortError') copy(text); });
        } else copy(text);
    });
    function copy(text) {
        function manual() {
            status('Panoya kopyalanamadı; metni elle kopyalayın:', true);
            var t = document.createElement('textarea'); t.readOnly = true; t.className = 'hh-copy'; t.value = text; t.rows = 9;
            $('hhStatus').appendChild(t); t.focus(); t.select();
        }
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(function () { status('Metin panoya kopyalandı.'); }, manual);
        else manual();
    }
    render();
}(typeof window !== 'undefined' ? window : globalThis));
