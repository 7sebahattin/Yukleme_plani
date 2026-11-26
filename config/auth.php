<?php
// =========================================================
// config/auth.php — Kimlik doğrulama altyapısı
// Gereksinim: db.php + helpers.php önceden yüklenmiş olmalı
// =========================================================

declare(strict_types=1);

require_once __DIR__ . '/beyan_permissions.php';
require_once __DIR__ . '/role_permission_matrix.php';
require_once __DIR__ . '/role_data_scope.php';
require_once __DIR__ . '/depo_core.php';   // v574: yeni depo kayıt sistemi (geçiş açılana kadar eski davranış sürer)

const SESSION_DURATION_HOURS    = 24;
const SESSION_ABSOLUTE_MAX_DAYS = 14;
const AUTH_COOKIE_NAME          = 'asya_session';
const LOGIN_RL_BEKLEME          = [5 => 30, 6 => 60, 7 => 120, 8 => 300];
const LOGIN_RL_MAX_BEKLEME      = 600;
const LOGIN_RL_PENCERE_SN       = 900;

function &auth_cache_store(): array {
    static $store = [
        'current_user'    => false,
        'permissions'     => [],
        'is_admin'        => [],
        'primary_role'    => [],
        'assigned_depots' => [],
        'depot_options'   => null,
        'active_depot'    => false,
    ];
    return $store;
}

function auth_reset_cache(): void {
    $store = &auth_cache_store();
    $store = [
        'current_user'    => false,
        'permissions'     => [],
        'is_admin'        => [],
        'primary_role'    => [],
        'assigned_depots' => [],
        'depot_options'   => null,
        'active_depot'    => false,
    ];
}

function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    return false;
}

function safe_next_url(string $next): string {
    $next = trim($next);
    if ($next === '' || !preg_match('#^/(?![/\\\\])[^\r\n\t\x00]*$#', $next)) return '';
    $parts = parse_url($next);
    if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])) return '';
    return $next;
}

function auth_cookie_options(): array {
    return [
        'expires'  => time() + SESSION_DURATION_HOURS * 3600,
        'path'     => '/',
        'domain'   => '',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

// ── Giriş hız sınırlama (brute-force koruması, B-16; atomik dosya kilidi, şema gerektirmez) ──

function login_rate_limit_dir(): string {
    if (!empty($GLOBALS['_auth_rl_dir']) && is_string($GLOBALS['_auth_rl_dir'])) {
        $dir = $GLOBALS['_auth_rl_dir'];
    } else {
        $dir = dirname(__DIR__) . '/storage/auth_rl';
    }
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            $dir = rtrim(sys_get_temp_dir(), '/\\') . '/asya_auth_rl';
            if (!is_dir($dir)) @mkdir($dir, 0700, true);
        }
    }
    return $dir;
}

function login_rate_limit_keys(string $username, string $ip): array {
    $normUser = mb_strtolower(trim($username), 'UTF-8');
    $normIp   = trim($ip) !== '' ? trim($ip) : 'unknown';
    $keys = [hash('sha256', 'ip|' . $normIp)];
    if ($normUser !== '') {
        $keys[] = hash('sha256', 'user|' . $normUser . '|' . $normIp);
    }
    return $keys;
}

function login_rate_limit_with_file(string $key, callable $fn) {
    $path = login_rate_limit_dir() . '/' . $key . '.json';
    $fp = @fopen($path, 'c+');
    if ($fp === false) return $fn(['count' => 0, 'until' => 0, 'updated' => 0], false);
    if (!@flock($fp, LOCK_EX)) {
        fclose($fp);
        return $fn(['count' => 0, 'until' => 0, 'updated' => 0], false);
    }
    $raw = stream_get_contents($fp);
    $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($state)) $state = ['count' => 0, 'until' => 0, 'updated' => 0];
    [$result, $newState] = $fn($state, true);
    if ($newState === null) {
        flock($fp, LOCK_UN);
        fclose($fp);
        @unlink($path);
        return $result;
    }
    if (is_array($newState)) {
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, (string)json_encode($newState));
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
}

function login_rate_limit_check(string $username, string $ip, ?int $now = null): array {
    $now = $now ?? time();
    $maxWait = 0;
    foreach (login_rate_limit_keys($username, $ip) as $key) {
        $wait = (int)login_rate_limit_with_file($key, static function (array $st) use ($now): array {
            $updated = (int)($st['updated'] ?? 0);
            $until   = (int)($st['until'] ?? 0);
            if ($until <= $now && $updated > 0 && ($now - $updated) > LOGIN_RL_PENCERE_SN) {
                return [0, null];
            }
            return [max(0, $until - $now), false];
        });
        if ($wait > $maxWait) $maxWait = $wait;
    }
    return ['ok' => $maxWait === 0, 'bekle' => $maxWait];
}

function login_rate_limit_record_failure(string $username, string $ip, ?int $now = null): array {
    $now = $now ?? time();
    $maxWait = 0;
    foreach (login_rate_limit_keys($username, $ip) as $key) {
        $wait = (int)login_rate_limit_with_file($key, static function (array $st) use ($now): array {
            $updated = (int)($st['updated'] ?? 0);
            $count   = (int)($st['count'] ?? 0);
            if ($updated > 0 && ($now - $updated) > LOGIN_RL_PENCERE_SN) {
                $count = 0;
            }
            $count++;
            $bekle = 0;
            if ($count >= 5) {
                $bekle = LOGIN_RL_BEKLEME[$count] ?? LOGIN_RL_MAX_BEKLEME;
            }
            $until = $bekle > 0 ? ($now + $bekle) : 0;
            return [$bekle, ['count' => $count, 'until' => $until, 'updated' => $now]];
        });
        if ($wait > $maxWait) $maxWait = $wait;
    }
    return ['ok' => $maxWait === 0, 'bekle' => $maxWait];
}

