<?php
// =========================================================
// cavus_cari.php — Çavuş Cari Hesap Listesi (Günlük İşçi, Faz 5)
//
// SALT OKUNUR — kendi hesap SQL'ini YAZMAZ (pdks_cari_hesap_listesi()
// REUSE). Bakiye HER ZAMAN KESİN hakediş - GEÇERLİ ödeme'den CANLI
// türetilir, ikinci bir mutasyona açık defter YOKTUR.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_cari.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_cari('accounts');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_hakedis_sayfa_kapisi($pdo);
pdks_cari_sayfa_kapisi($pdo);

$q = trim($_GET['q'] ?? '');
$durum_f = trim($_GET['durum'] ?? '');
if (!in_array($durum_f, ['borc', 'avans', 'kapali'], true)) $durum_f = '';

$hesaplar = pdks_cari_hesap_listesi($pdo);

// Arama/filtre — sayfa PHP'de uygular (çavuş sayısı küçük, ekstra sorguya gerek yok).
if ($q !== '' || $durum_f !== '') {
    $hesaplar = array_values(array_filter($hesaplar, function ($h) use ($q, $durum_f) {
        if ($q !== '' && stripos($h['foreman']['name'], $q) === false && stripos($h['foreman']['code'], $q) === false) return false;
        if ($durum_f !== '') {
            $varMi = false;
            foreach ($h['bakiyeler'] as $b) { if ($b['durum'] === $durum_f) { $varMi = true; break; } }
            if (!$varMi) return false;
        }
        return true;
    }));
}

render_header('Çavuş Cari');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head">
    <h1>📒 Çavuş Cari Hesap</h1>
    <div class="page-head-actions">
        <a href="<?= h('cavus_cari_yazdir.php?' . http_build_query(array_filter(['q' => $q, 'durum' => $durum_f], fn($v) => $v !== null && $v !== ''))) ?>" class="btn" target="_blank" rel="noopener">🖨️ Yazdır</a>
        <a href="personel_takip.php" class="btn btn-ghost">← Personel Takibi</a>
    </div>
</div>

<form method="get" class="pdks-filter-bar">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Çavuş adı/kodu ara…">
    <select name="durum">
        <option value="">Tüm bakiyeler</option>
        <option value="borc" <?= $durum_f === 'borc' ? 'selected' : '' ?>>Çavuşa Borcumuz</option>
        <option value="avans" <?= $durum_f === 'avans' ? 'selected' : '' ?>>Çavuş Avansı</option>
        <option value="kapali" <?= $durum_f === 'kapali' ? 'selected' : '' ?>>Hesap Kapalı</option>
    </select>
    <button type="submit" class="btn">Filtrele</button>
    <?php if ($q !== '' || $durum_f !== ''): ?>
    <a href="cavus_cari.php" class="btn btn-ghost">Temizle</a>
    <?php endif; ?>
</form>

<?php if (empty($hesaplar)): ?>
<div class="pdks-empty">
    <span class="pdks-empty-icon" aria-hidden="true">📒</span>
    <p>Bu filtrelerde hesap kaydı bulunamadı. (Yalnız en az bir KESİN hakedişi veya ödemesi olan çavuşlar listelenir.)</p>
</div>
<?php else: ?>

<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr>
    <th>Çavuş</th><th>Para Birimi</th><th>Kesinleşmiş Hakediş</th><th>Toplam Ödeme</th><th>Düzeltme (Net)</th><th>Bakiye</th><th>Son Hakediş</th><th>Son Ödeme</th><th class="actions-col">İşlem</th>
