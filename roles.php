<?php
// =========================================================
// roles.php — Rol Yönetim Paneli (yalnızca admin)
// Roller ekranı: users.php'de sabit 5 rol arasından seçim yapmak yerine,
// admin kendi rollerini oluşturup her birine modül bazlı yetki paketi
// atayabilir. roles/role_permissions/user_roles tabloları zaten mevcuttu
// (kurulum seed'i config/helpers.php'nin en altındaki migrasyon IIFE'sinde)
// — bu sayfa yalnız onların üzerine bir CRUD arayüzü koyar, şema
// değişikliği yapmaz. Seed artık YALNIZ yetkisi hiç olmayan rolü doldurur;
// buradan kaldırılan bir yetkiyi geri yazmaz (bkz. helpers.php'deki not).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('users.admin');

$pdo = db();

$catalog = permission_catalog();
$flat_permissions = [];
foreach ($catalog as $group_perms) {
    foreach ($group_perms as $perm => $label) $flat_permissions[$perm] = $label;
}
$protected_slugs = protected_role_slugs();

// ── Yardımcılar ────────────────────────────────────────────────────────────
function valid_role_label(string $l): bool {
    return $l !== '' && mb_strlen($l) <= 80;
}

// POST'tan gelen yetki listesini temizler: yalnız katalogda TANIMLI, metin
// olan değerler geçer. is_string kapısı şart — `permissions[]` içine iç içe
// dizi gönderilirse array_map('strval') "Array to string conversion" uyarısı
// bastırıyordu (sayfaya PHP uyarısı sızıyordu).
function clean_permissions($raw, array $flat): array {
    $out = [];
    foreach ((array)$raw as $p) {
        if (is_string($p) && isset($flat[$p])) $out[$p] = true;
    }
    return array_keys($out);
}

// Rol adından slug üretir (Türkçe karakterleri sadeleştirir) ve çakışırsa
// _2, _3 ... ekleyerek benzersizleştirir. Slug yalnız OLUŞTURMADA üretilir,
// sonradan hiçbir yerden değiştirilmez (is_admin() vb. slug'a göre davranır).
function role_slug_from_label(PDO $pdo, string $label): string {
    $tr_map = ['ç'=>'c','Ç'=>'c','ğ'=>'g','Ğ'=>'g','ı'=>'i','I'=>'i','İ'=>'i',
               'ö'=>'o','Ö'=>'o','ş'=>'s','Ş'=>'s','ü'=>'u','Ü'=>'u'];
    $base = mb_strtolower(strtr($label, $tr_map), 'UTF-8');
    $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?? '';
    $base = trim($base, '_');
    if ($base === '') $base = 'rol';
    $base = mb_substr($base, 0, 30);
    $slug = $base;
    $st = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE slug = ?");
    $i = 2;
    while (true) {
        $st->execute([$slug]);
        if ((int)$st->fetchColumn() === 0) break;
        $slug = $base . '_' . $i;
        $i++;
    }
    return $slug;
}

// Yetki checkbox grid'ini basar — hem Yeni Rol hem Düzenle modalinde kullanılır.
function render_permission_grid(array $catalog, array $checked, string $prefix): void {
    foreach ($catalog as $group => $perms) {
        echo '<div class="rol-perm-group">';
        echo '<div class="rol-perm-group-head">';
        echo '<span class="rol-perm-group-title">' . h($group) . '</span>';
        echo '<span class="rol-perm-group-actions">'
           . '<button type="button" class="rol-perm-toggle" data-mode="all">Tümü</button>'
           . '<button type="button" class="rol-perm-toggle" data-mode="none">Hiçbiri</button>'
           . '</span></div>';
        echo '<div class="rol-perm-grid">';
        foreach ($perms as $perm => $label) {
            $cid = $prefix . '_' . str_replace('.', '_', $perm);
            $is_checked = in_array($perm, $checked, true) ? 'checked' : '';
            echo '<label class="usr-role-check" for="' . h($cid) . '">'
               . '<input type="checkbox" id="' . h($cid) . '" name="permissions[]" value="' . h($perm) . '" ' . $is_checked . '>'
               . h($label) . '</label>';
        }
        echo '</div></div>';
    }
}

