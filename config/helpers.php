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
    ?><!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="csrf-token" content="<?= h($token) ?>">
    <title><?= h($title) ?> · Yükleme Planı</title>
    <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
</head>
<body class="<?= $print_mode ? 'print-mode' : '' ?>">
<?php if (!$print_mode): ?>
<header class="topbar">
    <div class="topbar-inner">
        <a href="index.php" class="brand">
            <span class="brand-logo">📦</span>
            <span class="brand-text">Yükleme Planı</span>
        </a>
        <nav class="topnav">
            <a href="records.php">Kayıtlar</a>
            <a href="cikmalar.php">Çıkmalar</a>
            <a href="definitions.php">Tanımlar</a>
        </nav>
    </div>
</header>
<?php endif; ?>
<main class="container">
<?php
}

function render_footer(bool $print_mode = false): void {
    if (!$print_mode) {
        echo '</main><script src="assets/app.js"></script></body></html>';
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
