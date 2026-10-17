<?php
// =========================================================
// config/siparis_pdf.php — Sipariş modülü PDF ayrıştırıcısı (saf PHP)
//
// OGL / Fruitservice SAP çıktıları: Purchase order · Price confirmation ·
// Loading List (Ladeplan). Kaynak: kanıtlanmış v2 ayrıştırıcısının MEKANİK
// portu (iç önek sp_ → sipdf_, genel adlar aynı). Yalnız zlib + mbstring;
// pdftotext / exec / shell YOK. Belgeler GÜVENİLMEZ veridir: hiçbir şey
// eval edilmez, çıktı düz dizidir.
//
// ⚠ declare(strict_types=1) BİLEREK YOK — kod gevşek int/float
//   dönüşümüne dayanır (eklenirse ayrıştırma TypeError ile kırılır).
//
// GENEL API (dönüş şemaları her parse fonksiyonunun doc block'unda — doc_type, checks[],
// warnings[{level,code,message,context?}], meta):
//   siparis_pdf_oku(string $file): array        → ['pages'=>[n=>segments],'meta'=>[...]] (RuntimeException atar)
//   siparis_pdf_belge_turu(array $pages): string → purchase_order | price_confirmation | loading_list | unknown
//   siparis_pdf_parse_order / _parse_price_confirmation / _parse_loading_list(array $pages, array $meta=[])
//   siparis_pdf_isle(string $file): array        → oku + sınıfla + ayrıştır; ASLA istisna atmaz (doc_type 'error')
//   siparis_pdf_capraz_kontrol(array $docs): array → order_ref bazında çapraz denetim
// Sütun konumları sayfadaki başlık etiketlerinden çıkarılır (sipdf_cols_from_labels), sabit x YOK.
// =========================================================

/** Ayrıştırıcı sürümü — siparis_belgeler.parser_surum'a yazılır. */
const SIPARIS_PDF_SURUM = 'v2';

/**
 * Minimal pure-PHP PDF text-run extractor (no binaries, only zlib).
 * Scope: flattened PDFs (LiveCycle "print to PDF"): literal-string Tj/TJ, WinAnsi simple fonts,
 * Type0 fonts with ToUnicode CMap, FlateDecode streams, object streams (PDF 1.5+).
 * Not supported: encryption, xref-stream-only reconstruction (we scan instead), LZW/ASCII85, CTM (cm) on text.
 */

function sipdf_pdf_load_objects(string $d): array
{
    $objs = [];
    $n = preg_match_all('/(?<![0-9])(\d+)\s+(\d+)\s+obj(?![a-z])/', $d, $mm, PREG_OFFSET_CAPTURE);
    for ($i = 0; $i < $n; $i++) {
        $num = (int)$mm[1][$i][0];
        $pos = $mm[0][$i][1] + strlen($mm[0][$i][0]);
        $p = $pos;
        $val = sipdf_pdf_parse_value($d, $p);
        $stream = null;
        if (is_array($val) && ($val['_t'] ?? '') === 'dict') {
            if (preg_match('/\G\s*stream\r?\n/', $d, $sm, 0, $p)) {
                $s = $p + strlen($sm[0]);
                $len = $val['v']['Length'] ?? null;
                $e = false;
                if (is_int($len) || is_float($len)) {
                    if (substr($d, $s + (int)$len, 20) !== false && preg_match('/\G\s*endstream/', $d, $x, 0, $s + (int)$len)) {
                        $e = $s + (int)$len;
                    }
                }
                if ($e === false) { $e = strpos($d, 'endstream', $s); if ($e === false) continue; }
                $stream = substr($d, $s, $e - $s);
            }
        }
        $objs[$num] = ['val' => $val, 'stream' => $stream];
    }
    // object streams
    foreach ($objs as $num => $o) {
        $v = $o['val'];
        if ($o['stream'] !== null && ($v['_t'] ?? '') === 'dict' && (($v['v']['Type']['n'] ?? '') === 'ObjStm')) {
            $data = sipdf_pdf_stream_data($o, $objs);
            if ($data === null) continue;
            $N = (int)$v['v']['N']; $first = (int)$v['v']['First'];
            $hp = 0; $pairs = [];
            for ($k = 0; $k < $N; $k++) {
                $a = sipdf_pdf_parse_value($data, $hp); $b = sipdf_pdf_parse_value($data, $hp);
                $pairs[] = [(int)$a, (int)$b];
            }
            foreach ($pairs as [$onum, $off]) {
                $p = $first + $off;
                if (!isset($objs[$onum])) $objs[$onum] = ['val' => sipdf_pdf_parse_value($data, $p), 'stream' => null];
            }
        }
    }
    return $objs;
}

/** Tiny PDF object parser. Returns: int|float|string(['_t'=>'str','s'=>..])|name(['_t'=>'name','n'=>..])|ref|array|dict */
function sipdf_pdf_parse_value(string $d, int &$p)
{
    $L = strlen($d);
    while ($p < $L && strpos(" \t\r\n\f\0", $d[$p]) !== false) $p++;
    if ($p >= $L) return null;
    $c = $d[$p];
    if ($c === '%') { while ($p < $L && $d[$p] !== "\n" && $d[$p] !== "\r") $p++; return sipdf_pdf_parse_value($d, $p); }
    if ($c === '<' && ($d[$p + 1] ?? '') === '<') {
        $p += 2; $dict = [];
        while (true) {
            while ($p < $L && strpos(" \t\r\n\f\0", $d[$p]) !== false) $p++;
            if ($p >= $L) break;
            if ($d[$p] === '>' && ($d[$p + 1] ?? '') === '>') { $p += 2; break; }
            $k = sipdf_pdf_parse_value($d, $p);
            if (!is_array($k) || ($k['_t'] ?? '') !== 'name') { $p++; continue; }
            $dict[$k['n']] = sipdf_pdf_parse_value($d, $p);
        }
        return ['_t' => 'dict', 'v' => $dict];
    }
    if ($c === '[') {
        $p++; $arr = [];
        while (true) {
            while ($p < $L && strpos(" \t\r\n\f\0", $d[$p]) !== false) $p++;
            if ($p >= $L) break;
            if ($d[$p] === ']') { $p++; break; }
            $arr[] = sipdf_pdf_parse_value($d, $p);
        }
        return ['_t' => 'arr', 'v' => $arr];
    }
    if ($c === '/') {
        $p++; $s = $p;
        while ($p < $L && strpos(" \t\r\n\f\0/[]<>()%", $d[$p]) === false) $p++;
        $n = preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn($m) => chr(hexdec($m[1])), substr($d, $s, $p - $s));
        return ['_t' => 'name', 'n' => $n];
    }
    if ($c === '(') return ['_t' => 'str', 's' => sipdf_pdf_read_literal($d, $p)];
    if ($c === '<') {
        $e = strpos($d, '>', $p); $h = preg_replace('/\s+/', '', substr($d, $p + 1, $e - $p - 1)); $p = $e + 1;
        if (strlen($h) % 2) $h .= '0';
        return ['_t' => 'str', 's' => hex2bin($h) ?: ''];
    }
    if (preg_match('/\G([+-]?\d+)\s+(\d+)\s+R(?![a-zA-Z])/', $d, $m, 0, $p)) { $p += strlen($m[0]); return ['_t' => 'ref', 'n' => (int)$m[1]]; }
    if (preg_match('/\G[+-]?(\d+\.?\d*|\.\d+)/', $d, $m, 0, $p)) { $p += strlen($m[0]); return strpos($m[0], '.') !== false ? (float)$m[0] : (int)$m[0]; }
    if (preg_match('/\G[A-Za-z\'"*]+/', $d, $m, 0, $p)) { $p += strlen($m[0]); return ['_t' => 'kw', 'k' => $m[0]]; }
    $p++; return null;
}

function sipdf_pdf_read_literal(string $d, int &$p): string
{
    $L = strlen($d); $p++; $depth = 1; $out = '';
    while ($p < $L) {
        $c = $d[$p++];
        if ($c === '\\') {
            $n = $d[$p++] ?? '';
            switch ($n) {
                case 'n': $out .= "\n"; break; case 'r': $out .= "\r"; break; case 't': $out .= "\t"; break;
                case 'b': $out .= "\x08"; break; case 'f': $out .= "\x0c"; break;
                case '(': case ')': case '\\': $out .= $n; break;
                case "\r": if (($d[$p] ?? '') === "\n") $p++; break;
                case "\n": break;
                default:
                    if ($n >= '0' && $n <= '7') {
                        $o = $n;
                        for ($k = 0; $k < 2 && isset($d[$p]) && $d[$p] >= '0' && $d[$p] <= '7'; $k++) $o .= $d[$p++];
                        $out .= chr(octdec($o) & 255);
                    } else $out .= $n;
            }
        } elseif ($c === '(') { $depth++; $out .= $c; }
        elseif ($c === ')') { if (--$depth === 0) break; $out .= $c; }
        else $out .= $c;
    }
    return $out;
}

function sipdf_pdf_deref($v, array $objs)
{
    $guard = 0;
    while (is_array($v) && ($v['_t'] ?? '') === 'ref' && $guard++ < 20) $v = $objs[$v['n']]['val'] ?? null;
    return $v;
}

function sipdf_pdf_stream_data(array $o, array $objs): ?string
{
    $dict = $o['val']['v'] ?? [];
    $f = sipdf_pdf_deref($dict['Filter'] ?? null, $objs);
    $filters = [];
    if (is_array($f) && $f['_t'] === 'name') $filters[] = $f['n'];
    elseif (is_array($f) && $f['_t'] === 'arr') foreach ($f['v'] as $x) { $x = sipdf_pdf_deref($x, $objs); $filters[] = $x['n'] ?? '?'; }
    $data = $o['stream'];
    foreach ($filters as $fl) {
        if ($fl === 'FlateDecode' || $fl === 'Fl') {
            $r = @gzuncompress($data);
            if ($r === false) $r = @gzinflate(substr($data, 2));
            if ($r === false) return null;
            $data = $r;
        } else return null; // unsupported filter
    }
    return $data;
}

