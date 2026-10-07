<?php
// =========================================================
// config/mail_mime.php — MIME ayrıştırıcı + HTML temizleyici (M2)
//
// Güvenilmeyen (dış dünyadan gelen) mail ham baytlarını UTF-8 alanlara çevirir:
// RFC 2047 başlıklar, RFC 2231 parametreler, base64/quoted-printable, charset →
// UTF-8, multipart ağacı (IMAP parça numaralarıyla uyumlu), ek metadata'sı.
//
// HTML GÜVENLİĞİ (mail_html_sanitize): DOM üzerinden İZİN LİSTESİ ile YENİDEN ÜRETİM —
// kaynak HTML'in kendisi asla çıktıya kopyalanmaz; yalnız izinli etiket + izinli
// nitelik + doğrulanmış URL/CSS yeniden serileştirilir. script/style/iframe/object/
// svg/math/form… içerikleriyle birlikte düşer; on* nitelikleri yoktur; uzak görsel
// varsayılan ENGELLİ (data-blocked-src). Render ayrıca sandbox'lı iframe + CSP ile yapılır
// (mail_html_iframe_srcdoc).
// =========================================================
declare(strict_types=1);

const MAIL_MIME_MAX_DERINLIK = 10;
const MAIL_MIME_MAX_PARCA    = 200;
const MAIL_METIN_MAX         = 1048576;   // 1 MB gövde metni
const MAIL_HTML_MAX          = 2097152;   // 2 MB temiz HTML

// ── Karakter kümesi ─────────────────────────────────────────────────────

function mail_mime_utf8(string $s, string $charset = 'utf-8'): string
{
    $cs = strtolower(trim($charset, " \t\"'"));
    $alias = ['utf8' => 'utf-8', 'us-ascii' => 'utf-8', 'ascii' => 'utf-8', 'ansi_x3.4-1968' => 'utf-8', '' => 'utf-8',
        'gb2312' => 'gb18030', 'gbk' => 'gb18030', 'ks_c_5601-1987' => 'uhc', 'x-sjis' => 'sjis', 'shift_jis' => 'sjis',
        'iso-8859-8-i' => 'iso-8859-8', 'windows-874' => 'cp874', 'x-mac-roman' => 'macroman', 'unicode-1-1-utf-7' => 'utf-7'];
    $cs = $alias[$cs] ?? $cs;
    if ($cs === 'utf-7') return mb_scrub($s, 'UTF-8');   // UTF-7 XSS vektörü: çevirme, olduğu gibi (zararsız) metin say
    if ($cs === 'utf-8') {
        if (mb_check_encoding($s, 'UTF-8')) return $s;
        return mb_convert_encoding($s, 'UTF-8', 'Windows-1254');   // geçersiz UTF-8: Türkçe tabanlı yedek
    }
    $mb = array_map('strtolower', mb_list_encodings());
    if (in_array($cs, $mb, true)) {
        $r = @mb_convert_encoding($s, 'UTF-8', $cs);
        if (is_string($r)) return mb_scrub($r, 'UTF-8');
    }
    $r = @iconv($cs, 'UTF-8//IGNORE', $s);
    if ($r !== false) return mb_scrub($r, 'UTF-8');
    return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1254');
}

/** Kontrol karakterlerini (sekme/satır sonu hariç) temizler. */
function mail_mime_temiz(string $s): string
{
    return (string)preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $s);
}

// ── Başlıklar ───────────────────────────────────────────────────────────

