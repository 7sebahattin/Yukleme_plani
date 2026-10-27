<?php
// =========================================================
// gunluk_fis_yukle.php — Fiş Excel'i Yükle (Günlük İşçi, v446)
//
// Kâğıt "İŞLETME İŞÇİ FİŞİ"nin dış bir yapay zekâyla şablona dökülmüş Excel'ini okur, önizletir ve fiş başına
// kartsız mesai kayıtlarını (+ servis) yazar. YALNIZ yönetici. OCR/AI uygulamanın İÇİNDE YOK; dosya diske KAYDEDİLMEZ.
// Tüm mantık config/pdks_fis.php'de; yazma yolu Faz 8J toplu_ekle (ikinci yazma yolu YOK).
//
// Akış (PRG): GET ?sablon=1 (indir) · POST eylem=onizle (dosya → normalize → oturum token'ı → ?onizle=TOKEN) ·
// GET ?onizle=TOKEN (güncel DB'ye göre önizleme) · POST eylem=yaz (token + onay kutuları → ?sonuc=TOKEN) ·
// POST eylem=iptal. Kova/saat/sayı verisi İSTEMCİDEN GELMEZ; yazarken fişler oturumdan alınır ve yeniden değerlendirilir.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/pdks_faz8j.php';
require_once __DIR__ . '/config/pdks_fis.php';
$auth_user = require_login();
require_pdks_gunluk('daily_reports');
if (!function_exists('is_admin') || !is_admin()) forbidden('Fiş Excel yüklemesi yalnız yönetici içindir.');
$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_faz8b_sayfa_kapisi($pdo);
$uid = (int)$auth_user['id'];
$depo = function_exists('active_depot') ? (string)(active_depot() ?? '') : '';
$self = 'gunluk_fis_yukle.php';

// ── Şablon indirme (GET; çıktıdan ÖNCE) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['sablon'] ?? '') === '1') {
    export_audit('gunluk_fis_yukle', 'Fiş şablonu', 'xlsx', 0);
    xlsx_gonder(pdks_fis_sablon_uret($pdo), 'Fis_Excel_Sablonu_' . date('Ymd') . '.xlsx');
}

// ── POST (CSRF'ten ÖNCE yalnız post_max_size koruması: aşılırsa PHP $_POST/$_FILES'ı boşaltır) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $icerikUzunluk = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = xlsx_bayt((string)ini_get('post_max_size'));
    if (($postMax > 0 && $icerikUzunluk > $postMax) || ($icerikUzunluk > 0 && !$_POST && !$_FILES)) {
        set_flash('error', 'Dosya çok büyük (en çok ' . (int)(PDKS_FIS_MAX_BAYT / 1048576) . ' MB). Daha küçük bir dosya seçin.');
        header('Location: ' . $self);
        exit;
    }
    csrf_check($_POST['csrf'] ?? null);
    $eylem = (string)($_POST['eylem'] ?? '');

    if ($eylem === 'onizle') {
        if ($depo === '') { set_flash('error', 'Önce bir depo seçmelisiniz.'); header('Location: ' . $self); exit; }
        $d = pdks_fis_dosya_dogrula($_FILES['dosya'] ?? []);
        if (!$d['ok']) { set_flash('error', (string)$d['hata']); header('Location: ' . $self); exit; }
        $o = pdks_fis_oku((string)$d['yol']);
        if (empty($o['ok'])) { set_flash('error', (string)($o['hata'] ?? 'Dosya okunamadı.')); header('Location: ' . $self); exit; }
        $n = pdks_fis_normalize($o, pdks_fis_cavuslar($pdo));
        if (!$n['fisler']) { set_flash('error', implode(' ', $n['genel_hatalar'] ?: ['Dosyada fiş bulunamadı.'])); header('Location: ' . $self); exit; }
        $fmTip = pdks_fis_secenek(['fm_tip' => $_POST['fm_tip'] ?? 'tumu'])['fm_tip'];
        $token = pdks_fis_token_olustur([
            'sha' => (string)$d['sha256'], 'dosya_ad' => pdks_fis_metin_duz(basename(str_replace('\\', '/', (string)($_FILES['dosya']['name'] ?? 'dosya.xlsx'))), 120),
            'fisler' => $n['fisler'], 'secenek' => ['fm_tip' => $fmTip], 'hashler' => [], 'dosya_uyarilar' => $o['uyarilar'],
        ]);
        export_audit('gunluk_fis_yukle', 'Fiş Excel önizleme', 'xlsx', count($n['fisler']), ['sha256' => substr((string)$d['sha256'], 0, 16)]);
        session_write_close();   // yönlendirmeden ÖNCE: sonraki GET oturum yazımını beklemeden token'ı görsün
        header('Location: ' . $self . '?onizle=' . $token);
        exit;
    }

    if ($eylem === 'iptal') {
        pdks_fis_token_sil((string)($_POST['token'] ?? ''));
        set_flash('success', 'Önizleme kapatıldı; hiçbir kayıt yazılmadı.');
        header('Location: ' . $self);
        exit;
    }

    if ($eylem === 'yaz') {
        $token = (string)($_POST['token'] ?? '');
        $yuk = pdks_fis_token_al($token, $tokenNeden);
        if ($yuk === null) { error_log('[fis_yukle] yaz: token ' . ($tokenNeden ?? '?')); set_flash('error', pdks_fis_token_hata_metni($tokenNeden)); header('Location: ' . $self); exit; }
        $secenek = pdks_fis_secenek([
            'fm_tip' => $yuk['secenek']['fm_tip'] ?? 'tumu', 'onay_yarim' => !empty($_POST['onay_yarim']), 'onay_fm' => !empty($_POST['onay_fm']),
            'ek_yaz' => !empty($_POST['ek_yaz']), 'isletme_onay' => !empty($_POST['isletme_onay']),
        ]);
        // Seçim: masaüstü tablo (secilen[]) ve mobil kart (secilen_m[]) KESİŞİMİ — biri ayrışırsa fiş YAZILMAZ (güvenli yön).
        $a = array_map('intval', (array)($_POST['secilen'] ?? [])); $b = array_map('intval', (array)($_POST['secilen_m'] ?? []));
        $bilinen = array_map(fn($f) => (int)$f['satir'], $yuk['fisler']);
        $secilen = array_values(array_intersect($a, $b, $bilinen));
        if (!$secilen) { set_flash('error', 'Yazılacak fiş seçilmedi.'); header('Location: ' . $self . '?onizle=' . $token); exit; }
        $sonuc = pdks_fis_yaz($pdo, $yuk['fisler'], $secenek, $uid, (string)$yuk['sha'], $secilen, (array)($yuk['hashler'] ?? []));
        pdks_fis_token_sil($token);
        $sk = bin2hex(random_bytes(16));
        $skKayit = ['t' => time(), 'uid' => $uid, 'depo' => $depo, 'sonuc' => $sonuc, 'dosya_ad' => (string)$yuk['dosya_ad'], 'sha' => (string)$yuk['sha']];
        $_SESSION['fis_sonuc'] = [$sk => $skKayit];
        pdks_fis_kv_yaz(PDKS_FIS_KV_SONUC, $sk, $skKayit);   // kayıtlar YAZILDI; sonuç ekranı oturum kaybında da gösterilsin
        session_write_close();
        header('Location: ' . $self . '?sonuc=' . $sk);
        exit;
    }
    set_flash('error', 'Geçersiz istek.');
    header('Location: ' . $self);
    exit;
}

