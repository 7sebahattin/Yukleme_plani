<?php
// HKS Ayarlar
declare(strict_types=1);
require_once __DIR__ . '/includes/hks_bootstrap.php';
$auth_user = require_login();
if (!is_admin() && !(function_exists('can') && can('hks.settings'))) {
    http_response_code(403); die('Bu sayfaya erişim yetkiniz yok (hks.settings).');
}

$repo = new HksRepository(db());

// HKS_CRED_KEY anahtar oluşturma
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_key') {
    csrf_check($_POST['csrf'] ?? null);
    $local_php = __DIR__ . '/../../config/local.php';
    $new_key   = bin2hex(random_bytes(32)); // 64 hex karakter = 256 bit entropy

    if (file_exists($local_php)) {
        $existing = file_get_contents($local_php) ?: '';
        if (strpos($existing, 'HKS_CRED_KEY') !== false) {
            set_flash('info', 'HKS_CRED_KEY zaten config/local.php içinde tanımlı. Sayfa yenileniyor...');
            header('Location: ayarlar.php'); exit;
        }
        $write_content = rtrim($existing) . "\ndefine('HKS_CRED_KEY', '" . $new_key . "');\n";
    } else {
        $write_content = "<?php\ndefine('HKS_CRED_KEY', '" . $new_key . "');\n";
    }

    if (@file_put_contents($local_php, $write_content) !== false) {
        audit_log_event('hks_cred_key_generated', 'hks_settings', null, null, []);
        set_flash('success', 'HKS_CRED_KEY başarıyla oluşturuldu ve config/local.php\'ye kaydedildi. Artık şifre girebilirsiniz.');
    } else {
        set_flash('error', "config/local.php yazılamadı. Dosyayı el ile oluşturun — içeriği: define('HKS_CRED_KEY', '{$new_key}');");
    }
    header('Location: ayarlar.php'); exit;
}

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
$has_secure_key  = hks_can_save_passwords(); // sadece gösterge — artık şifre girişini bloke etmiyor
$suggested_key   = !$has_secure_key ? bin2hex(random_bytes(32)) : '';

render_header('HKS Ayarları');
render_flash();
?>
<div class="hks-page">
<?php
$hks_active_tab = 'ayarlar.php';
include __DIR__ . '/views/_tabs.php';
?>

<div class="page-head" style="margin-top:16px">
    <div>
        <h1>⚙️ HKS Giriş ve Web Servis Bilgileri</h1>
        <p class="muted">Kullanıcı adı ve şifreler şifrelenmiş olarak saklanır. Ekranda açık görünmez.</p>
    </div>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">← Panele Dön</a>
    </div>
</div>

<?php if ($has_secure_key): ?>
<div class="hks-info-box">🔐 Şifreler AES-256-CBC ile şifrelenmiş olarak saklanmaktadır.</div>
<?php else: ?>
<details class="hks-advanced" style="margin-bottom:12px">
    <summary style="font-size:.85rem">🔑 Güvenlik notu — opsiyonel güçlendirme</summary>
    <div class="hks-advanced-body" style="font-size:.85rem;color:#92400e">
        Şifreler şifrelenmiş kaydedilmektedir ancak özel bir anahtar tanımlanmamış.
        Daha güçlü şifreleme için <code>config/local.php</code> dosyasına ekleyin:<br>
        <code style="display:block;background:#fef3c7;border-radius:4px;padding:6px 10px;margin-top:6px;word-break:break-all">define('HKS_CRED_KEY', '<?= hks_h($suggested_key) ?>');</code>
        <div style="margin-top:8px">
            <form method="post" action="ayarlar.php" style="display:inline">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="generate_key">
                <button type="submit" class="btn btn-sm" style="background:#d97706;color:#fff;border-color:#d97706">
                    Otomatik Yaz (sunucu izni gerekir)
                </button>
            </form>
        </div>
    </div>
</details>
<?php endif; ?>