/** RFC 2047 encoded-word çözer; bitişik kelimeler arası boşluk atılır. */
function mail_mime_baslik_coz(string $v): string
{
    $v = (string)preg_replace('/\r?\n[ \t]+/', ' ', $v);
    if (!str_contains($v, '=?')) {
        return mail_mime_temiz(mail_mime_utf8($v, 'utf-8'));
    }
    $v = (string)preg_replace('/(\?=)\s+(?==\?)/', '$1', $v);   // bitişik encoded-word'ler
    $out = (string)preg_replace_callback('/=\?([^?\s]+)\?([BbQq])\?([^?]*)\?=/', static function ($m) {
        $cs = preg_replace('/\*.*$/', '', $m[1]);   // RFC 2231 dil eki
        if (strtolower($m[2]) === 'b') {
            $ham = base64_decode($m[3], true);
            if ($ham === false) $ham = (string)base64_decode($m[3]);
        } else {
            $ham = quoted_printable_decode(str_replace('_', ' ', $m[3]));
        }
        return mail_mime_utf8($ham, (string)$cs);
    }, $v);
    return mail_mime_temiz($out);
}

/**
 * Ham başlık bloğunu ayrıştırır: [küçükharfAd => [değer, …]] (katlama açılmış, DEĞERLER ham).
 */
function mail_mime_basliklar(string $blok): array
{
    $blok = (string)preg_replace('/\r?\n[ \t]+/', ' ', $blok);
    $h = [];
    foreach (preg_split('/\r?\n/', $blok) ?: [] as $satir) {
        if ($satir === '' || !str_contains($satir, ':')) continue;
        [$ad, $deger] = explode(':', $satir, 2);
        $ad = strtolower(trim($ad));
        if ($ad === '' || preg_match('/[^a-z0-9!#$%&\'*+.^_`|~-]/', $ad)) continue;
        $h[$ad][] = trim($deger);
        if (count($h[$ad]) > 50) array_pop($h[$ad]);
    }
    return $h;
}

/** "text/plain; charset=utf-8; name*0*=…" → ['deger' => 'text/plain', 'params' => [...]] (RFC 2231 dahil). */
function mail_mime_param_coz(string $v): array
{
    $parcalar = []; $buf = ''; $tirnak = false;
    for ($i = 0, $n = strlen($v); $i < $n; $i++) {
        $c = $v[$i];
        if ($c === '"' && ($i === 0 || $v[$i - 1] !== '\\')) $tirnak = !$tirnak;
        if ($c === ';' && !$tirnak) { $parcalar[] = $buf; $buf = ''; continue; }
        $buf .= $c;
    }
    $parcalar[] = $buf;
    $deger = strtolower(trim((string)array_shift($parcalar)));
    $ham = [];   // temel ad => [sira => [kodlu?, metin]]
    foreach ($parcalar as $p) {
        if (!str_contains($p, '=')) continue;
        [$ad, $d] = explode('=', $p, 2);
        $ad = strtolower(trim($ad)); $d = trim($d);
        if (strlen($d) >= 2 && $d[0] === '"' && str_ends_with($d, '"')) $d = stripcslashes(substr($d, 1, -1));
        if (preg_match('/^([^*]+)\*(\d+)(\*)?$/', $ad, $m)) { $ham[$m[1]][(int)$m[2]] = [$m[3] ?? '' ? true : false, $d]; }
        elseif (preg_match('/^([^*]+)\*$/', $ad, $m)) { $ham[$m[1]][0] = [true, $d]; }
        else { $ham[$ad][0] = [false, $d]; }
    }
    $params = [];
    foreach ($ham as $ad => $segs) {
        ksort($segs);
        $cs = 'utf-8'; $metin = ''; $ilk = true;
        foreach ($segs as [$kodlu, $d]) {
            if ($kodlu) {
                if ($ilk && preg_match("/^([^']*)'[^']*'(.*)$/s", $d, $m)) { $cs = $m[1] !== '' ? $m[1] : 'utf-8'; $d = $m[2]; }
                $metin .= rawurldecode($d);
            } else {
                $metin .= $d;
            }
            $ilk = false;
        }
        $kodluVar = false;
        foreach ($segs as [$kodlu]) if ($kodlu) $kodluVar = true;
        $params[$ad] = mail_mime_temiz($kodluVar ? mail_mime_utf8($metin, $cs) : mail_mime_baslik_coz($metin));
    }
    return ['deger' => $deger, 'params' => $params];
}

