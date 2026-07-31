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
$title = $id > 0 ? 'Kayıt Düzenle #' . $id : 'Yeni Kayıt';

// Chip olarak gösterilecek öne çıkan kategoriler (tür başına ilk 5).
// Tam liste her zaman Detaylar içindeki <select>'te durur.
$one_cikan = [];
foreach ($kategoriler as $tur => $liste) {
    $one_cikan[$tur] = array_slice($liste, 0, 5);
}
$aktif_tur = (string)$record['type'];

render_header($title);
hesap_assets();
render_flash();
?>
<div class="hs">

<div class="page-head">
    <div>
        <h1><?= h($title) ?></h1>
        <?php if ($id > 0): ?>
        <p class="muted"><?= hesap_status_badge($record['status'] ?? null) ?></p>
        <?php else: ?>
        <p class="muted">Fişi çekin, tutarı yazın, kaydedin.</p>
        <?php endif; ?>
    </div>
    <a href="hesap_liste.php" class="btn btn-ghost">İptal</a>
</div>

<?php if (($record['status'] ?? '') === 'rejected' && trim((string)$record['review_note']) !== ''): ?>
<div class="hs-alert" role="alert">
    <strong>Bu fiş reddedildi</strong>
    <?= h($record['review_note']) ?>
</div>
<?php endif; ?>

<?php if (!empty($errors)): foreach ($errors as $e): ?>
<div class="flash flash-error"><?= h($e) ?></div>
<?php endforeach; endif; ?>

<form method="post" enctype="multipart/form-data" class="hs-form" id="hsForm">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
<?php if ($id > 0): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

<!-- ═══ 1. FİŞ FOTOĞRAFI — en üstte ═══ -->
<section class="hs-step" data-hs-photo>
    <p class="hs-step-label"><span class="hs-step-num">1</span> Fiş Fotoğrafı</p>

    <label class="hs-photo-drop">
        <span class="hs-photo-drop-icon" aria-hidden="true">📷</span>
        <span class="hs-photo-drop-main">Fişi Çek</span>
        <span class="hs-photo-drop-sub">Dokunun — kamera açılır</span>
        <input type="file" class="hs-photo-cam" accept="image/*" capture="environment" hidden>
    </label>

    <div class="hs-photo-alt">
        <label class="btn">
            🖼 Galeriden Seç
            <input type="file" class="hs-photo-gal" accept="image/*,.pdf" multiple hidden>
        </label>
    </div>

    <!-- Gerçek gönderilen input — DataTransfer ile senkron tutulur -->
    <input type="file" name="dosyalar[]" class="hs-photo-real" multiple accept="image/*,.pdf" hidden>

    <!-- Yeni seçilen dosyalar -->
    <div class="hs-photo-grid"></div>

    <!-- Kayıtlı dosyalar -->
    <?php if (!empty($existing_files)): ?>
    <div class="hs-photo-grid">
        <?php foreach ($existing_files as $f): ?>
        <div class="hs-photo-item">
            <?php if (hesap_is_image($f['file_name'])): ?>
            <img src="hesap_dosya.php?f=<?= urlencode($f['file_name']) ?>" alt="Fiş" loading="lazy" data-hs-zoom>
            <?php else: ?>
            <div class="hs-photo-file" aria-hidden="true">📄</div>
            <div class="hs-photo-name"><?= h($f['original_name']) ?></div>
            <?php endif; ?>
            <button type="button" class="hs-photo-del" data-hs-file-del="<?= (int)$f['id'] ?>" aria-label="Sil">✕</button>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <p class="muted" style="font-size:.75rem;margin:8px 0 0">JPG, PNG, WEBP, PDF · en fazla 10 MB · birden fazla eklenebilir</p>

    <!-- Kırpma modalı -->
    <div class="hs-crop" hidden>
        <div class="hs-crop-dialog">
            <div class="hs-crop-head">
                <strong>Fiş Alanını Seç</strong>
                <span>Fişin bulunduğu alanı seçin, arka plan kırpılacak.</span>
            </div>
            <div class="hs-crop-stage">
                <div class="hs-crop-imgwrap">
                    <img class="hs-crop-img" alt="">
                    <div class="hs-crop-sel">
                        <span class="hs-crop-handle" data-c="tl"></span>
                        <span class="hs-crop-handle" data-c="tr"></span>
                        <span class="hs-crop-handle" data-c="bl"></span>
                        <span class="hs-crop-handle" data-c="br"></span>
                    </div>
                </div>
            </div>
            <div class="hs-crop-foot">
                <button type="button" class="btn hs-crop-cancel">Vazgeç</button>
                <button type="button" class="btn btn-primary hs-crop-ok">Kırp ve Kullan</button>
            </div>
        </div>
    </div>
</section>

<!-- ═══ 2. TUTAR ═══ -->
<section class="hs-step">
    <p class="hs-step-label"><span class="hs-step-num">2</span> Tutar</p>
    <div class="hs-amount-wrap">
        <span class="hs-amount-cur" aria-hidden="true"><?= hesap_currency_sym($record['currency'] ?: 'TRY') ?></span>
        <input type="text" name="amount" class="hs-amount-input" inputmode="decimal"
               value="<?= h(((float)$record['amount']) > 0 ? number_format((float)$record['amount'], 2, ',', '.') : '') ?>"
               placeholder="0,00" required aria-label="Tutar"
               data-autofocus="<?= $id === 0 ? '1' : '0' ?>">
    </div>

    <div class="hs-type-row" role="group" aria-label="İşlem türü">
        <?php foreach (['gider'=>'Gider','gelir'=>'Gelir','havale'=>'Havale','nakit'=>'Nakit'] as $tv => $tl): ?>
        <button type="button" class="hs-type-btn" data-type="<?= h($tv) ?>"
                aria-pressed="<?= $aktif_tur === $tv ? 'true' : 'false' ?>"><?= h($tl) ?></button>
        <?php endforeach; ?>
    </div>
