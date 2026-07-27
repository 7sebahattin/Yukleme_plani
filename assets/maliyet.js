/* =========================================================
   assets/maliyet.js — Maliyet Hesabı modülü (yalnız maliyet_* sayfaları)
   config/cost_calc.php içindeki formül motorunun birebir aynası.
   Buradaki hesap SADECE canlı önizlemedir; kaydedilen değerleri
   her zaman PHP tarafı yeniden hesaplar.
   ========================================================= */
(function () {
'use strict';

var CFG = window.MLY_CONFIG || { fields: [], calcTypes: {}, pctBases: {}, sysVars: {}, pkgPrices: [] };

/* ---------- Sayı yardımcıları ---------- */
function num(v) {
    if (v === null || v === undefined) return 0;
    var s = String(v).replace(/[\s ]/g, '');
    if (s === '') return 0;
    if (s.indexOf(',') >= 0) s = s.replace(/\./g, '').replace(',', '.');
    else s = s.replace(/\./g, '');           // nokta = binlik ayracı (PHP num() ile aynı)
    var f = parseFloat(s);
    return isFinite(f) ? f : 0;
}
function fmt(v, dec) {
    if (!isFinite(v)) v = 0;
    return v.toLocaleString('tr-TR', { minimumFractionDigits: dec, maximumFractionDigits: dec });
}
function fmtQty(v) {
    if (!isFinite(v)) v = 0;
    if (Math.abs(v - Math.round(v)) < 0.0005) return fmt(Math.round(v), 0);
    return fmt(v, 3).replace(/0+$/, '').replace(/,$/, '');
}

/* ---------- Formül motoru ---------- */
function FormulaError(msg) { this.message = msg; }

var TR_MAP = { 'İ':'i','I':'i','Ş':'s','ş':'s','Ğ':'g','ğ':'g','Ü':'u','ü':'u','Ö':'o','ö':'o','Ç':'c','ç':'c','ı':'i' };
function normVar(name) {
    name = String(name).trim().replace(/[İIŞşĞğÜüÖöÇçı]/g, function (c) { return TR_MAP[c] || c; });
    name = name.toLowerCase().replace(/[^a-z0-9_.]+/g, '_');
    return name.replace(/^_+|_+$/g, '');
}

function isDigit(c) { return c >= '0' && c <= '9'; }
function isAlpha(c) { return /[a-zA-Z_]/.test(c); }                       // tanımlayıcı başlangıcı
function isWordChar(c) { return /[a-zA-Z0-9_]/.test(c) || c.charCodeAt(0) > 127; }

function tokenize(expr) {
    var tokens = [], i = 0, len = expr.length, prev = null;
    while (i < len) {
        var c = expr[i];
        if (c === ' ' || c === '\t' || c === '\n' || c === '\r') { i++; continue; }

        if (c === '[') {
            var end = expr.indexOf(']', i);
            if (end < 0) throw new FormulaError('Kapanmayan köşeli parantez');
            var nm = expr.substring(i + 1, end).trim();
            if (!nm) throw new FormulaError('Boş değişken adı: []');
            tokens.push({ t: 'var', v: normVar(nm) });
            prev = 'val'; i = end + 1; continue;
        }

        if (isDigit(c) || ((c === '.' || c === ',') && i + 1 < len && isDigit(expr[i + 1]))) {
            var j = i, seen = false;
            while (j < len) {
                var d = expr[j];
                if (isDigit(d)) { j++; continue; }
                if ((d === '.' || d === ',') && !seen && j + 1 < len && isDigit(expr[j + 1])) { seen = true; j++; continue; }
                break;
            }
            tokens.push({ t: 'num', v: parseFloat(expr.substring(i, j).replace(',', '.')) });
            prev = 'val'; i = j; continue;
        }

        if (isAlpha(c)) {
            var k = i;
            while (k < len && isWordChar(expr[k])) k++;
            var word = expr.substring(i, k).toLowerCase();
            var m = k; while (m < len && expr[m] === ' ') m++;
            if (m < len && expr[m] === '(') { tokens.push({ t: 'func', v: word }); prev = 'func'; }
            else { tokens.push({ t: 'var', v: normVar(word) }); prev = 'val'; }
            i = k; continue;
        }

        var two = expr.substr(i, 2);
        if (two === '>=' || two === '<=' || two === '==' || two === '!=' || two === '<>') {
            tokens.push({ t: 'op', v: two === '<>' ? '!=' : two });
            prev = 'op'; i += 2; continue;
        }
        if ('+-*/%^'.indexOf(c) >= 0) {
            if (c === '-' && (prev === null || prev === 'op' || prev === 'lp' || prev === 'sep' || prev === 'func')) {
                tokens.push({ t: 'op', v: 'u-' });
            } else if (c === '+' && (prev === null || prev === 'op' || prev === 'lp' || prev === 'sep' || prev === 'func')) {
                /* tekli artı — yok say */
            } else {
                tokens.push({ t: 'op', v: c });
            }
            prev = 'op'; i++; continue;
        }
        if (c === '>' || c === '<' || c === '=') { tokens.push({ t: 'op', v: c === '=' ? '==' : c }); prev = 'op'; i++; continue; }
        if (c === '(') { tokens.push({ t: 'lp' }); prev = 'lp'; i++; continue; }
        if (c === ')') { tokens.push({ t: 'rp' }); prev = 'val'; i++; continue; }
        if (c === ',' || c === ';') { tokens.push({ t: 'sep' }); prev = 'sep'; i++; continue; }

        throw new FormulaError('Tanınmayan karakter: ' + c);
    }
    return tokens;
}

function opInfo(op) {
    if (op === 'u-') return { p: 5, right: true, args: 1 };
    if (op === '^')  return { p: 4, right: true, args: 2 };
    if (op === '*' || op === '/' || op === '%') return { p: 3, right: false, args: 2 };
    if (op === '+' || op === '-') return { p: 2, right: false, args: 2 };
    return { p: 1, right: false, args: 2 };
}

function toRpn(tokens) {
    var out = [], stack = [], argc = [];
    for (var n = 0; n < tokens.length; n++) {
        var tk = tokens[n];
        if (tk.t === 'num' || tk.t === 'var') { out.push(tk); continue; }
        if (tk.t === 'func') { stack.push(tk); argc.push(1); continue; }
        if (tk.t === 'sep') {
            while (stack.length && stack[stack.length - 1].t !== 'lp') out.push(stack.pop());
            if (!stack.length) throw new FormulaError('Parantez hatası (ayraç)');
            if (argc.length) argc[argc.length - 1]++;
            continue;
        }
        if (tk.t === 'op') {
            var o1 = opInfo(tk.v);
            while (stack.length) {
                var top = stack[stack.length - 1];
                if (top.t !== 'op') break;
                var o2 = opInfo(top.v);
                if ((o1.right && o1.p < o2.p) || (!o1.right && o1.p <= o2.p)) out.push(stack.pop());
                else break;
            }
            stack.push(tk); continue;
        }
        if (tk.t === 'lp') { stack.push(tk); continue; }
        if (tk.t === 'rp') {
            while (stack.length && stack[stack.length - 1].t !== 'lp') out.push(stack.pop());
            if (!stack.length) throw new FormulaError('Fazla kapanış parantezi');
            stack.pop();
            if (stack.length && stack[stack.length - 1].t === 'func') {
                var f = stack.pop();
                f.argc = argc.pop() || 1;
                out.push(f);
            }
            continue;
        }
    }
    while (stack.length) {
        var t = stack.pop();
        if (t.t === 'lp') throw new FormulaError('Kapanmayan parantez');
        out.push(t);
    }
    return out;
}

function callFunc(name, a) {
    switch (name) {
        case 'yuvarla': case 'round': {
            var d = Math.pow(10, a[1] || 0);
            return Math.round((a[0] || 0) * d) / d;
        }
        case 'tavan': case 'ceil':  return Math.ceil(a[0] || 0);
        case 'taban': case 'floor': return Math.floor(a[0] || 0);
        case 'mutlak': case 'abs':  return Math.abs(a[0] || 0);
        case 'min': return a.length ? Math.min.apply(null, a) : 0;
        case 'maks': case 'max': return a.length ? Math.max.apply(null, a) : 0;
        case 'topla': case 'sum': return a.reduce(function (x, y) { return x + y; }, 0);
        case 'ort': case 'avg': return a.length ? a.reduce(function (x, y) { return x + y; }, 0) / a.length : 0;
        case 'eger': case 'if': return (a[0] || 0) !== 0 ? (a[1] || 0) : (a[2] || 0);
        default: throw new FormulaError('Bilinmeyen fonksiyon: ' + name + '()');
    }
}

function evalRpn(rpn, vars) {
    var st = [];
    for (var n = 0; n < rpn.length; n++) {
        var tk = rpn[n];
        if (tk.t === 'num') { st.push(tk.v); continue; }
        if (tk.t === 'var') { st.push(Number(vars[tk.v] || 0)); continue; }
        if (tk.t === 'op') {
            var o = opInfo(tk.v);
            if (o.args === 1) { st.push(-(st.pop() || 0)); continue; }
            if (st.length < 2) throw new FormulaError('Eksik işlenen: ' + tk.v);
            var b = st.pop(), a = st.pop();
            switch (tk.v) {
                case '+': st.push(a + b); break;
                case '-': st.push(a - b); break;
                case '*': st.push(a * b); break;
                case '/': st.push(Math.abs(b) < 1e-12 ? 0 : a / b); break;
                case '%': st.push(Math.abs(b) < 1e-12 ? 0 : a % b); break;
                case '^': st.push(Math.pow(a, b)); break;
                case '>':  st.push(a >  b ? 1 : 0); break;
                case '<':  st.push(a <  b ? 1 : 0); break;
                case '>=': st.push(a >= b ? 1 : 0); break;
                case '<=': st.push(a <= b ? 1 : 0); break;
                case '==': st.push(Math.abs(a - b) < 1e-9 ? 1 : 0); break;
                case '!=': st.push(Math.abs(a - b) < 1e-9 ? 0 : 1); break;
                default: throw new FormulaError('Bilinmeyen operatör: ' + tk.v);
            }
            continue;
        }
        if (tk.t === 'func') {
            var cnt = tk.argc || 1, args = [];
            for (var k = 0; k < cnt; k++) args.unshift(st.pop() || 0);
            st.push(callFunc(tk.v, args));
        }
    }
    if (st.length !== 1) throw new FormulaError('Geçersiz ifade');
    var r = st[0];
    return isFinite(r) ? r : 0;
}

var rpnCache = {};
function evalFormula(expr, vars) {
    expr = String(expr || '').trim();
    if (!expr) return { value: 0, error: null };
    try {
        if (!rpnCache[expr]) rpnCache[expr] = toRpn(tokenize(expr));
        return { value: evalRpn(rpnCache[expr], vars), error: null };
    } catch (e) {
        return { value: 0, error: e.message || 'Formül hatası' };
    }
}
function formulaDeps(expr) {
    expr = String(expr || '').trim();
    if (!expr) return [];
    var toks;
    try { toks = tokenize(expr); } catch (e) { return []; }
    var out = {}, res = [];
    for (var i = 0; i < toks.length; i++) {
        if (toks[i].t !== 'var') continue;
        var root = toks[i].v.split('.')[0];
        if (root && !out[root]) { out[root] = 1; res.push(root); }
    }
    return res;
}

/* ---------- DOM okuma ---------- */
function slug(label) {
    var s = normVar(label).replace(/[^a-z0-9_]+/g, '_').replace(/_+/g, '_').replace(/^_+|_+$/g, '');
    return (s || 'alan').substring(0, 36);
}

function readSheetVars() {
    var vars = {
        net_kg: 0, kur: 0, navlun: 0, satis_kg: 0, satis_fiyat: 0,
        baz: 0, ust_toplam: 0, bolum_toplam: 0, genel_toplam: 0
    };
    document.querySelectorAll('[data-mly]').forEach(function (el) {
        vars[el.getAttribute('data-mly')] = num(el.value);
    });
    vars.baz = vars.net_kg;
    return vars;
}

function readSection(secEl) {
    var q = function (sel) { return secEl.querySelector(sel); };
    return {
        el:          secEl,
        key:         secEl.getAttribute('data-sec-key'),
        title:       (q('.mly-sec-title') || {}).value || '',
        basisType:   (q('.mly-basis-type') || {}).value || 'sheet',
        basisValue:  num(((q('.mly-basis-fixed input') || {}).value) || 0),
        basisFormula:((q('.mly-basis-formula input') || {}).value) || '',
        include:     !!(q('.mly-sec-include') && q('.mly-sec-include').checked),
        rows:        Array.prototype.slice.call(secEl.querySelectorAll('tr.mly-row'))
    };
}

function readRow(tr, order) {
    // Kod / miktar formülü / yüzde bazı / gelir bayrağı AYRI bir detay satırında durur —
    // her iki satırda da aramak zorundayız.
    var key = tr.getAttribute('data-key');
    var det = tr.parentNode.querySelector('tr.mly-row-detail[data-for="' + key + '"]');
    var q = function (sel) { return tr.querySelector(sel) || (det && det.querySelector(sel)) || null; };
    var v = function (sel) { var e = q(sel); return e ? e.value : ''; };
    var label = v('.mly-label');
    var code  = v('.mly-code');
    return {
        el:        tr,
        key:       key,
        order:     order,
        label:     label,
        code:      code ? slug(code) : (label ? slug(label) : ''),
        type:      v('.mly-type') || 'qty_price',
        qty:       num(v('.mly-qty')),
        qtyFormula:v('.mly-qtyf'),
        price:     num(v('.mly-price')),
        percent:   num(v('.mly-percent')),
        pctBase:   v('.mly-pctbase') || 'ust_toplam',
        formula:   v('.mly-formula'),
        isIncome:  !!(q('.mly-income') && q('.mly-income').checked)
    };
}

/* ---------- Bölüm hesabı (PHP cost_compute_section_items aynası) ---------- */
function computeSection(rows, baseVars, basis, errors) {
    var vars = {};
    for (var k in baseVars) vars[k] = baseVars[k];
    vars.baz = basis;

    var byKey = {}, codeKey = {};
    rows.forEach(function (r) {
        byKey[r.key] = r;
        if (r.code) codeKey[r.code] = r.key;
    });

    // Bağımlılıklar
    var deps = {};
    rows.forEach(function (r) {
        var set = {}, exprs = [];
        if (r.qtyFormula) exprs.push(r.qtyFormula);
        if (r.type === 'formula') exprs.push(r.formula);
        if (r.type === 'percent') exprs.push(r.pctBase);
        exprs.forEach(function (e) {
            formulaDeps(e).forEach(function (d) {
                if (codeKey[d] && codeKey[d] !== r.key) set[codeKey[d]] = 1;
                if (d === 'ust_toplam' || d === 'bolum_toplam') {
                    rows.forEach(function (r2) {
                        if (r2.key === r.key) return;
                        if (d === 'bolum_toplam' || r2.order < r.order) set[r2.key] = 1;
                    });
                }
            });
        });
        if (r.type === 'subtotal') {
            rows.forEach(function (r2) { if (r2.order < r.order) set[r2.key] = 1; });
        }
        deps[r.key] = Object.keys(set);
    });

    // Topolojik sıralama
    var order = [], state = {}, cycle = {};
    function visit(key) {
        if (state[key] === 2) return;
        if (state[key] === 1) { cycle[key] = 1; return; }
        state[key] = 1;
        (deps[key] || []).forEach(visit);
        state[key] = 2;
        order.push(key);
    }
    rows.forEach(function (r) { visit(r.key); });
    if (Object.keys(cycle).length) {
        errors.push('Döngüsel formül: ' + Object.keys(cycle).map(function (k) {
            return (byKey[k] && byKey[k].label) || k;
        }).join(', '));
    }

    var computed = {};
    order.forEach(function (key) {
        var r = byKey[key];
        if (!r) return;

        var prevSum = 0, secSum = 0;
        for (var ck in computed) {
            secSum += computed[ck].signed;
            if (byKey[ck] && byKey[ck].order < r.order) prevSum += computed[ck].signed;
        }
        vars.ust_toplam   = prevSum;
        vars.bolum_toplam = secSum;

        var err = null, qty = r.qty;
        if (r.qtyFormula && String(r.qtyFormula).trim() !== '') {
            var qres = evalFormula(r.qtyFormula, vars);
            qty = qres.value;
            if (qres.error) err = 'Miktar formülü: ' + qres.error;
        }
        if (r.type === 'per_kg') qty = basis;

        var amount = 0, res;
        switch (r.type) {
            case 'qty_price':
            case 'per_kg':
                amount = qty * r.price; break;
            case 'fixed':
                amount = r.price * (qty > 0 ? qty : 1); break;
            case 'percent':
                res = evalFormula(String(r.pctBase || 'ust_toplam').trim() || 'ust_toplam', vars);
                if (res.error && !err) err = 'Yüzde bazı: ' + res.error;
                amount = res.value * (r.percent / 100); break;
            case 'formula':
                res = evalFormula(r.formula, vars);
                if (res.error && !err) err = 'Formül: ' + res.error;
                amount = res.value; break;
            case 'subtotal':
                amount = prevSum; break;
            default:
                amount = 0;
        }
        if (!isFinite(amount)) amount = 0;
        amount = Math.round(amount * 10000) / 10000;

        var counts = (r.type !== 'info' && r.type !== 'subtotal');
        computed[key] = {
            qty: qty, price: r.price, amount: amount, counts: counts,
            signed: counts ? (r.isIncome ? -amount : amount) : 0,
            error: err
        };
        if (err) errors.push((r.label || 'Satır') + ': ' + err);

        if (r.code) {
            vars[r.code] = amount;
            vars[r.code + '.tutar']  = amount;
            vars[r.code + '.miktar'] = qty;
            vars[r.code + '.fiyat']  = r.price;
        }
    });

    var total = 0;
    rows.forEach(function (r) { if (computed[r.key]) total += computed[r.key].signed; });
    return { computed: computed, total: Math.round(total * 10000) / 10000 };
}

/* ---------- Ana hesap + DOM güncelleme ---------- */
function recalc() {
    var vars   = readSheetVars();
    var errors = [];
    var grand  = 0;
    var secEls = document.querySelectorAll('#mlySections .mly-section');

    secEls.forEach(function (secEl) {
        var sec  = readSection(secEl);
        var rows = sec.rows.map(function (tr, i) { return readRow(tr, i); });

        function basisOf(v) {
            if (sec.basisType === 'fixed')   return sec.basisValue;
            if (sec.basisType === 'none')    return 0;
            if (sec.basisType === 'formula') {
                var r = evalFormula(sec.basisFormula, v);
                if (r.error) errors.push('Bölüm “' + sec.title + '” bazı: ' + r.error);
                return r.value;
            }
            return v.net_kg;
        }

        var basis = basisOf(vars);
        var out   = computeSection(rows, vars, basis, errors);

        // Baz formülü kalemlere dayanıyorsa ikinci geçiş
        if (sec.basisType === 'formula') {
            var v2 = {};
            for (var k in vars) v2[k] = vars[k];
            rows.forEach(function (r) {
                var c = out.computed[r.key];
                if (!c || !r.code) return;
                v2[r.code] = c.amount;
                v2[r.code + '.tutar']  = c.amount;
                v2[r.code + '.miktar'] = c.qty;
                v2[r.code + '.fiyat']  = c.price;
            });
            var basis2 = basisOf(v2);
            if (Math.abs(basis2 - basis) > 1e-9) {
                basis = basis2;
                out = computeSection(rows, vars, basis, errors);
            }
        }

        // Satır çıktıları
        rows.forEach(function (r) {
            var c  = out.computed[r.key] || { qty: 0, amount: 0, error: null };
            var am = r.el.querySelector('.mly-amount');
            var uc = r.el.querySelector('.mly-ucost');
            var qi = r.el.querySelector('.mly-qty');
            if (am) am.textContent = fmt(c.amount, 2);
            if (uc) uc.textContent = basis > 0 ? fmt(c.amount / basis, 4) : '—';
            if (qi && qi.hasAttribute('readonly')) qi.value = fmtQty(c.qty);
            r.el.classList.toggle('mly-row-income', r.isIncome);
            r.el.classList.toggle('mly-row-error', !!c.error);

            var det = r.el.parentNode.querySelector('tr.mly-row-detail[data-for="' + r.key + '"]');
            if (det) {
                var box = det.querySelector('.mly-row-err');
                if (box) {
                    box.textContent = c.error || '';
                    box.hidden = !c.error;
                }
            }
            // Kod ipucu
            var codeInput = det && det.querySelector('.mly-code');
            if (codeInput && !codeInput.value) codeInput.placeholder = r.code || 'otomatik';
        });

        // Bölüm toplamı
        var tAmt = secEl.querySelector('.mly-sec-total-amount');
        var tUni = secEl.querySelector('.mly-sec-total-unit');
        var tTxt = secEl.querySelector('.mly-sec-basis-txt');
        if (tAmt) tAmt.textContent = fmt(out.total, 2);
        if (tUni) tUni.textContent = basis > 0 ? fmt(out.total / basis, 4) : '—';
        if (tTxt) {
            var lbl = secEl.querySelector('[name*="[basis_label]"]');
            tTxt.textContent = basis > 0
                ? '(' + ((lbl && lbl.value) || 'NET KG') + ': ' + fmtQty(basis) + ')'
                : '';
        }
        secEl.classList.toggle('mly-sec-excluded', !sec.include);

        if (sec.include) grand += out.total;

        // Sonraki bölümler bu bölümün kalemlerini görebilsin
        rows.forEach(function (r) {
            var c = out.computed[r.key];
            if (!c || !r.code) return;
            vars[r.code] = c.amount;
            vars[r.code + '.tutar']  = c.amount;
            vars[r.code + '.miktar'] = c.qty;
            vars[r.code + '.fiyat']  = c.price;
        });
    });

    vars.genel_toplam = grand;
    vars.baz = vars.net_kg;

    // Formül tipli kullanıcı alanları
    (CFG.fields || []).forEach(function (f) {
        if (f.type !== 'formula') return;
        var r = evalFormula(f.formula, vars);
        if (r.error) errors.push('Alan “' + f.label + '”: ' + r.error);
        vars[f.code] = r.value;
        var out = document.querySelector('[data-mly-field="' + f.code + '"]');
        if (out) out.textContent = fmt(r.value, f.decimals != null ? f.decimals : 2);
    });

    // Özet
    var netKg   = vars.net_kg, rate = vars.kur;
    var totalFx = rate > 0 ? grand / rate : 0;
    var costFx  = totalFx + vars.navlun;
    var invFx   = vars.satis_kg * vars.satis_fiyat;
    var sums = {
        grand_total:   grand,
        unit_cost_tl:  netKg > 0 ? grand / netKg : 0,
        total_fx:      totalFx,
        total_cost_fx: costFx,
        invoice_fx:    invFx,
        profit_fx:     invFx - costFx
    };
    var decMap = { unit_cost_tl: 4 };
    for (var key in sums) {
        var el = document.querySelector('[data-sum="' + key + '"]');
        if (el) el.textContent = fmt(sums[key], decMap[key] != null ? decMap[key] : 2);
    }
    var pBox = document.querySelector('.mly-sum-profit');
    if (pBox) {
        pBox.classList.toggle('mly-pos', sums.profit_fx >= 0);
        pBox.classList.toggle('mly-neg', sums.profit_fx < 0);
    }
    var curEl = document.getElementById('mlyCur');
    if (curEl) {
        var cur = (curEl.value || 'EUR').toUpperCase();
        document.querySelectorAll('.mly-cur').forEach(function (e) { e.textContent = cur; });
    }

    // Uyarılar
    var warn = document.getElementById('mlyWarn');
    if (warn) {
        if (errors.length) {
            warn.innerHTML = '<strong>⚠ Formül uyarıları:</strong><ul>' +
                errors.slice(0, 8).map(function (e) {
                    return '<li>' + e.replace(/[<>&]/g, function (c) {
                        return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c];
                    }) + '</li>';
                }).join('') + '</ul>';
            warn.hidden = false;
        } else {
            warn.hidden = true;
            warn.innerHTML = '';
        }
    }
}

/* ---------- Satır tipi görünürlüğü ---------- */
function applyRowType(tr) {
    var type = (tr.querySelector('.mly-type') || {}).value || 'qty_price';
    tr.setAttribute('data-type', type);

    var det   = tr.parentNode.querySelector('tr.mly-row-detail[data-for="' + tr.getAttribute('data-key') + '"]');
    var price = tr.querySelector('.mly-price');
    var pct   = tr.querySelector('.mly-pct-wrap');
    var fml   = tr.querySelector('.mly-formula');
    var qty   = tr.querySelector('.mly-qty');
    var qtyf  = det && det.querySelector('.mly-qtyf');

    var isPct = type === 'percent', isFml = type === 'formula';
    var isRo  = type === 'subtotal' || type === 'info';

    if (price) price.hidden = isPct || isFml || isRo;
    if (pct)   pct.hidden   = !isPct;
    if (fml)   fml.hidden   = !isFml;
    if (det) {
        var pctDetail = det.querySelector('.mly-detail-pct');
        if (pctDetail) pctDetail.hidden = !isPct;
    }
    if (qty) {
        var lock = (type === 'per_kg') || isRo || isFml || !!(qtyf && qtyf.value.trim());
        if (lock) qty.setAttribute('readonly', 'readonly');
        else qty.removeAttribute('readonly');
    }
}

/* ---------- Satır / bölüm ekleme ---------- */
var keyCounter = Date.now() % 100000;
function nextKey(prefix) { keyCounter++; return prefix + keyCounter; }

function addRow(secEl, focus) {
    var tpl = document.getElementById('mlyRowTpl');
    if (!tpl) return;
    var key  = nextKey('n');
    var html = tpl.innerHTML
        .replace(/__KEY__/g, key)
        .replace(/__SEC__/g, secEl.getAttribute('data-sec-key'));
    var tbody = secEl.querySelector('.mly-items');
    var tmp   = document.createElement('tbody');
    tmp.innerHTML = html;
    while (tmp.firstElementChild) tbody.appendChild(tmp.firstElementChild);
    var tr = tbody.querySelector('tr.mly-row[data-key="' + key + '"]');
    if (tr) {
        applyRowType(tr);
        if (focus !== false) { var l = tr.querySelector('.mly-label'); if (l) l.focus(); }
    }
    renumber();
    recalc();
    return tr;
}

function addSection() {
    var tpl = document.getElementById('mlySecTpl');
    if (!tpl) return;
    var key = nextKey('s');
    var tmp = document.createElement('div');
    tmp.innerHTML = tpl.innerHTML.replace(/__SEC__/g, key);
    var secEl = tmp.firstElementChild;
    document.getElementById('mlySections').appendChild(secEl);
    var t = secEl.querySelector('.mly-sec-title');
    if (t) { t.value = 'Yeni Bölüm'; t.focus(); t.select(); }
    addRow(secEl, false);
    renumber();
    recalc();
}

/* ---------- Sıra numaralarını DOM sırasına göre yaz ---------- */
function renumber() {
    var s = 0;
    document.querySelectorAll('#mlySections .mly-section').forEach(function (secEl) {
        s++;
        var ss = secEl.querySelector('.mly-sec-sort');
        if (ss) ss.value = s;
        var secKey = secEl.getAttribute('data-sec-key');
        var i = 0;
        secEl.querySelectorAll('tr.mly-row').forEach(function (tr) {
            i += 10;
            var so = tr.querySelector('.mly-row-sort');
            if (so) so.value = i;
            var sc = tr.querySelector('.mly-row-sec');
            if (sc) sc.value = secKey;
        });
    });
}

/* ---------- Satır taşıma ---------- */
function moveRow(tr, dir) {
    var det   = tr.parentNode.querySelector('tr.mly-row-detail[data-for="' + tr.getAttribute('data-key') + '"]');
    var tbody = tr.parentNode;
    if (dir < 0) {
        var prev = tr.previousElementSibling;
        while (prev && !prev.classList.contains('mly-row')) prev = prev.previousElementSibling;
        if (!prev) return;
        tbody.insertBefore(tr, prev);
        if (det) tbody.insertBefore(det, prev);
    } else {
        var next = det ? det.nextElementSibling : tr.nextElementSibling;
        while (next && !next.classList.contains('mly-row')) next = next.nextElementSibling;
        if (!next) return;
        var nextDet = tbody.querySelector('tr.mly-row-detail[data-for="' + next.getAttribute('data-key') + '"]');
        var anchor  = nextDet ? nextDet.nextSibling : next.nextSibling;
        tbody.insertBefore(tr, anchor);
        if (det) tbody.insertBefore(det, anchor);
    }
    renumber();
    recalc();
}

/* ---------- Parti No autocomplete (Adım 4) ----------
   JS burada SADECE api_maliyet_link.php'den veri getirip DOM'a yazar.
   Hiçbir tutar burada hesaplanmaz — yazılan qty/net_kg değerleri recalc()
   (bu dosyanın kendi canlı önizlemesi) ile normal satır gibi işlenir;
   kaydedilen tutarlar her zaman PHP cost_compute() tarafından belirlenir. */
var mlyPartiAbort      = null;
var mlyPartiDebounce   = null;
var mlyPartiItems      = [];
var mlyPartiActiveIdx  = -1;

function mlyEsc(s) {
    return String(s == null ? '' : s).replace(/[<>&]/g, function (c) {
        return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c];
    });
}

