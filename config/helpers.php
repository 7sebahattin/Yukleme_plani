<?php
// =========================================================
// config/helpers.php
// Görüntü yardımcıları, biçimleme, CSRF, flash, DB sorguları
// db.php tarafından require edilir.
// =========================================================

declare(strict_types=1);

// En yakın tam sayıya yuvarlama (0.5 ve üstü yukarı, altı aşağı)
if (!function_exists('round_half')) {
    function round_half(float $n): float {
        return (float)round($n);
    }
}

// --- HTML kaçışı ---
function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// --- Sayı parse (virgül/nokta tolere) ---
function num($v): float {
    if ($v === null || $v === '') return 0.0;
    $s = str_replace([' ', "\xc2\xa0"], '', (string)$v);
    // Türkçe format: nokta=binler ayırıcı, virgül=ondalık (ör. "1.234,560")
    // is_numeric kısa yolu kullanılmaz: "40.960" → is_numeric=true → 40.96 (yanlış!)
    if (str_contains($s, ',')) {
        $s = str_replace('.', '', $s); // binler noktasını sil
        $s = str_replace(',', '.', $s); // ondalık virgülü → nokta
    } else {
        $s = str_replace('.', '', $s); // nokta her zaman binler ayracıdır (ör. "40.960" → 40960)
    }
    return is_numeric($s) ? (float)$s : 0.0;
}

function intval_safe($v): int {
    if ($v === null || $v === '') return 0;
    if (is_numeric($v)) return (int)$v;
    return (int)preg_replace('/[^0-9-]/', '', (string)$v);
}

// --- CSRF ---
function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function is_json_request(): bool {
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') return true;
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) return true;
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) return true;
    return false;
}

function csrf_check(?string $token): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    // Geçerli token → sorun yok
    if (!empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token)) {
        return;
    }

    // CSRF başarısız. JSON/AJAX isteklerde düzgün JSON hata dön.
    $wants_json = is_json_request();
    if (!$wants_json) {
        // Çoğu fetch çağrısı Accept/X-Requested-With göndermez; ama JSON endpoint'ler
        // csrf_check'ten önce JSON yanıt başlığı set eder — bunu güvenilir sinyal kabul et.
        foreach (headers_list() as $h) {
            if (stripos($h, 'content-type:') === 0 && stripos($h, 'application/json') !== false) {
                $wants_json = true;
                break;
            }
        }
    }

    http_response_code(403);
    if ($wants_json) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'    => false,
            'error' => 'Güvenlik doğrulaması başarısız.',
            'code'  => 403,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    die('Güvenlik doğrulaması başarısız (CSRF).');
}

// --- Tarih biçimleme ---
function fmt_date(?string $d): string {
    if (!$d) return '';
    $ts = strtotime($d);
    if (!$ts) return h($d);
    return date('d.m.Y', $ts);
}

function fmt_datetime(?string $d): string {
    if (!$d) return '';
    $ts = strtotime($d);
    if (!$ts) return h($d);
    return date('d.m.Y H:i', $ts);
}

// --- Kg biçimle: tam sayıya yuvarla, nokta binlik ayraç (toplam kg için) ---
function fmt_kg($v): string {
    return number_format((int)round((float)$v), 0, '', '.');
}

// --- Birim dara kg: ondalıklı, gereksiz sıfır yok, virgül ondalık ayraç ---
function fmt_unit_kg($v): string {
    $s = rtrim(rtrim(number_format((float)$v, 3, ',', '.'), '0'), ',');
    return $s === '' ? '0' : $s;
}

function fmt_money($v): string {
    return number_format((float)$v, 2, ',', '.');
}

