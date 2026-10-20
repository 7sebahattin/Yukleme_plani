<?php
// =========================================================
// siparis_odemeler.php — OGL ödemeleri: liste + giriş/iptal (native <dialog>) + yalnız-tablo yazdırma (GET; v401)
// Kapı: require_siparis('read') + siparis_cari_yetkili() (görüntüleme). Giriş/iptal düğmeleri yalnız siparis_odeme_yetkili().
// POST'lar siparis_odeme.php'ye (CSRF + kapı orada; PRG buraya döner). Bu dosya FONKSİYON TANIMLAMAZ, veri DEĞİŞTİRMEZ.
// Ad TEK alt çizgili: sw.js bypass kapsar. Satır içi onclick YOK: data-od-* (assets/siparis.js), CSS assets/siparis.css.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/_siparis_ortak.php';
require_once __DIR__ . '/config/siparis_cari.php';
$auth_user = require_login();
require_siparis('read');
if (!siparis_cari_yetkili()) forbidden('Bu sayfaya erişim yetkiniz yok. (Gerekli yetki: reports.export)');
siparis_no_store();

$pdo = db();
$bugun = date('Y-m-d');
$bas = siparis_tarih_coz($_GET['bas'] ?? '') ?? '';
$bit = siparis_tarih_coz($_GET['bit'] ?? '') ?? '';
if ($bas !== '' && $bit !== '' && $bas > $bit) [$bas, $bit] = [$bit, $bas];
$hazir = siparis_odeme_hazir($pdo);
$yaz = $hazir && siparis_odeme_yetkili();
$odemeler = siparis_odeme_oku($pdo, ['bas' => $bas, 'bit' => $bit]);
$toplam = siparis_odeme_toplam($odemeler);
$qs = http_build_query(array_filter(['bas' => $bas, 'bit' => $bit], static fn($x) => $x !== ''));
$aralik = ($bas === '' && $bit === '') ? 'Tüm tarihler' : (($bas !== '' ? sipui_tarih($bas) : '…') . ' – ' . ($bit !== '' ? sipui_tarih($bit) : '…'));
$para = static fn(int $k, string $pb): string => siparis_cari_para($k) . ' ' . $pb;
$toplamMetin = $toplam ? implode(' · ', array_map(static fn($pb) => $para((int)$toplam[$pb], (string)$pb), array_keys($toplam))) : '—';
$sut = $yaz ? 5 : 4;

