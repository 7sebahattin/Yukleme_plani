<?php
// =========================================================
// stok_kartlari.php — Stok Kartları: master-detail (sol liste + sağ detay/hareketler). YALNIZ GET; yazmalar stok_kaydet.php'de.
//   ?id=N            seçili kart (mobilde liste yerine detay)            ?yeni=1  kart modalı açık gelir
//   ?ara= &tip= &grup=<id> &durum=aktif|pasif|tumu|kritik   (sunucuda `hidden` ile süzülür; JS açıkken istemci süzer)
//   ?parca=detay     kısmi yanıt (header/footer YOK): #stDetay bölümü (JS master-detail)
// Kapı: is_admin() tabanlı stok_kapi('read'); tablo yoksa şema uyarı kartı. Çekirdek config/stok_core.php, işaretleme config/stok_ui.php.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/stok_core.php';
require_once __DIR__ . '/config/stok_ui.php';
$auth_user = require_login();
if (!is_admin()) { forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.'); }
stok_kapi('read');
stok_no_store();
$pdo = db();
stok_sayfa_kapisi($pdo);

$parca = (string)($_GET['parca'] ?? '');
$selId = max(0, (int)($_GET['id'] ?? 0));
$kart  = $selId > 0 ? stok_kart_oku($pdo, $selId) : null;

// ── Kısmi yanıt (JS master-detail) ──
if ($parca !== '') {
    if ($parca !== 'detay') { http_response_code(400); exit; }
    if ($kart === null) { http_response_code(404); echo '<div class="flash flash-error" role="alert">Stok kartı bulunamadı.</div>'; exit; }
    echo stok_detay_html($pdo, $kart);
    exit;
}

// ── Liste süzgeci (tüm kartlar basılır; eşleşmeyenler `hidden`) ──
$tip = (string)($_GET['tip'] ?? '');
$durum = (string)($_GET['durum'] ?? 'aktif');
$F = [
    'ara' => mb_substr(trim((string)($_GET['ara'] ?? '')), 0, 100),
    'tip' => isset(stok_kart_tipleri()[$tip]) ? $tip : '',
    'grup' => max(0, (int)($_GET['grup'] ?? 0)),
    'durum' => in_array($durum, ['aktif', 'pasif', 'tumu', 'kritik'], true) ? $durum : 'aktif',
];
$L = stok_kart_listesi($pdo);
$gruplar = stok_tanim_listesi($pdo, 'grup', true);

if ($kart !== null) {
    $detay = stok_detay_html($pdo, $kart);
} else {
    $detay = '<section class="st-detay" id="stDetay" aria-label="Stok kartı detayı"><div class="st-detay-bos">'
        . ($selId > 0 ? 'Stok kartı bulunamadı. Soldan bir kart seçin' : 'Soldan bir stok kartı seçin') . '</div></section>';
}

render_header('Stok Kartları');
stok_assets();
render_flash();
?>
<div class="stok st--tek-ekran" id="stok"<?= ($_GET['yeni'] ?? '') === '1' ? ' data-yeni="kart"' : '' ?>>
  <?= stok_ust_menu('kartlar') ?>
  <div class="st-ana<?= $kart !== null ? ' st-ana--detay' : '' ?>">
    <?= stok_liste_kol_html($L['satirlar'], $gruplar, $F, $kart !== null ? (int)$kart['id'] : null, $L['kesildi']) ?>
    <?= $detay ?>
  </div>
  <?= stok_modal_kart(['geri' => 'stok_kartlari.php' . ($kart !== null ? '?id=' . (int)$kart['id'] : ''), 'gruplar' => $gruplar, 'kod_oneri' => stok_kod_oner($pdo)]) ?>
</div>
<?php
stok_scripts();
render_footer();