function mlyPartiFetch(url) {
    if (mlyPartiAbort) mlyPartiAbort.abort();
    mlyPartiAbort = ('AbortController' in window) ? new AbortController() : null;
    return fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        signal: mlyPartiAbort ? mlyPartiAbort.signal : undefined
    }).then(function (r) { return r.json(); });
}

function mlyPartiPositionPanel(input, panel) {
    var r = input.getBoundingClientRect();
    panel.style.left  = r.left + 'px';
    panel.style.top   = (r.bottom + 4) + 'px';
    panel.style.width = r.width + 'px';
}

function mlyPartiRenderPanel(panel, items, activeIdx) {
    if (!items.length) {
        panel.innerHTML = '<div class="mly-parti-empty">Kayıt bulunamadı</div>';
        panel.hidden = false;
        return;
    }
    panel.innerHTML = items.map(function (it, i) {
        var cls   = 'mly-parti-item' + (i === activeIdx ? ' is-active' : '');
        var line2 = [it.firma, it.plaka, it.tarih].filter(Boolean).join(' · ');
        return '<div class="' + cls + '" data-idx="' + i + '">' +
            '<strong>' + mlyEsc(it.parti_no || '(parti no yok)') + '</strong>' +
            '<small>' + mlyEsc(line2) + '</small></div>';
    }).join('');
    panel.hidden = false;
}