function sipdf_pdf_pages(array $objs): array
{
    $root = null;
    foreach ($objs as $n => $o) if ((($o['val']['v']['Type']['n'] ?? '') === 'Catalog')) $root = $o['val']['v'];
    $out = [];
    // sipariş modülü sağlamlaştırması: Pages'siz katalog uyarı basmaz; döngülü / aşırı derin Kids
    // (kötü niyetli PDF) yığın taşması yerine kesilir (derinlik 64, en çok 2000 sayfa). Geçerli PDF'te davranış aynı.
    $walk = function ($node, $inherit, int $derinlik = 0) use (&$walk, &$out, $objs) {
        if ($derinlik > 64 || count($out) >= 2000) return;
        $node = sipdf_pdf_deref($node, $objs);
        if (($node['_t'] ?? '') !== 'dict') return;
        $v = $node['v'];
        if (isset($v['Resources'])) $inherit['Resources'] = $v['Resources'];
        if (isset($v['MediaBox'])) $inherit['MediaBox'] = $v['MediaBox'];
        if (isset($v['Kids'])) { $kids = sipdf_pdf_deref($v['Kids'], $objs)['v'] ?? []; foreach (is_array($kids) ? $kids : [] as $k) $walk($k, $inherit, $derinlik + 1); return; }
        $v['_inh'] = $inherit; $out[] = $v;
    };
    if ($root && isset($root['Pages'])) $walk($root['Pages'], []);
    return $out;
}

function sipdf_pdf_parse_tounicode(string $cmap): array
{
    $map = []; $cs = 1;
    if (preg_match('/begincodespacerange\s*<([0-9A-Fa-f]+)>/', $cmap, $m)) $cs = (int)(strlen($m[1]) / 2);
    if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $bl)) foreach ($bl[1] as $blk)
        if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $blk, $pp, PREG_SET_ORDER))
            foreach ($pp as $p) $map[hexdec($p[1])] = mb_convert_encoding(hex2bin($p[2]), 'UTF-8', 'UTF-16BE');
    if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $bl)) foreach ($bl[1] as $blk)
        if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $blk, $pp, PREG_SET_ORDER))
            foreach ($pp as $p) {
                $a = hexdec($p[1]); $b = hexdec($p[2]); $u = hexdec($p[3]);
                for ($c = $a; $c <= $b && $c - $a < 65536; $c++) $map[$c] = mb_convert_encoding(pack('n', $u + $c - $a), 'UTF-8', 'UTF-16BE');
            }
    return ['cs' => $cs, 'map' => $map];
}

function sipdf_pdf_font_table(array $page, array $objs): array
{
    $res = sipdf_pdf_deref($page['Resources'] ?? ($page['_inh']['Resources'] ?? null), $objs);
    $fonts = sipdf_pdf_deref($res['v']['Font'] ?? null, $objs);
    $tbl = [];
    foreach (($fonts['v'] ?? []) as $name => $ref) {
        $f = sipdf_pdf_deref($ref, $objs)['v'] ?? [];
        $tu = null;
        if (isset($f['ToUnicode'])) {
            $so = $objs[$f['ToUnicode']['n']] ?? null;
            if ($so) { $cm = sipdf_pdf_stream_data($so, $objs); if ($cm !== null) $tu = sipdf_pdf_parse_tounicode($cm); }
        }
        $tbl[$name] = ['tu' => $tu, 'sub' => $f['Subtype']['n'] ?? ''];
    }
    return $tbl;
}

function sipdf_pdf_decode_string(string $s, ?array $font): string
{
    if ($font && $font['tu']) {
        $out = ''; $cs = $font['tu']['cs']; $L = strlen($s);
        for ($i = 0; $i + $cs <= $L; $i += $cs) {
            $c = 0; for ($k = 0; $k < $cs; $k++) $c = ($c << 8) | ord($s[$i + $k]);
            $out .= $font["tu"]["map"][$c] ?? "\u{FFFD}";
        }
        return $out;
    }
    return mb_convert_encoding($s, 'UTF-8', 'Windows-1252'); // WinAnsiEncoding
}

/** Returns list of text segments: ['x'=>,'y'=>,'size'=>,'text'=>] for one page. */
function sipdf_pdf_page_segments(array $page, array $objs): array
{
    $c = sipdf_pdf_deref($page['Contents'] ?? null, $objs);
    $streams = [];
    if (($c['_t'] ?? '') === 'arr') foreach ($c['v'] as $r) $streams[] = $r; elseif ($c) $streams[] = $page['Contents'];
    $data = '';
    foreach ($streams as $r) { $o = $objs[$r['n']] ?? null; if ($o && $o['stream'] !== null) $data .= (sipdf_pdf_stream_data($o, $objs) ?? '') . "\n"; }
    $fonts = sipdf_pdf_font_table($page, $objs);
    $segs = []; $cur = null; $font = null; $size = 1.0;
    $tm = [1, 0, 0, 1, 0, 0]; $lm = $tm; $leading = 0.0;
    $flush = function () use (&$cur, &$segs) { if ($cur !== null && trim($cur['text']) !== '') { $cur['text'] = $cur['text']; $segs[] = $cur; } $cur = null; };
    $begin = function () use (&$cur, &$tm, &$size, &$flush) { $flush(); $cur = ['x' => $tm[4], 'y' => $tm[5], 'size' => $size * abs($tm[3] ?: $tm[0]), 'text' => '']; };
    $stack = []; $p = 0; $L = strlen($data);
    while ($p < $L) {
        $v = sipdf_pdf_parse_value($data, $p);
        if ($v === null) { if ($p >= $L) break; continue; }
        if (is_array($v) && ($v['_t'] ?? '') === 'kw') {
            $op = $v['k'];
            $num = fn($i) => (float)($stack[$i] ?? 0);
            switch ($op) {
                case 'BT': $tm = $lm = [1, 0, 0, 1, 0, 0]; $flush(); break;
                case 'ET': $flush(); break;
                case 'Tf': $fn = $stack[count($stack) - 2]['n'] ?? null; $font = $fonts[$fn] ?? null; $size = $num(count($stack) - 1); break;
                case 'Tm': $tm = $lm = [$num(0), $num(1), $num(2), $num(3), $num(4), $num(5)]; $begin(); break;
                case 'Td': case 'TD':
                    if ($op === 'TD') $leading = -$num(1);
                    $lm[4] += $num(0) * $lm[0] + $num(1) * $lm[2]; $lm[5] += $num(0) * $lm[1] + $num(1) * $lm[3]; $tm = $lm; $begin(); break;
                case 'T*': $lm[4] += -$leading * $lm[2]; $lm[5] += -$leading * $lm[3]; $tm = $lm; $begin(); break;
                case 'TL': $leading = $num(0); break;
                case 'Tj': case "'": case '"':
                    if ($op !== 'Tj') { $lm[5] -= $leading * $lm[3]; $tm = $lm; $begin(); }
                    if ($cur === null) $begin();
                    $s = end($stack); if (is_array($s) && ($s['_t'] ?? '') === 'str') $cur['text'] .= sipdf_pdf_decode_string($s['s'], $font);
                    break;
                case 'TJ':
                    if ($cur === null) $begin();
                    $a = end($stack);
                    foreach (($a['v'] ?? []) as $it) {
                        if (is_array($it) && ($it['_t'] ?? '') === 'str') $cur['text'] .= sipdf_pdf_decode_string($it['s'], $font);
                        elseif (is_numeric($it) && $it < -250) $cur['text'] .= ' ';
                    }
                    break;
            }
            $stack = [];
        } else {
            $stack[] = $v;
            if (count($stack) > 64) array_shift($stack);
        }
    }
    $flush();
    return $segs;
}


/* =====================================================================================================
 * PART 2 - READING, LAYOUT HELPERS
 * ===================================================================================================== */

/**
 * Reads a PDF and returns ['pages' => [1 => segments, ...], 'meta' => [...]].
 * Pages are in PAGE-TREE order (not file order: in some files page 2's stream precedes page 1's).
 * segment = ['x'=>float,'y'=>float,'size'=>float,'text'=>string]; PDF origin is bottom-left, so larger y = higher on the page.
 * meta = ['pages'=>int, 'creator'=>?string, 'producer'=>?string, 'created'=>?'Y-m-d H:i:s', 'page_sizes'=>[[w,h],...], 'bytes'=>int]
 * Throws RuntimeException for non-PDF / encrypted / empty page tree / oversize input (> SIPARIS_MAX_DOSYA, default 15 MB).
 */
function siparis_pdf_oku(string $file): array
{
    $size = @filesize($file);
    if ($size === false) throw new RuntimeException('file not readable');
    $sinir = defined('SIPARIS_MAX_DOSYA') ? (int)SIPARIS_MAX_DOSYA : 15 * 1024 * 1024;   // sipariş modülünün dosya sınırı (varsayılan 15 MB)
    if ($size > $sinir) throw new RuntimeException('PDF too large (> ' . (int)($sinir / 1048576) . ' MB)');
    $d = file_get_contents($file);
    if ($d === false || strncmp($d, '%PDF', 4) !== 0) throw new RuntimeException('not a PDF');
    if (strpos($d, '/Encrypt') !== false) throw new RuntimeException('encrypted PDF not supported');
    $objs = sipdf_pdf_load_objects($d);
    $pages = sipdf_pdf_pages($objs);
    if (!$pages) throw new RuntimeException('no page tree');
    $out = []; $sizes = [];
    foreach ($pages as $i => $pg) {
        $out[$i + 1] = sipdf_pdf_page_segments($pg, $objs);
        $mb = sipdf_pdf_deref($pg['MediaBox'] ?? ($pg['_inh']['MediaBox'] ?? null), $objs);
        $mbv = array_map(fn($x) => (float)(is_array($x) ? 0 : $x), $mb['v'] ?? []);
        $sizes[] = count($mbv) === 4 ? [round($mbv[2] - $mbv[0], 1), round($mbv[3] - $mbv[1], 1)] : null;
    }
    $meta = ['pages' => count($out), 'creator' => null, 'producer' => null, 'created' => null, 'page_sizes' => $sizes, 'bytes' => (int)$size];
    foreach ($objs as $o) {
        $v = $o['val']['v'] ?? null;
        if (is_array($v) && (isset($v['Producer']) || isset($v['CreationDate'])) && !isset($v['Type'])) {
            $str = fn($k) => isset($v[$k]['s']) ? trim(preg_replace('/[^\x20-\x7E]/', '', (string)$v[$k]['s'])) : null;
            $meta['creator'] = $str('Creator'); $meta['producer'] = $str('Producer');
            if (preg_match('/D:(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', (string)($v['CreationDate']['s'] ?? ''), $m)) $meta['created'] = "$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]";
        }
    }
    return ['pages' => $out, 'meta' => $meta];
}