function login_rate_limit_reset(string $username, string $ip): void {
    foreach (login_rate_limit_keys($username, $ip) as $key) {
        $path = login_rate_limit_dir() . '/' . $key . '.json';
        if (is_file($path)) @unlink($path);
    }
}

function current_user(): ?array {
    $store = &auth_cache_store();
    if ($store['current_user'] !== false) {
        return ($store['current_user'] === null) ? null : $store['current_user'];
    }

    $token = $_COOKIE[AUTH_COOKIE_NAME] ?? '';
    if ($token === '' || strlen($token) !== 64) {
        $store['current_user'] = null;
        return null;
    }

    try {
        $pdo = db();
        $st  = $pdo->prepare("
            SELECT u.id, u.username, u.email, u.display_name, u.is_active,
                   us.token, us.expires_at, us.created_at, us.last_seen_at
            FROM user_sessions us
            JOIN users u ON u.id = us.user_id
            WHERE us.token = ?
              AND us.expires_at > NOW()
              AND us.created_at >= TIMESTAMPADD(DAY, -?, NOW())
        ");
        $st->execute([$token, SESSION_ABSOLUTE_MAX_DAYS]);
        $row = $st->fetch();

        if (!$row || !(bool)$row['is_active']) {
            $store['current_user'] = null;
            return null;
        }

        // B-42: Sliding expiry yazımını son güncellemeden >300 sn geçtiyse çalıştır (her istekte WRITE engeli)
        $lastSeenTs = !empty($row['last_seen_at']) ? strtotime((string)$row['last_seen_at']) : 0;
        if ($lastSeenTs === 0 || (time() - $lastSeenTs) > 300) {
            $pdo->prepare("UPDATE user_sessions SET last_seen_at = NOW(), expires_at = TIMESTAMPADD(HOUR, ?, NOW()) WHERE token = ?")
                ->execute([SESSION_DURATION_HOURS, $token]);
            setcookie(AUTH_COOKIE_NAME, $token, auth_cookie_options());
            // %1 olasılıkla süresi dolmuş oturumları temizle (GC)
            if (mt_rand(1, 100) === 1) {
                try {
                    $pdo->exec("DELETE FROM user_sessions WHERE expires_at < NOW() LIMIT 500");
                } catch (PDOException $e) {}
            }
        }

        $store['current_user'] = $row;
        return $store['current_user'];
    } catch (PDOException $e) {
        $store['current_user'] = null;
        return null;
    }
}

// Sunucu tarafı ekran kilidi zorlaması (B-17):
// Oturum sunucuda kilitliyse asya_kilit çerezi silinse bile hiçbir sayfa verisi / API dönmez.
function enforce_screen_unlock(): void {
    $user = current_user();
    if ($user === null) return;

    $cur = basename($_SERVER['PHP_SELF'] ?? '');
    if (in_array($cur, ['ekran_kilit.php', 'logout.php', 'login.php'], true)) return;

    if (!function_exists('ekran_kilit_oturum_kilitli_mi')) return;
    if (!ekran_kilit_oturum_kilitli_mi()) return;
    if (function_exists('ekran_pin_durum_otomatik') && ekran_pin_durum_otomatik($user) === 'pasif') return;

    $is_json = (function_exists('is_json_request') && is_json_request())
        || (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET');

    http_response_code(423);
    if (!headers_sent()) header('Cache-Control: no-store');

    if ($is_json) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'     => false,
            'error'  => 'Oturum kilitli. Lütfen ekran kilidini PIN ile açın.',
            'code'   => 'ekran_kilitli',
            'locked' => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Minimal kilitli ekran: hassas sayfa verisi HİÇ çizilmez; PIN açılınca sayfa yenilenir.
    $base  = function_exists('base_url') ? base_url() : '';
    $css_v = file_exists(__DIR__ . '/../assets/style.css') ? (int)filemtime(__DIR__ . '/../assets/style.css') : 0;
    $js_v  = file_exists(__DIR__ . '/../assets/app.js') ? (int)filemtime(__DIR__ . '/../assets/app.js') : 0;
    $dlg   = function_exists('ekran_kilit_html') ? ekran_kilit_html($user, 'var', true) : '';
    $dlg   = str_replace('data-kilitli="1"', 'data-kilitli="1" data-reload="1"', $dlg);
    echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Oturum Kilitli · Asya Fresh</title>'
        . '<link rel="stylesheet" href="' . h($base) . 'assets/style.css?v=' . $css_v . '">'
        . '</head><body class="ekran-kilitli">'
        . $dlg
        . '<script src="' . h($base) . 'assets/app.js?v=' . $js_v . '"></script>'
        . '</body></html>';
    exit;
}

function require_login(): array {
    $user = current_user();
    if ($user === null) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . base_url() . 'login.php' . ($next !== '' ? '?next=' . $next : ''));
        exit;
    }
    enforce_screen_unlock();
    if (function_exists('enforce_active_depot')) enforce_active_depot();
    return $user;
}

