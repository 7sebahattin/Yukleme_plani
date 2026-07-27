<?php
// =========================================================
// tarama.php — Görsel → Metin (OCR) tarama sayfası
//
// Tek dosya: PHP + HTML + CSS + JS burada toplanmıştır.
// (assets/style.css ve assets/app.js'e dokunulmaz.)
//
// Akış:
//   1) Kullanıcı resim yükler / sürükler / panodan yapıştırır (Ctrl+V)
//   2) "Tara" → dosya geçici klasöre alınır, Tesseract shell_exec ile çalışır
//   3) Metin sayfada gösterilir; .txt veya .xlsx olarak indirilebilir
//   4) Geçici dosyalar her durumda (hata dahil) silinir
//
// PHP 7.4+ uyumlu — str_contains/match/union type kullanılmaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('records.read');

// ── Sabitler ─────────────────────────────────────────────
const TARAMA_MAX_BYTES  = 10 * 1024 * 1024;   // 10 MB
const TARAMA_MAX_TEXT   = 2 * 1024 * 1024;    // dışa aktarımda metin sınırı
const TARAMA_TIMEOUT_S  = 120;                // tesseract azami süre

/** Kabul edilen görsel türleri: IMAGETYPE_* => uzantı */
function tarama_allowed_types(): array {
    $t = [
        IMAGETYPE_JPEG    => 'jpg',
        IMAGETYPE_PNG     => 'png',
        IMAGETYPE_TIFF_II => 'tif',
        IMAGETYPE_TIFF_MM => 'tif',
        IMAGETYPE_BMP     => 'bmp',
    ];
    if (defined('IMAGETYPE_WEBP')) $t[IMAGETYPE_WEBP] = 'webp';
    return $t;
}

/** Sayfa düzeni (tesseract --psm) seçenekleri */
function tarama_psm_options(): array {
    return [
        3  => 'Otomatik (varsayılan)',
        4  => 'Sütunlu metin',
        6  => 'Tek metin bloğu',
        7  => 'Tek satır',
        11 => 'Dağınık metin (fiş / etiket)',
    ];
}

/** ini disable_functions kontrolü — shell_exec kapalı olabilir. */
function tarama_fn_available(string $fn): bool {
    if (!function_exists($fn)) return false;
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array($fn, $disabled, true);
}

/** Tesseract çalıştırılabilir yolunu bulur; yoksa null. */
function tarama_tesseract_bin(): ?string {
    static $cache = false;
    if ($cache !== false) return $cache;

    $cands = [];
    // 1) config/local.php içinde tanımlanabilir: define('TESSERACT_BIN', '/usr/bin/tesseract');
    if (defined('TESSERACT_BIN') && is_string(TESSERACT_BIN) && TESSERACT_BIN !== '') {
        $cands[] = TESSERACT_BIN;
    }
    $env = getenv('TESSERACT_BIN');
    if (is_string($env) && $env !== '') $cands[] = $env;

    // 2) PATH üzerinden
    if (tarama_fn_available('shell_exec')) {
        $found = @shell_exec('command -v tesseract 2>/dev/null');
        if (is_string($found)) {
            foreach (explode("\n", $found) as $line) {
                $line = trim($line);
                if ($line !== '') $cands[] = $line;
            }
        }
    }

    // 3) Yaygın konumlar
    $cands[] = '/usr/bin/tesseract';
    $cands[] = '/usr/local/bin/tesseract';
    $cands[] = '/opt/homebrew/bin/tesseract';
    $cands[] = '/bin/tesseract';

    foreach ($cands as $c) {
        if ($c !== '' && @is_file($c) && @is_executable($c)) {
            $cache = $c;
            return $cache;
        }
    }
    $cache = null;
    return null;
}

/** Tesseract sürümü (ilk satır) — bilgi amaçlı. */
function tarama_tesseract_version(): string {
    $bin = tarama_tesseract_bin();
    if ($bin === null || !tarama_fn_available('shell_exec')) return '';
    $out = @shell_exec(escapeshellarg($bin) . ' --version 2>&1');
    if (!is_string($out)) return '';
    $first = trim(strtok($out, "\n"));
    return $first === false ? '' : $first;
}

/** Kurulu dil paketleri (osd hariç). */
function tarama_installed_langs(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $bin = tarama_tesseract_bin();
    if ($bin === null || !tarama_fn_available('shell_exec')) return $cache = [];

    $out = @shell_exec(escapeshellarg($bin) . ' --list-langs 2>&1');
    $langs = [];
    if (is_string($out)) {
        foreach (explode("\n", $out) as $line) {
            $line = trim($line);
            // Başlık satırını ve boşları atla
            if ($line === '' || strpos($line, ' ') !== false || strpos($line, ':') !== false) continue;
            if ($line === 'osd') continue;
            if (preg_match('/^[a-zA-Z_]{2,20}$/', $line)) $langs[] = $line;
        }
    }
    return $cache = array_values(array_unique($langs));
}

