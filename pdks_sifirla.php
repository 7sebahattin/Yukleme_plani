<?php
// =========================================================
// pdks_sifirla.php — Personel Takibi TEST VERİSİNİ sıfırla (TEK SEFERLİK)
//
// SADECE ADMIN. GET yalnız önizler; silme POST + CSRF + onay kutusu +
// "SIFIRLA" yazma + parmak izi eşleşmesi ister (CLAUDE.md: yalnız açık GO).
// Çekirdek ve güvenlik katmanları: config/pdks_sifirlama.php.
// İş bitince bu sayfa depodan kaldırılmalıdır.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/pdks_sifirlama.php';

$auth_user = require_login();
if (!is_admin()) forbidden('Bu araç yalnızca yöneticiler içindir.');

$pdo      = db();
$tablolar = pdks_sifir_tablolar();
$sayim    = pdks_sifir_sayim($pdo);
$toplam   = pdks_sifir_toplam($sayim);
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $onay   = ($_POST['onay'] ?? '') === '1';
    $yazi   = mb_strtoupper(trim((string)($_POST['onay_yazi'] ?? '')), 'UTF-8');
    $iz     = (string)($_POST['iz'] ?? '');

    if ($toplam === 0) {
        $error = 'Silinecek veri yok — tablolar zaten boş.';
    } elseif (!$onay || $yazi !== 'SIFIRLA') {
        $error = 'Onay kutusunu işaretleyip kutuya SIFIRLA yazmalısınız.';
    } elseif (!hash_equals(pdks_sifir_parmak_izi($sayim), $iz)) {
        $error = 'Siz sayfayı açtıktan sonra veriler değişti (ör. yeni tarama yapıldı). '
               . 'Hiçbir şey silinmedi — sayfayı yenileyip sayıları tekrar kontrol edin.';
    } else {
        $zaman = date('Ymd_His');
        try {
            $yedek   = pdks_sifir_yedekle($pdo, $zaman);
            $silinen = pdks_sifir_sil($pdo, $yedek);
            audit_log_event('pdks_reset', 'pdks', null, $sayim, [
                'silinen'      => $silinen,
                'yedek_onek'   => "pdks_yedek_{$zaman}_",
            ]);
            set_flash('success', 'Sıfırlama tamamlandı: ' . array_sum($silinen)
                . ' satır silindi. Yedek tablolar: pdks_yedek_' . $zaman . '_*');
            header('Location: pdks_sifirla.php');
            exit;
        } catch (Throwable $e) {
            error_log('[pdks_sifirla] ' . $e->getMessage());
            $error = 'İşlem yapılmadı (silinen veri yok): ' . $e->getMessage();
            $sayim  = pdks_sifir_sayim($pdo);
            $toplam = pdks_sifir_toplam($sayim);
        }
    }
}

// Silinecek çavuşların listesi
$cavuslar = [];
if ($sayim['foremen'] !== null) {
    $cavuslar = $pdo->query('SELECT id, code, name, is_active, created_at FROM foremen ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}

// Daha önce alınmış yedek tabloları (bilgi amaçlı)
$yedekler = [];
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
    try {
        $yedekler = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'pdks\\_yedek\\_%'
              ORDER BY TABLE_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {}
}

$csrf = csrf_token();
render_header('Personel Takibi Sıfırlama');
?>
<div class="page-head">
    <h1>🧹 Personel Takibi Sıfırlama</h1>
</div>

<?php render_flash(); ?>

<div class="card" style="max-width:760px">
    <p class="muted" style="margin-top:0">
        Test dönemine ait <b>tüm çavuşları</b> ve çavuşlara bağlı <b>tüm işlemleri</b> siler.
        Silmeden önce her tablo aynı veritabanına <code>pdks_yedek_…</code> adıyla kopyalanır.
        <b>Kart Havuzu, işçi tipleri ve işlem geçmişi korunur.</b>
    </p>

    <?php if ($error !== ''): ?>
    <div class="flash flash-error"><?= h($error) ?></div>
    <?php endif; ?>

    <h3 style="margin-bottom:8px">Silinecek veriler</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Veri</th><th style="text-align:right">Satır</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($tablolar, true) as $t => $label): $s = $sayim[$t]; ?>
                <tr>
                    <td><?= h($label) ?> <small class="muted">(<?= h($t) ?>)</small></td>
                    <td style="text-align:right">
                        <?php if ($s === null): ?><span class="muted">tablo yok</span>
                        <?php elseif ($s['adet'] === 0): ?><span style="color:#16a34a">✔ boş</span>
                        <?php else: ?><b><?= (int)$s['adet'] ?></b><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($cavuslar): ?>
    <h3 style="margin:16px 0 8px">Silinecek çavuşlar (<?= count($cavuslar) ?>)</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Kod</th><th>Ad</th><th>Durum</th><th>Oluşturma</th></tr></thead>
            <tbody>
            <?php foreach ($cavuslar as $c): ?>
                <tr>
                    <td><?= h($c['code']) ?></td>
                    <td><?= h($c['name']) ?></td>
                    <td><?= (int)$c['is_active'] === 1 ? 'Aktif' : 'Pasif' ?></td>
                    <td><?= h($c['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($toplam === 0): ?>
        <div class="flash flash-success" style="margin-top:16px">✔ Silinecek veri yok — Personel Takibi temiz.</div>
    <?php else: ?>
    <form method="post" action="pdks_sifirla.php" style="margin-top:16px">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="iz" value="<?= h(pdks_sifir_parmak_izi($sayim)) ?>">

        <label style="display:flex;gap:8px;align-items:flex-start">
            <input type="checkbox" name="onay" value="1">
            <span>Yukarıdaki <b><?= $toplam ?></b> satırın <b>kalıcı olarak</b> silineceğini anladım.
            Giriş/çıkış ekranında şu an tarama yapılmıyor.</span>
        </label>

        <label style="display:block;margin-top:12px">
            Onaylamak için <b>SIFIRLA</b> yazın:
            <input type="text" name="onay_yazi" autocomplete="off" required style="display:block;margin-top:6px;max-width:220px">
        </label>

        <div style="margin-top:16px">
            <button type="submit" class="btn btn-danger"
                    onclick="return confirm('Personel Takibi verileri yedeklenip silinecek. Emin misiniz?')">
                🧹 Yedekle ve Sıfırla
            </button>
            <a href="personel_takip.php" class="btn btn-ghost">Vazgeç</a>
        </div>
    </form>
    <?php endif; ?>

    <?php if ($yedekler): ?>
    <h3 style="margin:20px 0 8px">Veritabanındaki yedek tablolar</h3>
    <p class="muted" style="margin-top:0">Geri dönüş gerekirse bu tablolardan yüklenir. Sistem birkaç gün sorunsuz çalıştıktan sonra silinebilir.</p>
    <ul style="margin:0 0 0 18px"><?php foreach ($yedekler as $y): ?><li><code><?= h($y) ?></code></li><?php endforeach; ?></ul>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
