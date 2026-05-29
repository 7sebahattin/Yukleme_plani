<?php
// =========================================================
// users.php — Kullanıcı Yönetim Paneli (yalnızca admin)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('users.admin');

$pdo = db();
$me  = (int)$auth_user['id'];

// ── Rol rozeti HTML üret ───────────────────────────────────────────────────
function render_role_badges(string $slugs_str, string $labels_str): string {
    if ($slugs_str === '') return '<span style="color:var(--muted);font-size:.8rem">—</span>';
    $slugs  = explode(',', $slugs_str);
    $labels = explode(', ', $labels_str);
    $out = '';
    foreach ($slugs as $i => $slug) {
        $slug  = trim($slug);
        $label = trim($labels[$i] ?? $slug);
        $cls   = match($slug) {
            'admin'    => 'usr-badge-admin',
            'operator' => 'usr-badge-operator',
            'viewer'   => 'usr-badge-viewer',
            'muhasebe' => 'usr-badge-muhasebe',
            default    => 'usr-badge-default',
        };
        $out .= '<span class="usr-badge ' . $cls . '">' . h($label) . '</span>';
    }
    return $out;
}

// ── Yardımcılar ────────────────────────────────────────────────────────────
function count_active_admins(PDO $pdo): int {
    return (int)$pdo->query("
        SELECT COUNT(DISTINCT ur.user_id)
        FROM user_roles ur
        JOIN roles r ON r.id = ur.role_id
        JOIN users u ON u.id = ur.user_id
        WHERE r.slug = 'admin' AND u.is_active = 1
    ")->fetchColumn();
}

function user_has_role(PDO $pdo, int $user_id, int $role_id): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND role_id = ?");
    $st->execute([$user_id, $role_id]);
    return (int)$st->fetchColumn() > 0;
}

function valid_username(string $u): bool {
    return $u !== '' && mb_strlen($u) >= 3 && mb_strlen($u) <= 60
        && (bool)preg_match('/^[a-zA-Z0-9_.@-]+$/', $u);
}

// ── Roller ─────────────────────────────────────────────────────────────────
$all_roles  = $pdo->query("SELECT id, slug, label FROM roles ORDER BY id")->fetchAll();
$admin_role = array_values(array_filter($all_roles, fn($r) => $r['slug'] === 'admin'))[0] ?? null;
$admin_rid  = $admin_role ? (int)$admin_role['id'] : 0;
$valid_rids = array_column($all_roles, 'id');

