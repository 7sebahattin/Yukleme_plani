<?php
// =========================================================
// raporlar.php — Yönetim Raporlama Merkezi (Günlük İşçi, Faz 6)
//
// SALT OKUNUR — kendi SQL'ini yazmaz, TÜM toplama/agregasyon mantığı
// config/pdks_rapor.php'deki paylaşılan bulk fonksiyonlardan gelir (görev
// talimatı: "Phase 6 must be READ-ONLY reporting... Do not reimplement
// accounting math."). Faz 1-5 otoriter kaynaklarının ÜZERİNE oturur.
//
// ⚠ FİNANSAL BÖLÜMLER BÖLÜM-SEVİYESİNDE KAPILIDIR (görev talimatı: "Prefer
// section-level permission enforcement rather than duplicating pages."):
// sayfa attendance.management_reports ile açılır (require_pdks_rapor()),
// ama hakediş/ödeme/bakiye/cari-sıralama blokları AYRICA
// pdks_rapor_can('financial') (= attendance.foreman_accounts, Faz 5'in
// KENDİ izni — YENİ bir izin İCAT EDİLMEDİ) ister. 'ik' rolü
// attendance.management_reports ALIR ama attendance.foreman_accounts
// ALMAZ — operasyonel bölümleri görür, finansal rakamları GÖRMEZ.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_cari.php';
require_once __DIR__ . '/config/pdks_rapor.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_rapor();

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);   // operasyonel veri Faz 2/3'e SERT bağımlı — hazır değilse burada durur.

$finansalYetki = pdks_rapor_can('financial');
$semaDurumu = pdks_rapor_sema_durumu($pdo);
$finansalGosterilebilir = $finansalYetki && $semaDurumu['hakedis'] && $semaDurumu['cari'];

$depo = function_exists('active_depot') ? (active_depot() ?? '') : '';

// ── Filtreler ─────────────────────────────────────────────
$preset = trim($_GET['donem'] ?? 'bugun');
if (!array_key_exists($preset, pdks_rapor_presetler())) $preset = 'bugun';
$baslangicGirdi = trim($_GET['baslangic'] ?? '');
$bitisGirdi = trim($_GET['bitis'] ?? '');
$araligi = pdks_rapor_tarih_araligi($preset, $baslangicGirdi ?: null, $bitisGirdi ?: null);
$start = $araligi['start']; $end = $araligi['end'];

$cavusId = filter_var($_GET['cavus'] ?? '', FILTER_VALIDATE_INT) ?: null;
$tipId   = filter_var($_GET['tip'] ?? '', FILTER_VALIDATE_INT) ?: null;

$cavuslar = [];
try {
    $cavuslar = $pdo->query("SELECT id, code, name, is_active FROM foremen ORDER BY is_active DESC, name ASC")->fetchAll();
} catch (PDOException $e) { /* sayfa kapısı zaten durdurmuştur */ }
$tipler = [];
try {
    $tipler = $pdo->query("SELECT id, code, name FROM worker_types ORDER BY sort_order ASC, name ASC")->fetchAll();
} catch (PDOException $e) { /* aynı */ }

// ── Veri (görev talimatı: N+1 yok, her biri TEK GEÇİŞTE toplu) ──
$kpi        = pdks_rapor_operasyonel_kpi($start, $end, $depo, $cavusId, $tipId, $pdo);
$tipDagilim = pdks_rapor_isci_tipi_dagilimi($start, $end, $depo, $cavusId, $pdo, $tipId);
$trend      = pdks_rapor_gunluk_trend($start, $end, $depo, $cavusId, $tipId, $pdo);
$cavusOzeti = pdks_rapor_cavus_ozeti($start, $end, $depo, $cavusId, $tipId, $pdo);
$eksikler   = pdks_rapor_eksik_cikislar_araligi($start, $end, $depo, $cavusId, $pdo, $tipId);
$acikMesai  = pdks_rapor_acik_mesailer_araligi($start, $end, $depo, $cavusId, $pdo, $tipId);

