<?php
// =========================================================
// beyan_bildirim_tani.php — Beyan → Hal Kayıt köprüsü ÖN KONTROL (admin)
// Sprint Beyan-Bildirim-01
// =========================================================
// AMAÇ: Özelliği canlıda ilk kez denemeden önce, kodun varsaydığı her şeyi
// GERÇEK veriye karşı doğrulamak. Üç soruyu cevaplar:
//   1) Migration çalıştı mı? (tablolar + kolonlar)
//   2) HKS kataloğu bekleneni içeriyor mu? ("İhracat" sıfatı, "Satış" türü,
//      "Yurt Dışı" işletme türü — üçü de kodun ADA göre aradığı değerler)
//   3) Beyanlardaki ürün adları katalogla eşleşiyor mu, yoksa hep elle mi
//      seçilecek?
//
// TAMAMEN SALT-OKUNUR. Hiçbir tabloya yazmaz, HKS'e HİÇBİR çağrı yapmaz
// (katalog yalnız yerel önbellekten okunur). İstediğiniz kadar açabilirsiniz.
//
// Kural tekrarı YOKTUR: kontroller, uygulamanın kendi fonksiyonlarını
// (bb_katalog / bb_varsayilanlar / bb_yurtdisi_isletme_turu / bb_tahmin /
// beyan_hks_*) çağırır — bu sayfa "geçti" diyorsa özellik de aynı kararı verir.
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

$auth_user = require_login();
if (!is_admin()) forbidden('Bu sayfa yalnızca yöneticiye açıktır.');

$pdo = db();

// ── Yardımcılar ───────────────────────────────────────────────────────────
function tani_satir(string $ad, bool $ok, string $deger = '', string $ipucu = ''): array {
    return ['ad' => $ad, 'ok' => $ok, 'deger' => $deger, 'ipucu' => $ipucu];
}
function tani_tablo_var(PDO $pdo, string $tablo): bool {
    try { $pdo->query("SELECT 1 FROM `$tablo` LIMIT 1"); return true; }
    catch (PDOException $e) { return false; }
}
function tani_kolonlar(PDO $pdo, string $tablo): array {
    try {
        $out = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `$tablo`") as $c) $out[] = $c['Field'];
        return $out;
    } catch (PDOException $e) { return []; }
}

// ── 1. Migration ──────────────────────────────────────────────────────────
$cd_cols = tani_kolonlar($pdo, 'customs_declarations');
$m = [];
foreach (['vehicle_plate', 'hks_durum', 'hks_firma_id', 'hks_urun_id', 'hks_urun_ad',
          'hks_ulke_id', 'hks_ulke_ad'] as $k) {
    $m[] = tani_satir("customs_declarations.$k", in_array($k, $cd_cols, true), '',
        'Kolon yok — herhangi bir sayfayı açıp auto-migration\'ın çalışmasını bekleyin.');
}
foreach (['beyan_hks_bildirim', 'hks_eslesme'] as $t) {
    $m[] = tani_satir("$t tablosu", tani_tablo_var($pdo, $t), '',
        'Tablo yok — auto-migration çalışmamış.');
}
$on = defined('HKS_TABLO_ON') ? HKS_TABLO_ON : 'hks_';
foreach (["{$on}kv", "{$on}firmalar", "{$on}taslaklar", "{$on}gonderilenler"] as $t) {
    $m[] = tani_satir("$t tablosu (Hal Kayıt)", tani_tablo_var($pdo, $t), '',
        'Hal Kayıt panelini bir kez açın; tablolar orada oluşturulur.');
}
$migration_ok = !in_array(false, array_column($m, 'ok'), true);

