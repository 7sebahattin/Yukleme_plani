<?php
// =========================================================
// _beyan_liste.php — Beyan listesi gövdesi (beyanlar.php partial)
//
// Beyanlar sayfası İKİ bölüme ayrıldı (yüklenmeyenler üstte, yüklenenler
// altta). Satır/kart biçimi tek yerde dursun diye bu partial iki kez
// include edilir — dört kopya (2 bölüm × masaüstü/mobil) ayrışırdı.
//
// Beklenen değişkenler:
//   $sec_rows        — satırlar
//   $sec_secim       — toplu bildirim seçim kolonu çizilsin mi (bool)
//   $bildirim_uygun  — closure(array $r): bool
//   $firma_adlari    — HKS firma id → ad (ops.)
//   $kopyala_yetki   — Kopyala düğmesi çizilsin mi (bool, ops.)
//
// Fonksiyon TANIMLAMAZ: sayfa testte iki kez include edilir.
// =========================================================
if (!isset($sec_rows)) return;
$sec_rows = array_map('beyan_redact_fields', $sec_rows);
$firma_adlari  = $firma_adlari  ?? [];
$kopyala_yetki = $kopyala_yetki ?? false;
$firma_ad = function (array $r) use ($firma_adlari): string {
    $fid = (string)($r['hks_firma_id'] ?? '');
    return $fid === '' ? '' : ($firma_adlari[$fid] ?? '');
};
?>
<!-- PC: tablo -->
<div class="table-wrap pc-only">
    <table class="data-table">
        <thead>
        <tr>
            <?php if ($sec_secim): ?>
            <th class="bb-sec-col"><input type="checkbox" class="bb-tumu"
                title="Bu bölümdeki uygun olanların tümünü seç"></th>
            <?php endif; ?>
            <th>Tarih</th>
            <th>Parti No</th>
            <th>Ürün / Çeşit</th>
            <th class="num">Palet</th>
            <th class="num">Kasa</th>
            <th class="num">Brüt KG</th>
            <th class="num">Net KG</th>
            <?php if (can_beyan('contact.read')): ?><th>Alıcı</th><?php endif; ?>
            <th>Marka</th>
            <th>Çıkış Depo</th>
            <th>Plaka</th>
            <?php if (can_beyan('hks.read')): ?><th>HKS Firma</th><th>Ülke</th><?php endif; ?>
            <th>Durum</th>
            <?php if ($kopyala_yetki): ?><th class="bl-kop-col"><span class="sr-only">İşlem</span></th><?php endif; ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($sec_rows as $r): ?>
        <!-- Satır tıklanabilir (app.js: tr[data-href]) — İşlem sütunu kaldırıldı.
             Klavye/yeni sekme için Parti No gerçek bir bağlantıdır. Durum
             değişikliği (Yüklendi dahil) beyan_view.php'deki durum şeridinden. -->
        <tr class="row-link" data-href="beyan_view.php?id=<?= (int)$r['id'] ?>">
            <?php if ($sec_secim): ?>
            <td class="bb-sec-col">
                <?php if ($bildirim_uygun($r)): ?>
                <input type="checkbox" class="bb-sec" value="<?= (int)$r['id'] ?>">
                <?php endif; ?>
            </td>
            <?php endif; ?>
            <td class="muted" style="font-size:.82rem"><?= h(fmt_datetime($r['created_at'])) ?></td>
            <td><a class="row-link-a bn-parti" href="beyan_view.php?id=<?= (int)$r['id'] ?>"><?= h($r['party_no'] ?: '—') ?></a></td>
            <td>
                <?= h($r['product_name'] ?: '—') ?>
                <?php if ($r['product_variety']): ?>
                <span class="muted"><?= h($r['product_variety']) ?></span>
                <?php endif; ?>
            </td>
            <td class="num"><?= $r['pallet_count'] !== null ? (int)$r['pallet_count'] : '—' ?></td>
            <td class="num"><?= $r['crate_count'] !== null ? number_format((int)$r['crate_count'], 0, ',', '.') : '—' ?></td>
            <td class="num"><?= $r['gross_kg'] !== null ? fmt_kg($r['gross_kg']) : '—' ?></td>
            <td class="num strong"><?= $r['net_kg'] !== null ? fmt_kg($r['net_kg']) : '—' ?></td>
            <?php if (can_beyan('contact.read')): ?><td><?= h($r['buyer_name'] ?: '—') ?></td><?php endif; ?>
            <td><?= h($r['brand'] ?: '—') ?></td>
            <td><?= h($r['exit_depot'] ?: '—') ?></td>
            <td><?php if (trim((string)($r['vehicle_plate'] ?? '')) !== ''): ?><span class="bl-plaka"><?= h($r['vehicle_plate']) ?></span><?php else: ?><span class="bl-eksik">eksik</span><?php endif; ?></td>
            <?php if (can_beyan('hks.read')): ?><td><?php $fa = $firma_ad($r); echo $fa !== '' ? h($fa) : '<span class="bl-eksik">—</span>'; ?></td>
            <td><?= h(($r['hks_ulke_ad'] ?? '') ?: '—') ?></td><?php endif; ?>
            <td><?= beyan_badge_html($r['status']) ?><?php
                $hd = beyan_hks_durum_etiket($r['hks_durum'] ?? null);
                if (can_beyan('hks.read') && $hd !== '') echo ' <span class="beyan-badge beyan-hks-' . h((string)$r['hks_durum']) . '" title="Hal Kayıt bildirimi">' . h($hd) . '</span>';
            ?></td>
            <?php if ($kopyala_yetki): ?>
            <td class="bl-kop-col">
                <form method="post" action="beyan_kopyala.php" class="bl-kop-form"
                      onsubmit="return confirm('Bu beyandan yeni bir TASLAK oluşturulacak (parti no ve plaka boş gelir). Devam edilsin mi?')">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <button type="submit" class="bl-kop-btn" title="Taslak Oluştur" aria-label="Taslak Oluştur">📝</button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Mobil: kart listesi -->
