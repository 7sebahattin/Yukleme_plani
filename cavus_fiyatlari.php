<?php
// =========================================================
// cavus_fiyatlari.php — Çavuş Fiyat Yönetimi
// Faz 8B şeması hazırsa Tam/Yarım/FM fiyatları; hazır değilse Faz 4'ün
// mevcut tek günlük ücret davranışı aynen devam eder.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_hakedis('rates');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_hakedis_sayfa_kapisi($pdo);
$faz8bHazir = pdks_faz8b_sema_hazir($pdo);

$cavusId = filter_var($_GET['cavus'] ?? '', FILTER_VALIDATE_INT) ?: null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_hakedis('rates');
    $cavusId = filter_var($_POST['foreman_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
    $workerTypeId = filter_var($_POST['worker_type_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
    $tamUcret = trim((string)($_POST['daily_rate'] ?? ''));
    $validFrom = trim((string)($_POST['valid_from'] ?? ''));
    $currency = trim((string)($_POST['currency'] ?? 'TRY')) ?: 'TRY';

    if (!$cavusId || !$workerTypeId) {
        $errors[] = 'Çavuş ve işçi tipi zorunludur.';
    } else {
        if ($faz8bHazir) {
            $yarimUcret = trim((string)($_POST['half_day_rate'] ?? ''));
            $fmMode = trim((string)($_POST['overtime_mode'] ?? ''));
            $fmUcret = trim((string)($_POST['overtime_rate'] ?? ''));
            $sonuc = pdks_faz8b_oran_ekle(
                $cavusId,
                $workerTypeId,
                $tamUcret,
                $yarimUcret,
                $fmMode,
                $fmUcret,
                $validFrom,
                $currency,
                (int)$auth_user['id'],
                $pdo
            );
        } else {
            $sonuc = pdks_hakedis_oran_ekle(
                $cavusId,
                $workerTypeId,
                $tamUcret,
                $validFrom,
                $currency,
                (int)$auth_user['id'],
                $pdo
            );
        }

        if ($sonuc['ok']) {
            header('Location: cavus_fiyatlari.php?cavus=' . $cavusId . '&ok=' . urlencode('Yeni fiyat dönemi eklendi.'));
            exit;
        }
        $errors[] = $sonuc['hata'] ?? 'Kaydedilemedi.';
    }
}

$basari = '';
if (empty($errors) && isset($_GET['ok'])) $basari = trim((string)$_GET['ok']);

$cavuslar = $pdo->query("SELECT id, code, name, is_active FROM foremen ORDER BY is_active DESC, name ASC")->fetchAll();
$tipler = pdks_gunluk_tip_listele(true, $pdo);
$seciliCavus = null;
$oranlar = [];
if ($cavusId !== null) {
    foreach ($cavuslar as $c) {
        if ((int)$c['id'] === $cavusId) { $seciliCavus = $c; break; }
    }
    if ($seciliCavus) $oranlar = pdks_hakedis_oran_gecmisi($cavusId, $pdo);
}

render_header('Çavuş Fiyatları');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head">
    <h1>💰 Çavuş Fiyatları</h1>
    <div class="page-head-actions">
        <a href="cavuslar.php" class="btn">👷 Çavuşlar</a>
        <a href="cavus_hakedis.php" class="btn">🧾 Hakediş</a>
    </div>
</div>

<?php if ($basari !== ''): ?><div class="flash flash-success"><?= h($basari) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="flash flash-error"><?= h($e) ?></div><?php endforeach; ?>
<?php if (!$faz8bHazir): ?><div class="flash flash-warning">Faz 8B şeması henüz çalıştırılmadı. Bu ekran mevcut tek Günlük Ücret modeliyle güvenli biçimde devam ediyor. Yönetici <a href="faz8b_migrate.php">Faz 8B migrasyonunu</a> çalıştırabilir.</div><?php endif; ?>

<form method="get" class="pdks-filter-bar">
    <select name="cavus" onchange="this.form.submit()">
        <option value="">— Çavuş seçin —</option>
        <?php foreach ($cavuslar as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $cavusId === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?> (<?= h($c['code']) ?>)<?= $c['is_active'] ? '' : ' — pasif' ?></option>
        <?php endforeach; ?>
    </select>
    <noscript><button type="submit" class="btn">Seç</button></noscript>
</form>

<?php if (!$seciliCavus): ?>
<div class="pdks-empty"><span class="pdks-empty-icon" aria-hidden="true">💰</span><p>Fiyatları görmek/eklemek için önce bir çavuş seçin.</p></div>
<?php else: ?>

<div class="card" style="padding:18px 20px;margin:18px 0">
    <h2 style="margin-top:0;font-size:1rem">Yeni Etkin Fiyat Dönemi — <?= h($seciliCavus['name']) ?></h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="foreman_id" value="<?= (int)$seciliCavus['id'] ?>">
        <div class="pdks-form-grid">
            <label>
                <span class="form-label">İşçi Tipi *</span>
                <select name="worker_type_id" required>
                    <option value="">— Seçin —</option>
                    <?php foreach ($tipler as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>
                <span class="form-label"><?= $faz8bHazir ? 'Tam Mesai Ücreti *' : 'Günlük Ücret *' ?></span>
                <input type="text" name="daily_rate" required inputmode="decimal" placeholder="ör. 1500 veya 1500,50">
            </label>
            <?php if ($faz8bHazir): ?>
            <label>
                <span class="form-label">Yarım Mesai Ücreti *</span>
                <input type="text" name="half_day_rate" required inputmode="decimal" placeholder="ör. 900">
            </label>
            <label>
                <span class="form-label">Fazla Mesai Tipi *</span>
                <select name="overtime_mode" required>
                    <option value="hourly">Saatlik</option>
                    <option value="fixed">Sabit Toplam</option>
                </select>
            </label>
            <label>
                <span class="form-label">Fazla Mesai Ücreti *</span>
                <input type="text" name="overtime_rate" required inputmode="decimal" placeholder="ör. 200">
                <small class="muted">Saatlik: 15 dk tolerans sonrası başlayan her saat yukarı yuvarlanır. Sabit: onaylanan FM için bir kez uygulanır.</small>
            </label>
            <?php endif; ?>
            <label><span class="form-label">Para Birimi</span><input type="text" name="currency" maxlength="10" value="TRY"></label>
            <label><span class="form-label">Geçerlilik Başlangıcı *</span><input type="date" name="valid_from" required value="<?= h(date('Y-m-d')) ?>"></label>
        </div>
        <p class="muted" style="font-size:.85rem;margin:10px 0 0">Yeni dönem eklenince önceki açık fiyat dönemi bir gün öncesinde kapanır. Eski fiyatlar silinmez.</p>
        <button type="submit" class="btn btn-primary" style="margin-top:14px">+ Fiyat Dönemi Ekle</button>
    </form>
</div>

<h2 style="font-size:1.05rem">Fiyat Geçmişi</h2>
<?php if (empty($oranlar)): ?>
<div class="pdks-empty"><p>Bu çavuş için henüz bir fiyat tanımlanmadı.</p></div>
<?php else: ?>
<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr><th>İşçi Tipi</th><th><?= $faz8bHazir ? 'Tam' : 'Günlük Ücret' ?></th><?php if ($faz8bHazir): ?><th>Yarım</th><th>Fazla Mesai</th><?php endif; ?><th>Geçerlilik</th><th>Durum</th></tr></thead>
<tbody>
<?php foreach ($oranlar as $o): ?>
<tr>
    <td class="pdks-row-name"><?= h($o['worker_type_name']) ?></td>
    <td><strong><?= h(number_format((float)$o['daily_rate'], 2, ',', '.')) ?> <?= h($o['currency']) ?></strong></td>
    <?php if ($faz8bHazir): ?>
    <td><?= ($o['half_day_rate'] ?? null) !== null ? h(number_format((float)$o['half_day_rate'], 2, ',', '.') . ' ' . $o['currency']) : '—' ?></td>
    <td><?php if (($o['overtime_rate'] ?? null) !== null): ?><?= h(number_format((float)$o['overtime_rate'], 2, ',', '.') . ' ' . $o['currency']) ?> · <?= h(($o['overtime_mode'] ?? '') === 'fixed' ? 'Sabit' : 'Saatlik') ?><?php else: ?>—<?php endif; ?></td>
    <?php endif; ?>
    <td class="muted"><?= h(date('d.m.Y', strtotime($o['valid_from']))) ?> → <?= $o['valid_to'] ? h(date('d.m.Y', strtotime($o['valid_to']))) : 'devam ediyor' ?></td>
    <td><span class="pdks-badge <?= $o['is_active'] ? 'pdks-badge-aktif' : 'pdks-badge-pasif' ?>"><?= $o['is_active'] ? 'Aktif' : 'Pasif' ?></span></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>

<div class="pdks-cards mobile-only">
<?php foreach ($oranlar as $o): ?>
<div class="pdks-card-item">
    <div class="pdks-card-top"><div class="pdks-card-meta"><div class="pdks-row-name"><?= h($o['worker_type_name']) ?></div><div class="pdks-row-sub"><?= h(date('d.m.Y', strtotime($o['valid_from']))) ?> → <?= $o['valid_to'] ? h(date('d.m.Y', strtotime($o['valid_to']))) : 'devam ediyor' ?></div></div><span class="pdks-badge <?= $o['is_active'] ? 'pdks-badge-aktif' : 'pdks-badge-pasif' ?>"><?= $o['is_active'] ? 'Aktif' : 'Pasif' ?></span></div>
    <div class="pdks-row-sub"><?= $faz8bHazir ? 'Tam' : 'Günlük' ?>: <strong><?= h(number_format((float)$o['daily_rate'],2,',','.')) ?> <?= h($o['currency']) ?></strong></div>
    <?php if ($faz8bHazir): ?><div class="pdks-row-sub">Yarım: <?= ($o['half_day_rate'] ?? null) !== null ? h(number_format((float)$o['half_day_rate'],2,',','.') . ' ' . $o['currency']) : '—' ?></div><div class="pdks-row-sub">FM: <?= ($o['overtime_rate'] ?? null) !== null ? h(number_format((float)$o['overtime_rate'],2,',','.') . ' ' . $o['currency'] . ' · ' . (($o['overtime_mode'] ?? '') === 'fixed' ? 'Sabit' : 'Saatlik')) : '—' ?></div><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php render_footer(); ?>
