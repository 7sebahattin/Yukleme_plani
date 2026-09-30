<?php
// =========================================================
// hesap_personel.php — "Tüm Personel" (yalnız yönetici)
//
// Kişi × para birimi bakiye tablosu. Para birimleri AYRI satırdadır, asla
// toplanmaz. Her satırdan o kişinin Hesabı / Kayıtları / PDF raporu açılır
// (?personel=<uid> — yalnız yöneticide etkili). Sahipsiz (user_id NULL)
// kayıtlar burada YOK — kimsenin bakiyesine girmez.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_hesap('read');
hesap_migrate();

if (!hesap_sees_all()) {
    forbidden('Bu sayfa yalnız yöneticiye açıktır. (Gerekli yetki: hesap.admin)');
}

$tarih_b = trim((string)($_GET['tarih_bas'] ?? ''));
$tarih_s = trim((string)($_GET['tarih_son'] ?? ''));
if ($tarih_b !== '' && !hesap_tarih_gecerli($tarih_b)) $tarih_b = '';
if ($tarih_s !== '' && !hesap_tarih_gecerli($tarih_s)) $tarih_s = '';

audit_log_event('view', 'hesap', null, null, ['personel' => 'tum', 'sayfa' => 'hesap_personel.php']);

$satirlar = hesap_balance_by_user(
    $tarih_b !== '' ? $tarih_b : null,
    $tarih_s !== '' ? date('Y-m-d', strtotime($tarih_s . ' +1 day')) : null,
    null                                   // kişi × para birimi
);