// ── POST Handler ──────────────────────────────────────────────────────────
$action  = '';
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    require_perm('users.admin');
    $action = trim($_POST['action'] ?? '');

    // ─── Yeni rol oluştur ────────────────────────────────────────────────
    if ($action === 'create_role') {
        $label      = trim($_POST['label'] ?? '');
        $perms_post = clean_permissions($_POST['permissions'] ?? [], $flat_permissions);

        if (!valid_role_label($label)) {
            $error = 'Rol adı boş olamaz (en fazla 80 karakter).';
        } else {
            $st = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE label = ?");
            $st->execute([$label]);
            if ((int)$st->fetchColumn() > 0) $error = 'Bu isimde bir rol zaten var.';
        }

        if ($error === '') {
            $slug = role_slug_from_label($pdo, $label);
            $pdo->prepare("INSERT INTO roles (slug, label) VALUES (?, ?)")->execute([$slug, $label]);
            $rid = (int)$pdo->lastInsertId();
            if (!empty($perms_post)) {
                $ins = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission) VALUES (?, ?)");
                foreach ($perms_post as $p) $ins->execute([$rid, $p]);
            }
            audit_log_event('create', 'roles', $rid, null, [
                'label' => $label, 'slug' => $slug, 'permissions' => implode(', ', $perms_post),
            ]);
            header('Location: roles.php?ok=' . urlencode("Rol oluşturuldu: $label"));
            exit;
        }

    // ─── Rol düzenle (ad + yetki paketi) ────────────────────────────────
    } elseif ($action === 'update_role') {
        $rid        = (int)($_POST['id'] ?? 0);
        $label      = trim($_POST['label'] ?? '');
        $perms_post = clean_permissions($_POST['permissions'] ?? [], $flat_permissions);

        $st = $pdo->prepare("SELECT id, slug, label FROM roles WHERE id = ?");
        $st->execute([$rid]);
        $old = $st->fetch();

        if (!$old) {
            $error = 'Rol bulunamadı.';
        } elseif (!valid_role_label($label)) {
            $error = 'Rol adı boş olamaz (en fazla 80 karakter).';
        } else {
            $st = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE label = ? AND id != ?");
            $st->execute([$label, $rid]);
            if ((int)$st->fetchColumn() > 0) $error = 'Bu isimde bir rol zaten var.';
        }

        if ($error === '') {
            $st = $pdo->prepare("SELECT permission FROM role_permissions WHERE role_id = ?");
            $st->execute([$rid]);
            $old_perms = $st->fetchAll(PDO::FETCH_COLUMN);

            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE roles SET label = ? WHERE id = ?")->execute([$label, $rid]);
                $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$rid]);
                if (!empty($perms_post)) {
                    $ins = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission) VALUES (?, ?)");
                    foreach ($perms_post as $p) $ins->execute([$rid, $p]);
                }
                // Kilitlenme kilidi: bu değişiklikten sonra sistemde
                // 'users.admin' yetkisine sahip hiçbir aktif kullanıcı
                // kalmıyorsa geri al — aksi halde kimse rol/kullanıcı
                // yönetemez hale gelir ve düzeltmenin tek yolu DB'ye elden
                // müdahale olurdu.
                if (!any_active_user_has_permission('users.admin')) {
                    throw new RuntimeException('lockout');
                }
                $pdo->commit();
            } catch (PDOException $e) {
                // ÖNEMLİ: PDOException, RuntimeException'ın ALT SINIFIDIR —
                // bu blok aşağıdakinden ÖNCE gelmezse gerçek bir veritabanı
                // hatası kullanıcıya "kilitlenme" mesajı olarak gösterilirdi.
                $pdo->rollBack();
                $error = 'Rol kaydedilemedi (veritabanı hatası). Lütfen tekrar deneyin.';
            } catch (RuntimeException $e) {
                $pdo->rollBack();
                $error = 'Bu değişiklik kaydedilemedi: kaydedilirse sistemde "Kullanıcı ve Rol Yönetimi" yetkisine sahip hiçbir aktif kullanıcı kalmaz. En az bir kullanıcıda bu yetki kalmalı.';
            }

            if ($error === '') {
                audit_log_event('update', 'roles', $rid,
                    ['label' => $old['label'], 'permissions' => implode(', ', $old_perms)],
                    ['label' => $label, 'permissions' => implode(', ', $perms_post)]
                );
                header('Location: roles.php?ok=' . urlencode('Rol güncellendi: ' . $label));
                exit;
            }
        }

    // ─── Rol sil ─────────────────────────────────────────────────────────
    } elseif ($action === 'delete_role') {
        $rid = (int)($_POST['id'] ?? 0);
        $st  = $pdo->prepare("SELECT id, slug, label FROM roles WHERE id = ?");
        $st->execute([$rid]);
        $old = $st->fetch();

        if (!$old) {
            $error = 'Rol bulunamadı.';
        } elseif (in_array($old['slug'], $protected_slugs, true)) {
            $error = 'Bu sistem rolü silinemez: ' . $old['label'];
        } else {
            $st = $pdo->prepare("SELECT COUNT(*) FROM user_roles WHERE role_id = ?");
            $st->execute([$rid]);
            $ucount = (int)$st->fetchColumn();
            if ($ucount > 0) {
                $error = "Bu role atanmış $ucount kullanıcı var. Silmeden önce kullanıcıları başka bir role atayın.";
            }
        }

        if ($error === '') {
            $st = $pdo->prepare("SELECT permission FROM role_permissions WHERE role_id = ?");
            $st->execute([$rid]);
            $old_perms = $st->fetchAll(PDO::FETCH_COLUMN);

            // İki silme tek işlemde: ikincisi patlarsa yetim role_permissions
            // satırları kalır ve o id yeniden kullanılırsa yanlış yetki verir.
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$rid]);
                $pdo->prepare("DELETE FROM roles WHERE id = ?")->execute([$rid]);
                $pdo->commit();
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'Rol silinemedi (veritabanı hatası). Lütfen tekrar deneyin.';
            }
        }
        if ($error === '') {
            audit_log_event('delete', 'roles', $rid,
                ['label' => $old['label'], 'slug' => $old['slug'], 'permissions' => implode(', ', $old_perms)],
                null
            );
            header('Location: roles.php?ok=' . urlencode('Rol silindi: ' . $old['label']));
            exit;
        }
    }
}

