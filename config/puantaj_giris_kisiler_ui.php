<?php
// =========================================================
// config/puantaj_giris_kisiler_ui.php — Puantaj Girişi › "Kişiler" görünümü (v555)
//
// puantaj_cizelgesi.php?sekme=giris&gorunum=kisiler bu fonksiyonu çağırır. Yalnız HTML basar; hiçbir şey yazmaz.
// Etkileşim assets/puantaj_giris_kisiler.js'tedir (fetch → puantaj_giris_kaydet.php); ortak yardımcılar (post, pencere,
// yenile) assets/puantaj_giris.js'in window.pgApi'sindendir. <form> ELEMENTİ YOKTUR (.pm-dialog > form tuzağı; dosya yükleme
// FormData + fetch). JS tarafında innerHTML kullanılmaz. Pencereler z-index ≥ 600. Tek DOM: masaüstünde tablo, <768px'te kart.
// =========================================================
declare(strict_types=1);

/** Kişiler görünümü. $veri: ['kisiler'=>süzülmüş liste (pg_kisiler_yonetim satırları), 'sayac'=>pg_kisi_sayaclari()]. $ctx: q, durum. */
function pg_kisiler_ekran_ciz(array $veri, array $ctx): void
{
    $kisiler = $veri['kisiler'];
    $say = $veri['sayac'];
    $q = (string)($ctx['q'] ?? '');
    $durum = (string)($ctx['durum'] ?? 'hepsi');
    $cavuslar = $ctx['cavuslar'] ?? null;   // v562: null = «bağlı çavuş» kolonu kurulu değil → çavuş arayüzü yok
    $cavusSuz = (string)($ctx['cavus_suz'] ?? '');
?>
<div class="pk-sayac" role="status" aria-label="Kişi sayıları">
    <span class="pk-chip">Toplam <b data-pk-say="toplam"><?= (int)$say['toplam'] ?></b></span>
    <span class="pk-chip pk-chip-aktif">Aktif <b data-pk-say="aktif"><?= (int)$say['aktif'] ?></b></span>
    <span class="pk-chip pk-chip-pasif">Pasif <b data-pk-say="pasif"><?= (int)$say['pasif'] ?></b></span>
    <?php if ($q !== '' || $durum !== 'hepsi' || $cavusSuz !== ''): ?><span class="pk-chip pk-chip-gos">Gösterilen <b><?= count($kisiler) ?></b></span><?php endif; ?>
</div>
<p class="pi-hata pk-hata" id="pkHata" role="alert" hidden></p>
<?php if ((int)$say['toplam'] === 0): ?>
<div class="pc-bos pg-bos" role="status"><span aria-hidden="true">👥</span> <strong>Henüz kişi eklemediniz.</strong>
    <p>Tek tek ekleyebilir ya da bir Excel / CSV dosyasından toplu yükleyebilirsiniz.</p>
    <button type="button" class="btn btn-primary pg-buyuk" data-pg-kisi-ekle>+ Kişi ekle</button>
    <button type="button" class="btn pg-buyuk" data-pk-excel-ac>Excel'den ekle</button>
</div>
<?php elseif (!$kisiler): ?>
<div class="pc-bos" role="status"><span aria-hidden="true">📭</span> Aramanıza / süzgecinize uyan kişi yok.</div>
<?php else: ?>
<div class="table-wrap pk-wrap">
<table class="pk-tablo" id="pkTablo">
    <thead>
    <tr>
        <th class="pk-c" scope="col"><label class="pg-secbox" title="Görünen kişilerin tümünü seç"><input type="checkbox" data-pk-hepsi aria-label="Görünen kişilerin tümünü seç"></label></th>
        <th scope="col">Ad Soyad</th>
        <th scope="col">Görev</th>
        <?php if ($cavuslar !== null): ?><th scope="col">Bağlı çavuş</th><?php endif; ?>
        <th scope="col">Durum</th>
        <th scope="col" class="pk-sayi" title="Bu kişinin tüm aylardaki işaretli gün sayısı">Gün kaydı</th>
        <th scope="col">İşlemler</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($kisiler as $k): $aktif = (int)$k['aktif'] === 1; ?>
    <tr data-pk-satir="<?= (int)$k['id'] ?>" data-isim="<?= h($k['ad']) ?>" data-gorev="<?= h($k['gorev']) ?>" data-cavus-id="<?= (int)($k['cavus_id'] ?? 0) ?>" data-aktif="<?= $aktif ? '1' : '0' ?>" data-gun="<?= (int)$k['gun'] ?>"<?= $aktif ? '' : ' class="pk-pasif"' ?>>
        <td class="pk-c"><label class="pg-secbox"><input type="checkbox" data-pk-sec="<?= (int)$k['id'] ?>" aria-label="Seç: <?= h($k['ad']) ?>"></label></td>
        <td class="pk-isim" data-label="Ad Soyad"><?= h($k['ad']) ?></td>
        <td class="pk-gorev" data-label="Görev"><?= $k['gorev'] !== '' ? h($k['gorev']) : '<span class="pk-yok">—</span>' ?></td>
        <?php if ($cavuslar !== null): ?><td class="pk-cavus" data-label="Bağlı çavuş"><?= $k['cavus'] !== '' ? h($k['cavus']) . (!empty($k['cavus_pasif']) ? ' <em class="pg-pasif-etiket">(pasif)</em>' : '') : '<span class="pk-yok">—</span>' ?></td><?php endif; ?>
        <td class="pk-durum" data-label="Durum"><span class="pk-rozet <?= $aktif ? 'pk-rozet-aktif' : 'pk-rozet-pasif' ?>"><?= $aktif ? 'Aktif' : 'Pasif' ?></span></td>
        <td class="pk-sayi" data-label="Gün kaydı"><?= (int)$k['gun'] ?></td>
        <td class="pk-islem">
            <button type="button" class="btn btn-sm" data-pk-islem="duzenle">Düzenle</button>
            <button type="button" class="btn btn-sm" data-pk-islem="durum"><?= $aktif ? 'Pasife al' : 'Aktif yap' ?></button>
            <button type="button" class="btn btn-sm pk-sil" data-pk-islem="sil">Sil</button>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<!-- Toplu işlem çubuğu: seçim varken görünür (masaüstünde sayfa altına yapışık, <768px'te alt sayfa) -->
<div class="pg-cubuk pk-cubuk" id="pkCubuk" role="region" aria-label="Toplu kişi işlemleri" hidden>
    <div class="pg-cubuk-ust">
        <strong class="pg-cubuk-ozet" id="pkCubukOzet" aria-live="polite">0 kişi seçili</strong>
        <button type="button" class="pg-cubuk-kapat" data-pk-sec-temizle aria-label="Seçimi kaldır" title="Seçimi kaldır">×</button>
    </div>
    <div class="pg-cubuk-alt pk-cubuk-alt">
        <span class="pg-cubuk-dugmeler">
            <button type="button" class="btn btn-sm" data-pk-toplu="pasif">Pasife al</button>
            <button type="button" class="btn btn-sm" data-pk-toplu="aktif">Aktif yap</button>
            <?php if ($cavuslar !== null): ?><span class="pk-cavus-toplu"><select id="pkCavusSec" class="pg-cavus-sec" aria-label="Seçili kişileri bağlanacak çavuş"><?= pg_cavus_secenekleri($cavuslar, '— Bağı kaldır (çavuşsuz) —') ?></select> <button type="button" class="btn btn-sm" data-pk-cavus-uygula>Çavuşa bağla</button></span><?php endif; ?>
            <button type="button" class="btn btn-sm pk-sil" data-pk-toplu="sil">Sil</button>
            <button type="button" class="btn btn-sm" data-pk-sec-temizle>Seçimi kaldır</button>
        </span>
    </div>
    <p class="pi-hata" id="pkCubukHata" role="alert" hidden></p>
</div>

<!-- Excel'den toplu ekleme: 1) şablon / dosya seç → 2) önizleme (kaydetmez) → 3) Ekle onayı → sonuç. form elemanı yok (FormData + fetch). -->
<div class="pk-excel" id="pkExcel" role="dialog" aria-modal="true" aria-labelledby="pkExcelBaslik" hidden>
    <div class="pi-bas"><span class="pg-pen-baslik" id="pkExcelBaslik">Excel'den kişi ekle</span><button type="button" class="pi-kapat" data-pk-excel-kapat aria-label="Kapat">×</button></div>
    <div class="pk-excel-govde">
        <div id="pkExcelAdim1">
            <p class="pk-excel-not">Sütunlar: <strong>Ad Soyad</strong> (zorunlu)<?= $cavuslar !== null ? ', <strong>Görev</strong> ve <strong>Çavuş</strong> (isteğe bağlı; çavuş adı Personel Takibi\'ndeki aktif bir çavuşla eşleşirse bağlanır)' : ' ve <strong>Görev</strong> (isteğe bağlı)' ?>. .xlsx ya da .csv; en çok 500 satır, 2 MB. Dosya sunucuda saklanmaz; önce önizleme gösterilir.</p>
            <p class="pk-excel-satir">
                <a class="btn btn-sm" href="<?= h(pc_url('puantaj_cizelgesi.php', ['sekme' => 'giris', 'sablon' => '1'])) ?>" data-pk-sablon>⬇ Şablon indir</a>
                <input type="file" id="pkDosya" class="pk-dosya" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" aria-label="Excel ya da CSV dosyası seç">
            </p>
        </div>
        <div id="pkExcelOnizleme" hidden>
            <div class="pk-excel-ozet" id="pkExcelOzet" role="status"></div>
            <div class="pk-excel-tablo-kap">
                <table class="pk-excel-tablo">
                    <thead><tr><th scope="col">Sıra</th><th scope="col">Ad Soyad</th><th scope="col">Görev</th><?php if ($cavuslar !== null): ?><th scope="col">Çavuş</th><?php endif; ?><th scope="col">Durum</th></tr></thead>
                    <tbody id="pkExcelGovde"></tbody>
                </table>
            </div>
            <p class="pk-excel-not" id="pkExcelNot"></p>
        </div>
        <p class="pi-hata" id="pkExcelHata" role="alert" hidden></p>
        <p class="pg-cubuk-sonuc" id="pkExcelSonuc" role="status" hidden></p>
    </div>
    <div class="pg-pen-alt">
        <button type="button" class="btn" id="pkExcelVazgec" data-pk-excel-kapat>Vazgeç</button>
        <button type="button" class="btn btn-primary" id="pkExcelEkle" data-pk-excel-ekle disabled>Ekle</button>
    </div>
</div>
<?php
    pg_ekran_pencereler($cavuslar);
}
