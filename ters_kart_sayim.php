<?php
// =========================================================
// ters_kart_sayim.php — Ters Kart Takip · B) SABAH KALAN KART SAYIMI (v354)
//
// Çavuş seç → sayımı başlat → dağıtılmadan ELDE KALAN kartları okut → önizle
// → onayla. Onayda dağıtılan her kart için sabit giriş saatiyle açık dönem
// yazılır (config/pdks_ters.php → pdks_ters_sayim_onayla; HEP-YA-HİÇ).
// Kart/çavuş/sayım kararları SUNUCUDA; istemci yalnız ham UID + kaynak yollar.
// Yetki: attendance.daily_scan (yeni yetki YOK). Aktif depo zorunlu.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks.php';           // UID normalizasyon + PdksNfcOku
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_ters.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
$y = pdks_ters_require(['tarama']);

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
$base = base_url();
$hazir = pdks_ters_sema_hazir($pdo);
$depo = pdks_ters_aktif_depo();
$user = (int)$auth_user['id'];
$m = 'pdks_ters_metin';

// ── AJAX (POST JSON + CSRF) ──
$ajax = (string)($_GET['ajax'] ?? '');
if ($ajax !== '') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') pdks_ters_json(['ok' => false, 'hata' => 'Yalnız POST.'], 405);
    $g = pdks_ters_json_govde();
    if (!$hazir) pdks_ters_json(pdks_ters_red('sema_hazir_degil', 'Ters Kart Takip kurulmamış.'));
    $sid = (int)$m($g['sayim_id'] ?? 0);
    $r = match ($ajax) {
        'okut'    => pdks_ters_sayim_okut($sid, $m($g['ham_uid'] ?? ''), $m($g['kaynak'] ?? ''), $user, $pdo),
        'karar'   => pdks_ters_sayim_karar($sid, (int)$m($g['kart_id'] ?? 0), $m($g['karar'] ?? ''), $m($g['not'] ?? ''), $user, $pdo),
        'onizle'  => pdks_ters_sayim_onizle($sid, $m($g['giris_saati'] ?? ''), $m($g['neden'] ?? ''), $g['beyan'] ?? '', $pdo),
        'onayla'  => pdks_ters_sayim_onayla($sid, $m($g['giris_saati'] ?? ''), $m($g['neden'] ?? ''), $g['beyan'] ?? '', $m($g['istek_id'] ?? ''), $user, $pdo),
        'geri_al' => pdks_ters_sayim_geri_al($sid, $m($g['gerekce'] ?? ''), $user, $pdo),
        default   => ['ok' => false, 'hata' => 'Bilinmeyen işlem.'],
    };
    if ($ajax === 'onizle' && !empty($r['ok'])) unset($r['sayim']);
    pdks_ters_json($r);
}

$cavusId = (int)($_GET['cavus'] ?? 0);

// ── Sayımı başlat (POST form + CSRF, PRG) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'baslat') {
    csrf_check($_POST['csrf'] ?? null);
    $cavusId = (int)($_POST['cavus'] ?? 0);
    $r = $hazir ? pdks_ters_sayim_ac($cavusId, $user, $pdo) : pdks_ters_red('sema_hazir_degil', 'Ters Kart Takip kurulmamış.');
    if (empty($r['ok'])) set_flash('error', (string)$r['hata']);
    header('Location: ters_kart_sayim.php?cavus=' . $cavusId);
    exit;
}

$cavus = ($hazir && $cavusId > 0) ? pdks_ters_cavus($pdo, $cavusId) : null;
$sayim = $cavus ? pdks_ters_sayim_aktif($pdo, $cavusId, $depo, date('Y-m-d')) : null;
$uyeler = $cavus ? pdks_ters_havuz_uyeleri($pdo, $cavusId, $depo) : [];
$havuzOzet = pdks_ters_havuz_ozet($uyeler);

