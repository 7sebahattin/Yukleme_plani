<?php
// =========================================================
// pdks_nfc_test.php — Web NFC UYUMLULUK TEŞHİSİ (PDKS)
//
// ⚠ BU BİR ÜRETİM SAYFASI DEĞİLDİR. Tek işi: bu tarayıcının/telefonun
// gerçek personel kartlarını Web NFC (NDEFReader) API'siyle GERÇEKTEN
// okuyup okuyamadığını GÖZLE görünür kılmaktır.
//
//   - Hiçbir veritabanı YAZMASI yoktur (fetch/POST YOK — sayfa tamamen
//     istemci tarafında çalışır, hiçbir şeyi sunucuya göndermez).
//   - Hiçbir kart/personel eşlemesi yapılmaz.
//   - Yalnız MEVCUT Nuverna oturumu + yetkisiyle açılır (yeni auth YOK).
//
// Neden gerekli: Web NFC (NDEFReader) yalnız Android'de Chrome'da, yalnız
// HTTPS'te ve yalnız NDEF okuma API'si üzerinden çalışır. Kartlarınız
// MIFARE Classic/NfcA'dır — NDEF içerik taşıyıp taşımadıkları ve
// serialNumber'ın gerçekte hangi bayt sırasını döndürdüğü ÖLÇÜLMEDEN
// varsayılamaz (Faz 0'daki Android getId() ölçümüyle AYNI gerekçe,
// burada tarayıcı API'si için).
//
// Kanon KARARI BU SAYFADA VERİLMEZ. Burada gösterilen "olası kanonik"
// değerler YALNIZ GÖSTERİMDİR — hiçbiri kaydedilmez, hiçbiri "bu iki
// gösterim aynı karttır" diye otomatik varsayılmaz (bkz. Faz 1 §6a
// düzeltmesi: bayt-tersi ASLA otomatik alias sayılmaz).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks('cards');

$https = is_https();

render_header('Web NFC Teşhis');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
?>

<div class="page-head">
    <h1>🔬 Web NFC Teşhis</h1>
    <div class="page-head-actions">
        <a href="personel_kartlar.php" class="btn btn-ghost">← Kart Yönetimi</a>
    </div>
</div>

<div class="flash flash-error" style="margin-bottom:16px">
    <strong>Bu sayfa hiçbir kayıt yazmaz.</strong> Kart/personel eşlemesi yapmaz,
    veritabanına dokunmaz. Yalnız bu telefonun/tarayıcının gerçek kartları
    Web NFC ile okuyup okuyamadığını göstermek içindir.
</div>

<?php if (!$https): ?>
<div class="flash flash-error">
    ⚠ <strong>Bu sayfa HTTPS üzerinden açılmamış.</strong> Web NFC yalnız
    "güvenli bağlam"da (HTTPS) çalışır — aşağıdaki testler muhtemelen
    "desteklenmiyor" gösterecektir. Adresi <code>https://</code> ile açın.
</div>
<?php endif; ?>

<div class="card" style="padding:18px 20px;margin-bottom:20px">
    <h2 style="margin-top:0">1) Ortam Bilgisi</h2>
    <table class="data-table" style="margin-top:8px">
        <tbody>
            <tr><td style="width:220px" class="muted">Sayfa HTTPS (sunucu tarafı)</td>
                <td><span class="pdks-badge <?= $https ? 'pdks-badge-aktif' : 'pdks-badge-iptal' ?>"><?= $https ? '✓ Evet' : '✗ Hayır' ?></span></td></tr>
            <tr><td class="muted">Güvenli bağlam (tarayıcı — <code>isSecureContext</code>)</td>
                <td id="nfcSecureCtx">—</td></tr>
            <tr><td class="muted"><code>NDEFReader</code> tarayıcıda var mı</td>
                <td id="nfcSupport">—</td></tr>
            <tr><td class="muted">User-Agent</td>
                <td id="nfcUA" style="font-size:.78rem;word-break:break-all">—</td></tr>
        </tbody>
    </table>
</div>

