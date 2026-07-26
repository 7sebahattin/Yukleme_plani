/* =========================================================
   Yükleme Planı - app.js (v3)
   Modal-tabanlı palet ekleme/düzenleme
   ========================================================= */
(function () {
    'use strict';

    /* ── Türkçe büyük harf — data-uppercase="tr" ── */
    // Sayfa yüklenince mevcut değerleri dönüştür (DB'den gelen eski title-case değerler için)
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-uppercase="tr"]').forEach(function(el) {
            if (el.value) el.value = el.value.toLocaleUpperCase('tr-TR');
        });
    });
    // Kullanıcı yazarken anlık dönüştür
    document.addEventListener('input', function(e) {
        const el = e.target;
        if (!el || !el.matches('[data-uppercase="tr"]')) return;
        const start = el.selectionStart;
        const end   = el.selectionEnd;
        el.value = el.value.toLocaleUpperCase('tr-TR');
        try { el.setSelectionRange(start, end); } catch (_) {}
    });

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
                // Always use fixed positioning — avoids clipping by any overflow:auto ancestor
                const r = btn.getBoundingClientRect();
                dd.style.position = 'fixed';
                dd.style.top = (r.bottom + 4) + 'px';
                dd.style.right = (window.innerWidth - r.right) + 'px';
                dd.style.left = 'auto';
                dd.hidden = false;
            }
        } else {
            closeAllDropdowns();
        }
    });
    // Close on scroll (fixed dropdowns stay in place otherwise)
    window.addEventListener('scroll', closeAllDropdowns, true);

    /* ── Beyan WhatsApp metin ayrıştırıcı (tekli + toplu otomatik algılama) ── */
    (function () {
        var parseBtn = document.querySelector('[data-beyan-parse-btn]');
        if (!parseBtn) return;

        var beyanForm   = document.querySelector('[data-beyan-form]');
        var statusDiv   = document.getElementById('beyanParseStatus');
        var bulkBox     = document.getElementById('beyanBulkPreview'); // yalnız create sayfasında
        var baseUrl     = parseBtn.dataset.baseUrl || '';

        var FIELDS = [
            'declaration_title', 'party_no', 'transport_type', 'line_type',
            'company_name', 'company_address', 'product_name', 'product_variety',
            'pallet_count', 'gross_kg', 'net_kg', 'crate_count', 'crate_type',
            'exit_depot', 'buyer_name', 'contact_person', 'brand'
        ];

        function showParseStatus(type, msg) {
            if (!statusDiv) return;
            statusDiv.className = 'beyan-parse-alert beyan-parse-alert-' + type;
            statusDiv.textContent = msg;
            statusDiv.hidden = false;
        }

        function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

        function fillField(name, value) {
            if (value === null || value === undefined || value === '') return;
            var el = beyanForm ? beyanForm.querySelector('[name="' + name + '"]') : null;
            if (!el || el.tagName === 'SELECT') return;
            if (el.value.trim() === '' || el.value === '0') {
                el.value = String(value);
            }
        }

        // Tekli: parse sonucunu forma doldur
        function fillSingle(decl) {
            var p = (decl && decl.parsed) || {};
            FIELDS.forEach(function (f) { fillField(f, p[f]); });
            if (decl && decl.unmatched) {
                var unmEl = beyanForm ? beyanForm.querySelector('[name="unmatched_text"]') : null;
                if (unmEl && unmEl.value.trim() === '') unmEl.value = decl.unmatched;
            }
            var mc = (decl && decl.matched_count) || 0;
            var msg = mc > 0 ? (mc + ' alan dolduruldu') : 'Eşleşen alan bulunamadı — metni kontrol edin.';
            if (decl && decl.unmatched) {
                var ul = decl.unmatched.split('\n').filter(function (s) { return s.trim(); });
                if (ul.length) msg += ' (' + ul.length + ' satır eşleşmedi)';
            }
            showParseStatus(mc > 0 ? 'ok' : 'warn', msg);
        }

        // Toplu modda elle düzenleme bölümlerini gizle/göster
        function manualSections() {
            return Array.prototype.slice.call(document.querySelectorAll('.beyan-section')).slice(1);
        }
        function setSingleUI(visible) {
            manualSections().forEach(function (s) { s.style.display = visible ? '' : 'none'; });
            var acts = document.getElementById('beyanSingleActions');
            if (acts) acts.style.display = visible ? '' : 'none';
        }
        function restoreSingle() {
            if (bulkBox) { bulkBox.hidden = true; bulkBox.innerHTML = ''; }
            setSingleUI(true);
        }

        // Çoklu: önizleme listesi + "Hepsini Kaydet"
        function renderBulk(declarations, csrf) {
            if (!bulkBox) return;
            setSingleUI(false);

            var rowsHtml = declarations.map(function (d, idx) {
                var p = d.parsed || {};
                var bits = [];
                if (p.product_name)  bits.push(esc(p.product_name) + (p.product_variety ? ' ' + esc(p.product_variety) : ''));
                if (p.pallet_count)  bits.push(esc(p.pallet_count) + ' palet');
                if (p.net_kg)        bits.push('NET ' + esc(p.net_kg));
                if (p.buyer_name)    bits.push('Alıcı: ' + esc(p.buyer_name));
                if (p.brand)         bits.push(esc(p.brand));
                var unm = (d.unmatched || '').split('\n').filter(function (s) { return s.trim(); }).length;
                return '' +
                    '<div class="beyan-bulk-item">' +
                      '<div class="beyan-bulk-item-head">' +
                        '<span class="beyan-bulk-no">#' + (idx + 1) + '</span>' +
                        '<span class="beyan-bulk-parti">' + esc(p.party_no || '(parti no yok)') + '</span>' +
                        '<span class="beyan-bulk-count">' + (d.matched_count || 0) + ' alan' +
                          (unm ? ' · ' + unm + ' satır ?' : '') + '</span>' +
                      '</div>' +
                      '<div class="beyan-bulk-item-body">' + (bits.join(' · ') || '<em>tanınan alan yok</em>') + '</div>' +
                    '</div>';
            }).join('');

            bulkBox.innerHTML = '' +
                '<div class="beyan-bulk-head">' +
                  '<div><strong>' + declarations.length + ' beyan algılandı</strong>' +
                  '<span class="muted"> — toplu ekleme</span></div>' +
                  '<button type="button" class="btn btn-sm btn-ghost" data-beyan-bulk-cancel>✏️ Tekli düzenle</button>' +
                '</div>' +
                '<div class="beyan-bulk-list">' + rowsHtml + '</div>' +
                '<div class="beyan-bulk-actions">' +
                  '<button type="button" class="btn btn-primary btn-lg" data-beyan-bulk-save>' +
                    '✅ Hepsini Kaydet (' + declarations.length + ')</button>' +
                '</div>' +
                '<div class="beyan-bulk-status" hidden></div>';
            bulkBox.hidden = false;
            bulkBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

            var cancelBtn = bulkBox.querySelector('[data-beyan-bulk-cancel]');
            if (cancelBtn) cancelBtn.addEventListener('click', restoreSingle);

            var saveBtn  = bulkBox.querySelector('[data-beyan-bulk-save]');
            var bulkStat = bulkBox.querySelector('.beyan-bulk-status');
            if (saveBtn) saveBtn.addEventListener('click', function () {
                var payload = declarations.map(function (d) {
                    var rec = Object.assign({}, d.parsed || {});
                    rec.raw_text       = d.raw_text || '';
                    rec.unmatched_text = d.unmatched || '';
                    return rec;
                });
                saveBtn.disabled = true;
                saveBtn.textContent = 'Kaydediliyor…';
                if (bulkStat) { bulkStat.hidden = false; bulkStat.textContent = ''; bulkStat.className = 'beyan-bulk-status'; }

                fetch(baseUrl + 'beyan_bulk_save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf: csrf, declarations: payload })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.ok) {
                            if (bulkStat) { bulkStat.className = 'beyan-bulk-status ok'; bulkStat.textContent = '✓ ' + res.created + ' beyan eklendi, yönlendiriliyor…'; }
                            window.location.href = baseUrl + 'beyanlar.php';
                        } else {
                            saveBtn.disabled = false;
                            saveBtn.textContent = '✅ Hepsini Kaydet (' + declarations.length + ')';
                            if (bulkStat) { bulkStat.className = 'beyan-bulk-status err'; bulkStat.textContent = 'Hata: ' + (res.error || 'bilinmeyen'); }
                        }
                    })
                    .catch(function (err) {
                        saveBtn.disabled = false;
                        saveBtn.textContent = '✅ Hepsini Kaydet (' + declarations.length + ')';
                        if (bulkStat) { bulkStat.className = 'beyan-bulk-status err'; bulkStat.textContent = 'Bağlantı hatası: ' + err.message; }
                    });
            });
        }

        parseBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var rawEl = beyanForm ? beyanForm.querySelector('[name="raw_text"]') : null;
            if (!rawEl || rawEl.value.trim() === '') {
                showParseStatus('warn', 'Lütfen önce WhatsApp metnini girin.');
                return;
            }
            var csrfEl = beyanForm ? beyanForm.querySelector('[name="csrf"]') : null;
            var csrf   = csrfEl ? csrfEl.value : '';

            parseBtn.disabled = true;
            parseBtn.textContent = 'Ayrıştırılıyor…';
            if (statusDiv) statusDiv.hidden = true;
            if (bulkBox) { bulkBox.hidden = true; bulkBox.innerHTML = ''; }
            setSingleUI(true); // her ayrıştırmada tekli düzene dön; toplu ise renderBulk gizler

            var fd = new FormData();
            if (csrf) fd.append('csrf', csrf);
            fd.append('text', rawEl.value);

            fetch(baseUrl + 'beyan_parse.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    parseBtn.disabled = false;
                    parseBtn.textContent = '🔍 Metni Ayrıştır';

                    if (!data.ok) {
                        showParseStatus('error', data.error || 'Ayrıştırma başarısız.');
                        return;
                    }

                    var decls = data.declarations || [];
                    // Toplu: 2+ beyan VE create sayfasındaysak önizleme göster
                    if (bulkBox && decls.length > 1) {
                        showParseStatus('ok', decls.length + ' beyan algılandı — aşağıdan toplu kaydedin.');
                        renderBulk(decls, csrf);
                        return;
                    }
                    // Tekli: forma doldur
                    setSingleUI(true);
                    var first = decls[0] || { parsed: data.parsed, unmatched: data.unmatched, matched_count: data.matched_count };
                    fillSingle(first);
                })
                .catch(function (err) {
                    parseBtn.disabled = false;
                    parseBtn.textContent = '🔍 Metni Ayrıştır';
                    showParseStatus('error', 'Bağlantı hatası: ' + err.message);
                });
        });
    })();

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

    /* ── Pano (clipboard) yardımcıları — Excel'den kopyala/yapıştır ──
       Excel bir sütunu kopyalarken satırları \n, hücreleri \t ile ayırır.
       Türkçe CSV kopyalamalarında ayraç ";" olabilir. */
    function isNumericToken(s) {
        s = String(s == null ? '' : s).trim();
        if (s === '') return false;
        return /^-?[\d.,\s\u00a0]*\d[\d.,\s\u00a0]*$/.test(s);
    }
    /* Bilinen sütun başlıkları — "Size" gibi sayısal olmayan başlıkları
       gerçek veriden ayırmak için (yoksa ilk size değeri başlık sanılır). */
    const HEADER_WORDS = /^(#|s[ıi]ra|s[ıi]ra\s*no|palet|palet\s*no|palet\s*say[ıi]s[ıi]|no|kasa|kasa\s*adeti|kasa\s*cinsi|adet|br[üu]t|br[üu]t\s*kg|kg|kilo|a[ğg][ıi]rl[ıi]k|net|net\s*kg|dara|dara\s*kg|size|boy|boyut|[üu]r[üu]n|[üu]r[üu]n\s*cinsi|cins|depo|a[çc][ıi]klama|not)$/i;
    function looksLikeHeaderRow(cells) {
        const dolu = cells.filter(c => c !== '');
        return dolu.length > 0 && dolu.every(c => HEADER_WORDS.test(c));
    }
    /* Pano metnini satır/hücre ızgarasına çevirir.
       ARADAKİ boş satırlar KORUNUR — sütunlar ayrı ayrı yapıştırıldığında
       hizalama kaymasın diye. Yalnız baştaki/sondaki boş satırlar ve
       (varsa) başlık satırı atılır. */
    function parseClipGrid(text) {
        const out = [];
        String(text == null ? '' : text).replace(/\r\n?/g, '\n').split('\n').forEach(line => {
            let cells;
            if (line.indexOf('\t') !== -1)      cells = line.split('\t');
            else if (line.indexOf(';') !== -1)  cells = line.split(';');
            else                                cells = [line];
            out.push(cells.map(c => String(c).replace(/\u00a0/g, ' ').trim()));
        });
        const bos = r => r.every(c => c === '');
        while (out.length && bos(out[0]))              out.shift();
        while (out.length && bos(out[out.length - 1])) out.pop();
        // Başlık satırını at: ya bilinen bir başlık sözcüğü, ya da kendisi
        // sayı değilken hemen altındaki satır sayı olan bir satır.
        if (out.length > 1 &&
            (looksLikeHeaderRow(out[0]) ||
             (!out[0].some(isNumericToken) && out[1].some(isNumericToken)))) {
            out.shift();
        }
        return out;
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
                    <button type="button" class="pc-isle-btn${_done ? ' active' : ''}" data-isle="${i}" title="${_done ? 'Raporlandı — geri al' : 'Raporla'}">✓</button>
                </div>
                <div class="pc-body">
                    <div class="pc-title">Palet ${escHtml(p.palet_no || (i + 1))}${p.size ? ' · ' + escHtml(p.size) : ''}</div>
                    <div class="pc-stats">
                        <span><strong${kasaWarn ? ' class="pc-warn"' : ''}>${p.kasa_adeti}</strong> kasa</span>
                        <span>Brüt <strong${brutWarn ? ' class="pc-warn"' : ''}>${fmtKg(Math.round(br))}</strong></span>
                    </div>
                    <div class="pc-stats pc-stats-dara">
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
            snapshotFirstRow();
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
            snapshotFirstRow();
            return tr;
        }

        /* Panel aç: mevcut paletleri yükle */
        acBtn.addEventListener('click', () => {
            if (panel.style.display !== 'none') { panel.style.display = 'none'; return; }
            ['excelPanel', 'yapistirPanel'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.style.display = 'none';
            });

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

        /* ── 1. satır → aşağıya yayılım ──
           "Kasa cinsi / palet tipi / depo / ürün 1. satırda ne ise öyle olsun":
           1. satırdaki bir seçim değişince, o ana kadar AYNI değere sahip olan
           alt satırlar da güncellenir. Bilerek farklı yapılmış satırlara dokunulmaz. */
        const SYNC_KEYS  = ['kasa_cinsi_id', 'palet_tipi_id', 'depo', 'urun_cinsi'];
        const prevFirst  = {};

        function setSelectValue(sel, val) {
            sel.value = val;
            if (sel.value !== val) {            // seçenek listede yoksa ekle
                const o = document.createElement('option');
                o.value = val; o.textContent = val || '—';
                sel.appendChild(o);
                sel.value = val;
            }
        }

        function snapshotFirstRow() {
            const first = tbody.querySelector('tr');
            SYNC_KEYS.forEach(k => {
                const el = first ? first.querySelector('[data-key=' + k + ']') : null;
                prevFirst[k] = el ? el.value : '';
            });
        }

        tbody.addEventListener('change', function (e) {
            const el = e.target;
            if (!el || !el.dataset || SYNC_KEYS.indexOf(el.dataset.key) < 0) return;
            if (el.closest('tr') !== tbody.querySelector('tr')) { snapshotFirstRow(); return; }
            const key = el.dataset.key;
            const oldVal = prevFirst[key];
            const newVal = el.value;
            prevFirst[key] = newVal;
            if (oldVal === newVal) return;
            Array.from(tbody.querySelectorAll('tr')).slice(1).forEach(row => {
                const cell = row.querySelector('[data-key=' + key + ']');
                if (cell && cell.value === oldVal) {
                    setSelectValue(cell, newVal);
                    calcRow(row);
                }
            });
        });

        /* ── Yapıştır (fill-down) ──
           Excel'den kopyalanan çok satırlı veri bir hücreye yapıştırılınca
           sıra bozulmadan aşağıya doğru dolar; satır yetmezse yenisi açılır.
           Yeni satırların kasa cinsi / palet tipi / depo / ürün değerleri
           1. satırdan kopyalanır. */
        const PASTE_KEYS = ['palet_sayisi', 'kasa_adeti', 'brut_kg'];

        /* 1. satırın seçim alanları — yeni satırlar için şablon */
        function firstRowTemplate() {
            const first = tbody.querySelector('tr');
            if (first) {
                return {
                    palet_sayisi:  '1',
                    kasa_adeti:    '',
                    brut_kg:       '',
                    kasa_cinsi_id: first.querySelector('[data-key=kasa_cinsi_id]').value,
                    palet_tipi_id: first.querySelector('[data-key=palet_tipi_id]').value,
                    depo:          first.querySelector('[data-key=depo]').value,
                    urun_cinsi:    first.querySelector('[data-key=urun_cinsi]').value,
                };
            }
            const p = pallets[0] || {};
            return {
                palet_sayisi:  '1',
                kasa_adeti:    '',
                brut_kg:       '',
                kasa_cinsi_id: p.kasa_cinsi_id || '',
                palet_tipi_id: p.palet_tipi_id || '',
                depo:          p.depo || '',
                urun_cinsi:    p.urun_cinsi || '',
            };
        }

        function setPasteCell(tr, key, raw) {
            const el = tr.querySelector('[data-key=' + key + ']');
            if (!el) return;
            const s = String(raw == null ? '' : raw).trim();
            if (s === '') return;                       // boş hücre → mevcut değeri koru
            if (key === 'brut_kg')      el.value = fmtInput(parseNum(s));
            else if (key === 'palet_sayisi') el.value = String(Math.max(1, parseInt2(s) || 1));
            else                        el.value = String(parseInt2(s));
        }

        /* grid'i startCell'den başlayarak aşağı + sağa doğru uygula */
        function applyPasteGrid(startCell, grid) {
            const kIdx = PASTE_KEYS.indexOf(startCell.dataset.key);
            if (kIdx < 0) return 0;
            const startRowIdx = Array.from(tbody.querySelectorAll('tr')).indexOf(startCell.closest('tr'));
            if (startRowIdx < 0) return 0;

            const tpl = firstRowTemplate();
            let lastTr = null;
            grid.forEach((cells, i) => {
                let tr = tbody.querySelectorAll('tr')[startRowIdx + i];
                if (!tr) tr = addRow(tpl, -1);
                cells.forEach((v, j) => {
                    const key = PASTE_KEYS[kIdx + j];
                    if (key) setPasteCell(tr, key, v);
                });
                calcRow(tr);
                lastTr = tr;
            });
            updateNos();
            const total = tbody.querySelectorAll('tr').length;
            countEl.textContent = total + ' palet';
            if (lastTr) lastTr.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            return grid.length;
        }

        /* clipboardData vermeyen tarayıcılar için yedek yol:
           input tek satıra düzleştirir → boşluklardan böl */
        function pasteFallback(cell) {
            const before = cell.value;
            setTimeout(() => {
                const after = cell.value;
                if (after === before) return;
                const toks = after.trim().split(/[\s\u00a0]+/).filter(Boolean);
                if (toks.length < 2 || !toks.every(isNumericToken)) return;
                cell.value = before;
                applyPasteGrid(cell, toks.map(t => [t]));
                flashPasteInfo(toks.length);
            }, 0);
        }

        function flashPasteInfo(n) {
            if (!countEl) return;
            const total = tbody.querySelectorAll('tr').length;
            countEl.textContent = '📋 ' + n + ' satır yapıştırıldı · ' + total + ' palet';
            countEl.classList.add('tp-paste-flash');
            setTimeout(() => {
                countEl.classList.remove('tp-paste-flash');
                countEl.textContent = tbody.querySelectorAll('tr').length + ' palet';
            }, 2500);
        }

        tbody.addEventListener('paste', function (e) {
            const cell = e.target;
            if (!cell || !cell.matches || !cell.matches('input[data-key]')) return;
            const cd = e.clipboardData || window.clipboardData;
            const text = cd ? cd.getData('text') : '';
            if (!text) { pasteFallback(cell); return; }
            const grid = parseClipGrid(text);
            if (!grid.length) return;
            // Tek hücre → tarayıcının normal yapıştırması
            if (grid.length === 1 && grid[0].length === 1) return;
            e.preventDefault();
            const n = applyPasteGrid(cell, grid);
            if (n) flashPasteInfo(n);
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
            // diğer panelleri kapat
            ['topluPanel', 'yapistirPanel'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.style.display = 'none';
            });
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

    /* ── Yapıştır Paneli — Excel sütunlarını kopyala/yapıştır ──
       Mobil öncelikli: hücreye uzun basıp yapıştırmak yerine büyük
       textarea'lara yapıştırılır, satır sırası korunur. */
    (function () {
        const panel    = document.getElementById('yapistirPanel');
        const acBtn    = document.getElementById('yapistirAcBtn');
        if (!panel || !acBtn) return;
        const kapatBtn = document.getElementById('ypKapat');
        const temizBtn = document.getElementById('ypTemizle');
        const taKasa   = document.getElementById('ypKasa');
        const taBrut   = document.getElementById('ypBrut');
        const taSize   = document.getElementById('ypSize');
        const infoKasa = document.getElementById('ypKasaInfo');
        const infoBrut = document.getElementById('ypBrutInfo');
        const infoSize = document.getElementById('ypSizeInfo');
        const BOXES    = [taKasa, taBrut, taSize];   // yapıştırmada soldan sağa sıra
        const kcSel    = document.getElementById('ypKasaCinsi');
        const ptSel    = document.getElementById('ypPaletTipi');
        const depSel   = document.getElementById('ypDepo');
        const urunSel  = document.getElementById('ypUrun');
        const uyariEl  = document.getElementById('ypUyari');
        const onizWrap = document.getElementById('ypOnizlemeWrap');
        const onizBody = document.getElementById('ypTbody');
        const ozetEl   = document.getElementById('ypOzet');
        const countEl  = document.getElementById('ypCount');
        const rowCntEl = document.getElementById('ypRowCount');
        const uygulaBtn = document.getElementById('ypUygula');

        const MAX_PREVIEW = 300;   // çok uzun listelerde DOM'u şişirme

        /* Referans palet = 1. palet (yoksa genel alanlar) */
        function refPallet() { return pallets[0] || {}; }

        function initSelects() {
            const rp = refPallet();
            kcSel.innerHTML  = buildOptions(KASA_LIST,  rp.kasa_cinsi_id || '', '— seçiniz —');
            ptSel.innerHTML  = buildOptions(PALET_LIST, rp.palet_tipi_id || '', '— seçiniz —');
            depSel.innerHTML = buildTextOptions(DEPO_LIST,
                rp.depo || (document.getElementById('genelDepo') || {}).value || '', '— seçiniz —');
            urunSel.innerHTML = buildTextOptions(URUN_LIST,
                rp.urun_cinsi || (document.getElementById('genelUrun') || {}).value || '', '— seçiniz —');
        }

        /* Textarea içeriğini satır dizisine çevir.
           Sadece SONDAKİ boş satırlar atılır — aradaki boşluklar korunur, yoksa
           sütunlar arasındaki hizalama kayar (ör. size bazı satırlarda boş). */
        function lines(ta) {
            const arr = String(ta.value || '').replace(/\r\n?/g, '\n').split('\n')
                .map(s => s.replace(/\u00a0/g, ' ').trim());
            while (arr.length && arr[arr.length - 1] === '') arr.pop();
            return arr;
        }
        const has = v => v != null && v !== '';

        /* Çok sütunlu veri yapıştırılırsa sütunlar soldan sağa kutulara dağıtılır:
           kasa kutusuna 3 sütun → kasa / brüt / size. */
        function attachPaste(ta) {
            ta.addEventListener('paste', function (e) {
                const cd = e.clipboardData || window.clipboardData;
                const text = cd ? cd.getData('text') : '';
                if (!text) { setTimeout(refresh, 0); return; }
                const grid = parseClipGrid(text);
                if (!grid.length) return;
                // Tek değer → normal yapıştırma (kutunun içeriğini silme)
                if (grid.length === 1 && grid[0].length === 1) { setTimeout(refresh, 0); return; }
                e.preventDefault();
                const idx = BOXES.indexOf(ta);
                const colCount = grid.reduce((m, r) => Math.max(m, r.length), 0);
                for (let c = 0; c < colCount; c++) {
                    const box = BOXES[idx + c];
                    if (!box) break;                                  // sağda kutu kalmadı
                    const col = grid.map(r => r[c] || '');
                    if (c > 0 && col.every(v => v === '')) continue;   // tamamen boş sütunu yazma
                    box.value = col.join('\n');
                }
                refresh();
            });
            ta.addEventListener('input', refresh);
        }

        function unitOf(sel) {
            const o = sel.options[sel.selectedIndex];
            return parseNum((o && o.dataset ? o.dataset.unit : 0) || 0);
        }

        /* Kutulardan hizalı satır listesi üret.
           Size tek değerse tüm satırlara uygulanır (ör. tüm paletler "60/70"). */
        function collect() {
            const kasa = lines(taKasa);
            const brut = lines(taBrut);
            const size = lines(taSize);
            const n = Math.max(kasa.length, brut.length, size.length);
            const tekSize = (size.length === 1 && n > 1) ? size[0] : null;
            const rows = [];
            for (let i = 0; i < n; i++) {
                rows.push({
                    kasa: kasa[i], brut: brut[i],
                    size: tekSize != null ? tekSize : size[i],
                    // Kasa ve brüt birlikte boşsa bu satır palet olmaz (araya kaçmış boş satır)
                    atla: !has(kasa[i]) && !has(brut[i]),
                });
            }
            return { rows, kasa, brut, size, tekSize };
        }

        function refresh() {
            const { rows, kasa, brut, size, tekSize } = collect();
            infoKasa.textContent = kasa.length + ' satır';
            infoBrut.textContent = brut.length + ' satır';
            infoSize.textContent = tekSize != null ? 'tümüne uygulanır'
                                 : (size.length ? size.length + ' satır' : 'boş');

            const gecerli = rows.filter(r => !r.atla);
            rowCntEl.textContent = gecerli.length;
            countEl.textContent  = gecerli.length ? gecerli.length + ' satır hazır' : '';

            /* Uyarılar */
            const uyarilar = [];
            if (kasa.length && brut.length && kasa.length !== brut.length) {
                uyarilar.push('⚠ Kasa adeti <b>' + kasa.length + '</b> satır, brüt KG <b>' + brut.length +
                              '</b> satır — eşleşmiyor. Eksik hücreler boş bırakılacak.');
            }
            if (tekSize == null && size.length && size.length !== rows.length) {
                uyarilar.push('⚠ Size <b>' + size.length + '</b> satır, diğer sütunlar <b>' + rows.length +
                              '</b> satır — eşleşmeyen satırların size değeri boş kalacak.');
            }
            const atlanan = rows.length - gecerli.length;
            if (atlanan > 0) {
                uyarilar.push('ℹ Kasa ve brütü boş olan <b>' + atlanan + '</b> satır atlanacak.');
            }
            const bozuk = [];
            rows.forEach((r, i) => {
                if (r.atla) return;
                if (has(r.kasa) && !isNumericToken(r.kasa)) bozuk.push((i + 1) + '. satır kasa: "' + escHtml(r.kasa) + '"');
                if (has(r.brut) && !isNumericToken(r.brut)) bozuk.push((i + 1) + '. satır brüt: "' + escHtml(r.brut) + '"');
            });
            if (bozuk.length) {
                uyarilar.push('⚠ Sayı olarak okunamayan değerler 0 kabul edilir: ' +
                              bozuk.slice(0, 5).join(', ') + (bozuk.length > 5 ? ' …' : ''));
            }
            uyariEl.innerHTML = uyarilar.join('<br>');
            uyariEl.style.display = uyarilar.length ? '' : 'none';

            /* Önizleme */
            onizBody.innerHTML = '';
            if (!gecerli.length) {
                onizWrap.style.display = 'none';
                uygulaBtn.disabled = true;
                return;
            }
            const kasaUnit = unitOf(kcSel);
            const palUnit  = unitOf(ptSel);
            let tKasa = 0, tBrut = 0, tDara = 0, tNet = 0, no = 0;
            const frag = document.createDocumentFragment();
            for (let i = 0; i < rows.length; i++) {
                const r = rows[i];
                const tr = document.createElement('tr');
                if (r.atla) {
                    if (no < MAX_PREVIEW) {
                        tr.className = 'yp-satir-atlandi';
                        tr.innerHTML = '<td class="tp-no-td"><span class="tp-no">·</span></td>' +
                                       '<td colspan="5" class="yp-bos">boş satır — atlanacak</td>';
                        frag.appendChild(tr);
                    }
                    continue;
                }
                no++;
                const ka = parseInt2(r.kasa || 0);
                const br = parseNum(r.brut || 0);
                const da = ka * kasaUnit + palUnit;
                const ne = Math.max(0, br - da);
                tKasa += ka; tBrut += br; tDara += da; tNet += ne;
                if (no > MAX_PREVIEW) continue;
                tr.innerHTML =
                    '<td class="tp-no-td"><span class="tp-no">' + no + '</span></td>' +
                    '<td>' + (has(r.kasa) ? fmtKg(ka)  : '<span class="yp-bos">—</span>') + '</td>' +
                    '<td>' + (has(r.brut) ? fmtKg(br)  : '<span class="yp-bos">—</span>') + '</td>' +
                    '<td class="yp-size-td">' + (has(r.size) ? escHtml(r.size) : '<span class="yp-bos">—</span>') + '</td>' +
                    '<td class="tp-num">' + fmtKg(da) + '</td>' +
                    '<td class="tp-num">' + fmtKg(ne) + '</td>';
                // Net ≤ 0 yalnızca brüt girilmişken hata sayılır
                if (has(r.brut) && ne <= 0) tr.classList.add('yp-satir-hata');
                frag.appendChild(tr);
            }
            onizBody.appendChild(frag);
            if (no > MAX_PREVIEW) {
                const tr = document.createElement('tr');
                tr.innerHTML = '<td colspan="6" class="muted" style="text-align:center;padding:6px">' +
                               '… ve ' + (no - MAX_PREVIEW) + ' satır daha</td>';
                onizBody.appendChild(tr);
            }
            ozetEl.innerHTML =
                '<span>Palet <strong>' + no + '</strong></span>' +
                '<span>Kasa <strong>' + fmtKg(tKasa) + '</strong></span>' +
                '<span>Brüt <strong>' + fmtKg(tBrut) + '</strong></span>' +
                '<span>Dara <strong>' + fmtKg(tDara) + '</strong></span>' +
                '<span>Net <strong>' + fmtKg(tNet) + '</strong></span>';
            onizWrap.style.display = '';
            uygulaBtn.disabled = false;
        }

        [kcSel, ptSel, depSel, urunSel].forEach(s => s.addEventListener('change', () => {
            s.classList.remove('error');
            refresh();
        }));
        BOXES.forEach(attachPaste);

        function temizle() {
            BOXES.forEach(b => { b.value = ''; });
            refresh();
        }

        acBtn.addEventListener('click', () => {
            if (panel.style.display !== 'none') { panel.style.display = 'none'; return; }
            ['topluPanel', 'excelPanel'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.style.display = 'none';
            });
            initSelects();
            temizle();
            panel.style.display = '';
            panel.scrollIntoView({ block: 'nearest' });
            setTimeout(() => taKasa.focus(), 80);
        });
        kapatBtn.addEventListener('click', () => { panel.style.display = 'none'; });
        temizBtn.addEventListener('click', temizle);

        uygulaBtn.addEventListener('click', () => {
            const gecerli = collect().rows.filter(r => !r.atla);
            const n = gecerli.length;
            if (!n) return;

            /* Zorunlu alanlar — kayıt sırasında sunucu da arıyor, burada uyar */
            let eksik = false;
            [[kcSel, 'Kasa Cinsi'], [ptSel, 'Palet Tipi'], [depSel, 'Depo']].forEach(([el]) => {
                if (!el.value) { el.classList.add('error'); eksik = true; }
                else el.classList.remove('error');
            });
            if (eksik) {
                alert('Kasa Cinsi, Palet Tipi ve Depo seçilmelidir.');
                return;
            }

            const mode = (panel.querySelector('input[name=ypMode]:checked') || {}).value || 'ekle';
            if (mode === 'degistir' && pallets.length) {
                if (!confirm('Mevcut ' + pallets.length + ' palet silinip ' + n + ' yeni palet oluşturulacak. Onaylıyor musunuz?')) return;
            }

            /* Malzemeler 1. paletten kopyalanır (Excel Yükle ile aynı davranış) */
            const rp   = refPallet();
            const mats = Array.isArray(rp.materials) ? rp.materials : [];

            if (mode === 'degistir') pallets.length = 0;

            gecerli.forEach(r => {
                pallets.push({
                    palet_no:      String(pallets.length + 1),
                    kasa_adeti:    parseInt2(r.kasa || 0),
                    size:          has(r.size) ? String(r.size) : '',
                    brut_kg:       parseNum(r.brut || 0),
                    kasa_cinsi_id: kcSel.value,
                    palet_tipi_id: ptSel.value,
                    urun_cinsi:    urunSel.value,
                    depo:          depSel.value,
                    islendi:       false,
                    materials:     mats.map(m => ({ material_id: m.material_id, quantity: m.quantity })),
                });
            });

            renderCards();
            recomputeTotals();
            temizle();
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

/* =========================================================
   ÖNERİ KUTUSU — data-suggest'li serbest metin alanları
   (Alıcı · Gümrük · Nakliye Şirketi · Şoför · Telefon ·
    Ön/Arka Plaka · Ulaşım)

   Yazarken mevcut değerleri süzer; birebir eşleşme yoksa listenin
   sonuna "+ ekle" satırı koyar. Ekleme api_tanim_ekle.php'ye gider
   ve YALNIZCA kullanıcı bu satıra bastığında olur — kayıt kaydetmek
   tanım OLUŞTURMAZ, böylece yanlış yazımlar tanımları kirletmez.

   Serbest metin alanı olmayı sürdürür: listede olmayan bir değer
   yazılıp kaydedilebilir, tanıma eklenmesi zorunlu değildir.
   ========================================================= */
(function () {
    'use strict';

    var dataEl = document.getElementById('suggestData');
    if (!dataEl) return;
    var POOL;
    try { POOL = JSON.parse(dataEl.textContent || '{}'); } catch (_) { return; }
    if (!POOL || typeof POOL !== 'object') return;

    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    /* TR-duyarsız katlama — malzeme_stok_islem ve depo_fold ile aynı kurallar */
    function fold(s) {
        return String(s == null ? '' : s)
            .replace(/İ/g, 'i').replace(/I/g, 'i').replace(/Ş/g, 's').replace(/Ğ/g, 'g')
            .replace(/Ü/g, 'u').replace(/Ö/g, 'o').replace(/Ç/g, 'c')
            .replace(/ı/g, 'i').replace(/ş/g, 's').replace(/ğ/g, 'g')
            .replace(/ü/g, 'u').replace(/ö/g, 'o').replace(/ç/g, 'c')
            .toLowerCase().replace(/\s+/g, ' ').trim();
    }
    /* En az bir harf/rakam — sunucudaki suggest_value_is_meaningful ile aynı kural */
    function anlamli(v) { return /[0-9A-Za-zÇĞİIÖŞÜçğıiöşü]/.test(String(v || '')); }

    function attach(input) {
        var field = input.dataset.suggest;
        var label = input.dataset.suggestLabel || 'değer';
        var list0 = POOL[field];
        if (!Array.isArray(list0)) list0 = [];
        var items = list0.slice();

        var wrap = document.createElement('div');
        wrap.className = 'ms-sug-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        var list = document.createElement('div');
        list.className = 'ms-sug-list';
        list.hidden = true;
        wrap.appendChild(list);
        var active = -1;

        var suppress = false;   // programatik olay sırasında listeyi yeniden açma

        /* ── Kırpılmayı aşmak için sabit (fixed) konumlandırma ──
           .collapsible-card .card-body'de kapanma animasyonu için
           `overflow: hidden` var. Liste normal akışta (absolute) açılınca,
           kartın SON SATIRINDAKİ alanlarda (Ulaşım, Ön/Arka Plaka, Gümrük)
           kart gövdesinin dışına taştığı için TAMAMEN kırpılıyordu — kutu
           açılıyor ama hiç görünmüyordu. İlk satırdaki alanlar taşmadığı
           için sorunsuz çalışıyordu; bu yüzden hata yalnız bazı alanlarda
           görülüyordu.
           Çözüm, projede kebab menüsü için zaten kullanılan desen
           (bkz. CLAUDE.md "Dropdown overflow'da kesiyor"):
           position: fixed + getBoundingClientRect(). */
        function positionList() {
            if (list.hidden) return;
            var r = input.getBoundingClientRect();
            list.style.position = 'fixed';
            list.style.left     = r.left + 'px';
            list.style.width    = r.width + 'px';
            list.style.right    = 'auto';
            var altBosluk = window.innerHeight - r.bottom;
            var h = list.offsetHeight || 0;
            if (altBosluk < h + 8 && r.top > altBosluk) {
                // Aşağıda yer yok → yukarı aç
                list.style.top    = 'auto';
                list.style.bottom = (window.innerHeight - r.top + 3) + 'px';
            } else {
                list.style.bottom = 'auto';
                list.style.top    = (r.bottom + 3) + 'px';
            }
        }
        // Açıkken sayfa/kapsayıcı kaydırılırsa liste input'la birlikte gitsin
        window.addEventListener('scroll', positionList, true);
        window.addEventListener('resize', positionList);

        function close() {
            list.hidden = true;
            active = -1;
            // Satır içi konum stillerini temizle — CSS varsayılanına dön
            list.style.position = '';
            list.style.top      = '';
            list.style.bottom   = '';
            list.style.left     = '';
            list.style.width    = '';
            list.style.right    = '';
        }
        function hasExact(v) {
            var f = fold(v);
            return items.some(function (s) { return fold(s) === f; });
        }
        function pick(name) {
            input.value = name;
            // data-uppercase ve bağımlı alanlar tetiklensin. Bu 'input' olayı
            // kendi render()'ımızı da çağırır ve listeyi yeniden açardı —
            // suppress bayrağı bunu engeller.
            suppress = true;
            input.dispatchEvent(new Event('input',  { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            suppress = false;
            close();
        }

        function ekle(name, row) {
            if (row.dataset.busy === '1') return;      // çift dokunuş koruması
            row.dataset.busy = '1';
            row.classList.add('busy');
            row.textContent = '⏳ Ekleniyor…';
            fetch('api_tanim_ekle.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ csrf: csrf, field: field, name: name })
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (!j || !j.ok) {
                    row.textContent = '⚠ ' + ((j && j.error) || 'Eklenemedi');
                    row.classList.remove('busy');
                    row.dataset.busy = '';
                    return;
                }
                if (!hasExact(j.name)) items.push(j.name);
                if (Array.isArray(POOL[field]) && POOL[field].indexOf(j.name) === -1) {
                    POOL[field].push(j.name);          // aynı alanın diğer örnekleri de görsün
                }
                pick(j.name);
            }).catch(function () {
                row.textContent = '⚠ Bağlantı hatası';
                row.classList.remove('busy');
                row.dataset.busy = '';
            });
        }

        /* mousedown + touchstart: input blur'undan ÖNCE çalışır.
           İkisi birden tetiklenirse tek sefer çalışsın diye kilit var. */
        function onTap(el, fn) {
            var kilit = false;
            function handler(e) {
                if (kilit) return;
                kilit = true;
                setTimeout(function () { kilit = false; }, 400);
                e.preventDefault();
                fn();
            }
            el.addEventListener('mousedown', handler);
            el.addEventListener('touchstart', handler, { passive: false });
        }

        function render() {
            if (suppress) return;
            var v = input.value.trim();
            var q = fold(v);
            var matches = items.filter(function (s) {
                return q === '' || fold(s).indexOf(q) !== -1;
            }).slice(0, 8);

            list.innerHTML = '';
            matches.forEach(function (name) {
                var row = document.createElement('div');
                row.className = 'ms-sug-row';
                row.textContent = name;
                onTap(row, function () { pick(name); });
                list.appendChild(row);
            });
            // Birebir eşleşme yoksa "+ ekle" satırı — boş/anlamsız değerde gösterme
            if (v !== '' && anlamli(v) && !hasExact(v)) {
                var add = document.createElement('div');
                add.className = 'ms-sug-row ms-sug-add';
                add.textContent = '➕ "' + v + '" ' + label + ' olarak ekle';
                onTap(add, function () { ekle(v, add); });
                list.appendChild(add);
            }
            list.hidden = list.childNodes.length === 0;
            active = -1;
            positionList();   // satırlar eklendikten SONRA — yükseklik ölçülebilsin
        }

        function rows() { return list.querySelectorAll('.ms-sug-row'); }

        input.addEventListener('input', render);
        input.addEventListener('focus', render);
        input.addEventListener('blur', function () { setTimeout(close, 180); });
        input.addEventListener('keydown', function (e) {
            if (list.hidden) return;
            var rs = rows();
            if (e.key === 'ArrowDown')      { e.preventDefault(); active = Math.min(active + 1, rs.length - 1); }
            else if (e.key === 'ArrowUp')   { e.preventDefault(); active = Math.max(active - 1, 0); }
            else if (e.key === 'Enter') {
                if (active >= 0 && rs[active]) {
                    e.preventDefault();
                    rs[active].dispatchEvent(new Event('mousedown'));
                }
                return;
            }
            else if (e.key === 'Escape')    { close(); return; }
            else return;
            rs.forEach(function (r, i) { r.classList.toggle('active', i === active); });
        });
    }

    // Her alanı AYRI ayrı dene: forEach callback'i throw ederse tüm iterasyon
    // durur — bir alanın kurulumu (ör. üretimdeki eski/beklenmedik veri)
    // patlarsa, DOM sırasında ONDAN SONRAKİ TÜM alanlar (Ulaşım genelde en
    // sonda) sessizce hiç kurulmamış olurdu. try/catch bunu izole eder.
    document.querySelectorAll('input[data-suggest]').forEach(function (input) {
        try {
            attach(input);
        } catch (err) {
            console.error('[öneri kutusu] "' + (input.name || input.dataset.suggest) + '" alanı kurulamadı:', err);
        }
    });

    /* ── Şoför → Telefon otomatik doldurma ──
       Şoför adı bilinen bir şoföre eşitlenince telefon kutusu dolar.
       Kullanıcının ELLE yazdığı telefon EZİLMEZ: yalnızca kutu boşsa ya da
       içindeki değer bizim önceki otomatik doldurmamızsa değiştirilir. */
    (function () {
        var telEl   = document.querySelector('input[name="telefon"]');
        var soforEl = document.querySelector('input[name="sofor_adi"]');
        var mapEl   = document.getElementById('soforTelefonData');
        if (!telEl || !soforEl || !mapEl) return;

        var MAP;
        try { MAP = JSON.parse(mapEl.textContent || '{}'); } catch (_) { return; }
        if (!MAP || typeof MAP !== 'object') return;

        // ad → telefon (TR-duyarsız anahtar)
        var byFold = {};
        Object.keys(MAP).forEach(function (ad) { byFold[fold(ad)] = MAP[ad]; });

        var otoDolan = null;      // en son bizim yazdığımız değer
        var otoOnaylanmadi = false; // otomatik dolan değere kullanıcı hiç dokunmadı

        // Kullanıcı telefonu elle değiştirirse otomatik dolduruma son ver
        telEl.addEventListener('input', function () {
            if (telEl.value !== otoDolan) otoDolan = null;
        });

        /* Otomatik dolan değeri, kullanıcı yazmaya başlayınca TEMİZLE.
           Neden seçili bırakmak (select) tek başına YETMİYOR: fareyle telefon
           kutusuna tıklanınca sıra şöyle işliyor — mousedown şoförü blur eder,
           'change' tetiklenir, biz değeri yazıp seçeriz; ARDINDAN tarayıcı
           tıklama noktasına imleci koyar ve SEÇİMİ BOZAR. Sonraki tuş vuruşu
           da değerin SONUNA eklenir ("0545...05559...").
           beforeinput ile ilk yazımda alanı boşaltmak her iki akışta da
           (Tab ve fare) doğru davranışı garanti eder. */
        telEl.addEventListener('beforeinput', function (e) {
            if (!otoOnaylanmadi) return;
            if (!e.inputType || e.inputType.indexOf('insert') !== 0) return;
            if (telEl.value !== otoDolan) { otoOnaylanmadi = false; return; }
            otoOnaylanmadi = false;
            telEl.value = '';       // yazılan, otomatik değerin YERİNE geçsin
        });
        // Alandan çıkıldıysa değer kabul edilmiş sayılır; sonraki yazımlar normal düzenleme
        telEl.addEventListener('blur', function () { otoOnaylanmadi = false; });

        function uygula() {
            var tel = byFold[fold(soforEl.value)];
            if (!tel) return;
            var mevcut = telEl.value.trim();
            if (mevcut !== '' && mevcut !== otoDolan) return;   // elle girilmiş → dokunma
            if (mevcut === tel) return;
            telEl.value = tel;
            otoDolan = tel;
            otoOnaylanmadi = true;
            // Odaktaysa ayrıca seçili bırak — kullanıcı doldurulanı görsün
            // (asıl koruma yukarıdaki beforeinput; bu yalnız görsel ipucu).
            if (document.activeElement === telEl) {
                try { telEl.select(); } catch (_) {}
            }
            telEl.dispatchEvent(new Event('change', { bubbles: true }));
            telEl.classList.add('oto-dolan');
            setTimeout(function () { telEl.classList.remove('oto-dolan'); }, 1200);
        }

        // Yalnız 'change' yeterli: gerçek kullanıcı yazıp alandan çıkınca native
        // 'change' zaten ateşlenir; öneri listesinden seçim de pick() içinde
        // manuel 'change' dispatch eder. Ayrıca bir 'blur'+setTimeout tetikleyicisi
        // DAHA eklemek gereksiz ikinci bir tetikleyici yaratıp gecikmeli/iç içe
        // çağrılara (ve programatik doldurma sırasında yarış durumuna) yol açardı.
        soforEl.addEventListener('change', uygula);
    })();
})();

/* =========================================================
   Liste Scroll Koruma — records.php / cikmalar.php
   Kayıt linkine tıklanınca scroll kaydedilir; geri dönünce
   aynı karta scroll restore yapılır.
   Geri/İptal butonları: referrer listeyse history.back(),
   yoksa sessionStorage ile listeye doğrudan gidilir.
   ========================================================= */
(function () {
    'use strict';

    var TTL = 30 * 60 * 1000; // 30 dakika

    function sk(page, suffix) { return 'asya_' + page + '_' + suffix; }

    // Hangi liste sayfasındayız?
    var path = window.location.pathname;
    var currentPage = path.indexOf('cikmalar.php') !== -1 ? 'cikmalar'
                    : path.indexOf('records.php')  !== -1 ? 'records'
                    : null;

    if (currentPage) {
        // Kayıt linkine tıklanınca scroll + hedef kaydet
        document.addEventListener('click', function (e) {
            var link = e.target.closest('a[href]');
            if (!link) return;
            var href = link.getAttribute('href') || '';
            var m = href.match(/(?:record_view|record_edit)\.php.*?[?&]id=(\d+)/);
            if (!m) return;
            try {
                sessionStorage.setItem(sk(currentPage, 'scroll'), String(Math.round(window.scrollY)));
                sessionStorage.setItem(sk(currentPage, 'target'), m[1]);
                sessionStorage.setItem(sk(currentPage, 'ts'),     String(Date.now()));
            } catch (_) {}
        });

        // Sayfa yüklenince scroll restore (bfcache değilse)
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) return; // bfcache — tarayıcı scroll'ı zaten korur
            try {
                var ts = parseInt(sessionStorage.getItem(sk(currentPage, 'ts')) || '0', 10);
                if (!ts || Date.now() - ts > TTL) return;
                var target = sessionStorage.getItem(sk(currentPage, 'target')) || '';
                var savedY = parseInt(sessionStorage.getItem(sk(currentPage, 'scroll')) || '0', 10);
                if (target) {
                    // Her iki görünümdeki (pc tr + mobil div) data-record-id elementleri arasında
                    // offsetParent'ı null olmayan (display:none dışında) görünür olanı bul
                    var hits = document.querySelectorAll('[data-record-id="' + target + '"]');
                    for (var i = 0; i < hits.length; i++) {
                        if (hits[i].offsetParent !== null) {
                            hits[i].scrollIntoView({ block: 'center' });
                            return;
                        }
                    }
                }
                if (savedY > 0) window.scrollTo(0, savedY);
            } catch (_) {}
        });
    }

    // ── Geri / İptal butonları: data-list-back attribute ile hibrit davranış ──
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-list-back]');
        if (!btn) return;
        e.preventDefault();

        var listPage = btn.dataset.listBack;            // 'records' | 'cikmalar'
        var recordId = btn.dataset.recordId || '';
        var listUrl  = btn.getAttribute('href')
                    || (listPage === 'cikmalar' ? 'cikmalar.php' : 'records.php');

        // Referrer kontrol: aynı domain'den ve liste sayfasından mı gelindi?
        var ref = document.referrer || '';
        var isListRef = ref.length > 0 && ref.indexOf(listPage + '.php') !== -1;

        if (isListRef) {
            // Listeden gelindi → tarayıcı geçmişi ile dön (bfcache scroll koruması)
            history.back();
            return;
        }

        // Direkt link veya başka sayfa → sessionStorage'a hedef yaz, listeye git
        if (recordId) {
            try {
                sessionStorage.setItem(sk(listPage, 'target'), recordId);
                sessionStorage.setItem(sk(listPage, 'scroll'), '0');
                sessionStorage.setItem(sk(listPage, 'ts'),     String(Date.now()));
            } catch (_) {}
        }
        window.location.href = listUrl;
    });

})();
