<?php
// =========================================================
// scripts/mail_hardening_smoke.php — M6 sertleştirme: art arda hata geri çekilmesi, IMAP toplam süre sınırı,
// master-key rotasyonu, işletme uyarıları (dosya izinleri / cron canlılığı), senkron günlüğü ekranı verisi.
//   php scripts/mail_hardening_smoke.php
// =========================================================
require_once __DIR__ . '/_mail_test_lib.php';
require_once $ROOT . '/config/mail_imap.php';
require_once $ROOT . '/config/mail_mime.php';
require_once $ROOT . '/config/mail_sync.php';
require_once $ROOT . '/config/mail_view.php';
require_once $ROOT . '/config/mail_translate.php';
require_once __DIR__ . '/_mail_fake_imap.php';

$db = db(); mail_test_diger_tablolar($db); mail_test_sema_kur($db); mail_test_anahtar_kur();
$KILIT = sys_get_temp_dir() . '/mail_hard_kilit_' . getmypid(); @mkdir($KILIT, 0700, true);
function hesap(string $e, string $pw = 'IMAP-PAROLA-1'): int {
    global $db;
    return mail_hesap_kaydet(['label' => $e, 'email' => $e, 'imap_host' => 'i.t.com', 'imap_user' => $e, 'imap_pass' => $pw, 'smtp_host' => 's.t.com', 'smtp_user' => $e, 'smtp_pass' => 'SMTP-PAROLA-2'], null, 1, $db)['id'];
}

echo "=== 1. Art arda hata geri çekilmesi ===\n";
ok('bekleme: 0,1,2 hata → 0 sn', mail_sync_bekleme_sn(0) === 0 && mail_sync_bekleme_sn(1) === 0 && mail_sync_bekleme_sn(2) === 0);
ok('bekleme: 3→5 dk, 4→10 dk, 5→20 dk', mail_sync_bekleme_sn(3) === 300 && mail_sync_bekleme_sn(4) === 600 && mail_sync_bekleme_sn(5) === 1200);
ok('bekleme üst sınırı 6 saat (taşma yok)', mail_sync_bekleme_sn(40) === 6 * 3600 && mail_sync_bekleme_sn(10) <= 6 * 3600 && mail_sync_bekleme_sn(PHP_INT_MAX) === 6 * 3600);
$A = hesap('a@asya.com');
$T0 = strtotime('2026-10-06 12:00:00'); $cagri = 0;
$kotu = function (array $h) use (&$cagri) { $cagri++; throw new MailImapException('auth', 'Kimlik doğrulama başarısız (yanlış parola?)'); };
$sen = fn(int $t, array $ek = []) => mail_sync_hesap($db, $A, array_merge(['istemci' => $kotu, 'kilit_dizin' => $KILIT, 'simdi' => $T0 + $t], $ek));
for ($i = 0; $i < 3; $i++) $sen($i * 300);
ok('3 ardışık hata sayıldı', (int)$db->query("SELECT consecutive_failures FROM mail_sync_state WHERE account_id = $A")->fetchColumn() === 3 && $cagri === 3);
$r = $sen(600 + 100);
ok('4. çalıştırma erken: geri çekilme → IMAP\'a bağlanılmadı, hata sayacı artmadı', $r['bekle'] === true && $r['ok'] === true && $cagri === 3 && (int)$db->query("SELECT consecutive_failures FROM mail_sync_state WHERE account_id = $A")->fetchColumn() === 3);
ok('geri çekilme günlük satırı üretmez (spam yok)', (int)$db->query("SELECT COUNT(*) FROM mail_sync_log WHERE account_id = $A")->fetchColumn() === 3);
$r = $sen(600 + 301);
ok('süre dolunca yeniden denenir', $r['bekle'] === false && $cagri === 4);
$r = $sen(600 + 301 + 10, ['zorla' => true]);
ok('elle senkron ("zorla") geri çekilmeyi atlar', $cagri === 5 && $r['bekle'] === false);
$say = $cagri;
$cron = mail_cron_calistir($db, ['istemci' => $kotu, 'kilit_dizin' => $KILIT, 'simdi' => $T0 + 600 + 301 + 20, 'ceviri_saglayici' => null]);
ok('cron: geri çekilmedeki hesap BEKLE satırı, bağlantı yok, çıkış kodu 0', $cagri === $say && $cron['kod'] === 0 && (bool)array_filter($cron['satirlar'], fn($l) => str_starts_with($l, "BEKLE hesap=$A")), json_encode($cron['satirlar']));
// Başarı sayacı sıfırlar
$ok = fn(array $h) => (function () use ($h) { $s = new FakeMailStream(['user' => $h['imap_user'], 'pass' => $h['imap_pass'], 'mesajlar' => []]); $c = new MailImapClient($s); $c->baslat(false); $c->girisYap($h['imap_user'], $h['imap_pass']); return $c; })();
$r = mail_sync_hesap($db, $A, ['istemci' => $ok, 'kilit_dizin' => $KILIT, 'simdi' => $T0 + 90000, 'zorla' => true]);
ok('başarılı senkron sayacı sıfırlar + bekleme biter', $r['ok'] && (int)$db->query("SELECT consecutive_failures FROM mail_sync_state WHERE account_id = $A")->fetchColumn() === 0, json_encode($r));

