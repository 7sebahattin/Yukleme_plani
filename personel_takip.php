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

$_fn = function_exists('can');
$p_adm = function_exists('is_admin') && is_admin();

// ── Bölüm görünürlüğü — "en az BİR aktif alt-fonksiyona erişim" ──────────
// Kalıcı PDKS izinleri bu landing için yeterli değildir; ilgili eski sayfalar
// kendi izin kapılarıyla yerinde kalır.
$p_gunluk   = $p_adm || ($_fn && (can('attendance.foremen') || can('attendance.worker_cards') || can('attendance.daily_scan') || can('attendance.daily_reports')));
$p_hakcari  = $p_adm || ($_fn && (can('attendance.foreman_rates') || can('attendance.entitlements') || can('attendance.foreman_accounts') || can('attendance.foreman_payments')));
$p_rapor    = $p_adm || ($_fn && can('attendance.management_reports'));

if (!$p_gunluk && !$p_hakcari && !$p_rapor) {
    forbidden('Bu sayfaya erişim yetkiniz yok. (Günlük İşçi, Hakediş/Cari veya Yönetim Raporları modüllerinden en az birine yetkiniz olmalı.)');
}

render_header('Personel Takibi');
echo '<link rel="stylesheet" href="' . base_url() . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="pdks-mobile-shell pdks-dashboard">
<div class="pdks-mobile-hero">
    <span class="pdks-mobile-eyebrow">PERSONEL OPERASYONLARI</span>
    <h1>Personel Takibi</h1>
    <p>Günlük işçi, hakediş ve raporlar tek yerde.</p>
</div>

<div class="home-grid pdks-dashboard-grid">

<?php if ($p_gunluk): ?>
<div class="home-section-title">GÜNLÜK İŞÇİ</div>

<?php if ($p_adm || can('attendance.foremen')): ?>
<a href="cavuslar.php" class="home-card">
    <div class="home-card-icon pdks-icon-foreman" aria-hidden="true"></div>
    <div class="home-card-title">Çavuşlar</div>
    <div class="home-card-sub">Çavuş tanımları</div>
</a>
<?php endif; ?>

<?php if ($p_adm || can('attendance.worker_cards')): ?>
<a href="isci_kartlari.php" class="home-card">
    <div class="home-card-icon pdks-icon-card" aria-hidden="true"></div>
    <div class="home-card-title">Kart Havuzu</div>
    <div class="home-card-sub">Nötr günlük işçi kartları ve kart yönetimi</div>
</a>
<?php endif; ?>

<?php if ($p_adm || can('attendance.daily_scan')): ?>
<a href="gunluk_isci_giris_cikis.php" class="home-card">
    <div class="home-card-icon pdks-icon-scan" aria-hidden="true"></div>
    <div class="home-card-title">Günlük İşçi Giriş / Çıkış</div>
    <div class="home-card-sub">Mesai kart taraması</div>
</a>
<?php endif; ?>

<?php if ($p_adm || can('attendance.daily_reports')): ?>
<a href="gunluk_isci_puantaj.php" class="home-card">
    <div class="home-card-icon pdks-icon-calendar" aria-hidden="true"></div>
    <div class="home-card-title">Günlük Puantaj</div>
    <div class="home-card-sub">Mesai özeti ve eksik çıkışlar</div>
</a>
<?php endif; ?>
<?php endif; ?>

<?php if ($p_hakcari): ?>
<div class="home-section-title">HAKEDİŞ &amp; CARİ</div>

<?php if ($p_adm || can('attendance.foreman_rates')): ?>
<a href="cavus_fiyatlari.php" class="home-card">
    <div class="home-card-icon pdks-icon-rates" aria-hidden="true"></div>
    <div class="home-card-title">Çavuş Fiyatları</div>
    <div class="home-card-sub">Tam / Yarım / Fazla mesai ücretleri</div>
</a>
<?php endif; ?>

<?php if ($p_adm || can('attendance.entitlements')): ?>
<a href="cavus_hakedis.php" class="home-card">
    <div class="home-card-icon pdks-icon-entitlements" aria-hidden="true"></div>
    <div class="home-card-title">Hakedişler</div>
    <div class="home-card-sub">Mesai değerlendirme + taslak / kesin hakediş</div>
</a>
<?php endif; ?>

<?php if ($p_adm || can('attendance.foreman_accounts')): ?>
<a href="cavus_cari.php" class="home-card">
    <div class="home-card-icon pdks-icon-accounts" aria-hidden="true"></div>
    <div class="home-card-title">Çavuş Cari Hesapları</div>
    <div class="home-card-sub">Güncel bakiye listesi</div>
</a>
<?php endif; ?>

<?php if ($p_adm || can('attendance.foreman_payments')): ?>
<a href="cavus_odeme.php" class="home-card">
    <div class="home-card-icon pdks-icon-payments" aria-hidden="true"></div>
    <div class="home-card-title">Çavuş Ödemeleri</div>
    <div class="home-card-sub">Ödeme kaydı ve geçmişi</div>
</a>
<?php endif; ?>
<?php endif; ?>

<?php if ($p_rapor): ?>
<div class="home-section-title">RAPORLAR</div>

<a href="raporlar.php" class="home-card">
    <div class="home-card-icon pdks-icon-analytics" aria-hidden="true"></div>
    <div class="home-card-title">Yönetim Raporları</div>
    <div class="home-card-sub">Operasyonel ve finansal yönetim raporları</div>
</a>
<a href="cavus_toplu_dokum.php" class="home-card">
    <div class="home-card-icon pdks-icon-report" aria-hidden="true"></div>
    <div class="home-card-title">Çavuş Toplu Döküm</div>
    <div class="home-card-sub">Aylık çavuş ve kart bazlı işçi dökümü</div>
</a>
<?php endif; ?>

</div>
</div>

<?php render_footer(); ?>
