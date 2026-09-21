<?php
// =========================================================
// isci_kartlari.php — Kart Havuzu Yönetimi (Günlük İşçi, Faz 1)
//
// personel_kartlar.php ile AYNI "liste + kart-önce tanımlama" deseni —
// ama kart bir PERSONELE değil bir İŞÇİ TİPİNE bağlanır (bkz. config/
// pdks_gunluk.php başlığı: kart kişi değil, yeniden kullanılabilir bir
// oturum birimidir).
//
// ⚠ NFC/USB OKUMA: kullanıcının açık talimatı — "reuse the proven NFC/USB
// enrollment patterns already in the project... Do NOT invent another NFC
// lifecycle." Burada YENİ bir NFC kodu YOK: assets/pdks.js'teki
// data-pdks-scan / data-pdks-nfc-target / pdksnfcread deseni AYNEN
// kullanılır (personel_kartlar.php ile BİREBİR aynı JS, farklı sadece
// hedef form alanları). Bu, giris_cikis.php'nin PdksNfcOku okuma-döngüsü
// (Sprint NFC-Fix-01) İLE AYNI ŞEY DEĞİLDİR — o, tekrarlı "aç ve arka arkaya
// kartları oku" akışı içindir; assets/pdks.js'in deseni TEK KART tanımlama
// (enroll) içindir ve zaten bu iki sayfada (personel_kartlar.php,
// personel_form.php) ÇALIŞIYOR. İkisi de "proven" — burada olan doğru olan
// enrollment deseni REUSE edilir.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks.php';           // UID normalizasyon fonksiyonları (REUSE)
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_gunluk('worker_cards');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
$faz8aHazir = pdks_gunluk_faz8a_sema_hazir($pdo);

