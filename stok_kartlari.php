<?php
// =========================================================
// stok_kartlari.php — Stok Kartları: master-detail (sol liste + sağ detay/hareketler). YALNIZ GET; yazmalar stok_kaydet.php'de.
//   ?id=N            seçili kart (mobilde liste yerine detay)            ?yeni=1  kart modalı açık gelir
//   ?ara= &tip= &grup=<id> &durum=aktif|pasif|tumu|kritik   (sunucuda `hidden` ile süzülür; JS açıkken istemci süzer)
//   ?parca=detay     kısmi yanıt (header/footer YOK): #stDetay bölümü (JS master-detail)
//   v611: ek şema hazırsa (stok_ek_hazir) ambalaj tipi · birim dara · HKS ürün alanları + hızlı eklenen kart rengi; değilse yöneticiye kurulum notu.
// Kapı: muhasebe_sayfa_can (Roller; varsayılan kapalı, yönetici her zaman) → stok_kapi('read'); tablo yoksa şema uyarı kartı. Çekirdek config/stok_core.php, işaretleme config/stok_ui.php.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/stok_core.php';
require_once __DIR__ . '/config/stok_ui.php';
require_once __DIR__ . '/config/hizli_kart.php';      // v611 Faz 2: ortak Hızlı Ekle (stok türü)
require_once __DIR__ . '/config/hizli_kart_ui.php';
$auth_user = require_login();
if (!muhasebe_sayfa_can('stok_kartlari')) { forbidden(); }
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
$ekHazir = stok_ek_hazir($pdo);   // v611: ambalaj tipi · birim dara · HKS ürün · hızlı bayrağı (kolonlar yoksa alanlar gizli)

if ($kart !== null) {
    $detay = stok_detay_html($pdo, $kart);
} else {
    $detay = '<section class="st-detay" id="stDetay" aria-label="Stok kartı detayı"><div class="st-detay-bos">'
        . ($selId > 0 ? 'Stok kartı bulunamadı. Soldan bir kart seçin' : 'Soldan bir stok kartı seçin') . '</div></section>';
}

// v611 Faz 2: "⚡ Hızlı ekle" — yalnız stok oluşturma izni + ek şema hazırsa (aksi düğme/pencere çizilmez). Başarıda yeni karta gidilir (hızlı renkli satır).
$hkSecenek = hizli_kart_secenekler($pdo, ['stok']);
$hkDugme = hizli_kart_dugme_html('stok', ['git' => 'stok_kartlari.php?id={id}'], $pdo);

render_header('Stok Kartları');
stok_assets();
render_flash();
?>
<div class="stok st--tek-ekran" id="stok"<?= ($_GET['yeni'] ?? '') === '1' ? ' data-yeni="kart"' : '' ?>>
  <?= stok_ust_menu('kartlar') ?>
  <?= !$ekHazir && is_admin() ? '<p class="st-not st-ek-kurulum" role="note">' . h(STOK_EK_SEMA_MESAJ) . ' <a href="migrate.php#stok-ek">Şema Migrasyon</a></p>' : '' ?>
  <div class="st-ana<?= $kart !== null ? ' st-ana--detay' : '' ?>">
    <?= stok_liste_kol_html($L['satirlar'], $gruplar, $F, $kart !== null ? (int)$kart['id'] : null, $L['kesildi'], $hkDugme) ?>
    <?= $detay ?>
  </div>
  <?= stok_modal_kart(['geri' => 'stok_kartlari.php' . ($kart !== null ? '?id=' . (int)$kart['id'] : ''), 'gruplar' => $gruplar, 'kod_oneri' => stok_kod_oner($pdo),
      'ek' => $ekHazir, 'ambalajlar' => $ekHazir ? stok_tanim_listesi($pdo, 'ambalaj_tip') : [], 'hks' => $ekHazir ? stok_hks_urunler() : null, 'yonetici' => is_admin()]) ?>
</div>
<?php
stok_scripts();
echo hizli_kart_bilesen_html($hkSecenek);   // v611 Faz 2: ortak pencere (sayfa betiğinden SONRA; kendi CSS/JS'i içinde)
render_footer();