/** Small utilities ------------------------------------------------------------------------------------ */
function sipdf_t(array $seg): string { return trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $seg['text']))); }
function sipdf_ltext(array $line): string { return trim(implode(' ', array_map('sipdf_t', $line['segs']))); }
function sipdf_int(?string $s): ?int
{   // "1,600" -> 1600 ; "4" -> 4 ; anything else -> null
    if ($s === null) return null;
    $s = trim($s);
    return preg_match('/^\d{1,3}(,\d{3})+$|^\d+$/', $s) ? (int)str_replace(',', '', $s) : null;
}
function sipdf_dec(?string $s): ?float
{   // "4.78" -> 4.78 ; "14.00" -> 14.0   (this ERP prints dot decimals and comma thousands)
    if ($s === null) return null;
    $s = trim($s);
    return preg_match('/^\d{1,3}(,\d{3})*(\.\d+)?$|^\d+(\.\d+)?$/', $s) ? (float)str_replace(',', '', $s) : null;
}
function sipdf_dmy(?string $s): ?string { return $s !== null && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $s, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3]) ? "$m[3]-$m[2]-$m[1]" : null; }
function sipdf_is_ref(string $s): bool { return (bool)preg_match('/^[A-Z]{1,3}\d{5,}\.\d{1,3}$/', $s); }          // TC418910.10
function sipdf_warn(array &$w, string $level, string $code, string $msg, array $ctx = []): void { $w[] = ['level' => $level, 'code' => $code, 'message' => $msg] + ($ctx ? ['context' => $ctx] : []); }
function sipdf_check(array &$checks, array &$w, string $id, string $scope, bool $ok, $expected, $actual, string $msg): void
{
    $checks[] = ['id' => $id, 'scope' => $scope, 'ok' => $ok, 'expected' => $expected, 'actual' => $actual];
    if (!$ok) sipdf_warn($w, 'warn', 'check_' . $id, $msg . " [$scope] expected=" . json_encode($expected) . ' actual=' . json_encode($actual), ['scope' => $scope]);
}
/** "Pomegranate 14 PC LOSE (TR)" -> product, pcs_per_box (=calibre label used in the PKL), pack_mode, origin. */
function sipdf_parse_article(?string $d): array
{
    $r = ['description' => $d, 'product' => $d, 'pcs_per_box' => null, 'pack_mode' => null, 'origin' => null];
    if ($d !== null && preg_match('/^(.*?)\s+(\d+)\s+PC\s+(\w+)\s*\(([A-Z]{2})\)\s*$/u', $d, $m)) { $r['product'] = $m[1]; $r['pcs_per_box'] = (int)$m[2]; $r['pack_mode'] = $m[3]; $r['origin'] = $m[4]; }
    return $r;
}

/** Visual lines of one page (clustered by y, tolerance in pt), top to bottom, left to right. */
function sipdf_lines(array $segs, float $tol = 2.5): array
{
    usort($segs, fn($a, $b) => $b['y'] <=> $a['y'] ?: $a['x'] <=> $b['x']);
    $lines = [];
    foreach ($segs as $s) {
        $placed = false;
        foreach ($lines as &$ln) if (abs($ln['y'] - $s['y']) <= $tol) { $ln['segs'][] = $s; $placed = true; break; }
        unset($ln);
        if (!$placed) $lines[] = ['y' => $s['y'], 'segs' => [$s]];
    }
    foreach ($lines as &$ln) usort($ln['segs'], fn($a, $b) => $a['x'] <=> $b['x']);
    unset($ln);
    return $lines;
}

/** Page furniture (letter-head, repeated page header, bank footer): excluded from the BODY stream. */
function sipdf_is_furniture(array $line): bool
{
    $t = sipdf_ltext($line);
    if (preg_match('/Geschäftsführer|Sparkasse Erding|Deutsche Bank AG|Commerzbank AG|Allgemeine Geschäftsbedingungen|\bBIC [A-Z0-9]{8}|\bIBAN [A-Z]{2}\d|^HRB \d+|Lebensmittelvertrieb GmbH/u', $t)) return true;
    // Antet altbilgisindeki yönetici adları tek başına bir satır olabilir. Kişi adı kaynakta AÇIK yazılmasın diye
    // (CLAUDE.md: kişisel isim kullanma) yalnız SHA-1 özetleriyle karşılaştırılır — davranış v2 ile birebir aynı.
    if (in_array(sha1($t), ['3b91562981e0cba63bf0bcdfb0710ef00d569079', '6a9d86961a2c1ce16265a49d9a4b6f4b69b732c8', 'ff79e0cefcef4be4dc89750d53cdaab690c16dbc'], true)) return true;
    if (preg_match('/^(Agency|OGL Food Trade)$/', $t)) return true;                                   // continuation-page header, left part
    if (preg_match('/^Date\s+No\. supplier\s+Page$/', $t)) return true;                              // continuation-page header, right part (labels)
    if (preg_match('/^\d{2}\.\d{2}\.\d{4}\s+\d+\s+\d+\s*\/\s*\d+$/', $t)) return true;              // ... (values)
    return false;
}

/** All pages -> one ordered body line stream. Each line gets 'page'. Furniture removed. */
function sipdf_body_lines(array $pages): array
{
    $out = [];
    foreach ($pages as $pn => $segs) foreach (sipdf_lines($segs) as $ln) { if (sipdf_is_furniture($ln)) continue; $ln['page'] = $pn; $out[] = $ln; }
    return $out;
}

/** Estimated horizontal extent of a segment (no font metrics available: 0.5 em per character). */
function sipdf_seg_w(array $s): float { return mb_strlen(sipdf_t($s)) * $s['size'] * 0.5; }

/**
 * Column positions from header labels on the page (never fixed x values).
 * $spec = ['key' => '/regex/', 'key2' => ['/regex/', 'optional'], ...] in LEFT-TO-RIGHT order; labels are searched sequentially,
 * so the same text may appear twice (e.g. "Price" ... "Price unit").
 * Returns ['key' => ['x'=>left, 'c'=>estimated centre]] or null when a required label is missing.
 */
function sipdf_cols_from_labels(array $segs, array $spec): ?array
{
    $segs = array_values($segs);
    $p = 0; $cols = [];
    foreach ($spec as $key => $def) {
        $re = is_array($def) ? $def[0] : $def; $opt = is_array($def);
        $found = null;
        for ($i = $p; $i < count($segs); $i++) if (preg_match($re, sipdf_t($segs[$i]))) { $found = $i; break; }
        if ($found === null) { if ($opt) continue; return null; }
        $s = $segs[$found];
        $cols[$key] = ['x' => $s['x'], 'c' => $s['x'] + sipdf_seg_w($s) / 2];
        $p = $found + 1;
    }
    return $cols;
}
/** Which column does a data segment belong to? mode 'left': right-most header whose x <= seg.x + tol ; mode 'center': nearest centre. */
function sipdf_col_of(array $cols, array $seg, string $mode = 'left', float $tol = 12.0): ?string
{
    if (!$cols) return null;
    if ($mode === 'center') {
        $c = $seg['x'] + sipdf_seg_w($seg) / 2; $best = null; $bd = 1e9;
        foreach ($cols as $k => $col) { $d = abs($col['c'] - $c); if ($d < $bd) { $bd = $d; $best = $k; } }
        return $best;
    }
    $best = null; $bx = -1e9;
    foreach ($cols as $k => $col) if ($col['x'] <= $seg['x'] + $tol && $col['x'] > $bx) { $bx = $col['x']; $best = $k; }
    return $best;
}
/** Split one line into column => [texts]. */
function sipdf_line_cells(array $line, array $cols, string $mode = 'left', float $tol = 12.0): array
{
    $cells = [];
    foreach ($line['segs'] as $s) { $k = sipdf_col_of($cols, $s, $mode, $tol); if ($k === null) $k = '_'; $cells[$k][] = sipdf_t($s); }
    return $cells;
}

/* =====================================================================================================
 * PART 3 - CLASSIFICATION + COMMON HEADER
 * ===================================================================================================== */

/** 'purchase_order' | 'price_confirmation' | 'loading_list' | 'unknown'  (decided from the title text on PAGE 1 only). */
function siparis_pdf_belge_turu(array $pages): string
{
    $t = '';
    foreach ($pages[1] ?? ($pages ? reset($pages) : []) as $s) $t .= sipdf_t($s) . "\n";
    $hits = [];
    if (preg_match('/Purchase order for/i', $t)) $hits[] = 'purchase_order';
    if (preg_match('/Price confirmation for/i', $t)) $hits[] = 'price_confirmation';
    if (preg_match('/Loading List\s*-\s*Ladeplan|^Loading List\b/mi', $t)) $hits[] = 'loading_list';
    return count($hits) === 1 ? $hits[0] : 'unknown';
}

/** The right-hand box of the OGL print layout (PO + price confirmation): CW, date(+time), supplier no, clerk, e-mail, page x/y. */
function sipdf_ogl_header(array $pages): array
{
    $h = ['cw' => null, 'cw_year' => null, 'date' => null, 'time' => null, 'supplier_no' => null, 'clerk' => null, 'clerk_email' => null, 'page_count' => null];
    foreach (sipdf_lines($pages[1] ?? []) as $ln) {
        $segs = $ln['segs']; $t = sipdf_ltext($ln);
        if ($h['cw'] === null && (preg_match('/^CW (\d+)\.(\d{4})$/', $t, $m) || preg_match('/(?:Purchase order|Price confirmation) for (\d+)\.(\d{4})/', $t, $m))) { $h['cw'] = (int)$m[1]; $h['cw_year'] = (int)$m[2]; }
        if (preg_match('/Page\s+(\d+)\s*\/\s*(\d+)/', $t, $m)) $h['page_count'] = (int)$m[2];
        $lab = sipdf_t($segs[0]);
        if ($lab === 'Date' && $h['date'] === null && isset($segs[1])) { $h['date'] = sipdf_dmy(sipdf_t($segs[1])); if (isset($segs[2]) && preg_match('/^\d{2}:\d{2}:\d{2}$/', sipdf_t($segs[2]))) $h['time'] = sipdf_t($segs[2]); }
        if (preg_match('/^Supplier no\.?$/', $lab) && isset($segs[1])) $h['supplier_no'] = sipdf_t($segs[1]);
        if ($lab === 'Clerk' && isset($segs[1])) $h['clerk'] = sipdf_t($segs[1]);
        if ($lab === 'Email' && isset($segs[1])) $h['clerk_email'] = sipdf_t($segs[1]);
    }
    return $h;
}