// --- Aktif tanımları çek ---
function get_definitions_by_type(string $type): array {
    $st = db()->prepare("SELECT id, name, unit_dara_kg
                         FROM material_definitions
                         WHERE type = :t AND is_active = 1
                         ORDER BY name ASC");
    $st->execute([':t' => $type]);
    return $st->fetchAll();
}

function get_all_active_materials(): array {
    return db()->query("SELECT id, type, name, unit_dara_kg
                        FROM material_definitions
                        WHERE is_active = 1
                        ORDER BY type, name")->fetchAll();
}

// --- Tanım türü etiketleri (tek kaynak) ---
function definition_types(): array {
    return [
        'firma'         => 'Firma',
        'depo'          => 'Depo',
        'bolge'         => 'Bölge',
        'urun'          => 'Ürün',
        'lokasyon'      => 'Lokasyon',
        'kasa_cinsi'    => 'Kasa Cinsi',
        'palet_tipi'    => 'Palet Tipi',
        'sapka'         => 'Şapka',
        'kosebent'      => 'Köşebent',
        'serit'         => 'Şerit',
        'casus'         => 'Casus',
        'kasa_etiketi'  => 'Kasa Etiketi',
        'minti'         => 'Minti',
        'kenar_kartonu' => 'Kenar Kartonu',
        'taban_kagidi'  => 'Taban Kağıdı',
        'sale'          => 'Şale',
        'viyol'         => 'Viyol',
        'kose_karton'   => 'Köşe Karton',
        'kraft_kagit'   => 'Kraft Kağıt',
        'file'          => 'File',
        'diger'         => 'Diğer',
    ];
}

// Subdirectory-safe base URL (kök için '', hks/ için '../', vb.)
function base_url(): string {
    $dir   = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    $depth = ($dir === '/' || $dir === '') ? 0 : substr_count(trim($dir, '/'), '/') + 1;
    return $depth > 0 ? str_repeat('../', $depth) : '';
}

// --- Ortak header/footer parçaları ---

/**
 * Masaüstü (≥1024px) sabit sol sidebar navigasyonu.
 * Yalnızca CSS ile desktop'ta görünür; mobil/tablet'te gizlidir.
 * Permission mantığı topbar ile aynı can()/is_admin() üzerinden çalışır.
 */
function render_desktop_sidebar(string $base): void {
    $self   = $_SERVER['PHP_SELF'] ?? '';
    $cur    = basename($self);
    $in_hks = strpos($self, '/hks/') !== false;
    $cikma  = ($GLOBALS['_nav_cikma_hint'] ?? false) === true;

    $_fn   = function_exists('can');
    $p_dash = !$_fn || can('dashboard.read');
    $p_rec  = !$_fn || can('records.read');
    $p_recw = !$_fn || can('records.write');
    $p_kant = !$_fn || can('kantar.read');
    $p_stok = !$_fn || can('stok.read');
    $p_rep  = !$_fn || can('reports.read');
    $p_def  = !$_fn || can('defs.read');
    $p_usr  = $_fn && can('users.admin');
    $p_adm  = function_exists('is_admin') && is_admin();

    // Aktif sayfa tespiti
    $a_home  = ($cur === 'index.php' || $cur === '') && !$in_hks;
    $a_yuk   = !$cikma && !$in_hks && in_array($cur, ['records.php','record_view.php','record_edit.php','record_create.php','record_new.php'], true);
    $a_cik   = $cikma || in_array($cur, ['cikmalar.php','cikma_create.php'], true);
    $a_kant  = in_array($cur, ['kantar.php','kantar_view.php'], true);
    $a_krap  = $cur === 'kantar_raporu.php';
    $a_hks   = $in_hks;
    $a_not   = $cur === 'notes.php';
    $a_ustok = $cur === 'stok.php';
    $a_mstok = $cur === 'malzeme_stok.php';
    $a_rep   = $cur === 'reports.php';
    $a_hes   = $cur === 'hesap.php';
    $a_def   = $cur === 'definitions.php';
    $a_usr   = $cur === 'users.php';
    $a_aud   = $cur === 'audit.php';

    $lnk = function (string $href, string $icon, string $label, bool $active) use ($base) {
        echo '<a href="' . $base . $href . '" class="sidebar-link' . ($active ? ' active' : '') . '">'
           . '<span class="sidebar-link-icon" aria-hidden="true">' . $icon . '</span>'
           . '<span class="sidebar-link-label">' . h($label) . '</span></a>';
    };
    ?>
<aside class="desktop-sidebar" aria-label="Masaüstü gezinme">
    <a href="<?= $base ?>index.php" class="sidebar-brand">
        <img src="<?= $base ?>assets/logo.jpg" class="sidebar-brand-logo" alt="">
        <span class="sidebar-brand-txt">
            <span class="sidebar-brand-name">Asya Fresh</span>
            <span class="sidebar-brand-sub">Operasyon Paneli</span>
        </span>
    </a>

    <nav class="sidebar-nav">
        <div class="sidebar-section">Operasyon</div>
        <?php if ($p_dash) $lnk('index.php',     '🏠', 'Ana Sayfa',     $a_home); ?>
        <?php if ($p_rec)  $lnk('records.php',   '📋', 'Yüklemeler',    $a_yuk);  ?>
        <?php if ($p_rec)  $lnk('cikmalar.php',  '🚚', 'Çıkmalar',      $a_cik);  ?>
        <?php if ($p_kant) $lnk('kantar.php',    '⚖️', 'Kantar',        $a_kant); ?>
        <?php if ($p_recw) $lnk('hks/index.php', '🏛', 'Hal Bildirimi', $a_hks);  ?>
        <?php $lnk('notes.php', '📝', 'Notlar', $a_not); ?>

        <?php if ($p_rep): ?>
        <?php $lnk('reports.php', '📊', 'Raporlar', $a_rep); ?>
        <?php $lnk('hesap.php',   '🏦', 'Hesap',    $a_hes); ?>
        <?php endif; ?>

        <?php if ($p_def || $p_usr || $p_adm): ?>
        <div class="sidebar-section">Yönetim</div>
        <?php if ($p_def) $lnk('definitions.php', '⚙️', 'Tanımlar',       $a_def); ?>
        <?php if ($p_usr) $lnk('users.php',       '👥', 'Kullanıcılar',   $a_usr); ?>
        <?php if ($p_adm) $lnk('audit.php',       '🧾', 'İşlem Geçmişi',  $a_aud); ?>
        <?php endif; ?>
    </nav>

    <?php if (function_exists('current_user') && ($__su = current_user()) !== null): ?>
    <div class="sidebar-user">
        <div class="sidebar-user-info">
            <span class="sidebar-user-name"><?= h($__su['display_name'] ?: $__su['username']) ?></span>
            <?php if (function_exists('user_primary_role') && ($__sr = user_primary_role()) !== null): ?>
            <span class="sidebar-user-role"><?= h($__sr['label']) ?></span>
            <?php unset($__sr); endif; ?>
        </div>
        <a href="<?= $base ?>logout.php" class="sidebar-user-logout" title="Çıkış Yap" aria-label="Çıkış Yap">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </a>
    </div>
    <?php unset($__su); endif; ?>
</aside>
    <?php
}

function render_header(string $title, bool $print_mode = false): void {
    $token = csrf_token();
    $cur   = basename($_SERVER['PHP_SELF'] ?? '');
    $base  = base_url();
    ?><!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="csrf-token" content="<?= h($token) ?>">
    <meta name="theme-color" content="#1d6cf0">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Asya Fresh">
    <link rel="manifest" href="<?= $base ?>manifest.json">
    <link rel="apple-touch-icon" href="<?= $base ?>assets/logo.jpg">
    <title><?= h($title) ?> · Asya Fresh</title>
    <link rel="stylesheet" href="<?= $base ?>assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
</head>
<body class="<?= $print_mode ? 'print-mode' : '' ?>">
<?php if (!$print_mode): ?>
<header class="topbar">
    <div class="topbar-inner">
        <a href="<?= $base ?>index.php" class="brand">
            <img src="<?= $base ?>assets/logo.jpg" class="brand-logo" alt="">
            <span class="brand-text">Asya Fresh</span>
        </a>
        <?php
        $_nav_rec   = !function_exists('can') || can('records.read');
        $_nav_rep   = !function_exists('can') || can('reports.read');
        $_nav_stok  = !function_exists('can') || can('stok.read');
        $_nav_def   = !function_exists('can') || can('defs.read');
        $_nav_users = function_exists('is_admin') && is_admin();
        ?>
        <nav class="topnav">
            <?php if ($_nav_rec): ?>
            <a href="<?= $base ?>records.php" <?= $cur === 'records.php' ? 'class="active"' : '' ?>>Yüklemeler</a>
            <a href="<?= $base ?>cikmalar.php" <?= $cur === 'cikmalar.php' ? 'class="active"' : '' ?>>Çıkmalar</a>
            <?php endif; ?>
            <?php if ($_nav_rep): ?>
            <a href="<?= $base ?>reports.php" <?= $cur === 'reports.php' ? 'class="active"' : '' ?>>Raporlar</a>
            <?php endif; ?>
            <?php if ($_nav_stok): ?>
            <a href="<?= $base ?>stok.php" <?= $cur === 'stok.php' ? 'class="active"' : '' ?>>Ürün Stok</a>
            <a href="<?= $base ?>malzeme_stok.php" <?= $cur === 'malzeme_stok.php' ? 'class="active"' : '' ?>>Malzeme Stok</a>
            <?php endif; ?>
            <?php if ($_nav_def): ?>
            <a href="<?= $base ?>definitions.php" <?= $cur === 'definitions.php' ? 'class="active"' : '' ?>>Tanımlar</a>
            <?php endif; ?>
            <?php if ($_nav_users): ?>
            <a href="<?= $base ?>users.php" <?= $cur === 'users.php' ? 'class="active"' : '' ?>>Kullanıcılar</a>
            <?php endif; ?>
        </nav>
        <?php if (function_exists('current_user') && ($__ctu = current_user()) !== null): ?>
        <div class="topnav-user-wrap">
            <div class="topnav-user-info">
                <span class="topnav-user-name"><?= h($__ctu['display_name'] ?: $__ctu['username']) ?></span>
                <?php if (function_exists('user_primary_role') && ($__pr = user_primary_role()) !== null): ?>
                <span class="topnav-user-role"><?= h($__pr['label']) ?></span>
                <?php unset($__pr); endif; ?>
            </div>
            <a href="<?= $base ?>logout.php" class="topnav-logout" title="Çıkış Yap"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></a>
        </div>
        <?php unset($__ctu); endif; ?>
    </div>
</header>
<?php render_desktop_sidebar($base); ?>
<?php endif; ?>
<main class="container">
<?php
    // PWA service worker kaydı (sadece kök seviyesinde)
    if (!$print_mode && $base === ''): ?>
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(function(){});
}
</script>
<?php endif;
}

function render_footer(bool $print_mode = false): void {
    if (!$print_mode) {
        $cur         = basename($_SERVER['PHP_SELF'] ?? '');
        $base        = base_url();
        $is_home     = in_array($cur, ['index.php', '']);
        $_cikma_hint = ($GLOBALS['_nav_cikma_hint'] ?? false) === true;
        $is_records  = !$_cikma_hint && in_array($cur, ['records.php', 'record_view.php', 'record_create.php', 'record_edit.php', 'record_new.php']);
        $is_cikmalar = in_array($cur, ['cikmalar.php', 'cikma_create.php']) || $_cikma_hint;
        $is_defs     = $cur === 'definitions.php';
        $is_reports  = $cur === 'reports.php';
        $is_notes    = $cur === 'notes.php';
        echo '</main>';
        ?>
<nav class="bottomnav" role="navigation" aria-label="Ana gezinme">
    <a href="<?= $base ?>index.php" class="bottomnav-item<?= $is_home ? ' active' : '' ?>">
        <span class="bottomnav-icon">🏠</span>
        <span class="bottomnav-label">Ana Sayfa</span>
    </a>
    <?php if (!function_exists('can') || can('records.read')): ?>
    <a href="<?= $base ?>records.php" class="bottomnav-item<?= $is_records ? ' active' : '' ?>">
        <span class="bottomnav-icon">📋</span>
        <span class="bottomnav-label">Yüklemeler</span>
    </a>
    <?php endif; ?>
    <button type="button" id="notesOpenBtn" class="bottomnav-item bottomnav-notes<?= $is_notes ? ' active' : '' ?>">
        <span class="bottomnav-notes-circle">📝</span>
        <span class="bottomnav-label">Not</span>
    </button>
    <?php if (!function_exists('can') || can('records.read')): ?>
    <a href="<?= $base ?>cikmalar.php" class="bottomnav-item<?= $is_cikmalar ? ' active' : '' ?>">
        <span class="bottomnav-icon">🚚</span>
        <span class="bottomnav-label">Çıkmalar</span>
    </a>
    <?php endif; ?>
    <?php if (!function_exists('can') || can('reports.read')): ?>
    <a href="<?= $base ?>reports.php" class="bottomnav-item<?= $is_reports ? ' active' : '' ?>">
        <span class="bottomnav-icon">📊</span>
        <span class="bottomnav-label">Raporlar</span>
    </a>
    <?php endif; ?>
</nav>

<!-- ── Notlar Modal ── -->
<div id="notesModal" class="notes-overlay" hidden aria-modal="true" role="dialog">
    <div class="notes-modal">
        <div class="notes-modal-head">
            <span class="notes-modal-title">📝 Not Ekle</span>
            <div class="notes-modal-head-actions">
                <a href="<?= $base ?>notes.php" class="btn btn-sm btn-ghost">Tümü →</a>
                <button type="button" id="notesCloseBtn" class="notes-close-btn" aria-label="Kapat">✕</button>
            </div>
        </div>

        <div class="notes-add-section">
            <div class="notes-page-tag" id="notesPageTag"></div>
            <textarea id="notesTextarea" class="notes-textarea" rows="3" placeholder="Not, fikir veya hata..."></textarea>
            <button type="button" id="notesSaveBtn" class="btn btn-primary btn-sm">Kaydet</button>
        </div>

        <div class="notes-list-section">
            <div class="notes-list-head">
                <span>Son Notlar</span>
                <button type="button" id="notesCopyBtn" class="btn btn-sm btn-ghost">📋 Claude için Kopyala</button>
            </div>
            <div id="notesListContainer" class="notes-list-container">
                <div class="notes-loading">Yükleniyor…</div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var csrf    = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var baseUrl = <?= json_encode($base) ?>;
    var modal = document.getElementById('notesModal');
    var openBtn  = document.getElementById('notesOpenBtn');
    var closeBtn = document.getElementById('notesCloseBtn');
    var textarea = document.getElementById('notesTextarea');
    var saveBtn  = document.getElementById('notesSaveBtn');
    var listEl   = document.getElementById('notesListContainer');
    var pageTagEl = document.getElementById('notesPageTag');
    var copyBtn  = document.getElementById('notesCopyBtn');

    if (!modal || !openBtn) return;

    var pageUrl  = window.location.pathname + (window.location.search || '');
    var pageName = (document.title || '').replace(' · Asya Fresh', '').trim();

    function postJson(url, body) {
        body.csrf = csrf;
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    function escHtml(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function renderNote(n) {
        var done = parseInt(n.done, 10) === 1;
        var div = document.createElement('div');
        div.className = 'notes-row' + (done ? ' notes-done' : '');
        div.dataset.noteId = n.id;
        div.innerHTML =
            '<div class="notes-row-page">' + escHtml(n.page_name || n.page_url || '—') + '</div>' +
            '<div class="notes-row-text">' + escHtml(n.note) + '</div>' +
            '<div class="notes-row-actions">' +
                '<button class="notes-check-btn" data-id="' + n.id + '" title="' + (done ? 'Geri Al' : 'Tamamlandı') + '">' +
                    (done ? '↩' : '✓') + '</button>' +
                '<button class="notes-del-btn" data-id="' + n.id + '" title="Sil">✕</button>' +
            '</div>';
        return div;
    }

    function loadNotes() {
        listEl.innerHTML = '<div class="notes-loading">Yükleniyor…</div>';
        fetch(baseUrl + 'note_save.php')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                listEl.innerHTML = '';
                if (!d.notes || !d.notes.length) {
                    listEl.innerHTML = '<div class="notes-empty">Henüz not yok.</div>';
                    return;
                }
                var doneCount = 0;
                d.notes.forEach(function (n) {
                    if (parseInt(n.done, 10) === 1) { doneCount++; return; }
                    listEl.appendChild(renderNote(n));
                });
                if (!listEl.children.length) {
                    listEl.innerHTML = '<div class="notes-empty">Bekleyen not yok.</div>';
                }
                if (doneCount > 0) {
                    var inf = document.createElement('div');
                    inf.className = 'notes-done-info';
                    inf.textContent = doneCount + ' tamamlanan not gizlendi';
                    listEl.appendChild(inf);
                }
            })
            .catch(function () {
                listEl.innerHTML = '<div class="notes-empty">Yüklenemedi.</div>';
            });
    }

    openBtn.addEventListener('click', function () {
        pageTagEl.textContent = '📍 ' + pageName;
        modal.hidden = false;
        document.body.classList.add('notes-modal-open');
        textarea.focus();
        loadNotes();
    });

    function closeModal() {
        modal.hidden = true;
        document.body.classList.remove('notes-modal-open');
        textarea.value = '';
    }

    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });

    saveBtn.addEventListener('click', function () {
        var note = textarea.value.trim();
        if (!note) { textarea.focus(); return; }
        saveBtn.disabled = true;
        postJson(baseUrl + 'note_save.php', { page_url: pageUrl, page_name: pageName, note: note })
            .then(function (d) {
                saveBtn.disabled = false;
                if (!d.ok) { alert(d.msg || 'Hata'); return; }
                textarea.value = '';
                var emptyEl = listEl.querySelector('.notes-empty');
                if (emptyEl) emptyEl.remove();
                listEl.insertBefore(renderNote(d.note), listEl.firstChild);
            })
            .catch(function () { saveBtn.disabled = false; alert('Bağlantı hatası.'); });
    });

    textarea.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') saveBtn.click();
    });

    listEl.addEventListener('click', function (e) {
        var checkBtn = e.target.closest('.notes-check-btn');
        var delBtn   = e.target.closest('.notes-del-btn');

        if (checkBtn) {
            var id  = parseInt(checkBtn.dataset.id, 10);
            var row = listEl.querySelector('.notes-row[data-note-id="' + id + '"]');
            checkBtn.disabled = true;
            postJson('note_update.php', { action: 'toggle', id: id })
                .then(function (d) {
                    if (!d.ok) { checkBtn.disabled = false; alert(d.msg || 'Hata'); return; }
                    // done=1 → gizle (Son Notlar sadece actif olanları gösterir)
                    row.remove();
                    var remaining = listEl.querySelectorAll('.notes-row');
                    var doneInf = listEl.querySelector('.notes-done-info');
                    if (!remaining.length) {
                        listEl.innerHTML = '<div class="notes-empty">Bekleyen not yok.</div>';
                    } else {
                        if (doneInf) {
                            var prev = parseInt(doneInf.textContent, 10) || 0;
                            doneInf.textContent = (prev + 1) + ' tamamlanan not gizlendi';
                        } else {
                            var inf = document.createElement('div');
                            inf.className = 'notes-done-info';
                            inf.textContent = '1 tamamlanan not gizlendi';
                            listEl.appendChild(inf);
                        }
                    }
                });
        }

        if (delBtn) {
            var id  = parseInt(delBtn.dataset.id, 10);
            var row = listEl.querySelector('.notes-row[data-note-id="' + id + '"]');
            delBtn.disabled = true;
            postJson('note_update.php', { action: 'delete', id: id })
                .then(function (d) {
                    if (!d.ok) { delBtn.disabled = false; alert(d.msg || 'Hata'); return; }
                    row.remove();
                    if (!listEl.querySelector('.notes-row')) {
                        listEl.innerHTML = '<div class="notes-empty">Henüz not yok.</div>';
                    }
                });
        }
    });

    copyBtn.addEventListener('click', function () {
        var rows = listEl.querySelectorAll('.notes-row');
        if (!rows.length) { alert('Kopyalanacak not yok.'); return; }

        var groups = {};
        rows.forEach(function (row) {
            var page = (row.querySelector('.notes-row-page') || {}).textContent || '—';
            if (!groups[page]) groups[page] = [];
            var note = (row.querySelector('.notes-row-text') || {}).textContent || '';
            groups[page].push({ note: note.trim(), done: row.classList.contains('notes-done') });
        });

        var md = '## Geliştirme Notları\n\n';
        Object.keys(groups).forEach(function (page) {
            md += '### ' + page + '\n';
            groups[page].forEach(function (n) {
                md += (n.done ? '- [x] ' : '- [ ] ') + n.note + '\n';
            });
            md += '\n';
        });

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(md).then(function () {
                copyBtn.textContent = '✓ Kopyalandı!';
                setTimeout(function () { copyBtn.textContent = '📋 Claude için Kopyala'; }, 2000);
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = md; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            copyBtn.textContent = '✓ Kopyalandı!';
            setTimeout(function () { copyBtn.textContent = '📋 Claude için Kopyala'; }, 2000);
        }
    });
})();
</script>
<?php
        echo '<script src="' . base_url() . 'assets/app.js?v=' . filemtime(__DIR__ . '/../assets/app.js') . '"></script></body></html>';
    } else {
        echo '</main></body></html>';
    }
}

