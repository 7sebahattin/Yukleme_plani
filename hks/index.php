<?php
// =========================================================
// hks/index.php — Hal Bildirimi Sayfası
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';   // require_login() bu dosyada

// Resmi Hal Bildirimi giriş adresi
$hal_bildirimi_url = 'https://hks.hal.gov.tr';

render_header('Hal Bildirimi');
render_flash();
?>

<style>
.hal-page {
    max-width: 560px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding-top: 8px;
}

.hal-card {
    background: var(--surface, #fff);
    border: 1px solid var(--border, #e0e0e0);
    border-radius: 12px;
    padding: 24px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    text-align: center;
}

.hal-card-icon {
    font-size: 3rem;
    line-height: 1;
}

.hal-card-title {
    font-size: 1.15rem;
    font-weight: 700;
    color: var(--text, #111);
}

.hal-card-desc {
    font-size: .88rem;
    color: var(--muted, #666);
    line-height: 1.55;
    max-width: 380px;
}

.hal-btn-open {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 13px 28px;
    background: var(--primary, #1a73e8);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: opacity .15s;
}

.hal-btn-open:hover {
    opacity: .88;
}

.hal-url-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: .8rem;
    color: var(--muted, #888);
    background: var(--bg, #f5f5f5);
    border: 1px solid var(--border, #e0e0e0);
    border-radius: 20px;
    padding: 4px 12px;
    word-break: break-all;
}

.hal-security-note {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 14px;
    background: var(--info-bg, #f0f7ff);
    border: 1px solid var(--info-border, #b8d9f8);
    border-radius: 8px;
    font-size: .82rem;
    color: var(--info-text, #1565c0);
    line-height: 1.5;
}

.hal-security-note-icon {
    flex-shrink: 0;
    font-size: 1rem;
    margin-top: 1px;
}

@media (max-width: 767px) {
    .hal-page {
        max-width: 100%;
    }

    .hal-card {
        padding: 20px 16px;
    }

    .hal-btn-open {
        width: 100%;
        justify-content: center;
        padding: 14px 20px;
    }
}
</style>

<div class="hal-page">

    <div class="page-head" style="margin-bottom:0">
        <div>
            <h1>🏛 Hal Bildirimi</h1>
            <p class="muted">Resmi Hal Kayıt Sistemi</p>
        </div>
    </div>

    <!-- Güvenlik notu -->
    <div class="hal-security-note">
        <span class="hal-security-note-icon">🔒</span>
        <span>Bu uygulama Hal Bildirimi kullanıcı adı/şifrenizi kaydetmez.
        Giriş işlemi resmi site üzerinde yapılır; bilgileriniz sistemimizden geçmez.</span>
    </div>

    <!-- Ana kart -->
    <div class="hal-card">
        <span class="hal-card-icon">🏛</span>
        <span class="hal-card-title">Hal Kayıt Sistemi</span>
        <p class="hal-card-desc">
            T.C. Ticaret Bakanlığı Hal Kayıt Sistemi'ne erişmek için
            aşağıdaki butona tıklayın. Resmi site yeni sekmede açılır.
        </p>

        <a href="<?= htmlspecialchars($hal_bildirimi_url, ENT_QUOTES, 'UTF-8') ?>"
           target="_blank"
           rel="noopener noreferrer"
           class="hal-btn-open">
            ↗ Hal Bildirimi'ni Aç
        </a>

        <span class="hal-url-chip">
            🌐 <?= htmlspecialchars($hal_bildirimi_url, ENT_QUOTES, 'UTF-8') ?>
        </span>
    </div>

</div>

<?php render_footer(); ?>
