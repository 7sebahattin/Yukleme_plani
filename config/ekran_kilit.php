<?php
// =============================================================================
// config/ekran_kilit.php — Masaüstü ekran kilidi (v454)
// =============================================================================
// Masaüstünde 5 dk hareketsizlikte sayfa bir şifre penceresiyle kilitlenir; kullanıcı
// YALNIZ şifresini girip kaldığı yerden devam eder (sayfa yenilenmez, form/sekme durumu
// korunur). Mobilde/dokunmatikte kilit YOK (istemci kararı: assets/app.js).
//
// Bu bir EKRAN kilididir (oturum çerezi geçerli kalır, giriş yapılmış sayılır):
//   • Durum PHP oturumunda ($_SESSION['ekran_kilitli']) — sayfa yenilense / başka sekme
//     açılsa da kilitli görünür. Aç = oturum sahibinin KENDİ şifresi.
//   • Yanlış şifre sınırı: 5 hata → 30 sn bekleme (oturum başına).
//   • Şifre hiçbir yere (audit/log) yazılmaz.
// Kilit sayacı/pencere görünürlüğü istemcidedir; sunucu yalnız durumu tutar ve şifreyi doğrular.
// Fonksiyonlar çıktı basmaz / exit etmez (test edilebilir). Test: scripts/ekran_kilit_smoke.php
// =============================================================================

const EKRAN_KILIT_MAX_HATA   = 5;
const EKRAN_KILIT_BEKLEME_SN = 30;

function ekran_kilit_oturum(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
}

function ekran_kilitli_mi(): bool {
    ekran_kilit_oturum();
    return !empty($_SESSION['ekran_kilitli']);
}

function ekran_kilitle(): void {
    ekran_kilit_oturum();
    $_SESSION['ekran_kilitli'] = 1;
}

// Şifreyi doğrular, doğruysa kilidi açar. Dönüş: ['ok'=>bool, 'mesaj'=>string, 'bekle'=>int sn].
function ekran_kilit_ac(PDO $pdo, int $userId, string $sifre, ?int $simdi = null): array {
    ekran_kilit_oturum();
    $simdi = $simdi ?? time();

    $bekleBitis = (int)($_SESSION['ekran_kilit_bekle'] ?? 0);
    if ($bekleBitis > $simdi) {
        $kalan = $bekleBitis - $simdi;
        return ['ok' => false, 'mesaj' => 'Çok fazla hatalı deneme. ' . $kalan . ' sn sonra tekrar deneyin.', 'bekle' => $kalan];
    }
    if ($userId <= 0 || $sifre === '') {
        return ['ok' => false, 'mesaj' => 'Şifrenizi girin.', 'bekle' => 0];
    }

    $hash = '';
    try {
        $st = $pdo->prepare('SELECT password_hash, is_active FROM users WHERE id = ?');
        $st->execute([$userId]);
        $u = $st->fetch();
        if ($u && (int)$u['is_active'] === 1) $hash = (string)$u['password_hash'];
    } catch (Throwable $e) {
        error_log('[ekran_kilit] ' . $e->getMessage());
        return ['ok' => false, 'mesaj' => 'Şifre doğrulanamadı. Tekrar deneyin.', 'bekle' => 0];
    }

    if ($hash !== '' && password_verify($sifre, $hash)) {
        unset($_SESSION['ekran_kilitli'], $_SESSION['ekran_kilit_hata'], $_SESSION['ekran_kilit_bekle']);
        return ['ok' => true, 'mesaj' => '', 'bekle' => 0];
    }

    $hata = (int)($_SESSION['ekran_kilit_hata'] ?? 0) + 1;
    if ($hata >= EKRAN_KILIT_MAX_HATA) {
        $_SESSION['ekran_kilit_hata']  = 0;
        $_SESSION['ekran_kilit_bekle'] = $simdi + EKRAN_KILIT_BEKLEME_SN;
        return ['ok' => false, 'mesaj' => 'Çok fazla hatalı deneme. ' . EKRAN_KILIT_BEKLEME_SN . ' sn sonra tekrar deneyin.',
                'bekle' => EKRAN_KILIT_BEKLEME_SN];
    }
    $_SESSION['ekran_kilit_hata'] = $hata;
    return ['ok' => false, 'mesaj' => 'Şifre hatalı.', 'bekle' => 0];
}

// Kilit penceresi işaretlemesi (render_footer basar). Kapalı başlar; sunucu kilitliyse
// data-kilitli="1" taşır ve app.js sayfa yüklenince açar. Kullanıcı adı h() ile kaçırılır.
function ekran_kilit_html(?array $user): string {
    if ($user === null) return '';
    $ad = trim((string)($user['display_name'] ?? '')) !== '' ? (string)$user['display_name'] : (string)($user['username'] ?? '');
    $kilitli = ekran_kilitli_mi() ? ' data-kilitli="1"' : '';
    $base = function_exists('base_url') ? base_url() : '';
    return '<dialog id="ekranKilit" class="ekran-kilit" aria-labelledby="ekranKilitBaslik"' . $kilitli
        . ' data-uc="' . h($base . 'ekran_kilit.php') . '" data-login="' . h($base . 'login.php') . '">'
        . '<form id="ekranKilitForm" class="ekran-kilit-kart" autocomplete="off" novalidate>'
        . '<div class="ekran-kilit-ikon" aria-hidden="true">🔒</div>'
        . '<h2 id="ekranKilitBaslik">Oturum kilitlendi</h2>'
        . '<p class="ekran-kilit-not">Hareketsizlik nedeniyle ekran kilitlendi. <b>' . h($ad) . '</b> olarak devam etmek için şifrenizi girin.</p>'
        . '<input type="password" id="ekranKilitSifre" name="sifre" class="ekran-kilit-girdi" placeholder="Şifre" '
        . 'autocomplete="current-password" aria-label="Şifre" required>'
        . '<div id="ekranKilitHata" class="ekran-kilit-hata" role="alert" hidden></div>'
        . '<button type="submit" id="ekranKilitBtn" class="btn btn-primary ekran-kilit-btn">Kilidi Aç</button>'
        . '<a class="ekran-kilit-cikis" href="' . h($base . 'logout.php') . '">Çıkış yap</a>'
        . '</form></dialog>';
}
