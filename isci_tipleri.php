<?php
// =========================================================
// isci_tipleri.php — İşçi Tipleri (Günlük İşçi)
//
// ⚠ Faz 9B / H-01 kapanışı: iş kararı KESİNLEŞTİ — günlük işçi devam
// sistemi TAM OLARAK iki sabit sistem tipi destekler: KADIN, ERKEK.
// Eskiden bu sayfa "cinsiyet ENUM DEĞİL, serbest kod/ad" diyerek
// FORKLIFT/USTA/PAKETLEME gibi rastgele kod EKLENMESİNE izin veriyordu —
// bu, tarama/düzeltme/oran katmanlarının HER BİRİNİN kendi (ve BİRBİRİYLE
// ÇELİŞEN) desteklenen-tip kararı vermesine yol açan audit bulgusu H-01'in
// KÖKÜYDÜ. Artık BURASI, config/pdks_gunluk.php'deki TEK paylaşılan
// politikayla (pdks_gunluk_desteklenen_tip_kodlari()) AYNI gerçeği anlatır
// ve YENİ rastgele tip oluşturma İŞ AKIŞI KALDIRILDI — bkz. o dosyanın
// başlığı.
//
// ⚠ pdks_gunluk_tip_olustur() (ham CRUD fonksiyonu) BİLEREK SİLİNMEDİ —
// başka bir kurulumun/test altyapısının genel bir birincil işlem olarak
// ona ihtiyacı olabilir; YALNIZ bu SAYFANIN "ekle" iş akışı kaldırıldı.
//
// ⚠ Mevcut satırlar (ör. üretimde şu an yalnız KADIN/ERKEK var, ama başka
// bir kurulumda tarihsel bir üçüncü tip olabilir) ASLA silinmez/otomatik
// dönüştürülmez — görev talimatı §7. Bu sayfa TÜM satırları (destekli/
// desteksiz) listeler, yalnız DESTEKLENMEYEN satırlar için aktifleştirme
// engellenmez (tarihsel bir satırı aktif TUTMAK istemek admin'in kararı
// olabilir) ama YENİ operasyonel akışlar (tarama/düzeltme/oran) onu HİÇBİR
// ZAMAN seçilebilir kılmaz (bkz. pdks_gunluk_desteklenen_tip_listele()).
//
// Sidebar'a AYRI bir madde olarak eklenmedi (permission fragmentasyonunu
// artırmamak için attendance.worker_cards'a bağlı) — isci_kartlari.php'nin
// baş kısmındaki ikincil bağlantıdan açılır (pdks_nfc_test.php'nin
// personel_kartlar.php'den açılma deseniyle aynı). Faz 9B, Personel
// Takibi'nin 10 kartlık inişini DEĞİŞTİRMEZ — bu sayfa BİLEREK ikincil bir
// yapılandırma ekranı olarak kalır (görev talimatı §10).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_gunluk('worker_cards');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
$hata = ''; $basari = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_gunluk('worker_cards');
    $action = trim($_POST['action'] ?? '');

    // ⚠ 'ekle' (rastgele yeni tip oluşturma) BİLEREK YOK — sistem artık
    // yalnız KADIN/ERKEK'i tanır, üçüncü bir kod bu sayfadan ASLA
    // OLUŞTURULAMAZ (crafted bir POST dahi — action eşleşmediği için hiçbir
    // dal çalışmaz).
    if ($action === 'aktiflik') {
        $id = (int)($_POST['id'] ?? 0);
        $aktif = ($_POST['aktif'] ?? '') === '1';
        $sonuc = pdks_gunluk_tip_aktiflik($id, $aktif, $pdo);
        if ($sonuc['ok']) {
            header('Location: isci_tipleri.php?ok=' . urlencode('Durum güncellendi.'));
            exit;
        }
        $hata = $sonuc['hata'] ?? 'İşlem yapılamadı.';
    }
}
if ($hata === '' && isset($_GET['ok'])) $basari = trim($_GET['ok']);

$tipler = pdks_gunluk_tip_listele(false, $pdo);
$desteklenenKodlar = pdks_gunluk_desteklenen_tip_kodlari();