function create_session(int $user_id): string {
    $token = bin2hex(random_bytes(32)); // 64 hex karakter
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua    = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

    // TIMESTAMPADD ile MySQL zamanı kullan — PHP/MySQL timezone farkından kaçın
    db()->prepare("
        INSERT INTO user_sessions (token, user_id, ip, user_agent, expires_at, last_seen_at)
        VALUES (?, ?, ?, ?, TIMESTAMPADD(HOUR, ?, NOW()), NOW())
    ")->execute([$token, $user_id, $ip, $ua, SESSION_DURATION_HOURS]);

    return $token;
}

function destroy_session(string $token): void {
    if ($token === '') return;
    if (function_exists('ekran_kilit_sunucu_sil')) {
        ekran_kilit_sunucu_sil(null, $token);
    }
    try {
        db()->prepare("DELETE FROM user_sessions WHERE token = ?")->execute([$token]);
    } catch (PDOException $e) {}
}

function login_user(string $username_or_email, string $password): ?array {
    if ($username_or_email === '' || $password === '') return null;

    try {
        $st = db()->prepare("
            SELECT id, username, email, password_hash, display_name, is_active
            FROM users
            WHERE username = ? OR (email != '' AND email = ?)
            LIMIT 1
        ");
        $st->execute([$username_or_email, $username_or_email]);
        $user = $st->fetch();

        if (!$user) return null;
        if (!(bool)$user['is_active']) return null;
        if (!password_verify($password, $user['password_hash'])) return null;

        db()->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")
            ->execute([$user['id']]);

        return $user;
    } catch (PDOException $e) {
        return null;
    }
}

function logout_user(): void {
    $token = (string)($_COOKIE[AUTH_COOKIE_NAME] ?? '');
    if ($token !== '') {
        destroy_session($token);
    }
    $expired = [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => '',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    @setcookie(AUTH_COOKIE_NAME, '', $expired);
    unset($_COOKIE[AUTH_COOKIE_NAME]);
    if (defined('DEPOT_COOKIE_NAME')) {
        @setcookie(DEPOT_COOKIE_NAME, '', $expired);
        unset($_COOKIE[DEPOT_COOKIE_NAME]);
    }
    auth_reset_cache();
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['csrf'], $_SESSION['sifre_onay']);
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            @session_regenerate_id(true);
        }
    }
}

// ── Yetki Sistemi ─────────────────────────────────────────

