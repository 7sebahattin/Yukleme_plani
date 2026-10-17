<?php
// =========================================================
// scripts/mail_sync_smoke.php — IMAP senkron motoru (M2), SAHTE IMAP sunucusu + bellek içi SQLite.
//   php scripts/mail_sync_smoke.php   → çıkış kodu 0 = tüm testler geçti
// Gereksinimler (görev §12): çiftleme yok · okunmuş mail kaçmıyor · IMAP kesilince veri bozulmuyor ·
// bir hesap hata verince diğerleri çalışıyor · credential/log sızıntısı yok.
// =========================================================
require_once __DIR__ . '/_mail_test_lib.php';
require_once $ROOT . '/config/mail_imap.php';
require_once $ROOT . '/config/mail_mime.php';
require_once $ROOT . '/config/mail_sync.php';
require_once $ROOT . '/config/mail_view.php';
require_once __DIR__ . '/_mail_fake_imap.php';

$db = db();
mail_test_diger_tablolar($db);
mail_test_sema_kur($db);
mail_test_anahtar_kur();
$KILIT = sys_get_temp_dir() . '/mail_sync_kilit_' . getmypid();
@mkdir($KILIT, 0700, true);

function hesapEkle(string $email, string $ipass = 'imap-parola-ABC', array $ek = []): int {
    global $db;
    $r = mail_hesap_kaydet(array_merge(['label' => $email, 'email' => $email, 'imap_host' => 'imap.test.com', 'imap_user' => $email, 'imap_pass' => $ipass,
        'smtp_host' => 'smtp.test.com', 'smtp_user' => $email, 'smtp_pass' => 'smtp-parola-XYZ', 'initial_days' => 30], $ek), null, 1, $db);
    if (!$r['ok']) throw new RuntimeException(json_encode($r));
    return $r['id'];
}
/** @var FakeMailStream[] $SUNUCULAR hesapId => sahte sunucu */
$SUNUCULAR = []; $BAGLANTILAR = 0;
function fabrika(): callable {
    return function (array $h) {
        global $SUNUCULAR, $BAGLANTILAR; $BAGLANTILAR++;
        $srv = $SUNUCULAR[(int)$h['id']] ?? null;
        if ($srv === null) throw new MailImapException('connect', 'Sunucuya bağlanılamadı (imap.test.com:993): connection refused');
        $c = new MailImapClient($srv->yeniBaglanti());
        $c->baslat(false);
        $c->girisYap($h['imap_user'], $h['imap_pass']);
        return $c;
    };
}
function sunucu(int $hid, array $mesajlar, array $ek = []): FakeMailStream {
    global $SUNUCULAR, $db;
    $e = $db->query("SELECT imap_user FROM mail_accounts WHERE id = $hid")->fetchColumn();
    return $SUNUCULAR[$hid] = new FakeMailStream(array_merge(['user' => $e, 'pass' => 'imap-parola-ABC', 'mesajlar' => $mesajlar], $ek));
}
function msg(string $id, string $subj, string $date, array $f = [], array $raw = []): array {
    return ['raw' => mail_test_raw(array_merge(['msgid' => "<$id@musteri.com>", 'subject' => $subj, 'date' => $date], $raw)), 'flags' => $f, 'date' => $date];
}
function senk(int $hid, array $ek = []): array {
    global $db, $KILIT;
    return mail_sync_hesap($db, $hid, array_merge(['istemci' => fabrika(), 'kilit_dizin' => $KILIT, 'simdi' => strtotime('2026-10-06 12:00:00'), 'zorla' => true], $ek));
}
function sayi(string $tablo = 'mail_messages', string $where = '1=1'): int { global $db; return (int)$db->query("SELECT COUNT(*) FROM $tablo WHERE $where")->fetchColumn(); }

echo "=== 1. Temel akış ===\n";
$a1 = hesapEkle('info@asya.com');
$srv = sunucu($a1, [
    5 => msg('a', 'Birinci', '2026-10-04 09:00:00', ['\Seen']),
    6 => msg('b', 'İkinci', '2026-10-05 09:00:00'),
    9 => msg('c', 'Üçüncü', '2026-10-05 15:00:00', ['\Seen']),
    2 => msg('eski', 'Çok eski', '2026-08-01 09:00:00'),
]);
$r = senk($a1);
ok('ilk senkron OK, 3 mesaj (30 günden eski olan alınmadı)', $r['ok'] && $r['inserted'] === 3 && sayi() === 3, json_encode($r));
$st = $db->query("SELECT * FROM mail_sync_state WHERE account_id = $a1")->fetch();
ok('durum: uidvalidity + imleç = son UID', (int)$st['uidvalidity'] === 1000 && (int)$st['last_uid'] === 9 && $st['last_error'] === null && (int)$st['consecutive_failures'] === 0);
ok('günlük satırı ok + sayılar', $db->query('SELECT status, inserted FROM mail_sync_log ORDER BY id DESC LIMIT 1')->fetch() == ['status' => 'ok', 'inserted' => 3]);
ok('ilk taramada is_read = sunucu \\Seen (eski mailler rozet şişirmesin)', sayi('mail_messages', 'is_read = 1') === 2 && sayi('mail_messages', 'is_read = 0') === 1);
ok('imap_seen bilgisi saklandı', sayi('mail_messages', 'imap_seen = 1') === 2);
ok('sunucuya UNSEEN / STORE / SELECT / \\Seen yazan komut GİTMEDİ', !preg_grep('/UNSEEN|STORE|\bSELECT\b|\+FLAGS|-FLAGS|EXPUNGE|DELETE|APPEND/i', $srv->received));
ok('tüm gövde çekimleri BODY.PEEK', !preg_grep('/FETCH.*[^.]BODY\[/', $srv->received) && (bool)preg_grep('/BODY\.PEEK\[\]/', $srv->received));
ok('EXAMINE (salt okunur)', (bool)preg_grep('/EXAMINE/', $srv->received));
$k = $db->query("SELECT * FROM mail_messages WHERE uid = 6")->fetch();
ok('alanlar: konu/gönderen/tarih/gövde', $k['subject'] === 'İkinci' && $k['from_addr'] === 'ahmet@musteri.com' && $k['received_at'] === '2026-10-05 09:00:00' && str_contains($k['body_text'], 'Dünya') && $k['tr_status'] === 'skipped');

