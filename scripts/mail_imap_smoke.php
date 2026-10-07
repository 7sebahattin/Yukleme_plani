<?php
// =========================================================
// scripts/mail_imap_smoke.php — saf PHP IMAP istemcisi (M2) davranış testi, SAHTE sunucuyla.
//   php scripts/mail_imap_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
require_once __DIR__ . '/_mail_test_lib.php';
require_once $ROOT . '/config/mail_imap.php';
require_once __DIR__ . '/_mail_fake_imap.php';

function istemci(array $cfg, array $opts = []): array {
    $s = new FakeMailStream($cfg);
    return [new MailImapClient($s, $opts), $s];
}
function hata(callable $f): ?MailImapException {
    try { $f(); } catch (MailImapException $e) { return $e; }
    return null;
}

echo "=== 1. Oturum ===\n";
[$c, $s] = istemci(['user' => 'info@asya.com', 'pass' => 'Gizli-Parola-55']);
$c->baslat(false);
ok('CAPABILITY okundu', in_array('AUTH=PLAIN', $c->yetenekler, true));
$c->girisYap('info@asya.com', 'Gizli-Parola-55');
ok('AUTHENTICATE PLAIN ile giriş', in_array('AUTHENTICATE PLAIN', array_map(fn($x) => explode(' ', $x, 2)[1] ?? '', $s->received), true));
ok('LOGIN KULLANILMADI (AUTH=PLAIN varken)', !preg_grep('/LOGIN/', $s->received));
ok('istemci günlüğünde ŞİFRE yok', !str_contains(implode("\n", $c->gunluk), 'Gizli-Parola-55') && !str_contains(implode("\n", $c->gunluk), base64_encode("\0info@asya.com\0Gizli-Parola-55")));

[$c, $s] = istemci(['user' => 'a', 'pass' => 'b', 'caps' => ['IMAP4rev1']]);
$c->baslat(false); $c->girisYap('a', 'b');
ok('AUTH=PLAIN yoksa LOGIN\'e düşer', (bool)preg_grep('/^A\d+ LOGIN /', $s->received));
ok('LOGIN günlüğü maskeli', !preg_grep('/LOGIN "a"/', $c->gunluk) && (bool)preg_grep('/LOGIN \*\*\* \*\*\*/', $c->gunluk));

[$c, $s] = istemci(['user' => 'a', 'pass' => 'dogru', 'caps' => ['IMAP4rev1']]);
$c->baslat(false);
$e = hata(fn() => $c->girisYap('a', 'yanlisParola123'));
ok('yanlış şifre → kind=auth', $e && $e->kind === 'auth');
ok('hata metni şifre sızdırmıyor', $e && !str_contains($e->getMessage(), 'yanlisParola123'));
[$c, $s] = istemci(['user' => 'a', 'pass' => 'dogru']);
$c->baslat(false);
$e = hata(fn() => $c->girisYap('a', 'yanlis'));
ok('AUTHENTICATE PLAIN yanlış şifre → kind=auth', $e && $e->kind === 'auth');
$e = hata(fn() => $c->girisYap("a\r\nA9 DELETE x", 'p'));
ok('kullanıcı adında CRLF reddedilir', $e && $e->kind === 'auth');
[$c, $s] = istemci(['caps' => ['IMAP4rev1', 'LOGINDISABLED']]);
$c->baslat(false);
ok('LOGINDISABLED → giriş denenmiyor', hata(fn() => $c->girisYap('a', 'b'))?->kind === 'auth' && !preg_grep('/LOGIN|AUTHENTICATE/', $s->received));
[$c, $s] = istemci(['caps' => ['IMAP4rev1']]);
ok('STARTTLS istendi ama sunulmuyor → düz metne DÜŞMEZ', hata(fn() => $c->baslat(true))?->kind === 'connect');
[$c, $s] = istemci(['caps' => ['IMAP4rev1', 'STARTTLS']]);
$c->baslat(true);
ok('STARTTLS akışı (komut + yetenekler yeniden)', (bool)preg_grep('/STARTTLS/', $s->received) && count(preg_grep('/CAPABILITY/', $s->received)) === 2);

