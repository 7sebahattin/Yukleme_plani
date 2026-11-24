<?php
// =========================================================
// ajanda.php — Ajanda: KİŞİSEL görev / hatırlatma defteri (v574). Mantık config/ajanda_core.php.
//   ?gorunum=acik|tamam|tumu   (varsayılan acik: gecikmiş · bugün · yarın · bu hafta · daha sonra)
// Her kayıt yalnız sahibine aittir (yönetici dahil kimse başkasınınkini görmez); depo filtresi yok.
// POST (PRG): ekle · guncelle · durum · sil · tamamlananlari_sil — CSRF + kullanıcı id'si oturumdan.
// Şema: ajanda_kayitlar — yalnız migrate.php › "Ajanda" kartı; tablo yoksa kurulum notu. Kapı: muhasebe_sayfa_can.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/ajanda_core.php';
$auth_user = require_login();
if (!muhasebe_sayfa_can('ajanda')) { forbidden(); }
header('Cache-Control: no-store');
$pdo = db();
$uid = (int)$auth_user['id'];
$hazir = ajanda_hazir($pdo);

$gorunum = in_array($_GET['gorunum'] ?? '', ['acik', 'tamam', 'tumu'], true) ? (string)$_GET['gorunum'] : 'acik';
$bugun = date('Y-m-d');

if ($hazir && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $islem = is_string($_POST['islem'] ?? null) ? $_POST['islem'] : '';
    $id = (int)($_POST['id'] ?? 0);
    $giris = [
        'baslik'    => is_string($_POST['baslik'] ?? null) ? $_POST['baslik'] : '',
        'not_metni' => is_string($_POST['not_metni'] ?? null) ? $_POST['not_metni'] : '',
        'tarih'     => is_string($_POST['tarih'] ?? null) ? $_POST['tarih'] : '',
        'saat'      => is_string($_POST['saat'] ?? null) ? $_POST['saat'] : '',
    ];
    $r = match ($islem) {
        'ekle'                => ajanda_ekle($pdo, $uid, $giris),
        'guncelle'            => ajanda_guncelle($pdo, $uid, $id, $giris),
        'durum'               => ajanda_durum($pdo, $uid, $id, ($_POST['tamam'] ?? '') === '1'),
        'sil'                 => ajanda_sil($pdo, $uid, $id),
        'tamamlananlari_sil'  => ajanda_tamamlananlari_sil($pdo, $uid),
        default               => ['ok' => false, 'mesaj' => 'Geçersiz işlem.'],
    };
    set_flash($r['ok'] ? 'success' : 'error', $r['mesaj']);
    header('Location: ajanda.php' . ($gorunum !== 'acik' ? '?gorunum=' . $gorunum : ''));
    exit;
}

$ozet = $hazir ? ajanda_ozet($pdo, $uid, $bugun) : ['gecikmis' => 0, 'bugun' => 0, 'hafta' => 0, 'acik' => 0, 'tamam' => 0];
$kayitlar = $hazir ? ajanda_listele($pdo, $uid, $gorunum) : [];
$gruplar = ($hazir && $gorunum === 'acik') ? ajanda_grupla($kayitlar, $bugun) : [];
$GRUP_ADI = ['gecikmis' => 'Gecikmiş', 'bugun' => 'Bugün', 'yarin' => 'Yarın', 'hafta' => 'Bu hafta', 'sonra' => 'Daha sonra'];

/** Tek kayıt kartı (düzenleme formu dahil). */
$kayit_html = function (array $r, string $sinif) use ($gorunum, $bugun): string {
    $id = (int)$r['id'];
    $tamam = (int)$r['tamam'] === 1;
    $saat = (string)($r['saat'] ?? '');
    $meta = ajanda_tarih_etiket((string)$r['tarih']) . ($saat !== '' ? ' · ' . $saat : '');
    $not = (string)($r['not_metni'] ?? '');
    ob_start(); ?>
    <div class="ka-kayit <?= h($sinif) ?><?= $tamam ? ' tamam' : '' ?>">
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="islem" value="durum">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="tamam" value="<?= $tamam ? '0' : '1' ?>">
            <button type="submit" class="ka-tik<?= $tamam ? ' dolu' : '' ?>" aria-label="<?= $tamam ? 'Yeniden aç' : 'Tamamlandı işaretle' ?>" title="<?= $tamam ? 'Yeniden aç' : 'Tamamlandı' ?>"><?= $tamam ? '✓' : '' ?></button>
        </form>
        <div class="ka-kayit-govde">
            <div class="ka-kayit-baslik"><?= h((string)$r['baslik']) ?></div>
            <div class="ka-kayit-meta"><?= h($meta) ?></div>
            <?php if ($not !== ''): ?><div class="ka-kayit-not"><?= h($not) ?></div><?php endif; ?>
            <details class="ka-duzenle">
                <summary>✏️ Düzenle</summary>
                <form method="post" class="ka-yeni-form" style="margin-top:8px">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="islem" value="guncelle">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input class="baslik-alani" type="text" name="baslik" value="<?= h((string)$r['baslik']) ?>" maxlength="<?= AJ_BASLIK_MAX ?>" required aria-label="Başlık">
                    <input type="date" name="tarih" value="<?= h((string)$r['tarih']) ?>" required aria-label="Tarih">
                    <input class="ka-zaman" type="time" name="saat" value="<?= h($saat) ?>" aria-label="Saat">
                    <textarea class="tam" name="not_metni" maxlength="<?= AJ_NOT_MAX ?>" aria-label="Not"><?= h($not) ?></textarea>
                    <div class="tam"><button type="submit" class="btn btn-primary btn-sm">Kaydet</button></div>
                </form>
            </details>
        </div>
        <div class="ka-kayit-araclar">
            <form method="post" data-ka-onay="Bu kayıt silinsin mi?">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="islem" value="sil">
                <input type="hidden" name="id" value="<?= $id ?>">
                <button type="submit" class="btn btn-ghost btn-sm" aria-label="Sil" title="Sil">🗑️</button>
            </form>
        </div>
    </div>
    <?php return (string)ob_get_clean();
};