$finansalKpi = []; $guncelBakiye = []; $bakiyeSiralama = []; $karsilastirma = null;
if ($finansalGosterilebilir) {
    $finansalKpi    = pdks_rapor_finansal_kpi($start, $end, $depo, $cavusId, $pdo);
    $guncelBakiye   = pdks_rapor_bakiye_toplu($depo, $cavusId, $pdo);
    $bakiyeSiralama = pdks_rapor_cavus_bakiye_siralamasi($depo, $cavusId, $pdo);
    $karsilastirma  = pdks_rapor_karsilastirma($preset, $start, $end, $depo, $cavusId, $tipId, $pdo);
}

// ── CSV DIŞA AKTARIM — MEVCUT filtreyi yansıtır (görev madde 12), HTML
//    çıktısından ÖNCE. İki AYRI dosya (görev talimatı: "Prefer separate
//    exports if one giant CSV would be ambiguous"). ──
$csvTuru = trim($_GET['csv'] ?? '');
if ($csvTuru === 'gunluk' || $csvTuru === 'cavus') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="rapor_' . $csvTuru . '_' . $start . '_' . $end . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");

    // ⚠ Faz 7 (kullanıcının açık talimatı: "ALL downloaded report column
    // headings must be Turkish"): başlıklar Türkçe iş terminolojisiyle.
    if ($csvTuru === 'gunluk') {
        $baslik = ['Tarih', 'İşçi Sayısı', 'Çavuş Sayısı', 'Eksik Çıkış Sayısı'];
        if ($finansalGosterilebilir) $baslik = array_merge($baslik, ['Hakediş (para birimi: tutar)', 'Ödeme (para birimi: tutar)', 'Net Hareket (para birimi: tutar)']);
        fputcsv($out, $baslik, ';', '"', '\\');
        foreach ($trend as $g) {
            $satir = [$g['tarih'], $g['toplam_calisan'], $g['aktif_cavus'], $g['eksik_cikis']];
            if ($finansalGosterilebilir) {
                $fmt = fn(array $m) => implode(' | ', array_map(fn($c, $v) => "$c: $v", array_keys($m), $m));
                $satir[] = $fmt($g['hakedis']); $satir[] = $fmt($g['odeme']); $satir[] = $fmt($g['net']);
            }
            fputcsv($out, $satir, ';', '"', '\\');
        }
    } else {
        $baslik = ['Çavuş', 'Çalışılan Gün', 'Toplam İşçi', 'Eksik Çıkış'];
        if ($finansalGosterilebilir) $baslik = array_merge($baslik, ['Dönem Hakedişi (para birimi: tutar)', 'Dönem Ödemesi (para birimi: tutar)', 'Güncel Bakiye (para birimi: tutar)']);
        fputcsv($out, $baslik, ';', '"', '\\');
        foreach ($cavusOzeti as $c) {
            $satir = [$c['foreman']['name'], $c['calisilan_gun'], $c['toplam_isci'], $c['eksik_cikis']];
            if ($finansalGosterilebilir) {
                $fmt = fn(array $m) => implode(' | ', array_map(fn($cur, $v) => "$cur: $v", array_keys($m), $m));
                $bal = array_map(fn($b) => $b['bakiye'], $c['guncel_bakiye']);
                $satir[] = $fmt($c['donem_hakedis']); $satir[] = $fmt($c['donem_odeme']); $satir[] = $fmt($bal);
            }
            fputcsv($out, $satir, ';', '"', '\\');
        }
    }
    fclose($out);
    exit;
}

render_header('Yönetim Raporları');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head">
    <h1>📊 Yönetim Raporları</h1>
    <div class="page-head-actions">
        <a href="<?= h('rapor_yazdir.php?' . http_build_query(array_filter(['donem' => $preset, 'baslangic' => $start, 'bitis' => $end, 'cavus' => $cavusId, 'tip' => $tipId], fn($v) => $v !== null && $v !== ''))) ?>" class="btn btn-ghost">🖨️ Yazdır</a>
        <a href="personel_takip.php" class="btn btn-ghost">← Personel Takibi</a>
    </div>
</div>

