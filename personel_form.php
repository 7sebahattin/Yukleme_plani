<?php
// =========================================================
// personel_form.php — Personel Oluştur / Düzenle + Kart Yönetimi (PDKS Faz 1B)
//
// Tek dosya, hem oluşturma hem düzenleme (hesap_kayit.php deseni): ?id= yoksa
// yeni kayıt, varsa mevcut kayıt düzenlenir. Personel var olan bir kayıtsa,
// sayfanın altında "KART YÖNETİMİ" bölümü (mevcut kart, geçmiş, eylemler)
// gösterilir.
//
// UID mantığı BURADA TEKRARLANMAZ — kart eylemleri config/pdks.php'deki Faz 1
// fonksiyonlarına (pdks_kart_ata/pdks_kart_durum_degistir/pdks_kart_degistir)
// çıkar; bu dosya yalnız HTTP/form bağlama katmanıdır.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks('employees');
pdks_migrate();

$pdo = db();
$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$record = [
    'personnel_no' => '', 'full_name' => '', 'department' => '', 'job_title' => '',
    'depo' => active_depot() ?? '', 'status' => 'aktif', 'user_id' => '',
    'phone' => '', 'hire_date' => '', 'leave_date' => '', 'notes' => '',
    'photo_file' => null, 'photo_updated_at' => null,
];
$eski = null;
if ($id > 0) {
    $st = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $st->execute([$id]);
    $eski = $st->fetch();
    if (!$eski) {
        set_flash('error', 'Personel bulunamadı.');
        header('Location: personel.php');
        exit;
    }
    $record = $eski;
}

$errors = []; $kartMesaj = ''; $kartHata = '';

