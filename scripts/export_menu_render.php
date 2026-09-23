<?php
// =========================================================
// scripts/export_menu_render.php — "⬇ Excel İndir ▾" menüsünü GERÇEK
// style.css + app.js ile tek bir HTML dosyasına basar. Tek amacı tarayıcı
// testine (export_menu_smoke.js) girdi üretmektir; DB'ye dokunmaz.
//
//   php scripts/export_menu_render.php > _test_export_menu.html
//   node scripts/export_menu_smoke.js
//
// Menü, sayfalarda kullanıldığı ÜÇ zor yerleşimde basılır:
//   1) .rpt-actions içinde — mobilde overflow-x:auto olan kapsayıcı
//      (reports.php). position:absolute bir liste burada KESİLİRDİ.
//   2) Satırın SOL kenarında (PDKS filtre formu) — sağa hizalı liste
//      ekranın soluna taşardı.
//   3) Sayfanın EN ALTINDA — liste ekranın altından taşardı.
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function can(string $p): bool { return $p === 'reports.export'; }
require $ROOT . '/config/xlsx_export.php';
?><!doctype html><html lang="tr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Excel İndir menüsü testi</title>
<link rel="stylesheet" href="assets/style.css">
</head><body><main class="container">

<div class="rpt-head">
    <div class="rpt-title"><h1>🚚 Yüklemeler Raporu</h1><p class="muted">3 kayıt</p></div>
    <div class="rpt-actions rpt-no-print" id="kutu1">
        <button class="btn btn-sm">🖨 Yazdır</button>
        <?= export_menu('reports.php?type=yukleme&export=csv_summary', 'reports.php?type=yukleme&export=xlsx_summary', 'Özet Excel', 'btn btn-sm') ?>
        <?= export_menu('reports.php?type=yukleme&export=csv', 'reports.php?type=yukleme&export=xlsx', 'Detay Excel', 'btn btn-sm btn-primary') ?>
        <button class="btn btn-sm">📄 Toplu PDF</button>
    </div>
</div>

<form class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center" id="kutu2" onsubmit="return false">
    <?= export_menu('?csv=gunluk&a=1&b=2', '?xlsx=gunluk&a=1&b=2', 'Günlük Excel', 'btn btn-ghost') ?>
    <select><option>Tümü</option></select>
    <button type="submit" class="btn">Filtrele</button>
</form>

<div style="height:1400px" class="card">Uzun içerik</div>

<div style="display:flex;justify-content:flex-end" id="kutu3">
    <?= export_menu('?csv=1', '?xlsx=1', 'Excel İndir', 'btn btn-sm btn-ghost') ?>
</div>

</main>
<script src="assets/app.js"></script>
</body></html>