/* =====================================================================================================
 * PART 4 - PURCHASE ORDER
 * ===================================================================================================== */
/**
 * siparis_pdf_parse_order($pages, $meta=[]) -> array
 * {
 *  doc_type: 'purchase_order',
 *  header: {cw,cw_year,date,time,supplier_no,clerk,clerk_email,page_count},
 *  destination: {id,name}|null,                 // "Summery purchase orders with destination: <id> <name>" = goods recipient ID + name
 *  items: [ {item_no,ian,description,product,pcs_per_box,pack_mode,origin,pallets,pf,quantity,quantity_unit,incoterm,article_no,
 *            calibre_field,box_weight,variety,alt_calibre,ean_cert,packaging,box_dims,quality,extra_lines[]} ],       // summary table (all loadings together)
 *  loadings: [ {loading_date,logistics,incoterm,order_ref,tour,loading_point,customs_raw,customs_office,vat_no,carrier_text,carrier_name,
 *               planned_plates[],planned_time,sold_to,page,
 *               columns:[{name,role('customer'|'location')}],
 *               items:[{ian,description,product,pcs_per_box,pack_mode,origin,price_eur,bpm_unit,pallets,pallet_type,pf,quantity,quantity_unit,
 *                       calibre_field,box_weight,variety,alt_calibre,summary_item_no,
 *                       locations:[{name,role,code,pallets,pf,cartons}]}],
 *               totals:{pallets,quantity,by_location:[{name,pallets,cartons}]} } ],        // ONE entry per ORDER REF (= one truck)
 *  unloading_locations: [{name,code,address}],  pallet_types: [{code,name}],
 *  checks: [{id,scope,ok,expected,actual}], warnings: [{level,code,message,context?}], meta
 * }
 */
function siparis_pdf_parse_order(array $pages, array $meta = []): array
{
    $w = []; $checks = [];
    $out = ['doc_type' => 'purchase_order', 'header' => sipdf_ogl_header($pages), 'destination' => null, 'items' => [], 'loadings' => [],
            'unloading_locations' => [], 'pallet_types' => [], 'instruction_lines' => [], 'packing_list_emails' => [], 'checks' => [], 'warnings' => [], 'meta' => $meta];
    $L = sipdf_body_lines($pages);
    $n = count($L);

    // ---- 1. segment the body into regions: summary(s) | loading blocks | addresses | pallet types ----
    $summary = []; $blocks = []; $addr = []; $ptypes = []; $mode = 'pre'; $bi = -1;
    foreach ($L as $ln) {
        $t = sipdf_ltext($ln);
        if (preg_match('/Summ[ae]ry purchase orders with destination:\s*(\d+)\s+(.+)$/u', $t, $m)) {
            $out['destination'] ??= ['id' => $m[1], 'name' => trim($m[2])];
            if ($out['destination']['id'] !== $m[1]) sipdf_warn($w, 'warn', 'multiple_destinations', 'More than one destination section in one PO file; loadings are not attributed to destinations.', ['id' => $m[1]]);
            $mode = 'summary'; $summary[] = ['dest' => $m[1], 'lines' => []]; continue;
        }
        if (preg_match('/^Loading date\s+(\d{2}\.\d{2}\.\d{4})\s+Logistics\s+(.*?)\s+INC:\s*(\w+)/u', $t, $m)) {
            $blocks[] = ['loading_date' => sipdf_dmy($m[1]), 'logistics' => trim($m[2]), 'incoterm' => $m[3], 'page' => $ln['page'], 'lines' => []];
            $bi = count($blocks) - 1; $mode = 'block'; continue;
        }
        if (preg_match('/^Unloading addresses/i', $t)) { $mode = 'addr'; continue; }
        if (preg_match('/^Pallet types:?/i', $t)) { $mode = 'ptypes'; continue; }
        if ($mode === 'summary') $summary[count($summary) - 1]['lines'][] = $ln;
        elseif ($mode === 'block') $blocks[$bi]['lines'][] = $ln;
        elseif ($mode === 'addr') $addr[] = $ln;
        elseif ($mode === 'ptypes') $ptypes[] = $ln;
    }

    // ---- 2. summary table(s) ----
    foreach ($summary as $sec) { foreach (sipdf_po_parse_summary($sec['lines'], $sec['dest'], $w) as $it) $out['items'][] = $it; }
    // standing instructions printed below the summary table (page 1-2): kept as text; the packing-list recipients are extracted (functional mailboxes)
    $instr = [];
    foreach ($summary as $sec) { $on = false; foreach ($sec['lines'] as $ln) { $t = sipdf_ltext($ln); if (preg_match('/^\*/', $t)) $on = true; if ($on && $t !== '') $instr[] = $t; } }
    $out['instruction_lines'] = $instr;
    $joined = ''; foreach ($instr as $t) $joined .= (substr($joined, -1) === '-' ? '' : ' ') . $t;
    $out['packing_list_emails'] = [];
    if (preg_match('/per E-?Mail to:\s*(.+?)(?:\s{2,}|$)/i', $joined, $m)) { preg_match_all('/[A-Za-z0-9._\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)+/', $m[1], $em); $out['packing_list_emails'] = array_values(array_unique($em[0])); }
    if (!$summary) sipdf_warn($w, 'error', 'no_summary', 'Summary table ("Summery purchase orders with destination") not found.');

    // ---- 3. unloading addresses + pallet types ----
    foreach ($addr as $ln) {
        $segs = $ln['segs']; $first = sipdf_t($segs[0]);
        if (preg_match('/^(.+):$/u', $first, $m) && isset($segs[1]) && preg_match('/^\d+$/', sipdf_t($segs[1]))) {
            $rest = array_slice($segs, 2); $txt = trim(implode(' ', array_map('sipdf_t', $rest))); $txt = preg_replace('/^-\s*/', '', $txt);
            $out['unloading_locations'][] = ['name' => $m[1], 'code' => sipdf_t($segs[1]), 'address' => $txt];
        } elseif (preg_match('/^(.+?):\s+(\d+)\s+-\s+(.+)$/u', sipdf_ltext($ln), $m)) $out['unloading_locations'][] = ['name' => $m[1], 'code' => $m[2], 'address' => trim($m[3])];
    }
    foreach ($ptypes as $ln) if (preg_match('/^(\w{1,4})\s+-\s+(.+)$/u', sipdf_ltext($ln), $m)) $out['pallet_types'][] = ['code' => $m[1], 'name' => trim($m[2])];

    // ---- 4. loading blocks -> one loading per order ref ----
    foreach ($blocks as $b) foreach (sipdf_po_parse_block($b, $w) as $o) {
        $key = $o['loading_date'] . '|' . $o['order_ref'];
        foreach ($out['loadings'] as $ex) if ($ex['loading_date'] . '|' . $ex['order_ref'] === $key) sipdf_warn($w, 'warn', 'duplicate_order_in_po', 'Same loading date + order ref appears twice in one PO.', ['order_ref' => $o['order_ref']]);
        $out['loadings'][] = $o;
    }
    if (!$out['loadings']) sipdf_warn($w, 'error', 'no_loadings', 'No "Loading date" block found.');

    // ---- 5. role resolution of right-hand columns (known unloading address => location; otherwise first = customer) ----
    $addrNames = array_map(fn($a) => mb_strtoupper($a['name']), $out['unloading_locations']);
    $addrByName = []; foreach ($out['unloading_locations'] as $a) $addrByName[mb_strtoupper($a['name'])] = $a;
    foreach ($out['loadings'] as &$o) {
        $custName = null;
        foreach ($o['columns'] as $ci => &$c) {
            $isLoc = in_array(mb_strtoupper($c['name']), $addrNames, true);
            $c['role'] = $isLoc ? 'location' : ($ci === 0 ? 'customer' : 'location');
            if ($c['role'] === 'customer' && $custName === null) $custName = $c['name'];
        }
        unset($c);
        $o['sold_to'] = $custName;
        foreach ($o['items'] as &$it) foreach ($it['locations'] as &$loc) {
            $loc['role'] = 'location'; foreach ($o['columns'] as $c) if ($c['name'] === $loc['name']) $loc['role'] = $c['role'];
            $loc['code'] = $addrByName[mb_strtoupper($loc['name'])]['code'] ?? null;
        }
        unset($it, $loc);
        // recompute totals by location (locations only)
        $by = [];
        foreach ($o['items'] as $it) foreach ($it['locations'] as $loc) if ($loc['role'] === 'location') { $by[$loc['name']]['pallets'] = ($by[$loc['name']]['pallets'] ?? 0) + (int)$loc['pallets']; $by[$loc['name']]['cartons'] = ($by[$loc['name']]['cartons'] ?? 0) + (int)$loc['cartons']; }
        $o['totals']['by_location'] = []; foreach ($by as $nm => $v) $o['totals']['by_location'][] = ['name' => $nm] + $v;
    }
    unset($o);

    // ---- 6. link block items to summary items (ian + pcs_per_box) ----
    foreach ($out['loadings'] as &$o) foreach ($o['items'] as &$it) {
        $it['summary_item_no'] = null;
        foreach ($out['items'] as $si) if ($si['ian'] === $it['ian'] && $si['description'] === $it['description']) { $it['summary_item_no'] = $si['item_no']; break; }
        if ($it['summary_item_no'] === null) sipdf_warn($w, 'warn', 'item_not_in_summary', 'Loading item has no matching summary item.', ['order_ref' => $o['order_ref'], 'description' => $it['description']]);
    }
    unset($o, $it);

    // ---- 7. cross-checks ----
    foreach ($out['items'] as $si) {
        if ($si['pallets'] !== null && $si['pf'] !== null && $si['quantity'] !== null)
            sipdf_check($checks, $w, 'summary_pallets_x_pf', 'item ' . $si['item_no'], $si['pallets'] * $si['pf'] === $si['quantity'], $si['pallets'] * $si['pf'], $si['quantity'], 'pallets x PF must equal quantity.');
        $sp = 0; $sq = 0; $cnt = 0;
        foreach ($out['loadings'] as $o) foreach ($o['items'] as $it) if ($it['summary_item_no'] === $si['item_no']) { $sp += (int)$it['pallets']; $sq += (int)$it['quantity']; $cnt++; }
        sipdf_check($checks, $w, 'sum_blocks_pallets', 'item ' . $si['item_no'], $sp === $si['pallets'], $si['pallets'], $sp, 'Sum of loading-block pallets must equal summary pallets.');
        sipdf_check($checks, $w, 'sum_blocks_quantity', 'item ' . $si['item_no'], $sq === $si['quantity'], $si['quantity'], $sq, 'Sum of loading-block quantity must equal summary quantity.');
    }
    foreach ($out['loadings'] as $o) {
        $sc = 'order ' . $o['order_ref'];
        foreach ($o['items'] as $it) {
            $sc2 = $sc . ' / ' . ($it['pcs_per_box'] ?? '?') . ' PC';
            if ($it['pallets'] !== null && $it['pf'] !== null && $it['quantity'] !== null)
                sipdf_check($checks, $w, 'block_pallets_x_pf', $sc2, $it['pallets'] * $it['pf'] === $it['quantity'], $it['pallets'] * $it['pf'], $it['quantity'], 'pallets x PF must equal quantity.');
            $locs = array_values(array_filter($it['locations'], fn($l) => $l['role'] === 'location'));
            if ($locs) {
                $lp = array_sum(array_map(fn($l) => (int)$l['pallets'], $locs)); $lq = array_sum(array_map(fn($l) => (int)$l['cartons'], $locs));
                sipdf_check($checks, $w, 'sum_locations_pallets', $sc2, $lp === $it['pallets'], $it['pallets'], $lp, 'Sum of per-location pallets must equal row pallets.');
                sipdf_check($checks, $w, 'sum_locations_quantity', $sc2, $lq === $it['quantity'], $it['quantity'], $lq, 'Sum of per-location cartons must equal row quantity.');
                foreach ($locs as $l) if ($l['pallets'] !== null && $l['pf'] !== null && $l['cartons'] !== null)
                    sipdf_check($checks, $w, 'location_pallets_x_pf', $sc2 . ' @ ' . $l['name'], $l['pallets'] * $l['pf'] === $l['cartons'], $l['pallets'] * $l['pf'], $l['cartons'], 'pallets x PF must equal cartons.');
            }
        }
        if ($o['tour'] === null) sipdf_warn($w, 'warn', 'no_tour', 'Order has no tour id.', ['order_ref' => $o['order_ref']]);
    }
    if ($out['header']['page_count'] !== null && $out['header']['page_count'] !== count($pages))
        sipdf_warn($w, 'warn', 'page_count', 'Header says ' . $out['header']['page_count'] . ' pages, found ' . count($pages) . '.');
    $out['checks'] = $checks; $out['warnings'] = $w;
    return $out;
}

