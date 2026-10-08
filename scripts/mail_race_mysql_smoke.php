<?php
// =========================================================
// scripts/mail_race_mysql_smoke.php — GERÇEK MySQL/MariaDB'de çok-süreçli yarış testi (SQLite'ta ATLANIR)
//   MAIL_TEST_MYSQL_DSN='mysql:host=127.0.0.1;dbname=mailtest;charset=utf8mb4' MAIL_TEST_MYSQL_USER=… MAIL_TEST_MYSQL_PASS=… \
//     php scripts/mail_race_mysql_smoke.php
// Kanıtlanan: aynı onaylı satır için N süreç AYNI ANDA gonder() çağırsa SMTP'ye TEK mesaj gider; aynı içerikli ikiz satırların
// onayı yarışında yalnız biri kazanır; M3 mesaj ekleme yarışında UNIQUE tek satır bırakır. Gerçek fork + ayrı bağlantı.
// =========================================================
require_once __DIR__ . '/_mail_test_lib.php';
if (!mail_test_mysql() || !function_exists('pcntl_fork')) { echo "ATLANDI (MySQL kipi ya da pcntl yok)\n"; exit(0); }
require_once $ROOT . '/config/mail_imap.php';
require_once $ROOT . '/config/mail_mime.php';
require_once $ROOT . '/config/mail_sync.php';
require_once $ROOT . '/config/mail_view.php';
require_once $ROOT . '/config/mail_translate.php';
require_once $ROOT . '/config/mail_smtp.php';
require_once $ROOT . '/config/mail_outbox.php';
require_once __DIR__ . '/_mail_fake_smtp.php';

