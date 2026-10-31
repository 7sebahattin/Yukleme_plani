<?php
// =========================================================
// cavus_fis_rapor.php — Etkilenen Fişler Raporu (v464)
//
// SALT OKUNUR: Fiş Excel'i ile yazılmış fişleri (audit `fis_yukle`) tarih / çavuş / «Yarım > 0» süzgecinden geçirir; her fiş için
// dönem tablosundan Yarım kararlı kayıtların TİP KIRILIMINI gösterir. «Erkek Yarım > 0» olanlar ✱ ile işaretlenir: eski «12 + 1 =
// Tam + Yarım» yorumundan kalma olası yanlış (yeni kuralda «+1» Şeritçi'dir). YAZMA / POST YOK; CSV/XLSX'e sütun eklenmez.
// YALNIZ yönetici. Düzeltme: Excel'i yeni komutla yeniden üretin → Fiş Excel'i Yükle → «Üstüne yaz».
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/pdks_faz8j.php';
require_once __DIR__ . '/config/pdks_fis.php';
$auth_user = require_login();
require_pdks_gunluk('daily_reports');
if (!function_exists('is_admin') || !is_admin()) forbidden('Etkilenen fişler raporu yalnız yönetici içindir.');
header('Cache-Control: no-store');   // ticari veri: tarayıcı ve SW önbelleğine yazılmaz
$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_faz8b_sayfa_kapisi($pdo);
$depo = function_exists('active_depot') ? (string)(active_depot() ?? '') : '';
$cavuslar = pdks_fis_cavuslar($pdo);

// Süzgeç (GET; yalnız okuma)
$tarihOk = static fn(string $t): bool => pdks_fis_tarih_gecerli($t);
$bit = trim((string)($_GET['bit'] ?? '')); if (!$tarihOk($bit)) $bit = date('Y-m-d');
$bas = trim((string)($_GET['bas'] ?? '')); if (!$tarihOk($bas)) $bas = date('Y-m-d', strtotime('-90 day'));
if ($bas > $bit) { $x = $bas; $bas = $bit; $bit = $x; }
$cid = (int)($_GET['cavus'] ?? 0);
$filtreVar = isset($_GET['bas']) || isset($_GET['bit']) || isset($_GET['cavus']) || isset($_GET['f']);
$yalnizYarim = !$filtreVar || !empty($_GET['yarim']);          // ilk açılışta varsayılan AÇIK
$geriGizle = !$filtreVar || !empty($_GET['aktif']);            // geri alınmış fişleri varsayılan gizle
$satirlar = pdks_fis_etkilenen_rapor($pdo, $cid > 0 ? $cid : null, $bas, $bit, $yalnizYarim, $geriGizle, 500);
$kisaltildi = count($satirlar) >= 500;
$fd_tarih = static fn($t) => $t ? date('d.m.Y', strtotime((string)$t)) : '—';
$durumRozet = ['aktif' => ['Aktif', 'fy-b-hazir'], 'geri' => ['Geri alındı', 'fy-b-zaten'], 'kesin' => ['Kesin hakediş', 'fy-b-uyari']];
$tipMetni = static function (array $tip): string { $p = []; foreach ($tip as $ad => $n) $p[] = $ad . ' ' . (int)$n; return implode(' · ', $p); };
$supheli = count(array_filter($satirlar, static fn($r) => $r['erkek_yarim']));

render_header('Etkilenen Fişler');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>
<div class="page-head">
    <h1>📋 Etkilenen Fişler</h1>
    <div class="page-head-actions">
        <a href="cavus_fis_geri_al.php" class="btn">↩ Fiş yüklemelerini geri al</a>
        <a href="gunluk_fis_yukle.php" class="btn btn-geri">← Fiş Excel'i Yükle</a>
    </div>
</div>

