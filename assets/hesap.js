/* =========================================================
   assets/hesap.js — Hesap modülü davranışları
   Yalnız hesap_*.php sayfalarında yüklenir. assets/app.js'e HİÇ dokunmaz
   ve onunla hiçbir global isim paylaşmaz — hepsi HesapUI altında.

   Her parça kendi kökünü arar ve bulamazsa sessizce çıkar, böylece aynı
   dosya hem pano hem form hem liste sayfasında güvenle yüklenir.
   ========================================================= */
(function () {
    'use strict';

    var HesapUI = {};
    window.HesapUI = HesapUI;

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function csrf() {
        var el = document.querySelector('meta[name="csrf-token"]') || $('[data-hesap-csrf]');
        if (el && el.content) return el.content;
        if (el && el.value) return el.value;
        return '';
    }

    /* ══════════ 1. Alt sayfa (bottom sheet) ══════════ */

    HesapUI.sheetOpen = function (id) {
        var ovl = document.getElementById(id);
        if (!ovl) return;
        ovl.hidden = false;
        // hidden kaldırıldıktan sonra bir kare bekle ki geçiş animasyonu çalışsın
        requestAnimationFrame(function () { ovl.classList.add('open'); });
        document.body.style.overflow = 'hidden';
        var first = ovl.querySelector('.hs-sheet-item');
        if (first) first.focus();
    };

    HesapUI.sheetClose = function (ovl) {
        if (typeof ovl === 'string') ovl = document.getElementById(ovl);
        if (!ovl) return;
        ovl.classList.remove('open');
        document.body.style.overflow = '';
        window.setTimeout(function () { ovl.hidden = true; }, 200);
    };

    function initSheets() {
        $$('.hs-sheet-ovl').forEach(function (ovl) {
            // Dışına tıklayınca kapansın (içeriğe tıklama yayılmasın)
            ovl.addEventListener('click', function (e) {
                if (e.target === ovl) HesapUI.sheetClose(ovl);
            });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            var open = $('.hs-sheet-ovl.open');
            if (open) HesapUI.sheetClose(open);
        });
        $$('[data-hs-sheet]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                HesapUI.sheetOpen(btn.getAttribute('data-hs-sheet'));
            });
        });
        $$('[data-hs-sheet-close]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                HesapUI.sheetClose(btn.closest('.hs-sheet-ovl'));
            });
        });
    }

    /* ══════════ 2. Zoom katmanı ══════════ */

    HesapUI.zoom = function (src) {
        var ovl = document.createElement('div');
        ovl.className = 'hs-zoom';
        var img = document.createElement('img');
        img.src = src;
        ovl.appendChild(img);
        ovl.addEventListener('click', function () { ovl.remove(); });
        document.body.appendChild(ovl);
    };

    /* ══════════ 3. Durum geçişi ══════════ */

    HesapUI.durum = function (id, hedef, gerekceZorunlu) {
        var not = '';
        if (gerekceZorunlu) {
            not = window.prompt('Gerekçe (zorunlu):') || '';
            if (!not.trim()) { window.alert('Gerekçe girilmeden işlem yapılamaz.'); return; }
        }
        var body = new URLSearchParams({ id: id, durum: hedef, not: not, csrf: csrf() });
        fetch('hesap_durum.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) window.location.reload();
            else window.alert(d.msg || 'İşlem başarısız.');
        })
        .catch(function () { window.alert('Bağlantı hatası.'); });
    };

    function initDurumButtons() {
        $$('[data-hs-durum]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                HesapUI.durum(
                    btn.getAttribute('data-hs-id'),
                    btn.getAttribute('data-hs-durum'),
                    btn.getAttribute('data-hs-not') === '1'
                );
            });
        });
    }

    /* ══════════ 4. Tutar alanı ══════════ */

    function initAmount() {
        var inp = $('.hs-amount-input');
        if (!inp) return;

        // Yalnız rakam, virgül ve nokta; ilk odakta imleç sona
        inp.addEventListener('input', function () {
            var cleaned = inp.value.replace(/[^0-9.,]/g, '');
            if (cleaned !== inp.value) inp.value = cleaned;
        });
        inp.addEventListener('focus', function () {
            window.setTimeout(function () {
                inp.setSelectionRange(inp.value.length, inp.value.length);
            }, 0);
        });
        // Yeni kayıtta tutara odaklan (fotoğraf isteğe bağlı, tutar zorunlu).
        // Mobilde klavye hemen açılmasın diye yalnız masaüstünde.
        if (inp.dataset.autofocus === '1' && window.matchMedia('(min-width: 900px)').matches) {
            inp.focus();
        }
    }

    /* ══════════ 5. Tür seçici (gelir/gider) ══════════ */

    // Tür ve kategori için TEK yetkili alan, Detaylar içindeki <select>'lerdir.
    // Chip'ler ve tür butonları yalnız o select'leri yazar — böylece aynı isimde
    // iki alan olmaz ve JS kapalıyken form Detaylar üzerinden hâlâ doldurulabilir.
    function initTypeButtons() {
        var row = $('.hs-type-row');
        if (!row) return;
        var sel = $('#hsTypeSelect');
        row.addEventListener('click', function (e) {
            var btn = e.target.closest('.hs-type-btn');
            if (!btn) return;
            var type = btn.getAttribute('data-type');
            $$('.hs-type-btn', row).forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
            btn.setAttribute('aria-pressed', 'true');
            if (sel) sel.value = type;
            filterChips(type);
        });
    }

    /* ══════════ 6. Kategori chip'leri ══════════ */

    // Seçili türe ait olmayan kategori chip'lerini gizle; gizlenen seçiliyse bırak
    function filterChips(type) {
        var sel = $('#hsCategorySelect');
        $$('.hs-chip[data-cat-type]').forEach(function (chip) {
            var match = chip.getAttribute('data-cat-type') === type;
            chip.hidden = !match;
            if (!match && chip.getAttribute('aria-pressed') === 'true') {
                chip.setAttribute('aria-pressed', 'false');
                if (sel) sel.value = '';
            }
        });
        // Kategori <select>'inde de yalnız o türün seçenekleri görünsün
        if (sel) {
            $$('option[data-cat-type]', sel).forEach(function (o) {
                o.hidden = o.getAttribute('data-cat-type') !== type;
            });
            var cur = sel.selectedOptions[0];
            if (cur && cur.hidden) sel.value = '';
        }
    }

    function initChips() {
        var wrap = $('.hs-chips');
        var sel  = $('#hsCategorySelect');
        if (!wrap && !sel) return;

        if (wrap) {
            wrap.addEventListener('click', function (e) {
                var chip = e.target.closest('.hs-chip');
                if (!chip) return;

                // "Diğer…" → Detaylar'ı açıp tam listeye götür
                if (chip.classList.contains('hs-chip-more')) {
                    var det = $('#hsDetails');
                    if (det) {
                        det.open = true;
                        if (sel) {
                            sel.focus();
                            sel.scrollIntoView({ block: 'center', behavior: 'smooth' });
                        }
                    }
                    return;
                }

                var already = chip.getAttribute('aria-pressed') === 'true';
                $$('.hs-chip', wrap).forEach(function (c) { c.setAttribute('aria-pressed', 'false'); });
                if (!already) {
                    chip.setAttribute('aria-pressed', 'true');
                    if (sel) sel.value = chip.getAttribute('data-cat');
                } else if (sel) {
                    sel.value = '';
                }
            });
        }

        // Detaylardaki tam liste değişince chip'leri eşitle
        if (sel) {
            sel.addEventListener('change', function () {
                $$('.hs-chip').forEach(function (c) {
                    c.setAttribute('aria-pressed', String(c.getAttribute('data-cat') === sel.value));
                });
            });
        }

        // Detaylardaki tür <select>'i değişince üst butonları ve chip'leri eşitle
        var tsel = $('#hsTypeSelect');
        if (tsel) {
            tsel.addEventListener('change', function () {
                $$('.hs-type-btn').forEach(function (b) {
                    b.setAttribute('aria-pressed', String(b.getAttribute('data-type') === tsel.value));
                });
                filterChips(tsel.value);
            });
            filterChips(tsel.value);   // ilk yüklemede de uygula
        }
    }

    /* ══════════ 7. Fiş fotoğrafı: seçim, önizleme, kırpma ══════════ */

    function initPhoto() {
        var root = $('[data-hs-photo]');
        if (!root) return;

        var camInput  = $('.hs-photo-cam', root);
        var galInput  = $('.hs-photo-gal', root);
        var realInput = $('.hs-photo-real', root);
        var grid      = $('.hs-photo-grid', root);
        if (!realInput || !grid) return;

        var dt = new DataTransfer();
        var canCrop = (typeof DataTransfer !== 'undefined') && (typeof File === 'function');
        var MAX_BYTES = 10 * 1024 * 1024;

        function sync() { try { realInput.files = dt.files; } catch (e) {} }

        function add(fileList) {
            Array.prototype.forEach.call(fileList, function (f) {
                if (!f) return;
                if (f.size > MAX_BYTES) {
                    window.alert((f.name || 'Dosya') + ' 10 MB sınırını aşıyor, eklenmedi.');
                    return;
                }
                dt.items.add(f);
            });
            sync();
            render();
        }

        function removeAt(idx) {
            var ndt = new DataTransfer();
            Array.prototype.forEach.call(dt.files, function (f, i) { if (i !== idx) ndt.items.add(f); });
            dt = ndt;
            sync();
            render();
        }

        function replaceAt(idx, file) {
            var ndt = new DataTransfer();
            Array.prototype.forEach.call(dt.files, function (f, i) { ndt.items.add(i === idx ? file : f); });
            dt = ndt;
            sync();
            render();
        }

        function render() {
            grid.innerHTML = '';
            Array.prototype.forEach.call(dt.files, function (f, idx) {
                var isImg = f.type && f.type.indexOf('image/') === 0;
                var item = document.createElement('div');
                item.className = 'hs-photo-item';

                if (isImg) {
                    var img = document.createElement('img');
                    var reader = new FileReader();
                    reader.onload = function (e) { img.src = e.target.result; };
                    reader.readAsDataURL(f);
                    img.addEventListener('click', function () { HesapUI.zoom(img.src); });
                    item.appendChild(img);

                    if (canCrop) {
                        var crop = document.createElement('button');
                        crop.type = 'button';
                        crop.className = 'btn btn-sm hs-photo-crop';
                        crop.textContent = '✂ Fişi Kırp';
                        crop.addEventListener('click', function () { cropOpen(idx); });
                        item.appendChild(crop);
                    }
                } else {
                    var box = document.createElement('div');
                    box.className = 'hs-photo-file';
                    box.textContent = '📄';
                    item.appendChild(box);
                    var nm = document.createElement('div');
                    nm.className = 'hs-photo-name';
                    nm.textContent = f.name;
                    item.appendChild(nm);
                }

                var del = document.createElement('button');
                del.type = 'button';
                del.className = 'hs-photo-del';
                del.setAttribute('aria-label', 'Kaldır');
                del.textContent = '✕';
                del.addEventListener('click', function () { removeAt(idx); });
                item.appendChild(del);

                grid.appendChild(item);
            });
        }

        if (camInput) camInput.addEventListener('change', function () {
            if (this.files && this.files.length) add(this.files);
            this.value = '';
        });
        if (galInput) galInput.addEventListener('change', function () {
            if (this.files && this.files.length) add(this.files);
            this.value = '';
        });

        /* ── Kırpma ── */
        var modal  = $('.hs-crop', root);
        var cimg   = $('.hs-crop-img', root);
        var csel   = $('.hs-crop-sel', root);
        var okBtn  = $('.hs-crop-ok', root);
        var cancel = $('.hs-crop-cancel', root);
        var cropIdx = -1;
        var sel = { x: 0, y: 0, w: 0, h: 0 };
        var mode = null, startX = 0, startY = 0, startSel = null;
        var MIN = 28;

        function renderSel() {
            csel.style.left   = sel.x + 'px';
            csel.style.top    = sel.y + 'px';
            csel.style.width  = sel.w + 'px';
            csel.style.height = sel.h + 'px';
        }
        function clamp() {
            var W = cimg.offsetWidth, H = cimg.offsetHeight;
            if (sel.w > W) sel.w = W;
            if (sel.h > H) sel.h = H;
            if (sel.w < MIN) sel.w = MIN;
            if (sel.h < MIN) sel.h = MIN;
            if (sel.x < 0) sel.x = 0;
            if (sel.y < 0) sel.y = 0;
            if (sel.x + sel.w > W) sel.x = W - sel.w;
            if (sel.y + sel.h > H) sel.y = H - sel.h;
        }
        function cropOpen(idx) {
            if (!modal || !canCrop) return;
            var f = dt.files[idx];
            if (!f || f.type.indexOf('image/') !== 0) return;
            cropIdx = idx;
            var r = new FileReader();
            r.onload = function (ev) {
                cimg.onload = function () {
                    modal.hidden = false;
                    requestAnimationFrame(function () {
                        var W = cimg.offsetWidth, H = cimg.offsetHeight;
                        sel = { x: W * 0.1, y: H * 0.1, w: W * 0.8, h: H * 0.8 };
                        clamp();
                        renderSel();
                    });
                };
                cimg.src = ev.target.result;
            };
            r.readAsDataURL(f);
        }
        function cropClose() {
            if (modal) modal.hidden = true;
            cimg.src = '';
            cropIdx = -1;
            mode = null;
        }
        function point(e) {
            var t = (e.touches && e.touches.length) ? e.touches[0] : e;
            return { x: t.clientX, y: t.clientY };
        }
        function down(e) {
            var c = e.target.getAttribute && e.target.getAttribute('data-c');
            mode = c ? c : 'move';
            var p = point(e);
            startX = p.x; startY = p.y;
            startSel = { x: sel.x, y: sel.y, w: sel.w, h: sel.h };
            e.preventDefault();
        }
        function move(e) {
            if (!mode) return;
            var p = point(e);
            var dx = p.x - startX, dy = p.y - startY;
            var s = { x: startSel.x, y: startSel.y, w: startSel.w, h: startSel.h };
            var W = cimg.offsetWidth, H = cimg.offsetHeight;
            if (mode === 'move') {
                s.x += dx; s.y += dy;
            } else {
                if (mode.indexOf('l') >= 0) {
                    var nx = s.x + dx, mx = s.x + s.w - MIN;
                    if (nx < 0) nx = 0;
                    if (nx > mx) nx = mx;
                    s.w = s.x + s.w - nx; s.x = nx;
                }
                if (mode.indexOf('r') >= 0) {
                    s.w = s.w + dx;
                    if (s.w < MIN) s.w = MIN;
                    if (s.x + s.w > W) s.w = W - s.x;
                }
                if (mode.indexOf('t') >= 0) {
                    var ny = s.y + dy, my = s.y + s.h - MIN;
                    if (ny < 0) ny = 0;
                    if (ny > my) ny = my;
                    s.h = s.y + s.h - ny; s.y = ny;
                }
                if (mode.indexOf('b') >= 0) {
                    s.h = s.h + dy;
                    if (s.h < MIN) s.h = MIN;
                    if (s.y + s.h > H) s.h = H - s.y;
                }
            }
            sel = s; clamp(); renderSel();
            e.preventDefault();
        }
        function up() { mode = null; }

        function apply() {
            var W = cimg.offsetWidth, H = cimg.offsetHeight;
            var nW = cimg.naturalWidth, nH = cimg.naturalHeight;
            if (!W || !H || !nW) { cropClose(); return; }
            var rx = nW / W, ry = nH / H;
            var sx = Math.max(0, sel.x * rx), sy = Math.max(0, sel.y * ry);
            var sw = Math.min(nW - sx, sel.w * rx), sh = Math.min(nH - sy, sel.h * ry);
            var outW = sw, outH = sh, MAX = 1600;
            if (outW > MAX || outH > MAX) {
                var sc = Math.min(MAX / outW, MAX / outH);
                outW = outW * sc; outH = outH * sc;
            }
            var cv = document.createElement('canvas');
            cv.width  = Math.max(1, Math.round(outW));
            cv.height = Math.max(1, Math.round(outH));
            cv.getContext('2d').drawImage(cimg, sx, sy, sw, sh, 0, 0, cv.width, cv.height);
            var idx = cropIdx;
            cv.toBlob(function (blob) {
                if (!blob) { cropClose(); return; }
                var file = new File([blob], 'fis_' + Date.now() + '.jpg', { type: 'image/jpeg' });
                replaceAt(idx, file);
                cropClose();
            }, 'image/jpeg', 0.85);
        }

        if (csel) {
            csel.addEventListener('mousedown', down);
            csel.addEventListener('touchstart', down, { passive: false });
        }
        document.addEventListener('mousemove', move);
        document.addEventListener('touchmove', move, { passive: false });
        document.addEventListener('mouseup', up);
        document.addEventListener('touchend', up);
        if (okBtn)  okBtn.addEventListener('click', apply);
        if (cancel) cancel.addEventListener('click', cropClose);
        if (modal)  modal.addEventListener('click', function (e) { if (e.target === modal) cropClose(); });
    }

    /* ══════════ 8. Kayıtlı dosya silme ══════════ */

    function initExistingFiles() {
        $$('[data-hs-file-del]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!window.confirm('Dosya silinsin mi?')) return;
                var fid = btn.getAttribute('data-hs-file-del');
                fetch('hesap_dosya_sil.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'id=' + encodeURIComponent(fid) + '&csrf=' + encodeURIComponent(csrf())
                })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.ok) { var it = btn.closest('.hs-photo-item'); if (it) it.remove(); }
                    else window.alert(d.msg || 'Hata');
                })
                .catch(function () { window.alert('Bağlantı hatası.'); });
            });
        });
        $$('[data-hs-zoom]').forEach(function (img) {
            img.addEventListener('click', function () { HesapUI.zoom(img.src); });
        });
    }

    /* ══════════ 9. Form gönderimi ══════════ */

    function initFormGuard() {
        var form = $('#hsForm');
        if (!form) return;
        form.addEventListener('submit', function (e) {
            var amt = $('.hs-amount-input', form);
            if (amt && !amt.value.trim()) {
                e.preventDefault();
                window.alert('Tutar girmelisiniz.');
                amt.focus();
                return;
            }
            // Çift gönderimi engelle
            $$('button[type="submit"]', form).forEach(function (b) { b.disabled = true; });
            window.setTimeout(function () {
                $$('button[type="submit"]', form).forEach(function (b) { b.disabled = false; });
            }, 4000);
        });
    }

    /* ══════════ Başlat ══════════ */

    function init() {
        initSheets();
        initDurumButtons();
        initAmount();
        initTypeButtons();
        initChips();
        initPhoto();
        initExistingFiles();
        initFormGuard();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
