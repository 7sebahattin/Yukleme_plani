<?php
// =========================================================
// scripts/mail_ui_smoke.php — Mail Merkezi RENDER testi (M1)
// mail.php + mail_hesaplar.php'yi bellek içi SQLite ile GERÇEKTEN çalıştırır
// (beyan_ui_smoke.php deseni). Kapı tutarlılığı: sidebar / alt çubuk /
// first_allowed_page / sayfa kapısı AYNI can_mail() kararını verir.
//   php scripts/mail_ui_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
require_once __DIR__ . '/_mail_test_lib.php';
require_once $ROOT . '/config/mail_imap.php';
require_once $ROOT . '/config/mail_mime.php';
require_once $ROOT . '/config/mail_sync.php';
require_once $ROOT . '/config/mail_view.php';
require_once $ROOT . '/config/mail_translate.php';

$db = db();
mail_test_diger_tablolar($db);
mail_test_anahtar_kur();

function sayfa_render(string $dosya, array $get = [], array $post = []): string {
    global $ROOT;
    $src = (string)file_get_contents($ROOT . '/' . $dosya);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth|mail_core|mail_imap|mail_mime|mail_sync|mail_view|mail_translate)\.php';\s*$/m", '', $src);
    $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
    $tmp = sys_get_temp_dir() . '/mail_ui_' . getmypid() . '_' . basename($dosya);
    file_put_contents($tmp, $src);
    $_GET = $get; $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    $_SERVER['PHP_SELF'] = '/' . basename($dosya);
    ob_start();
    try { include $tmp; }
    catch (Throwable $e) { ob_end_clean(); return '__HATA__' . $e->getMessage(); }
    finally { @unlink($tmp); }
    return (string)ob_get_clean();
}

echo "=== 1. Tablolar yokken ===\n";
$IS_ADMIN = false; $PERMS = ['mail.read'];
$h = sayfa_render('mail.php');
ok('mail.php kurulu değil mesajı (admin değil: yöneticiye yönlendirir)', str_contains($h, 'henüz kurulmadı') && !str_contains($h, 'migrate.php">Şema'));
$IS_ADMIN = true;
$h = sayfa_render('mail.php');
ok('admin için migrate.php bağlantısı', str_contains($h, 'henüz kurulmadı') && str_contains($h, 'migrate.php'));
$h = sayfa_render('mail_hesaplar.php');
ok('mail_hesaplar tablo yokken kurulum uyarısı, form yok', str_contains($h, 'migrate.php') && !str_contains($h, 'name="imap_pass"'));

mail_test_sema_kur($db);

echo "\n=== 2. Kapı tutarlılığı (sidebar / alt çubuk / ilk sayfa / sayfa) ===\n";
$durumlar = [
    'yetkisiz'      => [[], false, false],
    'yalnız reply'  => [['mail.reply'], false, false],
    'mail.read'     => [['mail.read'], false, true],
    'mail.admin'    => [['mail.admin'], false, true],
    'is_admin'      => [[], true, true],
];
foreach ($durumlar as $ad => [$perms, $adm, $beklenen]) {
    $PERMS = $perms; $IS_ADMIN = $adm;
    $_SERVER['PHP_SELF'] = '/index.php';
    ob_start(); render_desktop_sidebar(''); $sb = ob_get_clean();
    $sidebarVar = str_contains($sb, 'href="mail.php"');
    $altVar = (nav_alt_izinler()['mail'] ?? null) === true;
    $sayfaAcik = true;
    try { $_SERVER['PHP_SELF'] = '/mail.php'; require_mail('read'); } catch (RuntimeException $e) { $sayfaAcik = false; }
    ok("[$ad] sidebar=alt çubuk=sayfa kapısı=" . ($beklenen ? 'AÇIK' : 'KAPALI'),
        $sidebarVar === $beklenen && $altVar === $beklenen && $sayfaAcik === $beklenen,
        "sidebar=$sidebarVar alt=$altVar sayfa=$sayfaAcik");
}
$PERMS = ['mail.read']; $IS_ADMIN = false;
ok('yalnız mail.read → first_allowed_page() = mail.php', first_allowed_page() === 'mail.php');
$PERMS = ['records.read', 'mail.read'];
ok('records.read varsa ilk sayfa değişmez (records.php)', first_allowed_page() === 'records.php');
$_SERVER['PHP_SELF'] = '/mail.php';
ok('nav_aktif_anahtar: mail.php → mail', nav_aktif_anahtar() === 'mail');
$_SERVER['PHP_SELF'] = '/mail_hesaplar.php';
ok('nav_aktif_anahtar: mail_hesaplar.php → mail', nav_aktif_anahtar() === 'mail');
ok('nav_alt_sayfalar kaydında mail var, ikon dosyası mevcut',
    isset(nav_alt_sayfalar()['mail']) && is_file($ROOT . '/' . nav_alt_sayfalar()['mail']['ikon']));
