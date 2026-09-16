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
        <p><strong>Kurallar:</strong> 9 saat normal mesai; 15 dakika tolerans. 8s45dk ve üzeri otomatik Tam. 9 saati aşan ilk 15 dakika fazla mesai sayılmaz; 16–75 dk = 1 saat, 76–135 dk = 2 saat. Fazla mesai muhasebe onayına tabidir.</p>
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