/** Adres listesi → [['name'=>…, 'email'=>…], …]; geçersiz adresler atılır. */
function mail_mime_adresler(string $v): array
{
    $v = mail_mime_baslik_coz_adres($v);
    $liste = []; $buf = ''; $tirnak = false; $aci = 0; $yorum = 0;
    $bol = function () use (&$buf, &$liste) {
        $t = trim($buf); $buf = '';
        if ($t === '') return;
        $liste[] = $t;
    };
    for ($i = 0, $n = strlen($v); $i < $n; $i++) {
        $c = $v[$i];
        if ($c === '"' && ($i === 0 || $v[$i - 1] !== '\\') && !$yorum) $tirnak = !$tirnak;
        elseif (!$tirnak) {
            if ($c === '(') $yorum++;
            elseif ($c === ')' && $yorum) $yorum--;
            elseif ($c === '<' && !$yorum) $aci++;
            elseif ($c === '>' && !$yorum && $aci) $aci--;
            elseif (($c === ',' || $c === ';') && !$aci && !$yorum) { $bol(); continue; }
        }
        $buf .= $c;
    }
    $bol();
    $out = []; $goruldu = [];
    foreach ($liste as $t) {
        if (str_contains($t, ':') && !str_contains($t, '<') && !str_contains($t, '@')) continue;   // "Grup:" başı
        $t = preg_replace('/^[^<"@]*:\s*(?=.*@)/', '', $t) ?? $t;                                // "Grup: a@b"
        $isim = ''; $email = '';
        if (preg_match('/^(.*)<([^<>]*)>\s*$/s', $t, $m)) {
            $email = trim($m[2]); $isim = trim($m[1]);
        } elseif (preg_match('/^([^\s()<>"]+@[^\s()<>"]+)\s*(?:\((.*)\))?\s*$/s', $t, $m)) {
            $email = $m[1]; $isim = trim($m[2] ?? '');
        } else continue;
        $isim = trim($isim, " \t\"");
        $isim = stripcslashes($isim);
        $email = strtolower(preg_replace('/\s+/', '', $email) ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || isset($goruldu[$email])) continue;
        $goruldu[$email] = true;
        $out[] = ['name' => mb_substr(mail_mime_temiz($isim), 0, 150), 'email' => mb_substr($email, 0, 190)];
        if (count($out) >= 100) break;
    }
    return $out;
}

/** Adres başlığında encoded-word'leri çözerken ayraçları ("<", ",") bozmamak için yalnız kelimeleri çözer. */
function mail_mime_baslik_coz_adres(string $v): string
{
    return mail_mime_baslik_coz($v);
}

/** <id> belirteçlerini döndürür (normalize: küçük harf, <> yok). @return list<string> */
function mail_mime_idler(string $v): array
{
    preg_match_all('/<([^<>\s]{1,500})>/', $v, $m);
    return array_values(array_unique(array_map('strtolower', $m[1])));
}

function mail_mime_gonder_adi(?string $ad): string
{
    $ad = mail_mime_temiz((string)$ad);
    $ad = str_replace(['/', '\\', "\0"], '_', $ad);
    $ad = trim((string)preg_replace('/\s+/u', ' ', $ad));
    return mb_substr($ad, 0, 180);
}

// ── Gövde çözme ─────────────────────────────────────────────────────────

function mail_mime_govde_coz(string $govde, string $cte): string
{
    switch (strtolower(trim($cte))) {
        case 'base64':
            $d = base64_decode((string)preg_replace('/[^A-Za-z0-9+\/=]/', '', $govde), false);
            return $d === false ? '' : $d;
        case 'quoted-printable':
            return quoted_printable_decode($govde);
        default:
            return $govde;
    }
}

// ── MIME ağacı ──────────────────────────────────────────────────────────

/**
 * @param array{parca:int} $durum paylaşılan sayaç (parça sınırı)
 * @return array{ctype:string,params:array,cte:string,disp:string,dparams:array,headers:array,govde:string,cocuklar:list<array>,parca:string}
 */