if ($success === '' && isset($_GET['ok'])) {
    $success = trim((string)$_GET['ok']);
}

// ── Liste verisi ─────────────────────────────────────────────────────────
$roles = $pdo->query("
    SELECT r.id, r.slug, r.label,
           (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS user_count
    FROM roles r
    ORDER BY r.id ASC
")->fetchAll();

$role_perm_map = [];
foreach ($pdo->query("SELECT role_id, permission FROM role_permissions")->fetchAll() as $rp) {
    $role_perm_map[(int)$rp['role_id']][] = $rp['permission'];
}

$total_perm_count = count($flat_permissions);
// Sabit 5 yerine GERÇEKTEN var olan sistem rolünü say — eksik kurulumda
// "Özel Rol" sayısı eksiye düşüyordu.
$sistem_rol_sayisi = count(array_filter($roles, fn($r) => in_array($r['slug'], $protected_slugs, true)));

render_header('Roller');
?>
<style>
.rol-badge-slug {
    display: inline-block; font-family: monospace; font-size: .72rem;
    color: var(--muted); background: var(--surface, #f3f4f6);
    border-radius: 6px; padding: 1px 6px; margin-left: 6px;
}
.rol-badge-system {
    display: inline-block; padding: 1px 8px; border-radius: 20px;
    font-size: .7rem; font-weight: 700; background: #eef2ff; color: #3730a3;
    border: 1px solid #c7d2fe; white-space: nowrap; margin-left: 4px;
}
.rol-perm-count { font-size: .82rem; color: var(--muted); }
.rol-cards { display: none; }
@media (max-width: 767px) {
    .rol-table-wrap { display: none; }
    .rol-cards      { display: flex; flex-direction: column; gap: 10px; }
}
.rol-card {
    background: var(--card); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 14px 16px;
}
.rol-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.rol-card-name { font-weight: 700; font-size: 1rem; }
.rol-card-actions { display: flex; gap: 6px; margin-top: 10px; flex-wrap: wrap; }
.rol-form-label-row { margin-bottom: 14px; }
.rol-perm-group {
    border: 1px solid var(--border); border-radius: var(--radius-sm);
    padding: 10px 12px; margin-bottom: 10px;
}
.rol-perm-group-head {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 8px; gap: 8px;
}
.rol-perm-group-title { font-weight: 700; font-size: .85rem; }
.rol-perm-group-actions { display: flex; gap: 6px; }
.rol-perm-toggle {
    background: none; border: none; color: var(--primary); cursor: pointer;
    font-size: .75rem; padding: 0; text-decoration: underline;
}
.rol-perm-grid { display: flex; flex-wrap: wrap; gap: 6px; }
.rol-ipucu {
    font-size: .8rem; color: var(--muted); line-height: 1.45;
    background: var(--primary-soft, #eef2ff); border-radius: var(--radius-sm);
    padding: 8px 10px; margin: 0 0 10px;
}
.rol-perm-grid .usr-role-check { font-size: .82rem; padding: 5px 9px; }
</style>

<div class="page-head">
    <h1 style="font-size:1.3rem;font-weight:700;margin:0">Rol Yönetimi</h1>
    <div class="page-head-actions">
        <button type="button" class="btn btn-primary" onclick="rolOpenCreate()">+ Yeni Rol</button>
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
        <span>Toplam Rol</span>
        <strong><?= count($roles) ?></strong>
    </div>
    <div class="rpt-sum-item">
        <span>Sistem Rolü</span>
        <strong><?= $sistem_rol_sayisi ?></strong>
    </div>
    <div class="rpt-sum-item rpt-sum-highlight">
        <span>Özel Rol</span>
        <strong><?= count($roles) - $sistem_rol_sayisi ?></strong>
    </div>
    <div class="rpt-sum-item">
        <span>Toplam Yetki</span>
        <strong><?= $total_perm_count ?></strong>
    </div>
</div>

<p style="color:var(--muted);font-size:.85rem;margin-top:-8px;margin-bottom:16px">
    Bir kullanıcıya rol atamak için <a href="users.php">Kullanıcı Yönetimi</a> ekranını kullanın.
</p>

<!-- Desktop tablo -->
<div class="table-wrap rol-table-wrap">
<table>
<thead>
<tr>
    <th style="width:58px">ID</th>
    <th>Rol Adı</th>
    <th>Yetki Sayısı</th>
    <th>Kullanıcı</th>
    <th></th>
</tr>
</thead>
<tbody>
<?php foreach ($roles as $r): ?>
<?php
    $rid       = (int)$r['id'];
    $is_system = in_array($r['slug'], $protected_slugs, true);
    $r_perms   = $role_perm_map[$rid] ?? [];
    $ucount    = (int)$r['user_count'];
?>
<tr>
    <td style="color:var(--muted);font-size:.8rem"><?= $rid ?></td>
    <td>
        <strong><?= h($r['label']) ?></strong>
        <span class="rol-badge-slug"><?= h($r['slug']) ?></span>
        <?php if ($is_system): ?><span class="rol-badge-system">Sistem</span><?php endif; ?>
    </td>
    <td class="rol-perm-count"><?= count($r_perms) ?> / <?= $total_perm_count ?></td>
    <td class="rol-perm-count"><?= $ucount ?></td>
    <td>
        <div style="display:flex;gap:4px;flex-wrap:wrap">
            <button type="button" class="btn btn-sm btn-ghost"
                onclick='rolOpenEdit(<?= h(json_encode([
                    'id'          => $rid,
                    'label'       => $r['label'],
                    'slug'        => $r['slug'],
                    'permissions' => array_values($r_perms),
                ])) ?>)'>Düzenle</button>
            <form method="post" style="margin:0" onsubmit="return confirm('Rol silinsin mi? Bu işlem geri alınamaz.')">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_role">
                <input type="hidden" name="id" value="<?= $rid ?>">
                <button type="submit" class="btn btn-sm btn-danger"
                    <?= ($is_system || $ucount > 0) ? 'disabled' : '' ?>
                    title="<?= $is_system ? 'Sistem rolü silinemez' : ($ucount > 0 ? 'Önce atanmış kullanıcıları başka role taşıyın' : 'Rolü sil') ?>">
                    Sil
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
<div class="rol-cards">
<?php foreach ($roles as $r): ?>
<?php
    $rid       = (int)$r['id'];
    $is_system = in_array($r['slug'], $protected_slugs, true);
    $r_perms   = $role_perm_map[$rid] ?? [];
    $ucount    = (int)$r['user_count'];