echo "\n=== 2. İkinci çalıştırma: çiftleme yok ===\n";
$r = senk($a1);
ok('yeni mail yok → 0 ekleme, 3 satır', $r['ok'] && $r['inserted'] === 0 && sayi() === 3);
$r = senk($a1); $r = senk($a1);
ok('art arda çalıştırmalar satır çoğaltmıyor', sayi() === 3);
// "UID n:*" tuzağı: imleç 9, sunucu son UID 9 → UID 10:* aranırsa sunucu 9'u döndürür; süzülmeli
$srv->cfg['uidnext'] = 9;   // uidnext yalan söylese bile (hızlı yol kapalı) tuzak süzülür
$r = senk($a1);
ok('UID n:* tuzağı süzülüyor (aynı mesaj yeniden eklenmiyor)', sayi() === 3 && $r['inserted'] === 0);
$srv->cfg['uidnext'] = null;
echo "\n=== 3. Yeni + başka cihazdan OKUNMUŞ mail kaçmıyor ===\n";
$srv->mesajlar[10] = msg('d', 'Telefonda okundu', '2026-10-06 08:00:00', ['\Seen']);
$srv->mesajlar[11] = msg('e', 'Okunmamış', '2026-10-06 09:00:00');
$r = senk($a1);
ok('iki yeni mail alındı (biri sunucuda ZATEN okunmuş)', $r['inserted'] === 2 && sayi() === 5, json_encode($r));
$o = $db->query("SELECT is_read, imap_seen FROM mail_messages WHERE uid = 10")->fetch();
ok('sunucuda okunmuş yeni mail: imap_seen=1, panelde is_read=0 (cevap bekleyen akışında kaybolmaz)', (int)$o['imap_seen'] === 1 && (int)$o['is_read'] === 0);
ok('imleç ilerledi', (int)$db->query("SELECT last_uid FROM mail_sync_state WHERE account_id = $a1")->fetchColumn() === 11);

echo "\n=== 4. Kayıt katmanı idempotent ===\n";
$m = mail_mime_mesaj($srv->mesajlar[11]['raw']);
$x = mail_mesaj_kaydet($db, $db->query("SELECT * FROM mail_accounts WHERE id = $a1")->fetch(), 'INBOX', 1000, 11, $m, ['flags' => []], false, '2026-10-06 12:00:00');
ok('aynı (hesap,klasör,uidvalidity,uid) ikinci kez yazılmıyor', $x === 'tekrar' && sayi() === 5);
try { $db->exec("INSERT INTO mail_messages (account_id, folder, uidvalidity, uid, message_id_hash, received_at) VALUES ($a1, 'INBOX', 1000, 11, 'x', '2026-10-06 12:00:00')"); $dup = false; }
catch (PDOException $e) { $dup = true; }
ok('DB UNIQUE anahtarı çift satırı REDDEDİYOR (son savunma)', $dup);
$tutulan = mail_kilit_al('account_' . $a1, $KILIT);
$r = senk($a1);
ok('aynı hesap için başka süreç çalışıyorsa busy (paralel koşu işlemiyor)', $r['busy'] === true && $r['inserted'] === 0);
mail_kilit_birak($tutulan);
$r = senk($a1);
ok('kilit bırakılınca normal çalışır', $r['busy'] === false && $r['ok']);

echo "\n=== 5. UIDVALIDITY değişimi ===\n";
$once = sayi();
$yeni = [];
foreach ($db->query("SELECT uid, message_id FROM mail_messages WHERE account_id = $a1 ORDER BY uid")->fetchAll() as $row) {
    // Aynı mesaj yeni UID'le geri gelir: ham baytlar (Message-ID + gönderen + konu + Date) AYNI.
    $yeni[(int)$row['uid'] + 100] = ['raw' => $srv->mesajlar[(int)$row['uid']]['raw'], 'flags' => [], 'date' => '2026-10-05 10:00:00'];
}
$yeni[200] = msg('yepyeni', 'Epoch sonrası yeni', '2026-10-06 10:00:00');
$srv = sunucu($a1, $yeni, ['uidvalidity' => 2000]);
$r = senk($a1);
ok('UIDVALIDITY değişti: eski kayıtlar SİLİNMEDİ', sayi('mail_messages', "uidvalidity = 1000") + sayi('mail_messages', "uidvalidity = 2000") === sayi());
ok('aynı mesajlar ÇİFTLENMEDİ; yalnız gerçekten yeni olan eklendi', sayi() === $once + 1 && $r['inserted'] === 1, "önce=$once şimdi=" . sayi() . ' ' . json_encode($r));
ok('uyarı notu yazıldı', (bool)preg_grep('/UIDVALIDITY değişti/', $r['notlar']));
ok('durum yeni epoch + imleç', (int)$db->query("SELECT uidvalidity FROM mail_sync_state WHERE account_id = $a1")->fetchColumn() === 2000 && (int)$db->query("SELECT last_uid FROM mail_sync_state WHERE account_id = $a1")->fetchColumn() === 200);
$r = senk($a1);
ok('epoch sonrası ikinci çalıştırma çoğaltmıyor', sayi() === $once + 1);

echo "\n=== 6. Aynı epoch'ta aynı Message-ID, farklı UID = AYRI mesaj ===\n";
$a6 = hesapEkle('sabitid@asya.com');
sunucu($a6, [1 => msg('ayni', 'Rapor 1', '2026-10-05 09:00:00'), 2 => msg('ayni', 'Rapor 2', '2026-10-05 10:00:00')]);
senk($a6);
ok('bozuk gönderici sabit Message-ID basarsa iki mail de korunur', sayi('mail_messages', "account_id = $a6") === 2);

echo "\n=== 7. IMAP kesilince veri bozulmuyor ===\n";
$a7 = hesapEkle('kopan@asya.com');
$m7 = [];
for ($i = 1; $i <= 6; $i++) $m7[$i] = msg("k$i", "Mesaj $i", '2026-10-05 1' . $i . ':00:00');
// komut sırası: CAPABILITY, AUTH, (auth-data sayılmaz), CAPABILITY, EXAMINE, UID SEARCH, UID FETCH meta, 6×UID FETCH body …
$srv = sunucu($a7, $m7, ['drop_after' => 9]);
$r = senk($a7);
ok('bağlantı kopunca hata raporlanır, istisna sızmaz', $r['ok'] === false && $r['error'] !== null, json_encode($r));
$n = sayi('mail_messages', "account_id = $a7");
ok('kopmadan önce işlenen mesajlar KORUNDU, yarım mesaj yok', $n > 0 && $n < 6, "n=$n");
$lastUid = (int)$db->query("SELECT last_uid FROM mail_sync_state WHERE account_id = $a7")->fetchColumn();
ok('imleç = işlenmiş son UID (geri alınmadı, ileri de atlamadı)', $lastUid === $n, "imleç=$lastUid n=$n");
ok('hata durumu kaydedildi (consecutive_failures=1)', (int)$db->query("SELECT consecutive_failures FROM mail_sync_state WHERE account_id = $a7")->fetchColumn() === 1);
ok('günlük satırı status=error', $db->query("SELECT status FROM mail_sync_log WHERE account_id = $a7 ORDER BY id DESC LIMIT 1")->fetchColumn() === 'error');
sunucu($a7, $m7);
$r = senk($a7);
ok('bağlantı düzelince kaldığı yerden devam, TOPLAM tam 6, çift yok', $r['ok'] && sayi('mail_messages', "account_id = $a7") === 6, json_encode($r));
ok('hata sayacı başarıyla sıfırlandı', (int)$db->query("SELECT consecutive_failures FROM mail_sync_state WHERE account_id = $a7")->fetchColumn() === 0);
// Literal ortasında kopma
$a7b = hesapEkle('yarim@asya.com');
sunucu($a7b, [1 => msg('y1', 'Yarım', '2026-10-05 10:00:00')], ['drop_in_fetch' => true]);
$r = senk($a7b);
ok('literal ortasında kopma: kısmi/yarım mesaj DB\'ye YAZILMADI', $r['ok'] === false && sayi('mail_messages', "account_id = $a7b") === 0);

