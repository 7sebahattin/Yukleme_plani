<?php
// =========================================================
// hks/settings.php — HKS Bağlantı Ayarları
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/HksRepository.php';

$repo = new HksRepository(db());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $username    = trim($_POST['username'] ?? '');
    $password    = trim($_POST['password'] ?? '');
    $svc_pass    = trim($_POST['service_password'] ?? '');
    $sec_word    = trim($_POST['security_word'] ?? '');
    $environment = in_array($_POST['environment'] ?? '', ['test', 'live'], true)
                   ? $_POST['environment'] : 'test';
    $genel_wsdl  = trim($_POST['genel_wsdl_url'] ?? '');
    $bil_wsdl    = trim($_POST['bildirim_wsdl_url'] ?? '');

    // Mevcut şifreli değerleri al (boş bırakıldıysa değiştirme)
    $existing = $repo->getSettings();

    // Şifre alanları boş bırakıldıysa mevcut encrypted değeri koru
    if ($password !== '') {
        $password_enc = hks_encrypt($password);
    } else {
        $password_enc = $existing['password_enc'] ?? '';
    }

    if ($svc_pass !== '') {
        $svc_pass_enc = hks_encrypt($svc_pass);
    } else {
        $svc_pass_enc = $existing['service_password_enc'] ?? '';
    }

    if ($sec_word !== '') {
        $sec_word_enc = hks_encrypt($sec_word);
    } else {
        $sec_word_enc = $existing['security_word_enc'] ?? null;
    }

    $repo->saveSettings([
        'username'               => $username,
        'password_enc'           => $password_enc,
        'service_password_enc'   => $svc_pass_enc,
        'security_word_enc'      => $sec_word_enc,
        'environment'            => $environment,
        'genel_wsdl_url'         => $genel_wsdl,
        'bildirim_wsdl_url'      => $bil_wsdl,
    ]);

    set_flash('success', 'HKS ayarları kaydedildi.');
    header('Location: settings.php');
    exit;
}

$settings = $repo->getSettings();

render_header('HKS Ayarları');
render_flash();
?>

<div class="page-head">
    <div>
        <h1>🏛 HKS Ayarları</h1>
        <p class="muted">Hal Kayıt Sistemi bağlantı yapılandırması</p>
    </div>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">← Bildirimlere Dön</a>
    </div>
</div>

<!-- Bağlantı Testi Sonucu -->
<div id="test-result" style="display:none;margin-bottom:12px"></div>

<form method="post" action="settings.php" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

    <div class="form-section">
        <h2 class="form-section-title">Kimlik Bilgileri</h2>

        <div class="form-group">
            <label for="username">Kullanıcı Adı</label>
            <input type="text" id="username" name="username"
                   value="<?= hks_h($settings['username'] ?? '') ?>"
                   placeholder="HKS kullanıcı adı" autocomplete="off">
        </div>

        <div class="form-group">
            <label for="password">Şifre
                <?php if (!empty($settings['password_enc'])): ?>
                    <small class="muted">(kayıtlı — değiştirmek için yeni girin)</small>
                <?php endif; ?>
            </label>
            <input type="password" id="password" name="password"
                   placeholder="<?= !empty($settings['password_enc']) ? '••••••••' : 'HKS şifresi' ?>"
                   autocomplete="new-password">
        </div>

        <div class="form-group">
            <label for="service_password">Servis Şifresi
                <?php if (!empty($settings['service_password_enc'])): ?>
                    <small class="muted">(kayıtlı — değiştirmek için yeni girin)</small>
                <?php endif; ?>
            </label>
            <input type="password" id="service_password" name="service_password"
                   placeholder="<?= !empty($settings['service_password_enc']) ? '••••••••' : 'Servis şifresi' ?>"
                   autocomplete="new-password">
        </div>

        <div class="form-group">
            <label for="security_word">Güvenlik Kelimesi
                <?php if (!empty($settings['security_word_enc'])): ?>
                    <small class="muted">(kayıtlı — değiştirmek için yeni girin)</small>
                <?php endif; ?>
            </label>
            <input type="password" id="security_word" name="security_word"
                   placeholder="<?= !empty($settings['security_word_enc']) ? '••••••••' : 'Opsiyonel' ?>"
                   autocomplete="new-password">
        </div>

        <div class="form-group">
            <label for="environment">Ortam</label>
            <select id="environment" name="environment">
                <option value="test" <?= ($settings['environment'] ?? 'test') === 'test' ? 'selected' : '' ?>>
                    Test Ortamı
                </option>
                <option value="live" <?= ($settings['environment'] ?? '') === 'live' ? 'selected' : '' ?>>
                    Canlı Ortam
                </option>
            </select>
        </div>
    </div>

    <div class="form-section">
        <h2 class="form-section-title">WSDL Adresleri</h2>

        <div class="form-group">
            <label for="genel_wsdl_url">GenelService WSDL URL</label>
            <input type="url" id="genel_wsdl_url" name="genel_wsdl_url"
                   value="<?= hks_h($settings['genel_wsdl_url'] ?? '') ?>"
                   placeholder="https://...?wsdl">
        </div>

        <div class="form-group">
            <label for="bildirim_wsdl_url">BildirimService WSDL URL</label>
            <input type="url" id="bildirim_wsdl_url" name="bildirim_wsdl_url"
                   value="<?= hks_h($settings['bildirim_wsdl_url'] ?? '') ?>"
                   placeholder="https://...?wsdl">
        </div>

        <!-- Bağlantı Testi -->
        <div style="margin-top:12px">
            <button type="button" id="btnTest" class="btn btn-ghost btn-lg">
                Bağlantıyı Test Et
            </button>
            <span id="test-spinner" style="display:none;margin-left:10px;color:var(--muted);font-size:.88rem">
                Test ediliyor...
            </span>
        </div>
    </div>

    <div class="form-actions">
        <a href="index.php" class="btn btn-ghost btn-lg">İptal</a>
        <button type="submit" class="btn btn-primary btn-lg">Ayarları Kaydet</button>
    </div>
</form>

<script>
document.getElementById('btnTest').addEventListener('click', function() {
    var btn     = this;
    var spinner = document.getElementById('test-spinner');
    var result  = document.getElementById('test-result');

    btn.disabled    = true;
    spinner.style.display = 'inline';
    result.style.display  = 'none';

    var csrf = document.querySelector('meta[name="csrf-token"]');
    var csrfVal = csrf ? csrf.getAttribute('content') : '';

    fetch('api_test.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf=' + encodeURIComponent(csrfVal)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        result.style.display = 'block';
        result.className     = data.ok ? 'hks-success-box' : 'hks-error-box';
        result.textContent   = data.message || (data.ok ? 'Bağlantı başarılı.' : 'Bağlantı başarısız.');
    })
    .catch(function() {
        result.style.display = 'block';
        result.className     = 'hks-error-box';
        result.textContent   = 'İstek gönderilemedi.';
    })
    .finally(function() {
        btn.disabled         = false;
        spinner.style.display = 'none';
    });
});
</script>

<?php render_footer(); ?>
