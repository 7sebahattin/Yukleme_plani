<?php
// =========================================================
// cavus_fiyatlari.php — Çavuş Fiyat Yönetimi
// Faz 8B şeması hazırsa Tam/Yarım/FM fiyatları; hazır değilse Faz 4'ün
// mevcut tek günlük ücret davranışı aynen devam eder.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/pdks_faz8b_cavus_b.php';
require_once __DIR__ . '/config/pdks_servis.php';   // v299 Servis Ücreti
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_hakedis('rates');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_hakedis_sayfa_kapisi($pdo);
$faz8bHazir = pdks_faz8b_sema_hazir($pdo);
// v299: fiyat dönemi saatleri + Çift Yevmiye — kendi kolonları; sayfa açılışında
// migrate ÇAĞRILMAZ (kurulum yalnız migrate.php kartı).
$saatHazir = $faz8bHazir && pdks_faz8b_saat_kolonlari_hazir($pdo);

// Çavuş Ücreti (Faz 8B eki): tablo yoksa BİR KEZ otomatik oluşturmayı dene
// (idempotent migrasyon fonksiyonu) — başarısızsa aşağıda uyarı kartı gösterilir.
if (!pdks_faz8b_cavus_ucret_sema_hazir($pdo)) {
    pdks_faz8b_cavus_ucret_migrate($pdo);
}
$cavusUcretHazir = pdks_faz8b_cavus_ucret_sema_hazir($pdo);

// Çavuş Ücreti Yöntem B: kendi 3 yeni tablosu, AYNI otomatik-deneme deseni.
// v297: eski kurulumda yöntem geçmişinin `unit_size` kolonu da AYNI
// idempotent migrasyonla eklenir (kolon varsa dokunulmaz).
if ($cavusUcretHazir && (!pdks_faz8b_cavus_ucret_b_sema_hazir($pdo) || !pdks_faz8b_cavus_ucret_b_birim_kolonu_var($pdo))) {
    pdks_faz8b_cavus_ucret_b_migrate($pdo);
}
$cavusBHazir = pdks_faz8b_cavus_ucret_b_sema_hazir($pdo);
// v299 Servis Ücreti: kendi 2 tablosu; sayfada migrate ÇAĞRILMAZ (kurulum yalnız migrate.php).
$servisHazir = pdks_servis_sema_hazir($pdo);

$paraBirimleri = [
    'TRY' => 'Türk Lirası (TRY)',
    'EUR' => 'Euro (EUR)',
    'USD' => 'ABD Doları (USD)',
    'GBP' => 'İngiliz Sterlini (GBP)',
];

