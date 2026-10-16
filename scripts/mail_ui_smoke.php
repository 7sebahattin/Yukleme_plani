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
require_once $ROOT . '/config/mail_smtp.php';
require_once $ROOT . '/config/mail_outbox.php';

$db = db();
mail_test_diger_tablolar($db);
mail_test_anahtar_kur();
// Hesap ekranındaki "mail.read yok" rozeti için: kullanıcı 2 mail.read'e sahip, 3 hiçbir izne (admin rolü user_roles/roles ile 6. bölümde kurulur).
function user_permissions(int $uid): array { return $uid === 2 ? ['mail.read'] : []; }

function sayfa_render(string $dosya, array $get = [], array $post = []): string {
    global $ROOT;
    $src = (string)file_get_contents($ROOT . '/' . $dosya);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth|mail_core|mail_imap|mail_mime|mail_sync|mail_view|mail_translate|mail_smtp|mail_outbox)\.php';\s*$/m", '', $src);
    $src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
    $tmp = sys_get_temp_dir() . '/mail_ui_' . getmypid() . '_' . basename($dosya);
    file_put_contents($tmp, $src);
    $_GET = $get; $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    $_SERVER['PHP_SELF'] = '/' . basename($dosya);
    ob_start();
    try { include $tmp; }
    catch (MailTestYonlendirme $e) { ob_end_clean(); return '__YONLENDIR__' . $e->url; }   // PRG: sayfa Location'a gitmek istedi (flash oturumda)
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
ok('hesap ekleme: PRG — başarıdan sonra yönlendirme (yenileme POST\'u tekrarlamaz)', $h === '__YONLENDIR__mail_hesaplar.php', substr($h, 0, 200));
$h = sayfa_render('mail_hesaplar.php');   // yönlendirmeden sonraki GET: flash gösterilir
ok('hesap ekleme başarılı (flash)', str_contains($h, 'Hesap eklendi.'), substr(strip_tags($h), 0, 200));
$h2 = sayfa_render('mail_hesaplar.php');
ok('flash TEK seferlik (ikinci GET\'te yok)', !str_contains($h2, 'Hesap eklendi.'));
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
ok('Cevapla artık pasif DEĞİL (M5): reply yetkili kullanıcıda bağlantı', !preg_match('/<button[^>]*mail-cevapla[^>]*disabled/', $h) && str_contains($h, 'mail-cevapla'));
$h = sayfa_render('mail.php', ['m' => (string)$htmlMid, 'v' => 'orj']);
ok('HTML mail sandbox iframe + srcdoc, satır içi zararlı yok', str_contains($h, 'sandbox="allow-popups allow-popups-to-escape-sandbox"') && str_contains($h, 'srcdoc="') && str_contains($h, 'Uzak görseller engellendi'));
ok('iframe sandbox allow-scripts / allow-same-origin İÇERMİYOR', !preg_match('/sandbox="[^"]*allow-(scripts|same-origin|forms|top-navigation)/', $h));
$h = sayfa_render('mail.php', ['f' => 'taslak']);
ok('outbox filtresi çalışır (boş klasör mesajı)', str_contains($h, 'Bu klasörde kayıt yok'));
$h = sayfa_render('mail.php', ['f' => "okunmamis' OR 1=1 --"]);
ok('geçersiz filtre → gelen (enjeksiyon etkisiz)', str_contains($h, 'Kalın'));
$h = sayfa_render('mail.php', ['m' => (string)$mid, 'v' => 'tr']);
ok('çeviri sağlayıcısı KAPALIYKEN "Şimdi çevir" düğmesi YOK (veri çıkış yolu görünmez)', !str_contains($h, 'ceviri_simdi') && str_contains($h, 'Çevrilmedi (çeviri yalnız istek üzerine yapılır)'));
$IS_ADMIN = true; $PERMS = [];
ok('hesap ekranı sağlayıcıyı KAPALI gösteriyor', str_contains(sayfa_render('mail_hesaplar.php'), 'KAPALI — mail içeriği hiçbir dış servise gönderilmiyor'));
$hh = sayfa_render('mail_hesaplar.php');
ok('M8: kurulu sistemde senkron durumu/günlüğü paneli GÖRÜNÜR (hesap formuyla birlikte)', str_contains($hh, 'id="mail-sync-gunluk"') && str_contains($hh, 'name="islem" value="kaydet"'));
define('MAIL_TRANSLATE_PROVIDER', 'mymemory');   // bu noktadan sonra sağlayıcı açık (sabit süreç boyunca kalır)
$h = sayfa_render('mail.php', ['m' => (string)$mid, 'v' => 'tr']);
ok('sağlayıcı açık ama HESAPTA çeviri kapalıyken "Şimdi çevir" düğmesi YOK (M8 bulgu 2)', !str_contains($h, 'value="ceviri_simdi"'));
$db->exec('UPDATE mail_accounts SET translate_enabled = 1');
$h = sayfa_render('mail.php', ['m' => (string)$mid, 'v' => 'tr']);
ok('sağlayıcı AÇIKKEN "Türkçeye çevir" POST formu (CSRF + islem=ceviri_simdi) görünür', str_contains($h, 'value="ceviri_simdi"') && str_contains($h, 'Mail metni yapılandırılmış çeviri servisine gönderilir') && str_contains($h, 'Türkçeye çevir'));
$h = sayfa_render('mail.php', ['m' => (string)$mid, 'v' => 'orj']);
ok('v369: Orijinal sekmesinde de araç çubuğunda TEK "Türkçeye çevir" düğmesi (çeviri yalnız tıklamayla)', substr_count($h, 'value="ceviri_simdi"') === 1 && str_contains($h, 'Türkçeye çevir'));
$h = sayfa_render('mail.php', []);
ok('v369: otomatik çeviri kapalıyken listede "çeviri bekliyor" rozeti YOK', !str_contains($h, 'çeviri bekliyor'));
ok('hesap ekranı sağlayıcıyı AÇIK gösteriyor', str_contains(sayfa_render('mail_hesaplar.php'), 'AÇIK — sağlayıcı: mymemory'));
$IS_ADMIN = false; $PERMS = ['mail.read', 'mail.reply'];
$IS_ADMIN = false; $PERMS = ['mail.read'];
$h = sayfa_render('mail.php', ['m' => (string)$mid]);
ok('mail.reply yetkisi yoksa durum düğmesi + yetki notu', str_contains($h, 'mail.reply yetkisi gerekir') && !str_contains($h, 'Cevaplandı say'));
$PERMS = ['mail.read', 'mail.reply']; $hesapIds = [1];
$ctx = fn(array $ek = []) => $ek + ['uid' => 2, 'hesapIds' => $hesapIds, 'yonetici' => false, 'cevap' => true, 'send' => false, 'a' => 0, 'm' => $mid, 'o' => 0];
$r = mail_post_isle($db, $ctx(), 'oku');
ok('POST oku: işaretlendi', $r['ok'] && (int)$db->query("SELECT is_read FROM mail_messages WHERE id = $mid")->fetchColumn() === 1);
$r = mail_post_isle($db, $ctx(['m' => $gizliMid]), 'oku');
ok('POST oku: yabancı hesabın mesajı İŞARETLENEMEZ', !$r['ok'] && (int)$db->query("SELECT is_read FROM mail_messages WHERE id = $gizliMid")->fetchColumn() === 0);
$r = mail_post_isle($db, $ctx(['cevap' => false]), 'cevaplandi');
ok('POST cevaplandi: mail.reply yoksa YASAK', $r['yasak'] !== null && str_contains($r['yasak'], 'mail.reply'));
$r = mail_post_isle($db, $ctx(), 'cevaplandi');
ok('POST cevaplandi: reply yetkisiyle çalışır', $r['ok'] && $db->query("SELECT replied_at FROM mail_messages WHERE id = $mid")->fetchColumn() !== null);
$r = mail_post_isle($db, $ctx(['a' => 1]), 'senkron');
ok('POST senkron: yönetici değilse YASAK', $r['yasak'] !== null && str_contains($r['yasak'], 'mail.admin'));
$r = mail_post_isle($db, $ctx(['uid' => 9, 'yonetici' => true, 'a' => $gizliHesap]), 'senkron');
ok('POST senkron: görünür olmayan hesap seçilemez', !$r['ok'] && $r['yasak'] === null);
$r = mail_post_isle($db, $ctx(['yonetici' => true]), 'bilinmeyen');
ok('bilinmeyen işlem etkisiz', !$r['ok'] && $r['yasak'] === null);
$src = file_get_contents($ROOT . '/mail.php');
ok('mail.php: POST\'ta csrf_check, yasak → forbidden(), GET yan etkisiz', str_contains($src, "csrf_check(\$_POST['csrf'] ?? null);") && str_contains($src, "forbidden(\$sn['yasak'])") && !preg_match('/\$_GET\[[^\]]*\][^;]*(UPDATE|INSERT|DELETE)/i', $src));
ok('mail.php: çift container YOK (render_header zaten <main class="container"> açar)', !str_contains($src, '<div class="container') );
ok('mail_hesaplar.php: çift container YOK', !str_contains(file_get_contents($ROOT . '/mail_hesaplar.php'), '<div class="container'));

