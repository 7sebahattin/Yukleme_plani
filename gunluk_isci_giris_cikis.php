<?php
// =========================================================
// gunluk_isci_giris_cikis.php — Günlük İşçi Giriş / Çıkış (Günlük İşçi, Faz 2)
//
// Kalıcı personel giris_cikis.php İLE AYNI kiosk deseni (mod seç → tara →
// sonuç) + BAŞINA bir ÇAVUŞ SEÇ adımı eklenir. Kalıcı personel giris_cikis.php
// DOSYASI HİÇ DEĞİŞTİRİLMEDİ — kullanıcının açık talimatı: "Do not reuse the
// permanent-personnel giris_cikis.php."
//
// Kart/işçi kimliği ve oturum durumu HER ZAMAN sunucuda çözülür
// (config/pdks_gunluk.php → pdks_gunluk_oturum_kaydet()); istemci yalnız ham
// okuma + kaynak (usb_decimal | web_nfc) + seçili mod + oturum id gönderir.
//
// ⚠ NFC: YENİ bir okuma döngüsü İCAT EDİLMEDİ — config/pdks.php'nin
// pdks_nfc_oku_js() ile bastığı PAYLAŞILAN window.PdksNfcOku (teşhis sayfası
// + kalıcı personel giris_cikis.php İLE AYNI kod) burada da REUSE edilir.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks.php';           // UID normalizasyon + PdksNfcOku (REUSE)
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_gunluk('daily_scan');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);   // Faz 1 kuralı: normal ziyarette DDL YOK — bkz. o fonksiyon
$base = base_url();

// ⚠ FAZ 8A: şema hazırsa GİRİŞ ekranında İşçi Tipi + Tam/Yarım seçimi
// gösterilir ve kayıt yeni work-period fonksiyonlarına gider; şema HENÜZ
// hazır değilse (migrasyon çalıştırılmadan önce) sayfa AYNEN Faz 2'nin eski
// davranışını sergiler — kod deploy'u ile migrasyon arasında tarama BOZULMAZ.
$faz8aHazir  = pdks_gunluk_faz8a_sema_hazir($pdo);
$isciTipleri = $faz8aHazir ? pdks_gunluk_tip_listele(true, $pdo) : [];

