<?php
// =========================================================
// cek_dekontu.php — Çek Dekontu: liste + sağ panel + bordro formu (YALNIZ GET; yazmalar cek_kaydet.php'de).
//   ?yon=alinan|verilen · ?id=N evrak detayı (panel açık) · ?yeni=1&yon= bordro formu · süzgeç: ara, durum (varsayılan acik), donem (+bas, bit), cari, sayfa
//   ?parca=detay&id=N · ?parca=cari_ozet&cari=N kısmi yanıt (header/footer YOK)
// Kapı: is_admin() tabanlı ceksenet_kapi('read'); tablo yoksa şema uyarı kartı. Gövde config/ceksenet_ui.php ceksenet_sayfa_govde().
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/ceksenet_core.php';
require_once __DIR__ . '/config/ceksenet_ui.php';
$auth_user = require_login();
if (!is_admin()) { forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.'); }
ceksenet_kapi('read');
ceksenet_no_store();
$pdo = db();
ceksenet_sayfa_kapisi($pdo);

if (ceksenet_parca($pdo, 'cek')) exit;   // kısmi yanıt

render_header('Çek Dekontu');
ceksenet_assets();
render_flash();
echo ceksenet_sayfa_govde($pdo, 'cek');
ceksenet_scripts();
render_footer();
