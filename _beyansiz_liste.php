<?php
// =========================================================
// _beyansiz_liste.php — Beyansız yükleme listesi gövdesi (v498)
// _beyansiz_sekme.php partial'ı iki bölüm için (Bekleyenler / Yapılanlar) include eder.
//
// Beklenen değişkenler:
//   $bsz_rows       — bsz_bekleyen()/bsz_yapilan() satırları (her satırda 'goster')
//   $bsz_kisi_gor   — karşı kişi/firma adı gösterilsin mi (bool)
//   $bsz_hks_erisim — kullanıcıda hks.access var mı (yoksa gönderim notu)
//
// Fonksiyon TANIMLAMAZ. Toplu seçim / yazdırma YOK (bilinçli).
// =========================================================
if (!isset($bsz_rows)) return;
$bsz_kisi_gor   = $bsz_kisi_gor   ?? false;
$bsz_hks_erisim = $bsz_hks_erisim ?? false;
$bsz_rozet = static function (array $r) use ($bsz_hks_erisim): string {
    switch ((string)($r['goster'] ?? '')) {
        case 'bekliyor':
            $o = '<span class="bsz-rozet bsz-r-bekliyor">Hal Kayıt\'ta bekliyor</span>';
            if ((string)($r['yon'] ?? '') === 'yurt_ici') $o .= ' <span class="bsz-rozet bsz-r-tamamla" title="Eksik taslak: Hal Kayıt\'ta Düzenle ile tamamlanır">Tamamlanacak</span>';
            if (!$bsz_hks_erisim) $o .= '<span class="bsz-not">Hal Kayıt\'ta gönderim için yetkili kişiye iletin</span>';
            return $o;
        case 'gonderildi': return '<span class="bsz-rozet bsz-r-gonderildi">Gönderildi</span>';
        case 'silindi':    return '<span class="bsz-rozet bsz-r-silindi">Taslak silindi</span>';
        default:           return '<span class="bsz-rozet bsz-r-belirsiz" title="Taslak Hal Kayıt\'ta bulunamadı; gönderilmiş ya da silinmiş olabilir">Durum belirsiz</span>';
    }
};
// v501: Sil düğmesi yalnız can_yukleme_sil() olana ve YALNIZ 'Bildirim Bekleyenler' satırında (Yapılanlarda ASLA; sunucu da reddeder).
// v504: kart bazlı — Bekleyenler satırı bsz_bek, Yapılanlar (taslağı silinmiş) satırı bsz_yap Sil izni ister.
$bsz_sil_yetki = function_exists('can_yukleme_sil') && can_yukleme_sil();
$bsz_sil_btn = static function (array $r) use ($bsz_sil_yetki): string {
    $g = (string)($r['goster'] ?? '');
    if (!$bsz_sil_yetki || !in_array($g, ['bekliyor', 'silindi'], true)) return '';
    if (function_exists('can_bsz_kart') && !can_bsz_kart($g === 'bekliyor' ? 'bek' : 'yap', 'delete')) return '';
    return '<button type="button" class="btn btn-sm bsz-sil" data-bsz-sil="' . (int)$r['id'] . '"'
         . ' data-bsz-ad="' . h((string)$r['plaka']) . '">🗑 Sil</button>';
};
$bsz_hedef = static function (array $r) use ($bsz_kisi_gor): string {
    if ((string)($r['yon'] ?? '') === 'yurt_ici') {
        $ad = $bsz_kisi_gor ? trim((string)($r['karsi_kisi_ad'] ?? '')) : '';
        return '<span class="bsz-yon">Yurt içi</span> ' . ($ad !== '' ? h($ad) : '—');
    }
    return h((string)($r['ulke_ad'] ?? '') ?: '—');
};
?>
<!-- PC: tablo -->
<div class="table-wrap pc-only">
    <table class="data-table bsz-tablo">
        <thead>
        <tr>
            <th>Tarih</th><th>Parti No</th><th>HKS Firma</th><th>Ülke / Kişi-Firma</th>
            <th>Ürün</th><th class="num">Net KG</th><th>Plaka</th><th>Durum</th><?php if ($bsz_sil_yetki): ?><th></th><?php endif; ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($bsz_rows as $r): ?>
        <tr>
            <td class="muted" style="font-size:.82rem"><?= h(fmt_datetime((string)$r['created_at'])) ?></td>
            <td><?= h((string)($r['parti_no'] ?? '') ?: '—') ?></td>
            <td><?= h((string)($r['hks_firma_ad'] ?? '') ?: '—') ?></td>
            <td><?= $bsz_hedef($r) ?></td>
            <td><?= h((string)$r['urun_ad']) ?></td>
            <td class="num strong"><?= h(fmt_kg($r['kg'])) ?></td>
            <td><span class="bl-plaka"><?= h((string)$r['plaka']) ?></span></td>
            <td class="bsz-durum-td"><?= $bsz_rozet($r) ?></td>
            <?php if ($bsz_sil_yetki): ?><td><?= $bsz_sil_btn($r) ?></td><?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Mobil: kartlar -->
<div class="mobile-only bsz-kartlar">
    <?php foreach ($bsz_rows as $r): ?>
    <div class="bsz-kart">
        <div class="bsz-kart-ust">
            <span class="bl-plaka"><?= h((string)$r['plaka']) ?></span>
            <span class="muted bsz-kart-tarih"><?= h(fmt_datetime((string)$r['created_at'])) ?></span>
        </div>
        <div class="bsz-kart-ana"><strong><?= h((string)$r['urun_ad']) ?></strong> · <?= h(fmt_kg($r['kg'])) ?> kg</div>
        <div class="bsz-kart-alt"><?= $bsz_hedef($r) ?></div>
        <div class="bsz-kart-alt muted"><?= h((string)($r['hks_firma_ad'] ?? '')) ?><?php if ((string)($r['parti_no'] ?? '') !== ''): ?> · Parti <?= h((string)$r['parti_no']) ?><?php endif; ?></div>
        <div class="bsz-kart-durum"><?= $bsz_rozet($r) ?></div>
        <?php if ($bsz_sil_yetki && ($b = $bsz_sil_btn($r)) !== ''): ?><div class="bsz-kart-sil"><?= $b ?></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