$db = db(); mail_test_diger_tablolar($db); mail_test_sema_kur($db); mail_test_anahtar_kur();
$A = mail_hesap_kaydet(['label' => 'r', 'email' => 'race@asya.com', 'imap_host' => 'i.t.com', 'imap_user' => 'race@asya.com', 'imap_pass' => 'x-IMAP-1', 'smtp_host' => 's.t.com', 'smtp_user' => 'race@asya.com', 'smtp_pass' => 'x-SMTP-2', 'display_name' => 'R'], null, 1, $db)['id'];
$H = [$A]; $GONDERIM = sys_get_temp_dir() . '/mail_race_' . getmypid() . '.log'; @unlink($GONDERIM);
$ekle = function (string $mid) use ($db, $A) {
    static $u = 0; $u++;
    $db->prepare("INSERT INTO mail_messages (account_id, folder, uidvalidity, uid, message_id, message_id_hash, subject, from_addr, received_at, body_text, to_addrs, cc_addrs) VALUES (?, 'INBOX', 1, ?, ?, ?, 'Re: x', 'ivan@musteri.ru', '2026-10-05 10:00:00', 'hi', '[]', '[]')")->execute([$A, $u, $mid, mail_mime_id_hash($mid)]);
    return (int)$db->lastInsertId();
};
final class S implements MailTranslationProviderInterface { public function ad(): string { return 's'; } public function parcaLimiti(): int { return 4000; } public function cevir(string $m, ?string $k, string $h): array { return ['metin' => 'EN: ' . $m, 'tespit' => 'tr']; } }
function hazir(int $msg): int { global $db, $H; $t = mail_outbox_taslak($db, $msg, $H, 5, bin2hex(random_bytes(16)), ['body_tr' => 'Yarış testi cevabı', 'target_lang' => 'en']); mail_outbox_onizle($db, $t['id'], $H, 5, new S(), ['target_lang' => 'en']); return $t['id']; }
/** N süreç çatalla; her biri KENDİ PDO bağlantısıyla $is'i çalıştırır; hepsi başlangıç sinyalini bekler. */
function yaris(int $n, callable $is): array {
    global $PDO_TEST, $MAIL_TEST_MYSQL;
    $ana = getmypid();   // sonuç dosyaları ebeveyn pid'iyle adlanır (çocukta getmypid() farklı)
    $baslat = sys_get_temp_dir() . '/mail_race_go_' . $ana; @unlink($baslat);
    $pids = [];
    for ($i = 0; $i < $n; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $c = new PDO($MAIL_TEST_MYSQL, (string)getenv('MAIL_TEST_MYSQL_USER'), (string)getenv('MAIL_TEST_MYSQL_PASS'));
            $c->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $c->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); $c->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $GLOBALS['PDO_TEST'] = $c;
            while (!file_exists($baslat)) usleep(200);
            try { $r = $is($c, $i); } catch (Throwable $e) { $r = ['ok' => false, 'mesaj' => 'ISTISNA ' . get_class($e) . ': ' . $e->getMessage()]; }
            file_put_contents(sys_get_temp_dir() . '/mail_race_res_' . $ana . '_' . $i, json_encode($r)); posix_kill(getmypid(), SIGKILL);   // PDO yıkıcısı ortak soketi kapatıp ebeveyn bağlantısını bozmasın
        }
        $pids[$i] = $pid;
    }
    usleep(300000); touch($baslat);
    $out = [];
    foreach ($pids as $i => $pid) { pcntl_waitpid($pid, $st); $f = sys_get_temp_dir() . '/mail_race_res_' . $ana . '_' . $i; $out[] = json_decode((string)@file_get_contents($f), true) ?: ['ok' => false, 'mesaj' => 'sonuç yok']; @unlink($f); }
    @unlink($baslat);
    return $out;
}
$smtpFab = function (array $h) use ($GONDERIM) {
    $s = new FakeSmtpStream(['user' => 'race@asya.com', 'pass' => 'x-SMTP-2']);
    $c = new MailSmtpClient($s); $c->baslat('asya.com', false); $c->girisYap($h['smtp_user'], $h['smtp_pass']);
    // Sahte sunucuya kabul ettirmeden önce GERÇEK gönderim sayacını artır: gonder() çağrısı = müşteriye giden mesaj
    return new class($c, $GONDERIM) { public function __construct(private MailSmtpClient $c, private string $f) {} public function gonder(...$a) { file_put_contents($this->f, "1\n", FILE_APPEND | LOCK_EX); return $this->c->gonder(...$a); } public function cikis() { $this->c->cikis(); } public function kapatZorla() { $this->c->kapatZorla(); } };
};
$say = fn() => is_file($GONDERIM) ? count(array_filter(explode("\n", (string)file_get_contents($GONDERIM)))) : 0;

echo "=== A. Aynı onaylı satır, 8 süreç AYNI ANDA gonder() ===\n";
$m = $ekle('race1@musteri.ru'); $id = hazir($m);
// onayı SMTP'siz ver (yalnız onay yazımı): gönderim yarışı ayrı test edilecek
$h = (string)$db->query("SELECT content_hash FROM mail_outbox WHERE id = $id")->fetchColumn();
$db->prepare("UPDATE mail_outbox SET status='approved', approved_by=8, approved_at=?, out_message_id=?, approved_hash=content_hash, dedupe_key=? WHERE id=?")->execute([date('Y-m-d H:i:s'), '<race-1@asya.com>', substr(sha1('race1'), 0, 40), $id]);
$res = yaris(8, fn(PDO $c, int $i) => mail_outbox_gonder($c, $id, ['smtp' => $smtpFab]));
$basari = count(array_filter($res, fn($r) => !empty($r['ok'])));
ok('SMTP\'ye TEK mesaj gitti (8 eşzamanlı süreç)', $say() === 1, 'gönderim=' . $say() . ' ' . json_encode(array_column($res, 'mesaj')));
ok('tam 1 süreç başarılı, 7\'si reddedildi', $basari === 1, json_encode($res));
$o = $db->query("SELECT status, attempts FROM mail_outbox WHERE id = $id")->fetch();
ok('durum sent, deneme sayısı 1', $o['status'] === 'sent' && (int)$o['attempts'] === 1, json_encode($o));

