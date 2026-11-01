'use strict';
// Hızlı Hesapla — JS önizleme motoru (model 3 = eski fire, model 4 = ihracat randımanı). Otorite PHP'dir
// (config/hizli_hesapla.php); kaydedilen sonuçları sunucu yeniden hesaplar. 'compute' = hh_compute (v3),
// 'compute4' = hh_compute_v4: işlem sırası BİREBİR aynıdır.
(function (root) {
    var MAX = 1000000;
    var NUM_RE = /^(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d{1,4})?$/;

    /** Türkçe sayı; boş → {value:0, empty:true}; geçersiz → {error}. Sessizce 0 yapılmaz. */
    function parseNum(text, label, maxDec) {
        var s = String(text == null ? '' : text).trim();
        if (s === '') return { value: 0, empty: true };
        var re = maxDec && maxDec !== 4 ? new RegExp('^(?:\\d+|\\d{1,3}(?:\\.\\d{3})+)(?:,\\d{1,' + maxDec + '})?$') : NUM_RE;
        if (!re.test(s)) return { error: label + ': geçerli bir sayı girin (örn. 1.250,50).' };
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

    function compute4(n) {
        var s = n.sale_kg;
        var rawKg = s / (n.yield_pct / 100);
        var rawCost = rawKg * n.raw_price;
        var material = s * n.material_kg;
        var otherBase = n.other_kg_basis === 'raw' ? rawKg : s;
        var otherKg = otherBase * n.other_kg;
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

    var LABELS = { yield_pct: 'İhracat randımanı', sale_kg: 'Satış net KG', sale_price: 'Satış fiyatı', rate: 'Kur', raw_price: 'Ham mal alış fiyatı',
        freight: 'Nakliye bedeli', material_kg: 'Malzeme gideri', docs: 'Evrak masrafları', other_kg: 'Diğer masraf',
        other_fixed: 'Diğer sabit masraf', waste_pct: 'Fire oranı', target_margin: 'Hedef kâr marjı' };

    /** Ham alan metinlerinden doğrulanmış hesap. raw: {model, alan: metin, currency, price_in_fx, other_kg_basis}. */
    function calculate(raw) {
        var errors = [], n = {}, m4 = raw.model !== 3;
        var keys = ['sale_kg', 'sale_price', 'rate', 'raw_price', 'freight', 'material_kg', 'docs', 'other_kg', 'other_fixed', m4 ? 'yield_pct' : 'waste_pct'];
        keys.forEach(function (k) {
            var label = k === 'rate' ? raw.currency + ' kuru' : LABELS[k];
            var p = parseNum(raw[k], label, (m4 && k === 'sale_price') ? 8 : 4);
            if (p.error) { errors.push(p.error); n[k] = 0; return; }
            n[k] = p.value;
            if (p.empty && (k === 'sale_kg' || k === 'rate')) errors.push(label + ': değer girin.');
            if (p.empty && k === 'yield_pct') errors.push('İhracat randımanı girin (%0\'dan büyük, en çok %100).');
        });
        var tmP = parseNum(raw.target_margin, LABELS.target_margin);
        if (tmP.error) { errors.push(tmP.error); n.target_margin = null; }
        else n.target_margin = tmP.empty ? null : tmP.value;
        n.price_in_fx = !!raw.price_in_fx;
        if (m4) {
            n.other_kg_basis = raw.other_kg_basis === 'raw' ? 'raw' : 'net';
            n.model = 4;
        } else n.model = 3;
        if (!errors.length) {
            if (!(n.sale_kg > 0)) errors.push('Satış net KG sıfırdan büyük olmalı.');
            if (!(n.rate > 0)) errors.push(raw.currency + ' kuru sıfırdan büyük olmalı.');
            if (m4) {
                if (!(n.yield_pct > 0 && n.yield_pct <= 100)) errors.push('İhracat randımanı girin: %0\'dan büyük, en çok %100 olmalı.');
            } else if (n.waste_pct >= 100) errors.push('Fire oranı %100\'ün altında olmalı.');
            if (n.target_margin !== null && n.target_margin >= 100) errors.push('Hedef kâr marjı %100\'ün altında olmalı.');
        }
        if (errors.length) return { ok: false, errors: errors };
        return { ok: true, n: n, r: m4 ? compute4(n) : compute(n) };
    }

    function group(intStr) { return intStr.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
    function fmt(x, d) {
        var neg = x < 0 && Math.abs(x) >= 0.5 * Math.pow(10, -d);
        var s = Math.abs(x).toFixed(d), parts = s.split('.');
        return (neg ? '-' : '') + group(parts[0]) + (d ? ',' + parts[1] : '');
    }
    /** Girdi kutusu için: en çok 4 ondalık, sondaki sıfırlar atılır. */
    function fmtInput(x, minDec, maxDec) {
        if (typeof x !== 'number') return String(x == null ? '' : x);
        var s = fmt(x, maxDec || 4), m = minDec || 0;
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
        v.rawKg = fmt(r.raw_kg, 0) + ' kg';
        v.share = shareText(n, r, cur, name, v);
        return v;
    }

    function shareText(n, r, cur, name, v) {
        var L = ['HIZLI HESAPLA' + (name ? ' — ' + name : '')];
        L.push('Satış net KG: ' + fmt(n.sale_kg, 0) + ' kg');
        if (n.model === 4) {
            L.push('İhracat randımanı: %' + fmt(n.yield_pct, 2));
            L.push('Gerekli ham mal: ' + v.rawKg);
        }
        L.push('Döviz / kur: ' + cur + ' · 1 ' + cur + ' = ' + fmt(n.rate, 2) + ' TL');
        L.push('Satış fiyatı: ' + v.entered);
        L.push('Toplam maliyet: ' + v.total + ' ' + v.total_fx);
        L.push('Net kg maliyeti: ' + v.unit + ' ' + v.unit_fx);
        if (n.model === 4) L.push('Not: yan ürün geliri düşülmemiş tahmini ihracat maliyeti');
        L.push('Satış toplamı: ' + v.sale + ' ' + v.sale_fx);
        L.push((v.loss ? 'Zarar: ' : 'Kâr: ') + v.profit + ' ' + v.profit_fx);
        L.push('Kâr marjı: ' + v.margin);
        L.push('Başabaş fiyatı: ' + v.breakeven);
        L.push(n.target_margin === null ? 'Önerilen satış fiyatı: hedef marj girilmedi'
            : 'Hedef marj %' + fmt(n.target_margin, 2) + ' → önerilen satış fiyatı: ' + v.suggested);
        return L.join('\n');
    }

    function trUpper(s) { return String(s).trim().toLocaleUpperCase('tr-TR'); }

    var api = { parseNum: parseNum, compute: compute, compute4: compute4, calculate: calculate, fmt: fmt, fmtInput: fmtInput, view: view, trUpper: trUpper };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.HizliHesapla = api;

    /* ---------------------------------------------------------------- arayüz */
    if (typeof document === 'undefined' || !document.getElementById('hh')) return;
    var cfg = root.HH_CONFIG || {}, doc = cfg.document || {};
    var MODEL = cfg.model === 3 ? 3 : 4;
    var el = {}; document.querySelectorAll('[data-hh]').forEach(function (e) { el[e.getAttribute('data-hh')] = e; });
    var out = {}; document.querySelectorAll('[data-hh-out]').forEach(function (e) { out[e.getAttribute('data-hh-out')] = e; });
    var $ = function (id) { return document.getElementById(id); };
    var rates = { USD: '', EUR: '' };
    var currency = doc.currency === 'EUR' ? 'EUR' : 'USD';
    var last = null;
    var pendingTl = null;   // döviz değişti ama yeni kur henüz yok: korunacak TL/kg satış fiyatı

    var NUMERIC = ['sale_kg', 'sale_price', 'raw_price', 'freight', 'material_kg', 'docs', 'other_kg', 'other_fixed', 'waste_pct', 'yield_pct', 'target_margin'];
    NUMERIC.forEach(function (k) {
        if (!el[k]) return;
        el[k].value = doc[k] === null || doc[k] === undefined ? '' : fmtInput(doc[k], k === 'sale_price' ? 2 : 0, k === 'sale_price' ? 8 : 4);
    });
    if (el.other_kg_basis) el.other_kg_basis.value = doc.other_kg_basis === 'raw' ? 'raw' : 'net';
    rates[currency] = fmtInput(doc.rate, 2);
    el.currency.value = currency;
    el.price_in_fx.checked = doc.price_in_fx === undefined ? true : (doc.price_in_fx === true || doc.price_in_fx === 1 || doc.price_in_fx === '1');
    el.rate.value = rates[currency];
    if (cfg.readOnly) Object.keys(el).forEach(function (k) { el[k].disabled = true; });

    function raw() {
        var r = { model: MODEL, currency: currency, price_in_fx: el.price_in_fx.checked };
        Object.keys(el).forEach(function (k) { if (k !== 'currency' && k !== 'price_in_fx') r[k] = el[k].value; });
        return r;
    }
    function setText(key, text) { if (out[key]) out[key].textContent = text; }
    function status(msg, bad) { var s = $('hhStatus'); s.textContent = msg; s.className = 'hh-hint hh-status' + (bad ? ' hh-bad' : ''); }

    /** Geçerli kur ve fiyat varsa {rate, price}; yoksa null. */
    function okRatePrice() {
        var rp = parseNum(el.rate.value, 'Kur'), pp = parseNum(el.sale_price.value, 'Satış fiyatı', MODEL === 4 ? 8 : 4);
        if (rp.error || rp.empty || !(rp.value > 0) || pp.error) return null;
        return { rate: rp.value, price: pp.value };
    }
    function put8(x) { return fmtInput(Math.round(x * 1e8) / 1e8, 2, 8); }

    function render() {
        $('hhRateLabel').textContent = currency + ' kuru (TL)';
        $('hhPriceHint').textContent = 'Satış fiyatı ' + (el.price_in_fx.checked ? currency + '/kg' : 'TL/kg') + ' olarak yorumlanır.';
        var c = calculate(raw()), errs = $('hhErrs');
        last = c;
        if (!c.ok) {
            errs.hidden = false; errs.innerHTML = '';
            var list = c.errors.slice();
            if (pendingTl !== null) list.unshift(currency + ' kurunu girin; satış fiyatının TL karşılığı (' + fmt(pendingTl, 4) + ' TL/kg) korunarak ' + currency + ' fiyatı hesaplanacak.');
            list.forEach(function (m) { var d = document.createElement('div'); d.textContent = m; errs.appendChild(d); });
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
            e.addEventListener('input', function () { if (e === el.sale_price) pendingTl = null; render(); tryPending(); });
            e.addEventListener('blur', function () {   // geçerli değeri düzgün biçimle; imleç yalnız odak kaybında etkilenir
                var p = parseNum(e.value, '', e === el.sale_price && MODEL === 4 ? 8 : 4);
                if (!p.error && !p.empty) e.value = fmtInput(p.value, (e === el.rate || e === el.sale_price) ? 2 : 0, e === el.sale_price && MODEL === 4 ? 8 : 4);
            });
        }
    });
    if (el.other_kg_basis) el.other_kg_basis.addEventListener('change', render);

    /** Fiyat döviz kutusu: satışın TL ekonomik değeri KORUNUR (1,10 USD @ 50 ⇄ 55 TL/kg). Kur yoksa değişiklik geri alınır. */
    el.price_in_fx.addEventListener('change', function () {
        var rp = okRatePrice();
        if (!rp) {
            el.price_in_fx.checked = !el.price_in_fx.checked;
            status('Satış fiyatını dönüştürmek için geçerli ' + currency + ' kuru ve fiyat girin.', true);
            render();
            return;
        }
        pendingTl = null;
        el.sale_price.value = put8(el.price_in_fx.checked ? rp.price / rp.rate : rp.price * rp.rate);
        status(el.price_in_fx.checked ? 'Satış fiyatı ' + currency + '/kg olarak güncellendi (TL değeri korundu).' : 'Satış fiyatı TL/kg olarak güncellendi (değer korundu).');
        render();
    });

    /** Döviz değişti: TL karşılığı korunarak yeni dövizde fiyat; yeni kur yoksa kur istenir ve fiyat beklemede tutulur. */
    el.currency.addEventListener('change', function () {
        var old = currency, rpOld = okRatePrice();
        rates[old] = el.rate.value;               // eski dövizin kuru saklanır, yenisine ASLA taşınmaz
        currency = el.currency.value === 'EUR' ? 'EUR' : 'USD';
        el.rate.value = rates[currency];
        pendingTl = (el.price_in_fx.checked && rpOld) ? rpOld.price * rpOld.rate : null;
        render();
        tryPending();
    });
    el.rate.addEventListener('input', function () { rates[currency] = el.rate.value; tryPending(); });
    function tryPending() {
        if (pendingTl === null) return;
        var rp = okRatePrice(); if (!rp) return;
        el.sale_price.value = put8(pendingTl / rp.rate);
        status('Satış fiyatı ' + currency + ' kuruna göre güncellendi (TL değeri korundu).');
        pendingTl = null;
        render();
    }

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
        var pl = { model: MODEL, product: name,
            sheet_date: cfg.id && !cfg.copy && doc.sheet_date ? doc.sheet_date : d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate()),
            sale_kg: n.sale_kg, sale_price: n.sale_price, currency: currency, rate: n.rate, price_in_fx: n.price_in_fx,
            raw_price: n.raw_price, freight: n.freight, material_kg: n.material_kg, docs: n.docs, other_kg: n.other_kg,
            other_fixed: n.other_fixed, target_margin: n.target_margin };
        if (MODEL === 4) { pl.yield_pct = n.yield_pct; pl.other_kg_basis = n.other_kg_basis; } else pl.waste_pct = n.waste_pct;
        $('hhPayload').value = JSON.stringify(pl);
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
