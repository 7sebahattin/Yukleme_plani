<?php
// =========================================================
// config/print_helpers.php
// Yazdırma sayfaları için ortak yardımcılar (Sprint Print-Arch-01)
//
// Kullanım:
//   require_once __DIR__ . '/print_helpers.php';
//
// Bu dosya mevcut sayfaları etkilemez.
// include edilmediği sürece pasif kalır.
// =========================================================

declare(strict_types=1);

/**
 * URL'deki mode parametresini güvenli şekilde döner.
 * Geçerli değerler: 'detail' | 'summary'
 * Varsayılan: 'summary'
 */
function print_mode(): string {
    $mode = $_GET['mode'] ?? 'summary';
    return in_array($mode, ['detail', 'summary'], true) ? $mode : 'summary';
}

/**
 * Yazdırma sayfası yönünü belirler.
 * Öncelik: URL parametresi > mode + kolon sayısı kuralı
 *
 * @param string $mode       'detail' | 'summary'
 * @param int    $columnCount Tablo kolon sayısı (≥7 → landscape)
 * @return string 'portrait' | 'landscape'
 */
function print_orientation(string $mode, int $columnCount = 0): string {
    $param = $_GET['orientation'] ?? '';
    if (in_array($param, ['portrait', 'landscape'], true)) {
        return $param;
    }
    if ($mode === 'detail' || $columnCount >= 7) {
        return 'landscape';
    }
    return 'portrait';
}

/**
 * @page CSS bloğunu A4 + belirtilen yöne göre üretir.
 * Margin: landscape → 10mm 8mm, portrait → 12mm
 *
 * @param string $orientation 'portrait' | 'landscape'
 * @return string  <style>...</style> bloğu
 */
function print_page_style(string $orientation): string {
    if ($orientation === 'landscape') {
        $size   = 'A4 landscape';
        $margin = '10mm 8mm';
    } else {
        $size   = 'A4 portrait';
        $margin = '12mm';
    }
    return "<style>\n@page { size: {$size}; margin: {$margin}; }\n</style>\n";
}

/**
 * print-page body class dizesini üretir.
 *
 * @param string $module      Modül adı: 'loading' | 'cikma' | 'daily' | 'account' | 'kantar'
 * @param string $mode        'detail' | 'summary'
 * @param string $orientation 'portrait' | 'landscape'
 * @return string  Örn: "print-page print-loading print-detail print-landscape"
 */
function print_body_class(string $module, string $mode, string $orientation): string {
    return implode(' ', [
        'print-page',
        'print-' . $module,
        'print-' . $mode,
        'print-' . $orientation,
    ]);
}

/**
 * Yazdırma sayfası HTML başlık bloğunu ekrana basar.
 * - No sidebar, no bottomnav, no topbar
 * - print_base.css yüklenir
 * - CSRF token üretilmez (GET-only read sayfaları)
 *
 * @param string $title      Sayfa başlığı (browser tab + print header)
 * @param string $module     Modül adı
 * @param string $mode       'detail' | 'summary'
 * @param string $orientation 'portrait' | 'landscape'
 */
function render_print_page_start(
    string $title,
    string $module,
    string $mode,
    string $orientation
): void {
    $body_class = print_body_class($module, $mode, $orientation);
    $page_style = print_page_style($orientation);
    $asset_path = str_contains($_SERVER['SCRIPT_FILENAME'] ?? '', '/hks/')
        ? '../assets/'
        : 'assets/';
    echo "<!doctype html>\n";
    echo "<html lang=\"tr\"><head><meta charset=\"utf-8\">\n";
    echo "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n";
    echo "<title>" . htmlspecialchars($title, ENT_QUOTES) . "</title>\n";
    echo "<link rel=\"stylesheet\" href=\"{$asset_path}print_base.css\">\n";
    echo $page_style;
    echo "</head>\n";
    echo "<body class=\"{$body_class}\">\n";
}

/**
 * Yazdırma sayfası HTML kapanışını basar.
 * Opsiyonel: load sonrası otomatik print dialog açar.
 *
 * @param bool $auto_print true → window.print() otomatik tetiklenir
 */
function render_print_page_end(bool $auto_print = false): void {
    if ($auto_print) {
        echo "<script>window.addEventListener('load',function(){ window.print(); });</script>\n";
    }
    echo "</body></html>\n";
}

/**
 * Basit print başlık kutusu HTML'si döner (print-header div).
 *
 * @param string $title     Sol büyük başlık
 * @param string $subtitle  Sol alt başlık
 * @param string $meta      Sağ meta bilgisi (tarih, filtreler vb.)
 * @return string HTML
 */
function render_print_header_html(
    string $title,
    string $subtitle = '',
    string $meta = ''
): string {
    $html  = '<div class="print-header">';
    $html .= '<div>';
    $html .= '<div class="print-header-title">' . htmlspecialchars($title, ENT_QUOTES) . '</div>';
    if ($subtitle !== '') {
        $html .= '<div class="print-header-sub">' . htmlspecialchars($subtitle, ENT_QUOTES) . '</div>';
    }
    $html .= '</div>';
    if ($meta !== '') {
        $html .= '<div class="print-header-meta">' . htmlspecialchars($meta, ENT_QUOTES) . '</div>';
    }
    $html .= '</div>';
    return $html;
}
