<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('records.write');

// Session $_SESSION'a yazmadan önce başlatılmalı — csrf_token() lazy başlatır ama o çok geç olur
if (session_status() === PHP_SESSION_NONE) session_start();

$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$hizli = trim($_GET['hizli'] ?? '');

// Yeni kayıt için idempotency token — çift submit'i önler
if ($id === 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['hesap_form_token'] = bin2hex(random_bytes(16));
}
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
    $record = $row;
    $old_for_audit = $row;
    $existing_files = hesap_get_files($id);
}

// POST işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    // Yeni kayıt: çift submit engeli (fail-open — token yoksa veya session uyuşmuyorsa geçir)
    if ($id === 0) {
        $submitted_token = $_POST['form_token'] ?? '';

        if ($submitted_token !== '') {
            // Eski used_token'ları temizle (30 dakikadan eski)
            $now = time();
            $used_tokens = $_SESSION['used_hesap_tokens'] ?? [];
            foreach ($used_tokens as $_t => $_ts) {
                if ($now - $_ts > 1800) unset($used_tokens[$_t]);
            }
            $_SESSION['used_hesap_tokens'] = $used_tokens;

            // Yalnızca kesin çift-submit kanıtında engelle: bu token daha önce başarıyla kullanıldı
            if (isset($used_tokens[$submitted_token])) {
                set_flash('warning', 'Bu form daha önce gönderildi, ikinci kez işlenmedi.');
                header('Location: hesap_liste.php');
                exit;
            }
        }
        // Token boşsa veya session'da uyuşmuyorsa: sessizce geçir (CSRF koruması yeterli)
    }

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
    $record['is_given_to_accountant'] = isset($_POST['is_given_to_accountant']) ? 1 : 0;
    $record['notes']                  = trim($_POST['notes'] ?? '');

    if (!in_array($record['type'], ['gelir','gider','havale','nakit'], true)) {
        $errors[] = 'Geçersiz tür.';
    }
    $amount_float = (float)str_replace(['.', ','], ['', '.'], $record['amount']);
    if ($amount_float <= 0) {
        $errors[] = 'Tutar 0\'dan büyük olmalı.';
    }

    if (empty($errors)) {
        $pdo = db();
        $is_update = $id > 0;
        if ($is_update) {
            $pdo->prepare("UPDATE account_transactions SET transaction_date=?,transaction_time=?,type=?,category=?,amount=?,currency=?,payment_method=?,person_company=?,description=?,document_no=?,has_invoice=?,is_for_company=?,is_given_to_accountant=?,notes=? WHERE id=?")
                ->execute([
                    $record['transaction_date'], $record['transaction_time'], $record['type'],
                    $record['category'], $amount_float, $record['currency'], $record['payment_method'],
                    $record['person_company'], $record['description'], $record['document_no'],
                    (int)$record['has_invoice'], (int)$record['is_for_company'],
                    (int)$record['is_given_to_accountant'], $record['notes'], $id,
                ]);
        } else {
            $pdo->prepare("INSERT INTO account_transactions (transaction_date,transaction_time,type,category,amount,currency,payment_method,person_company,description,document_no,has_invoice,is_for_company,is_given_to_accountant,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $record['transaction_date'], $record['transaction_time'], $record['type'],
                    $record['category'], $amount_float, $record['currency'], $record['payment_method'],
                    $record['person_company'], $record['description'], $record['document_no'],
                    (int)$record['has_invoice'], (int)$record['is_for_company'],
                    (int)$record['is_given_to_accountant'], $record['notes'],
                ]);
            $id = (int)$pdo->lastInsertId();

            // Token'ı başarılı insert sonrasında tüket — geri tuşu koruması için used listesine ekle
            $now = time();
            $used_tokens = $_SESSION['used_hesap_tokens'] ?? [];
            foreach ($used_tokens as $_t => $_ts) {
                if ($now - $_ts > 1800) unset($used_tokens[$_t]);
            }
            $token_used = $_POST['form_token'] ?? '';
            if ($token_used !== '') {
                $used_tokens[$token_used] = $now;
            }
            $_SESSION['used_hesap_tokens'] = $used_tokens;
            unset($_SESSION['hesap_form_token']);
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
                    // Audit — dosya yükleme (içerik loglanmaz, sadece meta)
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
render_flash();
?>
<div class="page-head">
    <h1><?= h($title) ?></h1>
    <div><a href="hesap_liste.php" class="btn btn-ghost">İptal</a></div>
</div>
<?php if (!empty($errors)): foreach ($errors as $e): ?>
<div class="flash flash-error"><?= h($e) ?></div>
<?php endforeach; endif; ?>

<form method="post" enctype="multipart/form-data" class="record-form" id="hesapKayitForm">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
<?php if ($id > 0): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
<?php if ($id === 0): ?><input type="hidden" name="form_token" value="<?= h($_SESSION['hesap_form_token'] ?? '') ?>"><?php endif; ?>

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
            <label style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="is_given_to_accountant" <?= $record['is_given_to_accountant'] ? 'checked' : '' ?>> Muhasebeye verildi
            </label>
        </div>
        <label style="display:block;margin-top:12px">Muhasebe Notu
            <textarea name="notes" rows="2" placeholder="Muhasebeciye özel not..."><?= h($record['notes']) ?></textarea>
        </label>
    </div>
</section>

<!-- Dosya Yükleme -->
<section class="card">
    <div class="card-head"><h2>📎 Fiş / Dekont</h2></div>
    <div class="card-body">
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
        <label class="hesap-upload-label" for="dosyalarInput">
            <div class="hesap-upload-area" id="uploadArea">
                <span>📷 Fotoğraf çek veya dosya seç</span>
                <small>JPG, PNG, WEBP, PDF — Maks 10 MB</small>
            </div>
        </label>
        <input type="file" name="dosyalar[]" id="dosyalarInput" multiple
               accept="image/*,.pdf" capture="environment" style="display:none"
               onchange="hesapPreview(this)">
        <div id="previewGrid" class="hesap-file-grid" style="margin-top:8px"></div>
    </div>
</section>

<div class="form-foot">
    <a href="hesap_liste.php" class="btn btn-ghost">İptal</a>
    <button type="submit" class="btn btn-primary btn-lg">Kaydet</button>
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

// Dosya önizleme
function hesapPreview(inp) {
    var grid = document.getElementById('previewGrid');
    grid.innerHTML = '';
    Array.from(inp.files).forEach(function(f) {
        var div = document.createElement('div');
        div.className = 'hesap-file-item';
        if (f.type.startsWith('image/')) {
            var img = document.createElement('img');
            img.className = 'hesap-thumb';
            img.onclick = function() { hesapZoom(img.src); };
            var reader = new FileReader();
            reader.onload = function(e) { img.src = e.target.result; };
            reader.readAsDataURL(f);
            div.appendChild(img);
        } else {
            div.textContent = '📄 ' + f.name;
        }
        grid.appendChild(div);
    });
}

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

// Upload area tıklama
document.getElementById('uploadArea').addEventListener('click', function() {
    document.getElementById('dosyalarInput').click();
});

// Çift submit engeli — form gönderilince kaydet butonunu devre dışı bırak
(function () {
    var form = document.getElementById('hesapKayitForm');
    if (!form) return;
    form.addEventListener('submit', function () {
        var btn = form.querySelector('button[type="submit"]');
        if (!btn) return;
        btn.disabled = true;
        btn.textContent = 'Kaydediliyor...';
    });
})();
</script>
<?php render_footer(); ?>
