<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_cari.php';
require_once __DIR__ . '/config/pdks_rapor.php';
require_once __DIR__ . '/config/pdks_faz8b_cavus_b.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/xlsx_export.php';

$auth_user = require_login();
require_pdks_rapor();
if (isset($_GET['xlsx'])) { require_perm('reports.export'); }

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);

$ay = trim((string)($_GET['ay'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ay)) {
    $ay = date('Y-m');
}
$start = $ay . '-01';
$end = date('Y-m-t', strtotime($start));

$cavusId = filter_var(
    $_GET['cavus'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$cavusId = $cavusId === false ? null : (int)$cavusId;

$depo = function_exists('active_depot')
    ? (active_depot() ?? '')
    : '';

$cavuslar = $pdo->query(
    "SELECT id, code, name, is_active
       FROM foremen
      ORDER BY is_active DESC, name ASC"
)->fetchAll();

$faz8aHazir = pdks_gunluk_faz8a_sema_hazir($pdo);

$finansalGosterilebilir =
    pdks_rapor_can('financial')
    && function_exists('pdks_hakedis_sema_hazir')
    && pdks_hakedis_sema_hazir($pdo);

$satirlar = $faz8aHazir
    ? pdks_rapor_cavus_toplu_dokum(
        $ay,
        $depo !== '' ? $depo : null,
        $cavusId,
        $finansalGosterilebilir,
        $pdo
    )
    : [];

$bKapanislar = ($finansalGosterilebilir && pdks_faz8b_cavus_ucret_b_sema_hazir($pdo))
    ? pdks_faz8b_cavus_ucret_b_kapanis_listesi($cavusId, $start, $end, 'valid', $pdo)
    : [];

$toplamIsci = 0;
$toplamTip = [];       // v299: tip kayıt defterinden — sutun anahtarı => toplam (kadin/erkek/rampaci/karisik)
foreach (pdks_gunluk_tip_sutunlari() as $tc) $toplamTip[$tc['sutun']] = 0;
$toplamEksik = 0;
$toplamFm = 0;   // v407: toplam fazla mesai saati

foreach ($satirlar as $r) {
    $toplamIsci += (int)$r['toplam_isci'];
    foreach ($toplamTip as $sk => $_) $toplamTip[$sk] += (int)($r[$sk] ?? 0);
    $toplamEksik += (int)$r['eksik_cikis'];
    $toplamFm    += (int)($r['fm_saat'] ?? 0);
}

// Rampacı sütunu 0 olsa da görünür; Karışık yalnız atanmamış kayıt varsa.
$tipSut = array_values(array_filter(pdks_gunluk_tip_sutunlari(), static fn($c) => $c['sistem'] || (int)($toplamTip[$c['sutun']] ?? 0) > 0));

// v407 — renkli XLSX (yalnız XLSX kolu). Ekranla AYNI veri/yetki: Hakediş sütunları yalnız finansal yetkide.
// Gün bandı / gün başı çizgisi ekrandaki $gNo/$gYeni mantığıyla aynıdır. Kapı: reports.export.
if (isset($_GET['xlsx'])) {
    $tipRenk = [
        'kadin'   => ['ac' => 'FFFCE4EC', 'koyu' => 'FFF4B6C8'],
        'erkek'   => ['ac' => 'FFE3F0FD', 'koyu' => 'FFAFCDEE'],
        'rampaci' => ['ac' => 'FFFFEDD5', 'koyu' => 'FFF8C998'],
        'karisik' => ['ac' => 'FFEDE7F6', 'koyu' => 'FFCBBCE6'],
    ];
    $baslikKoyu = 'FF1F3A5F';
    $kol = function (string $baslik, string $tip = 'metin', bool $topla = false, ?string $dolgu = null, ?string $baslikDolgu = null) use ($baslikKoyu): array {
        $k = ['baslik' => $baslik, 'tip' => $tip, 'topla' => $topla,
              'baslik_dolgu' => $baslikDolgu ?? $baslikKoyu, 'baslik_yazi' => $baslikDolgu === null ? 'FFFFFFFF' : 'FF1F2937'];
        if ($dolgu !== null) $k['dolgu'] = $dolgu;
        return $k;
    };
    $sutunlar = [$kol('Tarih', 'tarih'), $kol('Çavuş'), $kol('Çavuş Kodu')];
    foreach ($tipSut as $tc) {
        $rn = $tipRenk[$tc['sutun']] ?? ['ac' => 'FFF3F4F6', 'koyu' => 'FFD1D5DB'];
        $sutunlar[] = $kol((string)$tc['kisa'], 'tamsayi', true, $rn['ac'], $rn['koyu']);
    }
    $sutunlar[] = $kol('Toplam İşçi', 'tamsayi', true);
    if ($finansalGosterilebilir) {
        $sutunlar[] = $kol('Hakediş', 'tutar');          // para birimleri/tutarlar ASLA toplanmaz
        $sutunlar[] = $kol('Para Birimi');
        $sutunlar[] = $kol('Durum');
    }
    array_push($sutunlar, $kol('İlk Giriş'), $kol('Son Çıkış'), $kol('Eksik Çıkış', 'tamsayi', true));
    $fmAc = 'FFE8F5E9'; $fmKoyu = 'FFB7DFB9';
    array_push($sutunlar,
        $kol('FM Toplam Saat', 'tamsayi', true, $fmAc, $fmKoyu),
        $kol('FM Kişi', 'tamsayi', true, $fmAc, $fmKoyu),
        $kol('FM Dağılımı', 'metin', false, 'FFFFF8E1', 'FFFFE082')
    );

    $xs = []; $bant = []; $ust = [];
    $gSonX = null; $gNoX = 1;
    foreach ($satirlar as $i => $r) {
        $yeni = ($gSonX !== $r['tarih']);
        if ($yeni) { $gNoX = 1 - $gNoX; $gSonX = $r['tarih']; if ($i > 0) $ust[] = $i; }
        $bant[$i] = $gNoX;
        $h = $r['hakedis'] ?? null;
        $sat = [$r['tarih'], (string)$r['cavus_adi'], (string)$r['cavus_kodu']];
        foreach ($tipSut as $tc) $sat[] = (int)($r[$tc['sutun']] ?? 0);
        $sat[] = (int)$r['toplam_isci'];
        if ($finansalGosterilebilir) {
            $sat[] = $h === null ? null : (float)$h['total_amount'];
            $sat[] = $h === null ? '' : (string)$h['currency'];
            $sat[] = $h === null ? 'Hesaplanmadı' : ($h['status'] === 'final' ? 'Kesin' : 'Taslak');
        }
        $sat[] = $r['ilk_giris'] ? date('H:i', strtotime($r['ilk_giris'])) : '';
        $sat[] = $r['son_cikis'] ? date('H:i', strtotime($r['son_cikis'])) : '';
        $sat[] = (int)$r['eksik_cikis'];
        $fmSaat = (int)($r['fm_saat'] ?? 0);
        $sat[] = $fmSaat;
        $sat[] = (int)($r['fm_kisi'] ?? 0);
        $sat[] = $fmSaat > 0 ? pdks_faz8b_fm_dagilim_metni((array)($r['fm_dagilim'] ?? [])) : '';
        $xs[] = $sat;
    }
    $sayfa = [
        'ad' => 'Toplu Döküm', 'baslik' => 'Çavuş Toplu Döküm — ' . date('m/Y', strtotime($start)),
        'aciklama' => ($depo !== '' ? $depo : 'Tüm depolar') . ' · ' . ($cavusId !== null
            ? (string)(current(array_filter($cavuslar, fn($c) => (int)$c['id'] === $cavusId))['name'] ?? 'Çavuş') : 'Tüm çavuşlar'),
        'bloklar' => [[
            'baslik' => null, 'sutunlar' => $sutunlar, 'satirlar' => $xs, 'toplam' => true,
            'satir_bant' => $bant, 'bant_dolgu' => 'FFE2ECFA', 'satir_ust_cizgi' => $ust, 'ust_cizgi_renk' => 'FF9DB4D3',
        ]],
    ];
    export_audit('pdks', 'cavus_toplu_dokum', 'xlsx', count($xs), ['ay' => $ay, 'cavus' => $cavusId, 'depo' => $depo]);
    xlsx_indir('cavus_toplu_dokum_' . $ay . '.xlsx', [$sayfa]);
}

render_header('Çavuş Toplu Döküm');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v='
    . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head">
    <div>
        <h1>👷 Çavuş Toplu Döküm</h1>
        <p class="muted" style="margin:4px 0 0">
            Aylık günlük işçi, giriş/çıkış ve hakediş özeti
        </p>
    </div>
    <div class="page-head-actions">
        <a href="<?= h('cavus_toplu_dokum_yazdir.php?' . http_build_query(array_filter(['ay' => $ay, 'cavus' => $cavusId], fn($v) => $v !== null && $v !== ''))) ?>" class="btn" target="_blank" rel="noopener">🖨️ Yazdır</a>
        <?php if (can('reports.export')): ?><a href="<?= h('cavus_toplu_dokum.php?' . http_build_query(array_filter(['ay' => $ay, 'cavus' => $cavusId, 'xlsx' => '1'], fn($v) => $v !== null && $v !== ''))) ?>" class="btn">📊 XLSX İndir</a><?php endif; ?>
        <a href="personel_takip.php" class="btn btn-geri btn-geri-ptak">← Personel Takibi</a>
    </div>
</div>

<form method="get" class="pdks-filter-bar" data-oto-filtre>
    <label>
        <span class="form-label">Ay</span>
        <input type="month" name="ay" value="<?= h($ay) ?>">
    </label>

    <label>
        <span class="form-label">Çavuş</span>
        <select name="cavus">
            <option value="">Tüm çavuşlar</option>
            <?php foreach ($cavuslar as $c): ?>
            <option
                value="<?= (int)$c['id'] ?>"
                <?= $cavusId === (int)$c['id'] ? 'selected' : '' ?>
            >
                <?= h($c['name']) ?><?= $c['is_active'] ? '' : ' (pasif)' ?>
            </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>
        <span class="form-label">Depo</span>
        <select disabled>
            <option><?= h($depo !== '' ? $depo : 'Tüm depolar') ?></option>
        </select>
    </label>

    <?= pdks_oto_filtre_noscript('Göster') ?>
</form>

<?php if (!$faz8aHazir): ?>
<div class="card" style="padding:18px;margin:16px 0">
    <strong>Çavuş Toplu Döküm henüz kullanılamıyor.</strong>
    <p class="muted" style="margin-bottom:0">
        Günlük işçi work-period şeması hazır değil.
    </p>
</div>
<?php else: ?>

<div class="pdks-kiosk-counters rapor-genis" style="margin-bottom:16px">
    <h3><?= h(date('m/Y', strtotime($ay . '-01'))) ?> Özeti</h3>
    <div class="pdks-kiosk-counter-totals">
        <?php foreach ($tipSut as $tc): /* v299: Kadın + Erkek + Rampacı (+ atanmamış Karışık varsa) = Toplam */ ?>
        <div class="pdks-kiosk-counter-box">
            <div class="lbl"><?= h($tc['sayac']) ?></div>
            <div class="val"><?= (int)$toplamTip[$tc['sutun']] ?></div>
        </div>
        <?php endforeach; ?>
        <div class="pdks-kiosk-counter-box">
            <div class="lbl">Toplam İşçi</div>
            <div class="val"><?= $toplamIsci ?></div>
        </div>
        <div class="pdks-kiosk-counter-box">
            <div class="lbl">Toplam FM (saat)</div>
            <div class="val"><?= $toplamFm ?></div>
        </div>
        <div class="pdks-kiosk-counter-box eksik">
            <div class="lbl">Eksik Çıkış</div>
            <div class="val"><?= $toplamEksik ?></div>
        </div>
    </div>
</div>

<?php if (!$satirlar): ?>
<div class="pdks-empty">
    <span class="pdks-empty-icon">📭</span>
    <p>Seçilen ay için kayıt bulunamadı.</p>
</div>
<?php else: ?>

<div class="table-wrap">
<table class="data-table" id="ctdTable">
    <thead>
    <tr>
        <th>Tarih</th>
        <th>Çavuş</th>
        <?php foreach ($tipSut as $tc): ?><th><?= h($tc['kisa']) ?></th><?php endforeach; ?>
        <th>Toplam İşçi</th>
        <th>Hakediş</th>
        <th>İlk Giriş</th>
        <th>Son Çıkış</th>
        <th>Eksik Çıkış</th>
        <th>Fazla Mesai</th>
    </tr>
    </thead>
    <tbody>
    <?php $gSon = null; $gNo = 1; /* v296-B: gün grubu — aynı tarih aynı ton, tarih değişince ton ve üst çizgi değişir */
    foreach ($satirlar as $r):
        $gYeni = ($gSon !== $r['tarih']);
        if ($gYeni) { $gNo = 1 - $gNo; $gSon = $r['tarih']; }
        $gSinif = 'ctd-g' . $gNo . ($gYeni ? ' ctd-gyeni' : '');
        $detayUrl = 'cavus_toplu_dokum_detay.php?' . http_build_query([
            'session_id' => (int)$r['session_id'],
            'ay' => $ay,
            'cavus' => $cavusId,
        ]);

        $hakedis = $r['hakedis'];
    ?>
    <tr
        class="pdks-satir-link <?= $gSinif ?>"
        tabindex="0"
        data-href="<?= h($detayUrl) ?>"
        title="Kart dökümünü aç"
    >
        <td><a href="<?= h($detayUrl) ?>" class="pdks-satir-ana"><strong><?= h(date('d.m.Y', strtotime($r['tarih']))) ?></strong></a></td>
        <td>
            <strong><?= h($r['cavus_adi']) ?></strong>
            <div class="muted" style="font-size:.78rem"><?= h($r['cavus_kodu']) ?></div>
        </td>
        <?php foreach ($tipSut as $tc): ?><td><?= (int)($r[$tc['sutun']] ?? 0) ?></td><?php endforeach; ?>
        <td><strong><?= (int)$r['toplam_isci'] ?></strong><?php if ((int)($r['karisik'] ?? 0) > 0): ?><div class="muted" style="font-size:.78rem"><?= (int)$r['karisik'] ?> Karışık</div><?php endif; ?></td>

        <td>
            <?php if (!$finansalGosterilebilir): ?>
                <span class="muted">—</span>
            <?php elseif ($hakedis === null): ?>
                <span class="muted">Hesaplanmadı</span>
            <?php else: ?>
                <strong>
                    <?= h(pdks_rapor_para_formatla($hakedis['total_amount'])) ?>
                    <?= h($hakedis['currency']) ?>
                </strong>
                <div class="muted" style="font-size:.78rem">
                    <?= $hakedis['status'] === 'final' ? 'Kesin' : 'Taslak' ?>
                </div>
            <?php endif; ?>
        </td>

        <td>
            <?= $r['ilk_giris']
                ? h(date('H:i', strtotime($r['ilk_giris'])))
                : '—' ?>
        </td>

        <td>
            <?= $r['son_cikis']
                ? h(date('H:i', strtotime($r['son_cikis'])))
                : '—' ?>
        </td>

        <td>
            <?php if ((int)$r['eksik_cikis'] > 0): ?>
                <span class="pdks-badge pdks-badge-eksik_cikis">
                    <?= (int)$r['eksik_cikis'] ?>
                </span>
            <?php else: ?>
                <span class="pdks-badge pdks-badge-tamamlandi">0</span>
            <?php endif; ?>
        </td>

        <td>
            <?php if ((int)($r['fm_saat'] ?? 0) > 0): ?>
                <strong><?= (int)$r['fm_saat'] ?> sa</strong>
                <div class="muted" style="font-size:.78rem"><?= h(pdks_faz8b_fm_dagilim_metni((array)($r['fm_dagilim'] ?? []))) ?></div>
            <?php else: ?>
                <span class="muted">—</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<p class="muted" style="font-size:.82rem">
    Satıra tıklayarak o günün kart dökümünü açabilirsiniz.
</p>


<?php endif; ?>

<?php if ($bKapanislar): ?>
<h2 style="font-size:1.05rem;margin-top:24px">🧮 Çavuş Hakedişi (Yöntem B) — <?= h(date('m/Y', strtotime($ay . '-01'))) ?> Kapanışları</h2>
<p class="muted" style="font-size:.82rem">Kapanış ödeme tarihine göre bu aya düşer; depo filtresi uygulanmaz (dönem birden çok gün/depoyu kapsar).</p>
<div class="table-wrap">
<table class="data-table">
<thead><tr><th>Kapanış Tarihi</th><th>Çavuş</th><th>Devreden</th><th>Dönem Kişi-Gün</th><th>Hakediş Adedi</th><th>Birim Ücret</th><th>Tutar</th><th>Yeni Devir</th></tr></thead>
<tbody>
<?php $bToplamlar = []; foreach ($bKapanislar as $k): $bToplamlar[$k['currency']] = ($bToplamlar[$k['currency']] ?? 0) + (float)$k['amount']; ?>
<tr>
    <td><?= h(date('d.m.Y', strtotime($k['closure_date']))) ?></td>
    <td><?= h($k['foreman_name']) ?></td>
    <td><?= (int)$k['carry_in'] ?></td>
    <td><?= (int)$k['period_person_days'] ?></td>
    <td><strong><?= (int)$k['earned_units'] ?></strong> <span class="muted" style="font-size:.78rem">(<?= (int)($k['unit_size'] ?? 25) ?> kişi-gün = 1)</span></td>
    <td><?= h(number_format((float)$k['unit_rate'], 2, ',', '.')) ?> <?= h($k['currency']) ?></td>
    <td><strong><?= h(number_format((float)$k['amount'], 2, ',', '.')) ?> <?= h($k['currency']) ?></strong></td>
    <td><?= (int)$k['carry_out'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p style="font-weight:700">
<?php foreach ($bToplamlar as $cur => $tp): ?>
Toplam: <?= h(number_format($tp, 2, ',', '.')) ?> <?= h($cur) ?><br>
<?php endforeach; ?>
</p>
<?php endif; ?>

<?php endif; ?>

<?php pdks_liste_ui_js(); ?>
<?php render_footer(); ?>