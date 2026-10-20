<?php
// =========================================================
// siparis_cari.php — "Sipariş Cari Dökümü" (yazdırılabilir A4 yatay; GET; siparis.read + siparis_cari_yetkili(); v397)
// Ad TEK alt çizgili: sw.js bypass regex'i /siparis(ler|_[a-z]+)?\.php/ kapsar (ticari veri SW önbelleğine yazılmaz).
// Mantık config/siparis_cari.php'de; bu dosya FONKSİYON TANIMLAMAZ. Satır içi onclick YOK (siparis_ui_smoke).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/print_helpers.php';
require_once __DIR__ . '/_siparis_ortak.php';
require_once __DIR__ . '/config/siparis_cari.php';
$auth_user = require_login();
require_siparis('read');
if (!siparis_cari_yetkili()) forbidden('Bu sayfaya erişim yetkiniz yok. (Gerekli yetki: reports.export)');
siparis_no_store();
session_write_close();

$pdo = db();
$bugun = date('Y-m-d');
if (!siparis_sema_hazir($pdo)) {
    render_print_page_start('Sipariş Cari Dökümü', 'siparis', 'summary', 'landscape', ['print_pdks.css']);
    echo '<div class="print-sheet">' . siparis_kurulmadi_html() . '</div>';
    render_print_page_end();
    exit;
}
$f = siparis_cari_filtre($_GET, $bugun);
$v = siparis_cari_veri(siparis_cari_oku($pdo, $f), ['bugun' => $bugun, 'tahsilat' => siparis_cari_tahsilat_toplam($pdo, $f)]);
siparis_audit('siparis_cari_goruntule', null, null, ['yuklenmemis' => count($v['yuklenmemis']), 'yuklenen' => count($v['yuklenen']), 'fiyatli' => count($v['fiyatli']), 'kesildi' => $v['kesildi'] ? 1 : 0], (int)$auth_user['id']);
$depo = (string)(active_depot() ?? '');
$harita = siparis_yazdir_ulke_haritasi();
$tarih = static fn(?string $d): string => $d !== null ? sipui_tarih($d) : '—';
$bos = static fn(string $s): string => $s !== '' ? $s : '—';
$yildiz = static fn(bool $b): string => $b ? '<span class="sc-yildiz">*</span>' : '';
$pbHtml = static function (array $tutar): string {
    if ($tutar === []) return '<span class="sc-pb">—</span>';
    $o = ''; foreach ($tutar as $pb => $k) $o .= '<span class="sc-pb">' . h(siparis_cari_para((int)$k) . ' ' . $pb) . '</span>';
    return $o;
};
$oY = $v['ozet']['yuklenmemis']; $oL = $v['ozet']['yuklenen']; $oB = $v['ozet']['bekleyen'];
render_print_page_start('Sipariş Cari Dökümü', 'siparis', 'summary', 'landscape', ['print_pdks.css']);
?>
<style>
@page { size: A4 landscape; margin: 0; }
@media print { body { padding: 10mm; } }
body.print-siparis .print-sheet { max-width: 1240px; }
.sc-filtre { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; margin: 0; }
.sc-filtre label { display: flex; flex-direction: column; gap: 2px; font-size: .72rem; font-weight: 700; color: var(--pr-muted); }
.sc-filtre input, .sc-filtre select { font: inherit; font-size: .85rem; padding: 6px 8px; min-height: 36px; border: 1px solid #c3ccd8; border-radius: 6px; background: #fff; color: var(--pr-ink); }
.sc-kural { margin: 6px 0 2px; }
.sc-uyari { font-weight: 700; color: var(--pr-warn); }
.print-summary-box .sc-alt { display: block; margin-top: 3px; font-size: .74rem; color: var(--pr-muted); font-variant-numeric: tabular-nums; }
.print-summary-box .sc-pb { display: block; font-size: 1rem; font-weight: 800; line-height: 1.25; font-variant-numeric: tabular-nums; }
.sc-baslik-not { font-size: .7rem; font-weight: 600; text-transform: none; letter-spacing: 0; color: var(--pr-muted); }
table.print-table.sc-tablo .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
table.print-table.sc-tablo tbody tr td { background: #fff; }
table.print-table.sc-tablo tbody.sc-grup { break-inside: avoid; page-break-inside: avoid; }
table.print-table.sc-tablo tbody.sc-grup tr:first-child td { border-top: 2px solid var(--pr-line); }
table.print-table.sc-tablo tbody tr.sc-grup-bas td { background: var(--pr-head-bg); font-weight: 600; }
table.print-table.sc-tablo tbody tr.sc-ara td { font-weight: 700; border-top: 1px solid var(--pr-line); }
table.print-table.sc-tablo tbody.sc-toplam td { background: var(--pr-total-bg); font-weight: 800; border-top: 2px solid var(--pr-ink); }
table.print-table.sc-tablo .sc-esas { font-weight: 800; }
table.print-table.sc-tablo .sc-bos { color: var(--pr-muted); font-style: italic; }
.sc-yildiz { font-weight: 800; margin-left: 1px; }
.sc-cari { break-inside: avoid; page-break-inside: avoid; }
.sc-cari table.sc-tablo { max-width: 720px; }
@media screen and (max-width: 900px) { .sc-kaydir { overflow-x: auto; -webkit-overflow-scrolling: touch; } }
@media print {
  html, body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  table.print-table.sc-tablo { font-size: 7.6pt; }
  table.print-table.sc-tablo thead { display: table-header-group; }
  table.print-table.sc-tablo tbody.sc-grup, table.print-table.sc-tablo tbody.sc-toplam, .sc-cari { break-inside: avoid; page-break-inside: avoid; }
}
</style>
<div class="print-sheet">
  <div class="pr-actions no-print">
    <form method="get" action="siparis_cari.php" class="sc-filtre">
      <label>Yükleme başı <input type="date" name="bas" value="<?= h($f['bas']) ?>"></label>
      <label>Yükleme sonu <input type="date" name="bit" value="<?= h($f['bit']) ?>"></label>
      <label>Alıcı <select name="alici"><option value="">Tümü</option>
        <?php foreach ($harita as $kod => $u): ?><option value="<?= h((string)$kod) ?>"<?= (string)$kod === $f['alici'] ? ' selected' : '' ?>><?= h($u['ad'] . ' (' . $kod . ')') ?></option><?php endforeach; ?>
        <?php if ($f['alici'] !== '' && !isset($harita[$f['alici']])): ?><option value="<?= h($f['alici']) ?>" selected><?= h($f['alici']) ?></option><?php endif; ?>
      </select></label>
      <button type="submit" class="pr-btn">Uygula</button>
    </form>
    <button type="button" class="pr-btn pr-btn-primary" data-sc-yazdir>🖨 Yazdır</button>
    <a class="pr-btn" href="siparisler.php">← Siparişler</a>
  </div>
  <?= render_print_header_html('Sipariş Cari Dökümü', implode(' · ', $f['ozet']) . ($depo !== '' ? ' · Depo: ' . $depo : ''), 'Oluşturma: ' . date('d.m.Y H:i')) ?>
  <p class="pr-note sc-kural">Para birimleri ayrı gösterilir, birbiriyle toplanmaz. İşaret: Bakiye = müşterinin bize borcu (Faturalanan − Tahsilat).</p>
  <?php if ($v['kesildi']): ?><p class="pr-note sc-uyari">İlk <?= (int)SIPARIS_CARI_LIMIT ?> sevkiyat gösteriliyor (kesildi) — tarih aralığını daraltın.</p><?php endif; ?>
  <div class="print-summary-row">
    <div class="print-summary-box"><span class="psb-label">Yüklenmemiş sipariş</span><span class="psb-value"><?= (int)$oY['sevkiyat'] ?> sevkiyat</span><span class="sc-alt"><?= h(sipui_sayi($oY['palet'])) ?> palet · <?= h(sipui_sayi($oY['koli'])) ?> koli</span></div>
    <div class="print-summary-box psb-ok"><span class="psb-label">Yüklenen sevkiyat</span><span class="psb-value"><?= (int)$oL['sevkiyat'] ?></span><span class="sc-alt"><?= h(sipui_sayi($oL['koli'])) ?> koli · <?= h(siparis_cari_kg($oL['gram'])) ?> kg</span></div>
    <div class="print-summary-box"><span class="psb-label">Fiyatlı tutar</span><?= $pbHtml(array_map(static fn(array $c): int => $c['faturalanan'], $v['cari'])) ?></div>
    <div class="print-summary-box psb-warn"><span class="psb-label">Bakiye</span><?= $pbHtml(array_map(static fn(array $c): int => $c['bakiye'], $v['cari'])) ?></div>
  </div>

  <!-- A) Gelen — yüklenmemiş siparişler -->
  <h3 class="pr-section">A) Gelen — Yüklenmemiş Siparişler</h3>
  <div class="sc-kaydir"><table class="print-table sc-tablo">
    <thead><tr><th>Sipariş no</th><th>Planlanan yükleme</th><th>Alıcı ülke</th><th>Ürün / çeşit</th><th>Kalibre</th><th class="num">Palet</th><th class="num">Koli</th><th class="num">Kg</th></tr></thead>
    <?php if (!$v['yuklenmemis']): ?>
    <tbody><tr><td colspan="8" class="pr-empty">Yüklenmemiş sipariş yok.</td></tr></tbody>
    <?php else: ?>
    <?php foreach ($v['yuklenmemis'] as $s): ?>
    <tbody class="sc-grup">
      <?php if (!$s['satirlar']): ?>
      <tr><td><?= h($s['order_ref']) ?></td><td><?= h($tarih($s['tarih'])) ?></td><td><?= h($bos($s['ulke'])) ?></td><td colspan="5" class="sc-bos">Kalem yok</td></tr>
      <?php endif; ?>
      <?php foreach ($s['satirlar'] as $i => $l): ?>
      <tr>
        <?php if ($i === 0): ?><td><?= h($s['order_ref']) ?></td><td><?= h($tarih($s['tarih'])) ?></td><td><?= h($bos($s['ulke'])) ?></td><?php else: ?><td></td><td></td><td></td><?php endif; ?>
        <td><?= h($bos($l['urun'])) ?></td><td><?= h($bos($l['kalibre'])) ?></td>
        <td class="num"><?= $l['palet'] === null ? '—' : h(sipui_sayi($l['palet'])) ?></td>
        <td class="num"><?= h(sipui_sayi($l['koli'])) ?></td>
        <td class="num"><?= h(siparis_cari_kg($l['gram'])) ?><?= $yildiz($l['kg_varsayilan']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (count($s['satirlar']) > 1): ?>
      <tr class="sc-ara"><td colspan="5">Sevkiyat toplamı</td><td class="num"><?= h(sipui_sayi($s['toplam']['palet'])) ?></td><td class="num"><?= h(sipui_sayi($s['toplam']['koli'])) ?></td><td class="num"><?= h(siparis_cari_kg($s['toplam']['gram'])) ?></td></tr>
      <?php endif; ?>
    </tbody>
    <?php endforeach; ?>
    <tbody class="sc-toplam"><tr><td colspan="5">TOPLAM — <?= (int)$oY['sevkiyat'] ?> sevkiyat</td><td class="num"><?= h(sipui_sayi($oY['palet'])) ?></td><td class="num"><?= h(sipui_sayi($oY['koli'])) ?></td><td class="num"><?= h(siparis_cari_kg($oY['gram'])) ?></td></tr></tbody>
    <?php endif; ?>
  </table></div>

  <!-- B) Yüklenen sevkiyatlar -->
  <h3 class="pr-section">B) Yüklenen Sevkiyatlar</h3>
  <div class="sc-kaydir"><table class="print-table sc-tablo">
    <thead><tr><th>Yükleme tarihi</th><th>Sipariş no</th><th>Plaka</th><th>Nakliyeci</th><th>Kalibre</th><th class="num">Koli</th><th class="num">Kg</th><th class="num">Fiyat / kg</th></tr></thead>
    <?php if (!$v['yuklenen']): ?>
    <tbody><tr><td colspan="8" class="pr-empty">Yüklenen sevkiyat yok.</td></tr></tbody>
    <?php else: ?>
    <?php foreach ($v['yuklenen'] as $s): ?>
    <tbody class="sc-grup">
      <?php $durum = $s['fiyatli'] ? '' : 'Fiyat bekleniyor'; ?>
      <?php if (!$s['satirlar']): ?>
      <tr><td><?= h($tarih($s['tarih'])) ?></td><td><?= h($s['order_ref']) ?></td><td><?= h($bos($s['plaka'])) ?></td><td><?= h($bos($s['nakliyeci'])) ?></td><td colspan="3" class="sc-bos">Kalem yok</td><td><?= h($durum) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($s['satirlar'] as $i => $l): ?>
      <tr>
        <?php if ($i === 0): ?><td><?= h($tarih($s['tarih'])) ?></td><td><?= h($s['order_ref']) ?></td><td><?= h($bos($s['plaka'])) ?></td><td><?= h($bos($s['nakliyeci'])) ?></td><?php else: ?><td></td><td></td><td></td><td></td><?php endif; ?>
        <td><?= h($bos($l['kalibre'])) ?></td>
        <td class="num"><?= h(sipui_sayi($l['koli'])) ?></td>
        <td class="num"><?= h(siparis_cari_kg($l['gram'])) ?><?= $yildiz($l['kg_varsayilan']) ?></td>
        <td class="num"><?= $l['fiyatli'] ? h(siparis_cari_para(intdiv((int)$l['fiyat_kg'], 100)) . ' ' . $l['pb'] . ' / kg') : h($durum) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (count($s['satirlar']) > 1): ?>
      <tr class="sc-ara"><td colspan="5">Sevkiyat toplamı</td><td class="num"><?= h(sipui_sayi($s['toplam']['koli'])) ?></td><td class="num"><?= h(siparis_cari_kg($s['toplam']['gram'])) ?></td><td></td></tr>
      <?php endif; ?>
    </tbody>
    <?php endforeach; ?>
    <tbody class="sc-toplam"><tr><td colspan="5">TOPLAM — <?= (int)$oL['sevkiyat'] ?> sevkiyat · fiyat bekleyen: <?= (int)$oB['sevkiyat'] ?></td><td class="num"><?= h(sipui_sayi($oL['koli'])) ?></td><td class="num"><?= h(siparis_cari_kg($oL['gram'])) ?></td><td></td></tr></tbody>
    <?php endif; ?>
  </table></div>

  <!-- C) Fiyatlandırılmış sevkiyatlar -->
  <h3 class="pr-section">C) Fiyatlandırılmış Sevkiyatlar <span class="sc-baslik-not">(yüklenmemiş fiyatlı sevkiyatlar dahil — "Yüklenmedi" ile işaretli)</span></h3>
  <div class="sc-kaydir"><table class="print-table sc-tablo">
    <thead><tr><th>Kalibre</th><th>Ürün</th><th class="num">Koli</th><th class="num">Koli kg</th><th class="num">Kg</th><th class="num">Fiyat / koli</th><th class="num">Fiyat / kg</th><th class="num">Tutar</th></tr></thead>
    <?php if (!$v['fiyatli']): ?>
    <tbody><tr><td colspan="8" class="pr-empty">Fiyatlandırılmış sevkiyat yok.</td></tr></tbody>
    <?php else: ?>
    <?php foreach ($v['fiyatli'] as $s): ?>
    <tbody class="sc-grup">
      <tr class="sc-grup-bas"><td colspan="8"><strong><?= h($tarih($s['tarih'])) ?></strong> · Sipariş <?= h($s['order_ref']) ?> · Plaka <?= h($bos($s['plaka'])) ?> · Fiyat tarihi <?= h($tarih($s['fiyat_tarihi'])) ?><?= $s['yuklendi'] ? '' : ' · <span class="pr-tag">Yüklenmedi</span>' ?></td></tr>
      <?php foreach ($s['satirlar'] as $l): ?>
      <tr>
        <td><?= h($bos($l['kalibre'])) ?></td><td><?= h($bos($l['urun'])) ?></td>
        <td class="num"><?= h(sipui_sayi($l['koli'])) ?></td>
        <td class="num"><?= h(siparis_cari_kg_metin($l['koli_kg'])) ?><?= $yildiz($l['kg_varsayilan']) ?></td>
        <td class="num"><?= h(siparis_cari_kg($l['gram'])) ?></td>
        <?php if ($l['fiyatli']): ?>
        <td class="num<?= $l['baz'] === 'koli' ? ' sc-esas' : '' ?>"><?= h(siparis_cari_fiyat4((int)$l['fiyat_koli']) . ' ' . $l['pb'] . ' / koli (' . siparis_cari_kg_metin($l['koli_kg']) . ' kg)') ?></td>
        <td class="num<?= $l['baz'] === 'kg' ? ' sc-esas' : '' ?>"><?= h(siparis_cari_para(intdiv((int)$l['fiyat_kg'], 100)) . ' ' . $l['pb'] . ' / kg') ?></td>
        <td class="num"><?= h(siparis_cari_para((int)$l['tutar']) . ' ' . $l['pb']) ?></td>
        <?php else: ?>
        <td colspan="3" class="sc-bos">Fiyat yok — tutara dahil değil</td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
      <?php $ilk = true; foreach ($s['tutar'] as $pb => $kurus): ?>
      <tr class="sc-ara"><td colspan="2">Sevkiyat ara toplamı (<?= h((string)$pb) ?>)</td>
        <td class="num"><?= $ilk ? h(sipui_sayi($s['toplam']['koli'])) : '' ?></td><td></td>
        <td class="num"><?= $ilk ? h(siparis_cari_kg($s['toplam']['gram'])) : '' ?></td><td colspan="2"></td>
        <td class="num"><?= h(siparis_cari_para((int)$kurus) . ' ' . $pb) ?></td></tr>
      <?php $ilk = false; endforeach; ?>
    </tbody>
    <?php endforeach; ?>
    <?php endif; ?>
  </table></div>
  <p class="pr-note">Kalın fiyat belgedeki fiyattır; diğeri koli ağırlığından türetilmiştir. Satır tutarı kuruşa yuvarlanır; toplamlar yuvarlanmış satırların toplamıdır.</p>

  <!-- D) Cari özet -->
  <section class="sc-cari">
    <h3 class="pr-section">D) Cari Özet</h3>
    <div class="sc-kaydir"><table class="print-table sc-tablo">
      <thead><tr><th>Para birimi</th><th class="num">Faturalanan</th><th class="num">Tahsilat</th><th class="num">Bakiye</th></tr></thead>
      <tbody>
      <?php foreach ($v['cari'] as $pb => $c): ?>
        <tr><td><?= h((string)$pb) ?></td><td class="num"><?= h(siparis_cari_para($c['faturalanan'])) ?></td><td class="num"><?= h(siparis_cari_para($c['tahsilat'])) ?></td><td class="num"><strong><?= h(siparis_cari_para($c['bakiye'])) ?></strong></td></tr>
      <?php endforeach; ?>
      <?php if (!$v['cari']): ?><tr><td colspan="4" class="pr-empty">Fiyatlandırılmış sevkiyat yok.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
    <p class="pr-note">Fiyat bekleyen yüklenmiş: <?= (int)$oB['sevkiyat'] ?> sevkiyat · <?= h(sipui_sayi($oB['koli'])) ?> koli / <?= h(siparis_cari_kg($oB['gram'])) ?> kg (tutara dahil değil).</p>
    <?php if ($v['varsayilan_kg']): ?><p class="pr-note">* koli ağırlığı belgeden okunamadı, 3,5 kg varsayıldı.</p><?php endif; ?>
  </section>
</div>
<script>
(function () { var b = document.querySelector('[data-sc-yazdir]'); if (b) b.addEventListener('click', function () { window.print(); }); })();
</script>
<?php render_print_page_end(); ?>
