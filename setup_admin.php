<?php
// =========================================================
// setup_admin.php — Tek seferlik ilk admin kurulumu
// KULLANDIKTAN SONRA HEMEN SİLİN!
// =========================================================
declare(strict_types=1);

// Sadece kullanıcı yoksa çalışır
require_once __DIR__ . '/config/db.php';

$pdo    = db();
$count  = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$done   = false;
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    if ($count > 0) {
        $error = 'Sistemde zaten kullanıcı var. Bu dosyayı silin.';
    } elseif (strlen($username) < 3 || !preg_match('/^[a-zA-Z0-9_.\-]+$/', $username)) {
        $error = 'Kullanıcı adı en az 3 karakter, sadece harf/rakam/_.- içermeli.';
    } elseif (strlen($password) < 8) {
        $error = 'Şifre en az 8 karakter olmalıdır.';
    } elseif ($password !== $password2) {
        $error = 'Şifreler eşleşmiyor.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("INSERT INTO users (username, password_hash, display_name, is_active, created_at) VALUES (?, ?, ?, 1, NOW())")
            ->execute([$username, $hash, $username]);
        $uid = (int)$pdo->lastInsertId();

        // Admin rolü ata
        try {
            $rid = $pdo->query("SELECT id FROM roles WHERE slug='admin' LIMIT 1")->fetchColumn();
            if ($rid) {
                $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)")
                    ->execute([$uid, (int)$rid]);
            }
        } catch (Exception $e) {}

        $done = true;
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>İlk Admin Kurulumu</title>
<style>
body{font-family:system-ui,sans-serif;background:#f4f6fa;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.box{background:#fff;border-radius:10px;padding:32px 28px;width:100%;max-width:380px;box-shadow:0 4px 20px rgba(0,0,0,.08)}
h1{margin:0 0 6px;font-size:1.2rem;color:#1c2230}
p.sub{margin:0 0 24px;color:#6b7385;font-size:.88rem}
label{display:block;font-size:.82rem;color:#6b7385;margin-bottom:4px;font-weight:500}
input{width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:1rem;margin-bottom:14px}
button{width:100%;padding:10px;background:#1d6cf0;color:#fff;border:none;border-radius:6px;font-size:1rem;cursor:pointer;font-weight:600}
button:hover{background:#1655c4}
.err{background:#fdecec;color:#b91c1c;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:.88rem}
.ok{background:#e7f6ee;color:#065f46;border-radius:6px;padding:16px 14px;font-size:.95rem}
.warn{background:#fff8e1;border:1px solid #ffc107;color:#856404;border-radius:6px;padding:10px 14px;font-size:.82rem;margin-top:16px}
a.login-btn{display:block;margin-top:14px;text-align:center;background:#1d6cf0;color:#fff;padding:10px;border-radius:6px;text-decoration:none;font-weight:600}
</style>
</head>
<body>
<div class="box">
<?php if ($count > 0 && !$done): ?>
    <div class="err">Sistemde zaten <?= $count ?> kullanıcı kayıtlı.<br>Bu dosyayı sunucudan silin.</div>
<?php elseif ($done): ?>
    <div class="ok">
        <strong>✓ Admin kullanıcısı oluşturuldu!</strong><br><br>
        Artık giriş yapabilirsiniz.
    </div>
    <a href="login.php" class="login-btn">Giriş Yap →</a>
    <div class="warn">⚠️ <strong>Bu dosyayı (setup_admin.php) sunucudan hemen silin!</strong></div>
<?php else: ?>
    <h1>İlk Admin Kurulumu</h1>
    <p class="sub">Sisteme ilk admin kullanıcısını oluşturun.</p>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
        <label>Kullanıcı Adı</label>
        <input type="text" name="username" value="<?= htmlspecialchars($_POST['username'] ?? 'admin') ?>" autocomplete="off">
        <label>Şifre (min. 8 karakter)</label>
        <input type="password" name="password" autocomplete="new-password">
        <label>Şifre Tekrar</label>
        <input type="password" name="password2" autocomplete="new-password">
        <button type="submit">Admin Oluştur</button>
    </form>
    <div class="warn">⚠️ Kullanıcı oluşturduktan sonra bu dosyayı silin.</div>
<?php endif; ?>
</div>
</body>
</html>
