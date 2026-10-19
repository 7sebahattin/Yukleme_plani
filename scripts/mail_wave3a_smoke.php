<?php
// =========================================================
// scripts/mail_wave3a_smoke.php — Mail Merkezi Dalga 3A regresyonları
//   1) Okunmamış rozeti: kenar çubuğu · üst menü · alt çubuk (+ "Diğer" sayfası / güncel öğe) · 99+ sınırı · yetki · ACL · fail-safe
//   2) Ana Sayfa "24 saattir cevapsız N mail" sayacı (ACL kapsamlı)
//   3) "📝 Beyan Taslağı Oluştur": düğme kapısı, ACL (yabancı hesap maili reddedilir), tek kullanımlık oturum, audit'te yalnız mail id,
//      gövde URL'ye taşınmaz, uzunluk sınırı, güvenilmez metin
//   php scripts/mail_wave3a_smoke.php   → çıkış kodu 0 = tüm testler geçti
// Bellek içi SQLite; canlı DB'ye dokunmaz. (Beyan formunun ön-doldurması: beyan_ui_smoke.php `[mail-taslak]`.)
// =========================================================
require_once __DIR__ . '/_mail_test_lib.php';
require_once $ROOT . '/config/mail_imap.php';
require_once $ROOT . '/config/mail_mime.php';
require_once $ROOT . '/config/mail_sync.php';
require_once $ROOT . '/config/mail_view.php';
require_once $ROOT . '/config/mail_translate.php';
require_once $ROOT . '/config/mail_smtp.php';
require_once $ROOT . '/config/mail_outbox.php';
require_once $ROOT . '/config/mail_beyan.php';

ini_set('session.save_path', sys_get_temp_dir());
ini_set('session.use_cookies', '0');
session_start();   // csrf_token() + oturumdaki beyan taslağı

$db = db();
mail_test_diger_tablolar($db); mail_test_sema_kur($db); mail_test_anahtar_kur();
$db->exec("CREATE TABLE material_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, type TEXT, is_active INT DEFAULT 1, color TEXT)");

foreach (['a1@asya.com', 'a2@asya.com', 'a3@asya.com'] as $e) {
    mail_hesap_kaydet(['label' => $e, 'email' => $e, 'imap_host' => 'i.test.com', 'imap_user' => $e, 'imap_pass' => 'p1', 'smtp_host' => 's.test.com', 'smtp_user' => $e, 'smtp_pass' => 'p2'], null, 1, $db);
}
mail_hesap_kullanici_ata(1, [2], $db);   // kullanıcı 2: yalnız hesap 1 (aktif)
mail_hesap_kullanici_ata(3, [2], $db);   // hesap 3 atanmış ama PASİF olacak
$db->exec('UPDATE mail_accounts SET is_active = 0 WHERE id = 3');

