<?php
// =========================================================
// scripts/mail_mime_smoke.php — MIME ayrıştırıcı + HTML temizleyici (M2)
// Bölüm B = XSS KORPUSU: her vektör temizlendikten sonra çıktı DOM olarak yeniden
// ayrıştırılır ve BAĞIMSIZ doğrulayıcıyla (yalnız izinli etiket/nitelik/URL) denetlenir.
//   php scripts/mail_mime_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
require_once __DIR__ . '/_mail_test_lib.php';
require_once $ROOT . '/config/mail_imap.php';
require_once $ROOT . '/config/mail_mime.php';
require_once __DIR__ . '/_mail_fake_imap.php';   // mail_test_raw()

echo "=== A1. Başlıklar ===\n";
ok('RFC2047 B (UTF-8)', mail_mime_baslik_coz('=?UTF-8?B?w4dhbMSxxZ9rYW4gTcO8xZ90ZXJp?=') === 'Çalışkan Müşteri');
ok('RFC2047 Q (iso-8859-9)', mail_mime_baslik_coz('=?iso-8859-9?Q?G=F6nderilmi=FE?=') === 'Gönderilmiş');
ok('bitişik encoded-word birleşir', mail_mime_baslik_coz("=?UTF-8?Q?Mer?= =?UTF-8?Q?haba?=") === 'Merhaba');
ok('Rusça koi8-r', mail_mime_baslik_coz('=?koi8-r?B?8NLJ18XU?=') === 'Привет');
ok('düz başlık dokunulmaz', mail_mime_baslik_coz('Hello World') === 'Hello World');
ok('bozuk base64 çökmez', is_string(mail_mime_baslik_coz('=?UTF-8?B?!!!?=')));
ok('geçersiz UTF-8 başlık Türkçe yedeğiyle okunur', mail_mime_baslik_coz("G\xF6nder") === 'Gönder');
ok('kontrol karakterleri temizlenir', mail_mime_baslik_coz("a\x00b\x07c") === 'abc');

echo "\n=== A2. Adresler ===\n";
$a = mail_mime_adresler('"Yılmaz, Ahmet" <AHMET@Musteri.com>, ali@x.com (Ali Veli), Grup: a@b.com, c@d.com;, kotu@, =?UTF-8?B?w4dhxJ9sYXI=?= <cagla@x.com>');
ok('tırnaklı virgül', $a[0] === ['name' => 'Yılmaz, Ahmet', 'email' => 'ahmet@musteri.com']);
ok('parantez içi isim', $a[1] === ['name' => 'Ali Veli', 'email' => 'ali@x.com']);
ok('grup adresleri açılır', in_array('a@b.com', array_column($a, 'email'), true) && in_array('c@d.com', array_column($a, 'email'), true));
ok('geçersiz adres atılır', !in_array('kotu@', array_column($a, 'email'), true));
ok('encoded isim', in_array(['name' => 'Çağlar', 'email' => 'cagla@x.com'], $a, true));
ok('tekrarlayan adres tekilleşir', count(mail_mime_adresler('a@x.com, A@X.com')) === 1);
ok('CRLF enjeksiyonlu adres geçersiz', mail_mime_adresler("a@x.com\r\nBcc: z@z.com") === [] || array_column(mail_mime_adresler("a@x.com\r\nBcc: z@z.com"), 'email') === ['a@x.com']);
ok('100 adres sınırı', count(mail_mime_adresler(implode(',', array_map(fn($i) => "u$i@x.com", range(1, 300))))) === 100);

