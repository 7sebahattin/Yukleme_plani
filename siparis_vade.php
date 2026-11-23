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
// v572: Motor'un yeni alanları — henüz yoksa savunmacı oku (sayfa tek başına hata vermez). Hesap YOK, yalnız gösterim.
$al = static fn(string $k, $varsayilan) => $o[$k] ?? $v[$k] ?? $bh[$k] ?? $varsayilan;
$bhKurus = (int)$o['bu_hafta_kurus'];
$bhKalan = max(0, (int)($bh['kalan_kisim_kurus'] ?? 0));
$bhKesin = isset($bh['kesin_kurus']) ? (int)$bh['kesin_kurus'] : $bhKurus - $bhKalan;
$bhAna = $bhKalan > 0 ? $bhKesin : $bhKurus;   // büyük rakam: faturası girilmiş KESİN tutar (kalan yoksa eski rakam)
$uy = is_array($o['uyum'] ?? null) ? $o['uyum'] : [];
$tutarli = $uy ? (bool)($uy['tutarli'] ?? true) : true;
$sapma = (int)($uy['sapma_kurus'] ?? 0);
$asiri = (bool)$al('bu_hafta_asiri', false);
$gmax = (int)$al('gecmis_odeme_max_kurus', 0);
$kasaK = (int)($o['kasa_kurus'] ?? $o['odenen_kurus']);
$avFark = (int)($o['avizo_fark_kurus'] ?? 0);
$kalanTop = (int)($o['kalan_kisim_kurus'] ?? 0);
$eksiE = static fn(int $k): string => $k > 0 ? '−' . siparis_cari_para($k) . ' EUR' : ($k < 0 ? '+' . siparis_cari_para(-$k) . ' EUR' : '0,00 EUR');   // negatif çıkan düşüm = ARTI (fazla ödeme); 0'a yuvarlanmaz
$isaretli = static fn(int $k): string => ($k > 0 ? '+' : ($k < 0 ? '−' : '')) . siparis_cari_para(abs($k));
$vadeMetin = $bazlar[$p['baz']] . ' + ' . (int)$p['vade'] . ' gün → ilk ' . $gunler[(int)$p['gun']];
render_print_page_start('OGL Haftalık Ödeme', 'siparis', 'summary', 'landscape', ['print_pdks.css']);
?>
<style>
@page { size: A4 landscape; margin: 0; }
@media print { body { padding: 10mm; } }
body.print-siparis .print-sheet { max-width: 1440px; }
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
table.print-table.sc-tablo td.sv-yg { font-weight: 700; }
table.print-table.sc-tablo td.sv-not { font-size: .92em; color: var(--pr-muted); }
table.print-table.sc-tablo td.sv-not { min-width: 200px; }
tr.sv-uyum-sapma-satir td { color: #b3261e; font-weight: 700; }
@media print { .pr-section { break-after: avoid; } .sv-uyum, #svHaftalar { break-inside: avoid; } }
.sv-ana .sv-kalan-satir { display: block; margin-top: 4px; font-size: .84rem; font-weight: 700; color: #7a4b00; font-variant-numeric: tabular-nums; }
.sv-ana .sv-kalan-satir strong { color: #5a3600; }
.sv-ana .sv-asiri { flex: 1 1 100%; margin: 0; padding: 6px 10px; border: 1px solid #e0a040; border-radius: 6px; background: #fff3dc; color: #7a4b00; font-size: .82rem; font-weight: 700; line-height: 1.4; }
.sv-ana.sv-ana-hata { border-color: #b3261e; background: #fdecea; }
.sv-ana.sv-ana-hata .sv-etiket { color: #7a1a14; }
.sv-ana.sv-ana-hata .sv-tutar { font-size: 1.15rem; color: #7a1a14; font-weight: 700; }
.sv-ana.sv-ana-hata .sv-gun, .sv-ana.sv-ana-hata .sv-detay, .sv-ana.sv-ana-hata .sv-detay strong { color: #5a1612; }
.sv-ana .sv-hata-bant { flex: 1 1 100%; margin: 0; padding: 8px 12px; border-radius: 6px; background: #b3261e; color: #fff; font-size: .95rem; font-weight: 800; line-height: 1.35; }
.sv-uyum { border: 1px solid #c9d6e6; border-radius: 10px; padding: 8px 12px 10px; margin: 10px 0 12px; background: #fbfdff; }
.sv-uyum.sv-uyum-sapma { border: 2px solid #b3261e; background: #fdecea; }
.sv-uyum h3.pr-section { margin-top: 2px; }
table.print-table.sc-tablo.sv-uyum-tablo { max-width: 760px; }
table.print-table.sc-tablo.sv-uyum-tablo td:first-child { width: 68%; }
table.print-table.sc-tablo.sv-uyum-tablo tr.sv-uyum-ara td { font-weight: 800; border-top: 1px solid var(--pr-ink); background: #f2f6fb; }
table.print-table.sc-tablo.sv-uyum-tablo tr.sv-uyum-son td { font-weight: 800; border-top: 2px solid var(--pr-ink); background: var(--pr-total-bg); }
.sv-uyum-durum { display: inline-block; font-size: .8rem; font-weight: 800; padding: 1px 8px; border-radius: 9px; margin-left: 6px; }
.sv-uyum-durum.sv-ok { background: #1b6e3c; color: #fff; }
.sv-uyum-durum.sv-sapma { background: #b3261e; color: #fff; }
.sv-uyum .sv-uyum-not { margin: 6px 0 0; font-size: .74rem; color: var(--pr-muted); }
table.print-table.sc-tablo tbody tr.sv-kalan td { background: #fff7e2; }
table.print-table.sc-tablo tbody tr.sv-kalan td.sv-fno { font-style: italic; }
table.print-table.sc-tablo tbody tr.sv-kalan td.sv-not { color: #7a4b00; font-weight: 700; }
.sv-gri { display: block; font-size: .72em; color: var(--pr-muted); font-style: normal; font-weight: 400; }
.sv-isk { display: inline-block; font-size: .7rem; font-weight: 800; padding: 1px 6px; border-radius: 9px; background: #e67e00; color: #fff; margin-left: 4px; vertical-align: 1px; cursor: help; }
.sv-fark-not { color: #b3261e; font-weight: 700; }
.sv-rozet { display: inline-block; font-size: .7rem; font-weight: 800; padding: 1px 6px; border-radius: 9px; background: #1b6e3c; color: #fff; margin-left: 4px; vertical-align: 1px; }
.sd-hata { border: 2px solid var(--pr-warn); border-radius: 6px; padding: 6px 10px; margin: 8px 0; font-size: .82rem; color: var(--pr-warn); font-weight: 700; }
.sd-hata ul { margin: 2px 0 0 18px; padding: 0; }
.sd-notlar { margin: 6px 0 0 18px; padding: 0; font-size: .76rem; color: var(--pr-muted); }
.sd-uyari-liste { margin: 4px 0 0 18px; padding: 0; font-size: .8rem; }
.sd-uyari-liste li { margin: 2px 0; }
.sd-uyari-liste li.sd-u-uyari { font-weight: 700; color: var(--pr-warn); }
.sd-uyari-liste li.sd-u-hata { font-weight: 800; color: #b3261e; }
.sc-cari { break-inside: avoid; page-break-inside: avoid; }
details.sv-gecmis > summary { cursor: pointer; font-weight: 700; margin: 6px 0; }
@media screen { .sc-kaydir { overflow-x: auto; -webkit-overflow-scrolling: touch; } }
@media screen { table.print-table.sc-tablo th, table.print-table.sc-tablo td { padding-left: 5px; padding-right: 5px; } table.print-table.sc-tablo { font-size: .76rem; } }
@media screen and (max-width: 767px) { .sv-ana .sv-tutar { font-size: 1.6rem; } }
@media print {
  html, body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  table.print-table.sc-tablo { font-size: 7.8pt; }
  table.print-table.sc-tablo thead { display: table-header-group; }
  table.print-table.sc-tablo tr, .sc-cari, .sd-hata, .sv-ana, .sv-uyum { break-inside: avoid; page-break-inside: avoid; }
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
  <section class="sv-ana<?= $tutarli ? '' : ' sv-ana-hata' ?>" id="svBuHafta" aria-label="Bu hafta beklenen ödeme">
    <?php if (!$tutarli): ?><p class="sv-hata-bant" role="alert" id="svDogrulanamadi">RAKAM DOĞRULANAMADI — patrona vermeyin. Cari bakiyeyle sapma: <?= h($isaretli($sapma)) ?> EUR</p><?php endif; ?>
    <div>
      <span class="sv-etiket"><?= $tutarli ? 'Bu hafta OGL\'den beklenen ödeme' . ($bhKalan > 0 ? ' — KESİN (faturası girilmiş)' : '') : 'Hesaplanan rakam (doğrulanamadı)' ?></span>
      <span class="sv-tutar"><?= h($eur($bhAna)) ?></span>
      <?php if ($bhKalan > 0): ?><span class="sv-kalan-satir" id="svKalanKisim">+ <strong><?= h($eur($bhKalan)) ?></strong> faturası girilmemiş kalan kısım (kesin değil) = olası toplam <strong><?= h($eur($bhKurus)) ?></strong></span><?php endif; ?>
      <span class="sv-gun"><?= h($gunAd($bh['odeme_gunu']) . ' ' . $tarih($bh['odeme_gunu'])) ?> ödeme günü</span>
    </div>
    <div class="sv-detay">
      <strong><?= (int)$o['bu_hafta_sevkiyat'] ?></strong> fatura<?php if ((int)$o['bu_hafta_gecikmis_sevkiyat'] > 0): ?> · <strong><?= (int)$o['bu_hafta_gecikmis_sevkiyat'] ?></strong> tanesi vadesi geçmiş (<?= h($eur((int)$o['bu_hafta_gecikmis_kurus'])) ?>)<?php endif; ?><br>
      <?php if ((int)$o['bu_hafta_kesinti'] > 0): ?>OGL kesintisi düşüldü: <strong><?= h($eksi((int)$o['bu_hafta_kesinti'])) ?> EUR</strong><?= (int)$o['bekleyen_kesinti_kurus'] > 0 ? ' (önceki faturalardan bekleyen ' . h($eksi((int)$o['bekleyen_kesinti_kurus'])) . ' EUR dahil)' : '' ?><br><?php endif; ?>
      Vade: <?= h($vadeMetin) ?><?= $p['vade_oto'] ? ' (geçmiş ödemelerden)' : ' (elle)' ?>
    </div>
    <?php if ($asiri && $gmax > 0): ?><p class="sv-asiri" id="svAsiri">⚠ Geçmiş ödemelerin en büyüğü <?= h($eur($gmax)) ?>; bu hafta tahmini <?= h($eur($bhKurus)) ?> — birikmiş gecikmişler tek seferde ödenmeyebilir.</p><?php endif; ?>
  </section>

  <?php if ($uy): ?>
  <!-- 1b) Cari bakiye uyum kontrolü -->
  <section class="sv-uyum sc-cari<?= $tutarli ? '' : ' sv-uyum-sapma' ?>" id="svUyum" aria-label="Cari bakiye uyum kontrolü">
    <h3 class="pr-section">Cari bakiye uyum kontrolü
      <?php if ($tutarli): ?><span class="sv-uyum-durum sv-ok">✓ tutarlı</span><?php else: ?><span class="sv-uyum-durum sv-sapma">✗ sapma <?= h($isaretli($sapma)) ?> EUR</span><?php endif; ?></h3>
    <div class="sc-kaydir"><table class="print-table sc-tablo sv-uyum-tablo">
      <tbody>
        <tr><td>Σ Invoice net tutarı (iskonto sonrası, tüm sevkiyatlar)</td><td class="num"><?= h($eur((int)$o['fatura_toplam_kurus'])) ?></td></tr>
        <tr><td>− Kasaya giren (avizolardan gelen net ödeme)</td><td class="num"><?= h($eksiE($kasaK)) ?></td></tr>
        <tr class="sv-uyum-ara"><td>= Cari bakiye (Cari Döküm bakiyesiyle aynı mantık)</td><td class="num"><?= h($eur((int)($uy['cari_bakiye_kurus'] ?? ((int)$o['fatura_toplam_kurus'] - $kasaK)))) ?></td></tr>
        <tr><td>− Avizoda düşülen fark (OGL eksik ödemiş; tahsil edilmeyecek)</td><td class="num"><?= h($eksiE((int)($uy['avizo_fark_kurus'] ?? $avFark))) ?></td></tr>
        <tr><td>− Bekleyen kesintiler (fiyat farkı + debit note; bu hafta ve sonrası)</td><td class="num"><?= h($eksiE((int)($uy['bekleyen_kesinti_kurus'] ?? $o['bekleyen_kesinti_kurus']))) ?></td></tr>
        <?php if (!$tutarli): ?><tr class="sv-uyum-sapma-satir"><td>± Sapma (açıklanamayan fark — tablo bu yüzden toplamıyor)</td><td class="num"><?= h($isaretli($sapma)) ?> EUR</td></tr><?php endif; ?>
        <tr class="sv-uyum-son"><td>= Açık alacak (yukarıdaki kart)</td><td class="num"><?= h($eur((int)($uy['acik_kurus'] ?? $o['acik_kurus']))) ?></td></tr>
      </tbody>
    </table></div>
    <p class="sv-uyum-not"><?= $tutarli ? '✓ Rakamlar Cari bakiyeyle birebir tutuyor (sapma 0,00 EUR).' : '✗ Rakamlar Cari bakiyeyle tutmuyor — sapma ' . h($isaretli($sapma)) . ' EUR. Patrona vermeden önce kontrol edin.' ?> "Kasaya giren" avizolardan gerçekten gelen paradır; "kapanan fatura" = kasaya giren + avizoda düşülen farktır, ikisi aynı şey değildir.</p>
  </section>
  <?php endif; ?>

  <!-- 2) Özet kartlar -->
  <div class="print-summary-row">
    <div class="print-summary-box"><span class="psb-label">Gelecek hafta</span><span class="psb-value"><?= h($eur((int)$o['gelecek_hafta_kurus'])) ?></span><span class="sc-alt"><?= h($gunAd((string)$o['gelecek_odeme_gunu']) . ' ' . $tarih((string)$o['gelecek_odeme_gunu'])) ?> · <?= (int)$o['gelecek_hafta_sevkiyat'] ?> fatura</span></div>
    <div class="print-summary-box psb-warn"><span class="psb-label">Açık alacak (ödenmemiş invoice)</span><span class="psb-value"><?= h($eur((int)$o['acik_kurus'])) ?></span><span class="sc-alt"><?= (int)$o['acik_sevkiyat'] ?> fatura · kesintiler düşülmüş · invoice toplamı <?= h(siparis_cari_para((int)$o['fatura_toplam_kurus'])) ?> − kapanan fatura <?= h(siparis_cari_para((int)$o['odenen_fatura_kurus'])) ?> (kasaya giren <?= h(siparis_cari_para($kasaK)) ?><?= $avFark > 0 ? ' + avizoda düşülen fark ' . h(siparis_cari_para($avFark)) : '' ?>)<?= (int)$o['mahsup_kurus'] > 0 ? ' · kasaya girenin ' . h(siparis_cari_para((int)$o['mahsup_kurus'])) . ' kadarı tahmini mahsup' : '' ?><?= $kalanTop > 0 ? ' · içinde faturası girilmemiş kalan kısım ' . h(siparis_cari_para($kalanTop)) . ' (kesin değil)' : '' ?></span></div>
    <div class="print-summary-box psb-ok"><span class="psb-label">Kasaya giren (avizolardan)</span><span class="psb-value"><?= h($eur($kasaK)) ?></span><span class="sc-alt"><?= (int)$o['odeme_adet'] ?> ödeme<?= $o['son_odeme'] !== null ? ' · son: ' . h($tarih((string)$o['son_odeme']['tarih']) . ' · ' . $eur((int)$o['son_odeme']['kurus'])) : '' ?> · gerçekten gelen para<?= $avFark > 0 ? ' · kapanan fatura ' . h(siparis_cari_para((int)$o['odenen_fatura_kurus'])) . ' (avizoda düşülen fark ' . h(siparis_cari_para($avFark)) . ' dahil)' : '' ?></span></div>
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
    <thead><tr><th>Ödeme günü</th><th>Yükleme tarihi</th><th class="num">Yüklemeden beri (gün)</th><?= siparis_cari_kimlik_baslik() ?><th>Fatura no</th><th>Fatura tarihi</th><th>Vade</th><th class="num">Tutar (EUR)</th><th class="num">Kesinti (EUR)</th><th class="num">Beklenen (EUR)</th><th>Not</th></tr></thead>
    <?php if (!$v['acik']): ?>
    <tbody><tr><td colspan="14" class="pr-empty">Ödenmemiş invoice yok.</td></tr></tbody>
    <?php else: ?>
    <tbody>
      <?php foreach ($v['acik'] as $a):
        $not = [];
        $kalanK = !empty($a['kalan_kisim']);
        if ($kalanK) $not[] = 'Kalan kısım — invoice girilmemiş, kesin değil';
        if ($a['gecikmis']) $not[] = 'Vadesi geçti';
        if ($a['tarihsiz']) $not[] = 'Tarih yok — bu haftaya alındı';
        if ($a['pc_yok']) $not[] = 'Gelen fiyat (PC) bekleniyor';
        if ((int)$a['tahmini'] > 0) $not[] = 'Tahmini fiyat farkı kesintisi ' . siparis_cari_para((int)$a['tahmini']);
        foreach ($a['kesinti_liste'] as $k) $not[] = 'Kesinti ' . $k['no'] . ($k['fiyat_farki'] ? ' (fiyat farkı)' : ($k['neden'] !== '' ? ' (' . $k['neden'] . ')' : ''));
        if ($a['kismi']) $not[] = 'Sevkiyatın bir kısmı avizoda ödenmiş; bu tutar kalan kısım';
        elseif ($a['invoice_kismi']) $not[] = 'Sistemdeki invoice sevkiyatın yalnız bir kısmı — sevkiyatın tamamı alındı';
        if ((int)$a['mahsup'] > 0) $not[] = 'Eşleşmeyen/elle ödemeden ' . siparis_cari_para((int)$a['mahsup']) . ' mahsup edildi (tahmini)';
      ?>
      <tr class="<?= trim(($a['hafta'] === $bh['bas'] ? 'sv-bu' : '') . ($a['gecikmis'] ? ' sv-gecikmis' : '') . ($kalanK ? ' sv-kalan' : '')) ?>">
        <td><?= h($tarih($a['odeme_gunu'])) ?></td>
        <td><?= h($tarih($a['sev']['tarih'] ?? null)) ?></td>
        <td class="num sv-yg"><?= $a['yukleme_gun'] === null ? '—' : (int)$a['yukleme_gun'] ?></td><?= siparis_cari_kimlik_html($a['sev']) ?>
        <?php $fNo = (string)($a['sev']['fatura']['no'] ?? ''); ?>
        <?php if ($kalanK): ?><td class="sv-fno">— (kalan kısım · invoice girilmemiş)<?= $fNo !== '' ? '<span class="sv-gri" title="Ödenmiş faturanın numarası">ödenmiş fatura: ' . h($fNo) . '</span>' : '' ?></td>
        <?php else: ?><td><?= h($fNo !== '' ? $fNo : '—') ?></td><?php endif; ?>
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
      <thead><tr><th>Ödeme tarihi</th><th>Yükleme tarihi</th><?= siparis_cari_kimlik_baslik() ?><th>Fatura no</th><th>Fatura tarihi</th><th class="num">Gün</th><th class="num">Fatura tutarı (EUR)</th><th class="num">Ödenen (EUR)</th><th>Not</th></tr></thead>
      <tbody>
        <?php foreach ($v['odenmis'] as $od): $odFark = (int)($od['fark_kurus'] ?? 0); ?>
        <tr><td><?= $od['elle'] ? 'Mahsup (tahmini)' : h($tarih($od['odeme_tarih'])) . (!empty($od['kismi']) ? ' <small>(kısmi)</small>' : '') ?></td><td><?= h($tarih($od['sev']['tarih'] ?? null)) ?></td><?= siparis_cari_kimlik_html($od['sev']) ?>
          <td><?= h((string)($od['sev']['fatura']['no'] ?? '') !== '' ? (string)$od['sev']['fatura']['no'] : '—') ?></td><td><?= h($tarih($od['sev']['fatura']['tarih'] ?? null)) ?></td>
          <td class="num"><?= $od['gecikme'] !== null ? (int)$od['gecikme'] : '—' ?></td><td class="num"><?= h(siparis_cari_para((int)$od['net'])) ?></td><td class="num"><?= h(siparis_cari_para((int)$od['odenen'])) ?></td>
          <td class="sv-not"><?php if ($odFark > 0): ?><span class="sv-fark-not">avizoda <?= h(siparis_cari_para($odFark)) ?> eksik</span><?php endif; ?><?php if (!empty($od['iskonto_suphesi'])): ?><span class="sv-isk" title="Avizoda ödenen, fatura net tutarından tam %4 eksik — fatura iskontosu girilmemiş/çift uygulanmış olabilir">iskonto?</span><?php endif; ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </details>
  <?php endif; ?>

  <!-- 8) Uyarılar / notlar -->
  <section class="sc-cari">
    <h3 class="pr-section">Uyarılar ve notlar</h3>
    <ul class="sd-uyari-liste" id="svUyarilar">
      <?php foreach ($v['uyarilar'] as $u): ?><li class="<?= ($u['seviye'] ?? '') === 'hata' ? 'sd-u-hata' : (($u['seviye'] ?? '') === 'uyari' ? 'sd-u-uyari' : '') ?>"><?= h((string)$u['mesaj']) ?></li><?php endforeach; ?>
      <?php if (!$v['uyarilar']): ?><li>Uyarı yok.</li><?php endif; ?>
    </ul>
    <ul class="sd-notlar">
      <li>Ödenen faturalar OGL ödeme avizosundaki fatura numaralarından bulunur (💶 Ödeme sorgula ile alınan avizolar). Avizoda geçen ama sistemdeki bir invoice'la eşleşmeyen ödemeler en eski açık faturalara sırayla mahsup edilir — bu kısım TAHMİNİDİR. Açık alacak = Σ sevkiyat net tutarı − kapanan faturalar (Cari Döküm bakiyesiyle aynı mantık). "Kasaya giren" avizolardan gelen gerçek para, "kapanan fatura" = kasaya giren + avizoda düşülen farktır; "Cari bakiye uyum kontrolü" ikisinin farkını gösterir.</li>
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