echo "\n=== 2. IMAP toplam oturum süresi ===\n";
$s = new FakeMailStream(['user' => 'u', 'pass' => 'p', 'mesajlar' => []]);
$c = new MailImapClient($s, ['toplam_sn' => 0.0]);
$e = null; try { $c->baslat(false); $c->girisYap('u', 'p'); $c->klasorAc('INBOX'); } catch (MailImapException $ex) { $e = $ex; }
ok('toplam süre dolmuşsa komut zaman aşımıyla kesilir (yavaş-damla sunucu oturumu sonsuza uzatamaz)', $e !== null && $e->kind === 'timeout', $e?->getMessage() ?? 'hata yok');
$src = (string)file_get_contents($ROOT . '/config/mail_imap.php');
ok('gerçek soket akışı toplam süre sınırını alır (sureSinirla)', str_contains($src, "method_exists(\$s, 'sureSinirla')") && str_contains($src, "'toplam_sn'"));

echo "\n=== 3. MAIL_MASTER_KEY rotasyonu ===\n";
$B = hesap('b@asya.com', 'IMAP-PAROLA-B');
$eskiKey = base64_encode(random_bytes(32)); $yeniKey = base64_encode(random_bytes(32));
putenv('MAIL_MASTER_KEY=' . $eskiKey);
$ikisi = fn(int $id) => $db->query("SELECT imap_pass_enc, smtp_pass_enc FROM mail_accounts WHERE id = $id")->fetch();
// hesapları eski anahtarla yeniden kaydet (şifreler eski anahtarla)
mail_hesap_kaydet(['label' => 'a', 'email' => 'a@asya.com', 'imap_host' => 'i.t.com', 'imap_user' => 'a@asya.com', 'imap_pass' => 'IMAP-PAROLA-1', 'smtp_host' => 's.t.com', 'smtp_user' => 'a@asya.com', 'smtp_pass' => 'SMTP-PAROLA-2'], $A, 1, $db);
mail_hesap_kaydet(['label' => 'b', 'email' => 'b@asya.com', 'imap_host' => 'i.t.com', 'imap_user' => 'b@asya.com', 'imap_pass' => 'IMAP-PAROLA-B', 'smtp_host' => 's.t.com', 'smtp_user' => 'b@asya.com', 'smtp_pass' => 'SMTP-PAROLA-2'], $B, 1, $db);
$once = [$ikisi($A), $ikisi($B)];
$r = mail_anahtar_donustur($db, $eskiKey, $yeniKey, false);
ok('kuru çalıştırma: tüm blob\'lar çözüldü, HİÇBİR şey yazılmadı', $r['ok'] && $r['hesap'] === 2 && $r['alan'] === 4 && [$ikisi($A), $ikisi($B)] === $once, json_encode($r));
$yanlis = base64_encode(random_bytes(32));
$r = mail_anahtar_donustur($db, $yanlis, $yeniKey, true);
ok('YANLIŞ eski anahtar: hep-ya-hiç — hiçbir şey değişmez', !$r['ok'] && [$ikisi($A), $ikisi($B)] === $once && str_contains($r['mesaj'], 'HİÇBİR'), $r['mesaj']);
ok('aynı anahtar / geçersiz anahtar reddedilir', !mail_anahtar_donustur($db, $eskiKey, $eskiKey, true)['ok'] && !mail_anahtar_donustur($db, 'kısa', $yeniKey, true)['ok'] && !mail_anahtar_donustur($db, $eskiKey, '', true)['ok']);
// Bir hesabın blob'u bozuksa HİÇBİR hesap yazılmamalı
$db->exec("UPDATE mail_accounts SET smtp_pass_enc = 'v1:xxxxxxxx:bozuk' WHERE id = $B");
$bozukOnce = $ikisi($B);
$r = mail_anahtar_donustur($db, $eskiKey, $yeniKey, true);
ok('bir blob bozuksa HİÇ hesap yeniden yazılmaz (A da dokunulmaz)', !$r['ok'] && $ikisi($A) === $once[0] && $ikisi($B) === $bozukOnce);
$db->prepare("UPDATE mail_accounts SET smtp_pass_enc = ? WHERE id = $B")->execute([$once[1]['smtp_pass_enc']]);
$r = mail_anahtar_donustur($db, $eskiKey, $yeniKey, true);
ok('uygulama başarılı: 2 hesap / 4 alan', $r['ok'] && $r['hesap'] === 2 && $r['alan'] === 4, json_encode($r));
ok('blob\'lar DEĞİŞTİ (yeni nonce/kid)', $ikisi($A)['imap_pass_enc'] !== $once[0]['imap_pass_enc'] && $ikisi($B)['smtp_pass_enc'] !== $once[1]['smtp_pass_enc']);
ok('ESKİ anahtarla artık çözülmez (kimlik bilgisi okunamaz → fail-closed)', mail_hesap_cred_oku($A, $db) === null);
putenv('MAIL_MASTER_KEY=' . $yeniKey);
$h = mail_hesap_cred_oku($A, $db); $hb = mail_hesap_cred_oku($B, $db);
ok('YENİ anahtarla parolalar aynen çözülür (AAD hesap id\'sine bağlı kaldı)', $h && $h['imap_pass'] === 'IMAP-PAROLA-1' && $h['smtp_pass'] === 'SMTP-PAROLA-2' && $hb && $hb['imap_pass'] === 'IMAP-PAROLA-B');
ok('blob başka hesaba taşınırsa çözülmez (AAD)', mail_coz($ikisi($A)['imap_pass_enc'], mail_aad($B, 'imap_pass')) === null);
$db->exec("UPDATE mail_accounts SET imap_pass_enc = NULL, smtp_pass_enc = '' WHERE id = $B");
ok('boş/NULL blob\'lı hesap rotasyonu engellemez', mail_anahtar_donustur($db, $yeniKey, base64_encode(random_bytes(32)), false)['ok']);
$betik = (string)file_get_contents($ROOT . '/scripts/mail_rotate_key.php');
ok('rotasyon betiği CLI-only + anahtarlar ortam değişkeninden (argv\'den değil) + anahtarı yazdırmıyor', str_contains($betik, "PHP_SAPI !== 'cli'") && str_contains($betik, "getenv('MAIL_MASTER_KEY_OLD')") && !preg_match('/\$argv\[\s*[1-9]/', $betik) && !preg_match('/echo[^;]*\$(eski|yeni)\b/', $betik));
ok('rotasyon betiği varsayılan KURU çalışır (yazmak için --uygula)', str_contains($betik, "'--uygula'") && str_contains($betik, '$uygula'));