// ── POST Handler ──────────────────────────────────────────────────────────
$action  = '';
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    require_perm('users.admin');
    $action = trim($_POST['action'] ?? '');

    // ─── Yeni kullanıcı oluştur ──────────────────────────────────────────
    if ($action === 'create_user') {
        $uname      = trim($_POST['username']     ?? '');
        $email      = trim($_POST['email']        ?? '');
        $dname      = trim($_POST['display_name'] ?? '');
        $pass1      = $_POST['password']  ?? '';
        $pass2      = $_POST['password2'] ?? '';
        $roles_post = array_map('intval', (array)($_POST['roles'] ?? []));
        $is_active  = empty($_POST['is_active']) ? 0 : 1;

        if (!valid_username($uname)) {
            $error = 'Kullanıcı adı en az 3 karakter, yalnızca harf/rakam/._@- içerebilir.';
        } elseif ($pass1 === '') {
            $error = 'Şifre zorunludur.';
        } elseif (strlen($pass1) < 8) {
            $error = 'Şifre en az 8 karakter olmalı.';
        } elseif ($pass1 !== $pass2) {
            $error = 'Şifreler eşleşmiyor.';
        } elseif (empty($roles_post)) {
            $error = 'En az bir rol seçmelisiniz.';
        }
        if ($error === '') {
            $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $st->execute([$uname]);
            if ((int)$st->fetchColumn() > 0) $error = "Kullanıcı adı zaten kullanımda: $uname";
        }
        if ($error === '' && $email !== '') {
            $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND email != ''");
            $st->execute([$email]);
            if ((int)$st->fetchColumn() > 0) $error = 'Bu e-posta adresi başka kullanıcıda kayıtlı.';
        }
        if ($error === '') {
            $hash = password_hash($pass1, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare("INSERT INTO users (username, email, display_name, password_hash, is_active) VALUES (?, ?, ?, ?, ?)")
                ->execute([$uname, $email, $dname, $hash, $is_active]);
            $new_id = (int)$pdo->lastInsertId();
            $ins_r  = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)");
            foreach ($roles_post as $rid) {
                if (in_array($rid, $valid_rids, true)) $ins_r->execute([$new_id, $rid]);
            }
            $role_labels = array_filter(array_map(
                fn($r) => in_array((int)$r['id'], $roles_post, true) ? $r['label'] : null,
                $all_roles
            ));
            audit_log_event('create', 'users', $new_id, null, [
                'username'     => $uname,
                'email'        => $email,
                'display_name' => $dname,
                'is_active'    => $is_active,
                'roles'        => implode(', ', $role_labels),
            ]);
            header('Location: users.php?ok=' . urlencode("Kullanıcı oluşturuldu: $uname"));
            exit;
        }

    // ─── Kullanıcı düzenle ───────────────────────────────────────────────
    } elseif ($action === 'update_user') {
        $uid        = (int)($_POST['id']           ?? 0);
        $uname      = trim($_POST['username']      ?? '');
        $email      = trim($_POST['email']         ?? '');
        $dname      = trim($_POST['display_name']  ?? '');
        $roles_post = array_map('intval', (array)($_POST['roles'] ?? []));
        // Kendi hesabında is_active checkbox disabled olduğu için POST'ta gelmez;
        // sunucu tarafında 1 olarak zorla — self-lock zaten ayrıca korunuyor.
        $is_active  = ($uid === $me) ? 1 : (empty($_POST['is_active']) ? 0 : 1);

        $st_old = $pdo->prepare("SELECT id, username, email, display_name, is_active FROM users WHERE id = ?");
        $st_old->execute([$uid]);
        $old = $st_old->fetch();

        if (!$old) {
            $error = 'Kullanıcı bulunamadı.';
        } elseif (!valid_username($uname)) {
            $error = 'Kullanıcı adı en az 3 karakter, yalnızca harf/rakam/._@- içerebilir.';
        } elseif (empty($roles_post)) {
            $error = 'En az bir rol seçmelisiniz.';
        } else {
            if ($uid === $me && !$is_active) {
                $error = 'Kendi hesabınızı pasif yapamazsınız.';
            }
            if ($error === '' && $uid === $me && $admin_rid > 0
                && !in_array($admin_rid, $roles_post, true)
                && user_has_role($pdo, $me, $admin_rid)
            ) {
                $error = 'Kendi admin rolünüzü kaldıramazsınız.';
            }
            if ($error === '') {
                $cur_has_admin   = $admin_rid > 0 && user_has_role($pdo, $uid, $admin_rid);
                $will_have_admin = in_array($admin_rid, $roles_post, true);
                $active_admins   = count_active_admins($pdo);
                $will_lose_admin = $cur_has_admin && (!$is_active || !$will_have_admin);
                if ($will_lose_admin && $active_admins <= 1) {
                    $error = 'Sistemde en az bir aktif yönetici kalmalıdır.';
                }
            }
        }
        if ($error === '') {
            $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id != ?");
            $st->execute([$uname, $uid]);
            if ((int)$st->fetchColumn() > 0) $error = 'Kullanıcı adı zaten kullanımda.';
        }
        if ($error === '' && $email !== '') {
            $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ? AND email != ''");
            $st->execute([$email, $uid]);
            if ((int)$st->fetchColumn() > 0) $error = 'Bu e-posta adresi başka kullanıcıda kayıtlı.';
        }
        if ($error === '') {
            $pdo->prepare("UPDATE users SET username=?, email=?, display_name=?, is_active=? WHERE id=?")
                ->execute([$uname, $email, $dname, $is_active, $uid]);
            $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$uid]);
            $ins_r = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)");
            foreach ($roles_post as $rid) {
                if (in_array($rid, $valid_rids, true)) $ins_r->execute([$uid, $rid]);
            }
            $role_labels = array_filter(array_map(
                fn($r) => in_array((int)$r['id'], $roles_post, true) ? $r['label'] : null,
                $all_roles
            ));
            audit_log_event('update', 'users', $uid,
                ['username' => $old['username'], 'email' => $old['email'],
                 'display_name' => $old['display_name'], 'is_active' => $old['is_active']],
                ['username' => $uname, 'email' => $email,
                 'display_name' => $dname, 'is_active' => $is_active,
                 'roles' => implode(', ', $role_labels)]
            );
            header('Location: users.php?ok=' . urlencode('Kullanıcı güncellendi.'));
            exit;
        }

    // ─── Aktif/Pasif toggle ──────────────────────────────────────────────
    } elseif ($action === 'toggle_active') {
        $uid = (int)($_POST['id'] ?? 0);
        if ($uid === $me) {
            $error = 'Kendi hesabınızı pasif yapamazsınız.';
        } else {
            $st = $pdo->prepare("SELECT username, is_active FROM users WHERE id = ?");
            $st->execute([$uid]);
            $row = $st->fetch();
            if (!$row) {
                $error = 'Kullanıcı bulunamadı.';
            } else {
                $new_active = $row['is_active'] ? 0 : 1;
                if (!$new_active && $admin_rid > 0 && user_has_role($pdo, $uid, $admin_rid)) {
                    if (count_active_admins($pdo) <= 1) {
                        $error = 'Sistemde en az bir aktif yönetici kalmalıdır.';
                    }
                }
                if ($error === '') {
                    $pdo->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$new_active, $uid]);
                    audit_log_event('toggle_active', 'users', $uid,
                        ['is_active' => $row['is_active']],
                        ['is_active' => $new_active]
                    );
                    header('Location: users.php?ok=' . urlencode('Kullanıcı durumu değiştirildi.'));
                    exit;
                }
            }
        }

    // ─── Şifre sıfırlama ────────────────────────────────────────────────
    } elseif ($action === 'reset_password') {
        $uid   = (int)($_POST['id']        ?? 0);
        $pass1 = $_POST['password']  ?? '';
        $pass2 = $_POST['password2'] ?? '';

        if ($pass1 === '') {
            $error = 'Şifre boş olamaz.';
        } elseif (strlen($pass1) < 8) {
            $error = 'Şifre en az 8 karakter olmalı.';
        } elseif ($pass1 !== $pass2) {
            $error = 'Şifreler eşleşmiyor.';
        } else {
            $st = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $st->execute([$uid]);
            $row = $st->fetch();
            if (!$row) {
                $error = 'Kullanıcı bulunamadı.';
            } else {
                $hash = password_hash($pass1, PASSWORD_BCRYPT, ['cost' => 12]);
                $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $uid]);
                $pdo->prepare("DELETE FROM user_sessions WHERE user_id=?")->execute([$uid]);
                audit_log_event('password_reset', 'users', $uid, null,
                    ['username' => $row['username']]
                );
                header('Location: users.php?ok=' . urlencode('Şifre sıfırlandı, oturumlar kapatıldı.'));
                exit;
            }
        }
    }
}