function mail_mime_ayristir(string $ham, int $derinlik = 0, string $parcaNo = '1', array &$durum = ['parca' => 0]): array
{
    $durum['parca']++;
    $p = preg_match('/\r?\n\r?\n/', $ham, $mm, PREG_OFFSET_CAPTURE) ? $mm[0][1] : strlen($ham);
    $blok = substr($ham, 0, $p);
    $govde = $p < strlen($ham) ? substr($ham, $p + strlen($mm[0][0])) : '';
    $h = mail_mime_basliklar($blok);
    $ct = mail_mime_param_coz($h['content-type'][0] ?? 'text/plain; charset=us-ascii');
    if (!preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $ct['deger'])) $ct['deger'] = 'text/plain';
    $cd = mail_mime_param_coz($h['content-disposition'][0] ?? '');
    $dugum = ['ctype' => $ct['deger'], 'params' => $ct['params'], 'cte' => strtolower(trim($h['content-transfer-encoding'][0] ?? '7bit')),
        'disp' => $cd['deger'], 'dparams' => $cd['params'], 'headers' => $h, 'govde' => '', 'cocuklar' => [], 'parca' => $parcaNo];

    if (str_starts_with($ct['deger'], 'multipart/') && $derinlik < MAIL_MIME_MAX_DERINLIK && !empty($ct['params']['boundary'])) {
        $b = preg_quote($ct['params']['boundary'], '/');
        $parcalar = preg_split('/\r?\n--' . $b . '(?:--)?[ \t]*(?=\r?\n|$)/', "\n" . $govde) ?: [];
        array_shift($parcalar);   // önsöz
        $i = 0;
        foreach ($parcalar as $pc) {
            if ($durum['parca'] >= MAIL_MIME_MAX_PARCA) break;
            $pc = ltrim($pc, "\r\n");
            if (trim($pc) === '' ) continue;
            $i++;
            $dugum['cocuklar'][] = mail_mime_ayristir($pc, $derinlik + 1, $parcaNo === '1' && $derinlik === 0 ? (string)$i : $parcaNo . '.' . $i, $durum);
        }
        // Üst düzey multipart'ta çocuk numaraları 1,2,3…; iç içe olanlarda "2.1". Kök için parcaNo '1' yerine
        // çocuklar doğrudan 1..n alır (yukarıdaki koşul).
    } else {
        $dugum['govde'] = $govde;
    }
    return $dugum;
}

/** Ağaçtaki yaprakları düz listeye çevirir. */
function mail_mime_yapraklar(array $d): array
{
    if ($d['cocuklar']) {
        $o = [];
        foreach ($d['cocuklar'] as $c) foreach (mail_mime_yapraklar($c) as $y) $o[] = $y;
        return $o;
    }
    return [$d];
}

/** Yaprağın çözülmüş + UTF-8'e çevrilmiş metni. */
function mail_mime_yaprak_metin(array $y): string
{
    $ham = mail_mime_govde_coz($y['govde'], $y['cte']);
    return mail_mime_utf8($ham, $y['params']['charset'] ?? 'utf-8');
}

// ── Tam mesaj ───────────────────────────────────────────────────────────

/**
 * Ham mesajı senkron motorunun beklediği alanlara çevirir. Hata atmaz (bozuk mesaj → en az başlıklar).
 * @return array<string,mixed>
 */