echo "\n=== 5. Cevap yazma + onay ekranı (M5) ===\n";
$IS_ADMIN = false; $PERMS = ['mail.read', 'mail.reply', 'mail.send']; $UID = 2;
$h = sayfa_render('mail.php', ['m' => (string)$mid]);
ok('Cevapla artık BAĞLANTI (cevap=1) — yetkili kullanıcıda', (bool)preg_match('/<a class="btn btn-primary mail-cevapla" href="mail\.php\?[^"]*cevap=1/', $h));
$h = sayfa_render('mail.php', ['m' => (string)$mid, 'cevap' => '1']);
$hOkuma = sayfa_render('mail.php', ['m' => (string)$mid, 'v' => 'tr']);
$ins->execute([1, 9, sha1('m9'), 'Zapros prays-lista', 'Ivan', 'Privet', null]);
$trId = (int)$db->lastInsertId();
$db->exec("UPDATE mail_messages SET lang = 'ru', tr_status = 'translated', subject_tr = 'Fiyat listesi talebi', body_tr = 'Merhaba' WHERE id = $trId");
$hCev = sayfa_render('mail.php', ['m' => (string)$trId, 'v' => 'tr']);
ok('çevrilmiş mail etiketi kaynak dili gösterir: "Rusça → Türkçe"', str_contains($hCev, '>Rusça → Türkçe<'));
ok('çevrilmiş mail: "<dil> → Türkçe" etiketi var, "üçüncü taraf/ile üretildi" yazısı yok', (bool)preg_match('#<div class="mail-ceviri-not">[^<]+ → Türkçe</div>#u', $hCev) && !str_contains($hCev, 'ile üretildi') && !str_contains($hCev, 'üçüncü taraf'));
ok('okuma ekranında "Çevirirseniz … ÜÇÜNCÜ TARAF" uyarısı YOK; "Şimdi çevir" düğmesi kalır (kullanıcı kararı)', !str_contains($hOkuma, 'Çevirirseniz') && !str_contains($hOkuma, 'ÜÇÜNCÜ TARAF') && str_contains($hOkuma, 'value="ceviri_simdi"'));
ok('cevap formunda gereksiz "üçüncü taraf servise gönderilir" uyarısı YOK (sağlayıcı açıkken de; kullanıcı kararı)', !str_contains($h, 'üçüncü taraf servise çeviri için') && !str_contains($h, 'Çeviriyi Önizle dediğinizde'));
ok('cevap yazma formu: Türkçe metin, hedef dil, tek kullanımlık anahtar, CSRF, "onaylamadan gönderilmez" uyarısı', str_contains($h, 'name="body_tr"') && str_contains($h, 'name="target_lang"') && (bool)preg_match('/name="idem" value="[0-9a-f]{32}"/', $h) && str_contains($h, 'name="csrf"') && str_contains($h, 'onaylamadan hiçbir şey gönderilmez') && str_contains($h, 'value="cevap_onizle"'));
ok('form tek-gönderim korumalı (JS kolaylığı) + 20000 sınırı', str_contains($h, 'data-tek-gonderim') && str_contains($h, 'maxlength="' . MAIL_CEVAP_MAX . '"'));
$IS_ADMIN = false; $PERMS = ['mail.read'];
$h = sayfa_render('mail.php', ['m' => (string)$mid, 'cevap' => '1']);
ok('mail.reply yoksa cevap formu AÇILMAZ (cevap=1 yok sayılır)', !str_contains($h, 'name="body_tr"') && str_contains($h, 'mail.reply yetkisi gerekir'));
$IS_ADMIN = false; $PERMS = ['mail.read', 'mail.reply', 'mail.send']; $UID = 2;
$sagU = new class implements MailTranslationProviderInterface { public function ad(): string { return 'sahte'; } public function parcaLimiti(): int { return 4000; } public function cevir(string $m, ?string $k, string $h): array { return ['metin' => 'EN: ' . $m, 'tespit' => 'tr']; } };
$gOut = mail_post_isle($db, ['uid' => 2, 'hesapIds' => [1], 'yonetici' => false, 'cevap' => true, 'send' => true, 'a' => 0, 'm' => $mid, 'o' => 0], 'cevap_onizle',
    ['idem' => bin2hex(random_bytes(16)), 'body_tr' => 'Merhaba <script>alert(1)</script> & "tırnak"', 'target_lang' => 'en', 'quote' => '1'], ['sagl' => $sagU]);
