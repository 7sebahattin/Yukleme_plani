<?php
// =========================================================
// cavus_hakedis.php — Çavuş Hakediş Listesi
// Faz 8B hazırsa muhasebe mesai değerlendirmesi + yeni ücret motorunu kullanır.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_hakedis('entitlements_view');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_hakedis_sayfa_kapisi($pdo);
$faz8bHazir = pdks_faz8b_sema_hazir($pdo);

$flashHata = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hesapla') {
    csrf_check($_POST['csrf'] ?? null);
    // ⚠ Faz 9A / M-04 düzeltmesi: hesapla/yeniden hesapla bir FİNANSAL
    // YAZMADIR (taslak satırları siler ve yeniden yazar) — salt-okunur
    // 'entitlements_view' bunun için yeterli değildi. Değerlendirme
    // (mesai_degerlendirme.php) ve kesinleştirme İLE AYNI izne hizalandı.
    require_pdks_hakedis('entitlements_finalize');
    $sid = filter_var($_POST['session_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
    if ($sid) {
        // ⚠ Faz 9A / M-01 düzeltmesi: session_id istemciden geliyor — hesaplama
        // ÖNCESİ oturumun aktif depoya ait olduğu SUNUCU tarafında doğrulanır.
        $stSid = $pdo->prepare('SELECT depo FROM daily_work_sessions WHERE id=?');
        $stSid->execute([$sid]);
        $sidDepo = $stSid->fetchColumn();
        if ($sidDepo === false) {
            $flashHata = 'Mesai bulunamadı.';
        } elseif ($depoHata = pdks_gunluk_depo_kontrol((string)$sidDepo)) {
            $flashHata = $depoHata;
        } else {
            $sonuc = $faz8bHazir
                ? pdks_faz8b_hakedis_hesapla($sid, (int)$auth_user['id'], $pdo)
                : pdks_hakedis_hesapla($sid, (int)$auth_user['id'], $pdo);
            if ($sonuc['ok']) {
                header('Location: cavus_hakedis_detay.php?id=' . (int)$sonuc['entitlement_id']);
                exit;
            }
            $flashHata = $sonuc['hata'] ?? 'Hesaplanamadı.';
        }
    }
}

$tarih = trim($_GET['tarih'] ?? '');
if ($tarih === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih) || !strtotime($tarih)) $tarih = date('Y-m-d');
$cavusId = filter_var($_GET['cavus'] ?? '', FILTER_VALIDATE_INT) ?: null;
$durum_f = trim($_GET['durum'] ?? '');
if (!in_array($durum_f, ['hesaplanmadi', 'draft', 'final'], true)) $durum_f = '';
$depo = function_exists('active_depot') ? (active_depot() ?? '') : '';

$cavuslar = [];
try { $cavuslar = $pdo->query("SELECT id, code, name, is_active FROM foremen ORDER BY is_active DESC, name ASC")->fetchAll(); }
catch (PDOException $e) { $cavuslar = []; }

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
    $faz8bOzet = $faz8bHazir ? pdks_faz8b_oturum_ozeti($sid, $pdo) : null;
    $satirlar[] = ['puantaj' => $row, 'hakedis' => $hk, 'hakedis_durum' => $hkDurum, 'faz8b' => $faz8bOzet];
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

<?php if (!$faz8bHazir): ?>
<div class="flash flash-warning">Faz 8B şeması henüz çalıştırılmadı. Hakediş ekranı eski güvenli davranışla devam ediyor. Yönetici <a href="faz8b_migrate.php">Faz 8B migrasyonunu</a> çalıştırabilir.</div>
<?php endif; ?>

