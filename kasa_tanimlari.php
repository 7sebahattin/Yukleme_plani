<?php
// =========================================================
// kasa_tanimlari.php — Kasa Tanımları: Kasalar (nakit / banka) · Gelir Kalemleri · Gider Kalemleri. YALNIZ GET; yazmalar kasa_kaydet.php'de.
//   ?sekme=kasa|gelir|gider · ?yeni=1 (kasa sekmesinde yeni kasa modalı)
// Kapı: is_admin() tabanlı kasa_kapi('read'); tablo yoksa şema uyarı kartı. Gövde config/kasa_ui.php kasa_tanimlari_govde().
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/kasa_core.php';
require_once __DIR__ . '/config/kasa_ui.php';
$auth_user = require_login();
if (!is_admin()) { forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.'); }
kasa_kapi('read');
kasa_no_store();
$pdo = db();
kasa_sayfa_kapisi($pdo);

render_header('Kasa Tanımları');
kasa_assets();
render_flash();
echo kasa_tanimlari_govde($pdo);
kasa_scripts();
render_footer();
