<?php
// =========================================================
// scripts/mail_core_smoke.php — Mail Merkezi M1: şema, şifreleme, maskeleme,
// hesap deposu, hesap ACL (fail-closed), can_mail() yetki matrisi.
//   php scripts/mail_core_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
require_once __DIR__ . '/_mail_test_lib.php';

$db = db();
mail_test_diger_tablolar($db);

echo "=== 1. Şema ===\n";
ok('migrate öncesi sema_hazir=false', mail_sema_hazir($db) === false);
// mail_migrate() gerçek DDL'i MySQL sözdizimiyle çalıştırır; SQLite'ta ENGINE= reddedilir →
// 'hata' döner ve İSTİSNA SIZDIRMAZ (canlıda MySQL). Burada yalnız hata yolunu denetliyoruz.
$r = mail_migrate($db);
ok('MySQL DDL SQLite\'ta hata raporlanıyor, istisna fırlamıyor', count($r) === 7 && $r[0]['durum'] === 'hata');
ok('hata mesajı teknik ayrıntı sızdırmıyor', !str_contains($r[0]['mesaj'], 'SQLSTATE'));
mail_test_sema_kur($db);
ok('çevrilmiş DDL ile 7 tablo kuruldu', mail_sema_hazir($db) === true);
$r = mail_migrate($db);
ok('migrate idempotent (hepsi "var")', count(array_filter($r, fn($x) => $x['durum'] === 'var')) === 7);
$ddl = implode("\n", mail_tablolar());
ok('mevcut tablolara ALTER yok', stripos($ddl, 'ALTER TABLE') === false);
ok('uidvalidity+uid UNIQUE anahtarı var', str_contains($ddl, 'UNIQUE KEY `uq_mm_uid` (`account_id`, `folder`, `uidvalidity`, `uid`)'));
ok('outbox idempotency UNIQUE var', str_contains($ddl, 'UNIQUE KEY `uq_mo_idem` (`account_id`, `idempotency_key`)'));
ok('plaintext şifre kolonu YOK (yalnız *_enc)', !preg_match('/`(imap|smtp)_pass`\s/', $ddl) && str_contains($ddl, '`imap_pass_enc`'));

echo "\n=== 2. Anahtar yokken fail-closed ===\n";
putenv('MAIL_MASTER_KEY');
ok('anahtar yok → mail_crypto_hazir=false', mail_crypto_hazir() === false);
$istisna = false;
try { mail_sifrele('x', 'aad'); } catch (RuntimeException $e) { $istisna = true; }
ok('anahtar yok → mail_sifrele reddediyor', $istisna);
ok('anahtar yok → mail_coz null', mail_coz('v1:aaaaaaaa:AAAA', 'aad') === null);
$k = mail_hesap_kaydet(['label'=>'A','email'=>'a@x.com','imap_host'=>'imap.x.com','imap_user'=>'a','imap_pass'=>'p1',
    'smtp_host'=>'smtp.x.com','smtp_user'=>'a','smtp_pass'=>'p2'], null, 1, $db);
ok('anahtar yok → hesap KAYDEDİLMİYOR', $k['ok'] === false && (int)$db->query('SELECT COUNT(*) FROM mail_accounts')->fetchColumn() === 0);
putenv('MAIL_MASTER_KEY=' . base64_encode('kisa'));
ok('yanlış uzunlukta anahtar reddediliyor', mail_master_key() === null);
putenv('MAIL_MASTER_KEY=%%%not-base64%%%');
ok('base64 olmayan anahtar reddediliyor', mail_master_key() === null);