// ── POST İşleme ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = trim($_POST['action'] ?? 'save_employee');

    if ($action === 'save_employee') {
        $record = [
            'personnel_no' => trim($_POST['personnel_no'] ?? ''),
            'full_name'    => trim($_POST['full_name'] ?? ''),
            'department'   => trim($_POST['department'] ?? ''),
            'job_title'    => trim($_POST['job_title'] ?? ''),
            'depo'         => trim($_POST['depo'] ?? ''),
            'status'       => trim($_POST['status'] ?? 'aktif'),
            'user_id'      => trim($_POST['user_id'] ?? ''),
            'phone'        => trim($_POST['phone'] ?? ''),
            'hire_date'    => trim($_POST['hire_date'] ?? ''),
            'leave_date'   => trim($_POST['leave_date'] ?? ''),
            'notes'        => trim($_POST['notes'] ?? ''),
            'photo_file'       => $eski['photo_file'] ?? null,
            'photo_updated_at' => $eski['photo_updated_at'] ?? null,
        ];

        if ($id > 0) {
            $sonuc = pdks_personel_guncelle($id, $record, (int)$auth_user['id'], $pdo);
        } else {
            $sonuc = pdks_personel_olustur($record, (int)$auth_user['id'], $pdo);
        }

        if (!$sonuc['ok']) {
            $errors[] = $sonuc['hata'] ?? 'Kayıt işlenemedi.';
        } else {
            $id = $sonuc['id'] ?? $id;
            $fotoUyari = '';

            // Fotoğraf kaldırma
            if (!empty($_POST['fotograf_kaldir']) && !empty($record['photo_file'])) {
                pdks_foto_sil($record['photo_file']);
                $pdo->prepare("UPDATE employees SET photo_file=NULL, photo_updated_at=NULL WHERE id=?")->execute([$id]);
            }
            // Yeni fotoğraf
            if (!empty($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $fotoSonuc = pdks_foto_kaydet($_FILES['photo']);
                if ($fotoSonuc['ok']) {
                    $eskiFoto = $record['photo_file'];
                    $pdo->prepare("UPDATE employees SET photo_file=?, photo_updated_at=NOW() WHERE id=?")
                        ->execute([$fotoSonuc['file_name'], $id]);
                    if ($eskiFoto && $eskiFoto !== $fotoSonuc['file_name']) pdks_foto_sil($eskiFoto);
                    if (function_exists('audit_log_event')) {
                        audit_log_event('employee_photo_updated', 'pdks', $id, null, ['file' => $fotoSonuc['file_name']]);
                    }
                } else {
                    $fotoUyari = ' (Uyarı: fotoğraf kaydedilemedi — ' . ($fotoSonuc['hata'] ?? '') . ')';
                }
            }

            $mesaj = ($eski ? 'Personel güncellendi.' : 'Personel oluşturuldu.') . $fotoUyari;
            header('Location: personel_form.php?id=' . $id . '&ok=' . urlencode($mesaj));
            exit;
        }

    } elseif ($action === 'delete_employee') {
        if (!pdks_can('employees')) forbidden();
        if ($id <= 0) {
            $errors[] = 'Geçersiz personel.';
        } else {
            $stK = $pdo->prepare("SELECT COUNT(*) FROM employee_cards WHERE employee_id = ?");
            $stK->execute([$id]);
            $kartSayisi = (int)$stK->fetchColumn();
            if ($kartSayisi > 0) {
                $errors[] = 'Bu personelin kart geçmişi var — silinemez. Bunun yerine durumu "Ayrıldı" yapın.';
            } else {
                $eskiOzet = ['full_name' => $eski['full_name'] ?? '', 'personnel_no' => $eski['personnel_no'] ?? null];
                $pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$id]);
                audit_log_event('employee_deleted', 'pdks', $id, $eskiOzet, null);
                header('Location: personel.php?ok=' . urlencode('Personel silindi: ' . ($eskiOzet['full_name'] ?: '')));
                exit;
            }
        }

    } elseif (in_array($action, ['kart_ata', 'kart_durum', 'kart_degistir'], true)) {
        if (!pdks_can('cards')) forbidden();
        if ($id <= 0) {
            $kartHata = 'Geçersiz personel.';
        } elseif ($action === 'kart_ata') {
            $hamUid = trim($_POST['ham_uid'] ?? '');
            $etiket = trim($_POST['label'] ?? '');
            $sonuc = pdks_kart_ata($id, $hamUid, 'usb_decimal', ['label' => $etiket, 'created_by' => (int)$auth_user['id']], $pdo);
            $sonuc['ok'] ? $kartMesaj = 'Kart tanımlandı: ' . $sonuc['uid_hex'] : $kartHata = $sonuc['hata'] ?? 'Kart tanımlanamadı.';
        } elseif ($action === 'kart_durum') {
            $cardId  = (int)($_POST['card_id'] ?? 0);
            $durum   = trim($_POST['durum'] ?? '');
            $gerekce = trim($_POST['gerekce'] ?? '');
            if (!in_array($durum, ['iptal', 'kayip'], true)) {
                $kartHata = 'Geçersiz işlem.';
            } else {
                $sonuc = pdks_kart_durum_degistir($cardId, $durum, $gerekce, (int)$auth_user['id'], $pdo);
                $sonuc['ok'] ? $kartMesaj = 'Kart durumu güncellendi.' : $kartHata = $sonuc['hata'] ?? 'İşlem yapılamadı.';
            }
        } else { // kart_degistir
            $eskiCardId = (int)($_POST['eski_card_id'] ?? 0);
            $hamUid     = trim($_POST['ham_uid'] ?? '');
            $gerekce    = trim($_POST['gerekce'] ?? '');
            $sonuc = pdks_kart_degistir($eskiCardId, $hamUid, 'usb_decimal', $gerekce, (int)$auth_user['id'], $pdo);
            $sonuc['ok'] ? $kartMesaj = 'Kart değiştirildi: ' . $sonuc['uid_hex'] : $kartHata = $sonuc['hata'] ?? 'Kart değiştirilemedi.';
        }
        if ($kartMesaj !== '') {
            header('Location: personel_form.php?id=' . $id . '&ok=' . urlencode($kartMesaj));
            exit;
        }
        // Hata varsa sayfa aşağıda sticky $kartHata ile yeniden render edilir.
        $st = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
        $st->execute([$id]);
        $record = $st->fetch() ?: $record;
    }
}

$basari = '';
if (empty($errors) && $kartHata === '' && isset($_GET['ok'])) $basari = trim($_GET['ok']);

