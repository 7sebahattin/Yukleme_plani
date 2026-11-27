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
    const empty = document.getElementById('pfEmpty');
    let selectedCell = null;
    let editor = null;
    let holdTimer = 0;
    const gizli = new Set();   // kullanıcının gizlediği sütunlar (data-col)
    // v550: yetki bayrakları sunucudan (data-izin-*): ekle / duzelt / sil / mus (yeni müstahsil kartı = cari ekle). Yönetici hepsi "1". Asıl koruma sunucuda.
    const izin = ad => (page.dataset['izin' + ad] ?? '1') !== '0';
    const gorHuc = row => Array.from(row.children).filter(c => !gizli.has(c.dataset.col));
    const ilkHucre = row => gorHuc(row)[0] || row.children[0];
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
        return [visibleRows().indexOf(selectedCell.parentElement), gorHuc(selectedCell.parentElement).indexOf(selectedCell)];
    }
    function moveSelection(rowDelta, colDelta) {
        const rows = visibleRows();
        if (!rows.length) return;
        if (!selectedCell) { const h = ilkHucre(rows[0]); selectCell(h); h.scrollIntoView({ block: 'nearest', inline: 'nearest' }); return; }
        let [ri, ci] = selectionPosition();
        ri = Math.max(0, Math.min(rows.length - 1, ri + rowDelta));
        const hucreler = gorHuc(rows[ri]);
        ci = Math.max(0, Math.min(Math.max(0, hucreler.length - 1), ci + colDelta));
        const target = hucreler[ci] || rows[ri].children[0];
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
    // Satıra çift tık: Düzelt penceresi (hücre içinde düzenleme YOK).
    body.addEventListener('dblclick', event => {
        const cell = event.target.closest('td.pf-cell');
        if (!cell) return;
        selectCell(cell);
        openDialog('duzelt');
    });
    body.addEventListener('keydown', event => {
        if (!editor) return;
        if (event.key === 'Escape') { event.preventDefault(); finishEdit(false); }
        else if (event.key === 'Enter') { event.preventDefault(); finishEdit(true, true); }
        else if (event.key === 'Tab') { event.preventDefault(); finishEdit(true, true); }
    });

    function seciliSatir() { return selectedCell ? selectedCell.parentElement : null; }
    function sayacDegis(delta) {
        const sayac = document.getElementById('pfCount');
        if (sayac) sayac.textContent = Math.max(0, Number(String(sayac.textContent).replace(/\./g, '')) + delta).toLocaleString('tr-TR');
    }
    // Çoğalt: seçili satır hemen kopyalanır (yeni belge no) ve kopya Düzelt penceresinde açılır;
    // gerekirse değiştirip Güncelle ile kaydedilir, kapatılırsa kopya olduğu gibi kalır.
    async function coglat() {
        const row = seciliSatir();
        if (!izin('Ekle')) { setStatus('Çoğaltma için yetkiniz yok.'); return; }
        if (!row) { setStatus('Önce çoğaltılacak satırı seçin.'); return; }
        if (dialog?.open) return;
        try {
            const r = await send('duplicate', { id: row.dataset.id });
            satirEkle(r.row);
            nextBelge = r.next_belge || nextBelge;
            setStatus(`${r.row.belge_no} numaralı belge olarak kopyalandı; gerekirse düzeltip Güncelle'ye basın.`);
            openDialog('duzelt');
        } catch (error) { setStatus(error.message); }
    }
    // Sil: ilk basış satırı KIRMIZI işaretler (başka satıra geçilse de kırmızı kalır); işaretli satırda ikinci basış
    // (düğme / F3 / Delete) Sil · Geri Al · İptal penceresini açar. Sil: siler. Geri Al: kırmızılık kalkar. İptal: kırmızı kalır.
    const silDialog = document.getElementById('pfSilDialog');
    let silHedef = null;
    async function sil() {
        const row = seciliSatir();
        if (!izin('Sil')) { setStatus('Silme için yetkiniz yok.'); return; }
        if (!row) { setStatus('Önce silinecek satırı seçin.'); return; }
        if (silDialog?.open) return;
        const belge = row.querySelector('[data-col="belge_no"]')?.textContent.trim();
        if (!row.classList.contains('pf-row-silinecek')) {
            row.classList.add('pf-row-silinecek');
            setStatus(`${belge} numaralı palet fişi silinmek üzere işaretlendi — silmek için Sil'e tekrar basın.`);
            return;
        }
        silHedef = row;
        const ozet = ['parti_no', 'firma', 'brut_kg'].map(c => row.querySelector(`[data-col="${c}"]`)?.textContent.trim()).filter(Boolean).join(' · ');
        document.getElementById('pfSilMetin').innerHTML = '';
        const b = document.createElement('b'); b.textContent = `${belge} numaralı palet fişi`;
        document.getElementById('pfSilMetin').append(b, ` silinsin mi? Bu işlem geri alınamaz.${ozet ? ' (' + ozet + ')' : ''}`);
        silDialog.showModal();
        document.getElementById('pfSilIptal')?.focus();
    }
    async function silOnayla() {
        const row = silHedef; if (!row) return;
        const belge = row.querySelector('[data-col="belge_no"]')?.textContent.trim();
        const dugme = document.getElementById('pfSilOnay'); if (dugme) dugme.disabled = true;
        try {
            await send('delete', { id: row.dataset.id });
            const secili = row === seciliSatir();
            const komsu = row.nextElementSibling || row.previousElementSibling;
            row.remove(); sayacDegis(-1);
            if (secili) { selectedCell = null; if (komsu) selectCell(ilkHucre(komsu)); else refreshTotals(); }
            empty.hidden = body.children.length > 0;
            setStatus(`${belge} numaralı palet fişi silindi.`);
            silHedef = null; silDialog.close();
        } catch (error) { silDialog.close(); setStatus(error.message); }
        finally { if (dugme) dugme.disabled = false; }
    }
    document.getElementById('pfSilOnay')?.addEventListener('click', silOnayla);
    document.getElementById('pfSilGeriAl')?.addEventListener('click', () => {
        if (silHedef) { silHedef.classList.remove('pf-row-silinecek'); setStatus('Silme geri alındı.'); }
        silHedef = null; silDialog.close();
    });
    document.getElementById('pfSilIptal')?.addEventListener('click', () => { silHedef = null; silDialog.close(); });
    silDialog?.addEventListener('close', () => { silHedef = null; });
    wrap.addEventListener('keydown', event => {
        if (editor || event.target.matches('input,select,textarea')) return;
        if (event.ctrlKey && event.shiftKey && event.key.toLowerCase() === 'd') { event.preventDefault(); coglat(); return; }
        if ((event.key === 'Delete' || event.key === 'F3') && selectedCell) { event.preventDefault(); sil(); return; }
        if (event.key === 'F4' && selectedCell) { event.preventDefault(); openDialog('duzelt'); return; }
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
    const alanSirasi = ['tarih', 'parti_no', 'firma', 'mustahsil', 'palet', 'kasa', 'urun', 'kasa_adeti', 'brut_kg', 'aciklama1', 'aciklama2'];
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

    const fold = v => String(v ?? '')
        .replace(/İ/g, 'i').replace(/I/g, 'i').replace(/Ş/g, 's').replace(/Ğ/g, 'g')
        .replace(/Ü/g, 'u').replace(/Ö/g, 'o').replace(/Ç/g, 'c')
        .replace(/ı/g, 'i').replace(/ş/g, 's').replace(/ğ/g, 'g')
        .replace(/ü/g, 'u').replace(/ö/g, 'o').replace(/ç/g, 'c')
        .toLowerCase().trim();

    // İmleç gelince (focus/click) + yazdıkça bul: en son 3 işlem üstte, kalanı alfabetik; yoksa "+ ekle"
    function makeCombo(input) {
        const kind = input.dataset.cb;
        const canAdd = kind !== 'parti' && (kind !== 'mustahsil' || izin('Mus'));   // yeni müstahsil = cari_kaydet (cari Ekle yetkisi)
        const box = document.createElement('ul');
        box.className = 'pf-cb-list'; box.hidden = true; box.setAttribute('role', 'listbox');
        input.parentElement.appendChild(box);
        let items = [], active = -1;
        const kaynak = () => L()[kind] || [];
        function close() { box.hidden = true; active = -1; }
        function updateActive() {
            const lis = box.querySelectorAll('li');
            lis.forEach((li, i) => {
                const on = i === active;
                li.classList.toggle('pf-cb-aktif', on);
                if (on && typeof li.scrollIntoView === 'function') li.scrollIntoView({ block: 'nearest' });
            });
        }
        function draw() {
            box.innerHTML = '';
            items.forEach((it, i) => {
                const li = document.createElement('li');
                li.setAttribute('role', 'option');
                const cls = [];
                if (it.type === 'ekle') cls.push('pf-cb-ekle');
                if (it.sonBitis) cls.push('pf-cb-son-bitis');
                if (i === active) cls.push('pf-cb-aktif');
                li.className = cls.join(' ');
                li.textContent = it.type === 'ekle' ? `+ “${it.ad}” ekle` : it.x.name;
                li.addEventListener('pointerdown', e => { e.preventDefault(); choose(it); });
                box.appendChild(li);
            });
            box.hidden = !items.length;
        }
        function render() {
            if (input.disabled || input.readOnly) { close(); return; }
            const raw = input.value.trim();
            const q = fold(raw);
            const all = kaynak();
            items = [];
            if (!q) {
                let lastRecentIdx = -1;
                for (let i = 0; i < all.length; i++) {
                    if (all[i].recent) lastRecentIdx = i;
                    items.push({ type: 'var', x: all[i] });
                }
                if (lastRecentIdx >= 0 && lastRecentIdx < items.length - 1) {
                    items[lastRecentIdx].sonBitis = true;
                }
                active = -1;
            } else {
                const skor = x => { const n = fold(x.name); return n === q ? 0 : n.startsWith(q) ? 1 : 2; };
                const matched = all.filter(x => fold(x.name).includes(q))
                    .map((x, idx) => ({ x, idx, s: skor(x) }))
                    .sort((a, b) => (a.s - b.s) || (a.idx - b.idx));
                items = matched.map(m => ({ type: 'var', x: m.x }));
                const tam = all.some(x => fold(x.name) === q);
                if (canAdd && !tam) items.push({ type: 'ekle', ad: trUp(raw) });
                active = -1;
            }
            draw();
        }
        async function choose(it) {
            if (it.type === 'var') { input.value = it.x.name; if (it.x.id) input.dataset.id = it.x.id; else delete input.dataset.id; close(); daraOtomatik(); return true; }
            try {
                if (kind === 'mustahsil') {
                    const r = await cariKartEkle(it.ad);
                    const list = L().mustahsil; if (list && !list.some(x => x.id === r.id)) list.push({ id: r.id, name: r.name });
                    input.value = r.name; input.dataset.id = r.id; close(); mesaj(`“${r.name}” cari kartı açıldı (tedarikçi).`, true);
                    return true;
                }
                const r = await send('tanim_ekle', { tur: TUR[kind], ad: it.ad });
                const list = L()[kind]; if (list && !list.some(x => x.id === r.id)) list.push({ id: r.id, name: r.name });
                input.value = r.name; input.dataset.id = r.id; close(); daraOtomatik(); mesaj(r.yeni ? `“${r.name}” tanımlara eklendi.` : '', true);
                return true;
            } catch (error) { mesaj(error.message); return false; }
        }
        let mustTimer = null, mustSeq = 0;
        async function mustahsilAra(qRaw) {
            const seq = ++mustSeq;
            try {
                const res = await fetch('palet_fisleri.php?mustahsil_ara=' + encodeURIComponent(qRaw), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                const r = await res.json().catch(() => null);
                if (seq !== mustSeq || !r || !r.ok || !Array.isArray(r.items)) return;
                const list = L().mustahsil || (L().mustahsil = []);
                r.items.forEach(it => {
                    if (!list.some(x => x.id === it.id)) list.push({ id: it.id, name: it.name });
                });
                if (document.activeElement === input) render();
            } catch (_) { /* ağ hatasında yerel liste/ekleme yine çalışır */ }
        }
        input.addEventListener('focus', render);
        input.addEventListener('click', () => { if (box.hidden) render(); });
        input.addEventListener('input', () => {
            delete input.dataset.id;
            render();
            if (kind === 'mustahsil') {
                clearTimeout(mustTimer);
                const qRaw = input.value.trim();
                if (qRaw.length >= 1) mustTimer = setTimeout(() => mustahsilAra(qRaw), 180);
            }
        });
        input.addEventListener('blur', () => setTimeout(close, 150));
        input.addEventListener('keydown', async event => {
            if (box.hidden) {
                if (event.key === 'ArrowDown') { event.preventDefault(); render(); }
                return;
            }
            if (event.key === 'ArrowDown') { event.preventDefault(); active = Math.min(items.length - 1, active + 1); updateActive(); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); active = Math.max(0, active - 1); updateActive(); }
            else if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); }
            else if (event.key === 'Enter' && active >= 0 && items[active]) { event.preventDefault(); event.stopPropagation(); if (await choose(items[active])) siradaki(input); }
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
    // Dara = kasa adedi × kasa darası + palet darası (Yükleme Planı ile aynı formül; tanımlardaki birim dara).
    function tanimBul(kind) {
        const girilen = trUp(alan(kind).value);
        if (!girilen) return null;
        return (L()[kind] || []).find(x => trUp(x.name) === girilen) || null;
    }
    // Seçilen palet / ambalajın birim darası, kutunun yanında gösterilir.
    function birimGoster() {
        const yaz = (kind, ek) => {
            const el = form.querySelector(`[data-birim="${kind}"]`); if (!el) return;
            const u = tanimBul(kind)?.unit;
            const var_ = Number(u) > 0;
            const sayi_ = Number(u).toLocaleString('tr-TR', { maximumFractionDigits: 3 });
            el.textContent = var_ ? `Dara ${sayi_} kg${ek}` : '';
            el.dataset.kisa = var_ ? `${sayi_} kg${ek}` : '';   // dar ekranda "Dara" sözü düşer
            el.hidden = !var_;
        };
        yaz('palet', ''); yaz('kasa', '/adet');
    }
    function daraOtomatik() {
        const adet = Math.max(0, parseInt(alan('kasa_adeti').value, 10) || 0);
        const dara = adet * (Number(tanimBul('kasa')?.unit) || 0) + (Number(tanimBul('palet')?.unit) || 0);
        alan('dara_kg').value = String(Math.round(dara * 1000) / 1000).replace('.', ',');   // ham saklanır (yuvarlama yalnız gösterimde)
        birimGoster();
        hesapla();
    }
    function hesapla() {
        const brut = sayi(alan('brut_kg').value), dara = sayi(alan('dara_kg').value);
        const net = brut - dara;
        netBox.value = fmt0(Math.max(0, net));
        let u = '';
        const daraAsti = brut > 0 && dara > brut;
        if (daraAsti) u = 'Dara, brüt ağırlıktan büyük olamaz.';
        else if (brut > 0) {
            const parti = alan('parti_no').value.trim();
            const ornek = parti ? Array.from(body.querySelectorAll('tr')).filter(r => (r.querySelector('[data-col="parti_no"]')?.dataset.value ?? '') === parti).map(r => parseDecimal(r.querySelector('[data-col="brut_kg"]')?.dataset.value)).filter(n => n > 0).slice(0, 30) : [];
            if (ornek.length >= 5) {
                const ort = ornek.reduce((s, n) => s + n, 0) / ornek.length;
                if (Math.abs(brut - ort) / ort > 0.4) u = `Dikkat: bu partinin ortalama brütü yaklaşık ${fmt0(ort)} kg; girilen değer çok farklı.`;
            }
        }
        uyari.textContent = u;
        uyari.classList.toggle('pf-uyari-hata', daraAsti);
        saveBtn.disabled = daraAsti;
    }
    ['brut_kg', 'parti_no'].forEach(n => alan(n).addEventListener('input', hesapla));
    ['palet', 'kasa', 'kasa_adeti'].forEach(n => alan(n).addEventListener('input', daraOtomatik));

    // ── Windows-tarzı yüzen pencere yönetimi (#pfDialog / #pfWinDock) ──
    const pfWinBar = document.getElementById('pfWinBar');
    const pfWinDock = document.getElementById('pfWinDock');
    const pfMaxBtn = dialog?.querySelector('[data-pf-win-max]');
    const pfDockTitle = document.getElementById('pfWinDockTitle');
    const PF_MIN_W = 380, PF_MIN_H = 340;
    let pfMinimized = false, pfMaximized = false, pfHasRect = false;
    let pfRect = { left: 0, top: 0, width: 0, height: 0 }, pfSavedRect = null;
    if (dialog && typeof dialog.open === 'undefined') {
        Object.defineProperty(dialog, 'open', { get() { return !dialog.hidden; } });
    }
    const pfIsMobile = () => window.innerWidth < 768;
    const pfApplyRect = r => {
        dialog.style.left = Math.round(r.left) + 'px';
        dialog.style.top = Math.round(r.top) + 'px';
        dialog.style.width = Math.round(r.width) + 'px';
        dialog.style.height = Math.round(r.height) + 'px';
    };
    const pfClearRect = () => { dialog.style.left = dialog.style.top = dialog.style.width = dialog.style.height = ''; };
    const pfClampRect = r => {
        const vw = window.innerWidth, vh = window.innerHeight;
        const minW = Math.min(PF_MIN_W, Math.max(320, vw - 16));
        const minH = Math.min(PF_MIN_H, Math.max(240, vh - 16));
        const w = Math.max(minW, Math.min(r.width, vw));
        const h = Math.max(minH, Math.min(r.height, vh));
        const left = Math.max(Math.min(0, 120 - w), Math.min(r.left, Math.max(0, vw - 120)));
        const top = Math.max(0, Math.min(r.top, Math.max(0, vh - 44)));
        return { left, top, width: w, height: h };
    };
    const pfCenterWin = () => {
        const vw = window.innerWidth, vh = window.innerHeight;
        const width = Math.min(960, Math.max(PF_MIN_W, vw - 40));
        const height = Math.min(Math.round(vh * 0.86), 640);
        pfRect = pfClampRect({ left: Math.round((vw - width) / 2), top: Math.round((vh - height) / 2), width, height });
        pfHasRect = true;
        pfApplyRect(pfRect);
    };
    const pfSyncMaxIcon = () => { if (pfMaxBtn) pfMaxBtn.textContent = pfMaximized ? '❐' : '□'; };
    function pfShowWin() {
        pfMinimized = false;
        if (pfWinDock) pfWinDock.hidden = true;
        dialog.hidden = false;
        if (pfIsMobile()) { pfClearRect(); }
        else if (pfMaximized) { dialog.classList.add('is-max'); pfClearRect(); }
        else { dialog.classList.remove('is-max'); if (!pfHasRect) pfCenterWin(); else { pfRect = pfClampRect(pfRect); pfApplyRect(pfRect); } }
        pfSyncMaxIcon();
    }
    function closePfWin() {
        dialog.hidden = true;
        pfMinimized = false;
        if (pfWinDock) pfWinDock.hidden = true;
        if (mod === 'duzelt') { mod = 'ekle'; duzenlenen = null; formSifirla(); }
    }
    function pfMinimizeWin() {
        if (!dialog.open) return;
        pfMinimized = true;
        dialog.hidden = true;
        if (pfWinDock) pfWinDock.hidden = false;
    }
    function pfToggleMax() {
        if (pfIsMobile()) return;
        if (pfMaximized) {
            pfMaximized = false;
            dialog.classList.remove('is-max');
            pfRect = pfClampRect(pfSavedRect || pfRect);
            pfApplyRect(pfRect);
        } else {
            pfSavedRect = { ...pfRect };
            pfMaximized = true;
            dialog.classList.add('is-max');
            pfClearRect();
        }
        pfSyncMaxIcon();
    }
    if (dialog) {
        dialog.close = closePfWin;
        dialog.querySelector('[data-pf-win-min]')?.addEventListener('click', pfMinimizeWin);
        pfMaxBtn?.addEventListener('click', pfToggleMax);
        pfWinDock?.querySelector('[data-pf-win-restore]')?.addEventListener('click', pfShowWin);
        pfWinDock?.querySelector('[data-pf-win-close]')?.addEventListener('click', closePfWin);
        pfWinBar?.addEventListener('dblclick', e => { if (!e.target.closest('button')) pfToggleMax(); });
        // Başlık çubuğundan sürükleme
        pfWinBar?.addEventListener('pointerdown', e => {
            if (e.button !== 0 || pfIsMobile() || e.target.closest('button')) return;
            e.preventDefault();
            if (pfMaximized) {
                const ratio = e.clientX / Math.max(1, window.innerWidth);
                const r = pfSavedRect || { width: Math.min(960, window.innerWidth - 40), height: Math.min(Math.round(window.innerHeight * 0.86), 640) };
                pfMaximized = false;
                dialog.classList.remove('is-max');
                pfRect = pfClampRect({ left: e.clientX - r.width * ratio, top: Math.max(0, e.clientY - 18), width: r.width, height: r.height });
                pfApplyRect(pfRect);
                pfSyncMaxIcon();
            }
            const sx = e.clientX, sy = e.clientY, orig = { ...pfRect };
            document.body.classList.add('mk-win-dragging');
            const onMove = ev => {
                pfRect = pfClampRect({ left: orig.left + (ev.clientX - sx), top: orig.top + (ev.clientY - sy), width: orig.width, height: orig.height });
                pfApplyRect(pfRect);
            };
            const onUp = () => {
                document.body.classList.remove('mk-win-dragging');
                window.removeEventListener('pointermove', onMove);
                window.removeEventListener('pointerup', onUp);
            };
            window.addEventListener('pointermove', onMove);
            window.addEventListener('pointerup', onUp);
        });
        // 8 yönlü boyutlandırma
        dialog.querySelectorAll('.mk-win-rh').forEach(rh => {
            rh.addEventListener('pointerdown', e => {
                if (e.button !== 0 || pfIsMobile() || pfMaximized) return;
                e.preventDefault();
                const dir = rh.dataset.dir || '';
                const sx = e.clientX, sy = e.clientY, orig = { ...pfRect };
                document.body.classList.add('mk-win-dragging');
                const onMove = ev => {
                    const dx = ev.clientX - sx, dy = ev.clientY - sy;
                    let { left, top, width, height } = orig;
                    if (dir.includes('e')) width = orig.width + dx;
                    if (dir.includes('s')) height = orig.height + dy;
                    if (dir.includes('w')) {
                        const nw = Math.max(PF_MIN_W, orig.width - dx);
                        left = orig.left + (orig.width - nw);
                        width = nw;
                    }
                    if (dir.includes('n')) {
                        const nh = Math.max(PF_MIN_H, orig.height - dy);
                        top = Math.max(0, orig.top + (orig.height - nh));
                        height = orig.top + orig.height - top;
                    }
                    pfRect = pfClampRect({ left, top, width, height });
                    pfApplyRect(pfRect);
                };
                const onUp = () => {
                    document.body.classList.remove('mk-win-dragging');
                    window.removeEventListener('pointermove', onMove);
                    window.removeEventListener('pointerup', onUp);
                };
                window.addEventListener('pointermove', onMove);
                window.addEventListener('pointerup', onUp);
            });
        });
        window.addEventListener('resize', () => {
            if (!dialog.open) return;
            if (pfIsMobile()) pfClearRect();
            else if (!pfMaximized) { pfRect = pfClampRect(pfRect); pfApplyRect(pfRect); }
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && dialog.open && !dialog.querySelector('.pf-cb-list:not([hidden])')) {
                e.preventDefault();
                closePfWin();
            }
        });
    }

    let mod = 'ekle';          // 'ekle' | 'duzelt'
    let duzenlenen = null;     // Düzelt modunda satır (tr)
    let orijinal = {};         // Düzelt: değişmemiş müşteri/ürün kayıtlı tanım olmasa da kabul edilir (eski serbest metin kayıtlar)
    const baslikEl = document.getElementById('pfWinTitle');
    function bugun() { const d = new Date(); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); }
    function formSifirla() {
        form.reset();
        alan('tarih').value = bugun();
        form.querySelectorAll('input[data-cb]').forEach(i => { delete i.dataset.id; });
        alan('belge_no').value = String(nextBelge);
        mesaj(''); daraOtomatik();
    }
    function formDoldur(tr) {
        const c = n => tr.querySelector(`[data-col="${n}"]`);
        const v = n => c(n)?.dataset.value ?? '';
        const kg = n => { const x = parseDecimal(v(n)); return x ? String(x) : ''; };
        const ata = (name, text, id) => { alan(name).value = text; if (id) alan(name).dataset.id = id; else delete alan(name).dataset.id; };
        alan('tarih').value = tr.dataset.tarih || bugun();
        alan('belge_no').value = v('belge_no');
        alan('parti_no').value = v('parti_no');
        const paletId = v('palet_adi');
        ata('palet', paletId ? (c('palet_adi')?.textContent || '') : '', paletId);
        ata('firma', v('firma'), 0);
        ata('urun', tr.dataset.urun || '', 0);
        const kasaId = Number(tr.dataset.kasaId || 0);
        ata('kasa', kasaId ? ((L().kasa || []).find(x => x.id === kasaId)?.name || '') : '', kasaId);
        alan('kasa_adeti').value = String(parseDecimal(v('kasa_adeti')) || 0);
        const mustId = Number(tr.dataset.mustId || 0);
        ata('mustahsil', mustId ? v('mustahsil_ad') : '', mustId);
        alan('brut_kg').value = kg('brut_kg'); alan('dara_kg').value = kg('dara_kg') || '0';
        alan('aciklama1').value = v('aciklama1'); alan('aciklama2').value = v('aciklama2');
        orijinal = { firma: v('firma'), urun: tr.dataset.urun || '' };
        birimGoster();
        hesapla();
    }
    function openDialog(istenen) {
        if (!dialog) return;
        if (dialog.open) return;
        if (!izin(istenen === 'duzelt' ? 'Duzelt' : 'Ekle')) { setStatus('Bu işlem için yetkiniz yok.'); return; }
        mod = istenen === 'duzelt' ? 'duzelt' : 'ekle';
        if (mod === 'duzelt') {
            const tr = seciliSatir();
            if (!tr) { setStatus('Önce düzeltilecek satırı seçin.'); mod = 'ekle'; return; }
            duzenlenen = tr;
            formDoldur(tr);
            if (baslikEl) baslikEl.textContent = 'Palet Fişi Düzelt';
            if (pfDockTitle) pfDockTitle.textContent = 'Palet Fişi Düzelt';
            saveBtn.innerHTML = '✓ Güncelle <span class="pf-shortcut">F10</span>';
        } else {
            duzenlenen = null;
            if (baslikEl) baslikEl.textContent = 'Palet Fişi Ekle';
            if (pfDockTitle) pfDockTitle.textContent = 'Palet Fişi Ekle';
            saveBtn.innerHTML = '✓ Kaydet <span class="pf-shortcut">F10</span>';
            alan('belge_no').value = String(nextBelge);
        }
        mesaj('');
        pfShowWin();
        const hazir = alan('parti_no').value && alan('firma').value;
        const hedef = (mod === 'duzelt' || hazir) ? alan('brut_kg') : alan('parti_no');
        hedef.focus(); if (hedef.select) hedef.select();
    }
    document.getElementById('pfAdd')?.addEventListener('click', () => openDialog('ekle'));
    document.addEventListener('keydown', event => {
        if (event.key === 'F2' && !dialog.open && !event.target.matches('input,textarea,select')) { event.preventDefault(); openDialog('ekle'); }
    });
    document.getElementById('pfClose').addEventListener('click', closePfWin);
    document.getElementById('pfCancel').addEventListener('click', closePfWin);

    // Listede yok mu? Tam eşleşme varsa id'yi bul; yoksa hata (kullanıcı "+ ekle" ile tanımlasın).
    function coz(name, zorunlu, etiket) {
        const input = alan(name);
        const text = input.value.trim();
        if (!text) { if (zorunlu) throw new Error(`${etiket} zorunludur.`); return null; }
        if (input.dataset.id) return { id: Number(input.dataset.id), name: text };
        if (mod === 'duzelt' && orijinal[name] !== undefined && trUp(orijinal[name]) === trUp(text)) return { id: 0, name: text };
        const bulunan = (L()[input.dataset.cb] || []).find(x => trUp(x.name) === trUp(text));
        if (bulunan) { input.dataset.id = bulunan.id; input.value = bulunan.name; return { id: bulunan.id, name: bulunan.name }; }
        throw new Error(`${etiket}: “${text}” kayıtlı değil. Listeden seçin ya da “+ ekle” ile tanımlayın.`);
    }
    function trTarih(iso) { const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || '')); return m ? `${m[3]}.${m[2]}.${m[1]}` : ''; }
    function degerler(r) {
        return { belge_no: [r.belge_no, r.belge_no], parti_no: [r.parti_no, r.parti_no], palet_adi: [r.palet_tipi_id || '', r.palet_adi], firma: [r.firma, r.firma], kasa_adi: [r.kasa_adi, r.kasa_adi],
            kasa_adeti: [r.kasa_adeti, fmt0(r.kasa_adeti)], brut_kg: [r.brut_kg, fmt0(r.brut_kg)], dara_kg: [r.dara_kg, fmt0(r.dara_kg)], net_kg: [r.net_kg, fmt0(r.net_kg)],
            mustahsil_ad: [r.mustahsil_ad, r.mustahsil_ad], aciklama1: [r.aciklama1, r.aciklama1], aciklama2: [r.aciklama2, r.aciklama2],
            tarih: [trTarih(r.tarih), trTarih(r.tarih)], islem: [r.islem, r.islem], islem_zaman: [r.islem_zaman, r.islem_zaman], kullanici: [r.kullanici, r.kullanici], sube: [r.sube, r.sube] };
    }
    function satirOznitelik(tr, r) {
        tr.dataset.id = r.id; tr.dataset.recordId = r.loading_record_id;
        tr.dataset.urun = r.urun || ''; tr.dataset.tarih = r.tarih || ''; tr.dataset.kasaId = r.kasa_cinsi_id || 0; tr.dataset.mustId = r.mustahsil_id || 0; tr.dataset.kilit = r.kilit ? 1 : 0;
        tr.dataset.search = [r.belge_no, r.parti_no, r.palet_adi, r.firma, r.urun].join(' ').toLocaleLowerCase('tr-TR');
    }
    function satirEkle(r) {
        const tr = document.createElement('tr');
        satirOznitelik(tr, r);
        const deger = degerler(r);
        Array.from(table.tHead.rows[0].children).forEach(th => {
            const col = th.dataset.col; const [raw, shown] = deger[col] || ['', ''];
            const td = document.createElement('td');
            td.className = 'pf-cell' + (['kasa_adeti', 'brut_kg', 'dara_kg', 'net_kg'].includes(col) ? ' pf-num' : '');
            td.dataset.col = col; td.dataset.value = String(raw ?? ''); td.textContent = String(shown ?? '');
            tr.appendChild(td);
        });
        body.insertBefore(tr, body.firstChild);
        empty.hidden = true;
        sayacDegis(1);
        selectCell(ilkHucre(tr));
        wrap.scrollTop = 0;
    }
    function satirGuncelle(tr, r) {
        satirOznitelik(tr, r);
        const deger = degerler(r);
        tr.querySelectorAll('td.pf-cell').forEach(td => { const d = deger[td.dataset.col]; if (d) { td.dataset.value = String(d[0] ?? ''); td.textContent = String(d[1] ?? ''); } });
        selectCell(ilkHucre(tr));
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
            if (mod === 'duzelt') {
                kaydediyor = true; saveBtn.disabled = true;
                const sonuc = await send('save', { id: duzenlenen.dataset.id, tam: 1, tarih: alan('tarih').value, parti_no: trUp(alan('parti_no').value), firma: firma.name, urun: urun.name,
                    palet_tipi_id: palet ? palet.id : '', kasa_cinsi_id: kasa ? kasa.id : '', mustahsil_id: mustahsil ? mustahsil.id : '', mustahsil_duzenle: alan('mustahsil').disabled ? '' : 1,
                    kasa_adeti: alan('kasa_adeti').value || 0, brut_kg: brut, dara_kg: dara, aciklama1: alan('aciklama1').value, aciklama2: alan('aciklama2').value });
                satirGuncelle(duzenlenen, sonuc.row);
                setStatus(`${sonuc.row.belge_no} numaralı palet fişi güncellendi.`);
                dialog.close();
                return;
            }
            kaydediyor = true; saveBtn.disabled = true;
            const r = await send('create', { tarih: alan('tarih').value, parti_no: trUp(alan('parti_no').value), firma: firma.name, urun: urun.name,
                palet_tipi_id: palet ? palet.id : '', kasa_cinsi_id: kasa ? kasa.id : '', mustahsil_id: mustahsil ? mustahsil.id : '', kasa_adeti: alan('kasa_adeti').value || 0, brut_kg: brut, dara_kg: dara,
                aciklama1: alan('aciklama1').value, aciklama2: alan('aciklama2').value });
            satirEkle(r.row);
            const parti = trUp(alan('parti_no').value);
            alan('parti_no').value = parti;
            if (parti && !(L().parti || []).some(x => trUp(x.name) === trUp(parti))) (L().parti = L().parti || []).unshift({ id: 0, name: parti });
            nextBelge = r.next_belge; alan('belge_no').value = String(nextBelge);
            ['brut_kg', 'aciklama1', 'aciklama2', 'mustahsil'].forEach(n => { alan(n).value = ''; });
            delete alan('mustahsil').dataset.id;   // müstahsil palet başınadır, bir sonrakine taşınmaz
            daraOtomatik();   // palet/kasa/adet korunur → dara aynı kalır
            mesaj(`${r.row.belge_no} numaralı belge kaydedildi.`, true);
            alan('brut_kg').focus();
        } catch (error) { mesaj(error.message); }
        finally { kaydediyor = false; hesapla(); }
    }
    form.addEventListener('submit', event => { event.preventDefault(); submitForm(); });

    // ───────── Bul: seçili hücrenin SÜTUNUNDA filtre (Boşluk tuşu ya da Bul düğmesi) ─────────
    const bulDialog = document.getElementById('pfBulDialog');
    const bulMod = document.getElementById('pfBulMod');
    const bulMetin = document.getElementById('pfBulMetin');
    const filtreKutu = document.getElementById('pfFiltreler');
    const MOD_ADI = { basla: 'Başlayan', icinde: 'İçinde geçen', esit: 'Eşit Olan', basla_degil: 'İle Başlamayan', icinde_degil: 'İçinde Geçmeyen', akilli: 'Akıllı arama', bos: 'Boş Olan', dolu: 'Dolu Olan', esit_degil: 'Eşit Olmayan' };
    const filtreler = new Map();      // sütun → { mod, metin }  (her sütunda bir filtre; sütunlar birlikte uygulanır)
    const kucuk = v => String(v ?? '').trim().toLocaleLowerCase('tr-TR');
    function filtreEslesir(deger, f) {
        const d = kucuk(deger), m = kucuk(f.metin);
        switch (f.mod) {
            case 'basla': return d.startsWith(m);
            case 'icinde': return d.includes(m);
            case 'esit': return d === m;
            case 'basla_degil': return !d.startsWith(m);
            case 'icinde_degil': return !d.includes(m);
            case 'akilli': return m.split(/\s+/).filter(Boolean).every(k => d.includes(k));   // yazılan her sözcük, sırası önemsiz
            case 'bos': return d === '';
            case 'dolu': return d !== '';
            case 'esit_degil': return d !== m;
            default: return true;
        }
    }
    function sutunAdi(col) { return table.tHead.rows[0].querySelector(`th[data-col="${col}"]`)?.childNodes[0]?.textContent.trim() || col; }
    function filtreUygula() {
        let say = 0;
        body.querySelectorAll('tr').forEach(row => {
            let gorunur = true;
            for (const [col, f] of filtreler) { if (!filtreEslesir(row.querySelector(`[data-col="${col}"]`)?.textContent, f)) { gorunur = false; break; } }
            row.hidden = !gorunur;
            if (gorunur) say++;
        });
        const sayac = document.getElementById('pfCount'); if (sayac) sayac.textContent = say.toLocaleString('tr-TR');
        empty.hidden = say > 0;
        if (!say) empty.textContent = filtreler.size ? 'Filtreye uyan kayıt yok. Filtreyi kaldırmak için alttaki × işaretine basın.' : 'Seçilen filtreye uyan palet fişi yok.';
        // Göstergeler (× ile tek tek kaldırılır)
        if (filtreKutu) {
            filtreKutu.innerHTML = ''; filtreKutu.hidden = !filtreler.size;
            filtreler.forEach((f, col) => {
                const chip = document.createElement('span'); chip.className = 'pf-filtre-chip';
                const bos = f.mod === 'bos' || f.mod === 'dolu';
                chip.textContent = `${sutunAdi(col)}: ${MOD_ADI[f.mod]}${bos ? '' : ' “' + f.metin + '”'} `;
                const x = document.createElement('button'); x.type = 'button'; x.textContent = '×'; x.title = 'Filtreyi kaldır'; x.setAttribute('aria-label', `${sutunAdi(col)} filtresini kaldır`);
                x.addEventListener('click', () => { filtreler.delete(col); filtreUygula(); });
                chip.appendChild(x); filtreKutu.appendChild(chip);
            });
        }
        const gorunen = body.querySelector('tr:not([hidden])');
        if (selectedCell && selectedCell.parentElement.hidden && gorunen) selectCell(gorHuc(gorunen)[gorHuc(selectedCell.parentElement).indexOf(selectedCell)] || ilkHucre(gorunen));
    }
    let bulSutun = 'parti_no';
    function bulAc() {
        if (!bulDialog || bulDialog.open) return;
        bulSutun = selectedCell?.dataset.col || 'parti_no';
        const var_ = filtreler.get(bulSutun);
        document.getElementById('pfBulBaslik').textContent = sutunAdi(bulSutun);
        bulMod.value = var_ ? var_.mod : 'akilli';   // her açılışta Akıllı arama (ters anlamlı biçimler yanlışlıkla kalıcı olmasın)
        bulMetin.value = var_ ? var_.metin : '';
        bulMetinDurumu();
        bulDialog.showModal();
        window.setTimeout(() => { (bulMetin.disabled ? bulMod : bulMetin).focus(); bulMetin.select(); }, 30);
    }
    function bulMetinDurumu() { const metinsiz = bulMod.value === 'bos' || bulMod.value === 'dolu'; bulMetin.disabled = metinsiz; if (metinsiz) bulMetin.value = ''; }
    bulMod.addEventListener('change', () => { bulMetinDurumu(); if (!bulMetin.disabled) bulMetin.focus(); });
    document.getElementById('pfBulIptal').addEventListener('click', () => bulDialog.close());
    // Enter seçim kutusundayken de uygular (tarayıcı varsayılanı seçim kutusunda formu göndermez; Boş/Dolu biçiminde metin kutusu kapalıdır).
    bulDialog.addEventListener('keydown', event => { if (event.key === 'Enter' && event.target.tagName !== 'BUTTON') { event.preventDefault(); document.getElementById('pfBulForm').requestSubmit(); } });
    document.getElementById('pfBulForm').addEventListener('submit', event => {
        event.preventDefault();
        const mod = bulMod.value, metin = bulMetin.value.trim();
        if ((mod === 'bos' || mod === 'dolu') || metin !== '') filtreler.set(bulSutun, { mod, metin });
        else filtreler.delete(bulSutun);        // metin boş + metin gerektiren biçim = bu sütunun filtresini kaldır
        bulDialog.close();
        filtreUygula();
        setStatus(filtreler.size ? `${sutunAdi(bulSutun)} sütununda filtre uygulandı.` : 'Filtre kaldırıldı.');
        wrap.focus({ preventScroll: true });
    });
    // Boşluk tuşu: Bul penceresi (yazı alanında/düğmede/pencere açıkken değil)
    document.addEventListener('keydown', event => {
        if (event.key !== ' ' || event.ctrlKey || event.altKey || event.metaKey || event.repeat) return;
        if (document.querySelector('dialog[open]')) return;
        if (event.target.closest?.('input,select,textarea,button,a,summary,[contenteditable="true"]')) return;
        event.preventDefault();
        bulAc();
    });

    // ───────── Parti palet listesi (alttaki kartlara tıklayınca) ─────────
    const ozetDialog = document.getElementById('pfOzetDialog');
    function satirDeger(row, name) { return row.querySelector(`[data-col="${name}"]`)?.dataset.value ?? ''; }
    function partiListesiAc() {
        if (!ozetDialog) return;
        const row = seciliSatir();
        if (!row) { setStatus('Önce bir satır seçin.'); return; }
        const parti = satirDeger(row, 'parti_no');
        const satirlar = Array.from(body.querySelectorAll('tr')).filter(r => satirDeger(r, 'parti_no') === parti);
        const kolonlar = ['belge_no', 'palet_adi', 'firma', 'kasa_adi', 'kasa_adeti', 'brut_kg', 'dara_kg', 'net_kg', 'mustahsil_ad']
            .filter(c => !gizli.has(c) && table.tHead.rows[0].querySelector(`th[data-col="${c}"]`));
        const sayisal = ['kasa_adeti', 'brut_kg', 'dara_kg', 'net_kg'];
        const ad = c => table.tHead.rows[0].querySelector(`th[data-col="${c}"]`).childNodes[0]?.textContent.trim() || c;
        const hr = ozetDialog.querySelector('thead tr'), tb = ozetDialog.querySelector('tbody'), tf = ozetDialog.querySelector('tfoot');
        hr.innerHTML = ''; tb.innerHTML = ''; tf.innerHTML = '';
        kolonlar.forEach(c => { const th = document.createElement('th'); th.textContent = ad(c); hr.appendChild(th); });
        const T = { kasa_adeti: 0, brut_kg: 0, dara_kg: 0, net_kg: 0 };
        satirlar.forEach(r => {
            const tr = document.createElement('tr'); tr.tabIndex = 0; tr.dataset.id = r.dataset.id;
            kolonlar.forEach(c => {
                const td = document.createElement('td'); td.textContent = r.querySelector(`[data-col="${c}"]`)?.textContent.trim() || '';
                if (sayisal.includes(c)) { td.className = 'pf-num'; T[c] += parseDecimal(satirDeger(r, c)); }
                tr.appendChild(td);
            });
            tb.appendChild(tr);
        });
        const ft = document.createElement('tr');
        kolonlar.forEach((c, i) => { const td = document.createElement('td'); if (sayisal.includes(c)) { td.className = 'pf-num'; td.textContent = fmt0(T[c]); } else if (i === 0) td.textContent = `Toplam (${satirlar.length.toLocaleString('tr-TR')} palet)`; ft.appendChild(td); });
        tf.appendChild(ft);
        ozetDialog.querySelector('#pfOzetBaslik').textContent = `Parti: ${parti || '—'}`;
        ozetDialog.querySelector('#pfOzetAlt').textContent = `${satirlar.length.toLocaleString('tr-TR')} palet · Bir satıra tıklarsanız listede o palete gidilir.`;
        ozetDialog.showModal();
    }
    function paleteGit(tr) {
        if (!tr || !tr.dataset.id) return;
        const hedef = Array.from(body.querySelectorAll('tr')).find(r => r.dataset.id === tr.dataset.id);
        ozetDialog.close();
        if (hedef) { hedef.hidden = false; selectCell(ilkHucre(hedef)); hedef.scrollIntoView({ block: 'center' }); }
    }
    ozetDialog?.querySelector('tbody').addEventListener('click', event => paleteGit(event.target.closest('tr')));
    ozetDialog?.querySelector('tbody').addEventListener('keydown', event => { if (event.key === 'Enter') paleteGit(event.target.closest('tr')); });
    document.getElementById('pfOzetKapat')?.addEventListener('click', () => ozetDialog.close());
    document.querySelectorAll('[data-pf-liste]').forEach(kart => {
        kart.addEventListener('click', partiListesiAc);
        kart.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); partiListesiAc(); } });
    });

    // ───────── Yazdır (görünen liste; gizli çerçeve ile, açılır pencere yok) ─────────
    const esc = v => String(v ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    function yazdir() {
        const basliklar = Array.from(table.tHead.rows[0].children).filter(th => !gizli.has(th.dataset.col));
        const satirlar = Array.from(body.querySelectorAll('tr')).filter(r => !r.hidden);
        if (!satirlar.length) { setStatus('Yazdırılacak kayıt yok.'); return; }
        const sayisal = ['kasa_adeti', 'brut_kg', 'dara_kg', 'net_kg'];
        const toplam = { kasa_adeti: 0, brut_kg: 0, dara_kg: 0, net_kg: 0 };
        let govde = '';
        satirlar.forEach(r => {
            govde += '<tr>' + basliklar.map(th => { const col = th.dataset.col; const td = r.querySelector(`[data-col="${col}"]`); if (col in toplam) toplam[col] += parseDecimal(td?.dataset.value); return `<td${sayisal.includes(col) ? ' class="n"' : ''}>${esc(td?.textContent)}</td>`; }).join('') + '</tr>';
        });
        const alt = '<tr class="t">' + basliklar.map((th, i) => { const col = th.dataset.col; return col in toplam ? `<td class="n">${fmt0(toplam[col])}</td>` : `<td>${i === 0 ? 'TOPLAM' : ''}</td>`; }).join('') + '</tr>';
        const veri = window.PALET_FISLERI || {};
        const bas = veri.baslangic ? veri.baslangic.split('-').reverse().join('.') : '';
        const html = `<!doctype html><html lang="tr"><head><meta charset="utf-8"><title>Palet Fişleri</title><style>
@page{size:A4 landscape;margin:10mm}body{font:11px/1.35 system-ui,Arial,sans-serif;color:#111}h1{font-size:16px;margin:0 0 2px}p{margin:0 0 8px;color:#555}
table{width:100%;border-collapse:collapse}th,td{border:1px solid #999;padding:3px 5px;text-align:left;white-space:nowrap}th{background:#e8eef5;text-align:center}thead{display:table-header-group}tr{break-inside:avoid}
td.n{text-align:right}tr.t td{font-weight:700;background:#f1f5f9}</style></head><body><h1>Palet Fişleri</h1>
<p>${bas ? 'Başlangıç: ' + bas + ' · ' : ''}${veri.yuklenen ? 'Yüklenen paletler dahil' : 'Yüklenen paletler hariç'} · ${satirlar.length.toLocaleString('tr-TR')} kayıt · ${new Date().toLocaleString('tr-TR')}</p>
<table><thead><tr>${basliklar.map(th => `<th>${esc(th.textContent)}</th>`).join('')}</tr></thead><tbody>${govde}${alt}</tbody></table></body></html>`;
        const cerceve = document.createElement('iframe');
        cerceve.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
        cerceve.srcdoc = html;
        cerceve.onload = () => { try { cerceve.contentWindow.focus(); cerceve.contentWindow.print(); } finally { setTimeout(() => cerceve.remove(), 60000); } };
        document.body.appendChild(cerceve);
    }

    // ───────── Araç çubuğu ─────────
    const araclar = { coglat, sil, bul: bulAc, duzelt: () => openDialog('duzelt'), yazdir };
    document.querySelectorAll('[data-pf]').forEach(btn => btn.addEventListener('click', () => araclar[btn.dataset.pf]?.()));

    // ───────── Sütun sırası + genişliği (kullanıcı bazında sunucuda; tablo kurulu değilse bu tarayıcıda) ─────────
    const TERCIH_ANAHTAR = 'palet_fisleri.tablo';
    const YEREL_ANAHTAR = 'asya.palet-fisleri.tablo.v2';
    const tercihSunucu = !!window.PALET_FISLERI?.tercihSunucu;
    const VARSAYILAN_GEN = { belge_no: 80, tarih: 90, islem: 100, islem_zaman: 130, kullanici: 130, sube: 130, parti_no: 130, palet_adi: 150, firma: 200, kasa_adi: 130, kasa_adeti: 70, brut_kg: 80, dara_kg: 70, net_kg: 80, mustahsil_ad: 150, aciklama1: 150, aciklama2: 150 };
    const headers = table.tHead.rows[0];
    let tercih = { genislik: {}, sira: [], gizli: [] };
    (function tercihYukle() {
        let ham = tercihSunucu ? window.PALET_FISLERI?.tercih : null;
        if (!tercihSunucu) { try { ham = JSON.parse(localStorage.getItem(YEREL_ANAHTAR) || 'null'); } catch (_) { ham = null; } }
        if (ham && typeof ham === 'object') tercih = { genislik: ham.genislik || {}, sira: Array.isArray(ham.sira) ? ham.sira : [], gizli: Array.isArray(ham.gizli) ? ham.gizli : [] };
    })();
    let tercihZamani = 0;
    function tercihKaydet() {
        clearTimeout(tercihZamani);
        tercihZamani = window.setTimeout(async () => {
            if (!tercihSunucu) { try { localStorage.setItem(YEREL_ANAHTAR, JSON.stringify(tercih)); } catch (_) { /* depolama kapalı */ } return; }
            try { await send('tercih_kaydet', { anahtar: TERCIH_ANAHTAR, deger: JSON.stringify(tercih) }); }
            catch (error) { setStatus('Sütun ayarı kaydedilemedi: ' + error.message); }
        }, 500);
    }
    function moveColumn(from, to) {
        if (from === to || from < 0 || to < 0) return;
        table.querySelectorAll('tr').forEach(row => {
            const items = Array.from(row.children);
            const item = items[from];
            const ref = items[to];
            if (item && ref) row.insertBefore(item, from < to ? ref.nextSibling : ref);
        });
    }
    function siraKaydet() { tercih.sira = Array.from(headers.children).map(th => th.dataset.col); tercihKaydet(); }
    function siraUygula() {
        const mevcut = Array.from(headers.children).map(th => th.dataset.col);
        const hedef = tercih.sira.filter(c => mevcut.includes(c)).concat(mevcut.filter(c => !tercih.sira.includes(c)));
        hedef.forEach((col, i) => {
            const simdi = Array.from(headers.children).findIndex(th => th.dataset.col === col);
            if (simdi !== i) moveColumn(simdi, i);
        });
    }
    const KOSE = 0;   // sol şerit kaldırıldı
    function genislikUygula() {
        let toplam = 0;
        Array.from(headers.children).forEach(th => {
            if (gizli.has(th.dataset.col)) return;
            const w = tercih.genislik[th.dataset.col] || 120;
            th.style.width = th.style.minWidth = th.style.maxWidth = w + 'px';
            toplam += w;
        });
        table.style.width = toplam + 'px';
    }
    // İlk açılış (kayıtlı genişlik yoksa): varsayılanlar, boş alanı dolduracak şekilde ölçeklenir.
    function varsayilanGenislikler() {
        const cols = Array.from(headers.children).map(th => th.dataset.col).filter(c => !gizli.has(c));
        const toplam = cols.reduce((s, c) => s + (VARSAYILAN_GEN[c] || 120), 0);
        const oran = Math.max(1, (wrap.clientWidth - KOSE - 24) / toplam);
        cols.forEach(c => { if (!tercih.genislik[c]) tercih.genislik[c] = Math.round((VARSAYILAN_GEN[c] || 120) * oran); });
    }
    // İçeriğe göre daralt: sütundaki en uzun yazı (başlık dahil) kadar.
    const olcum = document.createElement('canvas').getContext('2d');
    function icerikGenisligi(col) {
        const th = headers.querySelector(`th[data-col="${col}"]`);
        const ornek = body.querySelector(`td[data-col="${col}"]`);
        const stilT = getComputedStyle(th), stilH = ornek ? getComputedStyle(ornek) : stilT;
        let en = 0;
        olcum.font = `${stilT.fontWeight} ${stilT.fontSize} ${stilT.fontFamily}`;
        en = olcum.measureText(th.childNodes[0]?.textContent || '').width + 30;
        olcum.font = `${stilH.fontWeight} ${stilH.fontSize} ${stilH.fontFamily}`;
        body.querySelectorAll(`td[data-col="${col}"]`).forEach(td => { en = Math.max(en, olcum.measureText(td.textContent).width + 22); });
        return Math.max(44, Math.min(1200, Math.ceil(en)));
    }
    function hepsiniDaralt() {
        Array.from(headers.children).forEach(th => { tercih.genislik[th.dataset.col] = icerikGenisligi(th.dataset.col); });
        genislikUygula(); tercihKaydet();
        setStatus('Sütunlar içeriğe göre daraltıldı.');
    }
    // Başlık kenarından sürükle (Excel gibi); sütun birleşimine (kenara) çift tık: TÜM sütunları içeriğe göre daralt.
    Array.from(headers.children).forEach(th => {
        const tutac = document.createElement('i'); tutac.className = 'pf-rs'; tutac.title = 'Sürükleyin: genişlik · Çift tık: tüm sütunları içeriğe göre daralt'; th.appendChild(tutac);
        tutac.addEventListener('dblclick', event => { event.stopPropagation(); hepsiniDaralt(); });
        tutac.addEventListener('pointerdown', event => {
            if (event.button !== 0) return;
            event.preventDefault(); event.stopPropagation();
            const x0 = event.clientX, w0 = th.getBoundingClientRect().width, col = th.dataset.col;
            tutac.setPointerCapture(event.pointerId); document.body.classList.add('pf-boyutlaniyor');
            const hareket = e => { tercih.genislik[col] = Math.max(44, Math.min(1200, Math.round(w0 + e.clientX - x0))); genislikUygula(); };
            const bitti = () => { tutac.removeEventListener('pointermove', hareket); tutac.removeEventListener('pointerup', bitti); tutac.removeEventListener('pointercancel', bitti); document.body.classList.remove('pf-boyutlaniyor'); tercihKaydet(); };
            tutac.addEventListener('pointermove', hareket); tutac.addEventListener('pointerup', bitti); tutac.addEventListener('pointercancel', bitti);
        });
    });
    // ── Sütun görünürlüğü: başlığa çift tık → Sütunlar penceresi (aç/kapa, kullanıcı bazlı kayıt) ──
    const sutunDialog = document.getElementById('pfSutunDialog');
    const sutunListe = document.getElementById('pfSutunListe');
    const gizliStil = document.head.appendChild(document.createElement('style'));
    function gizliUygula() {
        gizliStil.textContent = Array.from(gizli).map(c => `#pfTable [data-col="${c}"]{display:none}`).join('');
        genislikUygula();
        if (selectedCell && gizli.has(selectedCell.dataset.col)) selectCell(ilkHucre(selectedCell.parentElement));
    }
    const mevcutSutunlar = () => Array.from(headers.children).map(th => th.dataset.col);
    (tercih.gizli || []).filter(c => mevcutSutunlar().includes(c)).forEach(c => gizli.add(c));
    if (gizli.size >= mevcutSutunlar().length) gizli.clear();
    function sutunListeCiz() {
        sutunListe.innerHTML = '';
        const toplam = mevcutSutunlar().length;
        Array.from(headers.children).forEach(th => {
            const col = th.dataset.col;
            const satir = document.createElement('label'); satir.className = 'pf-sutun-satir';
            const kutu = document.createElement('input'); kutu.type = 'checkbox'; kutu.checked = !gizli.has(col);
            kutu.disabled = kutu.checked && toplam - gizli.size <= 1;   // en az bir sütun görünür kalır
            kutu.addEventListener('change', () => {
                if (kutu.checked) gizli.delete(col); else gizli.add(col);
                tercih.gizli = Array.from(gizli); gizliUygula(); tercihKaydet(); sutunListeCiz();
            });
            const ad = document.createElement('span'); ad.textContent = th.childNodes[0]?.textContent.trim() || col;
            satir.append(kutu, ad); sutunListe.appendChild(satir);
        });
    }
    function sutunPenceresiAc() { if (!sutunDialog || sutunDialog.open) return; sutunListeCiz(); sutunDialog.showModal(); sutunListe.querySelector('input:not(:disabled)')?.focus(); }
    headers.addEventListener('dblclick', event => { if (event.target.closest('.pf-rs')) return; event.preventDefault(); sutunPenceresiAc(); });
    document.getElementById('pfSutunTamam')?.addEventListener('click', () => sutunDialog.close());
    document.getElementById('pfSutunKapat')?.addEventListener('click', () => sutunDialog.close());
    document.getElementById('pfSutunHepsi')?.addEventListener('click', () => { gizli.clear(); tercih.gizli = []; gizliUygula(); tercihKaydet(); sutunListeCiz(); });
    siraUygula();
    varsayilanGenislikler();
    gizliUygula();

    // Sütun sırası: başlığı tutup sürükle. Sürüklerken yarı saydam bir "hayalet" başlık imleci izler, sütun soluklaşır,
    // bırakılacak yer mavi çizgiyle gösterilir; sütun yalnız BIRAKINCA taşınır (Esc: vazgeç). Fare: 6px hareketle başlar;
    // dokunmatik: kısa basılı tutma (kaydırmayla karışmasın).
    let surukle = null;   // { th, from, ghost, cizgi, sinir, x, raf }
    function suruklemeBaslat(th, event) {
        const r = th.getBoundingClientRect();
        const ghost = document.createElement('div');
        ghost.className = 'pf-hayalet'; ghost.textContent = th.childNodes[0]?.textContent || '';
        ghost.style.width = r.width + 'px'; ghost.style.height = r.height + 'px';
        const cizgi = document.createElement('div'); cizgi.className = 'pf-birak';
        document.body.append(ghost, cizgi);
        document.body.classList.add('pf-suruklen');
        const col = th.dataset.col;
        th.classList.add('pf-drag-armed');
        body.querySelectorAll(`td[data-col="${col}"]`).forEach(td => td.classList.add('pf-kol-soluk'));
        surukle = { th, from: Array.from(headers.children).indexOf(th), ghost, cizgi, sinir: 0, dx: event.clientX - r.left, x: event.clientX, raf: 0 };
        setStatus('Sütun taşınıyor — bırakılacak yeri mavi çizgi gösterir, Esc: vazgeç.');
        suruklemeKonum(event.clientX, event.clientY);
        surukleKaydir();
    }
    function suruklemeKonum(x, y) {
        const d = surukle; if (!d) return;
        d.x = x;
        d.ghost.style.left = (x - d.dx) + 'px'; d.ghost.style.top = (headers.getBoundingClientRect().top) + 'px';
        const tum = Array.from(headers.children);
        const gor = tum.filter(t => !gizli.has(t.dataset.col)).map(t => ({ i: tum.indexOf(t), r: t.getBoundingClientRect() }));   // gizli sütunlar sayılmaz
        let b = gor[gor.length - 1].i + 1, cx = gor[gor.length - 1].r.right;
        for (const g of gor) { if (x < g.r.left + g.r.width / 2) { b = g.i; cx = g.r.left; break; } }
        d.sinir = b;
        const w = wrap.getBoundingClientRect();
        d.cizgi.style.left = (Math.max(w.left, Math.min(w.right - 3, cx)) - 1) + 'px';
        d.cizgi.style.top = w.top + 'px'; d.cizgi.style.height = wrap.clientHeight + 'px';
    }
    function surukleKaydir() {   // kenara yaklaşınca tablo yatay kayar
        const d = surukle; if (!d) return;
        const w = wrap.getBoundingClientRect();
        if (d.x > w.right - 50) wrap.scrollLeft += 14; else if (d.x < w.left + 50) wrap.scrollLeft -= 14;
        suruklemeKonum(d.x, 0);
        d.raf = requestAnimationFrame(surukleKaydir);
    }
    function suruklemeBitir(uygula) {
        const d = surukle; if (!d) return;
        cancelAnimationFrame(d.raf); surukle = null;
        d.ghost.remove(); d.cizgi.remove();
        document.body.classList.remove('pf-suruklen');
        d.th.classList.remove('pf-drag-armed');
        body.querySelectorAll('.pf-kol-soluk').forEach(td => td.classList.remove('pf-kol-soluk'));
        const hedef = d.sinir > d.from ? d.sinir - 1 : d.sinir;
        if (uygula && hedef !== d.from) { moveColumn(d.from, hedef); siraKaydet(); setStatus('Sütun sırası kaydedildi.'); }
        else setStatus(uygula ? '' : 'Sütun taşıma vazgeçildi.');
    }
    headers.addEventListener('pointerdown', event => {
        if (event.button !== 0 || event.target.closest('.pf-rs')) return;
        const th = event.target.closest('th[data-col]'); if (!th) return;
        const x0 = event.clientX, y0 = event.clientY, dokunmatik = event.pointerType !== 'mouse';
        let basladi = false;
        const temizle = () => { clearTimeout(holdTimer); window.removeEventListener('pointermove', hareket); window.removeEventListener('pointerup', bitti); window.removeEventListener('pointercancel', iptal); window.removeEventListener('keydown', tus, true); };
        const hareket = e => {
            if (!basladi) {
                if (dokunmatik) { if (Math.abs(e.clientX - x0) > 10 || Math.abs(e.clientY - y0) > 10) temizle(); return; }   // kaydırıyor: taşıma değil
                if (Math.abs(e.clientX - x0) < 6) return;
                basladi = true; suruklemeBaslat(th, e); return;
            }
            e.preventDefault(); suruklemeKonum(e.clientX, e.clientY);
        };
        const bitti = () => { temizle(); suruklemeBitir(true); };
        const iptal = () => { temizle(); suruklemeBitir(false); };
        const tus = e => { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); iptal(); } };
        if (dokunmatik) holdTimer = window.setTimeout(() => { basladi = true; suruklemeBaslat(th, { clientX: x0, clientY: y0 }); }, 380);
        window.addEventListener('pointermove', hareket); window.addEventListener('pointerup', bitti); window.addEventListener('pointercancel', iptal); window.addEventListener('keydown', tus, true);
    });
    headers.addEventListener('contextmenu', event => { if (surukle) event.preventDefault(); });

    // Tarih / "Yüklenen" değişince liste otomatik yenilenir (Getir düğmesi yok).
    const filtre = document.getElementById('pfFiltre');
    filtre?.querySelectorAll('input[name="baslangic"], input[name="yuklenen"]').forEach(i => i.addEventListener('change', () => { if (filtre.reportValidity()) filtre.requestSubmit(); }));
    { const first = visibleRows()[0]; if (first) selectCell(ilkHucre(first)); }
    // Sayfa, pencere yüksekliğine ölçeklenir: tablo kalan alanı doldurur, altta boşluk kalmaz.
    function fitPage() {
        const top = page.getBoundingClientRect().top + window.scrollY;
        if (window.innerWidth < 768) {
            // Mobil: sayfa kaymaz; alt çubuğun (--bn-h + güvenli alan) üstünde biter, yalnız tablo kayar.
            const probe = document.createElement('div');
            probe.style.cssText = 'position:fixed;left:-9999px;bottom:0;height:0;padding-bottom:calc(var(--bn-h, 0px) + env(safe-area-inset-bottom, 0px))';
            document.body.appendChild(probe);
            const alt = parseFloat(getComputedStyle(probe).paddingBottom) || 0;
            probe.remove();
            page.style.height = Math.max(240, window.innerHeight - top - alt - 8) + 'px';
            return;
        }
        page.style.height = Math.max(320, window.innerHeight - top - 12) + 'px';
    }
    fitPage();
    window.addEventListener('resize', fitPage);
    window.addEventListener('load', fitPage);
    wrap.addEventListener('click', () => wrap.focus({ preventScroll: true }));
})();