function ekle(int $acc, array $o = []): int {
    global $db;
    static $n = 0; $n++;
    $o += ['okundu' => 0, 'needs' => 1, 'replied' => null, 'recv' => date('Y-m-d H:i:s'), 'body' => 'Gövde', 'html' => null, 'tr' => 'skipped', 'body_tr' => null, 'subject' => 'Konu ' . $n];
    $db->prepare("INSERT INTO mail_messages (account_id, folder, uidvalidity, uid, message_id_hash, subject, from_name, from_addr, received_at, body_text, body_html_safe, is_read, needs_reply, replied_at, tr_status, body_tr, to_addrs, cc_addrs)
        VALUES (?, 'INBOX', 1, ?, ?, ?, 'Ivan', 'ivan@musteri.ru', ?, ?, ?, ?, ?, ?, ?, ?, '[]', '[]')")
        ->execute([$acc, 1000 + $n, sha1("m$n"), $o['subject'], $o['recv'], $o['body'], $o['html'], $o['okundu'], $o['needs'], $o['replied'], $o['tr'], $o['body_tr']]);
    return (int)$db->lastInsertId();
}
$eski = date('Y-m-d H:i:s', time() - 30 * 3600);   // 30 saat önce
$taze = date('Y-m-d H:i:s', time() - 2 * 3600);
// hesap 1 (kullanıcı 2 görür): 3 okunmamış (2'si 24 saatten eski + cevap bekliyor), 2 okunmuş (biri eski ama cevaplanmış)
ekle(1, ['recv' => $eski]); ekle(1, ['recv' => $eski]); ekle(1, ['recv' => $taze]);
ekle(1, ['okundu' => 1, 'recv' => $eski, 'needs' => 1, 'replied' => $taze]); ekle(1, ['okundu' => 1, 'recv' => $eski, 'needs' => 0]);
// hesap 2 (kullanıcı 2 GÖRMEZ): 4 okunmamış, hepsi eski + cevap bekliyor
for ($i = 0; $i < 4; $i++) ekle(2, ['recv' => $eski]);
// hesap 3 (PASİF, kullanıcı 2'ye atanmış): 5 okunmamış
for ($i = 0; $i < 5; $i++) ekle(3, ['recv' => $eski]);

echo "=== 1. Sayaç işlevleri (ACL) ===\n";
ok('kullanıcı 2: okunmamış = 3 (yabancı + pasif hesap sayılmaz)', mail_okunmamis_toplam(2, $db, false) === 3, (string)mail_okunmamis_toplam(2, $db, false));
ok('yönetici: tüm hesaplar = 12', mail_okunmamis_toplam(2, $db, true) === 12, (string)mail_okunmamis_toplam(2, $db, true));
ok('toplam = mail_okunmamis_sayilari() toplamı (kullanıcı)', mail_okunmamis_toplam(2, $db, false) === array_sum(mail_okunmamis_sayilari(2, $db, false)));
ok('toplam = mail_okunmamis_sayilari() toplamı (yönetici)', mail_okunmamis_toplam(2, $db, true) === array_sum(mail_okunmamis_sayilari(2, $db, true)));
ok('atanmamış kullanıcı 3 → 0', mail_okunmamis_toplam(3, $db, false) === 0);
ok('24 saattir cevapsız: kullanıcı 2 = 2 (taze, cevaplanmış, yabancı/pasif hesap hariç)', mail_cevapsiz_eski_sayisi(2, 24, $db, false) === 2, (string)mail_cevapsiz_eski_sayisi(2, 24, $db, false));
ok('24 saattir cevapsız: yönetici = 2 + 4 + 5 + 0 (cevaplanmış hariç) = 11', mail_cevapsiz_eski_sayisi(2, 24, $db, true) === 11, (string)mail_cevapsiz_eski_sayisi(2, 24, $db, true));
ok('48 saat eşiğinde hiçbiri yok', mail_cevapsiz_eski_sayisi(2, 48, $db, true) === 0);
ok('atanmamış kullanıcı için cevapsız = 0', mail_cevapsiz_eski_sayisi(3, 24, $db, false) === 0);
ok('rozet metni: 99 → 99, 100 → 99+, 0 → 0', nav_rozet_metni(99) === '99' && nav_rozet_metni(100) === '99+' && nav_rozet_metni(5000) === '99+' && nav_rozet_metni(0) === '0');
ok('n<=0 → rozet işaretlemesi YOK', nav_rozet_html(0) === '' && nav_rozet_html(-3) === '');

echo "\n=== 2. nav_mail_okunmamis(): yetki + önbellek + fail-safe ===\n";
$UID = 2; $IS_ADMIN = false;
$PERMS = ['dashboard.read', 'records.read'];
ok('mail.read yok → 0 (okunmamış mail olsa da)', nav_mail_okunmamis(true) === 0);
$PERMS = ['mail.reply'];
ok('yalnız mail.reply (read yok) → 0', nav_mail_okunmamis(true) === 0);
$PERMS = ['mail.read'];
ok('mail.read → 3', nav_mail_okunmamis(true) === 3, (string)nav_mail_okunmamis(true));
$db->exec("INSERT INTO mail_messages (account_id, folder, uidvalidity, uid, message_id_hash, received_at, is_read) VALUES (1,'INBOX',1,99999,'" . sha1('x') . "','" . date('Y-m-d H:i:s') . "',0)");
ok('istek içinde ÖNBELLEKLİ (ikinci çağrı sorgu yapmaz, aynı değer)', nav_mail_okunmamis() === 3);
ok('önbellek sıfırlanınca yeni değer (4)', nav_mail_okunmamis(true) === 4);
$db->exec('DELETE FROM mail_messages WHERE uid = 99999');
$PERMS = []; $IS_ADMIN = true;
ok('is_admin → tüm hesaplar (12)', nav_mail_okunmamis(true) === 12, (string)nav_mail_okunmamis(true));
$IS_ADMIN = false; $PERMS = ['mail.admin'];
ok('mail.admin → tüm hesaplar (12)', nav_mail_okunmamis(true) === 12);
$PERMS = ['mail.read'];
// fail-safe: tablo bozuk/yok → 0, sayfa çizimi PATLAMAZ
$db->exec('ALTER TABLE mail_messages RENAME TO mail_messages_yedek');
ok('mail_messages yok → 0 (istisna yutulur)', nav_mail_okunmamis(true) === 0);
$ciktiHata = null;
$_SERVER['PHP_SELF'] = '/index.php';
ob_start();
try { render_header('Test'); render_footer(); } catch (Throwable $e) { $ciktiHata = $e->getMessage(); }
$htmlBozuk = (string)ob_get_clean();
ok('tablo yokken sayfa (kenar çubuğu + alt çubuk) hatasız çizilir, rozet yok', $ciktiHata === null && str_contains($htmlBozuk, 'sidebar-link') && !str_contains($htmlBozuk, 'nav-rozet'), (string)$ciktiHata);
$db->exec('ALTER TABLE mail_messages_yedek RENAME TO mail_messages');
nav_mail_okunmamis(true);

echo "\n=== 3. Çizim: kenar çubuğu · üst menü · alt çubuk ===\n";
function sayfa_ciz(string $php_self, array $perms, bool $admin = false): string {
    global $PERMS, $IS_ADMIN, $UID;
    $PERMS = $perms; $IS_ADMIN = $admin; $UID = 2;
    $_SERVER['PHP_SELF'] = $php_self; $_SERVER['SCRIPT_NAME'] = $php_self; $_SERVER['REQUEST_URI'] = $php_self;
    unset($GLOBALS['_nav_cikma_hint']);
    nav_mail_okunmamis(true);
    ob_start();
    render_header('Test');
    echo '<div class="container">içerik</div>';
    render_footer();
    return (string)ob_get_clean();
}
function parca(string $html, string $bas, string $son): string {
    $a = strpos($html, $bas); if ($a === false) return '';
    $b = strpos($html, $son, $a); return $b === false ? substr($html, $a) : substr($html, $a, $b - $a);
}
$h = sayfa_ciz('/index.php', ['dashboard.read', 'mail.read']);
$sb = parca($h, '<aside class="desktop-sidebar"', '</aside>');
ok('kenar çubuğu Mail Merkezi bağlantısında rozet "3" + aria-label', preg_match('#href="[^"]*mail\.php" class="sidebar-link"[^>]*aria-label="Mail Merkezi, 3 okunmamış"[^>]*>.*?<span class="nav-rozet" aria-hidden="true">3</span>#s', $sb) === 1, substr($sb, strpos($sb, 'mail.php') - 40, 400));
$tn = parca($h, '<nav class="topnav">', '</nav>');
ok('üst menü (tablet) Mail bağlantısında rozet', preg_match('#mail\.php"[^>]*aria-label="Mail, 3 okunmamış"[^>]*>Mail<span class="nav-rozet" aria-hidden="true">3</span>#', $tn) === 1, $tn);
$bn = parca($h, '<nav class="bottomnav"', '</nav>');
ok('alt çubuk Mail karosunda rozet + bağlantı aria-label "Mail Merkezi, 3 okunmamış"',
   preg_match('#<a href="[^"]*mail\.php" class="bn-item[^"]*"[^>]*data-nav="mail"[^>]*aria-label="Mail Merkezi, 3 okunmamış"[^>]*>.*?<span class="nav-rozet" aria-hidden="true">3</span></span>#s', $bn) === 1, $bn);
ok('rozet yalnız Mail karosunda (başka karoda yok)', substr_count($bn, 'nav-rozet') === 1 || substr_count($bn, 'class="nav-rozet') === 1, (string)substr_count($bn, 'nav-rozet'));
ok('alt çubuk işaretlemesi: rozet ni-wrap İÇİNDE (app.js yeni işaretleme üretmez)', preg_match('#<span class="ni-wrap"><img[^>]*><span class="nav-rozet"#', $bn) === 1);

$h0 = sayfa_ciz('/index.php', ['dashboard.read', 'records.read']);
ok('mail yetkisi yok → hiçbir yerde rozet / Mail bağlantısı yok', !str_contains($h0, 'nav-rozet') && !str_contains($h0, 'mail.php'));
$db->exec('UPDATE mail_messages SET is_read = 1');
$hz = sayfa_ciz('/index.php', ['dashboard.read', 'mail.read']);
ok('okunmamış 0 → rozet yok, bağlantı var, aria-label sade', !str_contains($hz, 'nav-rozet') && str_contains($hz, 'mail.php') && !str_contains($hz, 'okunmamış"'));
$sinirId = (int)$db->query('SELECT MAX(id) FROM mail_messages')->fetchColumn();
for ($i = 0; $i < 120; $i++) ekle(1);
$h99 = sayfa_ciz('/index.php', ['dashboard.read', 'mail.read']);
ok('120 okunmamış → "99+" gösterilir, aria-label gerçek sayıyı söyler', str_contains($h99, '>99+</span>') && str_contains($h99, '120 okunmamış') && !str_contains($h99, '>120</span>'));
$db->exec('DELETE FROM mail_messages WHERE id > ' . $sinirId);
$db->exec('UPDATE mail_messages SET is_read = 0 WHERE id IN (1,2,3)');

// "Diğer": çok sayfa izinli → Mail slotlara sığmaz → "Diğer" sayfasındaki karoda rozet; güncel öğe Mail ise Diğer düğmesinde
// v391: soğuk başlangıç sırası değişti (Sipariş/Beyan/Mail öne geldi) → Mail'in slot dışında kalması için siparis.read de verilir.
$BOL = ['dashboard.read', 'records.read', 'reports.read', 'siparis.read', 'beyan.read', 'kantar.read', 'stok.read', 'hesap.read', 'mail.read'];
$hd = sayfa_ciz('/index.php', $BOL);
$bnd = parca($hd, '<nav class="bottomnav"', '</nav>');
$sheet = parca($hd, 'id="bnSheetOvl"', '</section>');
ok('Mail slotta değil → alt çubuk karosu gizli ama rozeti sunucuda basılı', preg_match('#data-nav="mail"[^>]*hidden[^>]*>.*?nav-rozet#s', $bnd) === 1);
ok('"Diğer" sayfasında Mail karosu rozetli + aria-label', preg_match('#class="bn-tile[^"]*" data-nav="mail">.*?aria-label="Mail Merkezi, 3 okunmamış".*?<span class="nav-rozet nav-rozet-tile" aria-hidden="true">3</span>#s', $sheet) === 1, $sheet);
ok('Mail güncel değil → Diğer düğmesindeki rozet GİZLİ (hidden), aria-label sade',
   preg_match('#id="bnMore"[^>]*aria-label="Diğer sayfalar"#', $bnd) === 1 && preg_match('#<span class="nav-rozet nav-rozet-more"[^>]*hidden>#', $bnd) === 1);
$hm = sayfa_ciz('/mail.php', $BOL);
$bnm = parca($hm, '<nav class="bottomnav"', '</nav>');
ok('Mail güncel öğe + Diğer içinde → Diğer düğmesi rozeti GÖRÜNÜR ve aria-label okunmamışı söyler',
   preg_match('#id="bnMore"[^>]*aria-label="Diğer sayfalar, şu an: Mail Merkezi, 3 okunmamış"#', $bnm) === 1
   && preg_match('#<span class="nav-rozet nav-rozet-more" aria-hidden="true">3</span>#', $bnm) === 1);
$appjs = (string)file_get_contents($ROOT . '/assets/app.js');
ok('app.js rozeti yalnız hidden ile açıp kapatır (yeni işaretleme üretmez)', str_contains($appjs, ".nav-rozet-more") && !preg_match('/createElement\([\'"]span[\'"]\)[^;]*nav-rozet/', $appjs));

echo "\n=== 4. Stil: açık/koyu tema, indirgenmiş hareket, mobil ===\n";
$css = (string)file_get_contents($ROOT . '/assets/style.css');
ok('.nav-rozet tanımlı; koyu tema karşılığı var', str_contains($css, '.nav-rozet {') && str_contains($css, 'html[data-theme="dark"] .nav-rozet'));
$blok = substr($css, (int)strrpos($css, '/* v370: Mail Merkezi okunmamış rozeti'));
ok('rozet bloğunda animasyon/geçiş yok (prefers-reduced-motion\'a ek kural gerekmez)', !str_contains($blok, 'transition') && !str_contains($blok, 'animation') && !str_contains($blok, '@keyframes'));
ok('alt çubuk rozeti dokun halkasını --bn-dock ile çizer (koyu temada otomatik)', str_contains($blok, 'box-shadow: 0 0 0 2px var(--bn-dock)'));

echo "\n=== 5. Beyan Taslağı: kapı ===\n";
$UID = 2; $IS_ADMIN = false;
$PERMS = ['mail.read'];
ok('yalnız mail.read → düğme/uç kapısı kapalı', mail_beyan_taslak_yetkisi() === false);
$PERMS = ['beyan.read', 'beyan.write'];
ok('beyan yetkisi var ama mail.read yok → kapalı', mail_beyan_taslak_yetkisi() === false);
$PERMS = ['mail.read', 'beyan.read'];
ok('beyan.read (create/write yok) → kapalı', mail_beyan_taslak_yetkisi() === false);
$PERMS = ['mail.read', 'beyan.read', 'beyan.write'];
ok('mail.read + beyan.write → AÇIK', mail_beyan_taslak_yetkisi() === true);
$PERMS = ['mail.read', 'beyan.read', 'beyan.create', 'beyan.permissions.detailed'];
ok('ayrıntılı izinlerde source.write yoksa kapalı (kaynak metin alanı yazılamaz → prefill boşa giderdi)', mail_beyan_taslak_yetkisi() === false);
$PERMS = []; $IS_ADMIN = true;
ok('is_admin → açık', mail_beyan_taslak_yetkisi() === true);
$IS_ADMIN = false;

echo "\n=== 6. Beyan Taslağı: düğme (mail.php) ===\n";
function mail_sayfa(array $get, array $perms, bool $admin = false): string {
    global $ROOT, $PERMS, $IS_ADMIN, $UID;
    $PERMS = $perms; $IS_ADMIN = $admin; $UID = 2; nav_mail_okunmamis(true);
    $src = (string)file_get_contents($ROOT . '/mail.php');
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth|mail_core|mail_imap|mail_mime|mail_sync|mail_view|mail_translate|mail_smtp|mail_outbox)\.php';\s*$/m", '', $src);
    $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
    $tmp = sys_get_temp_dir() . '/mail_w3a_' . getmypid() . '.php';
    file_put_contents($tmp, $src);
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['PHP_SELF'] = '/mail.php';
    ob_start();
    try { include $tmp; } catch (Throwable $e) { ob_end_clean(); @unlink($tmp); return '__HATA__' . $e->getMessage(); }
    @unlink($tmp);
    return (string)ob_get_clean();
}
$mid = ekle(1, ['subject' => 'Order 55', 'body' => "PARTİ: 123\nÜRÜN: KAYISI\nNET KG: 1000"]);
$d = mail_sayfa(['m' => $mid], ['mail.read', 'beyan.read', 'beyan.write']);
ok('yetkili: "Beyan Taslağı Oluştur" düğmesi var', str_contains($d, 'id="mailBeyanTaslak"') && str_contains($d, 'Beyan Taslağı Oluştur'), substr($d, 0, 200));
ok('düğme POST formu: action=mail_beyan_taslak.php, CSRF + YALNIZ mail id (gövde/metin yok)',
   preg_match('#<form method="post" action="mail_beyan_taslak\.php"[^>]*>\s*<input type="hidden" name="csrf" value="[^"]+"><input type="hidden" name="m" value="' . $mid . '">\s*<button[^>]*id="mailBeyanTaslak"#s', $d) === 1);