// Kart bilgileri (yalnız mevcut personel)
$aktifKart = null; $kartGecmisi = [];
if ($id > 0) {
    $aktifKart   = pdks_personel_aktif_kart($id, $pdo);
    $kartGecmisi = pdks_personel_kart_gecmisi($id, $pdo);
}

// Kullanıcı hesabı seçenekleri: bağlı olmayanlar + (düzenlemede) mevcut bağlı hesap
$kullaniciSecenekleri = [];
try {
    $sql = "SELECT id, username, display_name FROM users
             WHERE is_active = 1 AND (id NOT IN (SELECT user_id FROM employees WHERE user_id IS NOT NULL)"
         . ($id > 0 ? " OR id = " . (int)($record['user_id'] ?? 0) : '') . ")
             ORDER BY username";
    $kullaniciSecenekleri = $pdo->query($sql)->fetchAll();
} catch (PDOException $e) { /* users tablosu her zaman vardır — sessiz geç */ }

$departmanlar = [];
try { $departmanlar = $pdo->query("SELECT DISTINCT department FROM employees WHERE department <> '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN); }
catch (PDOException $e) {}

render_header($id > 0 ? (string)$record['full_name'] : 'Yeni Personel');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head">
    <h1><?= $id > 0 ? h($record['full_name']) : 'Yeni Personel' ?></h1>
    <div class="page-head-actions">
        <a href="personel.php" class="btn btn-ghost">← Personeller</a>
    </div>
</div>

<?php if ($basari !== ''): ?><div class="flash flash-success"><?= h($basari) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="flash flash-error"><?= h($e) ?></div><?php endforeach; ?>
<?php if ($kartHata !== ''): ?><div class="flash flash-error"><?= h($kartHata) ?></div><?php endif; ?>

<div class="card" style="padding:18px 20px;margin-bottom:20px">
<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="save_employee">
    <?php if ($id > 0): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

    <div class="pdks-photo-row">
        <?= pdks_avatar_html((string)$record['full_name'], $record['photo_file'] ?? null, $record['photo_updated_at'] ?? null, $base, 'pdks-avatar pdks-avatar-lg') ?>
        <div>
            <label class="btn btn-sm">
                📷 Fotoğraf Seç
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" hidden onchange="this.closest('.pdks-photo-row').querySelector('.pdks-photo-name').textContent = this.files[0] ? this.files[0].name : ''">
            </label>
            <span class="muted pdks-photo-name" style="margin-left:8px"></span>
            <?php if (!empty($record['photo_file'])): ?>
            <label style="display:block;margin-top:6px;font-size:.85rem">
                <input type="checkbox" name="fotograf_kaldir" value="1"> Fotoğrafı kaldır
            </label>
            <?php endif; ?>
            <div class="muted" style="font-size:.78rem;margin-top:4px">JPG/PNG/WEBP, en fazla 5 MB.</div>
        </div>
    </div>

    <div class="pdks-form-grid">
        <label>
            <span class="form-label">Ad Soyad *</span>
            <input type="text" name="full_name" required maxlength="150" value="<?= h((string)$record['full_name']) ?>">
        </label>
        <label>
            <span class="form-label">Sicil No</span>
            <input type="text" name="personnel_no" maxlength="30" value="<?= h((string)$record['personnel_no']) ?>">
        </label>
        <label>
            <span class="form-label">Departman</span>
            <input type="text" name="department" list="pdksDeptList" maxlength="100" value="<?= h((string)$record['department']) ?>">
            <datalist id="pdksDeptList"><?php foreach ($departmanlar as $d): ?><option value="<?= h($d) ?>"><?php endforeach; ?></datalist>
        </label>
        <label>
            <span class="form-label">Görev</span>
            <input type="text" name="job_title" maxlength="100" value="<?= h((string)$record['job_title']) ?>">
        </label>
        <label>
            <span class="form-label">Durum</span>
            <select name="status">
                <?php foreach (pdks_personel_durumlari() as $k => $lbl): ?>
                <option value="<?= h($k) ?>" <?= (string)$record['status'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span class="form-label">Depo</span>
            <input type="text" name="depo" maxlength="150" value="<?= h((string)$record['depo']) ?>">
        </label>
        <label>
            <span class="form-label">Telefon</span>
            <input type="tel" name="phone" maxlength="30" value="<?= h((string)($record['phone'] ?? '')) ?>">
        </label>
        <label>
            <span class="form-label">Bağlı Kullanıcı Hesabı</span>
            <select name="user_id">
                <option value="">— hesabı yok —</option>
                <?php foreach ($kullaniciSecenekleri as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= (string)($record['user_id'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>>
                    <?= h($u['display_name'] ?: $u['username']) ?> (@<?= h($u['username']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span class="form-label">İşe Giriş</span>
            <input type="date" name="hire_date" value="<?= h((string)($record['hire_date'] ?? '')) ?>">
        </label>
        <label>
            <span class="form-label">Ayrılış</span>
            <input type="date" name="leave_date" value="<?= h((string)($record['leave_date'] ?? '')) ?>">
        </label>
        <label class="span-2">
            <span class="form-label">Not</span>
            <textarea name="notes" rows="2"><?= h((string)($record['notes'] ?? '')) ?></textarea>
        </label>
    </div>

    <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary"><?= $id > 0 ? 'Kaydet' : 'Personeli Oluştur' ?></button>
        <?php if ($id > 0 && pdks_can('employees') && empty($kartGecmisi)): ?>
        <button type="submit" class="btn btn-danger" name="action" value="delete_employee"
                formnovalidate onclick="return confirm('Bu personel silinsin mi? Bu işlem geri alınamaz.')">Sil</button>
        <?php endif; ?>
    </div>
</form>
</div>

<?php if ($id > 0 && pdks_can('cards')): ?>
<div class="card" style="padding:18px 20px;margin-bottom:20px">
    <h2 style="margin-top:0">🪪 Kart Yönetimi</h2>

    <?php if ($aktifKart): ?>
    <div class="pdks-card-item">
        <div class="pdks-card-top">
            <div class="pdks-card-meta">
                <div class="pdks-uid"><?= h($aktifKart['uid_hex']) ?></div>
                <div class="pdks-row-sub">
                    Tanımlandı: <?= h(fmt_datetime($aktifKart['created_at'])) ?>
                    <?= $aktifKart['label'] ? ' · ' . h($aktifKart['label']) : '' ?>
                </div>
            </div>
            <span class="pdks-badge pdks-badge-aktif">Aktif</span>
        </div>
        <div class="pdks-card-actions">
            <button type="button" class="btn btn-sm"
                    onclick="pdksKartModalAc('degistir', <?= (int)$aktifKart['id'] ?>, <?= $id ?>, '<?= h($aktifKart['uid_hex']) ?>')">Değiştir</button>
            <button type="button" class="btn btn-sm btn-danger"
                    onclick="pdksKartModalAc('iptal', <?= (int)$aktifKart['id'] ?>, <?= $id ?>, '<?= h($aktifKart['uid_hex']) ?>')">İptal Et</button>
            <button type="button" class="btn btn-sm btn-danger"
                    onclick="pdksKartModalAc('kayip', <?= (int)$aktifKart['id'] ?>, <?= $id ?>, '<?= h($aktifKart['uid_hex']) ?>')">Kayıp Bildir</button>
        </div>
    </div>
    <?php else: ?>
    <p class="muted">Bu personelin aktif kartı yok.</p>
    <button type="button" class="btn btn-primary" onclick="pdksKartModalAc('ata', null, <?= $id ?>, null)">+ Kart Ata</button>
    <?php endif; ?>

    <?php if (!empty($kartGecmisi)): ?>
    <h3 style="margin:20px 0 8px;font-size:.95rem">Kart Geçmişi</h3>
    <div class="table-wrap">
    <table class="data-table pdks-history-table">
    <thead><tr><th>UID</th><th>Durum</th><th>Tanımlandı</th><th>Kapandı</th><th>Gerekçe</th></tr></thead>
    <tbody>
    <?php foreach ($kartGecmisi as $k): ?>
    <tr>
        <td><span class="pdks-uid"><?= h($k['uid_hex']) ?></span></td>
        <td><span class="pdks-badge pdks-badge-<?= h($k['status']) ?>"><?= h(pdks_kart_durumlari()[$k['status']] ?? $k['status']) ?></span></td>
        <td class="muted"><?= h(fmt_datetime($k['created_at'])) ?></td>
        <td class="muted"><?= $k['revoked_at'] ? h(fmt_datetime($k['revoked_at'])) : '—' ?></td>
        <td class="pdks-history-reason"><?= h($k['revoke_reason'] ?: '—') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── Kart eylem modalı — Ata / Değiştir / İptal / Kayıp ortak ── -->
<div id="pdksKartModal" class="pm-overlay" hidden>
<div class="pm-dialog" style="max-width:480px">
    <div class="pm-header">
        <h2 class="pm-title" id="pdksKartBaslik">Kart İşlemi</h2>
        <button type="button" class="pm-close" onclick="pdksKartModalKapat()">✕</button>
    </div>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" id="pdksKartAction" value="">
        <input type="hidden" name="card_id" id="pdksKartCardId" value="">
        <input type="hidden" name="eski_card_id" id="pdksKartEskiCardId" value="">
        <input type="hidden" name="durum" id="pdksKartDurum" value="">
        <div class="pm-body">
            <p class="muted" id="pdksKartMevcutUidWrap" style="margin-top:0">
                Mevcut kart: <span class="pdks-uid" id="pdksKartMevcutUid"></span>
            </p>
            <div id="pdksKartScanBlok" hidden>
                <div class="pdks-scan-box">
                    <label class="pdks-scan-label" for="pdksKartUidInput">KARTI USB OKUYUCUYA OKUTUN</label>
                    <input type="text" inputmode="numeric" id="pdksKartUidInput" name="ham_uid" class="pdks-scan-input"
                           data-pdks-scan data-pdks-preview="#pdksKartOnizle" data-pdks-status="#pdksKartDurumMsj"
                           placeholder="631799511" autocomplete="off">
                    <div class="pdks-uid-lg" id="pdksKartOnizle" style="margin-top:12px;min-height:1.4em"></div>
                    <div class="pdks-scan-status" id="pdksKartDurumMsj"></div>
                </div>
                <label style="display:block;margin-top:10px">
                    <span class="form-label">Etiket (opsiyonel)</span>
                    <input type="text" name="label" maxlength="60">
                </label>
            </div>
            <div id="pdksKartGerekceBlok" hidden style="margin-top:10px">
                <label>
                    <span class="form-label">Gerekçe *</span>
                    <textarea id="pdksKartGerekce" name="gerekce" rows="2" placeholder="Zorunlu — ör. 'Kart bulunamadı', 'Personel talebi'"></textarea>
                </label>
            </div>
        </div>
        <div class="pm-footer">
            <button type="button" class="btn btn-ghost" onclick="pdksKartModalKapat()">İptal</button>
            <button type="submit" class="btn btn-primary" id="pdksKartOnayBtn">Onayla</button>
        </div>
    </form>
</div>
</div>

<script src="<?= $base ?>assets/pdks.js?v=<?= @filemtime(__DIR__ . '/assets/pdks.js') ?>"></script>
<script>
// Bu sayfada 'ata' ve 'degistir' eylemleri arasındaki hidden alan farkını
// (card_id vs eski_card_id) pdks.js'in genel fonksiyonunun üstüne ince bir
// katman olarak ekliyoruz — pdks.js dosyasını sayfa bazlı özel alan adlarıyla
// kirletmemek için.
(function () {
    var orijinal = window.pdksKartModalAc;
    window.pdksKartModalAc = function (action, cardId, employeeId, kartUid) {
        orijinal(action, cardId, employeeId, kartUid);
        document.getElementById('pdksKartDurum').value = (action === 'iptal' || action === 'kayip') ? action : '';
        document.getElementById('pdksKartEskiCardId').value = (action === 'degistir') ? (cardId || '') : '';
        document.getElementById('pdksKartCardId').value = (action === 'iptal' || action === 'kayip') ? (cardId || '') : '';
        document.getElementById('pdksKartMevcutUidWrap').hidden = !kartUid;
    };
})();
</script>
<?php endif; ?>

<?php render_footer(); ?>