/** Summary table: item rows + their detail lines. */
function sipdf_po_parse_summary(array $lines, string $destId, array &$w): array
{
    $cols = null; $items = []; $cur = null;
    $spec = ['item' => '/^Item$/', 'ian' => '/^IAN$/', 'desc' => '/^Article descr/', 'pallets' => '/^Pal$/', 'pf' => '/^PF$/', 'quantity' => '/^Quantity$/', 'unit' => '/^Unit$/', 'incoterm' => '/^Incoterm$/'];
    foreach ($lines as $ln) {
        if ($cols === null) { $c = sipdf_cols_from_labels($ln['segs'], $spec); if ($c) $cols = $c; continue; }
        $t = sipdf_ltext($ln);
        if (preg_match('/^\*|^Note:/u', $t)) break;                                                // end of the table
        $cells = sipdf_line_cells($ln, $cols, 'left', 14);
        $isItem = isset($cells['item'][0]) && preg_match('/^\d{1,3}$/', $cells['item'][0]) && isset($cells['ian'][0]) && preg_match('/^\d{4,}$/', $cells['ian'][0]);
        if ($isItem) {
            if ($cur) $items[] = $cur;
            $d = $cells['desc'][0] ?? null;
            $cur = ['item_no' => (int)$cells['item'][0], 'ian' => $cells['ian'][0]] + sipdf_parse_article($d) + [
                'pallets' => sipdf_int($cells['pallets'][0] ?? null), 'pf' => sipdf_int($cells['pf'][0] ?? null), 'quantity' => sipdf_int($cells['quantity'][0] ?? null),
                'quantity_unit' => $cells['unit'][0] ?? null, 'incoterm' => $cells['incoterm'][0] ?? null, 'destination_id' => $destId,
                'article_no' => null, 'calibre_field' => null, 'box_weight' => null, 'variety' => null, 'alt_calibre' => null, 'ean_cert' => null, 'packaging' => null, 'box_dims' => null, 'quality' => null, 'extra_lines' => []];
            continue;
        }
        if (!$cur) continue;
        // detail lines of the current item
        $art = $cells['ian'][0] ?? null; $desc = trim(implode(' ', $cells['desc'] ?? []));
        if ($art !== null && preg_match('/^\d{8}$/', $art) && $cur['article_no'] === null) {
            $cur['article_no'] = $art;
            $parts = preg_split('/,\s+/', $desc);                                                  // "-", "3,5 KG", "WONDERFUL"
            // "3,5 KG" contains a comma but no following space, so it survives the split
            $cur['calibre_field'] = $parts[0] ?? null; $cur['box_weight'] = $parts[1] ?? null; $cur['variety'] = $parts[2] ?? null; $cur['alt_calibre'] = $parts[3] ?? null;
        } elseif ($desc === '-') { $cur['ean_cert'] = null; }
        elseif (preg_match('/^(\w+),\s*(\d+\s*x\s*\d+\s*x\s*\d+\s*CM)/iu', $desc, $m)) { $cur['packaging'] = $m[1]; $cur['box_dims'] = $m[2]; }
        elseif (preg_match('/Brix|Color|Quality/i', $desc)) $cur['quality'] = preg_replace('/\s+/', ' ', $desc);
        elseif ($desc !== '') $cur['extra_lines'][] = $desc;
    }
    if ($cur) $items[] = $cur;
    if ($cols === null) sipdf_warn($w, 'error', 'summary_header', 'Summary table header labels not found.');
    foreach ($items as $it) if ($it['article_no'] === null) sipdf_warn($w, 'warn', 'no_article_no', 'Summary item without Art.no.', ['item_no' => $it['item_no']]);
    return $items;
}

