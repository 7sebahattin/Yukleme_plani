<?php
// =========================================================
// siparis_ayarlar.php — PKL sayaçları (sezonluk), son PKL'ler, üretici/kalıntı profilleri
//
// Görüntüleme: siparis.read. Profil kaydet: siparis.write. Sayaç düzeltme + profil silme: siparis.delete.
// POST'lar bu sayfaya gelir (CSRF + kapı burada); mutasyon ve audit kütüphanededir.
// Audit'e profil DEĞERLERİ (üretici adı, GGN, laboratuvar) yazılmaz — kütüphane yalnız id + alan adları yazar.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/_siparis_ortak.php';
$auth_user = require_login();
require_siparis('read');
$pdo   = db();
$hazir = siparis_sema_hazir($pdo);
$yaz = can_siparis('write');
$sil = can_siparis('delete');
$uid = (int)$auth_user['id'];

if ($hazir && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $islem = (string)($_POST['islem'] ?? '');
    $git = function (string $a = ''): void { header('Location: siparis_ayarlar.php' . $a); exit; };
    if ($islem === 'sayac') {
        require_siparis('delete');
        $ad = (string)($_POST['ad'] ?? '');
        $deger = trim((string)($_POST['deger'] ?? ''));
        if (!preg_match('/^(palet|belge_no)_\d{4}$/', $ad) || !preg_match('/^\d{1,7}$/', $deger)) { set_flash('error', 'Sayaç adı ya da değeri geçersiz.'); $git(); }
        if (($_POST['anladim'] ?? '') !== '1') { set_flash('error', 'Sayacı değiştirmenin yeni PKL numaralarını etkilediğini onaylayın.'); $git(); }
        try {
            siparis_sayac_ayarla($pdo, $ad, (int)$deger, $uid);
            set_flash('success', 'Sayaç güncellendi: ' . $ad . ' = ' . (int)$deger . ' (sıradaki numara ' . ((int)$deger + 1) . ').');
        } catch (Throwable $e) {
            error_log('[siparis_ayarlar] sayac: ' . $e->getMessage());
            set_flash('error', 'Sayaç güncellenemedi.');
        }
        $git();
    }
    if ($islem === 'profil_kaydet') {
        require_siparis('write');
        $pid = (int)($_POST['profil_id'] ?? 0);
        $r = siparis_profil_kaydet($pdo, $pid > 0 ? $pid : null, [
            'ad' => (string)($_POST['ad'] ?? ''), 'uretici_adi' => (string)($_POST['uretici_adi'] ?? ''), 'ggn' => (string)($_POST['ggn'] ?? ''),
            'tarla_kodu' => (string)($_POST['tarla_kodu'] ?? ''), 'izlenebilirlik_kodu' => (string)($_POST['izlenebilirlik_kodu'] ?? ''),
            'kalinti_rapor_no' => (string)($_POST['kalinti_rapor_no'] ?? ''), 'kalinti_tarihi' => (string)($_POST['kalinti_tarihi'] ?? ''),
            'lab_adi' => (string)($_POST['lab_adi'] ?? ''), 'aktif' => ($_POST['aktif'] ?? '') === '1' ? 1 : 0,
        ], $uid);
        set_flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Profil kaydedildi.' : (string)($r['hata'] ?? 'Profil kaydedilemedi.'));
        $git('#profiller');
    }
    if ($islem === 'profil_sil') {
        require_siparis('delete');
        $ok = siparis_profil_sil($pdo, (int)($_POST['profil_id'] ?? 0), $uid);
        set_flash($ok ? 'success' : 'error', $ok ? 'Profil silindi.' : 'Profil silinemedi.');
        $git('#profiller');
    }
    set_flash('error', 'Geçersiz işlem.');
    $git();
}

render_header('Sipariş ayarları');
siparis_assets();
render_flash();
if (!$hazir) {
    echo '<div class="sip-mod">' . siparis_kurulmadi_html() . '</div>';
    render_footer();
    exit;
}