echo "\n=== 4. İşletme uyarıları ===\n";
$lp = sys_get_temp_dir() . '/mail_local_' . getmypid() . '.php'; file_put_contents($lp, '<?php // test');
$depo = sys_get_temp_dir() . '/mail_depo_' . getmypid(); @mkdir($depo, 0700, true);
$mesajlar = fn(array $u) => implode(' | ', array_column($u, 'mesaj'));
chmod($lp, 0644); chmod($depo, 0755);
$u = mail_yapilandirma_uyarilari($db, ['local_php' => $lp, 'depo' => $depo, 'simdi' => $T0 + 90000]);
ok('local.php herkesçe okunabilirse uyarı', str_contains($mesajlar($u), 'local.php'));
ok('storage/mail dizini herkese açıksa uyarı', str_contains($mesajlar($u), 'storage/mail'));
chmod($lp, 0600); chmod($depo, 0700);
$u = mail_yapilandirma_uyarilari($db, ['local_php' => $lp, 'depo' => $depo, 'simdi' => $T0 + 90000 + 60]);
ok('izinler sıkıysa izin uyarısı yok', !str_contains($mesajlar($u), 'local.php') && !str_contains($mesajlar($u), 'storage/mail'), $mesajlar($u));
$sonLog = (string)$db->query('SELECT MAX(started_at) FROM mail_sync_log')->fetchColumn();
$u = mail_yapilandirma_uyarilari($db, ['local_php' => $lp, 'depo' => $depo, 'simdi' => strtotime($sonLog) + 7200]);
ok('kalp atışı YOK + 2 saattir senkron yok → "cron çalıştığına dair kayıt yok" + gerçek komut', str_contains($mesajlar($u), 'kayıt yok') && (bool)array_filter($u, fn($x) => str_contains($x['komut'] ?? '', 'scripts/mail_sync_cron.php')));
$u = mail_yapilandirma_uyarilari($db, ['local_php' => $lp, 'depo' => $depo, 'simdi' => strtotime($sonLog) + 120]);
ok('taze senkronda cron uyarısı yok', !str_contains($mesajlar($u), 'cron'));

