// =========================================================
// assets/kalan.js — Kalan Palet Hesaplama Modal
// =========================================================
(function () {
    'use strict';

    const RECORD_ID = window.KALAN_RECORD_ID || 0;
    const $ = id => document.getElementById(id);

    let crateTypes = [];   // {crate_count, avg_kg, source_type}
    let currentData = {};  // status response cache

    // ---- Format helpers ----
    function fmtN(v, decimals = 3) {
        const n = parseFloat(String(v).replace(',', '.')) || 0;
        let s = n.toLocaleString('tr-TR', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
        if (decimals > 0) {
            s = s.replace(/,(\d+)$/, (_, d) => { const t = d.replace(/0+$/, ''); return t ? ',' + t : ''; });
        }
        return s;
    }
    function fmtKg(v)      { return fmtN(v, 3); }
    function fmtKgSign(v)  { const n = parseFloat(String(v).replace(',', '.')) || 0; return (n >= 0 ? '+' : '') + fmtKg(n); }

    // ---- API helper ----
    async function api(action, params = {}, method = 'GET') {
        const isPost = method === 'POST';
        let url = `api_kalan.php?action=${encodeURIComponent(action)}`;
        const opts = { method };
        if (isPost) {
            const fd = new FormData();
            Object.entries(params).forEach(([k, v]) => fd.append(k, v));
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            if (csrfMeta) fd.append('csrf', csrfMeta.content);
            opts.body = fd;
        } else {
            Object.entries(params).forEach(([k, v]) => { url += `&${k}=${encodeURIComponent(v)}`; });
        }
        const r = await fetch(url, opts);
        return r.json();
    }

    // ---- Modal open/close ----
    const modal  = $('kalanModal');
    const openBtn = $('kalanOpenBtn');

    openBtn?.addEventListener('click', openModal);
    modal?.querySelector('.kalan-close')?.addEventListener('click', closeModal);
    modal?.addEventListener('click', e => { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal?.hidden) closeModal(); });

    async function openModal() {
        if (!modal) return;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        await loadAll();
    }
    function closeModal() {
        if (!modal) return;
        modal.hidden = true;
        document.body.style.overflow = '';
    }

    async function loadAll() {
        await Promise.all([loadStatus(), loadCrateSettings()]);
    }

    // ---- 1 & 2: Hedef + Mevcut Durum ----
    async function loadStatus() {
        const d = await api('status', { record_id: RECORD_ID });
        if (!d.ok) return;
        currentData = d;
        if (d.target_kg)           $('kalanTargetKg').value = d.target_kg;
        if (d.target_pallet_count) $('kalanTargetPc').value = d.target_pallet_count;
        renderStatus();
    }

    function renderStatus() {
        const d   = currentData;
        const tKg = parseFloat($('kalanTargetKg')?.value) || 0;
        const tPc = parseInt($('kalanTargetPc')?.value)   || 0;
        const remKg = tKg > 0 ? tKg - (d.total_kg || 0) : null;
        const remPc = tPc > 0 ? tPc - (d.pallet_count || 0) : null;
        const avgPer = (remPc > 0 && remKg !== null) ? (remKg / remPc) : null;

        const el = $('kalanStatus');
        if (!el) return;
        el.innerHTML = `
            <div class="kalan-stat"><span>Girilen Palet</span><strong>${d.pallet_count ?? '—'}</strong></div>
            <div class="kalan-stat"><span>Girilen Toplam</span><strong>${fmtKg(d.total_kg ?? 0)} kg</strong></div>
            <div class="kalan-stat"><span>Kalan Palet</span><strong>${remPc !== null ? remPc : '—'}</strong></div>
            <div class="kalan-stat"><span>Kalan Kilo</span><strong>${remKg !== null ? fmtKg(remKg) + ' kg' : '—'}</strong></div>
            <div class="kalan-stat kalan-stat-wide"><span>Kalan Palet Başı Ortalama</span><strong>${avgPer !== null ? fmtKg(avgPer) + ' kg' : '—'}</strong></div>
        `;
    }

    $('kalanTargetKg')?.addEventListener('input', renderStatus);
    $('kalanTargetPc')?.addEventListener('input', renderStatus);

    $('kalanSaveTarget')?.addEventListener('click', async () => {
        const btn = $('kalanSaveTarget');
        const d = await api('save_target', {
            record_id: RECORD_ID,
            target_kg: $('kalanTargetKg').value,
            target_pallet_count: $('kalanTargetPc').value,
        }, 'POST');
        if (d.ok) {
            btn.textContent = '✓ Kaydedildi';
            setTimeout(() => { btn.textContent = 'Kaydet'; }, 2000);
        }
    });

    // ---- 3: Kasa Tipi Ortalamaları ----
    async function loadCrateSettings() {
        const d = await api('crate_settings');
        if (!d.ok) return;
        crateTypes = d.items.map(i => ({
            crate_count: +i.crate_count,
            avg_kg: +i.avg_kg,
            source_type: i.source_type,
        }));
        renderCrateTable();
    }

    function renderCrateTable() {
        const tbody = $('kalanCrateRows');
        if (!tbody) return;
        tbody.innerHTML = crateTypes.length ? crateTypes.map(c => `
            <tr data-cc="${c.crate_count}">
                <td class="crate-count-cell">${c.crate_count}</td>
                <td><input class="crate-avg-input kalan-input" type="number" value="${c.avg_kg}" step="0.1" min="1" data-cc="${c.crate_count}"></td>
                <td><span class="kalan-src ${c.source_type === 'auto' ? 'kalan-src-auto' : 'kalan-src-manual'}">${c.source_type === 'auto' ? 'Otomatik' : 'Manuel'}</span></td>
                <td class="crate-actions-cell">
                    <button class="kalan-icon-btn crate-auto-btn" data-cc="${c.crate_count}" title="Veritabanından ortalama al">⟳</button>
                    <button class="kalan-icon-btn crate-del-btn" data-cc="${c.crate_count}" title="Sil">✕</button>
                </td>
            </tr>
        `).join('') : '<tr><td colspan="4" class="muted center" style="padding:12px">Kasa tipi tanımlanmamış</td></tr>';

        tbody.querySelectorAll('.crate-avg-input').forEach(inp => {
            inp.addEventListener('change', async () => {
                const cc  = +inp.dataset.cc;
                const akg = parseFloat(inp.value);
                if (!akg) return;
                await api('save_crate', { crate_count: cc, avg_kg: akg, source_type: 'manual' }, 'POST');
                const t = crateTypes.find(c => c.crate_count === cc);
                if (t) { t.avg_kg = akg; t.source_type = 'manual'; }
                renderCrateTable();
            });
        });

        tbody.querySelectorAll('.crate-auto-btn').forEach(btn => {
            btn.addEventListener('click', () => autoCalcCrate(+btn.dataset.cc));
        });

        tbody.querySelectorAll('.crate-del-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!confirm(`${btn.dataset.cc} kasa tipini silmek istediğinizden emin misiniz?`)) return;
                await api('delete_crate', { crate_count: +btn.dataset.cc }, 'POST');
                crateTypes = crateTypes.filter(c => c.crate_count !== +btn.dataset.cc);
                renderCrateTable();
            });
        });
    }

    async function autoCalcCrate(cc) {
        const d = await api('auto_avg', { crate_count: cc, record_id: RECORD_ID });
        if (!d.ok) return;
        if (!d.found) {
            alert(`${cc} kasa adedi için bu kayıtta palet bulunamadı.\nLütfen ortalama kg'ı manuel girin.`);
            return;
        }
        await api('save_crate', { crate_count: cc, avg_kg: d.avg_kg, source_type: 'auto' }, 'POST');
        const t = crateTypes.find(c => c.crate_count === cc);
        if (t) { t.avg_kg = d.avg_kg; t.source_type = 'auto'; }
        else crateTypes.push({ crate_count: cc, avg_kg: d.avg_kg, source_type: 'auto' });
        renderCrateTable();
    }

    $('kalanAutoAll')?.addEventListener('click', async () => {
        for (const c of crateTypes) await autoCalcCrate(c.crate_count);
    });

    // Kasa ekle formu
    $('kalanAddCrate')?.addEventListener('click', () => {
        $('kalanCrateAdd').hidden = false;
        $('kalanNewCount').focus();
    });
    $('kalanCancelAdd')?.addEventListener('click', () => {
        $('kalanCrateAdd').hidden = true;
        $('kalanNewCount').value = '';
        $('kalanNewAvg').value   = '';
    });
    $('kalanConfirmAdd')?.addEventListener('click', async () => {
        const cc  = parseInt($('kalanNewCount').value);
        const avg = parseFloat($('kalanNewAvg').value);
        if (!cc || !avg) { alert('Kasa adedi ve ortalama kg değerini girin'); return; }

        await api('save_crate', { crate_count: cc, avg_kg: avg, source_type: 'manual' }, 'POST');

        const existing = crateTypes.find(c => c.crate_count === cc);
        if (existing) { existing.avg_kg = avg; existing.source_type = 'manual'; }
        else { crateTypes.push({ crate_count: cc, avg_kg: avg, source_type: 'manual' }); }
        crateTypes.sort((a, b) => a.crate_count - b.crate_count);

        $('kalanCrateAdd').hidden = true;
        $('kalanNewCount').value  = '';
        $('kalanNewAvg').value    = '';
        renderCrateTable();
    });

    // ---- 4: Hesapla ----
    $('kalanCalcBtn')?.addEventListener('click', async () => {
        const tKg = parseFloat($('kalanTargetKg').value);
        const tPc = parseInt($('kalanTargetPc').value);
        if (!tKg || !tPc)          { alert('Hedef kilo ve palet sayısını girin'); return; }
        if (!crateTypes.length)    { alert('En az 1 kasa tipi tanımlayın'); return; }

        await api('save_target', { record_id: RECORD_ID, target_kg: tKg, target_pallet_count: tPc }, 'POST');

        const btn = $('kalanCalcBtn');
        btn.disabled = true;
        btn.textContent = 'Hesaplanıyor…';

        const d = await api('calculate', {
            record_id: RECORD_ID,
            target_kg: tKg,
            target_pallet_count: tPc,
            crate_types: JSON.stringify(crateTypes),
        }, 'POST');

        btn.disabled = false;
        btn.textContent = 'Hesapla';

        if (!d.ok) { alert('Hata: ' + d.error); return; }

        currentData = { ...currentData, ...d };
        renderResults(d);
        $('kalanResults').hidden = false;
        setTimeout(() => $('kalanResults').scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
    });

    // ---- 5: Sonuçlar ----
    function formatCombo(parts) {
        return parts.map(p => `<span class="combo-part"><strong>${p.count}</strong> × ${p.crate_count}'lı</span>`).join('<span class="combo-sep"> + </span>');
    }

    function renderResults(d) {
        const combos = d.combinations || [];

        // Önerilen plan
        const bestEl = $('kalanBestPlan');
        if (bestEl) {
            if (combos.length) {
                const b  = combos[0];
                const st = b.status;
                bestEl.innerHTML = `
                    <div class="kalan-best-inner ${st.cls}">
                        <div class="kalan-best-combo">${formatCombo(b.parts)}</div>
                        <div class="kalan-best-stats">
                            <div><span>Tahmini final:</span> <strong>${fmtKg(b.final_kg)} kg</strong></div>
                            <div><span>Hedef farkı:</span> <strong class="${b.final_diff >= 0 ? 'kalan-pos' : 'kalan-neg'}">${fmtKgSign(b.final_diff)} kg</strong></div>
                            <div><span class="kalan-status-badge ${st.cls}">${st.text}</span></div>
                        </div>
                    </div>
                `;
            } else {
                bestEl.innerHTML = '<p class="muted">Sonuç üretilemedi.</p>';
            }
        }

        // Tüm kombinasyonlar tablosu
        const tbody = $('kalanCombosBody');
        if (tbody) {
            tbody.innerHTML = combos.map(c => `
                <tr>
                    <td class="combo-cell">${formatCombo(c.parts)}</td>
                    <td class="num">${fmtKg(c.estimated_kg)}</td>
                    <td class="num">${fmtKg(c.final_kg)}</td>
                    <td class="num ${c.final_diff >= 0 ? 'kalan-pos' : 'kalan-neg'}">${fmtKgSign(c.final_diff)}</td>
                    <td><span class="kalan-status-badge ${c.status.cls}">${c.status.text}</span></td>
                </tr>
            `).join('') || '<tr><td colspan="5" class="muted center" style="padding:12px">Kombinasyon bulunamadı</td></tr>';
        }
    }

})();
