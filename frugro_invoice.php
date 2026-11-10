<?php
// =========================================================
// frugro_invoice.php?id= — Frugro INVOICE hazırlama (sipariş id)
//
// GET: fatura bilgileri formu + PKL'den salt okunur özet + kontrol listesi + üretilmiş PDF'in indirme/önizlemesi (?pdf=1[&dl=1]).
//      Alt bölüm "Fatura ayarları" (banka / IBAN / VAT) YALNIZ siparis.delete görür. Okuma: siparis.read.
// POST (siparis.write, CSRF, PRG): kaydet · kontrol · uret · ayar (siparis.delete). Mantık config/frugro_invoice.php (frgi_*).
// PDF diske KAYDEDİLMEZ: ?pdf=1 her istekte kayıtlı girdiden taze üretir. Bu dosya FONKSİYON TANIMLAMAZ.
// Ad TEK alt çizgili: sw.js bypass regex'i kapsar (/frugro_*.php önbelleğe girmez). Satır içi onclick YOK: data-frgi-*.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/siparis_eslestir.php';
require_once __DIR__ . '/config/frugro_invoice.php';
require_once __DIR__ . '/_siparis_ortak.php';
$auth_user = require_login();
require_siparis('read');
siparis_no_store();
$pdo = db();
$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$uid = (int)$auth_user['id'];

if (!frg_hazir($pdo)) {
    render_header('Frugro Invoice');
    siparis_assets();
    echo '<div class="sip-mod"><div class="card sip-kurulmadi" role="status"><h2>Frugro Siparişler henüz kurulmadı</h2><p class="muted">Veritabanı tabloları oluşturulmadan bu ekran kullanılamaz.</p>'
        . (function_exists('is_admin') && is_admin() ? '<p><a class="btn btn-primary" href="migrate.php#frugro">Şema panelinde kur</a></p>' : '') . '</div></div>';
    render_footer();
    exit;
}
$s = frg_getir($pdo, $id);
if ($s === null || !frg_gorunur($s)) {                       // başka depodaki kayıt 404 ile AYNI (varlığı sızdırmaz)
    http_response_code(404);
    render_header('Sipariş bulunamadı');
    siparis_assets();
    echo '<div class="sip-mod"><div class="empty"><h2>Sipariş bulunamadı</h2><p class="muted">Kayıt bulunamadı ya da silinmiş olabilir.</p><p><a class="btn btn-primary" href="index.php">← Ana sayfa</a></p></div></div>';
    render_footer();
    exit;
}
$yaz    = can_siparis('write') && frgi_yazilabilir($s);
$ayarYk = can_siparis('delete');                            // Fatura ayarları: yalnız siparis.delete
$buSayfa = 'frugro_invoice.php?id=' . $id;
$git = function (string $parca = '') use ($buSayfa): void { header('Location: ' . $buSayfa . $parca); exit; };
$GIRDI_ALANLARI = ['no', 'tarih', 'para_birimi', 'fiyat', 'kutu', 'palet', 'net_kg', 'brut_kg', 'incoterm', 'plaka', 'aciklama', 'agirlik_onay'];
$AYAR_ALANLARI = array_keys(frgi_ayar_alanlari());

// ── PDF (GET ?pdf=1): kayıtlı girdiden taze üretim ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['pdf'])) {
    try {
        $p = frgi_pdf_uret($pdo, $id);
    } catch (Throwable $e) {
        error_log('[frugro_invoice pdf] ' . get_class($e));
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'PDF üretilemedi.';
        exit;
    }
    if ($p === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bu sipariş için henüz fatura üretilmedi.';
        exit;
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $p['dosya_adi'] . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($p['pdf']));
    echo $p['pdf'];
    exit;
}