<form method="get" class="pdks-filter-bar">
    <select name="donem" onchange="document.getElementById('rpOzelAlan').style.display = this.value==='ozel' ? 'inline-flex' : 'none'">
        <?php foreach (pdks_rapor_presetler() as $val => $etiket): ?>
        <option value="<?= h($val) ?>" <?= $preset === $val ? 'selected' : '' ?>><?= h($etiket) ?></option>
        <?php endforeach; ?>
    </select>
    <span id="rpOzelAlan" style="display:<?= $preset === 'ozel' ? 'inline-flex' : 'none' ?>;gap:8px">
        <input type="date" name="baslangic" value="<?= h($start) ?>">
        <input type="date" name="bitis" value="<?= h($end) ?>">
    </span>
    <select name="cavus">
        <option value="">Tüm çavuşlar</option>
        <?php foreach ($cavuslar as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $cavusId === (int)$c['id'] ? 'selected' : '' ?>>
            <?= h($c['name']) ?><?= $c['is_active'] ? '' : ' (pasif)' ?>
        </option>
        <?php endforeach; ?>
    </select>
    <select name="tip">
        <option value="">Tüm işçi tipleri</option>
        <?php foreach ($tipler as $t): ?>
        <option value="<?= (int)$t['id'] ?>" <?= $tipId === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="depo" disabled title="Depo değiştirmek için üstteki/soldaki depo rozetini kullanın">
        <option><?= h($depo !== '' ? $depo : 'Depo seçilmemiş') ?></option>
    </select>
    <button type="submit" class="btn">Filtrele</button>
    <a href="?<?= h(http_build_query(array_filter(['donem' => $preset, 'baslangic' => $start, 'bitis' => $end, 'cavus' => $cavusId, 'tip' => $tipId, 'csv' => 'gunluk'], fn($v) => $v !== null && $v !== ''))) ?>" class="btn btn-ghost">⬇ Günlük CSV</a>
    <a href="?<?= h(http_build_query(array_filter(['donem' => $preset, 'baslangic' => $start, 'bitis' => $end, 'cavus' => $cavusId, 'tip' => $tipId, 'csv' => 'cavus'], fn($v) => $v !== null && $v !== ''))) ?>" class="btn btn-ghost">⬇ Çavuş CSV</a>
    <?php if ($cavusId !== null || $tipId !== null || $preset !== 'bugun'): ?>
    <a href="raporlar.php" class="btn btn-ghost">Temizle</a>
    <?php endif; ?>
</form>

<p class="muted" style="font-size:.85rem;margin:-6px 0 14px">
    <?= h(date('d.m.Y', strtotime($start))) ?><?= $start !== $end ? ' – ' . h(date('d.m.Y', strtotime($end))) : '' ?><?= $depo !== '' ? ' · ' . h($depo) : '' ?>
    <?php if (!$finansalGosterilebilir && $finansalYetki && (!$semaDurumu['hakedis'] || !$semaDurumu['cari'])): ?>
    · <span style="color:var(--warn)">Finansal modüller (Hakediş/Cari) henüz kurulmadığı için finansal bölümler gösterilemiyor.</span>
    <?php endif; ?>
</p>

<!-- ═══ OPERASYONEL KPI (görev madde 2) ═══ -->
<div class="pdks-kiosk-counters rapor-genis">
    <h3>Operasyonel Özet</h3>
    <div class="pdks-kiosk-counter-totals">
        <!-- ⚠ FAZ 8A (görev talimatı §21): "İşçi Katılımı" = mesai dönemi
             (katılım) sayısı — "benzersiz çalışan" DEĞİL, çünkü nötr kartlar
             insan kimliğini KANITLAYAMAZ. Ayrıca, karıştırılmasın diye AYRI
             bir "Fiziksel Kart Kullanımı" (distinct kart) metriği gösterilir. -->
        <div class="pdks-kiosk-counter-box"><div class="lbl">İşçi Katılımı</div><div class="val"><?= (int)$kpi['toplam_calisan'] ?></div></div>
        <?php if (isset($kpi['fiziksel_kart_kullanimi'])): ?>
        <div class="pdks-kiosk-counter-box"><div class="lbl">Fiziksel Kart Kullanımı</div><div class="val"><?= (int)$kpi['fiziksel_kart_kullanimi'] ?></div></div>
        <?php endif; ?>
        <?php foreach ($tipDagilim as $t): if ($t['adet'] === 0 && $t['ad'] !== 'Kadın' && $t['ad'] !== 'Erkek') continue; ?>
        <div class="pdks-kiosk-counter-box"><div class="lbl"><?= h($t['ad']) ?></div><div class="val"><?= (int)$t['adet'] ?></div></div>
        <?php endforeach; ?>
        <div class="pdks-kiosk-counter-box"><div class="lbl">Aktif Çavuş</div><div class="val"><?= (int)$kpi['aktif_cavus'] ?></div></div>
        <div class="pdks-kiosk-counter-box"><div class="lbl">Tamamlanan Mesai</div><div class="val"><?= (int)$kpi['tamamlanan_mesai'] ?></div></div>
        <div class="pdks-kiosk-counter-box eksik"><div class="lbl">Eksik Çıkış</div><div class="val"><?= (int)$kpi['eksik_cikis_mesai'] ?></div></div>
        <div class="pdks-kiosk-counter-box"><div class="lbl">Açık Mesai</div><div class="val"><?= (int)$kpi['acik_mesai'] ?></div></div>
    </div>
</div>

<!-- ═══ FİNANSAL KPI (görev madde 3/4) ═══ -->
<?php if ($finansalGosterilebilir): ?>
<?php if (empty($finansalKpi) && empty($guncelBakiye)): ?>
<div class="pdks-empty"><span class="pdks-empty-icon" aria-hidden="true">💰</span><p>Bu dönemde/filtrede finansal hareket yok.</p></div>
<?php else:
$tumParaBirimleri = array_values(array_unique(array_merge(array_keys($finansalKpi), array_keys($guncelBakiye))));
foreach ($tumParaBirimleri as $cur):
    $f = $finansalKpi[$cur] ?? ['hakedis' => '0.00', 'odeme' => '0.00', 'net' => '0.00'];
    $b = $guncelBakiye[$cur] ?? ['bakiye' => '0.00', 'durum' => 'kapali', 'durum_etiket' => 'Hesap Kapalı'];
    $durumSinif = $b['durum'] === 'borc' ? 'borc' : ($b['durum'] === 'avans' ? 'avans' : 'kapali');
?>
<div class="pdks-kiosk-counters rapor-genis">
    <h3><?= h($cur) ?> — Finansal Özet (Dönem: <?= h(date('d.m.Y', strtotime($start))) ?><?= $start !== $end ? ' – ' . h(date('d.m.Y', strtotime($end))) : '' ?>)</h3>
    <div class="pdks-kiosk-counter-totals">
        <div class="pdks-kiosk-counter-box"><div class="lbl">Kesinleşmiş Hakediş</div><div class="val"><?= h(pdks_rapor_para_formatla($f['hakedis'])) ?></div></div>
        <div class="pdks-kiosk-counter-box"><div class="lbl">Yapılan Ödeme</div><div class="val"><?= h(pdks_rapor_para_formatla($f['odeme'])) ?></div></div>
        <div class="pdks-kiosk-counter-box"><div class="lbl">Dönem Net Hareket</div><div class="val"><?= h(pdks_rapor_para_formatla($f['net'])) ?></div></div>
        <div class="pdks-kiosk-counter-box <?= h($durumSinif) ?>"><div class="lbl">Güncel <?= h($b['durum_etiket']) ?></div><div class="val"><?= h(pdks_rapor_para_formatla(abs((float)$b['bakiye']))) ?></div></div>
    </div>
    <p class="muted" style="font-size:.78rem;margin:8px 0 0">
        ⚠ "Dönem Net Hareket" seçili tarih aralığının hareketidir; "Güncel <?= h($b['durum_etiket']) ?>" ise ŞU ANKİ toplam bakiyedir (önceki dönemleri de kapsar) — ikisi FARKLI kavramlardır, birbirinin yerine kullanılmamalıdır.
    </p>
</div>
<?php endforeach; endif; ?>
<?php elseif ($finansalYetki): ?>
<?php /* sema hazır değil — üstteki uyarı zaten gösterildi */ ?>
<?php else: ?>
<div class="pdks-empty"><span class="pdks-empty-icon" aria-hidden="true">🔒</span><p>Finansal veriler için gerekli yetkiniz yok.</p></div>
<?php endif; ?>

<!-- ═══ DÖNEM KARŞILAŞTIRMASI (görev madde 10) ═══ -->
<?php if ($karsilastirma !== null): ?>
<div class="page-head" style="margin-top:24px"><h2 style="margin:0;font-size:1.05rem">📈 Dönem Karşılaştırması</h2></div>
<div class="table-wrap">
<table class="data-table">
<thead><tr><th>Ölçüt</th><th>Önceki Dönem</th><th>Bu Dönem</th><th>Fark</th><th>Değişim</th></tr></thead>
<tbody>
<tr>
    <td>Çalışan Katılımı</td>
    <td><?= (int)$karsilastirma['onceki_calisan'] ?></td>
    <td><?= (int)$karsilastirma['simdi_calisan'] ?></td>
    <td><?= ($karsilastirma['calisan_degisim']['fark'] >= 0 ? '+' : '') . (int)$karsilastirma['calisan_degisim']['fark'] ?></td>
    <td><?= $karsilastirma['calisan_degisim']['yuzde'] === null ? ($karsilastirma['calisan_degisim']['yeni_mi'] ? 'Yeni' : '-') : h(number_format($karsilastirma['calisan_degisim']['yuzde'], 1, ',', '.')) . '%' ?></td>
</tr>
<?php if ($finansalGosterilebilir): foreach ($karsilastirma['hakedis_degisim'] as $cur => $d): ?>
<tr>
    <td>Hakediş (<?= h($cur) ?>)</td>
    <td><?= h(pdks_rapor_para_formatla($karsilastirma['onceki_finansal'][$cur]['hakedis'] ?? '0.00')) ?></td>
    <td><?= h(pdks_rapor_para_formatla($karsilastirma['simdi_finansal'][$cur]['hakedis'] ?? '0.00')) ?></td>
    <td><?= h(pdks_rapor_para_formatla(pdks_hakedis_kurus_tl($d['fark']))) ?></td>
    <td><?= $d['yuzde'] === null ? ($d['yeni_mi'] ? 'Yeni' : '-') : h(number_format($d['yuzde'], 1, ',', '.')) . '%' ?></td>
</tr>
<?php endforeach;
foreach ($karsilastirma['odeme_degisim'] as $cur => $d): ?>
<tr>
    <td>Ödeme (<?= h($cur) ?>)</td>
    <td><?= h(pdks_rapor_para_formatla($karsilastirma['onceki_finansal'][$cur]['odeme'] ?? '0.00')) ?></td>
    <td><?= h(pdks_rapor_para_formatla($karsilastirma['simdi_finansal'][$cur]['odeme'] ?? '0.00')) ?></td>
    <td><?= h(pdks_rapor_para_formatla(pdks_hakedis_kurus_tl($d['fark']))) ?></td>
    <td><?= $d['yuzde'] === null ? ($d['yeni_mi'] ? 'Yeni' : '-') : h(number_format($d['yuzde'], 1, ',', '.')) . '%' ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
<p class="muted" style="font-size:.78rem">Önceki dönem: <?= h(date('d.m.Y', strtotime($karsilastirma['onceki_araligi']['start']))) ?> – <?= h(date('d.m.Y', strtotime($karsilastirma['onceki_araligi']['end']))) ?></p>
<?php endif; ?>

<!-- ═══ İŞÇİ TİPİ DAĞILIMI (görev madde 7) ═══ -->
<div class="page-head" style="margin-top:24px"><h2 style="margin:0;font-size:1.05rem">👥 İşçi Tipi Dağılımı</h2></div>
<?php if (empty($tipDagilim) || $kpi['toplam_calisan'] === 0): ?>
<div class="pdks-empty"><span class="pdks-empty-icon" aria-hidden="true">👥</span><p>Bu tarih/filtrelerde işçi katılımı yok.</p></div>
<?php else: ?>
<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr><th>İşçi Tipi</th><th>Katılım</th><th>Yüzde</th></tr></thead>
<tbody>
<?php foreach ($tipDagilim as $t): ?>
<tr><td class="pdks-row-name"><?= h($t['ad']) ?></td><td><?= (int)$t['adet'] ?></td><td><?= h(number_format($t['yuzde'], 1, ',', '.')) ?>%</td></tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<div class="pdks-cards mobile-only">
<?php foreach ($tipDagilim as $t): ?>
<div class="pdks-card-item"><div class="pdks-kiosk-counter-row"><span><?= h($t['ad']) ?></span><span class="n"><?= (int)$t['adet'] ?> (<?= h(number_format($t['yuzde'], 1, ',', '.')) ?>%)</span></div></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ═══ GÜNLÜK TREND (görev madde 5) ═══ -->
<div class="page-head" style="margin-top:24px"><h2 style="margin:0;font-size:1.05rem">📅 Günlük Trend</h2></div>
<div class="table-wrap">
<table class="data-table">
<thead><tr>
    <th>Tarih</th><th>Çalışan</th><th>Çavuş</th><th>Eksik Çıkış</th>
    <?php if ($finansalGosterilebilir): ?><th>Hakediş</th><th>Ödeme</th><th>Net</th><?php endif; ?>
</tr></thead>
<tbody>
<?php foreach ($trend as $g): ?>
<tr>
    <td class="muted"><?= h(date('d.m.Y', strtotime($g['tarih']))) ?></td>
    <td><?= (int)$g['toplam_calisan'] ?></td>
    <td><?= (int)$g['aktif_cavus'] ?></td>
    <td><?= $g['eksik_cikis'] > 0 ? '<span style="color:var(--warn);font-weight:700">' . (int)$g['eksik_cikis'] . '</span>' : '0' ?></td>
    <?php if ($finansalGosterilebilir): ?>
    <td><?php if (empty($g['hakedis'])) echo '—'; else foreach ($g['hakedis'] as $cur => $tl) echo h(pdks_rapor_para_formatla($tl)) . ' ' . h($cur) . '<br>'; ?></td>
    <td><?php if (empty($g['odeme'])) echo '—'; else foreach ($g['odeme'] as $cur => $tl) echo h(pdks_rapor_para_formatla($tl)) . ' ' . h($cur) . '<br>'; ?></td>
    <td><?php if (empty($g['net'])) echo '—'; else foreach ($g['net'] as $cur => $tl) echo h(pdks_rapor_para_formatla($tl)) . ' ' . h($cur) . '<br>'; ?></td>
    <?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- ═══ ÇAVUŞ BAZLI ÖZET (görev madde 6) ═══ -->
<div class="page-head" style="margin-top:24px"><h2 style="margin:0;font-size:1.05rem">👷 Çavuş Bazlı Özet</h2></div>
<?php if (empty($cavusOzeti)): ?>
<div class="pdks-empty"><span class="pdks-empty-icon" aria-hidden="true">👷</span><p>Bu tarih/filtrelerde çavuş hareketi yok.</p></div>
<?php else: ?>
<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr>
    <th>Çavuş</th><th>Çalışılan Gün</th><th>Toplam İşçi</th><th>Eksik Çıkış</th>
    <?php if ($finansalGosterilebilir): ?><th>Kesinleşmiş Hakediş</th><th>Dönem Ödeme</th><th>Güncel Bakiye</th><?php endif; ?>
    <th class="actions-col">İşlem</th>
</tr></thead>
<tbody>
<?php foreach ($cavusOzeti as $c): $f = $c['foreman']; ?>
<tr>
    <td class="pdks-row-name"><?= h($f['name']) ?></td>
    <td><?= (int)$c['calisilan_gun'] ?></td>
    <td><?= (int)$c['toplam_isci'] ?></td>
    <td><?= $c['eksik_cikis'] > 0 ? '<span style="color:var(--warn);font-weight:700">' . (int)$c['eksik_cikis'] . '</span>' : '0' ?></td>
    <?php if ($finansalGosterilebilir): ?>
    <td><?php if (empty($c['donem_hakedis'])) echo '—'; else foreach ($c['donem_hakedis'] as $cur => $tl) echo h(pdks_rapor_para_formatla($tl)) . ' ' . h($cur) . '<br>'; ?></td>
    <td><?php if (empty($c['donem_odeme'])) echo '—'; else foreach ($c['donem_odeme'] as $cur => $tl) echo h(pdks_rapor_para_formatla($tl)) . ' ' . h($cur) . '<br>'; ?></td>
    <td><?php if (empty($c['guncel_bakiye'])) echo '—'; else foreach ($c['guncel_bakiye'] as $cur => $b) echo h(pdks_rapor_para_formatla($b['bakiye'])) . ' ' . h($cur) . '<br>'; ?></td>
    <?php endif; ?>
    <td class="actions-col">
        <a href="gunluk_isci_puantaj.php?cavus=<?= (int)$f['id'] ?>" class="btn btn-sm">Puantaj</a>
        <?php if ($finansalGosterilebilir): ?>
        <a href="cavus_ekstre.php?foreman_id=<?= (int)$f['id'] ?>" class="btn btn-sm">Ekstre</a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<div class="pdks-cards mobile-only">
<?php foreach ($cavusOzeti as $c): $f = $c['foreman']; ?>
<div class="pdks-card-item">
    <div class="pdks-card-top">
        <div class="pdks-card-meta">
            <div class="pdks-row-name"><?= h($f['name']) ?></div>
            <div class="pdks-row-sub"><?= (int)$c['calisilan_gun'] ?> gün · <?= (int)$c['toplam_isci'] ?> işçi</div>
        </div>
    </div>
    <?php if ($finansalGosterilebilir): foreach ($c['guncel_bakiye'] as $cur => $b): ?>
    <div class="pdks-kiosk-counter-row"><span>Güncel Bakiye (<?= h($cur) ?>)</span><span class="n"><?= h(pdks_rapor_para_formatla($b['bakiye'])) ?></span></div>
    <?php endforeach; endif; ?>
    <div class="pdks-kiosk-counter-row"><span>Eksik Çıkış</span><span class="n"<?= $c['eksik_cikis'] > 0 ? ' style="color:var(--warn);font-weight:700"' : '' ?>><?= (int)$c['eksik_cikis'] ?></span></div>
    <div style="display:flex;gap:6px;margin-top:8px">
        <a href="gunluk_isci_puantaj.php?cavus=<?= (int)$f['id'] ?>" class="btn btn-sm">Puantaj</a>
        <?php if ($finansalGosterilebilir): ?>
        <a href="cavus_ekstre.php?foreman_id=<?= (int)$f['id'] ?>" class="btn btn-sm">Ekstre</a>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ═══ ÇAVUŞ CARİ DURUMU (görev madde 9) ═══ -->
<?php if ($finansalGosterilebilir): ?>
<div class="page-head" style="margin-top:24px"><h2 style="margin:0;font-size:1.05rem">📒 Çavuş Cari Durumu (Güncel, tüm zamanlar)</h2></div>
<?php if (empty($bakiyeSiralama)): ?>
<div class="pdks-empty"><span class="pdks-empty-icon" aria-hidden="true">📒</span><p>Kayıtlı hesap hareketi yok.</p></div>
<?php else: foreach ($bakiyeSiralama as $cur => $liste): ?>
<h3 style="font-size:.95rem;margin:14px 0 6px"><?= h($cur) ?></h3>
<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr><th>Çavuş</th><th>Hakediş</th><th>Ödeme</th><th>Bakiye</th><th>Durum</th><th class="actions-col">İşlem</th></tr></thead>
<tbody>
<?php foreach ($liste as $b): $f = $b['foreman']; ?>
<tr>
    <td class="pdks-row-name"><?= h($f['name']) ?></td>
    <td><?= h(pdks_rapor_para_formatla($b['hakedis'])) ?></td>
    <td><?= h(pdks_rapor_para_formatla($b['odeme'])) ?></td>
    <td><strong><?= h(pdks_rapor_para_formatla($b['bakiye'])) ?></strong></td>
    <td><span class="pdks-badge <?= $b['durum'] === 'borc' ? 'pdks-badge-eksik_cikis' : ($b['durum'] === 'avans' ? 'pdks-badge-acik' : 'pdks-badge-aktif') ?>"><?= h($b['durum_etiket']) ?></span></td>
    <td class="actions-col"><a href="cavus_ekstre.php?foreman_id=<?= (int)$f['id'] ?>" class="btn btn-sm">Ekstre</a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<div class="pdks-cards mobile-only">
<?php foreach ($liste as $b): $f = $b['foreman']; ?>
<a href="cavus_ekstre.php?foreman_id=<?= (int)$f['id'] ?>" class="pdks-card-item" style="text-decoration:none;color:inherit">
    <div class="pdks-card-top">
        <div class="pdks-card-meta"><div class="pdks-row-name"><?= h($f['name']) ?></div></div>
        <span class="pdks-badge <?= $b['durum'] === 'borc' ? 'pdks-badge-eksik_cikis' : ($b['durum'] === 'avans' ? 'pdks-badge-acik' : 'pdks-badge-aktif') ?>"><?= h($b['durum_etiket']) ?></span>
    </div>
    <div class="pdks-kiosk-counter-row"><span>Bakiye</span><span class="n"><strong><?= h(pdks_rapor_para_formatla($b['bakiye'])) ?></strong></span></div>
</a>
<?php endforeach; ?>
</div>
<?php endforeach; endif; ?>
<?php endif; ?>

<!-- ═══ İSTİSNALAR (görev madde 8) ═══ -->
<div class="page-head" style="margin-top:24px"><h2 style="margin:0;font-size:1.05rem">⚠️ Eksik Çıkışlar</h2></div>
<?php if (empty($eksikler)): ?>
<div class="pdks-empty"><span class="pdks-empty-icon" aria-hidden="true">✅</span><p>Bu tarih/filtrelerde eksik çıkış yok.</p></div>
<?php else: ?>
<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr><th>Tarih</th><th>Çavuş</th><th>Depo</th><th>Kart No</th><th>Tip</th><th>Giriş Saati</th><th>Durum</th></tr></thead>
<tbody>
<?php foreach ($eksikler as $e): ?>
<tr>
    <td class="muted"><?= h(date('d.m.Y', strtotime($e['tarih']))) ?></td>
    <td class="pdks-row-name"><?= h($e['cavus_adi']) ?></td>
    <td class="muted"><?= h($e['depo'] ?: '—') ?></td>
    <td class="pdks-uid"><?= h($e['card_no']) ?></td>
    <td><?= h($e['tip']) ?></td>
    <td class="muted"><?= h(date('H:i', strtotime($e['giris_saat']))) ?></td>
    <td><span class="pdks-badge pdks-badge-pasif">Kapalı</span><?php if ($e['oturum_kapali_mesaji']): ?><div class="pdks-row-sub" style="color:var(--warn)"><?= h($e['oturum_kapali_mesaji']) ?></div><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<div class="pdks-cards mobile-only">
<?php foreach ($eksikler as $e): ?>
<div class="pdks-card-item">
    <div class="pdks-card-top">
        <div class="pdks-card-meta">
            <div class="pdks-row-name"><?= h($e['card_no']) ?> · <?= h($e['tip']) ?></div>
            <div class="pdks-row-sub"><?= h($e['cavus_adi']) ?> · <?= h(date('d.m.Y H:i', strtotime($e['giris_saat']))) ?></div>
        </div>
        <span class="pdks-badge pdks-badge-pasif">Kapalı</span>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="page-head" style="margin-top:24px"><h2 style="margin:0;font-size:1.05rem">🚪 Açık Mesailer (hâlâ içeride)</h2></div>
<?php if (empty($acikMesai)): ?>
<div class="pdks-empty"><span class="pdks-empty-icon" aria-hidden="true">✅</span><p>Bu tarih/filtrelerde açık mesai/içeride kart yok.</p></div>
<?php else: ?>
<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr><th>Tarih</th><th>Çavuş</th><th>Depo</th><th>Kart No</th><th>Tip</th><th>Giriş Saati</th><th>Durum</th></tr></thead>
<tbody>
<?php foreach ($acikMesai as $e): ?>
<tr>
    <td class="muted"><?= h(date('d.m.Y', strtotime($e['tarih']))) ?></td>
    <td class="pdks-row-name"><?= h($e['cavus_adi']) ?></td>
    <td class="muted"><?= h($e['depo'] ?: '—') ?></td>
    <td class="pdks-uid"><?= h($e['card_no']) ?></td>
    <td><?= h($e['tip']) ?></td>
    <td class="muted"><?= h(date('H:i', strtotime($e['giris_saat']))) ?></td>
    <td><span class="pdks-badge pdks-badge-acik">İçeride</span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<div class="pdks-cards mobile-only">
<?php foreach ($acikMesai as $e): ?>
<div class="pdks-card-item">
    <div class="pdks-card-top">
        <div class="pdks-card-meta">
            <div class="pdks-row-name"><?= h($e['card_no']) ?> · <?= h($e['tip']) ?></div>
            <div class="pdks-row-sub"><?= h($e['cavus_adi']) ?> · <?= h(date('d.m.Y H:i', strtotime($e['giris_saat']))) ?></div>
        </div>
        <span class="pdks-badge pdks-badge-acik">İçeride</span>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php render_footer(); ?>