/** One "Loading date" block -> list of loadings (one per order ref). */
function sipdf_po_parse_block(array $b, array &$w): array
{
    $spec = ['order_ref' => '/^Order ref\.?$/', 'tour' => '/^Tour$/', 'loading_point' => '/^Loading point$/', 'customs' => '/^Customs$/', 'article' => '/^Article specification$/',
             'price' => '/^Price$/', 'bpm' => '/^BPM$/', 'pallets' => '/^Pal\.?$/', 'pallet_type' => '/^P\.ART\.?$/', 'pf' => '/^PF$/', 'quantity' => '/^Quantity$/'];
    $cols = null; $clusters = []; $hdrTexts = []; $hdrZone = false; $firstHdr = false; $orders = []; $o = null; $iCur = null;
    $ctx = ['loading_date' => $b['loading_date'], 'logistics' => $b['logistics'], 'incoterm' => $b['incoterm'], 'page' => $b['page']];
    foreach ($b['lines'] as $ln) {
        $c = sipdf_cols_from_labels($ln['segs'], $spec);
        if ($c) {                                                                                   // header label line (first one, or repeated on a continuation page)
            $first = ($cols === null); $cols = $c; $hdrZone = true; $firstHdr = $first;       // repeated header on a continuation page: refresh columns only
            if ($first) { $clusters = []; $hdrTexts = [sipdf_ltext($ln)]; sipdf_po_add_header_words($clusters, $ln, $cols); }
            continue;
        }
        if ($cols === null) continue;
        $qx = $cols['quantity']['x'];
        // is this a data line? (order-ref in order_ref column, or an article-spec cell)
        $cells = sipdf_line_cells($ln, $cols, 'left', 10);
        $hasRef = false; foreach ($cells['order_ref'] ?? [] as $x) if (sipdf_is_ref($x)) $hasRef = true;
        $hasItem = false; foreach ($cells['article'] ?? [] as $x) if (preg_match('/^\d{4,},\s+/', $x)) $hasItem = true;
        if ($hdrZone) {
            if (!$hasRef && !$hasItem) {
                if ($firstHdr) { $hdrTexts[] = sipdf_ltext($ln); sipdf_po_add_header_words($clusters, $ln, $cols); continue; }   // first header: stack of customer/location words
                if (in_array(sipdf_ltext($ln), $hdrTexts, true)) continue;                                                  // repeated header: skip lines identical to the first header's
                $hdrZone = false;                                                                                         // anything else is data (e.g. carrier text) of the continued order
            } else $hdrZone = false;
        }
        if ($hasRef) {                                                                              // new order (truck) inside the block
            if ($o) $orders[] = $o;
            $o = $ctx + ['order_ref' => null, 'tour' => null, 'loading_point' => null, 'customs_raw' => null, 'customs_office' => null, 'vat_no' => null, 'carrier_text' => null, 'carrier_name' => null,
                         'planned_plates' => [], 'planned_time' => null, 'sold_to' => null, 'columns' => [], 'items' => [], 'totals' => ['pallets' => 0, 'quantity' => 0, 'by_location' => []],
                         '_lp' => [], '_cu' => [], '_ca' => []];
            $iCur = null;
        }
        if (!$o) continue;
        if ($hasItem) { $o['items'][] = ['ian' => null, 'description' => null, 'product' => null, 'pcs_per_box' => null, 'pack_mode' => null, 'origin' => null, 'price_eur' => null, 'bpm_unit' => null, 'pallets' => null,
                                         'pallet_type' => null, 'pf' => null, 'quantity' => null, 'quantity_unit' => null, 'calibre_field' => null, 'box_weight' => null, 'variety' => null, 'alt_calibre' => null,
                                         'summary_item_no' => null, 'locations' => [], '_q' => [], '_loc' => []]; $iCur = count($o['items']) - 1; }
        // left part: order ref / vat / tour / carrier / loading point / customs
        foreach ($ln['segs'] as $s) {
            $k = sipdf_col_of($cols, $s, 'left', 10); $x = sipdf_t($s);
            if ($s['x'] >= $qx + 20) {                                                              // right of Quantity: per-location cells
                if ($iCur === null) continue;
                $ci = sipdf_po_nearest_cluster($clusters, $s['x']);
                if ($ci !== null) $o['items'][$iCur]['_loc'][$ci][] = $x;
                continue;
            }
            switch ($k) {
                case 'order_ref':
                    if (sipdf_is_ref($x)) $o['order_ref'] = $x; elseif (preg_match('/^[A-Z]{2}[A-Z0-9]{6,}$/', $x)) $o['vat_no'] = $x; break;
                case 'tour':
                    if ($hasRef && $o['tour'] === null && preg_match('/^\d{6,}$/', $x)) $o['tour'] = $x;
                    elseif (preg_match('/^\d{1,2}:\d{2}:\d{2}\s*[AP]M$/i', $x)) $o['planned_time'] = $x;
                    elseif (!in_array($x, ['Truck', 'Time'], true)) $o['_ca'][] = $x;
                    break;
                case 'loading_point': $o['_lp'][] = $x; break;
                case 'customs': $o['_cu'][] = $x; break;
                case 'article':
                    if ($iCur !== null) {
                        $it = &$o['items'][$iCur];
                        if (preg_match('/^(\d{4,}),\s+(.+)$/u', $x, $m)) { $it['ian'] = $m[1]; $it = array_merge($it, array_intersect_key(sipdf_parse_article($m[2]), array_flip(['description', 'product', 'pcs_per_box', 'pack_mode', 'origin']))); }
                        else { $parts = preg_split('/,\s+/', $x); $it['calibre_field'] = $parts[0] ?? null; $it['box_weight'] = $parts[1] ?? null; $it['variety'] = $parts[2] ?? null; $it['alt_calibre'] = $parts[3] ?? null; }
                        unset($it);
                    }
                    break;
                case 'price': if ($iCur !== null && $o['items'][$iCur]['price_eur'] === null && sipdf_dec($x) !== null) $o['items'][$iCur]['price_eur'] = sipdf_dec($x); break;
                case 'bpm': if ($iCur !== null) $o['items'][$iCur]['bpm_unit'] = $x; break;
                case 'pallets': if ($iCur !== null && $o['items'][$iCur]['pallets'] === null) $o['items'][$iCur]['pallets'] = sipdf_int($x); break;
                case 'pallet_type': if ($iCur !== null) $o['items'][$iCur]['pallet_type'] = $x; break;
                case 'pf': if ($iCur !== null && $o['items'][$iCur]['pf'] === null) $o['items'][$iCur]['pf'] = sipdf_int($x); break;
                case 'quantity': if ($iCur !== null) $o['items'][$iCur]['_q'][] = $x; break;
            }
        }
    }
    if ($o) $orders[] = $o;
    // finalize each order
    foreach ($orders as &$o) {
        $o['columns'] = array_map(fn($c) => ['name' => $c['name'], 'role' => null], $clusters);
        $o['loading_point'] = $o['_lp'] ? trim(implode(' ', $o['_lp'])) : null;
        $cu = []; foreach ($o['_cu'] as $x) { if ($cu && mb_strlen($x) === 1) $cu[count($cu) - 1] .= $x; else $cu[] = $x; }        // "ZOLLSTELL"+"E" (hard-wrapped cell)
        $o['customs_raw'] = $cu ? implode(' ', $cu) : null;
        $o['customs_office'] = $cu ? trim(preg_replace('/^ZOLLSTELLE\s*/u', '', implode(' ', $cu))) ?: null : null;
        $carr = [];
        foreach ($o['_ca'] as $x) { if (preg_match('/^\d{2}\s?[A-Z]{1,3}\s?\d{2,4}$/', $x)) $o['planned_plates'][] = preg_replace('/\s+/', '', $x); else $carr[] = $x; }
        $o['carrier_name'] = $carr ? implode(' ', $carr) : null;
        $o['carrier_text'] = $o['_ca'] ? implode(' ', $o['_ca']) : null;
        foreach ($o['items'] as &$it) {
            $q = implode(' ', $it['_q']);
            if (preg_match('/([\d,]+)/', $q, $m)) $it['quantity'] = sipdf_int($m[1]);
            if (preg_match('/\b([A-Z]{2,4})\b/', $q, $m)) $it['quantity_unit'] = $m[1];
            ksort($it['_loc']);
            foreach ($it['_loc'] as $ci => $tx) {
                $loc = ['name' => $clusters[$ci]['name'], 'role' => null, 'code' => null, 'pallets' => null, 'pf' => null, 'cartons' => null];
                foreach ($tx as $x) { if (preg_match('/^(\d+)\s*x\s*\((\d+)\)$/', $x, $m)) { $loc['pallets'] = (int)$m[1]; $loc['pf'] = (int)$m[2]; } elseif (preg_match('/^[\d,]+$/', $x)) $loc['cartons'] = sipdf_int($x); }
                $it['locations'][] = $loc;
            }
            unset($it['_q'], $it['_loc']);
        }
        unset($it);
        $o['totals']['pallets'] = array_sum(array_map(fn($i) => (int)$i['pallets'], $o['items']));
        $o['totals']['quantity'] = array_sum(array_map(fn($i) => (int)$i['quantity'], $o['items']));
        unset($o['_lp'], $o['_cu'], $o['_ca']);
        if ($o['order_ref'] === null) sipdf_warn($w, 'error', 'order_ref_missing', 'Order row without order ref.');
        if (!$o['items']) sipdf_warn($w, 'warn', 'order_without_items', 'Order has no item rows.', ['order_ref' => $o['order_ref']]);
    }
    unset($o);
    return $orders;
}
/** Header words right of the Quantity column (customer / unloading-location names), stacked in several lines -> clusters by x. */
function sipdf_po_add_header_words(array &$clusters, array $line, array $cols): void
{
    $qx = $cols['quantity']['x'];
    foreach ($line['segs'] as $s) {
        if ($s['x'] < $qx + 20) continue;
        $x = sipdf_t($s); if ($x === '' || preg_match('/^EUR$/', $x)) continue;
        $hit = null; foreach ($clusters as $i => $c) if (abs($c['x'] - $s['x']) <= 20) { $hit = $i; break; }
        if ($hit === null) $clusters[] = ['x' => $s['x'], 'words' => [['y' => $line['y'], 'w' => $x]], 'name' => $x]; else $clusters[$hit]['words'][] = ['y' => $line['y'], 'w' => $x];
    }
    usort($clusters, fn($a, $b) => $a['x'] <=> $b['x']);
    foreach ($clusters as &$c) { usort($c['words'], fn($a, $b) => $b['y'] <=> $a['y']); $c['name'] = implode(' ', array_column($c['words'], 'w')); }
    unset($c);
}
function sipdf_po_nearest_cluster(array $clusters, float $x): ?int
{
    $best = null; $bd = 1e9;
    foreach ($clusters as $i => $c) { $d = abs($c['x'] - $x); if ($d < $bd) { $bd = $d; $best = $i; } }
    return $best;
}

/* =====================================================================================================
 * PART 5 - PRICE CONFIRMATION
 * ===================================================================================================== */
/**
 * siparis_pdf_parse_price_confirmation($pages, $meta=[]) -> array
 * {
 *  doc_type: 'price_confirmation',
 *  header: {cw,cw_year,date,time,supplier_no,clerk,clerk_email,page_count},
 *  cw: {week,year}|null, destination_text: "LIDL DISCOUNT SRL, LIDL UK GMBH", sold_to_list: [..],
 *  rows: [ {row_no, item_group, sold_to, ian, description, product, pcs_per_box, pack_mode, origin, collo_content, preisrel, variety, box_weight,
 *           order_ref, pricing_date, quantity, promo, incoterm, price, currency, price_unit, price_inherited(bool)} ],   // ONE ROW PER (order_ref, item)
 *  totals_by_order_ref: [{order_ref, quantity, pricing_date}],
 *  checks, warnings, meta
 * }
 * The price/currency/incoterm are PRINTED ONLY ON THE FIRST ROW of an item; the continuation rows (same item, other order ref)
 * print only order ref / pricing date / quantity - they inherit the item's price (price_inherited=true).
 * pricing_date equals the planned/actual UNLOADING date of the truck (matches the loading list "Unloading Date").
 */
