<?php
// =========================================================
// servis_hesabi.php — Servis Hesabı (servisçi cari; v532)
//
// Servis çavuş hakedişinden AYRI hesaplaşılır. Bu sayfa:
//  • seçilen aralıkta günlük/çavuş bazlı Büyük/Küçük adet × fiyat dökümü (SALT OKUNUR, canlı),
//  • servisçiye ödeme kaydı (DEĞİŞMEZ; yalnız gerekçeli iptal) ve güncel bakiye.
// Servis GİRİŞİ hâlâ çavuşun Mesai Detayı › 🚌 Servis Ücreti'nde yapılır. Hesap mantığı
// config/pdks_servis_hesap.php'dedir; burada SQL/hesap YAZMA.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_servis_hesap.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/pdks_cari.php';
$auth_user = require_login();
require_pdks_cari('accounts');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);

$depo = function_exists('active_depot') ? (active_depot() ?? '') : '';
$depoF = $depo !== '' ? $depo : null;
$odemeHazir = pdks_servis_odeme_hazir($pdo);
$servisHazir = pdks_servis_sema_hazir($pdo);

// ── POST: ödeme kaydet / iptal ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_cari('payments');
    $action = trim((string)($_POST['action'] ?? ''));
    $uid = (int)($auth_user['id'] ?? 0);
    if ($action === 'odeme_kaydet') {
        $s = pdks_servis_odeme_ekle(
            trim((string)($_POST['payment_date'] ?? '')), (string)($_POST['amount'] ?? ''),
            (string)($_POST['currency'] ?? 'TRY'), (string)($_POST['payment_method'] ?? 'OTHER'),
            (string)($_POST['reference_no'] ?? ''), (string)($_POST['description'] ?? ''), $depoF, $uid, $pdo
        );
        set_flash($s['ok'] ? 'success' : 'error', $s['ok'] ? 'Servis ödemesi kaydedildi.' : (string)$s['hata']);
    } elseif ($action === 'iptal') {
        $s = pdks_servis_odeme_iptal((int)($_POST['payment_id'] ?? 0), (string)($_POST['reason'] ?? ''), $uid, $pdo);
        set_flash($s['ok'] ? 'success' : 'error', $s['ok'] ? 'Ödeme iptal edildi.' : (string)$s['hata']);
    }
    header('Location: servis_hesabi.php?' . http_build_query(array_filter([
        'ay' => $_POST['ay'] ?? '', 'cavus' => $_POST['cavus'] ?? '',
    ], static fn($v) => $v !== '' && $v !== null)));
    exit;
}

