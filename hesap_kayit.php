<?php
// HESAP_TOKEN_REMOVED_V2
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_hesap('write');
hesap_migrate();

$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$hizli = trim($_GET['hizli'] ?? '');

$errors = [];

// Hızlı giriş ön ayarları
$quick_defaults = [
    'yemek'   => ['type'=>'gider',  'category'=>'Yemek gideri',       'payment_method'=>'nakit'],
    'gider'   => ['type'=>'gider',  'category'=>'Günlük harcama',     'payment_method'=>'nakit'],
    'gelir'   => ['type'=>'gelir',  'category'=>'Hesabıma gelen para','payment_method'=>'banka'],
    'havale'  => ['type'=>'havale', 'category'=>'Gönderilen havale',  'payment_method'=>'havale'],
    'malzeme' => ['type'=>'gider',  'category'=>'Şirket malzemesi',   'payment_method'=>'sirket_karti'],
    'nakit'   => ['type'=>'nakit',  'category'=>'Elden ödeme',        'payment_method'=>'nakit'],
];
$qd = $quick_defaults[$hizli] ?? [];

// Kayıt yükle (edit modu)
$record = [
    'transaction_date'     => date('Y-m-d'),
    'transaction_time'     => date('H:i'),
    'type'                 => $qd['type'] ?? 'gider',
    'category'             => $qd['category'] ?? '',
    'amount'               => '',
    'currency'             => 'TRY',
    'payment_method'       => $qd['payment_method'] ?? 'nakit',
    'person_company'       => '',
    'description'          => '',
    'document_no'          => '',
    'has_invoice'          => 0,
    'is_for_company'       => 1,
    'is_given_to_accountant' => 0,
    'notes'                => '',
    'status'               => 'submitted',
    'user_id'              => (int)$auth_user['id'],
    'depo'                 => active_depot() ?? '',
    'review_note'          => '',
];
$existing_files = [];
if ($id > 0) {
    $st = db()->prepare("SELECT * FROM account_transactions WHERE id=?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        set_flash('error', 'Kayıt bulunamadı.');
        header('Location: hesap_liste.php');
        exit;
    }
    if (!hesap_row_visible($row)) {
        forbidden('Bu kayıt size görünür değil.');
    }
    if (hesap_is_locked($row)) {
        set_flash('error', 'Ödenmiş kayıt kilitlidir. Değişiklik için sistem yöneticisine başvurun.');
        header('Location: hesap_liste.php');
        exit;
    }
    $record = $row;
    $old_for_audit = $row;
    $existing_files = hesap_get_files($id);
}