function mail_mime_mesaj(string $ham, bool $kesik = false): array
{
    $durum = ['parca' => 0];
    $kok = mail_mime_ayristir($ham, 0, '1', $durum);
    $h = $kok['headers'];
    $ilk = static fn(string $ad): string => (string)($h[$ad][0] ?? '');

    $mid = mail_mime_idler($ilk('message-id'))[0] ?? null;
    $from = mail_mime_adresler($ilk('from'))[0] ?? ['name' => '', 'email' => ''];
    $replyTo = mail_mime_adresler($ilk('reply-to'))[0]['email'] ?? '';
    $to = mail_mime_adresler(implode(', ', $h['to'] ?? []));
    $cc = mail_mime_adresler(implode(', ', $h['cc'] ?? []));
    $konu = trim((string)preg_replace('/\s+/u', ' ', mail_mime_baslik_coz($ilk('subject'))));
    $ts = $ilk('date') !== '' ? strtotime(mail_mime_temiz($ilk('date'))) : false;
    $refs = mail_mime_idler(implode(' ', $h['references'] ?? []));
    $inReply = mail_mime_idler($ilk('in-reply-to'))[0] ?? null;

    $metinler = []; $htmller = []; $ekler = [];
    foreach (mail_mime_yapraklar($kok) as $y) {
        $ct = $y['ctype'];
        $ad = $y['dparams']['filename'] ?? ($y['params']['name'] ?? '');
        $ek = $y['disp'] === 'attachment' || $ad !== '';   // adı olan her parça ek sayılır
        if (!$ek && $ct === 'text/plain') { $metinler[] = mail_mime_yaprak_metin($y); continue; }
        if (!$ek && $ct === 'text/html')  { $htmller[] = mail_mime_yaprak_metin($y); continue; }
        if (!$ek && str_starts_with($ct, 'text/') && $ct !== 'text/calendar') { $metinler[] = mail_mime_yaprak_metin($y); continue; }
        $cid = isset($y['headers']['content-id'][0]) ? (mail_mime_idler($y['headers']['content-id'][0])[0] ?? null) : null;
        if ($y['cte'] === 'base64') {
            $g = (string)preg_replace('/\s+/', '', $y['govde']);
            $boyut = max(0, intdiv(strlen($g) * 3, 4) - substr_count(substr($g, -2), '='));
        } else {
            $boyut = strlen($y['govde']);
        }
        $ekler[] = [
            'part'     => $y['parca'],
            'filename' => mail_mime_gonder_adi($ad !== '' ? $ad : ('ek-' . $y['parca'])),
            'mime'     => $ct,
            'size'     => $boyut,
            'inline'   => $y['disp'] === 'inline' || ($cid !== null && $y['disp'] !== 'attachment'),
            'cid'      => $cid,
        ];
        if (count($ekler) >= 100) break;
    }
    $metin = trim(implode("\n\n", $metinler));
    $htmlHam = trim(implode("\n", $htmller));
    $guvenli = $htmlHam !== '' ? mail_html_sanitize($htmlHam) : '';
    if ($metin === '' && $htmlHam !== '') $metin = mail_html_to_text($htmlHam);
    $kesildi = $kesik;
    if (strlen($metin) > MAIL_METIN_MAX) { $metin = mb_strcut($metin, 0, MAIL_METIN_MAX, 'UTF-8'); $kesildi = true; }
    if (strlen($guvenli) > MAIL_HTML_MAX) { $guvenli = ''; $kesildi = true; }   // yarım HTML GÖSTERME (etiket dengesi bozulur)

    $hamBaslik = substr($ham, 0, (int)(preg_match('/\r?\n\r?\n/', $ham, $mm, PREG_OFFSET_CAPTURE) ? $mm[0][1] : min(strlen($ham), 8192)));
    $hash = sha1($mid !== null ? 'id:' . $mid : 'noid:' . $hamBaslik);

    return [
        'message_id'      => $mid,
        'message_id_hash' => $hash,
        'in_reply_to'     => $inReply,
        'references'      => array_slice($refs, -30),
        'from_addr'       => $from['email'],
        'from_name'       => $from['name'],
        'reply_to_addr'   => $replyTo,
        'to'              => $to,
        'cc'              => $cc,
        'subject'         => mb_substr($konu, 0, 500),
        'date'            => $ts !== false && $ts > 0 ? date('Y-m-d H:i:s', $ts) : null,
        'body_text'       => mail_mime_temiz($metin),
        'body_html_safe'  => $guvenli,
        'body_truncated'  => $kesildi ? 1 : 0,
        'attachments'     => $ekler,
        'size'            => strlen($ham),
    ];
}

