<?php
// =========================================================
// scripts/mail_stream_smoke.php — MailSocketStream: satır/bayt okuma, mutlak süre sınırı, yavaş-damla koruması
// Gerçek (yerel, düz) soket çiftiyle; TLS el sıkışması burada TEST EDİLMEZ (sertifika gerekir).
//   php scripts/mail_stream_smoke.php
// =========================================================
require_once __DIR__ . '/_mail_test_lib.php';
require_once $ROOT . '/config/mail_imap.php';

function cift(float $timeout): array {
    $p = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $rc = new ReflectionClass(MailSocketStream::class);
    $s = $rc->newInstanceWithoutConstructor();
    $ctor = $rc->getConstructor(); $ctor->setAccessible(true); $ctor->invoke($s, $p[0], 'x', $timeout);
    return [$s, $p[1]];
}

[$s, $srv] = cift(2.0);
fwrite($srv, "220 hazir\r\n250-a\r\n250 b\r\n");
ok('satır okunur', $s->readLine(100) === '220 hazir');
ok('arka arda satırlar (tampondan)', $s->readLine(100) === '250-a' && $s->readLine(100) === '250 b');
$t = microtime(true);
ok('veri gelmezse timeout sonunda null döner', $s->readLine(100) === null && microtime(true) - $t < 3.5);

[$s, $srv] = cift(5.0);
fwrite($srv, str_repeat('x', 500));
try { $s->readLine(100); $uzun = false; } catch (MailImapException $e) { $uzun = $e->kind === 'limit'; }
ok('max\'tan uzun satır → limit hatası', $uzun);

[$s, $srv] = cift(5.0);
fwrite($srv, "ab"); 
$s->sureSinirla(microtime(true) + 1.0);
$t = microtime(true);
ok('mutlak süre sınırı: satır tamamlanmazsa 1 sn sonra null (timeout 5 sn olsa da)', $s->readLine(100) === null && microtime(true) - $t < 2.0);

// Yavaş damla: her 0,4 sn'de bir bayt — satır başına süre (timeout) dolunca kesilir
[$s, $srv] = cift(1.0);
$pid = function_exists('pcntl_fork') ? pcntl_fork() : -1;
if ($pid === -1) { ok('yavaş-damla testi atlandı (pcntl yok)', true); }
elseif ($pid === 0) { for ($i = 0; $i < 12; $i++) { fwrite($srv, 'z'); usleep(400000); } exit(0); }
if ($pid !== -1) {
$t = microtime(true);
ok('yavaş-damla: sürekli bayt gelse de satır süresi dolunca bırakır (<2.5 sn)', $s->readLine(100) === null && microtime(true) - $t < 2.5);
posix_kill($pid, SIGKILL); pcntl_waitpid($pid, $st);
}

[$s, $srv] = cift(5.0);
fwrite($srv, "5\r\nhello");
ok('readBytes tam n bayt döner, kalanı tamponda', $s->readLine(50) === '5' && $s->readBytes(3) === 'hel' && $s->readBytes(2) === 'lo');
[$s, $srv] = cift(5.0);
$s->write(str_repeat('A', 200000));   // soket tamponunu aşar: engellemeyen yazma + select ile devam etmeli
$oku = '';
stream_set_blocking($srv, false); $bit = microtime(true) + 3;
while (strlen($oku) < 200000 && microtime(true) < $bit) { $x = fread($srv, 65536); if ($x === '' || $x === false) usleep(1000); else $oku .= $x; }
ok('büyük yazma (soket tamponu dolsa da) eksiksiz iletilir', strlen($oku) === 200000);
// Yazma kilitlenirse (karşı taraf okumuyor) mutlak sınırda hata
[$s, $srv] = cift(1.0);
$s->sureSinirla(microtime(true) + 1.0);
$t = microtime(true);
try { $s->write(str_repeat('B', 50 * 1048576)); $yaz = false; } catch (MailImapException $e) { $yaz = true; }
ok('karşı taraf okumuyorsa yazma mutlak sınırda hata verir (<3 sn)', $yaz && microtime(true) - $t < 3.0);

mail_test_bitir();