echo "\n=== 8. Bir hesap hata verince diğerleri çalışır ===\n";
$aBozuk = hesapEkle('bozuk@asya.com', 'dogru-parola-QQQ');
$aSaglam = hesapEkle('saglam@asya.com');
sunucu($aBozuk, [1 => msg('z1', 'Z', '2026-10-05 10:00:00')], ['pass' => 'BASKA-parola']);   // yanlış şifre
sunucu($aSaglam, [1 => msg('s1', 'S', '2026-10-05 10:00:00')]);
$aYok = hesapEkle('sunucuyok@asya.com');   // $SUNUCULAR'da yok → bağlantı reddedildi
$sonuc = mail_sync_tum($db, ['istemci' => fabrika(), 'kilit_dizin' => $KILIT, 'simdi' => strtotime('2026-10-06 12:00:00')]);
ok('yanlış şifreli hesap hata verdi', $sonuc[$aBozuk]['ok'] === false && str_contains($sonuc[$aBozuk]['error'], 'auth'), json_encode($sonuc[$aBozuk]));
ok('sunucusu yanıt vermeyen hesap hata verdi', $sonuc[$aYok]['ok'] === false);
ok('sağlam hesap YİNE DE senkronlandı', $sonuc[$aSaglam]['ok'] === true && $sonuc[$aSaglam]['inserted'] === 1);
ok('hatalı hesabın durumunda hata + sayaç', (int)$db->query("SELECT consecutive_failures FROM mail_sync_state WHERE account_id = $aBozuk")->fetchColumn() === 1);
$db->exec("UPDATE mail_accounts SET is_active = 0 WHERE id = $aYok");
$sonuc = mail_sync_tum($db, ['istemci' => fabrika(), 'kilit_dizin' => $KILIT]);
ok('pasif hesap senkronlanmaz', !isset($sonuc[$aYok]));
$pasifTek = mail_sync_hesap($db, $aYok, ['istemci' => fabrika(), 'kilit_dizin' => $KILIT]);
ok('pasif hesap doğrudan çağrılsa da bağlanmaz', $pasifTek['ok'] && $pasifTek['inserted'] === 0 && (bool)preg_grep('/pasif/', $pasifTek['notlar']));

echo "\n=== 9. Credential / log sızıntısı yok ===\n";
$tum = '';
foreach (['mail_sync_log', 'mail_sync_state', 'audit_log'] as $t) $tum .= json_encode($db->query("SELECT * FROM $t")->fetchAll());
$logDosya = sys_get_temp_dir() . '/mail_test_' . getmypid() . '.log';
$tum .= is_file($logDosya) ? (string)file_get_contents($logDosya) : '';
foreach (['BASKA-parola', 'dogru-parola-QQQ', 'imap-parola-ABC', 'smtp-parola-XYZ'] as $sir) {
    ok("günlük/durum/audit/error_log '$sir' İÇERMİYOR", !str_contains($tum, $sir));
}
ok('hata metinleri kısa (≤255) ve kontrollü', (int)$db->query("SELECT MAX(LENGTH(error)) FROM mail_sync_log")->fetchColumn() <= 255);
putenv('MAIL_MASTER_KEY');
$r = senk($a1);
ok('anahtar yokken senkron güvenle hata verir, veri etkilenmez', $r['ok'] === false && str_contains($r['error'], 'Kimlik bilgisi çözülemedi') && sayi() >= $once);
mail_test_anahtar_kur();   // yeni anahtar → eski blob'lar çözülemez
$r = senk($a1);
ok('anahtar değişmişse (kid uyuşmaz) kimlik çözülemez → hata, çökme yok', $r['ok'] === false);
$db->exec("DELETE FROM mail_messages WHERE 1=0");

echo "\n=== 10. Thread ===\n";
putenv('MAIL_MASTER_KEY=' . base64_encode(str_repeat("\x07", 16) . 'abcdefghijklmnop'));
$a10 = hesapEkle('thread@asya.com');
$kok = msg('kok', 'Sipariş', '2026-10-05 09:00:00');
$cevap = msg('cevap1', 'Re: Sipariş', '2026-10-05 10:00:00', [], ['extra' => ['In-Reply-To: <kok@musteri.com>', 'References: <kok@musteri.com>']]);
$cevap2 = msg('cevap2', 'RE: Sipariş', '2026-10-05 11:00:00', [], ['extra' => ['In-Reply-To: <cevap1@musteri.com>', 'References: <kok@musteri.com> <cevap1@musteri.com>']]);
$baska = msg('baska', 'Sipariş', '2026-10-05 12:00:00');   // AYNI konu, bağlantısız → ayrı thread (konu tahmini yok)
sunucu($a10, [1 => $cevap2, 2 => $cevap, 3 => $kok, 4 => $baska]);   // ters sırada varış: önce cevap2, sonra cevap, en son kök
senk($a10);
$th = $db->query("SELECT uid, thread_id FROM mail_messages WHERE account_id = $a10 ORDER BY uid")->fetchAll(PDO::FETCH_KEY_PAIR);
ok('ters sırada gelen cevaplar + kök TEK thread', $th[1] === $th[2] && $th[2] === $th[3], json_encode($th));
ok('bağlantısız aynı-konulu mail AYRI thread (yanlış birleştirme yok)', $th[4] !== $th[1]);
$tr = $db->query("SELECT message_count, subject_norm FROM mail_threads WHERE id = " . (int)$th[1])->fetch();
ok('thread sayacı = 3, normalize konu "Sipariş"', (int)$tr['message_count'] === 3 && $tr['subject_norm'] === 'Sipariş', json_encode($tr));
ok('mail_konu_norm', mail_konu_norm('RE: Fwd: AW: Ответ: Konu') === 'Konu' && mail_konu_norm('Re[2]: x') === 'x');
ok('thread başka hesapla karışmıyor', sayi('mail_threads', "account_id <> $a10 AND id IN (" . implode(',', array_map('intval', $th)) . ")") === 0);

echo "\n=== 11. Gövde sınırları + güvenli HTML kalıcı ===\n";
$a11 = hesapEkle('buyuk@asya.com');
$html = '<p>Merhaba</p><script>alert(1)</script><img src="https://track.evil/p.gif"><a href="javascript:alert(1)">x</a>';
sunucu($a11, [
    1 => ['raw' => mail_test_raw(['ctype' => 'text/html; charset=utf-8', 'body' => $html, 'msgid' => '<h1@x>']), 'flags' => [], 'date' => '2026-10-05 10:00:00'],
    2 => ['raw' => mail_test_raw(['body' => str_repeat('B', 3000), 'msgid' => '<big@x>']), 'flags' => [], 'date' => '2026-10-05 11:00:00'],
]);
senk($a11);
$h1 = $db->query("SELECT body_html_safe, body_text FROM mail_messages WHERE account_id = $a11 AND uid = 1")->fetch();
ok('DB\'ye yazılan HTML temizlenmiş (script/js/uzak src yok)', !str_contains($h1['body_html_safe'], 'script') && !str_contains($h1['body_html_safe'], 'javascript') && !str_contains($h1['body_html_safe'], ' src=') && str_contains($h1['body_html_safe'], 'Merhaba'));
ok('yalnız HTML maili için düz metin türetildi', str_contains($h1['body_text'], 'Merhaba') && !str_contains($h1['body_text'], 'alert'));
// Ağ ile dev mesaj: yalnız başlık + kısmi gövde
$a11b = hesapEkle('devmesaj@asya.com');
$dev = ['raw' => mail_test_raw(['body' => str_repeat('D', 400000), 'msgid' => '<dev@x>']), 'flags' => [], 'date' => '2026-10-05 10:00:00'];
sunucu($a11b, [1 => $dev]);
senk($a11b, []);
ok('normal boyutlu mesaj tam alınır', (int)$db->query("SELECT body_truncated FROM mail_messages WHERE account_id = $a11b")->fetchColumn() === 0);