<div class="card" style="padding:18px 20px;margin-bottom:20px">
    <h2 style="margin-top:0">2) Bilinen Test Kartı (karşılaştırma referansı)</h2>
    <table class="data-table">
        <tbody>
            <tr><td class="muted" style="width:220px">USB okuyucu (ondalık)</td><td class="pdks-uid">631799511</td></tr>
            <tr><td class="muted">Kanonik (USB'den, sunucu onaylı)</td><td class="pdks-uid" style="color:var(--primary)">25A87ED7</td></tr>
            <tr><td class="muted">Bayt-tersi (yalnız referans — otomatik eşleşme YOK)</td><td class="pdks-uid muted">D77EA825</td></tr>
        </tbody>
    </table>
    <p class="muted" style="font-size:.85rem;margin-bottom:0">
        Aşağıda <strong>aynı fiziksel kartı</strong> Web NFC ile okutup
        <code>serialNumber</code>'ın bu değerlerden hangisiyle örtüştüğünü
        (veya hiçbiriyle örtüşmediğini) gözlemleyin.
    </p>
</div>

<div class="card" style="padding:18px 20px;margin-bottom:20px">
    <h2 style="margin-top:0">3) Okuma Testi</h2>
    <button type="button" id="nfcStartBtn" class="btn btn-primary btn-lg" disabled>📡 NFC OKUMAYI BAŞLAT</button>
    <div id="nfcLiveStatus" class="pdks-scan-status" style="margin-top:10px"></div>

    <div id="nfcResultBox" hidden style="margin-top:18px">
        <h3 style="font-size:.95rem;margin-bottom:8px">Son Okuma</h3>
        <table class="data-table">
        <tbody>
            <tr><td class="muted" style="width:220px"><code>event.serialNumber</code> (ham)</td><td id="rSerial" class="pdks-uid"></td></tr>
            <tr><td class="muted">Ayraçsız, büyük harf</td><td id="rStripped" class="pdks-uid"></td></tr>
            <tr><td class="muted">Olası kanonik — AYNI SIRA <span class="muted">(yalnız gösterim)</span></td><td id="rSame" class="pdks-uid" style="color:var(--primary)"></td></tr>
            <tr><td class="muted">Olası kanonik — TERS SIRA <span class="muted">(yalnız gösterim, otomatik alias DEĞİL)</span></td><td id="rRev" class="pdks-uid"></td></tr>
            <tr><td class="muted">Bilinen kartla eşleşme?</td><td id="rMatch"></td></tr>
            <tr><td class="muted">NDEF mesajı</td><td id="rMsg" class="muted"></td></tr>
        </tbody>
        </table>
    </div>

    <div id="nfcErrorBox" hidden style="margin-top:14px">
        <div class="flash flash-error">
            <strong id="rErrName"></strong>
            <div id="rErrMsg" style="margin-top:4px;font-size:.88rem"></div>
        </div>
    </div>
</div>

<div class="card" style="padding:18px 20px;margin-bottom:20px">
    <h2 style="margin-top:0">4) Okuma Geçmişi (bu oturum)</h2>
    <p class="muted" id="nfcLogEmpty">Henüz okuma yok.</p>
    <div class="table-wrap" id="nfcLogWrap" hidden>
    <table class="data-table pdks-history-table">
        <thead><tr><th>Saat</th><th>serialNumber (ham)</th><th>Ayraçsız</th><th>Sonuç</th></tr></thead>
        <tbody id="nfcLogBody"></tbody>
    </table>
    </div>
</div>

<?php pdks_nfc_oku_js();   /* ortak Web NFC okuma yolu — giris_cikis.php ile AYNI kod */ ?>