// --- Flash mesaj ---
function set_flash(string $type, string $msg): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function get_flash(): ?array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}
function render_flash(): void {
    $f = get_flash();
    if ($f) {
        echo '<div class="flash flash-' . h($f['type']) . '">' . h($f['msg']) . '</div>';
    }
}

// --- Tek seferlik otomatik migrasyon ---
(function () {
    try {
        $pdo = db();
        // 1) loading_records.type kolonu
        try {
            $pdo->query("SELECT 1 FROM `loading_records` LIMIT 0");
            $has = $pdo->query("SHOW COLUMNS FROM `loading_records` LIKE 'type'")->fetchColumn();
            if (!$has) {
                $pdo->exec("ALTER TABLE `loading_records` ADD COLUMN `type` VARCHAR(20) NOT NULL DEFAULT 'yukleme'");
            }
            $has_durum = $pdo->query("SHOW COLUMNS FROM `loading_records` LIKE 'durum'")->fetchColumn();
            if (!$has_durum) {
                $pdo->exec("ALTER TABLE `loading_records` ADD COLUMN `durum` VARCHAR(20) NOT NULL DEFAULT ''");
            }
            $has_upd = $pdo->query("SHOW COLUMNS FROM `loading_records` LIKE 'updated_at'")->fetchColumn();
            if (!$has_upd) {
                $pdo->exec("ALTER TABLE `loading_records` ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP");
            }
            $has_cn = $pdo->query("SHOW COLUMNS FROM `loading_records` LIKE 'cikis_nedeni'")->fetchColumn();
            if (!$has_cn) {
                $pdo->exec("ALTER TABLE `loading_records` ADD COLUMN `cikis_nedeni` VARCHAR(80) NOT NULL DEFAULT ''");
            }
        } catch (PDOException $e) {}

        // 2) Kantar tabloları
        $pdo->exec("CREATE TABLE IF NOT EXISTS `kantar_fisleri` (
            `id`           INT AUTO_INCREMENT PRIMARY KEY,
            `fis_no`       VARCHAR(50)  NOT NULL DEFAULT '',
            `giris_tarih`  VARCHAR(40)  NOT NULL DEFAULT '',
            `cikis_tarih`  VARCHAR(40)  NOT NULL DEFAULT '',
            `plaka`        VARCHAR(30)  NOT NULL DEFAULT '',
            `firma_adi`    VARCHAR(120) NOT NULL DEFAULT '',
            `malin_cinsi`  VARCHAR(200) NOT NULL DEFAULT '',
            `geldigi_yer`  VARCHAR(200) NOT NULL DEFAULT '',
            `gittigi_yer`  VARCHAR(100) NOT NULL DEFAULT '',
            `aciklama`     TEXT,
            `operator_adi` VARCHAR(100) NOT NULL DEFAULT '',
            `tartim1`      DECIMAL(12,3) NOT NULL DEFAULT 0,
            `alibi1`       VARCHAR(30)  NOT NULL DEFAULT '',
            `tartim2`      DECIMAL(12,3) NOT NULL DEFAULT 0,
            `alibi2`       VARCHAR(30)  NOT NULL DEFAULT '',
            `net_kg`       DECIMAL(12,3) NOT NULL DEFAULT 0,
            `toplam_palet` INT          NOT NULL DEFAULT 0,
            `kasa_dara`    DECIMAL(10,3) NOT NULL DEFAULT 0,
            `palet_dara`   DECIMAL(10,3) NOT NULL DEFAULT 0,
            `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `kantar_gruplar` (
            `id`           INT AUTO_INCREMENT PRIMARY KEY,
            `fis_id`       INT NOT NULL,
            `sira`         INT NOT NULL DEFAULT 0,
            `grup_adi`     VARCHAR(100) NOT NULL DEFAULT '',
            `palet_sayisi` INT NOT NULL DEFAULT 0,
            `kasa_adedi`   INT NOT NULL DEFAULT 0,
            FOREIGN KEY (`fis_id`) REFERENCES `kantar_fisleri`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 3) kantar_fisleri yeni kolonlar
        $kf_cols = $pdo->query("SHOW COLUMNS FROM `kantar_fisleri`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('aciklama2',    $kf_cols)) $pdo->exec("ALTER TABLE `kantar_fisleri` ADD COLUMN `aciklama2`    TEXT");
        if (!in_array('palet_sayisi', $kf_cols)) $pdo->exec("ALTER TABLE `kantar_fisleri` ADD COLUMN `palet_sayisi` INT NOT NULL DEFAULT 0");
        if (!in_array('kasa_cinsi',   $kf_cols)) $pdo->exec("ALTER TABLE `kantar_fisleri` ADD COLUMN `kasa_cinsi`   VARCHAR(200) NOT NULL DEFAULT ''");
        if (!in_array('kasa_sayisi',  $kf_cols)) $pdo->exec("ALTER TABLE `kantar_fisleri` ADD COLUMN `kasa_sayisi`  INT NOT NULL DEFAULT 0");
        if (!in_array('palet_cinsi',  $kf_cols)) $pdo->exec("ALTER TABLE `kantar_fisleri` ADD COLUMN `palet_cinsi`  VARCHAR(200) NOT NULL DEFAULT ''");
        if (!in_array('foto_data',    $kf_cols)) $pdo->exec("ALTER TABLE `kantar_fisleri` ADD COLUMN `foto_data`    MEDIUMTEXT NULL DEFAULT NULL");
        // depo + parti_no: stok eşleştirme için
        if (!in_array('depo',     $kf_cols)) $pdo->exec("ALTER TABLE `kantar_fisleri` ADD COLUMN `depo`     VARCHAR(150) NOT NULL DEFAULT ''");
        if (!in_array('parti_no', $kf_cols)) $pdo->exec("ALTER TABLE `kantar_fisleri` ADD COLUMN `parti_no` VARCHAR(80)  NOT NULL DEFAULT ''");

        // kantar_gruplar yeni kolonlar (per-grup dara) — ayrı try/catch: üst catch'e düşmesini engeller
        try {
            $kg_cols = $pdo->query("SHOW COLUMNS FROM `kantar_gruplar`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('kasa_dara_kg',  $kg_cols)) $pdo->exec("ALTER TABLE `kantar_gruplar` ADD COLUMN `kasa_dara_kg`  DECIMAL(10,3) NOT NULL DEFAULT 0");
            if (!in_array('palet_dara_kg', $kg_cols)) $pdo->exec("ALTER TABLE `kantar_gruplar` ADD COLUMN `palet_dara_kg` DECIMAL(10,3) NOT NULL DEFAULT 0");
            if (!in_array('brut_kg',       $kg_cols)) $pdo->exec("ALTER TABLE `kantar_gruplar` ADD COLUMN `brut_kg`       DECIMAL(12,3) NOT NULL DEFAULT 0");
        } catch (PDOException $e) {}

        // loading_pallets.islendi — palet başına işlendi işareti
        try {
            $lp_cols = $pdo->query("SHOW COLUMNS FROM `loading_pallets`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('islendi', $lp_cols)) {
                $pdo->exec("ALTER TABLE `loading_pallets` ADD COLUMN `islendi` TINYINT(1) NOT NULL DEFAULT 0");
            }
        } catch (PDOException $e) {}

        // kantar_kasa_palet_satir — çok-tip kasa/palet desteği
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `kantar_kasa_palet_satir` (
                `id`            INT AUTO_INCREMENT PRIMARY KEY,
                `fis_id`        INT NOT NULL,
                `tip`           VARCHAR(10) NOT NULL DEFAULT 'kasa',
                `cinsi`         VARCHAR(150) NOT NULL DEFAULT '',
                `sayisi`        INT NOT NULL DEFAULT 0,
                `birim_dara_kg` DECIMAL(10,3) NOT NULL DEFAULT 0,
                INDEX `idx_kps_fis` (`fis_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $e) {}
        try {
            $kf_cols2 = $pdo->query("SHOW COLUMNS FROM `kantar_fisleri`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('kasa_dara_total',  $kf_cols2)) $pdo->exec("ALTER TABLE `kantar_fisleri` ADD COLUMN `kasa_dara_total`  DECIMAL(12,3) NOT NULL DEFAULT 0");
            if (!in_array('palet_dara_total', $kf_cols2)) $pdo->exec("ALTER TABLE `kantar_fisleri` ADD COLUMN `palet_dara_total` DECIMAL(12,3) NOT NULL DEFAULT 0");
        } catch (PDOException $e) {}

        // 4) Depo/Ürün tanımlarını normalize et + loading_pallets.depo normalize
        try {
            $norm = function(string $s): string {
                return preg_replace('/\s+/', '', strtolower(strtr($s,
                    ['İ'=>'i','Ş'=>'s','Ç'=>'c','Ğ'=>'g','Ü'=>'u','Ö'=>'o','ı'=>'i','ş'=>'s','ç'=>'c','ğ'=>'g','ü'=>'u','ö'=>'o']
                )));
            };
            foreach ($pdo->query("SELECT id, name FROM material_definitions WHERE type='depo'")->fetchAll() as $dr) {
                if ($norm($dr['name']) === 'cihat') {
                    $pdo->prepare("UPDATE material_definitions SET name='Karaman Cihat' WHERE id=?")->execute([$dr['id']]);
                }
            }
            // Remove exact duplicates keeping first
            $depo_rows = $pdo->query("SELECT id, name FROM material_definitions WHERE type='depo' ORDER BY id")->fetchAll();
            $seen = [];
            foreach ($depo_rows as $dr) {
                $k = $norm($dr['name']);
                if (isset($seen[$k])) {
                    try { $pdo->prepare("DELETE FROM material_definitions WHERE id=?")->execute([$dr['id']]); } catch(Exception $e2) {}
                } else { $seen[$k] = true; }
            }
            foreach ($pdo->query("SELECT id, name FROM material_definitions WHERE type='urun'")->fetchAll() as $ur) {
                if ($norm($ur['name']) === 'kayisi') {
                    $pdo->prepare("UPDATE material_definitions SET name='Kayısı' WHERE id=?")->execute([$ur['id']]);
                }
            }
            $urun_rows = $pdo->query("SELECT id, name FROM material_definitions WHERE type='urun' ORDER BY id")->fetchAll();
            $seen2 = [];
            foreach ($urun_rows as $ur) {
                $k = $norm($ur['name']);
                if (isset($seen2[$k])) {
                    try { $pdo->prepare("DELETE FROM material_definitions WHERE id=?")->execute([$ur['id']]); } catch(Exception $e2) {}
                } else { $seen2[$k] = true; }
            }
            // loading_pallets.depo normalize (Cihat / CİHAT → Karaman Cihat)
            foreach (['Cihat','CİHAT','CIHAT','cihat','cİhat'] as $old_depo) {
                $pdo->prepare("UPDATE loading_pallets SET depo='Karaman Cihat' WHERE depo=?")->execute([$old_depo]);
            }
        } catch(PDOException $e) {}

        // 5) dev_notes tablosu
        $pdo->exec("CREATE TABLE IF NOT EXISTS `dev_notes` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `page_url`   VARCHAR(255) NOT NULL DEFAULT '',
            `page_name`  VARCHAR(100) NOT NULL DEFAULT '',
            `note`       TEXT NOT NULL,
            `done`       TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 6) Stok sorgu indeksleri (idempotent)
        try {
            $idx_checks = [
                ['kantar_fisleri', 'idx_kf_firma',      "ALTER TABLE `kantar_fisleri` ADD INDEX `idx_kf_firma`      (`firma_adi`)"],
                ['kantar_fisleri', 'idx_kf_malin',      "ALTER TABLE `kantar_fisleri` ADD INDEX `idx_kf_malin`      (`malin_cinsi`(100))"],
                ['kantar_fisleri', 'idx_kf_tarih',      "ALTER TABLE `kantar_fisleri` ADD INDEX `idx_kf_tarih`      (`giris_tarih`)"],
                ['kantar_fisleri', 'idx_kf_depo',       "ALTER TABLE `kantar_fisleri` ADD INDEX `idx_kf_depo`       (`depo`(80))"],
                ['kantar_fisleri', 'idx_kf_parti_no',   "ALTER TABLE `kantar_fisleri` ADD INDEX `idx_kf_parti_no`   (`parti_no`(40))"],
                ['loading_records','idx_lr_type',       "ALTER TABLE `loading_records` ADD INDEX `idx_lr_type`       (`type`)"],
                ['loading_pallets','idx_lp_depo',       "ALTER TABLE `loading_pallets` ADD INDEX `idx_lp_depo`       (`depo`(80))"],
                ['loading_pallets','idx_lp_urun_cinsi', "ALTER TABLE `loading_pallets` ADD INDEX `idx_lp_urun_cinsi` (`urun_cinsi`(80))"],
            ];
            foreach ($idx_checks as [$tbl, $key, $alter]) {
                $st = $pdo->prepare("SHOW INDEX FROM `$tbl` WHERE Key_name = ?");
                $st->execute([$key]);
                if ($st->rowCount() === 0) {
                    try { $pdo->exec($alter); } catch (PDOException $ie) {}
                }
            }
        } catch (PDOException $e) {}

        // 7) Hesap modülü tabloları
        $pdo->exec("CREATE TABLE IF NOT EXISTS `account_transactions` (
            `id`                     INT AUTO_INCREMENT PRIMARY KEY,
            `transaction_date`       DATE NOT NULL,
            `transaction_time`       TIME NOT NULL DEFAULT '00:00:00',
            `type`                   ENUM('gelir','gider','havale','nakit') NOT NULL,
            `category`               VARCHAR(100) NOT NULL DEFAULT '',
            `amount`                 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `currency`               VARCHAR(5) NOT NULL DEFAULT 'TRY',
            `payment_method`         VARCHAR(30) NOT NULL DEFAULT 'nakit',
            `person_company`         VARCHAR(200) NOT NULL DEFAULT '',
            `description`            TEXT NOT NULL DEFAULT '',
            `document_no`            VARCHAR(100) NOT NULL DEFAULT '',
            `has_invoice`            TINYINT(1) NOT NULL DEFAULT 0,
            `is_for_company`         TINYINT(1) NOT NULL DEFAULT 1,
            `is_given_to_accountant` TINYINT(1) NOT NULL DEFAULT 0,
            `notes`                  TEXT NOT NULL DEFAULT '',
            `has_files`              TINYINT(1) NOT NULL DEFAULT 0,
            `created_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`             TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_date` (`transaction_date`),
            INDEX `idx_type` (`type`),
            INDEX `idx_accountant` (`is_given_to_accountant`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS `account_files` (
            `id`             INT AUTO_INCREMENT PRIMARY KEY,
            `transaction_id` INT NOT NULL,
            `file_name`      VARCHAR(255) NOT NULL,
            `original_name`  VARCHAR(255) NOT NULL DEFAULT '',
            `file_type`      VARCHAR(50) NOT NULL DEFAULT '',
            `file_size`      INT NOT NULL DEFAULT 0,
            `uploaded_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_tid` (`transaction_id`),
            CONSTRAINT `fk_af_tid` FOREIGN KEY (`transaction_id`)
                REFERENCES `account_transactions`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 8) Malzeme stok hareketleri
        $pdo->exec("CREATE TABLE IF NOT EXISTS `material_stock_movements` (
            `id`               INT AUTO_INCREMENT PRIMARY KEY,
            `movement_date`    DATE NOT NULL,
            `movement_type`    ENUM('giris','sevk','kullanim','duzeltme') NOT NULL DEFAULT 'giris',
            `material_id`      INT NULL,
            `material_name`    VARCHAR(200) NOT NULL DEFAULT '',
            `material_type`    VARCHAR(50)  NOT NULL DEFAULT '',
            `depo`             VARCHAR(150) NOT NULL DEFAULT '',
            `quantity`         DECIMAL(12,3) NOT NULL DEFAULT 0,
            `unit`             VARCHAR(20)  NOT NULL DEFAULT 'adet',
            `unit_dara_kg`     DECIMAL(10,3) NOT NULL DEFAULT 0,
            `total_dara_kg`    DECIMAL(12,3) NOT NULL DEFAULT 0,
            `source_type`      VARCHAR(30)  NOT NULL DEFAULT '',
            `source_id`        INT NULL,
            `source_detail_id` INT NULL,
            `belge_no`         VARCHAR(100) NOT NULL DEFAULT '',
            `firma`            VARCHAR(200) NOT NULL DEFAULT '',
            `note`             TEXT NULL,
            `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_msm_date`    (`movement_date`),
            INDEX `idx_msm_type`    (`movement_type`),
            INDEX `idx_msm_mat`     (`material_id`),
            INDEX `idx_msm_matname` (`material_name`(100)),
            INDEX `idx_msm_mattype` (`material_type`(30)),
            INDEX `idx_msm_depo`    (`depo`(80)),
            INDEX `idx_msm_source`  (`source_type`, `source_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 9) Stok sayım tablosu
        $pdo->exec("CREATE TABLE IF NOT EXISTS `stock_counts` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `count_date` DATE NOT NULL,
            `firma`      VARCHAR(150) NOT NULL DEFAULT '',
            `urun`       VARCHAR(150) NOT NULL DEFAULT '',
            `depo`       VARCHAR(150) NOT NULL DEFAULT '',
            `parti_no`   VARCHAR(80)  NOT NULL DEFAULT '',
            `system_kg`  DECIMAL(12,3) NOT NULL DEFAULT 0,
            `counted_kg` DECIMAL(12,3) NOT NULL DEFAULT 0,
            `diff_kg`    DECIMAL(12,3) NOT NULL DEFAULT 0,
            `note`       TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_sc_date`    (`count_date`),
            INDEX `idx_sc_firma`   (`firma`(80)),
            INDEX `idx_sc_urun`    (`urun`(80)),
            INDEX `idx_sc_depo`    (`depo`(80)),
            INDEX `idx_sc_parti`   (`parti_no`(40))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 10) Auth tabloları: users + user_sessions
        $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `username`      VARCHAR(60)  NOT NULL,
            `email`         VARCHAR(120) NULL DEFAULT NULL,
            `password_hash` VARCHAR(255) NOT NULL,
            `display_name`  VARCHAR(100) NOT NULL DEFAULT '',
            `is_active`     TINYINT(1)  NOT NULL DEFAULT 1,
            `created_at`    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`    DATETIME    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            `last_login_at` DATETIME    NULL DEFAULT NULL,
            UNIQUE KEY `uq_users_username` (`username`),
            INDEX `idx_users_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `user_sessions` (
            `token`        CHAR(64)     NOT NULL,
            `user_id`      INT          NOT NULL,
            `ip`           VARCHAR(45)  NOT NULL DEFAULT '',
            `user_agent`   VARCHAR(500) NOT NULL DEFAULT '',
            `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `expires_at`   DATETIME     NOT NULL,
            `last_seen_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`token`),
            INDEX `idx_us_user_id` (`user_id`),
            INDEX `idx_us_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 11) Rol tabloları + yetki seed + admin garantisi
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `roles` (
                `id`         INT AUTO_INCREMENT PRIMARY KEY,
                `slug`       VARCHAR(40) NOT NULL,
                `label`      VARCHAR(80) NOT NULL DEFAULT '',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_roles_slug` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `role_permissions` (
                `role_id`    INT NOT NULL,
                `permission` VARCHAR(80) NOT NULL,
                PRIMARY KEY (`role_id`, `permission`),
                INDEX `idx_rp_perm` (`permission`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `user_roles` (
                `user_id`    INT NOT NULL,
                `role_id`    INT NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`, `role_id`),
                INDEX `idx_ur_role` (`role_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Rol seed (idempotent)
            $ins_r = $pdo->prepare("INSERT IGNORE INTO `roles` (slug, label) VALUES (?, ?)");
            foreach ([
                ['admin',    'Sistem Yöneticisi'],
                ['operator', 'Operatör'],
                ['viewer',   'Görüntüleyici'],
                ['muhasebe', 'Muhasebe'],
            ] as [$rs, $rl]) { $ins_r->execute([$rs, $rl]); }

            // Rol ID haritası
            $rids = $pdo->query("SELECT slug, id FROM `roles`")->fetchAll(PDO::FETCH_KEY_PAIR);

            // Yetki tanımları
            $all_p = ['dashboard.read','records.read','records.write','records.delete','records.lock','records.unlock','kantar.read','kantar.write','kantar.delete','stok.read','stok.write','defs.read','defs.write','defs.admin','reports.read','reports.export','users.read','users.write','users.admin'];
            $rp_map = [
                'admin'    => $all_p,
                'operator' => ['dashboard.read','records.read','records.write','records.lock','kantar.read','kantar.write','stok.read','stok.write','defs.read','reports.read','reports.export'],
                'viewer'   => ['dashboard.read','records.read','kantar.read','stok.read','defs.read','reports.read'],
                'muhasebe' => ['dashboard.read','records.read','stok.read','reports.read','reports.export'],
            ];
            $ins_p = $pdo->prepare("INSERT IGNORE INTO `role_permissions` (role_id, permission) VALUES (?, ?)");
            foreach ($rp_map as $slug => $perms) {
                $rid = (int)($rids[$slug] ?? 0);
                if (!$rid) continue;
                foreach ($perms as $p) { $ins_p->execute([$rid, $p]); }
            }

            // Admin garantisi: hiç admin rolü atanmamışsa ilk aktif kullanıcıya ver
            $admin_rid = (int)($rids['admin'] ?? 0);
            if ($admin_rid > 0) {
                $st_ha = $pdo->prepare("SELECT COUNT(*) FROM `user_roles` WHERE role_id = ?");
                $st_ha->execute([$admin_rid]);
                if ((int)$st_ha->fetchColumn() === 0) {
                    $fu = $pdo->query("SELECT id FROM `users` WHERE is_active=1 ORDER BY id ASC LIMIT 1")->fetchColumn();
                    if ($fu) {
                        $pdo->prepare("INSERT IGNORE INTO `user_roles` (user_id, role_id) VALUES (?, ?)")
                            ->execute([(int)$fu, $admin_rid]);
                    }
                }
            }
        } catch (PDOException $e) {}

        // 12) Audit log tablosu
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `audit_log` (
                `id`         BIGINT AUTO_INCREMENT PRIMARY KEY,
                `user_id`    INT NULL,
                `action`     VARCHAR(40) NOT NULL,
                `module`     VARCHAR(40) NOT NULL,
                `record_id`  INT NULL,
                `old_values` LONGTEXT NULL,
                `new_values` LONGTEXT NULL,
                `ip`         VARCHAR(45) NULL,
                `user_agent` VARCHAR(255) NULL,
                `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                INDEX `idx_al_uid`     (`user_id`),
                INDEX `idx_al_mod_rid` (`module`, `record_id`),
                INDEX `idx_al_action`  (`action`),
                INDEX `idx_al_ts`      (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (PDOException $e) {}

        // 13) Kayıt kilitleme kolonları
        try {
            $lr_cols = $pdo->query("SHOW COLUMNS FROM `loading_records`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('locked_at',       $lr_cols)) $pdo->exec("ALTER TABLE `loading_records` ADD COLUMN `locked_at`       DATETIME NULL DEFAULT NULL");
            if (!in_array('locked_by',       $lr_cols)) $pdo->exec("ALTER TABLE `loading_records` ADD COLUMN `locked_by`       INT NULL DEFAULT NULL");
            if (!in_array('unlocked_at',     $lr_cols)) $pdo->exec("ALTER TABLE `loading_records` ADD COLUMN `unlocked_at`     DATETIME NULL DEFAULT NULL");
            if (!in_array('unlocked_by',     $lr_cols)) $pdo->exec("ALTER TABLE `loading_records` ADD COLUMN `unlocked_by`     INT NULL DEFAULT NULL");
            if (!in_array('revision_reason', $lr_cols)) $pdo->exec("ALTER TABLE `loading_records` ADD COLUMN `revision_reason` TEXT NULL DEFAULT NULL");
            $st_idx = $pdo->prepare("SHOW INDEX FROM `loading_records` WHERE Key_name = 'idx_lr_locked'");
            $st_idx->execute();
            if ($st_idx->rowCount() === 0) {
                try { $pdo->exec("ALTER TABLE `loading_records` ADD INDEX `idx_lr_locked` (`locked_at`)"); } catch (PDOException $ie) {}
            }
        } catch (PDOException $e) {}

    } catch (PDOException $e) {}
})();

function _audit_sanitize(array $data): array {
    static $blocked = ['password', 'password_hash', 'token', 'csrf', 'cookie', 'foto_data', 'session', 'asya_session', 'http_cookie'];
    $out = [];
    foreach ($data as $k => $v) {
        if (in_array(strtolower((string)$k), $blocked, true)) continue;
        if (is_string($v) && strlen($v) > 1000) $v = substr($v, 0, 1000) . '…';
        $out[$k] = $v;
    }
    return $out;
}

function audit_log_event(
    string $action,
    string $module,
    ?int $record_id = null,
    ?array $old_values = null,
    ?array $new_values = null,
    ?int $explicit_user_id = null
): void {
    try {
        if ($explicit_user_id !== null) {
            $user_id = $explicit_user_id;
        } else {
            $user    = function_exists('current_user') ? current_user() : null;
            $user_id = $user ? (int)$user['id'] : null;
        }
        $ip       = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua       = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $old_json = $old_values !== null ? json_encode(_audit_sanitize($old_values), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $new_json = $new_values !== null ? json_encode(_audit_sanitize($new_values), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        db()->prepare(
            "INSERT INTO audit_log (user_id, action, module, record_id, old_values, new_values, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$user_id, $action, $module, $record_id, $old_json, $new_json, $ip, $ua]);
    } catch (PDOException $e) {
        error_log('audit_log_event failed: ' . $e->getMessage());
    }
}

// --- Bir kaydın özet toplamlarını çek ---
function record_totals(int $record_id): array {
    $st = db()->prepare("SELECT
            COUNT(*) AS palet_count,
            COALESCE(SUM(kasa_adeti),0) AS toplam_kasa,
            COALESCE(SUM(brut_kg),0)   AS toplam_brut,
            COALESCE(SUM(dara_kg),0)   AS toplam_dara,
            COALESCE(SUM(net_kg),0)    AS toplam_net
        FROM loading_pallets
        WHERE loading_record_id = :id");
    $st->execute([':id' => $record_id]);
    return $st->fetch() ?: [
        'palet_count'  => 0,
        'toplam_kasa'  => 0,
        'toplam_brut'  => 0,
        'toplam_dara'  => 0,
        'toplam_net'   => 0,
    ];
}

// ── Metin Normalleştirme ──────────────────────────────────
// Baş/son boşluk sil, çoklu boşluğu teke düşür, title case uygula.
// Türkçe karakterleri bozmaz; tamamen büyük/küçük yapmaz.
function normalize_text(string $v): string {
    $v = trim($v);
    if ($v === '') return '';
    $v = preg_replace('/\s+/u', ' ', $v);
    return mb_convert_case($v, MB_CASE_TITLE, 'UTF-8');
}

// Turkish-safe title case — avoids U+0307 combining dot that MB_CASE_TITLE produces for İ.
// Correctly handles i→İ and ı→I at word boundaries.
function normalize_text_v2(string $v): string {
    $v = trim($v);
    if ($v === '') return '';
    $v = preg_replace('/\s+/u', ' ', $v);
    $v = str_replace(['I', 'İ'], ['ı', 'i'], $v);
    $v = mb_strtolower($v, 'UTF-8');
    $words = explode(' ', $v);
    $words = array_map(function(string $w): string {
        if ($w === '') return '';
        $first = mb_substr($w, 0, 1, 'UTF-8');
        $rest  = mb_substr($w, 1, null, 'UTF-8');
        if ($first === 'i') return 'İ' . $rest;
        if ($first === 'ı') return 'I' . $rest;
        return mb_strtoupper($first, 'UTF-8') . $rest;
    }, $words);
    return implode(' ', $words);
}

function normalize_firma(string $v): string { return normalize_text_v2($v); }
function normalize_urun(string $v): string  { return normalize_text_v2($v); }
function normalize_depo(string $v): string  { return normalize_text_v2($v); }

// ── Tanım Tablosuna Otomatik Ekle ────────────────────────
// Case-insensitive kontrol; aynı isim iki kez eklenmez.
// type: 'firma' | 'depo' | 'urun'
function ensure_definition(string $type, string $name): void {
    $name = normalize_text_v2($name);
    if ($name === '') return;
    try {
        $pdo = db();
        $st  = $pdo->prepare("SELECT name FROM material_definitions WHERE type = ?");
        $st->execute([$type]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) {
            if (normalize_text_v2($n) === $name) return;
        }
        $pdo->prepare(
            "INSERT INTO material_definitions (type, name, unit_dara_kg, is_active) VALUES (?, ?, 0, 1)"
        )->execute([$type, $name]);
    } catch (PDOException $e) {}
}

// ── Palet Satırı Validasyonu ──────────────────────────────
// $computed: compute_pallet_row() çıktıları dizisi
// Döner: hata mesajları dizisi (boş ise sorun yok)
function validate_pallet_rows(array $computed, bool $require_urun_cinsi = false): array {
    $errs = [];
    if (empty($computed)) {
        $errs[] = 'En az bir palet satırı girilmelidir.';
        return $errs;
    }
    foreach ($computed as $i => $p) {
        $n = $i + 1;
        if (trim((string)($p['depo'] ?? '')) === '') {
            $errs[] = $n . '. paletin deposu zorunludur.';
        }
        if ((float)($p['net_kg'] ?? 0) <= 0) {
            $errs[] = $n . '. paletin net KG değeri sıfırdan büyük olmalıdır (brüt > dara).';
        }
        if (empty($p['kasa_cinsi_id'])) {
            $errs[] = $n . '. paletin kasa cinsi seçilmelidir.';
        }
        if (empty($p['palet_tipi_id'])) {
            $errs[] = $n . '. paletin palet tipi seçilmelidir.';
        }
        if ($require_urun_cinsi && trim((string)($p['urun_cinsi'] ?? '')) === '') {
            $errs[] = $n . '. paletin ürün cinsi zorunludur.';
        }
    }
    return $errs;
}

// ── Malzeme Stok: Yükleme/Çıkma hareketlerini senkronize et ──
// Yükleme/çıkma kaydı kaydedildikten/güncellendikten sonra çağrılır.
// Kaynaklar → material_stock_movements (idempotent sync):
//   A) loading_pallets.kasa_cinsi_id  (kasa kullanımı, quantity = kasa_adeti)
//   B) loading_pallets.palet_tipi_id  (palet kullanımı, quantity = 1)
//   C) pallet_materials               (sarf malzeme kullanımı)
// Yön: type='yukleme' → 'kullanim' (stoktan düş) · type='cikma' → 'giris' (stoğa dön).
// Yalnızca source_type='loading' + source_id=loading_record_id hareketleri silinip
// yeniden yazılır; manuel hareketlere dokunulmaz.
function sync_malzeme_kullanim(int $loading_record_id): void {
    try {
        $pdo = db();
        // material_stock_movements tablosu yoksa çık
        $pdo->query("SELECT 1 FROM `material_stock_movements` LIMIT 0");

        // Kayıt türü → hareket yönü
        $lr_st = $pdo->prepare("SELECT type, tarih FROM loading_records WHERE id=? LIMIT 1");
        $lr_st->execute([$loading_record_id]);
        $lr = $lr_st->fetch();
        if (!$lr) return;
        $is_cikma = (($lr['type'] ?? 'yukleme') === 'cikma');
        $mv_type  = $is_cikma ? 'giris' : 'kullanim';
        $lr_tarih = $lr['tarih'] ?: date('Y-m-d');

        // Okunabilir açıklamalar
        $note_kasa  = $is_cikma ? 'Çıkma kasa dönüşü'    : 'Yükleme kasa kullanımı';
        $note_palet = $is_cikma ? 'Çıkma palet dönüşü'   : 'Yükleme palet kullanımı';
        $note_sarf  = $is_cikma ? 'Çıkma malzeme dönüşü' : 'Yükleme malzeme kullanımı';

        // A+B) Kasa & palet — loading_pallets + tanım LEFT JOIN (pasif tanım da dahil)
        $pal_st = $pdo->prepare("
            SELECT lp.id AS pallet_id, lp.depo, lp.kasa_adeti,
                   lp.kasa_cinsi_id, kc.name AS kasa_name, kc.type AS kasa_type, kc.unit_dara_kg AS kasa_dara,
                   lp.palet_tipi_id, pt.name AS palet_name, pt.type AS palet_type, pt.unit_dara_kg AS palet_dara
            FROM loading_pallets lp
            LEFT JOIN material_definitions kc ON kc.id = lp.kasa_cinsi_id
            LEFT JOIN material_definitions pt ON pt.id = lp.palet_tipi_id
            WHERE lp.loading_record_id = ?
            ORDER BY lp.id
        ");
        $pal_st->execute([$loading_record_id]);
        $pallets = $pal_st->fetchAll();

        // C) Sarf malzeme — pallet_materials (mevcut davranış korunur)
        $mat_st = $pdo->prepare("
            SELECT pm.loading_pallet_id, pm.material_id, pm.quantity, pm.total_dara_kg,
                   md.name AS mat_name, md.type AS mat_type, md.unit_dara_kg,
                   lp.depo
            FROM pallet_materials pm
            JOIN material_definitions md ON md.id = pm.material_id
            JOIN loading_pallets lp ON lp.id = pm.loading_pallet_id
            WHERE lp.loading_record_id = ?
            ORDER BY pm.loading_pallet_id, pm.id
        ");
        $mat_st->execute([$loading_record_id]);
        $materials = $mat_st->fetchAll();

        $pdo->beginTransaction();
        try {
            // Sadece bu kayda ait otomatik hareketleri temizle (idempotent)
            $pdo->prepare(
                "DELETE FROM material_stock_movements WHERE source_type='loading' AND source_id=?"
            )->execute([$loading_record_id]);

            $ins = $pdo->prepare("
                INSERT INTO material_stock_movements
                    (movement_date, movement_type, material_id, material_name, material_type,
                     depo, quantity, unit, unit_dara_kg, total_dara_kg,
                     source_type, source_id, source_detail_id, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'adet', ?, ?, 'loading', ?, ?, ?)
            ");

            foreach ($pallets as $p) {
                // A) Kasa kullanımı — kasa_cinsi_id dolu ve kasa_adeti > 0
                $kasa_qty = (float)$p['kasa_adeti'];
                if (!empty($p['kasa_cinsi_id']) && $kasa_qty > 0) {
                    $kasa_dara = (float)($p['kasa_dara'] ?? 0);
                    $ins->execute([
                        $lr_tarih, $mv_type,
                        (int)$p['kasa_cinsi_id'], $p['kasa_name'] ?? '', $p['kasa_type'] ?? 'kasa_cinsi',
                        $p['depo'], $kasa_qty,
                        $kasa_dara, round($kasa_qty * $kasa_dara, 3),
                        $loading_record_id, $p['pallet_id'], $note_kasa,
                    ]);
                }
                // B) Palet kullanımı — palet adedi kolonu yok; her loading_pallets satırı = 1 palet
                if (!empty($p['palet_tipi_id'])) {
                    $palet_dara = (float)($p['palet_dara'] ?? 0);
                    $ins->execute([
                        $lr_tarih, $mv_type,
                        (int)$p['palet_tipi_id'], $p['palet_name'] ?? '', $p['palet_type'] ?? 'palet_tipi',
                        $p['depo'], 1,
                        $palet_dara, round($palet_dara, 3),
                        $loading_record_id, $p['pallet_id'], $note_palet,
                    ]);
                }
            }

            // C) Sarf malzeme kullanımı
            foreach ($materials as $r) {
                if ((float)$r['quantity'] <= 0) continue;
                $ins->execute([
                    $lr_tarih, $mv_type,
                    $r['material_id'], $r['mat_name'], $r['mat_type'],
                    $r['depo'], $r['quantity'],
                    $r['unit_dara_kg'], $r['total_dara_kg'],
                    $loading_record_id, $r['loading_pallet_id'], $note_sarf,
                ]);
            }

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
        }
    } catch (PDOException $e) {}
}

// ── Kantar Hesaplama ─────────────────────────────────────
// Tek kaynak: kantar_view.php ve kantar_raporu.php ortak kullanır.

function kantar_calc(array $fis): array {
    $t1   = (float)($fis['tartim1'] ?? 0);
    $t2   = (float)($fis['tartim2'] ?? 0);
    $brut = max(0.0, $t1 - $t2);
    $ks   = (int)($fis['kasa_sayisi']  ?? 0);
    $ps   = (int)($fis['palet_sayisi'] ?? 0);
    $kdt  = (float)($fis['kasa_dara_total']  ?? 0);
    $pdt  = (float)($fis['palet_dara_total'] ?? 0);
    $kdu  = (float)($fis['kasa_dara']  ?? 0);
    $pdu  = (float)($fis['palet_dara'] ?? 0);
    $dara = ($kdt > 0 || $pdt > 0) ? $kdt + $pdt : $ks * $kdu + $ps * $pdu;
    $net  = max(0.0, $brut - $dara);
    return [
        'brut'      => $brut,
        'dara'      => $dara,
        'net'       => $net,
        'eff_kdu'   => ($kdt > 0 && $ks > 0) ? $kdt / $ks : $kdu,
        'eff_pdu'   => ($pdt > 0 && $ps > 0) ? $pdt / $ps : $pdu,
        'kasa_say'  => $ks,
        'palet_say' => $ps,
    ];
}

function kantar_grup_dist(array $gruplar, float $brut, float $eff_kdu, float $eff_pdu): array {
    $manual_sum  = 0.0;
    $auto_weight = 0;
    foreach ($gruplar as $g) {
        $gb = (float)($g['brut_kg'] ?? 0);
        if ($gb > 0) $manual_sum  += $gb;
        else         $auto_weight += (int)$g['kasa_adedi'] + (int)$g['palet_sayisi'];
    }
    $auto_pool = max(0.0, $brut - $manual_sum);
    $per_unit  = $auto_weight > 0 ? $auto_pool / $auto_weight : 0.0;
    $rows = [];
    foreach ($gruplar as $g) {
        $gp    = (int)$g['palet_sayisi'];
        $gk    = (int)$g['kasa_adedi'];
        $gb    = (float)($g['brut_kg'] ?? 0);
        $gbrut = $gb > 0 ? $gb : (($gp + $gk) * $per_unit);
        $gkd   = (float)($g['kasa_dara_kg']  ?? 0) ?: $eff_kdu;
        $gpd   = (float)($g['palet_dara_kg'] ?? 0) ?: $eff_pdu;
        $gdara = $gp * $gpd + $gk * $gkd;
        $gnet  = max(0.0, $gbrut - $gdara);
        $rows[] = [
            'firma'     => $g['grup_adi'] ?: '—',
            'palet'     => $gp,
            'kasa'      => $gk,
            'brut_kg'   => $gbrut,
            'dara_kg'   => $gdara,
            'net_kg'    => $gnet,
            'is_manual' => $gb > 0,
        ];
    }
    return $rows;
}

// ── İzin Verilen Çıkış Nedenleri ─────────────────────────
function cikis_nedeni_listesi(): array {
    return ['ÇIKMA', 'KÜÇÜK BOY (2.)', 'MEYSU', 'Fire', 'Kötü Ürün', 'Çürük', 'Iskarta', 'Numune', 'İç Kullanım', 'Düzeltme', 'Diğer'];
}