echo "\n=== A3. RFC2231 parametreler ===\n";
$p = mail_mime_param_coz("attachment; filename*=UTF-8''%C3%87al%C4%B1%C5%9Fma%20Plan%C4%B1.pdf");
ok('filename*= percent-decode', $p['params']['filename'] === 'Çalışma Planı.pdf');
$p = mail_mime_param_coz('attachment; filename*0*=utf-8\'\'T%C3%BCrk; filename*1*=%C3%A7e.txt');
ok('filename*0*/1* birleşir', $p['params']['filename'] === 'Türkçe.txt');
$p = mail_mime_param_coz('text/html; charset="utf-8"; name="a;b.html"');
ok('tırnak içinde noktalı virgül', $p['params']['name'] === 'a;b.html' && $p['deger'] === 'text/html');
ok('dosya adı yol ayracı temizlenir', mail_mime_gonder_adi('../../etc/passwd') === '.._.._etc_passwd');

echo "\n=== A4. Gövde / multipart ===\n";
$m = mail_mime_mesaj(mail_test_raw(['subject' => '=?UTF-8?B?w4dhbMSxxZ9rYW4=?=', 'body' => "Merhaba\r\nDünya"]));
ok('basit mesaj alanları', $m['subject'] === 'Çalışkan' && $m['from_addr'] === 'ahmet@musteri.com' && $m['from_name'] === 'Ahmet' && str_contains($m['body_text'], 'Dünya'));
ok('Message-ID normalize + hash', $m['message_id'] !== null && $m['message_id_hash'] === mail_mime_id_hash($m['message_id']));
$m = mail_mime_mesaj(mail_test_raw(['msgid' => null, 'subject' => 'X']));
ok('Message-ID yoksa başlıktan hash', $m['message_id'] === null && str_starts_with($m['message_id_hash'], sha1('noid:') ? '' : '') && strlen($m['message_id_hash']) === 40);
$m1 = mail_mime_mesaj(mail_test_raw(['msgid' => null, 'subject' => 'A'])); $m2 = mail_mime_mesaj(mail_test_raw(['msgid' => null, 'subject' => 'B']));
ok('Message-ID\'siz farklı mesajlar farklı hash', $m1['message_id_hash'] !== $m2['message_id_hash']);
$m = mail_mime_mesaj(mail_test_raw(['ctype' => 'text/plain; charset=iso-8859-9', 'cte' => 'quoted-printable', 'body' => "G=F6nderilen =DEirket"]));
ok('quoted-printable + iso-8859-9', str_contains($m['body_text'], 'Gönderilen Şirket'));
$m = mail_mime_mesaj(mail_test_raw(['ctype' => 'text/plain; charset=utf-8', 'cte' => 'base64', 'body' => chunk_split(base64_encode('Şifreli gövde'))]));
ok('base64 gövde', str_contains($m['body_text'], 'Şifreli gövde'));
$m = mail_mime_mesaj(mail_test_raw(['ctype' => 'text/plain; charset=windows-1251', 'body' => mb_convert_encoding('Здравствуйте', 'Windows-1251', 'UTF-8'), 'cte' => '8bit']));
ok('windows-1251 Rusça gövde', str_contains($m['body_text'], 'Здравствуйте'));

$b = 'BND_1';
$alt = "--$b\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nDüz metin sürümü\r\n--$b\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>HTML <b>sürümü</b><script>alert(1)</script></p>\r\n--$b--\r\n";
$m = mail_mime_mesaj(mail_test_raw(['ctype' => "multipart/alternative; boundary=\"$b\"", 'body' => $alt]));
ok('alternative: düz + HTML ayrı', $m['body_text'] === 'Düz metin sürümü' && str_contains($m['body_html_safe'], '<b>sürümü</b>') && !str_contains($m['body_html_safe'], 'script'));

$mix = 'MIX_1'; $alt2 = 'ALT_2';
$govde = "--$mix\r\nContent-Type: multipart/alternative; boundary=\"$alt2\"\r\n\r\n--$alt2\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nMetin\r\n--$alt2\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>Html</p>\r\n--$alt2--\r\n"
    . "--$mix\r\nContent-Type: application/pdf; name=\"fatura.pdf\"\r\nContent-Disposition: attachment; filename=\"fatura.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode(str_repeat('x', 3000))) . "\r\n"
    . "--$mix\r\nContent-Type: image/png\r\nContent-ID: <logo@x>\r\nContent-Disposition: inline; filename=\"logo.png\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode('PNGDATA') . "\r\n--$mix--\r\n";
