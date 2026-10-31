<?php
// =============================================================================
// config/ekran_kilit.php — Masaüstü ekran kilidi, PIN'li (v455)
// =============================================================================
// Masaüstünde 5 dk hareketsizlikte sayfa bir PIN penceresiyle kilitlenir; kullanıcı YALNIZ
// kendi 4–8 haneli rakamsal PIN'ini girip kaldığı yerden devam eder (sayfa yenilenmez).
// Mobilde/dokunmatikte kilit YOK (istemci kararı: assets/app.js). Bu bir EKRAN kilididir:
// oturum çerezi geçerli kalır.
//
//  • Kilit durumu PHP oturumundan BAĞIMSIZ: imzalı çerez `asya_kilit` = '1.' + HMAC(asya_session
//    token). PHP oturumu (PHPSESSID) GC'lenince kilit ne kaybolur ne de uç 403 verir.
//  • PIN `hks_kv` içinde (anahtar `ekran_pin_<uid>`): password_hash(HMAC(pin, pepper)). Sır =
//    HKS_CRED_KEY (config/local.php). Sır yoksa özellik PASİF (hiçbir pencere/otomatik kilit çıkmaz).
//  • Yanlış PIN sayacı kullanıcıya bağlı ve kalıcıdır (kv içinde): 5→30 sn, 6→120, 7→600, 8→1800,
//    9→3600, 10. yanlışta oturum sonlandırılır.
//  • PIN / hash / şifre hiçbir yere (audit/log) yazılmaz. Hata günlüğüne yalnız istisna sınıf adı girer.
//  • $_SESSION yalnız ESKİ (v454) anahtarları silmek için anılır.
// Fonksiyonlar çıktı basmaz / exit etmez (test edilebilir). Test: scripts/ekran_kilit_smoke.php
// =============================================================================

const EKRAN_KILIT_COOKIE      = 'asya_kilit';
const EKRAN_PIN_REGEX         = '/^\d{4,8}$/D';
const EKRAN_PIN_UYARI_ESIK    = 5;
const EKRAN_PIN_CIKIS_ESIK    = 10;
const EKRAN_PIN_BEKLEME       = [5 => 30, 6 => 120, 7 => 600, 8 => 1800, 9 => 3600];
const EKRAN_KILIT_NABIZ_SN    = 300;

// ── Sır + çerez ──────────────────────────────────────────────────────────────

function ekran_kilit_sir(): string {
    return (defined('HKS_CRED_KEY') && is_string(HKS_CRED_KEY) && strlen(HKS_CRED_KEY) >= 16) ? HKS_CRED_KEY : '';
}

function ekran_kilit_oturum_cerezi_adi(): string {
    return defined('AUTH_COOKIE_NAME') ? (string)AUTH_COOKIE_NAME : 'asya_session';
}

function ekran_kilit_token_gecerli(?string $token): bool {
    return is_string($token) && strlen($token) === 64 && ctype_xdigit($token);
}

function ekran_kilit_cookie_degeri(string $token): string {
    $sir = ekran_kilit_sir();
    if ($sir === '' || !ekran_kilit_token_gecerli($token)) return '';
    return '1.' . hash_hmac('sha256', 'kilit|' . $token, hash_hmac('sha256', 'ekran_kilit_cookie_v1', $sir));
}

function ekran_kilit_cerez_secenekleri(int $expires): array {
    $o = function_exists('auth_cookie_options') ? auth_cookie_options() : [
        'path' => '/', 'domain' => '', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax',
    ];
    $o['expires'] = $expires;
    return $o;
}

// Çerezden (ya da verilen parametrelerden) kilit durumu. Oturum/DB'ye DOKUNMAZ.
function ekran_kilitli_mi(?string $token = null, ?string $cerez = null): bool {
    $token = $token ?? (string)($_COOKIE[ekran_kilit_oturum_cerezi_adi()] ?? '');
    $cerez = $cerez ?? (string)($_COOKIE[EKRAN_KILIT_COOKIE] ?? '');
    $beklenen = ekran_kilit_cookie_degeri($token);
    return $beklenen !== '' && $cerez !== '' && hash_equals($beklenen, $cerez);
}

