<?php
// HKS Bağlantı Testi
declare(strict_types=1);
require_once __DIR__ . '/includes/hks_bootstrap.php';
$auth_user = require_login();
if (!is_admin() && !(function_exists('can') && can('hks.settings'))) {
    http_response_code(403); die('Erişim yetkiniz yok.');
}

$repo   = new HksRepository(db());
$client = new HksClient($repo);
$s      = $repo->getSettings();

render_header('HKS Bağlantı Testi');
render_flash();
?>

<div class="hks-page" style="max-width:800px;margin:0 auto">

<div class="page-head" style="margin-top:16px">
    <div>
        <h1>🔌 Bağlantı Testi</h1>
        <p class="muted">HKS web servis bağlantısını doğrula</p>
    </div>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">← Panele Dön</a>
        <a href="ayarlar.php" class="btn btn-ghost">⚙️ Ayarlar</a>
    </div>
</div>

<?php if (!hks_check_soap()): ?>
<div style="background:#fef2f2;border:1px solid var(--danger);border-radius:8px;padding:16px;margin-bottom:16px">
    <strong>❌ PHP SOAP Extension Aktif Değil</strong><br>
    Sunucuda <code>extension=soap</code> etkinleştirilmesi gerekiyor.<br>
    phpinfo() çıktısında "soap" bölümü aranabilir.
</div>
<?php elseif (!$client->hasSettings()): ?>
<div style="background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;padding:16px;margin-bottom:16px">
    ⚙️ HKS ayarları yapılandırılmamış. <a href="ayarlar.php">Önce ayarları girin.</a>
</div>
<?php endif; ?>

<div class="card" style="padding:24px;margin-bottom:16px">
    <h3 style="margin-top:0">Test Kontrol Listesi</h3>
    <ul style="list-style:none;padding:0;line-height:2">
        <li><?= hks_check_soap() ? '✅' : '❌' ?> PHP SOAP Extension</li>
        <li><?= $client->hasSettings() ? '✅' : '⚠️' ?> HKS Ayarları Yapılandırılmış</li>
        <li><?= hks_has_secure_key() ? '✅' : '⚠️' ?> Şifreleme Anahtarı (HKS_CRED_KEY)</li>
        <li><?= hks_decrypt($s['password_enc'] ?? '') !== '' ? '✅' : '⚠️' ?> Şifre Çözülebilir</li>
        <li><?= ($s['environment'] ?? 'test') === 'live' ? '🔴 Canlı Ortam' : '🟡 Test Ortamı' ?></li>
    </ul>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
        <button type="button" id="btnTest" class="btn btn-primary btn-lg">
            🔌 Bağlantıyı Test Et
        </button>
        <button type="button" id="btnInspect" class="btn btn-ghost btn-lg">
            🔎 WSDL Metodlarını Gör
        </button>
    </div>
    <span id="test-spinner" style="display:none;margin-left:10px;color:var(--muted)">Test ediliyor...</span>
    <span id="inspect-spinner" style="display:none;margin-left:10px;color:var(--muted)">WSDL yükleniyor...</span>
</div>

<div id="test-result" style="display:none;padding:16px;border-radius:8px;margin-bottom:16px"></div>

<div id="inspect-result" style="display:none;margin-bottom:16px">
    <div class="card" style="padding:20px">
        <h3 style="margin-top:0">🔎 WSDL Metodları</h3>
        <div style="margin-bottom:12px">
            <button type="button" class="btn btn-ghost btn-sm inspect-tab active" data-svc="genel">GenelService</button>
            <button type="button" class="btn btn-ghost btn-sm inspect-tab" data-svc="bildirim">BildirimService</button>
        </div>
        <div id="inspect-methods" style="font-size:.85rem;font-family:monospace;line-height:1.7;white-space:pre-wrap;max-height:320px;overflow-y:auto;background:var(--bg);padding:12px;border-radius:6px;border:1px solid var(--border)"></div>
        <p id="inspect-wsdl-url" style="font-size:.78rem;color:var(--muted);margin:8px 0 0"></p>
    </div>
</div>

<?php if ($s && $s['last_test_at']): ?>
<div class="card" style="padding:16px">
    <strong>Son Test Sonucu:</strong><br>
    Tarih: <?= hks_h(date('d.m.Y H:i:s', strtotime($s['last_test_at']))) ?><br>
    Durum: <?= $s['last_test_ok'] ? '✅ Başarılı' : '❌ Başarısız' ?><br>
    <?php if ($s['last_test_message']): ?>
    Mesaj: <?= hks_h($s['last_test_message']) ?>
    <?php endif; ?>
</div>
<?php endif; ?>

</div>

<script>
var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

document.getElementById('btnTest').addEventListener('click', function() {
    var btn = this, spinner = document.getElementById('test-spinner'), result = document.getElementById('test-result');
    btn.disabled = true; spinner.style.display = 'inline'; result.style.display = 'none';
    fetch('ajax.php?action=test_connection', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf=' + encodeURIComponent(csrfToken)
    })
    .then(r => r.json())
    .then(data => {
        result.style.display = 'block';
        result.style.background = data.ok ? '#f0fdf4' : '#fef2f2';
        result.style.border = '1px solid ' + (data.ok ? 'var(--success)' : 'var(--danger)');
        result.style.color = data.ok ? '#065f46' : '#991b1b';
        result.innerHTML = (data.ok ? '✅ ' : '❌ ') + (data.message || '') +
            (data.duration_ms !== undefined ? ' <small style="opacity:.7">('+data.duration_ms+'ms)</small>' : '');
        if (data.ok) setTimeout(() => location.reload(), 2000);
    })
    .catch(() => {
        result.style.display='block'; result.style.background='#fef2f2';
        result.style.border='1px solid var(--danger)'; result.textContent='İstek gönderilemedi.';
    })
    .finally(() => { btn.disabled=false; spinner.style.display='none'; });
});

function loadInspect(service) {
    var spinner = document.getElementById('inspect-spinner');
    var panel   = document.getElementById('inspect-result');
    var methods = document.getElementById('inspect-methods');
    var urlEl   = document.getElementById('inspect-wsdl-url');
    spinner.style.display = 'inline';
    fetch('ajax.php?action=inspect_wsdl', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf=' + encodeURIComponent(csrfToken) + '&service=' + encodeURIComponent(service)
    })
    .then(r => r.json())
    .then(data => {
        panel.style.display = 'block';
        if (data.ok) {
            methods.textContent = data.methods && data.methods.length
                ? data.methods.join('\n')
                : '(Metod bulunamadı)';
            urlEl.textContent = 'WSDL: ' + (data.wsdl || '');
        } else {
            methods.textContent = '❌ ' + (data.message || 'Hata');
            urlEl.textContent = '';
        }
    })
    .catch(() => { methods.textContent = 'İstek gönderilemedi.'; })
    .finally(() => { spinner.style.display = 'none'; });
}

document.getElementById('btnInspect').addEventListener('click', function() {
    loadInspect('genel');
});

document.querySelectorAll('.inspect-tab').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.inspect-tab').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        loadInspect(this.dataset.svc);
    });
});
</script>

<?php render_footer(); ?>
