<?php
// =========================================================
// _fis_onizle.php — Fiş Excel'i önizleme + sonuç HTML'i (PARTIAL, v446)
//
// gunluk_fis_yukle.php `$model` ile include eder; fonksiyon TANIMLAMAZ (test fixture ile iki kez include edebilir).
// Doğrudan istek → 404. Kullanıcı verisinin TAMAMI h() ile kaçırılır; hiçbir değer satır içi olaya/öznitelik JS'ine girmez.
//   $model['mod']      'onizle' | 'sonuc'
//   $model['onizle']   pdks_fis_onizle() çıktısı (mod=onizle)   · $model['sonuc'] pdks_fis_yaz() çıktısı (mod=sonuc)
//   $model['token'], ['csrf'], ['dosya_ad'], ['sha'], ['dosya_uyarilar'], ['varsayilan'], ['depo'], ['url']
// =========================================================
if (!isset($model) || !is_array($model) || (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__)) {
    http_response_code(404);
    exit;
}
$fy_url = (string)($model['url'] ?? 'gunluk_fis_yukle.php');
$fy_rozet = [
    'hazir' => ['✅ Hazır', 'fy-b-hazir'], 'uyari' => ['⚠ Uyarı', 'fy-b-uyari'], 'onay' => ['❓ Onay gerekli', 'fy-b-uyari'],
    'engel' => ['⛔ Engel', 'fy-b-engel'], 'hata' => ['❌ Hata', 'fy-b-hata'], 'zaten' => ['⏭ Zaten yüklü', 'fy-b-zaten'], 'devam' => ['🔁 Tamamlanacak', 'fy-b-devam'],
    'yazildi' => ['✅ Yazıldı', 'fy-b-hazir'], 'devam_tamamlandi' => ['✅ Tamamlandı', 'fy-b-hazir'], 'zaten_yuklu' => ['⏭ Zaten yüklü', 'fy-b-zaten'], 'degisti' => ['⚠ Değişti', 'fy-b-uyari'],
];
$fy_tarih = static fn($t) => $t ? date('d.m.Y', strtotime((string)$t)) : '—';
$fy_grup = static function (array $g, string $tarih): string {
    $cik = $g['exit_clock'] ?? '';
    $ek = (($g['exit_date'] ?? $tarih) !== $tarih) ? ' (+1 gün)' : '';
    return h(pdks_fis_grup_etiketi($g)) . ' · ' . (int)$g['adet'] . ' kişi · ' . h((string)($g['entry_clock'] ?? '')) . '→' . h((string)$cik) . h($ek);
};
?>
<div class="fy-kap" id="fisYukle" data-fy-mod="<?= h((string)$model['mod']) ?>">
<?php if (($model['mod'] ?? '') === 'onizle' && !empty($model['ust'])): $u = $model['ust']; ?>
    <div class="fy-ust" role="note">
        <span>İşletme: <b><?= h((string)($u['isletme'] ?? '')) ?></b></span>
        <span>Depo: <b><?= h((string)($u['depo'] ?? '')) ?></b></span>
        <span>Çavuş: <b><?= h((string)($u['cavus'] ?? '')) ?></b></span>
    </div>
