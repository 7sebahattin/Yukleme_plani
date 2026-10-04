<?php
// =========================================================
// isci_kartlari.php — Kart Havuzu Yönetimi (Günlük İşçi, Faz 1)
//
// personel_kartlar.php ile AYNI "liste + kart-önce tanımlama" deseni —
// ama kart bir PERSONELE değil bir İŞÇİ TİPİNE bağlanır (bkz. config/
// pdks_gunluk.php başlığı: kart kişi değil, yeniden kullanılabilir bir
// oturum birimidir).
//
// ⚠ NFC/USB OKUMA: bu sayfada YENİ bir NFC kodu YOK — Kart Sorgula ve Seri
// Kart Tanımla, config/pdks.php'nin ortak PdksNfcOku okuma-döngüsünü kullanır.
// v300: tekli kart ekleme formu (assets/pdks.js tek-kart tarama deseni + tekli POST
// ve önizleme ucu) KALDIRILDI — kart ekleme yolu "Seri Kart Tanımla"dır. O desen
// assets/pdks.js'te personel_kartlar.php / personel_form.php için durur.
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
// v298 — Tanımlı Giriş: kart → çavuş + tip (Kadın/Erkek/Rampacı) + depo. Tablo OPSİYONELDİR
// (worker_card_assignments, yalnız migrate.php'den kurulur); yoksa tanım arayüzü gizlenir.
$tanimHazir = pdks_gunluk_kart_tanim_sema_hazir($pdo);
$aktifDepo  = trim((string)(function_exists('active_depot') ? (active_depot() ?? '') : ''));
// v299 — Seri Kart Tanımla: tanım tablosu + Faz 8A (yeni kartlar nötr yazılır) + aktif depo varsa; yoksa düğme/pencere GİZLİ.
$seriHazir  = $tanimHazir && $faz8aHazir && $aktifDepo !== '';

// ── Salt-okunur SORGU ucu (Sprint Kart-Sorgula-01) — GİRİŞ/ÇIKIŞ YAPMAZ,
// yalnız kartın şu anki durumunu + son 5 dönemini (KİMLİĞİNİ ve GEÇMİŞİNİ) döner. daily_worker_work_periods Faz 8A'ya
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

