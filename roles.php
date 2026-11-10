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
    if (is_string($stored_permission) && !str_starts_with($stored_permission, 'scope.') && !str_starts_with($stored_permission, 'role.catalog.') && !isset($flat_permissions[$stored_permission])) {
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

// ── v507: iki panelli rol düzenleyici ─────────────────────────────────────
// Solda sayfa listesi, sağda seçili sayfanın izinleri (sayfa + kartlar Gör/Ekle/Düzenle/Sil
// tablosu, ek işlemler, içerik = kart/düğme anahtarları). Tüm kontroller gerçek izin kodunu
// `permissions[]` ile gönderir; işaretli durumu JS doldurur (tek pencere: oluştur + düzenle + kopyala).
function rolx_switch(string $perm, string $aria, string $prefix, array &$used, bool $salt = false): string {
    $used[$perm] = true;
    static $say = 0; $say++;
    $id = $prefix . '_p' . $say;
    return '<label class="rol-switch" for="' . h($id) . '" title="' . h($aria) . '"><input type="checkbox" role="switch" id="' . h($id) . '" name="permissions[]" value="' . h($perm) . '" aria-label="' . h($aria) . '"'
        . ($salt ? ' data-salt="1"' : '') . '><span class="rol-switch-track" aria-hidden="true"></span></label>';
}
/** Sayfa + alt kartlar için Gör/Ekle/Düzenle/Sil tablosunun satırları (özyinelemeli). Ek işlemleri $ekler'e toplar. */
function rolx_tablo_satirlari(array $row, string $prefix, array &$used, array &$ekler, string $ust = '', int $derinlik = 0): string {
    $kolonlar = ['read' => 'Gör', 'create' => 'Ekle', 'edit' => 'Düzenle', 'delete' => 'Sil'];
    $key = (string)$row['key'];
    $path = $ust === '' ? $key : $ust . '/' . $key;
    $label = (string)$row['label'];
    $actions = (array)($row['actions'] ?? []);
    $adminOnly = !empty($row['admin_only']);
    $o = '<tr class="rolx-satir' . ($derinlik === 0 ? ' rolx-satir-sayfa' : '') . '" data-path="' . h($path) . '" data-label="' . h($label) . '" data-derinlik="' . $derinlik . '">';
    $o .= '<th scope="row"><div class="rolx-satir-ad" style="--rolx-d:' . $derinlik . '"><span class="rolx-satir-baslik">' . h($derinlik === 0 ? 'Sayfanın kendisi' : $label) . '</span>';
    if ($derinlik === 0) $o .= '<small>' . h($label) . ' sayfasına giriş ve genel işlemler</small>';
    elseif ($adminOnly) $o .= '<small>Yalnız sistem yöneticisi</small>';
    elseif (!empty($row['note'])) $o .= '<small>' . h((string)$row['note']) . '</small>';
    $o .= '</div></th>';
    foreach ($kolonlar as $tur => $ad) {
        $o .= '<td data-column="' . h($ad) . '">';
        if (isset($actions[$tur]) && !$adminOnly) {
            $etiket = trim((string)($actions[$tur]['label'] ?? ''));
            $o .= rolx_switch((string)$actions[$tur]['permission'], $label . ' — ' . ($etiket !== '' ? $etiket : $ad), $prefix, $used, $tur === 'read');
            if ($etiket !== '') $o .= '<small class="rolx-hucre-not">' . h($etiket) . '</small>';
        } else {
            $o .= '<span class="rol-unavailable" aria-label="Bu işlem bu satırda yok">—</span>';
        }
        $o .= '</td>';
    }
    $o .= '</tr>';
    if (!$adminOnly) foreach (array_diff_key($actions, $kolonlar) as $tur => $action) {
        $ekler[] = ['perm' => (string)$action['permission'], 'ad' => (string)(($action['label'] ?? '') !== '' ? $action['label'] : $tur), 'yer' => $derinlik === 0 ? '' : $label, 'salt' => $tur === 'export'];
    }
    foreach ((array)($row['children'] ?? []) as $child) $o .= rolx_tablo_satirlari($child, $prefix, $used, $ekler, $path, $derinlik + 1);
    return $o;
}
/** Bir sayfanın paneli. */
function rolx_panel(string $grup, array $row, array $icerik, string $prefix, array &$used): string {
    $key = (string)$row['key'];
    $ekler = [];
    $satirlar = rolx_tablo_satirlari($row, $prefix, $used, $ekler);
    $okuma = (string)($row['actions']['read']['permission'] ?? '');
    $ara = [$row['label'], $grup];
    $o = '<section class="rolx-panel" data-sayfa="' . h($key) . '" data-grup="' . h($grup) . '" data-okuma="' . h($okuma) . '" hidden>';
    $o .= '<header class="rolx-panel-bas"><div><h3>' . h((string)$row['label']) . ' <span class="rolx-durum" data-durum></span></h3>';
    if (!empty($row['note'])) $o .= '<p>' . h((string)$row['note']) . '</p>';
    $o .= '</div><div class="rolx-hazir" role="group" aria-label="Hazır ayarlar"><button type="button" class="btn btn-sm" data-hazir="tam">Tam yetki</button><button type="button" class="btn btn-sm" data-hazir="salt">Yalnız görüntüleme</button><button type="button" class="btn btn-sm btn-ghost" data-hazir="kapat">Kapat</button></div></header>';
    $o .= '<p class="rolx-kapali-not" hidden>Bu sayfanın <strong>Gör</strong> izni kapalı: aşağıdaki izinler sayfa açılmadan işe yaramaz.</p>';
    $o .= '<h4 class="rolx-alt-bas">Sayfa ve bölümler</h4><div class="rolx-tablo-kap"><table class="rolx-tablo"><thead><tr><th scope="col">Sayfa / bölüm</th><th scope="col">Gör</th><th scope="col">Ekle</th><th scope="col">Düzenle</th><th scope="col">Sil</th></tr></thead><tbody>' . $satirlar . '</tbody></table></div>';
    if ($ekler) {
        $o .= '<h4 class="rolx-alt-bas">Ek işlemler</h4><div class="rolx-liste">';
        foreach ($ekler as $e) {
            $ara[] = $e['ad'];
            $o .= '<div class="rolx-oge"><span class="rolx-oge-metin"><strong>' . h($e['ad']) . '</strong>' . ($e['yer'] !== '' ? '<small>' . h($e['yer']) . '</small>' : '') . '</span>' . rolx_switch($e['perm'], $row['label'] . ' — ' . $e['ad'], $prefix, $used, $e['salt']) . '</div>';
        }
        $o .= '</div>';
    }
    foreach ($icerik as $grupAd => $ogeler) {
        $o .= '<h4 class="rolx-alt-bas">İçerik · ' . h((string)$grupAd) . '</h4><div class="rolx-liste">';
        foreach ($ogeler as $perm => $oge) {
            $ara[] = $oge['ad'];
            $o .= '<div class="rolx-oge" data-gerek="' . h(json_encode(array_values($oge['gerek']))) . '"><span class="rolx-oge-metin"><strong>' . h($oge['ad']) . '</strong>'
                . ($oge['not'] !== '' ? '<small>' . h($oge['not']) . '</small>' : '') . '<small class="rolx-gerek-not" hidden>Ön koşul izni kapalı — tek başına etkisiz.</small></span>'
                . '<span class="rolx-tur rolx-tur-' . h($oge['tur']) . '">' . ($oge['tur'] === 'kart' ? 'Kart' : 'Düğme') . '</span>'
                . rolx_switch((string)$perm, $row['label'] . ' — ' . $oge['ad'], $prefix, $used, !empty($oge['salt'])) . '</div>';
        }
        $o .= '</div>';
    }
    $topla = static function (array $r) use (&$topla, &$ara): void { foreach ((array)($r['children'] ?? []) as $c) { $ara[] = $c['label']; $topla($c); } };
    $topla($row);
    return str_replace('data-okuma=', 'data-ara="' . h(mb_strtolower(implode(' ', array_map('strval', $ara)), 'UTF-8')) . '" data-okuma=', $o) . '</section>';
}
/** Düzenleyicinin tamamı: sol gezinme + paneller. */
function render_permission_grid(array $catalog, array $checked, string $prefix): void {
    global $scope_options;
    $matrix = role_permission_matrix();
    $icerikKatalog = role_content_catalog();
    $used = [];
    $nav = []; $paneller = ''; $yonetici = [];
    foreach ($matrix as $grup => $rows) {
        foreach ($rows as $row) {
            if (!empty($row['admin_only'])) { $yonetici[$grup][] = (string)$row['label']; continue; }
            $nav[$grup][] = ['key' => (string)$row['key'], 'label' => (string)$row['label']];
            $paneller .= rolx_panel((string)$grup, $row, (array)($icerikKatalog[$row['key']] ?? []), $prefix, $used);
        }
    }
    // Katalogda olup matriste yeri olmayan izinler (uyumluluk + saklı ek izinler)
    $gizli = ['beyan.write','beyan.permissions.detailed','role.permissions.detailed','role.sections.detailed','records.read','records.create','records.edit','records.delete','records.write','kantar.write','stok.write','stok.edit','maliyet.write','hesap.write','defs.write','siparis.read','siparis.create','siparis.edit','siparis.delete','siparis.write','siparis.ogl.create','siparis.ogl.edit','siparis.ogl.delete','beyan.edit','beyan.delete','beyan.yukleme_sil','hks.access','attendance.read'];
    foreach (role_pdks_pages() as $policy) foreach ($policy['legacy'] as $legacy_pdks) $gizli[] = 'attendance.' . $legacy_pdks;
    // Ayrılmış eski sistem ayrıcalıkları görünür ve geri alınabilir kalır.
    $gizli = array_diff($gizli, ['attendance.admin','attendance.devices','attendance.manual','attendance.correct','attendance.report']);
    $kalan = '';
    foreach ($catalog as $perms) foreach ($perms as $perm => $label) {
        if (isset($used[$perm]) || in_array($perm, $gizli, true) || str_starts_with((string)$perm, 'role.catalog.')) continue;
        $kalan .= '<div class="rolx-oge"><span class="rolx-oge-metin"><strong>' . h((string)$label) . '</strong><small>' . h((string)$perm) . '</small></span>' . rolx_switch((string)$perm, (string)$label, $prefix, $used) . '</div>';
    }
    $scope = role_data_scope_decode($checked);

    echo '<div class="rol-editor rolx" data-varsayilan="' . h(json_encode(['dashboard.read', 'ui.dashboard.rozetler'])) . '">';
    echo '<input type="hidden" name="permissions[]" value="role.permissions.detailed"><input type="hidden" name="permissions[]" value="beyan.permissions.detailed"><input type="hidden" name="permissions[]" value="role.sections.detailed"><input type="hidden" name="permissions[]" value="role.catalog.' . ROLE_CATALOG_VERSION . '">';
    // ── sol gezinme ──
    echo '<aside class="rolx-nav" aria-label="Sayfalar"><label class="rolx-ara"><span class="rol-sr-only">Sayfa veya yetki ara</span><input type="search" class="form-control rolx-ara-kutu" placeholder="Sayfa veya yetki ara…" autocomplete="off"></label>';
    echo '<label class="rolx-nav-sec-kap"><span class="rol-sr-only">Sayfa seç</span><select class="form-control rolx-nav-sec"></select></label>';
    echo '<nav class="rolx-nav-liste"><button type="button" class="rolx-nav-oge" data-git="kapsam"><span class="rolx-nav-ad">Veri kapsamı (depo)</span><span class="rolx-nav-durum" data-kapsam-ozet></span></button>';
    foreach ($nav as $grup => $ogeler) {
        echo '<div class="rolx-nav-grup" data-grup="' . h((string)$grup) . '"><div class="rolx-nav-bas">' . h((string)$grup) . '</div>';
        foreach ($ogeler as $o) echo '<button type="button" class="rolx-nav-oge" data-git="' . h($o['key']) . '"><span class="rolx-nav-ad">' . h($o['label']) . '</span><span class="rolx-nav-durum" data-durum></span></button>';
        echo '</div>';
    }
    if ($kalan !== '') echo '<div class="rolx-nav-grup" data-grup="Diğer"><div class="rolx-nav-bas">Diğer</div><button type="button" class="rolx-nav-oge" data-git="diger"><span class="rolx-nav-ad">Diğer yetkiler</span><span class="rolx-nav-durum" data-durum></span></button></div>';
    if ($yonetici) echo '<button type="button" class="rolx-nav-oge rolx-nav-kilit" data-git="yonetici"><span class="rolx-nav-ad">Yöneticiye özel sayfalar</span><span class="rolx-nav-durum">kilitli</span></button>';
    echo '</nav><p class="rolx-nav-bos" hidden>Aramayla eşleşen sayfa yok.</p></aside>';
    // ── sağ: paneller ──
    echo '<div class="rolx-govde"><div class="rolx-ust"><strong class="rol-total" aria-live="polite"></strong><span class="rolx-degisti" hidden>Kaydedilmemiş değişiklik var</span>';
    echo '<label class="rolx-kopya"><span>Başka rolden kopyala</span><select class="form-control rolx-kopya-sec"><option value="">Rol seçin…</option></select></label></div>';
    // Veri kapsamı
    echo '<section class="rolx-panel rolx-kapsam" data-sayfa="kapsam" data-ara="veri kapsamı depo" hidden><header class="rolx-panel-bas"><div><h3>Veri kapsamı (depo)</h3><p>Depo seçimi; yükleme, paletleme, mal kabul, stok, maliyet, raporlar ve depo filtresi bulunan personel bölümlerini sınırlar. Birden fazla roldeki depo sınırları ve kullanıcının depo atamaları kesişir. Sistem yöneticisi bu sınırdan muaftır.</p></div></header>';
    echo '<p class="rolx-bilgi">Beyanlar şirket genelinde kalır. Mailde hesap atamaları, Hesap bölümünde kişinin kendi kayıtları ve hesap yöneticisi kuralları geçerlidir. Depo sınırı seçildiğinde, deposu doğrulanamayan eski günlük rapor arşivleri açılamaz; güncel günlük rapor kullanılabilir.</p>';
    echo '<label class="rolx-kapsam-sec">Kapsam<select name="scope_mode" class="form-control rol-scope-mode"><option value="all"' . ($scope['mode'] === 'all' ? ' selected' : '') . '>Atandığı tüm depolar</option><option value="selected"' . ($scope['mode'] === 'selected' ? ' selected' : '') . '>Yalnız seçilen depolar</option></select></label><fieldset class="rol-scope-depots"><legend>İzin verilen depolar</legend>';
    foreach (($scope_options ?? []) as $depot) echo '<label><input type="checkbox" name="scope_depots[]" data-permission="' . h(role_data_scope_code($depot)) . '" value="' . h($depot) . '" ' . (in_array($depot, $scope['depots'], true) ? 'checked' : '') . '> ' . h($depot) . '</label>';
    echo '<p class="rol-scope-empty">Hiç depo seçilmezse bu rol depo kayıtlarına erişim vermez.</p></fieldset></section>';
    echo $paneller;
    if ($kalan !== '') echo '<section class="rolx-panel" data-sayfa="diger" data-ara="diğer yetkiler ek uyumluluk" hidden><header class="rolx-panel-bas"><div><h3>Diğer yetkiler <span class="rolx-durum" data-durum></span></h3><p>Sayfa tablosunda yeri olmayan ek işlem ve uyumluluk izinleri.</p></div></header><div class="rolx-liste">' . $kalan . '</div></section>';
    if ($yonetici) {
        echo '<section class="rolx-panel" data-sayfa="yonetici" data-ara="yöneticiye özel sistem yöneticisi" hidden><header class="rolx-panel-bas"><div><h3>Yöneticiye özel sayfalar</h3><p>Bu sayfalar yalnız <strong>Sistem Yöneticisi</strong> rolüne açıktır; başka rollere bu ekrandan verilemez.</p></div></header>';
        foreach ($yonetici as $grup => $adlar) echo '<h4 class="rolx-alt-bas">' . h((string)$grup) . '</h4><p class="rolx-yonetici-liste">' . h(implode(' · ', $adlar)) . '</p>';
        echo '</section>';
    }
    echo '</div></div>';
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

// Sabit 5 yerine GERÇEKTEN var olan sistem rolünü say — eksik kurulumda
// "Özel Rol" sayısı eksiye düşüyordu.
$sistem_rol_sayisi = count(array_filter($roles, fn($r) => in_array($r['slug'], $protected_slugs, true)));

// Role atanmış kullanıcı adları (liste sütunu). users tablosu yoksa/okunamazsa yalnız sayı gösterilir.
$rol_kullanicilar = [];
try {
    foreach ($pdo->query("SELECT ur.role_id, COALESCE(NULLIF(u.display_name, ''), u.username) AS ad FROM user_roles ur JOIN users u ON u.id = ur.user_id ORDER BY ad")->fetchAll() as $__uk) {
        $rol_kullanicilar[(int)$__uk['role_id']][] = (string)$__uk['ad'];
    }
} catch (PDOException $__e) { $rol_kullanicilar = []; }

// Her rolün genişletilmiş izinleri + sayfa özeti (liste ve düzenleyici aynı veriyi kullanır).
$rol_veri = [];
foreach ($roles as $r) {
    $rid = (int)$r['id'];
    $genis = role_matrix_expand_permissions($role_perm_map[$rid] ?? []);
    $rol_veri[$rid] = [
        'id' => $rid, 'label' => (string)$r['label'], 'slug' => (string)$r['slug'],
        'sistem' => in_array($r['slug'], $protected_slugs, true),
        'permissions' => array_values($genis),
        'ozet' => role_ozet($genis),
    ];
}
$duzey_ad = ['kapali' => 'Kapalı', 'salt' => 'Görüntüleme', 'kismi' => 'Kısmi', 'tam' => 'Tam'];
$rol_cipleri = static function (array $ozet) use ($duzey_ad): string {
    $acik = array_values(array_filter($ozet, static fn($o) => $o['duzey'] !== 'kapali'));
    if (!$acik) return '<span class="rolx-cip rolx-cip-kapali">Hiçbir sayfa açık değil</span>';
    $o = '';
    foreach (array_slice($acik, 0, 8) as $x) $o .= '<span class="rolx-cip rolx-cip-' . h($x['duzey']) . '" title="' . h($duzey_ad[$x['duzey']] . ' · ' . $x['acik'] . ' / ' . $x['toplam'] . ' izin') . '">' . h($x['label']) . '</span>';
    if (count($acik) > 8) $o .= '<span class="rolx-cip rolx-cip-fazla">+' . (count($acik) - 8) . '</span>';
    return $o;
};
$rol_kisiler = static function (int $rid, int $say) use ($rol_kullanicilar): string {
    $adlar = $rol_kullanicilar[$rid] ?? [];
    if (!$adlar) return $say > 0 ? $say . ' kullanıcı' : '<span class="muted">—</span>';
    $o = h(implode(', ', array_slice($adlar, 0, 3)));
    if (count($adlar) > 3) $o .= ' <span class="muted">+' . (count($adlar) - 3) . '</span>';
    return $o;
};

// POST hatası sonrası pencere eski değerlerle yeniden açılır.
$rol_hata_veri = null;
if ($error !== '' && in_array($action, ['create_role', 'update_role'], true)) {
    $rol_hata_veri = [
        'kip' => $action === 'create_role' ? 'yeni' : 'duzenle',
        'id' => (int)($_POST['id'] ?? 0),
        'label' => trim((string)($_POST['label'] ?? '')),
        'slug' => (string)($rol_veri[(int)($_POST['id'] ?? 0)]['slug'] ?? ''),
        'permissions' => array_merge(clean_permissions($_POST['permissions'] ?? [], $flat_permissions), role_data_scope_permissions($_POST, $scope_options)),
    ];
}

render_header('Roller');
?>
<div class="page-head">
    <div>
        <h1 style="font-size:1.3rem;font-weight:700;margin:0">Rol Yönetimi</h1>
        <p class="muted" style="margin:4px 0 0;font-size:.85rem"><?= count($roles) ?> rol · <?= $sistem_rol_sayisi ?> sistem · <?= count($roles) - $sistem_rol_sayisi ?> özel. Kullanıcıya rol atamak için <a href="users.php">Kullanıcı Yönetimi</a>.</p>
    </div>
    <div class="page-head-actions">
        <button type="button" class="btn btn-primary" data-rol-yeni>+ Yeni Rol</button>
    </div>
</div>

<?php if ($success !== ''): ?>
<div class="rolx-flash rolx-flash-ok" role="status"><?= h($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
<div class="rolx-flash rolx-flash-hata" role="alert"><?= h($error) ?></div>
<?php endif; ?>

<p class="rolx-lejant"><span class="rolx-cip rolx-cip-tam">Tam</span> tüm izinler · <span class="rolx-cip rolx-cip-kismi">Kısmi</span> bazı işlemler · <span class="rolx-cip rolx-cip-salt">Görüntüleme</span> yalnız görür</p>

<!-- Masaüstü tablo -->
<div class="table-wrap rolx-tablo-liste">
<table class="data-table">
<thead>
<tr>
    <th>Rol</th>
    <th>Erişebildiği sayfalar</th>
    <th>Kullanıcılar</th>
    <th></th>
</tr>
</thead>
<tbody>
<?php foreach ($roles as $r): ?>
<?php
    $rid       = (int)$r['id'];
    $is_system = $rol_veri[$rid]['sistem'];
    $ucount    = (int)$r['user_count'];
?>
<tr>
    <td>
        <strong><?= h($r['label']) ?></strong>
        <?php if ($is_system): ?><span class="rol-badge-system">Sistem</span><?php endif; ?>
        <div><span class="rol-badge-slug"><?= h($r['slug']) ?></span></div>
    </td>
    <td><div class="rolx-cipler"><?= $r['slug'] === 'admin' ? '<span class="rolx-cip rolx-cip-tam">Tüm sayfalar (sistem yöneticisi)</span>' : $rol_cipleri($rol_veri[$rid]['ozet']) ?></div></td>
    <td class="rolx-kisiler"><?= $rol_kisiler($rid, $ucount) ?></td>
    <td>
        <div class="rolx-eylemler">
            <button type="button" class="btn btn-sm btn-primary" data-rol-duzenle="<?= $rid ?>">Düzenle</button>
            <button type="button" class="btn btn-sm btn-ghost" data-rol-kopyala="<?= $rid ?>" title="Bu rolün izinleriyle yeni rol aç">Kopyala</button>
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
<div class="rolx-kartlar">
<?php foreach ($roles as $r): ?>
<?php
    $rid       = (int)$r['id'];
    $is_system = $rol_veri[$rid]['sistem'];
    $ucount    = (int)$r['user_count'];
?>
<div class="rol-card">
    <div class="rol-card-name"><?= h($r['label']) ?>
        <?php if ($is_system): ?><span class="rol-badge-system">Sistem</span><?php endif; ?>
    </div>
    <div class="rol-badge-slug" style="margin-left:0"><?= h($r['slug']) ?></div>
    <div class="rolx-cipler" style="margin-top:8px"><?= $r['slug'] === 'admin' ? '<span class="rolx-cip rolx-cip-tam">Tüm sayfalar (sistem yöneticisi)</span>' : $rol_cipleri($rol_veri[$rid]['ozet']) ?></div>
    <div class="rolx-kisiler" style="margin-top:6px"><?= $rol_kisiler($rid, $ucount) ?></div>
    <div class="rolx-eylemler" style="margin-top:10px">
        <button type="button" class="btn btn-sm btn-primary" data-rol-duzenle="<?= $rid ?>">Düzenle</button>
        <button type="button" class="btn btn-sm btn-ghost" data-rol-kopyala="<?= $rid ?>">Kopyala</button>
        <form method="post" style="margin:0" onsubmit="return confirm('Rol silinsin mi? Bu işlem geri alınamaz.')">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_role">
            <input type="hidden" name="id" value="<?= $rid ?>">
            <button type="submit" class="btn btn-sm btn-danger" <?= ($is_system || $ucount > 0) ? 'disabled' : '' ?>>Sil</button>
        </form>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php if (empty($roles)): ?>
<p style="color:var(--muted);text-align:center;padding:32px">Henüz rol yok.</p>
<?php endif; ?>

<!-- ── Rol penceresi (oluştur · düzenle · kopyala) ────────────────────── -->
<div id="rolModal" class="pm-overlay" hidden>
<div class="pm-dialog rol-dialog rolx-dialog" role="dialog" aria-modal="true" aria-labelledby="rolModalBaslik">
    <div class="pm-header">
        <h2 class="pm-title" id="rolModalBaslik">Yeni Rol</h2>
        <button type="button" class="pm-close" data-rol-kapat aria-label="Kapat">✕</button>
    </div>
    <form method="post" id="rolForm">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" id="rolAction" value="create_role">
    <input type="hidden" name="id" id="rolId" value="">
    <div class="pm-body rolx-body">
        <div class="rolx-kimlik">
            <label class="form-label" for="rolLabel">Rol Adı *</label>
            <input class="form-control" type="text" id="rolLabel" name="label" required maxlength="80" placeholder="ör. Depo Sorumlusu">
            <p class="rolx-slug-not" id="rolSlugNot" hidden>Sistem kimliği: <span id="rolSlug" class="rol-badge-slug"></span> (değiştirilemez)</p>
        </div>
        <?php render_permission_grid($catalog, [], 'r'); ?>
    </div>
    <div class="pm-footer">
        <button type="button" class="btn btn-ghost" data-rol-kapat>İptal</button>
        <button type="submit" class="btn btn-primary" id="rolKaydet">Kaydet</button>
    </div>
    </form>
</div>
</div>

<script type="application/json" id="rolVeri"><?= json_encode(['roller' => array_values(array_map(static fn($v) => ['id' => $v['id'], 'label' => $v['label'], 'slug' => $v['slug'], 'permissions' => $v['permissions']], $rol_veri)), 'hata' => $rol_hata_veri], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
(function () {
    'use strict';
    var modal = document.getElementById('rolModal');
    var form = document.getElementById('rolForm');
    var editor = modal.querySelector('.rolx');
    var veri = JSON.parse(document.getElementById('rolVeri').textContent || '{}');
    var roller = veri.roller || [];
    var DUZEY = { kapali: 'Kapalı', salt: 'Görüntüleme', kismi: 'Kısmi', tam: 'Tam' };
    var acan = null, ilkDurum = '', aktif = '';

    function tr(s) { return String(s || '').toLocaleLowerCase('tr'); }
    function kutular(kok) { return Array.prototype.slice.call((kok || editor).querySelectorAll('input[name="permissions[]"][type="checkbox"]')); }
    function seciliDegerler() {
        var s = {};
        kutular().forEach(function (cb) { if (cb.checked) s[cb.value] = true; });
        return s;
    }
    function durumMetni() {
        var d = Object.keys(seciliDegerler()).sort();
        d.push('#' + editor.querySelector('.rol-scope-mode').value);
        editor.querySelectorAll('input[name="scope_depots[]"]').forEach(function (cb) { if (cb.checked) d.push('@' + cb.value); });
        d.push('~' + document.getElementById('rolLabel').value);
        return d.join('|');
    }
    function izinleriUygula(perms) {
        var var_ = {};
        (perms || []).forEach(function (p) { var_[p] = true; });
        kutular().forEach(function (cb) { cb.checked = !!var_[cb.value]; });
        editor.querySelector('.rol-scope-mode').value = var_['scope.depots.selected'] ? 'selected' : 'all';
        editor.querySelectorAll('input[name="scope_depots[]"]').forEach(function (cb) { cb.checked = !!var_[cb.dataset.permission]; });
    }

    // ── durum rozetleri, ön koşul, toplam ──
    function panelDurumu(panel, secili) {
        var gorulen = {}, acik = 0, toplam = 0, saltDisi = false;
        kutular(panel).forEach(function (cb) {
            if (gorulen[cb.value]) return;
            gorulen[cb.value] = true; toplam++;
            if (!secili[cb.value]) return;
            acik++;
            if (cb.dataset.salt !== '1') saltDisi = true;
        });
        var okuma = panel.dataset.okuma || '';
        var gor = okuma ? !!secili[okuma] : acik > 0;
        var duzey = (!gor || acik === 0) ? 'kapali' : (acik === toplam ? 'tam' : (saltDisi ? 'kismi' : 'salt'));
        return { acik: acik, toplam: toplam, duzey: duzey, gor: gor, okuma: okuma };
    }
    function rozetYaz(el, d) {
        if (!el) return;
        el.textContent = DUZEY[d.duzey] + (d.duzey === 'kismi' || d.duzey === 'salt' ? ' · ' + d.acik + '/' + d.toplam : '');
        el.className = el.className.replace(/\s*rolx-d-\w+/g, '') + ' rolx-d-' + d.duzey;
    }
    function yenile() {
        var secili = seciliDegerler();
        editor.querySelectorAll('.rolx-panel').forEach(function (panel) {
            var ad = panel.dataset.sayfa;
            if (ad === 'kapsam' || ad === 'yonetici') return;
            var d = panelDurumu(panel, secili);
            rozetYaz(panel.querySelector('.rolx-panel-bas [data-durum]'), d);
            rozetYaz(editor.querySelector('.rolx-nav-oge[data-git="' + ad + '"] [data-durum]'), d);
            var not = panel.querySelector('.rolx-kapali-not');
            if (not) not.hidden = !(d.okuma && !d.gor && d.acik > 0);
        });
        editor.querySelectorAll('.rolx-oge[data-gerek]').forEach(function (oge) {
            var gerek = [];
            try { gerek = JSON.parse(oge.dataset.gerek) || []; } catch (e) { gerek = []; }
            var tamam = !gerek.length || gerek.some(function (g) { return !!secili[g]; });
            oge.classList.toggle('rolx-gerek-yok', !tamam);
            var n = oge.querySelector('.rolx-gerek-not');
            if (n) n.hidden = tamam || !oge.querySelector('input').checked;
        });
        var gorunur = Object.keys(secili).filter(function (p) { return !/\.detailed$/.test(p) && p.indexOf('role.catalog.') !== 0; }).length;
        editor.querySelector('.rol-total').textContent = gorunur + ' izin seçili';
        var kapsamli = editor.querySelector('.rol-scope-mode').value === 'selected';
        var depolar = editor.querySelector('.rol-scope-depots');
        depolar.hidden = !kapsamli; depolar.disabled = !kapsamli;
        var kOzet = editor.querySelector('[data-kapsam-ozet]');
        if (kOzet) kOzet.textContent = kapsamli ? 'Seçili depolar' : 'Tüm depolar';
        editor.querySelector('.rolx-degisti').hidden = durumMetni() === ilkDurum;
        // mobil sayfa seçicisinin etiketleri
        var sec = editor.querySelector('.rolx-nav-sec');
        Array.prototype.forEach.call(sec.options, function (o) {
            var oge = editor.querySelector('.rolx-nav-oge[data-git="' + o.value + '"]');
            var r = oge && oge.querySelector('.rolx-nav-durum');
            o.textContent = (o.dataset.on || '') + o.dataset.ad + (r && r.textContent ? ' — ' + r.textContent : '');
        });
    }

    // ── gezinme ──
    function sayfaAc(ad) {
        if (!editor.querySelector('.rolx-panel[data-sayfa="' + ad + '"]')) return;
        aktif = ad;
        editor.querySelectorAll('.rolx-panel').forEach(function (p) { p.hidden = p.dataset.sayfa !== ad; });
        editor.querySelectorAll('.rolx-nav-oge').forEach(function (b) {
            var sec = b.dataset.git === ad;
            b.classList.toggle('rolx-aktif', sec);
            if (sec) b.setAttribute('aria-current', 'true'); else b.removeAttribute('aria-current');
        });
        editor.querySelector('.rolx-nav-sec').value = ad;
        editor.querySelector('.rolx-govde').scrollTop = 0;
    }
    function secimiKur() {
        var sec = editor.querySelector('.rolx-nav-sec');
        sec.innerHTML = '';
        editor.querySelectorAll('.rolx-nav-oge').forEach(function (b) {
            var o = document.createElement('option');
            var grup = b.closest('.rolx-nav-grup');
            o.value = b.dataset.git;
            o.dataset.ad = b.querySelector('.rolx-nav-ad').textContent;
            o.dataset.on = grup ? grup.dataset.grup + ' › ' : '';
            o.textContent = o.dataset.on + o.dataset.ad;
            sec.appendChild(o);
        });
    }
    function ara() {
        var q = tr(editor.querySelector('.rolx-ara-kutu').value).trim();
        var gorunen = 0, ilk = '';
        editor.querySelectorAll('.rolx-nav-oge').forEach(function (b) {
            var panel = editor.querySelector('.rolx-panel[data-sayfa="' + b.dataset.git + '"]');
            var metin = tr(b.textContent) + ' ' + (panel ? panel.dataset.ara || '' : '');
            var uy = !q || metin.indexOf(q) !== -1;
            b.hidden = !uy;
            if (uy) { gorunen++; if (!ilk) ilk = b.dataset.git; }
        });
        editor.querySelectorAll('.rolx-nav-grup').forEach(function (g) {
            g.hidden = !g.querySelector('.rolx-nav-oge:not([hidden])');
        });
        editor.querySelector('.rolx-nav-bos').hidden = gorunen !== 0;
        if (q && ilk && editor.querySelector('.rolx-nav-oge[data-git="' + aktif + '"]').hidden) sayfaAc(ilk);
        // eşleşen öğeleri panelde vurgula
        editor.querySelectorAll('.rolx-oge, .rolx-satir').forEach(function (el) {
            el.classList.toggle('rolx-bulundu', !!q && tr(el.textContent).indexOf(q) !== -1);
        });
    }

    // ── olaylar ──
    editor.addEventListener('input', function (e) {
        if (e.target.matches('input[name="permissions[]"][type="checkbox"]')) {
            kutular().forEach(function (cb) { if (cb.value === e.target.value) cb.checked = e.target.checked; });   // aynı izin birden çok yerde
        }
        if (e.target.classList.contains('rolx-ara-kutu')) ara();
        yenile();
    });
    editor.addEventListener('change', function (e) {
        if (e.target.classList.contains('rolx-nav-sec')) sayfaAc(e.target.value);
        if (e.target.classList.contains('rolx-kopya-sec') && e.target.value) {
            var kaynak = roller.filter(function (r) { return String(r.id) === e.target.value; })[0];
            if (kaynak && confirm('“' + kaynak.label + '” rolünün izinleri bu forma kopyalansın mı? Mevcut seçimler değişir.')) izinleriUygula(kaynak.permissions);
            e.target.value = '';
        }
        yenile();
    });
    editor.addEventListener('click', function (e) {
        var git = e.target.closest('.rolx-nav-oge');
        if (git) { sayfaAc(git.dataset.git); return; }
        var hazir = e.target.closest('[data-hazir]');
        if (hazir) {
            var panel = hazir.closest('.rolx-panel'), kip = hazir.dataset.hazir, degerler = {};
            kutular(panel).forEach(function (cb) { degerler[cb.value] = kip === 'tam' ? true : (kip === 'salt' ? cb.dataset.salt === '1' : false); });
            kutular().forEach(function (cb) { if (cb.value in degerler) cb.checked = degerler[cb.value]; });
            yenile();
        }
    });
    document.getElementById('rolLabel').addEventListener('input', yenile);

    // ── pencere ──
    function ac(kip, data) {
        acan = document.activeElement;
        data = data || {};
        document.getElementById('rolModalBaslik').textContent = kip === 'duzenle' ? 'Rolü Düzenle' : (kip === 'kopya' ? 'Rolü Kopyala' : 'Yeni Rol');
        document.getElementById('rolAction').value = kip === 'duzenle' ? 'update_role' : 'create_role';
        document.getElementById('rolId').value = kip === 'duzenle' ? data.id : '';
        document.getElementById('rolLabel').value = data.label || '';
        document.getElementById('rolSlug').textContent = data.slug || '';
        document.getElementById('rolSlugNot').hidden = kip !== 'duzenle';
        document.getElementById('rolKaydet').textContent = kip === 'duzenle' ? 'Kaydet' : 'Oluştur';
        var varsayilan = [];
        try { varsayilan = JSON.parse(editor.dataset.varsayilan) || []; } catch (e) { varsayilan = []; }
        izinleriUygula(data.permissions || varsayilan);
        var kopya = editor.querySelector('.rolx-kopya-sec');
        kopya.innerHTML = '<option value="">Rol seçin…</option>';
        roller.forEach(function (r) {
            if (kip === 'duzenle' && String(r.id) === String(data.id)) return;
            var o = document.createElement('option'); o.value = r.id; o.textContent = r.label; kopya.appendChild(o);
        });
        editor.querySelector('.rolx-ara-kutu').value = '';
        ara();
        modal.removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
        var ilkSayfa = editor.querySelector('.rolx-nav-grup .rolx-nav-oge');
        sayfaAc(ilkSayfa ? ilkSayfa.dataset.git : 'kapsam');
        yenile();
        // Kopya ve hata-sonrası yeniden açılış "kaydedilmemiş" sayılır; diğerlerinde açılış anı taban alınır.
        ilkDurum = (kip === 'kopya' || data.kirli) ? '' : durumMetni();
        yenile();
        document.getElementById('rolLabel').focus();
    }
    function kapat(zorla) {
        if (!zorla && ilkDurum !== durumMetni() && !confirm('Kaydedilmemiş değişiklikler var. Pencere kapatılsın mı?')) return;
        modal.setAttribute('hidden', '');
        document.body.style.overflow = '';
        if (acan && acan.focus) acan.focus();
    }
    modal.addEventListener('click', function (e) {
        if (e.target === modal || e.target.closest('[data-rol-kapat]')) kapat(false);
    });
    modal.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); kapat(false); return; }
        if (e.key !== 'Tab') return;
        var ogeler = Array.prototype.slice.call(modal.querySelectorAll('button,input,select,a[href]')).filter(function (el) {
            return !el.disabled && el.type !== 'hidden' && el.getClientRects().length > 0;
        });
        var ilk = ogeler[0], son = ogeler[ogeler.length - 1];
        if (e.shiftKey && document.activeElement === ilk) { e.preventDefault(); son.focus(); }
        if (!e.shiftKey && document.activeElement === son) { e.preventDefault(); ilk.focus(); }
    });
    function rolBul(id) { return roller.filter(function (r) { return String(r.id) === String(id); })[0]; }
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-rol-yeni],[data-rol-duzenle],[data-rol-kopyala]');
        if (!b) return;
        if (b.hasAttribute('data-rol-yeni')) { ac('yeni'); return; }
        var r = rolBul(b.getAttribute('data-rol-duzenle') || b.getAttribute('data-rol-kopyala'));
        if (!r) return;
        if (b.hasAttribute('data-rol-duzenle')) ac('duzenle', r);
        else ac('kopya', { label: r.label + ' (kopya)', permissions: r.permissions });
    });
    window.rolOpenCreate = function () { ac('yeni'); };
    window.rolOpenEdit = function (data) { ac('duzenle', data); };
    window.rolClose = function () { kapat(true); };

    secimiKur();
    yenile();
    // users.php "Yetki kaynağı" bağlantısı: roles.php?duzenle=ID doğrudan o rolü açar
    var istenen = new URLSearchParams(location.search).get('duzenle');
    if (!veri.hata && istenen && rolBul(istenen)) ac('duzenle', rolBul(istenen));
    if (veri.hata) ac(veri.hata.kip, { id: veri.hata.id, label: veri.hata.label, slug: veri.hata.slug, permissions: veri.hata.permissions, kirli: true });
})();
</script>

<?php render_footer(); ?>
