<?php
// =========================================================
// cari_kartlari.php — Cari Kayıt Kartları: master-detail (sol liste + sağ detay/ekstre). YALNIZ GET; yazmalar cari_kaydet.php'de.
//   ?id=N            seçili kart (mobilde liste yerine detay)      ?sekme=ekstre|bilgiler
//   ?bas= &bit= &pb= &tur= &iptal=1   ekstre süzgeci               ?yeni=1  kart modalı açık gelir
//   ?q= &tip= &grup=<slug> &bakiye=borclu|alacakli|sifir &durum=aktif|pasif   (JS'siz süzme; JS açıkken istemci süzer)
//   ?parca=detay|ekstre|kart  kısmi yanıt (header/footer YOK): detay = #crDetay + yenilenen menüler · ekstre = #crPanelEkstre · kart = JSON
// Kapı: is_admin() tabanlı cari_kapi('read'); tablo yoksa şema uyarı kartı. Çekirdek config/cari_core.php, işaretleme config/cari_ui.php.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/cari_core.php';
require_once __DIR__ . '/config/cari_ui.php';
$auth_user = require_login();
if (!is_admin()) { forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.'); }
cari_kapi('read');
cari_no_store();
$pdo = db();
cari_sayfa_kapisi($pdo);

$parca = (string)($_GET['parca'] ?? '');
$selId = max(0, (int)($_GET['id'] ?? 0));
$kart  = $selId > 0 ? cari_kart_oku($pdo, $selId) : null;
$sekme = ($_GET['sekme'] ?? '') === 'bilgiler' ? 'bilgiler' : 'ekstre';

// ── Kısmi yanıtlar (JS master-detail) ──
if ($parca !== '') {
    if ($kart === null) {
        http_response_code(404);
        if ($parca === 'kart') { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'hata' => 'Cari kart bulunamadı.'], JSON_UNESCAPED_UNICODE); exit; }
        echo '<div class="flash flash-error" role="alert">Cari kart bulunamadı.</div>';
        exit;
    }
    if ($parca === 'kart') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'kart' => cari_kart_json($kart)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $E = cari_ekstre_durum($pdo, $kart, $_GET);
    if ($parca === 'ekstre') { echo cari_ekstre_panel_html($pdo, $kart, $E); exit; }
    if ($parca === 'detay') {
        echo cari_detay_html($pdo, $kart, $E, $sekme);
        echo cari_detay_menuleri($kart, cari_kart_hareket_say($pdo, (int)$kart['id']) > 0);
        exit;
    }
    http_response_code(400);
    exit;
}

// ── Liste süzgeci ──
$tip = (string)($_GET['tip'] ?? '');
$F = [
    'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
    'tip' => isset(cari_tipler()[$tip]) ? $tip : '',
    'grup' => mb_substr(trim((string)($_GET['grup'] ?? '')), 0, 100),
    'bakiye' => in_array((string)($_GET['bakiye'] ?? ''), ['borclu', 'alacakli', 'sifir'], true) ? (string)$_GET['bakiye'] : '',
    'durum' => in_array((string)($_GET['durum'] ?? ''), ['aktif', 'pasif'], true) ? (string)$_GET['durum'] : '',
    'kesildi' => false,
];
$filtreli = $F['q'] !== '' || $F['tip'] !== '' || $F['grup'] !== '' || $F['bakiye'] !== '' || $F['durum'] !== '';
$tum = cari_kart_listesi($pdo);
$kartlar = $tum['satirlar'];
if ($filtreli) {
    $sorgu = ['q' => $F['q'], 'tip' => $F['tip'], 'bakiye' => $F['bakiye']];
    if ($F['durum'] !== '') $sorgu['aktif'] = $F['durum'] === 'aktif' ? 1 : 0;
    $L = cari_kart_listesi($pdo, $sorgu);
    $kartlar = $L['satirlar'];
    if ($F['grup'] !== '') $kartlar = array_values(array_filter($kartlar, static fn(array $k): bool => cari_slug((string)($k['grup_adi'] ?? '')) === $F['grup']));
    $F['kesildi'] = $L['kesildi'];
} else {
    $F['kesildi'] = $tum['kesildi'];
}
$genel   = cari_genel_ozet($pdo);
$gruplar = cari_tanim_listesi($pdo, 'grup', true);
$odeme   = array_map(static fn(array $t): string => (string)$t['ad'], cari_tanim_listesi($pdo, 'odeme_sekli', true));

// ── Seçili kart ──
$detay = ''; $menuler = '';
$kilitli = null;
if ($kart !== null) {
    $E = cari_ekstre_durum($pdo, $kart, $_GET);
    $detay = cari_detay_html($pdo, $kart, $E, $sekme);
    $menuler = cari_detay_menuleri($kart, cari_kart_hareket_say($pdo, (int)$kart['id']) > 0);
    if ((int)$kart['aktif'] === 1) {
        $pb = (string)$kart['para_birimi'];
        $b = (int)(cari_bakiye($pdo, (int)$kart['id'])[$pb]['bakiye'] ?? 0);
        $kilitli = $kart + ['bakiye_metin' => cari_tutar_bicim(abs($b), $pb) . ' ' . cari_bakiye_etiketi($b)['metin']];
    }
} else {
    $detay = '<section class="cari-detay" id="crDetay" aria-label="Cari detayı"><div class="cari-detay-bos">Soldan bir cari seçin</div></section>';
}

render_header('Cari Kayıt Kartları');
cari_assets();
render_flash();
?>
<div class="cari cari--tek-ekran" id="cari"<?= ($_GET['yeni'] ?? '') === '1' ? ' data-yeni="kart"' : '' ?>>
  <?= cari_ust_menu('kartlar') ?>
  <div class="cari-ana<?= $kart !== null ? ' cari-ana--detay' : '' ?>">
    <?= cari_liste_kol_html($kartlar, $genel, $gruplar, $F, $kart !== null ? (int)$kart['id'] : null, $filtreli ? count($tum['satirlar']) : count($kartlar)) ?>
    <?= $detay ?>
  </div>
  <div class="cari-menu-ortu" hidden></div>
  <?= $menuler ?>
  <?= cari_modal_hareket(['geri' => $kart !== null ? 'cari_kartlari.php?id=' . (int)$kart['id'] : 'cari_kartlari.php', 'tur' => 'tahsilat', 'kilitli' => $kilitli, 'odeme' => $odeme]) ?>
  <?= cari_modal_kart(['geri' => 'cari_kartlari.php' . ($kart !== null ? '?id=' . (int)$kart['id'] : ''), 'gruplar' => $gruplar, 'kod_oneri' => cari_kod_oner($pdo)]) ?>
  <?= cari_modal_iptal('cari_kartlari.php' . ($kart !== null ? '?id=' . (int)$kart['id'] : '')) ?>
  <?= cari_json_script('crCariVeri', cari_veri_dizisi($tum['satirlar'])) ?>
</div>
<?php
cari_scripts();
render_footer();
