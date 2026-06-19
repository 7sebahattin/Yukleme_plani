<?php
// HKS Ham Servis Teşhis Ekranı — sadece admin
declare(strict_types=1);
require_once __DIR__ . '/includes/hks_bootstrap.php';
$auth_user = require_login();
if (!is_admin()) {
    http_response_code(403); die('Bu sayfa yalnızca yöneticiler içindir.');
}

$repo   = new HksRepository(db());
$client = new HksClient($repo);
$s      = $repo->getSettings();
$env    = $s['environment'] ?? 'test';
$firma_vkn    = trim($s['firma_vkn'] ?? '');
$has_settings = $client->hasSettings();
$has_soap     = hks_check_soap();
$env_color    = $env === 'live' ? '#dc2626' : '#d97706';
$env_label    = $env === 'live' ? '🔴 CANLI' : '🟡 TEST';

render_header('HKS Teşhis');
render_flash();
?>
<div class="hks-page" style="max-width:1100px;margin:0 auto">
<?php
$hks_active_tab = 'teshis.php';
include __DIR__ . '/views/_tabs.php';
?>

<div class="page-head" style="margin-top:16px">
    <div>
        <h1>🔬 HKS Servis Teşhis</h1>
        <p class="muted">Raw SOAP yanıt katmanlarını incele — veri neden gelmiyor?</p>
    </div>
    <div class="page-head-actions">
        <span style="font-weight:700;color:<?= $env_color ?>;font-size:.9rem;padding:6px 12px;
            background:<?= $env === 'live' ? '#fef2f2' : '#fffbeb' ?>;border-radius:20px;
            border:1px solid <?= $env_color ?>"><?= $env_label ?></span>
    </div>
</div>

<?php if (!$has_soap): ?>
<div class="hks-warning-box">⛔ PHP SOAP extension yüklü değil.</div>
<?php elseif (!$has_settings): ?>
<div class="hks-warning-box">⛔ HKS ayarları yapılandırılmamış. <a href="ayarlar.php">Ayarlar →</a></div>
<?php else: ?>

<!-- Ortam bilgisi -->
<div class="card" style="padding:12px 16px;margin-bottom:16px;font-size:.83rem;line-height:1.8">
    <b>Ortam:</b> <?= hks_h(strtoupper($env)) ?> &nbsp;|&nbsp;
    <b>Urun WSDL:</b> <code style="font-size:.75rem"><?= hks_h($client->urunWsdl()) ?></code><br>
    <b>Bildirim WSDL:</b> <code style="font-size:.75rem"><?= hks_h($client->bildirimWsdl()) ?></code><br>
    <b>Firma VKN:</b> <?= $firma_vkn !== '' ? hks_h($firma_vkn) : '<em style="color:var(--muted)">Tanımlı değil — Ayarlar\'dan ekle</em>' ?>
</div>

<!-- Parametreler -->
<div class="card" style="padding:14px 16px;margin-bottom:20px">
    <p style="font-weight:600;font-size:.85rem;margin:0 0 10px">Sorgu Parametreleri</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px;align-items:end">
        <div>
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:3px">TC / VKN (KisiSorgu)</label>
            <input type="text" id="p_tc_vkn" maxlength="11" placeholder="11 TC veya 10 VKN"
                   style="width:100%;box-sizing:border-box;padding:7px 9px;border:1px solid var(--border);border-radius:6px;font-size:.88rem">
        </div>
        <div>
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:3px">Firma VKN</label>
            <input type="text" id="p_firma_vkn" value="<?= hks_h($firma_vkn) ?>" placeholder="Ayarlardan alınır"
                   style="width:100%;box-sizing:border-box;padding:7px 9px;border:1px solid var(--border);border-radius:6px;font-size:.88rem">
        </div>
        <div>
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:3px">UrunId (ReferansKunyeler)</label>
            <input type="text" id="p_urun_id" placeholder="örn. 339 (ELMA), 335 (DOMATES)"
                   style="width:100%;box-sizing:border-box;padding:7px 9px;border:1px solid var(--border);border-radius:6px;font-size:.88rem">
        </div>
        <div>
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:3px">Başlangıç</label>
            <input type="date" id="p_bas" value="<?= date('Y-m-d', strtotime('-90 days')) ?>"
                   style="width:100%;box-sizing:border-box;padding:7px 9px;border:1px solid var(--border);border-radius:6px;font-size:.88rem">
        </div>
        <div>
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:3px">Bitiş</label>
            <input type="date" id="p_bit" value="<?= date('Y-m-d') ?>"
                   style="width:100%;box-sizing:border-box;padding:7px 9px;border:1px solid var(--border);border-radius:6px;font-size:.88rem">
        </div>
    </div>
