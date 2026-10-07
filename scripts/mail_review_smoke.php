<?php
// =========================================================
// scripts/mail_review_smoke.php — M2 bağımsız güvenlik incelemesinin (Opus) bulgularının REGRESYON testleri.
// Her blok bir bulguyu (H1, M1–M4, L1–L5) yeniden üretir ve düzeltmenin tuttuğunu kanıtlar.
//   php scripts/mail_review_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
require_once __DIR__ . '/_mail_test_lib.php';
require_once $ROOT . '/config/mail_imap.php';
require_once $ROOT . '/config/mail_mime.php';
require_once $ROOT . '/config/mail_sync.php';
require_once __DIR__ . '/_mail_fake_imap.php';

$db = db(); mail_test_diger_tablolar($db); mail_test_sema_kur($db); mail_test_anahtar_kur();
$KILIT = sys_get_temp_dir() . '/mail_rev_kilit_' . getmypid(); @mkdir($KILIT, 0700, true);
// MySQL strict modunu SQLite'ta taklit et: aralık dışı tarih, TEXT taşması, geçersiz UTF-8 → INSERT reddedilir.
$db->sqliteCreateFunction('gecerli_utf8', fn($x) => $x === null ? 1 : (mb_check_encoding((string)$x, 'UTF-8') ? 1 : 0), 1);
$db->exec("CREATE TRIGGER strict_mod BEFORE INSERT ON mail_messages BEGIN
  SELECT CASE
    WHEN new.date_header IS NOT NULL AND (length(new.date_header) > 19 OR new.date_header > '9999-12-31 23:59:59') THEN RAISE(ABORT, 'Incorrect datetime value')
    WHEN length(new.to_addrs) > 65535 OR length(new.cc_addrs) > 65535 THEN RAISE(ABORT, 'Data too long for column')
    WHEN gecerli_utf8(new.references_hdr) = 0 OR gecerli_utf8(new.message_id) = 0 OR gecerli_utf8(new.in_reply_to) = 0 OR gecerli_utf8(new.subject) = 0 OR gecerli_utf8(new.from_name) = 0 OR gecerli_utf8(new.attachments_json) = 0 THEN RAISE(ABORT, 'Incorrect string value')
    WHEN new.subject = 'POISON' THEN RAISE(ABORT, 'poison')
    WHEN new.subject LIKE '(kaydedilemedi%' AND (SELECT COUNT(*) FROM mail_messages WHERE subject = 'DBDOWN') >= 0 AND new.account_id = (SELECT id FROM mail_accounts WHERE email = 'dbdown@asya.com') THEN RAISE(ABORT, 'db down')
  END;
END");

function hesap(string $e, array $ek = []): int {
    global $db;
    $r = mail_hesap_kaydet(array_merge(['label' => $e, 'email' => $e, 'imap_host' => 'i.test.com', 'imap_user' => $e, 'imap_pass' => 'imap-parola-ABC', 'smtp_host' => 's.test.com', 'smtp_user' => $e, 'smtp_pass' => 'smtp-parola-XYZ', 'initial_days' => 30], $ek), null, 1, $db);
    if (!$r['ok']) throw new RuntimeException(json_encode($r));
    return $r['id'];
}
$SRV = [];
function srv(int $id, array $mesajlar, array $ek = []): FakeMailStream {
    global $SRV, $db;
    $e = $db->query("SELECT email FROM mail_accounts WHERE id = $id")->fetchColumn();
    return $SRV[$id] = new FakeMailStream(array_merge(['user' => $e, 'pass' => 'imap-parola-ABC', 'mesajlar' => $mesajlar], $ek));
}
function senk(int $id, string $simdi = '2026-10-06 12:00:00', array $ek = []): array {
    global $db, $KILIT, $SRV;
    return mail_sync_hesap($db, $id, array_merge(['kilit_dizin' => $KILIT, 'simdi' => strtotime($simdi), 'istemci' => function (array $h) use ($SRV) {
        $s = $SRV[(int)$h['id']]; $c = new MailImapClient($s->yeniBaglanti()); $c->baslat(false); $c->girisYap($h['imap_user'], $h['imap_pass']); return $c;
    }], $ek));
}
function M(string $raw, string $date = '2026-10-05 10:00:00', array $f = []): array { return ['raw' => $raw, 'flags' => $f, 'date' => $date]; }
function n(string $w = '1=1'): int { global $db; return (int)$db->query("SELECT COUNT(*) FROM mail_messages WHERE $w")->fetchColumn(); }

echo "=== H1: zehirli mail hesabı kilitleyemez ===\n";
$a = hesap('zehir@asya.com');
$cok = implode(', ', array_map(fn($i) => '"' . str_repeat('😀', 150) . "$i\" <u$i@x.com>", range(1, 100)));
srv($a, [
    1 => M(mail_test_raw(['msgid' => '<d@x>', 'date' => 'Fri, 31 Dec 9999 22:00:00 +0000', 'subject' => 'Gelecek tarihli'])),
    2 => M(mail_test_raw(['msgid' => '<r@x>', 'extra' => ["References: <\xe0\x80@z> <ok@x>"], 'subject' => 'Bozuk References'])),
    3 => M(mail_test_raw(['msgid' => '<t@x>', 'to' => $cok, 'extra' => ["Cc: $cok"], 'subject' => 'Çok alıcı'])),
    4 => M(mail_test_raw(['msgid' => '<p@x>', 'subject' => 'POISON'])),
    5 => M(mail_test_raw(['msgid' => '<son@x>', 'subject' => 'Zehirden sonraki'])),
]);
$r = senk($a);
ok('hesap takılmadı: hepsi işlendi, hata yok', $r['ok'] === true && $r['error'] === null, json_encode($r));
ok('zehirden SONRAKİ mail alındı (imleç ilerledi)', n("account_id = $a AND subject = 'Zehirden sonraki'") === 1 && (int)$db->query("SELECT last_uid FROM mail_sync_state WHERE account_id = $a")->fetchColumn() === 5);
ok('zehirli mail için GÖRÜNÜR yer tutucu satır yazıldı', n("account_id = $a AND subject LIKE '(kaydedilemedi%'") === 1 && (bool)preg_grep('/uid 4 kaydedilemedi/', $r['notlar']));
ok('9999 tarihli mail: tarih null\'a sıkıştı, mail kaydedildi', n("account_id = $a AND subject = 'Gelecek tarihli' AND date_header IS NULL") === 1);
ok('geçersiz UTF-8 References temizlenip kaydedildi', n("account_id = $a AND subject = 'Bozuk References'") === 1);
$t = $db->query("SELECT to_addrs, cc_addrs FROM mail_messages WHERE account_id = $a AND subject = 'Çok alıcı'")->fetch();
ok('100 emojili alıcı: JSON ≤ 60 000 bayt, geçerli', strlen($t['to_addrs']) <= 60000 && strlen($t['cc_addrs']) <= 60000 && is_array(json_decode($t['to_addrs'], true)));
$r = senk($a); $r = senk($a);
ok('tekrar çalıştırmalar yer tutucuyu/postayı çoğaltmıyor', n("account_id = $a") === 5);
// DB gerçekten çalışmıyorsa (yer tutucu da yazılamıyor) imleç İLERLEMEMELİ → mail kaybolmaz
$b = hesap('dbdown@asya.com');
srv($b, [1 => M(mail_test_raw(['msgid' => '<ok1@x>'])), 2 => M(mail_test_raw(['msgid' => '<x2@x>', 'subject' => 'POISON'])), 3 => M(mail_test_raw(['msgid' => '<ok3@x>']))]);
$r = senk($b);
ok('yer tutucu da yazılamıyorsa hata yükselir, imleç zehirli UID\'in ÖNÜNDE kalır (veri kaybı yok)', $r['ok'] === false && (int)$db->query("SELECT last_uid FROM mail_sync_state WHERE account_id = $b")->fetchColumn() === 1, json_encode($r));
$db->exec("DROP TRIGGER strict_mod");
$r = senk($b);
ok('DB düzelince kaldığı yerden devam, hiçbir mail kaybolmadı', $r['ok'] && n("account_id = $b") === 3);

echo "\n=== M1: STARTTLS tampon enjeksiyonu + yanlış etiket ===\n";
[$x, $y] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
fwrite($y, "A002 OK Begin TLS\r\n* CAPABILITY IMAP4rev1 INJECTED-BY-MITM\r\nA002 OK injected\r\n");
$ref = new ReflectionClass(MailSocketStream::class);
$ms = $ref->newInstanceWithoutConstructor();
$fp = $ref->getProperty('fp'); $fp->setAccessible(true); $fp->setValue($ms, $x);
$ilk = $ms->readLine(65536);
ok('ilk satır okundu', $ilk === 'A002 OK Begin TLS');
$meta = stream_get_meta_data($x);
ok('PHP tamponunda düz metin bayt kaldı (saldırı koşulu gerçek)', ($meta['unread_bytes'] ?? 0) > 0, json_encode($meta['unread_bytes'] ?? null));
ok('tamponda bayt varken startTls() REDDEDİYOR (TLS\'e geçilmiyor)', $ms->startTls() === false);
$c = new MailImapClient((new FakeMailStream(['caps' => ['IMAP4rev1', 'STARTTLS'], 'inject_tag' => true])));
$e = null; try { $c->baslat(false); } catch (MailImapException $ex) { $e = $ex; }
ok('yanlış etiketli (A999) satır → protocol hatası, yok sayılmıyor', $e && $e->kind === 'protocol', $e?->getMessage() ?? 'hata yok');

echo "\n=== M2: <meta charset> çift çözme / UTF-7 ===\n";
$h = mail_html_sanitize('<html><head><meta http-equiv="Content-Type" content="text/html; charset=windows-1254"></head><body><p>Sayın müşterimiz, Şükran ödeme yapıldı.</p></body></html>');
ok('windows-1254 meta bildirimi UTF-8 içeriği bozmuyor', str_contains($h, 'Sayın müşterimiz, Şükran ödeme yapıldı.'), $h);
$h = mail_html_sanitize('<meta charset="utf-7"><p>+ADw-b+AD4-bold+ADw-/b+AD4-</p>');
ok('meta utf-7 ile gerçek <b> ÜRETİLEMİYOR', !str_contains($h, '<b>') && str_contains($h, '+ADw-b+AD4-'), $h);
$t = mail_html_to_text('<meta charset="iso-8859-9"><p>Şükran Sayın</p>');
ok('html_to_text de meta\'dan etkilenmiyor', $t === 'Şükran Sayın', $t);
$m = mail_mime_mesaj(mail_test_raw(['ctype' => 'text/html; charset=windows-1254', 'cte' => '8bit', 'body' => mb_convert_encoding('<meta http-equiv="Content-Type" content="text/html; charset=windows-1254"><p>Şükran Sayın</p>', 'Windows-1254', 'UTF-8')]));
ok('uçtan uca: Türkçe Outlook HTML maili doğru çözülüyor (metin + HTML)', str_contains($m['body_html_safe'], 'Şükran Sayın') && str_contains($m['body_text'], 'Şükran Sayın'), $m['body_text']);

echo "\n=== M3: Message-ID dedupe yalnız geri tarama sırasında, yalnız önceki dönemde ===\n";
$a3 = hesap('epoch@asya.com');
srv($a3, [1 => M(mail_test_raw(['msgid' => '<fatura@x>', 'subject' => 'Invoice'])), 2 => M(mail_test_raw(['msgid' => '<diger@x>', 'subject' => 'Diger']))]);
senk($a3);
srv($a3, [101 => M(mail_test_raw(['msgid' => '<fatura@x>', 'subject' => 'Invoice'])), 102 => M(mail_test_raw(['msgid' => '<diger@x>', 'subject' => 'Diger']))], ['uidvalidity' => 2000]);
$r = senk($a3);
ok('epoch değişimi: çiftleme yok, satırlar yeni UID\'e taşındı', n("account_id = $a3") === 2 && n("account_id = $a3 AND uidvalidity = 2000 AND uid IN (101,102)") === 2, json_encode($r));
ok('geri tarama bitince bayrak temizlendi', $db->query("SELECT rescan_from_epoch FROM mail_sync_state WHERE account_id = $a3")->fetchColumn() === null);
srv($a3, [101 => M(mail_test_raw(['msgid' => '<fatura@x>', 'subject' => 'Invoice'])), 102 => M(mail_test_raw(['msgid' => '<diger@x>', 'subject' => 'Diger'])),
    103 => M(mail_test_raw(['msgid' => '<fatura@x>', 'subject' => 'ACİL: yeni banka bilgileri']), '2026-10-06 09:00:00')], ['uidvalidity' => 2000]);
$r = senk($a3);
ok('sonradan gelen, eski Message-ID\'yi yeniden kullanan mail YENİ SATIR olur (atılmaz)', $r['inserted'] === 1 && n("account_id = $a3") === 3, json_encode($r));
ok('eski satırın UID\'si ele geçirilmedi (Invoice hâlâ uid 101)', $db->query("SELECT uid FROM mail_messages WHERE account_id = $a3 AND subject = 'Invoice'")->fetchColumn() == 101 && $db->query("SELECT subject FROM mail_messages WHERE account_id = $a3 AND uid = 103")->fetchColumn() === 'ACİL: yeni banka bilgileri');
// Geri tarama birkaç çalıştırmaya yayılırsa bayrak KALICI olmalı (çiftleme sürmeli)
$a3b = hesap('epoch2@asya.com');
$eski = []; for ($i = 1; $i <= 5; $i++) $eski[$i] = M(mail_test_raw(['msgid' => "<e$i@x>", 'subject' => "E$i"]));
srv($a3b, $eski); senk($a3b);
$yeni = []; for ($i = 1; $i <= 5; $i++) $yeni[$i + 500] = M(mail_test_raw(['msgid' => "<e$i@x>", 'subject' => "E$i"]));
srv($a3b, $yeni, ['uidvalidity' => 3000]);
senk($a3b, '2026-10-06 12:00:00', ['limit' => 2]);
ok('parçalı geri tarama: bayrak sürüyor', $db->query("SELECT rescan_from_epoch FROM mail_sync_state WHERE account_id = $a3b")->fetchColumn() !== null);
senk($a3b, '2026-10-06 12:00:00', ['limit' => 2]); senk($a3b, '2026-10-06 12:00:00', ['limit' => 2]);
ok('parçalı geri tarama sonunda TEK kopya (çiftleme yok), bayrak temiz', n("account_id = $a3b") === 5 && $db->query("SELECT rescan_from_epoch FROM mail_sync_state WHERE account_id = $a3b")->fetchColumn() === null);

echo "\n=== M4: epoch değişiminde kesinti aralığı kaybolmaz ===\n";
$a4 = hesap('kesinti@asya.com');
srv($a4, [1 => M(mail_test_raw(['msgid' => '<eski@x>', 'subject' => 'Eski']), '2026-08-31 10:00:00')]);
senk($a4, '2026-09-01 12:00:00');
srv($a4, [
    11 => M(mail_test_raw(['msgid' => '<eski@x>', 'subject' => 'Eski']), '2026-08-31 10:00:00'),
    12 => M(mail_test_raw(['msgid' => '<sep5@x>', 'subject' => 'Sep5']), '2026-09-05 10:00:00'),
    13 => M(mail_test_raw(['msgid' => '<sep15@x>', 'subject' => 'Sep15']), '2026-09-15 10:00:00'),
    14 => M(mail_test_raw(['msgid' => '<oct10@x>', 'subject' => 'Oct10']), '2026-10-10 10:00:00'),
], ['uidvalidity' => 5000]);
senk($a4, '2026-10-20 12:00:00');
$konular = $db->query("SELECT subject FROM mail_messages WHERE account_id = $a4 ORDER BY uid")->fetchAll(PDO::FETCH_COLUMN);
ok('kesinti sırasında gelen Sep5 + Sep15 de alındı (initial_days=30 penceresi son başarılı senkrona kadar genişledi)', in_array('Sep5', $konular, true) && in_array('Sep15', $konular, true) && in_array('Oct10', $konular, true), implode(',', $konular));
ok('eski mail çiftlenmedi', count(array_keys($konular, 'Eski', true)) === 1);

echo "\n=== L1: multipart RFC 2046 ===\n";
$b = 'BND';
$raw = mail_test_raw(['ctype' => "multipart/mixed; boundary=\"$b\"", 'body' =>
    "preamble\r\n--$b\r\nContent-Type: text/plain\r\n\r\nGörünen metin\r\n--$b\r\n\r\nBaşlıksız parça gövdesi\r\n--$b\r\n--$b\r\nContent-Type: application/pdf; name=\"a.pdf\"\r\nContent-Disposition: attachment; filename=\"a.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\nUERG\r\n--$b--\r\nContent-Type: text/html\r\n\r\n<b>EPILOG GİZLİ</b>\r\n"]);
$m = mail_mime_mesaj($raw);
ok('epilog (kapanış ayracından sonrası) GÖSTERİLMİYOR', !str_contains($m['body_html_safe'], 'EPILOG') && !str_contains($m['body_text'], 'EPILOG'));
ok('başlıksız parçanın gövdesi kayıp değil', str_contains($m['body_text'], 'Başlıksız parça gövdesi') && str_contains($m['body_text'], 'Görünen metin'), $m['body_text']);
ok('boş parça IMAP numarasında SAYILIYOR (pdf = parça 4)', ($m['attachments'][0]['part'] ?? '') === '4', json_encode($m['attachments']));

echo "\n=== L2: ham 8-bit + encoded-word karışık başlık ===\n";
$m = mail_mime_mesaj(mail_test_raw(['subject' => "\xd6deme =?utf-8?B?" . base64_encode('Onayı') . "?=", 'from' => "\xd6mer <omer@x.com>"]));
ok('karışık başlık silinmiyor (Ödeme Onayı)', $m['subject'] === 'Ödeme Onayı', $m['subject']);
ok('gönderen kaybolmadı', $m['from_addr'] === 'omer@x.com' && str_contains($m['from_name'], 'mer'), json_encode([$m['from_addr'], $m['from_name']]));

echo "\n=== L3: IMAP ayrıştırıcı dayanıklılığı ===\n";
final class SonsuzSifirLiteral implements MailStream {
    public function readLine(int $max): ?string { return '* 1 FETCH {0}'; }
    public function readBytes(int $n): ?string { return ''; }
    public function write(string $d): void {}
    public function startTls(): bool { return false; }
    public function close(): void {}
}
$c = new MailImapClient(new SonsuzSifirLiteral(), ['sure' => 1.0]);
$t0 = microtime(true); $e = null;
try { $c->baslat(false); } catch (MailImapException $ex) { $e = $ex; }
ok('sonsuz "{0}" akışı hızla kesilir (limit/zaman), takılmaz', $e && $e->kind === 'limit' && microtime(true) - $t0 < 3, ($e?->getMessage() ?? 'hata yok') . ' ' . round(microtime(true) - $t0, 2) . 's');
$t = MailImapClient::belirtecle('(FLAGS (\Seen foo[) UID 105 RFC822.SIZE 99)', []);
$f = MailImapClient::fetchAlanlari($t[0]);
ok('"foo[" anahtar kelimesi satırın kalanını yutmuyor (UID korunur)', ($f['UID'] ?? null) === '105' && ($f['RFC822.SIZE'] ?? null) === '99', json_encode($f));
$t = MailImapClient::belirtecle('(UID 5 BODY[HEADER.FIELDS (A B)] "x")', []);
ok('BODY[...] köşeli bölge hâlâ tek atom', ($t[0][2] ?? '') === 'BODY[HEADER.FIELDS (A B)]', json_encode($t));

echo "\n=== L4: sanitizer sınırı sessiz değil ===\n";
$bayrak = false; $o = mail_html_sanitize(str_repeat('<span></span>', 20500) . 'SONRAKİ METİN', $bayrak);
ok('20 000 düğüm aşılınca bayrak kalkıyor', $bayrak === true);
$m = mail_mime_mesaj(mail_test_raw(['ctype' => 'text/html; charset=utf-8', 'body' => str_repeat('<span></span>', 20500) . 'X']));
ok('mesaj body_truncated=1 işaretleniyor', $m['body_truncated'] === 1);
$bayrak = false; mail_html_sanitize(str_repeat('<div>', 70) . 'x' . str_repeat('</div>', 70), $bayrak);
ok('70 derinlik: bayrak kalkıyor', $bayrak === true);
$bayrak = true; mail_html_sanitize('<p>normal</p>', $bayrak);
ok('normal HTML: bayrak sıfırlanıyor', $bayrak === false);
$t0 = microtime(true); $o = mail_html_sanitize(str_repeat('<p></p>', 200000));
ok('200 000 kardeş etiket: çıktı sınırlı ve hızlı', substr_count($o, '<p>') <= 20001 && microtime(true) - $t0 < 5, (string)substr_count($o, '<p>'));

echo "\n=== L5: bozuk Content-ID ek listesini silmiyor ===\n";
$ek = "--$b\r\nContent-Type: text/plain\r\n\r\nx\r\n--$b\r\nContent-Type: image/png\r\nContent-ID: <\xff@x>\r\nContent-Disposition: inline; filename=\"l.png\"\r\nContent-Transfer-Encoding: base64\r\n\r\nUE5H\r\n--$b--\r\n";
$m = mail_mime_mesaj(mail_test_raw(['ctype' => "multipart/related; boundary=\"$b\"", 'body' => $ek]));
$a5 = hesap('cid@asya.com'); srv($a5, [1 => M(mail_test_raw(['ctype' => "multipart/related; boundary=\"$b\"", 'body' => $ek, 'msgid' => '<cid@x>']))]);
senk($a5);
$js = $db->query("SELECT attachments_json, has_attachments FROM mail_messages WHERE account_id = $a5")->fetch();
ok('attachments_json geçerli ve dolu', $js && (int)$js['has_attachments'] === 1 && is_array(json_decode((string)$js['attachments_json'], true)) && count(json_decode($js['attachments_json'], true)) === 1, json_encode($js));
mail_test_bitir();