ok('düğme formunda başka veri alanı yok (hidden yalnız csrf + m)',
   preg_match('#action="mail_beyan_taslak\.php"(.*?)</form>#s', $d, $mm) === 1 && substr_count($mm[1], '<input') === 2);
$d2 = mail_sayfa(['m' => $mid], ['mail.read']);
ok('beyan yetkisi yok: düğme YOK', !str_contains($d2, 'mailBeyanTaslak') && !str_contains($d2, 'mail_beyan_taslak.php') && str_contains($d2, 'Order 55'));   // mail okuyucu yine açık, yalnız düğme yok
$d3 = mail_sayfa(['m' => $mid], ['mail.read', 'beyan.read']);
ok('yalnız beyan.read: düğme YOK', !str_contains($d3, 'mailBeyanTaslak'));
$dl = mail_sayfa([], ['mail.read', 'beyan.read', 'beyan.write']);
ok('liste ekranında düğme YOK (yalnız okuyucuda)', !str_contains($dl, 'mailBeyanTaslak'));

echo "\n=== 7. Beyan Taslağı: metin seçimi + temizlik ===\n";
$m = ['body_text' => "Hello\r\nPARTI: 5", 'body_tr' => "Merhaba\nPARTİ: 5", 'tr_status' => 'translated', 'body_html_safe' => null];
ok('çevrilmiş Türkçe gövde tercih edilir (ayrıştırıcı Türkçe etiket bekler)', str_starts_with(mail_beyan_metni($m), 'Merhaba'));
ok('çeviri yoksa özgün düz metin; CRLF → LF', mail_beyan_metni(['body_text' => "A\r\nB", 'tr_status' => 'skipped']) === "A\nB");
ok('tr_status translated değilse (örn. failed) body_tr kullanılmaz', mail_beyan_metni(['body_text' => 'ORJ', 'body_tr' => 'TR', 'tr_status' => 'failed']) === 'ORJ');
ok('düz metin yoksa güvenli HTML\'den etiketsiz metin', mail_beyan_metni(['body_text' => '', 'tr_status' => 'skipped', 'body_html_safe' => '<p>Net <b>KG</b>: 1000</p><script>alert(1)</script>']) !== '' && !str_contains(mail_beyan_metni(['body_text' => '', 'tr_status' => 'skipped', 'body_html_safe' => '<p>Net <b>KG</b>: 1000</p>']), '<'));
ok('kontrol / yön değiştirme karakterleri atılır', mail_beyan_metni(['body_text' => "a\x00b\x07c\u{202E}d\u{200B}e", 'tr_status' => 'skipped']) === 'abcde');
$uzun = mail_beyan_metni(['body_text' => str_repeat('Ü', 25000), 'tr_status' => 'skipped']);
ok('20.000 karakterle kırpılır (çok baytlı karakterde de)', mb_strlen($uzun, 'UTF-8') === MAIL_BEYAN_MAX);
ok('HTML/script içeren metin DEĞİŞTİRİLMEDEN taşınır (kaçışlama basım yerinde h() ile)', mail_beyan_metni(['body_text' => '<script>x</script>', 'tr_status' => 'skipped']) === '<script>x</script>');