function ekran_kilitle(?string $token = null): bool {
    $token = $token ?? (string)($_COOKIE[ekran_kilit_oturum_cerezi_adi()] ?? '');
    $deger = ekran_kilit_cookie_degeri($token);
    if ($deger === '') return false;
    $ok = @setcookie(EKRAN_KILIT_COOKIE, $deger, ekran_kilit_cerez_secenekleri(time() + 24 * 3600));
    $_COOKIE[EKRAN_KILIT_COOKIE] = $deger;
    return (bool)$ok;
}

// Çerezi siler (giriş/çıkış/açma). Eski v454 oturum anahtarlarını da temizler (oturum BAŞLATMAZ).
function ekran_kilit_temizle(): void {
    @setcookie(EKRAN_KILIT_COOKIE, '', ekran_kilit_cerez_secenekleri(time() - 3600));
    unset($_COOKIE[EKRAN_KILIT_COOKIE]);
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['ekran_kilitli'], $_SESSION['ekran_kilit_hata'], $_SESSION['ekran_kilit_bekle']);
    }
}

// ── PIN deposu (hks_kv) ──────────────────────────────────────────────────────

function ekran_pin_kv_tablo(): string {
    $on = defined('HKS_TABLO_ON') ? (string)HKS_TABLO_ON : 'hks_';
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $on)) throw new RuntimeException('Geçersiz KV tablo adı.');
    return $on . 'kv';
}

function ekran_pin_anahtar(int $uid): string {
    return 'ekran_pin_' . $uid;
}

function ekran_pin_gecerli_mi(string $pin): bool {
    return (bool)preg_match(EKRAN_PIN_REGEX, $pin);
}

function ekran_pin_pepper(): string {
    return hash_hmac('sha256', 'ekran_pin_pepper_v1', ekran_kilit_sir());
}

function ekran_pin_ozet(string $pin): string {
    return password_hash(hash_hmac('sha256', $pin, ekran_pin_pepper()), PASSWORD_DEFAULT);
}

function &ekran_pin_onbellek(): array {
    static $o = [];
    return $o;
}

function ekran_pin_onbellek_temizle(): void {
    $o = &ekran_pin_onbellek();
    $o = [];
}

function ekran_pin_mysql(PDO $pdo): bool {
    try { return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; } catch (Throwable $e) { return false; }
}

// Kaydı okur (null = satır yok; hata → istisna). $kilitle: MySQL'de FOR UPDATE (transaction içinde).
function ekran_pin_kv_oku(PDO $pdo, int $uid, bool $kilitle = false): ?array {
    $sql = 'SELECT deger FROM `' . ekran_pin_kv_tablo() . '` WHERE anahtar = ?';
    if ($kilitle && ekran_pin_mysql($pdo)) $sql .= ' FOR UPDATE';
    $st = $pdo->prepare($sql);
    $st->execute([ekran_pin_anahtar($uid)]);
    $d = $st->fetchColumn();
    if ($d === false || $d === null || $d === '') return null;
    $j = json_decode((string)$d, true);
    if (!is_array($j) || !isset($j['h']) || !is_string($j['h']) || $j['h'] === '') return null;
    return [
        'h' => $j['h'], 't' => (int)($j['t'] ?? 0),
        'toplam_hata' => max(0, (int)($j['toplam_hata'] ?? 0)),
        'bekle_bitis' => max(0, (int)($j['bekle_bitis'] ?? 0)),
    ];
}

function ekran_pin_kv_json(array $k): string {
    return json_encode(['h' => $k['h'], 't' => (int)$k['t'], 'toplam_hata' => (int)$k['toplam_hata'], 'bekle_bitis' => (int)$k['bekle_bitis']]);
}