$oid = $gOut['o'];
$h = sayfa_render('mail.php', ['o' => (string)$oid]);
ok('onay ekranı: iki kutu — TÜRKÇE ORİJİNAL CEVAP + GÖNDERİLECEK ÇEVİRİ', str_contains($h, 'TÜRKÇE ORİJİNAL CEVAP') && str_contains($h, 'GÖNDERİLECEK ÇEVİRİ (İngilizce)'));
ok('onay ekranı: metinler KAÇIRILMIŞ (script/tırnak)', !str_contains($h, '<script>alert(1)</script>') && str_contains($h, '&lt;script&gt;alert(1)&lt;/script&gt;') && str_contains($h, '&amp; &quot;tırnak&quot;'));
ok('send yetkisi: "✅ Onayla ve Gönder" + gizli hash = saklı hash + alıcı bilgisi', str_contains($h, '✅ Onayla ve Gönder') && str_contains($h, 'name="hash" value="' . $db->query("SELECT content_hash FROM mail_outbox WHERE id = $oid")->fetchColumn() . '"') && str_contains($h, 'geri alınamaz'));
ok('alıntı bilgisi + üçüncü taraf çeviri notu', str_contains($h, 'Altına eklenecek alıntı') && str_contains($h, 'sahte (üçüncü taraf servis)'));
ok('düzenleme formu: Türkçe + çeviri düzeltme + iki ayrı düğme (yeniden üret / elle kullan)', str_contains($h, 'name="body_out_manual"') && str_contains($h, 'name="mod" value="ceviri"') && str_contains($h, 'name="mod" value="manuel"'));
$IS_ADMIN = false; $PERMS = ['mail.read', 'mail.reply'];
$h = sayfa_render('mail.php', ['o' => (string)$oid]);
ok('mail.send YOKSA onay düğmesi YOK, bekleme açıklaması VAR', !str_contains($h, 'Onayla ve Gönder') && str_contains($h, 'mail.send</code> yetkisi gerekir') && str_contains($h, 'Taslak / Bekleyen'));
$h = sayfa_render('mail.php', ['f' => 'taslak']);
ok('Taslak/Bekleyen klasörü: giden kayıt bağlantı olarak listelenir', (bool)preg_match('/<a class="mail-oge mail-oge--giden[^"]*" href="mail\.php\?[^"]*o=' . $oid . '/', $h));
$PERMS = ['mail.read', 'mail.reply', 'mail.send'];
foreach (['unknown' => ['Belirsiz durum', 'Gönderildi (doğruladım)', 'cevap_belirsiz_gonderilmedi'], 'failed' => ['Tekrar dene', 'cevap_tekrar', 'kabul EDİLMEDİ'], 'sent' => ['Gönderildi', null, null], 'cancelled' => ['İptal edildi', null, null], 'sending' => ['Gönderiliyor', null, null]] as $durum => $beklenen) {
    $db->exec("UPDATE mail_outbox SET status = '$durum', out_message_id = '<x@asya.com>', last_error = 'örnek hata' WHERE id = $oid");
    if ($durum === 'sending') $db->exec("UPDATE mail_outbox SET send_started_at = '" . date('Y-m-d H:i:s') . "' WHERE id = $oid");
    $h = sayfa_render('mail.php', ['o' => (string)$oid]);
    $hepsi = true; foreach ($beklenen as $b) if ($b !== null && !str_contains($h, $b)) $hepsi = false;
    ok("durum $durum: beklenen eylemler görünür", $hepsi);
    ok("durum $durum: 'Onayla ve Gönder' ASLA görünmez", !str_contains($h, '✅ Onayla ve Gönder'));
}
$db->exec("UPDATE mail_outbox SET status = 'unknown' WHERE id = $oid");
$h = sayfa_render('mail.php', ['o' => (string)$oid]);
ok('unknown: "OTOMATİK TEKRAR GÖNDERMEZ" uyarısı + Message-ID tanı için görünür', str_contains($h, 'OTOMATİK TEKRAR GÖNDERMEZ') && str_contains($h, '&lt;x@asya.com&gt;'));
$yabanci = mail_hesap_kaydet(['label' => 'Yabancı', 'email' => 'yabanci@asya.com', 'imap_host' => 'i.t.com', 'imap_user' => 'g', 'imap_pass' => 'p-g-1', 'smtp_host' => 's.t.com', 'smtp_user' => 'g', 'smtp_pass' => 'p-g-2'], null, 1, $db)['id'];
$db->prepare("INSERT INTO mail_outbox (account_id, idempotency_key, status, to_addr, subject, body_tr, body_out, created_at) VALUES (?, ?, 'translated', 'z@z.com', 'YABANCI HESAP CEVABI', 'gizli', 'gizli', '2026-10-06 10:00:00')")->execute([$yabanci, bin2hex(random_bytes(16))]);
$yo = (int)$db->lastInsertId();
$h = sayfa_render('mail.php', ['o' => (string)$yo]);
ok('?o=<yabancı hesabın cevabı> (IDOR): içerik YOK', !str_contains($h, 'YABANCI HESAP CEVABI') && !str_contains($h, 'gizli</div>') && str_contains($h, 'Okumak için soldan'));
$h = sayfa_render('mail.php', ['f' => 'taslak']);
ok('yabancı hesabın taslağı listede GÖRÜNMEZ', !str_contains($h, 'YABANCI HESAP CEVABI') && !str_contains($h, 'z@z.com'));

echo "\n=== 6. Dalga 2: bağlantılar, PRG, cevap kurtarma, toplu işlem, sayfalama, erişilebilirlik, audit ===\n";
$tok = (function () { ob_start(); $t = csrf_token(); ob_end_clean(); return $t; })();
$href = static function (string $h, string $desen): array { preg_match_all($desen, $h, $m); return $m[1] ?? []; };
$IS_ADMIN = false; $PERMS = ['mail.read', 'mail.reply', 'mail.send']; $UID = 2;