// ── POST: kaydet · kontrol · uret · ayar (hepsi PRG) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $islem = (string)($_POST['islem'] ?? '');
    if ($islem === 'ayar') {
        if (!$ayarYk) forbidden('Fatura ayarlarını yalnız silme yetkisi olan kullanıcı değiştirebilir.');
        $girdi = [];
        foreach ($AYAR_ALANLARI as $k) if (array_key_exists($k, $_POST)) $girdi[$k] = $_POST[$k];
        $r = frgi_ayar_kaydet($pdo, $girdi, $uid);
        set_flash(!empty($r['ok']) ? 'success' : 'error', !empty($r['ok'])
            ? (empty($r['degisen']) ? 'Fatura ayarında değişiklik yok.' : 'Fatura ayarı kaydedildi (' . count((array)$r['degisen']) . ' alan).')
            : (string)($r['hata'] ?? 'Ayar kaydedilemedi.'));
        $git('#ayar');
    }
    require_siparis('write');
    if (!frgi_yazilabilir($s)) forbidden('Bu siparişin durumu fatura işlemine kapalı.');
    $gonderilen = [];
    foreach ($GIRDI_ALANLARI as $k) $gonderilen[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, 120, 'UTF-8');
    $izin = ($_POST['eksik_izin'] ?? '') === '1';
    $formSakla = function (array $deger, array $hata = []) use ($id, $izin): void {
        $_SESSION['frugro_invoice_form'] = [$id => ['deger' => $deger, 'hata' => $hata, 'izin' => $izin]];
    };
    if ($islem === 'kontrol') { $formSakla($gonderilen); $git('#kontrol'); }
    if ($islem === 'kaydet') {
        $r = frgi_girdi_kaydet($pdo, $id, $uid, $gonderilen);
        if (empty($r['ok'])) { $formSakla($gonderilen, (array)($r['hatalar'] ?? [])); set_flash('error', (string)($r['hata'] ?? 'Kaydedilemedi.')); $git('#bilgiler'); }
        unset($_SESSION['frugro_invoice_form']);
        set_flash('success', 'Fatura bilgileri kaydedildi.');
        $git('#bilgiler');
    }
    if ($islem === 'uret') {
        $r = frgi_uret($pdo, $id, $uid, $gonderilen, $izin);
        if (!empty($r['ok'])) {
            unset($_SESSION['frugro_invoice_form']);
            $uy = count(array_filter((array)($r['uyarilar'] ?? []), static fn($x) => ($x['seviye'] ?? '') === 'uyari'));
            set_flash('success', 'Fatura oluşturuldu: ' . (string)($r['dosya_adi'] ?? '') . ($uy > 0 ? ' (' . $uy . ' uyarı ile — kontrol listesine bakın)' : '') . '. Aşağıdan indirebilirsiniz.');
            $git('#uretim');
        }
        $formSakla($gonderilen);
        set_flash('error', 'Fatura oluşturulamadı: ' . (string)($r['hata'] ?? 'bilinmeyen hata'));
        $git('#kontrol');
    }
    set_flash('error', 'Geçersiz işlem.');
    $git();
}

// ── GET: görüntü durumu ──────────────────────────────────────────────────────
$vs     = frgi_varsayilan($pdo, $s);
$ozet   = (array)$vs['ozet'];
$ayar   = frgi_ayar_etkin($pdo);
$kayitli = frgi_girdi_oku($pdo, $id);
$form   = $_SESSION['frugro_invoice_form'][$id] ?? null;      // başarısız gönderim / kontrol: tek kullanımlık
unset($_SESSION['frugro_invoice_form']);
$deger  = (array)$vs['girdi'];
if (is_array($form)) foreach ($GIRDI_ALANLARI as $k) if (array_key_exists($k, (array)$form['deger'])) $deger[$k] = (string)$form['deger'][$k];
$deger['tarih'] = (string)(siparis_tarih_coz($deger['tarih']) ?? $deger['tarih']);
$hataMap = is_array($form) ? (array)($form['hata'] ?? []) : [];
$izinOnceki = is_array($form) && !empty($form['izin']);
$tK = frgi_girdi_temizle($deger);
if (is_array($form)) $hataMap += (array)$tK['hata'];
$kontrol = frgi_kontrol($pdo, $s, $deger, $ozet, $ayar);
$hataTum  = array_values(array_filter($kontrol, static fn($x) => $x['seviye'] === 'hata'));
$girdiHata = array_values(array_filter($hataTum, static fn($x) => str_starts_with((string)$x['kod'], 'girdi_')));
$engeller = array_values(array_filter($hataTum, static fn($x) => !str_starts_with((string)$x['kod'], 'girdi_')));
$uyarilar = array_values(array_filter($kontrol, static fn($x) => $x['seviye'] === 'uyari'));
$bilgiler = array_values(array_filter($kontrol, static fn($x) => $x['seviye'] === 'bilgi'));
$hes  = $tK['ok'] ? frgi_hesapla((array)$tK['veri'], $ozet) : null;
$etkin = frgi_etkin((array)$tK['veri'], $ozet);
$pbSec = (string)$tK['veri']['para_birimi'];
$sem  = $pbSec !== '' ? frgi_para_birimleri()[$pbSec]['sembol'] : '';
$ayarEksik = frgi_ayar_eksikler($ayar, $pbSec !== '' ? $pbSec : 'GBP');
$var  = !empty($kayitli['uretildi_at']) && is_array($kayitli['snap'] ?? null);
$para = static fn(int $kurus): string => number_format($kurus / 100, 2, ',', '.');
$kgG  = static fn(int|float $x): string => $x > 0 ? number_format((float)$x, 0, ',', '.') : '—';
$ayarHam = $ayarYk ? frgi_ayar_ham($pdo) : [];