// ── Filtre ──
$ay = trim((string)($_GET['ay'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ay)) $ay = date('Y-m');
$bas = $ay . '-01';
$bit = date('Y-m-t', strtotime($bas));
$cavusId = filter_var($_GET['cavus'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$cavusId = $cavusId === false ? null : (int)$cavusId;

$cavuslar = $pdo->query('SELECT id, name FROM foremen ORDER BY is_active DESC, name ASC')->fetchAll();
$turler = pdks_servis_turleri();
$satirlar = $servisHazir ? pdks_servis_hesap_satirlari($bas, $bit, $depoF, $cavusId, $pdo) : [];
$ozet = pdks_servis_hesap_ozet($satirlar);
$odemeler = pdks_servis_odeme_listesi($bas, $bit, $depoF, $pdo);
$bakiye = $servisHazir ? pdks_servis_bakiye($depoF, $pdo) : [];
$tl = static fn(int $k): string => number_format($k / 100, 2, ',', '.');
$tarihTr = static fn(string $d): string => date('d.m.Y', strtotime($d));
$yontemAd = ['BANK' => 'Banka / Havale', 'CASH' => 'Nakit', 'OTHER' => 'Diğer'];
$yazdir = isset($_GET['yazdir']);
$kayitYetkisi = pdks_cari_can('payments');

render_header('Servis Hesabı');
render_flash();
?>
<style>
@media print { .topbar, .sidebar, .bottomnav, .no-print, .page-head-actions { display:none !important; } .card { box-shadow:none; } }
.sv-h-kutular { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin:0 0 16px; }
.sv-h-kutu { border:1px solid var(--border,#ddd); border-radius:10px; padding:10px 12px; }
.sv-h-kutu b { display:block; font-size:1.15rem; }
.sv-h-sayi { text-align:right; white-space:nowrap; }
.sv-h-soluk { color:#888; font-size:.85em; }
</style>

<div class="page-head">
    <h1>🚌 Servis Hesabı</h1>
    <div class="page-head-actions no-print">
        <button type="button" class="btn btn-ghost" onclick="window.print()">🖨️ Yazdır</button>
        <a href="personel_takip.php" class="btn btn-geri btn-geri-ptak">← Personel Takibi</a>
    </div>
</div>

<p class="muted" style="margin-top:0">Servisçi ile ayrı hesaplaşılır; servis tutarı <b>çavuş hakedişine/bakiyesine yazılmaz</b>.
   Servis girişi çavuşun Mesai Detayı › 🚌 Servis Ücreti'nde yapılır; fiyat Çavuş Ücretleri › Servis Ücreti'ndedir.</p>

<?php if (!$servisHazir): ?>
<div class="card" style="padding:14px 16px">Servis tabloları kurulmamış (migrate.php › Servis Ücreti).</div>
<?php else: ?>

<form method="get" class="pdks-filter-bar no-print" data-oto-filtre>
    <label><span class="form-label">Ay</span><input type="month" name="ay" value="<?= h($ay) ?>"></label>
    <label><span class="form-label">Çavuş</span>
        <select name="cavus"><option value="">Tümü</option>
        <?php foreach ($cavuslar as $c): ?>
            <option value="<?= (int)$c['id'] ?>"<?= $cavusId === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?></select></label>
    <button class="btn btn-primary" type="submit">Göster</button>
</form>

<h2 style="font-size:1rem">Güncel Bakiye (tüm zamanlar<?= $depo !== '' ? ' · ' . h($depo) : '' ?>)</h2>
<?php if (!$bakiye): ?><p class="muted">Henüz servis kaydı yok.</p><?php endif; ?>
<div class="sv-h-kutular">
<?php foreach ($bakiye as $cur => $b): ?>
    <div class="sv-h-kutu"><span class="sv-h-soluk">Servis tutarı (<?= h($cur) ?>)</span><b><?= $tl($b['servis_kurus']) ?></b></div>
    <div class="sv-h-kutu"><span class="sv-h-soluk">Ödenen</span><b><?= $tl($b['odeme_kurus']) ?></b></div>
    <div class="sv-h-kutu"><span class="sv-h-soluk"><?= $b['bakiye_kurus'] >= 0 ? 'Servisçiye borç' : 'Avans (fazla ödeme)' ?></span><b><?= $tl(abs($b['bakiye_kurus'])) ?></b></div>
<?php endforeach; ?>
</div>

<h2 style="font-size:1rem"><?= h($tarihTr($bas)) ?> – <?= h($tarihTr($bit)) ?> · Günlük Döküm</h2>
<div class="sv-h-kutular">
    <?php foreach ($ozet as $cur => $o): ?>
    <div class="sv-h-kutu"><span class="sv-h-soluk">Büyük / Küçük adet</span><b><?= (int)$o['buyuk'] ?> / <?= (int)$o['kucuk'] ?></b></div>
    <div class="sv-h-kutu"><span class="sv-h-soluk">Dönem servis tutarı (<?= h($cur) ?>)</span><b><?= $tl($o['tutar_kurus']) ?></b></div>
    <?php endforeach; ?>
</div>
<div class="table-wrap">
<table class="table">
    <thead><tr>
        <th>Tarih</th><th>Çavuş</th>
        <th class="sv-h-sayi">Büyük</th><th class="sv-h-sayi">Küçük</th>
        <th class="sv-h-sayi">Tutar</th><th>Not</th>
    </tr></thead>
    <tbody>
    <?php if (!$satirlar): ?><tr><td colspan="6" class="muted">Bu dönemde servis kaydı yok.</td></tr><?php endif; ?>
    <?php foreach ($satirlar as $s): ?>
        <tr>
            <td><a href="gunluk_isci_puantaj_detay.php?id=<?= (int)$s['session_id'] ?>"><?= h($tarihTr($s['tarih'])) ?></a></td>
            <td><?= h($s['cavus']) ?></td>
            <?php foreach (array_keys($turler) as $t): ?>
            <td class="sv-h-sayi"><?= (int)$s['adet'][$t] ?>
                <?php if ($s['adet'][$t] > 0 && $s['birim'][$t] !== null): ?><span class="sv-h-soluk">× <?= $tl($s['birim'][$t]) ?></span><?php endif; ?></td>
            <?php endforeach; ?>
            <td class="sv-h-sayi"><?= $tl($s['tutar_kurus']) ?> <span class="sv-h-soluk"><?= h($s['para']) ?></span></td>
            <td>
                <?php if ($s['fiyatsiz']): ?><span style="color:#c0392b">Fiyat yok: <?= h(implode(', ', $s['fiyatsiz'])) ?></span><?php endif; ?>
                <?php if ($s['hakedis_eski']): ?><span class="sv-h-soluk">Çavuş hakedişinde ödendi (bakiyeye katılmaz)</span><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<h2 style="font-size:1rem;margin-top:24px">Servisçi Ödemeleri</h2>
<?php if (!$odemeHazir): ?>
<div class="card" style="padding:14px 16px">Ödeme defteri henüz kurulmamış. Bir yönetici migrate.php › «Servis Ödemeleri» kartından tabloyu oluşturmalı.</div>
<?php else: ?>
<?php if ($kayitYetkisi): ?>
<div class="card no-print" style="padding:14px 16px;margin-bottom:14px">
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="odeme_kaydet">
        <input type="hidden" name="ay" value="<?= h($ay) ?>">
        <input type="hidden" name="cavus" value="<?= $cavusId ? (int)$cavusId : '' ?>">
        <div class="pdks-form-grid">
            <label><span class="form-label">Ödeme Tarihi *</span><input type="date" name="payment_date" required value="<?= h(date('Y-m-d')) ?>" max="<?= h(date('Y-m-d')) ?>"></label>
            <label><span class="form-label">Tutar *</span><input type="text" name="amount" required inputmode="decimal" placeholder="ör. 20000 veya 20000,50"></label>
            <label><span class="form-label">Para Birimi</span><input type="text" name="currency" maxlength="10" value="TRY"></label>
            <label><span class="form-label">Yöntem</span>
                <select name="payment_method"><option value="BANK">Banka / Havale</option><option value="CASH">Nakit</option><option value="OTHER" selected>Diğer</option></select></label>
            <label><span class="form-label">Referans No</span><input type="text" name="reference_no" maxlength="100"></label>
            <label class="span-2"><span class="form-label">Açıklama</span><input type="text" name="description" maxlength="500"></label>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:12px">ÖDEME KAYDET</button>
    </form>
</div>
<?php endif; ?>
<div class="table-wrap">
<table class="table">
    <thead><tr><th>Tarih</th><th>Yöntem</th><th>Referans / Açıklama</th><th class="sv-h-sayi">Tutar</th><th>Durum</th><th class="no-print"></th></tr></thead>
    <tbody>
    <?php if (!$odemeler): ?><tr><td colspan="6" class="muted">Bu dönemde ödeme yok.</td></tr><?php endif; ?>
    <?php foreach ($odemeler as $o): $iptal = (string)$o['status'] === 'cancelled'; ?>
        <tr<?= $iptal ? ' style="opacity:.55;text-decoration:line-through"' : '' ?>>
            <td><?= h($tarihTr((string)$o['payment_date'])) ?></td>
            <td><?= h($yontemAd[(string)$o['payment_method']] ?? (string)$o['payment_method']) ?></td>
            <td><?= h(trim((string)$o['reference_no'] . ' ' . (string)$o['description'])) ?></td>
            <td class="sv-h-sayi"><?= h(number_format((float)$o['amount'], 2, ',', '.')) ?> <span class="sv-h-soluk"><?= h($o['currency']) ?></span></td>
            <td><?= $iptal ? 'İptal: ' . h((string)$o['cancellation_reason']) : 'Geçerli' ?></td>
            <td class="no-print">
            <?php if (!$iptal && $kayitYetkisi): ?>
                <details><summary class="btn btn-sm">İptal Et</summary>
                <form method="post" style="margin-top:8px">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="iptal">
                    <input type="hidden" name="payment_id" value="<?= (int)$o['id'] ?>">
                    <input type="hidden" name="ay" value="<?= h($ay) ?>">
                    <input type="hidden" name="cavus" value="<?= $cavusId ? (int)$cavusId : '' ?>">
                    <input type="text" name="reason" required maxlength="500" placeholder="İptal gerekçesi *">
                    <button type="submit" class="btn btn-sm" style="margin-top:6px">Onayla</button>
                </form></details>
            <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
<?php render_footer(); ?>
