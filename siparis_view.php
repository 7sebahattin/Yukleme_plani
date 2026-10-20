<?php
// =========================================================
// siparis_view.php?id= — Sevkiyat ayrıntısı
//
// Başlık + durum adımları (tam genişlik), altında ızgara: uyarılar · çatışmalar · sevkiyat bilgileri · kalemler ·
// belgeler · PKL · beyan · zaman çizelgesi. Salt okunur sayfa; yazan işlemler siparis_kaydet.php /
// siparis_yukle.php / siparis_pkl.php / siparis_beyan_bagla.php uçlarına POST eder (CSRF + kapı orada).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/siparis_eslestir.php';
require_once __DIR__ . '/config/siparis_beyan.php';
require_once __DIR__ . '/_siparis_ortak.php';
$auth_user = require_login();
require_siparis('read');
$pdo   = db();
$hazir = siparis_sema_hazir($pdo);

if (!$hazir) {
    render_header('Sevkiyat');
    siparis_assets();
    echo '<div class="sip-mod">' . siparis_kurulmadi_html() . '</div>';
    render_footer();
    exit;
}
$id  = (int)($_GET['id'] ?? 0);
$sev = siparis_sevkiyat($pdo, $id);
if ($sev === null) { sipui_yok_sayfasi('Sevkiyat bulunamadı'); exit; }
if (!siparis_gorunur($sev)) forbidden('Bu sevkiyat "' . (string)$sev['depo'] . '" deposuna ait; aktif depoda görünmüyor.');

$yaz  = can_siparis('write');
$sil  = can_siparis('delete');
$esle = can_siparis('match') && can_beyan('read');
$beyanOku = can_beyan('read');
$durumlar = siparis_durumlar();
$kapali   = in_array((string)$sev['durum'], siparis_kapali_durumlar(), true);
$iptal    = (string)$sev['durum'] === 'iptal';
$sira     = (int)($durumlar[(string)$sev['durum']]['sira'] ?? 0);

$kalemler  = siparis_kalemler($pdo, $id);
$belgeler  = siparis_belgeler($pdo, $id, true);
$acikCat   = siparis_catismalar($pdo, $id, 'acik');
$tumCat    = siparis_catismalar($pdo, $id, null);
$cozulenCat = array_values(array_filter($tumCat, fn($c) => $c['durum'] !== 'acik'));
$tumGecmis = (string)($_GET['gecmis'] ?? '') === 'tum';
$olaylar   = siparis_olaylar($pdo, $id, $tumGecmis ? 1000 : 30);
$pklD      = siparis_pkl_durum($pdo, $id);
$pkl       = $pklD['pkl'];
$tanimlar  = siparis_alan_tanimlari();
$soforHazir = siparis_sofor_hazir($pdo);
$yiHazir = siparis_yurtici_gumruk_hazir($pdo);   // v387: yurt içi gümrük kolonu (migrate.php kartı) — yoksa alan gizli      // v377: şoför adı/telefon kolonları (migrate.php kartı) — yoksa alanlar gizli

// Aynı PO'daki diğer sevkiyatlar
$kardesler = [];
if ($sev['po_belge_id']) {
    [$ksql, $kprm] = depo_sql_column('s.depo', ':udk');
    $k = $pdo->prepare('SELECT s.id, s.order_ref FROM siparis_sevkiyatlar s WHERE s.po_belge_id = :po AND s.id <> :id AND s.deleted_at IS NULL' . $ksql . ' ORDER BY s.order_ref LIMIT 8');
    $k->execute([':po' => (int)$sev['po_belge_id'], ':id' => $id] + $kprm);
    $kardesler = $k->fetchAll(PDO::FETCH_ASSOC);
}
// PO'daki PKL e-postaları
$poEpostalar = siparis_po_pkl_epostalar($pdo, $sev);
// PKL üreten kullanıcı adı
$pklKullanici = '';
if ($pkl && $pkl['uretildi_by']) {
    $u = $pdo->prepare('SELECT COALESCE(display_name, username) FROM users WHERE id = ?');
    $u->execute([(int)$pkl['uretildi_by']]);
    $pklKullanici = (string)($u->fetchColumn() ?: '');
}
// Beyan
$beyanBaglar = []; $beyanOneri = []; $beyanAra = []; $beyanHata = false;
$beyanQ = trim((string)($_GET['beyan_q'] ?? ''));
if ($beyanOku) {
    try {
        $beyanBaglar = siparis_beyan_baglar($pdo, $id, false);
        if ($esle && !$kapali) $beyanOneri = siparis_beyan_oneriler($pdo, $sev, 5);
        if ($esle && $beyanQ !== '') $beyanAra = siparis_beyan_ara($pdo, $beyanQ, 10);
    } catch (PDOException $e) {
        error_log('[siparis_view] beyan: ' . $e->getMessage());
        $beyanHata = true;
    }
}
$yuklemeSonuc = [];
if (isset($_SESSION['siparis_yukleme']) && is_array($_SESSION['siparis_yukleme'])) { $yuklemeSonuc = $_SESSION['siparis_yukleme']; unset($_SESSION['siparis_yukleme']); }

$kalemMap = [];
foreach ($kalemler as $k) $kalemMap[(int)$k['id']] = $k;
/** Alan değeri → ekran metni. */
$goster = function (string $tip, mixed $v): string {
    if ($v === null || $v === '') return '—';
    return match ($tip) { 'tarih' => sipui_tarih((string)$v), 'sayi' => sipui_sayi($v), 'ondalik' => sipui_fiyat($v, null), default => (string)$v };
};
$duzenleAlan = (string)($_GET['duzenle'] ?? '');                       // JS yoksa sunucu tarafı düzenleme formu
$alanIpuclari = ['plakalar' => 'Çekici-Dorse, örn. 33AHU899-33AHU910', 'planlanan_plakalar' => 'Çekici-Dorse', 'navlun_no' => 'Loading List "Transport" numarası (PKL C9)',
    'tur_no' => 'PO / Loading List tur numarası', 'sofor_adi' => 'Elle girilen not; belgeler bu alanı değiştirmez.', 'sofor_telefon' => 'Elle girilen not (7–15 hane); belgeler bu alanı değiştirmez.',
    'gumruk' => 'PO “Customs” — yurt dışı (sınır) gümrüğü', 'yurtici_gumruk' => 'Elle girilir; Siparişler listesinde bu gümrük gösterilir.'];
$alanDuzenleVeri = function (string $alan) use ($sev, $tanimlar, $id, $alanIpuclari): array {
    $t = $tanimlar['sevkiyat'][$alan];
    $ham = $sev[$alan] ?? null;
    return ['varlik' => 'sevkiyat', 'id' => $id, 'alan' => $alan, 'etiket' => $t['etiket'], 'tip' => $t['tip'] === 'metin' && $alan === 'notlar' ? 'notlar' : $t['tip'],
            'deger' => $ham === null ? '' : (string)$ham, 'ipucu' => $alanIpuclari[$alan] ?? '', 'kilitli' => isset($sev['_elle'][$alan]) && $t['otorite'] !== []];   // yalnız-elle alanda (notlar, şoför) "kilidi kaldır" anlamsız: değeri silerdi
};

