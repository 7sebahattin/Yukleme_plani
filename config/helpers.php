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
    if (is_numeric($v)) return (float)$v;
    $s = str_replace([' ', "\xc2\xa0"], '', (string)$v);
    $s = str_replace(',', '.', $s);
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

function csrf_check(?string $token): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf']) || !is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(400);
        die('Güvenlik doğrulaması başarısız (CSRF).');
    }
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

// --- Kg biçimle: sondaki gereksiz sıfırları kaldır ---
function fmt_kg($v): string {
    $s = number_format((float)$v, 3, ',', '.');
    $s = rtrim($s, '0');
    $s = rtrim($s, ',');
    return $s;
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
        'urun'          => 'Ürün',
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

// --- Ortak header/footer parçaları ---
function render_header(string $title, bool $print_mode = false): void {
    $token = csrf_token();
    $cur = basename($_SERVER['PHP_SELF'] ?? '');
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
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="assets/icon.svg">
    <title><?= h($title) ?> · Asya Fresh</title>
    <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
</head>
<body class="<?= $print_mode ? 'print-mode' : '' ?>">
<?php if (!$print_mode): ?>
<header class="topbar">
    <div class="topbar-inner">
        <a href="index.php" class="brand">
            <span class="brand-logo">🌿</span>
            <span class="brand-text">Asya Fresh</span>
        </a>
        <nav class="topnav">
            <a href="records.php" <?= in_array($cur, ['records.php']) ? 'class="active"' : '' ?>>Yüklemeler</a>
            <a href="cikmalar.php" <?= $cur === 'cikmalar.php' ? 'class="active"' : '' ?>>Çıkmalar</a>
            <a href="reports.php" <?= $cur === 'reports.php' ? 'class="active"' : '' ?>>Raporlar</a>
            <a href="definitions.php" <?= $cur === 'definitions.php' ? 'class="active"' : '' ?>>Tanımlar</a>
        </nav>
    </div>
</header>
<?php endif; ?>
<main class="container">
<?php
    // PWA service worker kaydı
    if (!$print_mode): ?>
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(function(){});
}
</script>
<?php endif;
}

function render_footer(bool $print_mode = false): void {
    if (!$print_mode) {
        $cur = basename($_SERVER['PHP_SELF'] ?? '');
        $is_home     = in_array($cur, ['index.php', '']);
        $_cikma_hint = ($GLOBALS['_nav_cikma_hint'] ?? false) === true;
        $is_records  = !$_cikma_hint && in_array($cur, ['records.php', 'record_view.php', 'record_create.php', 'record_edit.php', 'record_new.php']);
        $is_cikmalar = in_array($cur, ['cikmalar.php', 'cikma_create.php']) || $_cikma_hint;
        $is_defs     = $cur === 'definitions.php';
        $is_notes    = $cur === 'notes.php';
        echo '</main>';
        ?>
<nav class="bottomnav" role="navigation" aria-label="Ana gezinme">
    <a href="index.php" class="bottomnav-item<?= $is_home ? ' active' : '' ?>">
        <span class="bottomnav-icon">🏠</span>
        <span class="bottomnav-label">Ana Sayfa</span>
    </a>
    <a href="records.php" class="bottomnav-item<?= $is_records ? ' active' : '' ?>">
        <span class="bottomnav-icon">📋</span>
        <span class="bottomnav-label">Yüklemeler</span>
    </a>
    <button type="button" id="notesOpenBtn" class="bottomnav-item bottomnav-notes<?= $is_notes ? ' active' : '' ?>">
        <span class="bottomnav-notes-circle">📝</span>
        <span class="bottomnav-label">Not</span>
    </button>
    <a href="cikmalar.php" class="bottomnav-item<?= $is_cikmalar ? ' active' : '' ?>">
        <span class="bottomnav-icon">🚚</span>
        <span class="bottomnav-label">Çıkmalar</span>
    </a>
    <a href="definitions.php" class="bottomnav-item<?= $is_defs ? ' active' : '' ?>">
        <span class="bottomnav-icon">⚙️</span>
        <span class="bottomnav-label">Tanımlar</span>
    </a>
</nav>

<!-- ── Notlar Modal ── -->
<div id="notesModal" class="notes-overlay" hidden aria-modal="true" role="dialog">
    <div class="notes-modal">
        <div class="notes-modal-head">
            <span class="notes-modal-title">📝 Not Ekle</span>
            <div class="notes-modal-head-actions">
                <a href="notes.php" class="btn btn-sm btn-ghost">Tümü →</a>
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
    var csrf  = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
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
    var pageName = (document.title || '').replace(' · Yükleme Planı', '').trim();

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
        fetch('note_save.php')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                listEl.innerHTML = '';
                if (!d.notes || !d.notes.length) {
                    listEl.innerHTML = '<div class="notes-empty">Henüz not yok.</div>';
                    return;
                }
                d.notes.forEach(function (n) {
                    listEl.appendChild(renderNote(n));
                });
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
        postJson('note_save.php', { page_url: pageUrl, page_name: pageName, note: note })
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
                    checkBtn.disabled = false;
                    if (!d.ok) { alert(d.msg || 'Hata'); return; }
                    if (d.done) {
                        row.classList.add('notes-done');
                        checkBtn.textContent = '↩'; checkBtn.title = 'Geri Al';
                    } else {
                        row.classList.remove('notes-done');
                        checkBtn.textContent = '✓'; checkBtn.title = 'Tamamlandı';
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
        echo '<script src="assets/app.js"></script></body></html>';
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

        // 4) Depo/Ürün tanımlarını normalize et
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
    } catch (PDOException $e) {}
})();

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
