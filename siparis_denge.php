<?php
// =========================================================
// siparis_denge.php — "Fiyat Dengeleme" (yazdırılabilir A4 yatay; GET; siparis.read + siparis_cari_yetkili(); v448)
// Invoice (OGL) ekranında kesilen ORTALAMA fatura fiyatı ile OGL Price Confirmation (PC) fiyatı arasındaki farkı gösterir ve
// farkı sonraki N faturaya ufak ufak yansıtacak fatura fiyatı önerir. SALT OKUNUR: mutasyon/CSRF yok (yalnız audit sayıları).
// Ad TEK alt çizgili: sw.js bypass regex'i /siparis(ler|_[a-z]+)?\.php/ kapsar (ticari veri SW önbelleğine yazılmaz).
// Mantık config/siparis_cari.php + config/siparis_denge.php'de; bu dosya FONKSİYON TANIMLAMAZ. Satır içi onclick YOK.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/print_helpers.php';
require_once __DIR__ . '/_siparis_ortak.php';
require_once __DIR__ . '/config/siparis_cari.php';
require_once __DIR__ . '/config/siparis_denge.php';
$auth_user = require_login();
require_siparis('read');
if (!siparis_cari_yetkili()) forbidden('Bu sayfaya erişim yetkiniz yok. (Gerekli yetki: reports.export)');
siparis_no_store();
session_write_close();