echo "\n=== 4b. Cron kalp atışı (M9: \"cron durmuş olabilir\" yanlış alarmı) ===\n";
$kd = sys_get_temp_dir() . '/mail_kalp_' . getmypid(); @mkdir($kd, 0700, true);
mail_redact_sirlar('KALP-SIR-DEGER');
$cr = mail_cron_calistir($db, ['istemci' => $kotu, 'kilit_dizin' => $kd, 'simdi' => $T0 + 200000, 'ceviri_saglayici' => null]);
$k = mail_cron_kalp_oku($kd);
ok('cron her çalışmada kalp atışı yazar (zaman, kod, SAPI, PHP sürümü, satırlar)', $k !== null && $k['zaman'] === $T0 + 200000 && $k['kod'] === $cr['kod'] && $k['sapi'] === PHP_SAPI && $k['php'] === PHP_VERSION && $k['satirlar'] === array_map(fn($l) => mb_substr($l, 0, 200), $cr['satirlar']), json_encode($k));
ok('kalp dosyası izinleri 0600', (fileperms($kd . '/' . MAIL_CRON_KALP) & 0777) === 0600);
mail_cron_kalp_yaz(['kod' => 1, 'satirlar' => ['FAIL hesap=1 error=parola KALP-SIR-DEGER yanlış']], $kd, $T0);
ok('kalp atışında sır MASKELİ', !str_contains((string)file_get_contents($kd . '/' . MAIL_CRON_KALP), 'KALP-SIR-DEGER'));
// Senaryolar: kalp taze + BEKLE → "bekletiliyor" (cron durmuş DEĞİL)
$db->exec("INSERT INTO mail_sync_log (account_id, started_at, status) VALUES (1, '" . date('Y-m-d H:i:s', $T0 - 6000) . "', 'error')");
mail_cron_kalp_yaz(['kod' => 0, 'satirlar' => ['BEKLE hesap=1 Art arda 7 hata: geri çekilme, 60 dk sonra yeniden denenecek.']], $kd, $T0 - 60);
$u = mail_cron_durum_uyarilari($db, ['depo' => $kd, 'simdi' => $T0]);
ok('cron çalışıyor + hesap geri çekilmede → "bekletiliyor" (87 dk\'lık eski senkron "cron durmuş" SAYILMAZ)', str_contains($mesajlar($u), 'bekletiliyor') && !str_contains($mesajlar($u), 'çalışmıyor') && !str_contains($mesajlar($u), 'kayıt yok'), $mesajlar($u));
mail_cron_kalp_yaz(['kod' => 1, 'satirlar' => ['FAIL MAIL_MASTER_KEY tanımlı/geçerli değil']], $kd, $T0 - 60);
$u = mail_cron_durum_uyarilari($db, ['depo' => $kd, 'simdi' => $T0]);
ok('cron çalışıyor ama FAIL → hata seviyesinde, nedeniyle birlikte', count($u) === 1 && $u[0]['seviye'] === 'hata' && str_contains($u[0]['mesaj'], 'MAIL_MASTER_KEY'), $mesajlar($u));
mail_cron_kalp_yaz(['kod' => 0, 'satirlar' => ['OK hesap=1 fetched=0']], $kd, $T0 - 3 * 3600);
$u = mail_cron_durum_uyarilari($db, ['depo' => $kd, 'simdi' => $T0]);
ok('kalp 3 saat eski → "180 dakikadır çalışmıyor" + son sonuç + komut', str_contains($mesajlar($u), '180 dakikadır çalışmıyor') && str_contains($mesajlar($u), 'OK hesap=1') && !empty($u[0]['komut']), $mesajlar($u));
mail_cron_kalp_yaz(['kod' => 0, 'satirlar' => ['OK hesap=1 fetched=2 inserted=2']], $kd, $T0 - 120);
ok('kalp taze + OK → uyarı YOK (eski senkron günlüğü olsa bile)', mail_cron_durum_uyarilari($db, ['depo' => $kd, 'simdi' => $T0]) === []);
file_put_contents($kd . '/' . MAIL_CRON_KALP, '{bozuk');
ok('bozuk kalp dosyası çökertmez (kalp yok sayılır)', mail_cron_kalp_oku($kd) === null);
ok('cron komutu bu kurulumun gerçek yolunu içerir', str_contains(mail_cron_komutu(), realpath($ROOT . '/scripts/mail_sync_cron.php')) && str_starts_with(mail_cron_komutu(), '*/5 * * * * php '));
array_map('unlink', array_merge(glob($kd . '/*') ?: [], glob($kd . '/.[!.]*') ?: [])); @rmdir($kd);
$db->exec('DELETE FROM mail_sync_log');
$u = mail_yapilandirma_uyarilari($db, ['local_php' => $lp, 'depo' => $depo, 'simdi' => $T0]);
ok('hiç senkron + kalp yoksa cron kurulumu hatırlatılır (komutla)', str_contains($mesajlar($u), 'hiç çalışmadı') && (bool)array_filter($u, fn($x) => !empty($x['komut'])));
@unlink($lp); @rmdir($depo);

