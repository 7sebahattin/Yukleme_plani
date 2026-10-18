<?php
// =========================================================
// siparis_pkl.php?id= — Packing List (PKL) düzenleyici
//
// GET: taslağı gösterir (yazma yetkisi yoksa salt okunur). POST (siparis.write): kaydet · onizle · uret ·
// profil_kaydet · bloklari_yenile. PKL müşterinin KENDİ şablonundan üretilir (siparis_pkl_uret); sayaç
// aralığı / belge no ilk üretimde ayrılır. "Eksik alanlarla oluştur" yalnız açık onayla.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/siparis_eslestir.php';
require_once __DIR__ . '/_siparis_ortak.php';
$auth_user = require_login();
require_siparis('read');
$pdo   = db();
$hazir = siparis_sema_hazir($pdo);
if (!$hazir) {
    render_header('Packing List');
    siparis_assets();
    echo '<div class="sip-mod">' . siparis_kurulmadi_html() . '</div>';
    render_footer();
    exit;
}
$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$sev = siparis_sevkiyat($pdo, $id);
if ($sev === null) { sipui_yok_sayfasi('Sevkiyat bulunamadı'); exit; }
if (!siparis_gorunur($sev)) forbidden('Bu sevkiyat aktif depoda görünmüyor.');
$yaz = can_siparis('write');
$uid = (int)$auth_user['id'];
$kalemler = siparis_kalemler($pdo, $id);
$profiller = siparis_profiller($pdo, true);
$profilHaritasi = [];
foreach ($profiller as $p) $profilHaritasi[(int)$p['id']] = $p;
$git = function (string $url): void { header('Location: ' . $url); exit; };
$buSayfa = 'siparis_pkl.php?id=' . $id;
$onizleVeri = null;          // "Önizle" POST'u: ekrandaki değerlerle hücre önizlemesi (kayıt YOK)
$onizleBaslik = null;
$sutunAd = ['B' => 'Sipariş', 'C' => 'Palet ID', 'D' => 'Artikel', 'E' => 'Ürün', 'F' => 'Çeşit', 'G' => 'Kalibre', 'H' => 'Koli içeriği', 'I' => 'Ambalaj', 'J' => 'Marka',
            'K' => 'Sezon', 'L' => 'Not', 'M' => 'Brüt kg', 'N' => 'Net kg', 'O' => 'Hasat', 'P' => 'Paketleme', 'Q' => 'Lot', 'R' => 'Paketevi', 'S' => 'GLN', 'T' => 'Koli/palet',
            'U' => 'Koli', 'V' => 'Üretici', 'W' => 'GGN', 'X' => 'Tarla', 'Y' => 'İzlenebilirlik', 'Z' => 'Kalıntı rapor', 'AA' => 'Rapor tarihi', 'AB' => 'Laboratuvar'];