render_header('Ajanda');
echo '<link rel="stylesheet" href="assets/kullanici_ayar.css?v=' . (int)@filemtime(__DIR__ . '/assets/kullanici_ayar.css') . '">' . "\n";
?>
<div class="ka-sayfa">
    <h1 class="ka-baslik">📅 Ajanda</h1>
    <p class="ka-alt">Kişisel görev ve hatırlatma listeniz. Yalnız siz görürsünüz — yönetici dahil kimse sizin kayıtlarınıza erişemez.</p>

    <?php render_flash(); ?>

<?php if (!$hazir): ?>
    <div class="ka-uyari">
        <strong>Ajanda henüz kurulmamış.</strong>
        <?php if (is_admin()): ?>
            <a href="migrate.php#ajanda">Şema Migrasyon › Ajanda</a> kartından <em>Ajanda Tablosunu Oluştur</em> düğmesine basın (yalnızca yeni bir tablo ekler, mevcut veriye dokunmaz).
        <?php else: ?>
            Kurulum için bir yöneticiye başvurun.
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="ka-ozet">
        <a class="ka-ozet-kutu gecikmis" href="ajanda.php"><b><?= $ozet['gecikmis'] ?></b><span>Gecikmiş</span></a>
        <a class="ka-ozet-kutu bugun" href="ajanda.php"><b><?= $ozet['bugun'] ?></b><span>Bugün</span></a>
        <a class="ka-ozet-kutu" href="ajanda.php"><b><?= $ozet['hafta'] ?></b><span>Önümüzdeki 7 gün</span></a>
        <a class="ka-ozet-kutu" href="ajanda.php?gorunum=tamam"><b><?= $ozet['tamam'] ?></b><span>Tamamlanan</span></a>
    </div>

    <div class="card">
        <h2 class="ka-kart-baslik">➕ Yeni kayıt</h2>
        <form method="post" class="ka-yeni-form">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="islem" value="ekle">
            <input class="baslik-alani" type="text" name="baslik" placeholder="Ne yapılacak? (ör. Beyan evraklarını kontrol et)" maxlength="<?= AJ_BASLIK_MAX ?>" required aria-label="Başlık">
            <input type="date" name="tarih" value="<?= h($bugun) ?>" required aria-label="Tarih">
            <input class="ka-zaman" type="time" name="saat" aria-label="Saat (isteğe bağlı)">
            <textarea class="tam" name="not_metni" placeholder="Not (isteğe bağlı)" maxlength="<?= AJ_NOT_MAX ?>" aria-label="Not"></textarea>
            <div class="tam"><button type="submit" class="btn btn-primary">Ajandaya ekle</button></div>
        </form>
    </div>

    <nav class="ka-sekmeler" aria-label="Ajanda görünümü">
        <a class="ka-sekme<?= $gorunum === 'acik' ? ' aktif' : '' ?>" href="ajanda.php">Açık (<?= $ozet['acik'] ?>)</a>
        <a class="ka-sekme<?= $gorunum === 'tamam' ? ' aktif' : '' ?>" href="ajanda.php?gorunum=tamam">Tamamlanan (<?= $ozet['tamam'] ?>)</a>
        <a class="ka-sekme<?= $gorunum === 'tumu' ? ' aktif' : '' ?>" href="ajanda.php?gorunum=tumu">Tümü</a>
    </nav>

    <?php if (!$kayitlar): ?>
        <div class="card ka-bos">
            <?= $gorunum === 'tamam' ? 'Henüz tamamlanan kayıt yok.' : ($gorunum === 'tumu' ? 'Ajandanız boş.' : 'Açık kaydınız yok. Yukarıdan yeni bir hatırlatma ekleyebilirsiniz.') ?>
        </div>
    <?php elseif ($gorunum === 'acik'): ?>
        <?php foreach ($GRUP_ADI as $anahtar => $adi): if (!$gruplar[$anahtar]) continue; ?>
            <section class="ka-grup <?= h($anahtar) ?>">
                <h2 class="ka-grup-baslik"><?= h($adi) ?> <span class="sayi"><?= count($gruplar[$anahtar]) ?></span></h2>
                <?php foreach ($gruplar[$anahtar] as $r) echo $kayit_html($r, $anahtar === 'gecikmis' ? 'gecikmis' : ($anahtar === 'bugun' ? 'bugun' : '')); ?>
            </section>
        <?php endforeach; ?>
    <?php else: ?>
        <section class="ka-grup">
            <?php foreach ($kayitlar as $r) echo $kayit_html($r, (int)$r['tamam'] === 1 ? '' : ((string)$r['tarih'] < $bugun ? 'gecikmis' : ((string)$r['tarih'] === $bugun ? 'bugun' : ''))); ?>
        </section>
        <?php if ($gorunum === 'tamam'): ?>
            <form method="post" data-ka-onay="Tamamlanan tüm kayıtlar kalıcı olarak silinsin mi?">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="islem" value="tamamlananlari_sil">
                <button type="submit" class="btn btn-ghost">🗑️ Tamamlananların hepsini sil</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
</div>
<script src="assets/kullanici_ayar.js?v=<?= (int)@filemtime(__DIR__ . '/assets/kullanici_ayar.js') ?>"></script>
<?php render_footer();
