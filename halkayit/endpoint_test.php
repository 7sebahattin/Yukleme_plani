<?php
// =============================================================================
// HAL KAYIT — Endpoint Testi (tarayıcıdan çalışır, komut satırı GEREKTİRMEZ)
//
// GTB 12.03.2025 duyurusu yeni web servis adresleri yayımladı. Bu sayfa eski ve
// yeni adresi AYNI salt-okunur çağrıyla (Ülkeler listesi) dener ve hangisinin
// çalıştığını söyler.
//
// GÜVENLİ: HKS'te KAYIT OLUŞTURMAZ, bildirim göndermez, rüsum doğurmaz.
// Yalnızca referans listesi okur — panelin "🔄 Listeler" butonuyla aynı işlem.
//
// Kullanım: giriş yaptıktan sonra /halkayit/endpoint_test.php adresini aç.
// =============================================================================
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$auth_user = require_login();
if (!is_admin()) {
    http_response_code(403);
    die('Bu sayfa yalnızca yöneticiye açıktır.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/hks_soap.php';

hks_tablolari_hazirla();
$db = hks_db();
$firmalar = $db->query('SELECT id, ad FROM ' . hks_tablo('firmalar') . ' ORDER BY olusturma')->fetchAll();

// Tek bir adres kalıbını salt-okunur çağrıyla dener.
// hks_endpoint() config sabitine bakar; burada İKİ kalıbı da denemek için
// istek doğrudan kurulur (üretim kodu değiştirilmeden).
function endpoint_dene(string $kalip, array $cfg): array {
    $url = sprintf($kalip, 'Genel');
    $istek = '<BaseRequestMessageOf_UlkelerIstek xmlns="' . HKS_SVC_NS . '">'
           . '<Istek/>'
           . '<Password>' . hks_esc($cfg['password']) . '</Password>'
           . '<ServicePassword>' . hks_esc($cfg['servicePassword']) . '</ServicePassword>'
           . '<UserName>' . hks_esc($cfg['userName']) . '</UserName>'
           . '</BaseRequestMessageOf_UlkelerIstek>';
    $zarf = '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
          . $istek . '</s:Body></s:Envelope>';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $zarf,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "' . HKS_SVC_NS . '/IGenelService/GenelServisUlkeler"',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $t0 = microtime(true);
    $cevap = curl_exec($ch);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    if ($cevap === false) {
        $e = curl_error($ch); curl_close($ch);
        return ['ok' => false, 'url' => $url, 'ms' => $ms, 'mesaj' => 'Bağlantı kurulamadı: ' . $e];
    }
    $kod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($kod !== 200) {
        return ['ok' => false, 'url' => $url, 'ms' => $ms,
                'mesaj' => 'HTTP ' . $kod,
                'ham' => preg_replace('/\s+/', ' ', substr((string)$cevap, 0, 500))];
    }
    $duz  = hks_sadelestir($cevap);
    $hata = hks_islem_kontrol($duz);
    if ($hata) {
        return ['ok' => false, 'url' => $url, 'ms' => $ms, 'mesaj' => 'Servis hatası: ' . $hata];
    }
    $adet = count(hks_bloklar($duz, 'UlkeDTO'));
    if ($adet === 0) {
        return ['ok' => false, 'url' => $url, 'ms' => $ms,
                'mesaj' => 'Yanıt geldi ama ülke listesi boş (şema uyuşmuyor olabilir)',
                'ham' => preg_replace('/\s+/', ' ', substr($duz, 0, 500))];
    }
    return ['ok' => true, 'url' => $url, 'ms' => $ms, 'mesaj' => $adet . ' ülke döndü'];
}

$sonuc = null;
$firmaAd = '';
if (($_POST['firmaId'] ?? '') !== '') {
    $st = $db->prepare('SELECT * FROM ' . hks_tablo('firmalar') . ' WHERE id = ?');
    $st->execute([$_POST['firmaId']]);
    if ($f = $st->fetch()) {
        $firmaAd = $f['ad'];
        $cfg = [
            'userName' => $f['user_name'],
            'password' => hks_coz($f['password_enc']),
            'servicePassword' => hks_coz($f['service_password_enc']),
        ];
        @set_time_limit(120);
        $sonuc = [
            'eski' => endpoint_dene(HKS_ENDPOINT_ESKI, $cfg),
            'yeni' => endpoint_dene(HKS_ENDPOINT_YENI, $cfg),
        ];
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HKS Endpoint Testi</title>
<style>
  :root { --bg:#0f172a; --card:#1e293b; --line:#334155; --tx:#e2e8f0; --mut:#94a3b8; --acc:#f97316; }
  * { box-sizing:border-box; }
  body { margin:0; font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif; background:var(--bg); color:var(--tx); padding:16px; }
  h1 { font-size:19px; margin:0 0 4px; }
  p.mut { color:var(--mut); margin:0 0 16px; font-size:13px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:16px; margin-bottom:14px; }
  label { display:block; font-size:13px; color:var(--mut); margin-bottom:6px; }
  select { width:100%; padding:11px; border-radius:8px; border:1px solid var(--line); background:#0b1220; color:var(--tx); font-size:16px; }
  button { margin-top:12px; padding:12px 18px; border:0; border-radius:8px; background:var(--acc); color:#111; font-weight:700; cursor:pointer; font-size:15px; width:100%; }
  .sonuc { border-left:4px solid var(--line); padding:12px 14px; border-radius:6px; background:#0b1220; margin-bottom:10px; }
  .sonuc.ok { border-left-color:#4ade80; }
  .sonuc.err { border-left-color:#f87171; }
  .rozet { font-weight:800; font-size:15px; }
  .ok .rozet { color:#4ade80; } .err .rozet { color:#f87171; }
  .url { font-family:ui-monospace,monospace; font-size:12px; color:var(--mut); word-break:break-all; margin:4px 0; }
  .karar { background:#1c2b1f; border:1px solid #4ade80; border-radius:10px; padding:16px; }
  .karar.uyari { background:#2b1c1c; border-color:#f87171; }
  code { background:#0b1220; padding:2px 6px; border-radius:4px; font-size:13px; }
  pre { background:#0b1220; border:1px solid var(--line); border-radius:6px; padding:10px; overflow:auto; font-size:11px; white-space:pre-wrap; word-break:break-word; max-height:200px; color:var(--mut); }
</style>
</head>
<body>
  <h1>HKS Endpoint Testi</h1>
  <p class="mut">Eski ve yeni web servis adresini karşılaştırır.
     <b>Güvenli:</b> yalnızca ülke listesi okur — bildirim OLUŞTURMAZ, rüsum doğurmaz.</p>

  <form method="post" class="card">
    <label>Firma seçin (kimlik bilgileri için)</label>
    <select name="firmaId" required>
      <option value="">— seçiniz —</option>
      <?php foreach ($firmalar as $f): ?>
        <option value="<?= htmlspecialchars($f['id']) ?>"
          <?= (($_POST['firmaId'] ?? '') === $f['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($f['ad']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit">▶ Testi Başlat</button>
  </form>

<?php if ($sonuc): ?>
  <div class="card">
    <h2 style="font-size:15px;margin:0 0 12px;color:var(--acc)">Sonuç — <?= htmlspecialchars($firmaAd) ?></h2>

    <?php foreach ([['eski', 'ESKİ adres (şu an kullanılan)'], ['yeni', 'YENİ adres (GTB 12.03.2025)']] as [$k, $baslik]):
          $r = $sonuc[$k]; ?>
      <div class="sonuc <?= $r['ok'] ? 'ok' : 'err' ?>">
        <div><?= $baslik ?></div>
        <div class="url"><?= htmlspecialchars($r['url']) ?></div>
        <div class="rozet"><?= $r['ok'] ? '✓ ÇALIŞIYOR' : '✗ ÇALIŞMIYOR' ?>
          <span style="font-weight:400;color:var(--mut);font-size:13px">
            — <?= htmlspecialchars($r['mesaj']) ?> (<?= $r['ms'] ?> ms)</span></div>
        <?php if (!empty($r['ham'])): ?><pre><?= htmlspecialchars($r['ham']) ?></pre><?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($sonuc['yeni']['ok']): ?>
      <div class="karar">
        <b>✓ Yeni adres çalışıyor — geçiş yapabilirsiniz.</b><br><br>
        <code>halkayit/config.php</code> dosyasında şu satırı bulun:<br>
        <code>define('HKS_YENI_ENDPOINT', false);</code><br>
        <code>false</code> yerine <code>true</code> yazıp kaydedin.<br><br>
        Sonra panelde <b>🔄 Listeler</b> butonuna basıp listelerin geldiğini doğrulayın.
        Sorun çıkarsa aynı satırı <code>false</code>'a geri alın.
        <?php if (!$sonuc['eski']['ok']): ?>
          <br><br><b>⚠️ Eski adres artık cevap vermiyor — geçiş acil.</b>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="karar uyari">
        <b>✗ Yeni adres bu sunucudan doğrulanamadı.</b><br><br>
        <code>config.php</code> içindeki <code>HKS_YENI_ENDPOINT</code> değerini
        <code>false</code> bırakın. Olası nedenler:
        <ul style="margin:8px 0 0;padding-left:20px">
          <li>Sunucunun güvenlik duvarı <b>8443</b> portunu engelliyor (hosting firmasına sorun)</li>
          <li>Firmanın web servis yetkisi yeni adreste tanımlı değil (GTB'ye sorun)</li>
        </ul>
        <br>Yukarıdaki hata mesajını paylaşırsanız birlikte bakabiliriz.
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
</body>
</html>