/** V..AB boşsa seçilen profilden doldurur (JS kapalıyken de profil seçimi çalışsın). */
$profilUygula = function (array $post) use ($profilHaritasi): array {
    $pid = (int)($post['profil_id'] ?? ($post['baslik']['profil_id'] ?? 0));
    if ($pid < 1 || !isset($profilHaritasi[$pid])) return $post;
    $p = $profilHaritasi[$pid];
    $map = ['V' => 'uretici_adi', 'W' => 'ggn', 'X' => 'tarla_kodu', 'Y' => 'izlenebilirlik_kodu', 'Z' => 'kalinti_rapor_no', 'AA' => 'kalinti_tarihi', 'AB' => 'lab_adi'];
    $bos = true;
    foreach ($map as $col => $_) if (trim((string)($post['varsayilan'][$col] ?? '')) !== '') { $bos = false; break; }
    if ($bos) foreach ($map as $col => $alan) $post['varsayilan'][$col] = (string)($p[$alan] ?? '');
    return $post;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    require_siparis('write');
    $islem = (string)($_POST['islem'] ?? '');
    $baslikG = is_array($_POST['baslik'] ?? null) ? $_POST['baslik'] : [];
    $post = $profilUygula($_POST);
    // Palet ID başlangıcı: kullanıcı ÖNERİLEN numarayı değiştirmediyse sabitleme (aradaki başka PKL sayacı ilerletirse
    // otomatik sıradaki numara alınsın). Daha önce açıkça kaydedilmiş aynı değer korunur.
    $pbVars = trim((string)($post['palet_bas_varsayilan'] ?? ''));
    if ($pbVars !== '' && trim((string)($post['palet_bas_istenen'] ?? '')) === $pbVars) {
        $kayitliIst = siparis_pkl_durum($pdo, $id)['veri']['palet_bas_istenen'] ?? null;
        if ($kayitliIst === null || (string)(int)$kayitliIst !== $pbVars) $post['palet_bas_istenen'] = '';
    }
    if ($baslikG && !isset($baslikG['profil_id']) && isset($post['profil_id'])) $baslikG['profil_id'] = $post['profil_id'];
    if (isset($_POST['profil_id']) && !isset($baslikG['profil_id'])) $baslikG['profil_id'] = $_POST['profil_id'];

    if ($islem === 'kaydet') {
        $r = siparis_pkl_kaydet_taslak($pdo, $id, $post, $baslikG, $uid);
        set_flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'PKL taslağı kaydedildi.' : (string)($r['hata'] ?? 'Taslak kaydedilemedi.'));
        $git($buSayfa);
    }
    if ($islem === 'uret') {
        $izin = ($_POST['eksik_izin'] ?? '') === '1';
        if (isset($_POST['bloklar']) || isset($_POST['varsayilan'])) {      // ana form: önce taslağı kaydet
            $k = siparis_pkl_kaydet_taslak($pdo, $id, $post, $baslikG, $uid);
            if (!$k['ok']) { set_flash('error', (string)($k['hata'] ?? 'Taslak kaydedilemedi.')); $git($buSayfa); }
        }
        $r = siparis_pkl_uret($pdo, $id, $uid, $izin);
        if ($r['ok']) {
            set_flash('success', 'PKL oluşturuldu: ' . (string)$r['dosya_adi'] . ($r['eksik'] > 0 ? ' (kısmi: ' . (int)$r['eksik'] . ' boş hücre — Excel\'de tamamlayın)' : '') . '. Aşağıdan indirebilirsiniz.');
            $git('siparis_view.php?id=' . $id . '#pkl');
        }
        set_flash('error', (string)($r['hata'] ?? 'PKL oluşturulamadı.'));
        $git($buSayfa . '#onkontrol');
    }
    if ($islem === 'onizle') {
        $onizleVeri = siparis_pkl_girdi_temizle($post, $kalemler);
        $onizleBaslik = siparis_pkl_baslik_girdi_temizle($baslikG);
    } elseif ($islem === 'profil_kaydet') {
        $v = is_array($_POST['varsayilan'] ?? null) ? $_POST['varsayilan'] : [];
        $r = siparis_profil_kaydet($pdo, null, [
            'ad' => (string)($_POST['profil_ad'] ?? ''), 'uretici_adi' => (string)($v['V'] ?? ''), 'ggn' => (string)($v['W'] ?? ''), 'tarla_kodu' => (string)($v['X'] ?? ''),
            'izlenebilirlik_kodu' => (string)($v['Y'] ?? ''), 'kalinti_rapor_no' => (string)($v['Z'] ?? ''), 'kalinti_tarihi' => (string)($v['AA'] ?? ''), 'lab_adi' => (string)($v['AB'] ?? ''),
        ], $uid);
        set_flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Profil kaydedildi; listeden seçebilirsiniz.' : (string)($r['hata'] ?? 'Profil kaydedilemedi.'));
        $git($buSayfa);
    } elseif ($islem === 'bloklari_yenile') {
        $d0 = siparis_pkl_durum($pdo, $id);
        $taze = siparis_pkl_varsayilan($sev, $kalemler, null);
        $eskiB = [];
        foreach ((array)($d0['veri']['bloklar'] ?? []) as $b) $eskiB[(int)($b['kalem_id'] ?? 0)] = $b;
        $yeniB = [];
        foreach ($taze['bloklar'] as $b) {
            $o = $eskiB[(int)$b['kalem_id']] ?? null;
            if ($o !== null) foreach (['D', 'F', 'H', 'T', 'M', 'N'] as $c) if (($o[$c] ?? null) !== null && $o[$c] !== '') $b[$c] = $o[$c];
            $yeniB[] = $b;
        }
        $v0 = $d0['veri']; $v0['bloklar'] = $yeniB;
        $pk = $d0['pkl'] ?? [];
        $r = siparis_pkl_kaydet_taslak($pdo, $id, $v0, [
            'belge_tarihi' => $pk['belge_tarihi'] ?? null, 'etd' => $pk['etd'] ?? null, 'eta' => $pk['eta'] ?? null, 'plakalar' => $pk['plakalar'] ?? null,
            'navlun_no' => $pk['navlun_no'] ?? null, 'alici_kodu' => $pk['alici_kodu'] ?? null, 'alici_adi' => $pk['alici_adi'] ?? null, 'profil_id' => $pk['profil_id'] ?? null,
        ], $uid);
        set_flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Bloklar sevkiyattaki güncel kalemlerden yeniden oluşturuldu.' : (string)($r['hata'] ?? 'Bloklar yenilenemedi.'));
        $git($buSayfa);
    } elseif ($islem !== 'onizle') {
        set_flash('error', 'Geçersiz işlem.');
        $git($buSayfa);
    }
}

