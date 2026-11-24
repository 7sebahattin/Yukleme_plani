<?php
// =========================================================
// depo_sec.php — Aktif depo seçimi (giriş sonrası 2. pencere)
// Tüm veri (yükleme, çıkma, kantar, stok, rapor) seçilen
// depoya göre listelenir. Değişiklik her ekrandan yapılabilir.
// =========================================================

declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

// Giriş şart — ama enforce_active_depot bu sayfayı muaf tutar (döngü yok)
$user = require_login();

$error   = '';
$next    = trim($_GET['next'] ?? $_POST['next'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    // v575 (sahip kararı): depo ekleme BURADAN kalktı — yalnız Yönetim › Depo Tanımları (yalnız yönetici).
    if (isset($_POST['depo'])) {
        $sec = trim($_POST['depo'] ?? '');
        if (!in_array($sec, depot_options(), true)) {
            $error = 'Geçersiz depo seçimi.';
        } else {
            $onceki = trim((string)($_COOKIE[DEPOT_COOKIE_NAME] ?? ''));
            set_active_depot($sec);
            if ($onceki !== $sec) {
                audit_log_event('depot_switch', 'auth', null,
                    $onceki !== '' ? ['depo' => $onceki] : null, ['depo' => $sec]);
            }
            // Güvenli yönlendirme: yalnız site içi yol
            if ($next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//')) {
                header('Location: ' . $next);
            } else {
                header('Location: index.php');
            }
            exit;
        }
    }
}

$depolar  = depot_options();
$aktif    = active_depot();
$csrf     = csrf_token();
$css_v    = filemtime(__DIR__ . '/assets/style.css');
$kullanici = $user['display_name'] ?: $user['username'];
?><!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="csrf-token" content="<?= h($csrf) ?>">
    <meta name="theme-color" content="#1d6cf0">
    <title>Depo Seçimi · Asya Fresh</title>
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
    /* Gerçek görünür yükseklik (login.php ile aynı) — mobilde kart tam ekran, taşarsa kaydırılır */
    (function () {
        function ayarla() { document.documentElement.style.setProperty('--app-h', window.innerHeight + 'px'); }
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
<div class="lc">
    <div class="lc-decor lc-decor-a"></div>
    <div class="lc-decor lc-decor-b"></div>

    <div class="lc-brand">
        <div class="lc-logo">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 21V9l9-6 9 6v12"></path>
                <path d="M9 21v-7h6v7"></path>
                <path d="M3 21h18"></path>
            </svg>
        </div>
        <div class="lc-brand-title">Asya Fresh</div>
        <div class="lc-brand-sub">Çalışacağınız depoyu seçin</div>
    </div>

    <div class="lc-sec">
        <p class="lc-selam">Hoş geldiniz, <?= h($kullanici) ?></p>

        <?php if ($error !== ''): ?>
        <div class="lc-error" role="alert"><?= h($error) ?></div>
        <?php endif; ?>
        <?php render_flash(); ?>

        <?php if (empty($depolar)): ?>
            <div class="lc-bos">
                Henüz size tanımlı bir depo yok.<br>
                <?php if (is_admin()): ?>
                    <a href="depo_tanimlari.php">Depo Tanımları</a> sayfasından depo ekleyin.
                <?php else: ?>
                    Lütfen yöneticinizden depo tanımlamasını isteyin.
                <?php endif; ?>
            </div>
        <?php else: ?>
        <form method="post" action="depo_sec.php" class="lc-sec" style="padding:0">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <?php if ($next !== ''): ?><input type="hidden" name="next" value="<?= h($next) ?>"><?php endif; ?>
            <?php foreach ($depolar as $d): $on = ($aktif === $d); ?>
            <button type="submit" name="depo" value="<?= h($d) ?>" class="lc-depo<?= $on ? ' lc-depo-aktif' : '' ?>"<?= $on ? ' aria-current="true"' : '' ?>>
                <span class="lc-depo-nokta" style="background:<?= h(depot_color($d)) ?>"></span>
                <span class="lc-depo-isim"><?= h($d) ?></span>
                <?php if ($on): ?><span class="lc-depo-rozet">Aktif</span><?php endif; ?>
            </button>
            <?php endforeach; ?>
        </form>
        <?php endif; ?>
    </div>

    <div class="lc-alt"><a href="logout.php">Çıkış Yap</a></div>
</div>
</body>
</html>
