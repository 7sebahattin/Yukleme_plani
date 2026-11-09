<?php
// =========================================================
// _beyansiz_sekme.php — Beyansız Yüklemeler sekmesi (v498)
// beyanlar.php `?sekme=beyansiz` iken render_header()'dan SONRA include eder;
// beyan sorguları bu yolda HİÇ çalışmaz.
//
// Beklenen: $sekme, $bszGor, $beyanOku (beyanlar.php); config/beyansiz_yukleme.php yüklü.
// Fonksiyon TANIMLAMAZ.
// =========================================================
if (!isset($sekme) || !function_exists('bsz_bekleyen')) return;

$bsz_per    = 50;
$bs_q       = trim((string)($_GET['bs_q'] ?? ''));
$bs_page    = max(1, (int)($_GET['bs_page'] ?? 1));
$yp_q       = trim((string)($_GET['yp_q'] ?? ''));
$yp_page    = max(1, (int)($_GET['yp_page'] ?? 1));
$bsz_tablo  = beyansiz_tablo_var();
$bsz_kisi_gor   = can_beyan('contact.read') || can_yukleme_bildir();
$bsz_hks_erisim = (function_exists('is_admin') && is_admin()) || can('hks.access');

// Bölümlerin araması/sayfası bağımsızdır; diğer bölümün parametreleri korunur.
$beyansiz_url = static function (array $override = []) use (&$bs_q, &$bs_page, &$yp_q, &$yp_page): string {
    $p = ['sekme' => 'beyansiz', 'bs_q' => $bs_q, 'bs_page' => $bs_page > 1 ? (string)$bs_page : '',
          'yp_q' => $yp_q, 'yp_page' => $yp_page > 1 ? (string)$yp_page : ''];
    foreach ($override as $k => $v) $p[$k] = $v;
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null);
    return 'beyanlar.php' . ($p ? '?' . http_build_query($p) : '');
};

$bsz_bek = ['satirlar' => [], 'toplam' => 0, 'sayfa' => 1];
$bsz_yap = ['satirlar' => [], 'toplam' => 0, 'sayfa' => 1];
$bsz_say = ['bekleyen' => 0, 'yapilan' => 0];
if ($bsz_tablo) {
    $bsz_say = bsz_sayilar();
    $bsz_bek = bsz_bekleyen($bs_q, $bs_page, $bsz_per);
    $bsz_yap = bsz_yapilan($yp_q, $yp_page, $bsz_per);
    $bs_page = $bsz_bek['sayfa'];
    $yp_page = $bsz_yap['sayfa'];
}
$bsl_alt = $bsz_tablo
    ? 'Beyansız yüklemeler · <strong>' . (int)$bsz_say['bekleyen'] . '</strong> Hal Kayıt\'ta bekliyor'
    : 'Beyansız yüklemeler';
include __DIR__ . '/_beyanlar_baslik.php';
?>

<?php if (!$bsz_tablo): ?>
<div class="beyan-blok-bos" id="bszKurulmadi">
    Beyansız yükleme tablosu henüz kurulmadı.
    <?php if (is_admin()): ?>
    <a href="migrate.php#beyansiz-yukleme" class="btn btn-sm btn-primary">Kurulum sayfası</a>
    <?php else: ?>
    Kurulum yöneticiden beklenir.
    <?php endif; ?>
</div>
<?php else: ?>

<!-- ══ BİLDİRİM BEKLEYENLER ═══════════════════════════════════════════════ -->
<?php if (can_bsz_kart('bek', 'read')): ?>
<section class="beyan-blok beyan-blok-acik" id="bszBekleyen">
    <div class="beyan-blok-head">
        <span class="bl-ikon bl-ikon-acik" aria-hidden="true">⏳</span>
        <div class="bl-baslik-kap">
            <h2 class="beyan-blok-baslik">Bildirim Bekleyenler
                <span class="beyan-blok-sayi"><?= (int)$bsz_say['bekleyen'] ?></span>
            </h2>
            <span class="muted beyan-blok-not">Hal Kayıt'ta gönderilmeyi bekleyen taslaklar</span>
        </div>
    </div>
    <form method="get" class="beyan-pending-search">
        <input type="hidden" name="sekme" value="beyansiz">
        <?php foreach (['yp_q' => $yp_q, 'yp_page' => $yp_page > 1 ? (string)$yp_page : ''] as $fk => $fv): if ($fv === '') continue; ?>
        <input type="hidden" name="<?= h($fk) ?>" value="<?= h($fv) ?>">
        <?php endforeach; ?>
        <label class="form-label" for="bsBekAra">Bekleyenlerde ara</label>
        <div class="bff-main bl-arama" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
            <input type="search" id="bsBekAra" name="bs_q" class="form-control" style="flex:1;min-width:160px"
                   value="<?= h($bs_q) ?>" placeholder="Parti no, firma, ülke, ürün, plaka…">
            <button class="btn">Ara</button>
            <?php if ($bs_q !== ''): ?><a class="btn btn-ghost" href="<?= h($beyansiz_url(['bs_q' => '', 'bs_page' => ''])) ?>">Temizle</a><?php endif; ?>
        </div>
    </form>
    <?php if (!$bsz_bek['satirlar']): ?>
    <div class="beyan-blok-bos"><?= $bs_q !== '' ? 'Aramanızla eşleşen bekleyen kayıt yok.' : '✅ Bildirim bekleyen beyansız yükleme yok.' ?></div>
    <?php else: ?>
    <?php $bsz_rows = $bsz_bek['satirlar']; include __DIR__ . '/_beyansiz_liste.php'; ?>
    <?php $bs_top = max(1, (int)ceil($bsz_bek['toplam'] / $bsz_per)); if ($bs_top > 1): ?>
    <nav class="pagination" aria-label="Bekleyen sayfaları" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
        <?php if ($bs_page > 1): ?><a class="btn btn-sm btn-ghost" href="<?= h($beyansiz_url(['bs_page' => (string)($bs_page - 1)])) ?>">← Önceki</a><?php endif; ?>
        <span class="muted">Sayfa <?= $bs_page ?> / <?= $bs_top ?> · <?= (int)$bsz_bek['toplam'] ?> sonuç</span>
        <?php if ($bs_page < $bs_top): ?><a class="btn btn-sm btn-ghost" href="<?= h($beyansiz_url(['bs_page' => (string)($bs_page + 1)])) ?>">Sonraki →</a><?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