// ── GET: form / önizleme / sonuç ─────────────────────────────────────
$model = null;
$onizleToken = (string)($_GET['onizle'] ?? '');
$sonucToken = (string)($_GET['sonuc'] ?? '');
$varsayilan = ['onay_yarim' => true, 'onay_fm' => true, 'ek_yaz' => false, 'isletme_onay' => false];
if ($onizleToken !== '') {
    $yuk = pdks_fis_token_al($onizleToken, $tokenNeden);
    if ($yuk === null) { error_log('[fis_yukle] onizle: token ' . ($tokenNeden ?? '?')); set_flash('error', pdks_fis_token_hata_metni($tokenNeden)); header('Location: ' . $self); exit; }
    $sec = pdks_fis_secenek($yuk['secenek'] + $varsayilan);
    $on = pdks_fis_onizle($pdo, $yuk['fisler'], $sec, $uid);
    // Yazma anında karşılaştırılacak plan hash'i = kullanıcının ŞU AN gördüğü önizleme
    $hashler = [];
    foreach ($on['fisler'] as $m) if ($m['hash'] !== '') $hashler[(int)$m['satir']] = $m['hash'];
    pdks_fis_token_hashler($onizleToken, $hashler);
    $model = ['mod' => 'onizle', 'token' => $onizleToken, 'onizle' => $on, 'dosya_ad' => $yuk['dosya_ad'], 'sha' => $yuk['sha'], 'dosya_uyarilar' => $yuk['dosya_uyarilar'] ?? [], 'varsayilan' => $varsayilan];
} elseif ($sonucToken !== '') {
    $sk = $_SESSION['fis_sonuc'][$sonucToken] ?? pdks_fis_kv_oku(PDKS_FIS_KV_SONUC, $sonucToken);
    if (!preg_match('/^[a-f0-9]{32}$/D', $sonucToken) || !is_array($sk) || (int)$sk['uid'] !== $uid || (string)$sk['depo'] !== $depo || time() - (int)$sk['t'] > PDKS_FIS_TOKEN_TTL) {
        set_flash('error', 'Sonuç süresi doldu.'); header('Location: ' . $self); exit;
    }
    $model = ['mod' => 'sonuc', 'sonuc' => $sk['sonuc'], 'dosya_ad' => $sk['dosya_ad'], 'sha' => $sk['sha']];
}