echo "\n=== 6. M8 bulguları ===\n";
// 1) Sunucu/kullanıcı değişince parola yeniden girilmeli (mail.admin delegasyonu ile parola sızdırma)
$S1 = hesap('guv@asya.com', 'GERCEK-IMAP-SIFRE');
$baz = ['label' => 'g', 'email' => 'guv@asya.com', 'imap_host' => 'i.t.com', 'imap_port' => 993, 'imap_security' => 'ssl', 'imap_user' => 'guv@asya.com', 'smtp_host' => 's.t.com', 'smtp_port' => 465, 'smtp_security' => 'ssl', 'smtp_user' => 'guv@asya.com', 'imap_pass' => '', 'smtp_pass' => ''];
$r = mail_hesap_kaydet(array_merge($baz, ['imap_host' => 'evil.example.net']), $S1, 1, $db);
ok('IMAP host değişti + parola boş → REDDEDİLİR (kayıtlı parola yeni sunucuya gitmez)', !$r['ok'] && str_contains(implode(' ', $r['hatalar']), 'yeniden girin') && mail_hesap_cred_oku($S1, $db)['imap_host'] === 'i.t.com');
$r = mail_hesap_kaydet(array_merge($baz, ['smtp_user' => 'baska@asya.com']), $S1, 1, $db);
ok('SMTP kullanıcı adı değişti + parola boş → reddedilir', !$r['ok']);
$r = mail_hesap_kaydet(array_merge($baz, ['imap_port' => 143, 'imap_security' => 'starttls']), $S1, 1, $db);
ok('port/güvenlik değişti + parola boş → reddedilir', !$r['ok']);
$r = mail_hesap_kaydet(array_merge($baz, ['imap_host' => 'evil.example.net', 'imap_pass' => 'YENI-PAROLA']), $S1, 1, $db);
ok('sunucu değişti + YENİ parola girildi → kabul', $r['ok'] && mail_hesap_cred_oku($S1, $db)['imap_pass'] === 'YENI-PAROLA');
$r = mail_hesap_kaydet(array_merge($baz, ['imap_host' => 'evil.example.net', 'label' => 'yeni etiket', 'display_name' => 'Ad']), $S1, 1, $db);
ok('sunucu/kullanıcı AYNI kalınca boş parola = değiştirme (etiket vb. düzenlenebilir)', $r['ok'] && mail_hesap_cred_oku($S1, $db)['imap_pass'] === 'YENI-PAROLA');
ok('host büyük/küçük harf farkı değişiklik sayılmaz', mail_hesap_kaydet(array_merge($baz, ['imap_host' => 'EVIL.example.NET']), $S1, 1, $db)['ok']);
// 5) kilit dosyası açılamıyor ≠ BUSY
$cr = mail_cron_calistir($db, ['kilit_dizin' => '/proc/yok/dizin', 'simdi' => $T0]);
ok('kilit dosyası açılamazsa cron FAIL + kod 1 (BUSY/0 DEĞİL — sessiz durma yok)', $cr['kod'] === 1 && str_starts_with($cr['satirlar'][0], 'FAIL') && !str_contains($cr['satirlar'][0], 'BUSY'), json_encode($cr));
$sr = mail_sync_hesap($db, $S1, ['kilit_dizin' => '/proc/yok/dizin', 'simdi' => $T0, 'zorla' => true]);
ok('hesap senkronu: kilit açılamazsa busy DEĞİL, hata mesajlı', $sr['busy'] === false && $sr['ok'] === false && str_contains((string)$sr['error'], 'Kilit dosyası'));
// 7) rotasyon: okuma transaction içinde ve kilitli
$ks = (string)file_get_contents($ROOT . '/config/mail_core.php');
$a0 = strpos($ks, 'function mail_anahtar_donustur');
$govde = substr($ks, $a0, 4000);
ok('rotasyon: SELECT ... FOR UPDATE, transaction İÇİNDE (okuma-yazma arası kaydedilen parola ezilmez)', strpos($govde, 'beginTransaction') < strpos($govde, 'SELECT id, imap_pass_enc') && str_contains($govde, "' FOR UPDATE'"));
// 4) yönlendirilmiş mail başlık bloğu sağlayıcıya GİTMEZ
$fw = mail_ceviri_hazirla("---------- Forwarded message ---------\nFrom: Alice Buyer <alice.buyer@customer-corp.com>\nDate: Mon, 5 Oct 2026\nSubject: Offer\nTo: Bob <bob.internal@asya.com>, cfo@asya.com\n\nPlease find the price list attached.");
ok('yönlendirme: gövde çevrilir ama From/To/Date/Subject başlık satırları (adresler) ASLA', str_contains($fw['metin'], 'price list') && !str_contains($fw['metin'], '@') && !str_contains($fw['metin'], 'Alice'), $fw['metin']);
// 2) hesapta çeviri kapalıyken "Şimdi çevir" sağlayıcıya gitmez
class SagKayit implements MailTranslationProviderInterface { public array $c = []; public function ad(): string { return 'sahte'; } public function parcaLimiti(): int { return 4000; } public function cevir(string $m, ?string $k, string $h): array { $this->c[] = $m; return ['metin' => 'X', 'tespit' => 'en']; } }
$db->exec("UPDATE mail_accounts SET translate_enabled = 0 WHERE id = $S1");
$db->prepare("INSERT INTO mail_messages (account_id, folder, uidvalidity, uid, message_id_hash, subject, from_addr, received_at, body_text, lang, tr_status, to_addrs, cc_addrs) VALUES (?, 'INBOX', 1, 1, 'h1', 'Gizli', 'x@y.com', '2026-10-05 10:00:00', 'Confidential amount 1.2M EUR', 'en', 'skipped', '[]', '[]')")->execute([$S1]);
$mid = (int)$db->lastInsertId(); $sg = new SagKayit();
$r = mail_ceviri_simdi($db, $sg, $mid, [$S1]);
ok('hesapta çeviri KAPALI: "Şimdi çevir" reddedilir, sağlayıcıya HİÇBİR ŞEY gitmez', !$r['ok'] && $sg->c === [] && str_contains($r['mesaj'], 'kapalı'), json_encode($r));
$db->exec("UPDATE mail_accounts SET translate_enabled = 1 WHERE id = $S1");
$r = mail_ceviri_simdi($db, $sg, $mid, [$S1]);
ok('hesapta çeviri AÇIK: aynı istek çalışır', $r['ok'] && count($sg->c) >= 1, json_encode($r));