// ── v299 SERİ KART TANIMLA — salt okunur satır durumu. Okutulan UID için kanonik
// UID / havuzda var mı / aktif tanım / çakışma bilgisini ve seçili çavuş + tipe
// göre SINIFI (yeni · tanimlanacak · ayni · baska_cavus · hata) döner. Hiçbir
// yazma YOK; depo = aktif depo (istemciden alınmaz). ──
if (($_GET['ajax'] ?? '') === 'tanim_satir') {
    header('Content-Type: application/json; charset=utf-8');
    $cavusId = (int)($_GET['cavus'] ?? 0);
    $tipId   = (int)($_GET['tip'] ?? 0);
    $baslik  = $seriHazir ? pdks_gunluk_kart_tanim_toplu_baslik($pdo, $cavusId, $tipId, $aktifDepo) : 'Seri tanım için Tanımlı Kart tablosu ve Faz 8A şeması gerekir (migrate.php).';
    if ($baslik !== null) {
        echo json_encode(['ok' => false, 'hata' => $baslik], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $bilgi = pdks_gunluk_kart_tanim_satir_bilgi($pdo, trim((string)($_GET['uid'] ?? '')), trim((string)($_GET['kaynak'] ?? '')));
    echo json_encode($bilgi + pdks_gunluk_kart_tanim_satir_sinifla($bilgi, $cavusId, $tipId, $aktifDepo), JSON_UNESCAPED_UNICODE);
    exit;
}

// ── v299 SERİ KART TANIMLA — HEP-YA-HİÇ kaydet (tek POST, JSON; csrf_check JSON-aware).
// Tüm kural/yazma pdks_gunluk_kart_tanim_toplu_kaydet()'te; burada yalnız kapı + biçim. ──
if (($_GET['ajax'] ?? '') === 'tanim_toplu_kaydet' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $govde = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($govde)) $govde = [];
    csrf_check($govde['csrf'] ?? null);
    require_pdks_gunluk('worker_cards');   // savunma derinliği
    if (!$seriHazir) {
        echo json_encode(['ok' => false, 'hata' => 'Seri tanım için Tanımlı Kart tablosu ve Faz 8A şeması gerekir (migrate.php).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $liste = is_array($govde['satirlar'] ?? null) ? array_slice($govde['satirlar'], 0, PDKS_GUNLUK_TOPLU_TANIM_LIMIT + 1) : [];
    $satirlar = array_map(fn($s) => is_array($s) ? ['ham_uid' => $s['uid'] ?? '', 'kaynak' => $s['kaynak'] ?? ''] : ['ham_uid' => '', 'kaynak' => ''], $liste);
    $sonuc = pdks_gunluk_kart_tanim_toplu_kaydet($satirlar, (int)($govde['cavus'] ?? 0), (int)($govde['tip'] ?? 0),
        $aktifDepo, is_scalar($govde['istek_id'] ?? null) ? (string)$govde['istek_id'] : '', (int)$auth_user['id'], $pdo);
    if (!empty($sonuc['ok'])) {
        $mesaj = $sonuc['tanimlanan'] . ' kart tanımlandı: ' . $sonuc['yeni'] . ' yeni havuza eklendi, ' . $sonuc['ayni'] . ' zaten tanımlıydı.';
        if (!empty($sonuc['uyarilar'])) {
            $mesaj .= ' ⚠ ' . count($sonuc['uyarilar']) . ' kart şu an içeride (' . implode(', ', array_column($sonuc['uyarilar'], 'card_no')) . ') — açık dönem eski çavuşta kalır; çıkış Ortak Çıkış ile yapılır.';
        }
        $sonuc['mesaj'] = $mesaj;
    }
    echo json_encode($sonuc, JSON_UNESCAPED_UNICODE);
    exit;
}

$hata = ''; $basari = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_gunluk('worker_cards');   // savunma derinliği
    $action = trim($_POST['action'] ?? '');

    // v300: tekli kart ekleme POST dalı KALDIRILDI — kart ekleme yolu Seri Kart Tanımla (ajax=tanim_toplu_kaydet).
    if ($action === 'kart_duzenle' && !$faz8aHazir && (int)($_POST['worker_type_id'] ?? 0) <= 0) {
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
    } elseif ($action === 'kart_tanim') {
        // v298: tanım deposu = AKTİF depo (istemciden alınmaz). Yetki = bu sayfanın kapısı.
        $cardId = (int)($_POST['card_id'] ?? 0);
        $sonuc = pdks_gunluk_kart_tanim_kaydet($cardId, (int)($_POST['tanim_foreman_id'] ?? 0),
            (int)($_POST['tanim_worker_type_id'] ?? 0), $aktifDepo, (int)$auth_user['id'], $pdo);
        if ($sonuc['ok']) {
            $mesaj = !empty($sonuc['degisiklik_yok']) ? 'Tanım zaten bu şekilde — değişiklik yapılmadı.' : 'Kart tanımı kaydedildi.';
            if (!empty($sonuc['uyari'])) $mesaj .= ' ⚠ ' . $sonuc['uyari'];
            header('Location: isci_kartlari.php?ok=' . urlencode($mesaj));
            exit;
        }
        $hata = $sonuc['hata'] ?? 'Tanım kaydedilemedi.';
    } elseif ($action === 'kart_tanim_bitir') {
        $sonuc = pdks_gunluk_kart_tanim_bitir((int)($_POST['card_id'] ?? 0), trim((string)($_POST['tanim_reason'] ?? '')), (int)$auth_user['id'], $pdo);
        if ($sonuc['ok']) {
            header('Location: isci_kartlari.php?ok=' . urlencode('Kart tanımı kaldırıldı.'));
            exit;
        }
        $hata = $sonuc['hata'] ?? 'Tanım kaldırılamadı.';
    } else {
        $hata = 'Bilinmeyen işlem.';
    }
}
if ($hata === '' && isset($_GET['ok'])) $basari = trim($_GET['ok']);

$tipler = pdks_gunluk_tip_listele(true, $pdo);

// ── Kart listesi (filtre) ──────────────────────────────────
$q = trim($_GET['q'] ?? '');
$tip_f = (int)($_GET['tip'] ?? 0);
$durum_f = trim($_GET['durum'] ?? '');
if ($durum_f !== '' && !array_key_exists($durum_f, pdks_gunluk_kart_durumlari())) $durum_f = '';

// Kartsız mesai kayıtlarının SANAL kartları (config/pdks_faz8j.php) havuzda gösterilmez.
$where = ["w.enrolled_source <> 'kartsiz'"]; $params = [];
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

// v298: listedeki kartların aktif tanımları + tanım formu seçenekleri (yalnız AKTİF çavuşlar, KADIN/ERKEK/RAMPACI).
$tanimlar = $tanimHazir ? pdks_gunluk_kart_tanim_listesi($pdo, array_column($kartlar, 'id')) : [];
$tanimCavuslar = []; $tanimTipler = [];
if ($tanimHazir) {
    try {
        $tanimCavuslar = $pdo->query("SELECT id, code, name FROM foremen WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
        $tanimTipler = pdks_gunluk_desteklenen_tip_listele($pdo);
    } catch (PDOException $e) { /* seçenek yoksa form boş kalır */ }
}
/** Liste satırı için tanım rozeti + uyarı rozetleri (HTML). */
$tanimRozet = function (array $k) use ($tanimlar): string {
    $t = $tanimlar[(int)$k['id']] ?? null;
    if (!$t) return '';
    $html = '<span class="pdks-badge pdks-badge-tanimli" title="Tanımlı Giriş: kart bu çavuşa / tipe / depoya girer">🏷 '
          . h($t['foreman_name']) . ' · ' . h($t['tip_adi']) . ' · ' . h($t['depo']) . '</span>';
    if (!(int)$t['foreman_aktif']) $html .= ' <span class="pdks-badge pdks-badge-kayip" title="Tanımlı çavuş pasif — kiosk girişi reddeder">çavuş pasif</span>';
    if ($k['status'] !== 'available') $html .= ' <span class="pdks-badge pdks-badge-iptal" title="Kart kayıp/devre dışı — tanım çalışmaz (tanım kendiliğinden bitmez)">kart ' . h(pdks_gunluk_kart_durumlari()[$k['status']] ?? $k['status']) . '</span>';
    return $html;
};
/** Tanım düğmesinin data-* öznitelikleri (JS değerleri buradan okur; satır içi JS argümanı YOK). */
$tanimData = function (array $k) use ($tanimlar): string {
    $t = $tanimlar[(int)$k['id']] ?? null;
    return ' data-isk-tanim-kart="' . (int)$k['id'] . '" data-isk-tanim-no="' . h($k['card_no']) . '"'
         . ' data-isk-tanim-cavus="' . ($t ? (int)$t['foreman_id'] : 0) . '" data-isk-tanim-tip="' . ($t ? (int)$t['worker_type_id'] : 0) . '"'
         . ' data-isk-tanim-ozet="' . ($t ? h($t['foreman_name'] . ' · ' . $t['tip_adi'] . ' · ' . $t['depo']) : '') . '"'
         . ' data-isk-tanim-pasif="' . ($t && !(int)$t['foreman_aktif'] ? '1' : '0') . '"';
};

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
        <a href="isci_tipleri.php" class="btn btn-ghost">⚙️ İşçi Tipleri</a>
        <a href="personel_takip.php" class="btn btn-geri btn-geri-ptak">← Personel Takibi</a>
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

<?php if ($seriHazir): ?>
<!-- ── Seri Kart Tanımla — kart ekleme/tanımlamanın TEK yolu (v300: tekli ekleme formu
     kaldırıldı). Tablo/Faz 8A/aktif depo hazır değilse hiç çizilmez. -->
<div class="isk-seri-bar">
    <button type="button" class="btn btn-primary" id="iskSeriAc">⚡ Seri Kart Tanımla</button>
    <span class="muted">Çavuş ve tipi seçin, kartları art arda okutun, listeyi kontrol edip tek seferde kaydedin.</span>
</div>
<?php else: ?>
<div class="flash flash-warning" id="iskSeriYok">Yeni kart eklemek için <strong>Seri Kart Tanımla</strong> gerekir
    <?php if (!$tanimHazir): ?>— Tanımlı Kart tablosu kurulmalı: <a href="migrate.php">migrate.php</a>.
    <?php elseif (!$faz8aHazir): ?>— Faz 8A migrasyonu tamamlanmalı: <a href="migrate.php">migrate.php</a>.
    <?php else: ?>— önce üstteki menüden bir depo seçin.<?php endif; ?></div>
<?php endif; ?>

<?php if (!$tanimHazir && function_exists('is_admin') && is_admin()): ?>
<div class="flash flash-warning">🏷 Tanımlı Giriş (karta çavuş + tip tanımlama) için <a href="migrate.php">migrate.php</a> sayfasından "Tanımlı Kart Tablosunu Oluştur" adımını çalıştırın.</div>
<?php endif; ?>

<!-- ── Kart listesi ───────────────────────────────────────── -->
<form method="get" class="pdks-filter-bar" data-oto-filtre>
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
    <?= pdks_oto_filtre_noscript() ?>
    <?php if ($q !== '' || $tip_f > 0 || $durum_f !== ''): ?>
    <a href="isci_kartlari.php" class="btn btn-ghost">Temizle</a>
    <?php endif; ?>
</form>

<?php if (empty($kartlar)): ?>
<div class="pdks-empty">
    <span class="pdks-empty-icon" aria-hidden="true">🪪</span>
    <p>Bu filtrelerle kart bulunamadı.<?= $seriHazir ? ' Kart eklemek için <strong>Seri Kart Tanımla</strong>\'yı kullanın.' : '' ?></p>
</div>
<?php else: ?>
<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr>
    <th>Kart No</th>
    <th>Tip (eski — tanım için kullanılmaz)</th>
    <?php if ($tanimHazir): ?><th>Tanımlı Giriş</th><?php endif; ?>
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
    <?php if ($tanimHazir): ?><td><?= $tanimRozet($k) ?: '<span class="muted">—</span>' ?></td><?php endif; ?>
    <td class="muted pdks-uid"><?= h($k['canonical_uid']) ?></td>
    <td><span class="pdks-badge pdks-badge-<?= $k['status'] === 'available' ? 'aktif' : ($k['status'] === 'lost' ? 'kayip' : 'iptal') ?>">
        <?= h(pdks_gunluk_kart_durumlari()[$k['status']] ?? $k['status']) ?></span></td>
    <td class="muted"><?= h(fmt_datetime($k['created_at'])) ?></td>
    <td class="actions-col">
        <button type="button" class="btn btn-sm" onclick="iskKartModalAc(<?= (int)$k['id'] ?>,<?= $k['worker_type_id'] !== null ? (int)$k['worker_type_id'] : 0 ?>,'<?= h(addslashes($k['card_no'])) ?>','<?= h(addslashes($k['notes'] ?? '')) ?>','<?= h($k['status']) ?>')">Düzenle</button>
        <?php if ($tanimHazir && ($k['enrolled_source'] ?? '') !== 'kartsiz'): ?><button type="button" class="btn btn-sm"<?= $tanimData($k) ?>>🏷 Tanım</button><?php endif; ?>
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
            <?php if ($tanimHazir && ($tanimR = $tanimRozet($k)) !== ''): ?><div class="isk-tanim-satir"><?= $tanimR ?></div><?php endif; ?>
        </div>
        <span class="pdks-badge pdks-badge-<?= $k['status'] === 'available' ? 'aktif' : ($k['status'] === 'lost' ? 'kayip' : 'iptal') ?>">
            <?= h(pdks_gunluk_kart_durumlari()[$k['status']] ?? $k['status']) ?></span>
    </div>
    <div class="pdks-card-actions">
        <button type="button" class="btn btn-sm" onclick="iskKartModalAc(<?= (int)$k['id'] ?>,<?= $k['worker_type_id'] !== null ? (int)$k['worker_type_id'] : 0 ?>,'<?= h(addslashes($k['card_no'])) ?>','<?= h(addslashes($k['notes'] ?? '')) ?>','<?= h($k['status']) ?>')">Düzenle</button>
        <?php if ($tanimHazir && ($k['enrolled_source'] ?? '') !== 'kartsiz'): ?><button type="button" class="btn btn-sm"<?= $tanimData($k) ?>>🏷 Tanım</button><?php endif; ?>
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
                    <span class="form-label">Tip (eski — tanım için kullanılmaz; Tanımlı Giriş için "🏷 Tanım")</span>
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

<?php if ($tanimHazir): ?>
<!-- ── v298: Tanımlı Giriş tanım modalı — karta çavuş + tip (Kadın/Erkek/Rampacı) + AKTİF depo.
     Ayrı küçük modal (düzenleme modalına dokunulmadı); form .isk-card-modal-body İÇİNDE
     (gövde kayar — .pm-dialog > form zinciri kırılmaz). -->
<div class="pm-overlay" id="iskTanimModal" hidden>
<div class="pm-dialog isk-card-modal">
    <div class="pm-header">
        <h2 class="pm-title">🏷 Tanımlı Giriş — <span id="iskTanimKartNo"></span></h2>
        <button type="button" class="pm-close" onclick="pdksCloseModal('iskTanimModal')">✕</button>
    </div>
    <div class="isk-card-modal-body">
        <p class="muted" style="font-size:.85rem;margin-top:0">
            Kioskta "🏷 TANIMLI GİRİŞ" ile okutulunca kart bu çavuşun bugünkü mesaisine bu tiple girer.
            Çavuş seçilen normal girişte başka çavuş/tiple okutulursa reddedilir. Çıkış Ortak Çıkış ile yapılır.
        </p>
        <div class="isk-tanim-mevcut" id="iskTanimMevcut" hidden></div>
        <?php if ($aktifDepo === ''): ?>
        <div class="flash flash-warning">Tanım için önce bir depo seçin.</div>
        <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="kart_tanim">
            <input type="hidden" name="card_id" id="iskTanimCardId">
            <div class="pdks-form-grid">
                <label>
                    <span class="form-label">Tanımlı Çavuş *</span>
                    <select name="tanim_foreman_id" id="iskTanimCavus" required>
                        <option value="">— Çavuş seçin —</option>
                        <?php foreach ($tanimCavuslar as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?> (<?= h($c['code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span class="form-label">Tanımlı Tip *</span>
                    <select name="tanim_worker_type_id" id="iskTanimTip" required>
                        <option value="">— Tip seçin —</option>
                        <?php foreach ($tanimTipler as $t): ?>
                        <option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="span-2 muted" style="font-size:.85rem">Depo: <strong><?= h($aktifDepo) ?></strong> (aktif depo)</div>
            </div>
            <div class="isk-card-form-actions">
                <button type="submit" class="btn btn-primary">Tanımı Kaydet</button>
                <button type="button" class="btn btn-ghost" onclick="pdksCloseModal('iskTanimModal')">Vazgeç</button>
            </div>
        </form>
        <?php endif; ?>
        <form method="post" id="iskTanimBitirForm" hidden>
            <hr>
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="kart_tanim_bitir">
            <input type="hidden" name="card_id" id="iskTanimBitirCardId">
            <div class="pdks-form-grid">
                <label class="span-2">
                    <span class="form-label">Kaldırma notu (opsiyonel)</span>
                    <input type="text" name="tanim_reason" maxlength="255">
                </label>
            </div>
            <div class="isk-card-form-actions">
                <button type="submit" class="btn">✖ Tanımı Kaldır</button>
            </div>
        </form>
    </div>
</div>
</div>
<script>
document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-isk-tanim-kart]');
    if (!b) return;
    var id = b.getAttribute('data-isk-tanim-kart');
    var ozet = b.getAttribute('data-isk-tanim-ozet') || '';
    document.getElementById('iskTanimKartNo').textContent = b.getAttribute('data-isk-tanim-no') || '';
    var idAlan = document.getElementById('iskTanimCardId');
    if (idAlan) idAlan.value = id;
    document.getElementById('iskTanimBitirCardId').value = id;
    var cavus = document.getElementById('iskTanimCavus');
    var tip = document.getElementById('iskTanimTip');
    if (cavus) cavus.value = b.getAttribute('data-isk-tanim-cavus') !== '0' ? b.getAttribute('data-isk-tanim-cavus') : '';
    if (cavus && cavus.selectedIndex < 0) cavus.value = '';   // pasif çavuş listede yok
    if (tip) tip.value = b.getAttribute('data-isk-tanim-tip') !== '0' ? b.getAttribute('data-isk-tanim-tip') : '';
    var mevcut = document.getElementById('iskTanimMevcut');
    mevcut.hidden = ozet === '';
    mevcut.textContent = ozet === '' ? '' : ('Mevcut tanım: ' + ozet + (b.getAttribute('data-isk-tanim-pasif') === '1' ? ' — çavuş pasif, kiosk girişi reddeder' : ''));
    document.getElementById('iskTanimBitirForm').hidden = ozet === '';
    window.pdksOpenModal('iskTanimModal');
});
</script>
<?php endif; ?>

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
        // v298: aktif tanım (Tanımlı Giriş) — salt okunur bilgi.
        if (d.tanim) {
            html += '<div class="isk-tanim-satir" style="margin-top:8px"><span class="pdks-badge pdks-badge-tanimli">🏷 Tanımlı: '
                + esc(d.tanim.foreman_name) + ' · ' + esc(d.tanim.tip_adi) + ' · ' + esc(d.tanim.depo) + '</span>'
                + (d.tanim.foreman_aktif ? '' : ' <span class="pdks-badge pdks-badge-kayip">çavuş pasif</span>') + '</div>';
        }
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

<?php if ($seriHazir): ?>
<!-- ── v299: Seri Kart Tanımla penceresi ─────────────────────────────────────
     Çavuş + tip seçilir (depo = aktif depo, yalnız bilgi), kartlar art arda okutulur
     (USB Enter/250 ms + sürekli NFC), her okutma listeye bir satır ekler; "Kaydet"
     HEP-YA-HİÇ tek POST'tur (?ajax=tanim_toplu_kaydet). Yapı: başlık / kayan gövde /
     sabit alt çubuk — araya <form> SARILMAZ (.pm-dialog flex zinciri bozulmasın;
     CLAUDE.md "Modal içinde form"). Kullanıcı verisi yalnız textContent ile basılır. -->
<div class="pm-overlay" id="iskSeriModal" hidden data-csrf="<?= h(csrf_token()) ?>" data-limit="<?= (int)PDKS_GUNLUK_TOPLU_TANIM_LIMIT ?>">
<div class="pm-dialog isk-card-modal isk-seri" role="dialog" aria-modal="true" aria-labelledby="iskSeriBaslik">
    <div class="pm-header">
        <h2 class="pm-title" id="iskSeriBaslik">⚡ Seri Kart Tanımla</h2>
        <button type="button" class="pm-close" id="iskSeriKapat" aria-label="Kapat">✕</button>
    </div>
    <div class="isk-card-modal-body isk-seri-body">
        <p class="muted isk-seri-not">
            Okutulan her kart seçilen çavuşun altına bu tiple tanımlanır; havuzda olmayan kartlar otomatik eklenir
            (kart no sıradan). Başka çavuşa tanımlı kart <strong>engeldir</strong> — önce Kart Havuzu'ndan "Tanımı Kaldır".
        </p>
        <div class="pdks-form-grid">
            <label>
                <span class="form-label">Çavuş *</span>
                <select id="iskSeriCavus">
                    <option value="">— Çavuş seçin —</option>
                    <?php foreach ($tanimCavuslar as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?> (<?= h($c['code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span class="form-label">Tip *</span>
                <select id="iskSeriTip">
                    <option value="">— Tip seçin —</option>
                    <?php foreach ($tanimTipler as $t): ?>
                    <option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="span-2 muted" style="font-size:.85rem">Depo: <strong><?= h($aktifDepo) ?></strong> (aktif depo)</div>
        </div>
        <div class="pdks-scan-box isk-seri-scan">
            <label class="pdks-scan-label" for="iskSeriGiris">KARTLARI ART ARDA OKUTUN</label>
            <input type="text" inputmode="numeric" id="iskSeriGiris" class="pdks-scan-input"
                   placeholder="Önce çavuş ve tip seçin" autocomplete="off" disabled>
            <div class="pdks-scan-status" id="iskSeriDurum" role="status" aria-live="polite"></div>
            <button type="button" id="iskSeriNfc" class="btn btn-ghost" style="margin-top:8px" hidden>📡 NFC İLE OKU</button>
        </div>
        <div class="isk-seri-mesaj" id="iskSeriMesaj" role="alert" hidden></div>
        <div class="isk-seri-liste-ust">
            <strong>Liste</strong>
            <span class="isk-seri-sayac" id="iskSeriSayac">0 kart</span>
        </div>
        <div class="isk-seri-liste" id="iskSeriListe"></div>
        <p class="muted isk-seri-bos" id="iskSeriBos">Henüz kart okutulmadı.</p>
    </div>
    <div class="isk-seri-foot">
        <button type="button" class="btn btn-ghost" id="iskSeriTemizle">Listeyi Temizle</button>
        <span class="isk-seri-foot-bosluk"></span>
        <button type="button" class="btn btn-ghost" id="iskSeriVazgec">Vazgeç</button>
        <button type="button" class="btn btn-primary" id="iskSeriKaydet" disabled>Kaydet</button>
    </div>
</div>
</div>
<script>
(function () {
    'use strict';
    var modal = document.getElementById('iskSeriModal');
    var acBtn = document.getElementById('iskSeriAc');
    var input = document.getElementById('iskSeriGiris');
    if (!modal || !acBtn || !input) return;

    var LIMIT     = parseInt(modal.getAttribute('data-limit'), 10) || 100;
    var csrf      = modal.getAttribute('data-csrf') || '';
    var cavusEl   = document.getElementById('iskSeriCavus');
    var tipEl     = document.getElementById('iskSeriTip');
    var durumEl   = document.getElementById('iskSeriDurum');
    var mesajEl   = document.getElementById('iskSeriMesaj');
    var listeEl   = document.getElementById('iskSeriListe');
    var bosEl     = document.getElementById('iskSeriBos');
    var sayacEl   = document.getElementById('iskSeriSayac');
    var kaydetBtn = document.getElementById('iskSeriKaydet');
    var nfcBtn    = document.getElementById('iskSeriNfc');

    var ETIKET = { yeni: 'YENİ', tanimlanacak: 'TANIMLANACAK', ayni: 'ZATEN TANIMLI', baska_cavus: 'BAŞKA ÇAVUŞTA', hata: 'HATA', bekliyor: 'KONTROL…' };

    var satirlar = [];          // {id, ham, kaynak, sinif, hata, cardNo, canonical, tanim}
    var sayac = 0;
    var kuyruk = Promise.resolve();   // sorgular sırayla — listede okutma sırası korunur
    var surum = 0;              // seçim değiştikçe artar; eski yanıtlar yok sayılır
    var gonderiyor = false;
    var istekId = yeniIstek();
    var timer = null;

    function yeniIstek() {
        var a = new Uint8Array(16), s = '';
        try { (window.crypto || window.msCrypto).getRandomValues(a); }
        catch (e) { for (var i = 0; i < 16; i++) a[i] = Math.floor(Math.random() * 256); }
        for (var j = 0; j < a.length; j++) s += ('0' + a[j].toString(16)).slice(-2);
        return s;
    }
    function secimTamam() { return !!(cavusEl.value && tipEl.value); }
    function mesaj(metin, tur) {
        if (!metin) { mesajEl.hidden = true; mesajEl.textContent = ''; return; }
        mesajEl.className = 'isk-seri-mesaj ' + (tur || 'hata');
        mesajEl.textContent = metin;
        mesajEl.hidden = false;
    }
    function durum(metin, sinif) { durumEl.textContent = metin || ''; durumEl.className = 'pdks-scan-status' + (sinif ? ' ' + sinif : ''); }

    function engelli(s) { return s.sinif === 'hata' || s.sinif === 'baska_cavus'; }
    function render() {
        var n = { yeni: 0, tanimlanacak: 0, ayni: 0, hata: 0, bekliyor: 0 };
        listeEl.textContent = '';
        satirlar.forEach(function (s, i) {
            var k = s.bekliyor ? 'bekliyor' : (engelli(s) ? 'hata' : s.sinif);
            n[k] = (n[k] || 0) + 1;
            var sat = document.createElement('div');
            sat.className = 'isk-seri-satir isk-seri-s-' + (s.bekliyor ? 'bekliyor' : s.sinif);
            sat.setAttribute('data-sid', s.id);
            var no = document.createElement('span'); no.className = 'isk-seri-no'; no.textContent = String(i + 1);
            var bilgi = document.createElement('div'); bilgi.className = 'isk-seri-bilgi';
            var ust = document.createElement('div'); ust.className = 'isk-seri-ust';
            var uid = document.createElement('span'); uid.className = 'pdks-uid';
            uid.textContent = s.cardNo ? s.cardNo : (s.kaynak === 'web_nfc' ? (s.canonical || s.ham) : s.ham);
            var rozet = document.createElement('span'); rozet.className = 'isk-seri-rozet';
            rozet.textContent = ETIKET[s.bekliyor ? 'bekliyor' : s.sinif] || s.sinif;
            ust.appendChild(uid); ust.appendChild(rozet);
            bilgi.appendChild(ust);
            var alt = '';
            if (!s.bekliyor) {
                if (s.sinif === 'yeni') alt = 'Havuzda yok — otomatik eklenecek (' + s.ham + ')';
                else if (s.sinif === 'tanimlanacak') alt = s.tanim ? ('Tanım güncellenecek (şu an: ' + s.tanim.foreman_name + ' · ' + s.tanim.tip_adi + ')') : 'Havuzda var, tanımsız';
                else if (s.sinif === 'ayni') alt = 'Zaten bu çavuş / tip / depoda — atlanır';
                else alt = s.hata || '';
            }
            if (alt) { var a = document.createElement('div'); a.className = 'isk-seri-alt'; a.textContent = alt; bilgi.appendChild(a); }
            var sil = document.createElement('button');
            sil.type = 'button'; sil.className = 'isk-seri-sil'; sil.setAttribute('aria-label', 'Satırı sil'); sil.setAttribute('data-sil', s.id);
            sil.textContent = '✕'; sil.disabled = gonderiyor;
            sat.appendChild(no); sat.appendChild(bilgi); sat.appendChild(sil);
            listeEl.appendChild(sat);
        });
        bosEl.hidden = satirlar.length > 0;
        var parca = [satirlar.length + ' kart'];
        if (n.yeni) parca.push(n.yeni + ' yeni');
        if (n.tanimlanacak) parca.push(n.tanimlanacak + ' tanımlanacak');
        if (n.ayni) parca.push(n.ayni + ' zaten tanımlı');
        if (n.hata) parca.push(n.hata + ' hata');
        if (n.bekliyor) parca.push(n.bekliyor + ' kontrol ediliyor');
        sayacEl.textContent = parca.join(' · ');
        sayacEl.className = 'isk-seri-sayac' + (n.hata ? ' hatali' : '');
        kaydetBtn.disabled = gonderiyor || !secimTamam() || satirlar.length === 0 || n.hata > 0 || n.bekliyor > 0;
        input.disabled = gonderiyor || !secimTamam();
        input.placeholder = secimTamam() ? '631799511' : 'Önce çavuş ve tip seçin';
    }

    function vurgula(s) {
        var el = listeEl.querySelector('[data-sid="' + s.id + '"]');
        if (!el) return;
        el.classList.add('isk-seri-vurgu');
        try { el.scrollIntoView({ block: 'nearest' }); } catch (e) { /* eski tarayıcı */ }
        setTimeout(function () { el.classList.remove('isk-seri-vurgu'); }, 1400);
    }
    function degisti() { istekId = yeniIstek(); }

    function sorgula(s) {
        var v = surum;
        s.bekliyor = true;
        return fetch('isci_kartlari.php?ajax=tanim_satir&kaynak=' + encodeURIComponent(s.kaynak) + '&uid=' + encodeURIComponent(s.ham)
                + '&cavus=' + encodeURIComponent(cavusEl.value) + '&tip=' + encodeURIComponent(tipEl.value),
                { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (v !== surum || satirlar.indexOf(s) < 0) return;   // seçim değişti ya da satır silindi
                s.bekliyor = false;
                if (!d.sinif) { s.sinif = 'hata'; s.hata = d.hata || 'Kontrol edilemedi.'; mesaj(d.hata || 'Kontrol edilemedi.'); render(); return; }
                // Aynı kanonik UID zaten listede → yeni satır YOK, mevcut satır vurgulanır.
                var var_ = d.canonical ? satirlar.filter(function (o) { return o !== s && !o.bekliyor && o.canonical === d.canonical; })[0] : null;
                if (var_) {
                    satirlar.splice(satirlar.indexOf(s), 1);
                    render(); vurgula(var_);
                    durum('Zaten listede: ' + (var_.cardNo || var_.ham), 'warn');
                    return;
                }
                s.sinif = d.sinif; s.hata = d.hata || ''; s.cardNo = d.card_no || ''; s.canonical = d.canonical || ''; s.tanim = d.tanim || null;
                render();
            })
            .catch(function () {
                if (v !== surum || satirlar.indexOf(s) < 0) return;
                s.bekliyor = false; s.sinif = 'hata'; s.hata = 'Kontrol edilemedi — bağlantıyı denetleyin; satırı silip yeniden okutun.';
                render();
            });
    }
    function sirala(s) { kuyruk = kuyruk.then(function () { return sorgula(s); }); }

    function ekle(ham, kaynak) {
        ham = String(ham || '').trim();
        if (ham === '') return;
        if (modal.hidden) return;
        if (!secimTamam()) { mesaj('Önce çavuş ve tipi seçin.'); return; }
        mesaj('');
        var ayniOkuma = satirlar.filter(function (o) { return o.ham === ham && o.kaynak === kaynak; })[0];
        if (ayniOkuma) { vurgula(ayniOkuma); durum('Zaten listede: ' + (ayniOkuma.cardNo || ayniOkuma.ham), 'warn'); return; }
        if (satirlar.length >= LIMIT) { mesaj('Bir seferde en çok ' + LIMIT + ' kart tanımlanabilir — önce bu listeyi kaydedin.'); return; }
        var s = { id: ++sayac, ham: ham, kaynak: kaynak, sinif: '', hata: '', cardNo: '', canonical: '', tanim: null, bekliyor: true };
        satirlar.push(s);
        degisti(); durum('');
        render();
        try { listeEl.lastElementChild.scrollIntoView({ block: 'nearest' }); } catch (e) { /* yoksay */ }
        sirala(s);
    }

    function yenidenSinifla() {
        surum++;
        degisti(); mesaj('');
        satirlar.forEach(function (s) { s.bekliyor = true; });
        render();
        satirlar.slice().forEach(sirala);
    }

    // ── USB (klavye tipi okuyucu): yalnız rakam; Enter ya da 250 ms durgunluk ──
    input.addEventListener('input', function () {
        var t = input.value.replace(/[^0-9]/g, '');
        if (t !== input.value) input.value = t;
        clearTimeout(timer);
        if (t === '') return;
        timer = setTimeout(function () { input.value = ''; ekle(t, 'usb_decimal'); input.focus(); }, 250);
    });
    input.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        clearTimeout(timer);
        var t = input.value.trim();
        input.value = '';
        if (t !== '') ekle(t, 'usb_decimal');
    });

    // ── Web NFC: paylaşılan PdksNfcOku (sürekli dinleme) — pencere kapalıyken okumalar yok sayılır ──
    if (window.PdksNfcOku && window.PdksNfcOku.destekli()) {
        nfcBtn.hidden = false;
        var dinlemede = false;
        nfcBtn.addEventListener('click', function () {
            if (dinlemede) return;
            window.PdksNfcOku.baslat({
                onOkuma: function (ev) { if (!modal.hidden && ev.serialNumber != null) ekle(String(ev.serialNumber), 'web_nfc'); },
                onOkumaHatasi: function () { durum('NFC okuma hatası — kartı tekrar yaklaştırın.', 'err'); },
                onBasladi: function () { dinlemede = true; nfcBtn.textContent = '🟢 NFC DİNLENİYOR'; nfcBtn.disabled = true; },
                onHata: function (ad, msj) { durum('NFC başlatılamadı: ' + msj, 'err'); }
            });
        });
    }

    // ── Satır sil / seçim değişimi / pencere ──
    listeEl.addEventListener('click', function (e) {
        var b = e.target.closest('[data-sil]');
        if (!b || gonderiyor) return;
        var id = parseInt(b.getAttribute('data-sil'), 10);
        satirlar = satirlar.filter(function (s) { return s.id !== id; });
        degisti(); mesaj(''); render();
    });
    cavusEl.addEventListener('change', function () { yenidenSinifla(); if (secimTamam()) input.focus(); });
    tipEl.addEventListener('change', function () { yenidenSinifla(); if (secimTamam()) input.focus(); });
    document.getElementById('iskSeriTemizle').addEventListener('click', function () {
        if (gonderiyor) return;
        surum++; satirlar = []; degisti(); mesaj(''); durum(''); render();
        if (!input.disabled) input.focus();
    });
    function kapat() { if (!gonderiyor) window.pdksCloseModal('iskSeriModal'); }
    document.getElementById('iskSeriVazgec').addEventListener('click', kapat);
    document.getElementById('iskSeriKapat').addEventListener('click', kapat);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) kapat(); });
    acBtn.addEventListener('click', function () {
        window.pdksOpenModal('iskSeriModal');
        render();
        setTimeout(function () { (secimTamam() ? input : cavusEl).focus(); }, 60);
    });

    // ── Kaydet: HEP-YA-HİÇ tek POST ──
    kaydetBtn.addEventListener('click', function () {
        if (gonderiyor || kaydetBtn.disabled) return;
        gonderiyor = true; mesaj(''); durum('Kaydediliyor…'); render();
        fetch('isci_kartlari.php?ajax=tanim_toplu_kaydet', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                csrf: csrf, cavus: parseInt(cavusEl.value, 10) || 0, tip: parseInt(tipEl.value, 10) || 0, istek_id: istekId,
                satirlar: satirlar.map(function (s) { return { uid: s.ham, kaynak: s.kaynak }; })
            })
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.ok) { location.href = 'isci_kartlari.php?ok=' + encodeURIComponent(d.mesaj || 'Kartlar tanımlandı.'); return; }
                gonderiyor = false; durum('');
                if (d && d.satirlar) {   // sunucunun satır bazlı hataları — liste KORUNUR, hatalı satırlar kırmızı
                    d.satirlar.forEach(function (r) {
                        var s = satirlar[r.idx];
                        if (!s) return;
                        s.sinif = r.sinif; s.hata = r.hata || ''; s.cardNo = r.card_no || s.cardNo; s.tanim = r.tanim || s.tanim;
                    });
                }
                mesaj((d && (d.hata || d.error)) || 'Kaydedilemedi.');
                render();
            })
            .catch(function () {
                gonderiyor = false; durum('');
                mesaj('Bağlantı hatası — liste korundu. Kayıt yapılmış olabilir: sayfayı yenileyip kontrol edin ya da tekrar deneyin (çift kayıt oluşmaz).');
                render();
            });
    });
    render();
})();
</script>
<?php endif; ?>

<script src="<?= $base ?>assets/pdks.js?v=<?= @filemtime(__DIR__ . '/assets/pdks.js') ?>"></script>
<?php pdks_liste_ui_js(); ?>
<?php render_footer(); ?>
