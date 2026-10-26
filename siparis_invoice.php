<?php
// =========================================================
// siparis_invoice.php?id= — OGL Invoice hazırlama (v442)
//
// GET: e-fatura yükleme + invoice bilgileri (no / tarih / €-kg fiyat / iskonto) + PKL'den gelen salt okunur özet + kontrol listesi
//      + PDF durumu/indirme/önizleme. Okuma: siparis.read. POST (siparis.write, CSRF, PRG): efatura · kontrol · uret.
// Mantık config/siparis_invoice.php'dedir (siparis_inv_*). Bu dosya FONKSİYON TANIMLAMAZ (render testi iki kez include eder).
// Menşe beyanı (REX) her PDF'te şablonda koşulsuzdur; ayar boşsa "eksik izni" onayı ister.
// Ad TEK alt çizgili: sw.js bypass regex'i kapsar (/siparis_invoice.php önbelleğe girmez). Satır içi onclick YOK: data-sip-inv-*.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/siparis_eslestir.php';
require_once __DIR__ . '/config/siparis_invoice.php';
require_once __DIR__ . '/_siparis_ortak.php';
$auth_user = require_login();
require_siparis('read');
siparis_no_store();
$pdo   = db();
$hazir = siparis_sema_hazir($pdo);

if (!$hazir) {
    render_header('Invoice');
    siparis_assets();
    echo '<div class="sip-mod">' . siparis_kurulmadi_html() . '</div>';
    render_footer();
    exit;
}
$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$sev = siparis_sevkiyat($pdo, $id);
if ($sev === null || !siparis_gorunur($sev)) { sipui_yok_sayfasi('Sevkiyat bulunamadı'); exit; }   // başka depodaki kayıt 404 ile AYNI (varlığı sızdırmaz)
$yaz = can_siparis('write');
$uid = (int)$auth_user['id'];
$buSayfa = 'siparis_invoice.php?id=' . $id;
$git = function (string $parca = '') use ($buSayfa): void { header('Location: ' . $buSayfa . $parca); exit; };

/** Ekran değeri: sayı → virgüllü yazım (sunucu ayrıştırıcısı TR biçimini bilir), tarih → ISO. */
$sayiYaz = function (mixed $v, int $ond, bool $sabit = false): string {
    if ($v === null || $v === '') return '';
    if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
        $s = number_format((float)$v, $ond, ',', '');
        return ($sabit || !str_contains($s, ',')) ? $s : rtrim(rtrim($s, '0'), ',');
    }
    return trim((string)$v);
};
$alanYaz = function (string $k, mixed $v) use ($sayiYaz): string {
    return match ($k) {
        'tarih' => (string)(siparis_tarih_coz(is_string($v) || is_int($v) ? $v : '') ?? ''),
        'fiyat' => $sayiYaz($v, 4),
        'iskonto' => $sayiYaz($v, 2),
        'efatura_toplam' => $sayiYaz($v, 2, true),
        default => trim((string)($v ?? '')),
    };
};
$FORM_ALANLARI = ['no', 'tarih', 'fiyat', 'iskonto', 'efatura_toplam'];