<?php endif; ?>
<?php if (($model['mod'] ?? '') === 'sonuc'): $s = $model['sonuc']; $oz = $s['ozet']; ?>
    <div class="fy-ozet" role="status">
        <span><b><?= (int)$oz['islenen'] ?></b> fiş işlendi</span>
        <span class="fy-b-hazir"><b><?= (int)$oz['yazildi'] + (int)$oz['devam_tamamlandi'] ?></b> yazıldı</span>
        <span class="fy-b-zaten"><b><?= (int)$oz['zaten_yuklu'] ?></b> zaten yüklü</span>
        <span class="fy-b-uyari"><b><?= (int)$oz['degisti'] ?></b> değişti</span>
        <span class="fy-b-hata"><b><?= (int)$oz['hata'] ?></b> hatalı / atlandı</span>
        <span><b><?= (int)$oz['kisi'] ?></b> kişi kaydı</span>
        <span><b><?= (int)$oz['fm_onayli'] ?></b> FM onayı</span>
        <span>Servis: <b><?= (int)$oz['servis']['buyuk'] ?></b> büyük · <b><?= (int)$oz['servis']['kucuk'] ?></b> küçük</span>
    </div>
    <div class="table-wrap">
    <table class="fy-tablo">
        <thead><tr><th>Fiş</th><th>Durum</th><th>Ayrıntı</th><th>Yazılan</th><th>Mesai</th></tr></thead>
        <tbody>
        <?php foreach ($s['sonuclar'] as $r): $rz = $fy_rozet[$r['durum']] ?? [$r['durum'], 'fy-b-zaten']; $y = $r['yazilan'] ?? null; ?>
            <tr>
                <td><b><?= h((string)$r['fis_no']) ?></b><?= (int)$r['rev'] > 0 ? ' <small>rev ' . (int)$r['rev'] . '</small>' : '' ?><br><small>satır <?= (int)$r['satir'] ?></small></td>
                <td><span class="fy-rozet <?= h($rz[1]) ?>"><?= h($rz[0]) ?></span></td>
                <td>
                    <?= h((string)($r['mesaj'] ?? '')) ?>
                    <?php foreach (($r['uyarilar'] ?? []) as $u): ?><div class="fy-not fy-not-uyari">⚠ <?= h((string)$u) ?></div><?php endforeach; ?>
                </td>
                <td>
                    <?php if ($y): ?>
                        <?= (int)$y['kisi'] ?> kişi<?= (int)($y['yevmiye'] ?? 0) > 0 ? ' · ' . (int)$y['yevmiye'] . ' yevmiye' : '' ?><?= (int)$y['fm_onayli'] > 0 ? ' · ' . (int)$y['fm_onayli'] . ' FM (' . (int)$y['fm_saat'] . ' sa) onaylı' : '' ?><?= (int)$y['yarim'] > 0 ? ' · ' . (int)$y['yarim'] . ' yarım' : '' ?>
                        <?php if ((int)$y['servis']['buyuk'] + (int)$y['servis']['kucuk'] > 0): ?><br>Servis: <?= (int)$y['servis']['buyuk'] ?> büyük · <?= (int)$y['servis']['kucuk'] ?> küçük<?php endif; ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($r['session_id'])): ?><a class="btn" href="gunluk_isci_puantaj_detay.php?id=<?= (int)$r['session_id'] ?>">Mesai Detayı</a><?php endif; ?>
                    <?php if (!empty($r['toplu_id'])): ?><br><small class="fy-kod" title="Toplu Geri Al için">Toplu işlem: <?= h((string)$r['toplu_id']) ?></small><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ((int)$oz['hata'] + (int)$oz['degisti'] > 0): ?>
    <p class="fy-yonerge">Hatalı fişleri Excel'de düzeltip <b>aynı dosyayı</b> yeniden yükleyin: yazılmış fişler «zaten yüklü» diye atlanır, çiftlenmez. Yazılmış bir fişi düzeltmek için Mesai Detayı › Toplu İşlemler › <b>Geri Al</b>, sonra o fişin <b>Revizyon</b> sütununu 1 artırın.</p>
    <?php endif; ?>
    <p><a class="btn btn-primary" href="<?= h($fy_url) ?>">📥 Yeni dosya yükle</a></p>