echo "\n=== 12. Limitler / bütçe ===\n";
$a12 = hesapEkle('limit@asya.com');
$m12 = []; for ($i = 1; $i <= 7; $i++) $m12[$i] = msg("l$i", "L$i", '2026-10-05 10:0' . $i . ':00');
sunucu($a12, $m12);
$r = senk($a12, ['limit' => 3]);
ok('çalıştırma başına limit: 3 alındı, 4 kaldı', $r['inserted'] === 3 && $r['kalan'] === 4, json_encode($r));
$r = senk($a12, ['limit' => 3]); $r = senk($a12, ['limit' => 3]);
ok('sonraki çalıştırmalarda kalan alındı, toplam 7 tam', sayi('mail_messages', "account_id = $a12") === 7);
$a12b = hesapEkle('sure@asya.com');
sunucu($a12b, $m12);
$r = senk($a12b, ['sure' => -1]);
ok('zaman bütçesi dolmuşsa hiçbir şey kaçmadan ertelenir (veri bozulmaz, kalan raporlanır)', $r['ok'] && $r['inserted'] === 0 && $r['kalan'] > 0, json_encode($r));
$r = senk($a12b);
ok('ertelenen mesajlar sonraki turda alınır', sayi('mail_messages', "account_id = $a12b") === 7);

echo "\n=== 13. Bağlantıyı Test Et ===\n";
sunucu($a1, $srv->mesajlar ?? [], []);
putenv('MAIL_MASTER_KEY=' . base64_encode(str_repeat("\x07", 16) . 'abcdefghijklmnop'));
$t = mail_imap_test($db, $a10, ['istemci' => fabrika()]);
ok('test: başarı mesajı + klasör sayısı', $t['ok'] && str_contains($t['mesaj'], '4 mesaj'), $t['mesaj']);
$aBozuk2 = hesapEkle('bozuk2@asya.com', 'dogru-parola-QQQ');
sunucu($aBozuk2, [], ['pass' => 'BASKA-parola']);
$t = mail_imap_test($db, $aBozuk2, ['istemci' => fabrika()]);
ok('test: yanlış şifre → anlaşılır mesaj, şifre YOK', !$t['ok'] && str_contains($t['mesaj'], 'auth') && !str_contains($t['mesaj'], 'dogru-parola-QQQ') && !str_contains($t['mesaj'], 'BASKA-parola'));
ok('test sunucuda değişiklik yapmıyor', !preg_grep('/STORE|DELETE|APPEND|EXPUNGE/i', $SUNUCULAR[$a10]->received));

echo "\n=== 14. Geri dolum \"Cevap Bekleyen\"i doldurmaz ===\n";
$a14 = hesapEkle('geridolum@asya.com');
$db->exec("UPDATE mail_accounts SET created_at = '2026-10-06 11:00:00' WHERE id = $a14");   // hesap simdi'den 1 sa önce eklendi
$m14 = []; for ($i = 1; $i <= 5; $i++) $m14[$i] = msg("g$i", "G$i", "2026-10-0{$i} 09:00:00");
$srv14 = sunucu($a14, $m14);
$r = senk($a14, ['limit' => 2]);
ok('ilk tarama: geçmiş mailler cevap beklemiyor', $r['inserted'] === 2 && sayi('mail_messages', "account_id = $a14 AND needs_reply = 1") === 0, json_encode($r));
senk($a14, ['limit' => 2]); senk($a14, ['limit' => 2]);
ok('birkaç çalıştırmaya yayılan geri dolum: hesap eklenmeden önceki mailler de cevap beklemiyor', sayi('mail_messages', "account_id = $a14") === 5 && sayi('mail_messages', "account_id = $a14 AND needs_reply = 1") === 0);
$srv14->mesajlar[6] = msg('g6', 'Yeni gelen', '2026-10-06 11:30:00');
$srv14->mesajlar[7] = msg('g7', 'Kendi gönderdiğim', '2026-10-06 11:40:00', [], ['from' => 'Biz <geridolum@asya.com>']);
senk($a14);
ok('hesap eklendikten SONRA gelen mail cevap bekler; kendi mailimiz beklemez', sayi('mail_messages', "account_id = $a14 AND needs_reply = 1") === 1
    && (int)$db->query("SELECT needs_reply FROM mail_messages WHERE account_id = $a14 AND uid = 6")->fetchColumn() === 1);

echo "\n=== 15. Uzun UID SEARCH yanıtı hesabı kilitlemez ===\n";
$a15 = hesapEkle('cokmail@asya.com', 'imap-parola-ABC', ['initial_days' => 365]);
$ham15 = mail_test_raw(['msgid' => null, 'subject' => 'Toplu']);
$m15 = []; for ($i = 1; $i <= 9000; $i++) $m15[1000000 + $i] = ['raw' => $ham15, 'flags' => ['\Seen'], 'date' => '2026-10-01 10:00:00'];
$srv15 = sunucu($a15, $m15); unset($m15);
$c = new MailImapClient($srv15->yeniBaglanti(), ['max_satir_ara' => 65536]); $c->baslat(false); $c->girisYap('cokmail@asya.com', 'imap-parola-ABC'); $c->klasorAc('INBOX');
$e = null; try { $c->uidAra('SINCE 01-Sep-2026'); } catch (MailImapException $ex) { $e = $ex; }
ok('(koşul gerçek) 9000 × 7 haneli UID tek satırı 64 KB\'ı aşar → eski sınırla limit hatası', $e !== null && $e->kind === 'limit');
$r = senk($a15);
ok('SEARCH yanıtı > 64 KB: senkron başarılı, çalıştırma limiti kadar alındı, kalan raporlandı', $r['ok'] && $r['inserted'] === 200 && $r['kalan'] === 8800 && $srv15->enUzunSatir > 65536, json_encode($r) . ' satır=' . $srv15->enUzunSatir);
ok('imleç ilerledi (sonraki çalıştırma kaldığı yerden)', (int)$db->query("SELECT last_uid FROM mail_sync_state WHERE account_id = $a15")->fetchColumn() === 1000200);
$r = senk($a15);
ok('ikinci çalıştırma (UID n:* araması da uzun satır) sorunsuz devam eder', $r['ok'] && $r['inserted'] === 200 && sayi('mail_messages', "account_id = $a15") === 400, json_encode($r));
$c->kapatZorla(); unset($srv15, $SUNUCULAR[$a15]);
$db->exec("UPDATE mail_accounts SET is_active = 0 WHERE id = $a15");

