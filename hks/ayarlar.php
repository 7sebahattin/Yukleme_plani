<?php
// HKS Ayarlar
declare(strict_types=1);
require_once __DIR__ . '/includes/hks_bootstrap.php';
$auth_user = require_login();
if (!is_admin() && !(function_exists('can') && can('hks.settings'))) {
    http_response_code(403); die('Bu sayfaya erişim yetkiniz yok (hks.settings).');
}

$repo = new HksRepository(db());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $existing    = $repo->getSettings();
    $environment = in_array($_POST['environment'] ?? '', ['test','live'], true)
                   ? $_POST['environment'] : 'test';
    $username    = trim($_POST['username'] ?? '');
    $password    = trim($_POST['password'] ?? '');
    $svc_pass    = trim($_POST['service_password'] ?? '');
    $sec_word    = trim($_POST['security_word'] ?? '');
    $sender      = trim($_POST['sender_name'] ?? '');
    $depo        = trim($_POST['default_depo'] ?? '');
    $il          = trim($_POST['default_il'] ?? '');
    $ilce        = trim($_POST['default_ilce'] ?? '');
    $timeout     = max(5, min(120, (int)($_POST['timeout_seconds'] ?? 30)));
    $live_send   = isset($_POST['live_send_enabled']) ? 1 : 0;
    $genel_wsdl  = trim($_POST['genel_wsdl_url'] ?? '');
    $bil_wsdl    = trim($_POST['bildirim_wsdl_url'] ?? '');

    // Şifre alanı doldurulduysa HKS_CRED_KEY zorunlu
    $has_any_password = $password !== '' || $svc_pass !== '' || $sec_word !== '';
    if ($has_any_password && !hks_can_save_passwords()) {
        set_flash('error', 'HKS_CRED_KEY tanımlanmadan HKS şifreleri kaydedilemez. config/local.php dosyasına HKS_CRED_KEY tanımlayın.');
        header('Location: ayarlar.php'); exit;
    }

    // Boş bırakılan şifreler mevcut değeri korur
    $password_enc = $password !== '' ? hks_encrypt($password) : ($existing['password_enc'] ?? '');
    $svc_enc      = $svc_pass !== ''  ? hks_encrypt($svc_pass) : ($existing['service_password_enc'] ?? '');
    $sec_enc      = $sec_word !== ''  ? hks_encrypt($sec_word) : ($existing['security_word_enc'] ?? null);

    $repo->saveSettings([
        'environment'           => $environment,
        'username'              => $username,
        'password_enc'          => $password_enc,
        'service_password_enc'  => $svc_enc,
        'security_word_enc'     => $sec_enc,
        'sender_name'           => $sender,
        'default_depo'          => $depo,
        'default_il'            => $il,
        'default_ilce'          => $ilce,
        'timeout_seconds'       => $timeout,
        'live_send_enabled'     => $live_send,
        'genel_wsdl_url'        => $genel_wsdl,
        'bildirim_wsdl_url'     => $bil_wsdl,
    ]);

    audit_log_event('hks_settings_updated', 'hks_settings', null, null, [
        'environment' => $environment, 'username' => $username,
        'live_send_enabled' => $live_send,
    ]);
    set_flash('success', 'HKS ayarları kaydedildi.');
    header('Location: ayarlar.php'); exit;
}

$s = $repo->getSettings();
$has_secure_key = hks_can_save_passwords();

render_header('HKS Ayarları');
render_flash();
?>

