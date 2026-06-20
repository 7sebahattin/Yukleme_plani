<?php
// HKS Teknik / Yönetim — teknik ekranların toplandığı panel (yalnızca admin / hks.settings)
declare(strict_types=1);
require_once __DIR__ . '/includes/hks_bootstrap.php';
$auth_user = require_login();
if (!is_admin() && !(function_exists('can') && can('hks.settings'))) {
    http_response_code(403);
    die('Bu bölüme yalnızca yöneticiler erişebilir.');
}

// Yol önekleri — teknik.php hks/ kökündedir
$op_dir  = 'operasyon/';
$op_root = '';
require __DIR__ . '/operasyon/views/_op_init.php';   // firma guard + $op_* (yukarıdaki önekleri korur)

$soap_ok   = hks_check_soap();
$has_cfg   = $op_client->hasSettings();
$live_on   = $op_client->isLiveSendEnabled();
$refStats  = $op_repo->getReferenceStats();
$recentLogs = $op_repo->getRecentLogs(5);

// Teknik kartlar
$cards = [
    ['ayarlar.php',        '⚙️',  'Ayarlar',          'HKS giriş bilgileri, WSDL, şifreleme anahtarı, canlı gönderim'],
    ['baglanti_test.php',  '🔌',  'Bağlantı Testi',    'HKS web servisine bağlantıyı test et'],
    ['referanslar.php',    '📚',  'Referanslar',       'Ürün, il, ilçe, sıfat vb. referans senkronizasyonu'],
    ['veri_cek.php',       '📡',  'Veri Çek',          'HKS servislerinden veri çekme / sorgu denemeleri'],
    ['servis_loglari.php', '🧾',  'Servis Logları',    'Gönderilen istek ve yanıtların teknik kaydı'],
    ['teshis.php',         '🔬',  'Teşhis',            'WSDL metod keşfi ve yanıt teşhis ekranları'],
    ['wsdl_tipleri.php',   '🧩',  'WSDL Tipleri',      'BildirimKayitIstek gerçek alan adlarını WSDL\'den çıkar (yalnız okuma)'],
    ['firmalar.php',       '🏢',  'Firmalar',          'HKS firma tanımları ve yönetimi'],
];

$op_page_title  = 'HKS Teknik / Yönetim';
$op_active_tab  = 'teknik';
$op_active_menu = '';
include __DIR__ . '/operasyon/views/_layout_start.php';
?>

<div class="hks-op-note warn">
    🛠 Bu bölüm teknik kullanıcılar içindir. Günlük bildirim işlemleri için <a href="operasyon/index.php">HKS Operasyon</a> ekranını kullanın.
</div>

<!-- Sistem durumu -->
<p class="hks-op-section-title">Sistem Durumu</p>
<div class="hks-op-grid" style="margin-bottom:18px">
    <div class="hks-op-card" style="border-left:3px solid <?= $soap_ok ? 'var(--success)' : 'var(--danger)' ?>">
        <div style="font-weight:700"><?= $soap_ok ? '✅ SOAP Aktif' : '❌ SOAP Yok' ?></div>
        <div class="muted" style="font-size:.82rem">PHP SOAP eklentisi</div>
    </div>
    <div class="hks-op-card" style="border-left:3px solid <?= $has_cfg ? 'var(--success)' : '#f59e0b' ?>">
        <div style="font-weight:700"><?= $has_cfg ? '✅ Yapılandırıldı' : '⚙️ Eksik' ?></div>
        <div class="muted" style="font-size:.82rem">HKS bağlantı ayarları</div>
    </div>
    <div class="hks-op-card" style="border-left:3px solid <?= $live_on ? 'var(--danger)' : 'var(--border)' ?>">
        <div style="font-weight:700"><?= $live_on ? '🔴 Canlı Gönderim Açık' : '🟡 Canlı Gönderim Kapalı' ?></div>
        <div class="muted" style="font-size:.82rem">BildirimKaydet durumu</div>
    </div>
    <div class="hks-op-card" style="border-left:3px solid var(--primary)">
        <div style="font-weight:700"><?= count($refStats) ?>/<?= count(HKS_REF_TYPES) ?></div>
        <div class="muted" style="font-size:.82rem">Senkronize referans tipi</div>
    </div>
</div>

<!-- Teknik ekran kartları -->
<p class="hks-op-section-title">Teknik Ekranlar</p>
<div class="hks-op-grid">
<?php foreach ($cards as $c): [$file, $icon, $title, $desc] = $c; ?>
    <a href="<?= hks_h($file) ?>" class="hks-op-card" style="text-decoration:none;color:inherit">
        <div style="font-size:1.5rem"><?= $icon ?></div>
        <div style="font-weight:700;margin-top:6px"><?= hks_h($title) ?></div>
        <div class="muted" style="font-size:.8rem;margin-top:2px"><?= hks_h($desc) ?></div>
    </a>
<?php endforeach; ?>
</div>

<?php if ($recentLogs): ?>
<p class="hks-op-section-title" style="margin-top:18px">Son Servis Logları</p>
<div class="table-wrap">
<table class="hks-op-table">
    <thead><tr><th>Tarih</th><th>Servis</th><th>Method</th><th>Durum</th><th>ms</th></tr></thead>
    <tbody>
    <?php foreach ($recentLogs as $log): ?>
    <tr>
        <td style="white-space:nowrap;color:var(--muted);font-size:.8rem"><?= hks_h(date('d.m H:i', strtotime($log['created_at']))) ?></td>
        <td><?= hks_h($log['service_name']) ?></td>
        <td><?= hks_h($log['method_name']) ?></td>
        <td><?= $log['is_success'] ? '<span style="color:var(--success)">✓</span>' : '<span style="color:var(--danger)">✗</span>' ?></td>
        <td><?= hks_h($log['duration_ms']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php include __DIR__ . '/operasyon/views/_layout_end.php'; ?>
