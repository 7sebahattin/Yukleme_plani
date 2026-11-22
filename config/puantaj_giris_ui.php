<?php
// =========================================================
// config/puantaj_giris_ui.php — "Puantaj Girişi" sekmesinin ekran gövdesi (v554)
//
// puantaj_cizelgesi.php bu fonksiyonu çağırır (sayfa ince kalır). Yalnız HTML basar; hiçbir şey yazmaz.
// Etkileşim assets/puantaj_giris.js'tedir (fetch → puantaj_giris_kaydet.php). <form> ELEMENTİ YOKTUR
// (.pm-dialog > form tuzağı); pencereler z-index ≥ 600. JS tarafında innerHTML kullanılmaz.
// Hücre/özet işaretleri (data-*) JS'in yerinde güncelleme sözleşmesidir: td[data-kod|data-tarih|data-emp],
// td[data-oz], td[data-pg-say], [data-gz], [data-rz].
// v555: kişi seçim kutusu (data-pg-sec) + gün seçimi (gün başlığı düğmesi data-pg-gun = seçim aç/kapa) + sabit işlem çubuğu
// (#pgCubuk: N kişi × M gün, kod düğmeleri, "Dolu günlerin üzerine yaz", Uygula → sayfa içi onay). Mobilde "Toplu seç" modu.
// =========================================================
declare(strict_types=1);

const PG_BILGI_METNI = 'Bu alan gayri resmi / göstermeliktir; PDKS, hakediş ve diğer modüllerle ilişkisi yoktur.';

