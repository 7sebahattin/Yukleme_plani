<?php
// =========================================================
// cavus_hakedis.php — Çavuş Hakediş Listesi (Günlük İşçi, Faz 4)
//
// Faz 3'ün puantaj (Kadın/Erkek/Toplam/durum) verisiyle Faz 4'ün hakediş
// (tutar/taslak-kesin) verisini BİRLEŞTİRİR — kendi tarama/sayım SQL'ini
// YAZMAZ (pdks_gunluk_gun_listesi() + pdks_hakedis_gun_listesi() REUSE).
// Arayüz Kadın/Erkek'i ÖNE ÇIKARABİLİR ama hesap motoru (config/
// pdks_hakedis.php) İŞÇİ TİPİNDEN BAĞIMSIZ/GENEL kalır (görev talimatı).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_hakedis('entitlements_view');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_hakedis_sayfa_kapisi($pdo);

// ── "Hesapla" aksiyonu — taslak hesap, entitlements_view YETER (görev
//    talimatı: taslak/önizleme herkesin görebileceği bir şey; KESİNLEŞTİRME
//    ayrı, daha sıkı bir yetki gerektirir — bkz. cavus_hakedis_detay.php). ──
$flashHata = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hesapla') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_hakedis('entitlements_view');
    $sid = filter_var($_POST['session_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
    if ($sid) {
        $sonuc = pdks_hakedis_hesapla($sid, (int)$auth_user['id'], $pdo);
        if ($sonuc['ok']) {
            header('Location: cavus_hakedis_detay.php?id=' . (int)$sonuc['entitlement_id']);
            exit;
        }
        $flashHata = $sonuc['hata'] ?? 'Hesaplanamadı.';
    }
}

// ── Filtreler — Faz 3'ün AYNI deseni ─────────────────────
$tarih = trim($_GET['tarih'] ?? '');
if ($tarih === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih) || !strtotime($tarih)) {
    $tarih = date('Y-m-d');
}
$cavusId = filter_var($_GET['cavus'] ?? '', FILTER_VALIDATE_INT) ?: null;
$durum_f = trim($_GET['durum'] ?? '');
if (!in_array($durum_f, ['hesaplanmadi', 'draft', 'final'], true)) $durum_f = '';
$depo = function_exists('active_depot') ? (active_depot() ?? '') : '';

$cavuslar = [];
try {
    $cavuslar = $pdo->query("SELECT id, code, name, is_active FROM foremen ORDER BY is_active DESC, name ASC")->fetchAll();
} catch (PDOException $e) { /* boş bırak */ }

// ── Veri: puantaj (Faz 3) + hakediş (Faz 4) BİRLEŞTİRME ──
$gunListesi = pdks_gunluk_gun_listesi($tarih, $depo, $cavusId, null, $pdo);
$hakedisler = pdks_hakedis_gun_listesi($tarih, $depo, $cavusId, null, $pdo);
$hakedisBySession = [];
foreach ($hakedisler as $h) $hakedisBySession[(int)$h['session_id']] = $h;

$satirlar = [];
foreach ($gunListesi as $row) {
    $sid = (int)$row['session']['id'];
    $hk = $hakedisBySession[$sid] ?? null;
    $hkDurum = $hk ? $hk['status'] : 'hesaplanmadi';
    if ($durum_f !== '' && $hkDurum !== $durum_f) continue;
    $satirlar[] = ['puantaj' => $row, 'hakedis' => $hk, 'hakedis_durum' => $hkDurum];
}

render_header('Çavuş Hakediş');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
if ($flashHata) echo '<div class="flash flash-error">' . h($flashHata) . '</div>';

$durum_secenekleri = ['' => 'Tümü', 'hesaplanmadi' => 'Hesaplanmadı', 'draft' => 'Taslak', 'final' => 'Kesin'];
$durumEtiket = ['hesaplanmadi' => ['Hesaplanmadı', 'pasif'], 'draft' => ['Taslak', 'acik'], 'final' => ['Kesin', 'tamamlandi']];
?>

<div class="page-head">
    <h1>🧾 Çavuş Hakediş</h1>
    <div class="page-head-actions">
        <a href="cavus_fiyatlari.php" class="btn">💰 Çavuş Fiyatları</a>
        <a href="gunluk_isci_puantaj.php" class="btn">📅 Günlük Puantaj</a>
    </div>
</div>