<form method="post" action="ayarlar.php" autocomplete="off" class="hks-form">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

    <!-- Bölüm 1: Giriş Bilgileri -->
    <div class="form-section">
        <div class="form-section-title">Giriş Bilgileri</div>

        <div class="form-group">
            <label for="username">HKS Kullanıcı Adı</label>
            <input type="text" id="username" name="username"
                   value="<?= hks_h($s['username'] ?? '') ?>"
                   placeholder="HKS kullanıcı adı" autocomplete="off">
        </div>

        <div class="form-group">
            <label for="password">
                HKS Şifresi
                <?php if (!empty($s['password_enc'])): ?>
                    <span class="hks-secret-badge hks-badge-ok">✓ Kayıtlı</span>
                <?php else: ?>
                    <span class="hks-secret-badge hks-badge-missing">○ Eksik</span>
                <?php endif; ?>
            </label>
            <input type="password" id="password" name="password"
                   placeholder="<?= !empty($s['password_enc']) ? '(kayıtlı — değiştirmek için yeni şifre girin)' : 'HKS kullanıcı şifresi' ?>"
                   autocomplete="new-password">
        </div>

        <div class="form-group">
            <label for="service_password">
                Web Servis Şifresi
                <?php if (!empty($s['service_password_enc'])): ?>
                    <span class="hks-secret-badge hks-badge-ok">✓ Kayıtlı</span>
                <?php else: ?>
                    <span class="hks-secret-badge hks-badge-missing">○ Eksik</span>
                <?php endif; ?>
            </label>
            <input type="password" id="service_password" name="service_password"
                   placeholder="<?= !empty($s['service_password_enc']) ? '(kayıtlı — değiştirmek için yeni şifre girin)' : 'Web servis şifresi (ServicePassword)' ?>"
                   autocomplete="new-password">
        </div>
    </div>

    <!-- Bölüm 2: Ortam -->
    <div class="form-section">
        <div class="form-section-title">Ortam</div>
        <div class="form-group">
            <label for="environment">HKS Servis Ortamı</label>
            <select id="environment" name="environment">
                <option value="test" <?= ($s['environment'] ?? 'test') === 'test' ? 'selected' : '' ?>>🟡 Test Ortamı</option>
                <option value="live" <?= ($s['environment'] ?? '') === 'live' ? 'selected' : '' ?>>🔴 Canlı Ortam</option>
            </select>
        </div>
    </div>

    <!-- Gelişmiş Ayarlar (accordion) -->
    <details class="hks-advanced">
        <summary>Gelişmiş Ayarlar <small class="muted" style="font-weight:400;margin-left:6px">— firma, depo, WSDL, timeout</small></summary>
        <div class="hks-advanced-body hks-form">

            <div class="form-group">
                <label for="security_word">
                    Güvenlik Kelimesi <small class="muted">(opsiyonel)</small>
                    <?php if (!empty($s['security_word_enc'])): ?>
                        <span class="hks-secret-badge hks-badge-ok">✓ Kayıtlı</span>
                    <?php endif; ?>
                </label>
                <input type="password" id="security_word" name="security_word"
                       placeholder="<?= !empty($s['security_word_enc']) ? '(kayıtlı — değiştirmek için yeni değer girin)' : 'Opsiyonel' ?>"
                       autocomplete="new-password">
            </div>

            <div class="form-group">
                <label for="sender_name">Bildirimci Adı / Firma Notu</label>
                <input type="text" id="sender_name" name="sender_name"
                       value="<?= hks_h($s['sender_name'] ?? '') ?>" placeholder="Firma adı">
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px">
                <div class="form-group">
                    <label for="default_depo">Varsayılan Depo</label>
                    <input type="text" id="default_depo" name="default_depo"
                           value="<?= hks_h($s['default_depo'] ?? '') ?>" placeholder="Depo kodu">
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

            <div class="form-group">
                <label for="genel_wsdl_url">GenelService WSDL <small class="muted">(boş = otomatik)</small></label>
                <input type="url" id="genel_wsdl_url" name="genel_wsdl_url"
                       value="<?= hks_h($s['genel_wsdl_url'] ?? '') ?>"
                       placeholder="Boş = ortama göre otomatik">
            </div>

            <div class="form-group">
                <label for="bildirim_wsdl_url">BildirimService WSDL <small class="muted">(boş = otomatik)</small></label>
                <input type="url" id="bildirim_wsdl_url" name="bildirim_wsdl_url"
                       value="<?= hks_h($s['bildirim_wsdl_url'] ?? '') ?>"
                       placeholder="Boş = ortama göre otomatik">
            </div>

            <div class="form-group">
                <label for="timeout_seconds">Servis Timeout <small class="muted">(saniye)</small></label>
                <input type="number" id="timeout_seconds" name="timeout_seconds" min="5" max="120"
                       value="<?= (int)($s['timeout_seconds'] ?? 30) ?>" style="max-width:100px">
            </div>

            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;font-weight:normal">
                    <input type="checkbox" name="live_send_enabled" value="1"
                           <?= (int)($s['live_send_enabled'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <span>Canlı HKS gönderimini etkinleştir <small class="muted">(servis eşlemesi tamamlandıktan sonra açın)</small></span>
                </label>
            </div>
        </div>
    </details>

    <!-- Form eylemleri + bağlantı testi -->
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <button type="submit" class="btn btn-primary btn-lg">Kaydet</button>
        <a href="index.php" class="btn btn-ghost btn-lg">İptal</a>
        <button type="button" id="btnTest" class="btn btn-ghost" style="margin-left:auto">🔌 Bağlantı Testi</button>
    </div>
</form>

<div id="test-result" style="display:none;margin-top:12px"></div>

</div><!-- /.hks-page -->

<script>
document.getElementById('btnTest').addEventListener('click', function() {
    var btn = this, result = document.getElementById('test-result');
    btn.disabled = true; btn.textContent = '⏳ Test ediliyor...';
    result.style.display = 'none';
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
    .catch(function() { result.style.display='block'; result.className='hks-error-box'; result.textContent='İstek gönderilemedi.'; })
    .finally(function() { btn.disabled=false; btn.textContent='🔌 Bağlantı Testi'; });
});
</script>

<?php render_footer(); ?>
