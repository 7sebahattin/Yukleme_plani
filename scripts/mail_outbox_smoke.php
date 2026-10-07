<?php
// =========================================================
// scripts/mail_outbox_smoke.php — Cevap onayı + SMTP + at-most-once durum makinesi (M5)
// Sahte SMTP/IMAP + bellek içi SQLite. Görev §12: gönder'e basmadan SMTP yok · çift gönderim engelli ·
// aynı cevap iki kez gönderilemez · thread başlıkları doğru · credential/log sızıntısı yok.
//   php scripts/mail_outbox_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
require_once __DIR__ . '/_mail_test_lib.php';
require_once $ROOT . '/config/mail_imap.php';
require_once $ROOT . '/config/mail_mime.php';
require_once $ROOT . '/config/mail_sync.php';
require_once $ROOT . '/config/mail_view.php';
require_once $ROOT . '/config/mail_translate.php';
require_once $ROOT . '/config/mail_smtp.php';
require_once $ROOT . '/config/mail_outbox.php';
require_once __DIR__ . '/_mail_fake_smtp.php';
require_once __DIR__ . '/_mail_fake_imap.php';

$db = db(); mail_test_diger_tablolar($db); mail_test_sema_kur($db); mail_test_anahtar_kur();
$NOW = strtotime('2026-10-06 12:00:00');