// — 1. `o` (giden kaydı) bağlantılara YAPIŞMAZ: başka maile/klasöre/sayfaya geçmek paneli temizler —
$db->exec("UPDATE mail_outbox SET status = 'translated' WHERE id = $oid");
$h = sayfa_render('mail.php', ['m' => (string)$mid, 'o' => (string)$oid]);
ok('[o] onay paneli açık (oPanel önceliği)', str_contains($h, 'TÜRKÇE ORİJİNAL CEVAP'));
$klasor = $href($h, '/<a class="mail-chip[^"]*" data-filtre="[^"]*" href="([^"]*)"/');
$mailOge = $href($h, '/<a class="mail-oge[^"]*"[^>]*?href="([^"]*)"/s');
ok('[o] klasör çipleri o= TAŞIMAZ', count($klasor) === 6 && !array_filter($klasor, fn($u) => str_contains($u, 'o=')), json_encode($klasor));
ok('[o] gelen listesindeki mail bağlantıları o= TAŞIMAZ (başka maile geçilebilir)', count($mailOge) > 0 && !array_filter($mailOge, fn($u) => str_contains($u, 'o=')), json_encode($mailOge));
$h = sayfa_render('mail.php', ['o' => (string)$oid, 'f' => 'taslak']);
$outOge = $href($h, '/<a class="mail-oge mail-oge--giden[^"]*"[^>]*?href="([^"]*)"/s');
ok('[o] giden kaydı satırı KENDİ o= değerini taşır (panel açılır)', (bool)array_filter($outOge, fn($u) => str_contains($u, 'o=' . $oid)));
$sayfaBag = $href($h, '/<a class="mail-chip[^"]*" data-filtre="[^"]*" href="([^"]*)"/');
ok('[o] taslak klasöründe de klasör çipleri o= taşımaz', !array_filter($sayfaBag, fn($u) => str_contains($u, 'o=')));
// PRG: önizleme sonrası yönlendirme yalnız o'yu taşır, m'yi DEĞİL (eskiden ?m=X&o=Y)
$prg = sayfa_render('mail.php', [], ['csrf' => $tok, 'islem' => 'cevap_onizle', 'm' => (string)$mid, 'a' => '', 'f' => 'gelen', 'idem' => bin2hex(random_bytes(16)),
    'body_tr' => 'Teşekkürler, siparişiniz yolda.', 'target_lang' => 'tr', 'quote' => '1']);
ok('[o] önizleme sonrası PRG: ?o=<yeni> (m= YOK)', (bool)preg_match('#^__YONLENDIR__mail\.php\?o=\d+$#', $prg), $prg);

// — 3. Cevapla ön koşulları + metin kurtarma —
$IS_ADMIN = true; $PERMS = []; $UID = 9;
$db->exec('UPDATE mail_accounts SET is_active = 0 WHERE id = 1');
$h = sayfa_render('mail.php', ['m' => (string)$mid]);
ok('[cevap] pasif hesapta "Cevapla" bağlantısı YOK, neden yazılı', !str_contains($h, 'mail-cevapla') && str_contains($h, 'Hesap pasif olduğu için cevap yazılamaz') && !str_contains($h, 'class="mail-cevapbar"'));
$h = sayfa_render('mail.php', ['m' => (string)$mid, 'cevap' => '1']);
ok('[cevap] pasif hesapta cevap=1 formu AÇILMAZ, neden gösterilir', !str_contains($h, 'name="body_tr"') && str_contains($h, 'Cevap yazılamıyor'));
$yaz = ['csrf' => $tok, 'islem' => 'cevap_onizle', 'm' => (string)$mid, 'a' => '', 'f' => 'gelen', 'idem' => bin2hex(random_bytes(16)),
    'body_tr' => "Önemli cevap <b>metni</b>\nikinci satır", 'target_lang' => 'en', 'quote' => '1'];
$prg = sayfa_render('mail.php', [], $yaz);
ok('[cevap] taslak oluşamazsa cevap formuna DÖNÜLÜR (cevap=1&m=)', (bool)preg_match('#^__YONLENDIR__mail\.php\?[^ ]*cevap=1#', $prg) && str_contains($prg, 'm=' . $mid), $prg);
$h = sayfa_render('mail.php', ['m' => (string)$mid, 'cevap' => '1']);
ok('[cevap] kurtarılan metin ekranda (kaçırılmış, salt okunur kutu) — kaybolmadı', str_contains($h, 'id="mail-tr-kurtar"') && str_contains($h, 'Önemli cevap &lt;b&gt;metni&lt;/b&gt;') && !str_contains($h, 'Önemli cevap <b>'));
$h = sayfa_render('mail.php', ['m' => (string)$mid, 'cevap' => '1']);
ok('[cevap] kurtarma TEK seferlik (ikinci açılışta bayat metin yok)', !str_contains($h, 'Önemli cevap'));
$db->exec('UPDATE mail_accounts SET is_active = 1 WHERE id = 1');
$smtpYedek = (string)$db->query('SELECT smtp_pass_enc FROM mail_accounts WHERE id = 1')->fetchColumn();
$db->exec("UPDATE mail_accounts SET smtp_pass_enc = '' WHERE id = 1");
$h = sayfa_render('mail.php', ['m' => (string)$mid]);
ok('[cevap] SMTP şifresi yoksa "Cevapla" yerine neden', !str_contains($h, 'mail-cevapla') && str_contains($h, 'SMTP şifresi tanımlı değil'));
$db->prepare('UPDATE mail_accounts SET smtp_pass_enc = ? WHERE id = 1')->execute([$smtpYedek]);
$h = sayfa_render('mail.php', ['m' => (string)$mid]);
ok('[cevap] koşullar sağlanınca "Cevapla" geri gelir', str_contains($h, 'mail-cevapla') && str_contains($h, 'class="mail-cevapbar"'));
// Geçersiz form anahtarı (CSRF değil, taslak hatası): metin ŞİMDİ cevap formunu doldurur
$kotu = $yaz; $kotu['idem'] = 'x';
$prg = sayfa_render('mail.php', [], $kotu);
$h = sayfa_render('mail.php', ['m' => (string)$mid, 'cevap' => '1']);
ok('[cevap] başarısız önizlemede form yazılan metinle GERİ DOLAR', str_contains($h, 'name="body_tr"') && str_contains($h, 'Önemli cevap &lt;b&gt;metni&lt;/b&gt;') && str_contains($h, 'data-taslak-anahtar="m' . $mid . '"'), $prg);
ok('[cevap] mail.js: localStorage yedeği try/catch içinde, başarıda (onay paneli) temizlenir', str_contains(file_get_contents($ROOT . '/assets/mail.js'), 'data-taslak-temizle') && str_contains(file_get_contents($ROOT . '/assets/mail.js'), "window.localStorage || null; } catch"));
ok('[cevap] onay paneli taslak temizleme kancasını taşır', str_contains(sayfa_render('mail.php', ['o' => (string)$oid]), 'data-taslak-temizle="m' . $mid . '"'));

