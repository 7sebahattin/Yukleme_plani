<?php
// =========================================================
// kasa_fisleri.php — Kasa Fişleri: sol kasa kartları + sağ hareket listesi (YALNIZ GET; yazmalar kasa_kaydet.php'de).
//   ?kasa=ID (yoksa tümü) · ?id=N hareket detayı · süzgeç: ara, tur, durum (varsayılan gecerli), donem (+bas, bit), sayfa
//   ?parca=detay&id=N kısmi yanıt (header/footer YOK)
// Kapı: is_admin() tabanlı kasa_kapi('read'); tablo yoksa şema uyarı kartı. Gövde config/kasa_ui.php kasa_fisleri_govde().
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

if (kasa_parca($pdo)) exit;   // kısmi yanıt

render_header('Kasa Fişleri');
kasa_assets();
render_flash();
echo kasa_fisleri_govde($pdo);
kasa_scripts();
render_footer();
