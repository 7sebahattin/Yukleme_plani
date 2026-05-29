<?php
// =========================================================
// scripts/create_admin_user.php — İlk admin kullanıcı seed
// SADECE CLI üzerinden çalışır, web'den erişilemez.
//
// Kullanım:
//   php scripts/create_admin_user.php <username> <şifre> [display_name] [email]
//
// Örnek:
//   php scripts/create_admin_user.php admin "GucluSifre123!" "Sistem Yöneticisi" "admin@example.com"
// =========================================================

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Bu script sadece komut satırından (CLI) çalıştırılabilir.\n";
    exit(1);
}

$args = $argv ?? [];
if (count($args) < 3) {
    echo "Kullanım  : php scripts/create_admin_user.php <username> <şifre> [display_name] [email]\n";
    echo "Örnek     : php scripts/create_admin_user.php admin \"GucluSifre123!\" \"Sistem Yöneticisi\" \"admin@example.com\"\n";
    exit(1);
}

$username     = trim($args[1]);
$password     = $args[2];
$display_name = trim($args[3] ?? $username);
$email        = trim($args[4] ?? '');

// Temel doğrulama
if (strlen($username) < 3) {
    echo "Hata: Kullanıcı adı en az 3 karakter olmalıdır.\n";
    exit(1);
}
if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $username)) {
    echo "Hata: Kullanıcı adı yalnızca harf, rakam, _ . - içerebilir.\n";
    exit(1);
}
if (strlen($password) < 8) {
    echo "Hata: Şifre en az 8 karakter olmalıdır.\n";
    exit(1);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "Hata: Geçersiz e-posta adresi: $email\n";
    exit(1);
}

require_once __DIR__ . '/../config/db.php';

$pdo = db();

// Kullanıcı adı çakışma kontrolü
$st = $pdo->prepare("SELECT id FROM users WHERE username = ?");
$st->execute([$username]);
if ($st->fetch()) {
    echo "Bilgi: '$username' kullanıcı adı zaten mevcut. İşlem yapılmadı.\n";
    exit(0);
}

// E-posta çakışma kontrolü (e-posta girilmişse)
if ($email !== '') {
    $st = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $st->execute([$email]);
    if ($st->fetch()) {
        echo "Bilgi: '$email' e-posta adresi zaten kullanımda. İşlem yapılmadı.\n";
        exit(0);
    }
}

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

$pdo->prepare("
    INSERT INTO users (username, email, password_hash, display_name, is_active, created_at)
    VALUES (?, ?, ?, ?, 1, NOW())
")->execute([$username, $email, $hash, $display_name]);

$id = (int)$pdo->lastInsertId();

// Admin rolü ata
$role_label = '(rol tablosu hazır değil — uygulamayı bir kez açın)';
try {
    $admin_role_id = $pdo->query("SELECT id FROM roles WHERE slug='admin' LIMIT 1")->fetchColumn();
    if ($admin_role_id) {
        $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)")
            ->execute([$id, (int)$admin_role_id]);
        $role_label = 'Sistem Yöneticisi (admin)';
    }
} catch (PDOException $re) {
    $role_label = '(atama başarısız: ' . $re->getMessage() . ')';
}

echo "====================================================\n";
echo " Kullanıcı başarıyla oluşturuldu\n";
echo "====================================================\n";
echo " ID            : $id\n";
echo " Kullanıcı adı : $username\n";
echo " Display adı   : $display_name\n";
echo " E-posta       : " . ($email ?: '—') . "\n";
echo " Aktif         : Evet\n";
echo " Rol           : $role_label\n";
echo " Bcrypt cost   : 12\n";
echo "====================================================\n";
echo " Giriş: login.php → '$username' + şifreniz\n";
echo "====================================================\n";
