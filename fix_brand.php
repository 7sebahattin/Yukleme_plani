<?php
// =========================================================
// fix_brand.php — Marka (brand) kolonu onarım (admin-only, web)
// loading_records.brand kolonunu güvenli/idempotent ekler.
// scripts/ web'e kapalı olduğu için bu kök dosya kullanılır.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_login();
if (!function_exists('is_admin') || !is_admin()) {
    http_response_code(403);
    die('Bu işlem yalnızca admin kullanıcılar içindir.');
}

$msg = '';
$state = 'info';
$has_now = false;

try {
    $has_now = (bool)db()->query("SHOW COLUMNS FROM `loading_records` LIKE 'brand'")->fetchColumn();
} catch (Throwable $e) { /* yoksay */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    try {
        if ($has_now) {
            $state = 'ok'; $msg = 'brand kolonu zaten mevcut — işlem gerekmedi.';
        } else {
            db()->exec("ALTER TABLE `loading_records` ADD COLUMN `brand` VARCHAR(20) NULL");
            $has_now = true;
            $state = 'ok'; $msg = '✓ brand kolonu başarıyla eklendi. Artık marka kaydedilebilir.';
        }
    } catch (Throwable $e) {
        $state = 'err';
        $msg = 'HATA: ' . $e->getMessage();
    }
}

render_header('Marka Kolonu Onarım');
?>
<div class="page-head"><h1>🔧 Marka Kolonu Onarım</h1></div>
<div class="card" style="max-width:640px">
    <?php if ($msg !== ''): ?>
    <div style="padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:.9rem;
        background:<?= $state==='ok' ? '#dcfce7' : ($state==='err' ? '#fee2e2' : '#f1f5f9') ?>;
        color:<?= $state==='ok' ? '#166534' : ($state==='err' ? '#991b1b' : '#334155') ?>">
        <?= h($msg) ?>
    </div>
    <?php endif; ?>

    <?php if ($has_now): ?>
        <p style="font-size:.92rem">Veritabanında <code>loading_records.brand</code> kolonu <strong>mevcut</strong>. Marka seçimi çalışıyor olmalı.</p>
        <a href="records.php" class="btn btn-primary">← Kayıtlara Dön</a>
    <?php else: ?>
        <p style="font-size:.92rem;margin-bottom:12px">
            <code>loading_records.brand</code> kolonu eksik. Aşağıdaki butona basınca eklenmeye çalışılır.
        </p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <button type="submit" class="btn btn-primary btn-lg">Kolonu Şimdi Ekle</button>
        </form>
        <?php if ($state === 'err'): ?>
        <p style="font-size:.85rem;color:#991b1b;margin-top:14px">
            Ekleme başarısız oldu (DB kullanıcısında <code>ALTER</code> yetkisi olmayabilir).
            phpMyAdmin → SQL sekmesinde şunu çalıştırın:<br>
            <code style="display:inline-block;margin-top:6px;background:#fff;padding:4px 8px;border-radius:4px;user-select:all">ALTER TABLE `loading_records` ADD COLUMN `brand` VARCHAR(20) NULL;</code>
        </p>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