// ── görüntü durumu ──────────────────────────────────────
$d = siparis_pkl_durum($pdo, $id);
$pklRow = $d['pkl']; $oneri = $d['oneri'];
$veri = $onizleVeri ?? $d['veri'];
$pklHdr = $pklRow ?? [];
if ($onizleBaslik !== null) foreach ($onizleBaslik as $k => $x) $pklHdr[$k] = $x;
$rezerve = $pklRow !== null && $pklRow['belge_no'] !== null;
$belgeNo = $rezerve ? (string)$pklRow['belge_no'] : ((string)($veri['belge_no_istenen'] ?? '') !== '' ? (string)$veri['belge_no_istenen'] : $oneri['belge_no']);
$paletBas = $pklRow !== null && $pklRow['palet_bas'] !== null ? (int)$pklRow['palet_bas'] : (int)($veri['palet_bas_istenen'] ?? 0 ?: $oneri['palet_bas']);
$baslik   = siparis_pkl_baslik($sev, $pklHdr, $veri, $belgeNo);
$satirlar = siparis_pkl_satirlar($sev, $kalemler, $veri, $paletBas);
$kontrol  = siparis_pkl_on_kontrol($sev, $kalemler, $veri, $baslik, $satirlar);
$eksik    = siparis_pkl_eksikler($baslik, $satirlar);
$hatalar  = array_values(array_filter($kontrol, fn($x) => $x['seviye'] === 'hata'));
$uyarilar = array_values(array_filter($kontrol, fn($x) => $x['seviye'] === 'uyari'));
$paletKilitli = $pklRow !== null && $pklRow['palet_bas'] !== null;        // aralık ayrıldı: yeniden üretimde korunur, başlangıç değişmez
$paletBaglam = siparis_pkl_palet_baglam($pdo, (string)$oneri['sezon'], $id);
$uyarilar = array_merge($uyarilar, siparis_pkl_palet_uyarilari_hesapla($paletBaglam['son'], $paletBaglam['diger'], $paletBas, count($satirlar)));
$vg = (array)($veri['varsayilan'] ?? []);
$bloklar = (array)($veri['bloklar'] ?? []);
$n = count($satirlar);
$blokYenile = !$bloklar || in_array('palet_toplam_farkli', array_column($kontrol, 'kod'), true);
$sezon = $oneri['sezon'];
$varlikDurum = $pklRow !== null ? (string)$pklRow['durum'] : 'yok';
$etkinToplam = (int)($sev['toplam_palet'] ?? 0);
$tarihGoster = fn($v): string => ($v !== null && $v !== '' && siparis_tarih_coz((string)$v) !== null) ? sipui_tarih((string)$v) : (string)($v ?? '');
$satirOv = (array)($veri['satir'] ?? []);
$satirBlok = [];                                // satır indeksi → blok indeksi (ortak değer önceliği: satır > blok > ortak)
foreach ($bloklar as $bi => $bl) for ($pi = 0, $pa = max(0, (int)($bl['palet'] ?? 0)); $pi < $pa && count($satirBlok) < count($satirlar); $pi++) $satirBlok[] = (int)$bi;