function siparis_pdf_parse_price_confirmation(array $pages, array $meta = []): array
{
    $w = []; $checks = [];
    $out = ['doc_type' => 'price_confirmation', 'header' => sipdf_ogl_header($pages), 'cw' => null, 'destination_text' => null, 'sold_to_list' => [], 'rows' => [],
            'totals_by_order_ref' => [], 'checks' => [], 'warnings' => [], 'meta' => $meta];
    $spec = ['sold_to' => '/^Sold-to$/', 'ian' => '/^IAN$/', 'description' => '/^Article description$/', 'collo' => '/^Collo$/', 'origin' => '/^Origin$/', 'preisrel' => '/^Preisrel/',
             'variety' => '/^Variety/', 'order_ref' => '/^Purchase$/', 'pricing_date' => '/^Pricing$/', 'quantity' => '/^Quantity$/', 'promo' => ['/^Promo$/'], 'incoterm' => '/^Inco/',
             'price' => '/^Price$/', 'currency' => '/^Curr/', 'price_unit' => '/^Price$/'];
    $cols = null; $ctx = null; $group = 0; $skipHdr = false; $rowNo = 0;
    foreach (sipdf_body_lines($pages) as $ln) {
        $t = sipdf_ltext($ln);
        if (preg_match('/^CW:\s*(\d+)\.(\d{4})\s+Destination:\s*(.*)$/u', $t, $m)) {
            $out['cw'] = ['week' => (int)$m[1], 'year' => (int)$m[2]]; $out['destination_text'] = trim($m[3]);
            $out['sold_to_list'] = array_values(array_filter(array_map('trim', explode(',', $m[3])))); continue;
        }
        $c = sipdf_cols_from_labels($ln['segs'], $spec);
        if ($c) { $cols = $c; $skipHdr = true; continue; }
        if ($cols === null) continue;
        $cells = sipdf_line_cells($ln, $cols, 'left', 12);
        $ref = null; foreach ($cells['order_ref'] ?? [] as $x) if (sipdf_is_ref($x)) $ref = $x;
        if ($ref === null) { if (!$skipHdr && $t !== '' && !preg_match('/^(content|order ref\.|date|unit)/i', $t)) sipdf_warn($w, 'info', 'ignored_line', 'Line below table header ignored.', ['text' => $t]); continue; }
        $skipHdr = false;
        $one = fn($k) => isset($cells[$k]) ? trim(implode(' ', $cells[$k])) : null;
        $startsItem = ($one('sold_to') !== null && $one('sold_to') !== '') || ($one('description') !== null && $one('description') !== '');
        if ($startsItem) {
            $group++;
            $art = sipdf_parse_article($one('description'));
            $var = $one('variety'); $vparts = $var !== null ? preg_split('/,\s+/', $var) : [];
            $ctx = ['item_group' => $group, 'sold_to' => $one('sold_to') ?: ($ctx['sold_to'] ?? null), 'ian' => $one('ian')] + $art + [
                'collo_content' => sipdf_dec($one('collo')), 'preisrel' => $one('preisrel'), 'variety' => $vparts[0] ?? null, 'box_weight' => $vparts[1] ?? null,
                'incoterm' => $one('incoterm'), 'price' => sipdf_dec($one('price')), 'currency' => $one('currency'), 'price_unit' => $one('price_unit')];
            if ($ctx['origin'] === null) $ctx['origin'] = $one('origin');
            $inherited = false;
            if ($ctx['price'] === null) sipdf_warn($w, 'warn', 'price_missing', 'Item row without a price.', ['description' => $ctx['description'], 'order_ref' => $ref]);
        } else {
            if ($ctx === null) { sipdf_warn($w, 'warn', 'orphan_row', 'Continuation row before any item row.', ['order_ref' => $ref]); continue; }
            $inherited = true;
        }
        $rowNo++;
        $out['rows'][] = ['row_no' => $rowNo] + $ctx + ['order_ref' => $ref, 'pricing_date' => sipdf_dmy($one('pricing_date')), 'quantity' => sipdf_int($one('quantity')),
                         'promo' => $one('promo') ?: null, 'price_inherited' => $inherited];
        // keep column order stable: move inherited flag last
    }
    if ($cols === null) sipdf_warn($w, 'error', 'table_header', 'Price table header labels not found.');
    if (!$out['rows']) sipdf_warn($w, 'error', 'no_rows', 'No price rows found.');
    // totals + checks
    $tot = [];
    foreach ($out['rows'] as $r) { $k = $r['order_ref']; $tot[$k]['quantity'] = ($tot[$k]['quantity'] ?? 0) + (int)$r['quantity']; $tot[$k]['pricing_date'] = $r['pricing_date']; }
    foreach ($tot as $ref => $v) $out['totals_by_order_ref'][] = ['order_ref' => $ref] + $v;
    $seen = [];
    foreach ($out['rows'] as $r) {
        if ($r['sold_to'] !== null && $out['sold_to_list'] && !in_array($r['sold_to'], $out['sold_to_list'], true)) sipdf_warn($w, 'warn', 'sold_to_not_in_destination', 'Sold-to is not listed in the "Destination:" header.', ['sold_to' => $r['sold_to']]);
        $dup = $r['order_ref'] . '|' . $r['ian'] . '|' . $r['description'] . '|' . $r['variety'];
        sipdf_check($checks, $w, 'unique_order_item', $r['order_ref'] . ' / ' . ($r['pcs_per_box'] ?? '?') . ' PC', !isset($seen[$dup]), 'unique', isset($seen[$dup]) ? 'duplicate' : 'unique', 'The same (order ref, item) occurs twice.');
        $seen[$dup] = true;
        if ($r['collo_content'] !== null && $r['pcs_per_box'] !== null) sipdf_check($checks, $w, 'collo_vs_description', $r['order_ref'] . ' / ' . $r['pcs_per_box'] . ' PC', (int)$r['collo_content'] === $r['pcs_per_box'], $r['pcs_per_box'], $r['collo_content'], 'Collo content must equal the PC count of the article description.');
        if ($r['pricing_date'] === null) sipdf_warn($w, 'warn', 'pricing_date_missing', 'Row without pricing date.', ['order_ref' => $r['order_ref']]);
    }
    $out['checks'] = $checks; $out['warnings'] = $w;
    return $out;
}

/* =====================================================================================================
 * PART 6 - LOADING LIST
 * ===================================================================================================== */
/**
 * siparis_pdf_parse_loading_list($pages, $meta=[]) -> array
 * {
 *  doc_type: 'loading_list',
 *  header: {agency, vendor, loading_date, loading_location, transport_no, transport_company, plates[], additional_info},
 *  rows: [ {product ("Pomegranate"), description (raw "Pomegranate 14 PC LOSE (TR)"), pcs_per_box, pack_mode, origin, ian, pallet_type, pallets, pf, colli, seq, unloading_location, unloading_date, order_ref, tour_id, temp, client, final_unloading_location} ],
 *  shipments: [ {order_ref, tour_id, pallets, colli, row_count, items:[{pcs_per_box, ian, pallets, colli, pf}]} ],   // grouped by (order_ref, tour_id)
 *  total_pallets, total_colli, checks, warnings, meta
 * }
 * plates[] is EMPTY when the carrier has not been announced yet (set 2) - the PDF has the label but no value; this is NOT an error.
 */
function siparis_pdf_parse_loading_list(array $pages, array $meta = []): array
{
    $w = []; $checks = [];
    $out = ['doc_type' => 'loading_list', 'header' => ['agency' => null, 'vendor' => null, 'loading_date' => null, 'loading_location' => null, 'transport_no' => null,
            'transport_company' => null, 'plates' => [], 'additional_info' => null], 'rows' => [], 'shipments' => [], 'total_pallets' => 0, 'total_colli' => 0, 'checks' => [], 'warnings' => [], 'meta' => $meta];
    $H = &$out['header'];
    $spec = ['product' => '/^Product$/', 'ian' => '/^IAN$/', 'pallet_type' => '/^PAL-Typ$/', 'pallets' => '/^Pal$/', 'pf' => '/^PF$/', 'colli' => '/^Colli$/', 'seq' => '/^SEQ$/',
             'unloading_location' => '/^Unloading location$/', 'unloading_date' => '/^Unloading Date$/', 'order_ref' => '/^Order reference$/', 'tour_id' => '/^Tour-ID$/',
             'temp' => '/^Temp\.?$/', 'client' => '/^Client$/', 'final_unloading_location' => '/^Final unloading location$/'];
    $cols = null; $skipHdr = false; $rows = []; $lines = sipdf_body_lines($pages); $n = count($lines);
    $valueBelow = function (int $i, string $labelRe) use ($lines) {              // label on line i, value = nearest segment on the next line
        foreach ($lines[$i]['segs'] as $s) if (preg_match($labelRe, sipdf_t($s))) {
            $nx = $lines[$i + 1] ?? null; if (!$nx) return null; $best = null; $bd = 60;
            foreach ($nx['segs'] as $v) { $d = abs(($v['x'] + sipdf_seg_w($v) / 2) - ($s['x'] + sipdf_seg_w($s) / 2)); if ($d < $bd) { $bd = $d; $best = sipdf_t($v); } }
            return $best;
        }
        return null;
    };
    for ($i = 0; $i < $n; $i++) {
        $ln = $lines[$i]; $t = sipdf_ltext($ln);
        if (preg_match('/Loading Date/', $t) && preg_match('/Loading Location/', $t)) {
            $H['loading_date'] = sipdf_dmy($valueBelow($i, '/^Loading Date$/')); $H['loading_location'] = $valueBelow($i, '/^Loading Location$/'); continue;
        }
        if (preg_match('/^Agency\b/', $t) && preg_match('/Vendor/', $t)) { $H['agency'] = $valueBelow($i, '/^Agency$/'); $H['vendor'] = $valueBelow($i, '/^Vendor$/'); continue; }
        if (sipdf_t($ln['segs'][0]) === 'Transport' && isset($ln['segs'][1]) && preg_match('/^\d{6,}$/', sipdf_t($ln['segs'][1]))) {
            $H['transport_no'] = sipdf_t($ln['segs'][1]);
            $lpIdx = null; foreach ($ln['segs'] as $k => $s) if (preg_match('/^Licence plate$/i', sipdf_t($s))) $lpIdx = $k;
            $addX = null; foreach ($lines[$i - 1]['segs'] ?? [] as $s) if (preg_match('/^Additional$/i', sipdf_t($s))) $addX = $s['x'];
            $comp = []; $plates = []; $add = [];
            foreach ($ln['segs'] as $k => $s) {
                if ($k < 2) continue; $x = sipdf_t($s);
                if ($lpIdx !== null && $k === $lpIdx) continue;
                if ($lpIdx === null || $k < $lpIdx) { $comp[] = $x; continue; }
                if ($addX !== null && $s['x'] >= $addX - 5) $add[] = $x; else $plates[] = $x;
            }
            $H['transport_company'] = $comp ? implode(' ', $comp) : null; $H['plates'] = $plates; $H['additional_info'] = $add ? implode(' ', $add) : null;
            continue;
        }
        $c = sipdf_cols_from_labels($ln['segs'], $spec);
        if ($c) { $cols = $c; $skipHdr = true; continue; }
        if ($cols === null) continue;
        $cells = sipdf_line_cells($ln, $cols, 'center');
        $ref = null; foreach ($cells['order_ref'] ?? [] as $x) if (sipdf_is_ref($x)) $ref = $x;
        $one = fn($k) => isset($cells[$k]) ? trim(implode(' ', $cells[$k])) : null;
        if ($ref !== null) {
            $rows[] = ['product' => $one('product'), 'ian' => $one('ian'), 'pallet_type' => $one('pallet_type'), 'pallets' => sipdf_int($one('pallets')), 'pf' => sipdf_int($one('pf')), 'colli' => sipdf_int($one('colli')),
                       'seq' => sipdf_int($one('seq')), 'unloading_location' => $one('unloading_location'), 'unloading_date' => sipdf_dmy($one('unloading_date')), 'order_ref' => $ref, 'tour_id' => $one('tour_id'),
                       'temp' => $one('temp'), 'client' => $one('client'), 'final_unloading_location' => $one('final_unloading_location')];
            $skipHdr = false;
        } elseif (!$skipHdr && $rows && $t !== '' && count($cells) <= 4) {                       // wrapped text of the previous row
            $last = count($rows) - 1;
            foreach ($cells as $k => $tx) if (isset($rows[$last][$k]) && is_string($rows[$last][$k])) $rows[$last][$k] .= ' ' . implode(' ', $tx);
        }
    }
    unset($H);
    foreach ($rows as &$r) { $a = sipdf_parse_article($r['product']); $r['description'] = $r['product']; $r['product'] = $a['product']; $r['pcs_per_box'] = $a['pcs_per_box']; $r['pack_mode'] = $a['pack_mode']; $r['origin'] = $a['origin']; } unset($r);
    $out['rows'] = $rows;
    foreach ($rows as $r) { $out['total_pallets'] += (int)$r['pallets']; $out['total_colli'] += (int)$r['colli']; }
    // shipments (order_ref + tour)
    $sh = [];
    foreach ($rows as $r) {
        $k = $r['order_ref'] . '|' . $r['tour_id'];
        $sh[$k] ??= ['order_ref' => $r['order_ref'], 'tour_id' => $r['tour_id'], 'pallets' => 0, 'colli' => 0, 'row_count' => 0, 'unloading_date' => $r['unloading_date'], 'items' => []];
        $sh[$k]['pallets'] += (int)$r['pallets']; $sh[$k]['colli'] += (int)$r['colli']; $sh[$k]['row_count']++;
        $sh[$k]['items'][] = ['pcs_per_box' => $r['pcs_per_box'], 'ian' => $r['ian'], 'pallets' => $r['pallets'], 'pf' => $r['pf'], 'colli' => $r['colli'], 'unloading_location' => $r['unloading_location'], 'seq' => $r['seq']];
    }
    $out['shipments'] = array_values($sh);
    // checks / warnings
    $H = $out['header'];
    if ($H['loading_date'] === null) sipdf_warn($w, 'error', 'loading_date_missing', 'Loading date not found.');
    if ($H['transport_no'] === null) sipdf_warn($w, 'warn', 'transport_missing', 'Transport (freight order) number not found.');
    if (!$H['plates']) sipdf_warn($w, 'info', 'no_plates', 'No licence plate on the loading list (not announced yet) - enter manually.');
    if (!$rows) sipdf_warn($w, 'error', 'no_rows', 'No data rows found.');
    foreach ($rows as $i => $r) {
        $sc = ($r['order_ref'] ?? '?') . ' row ' . ($i + 1);
        if ($r['pallets'] !== null && $r['pf'] !== null && $r['colli'] !== null) sipdf_check($checks, $w, 'row_pallets_x_pf', $sc, $r['pallets'] * $r['pf'] === $r['colli'], $r['pallets'] * $r['pf'], $r['colli'], 'pallets x PF must equal colli.');
        foreach (['ian', 'pallets', 'pf', 'colli', 'tour_id', 'unloading_date'] as $f) if ($r[$f] === null) sipdf_warn($w, 'warn', 'cell_missing', "Row $sc: '$f' empty.");
    }
    foreach ($out['shipments'] as $s) {
        $tours = array_unique(array_column(array_filter($rows, fn($r) => $r['order_ref'] === $s['order_ref']), 'tour_id'));
        sipdf_check($checks, $w, 'order_ref_single_tour', 'order ' . $s['order_ref'], count($tours) === 1, 1, count($tours), 'One order ref must map to exactly one tour id.');
    }
    $out['checks'] = $checks; $out['warnings'] = $w;
    return $out;
}