/** JS'e verilecek kod sözlüğü (JSON): kod → ad / sinif / grup / agirlik. */
function pg_kodlar_json(): string
{
    $o = [];
    foreach (pg_kodlar() as $k => $kd) $o[$k] = ['ad' => $kd['ad'], 'sinif' => $kd['sinif'], 'grup' => $kd['grup'], 'agirlik' => $kd['agirlik']];
    return (string)json_encode($o, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

/** Sekme gövdesi. $tablo = pg_tablo() çıktısı (hatasız). $ctx: ay, q, pasif. */
function pg_ekran_ciz(array $tablo, array $ctx): void
{
    $ay = (string)$ctx['ay'];
    $q = (string)($ctx['q'] ?? '');
    $ayUzun = pc_ay_adlari();
    $satirlar = $tablo['satirlar'];
    $say = count($satirlar);

    if ($say === 0) {
        if ((int)($tablo['toplam_kisi'] ?? 0) === 0) {
            echo '<div class="pc-bos pg-bos" role="status"><span aria-hidden="true">👥</span> <strong>Henüz kişi eklemediniz.</strong>'
               . '<p>Kendi listenizdeki kişileri ekleyip günlerini X / Y / D / İ / Ü / R / T ile işaretleyebilirsiniz.</p>'
               . '<button type="button" class="btn btn-primary pg-buyuk" data-pg-kisi-ekle>+ Kişi ekle</button></div>';
        } elseif ($q !== '') {
            echo '<div class="pc-bos" role="status"><span aria-hidden="true">📭</span> Aramanıza uyan kişi yok.</div>';
        } else {
            echo '<div class="pc-bos" role="status"><span aria-hidden="true">📭</span> Aktif kişi yok. "Pasifleri de göster" ile kaldırdığınız kişileri görüp geri alabilirsiniz.</div>';
        }
        pg_ekran_pencereler();
        return;
    }

    $gunler = $tablo['gunler'];
    $genel = $tablo['genel'];
    $ilkHg = $gunler[0]['hg'];
    $ayAd = $ayUzun[(int)substr($ay, 5, 2)];
    // Hücre öznitelikleri: kod + tarih HER satırda (JS toplamı bunlardan hesaplar); düzenleme işareti yalnız aktif kişide
    $oz = static function (array $s, array $g, ?array $hh): string {
        $a = ' data-tarih="' . h($g['tarih']) . '" data-kod="' . h($hh ? (string)$hh['k'] : '') . '"';
        if (empty($s['pasif'])) $a .= ' data-pg data-emp="' . (int)$s['id'] . '"';
        return $a;
    };
    $uc = static function (array $s): string {
        return '<button type="button" class="pg-uc" data-pg-kisi="' . (int)$s['id'] . '" data-ad="' . h($s['ad']) . '" data-gorev="' . h($s['alt']) . '" data-pasif="' . (!empty($s['pasif']) ? '1' : '0') . '" aria-haspopup="menu" aria-label="İşlemler: ' . h($s['ad']) . '" title="İşlemler">⋯</button>';
    };
?>
<!-- Toplu seçim araç çubuğu: masaüstünde her zaman; <768px'te "Toplu seç" modunda hızlı seçimler açılır -->
<div class="pg-sec-arac" id="pgSecArac">
    <button type="button" class="btn btn-sm pg-secmod-dugme" data-pg-secmod aria-pressed="false">☑ Toplu seç</button>
    <div class="pg-sec-hizli" role="group" aria-label="Gün seçimi">
        <span class="pg-sec-etiket">Gün seç:</span>
        <button type="button" class="btn btn-sm" data-pg-gunsec="tum">Tüm günler</button>
        <button type="button" class="btn btn-sm" data-pg-gunsec="haftaici">Hafta içleri</button>
        <button type="button" class="btn btn-sm" data-pg-gunsec="haftasonu">Hafta sonları</button>
        <button type="button" class="btn btn-sm" data-pg-gunsec="temizle">Temizle</button>
        <button type="button" class="btn btn-sm pg-sec-kisiler" data-pg-kisisec="hepsi">Tüm kişiler</button>
    </div>
    <span class="pg-sec-ozet" id="pgSecOzet" aria-live="polite"></span>
</div>

<!-- ≥768px: çizelge ızgarası -->
<div class="table-wrap pc-grid-wrap pg-grid-wrap" id="pcGridWrap">
<table class="pc-grid pg-grid">
    <colgroup><col class="pc-col-ad"></colgroup>
    <thead>
    <tr>
        <th class="pc-ad pc-kose" scope="col"><span class="pg-ad-kap"><label class="pg-secbox" title="Görünen aktif kişilerin tümünü seç"><input type="checkbox" data-pg-sec-hepsi aria-label="Görünen aktif kişilerin tümünü seç"></label><span class="pg-ad-metin"><span class="pc-ad-ad">Kişi <span class="pc-adet">(<?= $say ?>)</span></span></span></span></th>
        <?php foreach ($gunler as $g): ?>
        <th class="pc-g<?= $g['hs'] ? ' pc-hs' : '' ?><?= $g['bugun'] ? ' pc-bugun' : '' ?>" scope="col"<?= $g['bugun'] ? ' data-bugun' : '' ?>><button type="button" class="pg-gbtn" data-pg-gun="<?= h($g['tarih']) ?>" data-hs="<?= $g['hs'] ? '1' : '0' ?>" aria-pressed="false" title="<?= h($g['n'] . ' ' . $ayAd . ' ' . pc_gun_adlari_uzun()[$g['hg']] . ' — günü seç / seçimi kaldır') ?>"><span class="pc-gn"><?= $g['n'] ?></span><span class="pc-gk"><?= h($g['ad']) ?></span></button></th>
        <?php endforeach; ?>
        <th class="pc-oz" scope="col" title="Çalışılan gün (X + Y/2)">Gün</th>
        <th class="pc-oz" scope="col" title="İzin günü (Yıllık izin + Ücretsiz izin)">İzin</th>
        <th class="pc-oz" scope="col" title="Raporlu gün">Rapor</th>
        <th class="pc-oz" scope="col" title="Devamsız gün">Devamsız</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($satirlar as $s): ?>
    <tr data-pg-satir="<?= (int)$s['id'] ?>"<?= !empty($s['pasif']) ? ' class="pg-pasif"' : '' ?>>
        <th class="pc-ad" scope="row"><span class="pg-ad-kap"><?= empty($s['pasif']) ? '<label class="pg-secbox"><input type="checkbox" data-pg-sec="' . (int)$s['id'] . '" aria-label="Seç: ' . h($s['ad']) . '"></label>' : '<span class="pg-secbox pg-secbox-bos" aria-hidden="true"></span>' ?><span class="pg-ad-metin"><span class="pc-ad-ad"><?= h($s['ad']) ?><?= !empty($s['pasif']) ? ' <em class="pg-pasif-etiket">(pasif)</em>' : '' ?></span><?php if ($s['alt'] !== ''): ?><small><?= h($s['alt']) ?></small><?php endif; ?></span><?= $uc($s) ?></span></th>
        <?php foreach ($gunler as $g):
            $hh = $s['hucre'][$g['tarih']] ?? null;
            [$cls, $ic, $tt] = pg_hucre_goster($hh); ?>
        <td class="pc-h <?= $cls ?><?= $g['hs'] ? ' pc-hs' : '' ?><?= $g['bugun'] ? ' pc-bugun' : '' ?><?= empty($s['pasif']) ? ' pc-duz' : '' ?>"<?= $tt !== '' ? ' title="' . h($tt) . '"' : '' ?><?= $oz($s, $g, $hh) ?>><?= $ic ?></td>
        <?php endforeach; ?>
        <td class="pc-oz" data-oz="gun"><?= h(pg_gun_fmt($s['ozet']['gun'])) ?></td>
        <td class="pc-oz" data-oz="izin"><?= (int)$s['ozet']['izin'] ?></td>
        <td class="pc-oz" data-oz="rapor"><?= (int)$s['ozet']['rapor'] ?></td>
        <td class="pc-oz" data-oz="devamsiz"><?= (int)$s['ozet']['devamsiz'] ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
    <tr class="pc-say">
        <th class="pc-ad" scope="row">O gün çalışan</th>
        <?php foreach ($gunler as $g): $n = (int)($tablo['gun_say'][$g['tarih']] ?? 0); ?>
        <td class="pc-h<?= $g['hs'] ? ' pc-hs' : '' ?><?= $g['bugun'] ? ' pc-bugun' : '' ?>" data-pg-say="<?= h($g['tarih']) ?>"><?= $n > 0 ? $n : '·' ?></td>
        <?php endforeach; ?>
        <td class="pc-oz" colspan="4"></td>
    </tr>
    <tr class="pc-genel">
        <th class="pc-ad" scope="row">Genel toplam</th>
        <td class="pc-genel-bos" colspan="<?= count($gunler) ?>"><span data-gz="kisi"><?= (int)$genel['kisi'] ?></span> kişi</td>
        <td class="pc-oz" data-gz-oz="gun"><?= h(pg_gun_fmt($genel['gun'])) ?></td>
        <td class="pc-oz" data-gz-oz="izin"><?= (int)$genel['izin'] ?></td>
        <td class="pc-oz" data-gz-oz="rapor"><?= (int)$genel['rapor'] ?></td>
        <td class="pc-oz" data-gz-oz="devamsiz"><?= (int)$genel['devamsiz'] ?></td>
    </tr>
    </tfoot>
</table>
</div>

<!-- <768px: kişi listesi + rozet özeti; dokununca o kişinin ay takvimi, güne dokununca kod seçici -->
<div class="pc-liste" id="pcListe">
    <div class="pc-genel-kart">
        <strong><span data-gz="kisi"><?= (int)$genel['kisi'] ?></span> kişi</strong>
        <span class="pc-rz" data-gz="gun" title="Toplam çalışılan gün"><?= h(pg_gun_fmt($genel['gun'])) ?> gün</span>
        <span class="pc-rz" data-gz="izin" title="İzin günü"<?= (int)$genel['izin'] === 0 ? ' hidden' : '' ?>><?= (int)$genel['izin'] ?> izin</span>
        <span class="pc-rz" data-gz="rapor" title="Raporlu gün"<?= (int)$genel['rapor'] === 0 ? ' hidden' : '' ?>><?= (int)$genel['rapor'] ?> rapor</span>
        <span class="pc-rz" data-gz="devamsiz" title="Devamsız gün"<?= (int)$genel['devamsiz'] === 0 ? ' hidden' : '' ?>><?= (int)$genel['devamsiz'] ?> devamsız</span>
    </div>
    <?php foreach ($satirlar as $s): $o = $s['ozet']; ?>
    <details class="pc-kisi<?= !empty($s['pasif']) ? ' pg-pasif' : '' ?>" data-emp-kisi="<?= (int)$s['id'] ?>">
        <summary>
            <?php if (empty($s['pasif'])): ?><input type="checkbox" class="pg-msec" data-pg-sec="<?= (int)$s['id'] ?>" aria-label="Seç: <?= h($s['ad']) ?>"><?php endif; ?>
            <span class="pc-k-ad"><span class="pc-ad-ad"><?= h($s['ad']) ?><?= !empty($s['pasif']) ? ' <em class="pg-pasif-etiket">(pasif)</em>' : '' ?></span><?php if ($s['alt'] !== ''): ?><small><?= h($s['alt']) ?></small><?php endif; ?></span>
            <span class="pc-rozetler">
                <span class="pc-rz" data-rz="gun" title="Çalışılan gün"><?= h(pg_gun_fmt($o['gun'])) ?> gün</span>
                <span class="pc-rz" data-rz="izin" title="İzin günü"<?= (int)$o['izin'] === 0 ? ' hidden' : '' ?>><?= (int)$o['izin'] ?> izin</span>
                <span class="pc-rz" data-rz="rapor" title="Raporlu gün"<?= (int)$o['rapor'] === 0 ? ' hidden' : '' ?>><?= (int)$o['rapor'] ?> rapor</span>
                <span class="pc-rz" data-rz="devamsiz" title="Devamsız gün"<?= (int)$o['devamsiz'] === 0 ? ' hidden' : '' ?>><?= (int)$o['devamsiz'] ?> devamsız</span>
            </span>
        </summary>
        <div class="pg-mob-islem"><?= $uc($s) ?><span class="pg-mob-yaz">İşlemler</span></div>
        <div class="pc-takvim" role="grid" aria-label="<?= h($s['ad'] . ' — ' . pc_ay_basligi($ay)) ?>">
            <?php foreach (pc_gun_adlari() as $hn => $hk): ?><span class="pc-t-bas<?= $hn >= 6 ? ' pc-hs' : '' ?>"><?= h($hk) ?></span><?php endforeach; ?>
            <?php for ($i = 1; $i < $ilkHg; $i++): ?><span class="pc-t pc-t-bos" aria-hidden="true"></span><?php endfor; ?>
            <?php foreach ($gunler as $g):
                $hh = $s['hucre'][$g['tarih']] ?? null;
                [$cls, $ic, $tt] = pg_hucre_goster($hh); ?>
            <span class="pc-t <?= $cls ?><?= $g['hs'] ? ' pc-hs' : '' ?><?= $g['bugun'] ? ' pc-bugun' : '' ?><?= empty($s['pasif']) ? ' pc-duz' : '' ?>"<?= $tt !== '' ? ' title="' . h($tt) . '"' : '' ?><?= $oz($s, $g, $hh) ?>><span class="pc-t-n"><?= $g['n'] ?></span><span class="pc-t-ic"><?= $ic ?></span></span>
            <?php endforeach; ?>
        </div>
    </details>
    <?php endforeach; ?>
</div>

<!-- Toplu işaret çubuğu (v555): seçim varken görünür. Masaüstünde sayfa altına yapışık, <768px'te alt sayfa (z-index ≥ 600). form elemanı yok. -->
<div class="pg-cubuk" id="pgCubuk" role="region" aria-label="Toplu işaretleme" hidden>
    <div class="pg-cubuk-ust">
        <strong class="pg-cubuk-ozet" id="pgCubukOzet" aria-live="polite">0 kişi × 0 gün</strong>
        <button type="button" class="pg-cubuk-kapat" data-pg-sec-temizle aria-label="Seçimi kaldır" title="Seçimi kaldır">×</button>
    </div>
    <div class="pg-cubuk-kodlar" role="group" aria-label="İşaret kodu">
        <?php foreach (pg_kodlar() as $k => $kd): ?>
        <button type="button" class="pg-kodbtn pc-k pc-k-<?= h($kd['sinif']) ?>" data-pg-sec-kod="<?= h($k) ?>" aria-pressed="false" title="<?= h($kd['ad']) ?>"><b><?= h($k) ?></b><span><?= h($kd['ad']) ?></span></button>
        <?php endforeach; ?>
        <button type="button" class="pg-kodbtn pg-kodbtn-temizle" data-pg-sec-kod="" aria-pressed="false" title="Seçili hücrelerdeki işareti sil">Temizle</button>
    </div>
    <div class="pg-cubuk-alt">
        <label class="pg-ustune"><input type="checkbox" id="pgUstune"> Dolu günlerin üzerine yaz</label>
        <span class="pg-cubuk-dugmeler">
            <button type="button" class="btn btn-sm" data-pg-sec-temizle>Seçimi kaldır</button>
            <button type="button" class="btn btn-sm btn-primary" id="pgUygula" data-pg-uygula disabled>Uygula</button>
        </span>
    </div>
    <div class="pg-cubuk-onay" id="pgCubukOnay" hidden>
        <p id="pgCubukOnayMetin"></p>
        <span class="pg-cubuk-dugmeler">
            <button type="button" class="btn btn-sm" data-pg-onay-iptal>Vazgeç</button>
            <button type="button" class="btn btn-sm btn-primary" id="pgOnayla" data-pg-onayla>Onayla</button>
        </span>
    </div>
    <p class="pi-hata" id="pgCubukHata" role="alert" hidden></p>
    <p class="pg-cubuk-sonuc" id="pgCubukSonuc" role="status" hidden></p>
</div>
<?php
    pg_ekran_pencereler();
}

/** Açılır pencereler (hücre kod menüsü, kişi işlem menüsü, ad/onay penceresi). form elemanı yok. */
function pg_ekran_pencereler(): void
{
?>
<div class="pi-ovl" id="pgOvl" hidden></div>

<!-- Hücre kod menüsü (tek hücre): masaüstünde hücrenin yanında, <768px'te alt sayfa -->
<div class="pi-menu pg-menu" id="pgMenu" role="dialog" aria-labelledby="pgMenuBaslik" hidden>
    <div class="pi-bas"><span class="pi-baslik" id="pgMenuBaslik"></span><button type="button" class="pi-kapat" data-pg-kapat aria-label="Kapat">×</button></div>
    <div class="pi-liste">
        <?php foreach (pg_kodlar() as $k => $kd): ?>
        <button type="button" class="pi-dugme pc-k pc-k-<?= h($kd['sinif']) ?>" data-pg-kod="<?= h($k) ?>"><b><?= h($k) ?></b><span><?= h($kd['ad']) ?></span></button>
        <?php endforeach; ?>
        <button type="button" class="pi-dugme pi-temizle" data-pg-kod="">Temizle</button>
    </div>
    <p class="pi-hata" id="pgMenuHata" role="alert" hidden></p>
</div>

<!-- Kişi işlem menüsü (⋯) -->
<div class="pi-menu pg-menu pg-islem" id="pgIslem" role="menu" aria-labelledby="pgIslemBaslik" hidden>
    <div class="pi-bas"><span class="pi-baslik" id="pgIslemBaslik"></span><button type="button" class="pi-kapat" data-pg-kapat aria-label="Kapat">×</button></div>
    <div class="pi-liste">
        <button type="button" class="pi-dugme pg-oge" role="menuitem" data-pg-islem="duzenle">Düzenle</button>
        <button type="button" class="pi-dugme pg-oge" role="menuitem" data-pg-islem="haftaici">Hafta içlerini &lsquo;Çalıştı&rsquo; yap</button>
        <button type="button" class="pi-dugme pg-oge" role="menuitem" data-pg-islem="temizle">Ayı temizle</button>
        <button type="button" class="pi-dugme pg-oge pg-tehlike" role="menuitem" data-pg-islem="kaldir">Kaldır</button>
        <button type="button" class="pi-dugme pg-oge" role="menuitem" data-pg-islem="geri">Geri al</button>
    </div>
    <p class="pi-hata" id="pgIslemHata" role="alert" hidden></p>
</div>

<!-- Kişi ekle / düzenle / onay penceresi -->
<div class="pg-pen" id="pgPen" role="dialog" aria-modal="true" aria-labelledby="pgPenBaslik" hidden>
    <div class="pi-bas"><span class="pg-pen-baslik" id="pgPenBaslik"></span><button type="button" class="pi-kapat" data-pg-pen-iptal aria-label="Kapat">×</button></div>
    <div class="pg-pen-alan" id="pgPenAlan">
        <label for="pgAd">Ad Soyad <span class="pg-zorunlu" aria-hidden="true">*</span></label>
        <input type="text" id="pgAd" maxlength="<?= PG_AD_MAX ?>" autocomplete="off" placeholder="Örnek Kişi A">
        <label for="pgGorev">Görev <span class="pg-opsiyonel">(isteğe bağlı)</span></label>
        <input type="text" id="pgGorev" maxlength="<?= PG_GOREV_MAX ?>" autocomplete="off" placeholder="Ünvan / not">
    </div>
    <p class="pg-pen-mesaj" id="pgPenMesaj" hidden></p>
    <p class="pi-hata" id="pgPenHata" role="alert" hidden></p>
    <div class="pg-pen-alt">
        <button type="button" class="btn" data-pg-pen-iptal>Vazgeç</button>
        <button type="button" class="btn btn-primary" id="pgPenTamam" data-pg-pen-tamam>Kaydet</button>
    </div>
</div>
<?php
}