function user_permissions(int $user_id): array {
    $store = &auth_cache_store();
    if (isset($store['permissions'][$user_id])) return $store['permissions'][$user_id];
    try {
        $st = db()->prepare("
            SELECT rp.role_id, rp.permission
            FROM user_roles ur
            JOIN role_permissions rp ON rp.role_id = ur.role_id
            WHERE ur.user_id = ?
        ");
        $st->execute([$user_id]);
        $roles = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $roles[(int)$row['role_id']][] = $row['permission'];
        $effective = [];
        foreach ($roles as $permissions) $effective = array_merge($effective, role_matrix_expand_permissions(beyan_expand_role_permissions($permissions)));
        $store['permissions'][$user_id] = array_values(array_unique($effective));
    } catch (PDOException $e) {
        $store['permissions'][$user_id] = [];
    }
    return $store['permissions'][$user_id];
}

function can(string $permission): bool {
    $user = current_user();
    if ($user === null) return false;
    return in_array($permission, user_permissions((int)$user['id']), true);
}

function is_admin(): bool {
    $user = current_user();
    if ($user === null) return false;
    $store = &auth_cache_store();
    $uid = (int)$user['id'];
    if (isset($store['is_admin'][$uid])) return $store['is_admin'][$uid];
    try {
        $st = db()->prepare("
            SELECT 1 FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = ? AND r.slug = 'admin'
            LIMIT 1
        ");
        $st->execute([$uid]);
        return $store['is_admin'][$uid] = (bool)$st->fetchColumn();
    } catch (PDOException $e) {
        return $store['is_admin'][$uid] = false;
    }
}

function user_primary_role(): ?array {
    $user = current_user();
    if ($user === null) return null;
    $store = &auth_cache_store();
    $uid = (int)$user['id'];
    if (array_key_exists($uid, $store['primary_role'])) return $store['primary_role'][$uid];
    try {
        $st = db()->prepare("
            SELECT r.slug, r.label
            FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = ?
            ORDER BY CASE r.slug
                WHEN 'admin'    THEN 1
                WHEN 'operator' THEN 2
                WHEN 'muhasebe' THEN 3
                WHEN 'viewer'   THEN 4
                ELSE 5
            END ASC
            LIMIT 1
        ");
        $st->execute([$uid]);
        $store['primary_role'][$uid] = $st->fetch() ?: null;
    } catch (PDOException $e) {
        $store['primary_role'][$uid] = null;
    }
    return $store['primary_role'][$uid];
}

// ── Yetki Kataloğu (Roller ekranı) ────────────────────────
//
// Modül → [permission => Türkçe etiket]. roles.php buradan checkbox
// grid'i üretir. Değerler config/db.php'deki seed dizileriyle ($all_p /
// $pdks_p) AYNI string'lerdir — db.php migration'ı bilerek burayı
// ÇAĞIRMAZ (o kod yalnızca ilk kurulumda 5 varsayılan role başlangıç
// yetkisi vermek için bir kere çalışır; migration'a GO olmadan dokunma
// kuralı gereği ayrıştırılmadı). YENİ BİR YETKİ EKLERSEN üç yeri birden
// güncelle: ① burası (görünür ad) ② ilgili sayfadaki can('...') çağrısı
// (asıl kapı) ③ db.php'deki seed dizisi (yalnız varsayılan rollerin
// başlangıç değeri — burada olmayan bir yetki role_permissions'a elle
// eklenebilir ve can() yine çalışır, ama roles.php'de GÖRÜNMEZ).
function permission_catalog(): array {
    $catalog = [
        'Operasyon' => [
            'dashboard.read' => 'Ana sayfayı görüntüle',
            'records.read'   => 'Yüklemeleri / çıkmaları görüntüle',
            'records.write'  => 'Yükleme / çıkma oluştur ve düzenle',
            'records.delete' => 'Yükleme / çıkma sil',
            'records.lock'   => 'Kaydı kilitle (yüklendi durumuna al)',
            'records.unlock' => 'Kilitli kaydın kilidini aç (revizyon)',
        ],
        'Beyanlar' => [
            'beyan.read'   => 'Beyanları görüntüle',
            'beyan.write'  => 'Beyan oluştur / düzenle / bildirim yap',
            'beyan.delete' => 'Beyan sil',
            'beyan.yukleme_bildir' => 'Yükleme Bildir: beyansız yükleme için HKS taslağı oluştur (Hal Kayıt\'ta göndermek için ayrıca "Hal Bildirimi erişimi" gerekir)',
            'beyan.yukleme_sil' => 'Yükleme Bildir sil: hatalı/fazla beyansız yükleme kaydını sil (bekleyen Hal Kayıt taslağı da silinir; gönderilmiş olan silinemez)',
            'beyan.permissions.detailed' => 'Ayrıntılı yetkilendirme (eski toplu yazma yetkisini sınırlar)',
            'beyan.create' => 'Beyan oluştur / kopyala / toplu içe aktar',
            'beyan.edit' => 'Temel beyan bilgilerini düzenle',
            'beyan.status' => 'Beyan durumunu değiştir',
            'beyan.match' => 'Yükleme planıyla eşleştir (yükleme düzenleme yetkisi gerekir)',
            'beyan.notify' => 'Hal bildirim taslağı / bağını yönet (yükleme düzenleme yetkisi gerekir)',
            'beyan.analysis.read' => 'Analiz ve numune bilgilerini gör',
            'beyan.analysis.write' => 'Analiz ve numune bilgilerini düzenle',
            'beyan.contact.read' => 'Adres, ilgili kişi ve alıcıyı gör',
            'beyan.contact.write' => 'Adres, ilgili kişi ve alıcıyı düzenle',
            'beyan.hks.read' => 'HKS eşleştirme ve bildirim bilgilerini gör',
            'beyan.hks.write' => 'HKS eşleştirme bilgilerini düzenle',
            'beyan.source.read' => 'Ham metni gör (tüm kaynak bilgilerini içerir)',
            'beyan.source.write' => 'Ham metni düzenle / ayrıştır',
        ],
        'Siparişler' => [
            'siparis.read'   => 'Siparişleri, belgeleri ve Packing List\'i görüntüle / indir',
            'siparis.write'  => 'Belge yükle, alan düzelt, durum değiştir, PKL oluştur, "mail gönderildi" işaretle',
            'siparis.delete' => 'Sevkiyat / belge sil, PKL sayaçlarını düzelt, profil sil',
            'siparis.match'  => 'Siparişi beyanla eşleştir / bağı kaldır (beyan görüntüleme yetkisi de gerekir)',
        ],
        'Kantar' => [
            'kantar.read'   => 'Kantar kayıtlarını görüntüle',
            'kantar.write'  => 'Kantar kaydı oluştur / düzenle',
            'kantar.delete' => 'Kantar kaydı sil',
        ],
        'Malzeme Stok' => [
            'stok.read'  => 'Malzeme stoğunu görüntüle',
            'stok.write' => 'Stok hareketi (giriş / çıkış) oluştur',
        ],
        'Raporlar' => [
            'reports.read'   => 'Raporları görüntüle',
            'reports.export' => 'Raporları dışa aktar (Excel / CSV)',
        ],
        'Maliyet' => [
            'maliyet.read'   => 'Maliyet hesaplarını görüntüle',
            'maliyet.write'  => 'Maliyet hesabı oluştur / düzenle',
            'maliyet.delete' => 'Maliyet hesabı sil',
            'maliyet.unlock' => 'Kesinleşmiş hesabın kilidini aç',
            'maliyet.admin'  => 'Alan / şablon / ambalaj tanımlarını yönet',
        ],
        'Hızlı Maliyet' => [
            'hizli_maliyet.read'   => 'Hızlı Maliyet kayıtlarını görüntüle (eski Maliyet izinlerinden bağımsızdır)',
            'hizli_maliyet.write'  => 'Hızlı Maliyet hesabı oluştur / düzenle / kopyala',
            'hizli_maliyet.delete' => 'Hızlı Maliyet kaydı sil',
        ],
        'Günlük Üretim' => [
            'uretim.read'   => 'Günlük Üretim raporunu görüntüle (işlenen mal, işçi başı verim)',
            'uretim.write'  => 'Günlük üretim verisi gir (Excel / toplu / elle)',
            'uretim.delete' => 'Günlük üretim kaydı sil',
        ],
        'Hesap' => [
            'hesap.read'    => 'Hesabım — yalnız KENDİ hesap kayıtlarını görüntüle',
            'hesap.write'   => 'Kendi masraf kaydını oluştur / düzenle (onaylanana kadar)',
            'hesap.delete'  => 'Kendi kaydını sil (onaylanana kadar)',
            'hesap.approve' => 'Görebildiği kayıtları onayla / reddet (başkasının kaydını GÖSTERMEZ)',
            'hesap.pay'     => 'Görebildiği kayıtları ödendi işaretle (başkasının kaydını GÖSTERMEZ)',
            'hesap.admin'   => 'Hesap yöneticisi — TÜM personelin hesabını görür, onaylar/öder, kayıt sahibini değiştirir, ödenmiş kaydı açar',
        ],
        'Mail Merkezi' => [
            'mail.read'  => 'Mail — atandığı hesapların gelen kutusunu ve çevirileri görüntüle',
            'mail.reply' => 'Mail — Türkçe cevap taslağı yaz ve çeviri önizle (göndermez)',
            'mail.send'  => 'Mail — çeviriyi onayla ve GÖNDER',
            'mail.admin' => 'Mail yöneticisi — hesap, şifre, kullanıcı atama, senkron günlüğü (tüm hesapları görür)',
        ],
        'Personel Takibi' => [
            'attendance.read'               => 'Devam kayıtlarını görüntüle',
            'attendance.scan'               => 'Kapı / kart okuyucu ile tarama yap',
            'attendance.manual'             => 'Manuel giriş-çıkış kaydı gir',
            'attendance.correct'            => 'Giriş-çıkış kaydını düzelt',
            'attendance.report'             => 'Devam raporlarını görüntüle',
            'attendance.employees'          => 'Personel kartoteksini yönet',
            'attendance.cards'              => 'Personel kart zimmetini yönet',
            'attendance.devices'            => 'Kapı / kart okuyucu cihazlarını yönet',
            'attendance.admin'              => 'PDKS modülü gelişmiş yönetimi',
            'attendance.foremen'            => 'Çavuşları yönet',
            'attendance.worker_cards'       => 'Günlük işçi kart havuzunu yönet',
            'attendance.daily_scan'         => 'Günlük işçi giriş-çıkış taraması yap',
            'attendance.daily_reports'      => 'Günlük işçi puantaj raporlarını görüntüle',
            'attendance.foreman_rates'      => 'Çavuş günlük ücretlerini yönet',
            'attendance.entitlements'       => 'Hakediş görüntüle / hesapla',
            'attendance.foreman_accounts'   => 'Çavuş cari hesap / ekstresini görüntüle',
            'attendance.foreman_payments'   => 'Çavuş ödemesi kaydet / iptal et',
            'attendance.management_reports' => 'Personel yönetim raporlarını görüntüle',
        ],
        'Tanımlar' => [
            'defs.read'  => 'Tanımları görüntüle',
            'defs.write' => 'Tanım ekle / düzenle',
            'defs.admin' => 'Gelişmiş tanım yönetimi (silme, depo eşitleme vb.)',
        ],
        'Kullanıcılar ve Roller' => [
            'users.read'  => 'Kullanıcı listesini görüntüle',
            'users.write' => 'Kullanıcı bilgisi düzenle',
            'users.admin' => 'Kullanıcı ve rol yönetimi (oluştur / sil / şifre sıfırla / yetkilendirme)',
        ],
    ];
    $catalog['Sayfa ve işlem izinleri'] = role_matrix_catalog_additions();
    return $catalog;
}

// Kuruluşta seed edilen 5 varsayılan rol. Kodun başka yerleri slug'a göre
// davranış değiştiriyor (is_admin() 'admin' slug'ını arar, users.php rozet
// rengini slug'a göre seçer) — bu yüzden roles.php bu beşinin SİLİNMESİNE
// izin vermez. Etiket ve yetkileri yine de serbestçe düzenlenebilir.
function protected_role_slugs(): array {
    return ['admin', 'operator', 'viewer', 'muhasebe', 'ik'];
}

// Verilen yetkiye sahip en az bir AKTİF kullanıcı var mı? Rol yetkilerini
// kaydetmeden önce "users.admin sistemden tamamen kaybolmasın" kilidi
// için kullanılır — aksi halde bir admin kendi rolünden bu yetkiyi
// kaldırıp sistemi kilitleyebilir (kimse rol/kullanıcı yönetemez).
function any_active_user_has_permission(string $permission): bool {
    try {
        $st = db()->prepare("
            SELECT 1
            FROM users u
            JOIN user_roles ur ON ur.user_id = u.id
            JOIN role_permissions rp ON rp.role_id = ur.role_id
            WHERE u.is_active = 1 AND rp.permission = ?
            LIMIT 1
        ");
        $st->execute([$permission]);
        return (bool)$st->fetchColumn();
    } catch (PDOException $e) {
        // fail-open: sorgu hatasında kaydı engelleme (mevcut depo/yetki
        // yardımcılarındaki fail-open deseniyle aynı ilke)
        return true;
    }
}

function forbidden(string $msg = 'Bu sayfaya erişim yetkiniz yok.'): void {
    http_response_code(403);

    $is_ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');

    if ($is_ajax) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $msg, 'code' => 403]);
        exit;
    }

    $base  = function_exists('base_url') ? base_url() : '';
    $css_v = file_exists(__DIR__ . '/../assets/style.css') ? filemtime(__DIR__ . '/../assets/style.css') : 0;
    $msg_h = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    $csrf_h = function_exists('csrf_token') ? htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') : '';
    echo "<!doctype html><html lang='tr'><head>
<meta charset='utf-8'>
<meta name='viewport' content='width=device-width,initial-scale=1'>
<title>Erişim Reddedildi · Asya Fresh</title>
<link rel='stylesheet' href='{$base}assets/style.css?v={$css_v}'>
<style>
  body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f0f4f8}
  .fb{text-align:center;padding:40px 24px}
  .fb-code{font-size:5rem;font-weight:900;color:#e2e8f0;line-height:1;margin-bottom:8px}
  .fb-title{font-size:1.4rem;font-weight:700;color:#1e293b;margin-bottom:8px}
  .fb-msg{color:#64748b;margin-bottom:28px;font-size:.95rem}
  .fb-actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
</style>
</head><body>
<div class='fb'>
  <div class='fb-code'>403</div>
  <div class='fb-title'>Erişim Reddedildi</div>
  <div class='fb-msg'>{$msg_h}</div>
  <div class='fb-actions'>
    <a href='{$base}index.php' class='btn btn-primary'>Ana Sayfa</a>
    <form method='post' action='{$base}logout.php' style='display:inline;margin:0'>
      <input type='hidden' name='csrf' value='{$csrf_h}'>
      <button type='submit' class='btn btn-ghost'>Çıkış Yap</button>
    </form>
  </div>
</div>
</body></html>";
    exit;
}

// ── Depo Sorumluluğu (çoklu depo) ─────────────────────────
//
// Kural (DB hatasında fail-closed, B-38):
//   • Admin            → null  (kısıtsız, tüm depolar)
//   • Atama YOK         → null  (kısıtsız — mevcut kullanıcılar etkilenmez)
//   • Atama VAR         → ['Depo A', 'Depo B', ...] (yalnız bunları görür)
//   • DB okuma hatası   → []    (fail-closed: hata anında kapsam genişlemez)
//
// null döndüğünde çağıran taraf HİÇ filtre uygulamaz (her şeyi gösterir).
function user_assigned_depots(?int $user_id = null): ?array {
    $store = &auth_cache_store();

    if ($user_id === null) {
        $u = current_user();
        if ($u === null) return [];        // giriş yok → hiçbir şey
        $user_id = (int)$u['id'];
    }
    if (array_key_exists($user_id, $store['assigned_depots'])) return $store['assigned_depots'][$user_id];

    $roleScope = role_user_depot_scope($user_id);

    // Admin → kısıtsız
    try {
        $st = db()->prepare("
            SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = ? AND r.slug = 'admin' LIMIT 1
        ");
        $st->execute([$user_id]);
        if ($st->fetchColumn()) { return $store['assigned_depots'][$user_id] = null; }
    } catch (PDOException $e) { return $store['assigned_depots'][$user_id] = []; }

    // v574: yeni depo sistemi açıksa atama `depo_kullanicilar` (ID) tablosundan okunur; eski `user_depolar` ad tablosu YOK SAYILIR.
    if (depo_gecis_aktif(db())) {
        return $store['assigned_depots'][$user_id] = role_depot_scope_intersect(depo_atanan_adlar(db(), $user_id), $roleScope);
    }
    try {
        $st = db()->prepare("SELECT depo FROM user_depolar WHERE user_id = ?");
        $st->execute([$user_id]);
        $rows = array_values(array_filter(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)), fn($d) => $d !== ''));
        // Atama yoksa kısıtsız (null); varsa o listeyle sınırlı
        return $store['assigned_depots'][$user_id] = role_depot_scope_intersect((count($rows) === 0) ? null : $rows, $roleScope);
    } catch (PDOException $e) {
        // Fail-closed: DB okuma hatasında kapsam genişletilmez.
        return $store['assigned_depots'][$user_id] = [];
    }
}

// ── Aktif Depo (oturum bazlı tek depo kapsamı) ────────────
//
// Kullanıcı girişten sonra TEK bir depo seçer (zorunlu). Tüm listeleme,
// stok, kantar ve rapor sorguları bu depoya daraltılır. Depo değiştirme
// her ekrandan depo_sec.php ile yapılır. Saklama: cookie (DB migration yok).

const DEPOT_COOKIE_NAME = 'asya_depo';

// Kullanıcının seçebileceği depolar: Tanımlar (type='depo', aktif)
// ∩ user_depolar ataması (varsa). Atanan ama tanımda olmayan depo da listelenir
// (eski serbest-metin veriler kaybolmasın).
function depot_options(): array {
    $store = &auth_cache_store();
    if ($store['depot_options'] !== null) return $store['depot_options'];

    // v574: yeni depo sistemi açıksa seçenekler YALNIZ `depolar` (aktif) kaydından gelir; eski tanımlar/serbest metin görünmez.
    if (depo_gecis_aktif(db())) {
        $defs = depo_aktif_adlar(db());
        $assigned = user_assigned_depots();
        if ($assigned !== null) $defs = array_values(array_filter($defs, fn($d) => in_array($d, $assigned, true)));
        return $store['depot_options'] = $defs;
    }

    $defs = [];
    try {
        $st = db()->query("SELECT name FROM material_definitions WHERE type = 'depo' AND is_active = 1 ORDER BY name");
        $defs = array_values(array_filter(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)), fn($d) => trim($d) !== ''));
    } catch (PDOException $e) { /* tanım tablosu yoksa boş liste */ }

    $assigned = user_assigned_depots();
    if ($assigned !== null) {
        $defs = array_values(array_filter($defs, fn($d) => in_array($d, $assigned, true)));
        foreach ($assigned as $a) {
            if ($a !== '' && !in_array($a, $defs, true)) $defs[] = $a;
        }
    }
    return $store['depot_options'] = $defs;
}

