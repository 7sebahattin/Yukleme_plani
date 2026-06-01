/* =========================================================
   Yükleme Planı - app.js (v3)
   Modal-tabanlı palet ekleme/düzenleme
   ========================================================= */
(function () {
    'use strict';

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
        const btn = e.target.closest('.pc-kebab');
        if (btn) {
            const dd = btn.nextElementSibling;
            const wasOpen = !dd.hidden;
            closeAllDropdowns();
            if (!wasOpen) {
                // .table-wrap has overflow-x:auto which clips absolute children → use fixed
                if (btn.closest('.table-wrap')) {
                    const r = btn.getBoundingClientRect();
                    dd.style.position = 'fixed';
                    dd.style.top = (r.bottom + 4) + 'px';
                    dd.style.right = (window.innerWidth - r.right) + 'px';
                    dd.style.left = 'auto';
                }
                dd.hidden = false;
            }
        } else {
            closeAllDropdowns();
        }
    });
    // Close on scroll (fixed dropdowns stay in place otherwise)
    window.addEventListener('scroll', closeAllDropdowns, true);

    const formEl = document.getElementById('recordForm');
    if (!formEl) return;

    /* ── Statik veriler ── */
    const MATERIALS  = JSON.parse(document.getElementById('materialsData').textContent  || '{}');
    const KASA_LIST  = JSON.parse(document.getElementById('kasaCinsiData').textContent  || '[]');
    const PALET_LIST = JSON.parse(document.getElementById('paletTipiData').textContent  || '[]');
    const TYPE_LABELS = JSON.parse(document.getElementById('materialTypesData').textContent || '{}');
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
    function roundHalf(n) { return Math.round(n); }
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
            if (['kasa_cinsi','palet_tipi','firma','depo','urun'].includes(t)) return;
            const label = TYPE_LABELS[t] || t;
            opts += `<optgroup label="${escHtml(label)}">`;
            matsByType[t].forEach(it => {
                const sel = String(it.id) === String(d.material_id) ? ' selected' : '';
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
                    <button type="button" class="pc-isle-btn${_done ? ' active' : ''}" data-isle="${i}" title="${_done ? 'İşlendi — geri al' : 'İşlendi işaretle'}">✓</button>
                </div>
                <div class="pc-body">
                    <div class="pc-title">Palet ${escHtml(p.palet_no || (i + 1))}${p.size ? ' · ' + escHtml(p.size) : ''}</div>
                    <div class="pc-stats">
                        <span><strong${kasaWarn ? ' class="pc-warn"' : ''}>${p.kasa_adeti}</strong> kasa</span>
                        <span>Brüt <strong${brutWarn ? ' class="pc-warn"' : ''}>${fmtKg(Math.round(br))}</strong></span>
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
            return tr;
        }

        /* Panel aç: mevcut paletleri yükle */
        acBtn.addEventListener('click', () => {
            if (panel.style.display !== 'none') { panel.style.display = 'none'; return; }
            const ep = document.getElementById('excelPanel');
            if (ep) ep.style.display = 'none';

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

                if (ps <= 1) {
                    origBase.palet_no      = String(pallets.length + 1);
                    origBase.kasa_adeti    = totalKasa;
                    origBase.brut_kg       = totalBrut;
                    origBase.kasa_cinsi_id = kasa_cinsi_id;
                    origBase.palet_tipi_id = palet_tipi_id;
                    origBase.depo          = depo;
                    origBase.urun_cinsi    = urun_cinsi;
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
                            size:          '',
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
            // diğer paneli kapat
            const tp = document.getElementById('topluPanel');
            if (tp) tp.style.display = 'none';
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

/* ════════════════════════════════════════════════════════
   Ortak Paylaş Helper (tüm sayfalarda çalışır)
   <button class="share-btn" data-share-title=".." data-share-text=".."
           data-share-url="record_view.php?id=123">Paylaş</button>
   1) navigator.share  2) WhatsApp wa.me  3) panoya kopyala
   ════════════════════════════════════════════════════════ */
(function () {
    function absUrl(u) {
        if (!u) return window.location.href;
        if (/^https?:\/\//i.test(u)) return u;
        // mevcut dizine göre çöz (alt klasör güvenli)
        return new URL(u, window.location.href).href;
    }

    function toast(msg, sticky) {
        var t = document.createElement('div');
        t.className = 'share-toast';
        t.textContent = msg;
        document.body.appendChild(t);
        requestAnimationFrame(function () { t.classList.add('show'); });
        if (!sticky) {
            setTimeout(function () {
                t.classList.remove('show');
                setTimeout(function () { t.remove(); }, 300);
            }, 2000);
        }
        return t;  // sticky ise çağıran kapatır
    }
    function hideToast(t) {
        if (!t) return;
        t.classList.remove('show');
        setTimeout(function () { t.remove(); }, 300);
    }

    window.shareContent = function (opts) {
        opts = opts || {};
        var title = opts.title || 'Asya Fresh';
        var text  = opts.text  || '';
        var url   = absUrl(opts.url);
        var full  = (text ? text + '\n' : '') + url;

        if (navigator.share) {
            navigator.share({ title: title, text: text, url: url }).catch(function () {});
            return;
        }
        // WhatsApp fallback (yeni sekme)
        var wa = 'https://wa.me/?text=' + encodeURIComponent(full);
        var win = window.open(wa, '_blank');
        if (win) return;
        // Pop-up engellendi → panoya kopyala
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(full).then(function () {
                toast('Bağlantı kopyalandı');
            }).catch(function () { prompt('Bağlantıyı kopyalayın:', full); });
        } else {
            prompt('Bağlantıyı kopyalayın:', full);
        }
    };

    /* ── Görsellerin yüklenmesini bekle (etiket/fotoğraf) ── */
    function waitImages(el, timeoutMs) {
        var imgs = Array.prototype.slice.call(el.querySelectorAll('img'));
        var pending = imgs.filter(function (im) { return im.src && !im.complete; });
        if (!pending.length) return Promise.resolve();
        return new Promise(function (resolve) {
            var done = 0, finished = false;
            function tick() { if (!finished && ++done >= pending.length) { finished = true; resolve(); } }
            pending.forEach(function (im) {
                im.addEventListener('load',  tick, { once: true });
                im.addEventListener('error', tick, { once: true });
            });
            setTimeout(function () { if (!finished) { finished = true; resolve(); } }, timeoutMs || 4000);
        });
    }

    /* ── Element → PNG → paylaş (Web Share files) / indir / yeni sekme ── */
    window.shareElementAsImage = function (el, filename, title, text) {
        if (!el) return;
        if (typeof html2canvas === 'undefined') {
            toast('Görsel kütüphanesi yüklenemedi');
            if (window.shareContent) shareContent({ title: title, text: text, url: location.href });
            return;
        }
        var loading = toast('Görüntü hazırlanıyor…', true);
        document.body.classList.add('capturing');  // .no-capture öğeleri gizle

        waitImages(el, 4000).then(function () {
            return html2canvas(el, {
                backgroundColor: '#ffffff',
                scale: Math.min(2, window.devicePixelRatio || 1.5),
                useCORS: true, logging: false,
                windowWidth: el.scrollWidth, windowHeight: el.scrollHeight
            });
        }).then(function (canvas) {
            return new Promise(function (resolve) { canvas.toBlob(resolve, 'image/png'); });
        }).then(function (blob) {
            document.body.classList.remove('capturing');
            hideToast(loading);
            if (!blob) { toast('Görüntü oluşturulamadı'); return; }
            var fname = (filename || 'belge') + '.png';
            var file  = new File([blob], fname, { type: 'image/png' });

            // 1) Web Share files
            if (navigator.canShare && navigator.canShare({ files: [file] }) && navigator.share) {
                navigator.share({ files: [file], title: title || 'Asya Fresh', text: text || '' })
                    .catch(function () {});
                return;
            }
            // 2) İndir
            var url = URL.createObjectURL(blob);
            try {
                var a = document.createElement('a');
                a.href = url; a.download = fname;
                document.body.appendChild(a); a.click(); a.remove();
                toast('Görüntü indirildi');
                setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
            } catch (e) {
                // 3) Yeni sekmede aç
                window.open(url, '_blank');
            }
        }).catch(function (err) {
            document.body.classList.remove('capturing');
            hideToast(loading);
            toast('Görüntü hazırlanamadı');
            if (console && console.warn) console.warn('shareElementAsImage:', err);
        });
    };

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.share-btn, .card-share-btn, .kantar-share-btn');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        shareContent({
            title: btn.dataset.shareTitle || 'Asya Fresh',
            text:  btn.dataset.shareText  || '',
            url:   btn.dataset.shareUrl   || ''
        });
    });

    /* ── Detay sayfasındaki "Görüntü Olarak Paylaş" butonu ── */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.shot-share-btn');
        if (!btn) return;
        e.preventDefault();
        var target = document.querySelector(btn.dataset.shotTarget || '');
        if (!target) { toast('Paylaşılacak alan bulunamadı'); return; }
        shareElementAsImage(target, btn.dataset.shotFilename || 'belge',
                            btn.dataset.shareTitle || 'Asya Fresh',
                            btn.dataset.shareText || '');
    });

    /* Liste sayfasından share=1 ile gelindiyse otomatik görüntü paylaşımı tetikle */
    (function autoShot() {
        try {
            var p = new URLSearchParams(window.location.search);
            if (p.get('share') !== '1') return;
            var btn = document.querySelector('.shot-share-btn');
            if (btn) setTimeout(function () { btn.click(); }, 700);
        } catch (e) {}
    })();
})();