<div class="card-list mobile-only">
    <?php foreach ($sec_rows as $r): ?>
    <div class="beyan-card" style="cursor:default">
        <div class="beyan-card-head">
            <div>
                <div class="beyan-card-parti">
                    <?php if ($sec_secim && $bildirim_uygun($r)): ?>
                    <input type="checkbox" class="bb-sec" value="<?= (int)$r['id'] ?>"
                           title="Toplu bildirim için seç" style="margin-right:6px;vertical-align:middle">
                    <?php endif; ?>
                    <span class="bn-parti"><?= h($r['party_no'] ?: '(parti no yok)') ?></span>
                </div>
                <div class="beyan-card-urun">
                    <?= h($r['product_name'] ?: '—') ?>
                    <?php if ($r['product_variety']): ?>
                    <span class="muted"><?= h($r['product_variety']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?= beyan_badge_html($r['status']) ?><?php
                $hd = beyan_hks_durum_etiket($r['hks_durum'] ?? null);
                if (can_beyan('hks.read') && $hd !== '') echo ' <span class="beyan-badge beyan-hks-' . h((string)$r['hks_durum']) . '" title="Hal Kayıt bildirimi">' . h($hd) . '</span>';
            ?>
        </div>

        <div class="beyan-card-meta">
            <?php if ($r['pallet_count'] !== null): ?>
            <span><?= (int)$r['pallet_count'] ?> palet</span>
            <?php endif; ?>
            <?php if ($r['crate_count'] !== null): ?>
            <span><?= number_format((int)$r['crate_count'], 0, ',', '.') ?> kasa</span>
            <?php endif; ?>
            <?php if ($r['gross_kg'] !== null): ?>
            <span><?= fmt_kg($r['gross_kg']) ?> kg brüt</span>
            <?php endif; ?>
            <?php if ($r['net_kg'] !== null): ?>
            <span><?= fmt_kg($r['net_kg']) ?> kg net</span>
            <?php endif; ?>
            <?php if ($r['brand']): ?>
            <span><?= h($r['brand']) ?></span>
            <?php endif; ?>
            <?php if ($r['exit_depot']): ?>
            <span><?= h($r['exit_depot']) ?></span>
            <?php endif; ?>
        </div>

        <?php if ($r['buyer_name']): ?>
        <div class="beyan-card-alici muted">Alıcı: <?= h($r['buyer_name']) ?></div>
        <?php endif; ?>
        <?php $fa = $firma_ad($r); $pl = trim((string)($r['vehicle_plate'] ?? '')); $ul = trim((string)($r['hks_ulke_ad'] ?? '')); ?>
        <div class="bl-hks-satir">
            <span class="bl-hks-oge"><b>Plaka</b> <?php if ($pl !== ''): ?><span class="bl-plaka"><?= h($pl) ?></span><?php else: ?><span class="bl-eksik">eksik</span><?php endif; ?></span>
            <?php if (can_beyan('hks.read')): ?><span class="bl-hks-oge"><b>HKS Firma</b> <?= $fa !== '' ? h($fa) : '<span class="bl-eksik">—</span>' ?></span>
            <?php endif; ?>
            <?php if (can_beyan('hks.read') && $ul !== ''): ?><span class="bl-hks-oge"><b>Ülke</b> <?= h($ul) ?></span><?php endif; ?>
        </div>

        <div class="muted" style="font-size:.75rem;margin-top:4px">
            <?= h(fmt_datetime($r['created_at'])) ?>
        </div>

        <div class="beyan-card-actions">
            <a class="btn btn-sm" href="beyan_view.php?id=<?= (int)$r['id'] ?>">Görüntüle</a>
            <?php if (can_beyan('status') && $r['status'] === 'yukleme_olustu'): ?>
            <form method="post" action="beyan_edit.php?id=<?= (int)$r['id'] ?>" style="display:inline">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="status" value="yuklendi">
                <input type="hidden" name="status_only" value="1">
                <button type="submit" class="btn btn-sm btn-success"
                        onclick="return confirm('Bu beyanı YÜKLENDİ olarak işaretle?')">Yüklendi</button>
            </form>
            <?php endif; ?>
            <?php if (can_beyan('edit')): ?>
            <a class="btn btn-sm btn-ghost" href="beyan_edit.php?id=<?= (int)$r['id'] ?>">Düzenle</a>
            <?php endif; ?>
            <?php if ($kopyala_yetki): ?>
            <form method="post" action="beyan_kopyala.php" style="display:inline"
                  onsubmit="return confirm('Bu beyandan yeni bir TASLAK oluşturulacak (parti no ve plaka boş gelir). Devam edilsin mi?')">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <button type="submit" class="btn btn-sm btn-ghost" title="Taslak Oluştur">📝 Taslak Oluştur</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