echo "\n=== 8. Beyan Taslağı: hazırla (ACL, oturum, audit) ===\n";
$PERMS = ['mail.read', 'beyan.write']; $UID = 2;
$gorunur = mail_gorunur_hesap_idleri(2, $db, false);
$yabanci = ekle(2, ['body' => 'YABANCI HESAP GİZLİ METİN']);
$pasif   = ekle(3, ['body' => 'PASİF HESAP METİN']);
unset($_SESSION[MAIL_BEYAN_OTURUM]);
$sn = mail_beyan_taslak_hazirla($db, $yabanci, 2, $gorunur);
ok('yabancı hesabın mail id\'si REDDEDİLİR, oturuma hiçbir şey yazılmaz', !$sn['ok'] && !isset($_SESSION[MAIL_BEYAN_OTURUM]) && !str_contains(json_encode($sn), 'GİZLİ'), json_encode($sn));
$sn = mail_beyan_taslak_hazirla($db, $pasif, 2, $gorunur);
ok('pasif (görünmeyen) hesabın maili reddedilir', !$sn['ok'] && !isset($_SESSION[MAIL_BEYAN_OTURUM]));
ok('olmayan / 0 / negatif id reddedilir', !mail_beyan_taslak_hazirla($db, 999999, 2, $gorunur)['ok'] && !mail_beyan_taslak_hazirla($db, 0, 2, $gorunur)['ok'] && !mail_beyan_taslak_hazirla($db, -5, 2, $gorunur)['ok']);
ok('görünür hesap listesi boşsa (fail-closed) reddedilir', !mail_beyan_taslak_hazirla($db, $mid, 2, [])['ok']);
$bos = ekle(1, ['body' => '   ']);
ok('metni olmayan mail için anlaşılır ret', mail_beyan_taslak_hazirla($db, $bos, 2, $gorunur)['ok'] === false);
$db->exec('DELETE FROM audit_log');
$sn = mail_beyan_taslak_hazirla($db, $mid, 2, $gorunur);
ok('görünür hesabın maili hazırlanır', $sn['ok'] === true && ($_SESSION[MAIL_BEYAN_OTURUM]['m'] ?? 0) === $mid && str_contains($_SESSION[MAIL_BEYAN_OTURUM]['metin'], 'KAYISI'));
$au = $db->query("SELECT * FROM audit_log WHERE action = 'mail_beyan_taslak'")->fetchAll();
ok('audit mail_beyan_taslak: modül mail, kayıt = mail id', count($au) === 1 && $au[0]['module'] === 'mail' && (int)$au[0]['record_id'] === $mid, json_encode($au));
ok('audit içinde mail gövdesi / konu / adres YOK (yalnız mail id + karakter sayısı)',
   !str_contains(json_encode($au), 'KAYISI') && !str_contains(json_encode($au), 'Order 55') && !str_contains(json_encode($au), 'ivan@') && str_contains((string)$au[0]['new_values'], '"mail_id"'));
