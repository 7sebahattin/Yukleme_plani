<?php
declare(strict_types=1);
// HKS Mobil — ortak layout yardımcıları
// Web'den doğrudan erişim .htaccess ile engellenir.
if (!defined('HKS_MODULE_VERSION')) {
    http_response_code(403); exit('Direct access not allowed.');
}

function hks_mob_h(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hks_mob_start(string $page_title, string $active_tab): void {
    global $op_company_name, $op_env;
    $company = hks_mob_h($op_company_name ?? 'AGRONATURAL');
    $env     = ($op_env ?? 'test') === 'live' ? 'CANLI' : 'TEST';
    $env_cls = ($op_env ?? 'test') === 'live' ? 'live' : 'test';

    $tabs = [
        'anasayfa'    => ['label' => 'Ana Sayfa',   'href' => 'index.php',       'icon' => 'home'],
        'ureticiler'  => ['label' => 'Üretici',     'href' => 'ureticiler.php',  'icon' => 'people'],
        'musteriler'  => ['label' => 'Müşteri',     'href' => 'musteriler.php',  'icon' => 'people'],
        'bildirimler' => ['label' => 'Bildirimler', 'href' => 'bildirimler.php', 'icon' => 'bell'],
        'ayarlar'     => ['label' => 'Ayarlar',     'href' => 'ayarlar.php',     'icon' => 'gear'],
    ];

    $icons = [
        'home'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
        'people' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'bell'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
        'gear'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
    ];
    ?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?= hks_mob_h($page_title) ?> — HKS Mobil</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { -webkit-text-size-adjust: 100%; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f4f6f9; }
html { overflow-x: clip; }
body { background: #f4f6f9; color: #1a2236; min-height: 100dvh; }

/* ── Topbar ─────────────────────────────────────────── */
.mob-topbar {
    position: sticky; top: 0; z-index: 100;
    background: #fff;
    border-bottom: 1.5px solid #e3e8f0;
    display: flex; align-items: center;
    padding: 0 16px;
    height: 56px;
}
.mob-topbar-logo {
    display: flex; align-items: center; gap: 0; flex-shrink: 0;
}
.mob-topbar-logo-circle {
    width: 36px; height: 36px; border-radius: 50%;
    background: linear-gradient(135deg, #1565c0 60%, #42a5f5 100%);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 800; font-size: 13px; letter-spacing: -0.5px;
    flex-shrink: 0;
}
.mob-topbar-title {
    flex: 1; text-align: center;
    font-size: 18px; font-weight: 600; color: #1565c0; letter-spacing: 0.01em;
}
.mob-topbar-env {
    flex-shrink: 0; font-size: 11px; font-weight: 700; letter-spacing: 0.05em;
    padding: 3px 8px; border-radius: 10px;
}
.mob-topbar-env.test { background: #fef3c7; color: #92400e; }
.mob-topbar-env.live { background: #fee2e2; color: #991b1b; }

/* ── Sayfa içeriği ──────────────────────────────────── */
.mob-content {
    padding: 16px 16px calc(76px + env(safe-area-inset-bottom, 0px)) 16px;
    min-height: 100dvh;
}

/* ── Bottom nav ─────────────────────────────────────── */
.mob-bottomnav {
    position: fixed; bottom: 0; left: 0; right: 0; z-index: 500;
    background: #fff;
    border-top: 1.5px solid #e3e8f0;
    display: flex;
    padding-bottom: env(safe-area-inset-bottom, 0px);
}
.mob-bottomnav a {
    flex: 1; display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    gap: 3px; padding: 8px 4px 6px;
    text-decoration: none; color: #9eaabf;
    font-size: 10px; font-weight: 500;
    transition: color 0.15s;
}
.mob-bottomnav a svg { width: 22px; height: 22px; }
.mob-bottomnav a.active { color: #1565c0; }
.mob-bottomnav a:active { opacity: 0.7; }

/* ── Form bileşenleri ───────────────────────────────── */
.mob-card {
    background: #fff; border-radius: 14px;
    box-shadow: 0 1px 4px rgba(21,101,192,.06);
    padding: 16px; margin-bottom: 16px;
}

.mob-input-group { margin-bottom: 10px; }
.mob-input-group:last-child { margin-bottom: 0; }

.mob-input {
    width: 100%;
    border: 1.5px solid #d0d8e4;
    border-radius: 10px;
    padding: 13px 14px;
    font-size: 16px;
    color: #1a2236;
    background: #fff;
    outline: none;
    -webkit-appearance: none;
    appearance: none;
    transition: border-color 0.15s;
}
.mob-input:focus { border-color: #1565c0; }
.mob-input::placeholder { color: #9eaabf; }
.mob-input[disabled], .mob-input[readonly] { background: #f4f6f9; color: #9eaabf; }

.mob-row { display: flex; gap: 8px; }
.mob-row .mob-input-group { flex: 1; }
.mob-row .mob-input-group.w2 { flex: 2; }

/* Checkbox satır */
.mob-check-row {
    display: flex; align-items: center; gap: 10px;
    padding: 6px 0;
}
.mob-check-row input[type=checkbox] {
    width: 20px; height: 20px; accent-color: #1565c0; flex-shrink: 0;
}
.mob-check-row label { font-size: 15px; color: #1a2236; cursor: pointer; }

/* Tarih alanı — floating label efekti */
.mob-date-group { position: relative; flex: 1; }
.mob-date-group label {
    position: absolute; top: -8px; left: 10px;
    background: #fff; padding: 0 4px;
    font-size: 11px; color: #7b8fa8; font-weight: 600;
    pointer-events: none; white-space: nowrap;
}
.mob-date-group .mob-input { padding-top: 16px; padding-bottom: 10px; }

/* ── Tab butonları ──────────────────────────────────── */
.mob-tabs {
    display: flex; gap: 8px; margin-bottom: 16px;
}
.mob-tab-btn {
    flex: 1; padding: 12px 8px;
    border: 1.5px solid #1565c0; border-radius: 10px;
    background: #fff; color: #1565c0;
    font-size: 14px; font-weight: 600;
    cursor: pointer; text-align: center;
    transition: background 0.15s, color 0.15s;
    -webkit-tap-highlight-color: transparent;
}
.mob-tab-btn.active {
    background: #1565c0; color: #fff;
}

/* ── Aksiyon butonları ──────────────────────────────── */
.mob-actions {
    display: flex; gap: 10px; justify-content: center;
    margin-top: 18px; flex-wrap: wrap;
}
.mob-btn {
    flex: 1; min-width: 120px;
    padding: 14px 20px;
    border-radius: 10px; border: 1.5px solid #1565c0;
    font-size: 15px; font-weight: 600;
    cursor: pointer; text-align: center; text-decoration: none;
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    transition: opacity 0.15s, background 0.15s;
    -webkit-tap-highlight-color: transparent;
}
.mob-btn-outline { background: #fff; color: #1565c0; }
.mob-btn-solid   { background: #1565c0; color: #fff; }
.mob-btn[disabled], .mob-btn.disabled {
    opacity: 0.4; cursor: not-allowed; pointer-events: none;
}

/* ── Liste sayfaları ────────────────────────────────── */
.mob-list-empty {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 64px 24px; color: #9eaabf; text-align: center; gap: 14px;
}
.mob-list-empty svg { width: 56px; height: 56px; opacity: 0.45; }
.mob-list-empty p { font-size: 15px; }

.mob-fab {
    position: fixed; bottom: calc(72px + env(safe-area-inset-bottom, 0px) + 8px); right: 20px;
    width: 52px; height: 52px; border-radius: 50%;
    background: #1565c0; color: #fff; border: none;
    font-size: 28px; display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 12px rgba(21,101,192,.35);
    cursor: pointer; z-index: 400; text-decoration: none;
    -webkit-tap-highlight-color: transparent;
}
.mob-fab:active { opacity: 0.8; }

.mob-import-btn {
    display: inline-flex; align-items: center; justify-content: center;
    padding: 13px 24px; border-radius: 10px;
    border: 1.5px solid #1565c0; background: #fff; color: #1565c0;
    font-size: 15px; font-weight: 600; cursor: pointer; text-decoration: none;
    -webkit-tap-highlight-color: transparent;
}
.mob-import-btn[disabled], .mob-import-btn.disabled {
    opacity: 0.4; cursor: not-allowed; pointer-events: none;
}

.mob-bottom-actions {
    position: fixed; bottom: calc(60px + env(safe-area-inset-bottom, 0px) + 8px);
    left: 0; right: 0;
    display: flex; align-items: center; justify-content: center; gap: 16px;
    padding: 0 20px;
    pointer-events: none;
}
.mob-bottom-actions > * { pointer-events: auto; }

/* ── Ayarlar listesi ────────────────────────────────── */
.mob-settings-list { background: #fff; border-radius: 14px; overflow: hidden; box-shadow: 0 1px 4px rgba(21,101,192,.06); }
.mob-settings-item {
    display: flex; align-items: center; gap: 14px;
    padding: 15px 16px;
    border-bottom: 1px solid #eef0f4;
    text-decoration: none; color: #1a2236;
    -webkit-tap-highlight-color: transparent;
}
.mob-settings-item:last-child { border-bottom: none; }
.mob-settings-item:active { background: #f0f5fd; }
.mob-settings-icon {
    width: 34px; height: 34px; border-radius: 8px;
    background: #e8f0fe; color: #1565c0;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.mob-settings-icon svg { width: 18px; height: 18px; }
.mob-settings-label { flex: 1; font-size: 15px; }
.mob-settings-chevron { color: #9eaabf; }
.mob-settings-chevron svg { width: 16px; height: 16px; }

.mob-version { text-align: center; padding: 24px 16px 8px; color: #9eaabf; font-size: 13px; }

/* ── Bildirimler liste ──────────────────────────────── */
.mob-notif-item {
    background: #fff; border-radius: 12px; padding: 14px;
    margin-bottom: 10px; box-shadow: 0 1px 3px rgba(21,101,192,.05);
    text-decoration: none; color: #1a2236; display: block;
}
.mob-notif-item:active { opacity: 0.8; }
.mob-notif-meta { font-size: 12px; color: #9eaabf; margin-bottom: 4px; }
.mob-notif-title { font-size: 14px; font-weight: 600; }
.mob-badge {
    display: inline-block; padding: 2px 8px; border-radius: 10px;
    font-size: 11px; font-weight: 700;
}
.mob-badge-draft  { background: #e5e7eb; color: #374151; }
.mob-badge-ready  { background: #dbeafe; color: #1e40af; }
.mob-badge-sent   { background: #d1fae5; color: #065f46; }
.mob-badge-failed { background: #fee2e2; color: #991b1b; }

/* ── İskelet: yokluk bildirimi ──────────────────────── */
.mob-sprint-note {
    font-size: 12px; color: #9eaabf; text-align: center; margin-top: 8px;
}

@media (min-width: 480px) {
    .mob-content { max-width: 480px; margin: 0 auto; }
    .mob-bottom-actions { max-width: 480px; left: 50%; transform: translateX(-50%); }
    .mob-fab { right: calc(50% - 240px + 20px); }
    .mob-bottomnav { max-width: 480px; left: 50%; transform: translateX(-50%); }
}
</style>
</head>
<body>

<div class="mob-topbar">
    <div class="mob-topbar-logo">
        <div class="mob-topbar-logo-circle">AG</div>
    </div>
    <div class="mob-topbar-title"><?= hks_mob_h($page_title) ?></div>
    <span class="mob-topbar-env <?= $env_cls ?>"><?= $env_cls === 'live' ? 'CANLI' : 'TEST' ?></span>
</div>

<div class="mob-content">
<?php
    /* ── Bottom nav ─────────────────────────────────── */
    ob_start(); // nav içeriği sayfa sonunda basılacak
    ?>
<nav class="mob-bottomnav">
<?php foreach ($tabs as $key => $t):
    $icon_svg = $icons[$t['icon']] ?? '';
    $is_active = ($active_tab === $key);
    ?>
    <a href="<?= hks_mob_h($t['href']) ?>" class="<?= $is_active ? 'active' : '' ?>">
        <?= $icon_svg ?>
        <?= hks_mob_h($t['label']) ?>
    </a>
<?php endforeach; ?>
</nav>
<?php
    $GLOBALS['_hks_mob_nav'] = ob_get_clean();
}

function hks_mob_end(): void {
    echo '</div><!-- .mob-content -->';
    echo $GLOBALS['_hks_mob_nav'] ?? '';
    echo '</body></html>';
}
