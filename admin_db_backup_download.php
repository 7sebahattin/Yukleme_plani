<?php
// =========================================================
// admin_db_backup_download.php — Geçici DB Yedek İndirme
//
// !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
// BU DOSYA GEÇİCİDİR.
// Yedek alındıktan sonra sunucudan ve repodan
// DERHAL KALDIRILMALIDIR.
// !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
//
// Yalnızca admin hesabına açık.
// POST + CSRF + onay metni olmadan backup başlamaz.
// Backup stream ile indirilir; sunucuda kalıcı dosya bırakmaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';   // helpers.php dahil
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!is_admin()) {
    forbidden('Bu araç yalnızca sistem yöneticilerine açıktır.');
}

const BACKUP_CONFIRM_TEXT = 'VERITABANI_YEDEGI_AL';

// ── Shell fonksiyon kullanılabilirlik kontrolü ─────────────
function _bkp_shell_ok(): bool {
    if (!function_exists('shell_exec')) return false;
    $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('shell_exec', $dis, true);
}
function _bkp_popen_ok(): bool {
    if (!function_exists('popen')) return false;
    $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('popen', $dis, true);
}

// ── mysqldump binary yolu ──────────────────────────────────
function _bkp_find_mysqldump(): string {
    if (!_bkp_shell_ok()) return '';
    foreach (['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/opt/mysql/bin/mysqldump'] as $p) {
        if (is_executable($p)) return $p;
    }
    $found = trim((string)@shell_exec('which mysqldump 2>/dev/null'));
    return (str_contains($found, 'mysqldump') && is_executable($found)) ? $found : '';
}

// ── POST: Backup işlemi ─────────────────────────────────────
$page_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $err = [];
    if (empty($_POST['hassas_onay'])) {
        $err[] = '"Hassas veri içerdiğini biliyorum" onayı işaretlenmedi.';
    }
    $given_text = trim($_POST['onay_metni'] ?? '');
    if ($given_text !== BACKUP_CONFIRM_TEXT) {
        $err[] = 'Onay metni hatalı. Tam olarak <strong>' . h(BACKUP_CONFIRM_TEXT) . '</strong> yazılmalı.';
    }

    if ($err) {
        $page_error = implode('<br>', $err);
    } else {
        // Output buffer'ları temizle
        while (ob_get_level()) { ob_end_clean(); }

        $ts       = date('Ymd_His');
        $method   = 'unknown';
        $filename = '';

        // ─────────────────────────────────────────────────────
        // Yöntem 1: mysqldump
        // ─────────────────────────────────────────────────────
        $mysqldump = _bkp_find_mysqldump();
        $use_dump  = $mysqldump !== '' && _bkp_popen_ok();

        if ($use_dump) {
            // Şifreyi komut satırına koymamak için geçici options dosyası
            $opts_file = (string)tempnam(sys_get_temp_dir(), 'asya_dbo_');
            file_put_contents($opts_file,
                "[client]\n"
                . 'user='     . DB_USER . "\n"
                . 'password=' . DB_PASS . "\n"
                . 'host='     . DB_HOST . "\n"
            );
            @chmod($opts_file, 0600);

            $has_gzip = _bkp_shell_ok()
                && str_contains((string)@shell_exec('which gzip 2>/dev/null'), 'gzip');

            $base_cmd = escapeshellarg($mysqldump)
                . ' --defaults-extra-file=' . escapeshellarg($opts_file)
                . ' --single-transaction --routines --skip-lock-tables --hex-blob'
                . ' ' . escapeshellarg(DB_NAME);

            if ($has_gzip) {
                $filename     = 'db_backup_' . $ts . '.sql.gz';
                $content_type = 'application/gzip';
                $cmd          = $base_cmd . ' | gzip';
                $method       = 'mysqldump+gzip';
            } else {
                $filename     = 'db_backup_' . $ts . '.sql';
                $content_type = 'application/octet-stream';
                $cmd          = $base_cmd;
                $method       = 'mysqldump';
            }

            // Audit — header göndermeden önce
            audit_log_event('database_backup_download', 'system', null, null, [
                'method'     => $method,
                'filename'   => $filename,
                'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
            ]);

            header('Content-Type: ' . $content_type);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');

            $proc = popen($cmd, 'r');
            if ($proc !== false) {
                while (!feof($proc)) {
                    echo fread($proc, 65536);
                    @flush();
                }
                pclose($proc);
            }
            @unlink($opts_file);
            exit;
        }

        // ─────────────────────────────────────────────────────
        // Yöntem 2: PDO fallback
        // ─────────────────────────────────────────────────────
        $method = 'pdo_fallback';
        $pdo    = db();
        $sql    = '';
        $sql   .= "-- Asya Fresh DB Backup (PDO Fallback)\n";
        $sql   .= "-- Tarih: " . date('Y-m-d H:i:s') . "\n";
        $sql   .= "-- Veritabanı: " . DB_NAME . "\n\n";
        $sql   .= "SET FOREIGN_KEY_CHECKS=0;\n";
        $sql   .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
        $sql   .= "SET NAMES utf8mb4;\n\n";

        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $safe  = str_replace('`', '``', (string)$table);
            $create_row = $pdo->query("SHOW CREATE TABLE `{$safe}`")->fetch(PDO::FETCH_ASSOC);
            $create_sql = $create_row['Create Table'] ?? $create_row[array_key_last($create_row)] ?? '';

            $sql .= "-- ---\n-- Tablo: `{$safe}`\n-- ---\n";
            $sql .= "DROP TABLE IF EXISTS `{$safe}`;\n";
            $sql .= $create_sql . ";\n\n";

            $stmt = $pdo->query("SELECT * FROM `{$safe}`");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cols = implode('`, `', array_map(
                    fn($c) => str_replace('`', '``', $c),
                    array_keys($row)
                ));
                $vals = array_map(function($v) use ($pdo) {
                    if ($v === null) return 'NULL';
                    return $pdo->quote((string)$v);
                }, array_values($row));
                $sql .= "INSERT INTO `{$safe}` (`{$cols}`) VALUES (" . implode(', ', $vals) . ");\n";
            }
            $sql .= "\n";
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        $has_gz   = function_exists('gzencode');
        $method  .= $has_gz ? '+gzencode' : '';
        if ($has_gz) {
            $filename     = 'db_backup_' . $ts . '.sql.gz';
            $content_type = 'application/gzip';
            $output       = (string)gzencode($sql, 6);
        } else {
            $filename     = 'db_backup_' . $ts . '.sql';
            $content_type = 'application/octet-stream';
            $output       = $sql;
        }

        // Audit — header göndermeden önce
        audit_log_event('database_backup_download', 'system', null, null, [
            'method'     => $method,
            'filename'   => $filename,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        ]);

        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($output));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $output;
        exit;
    }
}