function mlySetByName(form, name, value) {
    var el = form.elements[name];
    if (el) el.value = (value === null || value === undefined) ? '' : value;
}

// .mly-code her satırın GİZLİ "detay" satırındadır (⋯ butonuyla açılır) —
// ana satırda değil. API'nin 'rows' anahtarı bu koddur (label değil).
function mlyFindRowByCode(code) {
    var codes = document.querySelectorAll('.mly-code');
    for (var i = 0; i < codes.length; i++) {
        if ((codes[i].value || '').trim() === code) {
            var det = codes[i].closest('tr.mly-row-detail');
            var key = det && det.getAttribute('data-for');
            if (key) return document.querySelector('tr.mly-row[data-key="' + key + '"]');
        }
    }
    return null;
}

// Yeni kalemler sunucudaki kuralla AYNI bölüme düşer: ilk include_in_total.
function mlyFirstIncludedSection() {
    var secs = document.querySelectorAll('#mlySections .mly-section');
    for (var i = 0; i < secs.length; i++) {
        var cb = secs[i].querySelector('.mly-sec-include');
        if (!cb || cb.checked) return secs[i];
    }
    return secs[0] || null;
}

function mlyApplyPartyDetail(form, data) {
    var h = data.header || {};
    mlySetByName(form, 'product', h.product);
    mlySetByName(form, 'firma', h.firma);
    mlySetByName(form, 'alici', h.alici);
    mlySetByName(form, 'brand', h.brand);
    mlySetByName(form, 'gumruk', h.gumruk);
    mlySetByName(form, 'plaka', h.plaka);
    mlySetByName(form, 'sheet_date', h.sheet_date);
    if (h.net_kg != null)  mlySetByName(form, 'net_kg',  fmtQty(Number(h.net_kg)));
    if (h.brut_kg != null) mlySetByName(form, 'brut_kg', fmt(Number(h.brut_kg), 3));

    var rows = data.rows || {};
    Object.keys(rows).forEach(function (code) {
        var tr = mlyFindRowByCode(code);
        if (!tr) return;
        var q = tr.querySelector('.mly-qty');
        // readonly miktar = formülle türeyen satır → miktarına dokunulmaz (formül yönetir),
        // ama adı yine gerçek malzemeye çekilir (sunucu tarafı yoluyla tutarlı kalsın).
        if (q && !q.hasAttribute('readonly')) q.value = fmtQty(Number(rows[code].qty));
        var lbl = tr.querySelector('.mly-label');
        if (lbl && rows[code].label) lbl.value = rows[code].label;
        tr.classList.add('mly-from-record');
    });

    (data.newRows || []).forEach(function (nr) {
        var secEl = mlyFirstIncludedSection();
        if (!secEl) return;
        var tr = addRow(secEl, false);
        if (!tr) return;
        var l = tr.querySelector('.mly-label'); if (l) l.value = nr.label || '';
        var u = tr.querySelector('.mly-unit');  if (u) u.value = nr.unit || '';
        var p = tr.querySelector('.mly-price'); if (p) p.value = fmt(Number(nr.unit_price || 0), 4);
        var q = tr.querySelector('.mly-qty');   if (q) q.value = fmtQty(Number(nr.qty || 0));
        var det = tr.parentNode.querySelector('tr.mly-row-detail[data-for="' + tr.getAttribute('data-key') + '"]');
        var c   = det && det.querySelector('.mly-code');
        if (c) c.value = nr.code || '';
        tr.classList.add('mly-from-record');
    });

    renumber();
    recalc();

    var status = document.getElementById('mlyPartiStatus');
    if (status) {
        var info = data.info || {};
        status.textContent = '✓ ' + (info.parti_no || '') + ' — ' + (info.matched || 0) + ' kalem eşleşti' +
            (info.unmatched ? ', ' + info.unmatched + ' yeni kalem eklendi' : '');
    }
}