echo "\n=== 3. AES-256-GCM + AAD ===\n";
$ana = mail_test_anahtar_kur();
ok('geçerli anahtar → hazır', mail_crypto_hazir());
$b1 = mail_sifrele('Gizli-Sifre-123', mail_aad(5, 'imap_pass'));
$b2 = mail_sifrele('Gizli-Sifre-123', mail_aad(5, 'imap_pass'));
ok('blob biçimi v1:<kid>:<b64>', (bool)preg_match('/^v1:[0-9a-f]{8}:[A-Za-z0-9+\/=]+$/', $b1));
ok('blob düz metni İÇERMİYOR', !str_contains($b1, 'Gizli') && !str_contains(base64_decode(explode(':', $b1, 3)[2]), 'Gizli'));
ok('aynı düz metin iki kez farklı blob (rastgele nonce)', $b1 !== $b2);
ok('gidiş-dönüş', mail_coz($b1, mail_aad(5, 'imap_pass')) === 'Gizli-Sifre-123');
ok('AAD: başka hesaba taşınan blob ÇÖZÜLMÜYOR', mail_coz($b1, mail_aad(6, 'imap_pass')) === null);
ok('AAD: başka alana taşınan blob ÇÖZÜLMÜYOR', mail_coz($b1, mail_aad(5, 'smtp_pass')) === null);
$p = explode(':', $b1, 3); $ham = base64_decode($p[2]); $ham[40] = $ham[40] ^ "\x01";
ok('bozulmuş blob (tag/ct) ÇÖZÜLMÜYOR', mail_coz($p[0] . ':' . $p[1] . ':' . base64_encode($ham), mail_aad(5, 'imap_pass')) === null);
ok('bozuk/boş blob null, istisna yok', mail_coz('çöp', 'a') === null && mail_coz('', 'a') === null && mail_coz(null, 'a') === null);
mail_test_anahtar_kur(); // başka anahtar
ok('farklı anahtarla eski blob çözülmüyor (kid uyuşmaz)', mail_coz($b1, mail_aad(5, 'imap_pass')) === null);
putenv('MAIL_MASTER_KEY=' . $ana);
ok('doğru anahtar geri gelince çözülüyor', mail_coz($b1, mail_aad(5, 'imap_pass')) === 'Gizli-Sifre-123');

echo "\n=== 4. Sır maskeleme ===\n";
mail_redact_sirlar('SuperGizliParola');
ok('kayıtlı sır maskeleniyor', !str_contains(mail_redact('hata: SuperGizliParola yanlış'), 'SuperGizliParola'));
ok('kayıtlı sırrın base64 hâli maskeleniyor', !str_contains(mail_redact('AUTH ' . base64_encode('SuperGizliParola')), base64_encode('SuperGizliParola')));
ok('IMAP LOGIN argümanları maskeleniyor', mail_redact('a1 LOGIN "ahmet" "p@ss w0rd"') === 'a1 LOGIN *** ***');
ok('AUTHENTICATE PLAIN maskeleniyor', mail_redact('AUTHENTICATE PLAIN AGFobWV0AHNpZnJl') === 'AUTHENTICATE PLAIN ***');
ok('password=… maskeleniyor', !str_contains(mail_redact('connect password=abc123 host=x'), 'abc123'));
ok('uzun base64 jetonu maskeleniyor', !str_contains(mail_redact('tok ' . str_repeat('A1b2', 15)), 'A1b2A1b2'));
ok('normal metin bozulmuyor', mail_redact('Bağlantı zaman aşımı (993)') === 'Bağlantı zaman aşımı (993)');

echo "\n=== 5. Hesap doğrulama ===\n";
$gecerli = ['label'=>'Asya','email'=>'Info@Asya.com','imap_host'=>'imap.asya.com','imap_user'=>'info@asya.com','imap_pass'=>'ImapP4ss!',
    'smtp_host'=>'smtp.asya.com','smtp_user'=>'info@asya.com','smtp_pass'=>'SmtpP4ss!'];
$d = mail_hesap_dogrula($gecerli, true);
ok('geçerli girdi kabul', $d['ok'], implode('|', $d['hatalar']));
ok('e-posta küçük harfe', $d['veri']['email'] === 'info@asya.com');
foreach ([
    'host URL' => ['imap_host' => 'http://evil.com'],
    'host boşluk' => ['smtp_host' => 'a b.com'],
    'host CRLF' => ['imap_host' => "a.com\r\nX: y"],
    'plain güvenlik' => ['imap_security' => 'none'],
    'port 0' => ['smtp_port' => 0],
    'port 70000' => ['imap_port' => 70000],
    'kullanıcı CRLF' => ['imap_user' => "a\r\nb"],
    'şifre CRLF' => ['smtp_pass' => "x\r\nQUIT"],
    'bad email' => ['email' => 'yok'],
    'bad reply-to' => ['reply_to' => 'x'],
    'klasör CRLF' => ['sync_folder' => "INBOX\r\nA1 DELETE x"],
    'dil kodu' => ['target_lang' => 'tr;drop'],
] as $ad => $ek) {
    $x = mail_hesap_dogrula(array_merge($gecerli, $ek), true);
    ok("reddedilir: $ad", !$x['ok']);
}
ok('yeni hesapta şifre zorunlu', !mail_hesap_dogrula(array_merge($gecerli, ['imap_pass' => '']), true)['ok']);
ok('düzenlemede şifre boş olabilir', mail_hesap_dogrula(array_merge($gecerli, ['imap_pass' => '', 'smtp_pass' => '']), false)['ok']);

