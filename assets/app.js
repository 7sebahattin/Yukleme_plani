/* =========================================================
   Yükleme Planı - app.js (v2)
   - Palet satırı dinamik ekle/sil
   - Yeni palet, önceki palet doluysa onun değerlerini KOPYALAR
     (kasa adeti ve brüt kg hariç — onlar boş kalır)
   - Anlık dara, net, alt toplam (sadece en alttaki mavi alanda)
   - Zorunlu alanlar: brüt kg, kasa adeti, kasa cinsi, palet tipi
   - Genel/Nakliye bilgileri açılır/kapanır kart
   ========================================================= */
(function () {
    'use strict';

    const formEl = document.getElementById('recordForm');

    // ============== Açılır/kapanır kart bölümleri ==============
    document.querySelectorAll('.collapsible-card').forEach(card => {
        const head = card.querySelector('.card-head-toggle');
        if (!head) return;
        head.addEventListener('click', (e) => {
            if (e.target.closest('input, select, textarea, button')) return;
            card.classList.toggle('collapsed');
        });
    });

    if (!formEl) return;

    const MATERIALS = JSON.parse(document.getElementById('materialsData').textContent || '{}');
    const KASA_LIST = JSON.parse(document.getElementById('kasaCinsiData').textContent || '[]');
    const PALET_LIST = JSON.parse(document.getElementById('paletTipiData').textContent || '[]');
    const TYPE_LABELS = JSON.parse(document.getElementById('materialTypesData').textContent || '{}');
    let palletsInit = JSON.parse(document.getElementById('palletsInit').textContent || '[]');

    const matsByType = {};
    Object.keys(MATERIALS).forEach(id => {
        const m = MATERIALS[id];
        if (!matsByType[m.type]) matsByType[m.type] = [];
        matsByType[m.type].push({ id: parseInt(id, 10), name: m.name, unit: m.unit });
    });

    const list = document.getElementById('palletList');
    let rowCounter = 0;

    function fmtKg(n) {
        if (!isFinite(n)) n = 0;
        let s = n.toLocaleString('tr-TR', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
        s = s.replace(/,(\d+)$/, (_, dec) => {
            const t = dec.replace(/0+$/, '');
            return t ? ',' + t : '';
        });
        return s;
    }
    function roundHalf(n) {
        return Math.round(n * 2) / 2;
    }
    function parseNum(v) {
        if (v === null || v === undefined || v === '') return 0;
        if (typeof v === 'number') return isFinite(v) ? v : 0;
        const s = String(v).replace(/\s/g, '').replace(',', '.');
        const n = parseFloat(s);
        return isFinite(n) ? n : 0;
    }
    function parseInt2(v) {
        const n = parseInt(String(v || '0').replace(/[^\d-]/g, ''), 10);
        return isFinite(n) ? n : 0;
    }
    function escAttr(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
            .replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function buildOptions(items, selectedId, placeholder) {
        let html = `<option value="">${placeholder || '-- seçiniz --'}</option>`;
        items.forEach(it => {
            const sel = String(it.id) === String(selectedId) ? ' selected' : '';
            html += `<option value="${it.id}" data-unit="${it.unit}"${sel}>${escAttr(it.name)} (${fmtKg(it.unit)} kg)</option>`;
        });
        return html;
    }

    // Önceki paletten kopyalanacak veriyi çıkart
    function getPrefillFromLastRow() {
        const rows = list.querySelectorAll('.pallet-row');
        if (!rows.length) return null;
        const last = rows[rows.length - 1];
        const ka = parseInt2(last.querySelector('.kasa-adeti').value);
        const br = parseNum(last.querySelector('.brut-kg').value);
        const kc = last.querySelector('.kasa-cinsi').value;
        const pt = last.querySelector('.palet-tipi').value;
        if (!ka && !br && !kc && !pt) return null;

        const materials = [];
        last.querySelectorAll('.material-row').forEach(mr => {
            const mid = mr.querySelector('.mat-select').value;
            const qty = mr.querySelector('.mat-qty').value;
            if (mid) materials.push({ material_id: mid, quantity: qty });
        });

        return {
            palet_no: '',
            kasa_adeti: '',
            size: last.querySelector('input[name*="[size]"]').value,
            brut_kg: '',
            kasa_cinsi_id: kc,
            palet_tipi_id: pt,
            urun_cinsi: last.querySelector('input[name*="[urun_cinsi]"]').value,
            depo: last.querySelector('input[name*="[depo]"]').value,
            materials: materials,
        };
    }

    function createPalletRow(data) {
        rowCounter++;
        const idx = rowCounter;
        const d = data || {};

        const wrap = document.createElement('div');
        wrap.className = 'pallet-row';
        wrap.dataset.idx = idx;

        const paletNoVal = (d.palet_no !== '' && d.palet_no != null) ? d.palet_no : idx;

        wrap.innerHTML = `
            <div class="pallet-row-head">
                <span class="pallet-num">Palet #<span class="row-no">${idx}</span></span>
                <button type="button" class="btn btn-sm btn-danger btn-icon row-remove" title="Sil">×</button>
            </div>
            <div class="pallet-fields">
                <label><span class="lbl-text">Palet No</span>
                    <input type="text" name="pallets[${idx}][palet_no]" value="${escAttr(paletNoVal)}" placeholder="Palet No" data-tab>
                </label>
                <label><span class="lbl-text">Kasa Adeti *</span>
                    <input type="text" inputmode="numeric" name="pallets[${idx}][kasa_adeti]" value="${escAttr(d.kasa_adeti || '')}" placeholder="Kasa adet" class="num kasa-adeti req" data-tab required>
                </label>
                <label><span class="lbl-text">Size</span>
                    <input type="text" name="pallets[${idx}][size]" value="${escAttr(d.size || '')}" placeholder="Size" data-tab>
                </label>
                <label><span class="lbl-text">Brüt KG *</span>
                    <input type="text" inputmode="decimal" name="pallets[${idx}][brut_kg]" value="${escAttr(d.brut_kg || '')}" placeholder="Brüt kg" class="num brut-kg req" data-tab required>
                </label>
                <label><span class="lbl-text">Kasa Cinsi *</span>
                    <select name="pallets[${idx}][kasa_cinsi_id]" class="kasa-cinsi req" data-tab required>
                        ${buildOptions(KASA_LIST, d.kasa_cinsi_id, '-- kasa cinsi --')}
                    </select>
                </label>
                <label><span class="lbl-text">Palet Tipi *</span>
                    <select name="pallets[${idx}][palet_tipi_id]" class="palet-tipi req" data-tab required>
                        ${buildOptions(PALET_LIST, d.palet_tipi_id, '-- palet tipi --')}
                    </select>
                </label>
                <label><span class="lbl-text">Ürün Cinsi</span>
                    <input type="text" name="pallets[${idx}][urun_cinsi]" value="${escAttr(d.urun_cinsi || '')}" placeholder="Ürün cinsi" data-tab>
                </label>
                <label><span class="lbl-text">Depo</span>
                    <input type="text" name="pallets[${idx}][depo]" value="${escAttr(d.depo || '')}" placeholder="Depo" data-tab>
                </label>
                <div class="pf-dara" data-role="dara" title="Dara KG">0,000</div>
                <div class="pf-net" data-role="net" title="Net KG">0,000</div>
                <div class="pf-action">
                    <button type="button" class="btn btn-sm btn-ghost btn-icon row-remove pc-only-inline" title="Sil">×</button>
                </div>
            </div>

            <div class="materials-block">
                <div class="materials-block-head">
                    <span>Ek malzemeler / dara kalemleri</span>
                    <button type="button" class="btn btn-sm add-material">+ Malzeme</button>
                </div>
                <div class="materials-list"></div>
            </div>
        `;

        list.appendChild(wrap);

        if (Array.isArray(d.materials)) {
            d.materials.forEach(m => addMaterial(wrap, m));
        }

        bindRow(wrap);
        recompute(wrap);
        return wrap;
    }

    function addMaterial(rowEl, data) {
        const idx = rowEl.dataset.idx;
        const d = data || {};
        const list = rowEl.querySelector('.materials-list');
        const m = document.createElement('div');
        m.className = 'material-row';

        let opts = '<option value="">-- malzeme seçiniz --</option>';
        Object.keys(matsByType).sort().forEach(t => {
            if (t === 'kasa_cinsi' || t === 'palet_tipi') return;
            const label = TYPE_LABELS[t] || t;
            opts += `<optgroup label="${escAttr(label)}">`;
            matsByType[t].forEach(it => {
                const sel = String(it.id) === String(d.material_id) ? ' selected' : '';
                opts += `<option value="${it.id}" data-unit="${it.unit}"${sel}>${escAttr(it.name)} (${fmtKg(it.unit)} kg)</option>`;
            });
            opts += '</optgroup>';
        });

        m.innerHTML = `
            <select name="pallets[${idx}][materials][][material_id]" class="mat-select" data-tab>${opts}</select>
            <input type="text" inputmode="decimal" class="num mat-qty" data-tab
                   name="pallets[${idx}][materials][][quantity]"
                   value="${escAttr(d.quantity || 1)}" placeholder="Adet">
            <button type="button" class="btn btn-sm btn-ghost btn-icon mat-remove" title="Sil">×</button>
        `;
        list.appendChild(m);

        m.querySelector('.mat-select').addEventListener('change', () => recompute(rowEl));
        m.querySelector('.mat-qty').addEventListener('input', () => recompute(rowEl));
        m.querySelector('.mat-remove').addEventListener('click', () => {
            m.remove();
            recompute(rowEl);
        });
    }

    function bindRow(rowEl) {
        rowEl.querySelectorAll('input, select').forEach(el => {
            el.addEventListener('input', () => {
                el.classList.remove('error');
                recompute(rowEl);
            });
            el.addEventListener('change', () => {
                el.classList.remove('error');
                recompute(rowEl);
            });
        });
        rowEl.querySelectorAll('.row-remove').forEach(btn => {
            btn.addEventListener('click', () => {
                if (list.children.length === 1) {
                    if (!confirm('Son palet satırı silinecek. Emin misiniz?')) return;
                }
                rowEl.remove();
                renumber();
                recomputeAll();
            });
        });
        rowEl.querySelector('.add-material').addEventListener('click', () => addMaterial(rowEl));
    }

    function renumber() {
        let i = 0;
        list.querySelectorAll('.pallet-row').forEach(r => {
            i++;
            r.querySelector('.row-no').textContent = i;
        });
    }

    function rowDara(r) {
        const kSel = r.querySelector('.kasa-cinsi');
        const pSel = r.querySelector('.palet-tipi');
        const kasaUnit  = parseNum(kSel.options[kSel.selectedIndex]?.dataset.unit || 0);
        const paletUnit = parseNum(pSel.options[pSel.selectedIndex]?.dataset.unit || 0);
        const ka = parseInt2(r.querySelector('.kasa-adeti').value);
        let extra = 0;
        r.querySelectorAll('.material-row').forEach(mr => {
            const sel = mr.querySelector('.mat-select');
            const opt = sel.options[sel.selectedIndex];
            const unit = parseNum(opt?.dataset.unit || 0);
            const qty  = parseNum(mr.querySelector('.mat-qty').value);
            extra += (unit * qty) || 0;
        });
        return roundHalf(ka * kasaUnit + paletUnit + extra);
    }

    function recompute(rowEl) {
        const brut = parseNum(rowEl.querySelector('.brut-kg').value);
        const dara = rowDara(rowEl);
        const net  = Math.max(0, brut - dara);
        rowEl.querySelector('[data-role="dara"]').textContent = fmtKg(dara);
        rowEl.querySelector('[data-role="net"]').textContent  = fmtKg(net);
        recomputeTotals();
    }
    function recomputeAll() {
        list.querySelectorAll('.pallet-row').forEach(r => recompute(r));
    }
    function recomputeTotals() {
        let totKasa = 0, totBrut = 0, totDara = 0, totNet = 0;
        list.querySelectorAll('.pallet-row').forEach(r => {
            const ka = parseInt2(r.querySelector('.kasa-adeti').value);
            const br = parseNum(r.querySelector('.brut-kg').value);
            const dara = rowDara(r);
            const net  = Math.max(0, br - dara);
            totKasa += ka;
            totBrut += br;
            totDara += dara;
            totNet  += net;
        });
        document.getElementById('totKasa').textContent = String(totKasa);
        document.getElementById('totBrut').textContent = fmtKg(totBrut);
        document.getElementById('totDara').textContent = fmtKg(totDara);
        document.getElementById('totNet').textContent  = fmtKg(totNet);
    }

    function addNewPallet() {
        const prefill = getPrefillFromLastRow() || {};
        prefill.palet_no = rowCounter + 1;
        // Ürün cinsi ve depo her zaman Genel Bilgilerden gelir
        const urunEl = document.getElementById('genelUrun');
        const depoEl = document.getElementById('genelDepo');
        if (urunEl) prefill.urun_cinsi = urunEl.value;
        if (depoEl) prefill.depo = depoEl.value;
        const r = createPalletRow(prefill);
        const focusTarget = r.querySelector('.kasa-adeti');
        focusTarget.focus();
        r.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    document.getElementById('addPalletBtn').addEventListener('click', addNewPallet);
    const bottomBtn = document.getElementById('addPalletBtnBottom');
    if (bottomBtn) bottomBtn.addEventListener('click', addNewPallet);

    formEl.addEventListener('keydown', e => {
        if (e.key !== 'Enter') return;
        const t = e.target;
        if (!t.matches('input[data-tab], select[data-tab]')) return;
        if (t.tagName === 'TEXTAREA') return;
        e.preventDefault();
        const fields = Array.from(formEl.querySelectorAll('input[data-tab], select[data-tab]'));
        const i = fields.indexOf(t);
        if (i >= 0 && i < fields.length - 1) fields[i + 1].focus();
    });

    formEl.addEventListener('submit', e => {
        let firstError = null;
        let errorRowIdx = null;
        let hasAny = false;

        list.querySelectorAll('.pallet-row').forEach((r, idx) => {
            const ka = parseInt2(r.querySelector('.kasa-adeti').value);
            const br = parseNum(r.querySelector('.brut-kg').value);
            const kc = r.querySelector('.kasa-cinsi').value;
            const pt = r.querySelector('.palet-tipi').value;

            const isEmpty = !ka && !br && !kc && !pt;
            if (isEmpty) return;

            hasAny = true;
            r.querySelectorAll('.req').forEach(el => el.classList.remove('error'));

            const adetEl = r.querySelector('.kasa-adeti');
            const brutEl = r.querySelector('.brut-kg');
            const kcEl   = r.querySelector('.kasa-cinsi');
            const ptEl   = r.querySelector('.palet-tipi');

            if (!ka) { adetEl.classList.add('error'); firstError = firstError || adetEl; if (errorRowIdx == null) errorRowIdx = idx + 1; }
            if (!br) { brutEl.classList.add('error'); firstError = firstError || brutEl; if (errorRowIdx == null) errorRowIdx = idx + 1; }
            if (!kc) { kcEl.classList.add('error');   firstError = firstError || kcEl;   if (errorRowIdx == null) errorRowIdx = idx + 1; }
            if (!pt) { ptEl.classList.add('error');   firstError = firstError || ptEl;   if (errorRowIdx == null) errorRowIdx = idx + 1; }
        });

        if (firstError) {
            e.preventDefault();
            alert(`Palet #${errorRowIdx}: Kasa adeti, Brüt KG, Kasa Cinsi ve Palet Tipi zorunludur.`);
            firstError.focus();
            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        if (!hasAny) {
            if (!confirm('Hiç palet satırı doldurulmadı. Yine de kaydedilsin mi?')) {
                e.preventDefault();
            }
        }
    });

    if (palletsInit && palletsInit.length) {
        palletsInit.forEach(p => {
            if (Array.isArray(p.materials)) {
                p.materials = p.materials.map(m => ({
                    material_id: m.material_id,
                    quantity: m.quantity,
                }));
            }
            createPalletRow(p);
        });
    } else {
        createPalletRow();
    }
})();