render_header('Sabah Sayımı — Ters Kart Takip');
pdks_ters_varliklar($base);
render_flash();
?>
<div class="tk-sayfa" data-tk-sayfa="sayim">
<div class="page-head">
    <h1>🌅 Sabah Kalan Kart Sayımı</h1>
    <div class="page-head-actions">
        <a href="ters_kart.php" class="btn btn-geri">← Ters Kart Takip</a>
    </div>
</div>
<?php pdks_ters_sekmeler('sayim', $y); ?>

<?php if (!pdks_ters_kurulum_uyarisi($pdo)): ?>

<?php if (!$cavus): ?>
    <p class="muted">Sayım yapılacak çavuşu seçin. Depo: <strong><?= h($depo) ?></strong></p>
    <?php $durumlar = pdks_ters_hub_durumlari($pdo, $depo); ?>
    <?php if (!$durumlar): ?>
        <div class="tk-kutu tk-kutu-bilgi">Bu depoda ters kart havuzu tanımlanmış çavuş yok.<?= $y['havuz'] ? ' <a href="ters_kart_havuz.php">Kart Havuzu</a> ekranından kartları tanımlayın.' : '' ?></div>
    <?php else: ?>
    <div class="tk-cavus-grid">
        <?php foreach ($durumlar as $c): $sd = $c['sayim']['durum'] ?? null; ?>
        <a class="tk-cavus-kart" href="ters_kart_sayim.php?cavus=<?= (int)$c['foreman_id'] ?>">
            <span class="tk-cavus-ad"><?= h($c['name']) ?></span>
            <span class="tk-cavus-alt"><?= (int)$c['havuz']['aktif'] ?> aktif kart<?= $c['havuz']['toplam'] !== $c['havuz']['aktif'] ? ' · ' . ($c['havuz']['toplam'] - $c['havuz']['aktif']) . ' hariç' : '' ?></span>
            <?php if ($sd === 'onayli'): ?><span class="tk-rozet tk-rozet-ok">✓ Onaylı · <?= (int)$c['sayim']['dagitilan'] ?> kişi</span>
            <?php elseif ($sd === 'taslak'): ?><span class="tk-rozet tk-rozet-uyari">Sayım sürüyor</span>
            <?php else: ?><span class="tk-rozet">Sayım yok</span><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php else: ?>
    <div class="tk-baslik-satir">
        <h2 class="tk-cavus-baslik"><?= h($cavus['name']) ?> <small class="muted"><?= h(date('d.m.Y')) ?> · <?= h($depo) ?></small></h2>
        <a href="ters_kart_sayim.php" class="btn btn-sm">Çavuş değiştir</a>
    </div>

    <?php if (!$sayim): ?>
        <?php $eski = pdks_gunluk_eski_acik_oturumlar($depo, $cavusId, $pdo); ?>
        <?php if ($eski): ?><div class="tk-kutu tk-kutu-uyari">⚠ Bu çavuşun <?= h(date('d.m.Y', strtotime((string)$eski[0]['work_date']))) ?> tarihli mesaisi kapatılmamış. Onaydan önce <a href="gunluk_isci_giris_cikis.php">Giriş-Çıkış</a> ekranından kapatılmalı.</div><?php endif; ?>
        <div class="tk-sayaclar">
            <div class="tk-sayac"><span class="tk-sayac-n"><?= (int)$havuzOzet['aktif'] ?></span><span class="tk-sayac-et">Aktif havuz</span></div>
            <div class="tk-sayac tk-sayac-soluk"><span class="tk-sayac-n"><?= (int)($havuzOzet['toplam'] - $havuzOzet['aktif']) ?></span><span class="tk-sayac-et">Kayıp / bozuk / pasif</span></div>
        </div>
        <?php if (!$uyeler): ?>
            <div class="tk-kutu tk-kutu-bilgi">Bu çavuşun ters kart havuzu boş.<?= $y['havuz'] ? ' <a href="ters_kart_havuz.php?cavus=' . (int)$cavusId . '">Kart Havuzu</a> ekranından kartları tanımlayın.' : '' ?></div>
        <?php else: ?>
        <form method="post" class="tk-baslat">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="form" value="baslat">
            <input type="hidden" name="cavus" value="<?= (int)$cavusId ?>">
            <button type="submit" class="btn btn-primary tk-buyuk-btn">▶ Bugünkü Sayımı Başlat</button>
            <p class="muted">Kartlar dağıtıldıktan sonra yalnız çavuşun ELİNDE KALAN kartları okutacaksınız.</p>
        </form>
        <?php endif; ?>

    <?php elseif ($sayim['durum'] === 'taslak'): ?>
        <?php
        $satirlar = pdks_ters_sayim_satirlari($pdo, (int)$sayim['id']);
        $sayac = pdks_ters_sayim_sayac($pdo, $sayim);
        ?>
        <div class="tk-sayaclar">
            <div class="tk-sayac"><span class="tk-sayac-n" data-tk-sayac="havuz_aktif"><?= (int)$sayac['havuz_aktif'] ?></span><span class="tk-sayac-et">Aktif havuz</span></div>
            <div class="tk-sayac tk-sayac-mavi"><span class="tk-sayac-n" data-tk-sayac="kalan"><?= (int)$sayac['kalan'] ?></span><span class="tk-sayac-et">Elde kalan (okutulan)</span></div>
            <div class="tk-sayac tk-sayac-yesil"><span class="tk-sayac-n" data-tk-sayac="dagitilacak"><?= (int)$sayac['dagitilacak'] ?></span><span class="tk-sayac-et">Dağıtılan = çalışan</span></div>
            <div class="tk-sayac tk-sayac-soluk"><span class="tk-sayac-n" data-tk-sayac="haric"><?= (int)$sayac['haric'] ?></span><span class="tk-sayac-et">Kayıp / bozuk</span></div>
        </div>

        <div class="tk-tara" role="group" aria-label="Kart okutma alanı">
            <div class="tk-tara-ikon" aria-hidden="true">💳</div>
            <div class="tk-tara-metin"><strong>Çavuşun elinde KALAN kartları okutun</strong><br><span class="muted">USB okuyucu hazır — kartları art arda okutun. Aynı kart iki kez sayılmaz.</span></div>
            <input type="text" id="tkScanInput" class="tk-gizli-input" inputmode="none" autocomplete="off" aria-label="Kart okuyucu girişi">
            <button type="button" id="tkNfcBtn" class="btn" hidden>📶 NFC ile oku</button>
            <div id="tkNfcDurum" class="muted tk-kucuk"></div>
        </div>
        <div id="tkSonuc" class="tk-sonuc" role="status" aria-live="polite" hidden></div>

        <section class="tk-blok">
            <h3>Elde kalan kartlar</h3>
            <div id="tkKalanListe" class="tk-cip-liste">
                <?php $kalanVar = false; foreach (array_reverse($satirlar, true) as $kid => $r): if ($r['rol'] !== 'kalan') continue; $kalanVar = true; ?>
                <span class="tk-cip" data-kart="<?= (int)$kid ?>"><span><?= h($r['card_no_snapshot']) ?></span><small><?= h($r['worker_type_name_snapshot']) ?></small><button type="button" class="tk-cip-x" data-tk-karar="kalan_sil" data-kart="<?= (int)$kid ?>" data-no="<?= h($r['card_no_snapshot']) ?>" title="Listeden çıkar" aria-label="<?= h($r['card_no_snapshot']) ?> kartını kalan listesinden çıkar">×</button></span>
                <?php endforeach; ?>
            </div>
            <p id="tkKalanBos" class="muted"<?= $kalanVar ? ' hidden' : '' ?>>Henüz kalan kart okutulmadı. (Tüm kartlar dağıtıldıysa doğrudan onaylayabilirsiniz.)</p>
            <?php $teyitler = array_filter($satirlar, fn($r) => $r['rol'] === 'teyit'); ?>
            <?php if ($teyitler): ?>
            <h4>"Bugün dağıtıldı" teyidi verilen dönmemiş kartlar</h4>
            <div class="tk-cip-liste">
                <?php foreach ($teyitler as $kid => $r): ?>
                <span class="tk-cip tk-cip-uyari"><span><?= h($r['card_no_snapshot']) ?></span><button type="button" class="tk-cip-x" data-tk-karar="teyit_sil" data-kart="<?= (int)$kid ?>" data-no="<?= h($r['card_no_snapshot']) ?>" title="Teyidi geri al" aria-label="<?= h($r['card_no_snapshot']) ?> teyidini geri al">×</button></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <section class="tk-blok tk-onay-panel">
            <h3>Onay</h3>
            <div class="tk-form-satir">
                <label class="tk-etiket">Sabit giriş saati
                    <input type="time" id="tkGirisSaati" step="60" value="<?= h((string)($sayim['varsayilan_giris_saati'] ?? '')) ?>" required>
                </label>
                <label class="tk-etiket">Çavuşun getirdiği işçi sayısı <small class="muted">(isteğe bağlı)</small>
                    <input type="number" id="tkBeyan" min="0" max="2000" inputmode="numeric">
                </label>
            </div>
            <?php if ($sayim['varsayilan_giris_saati'] === null): ?>
                <div class="tk-kutu tk-kutu-uyari">Bu çavuş için tanımlı giriş saati yok — saati elle girin<?= $y['havuz'] ? ' (kalıcı ayar: <a href="ters_kart_havuz.php?cavus=' . (int)$cavusId . '">Kart Havuzu</a>)' : '' ?>.</div>
            <?php endif; ?>
            <label class="tk-etiket" id="tkNedenAlan"<?= $sayim['varsayilan_giris_saati'] === null ? '' : ' hidden' ?>>Saat gerekçesi
                <input type="text" id="tkNeden" maxlength="255" placeholder="<?= $sayim['varsayilan_giris_saati'] === null ? 'Tanımlı saat yok — neden bu saat?' : 'Tanımlı saatten farklı — neden?' ?>">
            </label>
            <div id="tkOnizleme" class="tk-onizleme" aria-live="polite"></div>
            <button type="button" id="tkOnayBtn" class="btn btn-primary tk-buyuk-btn" disabled>✅ Girişleri onayla</button>
            <p class="muted tk-kucuk">Onayla birlikte dağıtılan her kart için bu saatle GİRİŞ yazılır. Gerçek işlem zamanı ayrıca saklanır; fiziksel okutma yerine geçmez.</p>
        </section>

    <?php else: /* onaylı */ ?>
        <?php
        $satirlar = pdks_ters_sayim_satirlari($pdo, (int)$sayim['id']);
        $tipler = [];
        foreach ($satirlar as $r) if (in_array($r['rol'], ['dagitilan', 'zaten_iceride'], true)) $tipler[$r['worker_type_name_snapshot']] = ($tipler[$r['worker_type_name_snapshot']] ?? 0) + 1;
        $mesai = $sayim['session_id'] ? pdks_gunluk_faz8a_oturum_ozet((int)$sayim['session_id'], $pdo) : null;
        ?>
        <?php if (isset($_GET['onay'])): ?><div class="tk-kutu tk-kutu-ok" role="status">✅ Girişler kaydedildi.</div><?php endif; ?>
        <div class="tk-kutu tk-kutu-ok tk-onayli">
            <div class="tk-onayli-buyuk"><?= (int)$sayim['dagitilan_adet'] ?> işçi · giriş <?= h((string)$sayim['giris_saati']) ?></div>
            <div><?= h(implode(' · ', array_map(fn($t, $n) => "$t $n", array_keys($tipler), $tipler))) ?></div>
            <div class="muted tk-kucuk">Onay: <?= h(date('d.m.Y H:i', strtotime((string)$sayim['onay_zamani']))) ?> · Havuz <?= (int)$sayim['havuz_aktif'] ?> · Kalan <?= (int)$sayim['kalan_adet'] ?><?= (int)$sayim['zaten_iceride_adet'] ? ' · ' . (int)$sayim['zaten_iceride_adet'] . ' zaten içerideydi' : '' ?><?= (int)$sayim['haric_adet'] ? ' · ' . (int)$sayim['haric_adet'] . ' hariç' : '' ?><?= $sayim['beyan_adet'] !== null ? ' · Beyan ' . (int)$sayim['beyan_adet'] : '' ?><?= $sayim['giris_saati_nedeni'] ? ' · Saat gerekçesi: ' . h((string)$sayim['giris_saati_nedeni']) : '' ?></div>
        </div>
        <?php if ($mesai): ?>
        <div class="tk-sayaclar">
            <div class="tk-sayac"><span class="tk-sayac-n"><?= (int)$mesai['giris_toplam'] ?></span><span class="tk-sayac-et">Mesai girişi (tümü)</span></div>
            <div class="tk-sayac tk-sayac-yesil"><span class="tk-sayac-n"><?= (int)$mesai['cikis_toplam'] ?></span><span class="tk-sayac-et">Çıkış</span></div>
            <div class="tk-sayac tk-sayac-mavi"><span class="tk-sayac-n"><?= (int)$mesai['icerde_toplam'] ?></span><span class="tk-sayac-et">İçeride</span></div>
        </div>
        <?php endif; ?>
        <div id="tkSonuc" class="tk-sonuc" role="status" aria-live="polite" hidden></div>
        <div class="tk-eylemler">
            <a href="gunluk_isci_giris_cikis.php?mod=ortak" class="btn btn-primary">🚪 Ortak Çıkış</a>
            <a href="ters_kart_mutabakat.php" class="btn">📋 Mutabakat</a>
            <?php if ($sayim['session_id']): ?><a href="gunluk_isci_puantaj_detay.php?id=<?= (int)$sayim['session_id'] ?>" class="btn">Mesai Detayı</a><?php endif; ?>
            <button type="button" id="tkGeriAlBtn" class="btn btn-danger">↺ Sayımı Geri Al</button>
        </div>
        <p class="muted tk-kucuk">Geri alma yalnız ilk çıkış okutulana kadar yapılabilir; sonrasındaki düzeltmeler yönetici tarafından Mesai Detayı'ndan yapılır.</p>
        <details class="tk-blok">
            <summary>Kart listesi (<?= count($satirlar) ?>)</summary>
            <div class="tk-cip-liste">
                <?php foreach ($satirlar as $r): $rol = (string)$r['rol']; ?>
                <span class="tk-cip tk-cip-<?= h($rol) ?>" title="<?= h(['kalan' => 'Elde kaldı', 'dagitilan' => 'Dağıtıldı', 'zaten_iceride' => 'Zaten içerideydi', 'haric' => 'Hariç: ' . $r['haric_sebep']][$rol] ?? $rol) ?>"><?= h($r['card_no_snapshot']) ?><?= (int)$r['teyitli'] ? ' ✓' : '' ?></span>
                <?php endforeach; ?>
            </div>
            <p class="muted tk-kucuk">Yeşil: dağıtıldı · Gri: elde kaldı · Mavi: zaten içerideydi · Turuncu: hariç (kayıp/bozuk) · ✓: dönmemiş kart için teyit</p>
        </details>
    <?php endif; ?>

    <?php if ($sayim): ?>
    <script type="application/json" id="tkSayimVeri"><?= json_encode(['sayim_id' => (int)$sayim['id'], 'durum' => (string)$sayim['durum'], 'foreman_id' => $cavusId, 'varsayilan_saat' => $sayim['varsayilan_giris_saati']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
    <?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php pdks_ters_dialog(); ?>
</div>
<?php
pdks_nfc_oku_js();
pdks_ters_script($base);
render_footer();
