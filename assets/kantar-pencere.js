// assets/kantar-pencere.js — Mal Kabul Fişi Windows-tarzı yüzen pencere yönetimi (v604)
(function () {
    'use strict';

    var win = document.getElementById('mkWin');
    if (!win) return;

    var bar = document.getElementById('mkWinBar');
    var dock = document.getElementById('mkWinDock');
    var maxBtn = win.querySelector('[data-mk-win-max]');

    var MIN_W = 540;
    var MIN_H = 360;

    var isOpen = false;
    var isMinimized = false;
    var isMaximized = false;
    var hasRect = false;
    var rect = { left: 0, top: 0, width: 0, height: 0 };
    var savedRect = null;

    function isMobile() {
        return window.innerWidth < 768;
    }

    function applyRect(r) {
        // Not: #mkWin üzerinde asla CSS transform: translate kullanılmaz
        // (aksi halde içindeki position: fixed #kantarCropOverlay bozulur).
        win.style.left = Math.round(r.left) + 'px';
        win.style.top = Math.round(r.top) + 'px';
        win.style.width = Math.round(r.width) + 'px';
        win.style.height = Math.round(r.height) + 'px';
    }

    function clearInlineRect() {
        win.style.left = '';
        win.style.top = '';
        win.style.width = '';
        win.style.height = '';
    }

    function clampRect(r) {
        var vw = window.innerWidth;
        var vh = window.innerHeight;
        var minW = Math.min(MIN_W, Math.max(320, vw - 16));
        var minH = Math.min(MIN_H, Math.max(240, vh - 16));
        var w = Math.max(minW, Math.min(r.width, vw));
        var h = Math.max(minH, Math.min(r.height, vh));
        var minLeft = Math.min(0, 120 - w);
        var maxLeft = Math.max(0, vw - 120);
        var left = Math.max(minLeft, Math.min(r.left, maxLeft));
        var maxTop = Math.max(0, vh - 44);
        var top = Math.max(0, Math.min(r.top, maxTop));
        return { left: left, top: top, width: w, height: h };
    }

    function clampAndApply() {
        rect = clampRect(rect);
        applyRect(rect);
    }

    function centerWindow() {
        var vw = window.innerWidth;
        var vh = window.innerHeight;
        var width = Math.min(1180, Math.max(MIN_W, vw - 48));
        if (width > vw - 16) width = Math.max(320, vw - 16);
        var height = Math.min(Math.round(vh * 0.88), 780);
        if (height > vh - 16) height = Math.max(240, vh - 16);
        var left = Math.max(12, Math.round((vw - width) / 2));
        var top = Math.max(12, Math.round((vh - height) / 2));
        rect = { left: left, top: top, width: width, height: height };
        hasRect = true;
        applyRect(rect);
    }

    function updateMaxIcon() {
        if (!maxBtn) return;
        maxBtn.textContent = isMaximized ? '❐' : '□';
    }

    function openWin() {
        if (isOpen && isMinimized) {
            restoreFromDock();
            return;
        }
        isOpen = true;
        isMinimized = false;
        if (dock) dock.hidden = true;
        win.hidden = false;

        if (isMobile()) {
            clearInlineRect();
        } else if (isMaximized) {
            win.classList.add('is-max');
            clearInlineRect();
        } else {
            win.classList.remove('is-max');
            if (!hasRect) {
                centerWindow();
            } else {
                clampAndApply();
            }
        }
        updateMaxIcon();
    }

    function minimizeWin() {
        if (!isOpen) return;
        isMinimized = true;
        win.hidden = true;
        if (dock) dock.hidden = false;
    }

    function restoreFromDock() {
        if (!isOpen) {
            openWin();
            return;
        }
        isMinimized = false;
        if (dock) dock.hidden = true;
        win.hidden = false;
        if (isMobile()) {
            clearInlineRect();
        } else if (isMaximized) {
            win.classList.add('is-max');
            clearInlineRect();
        } else {
            win.classList.remove('is-max');
            if (hasRect) {
                clampAndApply();
            } else {
                centerWindow();
            }
        }
        updateMaxIcon();
    }

    function toggleMax() {
        if (isMobile()) return;
        if (!isMaximized) {
            savedRect = { left: rect.left, top: rect.top, width: rect.width, height: rect.height };
            isMaximized = true;
            win.classList.add('is-max');
            clearInlineRect();
        } else {
            isMaximized = false;
            win.classList.remove('is-max');
            if (savedRect && savedRect.width > 0) {
                rect = { left: savedRect.left, top: savedRect.top, width: savedRect.width, height: savedRect.height };
                hasRect = true;
                clampAndApply();
            } else {
                centerWindow();
            }
        }
        updateMaxIcon();
    }

    function closeWin() {
        isOpen = false;
        isMinimized = false;
        win.hidden = true;
        if (dock) dock.hidden = true;
    }

    // ── Başlık çubuğu ile sürükleme ──────────────────────────────────────────
    if (bar) {
        bar.addEventListener('pointerdown', function (e) {
            if (e.button !== 0 || isMobile()) return;
            if (e.target.closest('button, a, input, select, textarea')) return;

            e.preventDefault();

            // Tam ekranken sürüklemeye başlanırsa Windows gibi imleç altında normal boyuta dön
            if (isMaximized) {
                var vw = Math.max(1, window.innerWidth);
                var ratio = Math.max(0.1, Math.min(0.9, e.clientX / vw));
                var nw = (savedRect && savedRect.width > 0) ? savedRect.width : Math.min(1180, window.innerWidth - 48);
                var nh = (savedRect && savedRect.height > 0) ? savedRect.height : Math.min(Math.round(window.innerHeight * 0.88), 780);
                isMaximized = false;
                win.classList.remove('is-max');
                updateMaxIcon();
                rect = clampRect({
                    left: Math.round(e.clientX - nw * ratio),
                    top: Math.max(0, Math.round(e.clientY - 18)),
                    width: nw,
                    height: nh
                });
                hasRect = true;
                applyRect(rect);
            }

            var startX = e.clientX;
            var startY = e.clientY;
            var origLeft = rect.left;
            var origTop = rect.top;

            document.body.classList.add('mk-win-dragging');

            function onMove(ev) {
                var nx = origLeft + (ev.clientX - startX);
                var ny = origTop + (ev.clientY - startY);
                var vwNow = window.innerWidth;
                var vhNow = window.innerHeight;
                var minLeft = Math.min(0, 120 - rect.width);
                var maxLeft = Math.max(0, vwNow - 120);
                var maxTop = Math.max(0, vhNow - 44);
                rect.left = Math.max(minLeft, Math.min(nx, maxLeft));
                rect.top = Math.max(0, Math.min(ny, maxTop));
                applyRect(rect);
            }

            function onUp() {
                document.body.classList.remove('mk-win-dragging');
                window.removeEventListener('pointermove', onMove);
                window.removeEventListener('pointerup', onUp);
                window.removeEventListener('pointercancel', onUp);
            }

            window.addEventListener('pointermove', onMove);
            window.addEventListener('pointerup', onUp);
            window.addEventListener('pointercancel', onUp);
        });

        bar.addEventListener('dblclick', function (e) {
            if (isMobile()) return;
            if (e.target.closest('button, a, input')) return;
            toggleMax();
        });
    }

    // ── 8 yönlü boyutlandırma ────────────────────────────────────────────────
    win.querySelectorAll('.mk-win-rh[data-dir]').forEach(function (handle) {
        handle.addEventListener('pointerdown', function (e) {
            if (e.button !== 0 || isMobile() || isMaximized) return;
            e.preventDefault();

            var dir = handle.getAttribute('data-dir') || '';
            var startX = e.clientX;
            var startY = e.clientY;
            var orig = { left: rect.left, top: rect.top, width: rect.width, height: rect.height };

            document.body.classList.add('mk-win-dragging');

            function onMove(ev) {
                var dx = ev.clientX - startX;
                var dy = ev.clientY - startY;
                var vw = window.innerWidth;
                var vh = window.innerHeight;
                var minW = Math.min(MIN_W, Math.max(320, vw - 16));
                var minH = Math.min(MIN_H, Math.max(240, vh - 16));

                var left = orig.left;
                var top = orig.top;
                var right = orig.left + orig.width;
                var bottom = orig.top + orig.height;

                if (dir.indexOf('e') !== -1) {
                    right = Math.min(vw, Math.max(left + minW, right + dx));
                }
                if (dir.indexOf('s') !== -1) {
                    bottom = Math.min(vh, Math.max(top + minH, bottom + dy));
                }
                if (dir.indexOf('w') !== -1) {
                    left = Math.max(0, Math.min(right - minW, left + dx));
                }
                if (dir.indexOf('n') !== -1) {
                    top = Math.max(0, Math.min(bottom - minH, top + dy));
                }

                rect = {
                    left: left,
                    top: top,
                    width: right - left,
                    height: bottom - top
                };
                applyRect(rect);
            }

            function onUp() {
                document.body.classList.remove('mk-win-dragging');
                window.removeEventListener('pointermove', onMove);
                window.removeEventListener('pointerup', onUp);
                window.removeEventListener('pointercancel', onUp);
            }

            window.addEventListener('pointermove', onMove);
            window.addEventListener('pointerup', onUp);
            window.addEventListener('pointercancel', onUp);
        });
    });

    // ── Delegated tıklama olayları ───────────────────────────────────────────
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-mk-win-open]')) {
            e.preventDefault();
            openWin();
            return;
        }
        if (e.target.closest('[data-mk-win-min]')) {
            e.preventDefault();
            minimizeWin();
            return;
        }
        if (e.target.closest('[data-mk-win-max]')) {
            e.preventDefault();
            toggleMax();
            return;
        }
        if (e.target.closest('[data-mk-win-close]')) {
            e.preventDefault();
            closeWin();
            return;
        }
        if (e.target.closest('[data-mk-win-restore]')) {
            e.preventDefault();
            restoreFromDock();
            return;
        }
    });

    // ── ESC tuşu (fotoğraf kırpma katmanı veya combobox açıkken kapatma) ─────
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || !isOpen || isMinimized) return;
        var crop = document.getElementById('kantarCropOverlay');
        if (crop && !crop.hidden && window.getComputedStyle(crop).display !== 'none') return;
        if (win.querySelector('.mk-cb-list:not([hidden])')) return;
        closeWin();
    });

    // ── Ekran boyutu değişince konum/boyutu koru ─────────────────────────────
    window.addEventListener('resize', function () {
        if (!isOpen || isMinimized) return;
        if (isMobile()) {
            clearInlineRect();
        } else if (isMaximized) {
            win.classList.add('is-max');
            clearInlineRect();
        } else if (hasRect) {
            clampAndApply();
        } else {
            centerWindow();
        }
    });

    // ── Kayıt tamamlandı olayı ───────────────────────────────────────────────
    window.addEventListener('mk-win-saved', function () {
        closeWin();
        window.location.reload();
    });

    // ── ?yeni=1 ile otomatik açılış ──────────────────────────────────────────
    function checkAutoOpen() {
        if (win.dataset.autoOpen !== '1') return;
        openWin();
        try {
            var u = new URL(window.location.href);
            if (u.searchParams.has('yeni')) {
                u.searchParams.delete('yeni');
                window.history.replaceState({}, '', u.pathname + (u.search ? u.search : '') + u.hash);
            }
        } catch (_) {}
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', checkAutoOpen);
    } else {
        checkAutoOpen();
    }
})();
