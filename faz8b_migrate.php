<?php
// Faz 8B — kontrollü, admin-only additive migrasyon.
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/auth.php';

$auth_user = require_login();
if (!is_admin()) forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.');
$pdo = db();
$results = [];
$ran = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $ran = true;
    $results = pdks_faz8b_migrate($pdo);
    foreach ($results as $r) {
        if (($r['durum'] ?? '') === 'eklendi' && function_exists('audit_log_event')) {
            audit_log_event('migrate', 'pdks_faz8b', null, null, $r);
        }
    }
}

render_header('Faz 8B Migrasyon');
?>
<div class="container">
    <div class="page-head">
        <h1>Faz 8B — Mesai Değerlendirme / Ücretlendirme</h1>
        <div class="page-head-actions"><a class="btn" href="migrate.php">← Ana Migrasyon Paneli</a></div>
    </div>

    <div class="card" style="padding:18px 20px;margin:16px 0">
        <p>Bu migrasyon yalnız additive kolonlar ekler. Faz 8A tarama kayıtlarını değiştirmez; mevcut Tam Mesai fiyatı <code>daily_rate</code> olarak korunur.</p>
        <!-- ⚠ Faz 9A / L-05 düzeltmesi: bu metin eskiden GERÇEK DAVRANIŞLA
             UYUŞMUYORDU — "8s45dk ve üzeri otomatik Tam" YANLIŞTI (asıl eşik
             tolerans penceresiyle 8s30dk'da tetiklenir, bkz. 08:15-16:45) ve
             "9 saati aşan ilk 15 dakika" YANLIŞTI (fazla mesai fiili süreden
             DEĞİL, SABİT 17:00 planlı bitişten ölçülür — pdks_faz8b_sure_karari()
             DEĞİŞTİRİLMEDİ, yalnız BURADAKİ metin gerçek davranışa çekildi). -->
        <p><strong>Kurallar:</strong> 9 saat (540 dk) normal mesai; giriş/çıkışta 15 dakika tolerans.
            Otomatik Tam: fiili süre 540 dk ve üzeriyse, VEYA giriş 08:15 veya öncesi VE çıkış
            16:45 veya sonrasıysa. Bu ikisinin dışındaki kısa/erken-biten dönemler muhasebe
            Tam/Yarım kararı ister.
            Fazla mesai HER ZAMAN planlı 17:00 bitişinden ölçülür (fiili süreden değil):
            17:15'e kadar FM yok; 17:16–18:15 = 1 saat; 18:16–19:15 = 2 saat; sonrası aynı
            desende artar. Her fazla mesai adayı muhasebe onayına tabidir.</p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <button class="btn btn-primary" type="submit">Faz 8B Migrasyonunu Çalıştır</button>
        </form>
    </div>

    <?php if ($ran): ?>
    <div class="card" style="padding:18px 20px;margin:16px 0">
        <h2 style="margin-top:0">Sonuç</h2>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Adım</th><th>Durum</th><th>Mesaj</th></tr></thead>
            <tbody>
            <?php foreach ($results as $r): ?>
                <tr>
                    <td><?= h($r['adim'] ?? '') ?></td>
                    <td><strong><?= h($r['durum'] ?? '') ?></strong></td>
                    <td><?= h($r['mesaj'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    <div class="card" style="padding:18px 20px;margin:16px 0">
        <strong>Faz 8B şema durumu:</strong>
        <?= pdks_faz8b_sema_hazir($pdo) ? '✓ Hazır' : '✗ Henüz hazır değil' ?>
    </div>
</div>
<?php render_footer(); ?>