echo "\n=== 5. Senkron günlüğü ekranı verisi ===\n";
mail_redact_sirlar('SIR-DEGER-XYZ');
$db->prepare("INSERT INTO mail_sync_log (account_id, started_at, finished_at, status, fetched, inserted, skipped, error) VALUES (?, '2026-10-06 12:00:00', '2026-10-06 12:00:03', 'error', 0, 0, 0, ?)")->execute([$A, 'Giriş başarısız parola=SIR-DEGER-XYZ']);
for ($i = 0; $i < 30; $i++) $db->prepare("INSERT INTO mail_sync_log (account_id, started_at, status) VALUES (?, ?, 'ok')")->execute([$A, date('Y-m-d H:i:s', $T0 + $i)]);
$g = mail_sync_gunluk_getir($db, 20);
ok('en çok 20 satır, en yeni önce', count($g['gunluk']) === 20 && $g['gunluk'][0]['id'] > $g['gunluk'][19]['id']);
ok('hesap durum satırları + bekleme alanı var', count($g['durum']) >= 2 && array_key_exists('bekleme_sn', $g['durum'][0]));
$g2 = mail_sync_gunluk_getir($db, 100000);
ok('limit sınırlı (≤100)', count($g2['gunluk']) <= 100);
$tum = json_encode(mail_sync_gunluk_getir($db, 100));
ok('günlükteki sır ekran verisinde MASKELİ', !str_contains($tum, 'SIR-DEGER-XYZ'));
$ek = (string)file_get_contents($ROOT . '/mail_hesaplar.php');
ok('ekran yalnız mail.admin sayfasında (require_mail admin) ve h() ile kaçırıyor', str_contains($ek, "require_mail('admin')") && str_contains($ek, 'mail_sync_gunluk_getir') && !preg_match('/<\?=\s*\$g\[/', $ek));

mail_test_bitir();
