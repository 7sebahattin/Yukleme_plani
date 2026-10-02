<?php
// =============================================================================
// HAL KAYIT — Doğum Tarihi Biçim Deneyi (yalnız yönetici)
// Kayıtsız kişili Satın Alım'da HKS "Tc kimlik numarası Mernis sisteminde
// bulunamadı" (satır HataKodu 21) dönüyor. DogumTarihi alanı HKS'te metin
// (xs:string) ve sunucunun beklediği biçim belgelenmemiş. Bu ekran, BİR
// SONRAKİ gönderimin hangi biçimle gideceğini TEK KULLANIMLIK seçer ve
// denemelerin maskeli kaydını gösterir. Plan ve gerekçe:
// docs/HKS_MERNIS_ILK_KAYIT_ANALIZ.md §10.
//
// Bu ekran HKS'e HİÇBİR ŞEY GÖNDERMEZ. Gönderim her zaman operatörün Hal Kayıt
// panelindeki kendi "Gönder"idir (tek gönderim yolu: hks_bildirim_kaydet()).
// =============================================================================
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$auth_user = require_login();
if (!is_admin()) {
    http_response_code(403);
    die('Bu sayfa yalnızca yöneticiye açıktır.');
}

require_once __DIR__ . '/taslak_lib.php';   // config + db + hks_soap + deney yardımcıları
hks_tablolari_hazirla();

$mesaj = '';
$hata  = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $islem = (string)($_POST['islem'] ?? '');
    if ($islem === 'kur') {
        $bicim = (string)($_POST['bicim'] ?? '');
        $h = hks_dogum_deney_kur($bicim, (string)($_POST['tc'] ?? ''), (string)($auth_user['username'] ?? ''));
        if ($h) {
            $hata = $h;
        } else {
            $d = hks_dogum_deney_oku();
            audit_log_event('update', 'hks_dogum_deney', null, null, ['islem' => 'kur', 'bicim' => $bicim, 'tc' => $d['tcSon4'] ?? '']);
            $mesaj = 'Deney kuruldu. Bu kişi için Hal Kayıt panelinden yapılacak İLK gönderim seçilen biçimle gidecek.';
        }
    } elseif ($islem === 'iptal') {
        hks_dogum_deney_iptal();
        audit_log_event('update', 'hks_dogum_deney', null, null, ['islem' => 'iptal']);
        $mesaj = 'Deney iptal edildi.';
    } elseif ($islem === 'sifirla') {
        hks_kv_yaz('dogum_varyant', null);
        audit_log_event('update', 'hks_dogum_deney', null, null, ['islem' => 'ogrenilen_sifirla']);
        $mesaj = 'Öğrenilen biçim silindi; varsayılan (config) biçime dönüldü.';
    }
}

$bicimler   = hks_dogum_bicimleri();
$yururluk   = hks_dogum_varyant_coz(null);
$ogrenilen  = hks_dogum_varyant_ogrenilen();
$ogrHam     = hks_kv_oku('dogum_varyant', null);
$deney      = hks_dogum_deney_oku();
$denemeler  = hks_dogum_denemeleri();
// Örnek gösterimi kurgusal bir tarihle (gün ≤ 12, gün ≠ ay — takası görünür kılar).
$ornek = fn(string $b) => hks_dogum_tarihi_xml('1960-02-11', $b);
$sonucAd = ['kunye' => '✅ Künye', 'mernis' => '✗ Mernis', 'girilmelidir' => '✗ Girilmelidir', 'diger' => '✗ Diğer hata', 'belirsiz' => '⚠ Belirsiz (bağlantı)'];
$onerilen = ['iso_oglen', 'gtb_oglen', 'gtb_tarih', 'iso_tarih'];

