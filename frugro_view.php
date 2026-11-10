<?php
// =========================================================
// frugro_view.php?id= — Frugro Sipariş detayı: bilgi kartları, Düzenle penceresi (native <dialog>), PKL / Invoice bağlantıları,
// durum (kapat / iptal / yeniden aç), olay zaman çizelgesi, silme (iki adımlı).
// Gösterim: siparis.read · düzenle/durum: siparis.write · sil: siparis.delete (hepsi OGL ile aynı kapı kuralı; frgui_yetki).
// Yazan uç: frugro_kaydet.php. Başka depoya ait / olmayan kayıt AYNI 404'ü verir.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/frugro_core.php';
require_once __DIR__ . '/config/frugro_ui.php';
$auth_user = require_login();
require_siparis('read');
siparis_no_store();
$pdo   = db();
$hazir = frg_hazir($pdo);

render_header('Frugro Sipariş');
echo '<link rel="stylesheet" href="assets/frugro.css?v=' . (int)@filemtime(__DIR__ . '/assets/frugro.css') . '">' . "\n";
render_flash();
if (!$hazir) {
    echo '<div class="frg"><div class="card frg-bos"><h2>Frugro Siparişler henüz kurulmadı</h2><p class="muted">'
        . (is_admin() ? 'Şema Migrasyon Paneli › <a href="migrate.php#frugro">Frugro Siparişler</a> kartı.' : 'Yönetici şema kurulumunu tamamlayınca bu sayfa açılır.')
        . '</p></div></div>';
    render_footer();
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$s  = $id > 0 ? frg_getir($pdo, $id) : null;
if ($s === null || !frg_gorunur($s)) {
    http_response_code(404);
    echo '<div class="frg"><div class="card frg-bos"><h2>Sipariş bulunamadı</h2><p><a class="btn" href="frugro_siparis.php">← Frugro Siparişler</a></p></div></div>';
    render_footer();
    exit;
}

$duzenle = frgui_yetki('edit');
$sil     = frgui_yetki('delete');
$durum   = (string)$s['durum'];
$kilitli = in_array($durum, ['kapali', 'iptal'], true);
$hs      = frg_hesap($s);
$bugun   = date('Y-m-d');
$olaylar = frg_olaylar($pdo, $id, 100);
$kullanici = [];
try {
    $uids = array_values(array_unique(array_filter(array_map(static fn($o) => (int)($o['created_by'] ?? 0), $olaylar))));
    if ($uids) {
        $st = $pdo->prepare('SELECT id, display_name FROM users WHERE id IN (' . implode(',', array_fill(0, count($uids), '?')) . ')');
        $st->execute($uids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) $kullanici[(int)$u['id']] = (string)$u['display_name'];
    }
} catch (Throwable $e) { /* ad çözülemezse yalnız ad gösterilmez */ }

$v = static fn(string $k): string => trim((string)($s[$k] ?? ''));
$satir = static function (string $et, string $deger, bool $html = false): string {
    $bos = $deger === '';
    return '<div class="frg-bilgi"><dt>' . h($et) . '</dt><dd' . ($bos ? ' class="frg-soluk"' : '') . '>' . ($bos ? '—' : ($html ? $deger : h($deger))) . '</dd></div>';
};
$ilerleme = ['yeni', 'pkl_hazir', 'invoice_hazir', 'yola_cikti', 'kapali'];
$konum = array_search($durum, $ilerleme, true);

// Düzenle penceresi grupları (alan adları frgui_duzenle_alanlari() beyaz listesinden)
$gruplar = [
    'Sipariş' => ['cikis_tarihi', 'varis_tarihi', 'varis_saati', 'ihracatci', 'paketlemeci'],
    'Teslimat' => ['giris_limani', 'teslim_adresi', 'son_musteri', 'son_musteri_po', 'incoterm'],
    'Ürün' => ['urun', 'cesit', 'mense', 'boy', 'fruit_agirlik_notu', 'dis_paket', 'ic_paket', 'palet_tipi'],
    'Miktar ve fiyat' => ['kutu', 'kutu_palet', 'palet', 'fiyat_kutu_birim', 'para_birimi'],
    'Sevkiyat' => ['parti_no', 'plaka', 'sofor_adi', 'sofor_telefon', 'nakliyeci'],
];
$tanim = frgui_duzenle_alanlari();
$alanDeger = static function (string $a) use ($s): string {
    $x = $s[$a] ?? null;
    if ($a === 'fiyat_kutu_birim') return $x === null || $x === '' ? '' : frg_fiyat_metin((int)$x);
    return $x === null ? '' : (string)$x;
};
?>
<div class="frg" id="frg" data-csrf="<?= h(csrf_token()) ?>" data-id="<?= (int)$id ?>" data-durum="<?= h($durum) ?>" data-po="<?= h($s['po_no']) ?>">
  <div class="page-head">
    <div>
      <h1>🍇 <?= h($s['po_no']) ?> <?= frgui_durum_rozet($durum) ?></h1>
      <div class="muted"><?= h(implode(' · ', array_filter([$v('son_musteri'), $v('urun'), $v('giris_limani')], static fn($x) => $x !== ''))) ?></div>
    </div>
    <div class="page-head-actions no-print">
      <a class="btn" href="frugro_siparis.php">← Frugro Siparişler</a>
      <?php if ($duzenle && !$kilitli): ?><button type="button" class="btn btn-primary" id="frgDuzenleAc">✏ Düzenle</button><?php endif; ?>
    </div>
  </div>

  <div class="frg-hata" id="frgHata" role="alert" hidden></div>

<?php if ($durum !== 'iptal'): ?>
  <ol class="frg-adim" aria-label="Sipariş ilerlemesi">
    <?php foreach ($ilerleme as $i => $k): $on = $konum !== false && $i <= $konum; ?>
    <li class="frg-adim-ogesi<?= $on ? ' on' : '' ?><?= $k === $durum ? ' simdi' : '' ?>"><?= h(frg_durumlar()[$k]) ?></li>
    <?php endforeach; ?>
  </ol>
<?php else: ?>
  <div class="card frg-iptal-not" role="status">✖ Bu sipariş <b>iptal</b> edildi.</div>
<?php endif; ?>

  <div class="frg-izgara">
    <section class="card frg-bolum" aria-labelledby="frgBSip">
      <h2 id="frgBSip">Sipariş</h2>
      <dl class="frg-bilgiler">
        <?= $satir('PO no', $v('po_no')) ?>
        <?= $satir('Çıkış tarihi', $v('cikis_tarihi') === '' ? '' : frgui_tarih_html($v('cikis_tarihi'), $bugun), true) ?>
        <?= $satir('Varış tarihi', $v('varis_tarihi') === '' ? '' : frgui_tarih_html($v('varis_tarihi'), $bugun), true) ?>
        <?= $satir('Varış saati', $v('varis_saati')) ?>
        <?= $satir('İhracatçı', $v('ihracatci')) ?>
        <?= $satir('Paketlemeci', $v('paketlemeci')) ?>
      </dl>
    </section>
    <section class="card frg-bolum" aria-labelledby="frgBTes">
      <h2 id="frgBTes">Teslimat</h2>
      <dl class="frg-bilgiler">
        <?= $satir('Giriş limanı', $v('giris_limani')) ?>
        <?= $satir('Teslim adresi', $v('teslim_adresi')) ?>
        <?= $satir('Son müşteri', $v('son_musteri')) ?>
        <?= $satir('Müşteri PO', $v('son_musteri_po')) ?>
        <?= $satir('Incoterm', $v('incoterm')) ?>
      </dl>
    </section>
    <section class="card frg-bolum" aria-labelledby="frgBUrun">
      <h2 id="frgBUrun">Ürün ve paketleme</h2>
      <dl class="frg-bilgiler">
        <?= $satir('Ürün', $v('urun')) ?>
        <?= $satir('Çeşit', $v('cesit')) ?>
        <?= $satir('Menşe', $v('mense')) ?>
        <?= $satir('Boy', $v('boy')) ?>
        <?= $satir('Meyve ağırlık notu', $v('fruit_agirlik_notu')) ?>
        <?= $satir('Dış paket', $v('dis_paket')) ?>
        <?= $satir('İç paket', $v('ic_paket')) ?>
        <?= $satir('Palet tipi', $v('palet_tipi')) ?>
      </dl>
    </section>
    <section class="card frg-bolum" aria-labelledby="frgBMik">
      <h2 id="frgBMik">Miktar ve tutar</h2>
      <dl class="frg-bilgiler">
        <?= $satir('Kutu', frgui_tam($hs['kutu'])) ?>
        <?= $satir('Kutu / palet', $s['kutu_palet'] === null ? '' : frgui_tam($s['kutu_palet'])) ?>
        <?= $satir('Palet', frgui_tam($hs['palet'])) ?>
        <?= $satir('Fiyat (kutu başı)', $s['fiyat_kutu_birim'] === null ? '' : frg_fiyat_metin((int)$s['fiyat_kutu_birim']) . ($v('para_birimi') !== '' ? ' ' . $v('para_birimi') : '')) ?>
        <?= $satir('Tutar', $hs['fiyat_var'] ? frgui_tutar_html($s) : '', true) ?>
      </dl>
    </section>
    <section class="card frg-bolum" aria-labelledby="frgBSev">
      <h2 id="frgBSev">Sevkiyat</h2>
      <dl class="frg-bilgiler">
        <?= $satir('Parti no', $v('parti_no')) ?>
        <?= $satir('Plaka', $v('plaka')) ?>
        <?= $satir('Şoför', $v('sofor_adi')) ?>
        <?= $satir('Şoför telefonu', $v('sofor_telefon')) ?>
        <?= $satir('Nakliyeci', $v('nakliyeci')) ?>
        <?= $satir('Fatura no', $v('fatura_no')) ?>
      </dl>
    </section>
    <section class="card frg-bolum" aria-labelledby="frgBKay">
      <h2 id="frgBKay">Kayıt</h2>
      <dl class="frg-bilgiler">
        <?= $satir('Kaynak', ['mail' => 'Mail', 'yukleme' => 'Excel yükleme', 'elle' => 'Elle'][$v('kaynak')] ?? $v('kaynak')) ?>
        <?= $satir('Depo', $v('depo')) ?>
        <?= $satir('Oluşturulma', $v('created_at') === '' ? '' : date('d.m.Y H:i', (int)strtotime($v('created_at')))) ?>
        <?= $satir('Son güncelleme', $v('updated_at') === '' ? '' : date('d.m.Y H:i', (int)strtotime($v('updated_at')))) ?>
      </dl>
    </section>
  </div>

  <div class="frg-baglar">
    <a class="card frg-bag" href="frugro_pkl.php?id=<?= (int)$id ?>">
      <span class="frg-bag-ikon" aria-hidden="true">📦</span>
      <span><b>Packing List</b><small><?= $s['paletler'] ? count($s['paletler']) . ' palet satırı' : 'Henüz hazırlanmadı' ?></small></span>
    </a>
    <a class="card frg-bag" href="frugro_invoice.php?id=<?= (int)$id ?>">
      <span class="frg-bag-ikon" aria-hidden="true">🧾</span>
      <span><b>Invoice</b><small><?= $v('fatura_no') !== '' ? h('Fatura no: ' . $v('fatura_no')) : 'Henüz hazırlanmadı' ?></small></span>
    </a>
  </div>

<?php if ($duzenle || $sil): ?>
  <section class="card frg-bolum frg-eylemler no-print" aria-labelledby="frgBEy">
    <h2 id="frgBEy">İşlemler</h2>
    <div class="frg-eylem-satir">
      <?php if ($duzenle && !$kilitli): ?>
        <button type="button" class="btn" data-frg-durum="kapali">✔ Siparişi kapat</button>
        <button type="button" class="btn btn-danger" id="frgIptalAc">✖ İptal et</button>
      <?php elseif ($duzenle && $kilitli): ?>
        <button type="button" class="btn" data-frg-durum="yeniden_ac">↺ Yeniden aç</button>
      <?php endif; ?>
      <?php if ($sil): ?>
        <span class="frg-sil" id="frgSilAlan">
          <button type="button" class="btn btn-danger" id="frgSilIlk">🗑 Sil…</button>
          <span class="frg-sil-onay" id="frgSilOnay" hidden>
            <span>Bu siparişi silmek istediğinize emin misiniz?</span>
            <button type="button" class="btn btn-danger" id="frgSilEvet">Evet, sil</button>
            <button type="button" class="btn" id="frgSilHayir">Vazgeç</button>
          </span>
        </span>
      <?php endif; ?>
    </div>
  </section>
<?php endif; ?>

  <section class="card frg-bolum" aria-labelledby="frgBOl">
    <h2 id="frgBOl">Geçmiş</h2>
<?php if (!$olaylar): ?>
    <p class="frg-soluk">Henüz olay yok.</p>
<?php else: ?>
    <ol class="frg-zaman">
      <?php foreach ($olaylar as $o): $ad = $kullanici[(int)($o['created_by'] ?? 0)] ?? ''; ?>
      <li class="frg-olay frg-olay--<?= h(preg_replace('/[^a-z_]/', '', (string)$o['tur'])) ?>">
        <div class="frg-olay-ust"><time><?= h(date('d.m.Y H:i', (int)strtotime((string)$o['created_at']))) ?></time><?= $ad !== '' ? ' · ' . h($ad) : '' ?></div>
        <div class="frg-olay-metin"><?= h((string)$o['metin']) ?></div>
      </li>
      <?php endforeach; ?>
    </ol>
<?php endif; ?>
  </section>

<?php if ($duzenle && !$kilitli): ?>
  <dialog class="pm-dialog frg-dialog" id="frgDialog" aria-labelledby="frgDlBaslik">
    <div class="pm-header">
      <h2 class="pm-title" id="frgDlBaslik">Siparişi düzenle · <?= h($s['po_no']) ?></h2>
      <button type="button" class="pm-close" data-frg-kapat aria-label="Kapat">✕</button>
    </div>
    <form id="frgForm" autocomplete="off" novalidate>
      <div class="pm-body">
        <div class="frg-hata" id="frgFormHata" role="alert" hidden></div>
        <?php foreach ($gruplar as $baslik => $alanlar): ?>
        <fieldset class="frg-fs">
          <legend><?= h($baslik) ?></legend>
          <div class="frg-grid">
          <?php foreach ($alanlar as $a): [$tur, $et, $max] = $tanim[$a]; $val = $alanDeger($a); ?>
            <div class="frg-f<?= $tur === 'uzun' ? ' frg-tam' : '' ?>">
              <label for="f_<?= h($a) ?>"><?= h($et) ?></label>
              <?php if ($tur === 'uzun'): ?>
                <textarea id="f_<?= h($a) ?>" name="<?= h($a) ?>" maxlength="<?= (int)$max ?>" rows="2"><?= h($val) ?></textarea>
              <?php elseif ($tur === 'pb'): ?>
                <select id="f_<?= h($a) ?>" name="<?= h($a) ?>">
                  <option value="">Seçilmedi</option>
                  <?php foreach (['GBP', 'EUR'] as $pb): ?><option value="<?= h($pb) ?>"<?= $val === $pb ? ' selected' : '' ?>><?= h($pb) ?></option><?php endforeach; ?>
                </select>
              <?php else: ?>
                <input id="f_<?= h($a) ?>" name="<?= h($a) ?>" value="<?= h($val) ?>"
                  <?= $tur === 'tarih' ? 'type="date"' : 'type="text"' ?>
                  <?= $tur === 'tam' ? 'inputmode="numeric"' : ($tur === 'fiyat' ? 'inputmode="decimal"' : '') ?>
                  <?= $max > 0 ? 'maxlength="' . (int)$max . '"' : '' ?>>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          </div>
        </fieldset>
        <?php endforeach; ?>
      </div>
      <div class="pm-footer">
        <button type="button" class="btn" data-frg-kapat>Vazgeç</button>
        <button type="submit" class="btn btn-primary" id="frgKaydet">Kaydet</button>
      </div>
    </form>
  </dialog>

  <dialog class="pm-dialog frg-dialog frg-dialog--dar" id="frgIptalDialog" aria-labelledby="frgIpBaslik">
    <div class="pm-header">
      <h2 class="pm-title" id="frgIpBaslik">Siparişi iptal et</h2>
      <button type="button" class="pm-close" data-frg-kapat aria-label="Kapat">✕</button>
    </div>
    <form id="frgIptalForm" novalidate>
      <div class="pm-body">
        <div class="frg-hata" id="frgIptalHata" role="alert" hidden></div>
        <div class="frg-f"><label for="frgGerekce">İptal gerekçesi <span class="frg-zorunlu">(zorunlu)</span></label>
          <textarea id="frgGerekce" maxlength="500" rows="3"></textarea></div>
      </div>
      <div class="pm-footer">
        <button type="button" class="btn" data-frg-kapat>Vazgeç</button>
        <button type="submit" class="btn btn-danger">İptal et</button>
      </div>
    </form>
  </dialog>
<?php endif; ?>
</div>
<script src="assets/frugro.js?v=<?= (int)@filemtime(__DIR__ . '/assets/frugro.js') ?>" defer></script>
<?php render_footer();