render_header('Sevkiyat ' . (string)$sev['order_ref']);
siparis_assets();
render_flash();
$mailPklAd = $pkl && $pkl['dosya_adi'] ? (string)$pkl['dosya_adi'] : '';
$mailPklBelge = $pkl && $pkl['dosya_belge_id'] ? (int)$pkl['dosya_belge_id'] : 0;
$eksikHucre = $pkl ? (int)$pkl['eksik_hucre'] : 0;
// v383: mail taslağı (Kime / Bilgi / konu / gövde) — mailto ve ekli .eml AYNI taslaktan
$mailTaslak = siparis_pkl_mail_taslak($poEpostalar, $mailPklAd, (string)$sev['order_ref'], (string)($auth_user['display_name'] ?: $auth_user['username']));
$pklHazir = (int)$sev['var_pkl'] === 1;
?>
<div class="sip-mod sip-view">
  <?= sipui_gorulen_meta((int)$auth_user['id'], $sev) ?>
  <div class="page-head sip-head">
    <div class="sip-head-sol">
      <a class="btn btn-geri" href="siparisler.php">← Siparişler</a>
      <h1 class="sip-h1"><?= h((string)$sev['order_ref']) ?> <?= siparis_durum_badge((string)$sev['durum']) ?></h1>
      <p class="sip-alt-satir muted">
        <?php if ((string)$sev['tur_no'] !== ''): ?>Tur <?= h((string)$sev['tur_no']) ?> · <?php endif; ?>
        <?php if ($sev['cw_hafta'] !== null): ?>CW<?= (int)$sev['cw_hafta'] ?><?= $sev['cw_yil'] !== null ? '/' . (int)$sev['cw_yil'] : '' ?> · <?php endif; ?>
        <?= h((string)$sev['alici_adi'] !== '' ? (string)$sev['alici_adi'] : 'Alıcı bilinmiyor') ?>
        <?php if ((string)$sev['depo'] !== ''): ?> · <span class="sip-depo" title="Depo"><?= h((string)$sev['depo']) ?></span><?php endif; ?>
      </p>
      <?php if ($kardesler): ?>
        <p class="sip-alt-satir muted">Aynı PO'daki diğer sevkiyatlar:
          <?php foreach ($kardesler as $i => $kd): ?><?= $i ? ', ' : ' ' ?><a href="siparis_view.php?id=<?= (int)$kd['id'] ?>"><?= h((string)$kd['order_ref']) ?></a><?php endforeach; ?></p>
      <?php endif; ?>
    </div>
    <div class="page-head-actions sip-eylemler">
      <?php if ($yaz): ?><button type="button" class="btn btn-primary" data-sip-ac="sipYukleModal">📥 Belge yükle</button>
      <?php else: ?><button type="button" class="btn" disabled title="Gerekli yetki: siparis.write">📥 Belge yükle</button><?php endif; ?>
      <?php if ($belgeler): ?><a class="btn" href="siparis_zip.php?id=<?= $id ?>">🗜 Evrak takımı (ZIP)</a>
      <?php else: ?><button type="button" class="btn" disabled title="Henüz belge yok">🗜 Evrak takımı (ZIP)</button><?php endif; ?>
      <a class="btn" href="siparis_pkl.php?id=<?= $id ?>">📄 PKL</a>
      <?php if ($sil): ?>
        <div class="pc-kebab-wrap">
          <button class="btn pc-kebab" type="button" title="Diğer işlemler" aria-label="Diğer işlemler">⋮</button>
          <div class="pc-dropdown" hidden><button type="button" class="pc-drop-danger" data-sip-ac="sipSilModal">🗑 Sevkiyatı sil</button></div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?= sipui_yukleme_sonuc_html($yuklemeSonuc) ?>

  <!-- 2) Durum adımları -->
  <section class="card sip-durum" id="durum" aria-label="Süreç durumu">
    <?php if ($iptal): ?>
      <div class="sip-iptal-bant" role="status">✖ İptal edildi<?= (string)$sev['iptal_nedeni'] !== '' ? ' — ' . h((string)$sev['iptal_nedeni']) : '' ?></div>
    <?php endif; ?>
    <ol class="sip-steps<?= $iptal ? ' sip-steps--iptal' : '' ?>">
      <?php foreach ($durumlar as $dk => $dv): if ($dk === 'iptal') continue;
          $bitti = !$iptal && $dv['sira'] < $sira; $suAn = !$iptal && $dv['sira'] === $sira; $sonraki = !$iptal && !$kapali && $dv['sira'] === $sira + 1;
          $zaman = match ($dk) {
              'yola_cikti' => $sev['yola_cikis_at'],
              'mail_gonderildi' => $sev['mail_gonderildi_at'],
              'evrak_gonderildi' => (string)$sev['durum'] === 'evrak_gonderildi' ? $sev['durum_at'] : null,   // ayrı kolon yok: durum_at
              default => null,
          };
      ?>
        <li class="sip-step<?= $bitti ? ' sip-step--bitti' : '' ?><?= $suAn ? ' sip-step--su-an' : '' ?><?= $sonraki ? ' sip-step--sonraki' : '' ?>"<?= $suAn ? ' aria-current="step"' : '' ?>>
          <span class="sip-step-nokta" aria-hidden="true"><?= $bitti || ($suAn && $kapali) ? '✓' : (int)$dv['sira'] ?></span>
          <span class="sip-step-et"><?= h($dv['ikon'] . ' ' . $dv['etiket']) ?></span>
          <span class="sip-sr"><?= $bitti ? ' (tamamlandı)' : ($suAn ? ' (şu anki adım)' : ($sonraki ? ' (sıradaki adım)' : '')) ?></span>
          <?php if ($zaman): ?><span class="sip-step-zaman"><?= h(sipui_zaman((string)$zaman)) ?></span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
    <?php $ilr = siparis_ilerleme($sev); ?>
    <p class="sip-adim-mobil"><?= $iptal ? 'İptal' : 'Adım ' . $sira . '/' . (int)$ilr['toplam'] . ' · ' . h($durumlar[(string)$sev['durum']]['etiket'] ?? '') ?></p>
    <?php if ($yaz): ?>
    <div class="sip-hizli">
      <?php if (!$kapali && (string)$sev['durum'] === 'siparis_alindi'): ?>
        <form method="post" action="siparis_kaydet.php" class="sip-satir-form" data-sip-tek><?= sipui_csrf() ?><input type="hidden" name="islem" value="durum"><input type="hidden" name="sid" value="<?= $id ?>"><input type="hidden" name="hedef" value="hazirlik"><input type="hidden" name="donus" value="durum">
          <button type="submit" class="btn">🧺 Hazırlık başladı</button></form>
      <?php endif; ?>
      <?php if (!$kapali && $sira <= 3): ?>
        <form method="post" action="siparis_kaydet.php" class="sip-satir-form" data-sip-tek data-sip-onay="Araç yola çıktı olarak işaretlensin mi?"><?= sipui_csrf() ?><input type="hidden" name="islem" value="durum"><input type="hidden" name="sid" value="<?= $id ?>"><input type="hidden" name="hedef" value="yola_cikti"><input type="hidden" name="donus" value="durum">
          <button type="submit" class="btn">🛣 Yola çıktı ✓</button></form>
      <?php endif; ?>
      <?php if (!$kapali && (string)$sev['durum'] !== 'mail_gonderildi'): ?>
        <?php if ($pklHazir): ?><button type="button" class="btn btn-secondary" data-sip-ac="sipMailModal">✅ Mail gönderildi</button>
        <?php else: ?><span class="sip-pasif-grup"><button type="button" class="btn" disabled aria-describedby="sipMailNeden">✅ Mail gönderildi</button><span class="sip-pasif-neden" id="sipMailNeden">Önce PKL oluşturun ya da yükleyin.</span></span><?php endif; ?>
      <?php endif; ?>
      <?php if ((string)$sev['durum'] === 'mail_gonderildi'): ?>
        <button type="button" class="btn btn-secondary" data-sip-ac="sipEvrakModal">📦 Evrak takımı gönderildi</button>
      <?php endif; ?>
      <?php if (!$kapali): ?>
        <button type="button" class="btn btn-danger" data-sip-ac="sipIptalModal">✖ İptal</button>
      <?php endif; ?>
      <button type="button" class="btn btn-ghost" data-sip-ac="sipDurumModal">⇄ Durumu değiştir</button>
    </div>
    <?php else: ?>
      <p class="sip-bilgi" role="note">Durumu değiştirmek için siparis.write yetkisi gerekir.</p>
    <?php endif; ?>
  </section>

  <div class="sip-grid">
    <div class="sip-col sip-col-ana">
      <!-- 3) Uyarılar -->
      <?php if ($sev['_uyarilar']): ?>
      <section class="sip-uyari sip-o2" id="uyarilar" aria-label="Uyarılar">
        <h2>⚠ Uyarılar <span class="sip-say"><?= count($sev['_uyarilar']) ?></span></h2>
        <ul><?php foreach ($sev['_uyarilar'] as $u): ?><li><?= h((string)($u['mesaj'] ?? '')) ?></li><?php endforeach; ?></ul>
      </section>
      <?php endif; ?>

      <!-- 5) Sevkiyat bilgileri -->
      <section class="card sip-o4" id="bilgi" aria-labelledby="sipBilgiBas">
        <div class="card-head"><h2 id="sipBilgiBas">Sevkiyat bilgileri</h2><span class="sip-soluk">Elle girilen değer kilitlenir; belgeler onu ezmez.</span></div>
        <?php if ($yaz && $duzenleAlan !== '' && isset($tanimlar['sevkiyat'][$duzenleAlan]) && ($soforHazir || !in_array($duzenleAlan, siparis_sofor_alanlari(), true)) && ($yiHazir || $duzenleAlan !== 'yurtici_gumruk')): $dv = $alanDuzenleVeri($duzenleAlan); ?>
          <form method="post" action="siparis_kaydet.php" class="sip-edit-inline" data-sip-tek>
            <?= sipui_csrf() ?><input type="hidden" name="islem" value="alan"><input type="hidden" name="varlik" value="sevkiyat"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="alan" value="<?= h($duzenleAlan) ?>"><input type="hidden" name="donus" value="bilgi">
            <label><?= h($dv['etiket']) ?>
              <?php if ($dv['tip'] === 'notlar'): ?><textarea name="deger" rows="4" maxlength="2000"><?= h($dv['deger']) ?></textarea>
              <?php else: ?><input type="<?= $dv['tip'] === 'tarih' ? 'date' : ($dv['tip'] === 'telefon' ? 'tel' : 'text') ?>" name="deger" value="<?= h($dv['deger']) ?>"<?= $dv['tip'] === 'plaka' ? ' autocapitalize="characters"' : '' ?><?= $dv['tip'] === 'telefon' ? ' inputmode="tel" autocomplete="off"' : '' ?>><?php endif; ?></label>
            <?php if ($dv['ipucu'] !== ''): ?><p class="muted"><?= h($dv['ipucu']) ?></p><?php endif; ?>
            <div class="sip-edit-btn"><button type="submit" class="btn btn-primary">Kaydet</button><a class="btn" href="siparis_view.php?id=<?= $id ?>#bilgi">Vazgeç</a></div>
          </form>
        <?php endif; ?>
        <?php if (!$soforHazir && is_admin()): ?><p class="sip-bilgi" role="note">Şoför adı / telefonu alanları için <a href="migrate.php#siparis-sofor">Şema Güncelleme</a> sayfasındaki “Siparişler — Şoför alanları” kartını bir kez çalıştırın.</p><?php endif; ?>
        <?php if (!$yiHazir && is_admin()): ?><p class="sip-bilgi" role="note">Yurt içi gümrük alanı için <a href="migrate.php#siparis-yurtici-gumruk">Şema Güncelleme</a> sayfasındaki “Siparişler — Yurt içi gümrük” kartını bir kez çalıştırın.</p><?php endif; ?>
        <?php foreach (sipui_alan_gruplari($soforHazir, $yiHazir) as $grup => $alanlar): ?>
          <h3 class="sip-grup-bas"><?= h($grup) ?></h3>
          <dl class="sip-dl">
            <?php foreach ($alanlar as $alan): $t = $tanimlar['sevkiyat'][$alan]; $yalnizElle = $t['otorite'] === [];   // şoför: belge kaynağı yok → "Elle"/kilit rozeti ve "Kilidi kaldır" gösterilmez
                $kilit = !$yalnizElle && isset($sev['_elle'][$alan]); $kaynak = $yalnizElle ? null : ($kilit ? 'elle' : ($sev['_kaynak'][$alan]['_s'] ?? null)); ?>
              <div class="sip-dl-satir" data-alan="<?= h($alan) ?>">
                <dt><?= h($t['etiket']) ?></dt>
                <dd>
                  <span class="sip-dd-deger<?= $sev[$alan] === null || $sev[$alan] === '' ? ' sip-bos' : '' ?>"><?= h($goster($t['tip'], $sev[$alan])) ?></span>
                  <?= sipui_kaynak_chip($kaynak, $kilit) ?>
                  <?php if ($yaz): ?>
                    <a class="sip-edit" href="siparis_view.php?id=<?= $id ?>&amp;duzenle=<?= h($alan) ?>#bilgi" data-sip-alan="<?= sipui_json_attr($alanDuzenleVeri($alan)) ?>" title="<?= h($t['etiket']) ?> alanını düzenle">✎<span class="sip-sr"> Düzenle</span></a>
                    <?php if ($kilit): ?>
                      <form method="post" action="siparis_kaydet.php" class="sip-satir-form" data-sip-tek><?= sipui_csrf() ?><input type="hidden" name="islem" value="kilit_ac"><input type="hidden" name="varlik" value="sevkiyat"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="alan" value="<?= h($alan) ?>"><input type="hidden" name="donus" value="bilgi">
                        <button type="submit" class="sip-link" title="Elle girilen değerin kilidini kaldırıp belgedeki değere dön">Kilidi kaldır</button></form>
                    <?php endif; ?>
                  <?php endif; ?>
                  <?php if ($alan === 'nakliyeci' && ((string)$sev['planlanan_nakliyeci'] !== '' || (string)$sev['planlanan_plakalar'] !== '')): ?>
                    <div class="sip-planlanan">Planlanan: <?= h(trim((string)$sev['planlanan_nakliyeci'] . ((string)$sev['planlanan_plakalar'] !== '' ? ' · ' . $sev['planlanan_plakalar'] : ''))) ?></div>
                  <?php endif; ?>
                </dd>
              </div>
            <?php endforeach; ?>
          </dl>
        <?php endforeach; ?>
        <h3 class="sip-grup-bas">Not</h3>
        <?php $nk = isset($sev['_elle']['notlar']); ?>
        <div class="sip-not">
          <div class="sip-not-metin<?= (string)$sev['notlar'] === '' ? ' sip-bos' : '' ?>"><?= (string)$sev['notlar'] !== '' ? nl2br(h((string)$sev['notlar'])) : 'Not yok' ?></div>
          <?php if ($yaz): ?><a class="sip-edit" href="siparis_view.php?id=<?= $id ?>&amp;duzenle=notlar#bilgi" data-sip-alan="<?= sipui_json_attr($alanDuzenleVeri('notlar')) ?>" title="Notu düzenle">✎<span class="sip-sr"> Notu düzenle</span></a><?php endif; ?>
        </div>
      </section>

      <!-- 6) Kalemler -->
      <section class="card sip-o5" id="kalemler" aria-labelledby="sipKalemBas">
        <div class="card-head"><h2 id="sipKalemBas">Kalemler (kalibre bazında)</h2><span class="sip-soluk">Etkin = elle &gt; PC &gt; LL &gt; PO</span></div>
        <?php if (!$kalemler): ?>
          <div class="empty sip-bos-kutu"><p>Henüz kalem yok. PO ya da Loading List yüklenince kalemler oluşur.</p></div>
        <?php else:
            $top = ['po_palet' => 0, 'po_koli' => 0, 'll_palet' => 0, 'll_koli' => 0, 'pc_koli' => 0, 'etkin_palet' => 0, 'etkin_koli' => 0];
            foreach ($kalemler as $k) foreach ($top as $kk => $_) $top[$kk] += (int)($k[$kk] ?? 0);
            $varKaynak = ['po' => (int)$sev['var_po'] === 1, 'll' => (int)$sev['var_ll'] === 1, 'pc' => (int)$sev['var_pc'] === 1];
            $kaynakKoli = ['po' => $top['po_koli'], 'll' => $top['ll_koli'], 'pc' => $top['pc_koli']];
            $mevcutT = array_filter($kaynakKoli, fn($s) => $varKaynak[$s] ?? false, ARRAY_FILTER_USE_KEY);
            $toplamFarkli = count($mevcutT) > 1 && count(array_unique($mevcutT)) > 1;
            $kalemFark = function (array $k): bool {
                $d = array_filter([$k['po_koli'], $k['ll_koli'], $k['pc_koli']], fn($x) => $x !== null);
                return count(array_unique($d)) > 1 || (isset($k['_kaynak']['_yok']) && $d);
            };
            $kalemDuzenleVeri = function (array $k): array {
                $v = ['id' => (int)$k['id'], '_yazi:kalem_ad' => 'Kalibre ' . $k['kalibre'] . ((string)$k['urun'] !== '' ? ' · ' . $k['urun'] : '')];
                foreach (sipui_kalem_alanlari() as $a) $v['alanlar[' . $a . ']'] = $k[$a] === null ? '' : (string)$k[$a];
                return $v;
            };
        ?>
        <div class="table-wrap pc-only">
          <table class="data-table sip-table sip-kalem-tablo">
            <thead><tr><th>Kalibre</th><th>Artikel · çeşit · PF</th><th class="num">PO pal/koli</th><th class="num">LL pal/koli</th><th class="num">PC koli</th><th class="num">Etkin pal/koli</th><th class="num">Fiyat</th><th><span class="sip-sr">İşlem</span></th></tr></thead>
            <tbody>
            <?php foreach ($kalemler as $k): $fark = $kalemFark($k); $ek = isset($k['_elle']['etkin_palet']) || isset($k['_elle']['etkin_koli']); ?>
              <tr class="<?= $fark ? 'sip-row--fark' : '' ?>">
                <td><strong><?= h((string)$k['kalibre']) ?></strong><div class="sip-soluk sip-kes"><?= h((string)$k['urun']) ?></div><?php if ($fark): ?><span class="sip-fark-rozet" title="Belgeler arasında miktar farklı">⚠ Bölüm farklı</span><?php endif; ?></td>
                <td class="sip-kalem-meta"><?= h((string)$k['artikel_no'] !== '' ? (string)$k['artikel_no'] : '—') ?><div class="sip-soluk"><?= h((string)$k['cesit'] !== '' ? (string)$k['cesit'] : '—') ?> · PF <?= h(sipui_sayi($k['pf'])) ?></div></td>
                <td class="num"><?= h(sipui_sayi($k['po_palet'])) ?> / <?= h(sipui_sayi($k['po_koli'])) ?></td>
                <td class="num"><?= h(sipui_sayi($k['ll_palet'])) ?> / <?= h(sipui_sayi($k['ll_koli'])) ?></td>
                <td class="num"><?= h(sipui_sayi($k['pc_koli'])) ?></td>
                <td class="num sip-etkin"><strong><?= h(sipui_sayi($k['etkin_palet'])) ?> / <?= h(sipui_sayi($k['etkin_koli'])) ?></strong>
                  <?php if ($k['etkin_kaynak']): ?><?= sipui_kaynak_chip($ek ? 'elle' : (string)$k['etkin_kaynak'], $ek) ?><?php endif; ?></td>
                <td class="num"><?= h(sipui_fiyat($k['fiyat'], $k['para_birimi'])) ?><?php if ($k['fiyat'] !== null && $k['fiyat_birim']): ?> <span class="sip-soluk">/<?= h((string)$k['fiyat_birim']) ?></span><?php endif; ?>
                  <?php if ((int)$k['fiyat_miras'] === 1): ?><span class="sip-miras" title="Fiyat önceki bir onaydan miras alındı">ⓘ miras</span><?php endif; ?></td>
                <td class="actions-col"><?php if ($yaz): ?><button type="button" class="btn btn-sm" data-sip-ac="sipKalemModal" data-sip-doldur="<?= sipui_json_attr($kalemDuzenleVeri($k)) ?>" aria-label="Kalibre <?= h((string)$k['kalibre']) ?> düzenle">✎ Düzenle</button><?php endif; ?></td>
              </tr>
              <?php if (!empty($k['_yerler'])): ?>
              <tr class="sip-yer-tr"><td colspan="8">
                <details><summary>Boşaltma yerleri</summary>
                  <div class="sip-yerler">
                  <?php foreach (['po' => 'PO', 'll' => 'LL'] as $yk => $ye): if (empty($k['_yerler'][$yk])) continue; ?>
                    <div><strong><?= h($ye) ?></strong>
                      <ul><?php foreach ($k['_yerler'][$yk] as $y): ?><li><?= h((string)($y['ad'] ?? '')) ?><?= !empty($y['kod']) ? ' (' . h((string)$y['kod']) . ')' : '' ?><?= isset($y['seq']) ? ' · sıra ' . (int)$y['seq'] : '' ?> — <?= h(sipui_sayi($y['palet'] ?? null)) ?> palet · <?= h(sipui_sayi($y['koli'] ?? null)) ?> koli<?= !empty($y['bosaltma']) ? ' · ' . h(sipui_tarih((string)$y['bosaltma'])) : '' ?></li><?php endforeach; ?></ul></div>
                  <?php endforeach; ?>
                  </div>
                </details></td></tr>
              <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr>
              <th>Toplam</th><th></th>
              <th class="num"><?= h(sipui_sayi($top['po_palet'])) ?> / <?= h(sipui_sayi($top['po_koli'])) ?></th>
              <th class="num"><?= h(sipui_sayi($top['ll_palet'])) ?> / <?= h(sipui_sayi($top['ll_koli'])) ?></th>
              <th class="num"><?= h(sipui_sayi($top['pc_koli'])) ?></th>
              <th class="num"><?= h(sipui_sayi($top['etkin_palet'])) ?> / <?= h(sipui_sayi($top['etkin_koli'])) ?></th>
              <th></th><th></th>
            </tr></tfoot>
          </table>
        </div>
        <?php if ($toplamFarkli): ?><p class="sip-uyari-satir">⚠ Kaynak toplamları farklı: <?php foreach ($mevcutT as $s => $t): ?><?= strtoupper($s) ?> <?= h(sipui_sayi($t)) ?> koli · <?php endforeach; ?>PKL'de kullanılacak miktarı kontrol edin.</p><?php endif; ?>

        <div class="card-list mobile-only">
          <?php foreach ($kalemler as $k): $fark = $kalemFark($k); $ek = isset($k['_elle']['etkin_palet']) || isset($k['_elle']['etkin_koli']); ?>
            <article class="sip-kalem-kart<?= $fark ? ' sip-row--fark' : '' ?>">
              <header><strong>Kalibre <?= h((string)$k['kalibre']) ?></strong> <span class="sip-soluk"><?= h((string)$k['urun']) ?><?= (string)$k['cesit'] !== '' ? ' · ' . h((string)$k['cesit']) : '' ?></span>
                <?php if ($fark): ?><span class="sip-fark-rozet">⚠ Bölüm farklı</span><?php endif; ?></header>
              <div class="sip-mini-izgara" role="table" aria-label="Kalibre <?= h((string)$k['kalibre']) ?> miktarları">
                <span role="row" class="sip-mini-bas"><span role="columnheader">Kaynak</span><span role="columnheader" class="num">Palet</span><span role="columnheader" class="num">Koli</span></span>
                <span role="row"><span role="cell">PO</span><span role="cell" class="num"><?= h(sipui_sayi($k['po_palet'])) ?></span><span role="cell" class="num"><?= h(sipui_sayi($k['po_koli'])) ?></span></span>
                <span role="row"><span role="cell">LL</span><span role="cell" class="num"><?= h(sipui_sayi($k['ll_palet'])) ?></span><span role="cell" class="num"><?= h(sipui_sayi($k['ll_koli'])) ?></span></span>
                <span role="row"><span role="cell">PC</span><span role="cell" class="num">—</span><span role="cell" class="num"><?= h(sipui_sayi($k['pc_koli'])) ?></span></span>
                <span role="row" class="sip-mini-etkin"><span role="cell">Etkin <?= $ek ? '🔒' : '' ?></span><span role="cell" class="num"><?= h(sipui_sayi($k['etkin_palet'])) ?></span><span role="cell" class="num"><?= h(sipui_sayi($k['etkin_koli'])) ?></span></span>
              </div>
              <div class="sip-kalem-alt"><span>Fiyat: <?= h(sipui_fiyat($k['fiyat'], $k['para_birimi'])) ?><?= (int)$k['fiyat_miras'] === 1 ? ' <span class="sip-miras">ⓘ miras</span>' : '' ?></span>
                <?php if ($yaz): ?><button type="button" class="btn btn-sm" data-sip-ac="sipKalemModal" data-sip-doldur="<?= sipui_json_attr($kalemDuzenleVeri($k)) ?>">✎ Düzenle</button><?php endif; ?></div>
            </article>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </section>

      <!-- 7) Belgeler -->
      <section class="card sip-o6" id="belgeler" aria-labelledby="sipBelgeBas">
        <div class="card-head">
          <h2 id="sipBelgeBas">Belgeler</h2>
          <div class="sip-zip-eylem">
            <label class="sip-onay-satir"><input type="checkbox" data-sip-eski> Eski sürümler dahil</label>
            <a class="btn btn-sm" href="siparis_zip.php?id=<?= $id ?>" data-sip-zip-hepsi<?= $belgeler ? '' : ' aria-disabled="true"' ?>>🗜 Tümünü ZIP</a>
            <button type="button" class="btn btn-sm" data-sip-zip-secili data-id="<?= $id ?>" disabled title="Önce listeden belge seçin">🗜 Seçilenleri ZIP</button>
          </div>
        </div>
        <?php if (!$belgeler): ?>
          <div class="empty sip-bos-kutu"><p>Bu sevkiyata bağlı belge yok.</p><?php if ($yaz): ?><button type="button" class="btn btn-primary" data-sip-ac="sipYukleModal">📥 Belge yükle</button><?php endif; ?></div>
        <?php else:
            $gruplu = [];
            foreach ($belgeler as $b) $gruplu[(string)$b['tur']][] = $b;
            $tSira = ['po', 'll', 'pc', 'pkl', 'diger', 'bilinmiyor'];
            foreach ($tSira as $tur): if (empty($gruplu[$tur])) continue; ?>
          <h3 class="sip-grup-bas"><?= h(siparis_belge_tur_etiketleri()[$tur]) ?></h3>
          <ul class="sip-belgeler">
            <?php foreach ($gruplu[$tur] as $b):
                $eski = (int)$b['gecerli'] === 0; $ext = strtolower(pathinfo((string)$b['disk_adi'], PATHINFO_EXTENSION)); ?>
              <li class="sip-belge<?= $eski ? ' sip-belge--eski' : '' ?>">
                <label class="sip-belge-sec"><input type="checkbox" data-sip-zip value="<?= (int)$b['id'] ?>" data-eski="<?= $eski ? '1' : '0' ?>" aria-label="<?= h((string)$b['orijinal_ad']) ?> seç"></label>
                <span class="sip-belge-ikon" aria-hidden="true"><?= $ext === 'pdf' ? '📕' : ($ext === 'xlsx' ? '📊' : '🖼') ?></span>
                <div class="sip-belge-bilgi">
                  <a class="sip-kes sip-belge-ad" href="siparis_belge.php?id=<?= (int)$b['id'] ?>" title="<?= h((string)$b['orijinal_ad']) ?>"><?= h((string)$b['orijinal_ad']) ?></a>
                  <span class="sip-belge-meta">
                    <?= sipui_tur_rozeti((string)$b['tur']) ?>
                    <?php if ($eski): ?><span class="sip-eski-rozet">eski sürüm</span><?php endif; ?>
                    <?php if ($b['kaynak'] === 'uretim'): ?><span class="sip-uretim-rozet">sistemde üretildi</span><?php endif; ?>
                    <?php if ($b['kaynak'] === 'mail'): ?><span class="sip-mail-rozet" title="Mail Merkezi'nden alındı">📧 mailden</span><?php endif; ?>
                    <?php if ((int)$b['uyari_sayisi'] > 0): ?><span class="sip-uy-say" title="Belgede <?= (int)$b['uyari_sayisi'] ?> uyarı var">⚠ <?= (int)$b['uyari_sayisi'] ?></span><?php endif; ?>
                    <span class="sip-soluk"><?= h(sipui_zaman((string)($b['belge_tarihi'] ?: $b['created_at']))) ?> · <?= h(sipui_boyut((int)$b['boyut'])) ?></span>
                  </span>
                </div>
                <div class="sip-belge-eylem">
                  <a class="btn btn-sm" href="siparis_belge.php?id=<?= (int)$b['id'] ?>" data-sip-onizle data-id="<?= (int)$b['id'] ?>" data-ext="<?= h($ext) ?>" data-ad="<?= h((string)$b['orijinal_ad']) ?>">Önizle</a>
                  <a class="btn btn-sm" href="siparis_dosya.php?id=<?= (int)$b['id'] ?>&amp;dl=1">İndir</a>
                  <?php if ($sil): ?>
                    <form method="post" action="siparis_kaydet.php" class="sip-satir-form" data-sip-onay="Bu belge silinsin mi? Uygulanmış değerler geri alınmaz.">
                      <?= sipui_csrf() ?><input type="hidden" name="islem" value="belge_sil"><input type="hidden" name="belge_id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="donus_sid" value="<?= $id ?>">
                      <button type="submit" class="btn btn-sm btn-danger" aria-label="<?= h((string)$b['orijinal_ad']) ?> sil">Sil</button></form>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endforeach; endif; ?>
      </section>

      <!-- 8) PKL -->
      <section class="card sip-o7" id="pkl" aria-labelledby="sipPklBas">
        <div class="card-head"><h2 id="sipPklBas">Packing List (PKL)</h2>
          <?php
            $pklDurumEt = $pkl === null ? 'Yok' : match ((string)$pkl['durum']) { 'taslak' => 'Taslak', 'uretildi' => 'Üretildi' . ($eksikHucre > 0 ? ' · kısmi: ' . $eksikHucre . ' boş hücre' : ''), 'yuklendi' => 'Yüklendi', default => (string)$pkl['durum'] };
          ?>
          <span class="sip-pkl-durum sip-pkl-durum--<?= $pkl === null ? 'yok' : h((string)$pkl['durum']) ?><?= $eksikHucre > 0 ? ' sip-pkl-durum--kismi' : '' ?>"><?= h($pklDurumEt) ?></span>
        </div>
        <?php if ($pkl && $pkl['belge_no']): ?>
          <dl class="sip-dl sip-dl--kisa">
            <div class="sip-dl-satir"><dt>Belge no</dt><dd><?= h((string)$pkl['belge_no']) ?></dd></div>
            <div class="sip-dl-satir"><dt>Palet aralığı</dt><dd><?= $pkl['palet_bas'] !== null ? h((string)$pkl['palet_bas'] . ' – ' . (string)$pkl['palet_bit']) : '—' ?></dd></div>
            <div class="sip-dl-satir"><dt>Son üretim</dt><dd><?= h(sipui_zaman((string)$pkl['uretildi_at'])) ?><?= $pklKullanici !== '' ? ' · ' . h($pklKullanici) : '' ?><?= (int)$pkl['uretim_sayisi'] > 1 ? ' · ' . (int)$pkl['uretim_sayisi'] . '. üretim' : '' ?></dd></div>
            <?php if ($pkl['dosya_adi']): ?><div class="sip-dl-satir"><dt>Dosya</dt><dd class="sip-kes"><?= h((string)$pkl['dosya_adi']) ?></dd></div><?php endif; ?>
          </dl>
        <?php else: ?>
          <p class="muted"><?= (int)$sev['var_ll'] === 1 ? 'Henüz PKL oluşturulmadı.' : 'PKL için önce Loading List (navlun numarası) yüklenmeli.' ?></p>
        <?php endif; ?>
        <div class="sip-pkl-eylem">
          <?php if ($yaz): ?><a class="btn btn-primary" href="siparis_pkl.php?id=<?= $id ?>"><?= $pkl && $pkl['dosya_belge_id'] ? '📄 PKL düzenle / yeniden oluştur' : '📄 PKL oluştur' ?></a>
          <?php else: ?><a class="btn" href="siparis_pkl.php?id=<?= $id ?>">📄 PKL'yi görüntüle</a><?php endif; ?>
          <?php if ($pkl && $pkl['dosya_belge_id']): ?><a class="btn" href="siparis_dosya.php?id=<?= (int)$pkl['dosya_belge_id'] ?>&amp;dl=1">⬇ İndir</a><?php endif; ?>
        </div>
        <?php
          $pklOn = null;
          if ($pkl && $pkl['dosya_belge_id']) {
              $pb = $pdo->prepare('SELECT * FROM siparis_belgeler WHERE id = ? AND deleted_at IS NULL');
              $pb->execute([(int)$pkl['dosya_belge_id']]);
              $pbr = $pb->fetch(PDO::FETCH_ASSOC);
              $pbYol = $pbr ? siparis_dosya_yolu($pbr) : null;
              if ($pbYol !== null) $pklOn = siparis_xlsx_onizleme($pbYol, 6);
          }
        ?>
        <?php if ($pklOn && $pklOn['sutunlar']): ?>
          <details class="sip-pkl-onizle"><summary>Hücre önizleme (ilk <?= count($pklOn['satirlar']) ?> / <?= (int)$pklOn['satir_sayisi'] ?> satır)</summary>
            <dl class="sip-dl sip-dl--kisa"><?php foreach ($pklOn['baslik'] as $bk => $bv): ?><div class="sip-dl-satir"><dt><?= h((string)$bk) ?></dt><dd><?= h((string)$bv) ?></dd></div><?php endforeach; ?></dl>
            <div class="table-wrap"><table class="data-table small sip-table"><thead><tr><?php foreach ($pklOn['sutunlar'] as $c): ?><th><?= h((string)$c) ?></th><?php endforeach; ?></tr></thead>
              <tbody><?php foreach ($pklOn['satirlar'] as $row): ?><tr><?php foreach ($pklOn['sutunlar'] as $c): ?><td><?= h((string)($row[$c] ?? '')) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div>
          </details>
        <?php endif; ?>
      </section>
    </div>

    <div class="sip-col sip-col-yan">
      <!-- 4) Çatışmalar -->
      <?php if ($acikCat || $cozulenCat): ?>
      <section class="card sip-catisma sip-o3" id="catismalar" aria-labelledby="sipCatBas">
        <div class="card-head"><h2 id="sipCatBas">⚠ Çatışmalar <span class="sip-say"><?= count($acikCat) ?></span></h2></div>
        <?php if (!$acikCat): ?><p class="muted">Açık çatışma yok.</p><?php endif; ?>
        <?php foreach ($acikCat as $c):
            $kalem = $c['kalem_id'] !== null ? ($kalemMap[(int)$c['kalem_id']] ?? null) : null;
            $varlik = $kalem !== null ? 'kalem' : 'sevkiyat';
            $tip = (string)($tanimlar[$varlik][$c['alan']]['tip'] ?? 'metin');
        ?>
          <div class="sip-cat">
            <div class="sip-cat-bas"><strong><?= h(siparis_alan_etiketi($varlik, (string)$c['alan'])) ?></strong><?= $kalem ? ' <span class="sip-soluk">· kalibre ' . h((string)$kalem['kalibre']) . '</span>' : '' ?></div>
            <div class="sip-cat-karsi">
              <div><span class="sip-soluk">Mevcut</span><br><span class="sip-cat-deger"><?= h($goster($tip, $c['mevcut_deger'])) ?></span> <?= sipui_kaynak_chip($c['mevcut_kaynak']) ?></div>
              <div><span class="sip-soluk">Gelen</span><br><span class="sip-cat-deger"><?= h($goster($tip, $c['gelen_deger'])) ?></span> <?= sipui_kaynak_chip($c['gelen_kaynak']) ?>
                <?php if ($c['belge_id']): ?><a class="sip-link" href="siparis_belge.php?id=<?= (int)$c['belge_id'] ?>">belge</a><?php endif; ?></div>
            </div>
            <?php if ($yaz): ?>
            <div class="sip-cat-eylem">
              <form method="post" action="siparis_kaydet.php" class="sip-satir-form" data-sip-tek><?= sipui_csrf() ?><input type="hidden" name="islem" value="catisma"><input type="hidden" name="catisma_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="karar" value="kabul"><input type="hidden" name="donus" value="catismalar"><button type="submit" class="btn btn-sm btn-primary">Geleni kabul et</button></form>
              <form method="post" action="siparis_kaydet.php" class="sip-satir-form" data-sip-tek><?= sipui_csrf() ?><input type="hidden" name="islem" value="catisma"><input type="hidden" name="catisma_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="karar" value="red"><input type="hidden" name="donus" value="catismalar"><button type="submit" class="btn btn-sm">Mevcudu koru</button></form>
            </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (!$yaz && $acikCat): ?><p class="sip-bilgi" role="note">Çatışmayı çözmek için siparis.write yetkisi gerekir.</p><?php endif; ?>
        <?php if ($cozulenCat): ?>
          <details class="sip-cat-cozulen"><summary>Çözülen çatışmalar (<?= count($cozulenCat) ?>)</summary>
            <ul><?php foreach ($cozulenCat as $c): ?><li><?= h(siparis_alan_etiketi($c['kalem_id'] !== null ? 'kalem' : 'sevkiyat', (string)$c['alan'])) ?>:
              <?= $c['durum'] === 'kabul' ? 'gelen kabul edildi' : 'mevcut korundu' ?> (<?= h((string)$c['mevcut_deger']) ?> ↔ <?= h((string)$c['gelen_deger']) ?>) <span class="sip-soluk"><?= h(sipui_zaman((string)$c['cozuldu_at'])) ?></span></li><?php endforeach; ?></ul></details>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <!-- 9) Beyan -->
      <?php if ($beyanOku): ?>
      <section class="card sip-o8" id="beyan" aria-labelledby="sipBeyanBas">
        <div class="card-head"><h2 id="sipBeyanBas">🏛 Gümrük beyanı</h2></div>
        <?php if ($beyanHata): ?><p class="sip-hata-metin">Beyan verisi okunamadı.</p><?php endif; ?>
        <?php if (!$beyanBaglar): ?><p class="muted">Bu sevkiyata bağlı beyan yok.</p><?php endif; ?>
        <?php foreach ($beyanBaglar as $bg): $b = $bg['beyan']; ?>
          <div class="sip-beyan-kart">
            <?php if ($b): ?>
              <div class="sip-beyan-ust"><a href="beyan_view.php?id=<?= (int)$b['id'] ?>"><strong>Beyan #<?= (int)$b['id'] ?></strong></a> <?= beyan_badge_html((string)$b['status']) ?></div>
              <div class="sip-soluk">Parti <?= h((string)($b['party_no'] ?? '—')) ?> · <?= h(trim((string)($b['tractor_plate'] ?? '') . ' ' . (string)($b['vehicle_plate'] ?? ''))) ?: '—' ?></div>
              <div class="sip-soluk"><?= $b['net_kg'] !== null ? h(fmt_kg($b['net_kg'])) . ' kg net' : 'net —' ?> · <?= $b['pallet_count'] !== null ? (int)$b['pallet_count'] . ' palet' : 'palet —' ?></div>
            <?php else: ?><div class="sip-soluk">Beyan #<?= (int)$bg['beyan_id'] ?> (okunamadı)</div><?php endif; ?>
            <?php if ($esle): ?>
              <form method="post" action="siparis_beyan_bagla.php" class="sip-satir-form" data-sip-onay="Beyan bağı kaldırılsın mı?"><?= sipui_csrf() ?><input type="hidden" name="islem" value="kaldir"><input type="hidden" name="bag_id" value="<?= (int)$bg['id'] ?>"><button type="submit" class="btn btn-sm">Bağı kaldır</button></form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if ($esle && !$kapali): ?>
          <?php if ($beyanOneri): ?>
            <h3 class="sip-grup-bas">Öneriler</h3>
            <?php foreach ($beyanOneri as $o): $b = $o['beyan']; ?>
              <div class="sip-beyan-kart">
                <div class="sip-beyan-ust"><a href="beyan_view.php?id=<?= (int)$o['beyan_id'] ?>"><strong>Beyan #<?= (int)$o['beyan_id'] ?></strong></a> <?= beyan_badge_html((string)$b['status']) ?>
                  <span class="sip-puan" title="Eşleşme puanı"><span class="sip-puan-cubuk" style="width:<?= min(100, (int)$o['puan']) ?>%"></span><span class="sip-puan-txt"><?= (int)$o['puan'] ?></span></span></div>
                <div class="sip-soluk">Parti <?= h((string)($b['party_no'] ?? '—')) ?> · <?= h(trim((string)($b['tractor_plate'] ?? '') . ' ' . (string)($b['vehicle_plate'] ?? ''))) ?: '—' ?></div>
                <div class="sip-nedenler"><?= h(implode(' · ', $o['gerekce'])) ?></div>
                <form method="post" action="siparis_beyan_bagla.php" class="sip-satir-form" data-sip-tek><?= sipui_csrf() ?><input type="hidden" name="islem" value="bagla"><input type="hidden" name="sid" value="<?= $id ?>"><input type="hidden" name="beyan_id" value="<?= (int)$o['beyan_id'] ?>"><input type="hidden" name="tur" value="oneri"><input type="hidden" name="gerekce" value="<?= h(implode(', ', $o['gerekce'])) ?>"><button type="submit" class="btn btn-sm btn-primary">Bağla</button></form>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
          <form method="get" action="siparis_view.php#beyan" class="sip-beyan-ara"><input type="hidden" name="id" value="<?= $id ?>">
            <label class="sip-sr" for="sipBeyanQ">Beyan ara</label>
            <input type="search" id="sipBeyanQ" name="beyan_q" value="<?= h($beyanQ) ?>" placeholder="Beyan no, parti no ya da plaka ara…"><button type="submit" class="btn btn-sm">Ara</button></form>
          <?php if ($beyanQ !== ''): ?>
            <?php if (!$beyanAra): ?><p class="muted">"<?= h($beyanQ) ?>" için beyan bulunamadı.</p><?php endif; ?>
            <?php foreach ($beyanAra as $b): ?>
              <div class="sip-beyan-kart">
                <div class="sip-beyan-ust"><a href="beyan_view.php?id=<?= (int)$b['id'] ?>"><strong>Beyan #<?= (int)$b['id'] ?></strong></a> <?= beyan_badge_html((string)$b['status']) ?></div>
                <div class="sip-soluk">Parti <?= h((string)($b['party_no'] ?? '—')) ?> · <?= h(trim((string)($b['tractor_plate'] ?? '') . ' ' . (string)($b['vehicle_plate'] ?? ''))) ?: '—' ?></div>
                <form method="post" action="siparis_beyan_bagla.php" class="sip-satir-form" data-sip-tek><?= sipui_csrf() ?><input type="hidden" name="islem" value="bagla"><input type="hidden" name="sid" value="<?= $id ?>"><input type="hidden" name="beyan_id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="tur" value="elle"><button type="submit" class="btn btn-sm btn-primary">Bağla</button></form>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php elseif (!$esle && $beyanBaglar === []): ?>
          <p class="sip-bilgi" role="note">Beyan bağlamak için siparis.match yetkisi gerekir.</p>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <!-- 10) Geçmiş -->
      <section class="card sip-o9" id="gecmis" aria-labelledby="sipGecmisBas">
        <div class="card-head"><h2 id="sipGecmisBas">Geçmiş</h2></div>
        <?php if (!$olaylar): ?><p class="muted">Kayıtlı olay yok.</p><?php else: ?>
        <ol class="sip-tl">
          <?php foreach ($olaylar as $o): $et = sipui_olay_etiketleri()[(string)$o['tur']] ?? ['•', '']; ?>
            <li>
              <span class="sip-tl-ikon" aria-hidden="true"><?= h($et[0]) ?></span>
              <div class="sip-tl-govde">
                <div><?= h(sipui_olay_cumle($o)) ?><?php if ($o['belge_id']): ?> <a class="sip-link" href="siparis_belge.php?id=<?= (int)$o['belge_id'] ?>">belge</a><?php endif; ?></div>
                <div class="sip-soluk"><?= h(sipui_zaman((string)$o['created_at'])) ?><?= (string)$o['kullanici_adi'] !== '' ? ' · ' . h((string)$o['kullanici_adi']) : '' ?></div>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
        <?php if (!$tumGecmis && count($olaylar) >= 30): ?><p><a class="btn btn-sm" href="siparis_view.php?id=<?= $id ?>&amp;gecmis=tum#gecmis">Tümünü göster</a></p><?php endif; ?>
        <?php endif; ?>
      </section>
    </div>
  </div>

  <?php if ($yaz): ?>
  <!-- Belge yükle (bu sevkiyata) -->
  <div class="pm-overlay" id="sipYukleModal" hidden role="dialog" aria-modal="true" aria-labelledby="sipYukleBas">
    <div class="pm-dialog">
      <form method="post" action="siparis_yukle.php?sevkiyat_id=<?= $id ?>" enctype="multipart/form-data" <?= sipui_yukleme_ozellikleri() ?>>
        <div class="pm-header"><h2 class="pm-title" id="sipYukleBas">Belge yükle · <?= h((string)$sev['order_ref']) ?></h2><button type="button" class="pm-close" data-sip-kapat aria-label="Kapat">✕</button></div>
        <div class="pm-body">
          <?= sipui_csrf() ?><input type="hidden" name="sevkiyat_id" value="<?= $id ?>">
          <?= sipui_dropzone_html('sipDosyaSevkiyat', true) ?>
          <p class="muted">İçeriği başka bir sevkiyata ait belge yine içeriğe göre işlenir; sonuç ekranında size söylenir.</p>
        </div>
        <div class="pm-footer"><button type="button" class="btn" data-sip-kapat>Vazgeç</button><button type="submit" class="btn btn-primary" data-sip-gonder>Yükle ve eşleştir</button></div>
      </form>
    </div>
  </div>

  <!-- Alan düzenle (genel) -->
  <div class="pm-overlay" id="sipAlanModal" hidden role="dialog" aria-modal="true" aria-labelledby="sipAlanBas">
    <div class="pm-dialog">
      <form method="post" action="siparis_kaydet.php" data-sip-tek data-sip-alan-form>
        <div class="pm-header"><h2 class="pm-title" id="sipAlanBas"><span data-sip-yazi="etiket">Alan</span> düzenle</h2><button type="button" class="pm-close" data-sip-kapat aria-label="Kapat">✕</button></div>
        <div class="pm-body sip-form">
          <?= sipui_csrf() ?><input type="hidden" name="varlik" value="sevkiyat"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="alan" value=""><input type="hidden" name="donus" value="bilgi">
          <label><span data-sip-yazi="etiket">Değer</span>
            <input type="text" name="deger" data-sip-girdi="metin" maxlength="255" autocomplete="off">
            <input type="date" name="deger" data-sip-girdi="tarih" disabled hidden>
            <textarea name="deger" data-sip-girdi="notlar" rows="5" maxlength="2000" disabled hidden></textarea>
          </label>
          <p class="muted" data-sip-yazi="ipucu"></p>
          <p class="sip-kilit-not" data-sip-kilit-not hidden>🔒 Bu alan elle girilmiş; belgeler değerini ezmez. Kilidi kaldırırsanız belgedeki değere dönülür.</p>
        </div>
        <div class="pm-footer sip-alan-footer">
          <button type="submit" class="btn btn-primary sip-sira-son" name="islem" value="alan">Kaydet</button>
          <button type="submit" class="btn sip-sira-orta" name="islem" value="kilit_ac" data-sip-kilit-btn hidden formnovalidate>🔓 Kilidi kaldır</button>
          <button type="button" class="btn sip-sira-ilk" data-sip-kapat>Vazgeç</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Kalem düzenle -->
  <div class="pm-overlay" id="sipKalemModal" hidden role="dialog" aria-modal="true" aria-labelledby="sipKalemBaslik">
    <div class="pm-dialog">
      <form method="post" action="siparis_kaydet.php" data-sip-tek>
        <div class="pm-header"><h2 class="pm-title" id="sipKalemBaslik">Kalem düzenle · <span data-sip-yazi="kalem_ad"></span></h2><button type="button" class="pm-close" data-sip-kapat aria-label="Kapat">✕</button></div>
        <div class="pm-body sip-form">
          <?= sipui_csrf() ?><input type="hidden" name="islem" value="alan"><input type="hidden" name="varlik" value="kalem"><input type="hidden" name="id" value=""><input type="hidden" name="donus" value="kalemler">
          <div class="sip-iki-kolon">
            <label>Etkin palet<input type="text" inputmode="numeric" name="alanlar[etkin_palet]" autocomplete="off"></label>
            <label>Etkin koli<input type="text" inputmode="numeric" name="alanlar[etkin_koli]" autocomplete="off"></label>
          </div>
          <p class="muted">Etkin değerleri boş bırakırsanız hesaplanan değer (PC &gt; LL &gt; PO) kullanılır; doldurursanız elle kilitlenir.</p>
          <div class="sip-iki-kolon">
            <label>Palet başına koli (PF)<input type="text" inputmode="numeric" name="alanlar[pf]" autocomplete="off"></label>
            <label>Palet tipi<input type="text" name="alanlar[palet_tipi]" maxlength="10" autocomplete="off"></label>
            <label>Artikel no<input type="text" name="alanlar[artikel_no]" maxlength="20" autocomplete="off"></label>
            <label>Çeşit<input type="text" name="alanlar[cesit]" maxlength="60" autocomplete="off"></label>
          </div>
        </div>
        <div class="pm-footer"><button type="button" class="btn" data-sip-kapat>Vazgeç</button><button type="submit" class="btn btn-primary">Kaydet</button></div>
      </form>
    </div>
  </div>

  <!-- Durumu değiştir -->
  <div class="pm-overlay" id="sipDurumModal" hidden role="dialog" aria-modal="true" aria-labelledby="sipDurumBas">
    <div class="pm-dialog">
      <form method="post" action="siparis_kaydet.php" data-sip-tek data-sip-durum-form data-sip-kapali="<?= $kapali ? '1' : '0' ?>">
        <div class="pm-header"><h2 class="pm-title" id="sipDurumBas">Durumu değiştir</h2><button type="button" class="pm-close" data-sip-kapat aria-label="Kapat">✕</button></div>
        <div class="pm-body sip-form">
          <?= sipui_csrf() ?><input type="hidden" name="islem" value="durum"><input type="hidden" name="sid" value="<?= $id ?>"><input type="hidden" name="donus" value="durum">
          <fieldset class="sip-radyo-grup"><legend>Yeni durum</legend>
            <?php foreach ($durumlar as $dk => $dv):
                // v400: mail yalnız evrak takımından geri alma hedefi; evrak takımı yalnız kendi düğmesiyle (onay ister)
                if ($dk === 'evrak_gonderildi' || $dk === (string)$sev['durum'] || ($dk === 'mail_gonderildi' && (string)$sev['durum'] !== 'evrak_gonderildi')) continue;
                $dEt = $dk === 'mail_gonderildi' ? '✅ Mail gönderildi (evrak takımını geri al)' : $dv['ikon'] . ' ' . $dv['etiket']; ?>
              <label class="sip-radyo"><input type="radio" name="hedef" value="<?= h($dk) ?>" required> <?= h($dEt) ?></label>
            <?php endforeach; ?>
          </fieldset>
          <p class="muted">"Mail gönderildi" ve "Evrak takımı" için ayrı düğmeleri kullanın (onay isterler).</p>
          <label data-sip-gerekce-kap hidden>Gerekçe <span class="muted">(iptal ya da kapanmış sevkiyatı yeniden açmak için zorunlu)</span>
            <textarea name="gerekce" rows="3" maxlength="255" data-sip-gerekce></textarea></label>
        </div>
        <div class="pm-footer"><button type="button" class="btn" data-sip-kapat>Vazgeç</button><button type="submit" class="btn btn-primary">Durumu kaydet</button></div>
      </form>
    </div>
  </div>

  <!-- İptal -->
  <div class="pm-overlay" id="sipIptalModal" hidden role="dialog" aria-modal="true" aria-labelledby="sipIptalBas">
    <div class="pm-dialog">
      <form method="post" action="siparis_kaydet.php" data-sip-tek>
        <div class="pm-header"><h2 class="pm-title" id="sipIptalBas">Sevkiyatı iptal et</h2><button type="button" class="pm-close" data-sip-kapat aria-label="Kapat">✕</button></div>
        <div class="pm-body sip-form">
          <?= sipui_csrf() ?><input type="hidden" name="islem" value="durum"><input type="hidden" name="sid" value="<?= $id ?>"><input type="hidden" name="hedef" value="iptal"><input type="hidden" name="donus" value="durum">
          <label>İptal gerekçesi<textarea name="gerekce" rows="3" required minlength="3" maxlength="255"></textarea></label>
        </div>
        <div class="pm-footer"><button type="button" class="btn" data-sip-kapat>Vazgeç</button><button type="submit" class="btn btn-danger">İptal et</button></div>
      </form>
    </div>
  </div>

  <?php if (!$kapali && (string)$sev['durum'] !== 'mail_gonderildi'): ?>
  <!-- Mail gönderildi -->
  <div class="pm-overlay" id="sipMailModal" hidden role="dialog" aria-modal="true" aria-labelledby="sipMailBas">
    <div class="pm-dialog">
      <form method="post" action="siparis_kaydet.php" data-sip-tek data-sip-mail-form>
        <div class="pm-header"><h2 class="pm-title" id="sipMailBas">Mail gönderildi olarak işaretle</h2><button type="button" class="pm-close" data-sip-kapat aria-label="Kapat">✕</button></div>
        <div class="pm-body sip-form">
          <?= sipui_csrf() ?><input type="hidden" name="islem" value="durum"><input type="hidden" name="sid" value="<?= $id ?>"><input type="hidden" name="hedef" value="mail_gonderildi"><input type="hidden" name="donus" value="durum">
          <p>Uygulama mail göndermez: PKL'yi kendi mail programınızdan müşteriye gönderin, sonra burada işaretleyin. Sevkiyat “📦 Evrak takımı bekleyen” bölümüne geçer.</p>
          <?php if ($mailPklAd !== ''): ?>
            <div class="sip-mail-dosya">📎 <strong class="sip-kes"><?= h($mailPklAd) ?></strong>
              <?php if ($mailPklBelge > 0): ?><a class="btn btn-sm" href="siparis_dosya.php?id=<?= $mailPklBelge ?>&amp;dl=1">⬇ İndir</a><?php endif; ?></div>
          <?php endif; ?>
          <?php if ($mailTaslak['kime']): ?>
            <div class="sip-mail-alici"><span class="sip-soluk">PO'da yazan Packing List adresi:</span>
              <?php foreach (['Kime' => $mailTaslak['kime'], 'Bilgi' => $mailTaslak['bilgi']] as $etk => $liste): foreach ($liste as $em): ?>
                <div class="sip-mail-satir"><span class="sip-mail-rol"><?= h($etk) ?></span> <code><?= h($em) ?></code> <button type="button" class="btn btn-sm" data-sip-kopyala="<?= h($em) ?>">Kopyala</button></div>
              <?php endforeach; endforeach; ?>
              <div class="sip-mail-konu"><span class="sip-soluk">Konu:</span> <?= h($mailTaslak['konu']) ?></div>
              <div class="sip-mail-eylem">
                <?php if ($mailPklBelge > 0): ?><a class="btn btn-sm btn-primary" href="siparis_dosya.php?id=<?= $mailPklBelge ?>&amp;eml=1&amp;sid=<?= $id ?>" data-sip-eml>📎 Ekli mail taslağını indir</a><?php endif; ?>
                <a class="btn btn-sm" href="<?= h(siparis_pkl_mailto($mailTaslak)) ?>">✉ Mail programını aç</a>
              </div>
              <p class="sip-soluk sip-mail-ipucu">"Ekli mail taslağı" PKL eki, Kime/Bilgi, konu ve metinle hazır bir taslak (.eml) indirir; açınca Outlook'ta gönderilmeye hazır gelir. "Mail programını aç" eki EKLEYEMEZ (tarayıcı kısıtı) — PKL'yi elle ekleyin.</p>
            </div>
          <?php endif; ?>
          <label class="sip-onay-satir"><input type="checkbox" name="onay_gonderdim" value="1" required data-sip-mail-onay> PKL'yi müşteriye gönderdim</label>
          <?php if ($eksikHucre > 0): ?>
            <label class="sip-onay-satir"><input type="checkbox" name="onay_eksik" value="1" required data-sip-mail-onay> PKL'de <?= $eksikHucre ?> boş hücre vardı; eksik alanları Excel'de tamamladım</label>
          <?php endif; ?>
        </div>
        <div class="pm-footer"><button type="button" class="btn" data-sip-kapat>Vazgeç</button><button type="submit" class="btn btn-secondary" data-sip-mail-gonder disabled>✅ Mail gönderildi</button></div>
      </form>
    </div>
  </div>

  <?php endif; ?>

  <?php if ((string)$sev['durum'] === 'mail_gonderildi'): ?>
  <!-- Evrak takımı gönderildi (v400: son adım, süreci kapatır) -->
  <div class="pm-overlay" id="sipEvrakModal" hidden role="dialog" aria-modal="true" aria-labelledby="sipEvrakBas">
    <div class="pm-dialog">
      <form method="post" action="siparis_kaydet.php" data-sip-tek data-sip-evrak-form>
        <div class="pm-header"><h2 class="pm-title" id="sipEvrakBas">Evrak takımı gönderildi olarak işaretle</h2><button type="button" class="pm-close" data-sip-kapat aria-label="Kapat">✕</button></div>
        <div class="pm-body sip-form">
          <?= sipui_csrf() ?><input type="hidden" name="islem" value="durum"><input type="hidden" name="sid" value="<?= $id ?>"><input type="hidden" name="hedef" value="evrak_gonderildi"><input type="hidden" name="donus" value="durum">
          <p>Uygulama evrak göndermez: evrak takımını (Excel + belgeler) müşteriye kendiniz gönderin, sonra burada süreci kapatın.</p>
          <?php if ((string)$sev['mail_gonderildi_at'] !== ''): ?><p class="sip-soluk">Mail gönderildi: <?= h(sipui_zaman((string)$sev['mail_gonderildi_at'])) ?></p><?php endif; ?>
          <?php if ($belgeler): ?><div class="sip-mail-eylem"><a class="btn btn-sm" href="siparis_zip.php?id=<?= $id ?>">🗜 Evrak takımı (ZIP)</a></div><?php endif; ?>
          <label class="sip-onay-satir"><input type="checkbox" name="onay_evrak" value="1" required data-sip-evrak-onay> Evrak takımını müşteriye gönderdim</label>
        </div>
        <div class="pm-footer"><button type="button" class="btn" data-sip-kapat>Vazgeç</button><button type="submit" class="btn btn-secondary" data-sip-evrak-gonder disabled>📦 Süreci kapat</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <?php if ($sil): ?>
  <div class="pm-overlay" id="sipSilModal" hidden role="dialog" aria-modal="true" aria-labelledby="sipSilBas">
    <div class="pm-dialog">
      <form method="post" action="siparis_kaydet.php" data-sip-tek>
        <div class="pm-header"><h2 class="pm-title" id="sipSilBas">Sevkiyatı sil</h2><button type="button" class="pm-close" data-sip-kapat aria-label="Kapat">✕</button></div>
        <div class="pm-body sip-form">
          <?= sipui_csrf() ?><input type="hidden" name="islem" value="sil"><input type="hidden" name="sid" value="<?= $id ?>">
          <p><strong><?= h((string)$sev['order_ref']) ?></strong> listeden kaldırılır (yumuşak silme; aynı sipariş numarasıyla yeni belge gelirse geri açılır).</p>
          <label>Silme gerekçesi<textarea name="gerekce" rows="3" required minlength="3" maxlength="255"></textarea></label>
        </div>
        <div class="pm-footer"><button type="button" class="btn" data-sip-kapat>Vazgeç</button><button type="submit" class="btn btn-danger">Sevkiyatı sil</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>
  <?= sipui_onizle_modal_html() ?>
</div>
<?php
siparis_scripts();
render_footer();
