<?php
// =========================================================
// personel_takip.php — Personel Takip Merkezi (Faz 7, Sprint Navigasyon-01)
//
// Faz 1-6'nın dağınık sidebar girdilerini (Personel/Kalıcı PDKS + Günlük
// İşçi + Hakediş/Cari + Raporlama, toplam 12 ayrı link) TEK bir merkezi
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

$_fn = function_exists('can');
$p_adm = function_exists('is_admin') && is_admin();

// ── Bölüm görünürlüğü — "en az BİR alt-fonksiyona erişim" (görev talimatı:
//    "Do not require one broad super-permission just to see the landing page.") ──
$p_personel = $p_adm || ($_fn && (can('attendance.employees') || can('attendance.cards') || can('attendance.scan')));
$p_gunluk   = $p_adm || ($_fn && (can('attendance.foremen') || can('attendance.worker_cards') || can('attendance.daily_scan') || can('attendance.daily_reports')));
$p_hakcari  = $p_adm || ($_fn && (can('attendance.foreman_rates') || can('attendance.entitlements') || can('attendance.foreman_accounts') || can('attendance.foreman_payments')));
$p_rapor    = $p_adm || ($_fn && can('attendance.management_reports'));

if (!$p_personel && !$p_gunluk && !$p_hakcari && !$p_rapor) {
    forbidden('Bu sayfaya erişim yetkiniz yok. (Personel/Günlük İşçi modüllerinden en az birine yetkiniz olmalı.)');
}

render_header('Personel Takibi');
render_flash();
?>

<div class="page-head">
    <h1>🧑‍🌾 Personel Takip Merkezi</h1>
</div>
<p class="muted" style="margin:-8px 0 18px">Kalıcı personel, günlük işçi, hakediş/cari ve raporlama — tek merkezden.</p>

<div class="home-grid">

<?php if ($p_personel): ?>
<div class="home-section-title">Personel</div>

<?php if ($p_adm || can('attendance.employees')): ?>
<a href="personel.php" class="home-card">
    <div class="home-card-icon" style="background:#eef2ff">👤</div>
    <div class="home-card-title">Personeller</div>
    <div class="home-card-sub">Kalıcı personel listesi</div>
</a>
<?php endif; ?>

<?php if ($p_adm || can('attendance.cards')): ?>
<a href="personel_kartlar.php" class="home-card">
    <div class="home-card-icon" style="background:#eef2ff">🪪</div>
    <div class="home-card-title">Personel Kartları</div>
    <div class="home-card-sub">Kart zimmet yönetimi</div>
</a>
<?php endif; ?>

<?php if ($p_adm || can('attendance.scan')): ?>
<a href="giris_cikis.php" class="home-card">
    <div class="home-card-icon" style="background:#eef2ff">🚪</div>
    <div class="home-card-title">Personel Giriş / Çıkış</div>
    <div class="home-card-sub">Kalıcı personel PDKS taraması</div>
</a>
<?php endif; ?>
<?php endif; ?>

<?php if ($p_gunluk): ?>
<div class="home-section-title">Günlük İşçi</div>

<?php if ($p_adm || can('attendance.foremen')): ?>
<a href="cavuslar.php" class="home-card">
    <div class="home-card-icon" style="background:#fff3e0">👷</div>
    <div class="home-card-title">Çavuşlar</div>
    <div class="home-card-sub">Çavuş tanımları</div>
</a>
<?php endif; ?>

<?php if ($p_adm || can('attendance.worker_cards')): ?>
<a href="isci_kartlari.php" class="home-card">
    <div class="home-card-icon" style="background:#fff3e0">🪪</div>
    <div class="home-card-title">İşçi Kartları</div>
    <div class="home-card-sub">Günlük işçi kart havuzu</div>
</a>
<a href="isci_tipleri.php" class="home-card">
    <div class="home-card-icon" style="background:#fff3e0">🏷</div>
    <div class="home-card-title">İşçi Tipleri</div>
    <div class="home-card-sub">Kadın / Erkek / diğer kategoriler</div>
</a>
<?php endif; ?>

<?php if ($p_adm || can('attendance.daily_scan')): ?>
<a href="gunluk_isci_giris_cikis.php" class="home-card">
    <div class="home-card-icon" style="background:#fff3e0">🚪</div>
    <div class="home-card-title">Günlük İşçi Giriş / Çıkış</div>
    <div class="home-card-sub">Mesai kart taraması</div>
</a>
<?php endif; ?>

<?php if ($p_adm || can('attendance.daily_reports')): ?>
<a href="gunluk_isci_puantaj.php" class="home-card">
    <div class="home-card-icon" style="background:#fff3e0">📅</div>
    <div class="home-card-title">Günlük Puantaj</div>
    <div class="home-card-sub">Mesai özeti ve eksik çıkışlar</div>
</a>
<?php endif; ?>
<?php endif; ?>

<?php if ($p_hakcari): ?>
<div class="home-section-title">Hakediş &amp; Cari</div>

<?php if ($p_adm || can('attendance.foreman_rates')): ?>
<a href="cavus_fiyatlari.php" class="home-card">
    <div class="home-card-icon" style="background:#e0f2f1">💰</div>
    <div class="home-card-title">Çavuş Fiyatları</div>
    <div class="home-card-sub">Tam / Yarım / Fazla mesai ücretleri</div>
</a>
<?php endif; ?>

<?php if ($p_adm || can('attendance.entitlements')): ?>
<a href="cavus_hakedis.php" class="home-card">
    <div class="home-card-icon" style="background:#e0f2f1">🧾</div>
    <div class="home-card-title">Hakedişler</div>
    <div class="home-card-sub">Mesai değerlendirme + taslak / kesin hakediş</div>
</a>
<?php endif; ?>

<?php if ($p_adm || can('attendance.foreman_accounts')): ?>
<a href="cavus_cari.php" class="home-card">
    <div class="home-card-icon" style="background:#e0f2f1">📒</div>
    <div class="home-card-title">Çavuş Cari Hesapları</div>
    <div class="home-card-sub">Güncel bakiye listesi</div>
</a>
<a href="cavus_cari.php" class="home-card">
    <div class="home-card-icon" style="background:#e0f2f1">📄</div>
    <div class="home-card-title">Ekstre</div>
    <div class="home-card-sub">Bir çavuş seçip hesap ekstresini görüntüleyin</div>
</a>
<?php endif; ?>

<?php if ($p_adm || can('attendance.foreman_payments')): ?>
<a href="cavus_odeme.php" class="home-card">
    <div class="home-card-icon" style="background:#e0f2f1">💸</div>
    <div class="home-card-title">Çavuş Ödemeleri</div>
    <div class="home-card-sub">Ödeme kaydı ve geçmişi</div>
</a>
<?php endif; ?>
<?php endif; ?>

<?php if ($p_rapor): ?>
<div class="home-section-title">Raporlar</div>

<a href="raporlar.php" class="home-card">
    <div class="home-card-icon" style="background:#faf0ff">📊</div>
    <div class="home-card-title">Personel / Günlük İşçi Raporları</div>
    <div class="home-card-sub">Yönetim raporlama merkezi</div>
</a>
<a href="cavus_toplu_dokum.php" class="home-card">
    <div class="home-card-icon" style="background:#faf0ff">📋</div>
    <div class="home-card-title">Çavuş Toplu Döküm</div>
    <div class="home-card-sub">Aylık çavuş ve kart bazlı işçi dökümü</div>
</a>
<?php endif; ?>

</div>

<?php render_footer(); ?>