// — 9. Yetkisiz kullanıcıda boş yapışkan Cevapla şeridi çizilmez —
$IS_ADMIN = false; $PERMS = ['mail.read']; $UID = 2;
$h = sayfa_render('mail.php', ['m' => (string)$mid]);
ok('[cevapbar] mail.reply yoksa .mail-cevapbar ŞERİDİ çizilmez, yetki notu düz metin', !str_contains($h, 'class="mail-cevapbar"') && !str_contains($h, 'mail-okuyucu--bar') && str_contains($h, 'mail.reply yetkisi gerekir'));
$PERMS = ['mail.read', 'mail.reply'];
$h = sayfa_render('mail.php', ['m' => (string)$mid]);
ok('[cevapbar] mail.reply varsa şerit + bölüm sınıfı var', str_contains($h, 'class="mail-cevapbar"') && str_contains($h, 'mail-okuyucu--bar'));

// — 10/17. Sekmeler img=1 taşır; role=tab yok, aria-current; görünmez metinli noktalar —
$h = sayfa_render('mail.php', ['m' => (string)$htmlMid, 'v' => 'orj', 'img' => '1']);
$sekme = $href($h, '/<nav class="mail-sekmeler"[^>]*>(.*?)<\/nav>/s');
ok('[sekme] sekme bağlantıları img=1 taşır', $sekme && substr_count($sekme[0], 'img=1') === 2, $sekme[0] ?? '');
ok('[a11y] role="tab"/tablist YOK; aktif sekme aria-current="page"', !str_contains($h, 'role="tab"') && !str_contains($h, 'role="tablist"') && str_contains($h, 'aria-current="page"'));
$h = sayfa_render('mail.php');
ok('[a11y] okunmamış noktası aria-hidden + görünmez "Okunmamış:" metni', str_contains($h, '<i class="mail-nokta" aria-hidden="true"></i><span class="mail-gizli">Okunmamış: </span>') && !str_contains($h, 'aria-label="Okunmamış"'));
ok('[a11y] .mail-gizli görsel gizleme kuralı mail.css\'te', (bool)preg_match('/\.mail-gizli\s*\{[^}]*clip/', file_get_contents($ROOT . '/assets/mail.css')));
$db->exec("DELETE FROM mail_sync_state WHERE account_id = 1");
$db->exec("INSERT INTO mail_sync_state (account_id, folder, last_error) VALUES (1, 'INBOX', 'bağlantı reddedildi')");
$IS_ADMIN = true; $PERMS = []; $UID = 9;
$h = sayfa_render('mail.php');
ok('[a11y] senkron hata noktası: "!" aria-hidden + metin karşılığı', str_contains($h, 'aria-hidden="true">!</span><span class="mail-gizli">Son senkron hatası: bağlantı reddedildi</span>'));

// — 5. .mail-bilgi blok (düz cümle kutusu), düğme satırı ayrı sınıf —
$css = (string)file_get_contents($ROOT . '/assets/mail.css');
preg_match('/^\.mail-bilgi \{([^}]*)\}/m', $css, $mb);
ok('[bilgi] .mail-bilgi flex DEĞİL (cümle parçaları kırılmaz)', !empty($mb[1]) && !str_contains($mb[1], 'display: flex') && !str_contains($mb[1], 'display:flex'), $mb[1] ?? 'kural yok');
ok('[bilgi] düğme/form satırı için .mail-bilgi-eylem (flex) var', (bool)preg_match('/^\.mail-bilgi-eylem \{[^}]*display: flex/m', $css));
$h = sayfa_render('mail.php', ['m' => (string)$htmlMid, 'v' => 'orj']);
ok('[bilgi] "Görselleri göster" düğmesi .mail-bilgi-eylem içinde', str_contains($h, '<div class="mail-bilgi-eylem"><a class="btn"'));
ok('[bilgi] Reply-To açıklaması .mail-bilgi (kutu) DEĞİL, satır içi .mail-ince', !preg_match('/<dd>[^<]*<span class="mail-bilgi">/', sayfa_render('mail.php', ['o' => (string)$oid])));

// — 4. Onay kutuları: kapsayıcı sorgusu (dar panelde alt alta), kapsayıcı yalnız sarmalayıcıda —
ok('[onay] kutular .mail-onay-kap kapsayıcısında; @container ile yan yana', str_contains(sayfa_render('mail.php', ['o' => (string)$oid]), '<div class="mail-onay-kap"><div class="mail-onay-kutular">') && (bool)preg_match('/@container \(min-width: \d+px\) \{ \.mail-onay-kutular \{ grid-template-columns: 1fr 1fr/', $css));
ok('[onay] .mail-okuyucu container-type ALMAZ (fixed Cevapla çubuğu bozulmasın)', !preg_match('/\.mail-okuyucu[^{]*\{[^}]*container-type/', preg_replace('~/\*.*?\*/~s', '', $css)));

// — 14. Geri alınamaz düğmelerde data-onay (inline onclick YOK) —
$db->exec("UPDATE mail_outbox SET status = 'unknown' WHERE id = $oid");
$IS_ADMIN = false; $PERMS = ['mail.read', 'mail.reply', 'mail.send']; $UID = 2;
$h = sayfa_render('mail.php', ['o' => (string)$oid]);
ok('[onay-sor] Gönderildi/Gönderilmedi düğmeleri data-onay taşır (inline onclick yok)', substr_count($h, 'data-onay=') === 2 && !str_contains(substr($h, (int)strpos($h, '<section class="mail-okuyucu')), 'onclick='), 'adet=' . substr_count($h, 'data-onay='));
$db->exec("UPDATE mail_outbox SET status = 'translated' WHERE id = $oid");
$h = sayfa_render('mail.php', ['o' => (string)$oid]);
ok('[onay-sor] "Cevabı iptal et" data-onay taşır', (bool)preg_match('/value="cevap_iptal" type="submit" data-onay="/', $h));

