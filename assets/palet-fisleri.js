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
        refreshTotals();
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
    function fmt0(value) { return Math.round(Number(value) || 0).toLocaleString('tr-TR'); }
    function refreshTotals() {
        const set = (key, text) => { const el = document.querySelector(`[data-sum="${key}"]`); if (el) el.textContent = text; };
        const cell = selectedCell;
        if (!cell) { set('parti', '—'); ['palet', 'adet', 'brut', 'dara', 'net'].forEach(k => set(k, '0')); set('ort', '0'); return; }
        const parti = cell.parentElement.querySelector('[data-col="parti_no"]')?.dataset.value ?? '';
        let palet = 0, adet = 0, brut = 0, dara = 0, net = 0;
        body.querySelectorAll('tr').forEach(row => {
            if ((row.querySelector('[data-col="parti_no"]')?.dataset.value ?? '') !== parti) return;
            const num = name => parseDecimal(row.querySelector(`[data-col="${name}"]`)?.dataset.value);
            palet++; adet += num('kasa_adeti'); brut += num('brut_kg'); dara += num('dara_kg'); net += num('net_kg');
        });
        set('parti', parti || '—'); set('palet', fmt0(palet)); set('adet', fmt0(adet));
        set('brut', fmt0(brut)); set('dara', fmt0(dara)); set('net', fmt0(net));
        set('ort', adet > 0 ? (brut / adet).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '0');
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
            (window.PALET_FISLERI?.listeler?.palet || []).forEach(item => {
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
        if (['brut_kg', 'dara_kg', 'net_kg'].includes(col)) return Number(value || 0).toLocaleString('tr-TR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        if (col === 'palet_adi') return (window.PALET_FISLERI?.listeler?.palet || []).find(p => String(p.id) === String(value))?.name || '';
        return value;
    }

    body.addEventListener('click', event => {
        const cell = event.target.closest('td.pf-cell');
        if (cell && !editor) selectCell(cell);
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
        if (event.key === 'ArrowDown') { event.preventDefault(); moveSelection(1, 0); }
        else if (event.key === 'ArrowUp') { event.preventDefault(); moveSelection(-1, 0); }
        else if (event.key === 'ArrowRight' || event.key === 'Tab') { event.preventDefault(); moveSelection(0, 1); }
        else if (event.key === 'ArrowLeft') { event.preventDefault(); moveSelection(0, -1); }
        else if (event.key === 'c' && event.ctrlKey && selectedCell) {
            event.preventDefault();
            navigator.clipboard?.writeText(getValue(selectedCell)).then(() => setStatus('Hücre panoya kopyalandı.')).catch(() => setStatus('Pano erişimi için HTTPS gerekir.'));
        }
    });

    // ───────── Ekle penceresi (kantar girişi) ─────────
    const L = () => window.PALET_FISLERI?.listeler || {};
    const trUp = v => String(v ?? '').trim().replace(/\s+/g, ' ').toLocaleUpperCase('tr-TR');
    const TUR = { palet: 'palet_tipi', firma: 'firma', urun: 'urun', kasa: 'kasa_cinsi' };
    const errorBox = document.getElementById('pfFormError');
    const saveBtn = document.getElementById('pfSave');
    const uyari = document.getElementById('pfUyari');
    const netBox = document.getElementById('pfNet');
    const alanSirasi = ['tarih', 'parti_no', 'palet', 'firma', 'urun', 'kasa', 'kasa_adeti', 'mustahsil', 'brut_kg', 'dara_kg', 'aciklama1', 'aciklama2'];
    let nextBelge = window.PALET_FISLERI?.sonrakiBelge || 1;

    function mesaj(text, ok) { errorBox.textContent = text || ''; errorBox.classList.toggle('pf-ok', !!ok); }

    // Müstahsil için yeni cari kartı: yazma, Cari modülünün TEK ucu cari_kaydet.php üzerinden (kendi yetki + audit kapısıyla).
    async function cariKartEkle(ad) {
        const fd = new FormData();
        fd.append('csrf', page.dataset.csrf || ''); fd.append('islem', 'kart_kaydet'); fd.append('unvan', ad); fd.append('tip', 'tedarikci'); fd.append('aktif', '1');
        const response = await fetch('cari_kaydet.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' } });
        const r = await response.json().catch(() => ({ ok: false, hata: 'Sunucudan geçerli yanıt alınamadı.' }));
        if (!response.ok || !r.ok) throw new Error(r.hata || 'Cari kartı açılamadı.');
        return { id: Number(r.id), name: ad };
    }

    // Yazdıkça bul — yoksa "+ ekle" (parti serbest metindir, ekleme satırı yok)
    function makeCombo(input) {
        const kind = input.dataset.cb;
        const canAdd = kind !== 'parti';
        const box = document.createElement('ul');
        box.className = 'pf-cb-list'; box.hidden = true; box.setAttribute('role', 'listbox');
        input.parentElement.appendChild(box);
        let items = [], active = -1;
        const kaynak = () => L()[kind] || [];
        function close() { box.hidden = true; active = -1; }
        function draw() {
            box.innerHTML = '';
            items.forEach((it, i) => {
                const li = document.createElement('li');
                li.setAttribute('role', 'option');
                li.className = (it.type === 'ekle' ? 'pf-cb-ekle ' : '') + (i === active ? 'pf-cb-aktif' : '');
                li.textContent = it.type === 'ekle' ? `+ “${it.ad}” ekle` : it.x.name;
                li.addEventListener('pointerdown', e => { e.preventDefault(); choose(it); });
                box.appendChild(li);
            });
            box.hidden = !items.length;
        }
        function render() {
            const q = trUp(input.value);
            const all = kaynak();
            const skor = x => { const n = trUp(x.name); return n === q ? 0 : n.startsWith(q) ? 1 : 2; };
            items = (q ? all.filter(x => trUp(x.name).includes(q)).sort((a, b) => skor(a) - skor(b)) : all).slice(0, 8).map(x => ({ type: 'var', x }));
            const tam = q && all.some(x => trUp(x.name) === q);
            if (canAdd && q && !tam) items.push({ type: 'ekle', ad: q });
            active = (kind !== 'parti' && q && items.length) ? 0 : -1;
            draw();
        }
        async function choose(it) {
            if (it.type === 'var') { input.value = it.x.name; if (it.x.id) input.dataset.id = it.x.id; else delete input.dataset.id; close(); return true; }
            try {
                if (kind === 'mustahsil') {
                    const r = await cariKartEkle(it.ad);
                    const list = L().mustahsil; if (list && !list.some(x => x.id === r.id)) list.push({ id: r.id, name: r.name });
                    input.value = r.name; input.dataset.id = r.id; close(); mesaj(`“${r.name}” cari kartı açıldı (tedarikçi).`, true);
                    return true;
                }
                const r = await send('tanim_ekle', { tur: TUR[kind], ad: it.ad });
                const list = L()[kind]; if (list && !list.some(x => x.id === r.id)) list.push({ id: r.id, name: r.name });
                input.value = r.name; input.dataset.id = r.id; close(); mesaj(r.yeni ? `“${r.name}” tanımlara eklendi.` : '', true);
                return true;
            } catch (error) { mesaj(error.message); return false; }
        }
        input.addEventListener('input', () => { delete input.dataset.id; render(); });
        input.addEventListener('focus', render);
        input.addEventListener('blur', () => setTimeout(close, 120));
        input.addEventListener('keydown', async event => {
            if (box.hidden) return;
            if (event.key === 'ArrowDown') { event.preventDefault(); active = Math.min(items.length - 1, active + 1); draw(); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); active = Math.max(0, active - 1); draw(); }
            else if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); }
            else if (event.key === 'Enter' && active >= 0) { event.preventDefault(); event.stopPropagation(); if (await choose(items[active])) siradaki(input); }
        });
    }
    function alan(name) { return form.elements[name]; }
    function siradaki(from) {
        let i = alanSirasi.indexOf(from.name) + 1;
        while (i < alanSirasi.length && (!alan(alanSirasi[i]) || alan(alanSirasi[i]).disabled)) i++;
        const next = alan(alanSirasi[i]);
        if (next) { next.focus(); if (next.select) next.select(); } else submitForm();
    }
    form.querySelectorAll('input[data-cb]').forEach(makeCombo);
    form.addEventListener('keydown', event => {
        const t = event.target;
        if (event.key === 'F10') { event.preventDefault(); submitForm(); return; }
        if (event.key === 'Enter' && t.tagName === 'INPUT' && alanSirasi.includes(t.name) && !event.defaultPrevented) { event.preventDefault(); siradaki(t); }
    });

    function sayi(v) { return parseDecimal(v); }
    function hesapla() {
        const brut = sayi(alan('brut_kg').value), dara = sayi(alan('dara_kg').value);
        const net = brut - dara;
        netBox.value = fmt0(Math.max(0, net));
        let u = '';
        if (dara > brut) u = 'Dara, brüt ağırlıktan büyük olamaz.';
        else if (brut > 0) {
            const parti = alan('parti_no').value.trim();
            const ornek = parti ? Array.from(body.querySelectorAll('tr')).filter(r => (r.querySelector('[data-col="parti_no"]')?.dataset.value ?? '') === parti).map(r => parseDecimal(r.querySelector('[data-col="brut_kg"]')?.dataset.value)).filter(n => n > 0).slice(0, 30) : [];
            if (ornek.length >= 5) {
                const ort = ornek.reduce((s, n) => s + n, 0) / ornek.length;
                if (Math.abs(brut - ort) / ort > 0.4) u = `Dikkat: bu partinin ortalama brütü yaklaşık ${fmt0(ort)} kg; girilen değer çok farklı.`;
            }
        }
        uyari.textContent = u;
        uyari.classList.toggle('pf-uyari-hata', dara > brut);
        saveBtn.disabled = dara > brut;
    }
    ['brut_kg', 'dara_kg', 'parti_no'].forEach(n => alan(n).addEventListener('input', hesapla));

    function openDialog() {
        if (!dialog || dialog.open) return;
        alan('belge_no').value = String(nextBelge);
        mesaj('');
        dialog.showModal();
        const hazir = alan('parti_no').value && alan('firma').value;
        const hedef = hazir ? alan('brut_kg') : alan('parti_no');
        hedef.focus(); if (hedef.select) hedef.select();
    }
    document.getElementById('pfAdd')?.addEventListener('click', openDialog);
    document.addEventListener('keydown', event => {
        if (event.key === 'F2' && !dialog.open && !event.target.matches('input,textarea,select')) { event.preventDefault(); openDialog(); }
    });
    document.getElementById('pfClose').addEventListener('click', () => dialog.close());
    document.getElementById('pfCancel').addEventListener('click', () => dialog.close());

    // Listede yok mu? Tam eşleşme varsa id'yi bul; yoksa hata (kullanıcı "+ ekle" ile tanımlasın).
    function coz(name, zorunlu, etiket) {
        const input = alan(name);
        const text = input.value.trim();
        if (!text) { if (zorunlu) throw new Error(`${etiket} zorunludur.`); return null; }
        if (input.dataset.id) return { id: Number(input.dataset.id), name: text };
        const bulunan = (L()[input.dataset.cb] || []).find(x => trUp(x.name) === trUp(text));
        if (bulunan) { input.dataset.id = bulunan.id; input.value = bulunan.name; return { id: bulunan.id, name: bulunan.name }; }
        throw new Error(`${etiket}: “${text}” kayıtlı değil. Listeden seçin ya da “+ ekle” ile tanımlayın.`);
    }
    function satirEkle(r) {
        const tr = document.createElement('tr');
        tr.dataset.id = r.id; tr.dataset.recordId = r.loading_record_id;
        tr.dataset.search = [r.belge_no, r.parti_no, r.palet_adi, r.firma, r.urun].join(' ').toLocaleLowerCase('tr-TR');
        const deger = { belge_no: [r.belge_no, r.belge_no], parti_no: [r.parti_no, r.parti_no], palet_adi: [r.palet_tipi_id || '', r.palet_adi], firma: [r.firma, r.firma],
            kasa_adeti: [r.kasa_adeti, fmt0(r.kasa_adeti)], brut_kg: [r.brut_kg, fmt0(r.brut_kg)], dara_kg: [r.dara_kg, fmt0(r.dara_kg)], net_kg: [r.net_kg, fmt0(r.net_kg)],
            mustahsil_ad: [r.mustahsil_ad, r.mustahsil_ad], aciklama1: [r.aciklama1, r.aciklama1], aciklama2: [r.aciklama2, r.aciklama2] };
        Array.from(table.tHead.rows[0].children).forEach(th => {
            const col = th.dataset.col; const [raw, shown] = deger[col] || ['', ''];
            const td = document.createElement('td');
            td.className = 'pf-cell' + (['kasa_adeti', 'brut_kg', 'dara_kg', 'net_kg'].includes(col) ? ' pf-num' : '');
            td.dataset.col = col; td.dataset.value = String(raw ?? ''); td.textContent = String(shown ?? '');
            tr.appendChild(td);
        });
        body.insertBefore(tr, body.firstChild);
        empty.hidden = true;
        const sayac = document.getElementById('pfCount'); if (sayac) sayac.textContent = (Number(String(sayac.textContent).replace(/\./g, '')) + 1).toLocaleString('tr-TR');
        selectCell(tr.children[0]);
        wrap.scrollTop = 0;
    }
    let kaydediyor = false;
    async function submitForm() {
        if (kaydediyor || saveBtn.disabled) return;
        mesaj('');
        try {
            const brut = sayi(alan('brut_kg').value), dara = sayi(alan('dara_kg').value);
            if (!(brut > 0)) { alan('brut_kg').focus(); throw new Error('Brüt ağırlık girin.'); }
            if (dara > brut) throw new Error('Dara, brüt ağırlıktan büyük olamaz.');
            const firma = coz('firma', true, 'Müşteri');
            const urun = coz('urun', true, 'Ürün');
            const palet = coz('palet', false, 'Palet');
            const kasa = coz('kasa', false, 'Kasa ambalaj');
            const mustahsil = alan('mustahsil').disabled ? null : coz('mustahsil', false, 'Müstahsil');
            kaydediyor = true; saveBtn.disabled = true;
            const r = await send('create', { tarih: alan('tarih').value, parti_no: trUp(alan('parti_no').value), firma: firma.name, urun: urun.name,
                palet_tipi_id: palet ? palet.id : '', kasa_cinsi_id: kasa ? kasa.id : '', mustahsil_id: mustahsil ? mustahsil.id : '', kasa_adeti: alan('kasa_adeti').value || 0, brut_kg: brut, dara_kg: dara,
                aciklama1: alan('aciklama1').value, aciklama2: alan('aciklama2').value });
            satirEkle(r.row);
            const parti = trUp(alan('parti_no').value);
            alan('parti_no').value = parti;
            if (parti && !(L().parti || []).some(x => trUp(x.name) === trUp(parti))) (L().parti = L().parti || []).unshift({ id: 0, name: parti });
            nextBelge = r.next_belge; alan('belge_no').value = String(nextBelge);
            ['brut_kg', 'dara_kg', 'aciklama1', 'aciklama2', 'mustahsil'].forEach(n => { alan(n).value = ''; });
            delete alan('mustahsil').dataset.id;   // müstahsil palet başınadır, bir sonrakine taşınmaz
            hesapla();
            mesaj(`${r.row.belge_no} numaralı belge kaydedildi.`, true);
            alan('brut_kg').focus();
        } catch (error) { mesaj(error.message); }
        finally { kaydediyor = false; hesapla(); }
    }
    form.addEventListener('submit', event => { event.preventDefault(); submitForm(); });

    if (search) search.addEventListener('input', () => {
        const q = search.value.trim().toLocaleLowerCase('tr-TR');
        let count = 0;
        body.querySelectorAll('tr').forEach(row => { row.hidden = !!q && !row.dataset.search.toLocaleLowerCase('tr-TR').includes(q); if (!row.hidden) count++; });
        document.getElementById('pfCount').textContent = count.toLocaleString('tr-TR');
        empty.hidden = count > 0;
        if (!count) empty.textContent = q ? 'Aramanızla eşleşen kayıt yok.' : 'Henüz palet fişi yok. F2 - Ekle ile başlayın.';
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
    document.querySelectorAll('[data-pf-pasif]').forEach(el => el.addEventListener('click', event => { event.preventDefault(); setStatus('Bu araç henüz çalışmıyor.'); }));
    { const first = visibleRows()[0]; if (first) selectCell(first.children[0]); }
    // Sayfa, pencere yüksekliğine ölçeklenir: tablo kalan alanı doldurur, altta boşluk kalmaz.
    function fitPage() {
        if (window.innerWidth < 768) { page.style.height = ''; return; }
        const top = page.getBoundingClientRect().top + window.scrollY;
        page.style.height = Math.max(320, window.innerHeight - top - 12) + 'px';
    }
    fitPage();
    window.addEventListener('resize', fitPage);
    window.addEventListener('load', fitPage);
    wrap.addEventListener('click', () => wrap.focus({ preventScroll: true }));
})();