// Geçerli aktif depo — cookie'den okunur, seçilebilir listeye göre doğrulanır.
// null → henüz seçilmemiş / geçersiz (require_login depo seçimine yönlendirir).
function active_depot(): ?string {
    $store = &auth_cache_store();
    if ($store['active_depot'] !== false) return $store['active_depot'];

    if (current_user() === null) return $store['active_depot'] = null;

    $raw = trim((string)($_COOKIE[DEPOT_COOKIE_NAME] ?? ''));
    if ($raw === '' || mb_strlen($raw) > 150) return $store['active_depot'] = null;
    if (!in_array($raw, depot_options(), true)) return $store['active_depot'] = null;
    return $store['active_depot'] = $raw;
}

function set_active_depot(string $depo): void {
    $opts = auth_cookie_options();
    $opts['expires'] = time() + 180 * 24 * 3600; // 180 gün — depo tercihi kalıcı
    setcookie(DEPOT_COOKIE_NAME, $depo, $opts);
    $_COOKIE[DEPOT_COOKIE_NAME] = $depo; // aynı istek içinde de geçerli olsun
    $store = &auth_cache_store();
    $store['active_depot'] = $depo;
}

// Aktif depo seçilmeden hiçbir sayfa açılmaz (zorunlu tek depo).
// Depo seçim/giriş/çıkış sayfaları hariç. JSON isteklerde 403+JSON döner.
function enforce_active_depot(): void {
    if (current_user() === null) return; // login kontrolü çağıran tarafta
    if (active_depot() !== null) return;

    $cur = basename($_SERVER['PHP_SELF'] ?? '');
    if (in_array($cur, ['depo_sec.php', 'login.php', 'logout.php'], true)) return;

    $is_json = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
    if ($is_json) {
        http_response_code(403);
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Önce depo seçmelisiniz.', 'code' => 'depo_secilmedi']);
        exit;
    }

    $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
    header('Location: ' . (function_exists('base_url') ? base_url() : '') . 'depo_sec.php' . ($next !== '' ? '?next=' . $next : ''));
    exit;
}