echo "\n=== 16. Bağlantı bayt bütçesi normal yükte HATA değildir ===\n";
$a16 = hesapEkle('butce@asya.com');
$m16 = []; for ($i = 1; $i <= 7; $i++) $m16[$i] = msg("bt$i", "BT$i", '2026-10-05 10:00:00', [], ['body' => str_repeat('b', 1800)]);
sunucu($a16, $m16);
$boy16 = strlen($m16[1]['raw']);
$kucukButce = function (array $h) use ($boy16) {   // 2 mesaj sığar, 3.'sü sığmaz
    global $SUNUCULAR;
    $c = new MailImapClient($SUNUCULAR[(int)$h['id']]->yeniBaglanti(), ['max_toplam' => 65536 + $boy16 * 2 + 100]);
    $c->baslat(false); $c->girisYap($h['imap_user'], $h['imap_pass']); return $c;
};
$r = senk($a16, ['istemci' => $kucukButce]);
ok('bütçe dolunca mesaj ÇEKİLMEDEN durur: başarı, 2 alındı, 5 kalan', $r['ok'] && $r['error'] === null && $r['inserted'] === 2 && $r['kalan'] === 5 && (bool)preg_grep('/veri bütçesi/', $r['notlar']), json_encode($r));
ok('hata sayacı artmadı, günlük ok', (int)$db->query("SELECT consecutive_failures FROM mail_sync_state WHERE account_id = $a16")->fetchColumn() === 0
    && $db->query("SELECT status FROM mail_sync_log WHERE account_id = $a16 ORDER BY id DESC LIMIT 1")->fetchColumn() === 'ok');
senk($a16, ['istemci' => $kucukButce]); senk($a16, ['istemci' => $kucukButce]); senk($a16, ['istemci' => $kucukButce]);
ok('sonraki çalıştırmalar kalanı alır: toplam 7, çift yok', sayi('mail_messages', "account_id = $a16") === 7);

echo "\n=== 17. Çökme koruması (yakalanamayan fatal: bellek/süre sınırı) ===\n";
$a17 = hesapEkle('cokme@asya.com');
$m17 = []; for ($i = 1; $i <= 3; $i++) $m17[$i] = msg("ck$i", "CK$i", '2026-10-05 10:00:00');
$srv17 = sunucu($a17, $m17);
$isYol = mail_sync_isaret_yolu($a17, $KILIT);
$gozlem = [];
$srv17->cfg['fetch_kanca'] = function (int $uid) use (&$gozlem, $a17) { global $KILIT; $gozlem[$uid] = mail_sync_isaret_oku($a17, $KILIT)['aktif']['uid'] ?? null; };
$r = senk($a17);
ok('her mesaj çekilmeden ÖNCE "işleniyor" işareti o UID\'i gösterir', $gozlem === [1 => 1, 2 => 2, 3 => 3], json_encode($gozlem));
ok('normal bitişte işaret dosyası kalmaz', $r['ok'] && !is_file($isYol));
// Yakalanan hata (ağ kopması) mesajın suçu değildir: işaret temizlenir, çökme sayılmaz.
$srv17->mesajlar[4] = msg('ck4', 'CK4', '2026-10-05 11:00:00');
$srv17->cfg['drop_in_fetch'] = true;
$r = senk($a17);
$srv17->cfg['drop_in_fetch'] = false;
ok('ağ kopması (yakalanan hata): işaret dosyası kalmaz', $r['ok'] === false && !is_file($isYol));
$r = senk($a17);
ok('sonraki çalıştırma bunu çökme SAYMAZ', $r['ok'] && !preg_grep('/yarıda kesildi/', $r['notlar']) && sayi('mail_messages', "account_id = $a17") === 4);
// Fatal benzetimi: önceki süreç uid 6'yı işlerken öldü (işaret kaldı, günlük 'running' kaldı).
$srv17->mesajlar[5] = msg('ck5', 'CK5', '2026-10-05 12:00:00');
$srv17->mesajlar[6] = msg('ck6', 'CK6 zehir', '2026-10-05 12:01:00');
$srv17->mesajlar[7] = msg('ck7', 'CK7', '2026-10-05 12:02:00');
mail_sync_isaret_yaz($a17, ['aktif' => ['klasor' => 'INBOX', 'uidv' => 1000, 'uid' => 6], 'supheli' => []], $KILIT);
$db->exec("INSERT INTO mail_sync_log (account_id, started_at, status) VALUES ($a17, '2026-10-06 11:55:00', 'running')");
$yariLog = (int)$db->lastInsertId();
$gozlem = [];
$r = senk($a17);
ok('ilk çökme: yarım günlük satırı error\'a çekildi, not düşüldü', $db->query("SELECT status FROM mail_sync_log WHERE id = $yariLog")->fetchColumn() === 'error' && (bool)preg_grep('/uid 6 işlenirken yarıda kesildi/', $r['notlar']), json_encode($r['notlar']));
ok('ilk çökmeden sonra mail YENİDEN denenir (tek kesinti sağlam maili kaybettirmez)', $r['ok'] && isset($gozlem[6]) && sayi('mail_messages', "account_id = $a17 AND subject = 'CK6 zehir'") === 1 && !is_file($isYol), json_encode($gozlem));
// İkinci kez aynı mailde öldü (şüpheli sayacı 1 + yeni aktif işaret) → çekilmeden yer tutucu.
$srv17->mesajlar[8] = msg('ck8', 'CK8 zehir', '2026-10-05 12:03:00');
$srv17->mesajlar[9] = msg('ck9', 'CK9', '2026-10-05 12:04:00');
mail_sync_isaret_yaz($a17, ['aktif' => ['klasor' => 'INBOX', 'uidv' => 1000, 'uid' => 8], 'supheli' => ['INBOX|1000|8' => 1]], $KILIT);
$gozlem = [];
$r = senk($a17);
$st8 = $db->query("SELECT subject, body_truncated FROM mail_messages WHERE account_id = $a17 AND uid = 8")->fetch();
ok('art arda 2. çökme: zehirli mail ÇEKİLMEDEN yer tutucu yazıldı', $r['ok'] && !isset($gozlem[8]) && str_starts_with((string)$st8['subject'], '(işlenemedi') && (int)$st8['body_truncated'] === 1, json_encode([$r, $gozlem, $st8]));
ok('zehirden sonraki mail alındı, imleç ilerledi, işaret temiz', sayi('mail_messages', "account_id = $a17 AND subject = 'CK9'") === 1
    && (int)$db->query("SELECT last_uid FROM mail_sync_state WHERE account_id = $a17")->fetchColumn() === 9 && !is_file($isYol));