render_header('Packing List ' . (string)$sev['order_ref']);
siparis_assets();
render_flash();
?>
<div class="sip-mod sip-pkl-sayfa">
  <div class="page-head">
    <div>
      <a class="btn btn-geri" href="siparis_view.php?id=<?= $id ?>#pkl">← <?= h((string)$sev['order_ref']) ?></a>
      <h1 class="sip-h1">Packing List <span class="sip-pkl-durum sip-pkl-durum--<?= h($varlikDurum) ?>"><?= h(['yok' => 'Yok', 'taslak' => 'Taslak', 'uretildi' => 'Üretildi', 'yuklendi' => 'Yüklendi'][$varlikDurum] ?? $varlikDurum) ?></span></h1>
      <p class="muted sip-alt-satir"><?= h($belgeNo) ?> · <?= $n ?> palet · palet ID <span data-sip-palet-aralik-yazi><?= $paletBas ?>–<?= $paletBas + max(0, $n - 1) ?></span> · sezon <?= h($sezon) ?></p>
    </div>
  </div>

  <?php if (!$yaz): ?><p class="sip-bilgi" role="note">Salt okunur görünüm: PKL düzenlemek ve oluşturmak için siparis.write yetkisi gerekir.</p><?php endif; ?>

  <section class="card sip-onkontrol" id="onkontrol" aria-labelledby="sipOnBas">
    <div class="card-head"><h2 id="sipOnBas">Üretim öncesi denetim</h2></div>
    <?php if (!$hatalar && !$uyarilar && $eksik['toplam'] === 0): ?><p class="sip-tamam">✓ Hazır: engel, uyarı ve boş zorunlu hücre yok.</p><?php endif; ?>
    <?php foreach ($hatalar as $x): ?><div class="sip-ok-hata" role="alert">⛔ <?= h($x['mesaj']) ?>
      <?php if ($x['kod'] === 'navlun_yok'): ?> <a class="sip-link" href="siparis_view.php?id=<?= $id ?>&amp;duzenle=navlun_no#bilgi">Navlun no'yu elle gir</a><?php endif; ?></div><?php endforeach; ?>
    <?php foreach ($uyarilar as $x): ?><div class="sip-ok-uyari">⚠ <?= h($x['mesaj']) ?></div><?php endforeach; ?>
    <?php if ($eksik['toplam'] > 0): ?>
      <div class="sip-ok-uyari">Boş zorunlu hücre: <strong><?= (int)$eksik['toplam'] ?></strong> —
        <?php $p = []; foreach ($eksik['alanlar'] as $c => $say) $p[] = h(($sutunAd[$c] ?? $c) . ' (' . $c . ')') . ': ' . (int)$say . (preg_match('/^C\d+$/', (string)$c) ? '' : ' satır'); echo implode(' · ', $p); ?></div>
    <?php endif; ?>
    <?php if ($yaz && $blokYenile): ?>
      <form method="post" action="siparis_pkl.php?id=<?= $id ?>" class="sip-satir-form" data-sip-tek><?= sipui_csrf() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="islem" value="bloklari_yenile">
        <button type="submit" class="btn btn-sm" title="Kalem blokları sevkiyattaki güncel kalemlerden yeniden kurulur; elle girdiğiniz D/F/H/T/M/N korunur">↻ Blokları sevkiyattan yenile</button></form>
    <?php endif; ?>
    <?php if ($yaz && $eksik['toplam'] > 0 && !$hatalar): ?>
      <form method="post" action="siparis_pkl.php?id=<?= $id ?>" class="sip-eksik-kart" data-sip-onay="PKL <?= (int)$eksik['toplam'] ?> boş hücreyle oluşturulsun mu? Eksikleri Excel'de tamamlamanız gerekir.">
        <?= sipui_csrf() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="islem" value="uret"><input type="hidden" name="eksik_izin" value="1">
        <span>Alanları aşağıdan tamamlayabilir ya da şimdilik boş bırakıp Excel'de doldurabilirsiniz.</span>
        <button type="submit" class="btn btn-sm btn-danger">Eksik alanlarla oluştur (Excel'de tamamlayacağım)</button></form>
    <?php endif; ?>
  </section>

  <form method="post" action="siparis_pkl.php?id=<?= $id ?>" class="sip-pkl-form" id="sipPklForm" data-sip-pkl data-sip-etkin-palet="<?= $etkinToplam ?>" data-sip-dara="<?= SIPARIS_DARA_KG ?>"
    data-sip-palet-son="<?= (int)$paletBaglam['son'] ?>" data-sip-palet-diger="<?= sipui_json_attr($paletBaglam['diger']) ?>" data-sip-palet-ilk="<?= $paletBas ?>" data-sip-palet-kilitli="<?= $paletKilitli ? '1' : '0' ?>">
    <?= sipui_csrf() ?><input type="hidden" name="id" value="<?= $id ?>">
    <fieldset class="sip-fieldset"<?= $yaz ? '' : ' disabled' ?>>
      <section class="card" aria-labelledby="sipBaslikBas">
        <div class="card-head"><h2 id="sipBaslikBas">Başlık bilgileri</h2></div>
        <div class="sip-izgara">
          <label>Belge no (C4)
            <?php if ($rezerve): ?><input type="text" value="<?= h($belgeNo) ?>" readonly aria-describedby="sipBelgeNoNot"><span class="sip-ipucu" id="sipBelgeNoNot">Ayrıldı; düzeltme: <a href="siparis_ayarlar.php">Ayarlar</a></span>
            <?php else: ?><input type="text" name="belge_no_istenen" value="<?= h((string)($veri['belge_no_istenen'] ?? '')) ?>" placeholder="<?= h($oneri['belge_no']) ?>" maxlength="20" autocomplete="off"><span class="sip-ipucu">Boş = sıradaki numara (<?= h($oneri['belge_no']) ?>); ilk üretimde ayrılır.</span><?php endif; ?></label>
          <label>Belge tarihi (C5)<input type="date" name="baslik[belge_tarihi]" value="<?= h((string)($pklHdr['belge_tarihi'] ?? '')) ?>"><span class="sip-ipucu">Boş = yükleme tarihi (<?= h(sipui_tarih($sev['yukleme_tarihi'])) ?>)</span></label>
          <label>Plakalar (C8)<input type="text" name="baslik[plakalar]" value="<?= h((string)($pklHdr['plakalar'] ?? '')) ?>" placeholder="<?= h((string)$sev['plakalar']) ?>" autocapitalize="characters" autocomplete="off"><span class="sip-ipucu">Çekici-Dorse, örn. 33AHU899-33AHU910. Boş = sevkiyattaki plaka.</span></label>
          <div class="sip-salt">Navlun no (C9)
            <?php if ($baslik['C9']): ?><strong><?= h((string)$baslik['C9'][1]) ?></strong><?php else: ?><span class="sip-hata-metin">Loading List yüklenmeden PKL oluşturulamaz.</span> <a class="sip-link" href="siparis_view.php?id=<?= $id ?>&amp;duzenle=navlun_no#bilgi">elle gir</a><?php endif; ?></div>
          <label>Alıcı kodu (C10)<input type="text" name="baslik[alici_kodu]" value="<?= h((string)($pklHdr['alici_kodu'] ?? '')) ?>" placeholder="<?= h((string)$sev['alici_kodu']) ?>" inputmode="numeric" maxlength="20"></label>
          <label>Alıcı adı (C11)<input type="text" name="baslik[alici_adi]" value="<?= h((string)($pklHdr['alici_adi'] ?? '')) ?>" placeholder="<?= h((string)$sev['alici_adi']) ?>" maxlength="100"></label>
          <label>ETD (C12)<input type="date" name="baslik[etd]" value="<?= h((string)($pklHdr['etd'] ?? '')) ?>"><span class="sip-ipucu">Boş = yükleme tarihi</span></label>
          <label>ETA (C13)<input type="date" name="baslik[eta]" id="sipEta" value="<?= h((string)($pklHdr['eta'] ?? '')) ?>">
            <?php if ((string)$sev['bosaltma_tarihi'] !== ''): ?><button type="button" class="btn btn-sm" data-sip-eta="<?= h((string)$sev['bosaltma_tarihi']) ?>">LL boşaltma tarihini kullan (<?= h(sipui_tarih($sev['bosaltma_tarihi'])) ?>)</button><?php endif; ?></label>
        </div>
        <p class="sip-sabitler muted">Şablon sabitleri (C2, C3, C6, C7) değişmez.</p>
      </section>

      <section class="card" aria-labelledby="sipProfilBas">
        <div class="card-head"><h2 id="sipProfilBas">Üretici ve kalıntı bilgileri (V–AB)</h2>
          <?php if ($yaz): ?><button type="button" class="btn btn-sm" data-sip-ac="sipProfilModal">💾 Profil olarak kaydet</button><?php endif; ?></div>
        <label class="sip-profil-sec">Kayıtlı profil
          <select name="profil_id" data-sip-profil>
            <option value="">— seçin —</option>
            <?php foreach ($profiller as $p): ?>
              <option value="<?= (int)$p['id'] ?>"<?= (int)($pklHdr['profil_id'] ?? $veri['profil_id'] ?? 0) === (int)$p['id'] ? ' selected' : '' ?>
                data-v="<?= sipui_json_attr(['V' => (string)$p['uretici_adi'], 'W' => (string)$p['ggn'], 'X' => (string)$p['tarla_kodu'], 'Y' => (string)$p['izlenebilirlik_kodu'], 'Z' => (string)$p['kalinti_rapor_no'], 'AA' => (string)$p['kalinti_tarihi'], 'AB' => (string)$p['lab_adi']]) ?>"><?= h((string)$p['ad']) ?></option>
            <?php endforeach; ?>
          </select></label>
        <div class="sip-izgara">
          <label>Üretici (V)<input type="text" name="varsayilan[V]" value="<?= h((string)($vg['V'] ?? '')) ?>" maxlength="255"></label>
          <label>GGN (W)<input type="text" name="varsayilan[W]" value="<?= h((string)($vg['W'] ?? '')) ?>" inputmode="numeric" maxlength="20"></label>
          <label>Tarla kodu (X) · isteğe bağlı<input type="text" name="varsayilan[X]" value="<?= h((string)($vg['X'] ?? '')) ?>" maxlength="255"></label>
          <label>İzlenebilirlik kodu (Y)<input type="text" name="varsayilan[Y]" value="<?= h((string)($vg['Y'] ?? '')) ?>" maxlength="255"></label>
          <label>Kalıntı rapor no (Z)<input type="text" name="varsayilan[Z]" value="<?= h((string)($vg['Z'] ?? '')) ?>" maxlength="255"></label>
          <label>Rapor tarihi (AA)<input type="date" name="varsayilan[AA]" value="<?= h((string)(siparis_tarih_coz((string)($vg['AA'] ?? '')) ?? '')) ?>"></label>
          <label>Laboratuvar (AB)<input type="text" name="varsayilan[AB]" value="<?= h((string)($vg['AB'] ?? '')) ?>" maxlength="255"></label>
        </div>
      </section>

      <section class="card" aria-labelledby="sipVarsBas">
        <div class="card-head"><h2 id="sipVarsBas">Tüm satırlara uygulanan değerler</h2><span class="sip-soluk">Satır bazında değiştirilebilir</span></div>
        <div class="sip-izgara">
          <label>Brüt kg (M)<input type="text" inputmode="decimal" name="varsayilan[M]" id="sipM" value="<?= h((string)($vg['M'] ?? '')) ?>" autocomplete="off"></label>
          <label>Net kg (N)<input type="text" inputmode="decimal" name="varsayilan[N]" id="sipN" value="<?= h((string)($vg['N'] ?? '')) ?>" autocomplete="off"><span class="sip-ipucu" data-sip-n-ipucu>Brüt − net genelde <?= SIPARIS_DARA_KG ?> kg; brütü yazınca net kendiliğinden hesaplanır.</span></label>
          <label>Paketleme (P)<input type="date" name="varsayilan[P]" value="<?= h((string)(siparis_tarih_coz((string)($vg['P'] ?? '')) ?? '')) ?>"></label>
          <label>Hasat (O)<input type="date" name="varsayilan[O]" value="<?= h((string)(siparis_tarih_coz((string)($vg['O'] ?? '')) ?? '')) ?>"><span class="sip-ipucu">Varsayılan: paketlemeden 1 gün önce</span></label>
          <label>Sezon (K)<input type="text" inputmode="numeric" name="varsayilan[K]" value="<?= h((string)($vg['K'] ?? '')) ?>" maxlength="4"></label>
          <label>Marka (J) · isteğe bağlı<input type="text" name="varsayilan[J]" value="<?= h((string)($vg['J'] ?? '')) ?>" maxlength="255"></label>
          <label class="sip-genis">Not (L) · isteğe bağlı<input type="text" name="varsayilan[L]" value="<?= h((string)($vg['L'] ?? '')) ?>" maxlength="255"></label>
        </div>
        <details class="sip-gelismis"><summary>Paketevi ve palet numaralama (ileri düzey)</summary>
          <div class="sip-izgara">
            <label>Paketevi (R)<input type="text" name="varsayilan[R]" value="<?= h((string)($vg['R'] ?? '')) ?>" maxlength="255"></label>
            <label>Paketevi GLN (S)<input type="text" name="varsayilan[S]" value="<?= h((string)($vg['S'] ?? '')) ?>" inputmode="numeric" maxlength="20"></label>
            <?php if ($paletKilitli): ?><div class="sip-salt">Palet aralığı<strong><?= (int)$pklRow['palet_bas'] ?> – <?= (int)$pklRow['palet_bit'] ?></strong><span class="sip-ipucu">Ayrıldı; yeniden üretimde korunur.</span></div>
            <?php elseif ($n > 0 && $n <= SIPARIS_PKL_MAX_PALET): ?><div class="sip-salt">Palet ID başlangıcı<strong data-sip-palet-aralik-yazi><?= $paletBas ?>–<?= $paletBas + max(0, $n - 1) ?></strong><span class="sip-ipucu">«Satır bazında düzenle» tablosunun ilk satırındaki Palet ID'den değiştirilir; sonraki satırlar +1 gider.</span></div>
            <?php else: ?><label>Palet ID başlangıcı<input type="text" inputmode="numeric" name="palet_bas_istenen" value="<?= h((string)($veri['palet_bas_istenen'] ?? '')) ?>" placeholder="<?= (int)$oneri['palet_bas'] ?>"><span class="sip-ipucu">Boş = sayaçtaki sıradaki (<?= (int)$oneri['palet_bas'] ?>).</span></label><?php endif; ?>
          </div>
        </details>
      </section>

      <section class="card" aria-labelledby="sipBlokBas">
        <div class="card-head"><h2 id="sipBlokBas">Kalibre blokları</h2>
          <span class="sip-sayac" data-sip-sayac aria-live="polite">Σ palet: <strong data-sip-sayac-toplam>0</strong> / etkin <?= $etkinToplam ?></span></div>
        <?php if (!$bloklar): ?><div class="empty sip-bos-kutu"><p>Etkin paleti olan kalem yok. PO / Loading List yüklenince bloklar oluşur.</p></div><?php endif; ?>
        <div class="sip-bloklar">
        <?php
        $kmap = [];
        foreach ($kalemler as $k) $kmap[(int)$k['id']] = $k;
        foreach ($bloklar as $i => $b):
            $k = $kmap[(int)($b['kalem_id'] ?? 0)] ?? null;
            $poCesit = $k['_kaynak']['cesit']['po']['v'] ?? null;
            $cesitFark = $poCesit !== null && (string)($b['F'] ?? '') !== '' && mb_strtoupper(trim((string)$b['F']), 'UTF-8') !== mb_strtoupper(trim((string)$poCesit), 'UTF-8');
        ?>
          <fieldset class="sip-blok">
            <legend>Kalibre <?= h((string)($b['kalibre'] ?? '')) ?><?= $k && (string)$k['urun'] !== '' ? ' · ' . h((string)$k['urun']) : '' ?>
              <?php if ($cesitFark): ?><span class="sip-fark-rozet" title="PO'daki çeşit: <?= h((string)$poCesit) ?>">⚠ Çeşit PO'dan farklı</span><?php endif; ?></legend>
            <input type="hidden" name="bloklar[<?= $i ?>][kalem_id]" value="<?= (int)($b['kalem_id'] ?? 0) ?>">
            <div class="sip-izgara sip-izgara--blok">
              <label>Palet sayısı<input type="number" min="0" max="<?= SIPARIS_PKL_MAX_PALET ?>" inputmode="numeric" name="bloklar[<?= $i ?>][palet]" value="<?= (int)($b['palet'] ?? 0) ?>" data-sip-blok-palet></label>
              <label>Artikel (D)<input type="text" inputmode="numeric" name="bloklar[<?= $i ?>][D]" value="<?= h((string)($b['D'] ?? '')) ?>"></label>
              <label>Çeşit (F)<input type="text" name="bloklar[<?= $i ?>][F]" value="<?= h((string)($b['F'] ?? '')) ?>"></label>
              <label>Koli içeriği (H)<input type="text" name="bloklar[<?= $i ?>][H]" value="<?= h((string)($b['H'] ?? '')) ?>"></label>
              <label>Koli/palet (T)<input type="text" inputmode="numeric" name="bloklar[<?= $i ?>][T]" value="<?= h((string)($b['T'] ?? '')) ?>"></label>
              <label>Brüt (M) · blok<input type="text" inputmode="decimal" name="bloklar[<?= $i ?>][M]" value="<?= h((string)($b['M'] ?? '')) ?>" placeholder="ortak"></label>
              <label>Net (N) · blok<input type="text" inputmode="decimal" name="bloklar[<?= $i ?>][N]" value="<?= h((string)($b['N'] ?? '')) ?>" placeholder="ortak"></label>
            </div>
          </fieldset>
        <?php endforeach; ?>
        </div>
      </section>

      <?php if ($n > 0 && $n <= SIPARIS_PKL_MAX_PALET): ?>
      <section class="card sip-satir-kart" aria-labelledby="sipSatirBas" data-sip-izgara-kart>
        <div class="sip-satir-arac">
          <?php if ($yaz): ?><button type="button" class="btn btn-sm" data-sip-ac="sipYapistirModal" title="Excel'den kopyaladığınız Brüt (ve Net) sütununu yapıştırın">📋 Excelden yapıştır</button><?php endif; ?>
          <span class="sip-toplam" data-sip-toplam aria-live="polite">Σ Brüt <strong data-sip-t-brut>0</strong> kg · Σ Net <strong data-sip-t-net>0</strong> kg · Dolu satır <strong data-sip-t-dolu>0</strong> / <?= $n ?></span>
        </div>
        <div class="sip-yap-durum" data-sip-yapistir-durum role="status" aria-live="polite" hidden></div>
        <div class="sip-palet-uyari" data-sip-palet-uyari aria-live="polite"></div>
        <details class="sip-satir-detay">
          <summary id="sipSatirBas">Satır bazında düzenle (<?= $n ?> satır)</summary>
          <p class="muted">Boş bırakılan hücre ortak değeri kullanır (gri ipucu). Yalnız farklı olanı yazın. <span class="sip-ipucu">Enter / ↓ bir alt satır · Shift+Enter / ↑ bir üst satır · Brüt/Net hücresine Excel'den çok satırlı yapıştırabilirsiniz.</span></p>
          <?php if ($paletKilitli): ?><p class="sip-ipucu" data-sip-palet-kilit-not>Palet ID aralığı ayrıldı (<?= (int)$pklRow['palet_bas'] ?>–<?= (int)$pklRow['palet_bit'] ?>); yeniden oluşturmada korunur, başlangıç buradan değiştirilemez.</p><?php endif; ?>
          <div class="table-wrap sip-satir-izgara" tabindex="0" aria-label="Satır bazında değerler (yatay kaydırılır)">
            <table class="data-table small sip-table sip-satir-tablo" data-sip-izgara>
              <thead><tr><th class="sip-sticky">Palet ID</th><th>Kalibre</th><th>Lot (Q)</th>
                <?php foreach (['M', 'N', 'O', 'P', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB'] as $c): ?><th><?= h($sutunAd[$c]) ?> <span class="sip-soluk">(<?= $c ?>)</span></th><?php endforeach; ?></tr></thead>
              <tbody>
              <?php foreach ($satirlar as $i => $r): ?>
                <tr data-sip-satir="<?= $i ?>" data-sip-blok="<?= (int)($satirBlok[$i] ?? 0) ?>">
                  <td class="sip-sticky"><?php if ($i === 0 && $yaz && !$paletKilitli): ?>
                    <input type="text" inputmode="numeric" enterkeyhint="next" maxlength="9" class="sip-hucre sip-palet-bas" name="palet_bas_istenen" value="<?= $paletBas ?>" data-sip-palet-bas data-sip-palet-varsayilan="<?= (int)$oneri['palet_bas'] ?>" aria-label="Palet ID başlangıcı (ilk satır; sonraki satırlar +1)" autocomplete="off">
                    <input type="hidden" name="palet_bas_varsayilan" value="<?= (int)$oneri['palet_bas'] ?>">
                  <?php else: ?><strong data-sip-palet-no><?= (int)$r['C'][1] ?></strong><?php endif; ?></td>
                  <td><?= h((string)($r['G'][1] ?? '')) ?></td>
                  <td><?= h((string)($r['Q'][1] ?? '—')) ?></td>
                  <?php foreach (['M', 'N', 'O', 'P', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB'] as $c):
                      $ov = $satirOv[(string)$i][$c] ?? '';
                      $ph = isset($r[$c]) ? (string)$r[$c][1] : '';
                      $tarihSutun = SIPARIS_PKL_SUTUN_TIP[$c] === 'd';
                      if ($tarihSutun) { $ov = $ov !== '' ? sipui_tarih((string)$ov) : ''; $ph = $ph !== '' ? sipui_tarih($ph) : ''; }
                      $im = in_array($c, ['M', 'N'], true) ? ' inputmode="decimal"' : (in_array($c, ['T', 'U'], true) ? ' inputmode="numeric"' : '');
                  ?>
                    <td><input type="text" name="satir[<?= $i ?>][<?= $c ?>]" value="<?= h((string)$ov) ?>" placeholder="<?= h($ph) ?>" aria-label="<?= h($sutunAd[$c] . ' · palet ' . (int)$r['C'][1]) ?>" class="sip-hucre<?= $ph === '' && $ov === '' ? ' sip-hucre-bos' : '' ?>" autocomplete="off" data-sip-sutun="<?= $c ?>"<?= $im ?> enterkeyhint="<?= $i === $n - 1 ? 'done' : 'next' ?>"></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      </section>
      <?php endif; ?>
    </fieldset>

    <?php
    // hücre önizlemesi (kayıtlı ya da ekrandaki değerlerle)
    $onizleSat = array_slice($satirlar, 0, 40);
    ?>
    <section class="card" id="onizle" aria-labelledby="sipOnizBas">
      <details<?= $onizleVeri !== null ? ' open' : '' ?>>
        <summary id="sipOnizBas">Hücre önizleme<?= $onizleVeri !== null ? ' (ekrandaki değerlerle)' : ' (kayıtlı taslakla)' ?> — ilk <?= count($onizleSat) ?> / <?= $n ?> satır</summary>
        <dl class="sip-dl sip-dl--kisa">
          <?php foreach (['C4' => 'Belge no', 'C5' => 'Belge tarihi', 'C8' => 'Plakalar', 'C9' => 'Navlun', 'C10' => 'Alıcı kodu', 'C11' => 'Alıcı adı', 'C12' => 'ETD', 'C13' => 'ETA'] as $c => $et): $hv = $baslik[$c] ?? null; ?>
            <div class="sip-dl-satir"><dt><?= h($et) ?> (<?= $c ?>)</dt><dd class="<?= $hv === null ? 'sip-hucre-bos-yazi' : '' ?>"><?= $hv === null ? 'boş' : h($hv[0] === 'd' ? sipui_tarih((string)$hv[1]) : (string)$hv[1]) ?></dd></div>
          <?php endforeach; ?>
        </dl>
        <div class="table-wrap"><table class="data-table small sip-table sip-onizle-tablo">
          <thead><tr><?php foreach ($sutunAd as $c => $et): ?><th><?= h($et) ?><span class="sip-soluk"> <?= $c ?></span></th><?php endforeach; ?></tr></thead>
          <tbody><?php foreach ($onizleSat as $r): ?><tr><?php foreach ($sutunAd as $c => $et): $hv = $r[$c] ?? null; ?>
            <td class="<?= $hv === null && !in_array($c, ['J', 'L', 'X'], true) ? 'sip-hucre-bos' : '' ?>"><?= $hv === null ? '' : h($hv[0] === 'd' ? sipui_tarih((string)$hv[1]) : (string)$hv[1]) ?></td>
          <?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div>
      </details>
    </section>

    <?php if ($yaz): ?>
    <div class="sip-bar" role="region" aria-label="PKL işlemleri">
      <button type="submit" class="btn" name="islem" value="kaydet">💾 Taslağı kaydet</button>
      <button type="submit" class="btn" name="islem" value="onizle">👁 Önizle</button>
      <button type="submit" class="btn btn-primary" name="islem" value="uret"<?= $hatalar ? ' disabled aria-describedby="sipUretNeden"' : '' ?>><?= $pklRow && $pklRow['dosya_belge_id'] ? '↻ Yeniden oluştur' : '📄 PKL oluştur' ?></button>
      <?php if ($hatalar): ?><span class="sip-pasif-neden" id="sipUretNeden">Önce yukarıdaki engelleri giderin.</span><?php endif; ?>
    </div>
    <?php endif; ?>
  </form>

  <?php if ($yaz && $n > 0 && $n <= SIPARIS_PKL_MAX_PALET): ?>
  <div class="pm-overlay" id="sipYapistirModal" hidden role="dialog" aria-modal="true" aria-labelledby="sipYapistirBaslik">
    <div class="pm-dialog">
      <div class="pm-header"><h2 class="pm-title" id="sipYapistirBaslik">Excel'den yapıştır</h2><button type="button" class="pm-close" data-sip-kapat aria-label="Kapat">✕</button></div>
      <div class="pm-body sip-form">
        <label>Yapıştırılacak metin
          <textarea rows="7" class="sip-yap-metin" data-sip-yap-metin placeholder="Excel'de Brüt (ve yanındaki Net) sütununu seçip kopyalayın, buraya yapıştırın" autocomplete="off" spellcheck="false" inputmode="text"></textarea></label>
        <div class="sip-izgara sip-yap-sec">
          <label>Başlangıç satırı<select data-sip-yap-satir></select></label>
          <label>Hedef sütun<select data-sip-yap-hedef><option value="M">Brüt kg (M)</option><option value="N">Net kg (N)</option></select><span class="sip-ipucu">İki sütun (Brüt | Net) yapıştırırsanız Brüt'ten başlar.</span></label>
        </div>
        <div class="sip-yap-onizleme" data-sip-yap-onizleme aria-live="polite"></div>
        <p class="muted sip-ipucu">Sayılar Türkçe biçimde okunur: nokta binlik ayırıcı, virgül ondalık (1.234,5). Hiçbir şey kaydedilmez; hücreler dolar, sonra «Taslağı kaydet»'e basın.</p>
      </div>
      <div class="pm-footer"><button type="button" class="btn" data-sip-kapat>Vazgeç</button><button type="button" class="btn btn-primary" data-sip-yap-uygula disabled>Uygula</button></div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($yaz): ?>
  <div class="pm-overlay" id="sipProfilModal" hidden role="dialog" aria-modal="true" aria-labelledby="sipProfilBaslik">
    <div class="pm-dialog">
      <form method="post" action="siparis_pkl.php?id=<?= $id ?>" data-sip-tek data-sip-profil-form>
        <div class="pm-header"><h2 class="pm-title" id="sipProfilBaslik">Profil olarak kaydet</h2><button type="button" class="pm-close" data-sip-kapat aria-label="Kapat">✕</button></div>
        <div class="pm-body sip-form">
          <?= sipui_csrf() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="islem" value="profil_kaydet">
          <label>Profil adı<input type="text" name="profil_ad" required maxlength="100" autocomplete="off" placeholder="örn. Bahçe A — 2026"></label>
          <p class="muted">V–AB alanlarındaki şu anki değerler profil olarak saklanır (sayfadaki değerler gönderilir).</p>
          <?php foreach (['V', 'W', 'X', 'Y', 'Z', 'AA', 'AB'] as $c): ?><input type="hidden" name="varsayilan[<?= $c ?>]" value="" data-sip-profil-kopya="<?= $c ?>"><?php endforeach; ?>
        </div>
        <div class="pm-footer"><button type="button" class="btn" data-sip-kapat>Vazgeç</button><button type="submit" class="btn btn-primary">Profili kaydet</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php
siparis_scripts();
render_footer();