render_header('İşçi Tipleri');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head">
    <h1>🏷 İşçi Tipleri</h1>
    <div class="page-head-actions">
        <a href="isci_kartlari.php" class="btn btn-ghost">← Kart Havuzu</a>
    </div>
</div>

<?php if ($basari !== ''): ?><div class="flash flash-success"><?= h($basari) ?></div><?php endif; ?>
<?php if ($hata !== ''): ?><div class="flash flash-error"><?= h($hata) ?></div><?php endif; ?>

<div class="card" style="padding:16px 18px;margin-bottom:20px">
    <h2 style="margin-top:0;font-size:1rem">Sabit Sistem Tipleri</h2>
    <p class="muted" style="margin-top:-6px;font-size:.85rem">
        Günlük işçi devam sistemi (giriş/çıkış tarama, puantaj düzeltme, çavuş fiyatlandırma)
        şu an <b>tam olarak iki sabit tip</b> kullanır: <b>KADIN</b> ve <b>ERKEK</b>. Bu bir
        eksiklik değil, bilinçli bir tasarım kararıdır — yeni, rastgele bir işçi tipi
        (ör. "Forklift", "Usta") buradan <b>oluşturulamaz</b>. İleride görev/pozisyon/kategori
        gibi bir ihtiyaç doğarsa, bu <b>işçi tipinden AYRI</b> bir kavram olarak tasarlanacaktır.
    </p>
</div>

<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr><th>Kod</th><th>Ad</th><th>Kapsam</th><th>Durum</th><th class="actions-col">İşlem</th></tr></thead>
<tbody>
<?php foreach ($tipler as $t): $destekli = in_array($t['code'], $desteklenenKodlar, true); ?>
<tr>
    <td class="pdks-uid"><?= h($t['code']) ?></td>
    <td class="pdks-row-name"><?= h($t['name']) ?></td>
    <td>
        <?php if ($destekli): ?>
        <span class="pdks-badge pdks-badge-aktif">Sistem Tipi (sabit)</span>
        <?php else: ?>
        <span class="pdks-badge pdks-badge-pasif">Desteklenmiyor — yalnız geçmiş/görüntüleme</span>
        <?php endif; ?>
    </td>
    <td><span class="pdks-badge <?= $t['is_active'] ? 'pdks-badge-aktif' : 'pdks-badge-pasif' ?>"><?= $t['is_active'] ? 'Aktif' : 'Pasif' ?></span></td>
    <td class="actions-col">
        <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="aktiflik">
            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <input type="hidden" name="aktif" value="<?= $t['is_active'] ? '0' : '1' ?>">
            <button type="submit" class="btn btn-sm"><?= $t['is_active'] ? 'Pasifleştir' : 'Aktifleştir' ?></button>
        </form>
        <?php if ($destekli): ?>
        <div class="muted" style="font-size:.75rem;margin-top:4px">Son aktif sistem tipi pasifleştirilemez.</div>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="pdks-cards mobile-only">
<?php foreach ($tipler as $t): $destekli = in_array($t['code'], $desteklenenKodlar, true); ?>
<div class="pdks-card-item">
    <div class="pdks-card-top">
        <div class="pdks-card-meta">
            <div class="pdks-row-name"><?= h($t['name']) ?></div>
            <div class="pdks-row-sub"><?= h($t['code']) ?> · <?= $destekli ? 'Sistem Tipi (sabit)' : 'Desteklenmiyor' ?></div>
        </div>
        <span class="pdks-badge <?= $t['is_active'] ? 'pdks-badge-aktif' : 'pdks-badge-pasif' ?>"><?= $t['is_active'] ? 'Aktif' : 'Pasif' ?></span>
    </div>
    <div class="pdks-card-actions">
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="aktiflik">
            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <input type="hidden" name="aktif" value="<?= $t['is_active'] ? '0' : '1' ?>">
            <button type="submit" class="btn btn-sm"><?= $t['is_active'] ? 'Pasifleştir' : 'Aktifleştir' ?></button>
        </form>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php render_footer(); ?>