// POST işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $record['transaction_date']       = trim($_POST['transaction_date'] ?? date('Y-m-d')) ?: date('Y-m-d');
    $record['transaction_time']       = trim($_POST['transaction_time'] ?? '00:00') ?: '00:00';
    $record['type']                   = trim($_POST['type'] ?? 'gider');
    $record['category']               = trim($_POST['category'] ?? '');
    $record['amount']                 = trim($_POST['amount'] ?? '');
    $record['currency']               = trim($_POST['currency'] ?? 'TRY');
    $record['payment_method']         = trim($_POST['payment_method'] ?? 'nakit');
    $record['person_company']         = trim($_POST['person_company'] ?? '');
    $record['description']            = trim($_POST['description'] ?? '');
    $record['document_no']            = trim($_POST['document_no'] ?? '');
    $record['has_invoice']            = isset($_POST['has_invoice']) ? 1 : 0;
    $record['is_for_company']         = isset($_POST['is_for_company']) ? 1 : 0;
    // is_given_to_accountant artık formdan gelmez — durum makinesi belirler ($legacy_muh)
    $record['notes']                  = trim($_POST['notes'] ?? '');

    if (!in_array($record['type'], ['gelir','gider','havale','nakit'], true)) {
        $errors[] = 'Geçersiz tür.';
    }
    // B1: "1234.56" gibi nokta-ondalık girdiler 123456 oluyordu — hesap_parse_amount() düzeltir
    $amount_float = hesap_parse_amount($record['amount']);
    if ($amount_float <= 0) {
        $errors[] = 'Tutar 0\'dan büyük olmalı.';
    }

    // Taslak olarak kaydet / muhasebeye gönder
    $yeni_durum = (($_POST['kaydet_turu'] ?? '') === 'taslak') ? 'draft' : 'submitted';
    if ($id > 0) {
        // Mevcut kayıt: onay sürecine girmiş bir kaydın durumu düzenlemeyle sıfırlanmaz;
        // yalnız taslak/reddedilen kayıtlar yeniden gönderilebilir.
        $mevcut = (string)($old_for_audit['status'] ?? 'submitted');
        $yeni_durum = in_array($mevcut, ['draft', 'rejected'], true) ? $yeni_durum : $mevcut;
    }

    if (empty($errors)) {
        $pdo = db();
        $is_update = $id > 0;
        // Durum ↔ legacy bayrak senkron: bakiyeye giren durumlar muhasebeye verilmiş sayılır
        $legacy_muh = in_array($yeni_durum, hesap_balance_statuses(), true) ? 1 : 0;

        if ($is_update) {
            $pdo->prepare("UPDATE account_transactions SET transaction_date=?,transaction_time=?,type=?,category=?,amount=?,currency=?,payment_method=?,person_company=?,description=?,document_no=?,has_invoice=?,is_for_company=?,is_given_to_accountant=?,notes=?,status=?,submitted_at=CASE WHEN ? THEN COALESCE(submitted_at,NOW()) ELSE submitted_at END WHERE id=?")
                ->execute([
                    $record['transaction_date'], $record['transaction_time'], $record['type'],
                    $record['category'], $amount_float, $record['currency'], $record['payment_method'],
                    $record['person_company'], $record['description'], $record['document_no'],
                    (int)$record['has_invoice'], (int)$record['is_for_company'],
                    $legacy_muh, $record['notes'], $yeni_durum,
                    $yeni_durum === 'submitted' ? 1 : 0, $id,
                ]);
        } else {
            $pdo->prepare("INSERT INTO account_transactions (user_id,created_by,depo,transaction_date,transaction_time,type,category,amount,currency,payment_method,person_company,description,document_no,has_invoice,is_for_company,is_given_to_accountant,notes,status,submitted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    (int)$auth_user['id'], (int)$auth_user['id'], active_depot() ?? '',
                    $record['transaction_date'], $record['transaction_time'], $record['type'],
                    $record['category'], $amount_float, $record['currency'], $record['payment_method'],
                    $record['person_company'], $record['description'], $record['document_no'],
                    (int)$record['has_invoice'], (int)$record['is_for_company'],
                    $legacy_muh, $record['notes'], $yeni_durum,
                    $yeni_durum === 'submitted' ? date('Y-m-d H:i:s') : null,
                ]);
            $id = (int)$pdo->lastInsertId();
        }

        // Audit — mali kayıt create/update
        $new_summary = [
            'transaction_date' => $record['transaction_date'],
            'type'             => $record['type'],
            'category'         => $record['category'],
            'amount'           => $amount_float,
            'currency'         => $record['currency'],
            'payment_method'   => $record['payment_method'],
            'person_company'   => $record['person_company'],
            'status'           => $yeni_durum,
        ];
        if ($is_update) {
            $old_summary = isset($old_for_audit) ? [
                'transaction_date' => $old_for_audit['transaction_date'],
                'type'             => $old_for_audit['type'],
                'category'         => $old_for_audit['category'],
                'amount'           => (float)$old_for_audit['amount'],
                'currency'         => $old_for_audit['currency'],
                'payment_method'   => $old_for_audit['payment_method'],
                'person_company'   => $old_for_audit['person_company'],
                'status'           => $old_for_audit['status'] ?? null,
            ] : null;
            audit_log_event('update', 'hesap', $id, $old_summary, $new_summary);
        } else {
            audit_log_event('create', 'hesap', $id, null, $new_summary);
        }

        // Dosya yükleme
        if (!empty($_FILES['dosyalar']['name'][0])) {
            foreach ($_FILES['dosyalar']['name'] as $i => $fname) {
                if ($_FILES['dosyalar']['error'][$i] !== UPLOAD_ERR_OK || !$fname) continue;
                $single = [
                    'name'     => $fname,
                    'type'     => $_FILES['dosyalar']['type'][$i],
                    'tmp_name' => $_FILES['dosyalar']['tmp_name'][$i],
                    'error'    => $_FILES['dosyalar']['error'][$i],
                    'size'     => $_FILES['dosyalar']['size'][$i],
                ];
                $up = hesap_upload_file($single, $id);
                if (!empty($up['ok'])) {
                    audit_log_event('upload', 'hesap', $id, null, [
                        'original_name' => $up['original_name'] ?? basename($fname),
                        'file_type'     => $single['type'],
                        'file_size'     => (int)$single['size'],
                    ]);
                }
            }
        }
        set_flash('success', $is_update ? 'Kayıt güncellendi.' : 'Kayıt eklendi.');
        header('Location: hesap_liste.php');
        exit;
    }
}