/** Kullanıcıya sunulacak dil seçenekleri: kod => etiket */
function tarama_lang_options(): array {
    $inst = tarama_installed_langs();
    $has  = function (string $l) use ($inst) { return in_array($l, $inst, true); };

    $opts = [];
    if ($has('tur') && $has('eng')) $opts['tur+eng'] = 'Türkçe + İngilizce';
    if ($has('tur'))                $opts['tur']     = 'Türkçe';
    if ($has('eng'))                $opts['eng']     = 'İngilizce';

    // Kalan diller (varsa) ham kodla listelensin
    foreach ($inst as $l) {
        if ($l === 'tur' || $l === 'eng') continue;
        $opts[$l] = $l;
    }
    // Hiç dil bulunamadıysa en azından eng dene
    if (!$opts) $opts['eng'] = 'İngilizce';
    return $opts;
}

/** Geçici çalışma klasörü — sistem temp yazılabilir değilse storage/tmp. */
function tarama_tmp_dir(): ?string {
    $sys = sys_get_temp_dir();
    if ($sys !== '' && @is_dir($sys) && @is_writable($sys)) return $sys;

    $local = __DIR__ . '/storage/tmp';
    if (!@is_dir($local)) @mkdir($local, 0770, true);
    if (@is_dir($local) && @is_writable($local)) {
        // Yarım kalmış eski dosyaları temizle (1 saatten eski)
        $files = @glob($local . '/ocr_*');
        if (is_array($files)) {
            foreach ($files as $f) {
                if (@is_file($f) && (time() - (int)@filemtime($f)) > 3600) @unlink($f);
            }
        }
        return $local;
    }
    return null;
}

/** OCR çıktısını temizler: CRLF, kontrol karakterleri, fazla boş satır. */
function tarama_clean_text(string $t): string {
    $t = str_replace(["\r\n", "\r"], "\n", $t);
    // Yazdırılamayan kontrol karakterlerini at (\n ve \t hariç)
    $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $t);
    if (!is_string($t)) $t = '';
    // Satır sonu boşlukları
    $t = preg_replace("/[ \t]+\n/", "\n", $t);
    // 3+ boş satır → 1 boş satır
    $t = preg_replace("/\n{3,}/", "\n\n", $t);
    return trim((string)$t);
}

/**
 * Tesseract'ı çalıştırır.
 * @return array{ok:bool,text:string,error:string,stderr:string}
 */
function tarama_run_ocr(string $img_path, string $lang, int $psm): array {
    $res = ['ok' => false, 'text' => '', 'error' => '', 'stderr' => ''];

    $bin = tarama_tesseract_bin();
    if ($bin === null) {
        $res['error'] = 'Tesseract sunucuda kurulu değil.';
        return $res;
    }
    if (!tarama_fn_available('shell_exec')) {
        $res['error'] = 'shell_exec PHP ayarlarında kapalı — OCR çalıştırılamıyor.';
        return $res;
    }
    $tmp = tarama_tmp_dir();
    if ($tmp === null) {
        $res['error'] = 'Geçici klasör yazılabilir değil.';
        return $res;
    }

    $base    = $tmp . '/ocr_out_' . bin2hex(random_bytes(8));
    $txtFile = $base . '.txt';
    $errFile = $base . '.err';

    // timeout(1) varsa kilitlenmeye karşı süre sınırı koy
    $prefix = '';
    foreach (['/usr/bin/timeout', '/bin/timeout'] as $t) {
        if (@is_file($t) && @is_executable($t)) {
            $prefix = escapeshellarg($t) . ' ' . TARAMA_TIMEOUT_S . ' ';
            break;
        }
    }

    $cmd = $prefix
         . escapeshellarg($bin) . ' '
         . escapeshellarg($img_path) . ' '
         . escapeshellarg($base) . ' '
         . '-l ' . escapeshellarg($lang) . ' '
         . '--psm ' . (int)$psm
         . ' 2> ' . escapeshellarg($errFile);

    @shell_exec($cmd);

    $stderr = @is_file($errFile) ? (string)@file_get_contents($errFile) : '';
    if (@is_file($errFile)) @unlink($errFile);
    $res['stderr'] = trim($stderr);

    if (!@is_file($txtFile)) {
        $res['error'] = 'Tesseract çıktı üretmedi.';
        if ($res['stderr'] !== '') {
            // Eksik dil paketi en sık görülen sebep — mesajı anlaşılır yap
            if (stripos($res['stderr'], 'Failed loading language') !== false
                || stripos($res['stderr'], 'Error opening data file') !== false) {
                $res['error'] = 'Seçilen dil paketi sunucuda kurulu değil (' . $lang . ').';
            }
        }
        return $res;
    }

    $raw = (string)@file_get_contents($txtFile);
    @unlink($txtFile);

    if (!mb_check_encoding($raw, 'UTF-8')) {
        $conv = @iconv('UTF-8', 'UTF-8//IGNORE', $raw);
        $raw  = is_string($conv) ? $conv : '';
    }

    $res['ok']   = true;
    $res['text'] = tarama_clean_text($raw);
    return $res;
}

