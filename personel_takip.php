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
// Kartlar role_pdks_can() ile sayfanın Gör iznine bakar. Eski paketler
// merkezi uyumluluk katmanında korunur; hedeflerin işlem kapıları ayrıca çalışır.
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
if (!$tersGoster && $_fn && (role_pdks_can('ters_pool') || role_pdks_can('ters_count') || role_pdks_can('ters_report'))) {
    try {
        require_once __DIR__ . '/config/pdks_gunluk.php';
        $tersGoster = pdks_gunluk_ters_havuz_sema_hazir(db());
    } catch (Throwable $e) { $tersGoster = false; }
}

// Mesai Değerlendirme / Manuel Çıkış kayıt kimliği ister; kartlar kayıt seçiciyi
// (personel_kayit_sec.php) açar. Kapı seçicininkiyle birebir aynıdır: Gör izni +
// "Çavuş fiyatları Gör". Burada hiçbir sorgu çalışmaz.
$contextMesai=false; $contextExit=false;
if (role_pdks_can('mesai') || role_pdks_can('manual_exit')) {
    require_once __DIR__ . '/config/pdks_hakedis.php';
    $contextFinancial = pdks_hakedis_can('entitlements_finalize');
    $contextMesai = role_pdks_can('mesai') && $contextFinancial;
    $contextExit = role_pdks_can('manual_exit') && $contextFinancial;
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

<?php if ($p_gunluk || $tersGoster): ?>
<div class="home-section-title">GÜNLÜK İŞÇİ</div>

<?php if ($p_adm || role_pdks_can('foremen')): ?>
<a href="cavuslar.php" class="home-card">
    <div class="home-card-icon pdks-icon-foreman" aria-hidden="true"></div>
    <div class="home-card-title">Çavuşlar</div>
    <div class="home-card-sub">Çavuş tanımları</div>
</a>
<?php endif; ?>

<?php if ($p_adm || role_pdks_can('worker_cards')): ?>
<a href="isci_kartlari.php" class="home-card">
    <div class="home-card-icon pdks-icon-card" aria-hidden="true"></div>
    <div class="home-card-title">Kart Havuzu</div>
    <div class="home-card-sub">Nötr günlük işçi kartları ve kart yönetimi</div>
</a>
<?php endif; ?>

<?php if ($p_adm || role_pdks_can('daily_scan')): ?>
<a href="gunluk_isci_giris_cikis.php" class="home-card">
    <div class="home-card-icon pdks-icon-scan" aria-hidden="true"></div>
    <div class="home-card-title">Günlük İşçi Giriş / Çıkış</div>
    <div class="home-card-sub">Mesai kart taraması</div>
    <?php if ($eskiAcikSayi > 0): ?>
    <div class="home-card-badge" style="background:var(--warn)" title="Önceki günden kapatılmamış mesai"><?= $eskiAcikSayi ?></div>
    <?php endif; ?>
</a>
<?php endif; ?>

<?php if ($tersGoster): ?>
<a href="ters_kart.php" class="home-card">
    <div class="home-card-icon pdks-icon-giris-tanimli" aria-hidden="true"></div>
    <div class="home-card-title">↩ Giriş Tanımlı</div>
    <div class="home-card-sub">Sabah kalan kart sayımı · hızlı çıkış · mutabakat</div>
</a>
<?php endif; ?>

<?php if ($p_adm || role_pdks_can('daily_reports')): ?>
<a href="gunluk_isci_puantaj.php" class="home-card">
    <div class="home-card-icon pdks-icon-calendar" aria-hidden="true"></div>
    <div class="home-card-title">Günlük Puantaj</div>
    <div class="home-card-sub">Mesai özeti ve eksik çıkışlar</div>
</a>
<?php endif; ?>
<?php if ($p_adm): ?>
<a href="gunluk_fis_yukle.php" class="home-card home-card-ek">
    <div class="home-card-icon pdks-icon-card" aria-hidden="true"></div>
    <div class="home-card-title">Fiş Excel Yükleme</div>
    <div class="home-card-sub">Yönetici işlemi</div>
</a>
<?php endif; ?>
<?php endif; ?>

<?php if ($p_hakcari): ?>
<div class="home-section-title">HAKEDİŞ &amp; CARİ</div>

<?php if ($p_adm || role_pdks_can('foreman_rates')): ?>
<a href="cavus_fiyatlari.php" class="home-card">
    <div class="home-card-icon pdks-icon-rates" aria-hidden="true"></div>
    <div class="home-card-title">Çavuş Ücretleri</div>
    <div class="home-card-sub">Tam / Yarım / Fazla mesai ücretleri</div>
</a>
<?php endif; ?>

<?php if ($p_adm || role_pdks_can('entitlements')): ?>
<a href="cavus_hakedis.php" class="home-card">
    <div class="home-card-icon pdks-icon-entitlements" aria-hidden="true"></div>
    <div class="home-card-title">Hakedişler</div>
    <div class="home-card-sub">Mesai değerlendirme + taslak / kesin hakediş</div>
</a>
<?php endif; ?>

<?php if ($p_adm || role_pdks_can('foreman_accounts')): ?>
<a href="cavus_cari.php" class="home-card">
    <div class="home-card-icon pdks-icon-accounts" aria-hidden="true"></div>
    <div class="home-card-title">Çavuş Cari Hesapları</div>
    <div class="home-card-sub">Güncel bakiye listesi</div>
</a>
<?php endif; ?>

<?php if ($p_adm || role_pdks_can('foreman_payments')): ?>
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

<?php
// Granüler sayfalar kendi Gör kapısını korur; her biri kendi kartıdır.
$personelEkSayfalar = [
    'employees'=>['personel.php','Personeller','pdks-icon-foreman','Personel kayıtları'],
    'cards'=>['personel_kartlar.php','Personel Kartları','pdks-icon-card','Kalıcı personel kartları'],
    'scan'=>['giris_cikis.php','Personel Giriş / Çıkış','pdks-icon-scan','Kalıcı personel kart taraması'],
    'worker_types'=>['isci_tipleri.php','İşçi Tipleri','pdks-icon-rates','İşçi tipi tanımları'],
];
// ŞİMDİLİK GİZLİ (sahip kararı): «Diğer personel sayfaları» ile «Mesai Değerlendirme / Manuel Çıkış»
// kayıt-seçici kartları hub'da görünmez. Geri açmak için true yapın (sayfalar/seçici duruyor).
$ptakEkBolumlerGoster = false;
$personelEkGoster = false;
if ($ptakEkBolumlerGoster) foreach ($personelEkSayfalar as $ekKey=>$ekPage) if (role_pdks_can($ekKey)) $personelEkGoster = true;
?>
<?php if ($personelEkGoster): ?>
<div class="home-section-title">PERSONEL</div>
<?php foreach ($personelEkSayfalar as $ekKey=>[$ekRoute,$ekTitle,$ekIcon,$ekSub]): ?>
<?php if (role_pdks_can($ekKey)): ?>
<a href="<?= h($ekRoute) ?>" class="home-card home-card-ek">
    <div class="home-card-icon <?= h($ekIcon) ?>" aria-hidden="true"></div>
    <div class="home-card-title"><?= h($ekTitle) ?></div>
    <div class="home-card-sub"><?= h($ekSub) ?></div>
</a>
<?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($ptakEkBolumlerGoster && ($contextMesai || $contextExit)): ?>
<div class="home-section-title">MESAİ İŞLEMLERİ</div>
<?php if ($contextMesai): ?>
<a href="personel_kayit_sec.php?tur=mesai" class="home-card home-card-ek">
    <div class="home-card-icon pdks-icon-entitlements" aria-hidden="true"></div>
    <div class="home-card-title">Mesai Değerlendirme</div>
    <div class="home-card-sub">Son mesailerden kayıt seçerek açılır</div>
</a>
<?php endif; ?>
<?php if ($contextExit): ?>
<a href="personel_kayit_sec.php?tur=cikis" class="home-card home-card-ek">
    <div class="home-card-icon pdks-icon-calendar" aria-hidden="true"></div>
    <div class="home-card-title">Manuel Çıkış</div>
    <div class="home-card-sub">Çıkışı bekleyen kayıttan seçerek açılır</div>
</a>
<?php endif; ?>
<?php endif; ?>
<?php if (!$p_gunluk && !$p_hakcari && !$p_rapor && !$tersGoster && !$personelEkGoster): ?>
<p class="muted">Genel ekranı görüntülüyorsunuz. Alt sayfalar ayrıca yetkilendirilir. Mesai değerlendirme ve manuel çıkış, ilgili mesai kaydından açılır.</p>
<?php endif; ?>

</div>
</div>

<?php render_footer(); ?>