render_header('Fiş Excel\'i Yükle');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>
<div class="page-head">
    <h1>📥 Fiş Excel'i Yükle</h1>
    <div class="page-head-actions">
        <a href="gunluk_isci_puantaj.php" class="btn btn-geri">← Günlük Puantaj</a>
    </div>
</div>

<div class="fy-sayfa" id="fisYukleSayfa">
<?php if ($depo === ''): ?>
    <div class="card"><p>Önce bir <b>depo</b> seçin (üst menüdeki depo göstergesi).</p></div>
<?php elseif ($model !== null):
    $model += ['csrf' => csrf_token(), 'url' => $self, 'depo' => $depo];
    include __DIR__ . '/_fis_onizle.php';
else: ?>
    <div class="card fy-kart-adim">
        <h2>1 · Şablon</h2>
        <p>Şablonu indirin, fiş fotoğraflarıyla birlikte yapay zekâya verin (aşağıdaki komutla) ve «Fişler» sayfasını doldurtun. Aktif depo: <b><?= h($depo) ?></b> · yalnız <b>geçmiş günlerin</b> fişleri · en çok <?= (int)PDKS_FIS_MAX_FIS ?> fiş · en çok <?= (int)(PDKS_FIS_MAX_BAYT / 1048576) ?> MB.</p>
        <p><a class="btn btn-primary" href="<?= h($self) ?>?sablon=1">📄 Şablonu indir (.xlsx)</a></p>
        <details class="fy-komut">
            <summary>Yapay zekâya verilecek komut</summary>
            <textarea id="fyKomut" readonly rows="14" aria-label="Yapay zekâ komutu"><?= h(pdks_fis_ocr_komutu()) ?></textarea>
            <p><button type="button" class="btn" id="fyKopyala">📋 Komutu kopyala</button> <span id="fyKopyalaDurum" class="fy-kod" aria-live="polite"></span></p>
        </details>
    </div>
    <div class="card fy-kart-adim">
        <h2>2 · Dolu dosyayı yükle</h2>
        <form method="post" action="<?= h($self) ?>" enctype="multipart/form-data" class="fy-form">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="eylem" value="onizle">
            <div class="form-group">
                <label for="fyDosya">Fiş Excel dosyası (.xlsx)</label>
                <input type="file" id="fyDosya" name="dosya" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
            </div>
            <div class="form-group">
                <label for="fyFmTip">Toplam FM dağıtımında / FM Tipi boş fişlerde FM yapanlar</label>
                <select id="fyFmTip" name="fm_tip">
                    <option value="tumu">Tümü (Tam kişi sayısıyla orantılı)</option>
                    <option value="erkek">Yalnız Erkek</option>
                    <option value="erkek_rampaci">Erkek + Rampacı</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">🔍 Önizle</button>
        </form>
        <p class="fy-yonerge">Önizlemede hiçbir kayıt yazılmaz. Dosya sunucuda saklanmaz.</p>
    </div>
<?php endif; ?>
</div>

<script>
(function () {
    var kap = document.getElementById('fisYukleSayfa');
    if (!kap) return;
    var kop = document.getElementById('fyKopyala'), kutu = document.getElementById('fyKomut'), durum = document.getElementById('fyKopyalaDurum');
    if (kop && kutu) kop.addEventListener('click', function () {
        function bitti(m) { if (durum) durum.textContent = m; }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(kutu.value).then(function () { bitti('Kopyalandı'); }, function () { kutu.select(); bitti(document.execCommand('copy') ? 'Kopyalandı' : 'Kopyalanamadı — metni seçip kopyalayın'); });
        } else { kutu.select(); bitti(document.execCommand('copy') ? 'Kopyalandı' : 'Kopyalanamadı — metni seçip kopyalayın'); }
    });
    var form = document.getElementById('fyYazForm');
    if (!form) return;
    function satirlar() { return form.querySelectorAll('input[data-fy-satir]'); }
    function say() {
        var s = {};
        satirlar().forEach(function (c) { if (c.checked) s[c.getAttribute('data-fy-satir')] = (s[c.getAttribute('data-fy-satir')] || 0) + 1; });
        var n = 0; Object.keys(s).forEach(function (k) { if (s[k] >= 2) n++; });
        var e = form.querySelector('[data-fy-sayi]'); if (e) e.textContent = String(n);
        var b = document.getElementById('fyYazBtn'); if (b) b.disabled = n === 0;
    }
    form.addEventListener('change', function (ev) {
        var t = ev.target;
        if (t.hasAttribute('data-fy-hepsi')) {
            satirlar().forEach(function (c) { if (!c.disabled) c.checked = t.checked; });
        } else if (t.hasAttribute && t.hasAttribute('data-fy-satir')) {
            // masaüstü tablo ve mobil kart kopyaları aynı durumda tutulur
            var s = t.getAttribute('data-fy-satir');
            satirlar().forEach(function (c) { if (c !== t && c.getAttribute('data-fy-satir') === s && !c.disabled) c.checked = t.checked; });
        }
        say();
    });
    say();
})();
</script>
<?php render_footer(); ?>
