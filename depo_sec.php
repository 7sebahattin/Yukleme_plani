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
$is_admin_or_def = is_admin() || can('defs.write');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    // Hızlı depo ekleme (yalnız admin / defs.write) — yeni depo kurulumu için
    if (isset($_POST['yeni_depo']) && $is_admin_or_def) {
        $yeni = normalize_depo(trim($_POST['yeni_depo'] ?? ''));
        if ($yeni === '' || mb_strlen($yeni) > 100) {
            $error = 'Geçerli bir depo adı girin (en fazla 100 karakter).';
        } else {
            ensure_definition('depo', $yeni);
            audit_log_event('create', 'definitions', null, null, ['type' => 'depo', 'name' => $yeni]);
            set_flash('success', 'Depo eklendi: ' . $yeni);
            header('Location: depo_sec.php' . ($next !== '' ? '?next=' . urlencode($next) : ''));
            exit;
        }
    } elseif (isset($_POST['depo'])) {
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
        .dsc {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 24px rgba(0,0,0,.10);
            padding: 36px 32px 32px;
            width: 100%;
            max-width: 420px;
            box-sizing: border-box;
            margin: 16px;
        }
        .dsc-brand { text-align: center; margin-bottom: 4px; }
        .dsc-brand span { font-size: 1.3rem; font-weight: 800; color: #1d6cf0; letter-spacing: -0.5px; }
        .dsc-subtitle { text-align: center; color: #64748b; font-size: .85rem; margin-bottom: 6px; }
        .dsc-user { text-align: center; color: #94a3b8; font-size: .8rem; margin-bottom: 22px; }
        .dsc-error {
            background: #fef2f2; border: 1px solid #fca5a5; color: #b91c1c;
            padding: 10px 14px; border-radius: 8px; font-size: .88rem; margin-bottom: 16px;
        }
        .dsc-grid { display: flex; flex-direction: column; gap: 10px; }
        .dsc-depo {
            display: flex; align-items: center; gap: 12px;
            width: 100%; box-sizing: border-box;
            padding: 14px 16px;
            border: 1.5px solid #e2e8f0; border-radius: 10px;
            background: #f8fafc; cursor: pointer;
            font-size: 1rem; font-weight: 600; color: #1e293b;
            text-align: left; transition: border-color .15s, background .15s;
        }
        .dsc-depo:hover { border-color: #1d6cf0; background: #fff; }
        .dsc-depo.aktif { border-color: #1d6cf0; background: #eff6ff; }
        .dsc-depo-ikon { font-size: 1.3rem; }
        .dsc-depo-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; box-shadow: 0 0 0 1px rgba(0,0,0,.08); }
        .dsc-depo-ad { flex: 1; }
        .dsc-depo-rozet {
            font-size: .7rem; font-weight: 700; color: #1d6cf0;
            background: #dbeafe; padding: 3px 8px; border-radius: 999px;
        }
        .dsc-bos {
            text-align: center; color: #64748b; font-size: .9rem;
            padding: 18px 10px; background: #f8fafc; border-radius: 10px;
        }
        .dsc-yeni { margin-top: 18px; padding-top: 16px; border-top: 1px solid #e2e8f0; }
        .dsc-yeni-baslik { font-size: .8rem; font-weight: 700; color: #475569; margin-bottom: 8px; }
        .dsc-yeni-satir { display: flex; gap: 8px; }
        .dsc-yeni-satir input {
            flex: 1; box-sizing: border-box; padding: 10px 12px;
            border: 1.5px solid #e2e8f0; border-radius: 8px;
            font-size: 16px; background: #f8fafc; outline: none;
        }
        .dsc-yeni-satir input:focus { border-color: #1d6cf0; background: #fff; }
        .dsc-yeni-satir button {
            padding: 10px 16px; background: #1d6cf0; color: #fff;
            font-weight: 600; border: none; border-radius: 8px; cursor: pointer;
        }
        .dsc-cikis { text-align: center; margin-top: 18px; }
        .dsc-cikis a { color: #94a3b8; font-size: .82rem; text-decoration: none; }
        .dsc-cikis a:hover { color: #64748b; }
        @media (max-width: 400px) { .dsc { padding: 28px 18px 24px; } }
    </style>
</head>
<body>
<div class="dsc">
    <div class="dsc-brand"><span>Asya Fresh</span></div>
    <div class="dsc-subtitle">Çalışacağınız depoyu seçin</div>
    <div class="dsc-user">👤 <?= h($kullanici) ?> — tüm veriler seçilen depoya göre listelenir</div>

    <?php if ($error !== ''): ?>
    <div class="dsc-error"><?= h($error) ?></div>
    <?php endif; ?>
    <?php render_flash(); ?>

    <?php if (empty($depolar)): ?>
        <div class="dsc-bos">
            Henüz tanımlı depo yok.
            <?php if (!$is_admin_or_def): ?><br>Lütfen yöneticinizden depo tanımlamasını isteyin.<?php endif; ?>
        </div>
    <?php else: ?>
        <form method="post" action="depo_sec.php" class="dsc-grid">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <?php if ($next !== ''): ?><input type="hidden" name="next" value="<?= h($next) ?>"><?php endif; ?>
            <?php foreach ($depolar as $d): ?>
            <button type="submit" name="depo" value="<?= h($d) ?>"
                    class="dsc-depo<?= $aktif === $d ? ' aktif' : '' ?>"
                    style="border-left:4px solid <?= h(depot_color($d)) ?>">
                <span class="dsc-depo-ikon">🏭</span>
                <span class="dsc-depo-dot" style="background:<?= h(depot_color($d)) ?>"></span>
                <span class="dsc-depo-ad"><?= h($d) ?></span>
                <?php if ($aktif === $d): ?><span class="dsc-depo-rozet">Aktif</span><?php endif; ?>
            </button>
            <?php endforeach; ?>
        </form>
    <?php endif; ?>

    <?php if ($is_admin_or_def): ?>
    <div class="dsc-yeni">
        <div class="dsc-yeni-baslik">➕ Yeni Depo Ekle</div>
        <form method="post" action="depo_sec.php" class="dsc-yeni-satir">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <?php if ($next !== ''): ?><input type="hidden" name="next" value="<?= h($next) ?>"><?php endif; ?>
            <input type="text" name="yeni_depo" placeholder="Örn: KARAMAN" maxlength="100" required>
            <button type="submit">Ekle</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="dsc-cikis"><a href="logout.php">Çıkış Yap</a></div>
</div>
</body>
</html>
