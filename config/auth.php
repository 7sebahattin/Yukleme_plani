<?php
// =========================================================
// config/auth.php — Kimlik doğrulama altyapısı
// Gereksinim: db.php + helpers.php önceden yüklenmiş olmalı
// =========================================================

declare(strict_types=1);

const SESSION_DURATION_HOURS = 24;
const AUTH_COOKIE_NAME       = 'asya_session';

function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    return false;
}

function auth_cookie_options(): array {
    return [
        'expires'  => time() + SESSION_DURATION_HOURS * 3600,
        'path'     => '/',
        'domain'   => '',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function current_user(): ?array {
    static $cache = false;
    if ($cache !== false) return ($cache === null) ? null : $cache;

    $token = $_COOKIE[AUTH_COOKIE_NAME] ?? '';
    if ($token === '' || strlen($token) !== 64) {
        $cache = null;
        return null;
    }

    try {
        $pdo = db();
        $st  = $pdo->prepare("
            SELECT u.id, u.username, u.email, u.display_name, u.is_active,
                   us.token, us.expires_at
            FROM user_sessions us
            JOIN users u ON u.id = us.user_id
            WHERE us.token = ? AND us.expires_at > NOW()
        ");
        $st->execute([$token]);
        $row = $st->fetch();

        if (!$row || !(bool)$row['is_active']) {
            $cache = null;
            return null;
        }

        // Sliding expiry: expires_at ve last_seen_at güncelle
        $new_expires = date('Y-m-d H:i:s', time() + SESSION_DURATION_HOURS * 3600);
        $pdo->prepare("UPDATE user_sessions SET last_seen_at = NOW(), expires_at = ? WHERE token = ?")
            ->execute([$new_expires, $token]);

        // Cookie süresini de yenile
        setcookie(AUTH_COOKIE_NAME, $token, auth_cookie_options());

        $cache = $row;
        return $cache;
    } catch (PDOException $e) {
        $cache = null;
        return null;
    }
}

function require_login(): array {
    $user = current_user();
    if ($user === null) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . base_url() . 'login.php' . ($next !== '' ? '?next=' . $next : ''));
        exit;
    }
    return $user;
}

function create_session(int $user_id): string {
    $token   = bin2hex(random_bytes(32)); // 64 hex karakter
    $expires = date('Y-m-d H:i:s', time() + SESSION_DURATION_HOURS * 3600);
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua      = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

    db()->prepare("
        INSERT INTO user_sessions (token, user_id, ip, user_agent, expires_at, last_seen_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ")->execute([$token, $user_id, $ip, $ua, $expires]);

    return $token;
}

function destroy_session(string $token): void {
    if ($token === '') return;
    try {
        db()->prepare("DELETE FROM user_sessions WHERE token = ?")->execute([$token]);
    } catch (PDOException $e) {}
}

function login_user(string $username_or_email, string $password): ?array {
    if ($username_or_email === '' || $password === '') return null;

    try {
        $st = db()->prepare("
            SELECT id, username, email, password_hash, display_name, is_active
            FROM users
            WHERE username = ? OR (email != '' AND email = ?)
            LIMIT 1
        ");
        $st->execute([$username_or_email, $username_or_email]);
        $user = $st->fetch();

        if (!$user) return null;
        if (!(bool)$user['is_active']) return null;
        if (!password_verify($password, $user['password_hash'])) return null;

        db()->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")
            ->execute([$user['id']]);

        return $user;
    } catch (PDOException $e) {
        return null;
    }
}

function logout_user(): void {
    $token = $_COOKIE[AUTH_COOKIE_NAME] ?? '';
    if ($token !== '') {
        destroy_session($token);
    }
    setcookie(AUTH_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => '',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    // Static cache temizle
    // (Bir sonraki current_user() çağrısında session bulunamayacak)
}