echo "\n=== B. İkiz satırlar (aynı içerik), 6 süreç AYNI ANDA onayla ===\n";
@unlink($GONDERIM);
$m = $ekle('race2@musteri.ru'); $ikizler = [];
for ($i = 0; $i < 6; $i++) $ikizler[] = hazir($m);
$res = yaris(6, fn(PDO $c, int $i) => mail_outbox_onayla($c, $ikizler[$i], $GLOBALS['H'], 8, true, (string)$c->query("SELECT content_hash FROM mail_outbox WHERE id = {$ikizler[$i]}")->fetchColumn(), ['smtp' => $GLOBALS['smtpFab']]));
ok('6 ikiz satırdan yalnız BİRİ gönderildi (müşteriye 1 mesaj)', $say() === 1, 'gönderim=' . $say() . ' ' . json_encode(array_column($res, 'mesaj')));
$sent = (int)$db->query("SELECT COUNT(*) FROM mail_outbox WHERE id IN (" . implode(',', $ikizler) . ") AND status = 'sent'")->fetchColumn();
$kalan = (int)$db->query("SELECT COUNT(*) FROM mail_outbox WHERE id IN (" . implode(',', $ikizler) . ") AND status = 'translated'")->fetchColumn();
ok('1 sent + 5 translated (onaylanamadı)', $sent === 1 && $kalan === 5, "sent=$sent translated=$kalan");

echo "\n=== C. Aynı UID'li mesaj ekleme yarışı (senkron çakışması) ===\n";
$res = yaris(8, function (PDO $c, int $i) use ($A) {
    $h = mail_hesap_cred_oku($A, $c);
    $m = mail_mime_mesaj("From: a@x.com\r\nSubject: yaris\r\nMessage-ID: <yaris@x.com>\r\nDate: Mon, 05 Oct 2026 10:00:00 +0000\r\n\r\ngovde");
    return ['ok' => true, 'mesaj' => mail_mesaj_kaydet($c, $h, 'INBOX', 777, 55, $m, ['size' => 100, 'internaldate' => strtotime('2026-10-05 10:00:00'), 'flags' => []], false, date('Y-m-d H:i:s'))];
});
$n = (int)$db->query("SELECT COUNT(*) FROM mail_messages WHERE account_id = $A AND uidvalidity = 777 AND uid = 55")->fetchColumn();
ok('8 eşzamanlı ekleme → DB\'de TEK satır (UNIQUE), istisna sızmadı', $n === 1 && !array_filter($res, fn($r) => str_starts_with((string)$r['mesaj'], 'ISTISNA')), json_encode(array_column($res, 'mesaj')));
echo "\n=== D. Gerçek mail_migrate() (MySQL DDL, COMPACT varsayılanlı sunucuda bile) ===\n";
$db->exec('SET FOREIGN_KEY_CHECKS=0'); foreach (array_keys(mail_tablolar()) as $t) $db->exec("DROP TABLE IF EXISTS `$t`"); $db->exec('SET FOREIGN_KEY_CHECKS=1');
// mail_tablo_var() istek içinde "var" sonucunu önbelleğe alır (nesne kimliğine göre) → temiz bir bağlantı kullan
$db = new PDO($MAIL_TEST_MYSQL, (string)getenv('MAIL_TEST_MYSQL_USER'), (string)getenv('MAIL_TEST_MYSQL_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
ok('migrate öncesi şema hazır değil', mail_sema_hazir($db) === false);
$mr = mail_migrate($db);
ok('migrate: 7 tablo hatasız kuruldu', mail_sema_hazir($db) === true && !array_filter($mr, fn($r) => $r['durum'] !== 'olusturuldu'), json_encode($mr));
mail_migrate($db);
ok('ikinci çalıştırma idempotent', mail_sema_hazir($db) === true);
$rf = $db->query("SELECT TABLE_NAME, ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'mail\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
ok('tüm tablolar Dynamic satır biçiminde', count($rf) === 7 && !array_filter($rf, fn($v) => $v !== 'Dynamic'), json_encode($rf));
@unlink($GONDERIM);
mail_test_bitir();