echo "\n=== 2. EXAMINE / UID SEARCH / UID FETCH ===\n";
$m = [
    5 => ['raw' => mail_test_raw(['subject' => 'Bir', 'msgid' => '<1@x>']), 'flags' => ['\Seen'], 'date' => '2026-10-01 10:00:00'],
    6 => ['raw' => mail_test_raw(['subject' => 'İki', 'msgid' => '<2@x>']), 'flags' => [], 'date' => '2026-10-02 10:00:00'],
    9 => ['raw' => mail_test_raw(['subject' => 'Üç', 'msgid' => '<3@x>']), 'flags' => ['\Seen'], 'date' => '2026-10-05 10:00:00'],
];
[$c, $s] = istemci(['user' => 'a', 'pass' => 'b', 'uidvalidity' => 777, 'mesajlar' => $m]);
$c->baslat(false); $c->girisYap('a', 'b');
$k = $c->klasorAc('INBOX');
ok('EXAMINE: UIDVALIDITY/UIDNEXT/EXISTS', $k === ['uidvalidity' => 777, 'uidnext' => 10, 'exists' => 3], json_encode($k));
ok('EXAMINE kullanıldı (SELECT DEĞİL — salt okunur)', (bool)preg_grep('/EXAMINE/', $s->received) && !preg_grep('/\bSELECT\b/', $s->received));
ok('UID SEARCH UID 6:* → [6,9]', $c->uidAra('UID 6:*') === [6, 9]);
ok('UID n:* tuzağı: n > son UID olsa da sunucu son mesajı döndürür (istemci süzmeli)', $c->uidAra('UID 50:*') === [9]);
ok('SINCE araması (İngilizce ay)', $c->uidAra('SINCE ' . mail_imap_tarih(strtotime('2026-10-02 00:00:00 UTC'))) === [6, 9]);
ok('geçersiz arama ölçütü reddedilir (komut enjeksiyonu)', hata(fn() => $c->uidAra("ALL\r\nA9 DELETE"))?->kind === 'bad' && hata(fn() => $c->uidAra('ALL) (x'))?->kind === 'bad');
ok('uidKumesi sıkıştırma', MailImapClient::uidKumesi([1, 2, 3, 7, 9, 10, 3]) === '1:3,7,9:10');
$meta = $c->uidMeta([5, 6, 9]);
ok('uidMeta: boyut + bayrak', $meta[5]['size'] === strlen($m[5]['raw']) && $meta[5]['flags'] === ['\Seen'] && $meta[6]['flags'] === []);
$r = $c->uidMesaj(6, $meta[6]['size'], 1000000);
ok('uidMesaj ham baytları birebir döndürür (UTF-8, CRLF)', $r['raw'] === $m[6]['raw'] && $r['truncated'] === false);
ok('BODY.PEEK kullanıldı, \Seen DEĞİŞTİRİLMEDİ', (bool)preg_grep('/BODY\.PEEK\[\]/', $s->received) && !preg_grep('/\bBODY\[\]|STORE|\+FLAGS|\bUNSEEN\b/', $s->received));
ok('UNSEEN hiç kullanılmadı', !preg_grep('/UNSEEN/i', $s->received));
ok('olmayan UID → null (istisna yok)', $c->uidMesaj(123, 10, 1000000) === null);
$buyuk = ['raw' => mail_test_raw(['body' => str_repeat('A', 5000)]), 'flags' => [], 'date' => '2026-10-05 10:00:00'];
[$c, $s] = istemci(['user' => 'a', 'pass' => 'b', 'mesajlar' => [1 => $buyuk]]);
$c->baslat(false); $c->girisYap('a', 'b'); $c->klasorAc('INBOX');
$r = $c->uidMesaj(1, strlen($buyuk['raw']), 1000, 700);
ok('boyut sınırı aşılınca yalnız başlık + kısmi gövde', $r['truncated'] === true && strlen($r['raw']) < strlen($buyuk['raw']) && str_contains($r['raw'], 'Message-ID'), (string)strlen($r['raw']));
[$c, $s] = istemci(['user' => 'a', 'pass' => 'b', 'mesajlar' => [1 => $buyuk + ['parcalar' => ['2' => 'EKVERISI']]]]);
$c->baslat(false); $c->girisYap('a', 'b'); $c->klasorAc('INBOX');
ok('uidParca: tek MIME parçası', $c->uidParca(1, '2', 1000) === 'EKVERISI');
ok('uidParca: boyut sınırı', hata(fn() => $c->uidParca(1, '2', 3))?->kind === 'limit');
ok('uidParca: geçersiz parça no reddedilir', hata(fn() => $c->uidParca(1, "1]\r\nA9", 100))?->kind === 'bad');