// ── Flash (GET redirect) ──────────────────────────────────────────────────
if ($success === '' && isset($_GET['ok'])) {
    $success = trim((string)$_GET['ok']);
}

// ── İstatistikler ─────────────────────────────────────────────────────────
$stats = $pdo->query("
    SELECT COUNT(*) AS total,
           SUM(is_active = 1) AS active,
           SUM(is_active = 0) AS passive
    FROM users
")->fetch();
$admin_count  = (int)$pdo->query("
    SELECT COUNT(DISTINCT ur.user_id)
    FROM user_roles ur JOIN roles r ON r.id = ur.role_id
    WHERE r.slug = 'admin'
")->fetchColumn();
$today_logins = (int)$pdo->query(
    "SELECT COUNT(*) FROM users WHERE DATE(last_login_at) = CURDATE()"
)->fetchColumn();

// ── Kullanıcı listesi ─────────────────────────────────────────────────────
$users = $pdo->query("
    SELECT u.id, u.username, u.display_name, u.email, u.is_active,
           u.last_login_at, u.created_at,
           GROUP_CONCAT(r.label ORDER BY r.slug SEPARATOR ', ') AS roles,
           GROUP_CONCAT(r.id    ORDER BY r.slug SEPARATOR ',')  AS role_ids,
           GROUP_CONCAT(r.slug  ORDER BY r.slug SEPARATOR ',')  AS role_slugs
    FROM users u
    LEFT JOIN user_roles ur ON ur.user_id = u.id
    LEFT JOIN roles r ON r.id = ur.role_id
    GROUP BY u.id
    ORDER BY u.id ASC
")->fetchAll();

// POST hata durumunda modali yeniden açmak için kullanıcı adını bul
$pw_err_uid   = ($error !== '' && $action === 'reset_password') ? (int)($_POST['id'] ?? 0) : 0;
$pw_err_uname = '';
if ($pw_err_uid > 0) {
    foreach ($users as $_u) {
        if ((int)$_u['id'] === $pw_err_uid) { $pw_err_uname = $_u['username']; break; }
    }
}

render_header('Kullanıcı Yönetimi');
?>
<style>
.usr-badge {
    display: inline-block; padding: 2px 8px; border-radius: 20px;
    font-size: .75rem; font-weight: 600; white-space: nowrap; margin: 1px;
}
.usr-badge-admin    { background: #fce7f3; color: #9d174d; }
.usr-badge-operator { background: #dbeafe; color: #1e40af; }
.usr-badge-viewer   { background: #f3f4f6; color: #374151; }
.usr-badge-muhasebe { background: #fef3c7; color: #92400e; }
.usr-badge-default  { background: #f3f4f6; color: #374151; }
.usr-active  { color: #059669; font-weight: 600; }
.usr-passive { color: #9ca3af; }
.usr-cards { display: none; }
@media (max-width: 767px) {
    .usr-table-wrap { display: none; }
    .usr-cards      { display: flex; flex-direction: column; gap: 10px; }
}
.usr-card {
    background: var(--card); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 14px 16px;
}
.usr-card-top {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;
}
.usr-card-name  { font-weight: 700; font-size: 1rem; }
.usr-card-uname { font-size: .82rem; color: var(--muted); margin-top: 2px; }
.usr-card-meta  { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 8px; }
.usr-card-actions { display: flex; gap: 6px; margin-top: 10px; flex-wrap: wrap; }
.usr-form-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
}
@media (max-width: 480px) { .usr-form-grid { grid-template-columns: 1fr; } }
.usr-roles-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px; }
.usr-role-check {
    display: flex; align-items: center; gap: 6px; cursor: pointer;
    font-size: .9rem; padding: 6px 10px;
    border: 1px solid var(--border); border-radius: var(--radius-sm);
    background: var(--card);
}
.usr-role-check input { cursor: pointer; width: 16px; height: 16px; }
.usr-role-check:has(input:checked) {
    border-color: var(--primary); background: var(--primary-soft);
}
</style>

<div class="page-head">
    <h1 style="font-size:1.3rem;font-weight:700;margin:0">Kullanıcı Yönetimi</h1>
    <div class="page-head-actions">
        <button type="button" class="btn btn-primary" onclick="usrOpenCreate()">+ Yeni Kullanıcı</button>
    </div>
</div>

<?php if ($success !== ''): ?>
<div style="background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;border-radius:var(--radius-sm);padding:10px 14px;margin-bottom:12px;font-weight:500">
    <?= h($success) ?>
</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
<div style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:var(--radius-sm);padding:10px 14px;margin-bottom:12px;font-weight:500">
    <?= h($error) ?>
</div>
<?php endif; ?>

<div class="rpt-summary" style="margin-bottom:18px">
    <div class="rpt-sum-item">
        <span>Toplam</span>
        <strong><?= (int)($stats['total'] ?? 0) ?></strong>
    </div>
    <div class="rpt-sum-item rpt-sum-highlight">
        <span>Aktif</span>
        <strong><?= (int)($stats['active'] ?? 0) ?></strong>
    </div>
    <div class="rpt-sum-item">
        <span>Pasif</span>
        <strong><?= (int)($stats['passive'] ?? 0) ?></strong>
    </div>
    <div class="rpt-sum-item">
        <span>Yönetici</span>
        <strong><?= $admin_count ?></strong>
    </div>
    <div class="rpt-sum-item">
        <span>Bugün Giriş</span>
        <strong><?= $today_logins ?></strong>
    </div>
</div>

<!-- Desktop tablo -->
<div class="table-wrap usr-table-wrap">
<table>
<thead>
<tr>
    <th style="width:36px">ID</th>
    <th>Kullanıcı Adı</th>
    <th>Ad Soyad</th>
    <th>E-posta</th>
    <th>Roller</th>
    <th>Durum</th>
    <th>Son Giriş</th>
    <th>Kayıt</th>
    <th></th>
</tr>
</thead>
<tbody>
<?php foreach ($users as $u): ?>
<?php $u_is_me = (int)$u['id'] === $me; ?>
<tr>
    <td style="color:var(--muted);font-size:.8rem"><?= $u['id'] ?></td>
    <td><strong><?= h($u['username']) ?></strong><?= $u_is_me ? ' <span style="font-size:.73rem;color:var(--muted)">(siz)</span>' : '' ?></td>
    <td><?= h($u['display_name']) ?></td>
    <td style="font-size:.85rem"><?= h($u['email']) ?></td>
    <td><?= render_role_badges($u['role_slugs'] ?? '', $u['roles'] ?? '') ?></td>
    <td>
        <?php if ($u['is_active']): ?>
            <span class="usr-active">● Aktif</span>
        <?php else: ?>
            <span class="usr-passive">○ Pasif</span>
        <?php endif; ?>
    </td>
    <td style="white-space:nowrap;font-size:.82rem">
        <?= $u['last_login_at'] ? h(substr($u['last_login_at'], 0, 16)) : '<span style="color:var(--muted)">—</span>' ?>
    </td>
    <td style="white-space:nowrap;font-size:.82rem"><?= h(substr((string)($u['created_at'] ?? ''), 0, 10)) ?></td>
    <td>
        <div style="display:flex;gap:4px;flex-wrap:wrap">
            <button type="button" class="btn btn-sm btn-ghost"
                onclick='usrOpenEdit(<?= h(json_encode([
                    'id'           => (int)$u['id'],
                    'username'     => $u['username'],
                    'display_name' => $u['display_name'],
                    'email'        => $u['email'],
                    'is_active'    => (int)$u['is_active'],
                    'role_ids'     => $u['role_ids'] ? array_map('intval', explode(',', $u['role_ids'])) : [],
                ])) ?>)'>Düzenle</button>
            <button type="button" class="btn btn-sm btn-ghost"
                onclick="usrOpenPwReset(<?= (int)$u['id'] ?>, <?= h(json_encode($u['username'])) ?>)">Şifre</button>
            <form method="post" style="margin:0" onsubmit="return confirm('Durum değiştirilsin mi?')">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button type="submit" class="btn btn-sm <?= $u['is_active'] ? 'btn-ghost' : 'btn-secondary' ?>"
                    <?= $u_is_me ? 'disabled title="Kendinizi pasif yapamazsınız"' : '' ?>>
                    <?= $u['is_active'] ? 'Pasif Yap' : 'Aktif Yap' ?>
                </button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- Mobil kartlar -->
<div class="usr-cards">
<?php foreach ($users as $u): ?>
<?php $u_is_me = (int)$u['id'] === $me; ?>
<div class="usr-card">
    <div class="usr-card-top">
        <div>
            <div class="usr-card-name"><?= h($u['display_name'] ?: $u['username']) ?></div>
            <div class="usr-card-uname">@<?= h($u['username']) ?><?= $u['email'] ? ' · ' . h($u['email']) : '' ?><?= $u_is_me ? ' (siz)' : '' ?></div>
        </div>
        <span class="<?= $u['is_active'] ? 'usr-active' : 'usr-passive' ?>">
            <?= $u['is_active'] ? '● Aktif' : '○ Pasif' ?>
        </span>
    </div>
    <div class="usr-card-meta">
        <?= render_role_badges($u['role_slugs'] ?? '', $u['roles'] ?? '') ?>
    </div>
    <?php if ($u['last_login_at']): ?>
    <div style="font-size:.78rem;color:var(--muted);margin-top:6px">
        Son giriş: <?= h(substr($u['last_login_at'], 0, 16)) ?>
    </div>
    <?php endif; ?>
    <div class="usr-card-actions">
        <button type="button" class="btn btn-sm btn-ghost"
            onclick='usrOpenEdit(<?= h(json_encode([
                'id'           => (int)$u['id'],
                'username'     => $u['username'],
                'display_name' => $u['display_name'],
                'email'        => $u['email'],
                'is_active'    => (int)$u['is_active'],
                'role_ids'     => $u['role_ids'] ? array_map('intval', explode(',', $u['role_ids'])) : [],
            ])) ?>)'>Düzenle</button>
        <button type="button" class="btn btn-sm btn-ghost"
            onclick="usrOpenPwReset(<?= (int)$u['id'] ?>, <?= h(json_encode($u['username'])) ?>)">Şifre Sıfırla</button>
        <form method="post" style="margin:0" onsubmit="return confirm('Durum değiştirilsin mi?')">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button type="submit" class="btn btn-sm <?= $u['is_active'] ? 'btn-ghost' : 'btn-secondary' ?>"
                <?= $u_is_me ? 'disabled title="Kendinizi pasif yapamazsınız"' : '' ?>>
                <?= $u['is_active'] ? 'Pasif Yap' : 'Aktif Yap' ?>
            </button>
        </form>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php if (empty($users)): ?>