ok('çökme izleri başka hesabı etkilemez (işaret hesap başına)', mail_sync_isaret_yolu($a17, $KILIT) !== mail_sync_isaret_yolu($a16, $KILIT));
echo "\n=== 18. Gönderilenler klasörünü oku (Dalga 3B) ===\n";
function msgS(string $id, string $subj, string $date, array $raw = []): array {
    return msg($id, $subj, $date, ['\Seen'], array_merge(['from' => 'Biz <sent18@asya.com>', 'to' => 'ahmet@musteri.com', 'msgid' => "<$id@asya.com>"], $raw));
}
$ENG = "Dear Sir, please find attached the payment confirmation for your order. We will ship the goods with the next vessel. Best regards";
$a18 = hesapEkle('sent18@asya.com', 'imap-parola-ABC', ['sent_folder' => 'Sent', 'translate_enabled' => 1]);
$db->exec("UPDATE mail_accounts SET created_at = '2026-10-01 00:00:00' WHERE id = $a18");
$kl = fn(array $inbox, array $sent) => ['klasorler' => ['INBOX' => ['mesajlar' => $inbox], 'Sent' => ['mesajlar' => $sent]]];
$srv18 = sunucu($a18, [], $kl([1 => msg('eski18', 'Eski', '2026-10-02 09:00:00', ['\Seen'], ['body' => $ENG])], []));
$r = senk($a18);
ok('ilk senkron (Gönderilenler boş) başarılı', $r['ok'] && sayi('mail_messages', "account_id = $a18") === 1, json_encode($r));
// Yeni gelenler + Outlook/telefondan yazılmış cevaplar (Gönderilenler'de)
$cfg = &$srv18->cfg['klasorler'];
$cfg['INBOX']['mesajlar'] += [
    2 => msg('q1', 'Order 1', '2026-10-05 09:00:00', [], ['body' => $ENG]),
    3 => msg('q2', 'Order 2', '2026-10-05 10:00:00', [], ['body' => $ENG]),
    4 => msg('q3', 'Order 3', '2026-10-05 11:00:00', [], ['body' => $ENG]),
    5 => msg('q4', 'Order 4', '2026-10-05 12:00:00', [], ['body' => $ENG]),
];
$cfg['Sent']['mesajlar'] = [
    1 => msgS('s1', 'Re: Order 1', '2026-10-05 12:00:00', ['body' => $ENG, 'extra' => ['In-Reply-To: <q1@musteri.com>', 'References: <q1@musteri.com>']]),
    2 => msgS('s2', 'Yeni konu', '2026-10-05 12:30:00', ['body' => $ENG]),
    3 => msgS('s3', 'Re: Order 2', '2026-10-05 13:00:00', ['body' => $ENG, 'extra' => ['References: <root@musteri.com> <q2@musteri.com>']]),
    4 => msgS('s4', 'Re: Order 4 (eski tarihli)', '2026-10-05 08:00:00', ['body' => $ENG, 'extra' => ['In-Reply-To: <q4@musteri.com>']]),
];
unset($cfg);
$konuBaglanti = $BAGLANTILAR;
$r = senk($a18);
ok('ikinci senkron: gelen kutusu + Gönderilenler okundu (4 yeni gelen, 4 gönderilen)', $r['ok'] && $r['inserted'] === 8 && sayi('mail_messages', "account_id = $a18 AND folder = 'INBOX'") === 5 && sayi('mail_messages', "account_id = $a18 AND folder = 'Sent'") === 4, json_encode($r));
ok('Gönderilenler için AYRI bağlantı (kendi bayt bütçesi): bu turda toplam 2 bağlantı', $BAGLANTILAR - $konuBaglanti === 2);
$s18 = $db->query("SELECT * FROM mail_messages WHERE account_id = $a18 AND folder = 'Sent' ORDER BY uid")->fetchAll();
ok('gönderilen satırlar: okundu, cevap beklemez, çevrilmez (translate_enabled=1 olsa bile)', count(array_filter($s18, fn($x) => (int)$x['is_read'] === 1 && (int)$x['needs_reply'] === 0 && $x['tr_status'] === 'skipped')) === 4, json_encode(array_map(fn($x) => [$x['is_read'], $x['needs_reply'], $x['tr_status']], $s18)));
$gelen18 = $db->query("SELECT message_id, needs_reply, replied_at, tr_status FROM mail_messages WHERE account_id = $a18 AND folder = 'INBOX' ORDER BY uid")->fetchAll();
$g = array_column($gelen18, null, 'message_id');
ok('gelen kutusu mailleri çeviri kuyruğuna girdi (pending), gönderilenler girmedi', $g['q1@musteri.com']['tr_status'] === 'pending' && sayi('mail_messages', "account_id = $a18 AND folder = 'Sent' AND tr_status = 'pending'") === 0);
ok('In-Reply-To ile cevaplanan ebeveyn: replied_at dolu, needs_reply=0', $g['q1@musteri.com']['replied_at'] !== null && (int)$g['q1@musteri.com']['needs_reply'] === 0, json_encode($g['q1@musteri.com']));
ok('yalnız References ile cevaplanan (son öğe = ebeveyn) da temizlenir', $g['q2@musteri.com']['replied_at'] !== null && (int)$g['q2@musteri.com']['needs_reply'] === 0);
ok('cevaplanmayan mail hâlâ cevap bekliyor', (int)$g['q3@musteri.com']['needs_reply'] === 1 && $g['q3@musteri.com']['replied_at'] === null);
ok('ebeveyninden ÖNCE tarihli "cevap" ebeveyni temizlemez (zaman tutarlılığı)', (int)$g['q4@musteri.com']['needs_reply'] === 1 && $g['q4@musteri.com']['replied_at'] === null);
$t1 = (int)$db->query("SELECT thread_id FROM mail_messages WHERE account_id = $a18 AND message_id = 'q1@musteri.com'")->fetchColumn();
ok('gönderilen cevap ebeveyniyle AYNI thread (başlıklarla bağlı)', $t1 > 0 && (int)$db->query("SELECT thread_id FROM mail_messages WHERE account_id = $a18 AND message_id = 's1@asya.com'")->fetchColumn() === $t1);
$kimlik18 = [$a18];
$gelenListe = mail_mesaj_listele($db, $kimlik18, 'gelen', '', 1);
ok('"Gelen" listesi Gönderilenler satırlarını İÇERMEZ', $gelenListe['toplam'] === 5 && !array_filter($gelenListe['satirlar'], fn($x) => str_ends_with((string)$x['subject'], 'Yeni konu') || str_starts_with((string)$x['subject'], 'Re: Order 1')), json_encode($gelenListe['toplam']));
$say18 = mail_filtre_sayilari($db, $kimlik18);
ok('sayaçlar: gelen=5, okunmamış=4 (q1..q4), cevap bekleyen=2 (q3,q4)', $say18['gelen'] === 5 && $say18['okunmamis'] === 4 && $say18['cevap'] === 2, json_encode($say18));
ok('okunmamış/cevap listeleri de gönderilenleri içermez', mail_mesaj_listele($db, $kimlik18, 'okunmamis', '', 1)['toplam'] === 4 && mail_mesaj_listele($db, $kimlik18, 'cevap', '', 1)['toplam'] === 2);
// Gönderilenler satırı elle "okunmadı" yapılsa bile gelen sayaçlarına girmez
$sid = (int)$db->query("SELECT id FROM mail_messages WHERE account_id = $a18 AND message_id = 's2@asya.com'")->fetchColumn();
$db->exec("UPDATE mail_messages SET is_read = 0, needs_reply = 1 WHERE id = $sid");
$say18b = mail_filtre_sayilari($db, $kimlik18);
ok('(savunma) elle okunmadı/cevap bekliyor yapılan gönderilen satır gelen sayaçlarına girmez', $say18b['okunmamis'] === 4 && $say18b['cevap'] === 2 && $say18b['gelen'] === 5);
ok('(savunma) ana sayfa okunmamış rozet sorgusu da dışlar', (mail_okunmamis_sayilari(1, $db, true)[$a18] ?? -1) === 4, json_encode(mail_okunmamis_sayilari(1, $db, true)));
$db->exec("UPDATE mail_messages SET is_read = 1, needs_reply = 0 WHERE id = $sid");
$gorunen = mail_thread_mesajlari($db, mail_mesaj_getir($db, (int)$db->query("SELECT id FROM mail_messages WHERE account_id = $a18 AND message_id = 'q1@musteri.com'")->fetchColumn(), $kimlik18));
ok('thread görünümünde ("Konuşmadaki diğer mailler") gönderilen cevap görünür', count(array_filter($gorunen, fn($x) => str_starts_with((string)$x['subject'], 'Re: Order 1'))) === 1);
$durumlar = $db->query("SELECT folder, last_uid, consecutive_failures FROM mail_sync_state WHERE account_id = $a18 ORDER BY folder")->fetchAll();
ok('klasör başına AYRI durum satırı: INBOX imleci 5, Sent imleci 4', count($durumlar) === 2 && (int)$durumlar[0]['last_uid'] === 5 && $durumlar[0]['folder'] === 'INBOX' && (int)$durumlar[1]['last_uid'] === 4 && $durumlar[1]['folder'] === 'Sent', json_encode($durumlar));
ok('senkron durumu (mail_senkron_durumlari) yalnız gelen kutusu satırını verir', count(mail_senkron_durumlari($db, $kimlik18)) === 1);
ok('SALT OKUNUR: sunucuya STORE/APPEND/SELECT/EXPUNGE/DELETE gitmedi', !preg_grep('/\bSTORE\b|\bAPPEND\b|\bSELECT\b|EXPUNGE|\bDELETE\b|\+FLAGS/i', $srv18->received));
ok('Gönderilenler EXAMINE ile açıldı', (bool)preg_grep('/EXAMINE "Sent"/', $srv18->received));
// Tekrar senkron: çift yok, hiçbir şey ezilmez
$once = sayi('mail_messages', "account_id = $a18");
$ra = $db->query("SELECT replied_at FROM mail_messages WHERE message_id = 'q1@musteri.com' AND account_id = $a18")->fetchColumn();
$r = senk($a18);
ok('tekrar senkron: yeni mail yok, çift yok, replied_at değişmedi', $r['ok'] && $r['inserted'] === 0 && sayi('mail_messages', "account_id = $a18") === $once && $db->query("SELECT replied_at FROM mail_messages WHERE message_id = 'q1@musteri.com' AND account_id = $a18")->fetchColumn() === $ra);
// Sonradan Gönderilenler'e düşen cevap q3'ü temizler
$srv18->cfg['klasorler']['Sent']['mesajlar'][5] = msgS('s5', 'Re: Order 3', '2026-10-05 15:00:00', ['body' => $ENG, 'extra' => ['In-Reply-To: <q3@musteri.com>']]);
$r = senk($a18);
ok('sonradan yazılan cevap (Outlook) q3\'ü "Cevap Bekleyen"den çıkarır', $r['ok'] && (int)$db->query("SELECT needs_reply FROM mail_messages WHERE account_id = $a18 AND message_id = 'q3@musteri.com'")->fetchColumn() === 0 && mail_filtre_sayilari($db, $kimlik18)['cevap'] === 1);
// append_sent ile kendi cevabımızın yeniden okunması: outbox zaten replied_at yazmış → ezilmez, yanlış işaretleme olmaz
$db->exec("UPDATE mail_messages SET replied_at = '2026-10-05 14:10:00', needs_reply = 0 WHERE account_id = $a18 AND message_id = 'q4@musteri.com'");
$srv18->cfg['klasorler']['Sent']['mesajlar'][6] = msgS('s6', 'Re: Order 4', '2026-10-05 14:20:00', ['body' => $ENG, 'extra' => ['In-Reply-To: <q4@musteri.com>']]);
senk($a18);
ok('append_sent geri okuması: zaten cevaplanmış ebeveynin replied_at değeri EZİLMEZ', $db->query("SELECT replied_at FROM mail_messages WHERE account_id = $a18 AND message_id = 'q4@musteri.com'")->fetchColumn() === '2026-10-05 14:10:00');

