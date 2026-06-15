<?php
// =========================================================
// hks/index.php — Hal Bildirimi embed sayfası
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';   // require_login() bu dosyada

// Resmi Hal Bildirimi giriş adresi — iframe doğrudan buraya bağlanır.
// (Proxy DEĞİL: HKS sunucusu proxy isteklerine "eski tarayıcı" sayfası
//  döndürüyor; tarayıcının doğrudan isteğinde giriş formu normal açılıyor.)
$hal_direct_url = 'https://hks.hal.gov.tr/Pages/Account/Login.aspx';

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
        height: calc(100vh - 175px);
    }

    .hal-embed-toolbar-url {
        display: none; /* Dar ekranda URL chip'i gizle */
    }

    .hal-embed-toolbar-actions .btn {
        font-size: .78rem;
        padding: 6px 8px;
    }
}

/* Geniş desktop: sidebar var, daha fazla yer */
@media (min-width: 900px) {
    .hal-embed-wrap {
        height: calc(100vh - 90px);
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

    <!-- iframe — resmi HKS giriş sayfasını doğrudan açar -->
    <div class="hal-embed-frame-box">
        <iframe
            id="hal-frame"
            class="hal-embed-frame"
            src="<?= htmlspecialchars($hal_direct_url, ENT_QUOTES, 'UTF-8') ?>"
            referrerpolicy="no-referrer-when-downgrade"
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
    document.getElementById('hal-btn-reload').addEventListener('click', function () {
        frame.src = frame.src;
    });
})();
</script>

<?php render_footer(); ?>