ok('sidebar ve alt çubuk aynı hedef (mail.php)', nav_alt_sayfalar()['mail']['href'] === 'mail.php');
$kat = array_keys(array_merge(...array_values(mail_test_katalog())));
ok('permission_catalog 4 mail yetkisini taşıyor', count(array_intersect($kat, ['mail.read','mail.reply','mail.send','mail.admin'])) === 4);
$PERMS = []; $IS_ADMIN = false;
$yetkisizHata = false;
try { require_mail('read'); } catch (RuntimeException $e) { $yetkisizHata = str_contains($e->getMessage(), 'mail.read'); }
ok('yetkisiz kullanıcı mail ekranına giremiyor (403)', $yetkisizHata);

echo "\n=== 3. mail.php içerik ===\n";
$IS_ADMIN = true; $PERMS = []; $UID = 9;
$h = sayfa_render('mail.php');
ok('hesap yokken yönetici "Hesap Ekle" görür', str_contains($h, 'Hesap Ekle'));
$IS_ADMIN = false; $PERMS = ['mail.read']; $UID = 2;
$h = sayfa_render('mail.php');
ok('yönetici olmayan, atama yoksa "atanmış hesap yok"', str_contains($h, 'atanmış bir mail hesabı yok') && !str_contains($h, 'Hesap Ekle'));

// Hesapları yönetici ekler (gerçek sayfa akışı, gerçek CSRF)
$IS_ADMIN = true; $PERMS = []; $UID = 9;
$sifreI = 'ImapGizli-7731'; $sifreS = 'SmtpGizli-9942';
$tok = (function () { ob_start(); $t = csrf_token(); ob_end_clean(); return $t; })();
$post = ['csrf' => $tok, 'islem' => 'kaydet', 'kullanicilar_gonderildi' => '1', 'kullanicilar' => ['2'],
    'label' => '<script>alert(1)</script>Asya', 'email' => 'info@asya.com',
    'imap_host' => 'imap.asya.com', 'imap_port' => '993', 'imap_security' => 'ssl', 'imap_user' => 'info@asya.com', 'imap_pass' => $sifreI,
    'smtp_host' => 'smtp.asya.com', 'smtp_port' => '465', 'smtp_security' => 'ssl', 'smtp_user' => 'info@asya.com', 'smtp_pass' => $sifreS,
    'sync_folder' => 'INBOX', 'initial_days' => '30', 'target_lang' => 'tr'];
$h = sayfa_render('mail_hesaplar.php', [], $post);
ok('hesap ekleme başarılı', str_contains($h, 'Hesap eklendi.'), substr(strip_tags($h), 0, 200));
ok('yanıt HTML\'inde HİÇBİR şifre yok', !str_contains($h, $sifreI) && !str_contains($h, $sifreS));
ok('etiketteki <script> kaçırılmış', !str_contains($h, '<script>alert(1)') && str_contains($h, '&lt;script&gt;'));
ok('DB\'de şifre şifreli', !str_contains(json_encode($db->query('SELECT * FROM mail_accounts')->fetchAll()), 'Gizli'));
$au = $db->query("SELECT action, new_values FROM audit_log WHERE module='mail_accounts'")->fetchAll();
ok('audit: mail_account_create yazıldı', count($au) === 1 && $au[0]['action'] === 'mail_account_create');
ok('audit: şifre/değer YOK, yalnız alan adları', !str_contains(json_encode($au), 'Gizli') && str_contains($au[0]['new_values'], 'sifre_degisti'));
ok('kullanıcı ataması kaydedildi', mail_hesap_kullanicilari(1, $db) === [2]);

