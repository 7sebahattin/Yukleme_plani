<?php
// =========================================================
// config/helpers.php
// Görüntü yardımcıları, biçimleme, CSRF, flash, DB sorguları
// db.php tarafından require edilir.
// =========================================================

declare(strict_types=1);

// Uygulama sürüm damgası — sidebar altında gösterilir (Hal Bildirimi paneli
// PANEL_SURUM'u ile aynı amaç: sunucudaki dosyanın güncel olup olmadığını
// gözle doğrulamak). sw.js'teki CACHE_NAME sayısıyla EŞLENİR — anlamlı bir
// değişiklik yapıp SW cache'i artırdığınızda BU DEĞERİ DE aynı sayıya çekin.
if (!defined('APP_SURUM')) {
    define('APP_SURUM', 'v174');
}

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

// Edit formları için: DB değerini num()-uyumlu Türkçe formata çevirir.
// Türkçe: nokta=binler ayracı, virgül=ondalık → "24.300" veya "24.300,50"
// num("24.300") = 24300, num("24.300,50") = 24300.5  — doğru parse edilir.
// Sıfır/null → '' döner (input boş kalır).
function fmt_edit_num($v, int $decimals = 0): string
{
    if ($v === null || $v === '') return '';
    $f = (float)$v;
    if (abs($f) < 0.000001) return '';
    return number_format($f, $decimals, ',', '.');
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
        'tedarikci'     => 'Tedarikçi',
        'depo'          => 'Depo',
        'cikis_nedeni'  => 'Çıkış Nedeni',
        'bolge'         => 'Bölge',
        'urun'          => 'Ürün',
        'marka'         => 'Marka',
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
        // ── Sevkiyat / kayıt alanı tanımları (Sprint Öneri-01) ──
        // Yükleme kaydı formundaki serbest metin alanlarının öneri havuzu.
        // MALZEME DEĞİLLER — non_material_definition_types() ile stok
        // modülünün tür listelerinden hariç tutulurlar.
        'alici'           => 'Alıcı',
        'gumruk'          => 'Gümrük',
        'nakliye_sirketi' => 'Nakliye Şirketi',
        'sofor'           => 'Şoför',
        'telefon'         => 'Telefon',
        'on_plaka'        => 'Ön Plaka',
        'arka_plaka'      => 'Arka Plaka',
        'ulasim'          => 'Ulaşım',
        'gidecek_ulke'    => 'Gideceği Ülke',
    ];
}

// ── Malzeme OLMAYAN tanım türleri — TEK KAYNAK ────────────
// Stok modülü "Malzeme Türü" listeleri bu türleri göstermemeli.
// ms_material_types(), ms_excluded_types(), malzeme_stok_import ve
// reports.php hepsi buradan beslenir; yeni bir lookup türü eklenince
// tek yerde güncellenir (önceden 4 ayrı kopya vardı).
function non_material_definition_types(): array {
    return [
        'firma', 'tedarikci', 'depo', 'bolge', 'urun', 'marka', 'lokasyon', 'cikis_nedeni',
        'alici', 'gumruk', 'nakliye_sirketi', 'sofor', 'telefon', 'on_plaka', 'arka_plaka', 'ulasim', 'gidecek_ulke',
    ];
}

// ── PALETE GİYDİRİLEN SARF MALZEME TÜRLERİ — TEK KAYNAK ────
// "Malzeme Ekle" listeleri (record_view toplu modal + palet modalı) ve
// api_bulk_material doğrulaması buradan beslenir.
// Hariç tutulanlar:
//   - non_material_definition_types(): lookup/ticari türler (firma, depo,
//     alıcı, telefon, şoför, plaka…) — bunlar MALZEME DEĞİL.
//   - kasa_cinsi / palet_tipi: malzeme türü olsalar da yapısaldır, kendi
//     ayrı seçim alanları var; giydirme malzemesi olarak eklenmezler.
function pallet_material_types(): array {
    $skip = array_merge(non_material_definition_types(), ['kasa_cinsi', 'palet_tipi']);
    return array_values(array_diff(array_keys(definition_types()), $skip));
}