function ekran_pin_kv_guncelle(PDO $pdo, int $uid, array $k): void {
    $st = $pdo->prepare('UPDATE `' . ekran_pin_kv_tablo() . '` SET deger = ? WHERE anahtar = ?');
    $st->execute([ekran_pin_kv_json($k), ekran_pin_anahtar($uid)]);
}

function ekran_pin_hata_logla(Throwable $e): void {
    error_log('[ekran_pin] ' . get_class($e));
}

// 'var' | 'yok' | 'pasif' — istek başına önbellekli; hata → 'pasif' (fail-soft).
function ekran_pin_durum(PDO $pdo, int $uid): string {
    if (ekran_kilit_sir() === '' || $uid <= 0) return 'pasif';
    $o = &ekran_pin_onbellek();
    if (isset($o[$uid])) return $o[$uid];
    try {
        $o[$uid] = ekran_pin_kv_oku($pdo, $uid) !== null ? 'var' : 'yok';
    } catch (Throwable $e) {
        ekran_pin_hata_logla($e);
        $o[$uid] = 'pasif';
    }
    return $o[$uid];
}

function ekran_pin_pasif_sonuc(array $ek = []): array {
    return $ek + ['ok' => false, 'mesaj' => 'PIN özelliği şu an kullanılamıyor.', 'kod' => 'pasif', 'bekle' => 0, 'engelli' => false, 'cikis' => false];
}

function ekran_pin_kur(PDO $pdo, int $uid, string $pin, string $pin2): array {
    $r = fn(bool $ok, string $mesaj, string $kod) => ['ok' => $ok, 'mesaj' => $mesaj, 'kod' => $kod];
    if (ekran_kilit_sir() === '' || $uid <= 0) return $r(false, 'PIN özelliği şu an kullanılamıyor.', 'pasif');
    if (!ekran_pin_gecerli_mi($pin)) return $r(false, 'PIN 4–8 haneli rakamlardan oluşmalı.', 'gecersiz');
    if ($pin !== $pin2) return $r(false, 'PIN\'ler eşleşmiyor.', 'eslesmiyor');

    $tx = false;
    try {
        $pdo->beginTransaction(); $tx = true;
        if (ekran_pin_kv_oku($pdo, $uid, true) !== null) {
            $pdo->rollBack(); $tx = false;
            return $r(false, 'Zaten bir PIN belirlenmiş.', 'var');
        }
        $pdo->prepare('INSERT INTO `' . ekran_pin_kv_tablo() . '` (anahtar, deger) VALUES (?, ?)')->execute([
            ekran_pin_anahtar($uid),
            ekran_pin_kv_json(['h' => ekran_pin_ozet($pin), 't' => time(), 'toplam_hata' => 0, 'bekle_bitis' => 0]),
        ]);
        $pdo->commit(); $tx = false;
        ekran_pin_onbellek_temizle();
        return $r(true, '', '');
    } catch (Throwable $e) {
        if ($tx) { try { $pdo->rollBack(); } catch (Throwable $e2) {} }
        ekran_pin_onbellek_temizle();
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') return $r(false, 'Zaten bir PIN belirlenmiş.', 'var');
        ekran_pin_hata_logla($e);
        return $r(false, 'PIN özelliği şu an kullanılamıyor.', 'pasif');
    }
}

