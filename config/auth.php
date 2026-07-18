<?php
// =========================================================
// config/auth.php — Kimlik doğrulama altyapısı
// Gereksinim: db.php + helpers.php önceden yüklenmiş olmalı
// =========================================================

declare(strict_types=1);

const SESSION_DURATION_HOURS = 24;
const AUTH_COOKIE_NAME       = 'asya_session';

function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    return false;
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

function current_user(): ?array {
    static $cache = false;
    if ($cache !== false) return ($cache === null) ? null : $cache;

    $token = $_COOKIE[AUTH_COOKIE_NAME] ?? '';
    if ($token === '' || strlen($token) !== 64) {
        $cache = null;
        return null;
    }

    try {
        $pdo = db();
        $st  = $pdo->prepare("
            SELECT u.id, u.username, u.email, u.display_name, u.is_active,
                   us.token, us.expires_at
            FROM user_sessions us
            JOIN users u ON u.id = us.user_id
            WHERE us.token = ? AND us.expires_at > NOW()
        ");
        $st->execute([$token]);
        $row = $st->fetch();

        if (!$row || !(bool)$row['is_active']) {
            $cache = null;
            return null;
        }

        // Sliding expiry: MySQL NOW() kullan — PHP timezone uyumsuzluğunu önler
        $pdo->prepare("UPDATE user_sessions SET last_seen_at = NOW(), expires_at = TIMESTAMPADD(HOUR, ?, NOW()) WHERE token = ?")
            ->execute([SESSION_DURATION_HOURS, $token]);

        // Cookie süresini de yenile
        setcookie(AUTH_COOKIE_NAME, $token, auth_cookie_options());

        $cache = $row;
        return $cache;
    } catch (PDOException $e) {
        $cache = null;
        return null;
    }
}

function require_login(): array {
    $user = current_user();
    if ($user === null) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . base_url() . 'login.php' . ($next !== '' ? '?next=' . $next : ''));
        exit;
    }
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
    $token = $_COOKIE[AUTH_COOKIE_NAME] ?? '';
    if ($token !== '') {
        destroy_session($token);
    }
    setcookie(AUTH_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => '',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    // Static cache temizle
    // (Bir sonraki current_user() çağrısında session bulunamayacak)
}

// ── Yetki Sistemi ─────────────────────────────────────────

