<?php
// =========================================================
// scripts/mail_smtp_smoke.php — Saf PHP SMTP istemcisi + giden mesaj oluşturucu (M5), SAHTE SMTP sunucusuyla.
//   php scripts/mail_smtp_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
require_once __DIR__ . '/_mail_test_lib.php';
require_once $ROOT . '/config/mail_imap.php';
require_once $ROOT . '/config/mail_mime.php';
require_once $ROOT . '/config/mail_sync.php';
require_once $ROOT . '/config/mail_smtp.php';
require_once __DIR__ . '/_mail_fake_smtp.php';
require_once __DIR__ . '/_mail_fake_imap.php';

function hata(callable $f): ?MailSmtpException { try { $f(); } catch (MailSmtpException $e) { return $e; } return null; }
function smtp(array $cfg, array $o = []): array { $s = new FakeSmtpStream($cfg); return [new MailSmtpClient($s, $o), $s]; }

echo "=== 1. Oturum ===\n";
[$c, $s] = smtp(['user' => 'info@asya.com', 'pass' => 'Gizli-SMTP-77']);
$c->baslat('asya.com', false);
ok('EHLO yetenekleri okundu (AUTH mekanizmaları dahil)', in_array('AUTH=PLAIN', $c->yetenekler, true) && in_array('AUTH=LOGIN', $c->yetenekler, true) && in_array('STARTTLS', $c->yetenekler, true));
$c->girisYap('info@asya.com', 'Gizli-SMTP-77');
ok('AUTH PLAIN ile giriş', (bool)preg_grep('/^AUTH PLAIN /', $s->komutlar));
ok('günlükte ŞİFRE / base64 kimlik YOK', !str_contains(implode("\n", $c->gunluk), 'Gizli-SMTP-77') && !str_contains(implode("\n", $c->gunluk), base64_encode("\0info@asya.com\0Gizli-SMTP-77")) && (bool)preg_grep('/AUTH PLAIN \*\*\*/', $c->gunluk));
[$c, $s] = smtp(['user' => 'a', 'pass' => 'b', 'caps' => ['AUTH LOGIN']]);
$c->baslat('asya.com', false); $c->girisYap('a', 'b');
ok('AUTH PLAIN yoksa AUTH LOGIN (3 adım)', in_array('AUTH LOGIN', $s->komutlar, true) && in_array('[auth-pass]', $s->komutlar, true));
ok('AUTH LOGIN günlüğü kimlik içermiyor', !str_contains(implode("\n", $c->gunluk), base64_encode('b')) && !str_contains(implode("\n", $c->gunluk), base64_encode('a')));
[$c, $s] = smtp(['user' => 'a', 'pass' => 'dogru']);
$c->baslat('asya.com', false);
$e = hata(fn() => $c->girisYap('a', 'YanlisParola123'));
ok('yanlış şifre → kind=auth, şifre mesajda yok', $e && $e->kind === 'auth' && !str_contains($e->getMessage(), 'YanlisParola123'));
[$c, $s] = smtp(['caps' => ['8BITMIME']]);
$c->baslat('asya.com', false);
ok('AUTH sunmayan sunucu → auth hatası (kimlik gönderilmez)', hata(fn() => $c->girisYap('a', 'b'))?->kind === 'auth' && !preg_grep('/AUTH/', $s->komutlar));
$e = hata(function () { [$c] = smtp([]); $c->girisYap("a\r\nRCPT TO:<x@y.com>", 'p'); });
ok('kullanıcı adında CRLF reddedilir (komut enjeksiyonu)', $e && $e->kind === 'auth');
[$c, $s] = smtp(['caps' => ['AUTH PLAIN']]);
ok('STARTTLS istendi ama sunulmuyor → düz metne DÜŞMEZ', hata(fn() => $c->baslat('asya.com', true))?->kind === 'connect');
[$c, $s] = smtp([]);
$c->baslat('asya.com', true);
ok('STARTTLS akışı: STARTTLS + EHLO yeniden', (bool)preg_grep('/^STARTTLS$/', $s->komutlar) && count(preg_grep('/^EHLO/', $s->komutlar)) === 2);
ok('selamlama 220 değilse reddedilir', hata(function () { [$c] = smtp(['greeting' => '554 no service']); $c->baslat('x.com', false); })?->kind === 'connect');
ok('EHLO adı geçersizse localhost', (function () { [$c, $s] = smtp([]); $c->baslat("evil\r\nX", false); return (bool)preg_grep('/^EHLO localhost$/', $s->komutlar); })());