<form method="get" class="pdks-filter-bar">
    <input type="date" name="tarih" value="<?= h($tarih) ?>">
    <select name="cavus">
        <option value="">Tüm çavuşlar</option>
        <?php foreach ($cavuslar as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $cavusId === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?><?= $c['is_active'] ? '' : ' (pasif)' ?></option>
        <?php endforeach; ?>
    </select>
    <select name="depo" disabled><option><?= h($depo !== '' ? $depo : 'Depo seçilmemiş') ?></option></select>
    <select name="durum">
        <?php foreach ($durum_secenekleri as $val => $etiket): ?><option value="<?= h($val) ?>" <?= $durum_f === $val ? 'selected' : '' ?>><?= h($etiket) ?></option><?php endforeach; ?>
    </select>
    <button type="submit" class="btn">Filtrele</button>
    <?php if ($cavusId !== null || $durum_f !== '' || $tarih !== date('Y-m-d')): ?><a href="cavus_hakedis.php" class="btn btn-ghost">Temizle</a><?php endif; ?>
</form>

<?php if (empty($satirlar)): ?>
<div class="pdks-empty"><span class="pdks-empty-icon">🧾</span><p>Bu tarih/filtrelerde mesai kaydı bulunamadı.</p></div>
<?php else: ?>
<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr>
    <th>Çavuş</th><th>Kadın</th><th>Erkek</th><th>Toplam</th><th>Mesai Değ.</th><th>Hakediş</th><th>Durum</th><th>Uyarı</th><th>İşlem</th>
</tr></thead>
<tbody>
<?php foreach ($satirlar as $s): $p=$s['puantaj']; $sess=$p['session']; $hk=$s['hakedis']; [$etkt,$ekod]=$durumEtiket[$s['hakedis_durum']]; $f8=$s['faz8b']; ?>
<tr>
    <td class="pdks-row-name"><?= h($sess['foreman_name_snapshot']) ?></td>
    <td><?= (int)($p['giris']['Kadın'] ?? 0) ?></td>
    <td><?= (int)($p['giris']['Erkek'] ?? 0) ?></td>
    <td><strong><?= (int)$p['giris_toplam'] ?></strong></td>
    <td>
        <?php if (!$faz8bHazir): ?>—
        <?php elseif ($f8['tam_hazir']): ?><span class="pdks-badge pdks-badge-tamamlandi">Hazır</span>
        <?php else: ?><span class="pdks-badge pdks-badge-eksik_cikis">Bekliyor <?= (int)$f8['hazir'] ?>/<?= (int)$f8['toplam'] ?></span><?php endif; ?>
    </td>
    <td><?= $hk ? h(number_format((float)$hk['total_amount'],2,',','.') . ' ' . $hk['currency']) : '—' ?>
        <?php if ($hk && !empty($hk['needs_recalculation'])): ?>
        <br><span class="pdks-badge pdks-badge-eksik_cikis" title="Puantaj / mesai değerlendirmesi hakediş taslağından sonra değişti.">Yeniden hesaplama gerekli</span>
        <?php endif; ?>
    </td>
    <td><span class="pdks-badge pdks-badge-<?= h($ekod) ?>"><?= h($etkt) ?></span></td>
    <td><?php if ((int)$p['eksik_toplam'] > 0): ?><span class="pdks-badge pdks-badge-eksik_cikis">⚠️ Eksik Çıkış</span><?php endif; ?></td>
    <td>
        <?php if ($faz8bHazir && pdks_hakedis_can('entitlements_finalize')): ?><a href="mesai_degerlendirme.php?session_id=<?= (int)$sess['id'] ?>" class="btn btn-sm">Mesai Değerlendir</a><?php endif; ?>
        <?php if ($hk): ?><a href="cavus_hakedis_detay.php?id=<?= (int)$hk['id'] ?>" class="btn btn-sm">Detay</a>
        <?php else: ?>
        <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="hesapla"><input type="hidden" name="session_id" value="<?= (int)$sess['id'] ?>">
            <button type="submit" class="btn btn-sm btn-primary">Hesapla</button>
        </form>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody></table></div>

<div class="pdks-cards mobile-only">
<?php foreach ($satirlar as $s): $p=$s['puantaj']; $sess=$p['session']; $hk=$s['hakedis']; [$etkt,$ekod]=$durumEtiket[$s['hakedis_durum']]; $f8=$s['faz8b']; ?>
<div class="pdks-card-item">
    <div class="pdks-card-top"><div class="pdks-card-meta"><div class="pdks-row-name"><?= h($sess['foreman_name_snapshot']) ?></div><div class="pdks-row-sub"><?= h(date('d.m.Y',strtotime($sess['work_date']))) ?><?= $sess['depo'] ? ' / '.h($sess['depo']) : '' ?></div></div><span class="pdks-badge pdks-badge-<?= h($ekod) ?>"><?= h($etkt) ?></span></div>
    <div class="pdks-kiosk-counter-row"><span>Toplam İşçi</span><span class="n"><strong><?= (int)$p['giris_toplam'] ?></strong></span></div>
    <?php if ($faz8bHazir): ?><div class="pdks-row-sub">Mesai değerlendirme: <?= $f8['tam_hazir'] ? 'Hazır' : 'Bekliyor ' . (int)$f8['hazir'] . '/' . (int)$f8['toplam'] ?></div><?php endif; ?>
    <div class="pdks-row-sub">Hakediş: <?= $hk ? h(number_format((float)$hk['total_amount'],2,',','.') . ' ' . $hk['currency']) : '—' ?></div>
    <?php if ($hk && !empty($hk['needs_recalculation'])): ?>
    <div class="pdks-row-sub" style="color:var(--warn);font-weight:600">⚠️ Yeniden hesaplama gerekli — puantaj/mesai değerlendirmesi taslaktan sonra değişti.</div>
    <?php endif; ?>
    <div style="margin-top:8px">
        <?php if ($faz8bHazir && pdks_hakedis_can('entitlements_finalize')): ?><a href="mesai_degerlendirme.php?session_id=<?= (int)$sess['id'] ?>" class="btn btn-sm">Mesai Değerlendir</a><?php endif; ?>
        <?php if ($hk): ?><a href="cavus_hakedis_detay.php?id=<?= (int)$hk['id'] ?>" class="btn btn-sm">Detay</a>
        <?php else: ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="hesapla"><input type="hidden" name="session_id" value="<?= (int)$sess['id'] ?>"><button class="btn btn-sm btn-primary">Hesapla</button></form><?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php render_footer(); ?>