$pdo = db();
$bugun = date('Y-m-d');
if (!siparis_sema_hazir($pdo)) {
    render_print_page_start('Fiyat Dengeleme', 'siparis', 'summary', 'landscape', ['print_pdks.css']);
    echo '<div class="print-sheet">' . siparis_kurulmadi_html() . '</div>';
    render_print_page_end();
    exit;
}
$f = siparis_cari_filtre($_GET, $bugun);
$okunan = siparis_cari_oku($pdo, $f);
$sevIdler = array_map(static fn(array $r): int => (int)$r['id'], $okunan['rows']);
$faturaFiyat = siparis_cari_fatura_fiyatlari($pdo, $sevIdler);
$v = siparis_cari_veri($okunan, ['bugun' => $bugun, 'tahsilat' => [], 'fatura' => $faturaFiyat, 'parti' => siparis_cari_parti_haritasi($pdo, $sevIdler)]);
$param = siparis_denge_param($_GET, $v);   // varsayılanlar (kg boş → ortalama kg, hedef boş → son sevkiyat PC fiyatı) cari verisinden
$d = siparis_denge_hesapla($v, $param);
$o = $d['ozet'];
$po = $d['plan_ozet'];
siparis_audit('siparis_denge_goruntule', null, null, ['sevkiyat' => (int)$o['adet'], 'n' => (int)$param['n'], 'kesildi' => $v['kesildi'] ? 1 : 0], (int)$auth_user['id']);
$depo = (string)(active_depot() ?? '');
$harita = siparis_yazdir_ulke_haritasi();
$tarih = static fn(?string $t): string => $t !== null && $t !== '' ? sipui_tarih($t) : '—';
$isaretli = static fn(int $k): string => ($k > 0 ? '+' : '') . siparis_cari_para($k);   // kuruş, işaretli (+ yalnız pozitifte)
$eur = static fn(int $k): string => siparis_cari_para($k) . ' EUR';
$eurI = static fn(int $k): string => $isaretli($k) . ' EUR';
$fiyat = static fn(int $b): string => siparis_cari_fiyat_kisa($b);
$farkSinif = static fn(int $x): string => $x < 0 ? ' sc-fark-eksi' : ($x > 0 ? ' sc-fark-arti' : '');
$ham = static fn(string $k): string => is_string($_GET[$k] ?? null) ? trim((string)$_GET[$k]) : '';   // form kutusu: kullanıcının yazdığı (h() ile basılır)
$kgTam = static fn(int $kg): string => $kg > 0 ? number_format($kg, 0, ',', '.') : '';
$hane = (int)$param['hane'];
$kapat = (int)$o['kapatilacak_kurus'];
$pctMetin = static fn(string $m): string => $m . ' %';   // duzeltme_oran_metin: işaretli, 2 ondalık ("+1,23")
render_print_page_start('Fiyat Dengeleme', 'siparis', 'summary', 'landscape', ['print_pdks.css']);
?>
<style>
@page { size: A4 landscape; margin: 0; }
@media print { body { padding: 10mm; } }
body.print-siparis .print-sheet { max-width: 1240px; }
.sc-filtre { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; margin: 0; }
.sc-filtre label { display: flex; flex-direction: column; gap: 2px; font-size: .72rem; font-weight: 700; color: var(--pr-muted); }
.sc-filtre input, .sc-filtre select { font: inherit; font-size: .85rem; padding: 6px 8px; min-height: 36px; border: 1px solid #c3ccd8; border-radius: 6px; background: #fff; color: var(--pr-ink); }
.sc-filtre input[type="number"], .sc-filtre input[type="text"].sd-kisa { width: 120px; }
.sd-formlar { display: flex; flex-direction: column; gap: 8px; width: 100%; }
.sc-kural { margin: 6px 0 2px; }
.sc-uyari { font-weight: 700; color: var(--pr-warn); }
.print-summary-box .sc-alt { display: block; margin-top: 3px; font-size: .74rem; color: var(--pr-muted); font-variant-numeric: tabular-nums; }
.print-summary-box .sc-pb { display: block; font-size: 1rem; font-weight: 800; line-height: 1.25; font-variant-numeric: tabular-nums; }
table.print-table.sc-tablo .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
table.print-table.sc-tablo tbody tr td { background: #fff; }
table.print-table.sc-tablo tbody.sc-toplam td { background: var(--pr-total-bg); font-weight: 800; border-top: 2px solid var(--pr-ink); }
table.print-table.sc-tablo .sc-bos { color: var(--pr-muted); font-style: italic; }
table.print-table.sc-tablo .sc-fark-eksi { color: #b3261e; font-weight: 700; }
table.print-table.sc-tablo .sc-fark-arti { color: #1b6e3c; font-weight: 700; }
table.print-table.sc-tablo td.sd-oneri { font-weight: 800; font-size: 1.08em; border: 2px solid var(--pr-ink); background: #fff7d6; }
table.print-table.sc-tablo th.sd-oneri { border: 2px solid var(--pr-ink); }
.sd-hata { border: 2px solid var(--pr-warn); border-radius: 6px; padding: 6px 10px; margin: 8px 0; font-size: .82rem; color: var(--pr-warn); font-weight: 700; }
.sd-hata ul { margin: 2px 0 0 18px; padding: 0; }
.sd-notlar { margin: 6px 0 0 18px; padding: 0; font-size: .76rem; color: var(--pr-muted); }
.sd-uyari-liste { margin: 4px 0 0 18px; padding: 0; font-size: .8rem; }
.sd-uyari-liste li { margin: 2px 0; }
.sd-uyari-liste li.sd-u-uyari { font-weight: 700; color: var(--pr-warn); }
.sc-cari { break-inside: avoid; page-break-inside: avoid; }
@media screen and (max-width: 900px) { .sc-kaydir { overflow-x: auto; -webkit-overflow-scrolling: touch; } }
@media screen and (max-width: 767px) { .sc-filtre input, .sc-filtre select { font-size: 16px; } }
@media print {
  html, body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  table.print-table.sc-tablo { font-size: 7.8pt; }
  table.print-table.sc-tablo thead { display: table-header-group; }
  table.print-table.sc-tablo tr, .sc-cari, .sd-hata { break-inside: avoid; page-break-inside: avoid; }
}
</style>
<div class="print-sheet">
  <div class="pr-actions no-print">
    <div class="sd-formlar">
      <form method="get" action="siparis_denge.php" class="sc-filtre">
        <label>Yükleme başı <input type="date" name="bas" value="<?= h($f['bas']) ?>"></label>
        <label>Yükleme sonu <input type="date" name="bit" value="<?= h($f['bit']) ?>"></label>
        <label>Alıcı <select name="alici"><option value="">Tümü</option>
          <?php foreach ($harita as $kod => $u): ?><option value="<?= h((string)$kod) ?>"<?= (string)$kod === $f['alici'] ? ' selected' : '' ?>><?= h($u['ad'] . ' (' . $kod . ')') ?></option><?php endforeach; ?>
          <?php if ($f['alici'] !== '' && !isset($harita[$f['alici']])): ?><option value="<?= h($f['alici']) ?>" selected><?= h($f['alici']) ?></option><?php endif; ?>
        </select></label>
        <?php foreach (['n', 'kg', 'hedef', 'hane'] as $k): if ($ham($k) !== ''): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($ham($k)) ?>"><?php endif; endforeach; ?>
        <button type="submit" class="pr-btn">Uygula</button>
      </form>
      <form method="get" action="siparis_denge.php" class="sc-filtre" id="sdParam">
        <input type="hidden" name="bas" value="<?= h($f['bas']) ?>"><input type="hidden" name="bit" value="<?= h($f['bit']) ?>"><input type="hidden" name="alici" value="<?= h($f['alici']) ?>">
        <label>Fatura sayısı (1–12) <input type="number" name="n" min="1" max="12" step="1" value="<?= h($ham('n')) ?>" placeholder="<?= (int)$param['n'] ?>"></label>
        <label>Fatura başına net kg (boş = ortalama) <input type="text" name="kg" inputmode="numeric" class="sd-kisa" value="<?= h($ham('kg')) ?>" placeholder="<?= h($kgTam((int)$param['kg'])) ?>"></label>
        <label>Beklenen PC fiyatı €/kg (boş = son sevkiyat) <input type="text" name="hedef" inputmode="decimal" class="sd-kisa" value="<?= h($ham('hedef')) ?>" placeholder="<?= h($fiyat((int)$param['hedef'])) ?>"></label>
        <label>Fatura fiyatı hassasiyeti <select name="hane">
          <?php foreach ([2, 3, 4] as $hh): ?><option value="<?= $hh ?>"<?= $hane === $hh ? ' selected' : '' ?>><?= $hh ?> ondalık</option><?php endforeach; ?>
        </select></label>
        <button type="submit" class="pr-btn pr-btn-primary">Hesapla</button>
      </form>
    </div>
    <button type="button" class="pr-btn" data-sc-yazdir>🖨 Yazdır</button>
    <a class="pr-btn" href="siparis_cari.php?<?= h(http_build_query(['bas' => $f['bas'], 'bit' => $f['bit'], 'alici' => $f['alici']])) ?>">📒 Cari Döküm</a>
    <a class="pr-btn" href="siparisler.php">← Siparişler</a>
  </div>
  <?= render_print_header_html('Fiyat Dengeleme', implode(' · ', $f['ozet']) . ($depo !== '' ? ' · Depo: ' . $depo : ''), 'Oluşturma: ' . date('d.m.Y H:i')) ?>
  <p class="pr-note sc-kural">Yalnız EUR sevkiyatları karşılaştırılır. Fark = Fatura − PC: eksi = PC'den düşük faturalandı (alacak eksik kaldı), artı = fazla faturalandı. Kapatılacak tutar için "+" = ileride bu kadar FAZLA faturalanmalı.</p>
  <?php if ($v['kesildi']): ?><p class="pr-note sc-uyari">İlk <?= (int)SIPARIS_CARI_LIMIT ?> sevkiyat okundu (kesildi) — tarih aralığını daraltın.</p><?php endif; ?>
  <?php if ($param['hata']): ?>
  <div class="sd-hata" role="alert">Parametre hatası — varsayılan değer kullanıldı:<ul><?php foreach ($param['hata'] as $m): ?><li><?= h((string)$m) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <!-- 1) Özet -->
  <div class="print-summary-row">
    <div class="print-summary-box"><span class="psb-label">Karşılaştırılan sevkiyat</span><span class="psb-value"><?= (int)$o['adet'] ?></span><span class="sc-alt"><?= h(siparis_cari_kg((int)$o['gram'])) ?> kg</span></div>
    <div class="print-summary-box"><span class="psb-label">Ort. PC fiyatı</span><span class="psb-value"><?= $o['adet'] > 0 ? h($fiyat((int)$o['pc_ort']) . ' EUR / kg') : '—' ?></span><span class="sc-alt">kg ağırlıklı</span></div>
    <div class="print-summary-box"><span class="psb-label">Ort. fatura fiyatı</span><span class="psb-value"><?= $o['adet'] > 0 ? h($fiyat((int)$o['fatura_ort']) . ' EUR / kg') : '—' ?></span><span class="sc-alt">kg ağırlıklı</span></div>
    <div class="print-summary-box <?= $o['adet'] > 0 && (int)$o['fark_ort'] !== 0 ? 'psb-warn' : 'psb-ok' ?>"><span class="psb-label">Fark / kg (fatura − PC)</span><span class="psb-value"><?= $o['adet'] > 0 ? h(siparis_cari_fark_metin((int)$o['fark_ort']) . ' EUR / kg') : '—' ?></span><span class="sc-alt">toplam fark: <?= h($eurI((int)$o['fark_kurus'])) ?></span></div>
    <div class="print-summary-box psb-warn"><span class="psb-label">Kapatılacak tutar</span><span class="psb-value"><?= $o['adet'] > 0 ? h($eurI($kapat)) : '—' ?></span><span class="sc-alt">+ = ileride bu kadar fazla faturalanmalı</span></div>
  </div>

  <!-- 2) Sevkiyat bazında karşılaştırma -->
  <h3 class="pr-section">Sevkiyat bazında karşılaştırma</h3>
  <div class="sc-kaydir"><table class="print-table sc-tablo" id="sdKarsi">
    <thead><tr><th>Yükleme tarihi</th><?= siparis_cari_kimlik_baslik() ?><th class="num">Kg</th><th class="num">PC fiyat / kg</th><th class="num">Fatura fiyat / kg</th><th class="num">Fark / kg</th><th class="num">Fark tutar (EUR)</th><th class="num">Kümülatif fark (EUR)</th></tr></thead>
    <?php if (!$d['sevkiyatlar']): ?>
    <tbody><tr><td colspan="11" class="pr-empty">Karşılaştırılacak sevkiyat yok (fiyatlı + invoice kesilmiş EUR sevkiyat gerekir).</td></tr></tbody>
    <?php else: ?>
    <tbody>
      <?php foreach ($d['sevkiyatlar'] as $s): ?>
      <tr>
        <td><?= h($tarih($s['tarih'])) ?></td><?= siparis_cari_kimlik_html($s) ?>
        <td class="num"><?= h(siparis_cari_kg((int)$s['gram'])) ?></td>
        <td class="num"><?= h($fiyat((int)$s['pc_fiyat'])) ?></td>
        <td class="num"><?= h($fiyat((int)$s['fatura_fiyat'])) ?></td>
        <td class="num<?= $farkSinif((int)$s['fark_fiyat']) ?>"><?= h(siparis_cari_fark_metin((int)$s['fark_fiyat'])) ?></td>
        <td class="num<?= $farkSinif((int)$s['fark_kurus']) ?>"><?= h($isaretli((int)$s['fark_kurus'])) ?></td>
        <td class="num<?= $farkSinif((int)$s['kumulatif_fark_kurus']) ?>"><?= h($isaretli((int)$s['kumulatif_fark_kurus'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tbody class="sc-toplam"><tr>
      <td colspan="5">TOPLAM — <?= (int)$o['adet'] ?> sevkiyat (ağırlıklı ortalama)</td>
      <td class="num"><?= h(siparis_cari_kg((int)$o['gram'])) ?></td>
      <td class="num"><?= h($fiyat((int)$o['pc_ort'])) ?></td>
      <td class="num"><?= h($fiyat((int)$o['fatura_ort'])) ?></td>
      <td class="num<?= $farkSinif((int)$o['fark_ort']) ?>"><?= h(siparis_cari_fark_metin((int)$o['fark_ort'])) ?></td>
      <td class="num<?= $farkSinif((int)$o['fark_kurus']) ?>"><?= h($isaretli((int)$o['fark_kurus'])) ?></td>
      <td class="num<?= $farkSinif((int)$o['fark_kurus']) ?>"><?= h($isaretli((int)$o['fark_kurus'])) ?></td>
    </tr></tbody>
    <?php endif; ?>
  </table></div>

  <!-- 3) Sonraki N fatura planı -->
  <h3 class="pr-section">Sonraki <?= (int)$param['n'] ?> fatura planı</h3>
  <div class="sc-kaydir"><table class="print-table sc-tablo" id="sdPlan">
    <thead><tr><th class="num">Sıra</th><th class="num">Tahmini net kg</th><th class="num">Beklenen PC fiyatı / kg</th><th class="num">Düzeltme (€/kg)</th><th class="num sd-oneri">Önerilen fatura fiyatı / kg</th><th class="num">Fatura tutarı (EUR)</th><th class="num">PC beklenen tutar (EUR)</th><th class="num">Kapanan (EUR)</th><th class="num">Kalan (EUR)</th></tr></thead>
    <?php if (!$d['plan']): ?>
    <tbody><tr><td colspan="9" class="pr-empty">Plan çıkarılamadı — karşılaştırılan sevkiyat, net kg ve beklenen PC fiyatı gerekir (altta nedenler).</td></tr></tbody>
    <?php else: ?>
    <tbody>
      <?php foreach ($d['plan'] as $p): ?>
      <tr>
        <td class="num"><?= (int)$p['sira'] ?></td>
        <td class="num"><?= h(siparis_cari_kg((int)$p['gram'])) ?></td>
        <td class="num"><?= h($fiyat((int)$p['hedef'])) ?></td>
        <td class="num<?= $farkSinif((int)$p['duzeltme_fiyat']) ?>"><?= h(siparis_cari_fark_metin((int)$p['duzeltme_fiyat'])) ?></td>
        <td class="num sd-oneri"><?= h($fiyat((int)$p['fiyat'])) ?></td>
        <td class="num"><?= h(siparis_cari_para((int)$p['tutar_kurus'])) ?></td>
        <td class="num"><?= h(siparis_cari_para((int)$p['hedef_tutar_kurus'])) ?></td>
        <td class="num<?= $farkSinif((int)$p['kapanan_kurus']) ?>"><?= h($isaretli((int)$p['kapanan_kurus'])) ?></td>
        <td class="num"><?= h($isaretli((int)$p['kalan_kurus'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tbody class="sc-toplam"><tr>
      <td colspan="3">PLAN ÖZETİ</td>
      <td class="num<?= $farkSinif((int)$po['duzeltme_ort_fiyat']) ?>"><?= h(siparis_cari_fark_metin((int)$po['duzeltme_ort_fiyat'])) ?><br><small>(<?= h($pctMetin($po['duzeltme_oran_metin'])) ?>)</small></td>
      <td colspan="3"></td>
      <td class="num"><?= h($isaretli((int)$po['toplam_kapanan'])) ?></td>
      <td class="num"><?= h($isaretli((int)$po['kalan'])) ?></td>
    </tr></tbody>
    <?php endif; ?>
  </table></div>
  <?php if ($d['plan']): ?>
  <p class="pr-note">Plan özeti: ortalama düzeltme <?= h(siparis_cari_fark_metin((int)$po['duzeltme_ort_fiyat'])) ?> EUR / kg (hedefe göre <?= h($pctMetin($po['duzeltme_oran_metin'])) ?>) · toplam kapanan <?= h($eurI((int)$po['toplam_kapanan'])) ?> · yuvarlama artığı <?= h($eurI((int)$po['kalan'])) ?> (<?= $hane ?> ondalık fatura fiyatı).</p>
  <?php endif; ?>

  <!-- 4) Uyarılar / notlar -->
  <section class="sc-cari">
    <h3 class="pr-section">Uyarılar ve notlar</h3>
    <ul class="sd-uyari-liste" id="sdUyarilar">
      <?php foreach ($d['uyarilar'] as $u): ?><li class="<?= ($u['seviye'] ?? '') === 'uyari' ? 'sd-u-uyari' : '' ?>"><?= h((string)$u['mesaj']) ?></li><?php endforeach; ?>
      <?php if (!$d['uyarilar']): ?><li>Uyarı yok.</li><?php endif; ?>
    </ul>
    <ul class="sd-notlar">
      <li>Ortalama fiyatlar kg ağırlıklıdır (Σ tutar ÷ Σ kg); sevkiyat fiyatlarının basit ortalaması DEĞİLDİR.</li>
      <li>Fatura fiyatı = Invoice (OGL) ekranında üretilen invoice'taki birim fiyat; PC fiyatı = Cari Döküm'deki satır fiyatı. İkisi de iskonto ÖNCESİDİR, iskonto % karşılaştırmaya katılmaz (iki tarafta aynı oranla uygulandığı varsayılır).</li>
      <li>Kg tabanı Cari Döküm'deki koli × koli kg'dır; invoice'ta elle farklı bir net kg girildiyse (ör. PKL toplamından farklı) gerçek fatura tutarı bundan az farklı olabilir.</li>
      <li>PC fiyatı geldikçe sayfayı yenileyin — plan her seferinde güncel farktan yeniden hesaplanır.</li>
    </ul>
  </section>
</div>
<script>
(function () { var b = document.querySelector('[data-sc-yazdir]'); if (b) b.addEventListener('click', function () { window.print(); }); })();
</script>
<?php render_print_page_end(); ?>
