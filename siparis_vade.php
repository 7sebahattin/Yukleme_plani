<?php
// =========================================================
// siparis_vade.php — "OGL Haftalık Ödeme": bu hafta (ve sonraki haftalar) OGL'den beklenen ödeme (yazdırılabilir A4 yatay; GET; v569)
// Kapı: require_siparis('read') + siparis_cari_yetkili() + içerik `siparis.odeme` (Ödemeler sayfasıyla aynı). SALT OKUNUR: mutasyon/CSRF yok
// (yalnız audit SAYILARI). Ad TEK alt çizgili: sw.js bypass regex'i /siparis(ler|_[a-z]+)?\.php/ kapsar (ticari veri SW önbelleğine yazılmaz).
// Mantık config/siparis_vade.php'de (siparis_vade_hesapla, saf); bu dosya FONKSİYON TANIMLAMAZ, hesap yapmaz. Satır içi onclick YOK.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/print_helpers.php';
require_once __DIR__ . '/_siparis_ortak.php';
require_once __DIR__ . '/config/siparis_vade.php';
$auth_user = require_login();
require_siparis('read');
if (!siparis_cari_yetkili()) forbidden('Bu sayfaya erişim yetkiniz yok. (Gerekli yetki: reports.export)');
icerik_kapi('siparis.odeme');
siparis_no_store();
session_write_close();

$pdo = db();
$bugun = date('Y-m-d');
if (!siparis_sema_hazir($pdo)) {
    render_print_page_start('OGL Haftalık Ödeme', 'siparis', 'summary', 'landscape', ['print_pdks.css']);
    echo '<div class="print-sheet">' . siparis_kurulmadi_html() . '</div>';
    render_print_page_end();
    exit;
}
$param = siparis_vade_param($_GET);
$x = siparis_vade_oku($pdo, $param, $bugun);
$v = $x['veri'];
$o = $v['ozet'];
$p = $v['param'];
siparis_audit('siparis_vade_goruntule', null, null, ['acik' => (int)$o['acik_sevkiyat'], 'odenmis' => count($v['odenmis']), 'odeme' => (int)$o['odeme_adet'], 'vade_oto' => $p['vade_oto'] ? 1 : 0], (int)$auth_user['id']);

