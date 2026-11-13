<?php
// =========================================================
// cari_tanimlari.php — Cari Tanımları: Cari Grupları · Ödeme Şekilleri · İçe Aktar. YALNIZ GET; yazmalar cari_kaydet.php'de.
//   ?sekme=grup|odeme_sekli|ice_aktar     (yazma düğmeleri yalnız cari_can('admin') için basılır)
// Kapı: is_admin() tabanlı cari_kapi('read'); tablo yoksa şema uyarı kartı. Gövde config/cari_ui.php cari_tanimlari_govde().
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

render_header('Cari Tanımları');
cari_assets();
render_flash();
echo cari_tanimlari_govde($pdo);
cari_scripts();
render_footer();
