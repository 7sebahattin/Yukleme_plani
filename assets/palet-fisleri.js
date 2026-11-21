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
    let armedHeader = null;
    let movingHeader = false;
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
    let silinecek = null;     // ilk Sil ile kırmızıya boyanan satır (ikinci Sil'de silinir)
    function silmeIptal(mesajli) {
        if (!silinecek) return;
        silinecek.classList.remove('pf-row-silinecek'); silinecek = null;
        if (mesajli) setStatus('Silme vazgeçildi.');
    }
    function selectCell(cell) {
        if (!cell || cell.tagName !== 'TD') return;
        body.querySelectorAll('.pf-cell-selected').forEach(node => node.classList.remove('pf-cell-selected'));
        body.querySelectorAll('.pf-row-selected').forEach(node => node.classList.remove('pf-row-selected'));
        if (silinecek && silinecek !== cell.parentElement) silmeIptal();
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
        if (!row) { setStatus('Önce çoğaltılacak satırı seçin.'); return; }
        if (dialog?.open) return;
        silmeIptal();
        try {
            const r = await send('duplicate', { id: row.dataset.id });
            satirEkle(r.row);
            nextBelge = r.next_belge || nextBelge;
            setStatus(`${r.row.belge_no} numaralı belge olarak kopyalandı; gerekirse düzeltip Güncelle'ye basın.`);
            openDialog('duzelt');
        } catch (error) { setStatus(error.message); }
    }
    // Sil: ilk basışta satır kırmızı olur; AYNI satırda ikinci basışta (düğme / F3 / Delete) silinir.
    // Başka satıra geçmek, Esc ya da Çoğalt/Düzelt vazgeçirir.
    async function sil() {
        const row = seciliSatir();
        if (!row) { setStatus('Önce silinecek satırı seçin.'); return; }
        const belge = row.querySelector('[data-col="belge_no"]')?.textContent.trim();
        if (silinecek !== row) {
            silmeIptal();
            silinecek = row; row.classList.add('pf-row-silinecek');
            setStatus(`${belge} numaralı palet fişi silinecek — devam etmek için Sil'e tekrar basın (onay sorulur), vazgeçmek için Esc.`);
            return;
        }
        if (!window.confirm(`${belge} numaralı palet fişi silinsin mi? Bu işlem geri alınamaz.`)) { silmeIptal(true); return; }
        try {
            await send('delete', { id: row.dataset.id });
            silinecek = null;
            const komsu = row.nextElementSibling || row.previousElementSibling;
            row.remove(); selectedCell = null;
            sayacDegis(-1);
            if (komsu) selectCell(komsu.children[0]); else refreshTotals();
            empty.hidden = body.children.length > 0;
            setStatus(`${belge} numaralı palet fişi silindi.`);
        } catch (error) { silmeIptal(); setStatus(error.message); }
    }
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && silinecek && !document.querySelector('dialog[open]')) { event.preventDefault(); silmeIptal(true); }
    });
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
    const alanSirasi = ['tarih', 'parti_no', 'palet', 'firma', 'urun', 'kasa', 'kasa_adeti', 'mustahsil', 'brut_kg', 'aciklama1', 'aciklama2'];
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
            if (!q) { items = []; close(); return; }     // odaklanınca ya da boşken liste açılmaz; yazınca açılır
            const all = kaynak();
            const skor = x => { const n = trUp(x.name); return n === q ? 0 : n.startsWith(q) ? 1 : 2; };
            items = (q ? all.filter(x => trUp(x.name).includes(q)).sort((a, b) => skor(a) - skor(b)) : all).slice(0, 8).map(x => ({ type: 'var', x }));
            const tam = q && all.some(x => trUp(x.name) === q);
            if (canAdd && q && !tam) items.push({ type: 'ekle', ad: q });
            active = (kind !== 'parti' && q && items.length) ? 0 : -1;
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
        input.addEventListener('input', () => { delete input.dataset.id; render(); });
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

    let mod = 'ekle';          // 'ekle' | 'duzelt'
    let duzenlenen = null;     // Düzelt modunda satır (tr)
    let orijinal = {};         // Düzelt: değişmemiş müşteri/ürün kayıtlı tanım olmasa da kabul edilir (eski serbest metin kayıtlar)
    const baslikEl = dialog.querySelector('.pf-dialog-head h2');
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
        if (!dialog || dialog.open) return;
        mod = istenen === 'duzelt' ? 'duzelt' : 'ekle';
        silmeIptal();
        if (mod === 'duzelt') {
            const tr = seciliSatir();
            if (!tr) { setStatus('Önce düzeltilecek satırı seçin.'); mod = 'ekle'; return; }
            duzenlenen = tr;
            formDoldur(tr);
            if (baslikEl) baslikEl.textContent = 'Palet Fişi Düzelt';
            saveBtn.innerHTML = 'Güncelle <span class="pf-shortcut">F10</span>';
        } else {
            duzenlenen = null;
            if (baslikEl) baslikEl.textContent = 'Palet Fişi Ekle';
            saveBtn.innerHTML = 'Kaydet <span class="pf-shortcut">F10</span>';
            alan('belge_no').value = String(nextBelge);
        }
        mesaj('');
        dialog.showModal();
        const hazir = alan('parti_no').value && alan('firma').value;
        const hedef = (mod === 'duzelt' || hazir) ? alan('brut_kg') : alan('parti_no');
        hedef.focus(); if (hedef.select) hedef.select();
    }
    dialog.addEventListener('close', () => { if (mod === 'duzelt') { mod = 'ekle'; duzenlenen = null; formSifirla(); } });
    document.getElementById('pfAdd')?.addEventListener('click', () => openDialog('ekle'));
    document.addEventListener('keydown', event => {
        if (event.key === 'F2' && !dialog.open && !event.target.matches('input,textarea,select')) { event.preventDefault(); openDialog('ekle'); }
    });
    document.getElementById('pfClose').addEventListener('click', () => dialog.close());
    document.getElementById('pfCancel').addEventListener('click', () => dialog.close());

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
        selectCell(tr.children[0]);
        wrap.scrollTop = 0;
    }
    function satirGuncelle(tr, r) {
        satirOznitelik(tr, r);
        const deger = degerler(r);
        tr.querySelectorAll('td.pf-cell').forEach(td => { const d = deger[td.dataset.col]; if (d) { td.dataset.value = String(d[0] ?? ''); td.textContent = String(d[1] ?? ''); } });
        selectCell(tr.children[0]);
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
        if (selectedCell && selectedCell.parentElement.hidden && gorunen) selectCell(gorunen.children[Array.from(selectedCell.parentElement.children).indexOf(selectedCell)] || gorunen.children[0]);
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
            .filter(c => table.tHead.rows[0].querySelector(`th[data-col="${c}"]`));
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
        if (hedef) { hedef.hidden = false; selectCell(hedef.children[0]); hedef.scrollIntoView({ block: 'center' }); }
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
        const basliklar = Array.from(table.tHead.rows[0].children);
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
    let tercih = { genislik: {}, sira: [] };
    (function tercihYukle() {
        let ham = tercihSunucu ? window.PALET_FISLERI?.tercih : null;
        if (!tercihSunucu) { try { ham = JSON.parse(localStorage.getItem(YEREL_ANAHTAR) || 'null'); } catch (_) { ham = null; } }
        if (ham && typeof ham === 'object') tercih = { genislik: ham.genislik || {}, sira: Array.isArray(ham.sira) ? ham.sira : [] };
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
    const KOSE = 22;   // sol üst köşe alanı + satır numarası şeridi
    function genislikUygula() {
        let toplam = 0;
        Array.from(headers.children).forEach(th => {
            const w = tercih.genislik[th.dataset.col] || 120;
            th.style.width = th.style.minWidth = th.style.maxWidth = w + 'px';
            toplam += w;
        });
        table.style.width = toplam + 'px';
    }
    // İlk açılış (kayıtlı genişlik yoksa): varsayılanlar, boş alanı dolduracak şekilde ölçeklenir.
    function varsayilanGenislikler() {
        const cols = Array.from(headers.children).map(th => th.dataset.col);
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
    function sutunuDaralt(col) { tercih.genislik[col] = icerikGenisligi(col); genislikUygula(); tercihKaydet(); }
    function hepsiniDaralt() {
        Array.from(headers.children).forEach(th => { tercih.genislik[th.dataset.col] = icerikGenisligi(th.dataset.col); });
        genislikUygula(); tercihKaydet();
        setStatus('Sütunlar içeriğe göre daraltıldı.');
    }
    document.getElementById('pfKose')?.addEventListener('dblclick', hepsiniDaralt);
    // Başlık kenarından sürükle (Excel gibi); kenara çift tık: o sütunu daralt.
    Array.from(headers.children).forEach(th => {
        const tutac = document.createElement('i'); tutac.className = 'pf-rs'; tutac.title = 'Sürükleyin: genişlik · Çift tık: içeriğe göre daralt'; th.appendChild(tutac);
        tutac.addEventListener('dblclick', event => { event.stopPropagation(); sutunuDaralt(th.dataset.col); });
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
    siraUygula();
    varsayilanGenislikler();
    genislikUygula();

    // Başlıklara basılı tutup sürükleyerek sütun sırası değiştir.
    headers.addEventListener('pointerdown', event => {
        if (event.button !== 0) return;
        if (event.target.closest('.pf-rs')) return;
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
        if (movingHeader) { headers.querySelectorAll('.pf-drag-armed').forEach(th => th.classList.remove('pf-drag-armed')); siraKaydet(); setStatus('Sütun sırası kaydedildi.'); }
        movingHeader = false; armedHeader = null;
    }
    headers.addEventListener('pointerup', releaseHeader);
    headers.addEventListener('pointercancel', releaseHeader);
    headers.addEventListener('contextmenu', event => { if (movingHeader) event.preventDefault(); });

    // Tarih / "Yüklenen" değişince liste otomatik yenilenir (Getir düğmesi yok).
    const filtre = document.getElementById('pfFiltre');
    filtre?.querySelectorAll('input[name="baslangic"], input[name="yuklenen"]').forEach(i => i.addEventListener('change', () => { if (filtre.reportValidity()) filtre.requestSubmit(); }));
    { const first = visibleRows()[0]; if (first) selectCell(first.children[0]); }
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
