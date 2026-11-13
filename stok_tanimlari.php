<?php
// =========================================================
// stok_tanimlari.php — Stok Tanımları: Stok Grupları · Giriş Nedenleri · Çıkış Nedenleri · İçe Aktar. YALNIZ GET; yazmalar stok_kaydet.php'de.
//   ?sekme=grup|giris_neden|cikis_neden|ice_aktar     (yazma düğmeleri yalnız stok_can('admin') için basılır)
// Kapı: is_admin() tabanlı stok_kapi('read'); tablo yoksa şema uyarı kartı. Gövde config/stok_ui.php stok_tanimlari_govde().
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

render_header('Stok Tanımları');
stok_assets();
render_flash();
echo stok_tanimlari_govde($pdo);
stok_scripts();
render_footer();
