<?php
// =========================================================
// rapor_tanimlari.php — ERP Faz 5 Dinamik BI Rapor Tasarımcısı & Zamanlanmış Dağıtım. YALNIZ GET.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/bi_rapor_core.php';
require_once __DIR__ . '/config/bi_rapor_ui.php';

$auth_user = require_login();
if (!muhasebe_sayfa_can('rapor_tanimlari')) { forbidden(); }
bi_kapi('read');
bi_no_store();
$pdo = db();
bi_rapor_sayfa_kapisi($pdo);

$secKaynak = trim((string)($_GET['kaynak'] ?? 'genel_muhasebe'));
$veri = [
    'sec_kaynak'   => $secKaynak,
    'sablonlar'    => bi_sablon_listesi($pdo),
    'zamanlamalar' => bi_zamanlama_listesi($pdo),
];
$yazabilir = bi_can('create') || bi_can('edit');

render_header('Dinamik BI Rapor Tasarımcısı');
bi_head_varlik();
render_flash();
echo bi_rapor_tanimlari_render($veri, $yazabilir);
bi_scripts();
render_footer();