// Genel bilgiler alanlarından yalnız yükleme planının doldurduğu alanlar —
// "temiz sayfa" sıfırlaması bunları kapsar (Kur/Navlun/Not gibi maliyetin
// KENDİ alanlarına dokunulmaz, onlar plan verisiyle zaten ilgisizdir).
var mlyHeaderFieldNames = ['product', 'firma', 'alici', 'brand', 'gumruk', 'plaka', 'sheet_date', 'net_kg', 'brut_kg'];
var mlyPristineHeader       = null;
var mlyPristineSectionsHTML = null;

// Sayfa İLK AÇILDIĞINDAKİ (henüz hiçbir parti uygulanmamış) hâlin
// anlık görüntüsü. record_id zaten doluysa (?record= derin linkiyle
// açılmış) gerçek bir "boş" taban yok demektir — o durumda sıfırlama
// atlanır (mlyResetToPristine no-op kalır), eski birleştirme davranışı sürer.
function mlySnapshotPristine(form) {
    // DİKKAT: value="0" boş sayfada bile dolu STRING'tir ve JS'te "0" truthy'dir
    // — düz bir truthy kontrolü burada YANLIŞ pozitif verir (parseInt şart).
    if (parseInt((form.elements.record_id || {}).value || '0', 10) > 0) return;
    mlyPristineHeader = {};
    mlyHeaderFieldNames.forEach(function (name) {
        var el = form.elements[name];
        mlyPristineHeader[name] = el ? el.value : '';
    });
    var secsEl = document.getElementById('mlySections');
    mlyPristineSectionsHTML = secsEl ? secsEl.innerHTML : '';
}