function is_pallet_material_type(string $type): bool {
    return in_array($type, pallet_material_types(), true);
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
    $in_hks = strpos($self, '/halkayit/') !== false;
    $cikma  = ($GLOBALS['_nav_cikma_hint'] ?? false) === true;

    $_fn   = function_exists('can');
    $p_dash  = !$_fn || can('dashboard.read');
    $p_rec   = !$_fn || can('records.read');
    $p_recw  = !$_fn || can('records.write');
    $p_kant  = !$_fn || can('kantar.read');
    $p_stok  = !$_fn || can('stok.read');
    $p_rep   = !$_fn || can('reports.read');
    $p_def   = !$_fn || can('defs.read');
    $p_usr   = $_fn && can('users.admin');
    $p_adm   = function_exists('is_admin') && is_admin();
    $p_beyan = !$_fn || can('beyan.read') || $p_adm;
    $p_mal   = ($_fn && can('maliyet.read')) || $p_adm;
    // Hesap: kendi yetkisi; hesap.* henüz seed edilmemiş kurulumlarda reports.read'e düşer
    $p_hes   = !$_fn || can('hesap.read') || can('reports.read') || $p_adm;

    // Aktif sayfa tespiti
    $a_home  = ($cur === 'index.php' || $cur === '') && !$in_hks;
    $a_yuk   = !$cikma && !$in_hks && in_array($cur, ['records.php','record_view.php','record_edit.php','record_create.php','record_new.php'], true);
    $a_cik   = $cikma || in_array($cur, ['cikmalar.php','cikma_create.php'], true);
    $a_kant  = in_array($cur, ['kantar.php','kantar_view.php'], true);
    $a_krap  = $cur === 'kantar_raporu.php';
    $a_hks   = $in_hks;
    $a_beyan = in_array($cur, ['beyanlar.php','beyan_create.php','beyan_edit.php','beyan_view.php','beyan_delete.php'], true);
    $a_ustok = $cur === 'stok.php';
    $a_mstok = in_array($cur, ['malzeme_stok.php', 'malzeme_stok_islem.php', 'malzeme_hareketleri.php',
                               'malzeme_stok_rapor.php', 'malzeme_stok_tehis.php', 'malzeme_stok_import.php'], true);
    $a_rep   = $cur === 'reports.php';
    $a_hes   = in_array($cur, ['hesap.php','hesap_liste.php','hesap_kayit.php','hesap_muhasebe.php',
                               'hesap_sil.php','hesap_muhasebe_fis_pdf.php'], true);
    $a_mal   = in_array($cur, ['maliyet.php','maliyet_form.php','maliyet_view.php',
                               'maliyet_sablon.php','maliyet_alanlar.php','maliyet_ambalaj.php'], true);
    $a_def   = $cur === 'definitions.php';
    $a_usr   = $cur === 'users.php';
    $a_aud   = $cur === 'audit.php';
    $a_bkp   = $cur === 'admin_db_backups.php';

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

    <?php if (function_exists('active_depot') && ($__sdp = active_depot()) !== null): ?>
    <a href="<?= $base ?>depo_sec.php?next=<?= urlencode($_SERVER['REQUEST_URI'] ?? '/') ?>"
       class="sidebar-depo" title="Depo değiştir">
        <span class="sidebar-depo-icon" aria-hidden="true">🏭</span>
        <span class="sidebar-depo-txt">
            <span class="sidebar-depo-label">Aktif Depo</span>
            <span class="sidebar-depo-name"><?= h($__sdp) ?></span>
        </span>
        <span class="sidebar-depo-caret" aria-hidden="true">▾</span>
    </a>
    <?php unset($__sdp); endif; ?>

    <nav class="sidebar-nav">
        <div class="sidebar-section">Operasyon</div>
        <?php if ($p_dash) $lnk('index.php',     '🏠', 'Ana Sayfa',     $a_home); ?>
        <?php if ($p_rec)  $lnk('records.php',   '📋', 'Yüklemeler',    $a_yuk);  ?>
        <?php if ($p_rec)   $lnk('cikmalar.php',  '🚚', 'Çıkmalar',  $a_cik);   ?>
        <?php if ($p_beyan) $lnk('beyanlar.php', '🧾', 'Beyanlar',  $a_beyan); ?>
        <?php if ($p_kant)  $lnk('kantar.php',   '⚖️', 'Kantar',    $a_kant);  ?>
        <?php if ($p_recw) $lnk('halkayit/index.php', '🏛', 'Hal Bildirimi', $a_hks);  ?>

        <?php if ($p_rep)  $lnk('reports.php', '📊', 'Raporlar', $a_rep); ?>
        <?php if ($p_stok) $lnk('malzeme_stok.php', '📦', 'Malzeme Stok', $a_mstok); ?>
        <?php if ($p_hes)  $lnk('hesap.php',   '🏦', 'Hesap',    $a_hes); ?>
        <?php if ($p_mal)  $lnk('maliyet.php', '🧮', 'Maliyet',  $a_mal); ?>

        <?php if ($p_def || $p_usr || $p_adm): ?>
        <div class="sidebar-section">Yönetim</div>
        <?php if ($p_def) $lnk('definitions.php', '⚙️', 'Tanımlar',       $a_def); ?>
        <?php if ($p_usr) $lnk('users.php',       '👥', 'Kullanıcılar',   $a_usr); ?>
        <?php if ($p_adm) $lnk('audit.php',       '🧾', 'İşlem Geçmişi',  $a_aud); ?>
        <?php if ($p_adm) $lnk('admin_db_backups.php', '🗄', 'Veritabanı Yedekleri', $a_bkp); ?>
        <?php endif; ?>
        <!-- Şema Migrasyon / Depo Taşıma / Tedarikçi Eşleştirme: menüden kaldırıldı
             (tek seferlik kurulum araçları) — dosyalar silinmedi, gerekirse
             doğrudan URL ile (migrate.php, depo_tasima.php, firma_eslestirme.php)
             admin erişebilir. render_desktop_sidebar'daki $a_mig/$a_dtas/$a_fes
             aktif-sayfa değişkenleri de bu yüzden burada bilinçli kullanılmıyor. -->
    </nav>

    <div class="sidebar-surum"><?= h(APP_SURUM) ?></div>

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
    <script>
    /* Tema (Açık/Koyu/Sistem) — CSS yüklenmeden ÖNCE uygulanır (beyaz parlama olmaz).
       localStorage 'asya_tema' ∈ acik|koyu|sistem. halkayit iframe'i aynı anahtarı
       okur; storage olayı ile tüm sekmeler/iframe'ler anında senkron değişir. */
    (function () {
        function oku() { try { return localStorage.getItem('asya_tema') || 'sistem'; } catch (e) { return 'sistem'; } }
        function uygula() {
            var t = oku();
            var koyu = t === 'koyu' || (t === 'sistem' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.setAttribute('data-theme', koyu ? 'dark' : 'light');
            var m = document.querySelector('meta[name="theme-color"]');
            if (m) m.setAttribute('content', koyu ? '#10151c' : '#1d6cf0');
            /* Seçici butonlarının aktif durumu */
            document.querySelectorAll('[data-tema]').forEach(function (b) {
                b.classList.toggle('aktif', b.getAttribute('data-tema') === t);
            });
            var d = document.getElementById('temaDongu');
            if (d) d.textContent = t === 'acik' ? '☀️' : (t === 'koyu' ? '🌙' : '🖥️');
        }
        window.asyaTemaSec = function (t) {
            try { localStorage.setItem('asya_tema', t); } catch (e) {}
            uygula();
        };
        window.asyaTemaDondur = function () {
            var sira = ['sistem', 'acik', 'koyu'];
            window.asyaTemaSec(sira[(sira.indexOf(oku()) + 1) % 3]);
        };
        uygula();
        try {
            matchMedia('(prefers-color-scheme: dark)').addEventListener('change', uygula);
            window.addEventListener('storage', function (e) { if (e.key === 'asya_tema') uygula(); });
            document.addEventListener('DOMContentLoaded', uygula); /* butonlar DOM'a gelince durumları işle */
        } catch (e) {}
    })();
    </script>
    <link rel="stylesheet" href="<?= $base ?>assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
</head>
<?php
    // Aktif depo rengi: CSS değişkeni olarak body'ye enjekte edilir.
    // Sidebar, topbar rozeti ve bottomnav bu değişkeni kullanarak depo değişince renk değiştirir.
    $__body_style = '';
    if (!$print_mode && function_exists('active_depot') && ($__hdp = active_depot()) !== null) {
        $__dc = depot_color($__hdp);
        $__body_style = ' style="--depot-accent:' . h($__dc)
            . ';--depot-accent-rgb:' . h(color_rgb_triplet($__dc))
            . ';--depot-accent-text:' . h(color_readable_text($__dc)) . ';"';
    }
?>
<body class="<?= $print_mode ? 'print-mode' : '' ?>"<?= $__body_style ?>>
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
            <?php if (!function_exists('can') || can('beyan.read') || (function_exists('is_admin') && is_admin())): ?>
            <a href="<?= $base ?>beyanlar.php" <?= in_array($cur, ['beyanlar.php','beyan_create.php','beyan_edit.php','beyan_view.php'], true) ? 'class="active"' : '' ?>>Beyanlar</a>
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
        <button type="button" class="tema-dongu" id="temaDongu" onclick="asyaTemaDondur()"
                title="Tema değiştir (Açık / Koyu / Sistem)" aria-label="Tema değiştir">🖥️</button>
        <?php if (function_exists('active_depot') && ($__adp = active_depot()) !== null): ?>
        <a href="<?= $base ?>depo_sec.php?next=<?= urlencode($_SERVER['REQUEST_URI'] ?? '/') ?>"
           class="depo-badge" title="Depo değiştir">
            <span class="depo-badge-icon" aria-hidden="true">🏭</span><span class="depo-badge-name"><?= h($__adp) ?></span><span class="depo-badge-caret" aria-hidden="true">▾</span>
        </a>
        <?php unset($__adp); endif; ?>
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
        $is_hks      = strpos((string)($_SERVER['REQUEST_URI'] ?? ''), '/halkayit/') !== false;
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
    <?php if (!function_exists('can') || can('records.write')): ?>
    <a href="<?= $base ?>halkayit/index.php" class="bottomnav-item bottomnav-raised<?= $is_hks ? ' active' : '' ?>">
        <span class="bottomnav-raised-circle">🏛</span>
        <span class="bottomnav-label">Bildirim</span>
    </a>
    <?php endif; ?>
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

// --- Şema yardımcı: kolon var mı? (request başına cache'li) ---
if (!function_exists('db_has_column')):
function db_has_column(string $table, string $column): bool {
    static $cache = [];
    $key = $table . '::' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        // NOT: "SHOW COLUMNS ... LIKE ?" native prepared statement'ta (EMULATE_PREPARES=false)
        // güvenilir çalışmaz → tüm kolonları çekip PHP'de kontrol et.
        $tbl  = str_replace('`', '', $table);
        $cols = db()->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_COLUMN);
        $cache[$key] = in_array($column, $cols, true);
    } catch (PDOException $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}
endif;

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
        } catch (PDOException $e) {
            error_log('[migration] loading_records temel kolonlar: ' . $e->getMessage());
        }

        // Sprint 34/36: marka (brand) — KENDİ try bloğunda (önceki ALTER hatası bunu atlamasın)
        try {
            $has_brand = $pdo->query("SHOW COLUMNS FROM `loading_records` LIKE 'brand'")->fetchColumn();
            if (!$has_brand) {
                $pdo->exec("ALTER TABLE `loading_records` ADD COLUMN `brand` VARCHAR(20) NULL");
            }
        } catch (PDOException $e) {
            error_log('[migration] brand kolonu eklenemedi (ALTER yetkisi?): ' . $e->getMessage());
        }

        // Sprint ÜrünSahibi-01: urun_sahibi_id — ayrı try bloğu
        try {
            $has_us = $pdo->query("SHOW COLUMNS FROM `loading_records` LIKE 'urun_sahibi_id'")->fetchColumn();
            if (!$has_us) {
                $pdo->exec("ALTER TABLE `loading_records` ADD COLUMN `urun_sahibi_id` INT NULL DEFAULT NULL");
            }
        } catch (PDOException $e) {
            error_log('[migration] urun_sahibi_id kolonu eklenemedi: ' . $e->getMessage());
        }

        // Etiket fotoğrafı (base64) — cihazlar arası görünsün diye DB'de saklanır
        try {
            $has_ef = $pdo->query("SHOW COLUMNS FROM `loading_records` LIKE 'etiket_foto'")->fetchColumn();
            if (!$has_ef) {
                $pdo->exec("ALTER TABLE `loading_records` ADD COLUMN `etiket_foto` MEDIUMTEXT NULL");
            }
        } catch (PDOException $e) {
            error_log('[migration] etiket_foto kolonu eklenemedi (ALTER yetkisi?): ' . $e->getMessage());
        }

        // Sprint 36: sarf (kasa/palet dışı) tanımlarda dara artık kullanılmıyor → sıfırla (idempotent)
        try {
            $pdo->exec("UPDATE `material_definitions` SET unit_dara_kg = 0
                        WHERE unit_dara_kg <> 0 AND type NOT IN ('kasa_cinsi','palet_tipi')");
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
            `user_id`                INT NULL,
            `created_by`             INT NULL,
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
            `status`                 VARCHAR(20) NOT NULL DEFAULT 'submitted',
            `submitted_at`           DATETIME NULL,
            `reviewed_by`            INT NULL,
            `reviewed_at`            DATETIME NULL,
            `review_note`            VARCHAR(500) NOT NULL DEFAULT '',
            `paid_at`                DATETIME NULL,
            `depo`                   VARCHAR(150) NOT NULL DEFAULT '',
            `notes`                  TEXT NOT NULL DEFAULT '',
            `has_files`              TINYINT(1) NOT NULL DEFAULT 0,
            `created_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`             TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_date` (`transaction_date`),
            INDEX `idx_type` (`type`),
            INDEX `idx_accountant` (`is_given_to_accountant`),
            INDEX `idx_at_user` (`user_id`),
            INDEX `idx_at_status` (`status`),
            INDEX `idx_at_depo` (`depo`(80))
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
            $all_p = ['dashboard.read','records.read','records.write','records.delete','records.lock','records.unlock','kantar.read','kantar.write','kantar.delete','stok.read','stok.write','defs.read','defs.write','defs.admin','reports.read','reports.export','users.read','users.write','users.admin','beyan.read','beyan.write','beyan.delete','maliyet.read','maliyet.write','maliyet.delete','maliyet.unlock','maliyet.admin','hesap.read','hesap.write','hesap.delete','hesap.approve','hesap.pay','hesap.admin'];
            $rp_map = [
                'admin'    => $all_p,
                'operator' => ['dashboard.read','records.read','records.write','records.lock','kantar.read','kantar.write','stok.read','stok.write','defs.read','reports.read','reports.export','beyan.read','beyan.write','maliyet.read','maliyet.write','hesap.read','hesap.write'],
                'viewer'   => ['dashboard.read','records.read','kantar.read','stok.read','defs.read','reports.read','beyan.read','hesap.read'],
                // Muhasebe rolü Hesap modülünün asıl kullanıcısı: kendi sayfasına
                // girebilmesi için hesap.write + onay/ödeme yetkileri şart.
                'muhasebe' => ['dashboard.read','records.read','stok.read','reports.read','reports.export','beyan.read','maliyet.read','maliyet.write','hesap.read','hesap.write','hesap.approve','hesap.pay'],
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

        // 11b) Depo sorumluluğu — user_depolar (kullanıcı → depo ataması)
        // Yeni tablo; mevcut veriye dokunmaz. Atama YOKSA kullanıcı kısıtsızdır (her şeyi görür).
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `user_depolar` (
                `user_id`    INT NOT NULL,
                `depo`       VARCHAR(100) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`, `depo`),
                INDEX `idx_ud_user` (`user_id`),
                INDEX `idx_ud_depo` (`depo`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (PDOException $e) { error_log('[migration user_depolar] ' . $e->getMessage()); }

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

        // Sprint Beyan-01: customs_declarations tablosu (idempotent)
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `customs_declarations` (
                `id`                 INT AUTO_INCREMENT PRIMARY KEY,
                `raw_text`           LONGTEXT NULL,
                `unmatched_text`     LONGTEXT NULL,
                `declaration_title`  VARCHAR(100) NULL,
                `company_name`       VARCHAR(255) NULL,
                `company_address`    TEXT NULL,
                `transport_type`     VARCHAR(100) NULL,
                `line_type`          VARCHAR(100) NULL,
                `party_no`           VARCHAR(100) NULL,
                `pallet_count`       INT NULL,
                `product_name`       VARCHAR(150) NULL,
                `product_variety`    VARCHAR(150) NULL,
                `gross_kg`           DECIMAL(12,3) NULL,
                `net_kg`             DECIMAL(12,3) NULL,
                `crate_count`        INT NULL,
                `crate_type`         VARCHAR(100) NULL,
                `exit_depot`         VARCHAR(150) NULL,
                `contact_person`     VARCHAR(150) NULL,
                `buyer_name`         VARCHAR(150) NULL,
                `brand`              VARCHAR(50) NULL,
                `status`             VARCHAR(50) NOT NULL DEFAULT 'beyan_acildi',
                `analysis_note`      TEXT NULL,
                `sample_taken_at`    DATETIME NULL,
                `analysis_result_at` DATETIME NULL,
                `loading_record_id`  INT NULL,
                `created_by`         INT NULL,
                `updated_by`         INT NULL,
                `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`         DATETIME NULL,
                `deleted_at`         DATETIME NULL,
                INDEX `idx_cd_status`  (`status`),
                INDEX `idx_cd_created` (`created_at`),
                INDEX `idx_cd_party`   (`party_no`),
                INDEX `idx_cd_buyer`   (`buyer_name`(80)),
                INDEX `idx_cd_product` (`product_name`(80)),
                INDEX `idx_cd_depot`   (`exit_depot`(80)),
                INDEX `idx_cd_deleted` (`deleted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (PDOException $e) {
            error_log('[Beyan-01 MIGRATION] customs_declarations: ' . $e->getMessage());
        }

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

// ── Türkçe Büyük Harf ────────────────────────────────────
// i→İ ve ı→I dahil tüm Türkçe karakterleri doğru büyütür.
// Boşlukları normalize eder; null → '' döner.
function tr_upper(?string $value): string
{
    if ($value === null) return '';
    $value = trim($value);
    if ($value === '') return '';
    $value = preg_replace('/\s+/u', ' ', $value);
    $value = str_replace(['i', 'ı'], ['İ', 'I'], $value);
    return mb_strtoupper($value, 'UTF-8');
}

function tr_upper_or_null($value): ?string
{
    $v = tr_upper(is_null($value) ? null : (string)$value);
    return $v === '' ? null : $v;
}

// ── Depo Rengi (Sprint Depo-02) ───────────────────────────
// Her depoya görsel bir renk atanır: admin definitions.php'den elle
// seçebilir (material_definitions.color); seçilmemişse isimden türetilen
// sabit bir palet rengi kullanılır (aynı depo her zaman aynı rengi alır).
function depot_color_palette(): array {
    return ['#2563eb', '#dc2626', '#16a34a', '#d97706', '#7c3aed', '#0891b2',
            '#db2777', '#65a30d', '#ea580c', '#0d9488', '#4f46e5', '#be123c'];
}

function depot_color(string $name): string {
    static $cache = [];
    if (isset($cache[$name])) return $cache[$name];
    $stored = null;
    try {
        $st = db()->prepare("SELECT color FROM material_definitions WHERE type='depo' AND name = ? LIMIT 1");
        $st->execute([$name]);
        $stored = $st->fetchColumn() ?: null;
    } catch (Throwable $e) { /* kolon henüz yoksa — otomatik palete düş */ }

    if (is_string($stored) && preg_match('/^#[0-9a-fA-F]{6}$/', $stored)) {
        return $cache[$name] = $stored;
    }
    $palette = depot_color_palette();
    $idx = crc32($name) % count($palette);
    return $cache[$name] = $palette[$idx];
}

// Verilen hex rengin üzerine beyaz mı koyu mu yazı okunur? (WCAG basit luminans)
function color_readable_text(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return '#ffffff';
    $r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
    $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return $lum > 0.6 ? '#111827' : '#ffffff';
}

// Hex rengi "r, g, b" formatına çevirir — CSS rgba(var(--x), alpha) kalıbı için.
// color-mix() gibi yeni CSS fonksiyonlarına gerek kalmadan eski tarayıcılarda da çalışır.
function color_rgb_triplet(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return '29,108,240';
    return hexdec(substr($hex, 0, 2)) . ',' . hexdec(substr($hex, 2, 2)) . ',' . hexdec(substr($hex, 4, 2));
}

function normalize_firma(string $v): string { return tr_upper($v); }
function normalize_urun(string $v): string  { return tr_upper($v); }
function normalize_depo(string $v): string  { return tr_upper($v); }

// ── Depo adı → veri yayma / eşitleme ─────────────────────
// Depo tanımı adı değişince (veya yalnızca büyük/küçük harf farkı oluşunca)
// tüm depo kolonlarını canonical (tanımdaki) yazıma çeker. TR-duyarsız eşleşme
// PHP tarafında yapılır — MySQL collation belirsizliğine takılmaz.
// $old boş verilirse yalnız casing eşitlemesi yapar; doluysa gerçek A→B rename.
// Dönen: güncellenen toplam satır sayısı.
function sync_depot_name_in_data(string $canonical, string $old = ''): int {
    $canon = trim($canonical);
    if ($canon === '') return 0;
    $fold = function (string $s): string {
        $s = strtr($s, ['İ'=>'i','I'=>'i','Ş'=>'s','Ğ'=>'g','Ü'=>'u','Ö'=>'o','Ç'=>'c',
                        'ı'=>'i','ş'=>'s','ğ'=>'g','ü'=>'u','ö'=>'o','ç'=>'c']);
        return mb_strtolower(trim($s), 'UTF-8');
    };
    // Hangi eski yazımlar canonical'a çekilecek? casing varyantları + (varsa) eski ad
    $targets = [$fold($canon)];
    if ($old !== '') $targets[] = $fold($old);

    $pdo = db();
    $cols = [
        ['loading_pallets',          'depo'],
        ['kantar_fisleri',           'depo'],
        ['material_stock_movements', 'depo'],
        ['stock_counts',             'depo'],
        ['customs_declarations',     'exit_depot'],
    ];
    $total = 0;
    foreach ($cols as [$t, $c]) {
        try {
            $vals = $pdo->query("SELECT DISTINCT `$c` FROM `$t` WHERE `$c` IS NOT NULL AND `$c` <> ''")
                ->fetchAll(PDO::FETCH_COLUMN);
            foreach ($vals as $v) {
                $v = (string)$v;
                if ($v !== $canon && in_array($fold($v), $targets, true)) {
                    $st = $pdo->prepare("UPDATE `$t` SET `$c` = ? WHERE `$c` = ?");
                    $st->execute([$canon, $v]);
                    $total += $st->rowCount();
                }
            }
        } catch (PDOException $e) { /* tablo/kolon yok — sessiz geç */ }
    }
    return $total;
}

// ── Tanım Tablosuna Otomatik Ekle ────────────────────────
// Case-insensitive kontrol; aynı isim iki kez eklenmez.
// type: 'firma' | 'depo' | 'urun'
function ensure_definition(string $type, string $name): void {
    $name = tr_upper($name);
    if ($name === '') return;
    try {
        $pdo = db();
        $st  = $pdo->prepare("SELECT name FROM material_definitions WHERE type = ?");
        $st->execute([$type]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) {
            if (tr_upper($n) === $name) return;
        }
        $pdo->prepare(
            "INSERT INTO material_definitions (type, name, unit_dara_kg, is_active) VALUES (?, ?, 0, 1)"
        )->execute([$type, $name]);
    } catch (PDOException $e) {}
}

// ═══════════════════════════════════════════════════════════
// ÖNERİLİ SERBEST METİN ALANLARI (Sprint Öneri-01)
// Yükleme kaydı formunda yazarken öneri sunulan, eşleşme yoksa
// "+ ekle" ile tanımlara kaydedilen alanlar.
// ═══════════════════════════════════════════════════════════

// form alanı => [tanım tipi, etiket, en fazla karakter, TR-büyük harf mi]
// 'max' = min(loading_records kolon uzunluğu, material_definitions.name=150)
function record_suggest_fields(): array {
    return [
        'alici'           => ['type' => 'alici',           'label' => 'alıcı',           'max' => 150, 'upper' => true],
        'gumruk'          => ['type' => 'gumruk',          'label' => 'gümrük',          'max' => 150, 'upper' => true],
        'nakliye_sirketi' => ['type' => 'nakliye_sirketi', 'label' => 'nakliye şirketi', 'max' => 150, 'upper' => true],
        'sofor_adi'       => ['type' => 'sofor',           'label' => 'şoför',           'max' => 150, 'upper' => true],
        'telefon'         => ['type' => 'telefon',         'label' => 'telefon',         'max' => 40,  'upper' => false],
        'on_plaka'        => ['type' => 'on_plaka',        'label' => 'ön plaka',        'max' => 30,  'upper' => true],
        'arka_plaka'      => ['type' => 'arka_plaka',      'label' => 'arka plaka',      'max' => 30,  'upper' => true],
        'ulasim'          => ['type' => 'ulasim',          'label' => 'ulaşım',          'max' => 100, 'upper' => true],
        'gidecek_ulke'    => ['type' => 'gidecek_ulke',    'label' => 'gideceği ülke',    'max' => 100, 'upper' => true],
        // DİKKAT: loading_records.brand VARCHAR(20) — tanım adı 150'ye kadar
        // olabilir ama kayda 20'den fazlası yazılamaz, o yüzden sınır 20.
        'brand'           => ['type' => 'marka',           'label' => 'marka',           'max' => 20,  'upper' => true],
    ];
}

// Alan değerini kanonik hâle getirir: boşluk sadeleştir, (telefon hariç)
// TR-büyük harfe çevir, kolon uzunluğuna göre kırp.
function normalize_suggest_value(string $field, string $raw): string {
    $meta = record_suggest_fields()[$field] ?? null;
    if ($meta === null) return '';
    $v = trim(str_replace("\xc2\xa0", ' ', $raw));
    $v = (string)preg_replace('/\s+/u', ' ', $v);
    if ($v === '') return '';
    $v = $meta['upper'] ? tr_upper($v) : $v;
    return mb_substr($v, 0, $meta['max'], 'UTF-8');
}

// Anlamlı bir değer mi? En az bir harf veya rakam içermeli —
// "-", "...", "??" gibi çöp tanımların oluşmasını engeller.
function suggest_value_is_meaningful(string $v): bool {
    return $v !== '' && (bool)preg_match('/[\p{L}\p{N}]/u', $v);
}

// ── loading_records metin kolonlarının uzunluk sınırları ───
// INSERT/UPDATE öncesi kırpma için. Strict mode'da uzun değer hata
// verir, strict değilse SESSİZCE kırpılır — ikisi de istenmez.
function loading_record_text_limits(): array {
    return [
        'firma' => 150, 'bolge' => 150, 'parti_no' => 80, 'gumruk' => 150,
        'sofor_adi' => 150, 'fatura_no' => 80, 'casus_no' => 80,
        'on_plaka' => 30, 'arka_plaka' => 30, 'nakliye_sirketi' => 150,
        'telefon' => 40, 'ulasim' => 100, 'gidecek_ulke' => 100, 'alici' => 150, 'urun' => 150,
        'etiket' => 255, 'cikis_nedeni' => 100, 'brand' => 20,
    ];
}

// ── Marka doğrulama (TR-duyarlı) ──────────────────────────
// brand serbest metin kutusu oldu; tanımlarda olmayan değer sessizce
// NULL'lanmasın diye çağıran taraf hata gösterebilsin.
// strtoupper() ASCII'dir: "cihat karaköse" → "CIHAT KARAKöSE" olur ve
// "CİHAT KARAKÖSE" ile EŞLEŞMEZ. Bu yüzden tr_upper ile karşılaştırılır.
// Döner: [kanonik_ad|null, gecerli_mi]  (boş değer → [null, true])
function resolve_brand_value(string $raw): array {
    $v = tr_upper($raw);
    if ($v === '') return [null, true];
    try {
        $names = db()->query("SELECT name FROM material_definitions
                              WHERE type='marka' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $names = ['ASYA', 'URAL', 'URAS', 'AGRO'];
    }
    foreach ($names as $n) {
        if (tr_upper((string)$n) === $v) return [mb_substr((string)$n, 0, 20, 'UTF-8'), true];
    }
    return [null, false];   // tanımlarda yok → çağıran hata üretmeli
}

// ── Şoför → telefon eşlemesi ──────────────────────────────
// Şoför adı seçilince telefonu otomatik dolsun diye. Her şoför için EN SON
// kullanılan telefon alınır. Aktif depo kapsamı + son 24 ay ile sınırlı
// (record_suggest_lists ile aynı kurallar).
function record_sofor_phone_map(): array {
    $map = [];
    try {
        if (!function_exists('depo_sql_records_in')) return [];
        foreach (['sofor_adi', 'telefon'] as $c) {
            if (function_exists('db_has_column') && !db_has_column('loading_records', $c)) return [];
        }
        [$depo_sql, $params] = depo_sql_records_in('loading_records');
        $sql = "SELECT sofor_adi, telefon FROM loading_records
                WHERE sofor_adi <> '' AND telefon <> ''
                  AND (tarih IS NULL OR tarih >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH))"
             . ($depo_sql !== '' ? " AND $depo_sql" : '')
             . " ORDER BY tarih ASC, id ASC";   // sonraki satır öncekini ezer → en güncel kalır
        $st = db()->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(string)$r['sofor_adi']] = (string)$r['telefon'];
        }
    } catch (Throwable $e) {
        error_log('[record_sofor_phone_map] ' . $e->getMessage());
    }
    return $map;
}

// Kayıt dizisindeki metin alanlarını kolon uzunluğuna göre kırpar.
function clamp_loading_record_fields(array $record): array {
    foreach (loading_record_text_limits() as $col => $len) {
        if (!array_key_exists($col, $record)) continue;
        if (!is_string($record[$col])) continue;
        if (mb_strlen($record[$col], 'UTF-8') > $len) {
            $record[$col] = mb_substr($record[$col], 0, $len, 'UTF-8');
        }
    }
    return $record;
}

// ── Öneri havuzu ──────────────────────────────────────────
// Her alan için: tanımlar (material_definitions) ∪ geçmiş kayıtlardaki
// mevcut değerler. Böylece özellik ilk günden dolu listeyle çalışır.
// Geçmiş değerler AKTİF DEPO kapsamıyla süzülür (depo izolasyonu).
function record_suggest_lists(): array {
    $fields = record_suggest_fields();
    $out = [];
    foreach ($fields as $f => $m) $out[$f] = [];

    // 1) Tanımlar
    try {
        $types = array_column($fields, 'type');
        $ph = implode(',', array_fill(0, count($types), '?'));
        $st = db()->prepare("SELECT type, name FROM material_definitions
                             WHERE type IN ($ph) AND is_active = 1");
        $st->execute($types);
        $byType = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byType[$r['type']][] = (string)$r['name'];
        }
        foreach ($fields as $f => $m) {
            if (!empty($byType[$m['type']])) $out[$f] = $byType[$m['type']];
        }
    } catch (Throwable $e) {
        error_log('[record_suggest_lists] tanımlar: ' . $e->getMessage());
    }

    // 2) Geçmiş kayıtlar (aktif depo kapsamında)
    try {
        // depo_sql_records_in() config/auth.php'de tanımlı. Bu dosya auth.php
        // olmadan yüklenirse depo süzgeci UYGULANAMAZ — o durumda geçmiş
        // değerleri hiç okumayız (başka deponun verisi sızmasın).
        if (!function_exists('depo_sql_records_in')) {
            throw new RuntimeException('depo_sql_records_in yok — geçmiş öneriler atlandı');
        }
        [$depo_sql, $depo_params] = depo_sql_records_in('loading_records');
        // Yalnız SON 24 AY: form her açılışta çalışan bir sorgu, tablo
        // büyüdükçe maliyeti sabit kalsın. Eski alıcı/şoför zaten öneri
        // olarak anlamlı değil; kalıcı olması gerekenler tanıma eklenir.
        $recent = " AND (tarih IS NULL OR tarih >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH))";
        $parts = [];
        $params = [];
        foreach ($fields as $f => $m) {
            // Eski veritabanında olmayan kolon (ör. ulasim) TÜM sorguyu
            // düşürmesin — o alanı listeden çıkar, diğerleri çalışsın.
            if (function_exists('db_has_column') && !db_has_column('loading_records', $f)) continue;
            $w = "`$f` <> ''" . $recent . ($depo_sql !== '' ? " AND $depo_sql" : '');
            $parts[] = "SELECT '" . $f . "' AS k, `$f` AS v FROM loading_records WHERE $w";
            foreach ($depo_params as $dp) $params[] = $dp;
        }
        if (empty($parts)) throw new RuntimeException('öneri kolonu yok');
        $st = db()->prepare(implode(' UNION ', $parts));
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['k']][] = (string)$r['v'];
        }
    } catch (Throwable $e) {
        error_log('[record_suggest_lists] geçmiş: ' . $e->getMessage());
    }

    // 3) TR-duyarsız tekilleştir + sırala
    foreach ($out as $f => $vals) {
        $seen = [];
        $uniq = [];
        foreach ($vals as $v) {
            $v = trim($v);
            if ($v === '') continue;
            $key = function_exists('depo_fold') ? depo_fold($v) : mb_strtolower($v, 'UTF-8');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $uniq[] = $v;
        }
        usort($uniq, fn($a, $b) => strcoll($a, $b) ?: strcmp($a, $b));
        $out[$f] = $uniq;
    }
    return $out;
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

// ── Malzeme hesaplama bazı: kasa mı, palet mi? ──────────────
// 'kasa'  → effective_qty = quantity × kasa_adeti
// 'palet' → effective_qty = quantity
// KURAL (tek-çarpım): kasa-bazlı tüm malzemeler DB'ye HAM (girilen) adet olarak yazılır;
// kasa_adeti ile çarpım YALNIZCA burada (görüntü/stok/excel hesabında) bir kez yapılır.
// Böylece ekle→düzenle döngüsünde çift çarpım/balon (ör. 8→896→100352) oluşamaz.
function material_calc_basis(string $type, string $name): string {
    // kasa_etiketi/viyol gibi türler kendi ismiyle (ör. "30X50 SİYAH 12") tanımlanır,
    // isimde "kasa"/"viyol" geçmez — bu yüzden tür doğrudan kontrol edilir.
    if ($type === 'kasa_etiketi' || $type === 'viyol') return 'kasa'; // ham sakla, çarpımı burada yap (tek nokta)

    // İsim normalizasyonu (tr_norm benzeri, helpers.php içi)
    $n = mb_strtolower($name, 'UTF-8');
    $n = str_replace("\xCC\x87", '', $n); // combining dot above (İ → i)
    $n = strtr($n, ['ı' => 'i', 'ş' => 's', 'ç' => 'c', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o']);
    $n = str_replace([' ', '-', '.', ',', '_'], '', $n);

    // Kasa bazlı: her kasa için hesaplanır
    $kasa_kw = ['kenarkartonu', 'kenarkart', 'kenarkagidi', 'kenarkagit', 'tabankagidi', 'tabankagit', 'sale', 'kasaetiketi'];
    foreach ($kasa_kw as $kw) {
        if (str_contains($n, $kw)) return 'kasa';
    }
    return 'palet';
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

        // Sprint 36: Çıkma kayıtları artık stok hareketi ÜRETMEZ.
        // Daha önce oluşmuş otomatik (loading kaynaklı) hareketleri temizle ve çık.
        if ($is_cikma) {
            try {
                $pdo->prepare("DELETE FROM material_stock_movements WHERE source_type='loading' AND source_id=?")
                    ->execute([$loading_record_id]);
            } catch (PDOException $e) {}
            return;
        }

        $mv_type  = 'kullanim';
        $lr_tarih = $lr['tarih'] ?: date('Y-m-d');

        // Okunabilir açıklamalar (yalnız yükleme)
        $note_kasa  = 'Yükleme kasa kullanımı';
        $note_palet = 'Yükleme palet kullanımı';
        $note_sarf  = 'Yükleme malzeme kullanımı';

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

        // C) Sarf malzeme — pallet_materials + kasa_adeti (effective_qty hesabı için)
        $mat_st = $pdo->prepare("
            SELECT pm.loading_pallet_id, pm.material_id, pm.quantity, pm.total_dara_kg,
                   md.name AS mat_name, md.type AS mat_type, md.unit_dara_kg,
                   lp.depo, lp.kasa_adeti
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

            // C) Sarf malzeme kullanımı — kasa bazlı malzemeler kasa_adeti ile çarpılır
            foreach ($materials as $r) {
                if ((float)$r['quantity'] <= 0) continue;
                $basis   = material_calc_basis($r['mat_type'], $r['mat_name']);
                $eff_qty = ($basis === 'kasa')
                    ? round((float)$r['quantity'] * (int)($r['kasa_adeti'] ?? 0), 3)
                    : (float)$r['quantity'];
                if ($eff_qty <= 0) continue;
                $ins->execute([
                    $lr_tarih, $mv_type,
                    $r['material_id'], $r['mat_name'], $r['mat_type'],
                    $r['depo'], $eff_qty,
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
// Çıkış nedenleri — Tanımlar'dan yönetilir (type='cikis_nedeni').
// db.php ilk açılışta eski sabit listeyi tanım olarak seed eder.
// Tanım tablosu yoksa/boşsa eski sabit listeye düşer (fail-safe).
function cikis_nedeni_listesi(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $rows = db()->query(
            "SELECT name FROM material_definitions WHERE type='cikis_nedeni' AND is_active=1 ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($rows)) return $cache = $rows;
    } catch (Throwable $e) { /* tanım tablosu yok — fallback */ }
    return $cache = ['ÇIKMA', 'KÜÇÜK BOY (2.)', 'MEYSU', 'Fire', 'Kötü Ürün', 'Çürük', 'Iskarta', 'Numune', 'İç Kullanım', 'Düzeltme', 'Diğer'];
}

// ── Beyan Modülü Yardımcıları (Sprint Beyan-01) ──────────

function beyan_statuses(): array {
    return [
        'taslak'          => ['label' => 'TASLAK',          'css' => 'taslak'],
        'beyan_acildi'    => ['label' => 'AÇILDI',          'css' => 'beyan_acildi'],
        'numune_bekliyor' => ['label' => 'NUMUNE',          'css' => 'numune_bekliyor'],
        'analiz_bekliyor' => ['label' => 'ANALİZ',          'css' => 'analiz_bekliyor'],
        'temiz'           => ['label' => 'TEMİZ',           'css' => 'temiz'],
        'red'             => ['label' => 'RED',             'css' => 'red'],
        'iptal'           => ['label' => 'İPTAL',           'css' => 'iptal'],
        'yukleme_olustu'  => ['label' => 'YÜKLEME OLUŞTU', 'css' => 'yukleme_olustu'],
        'yuklendi'        => ['label' => 'YÜKLENDİ',       'css' => 'yuklendi'],
    ];
}

function beyan_badge_html(string $status): string {
    $statuses = beyan_statuses();
    $s = $statuses[$status] ?? ['label' => $status, 'css' => 'taslak'];
    return '<span class="beyan-badge beyan-badge-' . h($s['css']) . '">' . h($s['label']) . '</span>';
}

function beyan_next_statuses(string $current): array {
    $map = [
        'taslak'          => ['beyan_acildi', 'iptal'],
        'beyan_acildi'    => ['numune_bekliyor', 'analiz_bekliyor', 'iptal'],
        'numune_bekliyor' => ['analiz_bekliyor', 'iptal'],
        'analiz_bekliyor' => ['temiz', 'red', 'iptal'],
        'temiz'           => ['yukleme_olustu', 'iptal'],
        'red'             => ['iptal'],
        'iptal'           => [],
        'yukleme_olustu'  => ['yuklendi'],
        'yuklendi'        => [],
    ];
    return $map[$current] ?? [];
}

function can_beyan(string $perm): bool {
    if (!function_exists('can')) return true;
    return can('beyan.' . $perm) || (function_exists('is_admin') && is_admin());
}

// ── Sayı normalize: zaten sayı ise dokunma (parser JSON'undan gelen float
//    "." round-trip'inde num() tarafından binlik sanılıp bozulmasın). ──
function beyan_num_or_null($v): ?float {
    if ($v === null || $v === '') return null;
    if (is_int($v) || is_float($v)) return (float)$v;     // parser/JSON sayısı — olduğu gibi
    return num((string)$v);                                // elle girilen Türkçe format
}
function beyan_int_or_null($v): ?int {
    if ($v === null || $v === '') return null;
    if (is_int($v))   return $v;
    if (is_float($v)) return (int)$v;
    return (int)num((string)$v);
}

// ── Tek bir beyanı DB'ye ekler, yeni id döner (toplu içe aktarma için ortak yol). ──
// $data: parser/JSON alanları (string ya da sayı). Numune/analiz tarihleri set edilmez.
function beyan_insert(array $data, int $user_id): int {
    $str_fields = [
        'raw_text', 'unmatched_text', 'declaration_title', 'company_name', 'company_address',
        'transport_type', 'line_type', 'party_no', 'product_name', 'product_variety',
        'crate_type', 'exit_depot', 'contact_person', 'buyer_name', 'brand', 'analysis_note',
    ];
    $f = [];
    foreach ($str_fields as $k) $f[$k] = trim((string)($data[$k] ?? ''));

    // Büyük harf — seçili metin alanları (raw_text / unmatched / not hariç)
    foreach (['declaration_title', 'company_name', 'company_address', 'transport_type',
              'line_type', 'party_no', 'product_name', 'product_variety', 'crate_type',
              'exit_depot', 'contact_person', 'buyer_name', 'brand'] as $k) {
        if ($f[$k] !== '') $f[$k] = tr_upper($f[$k]);
    }

    $gross_kg  = beyan_num_or_null($data['gross_kg']     ?? null);
    $net_kg    = beyan_num_or_null($data['net_kg']       ?? null);
    $pallet_ct = beyan_int_or_null($data['pallet_count'] ?? null);
    $crate_ct  = beyan_int_or_null($data['crate_count']  ?? null);

    $status = (string)($data['status'] ?? 'beyan_acildi');
    if (!in_array($status, array_keys(beyan_statuses()), true)) $status = 'beyan_acildi';

    $st = db()->prepare("INSERT INTO customs_declarations
        (raw_text, unmatched_text, declaration_title, company_name, company_address,
         transport_type, line_type, party_no, pallet_count, product_name, product_variety,
         gross_kg, net_kg, crate_count, crate_type, exit_depot, contact_person,
         buyer_name, brand, status, analysis_note,
         created_by, updated_by, created_at, updated_at)
        VALUES
        (?, ?, ?, ?, ?,
         ?, ?, ?, ?, ?, ?,
         ?, ?, ?, ?, ?, ?,
         ?, ?, ?, ?,
         ?, ?, NOW(), NOW())");

    $st->execute([
        $f['raw_text']          ?: null,
        $f['unmatched_text']    ?: null,
        $f['declaration_title'] ?: null,
        $f['company_name']      ?: null,
        $f['company_address']   ?: null,
        $f['transport_type']    ?: null,
        $f['line_type']         ?: null,
        $f['party_no']          ?: null,
        $pallet_ct,
        $f['product_name']      ?: null,
        $f['product_variety']   ?: null,
        $gross_kg,
        $net_kg,
        $crate_ct,
        $f['crate_type']        ?: null,
        $f['exit_depot']        ?: null,
        $f['contact_person']    ?: null,
        $f['buyer_name']        ?: null,
        $f['brand']             ?: null,
        $status,
        $f['analysis_note']     ?: null,
        $user_id,
        $user_id,
    ]);

    return (int)db()->lastInsertId();
}