$m = mail_mime_mesaj(mail_test_raw(['ctype' => "multipart/mixed; boundary=\"$mix\"", 'body' => $govde]));
ok('iç içe multipart: 2 ek', count($m['attachments']) === 2);
ok('ek metadata: ad/mime/boyut', $m['attachments'][0]['filename'] === 'fatura.pdf' && $m['attachments'][0]['mime'] === 'application/pdf' && abs($m['attachments'][0]['size'] - 3000) < 40);
ok('IMAP parça numaraları (alternative=1.x, pdf=2, png=3)', $m['attachments'][0]['part'] === '2' && $m['attachments'][1]['part'] === '3', json_encode(array_column($m['attachments'], 'part')));
ok('inline görsel cid ile işaretlenir', $m['attachments'][1]['inline'] === true && $m['attachments'][1]['cid'] === 'logo@x');
ok('ek içeriği metne sızmıyor', $m['body_text'] === 'Metin');
// Yalnız HTML
$m = mail_mime_mesaj(mail_test_raw(['ctype' => 'text/html; charset=utf-8', 'body' => '<div>Merhaba<br>Dünya</div><p>Satır</p><style>.x{}</style>']));
ok('yalnız HTML: metin HTML\'den türetilir', str_contains($m['body_text'], 'Merhaba') && str_contains($m['body_text'], 'Satır') && !str_contains($m['body_text'], '.x{}'));
// Sınırlar
$m = mail_mime_mesaj(mail_test_raw(['body' => str_repeat('a', MAIL_METIN_MAX + 5000)]));
ok('1 MB gövde sınırı + kesik bayrağı', strlen($m['body_text']) <= MAIL_METIN_MAX && $m['body_truncated'] === 1);
$derin = ''; for ($i = 0; $i < 30; $i++) $derin = "Content-Type: multipart/mixed; boundary=\"b$i\"\r\n\r\n--b$i\r\n$derin\r\n--b$i--\r\n";
$t0 = microtime(true); $m = mail_mime_mesaj("From: a@x.com\r\n" . $derin);
ok('30 derinlikli multipart çökmez/yavaşlamaz', is_array($m) && microtime(true) - $t0 < 2);
$cok = "Content-Type: multipart/mixed; boundary=\"B\"\r\n\r\n"; for ($i = 0; $i < 600; $i++) $cok .= "--B\r\nContent-Type: text/plain\r\n\r\nx$i\r\n"; $cok .= "--B--\r\n";
$m = mail_mime_mesaj("From: a@x.com\r\n" . $cok);
ok('600 parça: 200 parça sınırı', is_array($m) && substr_count($m['body_text'], 'x') <= 210);
ok('bozuk/boş girdi çökmez', is_array(mail_mime_mesaj('')) && is_array(mail_mime_mesaj("\x00\xff\xfe garbage")) && is_array(mail_mime_mesaj("Content-Type: multipart/mixed\r\n\r\nx")));
$m = mail_mime_mesaj(mail_test_raw(['extra' => ['In-Reply-To: <Parent@X.com>', 'References: <root@x.com> <Parent@X.com>']]));
ok('In-Reply-To / References: yazım KORUNUR (yalnız hash harf-duyarsız)', $m['in_reply_to'] === 'Parent@X.com' && $m['references'] === ['root@x.com', 'Parent@X.com'] && mail_mime_id_hash('Parent@X.com') === mail_mime_id_hash('parent@x.com'));
$m = mail_mime_mesaj(mail_test_raw(['date' => 'Mon, 05 Oct 2026 10:00:00 +0300']));
ok('Date çözülür', $m['date'] === '2026-10-05 10:00:00');
ok('geçersiz Date null', mail_mime_mesaj(mail_test_raw(['date' => 'yok']))['date'] === null);