<p style="color:var(--muted);text-align:center;padding:32px">Henüz kullanıcı yok.</p>
<?php endif; ?>

<!-- ── Yeni Kullanıcı Modal ────────────────────────────────────────────── -->
<div id="usrCreateModal" class="pm-overlay" hidden>
<div class="pm-dialog" style="max-width:520px">
    <div class="pm-header">
        <h2 class="pm-title">Yeni Kullanıcı</h2>
        <button type="button" class="pm-close" onclick="usrCloseCreate()">✕</button>
    </div>
    <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create_user">
    <div class="pm-body">
        <div class="usr-form-grid">
            <div>
                <label class="form-label" for="c_username">Kullanıcı Adı *</label>
                <input class="form-control" type="text" id="c_username" name="username"
                    required minlength="3" maxlength="60"
                    pattern="[a-zA-Z0-9_.@\-]+" placeholder="ornek_kadi" autocomplete="off"
                    value="<?= $action === 'create_user' ? h(trim($_POST['username'] ?? '')) : '' ?>">
            </div>
            <div>
                <label class="form-label" for="c_display">Ad Soyad</label>
                <input class="form-control" type="text" id="c_display" name="display_name"
                    maxlength="100" placeholder="Mehmet Yılmaz"
                    value="<?= $action === 'create_user' ? h(trim($_POST['display_name'] ?? '')) : '' ?>">
            </div>
            <div>
                <label class="form-label" for="c_pass">Şifre *</label>
                <input class="form-control" type="password" id="c_pass" name="password"
                    required minlength="8" autocomplete="new-password">
            </div>
            <div>
                <label class="form-label" for="c_pass2">Şifre Tekrar *</label>
                <input class="form-control" type="password" id="c_pass2" name="password2"
                    required minlength="8" autocomplete="new-password">
            </div>
        </div>
        <div style="margin-top:12px">
            <label class="form-label" for="c_email">E-posta</label>
            <input class="form-control" type="email" id="c_email" name="email"
                maxlength="180" placeholder="kullanici@sirket.com"
                value="<?= $action === 'create_user' ? h(trim($_POST['email'] ?? '')) : '' ?>">
        </div>
        <div style="margin-top:14px">
            <div class="form-label">Roller *</div>
            <div class="usr-roles-grid">
            <?php
            $c_roles_post = ($action === 'create_user') ? array_map('intval', (array)($_POST['roles'] ?? [])) : [];
            foreach ($all_roles as $r):
            ?>
                <label class="usr-role-check">
                    <input type="checkbox" name="roles[]" value="<?= $r['id'] ?>"
                        <?= in_array((int)$r['id'], $c_roles_post, true) ? 'checked' : '' ?>>
                    <?= h($r['label']) ?>
                </label>
            <?php endforeach; ?>
            </div>
        </div>
        <div style="margin-top:14px">
            <label class="usr-role-check" style="display:inline-flex">
                <input type="checkbox" name="is_active" value="1"
                    <?= ($action !== 'create_user' || !empty($_POST['is_active'])) ? 'checked' : '' ?>>
                Hesap aktif
            </label>
        </div>
    </div>
    <div class="pm-footer">
        <button type="button" class="btn btn-ghost" onclick="usrCloseCreate()">İptal</button>
        <button type="submit" class="btn btn-primary">Oluştur</button>
    </div>
    </form>
