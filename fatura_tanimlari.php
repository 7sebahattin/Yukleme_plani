<?php
// =========================================================
// fatura_tanimlari.php — Fatura Tanımları: KDV · Tevkifat · Kesintiler (stopaj + Bağ-Kur) · İstisna · Seriler · Teslim şekilleri ·
//   Açıklama şablonları · Ödeme şekilleri (salt okunur; kaynak Cari Tanımları). ?sekme=kdv|tevkifat|kesinti|istisna|seri|teslim|sablon|odeme
// YALNIZ GET; yazma (tanim_kaydet / tanim_durum / tanim_sil) fatura_kaydet.php'de. Kapı: muhasebe_sayfa_can (Roller; varsayılan kapalı, yönetici her zaman) → fatura_kapi('read').
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/fatura_core.php';
require_once __DIR__ . '/config/fatura_ui.php';
$auth_user = require_login();
if (!muhasebe_sayfa_can('fatura_tanimlari')) { forbidden(); }
fatura_kapi('read');
fatura_no_store();
$pdo = db();
fatura_sayfa_kapisi($pdo);

render_header('Fatura Tanımları');
fatura_assets();
render_flash();
echo fatura_tanimlari_govde($pdo);
fatura_scripts();
render_footer();