</div>

<!-- Özet paneli (testler tamamlandıkça dolar) -->
<div id="summary-box" style="display:none" class="card" >
    <p style="font-weight:700;font-size:.88rem;margin:0 0 8px">📋 Teşhis Özeti</p>
    <div id="summary-rows"></div>
</div>

<!-- ── Test 1: UrunServiceUrunler ───────────────────────── -->
<div class="card" style="padding:14px 16px;margin-bottom:14px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
        <div>
            <b>1. UrunServiceUrunler</b>
            <span style="display:block;font-size:.78rem;color:var(--muted)">Ürün listesi — parametre yok</span>
        </div>
        <button class="btn btn-primary btn-sm" data-panel="p1" data-wsdl="urun"
                data-method="UrunServiceUrunler" data-variant="simple">🔬 Test Et</button>
    </div>
    <div id="result-p1" style="display:none;margin-top:10px"></div>
</div>

<!-- ── Test 2: KayitliKisiSorgu ─────────────────────────── -->
<div class="card" style="padding:14px 16px;margin-bottom:14px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
        <div>
            <b>2. BildirimServisKayitliKisiSorgu</b>
            <span style="display:block;font-size:.78rem;color:var(--muted)">TC/VKN doğrulama — 2 format</span>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            <button class="btn btn-ghost btn-sm" data-panel="p2" data-wsdl="bildirim"
                    data-method="BildirimServisKayitliKisiSorgu" data-variant="kisi_a">Format A: [tc]</button>
            <button class="btn btn-ghost btn-sm" data-panel="p2" data-wsdl="bildirim"
                    data-method="BildirimServisKayitliKisiSorgu" data-variant="kisi_b">Format B: {string:[tc]}</button>
        </div>
    </div>
    <div id="result-p2" style="display:none;margin-top:10px"></div>
</div>

<!-- ── Test 3: ReferansKunyeler ──────────────────────────── -->
<div class="card" style="padding:14px 16px;margin-bottom:14px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
        <div>
            <b>3. BildirimServisReferansKunyeler</b>
            <span style="display:block;font-size:.78rem;color:var(--muted)">Firma VKN + KunyeNo=0</span>
        </div>
        <button class="btn btn-primary btn-sm" data-panel="p3" data-wsdl="bildirim"
                data-method="BildirimServisReferansKunyeler" data-variant="kunye">🔬 Test Et</button>
    </div>
    <div id="result-p3" style="display:none;margin-top:10px"></div>
</div>

<!-- ── Test 4: YaptigimBildirimler ──────────────────────── -->
<div class="card" style="padding:14px 16px;margin-bottom:14px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
        <div>
            <b>4. BildirimServisBildirimcininYaptigiBildirimListesi</b>
            <span style="display:block;font-size:.78rem;color:var(--muted)">Yaptığım bildirimler — 2 tarih formatı</span>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            <button class="btn btn-ghost btn-sm" data-panel="p4" data-wsdl="bildirim"
                    data-method="BildirimServisBildirimcininYaptigiBildirimListesi" data-variant="bl_iso">Tarih A: ISO</button>
            <button class="btn btn-ghost btn-sm" data-panel="p4" data-wsdl="bildirim"
                    data-method="BildirimServisBildirimcininYaptigiBildirimListesi" data-variant="bl_tr">Tarih B: DD.MM.YYYY</button>
        </div>
    </div>
    <div id="result-p4" style="display:none;margin-top:10px"></div>
