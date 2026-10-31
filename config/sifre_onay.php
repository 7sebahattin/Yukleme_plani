<?php
declare(strict_types=1);
// =========================================================
// config/sifre_onay.php — "Şifre ile onay" yardımcısı (v464)
//
// Yıkıcı/geri alınması zor işlemlerden (ör. çavuşun fiş yüklemelerini geri al) ÖNCE oturumdaki kullanıcının GİRİŞ ŞİFRESİNİ
// yeniden doğrular. Giriş şifresini (users.password_hash, password_verify) doğrular — PIN'i DEĞİL.
//
// Ekran kilidi / PIN kodundan (config/ekran_kilit.php, ekran_kilit.php, asya_kilit çerezi, hks_kv ekran_pin_*) TAMAMEN
// BAĞIMSIZDIR: o koda dokunmaz, onun çerezini/KV kaydını okumaz/yazmaz, kilit durumunu DEĞİŞTİRMEZ.
//
// DEĞİŞMEZLER:
//  - Kullanıcı kimliği çağıranın verdiği oturum kimliğidir; İSTEMCİDEN ALINMAZ (çağıran current_user()'dan geçirir).
//  - Şifre hiçbir yere YAZILMAZ: audit, error_log, exception mesajı, dönüş değeri.
//  - Hatalı deneme sayacı PHP oturumunda, bağlam (işlem) başına AYRI tutulur: 5 ardışık hata → 60 sn bekleme; bekleme
//    sürerken DOĞRU şifre de reddedilir (sayaç/şifre kontrolü yapılmaz). Başarıda sayaç sıfırlanır.
//  - Fonksiyon çıktı basmaz, exit etmez (test edilebilir).
// =========================================================

const SIFRE_ONAY_MAX_HATA = 5;
const SIFRE_ONAY_BEKLEME_SN = 60;

/**
 * @param string $baglam işlem adı ([a-z0-9_]{1,40}); sayaçlar bağlam başına ayrıdır
 * @param ?int   $simdi  yalnız test için (unix zamanı)
 * @return array{ok:bool, mesaj:string, bekle:int, engelli?:bool}
 */
function sifre_onay_dogrula(PDO $pdo, int $userId, string $sifre, string $baglam, ?int $simdi = null): array
{
    $simdi = $simdi ?? time();
    if (!preg_match('/^[a-z0-9_]{1,40}$/D', $baglam)) return ['ok' => false, 'mesaj' => 'Geçersiz onay bağlamı.', 'bekle' => 0];
    if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli' && !headers_sent()) @session_start();
    $durum = $_SESSION['sifre_onay'][$baglam] ?? [];
    $hata = (int)($durum['hata'] ?? 0);
    $bekle = (int)($durum['bekle'] ?? 0);
    if ($bekle > $simdi) {
        return ['ok' => false, 'engelli' => true, 'bekle' => $bekle - $simdi,
                'mesaj' => 'Çok fazla hatalı deneme. ' . ($bekle - $simdi) . ' sn sonra tekrar deneyin.'];
    }
    if ($userId < 1) return ['ok' => false, 'mesaj' => 'Oturum bulunamadı.', 'bekle' => 0];
    if ($sifre === '') return ['ok' => false, 'mesaj' => 'Şifrenizi girin.', 'bekle' => 0];   // boş girdi sayaca YAZILMAZ

    $gecerli = false;
    try {
        $st = $pdo->prepare('SELECT password_hash, is_active FROM users WHERE id = ?');
        $st->execute([$userId]);
        $u = $st->fetch();
        $gecerli = $u && (int)($u['is_active'] ?? 0) === 1 && is_string($u['password_hash'] ?? null) && $u['password_hash'] !== ''
            && password_verify($sifre, (string)$u['password_hash']);
    } catch (Throwable $e) {
        error_log('[sifre_onay] doğrulama hatası: ' . get_class($e));   // yalnız sınıf adı: ne şifre ne SQL ne ileti
        return ['ok' => false, 'mesaj' => 'Şifre doğrulanamadı (teknik hata). Tekrar deneyin.', 'bekle' => 0];
    }

    if ($gecerli) {
        unset($_SESSION['sifre_onay'][$baglam]);
        return ['ok' => true, 'mesaj' => '', 'bekle' => 0];
    }
    $hata++;
    if ($hata >= SIFRE_ONAY_MAX_HATA) {
        $_SESSION['sifre_onay'][$baglam] = ['hata' => 0, 'bekle' => $simdi + SIFRE_ONAY_BEKLEME_SN];
        // TEK audit satırı: yalnız bağlam + kullanıcı + sayı (şifre YOK). Hata yutulur.
        try {
            if (function_exists('audit_log_event')) {
                audit_log_event('sifre_onay_engel', 'auth', $userId, null, ['baglam' => $baglam, 'hata_sayisi' => SIFRE_ONAY_MAX_HATA, 'bekleme_sn' => SIFRE_ONAY_BEKLEME_SN]);
            }
        } catch (Throwable $e) {
            error_log('[sifre_onay] audit yazılamadı: ' . get_class($e));
        }
        return ['ok' => false, 'engelli' => true, 'bekle' => SIFRE_ONAY_BEKLEME_SN,
                'mesaj' => 'Çok fazla hatalı deneme. ' . SIFRE_ONAY_BEKLEME_SN . ' sn sonra tekrar deneyin.'];
    }
    $_SESSION['sifre_onay'][$baglam] = ['hata' => $hata, 'bekle' => 0];
    return ['ok' => false, 'bekle' => 0, 'mesaj' => 'Şifre hatalı (' . (SIFRE_ONAY_MAX_HATA - $hata) . ' deneme hakkı kaldı).'];
}