render_header('Frugro Invoice ' . (string)$s['po_no']);
siparis_assets();
echo '<link rel="stylesheet" href="assets/frugro_invoice.css?v=' . (int)@filemtime(__DIR__ . '/assets/frugro_invoice.css') . '">' . "\n";
render_flash();
?>
<div class="sip-mod frgi-sayfa">
  <div class="page-head">
    <div>
      <a class="btn btn-geri" href="index.php">← Ana sayfa</a>
      <h1 class="sip-h1">Frugro Invoice <span class="sip-pkl-durum frgi-durum--<?= $var ? 'hazir' : 'yok' ?>"><?= $var ? 'Hazır' : 'Yok' ?></span></h1>
      <p class="muted sip-alt-satir">PO <?= h((string)$s['po_no']) ?> · <?= h((string)($s['son_musteri'] ?? '') !== '' ? (string)$s['son_musteri'] : 'Müşteri belirtilmemiş') ?> · <?= h(frg_durumlar()[(string)$s['durum']] ?? (string)$s['durum']) ?></p>
    </div>
  </div>
  <?php if (!$yaz): ?><p class="sip-bilgi" role="note">Salt okunur görünüm: fatura hazırlamak için siparis.write yetkisi gerekir<?= frgi_yazilabilir($s) ? '' : ' (sipariş durumu fatura işlemine kapalı)' ?>.</p><?php endif; ?>

  <form method="post" action="<?= h($buSayfa) ?>" class="frgi-form" id="frgiForm" data-frgi
        data-frgi-kutu="<?= (int)$ozet['kutu'] ?>" data-frgi-net="<?= (int)$ozet['net_pkl_kg'] ?>" data-frgi-brut="<?= (int)$ozet['brut_pkl_kg'] ?>">
    <?= sipui_csrf() ?><input type="hidden" name="id" value="<?= $id ?>">
    <fieldset class="sip-fieldset"<?= $yaz ? '' : ' disabled' ?>>

      <section class="card" id="bilgiler" aria-labelledby="frgiBilBas">
        <div class="card-head"><h2 id="frgiBilBas">1. Fatura bilgileri</h2></div>
        <div class="sip-izgara">
          <?php
          $pklNet = $kgG((int)$ozet['net_pkl_kg']); $pklBrut = $kgG((int)$ozet['brut_pkl_kg']);
          $alanlar = [
              'no' => ['Fatura no', 'text', 'IHR…', ' autocomplete="off" maxlength="40" required', ''],
              'tarih' => ['Fatura tarihi', 'date', '', ' required', ''],
              'fiyat' => ['Kutu başına fiyat', 'text', '5,90', ' inputmode="decimal" autocomplete="off" maxlength="14" data-frgi-giris="fiyat" required',
                  $ozet['fiyat_siparis_1e4'] !== null ? 'Siparişteki fiyat: ' . frg_fiyat_metin((int)$ozet['fiyat_siparis_1e4']) : 'Siparişte fiyat yok'],
              'kutu' => ['Kutu adedi', 'text', (string)$ozet['kutu'], ' inputmode="numeric" autocomplete="off" maxlength="9" data-frgi-giris="kutu"', 'Boş = ' . $kgG((int)$ozet['kutu']) . ($ozet['pkl_var'] ? ' (PKL toplamı)' : ' (sipariş)')],
              'palet' => ['Palet adedi', 'text', (string)$ozet['palet'], ' inputmode="numeric" autocomplete="off" maxlength="5"', 'Boş = ' . $kgG((int)$ozet['palet']) . ($ozet['pkl_var'] ? ' (PKL satır sayısı)' : ' (sipariş)')],
              'net_kg' => ['Net ağırlık (kg)', 'text', $pklNet, ' inputmode="numeric" autocomplete="off" maxlength="9" data-frgi-giris="net"', 'PKL: ' . $pklNet . ' — boş = PKL toplamı (Frugro şartı: tüm evraklarda aynı)'],
              'brut_kg' => ['Brüt ağırlık (kg)', 'text', $pklBrut, ' inputmode="numeric" autocomplete="off" maxlength="9" data-frgi-giris="brut"', 'PKL: ' . $pklBrut . ' — boş = PKL toplamı'],
              'incoterm' => ['Teslim şekli (Incoterm)', 'text', (string)$ozet['incoterm'], ' autocomplete="off" maxlength="3" list="frgiIncoList"', 'Boş = siparişteki: ' . ($ozet['incoterm'] !== '' ? $ozet['incoterm'] : '—')],
              'plaka' => ['Plaka (TRUCK / TIR)', 'text', (string)$ozet['plaka'], ' autocomplete="off" maxlength="80"', 'Boş = siparişteki plaka'],
              'aciklama' => ['Mal tanımı', 'text', (string)$ozet['aciklama'], ' autocomplete="off" maxlength="120"', 'Boş = otomatik (ürün + kalibre)'],
          ];
          ?>
          <label>Para birimi
            <select name="para_birimi" required data-frgi-giris="pb"<?= isset($hataMap['para_birimi']) ? ' aria-invalid="true"' : '' ?>>
              <option value="">— seçin —</option>
              <?php foreach (frgi_para_birimleri() as $kod => $pbb): ?><option value="<?= h($kod) ?>" data-frgi-sembol="<?= h($pbb['sembol']) ?>"<?= $deger['para_birimi'] === $kod ? ' selected' : '' ?>><?= h($kod . ' (' . $pbb['sembol'] . ')') ?></option><?php endforeach; ?>
            </select>
            <span class="sip-ipucu">Yalnız GBP ya da EUR (Frugro şartı). Başlıkta POUND / EURO basılır.</span>
            <?php if (isset($hataMap['para_birimi'])): ?><span class="sip-hata-metin" role="alert"><?= h((string)$hataMap['para_birimi']) ?></span><?php endif; ?>
          </label>
          <?php foreach ($alanlar as $k => [$et, $tip, $ph, $ek, $ipucu]): ?>
            <label><?= h($et) ?>
              <input type="<?= $tip ?>" name="<?= $k ?>" value="<?= h((string)$deger[$k]) ?>"<?= $ph !== '' ? ' placeholder="' . h($ph) . '"' : '' ?><?= $ek ?><?= isset($hataMap[$k]) ? ' aria-invalid="true"' : '' ?>>
              <?php if ($ipucu !== ''): ?><span class="sip-ipucu"><?= h($ipucu) ?></span><?php endif; ?>
              <?php if (isset($hataMap[$k])): ?><span class="sip-hata-metin" role="alert"><?= h((string)$hataMap[$k]) ?></span><?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
        <datalist id="frgiIncoList"><?php foreach (FRGI_INCOTERMLER as $ic): ?><option value="<?= h($ic) ?>"><?php endforeach; ?></datalist>
        <label class="sip-onay-satir frgi-agirlik-onay"><input type="checkbox" name="agirlik_onay" value="1"<?= $deger['agirlik_onay'] === '1' ? ' checked' : '' ?>> <span>PKL'den farklı net / brüt ağırlığı onaylıyorum (yalnız elle farklı girdiyseniz gerekir)</span></label>
        <p class="sip-ipucu">Ondalık ayırıcı virgüldür (5,90). Tutar = kutu × kutu fiyatı; sunucuda yeniden hesaplanır, aşağıdaki sonuç yalnız önizlemedir. İskonto yoktur.</p>

        <div class="sip-inv-sonuc" data-frgi-sonuc aria-live="polite">
          <div><span class="sip-soluk">Kutu × fiyat</span><span class="sip-inv-deger"><span data-frgi-sembol-goster><?= h($sem) ?></span> <strong data-frgi-toplam><?= $hes ? h($para((int)$hes['toplam_kurus'])) : '—' ?></strong></span></div>
          <div class="sip-inv-fark" data-frgi-fark<?= ($etkin['net_farkli'] || $etkin['brut_farkli']) ? '' : ' hidden' ?>>PKL ile farklı ağırlık — onay kutusu gerekir.</div>
        </div>

        <h3 class="sip-grup-bas">Özet (salt okunur — <?= $ozet['pkl_var'] ? 'PKL / palet satırları' : 'sipariş (PKL yok)' ?>)</h3>
        <dl class="sip-dl sip-dl--kisa frgi-ozet">
          <?php
          $ozetSatir = [
              'PO' => (string)$ozet['po_no'], 'Hasat' => (string)$ozet['harvest'], 'Palet' => $kgG((int)$etkin['palet']), 'Kutu' => $kgG((int)$etkin['kutu']),
              'Brüt (kg)' => $kgG((int)$etkin['brut_kg']) . ($etkin['brut_farkli'] ? ' (elle; PKL ' . $pklBrut . ')' : ''),
              'Net (kg)' => $kgG((int)$etkin['net_kg']) . ($etkin['net_farkli'] ? ' (elle; PKL ' . $pklNet . ')' : ''),
              'Mal tanımı' => (string)$etkin['aciklama'], 'Teslim şekli' => $etkin['incoterm'] !== '' ? 'INCOTERMS 2020 ' . $etkin['incoterm'] : '—', 'Plaka' => (string)$etkin['plaka'],
          ];
          foreach ($ozetSatir as $et => $vv): ?>
            <div class="sip-dl-satir"><dt><?= h($et) ?></dt><dd class="<?= $vv === '' || $vv === '—' ? 'sip-bos' : '' ?>"><?= h($vv !== '' ? $vv : '—') ?></dd></div>
          <?php endforeach; ?>
        </dl>
      </section>

      <section class="card sip-onkontrol" id="kontrol" aria-labelledby="frgiKonBas">
        <div class="card-head"><h2 id="frgiKonBas">2. Üretim öncesi kontrol</h2></div>
        <?php if (!$kontrol): ?><p class="sip-tamam">✓ Hazır: engel, uyarı ve not yok.</p><?php endif; ?>
        <?php foreach ($engeller as $x): ?><div class="sip-ok-hata" role="alert">⛔ <?= h($x['mesaj']) ?></div><?php endforeach; ?>
        <?php foreach ($girdiHata as $x): ?><div class="sip-ok-uyari">✎ Doldurun: <?= h($x['mesaj']) ?></div><?php endforeach; ?>
        <?php foreach ($uyarilar as $x): ?><div class="sip-ok-uyari">⚠ <?= h($x['mesaj']) ?></div><?php endforeach; ?>
        <?php foreach ($bilgiler as $x): ?><div class="sip-ok-bilgi">ℹ <?= h($x['mesaj']) ?></div><?php endforeach; ?>
        <?php if ($yaz): ?><p class="sip-ipucu">Değerleri değiştirdiyseniz “Kontrol et” ile listeyi yenileyin.</p><?php endif; ?>
      </section>

      <section class="card" id="uretim" aria-labelledby="frgiUretBas">
        <div class="card-head"><h2 id="frgiUretBas">3. Fatura PDF</h2></div>
        <p class="sip-inv-mensei">🛈 Menşe notu ("<?= h(FRGI_MENSE_NOTU) ?>") her faturaya otomatik eklenir ve kaldırılamaz.</p>
        <?php if ($ayarEksik): ?><div class="sip-ok-uyari">Fatura ayarında eksik: <?= h(implode(', ', $ayarEksik)) ?>.<?= $ayarYk ? ' <a class="sip-link" href="#ayar">Fatura ayarları</a>' : ' Yönetici Fatura ayarlarını tamamlamalı.' ?></div><?php endif; ?>
        <?php if ($var): ?>
          <dl class="sip-dl sip-dl--kisa">
            <div class="sip-dl-satir"><dt>Fatura no</dt><dd><?= h((string)($kayitli['snap']['no'] ?? '')) ?></dd></div>
            <div class="sip-dl-satir"><dt>Son üretim</dt><dd><?= h(sipui_zaman((string)$kayitli['uretildi_at'])) ?></dd></div>
          </dl>
        <?php else: ?>
          <p class="muted">Henüz fatura oluşturulmadı.</p>
        <?php endif; ?>
        <?php if ($yaz && $ayarEksik): ?>
          <label class="sip-onay-satir"><input type="checkbox" name="eksik_izin" value="1" required<?= $izinOnceki ? ' checked' : '' ?>> <span>Fatura ayarında eksik alan var (<?= h(implode(', ', $ayarEksik)) ?>); faturayı <strong>eksik bilgiyle</strong> oluştur</span></label>
        <?php endif; ?>
        <div class="sip-pkl-eylem sip-inv-eylem">
          <?php if ($yaz): ?>
            <button type="submit" class="btn" name="islem" value="kontrol" formnovalidate>🔍 Kontrol et</button>
            <button type="submit" class="btn" name="islem" value="kaydet" formnovalidate>💾 Taslağı kaydet</button>
            <?php if ($engeller): ?>
              <span class="sip-pasif-grup"><button type="button" class="btn btn-primary" disabled aria-describedby="frgiNeden"><?= $var ? '↻ Faturayı yeniden oluştur' : '📄 Fatura PDF oluştur' ?></button><span class="sip-pasif-neden" id="frgiNeden">Önce engelleri giderin: <?= h($engeller[0]['mesaj']) ?></span></span>
            <?php else: ?>
              <button type="submit" class="btn btn-primary" name="islem" value="uret"><?= $var ? '↻ Faturayı yeniden oluştur' : '📄 Fatura PDF oluştur' ?></button>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($var): ?>
            <a class="btn" href="<?= h($buSayfa) ?>&amp;pdf=1&amp;dl=1">⬇ İndir</a>
            <a class="btn" href="<?= h($buSayfa) ?>&amp;pdf=1" target="_blank" rel="noopener">👁 Yeni sekmede aç</a>
          <?php endif; ?>
        </div>
        <?php if ($var): ?>
          <details class="sip-inv-onizle" data-frgi-onizle data-src="<?= h($buSayfa) ?>&amp;pdf=1">
            <summary>PDF önizleme</summary>
            <iframe class="sip-inv-iframe" title="Fatura PDF önizleme" referrerpolicy="same-origin"></iframe>
          </details>
        <?php endif; ?>
      </section>

    </fieldset>
  </form>

  <?php if ($ayarYk): ?>
  <section class="card" id="ayar" aria-labelledby="frgiAyarBas">
    <div class="card-head"><h2 id="frgiAyarBas">Fatura ayarları</h2></div>
    <p class="muted">Banka, IBAN ve VAT bilgileri faturaya basılır. Boş bırakılan alan, varsa OGL invoice ayarından (banka adı / adres / şube / SWIFT / IBAN-EUR) alınır. Değerler günlüğe ve bildirimlere yazılmaz.</p>
    <form method="post" action="<?= h($buSayfa) ?>#ayar" class="frgi-ayar-form">
      <?= sipui_csrf() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="islem" value="ayar">
      <div class="sip-izgara">
        <?php foreach (frgi_ayar_alanlari() as $k => [$et, $n]): ?>
          <label><?= h($et) ?>
            <input type="text" name="<?= h($k) ?>" value="<?= h((string)($ayarHam[$k] ?? '')) ?>" maxlength="<?= (int)$n + 8 ?>" autocomplete="off"<?= in_array($k, ['iban_gbp', 'iban_eur', 'swift'], true) ? ' autocapitalize="characters" spellcheck="false"' : '' ?>>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="sip-pkl-eylem"><button type="submit" class="btn btn-primary">Ayarları kaydet</button></div>
    </form>
  </section>
  <?php endif; ?>
</div>
<script src="assets/frugro_invoice.js?v=<?= (int)@filemtime(__DIR__ . '/assets/frugro_invoice.js') ?>" defer></script>
<?php
render_footer();