$cavusId = filter_var($_GET['cavus'] ?? '', FILTER_VALIDATE_INT) ?: null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'cavus_ucret') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_hakedis('rates');
    $cavusId = filter_var($_POST['foreman_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
    $ucret = trim((string)($_POST['cavus_daily_rate'] ?? ''));
    $ccy = strtoupper(trim((string)($_POST['cavus_currency'] ?? 'TRY'))) ?: 'TRY';
    $vf = trim((string)($_POST['cavus_valid_from'] ?? ''));

    if (!$cavusId) {
        $errors[] = 'Çavuş seçilmedi.';
    } elseif (!array_key_exists($ccy, $paraBirimleri)) {
        $errors[] = 'Geçersiz para birimi seçildi.';
    } else {
        $sonuc = pdks_faz8b_cavus_ucret_ekle($cavusId, $ucret, $vf, $ccy, (int)$auth_user['id'], $pdo);
        if ($sonuc['ok']) {
            header('Location: cavus_fiyatlari.php?cavus=' . $cavusId . '&ok=' . urlencode('Çavuş ücreti eklendi.'));
            exit;
        }
        $errors[] = $sonuc['hata'] ?? 'Kaydedilemedi.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'servis_ucret') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_hakedis('rates');
    $cavusId = filter_var($_POST['foreman_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
    $svCcy = strtoupper(trim((string)($_POST['servis_currency'] ?? 'TRY'))) ?: 'TRY';
    if (!$cavusId) {
        $errors[] = 'Çavuş seçilmedi.';
    } elseif (!$servisHazir) {
        $errors[] = 'Servis Ücreti tabloları kurulmamış — yönetici migrate.php\'den kurabilir.';
    } elseif (!array_key_exists($svCcy, $paraBirimleri)) {
        $errors[] = 'Geçersiz para birimi seçildi.';
    } else {
        $sonuc = pdks_servis_ucret_ekle($cavusId, (string)($_POST['servis_buyuk'] ?? ''), (string)($_POST['servis_kucuk'] ?? ''),
            trim((string)($_POST['servis_valid_from'] ?? '')), $svCcy, (int)$auth_user['id'], $pdo);
        if ($sonuc['ok']) {
            header('Location: cavus_fiyatlari.php?cavus=' . $cavusId . '&ok=' . urlencode('Servis ücreti dönemi eklendi.') . '#cfServisUcreti');
            exit;
        }
        $errors[] = $sonuc['hata'] ?? 'Kaydedilemedi.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'cavus_yontem') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_hakedis('rates');
    $cavusId = filter_var($_POST['foreman_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
    $yeniYontem = strtoupper(trim((string)($_POST['cavus_yontem'] ?? '')));
    // v297: "Kaç kişi-gün = 1 hakediş" — yalnız Yöntem B'de okunur; doğrulama
    // sunucuda (pdks_faz8b_cavus_ucret_b_birim_dogrula, 1–1000 tam sayı).
    $birimHam = isset($_POST['cavus_birim']) ? (string)$_POST['cavus_birim'] : null;

    if (!$cavusId) {
        $errors[] = 'Çavuş seçilmedi.';
    } else {
        $sonuc = pdks_faz8b_cavus_ucret_yontem_degistir($cavusId, $yeniYontem, (int)$auth_user['id'], $pdo, $birimHam);
        if ($sonuc['ok']) {
            if (!$sonuc['degisti']) {
                $msg = 'Hesaplama yöntemi zaten seçili; değişiklik yapılmadı.';
            } elseif ($sonuc['yontem'] === 'B' && ($sonuc['eski'] ?? '') === 'B') {
                $msg = 'Yöntem B birimi ' . (int)$sonuc['birim'] . ' kişi-gün = 1 hakediş olarak güncellendi (önceki: '
                    . (int)$sonuc['eski_birim'] . '). Geriye dönük değildir; yapılmış kapanışlar değişmez.';
            } elseif ($sonuc['yontem'] === 'B') {
                $msg = 'Hesaplama yöntemi Yöntem B (' . (int)$sonuc['birim'] . ' kişi-gün = 1 hakediş) olarak kaydedildi.';
            } else {
                $msg = "Hesaplama yöntemi Yöntem A (günlük sabit ücret) olarak kaydedildi. Yöntem B'den bekleyen kişi-günler silinmedi; çavuş tekrar B'ye alınınca sayılmaya devam eder.";
            }
            header('Location: cavus_fiyatlari.php?cavus=' . $cavusId . '&ok=' . urlencode($msg));
            exit;
        }
        $errors[] = $sonuc['hata'] ?? 'Kaydedilemedi.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_hakedis('rates');
    $cavusId = filter_var($_POST['foreman_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
    $workerTypeId = filter_var($_POST['worker_type_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
    $tamUcret = trim((string)($_POST['daily_rate'] ?? ''));
    $validFrom = trim((string)($_POST['valid_from'] ?? ''));
    $currency = strtoupper(trim((string)($_POST['currency'] ?? 'TRY'))) ?: 'TRY';

    if (!$cavusId || !$workerTypeId) {
        $errors[] = 'Çavuş ve işçi tipi zorunludur.';
    } elseif (!array_key_exists($currency, $paraBirimleri)) {
        $errors[] = 'Geçersiz para birimi seçildi.';
    } else {
        if ($faz8bHazir) {
            $yarimUcret = trim((string)($_POST['half_day_rate'] ?? ''));
            $fmMode = trim((string)($_POST['overtime_mode'] ?? ''));
            $fmUcret = trim((string)($_POST['overtime_rate'] ?? ''));
            // v299: saat alanları (boş = NULL = bugünkü davranış); doğrulama sunucuda.
            $saatler = [
                'full_day' => (string)($_POST['full_day_saat'] ?? ''),
                'half_day' => (string)($_POST['half_day_saat'] ?? ''),
                'overtime_start' => (string)($_POST['overtime_start_saat'] ?? ''),
                'double_day' => (string)($_POST['double_day_saat'] ?? ''),
                'double_day_rate' => (string)($_POST['double_day_rate'] ?? ''),
            ];
            $sonuc = pdks_faz8b_oran_ekle(
                $cavusId,
                $workerTypeId,
                $tamUcret,
                $yarimUcret,
                $fmMode,
                $fmUcret,
                $validFrom,
                $currency,
                (int)$auth_user['id'],
                $pdo,
                $saatler
            );
        } else {
            $sonuc = pdks_hakedis_oran_ekle(
                $cavusId,
                $workerTypeId,
                $tamUcret,
                $validFrom,
                $currency,
                (int)$auth_user['id'],
                $pdo
            );
        }

        if ($sonuc['ok']) {
            header('Location: cavus_fiyatlari.php?cavus=' . $cavusId . '&ok=' . urlencode('Yeni fiyat dönemi eklendi.'));
            exit;
        }
        $errors[] = $sonuc['hata'] ?? 'Kaydedilemedi.';
    }
}

$basari = '';
if (empty($errors) && isset($_GET['ok'])) $basari = trim((string)$_GET['ok']);
$seciliParaBirimi = strtoupper(trim((string)($_POST['currency'] ?? 'TRY')));
if (!array_key_exists($seciliParaBirimi, $paraBirimleri)) $seciliParaBirimi = 'TRY';

$cavuslar = $pdo->query("SELECT id, code, name, is_active FROM foremen ORDER BY is_active DESC, name ASC")->fetchAll();
// ⚠ Faz 9B / H-01 kapanışı: YENİ oran tanımlama açılır listesi TEK
// paylaşılan politikadan (config/pdks_gunluk.php) gelir — yalnız KADIN/ERKEK.
// Mevcut/tarihsel oranlar (aşağıdaki liste, pdks_hakedis_oran_gecmisi())
// durum/tip fark etmeksizin AYNEN görüntülenmeye devam eder.
$tipler = pdks_gunluk_desteklenen_tip_listele($pdo);
$seciliCavus = null;
$oranlar = [];
if ($cavusId !== null) {
    foreach ($cavuslar as $c) {
        if ((int)$c['id'] === $cavusId) { $seciliCavus = $c; break; }
    }
    if ($seciliCavus) $oranlar = pdks_hakedis_oran_gecmisi($cavusId, $pdo);
}
$cavusUcretGecmisi = ($seciliCavus && $cavusUcretHazir) ? pdks_faz8b_cavus_ucret_gecmisi($cavusId, $pdo) : [];
$servisGecmisi = ($seciliCavus && $servisHazir) ? pdks_servis_ucret_gecmisi($cavusId, $pdo) : [];
$servisHata = $errors && ($_POST['form'] ?? '') === 'servis_ucret';
$cavusYontem = ($seciliCavus && $cavusBHazir) ? pdks_faz8b_cavus_ucret_yontem($cavusId, $pdo) : 'A';
$cavusYontemGecmisi = ($seciliCavus && $cavusBHazir)
    ? array_reverse(pdks_faz8b_cavus_ucret_yontem_gecmisi($cavusId, $pdo))
    : [];
// v297: çavuşun ŞU AN geçerli Yöntem B birimi (NULL → 25). Hatalı POST'ta
// girilen değer formda korunur.
$cavusBirim = ($seciliCavus && $cavusBHazir) ? pdks_faz8b_cavus_ucret_birim($cavusId, $pdo) : PDKS_FAZ8B_CAVUS_B_BIRIM;
$cavusBirimForm = (string)$cavusBirim;
if ($errors && ($_POST['form'] ?? '') === 'cavus_yontem' && isset($_POST['cavus_birim'])) {
    $cavusBirimForm = substr(trim((string)$_POST['cavus_birim']), 0, 12);
}
$cavusYontemSecili = ($errors && ($_POST['form'] ?? '') === 'cavus_yontem' && in_array(($_POST['cavus_yontem'] ?? ''), ['A', 'B'], true))
    ? (string)$_POST['cavus_yontem'] : $cavusYontem;
// v299: saat alanlarının varsayılanı = çavuşun normal günlük süresi (Tam saati);
// hatalı POST'ta girilen değerler korunur.
$oranHataPost = $errors && ($_POST['form'] ?? '') === 'oran';
$saatForm = [
    'full_day_saat' => $seciliCavus ? pdks_faz8b_dk_girdi(pdks_faz8b_cavus_normal_sure_dk((int)$seciliCavus['id'], $pdo)) : '',
    'half_day_saat' => '', 'overtime_start_saat' => '', 'double_day_saat' => '', 'double_day_rate' => '',
];
if ($oranHataPost) {
    foreach (array_keys($saatForm) as $k) $saatForm[$k] = substr(trim((string)($_POST[$k] ?? '')), 0, 16);
}
$cavusYontemEtiket = pdks_faz8b_cavus_ucret_yontem_etiketi($cavusYontem, $cavusYontem === 'B' ? $cavusBirim : null);

// Satır içi SVG simgeler (stroke = currentColor; CDN yok). Sayfa testte birden
// çok kez include edildiği için fonksiyon DEĞİL, kapanış (closure).
$cfIk = function (string $ad): string {
    static $yol = [
        'cuzdan'  => '<rect x="3" y="6" width="18" height="14" rx="2.5"/><path d="M3 10h18"/><path d="M16 15h2"/><path d="M7 6V4.5A1.5 1.5 0 0 1 8.5 3h7A1.5 1.5 0 0 1 17 4.5V6"/>',
        'kisi'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/>',
        'takvim+' => '<rect x="3" y="4" width="18" height="17" rx="2.5"/><path d="M16 2v4M8 2v4M3 10h18M12 13.5v5M9.5 16h5"/>',
        'takvim'  => '<rect x="3" y="4" width="18" height="17" rx="2.5"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'kisiler' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.6c2 .6 3.5 2.4 3.5 5.4"/>',
        'gunes'   => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'yarim'   => '<circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor" stroke="none"/>',
        'saat'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
        'saat+'   => '<circle cx="11" cy="13" r="8"/><path d="M11 9v4l2.5 2.5M19 2v5M16.5 4.5h5"/>',
        'para'    => '<circle cx="12" cy="12" r="9"/><path d="M10 6.5v9a3 3 0 0 0 5-2.2"/><path d="M7.5 11l6-2.2M7.5 14l6-2.2"/>',
        'bilgi'   => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 7.6v.4"/>',
        'gecmis'  => '<path d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1L3.5 8.5"/><path d="M3.5 3.5v5h5"/><path d="M12 7.5V12l3 2"/>',
        'disli'   => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'kare'    => '<path d="M4 9h16M4 15h16M10 3 8 21M16 3l-2 18"/>',
        'servis'  => '<rect x="3" y="5" width="18" height="12" rx="2.5"/><path d="M3 11h18M8 5v6M16 5v6"/><circle cx="7.5" cy="19" r="1.6"/><circle cx="16.5" cy="19" r="1.6"/>',
        'uyari'   => '<path d="M12 3.5 2.5 20h19L12 3.5z"/><path d="M12 10v4.5"/><path d="M12 17.3v.2"/>',
    ];
    return '<svg class="cf-ik" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($yol[$ad] ?? '') . '</svg>';
};

render_header('Çavuş Ücretleri');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="cf2">

<div class="cf2-head">
    <div class="cf2-baslik">
        <span class="cf2-tile cf2-tile--yesil"><?= $cfIk('cuzdan') ?></span>
        <h1>Çavuş Ücretleri</h1>
    </div>
    <a href="personel_takip.php" class="btn btn-geri">← Personel Takibi</a>
</div>

<?php if ($basari !== ''): ?><div class="flash flash-success"><?= h($basari) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="flash flash-error"><?= h($e) ?></div><?php endforeach; ?>
<?php if (!$faz8bHazir): ?><div class="flash flash-warning">Faz 8B şeması henüz çalıştırılmadı. Bu ekran mevcut tek Günlük Ücret modeliyle güvenli biçimde devam ediyor. Yönetici <a href="migrate.php">Şema Migrasyon</a> ekranından Faz 8B migrasyonunu çalıştırabilir.</div><?php endif; ?>

<form method="get" class="pdks-filter-bar cf2-sec" data-oto-filtre>
    <label class="cf2-kontrol cf2-kontrol--sec">
        <span class="cf2-kontrol-ik"><?= $cfIk('kisi') ?></span>
        <select name="cavus" aria-label="Çavuş">
            <option value="">— Çavuş seçin —</option>
            <?php foreach ($cavuslar as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $cavusId === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?> (<?= h($c['code']) ?>)<?= $c['is_active'] ? '' : ' — pasif' ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?= pdks_oto_filtre_noscript('Seç') ?>
</form>

<?php if (!$seciliCavus): ?>
<div class="pdks-empty"><span class="pdks-empty-icon" aria-hidden="true">💰</span><p>Fiyatları görmek/eklemek için önce bir çavuş seçin.</p></div>
<?php else: ?>

<section class="card cf2-kart" id="cfYeniDonem">
    <header class="cf2-kart-bas">
        <span class="cf2-tile cf2-tile--mavi"><?= $cfIk('takvim+') ?></span>
        <div class="cf2-kart-bas-metin">
            <h2>Yeni Etkin Fiyat Dönemi — <?= h($seciliCavus['name']) ?></h2>
            <p class="cf2-alt">İşçi tipi, mesai ücretleri ve geçerlilik başlangıcı bilgilerini girerek yeni fiyat dönemi ekleyin.</p>
        </div>
    </header>
    <form method="post" class="cf2-form">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="foreman_id" value="<?= (int)$seciliCavus['id'] ?>">
        <input type="hidden" name="form" value="oran">
        <div class="cf2-izgara">
            <label class="cf2-alan">
                <span class="cf2-etiket"><?= $cfIk('kisiler') ?>İşçi Tipi <b class="cf2-zorunlu">*</b></span>
                <span class="cf2-girdi"><span class="cf2-girdi-ik"><?= $cfIk('kisiler') ?></span>
                <select name="worker_type_id" required>
                    <option value="">— Seçin —</option>
                    <?php foreach ($tipler as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?>
                </select></span>
            </label>
            <label class="cf2-alan">
                <span class="cf2-etiket"><?= $cfIk('para') ?>Para Birimi <b class="cf2-zorunlu">*</b></span>
                <span class="cf2-girdi"><span class="cf2-girdi-ik"><?= $cfIk('para') ?></span>
                <select name="currency" required>
                    <?php foreach ($paraBirimleri as $kod => $etiket): ?>
                    <option value="<?= h($kod) ?>" <?= $seciliParaBirimi === $kod ? 'selected' : '' ?>><?= h($etiket) ?></option>
                    <?php endforeach; ?>
                </select></span>
            </label>
        </div>

        <div class="cf2-grup" data-cf-grup="tam">
            <div class="cf2-grup-bas"><?= $cfIk('gunes') ?><span><?= $faz8bHazir ? 'Tam Yevmiye' : 'Günlük Ücret' ?></span></div>
            <div class="cf2-izgara">
                <label class="cf2-alan">
                    <span class="cf2-etiket"><?= $cfIk('para') ?><?= $faz8bHazir ? 'Tam Mesai Ücreti' : 'Günlük Ücret' ?> <b class="cf2-zorunlu">*</b></span>
                    <span class="cf2-girdi"><span class="cf2-girdi-ik"><?= $cfIk('para') ?></span>
                    <input type="text" name="daily_rate" required inputmode="decimal" placeholder="ör. 1500 veya 1500,50"></span>
                </label>
                <?php if ($saatHazir): ?>
                <label class="cf2-alan">
                    <span class="cf2-etiket"><?= $cfIk('saat') ?>Tam Yevmiye Saati</span>
                    <span class="cf2-girdi"><span class="cf2-girdi-ik"><?= $cfIk('saat') ?></span>
                    <input type="text" name="full_day_saat" inputmode="decimal" maxlength="5" autocomplete="off" placeholder="ör. 9 ya da 9:30" value="<?= h($saatForm['full_day_saat']) ?>"></span>
                </label>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($faz8bHazir): ?>
        <div class="cf2-grup" data-cf-grup="yarim">
            <div class="cf2-grup-bas"><?= $cfIk('yarim') ?><span>Yarım Yevmiye</span></div>
            <div class="cf2-izgara">
                <label class="cf2-alan">
                    <span class="cf2-etiket"><?= $cfIk('para') ?>Yarım Mesai Ücreti <b class="cf2-zorunlu">*</b></span>
                    <span class="cf2-girdi"><span class="cf2-girdi-ik"><?= $cfIk('para') ?></span>
                    <input type="text" name="half_day_rate" required inputmode="decimal" placeholder="ör. 900"></span>
                </label>
                <?php if ($saatHazir): ?>
                <label class="cf2-alan">
                    <span class="cf2-etiket"><?= $cfIk('saat') ?>Yarım Yevmiye Saati <small class="cf2-ek">(bilgi)</small></span>
                    <span class="cf2-girdi"><span class="cf2-girdi-ik"><?= $cfIk('saat') ?></span>
                    <input type="text" name="half_day_saat" inputmode="decimal" maxlength="5" autocomplete="off" placeholder="ör. 5" value="<?= h($saatForm['half_day_saat']) ?>"></span>
                </label>
                <?php endif; ?>
            </div>
            <?php if ($saatHazir): ?><p class="cf2-not">Yarım saati yalnız bilgidir: Tam saatinin altındaki mesailerde karar yine muhasebede (otomatik Yarım yok).</p><?php endif; ?>
        </div>
        <div class="cf2-grup" data-cf-grup="fm">
            <div class="cf2-grup-bas"><?= $cfIk('saat+') ?><span>Fazla Mesai</span></div>
            <div class="cf2-izgara cf2-izgara--3">
                <label class="cf2-alan">
                    <span class="cf2-etiket"><?= $cfIk('saat') ?>Fazla Mesai Tipi <b class="cf2-zorunlu">*</b></span>
                    <span class="cf2-girdi"><span class="cf2-girdi-ik"><?= $cfIk('saat') ?></span>
                    <select name="overtime_mode" required>
                        <option value="hourly">Saatlik</option>
                        <option value="fixed">Sabit Toplam</option>
                    </select></span>
                </label>
                <label class="cf2-alan">
                    <span class="cf2-etiket"><?= $cfIk('para') ?>Fazla Mesai Ücreti <b class="cf2-zorunlu">*</b></span>
                    <span class="cf2-girdi"><span class="cf2-girdi-ik"><?= $cfIk('para') ?></span>
                    <input type="text" name="overtime_rate" required inputmode="decimal" placeholder="ör. 200"></span>
                </label>
                <?php if ($saatHazir): ?>
                <label class="cf2-alan">
                    <span class="cf2-etiket"><?= $cfIk('saat') ?>FM Başlangıç Saati</span>
                    <span class="cf2-girdi"><span class="cf2-girdi-ik"><?= $cfIk('saat') ?></span>
                    <input type="text" name="overtime_start_saat" inputmode="decimal" maxlength="5" autocomplete="off" placeholder="boş = Tam saati" value="<?= h($saatForm['overtime_start_saat']) ?>"></span>
                </label>
                <?php endif; ?>
            </div>
            <p class="cf2-bilgi cf2-bilgi--mavi"><?= $cfIk('bilgi') ?><span>Saatlik: 15 dk tolerans sonrası başlayan her saat yukarı yuvarlanır. Sabit: onaylanan FM için bir kez uygulanır.</span></p>
        </div>
        <?php if ($saatHazir): ?>
        <div class="cf2-grup" data-cf-grup="cift">
            <div class="cf2-grup-bas"><?= $cfIk('gunes') ?><span>Çift Yevmiye <small class="cf2-ek">(isteğe bağlı)</small></span></div>
            <div class="cf2-izgara">
                <label class="cf2-alan">
                    <span class="cf2-etiket"><?= $cfIk('para') ?>Çift Yevmiye Ücreti</span>
                    <span class="cf2-girdi"><span class="cf2-girdi-ik"><?= $cfIk('para') ?></span>
                    <input type="text" name="double_day_rate" inputmode="decimal" placeholder="ör. 2000" value="<?= h($saatForm['double_day_rate']) ?>"></span>
                </label>
                <label class="cf2-alan">
                    <span class="cf2-etiket"><?= $cfIk('saat') ?>Çift Yevmiye Eşik Saati</span>
                    <span class="cf2-girdi"><span class="cf2-girdi-ik"><?= $cfIk('saat') ?></span>
                    <input type="text" name="double_day_saat" inputmode="decimal" maxlength="5" autocomplete="off" placeholder="ör. 12" value="<?= h($saatForm['double_day_saat']) ?>"></span>
                </label>
            </div>
            <p class="cf2-bilgi cf2-bilgi--mor"><?= $cfIk('bilgi') ?><span>Eşik saati kadar çalışılıp FM onayı bunu kapsıyorsa Tam ücretin YERİNE çift ücret ödenir; eşikten sonraki süre FM olur (Tam ile çift arası ayrıca ödenmez). Sabit FM tipinde çift gününe FM eklenmez. İkisi birlikte girilir ya da boş bırakılır.</span></p>
        </div>
        <?php else: ?>
        <p class="cf2-bilgi cf2-bilgi--mavi" id="cfSaatKurulum"><?= $cfIk('bilgi') ?><span>Tam / Yarım / FM saatleri ve Çift Yevmiye alanları için yönetici <a href="migrate.php">migrate.php</a>'den "Fiyat Dönemi Saatleri" kolonlarını kurmalıdır. Kurulana kadar Tam eşiği mesainin normal süresidir.</span></p>
        <?php endif; ?>
        <?php endif; ?>
        <div class="cf2-izgara">
            <label class="cf2-alan">
                <span class="cf2-etiket"><?= $cfIk('takvim') ?>Geçerlilik Başlangıcı <b class="cf2-zorunlu">*</b></span>
                <span class="cf2-girdi"><span class="cf2-girdi-ik"><?= $cfIk('takvim') ?></span>
                <input type="date" name="valid_from" required value="<?= h(date('Y-m-d')) ?>"></span>
            </label>
        </div>
        <p class="cf2-bilgi cf2-bilgi--mor"><?= $cfIk('bilgi') ?><span>Yeni dönem eklenince önceki açık fiyat dönemi bir gün öncesinde kapanır. Eski fiyatlar silinmez.</span></p>
        <div class="cf2-eylem">
            <button type="submit" class="btn btn-primary cf2-btn">+ Fiyat Dönemi Ekle</button>
        </div>
    </form>
</section>

<section class="card cf2-kart" id="cfFiyatGecmisi">
    <header class="cf2-kart-bas">
        <span class="cf2-tile cf2-tile--turuncu"><?= $cfIk('gecmis') ?></span>
        <div class="cf2-kart-bas-metin">
            <h2>Fiyat Geçmişi</h2>
            <p class="cf2-alt">İşçi tipi bazında tanımlı tüm fiyat dönemleri; eski dönemler silinmez.</p>
        </div>
        <?php if (empty($oranlar)): ?>
        <p class="cf2-uyari-hap"><?= $cfIk('uyari') ?><span>Bu çavuş için henüz bir fiyat tanımlanmadı.</span></p>
        <?php endif; ?>
    </header>
<?php if (!empty($oranlar)): ?>
<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr><th>İşçi Tipi</th><th><?= $faz8bHazir ? 'Tam' : 'Günlük Ücret' ?></th><?php if ($faz8bHazir): ?><th>Yarım</th><th>Fazla Mesai</th><?php endif; ?><?php if ($saatHazir): ?><th>Saatler</th><th>Çift Yevmiye</th><?php endif; ?><th>Geçerlilik</th><th>Durum</th></tr></thead>
<tbody>
<?php foreach ($oranlar as $o): ?>
<tr>
    <td class="pdks-row-name"><?= h($o['worker_type_name']) ?></td>
    <td><strong><?= h(number_format((float)$o['daily_rate'], 2, ',', '.')) ?> <?= h($o['currency']) ?></strong></td>
    <?php if ($faz8bHazir): ?>
    <td><?= ($o['half_day_rate'] ?? null) !== null ? h(number_format((float)$o['half_day_rate'], 2, ',', '.') . ' ' . $o['currency']) : '—' ?></td>
    <td><?php if (($o['overtime_rate'] ?? null) !== null): ?><?= h(number_format((float)$o['overtime_rate'], 2, ',', '.') . ' ' . $o['currency']) ?> · <?= h(($o['overtime_mode'] ?? '') === 'fixed' ? 'Sabit' : 'Saatlik') ?><?php else: ?>—<?php endif; ?></td>
    <?php endif; ?>
    <?php if ($saatHazir): $oSaat = pdks_faz8b_oran_saat_ozeti($o); ?>
    <td class="cf2-saatler"><?= $oSaat['saatler'] !== '' ? h($oSaat['saatler']) : '<span class="muted">mesai normal süresi</span>' ?></td>
    <td><?= $oSaat['cift'] !== '' ? h($oSaat['cift']) : '—' ?></td>
    <?php endif; ?>
    <td class="muted"><?= h(date('d.m.Y', strtotime($o['valid_from']))) ?> → <?= $o['valid_to'] ? h(date('d.m.Y', strtotime($o['valid_to']))) : 'devam ediyor' ?></td>
    <td><span class="pdks-badge <?= $o['is_active'] ? 'pdks-badge-aktif' : 'pdks-badge-pasif' ?>"><?= $o['is_active'] ? 'Aktif' : 'Pasif' ?></span></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>

<div class="pdks-cards mobile-only">
<?php foreach ($oranlar as $o): ?>
<div class="pdks-card-item">
    <div class="pdks-card-top"><div class="pdks-card-meta"><div class="pdks-row-name"><?= h($o['worker_type_name']) ?></div><div class="pdks-row-sub"><?= h(date('d.m.Y', strtotime($o['valid_from']))) ?> → <?= $o['valid_to'] ? h(date('d.m.Y', strtotime($o['valid_to']))) : 'devam ediyor' ?></div></div><span class="pdks-badge <?= $o['is_active'] ? 'pdks-badge-aktif' : 'pdks-badge-pasif' ?>"><?= $o['is_active'] ? 'Aktif' : 'Pasif' ?></span></div>
    <div class="pdks-row-sub"><?= $faz8bHazir ? 'Tam' : 'Günlük' ?>: <strong><?= h(number_format((float)$o['daily_rate'],2,',','.')) ?> <?= h($o['currency']) ?></strong></div>
    <?php if ($faz8bHazir): ?><div class="pdks-row-sub">Yarım: <?= ($o['half_day_rate'] ?? null) !== null ? h(number_format((float)$o['half_day_rate'],2,',','.') . ' ' . $o['currency']) : '—' ?></div><div class="pdks-row-sub">FM: <?= ($o['overtime_rate'] ?? null) !== null ? h(number_format((float)$o['overtime_rate'],2,',','.') . ' ' . $o['currency'] . ' · ' . (($o['overtime_mode'] ?? '') === 'fixed' ? 'Sabit' : 'Saatlik')) : '—' ?></div><?php endif; ?>
    <?php if ($saatHazir): $oSaat = pdks_faz8b_oran_saat_ozeti($o); ?><div class="pdks-row-sub">Saatler: <?= $oSaat['saatler'] !== '' ? h($oSaat['saatler']) : 'mesai normal süresi' ?></div><?php if ($oSaat['cift'] !== ''): ?><div class="pdks-row-sub">Çift: <?= h($oSaat['cift']) ?></div><?php endif; ?><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>

<?php if (!$cavusUcretHazir): ?>
<div class="flash flash-warning">Çavuş Ücreti tablosu henüz oluşturulamadı. Yönetici <a href="migrate.php">migrate.php</a>'den oluşturabilir.</div>
<?php else: ?>

<section class="card cf2-kart" id="cfCavusUcreti">
    <header class="cf2-kart-bas">
        <span class="cf2-tile cf2-tile--mor"><?= $cfIk('disli') ?></span>
        <div class="cf2-kart-bas-metin">
            <h2>Çavuş Ücreti — <?= h($seciliCavus['name']) ?></h2>
            <p class="cf2-alt">Çavuşun kendi ücretinin nasıl hesaplanacağını ve ücret dönemlerini yönetin.</p>
        </div>
    </header>

    <?php if ($cavusBHazir): ?>
    <div class="cf2-blok cf2-yontem">
        <div class="cf2-yontem-ust">
            <span class="cf2-blok-baslik">Hesaplama Yöntemi</span>
            <span class="cf2-hap <?= $cavusYontem === 'B' ? 'cf2-hap--b' : 'cf2-hap--a' ?>" id="cfYontemHap"><?= h($cavusYontemEtiket) ?></span>
        </div>
        <form method="post" class="cf2-yontem-form" id="cfYontemForm">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="foreman_id" value="<?= (int)$seciliCavus['id'] ?>">
            <input type="hidden" name="form" value="cavus_yontem">
            <div class="cf2-secenekler">
                <label class="cf2-secenek">
                    <input type="radio" name="cavus_yontem" value="A" <?= $cavusYontemSecili !== 'B' ? 'checked' : '' ?>>
                    <span class="cf2-secenek-metin"><strong>Yöntem A — Günlük sabit ücret.</strong> Çavuşun işçi çalıştırdığı her gün hakedişe bir "Çavuş Ücreti" satırı eklenir.</span>
                </label>
                <label class="cf2-secenek">
                    <input type="radio" name="cavus_yontem" value="B" <?= $cavusYontemSecili === 'B' ? 'checked' : '' ?>>
                    <span class="cf2-secenek-metin"><strong>Yöntem B — <span data-cf-birim-yaz><?= h($cavusBirimForm) ?></span> kişi-gün = 1 hakediş.</strong> Kesinleşmiş günlerdeki kişi-gün toplamı her <span data-cf-birim-yaz><?= h($cavusBirimForm) ?></span> kişi-günde 1 hakediş kazandırır; çavuşa yapılan para verişi Çavuş Cari ekranında kaydedildiğinde dönem otomatik kapanır, kalan kişi-gün devreder.</span>
                </label>
            </div>
            <div class="cf2-birim" id="cfBirimAlan">
                <label class="cf2-alan">
                    <span class="cf2-etiket"><?= $cfIk('kare') ?>Kaç kişi-gün = 1 hakediş <b class="cf2-zorunlu">*</b></span>
                    <span class="cf2-girdi"><span class="cf2-girdi-ik"><?= $cfIk('kare') ?></span>
                    <input type="number" name="cavus_birim" id="cfBirim" min="<?= (int)PDKS_FAZ8B_CAVUS_B_BIRIM_MIN ?>" max="<?= (int)PDKS_FAZ8B_CAVUS_B_BIRIM_MAX ?>" step="1" inputmode="numeric" value="<?= h($cavusBirimForm) ?>"></span>
                </label>
                <p class="cf2-not">1–1000 arası tam sayı (varsayılan <?= (int)PDKS_FAZ8B_CAVUS_B_BIRIM ?>). Değişiklik yalnız SONRAKİ kapanışlara uygulanır; devreden kişi-gün yeni birime bölünür.</p>
            </div>
            <div class="cf2-eylem cf2-eylem--sol">
                <button type="submit" class="btn cf2-btn cf2-btn--cizgi">Yöntemi Kaydet</button>
                <?php if ($cavusYontemGecmisi): $sonY = $cavusYontemGecmisi[0]; ?>
                <span class="cf2-son">Son değişiklik: <?= h(date('d.m.Y H:i', strtotime((string)$sonY['effective_at']))) ?> — <?= h(pdks_faz8b_cavus_ucret_yontem_etiketi((string)$sonY['method'], (string)$sonY['method'] === 'B' ? pdks_faz8b_cavus_ucret_b_satir_birimi($sonY) : null)) ?></span>
                <?php endif; ?>
            </div>
            <?php if (count($cavusYontemGecmisi) > 1): ?>
            <p class="cf2-not">Önceki değişiklikler:
                <?php foreach (array_slice($cavusYontemGecmisi, 1, 4) as $i => $y): ?>
                <?= $i > 0 ? ' · ' : '' ?><?= h(date('d.m.Y H:i', strtotime((string)$y['effective_at']))) ?> → <?= h((string)$y['method']) ?><?= (string)$y['method'] === 'B' ? ' (' . (int)pdks_faz8b_cavus_ucret_b_satir_birimi($y) . ')' : '' ?>
                <?php endforeach; ?>
            </p>
            <?php endif; ?>
        </form>
        <p class="cf2-bilgi cf2-bilgi--mavi"><?= $cfIk('bilgi') ?><span>Yöntem değişikliği geriye dönük değildir: değişiklikten önce kesinleşmiş günler eski yöntemle kalır. B'den A'ya geçişte bekleyen kişi-günler ve devir silinmez. Yöntem B'de birim (kaç kişi-gün = 1 hakediş) her kapanışta o anki değerle donar.</span></p>
    </div>
    <?php elseif ($cavusUcretHazir): ?>
    <div class="flash flash-warning" style="margin:0 0 14px">Yöntem B tabloları henüz oluşturulamadı — yönetici migrate.php'den oluşturabilir.</div>
    <?php endif; ?>

    <div class="cf2-blok">
        <div class="cf2-yontem-ust">
            <span class="cf2-blok-baslik"><?= $cavusYontem === 'B' ? 'Hakediş Birim Ücreti (' . (int)$cavusBirim . ' kişi-gün)' : 'Günlük Ücret' ?></span>
        </div>
        <p class="cf2-not"><?php if ($cavusYontem === 'B'): ?>Yöntem B seçili: aşağıdaki ücret artık GÜNLÜK işlenmez — her <?= (int)$cavusBirim ?> kişi-günlük hakedişin BİRİM ÜCRETİDİR. Dönem kapanışında, çavuşa para verilen günün tarihinde geçerli ücret uygulanır. Ücret tanımsızsa para verişi yine kaydedilir ama dönem kapanışı yapılmaz.<?php else: ?>Bu çavuşun kendi günlük çalışma ücreti. Zorunlu
           değil — boş bırakılırsa hakedişe hiçbir satır eklenmez.<?php endif; ?></p>
        <form method="post" class="cf2-form">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="foreman_id" value="<?= (int)$seciliCavus['id'] ?>">
            <input type="hidden" name="form" value="cavus_ucret">
            <div class="cf2-izgara cf2-izgara--3">
                <label class="cf2-alan">
                    <span class="cf2-etiket"><?= $cfIk('para') ?><?= $cavusYontem === 'B' ? 'Hakediş Birim Ücreti (' . (int)$cavusBirim . ' kişi-gün)' : 'Günlük Ücret' ?> <b class="cf2-zorunlu">*</b></span>
                    <span class="cf2-girdi"><span class="cf2-girdi-ik"><?= $cfIk('para') ?></span>
                    <input type="text" name="cavus_daily_rate" required inputmode="decimal" placeholder="ör. 1500 veya 1500,50"></span>
                </label>
                <label class="cf2-alan">
                    <span class="cf2-etiket"><?= $cfIk('para') ?>Para Birimi <b class="cf2-zorunlu">*</b></span>
                    <span class="cf2-girdi"><span class="cf2-girdi-ik"><?= $cfIk('para') ?></span>
                    <select name="cavus_currency" required>
                        <?php foreach ($paraBirimleri as $kod => $etiket): ?>
                        <option value="<?= h($kod) ?>"><?= h($etiket) ?></option>
                        <?php endforeach; ?>
                    </select></span>
                </label>
                <label class="cf2-alan">
                    <span class="cf2-etiket"><?= $cfIk('takvim') ?>Geçerlilik Başlangıcı <b class="cf2-zorunlu">*</b></span>
                    <span class="cf2-girdi"><span class="cf2-girdi-ik"><?= $cfIk('takvim') ?></span>
                    <input type="date" name="cavus_valid_from" required value="<?= h(date('Y-m-d')) ?>"></span>
                </label>
            </div>
            <div class="cf2-eylem">
                <button type="submit" class="btn btn-primary cf2-btn"><?= $cavusYontem === 'B' ? '+ Birim Ücret Dönemi Ekle' : '+ Çavuş Ücreti Dönemi Ekle' ?></button>
            </div>
        </form>
    </div>

    <div class="cf2-blok cf2-blok--gecmis">
        <div class="cf2-yontem-ust">
            <span class="cf2-blok-baslik">Çavuş Ücreti Geçmişi</span>
        </div>
<?php if (empty($cavusUcretGecmisi)): ?>
        <p class="cf2-uyari-hap cf2-uyari-hap--blok"><?= $cfIk('uyari') ?><span><?= $cavusYontem === 'B' ? 'Bu çavuş için henüz bir birim ücret tanımlanmadı.' : 'Bu çavuş için henüz bir günlük ücret tanımlanmadı.' ?></span></p>
<?php else: ?>
<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr><th><?= $cavusYontem === 'B' ? 'Birim Ücret (' . (int)$cavusBirim . ' kişi-gün)' : 'Ücret' ?></th><th>Geçerlilik</th><th>Durum</th></tr></thead>
<tbody>
<?php foreach ($cavusUcretGecmisi as $cu): ?>
<tr>
    <td><strong><?= h(number_format((float)$cu['daily_rate'], 2, ',', '.')) ?> <?= h($cu['currency']) ?></strong></td>
    <td class="muted"><?= h(date('d.m.Y', strtotime($cu['valid_from']))) ?> → <?= $cu['valid_to'] ? h(date('d.m.Y', strtotime($cu['valid_to']))) : 'devam ediyor' ?></td>
    <td><span class="pdks-badge <?= $cu['is_active'] ? 'pdks-badge-aktif' : 'pdks-badge-pasif' ?>"><?= $cu['is_active'] ? 'Aktif' : 'Pasif' ?></span></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>

<div class="pdks-cards mobile-only">
<?php foreach ($cavusUcretGecmisi as $cu): ?>
<div class="pdks-card-item">
    <div class="pdks-card-top"><div class="pdks-card-meta"><div class="pdks-row-name"><?= h(number_format((float)$cu['daily_rate'],2,',','.')) ?> <?= h($cu['currency']) ?></div><div class="pdks-row-sub"><?= h(date('d.m.Y', strtotime($cu['valid_from']))) ?> → <?= $cu['valid_to'] ? h(date('d.m.Y', strtotime($cu['valid_to']))) : 'devam ediyor' ?></div></div><span class="pdks-badge <?= $cu['is_active'] ? 'pdks-badge-aktif' : 'pdks-badge-pasif' ?>"><?= $cu['is_active'] ? 'Aktif' : 'Pasif' ?></span></div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($servisHazir): $svSon = $servisGecmisi[0] ?? null; ?>
<section class="card cf2-kart cf2-servis" id="cfServisUcreti">
    <header class="cf2-kart-bas">
        <span class="cf2-tile cf2-tile--turuncu"><?= $cfIk('servis') ?></span>
        <div class="cf2-kart-bas-metin">
            <h2>🚌 Servis Ücreti — <?= h($seciliCavus['name']) ?></h2>
            <p class="cf2-alt">Mesai Detayı'nda eklenen BÜYÜK / KÜÇÜK servislerin birim fiyatı. Mesai tarihinde geçerli fiyat hakedişe adet × fiyat olarak yansır.</p>
        </div>
        <?php if (!$servisGecmisi): ?>
        <p class="cf2-uyari-hap"><?= $cfIk('uyari') ?><span>Servis fiyatı tanımlı değil — tanımlanmadan mesaiye servis eklenemez.</span></p>
        <?php endif; ?>
    </header>
    <form method="post" class="cf2-form">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="foreman_id" value="<?= (int)$seciliCavus['id'] ?>">
        <input type="hidden" name="form" value="servis_ucret">
        <div class="cf2-izgara">
            <label class="cf2-alan">
                <span class="cf2-etiket"><?= $cfIk('servis') ?>Büyük Servis Fiyatı</span>
                <span class="cf2-girdi"><span class="cf2-girdi-ik"><?= $cfIk('para') ?></span>
                <input type="text" name="servis_buyuk" id="cfServisBuyuk" inputmode="decimal" placeholder="ör. 1500 veya 1500,50" value="<?= $servisHata ? h(substr(trim((string)($_POST['servis_buyuk'] ?? '')), 0, 20)) : '' ?>"></span>
            </label>
            <label class="cf2-alan">
                <span class="cf2-etiket"><?= $cfIk('servis') ?>Küçük Servis Fiyatı</span>
                <span class="cf2-girdi"><span class="cf2-girdi-ik"><?= $cfIk('para') ?></span>
                <input type="text" name="servis_kucuk" id="cfServisKucuk" inputmode="decimal" placeholder="ör. 800" value="<?= $servisHata ? h(substr(trim((string)($_POST['servis_kucuk'] ?? '')), 0, 20)) : '' ?>"></span>
            </label>
            <label class="cf2-alan">
                <span class="cf2-etiket"><?= $cfIk('para') ?>Para Birimi <b class="cf2-zorunlu">*</b></span>
                <span class="cf2-girdi"><span class="cf2-girdi-ik"><?= $cfIk('para') ?></span>
                <select name="servis_currency" required>
                    <?php foreach ($paraBirimleri as $kod => $etiket): ?>
                    <option value="<?= h($kod) ?>"<?= $kod === 'TRY' ? ' selected' : '' ?>><?= h($etiket) ?></option>
                    <?php endforeach; ?>
                </select></span>
            </label>
            <label class="cf2-alan">
                <span class="cf2-etiket"><?= $cfIk('takvim') ?>Geçerlilik Başlangıcı <b class="cf2-zorunlu">*</b></span>
                <span class="cf2-girdi"><span class="cf2-girdi-ik"><?= $cfIk('takvim') ?></span>
                <input type="date" name="servis_valid_from" required value="<?= h(date('Y-m-d')) ?>"></span>
            </label>
        </div>
        <p class="cf2-bilgi cf2-bilgi--mavi"><?= $cfIk('bilgi') ?><span>En az biri girilmelidir; boş bırakılan türün fiyatı yoktur (o tür servis eklenemez). Yeni dönem eklenince önceki dönem bir gün öncesinde kapanır, eski fiyatlar silinmez. Para birimi işçi fiyatlarıyla aynı olmalıdır (farklıysa hakediş hesaplanmaz).</span></p>
        <div class="cf2-eylem">
            <button type="submit" class="btn btn-primary cf2-btn">+ Servis Fiyatı Dönemi Ekle</button>
        </div>
    </form>

    <div class="cf2-blok cf2-blok--gecmis">
        <div class="cf2-yontem-ust"><span class="cf2-blok-baslik">Servis Ücreti Geçmişi</span></div>
<?php if ($servisGecmisi): ?>
<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr><th>Büyük</th><th>Küçük</th><th>Geçerlilik</th><th>Durum</th></tr></thead>
<tbody>
<?php foreach ($servisGecmisi as $sv): ?>
<tr>
    <td><strong><?= $sv['big_rate'] !== null ? h(number_format((float)$sv['big_rate'], 2, ',', '.') . ' ' . $sv['currency']) : '—' ?></strong></td>
    <td><strong><?= $sv['small_rate'] !== null ? h(number_format((float)$sv['small_rate'], 2, ',', '.') . ' ' . $sv['currency']) : '—' ?></strong></td>
    <td class="muted"><?= h(date('d.m.Y', strtotime($sv['valid_from']))) ?> → <?= $sv['valid_to'] ? h(date('d.m.Y', strtotime($sv['valid_to']))) : 'devam ediyor' ?></td>
    <td><span class="pdks-badge <?= $sv['is_active'] ? 'pdks-badge-aktif' : 'pdks-badge-pasif' ?>"><?= $sv['is_active'] ? 'Aktif' : 'Pasif' ?></span></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<div class="pdks-cards mobile-only">
<?php foreach ($servisGecmisi as $sv): ?>
<div class="pdks-card-item">
    <div class="pdks-card-top"><div class="pdks-card-meta"><div class="pdks-row-name">Büyük <?= $sv['big_rate'] !== null ? h(number_format((float)$sv['big_rate'], 2, ',', '.')) : '—' ?> · Küçük <?= $sv['small_rate'] !== null ? h(number_format((float)$sv['small_rate'], 2, ',', '.')) : '—' ?> <?= h($sv['currency']) ?></div><div class="pdks-row-sub"><?= h(date('d.m.Y', strtotime($sv['valid_from']))) ?> → <?= $sv['valid_to'] ? h(date('d.m.Y', strtotime($sv['valid_to']))) : 'devam ediyor' ?></div></div><span class="pdks-badge <?= $sv['is_active'] ? 'pdks-badge-aktif' : 'pdks-badge-pasif' ?>"><?= $sv['is_active'] ? 'Aktif' : 'Pasif' ?></span></div>
</div>
<?php endforeach; ?>
</div>
<?php else: ?>
        <p class="cf2-uyari-hap cf2-uyari-hap--blok"><?= $cfIk('uyari') ?><span>Bu çavuş için henüz servis fiyatı tanımlanmadı.</span></p>
<?php endif; ?>
    </div>
</section>
<?php elseif (function_exists('is_admin') && is_admin()): ?>
<p class="cf2-bilgi cf2-bilgi--mavi" id="cfServisKurulum"><?= $cfIk('bilgi') ?><span>🚌 Servis Ücreti için yönetici <a href="migrate.php">migrate.php</a>'den "Servis Ücreti" tablolarını kurmalıdır.</span></p>
<?php endif; ?>

<?php endif; ?>

</div>

<script>
(function () {
    // v297: birim kutusu YALNIZ Yöntem B seçiliyken görünür (JS kapalıyken
    // her zaman görünür kalır; sunucu A'da birimi yok sayar).
    var form = document.getElementById('cfYontemForm');
    if (!form) return;
    var alan = document.getElementById('cfBirimAlan');
    var kutu = document.getElementById('cfBirim');
    function guncelle() {
        var b = form.querySelector('input[name="cavus_yontem"][value="B"]');
        var acik = !!(b && b.checked);
        if (alan) alan.hidden = !acik;
    }
    form.addEventListener('change', guncelle);
    if (kutu) kutu.addEventListener('input', function () {
        var v = String(kutu.value || '').trim();
        if (!/^\d{1,4}$/.test(v)) return;
        form.querySelectorAll('[data-cf-birim-yaz]').forEach(function (s) { s.textContent = v; });
    });
    guncelle();
})();
</script>

<?php pdks_liste_ui_js(); ?>
<?php render_footer(); ?>