</div>
</div>

<!-- ── Düzenle Modal ───────────────────────────────────────────────────── -->
<div id="usrEditModal" class="pm-overlay" hidden>
<div class="pm-dialog" style="max-width:520px">
    <div class="pm-header">
        <h2 class="pm-title">Kullanıcıyı Düzenle</h2>
        <button type="button" class="pm-close" onclick="usrCloseEdit()">✕</button>
    </div>
    <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="update_user">
    <input type="hidden" name="id" id="e_id">
    <div class="pm-body">
        <div class="usr-form-grid">
            <div>
                <label class="form-label" for="e_username">Kullanıcı Adı *</label>
                <input class="form-control" type="text" id="e_username" name="username"
                    required minlength="3" maxlength="60"
                    pattern="[a-zA-Z0-9_.@\-]+" autocomplete="off">
            </div>
            <div>
                <label class="form-label" for="e_display">Ad Soyad</label>
                <input class="form-control" type="text" id="e_display" name="display_name" maxlength="100">
            </div>
        </div>
        <div style="margin-top:12px">
            <label class="form-label" for="e_email">E-posta</label>
            <input class="form-control" type="email" id="e_email" name="email" maxlength="180">
        </div>
        <div style="margin-top:14px">
            <div class="form-label">Roller *</div>
            <div class="usr-roles-grid" id="e_roles_wrap">
            <?php foreach ($all_roles as $r): ?>
                <label class="usr-role-check">
                    <input type="checkbox" name="roles[]" value="<?= $r['id'] ?>" data-rid="<?= $r['id'] ?>">
                    <?= h($r['label']) ?>
                </label>
            <?php endforeach; ?>
            </div>
        </div>
        <div style="margin-top:14px;display:flex;align-items:center;gap:10px">
            <label class="usr-role-check" style="display:inline-flex">
                <input type="checkbox" id="e_is_active" name="is_active" value="1">
                Hesap aktif
            </label>
            <span id="e_self_note" style="display:none;font-size:.8rem;color:var(--muted)">(Kendi hesabınız — değiştirilemez)</span>
        </div>
    </div>
    <div class="pm-footer">
        <button type="button" class="btn btn-ghost" onclick="usrCloseEdit()">İptal</button>
        <button type="submit" class="btn btn-primary">Kaydet</button>
    </div>
    </form>