?>
<div class="rol-card">
    <div class="rol-card-top">
        <div>
            <div class="rol-card-name"><?= h($r['label']) ?>
                <?php if ($is_system): ?><span class="rol-badge-system">Sistem</span><?php endif; ?>
            </div>
            <div class="rol-badge-slug" style="margin-left:0"><?= h($r['slug']) ?></div>
        </div>
    </div>
    <div class="rol-perm-count" style="margin-top:6px">
        <?= count($r_perms) ?> / <?= $total_perm_count ?> yetki · <?= $ucount ?> kullanıcı
    </div>
    <div class="rol-card-actions">
        <button type="button" class="btn btn-sm btn-ghost"
            onclick='rolOpenEdit(<?= h(json_encode([
                'id'          => $rid,
                'label'       => $r['label'],
                'slug'        => $r['slug'],
                'permissions' => array_values($r_perms),
            ])) ?>)'>Düzenle</button>
        <form method="post" style="margin:0" onsubmit="return confirm('Rol silinsin mi? Bu işlem geri alınamaz.')">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_role">
            <input type="hidden" name="id" value="<?= $rid ?>">
            <button type="submit" class="btn btn-sm btn-danger" <?= ($is_system || $ucount > 0) ? 'disabled' : '' ?>>
                Sil
            </button>
        </form>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php if (empty($roles)): ?>