</section>

<!-- ═══ 3. KATEGORİ ═══ -->
<section class="hs-step">
    <p class="hs-step-label"><span class="hs-step-num">3</span> Kategori</p>
    <div class="hs-chips">
        <?php foreach ($one_cikan as $tur => $liste): ?>
            <?php foreach ($liste as $kat): ?>
            <button type="button" class="hs-chip" data-cat="<?= h($kat) ?>" data-cat-type="<?= h($tur) ?>"
                    aria-pressed="<?= $record['category'] === $kat ? 'true' : 'false' ?>"
                    <?= $tur === $aktif_tur ? '' : 'hidden' ?>><?= h($kat) ?></button>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <button type="button" class="hs-chip hs-chip-more">Diğer…</button>
    </div>
</section>

<!-- ═══ DETAYLAR — varsayılan kapalı ═══ -->
<details class="hs-step hs-details" id="hsDetails" style="padding:0"
         <?= ($id > 0 || !empty($errors)) ? 'open' : '' ?>>
    <summary>Detaylar <span class="muted" style="font-weight:400">— tarih, ödeme, kişi, açıklama</span></summary>
    <div class="hs-details-body">
        <div class="hs-grid">
            <label class="hs-field">
                <span>Tür</span>
                <select name="type" id="hsTypeSelect" required>
                    <?php foreach (['gelir','gider','havale','nakit'] as $t): ?>
                    <option value="<?= $t ?>" <?= $record['type'] === $t ? 'selected' : '' ?>><?= hesap_type_label($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="hs-field">
                <span>Kategori</span>
                <select name="category" id="hsCategorySelect">
                    <option value="">— seçiniz —</option>
                    <?php foreach ($kategoriler as $tur => $liste): ?>
                        <?php foreach ($liste as $kat): ?>
                        <option value="<?= h($kat) ?>" data-cat-type="<?= h($tur) ?>"
                                <?= $record['category'] === $kat ? 'selected' : '' ?>><?= h($kat) ?></option>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="hs-field">
                <span>Tarih</span>
                <input type="date" name="transaction_date" value="<?= h($record['transaction_date']) ?>" required>
            </label>
            <label class="hs-field">
                <span>Saat</span>
                <input type="time" name="transaction_time" value="<?= h(substr((string)$record['transaction_time'], 0, 5)) ?>">
            </label>
            <label class="hs-field">
                <span>Para Birimi</span>
                <select name="currency">
                    <?php foreach (['TRY','USD','EUR','AED'] as $c): ?>
                    <option value="<?= $c ?>" <?= $record['currency'] === $c ? 'selected' : '' ?>><?= $c ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="hs-field">
                <span>Ödeme Yöntemi</span>
                <select name="payment_method">
                    <?php foreach (['nakit'=>'Nakit','banka'=>'Banka','kredi_karti'=>'Kredi Kartı','havale'=>'Havale','sirket_karti'=>'Şirket Kartı','sahsi'=>'Şahsi'] as $v => $l): ?>
                    <option value="<?= $v ?>" <?= $record['payment_method'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="hs-field">
                <span>Kişi / Firma</span>
                <input type="text" name="person_company" value="<?= h($record['person_company']) ?>" placeholder="Alınan / verilen kişi veya firma">
            </label>
            <label class="hs-field">
                <span>Belge / Fiş No</span>
                <input type="text" name="document_no" value="<?= h($record['document_no']) ?>" placeholder="Fiş veya belge numarası">
            </label>
            <label class="hs-field span-2">
                <span>Açıklama</span>
                <textarea name="description" rows="2" placeholder="Kısa açıklama..."><?= h($record['description']) ?></textarea>
            </label>
            <label class="hs-field span-2">
                <span>Muhasebe Notu</span>
                <textarea name="notes" rows="2" placeholder="Muhasebeciye özel not..."><?= h($record['notes']) ?></textarea>
            </label>
        </div>

        <div style="margin-top:14px">
            <label class="hs-check">
                <input type="checkbox" name="has_invoice" <?= $record['has_invoice'] ? 'checked' : '' ?>>
                Elimde basılı fatura/fiş var
            </label>
            <label class="hs-check">
                <input type="checkbox" name="is_for_company" <?= $record['is_for_company'] ? 'checked' : '' ?>>
                Şirket için yapılan masraf
            </label>
        </div>

        <p class="muted" style="font-size:.78rem;margin:10px 0 0">
            Muhasebe onayı durum akışıyla yürür — gönderdikten sonra onay/red işlemini muhasebe yapar.
        </p>
    </div>
</details>

<?php
// Onay sürecine girmiş kayıtlarda durum düzenlemeyle değişmez — buton da gösterilmez
$durum_secilebilir = ($id === 0) || in_array((string)($record['status'] ?? 'submitted'), ['draft','rejected'], true);
?>
<div class="hs-save">
    <?php if ($durum_secilebilir): ?>
    <button type="submit" name="kaydet_turu" value="taslak" class="btn">Taslak</button>
    <button type="submit" name="kaydet_turu" value="gonder" class="hs-cta">
        <span class="hs-cta-icon" aria-hidden="true">✓</span> Kaydet ve Gönder
    </button>
    <?php else: ?>
    <button type="submit" class="hs-cta"><span class="hs-cta-icon" aria-hidden="true">✓</span> Kaydet</button>
    <?php endif; ?>
</div>
</form>

</div><!-- /.hs -->
<?php hesap_scripts(); ?>
<?php render_footer(); ?>
