<?php
// =========================================================
// scripts/mail_view_smoke.php — Gelen kutusu sorguları, ACL, durum değişiklikleri, ek indirme (M3)
//   php scripts/mail_view_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
require_once __DIR__ . '/_mail_test_lib.php';
require_once $ROOT . '/config/mail_imap.php';
require_once $ROOT . '/config/mail_mime.php';
require_once $ROOT . '/config/mail_sync.php';
require_once $ROOT . '/config/mail_view.php';
require_once $ROOT . '/config/mail_attach.php';
require_once __DIR__ . '/_mail_fake_imap.php';

$db = db(); mail_test_diger_tablolar($db); mail_test_sema_kur($db); mail_test_anahtar_kur();

function hesap(string $e): int {
    global $db;
    $r = mail_hesap_kaydet(['label' => $e, 'email' => $e, 'imap_host' => 'i.test.com', 'imap_user' => $e, 'imap_pass' => 'imap-parola-ABC', 'smtp_host' => 's.test.com', 'smtp_user' => $e, 'smtp_pass' => 'smtp-parola-XYZ'], null, 1, $db);
    return $r['id'];
}
function ekle(int $hid, int $uid, array $o = []): int {
    global $db;
    $o += ['subject' => "Konu $uid", 'from' => 'Ahmet', 'addr' => 'ahmet@x.com', 'okundu' => 0, 'tarih' => '2026-10-05 10:00:00', 'body' => 'Gövde ' . $uid, 'needs' => 1, 'replied' => null, 'tr' => 'skipped', 'ek' => null];
    $db->prepare("INSERT INTO mail_messages (account_id, folder, uidvalidity, uid, message_id_hash, subject, from_name, from_addr, received_at, body_text, is_read, needs_reply, replied_at, tr_status, attachments_json, has_attachments, to_addrs, cc_addrs)
        VALUES (?, 'INBOX', 1000, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '[]', '[]')")
        ->execute([$hid, $uid, sha1("$hid:$uid"), $o['subject'], $o['from'], $o['addr'], $o['tarih'], $o['body'], $o['okundu'], $o['needs'], $o['replied'], $o['tr'], $o['ek'] ? json_encode($o['ek']) : null, $o['ek'] ? 1 : 0]);
    return (int)$db->lastInsertId();
}
$A = hesap('a@asya.com'); $B = hesap('b@asya.com');
$a1 = ekle($A, 1, ['subject' => 'Sipariş %100 indirim_özel', 'tarih' => '2026-10-01 10:00:00']);
$a2 = ekle($A, 2, ['okundu' => 1, 'tarih' => '2026-10-02 10:00:00', 'replied' => '2026-10-02 12:00:00']);
$a3 = ekle($A, 3, ['tarih' => '2026-10-03 10:00:00', 'tr' => 'failed']);
$b1 = ekle($B, 1, ['subject' => 'B hesabının gizli maili', 'tarih' => '2026-10-04 10:00:00']);

echo "=== Liste + filtreler + ACL ===\n";
$l = mail_mesaj_listele($db, [$A], 'gelen', '', 1);
ok('yalnız görünür hesabın mailleri, yeniden eskiye', array_column($l['satirlar'], 'id') == [$a3, $a2, $a1] && $l['toplam'] === 3);
ok('B hesabının maili A kapsamında GÖRÜNMÜYOR', !in_array($b1, array_column($l['satirlar'], 'id')));
ok('boş kapsam (atanmamış kullanıcı) → hiçbir şey', mail_mesaj_listele($db, [], 'gelen', '', 1) === ['satirlar' => [], 'toplam' => 0]);
ok('okunmamış filtresi', array_column(mail_mesaj_listele($db, [$A], 'okunmamis', '', 1)['satirlar'], 'id') == [$a3, $a1]);
ok('cevap bekleyen: needs_reply=1 ve cevaplanmamış', array_column(mail_mesaj_listele($db, [$A], 'cevap', '', 1)['satirlar'], 'id') == [$a3, $a1]);
$l = mail_mesaj_listele($db, [$A, $B], 'gelen', '', 1);
ok('iki hesap birlikte', $l['toplam'] === 4);
ok('arama konuda', array_column(mail_mesaj_listele($db, [$A], 'gelen', 'Sipariş', 1)['satirlar'], 'id') == [$a1]);
ok('arama LIKE jokerlerini KAÇIRIYOR (% ve _ düz karakter)', mail_mesaj_listele($db, [$A], 'gelen', '%', 1)['toplam'] === 1 && mail_mesaj_listele($db, [$A], 'gelen', '_', 1)['toplam'] === 1 && mail_mesaj_listele($db, [$A], 'gelen', 'x%y', 1)['toplam'] === 0);
ok('arama enjeksiyonu: tırnak/kaçış zararsız', mail_mesaj_listele($db, [$A], 'gelen', "' OR 1=1 --", 1)['toplam'] === 0);
ok('arama gönderende', mail_mesaj_listele($db, [$A], 'gelen', 'ahmet@x', 1)['toplam'] === 3);
for ($i = 10; $i < 75; $i++) ekle($A, $i, ['tarih' => date('Y-m-d', strtotime('2026-08-01') + ($i - 9) * 86400) . ' 10:00:00']);   // geçerli tarihler (MySQL strict '09-31'i reddeder)
$p1 = mail_mesaj_listele($db, [$A], 'gelen', '', 1); $p3 = mail_mesaj_listele($db, [$A], 'gelen', '', 3);
ok('sayfalama: 30/sayfa, toplam doğru', count($p1['satirlar']) === 30 && $p1['toplam'] === 68 && count($p3['satirlar']) === 8, count($p3['satirlar']) . '/' . $p1['toplam']);
ok('ozet alanı kısaltılıyor (≤300 karakter, SUBSTR)', strlen($p1['satirlar'][0]['ozet']) <= 300);
ok('mail_oz kısaltma + alıntı işareti', mail_oz(str_repeat('a ', 200), 20) === 'a a a a a a a a a a…' || mb_strlen(mail_oz(str_repeat('a ', 200), 20)) === 20);

echo "\n=== Sayılar ===\n";
$sy = mail_filtre_sayilari($db, [$A]);
ok('rozet sayıları', $sy['gelen'] === 68 && $sy['okunmamis'] === 67 && $sy['taslak'] === 0, json_encode($sy));
ok('boş kapsam sayıları sıfır', array_sum(mail_filtre_sayilari($db, [])) === 0);
$db->exec("INSERT INTO mail_outbox (account_id, idempotency_key, status, to_addr, subject, created_at) VALUES ($A, 'k1', 'translated', 'm@x.com', 'Taslak', '2026-10-05 10:00:00'), ($A, 'k2', 'sent', 'm@x.com', 'Gitti', '2026-10-05 11:00:00'), ($A, 'k3', 'failed', 'm@x.com', 'Patladı', '2026-10-05 12:00:00'), ($B, 'k4', 'sent', 'z@x.com', 'B gitti', '2026-10-05 12:00:00')");
$sy = mail_filtre_sayilari($db, [$A]);
ok('outbox sayıları yalnız görünür hesap', $sy['taslak'] === 1 && $sy['gonderilen'] === 1 && $sy['hatali'] === 2, json_encode($sy));   // 1 failed outbox + 1 tr failed mesaj
ok('outbox listesi (gönderilen) yalnız görünür hesap', array_column(mail_outbox_listele($db, [$A], 'gonderilen', '', 1)['satirlar'], 'subject') == ['Gitti']);
ok('taslak/bekleyen listesi', array_column(mail_outbox_listele($db, [$A], 'taslak', '', 1)['satirlar'], 'subject') == ['Taslak']);
ok('çevirisi başarısız mailler hatalı görünümünde', array_column(mail_ceviri_hatalilari($db, [$A]), 'id') == [$a3]);
ok('filtre doğrulama: bilinmeyen → gelen', mail_filtre_gecerli("x'; DROP") === 'gelen' && mail_filtre_gecerli('cevap') === 'cevap');

echo "\n=== Tek mesaj ACL (IDOR) ===\n";
ok('görünür hesaptaki mesaj açılır', mail_mesaj_getir($db, $a1, [$A])['id'] == $a1);
ok('BAŞKA hesabın mesajı → null (varlık sızmaz)', mail_mesaj_getir($db, $b1, [$A]) === null);
ok('boş kapsam → null', mail_mesaj_getir($db, $a1, []) === null);
ok('olmayan id → null', mail_mesaj_getir($db, 99999, [$A]) === null && mail_mesaj_getir($db, -1, [$A]) === null && mail_mesaj_getir($db, 0, [$A]) === null);

echo "\n=== Durum değişiklikleri ===\n";
ok('okundu yaz', mail_okundu_yaz($db, $a1, 7, true, [$A]) && (int)$db->query("SELECT is_read FROM mail_messages WHERE id = $a1")->fetchColumn() === 1 && (int)$db->query("SELECT read_by FROM mail_messages WHERE id = $a1")->fetchColumn() === 7);
ok('okunmadı yaz', mail_okundu_yaz($db, $a1, 7, false, [$A]) && (int)$db->query("SELECT is_read FROM mail_messages WHERE id = $a1")->fetchColumn() === 0);
ok('başka hesabın mesajı okundu İŞARETLENEMEZ', mail_okundu_yaz($db, $b1, 7, true, [$A]) === false && (int)$db->query("SELECT is_read FROM mail_messages WHERE id = $b1")->fetchColumn() === 0);
ok('cevaplandı say / cevap bekliyor say', mail_cevap_durumu_yaz($db, $a1, false, [$A]) && $db->query("SELECT replied_at FROM mail_messages WHERE id = $a1")->fetchColumn() !== null && mail_cevap_durumu_yaz($db, $a1, true, [$A]) && $db->query("SELECT replied_at FROM mail_messages WHERE id = $a1")->fetchColumn() === null);
ok('başka hesapta cevap durumu DEĞİŞTİRİLEMEZ', mail_cevap_durumu_yaz($db, $b1, false, [$A]) === false);

echo "\n=== Yardımcılar ===\n";
ok('riskli ek türleri', mail_ek_tehlikeli('fatura.exe') && mail_ek_tehlikeli('a.HTML') && mail_ek_tehlikeli('x.svg') && mail_ek_tehlikeli('x.pdf', 'text/html') && mail_ek_tehlikeli('m.docm') && !mail_ek_tehlikeli('fatura.pdf', 'application/pdf') && !mail_ek_tehlikeli('foto.jpg', 'image/jpeg'));
ok('boyut biçimi', mail_boyut_fmt(500) === '500 B' && mail_boyut_fmt(2048) === '2 KB' && str_contains(mail_boyut_fmt(5242880), 'MB'));
ok('zaman biçimi', mail_zaman_fmt(date('Y-m-d') . ' 14:05:00') === '14:05' && mail_zaman_fmt('2020-01-02 03:04:05') === '02.01.2020' && mail_zaman_fmt(null) === '');
ok('gönderen adı yedeği', mail_gonderen_adi(['from_name' => '', 'from_addr' => 'a@b.com']) === 'a@b.com' && mail_gonderen_adi(['from_name' => 'Ali', 'from_addr' => 'a@b.com']) === 'Ali' && mail_gonderen_adi([]) === '(bilinmeyen)');
ok('dosya adı: RTL geçersiz kılma (U+202E) temizlenir', mail_mime_gonder_adi("fatura\u{202E}fdp.exe") === 'faturafdp.exe' && mail_ek_tehlikeli(mail_mime_gonder_adi("fatura\u{202E}fdp.exe")));

echo "\n=== Ek indirme ===\n";
$pdf = 'PDF-1.4 fake'; $exe = "MZ\x90\x00exe";
$raw = mail_test_raw(['msgid' => '<ek@x>', 'ctype' => 'multipart/mixed; boundary="B"', 'body' =>
    "--B\r\nContent-Type: text/plain\r\n\r\nmerhaba\r\n--B\r\nContent-Type: application/pdf; name=\"fatura.pdf\"\r\nContent-Disposition: attachment; filename=\"fatura.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($pdf)) .
    "--B\r\nContent-Type: application/octet-stream; name=\"x.exe\"\r\nContent-Disposition: attachment; filename=\"x.exe\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($exe)) .
    "--B\r\nContent-Type: text/html; name=\"a.html\"\r\nContent-Disposition: attachment; filename=\"a.html\"\r\n\r\n<script>alert(1)</script>\r\n--B--\r\n"]);
$parsed = mail_mime_mesaj($raw);
$ekMsg = ekle($A, 500, ['subject' => 'Ekli', 'ek' => $parsed['attachments']]);
$parcalar = ['2' => chunk_split(base64_encode($pdf)), '3' => chunk_split(base64_encode($exe)), '4' => '<script>alert(1)</script>'];
$srv = new FakeMailStream(['user' => 'a@asya.com', 'pass' => 'imap-parola-ABC', 'uidvalidity' => 1000, 'mesajlar' => [500 => ['raw' => $raw, 'flags' => [], 'date' => '2026-10-05 10:00:00', 'parcalar' => $parcalar]]]);
$fab = function (array $h) use (&$srv) { $c = new MailImapClient($srv->yeniBaglanti()); $c->baslat(false); $c->girisYap($h['imap_user'], $h['imap_pass']); return $c; };
$r = mail_ek_hazirla($db, $ekMsg, '2', [$A], ['istemci' => $fab]);
ok('PDF eki indirildi ve base64 çözüldü', $r['ok'] && $r['veri'] === $pdf && $r['ad'] === 'fatura.pdf' && $r['mime'] === 'application/pdf' && !$r['tehlikeli'], json_encode($r));
$r = mail_ek_hazirla($db, $ekMsg, '3', [$A], ['istemci' => $fab]);
ok('.exe: octet-stream\'e zorlanır + riskli işaretli', $r['ok'] && $r['mime'] === 'application/octet-stream' && $r['tehlikeli'] === true);
$r = mail_ek_hazirla($db, $ekMsg, '4', [$A], ['istemci' => $fab]);
ok('HTML eki: text/html olarak SERVİS EDİLMEZ (octet-stream) — saklı XSS yok', $r['ok'] && $r['mime'] === 'application/octet-stream' && $r['tehlikeli'] === true);
ok('başka hesap kullanıcısı eki İNDİREMEZ (404)', mail_ek_hazirla($db, $ekMsg, '2', [$B], ['istemci' => $fab]) === ['ok' => false, 'kod' => 404, 'mesaj' => 'Ek bulunamadı.']);
ok('boş kapsam → 404', mail_ek_hazirla($db, $ekMsg, '2', [], ['istemci' => $fab])['kod'] === 404);
ok('mesajın ek listesinde OLMAYAN parça no reddedilir (beyaz liste)', mail_ek_hazirla($db, $ekMsg, '9', [$A], ['istemci' => $fab])['kod'] === 404);
ok('parça numarası enjeksiyonu reddedilir', mail_ek_hazirla($db, $ekMsg, "2]\r\nA9 DELETE", [$A], ['istemci' => $fab])['kod'] === 404 && mail_ek_hazirla($db, $ekMsg, '1.x', [$A], ['istemci' => $fab])['kod'] === 404);
$srv = new FakeMailStream(['user' => 'a@asya.com', 'pass' => 'imap-parola-ABC', 'uidvalidity' => 7777, 'mesajlar' => [500 => ['raw' => $raw, 'flags' => [], 'date' => '2026-10-05 10:00:00', 'parcalar' => ['2' => 'BASKA MESAJ']]]]);
$r = mail_ek_hazirla($db, $ekMsg, '2', [$A], ['istemci' => $fab]);
ok('UIDVALIDITY değişmişse ek İNDİRİLMEZ (UID başka mesajı gösterebilir) → 409', $r['ok'] === false && $r['kod'] === 409 && !str_contains($r['mesaj'], 'BASKA'), json_encode($r));
$srv = new FakeMailStream(['user' => 'a@asya.com', 'pass' => 'YANLIS', 'uidvalidity' => 1000, 'mesajlar' => []]);
$r = mail_ek_hazirla($db, $ekMsg, '2', [$A], ['istemci' => $fab]);
ok('IMAP hatası → 502, parola sızmıyor', $r['ok'] === false && $r['kod'] === 502 && !str_contains($r['mesaj'], 'imap-parola-ABC') && !str_contains($r['mesaj'], 'YANLIS'));
$srv = new FakeMailStream(['user' => 'a@asya.com', 'pass' => 'imap-parola-ABC', 'uidvalidity' => 1000, 'mesajlar' => []]);
ok('sunucuda silinmiş ek → 404', mail_ek_hazirla($db, $ekMsg, '2', [$A], ['istemci' => $fab])['kod'] === 404);
$db->exec("UPDATE mail_messages SET attachments_json = '" . json_encode([['part' => '2', 'filename' => 'dev.bin', 'mime' => 'application/octet-stream', 'size' => 99999999, 'cte' => 'base64']]) . "' WHERE id = $ekMsg");
ok('dev ek (> 15 MB) reddedilir → 413', mail_ek_hazirla($db, $ekMsg, '2', [$A], ['istemci' => $fab])['kod'] === 413);
$src = file_get_contents($ROOT . '/mail_ek.php');
ok('mail_ek.php: require_mail(read) + ACL + attachment + nosniff + no-store + sandbox CSP + audit', str_contains($src, "require_mail('read')") && str_contains($src, 'mail_gorunur_hesap_idleri') && str_contains($src, 'Content-Disposition: attachment') && str_contains($src, 'X-Content-Type-Options: nosniff') && str_contains($src, 'Cache-Control: no-store') && str_contains($src, "Content-Security-Policy: sandbox") && str_contains($src, 'mail_attachment_download') && str_contains($src, 'session_write_close()'));
mail_test_bitir();