// ── 2. Katalog ────────────────────────────────────────────────────────────
$katalog = $migration_ok ? bb_katalog() : [];
$k = [];
if (!$katalog) {
    $k[] = tani_satir('HKS katalog önbelleği', false, '',
        'Hal Kayıt panelini açın → firma seçin → "Listeleri Güncelle". '
      . 'Önbellek boşken özellik bilinçli olarak kapalı kalır.');
} else {
    $ham = bb_listeler_cache();
    $k[] = tani_satir('HKS katalog önbelleği', true,
        'güncelleme: ' . (($ham['zaman'] ?? '') !== '' ? date('d.m.Y H:i', strtotime($ham['zaman'])) : '—'));
    foreach ([['urunler', 'Ürün'], ['ulkeler', 'Ülke'], ['sifatlar', 'Sıfat'],
              ['isletmeTurleri', 'İşletme türü']] as [$anahtar, $etiket]) {
        $n = count($katalog[$anahtar] ?? []);
        $k[] = tani_satir("$etiket listesi", $n > 0, $n . ' kayıt', 'Liste boş — güncelleyin.');
    }

    // Kodun ADA göre aradığı üç değer — bu sayfanın asıl varlık sebebi.
    $vs  = bb_varsayilanlar($katalog);
    $adi = function (array $liste, string $id): string {
        foreach ($liste as $x) if ((string)$x['id'] === $id) return (string)$x['ad'];
        return '';
    };
    $k[] = tani_satir('Varsayılan sıfat ("ihracat" geçen)', $vs['sifatId'] !== '',
        $vs['sifatId'] !== '' ? $adi($katalog['sifatlar'], $vs['sifatId']) . ' (id ' . $vs['sifatId'] . ')' : '',
        'Katalogda adında "ihracat" geçen sıfat yok — modalde sıfat elle seçilir.');
    $k[] = tani_satir('Varsayılan tür ("satış", "alım" değil)', $vs['bildirimTuruId'] !== '',
        $vs['bildirimTuruId'] !== '' ? $adi($katalog['bildirimTurleri'], $vs['bildirimTuruId']) . ' (id ' . $vs['bildirimTuruId'] . ')' : '',
        'Katalogda uygun "Satış" türü yok — modalde tür elle seçilir.');
    $yd = bb_yurtdisi_isletme_turu($katalog);
    $k[] = tani_satir('"Yurt Dışı" işletme türü', $yd !== '',
        $yd !== '' ? $adi($katalog['isletmeTurleri'], $yd) . ' (id ' . $yd . ')' : '',
        'BULUNAMAZSA TASLAK OLUŞTURULAMAZ (409). Bu zorunlu bir alandır.');

    $elenen = count($ham['bildirimTurleri'] ?? []) - count($katalog['bildirimTurleri']);
    $k[] = tani_satir('"Alım" türleri listeden çıkarıldı', true,
        $elenen . ' tür elendi (referanssız — bu ekranda kullanılamaz)');
}

// ── HKS firmaları ─────────────────────────────────────────────────────────
$firmalar = [];
try {
    foreach ($pdo->query("SELECT id, ad FROM `{$on}firmalar` ORDER BY ad") as $r) $firmalar[] = $r;
} catch (PDOException $e) {}