// ── HTML temizleyici ────────────────────────────────────────────────────

/** İçeriğiyle BİRLİKTE tamamen düşen etiketler. */
function mail_html_dusenler(): array
{
    return ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select',
        'option', 'textarea', 'svg', 'math', 'head', 'title', 'meta', 'link', 'base', 'noscript', 'template', 'audio', 'video',
        'source', 'track', 'canvas', 'map', 'area', 'dialog', 'xmp', 'listing', 'plaintext', 'noembed', 'noframes', 'param',
        'bgsound', 'marquee', 'portal', 'slot', 'details', 'summary', 'datalist', 'fieldset', 'legend', 'label', 'output',
        'picture', 'image', 'isindex', 'keygen', 'menu', 'menuitem', 'rp', 'rt', 'ruby'];
}

function mail_html_izinli(): array
{
    return ['a', 'abbr', 'b', 'blockquote', 'br', 'caption', 'center', 'cite', 'code', 'col', 'colgroup', 'dd', 'div', 'dl', 'dt',
        'em', 'font', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'img', 'li', 'ol', 'p', 'pre', 'q', 's', 'small', 'span',
        'strike', 'strong', 'sub', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'u', 'ul', 'big', 'tt', 'address'];
}

/** URL'yi doğrular. Dönüş: normalize edilmiş URL ya da null (reddedildi). */
function mail_html_url(string $u, array $semalar, bool $parcaIzin = false): ?string
{
    $u = (string)preg_replace('/[\x00-\x20\x7f-\x9f\x{200b}-\x{200f}\x{2028}\x{2029}\x{feff}]+/u', '', $u);
    if ($u === '' || strlen($u) > 2000) return null;
    if ($parcaIzin && $u[0] === '#') return $u;
    if (!preg_match('/^([a-z][a-z0-9+.\-]*):/i', $u, $m)) return null;   // göreli / protokol-göreli ("//x") reddedilir
    $sema = strtolower($m[1]);
    if (!in_array($sema, $semalar, true)) return null;
    if (($sema === 'http' || $sema === 'https') && !preg_match('~^https?://[^/?#\s]+~i', $u)) return null;
    return $u;
}

function mail_html_css(string $css): string
{
    $izinli = ['color', 'background-color', 'background', 'font-size', 'font-family', 'font-weight', 'font-style', 'text-align',
        'text-decoration', 'line-height', 'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right', 'padding',
        'padding-top', 'padding-bottom', 'padding-left', 'padding-right', 'border', 'border-top', 'border-bottom', 'border-left',
        'border-right', 'border-color', 'border-style', 'border-width', 'border-collapse', 'width', 'height', 'max-width',
        'vertical-align', 'white-space', 'text-indent', 'list-style-type'];
    if (strlen($css) > 2000 || str_contains($css, '\\') || str_contains($css, '/*') || str_contains($css, '&')) return '';
    $out = [];
    foreach (explode(';', $css) as $d) {
        if (!str_contains($d, ':')) continue;
        [$p, $v] = explode(':', $d, 2);
        $p = strtolower(trim($p)); $v = trim($v);
        if (!in_array($p, $izinli, true) || $v === '') continue;
        $vl = strtolower($v);
        if (preg_match('/url\s*\(|expression|javascript|behavior|@import|binding|-moz-|-webkit-|var\s*\(|attr\s*\(|image-set|calc\s*\(/i', $vl)) continue;
        if (str_contains($v, '(') && !preg_match('/^(?:[a-z0-9#%.\s,-]*\s)?(?:rgb|rgba|hsl|hsla)\([0-9.,%\s\/]+\)(?:\s[a-z0-9#%.\s,-]*)?$/i', $v)) continue;
        if (!preg_match('/^[a-zA-Z0-9#%.,\s()\'"\/-]+$/u', $v)) continue;
        $out[] = $p . ':' . $v;
    }
    return implode(';', $out);
}

