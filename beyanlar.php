<?php
// =========================================================
// beyanlar.php — Gümrük beyanları listesi (Sprint Beyan-01)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!can_beyan('read')) forbidden();

function valid_date_beyan(string $d): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
}

function beyan_url(array $override = []): string {
    global $q, $f_status, $f_urun, $f_marka, $f_depo, $tarih_bas, $tarih_bit, $page;
    $p = [
        'q'         => $q,
        'status'    => $f_status,
        'urun'      => $f_urun,
        'marka'     => $f_marka,
        'depo'      => $f_depo,
        'tarih_bas' => $tarih_bas,
        'tarih_bit' => $tarih_bit,
        'page'      => $page > 1 ? (string)$page : '',
    ];
    foreach ($override as $k => $v) $p[$k] = $v;
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null);
    return 'beyanlar.php' . ($p ? '?' . http_build_query($p) : '');
}

const BEYAN_PER_PAGE = 50;

$q         = trim((string)($_GET['q']         ?? ''));
$f_status  = trim((string)($_GET['status']    ?? ''));
$f_urun    = trim((string)($_GET['urun']      ?? ''));
$f_marka   = trim((string)($_GET['marka']     ?? ''));
$f_depo    = trim((string)($_GET['depo']      ?? ''));
$tarih_bas = trim((string)($_GET['tarih_bas'] ?? ''));
$tarih_bit = trim((string)($_GET['tarih_bit'] ?? ''));
$page      = max(1, (int)($_GET['page'] ?? 1));

$valid_statuses = array_keys(beyan_statuses());
if (!in_array($f_status, $valid_statuses, true)) $f_status = '';
if ($tarih_bas !== '' && !valid_date_beyan($tarih_bas)) $tarih_bas = '';
if ($tarih_bit !== '' && !valid_date_beyan($tarih_bit)) $tarih_bit = '';

// ── WHERE koşulları ───────────────────────────────────────
$where  = "WHERE deleted_at IS NULL";
$params = [];