echo "\n=== 2. Gönderim: DATA öncesi hatalar GÜVENLİ ===\n";
[$c, $s] = smtp(['reject_rcpt' => ['yok@musteri.com']]);
$c->baslat('asya.com', false);
$e = hata(fn() => $c->gonder('info@asya.com', ['yok@musteri.com'], "Subject: x\r\n\r\nmerhaba"));
ok('tüm alıcılar reddedildi → kind=rcpt, güvenli tekrar, DATA\'ya GİDİLMEDİ', $e && $e->kind === 'rcpt' && $e->guvenliTekrar && !in_array('DATA', $s->komutlar, true) && !$s->dataAlindi);
[$c, $s] = smtp(['pre_data_drop' => true]);
$c->baslat('asya.com', false);
$e = hata(fn() => $c->gonder('info@asya.com', ['a@b.com'], "x"));
ok('DATA komutunda kopma → connect/güvenli (veri HİÇ gönderilmedi)', $e && $e->guvenliTekrar && !$s->dataAlindi && !$c->dataFazi, $e?->kind . '');
[$c, $s] = smtp([]);
$c->baslat('asya.com', false);
ok('geçersiz zarf gönderen reddedilir', hata(fn() => $c->gonder("a@b.com\r\nRCPT TO:<z@z.com>", ['a@b.com'], 'x'))?->kind === 'rcpt');
ok('CRLF içeren alıcı atılır (enjeksiyon), kalan geçerli alıcıya gider', (function () { [$c, $s] = smtp([]); $c->baslat('a.com', false); $r = $c->gonder('i@a.com', ["x@y.com\r\nRCPT TO:<evil@e.com>", 'ok@y.com'], "Subject: t\r\n\r\ngövde"); return $r['kabul'] === ['ok@y.com'] && !preg_grep('/evil/', $s->komutlar); })());

echo "\n=== 3. Başarılı gönderim + DATA kodlaması ===\n";
[$c, $s] = smtp([]);
$c->baslat('asya.com', false);
$govde = "Subject: Test\r\n\r\n.nokta ile başlayan satır\r\nikinci\r\n..iki nokta\r\n\r\nson";
$r = $c->gonder('info@asya.com', ['musteri@x.com', 'red@x.com'], $govde);
ok('başarılı: 250 yanıtı + kabul/ret listesi', $r['kabul'] === ['musteri@x.com', 'red@x.com'] && str_contains($r['yanit'], 'queued'));
ok('nokta doldurma sunucuda geri çözülünce mesaj BİREBİR', $s->mesajlar[0] === $govde . "\r\n", json_encode($s->mesajlar[0]));
ok('MAIL FROM / RCPT / DATA sırası', preg_grep('/^MAIL FROM:<info@asya.com>$/', $s->komutlar) && preg_grep('/^RCPT TO:<musteri@x.com>$/', $s->komutlar));
$s2 = new FakeSmtpStream([]); $c2 = new MailSmtpClient($s2); $c2->baslat('a.com', false);
$c2->gonder('i@a.com', ['x@y.com'], "L1\nL2\rL3");
ok('LF/CR satır sonları CRLF\'e normalize edilir', $s2->mesajlar[0] === "L1\r\nL2\r\nL3\r\n");
ok('boyut sınırı aşılırsa DATA öncesi reddedilir', hata(function () { [$c, $s] = smtp([], ['max_boyut' => 100]); $c->baslat('a.com', false); $c->gonder('i@a.com', ['x@y.com'], str_repeat('x', 500)); })?->kind === 'limit');

