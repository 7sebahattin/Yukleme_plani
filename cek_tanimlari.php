<?php
// =========================================================
// cek_tanimlari.php — Çek Tanımları: Bankalar · Çek Karneleri. YALNIZ GET; yazmalar cek_kaydet.php'de.
//   ?sekme=banka|karne
// Kapı: muhasebe_sayfa_can (Roller; varsayılan kapalı, yönetici her zaman) → ceksenet_kapi('read'); tablo yoksa şema uyarı kartı. Gövde config/ceksenet_ui.php ceksenet_tanimlari_govde().
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/ceksenet_core.php';
require_once __DIR__ . '/config/ceksenet_ui.php';
$auth_user = require_login();
if (!muhasebe_sayfa_can('cek_tanimlari')) { forbidden(); }
ceksenet_kapi('read');
ceksenet_no_store();
$pdo = db();
ceksenet_sayfa_kapisi($pdo);

render_header('Çek Tanımları');
ceksenet_assets();
render_flash();
echo ceksenet_tanimlari_govde($pdo);
ceksenet_scripts();
render_footer();