$buSezon = siparis_sezon(null);
$oncekiSezon = (string)((int)$buSezon - 1);
$sayaclar = [];
foreach ([$buSezon, $oncekiSezon] as $sz) {
    $sayaclar[] = ['sezon' => $sz, 'palet' => siparis_sayac_oku($pdo, 'palet_' . $sz), 'belge' => siparis_sayac_oku($pdo, 'belge_no_' . $sz)];
}
[$ds, $dp] = depo_sql_column('s.depo', ':udm');
$ps = $pdo->prepare('SELECT p.id, p.sevkiyat_id, p.durum, p.sezon, p.belge_no, p.palet_bas, p.palet_bit, p.uretildi_at, s.order_ref FROM siparis_pkl p
    JOIN siparis_sevkiyatlar s ON s.id = p.sevkiyat_id WHERE s.deleted_at IS NULL AND p.belge_no IS NOT NULL' . $ds . ' ORDER BY p.id DESC LIMIT 20');
$ps->execute($dp);
$pkller = $ps->fetchAll(PDO::FETCH_ASSOC);
$profiller = siparis_profiller($pdo, false);
$profilVeri = function (array $p): array {
    $v = ['profil_id' => (int)$p['id'], '_yazi:profil_baslik' => 'Profili düzenle'];
    foreach (array_keys(siparis_profil_alanlari()) as $k) $v[$k] = (string)($p[$k] ?? '');
    $v['aktif'] = (int)$p['aktif'] === 1;
    return $v;
};
?>
<div class="sip-mod">
  <div class="page-head">
    <div><a class="btn btn-geri" href="siparisler.php">← Siparişler</a><h1 class="sip-h1">Sipariş ayarları</h1><p class="muted">PKL numaralandırma sayaçları ve üretici profilleri</p></div>
  </div>

  <section class="card" id="sayaclar" aria-labelledby="sipSayacBas">
    <div class="card-head"><h2 id="sipSayacBas">PKL sayaçları (sezonluk)</h2><span class="sip-soluk">Sezon Temmuz'da döner.</span></div>
    <?php foreach ($sayaclar as $s): ?>
      <div class="sip-sayac-satir">
        <div class="sip-sayac-sezon"><strong>Sezon <?= h($s['sezon']) ?></strong><?= $s['sezon'] === $buSezon ? ' <span class="sip-say">güncel</span>' : '' ?></div>
        <?php foreach ([['palet', 'Son palet ID', $s['palet']], ['belge_no', 'Son belge no', $s['belge']]] as [$tip, $et, $deger]): ?>
          <div class="sip-sayac-kutu">
            <span class="sip-soluk"><?= h($et) ?></span>
            <strong class="sip-sayac-deger"><?= $tip === 'belge_no' ? h($deger > 0 ? siparis_belge_no_bicim($deger) : '—') : h($deger > 0 ? (string)$deger : '—') ?></strong>
            <?php if ($sil): ?>
              <details class="sip-sayac-duzelt"><summary class="btn btn-sm">✎ Düzelt</summary>
                <form method="post" action="siparis_ayarlar.php" data-sip-tek data-sip-onay="Sayaç değişikliği yeni PKL numaralarını etkiler. Devam edilsin mi?"><?= sipui_csrf() ?><input type="hidden" name="islem" value="sayac"><input type="hidden" name="ad" value="<?= h($tip . '_' . $s['sezon']) ?>">
                  <label>Yeni son değer<input type="text" inputmode="numeric" name="deger" required pattern="[0-9]{1,7}" value="<?= (int)$deger ?>"></label>
                  <label class="sip-onay-satir"><input type="checkbox" name="anladim" value="1" required> Neden değiştirdiğimi biliyorum; yeni PKL numaraları bu değerden devam eder</label>
                  <button type="submit" class="btn btn-sm btn-primary">Kaydet</button></form></details>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    <?php if (!$sil): ?><p class="sip-bilgi" role="note">Sayaç düzeltmek için siparis.delete yetkisi gerekir.</p><?php endif; ?>
  </section>

  <section class="card" id="pkller" aria-labelledby="sipPklListBas">
    <div class="card-head"><h2 id="sipPklListBas">Son PKL'ler</h2></div>
    <?php if (!$pkller): ?><div class="empty sip-bos-kutu"><p>Henüz numaralanmış PKL yok.</p></div><?php else: ?>
      <div class="table-wrap pc-only"><table class="data-table sip-table"><thead><tr><th>Belge no</th><th>Palet aralığı</th><th>Sevkiyat</th><th>Durum</th><th>Son üretim</th></tr></thead><tbody>
        <?php foreach ($pkller as $p): ?><tr><td><strong><?= h((string)$p['belge_no']) ?></strong></td><td><?= $p['palet_bas'] !== null ? (int)$p['palet_bas'] . ' – ' . (int)$p['palet_bit'] : '—' ?></td>
          <td><a href="siparis_view.php?id=<?= (int)$p['sevkiyat_id'] ?>#pkl"><?= h((string)$p['order_ref']) ?></a></td><td><?= h(['taslak' => 'Taslak', 'uretildi' => 'Üretildi', 'yuklendi' => 'Yüklendi'][$p['durum']] ?? (string)$p['durum']) ?></td><td><?= h(sipui_zaman((string)$p['uretildi_at'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <div class="card-list mobile-only"><?php foreach ($pkller as $p): ?>
        <div class="sip-mini-kart"><div class="sip-mini-ust"><strong><?= h((string)$p['belge_no']) ?></strong><a href="siparis_view.php?id=<?= (int)$p['sevkiyat_id'] ?>#pkl"><?= h((string)$p['order_ref']) ?></a></div>
          <div class="sip-soluk">Palet <?= $p['palet_bas'] !== null ? (int)$p['palet_bas'] . '–' . (int)$p['palet_bit'] : '—' ?> · <?= h(['taslak' => 'Taslak', 'uretildi' => 'Üretildi', 'yuklendi' => 'Yüklendi'][$p['durum']] ?? (string)$p['durum']) ?> · <?= h(sipui_zaman((string)$p['uretildi_at'])) ?></div></div>
      <?php endforeach; ?></div>
    <?php endif; ?>
  </section>

  <section class="card" id="profiller" aria-labelledby="sipProfBas">
    <div class="card-head"><h2 id="sipProfBas">Üretici / kalıntı profilleri</h2>
      <?php if ($yaz): ?><button type="button" class="btn btn-primary btn-sm" data-sip-ac="sipProfilDuzenle" data-sip-doldur="<?= sipui_json_attr(['profil_id' => '', '_yazi:profil_baslik' => 'Yeni profil', 'aktif' => true, 'ad' => '', 'uretici_adi' => '', 'ggn' => '', 'tarla_kodu' => '', 'izlenebilirlik_kodu' => '', 'kalinti_rapor_no' => '', 'kalinti_tarihi' => '', 'lab_adi' => '']) ?>">+ Yeni profil</button><?php endif; ?></div>
    <p class="muted">PKL'de V–AB alanlarını tek seçimle doldurur. Profil değerleri denetim kaydına yazılmaz.</p>
    <?php if (!$profiller): ?><div class="empty sip-bos-kutu"><p>Henüz profil yok.</p></div><?php else: ?>
      <div class="table-wrap pc-only"><table class="data-table sip-table"><thead><tr><th>Ad</th><th>Üretici</th><th>GGN</th><th>Kalıntı rapor</th><th>Laboratuvar</th><th>Durum</th><th class="actions-col"></th></tr></thead><tbody>
        <?php foreach ($profiller as $p): ?><tr>
          <td><strong><?= h((string)$p['ad']) ?></strong></td><td><?= h((string)$p['uretici_adi']) ?></td><td><?= h((string)$p['ggn']) ?></td>
          <td><?= h((string)$p['kalinti_rapor_no']) ?> <span class="sip-soluk"><?= h(sipui_tarih($p['kalinti_tarihi'])) ?></span></td><td><?= h((string)$p['lab_adi']) ?></td>
          <td><?= (int)$p['aktif'] === 1 ? 'Aktif' : '<span class="sip-soluk">Pasif</span>' ?></td>
          <td class="actions-col"><?php if ($yaz): ?><button type="button" class="btn btn-sm" data-sip-ac="sipProfilDuzenle" data-sip-doldur="<?= sipui_json_attr($profilVeri($p)) ?>">✎ Düzenle</button><?php endif; ?>
            <?php if ($sil): ?><form method="post" action="siparis_ayarlar.php" class="sip-satir-form" data-sip-onay="Profil silinsin mi?"><?= sipui_csrf() ?><input type="hidden" name="islem" value="profil_sil"><input type="hidden" name="profil_id" value="<?= (int)$p['id'] ?>"><button type="submit" class="btn btn-sm btn-danger">Sil</button></form><?php endif; ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <div class="card-list mobile-only"><?php foreach ($profiller as $p): ?>
        <div class="sip-mini-kart"><div class="sip-mini-ust"><strong><?= h((string)$p['ad']) ?></strong><span class="sip-soluk"><?= (int)$p['aktif'] === 1 ? 'Aktif' : 'Pasif' ?></span></div>
          <div class="sip-soluk"><?= h((string)$p['uretici_adi']) ?> · GGN <?= h((string)$p['ggn']) ?></div>
          <div class="sip-mini-eylem"><?php if ($yaz): ?><button type="button" class="btn btn-sm" data-sip-ac="sipProfilDuzenle" data-sip-doldur="<?= sipui_json_attr($profilVeri($p)) ?>">✎ Düzenle</button><?php endif; ?>
            <?php if ($sil): ?><form method="post" action="siparis_ayarlar.php" class="sip-satir-form" data-sip-onay="Profil silinsin mi?"><?= sipui_csrf() ?><input type="hidden" name="islem" value="profil_sil"><input type="hidden" name="profil_id" value="<?= (int)$p['id'] ?>"><button type="submit" class="btn btn-sm btn-danger">Sil</button></form><?php endif; ?></div></div>
      <?php endforeach; ?></div>
    <?php endif; ?>
  </section>

  <?php if ($yaz): ?>
  <div class="pm-overlay" id="sipProfilDuzenle" hidden role="dialog" aria-modal="true" aria-labelledby="sipProfilDBas">
    <div class="pm-dialog">
      <form method="post" action="siparis_ayarlar.php" data-sip-tek>
        <div class="pm-header"><h2 class="pm-title" id="sipProfilDBas" data-sip-yazi="profil_baslik">Profil</h2><button type="button" class="pm-close" data-sip-kapat aria-label="Kapat">✕</button></div>
        <div class="pm-body sip-form">
          <?= sipui_csrf() ?><input type="hidden" name="islem" value="profil_kaydet"><input type="hidden" name="profil_id" value="">
          <label>Profil adı<input type="text" name="ad" required maxlength="100" autocomplete="off"></label>
          <label>Üretici (V)<input type="text" name="uretici_adi" maxlength="150" autocomplete="off"></label>
          <div class="sip-iki-kolon">
            <label>GGN (W)<input type="text" name="ggn" inputmode="numeric" maxlength="20" autocomplete="off"></label>
            <label>Tarla kodu (X)<input type="text" name="tarla_kodu" maxlength="50" autocomplete="off"></label>
          </div>
          <label>İzlenebilirlik kodu (Y)<input type="text" name="izlenebilirlik_kodu" maxlength="60" autocomplete="off"></label>
          <div class="sip-iki-kolon">
            <label>Kalıntı rapor no (Z)<input type="text" name="kalinti_rapor_no" maxlength="60" autocomplete="off"></label>
            <label>Rapor tarihi (AA)<input type="date" name="kalinti_tarihi"></label>
          </div>
          <label>Laboratuvar (AB)<input type="text" name="lab_adi" maxlength="150" autocomplete="off"></label>
          <label class="sip-onay-satir"><input type="checkbox" name="aktif" value="1" checked> Aktif (PKL listesinde görünür)</label>
        </div>
        <div class="pm-footer"><button type="button" class="btn" data-sip-kapat>Vazgeç</button><button type="submit" class="btn btn-primary">Kaydet</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php
siparis_scripts();
render_footer();
