<?php
// =============================================================================
// HAL KAYIT — GÖNDERİM RAPORU (v494)
// Gönderilenler'i firma / tarih / ülke / ürün / plaka / türe göre süzer, seçilen
// alana göre gruplar (gönderim · künye · toplam KG · rüsum), yazdırır ve XLSX verir.
// SALT OKUNUR: tabloya yazmaz, HKS'e bağlanmaz, CSRF gerektiren POST yoktur.
// Mantık halkayit/gonderim_rapor_lib.php (kilo/adet + ad kuralları Gönderilenler
// listesiyle AYNI: tekrar_gonder_lib.php). CSV YOK (yalnız XLSX).
// =============================================================================
declare(strict_types=1);

require_once __DIR__ . '/config.php';        // ../config/db.php → helpers.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/tekrar_gonder_lib.php';
require_once __DIR__ . '/gonderim_rapor_lib.php';
require_once __DIR__ . '/../config/xlsx_export.php';

$auth_user = require_login();
require_perm('hks.access');   // Hal Kayıt paneliyle (index.php) aynı kapı
icerik_kapi('hks.gonderim_raporu');   // v507

header('Cache-Control: no-store, private');

$gr_f = hks_grapor_filtre($_GET);
$gr_hata = '';
$gr_firmalar = [];
$gr_rapor = ['satirlar' => [], 'gruplar' => [], 'toplam' => hks_grapor_toplam([]),
             'secenekler' => hks_grapor_secenekler([]), 'kesildi' => false];
try {
    try { hks_tablolari_hazirla(); } catch (Throwable $e) { /* tablo kurulamadıysa okuma hatası aşağıda yakalanır */ }
    $gr_db = hks_db();
    $gr_firmalar = hks_grapor_firmalar($gr_db, hks_tablo('firmalar'));
    $gr_firma_ad = $gr_firmalar[$gr_f['firma']] ?? '';
    $gr_rapor = hks_grapor_rapor($gr_db, hks_tablo('gonderilenler'), $gr_f, $gr_firma_ad);
} catch (Throwable $e) {
    error_log('[hks gonderim_rapor] ' . get_class($e));
    $gr_hata = 'Gönderim kayıtları okunamadı.';
}
$gr_firma_ad = $gr_firmalar[$gr_f['firma']] ?? '';

$gr_qs = array_filter([
    'firma' => $gr_f['firma'], 'bas' => $gr_f['bas'], 'bit' => $gr_f['bit'], 'ulke' => $gr_f['ulke'],
    'urun' => $gr_f['urun'], 'plaka' => $gr_f['plaka'], 'tur' => $gr_f['tur'], 'grup' => $gr_f['grup'],
], fn($v) => $v !== '');

// ── XLSX kolu (render_header'dan ÖNCE) ──
if (($_GET['xlsx'] ?? '') === '1') {
    require_perm('reports.export');
    if ($gr_hata !== '') { http_response_code(500); exit($gr_hata); }
    export_audit('hks', 'gonderim_rapor', 'xlsx', count($gr_rapor['satirlar']), $gr_qs);
    xlsx_indir('Gonderim-Raporu-' . $gr_f['bas'] . '_' . $gr_f['bit'] . '.xlsx',
        hks_grapor_xlsx_sayfalar($gr_rapor, $gr_f, $gr_firma_ad), '', ['baslik' => 'Gönderim Raporu']);
}

$gr_ozet = hks_grapor_ozet_metin($gr_f, $gr_firma_ad);
$gr_sec = $gr_rapor['secenekler'];
$gr_t = $gr_rapor['toplam'];
$gr_grup_etiket = hks_grapor_gruplar()[$gr_f['grup']];
// Seçili değer listede yoksa da seçenek olarak kalsın (ör. başka tarih aralığından gelen bağlantı)
foreach (['ulke', 'urun'] as $__k) {
    if ($gr_f[$__k] !== '') {
        $__var = false;
        foreach ($gr_sec[$__k] as $__v) if (hks_grapor_anahtar($__v) === hks_grapor_anahtar($gr_f[$__k])) { $__var = true; break; }
        if (!$__var) $gr_sec[$__k][] = $gr_f[$__k];
    }
}
$gr_secili = fn(string $a, string $b): string => hks_grapor_anahtar($a) === hks_grapor_anahtar($b) ? ' selected' : '';
$gr_durum_etiket = ['tam' => 'Başarılı', 'kismi' => 'Kısmi', 'basarisiz' => 'Hatalı'];

