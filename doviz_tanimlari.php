<?php
// =========================================================
// doviz_tanimlari.php — Para Birimi Tanımları (YALNIZ GET; tüm yazmalar doviz_kaydet.php'de)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/doviz_core.php';
require_once __DIR__ . '/config/doviz_ui.php';

$auth_user = require_login();
if (!muhasebe_sayfa_can('doviz_tanimlari')) { forbidden(); }
doviz_no_store();

$pdo = db();
doviz_sayfa_kapisi($pdo);

$ctx = [
    'tanimlar'    => doviz_tanim_listesi($pdo, false),
    'kur_tipleri' => doviz_kur_tipleri(),
    'katalog'     => doviz_tcmb_katalog(),
    'yonetici'    => doviz_can('admin'),
    'csrf'        => csrf_token(),
];

render_header('Döviz Tanımları');
doviz_head_varlik();
render_flash();
echo doviz_tanimlar_sayfa_render($ctx);
doviz_scripts();
render_footer();