echo "\n=== 6. Hesap deposu ===\n";
$k = mail_hesap_kaydet($gecerli, null, 1, $db);
ok('hesap kaydedildi', $k['ok'] && $k['id'] === 1, json_encode($k));
$satir = $db->query('SELECT * FROM mail_accounts WHERE id = 1')->fetch();
ok('DB\'de düz şifre YOK', !str_contains(json_encode($satir), 'ImapP4ss') && !str_contains(json_encode($satir), 'SmtpP4ss'));
ok('DB\'de şifre alanları v1: blob', str_starts_with($satir['imap_pass_enc'], 'v1:') && str_starts_with($satir['smtp_pass_enc'], 'v1:'));
$c = mail_hesap_cred_oku(1, $db);
ok('cred_oku şifreleri çözüyor', $c && $c['imap_pass'] === 'ImapP4ss!' && $c['smtp_pass'] === 'SmtpP4ss!');
ok('cred_oku *_enc sızdırmıyor', !isset($c['imap_pass_enc']) && !isset($c['smtp_pass_enc']));
$g = mail_hesap_goster(1, $db);
ok('goster: şifre/blobsuz, yalnız var bayrağı', !isset($g['imap_pass']) && !isset($g['imap_pass_enc']) && $g['imap_pass_var'] === 1 && $g['smtp_pass_var'] === 1);
ok('goster çıktısı düz şifre içermiyor', !str_contains(json_encode($g), 'ImapP4ss'));
$once = $satir['imap_pass_enc'];
$k2 = mail_hesap_kaydet(array_merge($gecerli, ['label' => 'Asya 2', 'imap_pass' => '', 'smtp_pass' => '']), 1, 1, $db);
$sonra = $db->query('SELECT * FROM mail_accounts WHERE id = 1')->fetch();
ok('boş şifre = değiştirme (blob aynı kaldı), diğer alan güncellendi', $k2['ok'] && $sonra['imap_pass_enc'] === $once && $sonra['label'] === 'Asya 2');
mail_hesap_kaydet(array_merge($gecerli, ['imap_pass' => 'Yeni-Imap-9']), 1, 1, $db);
ok('yeni şifre yazılınca değişiyor ve çözülüyor', mail_hesap_cred_oku(1, $db)['imap_pass'] === 'Yeni-Imap-9' && mail_hesap_cred_oku(1, $db)['smtp_pass'] === 'SmtpP4ss!');
$dup = mail_hesap_kaydet($gecerli, null, 1, $db);
ok('aynı e-posta ikinci kez eklenemez', !$dup['ok'] && str_contains($dup['hatalar'][0], 'zaten'));
ok('olmayan hesap güncellenemez', !mail_hesap_kaydet($gecerli, 999, 1, $db)['ok']);
$mal = mail_hesap_kaydet(array_merge($gecerli, ['email' => 'b@asya.com']), null, 1, $db);
ok('ikinci hesap eklendi', $mal['ok'] && $mal['id'] === 2);
// Başka satırın blob'unu bu satıra kopyalama (satır değiştirme saldırısı)
$db->exec("UPDATE mail_accounts SET imap_pass_enc = (SELECT imap_pass_enc FROM mail_accounts WHERE id = 1) WHERE id = 2");
ok('satırlar arası blob kopyası ÇÖZÜLMÜYOR → cred_oku null', mail_hesap_cred_oku(2, $db) === null);
ok('olmayan hesap cred null', mail_hesap_cred_oku(404, $db) === null);