/* =====================================================================================================
 * PART 7 - DISPATCHER + CROSS-DOCUMENT CHECKS
 * ===================================================================================================== */
/** Read + classify + parse. Never throws: on failure returns ['doc_type'=>..., 'error'=>msg]. Adds _file. */
function siparis_pdf_isle(string $file): array
{
    try {
        $r = siparis_pdf_oku($file);
        $type = siparis_pdf_belge_turu($r['pages']);
        $doc = match ($type) {
            'purchase_order' => siparis_pdf_parse_order($r['pages'], $r['meta']),
            'price_confirmation' => siparis_pdf_parse_price_confirmation($r['pages'], $r['meta']),
            'loading_list' => siparis_pdf_parse_loading_list($r['pages'], $r['meta']),
            default => ['doc_type' => 'unknown', 'checks' => [], 'warnings' => [['level' => 'error', 'code' => 'unknown_doc', 'message' => 'Document type not recognised.']], 'meta' => $r['meta']],
        };
    } catch (Throwable $e) {
        $doc = ['doc_type' => 'error', 'error' => $e->getMessage(), 'checks' => [], 'warnings' => [['level' => 'error', 'code' => 'exception', 'message' => $e->getMessage()]]];
    }
    $doc['_file'] = basename($file);
    return $doc;
}

/**
 * Cross-document reconciliation by ORDER REF.  $docs = list of parsed docs (any mix / any order).
 * Returns ['shipments'=>[order_ref=>{...}], 'checks'=>[...], 'warnings'=>[...]]
 *  - per order ref: presence in po/ll/pc, tour (PO vs LL), loading date (PO vs LL), pallets & cartons per document, per-item (pcs_per_box) quantities per document,
 *    unloading date (LL) vs pricing date (PC), planned carrier (PO) vs actual carrier/plates (LL).
 * Rule: totals per order ref are expected to agree across PO / LL / PC; the per-item SPLIT may differ (PO/LL are plans, PC is the later confirmation)
 * -> reported as 'split_differs' (warn), never overwritten.
 */
function siparis_pdf_capraz_kontrol(array $docs): array
{
    $S = []; $w = []; $checks = [];
    foreach ($docs as $d) {
        switch ($d['doc_type'] ?? '') {
            case 'purchase_order':
                foreach ($d['loadings'] as $o) {
                    $s = &sipdf_ship($S, $o['order_ref']); $s['po'] = ['pallets' => $o['totals']['pallets'], 'quantity' => $o['totals']['quantity'], 'file' => $d['_file'] ?? null];
                    $s['tour']['po'] = $o['tour']; $s['loading_date']['po'] = $o['loading_date']; $s['carrier']['po_planned'] = $o['carrier_name'];
                    foreach ($o['items'] as $it) { $k = (string)$it['pcs_per_box']; $s['items'][$k]['po'] = ['pallets' => $it['pallets'], 'quantity' => $it['quantity'], 'variety' => (array_values(array_filter($d['items'], fn($x) => $x['pcs_per_box'] === $it['pcs_per_box']))[0]['variety'] ?? null)]; }
                    unset($s);
                }
                break;
            case 'loading_list':
                foreach ($d['shipments'] as $sh) {
                    $s = &sipdf_ship($S, $sh['order_ref']); $s['ll'] = ['pallets' => $sh['pallets'], 'quantity' => $sh['colli'], 'file' => $d['_file'] ?? null];
                    $s['tour']['ll'] = $sh['tour_id']; $s['loading_date']['ll'] = $d['header']['loading_date']; $s['unloading_date_ll'] = $sh['unloading_date'];
                    $s['carrier']['ll_actual'] = $d['header']['transport_company']; $s['carrier']['ll_plates'] = $d['header']['plates']; $s['transport_no'] = $d['header']['transport_no'];
                    foreach ($sh['items'] as $it) { $k = (string)$it['pcs_per_box']; $e = $s['items'][$k]['ll'] ?? ['pallets' => 0, 'quantity' => 0]; $e['pallets'] += (int)$it['pallets']; $e['quantity'] += (int)$it['colli']; $s['items'][$k]['ll'] = $e; }
                    unset($s);
                }
                break;
            case 'price_confirmation':
                foreach ($d['rows'] as $r) {
                    $s = &sipdf_ship($S, $r['order_ref']); $s['pricing_date_pc'] = $r['pricing_date'];
                    $s['pc'] = ['quantity' => (($s['pc']['quantity'] ?? 0) + (int)$r['quantity']), 'sold_to' => $r['sold_to'], 'file' => $d['_file'] ?? null];
                    $k = (string)$r['pcs_per_box']; $s['items'][$k]['pc'] = ['quantity' => (int)$r['quantity'], 'price' => $r['price'], 'currency' => $r['currency'], 'price_unit' => $r['price_unit'], 'variety' => $r['variety']];
                    unset($s);
                }
                break;
        }
    }
    foreach ($S as $ref => &$s) {
        $present = array_keys(array_filter(['po' => $s['po'], 'll' => $s['ll'], 'pc' => $s['pc']]));
        $s['present_in'] = $present;
        $same = function (string $id, string $label, $a, $b) use (&$checks, &$w, $ref) { if ($a === null || $b === null) return; sipdf_check($checks, $w, $id, "order $ref", $a === $b, $a, $b, $label); };
        $same('tour_po_vs_ll', 'Tour id differs between PO and loading list.', $s['tour']['po'], $s['tour']['ll']);
        $same('loading_date_po_vs_ll', 'Loading date differs between PO and loading list.', $s['loading_date']['po'], $s['loading_date']['ll']);
        if ($s['po'] && $s['ll']) {
            $same('pallets_po_vs_ll', 'Pallet total differs between PO and loading list.', $s['po']['pallets'], $s['ll']['pallets']);
            $same('quantity_po_vs_ll', 'Carton total differs between PO and loading list.', $s['po']['quantity'], $s['ll']['quantity']);
        }
        if ($s['po'] && $s['pc']) $same('quantity_po_vs_pc', 'Carton total differs between PO and price confirmation.', $s['po']['quantity'], $s['pc']['quantity']);
        if ($s['ll'] && $s['pc']) $same('quantity_ll_vs_pc', 'Carton total differs between loading list and price confirmation.', $s['ll']['quantity'], $s['pc']['quantity']);
        if ($s['unloading_date_ll'] !== null && $s['pricing_date_pc'] !== null) $same('unloading_vs_pricing_date', 'LL unloading date differs from PC pricing date.', $s['unloading_date_ll'], $s['pricing_date_pc']);
        // per item split: compare every pair of documents that carry that item
        foreach ($s['items'] as $k => $e) {
            $vals = []; foreach (['po', 'll', 'pc'] as $dk) if (isset($e[$dk])) $vals[$dk] = $e[$dk]['quantity'];
            $inDocs = array_keys($vals);
            $missing = array_diff($present, $inDocs);
            if (count(array_unique($vals)) > 1 || ($missing && count($present) > 1)) {
                $s['split_differs'] = true;
                sipdf_warn($w, 'warn', 'split_differs', "order $ref, $k PC: quantity per document " . json_encode($vals) . ($missing ? ' (item absent in ' . implode(',', $missing) . ')' : ''), ['order_ref' => $ref, 'pcs_per_box' => $k]);
            }
        }
    }
    unset($s);
    return ['shipments' => $S, 'checks' => $checks, 'warnings' => $w];
}

/** Reference to the (created-on-demand) per-order record used by siparis_pdf_capraz_kontrol(). */
function &sipdf_ship(array &$S, string $ref)
{
    if (!isset($S[$ref])) $S[$ref] = ['order_ref' => $ref, 'po' => null, 'll' => null, 'pc' => null, 'tour' => ['po' => null, 'll' => null], 'loading_date' => ['po' => null, 'll' => null],
        'items' => [], 'carrier' => ['po_planned' => null, 'll_actual' => null, 'll_plates' => []], 'transport_no' => null, 'unloading_date_ll' => null, 'pricing_date_pc' => null];
    return $S[$ref];
}
