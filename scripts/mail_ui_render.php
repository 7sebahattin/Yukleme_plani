<?php
// =========================================================
// scripts/mail_ui_render.php — Mail Merkezi sayfalarını TARAYICI testi için HTML dosyalarına basar.
//   MAIL_UI_OUT=/tmp/mail-ui php scripts/mail_ui_render.php
//   MAIL_UI_OUT=/tmp/mail-ui node scripts/mail_ui_smoke.js
// Bellek içi SQLite + stub auth; üretim style.css + mail.css satır içi (file:// altında ölçülebilsin).
// Canlı DB'ye dokunmaz. (bottomnav_render.php deseni.)
// =========================================================
require_once __DIR__ . '/_mail_test_lib.php';
require_once $ROOT . '/config/mail_imap.php';
require_once $ROOT . '/config/mail_mime.php';
require_once $ROOT . '/config/mail_sync.php';
require_once $ROOT . '/config/mail_view.php';
require_once $ROOT . '/config/mail_translate.php';

$OUT = getenv('MAIL_UI_OUT') ?: (sys_get_temp_dir() . '/mail-ui-test');
@mkdir($OUT, 0777, true);
$OUT = realpath($OUT);
ini_set('session.save_path', sys_get_temp_dir());
ini_set('session.use_cookies', '0');
session_start();

$db = db();
mail_test_diger_tablolar($db); mail_test_sema_kur($db); mail_test_anahtar_kur();
$db->exec("CREATE TABLE material_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, type TEXT, is_active INT DEFAULT 1, color TEXT)");
$db->exec("INSERT INTO material_definitions (name, type, color) VALUES ('MERKEZ DEPO', 'depo', '#16a34a')");

foreach (['info@asya.com' => 'Asya Info', 'satis@asya.com' => 'Asya Satış'] as $e => $l) {
    mail_hesap_kaydet(['label' => $l, 'email' => $e, 'imap_host' => 'i.test.com', 'imap_user' => $e, 'imap_pass' => 'p-imap-1', 'smtp_host' => 's.test.com', 'smtp_user' => $e, 'smtp_pass' => 'p-smtp-1'], null, 1, $db);
}
mail_hesap_kullanici_ata(1, [2], $db); mail_hesap_kullanici_ata(2, [2], $db);

function ekle(array $o): int {
    global $db;
    $o += ['acc' => 1, 'uid' => random_int(1000, 999999), 'subject' => 'Konu', 'from' => 'Ivan Petrov', 'addr' => 'ivan@musteri.ru', 'tarih' => date('Y-m-d H:i:s'),
        'body' => 'Gövde', 'html' => null, 'okundu' => 0, 'tr' => 'skipped', 'body_tr' => null, 'subject_tr' => null, 'lang' => null, 'ek' => null, 'trunc' => 0];
    $db->prepare("INSERT INTO mail_messages (account_id, folder, uidvalidity, uid, message_id_hash, subject, from_name, from_addr, received_at, body_text, body_html_safe, is_read, tr_status, body_tr, subject_tr, lang, attachments_json, has_attachments, to_addrs, cc_addrs, body_truncated)
        VALUES (?, 'INBOX', 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '[]', ?)")
        ->execute([$o['acc'], $o['uid'], sha1(uniqid('', true)), $o['subject'], $o['from'], $o['addr'], $o['tarih'], $o['body'], $o['html'], $o['okundu'], $o['tr'], $o['body_tr'], $o['subject_tr'], $o['lang'],
            $o['ek'] ? json_encode($o['ek']) : null, $o['ek'] ? 1 : 0, json_encode([['name' => 'Asya', 'email' => 'info@asya.com']]), $o['trunc']]);
    return (int)$db->lastInsertId();
}
$kimler = ['Ivan Petrov', 'Hans Müller', 'Çağlar Şahin', 'Maria Gonzalez-Fernandez-De-La-Cruz', 'Ahmed Al-Rashid'];
for ($i = 0; $i < 14; $i++) {
    ekle(['subject' => ['Order 4411 — payment confirmation and loading schedule for next week', 'Re: Offer', 'Fatura', 'Reklamation Nr. 7', 'Заказ №12 — подтверждение'][$i % 5],
        'from' => $kimler[$i % 5], 'okundu' => $i % 3 === 0 ? 1 : 0, 'tarih' => date('Y-m-d H:i:s', time() - $i * 7200),
        'body' => str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 4), 'acc' => $i % 2 ? 2 : 1,
        'ek' => $i === 1 ? [['part' => '2', 'filename' => 'invoice.pdf', 'mime' => 'application/pdf', 'size' => 123456, 'inline' => false, 'cid' => null, 'cte' => 'base64']] : null]);
}
$guvenliHtml = mail_html_sanitize('<p style="color:#1a1a1a">Dear <b>partner</b>,</p><p>Please see the <a href="https://example.com/x">details</a>.</p><table style="width:100%"><tr><td>Item</td><td>12 pallets</td></tr></table><img src="https://track.example.net/p.gif" alt="logo" width="200"><blockquote>On Monday you wrote: thanks.</blockquote>');
$ID_HTML = ekle(['subject' => 'HTML mail — remote image blocked', 'html' => $guvenliHtml, 'body' => 'Dear partner, Please see the details.', 'lang' => 'en', 'tr' => 'pending',
    'ek' => [['part' => '2', 'filename' => 'packing-list.xlsx', 'mime' => 'application/vnd.ms-excel', 'size' => 54321, 'inline' => false, 'cid' => null, 'cte' => 'base64'],
             ['part' => '3', 'filename' => 'setup.exe', 'mime' => 'application/octet-stream', 'size' => 999, 'inline' => false, 'cid' => null, 'cte' => 'base64']]]);
