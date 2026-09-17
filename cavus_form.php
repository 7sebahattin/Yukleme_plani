<?php
// =========================================================
// cavus_form.php — Çavuş Oluştur / Düzenle (Günlük İşçi, Faz 1)
//
// personel_form.php'nin "tek dosya, hem oluşturma hem düzenleme" deseni
// (?id= yoksa yeni kayıt, varsa mevcut kayıt düzenlenir). Çavuş bir Nuverna
// kullanıcı hesabı DEĞİLDİR (kullanıcının açık talimatı) — bu form users
// tablosuna hiç dokunmaz.
//
// Silme YOK (kullanıcının açık talimatı: "No deletion if later historical
// references may exist.") — yalnız aktif/pasif geçiş.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_gunluk('foremen');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
// Faz 9C / H-02: "Normal Günlük Çalışma Süresi" alanı yalnız Faz 8B şeması
// migrate edildiyse gösterilir/kaydedilir — migrate edilmemiş bir üretimde
// eksik kolona YAZMAYA ÇALIŞMAZ (bkz. config/pdks_faz8b.php).
$faz8bHazir = pdks_faz8b_sema_hazir($pdo);

$record = ['code' => '', 'name' => '', 'phone' => '', 'notes' => '', 'is_active' => 1];
if ($id > 0) {
    $st = $pdo->prepare("SELECT * FROM foremen WHERE id = ?");
    $st->execute([$id]);
    $eski = $st->fetch();
    if (!$eski) {
        set_flash('error', 'Çavuş bulunamadı.');
        header('Location: cavuslar.php');
        exit;
    }
    $record = $eski;
} else {
    $record['code'] = pdks_gunluk_sonraki_cavus_kodu($pdo);
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_gunluk('foremen');   // savunma derinliği
    $action = trim($_POST['action'] ?? 'save');

    if ($action === 'save') {
        $veri = [
            'code'      => trim($_POST['code'] ?? ''),
            'name'      => trim($_POST['name'] ?? ''),
            'phone'     => trim($_POST['phone'] ?? ''),
            'notes'     => trim($_POST['notes'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
        $sonuc = ($id > 0)
            ? pdks_gunluk_cavus_guncelle($id, $veri, (int)$auth_user['id'], $pdo)
            : pdks_gunluk_cavus_olustur($veri, (int)$auth_user['id'], $pdo);

        if ($sonuc['ok']) {
            $kayitId = ($id > 0) ? $id : (int)$sonuc['id'];
            // Faz 9C / H-02: normal çalışma süresi OLUŞTURMA ve DÜZENLEME'de
            // AYNI formdadır — yeni bir çavuş 8h/9h/10h olarak DOĞRUDAN
            // kurulabilir, her seferinde ikinci bir düzenleme adımına
            // ZORLANMAZ. Şema henüz migrate edilmediyse (fail-safe) alan hiç
            // GÖSTERİLMEZ/POST edilmez — DB'nin kendi DEFAULT'u (540) geçerli
            // olur, hiçbir yerde HATA verilmez.
            $sureHata = null;
            if ($faz8bHazir) {
                $saatHam = str_replace(',', '.', trim((string)($_POST['saat'] ?? '')));
                $saat = is_numeric($saatHam) ? (float)$saatHam : null;
                if ($saat !== null && $saat > 0) {
                    $dakika = (int)round($saat * 60);
                    $sureSonuc = pdks_faz8b_cavus_normal_sure_guncelle($kayitId, $dakika, (int)$auth_user['id'], $pdo);
                    if (!$sureSonuc['ok']) $sureHata = $sureSonuc['hata'] ?? 'Normal çalışma süresi kaydedilemedi.';
                }
            }
            if ($sureHata === null) {
                header('Location: cavus_form.php?id=' . $kayitId . '&ok=' . urlencode('Çavuş kaydedildi.'));
                exit;
            }
            // Çavuş kaydı BAŞARILI oldu, yalnız süre aralık dışıydı (1-24
            // saat) — kullanıcı artık DÜZENLEME modundaki forma dönüp
            // yalnız süreyi düzeltebilir, kayıt kaybolmaz.
            header('Location: cavus_form.php?id=' . $kayitId . '&sure_hata=' . urlencode($sureHata));
            exit;
        }
        $errors = $sonuc['hatalar'] ?? [$sonuc['hata'] ?? 'Kaydedilemedi.'];
        $record = array_merge($record, $veri, ['id' => $id]);
    } elseif ($action === 'aktiflik') {
        $aktif = ($_POST['aktif'] ?? '') === '1';
        $sonuc = pdks_gunluk_cavus_aktiflik($id, $aktif, (int)$auth_user['id'], $pdo);
        if ($sonuc['ok']) {
            header('Location: cavus_form.php?id=' . $id . '&ok=' . urlencode($aktif ? 'Çavuş aktifleştirildi.' : 'Çavuş pasifleştirildi.'));
            exit;
        }
        $errors[] = $sonuc['hata'] ?? 'İşlem yapılamadı.';
    }
}
if (isset($_GET['sure_hata'])) $errors[] = trim((string)$_GET['sure_hata']);

$basari = '';
if (empty($errors) && isset($_GET['ok'])) $basari = trim($_GET['ok']);

render_header($id > 0 ? (string)$record['name'] : 'Yeni Çavuş');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head">
    <h1><?= $id > 0 ? h($record['name']) : 'Yeni Çavuş' ?></h1>
    <div class="page-head-actions">
        <a href="cavuslar.php" class="btn btn-ghost">← Çavuşlar</a>
    </div>
</div>

<?php if ($basari !== ''): ?><div class="flash flash-success"><?= h($basari) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="flash flash-error"><?= h($e) ?></div><?php endforeach; ?>

<div class="card" style="padding:18px 20px;margin-bottom:20px">
<form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <?php if ($id > 0): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

    <div class="pdks-form-grid">
        <label>
            <span class="form-label">Çavuş Kodu *</span>
            <input type="text" name="code" required maxlength="20" value="<?= h((string)$record['code']) ?>">
        </label>
        <label>
            <span class="form-label">Ad Soyad *</span>
            <input type="text" name="name" required maxlength="150" value="<?= h((string)$record['name']) ?>" placeholder="ör. Ayşe Çavuş">
        </label>
        <label>
            <span class="form-label">Telefon</span>
            <input type="tel" name="phone" maxlength="30" value="<?= h((string)($record['phone'] ?? '')) ?>">
        </label>
        <label>
            <span class="form-label">Durum</span>
            <label style="display:flex;align-items:center;gap:8px;margin-top:8px;font-weight:400">
                <input type="checkbox" name="is_active" value="1" <?= !empty($record['is_active']) ? 'checked' : '' ?>> Aktif
            </label>
        </label>
        <label class="span-2">
            <span class="form-label">Notlar</span>
            <textarea name="notes" rows="3" maxlength="2000"><?= h((string)($record['notes'] ?? '')) ?></textarea>
        </label>
        <?php if ($faz8bHazir):
            // Faz 9C / H-02: OLUŞTURMA'da da DÜZENLEME'de de AYNI alan —
            // yeni çavuş 8h/9h/10h olarak TEK adımda kurulabilir. DB
            // DEFAULT'u (540/9 saat) yalnız GÜVENLİK AĞIDIR, zorunlu ilk
            // değer değil (mevcut kayıtta GERÇEK canlı değer gösterilir).
            $normalDkGosterim = $id > 0 ? pdks_faz8b_cavus_normal_sure_dk($id, $pdo) : 540;
        ?>
        <label>
            <span class="form-label">Normal Günlük Çalışma Süresi (saat)</span>
            <input type="number" name="saat" min="1" max="24" step="0.25"
                   value="<?= h((string)round($normalDkGosterim / 60, 2)) ?>">
        </label>
        <?php endif; ?>
    </div>
    <?php if ($faz8bHazir): ?>
    <p class="muted" style="font-size:.8rem;margin-top:8px">
        Sabit bir 08:00–17:00 vardiyası YOKTUR — işçi istediği saatte başlayabilir. Önemli olan,
        geçen sürenin bu ÇAVUŞA ait anlaşmalı normal süreyi tamamlamasıdır; tamamlanınca otomatik
        Tam Mesai sayılır, sonrası (15 dk tolerans sonrası) fazla mesaidir. KADIN/ERKEK aynı
        çavuşta AYNI süreyi kullanır. Bu değeri değiştirmek YALNIZ BUNDAN SONRA açılacak
        oturumları etkiler — geçmiş mesailer ve kesinleşmiş hakedişler donmuş kendi süreleriyle kalır.
    </p>
    <?php endif; ?>

    <button type="submit" class="btn btn-primary" style="margin-top:14px"><?= $id > 0 ? 'Kaydet' : 'Çavuşu Oluştur' ?></button>
</form>
</div>

<?php if ($id > 0): ?>
<div class="card" style="padding:16px 18px;margin-bottom:20px">
    <h2 style="margin-top:0;font-size:1rem">Durum</h2>
    <form method="post" style="display:inline">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="aktiflik">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="aktif" value="<?= !empty($record['is_active']) ? '0' : '1' ?>">
        <button type="submit" class="btn <?= !empty($record['is_active']) ? '' : 'btn-primary' ?>">
            <?= !empty($record['is_active']) ? '⏸ Pasifleştir' : '▶ Aktifleştir' ?>
        </button>
    </form>
    <p class="muted" style="margin-top:10px;font-size:.85rem">
        Çavuş kaydı silinemez — yalnız aktif/pasif yapılabilir (ileride hakediş/cari
        geçmişi bu kayda bağlanacaktır).
    </p>
</div>
<?php endif; ?>

<?php render_footer(); ?>
