<?php
// =========================================================
// stok_cikis_fisleri.php — Stok Çıkış Fişleri (Çıkış fişi — satış/sevk, sarf, fire; maliyet ortalamadan; cari seçilirse borç dekontu).
//   Sol liste (GET süzgeç: ?ara= &durum=tumu|gecerli|iptal &donem=tumu|30g|bu_ay|gecen_ay &neden=<id>) + sağ panel:
//   ?id=N detay · ?yeni=1 form · ?kopya=ID kopya formu
//   ?parca=detay&id=N kısmi yanıt (header/footer YOK)
// YALNIZ GET; yazma (fis_kaydet / fis_iptal) stok_kaydet.php'de. Kapı: is_admin() tabanlı stok_kapi('read'); tablo yoksa şema uyarı kartı.
// Ortak gövde config/stok_ui.php stok_fis_sayfa_govde(); kurallar config/stok_core.php (Parça B).
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

if (stok_fis_parca($pdo, 'cikis')) exit;   // kısmi yanıt

render_header('Stok Çıkış Fişleri');
stok_assets();
render_flash();
echo stok_fis_sayfa_govde($pdo, 'cikis');
stok_scripts();
render_footer();