function user_permissions(int $user_id): array {
    static $cache = [];
    if (isset($cache[$user_id])) return $cache[$user_id];
    try {
        $st = db()->prepare("
            SELECT DISTINCT rp.permission
            FROM user_roles ur
            JOIN role_permissions rp ON rp.role_id = ur.role_id
            WHERE ur.user_id = ?
        ");
        $st->execute([$user_id]);
        $cache[$user_id] = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $cache[$user_id] = [];
    }
    return $cache[$user_id];
}

function can(string $permission): bool {
    $user = current_user();
    if ($user === null) return false;
    return in_array($permission, user_permissions((int)$user['id']), true);
}

function is_admin(): bool {
    $user = current_user();
    if ($user === null) return false;
    try {
        $st = db()->prepare("
            SELECT 1 FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = ? AND r.slug = 'admin'
            LIMIT 1
        ");
        $st->execute([(int)$user['id']]);
        return (bool)$st->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function user_primary_role(): ?array {
    $user = current_user();
    if ($user === null) return null;
    static $role_cache = [];
    $uid = (int)$user['id'];
    if (isset($role_cache[$uid])) return $role_cache[$uid];
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
        $role_cache[$uid] = $st->fetch() ?: null;
    } catch (PDOException $e) {
        $role_cache[$uid] = null;
    }
    return $role_cache[$uid];
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
    <a href='{$base}logout.php' class='btn btn-ghost'>Çıkış Yap</a>
  </div>
</div>
</body></html>";
    exit;
}

// ── Depo Sorumluluğu (çoklu depo) ─────────────────────────
//
// Kural (fail-open):
//   • Admin            → null  (kısıtsız, tüm depolar)
//   • Atama YOK         → null  (kısıtsız — mevcut kullanıcılar etkilenmez)
//   • Atama VAR         → ['Depo A', 'Depo B', ...] (yalnız bunları görür)
//   • Tablo yok / hata  → null  (fail-open: kimseyi kilitleme)
//
// null döndüğünde çağıran taraf HİÇ filtre uygulamaz (her şeyi gösterir).
function user_assigned_depots(?int $user_id = null): ?array {
    static $cache = [];

    if ($user_id === null) {
        $u = current_user();
        if ($u === null) return [];        // giriş yok → hiçbir şey
        $user_id = (int)$u['id'];
    }
    if (array_key_exists($user_id, $cache)) return $cache[$user_id];

    // Admin → kısıtsız
    try {
        $st = db()->prepare("
            SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = ? AND r.slug = 'admin' LIMIT 1
        ");
        $st->execute([$user_id]);
        if ($st->fetchColumn()) { return $cache[$user_id] = null; }
    } catch (PDOException $e) { return $cache[$user_id] = null; }

    try {
        $st = db()->prepare("SELECT depo FROM user_depolar WHERE user_id = ?");
        $st->execute([$user_id]);
        $rows = array_values(array_filter(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)), fn($d) => $d !== ''));
        // Atama yoksa kısıtsız (null); varsa o listeyle sınırlı
        return $cache[$user_id] = (count($rows) === 0) ? null : $rows;
    } catch (PDOException $e) {
        // user_depolar henüz yoksa veya hata → fail-open
        return $cache[$user_id] = null;
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
    static $cache = null;
    if ($cache !== null) return $cache;

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
    return $cache = $defs;
}

// Geçerli aktif depo — cookie'den okunur, seçilebilir listeye göre doğrulanır.
// null → henüz seçilmemiş / geçersiz (require_login depo seçimine yönlendirir).
function active_depot(): ?string {
    static $cache = false;
    if ($cache !== false) return $cache;

    if (current_user() === null) return $cache = null;

    $raw = trim((string)($_COOKIE[DEPOT_COOKIE_NAME] ?? ''));
    if ($raw === '' || mb_strlen($raw) > 150) return $cache = null;
    if (!in_array($raw, depot_options(), true)) return $cache = null;
    return $cache = $raw;
}

function set_active_depot(string $depo): void {
    $opts = auth_cookie_options();
    $opts['expires'] = time() + 180 * 24 * 3600; // 180 gün — depo tercihi kalıcı
    setcookie(DEPOT_COOKIE_NAME, $depo, $opts);
    $_COOKIE[DEPOT_COOKIE_NAME] = $depo; // aynı istek içinde de geçerli olsun
}

function clear_active_depot(): void {
    $opts = auth_cookie_options();
    $opts['expires'] = time() - 3600;
    setcookie(DEPOT_COOKIE_NAME, '', $opts);
    unset($_COOKIE[DEPOT_COOKIE_NAME]);
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

function user_is_depot_restricted(): bool {
    return user_allowed_depots() !== null;
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
    if ($depo === null || trim($depo) === '') return true; // atanmamış → her depoda
    return depo_in_allowed($depo, $allowed);
}

// ── Atanmamış veri kuralı ─────────────────────────────────
// Deposu BOŞ (henüz atanmamış) kayıtlar TÜM depolarda görünür ve erişilebilir
// kalır — depo özelliği yüzünden hiçbir eski veri kaybolmaz/kilitlenmez.
// Bir depoya atanınca (kayıt düzenleme veya Depo Taşıma) yalnız o depoda görünür.
// Aşağıdaki filtreler bu nedenle "IN (aktif depo) VEYA depo boş" kurar.

// loading_records'ı palet deposuna göre kapsayan WHERE parçası döndürür.
// NAMED parametre üretir ($prefix0, $prefix1 ...) — named-param sorgularla birlikte kullanılır.
// Dönen: [' AND (EXISTS(...) OR atanmamış) ', [':udp0'=>'Depo', ...]]  — kısıtsızsa ['', []].
function depo_sql_records(string $records_alias = 'r', string $prefix = ':udp'): array {
    $allowed = user_allowed_depots();
    if ($allowed === null || count($allowed) === 0) return ['', []];
    $names = []; $params = [];
    foreach (array_values($allowed) as $i => $d) { $k = $prefix . $i; $names[] = $k; $params[$k] = $d; }
    $sql = " AND (EXISTS (SELECT 1 FROM loading_pallets _udp
                          WHERE _udp.loading_record_id = {$records_alias}.id
                          AND _udp.depo IN (" . implode(',', $names) . "))
                  OR NOT EXISTS (SELECT 1 FROM loading_pallets _udpu
                                 WHERE _udpu.loading_record_id = {$records_alias}.id
                                 AND TRIM(COALESCE(_udpu.depo,'')) <> '')) ";
    return [$sql, $params];
}

// depo kolonu OLAN tablolar için POZİSYONEL (?) parametreli WHERE parçası.
// Pozisyonel sorgu kuran sayfalarda (stok.php vb.) kullanılır.
// Dönen: ['(col IN (?,?) OR col boş)', ['Depo A','Depo B']] — kısıtsızsa ['', []].
function depo_sql_in(string $col): array {
    $allowed = user_allowed_depots();
    if ($allowed === null || count($allowed) === 0) return ['', []];
    $ph = implode(',', array_fill(0, count($allowed), '?'));
    return ["($col IN ($ph) OR TRIM(COALESCE($col,'')) = '')", array_values($allowed)];
}

// loading_records için POZİSYONEL (?) parametreli EXISTS parçası
// (palet deposu üzerinden kapsar). Pozisyonel sorgu kuran sayfalarda kullanılır.
// Dönen: ['(EXISTS(...) OR atanmamış)', ['Depo A']] — kısıtsızsa ['', []].
function depo_sql_records_in(string $records_ref = 'loading_records'): array {
    $allowed = user_allowed_depots();
    if ($allowed === null || count($allowed) === 0) return ['', []];
    $ph = implode(',', array_fill(0, count($allowed), '?'));
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
    if ($allowed === null || count($allowed) === 0) return ['', []];
    $names = []; $params = [];
    foreach (array_values($allowed) as $i => $d) { $k = $prefix . $i; $names[] = $k; $params[$k] = $d; }
    return [" AND ($col IN (" . implode(',', $names) . ") OR TRIM(COALESCE($col,'')) = '') ", $params];
}

function require_perm(string $permission): void {
    if (current_user() === null) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . (function_exists('base_url') ? base_url() : '') . 'login.php' . ($next ? '?next=' . $next : ''));
        exit;
    }
    enforce_active_depot();
    if (!can($permission)) {
        forbidden("Bu sayfaya erişim yetkiniz yok. (Gerekli yetki: {$permission})");
    }
}

function require_any_perm(array $permissions): void {
    if (current_user() === null) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . (function_exists('base_url') ? base_url() : '') . 'login.php' . ($next ? '?next=' . $next : ''));
        exit;
    }
    enforce_active_depot();
    foreach ($permissions as $p) {
        if (can($p)) return;
    }
    forbidden();
}