// Tek kullanımlık okuma
$t = mail_beyan_taslak_al(2);
ok('beyan formu okur: mail id + metin', $t !== null && $t['m'] === $mid && str_contains($t['metin'], 'NET KG'));
ok('TEK KULLANIMLIK: ikinci okuma null', mail_beyan_taslak_al(2) === null && !isset($_SESSION[MAIL_BEYAN_OTURUM]));
mail_beyan_taslak_hazirla($db, $mid, 2, $gorunur);
ok('başka kullanıcı okuyamaz (ve kayıt silinir)', mail_beyan_taslak_al(3) === null && !isset($_SESSION[MAIL_BEYAN_OTURUM]));
mail_beyan_taslak_hazirla($db, $mid, 2, $gorunur);
$_SESSION[MAIL_BEYAN_OTURUM]['t'] = time() - MAIL_BEYAN_TTL - 5;
ok('süresi dolan taslak okunmaz', mail_beyan_taslak_al(2) === null);
mail_beyan_taslak_hazirla($db, $mid, 2, $gorunur);
$PERMS = ['beyan.write'];   // mail.read yetkisi bu arada alındı
ok('okuma anında mail.read yoksa null', mail_beyan_taslak_al(2) === null);
$PERMS = ['mail.read', 'beyan.write'];