render_header('Gönderim Raporu');
?>
<style>
@page { size: A4 landscape; margin: 10mm; }
.gr-sayfa { max-width: 1400px; }
.gr-bas { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 14px; margin: 4px 0 14px; }
.gr-bas h1 { margin: 0; font-size: 22px; flex: 1 1 auto; min-width: 0; }
.gr-eylem { display: flex; flex-wrap: wrap; gap: 8px; }
.gr-ozet-metin { color: var(--muted); font-size: 13px; margin: -6px 0 12px; overflow-wrap: anywhere; }
.gr-filtre { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px; margin-bottom: 14px; }
.gr-filtre-izgara { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 10px 12px; }
.gr-filtre label { display: flex; flex-direction: column; gap: 4px; font-size: 12.5px; font-weight: 600; color: var(--muted); min-width: 0; }
.gr-filtre select, .gr-filtre input { width: 100%; min-width: 0; box-sizing: border-box; }
.gr-filtre-alt { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.gr-not { background: var(--warn-soft); color: var(--text); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 8px 10px; margin-bottom: 12px; font-size: 13px; }
.gr-hata { background: var(--danger-soft); }
.gr-kartlar { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 16px; }
.gr-kart { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 14px; box-shadow: var(--shadow); min-width: 0; }
.gr-kart-e { font-size: 12px; color: var(--muted); font-weight: 600; }
.gr-kart-d { font-size: 22px; font-weight: 800; color: var(--text); margin-top: 2px; overflow-wrap: anywhere; }
.gr-kart-alt { font-size: 11.5px; color: var(--muted); margin-top: 2px; }
.gr-bolum { margin: 0 0 8px; font-size: 16px; }
.gr-tablo { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.gr-tablo th, .gr-tablo td { padding: 7px 9px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
.gr-tablo th { background: var(--surface-2); font-size: 12.5px; color: var(--muted); white-space: nowrap; }
.gr-tablo .gr-nw { white-space: nowrap; }
.gr-tablo .gr-s { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.gr-tablo tr.gr-toplam td { font-weight: 800; background: var(--primary-soft); border-top: 2px solid var(--border-strong); }
.gr-tablo tr.gr-hatali td { color: var(--muted); background: var(--danger-soft); }
.gr-rozet { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 700; background: var(--surface-2); border: 1px solid var(--border); white-space: nowrap; }
.gr-rozet-basarisiz { background: var(--danger-soft); color: var(--danger); }
.gr-rozet-kismi { background: var(--warn-soft); color: var(--warn); }
.gr-bos { color: var(--muted); padding: 14px 0; }
.gr-ayrinti { margin-top: 18px; }
@media (max-width: 767px) {
    .gr-kartlar { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .gr-kart-d { font-size: 18px; }
    .gr-filtre-izgara { grid-template-columns: 1fr 1fr; }
    .gr-filtre-izgara .gr-genis { grid-column: 1 / -1; }
    .gr-bas h1 { font-size: 19px; }
}
@media (max-width: 389px) { .gr-filtre-izgara { grid-template-columns: 1fr; } }
@media print {
    .desktop-sidebar, .topbar, .topnav, .bottomnav, .gr-filtre, .gr-eylem, .gr-ekran { display: none !important; }
    .container { margin: 0 !important; padding: 0 !important; max-width: none !important; }
    body { background: #fff !important; color: #000 !important; }
    .gr-kart, .gr-filtre { box-shadow: none; }
    .gr-kart, .gr-tablo th, .gr-tablo td { border-color: #999 !important; color: #000 !important; background: #fff !important; }
    .gr-tablo tr.gr-toplam td { background: #eee !important; }
    .gr-tablo { font-size: 9.5pt; }
    .gr-ozet-metin { color: #000; margin: 0 0 8px; }
    .table-wrap { overflow: visible !important; border: 0 !important; }
    .gr-ayrinti { break-before: page; }
}
</style>

<div class="gr-sayfa">
<div class="gr-bas">
    <a class="btn btn-geri gr-ekran" href="index.php">← Hal Kayıt</a>
    <h1>📊 Gönderim Raporu</h1>
    <div class="gr-eylem">
        <button type="button" class="btn" data-gr-yazdir>🖨 Yazdır</button>
        <?php if (can('reports.export')): ?>
        <a class="btn" href="gonderim_rapor.php?<?= h(http_build_query($gr_qs + ['xlsx' => '1'])) ?>">📊 XLSX İndir</a>
        <?php endif; ?>
    </div>
</div>
<p class="gr-ozet-metin"><?= h($gr_ozet) ?></p>

<form class="gr-filtre" method="get" action="gonderim_rapor.php">
    <div class="gr-filtre-izgara">
        <label class="gr-genis">Firma
            <select name="firma">
                <option value="">Tüm firmalar</option>
                <?php foreach ($gr_firmalar as $__id => $__ad): ?>
                <option value="<?= h($__id) ?>"<?= (string)$__id === $gr_f['firma'] ? ' selected' : '' ?>><?= h($__ad) ?></option>
                <?php endforeach; ?>
                <?php if ($gr_f['firma'] !== '' && !isset($gr_firmalar[$gr_f['firma']])): ?>
                <option value="<?= h($gr_f['firma']) ?>" selected><?= h($gr_f['firma']) ?></option>
                <?php endif; ?>
            </select>
        </label>
        <label>Başlangıç <input type="date" name="bas" value="<?= h($gr_f['bas']) ?>"></label>
        <label>Bitiş <input type="date" name="bit" value="<?= h($gr_f['bit']) ?>"></label>
        <label>Ülke / Karşı taraf
            <select name="ulke">
                <option value="">Tümü</option>
                <?php foreach ($gr_sec['ulke'] as $__v): ?>
                <option value="<?= h($__v) ?>"<?= $gr_secili($__v, $gr_f['ulke']) ?>><?= h($__v) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Ürün
            <select name="urun">
                <option value="">Tümü</option>
                <?php foreach ($gr_sec['urun'] as $__v): ?>
                <option value="<?= h($__v) ?>"<?= $gr_secili($__v, $gr_f['urun']) ?>><?= h($__v) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Plaka
            <input type="text" name="plaka" value="<?= h($gr_f['plaka']) ?>" list="grPlakalar" autocomplete="off" maxlength="30">
            <datalist id="grPlakalar">
                <?php foreach ($gr_sec['plaka'] as $__v): ?><option value="<?= h($__v) ?>"></option><?php endforeach; ?>
            </datalist>
        </label>
        <label>Tür
            <select name="tur">
                <option value="">Tümü</option>
                <?php foreach (hks_grapor_turler() as $__k => $__e): ?>
                <?php if (isset($gr_sec['tur'][$__k]) || $__k === $gr_f['tur']): ?>
                <option value="<?= h($__k) ?>"<?= $__k === $gr_f['tur'] ? ' selected' : '' ?>><?= h($__e) ?></option>
                <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Grupla
            <select name="grup">
                <?php foreach (hks_grapor_gruplar() as $__k => $__e): ?>
                <option value="<?= h($__k) ?>"<?= $__k === $gr_f['grup'] ? ' selected' : '' ?>><?= h($__e) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <div class="gr-filtre-alt">
        <button type="submit" class="btn btn-primary">Uygula</button>
        <a class="btn" href="gonderim_rapor.php">Temizle</a>
    </div>
</form>

<?php foreach ($gr_f['notlar'] as $__n): ?><div class="gr-not"><?= h($__n) ?></div><?php endforeach; ?>
<?php if ($gr_hata !== ''): ?><div class="gr-not gr-hata"><?= h($gr_hata) ?></div><?php endif; ?>
<?php if ($gr_rapor['kesildi']): ?>
<div class="gr-not">Bu aralıkta <?= (int)HKS_GRAPOR_LIMIT ?>'den çok gönderim var; yalnız en yeni <?= (int)HKS_GRAPOR_LIMIT ?> gönderim rapora alındı. Tarih aralığını ya da firmayı daraltın.</div>
<?php endif; ?>

<div class="gr-kartlar">
    <div class="gr-kart"><div class="gr-kart-e">Gönderim</div><div class="gr-kart-d"><?= h(hks_grapor_kg($gr_t['gonderim'])) ?></div>
        <?php if ($gr_t['hatali'] > 0): ?><div class="gr-kart-alt">+<?= (int)$gr_t['hatali'] ?> hatalı (toplama girmez)</div><?php endif; ?></div>
    <div class="gr-kart"><div class="gr-kart-e">Künye</div><div class="gr-kart-d"><?= h(hks_grapor_kg($gr_t['kunye'])) ?></div></div>
    <div class="gr-kart"><div class="gr-kart-e">Toplam KG</div><div class="gr-kart-d"><?= h(hks_grapor_kg($gr_t['kg'])) ?></div></div>
    <div class="gr-kart"><div class="gr-kart-e">Rüsum (TL)</div><div class="gr-kart-d"><?= h(hks_grapor_tl($gr_t['rusum'])) ?></div></div>
</div>

<h2 class="gr-bolum">Özet — <?= h($gr_grup_etiket) ?></h2>
<?php if (!$gr_rapor['gruplar']): ?>
<p class="gr-bos">Bu filtreyle gönderim bulunamadı.</p>
<?php else: ?>
<div class="table-wrap">
<table class="gr-tablo" id="grOzet">
    <thead><tr><th><?= h($gr_grup_etiket) ?></th><th class="gr-s">Gönderim</th><th class="gr-s">Künye</th><th class="gr-s">Toplam KG</th><th class="gr-s">Rüsum (TL)</th></tr></thead>
    <tbody>
    <?php foreach ($gr_rapor['gruplar'] as $__g): ?>
    <tr>
        <td><?= h($__g['ad']) ?><?php if ($__g['hatali'] > 0): ?> <span class="gr-rozet gr-rozet-basarisiz"><?= (int)$__g['hatali'] ?> hatalı</span><?php endif; ?></td>
        <td class="gr-s"><?= h(hks_grapor_kg($__g['gonderim'])) ?></td>
        <td class="gr-s"><?= h(hks_grapor_kg($__g['kunye'])) ?></td>
        <td class="gr-s"><?= h(hks_grapor_kg($__g['kg'])) ?></td>
        <td class="gr-s"><?= h(hks_grapor_tl($__g['rusum'])) ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="gr-toplam">
        <td>TOPLAM</td>
        <td class="gr-s"><?= h(hks_grapor_kg($gr_t['gonderim'])) ?></td>
        <td class="gr-s"><?= h(hks_grapor_kg($gr_t['kunye'])) ?></td>
        <td class="gr-s"><?= h(hks_grapor_kg($gr_t['kg'])) ?></td>
        <td class="gr-s"><?= h(hks_grapor_tl($gr_t['rusum'])) ?></td>
    </tr>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($gr_rapor['satirlar']): ?>
<div class="gr-ayrinti">
<h2 class="gr-bolum">Ayrıntı (<?= h(hks_grapor_kg(count($gr_rapor['satirlar']))) ?> gönderim)</h2>
<div class="table-wrap">
<table class="gr-tablo" id="grAyrinti">
    <thead><tr><th>Tarih</th><th>Firma</th><th>Plaka</th><th>Tür</th><th>Ülke / Karşı taraf</th><th>Ürün</th><th class="gr-s">Künye</th><th class="gr-s">KG</th><th class="gr-s">Rüsum</th></tr></thead>
    <tbody>
    <?php foreach ($gr_rapor['satirlar'] as $__s):
        $__z = strtotime($__s['zaman']); ?>
    <tr<?= $__s['durum'] === 'basarisiz' ? ' class="gr-hatali"' : '' ?>>
        <td class="gr-nw"><?= h($__z ? date('d.m.Y H:i', $__z) : $__s['zaman']) ?></td>
        <td><?= h($__s['firma']) ?></td>
        <td><?= h($__s['plaka'] !== '' ? $__s['plaka'] : ($__s['belge_no'] !== '' ? 'Belge ' . $__s['belge_no'] : '—')) ?></td>
        <td><?= h($__s['tur_etiket']) ?><?php if ($__s['durum'] !== 'tam'): ?> <span class="gr-rozet gr-rozet-<?= h($__s['durum']) ?>"><?= h($gr_durum_etiket[$__s['durum']]) ?></span><?php endif; ?></td>
        <td><?= h($__s['ad']) ?></td>
        <td><?= h($__s['urun']) ?></td>
        <td class="gr-s"><?= h(hks_grapor_kg($__s['adet'])) ?></td>
        <td class="gr-s"><?= h(hks_grapor_kg($__s['kg'])) ?></td>
        <td class="gr-s"><?= h(hks_grapor_tl($__s['rusum'])) ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="gr-toplam">
        <td colspan="6">TOPLAM (hatalı gönderimler hariç)</td>
        <td class="gr-s"><?= h(hks_grapor_kg($gr_t['kunye'])) ?></td>
        <td class="gr-s"><?= h(hks_grapor_kg($gr_t['kg'])) ?></td>
        <td class="gr-s"><?= h(hks_grapor_tl($gr_t['rusum'])) ?></td>
    </tr>
    </tbody>
</table>
</div>
</div>
<?php endif; ?>
</div>

<script>
(function () {
    var b = document.querySelector('[data-gr-yazdir]');
    if (b) b.addEventListener('click', function () { window.print(); });
})();
</script>
<?php render_footer(); ?>