<p style="color:var(--muted);text-align:center;padding:32px">Henüz rol yok.</p>
<?php endif; ?>

<!-- ── Yeni Rol Modal ──────────────────────────────────────────────────── -->
<div id="rolCreateModal" class="pm-overlay" hidden>
<div class="pm-dialog" style="max-width:640px">
    <div class="pm-header">
        <h2 class="pm-title">Yeni Rol</h2>
        <button type="button" class="pm-close" onclick="rolCloseCreate()">✕</button>
    </div>
    <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create_role">
    <div class="pm-body">
        <div class="rol-form-label-row">
            <label class="form-label" for="c_role_label">Rol Adı *</label>
            <input class="form-control" type="text" id="c_role_label" name="label"
                required maxlength="80" placeholder="ör. Depo Sorumlusu"
                value="<?= $action === 'create_role' ? h(trim($_POST['label'] ?? '')) : '' ?>">
        </div>
        <div class="form-label" style="margin-bottom:6px">Yetkiler</div>
        <p class="rol-ipucu">
            <strong>Ana sayfayı görüntüle</strong> yetkisi önerilir: kullanıcı girişten sonra
            ana sayfaya düşer. Bu yetki olmadan da sistem çalışır — kullanıcı doğrudan
            erişebildiği ilk sayfaya yönlendirilir.
        </p>
        <?php
        // Yeni rol VARSAYILANI: ana sayfa. POST hatasından sonra kullanıcının
        // kendi seçimi korunur (varsayılana geri dönmez).
        $c_perms_post = ($action === 'create_role')
            ? clean_permissions($_POST['permissions'] ?? [], $flat_permissions)
            : ['dashboard.read'];
        render_permission_grid($catalog, $c_perms_post, 'c');
        ?>
    </div>
    <div class="pm-footer">
        <button type="button" class="btn btn-ghost" onclick="rolCloseCreate()">İptal</button>
        <button type="submit" class="btn btn-primary">Oluştur</button>
    </div>
    </form>