// Formu sayfa ilk açıldığındaki temiz hâline döndürür — kullanıcının o ana
// kadar elle girdiği/eklediği HER ŞEYİ (kalemler dahil) siler. Parti
// seçiminin "temiz sayfaya veri çeker" davranışının temelidir: aksi halde
// ikinci bir seçim, elle girilenlerle veya önceki partiden kalan
// satırlarla KARIŞIR/ÇOĞALIR (bkz. kullanıcı geri bildirimi).
function mlyResetToPristine(form) {
    if (mlyPristineSectionsHTML === null) return;
    mlyHeaderFieldNames.forEach(function (name) {
        var el = form.elements[name];
        if (el) el.value = mlyPristineHeader[name] || '';
    });
    var secsEl = document.getElementById('mlySections');
    if (secsEl) secsEl.innerHTML = mlyPristineSectionsHTML;
    document.querySelectorAll('tr.mly-row').forEach(applyRowType);
    renumber();
    recalc();
}

function mlyPartiHasFormData(form) {
    var netKg   = num((form.elements.net_kg || {}).value || '0');
    var firma   = ((form.elements.firma || {}).value || '').trim();
    var product = ((form.elements.product || {}).value || '').trim();
    var secsEl  = document.getElementById('mlySections');
    var itemsChanged = !!secsEl && mlyPristineSectionsHTML !== null && secsEl.innerHTML !== mlyPristineSectionsHTML;
    return netKg > 0 || firma !== '' || product !== '' || itemsChanged;
}

