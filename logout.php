<?php
// =========================================================
// logout.php — Çıkış (POST + CSRF korumalı; GET yalnız onay gösterir)
// =========================================================

declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    audit_log_event('logout', 'auth');
    logout_user();
    if (function_exists('ekran_kilit_temizle')) ekran_kilit_temizle();   // v455: çıkıştan sonra kilit çerezi de silinir
    header('Location: login.php');
    exit;
}

if (current_user() === null) {
    header('Location: login.php');
    exit;
}

$csrf  = csrf_token();
$css_v = (int)@filemtime(__DIR__ . '/assets/style.css');
?><!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Çıkış Yap · Asya Fresh</title>
    <link rel="stylesheet" href="assets/style.css?v=<?= $css_v ?>">
    <style>
        body{display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:var(--bg,#f0f4f8);color:var(--text,#1e293b)}
        .lo-card{background:var(--card,#fff);border:1px solid var(--border,#e2e8f0);border-radius:14px;padding:28px 24px;max-width:360px;width:92%;text-align:center;box-shadow:0 8px 24px rgba(15,23,42,.08)}
        .lo-card h1{margin:0 0 8px;font-size:1.2rem}
        .lo-card p{margin:0 0 20px;color:var(--muted,#64748b);font-size:.92rem}
        .lo-act{display:flex;gap:10px;justify-content:center}
    </style>
</head>
<body>
<div class="lo-card">
    <h1>Oturumu Kapat</h1>
    <p>Hesabınızdan çıkış yapmak istiyor musunuz?</p>
    <form method="post" action="logout.php" class="lo-act">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <button type="submit" class="btn btn-primary">Çıkış Yap</button>
        <a href="index.php" class="btn btn-ghost">Vazgeç</a>
    </form>
</div>
</body>
</html>