/** $_FILES hata kodunu Türkçe mesaja çevirir. */
function tarama_upload_error(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'Dosya çok büyük. Sunucu sınırı: '
                 . (string)ini_get('upload_max_filesize') . ' — sayfa sınırı: 10 MB.';
        case UPLOAD_ERR_PARTIAL:    return 'Dosya yalnızca kısmen yüklendi, tekrar deneyin.';
        case UPLOAD_ERR_NO_FILE:    return 'Dosya seçilmedi.';
        case UPLOAD_ERR_NO_TMP_DIR: return 'Sunucuda geçici klasör tanımlı değil.';
        case UPLOAD_ERR_CANT_WRITE: return 'Sunucu diske yazamadı.';
        case UPLOAD_ERR_EXTENSION:  return 'Yükleme bir PHP eklentisi tarafından durduruldu.';
    }
    return 'Dosya yüklenemedi (kod: ' . $code . ').';
}

/** Metin sütununa yazarken formül enjeksiyonunu engelle (CSV yolu için). */
function tarama_csv_safe(string $v): string {
    if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) return "'" . $v;
    return $v;
}

// =========================================================
// POST — post_max_size aşıldı
// PHP bu durumda $_POST/$_FILES'ı tamamen boşaltır; action okunamadığı için
// aşağıdaki işleyiciler devreye girmez. Sayfa HTML dönerse JS "beklenmeyen
// yanıt" der — bu yüzden burada anlaşılır JSON hatası veriyoruz.
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_POST) && empty($_FILES)
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(413);
    echo json_encode([
        'ok'    => false,
        'error' => 'Gönderilen veri sunucu sınırını aştı (post_max_size = '
                 . (string)ini_get('post_max_size') . '). Daha küçük/düşük çözünürlüklü bir görsel deneyin.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =========================================================
// POST — OCR (JSON yanıt)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ocr') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check($_POST['csrf'] ?? null);

    $fail = function (string $msg, int $code = 400) {
        http_response_code($code);
        echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    };

    if (tarama_tesseract_bin() === null) {
        $fail('Tesseract sunucuda kurulu değil. Sunucuya "tesseract-ocr" paketini kurun.', 503);
    }
    if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
        $fail('Görsel alınamadı.');
    }
    $f = $_FILES['image'];
    if ((int)$f['error'] !== UPLOAD_ERR_OK) {
        $fail(tarama_upload_error((int)$f['error']));
    }
    if (!is_uploaded_file($f['tmp_name'])) {
        $fail('Geçersiz yükleme.');
    }
    if ((int)$f['size'] <= 0) {
        $fail('Dosya boş.');
    }
    if ((int)$f['size'] > TARAMA_MAX_BYTES) {
        $fail('Dosya 10 MB sınırını aşıyor (' . round($f['size'] / 1048576, 1) . ' MB).', 413);
    }

    // İçerikten tür doğrulaması — uzantıya güvenme
    $info = @getimagesize($f['tmp_name']);
    $allowed = tarama_allowed_types();
    if (!is_array($info) || !isset($info[2]) || !isset($allowed[$info[2]])) {
        $fail('Desteklenmeyen dosya türü. JPG, PNG, TIFF, BMP veya WEBP yükleyin.');
    }
    $ext = $allowed[$info[2]];

    $tmpdir = tarama_tmp_dir();
    if ($tmpdir === null) $fail('Sunucuda geçici klasör yazılabilir değil.', 500);

    $work = $tmpdir . '/ocr_in_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $work)) {
        $fail('Dosya geçici klasöre taşınamadı.', 500);
    }
    @chmod($work, 0600);

    // Dil / sayfa düzeni doğrulaması (yalnız beyaz listeden)
    $lang_opts = tarama_lang_options();
    $lang = (string)($_POST['lang'] ?? '');
    if (!isset($lang_opts[$lang])) $lang = (string)array_key_first($lang_opts);

    $psm_opts = tarama_psm_options();
    $psm = (int)($_POST['psm'] ?? 3);
    if (!isset($psm_opts[$psm])) $psm = 3;

    $t0 = microtime(true);
    try {
        $ocr = tarama_run_ocr($work, $lang, $psm);
    } catch (Throwable $e) {
        $ocr = ['ok' => false, 'text' => '', 'error' => 'OCR sırasında beklenmeyen hata.',
                'stderr' => $e->getMessage()];
    } finally {
        // İşlem sonrası yüklenen dosya HER durumda silinir
        if (@is_file($work)) @unlink($work);
    }
    $ms = (int)round((microtime(true) - $t0) * 1000);

    if (!$ocr['ok']) {
        http_response_code(500);
        echo json_encode([
            'ok'     => false,
            'error'  => $ocr['error'] !== '' ? $ocr['error'] : 'OCR başarısız.',
            'detail' => (function_exists('is_admin') && is_admin()) ? $ocr['stderr'] : '',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $text = $ocr['text'];
    echo json_encode([
        'ok'    => true,
        'text'  => $text,
        'ms'    => $ms,
        'lang'  => $lang,
        'chars' => mb_strlen($text, 'UTF-8'),
        'lines' => $text === '' ? 0 : count(explode("\n", $text)),
        'warn'  => $text === '' ? 'Görselde okunabilir metin bulunamadı. Daha net/yüksek çözünürlüklü bir görsel veya farklı bir sayfa düzeni deneyin.' : '',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =========================================================
// POST — Dışa aktarım (.txt / .xlsx)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export') {
    csrf_check($_POST['csrf'] ?? null);

    $text = (string)($_POST['text'] ?? '');
    if (strlen($text) > TARAMA_MAX_TEXT) $text = substr($text, 0, TARAMA_MAX_TEXT);
    $text = tarama_clean_text($text);
    if ($text === '') {
        http_response_code(400);
        die('İndirilecek metin yok.');
    }

    $format = ($_POST['format'] ?? 'txt') === 'xlsx' ? 'xlsx' : 'txt';
    $stamp  = date('Ymd_Hi');
    $lines  = explode("\n", $text);

    if ($format === 'txt') {
        $name = 'tarama_' . $stamp . '.txt';
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');
        echo "\xEF\xBB\xBF" . $text;   // BOM → Windows Not Defteri'nde Türkçe bozulmasın
        exit;
    }

    // --- XLSX: PhpSpreadsheet varsa gerçek xlsx, yoksa CSV'ye düş ---
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }

    if (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        $ss    = new PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Tarama');

        $sheet->setCellValue('A1', 'Metin');
        $sheet->getStyle('A1')->getFont()->setBold(true);

        $row = 2;
        foreach ($lines as $line) {
            // Açık string tipi → "=..." ile başlayan satırlar formül olarak yorumlanmaz
            $sheet->setCellValueExplicit(
                'A' . $row,
                $line,
                PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );
            $row++;
        }
        $sheet->getColumnDimension('A')->setWidth(90);
        $sheet->getStyle('A1:A' . max(2, $row - 1))->getAlignment()->setWrapText(true);
        $sheet->freezePane('A2');

        $name = 'tarama_' . $stamp . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');

        $writer = new PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
        $writer->save('php://output');
        $ss->disconnectWorksheets();
        exit;
    }

    // CSV yedeği (Excel uyumlu: BOM + noktalı virgül)
    $name = 'tarama_' . $stamp . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Metin'], ';');
    foreach ($lines as $line) {
        fputcsv($out, [tarama_csv_safe($line)], ';');
    }
    fclose($out);
    exit;
}

// =========================================================
// GET — Sayfa
// =========================================================
$tess_bin     = tarama_tesseract_bin();
$tess_ok      = $tess_bin !== null;
$shell_ok     = tarama_fn_available('shell_exec');
$tess_version = $tess_ok ? tarama_tesseract_version() : '';
$lang_opts    = tarama_lang_options();
$psm_opts     = tarama_psm_options();
$has_xlsx     = is_file(__DIR__ . '/vendor/autoload.php');

// Sunucu yükleme sınırı 10 MB'ın altındaysa kullanıcıya gerçek sınırı söyle
$ini_to_bytes = function (string $v): int {
    $v = trim($v);
    if ($v === '') return 0;
    $unit = strtolower(substr($v, -1));
    $num  = (float)$v;
    if ($unit === 'g') $num *= 1024 * 1024 * 1024;
    elseif ($unit === 'm') $num *= 1024 * 1024;
    elseif ($unit === 'k') $num *= 1024;
    return (int)$num;
};
$srv_limit = min(
    $ini_to_bytes((string)ini_get('upload_max_filesize')) ?: PHP_INT_MAX,
    $ini_to_bytes((string)ini_get('post_max_size')) ?: PHP_INT_MAX
);
$eff_limit = min(TARAMA_MAX_BYTES, $srv_limit);

render_header('Tarama');
?>
<style>
/* ── tarama.php'ye özel stiller (tek dosya kuralı) ─────────────────
   Renkler style.css değişkenlerinden gelir → açık/koyu tema otomatik uyar. */
.ocr-wrap { display: grid; grid-template-columns: 1fr; gap: 14px; }
@media (min-width: 1024px) { .ocr-wrap { grid-template-columns: minmax(0,1fr) minmax(0,1fr); align-items: start; } }

.ocr-note {
    display: flex; gap: 10px; align-items: flex-start;
    padding: 12px 14px; border-radius: 10px; margin-bottom: 14px;
    border: 1px solid var(--border); background: var(--card); font-size: .9rem; line-height: 1.5;
}
.ocr-note-err  { border-color: #f0c0c0; background: var(--danger-soft); color: var(--danger); }
.ocr-note-warn { border-color: #eddcb4; background: var(--warn-soft);   color: var(--warn); }
.ocr-note code {
    background: rgba(127,127,127,.15); padding: 1px 5px; border-radius: 4px;
    font-size: .85em; word-break: break-all;
}

.ocr-drop {
    position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 8px; text-align: center; min-height: 210px; padding: 22px 16px;
    border: 2px dashed var(--border-strong); border-radius: 12px;
    background: var(--bg); cursor: pointer;
    transition: border-color .15s, background .15s, transform .1s;
}
.ocr-drop:hover  { border-color: var(--primary); background: var(--primary-soft); }
.ocr-drop.dragin { border-color: var(--primary); background: var(--primary-soft); transform: scale(1.01); }
.ocr-drop:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.ocr-drop-icon  { font-size: 2.2rem; line-height: 1; }
.ocr-drop-title { font-weight: 700; font-size: 1rem; }
.ocr-drop-sub   { color: var(--muted); font-size: .84rem; max-width: 380px; }
.ocr-drop-kbd {
    display: inline-block; border: 1px solid var(--border-strong); border-bottom-width: 2px;
    border-radius: 5px; padding: 0 5px; font-size: .78rem; font-weight: 700; background: var(--card);
}

.ocr-preview { display: none; }
.ocr-preview.on { display: block; }
.ocr-preview-img {
    display: block; width: 100%; max-height: 320px; object-fit: contain;
    border: 1px solid var(--border); border-radius: 10px; background: var(--bg);
}
.ocr-file-row {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    margin-top: 10px; font-size: .86rem; color: var(--muted);
}
.ocr-file-name { font-weight: 600; color: var(--text); word-break: break-all; }

.ocr-opts { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
.ocr-opts label { flex: 1 1 150px; display: block; font-size: .83rem; color: var(--muted); }
.ocr-opts select { width: 100%; margin-top: 4px; }

.ocr-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
.ocr-actions .btn { flex: 1 1 auto; justify-content: center; }

.ocr-progress { display: none; margin-top: 12px; }
.ocr-progress.on { display: block; }
.ocr-bar { height: 6px; border-radius: 99px; background: var(--border); overflow: hidden; }
.ocr-bar-fill { height: 100%; width: 0; background: var(--primary); transition: width .2s; }
.ocr-bar-fill.indet { width: 40%; animation: ocrIndet 1.1s ease-in-out infinite; }
@keyframes ocrIndet { 0% { margin-left: -40%; } 100% { margin-left: 100%; } }
.ocr-progress-txt { margin-top: 6px; font-size: .82rem; color: var(--muted); }

.ocr-result-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.ocr-stats { font-size: .82rem; color: var(--muted); }
#ocrText {
    width: 100%; min-height: 320px; resize: vertical; margin-top: 10px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: .9rem; line-height: 1.55; white-space: pre; overflow-wrap: normal; overflow-x: auto;
}
.ocr-dl { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
.ocr-dl .btn { flex: 1 1 auto; justify-content: center; }

.ocr-msg { display: none; margin-top: 12px; padding: 10px 12px; border-radius: 8px; font-size: .88rem; border: 1px solid transparent; }
.ocr-msg.on { display: block; }
.ocr-msg.err  { background: var(--danger-soft); color: var(--danger); border-color: #f0c0c0; }
.ocr-msg.warn { background: var(--warn-soft);   color: var(--warn);   border-color: #eddcb4; }
.ocr-msg.ok   { background: var(--primary-soft); color: var(--primary-dark); border-color: #c9dcff; }

/* Koyu tema: açık tema için seçilmiş kenarlık tonlarını yumuşat */
html[data-theme="dark"] .ocr-note-err,
html[data-theme="dark"] .ocr-msg.err  { border-color: #5a2b2b; }
html[data-theme="dark"] .ocr-note-warn,
html[data-theme="dark"] .ocr-msg.warn { border-color: #5a4620; }
html[data-theme="dark"] .ocr-msg.ok   { border-color: #2b3d5e; color: var(--primary); }

@media (max-width: 767px) {
    /* iOS zoom önleme */
    #ocrText, .ocr-opts select { font-size: 16px; }
    .ocr-drop { min-height: 170px; }
    /* Mobilde yatay kaydırma yerine satır kaydır — dar ekranda okunabilirlik */
    #ocrText { min-height: 220px; white-space: pre-wrap; overflow-wrap: anywhere; }
}
@media print { .ocr-drop, .ocr-opts, .ocr-actions, .ocr-dl, .ocr-progress, .ocr-preview { display: none !important; } }
</style>

<div class="page-head">
    <h1 style="margin:0;font-size:1.15rem">📄 Tarama (OCR)</h1>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-sm">← Ana Sayfa</a>
    </div>
</div>

<?php if (!$tess_ok): ?>
<div class="ocr-note ocr-note-err">
    <span style="font-size:1.2rem;line-height:1">⚠️</span>
    <div>
        <strong>Tesseract sunucuda kurulu değil.</strong><br>
        OCR taraması yapılamaz. Sunucuya kurulum (Debian/Ubuntu):
        <code>sudo apt-get install tesseract-ocr tesseract-ocr-tur</code><br>
        Farklı bir konumdaysa <code>config/local.php</code> içine
        <code>define('TESSERACT_BIN', '/tam/yol/tesseract');</code> ekleyin.
        <?php if (!$shell_ok): ?>
        <br><strong>Ayrıca <code>shell_exec</code> PHP ayarlarında kapalı</strong> — <code>disable_functions</code> listesinden çıkarılmalı.
        <?php endif; ?>
    </div>
</div>
<?php elseif (!$shell_ok): ?>
<div class="ocr-note ocr-note-err">
    <span style="font-size:1.2rem;line-height:1">⚠️</span>
    <div><strong><code>shell_exec</code> PHP ayarlarında kapalı.</strong> Tesseract kurulu olsa da çalıştırılamıyor —
        <code>php.ini</code> içindeki <code>disable_functions</code> listesinden çıkarın.</div>
</div>
<?php elseif (!in_array('tur', tarama_installed_langs(), true)): ?>
<div class="ocr-note ocr-note-warn">
    <span style="font-size:1.2rem;line-height:1">ℹ️</span>
    <div><strong>Türkçe dil paketi kurulu değil.</strong> Türkçe metinlerde doğruluk düşük olur:
        <code>sudo apt-get install tesseract-ocr-tur</code></div>
</div>
<?php endif; ?>

<div class="ocr-wrap">
    <!-- ── SOL: Görsel ───────────────────────────────────── -->
    <section class="card">
        <div class="card-head"><h2>1 · Görsel</h2></div>

        <div id="ocrDrop" class="ocr-drop" tabindex="0" role="button"
             aria-label="Görsel yüklemek için tıklayın veya sürükleyip bırakın">
            <span class="ocr-drop-icon" aria-hidden="true">🖼️</span>
            <span class="ocr-drop-title">Tıklayın, sürükleyin veya yapıştırın</span>
            <span class="ocr-drop-sub">
                JPG · PNG · TIFF · BMP · WEBP — en fazla <?= number_format($eff_limit / 1048576, 0, ',', '.') ?> MB.
                Panodaki görseli <span class="ocr-drop-kbd">Ctrl</span>+<span class="ocr-drop-kbd">V</span> ile yapıştırabilirsiniz.
            </span>
        </div>

        <input type="file" id="ocrFile" accept="image/jpeg,image/png,image/tiff,image/bmp,image/webp" hidden>
        <input type="file" id="ocrCam" accept="image/*" capture="environment" hidden>

        <div id="ocrPreview" class="ocr-preview">
            <img id="ocrImg" class="ocr-preview-img" alt="Yüklenen görsel önizlemesi">
            <div class="ocr-file-row">
                <span class="ocr-file-name" id="ocrName"></span>
                <span id="ocrSize"></span>
                <button type="button" class="btn btn-sm btn-ghost" id="ocrClear" style="margin-left:auto">✕ Kaldır</button>
            </div>
        </div>

        <div class="ocr-opts">
            <label>Dil
                <select id="ocrLang">
                    <?php foreach ($lang_opts as $code => $label): ?>
                    <option value="<?= h((string)$code) ?>"><?= h((string)$label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Sayfa düzeni
                <select id="ocrPsm">
                    <?php foreach ($psm_opts as $code => $label): ?>
                    <option value="<?= (int)$code ?>"><?= h((string)$label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="ocr-actions">
            <button type="button" class="btn btn-primary btn-lg" id="ocrRun" <?= $tess_ok && $shell_ok ? '' : 'disabled' ?>>🔍 Tara</button>
            <button type="button" class="btn btn-lg" id="ocrCamBtn">📷 Fotoğraf Çek</button>
        </div>

        <div class="ocr-progress" id="ocrProg">
            <div class="ocr-bar"><div class="ocr-bar-fill" id="ocrBar"></div></div>
            <div class="ocr-progress-txt" id="ocrProgTxt">Yükleniyor…</div>
        </div>

        <div class="ocr-msg" id="ocrMsg" role="status"></div>

        <?php if ($tess_version !== ''): ?>
        <p class="muted" style="margin:12px 0 0"><?= h($tess_version) ?></p>
        <?php endif; ?>
    </section>

    <!-- ── SAĞ: Metin ────────────────────────────────────── -->
    <section class="card">
        <div class="card-head ocr-result-head">
            <h2>2 · Metin</h2>
            <span class="ocr-stats" id="ocrStats">—</span>
        </div>

        <textarea id="ocrText" spellcheck="false"
                  placeholder="Taranan metin burada görünecek. Buradan düzenleyip indirebilirsiniz."></textarea>

        <div class="ocr-dl">
            <button type="button" class="btn" id="ocrCopy">📋 Kopyala</button>
            <button type="button" class="btn btn-secondary" id="ocrTxt">⬇ Metni İndir (.txt)</button>
            <button type="button" class="btn btn-secondary" id="ocrXlsx">
                ⬇ Excel Olarak İndir (<?= $has_xlsx ? '.xlsx' : '.csv' ?>)
            </button>
        </div>
        <?php if (!$has_xlsx): ?>
        <p class="muted" style="margin:8px 0 0">PhpSpreadsheet bulunamadı — Excel indirmesi CSV olarak verilir (Excel açar).</p>
        <?php endif; ?>

        <!-- İndirme: metin POST ile sunucuya gider, dosya olarak geri döner -->
        <form id="ocrDlForm" method="post" action="tarama.php" style="display:none">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="export">
            <input type="hidden" name="format" id="ocrDlFormat" value="txt">
            <textarea name="text" id="ocrDlText"></textarea>
        </form>
    </section>
</div>

<script>
(function () {
    'use strict';

    var MAX_BYTES = <?= (int)$eff_limit ?>;
    var CSRF      = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>;
    var OK_TYPES  = ['image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png',
                     'image/tiff', 'image/x-tiff', 'image/bmp', 'image/x-ms-bmp', 'image/webp'];

    var drop = document.getElementById('ocrDrop');
    var fileInput = document.getElementById('ocrFile');
    var camInput  = document.getElementById('ocrCam');
    var camBtn    = document.getElementById('ocrCamBtn');
    var preview = document.getElementById('ocrPreview');
    var img     = document.getElementById('ocrImg');
    var nameEl  = document.getElementById('ocrName');
    var sizeEl  = document.getElementById('ocrSize');
    var clearBt = document.getElementById('ocrClear');
    var runBt   = document.getElementById('ocrRun');
    var prog    = document.getElementById('ocrProg');
    var bar     = document.getElementById('ocrBar');
    var progTxt = document.getElementById('ocrProgTxt');
    var msg     = document.getElementById('ocrMsg');
    var text    = document.getElementById('ocrText');
    var stats   = document.getElementById('ocrStats');
    var dlForm  = document.getElementById('ocrDlForm');
    var dlFmt   = document.getElementById('ocrDlFormat');
    var dlText  = document.getElementById('ocrDlText');

    var current = null;      // seçili File/Blob
    var objUrl  = null;
    var busy    = false;

    // ── Yardımcılar ─────────────────────────────────────
    function say(kind, txt) {
        msg.className = 'ocr-msg on ' + kind;
        msg.textContent = txt;
    }
    function hideMsg() { msg.className = 'ocr-msg'; msg.textContent = ''; }

    function fmtSize(b) {
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(1).replace('.', ',') + ' KB';
        return (b / 1048576).toFixed(1).replace('.', ',') + ' MB';
    }

    function refreshStats() {
        var t = text.value;
        if (!t) { stats.textContent = '—'; return; }
        var words = t.split(/\s+/).filter(function (w) { return w.length > 0; }).length;
        stats.textContent = t.length + ' karakter · ' + words + ' kelime · ' +
                            t.split('\n').length + ' satır';
    }

    function setFile(f) {
        if (!f) return;
        var type = (f.type || '').toLowerCase();
        if (type && OK_TYPES.indexOf(type) === -1) {
            say('err', 'Desteklenmeyen dosya türü: ' + (type || 'bilinmiyor') + '. JPG, PNG, TIFF, BMP veya WEBP seçin.');
            return;
        }
        if (f.size > MAX_BYTES) {
            say('err', 'Dosya çok büyük (' + fmtSize(f.size) + '). Sınır: ' + fmtSize(MAX_BYTES) + '.');
            return;
        }
        hideMsg();
        current = f;
        if (objUrl) URL.revokeObjectURL(objUrl);
        objUrl = URL.createObjectURL(f);
        img.src = objUrl;
        nameEl.textContent = f.name || 'panodan-yapistirildi.png';
        sizeEl.textContent = fmtSize(f.size);
        preview.classList.add('on');
    }

    function clearFile() {
        current = null;
        if (objUrl) { URL.revokeObjectURL(objUrl); objUrl = null; }
        img.removeAttribute('src');
        preview.classList.remove('on');
        fileInput.value = '';
        camInput.value = '';
        hideMsg();
    }

    // ── Dosya seçimi ────────────────────────────────────
    drop.addEventListener('click', function () { fileInput.click(); });
    drop.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
    });
    fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files[0]) setFile(fileInput.files[0]);
    });
    camBtn.addEventListener('click', function () { camInput.click(); });
    camInput.addEventListener('change', function () {
        if (camInput.files && camInput.files[0]) setFile(camInput.files[0]);
    });
    clearBt.addEventListener('click', function (e) { e.stopPropagation(); clearFile(); });

    // ── Sürükle-bırak ───────────────────────────────────
    ['dragenter', 'dragover'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) {
            e.preventDefault(); e.stopPropagation();
            drop.classList.add('dragin');
        });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) {
            e.preventDefault(); e.stopPropagation();
            drop.classList.remove('dragin');
        });
    });
    drop.addEventListener('drop', function (e) {
        var dt = e.dataTransfer;
        if (dt && dt.files && dt.files[0]) setFile(dt.files[0]);
    });
    // Sayfanın geri kalanına bırakılan dosya tarayıcıda açılmasın
    ['dragover', 'drop'].forEach(function (ev) {
        window.addEventListener(ev, function (e) {
            if (!drop.contains(e.target)) e.preventDefault();
        });
    });

    // ── Panodan yapıştırma (Ctrl+V) ─────────────────────
    document.addEventListener('paste', function (e) {
        // Metin kutusuna yazı yapıştırılıyorsa karışma
        var items = (e.clipboardData || window.clipboardData || {}).items;
        if (!items) return;
        for (var i = 0; i < items.length; i++) {
            if (items[i].kind === 'file' && items[i].type.indexOf('image/') === 0) {
                var blob = items[i].getAsFile();
                if (blob) {
                    e.preventDefault();
                    // Blob'un adı olmaz — sunucuya anlamlı bir ad ile gönder
                    var ext = (blob.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
                    try {
                        blob = new File([blob], 'pano.' + ext, { type: blob.type });
                    } catch (err) { /* File ctor yoksa Blob ile devam */ }
                    setFile(blob);
                    say('ok', 'Panodaki görsel alındı. "Tara" ile okuyabilirsiniz.');
                }
                return;
            }
        }
    });

    // ── Tara ────────────────────────────────────────────
    function runOcr() {
        if (busy) return;
        if (!current) { say('err', 'Önce bir görsel seçin, sürükleyin veya yapıştırın.'); return; }

        busy = true;
        runBt.disabled = true;
        hideMsg();
        prog.classList.add('on');
        bar.className = 'ocr-bar-fill';
        bar.style.width = '0%';
        progTxt.textContent = 'Yükleniyor…';

        var fd = new FormData();
        fd.append('action', 'ocr');
        fd.append('csrf', CSRF);
        fd.append('lang', document.getElementById('ocrLang').value);
        fd.append('psm', document.getElementById('ocrPsm').value);
        fd.append('image', current, current.name || 'pano.png');

        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'tarama.php', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');

        xhr.upload.onprogress = function (e) {
            if (!e.lengthComputable) return;
            var pct = Math.round((e.loaded / e.total) * 100);
            bar.style.width = pct + '%';
            if (pct >= 100) {
                progTxt.textContent = 'Metin okunuyor… (büyük görsellerde 10-30 sn sürebilir)';
                bar.className = 'ocr-bar-fill indet';
            }
        };

        xhr.onload = function () {
            busy = false;
            runBt.disabled = false;
            prog.classList.remove('on');

            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (err) { res = null; }

            if (!res) {
                say('err', 'Sunucudan beklenmeyen yanıt geldi (HTTP ' + xhr.status + ').');
                return;
            }
            if (!res.ok) {
                say('err', res.error || 'Tarama başarısız.');
                if (res.detail) { msg.textContent += ' — ' + res.detail; }
                return;
            }

            text.value = res.text || '';
            refreshStats();
            if (res.warn) {
                say('warn', res.warn);
            } else {
                say('ok', 'Tarama tamam · ' + (res.ms / 1000).toFixed(1).replace('.', ',') + ' sn · ' +
                          res.chars + ' karakter okundu.');
            }
        };

        xhr.onerror = function () {
            busy = false;
            runBt.disabled = false;
            prog.classList.remove('on');
            say('err', 'Bağlantı hatası. İnternet bağlantınızı kontrol edip tekrar deneyin.');
        };

        xhr.send(fd);
    }
    runBt.addEventListener('click', runOcr);

    // ── Metin işlemleri ─────────────────────────────────
    text.addEventListener('input', refreshStats);

    document.getElementById('ocrCopy').addEventListener('click', function () {
        if (!text.value) { say('err', 'Kopyalanacak metin yok.'); return; }
        var done = function () { say('ok', 'Metin panoya kopyalandı.'); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text.value).then(done, function () {
                text.select(); document.execCommand('copy'); done();
            });
        } else {
            text.select(); document.execCommand('copy'); done();
        }
    });

    function download(format) {
        if (!text.value.trim()) { say('err', 'İndirilecek metin yok. Önce bir görsel tarayın.'); return; }
        dlFmt.value = format;
        dlText.value = text.value;
        dlForm.submit();
    }
    document.getElementById('ocrTxt').addEventListener('click', function () { download('txt'); });
    document.getElementById('ocrXlsx').addEventListener('click', function () { download('xlsx'); });

    refreshStats();
})();
</script>
<?php render_footer(); ?>
