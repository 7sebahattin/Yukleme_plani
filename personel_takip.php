<?php
// =========================================================
// personel_takip.php — Personel Takip Merkezi (Faz 7, Sprint Navigasyon-01)
//
// Aktif Günlük İşçi + Hakediş/Cari + Raporlama girdilerini TEK bir merkezi
// modül LANDİNG sayfası altında toplayan bir GEZİNME KATMANI. Kullanıcının
// açık talimatı: "This is a navigation consolidation layer." — hiçbir
// mevcut sayfa SİLİNMEDİ, hiçbir yetki kontrolü GEVŞETİLMEDİ. Bu sayfa
// yalnız KARTLARI gizler/gösterir (UX); her hedef sayfa KENDİ
// require_pdks_gunluk()/require_pdks_hakedis()/require_pdks_cari()/
// require_pdks_rapor()/require_perm() kapısını AYNEN korur — bu sayfa
// bypass için bir yol DEĞİLDİR.
//
// ⚠ Kart görünürlüğü mevcut can()/is_admin() sistemini DOĞRUDAN kullanır
// (yeni bir "modül izni" İCAT EDİLMEDİ) — sidebar'ın (config/helpers.php)
// AYNI deseni: her kart kendi attendance.* iznine bakar.
//
// ⚠ Ekstre kartı (görev talimatı): cavus_ekstre.php DOĞRUDAN bir
// foreman_id İSTER — buraya kırık bir genel link KONULMADI, bunun yerine
// çavuş seçiminin yapıldığı cavus_cari.php'ye yönlendirilir (oradan her
// çavuşun "Ekstre" bağlantısı zaten var).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
role_pdks_require_request();

$_fn = function_exists('can');
$p_adm = function_exists('is_admin') && is_admin();

// ── Bölüm görünürlüğü — "en az BİR aktif alt-fonksiyona erişim" ──────────
// Kalıcı PDKS izinleri bu landing için yeterli değildir; ilgili eski sayfalar
// kendi izin kapılarıyla yerinde kalır.
$p_gunluk   = $p_adm || ($_fn && (role_pdks_can('foremen') || role_pdks_can('worker_cards') || role_pdks_can('daily_scan') || role_pdks_can('daily_reports')));
$p_hakcari  = $p_adm || ($_fn && (role_pdks_can('foreman_rates') || role_pdks_can('entitlements') || role_pdks_can('foreman_accounts') || role_pdks_can('foreman_payments')));
$p_rapor    = $p_adm || ($_fn && role_pdks_can('management_reports'));

if (!role_pdks_can('hub')) {
    forbidden('Bu sayfaya erişim yetkiniz yok. (Günlük İşçi, Hakediş/Cari veya Yönetim Raporları modüllerinden en az birine yetkiniz olmalı.)');
}

// ⚠ v275: kapatılmamış (önceki günden açık) mesai rozeti — Giriş/Çıkış
// kartında. Yalnız tarama yetkisi olan görür (kapatma o ekrandadır). Hata
// sayfayı ASLA bozmaz: modül/şema yoksa rozet çizilmez.
$eskiAcikSayi = 0;
if ($p_adm || role_pdks_can('daily_scan')) {
    try {
        require_once __DIR__ . '/config/pdks_gunluk.php';
        if (pdks_gunluk_sema_hazir()) $eskiAcikSayi = count(pdks_gunluk_eski_acik_oturumlar());
    } catch (Throwable $e) { $eskiAcikSayi = 0; }
}
// v354 Ters Kart Takip kartı: tablolar kurulmadıysa yalnız yönetici görür (kurulum uyarısı için). Hata sayfayı bozmaz.
$tersGoster = $p_adm;
if (!$tersGoster && $_fn && (role_pdks_can('daily_scan') || role_pdks_can('worker_cards') || role_pdks_can('daily_reports'))) {
    try {
        require_once __DIR__ . '/config/pdks_gunluk.php';
        $tersGoster = pdks_gunluk_ters_havuz_sema_hazir(db());
    } catch (Throwable $e) { $tersGoster = false; }
}

render_header('Personel Takibi');
echo '<link rel="stylesheet" href="' . base_url() . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="pdks-mobile-shell pdks-dashboard">
<div class="pdks-mobile-hero">
    <h1>Personel Takibi</h1>
</div>

<div class="home-grid pdks-dashboard-grid">
<?php $visiblePdksPages=0; foreach (role_pdks_pages() as $pageKey=>$pagePolicy): ?>
<?php if ($pageKey==='hub' || !role_pdks_can($pageKey)) continue; $visiblePdksPages++; ?>
<a href="<?= h($pagePolicy['route']) ?>" class="home-card">
    <div class="home-card-icon pdks-icon-card" aria-hidden="true"></div>
    <div class="home-card-title"><?= h($pagePolicy['label']) ?></div>
    <div class="home-card-sub"><?= role_pdks_can($pageKey,'create') || role_pdks_can($pageKey,'edit') || role_pdks_can($pageKey,'delete') ? 'Yetkiniz olan işlemleri açın' : 'Görüntüleme' ?></div>
</a>
<?php endforeach; ?>
<?php if ($p_adm): ?>
<a href="gunluk_fis_yukle.php" class="home-card"><div class="home-card-icon pdks-icon-card" aria-hidden="true"></div><div class="home-card-title">Fiş Excel Yükleme</div><div class="home-card-sub">Yönetici işlemi</div></a>
<?php endif; ?>
<?php if (!$visiblePdksPages && !$p_adm): ?><p class="muted">Bu rol genel ekranı görebilir. Alt sayfalar için ayrıca Gör yetkisi verilmelidir.</p><?php endif; ?>
</div>
</div>

<?php render_footer(); ?>
