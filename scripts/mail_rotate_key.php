<?php
// =========================================================
// scripts/mail_rotate_key.php — MAIL_MASTER_KEY rotasyonu (CLI-only; çalıştırılması sahibin kararı)
//
//   MAIL_MASTER_KEY_OLD='<eski b64>' MAIL_MASTER_KEY_NEW='<yeni b64>' php scripts/mail_rotate_key.php            # kuru çalıştırma
//   MAIL_MASTER_KEY_OLD=… MAIL_MASTER_KEY_NEW=… php scripts/mail_rotate_key.php --uygula                          # yazar
//
// Anahtarlar komut satırı ARGÜMANI DEĞİL, ortam değişkenidir (süreç listesinde/kabuk geçmişinde sızmasın).
// Sıra: ① DB yedeği al ② kuru çalıştırma ③ --uygula ④ HEMEN config/local.php'deki anahtarı yeniyle değiştir.
// ③–④ arasında senkron/gönderim kapalı-güvenli başarısız olur (veri kaybı yok). Ayrıntı: docs/MAIL_OPERATIONS.md
// =========================================================
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
$ROOT = dirname(__DIR__);
require_once $ROOT . '/config/db.php';
require_once $ROOT . '/config/helpers.php';
require_once $ROOT . '/config/mail_core.php';

$eski = (string)(getenv('MAIL_MASTER_KEY_OLD') ?: '');
$yeni = (string)(getenv('MAIL_MASTER_KEY_NEW') ?: '');
$uygula = in_array('--uygula', $argv ?? [], true);
if ($eski === '' || $yeni === '') { fwrite(STDERR, "FAIL MAIL_MASTER_KEY_OLD ve MAIL_MASTER_KEY_NEW ortam değişkenleri gerekli.\n"); exit(2); }
mail_redact_sirlar($eski); mail_redact_sirlar($yeni);
$r = mail_anahtar_donustur(db(), $eski, $yeni, $uygula);
if ($uygula && $r['ok']) {
    audit_log_event('mail_key_rotate', 'mail_accounts', null, null, ['hesap' => $r['hesap'], 'alan' => $r['alan']]);
}
echo ($r['ok'] ? 'OK ' : 'FAIL ') . "hesap={$r['hesap']} alan={$r['alan']} {$r['mesaj']}\n";
exit($r['ok'] ? 0 : 1);
