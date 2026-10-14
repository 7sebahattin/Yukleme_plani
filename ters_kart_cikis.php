<?php
// =========================================================
// ters_kart_cikis.php — Ters Kart Takip · C) HIZLI ÇIKIŞ (v354)
//
// Çavuş seçmeden tek ekrandan çıkış: kart okutulur, sunucu kartın AÇIK
// dönemini bulur ve çıkışı GERÇEK sunucu saatiyle yazar. İKİNCİ BİR ÇIKIŞ
// MOTORU YOK — pdks_ters_cikis_kaydet() mevcut pdks_gunluk_ortak_cikis_kaydet()'i
// çağırır (kart kilidi, yanlış çavuş/depo reddi, mükerrer çıkış koruması orada).
// Ters ve normal sistemle girmiş kartların ikisi de buradan çıkabilir.
// Yetki: attendance.daily_scan. Aktif depo zorunlu.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_ters.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
$y = pdks_ters_require(['tarama']);

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
$base = base_url();
$faz8a = pdks_gunluk_faz8a_sema_hazir($pdo);

if (($_GET['ajax'] ?? '') === 'cikis') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') pdks_ters_json(['ok' => false, 'hata' => 'Yalnız POST.'], 405);
    $g = pdks_ters_json_govde();
    // istemciden YALNIZ ham UID + kaynak alınır; mesai/çavuş sunucuda çözülür.
    pdks_ters_json(pdks_ters_cikis_kaydet(pdks_ters_metin($g['ham_uid'] ?? ''), pdks_ters_metin($g['kaynak'] ?? ''), (int)$auth_user['id'], $pdo));
}

$mesailer = $faz8a ? pdks_gunluk_ortak_cikis_mesailer(null, $pdo) : [];

render_header('Hızlı Çıkış — Ters Kart Takip');
pdks_ters_varliklar($base);
render_flash();
?>
<div class="tk-sayfa" data-tk-sayfa="cikis">
<div class="page-head">
    <h1>🚪 Hızlı Çıkış</h1>
    <div class="page-head-actions">
        <a href="ters_kart.php" class="btn btn-geri">← Ters Kart Takip</a>
    </div>
</div>
<?php pdks_ters_sekmeler('cikis', $y); ?>

<?php if (!$faz8a): ?>
    <div class="tk-kutu tk-kutu-bilgi">Hızlı çıkış için Faz 8A şeması gerekir (migrate.php).</div>
<?php else: ?>
<div class="tk-cikis-duzen">
    <div>
        <div class="tk-tara tk-tara-cikis" role="group" aria-label="Kart okutma alanı">
            <div class="tk-tara-ikon" aria-hidden="true">🚪</div>
            <div class="tk-tara-metin"><strong>Geri gelen kartı okutun</strong><br><span class="muted">Çavuş seçmeye gerek yok — farklı çavuşların kartları art arda okutulabilir. Çıkış saati okutma anıdır.</span></div>
            <input type="text" id="tkScanInput" class="tk-gizli-input" inputmode="none" autocomplete="off" aria-label="Kart okuyucu girişi">
            <button type="button" id="tkNfcBtn" class="btn" hidden>📶 NFC ile oku</button>
            <div id="tkNfcDurum" class="muted tk-kucuk"></div>
        </div>
        <div id="tkSonuc" class="tk-sonuc tk-sonuc-buyuk" role="status" aria-live="polite" hidden></div>
    </div>
    <aside class="tk-blok tk-icerde">
        <h3>İçeride <span class="tk-rozet tk-rozet-mavi" id="tkIcerdeToplam">0</span></h3>
        <div id="tkIcerdeListe" class="tk-icerde-liste"></div>
        <p class="muted tk-kucuk">Aktif depodaki açık mesailer. Mesaiyi kapatma çavuş bazında <a href="gunluk_isci_giris_cikis.php">Giriş-Çıkış</a> ekranından yapılır.</p>
    </aside>
</div>
<script type="application/json" id="tkMesailer"><?= json_encode($mesailer, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
</div>
<?php
pdks_nfc_oku_js();
pdks_ters_script($base);
render_footer();