echo "\n=== B. XSS KORPUSU ===\n";
function dogrula(string $cikti): array {
    $izinliEtiket = mail_html_izinli();
    $izinliNitelik = ['style', 'dir', 'align', 'valign', 'width', 'height', 'border', 'cellpadding', 'cellspacing', 'colspan', 'rowspan', 'size',
        'color', 'bgcolor', 'title', 'href', 'rel', 'target', 'src', 'data-cid', 'data-blocked-src', 'alt'];
    $hatalar = [];
    $d = new DOMDocument(); libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8"?><html><body>' . $cikti . '</body></html>', LIBXML_NONET);
    libxml_clear_errors();
    $xp = new DOMXPath($d);
    foreach ($xp->query('//body//*') as $el) {
        $t = strtolower($el->localName);
        if (!in_array($t, $izinliEtiket, true)) $hatalar[] = "etiket:$t";
        foreach ($el->attributes as $at) {
            $ad = strtolower($at->name); $v = $at->value;
            if (!in_array($ad, $izinliNitelik, true)) $hatalar[] = "nitelik:$ad";
            if (str_starts_with($ad, 'on')) $hatalar[] = "olay:$ad";
            if ($ad === 'href' && !preg_match('#^(https?://|mailto:|tel:|\#)#i', $v)) $hatalar[] = "href:$v";
            if ($ad === 'src' && !preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $v)) $hatalar[] = "src:$v";
            if ($ad === 'data-blocked-src' && !preg_match('#^https?://#i', $v)) $hatalar[] = "blocked:$v";
            if ($ad === 'style' && preg_match('/url\s*\(|expression|javascript|behavior|@import|\\\\/i', $v)) $hatalar[] = "style:$v";
        }
    }
    foreach ($xp->query('//body//comment() | //body//processing-instruction()') as $x) $hatalar[] = 'yorum/PI';
    return $hatalar;
}
$korpus = [
    'script' => '<script>alert(1)</script><p>x</p>',
    'script büyük harf' => '<ScRiPt SRC=//evil/x.js></sCrIpT>',
    'img onerror' => '<img src=x onerror=alert(1)>',
    'img onerror tırnaksız çok nitelik' => '<img/src=x/onerror=alert(1)//>',
    'svg onload' => '<svg onload=alert(1)><circle/></svg>',
    'svg içinde script' => '<svg><script>alert(1)</script></svg>',
    'math xlink' => '<math><mi xlink:href="javascript:alert(1)">x</mi></math>',
    'a javascript' => '<a href="javascript:alert(1)">tıkla</a>',
    'a java\tscript' => "<a href=\"jav\tascript:alert(1)\">x</a>",
    'a java\nscript' => "<a href=\"java\nscript:alert(1)\">x</a>",
    'a entity javascript' => '<a href="&#106;avascript:alert(1)">x</a>',
    'a entity hex' => '<a href="&#x6A;avascript&colon;alert(1)">x</a>',
    'a vbscript' => '<a href="vbscript:msgbox(1)">x</a>',
    'a data html' => '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
    'a protokol-göreli' => '<a href="//evil.com/x">x</a>',
    'a boşluklu başlangıç' => '<a href="  javascript:alert(1)">x</a>',
    'iframe' => '<iframe src="javascript:alert(1)"></iframe>',
    'iframe srcdoc' => '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
    'object' => '<object data="javascript:alert(1)"></object>',
    'embed' => '<embed src="javascript:alert(1)">',
    'form action' => '<form action="https://evil/"><input name=x><button formaction="javascript:alert(1)">go</button></form>',
    'meta refresh' => '<meta http-equiv="refresh" content="0;url=javascript:alert(1)">',
    'base href' => '<base href="https://evil/"><a href="x">x</a>',
    'link stylesheet' => '<link rel=stylesheet href="https://evil/x.css">',
    'style etiketi' => '<style>@import "https://evil/x.css"; body{background:url(javascript:alert(1))}</style>',
    'style url' => '<div style="background:url(javascript:alert(1))">x</div>',
    'style expression' => '<div style="width:expression(alert(1))">x</div>',
    'style behavior' => '<td style="behavior:url(x.htc)">x</td>',
    'style kaçışlı' => '<div style="background:\\75rl(javascript:alert(1))">x</div>',
    'style yorum' => '<div style="color:red;/*x*/background:url(//evil/p.gif)">x</div>',
    'style position:fixed' => '<div style="position:fixed;top:0;left:0;width:100%;height:100%">kaplama</div>',
    'body onload' => '<body onload=alert(1)><p>x</p></body>',
    'noscript mXSS' => '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
    'template' => '<template><script>alert(1)</script></template>',
    'yorum hilesi' => '<!--><script>alert(1)</script>-->',
    'CDATA' => '<![CDATA[<script>alert(1)</script>]]>',
    'xmp' => '<xmp><script>alert(1)</script></xmp>',
    'plaintext' => '<plaintext><script>alert(1)</script>',
    'listing' => '<listing><img src=x onerror=alert(1)></listing>',
    'input autofocus' => '<input autofocus onfocus=alert(1)>',
    'video source' => '<video><source onerror=alert(1)></video>',
    'details ontoggle' => '<details open ontoggle=alert(1)>x</details>',
    'marquee onstart' => '<marquee onstart=alert(1)>x</marquee>',
    'table background' => '<table background="javascript:alert(1)"><tr><td>x</td></tr></table>',
    'img src data svg' => '<img src="data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+">',
    'img src data html' => '<img src="data:text/html,<script>alert(1)</script>">',
    'img uzak izleme pikseli' => '<img src="https://track.evil/p.gif?u=1" width=1 height=1>',
    'img lowsrc/dynsrc' => '<img lowsrc="javascript:alert(1)" dynsrc="javascript:alert(1)" src=x>',
    'class/id/name' => '<p class="a" id="b" name="c" onclick="x()">x</p>',
    'isindex' => '<isindex action="javascript:alert(1)" type=image>',
    'null bayt' => "<scr\x00ipt>alert(1)</scr\x00ipt><img src=x on\x00error=alert(1)>",
    'bozuk iç içe' => '<a href="x"><a href="javascript:alert(1)"><b>x</a></b>',
    'unicode süsleme' => "<a href=\"\u{200B}javascript:alert(1)\">x</a>",
    'fieldset/legend/label' => '<label for=x onclick=alert(1)>x</label>',
    'ruby' => '<ruby onclick=alert(1)>x<rt>y</rt></ruby>',
    'picture' => '<picture><source srcset="javascript:alert(1)"><img src=x onerror=alert(1)></picture>',
    'dialog' => '<dialog open onclose=alert(1)>x</dialog>',
    'select/option' => '<select onchange=alert(1)><option>x</option></select>',
    'textarea mXSS' => '<textarea></textarea><img src=x onerror=alert(1)>',
    'title mXSS' => '<title></title><img src=x onerror=alert(1)>',
    'font color expression' => '<font color="expression(alert(1))" size="javascript:1">x</font>',
    'align js' => '<p align="javascript:alert(1)" dir="x">x</p>',
];
$yanlis = 0;
foreach ($korpus as $ad => $v) {
    $c = mail_html_sanitize($v);
    $hatalar = dogrula($c);
    $tekrar = mail_html_sanitize($c);
    $kararli = ($tekrar === $c);
    ok("XSS: $ad", $hatalar === [] && $kararli, json_encode($hatalar) . ' → ' . $c . ($kararli ? '' : ' | KARARSIZ: ' . $tekrar));
}
// Özel durumlar
ok('script İÇERİĞİ de düşer (metin olarak sızmaz)', !str_contains(mail_html_sanitize('<p>a</p><script>alert(1)</script>'), 'alert'));
ok('style İÇERİĞİ düşer', !str_contains(mail_html_sanitize('<style>.x{}</style><p>a</p>'), '.x{}'));
ok('meşru biçim korunur', mail_html_sanitize('<p style="color:#ff0000;font-weight:bold"><b>Merhaba</b> <a href="https://asya.com/x?a=1&b=2">site</a></p>')
    === '<p style="color:#ff0000;font-weight:bold"><b>Merhaba</b> <a href="https://asya.com/x?a=1&amp;b=2" rel="noopener noreferrer nofollow" target="_blank">site</a></p>');
