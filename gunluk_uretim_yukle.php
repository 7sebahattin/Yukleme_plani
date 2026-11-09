<?php
// =========================================================
// gunluk_uretim_yukle.php — Günlük Üretim › Veri Girişi (v507)
//
// Üç yol: (1) Excel (günlük «Sipariş Durum Listesi» ya da toplu «Tarih · Net Kilo» tablosu; birden çok dosya),
// (2) toplu yapıştırma (tarih ; net kg ; kasa ; palet), (3) tek gün elle.
// Excel/yapıştırma önce ÖNİZLENİR, onaydan sonra yazılır (önizleme oturumda tutulur; dosya diske kaydedilmez).
// Yetki: uretim.write (yazma) · uretim.delete (silme). Depo: aktif depo damgalanır.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/xlsx_export.php';
require_once __DIR__ . '/config/uretim.php';

$auth_user = require_login();
if (!can_uretim('write')) forbidden('Günlük Üretim veri girişi için yetkiniz yok. (Gerekli yetki: uretim.write)');

$pdo = db();
$depo = (string)(active_depot() ?? '');
$uid = isset($auth_user['id']) ? (int)$auth_user['id'] : null;
$hazir = uretim_hazir($pdo);
if (session_status() === PHP_SESSION_NONE) session_start();

$bugun = date('Y-m-d');
$yonlen = static function (string $tur, string $msg): never {
    set_flash($tur, $msg);
    header('Location: gunluk_uretim_yukle.php');
    exit;
};
$kgBicim = static fn($n): string => number_format((int)$n, 0, ',', '.');

// ── POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    if (!$hazir) $yonlen('error', 'Günlük Üretim tabloları kurulu değil. Yönetici migrate.php › «Günlük Üretim» kartından kurmalı.');
    $eylem = (string)($_POST['action'] ?? '');

    if ($eylem === 'onizle') {
        $gunler = []; $uyari = []; $hata = [];
        $metin = trim((string)($_POST['toplu_metin'] ?? ''));
        if ($metin !== '') {
            if (strlen($metin) > 200000) $hata[] = 'Yapıştırılan metin çok uzun.';
            else {
                $p = uretim_toplu_ayristir($metin);
                foreach ($p['gunler'] as $g) $gunler[] = $g;
                foreach ($p['hatalar'] as $x) $uyari[] = $x;
            }
        }
        $dosyalar = $_FILES['dosyalar'] ?? null;
        if (is_array($dosyalar) && is_array($dosyalar['name'] ?? null)) {
            $n = count($dosyalar['name']);
            if ($n > URETIM_MAX_DOSYA) { $hata[] = 'Tek seferde en çok ' . URETIM_MAX_DOSYA . ' dosya yüklenebilir.'; $n = 0; }
            for ($i = 0; $i < $n; $i++) {
                $ad = uretim_metin($dosyalar['name'][$i] ?? '', 190);
                $err = (int)($dosyalar['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($err === UPLOAD_ERR_NO_FILE) continue;
                if ($err !== UPLOAD_ERR_OK) { $hata[] = "$ad: dosya yüklenemedi (kod $err)."; continue; }
                $tmp = (string)$dosyalar['tmp_name'][$i];
                if (!is_uploaded_file($tmp)) { $hata[] = "$ad: geçersiz yükleme."; continue; }
                if ((int)$dosyalar['size'][$i] > URETIM_MAX_BAYT) { $hata[] = "$ad: dosya çok büyük (en çok " . (int)(URETIM_MAX_BAYT / 1048576) . ' MB).'; continue; }
                if (strtolower(pathinfo($ad, PATHINFO_EXTENSION)) !== 'xlsx' || file_get_contents($tmp, false, null, 0, 4) !== "PK\x03\x04") { $hata[] = "$ad: yalnız .xlsx dosyası kabul edilir."; continue; }
                $o = uretim_excel_oku($tmp, $ad);
                if (!$o['ok']) { $hata[] = "$ad: " . ($o['hata'] ?? 'okunamadı'); foreach ($o['uyarilar'] as $x) $uyari[] = "$ad: $x"; continue; }
                foreach ($o['uyarilar'] as $x) $uyari[] = "$ad: $x";
                foreach ($o['gunler'] as $g) $gunler[] = $g;
            }
        }
        // Aynı gün birden çok kaynaktan geldiyse sonuncusu geçerli; kullanıcıya bildir.
        $tekil = [];
        foreach ($gunler as $g) {
            if (isset($tekil[$g['tarih']])) $uyari[] = $g['tarih'] . ': birden çok kaynakta var — sonuncusu alındı.';
            $tekil[$g['tarih']] = $g;
        }
        ksort($tekil);
        $gunler = array_values($tekil);
        foreach ($gunler as $g) foreach (uretim_gun_dogrula($g) as $x) $hata[] = $x;
        if (!$gunler && !$hata) $hata[] = 'Yüklenecek dosya ya da yapıştırılmış veri yok.';
        if ($hata) { $_SESSION['uretim_onizleme'] = null; $yonlen('error', implode("\n", array_slice($hata, 0, 12))); }
        $token = bin2hex(random_bytes(16));
        $_SESSION['uretim_onizleme'] = ['token' => $token, 'depo' => $depo, 'gunler' => $gunler, 'uyari' => $uyari, 'zaman' => time()];
        header('Location: gunluk_uretim_yukle.php?onizle=1');
        exit;
    }

    if ($eylem === 'kaydet') {
        $oz = $_SESSION['uretim_onizleme'] ?? null;
        if (!is_array($oz) || !hash_equals((string)($oz['token'] ?? ''), (string)($_POST['token'] ?? '')) || (time() - (int)($oz['zaman'] ?? 0)) > 3600 || ($oz['depo'] ?? null) !== $depo) {
            $yonlen('error', 'Önizleme süresi doldu ya da depo değişti. Dosyayı yeniden yükleyin.');
        }
        $ustune = !empty($_POST['ustune']);
        $yeni = 0; $guncel = 0; $atlanan = [];
        foreach ($oz['gunler'] as $g) {
            $r = uretim_gun_kaydet($pdo, $g, $depo, $uid, $ustune);
            if ($r['ok']) { if (($r['eylem'] ?? '') === 'create') $yeni++; else $guncel++; }
            elseif (!empty($r['var'])) $atlanan[] = $g['tarih'];
            else $atlanan[] = $g['tarih'] . ' (' . ($r['hata'] ?? 'hata') . ')';
        }
        $_SESSION['uretim_onizleme'] = null;
        $msg = "$yeni yeni gün kaydedildi" . ($guncel ? ", $guncel gün güncellendi" : '') . '.';
        if ($atlanan) $msg .= ' Atlanan (zaten kayıtlı — «üzerine yaz» işaretlenmedi): ' . implode(', ', array_slice($atlanan, 0, 15)) . '.';
        $yonlen($atlanan ? 'warning' : 'success', $msg);
    }

    if ($eylem === 'elle') {
        $g = ['tarih' => (string)uretim_tarih($_POST['tarih'] ?? ''), 'net_kg' => (int)(uretim_sayi($_POST['net_kg'] ?? null) ?? 0),
              'kasa' => (int)(uretim_sayi($_POST['kasa'] ?? null) ?? 0), 'palet' => (int)(uretim_sayi($_POST['palet'] ?? null) ?? 0),
              'notu' => (string)($_POST['notu'] ?? ''), 'kaynak' => 'elle', 'kalemler' => []];
        $r = uretim_gun_kaydet($pdo, $g, $depo, $uid, !empty($_POST['ustune']));
        if ($r['ok']) $yonlen('success', $g['tarih'] . ' için ' . $kgBicim($g['net_kg']) . ' kg kaydedildi.');
        $yonlen('error', !empty($r['var']) ? $g['tarih'] . ' için kayıt zaten var — değiştirmek için «Üzerine yaz» kutusunu işaretleyin.' : (string)($r['hata'] ?? 'Kaydedilemedi.'));
    }

    if ($eylem === 'sil') {
        if (!can_uretim('delete')) forbidden('Silme yetkiniz yok. (Gerekli yetki: uretim.delete)');
        $ok = uretim_gun_sil($pdo, (int)($_POST['id'] ?? 0), $depo);
        $yonlen($ok ? 'success' : 'error', $ok ? 'Gün kaydı silindi.' : 'Kayıt bulunamadı.');
    }
    $yonlen('error', 'Geçersiz işlem.');
}

// ── Görünüm verisi ─────────────────────────────────────────────────
$onizleme = null;
if (!empty($_GET['onizle']) && is_array($_SESSION['uretim_onizleme'] ?? null) && ($_SESSION['uretim_onizleme']['depo'] ?? null) === $depo) $onizleme = $_SESSION['uretim_onizleme'];
$mevcut = [];
if ($hazir && $onizleme) {
    $tarihler = array_column($onizleme['gunler'], 'tarih');
    if ($tarihler) {
        $st = $pdo->prepare('SELECT work_date, net_kg FROM ' . URETIM_TABLO . ' WHERE depo = ? AND work_date IN (' . implode(',', array_fill(0, count($tarihler), '?')) . ')');
        $st->execute(array_merge([$depo], $tarihler));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $mevcut[$r['work_date']] = (int)$r['net_kg'];
    }
}
$son = [];
if ($hazir) {
    $st = $pdo->prepare('SELECT id, work_date, net_kg, kasa, palet, kaynak, dosya_adi, created_at FROM ' . URETIM_TABLO . ' WHERE depo = ? ORDER BY work_date DESC LIMIT 20');
    $st->execute([$depo]);
    $son = $st->fetchAll(PDO::FETCH_ASSOC);
}

render_header('Günlük Üretim — Veri Girişi');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/uretim.css?v=' . @filemtime(__DIR__ . '/assets/uretim.css') . '">';
?>
<div class="page-head">
    <h1>🏭 Günlük Üretim — Veri Girişi</h1>
    <div class="page-head-actions">
        <a href="gunluk_uretim.php" class="btn btn-primary">📈 Üretim Raporu</a>
    </div>
</div>
<?php render_flash(); ?>

<?php if (!$hazir): ?>
<div class="card"><p><strong>Günlük Üretim tabloları kurulu değil.</strong> Bir yönetici <a href="migrate.php#uretim">migrate.php › Günlük Üretim</a> kartından kurmalı.</p></div>
<?php else: ?>

<p class="uretim-not">Kayıt deposu: <strong><?= h($depo !== '' ? $depo : '—') ?></strong>. Veriler yalnız bu depo için tutulur; depoyu değiştirmek için üstteki depo adına dokunun.</p>

<?php if ($onizleme): ?>
<div class="card uretim-onizleme">
    <h2>Önizleme — kaydetmeden önce kontrol edin</h2>
    <?php foreach ($onizleme['uyari'] as $u): ?><div class="flash flash-warning"><?= h($u) ?></div><?php endforeach; ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Tarih</th><th class="num">Net kg</th><th class="num">Kasa</th><th class="num">Palet</th><th>Kaynak</th><th>Durum</th></tr></thead>
            <tbody>
            <?php $topKg = 0; foreach ($onizleme['gunler'] as $g): $topKg += (int)$g['net_kg']; $var = isset($mevcut[$g['tarih']]); ?>
                <tr>
                    <td><?= h(date('d.m.Y', strtotime($g['tarih']))) ?></td>
                    <td class="num"><strong><?= $kgBicim($g['net_kg']) ?></strong></td>
                    <td class="num"><?= $kgBicim($g['kasa']) ?></td>
                    <td class="num"><?= $kgBicim($g['palet']) ?></td>
                    <td><?= h($g['kaynak'] === 'excel' ? 'Excel (' . count($g['kalemler']) . ' kalem)' : ($g['kaynak'] === 'toplu' ? 'Toplu' : 'Elle')) ?><?= $g['dosya'] !== '' ? '<br><small>' . h($g['dosya']) . '</small>' : '' ?></td>
                    <td><?= $var ? '<span class="uretim-rozet uyari">Kayıtlı: ' . h($kgBicim($mevcut[$g['tarih']])) . ' kg</span>' : '<span class="uretim-rozet yeni">Yeni</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th><?= count($onizleme['gunler']) ?> gün</th><th class="num"><?= $kgBicim($topKg) ?></th><th></th><th></th><th></th><th></th></tr></tfoot>
        </table>
    </div>
    <form method="post" class="uretim-form">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="kaydet">
        <input type="hidden" name="token" value="<?= h($onizleme['token']) ?>">
        <?php if ($mevcut): ?>
        <label class="uretim-onay"><input type="checkbox" name="ustune" value="1"> Zaten kayıtlı <?= count($mevcut) ?> günün üzerine yaz (eski değerler işlem geçmişinde kalır)</label>
        <?php endif; ?>
        <div class="uretim-btnler">
            <button type="submit" class="btn btn-primary">✓ Kaydet</button>
            <a href="gunluk_uretim_yukle.php" class="btn btn-ghost">Vazgeç</a>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2>1 · Excel yükle</h2>
    <p class="uretim-not">İki biçim otomatik tanınır: günlük <b>«Sipariş Durum Listesi»</b> (2. satırda İlk/Son Tarih, tek gün) ya da <b>toplu tablo</b> (başlıkta <code>Tarih</code> ve <code>Net Kilo</code>, gün başına bir satır). Birden çok dosya seçebilirsiniz · .xlsx · en çok <?= (int)(URETIM_MAX_BAYT / 1048576) ?> MB. Dosya sunucuya kaydedilmez.</p>
    <form method="post" enctype="multipart/form-data" class="uretim-form">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="onizle">
        <input type="file" name="dosyalar[]" accept=".xlsx" multiple required>
        <button type="submit" class="btn btn-primary">Önizle</button>
    </form>
</div>

<div class="card">
    <h2>2 · Toplu yapıştır</h2>
    <p class="uretim-not">Her satır bir gün: <code>tarih ; net kg ; kasa ; palet</code> (kasa ve palet isteğe bağlı; Excel'den kopyalanan sekmeli sütunlar da olur). Örnek: <code>07.11.2026 ; 94697 ; 19286 ; 117</code></p>
    <form method="post" class="uretim-form">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="onizle">
        <textarea name="toplu_metin" rows="7" placeholder="01.11.2026 ; 88120 ; 17900 ; 108&#10;02.11.2026 ; 91340 ; 18500 ; 112"></textarea>
        <button type="submit" class="btn btn-primary">Önizle</button>
    </form>
</div>

<div class="card">
    <h2>3 · Tek gün elle gir</h2>
    <form method="post" class="uretim-form uretim-grid">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="elle">
        <label>Tarih<input type="date" name="tarih" max="<?= h($bugun) ?>" value="<?= h($bugun) ?>" required></label>
        <label>Net kg<input type="text" name="net_kg" inputmode="numeric" autocomplete="off" required></label>
        <label>Kasa<input type="text" name="kasa" inputmode="numeric" autocomplete="off"></label>
        <label>Palet<input type="text" name="palet" inputmode="numeric" autocomplete="off"></label>
        <label class="uretim-genis">Not<input type="text" name="notu" maxlength="255" autocomplete="off"></label>
        <label class="uretim-onay uretim-genis"><input type="checkbox" name="ustune" value="1"> O gün kayıtlıysa üzerine yaz</label>
        <div class="uretim-btnler uretim-genis"><button type="submit" class="btn btn-primary">Kaydet</button></div>
    </form>
</div>

<div class="card">
    <h2>Son girilen günler</h2>
    <?php if (!$son): ?><p class="uretim-not">Bu depo için henüz kayıt yok.</p><?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Tarih</th><th class="num">Net kg</th><th class="num">Kasa</th><th class="num">Palet</th><th>Kaynak</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($son as $r): ?>
                <tr>
                    <td><?= h(date('d.m.Y', strtotime((string)$r['work_date']))) ?></td>
                    <td class="num"><strong><?= $kgBicim($r['net_kg']) ?></strong></td>
                    <td class="num"><?= $kgBicim($r['kasa']) ?></td>
                    <td class="num"><?= $kgBicim($r['palet']) ?></td>
                    <td><?= h((string)$r['kaynak']) ?><?= $r['dosya_adi'] !== '' ? ' · <small>' . h((string)$r['dosya_adi']) . '</small>' : '' ?></td>
                    <td class="actions-col">
                        <?php if (can_uretim('delete')): ?>
                        <form method="post" onsubmit="return confirm('<?= h(date('d.m.Y', strtotime((string)$r['work_date']))) ?> günlük üretim kaydı silinsin mi?');" style="display:inline">
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="sil">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Sil</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php render_footer(); ?>