render_header('OGL ödemeleri');
siparis_assets();
render_flash();
?>
<div class="sip-mod od-sayfa">
  <div class="page-head">
    <div><h1>💳 OGL ödemeleri</h1><p class="muted"><?= count($odemeler) ?> aktif ödeme · <?= h($aralik) ?></p></div>
    <div class="page-head-actions">
      <?php if ($yaz): ?><button type="button" class="btn btn-primary btn-lg" data-od-ac="odEkleDlg">➕ Ödeme gir</button><?php endif; ?>
      <button type="button" class="btn btn-lg" data-od-yazdir>🖨 Ödemeleri yazdır</button>
      <a class="btn btn-lg" href="siparis_cari.php">📒 Cari Döküm</a>
      <a class="btn btn-lg" href="siparisler.php">← Siparişler</a>
    </div>
  </div>
  <?php if (!$hazir): ?><p class="sip-bilgi" role="note">Ödeme tablosu kurulmadı — yönetici migrate.php'den "Sipariş ödemeleri" kartını çalıştırmalı.</p><?php endif; ?>
  <form method="get" action="siparis_odemeler.php" class="od-filtre no-print">
    <label>Başlangıç <input type="date" name="bas" value="<?= h($bas) ?>"></label>
    <label>Bitiş <input type="date" name="bit" value="<?= h($bit) ?>"></label>
    <button type="submit" class="btn">Uygula</button>
    <?php if ($qs !== ''): ?><a class="btn" href="siparis_odemeler.php">Temizle</a><?php endif; ?>
  </form>
  <section id="odYazdirAlan" class="od-alan">
    <div class="od-yazdir-baslik"><h2>OGL Ödemeleri</h2><p>Dönem: <?= h($aralik) ?> · Toplam: <?= h($toplamMetin) ?> · Oluşturma: <?= h(date('d.m.Y H:i')) ?></p></div>
    <p class="od-toplam"><strong>Toplam:</strong> <?= h($toplamMetin) ?> <span class="muted">(para birimleri toplanmaz)</span></p>
    <div class="table-wrap"><table class="data-table od-tablo" id="odTablo">
      <thead><tr><th>Tarih</th><th>Açıklama</th><th>Dekont no</th><th class="num">Tutar</th><?php if ($yaz): ?><th class="od-eylem no-print"></th><?php endif; ?></tr></thead>
      <tbody>
      <?php if (!$odemeler): ?><tr><td colspan="<?= $sut ?>" class="muted">Bu aralıkta ödeme yok.</td></tr><?php endif; ?>
      <?php foreach ($odemeler as $o): $t = $para((int)$o['tutar_kurus'], (string)$o['para_birimi']); ?>
        <tr><td><?= h(sipui_tarih((string)$o['odeme_tarihi'])) ?></td><td><?= h((string)($o['aciklama'] ?? '') !== '' ? (string)$o['aciklama'] : '—') ?></td>
          <td><?= h((string)($o['dekont_no'] ?? '') !== '' ? (string)$o['dekont_no'] : '—') ?></td><td class="num"><?= h($t) ?></td>
          <?php if ($yaz): ?><td class="od-eylem no-print"><button type="button" class="btn btn-sm" data-od-iptal="<?= (int)$o['id'] ?>" data-od-ozet="<?= h(sipui_tarih((string)$o['odeme_tarihi']) . ' · ' . $t) ?>">İptal</button></td><?php endif; ?></tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($toplam): ?><tbody class="od-toplam-satir">
        <?php foreach ($toplam as $pb => $k): ?><tr><td colspan="3"><strong>Toplam (<?= h((string)$pb) ?>)</strong></td><td class="num"><strong><?= h($para((int)$k, (string)$pb)) ?></strong></td><?php if ($yaz): ?><td class="od-eylem no-print"></td><?php endif; ?></tr><?php endforeach; ?>
      </tbody><?php endif; ?>
    </table></div>
  </section>
  <?php if ($yaz): ?>
  <dialog id="odEkleDlg" class="pm-dialog od-dialog" aria-labelledby="odEkleBaslik">
    <form method="post" action="siparis_odeme.php" data-sip-tek>
      <div class="pm-header"><h2 class="pm-title" id="odEkleBaslik">Ödeme gir</h2><button type="button" class="pm-close" data-od-kapat aria-label="Kapat">✕</button></div>
      <div class="pm-body sip-form">
        <?= sipui_csrf() ?><input type="hidden" name="islem" value="ekle"><input type="hidden" name="qs" value="<?= h($qs) ?>">
        <input type="hidden" name="istek_id" value="<?= h(bin2hex(random_bytes(10))) ?>">
        <label>Ödeme tarihi<input type="date" name="tarih" value="<?= h($bugun) ?>" max="<?= h($bugun) ?>" required></label>
        <label>Tutar<input type="text" name="tutar" inputmode="decimal" placeholder="1.234,56" required autocomplete="off"></label>
        <label>Para birimi<select name="para_birimi" required><?php foreach (siparis_odeme_para_birimleri() as $pb): ?><option><?= h($pb) ?></option><?php endforeach; ?></select></label>
        <label>Açıklama<input type="text" name="aciklama" maxlength="255"></label>
        <label>Dekont no<input type="text" name="dekont_no" maxlength="60"></label>
      </div>
      <div class="pm-footer"><button type="button" class="btn" data-od-kapat>Vazgeç</button><button type="submit" class="btn btn-primary">Kaydet</button></div>
    </form>
  </dialog>
  <dialog id="odIptalDlg" class="pm-dialog od-dialog" aria-labelledby="odIptalBaslik">
    <form method="post" action="siparis_odeme.php" data-sip-tek>
      <div class="pm-header"><h2 class="pm-title" id="odIptalBaslik">Ödemeyi iptal et</h2><button type="button" class="pm-close" data-od-kapat aria-label="Kapat">✕</button></div>
      <div class="pm-body sip-form">
        <?= sipui_csrf() ?><input type="hidden" name="islem" value="iptal"><input type="hidden" name="id" value="0"><input type="hidden" name="qs" value="<?= h($qs) ?>">
        <p class="muted" data-od-ozet-yazi></p>
        <label>İptal gerekçesi (zorunlu)<input type="text" name="neden" required maxlength="255"></label>
        <p class="muted">Satır silinmez; iptal edilen ödeme Tahsilat'tan düşer.</p>
      </div>
      <div class="pm-footer"><button type="button" class="btn" data-od-kapat>Vazgeç</button><button type="submit" class="btn btn-danger">İptal et</button></div>
    </form>
  </dialog>
  <?php endif; ?>
</div>
<?php
siparis_scripts();
render_footer();
