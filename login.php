<?php
// =========================================================
// login.php — Kullanıcı girişi
// =========================================================

declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

// Zaten giriş yapılmışsa ana sayfaya yönlendir
if (current_user() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';
$next  = trim($_GET['next'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $next     = trim($_POST['next'] ?? '');

    $user = login_user($username, $password);

    if ($user === null) {
        audit_log_event('login_failed', 'auth', null, null, ['username' => $username]);
        $error = 'Kullanıcı adı veya şifre hatalı.';
    } else {
        $token = create_session((int)$user['id']);
        setcookie(AUTH_COOKIE_NAME, $token, auth_cookie_options());
        audit_log_event('login_success', 'auth', null, null, null, (int)$user['id']);

        // Girişten sonra 2. pencere: depo seçimi (zorunlu tek depo).
        // Güvenli yönlendirme hedefi depo_sec sonrasına taşınır (next paramı).
        if ($next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//')) {
            header('Location: depo_sec.php?next=' . urlencode($next));
        } else {
            header('Location: depo_sec.php');
        }
        exit;
    }
}

$csrf = csrf_token();
$css_v = filemtime(__DIR__ . '/assets/style.css');
?><!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="csrf-token" content="<?= h($csrf) ?>">
    <meta name="theme-color" content="#1d6cf0">
    <title>Giriş · Asya Fresh</title>
    <script>
    /* Tema — render_header kullanmayan sayfa: aynı anahtar (asya_tema) uygulanır */
    (function () {
        function uygula() {
            var t; try { t = localStorage.getItem('asya_tema') || 'sistem'; } catch (e) { t = 'sistem'; }
            var koyu = t === 'koyu' || (t === 'sistem' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.setAttribute('data-theme', koyu ? 'dark' : 'light');
        }
        uygula();
        try {
            matchMedia('(prefers-color-scheme: dark)').addEventListener('change', uygula);
            window.addEventListener('storage', function (e) { if (e.key === 'asya_tema') uygula(); });
        } catch (e) {}
    })();
    </script>
    <link rel="stylesheet" href="assets/style.css?v=<?= $css_v ?>">
    <style>
        html, body {
            margin: 0; padding: 0;
            min-height: 100vh;
            background: #f0f4f8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        .lc {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 24px rgba(0,0,0,.10);
            padding: 40px 36px 36px;
            width: 100%;
            max-width: 360px;
            box-sizing: border-box;
        }
        .lc-brand {
            text-align: center;
            margin-bottom: 4px;
        }
        .lc-brand span {
            font-size: 1.4rem;
            font-weight: 800;
            color: #1d6cf0;
            letter-spacing: -0.5px;
        }
        .lc-subtitle {
            text-align: center;
            color: #64748b;
            font-size: .85rem;
            margin-bottom: 28px;
        }
        .lc-field { margin-bottom: 16px; }
        .lc-field label {
            display: block;
            font-size: .82rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 6px;
        }
        .lc-field input {
            width: 100%;
            box-sizing: border-box;
            padding: 11px 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            color: #1e293b;
            outline: none;
            transition: border-color .15s;
            background: #f8fafc;
        }
        .lc-field input:focus {
            border-color: #1d6cf0;
            background: #fff;
        }
        .lc-btn {
            width: 100%;
            padding: 12px;
            background: #1d6cf0;
            color: #fff;
            font-size: 1rem;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            margin-top: 6px;
            transition: background .15s;
        }
        .lc-btn:hover { background: #1558c7; }
        .lc-btn:active { background: #1047aa; }
        .lc-error {
            background: #fef2f2;
            border: 1px solid #fca5a5;
            color: #b91c1c;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: .88rem;
            margin-bottom: 18px;
        }
        @media (max-width: 400px) {
            .lc { padding: 32px 20px 28px; }
        }
    </style>
</head>
<body>
<div class="lc">
    <div class="lc-brand"><span>Asya Fresh</span></div>
    <div class="lc-subtitle">Sisteme Giriş</div>

    <?php if ($error !== ''): ?>
    <div class="lc-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="login.php" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <?php if ($next !== ''): ?>
        <input type="hidden" name="next" value="<?= h($next) ?>">
        <?php endif; ?>

        <div class="lc-field">
            <label for="username">Kullanıcı Adı veya E-posta</label>
            <input type="text"
                   id="username"
                   name="username"
                   value="<?= h($_POST['username'] ?? '') ?>"
                   autocomplete="username"
                   autofocus
                   required
                   spellcheck="false"
                   autocapitalize="none">
        </div>
        <div class="lc-field">
            <label for="password">Şifre</label>
            <input type="password"
                   id="password"
                   name="password"
                   autocomplete="current-password"
                   required>
        </div>
        <button type="submit" class="lc-btn">Giriş Yap</button>
    </form>
</div>
</body>
</html>