echo "\n=== 4. DATA sonrası: açık ret GÜVENLİ, belirsizlik UNKNOWN ===\n";
foreach ([['550 5.7.1 Spam detected', 'data_reject'], ['451 4.3.0 Try again later', 'data_reject'], ['554 Transaction failed', 'data_reject']] as [$final, $tur]) {
    [$c, $s] = smtp(['final' => $final]); $c->baslat('a.com', false);
    $e = hata(fn() => $c->gonder('i@a.com', ['x@y.com'], "Subject: t\r\n\r\nx"));
    ok("son yanıt '$final' → $tur, KABUL EDİLMEDİ (güvenli tekrar)", $e && $e->kind === $tur && $e->guvenliTekrar && $s->dataAlindi);
}
[$c, $s] = smtp(['final' => 'DROP']); $c->baslat('a.com', false);
$e = hata(fn() => $c->gonder('i@a.com', ['x@y.com'], "Subject: t\r\n\r\nx"));
ok('son "." sonrası bağlantı koptu → UNKNOWN, güvenli tekrar DEĞİL', $e && $e->kind === 'unknown' && !$e->guvenliTekrar && $c->dataFazi);
[$c, $s] = smtp(['final' => 'SILENT'], ['sure' => 60]); $c->baslat('a.com', false);
$e = hata(fn() => $c->gonder('i@a.com', ['x@y.com'], "Subject: t\r\n\r\nx"));
ok('son yanıt hiç gelmedi → UNKNOWN', $e && $e->kind === 'unknown' && !$e->guvenliTekrar);
ok('hata metni şifre/gövde sızdırmıyor', $e && !str_contains($e->getMessage(), 'Subject'));
[$c, $s] = smtp(['final' => '250 ok']); $c->baslat('a.com', false);
$c->gonder('i@a.com', ['x@y.com'], 'x'); $c->cikis();
ok('QUIT + bağlantı kapanır', in_array('QUIT', $s->komutlar, true) && $s->kapandi);

echo "\n=== 5. Bozuk sunucu yanıtları ===\n";
[$c] = smtp(['greeting' => 'bu bir SMTP yanıtı değil']);
ok('çözülemeyen selamlama → protocol', hata(fn() => $c->baslat('a.com', false))?->kind === 'protocol');
final class SonsuzSatir implements MailStream { public function readLine(int $m): ?string { return '250-' . str_repeat('x', 10); } public function readBytes(int $n): ?string { return ''; } public function write(string $d): void {} public function startTls(): bool { return false; } public function close(): void {} }
$c = new MailSmtpClient(new SonsuzSatir(), ['max_satir_sayisi' => 50]);
$e = hata(fn() => $c->baslat('a.com', false));
ok('sonsuz çok satırlı yanıt → limit hatası (takılmaz)', $e && in_array($e->kind, ['limit', 'protocol'], true));
ok('tutarsız çok satırlı kod → protocol', hata(function () { $s = new class implements MailStream { private int $i = 0; public function readLine(int $m): ?string { return ['220-a', '250 b'][$this->i++] ?? null; } public function readBytes(int $n): ?string { return null; } public function write(string $d): void {} public function startTls(): bool { return false; } public function close(): void {} }; (new MailSmtpClient($s))->baslat('a.com', false); })?->kind === 'protocol');

echo "\n=== 6. Mesaj oluşturucu: başlıklar / enjeksiyon / thread ===\n";
$mid = '<abc123.1790000000@asya.com>';
$ham = mail_giden_mesaj(['from_email' => 'info@asya.com', 'from_name' => 'Asya Fresh Ihracat', 'to' => [['name' => 'Ivan Petrov', 'email' => 'ivan@musteri.ru']],
    'subject' => 'Re: Order 4411', 'body' => "Hello,\nthe goods ship Monday.", 'quote' => "On Mon, 05 Oct 2026 10:00, Ivan <ivan@musteri.ru> wrote:\n> Where is my order?", 'message_id' => $mid,
    'in_reply_to' => 'parent@musteri.ru', 'references' => ['root@musteri.ru', 'parent@musteri.ru'], 'date' => strtotime('2026-10-06 12:00:00'), 'reply_to' => 'satis@asya.com']);