// user_allowed_depots — TÜM depo filtrelerinin girdiği tek kapı.
// Aktif depo seçiliyse her sorgu o depoya daraltılır; seçim yoksa
// (henüz depo_sec'e yönlenmemiş uç durumlar) eski atama davranışı sürer.
function user_allowed_depots(?int $user_id = null): ?array {
    $u = current_user();
    if ($u !== null && ($user_id === null || $user_id === (int)$u['id'])) {
        $act = active_depot();
        if ($act !== null) return [$act];
    }
    return user_assigned_depots($user_id);
}

// Depo adı karşılaştırma için TR-duyarsız katlama — MySQL utf8mb4_unicode_ci
// (liste sorguları) ile tekil sayfa PHP kontrollerini tutarlı kılar.
// "KARAMAN CİHAT" == "Karaman Cihat" eşleşir.
function depo_fold(string $s): string {
    $s = strtr($s, [
        'İ'=>'i','I'=>'i','Ş'=>'s','Ğ'=>'g','Ü'=>'u','Ö'=>'o','Ç'=>'c',
        'ı'=>'i','ş'=>'s','ğ'=>'g','ü'=>'u','ö'=>'o','ç'=>'c',
    ]);
    return mb_strtolower(trim($s), 'UTF-8');
}

// Bir depo, verilen izinli depo listesinde var mı? (TR-duyarsız)
function depo_in_allowed(string $depo, array $allowed): bool {
    $d = depo_fold($depo);
    foreach ($allowed as $a) {
        if (depo_fold((string)$a) === $d) return true;
    }
    return false;
}