// Ortak deneme çekirdeği: kayıt kilitli okunur, bekleme/yanlış sayacı kalıcı işlenir.
// $dogrula(array $kayit): bool. $basari(array $kayit): array — başarıda yeni kaydı verir (sayaç sıfırlı).
// Dönüş: ['durum'=>'ok'|'yok'|'bekle'|'yanlis'|'cikis'|'pasif', 'bekle'=>int, 'engelli'=>bool]
function ekran_pin_deneme(PDO $pdo, int $uid, int $simdi, callable $dogrula, callable $basari): array {
    $tx = false;
    try {
        $pdo->beginTransaction(); $tx = true;
        $k = ekran_pin_kv_oku($pdo, $uid, true);
        if ($k === null) { $pdo->rollBack(); $tx = false; return ['durum' => 'yok', 'bekle' => 0, 'engelli' => false]; }
        if ($k['bekle_bitis'] > $simdi) {
            $pdo->rollBack(); $tx = false;
            return ['durum' => 'bekle', 'bekle' => $k['bekle_bitis'] - $simdi, 'engelli' => true];
        }
        if ($dogrula($k)) {
            ekran_pin_kv_guncelle($pdo, $uid, $basari($k));
            $pdo->commit(); $tx = false;
            ekran_pin_onbellek_temizle();
            return ['durum' => 'ok', 'bekle' => 0, 'engelli' => false];
        }
        $k['toplam_hata']++;
        if ($k['toplam_hata'] >= EKRAN_PIN_CIKIS_ESIK) {
            $k['toplam_hata'] = 0; $k['bekle_bitis'] = 0;
            ekran_pin_kv_guncelle($pdo, $uid, $k);
            $pdo->commit(); $tx = false;
            return ['durum' => 'cikis', 'bekle' => 0, 'engelli' => false];
        }
        $bekle = EKRAN_PIN_BEKLEME[$k['toplam_hata']] ?? 0;
        if ($bekle > 0) $k['bekle_bitis'] = $simdi + $bekle;
        ekran_pin_kv_guncelle($pdo, $uid, $k);
        $pdo->commit(); $tx = false;
        return $bekle > 0 ? ['durum' => 'bekle', 'bekle' => $bekle, 'engelli' => false] : ['durum' => 'yanlis', 'bekle' => 0, 'engelli' => false];
    } catch (Throwable $e) {
        if ($tx) { try { $pdo->rollBack(); } catch (Throwable $e2) {} }
        ekran_pin_onbellek_temizle();
        ekran_pin_hata_logla($e);
        return ['durum' => 'pasif', 'bekle' => 0, 'engelli' => false];
    }
}

function ekran_pin_bekle_mesaji(int $sn): string {
    return 'Çok fazla hatalı deneme. ' . $sn . ' sn sonra tekrar deneyin.';
}

// Deneme sonucunu uç sözleşmesindeki sonuç dizisine çevirir (başarı çağıran tarafından işlenir).
function ekran_pin_deneme_sonuc(array $d, string $yanlisMesaj): array {
    $b = ['ok' => false, 'mesaj' => '', 'kod' => '', 'bekle' => 0, 'engelli' => false, 'cikis' => false];
    switch ($d['durum']) {
        case 'yok':    return ['mesaj' => 'Önce bir PIN belirlemeniz gerekiyor.', 'kod' => 'yok'] + $b;
        case 'pasif':  return ekran_pin_pasif_sonuc();
        case 'bekle':  return ['mesaj' => ekran_pin_bekle_mesaji((int)$d['bekle']), 'kod' => 'bekle', 'bekle' => (int)$d['bekle'], 'engelli' => (bool)$d['engelli']] + $b;
        case 'cikis':  return ['mesaj' => 'Çok fazla hatalı deneme. Güvenliğiniz için oturum kapatıldı.', 'kod' => 'cikis', 'cikis' => true] + $b;
        default:       return ['mesaj' => $yanlisMesaj, 'kod' => 'yanlis'] + $b;
    }
}

function ekran_kilit_ac(PDO $pdo, int $uid, string $pin, ?int $simdi = null): array {
    $simdi = $simdi ?? time();
    if (ekran_kilit_sir() === '' || $uid <= 0) return ekran_pin_pasif_sonuc();
    if (!ekran_pin_gecerli_mi($pin)) {
        return ['ok' => false, 'mesaj' => 'PIN 4–8 haneli rakamlardan oluşmalı.', 'kod' => 'gecersiz', 'bekle' => 0, 'engelli' => false, 'cikis' => false];
    }
    $girdi = hash_hmac('sha256', $pin, ekran_pin_pepper());
    $d = ekran_pin_deneme($pdo, $uid, $simdi,
        fn(array $k) => password_verify($girdi, $k['h']),
        function (array $k) { $k['toplam_hata'] = 0; $k['bekle_bitis'] = 0; return $k; });
    if ($d['durum'] === 'ok') {
        ekran_kilit_temizle();
        return ['ok' => true, 'mesaj' => '', 'kod' => '', 'bekle' => 0, 'engelli' => false, 'cikis' => false];
    }
    return ekran_pin_deneme_sonuc($d, 'PIN hatalı.');
}