render_header('Doğum Tarihi Deneyi');
?>
<div style="max-width:900px">
  <h1 style="margin:0 0 4px">🧪 Doğum Tarihi Biçim Deneyi</h1>
  <p style="color:var(--text-muted,#64748b);margin:0 0 16px;font-size:14px">
    Kayıtsız kişili Satın Alım'da "Mernis'te bulunamadı" hatasının nedenini bulmak için.
    Bu ekran HKS'e bir şey <b>göndermez</b>; yalnız bir sonraki gönderimin biçimini seçer.
    Mernis reddi künye ve rüsum oluşturmaz, aynı taslak güvenle tekrar gönderilebilir.
  </p>

  <?php if ($mesaj): ?><div class="flash flash-success" style="margin-bottom:12px"><?= h($mesaj) ?></div><?php endif; ?>
  <?php if ($hata):  ?><div class="flash flash-error" style="margin-bottom:12px"><?= h($hata) ?></div><?php endif; ?>

  <div class="card" style="margin-bottom:14px">
    <h2 style="font-size:16px;margin:0 0 8px">Yürürlükteki biçim</h2>
    <p style="margin:0">
      <b><?= h($bicimler[$yururluk['bicim']] ?? $yururluk['bicim']) ?></b>
      — örnek: <code><?= h($ornek($yururluk['bicim'])) ?></code>
      <?= $ogrenilen ? '<span class="badge">öğrenildi</span>' : '<span class="badge">varsayılan</span>' ?>
    </p>
    <?php if ($ogrenilen && is_array($ogrHam)): ?>
      <p style="margin:6px 0 0;font-size:13px">Kanıt: <?= h((string)($ogrHam['kanit'] ?? '')) ?> · <?= h((string)($ogrHam['zaman'] ?? '')) ?></p>
      <form method="post" style="margin-top:8px" onsubmit="return confirm('Öğrenilen biçim silinsin mi?')">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <button class="btn" name="islem" value="sifirla">Öğrenileni sıfırla</button>
      </form>
    <?php endif; ?>
    <p style="margin:8px 0 0;font-size:13px;color:var(--text-muted,#64748b)">
      Bir biçim ancak <b>gerçek künye</b> üretir ve kişi gönderimden hemen önce <b>KAYITSIZ</b>
      doğrulanmışsa öğrenilir; sonraki bütün gönderimler onunla gider.
    </p>
  </div>

  <div class="card" style="margin-bottom:14px">
    <h2 style="font-size:16px;margin:0 0 8px">Tek kullanımlık deney</h2>
    <?php if ($deney): ?>
      <p style="margin:0 0 8px">
        Kurulu: <b><?= h($bicimler[$deney['bicim']]) ?></b> (<code><?= h($ornek($deney['bicim'])) ?></code>)
        · kişi <b><?= h((string)$deney['tcSon4']) ?></b>
        · <?= h((string)$deney['zaman']) ?> · 24 saat geçerli
      </p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <button class="btn" name="islem" value="iptal">Deneyi iptal et</button>
      </form>
    <?php else: ?>
      <p style="margin:0 0 8px;font-size:14px">
        Kişi HKS sitesinde <b>hiç sorgulanmamış</b> olmalı (Sorgula kişiyi tanınır yapar ve
        deney anlamını yitirir). Deney yalnız gönderim anında kişi KAYITSIZ doğrulanırsa uygulanır.
      </p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <div class="form-group">
          <label for="ddTc">Karşı tarafın TC kimlik no</label>
          <input id="ddTc" name="tc" inputmode="numeric" maxlength="11" autocomplete="off" required>
        </div>
        <div class="form-group">
          <label for="ddBicim">Biçim</label>
          <select id="ddBicim" name="bicim">
            <?php foreach ($bicimler as $k => $ad): ?>
              <option value="<?= h($k) ?>" <?= $k === 'iso_oglen' ? 'selected' : '' ?>>
                <?= h($ad) ?> — <?= h($ornek($k)) ?><?= in_array($k, $onerilen, true) ? '' : ' (bugünkü)' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn-primary" name="islem" value="kur">Deneyi kur</button>
      </form>
    <?php endif; ?>
    <details style="margin-top:10px;font-size:13px">
      <summary>Önerilen sıra</summary>
      <ol style="margin:6px 0 0;padding-left:20px">
        <li>Deney kurmadan normal gönderim (gidecek yer İl/İlçe/Belde artık otomatik ekleniyor).</li>
        <li>Mernis gelirse: <code>YYYY-AA-GGT12:00:00</code> (en olası çözüm).</li>
        <li>Sonra sırayla <code>GG.AA.YYYY 12:00:00</code>, <code>GG.AA.YYYY</code>, <code>YYYY-AA-GG</code>.</li>
        <li>Hepsi Mernis verirse sorun GTB tarafında — raporla başvuru.</li>
      </ol>
      Kişi başına günde en çok 4 deneme önerilir.
    </details>
  </div>

  <div class="card">
    <h2 style="font-size:16px;margin:0 0 8px">Deneme kaydı (son <?= count($denemeler) ?>)</h2>
    <?php if (!$denemeler): ?>
      <p style="margin:0">Henüz kayıtsız kişili Satın Alım gönderimi yok.</p>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Zaman</th><th>Kişi</th><th>Doğum</th><th>Kayıt (önce)</th><th>Biçim</th><th>Deney</th><th>Adres</th><th>Sonuç</th></tr></thead>
      <tbody>
      <?php foreach ($denemeler as $d): ?>
        <tr>
          <td><?= h(substr((string)($d['zaman'] ?? ''), 0, 16)) ?></td>
          <td><?= h((string)($d['tc'] ?? '')) ?></td>
          <td><?= h((string)($d['dogumSinifi'] ?? '')) ?></td>
          <td><?= h((string)($d['kayit'] ?? '')) ?></td>
          <td><code><?= h((string)($d['bicim'] ?? '')) ?></code></td>
          <td><?= !empty($d['deney']) ? '✓' : '' ?></td>
          <td><?= !empty($d['adres']) ? '✓' : '' ?></td>
          <td><?= h($sonucAd[$d['sonuc'] ?? ''] ?? (string)($d['sonuc'] ?? '')) ?><?= !empty($d['hataKodu']) ? ' (' . (int)$d['hataKodu'] . ')' : '' ?><?= !empty($d['ogrenildi']) ? ' · öğrenildi' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
    <p style="margin:8px 0 0;font-size:12px;color:var(--text-muted,#64748b)">
      Kişisel veri tutulmaz: yalnız TC'nin son 4 hanesi ve doğum tarihinin sınıfı (gün&gt;12 / gün&lt;=12 / gün=ay).
    </p>
  </div>
</div>
<?php
render_footer();