echo "\n=== 9. Statik: uç + beyan formu kancası ===\n";
$ep = (string)file_get_contents($ROOT . '/mail_beyan_taslak.php');
ok('uç: require_login + require_mail(read) + yetki kapısı + CSRF + POST şartı', str_contains($ep, 'require_login()') && str_contains($ep, "require_mail('read')") && str_contains($ep, 'mail_beyan_taslak_yetkisi()')
   && str_contains($ep, 'csrf_check(') && str_contains($ep, "'POST'"));
ok('uç: yalnız "m" okur, gövde/metin parametresi YOK; ACL için mail_gorunur_hesap_idleri', substr_count($ep, '$_POST[') === 2 && str_contains($ep, "\$_POST['m']") && str_contains($ep, 'mail_gorunur_hesap_idleri('));
ok('uç: yönlendirme URL\'si sabit (metin taşımaz)', str_contains($ep, "'Location: beyan_create.php?mail_taslak=1'") && !preg_match('/Location:[^;]*(body|metin|text)/i', $ep));
$bc = (string)file_get_contents($ROOT . '/beyan_create.php');
ok('beyan_create: kanca yalnız GET + mail_taslak=1 + source.write; raw_text GET/URL\'den ALINMAZ', str_contains($bc, "=== 'GET' && (\$_GET['mail_taslak'] ?? '') === '1' && can_beyan('source.write')") && !preg_match('/\$_GET\[[\'"](raw|metin|text|body)/', $bc) && str_contains($bc, 'mail_beyan_taslak_al('));
ok('beyan_create: kaydetme akışına (POST) dokunulmadı — kanca INSERT\'ten sonra, yalnız $f[raw_text] doldurur', substr_count($bc, '$f[\'raw_text\'] = $mt[\'metin\']') === 1);
$mp = (string)file_get_contents($ROOT . '/mail.php');
ok('mail.php: düğme kapısı uçla AYNI koşul (can_beyan create + source.write)', str_contains($mp, "can_beyan('create') && can_beyan('source.write')"));
ok('config/mail_beyan.php: tüm kullanıcı metni için DB yazımı yok (INSERT/UPDATE/DELETE)', !preg_match('/\b(INSERT|UPDATE|DELETE)\b/', (string)file_get_contents($ROOT . '/config/mail_beyan.php')));

$ix = (string)file_get_contents($ROOT . '/index.php');
ok('index.php Mail kartı: "24 saattir cevapsız N mail" — ACL kapsamlı sayaçtan, yalnız > 0 iken', str_contains($ix, 'mail_cevapsiz_eski_sayisi((int)$auth_user[\'id\'], 24)') && str_contains($ix, '24 saattir cevapsız <?= (int)$mail_cevapsiz24 ?> mail') && str_contains($ix, 'if ($mail_cevapsiz24 > 0)'));
ok('index.php rozet sayısı kenar çubuğuyla aynı kaynaktan (nav_mail_okunmamis)', str_contains($ix, '$mail_okunmamis = nav_mail_okunmamis();'));

mail_test_bitir();