// Belirli bir depo, geçerli kullanıcı için görünür mü?
// Kısıtsız (admin/atama yok) → true · Atanmamış (boş) → true (her depoda görünür)
// · Aksi halde TR-duyarsız eşleşme.
function depot_visible_to_user(?string $depo): bool {
    $allowed = user_allowed_depots();
    if ($allowed === null) return true;                 // kısıtsız
    if ($allowed === []) return false;
    if (role_current_depot_scope_restricted() && ($depo === null || trim($depo) === "")) return false;
    if ($depo === null || trim($depo) === '') return true; // atanmamış → her depoda
    return depo_in_allowed($depo, $allowed);
}

// ── Atanmamış veri kuralı ─────────────────────────────────
// Deposu BOŞ (henüz atanmamış) kayıtlar TÜM depolarda görünür ve erişilebilir
// kalır — depo özelliği yüzünden hiçbir eski veri kaybolmaz/kilitlenmez.
// Bir depoya atanınca (kayıt düzenleme) yalnız o depoda görünür.
// Aşağıdaki filtreler bu nedenle "IN (aktif depo) VEYA depo boş" kurar.

// loading_records'ı palet deposuna göre kapsayan WHERE parçası döndürür.
// NAMED parametre üretir ($prefix0, $prefix1 ...) — named-param sorgularla birlikte kullanılır.
// Dönen: [' AND (EXISTS(...) OR atanmamış) ', [':udp0'=>'Depo', ...]]  — kısıtsızsa ['', []].
function depo_sql_records(string $records_alias = 'r', string $prefix = ':udp'): array {
    $allowed = user_allowed_depots();
    if ($allowed === null) return ['', []];
    if ($allowed === []) return [' AND 1=0 ', []];
    $names = []; $params = [];
    foreach (array_values($allowed) as $i => $d) { $k = $prefix . $i; $names[] = $k; $params[$k] = $d; }
    $sql = " AND (EXISTS (SELECT 1 FROM loading_pallets _udp
                          WHERE _udp.loading_record_id = {$records_alias}.id
                          AND _udp.depo IN (" . implode(',', $names) . "))
                  OR NOT EXISTS (SELECT 1 FROM loading_pallets _udpu
                                 WHERE _udpu.loading_record_id = {$records_alias}.id
                                 AND TRIM(COALESCE(_udpu.depo,'')) <> '')) ";
    if (role_current_depot_scope_restricted()) {
        $sql = " AND EXISTS (SELECT 1 FROM loading_pallets _udp_any WHERE _udp_any.loading_record_id = {$records_alias}.id) AND NOT EXISTS (SELECT 1 FROM loading_pallets _udp WHERE _udp.loading_record_id = {$records_alias}.id AND (TRIM(COALESCE(_udp.depo,'')) = '' OR _udp.depo NOT IN (" . implode(',', $names) . "))) ";
    }
    return [$sql, $params];
}