function mail_html_renk(string $v): ?string
{
    $v = trim($v);
    return preg_match('/^(#[0-9a-f]{3,8}|[a-z]{3,20})$/i', $v) ? $v : null;
}

/**
 * Güvenilmeyen HTML → güvenli HTML parçası. Uzak görseller ENGELLİ: src yerine data-blocked-src.
 * Her çağrıda sıfırdan ve izin listesiyle üretildiği için sanitize(sanitize(x)) === sanitize(x).
 */
function mail_html_sanitize(string $html): string
{
    if (trim($html) === '') return '';
    $html = mail_mime_temiz(mb_scrub($html, 'UTF-8'));
    if (strlen($html) > 4 * MAIL_HTML_MAX) $html = mb_strcut($html, 0, 4 * MAIL_HTML_MAX, 'UTF-8');
    $doc = new DOMDocument();
    $onceki = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"?><!DOCTYPE html><html><body>' . $html . '</body></html>',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($onceki);
    $body = $doc->getElementsByTagName('body')->item(0);
    if (!$body) return '';
    $dusen = array_flip(mail_html_dusenler());
    $izinli = array_flip(mail_html_izinli());
    $sayac = 0;

    $yaz = function (DOMNode $n, int $d) use (&$yaz, $dusen, $izinli, &$sayac): string {
        if ($d > 60 || ++$sayac > 20000) return '';
        $o = '';
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMText) {
                $o .= htmlspecialchars((string)$c->nodeValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }
            if (!($c instanceof DOMElement)) continue;   // yorum / CDATA / PI düşer
            $tag = strtolower($c->localName ?? $c->nodeName);
            if (isset($dusen[$tag])) continue;
            if (!isset($izinli[$tag])) { $o .= $yaz($c, $d + 1); continue; }   // bilinmeyen: sar-aç (içerik korunur)
            $a = [];
            $st = mail_html_css((string)$c->getAttribute('style'));
            if ($st !== '') $a['style'] = $st;
            foreach (['dir' => '/^(ltr|rtl)$/i', 'align' => '/^(left|right|center|justify)$/i', 'valign' => '/^(top|middle|bottom|baseline)$/i'] as $k => $re) {
                if ($c->hasAttribute($k) && preg_match($re, trim($c->getAttribute($k)))) $a[$k] = strtolower(trim($c->getAttribute($k)));
            }
            foreach (['width', 'height', 'border', 'cellpadding', 'cellspacing', 'colspan', 'rowspan', 'size'] as $k) {
                if ($c->hasAttribute($k) && preg_match('/^\d{1,4}%?$/', trim($c->getAttribute($k)))) $a[$k] = trim($c->getAttribute($k));
            }
            foreach (['color', 'bgcolor'] as $k) {
                if ($c->hasAttribute($k) && ($rk = mail_html_renk($c->getAttribute($k))) !== null) $a[$k] = $rk;
            }
            if ($c->hasAttribute('title')) $a['title'] = mb_substr((string)$c->getAttribute('title'), 0, 300);
            if ($tag === 'a') {
                $u = mail_html_url((string)$c->getAttribute('href'), ['http', 'https', 'mailto', 'tel'], true);
                if ($u !== null) $a['href'] = $u;
                $a['rel'] = 'noopener noreferrer nofollow'; $a['target'] = '_blank';
            }
            if ($tag === 'img') {
                $src = (string)$c->getAttribute('src');
                $norm = (string)preg_replace('/[\x00-\x20\x7f-\x9f]+/', '', $src);
                if (strlen($norm) < 400000 && preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=]+$#i', $norm)) {
                    $a['src'] = $norm;
                } elseif (stripos($norm, 'cid:') === 0 && strlen($norm) < 300) {
                    $a['data-cid'] = strtolower(substr($norm, 4));
                } elseif (($u = mail_html_url($src, ['http', 'https'])) !== null) {
                    $a['data-blocked-src'] = $u;       // uzak görsel: varsayılan ENGELLİ (izleme pikseli)
                } elseif ($src === '' && ($u = mail_html_url((string)$c->getAttribute('data-blocked-src'), ['http', 'https'])) !== null) {
                    $a['data-blocked-src'] = $u;       // kendi çıktımızı yeniden temizlerken kararlılık
                } elseif ($src === '' && preg_match('/^[a-z0-9._%+@-]{1,250}$/i', (string)$c->getAttribute('data-cid'))) {
                    $a['data-cid'] = strtolower((string)$c->getAttribute('data-cid'));
                }
                if ($c->hasAttribute('alt')) $a['alt'] = mb_substr((string)$c->getAttribute('alt'), 0, 200);
            }
            $at = '';
            foreach ($a as $k => $v) $at .= ' ' . $k . '="' . htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            if (in_array($tag, ['br', 'hr', 'img', 'col'], true)) { $o .= '<' . $tag . $at . '>'; continue; }
            $o .= '<' . $tag . $at . '>' . $yaz($c, $d + 1) . '</' . $tag . '>';
        }
        return $o;
    };
    return $yaz($body, 0);
}