<form method="get" class="pdks-filter-bar">
    <input type="date" name="tarih" value="<?= h($tarih) ?>">
    <select name="cavus">
        <option value="">Tüm çavuşlar</option>
        <?php foreach ($cavuslar as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $cavusId === (int)$c['id'] ? 'selected' : '' ?>>
            <?= h($c['name']) ?><?= $c['is_active'] ? '' : ' (pasif)' ?>
        </option>
        <?php endforeach; ?>
    </select>
    <select name="depo" disabled title="Depo değiştirmek için üstteki/soldaki depo rozetini kullanın">
        <option><?= h($depo !== '' ? $depo : 'Depo seçilmemiş') ?></option>
    </select>
    <select name="durum">
        <?php foreach ($durum_secenekleri as $val => $etiket): ?>
        <option value="<?= h($val) ?>" <?= $durum_f === $val ? 'selected' : '' ?>><?= h($etiket) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn">Filtrele</button>
    <?php if ($cavusId !== null || $durum_f !== '' || $tarih !== date('Y-m-d')): ?>
    <a href="cavus_hakedis.php" class="btn btn-ghost">Temizle</a>
    <?php endif; ?>
</form>

<?php if (empty($satirlar)): ?>
<div class="pdks-empty">
    <span class="pdks-empty-icon" aria-hidden="true">🧾</span>
    <p>Bu tarih/filtrelerde mesai kaydı bulunamadı.</p>
</div>
<?php else: ?>

<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr>
    <th>Çavuş</th>
    <th>Kadın</th>
    <th>Erkek</th>
    <th>Toplam İşçi</th>
    <th>Hakediş</th>
    <th>Durum</th>
    <th>Uyarı</th>
    <th class="actions-col">İşlem</th>
</tr></thead>
<tbody>
<?php foreach ($satirlar as $s): $p = $s['puantaj']; $sess = $p['session']; $hk = $s['hakedis']; [$etkt, $ekod] = $durumEtiket[$s['hakedis_durum']]; ?>
<tr>
    <td class="pdks-row-name"><?= h($sess['foreman_name_snapshot']) ?></td>
    <td><?= (int)($p['giris']['Kadın'] ?? 0) ?></td>
    <td><?= (int)($p['giris']['Erkek'] ?? 0) ?></td>
    <td><strong><?= (int)$p['giris_toplam'] ?></strong></td>
    <td><?= $hk ? h(number_format((float)$hk['total_amount'], 2, ',', '.') . ' ' . $hk['currency']) : '—' ?></td>
    <td><span class="pdks-badge pdks-badge-<?= h($ekod) ?>"><?= h($etkt) ?></span></td>
    <td><?php if ((int)$p['eksik_toplam'] > 0): ?><span class="pdks-badge pdks-badge-eksik_cikis">⚠️ Eksik Çıkış</span><?php endif; ?></td>
    <td class="actions-col">
        <?php if ($hk): ?>
        <a href="cavus_hakedis_detay.php?id=<?= (int)$hk['id'] ?>" class="btn btn-sm">Detay</a>
        <?php else: ?>
        <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="hesapla">
            <input type="hidden" name="session_id" value="<?= (int)$sess['id'] ?>">
            <button type="submit" class="btn btn-sm btn-primary">Hesapla</button>
        </form>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="pdks-cards mobile-only">
<?php foreach ($satirlar as $s): $p = $s['puantaj']; $sess = $p['session']; $hk = $s['hakedis']; [$etkt, $ekod] = $durumEtiket[$s['hakedis_durum']]; ?>
<div class="pdks-card-item">
    <div class="pdks-card-top">
        <div class="pdks-card-meta">
            <div class="pdks-row-name"><?= h($sess['foreman_name_snapshot']) ?></div>
            <div class="pdks-row-sub"><?= h(date('d.m.Y', strtotime($sess['work_date']))) ?><?= $sess['depo'] ? ' / ' . h($sess['depo']) : '' ?></div>
        </div>
        <span class="pdks-badge pdks-badge-<?= h($ekod) ?>"><?= h($etkt) ?></span>
    </div>
    <div class="pdks-kiosk-counter-row"><span>Kadın</span><span class="n"><?= (int)($p['giris']['Kadın'] ?? 0) ?></span></div>
    <div class="pdks-kiosk-counter-row"><span>Erkek</span><span class="n"><?= (int)($p['giris']['Erkek'] ?? 0) ?></span></div>
    <div class="pdks-kiosk-counter-row"><span>Toplam</span><span class="n"><strong><?= (int)$p['giris_toplam'] ?></strong></span></div>
    <div class="pdks-kiosk-counter-row"><span>Hakediş</span><span class="n"><?= $hk ? h(number_format((float)$hk['total_amount'], 2, ',', '.') . ' ' . $hk['currency']) : '—' ?></span></div>
    <?php if ((int)$p['eksik_toplam'] > 0): ?><div class="pdks-row-sub" style="color:var(--warn)">⚠️ Eksik Çıkış</div><?php endif; ?>
    <div style="margin-top:8px">
        <?php if ($hk): ?>
        <a href="cavus_hakedis_detay.php?id=<?= (int)$hk['id'] ?>" class="btn btn-sm">Detay</a>
        <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="hesapla">
            <input type="hidden" name="session_id" value="<?= (int)$sess['id'] ?>">
            <button type="submit" class="btn btn-sm btn-primary">Hesapla</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php render_footer(); ?>