// — 6. Cevap Bekleyen: toplu "cevaplandı say" —
$ekleBekleyen = function (int $acc, string $konu) use ($db, $ins): int {
    $ins->execute([$acc, random_int(100, 99999), sha1($konu . random_int(1, 1e9)), $konu, 'Bekleyen', 'bekleyen gövde', null]);
    $i = (int)$db->lastInsertId();
    $db->exec("UPDATE mail_messages SET needs_reply = 1, replied_at = NULL WHERE id = $i");
    return $i;
};
$b1 = $ekleBekleyen(1, 'Bekleyen 1'); $b2 = $ekleBekleyen(1, 'Bekleyen 2'); $b3 = $ekleBekleyen(1, 'Bekleyen 3'); $bYab = $ekleBekleyen($gizliHesap, 'YABANCI BEKLEYEN');
$h = sayfa_render('mail.php', ['f' => 'cevap']);
ok('[toplu] Cevap Bekleyen\'de her satırda onay kutusu + toplu form (reply yetkili)', str_contains($h, 'name="ids[]" value="' . $b1 . '"') && str_contains($h, 'name="ids[]" value="' . $b3 . '"') && str_contains($h, 'id="mail-toplu-form"') && str_contains($h, 'value="cevaplandi_toplu"') && str_contains($h, 'data-toplu-tumu'), 'adet=' . substr_count($h, 'name="ids[]"'));
ok('[toplu] yabancı hesabın bekleyeni listede/formda YOK', !str_contains($h, 'YABANCI BEKLEYEN') && !str_contains($h, 'value="' . $bYab . '"'));
$h = sayfa_render('mail.php', ['f' => 'gelen']);
ok('[toplu] diğer klasörlerde toplu form YOK', !str_contains($h, 'mail-toplu-form'));
$PERMS = ['mail.read'];
$h = sayfa_render('mail.php', ['f' => 'cevap']);
ok('[toplu] mail.reply yoksa toplu form/kutular YOK', !str_contains($h, 'mail-toplu-form') && !str_contains($h, 'ids[]'));
$PERMS = ['mail.read', 'mail.reply'];
$ctxT = ['uid' => 2, 'hesapIds' => [1], 'yonetici' => false, 'cevap' => true, 'send' => false, 'a' => 0, 'm' => 0, 'o' => 0];
$r = mail_post_isle($db, ['cevap' => false] + $ctxT, 'cevaplandi_toplu', ['ids' => [$b1]]);
ok('[toplu] mail.reply yoksa YASAK', $r['yasak'] !== null && str_contains($r['yasak'], 'mail.reply'));
$sayAudit = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'mail_reply_mark_bulk'")->fetchColumn();
$r = mail_post_isle($db, $ctxT, 'cevaplandi_toplu', ['ids' => [(string)$b1, (string)$b2, (string)$bYab, 'abc', '0', (string)$b1]]);
$rep = fn(int $i) => $db->query("SELECT replied_at FROM mail_messages WHERE id = $i")->fetchColumn();
ok('[toplu] seçilenler işaretlendi (b1,b2), seçilmeyen b3 DEĞİL', $r['ok'] && $rep($b1) !== null && $rep($b2) !== null && $rep($b3) === null, json_encode($r));
ok('[toplu] yabancı hesabın mesajı İŞARETLENMEDİ (ACL), tekrarlı/geçersiz id etkisiz', $rep($bYab) === null && str_contains($r['mesaj'], '2 mail'));
$au = $db->query("SELECT new_values FROM audit_log WHERE action = 'mail_reply_mark_bulk' ORDER BY id DESC LIMIT 1")->fetchColumn();
ok('[toplu] audit: mail_reply_mark_bulk, id\'ler + adet', (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'mail_reply_mark_bulk'")->fetchColumn() === $sayAudit + 1 && str_contains((string)$au, '"adet":2') && str_contains((string)$au, (string)$b1));
$r = mail_post_isle($db, $ctxT, 'cevaplandi_toplu', ['ids' => [$b1, $b2]]);
ok('[toplu] zaten cevaplanmış olanlara tekrar dokunulmaz (idempotent)', !$r['ok'] && $rep($b3) === null);
$r = mail_post_isle($db, $ctxT, 'cevaplandi_toplu', ['kapsam' => 'sayfa', 'ids_sayfa' => "$b3,$bYab"]);
ok('[toplu] "sayfadakilerin hepsi" kapsamı: b3 işaretlendi, yabancı değil', $r['ok'] && $rep($b3) !== null && $rep($bYab) === null);
$src = (string)file_get_contents($ROOT . '/mail.php') . (string)file_get_contents($ROOT . '/config/mail_view.php');
ok('[toplu] POST yolu: csrf_check mail.php\'de önce, işlem mail_post_isle\'de', strpos((string)file_get_contents($ROOT . '/mail.php'), 'csrf_check(') < strpos((string)file_get_contents($ROOT . '/mail.php'), 'mail_post_isle(') && str_contains($src, "'cevaplandi_toplu'"));

// — 15. Hatalı klasörü sayfalama + rozet tutarlılığı + arama ipucu —
$db->exec("DELETE FROM mail_outbox WHERE status IN ('failed','unknown')");
for ($i = 0; $i < 35; $i++) {
    $ins->execute([1, 500 + $i, sha1('hata' . $i), 'Çeviri hatası ' . $i, 'Hatalı', 'x', null]);
    $db->exec('UPDATE mail_messages SET tr_status = \'failed\', tr_error = \'hata\', received_at = \'2026-09-' . str_pad((string)(1 + $i % 28), 2, '0', STR_PAD_LEFT) . ' 10:00:00\' WHERE id = ' . (int)$db->lastInsertId());
}
$sayilar = mail_filtre_sayilari($db, [1]);
$hl = mail_hatali_listele($db, [1], '', 1);
ok('[hatalı] rozet sayısı = listenin toplamı (tümü sayılıyor)', $sayilar['hatali'] === $hl['toplam'] && $hl['toplam'] >= 35, json_encode([$sayilar['hatali'], $hl['toplam']]));
$hl2 = mail_hatali_listele($db, [1], '', 2);
ok('[hatalı] sayfalama: her sayfa en çok ' . MAIL_SAYFA_BOYUTU . ' öğe, sayfalar kesişmez, hepsi görünür',
    count($hl['satirlar']) + count($hl['hatalilar']) <= MAIL_SAYFA_BOYUTU && count($hl2['hatalilar']) + count($hl2['satirlar']) > 0
    && !array_intersect(array_column($hl['hatalilar'], 'id'), array_column($hl2['hatalilar'], 'id'))
    && count($hl['satirlar']) + count($hl['hatalilar']) + count($hl2['satirlar']) + count($hl2['hatalilar']) === min($hl['toplam'], 2 * MAIL_SAYFA_BOYUTU));
$h = sayfa_render('mail.php', ['f' => 'hatali']);
ok('[hatalı] ekranda sayfa göstergesi "1 / 2" ve "Eski ›" bağlantısı', str_contains($h, '1 / 2') && str_contains($h, 'Eski ›'));
$h = sayfa_render('mail.php', ['f' => 'hatali', 'p' => '99']);
ok('[sayfa] aralık dışı ?p= SON sayfaya kıstırılır (boş sayfa değil)', str_contains($h, '2 / 2') && str_contains($h, 'Çeviri hatası') && !str_contains($h, 'Bu klasörde kayıt yok'));
$h = sayfa_render('mail.php', ['p' => '9999']);
ok('[sayfa] gelen kutusunda da aşırı ?p= kıstırılır, liste dolu', str_contains($h, 'mail-oge') && !str_contains($h, 'Bu klasörde mail yok'));
$h = sayfa_render('mail.php', ['f' => 'taslak']);
ok('[ara] giden klasöründe arama ipucu "alıcı"', str_contains($h, 'placeholder="Konu veya alıcı ara…"'));
$h = sayfa_render('mail.php', ['f' => 'gelen']);
ok('[ara] gelen klasöründe arama ipucu "gönderen"', str_contains($h, 'placeholder="Konu veya gönderen ara…"'));

// — 7/18. Tablet üst menüsü Mail bağlantısı; kaldırılmış mail_api.php —
ok('[topnav] mail.read olan kullanıcıda üst menüde Mail bağlantısı', (bool)preg_match('#<nav class="topnav">.*?<a href="[^"]*mail\.php"[^>]*>Mail</a>.*?</nav>#s', $h), 'topnav=' . substr_count($h, 'topnav') . ' mailLink=' . substr_count($h, '>Mail<'));
$PERMS = []; $IS_ADMIN = false;
$ust = sayfa_render('mail.php');
ok('[topnav] yetkisiz kullanıcıda üst menüde Mail YOK (sayfa 403)', str_contains($ust, '__HATA__'));
$hs = (string)file_get_contents($ROOT . '/config/helpers.php');
ok('[nav] nav_aktif_anahtar yok olan mail_api.php\'yi listelemiyor', !str_contains($hs, 'mail_api.php'));

// — 2/19/12/13/11/14. Hesap ekranı: PRG, idempotent durum, audit farkı, POST'tan geri doldurma, mail.read rozeti —
$db->exec("CREATE TABLE IF NOT EXISTS roles (id INTEGER PRIMARY KEY, slug TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS user_roles (user_id INT, role_id INT)");
$db->exec("INSERT INTO roles (id, slug) VALUES (1, 'admin')"); $db->exec("INSERT INTO user_roles (user_id, role_id) VALUES (1, 1)");
$IS_ADMIN = true; $PERMS = []; $UID = 9;
$durum = fn(int $i) => (int)$db->query("SELECT is_active FROM mail_accounts WHERE id = $i")->fetchColumn();
$d0 = $durum(1);
$pas = ['csrf' => $tok, 'islem' => 'durum', 'id' => '1', 'hedef' => '0'];
$r1 = sayfa_render('mail_hesaplar.php', [], $pas); $s1 = $durum(1);
$r2 = sayfa_render('mail_hesaplar.php', [], $pas); $s2 = $durum(1);
ok('[durum] Pasifleştir PRG ile yönlendirir; POST YENİLENİRSE hesap yeniden AKTİF OLMAZ (idempotent)', $d0 === 1 && $s1 === 0 && $s2 === 0 && $r1 === '__YONLENDIR__mail_hesaplar.php' && $r2 === '__YONLENDIR__mail_hesaplar.php', json_encode([$d0, $s1, $s2]));
$gf = sayfa_render('mail_hesaplar.php');
ok('[durum] flash: ikinci istek "zaten pasif" der', str_contains($gf, 'Hesap zaten pasif'));
$tg = $db->query("SELECT old_values, new_values FROM audit_log WHERE action = 'mail_account_toggle' ORDER BY id DESC")->fetchAll();
ok('[audit] toggle: yalnız GERÇEK değişimde ve yeni durumla yazılır (1 kayıt, 1→0)', count($tg) === 1 && str_contains($tg[0]['old_values'], '"is_active":1') && str_contains($tg[0]['new_values'], '"is_active":0'), json_encode($tg));
sayfa_render('mail_hesaplar.php', [], ['hedef' => '1'] + $pas);
ok('[durum] Aktifleştir hedef=1 ile', $durum(1) === 1);
sayfa_render('mail_hesaplar.php', [], ['hedef' => 'x'] + $pas);
ok('[durum] geçersiz hedef etkisiz', $durum(1) === 1);
$h = sayfa_render('mail_hesaplar.php');
ok('[durum] "Pasifleştir" data-onay taşır, "Aktifleştir" taşımaz (aktif hesap)', (bool)preg_match('/value="0">\s*<button class="btn" type="submit" data-onay="Hesap pasifleştirilsin mi/', $h));
// kaydet: PRG + audit farkı (yalnız şifresiz alanlar, değer eski→yeni) + erişim farkı
$once = $db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'mail_account_update'")->fetchColumn();
$duz = ['csrf' => $tok, 'islem' => 'kaydet', 'id' => '1', 'kullanicilar_gonderildi' => '1', 'kullanicilar' => ['3'],
    'label' => 'Asya Yeni Etiket', 'email' => 'info@asya.com', 'imap_host' => 'imap.asya.com', 'imap_port' => '993', 'imap_security' => 'ssl', 'imap_user' => 'info@asya.com',
    'imap_pass' => 'YeniImapSir-5521', 'smtp_host' => 'smtp.asya.com', 'smtp_port' => '465', 'smtp_security' => 'ssl', 'smtp_user' => 'info@asya.com', 'smtp_pass' => '',
    'sync_folder' => 'INBOX', 'initial_days' => '45', 'reply_to' => 'cevap@asya.com', 'target_lang' => 'tr', 'append_sent' => '1'];
$r = sayfa_render('mail_hesaplar.php', ['duzenle' => '1'], $duz);
ok('[kaydet] başarıdan sonra PRG (yenileme "zaten var" vermez); düzenleme bağlamı korunur', $r === '__YONLENDIR__mail_hesaplar.php?duzenle=1', $r);
$au = $db->query("SELECT old_values, new_values FROM audit_log WHERE action = 'mail_account_update' ORDER BY id DESC LIMIT 1")->fetch();
ok('[audit] update: değişen alanlar eski→yeni (initial_days 30→45, reply_to ""→cevap@…, label)', (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'mail_account_update'")->fetchColumn() === (int)$once + 1
    && str_contains($au['old_values'], '"initial_days":"30"') && str_contains($au['new_values'], '"initial_days":"45"') && str_contains($au['old_values'], '"reply_to":""')
    && str_contains($au['new_values'], '"reply_to":"cevap@asya.com"') && str_contains($au['new_values'], '"label":"Asya Yeni Etiket"'), json_encode($au));
ok('[audit] update: değişmeyen alan YOK (imap_host aynı), ŞİFRE değeri YOK, yalnız sifre_degisti', !str_contains($au['new_values'], 'imap_host') && !str_contains($au['old_values'] . $au['new_values'], 'YeniImapSir') && str_contains($au['new_values'], '"sifre_degisti":["imap_pass"]'));
ok('[audit] update: erişim farkı eklenen/çıkarılan kullanıcı id (2 çıktı, 3 girdi)', str_contains($au['new_values'], '"erisim":{"eklenen":[3],"cikarilan":[2]}'), $au['new_values']);
ok('[kaydet] erişim ataması kayıtla birlikte yazıldı', mail_hesap_kullanicilari(1, $db) === [3]);
$h = sayfa_render('mail_hesaplar.php', ['duzenle' => '1']);
ok('[kaydet] append_sent işaretli kaydedildi', (bool)preg_match('/name="append_sent" value="1" checked/', $h));
// Doğrulama hatası: işaretlenmeyen kutu + erişim listesi POST\'tan dolar (DB\'den değil)
$bozuk = $duz; $bozuk['imap_host'] = 'http://evil'; unset($bozuk['append_sent']); $bozuk['kullanicilar'] = ['2'];
$h = sayfa_render('mail_hesaplar.php', ['duzenle' => '1'], $bozuk);
ok('[form] hata sonrası işareti KALDIRILAN append_sent işaretsiz kalır (DB\'deki 1 geri basılmaz)', str_contains($h, 'sunucu adı geçersiz') && !preg_match('/name="append_sent" value="1" checked/', $h));
ok('[form] hata sonrası erişim listesi POST\'taki seçimi gösterir (2 işaretli, 3 işaretsiz)', (bool)preg_match('/name="kullanicilar\[\]" value="2" checked/', $h) && !preg_match('/name="kullanicilar\[\]" value="3" checked/', $h));
ok('[form] hata sonrası düzenleme modu korunur (gizli id)', str_contains($h, 'name="id" value="1"'));
ok('[form] hata sonrası şifre geri basılmaz', !str_contains($h, 'YeniImapSir'));
ok('[form] mail.read olmayan kullanıcıya "mail.read yok" rozeti (u3), olanlarda yok (u1 admin, u2 mail.read)', (bool)preg_match('/value="3"[^>]*>\s*Kullanıcı 3\s*<span[^>]*>mail\.read yok</u', $h) && !preg_match('/Kullanıcı 1\s*<span[^>]*>mail\.read yok/u', $h) && !preg_match('/Kullanıcı 2\s*<span[^>]*>mail\.read yok/u', $h));
ok('[form] #666 sabit renk yok, muted değişkeni', !str_contains((string)file_get_contents($ROOT . '/mail_hesaplar.php'), '#666') && str_contains((string)file_get_contents($ROOT . '/assets/mail.css'), '.mail-ince { color: var(--muted)'));
$tek = $db->prepare("SELECT COUNT(*) FROM mail_accounts WHERE email = ?");
$ya = $duz; unset($ya['id']); $ya['email'] = 'yeni-hesap@asya.com'; $ya['imap_pass'] = 'P1'; $ya['smtp_pass'] = 'P2'; $ya['kullanicilar'] = [];
$r = sayfa_render('mail_hesaplar.php', [], $ya);
$tek->execute(['yeni-hesap@asya.com']);
ok('[kaydet] yeni hesap PRG; refresh simülasyonu "zaten var" hatası verirdi, artık yönlendirme', $r === '__YONLENDIR__mail_hesaplar.php' && (int)$tek->fetchColumn() === 1);
$ca = $db->query("SELECT new_values FROM audit_log WHERE action = 'mail_account_create' ORDER BY id DESC LIMIT 1")->fetchColumn();
ok('[audit] create: alanlar yeni değerlerle, şifre değeri YOK', str_contains((string)$ca, '"email":"yeni-hesap@asya.com"') && !str_contains((string)$ca, '"P1"') && !str_contains((string)$ca, '"P2"') && str_contains((string)$ca, 'sifre_degisti'));
// Tek transaction: atama patlarsa hesap da yazılmaz
$db->exec('ALTER TABLE mail_account_users RENAME TO mail_account_users_x');
$ya2 = $ya; $ya2['email'] = 'atama-patlar@asya.com'; $ya2['kullanicilar'] = ['2'];
$r = sayfa_render('mail_hesaplar.php', [], $ya2);
$db->exec('ALTER TABLE mail_account_users_x RENAME TO mail_account_users');
$tek->execute(['atama-patlar@asya.com']);
ok('[kaydet] erişim ataması başarısızsa HESAP DA YAZILMAZ (tek transaction)', (int)$tek->fetchColumn() === 0 && str_contains($r, 'Hesap kaydedilemedi'), substr($r, 0, 120));
ok('[kaydet] hata sonrası PDO transaction açık kalmadı', !$db->inTransaction());

// Anahtar yokken yönetici uyarısı
putenv('MAIL_MASTER_KEY');
$IS_ADMIN = true; $PERMS = []; $UID = 9;
$h = sayfa_render('mail.php');
ok('anahtar yoksa yönetici uyarı görüyor', str_contains($h, 'Şifreleme anahtarı tanımlı değil'));
$hesapOnce = (int)$db->query('SELECT COUNT(*) FROM mail_accounts')->fetchColumn();
$h = sayfa_render('mail_hesaplar.php', [], array_merge($post, ['email' => 'yeni@asya.com']));
ok('anahtar yokken hesap kaydı reddediliyor', str_contains($h, 'MAIL_MASTER_KEY') && (int)$db->query('SELECT COUNT(*) FROM mail_accounts')->fetchColumn() === $hesapOnce);

mail_test_bitir();