echo "\n=== 7. can_mail() matrisi ===\n";
$IS_ADMIN = false;
$matris = [
    [[], [0,0,0,0]], [['mail.reply'], [0,0,0,0]], [['mail.send'], [0,0,0,0]], [['mail.read'], [1,0,0,0]],
    [['mail.read','mail.reply'], [1,1,0,0]], [['mail.read','mail.send'], [1,0,1,0]],
    [['mail.read','mail.reply','mail.send'], [1,1,1,0]], [['mail.admin'], [1,1,1,1]],
];
foreach ($matris as [$perms, $bek]) {
    $PERMS = $perms;
    $got = array_map(fn($p) => can_mail($p) ? 1 : 0, ['read','reply','send','admin']);
    ok('matris [' . implode(',', $perms) . ']', $got === $bek, json_encode($got));
}
$PERMS = []; $IS_ADMIN = true;
ok('is_admin() her şeyi açar (rol seed\'i olmasa da)', can_mail('read') && can_mail('reply') && can_mail('send') && can_mail('admin'));
ok('bilinmeyen izin adı kapalı', !can_mail('delete') && !can_mail(''));
$IS_ADMIN = false; $PERMS = ['mail.admin'];
ok('bilinmeyen izin mail.admin için de kapalı', !can_mail('x'));

echo "\n=== 8. Hesap ACL (fail-closed) ===\n";
mail_test_anahtar_kur();
$mal3 = mail_hesap_kaydet(array_merge($gecerli, ['email' => 'c@asya.com', 'label' => 'Pasif']), null, 1, $db);
$db->exec("UPDATE mail_accounts SET is_active = 0 WHERE id = {$mal3['id']}");
ok('atama yok → kullanıcı HİÇBİR hesabı görmez', mail_gorunur_hesap_idleri(2, $db, false) === []);
mail_hesap_kullanici_ata(1, [2], $db);
mail_hesap_kullanici_ata(3, [2], $db);
ok('atanan aktif hesap görünür, atanmayan gizli', mail_gorunur_hesap_idleri(2, $db, false) === [1]);
ok('PASİF hesap atanmış olsa da görünmez', !in_array(3, mail_gorunur_hesap_idleri(2, $db, false), true));
ok('başka kullanıcı (3) göremez', mail_gorunur_hesap_idleri(3, $db, false) === []);
ok('hesap_gorunur_mu: IDOR (başkasının hesabı) false', !mail_hesap_gorunur_mu(2, 2, $db, false));
ok('yönetici hepsini görür (pasif dahil)', mail_gorunur_hesap_idleri(9, $db, true) === [1, 2, 3]);
mail_hesap_kullanici_ata(1, [3, 3, 0, -1, 2], $db);
ok('kullanıcı atama tam liste değiştirir, tekrar/geçersiz süzülür', mail_hesap_kullanicilari(1, $db) === [2, 3]);
mail_hesap_kullanici_ata(1, [], $db);
ok('atama boşaltılınca hesap kimseye görünmez (fail-closed)', mail_gorunur_hesap_idleri(2, $db, false) === []);

echo "\n=== 9. Okunmamış sayacı ===\n";
$now = date('Y-m-d H:i:s');
$ins = $db->prepare("INSERT INTO mail_messages (account_id, folder, uidvalidity, uid, message_id_hash, received_at, is_read) VALUES (?, 'INBOX', 1, ?, ?, ?, ?)");
$ins->execute([1, 1, sha1('a'), $now, 0]); $ins->execute([1, 2, sha1('b'), $now, 0]); $ins->execute([1, 3, sha1('c'), $now, 1]); $ins->execute([2, 1, sha1('d'), $now, 0]);
mail_hesap_kullanici_ata(1, [2], $db);
ok('sayaç yalnız görünür hesapları sayar', mail_okunmamis_sayilari(2, $db, false) === [1 => 2]);
ok('yönetici sayacı tüm hesaplar', mail_okunmamis_sayilari(9, $db, true) === [1 => 2, 2 => 1, 3 => 0]);
ok('atanmamış kullanıcıya sayaç boş', mail_okunmamis_sayilari(3, $db, false) === []);

mail_test_bitir();