echo "\n=== 3. Modified UTF-7 ===\n";
foreach (['INBOX', 'Gönderilmiş Öğeler', 'Rechnungen/März', 'A&B', 'Отправленные', '日本語'] as $kl) {
    $e = mail_imap_utf7_encode($kl);
    ok("utf7 gidiş-dönüş: $kl", mail_imap_utf7_decode($e) === $kl && !preg_match('/[^\x20-\x7e]/', $e), $e);
}
ok('utf7 bilinen değer', mail_imap_utf7_encode('Gönderilmiş') === 'G&APY-nderilmi&AV8-', mail_imap_utf7_encode('Gönderilmiş'));

echo "\n=== 4. Kötü / bozuk sunucu ===\n";
[$c, $s] = istemci(['user' => 'a', 'pass' => 'b', 'mesajlar' => [1 => $buyuk]]);
$c->baslat(false); $c->girisYap('a', 'b'); $c->klasorAc('INBOX');
$s->cfg['drop_in_fetch'] = true;
$e = hata(fn() => $c->uidMesaj(1, 100, 1000000));
ok('literal ortasında kopan bağlantı → timeout hatası, kısmi veri DÖNMEZ', $e && $e->kind === 'timeout');
[$c, $s] = istemci(['user' => 'a', 'pass' => 'b', 'mesajlar' => [1 => $buyuk]], ['max_literal' => 100]);
$c->baslat(false); $c->girisYap('a', 'b'); $c->klasorAc('INBOX');
$e = hata(fn() => $c->uidMesaj(1, 0, 1000000));
ok('devasa literal → limit hatası ve bağlantı kapatılır', $e && $e->kind === 'limit' && $s->kapandi);
[$c, $s] = istemci(['user' => 'a', 'pass' => 'b', 'mesajlar' => [1 => $buyuk], 'bye_on_fetch' => true]);
$c->baslat(false); $c->girisYap('a', 'b'); $c->klasorAc('INBOX');
$e = hata(fn() => $c->uidMesaj(1, 0, 1000000));
ok('sunucu BYE → protocol hatası', $e && $e->kind === 'protocol' && $s->kapandi);
[$c, $s] = istemci(['user' => 'a', 'pass' => 'b', 'drop_after' => 2]);
$c->baslat(false);
ok('bağlantı komutlar arasında kopunca timeout', hata(fn() => $c->girisYap('a', 'b'))?->kind === 'timeout');
[$c, $s] = istemci(['mesajlar' => [], 'uidvalidity' => 0]);
$c->baslat(false); $c->girisYap('u', 'p');
ok('UIDVALIDITY bildirmeyen sunucu reddedilir (güvenli senkron yok)', hata(fn() => $c->klasorAc('INBOX'))?->kind === 'protocol');
ok('klasör adında CRLF reddedilir', hata(fn() => $c->klasorAc("INBOX\r\nA9 DELETE"))?->kind === 'bad');
$c->cikis();
ok('LOGOUT sonrası bağlantı kapalı', $s->kapandi);

echo "\n=== 5. Belirteç ayrıştırıcı ===\n";
$t = MailImapClient::belirtecle('(UID 5 FLAGS (\Seen \Answered) INTERNALDATE "01-Oct-2026 10:00:00 +0000" BODY[] ' . "\x01L0\x01" . ' X NIL)', ["a b\r\n(c)"]);
ok('iç içe liste, quoted, literal, NIL', $t[0][0] === 'UID' && $t[0][3] === ['\Seen', '\Answered'] && $t[0][5] === '01-Oct-2026 10:00:00 +0000' && $t[0][7] === "a b\r\n(c)" && $t[0][9] === null, json_encode($t));
$t = MailImapClient::belirtecle('("a\"b" "c\\\\d")', []);
ok('quoted kaçışlar', $t[0] === ['a"b', 'c\\d']);
mail_test_bitir();
