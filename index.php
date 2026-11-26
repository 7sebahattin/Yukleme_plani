<?php
// =========================================================
// index.php - Ana Sayfa / Dashboard
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
// 'dashboard.read' yoksa 403 BASMA: giriş akışı buraya düşer (login →
// depo_sec → index.php) ve kullanıcı sistemi hiç kullanamazdı. Bunun yerine
// gerçekten açabildiği ilk sayfaya yolla; hiçbiri yoksa anlaşılır bir mesaj
// ver (yetkisiz rol atanmış demektir).
if (!can('dashboard.read')) {
    $ilk_sayfa = first_allowed_page();
    if ($ilk_sayfa !== null) {
        header('Location: ' . base_url() . $ilk_sayfa);
        exit;
    }
    forbidden('Hesabınıza henüz hiçbir sayfa için yetki tanımlanmamış. Lütfen sistem yöneticinize başvurun.');
}

// ── DB-Backup-01 / B-32: Admin girişinde günlük otomatik yedek (yanıtı bloklamadan) ───
$db_backup_result = null;
$db_backup_eski   = null;   // DB-Backup-02: son başarılı yedek yok/eski ise uyarı metni
if (is_admin()) {
    require_once __DIR__ . '/config/db_backup_helpers.php';
    $_bkp_cron_only = defined('DB_BACKUP_CRON_ONLY') && (bool)DB_BACKUP_CRON_ONLY;
    $_bkp_uid = (int)($auth_user['id'] ?? 0);
    if (!$_bkp_cron_only) {
        if (function_exists('fastcgi_finish_request')) {
            // B-32: PHP-FPM/LiteSpeed altında yanıt tarayıcıya aktarıldıktan sonra arka planda çalışır (dashboard'u dondurmaz).
            register_shutdown_function(static function () use ($_bkp_uid): void {
                if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
                @fastcgi_finish_request();
                try { create_daily_backup_if_needed(db(), $_bkp_uid); } catch (Throwable $_e) { error_log('[db_backup_shutdown] ' . $_e->getMessage()); }
            });
        } else {
            try {
                if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
                $db_backup_result = create_daily_backup_if_needed(db(), $_bkp_uid);
            } catch (Throwable $_bkp_err) {
                error_log('[db_backup_index] ' . $_bkp_err->getMessage());
            }
        }
    }
    if ($db_backup_result === null) {
        try {
            $_bkp_son = last_successful_backup(db());
            $_bkp_yas = _bh_last_age_hours($_bkp_son);
            if ($_bkp_son === null) {
                $db_backup_eski = 'Henüz başarılı bir veritabanı yedeği yok';
            } elseif ($_bkp_yas !== null && $_bkp_yas > DB_BACKUP_STALE_SAAT) {
                $db_backup_eski = 'Son başarılı veritabanı yedeği ' . _bh_yas_metni($_bkp_yas) . ' önce';
            }
        } catch (Throwable $_bkp_err) {}
    }
}

// Bölüm görünürlükleri
// Ana sayfa grup başlıkları, sıralaması, kart sırası ve yetki kapıları TEK kaynak
// olan nav_menu_modeli('') üzerinden sol kenar çubuğu (render_desktop_sidebar) ile birebir üretilir.
$__home_menu = nav_menu_modeli('');

render_header('Ana Sayfa');
render_flash();
// Backup bildirimi (admin, HTML link içerdiği için render_flash() dışında)
// busy: başka bir yedek şu anda çalışıyor — kutu gösterilmez.
if ($db_backup_result !== null && empty($db_backup_result['busy'])): ?>
<div class="flash flash-<?= $db_backup_result['ok'] ? 'success' : 'error' ?>" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <span>
        <?php if ($db_backup_result['ok']): ?>
            🗄️ Bugünün veritabanı yedeği oluşturuldu (<?= h($db_backup_result['method']) ?>).
        <?php elseif (!empty($db_backup_result['file_ok']) && empty($db_backup_result['db_ok'])): ?>
            ⚠️ Yedek dosyası oluşturuldu fakat kayıt tablosuna yazılamadı.
        <?php else: ?>
            ⚠️ Otomatik veritabanı yedeği oluşturulamadı<?= !empty($db_backup_result['error']) ? ': ' . h(mb_substr((string)$db_backup_result['error'], 0, 160)) : '.' ?>
        <?php endif; ?>
    </span>
    <span style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($db_backup_result['ok'] && $db_backup_result['id'] > 0): ?>
        <a href="admin_db_backups.php?action=download&id=<?= (int)$db_backup_result['id'] ?>"
           style="font-weight:700;color:inherit;text-decoration:underline">⬇ İndir</a>
        <?php endif; ?>
        <a href="admin_db_backups.php" style="color:inherit;text-decoration:underline">Tüm yedekler</a>
    </span>
</div>
<?php elseif ($db_backup_eski !== null): ?>
<div class="flash flash-error" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <span>⚠️ <?= h($db_backup_eski) ?>.</span>
    <a href="admin_db_backups.php" style="color:inherit;text-decoration:underline">Tüm yedekler</a>
</div>
<?php endif; ?>

<div class="home-grid">
<?php foreach ($__home_menu['gruplar'] as $__hg):
    if (empty($__hg['ogeler'])) continue; ?>
<div class="home-section-title"><?= h($__hg['ad']) ?></div>
<?php foreach ($__hg['ogeler'] as $__ho):
    $__href   = (string)$__ho['href'];
    $__ikon3d = nav_3d_ikon_url($__href);
?>
    <a href="<?= h($__href) ?>" class="home-card">
        <div class="home-card-icon" aria-hidden="true"><?php if ($__ikon3d !== null): ?><img src="<?= h($__ikon3d) ?>" width="64" height="64" alt="" loading="lazy" decoding="async"><?php else: ?><?= h((string)$__ho['ikon']) ?><?php endif; ?></div>
        <div class="home-card-title"><?= h($__ho['etiket']) ?></div>
    </a>
<?php endforeach; ?>
<?php endforeach; unset($__home_menu, $__hg, $__ho, $__href, $__ikon3d); ?>
</div>

<!-- Sürüm damgası — mobilde sidebar görünmediği için buraya da konur.
     "Deploy yansıdı mı?" sorusunun telefondan cevabı. -->
<div class="muted" style="text-align:center;font-size:.75rem;margin:18px 0 6px;opacity:.7">
    <?= h(APP_SURUM) ?>
</div>

<?php render_footer(); ?>