// $g = ['mevcut'?, 'sifre'?, 'pin', 'pin2']. 'sifre' doluysa hesap şifresi yolu, değilse mevcut PIN.
function ekran_pin_degistir(PDO $pdo, int $uid, array $g, ?int $simdi = null): array {
    $simdi = $simdi ?? time();
    $str = fn($a) => is_string($a) ? $a : '';
    $pin = $str($g['pin'] ?? null); $pin2 = $str($g['pin2'] ?? null);
    $mevcut = $str($g['mevcut'] ?? null); $sifre = $str($g['sifre'] ?? null);
    $yontem = $sifre !== '' ? 'sifre' : 'pin';
    $b = ['ok' => false, 'mesaj' => '', 'kod' => '', 'bekle' => 0, 'engelli' => false, 'yontem' => $yontem, 'cikis' => false];

    if (ekran_kilit_sir() === '' || $uid <= 0) return ekran_pin_pasif_sonuc(['yontem' => $yontem]);
    if (!ekran_pin_gecerli_mi($pin)) return ['mesaj' => 'PIN 4–8 haneli rakamlardan oluşmalı.', 'kod' => 'gecersiz'] + $b;
    if ($pin !== $pin2) return ['mesaj' => 'PIN\'ler eşleşmiyor.', 'kod' => 'eslesmiyor'] + $b;
    if ($yontem === 'pin' && !ekran_pin_gecerli_mi($mevcut)) return ['mesaj' => 'PIN hatalı.', 'kod' => 'yanlis'] + $b;

    if ($yontem === 'pin') {
        $girdi = hash_hmac('sha256', $mevcut, ekran_pin_pepper());
        $dogrula = fn(array $k) => password_verify($girdi, $k['h']);
        $yanlis = 'PIN hatalı.';
    } else {
        $dogrula = function (array $k) use ($pdo, $uid, $sifre): bool {
            $st = $pdo->prepare('SELECT password_hash, is_active FROM users WHERE id = ?');
            $st->execute([$uid]);
            $u = $st->fetch();
            return $u && (int)$u['is_active'] === 1 && password_verify($sifre, (string)$u['password_hash']);
        };
        $yanlis = 'Hesap şifresi hatalı.';
    }
    $yeniOzet = ekran_pin_ozet($pin);
    $d = ekran_pin_deneme($pdo, $uid, $simdi, $dogrula,
        fn(array $k) => ['h' => $yeniOzet, 't' => time(), 'toplam_hata' => 0, 'bekle_bitis' => 0]);
    if ($d['durum'] === 'ok') return ['ok' => true] + $b;
    return ekran_pin_deneme_sonuc($d, $yanlis) + ['yontem' => $yontem];
}

function ekran_pin_sifirla(PDO $pdo, int $uid): bool {
    try {
        $pdo->prepare('DELETE FROM `' . ekran_pin_kv_tablo() . '` WHERE anahtar = ?')->execute([ekran_pin_anahtar($uid)]);
        ekran_pin_onbellek_temizle();
        return true;
    } catch (Throwable $e) {
        ekran_pin_hata_logla($e);
        return false;
    }
}

// Başarılı hesap şifresi girişi/doğrulaması sonrası sayacı sıfırlar. Sessiz, fail-soft.
function ekran_pin_sayac_sifirla(PDO $pdo, int $uid): void {
    if (ekran_kilit_sir() === '' || $uid <= 0) return;
    try {
        $k = ekran_pin_kv_oku($pdo, $uid);
        if ($k === null || ($k['toplam_hata'] === 0 && $k['bekle_bitis'] === 0)) return;
        $k['toplam_hata'] = 0; $k['bekle_bitis'] = 0;
        ekran_pin_kv_guncelle($pdo, $uid, $k);
    } catch (Throwable $e) {
        ekran_pin_hata_logla($e);
    }
}

