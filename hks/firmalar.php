<?php
// HKS Firma Yönetimi — admin-only
declare(strict_types=1);
require_once __DIR__ . '/includes/hks_bootstrap.php';
$auth_user = require_login();
if (!is_admin() && !(function_exists('can') && can('hks.settings'))) {
    http_response_code(403); die('Bu sayfaya erişim yetkiniz yok.');
}

$repo = new HksRepository(db());

// POST: Firma ekle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $firma_adi = trim($_POST['firma_adi'] ?? '');
        if ($firma_adi === '') {
            set_flash('error', 'Firma adı boş olamaz.');
        } else {
            $new_id = $repo->addCompany($firma_adi);
            audit_log_event('hks_company_added', 'hks_settings', $new_id, null, ['firma_adi' => $firma_adi]);
            set_flash('success', '"' . $firma_adi . '" eklendi. Şimdi ayarları girin.');
            $_SESSION['hks_company_id'] = $new_id;
            header('Location: ayarlar.php'); exit;
        }
    }

    if ($action === 'rename') {
        $id        = (int)($_POST['company_id'] ?? 0);
        $firma_adi = trim($_POST['firma_adi'] ?? '');
        if ($id > 0 && $firma_adi !== '') {
            $repo->renameCompany($id, $firma_adi);
            audit_log_event('hks_company_renamed', 'hks_settings', $id, null, ['firma_adi' => $firma_adi]);
            set_flash('success', 'Firma adı güncellendi.');
        }
        header('Location: firmalar.php'); exit;
    }

    if ($action === 'delete' && is_admin()) {
        $id = (int)($_POST['company_id'] ?? 0);
        if ($id > 0) {
            $repo->deleteCompany($id);
            audit_log_event('hks_company_deleted', 'hks_settings', $id, null, []);
            if (isset($_SESSION['hks_company_id']) && (int)$_SESSION['hks_company_id'] === $id) {
                unset($_SESSION['hks_company_id']);
            }
            set_flash('success', 'Firma silindi.');
        }
        header('Location: firmalar.php'); exit;
    }

    header('Location: firmalar.php'); exit;
}

$companies = $repo->getAllCompanies();

$hks_active_tab = '';
render_header('HKS Firma Yönetimi');
render_flash();
?>
<div class="hks-page" style="max-width:760px;margin:0 auto">

<div class="page-head" style="margin-top:16px">
    <div>
        <h1>🏢 Firma Yönetimi</h1>
        <p class="muted">HKS modülüne erişim sağlayacak firmaları yönetin</p>
    </div>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">← Geri</a>
    </div>
</div>

<!-- Yeni firma ekle -->
<div class="card" style="padding:20px;margin-bottom:20px">
    <h3 style="margin-top:0;font-size:1rem">+ Yeni Firma Ekle</h3>
    <form method="post" action="firmalar.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add">
        <div class="form-group" style="flex:1;min-width:220px;margin:0">
            <label for="firma_adi_new" style="font-size:.85rem">Firma Adı</label>
            <input type="text" id="firma_adi_new" name="firma_adi"
                   placeholder="Örn: Ahmet Yılmaz Tic. Ltd." required
                   maxlength="200" autocomplete="off">
        </div>
        <button type="submit" class="btn btn-primary">Ekle ve Ayarla →</button>
    </form>
</div>

<!-- Kayıtlı firmalar -->
<?php if (empty($companies)): ?>
<div class="hks-warning-box">Henüz kayıtlı firma yok. Yukarıdan ilk firmayı ekleyin.</div>
<?php else: ?>
<div class="table-wrap">
<table class="hks-table">
    <thead>
        <tr>
            <th>#</th>
            <th>Firma Adı</th>
            <th>Ortam</th>
            <th>Kullanıcı</th>
            <th>Bağlantı</th>
            <th>İşlem</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($companies as $co):
        $is_selected = isset($_SESSION['hks_company_id']) && (int)$_SESSION['hks_company_id'] === (int)$co['id'];
        $env_label   = ($co['environment'] ?? 'test') === 'live' ? '🔴 Canlı' : '🟡 Test';
        $conn_label  = $co['last_test_ok'] ? '<span style="color:#065f46">✅</span>'
                     : ($co['last_test_at'] ? '<span style="color:#92400e">⚠️</span>' : '<span style="color:var(--muted)">—</span>');
    ?>
    <tr style="<?= $is_selected ? 'background:var(--bg-soft,#f0fdf4)' : '' ?>">
        <td style="color:var(--muted);font-size:.82rem"><?= (int)$co['id'] ?></td>
        <td>
            <form method="post" action="firmalar.php" id="rename-form-<?= (int)$co['id'] ?>" style="display:flex;align-items:center;gap:6px">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="company_id" value="<?= (int)$co['id'] ?>">
                <input type="text" name="firma_adi" value="<?= hks_h($co['firma_adi']) ?>"
                       style="font-weight:<?= $is_selected ? '700' : 'normal' ?>;min-width:160px;font-size:.9rem"
                       maxlength="200" required>
                <button type="submit" class="btn btn-ghost btn-sm" title="Adı kaydet">✓</button>
            </form>
            <?php if ($is_selected): ?>
            <span style="font-size:.75rem;color:var(--success,#16a34a);margin-left:4px">● Aktif</span>
            <?php endif; ?>
        </td>
        <td style="font-size:.85rem"><?= $env_label ?></td>
        <td style="font-size:.85rem;color:var(--muted)"><?= hks_h($co['username'] ?: '—') ?></td>
        <td><?= $conn_label ?></td>
        <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                <!-- Firma seç -->
                <a href="index.php?sec=<?= (int)$co['id'] ?>" class="btn btn-sm <?= $is_selected ? 'btn-primary' : 'btn-ghost' ?>">
                    <?= $is_selected ? '✓ Seçili' : 'Giriş Yap' ?>
                </a>
                <!-- Ayarlar -->
                <a href="index.php?sec=<?= (int)$co['id'] ?>&amp;to=ayarlar" class="btn btn-ghost btn-sm" title="Ayarları düzenle">⚙️</a>
                <?php if (is_admin()): ?>
                <!-- Sil -->
                <form method="post" action="firmalar.php" onsubmit="return confirm('«<?= hks_h(addslashes($co['firma_adi'])) ?>» silinsin mi? Bu işlem geri alınamaz.')">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="company_id" value="<?= (int)$co['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger,#dc2626)" title="Sil">✕</button>
                </form>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

</div><!-- /.hks-page -->
<?php render_footer(); ?>