$kullanicilar = [];
try {
    $kullanicilar = db()->query("SELECT id, COALESCE(NULLIF(display_name,''), username) AS ad
                                 FROM users WHERE is_active = 1 ORDER BY ad")->fetchAll();
} catch (PDOException $e) { $kullanicilar = []; }

// Dönem linkleri kişi sayfalarına taşınır
$donem_q = array_filter(['tarih_bas' => $tarih_b, 'tarih_son' => $tarih_s]);
$link = fn(string $sayfa, int $uid, array $ek = []) => $sayfa . '?' . http_build_query(['personel' => $uid] + $ek);
$pdf_q = [
    'tarih_bas' => $tarih_b !== '' ? $tarih_b : date('Y-m-01'),
    'tarih_son' => $tarih_s !== '' ? $tarih_s : date('Y-m-t'),
];

render_header('Tüm Personel — Hesap');
hesap_assets();
render_flash();
?>
<div class="hs">
<div class="page-head">
    <div>
        <h1>👥 Tüm Personel</h1>
        <p class="muted">Personel hesap bakiyeleri — onaylanan ve ödenen kayıtlardan</p>
    </div>
    <div class="hs-actions">
        <a href="hesap_liste.php?<?= http_build_query(['personel' => 'tum'] + $donem_q) ?>" class="btn btn-ghost">📋 Tüm Kayıtlar</a>
        <a href="hesap_yazdir.php?<?= http_build_query(['personel' => 'tum'] + $pdf_q) ?>" class="btn btn-ghost" target="_blank" rel="noopener">📄 PDF</a>
        <a href="hesap.php" class="btn btn-ghost">← Hesabım</a>
    </div>
</div>

<div class="hs-filter-panel">
    <form method="get" action="hesap.php" class="hs-filter-row">
        <span class="hs-filter-label">Kişi</span>
        <select name="personel" class="hs-select" required aria-label="Personel seç">
            <option value="">— Hesabını aç —</option>
            <?php foreach ($kullanicilar as $ku): ?>
            <option value="<?= (int)$ku['id'] ?>"><?= h($ku['ad']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm">Aç</button>
    </form>
    <form method="get" class="hs-filter-row">
        <span class="hs-filter-label">Aralık</span>
        <div class="hs-daterange">
            <input type="date" name="tarih_bas" value="<?= h($tarih_b) ?>" aria-label="Başlangıç tarihi">
            <span class="sep">—</span>
            <input type="date" name="tarih_son" value="<?= h($tarih_s) ?>" aria-label="Bitiş tarihi">
            <button class="btn btn-sm btn-primary">Uygula</button>
            <?php if ($donem_q): ?><a href="hesap_personel.php" class="btn btn-ghost btn-sm">✕</a><?php endif; ?>
        </div>
    </form>
</div>

<?php if (empty($satirlar)): ?>
<div class="hs-empty">
    <span class="hs-empty-icon" aria-hidden="true">👥</span>
    <p>Bu aralıkta personele ait hesap kaydı yok.</p>
</div>
<?php else: ?>

<!-- Masaüstü -->
<div class="table-wrap pc-only">
<table class="data-table hs-yon-tablo">
<thead><tr>
    <th>Personel</th>
    <th class="num">Gelir</th><th class="num">Gider</th><th class="num">Net</th><th>Durum</th>
    <th class="num">Bekleyen</th><th class="num">Kayıt</th><th class="actions-col">Aç</th>
</tr></thead>
<tbody>
<?php foreach ($satirlar as $r): $cur = (string)$r['currency']; $bi = hesap_balance_label((float)$r['net'], true); $uid = (int)$r['user_id']; ?>
<tr>
    <td class="strong"><?= h($r['personel']) ?> <span class="hs-sum-cur"><?= h($cur) ?></span></td>
    <td class="num"><?= fmt_para((float)$r['gelir'], $cur) ?></td>
    <td class="num"><?= fmt_para((float)$r['gider'], $cur) ?></td>
    <td class="num strong hs-net--<?= h($bi['yon']) ?>"><?= fmt_para($bi['tutar'], $cur) ?></td>
    <td class="muted"><?= h($bi['label']) ?></td>
    <td class="num muted"><?= fmt_para((float)$r['bekleyen'], $cur) ?></td>
    <td class="num muted"><?= (int)$r['adet'] ?></td>
    <td class="actions-col">
        <div class="hs-actions">
            <a class="btn btn-sm" href="<?= h($link('hesap.php', $uid)) ?>">Hesabı</a>
            <a class="btn btn-sm" href="<?= h($link('hesap_liste.php', $uid, $donem_q)) ?>">Kayıtlar</a>
            <a class="btn btn-sm" href="<?= h($link('hesap_yazdir.php', $uid, $pdf_q)) ?>" target="_blank" rel="noopener">PDF</a>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- Mobil -->
<div class="hs-tx-list mobile-only">
<?php foreach ($satirlar as $r): $cur = (string)$r['currency']; $bi = hesap_balance_label((float)$r['net'], true); $uid = (int)$r['user_id']; ?>
<div class="hs-tx hs-personel-kart">
    <div class="hs-personel-ust">
        <span class="hs-tx-main">
            <span class="hs-tx-title"><?= h($r['personel']) ?> <span class="hs-sum-cur"><?= h($cur) ?></span></span>
            <span class="hs-tx-meta"><?= h($bi['label']) ?> · <?= (int)$r['adet'] ?> kayıt</span>
            <?php if (abs((float)$r['bekleyen']) > 0.005): ?>
            <span class="hs-tx-meta">Bekleyen <?= fmt_para((float)$r['bekleyen'], $cur) ?></span>
            <?php endif; ?>
        </span>
        <span class="hs-tx-amount hs-net--<?= h($bi['yon']) ?>"><?= fmt_para($bi['tutar'], $cur) ?></span>
    </div>
    <div class="hs-actions">
        <a class="btn btn-sm" href="<?= h($link('hesap.php', $uid)) ?>">Hesabı</a>
        <a class="btn btn-sm" href="<?= h($link('hesap_liste.php', $uid, $donem_q)) ?>">Kayıtlar</a>
        <a class="btn btn-sm" href="<?= h($link('hesap_yazdir.php', $uid, $pdf_q)) ?>" target="_blank" rel="noopener">PDF</a>
    </div>
</div>
<?php endforeach; ?>
</div>
<p class="hs-cur-note">Para birimleri ayrı satırdadır — farklı kurlar birbirine eklenmez. Bekleyen: taslak + gönderildi (bakiyeye girmez).</p>
<?php endif; ?>

</div><!-- /.hs -->
<?php hesap_scripts(); ?>
<?php render_footer(); ?>
