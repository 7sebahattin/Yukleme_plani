<?php
// =========================================================
// personel_kartlar.php — Kart Yönetimi (PDKS Faz 1B)
//
// İki iş: ① Tüm kartların genel listesi (arama/filtre).
//         ② "Kart-önce" tanımlama akışı: kart USB'den okutulur, sunucu
//            kanoniği ve boşta olup olmadığını gösterir, sonra personel
//            seçilip atanır. (personel_form.php'deki "personel-önce" akışın
//            tersidir — güvenlik masasında kartı elinize alıp okutmak daha
//            doğaldır.)
//
// UID mantığı BURADA TEKRARLANMAZ — hepsi config/pdks.php'deki Faz 1
// fonksiyonlarına (pdks_kart_ata/pdks_kart_degistir/pdks_kart_durum_degistir)
// çıkar.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks.php';
// ⚠ Sprint Günlük-İşçi-01: YALNIZ çapraz-sistem UID çakışma kontrolünü
// (pdks_kart_olustur() içindeki function_exists guard'lı yumuşak çağrı —
// bkz. config/pdks_gunluk.php başlığı) etkinleştirmek için eklendi. Kart
// yazma mantığının KENDİSİ değişmedi.
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks('cards');
pdks_migrate();

$pdo = db();

