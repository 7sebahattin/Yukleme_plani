<?php
// =========================================================
// hks/index.php — Hal Bildirimi embed sayfası
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';   // require_login() bu dosyada

// Resmi Hal Bildirimi doğrudan adresi — "Yeni Sekmede Aç" butonu için.
$hal_direct_url = 'https://hks.hal.gov.tr/Pages/Account/Login.aspx';

// iframe, HKS giriş sayfasını PROXY üzerinden açar (same-origin → çerez
// sorunsuz tutulur, giriş çalışır). __hksfresh: her açılışta temiz oturum.
$hal_proxy_url = 'proxy.php/Pages/Account/Login.aspx?__hksfresh=1';

render_header('Hal Bildirimi');
render_flash();
?>

<style>
/* ── Hal Bildirimi embed — sayfa özel stiller ── */
.hal-embed-wrap {
    display: flex;
    flex-direction: column;
    gap: 8px;
    /* İframe ile birlikte sayfa tam ekranı doldursun */
    height: calc(100vh - 130px);
}

.hal-embed-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    padding: 8px 10px;
    background: var(--surface, #fff);
    border: 1px solid var(--border, #e0e0e0);
    border-radius: 8px;
    flex-shrink: 0;
}

.hal-embed-toolbar-url {
    flex: 1 1 auto;
    font-size: .78rem;
    color: var(--muted, #888);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    min-width: 0;
}

.hal-embed-toolbar-actions {
    display: flex;
    gap: 5px;
    flex-shrink: 0;
}

/* ── Otomasyon paneli ── */
.hal-auto {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 12px;
    padding: 8px 10px;
    background: var(--surface, #fff);
    border: 1px solid var(--border, #e0e0e0);
    border-radius: 8px;
    flex-shrink: 0;
}

.hal-auto-field {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: .8rem;
    font-weight: 600;
    color: var(--text, #333);
}

.hal-auto-field input {
    width: 96px;
    padding: 5px 7px;
    border: 1px solid var(--border, #ccc);
    border-radius: 5px;
    font-size: .85rem;
}

.hal-auto-live {
    flex: 1 1 240px;
    min-width: 0;
    font-size: .82rem;
    color: var(--muted, #666);
    text-align: right;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.hal-auto-status {
    width: 100%;
    font-size: .78rem;
    color: var(--muted, #888);
    min-height: 1em;
}

.hal-auto-status.ok    { color: #2e7d32; }
.hal-auto-status.warn  { color: #c62828; }

.hal-embed-frame-box {
    flex: 1 1 auto;
    border: 1px solid var(--border, #e0e0e0);
    border-radius: 8px;
    overflow: hidden;
    position: relative;
    min-height: 0;
}

.hal-embed-frame {
    display: block;
    width: 100%;
    height: 100%;
    border: none;
    background: #fff;
}

.hal-embed-security {
    font-size: .75rem;
    color: var(--muted, #888);
    padding: 2px 2px;
    flex-shrink: 0;
}

/* Mobil: bottomnav için alt boşluk */
@media (max-width: 767px) {
    .hal-embed-wrap {
        height: calc(100vh - 230px);
    }

    .hal-embed-toolbar-url {
        display: none; /* Dar ekranda URL chip'i gizle */
    }

    .hal-embed-toolbar-actions .btn {
        font-size: .78rem;
        padding: 6px 8px;
    }

    .hal-auto-live {
        flex-basis: 100%;
        text-align: left;
    }
}

/* Geniş desktop: sidebar var, daha fazla yer */
@media (min-width: 900px) {
    .hal-embed-wrap {
        height: calc(100vh - 150px);
    }
}
</style>

<div class="hal-embed-wrap">

    <!-- Kontrol barı -->
    <div class="hal-embed-toolbar">
        <span class="hal-embed-toolbar-url">🌐 <?= htmlspecialchars($hal_direct_url, ENT_QUOTES, 'UTF-8') ?></span>
        <div class="hal-embed-toolbar-actions">
            <button class="btn btn-ghost btn-sm" id="hal-btn-reload">🔄 Yenile</button>
            <a href="<?= htmlspecialchars($hal_direct_url, ENT_QUOTES, 'UTF-8') ?>"
               target="_blank" rel="noopener noreferrer"
               class="btn btn-ghost btn-sm">↗ Yeni Sekmede Aç</a>
            <a href="../index.php" class="btn btn-ghost btn-sm">🏠 Ana Sayfa</a>
        </div>
    </div>

    <!-- Otomasyon paneli — Mevcut Miktar → Miktar, Fiyat doldur; canlı toplam -->
    <div class="hal-auto">
        <label class="hal-auto-field">Hedef Kilo
            <input type="text" id="auto-hedef" inputmode="decimal" placeholder="24500">
        </label>
        <label class="hal-auto-field">Birim Fiyat
            <input type="text" id="auto-fiyat" inputmode="decimal" placeholder="45">
        </label>
        <button class="btn btn-primary btn-sm" id="auto-fill-btn">⚡ Formu Doldur</button>
        <span class="hal-auto-live" id="auto-live"><span class="muted">Toplam bekleniyor…</span></span>
        <div class="hal-auto-status" id="auto-status"></div>
    </div>

    <!-- iframe — HKS giriş sayfasını proxy üzerinden (same-origin) açar -->
    <div class="hal-embed-frame-box">
        <iframe
            id="hal-frame"
            class="hal-embed-frame"
            src="<?= htmlspecialchars($hal_proxy_url, ENT_QUOTES, 'UTF-8') ?>"
            title="Hal Bildirimi Sistemi"
        ></iframe>
    </div>

    <!-- Güvenlik notu -->
    <p class="hal-embed-security">
        🔒 Bu uygulama kullanıcı adı/şifrenizi kaydetmez. Giriş resmi site üzerinde yapılır.
    </p>

</div>

<script>
(function () {
    var frame = document.getElementById('hal-frame');

    // ── Yenile ──
    document.getElementById('hal-btn-reload').addEventListener('click', function () {
        frame.src = frame.src;
    });

    // ── Yardımcılar (yer işaretindeki mantık) ──
    function parseTr(s) {
        s = (s || '').replace(/KG/gi, '').replace(/\s+/g, '').trim();
        if (!s) return NaN;
        s = s.replace(/\./g, '').replace(',', '.');
        return parseFloat(s);
    }
    function fmtTr(n) {
        return n.toLocaleString('tr-TR', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
    }
    function norm(s) {
        return (s || '').replace(/I/g, 'ı').replace(/İ/g, 'i').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    // iframe belgesine same-origin erişim (proxy sayesinde mümkün)
    function frameDoc() {
        try { return frame.contentDocument || frame.contentWindow.document; }
        catch (e) { return null; }
    }

    // ── Ayar kalıcılığı (tarayıcı localStorage) ──
    var elHedef  = document.getElementById('auto-hedef');
    var elFiyat  = document.getElementById('auto-fiyat');
    var elLive   = document.getElementById('auto-live');
    var elStatus = document.getElementById('auto-status');

    elHedef.value = localStorage.getItem('hks_auto_hedef') || '';
    elFiyat.value = localStorage.getItem('hks_auto_fiyat') || '';
    elHedef.addEventListener('input', function () { localStorage.setItem('hks_auto_hedef', elHedef.value); updateLive(); });
    elFiyat.addEventListener('input', function () { localStorage.setItem('hks_auto_fiyat', elFiyat.value); });

    function setStatus(msg, kind) {
        elStatus.textContent = msg || '';
        elStatus.className = 'hal-auto-status' + (kind ? ' ' + kind : '');
    }

    // ── Form doldurma (eklenti content.js'in OCR'sız hali) ──
    function setVal(d, id, val) {
        var el = d.getElementById(id);
        if (!el) return false;
        el.value = val;
        el.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    }

    function fillForm() {
        var d = frameDoc();
        if (!d) { setStatus('Form alanına erişilemiyor (sayfa yükleniyor olabilir).', 'warn'); return; }

        var mevcutEl = d.getElementById('ContentPlaceHolder1_Wizard1_txtMevcutMiktar');
        if (!mevcutEl) { setStatus('Bu sayfada doldurulacak bildirim formu yok.', 'warn'); return; }

        var mevcut = mevcutEl.value;
        var okMiktar = setVal(d, 'ContentPlaceHolder1_Wizard1_Miktar1_txtMiktar', mevcut);

        var okFiyat = true;
        var fiyat = elFiyat.value.trim();
        if (fiyat) okFiyat = setVal(d, 'ContentPlaceHolder1_Wizard1_Fiyat1_txtFiyat', fiyat);

        // İmleci CAPTCHA kutusuna getir — kullanıcı kodu yazıp Giriş'e basar
        var cap = d.getElementById('ContentPlaceHolder1_Wizard1_txtCaptchaCodeTextBox');
        if (cap) cap.focus();

        var parts = [];
        if (okMiktar) parts.push('Miktar: ' + mevcut);
        if (fiyat && okFiyat) parts.push('Fiyat: ' + fiyat);
        setStatus('✓ Dolduruldu (' + parts.join(', ') + '). CAPTCHA\'yı yazıp Giriş\'e basın.', 'ok');
    }

    document.getElementById('auto-fill-btn').addEventListener('click', fillForm);

    // ── Canlı toplam ("Malın Miktarı" sütunu) ──
    function computeTotal(d) {
        var cells = d.querySelectorAll('th, td');
        var header = null;
        for (var i = 0; i < cells.length; i++) {
            if (norm(cells[i].innerText) === norm('Malın Miktarı')) { header = cells[i]; break; }
        }
        if (!header) return null;
        var idx = header.cellIndex;
        var table = header.closest('table');
        if (!table) return null;

        var total = 0, rows = 0;
        Array.prototype.forEach.call(table.rows, function (r) {
            if (r.contains(header)) return;
            var c = r.cells[idx];
            if (!c) return;
            var v = parseTr(c.innerText);
            if (!isNaN(v) && v > 0) { total += v; rows++; }
        });
        return { total: total, rows: rows };
    }

    function updateLive() {
        var d = frameDoc();
        if (!d) { elLive.innerHTML = '<span class="muted">Sayfa yükleniyor…</span>'; return; }

        var res = computeTotal(d);
        if (!res) { elLive.innerHTML = '<span class="muted">Bu sayfada toplam tablosu yok.</span>'; return; }

        var hedef = parseTr(elHedef.value);
        var html = 'Satır: <b>' + res.rows + '</b> · Toplam: <b>' + fmtTr(res.total) + ' KG</b>';
        if (!isNaN(hedef)) {
            var diff = hedef - res.total;
            html += ' · Hedef: <b>' + fmtTr(hedef) + '</b>';
            if (diff > 0)      html += ' · <b style="color:#c62828">Eksik: ' + fmtTr(diff) + ' KG</b>';
            else if (diff < 0) html += ' · <b style="color:#2e7d32">Fazla: ' + fmtTr(Math.abs(diff)) + ' KG</b>';
            else               html += ' · <b style="color:#2e7d32">Tam tuttu ✅</b>';
        }
        elLive.innerHTML = html;
    }

    // iframe her yüklendiğinde + periyodik olarak güncelle
    frame.addEventListener('load', updateLive);
    setInterval(updateLive, 2000);
})();
</script>

<?php render_footer(); ?>
