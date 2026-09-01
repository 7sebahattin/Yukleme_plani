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
            min-height: 100dvh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        body {
            background: #eef1f6;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            box-sizing: border-box;
        }
        html[data-theme="dark"] body { background: #0b1220; }

        /* Kart — gradient "telefon ekranı" görünümü: üstte logo/marka,
           ortada hap (pill) girdiler, altta beyaz oturma kağıdı üstünde
           yüzen hap buton. Renkler projenin mevcut mavisi (#1d6cf0). */
        .lc {
            position: relative;
            overflow: hidden;
            width: 100%;
            max-width: 400px;
            min-height: 640px;
            border-radius: 32px;
            box-shadow: 0 20px 50px rgba(15,23,42,.22);
            background: linear-gradient(160deg, #123a9c 0%, #1d6cf0 55%, #4a92ff 100%);
            display: flex;
            flex-direction: column;
        }
        .lc-decor {
            position: absolute;
            border-radius: 50%;
            background: rgba(255,255,255,.10);
            filter: blur(2px);
            pointer-events: none;
        }
        .lc-decor-a { width: 220px; height: 220px; top: -90px; right: -70px; }
        .lc-decor-b { width: 160px; height: 160px; bottom: 170px; left: -60px; background: rgba(255,255,255,.08); }

        .lc-brand {
            position: relative;
            text-align: center;
            padding: 52px 24px 6px;
        }
        .lc-logo {
            width: 60px; height: 60px;
            margin: 0 auto 14px;
            border-radius: 18px;
            background: rgba(255,255,255,.16);
            border: 1px solid rgba(255,255,255,.35);
            display: flex; align-items: center; justify-content: center;
        }
        .lc-logo svg { width: 28px; height: 28px; stroke: #fff; }
        .lc-brand-title {
            font-size: 1.4rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.3px;
        }
        .lc-brand-sub {
            margin-top: 2px;
            color: rgba(255,255,255,.75);
            font-size: .85rem;
        }

        .lc-body {
            position: relative;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 14px;
            padding: 28px 28px 0;
        }
        .lc-error {
            background: rgba(255,255,255,.16);
            border: 1px solid rgba(255,255,255,.4);
            color: #fff;
            padding: 10px 16px;
            border-radius: 14px;
            font-size: .85rem;
            text-align: center;
        }
        .lc-pill {
            border-radius: 999px;
            border: 1.5px solid rgba(255,255,255,.45);
            background: rgba(255,255,255,.12);
            padding: 0 20px;
            height: 50px;
            display: flex;
            align-items: center;
        }
        .lc-pill:focus-within {
            border-color: rgba(255,255,255,.85);
            background: rgba(255,255,255,.18);
        }
        .lc-pill input {
            width: 100%;
            border: none;
            outline: none;
            background: transparent;
            color: #fff;
            font-size: 16px;
        }
        .lc-pill input::placeholder { color: rgba(255,255,255,.75); }
        .lc-sr-only {
            position: absolute; width: 1px; height: 1px;
            padding: 0; margin: -1px; overflow: hidden;
            clip: rect(0,0,0,0); white-space: nowrap; border: 0;
        }

        .lc-sheet {
            position: relative;
            margin-top: auto;
            background: #fff;
            border-radius: 32px 32px 0 0;
            padding: 34px 28px calc(26px + env(safe-area-inset-bottom, 0px));
            display: flex;
            justify-content: center;
            box-shadow: 0 -6px 20px rgba(15,23,42,.10);
        }
        .lc-cta {
            width: 100%;
            max-width: 260px;
            margin-top: -56px;
            padding: 15px;
            background: #1d6cf0;
            color: #fff;
            font-size: 1rem;
            font-weight: 700;
            border: none;
            border-radius: 999px;
            cursor: pointer;
            box-shadow: 0 12px 24px rgba(29,108,240,.35);
            transition: background .15s;
        }
        .lc-cta:hover { background: #1558c7; }
        .lc-cta:active { background: #1047aa; }

        @media (max-width: 480px) {
            body { padding: 0; }
            /* min-height değil height: min-height + flex çocukları, gerçek
               cihazda yazı tipi/emniyet alanı farkıyla 100dvh'yi aşıp
               "Giriş Yap" butonunu ekran dışına itebiliyordu. Sabit height +
               overflow-y:auto ile içerik hiçbir zaman erişilemez olmuyor —
               sığmazsa kart kendi içinde kayar, buton her zaman ulaşılabilir. */
            .lc {
                height: 100dvh;
                min-height: 100dvh;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                border-radius: 0;
                box-shadow: none;
            }
            /* Daha az dikey boşluk — kısa ekranlarda butonun kırpılma riskini azaltır */
            .lc-brand { padding: 28px 24px 4px; }
            .lc-logo { width: 50px; height: 50px; margin-bottom: 10px; }
            .lc-logo svg { width: 24px; height: 24px; }
            .lc-body { padding-top: 20px; gap: 12px; }
            .lc-pill { height: 46px; }
            .lc-sheet { border-radius: 0; padding-top: 22px; }
            .lc-cta { margin-top: -46px; }
        }
    </style>
</head>
<body>
<form class="lc" method="post" action="login.php" autocomplete="on">
    <div class="lc-decor lc-decor-a"></div>
    <div class="lc-decor lc-decor-b"></div>

    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <?php if ($next !== ''): ?>
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <?php endif; ?>

    <div class="lc-brand">
        <div class="lc-logo">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="1" y="3" width="15" height="13"></rect>
                <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
                <circle cx="5.5" cy="18.5" r="2.5"></circle>
                <circle cx="18.5" cy="18.5" r="2.5"></circle>
            </svg>
        </div>
        <div class="lc-brand-title">Asya Fresh</div>
        <div class="lc-brand-sub">Yükleme Planı</div>
    </div>

    <div class="lc-body">
        <?php if ($error !== ''): ?>
        <div class="lc-error"><?= h($error) ?></div>
        <?php endif; ?>

        <div class="lc-pill">
            <label class="lc-sr-only" for="username">Kullanıcı Adı veya E-posta</label>
            <input type="text"
                   id="username"
                   name="username"
                   placeholder="Kullanıcı Adı"
                   value="<?= h($_POST['username'] ?? '') ?>"
                   autocomplete="username"
                   autofocus
                   required
                   spellcheck="false"
                   autocapitalize="none">
        </div>
        <div class="lc-pill">
            <label class="lc-sr-only" for="password">Şifre</label>
            <input type="password"
                   id="password"
                   name="password"
                   placeholder="Şifre"
                   autocomplete="current-password"
                   required>
        </div>
    </div>

    <div class="lc-sheet">
        <button type="submit" class="lc-cta">Giriş Yap</button>
    </div>
</form>
</body>
</html>