</div>
</div>

<!-- ── Şifre Sıfırlama Modal ──────────────────────────────────────────── -->
<div id="usrPwModal" class="pm-overlay" hidden>
<div class="pm-dialog" style="max-width:400px">
    <div class="pm-header">
        <h2 class="pm-title">Şifre Sıfırla</h2>
        <button type="button" class="pm-close" onclick="usrClosePw()">✕</button>
    </div>
    <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="reset_password">
    <input type="hidden" name="id" id="pw_id">
    <div class="pm-body">
        <p style="margin-top:0;color:var(--muted);font-size:.9rem">
            Kullanıcı: <strong id="pw_uname"></strong><br>
            <small>Kayıt sonrası mevcut oturumlar kapatılır.</small>
        </p>
        <div style="margin-bottom:12px">
            <label class="form-label" for="pw_pass">Yeni Şifre *</label>
            <input class="form-control" type="password" id="pw_pass" name="password"
                required minlength="8" autocomplete="new-password">
        </div>
        <div>
            <label class="form-label" for="pw_pass2">Şifre Tekrar *</label>
            <input class="form-control" type="password" id="pw_pass2" name="password2"
                required minlength="8" autocomplete="new-password">
        </div>
    </div>
    <div class="pm-footer">
        <button type="button" class="btn btn-ghost" onclick="usrClosePw()">İptal</button>
        <button type="submit" class="btn btn-danger">Şifreyi Sıfırla</button>
    </div>
    </form>