</section>

<?php endif; // bsz_bek ?>
<!-- ══ BİLDİRİM YAPILANLAR ════════════════════════════════════════════════ -->
<?php if (can_bsz_kart('yap', 'read')): ?>
<section class="beyan-blok beyan-blok-kapanmis" id="bszYapilan">
    <div class="beyan-blok-head">
        <span class="bl-ikon bl-ikon-kapali" aria-hidden="true">✅</span>
        <div class="bl-baslik-kap">
            <h2 class="beyan-blok-baslik">Bildirim Yapılanlar
                <span class="beyan-blok-sayi"><?= (int)$bsz_say['yapilan'] ?></span>
            </h2>
            <span class="muted beyan-blok-not">Gönderilen · taslağı silinen · durumu belirsiz</span>
        </div>
    </div>
    <form method="get" class="beyan-pending-search">
        <input type="hidden" name="sekme" value="beyansiz">
        <?php foreach (['bs_q' => $bs_q, 'bs_page' => $bs_page > 1 ? (string)$bs_page : ''] as $fk => $fv): if ($fv === '') continue; ?>
        <input type="hidden" name="<?= h($fk) ?>" value="<?= h($fv) ?>">
        <?php endforeach; ?>
        <label class="form-label" for="bsYapAra">Yapılanlarda ara</label>
        <div class="bff-main bl-arama" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
            <input type="search" id="bsYapAra" name="yp_q" class="form-control" style="flex:1;min-width:160px"
                   value="<?= h($yp_q) ?>" placeholder="Parti no, firma, ülke, ürün, plaka…">
            <button class="btn">Ara</button>
            <?php if ($yp_q !== ''): ?><a class="btn btn-ghost" href="<?= h($beyansiz_url(['yp_q' => '', 'yp_page' => ''])) ?>">Temizle</a><?php endif; ?>
        </div>
    </form>
    <?php if (!$bsz_yap['satirlar']): ?>
    <div class="beyan-blok-bos"><?= $yp_q !== '' ? 'Aramanızla eşleşen kayıt yok.' : 'Henüz bildirimi yapılmış beyansız yükleme yok.' ?></div>
    <?php else: ?>
    <?php $bsz_rows = $bsz_yap['satirlar']; include __DIR__ . '/_beyansiz_liste.php'; ?>
    <?php $yp_top = max(1, (int)ceil($bsz_yap['toplam'] / $bsz_per)); if ($yp_top > 1): ?>
    <nav class="pagination" aria-label="Yapılan sayfaları" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
        <?php if ($yp_page > 1): ?><a class="btn btn-sm btn-ghost" href="<?= h($beyansiz_url(['yp_page' => (string)($yp_page - 1)])) ?>">← Önceki</a><?php endif; ?>
        <span class="muted">Sayfa <?= $yp_page ?> / <?= $yp_top ?> · <?= (int)$bsz_yap['toplam'] ?> sonuç</span>
        <?php if ($yp_page < $yp_top): ?><a class="btn btn-sm btn-ghost" href="<?= h($beyansiz_url(['yp_page' => (string)($yp_page + 1)])) ?>">Sonraki →</a><?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; // bsz_yap ?>
<?php endif; ?>

<?php if (function_exists('can_yukleme_sil') && can_yukleme_sil()): ?>
<script>
(function () {
    var CSRF = <?= json_encode(csrf_token()) ?>;
    Array.prototype.forEach.call(document.querySelectorAll('[data-bsz-sil]'), function (b) {
        b.addEventListener('click', function () {
            var id = parseInt(b.getAttribute('data-bsz-sil'), 10) || 0;
            var ad = b.getAttribute('data-bsz-ad') || '';
            var m = '“' + ad + '” plakalı kaydı silmek istiyor musunuz?\n\nHal Kayıt\'ta bekleyen taslağı da silinir.';
            if (!id || !window.confirm(m)) return;
            b.disabled = true;
            fetch('api_beyansiz_yukleme.php?action=sil', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf: CSRF, id: id })
            }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (res.ok && res.j.tamam) { location.reload(); return; }
                b.disabled = false;
                alert('HATA: ' + (res.j.hata || 'Silinemedi.'));
            }).catch(function (e) { b.disabled = false; alert('Bağlantı hatası: ' + e.message); });
        });
    });
})();
</script>
<?php endif; ?>

<?php if (can_yukleme_bildir()) include __DIR__ . '/_yukleme_bildir_modal.php'; ?>
