<?php
// =========================================================
// servis_odemeleri.php — Servisçi Ödemeleri (v533)
//
// Servis Hesabı'ndan AYRI sayfa: servisçiye ödeme kaydı (DEĞİŞMEZ; yalnız gerekçeli iptal),
// ödeme listesi ve güncel bakiye. Mantık config/pdks_servis_hesap.php'dedir; burada SQL YAZMA.
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
    header('Location: servis_odemeleri.php?' . http_build_query(array_filter([
        'ay' => $_POST['ay'] ?? '',
    ], static fn($v) => $v !== '' && $v !== null)));
    exit;
}

// ── Filtre ──
$ay = trim((string)($_GET['ay'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ay)) $ay = date('Y-m');
$bas = $ay . '-01';
$bit = date('Y-m-t', strtotime($bas));
$odemeler = pdks_servis_odeme_listesi($bas, $bit, $depoF, $pdo);
$bakiye = pdks_servis_sema_hazir($pdo) ? pdks_servis_bakiye($depoF, $pdo) : [];
$tl = static fn(int $k): string => number_format($k / 100, 2, ',', '.');
$tarihTr = static fn(string $d): string => date('d.m.Y', strtotime($d));
$yontemAd = ['BANK' => 'Banka / Havale', 'CASH' => 'Nakit', 'OTHER' => 'Diğer'];
$kayitYetkisi = pdks_cari_can('payments');

render_header('Servisçi Ödemeleri');
render_flash();
$base = function_exists('base_url') ? base_url() : '';
echo '<link rel="stylesheet" href="' . h($base) . 'assets/pdks.css?v=' . (int)@filemtime(__DIR__ . '/assets/pdks.css') . '">';
?>
<style>
@media print { .topbar, .sidebar, .bottomnav, .no-print, .page-head-actions { display:none !important; } }
.sv-h-kutular { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin:0 0 16px; }
.sv-h-kutu { border:1px solid var(--border,#ddd); border-radius:10px; padding:10px 12px; }
.sv-h-kutu b { display:block; font-size:1.15rem; }
.sv-h-sayi { text-align:right; white-space:nowrap; }
.sv-h-soluk { color:#888; font-size:.85em; }
.sv-h-baslik { font-size:1rem; margin:20px 0 8px; }
.sv-filtre { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; margin:0 0 18px; }
.sv-filtre label { display:flex; flex-direction:column; gap:4px; margin:0; }
.sv-filtre input[type="month"] { height:42px; padding:0 12px; border:1px solid var(--border,#d5dbe3); border-radius:8px; background:#fff; font:inherit; box-sizing:border-box; }
.sv-filtre .btn { height:42px; }
.data-table th, .data-table td { padding:8px 12px; }
</style>

<div class="page-head">
    <h1>💳 Servisçi Ödemeleri</h1>
    <div class="page-head-actions no-print">
        <a href="servis_hesabi.php?ay=<?= h($ay) ?>" class="btn btn-geri btn-geri-ptak">← Servis Hesabı</a>
    </div>
</div>

<h2 class="sv-h-baslik">Güncel Bakiye (tüm zamanlar<?= $depo !== '' ? ' · ' . h($depo) : '' ?>)</h2>
<?php if (!$bakiye): ?><p class="muted">Henüz servis kaydı yok.</p><?php endif; ?>
<div class="sv-h-kutular">
<?php foreach ($bakiye as $cur => $b): ?>
    <div class="sv-h-kutu"><span class="sv-h-soluk">Servis tutarı (<?= h($cur) ?>)</span><b><?= $tl($b['servis_kurus']) ?></b></div>
    <div class="sv-h-kutu"><span class="sv-h-soluk">Ödenen</span><b><?= $tl($b['odeme_kurus']) ?></b></div>
    <div class="sv-h-kutu"><span class="sv-h-soluk"><?= $b['bakiye_kurus'] >= 0 ? 'Servisçiye borç' : 'Avans (fazla ödeme)' ?></span><b><?= $tl(abs($b['bakiye_kurus'])) ?></b></div>
<?php endforeach; ?>
</div>

<form method="get" class="sv-filtre no-print" data-oto-filtre>
    <label><span class="form-label">Ay</span><input type="month" name="ay" value="<?= h($ay) ?>"></label>
    <button class="btn btn-primary" type="submit">Göster</button>
</form>

<h2 class="sv-h-baslik">Ödemeler · <?= h($tarihTr($bas)) ?> – <?= h($tarihTr($bit)) ?></h2>
<?php if (!$odemeHazir): ?>
<div class="card" style="padding:14px 16px">Ödeme defteri henüz kurulmamış. Bir yönetici migrate.php › «Servis Ödemeleri» kartından tabloyu oluşturmalı.</div>
<?php else: ?>
<?php if ($kayitYetkisi): ?>
<div class="card no-print" style="padding:14px 16px;margin-bottom:14px">
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="odeme_kaydet">
        <input type="hidden" name="ay" value="<?= h($ay) ?>">
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
<table class="data-table">
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
<?php render_footer(); ?>
