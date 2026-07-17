<?php
// =========================================================
// firma_eslestirme.php — Tedarikçi Eşleştirme (admin)
// 1) Hareketlerdeki serbest metin tedarikçi isimlerini tanımlarla eşleştirir.
// 2) Yanlışlıkla type='firma' altına eklenmiş tedarikçileri
//    type='tedarikci'ye taşır (Firma → Tedarikçi Taşıma bölümü).
// depo_tasima.php ile aynı desen: listele → seç → onayla → UPDATE + audit.
// =========================================================

declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

$auth_user = require_login();
if (!is_admin()) forbidden('Tedarikçi eşleştirme yalnızca yöneticiler içindir.');

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
            $chk = $pdo->prepare("SELECT COUNT(*) FROM material_definitions WHERE type='tedarikci' AND name = ?");
            $chk->execute([$yeni]);
            if (!(int)$chk->fetchColumn()) throw new RuntimeException('Hedef, tedarikçi tanımlarından biri olmalı.');

            $st = $pdo->prepare("UPDATE material_stock_movements SET firma = ? WHERE firma = ?");
            $st->execute([$yeni, $eski]);
            $n = $st->rowCount();
            audit_log_event('firma_merge', 'stok', null,
                ['firma' => $eski], ['firma' => $yeni, 'satir' => $n]);
            set_flash('success', '"' . $eski . '" → "' . $yeni . '" — ' . $n . ' hareket güncellendi.');

        } elseif ($action === 'tanim_ekle') {
            $isim = normalize_firma(trim((string)($_POST['isim'] ?? '')));
            if ($isim === '' || mb_strlen($isim) > 200) throw new RuntimeException('Geçersiz isim.');
            ensure_definition('tedarikci', $isim);
            audit_log_event('create', 'definitions', null, null,
                ['type' => 'tedarikci', 'name' => $isim, 'source' => 'firma_eslestirme']);
            // Serbest metin büyük/küçük farklıysa hareketleri de normalize et
            $st = $pdo->prepare("UPDATE material_stock_movements SET firma = ? WHERE firma = ? AND firma != ?");
            $st->execute([$isim, trim((string)($_POST['isim'] ?? '')), $isim]);
            set_flash('success', '"' . $isim . '" tedarikçi tanımlarına eklendi.');

        } elseif ($action === 'firma_to_tedarikci') {
            // Seçilen firma tanımlarını tedarikçi türüne taşı.
            // Aynı isimde tedarikçi zaten varsa firma kaydı silinir (birleştirme).
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($i) => $i > 0));
            if (empty($ids)) throw new RuntimeException('Taşınacak firma seçilmedi.');

            $ted_norm = [];
            foreach ($pdo->query("SELECT name FROM material_definitions WHERE type='tedarikci'")->fetchAll(PDO::FETCH_COLUMN) as $_tn) {
                $ted_norm[normalize_text_v2($_tn)] = true;
            }

            $tasindi = 0; $birlesti = 0;
            $sel = $pdo->prepare("SELECT id, name FROM material_definitions WHERE id = ? AND type = 'firma'");
            foreach ($ids as $id) {
                $sel->execute([$id]);
                $row = $sel->fetch();
                if (!$row) continue;
                if (isset($ted_norm[normalize_text_v2((string)$row['name'])])) {
                    // Tedarikçide zaten var → mükerrer firma kaydını sil
                    $pdo->prepare("DELETE FROM material_definitions WHERE id = ?")->execute([$id]);
                    audit_log_event('delete', 'definitions', $id,
                        ['type' => 'firma', 'name' => $row['name']],
                        ['reason' => 'tedarikci_duplicate']);
                    $birlesti++;
                } else {
                    $pdo->prepare("UPDATE material_definitions SET type = 'tedarikci' WHERE id = ?")->execute([$id]);
                    audit_log_event('type_change', 'definitions', $id,
                        ['type' => 'firma', 'name' => $row['name']],
                        ['type' => 'tedarikci']);
                    $ted_norm[normalize_text_v2((string)$row['name'])] = true;
                    $tasindi++;
                }
            }
            set_flash('success', $tasindi . ' firma tedarikçiye taşındı'
                . ($birlesti > 0 ? ', ' . $birlesti . ' mükerrer kayıt birleştirildi' : '') . '.');
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
    $firma_defs = $pdo->query("SELECT name FROM material_definitions WHERE type='tedarikci' ORDER BY name")
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

