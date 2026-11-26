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
$mesajlar = [
    'kilit_oturum' => 'Oturumunuz sona erdi, lütfen tekrar giriş yapın.',
    'pin_cikis'    => 'Çok fazla hatalı PIN denemesi nedeniyle oturum kapatıldı.',
];
$bilgi = $mesajlar[(string)($_GET['mesaj'] ?? '')] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $next     = safe_next_url((string)($_POST['next'] ?? ''));
    $ip       = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    $rl = login_rate_limit_check($username, $ip);
    if (!$rl['ok']) {
        http_response_code(429);
        $error = 'Çok fazla hatalı giriş denemesi. ' . (int)$rl['bekle'] . ' saniye sonra tekrar deneyin.';
    } else {
        $user = login_user($username, $password);

        if ($user === null) {
            $failRl = login_rate_limit_record_failure($username, $ip);
            audit_log_event('login_failed', 'auth', null, null, ['username' => $username]);
            if ((int)$failRl['bekle'] > 0) {
                http_response_code(429);
                $error = 'Çok fazla hatalı giriş denemesi. ' . (int)$failRl['bekle'] . ' saniye sonra tekrar deneyin.';
            } else {
                $error = 'Kullanıcı adı veya şifre hatalı.';
            }
        } else {
            login_rate_limit_reset($username, $ip);
            app_session_regenerate();
            $token = create_session((int)$user['id']);
            if (function_exists('ekran_kilit_temizle')) ekran_kilit_temizle($token);   // v454: önceki oturumdan kilit kalıntısı
            if (function_exists('ekran_pin_sayac_sifirla')) { try { ekran_pin_sayac_sifirla(db(), (int)$user['id']); } catch (Throwable $e) {} }   // v455: şifreyle girişte PIN deneme sayacı sıfırlanır
            setcookie(AUTH_COOKIE_NAME, $token, auth_cookie_options());
            audit_log_event('login_success', 'auth', null, null, null, (int)$user['id']);

            // Girişten sonra 2. pencere: depo seçimi (zorunlu tek depo).
            // Güvenli yönlendirme hedefi depo_sec sonrasına taşınır (next paramı).
            if ($next !== '') {
                header('Location: depo_sec.php?next=' . urlencode($next));
            } else {
                header('Location: depo_sec.php');
            }
            exit;
        }
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
    <script>
    /* Gerçek görünür yükseklik — CSS 100dvh bazı Android tarayıcılarında
       (özellikle veri tasarrufu/adres çubuğu davranışı farklı olanlarda)
       gerçekte görünenden daha uzun ölçülüyor; "Giriş Yap" butonu ekranın
       altına, sistem jest çubuğunun arkasına kayıyordu. window.innerHeight
       her zaman GERÇEKTEN görünen yüksekliği verir — --app-h buradan gelir,
       .lc bunu dvh yerine kullanır (dvh yalnız JS çalışmadan önceki ilk
       çizimde yedek). */
    (function () {
        function ayarla() {
            document.documentElement.style.setProperty('--app-h', window.innerHeight + 'px');
        }
        ayarla();
        window.addEventListener('resize', ayarla);
        window.addEventListener('orientationchange', ayarla);
        try { window.visualViewport && window.visualViewport.addEventListener('resize', ayarla); } catch (e) {}
    })();
    </script>
    <link rel="manifest" href="manifest.json?v=<?= (int)@filemtime(__DIR__ . '/manifest.json') ?>">
    <link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
    <link rel="icon" type="image/png" href="assets/icon-192.png">
    <link rel="stylesheet" href="assets/style.css?v=<?= $css_v ?>">
    <link rel="stylesheet" href="assets/giris.css?v=<?= (int)@filemtime(__DIR__ . '/assets/giris.css') ?>">
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
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10z"></path>
                <path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"></path>
            </svg>
        </div>
        <div class="lc-brand-title">Asya Fresh</div>
        <div class="lc-brand-sub">Operasyon Yönetim Sistemi</div>
    </div>

    <div class="lc-body">
        <?php if ($error !== ''): ?>
        <div class="lc-error"><?= h($error) ?></div>
        <?php endif; ?>
        <?php if ($error === '' && $bilgi !== ''): ?>
        <div class="lc-error" role="status"><?= h($bilgi) ?></div>
        <?php endif; ?>

        <div class="lc-pill">
            <label class="lc-sr-only" for="username">Kullanıcı Adı veya E-posta</label>
            <input type="text"
                   id="username"
                   name="username"
                   placeholder="Kullanıcı Adı"
                   value="<?= h($_POST['username'] ?? '') ?>"
                   autocomplete="username"
                   enterkeyhint="next"
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
                   enterkeyhint="go"
                   required>
        </div>

        <button type="submit" class="lc-cta">Giriş Yap</button>
    </div>
</form>
<script>
/* Klavye açılınca tarayıcı yalnız odaklanan alanı görünür kılar; butonu da
   görünür alana getir (klavye animasyonu bitsin diye kısa gecikme). */
(function () {
    var btn = document.querySelector('.lc-cta');
    if (!btn || !btn.scrollIntoView) return;
    document.querySelectorAll('.lc-pill input').forEach(function (inp) {
        inp.addEventListener('focus', function () {
            setTimeout(function () { btn.scrollIntoView({ block: 'nearest' }); }, 350);
        });
    });
})();
</script>
</body>
</html>
