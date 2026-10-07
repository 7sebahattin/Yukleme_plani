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
ok('2 saattir senkron çalışmadıysa "cron durmuş olabilir" uyarısı', str_contains($mesajlar($u), 'cron durmuş'));
$u = mail_yapilandirma_uyarilari($db, ['local_php' => $lp, 'depo' => $depo, 'simdi' => strtotime($sonLog) + 120]);
ok('taze senkronda cron uyarısı yok', !str_contains($mesajlar($u), 'cron'));
$db->exec('DELETE FROM mail_sync_log');
$u = mail_yapilandirma_uyarilari($db, ['local_php' => $lp, 'depo' => $depo, 'simdi' => $T0]);
ok('hiç senkron yoksa cron kurulumu hatırlatılır', str_contains($mesajlar($u), 'hiç çalışmadı'));
@unlink($lp); @rmdir($depo);

echo "\n=== 5. Senkron günlüğü ekranı verisi ===\n";
mail_redact_sirlar('SIR-DEGER-XYZ');
$db->prepare("INSERT INTO mail_sync_log (account_id, started_at, finished_at, status, fetched, inserted, skipped, error) VALUES (?, '2026-10-06 12:00:00', '2026-10-06 12:00:03', 'error', 0, 0, 0, ?)")->execute([$A, 'Giriş başarısız parola=SIR-DEGER-XYZ']);
for ($i = 0; $i < 30; $i++) $db->prepare("INSERT INTO mail_sync_log (account_id, started_at, status) VALUES (?, ?, 'ok')")->execute([$A, date('Y-m-d H:i:s', $T0 + $i)]);
$g = mail_sync_gunluk_getir($db, 20);
ok('en çok 20 satır, en yeni önce', count($g['gunluk']) === 20 && $g['gunluk'][0]['id'] > $g['gunluk'][19]['id']);
ok('hesap durum satırları + bekleme alanı var', count($g['durum']) === 2 && array_key_exists('bekleme_sn', $g['durum'][0]));
$g2 = mail_sync_gunluk_getir($db, 100000);
ok('limit sınırlı (≤100)', count($g2['gunluk']) <= 100);
$tum = json_encode(mail_sync_gunluk_getir($db, 100));
ok('günlükteki sır ekran verisinde MASKELİ', !str_contains($tum, 'SIR-DEGER-XYZ'));
$ek = (string)file_get_contents($ROOT . '/mail_hesaplar.php');
ok('ekran yalnız mail.admin sayfasında (require_mail admin) ve h() ile kaçırıyor', str_contains($ek, "require_mail('admin')") && str_contains($ek, 'mail_sync_gunluk_getir') && !preg_match('/<\?=\s*\$g\[/', $ek));

mail_test_bitir();
