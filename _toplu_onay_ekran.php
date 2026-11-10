<?php
// v507 — mesai_toplu_onay.php ekran parçası (include-only; fonksiyon tanımlamaz). Değişkenler sayfadan gelir.
if (!defined('TOPLU_ONAY_EKRAN')) { http_response_code(404); exit; }
?>
<?php
render_header('Toplu Mesai Onayı');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
foreach ($errors as $e) echo '<div class="flash flash-error">' . h($e) . '</div>';

$durumEtiket = ['onay' => ['✅ Onaylanacak', 'tamamlandi'], 'karar' => ['⚠ Karar gerekli', 'eksik_cikis'], 'uygunsuz' => ['⛔ Onaylanmaz', 'eksik_cikis'], 'atla' => ['Zaten hazır', 'pasif']];
$beklemeNedeni = static function (array $f): string {
    $p = [];
    if (!empty($f['sinif_onayi_gerekli']) && $f['etkin_sinif'] === null) $p[] = 'Tam/Yarım kararı';
    if ((int)$f['fazla_mesai_saat'] > 0 && $f['fazla_mesai_onay_saat'] === null) $p[] = 'FM ' . (int)$f['fazla_mesai_saat'] . ' sa onayı';
    return implode(' + ', $p) ?: '—';
};
?>
<div class="page-head">
    <h1>✅ Toplu Mesai Onayı</h1>
    <div class="page-head-actions">
        <a href="cavus_hakedis.php?<?= h(http_build_query(array_filter(['tarih' => $tarih, 'cavus' => $cavusId], static fn($v) => $v !== null && $v !== ''))) ?>" class="btn btn-geri">← Hakediş</a>
    </div>
</div>

<form method="get" class="pdks-filter-bar" data-oto-filtre>
    <input type="date" name="tarih" value="<?= h($tarih) ?>">
    <select name="cavus">
        <option value="">Tüm çavuşlar</option>
        <?php foreach ($cavuslar as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $cavusId === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?><?= $c['is_active'] ? '' : ' (pasif)' ?></option><?php endforeach; ?>
    </select>
    <select name="depo" disabled><option><?= h($depo !== '' ? $depo : 'Depo seçilmemiş') ?></option></select>
    <select name="kaynak">
        <option value="" <?= !$yalnizElle ? 'selected' : '' ?>>Tüm bekleyenler</option>
        <option value="elle" <?= $yalnizElle ? 'selected' : '' ?>>Yalnız elle eklenenler</option>
    </select>
    <?= function_exists('pdks_oto_filtre_noscript') ? pdks_oto_filtre_noscript() : '' ?>
</form>

<?php if ($onizleme !== null && $onizleme['satirlar']): ?>
<?php $sy = $onizleme['sayac']; ?>
<div class="card tvo-onizleme" id="tvoOnizleme">
    <strong><?= $onizleme['uygulandi'] ? 'Sonuç' : '🔍 Önizleme' ?></strong>
    <div class="tvo-ozet">
        <span class="pdks-badge pdks-badge-tamamlandi">✅ Onaylanacak: <?= (int)$sy['onay'] ?></span>
        <span class="pdks-badge pdks-badge-eksik_cikis">⚠ Karar gerekli: <?= (int)$sy['karar'] ?></span>
        <span class="pdks-badge pdks-badge-eksik_cikis">⛔ Onaylanmaz: <?= (int)$sy['uygunsuz'] ?></span>
        <?php if ($sy['atla'] > 0): ?><span class="pdks-badge pdks-badge-pasif">Zaten hazır: <?= (int)$sy['atla'] ?></span><?php endif; ?>
    </div>
    <div class="tvo-liste tvo-onizleme-liste">
        <?php foreach ($onizleme['satirlar'] as $s): $d = $s['d']; $pl = $s['plan']; [$et, $kod] = $durumEtiket[$pl['durum']]; ?>
        <div class="tvo-satir tvo-s-<?= h($pl['durum']) ?>">
            <div class="tvo-hucre tvo-ad"><strong><?= h($s['oturum']['foreman_name_snapshot']) ?></strong><span class="pdks-row-sub"><?= h($d['card_no']) ?> · <?= h($d['worker_type_name_snapshot']) ?></span></div>
            <div class="tvo-hucre"><?= h(date('H:i', strtotime((string)$d['entry_time']))) ?> – <?= $d['exit_time'] ? h(date('H:i', strtotime((string)$d['exit_time']))) : '—' ?> · <?= h(pdks_faz8b_sure_metni($d['faz8b'])) ?></div>
            <div class="tvo-hucre"><span class="pdks-badge pdks-badge-<?= h($kod) ?>"><?= h($et) ?></span> <span class="pdks-row-sub"><?= h($pl['durum'] === 'onay' ? $pl['etiket'] : $pl['neden']) ?></span></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if (!$onizleme['uygulandi'] && $sy['onay'] > 0): ?>
    <form method="post" class="tvo-uygula-form">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="uygula">
        <input type="hidden" name="tarih" value="<?= h($tarih) ?>">
        <input type="hidden" name="cavus" value="<?= (int)($cavusId ?? 0) ?>">
        <input type="hidden" name="kaynak" value="<?= $yalnizElle ? 'elle' : '' ?>">
        <input type="hidden" name="sinif_karari" value="<?= h($sinifKarari) ?>">
        <input type="hidden" name="fm_karar" value="<?= h($fmKarar) ?>">
        <?php foreach ($onizleme['satirlar'] as $s): if ($s['plan']['durum'] !== 'onay') continue; ?><input type="hidden" name="period_ids[]" value="<?= (int)$s['d']['id'] ?>"><?php endforeach; ?>
        <button type="submit" class="btn btn-primary" onclick="return confirm('<?= (int)$sy['onay'] ?> dönem onaylanacak. Devam edilsin mi?');">✅ <?= (int)$sy['onay'] ?> kaydı onayla</button>
        <span class="muted">Yalnız “Onaylanacak” satırlar uygulanır; diğerleri hiç yazılmaz.</span>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$bekleyen): ?>