// ── 3. Ürün eşleşmesi ─────────────────────────────────────────────────────
$urun_satir = [];
if ($katalog) {
    $st = $pdo->query("SELECT product_name, COUNT(*) n FROM customs_declarations
                       WHERE deleted_at IS NULL AND COALESCE(product_name,'') <> ''
                       GROUP BY product_name ORDER BY n DESC LIMIT 60");
    foreach ($st as $r) {
        $t = bb_tahmin('urun', (string)$r['product_name'], $katalog['urunler']);
        $urun_satir[] = [
            'ad'  => (string)$r['product_name'],
            'n'   => (int)$r['n'],
            'hks' => $t['ad'],
            'kaynak' => $t['kaynak'],
        ];
    }
}
$urun_eslesen = count(array_filter($urun_satir, fn($u) => $u['kaynak'] !== 'yok'));

// ── 4. Beyan hazırlık özeti ───────────────────────────────────────────────
// Her engel AYRI sayılır (öncelik sırasıyla, buton kapısındaki sırayla).
$ozet = ['hazir' => 0, 'durum' => 0, 'plaka' => 0, 'eslesme' => 0, 'netkg' => 0, 'bildirim_var' => 0];
$engelli_ornek = [];
if ($migration_ok) {
    $rows = $pdo->query("SELECT id, party_no, status, vehicle_plate, net_kg,
                                hks_firma_id, hks_urun_id, hks_ulke_id
                         FROM customs_declarations WHERE deleted_at IS NULL
                         ORDER BY id DESC LIMIT 500")->fetchAll();
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $bagli = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $bs = $pdo->prepare("SELECT DISTINCT beyan_id FROM beyan_hks_bildirim
                             WHERE beyan_id IN ($ph) AND durum IN ('taslak','gonderildi')");
        $bs->execute($ids);
        $bagli = array_flip(array_map('intval', $bs->fetchAll(PDO::FETCH_COLUMN)));
    }
    foreach ($rows as $r) {
        if (!in_array((string)$r['status'], beyan_hks_uygun_durumlar(), true)) { $ozet['durum']++; continue; }
        if (isset($bagli[(int)$r['id']]))                    { $ozet['bildirim_var']++; continue; }
        if (trim((string)$r['vehicle_plate']) === '')        { $ozet['plaka']++;   $engelli_ornek['plaka'][]   = $r; continue; }
        if (!beyan_hks_eslesme_tam($r))                      { $ozet['eslesme']++; $engelli_ornek['eslesme'][] = $r; continue; }
        if ((float)$r['net_kg'] <= 0)                        { $ozet['netkg']++;   $engelli_ornek['netkg'][]   = $r; continue; }
        $ozet['hazir']++;
    }
}

// ── Öğrenilmiş eşlemeler ──────────────────────────────────────────────────
$eslemeler = [];
try {
    $eslemeler = $pdo->query("SELECT tip, kaynak_norm, hks_ad, kullanim FROM hks_eslesme
                              ORDER BY tip, kullanim DESC LIMIT 60")->fetchAll();
} catch (PDOException $e) {}

$rozet = fn(bool $ok) => $ok
    ? '<span class="beyan-badge" style="background:#dcfce7;color:#166534">TAMAM</span>'
    : '<span class="beyan-badge" style="background:#fee2e2;color:#991b1b">EKSİK</span>';

render_header('Bildirim Ön Kontrol');
?>
<div class="page-head">
    <div>
        <h1>🩺 Beyan → Hal Kayıt · Ön Kontrol</h1>
        <p class="muted">
            Salt-okunur. Hiçbir kayda yazmaz, HKS'e çağrı yapmaz.
            Kontroller özelliğin kendi fonksiyonlarını çağırır — burada "TAMAM" görünen
            bir şey uygulamada da aynı sonucu verir.
        </p>
        <!-- Sunucudaki sürüm. "Kodu attım ama ekranda görmüyorum" sorusunun ilk
             cevabı burasıdır: deploy edilmemişse bu sayı eski kalır. Masaüstü
             sidebar'ında da yazar ama mobilde sidebar görünmez. -->
        <p class="muted" style="font-size:.82rem">
            Sunucudaki sürüm: <strong><?= h(APP_SURUM) ?></strong>
            · CSS/JS tazeliği: <?= h(date('d.m.Y H:i', (int)@filemtime(__DIR__ . '/assets/app.js'))) ?>
        </p>
    </div>
    <a href="beyanlar.php" class="btn btn-ghost">← Beyanlar</a>
</div>

<!-- 1 -->
<div class="beyan-section">
    <div class="beyan-section-title">1️⃣ Migration <?= $rozet($migration_ok) ?></div>
    <div class="table-wrap"><table class="beyan-match-table"><tbody>
    <?php foreach ($m as $x): ?>
        <tr>
            <td><?= h($x['ad']) ?></td>
            <td style="width:90px"><?= $rozet($x['ok']) ?></td>
            <td class="muted"><?= $x['ok'] ? '' : h($x['ipucu']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>

<!-- 2 -->
<div class="beyan-section">
    <div class="beyan-section-title">2️⃣ HKS Kataloğu</div>
    <div class="table-wrap"><table class="beyan-match-table"><tbody>
    <?php foreach ($k as $x): ?>
        <tr>
            <td><?= h($x['ad']) ?></td>
            <td style="width:90px"><?= $rozet($x['ok']) ?></td>
            <td><?= h($x['deger']) ?><?php if (!$x['ok'] && $x['ipucu']): ?>
                <span class="muted"><?= h($x['ipucu']) ?></span><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
        <tr>
            <td>Tanımlı HKS firması</td>
            <td><?= $rozet(count($firmalar) > 0) ?></td>
            <td><?= count($firmalar) ?: 'Hal Kayıt panelinden firma ekleyin.' ?>
                <?php if ($firmalar): ?>
                <span class="muted"><?= h(implode(', ', array_column($firmalar, 'ad'))) ?></span>
                <?php endif; ?></td>
        </tr>
    </tbody></table></div>
</div>

<!-- 3 -->
<div class="beyan-section">
    <div class="beyan-section-title">
        3️⃣ Ürün Eşleşmesi
        <?php if ($urun_satir): ?>
        <span class="muted" style="font-weight:400">
            — <?= $urun_eslesen ?>/<?= count($urun_satir) ?> ürün adı otomatik çözülüyor
        </span>
        <?php endif; ?>
    </div>
    <?php if (!$urun_satir): ?>
    <p class="muted">Katalog yüklenmeden ürün eşleşmesi hesaplanamaz.</p>
    <?php else: ?>
    <p class="muted" style="font-size:.85rem">
        "Eşleşmedi" olanlar beyan formunda <strong>bir kez</strong> elle seçilir; seçim
        öğrenilir ve o üründe bir daha sorulmaz.
    </p>
    <div class="table-wrap"><table class="beyan-match-table">
        <thead><tr><th>Beyandaki ürün</th><th class="num">Beyan</th><th>HKS karşılığı</th><th>Kaynak</th></tr></thead>
        <tbody>
        <?php foreach ($urun_satir as $u): ?>
            <tr>
                <td><strong><?= h($u['ad']) ?></strong></td>
                <td class="num"><?= $u['n'] ?></td>
                <td><?= $u['hks'] !== '' ? h($u['hks']) : '<span style="color:#b45309">— eşleşmedi</span>' ?></td>
                <td class="muted"><?= ['ogrenilen' => 'önceki seçim', 'katalog' => 'tam ad', 'yok' => '—'][$u['kaynak']] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<!-- 4 -->
<div class="beyan-section">
    <div class="beyan-section-title">4️⃣ Beyan Hazırlığı <span class="muted" style="font-weight:400">— son 500 beyan</span></div>
    <div class="beyan-view-grid">
        <div class="beyan-view-row"><span class="lbl">✅ Bildirime hazır</span><span class="val"><?= $ozet['hazir'] ?></span></div>
        <div class="beyan-view-row"><span class="lbl">Zaten bildirimi var</span><span class="val"><?= $ozet['bildirim_var'] ?></span></div>
        <div class="beyan-view-row"><span class="lbl">Plaka eksik</span><span class="val"><?= $ozet['plaka'] ?></span></div>
        <div class="beyan-view-row"><span class="lbl">Eşleştirme eksik</span><span class="val"><?= $ozet['eslesme'] ?></span></div>
        <div class="beyan-view-row"><span class="lbl">Net KG eksik</span><span class="val"><?= $ozet['netkg'] ?></span></div>
        <div class="beyan-view-row"><span class="lbl">Durumu uygun değil</span><span class="val"><?= $ozet['durum'] ?></span></div>
    </div>
    <?php foreach (['plaka' => 'Plaka eksik', 'eslesme' => 'Eşleştirme eksik', 'netkg' => 'Net KG eksik'] as $tip => $etiket):
        if (empty($engelli_ornek[$tip])) continue; ?>
    <p class="muted" style="font-size:.85rem;margin:8px 0 0">
        <?= h($etiket) ?> (ilk 5):
        <?php foreach (array_slice($engelli_ornek[$tip], 0, 5) as $e): ?>
        <a href="beyan_edit.php?id=<?= (int)$e['id'] ?>"><?= h($e['party_no'] ?: '#' . $e['id']) ?></a>
        <?php endforeach; ?>
    </p>
    <?php endforeach; ?>
</div>

<!-- 5 -->
<div class="beyan-section">
    <div class="beyan-section-title">5️⃣ Öğrenilmiş Eşlemeler <span class="muted" style="font-weight:400">— <?= count($eslemeler) ?> kayıt</span></div>
    <?php if (!$eslemeler): ?>
    <p class="muted">Henüz eşleme öğrenilmedi. Beyan formunda ilk seçimler yapıldıkça dolar.</p>
    <?php else: ?>
    <div class="table-wrap"><table class="beyan-match-table">
        <thead><tr><th>Tip</th><th>Beyandaki metin</th><th>HKS karşılığı</th><th class="num">Kullanım</th></tr></thead>
        <tbody>
        <?php foreach ($eslemeler as $e): ?>
            <tr><td><?= h($e['tip']) ?></td><td><?= h($e['kaynak_norm']) ?></td>
                <td><?= h((string)$e['hks_ad']) ?></td><td class="num"><?= (int)$e['kullanim'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