function initPartiSearch() {
    var wrap = document.getElementById('mlyPartiSearchWrap');
    if (!wrap) return;                                  // düzenleme modu: arama kutusu yok (madde 8)
    var form  = document.getElementById('mlyForm');
    var input = document.getElementById('mlyPartiInput');
    var panel = document.getElementById('mlyPartiPanel');
    var clearBtn     = document.getElementById('mlyPartiClear');
    var recordField  = form.elements.record_id;

    mlySnapshotPristine(form);

    function closePanel() { panel.hidden = true; mlyPartiActiveIdx = -1; }

    function selectItem(it) {
        if (mlyPartiHasFormData(form)) {
            if (!confirm('Formdaki mevcut kalemler ve bilgiler silinip seçilen partinin verileriyle değiştirilecek. Devam edilsin mi?')) return;
        }
        mlyResetToPristine(form);
        recordField.value = it.id;
        input.value = (it.parti_no || '') + (it.firma ? ' · ' + it.firma : '');
        clearBtn.hidden = false;
        closePanel();

        // template_id AÇIKÇA gönderilir — açık sayfanın DOM'da render ettiği
        // şablonla API'nin eşleştirdiği şablon AYNI olmalı (0/-1 = "Boş sayfa",
        // API bunu "hiçbir şablona eşleştirme yapma" sinyali olarak alır).
        mlyPartiFetch('api_maliyet_link.php?action=detail&record_id=' + encodeURIComponent(it.id) +
            '&template_id=' + encodeURIComponent(CFG.tplId || 0))
            .then(function (res) {
                if (res && res.ok) { mlyApplyPartyDetail(form, res); return; }
                var status = document.getElementById('mlyPartiStatus');
                if (status) status.textContent = '⚠ ' + ((res && res.error) || 'Veri alınamadı');
            })
            .catch(function (e) { /* AbortError beklenen durumdur — sessiz geç, form bozulmaz */ });
    }

    input.addEventListener('input', function () {
        // Elle yazınca önceki bağ geçersizleşir — yanlış kayda bağlı kalınmaz.
        if (recordField.value) { recordField.value = ''; clearBtn.hidden = true; }

        var q = input.value.trim();
        clearTimeout(mlyPartiDebounce);
        if (q.length < 2) { closePanel(); return; }
        mlyPartiDebounce = setTimeout(function () {
            mlyPartiFetch('api_maliyet_link.php?action=search&q=' + encodeURIComponent(q))
                .then(function (res) {
                    mlyPartiItems     = (res && res.ok) ? (res.items || []) : [];
                    mlyPartiActiveIdx = -1;
                    mlyPartiPositionPanel(input, panel);
                    mlyPartiRenderPanel(panel, mlyPartiItems, mlyPartiActiveIdx);
                })
                .catch(function (e) { closePanel(); });
        }, 250);
    });

    input.addEventListener('keydown', function (e) {
        if (panel.hidden || !mlyPartiItems.length) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            mlyPartiActiveIdx = Math.min(mlyPartiActiveIdx + 1, mlyPartiItems.length - 1);
            mlyPartiRenderPanel(panel, mlyPartiItems, mlyPartiActiveIdx);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            mlyPartiActiveIdx = Math.max(mlyPartiActiveIdx - 1, 0);
            mlyPartiRenderPanel(panel, mlyPartiItems, mlyPartiActiveIdx);
        } else if (e.key === 'Enter') {
            if (mlyPartiActiveIdx >= 0) { e.preventDefault(); selectItem(mlyPartiItems[mlyPartiActiveIdx]); }
        } else if (e.key === 'Escape') {
            closePanel();
        }
    });

    panel.addEventListener('click', function (e) {
        var row = e.target.closest && e.target.closest('.mly-parti-item');
        if (!row) return;
        var idx = parseInt(row.getAttribute('data-idx'), 10);
        if (mlyPartiItems[idx]) selectItem(mlyPartiItems[idx]);
    });

    clearBtn.addEventListener('click', function (e) {
        e.preventDefault();
        recordField.value = '';
        input.value = '';
        clearBtn.hidden = true;
        var status = document.getElementById('mlyPartiStatus');
        if (status) status.textContent = '';
        closePanel();
    });

    document.addEventListener('click', function (e) {
        if (e.target !== input && !panel.contains(e.target)) closePanel();
    });
    window.addEventListener('resize', function () { if (!panel.hidden) mlyPartiPositionPanel(input, panel); });
    window.addEventListener('scroll', function () { if (!panel.hidden) mlyPartiPositionPanel(input, panel); }, true);
}

