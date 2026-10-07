<?php
// =========================================================
// scripts/mail_outbox_review_smoke.php — M5 bağımsız (Opus) inceleme bulgularının REGRESYON testleri
// Her bölüm bir bulgunun deterministik yeniden üretimidir (yarışlar `_kanca_*` kancalarıyla zorlanır).
//   php scripts/mail_outbox_review_smoke.php
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

final class Sag implements MailTranslationProviderInterface {
    public function ad(): string { return 'sahte'; }
    public function parcaLimiti(): int { return 4000; }
    public function cevir(string $metin, ?string $kaynak, string $hedef): array { return ['metin' => strtoupper($hedef) . ': ' . $metin, 'tespit' => 'tr']; }
}
function hesap(string $e): int {
    global $db;
    return mail_hesap_kaydet(['label' => $e, 'email' => $e, 'imap_host' => 'i.t.com', 'imap_user' => $e, 'imap_pass' => 'imap-SIR-1', 'smtp_host' => 's.t.com', 'smtp_user' => $e, 'smtp_pass' => 'smtp-SIR-2', 'display_name' => 'Asya Fresh'], null, 1, $db)['id'];
}
function gelen(int $acc, string $mid, array $o = []): int {
    global $db; static $uid = 0; $uid++;
    $o += ['subject' => 'Re: Order 4411', 'from' => 'ivan@musteri.ru', 'reply_to' => ''];
    $db->prepare("INSERT INTO mail_messages (account_id, folder, uidvalidity, uid, message_id, message_id_hash, references_hdr, subject, from_name, from_addr, reply_to_addr, received_at, body_text, lang, to_addrs, cc_addrs)
        VALUES (?, 'INBOX', 1, ?, ?, ?, 'root@musteri.ru', ?, 'Ivan', ?, ?, '2026-10-05 10:00:00', 'Where is my order?', 'en', '[]', '[]')")
        ->execute([$acc, $uid, $mid, mail_mime_id_hash($mid), $o['subject'], $o['from'], $o['reply_to']]);
    return (int)$db->lastInsertId();
}
$SMTP = new FakeSmtpStream(['user' => 'rv@asya.com', 'pass' => 'smtp-SIR-2']);
$BAG = 0;
$fab = function (array $h) use (&$SMTP, &$BAG) { $BAG++; $c = new MailSmtpClient($SMTP->yeniBaglanti()); $c->baslat('asya.com', false); $c->girisYap($h['smtp_user'], $h['smtp_pass']); return $c; };
function satir(int $id): array { global $db; return $db->query("SELECT * FROM mail_outbox WHERE id = $id")->fetch(); }
function anahtar(): string { return bin2hex(random_bytes(16)); }
function auditSay(string $e): int { global $db; return (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = '$e'")->fetchColumn(); }
$A = hesap('rv@asya.com'); $H = [$A]; $UID = 5; $sag = new Sag();
/** translated (onaya hazır) bir cevap üretir. */
function hazir(int $msg, string $govde, array $ek = []): int {
    global $db, $H, $UID, $sag;
    $t = mail_outbox_taslak($db, $msg, $H, $UID, anahtar(), ['body_tr' => $govde, 'target_lang' => 'en'] + $ek);
    $p = mail_outbox_onizle($db, $t['id'], $H, $UID, $sag, ['target_lang' => 'en']);
    if (!$p['ok']) throw new RuntimeException('önizleme: ' . $p['mesaj']);
    return $t['id'];
}
function onayla(int $id, array $opt = []): array {
    global $db, $H, $UID, $fab, $NOW;
    return mail_outbox_onayla($db, $id, $H, 8, true, satir($id)['content_hash'], array_merge(['smtp' => $fab, 'simdi' => $NOW], $opt));
}

echo "=== B1. Önizleme ↔ onay yarışı: onaylanmış içerik ASLA ezilmez ===\n";
$m = gelen($A, 'b1@musteri.ru');
$id = hazir($m, 'İlk metin');
$h0 = satir($id)['content_hash'];
$r = onayla($id);
ok('hazırlık: onay + gönderim', $r['ok'] && satir($id)['status'] === 'sent');
$once = satir($id);
$p = mail_outbox_onizle($db, $id, $H, $UID, $sag, ['body_tr' => 'ONAYDAN SONRA DEĞİŞTİRİLDİ', 'target_lang' => 'en']);
$son = satir($id);
ok('sent satır önizlemeyle değiştirilemez; içerik/hash/durum aynen', !$p['ok'] && $son['body_out'] === $once['body_out'] && $son['content_hash'] === $once['content_hash'] && $son['status'] === 'sent');
// Eski sürümü okuyan istek (hash değişmiş) → koşullu yazma 0 satır
$id2 = hazir(gelen($A, 'b1b@musteri.ru'), 'Metin A');
$eskiHash = satir($id2)['content_hash'];
mail_outbox_onizle($db, $id2, $H, $UID, $sag, ['body_tr' => 'Metin B', 'target_lang' => 'en']);   // başka bir sekme güncelledi
ok('yaz(): okunan sürümün hash\'i değişmişse 0 satır (kayıp güncelleme yok)', mail_outbox_yaz($db, $id2, ['body_out' => 'EZİLDİ'], ['status' => ['draft', 'translated'], 'content_hash' => $eskiHash]) === false && satir($id2)['body_out'] !== 'EZİLDİ');
try { mail_outbox_yaz($db, $id2, ['body_out' => 'x'], []); $lg = false; } catch (LogicException $e) { $lg = true; }
ok('yaz(): koşulsuz çağrı programlama hatası (LogicException)', $lg);
// onay yazımı: hash değişmişse onay verilemez
$id3 = hazir(gelen($A, 'b1c@musteri.ru'), 'Onay öncesi');
$eski3 = satir($id3)['content_hash'];
$r = onayla($id3, ['_kanca_onay_oncesi' => function () use ($db, $id3, $H, $UID, $sag) { mail_outbox_onizle($db, $id3, $H, $UID, $sag, ['body_tr' => 'ARADA DEĞİŞTİ', 'target_lang' => 'en']); }]);
ok('onay penceresinde içerik değişirse onay YAZILMAZ, gönderilmez', !$r['ok'] && satir($id3)['status'] === 'translated' && satir($id3)['approved_by'] === null && $SMTP->mesajlar !== [] && count(array_filter($SMTP->mesajlar, fn($x) => str_contains($x, 'ARADA'))) === 0);
ok('değişen içerik yeni hash ile onaya hazır, eski onay hash\'i geçersiz', satir($id3)['content_hash'] !== $eski3);

echo "\n=== B2. İkiz satır yarışı: aynı cevap iki kez gitmez (UNIQUE dedupe_key) ===\n";
$mp = gelen($A, 'b2@musteri.ru');
$a = hazir($mp, 'Aynı cevap metni'); $b = hazir($mp, 'Aynı cevap metni');
ok('iki farklı satır AYNI içerik hash\'ine sahip', satir($a)['content_hash'] === satir($b)['content_hash'] && $a !== $b);
$say0 = count($SMTP->mesajlar);
$r = onayla($b, ['_kanca_onay_oncesi' => function () use ($a, $db, $H, $fab, $NOW) { mail_outbox_onayla($db, $a, $H, 8, true, satir($a)['content_hash'], ['smtp' => $fab, 'simdi' => $NOW]); }]);
ok('ön kontrolü atlatan yarış: ikinci satır UNIQUE ile yapısal olarak engellendi', !$r['ok'] && str_contains($r['mesaj'], 'Çift gönderim') && satir($b)['status'] === 'translated' && satir($a)['status'] === 'sent');
ok('SMTP\'ye yalnız BİR mesaj gitti', count($SMTP->mesajlar) - $say0 === 1);
$r = onayla($b);
ok('sonradan deneme de (ön kontrol) reddedilir', !$r['ok'] && count($SMTP->mesajlar) - $say0 === 1);
ok('onaylanmış satırın dedupe_key + approved_hash dolu', satir($a)['dedupe_key'] !== null && satir($a)['approved_hash'] === satir($a)['content_hash']);
mail_outbox_iptal($db, $b, $H, $UID);
ok('iptal edilen taslağın dedupe_key\'i yok', satir($b)['dedupe_key'] === null);

echo "\n=== B3. Süpürme ↔ uçuştaki gönderim ===\n";
$id = hazir(gelen($A, 'b3@musteri.ru'), 'Uçuş testi');
$say0 = count($SMTP->mesajlar);
$fabSupur = function (array $h) use ($fab, $db, $NOW) { $c = $fab($h); mail_outbox_takili_isaretle($db, $NOW + 5000); return $c; };   // SMTP sürerken süpürme çalışır
$r = onayla($id, ['smtp' => $fabSupur]);
ok('süpürme gönderim sürerken satırı unknown yaptı AMA mesaj gitti → durum sent (yanlış unknown kalmaz)', $r['ok'] && satir($id)['status'] === 'sent' && count($SMTP->mesajlar) - $say0 === 1, json_encode($r) . satir($id)['status']);
// yeni gönderim (100 sn) süpürülmez; sınır 20 dk
$id = hazir(gelen($A, 'b3b@musteri.ru'), 'Süpürme sınırı');
$db->exec("UPDATE mail_outbox SET status='sending', approved_by=5, approved_at='2026-10-06 11:00:00', out_message_id='<s1@asya.com>', send_token='t', approved_hash=content_hash, send_started_at='" . date('Y-m-d H:i:s', $NOW - 900) . "' WHERE id = $id");
ok('15 dk önce başlayan gönderim hâlâ "devam ediyor" sayılır (SMTP oturum üst sınırı 5 dk)', mail_outbox_takili_isaretle($db, $NOW) === 0 && satir($id)['status'] === 'sending');
ok('20 dk geçince unknown + audit', mail_outbox_takili_isaretle($db, $NOW + 400) === 1 && satir($id)['status'] === 'unknown' && auditSay('mail_send_unknown') >= 1);
// onaylı ama gönderilmemiş eski onay geri alınır
$id = hazir(gelen($A, 'b3c@musteri.ru'), 'Bayat onay');
$db->exec("UPDATE mail_outbox SET status='approved', approved_by=5, approved_at='2026-10-06 10:00:00', out_message_id='<b@asya.com>', approved_hash=content_hash, dedupe_key='k1' WHERE id = $id");
mail_outbox_takili_isaretle($db, $NOW);
$s = satir($id);
ok('30 dk\'dan eski onaylı-gönderilmemiş satır translated\'a döner, onay + dedupe + Message-ID temizlenir', $s['status'] === 'translated' && $s['approved_by'] === null && $s['approved_hash'] === null && $s['dedupe_key'] === null && $s['out_message_id'] === null);

$id = hazir(gelen($A, 'b3d@musteri.ru'), 'Hash uyuşmazlığı');
$db->exec("UPDATE mail_outbox SET status='approved', approved_by=5, approved_at='2026-10-06 11:59:00', out_message_id='<h1@asya.com>', approved_hash='" . str_repeat('0', 64) . "' WHERE id = $id");
$bag0 = $BAG;
$r = mail_outbox_gonder($db, $id, ['smtp' => $fab, 'simdi' => $NOW]);
ok('approved_hash ≠ content_hash (kayıt onaydan sonra oynanmış) → sahiplenilemez, SMTP yok', !$r['ok'] && $BAG === $bag0 && satir($id)['status'] === 'approved');

echo "\n=== B4. Sahiplik kaybı + kimlik değişimi + pasif hesap ===\n";
$id = hazir(gelen($A, 'b4@musteri.ru'), 'Sahiplik testi');
$say0 = count($SMTP->mesajlar); $bag0 = $BAG;
$r = onayla($id, ['_kanca_talep_sonrasi' => function () use ($db, $id) { $db->exec("UPDATE mail_outbox SET send_token = 'BASKASI' WHERE id = $id"); }]);
ok('sahiplenmeden sonra token değişti → ABORT, SMTP\'ye gidilmedi', !$r['ok'] && $BAG === $bag0 && count($SMTP->mesajlar) === $say0 && auditSay('mail_send_failed') >= 1);
// kimlik değişimi (onay ↔ gönderim arası)
$id = hazir(gelen($A, 'b4b@musteri.ru'), 'Kimlik testi');
$bag0 = $BAG;
$r = onayla($id, ['_kanca_onay_oncesi' => function () use ($db, $A) { $db->exec("UPDATE mail_accounts SET reply_to = 'saldirgan@evil.test' WHERE id = $A"); }]);
$s = satir($id);
ok('onay sonrası hesap Reply-To değişti → bütünlük reddi, SMTP\'ye gidilmedi', !$r['ok'] && $BAG === $bag0 && $s['status'] === 'failed' && str_contains((string)$s['last_error'], 'bütünlük'), json_encode($r));
$db->exec("UPDATE mail_accounts SET reply_to = NULL WHERE id = $A");
$id = hazir(gelen($A, 'b4c@musteri.ru'), 'Gönderen adı');
$db->exec("UPDATE mail_accounts SET display_name = 'Başkası' WHERE id = $A");
$r = onayla($id);
ok('önizleme sonrası görünen ad değişti → onay reddedilir (hash gönderen kimliğini kapsar)', !$r['ok'] && satir($id)['status'] === 'translated');
$db->exec("UPDATE mail_accounts SET display_name = 'Asya Fresh' WHERE id = $A");
$id = hazir(gelen($A, 'b4d@musteri.ru'), 'Pasif hesap');
$bag0 = $BAG;
$r = onayla($id, ['_kanca_onay_oncesi' => function () use ($db, $A) { $db->exec("UPDATE mail_accounts SET is_active = 0 WHERE id = $A"); }]);
ok('hesap pasifleştirildi → gönderilmez', !$r['ok'] && $BAG === $bag0);
$db->exec("UPDATE mail_accounts SET is_active = 1 WHERE id = $A");

echo "\n=== B5. SMTP yanıt sınıflaması + başlık güvenliği ===\n";
foreach (['250' => ['sent', 'çıplak 250 (metinsiz) kabul'], '354 go ahead' => ['unknown', 'DATA sonrası 3xx → belirsiz'], '199 hmm' => ['unknown', '1xx → belirsiz'], '452 disk' => ['failed', 'açık 4xx → kabul edilmedi'], '550 no' => ['failed', 'açık 5xx → kabul edilmedi']] as $fin => [$beklenen, $ad]) {
    $SMTP = new FakeSmtpStream(['user' => 'rv@asya.com', 'pass' => 'smtp-SIR-2', 'final' => $fin]);
    $id = hazir(gelen($A, 'b5' . random_int(1, 1e9) . '@musteri.ru'), "Yanıt $fin");
    $r = onayla($id);
    ok("$ad", $r['durum'] === $beklenen && satir($id)['status'] === $beklenen, $r['durum'] . ' ' . $r['mesaj']);
}
$SMTP = new FakeSmtpStream(['user' => 'rv@asya.com', 'pass' => 'smtp-SIR-2']);
ok('"=?" içeren ASCII konu kodlanır (encoded-word sahteciliği)', str_starts_with(mail_baslik_kodla('Fwd =?UTF-8?B?U1BPT0Y=?= x'), '=?UTF-8?B?') && mail_baslik_kodla('Düz konu 123') !== 'Düz konu 123' && mail_baslik_kodla('Plain subject') === 'Plain subject');
$ham = mail_giden_mesaj(['from_email' => 'rv@asya.com', 'from_name' => 'A', 'to' => [['name' => '', 'email' => 'x@y.com']], 'subject' => 'Re: =?UTF-8?B?U1BPT0Y=?=', 'body' => 'x', 'message_id' => '<m1@asya.com>', 'in_reply_to' => '<Parent.AbC@Musteri.RU>', 'references' => ['<Root@X.com>', '<Parent.AbC@Musteri.RU>'], 'date' => $NOW]);
ok('Message-ID büyük/küçük harfi başlıkta AYNEN korunur', str_contains($ham, 'In-Reply-To: <Parent.AbC@Musteri.RU>') && str_contains($ham, '<Root@X.com> <Parent.AbC@Musteri.RU>'));
$p = mail_mime_mesaj($ham);
ok('ham konu başlığı kodlu (düz "=?" yok)', !preg_match('/^Subject:\s*Re: =\?UTF-8\?B\?U1BPT0Y/mi', $ham));
// büyük harfli Message-ID ile gelen mailin cevabı: taslak başlıkları orijinal yazımı taşır
$mU = gelen($A, 'Upper.Case@Musteri.RU');
$t = mail_outbox_taslak($db, $mU, $H, $UID, anahtar(), ['body_tr' => 'x', 'target_lang' => 'en']);
ok('taslak In-Reply-To orijinal yazımı korur', satir($t['id'])['hdr_in_reply_to'] === '<Upper.Case@Musteri.RU>', (string)satir($t['id'])['hdr_in_reply_to']);

echo "\n=== B6. Reply-To farklıysa panel uyarır; alıntı açık gelir ===\n";
$kaynak = (string)file_get_contents($ROOT . '/mail.php');
ok('Reply-To ≠ From uyarısı onay panelinde', str_contains($kaynak, 'Reply-To farklı') && str_contains($kaynak, '$rtFarkli'));
ok('onay panelinde gönderen kimliği gösterilir', str_contains($kaynak, '<dt>Gönderen</dt>'));
ok('translated durumunda alıntı varsayılan AÇIK', str_contains($kaynak, "\$os === 'translated' ? 'open' : ''") && str_contains($kaynak, 'quote_text'));

echo "\n=== B7. IMAP APPEND devam isteği tek sefer ===\n";
$IM = new FakeMailStream(['user' => 'rv@asya.com', 'pass' => 'imap-SIR-1', 'caps' => ['IMAP4rev1'], 'double_continuation' => true]);
$c = new MailImapClient($IM); $c->baslat(false); $c->girisYap('rv@asya.com', 'imap-SIR-1');
$e = null; try { $c->ekle('Sent', "Subject: x\r\n\r\nGİZLİ GÖVDE\r\n"); } catch (MailImapException $ex) { $e = $ex; }
ok('sunucu iki kez "+" gönderirse bağlantı kesilir, gövde en fazla BİR kez yazıldı', $e !== null && $e->kind === 'protocol' && count($IM->eklenen) <= 1 && $IM->kapandi, $e?->getMessage() ?? 'hata yok');

mail_test_bitir();