// ── Salt-okunur önizleme ucu — JS bu uca fetch eder, hiçbir yazma yapmaz ──
// Bu sayfa iki giriş kanalı sunar: USB HID (usb_decimal) ve Web NFC
// (web_nfc, telefonun kendi tarayıcısı) — otomatik kaynak TESPİTİ hâlâ
// YASAK (Faz 0/1 kararı): kaynak istemcinin AÇIKÇA gönderdiği değerdir.
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
    $cozum = pdks_kart_cozumle($ham, $kaynak, $pdo);
    echo json_encode([
        'ok'            => true,
        'canonical'     => $kanonik,
        'exists'        => $cozum !== null,
        'employee_name' => $cozum['employee']['full_name'] ?? null,
        'card_status'   => $cozum['card']['status'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$hata = ''; $basari = '';

// ── POST işlemleri ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks('cards');   // savunma derinliği — sayfa girişindeki kontrolün tekrarı
    $action = trim($_POST['action'] ?? '');

    if ($action === 'kart_ata') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $hamUid     = trim($_POST['ham_uid'] ?? '');
        $etiket     = trim($_POST['label'] ?? '');
        $kaynak     = trim($_POST['kaynak'] ?? '');
        if (!in_array($kaynak, ['usb_decimal', 'web_nfc'], true)) $kaynak = 'usb_decimal';
        if ($employeeId <= 0) {
            $hata = 'Personel seçmelisiniz.';
        } elseif ($hamUid === '') {
            $hata = 'Kartı okutun.';
        } else {
            // Savunma derinliği: dropdown zaten depo filtreli, ama POST'a
            // elle başka depodan bir employee_id gönderilebilir — yazma
            // ÖNCESİ burada da doğrulanır.
            $stKaEmp = $pdo->prepare('SELECT depo FROM employees WHERE id=?');
            $stKaEmp->execute([$employeeId]);
            $kaEmpDepo = $stKaEmp->fetchColumn();
            if ($kaEmpDepo === false) {
                forbidden('Personel bulunamadı.');
            }
            $kaEmpDepo = trim((string)$kaEmpDepo);
            if ($kaEmpDepo !== '' && function_exists('depot_visible_to_user') && !depot_visible_to_user($kaEmpDepo)) {
                forbidden('Bu personel başka depoya ait (' . h($kaEmpDepo) . ').');
            }
            $sonuc = pdks_kart_ata($employeeId, $hamUid, $kaynak,
                ['label' => $etiket, 'created_by' => (int)$auth_user['id']], $pdo);
            if ($sonuc['ok']) {
                header('Location: personel_kartlar.php?ok=' . urlencode('Kart tanımlandı: ' . $sonuc['uid_hex']));
                exit;
            }
            $hata = $sonuc['hata'] ?? 'Kart tanımlanamadı.';
        }
    } elseif ($action === 'kart_durum') {
        $cardId = (int)($_POST['card_id'] ?? 0);
        $durum  = trim($_POST['durum'] ?? '');
        $gerekce = trim($_POST['gerekce'] ?? '');
        if (!in_array($durum, ['iptal', 'kayip'], true)) {
            $hata = 'Geçersiz işlem.';
        } else {
            $sonuc = pdks_kart_durum_degistir($cardId, $durum, $gerekce, (int)$auth_user['id'], $pdo);
            if ($sonuc['ok']) {
                header('Location: personel_kartlar.php?ok=' . urlencode('Kart durumu güncellendi.'));
                exit;
            }
            $hata = $sonuc['hata'] ?? 'İşlem yapılamadı.';
        }
    } elseif ($action === 'kart_degistir') {
        $eskiCardId = (int)($_POST['eski_card_id'] ?? 0);
        $hamUid     = trim($_POST['ham_uid'] ?? '');
        $gerekce    = trim($_POST['gerekce'] ?? '');
        $kaynak     = trim($_POST['kaynak'] ?? '');
        if (!in_array($kaynak, ['usb_decimal', 'web_nfc'], true)) $kaynak = 'usb_decimal';
        $sonuc = pdks_kart_degistir($eskiCardId, $hamUid, $kaynak, $gerekce, (int)$auth_user['id'], $pdo);
        if ($sonuc['ok']) {
            header('Location: personel_kartlar.php?ok=' . urlencode('Kart değiştirildi: ' . $sonuc['uid_hex']));
            exit;
        }
        $hata = $sonuc['hata'] ?? 'Kart değiştirilemedi.';
    } else {
        $hata = 'Bilinmeyen işlem.';
    }
}
if ($hata === '' && isset($_GET['ok'])) $basari = trim($_GET['ok']);

// ── Personel seçim listesi (aktif kartı OLMAYAN personeller, atama için) ──
$atanabilirPersonel = [];
try {
    // Aktif depo kapsamı (personel_form.php/personel_foto.php İLE AYNI
    // düzeltme): bu liste depo filtresi OLMADAN TÜM depoların personelini
    // gösteriyordu — Depo A'daki bir operatör Depo B'nin personeline kart
    // atayabiliyordu. depo_sql_column() ile AYNI kural: boş depo her yerde
    // görünür kalır.
    [$depoSartı, $depoParam] = depo_sql_column('e.depo');
    $stAp = $pdo->prepare(
        "SELECT e.id, e.full_name, e.personnel_no FROM employees e
          WHERE e.status = 'aktif'
            AND NOT EXISTS (SELECT 1 FROM employee_cards c WHERE c.employee_id = e.id AND c.status = 'aktif')
            $depoSartı
          ORDER BY e.full_name"
    );
    $stAp->execute($depoParam);
    $atanabilirPersonel = $stAp->fetchAll();
} catch (PDOException $e) { /* tablo yoksa boş liste — sayfa altta uyarı gösterir */ }

// ── Kart listesi (filtre) ──────────────────────────────────
$q = trim($_GET['q'] ?? '');
$durum_f = trim($_GET['durum'] ?? '');
if ($durum_f !== '' && !array_key_exists($durum_f, pdks_kart_durumlari())) $durum_f = '';

$where = ['1=1']; $params = [];
if ($q !== '') {
    $where[] = "(c.uid_hex LIKE ? OR c.uid_decimal LIKE ? OR e.full_name LIKE ?)";
    $params = array_merge($params, ["%$q%", "%$q%", "%$q%"]);
}
if ($durum_f !== '') { $where[] = "c.status = ?"; $params[] = $durum_f; }
$whereSql = implode(' AND ', $where);

$kartlar = [];
try {
    $st = $pdo->prepare(
        "SELECT c.*, e.full_name AS emp_name, e.personnel_no AS emp_no
           FROM employee_cards c
           JOIN employees e ON e.id = c.employee_id
          WHERE $whereSql
          ORDER BY c.created_at DESC, c.id DESC
          LIMIT 200"
    );
    $st->execute($params);
    $kartlar = $st->fetchAll();
} catch (PDOException $e) {
    set_flash('error', 'Kart tabloları henüz hazır değil. Bir yöneticinin migrate.php sayfasından "PDKS Tablolarını Oluştur" demesi gerekiyor.');
}

render_header('Kart Yönetimi');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
if ($basari !== ''): ?>
<div class="flash flash-success"><?= h($basari) ?></div>
<?php endif; if ($hata !== ''): ?>
<div class="flash flash-error"><?= h($hata) ?></div>
<?php endif; ?>

<div class="page-head">
    <h1>Kart Yönetimi</h1>
    <div class="page-head-actions">
        <a href="personel.php" class="btn">👤 Personeller</a>
        <a href="pdks_nfc_test.php" class="btn btn-ghost" title="Telefon NFC ile kart okuma teşhis testi — hiçbir kayıt yazmaz">🔬 Web NFC Testi</a>
    </div>
</div>

<!-- ── Kart-önce tanımlama ────────────────────────────────── -->
<div class="card" style="padding:16px 18px;margin-bottom:20px">
    <h2 style="margin-top:0">Yeni Kart Tanımla</h2>
    <?php if (empty($atanabilirPersonel)): ?>
    <p class="muted">Aktif kartı olmayan personel yok — tüm aktif personelin kartı tanımlı, ya da hiç personel yok.</p>
    <?php else: ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="kart_ata">
        <input type="hidden" name="kaynak" id="anaScanKaynak" value="usb_decimal">
        <div class="pdks-scan-box">
            <label class="pdks-scan-label" for="anaScanInput">KARTI USB OKUYUCUYA OKUTUN</label>
            <input type="text" inputmode="numeric" id="anaScanInput" name="ham_uid" class="pdks-scan-input"
                   data-pdks-scan data-pdks-preview="#anaScanOnizle" data-pdks-status="#anaScanDurum"
                   data-pdks-kaynak-field="#anaScanKaynak"
                   placeholder="631799511" autocomplete="off" required autofocus>
            <div class="pdks-uid-lg" id="anaScanOnizle" style="margin-top:12px;min-height:1.4em"></div>
            <div class="pdks-scan-status" id="anaScanDurum"></div>
            <button type="button" id="anaScanNfcBtn" class="btn btn-ghost" style="margin-top:10px"
                    data-pdks-nfc-target="#anaScanInput" hidden>📡 NFC İLE OKU</button>
        </div>
        <div class="pdks-form-grid" style="margin-top:14px">
            <label>
                <span class="form-label">Personel *</span>
                <select name="employee_id" required <?= count($atanabilirPersonel) > 10 ? 'data-aramali="personel adından"' : '' ?>>
                    <option value="">— personel seçin —</option>
                    <?php foreach ($atanabilirPersonel as $p): ?>
                    <option value="<?= (int)$p['id'] ?>"><?= h($p['full_name']) ?><?= $p['personnel_no'] ? ' (' . h($p['personnel_no']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span class="form-label">Etiket (opsiyonel)</span>
                <input type="text" name="label" maxlength="60" placeholder="Kart üstündeki yazı/no">
            </label>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:14px">PERSONELE TANIMLA</button>
    </form>
    <?php endif; ?>
</div>

<!-- ── Kart listesi ───────────────────────────────────────── -->
<form method="get" class="pdks-filter-bar">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="UID veya personel ara…">
    <select name="durum">
        <option value="">Tüm durumlar</option>
        <?php foreach (pdks_kart_durumlari() as $k => $lbl): ?>
        <option value="<?= h($k) ?>" <?= $durum_f === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn">Filtrele</button>
    <?php if ($q !== '' || $durum_f !== ''): ?>
    <a href="personel_kartlar.php" class="btn btn-ghost">Temizle</a>
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
    <th>UID (kanonik)</th>
    <th>Personel</th>
    <th>Durum</th>
    <th>Tanımlandı</th>
    <th>Kaynak</th>
    <th class="actions-col">İşlem</th>
</tr></thead>
<tbody>
<?php foreach ($kartlar as $k): ?>
<tr>
    <td><span class="pdks-uid"><?= h($k['uid_hex']) ?></span></td>
    <td><?= h($k['emp_name']) ?><?= $k['emp_no'] ? ' <span class="muted">(' . h($k['emp_no']) . ')</span>' : '' ?></td>
    <td><span class="pdks-badge pdks-badge-<?= h($k['status']) ?>"><?= h(pdks_kart_durumlari()[$k['status']] ?? $k['status']) ?></span></td>
    <td class="muted"><?= h(fmt_datetime($k['created_at'])) ?></td>
    <td class="muted"><?= h($k['enrolled_source']) ?></td>
    <td class="actions-col">
        <a href="personel_form.php?id=<?= (int)$k['employee_id'] ?>" class="btn btn-sm">Personeli Aç</a>
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
            <div class="pdks-uid"><?= h($k['uid_hex']) ?></div>
            <div class="pdks-row-sub"><?= h($k['emp_name']) ?></div>
        </div>
        <span class="pdks-badge pdks-badge-<?= h($k['status']) ?>"><?= h(pdks_kart_durumlari()[$k['status']] ?? $k['status']) ?></span>
    </div>
    <div class="pdks-card-actions">
        <a href="personel_form.php?id=<?= (int)$k['employee_id'] ?>" class="btn btn-sm">Personeli Aç</a>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<script src="<?= $base ?>assets/pdks.js?v=<?= @filemtime(__DIR__ . '/assets/pdks.js') ?>"></script>
<?php render_footer(); ?>
