<?php
// =========================================================
// hesap_sahipsiz.php — "Sahipsiz Kayıtlar" (yalnız yönetici)
//
// user_id NULL olan eski (Hesap-01 öncesi) kayıtlar hiç kimsenin hesabında
// görünmez ve hiç kimsenin bakiyesine girmez. Yönetici bu ekrandan her kaydı
// (açıklama / kişi-firma / fişe bakarak) tek tek ya da toplu olarak sahibine atar.
//
//   · Otomatik atama / tahmin YOK (eski satırlarda created_by da boş).
//   · UPDATE her zaman "AND user_id IS NULL" korumalı — eşzamanlı ya da ikinci
//     bir atama başkasına ait satırı EZEMEZ.
//   · Her atanan satır audit'e (owner_assign) + bir özet (bulk_update).
//   · Yanlış atama hesap_kayit.php "Kayıt sahibi" alanından tek tek geri alınır.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_hesap('read');
hesap_migrate();

if (!hesap_sees_all()) {
    forbidden('Bu sayfa yalnız yöneticiye açıktır. (Gerekli yetki: hesap.admin)');
}

$toplu_max = 500;

// Atanabilir kullanıcılar — yalnız AKTİF hesaplar
$kullanicilar = [];
try {
    $kullanicilar = db()->query("SELECT id, COALESCE(NULLIF(display_name,''), username) AS ad
                                 FROM users WHERE is_active = 1 ORDER BY ad")->fetchAll();
} catch (PDOException $e) { $kullanicilar = []; }
$kullanici_ad = [];
foreach ($kullanicilar as $ku) { $kullanici_ad[(int)$ku['id']] = (string)$ku['ad']; }

// Filtre parametreleri (GET ve POST dönüşünde aynı)
$filtre_oku = function (array $src, string $on = ''): array {
    $f = [
        'q'         => trim((string)($src[$on . 'q'] ?? '')),
        'type'      => trim((string)($src[$on . 'type'] ?? '')),
        'durum'     => trim((string)($src[$on . 'durum'] ?? '')),
        'tarih_bas' => trim((string)($src[$on . 'tarih_bas'] ?? '')),
        'tarih_son' => trim((string)($src[$on . 'tarih_son'] ?? '')),
    ];
    if (!in_array($f['type'], ['', 'gelir', 'gider', 'havale', 'nakit'], true)) $f['type'] = '';
    if ($f['durum'] !== '' && !hesap_status_valid($f['durum'])) $f['durum'] = '';
    foreach (['tarih_bas', 'tarih_son'] as $k) {
        if ($f[$k] !== '' && !hesap_tarih_gecerli($f[$k])) $f[$k] = '';
    }
    return $f;
};

// ── POST: sahip ata (tek ya da toplu) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $donus = $filtre_oku($_POST, 'f_');

    $hedef = (int)($_POST['hedef_uid'] ?? 0);
    $ids   = array_values(array_unique(array_filter(
        array_map('intval', is_array($_POST['ids'] ?? null) ? $_POST['ids'] : []),
        fn($v) => $v > 0
    )));

    if (($_POST['islem'] ?? '') !== 'ata') {
        set_flash('error', 'Geçersiz işlem.');
    } elseif (!isset($kullanici_ad[$hedef])) {
        set_flash('error', 'Geçerli (aktif) bir kullanıcı seçin.');
    } elseif (empty($ids)) {
        set_flash('error', 'Kayıt seçilmedi.');
    } elseif (count($ids) > $toplu_max) {
        set_flash('error', 'Tek seferde en çok ' . $toplu_max . ' kayıt atanabilir.');
    } else {
        $pdo = db();
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        // Satır kilidi yalnız MySQL'de (SQLite testinde FOR UPDATE yok)
        $kilit = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare("SELECT id, type, amount, currency, status FROM account_transactions
                                  WHERE id IN ($ph) AND user_id IS NULL$kilit");
            $st->execute($ids);
            $bulunan = $st->fetchAll();

            $etkilenen = 0;
            if (!empty($bulunan)) {
                $bids = array_map(fn($r) => (int)$r['id'], $bulunan);
                $bph  = implode(',', array_fill(0, count($bids), '?'));
                $up = $pdo->prepare("UPDATE account_transactions SET user_id = ?
                                      WHERE id IN ($bph) AND user_id IS NULL");
                $up->execute(array_merge([$hedef], $bids));
                $etkilenen = $up->rowCount();
            }
            foreach ($bulunan as $r) {
                audit_log_event('owner_assign', 'hesap', (int)$r['id'], ['user_id' => null], [
                    'user_id'  => $hedef,
                    'amount'   => (float)$r['amount'],
                    'currency' => $r['currency'],
                    'status'   => $r['status'],
                ]);
            }
            $atlanan = count($ids) - count($bulunan);
            audit_log_event('bulk_update', 'hesap', null, null, [
                'operation'      => 'owner_assign',
                'target_user'    => $hedef,
                'affected_count' => $etkilenen,
                'skipped_count'  => $atlanan,
            ]);
            $pdo->commit();

            // Kullanıcıya: bakiyeye giren net (kur başına ayrı — asla toplanmaz)
            $net = [];
            foreach ($bulunan as $r) {
                if (!in_array((string)$r['status'], hesap_balance_statuses(), true)) continue;
                $c = $r['currency'] ?: 'TRY';
                $net[$c] = ($net[$c] ?? 0.0) + ($r['type'] === 'gelir' ? (float)$r['amount'] : -(float)$r['amount']);
            }
            $msg = count($bulunan) . ' kayıt ' . $kullanici_ad[$hedef] . ' kişisine atandı.';
            if (!empty($net)) {
                $msg .= ' Bakiyeye giren net: ' . implode(' · ', array_map(
                    fn($c, $v) => ($v >= 0 ? '+' : '−') . number_format(abs($v), 2, ',', '.') . ' ' . $c,
                    array_keys($net), $net
                )) . '.';
            }
            if ($atlanan > 0) $msg .= ' ' . $atlanan . ' kayıt zaten atanmıştı, atlandı.';
            set_flash(count($bulunan) > 0 ? 'success' : 'error', $msg);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[hesap_sahipsiz ata] ' . $e->getMessage());
            set_flash('error', 'Atama yapılamadı — hiçbir kayıt değiştirilmedi.');
        }
    }
    header('Location: hesap_sahipsiz.php' . (($qs = http_build_query(array_filter($donus))) !== '' ? '?' . $qs : ''));
    exit;
}

// ── GET: özet + filtreli liste ──
$f = $filtre_oku($_GET);
$sayfa  = max(1, (int)($_GET['sayfa'] ?? 1));
$limit  = 50;
$offset = ($sayfa - 1) * $limit;

// Özet — tek sorgu (filtreden bağımsız: tüm sahipsiz kayıtlar)
$ozet_st = db()->query("SELECT currency, status, COUNT(*) AS adet,
        COALESCE(SUM(CASE WHEN type='gelir' THEN amount ELSE -amount END),0) AS net
    FROM account_transactions WHERE user_id IS NULL
    GROUP BY currency, status");
$ozet_toplam = 0;
$ozet = [];   // kur => ['bakiye'=>net,'bekleyen'=>net,'red'=>adet,'adet'=>n]
foreach ($ozet_st->fetchAll() as $o) {
    $c = $o['currency'] ?: 'TRY';
    $ozet[$c] ??= ['bakiye' => 0.0, 'bakiye_adet' => 0, 'bekleyen' => 0.0, 'bekleyen_adet' => 0, 'red' => 0, 'adet' => 0];
    $ozet[$c]['adet'] += (int)$o['adet'];
    $ozet_toplam      += (int)$o['adet'];
    if (in_array((string)$o['status'], hesap_balance_statuses(), true)) {
        $ozet[$c]['bakiye'] += (float)$o['net'];  $ozet[$c]['bakiye_adet'] += (int)$o['adet'];
    } elseif ((string)$o['status'] === 'rejected') {
        $ozet[$c]['red'] += (int)$o['adet'];
    } else {
        $ozet[$c]['bekleyen'] += (float)$o['net']; $ozet[$c]['bekleyen_adet'] += (int)$o['adet'];
    }
}
uksort($ozet, fn($a, $b) => ($b === 'TRY' ? 1 : 0) <=> ($a === 'TRY' ? 1 : 0) ?: strcmp($a, $b));

$where  = ['user_id IS NULL'];
$params = [];
if ($f['q'] !== '') {
    $where[] = "(category LIKE ? OR person_company LIKE ? OR description LIKE ? OR document_no LIKE ?)";
    array_push($params, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%");
}
if ($f['type']      !== '') { $where[] = 'type = ?';              $params[] = $f['type']; }
if ($f['durum']     !== '') { $where[] = 'status = ?';            $params[] = $f['durum']; }
if ($f['tarih_bas'] !== '') { $where[] = 'transaction_date >= ?'; $params[] = $f['tarih_bas']; }
if ($f['tarih_son'] !== '') { $where[] = 'transaction_date <= ?'; $params[] = $f['tarih_son']; }
$wstr = implode(' AND ', $where);

$cnt = db()->prepare("SELECT COUNT(*) FROM account_transactions WHERE $wstr");
$cnt->execute($params);
$toplam = (int)$cnt->fetchColumn();
$toplam_sayfa = max(1, (int)ceil($toplam / $limit));

$st = db()->prepare("SELECT * FROM account_transactions WHERE $wstr
                     ORDER BY transaction_date DESC, id DESC LIMIT $limit OFFSET $offset");
$st->execute($params);
$rows = $st->fetchAll();

// Kayıt başına ilk fiş dosyası (tek sorgu)
$fisler = [];
$rid = array_map(fn($r) => (int)$r['id'], $rows);
if (!empty($rid)) {
    $ph = implode(',', array_fill(0, count($rid), '?'));
    $fs = db()->prepare("SELECT transaction_id, file_name FROM account_files WHERE transaction_id IN ($ph) ORDER BY id");
    $fs->execute($rid);
    foreach ($fs->fetchAll() as $fr) { $fisler[(int)$fr['transaction_id']] ??= $fr['file_name']; }
}

// Kullanıcı seçim kutusu (tek parça — satır ve toplu formlar aynı listeyi kullanır)
$kisi_secenek = function (string $name, string $form = '') use ($kullanicilar): string {
    $o = '<select name="' . h($name) . '" class="hs-select" required' . ($form !== '' ? ' form="' . h($form) . '"' : '')
       . ' aria-label="Atanacak kişi"><option value="">— Kişi seçin —</option>';
    foreach ($kullanicilar as $ku) {
        $o .= '<option value="' . (int)$ku['id'] . '">' . h($ku['ad']) . '</option>';
    }
    return $o . '</select>';
};
$filtre_gizli = function () use ($f): string {
    $o = '';
    foreach ($f as $k => $v) {
        if ($v !== '') $o .= '<input type="hidden" name="f_' . h($k) . '" value="' . h($v) . '">';
    }
    return $o;
};
$onay_js = "var s=this.hedef_uid; if(!s.value){return false;} return confirm('Kayıt ' + s.options[s.selectedIndex].text + ' kişisine atansın mı? Kayıt bu kişinin bakiyesine girer.');";

render_header('Sahipsiz Kayıtlar');
hesap_assets();
render_flash();
?>
<div class="hs">
<div class="page-head">
    <div>
        <h1>🗂 Sahipsiz Kayıtlar</h1>
        <p class="muted">Sahibi olmayan eski hesap kayıtları — yalnız yönetici görür</p>
    </div>
    <div class="hs-actions">
        <a href="hesap_personel.php" class="btn btn-ghost">👥 Tüm Personel</a>
        <a href="hesap.php" class="btn btn-ghost">← Hesabım</a>
    </div>
</div>

<!-- Özet -->
<section class="hs-ozet" aria-label="Sahipsiz kayıt özeti">
    <p class="hs-ozet-baslik">Sahipsiz kayıt: <b><?= (int)$ozet_toplam ?></b></p>
    <?php if ($ozet_toplam > 0): ?>
    <div class="table-wrap">
    <table class="data-table hs-ozet-tablo">
        <thead><tr>
            <th>Kur</th><th class="num">Bakiyeye giren net</th><th class="num">Bekleyen net</th>
            <th class="num">Red</th><th class="num">Adet</th>
        </tr></thead>
        <tbody>
        <?php foreach ($ozet as $cur => $o): ?>
        <tr>
            <td><?= h($cur) ?></td>
            <td class="num"><?= fmt_para($o['bakiye'], $cur) ?> <span class="muted">(<?= (int)$o['bakiye_adet'] ?>)</span></td>
            <td class="num"><?= fmt_para($o['bekleyen'], $cur) ?> <span class="muted">(<?= (int)$o['bekleyen_adet'] ?>)</span></td>
            <td class="num"><?= (int)$o['red'] ?></td>
            <td class="num"><?= (int)$o['adet'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    <p class="hs-cur-note">Bu kayıtlar sahip atanana dek kimsenin bakiyesinde görünmez. Net: gelir − gider; para birimleri ayrı tutulur.</p>
</section>

<?php if ($ozet_toplam === 0): ?>
<div class="hs-empty">
    <span class="hs-empty-icon" aria-hidden="true">✅</span>
    <p>Sahipsiz kayıt kalmadı. Tüm kayıtlar bir personele ait.</p>
    <a href="hesap_personel.php" class="btn">Tüm Personel</a>
</div>
<?php else: ?>

<!-- Filtre -->
<form method="get" class="hs-filter-panel">
    <div class="hs-filter-row">
        <span class="hs-filter-label">Ara</span>
        <div class="hs-search">
            <input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="Kategori, kişi, açıklama, belge no...">
        </div>
    </div>
    <div class="hs-filter-row">
        <span class="hs-filter-label">Aralık</span>
        <div class="hs-daterange">
            <input type="date" name="tarih_bas" value="<?= h($f['tarih_bas']) ?>" aria-label="Başlangıç tarihi">
            <span class="sep">—</span>
            <input type="date" name="tarih_son" value="<?= h($f['tarih_son']) ?>" aria-label="Bitiş tarihi">
        </div>
    </div>
    <div class="hs-filter-row">
        <span class="hs-filter-label">Tür</span>
        <select name="type" class="hs-select">
            <option value="">Tüm türler</option>
            <?php foreach (['gelir','gider','havale','nakit'] as $t): ?>
            <option value="<?= $t ?>" <?= $f['type'] === $t ? 'selected' : '' ?>><?= h(hesap_type_label($t)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="hs-filter-row">
        <span class="hs-filter-label">Durum</span>
        <select name="durum" class="hs-select">
            <option value="">Tüm durumlar</option>
            <?php foreach (hesap_statuses() as $kod => $meta): ?>
            <option value="<?= h($kod) ?>" <?= $f['durum'] === $kod ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="hs-filter-actions">
            <?php if (array_filter($f)): ?><a href="hesap_sahipsiz.php" class="btn btn-sm btn-ghost">Temizle</a><?php endif; ?>
            <button class="btn btn-sm btn-primary">Filtrele</button>
        </div>
    </div>
</form>

<?php if (empty($rows)): ?>
<div class="hs-empty">
    <span class="hs-empty-icon" aria-hidden="true">🔍</span>
    <p>Bu filtrelerle sahipsiz kayıt bulunamadı.</p>
    <a href="hesap_sahipsiz.php" class="btn">Filtreleri Temizle</a>
</div>
<?php else: ?>

<!-- Toplu atama — seçim kutuları form="hsTopluAta" ile bu forma bağlıdır
     (satır formları iç içe olamayacağı için) -->
<form method="post" id="hsTopluAta" class="hs-toplu"
      onsubmit="var n=document.querySelectorAll('.hs-sec:checked').length; var s=this.hedef_uid;
                if(!n){alert('Önce kayıt seçin.');return false;} if(!s.value){return false;}
                return confirm(n + ' kayıt ' + s.options[s.selectedIndex].text + ' kişisine atansın mı? Kayıtlar bu kişinin bakiyesine girer.');">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="islem" value="ata">
    <?= $filtre_gizli() ?>
    <span class="hs-toplu-baslik"><?= (int)$toplam ?> kayıt · sayfa <?= $sayfa ?>/<?= $toplam_sayfa ?></span>
    <button type="button" class="btn btn-sm btn-ghost"
            onclick="var c=document.querySelectorAll('.hs-sec'); var hepsi=[].every.call(c,function(x){return x.checked}); c.forEach(function(x){x.checked=!hepsi});">
        Sayfadakilerin tümünü seç
    </button>
    <span class="hs-toplu-ata">
        Seçilenleri ata →
        <?= $kisi_secenek('hedef_uid') ?>
        <button type="submit" class="btn btn-sm btn-primary">Ata</button>
    </span>
</form>

<!-- Masaüstü: tablo -->
<div class="table-wrap pc-only">
<table class="data-table hs-yon-tablo hs-sahipsiz-tablo">
<thead><tr>
    <th style="width:30px"><span class="sr-only">Seç</span></th>
    <th>Tarih</th>
    <th>Tür / Kategori</th>
    <th>Kişi / Açıklama</th>
    <th class="num">Tutar</th>
    <th>Durum</th>
    <th>Fiş</th>
    <th>Sahip ata</th>
</tr></thead>
<tbody>
<?php foreach ($rows as $r): $rid = (int)$r['id']; ?>
<tr>
    <td><input type="checkbox" class="hs-sec" name="ids[]" value="<?= $rid ?>" form="hsTopluAta" aria-label="Kayıt #<?= $rid ?> seç"></td>
    <td class="muted"><?= h(date('d.m.Y', strtotime((string)$r['transaction_date']))) ?></td>
    <td><span class="hesap-type-badge" style="background:<?= hesap_type_color($r['type']) ?>"><?= h(hesap_type_label($r['type'])) ?></span>
        <?= h($r['category']) ?></td>
    <td><?= h($r['person_company']) ?>
        <?php if ($r['description'] !== '' || $r['document_no'] !== ''): ?>
        <div class="muted hs-sahipsiz-acik"><?= h($r['description']) ?><?php if ($r['document_no'] !== ''): ?> · <?= h($r['document_no']) ?><?php endif; ?></div>
        <?php endif; ?></td>
    <td class="num strong <?= $r['type'] === 'gelir' ? 'text-green' : 'text-red' ?>"><?= fmt_para((float)$r['amount'], (string)$r['currency']) ?></td>
    <td><?= hesap_status_badge($r['status'] ?? null, true) ?></td>
    <td><?php if (isset($fisler[$rid])): ?><a href="hesap_dosya.php?f=<?= urlencode($fisler[$rid]) ?>" target="_blank" rel="noopener">Fiş</a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
    <td>
        <form method="post" class="hs-ata-form" onsubmit="<?= h($onay_js) ?>">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="islem" value="ata">
            <input type="hidden" name="ids[]" value="<?= $rid ?>">
            <?= $filtre_gizli() ?>
            <?= $kisi_secenek('hedef_uid') ?>
            <button type="submit" class="btn btn-sm">Ata</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- Mobil: kartlar -->
<div class="hs-tx-list mobile-only">
<?php foreach ($rows as $r): $rid = (int)$r['id']; $gelir_mi = $r['type'] === 'gelir'; ?>
<div class="hs-tx hs-sahipsiz-kart">
    <div class="hs-sahipsiz-ust">
        <input type="checkbox" class="hs-sec" name="ids[]" value="<?= $rid ?>" form="hsTopluAta" aria-label="Kayıt #<?= $rid ?> seç">
        <span class="hs-tx-main">
            <span class="hs-tx-title"><?= h($r['category'] ?: hesap_type_label($r['type'])) ?></span>
            <span class="hs-tx-meta">
                <?= h(date('d.m.Y', strtotime((string)$r['transaction_date']))) ?>
                <?php if ($r['person_company'] !== ''): ?>· <?= h($r['person_company']) ?><?php endif; ?>
                <?php if (isset($fisler[$rid])): ?>· <a href="hesap_dosya.php?f=<?= urlencode($fisler[$rid]) ?>" target="_blank" rel="noopener">Fiş</a><?php endif; ?>
            </span>
            <span class="hs-tx-meta"><?= hesap_status_badge($r['status'] ?? null, true) ?></span>
        </span>
        <span class="hs-tx-amount <?= $gelir_mi ? 'pos' : 'neg' ?>"><?= ($gelir_mi ? '+' : '−') . fmt_para((float)$r['amount'], (string)$r['currency']) ?></span>
    </div>
    <?php if ($r['description'] !== ''): ?><div class="muted hs-sahipsiz-acik"><?= h($r['description']) ?></div><?php endif; ?>
    <form method="post" class="hs-ata-form" onsubmit="<?= h($onay_js) ?>">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="islem" value="ata">
        <input type="hidden" name="ids[]" value="<?= $rid ?>">
        <?= $filtre_gizli() ?>
        <?= $kisi_secenek('hedef_uid') ?>
        <button type="submit" class="btn btn-sm">Ata</button>
    </form>
</div>
<?php endforeach; ?>
</div>

<?php if ($toplam_sayfa > 1):
    $pg = fn(int $p) => 'hesap_sahipsiz.php?' . http_build_query(array_filter($f) + ['sayfa' => $p]); ?>
<div class="hs-sayfalama">
    <?php if ($sayfa > 1): ?><a href="<?= h($pg($sayfa - 1)) ?>" class="btn btn-sm">‹ Önceki</a><?php endif; ?>
    <span class="muted"><?= $sayfa ?> / <?= $toplam_sayfa ?></span>
    <?php if ($sayfa < $toplam_sayfa): ?><a href="<?= h($pg($sayfa + 1)) ?>" class="btn btn-sm">Sonraki ›</a><?php endif; ?>
</div>
<?php endif; ?>

<?php endif; /* rows */ ?>
<?php endif; /* ozet_toplam */ ?>

</div><!-- /.hs -->
<?php hesap_scripts(); ?>
<?php render_footer(); ?>
