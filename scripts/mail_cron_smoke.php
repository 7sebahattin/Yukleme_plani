<?php
// =========================================================
// scripts/mail_cron_smoke.php — Mail cron çalıştırıcısı: CLI-only, global kilit, hata yalıtımı, günlük bakımı.
//   php scripts/mail_cron_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
require_once __DIR__ . '/_mail_test_lib.php';
require_once $ROOT . '/config/mail_imap.php';
require_once $ROOT . '/config/mail_mime.php';
require_once $ROOT . '/config/mail_sync.php';
require_once __DIR__ . '/_mail_fake_imap.php';

$db = db(); mail_test_diger_tablolar($db); mail_test_sema_kur($db);
$KILIT = sys_get_temp_dir() . '/mail_cron_kilit_' . getmypid(); @mkdir($KILIT, 0700, true);

echo "=== Kaynak (statik) ===\n";
$src = (string)file_get_contents($ROOT . '/scripts/mail_sync_cron.php');
ok('CLI-only guard (PHP_SAPI !== cli → 403 + exit)', (bool)preg_match("/if \(PHP_SAPI !== 'cli'\) \{\s*http_response_code\(403\);\s*exit\(/", $src));
ok('guard require\'lardan ÖNCE', strpos($src, "PHP_SAPI !== 'cli'") < strpos($src, 'require_once'));
ok('yarıda kesilmeyi başarı sanmıyor (shutdown → exit 1)', str_contains($src, 'register_shutdown_function') && str_contains($src, 'exit(1)'));
ok('çıkış kodu mail_cron_calistir() sonucundan', str_contains($src, "exit(\$r['kod']);"));
$htaccess = (string)file_get_contents($ROOT . '/scripts/.htaccess');
ok('scripts/ web\'e kapalı (.htaccess)', str_contains($htaccess, 'Deny from all') || str_contains($htaccess, 'Require all denied'));
$sync = (string)file_get_contents($ROOT . '/config/mail_sync.php');
ok('global kilit LOCK_EX|LOCK_NB', str_contains($sync, 'LOCK_EX | LOCK_NB') && str_contains($sync, "mail_kilit_al('sync_all'"));
ok('cron çıktısı hata metnini yalnız mail_hata_metni() (redakte) üzerinden alıyor', !str_contains($sync, "getMessage() . \"\\n\"") );

echo "\n=== Davranış ===\n";
$opt = ['kilit_dizin' => $KILIT, 'simdi' => strtotime('2026-10-06 12:00:00')];
$r = mail_cron_calistir($db, $opt);
ok('anahtar yok → FAIL + kod 1', $r['kod'] === 1 && str_contains($r['satirlar'][0], 'MAIL_MASTER_KEY'));
mail_test_anahtar_kur();
$r = mail_cron_calistir($db, $opt);
ok('aktif hesap yok → OK, kod 0', $r['kod'] === 0 && $r['satirlar'] === ['OK aktif hesap yok']);

function hesap(string $e, string $pw = 'p-ASYA-1'): int {
    global $db;
    $r = mail_hesap_kaydet(['label' => $e, 'email' => $e, 'imap_host' => 'i.test.com', 'imap_user' => $e, 'imap_pass' => $pw, 'smtp_host' => 's.test.com', 'smtp_user' => $e, 'smtp_pass' => 'smtp-ASYA-2'], null, 1, $db);
    return $r['id'];
}
$SRV = [];
$fab = function (array $h) use (&$SRV) {
    $s = $SRV[(int)$h['id']] ?? null;
    if (!$s) throw new MailImapException('connect', 'Sunucuya bağlanılamadı: connection refused');
    $c = new MailImapClient($s->yeniBaglanti()); $c->baslat(false); $c->girisYap($h['imap_user'], $h['imap_pass']); return $c;
};
$a = hesap('a@x.com'); $b = hesap('b@x.com'); $c = hesap('c@x.com');
foreach ([$a, $c] as $id) {
    $e = $db->query("SELECT email FROM mail_accounts WHERE id = $id")->fetchColumn();
    $SRV[$id] = new FakeMailStream(['user' => $e, 'pass' => 'p-ASYA-1', 'mesajlar' => [1 => ['raw' => mail_test_raw(['msgid' => "<m$id@x>"]), 'flags' => [], 'date' => '2026-10-05 10:00:00']]]);
}
// $b için sunucu yok → bağlantı hatası
$r = mail_cron_calistir($db, $opt + ['istemci' => $fab]);
ok('bir hesap patlasa da diğerleri çalıştı (OK satırları)', count(preg_grep('/^OK hesap=/', $r['satirlar'])) === 2 && count(preg_grep('/^FAIL hesap=' . $b . ' /', $r['satirlar'])) === 1, json_encode($r));
ok('kısmi hata → kod 0 (cron kırmızıya boyanmaz, ayrıntı satırlarda)', $r['kod'] === 0);
ok('iki sağlam hesabın mesajı alındı', (int)$db->query('SELECT COUNT(*) FROM mail_messages')->fetchColumn() === 2);
$tum = implode("\n", $r['satirlar']);
ok('cron çıktısında şifre YOK', !str_contains($tum, 'p-ASYA-1') && !str_contains($tum, 'smtp-ASYA-2'));

$tutan = mail_kilit_al('sync_all', $KILIT);
$once = (int)$db->query('SELECT COUNT(*) FROM mail_sync_log')->fetchColumn();
$r = mail_cron_calistir($db, $opt + ['istemci' => $fab]);
ok('global kilit tutuluyken ikinci cron BUSY, hiçbir şeye dokunmuyor', $r['kod'] === 0 && str_starts_with($r['satirlar'][0], 'BUSY') && (int)$db->query('SELECT COUNT(*) FROM mail_sync_log')->fetchColumn() === $once);
mail_kilit_birak($tutan);

$SRV = [];   // tüm sunucular yok
$r = mail_cron_calistir($db, $opt + ['istemci' => $fab]);
ok('HİÇBİR hesap başarılı değilse kod 1', $r['kod'] === 1 && count(preg_grep('/^FAIL/', $r['satirlar'])) === 3);

echo "\n=== Günlük bakımı ===\n";
$db->exec("INSERT INTO mail_sync_log (account_id, started_at, status) VALUES ($a, '2026-01-01 00:00:00', 'ok')");
$mesajSayisi = (int)$db->query('SELECT COUNT(*) FROM mail_messages')->fetchColumn();
mail_cron_calistir($db, $opt + ['istemci' => $fab, 'log_gun' => 30]);
$eski = (int)$db->query("SELECT COUNT(*) FROM mail_sync_log WHERE started_at < '2026-09-01'")->fetchColumn();
ok('30 günden eski senkron günlüğü silindi', $eski === 0);
ok('bakım posta verisine DOKUNMADI', (int)$db->query('SELECT COUNT(*) FROM mail_messages')->fetchColumn() === $mesajSayisi);

echo "\n=== Depolama ===\n";
$d = sys_get_temp_dir() . '/mail_depo_' . getmypid();
define('MAIL_STORAGE_DIR', $d);
ok('depo dizini + .htaccess otomatik', mail_depo_dizini() === $d && is_file($d . '/.htaccess') && str_contains((string)file_get_contents($d . '/.htaccess'), 'Require all denied'));
ok('kilit adı beyaz liste (yol gezintisi yok)', mail_kilit_al('../x', $KILIT) === null && mail_kilit_al('a b', $KILIT) === null);
mail_test_bitir();