$p = mail_mime_mesaj($ham);
ok('mesaj kendi ayrıştırıcımızdan geçiyor: From/To/Subject/Message-ID', $p['from_addr'] === 'info@asya.com' && $p['from_name'] === 'Asya Fresh Ihracat' && $p['to'][0]['email'] === 'ivan@musteri.ru' && $p['subject'] === 'Re: Order 4411' && $p['message_id'] === 'abc123.1790000000@asya.com');
ok('In-Reply-To + References doğru', $p['in_reply_to'] === 'parent@musteri.ru' && $p['references'] === ['root@musteri.ru', 'parent@musteri.ru']);
ok('Reply-To', $p['reply_to_addr'] === 'satis@asya.com');
ok('gövde (QP, UTF-8) + alıntı birebir', str_contains(str_replace("\r\n", "\n", $p['body_text']), "Hello,\nthe goods ship Monday.") && str_contains($p['body_text'], '> Where is my order?'));
ok('Content-Type/CTE/MIME-Version', str_contains($ham, "Content-Type: text/plain; charset=UTF-8\r\n") && str_contains($ham, "Content-Transfer-Encoding: quoted-printable\r\n") && str_contains($ham, "MIME-Version: 1.0\r\n"));
ok('Date RFC 2822', (bool)preg_match('/^Date: [A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} [+-]\d{4}\r?$/m', $ham));
$tr = mail_giden_mesaj(['from_email' => 'info@asya.com', 'from_name' => 'Asya Fresh İhracat Şirketi', 'to' => [['name' => 'Çağlar Şahin', 'email' => 'c@x.com']], 'subject' => 'Re: Sipariş — Ödeme Onayı çok uzun bir konu satırı ' . str_repeat('ğüşiöç ', 12), 'body' => 'Türkçe gövde: şğüıöç İĞÜŞÖÇ', 'message_id' => $mid]);
$pt = mail_mime_mesaj($tr);
ok('Türkçe konu/ad RFC 2047 ile gidiş-dönüş', $pt['from_name'] === 'Asya Fresh İhracat Şirketi' && str_starts_with($pt['subject'], 'Re: Sipariş — Ödeme Onayı') && $pt['to'][0]['name'] === 'Çağlar Şahin' && str_contains($pt['body_text'], 'şğüıöç İĞÜŞÖÇ'));
ok('hiçbir başlık satırı 998 bayttan uzun değil, kodlu sözcükler ≤ 75', (function () use ($tr) { foreach (explode("\r\n", explode("\r\n\r\n", $tr)[0]) as $l) { if (strlen($l) > 200) return false; } return true; })());
foreach ([
    'konu CRLF' => ['subject' => "Merhaba\r\nBcc: gizli@evil.com"],
    'ad CRLF' => ['from_name' => "Asya\r\nBcc: gizli@evil.com"],
    'konu LF' => ['subject' => "Merhaba\nBcc: gizli@evil.com"],
    'NUL' => ['subject' => "x\0y"],
    'to e-posta CRLF' => ['to' => [['name' => '', 'email' => "a@b.com\r\nBcc: z@z.com"]]],
    'to e-posta geçersiz' => ['to' => [['name' => '', 'email' => 'yok']]],
    'message-id CRLF' => ['message_id' => "<a@b.com>\r\nBcc: z@z.com"],
    'message-id geçersiz' => ['message_id' => 'rastgele'],
    'in_reply_to CRLF' => ['in_reply_to' => "x@y.com>\r\nBcc: z@z.com"],
    'references CRLF' => ['references' => ["a@b.com\r\nBcc: z@z.com"]],
    'alıcı yok' => ['to' => []],
] as $ad => $ek) {
    $e = null; try { mail_giden_mesaj(array_merge(['from_email' => 'i@a.com', 'from_name' => 'X', 'to' => [['name' => '', 'email' => 'a@b.com']], 'subject' => 'S', 'body' => 'b', 'message_id' => $mid], $ek)); } catch (InvalidArgumentException $x) { $e = $x; }
    ok("başlık enjeksiyonu reddedilir: $ad", $e !== null);
}
$hamH = explode("\r\n\r\n", mail_giden_mesaj(['from_email' => 'i@a.com', 'from_name' => '', 'to' => [['name' => 'A', 'email' => 'a@b.com']], 'subject' => 'S', 'body' => "gövde\r\nBcc: gizli@evil.com", 'message_id' => $mid]), 2);
ok('GÖVDEDEKİ "Bcc:" satırı başlığa sızmaz (başlıklar gövdeden ayrı)', !preg_match('/^Bcc:/mi', $hamH[0]));
ok('mail_yanit_konusu: Re/AW/SV/Ответ önekleri tekrarlanmaz', mail_yanit_konusu('Re: RE: AW: Ответ: Konu') === 'Re: Konu' && mail_yanit_konusu('Sipariş') === 'Re: Sipariş' && mail_yanit_konusu('') === 'Re: (konu yok)');
$z = mail_references_zinciri(['a@x', 'b@x'], 'c@x');
ok('References zinciri: eski + ebeveyn, <> biçimli, tekrarsız', $z === ['<a@x>', '<b@x>', '<c@x>'] && mail_references_zinciri(['a@x', 'a@x'], '<a@x>') === ['<a@x>']);
$uzun = array_map(fn($i) => "r$i@x", range(1, 40));
$z = mail_references_zinciri($uzun, 'son@x');
ok('References en çok 20: kök + son 19', count($z) === 20 && $z[0] === '<r1@x>' && $z[19] === '<son@x>');
$m1 = mail_yeni_message_id('info@Asya.com'); $m2 = mail_yeni_message_id('info@asya.com');
ok('Message-ID biçimi + benzersiz + hesabın alan adı', (bool)preg_match('/^<[0-9a-f]{32}\.\d+@asya\.com>$/', $m1) && $m1 !== $m2);
ok('bozuk e-postadan Message-ID localhost alanına düşer', str_ends_with(mail_yeni_message_id('bozuk'), '@localhost>'));
$q = mail_alinti_olustur(['body_text' => "satır1\nsatır2", 'from_name' => "Ivan\r\nBcc: x", 'from_addr' => 'ivan@x.ru', 'received_at' => '2026-10-05 10:00:00']);
ok('alıntı: "> " önekli, atıf satırı tek satır', str_contains($q, "> satır1\n> satır2") && substr_count(explode("\n", $q)[0], "\n") === 0 && !str_contains(explode("\n", $q)[0], "\r"));
ok('alıntı 40 satır / 4000 karakterle sınırlı', substr_count(mail_alinti_olustur(['body_text' => str_repeat("x\n", 100), 'from_name' => '', 'from_addr' => 'a@b.com', 'received_at' => '2026-10-05 10:00:00']), "\n> ") <= 40);
ok('mail_adres_baslik: noktalı virgül/tırnak içeren ad güvenle tırnaklanır', mail_adres_baslik('Ali "Veli"; X', 'a@b.com') === '"Ali \\"Veli\\"; X" <a@b.com>');

echo "\n=== 7. IMAP APPEND (Gönderilenler kopyası) ===\n";
$srvI = new FakeMailStream(['user' => 'u', 'pass' => 'p', 'caps' => ['IMAP4rev1', 'AUTH=PLAIN']]);
$ci = new MailImapClient($srvI); $ci->baslat(false); $ci->girisYap('u', 'p');
$ci->ekle('Sent', "Subject: x\r\n\r\ngövde", '\Seen');
ok('APPEND komutu: klasör + bayrak + literal boyutu, günlükte gövde YOK', (bool)preg_grep('/^A\d+ APPEND "Sent" \(\\\\Seen\) \{\d+\}$/', $srvI->received) && !str_contains(implode("\n", $ci->gunluk), 'gövde'));
ok('APPEND klasör CRLF enjeksiyonu reddedilir', (function () use ($ci) { try { $ci->ekle("Sent\r\nA9 DELETE", 'x'); return false; } catch (MailImapException $e) { return $e->kind === 'bad'; } })());
ok('APPEND bayrak enjeksiyonu reddedilir', (function () use ($ci) { try { $ci->ekle('Sent', 'x', '\Seen) {5}'); return false; } catch (MailImapException $e) { return $e->kind === 'bad'; } })());
mail_test_bitir();
