<?php
// =========================================================
// giris_cikis.php — Personel Giriş / Çıkış (PDKS Giriş-Çıkış Faz)
//
// Kiosk benzeri, tek ekranlı akış: önce GİRİŞ ya da ÇIKIŞ modu AÇIKÇA
// seçilir (yön istemciden/son olaydan ASLA otomatik tahmin EDİLMEZ),
// sonra o mod içinde art arda kart okutulur — her geçerli okuma seçili
// yönle kaydedilir, mod kullanıcı değiştirene kadar SABİT kalır.
//
// Kart/personel kimliği ve yön kaydı HER ZAMAN sunucuda çözülür
// (pdks_devam_kaydet() → config/pdks.php); istemci yalnız ham okuma +
// kaynak (usb_decimal | web_nfc) + seçili modu gönderir, hiçbir kimlik
// iddiasında bulunmaz.
//
// Cihaz/Android/token/heartbeat/offline-kuyruk/vardiya/bordro/otomatik
// yön tahmini YOK — bilerek. Bkz. docs/PDKS_GIRIS_CIKIS.md.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks('scan');
pdks_migrate();

$pdo  = db();
$base = base_url();

// ── Kayıt ucu — SAYFANIN İÇİNDE, JSON. İstemci ham okuma + kaynak + seçili
// mod gönderir; kart/personel çözümü ve yön kaydı TAMAMEN sunucudadır. ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['ajax'] ?? '') === 'kaydet') {
    header('Content-Type: application/json; charset=utf-8');
    $govde = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($govde)) $govde = [];

    csrf_check($govde['csrf'] ?? null);
    require_pdks('scan');   // savunma derinliği — sayfa girişindeki kontrolün tekrarı

    $hamUid    = trim((string)($govde['ham_uid'] ?? ''));
    $kaynak    = trim((string)($govde['kaynak'] ?? ''));
    $eventType = trim((string)($govde['event_type'] ?? ''));

    if ($hamUid === '') {
        echo json_encode(['ok' => false, 'kod' => 'bos', 'hata' => 'Kart okutulmadı.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!in_array($kaynak, PDKS_UID_KAYNAKLARI, true)) {
        echo json_encode(['ok' => false, 'kod' => 'gecersiz_kaynak', 'hata' => 'Geçersiz okuma kaynağı.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!array_key_exists($eventType, pdks_event_turleri())) {
        echo json_encode(['ok' => false, 'kod' => 'gecersiz_yon', 'hata' => 'Önce GİRİŞ veya ÇIKIŞ modunu seçin.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sonuc = pdks_devam_kaydet($hamUid, $kaynak, $eventType, (int)$auth_user['id'], $pdo);

    if (!$sonuc['ok']) {
        echo json_encode([
            'ok'   => false,
            'kod'  => $sonuc['kod'] ?? 'hata',
            'hata' => $sonuc['hata'] ?? 'Kayıt yapılamadı.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $emp = $sonuc['employee'];
    echo json_encode([
        'ok'         => true,
        'event_type' => $eventType,
        'employee'   => [
            'full_name'    => (string)$emp['full_name'],
            'personnel_no' => $emp['personnel_no'] ?? null,
            'department'   => (string)($emp['department'] ?? ''),
            'photo_html'   => pdks_avatar_html(
                (string)$emp['full_name'],
                $emp['photo_file'] ?? null,
                $emp['photo_updated_at'] ?? null,
                $base,
                'pdks-avatar pdks-avatar-xl'
            ),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

render_header('Giriş / Çıkış');
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head">
    <h1>🚪 Giriş / Çıkış</h1>
    <div class="page-head-actions">
        <a href="personel.php" class="btn btn-ghost">👤 Personeller</a>
    </div>
</div>

<input type="hidden" id="gcCsrf" value="<?= h(csrf_token()) ?>">

<div class="pdks-kiosk">

    <!-- ── 1) Mod seçimi ─────────────────────────────────── -->
    <div id="gcModeSec" class="pdks-kiosk-modesec">
        <p class="muted" style="text-align:center;max-width:420px">
            Başlamadan önce modu seçin. Seçtiğiniz mod, siz <strong>değiştirene kadar</strong>
            her okutmada kullanılır — art arda birçok personeli aynı modda okutabilirsiniz.
        </p>
        <button type="button" class="pdks-kiosk-modebtn pdks-kiosk-modebtn-giris" data-gc-mode="GIRIS">
            ✅ GİRİŞ MODU
        </button>
        <button type="button" class="pdks-kiosk-modebtn pdks-kiosk-modebtn-cikis" data-gc-mode="CIKIS">
            🚪 ÇIKIŞ MODU
        </button>
    </div>

    <!-- ── 2) Tarama ekranı ──────────────────────────────── -->
    <div id="gcScanSec" class="pdks-kiosk-scan" hidden>
        <span id="gcModeBadge" class="pdks-kiosk-mode-badge"></span>
        <div class="pdks-kiosk-scan-icon" aria-hidden="true">📇</div>
        <div class="pdks-kiosk-scan-text">KARTINIZI OKUTUN</div>
        <div class="pdks-kiosk-scan-hint muted">USB okuyucuya okutun<span id="gcNfcHint"></span></div>

        <div id="gcNfcBtnWrap" hidden>
            <button type="button" id="gcNfcBtn" class="btn btn-lg">📡 NFC İLE OKUT</button>
        </div>

        <!-- USB HID girişi — görsel olarak gizli ama HER ZAMAN odaklı -->
        <input type="text" id="gcScanInput" class="pdks-kiosk-hidden-input"
               inputmode="numeric" autocomplete="off" aria-hidden="true" tabindex="-1">

        <button type="button" class="btn btn-ghost" id="gcModeChange" style="margin-top:24px">↩ Modu Değiştir</button>

        <!-- ── Sonuç overlay'i (başarı/hata) ────────────────── -->
        <div id="gcResult" class="pdks-kiosk-result" hidden>
            <div id="gcResultInner"></div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var csrf = document.getElementById('gcCsrf').value;

    var modeSec   = document.getElementById('gcModeSec');
    var scanSec   = document.getElementById('gcScanSec');
    var modeBadge = document.getElementById('gcModeBadge');
    var scanInput = document.getElementById('gcScanInput');
    var resultBox = document.getElementById('gcResult');
    var resultInner = document.getElementById('gcResultInner');
    var nfcBtnWrap = document.getElementById('gcNfcBtnWrap');
    var nfcBtn     = document.getElementById('gcNfcBtn');
    var nfcHint    = document.getElementById('gcNfcHint');

    var MOD_ETIKET = { GIRIS: '✅ GİRİŞ MODU', CIKIS: '🚪 ÇIKIŞ MODU' };
    var MOD_SINIF  = { GIRIS: 'pdks-kiosk-mode-badge-giris', CIKIS: 'pdks-kiosk-mode-badge-cikis' };

    var currentMode = null;   // 'GIRIS' | 'CIKIS' — İSTEMCİDE ASLA otomatik seçilmez
    var busy = false;
    var resultTimer = null;

    function focusInput() {
        try { scanInput.focus({ preventScroll: true }); } catch (e) { try { scanInput.focus(); } catch (e2) {} }
    }

    function girModuSec(mod) {
        currentMode = mod;
        modeBadge.textContent = MOD_ETIKET[mod];
        modeBadge.className = 'pdks-kiosk-mode-badge ' + MOD_SINIF[mod];
        modeSec.hidden = true;
        scanSec.hidden = false;
        scanInput.value = '';
        resultBox.hidden = true;
        focusInput();
    }

    document.querySelectorAll('[data-gc-mode]').forEach(function (btn) {
        btn.addEventListener('click', function () { girModuSec(btn.getAttribute('data-gc-mode')); });
    });
    document.getElementById('gcModeChange').addEventListener('click', function () {
        currentMode = null;
        scanSec.hidden = true;
        modeSec.hidden = false;
    });

    // Sayfa herhangi bir yere tıklanınca da odak USB kutusuna dönsün
    // (scan ekranı görünürken) — okuyucu her zaman yazabilsin diye.
    document.addEventListener('click', function (e) {
        if (!scanSec.hidden && e.target !== nfcBtn && e.target !== document.getElementById('gcModeChange')) {
            focusInput();
        }
    });

    function gosterSonuc(html, sinif, sureMs) {
        clearTimeout(resultTimer);
        resultInner.innerHTML = html;
        resultBox.className = 'pdks-kiosk-result ' + sinif;
        resultBox.hidden = false;
        resultTimer = setTimeout(function () {
            resultBox.hidden = true;
            focusInput();
        }, sureMs);
    }

    function basariGoster(d) {
        var emp = d.employee || {};
        var baslik = d.event_type === 'GIRIS' ? 'GİRİŞ KAYDEDİLDİ' : 'ÇIKIŞ KAYDEDİLDİ';
        var altBilgi = [];
        if (emp.personnel_no) altBilgi.push(emp.personnel_no);
        if (emp.department) altBilgi.push(emp.department);
        gosterSonuc(
            '<div class="pdks-kiosk-result-icon">✓</div>' +
            (emp.photo_html || '') +
            '<div class="pdks-kiosk-result-name">' + escHtml(emp.full_name || '') + '</div>' +
            (altBilgi.length ? '<div class="pdks-kiosk-result-sub">' + escHtml(altBilgi.join(' · ')) + '</div>' : '') +
            '<div class="pdks-kiosk-result-msg">' + baslik + '</div>',
            'pdks-kiosk-result-ok', 1600
        );
    }

    function hataGoster(mesaj) {
        gosterSonuc(
            '<div class="pdks-kiosk-result-icon">✕</div>' +
            '<div class="pdks-kiosk-result-msg">' + escHtml(mesaj || 'Kayıt yapılamadı.') + '</div>',
            'pdks-kiosk-result-err', 2000
        );
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    function kaydet(hamUid, kaynak) {
        if (busy || !currentMode) return;
        var deger = String(hamUid || '').trim();
        if (deger === '') return;
        busy = true;
        fetch('giris_cikis.php?ajax=kaydet', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ csrf: csrf, ham_uid: deger, kaynak: kaynak, event_type: currentMode })
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                busy = false;
                if (d && d.ok) basariGoster(d); else hataGoster(d && d.hata);
                focusInput();
            })
            .catch(function () {
                busy = false;
                hataGoster('Bağlantı hatası. Tekrar deneyin.');
                focusInput();
            });
    }

    // ── USB HID girişi ────────────────────────────────────
    // Yalnız rakam kabul eder (kaynak daima usb_decimal). Enter'ı yakalar
    // ama okuyucunun Enter göndermesi ZORUNLU değildir — kısa bir yazma
    // duraklamasından sonra da otomatik gönderilir (debounce).
    var usbTimer = null;
    scanInput.addEventListener('input', function () {
        var temiz = scanInput.value.replace(/[^0-9]/g, '');
        if (temiz !== scanInput.value) scanInput.value = temiz;
        clearTimeout(usbTimer);
        if (temiz === '') return;
        usbTimer = setTimeout(function () {
            var v = scanInput.value;
            scanInput.value = '';
            kaydet(v, 'usb_decimal');
        }, 350);
    });
    scanInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(usbTimer);
            var v = scanInput.value;
            scanInput.value = '';
            if (v.trim() !== '') kaydet(v, 'usb_decimal');
        }
    });

    // ── Web NFC girişi ─────────────────────────────────────
    // ⚠ Bayt-tersi dönüşümü BURADA YAPILMAZ — ham serialNumber olduğu gibi
    // sunucuya (kaynak=web_nfc ile) gönderilir; kanonikleştirme HER ZAMAN
    // sunucuda pdks_uid_from_web_nfc() ile yapılır (TEK OTORİTE — bkz.
    // config/pdks.php "UID NORMALİZASYONU" bölümü).
    var nfcDestekli = ('NDEFReader' in window) && !!window.isSecureContext;
    var nfcListening = false;
    if (nfcDestekli) {
        nfcBtnWrap.hidden = false;
        nfcHint.textContent = ' veya NFC ile telefonun arkasına yaklaştırın';
        nfcBtn.addEventListener('click', function () {
            if (nfcListening) return;
            var ndef = new NDEFReader();
            ndef.addEventListener('reading', function (ev) {
                var ham = (ev.serialNumber != null) ? String(ev.serialNumber) : '';
                if (ham !== '') kaydet(ham, 'web_nfc');
            });
            ndef.addEventListener('readingerror', function () {
                hataGoster('NFC okuma hatası — kartı tekrar yaklaştırın.');
            });
            ndef.scan().then(function () {
                nfcListening = true;
                nfcBtn.textContent = '📡 NFC DİNLENİYOR…';
                nfcBtn.disabled = true;
            }).catch(function (err) {
                hataGoster('NFC başlatılamadı: ' + (err && err.message ? err.message : String(err)));
            });
        });
    }
})();
</script>

<?php render_footer(); ?>