$depo = (string)(active_depot() ?? '');
$gunler = siparis_vade_gun_adlari();
$bazlar = siparis_vade_bazlar();
$tarih = static fn(?string $t): string => $t !== null && $t !== '' ? sipui_tarih($t) : '—';
$gunAd = static fn(string $t): string => $gunler[(int)(new DateTimeImmutable($t))->format('N')] ?? '';
$eur = static fn(int $k): string => siparis_cari_para($k) . ' EUR';
$eksi = static fn(int $k): string => $k > 0 ? '−' . siparis_cari_para($k) : '—';
$ham = static fn(string $k): string => is_string($_GET[$k] ?? null) ? trim((string)$_GET[$k]) : '';
$bh = $v['bu_hafta'];
$gec = $o['gecikme'];
$vadeMetin = $bazlar[$p['baz']] . ' + ' . (int)$p['vade'] . ' gün → ilk ' . $gunler[(int)$p['gun']];
render_print_page_start('OGL Haftalık Ödeme', 'siparis', 'summary', 'landscape', ['print_pdks.css']);
?>
<style>
@page { size: A4 landscape; margin: 0; }
@media print { body { padding: 10mm; } }
body.print-siparis .print-sheet { max-width: 1240px; }
.print-sheet > .pr-actions.no-print { display: block; padding: 0; margin: 0 0 14px; border: 0; }
.sc-filtrebar { background: #eef3fa; border: 1px solid #d5dfec; border-radius: 10px; padding: 8px 12px; display: flex; flex-direction: column; gap: 8px; }
.sc-fb-satir { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 8px 12px; }
.sc-fb-eylem { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 6px; margin-left: auto; }
.sc-filtre { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: flex-end; margin: 0; min-width: 0; }
.sc-filtre label { display: flex; flex-direction: column; gap: 2px; font-size: .6875rem; font-weight: 700; color: #46566b; line-height: 1.2; }
.sc-filtre input, .sc-filtre select { font: inherit; font-size: .8rem; padding: 3px 8px; height: 32px; box-sizing: border-box; border: 1px solid #b9c7da; border-radius: 6px; background: #fff; color: var(--pr-ink); }
.sc-filtre input[type="number"] { width: 130px; }
.sc-filtre select { max-width: 230px; }
.sc-filtre .pr-btn, .sc-fb-eylem .pr-btn { height: 32px; box-sizing: border-box; padding: 0 12px; font-size: .8rem; white-space: nowrap; }
@media screen and (max-width: 767px) {
  .sc-filtrebar { padding: 8px 10px; }
  .sc-filtre, .sc-filtre label { width: 100%; }
  .sc-filtre input, .sc-filtre select, .sc-filtre input[type="number"] { width: 100%; max-width: none; height: 38px; font-size: 16px; }
  .sc-filtre .pr-btn { width: 100%; height: 38px; justify-content: center; }
  .sc-fb-eylem { margin-left: 0; width: 100%; }
  .sc-fb-eylem .pr-btn { flex: 1 1 auto; height: 38px; justify-content: center; }
}
.sv-ana { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 24px; border: 2px solid #1b6e3c; border-left-width: 8px; border-radius: 10px; background: #effaf2; padding: 12px 16px; margin: 10px 0 12px; break-inside: avoid; }
.sv-ana .sv-etiket { display: block; font-size: .78rem; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; color: #1b6e3c; }
.sv-ana .sv-tutar { display: block; font-size: 2rem; font-weight: 800; line-height: 1.15; font-variant-numeric: tabular-nums; color: #0f3d22; }
.sv-ana .sv-gun { display: block; font-size: .95rem; font-weight: 700; color: #0f3d22; }
.sv-ana .sv-detay { font-size: .82rem; color: #2f4a3a; line-height: 1.5; }
.sv-ana .sv-detay strong { color: #0f3d22; }
.print-summary-box .sc-alt { display: block; margin-top: 3px; font-size: .74rem; color: var(--pr-muted); font-variant-numeric: tabular-nums; }
table.print-table.sc-tablo .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
table.print-table.sc-tablo tbody tr td { background: #fff; }
table.print-table.sc-tablo tbody tr.sv-bu td { background: #effaf2; font-weight: 700; }
table.print-table.sc-tablo tbody tr.sv-gecikmis td.sv-not { color: #b3261e; font-weight: 700; }
table.print-table.sc-tablo tbody.sc-toplam td { background: var(--pr-total-bg); font-weight: 800; border-top: 2px solid var(--pr-ink); }
table.print-table.sc-tablo .sv-eksi { color: #b3261e; }
table.print-table.sc-tablo td.sv-beklenen { font-weight: 800; }
table.print-table.sc-tablo td.sv-not { font-size: .92em; color: var(--pr-muted); }
.sv-rozet { display: inline-block; font-size: .7rem; font-weight: 800; padding: 1px 6px; border-radius: 9px; background: #1b6e3c; color: #fff; margin-left: 4px; vertical-align: 1px; }
.sd-hata { border: 2px solid var(--pr-warn); border-radius: 6px; padding: 6px 10px; margin: 8px 0; font-size: .82rem; color: var(--pr-warn); font-weight: 700; }
.sd-hata ul { margin: 2px 0 0 18px; padding: 0; }
.sd-notlar { margin: 6px 0 0 18px; padding: 0; font-size: .76rem; color: var(--pr-muted); }
.sd-uyari-liste { margin: 4px 0 0 18px; padding: 0; font-size: .8rem; }
.sd-uyari-liste li { margin: 2px 0; }
.sd-uyari-liste li.sd-u-uyari { font-weight: 700; color: var(--pr-warn); }
.sc-cari { break-inside: avoid; page-break-inside: avoid; }
details.sv-gecmis > summary { cursor: pointer; font-weight: 700; margin: 6px 0; }
@media screen { .sc-kaydir { overflow-x: auto; -webkit-overflow-scrolling: touch; } }
@media screen and (max-width: 767px) { .sv-ana .sv-tutar { font-size: 1.6rem; } }
@media print {
  html, body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  table.print-table.sc-tablo { font-size: 7.8pt; }
  table.print-table.sc-tablo thead { display: table-header-group; }
  table.print-table.sc-tablo tr, .sc-cari, .sd-hata, .sv-ana { break-inside: avoid; page-break-inside: avoid; }
  details.sv-gecmis > summary { list-style: none; }
}
</style>
<div class="print-sheet">
  <div class="pr-actions no-print">
   <div class="sc-filtrebar">
    <div class="sc-fb-satir">
      <form method="get" action="siparis_vade.php" class="sc-filtre" id="svParam">
        <label>Vade (gün) <input type="number" name="vade" min="0" max="<?= (int)SIPARIS_VADE_GUN_MAX ?>" step="1" value="<?= h($ham('vade')) ?>" placeholder="<?= (int)$p['vade'] ?> (otomatik)"></label>
        <label>Vade başlangıcı <select name="baz">
          <?php foreach ($bazlar as $k => $ad): ?><option value="<?= h($k) ?>"<?= $p['baz'] === $k ? ' selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?>
        </select></label>
        <label>OGL ödeme günü <select name="gun">
          <option value=""<?= $p['gun_oto'] ? ' selected' : '' ?>>Otomatik (<?= h($gunler[(int)$p['gun']]) ?>)</option>
          <?php foreach ($gunler as $n => $ad): ?><option value="<?= (int)$n ?>"<?= !$p['gun_oto'] && (int)$p['gun'] === $n ? ' selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?>
        </select></label>
        <button type="submit" class="pr-btn pr-btn-primary">Hesapla</button>
        <?php if ($ham('vade') !== '' || $ham('gun') !== '' || $ham('baz') !== ''): ?><a class="pr-btn" href="siparis_vade.php">Otomatiğe dön</a><?php endif; ?>
      </form>
      <div class="sc-fb-eylem">
        <button type="button" class="pr-btn" data-sc-yazdir>🖨 Yazdır</button>
        <a class="pr-btn" href="siparis_odemeler.php">💳 Ödemeler</a>
        <a class="pr-btn" href="siparis_cari.php">📒 Cari Döküm</a>
        <a class="pr-btn" href="siparisler.php">← Siparişler</a>
      </div>
    </div>
   </div>
  </div>
  <?= render_print_header_html('OGL Haftalık Ödeme', 'Bu hafta: ' . $tarih($bh['bas']) . ' – ' . $tarih($bh['son']) . ' · Vade: ' . $vadeMetin . ($depo !== '' ? ' · Depo: ' . $depo : ''), 'Oluşturma: ' . date('d.m.Y H:i')) ?>
  <?php if ($param['hata']): ?>
  <div class="sd-hata" role="alert">Parametre hatası — otomatik değer kullanıldı:<ul><?php foreach ($param['hata'] as $m): ?><li><?= h((string)$m) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <?php if ($x['cari']['kesildi']): ?><p class="pr-note sd-u-uyari">İlk <?= (int)SIPARIS_CARI_LIMIT ?> sevkiyat okundu (kesildi) — eski sevkiyatlar takvime girmemiş olabilir.</p><?php endif; ?>

  <!-- 1) Bu hafta -->
  <section class="sv-ana" id="svBuHafta" aria-label="Bu hafta beklenen ödeme">
    <div>
      <span class="sv-etiket">Bu hafta OGL'den beklenen ödeme</span>
      <span class="sv-tutar"><?= h($eur((int)$o['bu_hafta_kurus'])) ?></span>
      <span class="sv-gun"><?= h($gunAd($bh['odeme_gunu']) . ' ' . $tarih($bh['odeme_gunu'])) ?> ödeme günü</span>
    </div>
    <div class="sv-detay">
      <strong><?= (int)$o['bu_hafta_sevkiyat'] ?></strong> fatura<?php if ((int)$o['bu_hafta_gecikmis_sevkiyat'] > 0): ?> · <strong><?= (int)$o['bu_hafta_gecikmis_sevkiyat'] ?></strong> tanesi vadesi geçmiş (<?= h($eur((int)$o['bu_hafta_gecikmis_kurus'])) ?>)<?php endif; ?><br>
      <?php if ((int)$o['bu_hafta_kesinti'] > 0): ?>OGL kesintisi düşüldü: <strong><?= h($eksi((int)$o['bu_hafta_kesinti'])) ?> EUR</strong><?= (int)$o['bekleyen_kesinti_kurus'] > 0 ? ' (önceki faturalardan bekleyen ' . h($eksi((int)$o['bekleyen_kesinti_kurus'])) . ' EUR dahil)' : '' ?><br><?php endif; ?>
      Vade: <?= h($vadeMetin) ?><?= $p['vade_oto'] ? ' (geçmiş ödemelerden)' : ' (elle)' ?>
    </div>
  </section>

  <!-- 2) Özet kartlar -->
  <div class="print-summary-row">
    <div class="print-summary-box"><span class="psb-label">Gelecek hafta</span><span class="psb-value"><?= h($eur((int)$o['gelecek_hafta_kurus'])) ?></span><span class="sc-alt"><?= h($gunAd((string)$o['gelecek_odeme_gunu']) . ' ' . $tarih((string)$o['gelecek_odeme_gunu'])) ?> · <?= (int)$o['gelecek_hafta_sevkiyat'] ?> fatura</span></div>
    <div class="print-summary-box psb-warn"><span class="psb-label">Açık alacak (ödenmemiş invoice)</span><span class="psb-value"><?= h($eur((int)$o['acik_kurus'])) ?></span><span class="sc-alt"><?= (int)$o['acik_sevkiyat'] ?> fatura · kesintiler düşülmüş</span></div>
    <div class="print-summary-box psb-ok"><span class="psb-label">Şimdiye kadar ödenen</span><span class="psb-value"><?= h($eur((int)$o['odenen_kurus'])) ?></span><span class="sc-alt"><?= (int)$o['odeme_adet'] ?> ödeme<?= $o['son_odeme'] !== null ? ' · son: ' . h($tarih((string)$o['son_odeme']['tarih']) . ' · ' . $eur((int)$o['son_odeme']['kurus'])) : '' ?></span></div>
    <div class="print-summary-box"><span class="psb-label">Invoice'u kesilmemiş</span><span class="psb-value"><?= (int)$o['faturasiz_sevkiyat'] ?> sevkiyat</span><span class="sc-alt"><?= (int)$o['faturasiz_kurus'] > 0 ? 'gelen fiyatla ≈ ' . h($eur((int)$o['faturasiz_kurus'])) . ' · ' : '' ?>takvime girmez</span></div>
    <div class="print-summary-box"><span class="psb-label">Geçmiş ödeme süresi</span><span class="psb-value"><?= $gec['n'] > 0 ? ((int)$gec['min'] === (int)$gec['max'] ? (int)$gec['min'] : (int)$gec['min'] . '–' . (int)$gec['max']) . ' gün' : '—' ?></span><span class="sc-alt"><?= $gec['n'] > 0 ? h(mb_strtolower($bazlar[$p['baz']])) . 'nden avizoya · ' . (int)$gec['n'] . ' fatura · medyan ' . (int)$gec['medyan'] . ' gün' : 'avizoyla eşleşmiş ödeme yok' ?></span></div>
  </div>

  <!-- 3) Haftalık takvim -->
  <h3 class="pr-section">Haftalık ödeme takvimi</h3>
  <div class="sc-kaydir"><table class="print-table sc-tablo" id="svHaftalar">
    <thead><tr><th>Hafta</th><th>Ödeme günü</th><th class="num">Fatura</th><th class="num">Fatura tutarı (EUR)</th><th class="num">OGL kesintisi (EUR)</th><th class="num">Beklenen ödeme (EUR)</th><th class="num">Kümülatif (EUR)</th></tr></thead>
    <?php if (!$v['haftalar']): ?>
    <tbody><tr><td colspan="7" class="pr-empty">Açık (ödenmemiş) invoice yok.</td></tr></tbody>
    <?php else: ?>
    <tbody>
      <?php foreach ($v['haftalar'] as $w): ?>
      <tr class="<?= $w['bu_hafta'] ? 'sv-bu' : '' ?>">
        <td><?= h($tarih($w['bas']) . ' – ' . $tarih($w['son'])) ?><?= $w['bu_hafta'] ? '<span class="sv-rozet">Bu hafta</span>' : '' ?></td>
        <td><?= h($gunAd($w['odeme_gunu']) . ' ' . $tarih($w['odeme_gunu'])) ?></td>
        <td class="num"><?= (int)$w['sevkiyat'] ?><?= (int)$w['gecikmis_sevkiyat'] > 0 ? ' <small>(' . (int)$w['gecikmis_sevkiyat'] . ' gecikmiş)</small>' : '' ?></td>
        <td class="num"><?= h(siparis_cari_para((int)$w['net'])) ?></td>
        <td class="num<?= (int)$w['kesinti'] > 0 ? ' sv-eksi' : '' ?>"><?= h($eksi((int)$w['kesinti'])) ?></td>
        <td class="num sv-beklenen"><?= h(siparis_cari_para((int)$w['beklenen'])) ?></td>
        <td class="num"><?= h(siparis_cari_para((int)$w['kumulatif'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tbody class="sc-toplam"><tr><td colspan="5">TOPLAM AÇIK ALACAK — <?= (int)$o['acik_sevkiyat'] ?> fatura</td><td class="num"><?= h(siparis_cari_para((int)$o['acik_kurus'])) ?></td><td></td></tr></tbody>
    <?php endif; ?>
  </table></div>

  <!-- 4) Açık faturalar -->
  <h3 class="pr-section">Ödenmemiş invoice'lar (ödeme gününe göre)</h3>
  <?php if (!function_exists('can_beyan') || !can_beyan('read')): ?><p class="pr-note">Parti no görmek için beyan okuma yetkisi gerekir (sütun "—" gösterilir).</p><?php endif; ?>
  <div class="sc-kaydir"><table class="print-table sc-tablo" id="svAcik">
    <thead><tr><th>Ödeme günü</th><th>Yükleme tarihi</th><?= siparis_cari_kimlik_baslik() ?><th>Fatura no</th><th>Fatura tarihi</th><th>Vade</th><th class="num">Fatura tutarı (EUR)</th><th class="num">Kesinti (EUR)</th><th class="num">Beklenen (EUR)</th><th>Not</th></tr></thead>
    <?php if (!$v['acik']): ?>
    <tbody><tr><td colspan="13" class="pr-empty">Ödenmemiş invoice yok.</td></tr></tbody>
    <?php else: ?>
    <tbody>
      <?php foreach ($v['acik'] as $a):
        $not = [];
        if ($a['gecikmis']) $not[] = 'Vadesi geçti';
        if ($a['tarihsiz']) $not[] = 'Tarih yok — bu haftaya alındı';
        if ($a['pc_yok']) $not[] = 'Gelen fiyat (PC) bekleniyor';
        if ((int)$a['tahmini'] > 0) $not[] = 'Tahmini fiyat farkı kesintisi ' . siparis_cari_para((int)$a['tahmini']);
        foreach ($a['kesinti_liste'] as $k) $not[] = 'Kesinti ' . $k['no'] . ($k['fiyat_farki'] ? ' (fiyat farkı)' : ($k['neden'] !== '' ? ' (' . $k['neden'] . ')' : ''));
        if ((int)$a['mahsup'] > 0) $not[] = 'Elle ödemeden ' . siparis_cari_para((int)$a['mahsup']) . ' mahsup edildi';
      ?>
      <tr class="<?= trim(($a['hafta'] === $bh['bas'] ? 'sv-bu' : '') . ($a['gecikmis'] ? ' sv-gecikmis' : '')) ?>">
        <td><?= h($tarih($a['odeme_gunu'])) ?></td>
        <td><?= h($tarih($a['sev']['tarih'] ?? null)) ?></td><?= siparis_cari_kimlik_html($a['sev']) ?>
        <td><?= h((string)($a['sev']['fatura']['no'] ?? '') !== '' ? (string)$a['sev']['fatura']['no'] : '—') ?></td>
        <td><?= h($tarih($a['sev']['fatura']['tarih'] ?? null)) ?></td>
        <td><?= h($tarih($a['vade_tarih'])) ?></td>
        <td class="num"><?= h(siparis_cari_para((int)$a['net'])) ?></td>
        <td class="num<?= ((int)$a['kesinti'] + (int)$a['tahmini']) > 0 ? ' sv-eksi' : '' ?>"><?= h($eksi((int)$a['kesinti'] + (int)$a['tahmini'])) ?></td>
        <td class="num sv-beklenen"><?= h(siparis_cari_para((int)$a['beklenen'])) ?></td>
        <td class="sv-not"><?= $not ? h(implode(' · ', $not)) : '' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <?php endif; ?>
  </table></div>

  <?php if ($v['bekleyen_kesinti']): ?>
  <!-- 5) Ödenmiş faturaların henüz düşülmemiş kesintileri -->
  <h3 class="pr-section">Bu haftanın ödemesinden düşülecek kesintiler</h3>
  <p class="pr-note">Faturası ödenmiş (ya da sevkiyatı bilinmeyen) ve henüz hiçbir avizoda düşülmemiş OGL kesintileri (debit note).</p>
  <div class="sc-kaydir"><table class="print-table sc-tablo" id="svKesinti">
    <thead><tr><th>Belge no</th><th>Tarih</th><th>Sipariş no</th><th>Neden</th><th class="num">Tutar (EUR)</th></tr></thead>
    <tbody>
      <?php foreach ($v['bekleyen_kesinti'] as $k): ?>
      <tr><td><?= h($k['no']) ?></td><td><?= h($tarih($k['tarih'])) ?></td><td><?= h($k['ref'] !== '' ? $k['ref'] : '—') ?></td><td><?= h($k['fiyat_farki'] ? 'Fiyat farkı' : ($k['neden'] !== '' ? $k['neden'] : 'Diğer')) ?></td><td class="num sv-eksi"><?= h($eksi((int)$k['kurus'])) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

  <?php if ($v['faturasiz']): ?>
  <!-- 6) Invoice'u kesilmemiş yüklenen sevkiyatlar -->
  <h3 class="pr-section">Invoice'u kesilmemiş yüklenen sevkiyatlar (takvime girmez)</h3>
  <div class="sc-kaydir"><table class="print-table sc-tablo" id="svFaturasiz">
    <thead><tr><th>Yükleme tarihi</th><?= siparis_cari_kimlik_baslik() ?><th class="num">Kg</th><th class="num">Gelen fiyat tutarı (EUR)</th></tr></thead>
    <tbody>
      <?php foreach ($v['faturasiz'] as $fz): ?>
      <tr><td><?= h($tarih($fz['sev']['tarih'] ?? null)) ?></td><?= siparis_cari_kimlik_html($fz['sev']) ?><td class="num"><?= h(siparis_cari_kg((int)($fz['sev']['toplam']['gram'] ?? 0))) ?></td><td class="num"><?= $fz['gelen_kurus'] !== null ? h(siparis_cari_para((int)$fz['gelen_kurus'])) : 'Fiyat bekleniyor' ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

  <!-- 7) Geçmiş ödemeler -->
  <h3 class="pr-section">Geçmiş OGL ödemeleri</h3>
  <div class="sc-kaydir"><table class="print-table sc-tablo" id="svOdemeler">
    <thead><tr><th>Ödeme tarihi</th><th>Gün</th><th>Kaynak</th><th class="num">Ödenen fatura</th><th class="num">Fatura → ödeme (gün)</th><th class="num">Tutar (EUR)</th></tr></thead>
    <?php if (!$v['odemeler']): ?>
    <tbody><tr><td colspan="6" class="pr-empty">Kayıtlı EUR ödemesi yok.</td></tr></tbody>
    <?php else: ?>
    <tbody>
      <?php foreach (array_reverse($v['odemeler']) as $od): $gg = $od['gecikme']; ?>
      <tr><td><?= h($tarih($od['tarih'])) ?></td><td><?= h($od['tarih'] !== '' ? $gunAd($od['tarih']) : '—') ?></td><td><?= $od['avizolu'] ? 'Avizo' : 'Elle girildi' ?></td>
        <td class="num"><?= $od['avizolu'] ? (int)$od['sevkiyat'] . ((int)$od['eslesmeyen'] > 0 ? ' <small>(+' . (int)$od['eslesmeyen'] . ' eşleşmeyen)</small>' : '') : '—' ?></td>
        <td class="num"><?= $gg['n'] > 0 ? ((int)$gg['min'] === (int)$gg['max'] ? (int)$gg['min'] : (int)$gg['min'] . '–' . (int)$gg['max']) : '—' ?></td>
        <td class="num"><?= h(siparis_cari_para((int)$od['kurus'])) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
    <tbody class="sc-toplam"><tr><td colspan="5">TOPLAM ÖDENEN</td><td class="num"><?= h(siparis_cari_para((int)$o['odenen_kurus'])) ?></td></tr></tbody>
    <?php endif; ?>
  </table></div>
  <?php if ($v['odenmis']): ?>
  <details class="sv-gecmis">
    <summary>Ödenmiş invoice'lar (<?= count($v['odenmis']) ?>)</summary>
    <div class="sc-kaydir"><table class="print-table sc-tablo" id="svOdenmis">
      <thead><tr><th>Ödeme tarihi</th><th>Yükleme tarihi</th><?= siparis_cari_kimlik_baslik() ?><th>Fatura no</th><th>Fatura tarihi</th><th class="num">Gün</th><th class="num">Fatura tutarı (EUR)</th><th class="num">Ödenen (EUR)</th></tr></thead>
      <tbody>
        <?php foreach ($v['odenmis'] as $od): ?>
        <tr><td><?= $od['elle'] ? 'Elle ödemeden (tahmini)' : h($tarih($od['odeme_tarih'])) ?></td><td><?= h($tarih($od['sev']['tarih'] ?? null)) ?></td><?= siparis_cari_kimlik_html($od['sev']) ?>
          <td><?= h((string)($od['sev']['fatura']['no'] ?? '') !== '' ? (string)$od['sev']['fatura']['no'] : '—') ?></td><td><?= h($tarih($od['sev']['fatura']['tarih'] ?? null)) ?></td>
          <td class="num"><?= $od['gecikme'] !== null ? (int)$od['gecikme'] : '—' ?></td><td class="num"><?= h(siparis_cari_para((int)$od['net'])) ?></td><td class="num"><?= h(siparis_cari_para((int)$od['odenen'])) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </details>
  <?php endif; ?>

  <!-- 8) Uyarılar / notlar -->
  <section class="sc-cari">
    <h3 class="pr-section">Uyarılar ve notlar</h3>
    <ul class="sd-uyari-liste" id="svUyarilar">
      <?php foreach ($v['uyarilar'] as $u): ?><li class="<?= ($u['seviye'] ?? '') === 'uyari' ? 'sd-u-uyari' : '' ?>"><?= h((string)$u['mesaj']) ?></li><?php endforeach; ?>
      <?php if (!$v['uyarilar']): ?><li>Uyarı yok.</li><?php endif; ?>
    </ul>
    <ul class="sd-notlar">
      <li>Ödenen faturalar OGL ödeme avizosundaki fatura numaralarından bulunur (💶 Ödeme sorgula ile alınan avizolar). Avizo gelmeden ödeme "ödendi" sayılmaz.</li>
      <li>Vade otomatikken: geçmişte en kısa sürede ödenen faturanın süresi alınır (bu hafta tutarını şişirmez). OGL'nin vadesini biliyorsanız "Vade (gün)" kutusuna yazın.</li>
      <li>Fatura tutarı = üretilen invoice'un iskonto sonrası toplamı. Kesinti = avizoda henüz düşülmemiş OGL debit note'ları + gelen fiyat kesilenden düşükse tahmini fiyat farkı (iskonto sonrası).</li>
      <li>Vadesi geçmiş ama avizosu gelmemiş faturalar bu haftaya eklenir. Para birimleri toplanmaz; takvim yalnız EUR invoice'ları kapsar.</li>
    </ul>
  </section>
</div>
<script>
(function () { var b = document.querySelector('[data-sc-yazdir]'); if (b) b.addEventListener('click', function () { var d = document.querySelectorAll('details.sv-gecmis'); d.forEach(function (x) { x.open = true; }); window.print(); }); })();
</script>
<?php render_print_page_end(); ?>
