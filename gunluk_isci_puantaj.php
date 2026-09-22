<?php
// =========================================================
// gunluk_isci_puantaj.php — Günlük Puantaj Raporu (Günlük İşçi, Faz 3)
//
// SALT OKUNUR: hakediş/fiyat/ödeme/cari/fatura YOK (kullanıcının açık
// talimatı, sonraki faza bırakıldı). Bu sayfa KENDİ SQL'ini YAZMAZ — tüm
// toplama/özet mantığı config/pdks_gunluk.php'deki paylaşılan fonksiyonlardan
// (pdks_gunluk_gun_ozeti/pdks_gunluk_gun_listesi/pdks_gunluk_eksik_cikislar)
// gelir; aynı hesap iki yerde ayrışmasın diye.
//
// ⚠ DEPO FİLTRESİ: "Zorunlu tek depo" mimarisi (bkz. CLAUDE.md → Aktif Depo
// Sistemi) gereği kullanıcı HER ZAMAN tek bir aktif depoya bağlıdır —
// depo_sql_column()/user_allowed_depots() da AYNI ilkeyle çalışır ve
// admin dahil kimse aynı anda birden fazla depoyu serbestçe seçemez
// (repoda başka HİÇBİR sayfada da böyle bir "tüm depolar" seçici YOK,
// bkz. kantar.php). Bu yüzden buradaki "Depo" alanı SEÇİLEBİLİR bir
// filtre DEĞİL — aktif depoyu GÖSTEREN salt-okunur bir rozettir; depo
// değiştirmek için topbar/sidebar'daki mevcut depo değiştirici kullanılır.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
// ⚠ Faz 9E / F: "Manuel Çıkış Gir" derin bağlantısının İZİN kontrolü İÇİN
// (manuel_cikis.php'nin KENDİ kapısıyla AYNI yetki) — bu sayfanın SALT
// OKUNUR doğası (görev talimatı: "Do not build parallel business logic in
// the UI") DEĞİŞMEDİ, yalnız bağlantıyı göstermeden ÖNCE 403'e gideceğini
// bilmek için pdks_hakedis_can() OKUNUR.
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_gunluk('daily_reports');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
$manuelCikisYetkisi = function_exists('pdks_hakedis_can') && pdks_hakedis_can('entitlements_finalize');

// ── Filtreler ─────────────────────────────────────────────
$tarih = trim($_GET['tarih'] ?? '');
if ($tarih === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih) || !strtotime($tarih)) {
    $tarih = date('Y-m-d');
}
$cavusId = filter_var($_GET['cavus'] ?? '', FILTER_VALIDATE_INT) ?: null;
$durum_f = trim($_GET['durum'] ?? '');
if (!in_array($durum_f, ['acik', 'kapali', 'eksik_cikis'], true)) $durum_f = '';

$depo = function_exists('active_depot') ? (active_depot() ?? '') : '';

// ── Çavuş filtre listesi (aktif + pasif — geçmiş rapor için ikisi de gerekli) ──
$cavuslar = [];
try {
    $cavuslar = $pdo->query("SELECT id, code, name, is_active FROM foremen ORDER BY is_active DESC, name ASC")->fetchAll();
} catch (PDOException $e) { /* tablo henüz yoksa üstteki sayfa kapısı zaten durdurmuştur */ }

// ── Veri ──────────────────────────────────────────────────
$gunOzeti  = pdks_gunluk_gun_ozeti($tarih, $depo, $pdo);
$gunListesi = pdks_gunluk_gun_listesi($tarih, $depo, $cavusId, $durum_f !== '' ? $durum_f : null, $pdo);
$eksikler   = pdks_gunluk_eksik_cikislar($tarih, $depo, $cavusId, $pdo);

