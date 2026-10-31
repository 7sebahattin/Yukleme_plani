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
require_once __DIR__ . '/config/role_permission_matrix.php';
require_once __DIR__ . '/config/role_data_scope.php';
$auth_user = require_login();
require_perm('users.admin');

$pdo = db();
$scope_options = role_data_scope_options($pdo);

$catalog = permission_catalog();
$flat_permissions = [];
foreach ($catalog as $group_perms) {
    foreach ($group_perms as $perm => $label) $flat_permissions[$perm] = $label;
}
// Existing extension grants remain visible and editable when absent from this version's catalogue.
foreach ($pdo->query('SELECT DISTINCT permission FROM role_permissions')->fetchAll(PDO::FETCH_COLUMN) as $stored_permission) {
    if (is_string($stored_permission) && !str_starts_with($stored_permission, 'scope.') && !isset($flat_permissions[$stored_permission])) {
        $catalog['Ek yetkiler'][$stored_permission] = 'Ek yetki: ' . $stored_permission;
        $flat_permissions[$stored_permission] = 'Ek yetki: ' . $stored_permission;
    }
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

// Sidebar sıralı matris; kontroller gerçek izin kodlarını gönderir.
function render_matrix_control(array $action, array $checked, string $prefix, string $row, string $kind, array &$used): string {
    $perm = $action['permission'] ?? $action['perm'] ?? null;
    if (!is_string($perm) || $perm === '') return '<span class="rol-unavailable" aria-label="Bu işlem desteklenmiyor">—</span>';
    $used[$perm] = true;
    $label = (string)($action['label'] ?? '');
    if ($label === '') $label = ['read'=>'Gör','create'=>'Ekle','edit'=>'Düzenle','delete'=>'Sil'][$kind] ?? $kind;
    $id = $prefix . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $row . '_' . $kind . '_' . $perm);
    return '<label class="rol-switch" for="' . h($id) . '" title="' . h($label) . '"><input type="checkbox" role="switch" id="' . h($id) . '" name="permissions[]" value="' . h($perm) . '" aria-label="' . h($row . ' — ' . $label) . '" ' . (in_array($perm, $checked, true) ? 'checked' : '') . '><span class="rol-switch-track" aria-hidden="true"></span><span class="rol-switch-label">' . h($label) . '</span></label>';
}
function render_matrix_rows(array $rows, array $checked, string $prefix, array &$used, string $parent = '', int $depth = 0): void {
    $columns = ['read'=>'Gör', 'create'=>'Ekle', 'edit'=>'Düzenle', 'delete'=>'Sil'];
    foreach ($rows as $index => $row) {
        $key = (string)($row['key'] ?? $index);
        $path = $parent === '' ? $key : $parent . '/' . $key;
        $label = (string)($row['label'] ?? $key);
        $actions = $row['actions'] ?? [];
        $children = $row['children'] ?? [];
        $extras = array_diff_key($actions, $columns);
        $expandable = $children || $extras;
        $adminOnly = !empty($row['admin_only']);
        echo '<tr class="rol-matrix-row" data-path="' . h($path) . '" data-parent="' . h($parent) . '" data-label="' . h($label) . '" data-depth="' . $depth . '"><th scope="row"><div class="rol-row-heading" style="--rol-depth:' . $depth . '">';
        if ($expandable) echo '<button type="button" class="rol-row-expand" aria-expanded="false" aria-label="' . h($label . ' alt başlıklarını aç') . '"><span aria-hidden="true">›</span></button>';
        else echo '<span class="rol-row-spacer" aria-hidden="true"></span>';
        echo '<div><span class="rol-row-title">' . h($label) . '</span>';
        if ($adminOnly) echo '<small class="rol-admin-note">Yalnız sistem yöneticisi</small>';
        elseif ($children) echo '<small class="rol-row-subtitle">' . count($children) . ' alt başlık</small>';
        if (!empty($row['note'])) echo '<small class="rol-row-note">' . h((string)$row['note']) . '</small>';
        echo '</div></div></th>';
        foreach ($columns as $kind => $column) {
            echo '<td data-column="' . h($column) . '">';
            echo isset($actions[$kind]) && !$adminOnly ? render_matrix_control($actions[$kind], $checked, $prefix, $label, $kind, $used) : '<span class="rol-unavailable" aria-label="Bu işlem desteklenmiyor">—</span>';
            echo '</td>';
        }
        echo '</tr>';
        if ($extras) {
            echo '<tr class="rol-matrix-extra" data-path="' . h($path . '/_actions') . '" data-parent="' . h($path) . '" data-label="' . h($label . ' diğer yetkiler') . '"><td colspan="5"><div class="rol-extra-permissions"><strong>Diğer yetkiler</strong>';
            foreach ($extras as $kind => $action) {
                echo '<div class="rol-extra-item"><span>' . h((string)($action['label'] ?? $kind)) . '</span>' . render_matrix_control($action, $checked, $prefix, $label, (string)$kind, $used) . '</div>';
            }
            echo '</div></td></tr>';
        }
        if ($children) render_matrix_rows($children, $checked, $prefix, $used, $path, $depth + 1);
    }
}
function render_permission_grid(array $catalog, array $checked, string $prefix): void {
    global $scope_options;
    $checked = role_matrix_expand_permissions(beyan_expand_role_permissions($checked));
    $matrix = role_permission_matrix();
    $used = [];
    echo '<div class="rol-editor"><div class="rol-editor-tools"><label>Sayfa veya yetki ara<input type="search" class="form-control rol-search" placeholder="Ör. Yükleme raporları"></label><label>Bölüm<select class="form-control rol-section-filter"><option value="">Tüm bölümler</option>';
    foreach ($matrix as $group => $rows) echo '<option value="' . h($group) . '">' . h($group) . '</option>';
    echo '<option value="Diğer yetkiler">Diğer yetkiler</option></select></label><label>Görünüm<select class="form-control rol-state-filter"><option value="all">Tüm yetkiler</option><option value="selected">Seçili olanlar</option><option value="unselected">Seçili olmayanlar</option></select></label><button type="button" class="rol-reset-filters btn btn-ghost btn-sm">Temizle</button><strong class="rol-total" aria-live="polite"></strong></div>';
    echo '<p class="rol-matrix-intro">Sayfayı bulun, anahtarlarla yetki verin. Alt başlıkları ok düğmesiyle açın. Değişiklikler Kaydet ile uygulanır.</p><details class="rol-help"><summary>Yetkiler nasıl çalışır?</summary><p>Gör sayfaya erişimi, diğer sütunlar yapılabilen işlemleri belirler. — işareti bu satırda ilgili işlemin olmadığını gösterir. Alt başlıklar ayrı seçilir. İşlem yapabilmek için ilgili sayfanın Gör izni de gerekir. Raporlarda hem Raporlar hem ilgili alt raporun Gör iznini açın. Birden fazla rolün izinleri birleşir. Yöneticiye özel sayfalar bu ekrandan başka rollere açılamaz.</p></details>';
    echo '<input type="hidden" name="permissions[]" value="role.permissions.detailed"><input type="hidden" name="permissions[]" value="beyan.permissions.detailed">';
    $scope = role_data_scope_decode($checked);
    echo '<details class="rol-data-scope"><summary>Veri kapsamı <span class="rol-scope-summary"></span></summary><p>Depo seçimi; yükleme, paletleme, mal kabul, stok, maliyet, raporlar ve depo filtresi bulunan personel bölümlerini sınırlar. Birden fazla roldeki depo sınırları ve kullanıcının depo atamaları kesişir. Sistem yöneticisi bu sınırdan muaftır.</p><p>Beyanlar şirket genelinde kalır. Mailde hesap atamaları, Hesap bölümünde kişinin kendi kayıtları ve hesap yöneticisi kuralları geçerlidir. Depo sınırı seçildiğinde, deposu doğrulanamayan eski günlük rapor arşivleri açılamaz; güncel günlük rapor kullanılabilir.</p><label>Kapsam<select name="scope_mode" class="form-control rol-scope-mode"><option value="all"' . ($scope['mode'] === 'all' ? ' selected' : '') . '>Atandığı tüm depolar</option><option value="selected"' . ($scope['mode'] === 'selected' ? ' selected' : '') . '>Yalnız seçilen depolar</option></select></label><fieldset class="rol-scope-depots"><legend>İzin verilen depolar</legend>';
    foreach (($scope_options ?? []) as $depot) echo '<label><input type="checkbox" name="scope_depots[]" data-permission="' . h(role_data_scope_code($depot)) . '" value="' . h($depot) . '" ' . (in_array($depot, $scope['depots'], true) ? 'checked' : '') . '> ' . h($depot) . '</label>';
    echo '<p class="rol-scope-empty">Hiç depo seçilmezse bu rol depo kayıtlarına erişim vermez.</p></fieldset></details>';
    foreach ($matrix as $group => $rows) {
        echo '<section class="rol-perm-group" data-group="' . h($group) . '"><div class="rol-perm-group-head"><h3 class="rol-perm-group-title">' . h($group) . ' <small class="rol-group-count"></small></h3><button type="button" class="rol-expand-group btn btn-ghost btn-sm">Alt başlıkları aç</button></div><table class="rol-matrix-table"><caption class="rol-sr-only">' . h($group) . ' sayfa yetkileri</caption><thead><tr><th scope="col">Sayfa / alt başlık</th><th scope="col">Gör</th><th scope="col">Ekle</th><th scope="col">Düzenle</th><th scope="col">Sil</th></tr></thead><tbody>';
        render_matrix_rows($rows, $checked, $prefix, $used);
        echo '</tbody></table></section>';
    }
    $legacy = ['beyan.write','beyan.permissions.detailed','role.permissions.detailed','records.read','records.create','records.edit','records.delete','records.write','kantar.write','stok.write','stok.edit','maliyet.write','hesap.write','defs.write','siparis.read','siparis.create','siparis.edit','siparis.delete','siparis.write'];
    foreach (role_pdks_pages() as $policy) foreach ($policy['legacy'] as $legacy_pdks) $legacy[] = 'attendance.' . $legacy_pdks;
    $legacy = array_merge($legacy, ['attendance.read']);
    // Keep reserved legacy system privileges visible and revocable.
    $legacy = array_diff($legacy, ['attendance.admin','attendance.devices','attendance.manual','attendance.correct','attendance.report']);
    $remaining = [];
    foreach ($catalog as $perms) foreach ($perms as $perm => $label) {
        if (!isset($used[$perm]) && !in_array($perm, $legacy, true)) $remaining[] = ['key'=>$perm, 'label'=>$label, 'actions'=>['read'=>['permission'=>$perm, 'label'=>'Yetki ver']]];
    }
    if ($remaining) {
        echo '<section class="rol-perm-group" data-group="Diğer yetkiler"><div class="rol-perm-group-head"><h3 class="rol-perm-group-title">Diğer yetkiler <small class="rol-group-count"></small></h3></div><p class="rol-row-note">Ek işlem ve uyumluluk izinleri</p><table class="rol-matrix-table"><thead><tr><th scope="col">Yetki</th><th scope="col">İzin</th><th scope="col">Ekle</th><th scope="col">Düzenle</th><th scope="col">Sil</th></tr></thead><tbody>';
        render_matrix_rows($remaining, $checked, $prefix, $used);
        echo '</tbody></table></section>';
    }
    echo '<p class="rol-empty" hidden>Filtrelerle eşleşen sayfa veya yetki bulunamadı.</p></div>';
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
        $perms_post = array_merge(clean_permissions($_POST['permissions'] ?? [], $flat_permissions), role_data_scope_permissions($_POST, $scope_options));

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
        $perms_post = array_merge(clean_permissions($_POST['permissions'] ?? [], $flat_permissions), role_data_scope_permissions($_POST, $scope_options));

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
// Önce eski rolün etkin izinlerini görünür yap; ayrıntılı kayıtta örtük izin kalmasın.
if (function_exists('beyan_expand_role_permissions')) {
    foreach ($role_perm_map as &$role_permissions) $role_permissions = beyan_expand_role_permissions($role_permissions);
    unset($role_permissions);
}
foreach ($role_perm_map as &$role_permissions) {
    $role_permissions = array_values(array_filter($role_permissions, static fn($p) => !in_array($p, ['beyan.write', 'beyan.permissions.detailed'], true)));
}
unset($role_permissions);

$total_perm_count = count(array_diff_key($flat_permissions, array_flip(['beyan.write', 'beyan.permissions.detailed'])));
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
    $r_perms   = role_matrix_expand_permissions($role_perm_map[$rid] ?? []);
    $ucount    = (int)$r['user_count'];
?>
<tr>
    <td style="color:var(--muted);font-size:.8rem"><?= $rid ?></td>
    <td>
        <strong><?= h($r['label']) ?></strong>
        <span class="rol-badge-slug"><?= h($r['slug']) ?></span>
        <?php if ($is_system): ?><span class="rol-badge-system">Sistem</span><?php endif; ?>
    </td>
    <td class="rol-perm-count"><?= count(array_filter($r_perms, static fn($p) => !str_starts_with($p, 'scope.') && !str_ends_with($p, '.detailed'))) ?> / <?= $total_perm_count ?></td>
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
    $r_perms   = role_matrix_expand_permissions($role_perm_map[$rid] ?? []);
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
<div class="pm-dialog rol-dialog" role="dialog" aria-modal="true" aria-label="Yeni rol">
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
        <?php
        // Yeni rol VARSAYILANI: ana sayfa. POST hatasından sonra kullanıcının
        // kendi seçimi korunur (varsayılana geri dönmez).
        $c_perms_post = ($action === 'create_role')
            ? array_merge(clean_permissions($_POST['permissions'] ?? [], $flat_permissions), role_data_scope_permissions($_POST, $scope_options))
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
<div class="pm-dialog rol-dialog" role="dialog" aria-modal="true" aria-label="Rolü düzenle">
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
    var modalOpener = null;
    function openModal(id) {
        modalOpener = document.activeElement;
        document.getElementById(id).removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
        document.querySelector('#' + id + ' input[name="label"]').focus();
        refreshEditor(document.querySelector('#' + id + ' .rol-editor'));
    }
    function closeModal(id) {
        document.getElementById(id).setAttribute('hidden', '');
        document.body.style.overflow = '';
        if (modalOpener && modalOpener.focus) modalOpener.focus();
    }

    ['rolCreateModal', 'rolEditModal'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('click', function (e) {
            if (e.target === this) closeModal(id);
        });
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.preventDefault(); closeModal(id); return; }
            if (e.key !== 'Tab') return;
            var items = Array.from(el.querySelectorAll('button,input,select,a[href]')).filter(function (item) {
                return !item.disabled && item.getClientRects().length > 0;
            });
            var first = items[0], last = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        });
    });

    function inputsIn(editor) { return Array.from(editor.querySelectorAll('input[name="permissions[]"][type="checkbox"]')); }
    function refreshEditor(editor) {
        if (!editor) return;
        var query = editor.querySelector('.rol-search').value.toLocaleLowerCase('tr').trim();
        var section = editor.querySelector('.rol-section-filter').value;
        var state = editor.querySelector('.rol-state-filter').value;
        var filtered = !!query || state !== 'all';
        var total = new Set(inputsIn(editor).filter(function (cb) { return cb.checked; }).map(function (cb) { return cb.value; })).size;
        var visibleGroups = 0;
        editor.querySelectorAll('.rol-perm-group').forEach(function (group) {
            var groupName = group.dataset.group;
            var rows = Array.from(group.querySelectorAll('tbody > tr'));
            var matches = new Set(), selected = new Set();
            var byPath = new Map(rows.map(function (row) { return [row.dataset.path, row]; }));
            rows.forEach(function (row) {
                var cbs = Array.from(row.querySelectorAll('input[type="checkbox"]'));
                cbs.forEach(function (cb) { if (cb.checked) selected.add(cb.value); });
                var haystack = groupName + ' ' + row.dataset.label + ' ' + row.textContent + ' ' + cbs.map(function (cb) { return cb.value; }).join(' ');
                var matching = haystack.toLocaleLowerCase('tr').includes(query) && (state === 'all' || cbs.some(function (cb) { return state === 'selected' ? cb.checked : !cb.checked; }));
                if (matching) {
                    matches.add(row.dataset.path);
                    var parent = row.dataset.parent;
                    while (parent && byPath.has(parent)) { matches.add(parent); parent = byPath.get(parent).dataset.parent; }
                }
            });
            var visible = 0;
            rows.forEach(function (row) {
                var show = (!section || section === groupName) && matches.has(row.dataset.path);
                if (show && !filtered) {
                    var parent = row.dataset.parent;
                    while (parent && byPath.has(parent)) {
                        var parentRow = byPath.get(parent), button = parentRow.querySelector('.rol-row-expand');
                        if (button && button.getAttribute('aria-expanded') !== 'true') { show = false; break; }
                        parent = parentRow.dataset.parent;
                    }
                }
                row.hidden = !show;
                if (show) visible++;
            });
            group.querySelector('.rol-group-count').textContent = selected.size + ' seçili';
            group.hidden = visible === 0;
            if (visible) visibleGroups++;
        });
        editor.querySelector('.rol-total').textContent = total + ' yetki seçili';
        editor.querySelector('.rol-empty').hidden = visibleGroups !== 0;
        var scoped = editor.querySelector('.rol-scope-mode').value === 'selected';
        editor.querySelector('.rol-scope-depots').hidden = !scoped;
        editor.querySelector('.rol-scope-depots').disabled = !scoped;
        editor.querySelector('.rol-scope-summary').textContent = scoped ? 'Seçili depolar' : 'Atandığı tüm depolar';
    }
    document.querySelectorAll('.rol-editor').forEach(function (editor) {
        editor.addEventListener('input', function (event) {
            if (event.target.matches('input[name="permissions[]"][type="checkbox"]')) {
                inputsIn(editor).forEach(function (cb) { if (cb.value === event.target.value) cb.checked = event.target.checked; });
            }
            refreshEditor(editor);
        });
        editor.addEventListener('change', function () { refreshEditor(editor); });
        editor.addEventListener('click', function (event) {
            var expand = event.target.closest('.rol-row-expand');
            if (expand) {
                var expanded = expand.getAttribute('aria-expanded') === 'true';
                expand.setAttribute('aria-expanded', String(!expanded));
                expand.setAttribute('aria-label', expand.closest('tr').dataset.label + (expanded ? ' alt başlıklarını aç' : ' alt başlıklarını kapat'));
            }
            var groupButton = event.target.closest('.rol-expand-group');
            if (groupButton) {
                var buttons = Array.from(groupButton.closest('.rol-perm-group').querySelectorAll('.rol-row-expand'));
                var open = buttons.some(function (button) { return button.getAttribute('aria-expanded') !== 'true'; });
                buttons.forEach(function (button) { button.setAttribute('aria-expanded', String(open)); });
                groupButton.textContent = open ? 'Alt başlıkları kapat' : 'Alt başlıkları aç';
            }
            if (event.target.closest('.rol-reset-filters')) {
                editor.querySelector('.rol-search').value = '';
                editor.querySelector('.rol-section-filter').value = '';
                editor.querySelector('.rol-state-filter').value = 'all';
            }
            refreshEditor(editor);
        });
        refreshEditor(editor);
    });

    window.rolOpenCreate  = function () { openModal('rolCreateModal'); };
    window.rolCloseCreate = function () { closeModal('rolCreateModal'); };

    window.rolOpenEdit = function (data) {
        document.getElementById('e_role_id').value    = data.id;
        document.getElementById('e_role_label').value = data.label;
        document.getElementById('e_role_slug').textContent = data.slug;

        var perms = data.permissions || [];
        document.querySelectorAll('#e_perm_wrap input[name="permissions[]"][type=checkbox]').forEach(function (cb) {
            cb.checked = perms.indexOf(cb.value) !== -1;
        });

        var editor = document.querySelector('#e_perm_wrap .rol-editor');
        editor.querySelector('.rol-scope-mode').value = perms.indexOf('scope.depots.selected') !== -1 ? 'selected' : 'all';
        editor.querySelectorAll('input[name="scope_depots[]"]').forEach(function (cb) { cb.checked = perms.indexOf(cb.dataset.permission) !== -1; });
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
        permissions: <?= json_encode(array_merge(clean_permissions($_POST['permissions'] ?? [], $flat_permissions), role_data_scope_permissions($_POST, $scope_options))) ?>,
    });
    <?php endif; ?>
})();
</script>

<?php render_footer(); ?>