</div>
</div>

<!-- ── Rol Düzenle Modal ───────────────────────────────────────────────── -->
<div id="rolEditModal" class="pm-overlay" hidden>
<div class="pm-dialog" style="max-width:640px">
    <div class="pm-header">
        <h2 class="pm-title">Rolü Düzenle</h2>
        <button type="button" class="pm-close" onclick="rolCloseEdit()">✕</button>
    </div>
    <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="update_role">
    <input type="hidden" name="id" id="e_role_id">
    <div class="pm-body">
        <div class="rol-form-label-row">
            <label class="form-label" for="e_role_label">Rol Adı *</label>
            <input class="form-control" type="text" id="e_role_label" name="label" required maxlength="80">
            <p style="font-size:.78rem;color:var(--muted);margin:4px 0 0">
                Sistem kimliği: <span id="e_role_slug" class="rol-badge-slug"></span> (değiştirilemez)
            </p>
        </div>
        <div class="form-label" style="margin-bottom:6px">Yetkiler</div>
        <div id="e_perm_wrap">
        <?php render_permission_grid($catalog, [], 'e'); ?>
        </div>
    </div>
    <div class="pm-footer">
        <button type="button" class="btn btn-ghost" onclick="rolCloseEdit()">İptal</button>
        <button type="submit" class="btn btn-primary">Kaydet</button>
    </div>
    </form>
</div>
</div>

<script>
(function () {
    function openModal(id) {
        document.getElementById(id).removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
    }
    function closeModal(id) {
        document.getElementById(id).setAttribute('hidden', '');
        document.body.style.overflow = '';
    }

    ['rolCreateModal', 'rolEditModal'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('click', function (e) {
            if (e.target === this) closeModal(id);
        });
    });

    // Grup içi "Tümü / Hiçbiri" kısayolları — her iki modalde de çalışır.
    document.querySelectorAll('.rol-perm-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var group = btn.closest('.rol-perm-group');
            if (!group) return;
            var checked = btn.dataset.mode === 'all';
            group.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
                cb.checked = checked;
            });
        });
    });

    window.rolOpenCreate  = function () { openModal('rolCreateModal'); };
    window.rolCloseCreate = function () { closeModal('rolCreateModal'); };

    window.rolOpenEdit = function (data) {
        document.getElementById('e_role_id').value    = data.id;
        document.getElementById('e_role_label').value = data.label;
        document.getElementById('e_role_slug').textContent = data.slug;

        var perms = data.permissions || [];
        document.querySelectorAll('#e_perm_wrap input[type=checkbox]').forEach(function (cb) {
            cb.checked = perms.indexOf(cb.value) !== -1;
        });

        openModal('rolEditModal');
        document.getElementById('e_role_label').focus();
    };
    window.rolCloseEdit = function () { closeModal('rolEditModal'); };

    // POST hatası sonrası ilgili modali eski değerlerle yeniden aç
    <?php if ($error !== '' && $action === 'create_role'): ?>
    rolOpenCreate();
    <?php elseif ($error !== '' && $action === 'update_role'): ?>
    rolOpenEdit({
        id:          <?= (int)($_POST['id'] ?? 0) ?>,
        label:       <?= json_encode(trim($_POST['label'] ?? '')) ?>,
        slug:        <?= json_encode((function () use ($pdo) {
                            $st = $pdo->prepare("SELECT slug FROM roles WHERE id = ?");
                            $st->execute([(int)($_POST['id'] ?? 0)]);
                            return (string)($st->fetchColumn() ?: '');
                        })()) ?>,
        permissions: <?= json_encode(array_map('strval', (array)($_POST['permissions'] ?? []))) ?>,
    });
    <?php endif; ?>
})();
</script>

<?php render_footer(); ?>