// ── CSV export — MEVCUT filtreyi yansıtır (görev talimatı madde 12) ──
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="gunluk_puantaj_' . $tarih . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    // ⚠ Faz 7 (kullanıcının açık talimatı: "ALL downloaded report column
    // headings must be Turkish"): başlıklar Türkçe iş terminolojisiyle.
    fputcsv($out, ['Tarih', 'Depo', 'Çavuş', 'Kadın Sayısı', 'Erkek Sayısı', 'Toplam Giriş',
                   'Toplam Çıkış', 'Eksik Çıkış', 'Mesai Durumu', 'İlk Giriş', 'Son Çıkış'], ';', '"', '\\');
    foreach ($gunListesi as $row) {
        $s = $row['session'];
        fputcsv($out, [
            $s['work_date'],
            $s['depo'],
            $s['foreman_name_snapshot'],
            $row['giris']['Kadın'] ?? 0,
            $row['giris']['Erkek'] ?? 0,
            $row['giris_toplam'],
            $row['cikis_toplam'],
            $row['eksik_toplam'],
            $row['durum']['etiket'],
            $row['ilk_giris'] ? date('H:i', strtotime($row['ilk_giris'])) : '',
            $row['son_cikis'] ? date('H:i', strtotime($row['son_cikis'])) : '',
        ], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

render_header('Günlük Puantaj');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();

$durum_secenekleri = ['' => 'Tümü', 'acik' => 'Açık', 'kapali' => 'Kapalı', 'eksik_cikis' => 'Eksik Çıkışlı'];
?>

<div class="page-head">
    <h1>📅 Günlük Puantaj</h1>
    <div class="page-head-actions">
        <a href="<?= h('gunluk_puantaj_liste_yazdir.php?' . http_build_query(array_filter(['tarih' => $tarih, 'cavus' => $cavusId, 'durum' => $durum_f], fn($v) => $v !== null && $v !== ''))) ?>" class="btn" target="_blank" rel="noopener">🖨️ Yazdır</a>
        <a href="personel_takip.php" class="btn btn-ghost">← Personel Takibi</a>
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
    <a href="?<?= h(http_build_query(array_filter(['tarih' => $tarih, 'cavus' => $cavusId, 'durum' => $durum_f, 'csv' => '1'], fn($v) => $v !== null && $v !== ''))) ?>" class="btn btn-ghost">⬇ CSV</a>
    <?php if ($cavusId !== null || $durum_f !== '' || $tarih !== date('Y-m-d')): ?>
    <a href="gunluk_isci_puantaj.php" class="btn btn-ghost">Temizle</a>
    <?php endif; ?>
</form>

<div class="pdks-kiosk-counters" style="margin:0 0 18px">
    <h3><?= h(date('d.m.Y', strtotime($tarih))) ?><?= $depo !== '' ? ' — ' . h($depo) : '' ?></h3>
    <div class="pdks-kiosk-counter-totals">
        <div class="pdks-kiosk-counter-box"><div class="lbl">Aktif Çavuş</div><div class="val"><?= (int)$gunOzeti['aktif_cavus'] ?></div></div>
        <div class="pdks-kiosk-counter-box"><div class="lbl">Kadın İşçi</div><div class="val"><?= (int)($gunOzeti['giris']['Kadın'] ?? 0) ?></div></div>
        <div class="pdks-kiosk-counter-box"><div class="lbl">Erkek İşçi</div><div class="val"><?= (int)($gunOzeti['giris']['Erkek'] ?? 0) ?></div></div>
        <div class="pdks-kiosk-counter-box"><div class="lbl">Toplam İşçi</div><div class="val"><?= (int)$gunOzeti['giris_toplam'] ?></div></div>
        <div class="pdks-kiosk-counter-box"><div class="lbl">Tam Çıkış</div><div class="val"><?= (int)$gunOzeti['tam_cikis'] ?></div></div>
        <div class="pdks-kiosk-counter-box eksik"><div class="lbl">Eksik Çıkış</div><div class="val"><?= (int)$gunOzeti['eksik_cikis'] ?></div></div>
    </div>
</div>

<?php if (empty($gunListesi)): ?>
<div class="pdks-empty">
    <span class="pdks-empty-icon" aria-hidden="true">📅</span>
    <p>Bu tarih/filtrelerde mesai kaydı bulunamadı.</p>
</div>
<?php else: ?>

<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr>
    <th>Çavuş</th>
    <th>Depo</th>
    <th>Kadın</th>
    <th>Erkek</th>
    <th>Toplam Giriş</th>
    <th>Toplam Çıkış</th>
    <th>Eksik</th>
    <th>İlk Giriş</th>
    <th>Son Çıkış</th>
    <th>Durum</th>
    <th class="actions-col">İşlem</th>
</tr></thead>
<tbody>
<?php foreach ($gunListesi as $row): $s = $row['session']; ?>
<tr>
    <td class="pdks-row-name"><?= h($s['foreman_name_snapshot']) ?></td>
    <td class="muted"><?= h($s['depo'] ?: '—') ?></td>
    <td><?= (int)($row['giris']['Kadın'] ?? 0) ?></td>
    <td><?= (int)($row['giris']['Erkek'] ?? 0) ?></td>
    <td><strong><?= (int)$row['giris_toplam'] ?></strong></td>
    <td><?= (int)$row['cikis_toplam'] ?></td>
    <td><?= $row['eksik_toplam'] > 0 ? '<span style="color:var(--warn);font-weight:700">' . (int)$row['eksik_toplam'] . '</span>' : '0' ?></td>
    <td class="muted"><?= $row['ilk_giris'] ? h(date('H:i', strtotime($row['ilk_giris']))) : '—' ?></td>
    <td class="muted"><?= $row['son_cikis'] ? h(date('H:i', strtotime($row['son_cikis']))) : '—' ?></td>
    <td><span class="pdks-badge pdks-badge-<?= h($row['durum']['kod']) ?>"><?= h($row['durum']['etiket']) ?></span></td>
    <td class="actions-col">
        <a href="gunluk_isci_puantaj_detay.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm">Detay</a>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="pdks-cards mobile-only">
<?php foreach ($gunListesi as $row): $s = $row['session']; ?>
<a href="gunluk_isci_puantaj_detay.php?id=<?= (int)$s['id'] ?>" class="pdks-card-item" style="text-decoration:none;color:inherit">
    <div class="pdks-card-top">
        <div class="pdks-card-meta">
            <div class="pdks-row-name"><?= h($s['foreman_name_snapshot']) ?></div>
            <div class="pdks-row-sub"><?= h(date('d.m.Y', strtotime($s['work_date']))) ?><?= $s['depo'] ? ' / ' . h($s['depo']) : '' ?></div>
        </div>
        <span class="pdks-badge pdks-badge-<?= h($row['durum']['kod']) ?>"><?= h($row['durum']['etiket']) ?></span>
    </div>
    <div class="pdks-kiosk-counter-row"><span>Kadın</span><span class="n"><?= (int)($row['giris']['Kadın'] ?? 0) ?></span></div>
    <div class="pdks-kiosk-counter-row"><span>Erkek</span><span class="n"><?= (int)($row['giris']['Erkek'] ?? 0) ?></span></div>
    <div class="pdks-kiosk-counter-row"><span>Toplam</span><span class="n"><strong><?= (int)$row['giris_toplam'] ?></strong></span></div>
    <div class="pdks-kiosk-counter-row"><span>Eksik</span><span class="n"<?= $row['eksik_toplam'] > 0 ? ' style="color:var(--warn);font-weight:700"' : '' ?>><?= (int)$row['eksik_toplam'] ?></span></div>
    <div class="pdks-row-sub">İlk giriş: <?= $row['ilk_giris'] ? h(date('H:i', strtotime($row['ilk_giris']))) : '—' ?> · Son çıkış: <?= $row['son_cikis'] ? h(date('H:i', strtotime($row['son_cikis']))) : '—' ?></div>
</a>
<?php endforeach; ?>
</div>

<?php endif; ?>

<div class="page-head" style="margin-top:28px">
    <h2 style="margin:0;font-size:1.05rem">⚠️ Eksik Çıkışlar — <?= h(date('d.m.Y', strtotime($tarih))) ?></h2>
</div>

<?php if (empty($eksikler)): ?>
<div class="pdks-empty">
    <span class="pdks-empty-icon" aria-hidden="true">✅</span>
    <p>Bu tarih/filtrelerde eksik çıkış yok.</p>
</div>
<?php else: ?>

<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr>
    <th>Çavuş</th>
    <th>Kart No</th>
    <th>Tip</th>
    <th>Giriş Saati</th>
    <th>Mesai Durumu</th>
    <th>Kayıt Türü</th>
    <th>Kapanış Notu</th>
    <th class="actions-col">İşlem</th>
</tr></thead>
<tbody>
<?php foreach ($eksikler as $e):
    // ⚠ Faz 9E / F: "Açık Mesai" (oturum hâlâ açık) ile "Eksik Çıkış"
    // (oturum kapandı ama çıkış hiç okutulmadı) — MEVCUT anlam AYNEN
    // korunur, yalnız dönemin GERÇEK kaydı (canlı 'open' mı, geriye
    // aktarılmış 'legacy_unresolved' mı) EK bir rozetle netleştirilir.
    $donemDurum = $e['donem_durumu'] ?? null;
    $donemRozet = $donemDurum ? pdks_gunluk_faz8a_donem_durumu((string)$donemDurum) : null;
    $manuelUygun = !empty($e['period_id']) && in_array($donemDurum, ['open', 'legacy_unresolved'], true);
?>
<tr>
    <td class="pdks-row-name"><?= h($e['cavus_adi']) ?></td>
    <td class="pdks-uid"><?= h($e['card_no']) ?></td>
    <td><?= h($e['tip']) ?></td>
    <td class="muted"><?= h(date('H:i', strtotime($e['giris_saat']))) ?></td>
    <td>
        <span class="pdks-badge <?= $e['oturum_durumu'] === 'closed' ? 'pdks-badge-pasif' : 'pdks-badge-acik' ?>">
            <?= $e['oturum_durumu'] === 'closed' ? 'Kapalı' : 'Açık' ?>
        </span>
        <?php if ($e['oturum_kapali_mesaji']): ?>
        <div class="pdks-row-sub" style="color:var(--warn)"><?= h($e['oturum_kapali_mesaji']) ?></div>
        <?php endif; ?>
    </td>
    <td><?php if ($donemRozet): ?><span class="pdks-badge pdks-badge-<?= h($donemRozet['kod']) ?>"><?= h($donemRozet['etiket']) ?></span><?php else: ?>—<?php endif; ?></td>
    <td class="muted"><?= h($e['kapanis_notu'] ?: '—') ?></td>
    <td class="actions-col">
        <?php if ($manuelUygun && $manuelCikisYetkisi): ?>
        <a href="manuel_cikis.php?period_id=<?= (int)$e['period_id'] ?>&session_id=<?= (int)$e['session_id'] ?>" class="btn btn-sm">✍️ Manuel Çıkış Gir</a>
        <?php elseif ($manuelUygun): ?>
        <span class="muted" style="font-size:.85em">Manuel düzeltme yetkisi gerekir</span>
        <?php else: ?>—<?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="pdks-cards mobile-only">
<?php foreach ($eksikler as $e):
    $donemDurum = $e['donem_durumu'] ?? null;
    $donemRozet = $donemDurum ? pdks_gunluk_faz8a_donem_durumu((string)$donemDurum) : null;
    $manuelUygun = !empty($e['period_id']) && in_array($donemDurum, ['open', 'legacy_unresolved'], true);
?>
<div class="pdks-card-item">
    <div class="pdks-card-top">
        <div class="pdks-card-meta">
            <div class="pdks-row-name"><?= h($e['card_no']) ?> · <?= h($e['tip']) ?></div>
            <div class="pdks-row-sub"><?= h($e['cavus_adi']) ?> · Giriş <?= h(date('H:i', strtotime($e['giris_saat']))) ?></div>
        </div>
        <span class="pdks-badge <?= $e['oturum_durumu'] === 'closed' ? 'pdks-badge-pasif' : 'pdks-badge-acik' ?>">
            <?= $e['oturum_durumu'] === 'closed' ? 'Kapalı' : 'Açık' ?>
        </span>
    </div>
    <?php if ($donemRozet): ?><div class="pdks-row-sub"><span class="pdks-badge pdks-badge-<?= h($donemRozet['kod']) ?>"><?= h($donemRozet['etiket']) ?></span></div><?php endif; ?>
    <?php if ($e['oturum_kapali_mesaji']): ?>
    <div class="pdks-row-sub" style="color:var(--warn)"><?= h($e['oturum_kapali_mesaji']) ?><?= $e['kapanis_notu'] ? ' — ' . h($e['kapanis_notu']) : '' ?></div>
    <?php endif; ?>
    <?php if ($manuelUygun && $manuelCikisYetkisi): ?>
    <div style="margin-top:8px"><a href="manuel_cikis.php?period_id=<?= (int)$e['period_id'] ?>&session_id=<?= (int)$e['session_id'] ?>" class="btn btn-sm">✍️ Manuel Çıkış Gir</a></div>
    <?php elseif ($manuelUygun): ?>
    <div class="pdks-row-sub muted">Manuel düzeltme yetkisi gerekir</div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php render_footer(); ?>