// ── İstek kapısı (csrf_check yerine; PHP oturumuna bağlı DEĞİL) ─────────────────

function ekran_kilit_host_norm(string $hostPort): string {
    $hostPort = strtolower(trim($hostPort));
    return (string)preg_replace('/:(80|443)$/', '', $hostPort);
}

function ekran_kilit_istek_denetle(array $server): ?string {
    $hata = 'Geçersiz istek.';
    if (($server['REQUEST_METHOD'] ?? '') !== 'POST') return $hata;
    if (stripos((string)($server['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) return $hata;
    if (($server['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') return $hata;
    $host = ekran_kilit_host_norm((string)($server['HTTP_HOST'] ?? ''));
    if ($host === '') return $hata;
    $kaynak = (string)($server['HTTP_ORIGIN'] ?? '');
    if ($kaynak === '') $kaynak = (string)($server['HTTP_REFERER'] ?? '');
    if ($kaynak === '') return $hata;
    $p = parse_url($kaynak);
    if (!is_array($p) || empty($p['host'])) return $hata;
    $hp = strtolower((string)$p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
    return ekran_kilit_host_norm($hp) === $host ? null : $hata;
}

// ── İşaretleme ───────────────────────────────────────────────────────────────

function ekran_pin_durum_otomatik(?array $user): string {
    if ($user === null || ekran_kilit_sir() === '') return 'pasif';
    try {
        return ekran_pin_durum(db(), (int)($user['id'] ?? 0));
    } catch (Throwable $e) {
        return 'pasif';
    }
}

function ekran_pin_girdi_html(string $id, string $name, string $etiket): string {
    return '<input type="text" id="' . h($id) . '" name="' . h($name) . '" class="ekran-kilit-girdi ekran-pin-girdi" '
        . 'inputmode="numeric" autocomplete="off" maxlength="8" pattern="[0-9]{4,8}" data-pin-alan="1" '
        . 'autocapitalize="off" spellcheck="false" placeholder="' . h($etiket) . '" aria-label="' . h($etiket) . '">';
}

// Pencereler (render_footer basar). Hepsi KAPALI başlar. 'pasif' / oturumsuz → ''.
function ekran_kilit_html(?array $user, ?string $pinDurum = null, ?bool $kilitli = null): string {
    if ($user === null) return '';
    $durum = $pinDurum ?? ekran_pin_durum_otomatik($user);
    if ($durum !== 'var' && $durum !== 'yok') return '';
    $ad = trim((string)($user['display_name'] ?? '')) !== '' ? (string)$user['display_name'] : (string)($user['username'] ?? '');
    $base = function_exists('base_url') ? base_url() : '';
    $veri = ' data-uc="' . h($base . 'ekran_kilit.php') . '" data-login="' . h($base . 'login.php') . '"';
    $cikis = '<a class="ekran-kilit-cikis" href="' . h($base . 'logout.php') . '">Çıkış yap</a>';

    if ($durum === 'yok') {
        return '<dialog id="ekranPinKur" class="ekran-kilit ekran-pin ekran-pin-kur" aria-labelledby="ekranPinKurBaslik" data-pin="yok"' . $veri . '>'
            . '<form id="ekranPinKurForm" class="ekran-kilit-kart" autocomplete="off" novalidate>'
            . '<div class="ekran-kilit-ikon" aria-hidden="true">🔐</div>'
            . '<h2 id="ekranPinKurBaslik">Ekran kilidi PIN\'i belirleyin</h2>'
            . '<p class="ekran-kilit-not">Hareketsizlikte ekranınız bu PIN ile kilitlenir. PIN basit olabilir (4–8 rakam) ve giriş şifrenizden bağımsızdır.</p>'
            . ekran_pin_girdi_html('ekranPinKurPin', 'ek_a', 'PIN (4–8 rakam)')
            . ekran_pin_girdi_html('ekranPinKurPin2', 'ek_b', 'PIN tekrar')
            . '<div id="ekranPinKurHata" class="ekran-kilit-hata" role="alert" hidden></div>'
            . '<button type="submit" id="ekranPinKurBtn" class="btn btn-primary ekran-kilit-btn">PIN\'i Kaydet</button>'
            . $cikis
            . '</form></dialog>';
    }

    $kilitli = $kilitli ?? ekran_kilitli_mi();
    return '<dialog id="ekranKilit" class="ekran-kilit" aria-labelledby="ekranKilitBaslik" data-pin="var"' . ($kilitli ? ' data-kilitli="1"' : '') . $veri . '>'
        . '<form id="ekranKilitForm" class="ekran-kilit-kart" autocomplete="off" novalidate>'
        . '<div class="ekran-kilit-ikon" aria-hidden="true">🔒</div>'
        . '<h2 id="ekranKilitBaslik">Oturum kilitlendi</h2>'
        . '<p class="ekran-kilit-not">Hareketsizlik nedeniyle ekran kilitlendi. <b>' . h($ad) . '</b> olarak devam etmek için PIN\'inizi girin.</p>'
        . ekran_pin_girdi_html('ekranKilitPin', 'ek_a', 'PIN')
        . '<div id="ekranKilitHata" class="ekran-kilit-hata" role="alert" hidden></div>'
        . '<button type="submit" id="ekranKilitBtn" class="btn btn-primary ekran-kilit-btn">Kilidi Aç</button>'
        . $cikis
        . '</form></dialog>'
        . '<dialog id="ekranPinDegistir" class="ekran-kilit ekran-pin ekran-pin-degistir" aria-labelledby="ekranPinDegistirBaslik" data-pin="var"' . $veri . '>'
        . '<form id="ekranPinDegistirForm" class="ekran-kilit-kart" autocomplete="off" novalidate>'
        . '<div class="ekran-kilit-ikon" aria-hidden="true">🔐</div>'
        . '<h2 id="ekranPinDegistirBaslik">PIN değiştir</h2>'
        . '<div id="ekranPinMevcutKap">' . ekran_pin_girdi_html('ekranPinDegistirMevcut', 'ek_c', 'Mevcut PIN') . '</div>'
        . '<div id="ekranPinSifreKap" hidden>'
        . '<input type="password" id="ekranPinDegistirSifre" name="ek_s" class="ekran-kilit-girdi" autocomplete="new-password" placeholder="Hesap şifresi" aria-label="Hesap şifresi">'
        . '</div>'
        . '<button type="button" id="ekranPinUnuttum" class="ekran-kilit-cikis">PIN\'imi unuttum</button>'
        . ekran_pin_girdi_html('ekranPinDegistirYeni', 'ek_a', 'Yeni PIN (4–8 rakam)')
        . ekran_pin_girdi_html('ekranPinDegistirYeni2', 'ek_b', 'Yeni PIN tekrar')
        . '<div id="ekranPinDegistirHata" class="ekran-kilit-hata" role="alert" hidden></div>'
        . '<button type="submit" id="ekranPinDegistirBtn" class="btn btn-primary ekran-kilit-btn">PIN\'i Değiştir</button>'
        . '<button type="button" id="ekranPinDegistirIptal" class="ekran-kilit-cikis">İptal</button>'
        . '</form></dialog>';
}

// Sidebar'daki "PIN değiştir" düğmesi — yalnız PIN varken.
function ekran_pin_sidebar_dugme_html(?string $pinDurum = null): string {
    $durum = $pinDurum ?? (function_exists('current_user') ? ekran_pin_durum_otomatik(current_user()) : 'pasif');
    if ($durum !== 'var') return '';
    return '<button type="button" id="btnPinDegistir" class="sidebar-user-pin" data-pin-degistir="1" title="PIN değiştir" aria-label="PIN değiştir">'
        . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></button>';
}