// ── AJAX uçları — SAYFANIN İÇİNDE, JSON. Yön istemciden ASLA otomatik
// tahmin edilmez, kart/oturum çözümü TAMAMEN sunucudadır. ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['ajax'] ?? '') === 'oturum') {
    header('Content-Type: application/json; charset=utf-8');
    $govde = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($govde)) $govde = [];
    csrf_check($govde['csrf'] ?? null);
    require_pdks_gunluk('daily_scan');

    $foremanId = (int)($govde['foreman_id'] ?? 0);
    $mod       = trim((string)($govde['mode'] ?? ''));
    if ($foremanId <= 0 || !in_array($mod, ['GIRIS', 'CIKIS'], true)) {
        echo json_encode(['ok' => false, 'kod' => 'gecersiz_istek', 'hata' => 'Geçersiz istek.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sonuc = ($mod === 'GIRIS')
        ? pdks_gunluk_oturum_ac_veya_getir($foremanId, (int)$auth_user['id'], $pdo)
        : pdks_gunluk_oturum_bul_acik($foremanId, $pdo);
    echo json_encode($sonuc, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['ajax'] ?? '') === 'kaydet') {
    header('Content-Type: application/json; charset=utf-8');
    $govde = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($govde)) $govde = [];
    csrf_check($govde['csrf'] ?? null);
    require_pdks_gunluk('daily_scan');

    $sessionId = (int)($govde['session_id'] ?? 0);
    $hamUid    = trim((string)($govde['ham_uid'] ?? ''));
    $kaynak    = trim((string)($govde['kaynak'] ?? ''));
    $eventType = trim((string)($govde['event_type'] ?? ''));
    if ($sessionId <= 0) {
        echo json_encode(['ok' => false, 'kod' => 'oturum_yok', 'hata' => 'Önce çavuş ve mod seçin.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ⚠ FAZ 8A: USB VE Web NFC AYNI sunucu fonksiyonlarından geçer — kaynak
    // (usb_decimal|web_nfc) burada yalnız bir parametredir, iki AYRI iş
    // mantığı YOKTUR (görev talimatı §14).
    if (pdks_gunluk_faz8a_sema_hazir($pdo)) {
        if ($eventType === 'GIRIS') {
            $workerTypeId = (int)($govde['worker_type_id'] ?? 0);
            if ($workerTypeId <= 0) {
                echo json_encode(['ok' => false, 'kod' => 'secim_eksik', 'hata' => 'Önce İşçi Tipi seçin.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Faz 8D: operatör Tam/Yarım seçmez.
            // Gerçek mesai sonucu giriş/çıkış saatlerinden Faz 8B motorunda belirlenir.
            $sonuc = pdks_gunluk_faz8a_giris_kaydet(
                $hamUid,
                $kaynak,
                $sessionId,
                $workerTypeId,
                'auto',
                (int)$auth_user['id'],
                $pdo
            );
        } elseif ($eventType === 'CIKIS') {
            $sonuc = pdks_gunluk_faz8a_cikis_kaydet($hamUid, $kaynak, $sessionId, (int)$auth_user['id'], $pdo);
        } else {
            $sonuc = ['ok' => false, 'kod' => 'gecersiz_yon', 'hata' => 'Geçersiz giriş/çıkış yönü.'];
        }
    } else {
        $sonuc = pdks_gunluk_oturum_kaydet($hamUid, $kaynak, $sessionId, $eventType, (int)$auth_user['id'], $pdo);
    }
    echo json_encode($sonuc, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['ajax'] ?? '') === 'kapat') {
    header('Content-Type: application/json; charset=utf-8');
    $govde = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($govde)) $govde = [];
    csrf_check($govde['csrf'] ?? null);
    require_pdks_gunluk('daily_scan');

    $sessionId = (int)($govde['session_id'] ?? 0);
    $not       = trim((string)($govde['not'] ?? ''));
    if ($sessionId <= 0) {
        echo json_encode(['ok' => false, 'kod' => 'oturum_yok', 'hata' => 'Mesai bulunamadı.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sonuc = pdks_gunluk_oturum_kapat($sessionId, $not !== '' ? $not : null, (int)$auth_user['id'], $pdo);
    echo json_encode($sonuc, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── GET render — aktif çavuşlar listesi ────────────────────────────────
$cavuslar = [];
try {
    $cavuslar = $pdo->query("SELECT id, code, name FROM foremen WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) { /* pdks_gunluk_sayfa_kapisi() zaten şemayı garanti etti — buraya düşmemeli */ }

render_header('Günlük İşçi Giriş / Çıkış');
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head" id="giPageHead">
    <h1>🚪 Günlük İşçi Giriş / Çıkış</h1>
    <div class="page-head-actions">
        <a href="cavuslar.php" class="btn btn-ghost">👷 Çavuşlar</a>
    </div>
</div>

<input type="hidden" id="giCsrf" value="<?= h(csrf_token()) ?>">

<div class="pdks-kiosk">

    <!-- ── 0) Çavuş seç ─────────────────────────────────────── -->
    <div id="giCavusSec" class="pdks-kiosk-modesec">
        <p class="muted" style="text-align:center;max-width:420px;margin:0 auto">
            <strong>1. ÇAVUŞ SEÇ</strong><br>
            Günlük işçileri getiren çavuşu seçin.
        </p>
        <?php if (empty($cavuslar)): ?>
        <div class="pdks-empty">
            <span class="pdks-empty-icon" aria-hidden="true">👷</span>
            <p>Henüz aktif çavuş yok.</p>
            <a href="cavus_form.php" class="btn btn-primary">+ Çavuş Ekle</a>
        </div>
        <?php else: ?>
        <?php if (count($cavuslar) > 10): ?>
        <input type="search" id="giCavusFiltre" class="pdks-kiosk-cavus-filter" placeholder="Çavuş adı ara…" autocomplete="off">
        <?php endif; ?>
        <div class="pdks-kiosk-cavus-list" id="giCavusListe">
            <?php foreach ($cavuslar as $c): ?>
            <button type="button" class="pdks-kiosk-cavus-btn" data-gi-cavus-id="<?= (int)$c['id'] ?>"
                    data-gi-cavus-ad="<?= h($c['name']) ?>" data-gi-filtre="<?= h(mb_strtolower($c['name'], 'UTF-8')) ?>">
                <span><?= h($c['name']) ?></span>
                <span class="pdks-kiosk-cavus-kod"><?= h($c['code']) ?></span>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── 1) Mod seç ──────────────────────────────────────── -->
    <div id="giModeSec" class="pdks-kiosk-modesec" hidden>
        <div class="pdks-kiosk-selected">
            <div class="pdks-kiosk-selected-label">SEÇİLİ ÇAVUŞ</div>
            <div class="pdks-kiosk-selected-name" id="giSeciliCavusAd"></div>
        </div>
        <button type="button" class="pdks-kiosk-modebtn pdks-kiosk-modebtn-giris" data-gi-mode="GIRIS">
            ✅ GİRİŞ MODU
        </button>
        <button type="button" class="pdks-kiosk-modebtn pdks-kiosk-modebtn-cikis" data-gi-mode="CIKIS">
            🚪 ÇIKIŞ MODU
        </button>
        <button type="button" class="btn btn-ghost" id="giCavusDegistir1">↩ Çavuşu Değiştir</button>
    </div>

    <!-- ── 2) Tarama + canlı sayaçlar ───────────────────────── -->
    <div id="giScanSec" class="pdks-kiosk-scan" hidden>
        <div class="pdks-kiosk-selected" style="margin-bottom:4px">
            <div class="pdks-kiosk-selected-name" id="giScanCavusAd" style="font-size:1.05rem"></div>
        </div>
        <span id="giModeBadge" class="pdks-kiosk-mode-badge"></span>

        <?php if ($faz8aHazir): ?>
        <!-- ── FAZ 8A: GİRİŞ modunda İşçi Tipi + Tam/Yarım Mesai — büyük,
             dokunmatik dostu butonlar. Seçim değiştirmek TARAMAYI KESMEZ;
             başarılı her taramadan sonra AYNEN kalır (görev talimatı §11). ── -->
        <div id="giTipMesaiSec" class="pdks-kiosk-counters" hidden>
            <h3>İŞÇİ TİPİ</h3>
            <div class="pdks-kiosk-tipbtn-row" id="giTipBtnRow">
                <?php foreach ($isciTipleri as $t): ?>
                <button type="button" class="btn btn-lg pdks-kiosk-secbtn" data-gi-tip-id="<?= (int)$t['id'] ?>" data-gi-tip-ad="<?= h($t['name']) ?>"><?= h(mb_strtoupper($t['name'], 'UTF-8')) ?></button>
                <?php endforeach; ?>
            </div>

            <p class="muted pdks-kiosk-secuyari" id="giSecUyari" style="margin:8px 0 0" hidden>Taramaya başlamadan önce İşçi Tipi seçin.</p>
        </div>
        <?php endif; ?>

        <div class="pdks-kiosk-counters" id="giSayaclar">
            <h3>Bugün — <span id="giSayacDepo"></span></h3>
            <div id="giSayacSatirlar"></div>
            <div class="pdks-kiosk-counter-totals">
                <div class="pdks-kiosk-counter-box"><div class="lbl">Giriş</div><div class="val" id="giGirisToplam">0</div></div>
                <div class="pdks-kiosk-counter-box"><div class="lbl">Çıkış</div><div class="val" id="giCikisToplam">0</div></div>
                <div class="pdks-kiosk-counter-box"><div class="lbl">İçeride</div><div class="val" id="giIcerdeToplam">0</div></div>
                <div class="pdks-kiosk-counter-box eksik"><div class="lbl">Eksik Çıkış</div><div class="val" id="giEksikToplam">0</div></div>
            </div>
        </div>

        <div class="pdks-kiosk-scan-icon" aria-hidden="true">📇</div>
        <div class="pdks-kiosk-scan-text">KARTINIZI OKUTUN</div>
        <div class="pdks-kiosk-scan-hint muted">USB okuyucuya okutun<span id="giNfcHint"></span></div>

        <div id="giNfcBtnWrap" hidden>
            <button type="button" id="giNfcBtn" class="btn btn-lg">📡 NFC İLE KART OKU</button>
            <!-- ⚠ GEÇİCİ TEŞHİS PANELİ — kalıcı personel giris_cikis.php İLE AYNI amaç. -->
            <pre id="giNfcDebug" class="pdks-kiosk-nfc-debug"></pre>
        </div>

        <input type="text" id="giScanInput" class="pdks-kiosk-hidden-input"
               inputmode="none" autocomplete="off" aria-hidden="true" tabindex="-1">

        <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-top:24px">
            <button type="button" class="btn btn-ghost" id="giCavusDegistir2">↩ Çavuşu Değiştir</button>
            <button type="button" class="btn" id="giModDegistir">🔁 Modu Değiştir</button>
            <button type="button" class="btn btn-primary" id="giKapatBtn">🔒 MESAİYİ KAPAT</button>
        </div>

        <!-- ── Sonuç overlay'i (başarı/hata) ────────────────── -->
        <div id="giResult" class="pdks-kiosk-result" hidden>
            <div id="giResultInner"></div>
        </div>
    </div>

    <!-- ── 3) Mutabakat / kapatma ekranı ────────────────────── -->
    <div id="giReconSec" class="pdks-kiosk-modesec" hidden>
        <div class="pdks-kiosk-recon">
            <h2 style="margin-top:0">Eksik Çıkışlar Var</h2>
            <p class="muted">Tüm kartlar çıkış yapmadan mesai kapatılamaz. Eksik çıkışları kabul edip kapatmak
               için bir gerekçe yazın, veya vazgeçip taramaya devam edin.</p>

            <div class="pdks-kiosk-counters" style="margin:0 0 14px">
                <h3>Özet</h3>
                <div id="giReconSayaclar"></div>
                <div class="pdks-kiosk-counter-totals">
                    <div class="pdks-kiosk-counter-box"><div class="lbl">Giriş</div><div class="val" id="giReconGiris">0</div></div>
                    <div class="pdks-kiosk-counter-box"><div class="lbl">Çıkış</div><div class="val" id="giReconCikis">0</div></div>
                    <div class="pdks-kiosk-counter-box eksik"><div class="lbl">Eksik Çıkış</div><div class="val" id="giReconEksik">0</div></div>
                </div>
            </div>

            <h3 style="font-size:.9rem">Eksik Kartlar</h3>
            <div id="giReconKartlar"></div>

            <label style="display:block;margin-top:14px">
                <span class="form-label">Kapatma Gerekçesi *</span>
                <textarea id="giReconNot" rows="3" placeholder="ör. 1 kart sahada kaldı, yarın teslim edilecek" required></textarea>
            </label>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px">
                <button type="button" class="btn btn-primary" id="giReconKapatBtn">Eksik Çıkışlarla Kapat</button>
                <button type="button" class="btn btn-ghost" id="giReconVazgecBtn">Vazgeç — Taramaya Dön</button>
            </div>
        </div>
    </div>
</div>

<?php pdks_nfc_oku_js();   /* ortak Web NFC okuma yolu — giris_cikis.php / pdks_nfc_test.php İLE AYNI kod */ ?>

<script>
(function () {
    'use strict';

    var csrf = document.getElementById('giCsrf').value;

    var pageHead   = document.getElementById('giPageHead');
    var cavusSec   = document.getElementById('giCavusSec');
    var modeSec    = document.getElementById('giModeSec');
    var scanSec    = document.getElementById('giScanSec');
    var reconSec   = document.getElementById('giReconSec');
    var modeBadge  = document.getElementById('giModeBadge');
    var scanInput  = document.getElementById('giScanInput');
    var resultBox  = document.getElementById('giResult');
    var resultInner = document.getElementById('giResultInner');
    var nfcBtnWrap = document.getElementById('giNfcBtnWrap');
    var nfcBtn     = document.getElementById('giNfcBtn');
    var nfcHint    = document.getElementById('giNfcHint');
    var nfcDebugEl = document.getElementById('giNfcDebug');

    // ── FAZ 8A: İşçi Tipi + Tam/Yarım seçim durumu (yalnız şema hazırsa DOM'da var) ──
    var tipMesaiSec = document.getElementById('giTipMesaiSec');
    var secUyari    = document.getElementById('giSecUyari');
    var seciliTipId = null;
    var seciliTipAd = null;

    if (tipMesaiSec) {
        document.querySelectorAll('[data-gi-tip-id]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                seciliTipId = parseInt(btn.getAttribute('data-gi-tip-id'), 10);
                seciliTipAd = btn.getAttribute('data-gi-tip-ad');
                document.querySelectorAll('[data-gi-tip-id]').forEach(function (b) { b.classList.toggle('pdks-kiosk-secbtn-aktif', b === btn); });
                if (secUyari) secUyari.hidden = true;
            });
        });

    }

    // ── Basit ses geri bildirimi — Web Audio API, harici dosya/kütüphane
    // YOK (görev talimatı §15). Ses BAŞARISIZ olursa kayda ASLA engel olmaz
    // (try/catch içinde, sessizce yutulur — tarayıcı autoplay kısıtları dahil). ──
    var audioCtx = null;
    function sesBaglami() {
        try {
            if (!audioCtx) {
                var AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) return null;
                audioCtx = new AC();
            }
            if (audioCtx.state === 'suspended') audioCtx.resume().catch(function () {});
            return audioCtx;
        } catch (e) { return null; }
    }
    function biples(frekans, sureMs, baslangicMs) {
        try {
            var ctx = sesBaglami();
            if (!ctx) return;
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = frekans;
            gain.gain.value = 0.18;
            osc.connect(gain);
            gain.connect(ctx.destination);
            var basla = ctx.currentTime + (baslangicMs || 0) / 1000;
            osc.start(basla);
            osc.stop(basla + sureMs / 1000);
        } catch (e) { /* ses arızası kaydı ASLA engellemez */ }
    }
    function sesBasarili() { biples(1046, 110, 0); }
    function sesHata()     { biples(220, 120, 0); biples(180, 160, 140); }
    // İlk kullanıcı etkileşiminde bağlamı hazırla (tarayıcı autoplay kısıtı).
    document.addEventListener('click', function initAudioOnce() {
        sesBaglami();
        document.removeEventListener('click', initAudioOnce);
    }, { once: true });

    var MOD_ETIKET = { GIRIS: '✅ GİRİŞ MODU', CIKIS: '🚪 ÇIKIŞ MODU' };
    var MOD_SINIF  = { GIRIS: 'pdks-kiosk-mode-badge-giris', CIKIS: 'pdks-kiosk-mode-badge-cikis' };

    var seciliCavusId  = null;
    var seciliCavusAd  = null;
    var currentMode    = null;   // 'GIRIS' | 'CIKIS' — İSTEMCİDE ASLA otomatik seçilmez
    var currentSession = null;   // { id, ... } — sunucudan gelir, İCAT EDİLMEZ
    var busy = false;
    var resultTimer = null;

    function nfcDebugYaz(satir) {
        if (!nfcDebugEl) return;
        var zaman = new Date().toLocaleTimeString('tr-TR');
        nfcDebugEl.textContent = '[' + zaman + '] ' + satir + '\n' + nfcDebugEl.textContent;
    }

    // ⚠ Kalıcı personel giris_cikis.php İLE AYNI desen — dokunmatikte
    // otomatik odak YOK, masaüstünde (ince işaretçi) USB kutusu her zaman
    // odaklı kalır.
    var inceIsaretci = !!(window.matchMedia && window.matchMedia('(any-pointer: fine)').matches);
    function focusInput() {
        if (!inceIsaretci) return;
        try { scanInput.focus({ preventScroll: true }); } catch (e) { try { scanInput.focus(); } catch (e2) {} }
    }

    function ekranGoster(ekran) {
        [cavusSec, modeSec, scanSec, reconSec].forEach(function (el) { el.hidden = (el !== ekran); });
        // ⚠ .page-head display:flex TAŞIR — hidden TEK BAŞINA gizleyemez
        // (bkz. CLAUDE.md maliyet.css notu, giris_cikis.php İLE AYNI düzeltme).
        if (pageHead) pageHead.style.display = (ekran === scanSec) ? 'none' : '';
    }

    // ── 0) Çavuş seçimi ──────────────────────────────────────
    var cavusFiltre = document.getElementById('giCavusFiltre');
    if (cavusFiltre) {
        cavusFiltre.addEventListener('input', function () {
            var q = cavusFiltre.value.trim().toLowerCase();
            document.querySelectorAll('[data-gi-cavus-id]').forEach(function (btn) {
                btn.hidden = q !== '' && btn.getAttribute('data-gi-filtre').indexOf(q) === -1;
            });
        });
    }
    document.querySelectorAll('[data-gi-cavus-id]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            seciliCavusId = parseInt(btn.getAttribute('data-gi-cavus-id'), 10);
            seciliCavusAd = btn.getAttribute('data-gi-cavus-ad');
            document.getElementById('giSeciliCavusAd').textContent = seciliCavusAd;
            currentMode = null; currentSession = null;
            ekranGoster(modeSec);
        });
    });

    function cavusDegistir() {
        // ⚠ Sunucudaki oturum KAPATILMAZ — yalnız istemci ekranı sıfırlanır
        // (kullanıcının açık talimatı: "changing screen/foreman must NOT
        // close the session. Sessions stay server-side until explicitly closed.")
        seciliCavusId = null; seciliCavusAd = null; currentMode = null; currentSession = null;
        if (cavusFiltre) { cavusFiltre.value = ''; document.querySelectorAll('[data-gi-cavus-id]').forEach(function (b) { b.hidden = false; }); }
        ekranGoster(cavusSec);
    }
    document.getElementById('giCavusDegistir1').addEventListener('click', cavusDegistir);
    document.getElementById('giCavusDegistir2').addEventListener('click', cavusDegistir);

    // ── 1) Mod seçimi → sunucudan oturum aç/getir ────────────
    function sayaclariGoster(ozet) {
        var satirlar = '';
        var tipler = {};
        Object.keys(ozet.giris || {}).forEach(function (t) { tipler[t] = true; });
        Object.keys(ozet.cikis || {}).forEach(function (t) { tipler[t] = true; });
        Object.keys(tipler).sort().forEach(function (tip) {
            var g = (ozet.giris && ozet.giris[tip]) || 0;
            var c = (ozet.cikis && ozet.cikis[tip]) || 0;
            satirlar += '<div class="pdks-kiosk-counter-row"><span>' + escHtml(tip) + '</span>' +
                '<span class="n">Giriş ' + g + ' · Çıkış ' + c + '</span></div>';
        });
        if (satirlar === '') satirlar = '<div class="pdks-kiosk-counter-row muted"><span>Henüz tarama yok</span></div>';
        document.getElementById('giSayacSatirlar').innerHTML = satirlar;
        document.getElementById('giGirisToplam').textContent  = ozet.giris_toplam || 0;
        document.getElementById('giCikisToplam').textContent  = ozet.cikis_toplam || 0;
        document.getElementById('giIcerdeToplam').textContent = ozet.icerde_toplam || 0;
        document.getElementById('giEksikToplam').textContent  = ozet.eksik_toplam || 0;
    }

    function modSec(mod) {
        if (!seciliCavusId) return;
        currentMode = mod;
        fetch('gunluk_isci_giris_cikis.php?ajax=oturum', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ csrf: csrf, foreman_id: seciliCavusId, mode: mod })
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) {
                    alert((d && d.hata) || 'Mesai açılamadı/bulunamadı.');
                    currentMode = null;
                    return;
                }
                currentSession = d.session;
                modeBadge.textContent = MOD_ETIKET[mod];
                modeBadge.className = 'pdks-kiosk-mode-badge ' + MOD_SINIF[mod];
                document.getElementById('giScanCavusAd').textContent = seciliCavusAd + ' — ' + MOD_ETIKET[mod];
                document.getElementById('giSayacDepo').textContent = currentSession.depo || '(depo yok)';
                sayaclariGoster(d.ozet || {});
                scanInput.value = '';
                resultBox.hidden = true;
                // ⚠ FAZ 8A: İşçi Tipi/Mesai seçimi YALNIZ GİRİŞ modunda görünür —
                // ÇIKIŞ bunları AÇIK dönemden türetir, yeniden SORMAZ (görev talimatı §18).
                if (tipMesaiSec) {
                    tipMesaiSec.hidden = (mod !== 'GIRIS');
                    if (mod === 'GIRIS') {
                        // Yeni bir GİRİŞ akışına her girişte seçim SIFIRLANIR — operatör
                        // her çavuş/mod değişiminde BİLİNÇLİ olarak Tip/Mesai seçsin
                        // (önceki çavuşun seçimi sessizce taşınmasın).
                        seciliTipId = null; seciliTipAd = null;
                        document.querySelectorAll('[data-gi-tip-id]').forEach(function (b) { b.classList.remove('pdks-kiosk-secbtn-aktif'); });
                        if (secUyari) secUyari.hidden = true;
                    }
                }
                ekranGoster(scanSec);
                focusInput();
            })
            .catch(function () {
                alert('Bağlantı hatası. Tekrar deneyin.');
                currentMode = null;
            });
    }
    document.querySelectorAll('[data-gi-mode]').forEach(function (btn) {
        btn.addEventListener('click', function () { modSec(btn.getAttribute('data-gi-mode')); });
    });
    document.getElementById('giModDegistir').addEventListener('click', function () {
        currentMode = null; currentSession = null;
        ekranGoster(modeSec);
    });

    // Sayfa herhangi bir yere tıklanınca odak USB kutusuna dönsün.
    document.addEventListener('click', function (e) {
        if (!scanSec.hidden && e.target !== nfcBtn) focusInput();
    });

    function gosterSonuc(html, sinif, sureMs) {
        clearTimeout(resultTimer);
        resultInner.innerHTML = html;
        resultBox.className = 'pdks-kiosk-result ' + sinif;
        resultBox.hidden = false;
        resultTimer = setTimeout(function () { resultBox.hidden = true; focusInput(); }, sureMs);
    }
    function basariGoster(d) {
        var kart = d.card || {};
        var baslik = d.event_type === 'GIRIS' ? 'GİRİŞ KAYDEDİLDİ' : 'ÇIKIŞ KAYDEDİLDİ';
        var saat = (d.server_time || '').split(' ')[1] || '';
        var altBilgi = escHtml(kart.worker_type_name || '');
        if (kart.declared_class_label) altBilgi += ' · ' + escHtml(kart.declared_class_label);
        // ÇIKIŞ'ta giriş→çıkış aralığını da göster (görev talimatı §18 örneği: "08:03 → 12:05").
        if (d.event_type === 'CIKIS' && kart.entry_time) {
            var girisSaat = (kart.entry_time.split(' ')[1] || kart.entry_time).slice(0, 5);
            var cikisSaat = saat.slice(0, 5);
            altBilgi += '<br>' + escHtml(girisSaat) + ' → ' + escHtml(cikisSaat);
        }
        sesBasarili();
        gosterSonuc(
            '<div class="pdks-kiosk-result-icon">✓</div>' +
            '<div class="pdks-kiosk-result-name">' + escHtml(kart.card_no || '') + '</div>' +
            '<div class="pdks-kiosk-result-sub">' + altBilgi + '</div>' +
            '<div class="pdks-kiosk-result-msg">' + baslik + '</div>',
            'pdks-kiosk-result-ok', 1400
        );
    }
    function hataGoster(mesaj) {
        sesHata();
        gosterSonuc(
            '<div class="pdks-kiosk-result-icon">✕</div>' +
            '<div class="pdks-kiosk-result-msg">' + escHtml(mesaj || 'Kayıt yapılamadı.') + '</div>',
            'pdks-kiosk-result-err', 2200
        );
    }
    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    // ── Kayıt ────────────────────────────────────────────────
    function kaydet(hamUid, kaynak) {
        if (busy || !currentMode || !currentSession) return;
        var deger = String(hamUid || '').trim();
        if (deger === '') return;
        // ⚠ FAZ 8A: GİRİŞ modunda İşçi Tipi + Mesai seçilmeden TARAMA KABUL
        // EDİLMEZ (görev talimatı §10 akışı: "3. İşçi Tipi seç 4. Tam/Yarım
        // seç 5. tara") — istemcide erken reddedilir, sunucu da AYNI kuralı
        // ayrıca doğrular (secim_eksik, bkz. PHP tarafı).
        if (tipMesaiSec && currentMode === 'GIRIS' && !seciliTipId) {
            if (secUyari) secUyari.hidden = false;
            sesHata();
            return;
        }
        busy = true;
        var govde = { csrf: csrf, session_id: currentSession.id, ham_uid: deger, kaynak: kaynak, event_type: currentMode };
        if (tipMesaiSec && currentMode === 'GIRIS') {
            govde.worker_type_id = seciliTipId;
        }
        fetch('gunluk_isci_giris_cikis.php?ajax=kaydet', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(govde)
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                busy = false;
                nfcDebugYaz('backend response: ' + ((d && d.ok) ? 'ok' : 'hata (' + ((d && d.kod) || '?') + ')'));
                if (d && d.ok) { basariGoster(d); if (d.ozet) sayaclariGoster(d.ozet); }
                else hataGoster(d && d.hata);
                focusInput();
            })
            .catch(function () {
                busy = false;
                nfcDebugYaz('backend response: ağ hatası');
                hataGoster('Bağlantı hatası. Tekrar deneyin.');
                focusInput();
            });
    }

    // ── USB HID girişi — kalıcı personel giris_cikis.php İLE AYNI desen ──
    var usbTimer = null;
    scanInput.addEventListener('input', function () {
        var temiz = scanInput.value.replace(/[^0-9]/g, '');
        if (temiz !== scanInput.value) scanInput.value = temiz;
        clearTimeout(usbTimer);
        if (temiz === '') return;
        usbTimer = setTimeout(function () {
            var v = scanInput.value; scanInput.value = '';
            kaydet(v, 'usb_decimal');
        }, 350);
    });
    scanInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(usbTimer);
            var v = scanInput.value; scanInput.value = '';
            if (v.trim() !== '') kaydet(v, 'usb_decimal');
        }
    });

    // ── Web NFC girişi — PAYLAŞILAN PdksNfcOku (config/pdks.php) ─────────
    // ⚠ giris_cikis.php / pdks_nfc_test.php İLE BİREBİR AYNI KOD — burada
    // YENİ bir NDEFReader/AbortController AÇILMAZ (kullanıcının açık
    // talimatı: "Do not reintroduce the previously broken AbortController
    // lifecycle"). Oturum, teşhis sayfasındaki gibi AÇIK KALIR.
    var nfcDinlemede = false;
    var NFC_ETIKET_DINLEME = '🟢 NFC HAZIR — KARTI TELEFONA YAKLAŞTIRIN';

    nfcDebugYaz('NFC support: ' + (('NDEFReader' in window) ? 'evet' : 'hayır') +
        ' · secure context: ' + (window.isSecureContext ? 'evet' : 'hayır'));

    if (PdksNfcOku.destekli()) {
        nfcBtnWrap.hidden = false;
        nfcHint.textContent = ' veya NFC ile telefonun arkasına yaklaştırın';
        nfcBtn.disabled = false;
        nfcDebugYaz('button: enabled');

        nfcBtn.addEventListener('click', function () {
            if (nfcDinlemede) return;
            nfcDebugYaz('button clicked');
            PdksNfcOku.baslat({
                onOkuma: function (ev) {
                    var ham = (ev.serialNumber != null) ? String(ev.serialNumber) : '';
                    nfcDebugYaz('reading received — serialNumber=' + (ham || '(boş)'));
                    if (ham !== '') kaydet(ham, 'web_nfc');
                },
                onOkumaHatasi: function () {
                    nfcDebugYaz('readingerror');
                    hataGoster('NFC okuma hatası — kartı tekrar yaklaştırın.');
                },
                onBasladi: function () {
                    nfcDinlemede = true;
                    nfcDebugYaz('scan started');
                    nfcBtn.textContent = NFC_ETIKET_DINLEME;
                    nfcBtn.disabled = true;
                    nfcBtn.classList.add('pdks-kiosk-nfc-armed');
                },
                onHata: function (ad, msj) {
                    nfcDebugYaz('scan() rejected: ' + ad + ' — ' + msj);
                    hataGoster('NFC başlatılamadı. NFC İLE KART OKU butonuna tekrar dokunun.');
                }
            });
        });
    } else {
        nfcBtn.disabled = true;
        nfcDebugYaz('button: disabled (desteklenmiyor veya güvenli bağlam yok)');
    }

    // ── 3) Mesaiyi kapat / mutabakat ──────────────────────────
    function reconGoster(ozet) {
        var satirlar = '';
        var tipler = {};
        Object.keys(ozet.giris || {}).forEach(function (t) { tipler[t] = true; });
        Object.keys(ozet.cikis || {}).forEach(function (t) { tipler[t] = true; });
        Object.keys(tipler).sort().forEach(function (tip) {
            var g = (ozet.giris && ozet.giris[tip]) || 0;
            var c = (ozet.cikis && ozet.cikis[tip]) || 0;
            var e = (ozet.eksik_tip && ozet.eksik_tip[tip]) || 0;
            satirlar += '<div class="pdks-kiosk-counter-row"><span>' + escHtml(tip) + '</span>' +
                '<span class="n">Giriş ' + g + ' · Çıkış ' + c + (e ? ' · <span style="color:var(--warn)">Eksik ' + e + '</span>' : '') + '</span></div>';
        });
        document.getElementById('giReconSayaclar').innerHTML = satirlar;
        document.getElementById('giReconGiris').textContent = ozet.giris_toplam || 0;
        document.getElementById('giReconCikis').textContent = ozet.cikis_toplam || 0;
        document.getElementById('giReconEksik').textContent = ozet.eksik_toplam || 0;

        var kartHtml = '';
        (ozet.eksik_kartlar || []).forEach(function (k) {
            var saat = (k.giris_zamani || '').split(' ')[1] || k.giris_zamani || '';
            kartHtml += '<div class="pdks-kiosk-recon-missing-row"><span><strong>' + escHtml(k.card_no) + '</strong> — ' + escHtml(k.tip) + '</span>' +
                '<span>Giriş: ' + escHtml(saat) + ' · Çıkış: —</span></div>';
        });
        document.getElementById('giReconKartlar').innerHTML = kartHtml || '<p class="muted">Eksik kart yok.</p>';
        document.getElementById('giReconNot').value = '';
        ekranGoster(reconSec);
    }

    function kapat(not) {
        if (!currentSession) return;
        fetch('gunluk_isci_giris_cikis.php?ajax=kapat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ csrf: csrf, session_id: currentSession.id, not: not || '' })
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.ok) {
                    alert('Mesai kapatıldı.');
                    cavusDegistir();
                    return;
                }
                if (d && d.kod === 'eksik_cikis_var') {
                    reconGoster(d.ozet || {});
                    return;
                }
                alert((d && d.hata) || 'Mesai kapatılamadı.');
            })
            .catch(function () { alert('Bağlantı hatası. Tekrar deneyin.'); });
    }
    document.getElementById('giKapatBtn').addEventListener('click', function () { kapat(''); });
    document.getElementById('giReconKapatBtn').addEventListener('click', function () {
        var not = document.getElementById('giReconNot').value.trim();
        if (not === '') { alert('Kapatma gerekçesi zorunludur.'); return; }
        kapat(not);
    });
    document.getElementById('giReconVazgecBtn').addEventListener('click', function () {
        ekranGoster(scanSec);
        focusInput();
    });
})();
</script>

<?php render_footer(); ?>