if ($q !== '') {
    $where .= " AND (party_no LIKE :q OR product_name LIKE :q OR product_variety LIKE :q
                OR buyer_name LIKE :q OR brand LIKE :q OR exit_depot LIKE :q
                OR company_name LIKE :q OR contact_person LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($f_status !== '') {
    $where .= " AND status = :status";
    $params[':status'] = $f_status;
}
if ($f_urun !== '') {
    $where .= " AND product_name LIKE :urun";
    $params[':urun'] = '%' . $f_urun . '%';
}
if ($f_marka !== '') {
    $where .= " AND brand LIKE :marka";
    $params[':marka'] = '%' . $f_marka . '%';
}
if ($f_depo !== '') {
    $where .= " AND exit_depot LIKE :depo";
    $params[':depo'] = '%' . $f_depo . '%';
}
// Beyanlar tüm kullanıcılara ve tüm depolarda görünür — depo kapsamı uygulanmaz.
if ($tarih_bas !== '') {
    $where .= " AND DATE(created_at) >= :tarih_bas";
    $params[':tarih_bas'] = $tarih_bas;
}
if ($tarih_bit !== '') {
    $where .= " AND DATE(created_at) <= :tarih_bit";
    $params[':tarih_bit'] = $tarih_bit;
}

$st_count = db()->prepare("SELECT COUNT(*) FROM customs_declarations $where");
$st_count->execute($params);
$total       = (int)$st_count->fetchColumn();
$total_pages = max(1, (int)ceil($total / BEYAN_PER_PAGE));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * BEYAN_PER_PAGE;

$st = db()->prepare("SELECT * FROM customs_declarations $where ORDER BY id DESC LIMIT :lim OFFSET :off");
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':lim', BEYAN_PER_PAGE, PDO::PARAM_INT);
$st->bindValue(':off', $offset,        PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

// ── Toplu bildirim: hangi satırlar uygun? ─────────────────────────────────
// beyan_view'daki buton kapısının aynısı. Aktif bağlar TEK sorguda çekilir
// (satır başına sorgu, 50 satırlık sayfada 50 sorgu demekti).
$toplu_yetki = can_beyan('write') && (can('records.write') || is_admin());
$aktif_bagli = [];
if ($toplu_yetki && $rows) {
    try {
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $bs  = db()->prepare("SELECT DISTINCT beyan_id FROM beyan_hks_bildirim
                              WHERE beyan_id IN ($ph) AND durum IN ('taslak','gonderildi')");
        $bs->execute($ids);
        $aktif_bagli = array_flip(array_map('intval', $bs->fetchAll(PDO::FETCH_COLUMN)));
    } catch (PDOException $e) { $aktif_bagli = []; }
}
$bildirim_uygun = function (array $r) use ($toplu_yetki, $aktif_bagli): bool {
    return $toplu_yetki
        && trim((string)($r['vehicle_plate'] ?? '')) !== ''
        && beyan_hks_eslesme_tam($r)
        && (float)($r['net_kg'] ?? 0) > 0
        && in_array((string)$r['status'], beyan_hks_uygun_durumlar(), true)
        && !isset($aktif_bagli[(int)$r['id']]);
};
$uygun_sayisi = count(array_filter($rows, $bildirim_uygun));

$statuses  = beyan_statuses();
$today     = date('Y-m-d');
$has_filter = $q !== '' || $f_status !== '' || $f_urun !== '' || $f_marka !== '' || $f_depo !== '' || $tarih_bas !== '' || $tarih_bit !== '';

render_header('Beyanlar');
render_flash();
?>

<div class="page-head">
    <div>
        <h1>🧾 Beyanlar</h1>
        <p class="muted">
            Toplam <?= $total ?> beyan
            <?php if ($total_pages > 1): ?> · Sayfa <?= $page ?> / <?= $total_pages ?><?php endif; ?>
        </p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <?php if (is_admin()): ?>
        <a href="beyan_bildirim_tani.php" class="btn btn-ghost btn-sm"
           title="Bildirim köprüsü ön kontrolü (salt-okunur)">🩺 Bildirim Ön Kontrol</a>
        <?php endif; ?>
        <?php if (can_beyan('write')): ?>
        <a href="beyan_create.php" class="btn btn-primary btn-lg">+ Yeni Beyan</a>
        <?php endif; ?>
    </div>
</div>

<!-- ── Filtre formu ── -->
<form method="get" class="beyan-filter-form" id="beyanFilterForm">
    <div class="bff-main">
        <input type="search" name="q" value="<?= h($q) ?>"
               placeholder="Parti no, ürün, alıcı, marka, depo..." autocomplete="off">
        <button class="btn">Ara</button>
        <?php if ($has_filter): ?>
        <a href="beyanlar.php" class="btn btn-ghost">Temizle</a>
        <?php endif; ?>
    </div>

    <button type="button" class="beyan-filter-toggle" id="beyanFilterToggle">
        ▾ Detaylı Filtre<?php if ($f_status !== '' || $tarih_bas !== '' || $tarih_bit !== '' || $f_urun !== '' || $f_marka !== '' || $f_depo !== ''): ?> <span style="color:var(--primary)">●</span><?php endif; ?>
    </button>

    <div class="bff-filters<?= ($f_status !== '' || $tarih_bas !== '' || $tarih_bit !== '' || $f_urun !== '' || $f_marka !== '' || $f_depo !== '') ? ' bff-open' : '' ?>"
         id="beyanFilterPanel">
        <!-- Durum pilleri — eskiden liste üstünde ayrı bir satırdaydı ve on
             düğmeyle ekranın yarısını kaplıyordu. Artık detaylı filtrenin
             içinde. Bağlantı (link) olarak kalmaları bilinçli: tek tıkla
             filtrelerler, forma bağlı değiller ve JS kapalıyken de çalışırlar. -->
        <div style="grid-column:1/-1">
            <label>Durum</label>
            <div class="filter-pills" style="margin:0">
                <a href="<?= beyan_url(['status' => '', 'page' => '']) ?>"
                   class="pill<?= $f_status === '' ? ' active' : '' ?>">Tümü</a>
                <?php foreach ($statuses as $sk => $sv): ?>
                <a href="<?= beyan_url(['status' => $sk, 'page' => '']) ?>"
                   class="pill<?= $f_status === $sk ? ' active' : '' ?>"><?= h($sv['label']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <div>
            <label>Tarih (başlangıç)</label>
            <input type="date" name="tarih_bas" value="<?= h($tarih_bas) ?>" max="<?= $today ?>">
        </div>
        <div>
            <label>Tarih (bitiş)</label>
            <input type="date" name="tarih_bit" value="<?= h($tarih_bit) ?>" max="<?= $today ?>">
        </div>
        <div>
            <label>Ürün</label>
            <input type="text" name="urun" value="<?= h($f_urun) ?>" placeholder="KAYISI...">
        </div>
        <div>
            <label>Marka</label>
            <input type="text" name="marka" value="<?= h($f_marka) ?>" placeholder="URAS...">
        </div>
        <div>
            <label>Çıkış Depo</label>
            <input type="text" name="depo" value="<?= h($f_depo) ?>" placeholder="KARAMAN...">
        </div>
        <div style="align-self:flex-end;flex:0 0 auto;min-width:auto">
            <button class="btn btn-sm" style="white-space:nowrap">Filtrele</button>
        </div>
    </div>
</form>

<?php if (empty($rows)): ?>
<div class="empty">
    <?php if ($has_filter): ?>
        <p>Filtre kriterlerine uyan beyan bulunamadı.</p>
        <a href="beyanlar.php" class="btn btn-ghost">Filtreleri temizle</a>
    <?php else: ?>
        <p>Henüz beyan kaydı yok.</p>
        <?php if (can_beyan('write')): ?>
        <a href="beyan_create.php" class="btn btn-primary">İlk beyanı oluştur</a>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php else: ?>

<!-- PC: tablo -->
<div class="table-wrap pc-only">
    <table class="data-table">
        <thead>
        <tr>
            <?php if ($uygun_sayisi): ?>
            <th class="bb-sec-col"><input type="checkbox" id="bbTumu" title="Uygun olanların tümünü seç"></th>
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
        <?php foreach ($rows as $r): ?>
        <tr>
            <?php if ($uygun_sayisi): ?>
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
                <?php if (can_beyan('delete')): ?>
                <!-- Silme LİSTEDEN de yapılabilmeli: eskiden yalnız detay
                     ekranında vardı, birkaç beyanı temizlemek için her birini
                     tek tek açmak gerekiyordu. Soft delete (arşivleme). -->
                <form method="post" action="beyan_delete.php" style="display:inline"
                      onsubmit="return confirm('<?= h($r['party_no'] ?: '#' . $r['id']) ?> arşivden gizlenecek. Devam edilsin mi?')">
                    <input type="hidden" name="id"   value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <button type="submit" class="btn btn-sm btn-ghost"
                            style="color:var(--danger)">Sil</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Mobil: kart listesi -->
<div class="card-list mobile-only">
    <?php foreach ($rows as $r): ?>
    <div class="beyan-card" style="cursor:default">
        <div class="beyan-card-head">
            <div>
                <div class="beyan-card-parti">
                    <?php if ($bildirim_uygun($r)): ?>
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
            <?php if (can_beyan('delete')): ?>
            <form method="post" action="beyan_delete.php" style="display:inline"
                  onsubmit="return confirm('<?= h($r['party_no'] ?: '#' . $r['id']) ?> arşivden gizlenecek. Devam edilsin mi?')">
                <input type="hidden" name="id"   value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <button type="submit" class="btn btn-sm btn-ghost" style="color:var(--danger)">Sil</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Sayfalama -->
<?php if ($total_pages > 1): ?>
<div class="pagination" style="margin-top:16px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap">
    <?php if ($page > 1): ?>
    <a href="<?= beyan_url(['page' => (string)($page - 1)]) ?>" class="btn btn-sm btn-ghost">← Önceki</a>
    <?php endif; ?>
    <span class="muted" style="line-height:32px;padding:0 8px">
        <?= $page ?> / <?= $total_pages ?>
    </span>
    <?php if ($page < $total_pages): ?>
    <a href="<?= beyan_url(['page' => (string)($page + 1)]) ?>" class="btn btn-sm btn-ghost">Sonraki →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<script>
(function () {
    var toggle = document.getElementById('beyanFilterToggle');
    var panel  = document.getElementById('beyanFilterPanel');
    if (toggle && panel) {
        toggle.addEventListener('click', function () {
            panel.classList.toggle('bff-open');
        });
    }
})();
</script>

<?php if (!empty($uygun_sayisi)): ?>
<!-- ══ TOPLU BİLDİRİM ══════════════════════════════════════════════════════ -->
<!-- Seçim yapılınca beliren alt şerit + onay penceresi.
     Pencere yalnız TASLAK oluşturur; HKS'e hiçbir şey gönderilmez. -->
<div id="bbBar" class="bb-bar" hidden>
    <span id="bbBarSayi"></span>
    <button type="button" class="btn btn-sm btn-ghost" id="bbTemizle">Seçimi bırak</button>
    <button type="button" class="btn btn-sm btn-primary" id="bbAc">🏛 Toplu Bildirim</button>
</div>

<div id="bbOverlay" class="beyan-hks-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="bbTitle">
  <div class="beyan-hks-dialog" style="max-width:820px">
    <div class="beyan-hks-head">
      <strong id="bbTitle">🏛 Toplu Hal Kayıt Bildirimi</strong>
      <button type="button" class="beyan-hks-x" id="bbKapat" aria-label="Kapat">✕</button>
    </div>
    <div class="beyan-hks-body">
      <div id="bbYukleniyor" class="muted" style="padding:20px;text-align:center">Hazırlanıyor…</div>
      <div id="bbHata" class="flash flash-error" hidden></div>
      <div id="bbIcerik" hidden>
        <div class="beyan-hks-sub">Bildirim ayarları <span class="beyan-hks-rozet">tüm satırlar için ortak</span></div>
        <div class="beyan-hks-fields">
          <label>Bildirimci Sıfatı<select id="bbSifat" class="form-control"></select></label>
          <label>Bildirim Türü<select id="bbTur" class="form-control"></select></label>
        </div>
        <div class="beyan-hks-sub">Seçilen beyanlar — birim fiyatları ayrı ayrı girilir</div>
        <div class="table-wrap">
          <table class="beyan-match-table" id="bbTablo">
            <thead><tr><th>Parti</th><th>Ürün</th><th>Ülke</th><th>Plaka</th>
                       <th class="num">Net KG</th><th>Birim Fiyat</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <p class="beyan-hks-warn">
          Her satır için ayrı bir <strong>TASLAK</strong> oluşturulur. HKS'e bildirim
          <strong>GÖNDERİLMEZ</strong> — gönderim Hal Kayıt ekranında yapılır.
          Bir satır başarısız olursa diğerleri devam eder; sonuç satır satır bildirilir.
        </p>
      </div>
    </div>
    <div class="beyan-hks-foot">
      <button type="button" class="btn btn-ghost" id="bbVazgec">Vazgeç</button>
      <button type="button" class="btn btn-primary" id="bbKaydet" disabled>Taslakları Oluştur</button>
    </div>
  </div>
</div>

<script>
(function () {
    var CSRF = <?= json_encode(csrf_token()) ?>;
    var el = function (i) { return document.getElementById(i); };
    var veri = null;

    function secili() {
        return Array.prototype.filter.call(
            document.querySelectorAll('.bb-sec'), function (c) { return c.checked; });
    }
    // Aynı beyan hem tabloda hem mobil kartta listelenir; id'ler TEKİLLEŞTİRİLİR
    // yoksa aynı beyan için iki taslak denenirdi.
    function seciliIdler() {
        var g = {}, out = [];
        secili().forEach(function (c) { if (!g[c.value]) { g[c.value] = 1; out.push(Number(c.value)); } });
        return out;
    }

    function barGuncelle() {
        var n = seciliIdler().length;
        el('bbBar').hidden = n === 0;
        el('bbBarSayi').textContent = n + ' beyan seçildi';
        var t = el('bbTumu');
        if (t) t.checked = n > 0 && n === new Set(
            Array.prototype.map.call(document.querySelectorAll('.bb-sec'), function (c) { return c.value; })).size;
    }

    document.addEventListener('change', function (e) {
        if (e.target.classList && e.target.classList.contains('bb-sec')) {
            // Masaüstü satırı ve mobil kartı aynı beyanı gösterir — biri
            // işaretlenince diğeri de eşitlenir, sayım şaşmasın.
            Array.prototype.forEach.call(document.querySelectorAll('.bb-sec'), function (c) {
                if (c.value === e.target.value) c.checked = e.target.checked;
            });
            barGuncelle();
        }
    });

    var tumu = el('bbTumu');
    if (tumu) tumu.addEventListener('change', function () {
        Array.prototype.forEach.call(document.querySelectorAll('.bb-sec'), function (c) {
            c.checked = tumu.checked;
        });
        barGuncelle();
    });

    el('bbTemizle').addEventListener('click', function () {
        Array.prototype.forEach.call(document.querySelectorAll('.bb-sec'), function (c) { c.checked = false; });
        barGuncelle();
    });

    function kapat() { el('bbOverlay').hidden = true; document.body.style.overflow = ''; }
    el('bbKapat').addEventListener('click', kapat);
    el('bbVazgec').addEventListener('click', kapat);
    el('bbOverlay').addEventListener('click', function (e) { if (e.target === el('bbOverlay')) kapat(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !el('bbOverlay').hidden) kapat();
    });

    function doldur(sel, liste, secili) {
        sel.innerHTML = '<option value="">— seçiniz —</option>' + liste.map(function (x) {
            return '<option value="' + x.id + '"' +
                   (String(x.id) === String(secili || '') ? ' selected' : '') + '>' + x.ad + '</option>';
        }).join('');
    }
    function eHtml(v) {
        return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    function kontrol() {
        var uygun = (veri ? veri.satirlar : []).filter(function (r) { return !r.engel; });
        var hepsiFiyatli = uygun.length > 0 && uygun.every(function (r) {
            var i = el('bbFiyat_' + r.id);
            return i && i.value.trim() !== '';
        });
        el('bbKaydet').disabled = !(el('bbSifat').value && el('bbTur').value && hepsiFiyatli);
    }

    el('bbAc').addEventListener('click', function () {
        var idler = seciliIdler();
        if (!idler.length) return;
        el('bbOverlay').hidden = false;
        document.body.style.overflow = 'hidden';
        el('bbYukleniyor').hidden = false;
        el('bbIcerik').hidden = true;
        el('bbHata').hidden = true;
        veri = null;

        fetch('api_beyan_bildirim.php?action=toplu_hazirla', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ beyan_ids: idler })
        })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
            if (!res.ok) {
                el('bbYukleniyor').hidden = true;
                el('bbHata').textContent = res.j.hata || 'Hazırlanamadı.';
                el('bbHata').hidden = false;
                return;
            }
            veri = res.j;
            var vs = veri.varsayilan || {};
            doldur(el('bbSifat'), veri.katalog.sifatlar,        vs.sifatId || '');
            doldur(el('bbTur'),   veri.katalog.bildirimTurleri, vs.bildirimTuruId || '');

            el('bbTablo').querySelector('tbody').innerHTML = veri.satirlar.map(function (r) {
                if (r.engel) {
                    return '<tr class="bb-engel"><td>' + eHtml(r.partiNo || '#' + r.id) + '</td>' +
                           '<td colspan="5" class="muted">⚠️ ' + eHtml(r.engel) + '</td></tr>';
                }
                var f = r.fiyat === null ? '' : r.fiyat.toLocaleString('tr-TR', { maximumFractionDigits: 4 });
                return '<tr><td><strong>' + eHtml(r.partiNo || '#' + r.id) + '</strong></td>' +
                       '<td>' + eHtml(r.urunAd) + '</td><td>' + eHtml(r.ulkeAd) + '</td>' +
                       '<td>' + eHtml(r.plaka) + '</td>' +
                       '<td class="num">' + r.netKg.toLocaleString('tr-TR') + '</td>' +
                       '<td><input type="text" class="form-control bb-fiyat" id="bbFiyat_' + r.id +
                       '" inputmode="decimal" value="' + eHtml(f) + '" placeholder="örn. 12,50"></td></tr>';
            }).join('');

            Array.prototype.forEach.call(document.querySelectorAll('.bb-fiyat'), function (i) {
                i.addEventListener('input', kontrol);
            });
            el('bbSifat').addEventListener('change', kontrol);
            el('bbTur').addEventListener('change', kontrol);

            el('bbYukleniyor').hidden = true;
            el('bbIcerik').hidden = false;
            kontrol();
        })
        .catch(function (e) {
            el('bbYukleniyor').hidden = true;
            el('bbHata').textContent = 'Bağlantı hatası: ' + e.message;
            el('bbHata').hidden = false;
        });
    });

    el('bbKaydet').addEventListener('click', function () {
        var satirlar = veri.satirlar.filter(function (r) { return !r.engel; }).map(function (r) {
            return { beyan_id: r.id, fiyat: el('bbFiyat_' + r.id).value };
        });
        if (!satirlar.length) return;
        if (!confirm(satirlar.length + ' beyan için HKS TASLAĞI oluşturulacak.\n\n' +
                     'Bildirim GÖNDERİLMEZ; gönderim Hal Kayıt ekranında yapılır.')) return;

        el('bbKaydet').disabled = true;
        el('bbKaydet').textContent = 'Oluşturuluyor…';
        fetch('api_beyan_bildirim.php?action=toplu_olustur', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf: CSRF, sifatId: el('bbSifat').value,
                bildirimTuruId: el('bbTur').value, satirlar: satirlar
            })
        })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
            if (!res.ok) {
                alert('HATA: ' + (res.j.hata || 'Taslaklar oluşturulamadı.'));
                el('bbKaydet').disabled = false;
                el('bbKaydet').textContent = 'Taslakları Oluştur';
                return;
            }
            // Başarısız satırlar SESSİZCE geçilmez — hangisi neden olmadı, yazılır.
            var hatalilar = res.j.sonuclar.filter(function (x) { return !x.tamam; });
            var mesaj = '✅ ' + res.j.mesaj;
            if (hatalilar.length) {
                mesaj += '\n\nOluşturulamayanlar:\n' + hatalilar.map(function (x) {
                    return '• ' + (x.partiNo || '#' + x.id) + ': ' + x.hata;
                }).join('\n');
            }
            alert(mesaj);
            location.reload();
        })
        .catch(function (e) {
            alert('Bağlantı hatası: ' + e.message);
            el('bbKaydet').disabled = false;
            el('bbKaydet').textContent = 'Taslakları Oluştur';
        });
    });

    barGuncelle();
})();
</script>
<?php endif; ?>

<?php render_footer(); ?>
