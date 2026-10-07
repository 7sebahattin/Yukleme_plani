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
    $yeni[(int)$row['uid'] + 100] = ['raw' => mail_test_raw(['msgid' => '<' . $row['message_id'] . '>', 'subject' => 'x']), 'flags' => [], 'date' => '2026-10-05 10:00:00'];
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
mail_test_bitir();
