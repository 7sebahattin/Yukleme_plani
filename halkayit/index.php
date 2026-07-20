<?php
// =============================================================================
// HAL KAYIT (HKS) PANELİ — GİRİŞ NOKTASI
// Ana panel çerçevesi (topbar + sol sidebar + mobil bottomnav) çizilir; HKS
// SPA'sı içerik alanında tam-ekran bir iframe (app.php) olarak gömülür.
// Böylece sayfa site ile bütünleşik görünür ve tüm gezinme korunur.
// =============================================================================
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$auth_user = require_login();
require_perm('records.write');   // Eski "Hal Bildirimi" ile aynı yetki kapısı

render_header('Hal Kayıt');
?>
<script>document.body.classList.add('hk-page');</script>

<iframe src="app.php" class="hk-frame" title="Hal Kayıt Paneli" loading="eager"></iframe>

<style>
/* Bu sayfaya özel: içerik alanını tam-ekran iframe'e çevir.
   Body flex-kolon olur; topbar (varsa) akışta üstte kalır, .container kalan
   yüksekliği doldurur. Sidebar/bottomnav (fixed) çerçeve olarak korunur. */
body.hk-page {
    display: flex;
    flex-direction: column;
    height: 100vh;
    height: 100dvh;
    overflow: hidden;
}
body.hk-page .container {
    flex: 1 1 auto;
    min-height: 0;
    padding: 0 !important;
    max-width: none !important;
    width: 100%;
}
body.hk-page .hk-frame {
    display: block;
    width: 100%;
    height: 100%;
    border: 0;
    background: #eef4f7;
}

/* Mobil: alt gezinme çubuğu (fixed) için yer bırak — iframe onun üstünde biter */
@media (max-width: 767px) {
    body.hk-page .container {
        padding-bottom: calc(58px + env(safe-area-inset-bottom, 0px)) !important;
    }
}

/* Masaüstü (≥900px): sidebar için sol boşluk global kuralla (margin-left) korunur;
   burada yalnızca tam yükseklik sağlanır. */
@media (min-width: 900px) {
    body.hk-page { height: 100vh; height: 100dvh; }
}
</style>
<?php
render_footer();
