<?php
// =========================================================
// alinan_siparis_fisleri.php — Alınan Sipariş Fişleri (Fatura modülü).
//   Alınan sipariş fişleri (stok/cari etkisi yok). İrsaliye ya da faturaya dönüşür; kalan miktar türetilir. v526: eski 302 yönlendirmesi kalktı (Ürün Siparişleri urun_siparis.php ayrı kalır).
//   Sol liste (GET süzgeç: ?ara= &tur= &durum=tumu|gecerli|iptal &donem=tumu|30g|bu_ay|gecen_ay|ozel (&bas= &bit=) &cari= &sd=) + sağ panel:
//   ?id=N detay · ?yeni=1[&tur=] form · ?kopya=ID · ?yeni=1&tur=X&kaynak=ID[,ID] dönüşüm formu ·
//   ?parca=detay|cari_ozet|kaynaklar|kaynak_satirlari kısmi yanıt (header/footer YOK).
// YALNIZ GET; yazma fatura_kaydet.php'de. Kapı: muhasebe_sayfa_can (Roller; varsayılan kapalı, yönetici her zaman) → fatura_kapi('read'); tablo yoksa şema uyarı kartı.
// Ortak gövde config/fatura_ui.php fatura_sayfa_govde(); kurallar config/fatura_core.php.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/fatura_core.php';
require_once __DIR__ . '/config/fatura_ui.php';
$auth_user = require_login();
if (!muhasebe_sayfa_can('alinan_siparis_fisleri')) { forbidden(); }
fatura_kapi('read');
fatura_no_store();
$pdo = db();
fatura_sayfa_kapisi($pdo);

if (fatura_parca($pdo, 'siparis')) exit;   // kısmi yanıt

render_header('Alınan Sipariş Fişleri');
fatura_assets();
render_flash();
echo fatura_sayfa_govde($pdo, 'siparis');
fatura_scripts();
render_footer();