/* ---------- Olay bağlama ---------- */
function init() {
    var form = document.getElementById('mlyForm');
    if (!form) return;

    // Ambalaj fiyat listesi → kalem adı seçilince fiyatı doldur
    var pkgByName = {};
    (CFG.pkgPrices || []).forEach(function (p) { pkgByName[p.urun.toLowerCase()] = p; });

    form.addEventListener('input', function (e) {
        var t = e.target;
        if (t.classList && t.classList.contains('mly-label')) {
            var hit = pkgByName[(t.value || '').toLowerCase()];
            var tr  = t.closest('tr.mly-row');
            if (hit && tr) {
                var pi = tr.querySelector('.mly-price');
                var ui = tr.querySelector('.mly-unit');
                if (pi && num(pi.value) === 0) pi.value = String(hit.fiyat).replace('.', ',');
                if (ui && !ui.value) ui.value = hit.birim || '';
            }
        }
        if (t.classList && t.classList.contains('mly-qtyf')) {
            var row = document.querySelector('tr.mly-row[data-key="' + t.closest('tr').getAttribute('data-for') + '"]');
            if (row) applyRowType(row);
        }
        recalc();
    });

    form.addEventListener('change', function (e) {
        var t = e.target;
        if (t.classList && t.classList.contains('mly-type')) {
            applyRowType(t.closest('tr.mly-row'));
        }
        if (t.classList && t.classList.contains('mly-basis-type')) {
            var opts = t.closest('.mly-sec-opts');
            if (opts) {
                var f = opts.querySelector('.mly-basis-fixed');
                var g = opts.querySelector('.mly-basis-formula');
                if (f) f.hidden = t.value !== 'fixed';
                if (g) g.hidden = t.value !== 'formula';
            }
        }
        recalc();
    });

    form.addEventListener('click', function (e) {
        var t = e.target;

        if (t.classList.contains('mly-add-item')) {
            e.preventDefault();
            addRow(t.closest('.mly-section'), true);
            return;
        }
        if (t.classList.contains('mly-row-del')) {
            e.preventDefault();
            var tr  = t.closest('tr.mly-row');
            var det = tr.parentNode.querySelector('tr.mly-row-detail[data-for="' + tr.getAttribute('data-key') + '"]');
            var lbl = (tr.querySelector('.mly-label') || {}).value;
            if (lbl && !confirm('“' + lbl + '” satırı silinsin mi?')) return;
            if (det) det.remove();
            tr.remove();
            renumber();
            recalc();
            return;
        }
        if (t.classList.contains('mly-row-more')) {
            e.preventDefault();
            var r = t.closest('tr.mly-row');
            var d = r.parentNode.querySelector('tr.mly-row-detail[data-for="' + r.getAttribute('data-key') + '"]');
            if (d) d.hidden = !d.hidden;
            return;
        }
        if (t.classList.contains('mly-up') || t.classList.contains('mly-down')) {
            e.preventDefault();
            moveRow(t.closest('tr.mly-row'), t.classList.contains('mly-up') ? -1 : 1);
            return;
        }
        if (t.classList.contains('mly-sec-toggle')) {
            e.preventDefault();
            var o = t.closest('.mly-section').querySelector('.mly-sec-opts');
            if (o) o.hidden = !o.hidden;
            return;
        }
        if (t.classList.contains('mly-sec-del')) {
            e.preventDefault();
            var secs = document.querySelectorAll('#mlySections .mly-section');
            if (secs.length <= 1) { alert('En az bir bölüm kalmalı.'); return; }
            var sEl = t.closest('.mly-section');
            var nm  = (sEl.querySelector('.mly-sec-title') || {}).value || 'Bölüm';
            if (!confirm('“' + nm + '” bölümü ve içindeki tüm kalemler silinsin mi?')) return;
            sEl.remove();
            renumber();
            recalc();
            return;
        }
    });

    var addSecBtn = document.getElementById('mlyAddSection');
    if (addSecBtn) addSecBtn.addEventListener('click', function (e) { e.preventDefault(); addSection(); });

    form.addEventListener('submit', function () { renumber(); });

    document.querySelectorAll('tr.mly-row').forEach(applyRowType);
    renumber();
    recalc();
    initPartiSearch();
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
else init();

})();