$h = sayfa_render('mail_hesaplar.php', ['duzenle' => '1']);
ok('düzenleme formu "kayıtlı" ipucu gösteriyor, şifre değeri YOK',
    substr_count($h, 'kayıtlı — değiştirmek için yazın') === 2 && !str_contains($h, $sifreI) && !str_contains($h, $sifreS)
    && preg_match('/name="imap_pass"[^>]*value=""/', $h) === 1);

$bozuk = $post; $bozuk['imap_host'] = 'http://evil'; $bozuk['label'] = 'Korunan Etiket'; $bozuk['email'] = 'x@asya.com';
$h = sayfa_render('mail_hesaplar.php', [], $bozuk);
ok('doğrulama hatası gösteriliyor, hesap eklenmedi', str_contains($h, 'sunucu adı geçersiz') && (int)$db->query('SELECT COUNT(*) FROM mail_accounts')->fetchColumn() === 1);
ok('hata sonrası formda yazılanlar korunuyor ama ŞİFRE geri basılmıyor', str_contains($h, 'Korunan Etiket') && !str_contains($h, $sifreI) && !str_contains($h, $sifreS));

// Yetkisiz kullanıcı hesap yönetemez
$IS_ADMIN = false; $PERMS = ['mail.read', 'mail.reply', 'mail.send']; $UID = 2;
$ret = sayfa_render('mail_hesaplar.php');
ok('mail.read/reply/send sahibi hesap yönetimine giremiyor', str_contains($ret, '__HATA__') && str_contains($ret, 'mail.admin'));

// ACL: user 2 atanmış hesabı görür, başka kullanıcı görmez
$h = sayfa_render('mail.php');
ok('atanan kullanıcı hesabını görüyor (etiket kaçırılmış)', str_contains($h, 'info@asya.com') && str_contains($h, '&lt;script&gt;') && !str_contains($h, '<script>alert'));
$UID = 3;
$h = sayfa_render('mail.php');
ok('atanmamış kullanıcı hesabı GÖRMÜYOR (IDOR)', !str_contains($h, 'info@asya.com') && str_contains($h, 'atanmış bir mail hesabı yok'));

