<?php
// =========================================================
// mail.php — Mail Merkezi (M1: iskelet)
// Kapı: can_mail('read') — sidebar / bottomnav / index kartı / first_allowed_page
// ile AYNI fonksiyon. Gelen kutusu arayüzü M3'te gelir; bu sürüm kurulum
// durumunu ve kullanıcının görebildiği hesapları gösterir.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/mail_core.php';
$auth_user = require_login();
require_mail('read');

$pdo    = db();
$uid    = (int)$auth_user['id'];
$hazir  = mail_sema_hazir($pdo);
$anahtar = mail_crypto_hazir();
$yonetici = can_mail('admin');
$hesaplar = [];
$sayilar  = [];
if ($hazir) {
    $ids = mail_gorunur_hesap_idleri($uid, $pdo);
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id, label, email, is_active FROM mail_accounts WHERE id IN ($in) ORDER BY label");
        $st->execute($ids);
        $hesaplar = $st->fetchAll(PDO::FETCH_ASSOC);
        $sayilar  = mail_okunmamis_sayilari($uid, $pdo);
    }
}

render_header('Mail Merkezi');
?>
<div class="container">
    <h1 style="margin:0 0 12px">📧 Mail Merkezi</h1>

    <?php if (!$hazir): ?>
    <div class="card" style="padding:16px">
        <p><strong>Mail Merkezi henüz kurulmadı.</strong> Veritabanı tabloları oluşturulmamış.</p>
        <?php if (is_admin()): ?>
        <p><a class="btn btn-primary" href="migrate.php">Şema Migrasyonu (migrate.php)</a></p>
        <?php else: ?>
        <p>Sistem yöneticisinden kurulumu istemeniz gerekir.</p>
        <?php endif; ?>
    </div>
    <?php else: ?>
        <?php if (!$anahtar && $yonetici): ?>
        <div class="card" style="padding:12px;border-left:4px solid var(--warn,#d97706);margin-bottom:12px">
            <strong>Şifreleme anahtarı tanımlı değil.</strong>
            Hesap şifreleri saklanamaz ve senkron çalışmaz. <code>config/local.php</code> içine
            <code>MAIL_MASTER_KEY</code> ekleyin (bkz. docs/MAIL_CENTER_AGENT_BRIDGE.md).
        </div>
        <?php endif; ?>

        <?php if (!$hesaplar): ?>
        <div class="card" style="padding:16px">
            <?php if ($yonetici): ?>
            <p>Henüz mail hesabı tanımlı değil.</p>
            <p><a class="btn btn-primary" href="mail_hesaplar.php">Hesap Ekle</a></p>
            <?php else: ?>
            <p>Size atanmış bir mail hesabı yok. Sistem yöneticisinden hesap ataması isteyin.</p>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Hesap</th><th>Adres</th><th>Okunmamış</th><th>Durum</th></tr></thead>
                <tbody>
                <?php foreach ($hesaplar as $a): ?>
                    <tr>
                        <td><?= h($a['label']) ?></td>
                        <td><?= h($a['email']) ?></td>
                        <td><?= (int)($sayilar[(int)$a['id']] ?? 0) ?></td>
                        <td><?= (int)$a['is_active'] === 1 ? 'Aktif' : 'Pasif' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p style="color:#666;font-size:.9em">Gelen kutusu ekranı bir sonraki sürümde eklenecek.</p>
        <?php if ($yonetici): ?><p><a class="btn" href="mail_hesaplar.php">Hesapları Yönet</a></p><?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