$ID_TR = ekle(['subject' => 'Order confirmation', 'body' => "Hello,\nthe order is confirmed.", 'lang' => 'en', 'tr' => 'translated', 'subject_tr' => 'Sipariş onayı', 'body_tr' => "Merhaba,\nsipariş onaylandı.\n<b>satır</b>"]);
// Savunma derinliği: temizleyicinin atlandığı VARSAYIMIYLA ham zararlı HTML doğrudan saklı → iframe sandbox + CSP yine korumalı olmalı.
$ID_XSS = ekle(['subject' => 'SAKLI XSS DENEMESİ', 'html' => '<p>zararlı</p><script>window.__pwned=1</script><img src=x onerror="window.__pwned=2"><a id="jslink" href="javascript:window.__pwned=3">tıkla</a><iframe srcdoc="<script>parent.__pwned=4</script>"></iframe><form action="https://evil.example/" method="post"><button id="f">gönder</button></form>', 'body' => 'x']);
$ID_UZUN = ekle(['subject' => str_repeat('Çok uzun konu satırı ', 12), 'from' => 'Maria Gonzalez-Fernandez-De-La-Cruz-Y-Fernandez', 'body' => str_repeat('uzunkelime', 30) . "\n" . str_repeat('Satır satır metin. ', 80), 'trunc' => 1]);

$PERMS = ['mail.read', 'mail.reply']; $UID = 2;
$cssBase = file_get_contents($ROOT . '/assets/style.css');
$cssMail = file_get_contents($ROOT . '/assets/mail.css');
$fileRoot = 'file://' . $ROOT . '/';

function basla_sayfa(array $get, string $ad, bool $yonetici = false, array $perms = ['mail.read', 'mail.reply'], int $uid = 2): string {
    global $ROOT, $cssBase, $cssMail, $fileRoot, $PERMS, $IS_ADMIN, $UID;
    $PERMS = $perms; $IS_ADMIN = $yonetici; $UID = $uid;
    $src = (string)file_get_contents($ROOT . '/mail.php');
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth|mail_core|mail_imap|mail_mime|mail_sync|mail_view|mail_translate)\.php';\s*$/m", '', $src);
    $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
    $tmp = sys_get_temp_dir() . '/mail_ui_render_' . getmypid() . '.php';
    file_put_contents($tmp, $src);
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['PHP_SELF'] = '/mail.php';
    ob_start();
    try { include $tmp; } catch (Throwable $e) { ob_end_clean(); throw $e; } finally { @unlink($tmp); }
    $html = (string)ob_get_clean();
    $html = preg_replace_callback('#<link rel="stylesheet" href="[^"]*assets/style\.css\?v=\d+">#', fn() => "<style data-kaynak=\"style.css\">\n$cssBase\n</style>", $html, 1, $n1);
    $html = preg_replace_callback('#<link rel="stylesheet" href="assets/mail\.css\?v=\d+">#', fn() => "<style data-kaynak=\"mail.css\">\n$cssMail\n</style>", $html, 1, $n2);
    if ($n1 !== 1 || $n2 !== 1) throw new RuntimeException("CSS bağlantısı bulunamadı ($ad): style=$n1 mail=$n2");
    $html = str_replace(['src="assets/', 'href="assets/', '"/assets/', 'href="manifest.json"'], ['src="' . $fileRoot . 'assets/', 'href="' . $fileRoot . 'assets/', '"' . $fileRoot . 'assets/', 'href="' . $fileRoot . 'manifest.json"'], $html);
    return $html;
}

$sayfalar = [
    'liste'        => [[], true],
    'liste_filtre' => [['f' => 'okunmamis'], true],
    'liste_arama'  => [['q' => 'zzz-yok'], true],
    'detay_html'   => [['m' => $ID_HTML, 'v' => 'orj'], true],
    'detay_html_img' => [['m' => $ID_HTML, 'v' => 'orj', 'img' => '1'], true],
    'detay_tr'     => [['m' => $ID_TR, 'v' => 'tr'], true],
    'detay_tr_bekleyen' => [['m' => $ID_HTML, 'v' => 'tr'], true],
    'detay_xss'    => [['m' => $ID_XSS, 'v' => 'orj'], true],
    'detay_uzun'   => [['m' => $ID_UZUN, 'v' => 'orj'], true],
    'liste_hesapsiz' => [[], false, ['mail.read'], 3],
];
$manifest = ['sayfalar' => [], 'id' => ['html' => $ID_HTML, 'tr' => $ID_TR, 'xss' => $ID_XSS]];
foreach ($sayfalar as $ad => $s) {
    $html = basla_sayfa($s[0], $ad, $s[1] ?? false, $s[2] ?? ['mail.read', 'mail.reply'], $s[3] ?? 2);
    file_put_contents("$OUT/$ad.html", $html);
    $manifest['sayfalar'][] = $ad;
}
file_put_contents("$OUT/manifest.json", json_encode($manifest));
echo count($sayfalar) . " sayfa yazıldı → $OUT\n";
