<?php
// =========================================================
// hks/index.php — Hal Bildirimi (Embed / Browser Görünümü)
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
/* ── Hal Embed — sayfa özel stiller ── */
.hal-embed-page {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.hal-embed-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    padding: 10px 12px;
    background: var(--surface, #fff);
    border: 1px solid var(--border, #e0e0e0);
    border-radius: 10px;
}

.hal-embed-toolbar-title {
    font-weight: 600;
    font-size: .95rem;
    flex: 1 1 120px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.hal-embed-toolbar-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.hal-embed-info {
    font-size: .82rem;
    color: var(--muted, #888);
    padding: 6px 2px 0;
}

.hal-embed-wrapper {
    position: relative;
    border: 1px solid var(--border, #e0e0e0);
    border-radius: 10px;
    overflow: hidden;
    background: #f5f5f5;
    min-height: 75vh;
}

.hal-embed-frame {
    display: block;
    width: 100%;
    min-height: 75vh;
    border: none;
    background: #fff;
}

.hal-embed-warning {
    display: none;
    position: absolute;
    inset: 0;
    background: rgba(255,255,255,.96);
    z-index: 10;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 14px;
    padding: 24px;
    text-align: center;
}

.hal-embed-warning.visible {
    display: flex;
}

.hal-embed-warning-icon {
    font-size: 2.4rem;
    line-height: 1;
}

.hal-embed-warning-title {
    font-weight: 600;
    font-size: 1rem;
}

.hal-embed-warning-text {
    font-size: .85rem;
    color: var(--muted, #888);
    max-width: 340px;
}

.hal-embed-security-note {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 10px 12px;
    background: var(--info-bg, #f0f7ff);
    border: 1px solid var(--info-border, #b8d9f8);
    border-radius: 8px;
    font-size: .82rem;
    color: var(--info-text, #1565c0);
}

.hal-embed-security-note-icon {
    flex-shrink: 0;
    font-size: 1rem;
    margin-top: 1px;
}

@media (max-width: 767px) {
    .hal-embed-frame,
    .hal-embed-wrapper {
        min-height: 70vh;
    }

    .hal-embed-toolbar-title {
        width: 100%;
        flex: 1 1 100%;
    }

    .hal-embed-toolbar-actions {
        width: 100%;
    }

    .hal-embed-toolbar-actions .btn {
        flex: 1 1 auto;
        text-align: center;
        font-size: .82rem;
        padding: 7px 8px;
    }
}
</style>

<div class="hal-embed-page">

    <!-- Üst başlık -->
    <div class="page-head" style="margin-bottom:0">
        <div>
            <h1>🏛 Hal Bildirimi</h1>
            <p class="muted">Resmi Hal Bildirimi sistemi uygulama içinde açılır.</p>
        </div>
    </div>

    <!-- Güvenlik notu -->
    <div class="hal-embed-security-note">
        <span class="hal-embed-security-note-icon">🔒</span>
        <span>Bu uygulama Hal Bildirimi kullanıcı adı/şifrenizi kaydetmez. Giriş işlemi resmi site üzerinde yapılır; bilgileriniz sistemimizden geçmez.</span>
    </div>

    <!-- Kontrol barı -->
    <div class="hal-embed-toolbar">
        <span class="hal-embed-toolbar-title">🌐 <?= htmlspecialchars($hal_bildirimi_url, ENT_QUOTES, 'UTF-8') ?></span>
        <div class="hal-embed-toolbar-actions">
            <button class="btn btn-ghost btn-sm" id="hal-btn-reload" title="Yenile">
                🔄 Yenile
            </button>
            <button class="btn btn-ghost btn-sm" id="hal-btn-newtab" title="Yeni sekmede aç">
                ↗ Yeni Sekmede Aç
            </button>
            <a href="../index.php" class="btn btn-ghost btn-sm" title="Ana sayfaya dön">
                🏠 Ana Sayfa
            </a>
        </div>
    </div>

    <!-- Iframe alanı -->
    <div class="hal-embed-wrapper">
        <iframe
            id="hal-frame"
            class="hal-embed-frame"
            src="<?= htmlspecialchars($hal_bildirimi_url, ENT_QUOTES, 'UTF-8') ?>"
            referrerpolicy="no-referrer-when-downgrade"
            title="Hal Bildirimi Sistemi"
            allowfullscreen
        ></iframe>

        <!-- Fallback: iframe engellenmişse gösterilir -->
        <div class="hal-embed-warning" id="hal-fallback">
            <span class="hal-embed-warning-icon">⚠️</span>
            <span class="hal-embed-warning-title">Sayfa Açılamadı</span>
            <p class="hal-embed-warning-text">
                Resmi Hal Bildirimi sitesi güvenlik politikası nedeniyle uygulama içinde açılmıyor olabilir.
                Aşağıdaki butona tıklayarak yeni sekmede açabilirsiniz.
            </p>
            <button class="btn btn-primary" id="hal-fallback-newtab">
                ↗ Yeni Sekmede Aç
            </button>
            <button class="btn btn-ghost btn-sm" id="hal-fallback-dismiss" style="margin-top:-4px">
                Kapat
            </button>
        </div>
    </div>

    <!-- Alt uyarı notu -->
    <p class="hal-embed-info">
        💡 Resmi site güvenlik nedeniyle uygulama içinde açılmazsa <strong>Yeni Sekmede Aç</strong> butonunu kullanın.
    </p>

</div>

<script>
(function () {
    // Resmi URL — PHP'den aktarılır
    var HAL_URL = <?= json_encode($hal_bildirimi_url) ?>;

    var frame    = document.getElementById('hal-frame');
    var fallback = document.getElementById('hal-fallback');
    var timer    = null;

    // Yenile butonu
    document.getElementById('hal-btn-reload').addEventListener('click', function () {
        // Fallback gizliyse frame'i yenile
        fallback.classList.remove('visible');
        clearTimeout(timer);
        frame.src = HAL_URL;
        startFallbackTimer();
    });

    // Yeni Sekmede Aç butonu (toolbar)
    document.getElementById('hal-btn-newtab').addEventListener('click', function () {
        window.open(HAL_URL, '_blank', 'noopener,noreferrer');
    });

    // Fallback — Yeni Sekmede Aç
    document.getElementById('hal-fallback-newtab').addEventListener('click', function () {
        window.open(HAL_URL, '_blank', 'noopener,noreferrer');
    });

    // Fallback — Kapat
    document.getElementById('hal-fallback-dismiss').addEventListener('click', function () {
        fallback.classList.remove('visible');
    });

    // iframe yüklendiğinde timer'ı iptal et
    // Not: cross-origin olduğu için içeriği okuyamayız — bu normaldir.
    frame.addEventListener('load', function () {
        clearTimeout(timer);
    });

    // 8 saniye içinde load event gelmezse fallback göster
    function startFallbackTimer() {
        timer = setTimeout(function () {
            fallback.classList.add('visible');
        }, 8000);
    }

    startFallbackTimer();
})();
</script>

<?php render_footer(); ?>