$kategoriler = hesap_kategoriler();
$title = $id > 0 ? 'Kayıt Düzenle #' . $id : ($hizli ? ucfirst($hizli) . ' Ekle' : 'Yeni Kayıt');
render_header($title);
hesap_assets();
render_flash();
?>
<div class="page-head">
    <h1><?= h($title) ?></h1>
    <div><a href="hesap_liste.php" class="btn btn-ghost">İptal</a></div>
</div>
<?php if ($id > 0): ?>
<div style="margin-bottom:10px">
    <?= hesap_status_badge($record['status'] ?? null) ?>
    <?php if (($record['status'] ?? '') === 'rejected' && trim((string)$record['review_note']) !== ''): ?>
    <div class="hs-note">Red gerekçesi: <?= h($record['review_note']) ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php if (!empty($errors)): foreach ($errors as $e): ?>
<div class="flash flash-error"><?= h($e) ?></div>
<?php endforeach; endif; ?>

<form method="post" enctype="multipart/form-data" class="record-form" id="hesapKayitForm">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
<?php if ($id > 0): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

<section class="card">
    <div class="card-head"><h2>İşlem Bilgileri</h2></div>
    <div class="card-body">
        <div class="grid">
            <label>Tarih *
                <input type="date" name="transaction_date" value="<?= h($record['transaction_date']) ?>" required>
            </label>
            <label>Saat
                <input type="time" name="transaction_time" value="<?= h(substr((string)$record['transaction_time'], 0, 5)) ?>">
            </label>
            <label>Tür *
                <select name="type" id="kayitTur" required>
                    <?php foreach (['gelir','gider','havale','nakit'] as $t): ?>
                    <option value="<?= $t ?>" <?= $record['type'] === $t ? 'selected' : '' ?>><?= hesap_type_label($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Kategori
                <select name="category" id="kayitKategori">
                    <option value="">— seçiniz —</option>
                    <?php foreach ($kategoriler as $type => $cats): ?>
                    <?php foreach ($cats as $cat): ?>
                    <option value="<?= h($cat) ?>" data-type="<?= $type ?>" <?= $record['category'] === $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Tutar *
                <input type="text" name="amount" inputmode="decimal"
                       value="<?= h($record['amount'] > 0 ? number_format((float)$record['amount'], 2, ',', '.') : '') ?>"
                       placeholder="0,00" required style="font-size:1.3rem;font-weight:600">
            </label>
            <label>Para Birimi
                <select name="currency">
                    <?php foreach (['TRY','USD','EUR','AED'] as $c): ?>
                    <option value="<?= $c ?>" <?= $record['currency'] === $c ? 'selected' : '' ?>><?= $c ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Ödeme Yöntemi
                <select name="payment_method">
                    <?php foreach (['nakit'=>'Nakit','banka'=>'Banka','kredi_karti'=>'Kredi Kartı','havale'=>'Havale','sirket_karti'=>'Şirket Kartı','sahsi'=>'Şahsi'] as $v => $l): ?>
                    <option value="<?= $v ?>" <?= $record['payment_method'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Kişi / Firma
                <input type="text" name="person_company" value="<?= h($record['person_company']) ?>" placeholder="Alınan / verilen kişi veya firma">
            </label>
            <label class="span-2">Açıklama
                <textarea name="description" rows="2" placeholder="Kısa açıklama..."><?= h($record['description']) ?></textarea>
            </label>
            <label>Belge / Fiş No
                <input type="text" name="document_no" value="<?= h($record['document_no']) ?>" placeholder="Fiş veya belge numarası">
            </label>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:12px">
            <label style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="has_invoice" <?= $record['has_invoice'] ? 'checked' : '' ?>> Fatura / Fiş var
            </label>
            <label style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="is_for_company" <?= $record['is_for_company'] ? 'checked' : '' ?>> Şirket için masraf
            </label>
        </div>
        <p class="muted" style="font-size:.78rem;margin:8px 0 0">
            Muhasebe onayı artık durum akışıyla yürür — kayıt gönderildikten sonra
            onay/red işlemini muhasebe yapar.
        </p>
        <label style="display:block;margin-top:12px">Muhasebe Notu
            <textarea name="notes" rows="2" placeholder="Muhasebeciye özel not..."><?= h($record['notes']) ?></textarea>
        </label>
    </div>
</section>

<!-- Dosya Yükleme — hesap'a özel, kantardan tamamen bağımsız foto alanı -->
<section class="card" data-hesap-photo-root>
    <div class="card-head"><h2>📷 Fiş Fotoğrafları</h2></div>
    <div class="card-body">
        <p class="muted hesap-photo-intro">Fotoğraf çekebilir, galeriden seçebilir ve fiş alanını kırpabilirsiniz.</p>
        <?php if (!empty($existing_files)): ?>
        <div class="hesap-file-grid" id="existingFiles">
            <?php foreach ($existing_files as $f): ?>
            <div class="hesap-file-item" data-fid="<?= $f['id'] ?>">
                <?php if (hesap_is_image($f['file_name'])): ?>
                <img src="hesap_dosya.php?f=<?= urlencode($f['file_name']) ?>" class="hesap-thumb" onclick="hesapZoom(this.src)" loading="lazy">
                <?php else: ?>
                <div class="hesap-file-icon">📄</div>
                <a href="hesap_dosya.php?f=<?= urlencode($f['file_name']) ?>" target="_blank"><?= h($f['original_name']) ?></a>
                <?php endif; ?>
                <button type="button" class="hesap-file-del" data-fid="<?= $f['id'] ?>" onclick="hesapDelFile(this)">✕</button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="hesap-photo-actions">
            <label class="btn">
                📷 Kamera ile Çek
                <input type="file" class="hesap-photo-cam" accept="image/*" capture="environment" hidden>
            </label>
            <label class="btn">
                🖼 Galeriden Seç
                <input type="file" class="hesap-photo-gal" accept="image/*,.pdf" multiple hidden>
            </label>
        </div>
        <small class="muted" style="display:block;margin-top:6px">JPG, PNG, WEBP, PDF — Maks 10 MB · Birden fazla eklenebilir</small>
        <!-- Gerçek gönderilen input — DataTransfer ile yönetilir -->
        <input type="file" name="dosyalar[]" class="hesap-photo-real" multiple accept="image/*,.pdf" hidden>
        <div class="hesap-photo-preview"></div>
    </div>

    <!-- Fiş alanı kırpma modalı — hesap'a özel -->
    <div class="hesap-crop-modal" hidden>
        <div class="hesap-crop-dialog">
            <div class="hesap-crop-head">
                <strong>Fiş Alanını Seç</strong>
                <span class="muted">Fişin bulunduğu alanı seçin. Arka plan kırpılacaktır.</span>
            </div>
            <div class="hesap-crop-stage">
                <div class="hesap-crop-imgwrap">
                    <img class="hesap-crop-img" alt="">
                    <div class="hesap-crop-selection">
                        <span class="hesap-crop-handle" data-c="tl"></span>
                        <span class="hesap-crop-handle" data-c="tr"></span>
                        <span class="hesap-crop-handle" data-c="bl"></span>
                        <span class="hesap-crop-handle" data-c="br"></span>
                    </div>
                </div>
            </div>
            <div class="hesap-crop-foot">
                <button type="button" class="btn btn-ghost hesap-crop-cancel">Vazgeç</button>
                <button type="button" class="btn btn-primary hesap-crop-ok">Kırp ve Kullan</button>
            </div>
        </div>
    </div>
</section>

<?php
// Onay sürecine girmiş kayıtlarda durum düzenlemeyle değişmez — buton da gösterilmez
$durum_secilebilir = ($id === 0) || in_array((string)($record['status'] ?? 'submitted'), ['draft','rejected'], true);
?>
<div class="form-foot">
    <a href="hesap_liste.php" class="btn btn-ghost">İptal</a>
    <?php if ($durum_secilebilir): ?>
    <button type="submit" name="kaydet_turu" value="taslak" class="btn">Taslak Kaydet</button>
    <button type="submit" name="kaydet_turu" value="gonder" class="btn btn-primary btn-lg">Kaydet ve Muhasebeye Gönder</button>
    <?php else: ?>
    <button type="submit" class="btn btn-primary btn-lg">Kaydet</button>
    <?php endif; ?>
</div>
</form>

<!-- Zoom overlay -->
<div id="hesapZoomOvl" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9000;cursor:zoom-out" onclick="this.style.display='none'">
    <img id="hesapZoomImg" style="max-width:95vw;max-height:95vh;position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);border-radius:8px">
</div>

<script>
var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

// Kategori filtrele — sadece seçilen türe ait kategoriler
var turSel = document.getElementById('kayitTur');
var katSel = document.getElementById('kayitKategori');
function filterKat() {
    var t = turSel.value;
    Array.from(katSel.options).forEach(function(o) {
        if (!o.value) { o.style.display = ''; return; }
        o.style.display = (o.dataset.type === t || !o.dataset.type) ? '' : 'none';
    });
}
turSel.addEventListener('change', filterKat);
filterKat();

// ── Hesap'a özel fotoğraf/dosya seçimi (hesapPhoto*) ──
// Tamamen [data-hesap-photo-root] içine kapsüllenmiştir; kantar foto koduyla
// hiçbir global isim/seçici paylaşmaz. Kamera ve galeri ayrı inputlar; seçilenler
// DataTransfer ile birikir, gerçek gönderilen .hesap-photo-real input senkron tutulur.
(function hesapPhotoInit() {
    var root = document.querySelector('[data-hesap-photo-root]');
    if (!root) return;
    var camInput  = root.querySelector('.hesap-photo-cam');
    var galInput  = root.querySelector('.hesap-photo-gal');
    var realInput = root.querySelector('.hesap-photo-real');
    var preview   = root.querySelector('.hesap-photo-preview');
    if (!realInput || !preview) return;
    var hesapPhotoDT = new DataTransfer();
    var canCrop = (typeof DataTransfer !== 'undefined') && (typeof File === 'function');

    function hesapPhotoSync() {
        try { realInput.files = hesapPhotoDT.files; } catch (e) {}
    }
    function hesapPhotoAdd(fileList) {
        Array.from(fileList).forEach(function(f) {
            if (!f) return;
            if (f.size > 10 * 1024 * 1024) {
                alert((f.name || 'Dosya') + ' 10 MB sınırını aşıyor, eklenmedi.');
                return;
            }
            hesapPhotoDT.items.add(f);
        });
        hesapPhotoSync();
        hesapPhotoRender();
    }
    function hesapPhotoRemoveAt(idx) {
        var ndt = new DataTransfer();
        Array.from(hesapPhotoDT.files).forEach(function(f, i) { if (i !== idx) ndt.items.add(f); });
        hesapPhotoDT = ndt;
        hesapPhotoSync();
        hesapPhotoRender();
    }
    function hesapPhotoReplaceAt(idx, file) {
        var ndt = new DataTransfer();
        Array.from(hesapPhotoDT.files).forEach(function(f, i) { ndt.items.add(i === idx ? file : f); });
        hesapPhotoDT = ndt;
        hesapPhotoSync();
        hesapPhotoRender();
    }
    function hesapPhotoRender() {
        preview.innerHTML = '';
        Array.from(hesapPhotoDT.files).forEach(function(f, idx) {
            var isImg = f.type && f.type.indexOf('image/') === 0;
            var div = document.createElement('div');
            div.className = 'hesap-photo-item';
            if (isImg) {
                var img = document.createElement('img');
                img.className = 'hesap-thumb';
                img.onclick = function() { hesapZoom(img.src); };
                var reader = new FileReader();
                reader.onload = function(e) { img.src = e.target.result; };
                reader.readAsDataURL(f);
                div.appendChild(img);
                if (canCrop) {
                    var cropBtn = document.createElement('button');
                    cropBtn.type = 'button';
                    cropBtn.className = 'btn btn-sm hesap-photo-crop-btn';
                    cropBtn.textContent = '✂ Fiş Alanını Seç';
                    cropBtn.onclick = function() { hesapCropOpen(idx); };
                    div.appendChild(cropBtn);
                }
            } else {
                var ic = document.createElement('div');
                ic.className = 'hesap-file-icon';
                ic.textContent = '📄';
                div.appendChild(ic);
                var nm = document.createElement('div');
                nm.className = 'hesap-photo-fname';
                nm.textContent = f.name;
                div.appendChild(nm);
            }
            var del = document.createElement('button');
            del.type = 'button';
            del.className = 'hesap-file-del';
            del.textContent = '✕';
            del.onclick = function() { hesapPhotoRemoveAt(idx); };
            div.appendChild(del);
            preview.appendChild(div);
        });
    }

    if (camInput) camInput.addEventListener('change', function() {
        if (this.files && this.files.length) hesapPhotoAdd(this.files);
        this.value = '';
    });
    if (galInput) galInput.addEventListener('change', function() {
        if (this.files && this.files.length) hesapPhotoAdd(this.files);
        this.value = '';
    });

    /* ── Fiş alanı kırpma (hesapCrop*) ── */
    var cropModal  = root.querySelector('.hesap-crop-modal');
    var cropImg    = root.querySelector('.hesap-crop-img');
    var cropSel    = root.querySelector('.hesap-crop-selection');
    var cropOkBtn  = root.querySelector('.hesap-crop-ok');
    var cropCancel = root.querySelector('.hesap-crop-cancel');
    var hesapCropIdx = -1;
    var sel = { x: 0, y: 0, w: 0, h: 0 };
    var dragMode = null, startX = 0, startY = 0, startSel = null;
    var MIN = 28;

    function hesapCropRenderSel() {
        cropSel.style.left   = sel.x + 'px';
        cropSel.style.top    = sel.y + 'px';
        cropSel.style.width  = sel.w + 'px';
        cropSel.style.height = sel.h + 'px';
    }
    function hesapCropClamp() {
        var W = cropImg.offsetWidth, H = cropImg.offsetHeight;
        if (sel.w > W) sel.w = W;
        if (sel.h > H) sel.h = H;
        if (sel.w < MIN) sel.w = MIN;
        if (sel.h < MIN) sel.h = MIN;
        if (sel.x < 0) sel.x = 0;
        if (sel.y < 0) sel.y = 0;
        if (sel.x + sel.w > W) sel.x = W - sel.w;
        if (sel.y + sel.h > H) sel.y = H - sel.h;
    }
    function hesapCropOpen(idx) {
        if (!cropModal || !canCrop) return;
        var f = hesapPhotoDT.files[idx];
        if (!f || f.type.indexOf('image/') !== 0) return;
        hesapCropIdx = idx;
        var r = new FileReader();
        r.onload = function(ev) {
            cropImg.onload = function() {
                cropModal.removeAttribute('hidden');
                requestAnimationFrame(function() {
                    var W = cropImg.offsetWidth, H = cropImg.offsetHeight;
                    sel = { x: W * 0.1, y: H * 0.1, w: W * 0.8, h: H * 0.8 };
                    hesapCropClamp();
                    hesapCropRenderSel();
                });
            };
            cropImg.src = ev.target.result;
        };
        r.readAsDataURL(f);
    }
    function hesapCropClose() {
        if (cropModal) cropModal.setAttribute('hidden', '');
        cropImg.src = '';
        hesapCropIdx = -1;
        dragMode = null;
    }
    function hesapCropPoint(e) {
        var t = e.touches && e.touches.length ? e.touches[0] : e;
        return { x: t.clientX, y: t.clientY };
    }
    function hesapCropDown(e) {
        var c = e.target.getAttribute && e.target.getAttribute('data-c');
        dragMode = c ? c : 'move';
        var p = hesapCropPoint(e);
        startX = p.x; startY = p.y;
        startSel = { x: sel.x, y: sel.y, w: sel.w, h: sel.h };
        e.preventDefault();
    }
    function hesapCropMove(e) {
        if (!dragMode) return;
        var p = hesapCropPoint(e);
        var dx = p.x - startX, dy = p.y - startY;
        var s = { x: startSel.x, y: startSel.y, w: startSel.w, h: startSel.h };
        var W = cropImg.offsetWidth, H = cropImg.offsetHeight;
        if (dragMode === 'move') {
            s.x += dx; s.y += dy;
        } else {
            if (dragMode.indexOf('l') >= 0) { var nx = s.x + dx, mx = s.x + s.w - MIN; if (nx < 0) nx = 0; if (nx > mx) nx = mx; s.w = s.x + s.w - nx; s.x = nx; }
            if (dragMode.indexOf('r') >= 0) { s.w = s.w + dx; if (s.w < MIN) s.w = MIN; if (s.x + s.w > W) s.w = W - s.x; }
            if (dragMode.indexOf('t') >= 0) { var ny = s.y + dy, my = s.y + s.h - MIN; if (ny < 0) ny = 0; if (ny > my) ny = my; s.h = s.y + s.h - ny; s.y = ny; }
            if (dragMode.indexOf('b') >= 0) { s.h = s.h + dy; if (s.h < MIN) s.h = MIN; if (s.y + s.h > H) s.h = H - s.y; }
        }
        sel = s; hesapCropClamp(); hesapCropRenderSel();
        e.preventDefault();
    }
    function hesapCropUp() { dragMode = null; }
    function hesapCropApply() {
        var W = cropImg.offsetWidth, H = cropImg.offsetHeight;
        var nW = cropImg.naturalWidth, nH = cropImg.naturalHeight;
        if (!W || !H || !nW) { hesapCropClose(); return; }
        var rx = nW / W, ry = nH / H;
        var sx = Math.max(0, sel.x * rx), sy = Math.max(0, sel.y * ry);
        var sw = Math.min(nW - sx, sel.w * rx), sh = Math.min(nH - sy, sel.h * ry);
        var outW = sw, outH = sh, MAX = 1600;
        if (outW > MAX || outH > MAX) { var sc = Math.min(MAX / outW, MAX / outH); outW = outW * sc; outH = outH * sc; }
        var cv = document.createElement('canvas');
        cv.width  = Math.max(1, Math.round(outW));
        cv.height = Math.max(1, Math.round(outH));
        cv.getContext('2d').drawImage(cropImg, sx, sy, sw, sh, 0, 0, cv.width, cv.height);
        var idx = hesapCropIdx;
        cv.toBlob(function(blob) {
            if (!blob) { hesapCropClose(); return; }
            var file = new File([blob], 'fis_' + Date.now() + '.jpg', { type: 'image/jpeg' });
            hesapPhotoReplaceAt(idx, file);
            hesapCropClose();
        }, 'image/jpeg', 0.85);
    }

    if (cropSel) {
        cropSel.addEventListener('mousedown', hesapCropDown);
        cropSel.addEventListener('touchstart', hesapCropDown, { passive: false });
    }
    document.addEventListener('mousemove', hesapCropMove);
    document.addEventListener('touchmove', hesapCropMove, { passive: false });
    document.addEventListener('mouseup', hesapCropUp);
    document.addEventListener('touchend', hesapCropUp);
    if (cropOkBtn)  cropOkBtn.addEventListener('click', hesapCropApply);
    if (cropCancel) cropCancel.addEventListener('click', hesapCropClose);
    if (cropModal)  cropModal.addEventListener('click', function(e) { if (e.target === cropModal) hesapCropClose(); });
})();

function hesapZoom(src) {
    document.getElementById('hesapZoomImg').src = src;
    document.getElementById('hesapZoomOvl').style.display = 'block';
}

function hesapDelFile(btn) {
    if (!confirm('Dosya silinsin mi?')) return;
    var fid = btn.dataset.fid;
    fetch('hesap_dosya_sil.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + fid + '&csrf=' + encodeURIComponent(csrf)
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.ok) btn.closest('.hesap-file-item').remove();
        else alert(d.msg || 'Hata');
    });
}

</script>
<?php render_footer(); ?>