// ── POST: e-fatura yükle · kontrol · PDF üret (hepsi PRG) ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    require_siparis('write');
    $islem = (string)($_POST['islem'] ?? '');
    $gonderilen = [];
    foreach ($FORM_ALANLARI as $k) $gonderilen[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, 60, 'UTF-8');
    $izin = ($_POST['eksik_izin'] ?? '') === '1';
    $formSakla = function (array $deger, array $hata = [], array $ekstra = []) use ($id, $izin): void {
        $_SESSION['siparis_inv_form'] = [$id => ['deger' => $deger, 'hata' => $hata, 'izin' => $izin] + $ekstra];
    };

    if ($islem === 'efatura') {
        $dosya = $_FILES['efatura'] ?? null;
        if (!is_array($dosya) || (int)($dosya['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $formSakla($gonderilen);
            set_flash('error', 'Önce bir e-fatura PDF dosyası seçin.');
            $git('#efatura');
        }
        $moverFn = PHP_SAPI === 'cli' ? 'copy' : null;   // web'de move_uploaded_file; CLI (testler) onu kullanamaz (siparis_yukle.php ile aynı kalıp)
        $r = siparis_inv_efatura_yukle($pdo, $id, $dosya, $uid, $moverFn);
        $formSakla($gonderilen);
        if (empty($r['ok'])) { set_flash('error', (string)($r['hata'] ?? 'e-fatura yüklenemedi.')); $git('#efatura'); }
        $okundu = [];
        $deger = $gonderilen;
        foreach ((array)($r['alanlar'] ?? []) as $k => $v) {
            if (!in_array($k, $FORM_ALANLARI, true)) continue;
            $y = $alanYaz($k, $v);
            if ($y === '') continue;
            $deger[$k] = $y; $okundu[] = $k;
        }
        $formSakla($deger, [], ['okundu' => $okundu, 'metin_var' => !empty($r['metin_var'])]);
        set_flash('success', !empty($r['metin_var']) && $okundu
            ? 'e-fatura yüklendi; bulunan değerler forma yazıldı — lütfen kontrol edin.'
            : 'e-fatura yüklendi; değerler okunamadı, elle girin.');
        $git('#bilgiler');
    }
    if ($islem === 'kontrol') {
        $formSakla($gonderilen);
        $git('#kontrol');
    }
    if ($islem === 'uret') {
        $t = siparis_inv_girdi_temizle($gonderilen);
        if (empty($t['ok'])) {
            $formSakla($gonderilen, (array)($t['hata'] ?? []));
            set_flash('error', 'Invoice bilgilerinde eksik ya da hatalı alan var; işaretli alanları düzeltin.');
            $git('#bilgiler');
        }
        $veri = (array)$t['veri'];
        $vs = siparis_inv_varsayilan($pdo, $sev);
        $ozet = (array)($vs['ozet'] ?? []);
        $ayar = siparis_inv_ayar_oku($pdo);
        $hatalar = array_values(array_filter(siparis_inv_kontrol($pdo, $sev, $veri, $ozet, $ayar), static fn($x) => ($x['seviye'] ?? '') === 'hata'));
        if ($hatalar) {
            $formSakla($gonderilen);
            set_flash('error', 'Invoice oluşturulamadı: ' . (string)($hatalar[0]['mesaj'] ?? 'engel var') . ($hatalar[1] ?? null ? ' (+' . (count($hatalar) - 1) . ' engel daha)' : ''));
            $git('#kontrol');
        }
        $eksikAyar = siparis_inv_ayar_eksikler($ayar) !== [];
        if ($eksikAyar && !$izin) {
            $formSakla($gonderilen);
            set_flash('error', 'Invoice ayarında eksik alan var (REX no / banka bilgileri): boş alanlarla oluşturmak için “eksik bilgiyle oluştur” onayını işaretleyin (ya da Sipariş ayarları › Invoice ayarları’nı doldurun).');
            $git('#uretim');
        }
        $r = siparis_inv_uret($pdo, $id, $uid, $veri, $izin);
        if (!empty($r['ok'])) {
            unset($_SESSION['siparis_inv_form']);
            $uy = count(array_filter((array)($r['uyarilar'] ?? []), static fn($x) => ($x['seviye'] ?? '') === 'uyari'));
            set_flash('success', 'Invoice oluşturuldu: ' . (string)($r['dosya_adi'] ?? '') . ($uy > 0 ? ' (' . $uy . ' uyarı ile — kontrol listesine bakın)' : '') . '. Aşağıdan indirebilirsiniz.');
            $git('#uretim');
        }
        $formSakla($gonderilen);
        set_flash('error', (string)($r['hata'] ?? 'Invoice oluşturulamadı.'));
        $git('#uretim');
    }
    set_flash('error', 'Geçersiz işlem.');
    $git();
}

// ── GET: görüntü durumu ──────────────────────────────────────────────────────
$vs     = siparis_inv_varsayilan($pdo, $sev);
$ozet   = (array)($vs['ozet'] ?? []);
$notlar = (array)($vs['notlar'] ?? []);
$ayar   = siparis_inv_ayar_oku($pdo);
$durum  = siparis_inv_durum($pdo, $id);
$form   = $_SESSION['siparis_inv_form'][$id] ?? null;         // başarısız gönderim / kontrol / e-fatura sonucu: tek kullanımlık
unset($_SESSION['siparis_inv_form']);
$girdi  = (array)($vs['girdi'] ?? []);
$deger  = [];
foreach ($FORM_ALANLARI as $k) $deger[$k] = $alanYaz($k, $girdi[$k] ?? '');
if (is_array($form)) foreach ($FORM_ALANLARI as $k) if (array_key_exists($k, (array)$form['deger'])) $deger[$k] = (string)$form['deger'][$k];
$hataMap = is_array($form) ? (array)($form['hata'] ?? []) : [];
$okundu  = is_array($form) ? (array)($form['okundu'] ?? []) : [];
$metinVar = is_array($form) && array_key_exists('metin_var', $form) ? (bool)$form['metin_var'] : null;
$izinOnceki = is_array($form) && !empty($form['izin']);

$tK = siparis_inv_girdi_temizle($deger);
$veriK = is_array($tK['veri'] ?? null) && $tK['veri'] ? (array)$tK['veri'] : $girdi;
$kontrol = siparis_inv_kontrol($pdo, $sev, $veriK, $ozet, $ayar);
$hataTum  = array_values(array_filter($kontrol, static fn($x) => ($x['seviye'] ?? '') === 'hata'));
// 'girdi_*' = formdaki eksik/hatalı alan (kullanıcı doldurur; zorunlu alanlar ve sunucu doğrulaması korur) → Oluştur'u KİLİTLEMEZ; geri kalanı gerçek engeldir (net 0, brüt<net, palet/koli yok…)
$girdiHata = array_values(array_filter($hataTum, static fn($x) => str_starts_with((string)($x['kod'] ?? ''), 'girdi_')));
$hatalar  = array_values(array_filter($hataTum, static fn($x) => !str_starts_with((string)($x['kod'] ?? ''), 'girdi_')));
$uyarilar = array_values(array_filter($kontrol, static fn($x) => ($x['seviye'] ?? '') === 'uyari'));
$bilgiler = array_values(array_filter($kontrol, static fn($x) => !in_array(($x['seviye'] ?? ''), ['hata', 'uyari'], true)));
$hes = !empty($tK['ok']) ? siparis_inv_hesapla((array)$tK['veri'], $ozet) : null;

$ayarEksikler = siparis_inv_ayar_eksikler($ayar);     // REX no + banka adı/adresi/şube/SWIFT/IBAN
$rexBos   = trim((string)($ayar['rex_no'] ?? '')) === '';
$bankaBos = count(array_diff($ayarEksikler, ['REX no'])) > 0;
$eksikAyar = $ayarEksikler !== [];
$var = !empty($durum['var']);
$belgeId = (int)($durum['belge_id'] ?? 0);
$efBelgeId = (int)($durum['efatura_belge_id'] ?? 0);
$efAd = '';
if ($efBelgeId > 0) {
    $eb = $pdo->prepare('SELECT * FROM siparis_belgeler WHERE id = ? AND deleted_at IS NULL');
    $eb->execute([$efBelgeId]);
    $ebr = $eb->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($ebr && siparis_belge_gorunur($pdo, $ebr)) $efAd = (string)$ebr['orijinal_ad']; else $efBelgeId = 0;
}
$ozetMetin = static function (mixed $v): string {
    if (is_array($v)) return implode(', ', array_map(static fn($x) => is_array($x) ? implode(' ', array_map('strval', $x)) : (string)$x, $v));
    return trim((string)($v ?? ''));
};
$para = static fn(int $kurus): string => number_format($kurus / 100, 2, ',', '.');
$netKg = (float)($ozet['net_kg'] ?? 0);
$kaynakEt = (string)($ozet['kaynak'] ?? '') === 'pkl' ? 'Güncel PKL (Packing List)' : ((string)($ozet['kaynak'] ?? '') === 'kalem' ? 'Sevkiyat kalemleri (PKL yok)' : '—');
$seviyeIkon = ['hata' => '⛔', 'uyari' => '⚠', 'bilgi' => 'ℹ'];

render_header('Invoice ' . (string)$sev['order_ref']);
siparis_assets();
render_flash();
?>
<div class="sip-mod sip-inv-sayfa">
  <div class="page-head">
    <div>
      <a class="btn btn-geri" href="siparis_view.php?id=<?= $id ?>#invoice">← <?= h((string)$sev['order_ref']) ?></a>
      <h1 class="sip-h1">Invoice (OGL) <span class="sip-pkl-durum sip-inv-durum--<?= $var ? 'hazir' : 'yok' ?>"><?= $var ? 'Hazır' : 'Yok' ?></span></h1>
      <p class="muted sip-alt-satir"><?= h((string)$sev['order_ref']) ?> · <?= h((string)$sev['alici_adi'] !== '' ? (string)$sev['alici_adi'] : 'Alıcı bilinmiyor') ?></p>
    </div>
  </div>

  <?php if (!$yaz): ?><p class="sip-bilgi" role="note">Salt okunur görünüm: invoice hazırlamak için siparis.write yetkisi gerekir.</p><?php endif; ?>

  <form method="post" action="<?= h($buSayfa) ?>" enctype="multipart/form-data" class="sip-inv-form" id="sipInvForm" data-sip-inv data-sip-inv-net="<?= h((string)$netKg) ?>" data-sip-tek>
    <?= sipui_csrf() ?><input type="hidden" name="id" value="<?= $id ?>">
    <fieldset class="sip-fieldset"<?= $yaz ? '' : ' disabled' ?>>

      <section class="card" id="efatura" aria-labelledby="sipInvEfBas">
        <div class="card-head"><h2 id="sipInvEfBas">1. e-Fatura (isteğe bağlı)</h2></div>
        <p class="muted">Türk e-faturası PDF’ini yüklerseniz fatura no, tarih, birim fiyat, iskonto ve toplam (metin katmanı varsa) forma yazılır; her değeri siz onaylarsınız. Görüntü hâlindeki PDF okunamaz — değerleri elle girin.</p>
        <?php if ($efBelgeId > 0): ?>
          <p class="sip-inv-yuklu">Yüklü e-fatura: <a href="siparis_belge.php?id=<?= $efBelgeId ?>"><?= h($efAd) ?></a> · <a href="siparis_dosya.php?id=<?= $efBelgeId ?>&amp;dl=1">İndir</a></p>
        <?php endif; ?>
        <?php if ($metinVar === false): ?>
          <div class="sip-ok-uyari" role="status">Bu PDF görüntü olduğundan değerler okunamadı; aşağıya elle girin.</div>
        <?php elseif ($metinVar === true && $okundu): ?>
          <div class="sip-bilgi" role="status">e-faturadan okundu — kontrol edin: <?= h(implode(', ', array_map(static fn($k) => ['no' => 'invoice no', 'tarih' => 'tarih', 'fiyat' => 'birim fiyat', 'iskonto' => 'iskonto', 'efatura_toplam' => 'toplam'][$k] ?? $k, $okundu))) ?>.</div>
        <?php elseif ($metinVar === true): ?>
          <div class="sip-ok-uyari" role="status">PDF’te metin var ama tanınan alan bulunamadı; değerleri elle girin.</div>
        <?php endif; ?>
        <?php if ($yaz): ?>
        <div class="sip-inv-yukle">
          <label class="sip-inv-dosya">e-Fatura PDF<input type="file" name="efatura" accept="application/pdf,.pdf"></label>
          <button type="submit" class="btn" name="islem" value="efatura" formnovalidate>⬆ e-Faturayı yükle</button>
        </div>
        <?php endif; ?>
      </section>

      <section class="card" id="bilgiler" aria-labelledby="sipInvBilBas">
        <div class="card-head"><h2 id="sipInvBilBas">2. Invoice bilgileri</h2></div>
        <div class="sip-izgara">
          <?php
          $alanlar = [
              'no' => ['Invoice no', 'text', 'IHR…', ' autocomplete="off" maxlength="40" required'],
              'tarih' => ['Invoice tarihi', 'date', '', ' required'],
              'fiyat' => ['Birim fiyat (€ / kg)', 'text', '1,37', ' inputmode="decimal" autocomplete="off" maxlength="14" data-sip-inv-giris="fiyat" required'],
              'iskonto' => ['İskonto (%)', 'text', '0', ' inputmode="decimal" autocomplete="off" maxlength="8" data-sip-inv-giris="iskonto"'],
              'efatura_toplam' => ['e-fatura toplamı (€) · çapraz kontrol', 'text', '', ' inputmode="decimal" autocomplete="off" maxlength="16" data-sip-inv-giris="efatura"'],
          ];
          foreach ($alanlar as $k => [$et, $tip, $ph, $ek]): ?>
            <label><?= h($et) ?>
              <input type="<?= $tip ?>" name="<?= $k ?>" value="<?= h($deger[$k]) ?>"<?= $ph !== '' ? ' placeholder="' . h($ph) . '"' : '' ?><?= $ek ?><?= isset($hataMap[$k]) ? ' aria-invalid="true"' : '' ?>>
              <?php if (in_array($k, $okundu, true)): ?><span class="sip-inv-okundu">e-faturadan okundu — kontrol edin</span><?php endif; ?>
              <?php if (isset($hataMap[$k])): ?><span class="sip-hata-metin" role="alert"><?= h(is_string($hataMap[$k]) ? $hataMap[$k] : 'Geçersiz değer') ?></span><?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
        <p class="sip-ipucu">Ondalık ayırıcı virgüldür (1,37). Tutarlar sunucuda yeniden hesaplanır; aşağıdaki sonuç yalnız önizlemedir.</p>

        <div class="sip-inv-sonuc" data-sip-inv-sonuc aria-live="polite">
          <div><span class="sip-soluk">Tutar (net × fiyat)</span><span class="sip-inv-deger"><strong data-inv-tutar><?= $hes ? h($para((int)$hes['tutar_kurus'])) : '—' ?></strong> €</span></div>
          <div><span class="sip-soluk">İskonto <span data-inv-iskonto-yuzde></span></span><span class="sip-inv-deger"><strong data-inv-iskonto><?= $hes ? h($para((int)$hes['iskonto_kurus'])) : '—' ?></strong> €</span></div>
          <div class="sip-inv-toplam"><span class="sip-soluk">Toplam</span><span class="sip-inv-deger"><strong data-inv-toplam><?= $hes ? h($para((int)$hes['toplam_kurus'])) : '—' ?></strong> €</span></div>
          <div class="sip-inv-fark" data-inv-fark hidden></div>
        </div>

        <h3 class="sip-grup-bas">Özet (salt okunur — <?= h($kaynakEt) ?>)</h3>
        <dl class="sip-dl sip-dl--kisa sip-inv-ozet">
          <?php
          $ozetSatir = [
              'PO / sipariş' => $ozetMetin($ozet['order_ref'] ?? $sev['order_ref']),
              'PKL belge no' => $ozetMetin($ozet['belge_no'] ?? ''),
              'Palet' => sipui_sayi($ozet['palet'] ?? null),
              'Koli' => sipui_sayi($ozet['koli'] ?? null),
              'Brüt (kg)' => sipui_sayi($ozet['brut_kg'] ?? null),
              'Net (kg)' => sipui_sayi($ozet['net_kg'] ?? null),
              'Kalibre' => $ozetMetin($ozet['kalibreler'] ?? ''),
              'Mal tanımı' => $ozetMetin($ozet['aciklama'] ?? ''),
              'Hasat' => $ozetMetin($ozet['harvest'] ?? ''),
              'Plaka' => $ozetMetin($ozet['plaka'] ?? ''),
              'Teslim şekli' => trim((string)($ayar['teslim'] ?? '')) !== '' ? (string)$ayar['teslim'] : '—',
          ];
          foreach ($ozetSatir as $et => $vv): ?>
            <div class="sip-dl-satir"><dt><?= h($et) ?></dt><dd class="<?= $vv === '' || $vv === '—' ? 'sip-bos' : '' ?>"><?= h($vv !== '' ? $vv : '—') ?></dd></div>
          <?php endforeach; ?>
        </dl>
        <?php if ($notlar): ?>
          <ul class="sip-inv-notlar"><?php foreach ($notlar as $n): ?><li><?= h(is_array($n) ? (string)($n['mesaj'] ?? '') : (string)$n) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </section>

      <section class="card sip-onkontrol" id="kontrol" aria-labelledby="sipInvKonBas">
        <div class="card-head"><h2 id="sipInvKonBas">3. Üretim öncesi kontrol</h2></div>
        <?php if (!$kontrol): ?><p class="sip-tamam">✓ Hazır: engel, uyarı ve not yok.</p><?php endif; ?>
        <?php foreach ($hatalar as $x): ?><div class="sip-ok-hata" role="alert">⛔ <?= h((string)($x['mesaj'] ?? '')) ?></div><?php endforeach; ?>
        <?php foreach ($girdiHata as $x): ?><div class="sip-ok-uyari" data-sip-inv-doldur>✎ Doldurun: <?= h((string)($x['mesaj'] ?? '')) ?></div><?php endforeach; ?>
        <?php foreach ($uyarilar as $x): ?><div class="sip-ok-uyari">⚠ <?= h((string)($x['mesaj'] ?? '')) ?></div><?php endforeach; ?>
        <?php foreach ($bilgiler as $x): ?><div class="sip-ok-bilgi">ℹ <?= h((string)($x['mesaj'] ?? '')) ?></div><?php endforeach; ?>
        <?php if ($yaz): ?><p class="sip-ipucu">Değerleri değiştirdiyseniz “Kontrol et” ile listeyi yenileyin.</p><?php endif; ?>
      </section>

      <section class="card" id="uretim" aria-labelledby="sipInvUretBas">
        <div class="card-head"><h2 id="sipInvUretBas">4. Invoice PDF</h2></div>
        <p class="sip-inv-mensei">🛈 Menşe beyanı (REX) her invoice’a otomatik eklenir ve kaldırılamaz.</p>
        <?php if ($rexBos): ?><div class="sip-ok-hata" role="alert">REX numarası ayarlı değil: PDF’te “Customs Authorisation No. ______” boş basılır.<?php if (can_siparis('delete')): ?> <a class="sip-link" href="siparis_ayarlar.php#invoice-ayar">Invoice ayarları</a><?php endif; ?></div><?php endif; ?>
        <?php if ($bankaBos): ?><div class="sip-ok-uyari">Banka bilgisi eksik: <?= h(implode(', ', array_diff($ayarEksikler, ['REX no']))) ?>.<?php if (can_siparis('delete')): ?> <a class="sip-link" href="siparis_ayarlar.php#invoice-ayar">Invoice ayarları</a><?php endif; ?></div><?php endif; ?>
        <?php if ($var): ?>
          <dl class="sip-dl sip-dl--kisa">
            <div class="sip-dl-satir"><dt>Dosya</dt><dd class="sip-kes"><?= h((string)($durum['dosya_adi'] ?? '')) ?></dd></div>
            <div class="sip-dl-satir"><dt>Son üretim</dt><dd><?= h(sipui_zaman((string)($durum['uretildi_at'] ?? ''))) ?><?= (int)($durum['uretim_sayisi'] ?? 0) > 1 ? ' · ' . (int)$durum['uretim_sayisi'] . '. üretim' : '' ?></dd></div>
          </dl>
        <?php else: ?>
          <p class="muted">Henüz invoice oluşturulmadı.</p>
        <?php endif; ?>
        <?php if ($yaz && $eksikAyar): ?>
          <label class="sip-onay-satir"><input type="checkbox" name="eksik_izin" value="1" required<?= $izinOnceki ? ' checked' : '' ?>> Invoice ayarında eksik alan var (<?= h(implode(', ', $ayarEksikler)) ?>); invoice’ı <strong>eksik bilgiyle</strong> oluştur</label>
        <?php endif; ?>
        <div class="sip-pkl-eylem sip-inv-eylem">
          <?php if ($yaz): ?>
            <button type="submit" class="btn" name="islem" value="kontrol" formnovalidate>🔍 Kontrol et</button>
            <?php if ($hatalar): ?>
              <span class="sip-pasif-grup"><button type="button" class="btn btn-primary" disabled aria-describedby="sipInvNeden"><?= $var ? '↻ Invoice PDF yeniden oluştur' : '📄 Invoice PDF oluştur' ?></button><span class="sip-pasif-neden" id="sipInvNeden">Önce yukarıdaki engelleri giderin: <?= h((string)($hatalar[0]['mesaj'] ?? '')) ?></span></span>
            <?php else: ?>
              <button type="submit" class="btn btn-primary" name="islem" value="uret"><?= $var ? '↻ Invoice PDF yeniden oluştur' : '📄 Invoice PDF oluştur' ?></button>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($var && $belgeId > 0): ?>
            <a class="btn" href="siparis_dosya.php?id=<?= $belgeId ?>&amp;dl=1">⬇ İndir</a>
            <a class="btn" href="siparis_dosya.php?id=<?= $belgeId ?>" target="_blank" rel="noopener">👁 Yeni sekmede aç</a>
          <?php endif; ?>
        </div>
        <?php if ($var && $belgeId > 0): ?>
          <details class="sip-inv-onizle" data-sip-inv-onizle data-src="siparis_dosya.php?id=<?= $belgeId ?>">
            <summary>PDF önizleme</summary>
            <iframe class="sip-inv-iframe" title="Invoice PDF önizleme" referrerpolicy="same-origin"></iframe>
          </details>
        <?php endif; ?>
      </section>

    </fieldset>
  </form>
</div>
<?php
siparis_scripts();
render_footer();
