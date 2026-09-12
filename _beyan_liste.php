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
//
// Fonksiyon TANIMLAMAZ: sayfa testte iki kez include edilir.
// =========================================================
if (!isset($sec_rows)) return;
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
            <th>Alıcı</th>
            <th>Marka</th>
            <th>Çıkış Depo</th>
            <th>Durum</th>
            <th class="actions-col">İşlem</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($sec_rows as $r): ?>
        <tr>
            <?php if ($sec_secim): ?>
            <td class="bb-sec-col">
                <?php if ($bildirim_uygun($r)): ?>
                <input type="checkbox" class="bb-sec" value="<?= (int)$r['id'] ?>">
                <?php endif; ?>
            </td>
            <?php endif; ?>
            <td class="muted" style="font-size:.82rem"><?= h(fmt_datetime($r['created_at'])) ?></td>
            <td><strong><?= h($r['party_no'] ?: '—') ?></strong></td>
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
            <td><?= h($r['buyer_name'] ?: '—') ?></td>
            <td><?= h($r['brand'] ?: '—') ?></td>
            <td><?= h($r['exit_depot'] ?: '—') ?></td>
            <td><?= beyan_badge_html($r['status']) ?><?php
                $hd = beyan_hks_durum_etiket($r['hks_durum'] ?? null);
                if ($hd !== '') echo ' <span class="beyan-badge" title="Hal Kayıt bildirimi">' . h($hd) . '</span>';
            ?></td>
            <td class="actions-col">
                <a class="btn btn-sm" href="beyan_view.php?id=<?= (int)$r['id'] ?>">Görüntüle</a>
                <?php if (can_beyan('write') && $r['status'] === 'yukleme_olustu'): ?>
                <form method="post" action="beyan_edit.php?id=<?= (int)$r['id'] ?>" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="status" value="yuklendi">
                    <input type="hidden" name="status_only" value="1">
                    <button type="submit" class="btn btn-sm btn-success"
                            onclick="return confirm('Bu beyanı YÜKLENDİ olarak işaretle?')">Yüklendi</button>
                </form>
                <?php endif; ?>
                <?php if (can_beyan('write')): ?>
                <a class="btn btn-sm btn-ghost" href="beyan_edit.php?id=<?= (int)$r['id'] ?>">Düzenle</a>
                <?php endif; ?>
            </td>
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
                    <?= h($r['party_no'] ?: '(parti no yok)') ?>
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
                if ($hd !== '') echo ' <span class="beyan-badge" title="Hal Kayıt bildirimi">' . h($hd) . '</span>';
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

        <div class="muted" style="font-size:.75rem;margin-top:4px">
            <?= h(fmt_datetime($r['created_at'])) ?>
        </div>

        <div class="beyan-card-actions">
            <a class="btn btn-sm" href="beyan_view.php?id=<?= (int)$r['id'] ?>">Görüntüle</a>
            <?php if (can_beyan('write') && $r['status'] === 'yukleme_olustu'): ?>
            <form method="post" action="beyan_edit.php?id=<?= (int)$r['id'] ?>" style="display:inline">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="status" value="yuklendi">
                <input type="hidden" name="status_only" value="1">
                <button type="submit" class="btn btn-sm btn-success"
                        onclick="return confirm('Bu beyanı YÜKLENDİ olarak işaretle?')">Yüklendi</button>
            </form>
            <?php endif; ?>
            <?php if (can_beyan('write')): ?>
            <a class="btn btn-sm btn-ghost" href="beyan_edit.php?id=<?= (int)$r['id'] ?>">Düzenle</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