/** HTML → düz metin (gövdede yalnız HTML varsa; arama/çeviri/özet için). */
function mail_html_to_text(string $html): string
{
    if (trim($html) === '') return '';
    $doc = new DOMDocument();
    $onceki = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"?><html><body>' . mb_scrub($html, 'UTF-8') . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors(); libxml_use_internal_errors($onceki);
    $body = $doc->getElementsByTagName('body')->item(0);
    if (!$body) return '';
    $dusen = array_flip(['script', 'style', 'head', 'title', 'noscript', 'template']);
    $blok = array_flip(['p', 'div', 'br', 'tr', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'table', 'hr', 'ul', 'ol']);
    $sayac = 0;
    $yaz = function (DOMNode $n, int $d) use (&$yaz, $dusen, $blok, &$sayac): string {
        if ($d > 60 || ++$sayac > 20000) return '';
        $o = '';
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMText) { $o .= preg_replace('/[ \t\r\n]+/', ' ', (string)$c->nodeValue); continue; }
            if (!($c instanceof DOMElement)) continue;
            $t = strtolower($c->localName ?? '');
            if (isset($dusen[$t])) continue;
            $in = $yaz($c, $d + 1);
            $o .= isset($blok[$t]) ? "\n" . $in . "\n" : ($t === 'td' || $t === 'th' ? $in . "\t" : $in);
        }
        return $o;
    };
    $t = $yaz($body, 0);
    $t = (string)preg_replace('/[ \t]+\n/', "\n", $t);
    $t = (string)preg_replace('/\n{3,}/', "\n\n", $t);
    return trim(mail_mime_temiz(html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/**
 * Sandbox'lı iframe için srcdoc gövdesi (HAM belge — çağıran htmlspecialchars ile niteliğe koyar).
 * CSP: script/çerçeve/form/bağlantı YOK; görsel yalnız data: (uzak görseller kullanıcı açarsa https:).
 */
function mail_html_iframe_srcdoc(string $guvenliHtml, bool $uzakGorsel = false): string
{
    if ($uzakGorsel) {
        $guvenliHtml = (string)preg_replace('/ data-blocked-src="/', ' src="', $guvenliHtml);
    }
    $csp = "default-src 'none'; img-src data:" . ($uzakGorsel ? ' https:' : '') . "; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'";
    return '<!doctype html><html><head><meta charset="utf-8">'
        . '<meta http-equiv="Content-Security-Policy" content="' . htmlspecialchars($csp, ENT_QUOTES) . '">'
        . '<base target="_blank"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<style>body{margin:0;padding:8px;font:14px/1.45 system-ui,sans-serif;color:#1b2430;overflow-wrap:anywhere}'
        . 'img{max-width:100%;height:auto}table{max-width:100%}blockquote{margin:6px 0 6px 6px;padding-left:10px;border-left:3px solid #c9d2de;color:#4a5668}'
        . 'img[data-blocked-src]{display:none}</style></head><body>' . $guvenliHtml . '</body></html>';
}