ok('mailto/tel bağlantıları korunur', str_contains(mail_html_sanitize('<a href="mailto:a@b.com">m</a><a href="tel:+90555">t</a>'), 'href="mailto:a@b.com"') && str_contains(mail_html_sanitize('<a href="tel:+90555">t</a>'), 'href="tel:+90555"'));
ok('bağlantı HER ZAMAN noopener/noreferrer + _blank', preg_match('/rel="noopener noreferrer nofollow" target="_blank"/', mail_html_sanitize('<a href="https://x.com">x</a>')) === 1);
$u = mail_html_sanitize('<img src="https://track.evil/p.gif" alt="logo">');
ok('uzak görsel src\'si YOK, data-blocked-src var', !str_contains($u, ' src=') && str_contains($u, 'data-blocked-src="https://track.evil/p.gif"'));
ok('data:image/png korunur', str_contains(mail_html_sanitize('<img src="data:image/png;base64,iVBORw0KGgo=">'), 'src="data:image/png;base64,iVBORw0KGgo="'));
ok('cid: görsel data-cid olur', str_contains(mail_html_sanitize('<img src="cid:Logo@X">'), 'data-cid="logo@x"'));
ok('bilinmeyen etiket sarılıp içeriği korunur', mail_html_sanitize('<customtag>İçerik</customtag><o:p>Metin</o:p>') === 'İçerik<p>Metin</p>');
ok('metin içindeki < > & kaçırılır', mail_html_sanitize('<p>1 &lt; 2 &amp; 3 > 2</p>') === '<p>1 &lt; 2 &amp; 3 &gt; 2</p>');
ok('boş girdi', mail_html_sanitize('') === '' && mail_html_sanitize("  \n") === '');
$derin = str_repeat('<div>', 5000) . 'x' . str_repeat('</div>', 5000);
$t0 = microtime(true); $c = mail_html_sanitize($derin);
ok('5000 derinlikli HTML çökmez, makul sürede biter', is_string($c) && microtime(true) - $t0 < 3);
ok('devasa HTML sınır içinde', strlen(mail_html_sanitize(str_repeat('<p>x</p>', 600000))) <= MAIL_HTML_MAX * 4);

echo "\n=== C. iframe srcdoc ===\n";
$sd = mail_html_iframe_srcdoc('<p>x</p><img data-blocked-src="https://t/p.gif">');
$csp = html_entity_decode((string)(preg_match('/Content-Security-Policy" content="([^"]*)"/', $sd, $cm) ? $cm[1] : ''), ENT_QUOTES);
ok('CSP: script/frame YOK, görsel yalnız data:', str_contains($csp, "default-src 'none'") && str_contains($csp, "img-src data:;") && !str_contains($csp, 'https:') && !str_contains($csp, 'script-src'), $csp);
ok('uzak görsel kapalıyken data-blocked-src dönüştürülmez', str_contains($sd, 'data-blocked-src') && !str_contains($sd, ' src="https'));
$sd2 = mail_html_iframe_srcdoc('<img data-blocked-src="https://t/p.gif">', true);
ok('kullanıcı açınca src olur ve CSP https: ekler', str_contains($sd2, ' src="https://t/p.gif"') && str_contains($sd2, 'img-src data: https:'));
ok('base target=_blank, form-action none, base-uri none', str_contains($sd, '<base target="_blank">') && str_contains($csp, "form-action 'none'") && str_contains($csp, "base-uri 'none'"));
mail_test_bitir();