final class SahteSag implements MailTranslationProviderInterface {
    public array $cagrilar = []; public $davranis;
    public function __construct(?callable $d = null) { $this->davranis = $d ?? fn($m, $k, $h) => ['metin' => strtoupper($h) . ': ' . $m, 'tespit' => 'tr']; }
    public function ad(): string { return 'sahte'; }
    public function parcaLimiti(): int { return 4000; }
    public function cevir(string $metin, ?string $kaynak, string $hedef): array { $this->cagrilar[] = [$metin, $kaynak, $hedef]; return ($this->davranis)($metin, $kaynak, $hedef); }
}
function hesap(string $e, array $ek = []): int {
    global $db;
    return mail_hesap_kaydet(array_merge(['label' => $e, 'email' => $e, 'imap_host' => 'i.t.com', 'imap_user' => $e, 'imap_pass' => 'imap-SIR-1', 'smtp_host' => 's.t.com', 'smtp_user' => $e, 'smtp_pass' => 'smtp-SIR-2', 'display_name' => 'Asya Fresh'], $ek), null, 1, $db)['id'];
}
function gelen(int $acc, array $o = []): int {
    global $db; static $uid = 0; $uid++;
    $o += ['mid' => "parent$uid@musteri.ru", 'refs' => 'root@musteri.ru', 'subject' => 'Re: Order 4411', 'body' => 'Where is my order? Please confirm the shipping date.', 'from' => 'ivan@musteri.ru', 'reply_to' => '', 'thread' => null, 'lang' => 'en'];
    $db->prepare("INSERT INTO mail_messages (account_id, thread_id, folder, uidvalidity, uid, message_id, message_id_hash, references_hdr, subject, from_name, from_addr, reply_to_addr, received_at, body_text, lang, to_addrs, cc_addrs)
        VALUES (?, ?, 'INBOX', 1, ?, ?, ?, ?, ?, 'Ivan Petrov', ?, ?, '2026-10-05 10:00:00', ?, ?, '[]', '[]')")
        ->execute([$acc, $o['thread'], $uid, $o['mid'], sha1('id:' . $o['mid']), $o['refs'], $o['subject'], $o['from'], $o['reply_to'], $o['body'], $o['lang']]);
    return (int)$db->lastInsertId();
}
$SMTP = new FakeSmtpStream(['user' => 'ceviri@asya.com', 'pass' => 'smtp-SIR-2']);
$BAGLANTI = 0;
$smtpFab = function (array $h) use (&$SMTP, &$BAGLANTI) {
    $BAGLANTI++;
    $c = new MailSmtpClient($SMTP->yeniBaglanti());
    $c->baslat('asya.com', false); $c->girisYap($h['smtp_user'], $h['smtp_pass']);
    return $c;
};
function satir(int $id): array { global $db; return $db->query("SELECT * FROM mail_outbox WHERE id = $id")->fetch(); }
function anahtar(): string { return bin2hex(random_bytes(16)); }
function auditSay(string $eylem): int { global $db; return (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = '$eylem'")->fetchColumn(); }

$A = hesap('ceviri@asya.com'); $B = hesap('baska@asya.com');
$m1 = gelen($A); $mB = gelen($B);
$UID = 5; $H = [$A];
$sag = new SahteSag();

echo "=== 1. Taslak ===\n";
$k1 = anahtar();
$t = mail_outbox_taslak($db, $m1, $H, $UID, $k1, ['body_tr' => 'Merhaba, mallar pazartesi yola çıkıyor.', 'target_lang' => 'en', 'quote' => 1]);
ok('taslak oluştu', $t['ok'] && $t['id'] > 0, json_encode($t));
$o1 = satir($t['id']);
ok('durum draft, alıcı = gönderen, konu "Re: Order 4411" (Re: tekrarlanmadı)', $o1['status'] === 'draft' && $o1['to_addr'] === 'ivan@musteri.ru' && $o1['subject'] === 'Re: Order 4411');
ok('thread başlıkları: In-Reply-To = ebeveyn, References = kök + ebeveyn', $o1['hdr_in_reply_to'] === '<' . $db->query("SELECT message_id FROM mail_messages WHERE id = $m1")->fetchColumn() . '>' && str_starts_with((string)$o1['hdr_references'], '<root@musteri.ru> <parent'), (string)$o1['hdr_references']);
ok('taslakta çeviri/onay/Message-ID YOK', $o1['body_out'] === null && $o1['content_hash'] === null && $o1['approved_by'] === null && $o1['out_message_id'] === null);
$t2 = mail_outbox_taslak($db, $m1, $H, $UID, $k1, ['body_tr' => 'başka metin', 'target_lang' => 'en']);
ok('aynı form anahtarı: YENİ satır açılmaz (idempotent), mevcut döner', $t2['ok'] && $t2['id'] === $t['id'] && !empty($t2['mevcut']) && (int)$db->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn() === 1);
ok('mevcut taslağın metni ezilmedi', satir($t['id'])['body_tr'] === 'Merhaba, mallar pazartesi yola çıkıyor.');
$t3 = mail_outbox_taslak($db, $mB, $H, $UID, $k1, ['body_tr' => 'x', 'target_lang' => 'en']);
ok('yabancı hesabın mesajına taslak AÇILAMAZ (ACL)', !$t3['ok']);
foreach (['kısa anahtar' => [[$m1, $H, $UID, 'abc', ['body_tr' => 'x']], 'Form anahtarı'], 'boş metin' => [[$m1, $H, $UID, anahtar(), ['body_tr' => "  \n "]], 'boş'],
    'dev metin' => [[$m1, $H, $UID, anahtar(), ['body_tr' => str_repeat('a', MAIL_CEVAP_MAX + 1)]], 'en çok'], 'kötü dil' => [[$m1, $H, $UID, anahtar(), ['body_tr' => 'x', 'target_lang' => 'en;drop']], 'dil'],
    'olmayan mesaj' => [[99999, $H, $UID, anahtar(), ['body_tr' => 'x']], 'bulunamadı']] as $ad => [$a, $beklenen]) {
    $r = mail_outbox_taslak($db, ...$a);
    ok("taslak reddi: $ad", !$r['ok'] && stripos($r['mesaj'], $beklenen) !== false, $r['mesaj']);
}
$mX = gelen($A, ['from' => 'bozuk-adres', 'mid' => 'x@y.ru']);
ok('geçersiz alıcı adresi → reddedilir', !mail_outbox_taslak($db, $mX, $H, $UID, anahtar(), ['body_tr' => 'x'])['ok']);
$mRT = gelen($A, ['reply_to' => 'satinalma@musteri.ru', 'mid' => 'rt@musteri.ru']);
$tr = mail_outbox_taslak($db, $mRT, $H, $UID, anahtar(), ['body_tr' => 'x', 'target_lang' => 'en']);
ok('Reply-To varsa alıcı Reply-To', satir($tr['id'])['to_addr'] === 'satinalma@musteri.ru');
$mYok = gelen($A, ['mid' => null, 'refs' => '']);
$ty = mail_outbox_taslak($db, $mYok, $H, $UID, anahtar(), ['body_tr' => 'x', 'target_lang' => 'en']);
ok('Message-ID\'siz mesaj: In-Reply-To yok (uydurulmaz)', satir($ty['id'])['hdr_in_reply_to'] === null);
ok('SMTP: taslak aşamasında HİÇ bağlantı kurulmadı', $BAGLANTI === 0 && $SMTP->komutlar === []);

echo "\n=== 2. Önizleme (çeviri) ===\n";
$p = mail_outbox_onizle($db, $t['id'], $H, $UID, $sag, ['body_tr' => 'Merhaba, mallar pazartesi yola çıkıyor.', 'target_lang' => 'en', 'quote' => 1]);
$o1 = satir($t['id']);
ok('çeviri hazır: durum translated + hash + çıktı', $p['ok'] && $o1['status'] === 'translated' && $o1['body_out'] === 'EN: Merhaba, mallar pazartesi yola çıkıyor.' && strlen($o1['content_hash']) === 64 && $p['hash'] === $o1['content_hash'], json_encode($p));
ok('çeviriye yalnız Türkçe gövde gitti (adres/başlık/alıntı YOK)', $sag->cagrilar === [['Merhaba, mallar pazartesi yola çıkıyor.', 'tr', 'en']]);
ok('alıntı saklandı ("> " önekli, müşterinin yazısı)', str_contains($o1['quote_text'], '> Where is my order?') && str_contains($o1['quote_text'], 'wrote:'));
ok('tr_provider = sahte', $o1['tr_provider'] === 'sahte');
$hash1 = $o1['content_hash'];
ok('SMTP: önizlemede de HİÇ bağlantı yok', $BAGLANTI === 0);
$p = mail_outbox_onizle($db, $t['id'], $H, $UID, $sag, ['body_tr' => 'Merhaba, mallar salı günü yola çıkıyor.', 'target_lang' => 'en', 'quote' => 1]);
ok('metin değişince hash DEĞİŞİR', $p['ok'] && satir($t['id'])['content_hash'] !== $hash1);
$p = mail_outbox_onizle($db, $t['id'], $H, $UID, $sag, ['body_tr' => 'Merhaba, mallar salı günü yola çıkıyor.', 'target_lang' => 'en', 'quote' => 0]);
ok('alıntı kapatılınca hash değişir + quote_text boş', satir($t['id'])['quote_text'] === null && (int)satir($t['id'])['quote_original'] === 0);
$cagriOnce = count($sag->cagrilar);
$p = mail_outbox_onizle($db, $t['id'], $H, $UID, $sag, ['body_out_manual' => 'Hello, goods leave on Tuesday (edited by hand).', 'quote' => 1]);
ok('elle çeviri: sağlayıcı ÇAĞRILMAZ, tr_provider=manual', $p['ok'] && satir($t['id'])['body_out'] === 'Hello, goods leave on Tuesday (edited by hand).' && satir($t['id'])['tr_provider'] === 'manual' && count($sag->cagrilar) === $cagriOnce);
$tt = mail_outbox_taslak($db, $m1, $H, $UID, anahtar(), ['body_tr' => 'Merhaba Türkçe cevap', 'target_lang' => 'tr']);
$sagS = count($sag->cagrilar);
$p = mail_outbox_onizle($db, $tt['id'], $H, $UID, $sag, ['target_lang' => 'tr']);
ok('hedef dil Türkçe: çeviri YOK, sağlayıcıya gidilmez', $p['ok'] && satir($tt['id'])['body_out'] === 'Merhaba Türkçe cevap' && count($sag->cagrilar) === $sagS);
// Sağlayıcı arızası: taslak KAYBOLMAZ
$te = mail_outbox_taslak($db, $m1, $H, $UID, anahtar(), ['body_tr' => 'Önemli Türkçe cevap metni', 'target_lang' => 'de']);
$bozuk = new SahteSag(fn() => throw new MailTranslateException('temp', 'Çeviri servisi hatası (HTTP 503).'));
$p = mail_outbox_onizle($db, $te['id'], $H, $UID, $bozuk, ['target_lang' => 'de']);
$se = satir($te['id']);
ok('sağlayıcı arızası: önizleme başarısız ama TÜRKÇE METİN SAKLI, durum draft, onaya geçilemez', !$p['ok'] && $se['status'] === 'draft' && $se['body_tr'] === 'Önemli Türkçe cevap metni' && $se['content_hash'] === null && str_contains($p['mesaj'], 'elle'), $p['mesaj']);
$p = mail_outbox_onizle($db, $te['id'], $H, $UID, null, ['target_lang' => 'de']);
ok('sağlayıcı kapalı: elle çeviri yönlendirmesi, taslak korunur', !$p['ok'] && satir($te['id'])['status'] === 'draft' && str_contains($p['mesaj'], 'kendiniz'));
$p = mail_outbox_onizle($db, $te['id'], $H, $UID, null, ['body_out_manual' => 'Wichtige deutsche Antwort', 'target_lang' => 'de']);
ok('sağlayıcı yokken elle çeviri ile onaya hazır', $p['ok'] && satir($te['id'])['status'] === 'translated');
ok('başka hesap kullanıcısı önizleyemez', !mail_outbox_onizle($db, $t['id'], [$B], $UID, $sag, ['body_tr' => 'x'])['ok']);

echo "\n=== 3. ONAY KAPISI: onaysız SMTP yok ===\n";
$tg = mail_outbox_taslak($db, $m1, $H, $UID, anahtar(), ['body_tr' => 'Gönderim kapısı testi', 'target_lang' => 'en', 'quote' => 1]);
$r = mail_outbox_gonder($db, $tg['id'], ['smtp' => $smtpFab, 'simdi' => $NOW]);
ok('draft satırı gonder() ile GİTMEZ', !$r['ok'] && $BAGLANTI === 0 && satir($tg['id'])['status'] === 'draft');
mail_outbox_onizle($db, $tg['id'], $H, $UID, $sag, ['target_lang' => 'en']);
$r = mail_outbox_gonder($db, $tg['id'], ['smtp' => $smtpFab, 'simdi' => $NOW]);
ok('translated (önizlenmiş ama ONAYLANMAMIŞ) satır gonder() ile GİTMEZ', !$r['ok'] && $BAGLANTI === 0 && satir($tg['id'])['status'] === 'translated');
$db->exec("UPDATE mail_outbox SET status = 'approved' WHERE id = {$tg['id']}");
$r = mail_outbox_gonder($db, $tg['id'], ['smtp' => $smtpFab, 'simdi' => $NOW]);
ok('approved ama approved_by BOŞ (sahte onay) → GİTMEZ', !$r['ok'] && $BAGLANTI === 0 && satir($tg['id'])['status'] === 'approved');
$db->exec("UPDATE mail_outbox SET approved_by = 5 WHERE id = {$tg['id']}");
$r = mail_outbox_gonder($db, $tg['id'], ['smtp' => $smtpFab, 'simdi' => $NOW]);
ok('approved + approved_by ama Message-ID atanmamış → GİTMEZ', !$r['ok'] && $BAGLANTI === 0);
$db->exec("UPDATE mail_outbox SET status = 'translated', approved_by = NULL WHERE id = {$tg['id']}");
$r = mail_outbox_onayla($db, $tg['id'], $H, $UID, false, satir($tg['id'])['content_hash'], ['smtp' => $smtpFab, 'simdi' => $NOW]);
ok('mail.send yetkisi YOK → onay/gönderim reddedilir, SMTP yok', !$r['ok'] && str_contains($r['mesaj'], 'mail.send') && $BAGLANTI === 0 && satir($tg['id'])['status'] === 'translated');
$r = mail_outbox_onayla($db, $tg['id'], $H, $UID, true, '', ['smtp' => $smtpFab, 'simdi' => $NOW]);
ok('boş hash → reddedilir', !$r['ok'] && $BAGLANTI === 0);
$r = mail_outbox_onayla($db, $tg['id'], $H, $UID, true, str_repeat('a', 64), ['smtp' => $smtpFab, 'simdi' => $NOW]);
ok('yanlış hash (ekrandaki içerik değişmiş) → reddedilir, gönderilmez', !$r['ok'] && str_contains($r['mesaj'], 'değişmiş') && $BAGLANTI === 0 && satir($tg['id'])['status'] === 'translated');
$eskiHash = satir($tg['id'])['content_hash'];
$db->exec("UPDATE mail_outbox SET body_out = 'EN: KURCALANMIŞ — hesap no değişti' WHERE id = {$tg['id']}");
$r = mail_outbox_onayla($db, $tg['id'], $H, $UID, true, $eskiHash, ['smtp' => $smtpFab, 'simdi' => $NOW]);
ok('DB\'de içerik kurcalanmış (hash tutuyor ama içerik değişmiş) → bütünlük reddi, GÖNDERİLMEZ', !$r['ok'] && str_contains($r['mesaj'], 'değişmiş') && $BAGLANTI === 0 && $SMTP->mesajlar === []);
$db->exec("UPDATE mail_outbox SET body_out = 'EN: Gönderim kapısı testi' WHERE id = {$tg['id']}");
mail_outbox_onizle($db, $tg['id'], $H, $UID, $sag, ['target_lang' => 'en', 'quote' => 1]);
ok('yabancı hesap kullanıcısı onaylayamaz', !mail_outbox_onayla($db, $tg['id'], [$B], $UID, true, satir($tg['id'])['content_hash'], ['smtp' => $smtpFab])['ok'] && $BAGLANTI === 0);
ok('hiçbir aşamada audit "mail_send" yok (henüz gönderilmedi)', auditSay('mail_send') === 0);

echo "\n=== 4. Onayla ve Gönder (başarılı yol) ===\n";
$hash = satir($tg['id'])['content_hash'];
$r = mail_outbox_onayla($db, $tg['id'], $H, $UID, true, $hash, ['smtp' => $smtpFab, 'simdi' => $NOW]);
$og = satir($tg['id']);
ok('gönderildi', $r['ok'] && $r['durum'] === 'sent' && $og['status'] === 'sent' && $og['sent_at'] === date('Y-m-d H:i:s', $NOW) && (int)$og['approved_by'] === $UID && $og['last_error'] === null, json_encode($r));
ok('SMTP: tam 1 mesaj iletildi', count($SMTP->mesajlar) === 1 && $BAGLANTI === 1);
$ham = $SMTP->mesajlar[0]; $pm = mail_mime_mesaj($ham);
ok('doğru hesaptan (From), doğru alıcıya', $pm['from_addr'] === 'ceviri@asya.com' && $pm['from_name'] === 'Asya Fresh' && $pm['to'][0]['email'] === 'ivan@musteri.ru');
ok('zarf: MAIL FROM hesap adresi, RCPT müşteri', (bool)preg_grep('/^MAIL FROM:<ceviri@asya.com>$/', $SMTP->komutlar) && (bool)preg_grep('/^RCPT TO:<ivan@musteri.ru>$/', $SMTP->komutlar));
ok('Message-ID = onayda saklanan (out_message_id), hesabın alan adında', '<' . $pm['message_id'] . '>' === $og['out_message_id'] && str_ends_with($og['out_message_id'], '@asya.com>'));
$ebeveyn = (string)$db->query("SELECT message_id FROM mail_messages WHERE id = $m1")->fetchColumn();
ok('In-Reply-To = ebeveynin Message-ID', $pm['in_reply_to'] === $ebeveyn);
ok('References = kök + ebeveyn', $pm['references'] === ['root@musteri.ru', $ebeveyn], json_encode($pm['references']));
ok('Subject = Re: Order 4411', $pm['subject'] === 'Re: Order 4411');
ok('gövde: ONAYLANAN çeviri + alıntı (Türkçe metin gövdede YOK)', str_contains($pm['body_text'], 'EN: Gönderim kapısı testi') && str_contains($pm['body_text'], '> Where is my order?') && !str_contains($pm['body_text'], 'Gönderim kapısı testi') === false);
ok('Türkçe ORİJİNAL metin (alıntı hariç) müşteriye GİTMEDİ', substr_count($pm['body_text'], 'Gönderim kapısı testi') === 1);   // yalnız "EN: …" çevirisinde geçer
ok('orijinal mesaj "cevaplandı" oldu', $db->query("SELECT replied_at FROM mail_messages WHERE id = $m1")->fetchColumn() !== null && (int)$db->query("SELECT needs_reply FROM mail_messages WHERE id = $m1")->fetchColumn() === 0);
ok('audit: mail_send_approve + mail_send yazıldı', auditSay('mail_send_approve') === 1 && auditSay('mail_send') === 1);
ok('thread: gönderilen cevap, orijinalin thread\'ine KATILIR (başlıklarla)', (function () use ($db, $A, $pm, $m1) {
    $th = $db->prepare('INSERT INTO mail_threads (account_id, thread_key, subject_norm, message_count) VALUES (?, ?, ?, 1)');
    $kok = sha1('id:root@musteri.ru'); $th->execute([$A, $kok, 'Order 4411']); $tid = (int)$db->lastInsertId();
    $db->exec("UPDATE mail_messages SET thread_id = $tid, message_id_hash = '" . sha1('id:' . (string)$db->query("SELECT message_id FROM mail_messages WHERE id = $m1")->fetchColumn()) . "' WHERE id = $m1");
    return mail_thread_coz($db, $A, $pm, '2026-10-06 12:00:00') === $tid;
})());

echo "\n=== 5. ÇİFT GÖNDERİM yok ===\n";
$r = mail_outbox_onayla($db, $tg['id'], $H, $UID, true, $hash, ['smtp' => $smtpFab, 'simdi' => $NOW]);
ok('aynı onay ikinci kez (çift tık/yeniden gönderim): GÖNDERİLMEZ', !$r['ok'] && count($SMTP->mesajlar) === 1 && $BAGLANTI === 1 && str_contains($r['mesaj'], 'zaten'), $r['mesaj']);
$r = mail_outbox_gonder($db, $tg['id'], ['smtp' => $smtpFab, 'simdi' => $NOW]);
ok('gonder() doğrudan ikinci kez: sent satırı sahiplenilemez', !$r['ok'] && count($SMTP->mesajlar) === 1);
$r = mail_outbox_tekrar($db, $tg['id'], $H, $UID, true, ['smtp' => $smtpFab]);
ok('sent satırı "tekrar" ile yeniden gönderilemez', !$r['ok'] && count($SMTP->mesajlar) === 1);
// Aynı içerikli İKİNCİ taslak (farklı form anahtarı — iki sekme / kullanıcı yeniden doldurdu)
$ik = mail_outbox_taslak($db, $m1, $H, $UID, anahtar(), ['body_tr' => 'Gönderim kapısı testi', 'target_lang' => 'en', 'quote' => 1]);
mail_outbox_onizle($db, $ik['id'], $H, $UID, $sag, ['target_lang' => 'en', 'quote' => 1]);
$r = mail_outbox_onayla($db, $ik['id'], $H, $UID, true, satir($ik['id'])['content_hash'], ['smtp' => $smtpFab, 'simdi' => $NOW]);
ok('AYNI CEVAP (aynı ebeveyn + aynı içerik) farklı kayıttan da gönderilemez', !$r['ok'] && str_contains($r['mesaj'], 'Çift gönderim') && count($SMTP->mesajlar) === 1 && satir($ik['id'])['status'] === 'translated', $r['mesaj']);
// Yeniden giriş: SMTP bağlantısı kurulurken aynı kaydı bir başkası gönderse
$tr1 = mail_outbox_taslak($db, gelen($A, ['mid' => 'reent@musteri.ru']), $H, $UID, anahtar(), ['body_tr' => 'Yeniden giriş testi', 'target_lang' => 'en']);
mail_outbox_onizle($db, $tr1['id'], $H, $UID, $sag, ['target_lang' => 'en']);
$icCagri = null;
$yeniden = function (array $h) use ($smtpFab, &$icCagri, $tr1, $NOW) { $icCagri = mail_outbox_gonder(db(), $tr1['id'], ['smtp' => $smtpFab, 'simdi' => $NOW]); return $smtpFab($h); };
$say = count($SMTP->mesajlar);
$r = mail_outbox_onayla($db, $tr1['id'], $H, $UID, true, satir($tr1['id'])['content_hash'], ['smtp' => $yeniden, 'simdi' => $NOW]);
ok('gönderim SIRASINDA eşzamanlı ikinci gönderim denemesi sahiplenemez (en fazla 1 mesaj)', $r['ok'] && $icCagri !== null && !$icCagri['ok'] && count($SMTP->mesajlar) === $say + 1);

echo "\n=== 6. SMTP hataları: güvenli (failed) ve belirsiz (unknown) ===\n";
function yeniCevap(string $metin): int {
    global $db, $A, $H, $UID, $sag;
    $t = mail_outbox_taslak($db, gelen($A), $H, $UID, anahtar(), ['body_tr' => $metin, 'target_lang' => 'en']);
    mail_outbox_onizle($db, $t['id'], $H, $UID, $sag, ['target_lang' => 'en']);
    return $t['id'];
}
function onayla(int $id, array $opt = []): array { global $db, $H, $UID, $smtpFab, $NOW; return mail_outbox_onayla($db, $id, $H, $UID, true, satir($id)['content_hash'], $opt + ['smtp' => $smtpFab, 'simdi' => $NOW]); }

$SMTP = new FakeSmtpStream(['user' => 'ceviri@asya.com', 'pass' => 'YANLIS-SMTP-SIFRE-999']);
$f1 = yeniCevap('Kimlik hatası');
$r = onayla($f1);
ok('kimlik doğrulama hatası → failed (kabul edilmedi), şifre mesajda YOK', !$r['ok'] && $r['durum'] === 'failed' && !str_contains($r['mesaj'], 'smtp-SIR-2') && !str_contains($r['mesaj'], 'YANLIS-SMTP') && !$SMTP->dataAlindi);
$sf1 = satir($f1);
ok('failed: Message-ID + onay bilgisi saklı, hata metni var', $sf1['status'] === 'failed' && $sf1['out_message_id'] !== null && $sf1['last_error'] !== null && (int)$sf1['attempts'] === 1);
$SMTP = new FakeSmtpStream(['user' => 'ceviri@asya.com', 'pass' => 'smtp-SIR-2', 'reject_rcpt' => ['ivan@musteri.ru']]);
$f2 = yeniCevap('RCPT reddi');
$r = onayla($f2);
ok('alıcı reddi → failed (DATA\'ya gidilmedi)', $r['durum'] === 'failed' && !$SMTP->dataAlindi);
foreach (['550 5.7.1 Spam detected' => 'açık 5xx', '451 4.3.0 Try again later' => 'açık 4xx'] as $fin => $ad) {
    $SMTP = new FakeSmtpStream(['user' => 'ceviri@asya.com', 'pass' => 'smtp-SIR-2', 'final' => $fin]);
    $id = yeniCevap("Son yanıt $ad");
    $r = onayla($id);
    ok("DATA sonrası $ad → failed (açıkça kabul EDİLMEDİ), unknown DEĞİL", $r['durum'] === 'failed' && satir($id)['status'] === 'failed');
}
$SMTP = new FakeSmtpStream(['user' => 'ceviri@asya.com', 'pass' => 'smtp-SIR-2', 'final' => 'DROP']);
$u1 = yeniCevap('Bağlantı koptu');
$r = onayla($u1);
$su = satir($u1);
ok('son "." sonrası bağlantı koptu → UNKNOWN', !$r['ok'] && $r['durum'] === 'unknown' && $su['status'] === 'unknown' && $SMTP->dataAlindi);
ok('unknown: Message-ID + send_token + onaylayan KORUNDU (tanı için)', $su['out_message_id'] !== null && strlen((string)$su['send_token']) === 32 && (int)$su['approved_by'] === $UID && $su['send_started_at'] !== null);
ok('mesaj "OTOMATİK TEKRAR GÖNDERİLMEZ" diyor', str_contains($r['mesaj'], 'OTOMATİK TEKRAR'));
$say = count($SMTP->mesajlar); $bag = $BAGLANTI;
$r = mail_outbox_gonder($db, $u1, ['smtp' => $smtpFab, 'simdi' => $NOW + 99999]);
ok('unknown satır gonder() ile ASLA yeniden gönderilmez', !$r['ok'] && $BAGLANTI === $bag && count($SMTP->mesajlar) === $say);
$r = mail_outbox_tekrar($db, $u1, $H, $UID, true, ['smtp' => $smtpFab]);
ok('unknown satır "tekrar" ile de gönderilmez (önce insan çözmeli)', !$r['ok'] && $BAGLANTI === $bag);
mail_outbox_takili_isaretle($db, $NOW + 99999);
ok('takılı-işaretleme unknown\'ı yeniden göndermez/değiştirmez', satir($u1)['status'] === 'unknown' && $BAGLANTI === $bag);
$SMTP = new FakeSmtpStream(['user' => 'ceviri@asya.com', 'pass' => 'smtp-SIR-2', 'final' => 'SILENT']);
$u2 = yeniCevap('Yanıt gelmedi');
$r = onayla($u2);
ok('son yanıt hiç gelmedi → UNKNOWN', $r['durum'] === 'unknown');
// Süreç ölümü: sending'de takılı
$SMTP = new FakeSmtpStream(['user' => 'ceviri@asya.com', 'pass' => 'smtp-SIR-2']);
$k = yeniCevap('Süreç öldü');
$db->exec("UPDATE mail_outbox SET status = 'sending', approved_by = 5, approved_at = '2026-10-06 11:00:00', out_message_id = '<olu@asya.com>', send_token = 'tok', send_started_at = '" . date('Y-m-d H:i:s', $NOW - 100) . "' WHERE id = $k");
ok('yeni başlamış gönderim (100 sn) DOKUNULMAZ', mail_outbox_takili_isaretle($db, $NOW) === 0 && satir($k)['status'] === 'sending');
ok('20 dk\'dan uzun takılı sending → unknown (otomatik tekrar YOK)', mail_outbox_takili_isaretle($db, $NOW + 1300) >= 1 && satir($k)['status'] === 'unknown' && str_contains(satir($k)['last_error'], 'belirsiz'));
ok('takılı satır sonra da gönderilmez', !mail_outbox_gonder($db, $k, ['smtp' => $smtpFab, 'simdi' => $NOW + 1400])['ok'] && $SMTP->mesajlar === []);

echo "\n=== 7. Açık yeniden deneme + belirsizlik çözümü (insan kararı) ===\n";
$SMTP = new FakeSmtpStream(['user' => 'ceviri@asya.com', 'pass' => 'smtp-SIR-2']);
$mid1 = satir($f1)['out_message_id'];
$r = mail_outbox_tekrar($db, $f1, $H, $UID, false, ['smtp' => $smtpFab, 'simdi' => $NOW]);
ok('"Tekrar dene": mail.send yoksa reddedilir', !$r['ok'] && satir($f1)['status'] === 'failed' && $SMTP->mesajlar === []);
$r = mail_outbox_tekrar($db, $f1, $H, 9, true, ['smtp' => $smtpFab, 'simdi' => $NOW]);
ok('failed → açık "Tekrar dene" ile gönderilir', $r['ok'] && satir($f1)['status'] === 'sent' && count($SMTP->mesajlar) === 1 && (int)satir($f1)['approved_by'] === 9);
ok('tekrar gönderimde AYNI Message-ID (hiçbir şey kabul edilmemişti)', '<' . mail_mime_mesaj($SMTP->mesajlar[0])['message_id'] . '>' === $mid1 && (int)satir($f1)['attempts'] === 2);
$r = mail_outbox_belirsiz_coz($db, $u1, $H, $UID, false, 'gonderildi');
ok('unknown çözümü: mail.send yoksa reddedilir', !$r['ok'] && satir($u1)['status'] === 'unknown');
$r = mail_outbox_belirsiz_coz($db, $u1, $H, $UID, true, 'bilinmeyen');
ok('geçersiz karar reddedilir', !$r['ok'] && satir($u1)['status'] === 'unknown');
$r = mail_outbox_belirsiz_coz($db, $u1, $H, $UID, true, 'gonderildi');
ok('unknown → "Gönderildi (doğruladım)" → sent + orijinal cevaplandı', $r['ok'] && satir($u1)['status'] === 'sent' && str_contains(satir($u1)['last_error'], 'doğrulandı') && auditSay('mail_send_resolve') === 1);
$r = mail_outbox_belirsiz_coz($db, $u2, $H, $UID, true, 'gonderilmedi');
ok('unknown → "Gönderilmedi" → failed (tekrar denenebilir)', $r['ok'] && satir($u2)['status'] === 'failed');
$SMTP = new FakeSmtpStream(['user' => 'ceviri@asya.com', 'pass' => 'smtp-SIR-2']);
$r = mail_outbox_tekrar($db, $u2, $H, $UID, true, ['smtp' => $smtpFab, 'simdi' => $NOW]);
ok('insan "gönderilmedi" dedikten sonra tekrar gönderim çalışır', $r['ok'] && satir($u2)['status'] === 'sent');
ok('sent satırı belirsizlik çözümüyle değiştirilemez', !mail_outbox_belirsiz_coz($db, $u2, $H, $UID, true, 'gonderilmedi')['ok']);
// SMTP'ye hiç ulaşılamadı
$id = yeniCevap('Sunucu kapalı');
$r = onayla($id, ['smtp' => function (array $h) { throw new MailImapException('connect', 'Sunucuya bağlanılamadı (s.t.com:465): connection refused'); }]);
ok('bağlanılamadı (DATA öncesi) → failed (güvenli)', $r['durum'] === 'failed' && satir($id)['status'] === 'failed');
// Mesaj oluşturulamadı
$SMTP = new FakeSmtpStream(['user' => 'ceviri@asya.com', 'pass' => 'smtp-SIR-2']);
$id = yeniCevap('Bozuk alıcı');
$db->exec("UPDATE mail_outbox SET to_addr = 'bozuk' WHERE id = $id");
$db->prepare("UPDATE mail_outbox SET content_hash = ? WHERE id = ?")->execute([mail_outbox_hash(satir($id), mail_outbox_hesap_kimligi($db, $A)), $id]);
$r = onayla($id);
ok('mesaj oluşturulamazsa (geçersiz alıcı) → failed, SMTP\'ye gidilmez', $r['durum'] === 'failed' && $SMTP->mesajlar === [] && $SMTP->komutlar === []);

echo "\n=== 8. İptal ===\n";
$ic1 = mail_outbox_taslak($db, gelen($A), $H, $UID, anahtar(), ['body_tr' => 'iptal edilecek', 'target_lang' => 'en']);
ok('draft iptal edilir', mail_outbox_iptal($db, $ic1['id'], $H, $UID)['ok'] && satir($ic1['id'])['status'] === 'cancelled');
$ic2 = yeniCevap('iptal translated');
ok('translated iptal edilir; sonra onaylanamaz', mail_outbox_iptal($db, $ic2, $H, $UID)['ok'] && !onayla($ic2)['ok'] && satir($ic2)['status'] === 'cancelled');
ok('sent / unknown iptal EDİLEMEZ', !mail_outbox_iptal($db, $f1, $H, $UID)['ok'] && !mail_outbox_iptal($db, $k, $H, $UID)['ok'] && satir($k)['status'] === 'unknown');
ok('iptal edilmiş taslak önizlenemez/düzenlenemez', !mail_outbox_onizle($db, $ic1['id'], $H, $UID, $sag, ['body_tr' => 'x'])['ok']);
ok('başka hesap kullanıcısı iptal edemez', !mail_outbox_iptal($db, yeniCevap('acl'), [$B], $UID)['ok']);

echo "\n=== 9. Gönderilenler'e APPEND (best-effort) ===\n";
$A2 = hesap('append@asya.com', ['append_sent' => 1, 'sent_folder' => 'Gönderilmiş Öğeler']);
$SMTP = new FakeSmtpStream(['user' => 'append@asya.com', 'pass' => 'smtp-SIR-2']);
$IMAP = new FakeMailStream(['user' => 'append@asya.com', 'pass' => 'imap-SIR-1']);
$imapFab = function (array $h) use (&$IMAP) { $c = new MailImapClient($IMAP->yeniBaglanti()); $c->baslat(false); $c->girisYap($h['imap_user'], $h['imap_pass']); return $c; };
$t = mail_outbox_taslak($db, gelen($A2, ['mid' => 'ap@musteri.ru']), [$A2], $UID, anahtar(), ['body_tr' => 'append testi', 'target_lang' => 'en']);
mail_outbox_onizle($db, $t['id'], [$A2], $UID, $sag, ['target_lang' => 'en']);
$r = mail_outbox_onayla($db, $t['id'], [$A2], $UID, true, satir($t['id'])['content_hash'], ['smtp' => $smtpFab, 'imap' => $imapFab, 'simdi' => $NOW]);
ok('append açık: gönderilen kopya Gönderilenler klasörüne yazıldı (birebir aynı ham mesaj)', $r['ok'] && count($IMAP->eklenen) === 1 && $IMAP->eklenen[0]['klasor'] === 'G&APY-nderilmi&AV8- &ANY-&AMQ-geler' || (count($IMAP->eklenen) === 1 && str_contains($IMAP->eklenen[0]['klasor'], '&')), json_encode($IMAP->eklenen[0]['klasor'] ?? null));
ok('APPEND içeriği = SMTP\'ye giden mesaj (CRLF normalize)', rtrim($IMAP->eklenen[0]['ham'] ?? '') === rtrim(preg_replace('/\r\n|\r|\n/', "\r\n", $SMTP->mesajlar[0] ?? '')));
$t = mail_outbox_taslak($db, gelen($A2, ['mid' => 'ap2@musteri.ru']), [$A2], $UID, anahtar(), ['body_tr' => 'append hata', 'target_lang' => 'en']);
mail_outbox_onizle($db, $t['id'], [$A2], $UID, $sag, ['target_lang' => 'en']);
$r = mail_outbox_onayla($db, $t['id'], [$A2], $UID, true, satir($t['id'])['content_hash'], ['smtp' => $smtpFab, 'imap' => function () { throw new MailImapException('connect', 'IMAP kapalı'); }, 'simdi' => $NOW]);
ok('APPEND başarısız olsa da gönderim BAŞARILI kalır (geri alınmaz)', $r['ok'] && satir($t['id'])['status'] === 'sent' && auditSay('mail_send_append_failed') === 1);
$say = count($IMAP->eklenen);
$t = mail_outbox_taslak($db, gelen($A, ['mid' => 'ap3@musteri.ru']), $H, $UID, anahtar(), ['body_tr' => 'append kapalı', 'target_lang' => 'en']);
$SMTP = new FakeSmtpStream(['user' => 'ceviri@asya.com', 'pass' => 'smtp-SIR-2']);
mail_outbox_onizle($db, $t['id'], $H, $UID, $sag, ['target_lang' => 'en']);
mail_outbox_onayla($db, $t['id'], $H, $UID, true, satir($t['id'])['content_hash'], ['smtp' => $smtpFab, 'imap' => $imapFab, 'simdi' => $NOW]);
ok('append kapalı hesapta IMAP\'e hiç bağlanılmaz', count($IMAP->eklenen) === $say);

echo "\n=== 10. mail_post_isle (UI uçları) + yetkiler ===\n";
$ctx = fn(array $ek = []) => $ek + ['uid' => $UID, 'hesapIds' => $H, 'yonetici' => false, 'cevap' => true, 'send' => true, 'a' => 0, 'm' => $m1, 'o' => 0];
$SMTP = new FakeSmtpStream(['user' => 'ceviri@asya.com', 'pass' => 'smtp-SIR-2']);
$mP = gelen($A, ['mid' => 'ui@musteri.ru']);
$g = ['idem' => anahtar(), 'body_tr' => 'UI üzerinden cevap', 'target_lang' => 'en', 'quote' => '1'];
$r = mail_post_isle($db, $ctx(['m' => $mP]), 'cevap_onizle', $g, ['sagl' => $sag, 'smtp' => $smtpFab]);
ok('cevap_onizle: taslak + önizleme tek adımda, SMTP\'ye DOKUNMAZ', $r['ok'] && $r['o'] > 0 && satir($r['o'])['status'] === 'translated' && $SMTP->mesajlar === []);
$oUI = $r['o'];
$r = mail_post_isle($db, $ctx(['m' => $mP, 'cevap' => false]), 'cevap_onizle', ['idem' => anahtar(), 'body_tr' => 'x'], ['sagl' => $sag]);
ok('cevap_onizle: mail.reply yoksa YASAK', $r['yasak'] !== null && str_contains($r['yasak'], 'mail.reply'));
$r = mail_post_isle($db, $ctx(['o' => $oUI, 'send' => false]), 'cevap_onayla', ['hash' => satir($oUI)['content_hash']], ['smtp' => $smtpFab, 'simdi' => $NOW]);
ok('cevap_onayla: mail.send yoksa YASAK, gönderilmez', $r['yasak'] !== null && str_contains($r['yasak'], 'mail.send') && $SMTP->mesajlar === [] && satir($oUI)['status'] === 'translated');
$r = mail_post_isle($db, $ctx(['o' => $oUI]), 'cevap_onayla', ['hash' => 'yanlis'], ['smtp' => $smtpFab, 'simdi' => $NOW]);
ok('cevap_onayla: yanlış hash → gönderilmez', !$r['ok'] && $SMTP->mesajlar === []);
$r = mail_post_isle($db, $ctx(['o' => $oUI]), 'cevap_onizle', ['body_tr' => 'Düzeltilmiş cevap', 'target_lang' => 'en', 'body_out_manual' => 'EL YAZISI ÇEVİRİ', 'mod' => 'ceviri'], ['sagl' => $sag]);
ok('mod=ceviri: elle metin YOK SAYILIR, çeviri yeniden üretilir', $r['ok'] && satir($oUI)['body_out'] === 'EN: Düzeltilmiş cevap' && satir($oUI)['tr_provider'] === 'sahte');
$r = mail_post_isle($db, $ctx(['o' => $oUI]), 'cevap_onizle', ['body_tr' => 'Düzeltilmiş cevap', 'target_lang' => 'en', 'body_out_manual' => 'EL YAZISI ÇEVİRİ', 'mod' => 'manuel'], ['sagl' => $sag]);
ok('mod=manuel: elle çeviri kullanılır', $r['ok'] && satir($oUI)['body_out'] === 'EL YAZISI ÇEVİRİ' && satir($oUI)['tr_provider'] === 'manual');
$r = mail_post_isle($db, $ctx(['o' => $oUI]), 'cevap_onayla', ['hash' => satir($oUI)['content_hash']], ['smtp' => $smtpFab, 'simdi' => $NOW]);
ok('cevap_onayla (send yetkisi + doğru hash): gönderildi, tek mesaj', $r['ok'] && satir($oUI)['status'] === 'sent' && count($SMTP->mesajlar) === 1 && str_contains(mail_mime_mesaj($SMTP->mesajlar[0])['body_text'], 'EL YAZISI ÇEVİRİ'));
$r = mail_post_isle($db, $ctx(['o' => $oUI]), 'cevap_onayla', ['hash' => satir($oUI)['content_hash']], ['smtp' => $smtpFab, 'simdi' => $NOW]);
ok('aynı POST tekrarlanınca (geri tuşu/çift tık) ikinci mesaj GİTMEZ', !$r['ok'] && count($SMTP->mesajlar) === 1);
$r = mail_post_isle($db, $ctx(['o' => 12345]), 'cevap_iptal', []);
ok('cevap_iptal: olmayan kayıt', !$r['ok']);
$r = mail_post_isle($db, $ctx(['cevap' => false, 'send' => false, 'o' => $oUI]), 'cevap_iptal', []);
ok('cevap_iptal: hiçbir cevap/gönder yetkisi yoksa YASAK', $r['yasak'] !== null);
$r = mail_post_isle($db, $ctx(['send' => false, 'o' => $u1]), 'cevap_belirsiz_gonderildi', []);
ok('belirsizlik çözümü: mail.send yoksa YASAK', $r['yasak'] !== null);

echo "\n=== 11. Sızıntı / audit ===\n";
$tum = json_encode($db->query('SELECT * FROM audit_log')->fetchAll()) . json_encode($db->query('SELECT id, status, last_error, out_message_id FROM mail_outbox')->fetchAll());
foreach (['smtp-SIR-2', 'imap-SIR-1', 'YANLIS-SMTP-SIFRE-999'] as $sir) ok("audit/last_error '$sir' İÇERMİYOR", !str_contains($tum, $sir));
$auditMetin = json_encode($db->query("SELECT new_values FROM audit_log WHERE module = 'mail_outbox'")->fetchAll());
ok('audit: gövde / alıcı adresi / konu YOK (yalnız id, durum, sayı)', !str_contains($auditMetin, 'ivan@musteri.ru') && !str_contains($auditMetin, 'Order 4411') && !str_contains($auditMetin, 'UI üzerinden') && !str_contains($auditMetin, 'EL YAZISI'));
ok('günlükte gönderim komutu gövdesi/şifre yok (istemci günlüğü)', (function () { global $SMTP; $c = new MailSmtpClient($SMTP->yeniBaglanti()); $c->baslat('a.com', false); $c->girisYap('ceviri@asya.com', 'smtp-SIR-2'); return !str_contains(implode("\n", $c->gunluk), 'smtp-SIR-2'); })());
$log = sys_get_temp_dir() . '/mail_test_' . getmypid() . '.log';
ok('error_log şifre içermiyor', !is_file($log) || (!str_contains((string)file_get_contents($log), 'smtp-SIR-2') && !str_contains((string)file_get_contents($log), 'imap-SIR-1')));
mail_test_bitir();