require_once $ROOT . '/config/mail_smtp.php';
require_once $ROOT . '/config/mail_outbox.php';
$sentMsg = mail_mesaj_getir($db, (int)$db->query("SELECT id FROM mail_messages WHERE account_id = $a18 AND message_id = 's1@asya.com'")->fetchColumn(), $kimlik18);
$gelenMsg = mail_mesaj_getir($db, (int)$db->query("SELECT id FROM mail_messages WHERE account_id = $a18 AND message_id = 'q3@musteri.com'")->fetchColumn(), $kimlik18);
ok('Gönderilenler satırı "kendi mailiniz" diye tanınır; gelen satırı tanınmaz', mail_mesaj_gonderilen_mi($db, $sentMsg) && !mail_mesaj_gonderilen_mi($db, $gelenMsg));
ok('Gönderilenler satırına cevap taslağı AÇILAMAZ; gelen mailde açılır', !mail_outbox_taslak($db, (int)$sentMsg['id'], $kimlik18, 1, bin2hex(random_bytes(16)), ['body_tr' => 'x', 'target_lang' => 'en'])['ok']
    && mail_outbox_taslak($db, (int)$gelenMsg['id'], $kimlik18, 1, bin2hex(random_bytes(16)), ['body_tr' => 'x', 'target_lang' => 'en'])['ok']);
ok('mail_cevap_engeli: gönderilen satırda Türkçe neden, gelen satırda boş', str_contains(mail_cevap_engeli($db, $sentMsg), 'Gönderilenler klasöründeki kendi mailinizdir') && mail_cevap_engeli($db, $gelenMsg) === '');

