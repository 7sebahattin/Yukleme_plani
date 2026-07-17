<?php
// =========================================================
// firma_eslestirme.php — Malzeme hareketlerindeki serbest metin
// Tedarikçi/Firma isimlerini firma tanımlarıyla eşleştirme (admin).
// depo_tasima.php ile aynı desen: listele → hedef seç → onayla → UPDATE + audit.
// =========================================================

declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

$auth_user = require_login();
if (!is_admin()) forbidden('Firma eşleştirme yalnızca yöneticiler içindir.');

$pdo = db();

// ── POST: eşleştir / tanım olarak ekle ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'merge') {
            $eski = trim((string)($_POST['eski'] ?? ''));
            $yeni = trim((string)($_POST['yeni'] ?? ''));
            if ($eski === '' || $yeni === '') throw new RuntimeException('Kaynak ve hedef firma zorunlu.');
            if ($eski === $yeni) throw new RuntimeException('Kaynak ve hedef aynı — işlem gerekmez.');
            // Hedef, firma tanımlarından biri olmalı (serbest hedefe izin yok)
            $chk = $pdo->prepare("SELECT COUNT(*) FROM material_definitions WHERE type='firma' AND name = ?");
            $chk->execute([$yeni]);
            if (!(int)$chk->fetchColumn()) throw new RuntimeException('Hedef, firma tanımlarından biri olmalı.');

            $st = $pdo->prepare("UPDATE material_stock_movements SET firma = ? WHERE firma = ?");
            $st->execute([$yeni, $eski]);
            $n = $st->rowCount();
            audit_log_event('firma_merge', 'stok', null,
                ['firma' => $eski], ['firma' => $yeni, 'satir' => $n]);
            set_flash('success', '"' . $eski . '" → "' . $yeni . '" — ' . $n . ' hareket güncellendi.');

        } elseif ($action === 'tanim_ekle') {
            $isim = normalize_firma(trim((string)($_POST['isim'] ?? '')));
            if ($isim === '' || mb_strlen($isim) > 200) throw new RuntimeException('Geçersiz isim.');
            ensure_definition('firma', $isim);
            audit_log_event('create', 'definitions', null, null,
                ['type' => 'firma', 'name' => $isim, 'source' => 'firma_eslestirme']);
            // Serbest metin büyük/küçük farklıysa hareketleri de normalize et
            $st = $pdo->prepare("UPDATE material_stock_movements SET firma = ? WHERE firma = ? AND firma != ?");
            $st->execute([$isim, trim((string)($_POST['isim'] ?? '')), $isim]);
            set_flash('success', '"' . $isim . '" firma tanımlarına eklendi.');
        }
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
    }
    header('Location: firma_eslestirme.php');
    exit;
}

// ── Veri: hareketlerdeki firma değerleri + tanımlar ───────
$firma_defs = [];
try {
    $firma_defs = $pdo->query("SELECT name FROM material_definitions WHERE type='firma' ORDER BY name")
        ->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

$def_norm = [];   // normalize → canonical tanım adı
foreach ($firma_defs as $d) $def_norm[normalize_text_v2($d)] = $d;

$kullanimlar = [];
try {
    $kullanimlar = $pdo->query("
        SELECT firma, COUNT(*) AS adet
        FROM material_stock_movements
        WHERE firma IS NOT NULL AND firma != ''
        GROUP BY firma ORDER BY adet DESC, firma
    ")->fetchAll();
} catch (PDOException $e) {}

// Satırları sınıflandır: tam eşleşen / benzer tanım bulunan / tanımsız
$satirlar = [];
$sayi_esli = 0; $sayi_essiz = 0;
foreach ($kullanimlar as $k) {
    $isim  = (string)$k['firma'];
    $norm  = normalize_text_v2($isim);
    $tanim = $def_norm[$norm] ?? null;   // TR-duyarsız eşleşme
    $tam   = $tanim !== null && $tanim === $isim;
    if ($tanim !== null) $sayi_esli++; else $sayi_essiz++;
    $satirlar[] = [
        'isim'   => $isim,
        'adet'   => (int)$k['adet'],
        'tanim'  => $tanim,   // null = hiçbir tanıma benzemiyor
        'tam'    => $tam,     // birebir aynı yazım
    ];
}
// Tanımsızlar üstte
usort($satirlar, fn($a, $b) => [$a['tanim'] !== null, -$a['adet']] <=> [$b['tanim'] !== null, -$b['adet']]);

$csrf = csrf_token();
render_header('Firma Eşleştirme');
render_flash();
?>
<div class="page-head">
    <h1>🔗 Firma Eşleştirme</h1>
</div>

<div class="card" style="max-width:900px">
    <p class="muted" style="margin-top:0">
        Malzeme hareketlerinde elle girilmiş <b>Tedarikçi/Firma</b> isimlerini
        firma tanımlarıyla eşleştirir. Eşleştirince geçmiş hareketlerdeki isim
        toplu olarak düzeltilir — raporlarda tek isim altında toplanır.
    </p>
    <p style="font-size:.85rem">
        <span class="def3-chip" style="background:#dcfce7;color:#15803d">✓ Tanımlı: <?= $sayi_esli ?></span>
        &nbsp;<span class="def3-chip" style="background:#fee2e2;color:#b91c1c">Tanımsız: <?= $sayi_essiz ?></span>
        &nbsp;<a href="definitions.php?type=firma" class="muted" style="font-size:.82rem">Tanımlar → Firma ↗</a>
    </p>

    <?php if (empty($satirlar)): ?>
    <div class="empty"><p>Malzeme hareketlerinde firma bilgisi girilmiş kayıt yok.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Hareketlerdeki İsim</th>
                    <th>Kayıt</th>
                    <th>Durum</th>
                    <th style="min-width:260px">Eşleştir</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($satirlar as $s): ?>
                <tr>
                    <td><strong><?= h($s['isim']) ?></strong></td>
                    <td><?= $s['adet'] ?></td>
                    <td>
                        <?php if ($s['tam']): ?>
                        <span style="color:#16a34a;font-weight:700">✓ Tanımlı</span>
                        <?php elseif ($s['tanim'] !== null): ?>
                        <span style="color:#d97706;font-weight:700" title="Tanım var ama yazım farklı">≈ <?= h($s['tanim']) ?></span>
                        <?php else: ?>
                        <span style="color:#dc2626;font-weight:700">Tanımsız</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                            <form method="post" style="display:flex;gap:6px;align-items:center">
                                <input type="hidden" name="csrf"   value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="merge">
                                <input type="hidden" name="eski"   value="<?= h($s['isim']) ?>">
                                <select name="yeni" required style="max-width:190px;font-size:.85rem">
                                    <option value="">— hedef tanım —</option>
                                    <?php foreach ($firma_defs as $d): if ($d === $s['isim']) continue; ?>
                                    <option value="<?= h($d) ?>" <?= $s['tanim'] === $d && !$s['tam'] ? 'selected' : '' ?>><?= h($d) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-primary"
                                        onclick="return confirm('&quot;<?= h($s['isim']) ?>&quot; isimli <?= $s['adet'] ?> hareket seçilen tanıma taşınacak. Onaylıyor musunuz?')">Eşleştir</button>
                            </form>
                            <?php if ($s['tanim'] === null): ?>
                            <form method="post">
                                <input type="hidden" name="csrf"   value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="tanim_ekle">
                                <input type="hidden" name="isim"   value="<?= h($s['isim']) ?>">
                                <button class="btn btn-sm btn-ghost" title="Bu ismi olduğu gibi firma tanımlarına ekle">➕ Tanım yap</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