<div class="pdks-empty"><span class="pdks-empty-icon">✅</span><p>Bu tarih/depo/filtrede onay bekleyen mesai dönemi yok.</p></div>
<?php else: ?>
<form method="post" id="tvoForm" class="tvo-form">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="onizle">
    <input type="hidden" name="tarih" value="<?= h($tarih) ?>">
    <input type="hidden" name="cavus" value="<?= (int)($cavusId ?? 0) ?>">
    <input type="hidden" name="kaynak" value="<?= $yalnizElle ? 'elle' : '' ?>">

    <div class="card tvo-kontrol">
        <div class="tvo-kontrol-alan">
            <label for="tvoSinif">Kısa / çıkışı belirsiz mesai kararı</label>
            <select name="sinif_karari" id="tvoSinif">
                <option value="">Seçiniz (boşsa onaylanmaz)</option>
                <option value="tam" <?= $sinifKarari === 'tam' ? 'selected' : '' ?>>Tam Mesai</option>
                <option value="yarim" <?= $sinifKarari === 'yarim' ? 'selected' : '' ?>>Yarım Mesai (en az 3 sa)</option>
            </select>
        </div>
        <div class="tvo-kontrol-alan">
            <label for="tvoFm">Fazla mesai</label>
            <select name="fm_karar" id="tvoFm">
                <option value="">Seçiniz (boşsa onaylanmaz)</option>
                <option value="onayla" <?= $fmKarar === 'onayla' ? 'selected' : '' ?>>Hesaplanan saatin tamamını onayla</option>
                <option value="reddet" <?= $fmKarar === 'reddet' ? 'selected' : '' ?>>Reddet (0 saat)</option>
            </select>
        </div>
        <div class="tvo-kontrol-alan tvo-kontrol-btn">
            <label class="tvo-hepsi"><input type="checkbox" id="tvoTumu"> Tümünü seç</label>
            <span id="tvoSayac" class="muted">0 satır seçili</span>
            <button type="submit" class="btn btn-primary" id="tvoOnizleBtn" disabled>🔍 Seçilenleri önizle</button>
        </div>
    </div>

    <div class="tvo-liste" role="list">
        <?php foreach ($bekleyen as $s): $d = $s['d']; $f = $d['faz8b'];
            $pl = pdks_toplu_onay_plan($d, $s['kesin'], $s['fiyat_var'], null, null);
            $engel = $pl['durum'] === 'uygunsuz' ? $pl['neden'] : '';
        ?>
        <label class="tvo-satir<?= $engel !== '' ? ' tvo-s-uygunsuz' : '' ?>" role="listitem">
            <span class="tvo-chk"><input type="checkbox" name="period_ids[]" value="<?= (int)$d['id'] ?>" class="js-tvo-chk" <?= isset($topluGuvenli[(int)$d['id']]) ? 'checked' : '' ?>></span>
            <span class="tvo-hucre tvo-ad"><strong><?= h($s['oturum']['foreman_name_snapshot']) ?></strong><span class="pdks-row-sub"><?= h($d['card_no']) ?> · <?= h($d['worker_type_name_snapshot']) ?></span></span>
            <span class="tvo-hucre"><?= h(date('H:i', strtotime((string)$d['entry_time']))) ?> – <?= $d['exit_time'] ? h(date('H:i', strtotime((string)$d['exit_time']))) : '—' ?><span class="pdks-row-sub"><?= h(pdks_faz8b_sure_metni($f)) ?></span></span>
            <span class="tvo-hucre"><?= h($beklemeNedeni($f)) ?><span class="pdks-row-sub">Kaynak: <?= h($s['kaynak']) ?></span></span>
            <span class="tvo-hucre"><?php if ($engel !== ''): ?><span class="pdks-badge pdks-badge-eksik_cikis">⛔ <?= h($engel) ?></span><?php endif; ?></span>
        </label>
        <?php endforeach; ?>
    </div>
</form>
<script>
(function () {
    var f = document.getElementById('tvoForm'); if (!f) return;
    var tumu = document.getElementById('tvoTumu'), sayac = document.getElementById('tvoSayac'), btn = document.getElementById('tvoOnizleBtn');
    var kutular = [].slice.call(f.querySelectorAll('.js-tvo-chk'));
    function guncelle() {
        var n = kutular.filter(function (k) { return k.checked; }).length;
        sayac.textContent = n + ' satır seçili'; btn.disabled = n === 0;
        tumu.checked = n > 0 && n === kutular.length;
    }
    tumu.addEventListener('change', function () { kutular.forEach(function (k) { k.checked = tumu.checked; }); guncelle(); });
    kutular.forEach(function (k) { k.addEventListener('change', guncelle); });
    guncelle();
})();
</script>
<?php endif; ?>

<?php if (function_exists('pdks_liste_ui_js')) pdks_liste_ui_js(); ?>
<?php render_footer(); ?>