// depo kolonu OLAN tablolar için POZİSYONEL (?) parametreli WHERE parçası.
// Pozisyonel sorgu kuran sayfalarda (stok.php vb.) kullanılır.
// Dönen: ['(col IN (?,?) OR col boş)', ['Depo A','Depo B']] — kısıtsızsa ['', []].
function depo_sql_in(string $col): array {
    $allowed = user_allowed_depots();
    if ($allowed === null) return ['', []];
    if ($allowed === []) return ['1=0', []];
    $ph = implode(',', array_fill(0, count($allowed), '?'));
    if (role_current_depot_scope_restricted()) return ["$col IN ($ph)", array_values($allowed)];
    return ["($col IN ($ph) OR TRIM(COALESCE($col,'')) = '')", array_values($allowed)];
}

// loading_records için POZİSYONEL (?) parametreli EXISTS parçası
// (palet deposu üzerinden kapsar). Pozisyonel sorgu kuran sayfalarda kullanılır.
// Dönen: ['(EXISTS(...) OR atanmamış)', ['Depo A']] — kısıtsızsa ['', []].
function depo_sql_records_in(string $records_ref = 'loading_records'): array {
    $allowed = user_allowed_depots();
    if ($allowed === null) return ['', []];
    if ($allowed === []) return ['1=0', []];
    $ph = implode(',', array_fill(0, count($allowed), '?'));
    if (role_current_depot_scope_restricted()) return ["EXISTS (SELECT 1 FROM loading_pallets _udpi_any WHERE _udpi_any.loading_record_id = {$records_ref}.id) AND NOT EXISTS (SELECT 1 FROM loading_pallets _udpi WHERE _udpi.loading_record_id = {$records_ref}.id AND (TRIM(COALESCE(_udpi.depo,'')) = '' OR _udpi.depo NOT IN ($ph)))", array_values($allowed)];
    return ["(EXISTS (SELECT 1 FROM loading_pallets _udpi
              WHERE _udpi.loading_record_id = {$records_ref}.id
              AND _udpi.depo IN ($ph))
             OR NOT EXISTS (SELECT 1 FROM loading_pallets _udpiu
              WHERE _udpiu.loading_record_id = {$records_ref}.id
              AND TRIM(COALESCE(_udpiu.depo,'')) <> ''))", array_values($allowed)];
}

// depo kolonu OLAN tablolar (kantar vb.) için NAMED parametreli WHERE parçası.
// Dönen: [' AND (col IN (...) OR col boş) ', [':udc0'=>'Depo', ...]]  — kısıtsızsa ['', []].
function depo_sql_column(string $col, string $prefix = ':udc'): array {
    $allowed = user_allowed_depots();
    if ($allowed === null) return ['', []];
    if ($allowed === []) return [' AND 1=0 ', []];
    $names = []; $params = [];
    foreach (array_values($allowed) as $i => $d) { $k = $prefix . $i; $names[] = $k; $params[$k] = $d; }
    if (role_current_depot_scope_restricted()) return [" AND $col IN (" . implode(',', $names) . ") ", $params];
    return [" AND ($col IN (" . implode(',', $names) . ") OR TRIM(COALESCE($col,'')) = '') ", $params];
}

function require_perm(string $permission): void {
    if (current_user() === null) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . (function_exists('base_url') ? base_url() : '') . 'login.php' . ($next ? '?next=' . $next : ''));
        exit;
    }
    enforce_screen_unlock();
    enforce_active_depot();
    if (!can($permission)) {
        forbidden("Bu sayfaya erişim yetkiniz yok. (Gerekli yetki: {$permission})");
    }
}