</div>
</div>

<script>
(function () {
    var meId = <?= $me ?>;

    function openModal(id) {
        document.getElementById(id).removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
    }
    function closeModal(id) {
        document.getElementById(id).setAttribute('hidden', '');
        document.body.style.overflow = '';
    }

    ['usrCreateModal','usrEditModal','usrPwModal'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('click', function (e) {
            if (e.target === this) closeModal(id);
        });
    });

    window.usrOpenCreate  = function () { openModal('usrCreateModal'); };
    window.usrCloseCreate = function () { closeModal('usrCreateModal'); };

    window.usrOpenEdit = function (data) {
        document.getElementById('e_id').value       = data.id;
        document.getElementById('e_username').value = data.username;
        document.getElementById('e_display').value  = data.display_name || '';
        document.getElementById('e_email').value    = data.email || '';

        var isActive   = document.getElementById('e_is_active');
        var selfNote   = document.getElementById('e_self_note');
        isActive.checked  = !!data.is_active;
        isActive.disabled = (data.id == meId);
        selfNote.style.display = (data.id == meId) ? 'inline' : 'none';

        document.querySelectorAll('#e_roles_wrap input[type=checkbox]').forEach(function (cb) {
            cb.checked = data.role_ids && data.role_ids.indexOf(parseInt(cb.dataset.rid)) !== -1;
        });

        openModal('usrEditModal');
        document.getElementById('e_username').focus();
    };
    window.usrCloseEdit = function () { closeModal('usrEditModal'); };

    window.usrOpenPwReset = function (id, uname) {
        document.getElementById('pw_id').value = id;
        document.getElementById('pw_uname').textContent = uname;
        document.getElementById('pw_pass').value  = '';
        document.getElementById('pw_pass2').value = '';
        openModal('usrPwModal');
        document.getElementById('pw_pass').focus();
    };
    window.usrClosePw = function () { closeModal('usrPwModal'); };

    // POST hatası sonrası ilgili modali aç
    <?php if ($error !== '' && $action === 'create_user'): ?>
    usrOpenCreate();
    <?php elseif ($error !== '' && $action === 'update_user'): ?>
    usrOpenEdit({
        id:           <?= (int)($_POST['id'] ?? 0) ?>,
        username:     <?= json_encode(trim($_POST['username'] ?? '')) ?>,
        display_name: <?= json_encode(trim($_POST['display_name'] ?? '')) ?>,
        email:        <?= json_encode(trim($_POST['email'] ?? '')) ?>,
        is_active:    <?= empty($_POST['is_active']) ? '0' : '1' ?>,
        role_ids:     <?= json_encode(array_map('intval', (array)($_POST['roles'] ?? []))) ?>,
    });
    <?php elseif ($error !== '' && $action === 'reset_password' && $pw_err_uid > 0): ?>
    usrOpenPwReset(<?= $pw_err_uid ?>, <?= json_encode($pw_err_uname) ?>);
    <?php endif; ?>
})();
</script>

<?php render_footer(); ?>