<style>
.hks-page { max-width: 900px; margin: 0 auto; }
.hks-tabs { display: flex; flex-wrap: wrap; gap: 4px; padding: 12px 0 0; margin-bottom: 16px; border-bottom: 2px solid var(--border); }
.hks-tab { display: flex; align-items: center; gap: 5px; padding: 8px 14px; border-radius: 8px 8px 0 0; text-decoration: none; color: var(--muted); font-size: .88rem; border: 1px solid transparent; border-bottom: none; }
.hks-tab:hover { background: var(--bg); color: var(--text); }
.hks-tab.active { background: var(--card); color: var(--primary); font-weight: 600; border-color: var(--border); margin-bottom: -2px; }
.hks-tab-label { display: none; }
@media (min-width: 600px) { .hks-tab-label { display: inline; } }
.hks-warning-box { background: #fffbeb; border: 1px solid #f59e0b; border-radius: 8px; padding: 12px 16px; font-size: .88rem; color: #92400e; margin-bottom: 12px; }
.hks-info-box    { background: #eff6ff; border: 1px solid #93c5fd; border-radius: 8px; padding: 12px 16px; font-size: .88rem; color: #1e40af; margin-bottom: 12px; }
.hks-success-box { background: #f0fdf4; border: 1px solid var(--success); border-radius: 8px; padding: 12px 16px; font-size: .88rem; color: #065f46; margin-top: 12px; }
.hks-error-box   { background: #fef2f2; border: 1px solid var(--danger); border-radius: 8px; padding: 12px 16px; font-size: .88rem; color: #991b1b; margin-top: 12px; }
.hks-secret-status { display: inline-flex; align-items: center; gap: 5px; font-size: .82rem; padding: 2px 8px; border-radius: 20px; font-weight: 600; background: #d1fae5; color: #065f46; }
.hks-secret-status.missing { background: #fef3c7; color: #92400e; }
.hks-form .form-group { margin-bottom: 14px; }
.hks-form .form-group label { display: block; font-weight: 600; font-size: .85rem; margin-bottom: 4px; }
.hks-form .form-group input, .hks-form .form-group select { width: 100%; box-sizing: border-box; padding: 9px 11px; border: 1px solid var(--border); border-radius: 7px; font-size: .95rem; background: #fff; }
@media (max-width: 767px) { .hks-form .form-group input, .hks-form .form-group select { font-size: 16px; } }
.form-section { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 18px 20px; margin-bottom: 16px; }
.form-section-title { font-size: .95rem; font-weight: 700; margin-bottom: 14px; color: var(--text); border-bottom: 1px solid var(--border); padding-bottom: 8px; }
</style>

<div class="hks-page">
<?php
$hks_active_tab = 'ayarlar.php';
include __DIR__ . '/views/_tabs.php';
?>

<div class="page-head" style="margin-top:16px">
    <div>
        <h1>⚙️ HKS Web Servis Ayarları</h1>
        <p class="muted">HKS kullanıcı adı, kullanıcı şifresi ve web servis şifresi bu alanda güvenli şekilde saklanır. Şifreler ekranda açık gösterilmez.</p>
    </div>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">← Panele Dön</a>
    </div>
</div>

<?php if (!$has_secure_key): ?>
<div class="hks-warning-box">
    🔑 <strong>HKS_CRED_KEY tanımlanmamış — HKS şifreleri kaydedilemez.</strong><br>
    Şifre (HKS Şifresi, Servis Şifresi, Güvenlik Kelimesi) kaydetmek için
    <code>config/local.php</code> dosyasında şunu tanımlayın:<br>
    <code>&lt;?php define('HKS_CRED_KEY', 'en_az_32_karakter_rastgele_anahtar');</code><br>
    Bu dosyayı <code>.gitignore</code>'a ekleyin.
    Diğer ayarlar (kullanıcı adı, ortam, WSDL URL, firma bilgileri) kaydedilebilir.
</div>
<?php else: ?>
<div class="hks-info-box">🔐 Şifreler AES-256-CBC ile şifrelenmiş olarak saklanmaktadır.</div>
<?php endif; ?>

<form method="post" action="ayarlar.php" autocomplete="off" class="hks-form">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

    <div class="form-section">
        <div class="form-section-title">Ortam ve Kimlik Bilgileri</div>

        <div class="form-group">
            <label for="environment">Ortam</label>
            <select id="environment" name="environment">
                <option value="test" <?= ($s['environment'] ?? 'test') === 'test' ? 'selected' : '' ?>>🟡 Test Ortamı</option>
                <option value="live" <?= ($s['environment'] ?? '') === 'live' ? 'selected' : '' ?>>🔴 Canlı Ortam</option>
            </select>
        </div>

        <div class="form-group">
            <label for="username">HKS Kullanıcı Adı</label>
            <input type="text" id="username" name="username"
                   value="<?= hks_h($s['username'] ?? '') ?>"
                   placeholder="HKS kullanıcı adı" autocomplete="off">
        </div>

        <div class="form-group">
            <label for="password">HKS Şifresi
                <?php if (!empty($s['password_enc'])): ?>
                    <small class="muted">(kayıtlı — değiştirmek için yeni şifre girin)</small>
                <?php endif; ?>
            </label>
            <input type="password" id="password" name="password"
                   placeholder="<?= !empty($s['password_enc']) ? '••••••••  (kayıtlı)' : 'HKS şifresi' ?>"
                   autocomplete="new-password"
                   <?= !$has_secure_key ? 'disabled' : '' ?>>
        </div>

        <div class="form-group">
            <label for="service_password">Web Servis Şifresi (ServicePassword)
                <?php if (!empty($s['service_password_enc'])): ?>
                    <small class="muted">(kayıtlı)</small>
                <?php endif; ?>
            </label>
            <input type="password" id="service_password" name="service_password"
                   placeholder="<?= !empty($s['service_password_enc']) ? '••••••••  (kayıtlı)' : 'Servis şifresi' ?>"
                   autocomplete="new-password"
                   <?= !$has_secure_key ? 'disabled' : '' ?>>
        </div>

        <div class="form-group">
            <label for="security_word">Güvenlik Kelimesi (opsiyonel)
                <?php if (!empty($s['security_word_enc'])): ?>
                    <small class="muted">(kayıtlı)</small>
                <?php endif; ?>
            </label>
            <input type="password" id="security_word" name="security_word"
                   placeholder="<?= !empty($s['security_word_enc']) ? '••••••••  (kayıtlı)' : 'Opsiyonel' ?>"
                   autocomplete="new-password"
                   <?= !$has_secure_key ? 'disabled' : '' ?>>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Firma ve Varsayılan Değerler</div>

        <div class="form-group">
            <label for="sender_name">Bildirimci Adı / Firma Notu</label>
            <input type="text" id="sender_name" name="sender_name"
                   value="<?= hks_h($s['sender_name'] ?? '') ?>" placeholder="Firma adı">
        </div>

        <div class="form-group">
            <label for="default_depo">Varsayılan Depo</label>
            <input type="text" id="default_depo" name="default_depo"
                   value="<?= hks_h($s['default_depo'] ?? '') ?>" placeholder="Depo kodu veya adı">
        </div>

        <div class="form-group">
            <label for="default_il">Varsayılan İl</label>
            <input type="text" id="default_il" name="default_il"
                   value="<?= hks_h($s['default_il'] ?? '') ?>" placeholder="İl kodu">
        </div>

        <div class="form-group">
            <label for="default_ilce">Varsayılan İlçe</label>
            <input type="text" id="default_ilce" name="default_ilce"
                   value="<?= hks_h($s['default_ilce'] ?? '') ?>" placeholder="İlçe kodu">
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">WSDL ve Servis Ayarları</div>

        <div class="form-group">
            <label for="genel_wsdl_url">GenelService WSDL URL
                <small class="muted">(boş bırakılırsa otomatik)</small>
            </label>
            <input type="url" id="genel_wsdl_url" name="genel_wsdl_url"
                   value="<?= hks_h($s['genel_wsdl_url'] ?? '') ?>"
                   placeholder="Boş = ortama göre otomatik">
        </div>

        <div class="form-group">
            <label for="bildirim_wsdl_url">BildirimService WSDL URL
                <small class="muted">(boş bırakılırsa otomatik)</small>
            </label>
            <input type="url" id="bildirim_wsdl_url" name="bildirim_wsdl_url"
                   value="<?= hks_h($s['bildirim_wsdl_url'] ?? '') ?>"
                   placeholder="Boş = ortama göre otomatik">
        </div>

        <div class="form-group">
            <label for="timeout_seconds">Servis Timeout (saniye)</label>
            <input type="number" id="timeout_seconds" name="timeout_seconds" min="5" max="120"
                   value="<?= (int)($s['timeout_seconds'] ?? 30) ?>">
        </div>

        <div class="form-group">
            <label>
                <input type="checkbox" name="live_send_enabled" value="1"
                       <?= (int)($s['live_send_enabled'] ?? 0) === 1 ? 'checked' : '' ?>>
                &nbsp;Canlı HKS gönderimini etkinleştir
                <small class="muted">(sadece servis eşlemesi tamamlandıktan sonra açın)</small>
            </label>
        </div>
    </div>

    <div class="form-actions">
        <a href="index.php" class="btn btn-ghost btn-lg">İptal</a>
        <button type="submit" class="btn btn-primary btn-lg">
            Ayarları Kaydet
        </button>
    </div>
</form>

<div id="test-result" style="display:none;margin-top:16px"></div>
<div style="margin-top:16px">
    <button type="button" id="btnTest" class="btn btn-ghost btn-lg">🔌 Bağlantıyı Test Et</button>
    <span id="test-spinner" style="display:none;margin-left:10px;color:var(--muted);font-size:.88rem">Test ediliyor...</span>
    <p style="margin-top:8px;font-size:.82rem;color:var(--muted)">
        Test: SOAP extension, WSDL erişimi kontrol edilir. Ayarların kaydedilmesi gerekmez.
    </p>
</div>

</div><!-- /.hks-page -->

<script>
document.getElementById('btnTest').addEventListener('click', function() {
    var btn = this, spinner = document.getElementById('test-spinner'), result = document.getElementById('test-result');
    btn.disabled = true; spinner.style.display = 'inline'; result.style.display = 'none';
    fetch('ajax.php?action=test_connection', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]').content)
    })
    .then(r => r.json())
    .then(data => {
        result.style.display = 'block';
        result.className = data.ok ? 'hks-success-box' : 'hks-error-box';
        result.innerHTML = (data.ok ? '✅ ' : '❌ ') + (data.message || '') + (data.duration_ms ? ' <small>('+data.duration_ms+'ms)</small>' : '');
    })
    .catch(() => { result.style.display='block'; result.className='hks-error-box'; result.textContent='İstek gönderilemedi.'; })
    .finally(() => { btn.disabled=false; spinner.style.display='none'; });
});
</script>

<?php render_footer(); ?>