// ── Firma → Tedarikçi taşıma verisi ───────────────────────
// Müşteri kullanımı (yükleme + kantar) sıfır olan firma tanımları
// muhtemel tedarikçidir → önceden işaretli gelir.
$yuk_use = []; $kan_use = []; $mov_use = [];
try {
    $yuk_use = $pdo->query("SELECT firma, COUNT(*) FROM loading_records WHERE firma != '' GROUP BY firma")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {}
try {
    $kan_use = $pdo->query("SELECT firma_adi, COUNT(*) FROM kantar_fisleri WHERE firma_adi != '' GROUP BY firma_adi")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {}
try {
    $mov_use = $pdo->query("SELECT firma, COUNT(*) FROM material_stock_movements WHERE firma != '' GROUP BY firma")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {}

$firma_tasi = [];
try {
    foreach ($pdo->query("SELECT id, name, is_active FROM material_definitions WHERE type='firma' ORDER BY name")->fetchAll() as $fd) {
        $musteri = (int)($yuk_use[$fd['name']] ?? 0) + (int)($kan_use[$fd['name']] ?? 0);
        $firma_tasi[] = [
            'id'      => (int)$fd['id'],
            'name'    => (string)$fd['name'],
            'musteri' => $musteri,
            'hareket' => (int)($mov_use[$fd['name']] ?? 0),
            'oneri'   => $musteri === 0, // hiç müşteri olarak kullanılmamış
        ];
    }
} catch (PDOException $e) {}
// Önerilenler (muhtemel tedarikçiler) üstte
usort($firma_tasi, fn($a, $b) => [!$a['oneri'], $a['name']] <=> [!$b['oneri'], $b['name']]);

$csrf = csrf_token();
render_header('Tedarikçi Eşleştirme');
render_flash();
?>
<div class="page-head">
    <h1>🔗 Tedarikçi Eşleştirme</h1>
</div>

<div class="card" style="max-width:900px">
    <p class="muted" style="margin-top:0">
        Malzeme hareketlerinde elle girilmiş <b>Tedarikçi/Firma</b> isimlerini
        tedarikçi tanımlarıyla eşleştirir. Eşleştirince geçmiş hareketlerdeki isim
        toplu olarak düzeltilir — raporlarda tek isim altında toplanır.
    </p>
    <p style="font-size:.85rem">
        <span class="def3-chip" style="background:#dcfce7;color:#15803d">✓ Tanımlı: <?= $sayi_esli ?></span>
        &nbsp;<span class="def3-chip" style="background:#fee2e2;color:#b91c1c">Tanımsız: <?= $sayi_essiz ?></span>
        &nbsp;<a href="definitions.php?type=tedarikci" class="muted" style="font-size:.82rem">Tanımlar → Tedarikçi ↗</a>
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
                                <button class="btn btn-sm btn-ghost" title="Bu ismi olduğu gibi tedarikçi tanımlarına ekle">➕ Tanım yap</button>
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

<!-- ── Firma → Tedarikçi Taşıma ─────────────────────────── -->
<div class="card" style="max-width:900px;margin-top:14px">
    <h2 style="margin-top:0;font-size:1.05rem">🚚 Firma → Tedarikçi Taşıma</h2>
    <p class="muted">
        Daha önce yanlışlıkla <b>Firma</b> (ihracat müşterisi) altına eklenmiş tedarikçileri
        seçip <b>Tedarikçi</b> türüne taşıyın. Yükleme/kantarda hiç kullanılmamış firmalar
        muhtemel tedarikçi kabul edilip önceden işaretli gelir — müşterileri işaretlemeyin.
    </p>

    <?php if (empty($firma_tasi)): ?>
    <div class="empty"><p>Firma tanımı yok.</p></div>
    <?php else: ?>
    <form method="post" onsubmit="return confirm('Seçilen firmalar Tedarikçi türüne taşınacak. Onaylıyor musunuz?')">
        <input type="hidden" name="csrf"   value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="firma_to_tedarikci">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th></th>
                        <th>Firma Tanımı</th>
                        <th title="Yükleme + kantar kayıtlarında müşteri olarak kullanım">Müşteri Kullanımı</th>
                        <th title="Malzeme stok hareketlerinde tedarikçi olarak kullanım">Malzeme Hareketi</th>
                        <th>Öneri</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($firma_tasi as $f): ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?= $f['id'] ?>" <?= $f['oneri'] ? 'checked' : '' ?>></td>
                        <td><strong><?= h($f['name']) ?></strong></td>
                        <td><?= $f['musteri'] > 0 ? '<b>' . $f['musteri'] . ' kayıt</b>' : '<span class="muted">—</span>' ?></td>
                        <td><?= $f['hareket'] > 0 ? $f['hareket'] . ' hareket' : '<span class="muted">—</span>' ?></td>
                        <td>
                            <?php if ($f['oneri']): ?>
                            <span style="color:#16a34a;font-weight:700">Tedarikçi olabilir</span>
                            <?php else: ?>
                            <span style="color:#64748b">Müşteri görünüyor</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top:12px">
            <button class="btn btn-primary">🚚 Seçilenleri Tedarikçiye Taşı</button>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
