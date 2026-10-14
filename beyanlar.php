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
    global $q, $f_status, $f_urun, $f_marka, $f_depo, $tarih_bas, $tarih_bit, $page, $bek_q, $bek_page;
    $p = [
        'q'         => $q,
        'status'    => $f_status,
        'urun'      => $f_urun,
        'marka'     => $f_marka,
        'depo'      => $f_depo,
        'tarih_bas' => $tarih_bas,
        'tarih_bit' => $tarih_bit,
        'page'      => $page > 1 ? (string)$page : '',
        'bek_q'     => $bek_q,
        'bek_page'  => $bek_page > 1 ? (string)$bek_page : '',
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
$bek_q     = trim((string)($_GET['bek_q'] ?? ''));
$bek_page  = max(1, (int)($_GET['bek_page'] ?? 1));

// Durum filtresi ALT (arşiv) bölümünün özelliğidir; o bölümde yalnız kapalı
// durumlar bulunur. Bekleyen bir durum gelirse (eski yer imi) filtre olarak
// UYGULANMAZ — yoksa arşiv sebepsiz boş görünürdü; üst bölüm onu zaten gösterir.
$valid_statuses = beyan_kapali_durumlar();
if (!in_array($f_status, $valid_statuses, true)) $f_status = '';
if ($tarih_bas !== '' && !valid_date_beyan($tarih_bas)) $tarih_bas = '';
if ($tarih_bit !== '' && !valid_date_beyan($tarih_bit)) $tarih_bit = '';

// ── WHERE koşulları ───────────────────────────────────────
$where  = "WHERE deleted_at IS NULL";
$params = [];

if ($q !== '') {
    $search_parts = [];
    foreach (['party_no','product_name','product_variety','buyer_name','brand','exit_depot','company_name','contact_person'] as $si => $sc) {
        if (!can_beyan('contact.read') && in_array($sc, ['buyer_name', 'contact_person'], true)) continue;
        $search_parts[] = "$sc LIKE :q$si";
        $params[':q' . $si] = '%' . $q . '%';
    }
    $where .= ' AND (' . implode(' OR ', $search_parts) . ')';
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

// ── İki bölüm: yüklenmeyenler (üstte) / yüklenenler (altta) ───────────────
// Durum bölümü belirler: kapalı durumlar (yüklendi/iptal/red) ALT bölüme,
// geri kalan HER durum ÜST bölüme düşer.
//
// ÜST bölüm filtre şeridinden BAĞIMSIZ ve SAYFALANMAZ: burası "işlem bekleyen"
// tam listedir, bir arama ya da sayfa geçişi onu eksiltmemeli. Filtre şeridi
// ALT (arşiv) bölümünün süzgecidir ve orada, o bölümün içinde durur.
$kapali_durumlar = beyan_kapali_durumlar();
$kapali_ph       = implode(',', array_map(fn($i) => ':kd' . $i, array_keys($kapali_durumlar)));
$kapali_params   = [];
foreach ($kapali_durumlar as $i => $kd) $kapali_params[':kd' . $i] = $kd;

// Bekleyenlerin kendi araması/sayfası var; arşiv filtreleri burayı etkilemez.
$st_bek_c = db()->prepare("SELECT COUNT(*) FROM customs_declarations
                           WHERE deleted_at IS NULL AND status NOT IN ($kapali_ph)");
$st_bek_c->execute($kapali_params);
$bekleyen_total = (int)$st_bek_c->fetchColumn();

$bek_where = "WHERE deleted_at IS NULL AND status NOT IN ($kapali_ph)";
$bek_params = $kapali_params;
if ($bek_q !== '') {
    $bek_parts = [];
    foreach (['party_no','product_name','product_variety','buyer_name','brand','exit_depot','vehicle_plate'] as $si => $sc) {
        if (!can_beyan('contact.read') && $sc === 'buyer_name') continue;
        $bek_parts[] = "$sc LIKE :bq$si";
        $bek_params[':bq' . $si] = '%' . $bek_q . '%';
    }
    $bek_where .= ' AND (' . implode(' OR ', $bek_parts) . ')';
}
$bek_count_st = db()->prepare("SELECT COUNT(*) FROM customs_declarations $bek_where");
$bek_count_st->execute($bek_params);
$bek_result_total = (int)$bek_count_st->fetchColumn();
$bek_total_pages = max(1, (int)ceil($bek_result_total / BEYAN_PER_PAGE));
$bek_page = min($bek_page, $bek_total_pages);
$st_bek = db()->prepare("SELECT * FROM customs_declarations $bek_where ORDER BY id DESC LIMIT :lim OFFSET :off");
foreach ($bek_params as $k => $v) $st_bek->bindValue($k, $v);
$st_bek->bindValue(':lim', BEYAN_PER_PAGE, PDO::PARAM_INT);
$st_bek->bindValue(':off', ($bek_page - 1) * BEYAN_PER_PAGE, PDO::PARAM_INT);
$st_bek->execute();
$bekleyen_rows   = array_map('beyan_redact_fields', $st_bek->fetchAll());

// ALT bölüm — kapanan arşiv; kendi filtreleri ve sayfalaması vardır.
$w_kapali = $where . " AND status IN ($kapali_ph)";
$p_kapali = $params + $kapali_params;

$st_count = db()->prepare("SELECT COUNT(*) FROM customs_declarations $w_kapali");
$st_count->execute($p_kapali);
$kapali_total = (int)$st_count->fetchColumn();
$total_pages  = max(1, (int)ceil($kapali_total / BEYAN_PER_PAGE));
$page         = min($page, $total_pages);
$offset       = ($page - 1) * BEYAN_PER_PAGE;

$st = db()->prepare("SELECT * FROM customs_declarations $w_kapali ORDER BY id DESC LIMIT :lim OFFSET :off");
foreach ($p_kapali as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':lim', BEYAN_PER_PAGE, PDO::PARAM_INT);
$st->bindValue(':off', $offset,        PDO::PARAM_INT);
$st->execute();
$kapali_rows = array_map('beyan_redact_fields', $st->fetchAll());

$total = (int)db()->query("SELECT COUNT(*) FROM customs_declarations WHERE deleted_at IS NULL")->fetchColumn();
// Toplu bildirim uygunluk sorgusu ve seçim JS'i İKİ bölümün satırlarını
// birlikte görür — uygunluk kapısı bölümden bağımsızdır.
$rows = array_merge($bekleyen_rows, $kapali_rows);

// ── Toplu bildirim: hangi satırlar uygun? ─────────────────────────────────
// beyan_view'daki buton kapısının aynısı. Aktif bağlar TEK sorguda çekilir
// (satır başına sorgu, 50 satırlık sayfada 50 sorgu demekti).
$toplu_yetki = can_beyan('notify') && can_beyan('hks.write') && (can('records.write') || is_admin());
$aktif_bagli = [];
if ($toplu_yetki && $rows) {
    try {
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $bs  = db()->prepare("SELECT DISTINCT beyan_id FROM beyan_hks_bildirim
                              WHERE beyan_id IN ($ph) AND durum IN (" . beyan_hks_aktif_durumlar_sql() . ")");
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

// HKS firma adları (tek sorgu) — listede firma + plaka görünsün diye.
$firma_adlari = beyan_hks_firma_adlari(array_map(fn($r) => (string)($r['hks_firma_id'] ?? ''), $rows));
$kopyala_yetki = can_beyan('create');

$statuses  = beyan_statuses();
$today     = date('Y-m-d');
$has_filter = $q !== '' || $f_status !== '' || $f_urun !== '' || $f_marka !== '' || $f_depo !== '' || $tarih_bas !== '' || $tarih_bit !== '';

// Detay filtresi etkin mi? Panel bu koşulla açık gelir, toggle bu koşulla
// işaretlenir ve panel kapalıyken listenin neye göre süzüldüğünü yazar.
$detay_ozet = [];
if ($f_status !== '') $detay_ozet[] = $statuses[$f_status]['label'] ?? $f_status;
if ($tarih_bas !== '' || $tarih_bit !== '') $detay_ozet[] = 'Tarih';
if ($f_urun  !== '')  $detay_ozet[] = $f_urun;
if ($f_marka !== '')  $detay_ozet[] = $f_marka;
if ($f_depo  !== '')  $detay_ozet[] = $f_depo;
$detay_aktif = !empty($detay_ozet);
$detay_sayi  = count($detay_ozet);
$detay_ilk   = $detay_ozet[0] ?? '';

render_header('Beyanlar');
render_flash();
?>

<div class="page-head">
    <div>
        <h1>🧾 Beyanlar</h1>
        <p class="muted">Toplam <?= $total ?> beyan<?php if ($bekleyen_total): ?> · <strong><?= $bekleyen_total ?></strong> işlem bekliyor<?php endif; ?></p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <?php if (is_admin()): ?>
        <a href="beyan_bildirim_tani.php" class="btn btn-ghost btn-sm"
           title="Bildirim köprüsü ön kontrolü (salt-okunur)">🩺 Bildirim Ön Kontrol</a>
        <?php endif; ?>
        <?php if (can_beyan('create')): ?>
        <a href="beyan_create.php" class="btn btn-primary btn-lg">+ Yeni Beyan</a>
        <?php endif; ?>
    </div>
</div>

<!-- ══ ÜST BÖLÜM — YÜKLENMEYENLER ═══════════════════════════════════════════
     İşlem bekleyen beyanlar. Filtreden ve sayfalamadan BAĞIMSIZ: burası tam
     bir iş listesidir, arama ya da sayfa geçişi onu eksiltmez. -->
<section class="beyan-blok beyan-blok-acik">
    <div class="beyan-blok-head">
        <span class="bl-ikon bl-ikon-acik" aria-hidden="true">⏳</span>
        <div class="bl-baslik-kap">
            <h2 class="beyan-blok-baslik">İşlem Bekleyen Beyanlar
                <span class="beyan-blok-sayi"><?= $bekleyen_total ?></span>
            </h2>
            <span class="muted beyan-blok-not">Açık işler · <?= $bek_result_total ?> sonuç · Sayfa <?= $bek_page ?> / <?= $bek_total_pages ?></span>
        </div>
        <?php if (!empty($bekleyen_rows)): ?>
        <!-- v357: kartta görünen satırları yazdır / PDF olarak gönder (assets/beyan_yazdir.js) -->
        <div class="bl-aksiyon" role="group" aria-label="Bekleyen beyanları yazdır veya PDF gönder">
            <button type="button" class="btn btn-sm bl-yazdir-btn" id="bekleyenYazdirBtn"
                    title="Bu sayfadaki bekleyen beyanları yazdır">🖨️ Yazdır</button>
            <button type="button" class="btn btn-sm bl-pdf-btn" id="bekleyenPdfBtn"
                    title="Telefonda WhatsApp'a gönderir, bilgisayarda PDF indirir">📤 PDF Gönder</button>
        </div>
        <?php endif; ?>
        <span class="bl-bilgi bl-bilgi-acik">Onay ve yükleme bekleyen beyan kayıtlarıdır.</span>
    </div>

    <form method="get" class="beyan-pending-search">
        <?php foreach (['q' => $q, 'status' => $f_status, 'urun' => $f_urun, 'marka' => $f_marka, 'depo' => $f_depo,
                        'tarih_bas' => $tarih_bas, 'tarih_bit' => $tarih_bit, 'page' => $page > 1 ? (string)$page : ''] as $fk => $fv):
            if ($fv === '') continue; ?>
        <input type="hidden" name="<?= h($fk) ?>" value="<?= h($fv) ?>">
        <?php endforeach; ?>
        <label class="form-label" for="bekleyenSearch">Bekleyenlerde ara</label>
        <div class="bff-main bl-arama" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
            <input type="search" id="bekleyenSearch" name="bek_q" class="form-control" style="flex:1;min-width:160px"
                   value="<?= h($bek_q) ?>" placeholder="Parti no, ürün, alıcı, plaka…">
            <button class="btn">Ara</button>
            <?php if ($bek_q !== ''): ?><a class="btn btn-ghost" href="<?= h(beyan_url(['bek_q' => '', 'bek_page' => ''])) ?>">Temizle</a><?php endif; ?>
        </div>
    </form>

    <?php if (empty($bekleyen_rows)): ?>
    <div class="beyan-blok-bos">
        <?= $bek_q !== '' ? 'Aramanızla eşleşen bekleyen beyan yok.' : '✅ İşlem bekleyen beyan yok — hepsi yüklendi ya da kapandı.' ?>
        <?php if (can_beyan('create')): ?>
        <a href="beyan_create.php" class="btn btn-sm">+ Yeni Beyan</a>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <?php
        $sec_rows  = $bekleyen_rows;
        $sec_secim = (bool)$uygun_sayisi;
        include __DIR__ . '/_beyan_liste.php';
    ?>
    <?php if ($bek_total_pages > 1): ?>
    <nav class="pagination" aria-label="Bekleyen beyan sayfaları" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
        <?php if ($bek_page > 1): ?><a class="btn btn-sm btn-ghost" href="<?= h(beyan_url(['bek_page' => (string)($bek_page - 1)])) ?>">← Önceki</a><?php endif; ?>
        <span class="muted">Sayfa <?= $bek_page ?> / <?= $bek_total_pages ?> · <?= $bek_result_total ?> sonuç</span>
        <?php if ($bek_page < $bek_total_pages): ?><a class="btn btn-sm btn-ghost" href="<?= h(beyan_url(['bek_page' => (string)($bek_page + 1)])) ?>">Sonraki →</a><?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
</section>

<!-- ══ ALT BÖLÜM — YÜKLENENLER + FİLTRE ═════════════════════════════════════
     Yüklenen/kapanan beyanların aranabilir arşivi. Filtre şeridi ve sayfalama
     bu bölüme aittir; üst bölümü etkilemez. -->
<section class="beyan-blok beyan-blok-kapanmis">
    <div class="beyan-blok-head">
        <span class="bl-ikon bl-ikon-kapali" aria-hidden="true">✅</span>
        <div class="bl-baslik-kap">
            <h2 class="beyan-blok-baslik">Kapanan Beyanlar
                <span class="beyan-blok-sayi"><?= $kapali_total ?></span>
            </h2>
            <span class="muted beyan-blok-not">
                Tamamlanan kayıtlar (yüklendi · iptal · red)
                <?php if ($total_pages > 1): ?> · Sayfa <?= $page ?> / <?= $total_pages ?><?php endif; ?>
            </span>
        </div>
        <span class="bl-bilgi bl-bilgi-kapali">Yükleme tamamlanan, iptal veya reddedilen kayıtlardır.</span>
    </div>

    <!-- ── Filtre formu — yalnız bu bölümü süzer ── -->
    <form method="get" class="beyan-filter-form" id="beyanFilterForm">
        <?php if ($bek_q !== ''): ?><input type="hidden" name="bek_q" value="<?= h($bek_q) ?>"><?php endif; ?>
        <?php if ($bek_page > 1): ?><input type="hidden" name="bek_page" value="<?= $bek_page ?>"><?php endif; ?>
        <div class="bff-main">
            <input type="search" name="q" value="<?= h($q) ?>"
                   placeholder="Parti no, ürün, alıcı, marka, depo..." autocomplete="off">
            <button class="btn">Ara</button>
            <!-- Aç/kapa düğmesi arama satırının İÇİNDE — kendine satır açmaz.
                 Etkin bir detay filtresi varsa ne olduğunu da yazar, böylece panel
                 kapalıyken de listenin neye göre süzüldüğü görünür. -->
            <button type="button" class="beyan-filter-toggle" id="beyanFilterToggle"
                    aria-controls="beyanFilterPanel" aria-expanded="<?= $detay_aktif ? 'true' : 'false' ?>">
                <span class="bft-ok" aria-hidden="true">▾</span> Filtre<?php if ($detay_ilk !== ''): ?><span class="bft-rozet"><?= h($detay_ilk) ?><?= $detay_sayi > 1 ? ' +' . ($detay_sayi - 1) : '' ?></span><?php endif; ?>
            </button>
            <?php if ($has_filter): ?>
            <a href="beyanlar.php" class="btn btn-ghost">Temizle</a>
            <?php endif; ?>
        </div>

        <!-- Detay paneli HER genişlikte katlanır. Eskiden ≥768px'de toggle
             gizliydi, panel kalıcı açık geliyordu ve on durum pili üç satıra
             sarıp beş girdiyle birlikte liste üstünde ~200px yer kaplıyordu. -->
        <div class="bff-filters<?= $detay_aktif ? ' bff-open' : '' ?>" id="beyanFilterPanel">
            <!-- Durum pilleri tek satırda, taşarsa yatay kayar (sarmaz).
                 Bağlantı (link) olarak kalmaları bilinçli: tek tıkla filtrelerler,
                 forma bağlı değiller ve JS kapalıyken de çalışırlar.
                 Yalnız KAPALI durumlar listelenir — bu bölümde yalnız onlar var;
                 bekleyen durumlar üst bölümün işi. -->
            <div class="bff-durum">
                <span class="bff-durum-lbl">Durum</span>
                <a href="<?= beyan_url(['status' => '', 'page' => '']) ?>"
                   class="pill<?= $f_status === '' ? ' active' : '' ?>">Tümü</a>
                <?php foreach ($kapali_durumlar as $sk): $sv = $statuses[$sk] ?? null; if (!$sv) continue; ?>
                <a href="<?= beyan_url(['status' => $sk, 'page' => '']) ?>"
                   class="pill<?= $f_status === $sk ? ' active' : '' ?>"><?= h($sv['label']) ?></a>
                <?php endforeach; ?>
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
            <div class="bff-uygula">
                <button class="btn btn-sm" style="white-space:nowrap">Filtrele</button>
            </div>
        </div>
    </form>

    <?php if (empty($kapali_rows)): ?>
    <div class="beyan-blok-bos">
        <?php if ($has_filter): ?>
            Filtre kriterlerine uyan yüklenmiş beyan bulunamadı.
            <a href="beyanlar.php" class="btn btn-sm btn-ghost">Filtreleri temizle</a>
        <?php else: ?>
            Henüz yüklenmiş beyan yok.
        <?php endif; ?>
    </div>
    <?php else: ?>
    <?php
        $sec_rows  = $kapali_rows;
        $sec_secim = (bool)$uygun_sayisi;
        include __DIR__ . '/_beyan_liste.php';
    ?>

    <!-- Sayfalama — yalnız arşiv bölümüne ait -->
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
</section>

<script>
// Liste: durum rozeti üzerine gelince aşağı açılan durum seçenekleri (v344). Menü position:fixed —
// .table-wrap kaydırma alanında kesilmez. Fare ayrılınca 3 sn sonra kapanır; geri gelirse iptal.
(function () {
    var masaustu = window.matchMedia('(min-width: 1024px)');
    var acik = null, zamanlayici = null;
    function iptal() { if (zamanlayici) { clearTimeout(zamanlayici); zamanlayici = null; } }
    function kapat(f) {
        if (!f) return;
        f.classList.remove('is-open');
        var t = f.querySelector('.bl-durum-tetik'), m = f.querySelector('.bl-durum-menu');
        if (t) t.setAttribute('aria-expanded', 'false');
        if (m) m.inert = true;
        if (acik === f) acik = null;
    }
    function ac(f) {
        if (!masaustu.matches) return;
        iptal();
        if (acik && acik !== f) kapat(acik);
        var t = f.querySelector('.bl-durum-tetik'), m = f.querySelector('.bl-durum-menu');
        var r = t.getBoundingClientRect();
        m.style.left = Math.max(8, Math.min(r.left, window.innerWidth - 180)) + 'px';
        m.style.top = (r.bottom + 4) + 'px';
        m.style.maxHeight = Math.max(120, window.innerHeight - r.bottom - 16) + 'px';
        f.classList.add('is-open');
        t.setAttribute('aria-expanded', 'true');
        m.inert = false;
        acik = f;
    }
    function planla(f) {
        iptal();
        zamanlayici = setTimeout(function () { kapat(f); }, 3000);
    }
    document.querySelectorAll('[data-bl-durum]').forEach(function (f) {
        f.addEventListener('mouseenter', function () { ac(f); });
        f.addEventListener('mouseleave', function () { planla(f); });
        f.addEventListener('focusin', function () { ac(f); });
        f.addEventListener('focusout', function (e) { if (!f.contains(e.relatedTarget)) planla(f); });
        f.querySelector('.bl-durum-tetik').addEventListener('click', function () { f.classList.contains('is-open') ? kapat(f) : ac(f); });
        f.addEventListener('keydown', function (e) { if (e.key === 'Escape') { kapat(f); f.querySelector('.bl-durum-tetik').focus(); } });
        f.addEventListener('submit', function (e) {
            var b = e.submitter;
            if (b && b.hasAttribute('data-red')) {
                var n = prompt('Red için analiz notu giriniz (zorunlu):');
                if (!n || !n.trim()) { e.preventDefault(); return; }
                var h = document.createElement('input');
                h.type = 'hidden'; h.name = 'analysis_note'; h.value = n.trim();
                f.appendChild(h);
            }
        });
    });
    document.addEventListener('click', function (e) { if (acik && !acik.contains(e.target)) kapat(acik); });
    window.addEventListener('scroll', function () { if (acik) kapat(acik); }, true);
    window.addEventListener('resize', function () { if (acik) kapat(acik); });
})();
</script>

<?php if (!empty($bekleyen_rows)): ?>
<script type="application/json" id="bekleyenYazdirVeri"><?= json_encode(
    beyan_bekleyen_yazdir_veri($bekleyen_rows, $firma_adlari, (string)(active_depot() ?? ''), $bek_q),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR
) ?></script>
<script src="assets/beyan_yazdir.js?v=<?= (int)@filemtime(__DIR__ . '/assets/beyan_yazdir.js') ?>" defer></script>
<?php endif; ?>

<script>
(function () {
    var toggle = document.getElementById('beyanFilterToggle');
    var panel  = document.getElementById('beyanFilterPanel');
    if (toggle && panel) {
        toggle.addEventListener('click', function () {
            var acik = panel.classList.toggle('bff-open');
            toggle.setAttribute('aria-expanded', acik ? 'true' : 'false');
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

    // Her bölümün (yüklenmeyen / yüklenen) kendi tablosu ve kendi "tümü"
    // kutusu var — id yerine sınıf, çünkü sayfada iki tane olabilir.
    function kapsamKutulari(t) {
        var kapsam = t.closest('table') || document;
        return Array.prototype.slice.call(kapsam.querySelectorAll('.bb-sec'));
    }

    function barGuncelle() {
        var n = seciliIdler().length;
        el('bbBar').hidden = n === 0;
        el('bbBarSayi').textContent = n + ' beyan seçildi';
        Array.prototype.forEach.call(document.querySelectorAll('.bb-tumu'), function (t) {
            var kutular = kapsamKutulari(t);
            t.checked = kutular.length > 0 && kutular.every(function (c) { return c.checked; });
        });
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

    // "Tümü" yalnız KENDİ bölümünü seçer: yüklenmeyenleri seçmek isteyen
    // kullanıcı, arşiv satırlarını istemeden işaretlemesin.
    Array.prototype.forEach.call(document.querySelectorAll('.bb-tumu'), function (tumu) {
        tumu.addEventListener('change', function () {
            kapsamKutulari(tumu).forEach(function (c) {
                if (c.checked === tumu.checked) return;
                c.checked = tumu.checked;
                // Masaüstü satırının mobil kart eşini de eşitle.
                Array.prototype.forEach.call(document.querySelectorAll('.bb-sec'), function (o) {
                    if (o.value === c.value) o.checked = tumu.checked;
                });
            });
            barGuncelle();
        });
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