// ── GET / form hatalıysa — Formu göster ────────────────────
render_header('DB Yedek İndir (Geçici Araç)');
?>
<style>
.bkp-danger-banner {
    background: #b91c1c; color: #fff; font-size: 1rem; font-weight: 700;
    padding: 14px 18px; border-radius: 6px; margin-bottom: 18px;
    text-align: center; letter-spacing: .03em;
}
.bkp-warn-box {
    background: #fef9c3; border: 1.5px solid #ca8a04; border-radius: 6px;
    padding: 12px 16px; margin-bottom: 18px; font-size: .88rem; color: #78350f;
}
.bkp-warn-box ul { margin: 8px 0 0 18px; }
.bkp-form-card { max-width: 560px; margin: 0 auto; }
.bkp-confirm-input {
    font-family: monospace; font-size: 1rem; letter-spacing: .04em;
    background: #fafafa; border: 1.5px solid #d1d5db;
}
.bkp-confirm-input:focus { border-color: #dc2626; outline: none; box-shadow: 0 0 0 2px rgba(220,38,38,.2); }
</style>

<div class="container" style="padding-top:16px">

    <div class="bkp-danger-banner">
        ⚠️ BU ARAÇ GEÇİCİDİR — Yedek alındıktan sonra admin_db_backup_download.php kaldırılmalıdır.
    </div>

    <div class="page-head" style="margin-bottom:14px">
        <div>
            <h2 class="page-title">🗄️ Veritabanı Yedeği İndir</h2>
            <p style="color:var(--text-muted);font-size:.85rem;margin-top:2px">
                Uygulama DB bağlantısı kullanılarak SQL yedeği oluşturulup indirilir.
            </p>
        </div>
        <a href="malzeme_stok.php" class="btn btn-sm btn-secondary">← Ana Stoka Dön</a>
    </div>

    <div class="bkp-warn-box">
        <strong>⚠ Dikkat — Hassas Veri</strong>
        <ul>
            <li>Bu SQL yedeği kullanıcı, stok, işlem ve ticari veri içerir.</li>
            <li>İndirdikten sonra güvenli bir yerde saklayın.</li>
            <li>WhatsApp veya e-posta ile paylaşmayın.</li>
            <li>İşlem bittikten sonra bu backup aracı kaldırılmalıdır.</li>
        </ul>
    </div>

    <?php if ($page_error !== ''): ?>
    <div class="flash flash-error" style="margin-bottom:14px">
        ❌ <?= $page_error ?>
    </div>
    <?php endif; ?>

    <div class="card bkp-form-card">
        <form method="post" action="admin_db_backup_download.php" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

            <div class="form-group" style="margin-bottom:16px">
                <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:.95rem">
                    <input type="checkbox" name="hassas_onay" value="1" style="margin-top:3px;width:18px;height:18px;flex-shrink:0">
                    <span>Bu yedeğin hassas veri içerdiğini biliyorum ve indirme gerekçem meşrudur.</span>
                </label>
            </div>

            <div class="form-group" style="margin-bottom:20px">
                <label class="form-label">
                    Onay — Aşağıya tam olarak şunu yazın:
                    <code style="background:#fee2e2;padding:2px 6px;border-radius:3px;font-size:.95em"><?= h(BACKUP_CONFIRM_TEXT) ?></code>
                </label>
                <input type="text" name="onay_metni" class="form-control bkp-confirm-input"
                       placeholder="<?= h(BACKUP_CONFIRM_TEXT) ?>" autocomplete="off" spellcheck="false">
            </div>

            <button type="submit" class="btn btn-danger" style="width:100%;min-height:44px;font-size:1rem">
                🗄️ SQL Yedeğini İndir
            </button>
        </form>
    </div>

    <p style="text-align:center;margin-top:18px;color:#9ca3af;font-size:.8rem">
        Yedek indirildikten sonra bu sayfa (<strong>admin_db_backup_download.php</strong>) silinmelidir.
    </p>

</div>

<?php render_footer(); ?>