echo "\n=== 19. Gönderilenler: hata yalıtımı, kalan, aynı klasör ===\n";
$a19 = hesapEkle('sent19@asya.com', 'imap-parola-ABC', ['sent_folder' => 'Sent']);
$db->exec("UPDATE mail_accounts SET created_at = '2026-10-01 00:00:00' WHERE id = $a19");
$srv19 = sunucu($a19, [], ['klasorler' => ['INBOX' => ['mesajlar' => [1 => msg('x1', 'X1', '2026-10-05 09:00:00')]]]]);   // Sent klasörü sunucuda YOK
$r = senk($a19);
$st19 = $db->query("SELECT folder, last_error, consecutive_failures FROM mail_sync_state WHERE account_id = $a19 ORDER BY folder")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
ok('Gönderilenler klasörü yoksa gelen kutusu YİNE başarılı, not düşer', $r['ok'] && $r['inserted'] === 1 && (bool)preg_grep('/Gönderilenler klasörü okunamadı/', $r['notlar']), json_encode($r));
ok('hata yalnız Sent durum satırına yazıldı; gelen kutusu satırı temiz', (int)$st19['Sent']['consecutive_failures'] === 1 && $st19['Sent']['last_error'] !== null && (int)$st19['INBOX']['consecutive_failures'] === 0 && $st19['INBOX']['last_error'] === null, json_encode($st19));
$gd = mail_sync_gunluk_getir($db, 5)['durum'];
$gd19 = array_values(array_filter($gd, fn($x) => (int)$x['id'] === $a19))[0];
ok('yönetici ekranı verisi: Gönderilenler hatası ayrı alanda, gelen kutusu hatasız', $gd19['sent_error'] !== null && $gd19['last_error'] === null && (int)$gd19['consecutive_failures'] === 0);
senk($a19); senk($a19);
ok('3 hatadan sonra Gönderilenler geri çekilir, gelen kutusu etkilenmez', (int)$db->query("SELECT consecutive_failures FROM mail_sync_state WHERE account_id = $a19 AND folder = 'Sent'")->fetchColumn() === 3);
$srv19->cfg['klasorler']['INBOX']['mesajlar'][2] = msg('x2', 'X2', '2026-10-05 10:00:00');
$r = mail_sync_hesap($db, $a19, ['istemci' => fabrika(), 'kilit_dizin' => $KILIT, 'simdi' => strtotime('2026-10-06 12:00:30')]);   // zorla YOK
ok('geri çekilmedeki Gönderilenler atlanır (not), gelen kutusu yine alınır', $r['ok'] && !empty($r['bekle']) === false && sayi('mail_messages', "account_id = $a19 AND uid = 2") === 1 && (bool)preg_grep('/Gönderilenler klasörü art arda 3 hata/', $r['notlar']), json_encode($r));
// Gönderilenler sonradan oluşur → iyileşir
$srv19->cfg['klasorler']['Sent'] = ['mesajlar' => [1 => msgS('y1', 'Re: X1', '2026-10-05 11:00:00', ['extra' => ['In-Reply-To: <x1@musteri.com>']])]];
$r = senk($a19);
ok('klasör düzelince Gönderilenler okunur, hata sayacı sıfırlanır', $r['ok'] && sayi('mail_messages', "account_id = $a19 AND folder = 'Sent'") === 1 && (int)$db->query("SELECT consecutive_failures FROM mail_sync_state WHERE account_id = $a19 AND folder = 'Sent'")->fetchColumn() === 0);
// sent_folder = sync_folder → ikinci geçiş YOK
$a19b = hesapEkle('sent19b@asya.com', 'imap-parola-ABC', ['sent_folder' => 'INBOX']);
sunucu($a19b, [1 => msg('z1', 'Z1', '2026-10-05 09:00:00')]);
$b0 = $BAGLANTILAR; senk($a19b);
ok('sent_folder = gelen kutusu → tek bağlantı, çift okuma yok', $BAGLANTILAR - $b0 === 1 && sayi('mail_messages', "account_id = $a19b") === 1);
$a19c = hesapEkle('sent19c@asya.com');
sunucu($a19c, [1 => msg('w1', 'W1', '2026-10-05 09:00:00')]);
$b0 = $BAGLANTILAR; senk($a19c);
ok('sent_folder boş → tek bağlantı (eski davranış)', $BAGLANTILAR - $b0 === 1);
// Gelen kutusunda bitmemiş iş varsa Gönderilenler beklenir (ebeveyn henüz yokken cevaplandı işlenemez)
$a19d = hesapEkle('sent19d@asya.com', 'imap-parola-ABC', ['sent_folder' => 'Sent']);
$db->exec("UPDATE mail_accounts SET created_at = '2026-10-01 00:00:00' WHERE id = $a19d");
sunucu($a19d, [], ['klasorler' => ['INBOX' => ['mesajlar' => [1 => msg('k1', 'K1', '2026-10-05 09:00:00'), 2 => msg('k2', 'K2', '2026-10-05 10:00:00'), 3 => msg('k3', 'K3', '2026-10-05 11:00:00')]],
    'Sent' => ['mesajlar' => [1 => msgS('k1r', 'Re: K1', '2026-10-05 12:00:00', ['extra' => ['In-Reply-To: <k1@musteri.com>']])]]]]);
$r = senk($a19d, ['limit' => 2]);
ok('gelen kutusunda kalan iş varken Gönderilenler okunmaz (not düşer)', $r['ok'] && $r['kalan'] === 1 && sayi('mail_messages', "account_id = $a19d AND folder = 'Sent'") === 0 && (bool)preg_grep('/Gönderilenler klasörü gelen kutusu tamamlanınca/', $r['notlar']), json_encode($r));
$r = senk($a19d, ['limit' => 2]);
ok('gelen kutusu bitince Gönderilenler okunur', $r['ok'] && sayi('mail_messages', "account_id = $a19d AND folder = 'Sent'") === 1);
// Çökme işareti klasör-bilinçli: Sent'te art arda süreci öldüren mail yer tutucuya düşer
$srv19d = $SUNUCULAR[$a19d];
$srv19d->cfg['klasorler']['Sent']['mesajlar'][2] = msgS('k2zehir', 'zehir', '2026-10-05 12:30:00');
$srv19d->cfg['klasorler']['Sent']['mesajlar'][3] = msgS('k3s', 'Re: K3', '2026-10-05 13:00:00', ['extra' => ['In-Reply-To: <k3@musteri.com>']]);
mail_sync_isaret_yaz($a19d, ['aktif' => ['klasor' => 'Sent', 'uidv' => 1000, 'uid' => 2], 'supheli' => ['Sent|1000|2' => 1]], $KILIT);
$r = senk($a19d);
$stub = $db->query("SELECT subject, folder FROM mail_messages WHERE account_id = $a19d AND uid = 2 AND folder = 'Sent'")->fetch();
ok('çökme işareti Gönderilenler\'de de çalışır: yer tutucu, sonraki mail alındı', $stub && str_starts_with((string)$stub['subject'], '(işlenemedi') && sayi('mail_messages', "account_id = $a19d AND folder = 'Sent' AND uid = 3") === 1, json_encode([$r['notlar'], $stub]));
ok('yer tutucu da gelen listesinde görünmez', !array_filter(mail_mesaj_listele($db, [$a19d], 'gelen', '', 1)['satirlar'], fn($x) => str_starts_with((string)$x['subject'], '(işlenemedi')));
ok('çökme sonrası işaret dosyası temiz', !is_file(mail_sync_isaret_yolu($a19d, $KILIT)));
$leak = json_encode($db->query('SELECT * FROM mail_sync_state')->fetchAll()) . json_encode($db->query('SELECT * FROM mail_sync_log')->fetchAll());
ok('durum/günlük şifre İÇERMİYOR (Gönderilenler yolu)', !str_contains($leak, 'imap-parola-ABC'));

mail_test_bitir();
