(function () {
    'use strict';
    const page = document.getElementById('pfPage');
    const table = document.getElementById('pfTable');
    const body = document.getElementById('pfBody');
    if (!page || !table || !body) return;
    const wrap = document.getElementById('pfTableWrap');
    const dialog = document.getElementById('pfDialog');
    const form = document.getElementById('pfForm');
    const status = document.getElementById('pfStatus');
    const search = document.getElementById('pfSearch');
    const empty = document.getElementById('pfEmpty');
    let selectedCell = null;
    let editor = null;
    let holdTimer = 0;
    let armedHeader = null;
    let movingHeader = false;
    const columnOrderKey = 'asya.palet-fisleri.column-order.v1';
    const editable = new Set(['parti_no', 'palet_adi', 'firma', 'kasa_adeti', 'brut_kg', 'dara_kg', 'aciklama1', 'aciklama2']);

    async function send(action, data) {
        const fd = new FormData();
        fd.append('csrf', page.dataset.csrf || '');
        fd.append('pf_action', action);
        Object.entries(data || {}).forEach(([key, value]) => fd.append(key, value == null ? '' : String(value)));
        const response = await fetch(location.href, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
        const result = await response.json().catch(() => ({ ok: false, error: 'Sunucudan geçerli yanıt alınamadı.' }));
        if (!response.ok || !result.ok) throw new Error(result.error || 'İşlem tamamlanamadı.');
        return result;
    }

    function cells() { return Array.from(body.querySelectorAll('td.pf-cell')); }
    function visibleRows() { return Array.from(body.querySelectorAll('tr')).filter(row => !row.hidden); }
    function selectCell(cell) {
        if (!cell || cell.tagName !== 'TD') return;
        body.querySelectorAll('.pf-cell-selected').forEach(node => node.classList.remove('pf-cell-selected'));
        body.querySelectorAll('.pf-row-selected').forEach(node => node.classList.remove('pf-row-selected'));
        cell.classList.add('pf-cell-selected');
        cell.parentElement.classList.add('pf-row-selected');
        selectedCell = cell;
    }
    function selectionPosition() {
        if (!selectedCell) return [0, 0];
        return [visibleRows().indexOf(selectedCell.parentElement), Array.from(selectedCell.parentElement.children).indexOf(selectedCell)];
    }
    function moveSelection(rowDelta, colDelta) {
        const rows = visibleRows();
        if (!rows.length) return;
        if (!selectedCell) { selectCell(rows[0].children[0]); rows[0].children[0].scrollIntoView({ block: 'nearest', inline: 'nearest' }); return; }
        let [ri, ci] = selectionPosition();
        ri = Math.max(0, Math.min(rows.length - 1, ri + rowDelta));
        const maxCol = Math.max(0, rows[ri].children.length - 1);
        ci = Math.max(0, Math.min(maxCol, ci + colDelta));
        const target = rows[ri].children[ci];
        selectCell(target);
        target.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }
    function getValue(cell) { return cell?.dataset.value ?? ''; }
    function setStatus(message) { if (status) status.textContent = message; }
    function formatNumber(value, decimals) { return Number(value || 0).toLocaleString('tr-TR', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }); }
    function parseDecimal(value) {
        let s = String(value ?? '').replace(/[\s\u00a0]/g, '');
        const comma = s.includes(','); const dot = s.includes('.');
        if (comma && dot) s = s.lastIndexOf('.') > s.lastIndexOf(',') ? s.replace(/,/g, '') : s.replace(/\./g, '').replace(',', '.');
        else if (comma) s = s.replace(',', '.');
        const n = Number(s);
        return Number.isFinite(n) ? n : 0;
    }
    function refreshTotals() {
        ['kasa_adeti', 'brut_kg', 'dara_kg', 'net_kg'].forEach(col => {
            const sum = Array.from(body.querySelectorAll(`td[data-col="${col}"]`)).reduce((total, cell) => total + parseDecimal(cell.dataset.value), 0);
            const target = document.querySelector(`[data-total="${col}"]`);
            if (target) target.textContent = formatNumber(sum, col === 'kasa_adeti' ? 0 : 3);
        });
    }
    function rowData(row) {
        const get = name => row.querySelector(`[data-col="${name}"]`)?.dataset.value ?? '';
        return { id: row.dataset.id, firma: get('firma'), parti_no: get('parti_no'), palet_tipi_id: get('palet_adi'), kasa_adeti: get('kasa_adeti'), brut_kg: get('brut_kg'), dara_kg: get('dara_kg'), aciklama1: get('aciklama1'), aciklama2: get('aciklama2') };
    }

    function beginEdit(cell) {
        if (!cell || !editable.has(cell.dataset.col) || editor) return;
        selectCell(cell);
        const row = cell.parentElement;
        const col = cell.dataset.col;
        const oldValue = getValue(cell);
        let input;
        if (col === 'palet_adi') {
            input = document.createElement('select');
            const blank = document.createElement('option'); blank.value = ''; blank.textContent = 'Seçiniz'; input.appendChild(blank);
            (window.PALET_FISLERI?.paletTipleri || []).forEach(item => {
                const option = document.createElement('option'); option.value = item.id; option.textContent = item.name;
                if (String(item.id) === String(oldValue)) option.selected = true;
                input.appendChild(option);
            });
        } else {
            input = document.createElement('input');
            input.type = (col === 'kasa_adeti') ? 'number' : 'text';
            if (col === 'kasa_adeti') { input.min = '0'; input.step = '1'; input.inputMode = 'numeric'; }
            if (['brut_kg', 'dara_kg'].includes(col)) input.inputMode = 'decimal';
            input.value = oldValue;
        }
        input.className = 'pf-editor';
        input.setAttribute('aria-label', cell.dataset.col);
        cell.textContent = '';
        cell.classList.add('pf-editing');
        cell.appendChild(input);
        editor = { cell, row, col, input, oldValue };
        input.focus();
        if (input.select) input.select();
    }

    async function finishEdit(save, nextCol) {
        if (!editor) return;
        const current = editor;
        editor = null;
        if (!save) {
            current.cell.classList.remove('pf-editing');
            current.cell.textContent = displayValue(current.col, current.oldValue);
            setTimeout(() => current.cell.dataset.value = current.oldValue, 0);
            return;
        }
        const data = rowData(current.row);
        const value = current.input.value;
        const columnMap = { palet_adi: 'palet_tipi_id' };
        data[columnMap[current.col] || current.col] = value;
        current.cell.classList.remove('pf-editing');
        current.cell.textContent = value;
        current.cell.dataset.value = value;
        try {
            await send('save', data);
            current.cell.dataset.value = value;
            current.cell.textContent = displayValue(current.col, value);
            current.cell.title = value;
            if (['firma', 'parti_no'].includes(current.col)) {
                body.querySelectorAll(`tr[data-record-id="${CSS.escape(current.row.dataset.recordId)}"]`).forEach(sibling => {
                    const target = sibling.querySelector(`[data-col="${current.col}"]`);
                    if (target) { target.dataset.value = value; target.textContent = value; }
                });
            }
            if (['brut_kg', 'dara_kg'].includes(current.col)) {
                const brut = parseDecimal(current.row.querySelector('[data-col="brut_kg"]').dataset.value);
                const dara = parseDecimal(current.row.querySelector('[data-col="dara_kg"]').dataset.value);
                const net = Math.max(0, brut - dara);
                const netCell = current.row.querySelector('[data-col="net_kg"]');
                netCell.dataset.value = String(net);
                netCell.textContent = displayValue('net_kg', net);
            }
            if (current.col === 'firma' || current.col === 'parti_no') {
                const pno = current.row.querySelector('[data-col="parti_no"]')?.dataset.value || '';
                const firma = current.row.querySelector('[data-col="firma"]')?.dataset.value || '';
                body.querySelectorAll(`tr[data-record-id="${CSS.escape(current.row.dataset.recordId)}"]`).forEach(sibling => { sibling.dataset.search = `${pno} ${firma} ${sibling.querySelector('[data-col="palet_adi"]')?.textContent || ''}`.toLocaleLowerCase('tr-TR'); });
            }
            if (['kasa_adeti', 'brut_kg', 'dara_kg'].includes(current.col)) refreshTotals();
            setStatus('Değişiklik kaydedildi.');
            selectCell(current.cell);
        } catch (error) {
            current.cell.textContent = displayValue(current.col, current.oldValue);
            current.cell.dataset.value = current.oldValue;
            setStatus(error.message);
            current.cell.focus();
        }
        if (nextCol) moveSelection(0, 1);
    }
    function displayValue(col, value) {
        if (['kasa_adeti'].includes(col)) return Number(value || 0).toLocaleString('tr-TR');
        if (['brut_kg', 'dara_kg', 'net_kg'].includes(col)) return Number(value || 0).toLocaleString('tr-TR', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
        if (col === 'palet_adi') return (window.PALET_FISLERI?.paletTipleri || []).find(p => String(p.id) === String(value))?.name || '';
        return value;
    }

    body.addEventListener('click', event => {
        const cell = event.target.closest('td.pf-cell');
        if (cell && !editor) selectCell(cell);
    });
    body.addEventListener('dblclick', event => {
        const cell = event.target.closest('td.pf-cell');
        if (cell) beginEdit(cell);
    });
    body.addEventListener('keydown', event => {
        if (!editor) return;
        if (event.key === 'Escape') { event.preventDefault(); finishEdit(false); }
        else if (event.key === 'Enter') { event.preventDefault(); finishEdit(true, true); }
        else if (event.key === 'Tab') { event.preventDefault(); finishEdit(true, true); }
    });

    async function rowAction(action) {
        if (!selectedCell) return;
        const row = selectedCell.parentElement;
        if (action === 'delete' && !window.confirm('Seçili palet fişi silinsin mi? Bu işlem geri alınamaz.')) return;
        try {
            await send(action, { id: row.dataset.id });
            setStatus(action === 'delete' ? 'Palet fişi silindi.' : 'Palet fişi kopyalandı.');
            location.reload();
        } catch (error) { setStatus(error.message); }
    }
    wrap.addEventListener('keydown', event => {
        if (editor || event.target.matches('input,select,textarea')) return;
        if (event.ctrlKey && event.shiftKey && event.key.toLowerCase() === 'd') { event.preventDefault(); rowAction('duplicate'); return; }
        if (event.key === 'Delete' && selectedCell) { event.preventDefault(); rowAction('delete'); return; }
        if (event.key === 'F2' && selectedCell) { event.preventDefault(); beginEdit(selectedCell); return; }
        if (event.key === 'Enter' && selectedCell) { event.preventDefault(); beginEdit(selectedCell); return; }
        if (event.key === 'ArrowDown') { event.preventDefault(); moveSelection(1, 0); }
        else if (event.key === 'ArrowUp') { event.preventDefault(); moveSelection(-1, 0); }
        else if (event.key === 'ArrowRight' || event.key === 'Tab') { event.preventDefault(); moveSelection(0, 1); }
        else if (event.key === 'ArrowLeft') { event.preventDefault(); moveSelection(0, -1); }
        else if (event.key === 'c' && event.ctrlKey && selectedCell) {
            event.preventDefault();
            navigator.clipboard?.writeText(getValue(selectedCell)).then(() => setStatus('Hücre panoya kopyalandı.')).catch(() => setStatus('Pano erişimi için HTTPS gerekir.'));
        }
    });
    document.addEventListener('keydown', event => {
        if (event.ctrlKey && event.key.toLowerCase() === 'n' && !dialog.open && !event.target.matches('input,textarea,select')) { event.preventDefault(); openDialog(); }
    });

    function openDialog() { form.reset(); form.elements.tarih.value = new Date().toISOString().slice(0, 10); document.getElementById('pfFormError').textContent = ''; dialog.showModal(); form.elements.firma.focus(); }
    document.getElementById('pfAdd').addEventListener('click', openDialog);
    document.getElementById('pfClose').addEventListener('click', () => dialog.close());
    document.getElementById('pfCancel').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const errorBox = document.getElementById('pfFormError');
        const button = document.getElementById('pfSave');
        errorBox.textContent = ''; button.disabled = true;
        const data = Object.fromEntries(new FormData(form).entries());
        try { await send('create', data); location.reload(); }
        catch (error) { errorBox.textContent = error.message; button.disabled = false; }
    });

    if (search) search.addEventListener('input', () => {
        const q = search.value.trim().toLocaleLowerCase('tr-TR');
        let count = 0;
        body.querySelectorAll('tr').forEach(row => { row.hidden = !!q && !row.dataset.search.toLocaleLowerCase('tr-TR').includes(q); if (!row.hidden) count++; });
        document.getElementById('pfCount').textContent = count.toLocaleString('tr-TR');
        empty.hidden = count > 0;
        if (!count) empty.textContent = q ? 'Aramanızla eşleşen kayıt yok.' : 'Henüz palet fişi yok. Yeni Palet Fişi ile başlayın.';
    });

    // Başlıklara basılı tutup sürükleyerek sütun sırası değiştir.
    function moveColumn(from, to) {
        if (from === to || from < 0 || to < 0) return;
        table.querySelectorAll('tr').forEach(row => {
            const items = Array.from(row.children);
            const item = items[from];
            const ref = items[to];
            if (item && ref) row.insertBefore(item, from < to ? ref.nextSibling : ref);
        });
    }
    function saveOrder() { localStorage.setItem(columnOrderKey, JSON.stringify(Array.from(table.tHead.rows[0].children).map(th => th.dataset.col))); }
    function applyOrder() {
        let order; try { order = JSON.parse(localStorage.getItem(columnOrderKey) || '[]'); } catch (_) { return; }
        const header = Array.from(table.tHead.rows[0].children);
        if (!Array.isArray(order) || order.length !== header.length) return;
        const current = header.map(th => th.dataset.col);
        if (order.some(col => !current.includes(col))) return;
        order.forEach((col, targetIndex) => {
            const currentIndex = Array.from(table.tHead.rows[0].children).findIndex(th => th.dataset.col === col);
            if (currentIndex !== targetIndex) moveColumn(currentIndex, targetIndex);
        });
    }
    applyOrder();
    const headers = table.tHead.rows[0];
    headers.addEventListener('pointerdown', event => {
        if (event.button !== 0) return;
        const th = event.target.closest('th[data-col]'); if (!th) return;
        clearTimeout(holdTimer); armedHeader = th; movingHeader = false;
        holdTimer = window.setTimeout(() => { movingHeader = true; th.classList.add('pf-drag-armed'); setStatus('Sütun taşınıyor — istediğiniz yere bırakın.'); }, 520);
    });
    headers.addEventListener('pointermove', event => {
        if (!movingHeader || !armedHeader) return;
        const target = Array.from(headers.children).find(th => { const r = th.getBoundingClientRect(); return event.clientX >= r.left && event.clientX <= r.right; });
        if (!target || target === armedHeader) return;
        const from = Array.from(headers.children).indexOf(armedHeader);
        const to = Array.from(headers.children).indexOf(target);
        moveColumn(from, to);
        armedHeader = target;
        target.classList.add('pf-drag-armed');
    });
    function releaseHeader() {
        clearTimeout(holdTimer);
        if (movingHeader) { headers.querySelectorAll('.pf-drag-armed').forEach(th => th.classList.remove('pf-drag-armed')); saveOrder(); setStatus('Sütun sırası bu cihazda kaydedildi.'); }
        movingHeader = false; armedHeader = null;
    }
    headers.addEventListener('pointerup', releaseHeader);
    headers.addEventListener('pointercancel', releaseHeader);
    headers.addEventListener('contextmenu', event => { if (movingHeader) event.preventDefault(); });
    wrap.addEventListener('click', () => wrap.focus({ preventScroll: true }));
})();