<?php else: $on = $model['onizle']; $oz = $on['ozet']; $fisler = $on['fisler']; $v = $model['varsayilan']; ?>
    <div class="fy-ozet" role="status">
        <span><b><?= (int)$oz['fis'] ?></b> fiş</span>
        <?php foreach (['hazir' => 'hazır', 'uyari' => 'uyarılı', 'onay' => 'onay bekliyor', 'engel' => 'engelli', 'hata' => 'hatalı', 'zaten' => 'zaten yüklü', 'devam' => 'tamamlanacak'] as $k => $e): if (empty($oz['durum'][$k])) continue; ?>
        <span class="<?= h($fy_rozet[$k][1]) ?>"><b><?= (int)$oz['durum'][$k] ?></b> <?= h($e) ?></span>
        <?php endforeach; ?>
        <span><b><?= (int)$oz['kisi'] ?></b> kişi kaydı</span>
        <span><b><?= (int)$oz['fm_saat'] ?></b> sa FM</span>
        <span>Servis: <b><?= (int)$oz['servis']['buyuk'] ?></b> büyük · <b><?= (int)$oz['servis']['kucuk'] ?></b> küçük</span>
        <span class="fy-dosya"><?= h((string)$model['dosya_ad']) ?> · <?= h(substr((string)$model['sha'], 0, 12)) ?></span>
    </div>
    <?php foreach (($model['dosya_uyarilar'] ?? []) as $u): ?><div class="fy-not fy-not-uyari">⚠ <?= h((string)$u) ?></div><?php endforeach; ?>

    <form method="post" action="<?= h($fy_url) ?>" id="fyYazForm" onsubmit="return confirm('Seçili fişler yazılacak. Emin misiniz?')">
        <input type="hidden" name="csrf" value="<?= h((string)$model['csrf']) ?>">
        <input type="hidden" name="eylem" value="yaz">
        <input type="hidden" name="token" value="<?= h((string)$model['token']) ?>">
        <fieldset class="fy-onay">
            <legend>Yazma seçenekleri</legend>
            <label><input type="checkbox" name="onay_yarim" value="1" <?= !empty($v['onay_yarim']) ? 'checked' : '' ?>> Yarım kişilerin kararını «Yarım» olarak kaydet</label>
            <label><input type="checkbox" name="onay_fm" value="1" <?= !empty($v['onay_fm']) ? 'checked' : '' ?>> Fazla mesai adaylarını onayla (fiş = çavuş beyanı)</label>
            <label><input type="checkbox" name="ek_yaz" value="1" <?= !empty($v['ek_yaz']) ? 'checked' : '' ?>> Mevcut kayıtlara EK yaz (mesaide kayıt varsa üzerine eklenir)</label>
            <label><input type="checkbox" name="isletme_onay" value="1" <?= !empty($v['isletme_onay']) ? 'checked' : '' ?>> İşletme / depo uyuşmazlığı olan fişleri de yaz</label>
        </fieldset>

        <?php
        // Fiş başına ortak parçalar (masaüstü tablo + mobil kart AYNI veriyi basar)
        $fy_gruplar = static function (array $m) use ($fy_grup): string {
            if (empty($m['plan']['gruplar'])) return '—';
            $o = [];
            $yv = []; $yvTop = 0; $yvKisi = 0;
            foreach ($m['plan']['gruplar'] as $g) {
                $o[] = '<li>' . $fy_grup($g, (string)$m['tarih']) . '</li>';
                if (($g['profil'] ?? '') === 'yevmiye') { $yv[] = (int)$g['adet'] . '×' . (int)$g['yevmiye']; $yvTop += (int)$g['adet'] * (int)$g['yevmiye']; $yvKisi += (int)$g['adet']; }
            }
            // v463: yevmiyeli Rampacı özeti — «Rampacı: 3 kişi · 5 yevmiye (2×2 + 1×1)»
            $ek = $yv ? '<small class="fy-yevmiye">Rampacı: ' . $yvKisi . ' kişi · ' . $yvTop . ' yevmiye (' . h(implode(' + ', $yv)) . ')</small>' : '';
            return '<ul class="fy-grup">' . implode('', $o) . '</ul>' . $ek;
        };
        $fy_fm = static function (array $m): string {
            $f = $m['plan']['fm'] ?? null;
            if (!$f || ($f['mod'] ?? 'yok') === 'yok') return 'FM yok';
            $mod = $f['mod'] === 'kova' ? 'Kova (kişi × saat)' : 'Toplam saat → dağıtım';
            $dag = []; $adlar = [];
            foreach (($m['plan']['gruplar'] ?? []) as $g) $adlar[(string)$g['kod']] = (string)$g['tip_ad'];
            foreach ($f['tip_dagilim'] as $kod => $sk) { $p = []; foreach ($sk as $sa => $n) $p[] = $n . '×' . $sa . ' sa'; $dag[] = h($adlar[(string)$kod] ?? (string)$kod) . ': ' . h(implode(' + ', $p)); }
            $notr = $f['notr'] === true ? '<span class="fy-rozet fy-b-hazir">Ücret nötr ✓</span>' : ($f['notr'] === false ? '<span class="fy-rozet fy-b-hata">Nötr değil ✗</span>' : '<span class="fy-rozet fy-b-uyari">Fiyat bilinmiyor ⚠</span>');
            return '<b>' . h($mod) . '</b><br>' . h((string)$f['aciklama']) . '<br>' . implode('<br>', $dag) . '<br>' . $notr;
        };
        $fy_servis = static function (array $m): string {
            $h = $m['servis']['hedef']; $y = $m['servis']['yazilacak']; $me = $m['mevcut']['servis'] ?? ['BUYUK' => 0, 'KUCUK' => 0];
            if ($h['buyuk'] + $h['kucuk'] === 0) return '—';
            return 'Hedef ' . (int)$h['buyuk'] . 'B/' . (int)$h['kucuk'] . 'K · mevcut ' . (int)($me['BUYUK'] ?? 0) . 'B/' . (int)($me['KUCUK'] ?? 0) . 'K · <b>yazılacak ' . (int)$y['buyuk'] . 'B/' . (int)$y['kucuk'] . 'K</b>';
        };
        $fy_mevcut = static function (array $m): string {
            $me = $m['mevcut'];
            if (!$me) return '—';
            if (!$me['mesai']) return 'Mesai yok (yeni açılır)';
            return 'Mesai var · ' . (int)$me['aktif_donem'] . ' aktif kayıt' . (!empty($me['kesin']) ? ' · <b>kesin hakediş</b>' : '');
        };
        $fy_mesaj = static function (array $m): string {
            $o = '';
            foreach ($m['hatalar'] as $x) $o .= '<div class="fy-not fy-not-hata">❌ ' . h((string)$x) . '</div>';
            foreach ($m['engeller'] as $x) $o .= '<div class="fy-not fy-not-engel">⛔ ' . h((string)$x) . '</div>';
            foreach ($m['uyarilar'] as $x) $o .= '<div class="fy-not fy-not-uyari">⚠ ' . h((string)$x) . '</div>';
            if ($m['kontrol'] !== '' || $m['ham'] !== '') {
                $o .= '<details class="fy-ham"><summary>Fişten okunan</summary>';
                if ($m['kontrol'] !== '') $o .= '<div><b>Kontrol notu:</b> ' . h($m['kontrol']) . '</div>';
                if ($m['ham'] !== '') $o .= '<div><b>Ham metin:</b> ' . h($m['ham']) . '</div>';
                $o .= '</details>';
            }
            if ($m['hash'] !== '') $o .= '<small class="fy-kod">plan ' . h(substr($m['hash'], 0, 8)) . '</small>';
            return $o;
        };
        $fy_cavus = static function (array $m): string {
            $c = $m['cavus'] ?? [];
            return h((string)($c['kod'] ?? '')) . ' · ' . h((string)($c['ad'] ?? '')) . '';
        };
        ?>
        <div class="fy-secim-bar"><label><input type="checkbox" data-fy-hepsi> Tümünü seç / kaldır</label></div>

        <div class="table-wrap pc-only">
        <table class="fy-tablo">
            <thead><tr><th>Seç</th><th>Fiş</th><th>Tarih</th><th>Çavuş</th><th>Gruplar</th><th>Fazla mesai</th><th>Servis</th><th>Mevcut</th><th>Durum</th></tr></thead>
            <tbody>
            <?php foreach ($fisler as $m): $rz = $fy_rozet[$m['durum']] ?? [$m['durum'], 'fy-b-zaten']; ?>
                <tr class="fy-s-<?= h($m['durum']) ?>">
                    <td><input type="checkbox" name="secilen[]" value="<?= (int)$m['satir'] ?>" data-fy-satir="<?= (int)$m['satir'] ?>" <?= $m['varsayilan_secili'] ? 'checked' : '' ?> <?= $m['secilebilir'] ? '' : 'disabled' ?> aria-label="Fiş <?= h($m['fis_no']) ?> seç"></td>
                    <td><b><?= h($m['fis_no']) ?></b><?= $m['rev'] > 0 ? ' <small>rev ' . (int)$m['rev'] . '</small>' : '' ?><br><small>satır <?= (int)$m['satir'] ?></small></td>
                    <td><?= h($fy_tarih($m['tarih'])) ?><br><small><?= h($m['giris']) ?></small></td>
                    <td><?= $fy_cavus($m) ?></td>
                    <td><?= $fy_gruplar($m) ?><small><?= (int)$m['kisi'] ?> kişi</small></td>
                    <td><?= $fy_fm($m) ?></td>
                    <td><?= $fy_servis($m) ?></td>
                    <td><?= $fy_mevcut($m) ?></td>
                    <td><span class="fy-rozet <?= h($rz[1]) ?>"><?= h($rz[0]) ?></span><?= $fy_mesaj($m) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <div class="mobile-only fy-kartlar">
            <?php foreach ($fisler as $m): $rz = $fy_rozet[$m['durum']] ?? [$m['durum'], 'fy-b-zaten']; ?>
            <div class="fy-kart fy-s-<?= h($m['durum']) ?>">
                <div class="fy-kart-ust">
                    <label class="fy-kart-sec"><input type="checkbox" name="secilen_m[]" value="<?= (int)$m['satir'] ?>" data-fy-satir="<?= (int)$m['satir'] ?>" <?= $m['varsayilan_secili'] ? 'checked' : '' ?> <?= $m['secilebilir'] ? '' : 'disabled' ?> aria-label="Fiş <?= h($m['fis_no']) ?> seç"></label>
                    <div><b><?= h($m['fis_no']) ?></b><?= $m['rev'] > 0 ? ' <small>rev ' . (int)$m['rev'] . '</small>' : '' ?> <small>satır <?= (int)$m['satir'] ?></small></div>
                    <span class="fy-rozet <?= h($rz[1]) ?>"><?= h($rz[0]) ?></span>
                </div>
                <dl class="fy-dl">
                    <dt>Tarih</dt><dd><?= h($fy_tarih($m['tarih'])) ?> · giriş <?= h($m['giris']) ?></dd>
                    <dt>Çavuş</dt><dd><?= $fy_cavus($m) ?></dd>
                    <dt>Gruplar</dt><dd><?= $fy_gruplar($m) ?><small><?= (int)$m['kisi'] ?> kişi</small></dd>
                    <dt>Fazla mesai</dt><dd><?= $fy_fm($m) ?></dd>
                    <dt>Servis</dt><dd><?= $fy_servis($m) ?></dd>
                    <dt>Mevcut</dt><dd><?= $fy_mevcut($m) ?></dd>
                </dl>
                <?= $fy_mesaj($m) ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="fy-eylem">
            <button type="submit" class="btn btn-primary" id="fyYazBtn">✅ Seçilenleri yaz (<span data-fy-sayi>0</span>)</button>
        </div>
    </form>
    <form method="post" action="<?= h($fy_url) ?>" class="fy-vazgec">
        <input type="hidden" name="csrf" value="<?= h((string)$model['csrf']) ?>">
        <input type="hidden" name="eylem" value="iptal">
        <input type="hidden" name="token" value="<?= h((string)$model['token']) ?>">
        <button type="submit" class="btn">Vazgeç</button>
    </form>
<?php endif; ?>
</div>