</tr></thead>
<tbody>
<?php foreach ($hesaplar as $h): $f = $h['foreman']; ?>
<?php foreach ($h['bakiyeler'] as $cur => $b): ?>
<tr>
    <td class="pdks-row-name"><?= h($f['name']) ?></td>
    <td><?= h($cur) ?></td>
    <td><?= h(number_format((float)$b['hakedis_toplam'], 2, ',', '.')) ?></td>
    <td><?= h(number_format((float)$b['odeme_toplam'], 2, ',', '.')) ?></td>
    <!-- Fix 9 (Personel Takibi denetimi): bakiye = hakediş + düzeltme - ödeme
         (bkz. pdks_cari_bakiye()) — bu sütun olmadan Faz 9D düzeltmesi olan bir
         çavuşta Bakiye, Hakediş-Ödeme'ye eşit GÖRÜNMÜYORDU (fark "tutmuyor" sanılıyordu). -->
    <td class="muted"><?= ($b['duzeltme_kurus'] ?? 0) !== 0 ? h(number_format((float)$b['duzeltme_toplam'], 2, ',', '.')) : '—' ?></td>
    <td>
        <span class="pdks-badge <?= $b['durum'] === 'borc' ? 'pdks-badge-eksik_cikis' : ($b['durum'] === 'avans' ? 'pdks-badge-acik' : 'pdks-badge-aktif') ?>">
            <?= h($b['durum_etiket']) ?><?= $b['durum'] !== 'kapali' ? ': ' . h(number_format(abs((float)$b['bakiye']), 2, ',', '.')) . ' ' . h($cur) : '' ?>
        </span>
    </td>
    <td class="muted"><?= $b['son_hakedis_tarihi'] ? h(date('d.m.Y', strtotime($b['son_hakedis_tarihi']))) : '—' ?></td>
    <td class="muted"><?= $b['son_odeme_tarihi'] ? h(date('d.m.Y', strtotime($b['son_odeme_tarihi']))) : '—' ?></td>
    <td class="actions-col">
        <a href="cavus_ekstre.php?foreman_id=<?= (int)$f['id'] ?>" class="btn btn-sm">Ekstre</a>
    </td>
</tr>
<?php endforeach; ?>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="pdks-cards mobile-only">
<?php foreach ($hesaplar as $h): $f = $h['foreman']; ?>
<?php foreach ($h['bakiyeler'] as $cur => $b): ?>
<a href="cavus_ekstre.php?foreman_id=<?= (int)$f['id'] ?>" class="pdks-card-item" style="text-decoration:none;color:inherit">
    <div class="pdks-card-top">
        <div class="pdks-card-meta">
            <div class="pdks-row-name"><?= h($f['name']) ?></div>
            <div class="pdks-row-sub"><?= h($cur) ?> · Hakediş <?= h(number_format((float)$b['hakedis_toplam'], 2, ',', '.')) ?> · Ödeme <?= h(number_format((float)$b['odeme_toplam'], 2, ',', '.')) ?><?= ($b['duzeltme_kurus'] ?? 0) !== 0 ? ' · Düzeltme ' . h(number_format((float)$b['duzeltme_toplam'], 2, ',', '.')) : '' ?></div>
        </div>
        <span class="pdks-badge <?= $b['durum'] === 'borc' ? 'pdks-badge-eksik_cikis' : ($b['durum'] === 'avans' ? 'pdks-badge-acik' : 'pdks-badge-aktif') ?>">
            <?= h($b['durum_etiket']) ?>
        </span>
    </div>
    <div class="pdks-kiosk-counter-row"><span>Bakiye</span><span class="n"><strong><?= h(number_format(abs((float)$b['bakiye']), 2, ',', '.')) ?> <?= h($cur) ?></strong></span></div>
    <div class="pdks-row-sub">Son hakediş: <?= $b['son_hakedis_tarihi'] ? h(date('d.m.Y', strtotime($b['son_hakedis_tarihi']))) : '—' ?> · Son ödeme: <?= $b['son_odeme_tarihi'] ? h(date('d.m.Y', strtotime($b['son_odeme_tarihi']))) : '—' ?></div>
</a>
<?php endforeach; ?>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php render_footer(); ?>
