<?php
// =========================================================
// cari_fisler.php — Cari Fişler: pb×tür özet şeridi + süzgeç + liste + "+ Yeni" modalı. YALNIZ GET; yazmalar cari_kaydet.php'de.
//   ?bas= &bit= (varsayılan Bu yıl) &tur= &pb= &cari_q= &durum=gecerli|iptal|tumu &q= &s=<sayfa> &yeni=<tür>[&cari=<id>]
// Kapı: is_admin() tabanlı cari_kapi('read'); tablo yoksa şema uyarı kartı. Gövde config/cari_ui.php cari_hareket_sayfa_govde('fis').
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/cari_core.php';
require_once __DIR__ . '/config/xlsx_export.php';   // export_menu
require_once __DIR__ . '/config/cari_ui.php';
$auth_user = require_login();
if (!is_admin()) { forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.'); }
cari_kapi('read');
cari_no_store();
$pdo = db();
cari_sayfa_kapisi($pdo);

render_header('Cari Fişler');
cari_assets();
render_flash();
echo cari_hareket_sayfa_govde($pdo, 'fis');
cari_scripts();
render_footer();
