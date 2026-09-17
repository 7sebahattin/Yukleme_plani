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
        <!-- ⚠ Faz 9C / H-02 kapanışı: sabit 08:00-17:00 vardiya modeli VE
             saat-kilidi (clock-of-day) toleransı TAMAMEN kaldırıldı —
             pdks_faz8b_sure_karari() artık yalnız GEÇEN SÜREYİ çavuşun
             (foremen.normal_work_minutes → oturum açılırken
             daily_work_sessions.normal_work_minutes_snapshot'a donan)
             ANLAŞMALI normal süresiyle karşılaştırır. Metin BURADA da gerçek
             davranışa çekildi — Faz 9A/L-05'in "metin gerçeği yansıtmalı"
             ilkesinin devamı. -->
        <p><strong>Kurallar (Faz 9C):</strong> Sabit bir başlangıç/bitiş SAATİ YOKTUR — işçi
            08:00'de de 10:00'da da başlayabilir. Her çavuşun kendi ANLAŞMALI normal günlük
            çalışma süresi vardır (dakika olarak <code>foremen.normal_work_minutes</code>,
            varsayılan 540 dk / 9 saat — Çavuşlar ekranından düzenlenir); bu süre KADIN/ERKEK
            için AYNIDIR. Otomatik Tam: geçen süre bu normal süreye ULAŞTIYSA/geçtiyse. Altında
            kalan dönemler muhasebe Tam/Yarım kararı ister.
            Fazla mesai normal süre TAMAMLANDIKTAN SONRAKİ süreden ölçülür (planlı bir SAATTEN
            değil): ilk 15 dk tolerans FM sayılmaz; sonrasında başlayan her saat yukarı
            yuvarlanır (16–75 dk = 1 saat, 76–135 dk = 2 saat, ...). Her fazla mesai adayı
            muhasebe onayına tabidir — muhasebe HESAPLANAN adayın altında bir "Onaylanan FM
            Saati" seçebilir, üstüne çıkamaz.
            Her mesai oturumu KENDİ açıldığı andaki normal süreyi donmuş olarak taşır — çavuşun
            süresi sonradan değişse bile GEÇMİŞ oturumlar/kesinleşmiş hakedişler ETKİLENMEZ.</p>
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