</div>

<!-- ── Test 5: BanaYapilanBildirimler ───────────────────── -->
<div class="card" style="padding:14px 16px;margin-bottom:14px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
        <div>
            <b>5. BildirimServisBildirimciyeYapilanBildirimListesi</b>
            <span style="display:block;font-size:.78rem;color:var(--muted)">Bana yapılan bildirimler — 2 tarih formatı</span>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            <button class="btn btn-ghost btn-sm" data-panel="p5" data-wsdl="bildirim"
                    data-method="BildirimServisBildirimciyeYapilanBildirimListesi" data-variant="bl_iso">Tarih A: ISO</button>
            <button class="btn btn-ghost btn-sm" data-panel="p5" data-wsdl="bildirim"
                    data-method="BildirimServisBildirimciyeYapilanBildirimListesi" data-variant="bl_tr">Tarih B: DD.MM.YYYY</button>
        </div>
    </div>
    <div id="result-p5" style="display:none;margin-top:10px"></div>
</div>

<?php endif; ?>
</div><!-- /.hks-page -->

<style>
.diag-row { display:grid; grid-template-columns:200px 1fr; gap:4px 10px; align-items:baseline;
            padding:3px 0; border-bottom:1px solid var(--border); font-size:.82rem; }
.diag-row:last-child { border-bottom:none; }
.diag-key  { color:var(--muted); font-size:.78rem; }
.diag-val  { font-family:monospace; font-size:.78rem; word-break:break-all; }
.verdict { font-weight:700; font-size:1rem; }
.v-PASS        { color:#065f46; }
.v-PARSE_BUG   { color:#7c3aed; }
.v-HKS_BOŞ     { color:#92400e; }
.v-HKS_HATA    { color:#991b1b; }
.v-SOAP_HATASI { color:#991b1b; }
.v-PATH_HATASI { color:#7c3aed; }
.v-PREREQ      { color:#374151; }
.v-TEKNİK_HATA { color:#dc2626; }
.v-BİLİNMEYEN  { color:#374151; }
.diag-xml { font-size:.7rem; white-space:pre-wrap; overflow-x:auto; max-height:280px;
            background:#f8fafc; padding:8px; border:1px solid var(--border);
            border-radius:4px; margin:4px 0; }
.diag-bug-note { background:#f5f3ff; border:1px solid #7c3aed; border-radius:6px;
                 padding:8px 12px; margin:8px 0; font-size:.82rem; color:#4c1d95; }
</style>

<script>
var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
var summaryData = [];

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function emoji(v) {
    return {PASS:'✅',PARSE_BUG:'🐛',HKS_BOŞ:'📭',HKS_HATA:'❌',
            SOAP_HATASI:'🔌',PATH_HATASI:'🗂️',PREREQ:'⚠️',
            BİLİNMEYEN:'❓',TEKNİK_HATA:'💥'}[v] || '?';
}

function borderColor(v) {
    if (v === 'PASS')     return '#22c55e';
    if (v === 'HKS_BOŞ') return '#f59e0b';
    if (v === 'PARSE_BUG' || v === 'PATH_HATASI') return '#7c3aed';
    return '#ef4444';
}

function renderResult(panelId, d, btnLabel) {
    var el = document.getElementById('result-' + panelId);
    el.style.display = 'block';

    var verdict = d.verdict || (d.ok ? 'PASS' : 'SOAP_HATASI');
    var islemKodu = d.islem_kodu || '—';
    var hata = d.hatalar
        ? (typeof d.hatalar === 'object' ? JSON.stringify(d.hatalar) : String(d.hatalar))
        : (d.error || '—');

    var rows = [
        ['IslemKodu', islemKodu],
        ['Hata / error', hata],
        ['raw __soapCall tipi', d.raw_type || '—'],
        ['raw nesne key\'leri', (d.raw_keys || []).join(', ') || '—'],
        ['data[0] key\'leri', (d.first_keys || []).join(', ') || '—'],
        ['Liste alanı (listField)', d.list_field || '(bulunamadı)'],
        ['Liste içeriği (listDesc)', d.list_desc || '—'],
        ['İç alt liste (DTO alanı)', d.list_sub_list_field || '—'],
        ['HAM gerçek kayıt sayısı', (d.real_count ?? '?') + ' kayıt'],
        ['Sistem parse sayısı', (d.diag_count ?? '?') + ' kayıt (kategori: ' + (d.diag_category || '?') + ')'],
        ['Süre', (d.duration_ms || 0) + ' ms'],
    ];

    var sampleHtml = '';
    if (d.sample_record) {
        sampleHtml = '<details style="margin-top:6px"><summary style="cursor:pointer;font-weight:600;font-size:.8rem">'
            + 'Örnek Kayıt (ilk DTO)</summary>'
            + '<pre class="diag-xml">' + esc(JSON.stringify(d.sample_record, null, 2)) + '</pre></details>';
    }

    var rowsHtml = rows.map(function(r) {
        return '<div class="diag-row"><span class="diag-key">' + esc(r[0]) + '</span>'
             + '<span class="diag-val">' + esc(String(r[1])) + '</span></div>';
    }).join('');

    var bugNote = '';
    if (verdict === 'PARSE_BUG') {
        bugNote = '<div class="diag-bug-note">'
            + '<strong>🐛 PARSE BUG tespit edildi:</strong> HKS başarılı yanıt verdi. '
            + '<code>' + esc(d.list_field) + '</code> alanı bulundu '
            + '(<code>' + esc(d.list_desc) + '</code>). '
            + 'Alt liste <strong>' + esc(d.list_sub_list_field || '') + '</strong> içinde '
            + '<strong>' + (d.list_sub_list_count || 0) + ' kayıt</strong> var. '
            + 'Sistem yalnızca <strong>' + (d.diag_count || 0) + '</strong> kayıt çıkardı. '
            + '<br>Mapper <code>Sonuc</code> wrapper\'ını doğru parse etmiyor — iç liste alanına inmiyor.</div>';
    }

    var xmlHtml = '';
    if (d.request_xml) {
        xmlHtml += '<details style="margin-top:8px"><summary style="cursor:pointer;font-weight:600;font-size:.8rem">'
            + 'SOAP İstek XML (' + d.request_xml.length + ' B)</summary>'
            + '<pre class="diag-xml">' + esc(d.request_xml) + '</pre></details>';
    }
    if (d.response_xml) {
        xmlHtml += '<details style="margin-top:4px"><summary style="cursor:pointer;font-weight:600;font-size:.8rem">'
            + 'SOAP Yanıt XML (' + d.response_xml.length + ' B)</summary>'
            + '<pre class="diag-xml">' + esc(d.response_xml) + '</pre></details>';
    }

    el.innerHTML = '<div style="border-top:2px solid ' + borderColor(verdict) + ';padding-top:10px">'
        + '<div style="margin-bottom:6px">'
        + '<span class="verdict v-' + verdict.replace(/[^A-ZÇĞİÖŞÜa-zçğışöüÂ_]/g,'') + '">'
        + emoji(verdict) + ' ' + esc(verdict) + '</span>'
        + (btnLabel ? ' <span style="color:var(--muted);font-size:.78rem">— ' + esc(btnLabel) + '</span>' : '')
        + '</div>'
        + bugNote
        + '<div style="background:#f8fafc;border:1px solid var(--border);border-radius:6px;padding:8px 10px">'
        + rowsHtml + '</div>' + sampleHtml + xmlHtml + '</div>';

    // Özet
    summaryData.push({ panel: panelId, verdict: verdict, label: btnLabel || verdict });
    var sb = document.getElementById('summary-box');
    var sr = document.getElementById('summary-rows');
    if (sb && sr) {
        sb.style.display = 'block';
        var bgMap = {PASS:'#f0fdf4',HKS_BOŞ:'#fffbeb',PARSE_BUG:'#f5f3ff',PATH_HATASI:'#f5f3ff'};
        var bg = bgMap[verdict] || '#fef2f2';
        sr.innerHTML += '<div style="display:flex;align-items:center;gap:10px;padding:5px 8px;background:' + bg + ';border-radius:4px;margin-bottom:4px">'
            + '<span class="verdict v-' + verdict.replace(/[^A-ZÇĞİÖŞÜa-zçğışöüÂ_]/g,'') + '" style="font-size:.85rem;min-width:140px">'
            + emoji(verdict) + ' ' + esc(verdict) + '</span>'
            + '<span style="font-size:.79rem;color:var(--muted)">' + esc(btnLabel || '') + '</span>'
            + '</div>';
    }
}

document.querySelectorAll('[data-panel]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var panel   = this.dataset.panel;
        var wsdl    = this.dataset.wsdl;
        var method  = this.dataset.method;
        var variant = this.dataset.variant;
        var label   = this.textContent.trim();

        var tcvkn = (document.getElementById('p_tc_vkn').value || '').trim();
        var fvkn  = (document.getElementById('p_firma_vkn').value || '').trim();
        var bas   = document.getElementById('p_bas').value;
        var bit   = document.getElementById('p_bit').value;

        function toIso(d) { return d ? d + 'T00:00:00' : ''; }
        function toTr(d) {
            if (!d) return '';
            var p = d.split('-');
            return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : d;
        }

        var istek = {};

        if (variant === 'simple') {
            // Parametresiz — UrunServiceUrunler
        } else if (variant === 'kisi_a') {
            if (!tcvkn) { alert('TC/VKN girin.'); return; }
            istek = { TcKimlikVergiNolar: [tcvkn] };
        } else if (variant === 'kisi_b') {
            if (!tcvkn) { alert('TC/VKN girin.'); return; }
            istek = { TcKimlikVergiNolar: { string: [tcvkn] } };
        } else if (variant === 'kunye') {
            istek = { KunyeNo: '0' };
            if (fvkn) istek.MalinSahibiTcKimlikVergiNo = fvkn;
            var urunId = (document.getElementById('p_urun_id').value || '').trim();
            if (urunId) istek.UrunId = urunId;
        } else if (variant === 'bl_iso') {
            istek = { BaslangicTarihi: toIso(bas), BitisTarihi: toIso(bit), KunyeTuru: '1', KunyeNo: '0' };
        } else if (variant === 'bl_tr') {
            istek = { BaslangicTarihi: toTr(bas), BitisTarihi: toTr(bit), KunyeTuru: '1', KunyeNo: '0' };
        }

        this.disabled = true;
        var orig = this.textContent;
        this.textContent = '⏳';

        fetch('ajax.php?action=diag_raw_call', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'csrf=' + encodeURIComponent(csrfToken)
                + '&wsdl_type=' + encodeURIComponent(wsdl)
                + '&method='    + encodeURIComponent(method)
                + '&istek='     + encodeURIComponent(JSON.stringify(istek))
        })
        .then(function(r) { return r.json(); })
        .then(function(d) { renderResult(panel, d, label); })
        .catch(function(e) {
            var el = document.getElementById('result-' + panel);
            el.style.display = 'block';
            el.innerHTML = '<div style="color:#991b1b;font-size:.83rem">❌ İstek gönderilemedi: ' + esc(e.message) + '</div>';
        })
        .finally(function() { btn.disabled = false; btn.textContent = orig; });
    });
});
</script>

<?php render_footer(); ?>