<script>
(function () {
    'use strict';

    var startBtn   = document.getElementById('nfcStartBtn');
    var liveStatus = document.getElementById('nfcLiveStatus');
    var resultBox  = document.getElementById('nfcResultBox');
    var errorBox   = document.getElementById('nfcErrorBox');
    var logBody    = document.getElementById('nfcLogBody');
    var logWrap    = document.getElementById('nfcLogWrap');
    var logEmpty   = document.getElementById('nfcLogEmpty');

    // ── 1) Ortam bilgisi — sayfa açılır açılmaz göster ──────────────
    document.getElementById('nfcUA').textContent = navigator.userAgent || '(alınamadı)';

    var secure = !!window.isSecureContext;
    document.getElementById('nfcSecureCtx').innerHTML =
        '<span class="pdks-badge ' + (secure ? 'pdks-badge-aktif' : 'pdks-badge-iptal') + '">' +
        (secure ? '✓ Evet' : '✗ Hayır') + '</span>';

    var destekli = ('NDEFReader' in window);
    document.getElementById('nfcSupport').innerHTML =
        '<span class="pdks-badge ' + (destekli ? 'pdks-badge-aktif' : 'pdks-badge-iptal') + '">' +
        (destekli ? '✓ Var' : '✗ Yok') + '</span>';

    if (destekli && secure) {
        startBtn.disabled = false;
        liveStatus.textContent = 'Hazır — butona basıp kartı telefonun arkasına yaklaştırın.';
        liveStatus.className = 'pdks-scan-status';
    } else if (!secure) {
        liveStatus.textContent = 'Güvenli bağlam (HTTPS) yok — Web NFC bu sayfada çalışamaz.';
        liveStatus.className = 'pdks-scan-status err';
    } else {
        liveStatus.textContent = 'Bu tarayıcı/cihaz Web NFC (NDEFReader) desteklemiyor. ' +
            '(Yalnız Android + Chrome\'da, masaüstünde veya iOS\'ta ÇALIŞMAZ.)';
        liveStatus.className = 'pdks-scan-status err';
    }

    // ── Yardımcılar — YALNIZ GÖSTERİM, hiçbir şey kaydedilmez/gönderilmez ──
    function ayracsizBuyukHarf(s) {
        return String(s || '').toUpperCase().replace(/[^0-9A-F]/g, '');
    }
    function baytTersi(hex) {
        if (hex.length % 2 !== 0) return '(tek sayıda hane — ters çevrilemez)';
        var out = '';
        for (var i = hex.length - 2; i >= 0; i -= 2) out += hex.substr(i, 2);
        return out;
    }
    function logEkle(saat, ham, stripped, sonuc) {
        logWrap.hidden = false; logEmpty.hidden = true;
        var tr = document.createElement('tr');
        tr.innerHTML = '<td class="muted">' + saat + '</td>' +
                        '<td class="pdks-uid">' + ham + '</td>' +
                        '<td class="pdks-uid">' + stripped + '</td>' +
                        '<td>' + sonuc + '</td>';
        logBody.insertBefore(tr, logBody.firstChild);
    }

    var BILINEN = '25A87ED7';
    var BILINEN_TERS = 'D77EA825';

    function okumaGoster(ev) {
        errorBox.hidden = true;
        resultBox.hidden = false;

        var ham = (ev.serialNumber != null) ? String(ev.serialNumber) : '(serialNumber boş/yok)';
        var stripped = ayracsizBuyukHarf(ham);
        var ters = stripped ? baytTersi(stripped) : '';

        document.getElementById('rSerial').textContent   = ham;
        document.getElementById('rStripped').textContent = stripped || '—';
        document.getElementById('rSame').textContent     = stripped || '—';
        document.getElementById('rRev').textContent      = ters || '—';

        var eslesme;
        if (stripped === BILINEN) {
            eslesme = '<span class="pdks-badge pdks-badge-aktif">✓ AYNI SIRA ile eşleşiyor (' + BILINEN + ')</span>';
        } else if (stripped === BILINEN_TERS || ters === BILINEN) {
            eslesme = '<span class="pdks-badge pdks-badge-degistirildi">⚠ TERS SIRA ile eşleşiyor</span>';
        } else {
            eslesme = '<span class="pdks-badge pdks-badge-iptal">✗ Bilinen kartla eşleşmiyor (farklı kart olabilir)</span>';
        }
        document.getElementById('rMatch').innerHTML = eslesme;

        var msgInfo = '(NDEF mesajı yok / boş)';
        try {
            if (ev.message && ev.message.records && ev.message.records.length) {
                msgInfo = ev.message.records.length + ' kayıt — tür: ' +
                    ev.message.records.map(function (r) { return r.recordType; }).join(', ');
            }
        } catch (e) { msgInfo = '(okunamadı: ' + e.message + ')'; }
        document.getElementById('rMsg').textContent = msgInfo;

        var saat = new Date().toLocaleTimeString('tr-TR');
        logEkle(saat, ham, stripped || '—', eslesme);

        liveStatus.textContent = '✓ Okuma alındı. Başka bir kart deneyebilir veya aynı kartı tekrar okutabilirsiniz.';
        liveStatus.className = 'pdks-scan-status ok';
    }

    function hataGoster(ad, mesaj) {
        resultBox.hidden = true;
        errorBox.hidden = false;
        document.getElementById('rErrName').textContent = ad;
        document.getElementById('rErrMsg').textContent = mesaj;
        liveStatus.textContent = '✗ Hata oluştu — aşağıya bakın.';
        liveStatus.className = 'pdks-scan-status err';
        logEkle(new Date().toLocaleTimeString('tr-TR'), '—', '—',
            '<span class="pdks-badge pdks-badge-iptal">HATA: ' + ad + '</span>');
    }

    var tarayiciAktif = false;
    startBtn.addEventListener('click', function () {
        // ⚠ NFC izni YALNIZ kullanıcı etkileşimi (bu tıklama) İÇİNDE istenir —
        // sayfa açılışında veya arka planda OTOMATİK istenmez.
        //
        // ⚠ Okuma dizisinin kendisi artık PdksNfcOku.baslat() içindedir
        // (config/pdks.php) — giris_cikis.php de AYNI koddan geçer, böylece
        // iki sayfa ayrışamaz. BU SAYFANIN DAVRANIŞI DEĞİŞMEDİ: aynı sıra
        // (new NDEFReader → reading → readingerror → scan()), aynı çağrılar,
        // aynı arayüz güncellemeleri. Bu sayfa kanıt sayfasıdır — davranış
        // değiştirmeyin.
        if (tarayiciAktif) return;
        liveStatus.textContent = 'İzin isteniyor / dinleme başlatılıyor…';
        liveStatus.className = 'pdks-scan-status';

        PdksNfcOku.baslat({
            onOkuma: okumaGoster,
            onOkumaHatasi: function () {
                hataGoster('NDEFReadingError', 'Etiket okunamadı (readingerror olayı). Kartı tekrar, telefonun NFC antenine (genelde arka kamera yakını) daha yakın tutarak deneyin.');
            },
            onBasladi: function () {
                tarayiciAktif = true;
                startBtn.textContent = '📡 DİNLENİYOR — kartı yaklaştırın';
                startBtn.disabled = true;
                liveStatus.textContent = 'Dinleniyor… kartı telefonun arkasına yaklaştırın.';
            },
            onHata: hataGoster
        });
    });
})();
</script>

<?php render_footer(); ?>