echo "\n=== 4. Gelen kutusu (M3): GET yan etkisiz, ACL, POST işlemleri ===\n";
mail_test_anahtar_kur();
$IS_ADMIN = false; $PERMS = ['mail.read', 'mail.reply']; $UID = 2;
$db->exec("UPDATE mail_accounts SET is_active = 1");
mail_hesap_kullanici_ata(1, [2], $db);
$yeni = mail_hesap_kaydet(['label' => 'Gizli Hesap', 'email' => 'gizli@asya.com', 'imap_host' => 'i.t.com', 'imap_user' => 'g', 'imap_pass' => 'p-g-1', 'smtp_host' => 's.t.com', 'smtp_user' => 'g', 'smtp_pass' => 'p-g-2'], null, 1, $db);
$gizliHesap = $yeni['id'];
$ins = $db->prepare("INSERT INTO mail_messages (account_id, folder, uidvalidity, uid, message_id_hash, subject, from_name, from_addr, received_at, body_text, is_read, to_addrs, cc_addrs, body_html_safe)
    VALUES (?, 'INBOX', 1, ?, ?, ?, ?, 'x@y.com', '2026-10-05 10:00:00', ?, 0, '[]', '[]', ?)");
$ins->execute([1, 1, sha1('m1'), '<b>Kalın</b> konu & "tırnak"', '<i>Gönderen</i>', 'Merhaba gövde', null]);
$mid = (int)$db->lastInsertId();
$ins->execute([$gizliHesap, 2, sha1('m2'), 'GİZLİ HESAP MAİLİ', 'Başkası', 'gizli', null]);
$gizliMid = (int)$db->lastInsertId();
$ins->execute([1, 3, sha1('m3'), 'HTML mail', 'H', 'h', mail_html_sanitize('<p>güvenli</p><img src="https://t.example/p.gif">')]);
$htmlMid = (int)$db->lastInsertId();

$h = sayfa_render('mail.php');
ok('liste: görünür hesabın maili var, konu KAÇIRILMIŞ', str_contains($h, '&lt;b&gt;Kalın&lt;/b&gt; konu &amp; &quot;tırnak&quot;') && !str_contains($h, '<b>Kalın</b>'));
ok('liste: başka hesabın maili GÖRÜNMÜYOR', !str_contains($h, 'GİZLİ HESAP MAİLİ') && !str_contains($h, 'Gizli Hesap'));
$h = sayfa_render('mail.php', ['a' => (string)$gizliHesap]);
ok('?a=<yabancı hesap> sessizce yok sayılır (kendi hesaplarına döner)', !str_contains($h, 'GİZLİ HESAP MAİLİ') && str_contains($h, 'Kalın'));
$h = sayfa_render('mail.php', ['m' => (string)$gizliMid]);
ok('?m=<yabancı mesaj> (IDOR): içerik YOK, boş okuyucu', !str_contains($h, 'GİZLİ HESAP MAİLİ') && !str_contains($h, 'gizli</') && str_contains($h, 'Okumak için soldan'));
$once = (int)$db->query("SELECT is_read FROM mail_messages WHERE id = $mid")->fetchColumn();
$h = sayfa_render('mail.php', ['m' => (string)$mid]);
ok('mesaj açılır, gövde görünür', str_contains($h, 'Merhaba gövde') && str_contains($h, 'mail--detay'));
ok('GET mesajı OKUNDU İŞARETLEMEZ (yan etki yok; okundu işareti POST)', (int)$db->query("SELECT is_read FROM mail_messages WHERE id = $mid")->fetchColumn() === $once && $once === 0);
ok('okunmamış mesajda JS işaret kancası var', str_contains($h, 'data-okundu-gonder="' . $mid . '"'));
ok('Cevapla düğmesi PASİF (cevap yazma M5)', (bool)preg_match('/<button[^>]*mail-cevapla[^>]*disabled/', $h));
$h = sayfa_render('mail.php', ['m' => (string)$htmlMid, 'v' => 'orj']);
ok('HTML mail sandbox iframe + srcdoc, satır içi zararlı yok', str_contains($h, 'sandbox="allow-popups allow-popups-to-escape-sandbox"') && str_contains($h, 'srcdoc="') && str_contains($h, 'Uzak görseller engellendi'));
ok('iframe sandbox allow-scripts / allow-same-origin İÇERMİYOR', !preg_match('/sandbox="[^"]*allow-(scripts|same-origin|forms|top-navigation)/', $h));
$h = sayfa_render('mail.php', ['f' => 'taslak']);
ok('outbox filtresi çalışır (boş klasör mesajı)', str_contains($h, 'Bu klasörde kayıt yok'));
$h = sayfa_render('mail.php', ['f' => "okunmamis' OR 1=1 --"]);
ok('geçersiz filtre → gelen (enjeksiyon etkisiz)', str_contains($h, 'Kalın'));
$h = sayfa_render('mail.php', ['m' => (string)$mid, 'v' => 'tr']);
ok('çeviri sağlayıcısı KAPALIYKEN "Şimdi çevir" düğmesi YOK (veri çıkış yolu görünmez)', !str_contains($h, 'ceviri_simdi') && str_contains($h, 'Çeviri bekleniyor'));
$IS_ADMIN = true; $PERMS = [];
ok('hesap ekranı sağlayıcıyı KAPALI gösteriyor', str_contains(sayfa_render('mail_hesaplar.php'), 'KAPALI — mail içeriği hiçbir dış servise gönderilmiyor'));
define('MAIL_TRANSLATE_PROVIDER', 'mymemory');   // bu noktadan sonra sağlayıcı açık (sabit süreç boyunca kalır)
$h = sayfa_render('mail.php', ['m' => (string)$mid, 'v' => 'tr']);
ok('sağlayıcı AÇIKKEN "Şimdi çevir" POST formu (CSRF + islem=ceviri_simdi) görünür', str_contains($h, 'value="ceviri_simdi"') && str_contains($h, 'Mail metni yapılandırılmış çeviri servisine gönderilir'));
ok('hesap ekranı sağlayıcıyı AÇIK gösteriyor', str_contains(sayfa_render('mail_hesaplar.php'), 'AÇIK — sağlayıcı: mymemory'));
$IS_ADMIN = false; $PERMS = ['mail.read', 'mail.reply'];
$IS_ADMIN = false; $PERMS = ['mail.read'];
$h = sayfa_render('mail.php', ['m' => (string)$mid]);
ok('mail.reply yetkisi yoksa durum düğmesi + yetki notu', str_contains($h, 'mail.reply yetkisi gerekir') && !str_contains($h, 'Cevaplandı say'));
$PERMS = ['mail.read', 'mail.reply']; $hesapIds = [1];
$r = mail_post_isle($db, 2, 'oku', $mid, 0, $hesapIds, false, true);
ok('POST oku: işaretlendi', $r['ok'] && (int)$db->query("SELECT is_read FROM mail_messages WHERE id = $mid")->fetchColumn() === 1);
$r = mail_post_isle($db, 2, 'oku', $gizliMid, 0, $hesapIds, false, true);
ok('POST oku: yabancı hesabın mesajı İŞARETLENEMEZ', !$r['ok'] && (int)$db->query("SELECT is_read FROM mail_messages WHERE id = $gizliMid")->fetchColumn() === 0);
$r = mail_post_isle($db, 2, 'cevaplandi', $mid, 0, $hesapIds, false, false);
ok('POST cevaplandi: mail.reply yoksa YASAK', $r['yasak'] !== null && str_contains($r['yasak'], 'mail.reply'));
$r = mail_post_isle($db, 2, 'cevaplandi', $mid, 0, $hesapIds, false, true);
ok('POST cevaplandi: reply yetkisiyle çalışır', $r['ok'] && $db->query("SELECT replied_at FROM mail_messages WHERE id = $mid")->fetchColumn() !== null);
$r = mail_post_isle($db, 2, 'senkron', 0, 1, $hesapIds, false, true);
ok('POST senkron: yönetici değilse YASAK', $r['yasak'] !== null && str_contains($r['yasak'], 'mail.admin'));
$r = mail_post_isle($db, 9, 'senkron', 0, $gizliHesap, $hesapIds, true, true);
ok('POST senkron: görünür olmayan hesap seçilemez', !$r['ok'] && $r['yasak'] === null);
$r = mail_post_isle($db, 2, 'bilinmeyen', $mid, 0, $hesapIds, true, true);
ok('bilinmeyen işlem etkisiz', !$r['ok'] && $r['yasak'] === null);
$src = file_get_contents($ROOT . '/mail.php');
ok('mail.php: POST\'ta csrf_check, yasak → forbidden(), GET yan etkisiz', str_contains($src, "csrf_check(\$_POST['csrf'] ?? null);") && str_contains($src, "forbidden(\$sn['yasak'])") && !preg_match('/\$_GET\[[^\]]*\][^;]*(UPDATE|INSERT|DELETE)/i', $src));
ok('mail.php: çift container YOK (render_header zaten <main class="container"> açar)', !str_contains($src, '<div class="container') );
ok('mail_hesaplar.php: çift container YOK', !str_contains(file_get_contents($ROOT . '/mail_hesaplar.php'), '<div class="container'));

// Anahtar yokken yönetici uyarısı
putenv('MAIL_MASTER_KEY');
$IS_ADMIN = true; $PERMS = []; $UID = 9;
$h = sayfa_render('mail.php');
ok('anahtar yoksa yönetici uyarı görüyor', str_contains($h, 'Şifreleme anahtarı tanımlı değil'));
$hesapOnce = (int)$db->query('SELECT COUNT(*) FROM mail_accounts')->fetchColumn();
$h = sayfa_render('mail_hesaplar.php', [], array_merge($post, ['email' => 'yeni@asya.com']));
ok('anahtar yokken hesap kaydı reddediliyor', str_contains($h, 'MAIL_MASTER_KEY') && (int)$db->query('SELECT COUNT(*) FROM mail_accounts')->fetchColumn() === $hesapOnce);

mail_test_bitir();