// ── Salt-okunur önizleme ucu — personel_kartlar.php'deki ajax=onizle İLE
// AYNI JS'İ (assets/pdks.js) besler; burada AYRICA çapraz-sistem uyarısı da
// döner (kalıcı personel kartıyla çakışıyor mu) — hiçbir yazma yapmaz. ──
if (($_GET['ajax'] ?? '') === 'onizle') {
    header('Content-Type: application/json; charset=utf-8');
    $ham = trim($_GET['uid'] ?? '');
    $kaynak = trim($_GET['kaynak'] ?? '');
    if ($ham === '' || !in_array($kaynak, ['usb_decimal', 'web_nfc'], true)) {
        echo json_encode(['ok' => false, 'hata' => 'Geçersiz istek.']);
        exit;
    }
    $kanonik = ($kaynak === 'usb_decimal') ? pdks_uid_from_decimal($ham) : pdks_uid_from_web_nfc($ham);
    if ($kanonik === null) {
        echo json_encode(['ok' => false, 'hata' => $kaynak === 'usb_decimal'
            ? 'Geçersiz UID — yalnız rakam kabul edilir.'
            : 'Geçersiz NFC okuması.']);
        exit;
    }
    $havuz  = pdks_gunluk_uid_gecici_kartta_mi($kanonik, null, $pdo);
    $kalici = pdks_gunluk_uid_kalici_kartta_mi($kanonik, $pdo);
    echo json_encode([
        'ok'              => true,
        'canonical'       => $kanonik,
        'exists'          => $havuz !== null,
        'card_no'         => $havuz['card_no'] ?? null,
        'kalici_cakisma'  => $kalici !== null,
        'kalici_isim'     => $kalici['full_name'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Salt-okunur SORGU ucu (Sprint Kart-Sorgula-01) — GİRİŞ/ÇIKIŞ YAPMAZ,
// yalnız kartın şu anki durumunu + son 5 dönemini döner. ajax=onizle'den
// AYRI: onizle "bu UID boşta mı" der (enroll formu için), bu ise kartın
// KİMLİĞİNİ ve GEÇMİŞİNİ gösterir. daily_worker_work_periods Faz 8A'ya
// özgü olduğu için şema hazır değilse fail-closed döner. ──
if (($_GET['ajax'] ?? '') === 'sorgula') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$faz8aHazir) {
        echo json_encode(['ok' => false, 'hata' => 'Bu özellik için Faz 8A migrasyonunun tamamlanmış olması gerekiyor.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ham = trim($_GET['uid'] ?? '');
    $kaynak = trim($_GET['kaynak'] ?? '');
    if ($ham === '' || !in_array($kaynak, ['usb_decimal', 'web_nfc'], true)) {
        echo json_encode(['ok' => false, 'hata' => 'Geçersiz istek.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $kanonik = ($kaynak === 'usb_decimal') ? pdks_uid_from_decimal($ham) : pdks_uid_from_web_nfc($ham);
    if ($kanonik === null) {
        echo json_encode(['ok' => false, 'hata' => $kaynak === 'usb_decimal'
            ? 'Geçersiz UID — yalnız rakam kabul edilir.'
            : 'Geçersiz NFC okuması.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(pdks_gunluk_faz8a_kart_sorgula($kanonik, $pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

$hata = ''; $basari = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_gunluk('worker_cards');   // savunma derinliği
    $action = trim($_POST['action'] ?? '');

    if ($action === 'kart_ekle' && !$faz8aHazir) {
        // Kod deploy edilmiş fakat Faz 8A migrasyonu henüz tamamlanmamış olabilir.
        // Bu pencerede worker_cards.worker_type_id üretimde hâlâ NOT NULL olabilir;
        // nötr kartı NULL ile yazmayı denemek yerine kayıt güvenli biçimde durdurulur.
        $hata = 'Yeni nötr kart tanımlamak için önce yönetici Faz 8A migrasyonunu tamamlamalıdır. Mevcut kartlarla giriş/çıkış çalışmaya devam eder.';
    } elseif ($action === 'kart_ekle') {
        $veri = [
            'card_no'        => trim($_POST['card_no'] ?? ''),
            'worker_type_id' => (int)($_POST['worker_type_id'] ?? 0),
            'ham_uid'        => trim($_POST['ham_uid'] ?? ''),
            'kaynak'         => trim($_POST['kaynak'] ?? 'usb_decimal'),
            'notes'          => trim($_POST['notes'] ?? ''),
        ];
        $sonuc = pdks_gunluk_kart_olustur($veri, (int)$auth_user['id'], $pdo);
        if ($sonuc['ok']) {
            header('Location: isci_kartlari.php?ok=' . urlencode('Kart tanımlandı: ' . $sonuc['card_no']));
            exit;
        }
        // PDO/SQL ayrıntıları operatöre gösterilmez; teknik detay sunucu logunda kalır.
        $hata = (($sonuc['kod'] ?? '') === 'yazma_hatasi')
            ? 'Kart kaydedilemedi. Lütfen bilgileri kontrol edip yeniden deneyin; sorun sürerse yöneticinize başvurun.'
            : ($sonuc['hata'] ?? 'Kart tanımlanamadı.');
    } elseif ($action === 'kart_duzenle' && !$faz8aHazir && (int)($_POST['worker_type_id'] ?? 0) <= 0) {
        // Pre-migration üretim şemasında worker_type_id hâlâ NOT NULL olabilir.
        // Nötrleştirme yalnız Faz 8A şeması tamamen hazır olduğunda güvenlidir.
        $hata = 'Kartı nötr hale getirmek için önce yönetici Faz 8A migrasyonunu tamamlamalıdır. Mevcut işçi tipi korunarak diğer bilgiler düzenlenebilir.';
    } elseif ($action === 'kart_duzenle') {
        $cardId = (int)($_POST['card_id'] ?? 0);
        $veri = [
            'card_no'        => trim($_POST['card_no'] ?? ''),
            'worker_type_id' => (int)($_POST['worker_type_id'] ?? 0),
            'notes'          => trim($_POST['notes'] ?? ''),
        ];
        $sonuc = pdks_gunluk_kart_duzenle($cardId, $veri, (int)$auth_user['id'], $pdo);
        if ($sonuc['ok']) {
            header('Location: isci_kartlari.php?ok=' . urlencode('Kart güncellendi.'));
            exit;
        }
        $hata = $sonuc['hata'] ?? 'Güncellenemedi.';
    } elseif ($action === 'kart_durum') {
        $cardId = (int)($_POST['card_id'] ?? 0);
        $durum  = trim($_POST['durum'] ?? '');
        $sonuc = pdks_gunluk_kart_durum_degistir($cardId, $durum, (int)$auth_user['id'], $pdo);
        if ($sonuc['ok']) {
            header('Location: isci_kartlari.php?ok=' . urlencode('Kart durumu güncellendi.'));
            exit;
        }
        $hata = $sonuc['hata'] ?? 'İşlem yapılamadı.';
    } else {
        $hata = 'Bilinmeyen işlem.';
    }
}
if ($hata === '' && isset($_GET['ok'])) $basari = trim($_GET['ok']);

$tipler = pdks_gunluk_tip_listele(true, $pdo);
// ⚠ FAZ 8A: kart artık NÖTR oluşturulur (tip taramada seçilir, bkz.
// gunluk_isci_giris_cikis.php) — öneri numarası tipten BAĞIMSIZ 'K' önekiyle üretilir.
$onerilenKartNo = pdks_gunluk_sonraki_kart_no(0, $pdo);

// ── Kart listesi (filtre) ──────────────────────────────────
$q = trim($_GET['q'] ?? '');
$tip_f = (int)($_GET['tip'] ?? 0);
$durum_f = trim($_GET['durum'] ?? '');
if ($durum_f !== '' && !array_key_exists($durum_f, pdks_gunluk_kart_durumlari())) $durum_f = '';

$where = ['1=1']; $params = [];
if ($q !== '') {
    $where[] = "(w.card_no LIKE ? OR w.canonical_uid LIKE ?)";
    $params = array_merge($params, ["%$q%", "%$q%"]);
}
if ($tip_f > 0) { $where[] = "w.worker_type_id = ?"; $params[] = $tip_f; }
if ($durum_f !== '') { $where[] = "w.status = ?"; $params[] = $durum_f; }
$whereSql = implode(' AND ', $where);

$kartlar = [];
try {
    // ⚠ FAZ 8A: LEFT JOIN — worker_type_id artık NULL olabilir (nötr kart).
    // Eski INNER JOIN, worker_type_id'si NULL olan (Faz 8A'da yeni oluşturulan
    // NORMAL) kartları listeden SESSİZCE DÜŞÜRÜRDÜ.
    $st = $pdo->prepare(
        "SELECT w.*, t.name AS tip_adi, t.code AS tip_kodu
           FROM worker_cards w
           LEFT JOIN worker_types t ON t.id = w.worker_type_id
          WHERE $whereSql
          ORDER BY w.created_at DESC, w.id DESC
          LIMIT 300"
    );
    $st->execute($params);
    $kartlar = $st->fetchAll();
} catch (PDOException $e) {
    set_flash('error', 'Günlük İşçi tabloları henüz hazır değil. Bir yöneticinin migrate.php sayfasından "Günlük İşçi Tablolarını Oluştur" demesi gerekiyor.');
}

render_header('Kart Havuzu');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
if ($basari !== ''): ?>
<div class="flash flash-success"><?= h($basari) ?></div>
<?php endif; if ($hata !== ''): ?>
<div class="flash flash-error"><?= h($hata) ?></div>
<?php endif; ?>

<div class="page-head">
    <h1>🪪 Kart Havuzu</h1>
    <div class="page-head-actions">
        <a href="cavuslar.php" class="btn">👷 Çavuşlar</a>
        <a href="isci_tipleri.php" class="btn btn-ghost">🏷 İşçi Tipleri</a>
    </div>
</div>

<!-- ── Kart Sorgula (Sprint Kart-Sorgula-01) — GİRİŞ/ÇIKIŞ YAPMADAN kartın
     şu anki durumunu + son 5 dönemini gösterir. Enroll kutusundan (aşağıda)
     BİLEREK AYRI: o "boşta mı" der, bu "kim/ne zaman" der. NFC deseni
     KASITLI OLARAK farklı — burada PdksNfcOku (giris_cikis.php'nin sürekli
     dinleme kioskuyla AYNI ortak fonksiyon, config/pdks.php) kullanılır,
     çünkü sorgulama tek kart değil ARDIŞIK kartlar için yapılır; enroll
     kutusundaki assets/pdks.js tek-tık deseni burada UYGUN DEĞİLDİR. İKİ
     AYRI NFC yaşam döngüsü ÇAKIŞMAZ — enroll kutusu kendi NDEFReader'ını
     yalnız KENDİ butonuna tıklanınca kurar, ikisi aynı anda dinlemez. -->
<div class="card" style="padding:0;margin-bottom:20px">
    <details class="pdks-collapse">
    <summary>🔍 Kart Sorgula</summary>
    <div class="pdks-collapse-body">
    <p class="muted" style="font-size:.85rem">
        Kartı okutun — GİRİŞ/ÇIKIŞ yapılmaz, yalnız kartın şu anki durumu ve
        son 5 mesai dönemi görüntülenir.
    </p>
    <?php if (!$faz8aHazir): ?>
    <div class="flash flash-warning">Bu özellik için Faz 8A migrasyonunun tamamlanmış olması gerekiyor.</div>
    <?php else: ?>
    <div class="pdks-scan-box">
        <label class="pdks-scan-label" for="iskSorguInput">KARTI OKUTUN</label>
        <input type="text" inputmode="numeric" id="iskSorguInput" class="pdks-scan-input"
               placeholder="631799511" autocomplete="off">
        <div class="pdks-scan-status" id="iskSorguDurum"></div>
        <button type="button" id="iskSorguNfcBtn" class="btn btn-ghost" style="margin-top:10px" hidden>📡 NFC İLE OKU</button>
    </div>
    <div id="iskSorguSonuc" class="isk-sorgu-sonuc" hidden></div>
    <?php endif; ?>
    </div>
    </details>
</div>

<!-- ── Kart-önce tanımlama (enroll) ──────────────────────────
     assets/pdks.js'in data-pdks-scan / data-pdks-nfc-target deseni
     personel_kartlar.php İLE BİREBİR AYNI — burada TEKRARLANMADI. -->
<div class="card" style="padding:0;margin-bottom:20px">
    <details class="pdks-collapse">
    <summary>Yeni Kart Tanımla</summary>
    <div class="pdks-collapse-body">
    <p class="muted" style="font-size:.85rem">
        Kart artık NÖTR bir jetondur — işçi tipi ve mesai (Tam/Yarım) burada DEĞİL,
        her taramada <a href="gunluk_isci_giris_cikis.php">Giriş / Çıkış</a> ekranında seçilir.
        Aynı fiziksel kart farklı günlerde/çavuşlarda farklı işçi tipleri için kullanılabilir.
    </p>
    <?php if (!$faz8aHazir): ?>
    <div class="flash flash-warning" style="margin-bottom:12px">
        Yeni nötr kart tanımlama, Faz 8A migrasyonu tamamlanana kadar güvenlik nedeniyle kapalıdır. Mevcut kartlarla tarama çalışmaya devam eder.
    </div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="kart_ekle">
        <input type="hidden" name="kaynak" id="ikScanKaynak" value="usb_decimal">
        <div class="pdks-scan-box">
            <label class="pdks-scan-label" for="ikScanInput">KARTI USB OKUYUCUYA OKUTUN</label>
            <input type="text" inputmode="numeric" id="ikScanInput" name="ham_uid" class="pdks-scan-input"
                   data-pdks-scan data-pdks-preview="#ikScanOnizle" data-pdks-status="#ikScanDurum"
                   data-pdks-kaynak-field="#ikScanKaynak" data-pdks-onizle-url="isci_kartlari.php"
                   placeholder="631799511" autocomplete="off" required>
            <div class="pdks-uid-lg" id="ikScanOnizle" style="margin-top:12px;min-height:1.4em"></div>
            <div class="pdks-scan-status" id="ikScanDurum"></div>
            <button type="button" id="ikScanNfcBtn" class="btn btn-ghost" style="margin-top:10px"
                    data-pdks-nfc-target="#ikScanInput" hidden>📡 NFC İLE OKU</button>
        </div>
        <div class="pdks-form-grid" style="margin-top:14px">
            <label>
                <span class="form-label">Kart No *</span>
                <input type="text" name="card_no" maxlength="30" required value="<?= h($onerilenKartNo) ?>">
            </label>
            <label class="span-2">
                <span class="form-label">Not (opsiyonel)</span>
                <input type="text" name="notes" maxlength="200">
            </label>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:14px" <?= !$faz8aHazir ? 'disabled' : '' ?>>KARTI HAVUZA EKLE</button>
    </form>
    </div>
    </details>
</div>

<!-- ── Kart listesi ───────────────────────────────────────── -->
<form method="get" class="pdks-filter-bar">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Kart no veya UID ara…">
    <select name="tip">
        <option value="">Tüm tipler</option>
        <?php foreach (pdks_gunluk_tip_listele(false, $pdo) as $t): ?>
        <option value="<?= (int)$t['id'] ?>" <?= $tip_f === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="durum">
        <option value="">Tüm durumlar</option>
        <?php foreach (pdks_gunluk_kart_durumlari() as $k => $lbl): ?>
        <option value="<?= h($k) ?>" <?= $durum_f === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn">Filtrele</button>
    <?php if ($q !== '' || $tip_f > 0 || $durum_f !== ''): ?>
    <a href="isci_kartlari.php" class="btn btn-ghost">Temizle</a>
    <?php endif; ?>
</form>

<?php if (empty($kartlar)): ?>
<div class="pdks-empty">
    <span class="pdks-empty-icon" aria-hidden="true">🪪</span>
    <p>Bu filtrelerle kart bulunamadı.</p>
</div>
<?php else: ?>
<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr>
    <th>Kart No</th>
    <th>Tip (eski/kalıcı)</th>
    <th>UID (kanonik)</th>
    <th>Durum</th>
    <th>Tanımlandı</th>
    <th class="actions-col">İşlem</th>
</tr></thead>
<tbody>
<?php foreach ($kartlar as $k): ?>
<tr>
    <td class="pdks-uid"><?= h($k['card_no']) ?></td>
    <td class="muted"><?= h($k['tip_adi'] ?? '') !== '' ? h($k['tip_adi']) : '— (nötr)' ?></td>
    <td class="muted pdks-uid"><?= h($k['canonical_uid']) ?></td>
    <td><span class="pdks-badge pdks-badge-<?= $k['status'] === 'available' ? 'aktif' : ($k['status'] === 'lost' ? 'kayip' : 'iptal') ?>">
        <?= h(pdks_gunluk_kart_durumlari()[$k['status']] ?? $k['status']) ?></span></td>
    <td class="muted"><?= h(fmt_datetime($k['created_at'])) ?></td>
    <td class="actions-col">
        <button type="button" class="btn btn-sm" onclick="iskKartModalAc(<?= (int)$k['id'] ?>,<?= $k['worker_type_id'] !== null ? (int)$k['worker_type_id'] : 0 ?>,'<?= h(addslashes($k['card_no'])) ?>','<?= h(addslashes($k['notes'] ?? '')) ?>','<?= h($k['status']) ?>')">Düzenle</button>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="pdks-cards mobile-only">
<?php foreach ($kartlar as $k): ?>
<div class="pdks-card-item">
    <div class="pdks-card-top">
        <div class="pdks-card-meta">
            <div class="pdks-uid"><?= h($k['card_no']) ?></div>
            <div class="pdks-row-sub"><?= h($k['tip_adi'] ?? '') !== '' ? h($k['tip_adi']) . ' · ' : '' ?><?= h($k['canonical_uid']) ?></div>
        </div>
        <span class="pdks-badge pdks-badge-<?= $k['status'] === 'available' ? 'aktif' : ($k['status'] === 'lost' ? 'kayip' : 'iptal') ?>">
            <?= h(pdks_gunluk_kart_durumlari()[$k['status']] ?? $k['status']) ?></span>
    </div>
    <div class="pdks-card-actions">
        <button type="button" class="btn btn-sm" onclick="iskKartModalAc(<?= (int)$k['id'] ?>,<?= $k['worker_type_id'] !== null ? (int)$k['worker_type_id'] : 0 ?>,'<?= h(addslashes($k['card_no'])) ?>','<?= h(addslashes($k['notes'] ?? '')) ?>','<?= h($k['status']) ?>')">Düzenle</button>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Düzenleme modalı — window.pdksOpenModal/pdksCloseModal (assets/pdks.js)
     REUSE edilir, yeni bir modal aç/kapa mekanizması İCAT EDİLMEDİ. ── -->
<div class="pm-overlay" id="iskKartModal" hidden>
<div class="pm-dialog isk-card-modal">
    <div class="pm-header">
        <h2 class="pm-title">Kartı Düzenle</h2>
        <button type="button" class="pm-close" onclick="pdksCloseModal('iskKartModal')">✕</button>
    </div>
    <div class="isk-card-modal-body">
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="kart_duzenle">
            <input type="hidden" name="card_id" id="iskCardId">
            <div class="pdks-form-grid">
                <label>
                    <span class="form-label">Kart No *</span>
                    <input type="text" name="card_no" id="iskCardNo" maxlength="30" required>
                </label>
                <label>
                    <span class="form-label">Tip (eski/kalıcı — Faz 8A'da kullanılmaz)</span>
                    <select name="worker_type_id" id="iskWorkerTypeId">
                        <option value="0" <?= !$faz8aHazir ? 'disabled' : '' ?>>— (nötr, tip yok) —</option>
                        <?php foreach ($tipler as $t): ?>
                        <option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$faz8aHazir): ?>
                    <small class="muted">Nötr seçenek, Faz 8A migrasyonu tamamlandıktan sonra kullanılabilir.</small>
                    <?php endif; ?>
                </label>
                <label class="span-2">
                    <span class="form-label">Not</span>
                    <input type="text" name="notes" id="iskNotes" maxlength="200">
                </label>
            </div>
            <div class="isk-card-form-actions">
                <button type="submit" class="btn btn-primary">Kaydet</button>
                <button type="button" class="btn btn-ghost" onclick="pdksCloseModal('iskKartModal')">Vazgeç</button>
            </div>
        </form>
        <hr>
        <div class="isk-card-status-actions">
            <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="kart_durum"><input type="hidden" name="card_id" id="iskCardIdA">
                <input type="hidden" name="durum" value="available">
                <button type="submit" class="btn btn-sm" id="iskBtnAvailable">▶ Kullanılabilir Yap</button></form>
            <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="kart_durum"><input type="hidden" name="card_id" id="iskCardIdB">
                <input type="hidden" name="durum" value="lost">
                <button type="submit" class="btn btn-sm" id="iskBtnLost">⚠ Kayıp Bildir</button></form>
            <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="kart_durum"><input type="hidden" name="card_id" id="iskCardIdC">
                <input type="hidden" name="durum" value="disabled">
                <button type="submit" class="btn btn-sm" id="iskBtnDisabled">⛔ Devre Dışı Bırak</button></form>
        </div>
    </div>
</div>
</div>

<script>
function iskKartModalAc(id, tipId, kartNo, not, durum) {
    document.getElementById('iskCardId').value = id;
    document.getElementById('iskCardIdA').value = id;
    document.getElementById('iskCardIdB').value = id;
    document.getElementById('iskCardIdC').value = id;
    document.getElementById('iskCardNo').value = kartNo;
    document.getElementById('iskWorkerTypeId').value = tipId;
    document.getElementById('iskNotes').value = not;
    document.getElementById('iskBtnAvailable').disabled = (durum === 'available');
    document.getElementById('iskBtnLost').disabled = (durum === 'lost');
    document.getElementById('iskBtnDisabled').disabled = (durum === 'disabled');
    window.pdksOpenModal('iskKartModal');
}
</script>

<?php if ($faz8aHazir): ?>
<?php pdks_nfc_oku_js();   /* ortak Web NFC okuma yolu — giris_cikis.php / pdks_nfc_test.php İLE AYNI kod, bkz. sayfa başındaki not */ ?>
<script>
(function () {
    'use strict';
    var input   = document.getElementById('iskSorguInput');
    var durumEl = document.getElementById('iskSorguDurum');
    var nfcBtn  = document.getElementById('iskSorguNfcBtn');
    var sonucEl = document.getElementById('iskSorguSonuc');
    if (!input || !sonucEl) return;

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }
    function saat(dt) {
        if (!dt) return '';
        return (String(dt).split(' ')[1] || String(dt)).slice(0, 5);
    }
    function tarih(d) {
        var p = String(d || '').split('-');
        return p.length === 3 ? p.reverse().join('.') : (d || '');
    }
    var DURUM_ETIKET = { available: 'Kullanılabilir', lost: 'Kayıp', disabled: 'Devre Dışı' };
    var DURUM_SINIF  = { available: 'aktif', lost: 'kayip', disabled: 'iptal' };

    function sonucGoster(d) {
        if (!d || !d.ok) {
            sonucEl.innerHTML = '<div class="flash flash-error" style="margin-top:12px">' + esc((d && d.hata) || 'Sorgu başarısız.') + '</div>';
            sonucEl.hidden = false;
            return;
        }
        if (!d.bulundu) {
            var mesaj = 'Bu UID hiçbir karta kayıtlı değil.';
            if (d.kalici_cakisma) mesaj += ' Kalıcı personel kartı olarak tanımlı' + (d.kalici_isim ? (': ' + esc(d.kalici_isim)) : '') + '.';
            sonucEl.innerHTML = '<div class="flash flash-warning" style="margin-top:12px">' + mesaj + '</div>';
            sonucEl.hidden = false;
            return;
        }
        var html = '<div class="isk-sorgu-baslik" style="margin-top:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">'
            + '<span class="pdks-uid" style="font-size:1.1rem">' + esc(d.card_no) + '</span>'
            + '<span class="pdks-badge pdks-badge-' + (DURUM_SINIF[d.status] || 'iptal') + '">' + esc(DURUM_ETIKET[d.status] || d.status) + '</span>'
            + '</div>';
        if (d.kalici_cakisma) {
            html += '<div class="flash flash-warning" style="margin-top:8px">⚠ Bu UID kalıcı personel kartıyla da çakışıyor' + (d.kalici_isim ? (': ' + esc(d.kalici_isim)) : '') + '.</div>';
        }
        html += '<div class="isk-sorgu-simdi" style="margin-top:10px;padding:10px 12px;border-radius:var(--radius);background:var(--surface-2)">';
        if (d.acik) {
            html += '🟢 <strong>AÇIK</strong> — ' + esc(d.acik.foreman_name) + ' · ' + esc(d.acik.tip)
                + ' · ' + esc(d.acik.depo || '(depo yok)') + ' · Giriş: ' + esc(tarih(d.acik.entry_time.split(' ')[0])) + ' ' + esc(saat(d.acik.entry_time));
        } else {
            html += '⚪ Şu an boşta — açık bir mesai dönemi yok';
        }
        html += '</div>';
        html += '<h3 style="margin:16px 0 8px;font-size:.95rem">Son ' + d.gecmis.length + ' Dönem</h3>';
        if (d.gecmis.length === 0) {
            html += '<p class="muted">Bu kartla henüz hiç tarama yapılmamış.</p>';
        } else {
            // ⚠ min-width BİLEREK: beş kolon 390px'te table-wrap'in overflow-x:auto'su
            // tetiklenmeden sıkışıp metni saçmaya başlıyordu (Tarih/Giriş→Çıkış çok satıra
            // bölünüyordu) — genişlik zorlanınca aynı panel yatay kaydırmalı okunur olur.
            html += '<div class="table-wrap"><table class="data-table" style="min-width:560px"><thead><tr>'
                + '<th>Tarih</th><th>Çavuş</th><th>Tip</th><th>Depo</th><th>Giriş → Çıkış</th>'
                + '</tr></thead><tbody>';
            d.gecmis.forEach(function (p) {
                var cikis = p.exit_time ? saat(p.exit_time) : (p.status === 'open' ? '(henüz açık)' : '—');
                html += '<tr><td>' + esc(tarih(p.work_date_snapshot)) + '</td><td>' + esc(p.cavus) + '</td>'
                    + '<td>' + esc(p.tip) + '</td><td class="muted">' + esc(p.depo_snapshot || '') + '</td>'
                    + '<td class="muted">' + esc(saat(p.entry_time)) + ' → ' + esc(cikis) + '</td></tr>';
            });
            html += '</tbody></table></div>';
        }
        sonucEl.innerHTML = html;
        sonucEl.hidden = false;
    }

    var lastRequest = 0;
    function sorgula(hamUid, kaynak) {
        var deger = String(hamUid || '').trim();
        if (deger === '') { sonucEl.hidden = true; return; }
        var req = ++lastRequest;
        durumEl.textContent = 'Sorgulanıyor…'; durumEl.className = 'pdks-scan-status';
        fetch('isci_kartlari.php?ajax=sorgula&kaynak=' + encodeURIComponent(kaynak) + '&uid=' + encodeURIComponent(deger), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (req !== lastRequest) return;
                durumEl.textContent = ''; durumEl.className = 'pdks-scan-status';
                sonucGoster(d);
            })
            .catch(function () {
                if (req !== lastRequest) return;
                durumEl.textContent = 'Sorgu başarısız — bağlantıyı kontrol edin.';
                durumEl.className = 'pdks-scan-status err';
            });
    }

    // ── USB HID okuma — yalnız rakam, Enter formu göndermez ──
    var timer = null;
    input.addEventListener('input', function () {
        var temiz = input.value.replace(/[^0-9]/g, '');
        if (temiz !== input.value) input.value = temiz;
        clearTimeout(timer);
        if (temiz === '') { sonucEl.hidden = true; return; }
        timer = setTimeout(function () { sorgula(temiz, 'usb_decimal'); }, 250);
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(timer);
            if (input.value.trim() !== '') sorgula(input.value.trim(), 'usb_decimal');
        }
    });

    // ── Web NFC — PAYLAŞILAN PdksNfcOku (config/pdks.php), giris_cikis.php
    // İLE AYNI sürekli-dinleme deseni: bir kez başlatılır, arka arkaya
    // farklı kartlar için tekrar tıklamaya GEREK YOKTUR. ──
    if (window.PdksNfcOku && PdksNfcOku.destekli()) {
        nfcBtn.hidden = false;
        var dinlemede = false;
        nfcBtn.addEventListener('click', function () {
            if (dinlemede) return;
            PdksNfcOku.baslat({
                onOkuma: function (ev) {
                    var ham = (ev.serialNumber != null) ? String(ev.serialNumber) : '';
                    if (ham !== '') sorgula(ham, 'web_nfc');
                },
                onOkumaHatasi: function () {
                    durumEl.textContent = 'NFC okuma hatası — kartı tekrar yaklaştırın.';
                    durumEl.className = 'pdks-scan-status err';
                },
                onBasladi: function () {
                    dinlemede = true;
                    nfcBtn.textContent = '🟢 NFC DİNLENİYOR — kartları arka arkaya okutabilirsiniz';
                    nfcBtn.disabled = true;
                },
                onHata: function (ad, msj) {
                    durumEl.textContent = 'NFC başlatılamadı: ' + msj;
                    durumEl.className = 'pdks-scan-status err';
                }
            });
        });
    }
})();
</script>
<?php endif; ?>

<script src="<?= $base ?>assets/pdks.js?v=<?= @filemtime(__DIR__ . '/assets/pdks.js') ?>"></script>
<?php render_footer(); ?>