<div class="fy-sayfa fd-sayfa" id="fisRapor">
    <p class="fy-yonerge">Yazılmış fişlerden <b>Yarım gün</b> içerenleri listeler. ✱ işaretli satır: Yarım kararı <b>Erkek</b> işçiye ait — eski kuralla «12 + 1» yanlışlıkla «12 Tam + 1 Yarım» yazılmış olabilir (yeni kuralda +1 Şeritçi). Düzeltme: Excel'i yeni komutla yeniden üretin → <b>Fiş Excel'i Yükle</b> → «Üstüne yaz».</p>
    <form method="get" class="pdks-filter-bar fd-filtre" data-oto-filtre>
        <input type="hidden" name="f" value="1">
        <select name="cavus" aria-label="Çavuş">
            <option value="">Tüm çavuşlar</option>
            <?php foreach ($cavuslar as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $c['id'] === $cid ? ' selected' : '' ?>><?= h($c['code'] . ' · ' . $c['name']) ?></option><?php endforeach; ?>
        </select>
        <input type="date" name="bas" value="<?= h($bas) ?>" aria-label="Başlangıç">
        <input type="date" name="bit" value="<?= h($bit) ?>" aria-label="Bitiş">
        <label class="fd-ck"><input type="checkbox" name="yarim" value="1"<?= $yalnizYarim ? ' checked' : '' ?>> Yalnız Yarım &gt; 0</label>
        <label class="fd-ck"><input type="checkbox" name="aktif" value="1"<?= $geriGizle ? ' checked' : '' ?>> Geri alınanları gizle</label>
        <?= pdks_oto_filtre_noscript() ?>
    </form>

    <div class="fy-ozet" role="status">
        <span><b><?= count($satirlar) ?></b> fiş</span>
        <span class="fy-b-uyari"><b><?= (int)$supheli ?></b> ✱ olası yanlış yorum</span>
        <span><?= h($fd_tarih($bas)) ?> – <?= h($fd_tarih($bit)) ?></span>
    </div>
    <?php if ($kisaltildi): ?><div class="fy-not fy-not-uyari">⚠ Liste ilk 500 fişle sınırlıdır; aralığı daraltın.</div><?php endif; ?>

    <?php if (!$satirlar): ?>
    <div class="card"><p>Bu süzgeçle fiş bulunamadı.</p></div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="fy-tablo fd-tablo">
        <thead><tr><th>Fiş</th><th>Tarih</th><th>Çavuş</th><th>Yarım</th><th>Yarım (tip)</th><th>Durum</th><th>Mesai</th></tr></thead>
        <tbody>
        <?php foreach ($satirlar as $r): $dr = $durumRozet[$r['durum']] ?? [$r['durum'], 'fy-b-zaten']; ?>
            <tr class="<?= $r['erkek_yarim'] ? 'fy-s-onay' : '' ?>">
                <td><b><?= h($r['fis_no']) ?></b><?= $r['rev'] > 0 ? ' <small>rev ' . (int)$r['rev'] . '</small>' : '' ?><?= $r['ustune'] ? ' <small title="Üstüne yazılarak yazıldı">🔄</small>' : '' ?></td>
                <td><?= h($fd_tarih($r['tarih'])) ?></td>
                <td><?= h($r['cavus']) ?></td>
                <td><?= (int)$r['yarim'] ?></td>
                <td><?= $r['yarim_tip'] ? h($tipMetni($r['yarim_tip'])) : '—' ?><?= $r['erkek_yarim'] ? ' <span class="fy-rozet fy-b-uyari" title="Erkek Yarım: olası «12+1» yanlış yorumu">✱</span>' : '' ?></td>
                <td><span class="fy-rozet <?= h($dr[1]) ?>"><?= h($dr[0]) ?></span></td>
                <td><a class="btn" href="gunluk_isci_puantaj_detay.php?id=<?= (int)$r['session_id'] ?>">Mesai Detayı</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php pdks_liste_ui_js(); ?>
<?php render_footer(); ?>
