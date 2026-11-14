<?php
// =========================================================
// sevk_irsaliyeleri.php — Sevk İrsaliyeleri (Fatura modülü).
//   Sevk irsaliyeleri (her satırda stok kartı; kayıtta stok çıkışı). Cari hareketi oluşmaz; "Faturalaştır" ile satış faturasına dönüşür.
//   Sol liste (GET süzgeç: ?ara= &tur= &durum=tumu|gecerli|iptal &donem=tumu|30g|bu_ay|gecen_ay|ozel (&bas= &bit=) &cari= &sd=) + sağ panel:
//   ?id=N detay · ?yeni=1[&tur=] form · ?kopya=ID · ?yeni=1&tur=X&kaynak=ID[,ID] dönüşüm formu ·
//   ?parca=detay|cari_ozet|kaynaklar|kaynak_satirlari kısmi yanıt (header/footer YOK).
// YALNIZ GET; yazma fatura_kaydet.php'de. Kapı: is_admin() tabanlı fatura_kapi('read'); tablo yoksa şema uyarı kartı.
// Ortak gövde config/fatura_ui.php fatura_sayfa_govde(); kurallar config/fatura_core.php.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/fatura_core.php';
require_once __DIR__ . '/config/fatura_ui.php';
$auth_user = require_login();
if (!is_admin()) { forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.'); }
fatura_kapi('read');
fatura_no_store();
$pdo = db();
fatura_sayfa_kapisi($pdo);

if (fatura_parca($pdo, 'irsaliye')) exit;   // kısmi yanıt

render_header('Sevk İrsaliyeleri');
fatura_assets();
render_flash();
echo fatura_sayfa_govde($pdo, 'irsaliye');
fatura_scripts();
render_footer();
