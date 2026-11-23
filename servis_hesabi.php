<?php
// =========================================================
// servis_hesabi.php — Servis Hesabı (servisçi cari; v532)
//
// Servis çavuş hakedişinden AYRI hesaplaşılır. Bu sayfa:
//  • seçilen aralıkta günlük/çavuş bazlı Büyük/Küçük adet × fiyat dökümü (SALT OKUNUR, canlı),
//  • servisçiye ödeme kaydı (DEĞİŞMEZ; yalnız gerekçeli iptal) ve güncel bakiye.
// Servis GİRİŞİ hâlâ çavuşun Mesai Detayı › 🚌 Servis Ücreti'nde yapılır. Hesap mantığı
// config/pdks_servis_hesap.php'dedir; burada SQL/hesap YAZMA.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_servis_hesap.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/pdks_cari.php';
$auth_user = require_login();
require_pdks_cari('accounts');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);

$depo = function_exists('active_depot') ? (active_depot() ?? '') : '';
$depoF = $depo !== '' ? $depo : null;
$servisHazir = pdks_servis_sema_hazir($pdo);

// ── Filtre ──
$ay = trim((string)($_GET['ay'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ay)) $ay = date('Y-m');
$bas = $ay . '-01';
$bit = date('Y-m-t', strtotime($bas));
$cavusId = filter_var($_GET['cavus'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$cavusId = $cavusId === false ? null : (int)$cavusId;

$cavuslar = $pdo->query('SELECT id, name FROM foremen ORDER BY is_active DESC, name ASC')->fetchAll();
$turler = pdks_servis_turleri();
$satirlar = $servisHazir ? pdks_servis_hesap_satirlari($bas, $bit, $depoF, $cavusId, $pdo) : [];
$ozet = pdks_servis_hesap_ozet($satirlar);
$bakiye = $servisHazir ? pdks_servis_bakiye($depoF, $pdo) : [];
$tl = static fn(int $k): string => number_format($k / 100, 2, ',', '.');
$tarihTr = static fn(string $d): string => date('d.m.Y', strtotime($d));

render_header('Servis Hesabı');
render_flash();
$base = function_exists('base_url') ? base_url() : '';
echo '<link rel="stylesheet" href="' . h($base) . 'assets/pdks.css?v=' . (int)@filemtime(__DIR__ . '/assets/pdks.css') . '">';
?>
<style>
@media print { .topbar, .sidebar, .bottomnav, .no-print, .page-head-actions { display:none !important; } .card { box-shadow:none; } }
.sv-h-kutular { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin:0 0 16px; }
.sv-h-kutu { border:1px solid var(--border,#ddd); border-radius:10px; padding:10px 12px; }
.sv-h-kutu b { display:block; font-size:1.15rem; }
.sv-h-sayi { text-align:right; white-space:nowrap; }
.sv-filtre { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; margin:0 0 18px; }
.sv-filtre label { display:flex; flex-direction:column; gap:4px; margin:0; }
.sv-filtre input[type="month"], .sv-filtre select { height:42px; width:auto; min-width:170px; padding:0 12px; border:1px solid var(--border,#d5dbe3); border-radius:8px; background:#fff; font:inherit; box-sizing:border-box; }
.sv-filtre .btn { height:42px; }
.sv-h-baslik { font-size:1rem; margin:20px 0 8px; }
.data-table th, .data-table td { padding:8px 12px; }
.data-table td:first-child, .data-table td:nth-child(2) { white-space:nowrap; }
.sv-h-soluk { color:#888; font-size:.85em; }
</style>

<div class="page-head">
    <h1>🚌 Servis Hesabı</h1>
    <div class="page-head-actions no-print">
        <a href="servis_odemeleri.php" class="btn btn-primary">💳 Servis Ödemeleri</a>
        <button type="button" class="btn btn-ghost" onclick="window.print()">🖨️ Yazdır</button>
        <a href="personel_takip.php" class="btn btn-geri btn-geri-ptak">← Personel Takibi</a>
    </div>
</div>

<p class="muted" style="margin-top:0">Servisçi ile ayrı hesaplaşılır; servis tutarı <b>çavuş hakedişine/bakiyesine yazılmaz</b>.
   Servis girişi çavuşun Mesai Detayı › 🚌 Servis Ücreti'nde yapılır; fiyat Çavuş Ücretleri › Servis Ücreti'ndedir.</p>

<?php if (!$servisHazir): ?>
<div class="card" style="padding:14px 16px">Servis tabloları kurulmamış (migrate.php › Servis Ücreti).</div>
<?php else: ?>

<form method="get" class="sv-filtre no-print" data-oto-filtre>
    <label><span class="form-label">Ay</span><input type="month" name="ay" value="<?= h($ay) ?>"></label>
    <label><span class="form-label">Çavuş</span>
        <select name="cavus"><option value="">Tümü</option>
        <?php foreach ($cavuslar as $c): ?>
            <option value="<?= (int)$c['id'] ?>"<?= $cavusId === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?></select></label>
    <button class="btn btn-primary" type="submit">Göster</button>
</form>

<h2 class="sv-h-baslik">Güncel Bakiye (tüm zamanlar<?= $depo !== '' ? ' · ' . h($depo) : '' ?>)</h2>
<?php if (!$bakiye): ?><p class="muted">Henüz servis kaydı yok.</p><?php endif; ?>
<div class="sv-h-kutular">
<?php foreach ($bakiye as $cur => $b): ?>
    <div class="sv-h-kutu"><span class="sv-h-soluk">Servis tutarı (<?= h($cur) ?>)</span><b><?= $tl($b['servis_kurus']) ?></b></div>
    <div class="sv-h-kutu"><span class="sv-h-soluk">Ödenen</span><b><?= $tl($b['odeme_kurus']) ?></b></div>
    <div class="sv-h-kutu"><span class="sv-h-soluk"><?= $b['bakiye_kurus'] >= 0 ? 'Servisçiye borç' : 'Avans (fazla ödeme)' ?></span><b><?= $tl(abs($b['bakiye_kurus'])) ?></b></div>
<?php endforeach; ?>
</div>

<h2 class="sv-h-baslik"><?= h($tarihTr($bas)) ?> – <?= h($tarihTr($bit)) ?> · Günlük Döküm</h2>
<div class="sv-h-kutular">
    <?php foreach ($ozet as $cur => $o): ?>
    <div class="sv-h-kutu"><span class="sv-h-soluk">Büyük / Küçük adet</span><b><?= (int)$o['buyuk'] ?> / <?= (int)$o['kucuk'] ?></b></div>
    <div class="sv-h-kutu"><span class="sv-h-soluk">Dönem servis tutarı (<?= h($cur) ?>)</span><b><?= $tl($o['tutar_kurus']) ?></b></div>
    <?php endforeach; ?>
</div>
<div class="table-wrap">
<table class="data-table">
    <thead><tr>
        <th>Tarih</th><th>Çavuş</th>
        <th class="sv-h-sayi">Büyük</th><th class="sv-h-sayi">Küçük</th>
        <th class="sv-h-sayi">Tutar</th><th>Not</th>
    </tr></thead>
    <tbody>
    <?php if (!$satirlar): ?><tr><td colspan="6" class="muted">Bu dönemde servis kaydı yok.</td></tr><?php endif; ?>
    <?php foreach ($satirlar as $s): ?>
        <tr>
            <td><a href="gunluk_isci_puantaj_detay.php?id=<?= (int)$s['session_id'] ?>"><?= h($tarihTr($s['tarih'])) ?></a></td>
            <td><?= h($s['cavus']) ?></td>
            <?php foreach (array_keys($turler) as $t): ?>
            <td class="sv-h-sayi"><?= (int)$s['adet'][$t] ?>
                <?php if ($s['adet'][$t] > 0 && $s['birim'][$t] !== null): ?><span class="sv-h-soluk">× <?= $tl($s['birim'][$t]) ?></span><?php endif; ?></td>
            <?php endforeach; ?>
            <td class="sv-h-sayi"><?= $tl($s['tutar_kurus']) ?> <span class="sv-h-soluk"><?= h($s['para']) ?></span></td>
            <td>
                <?php if ($s['fiyatsiz']): ?><span style="color:#c0392b">Fiyat yok: <?= h(implode(', ', $s['fiyatsiz'])) ?></span><?php endif; ?>
                <?php if ($s['hakedis_eski']): ?><span class="sv-h-soluk">Çavuş hakedişinde ödendi (bakiyeye katılmaz)</span><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php endif; ?>
<?php render_footer(); ?>
