<?php
// HKS WSDL Tip İnceleme — BildirimKayitIstek gerçek alan adlarını WSDL'den çıkarır.
// SADECE introspeksiyon (yalnız okuma). Hiçbir koşulda BildirimServisBildirimKaydet
// operasyonu çağrılmaz; canlı gönderim açılmaz.
declare(strict_types=1);
require_once __DIR__ . '/includes/hks_bootstrap.php';
$auth_user = require_login();
if (!is_admin() && !(function_exists('can') && can('hks.settings'))) {
    http_response_code(403); die('Erişim yetkiniz yok.');
}

$repo   = new HksRepository(db());
$client = new HksClient($repo);

// Hangi servis (varsayılan bildirim) ve ortam.
$service = in_array($_GET['service'] ?? 'bildirim', ['bildirim', 'genel', 'urun'], true)
    ? ($_GET['service'] ?? 'bildirim') : 'bildirim';
$env = $client->getEnvironment();

// İlgilendiğimiz tip anahtar kelimeleri.
$type_keywords = [
    'BildirimKayit', 'BildirimKaydet', 'ListOf_BildirimKayit',
    'BaseRequestMessageOf_ListOf_BildirimKayitIstek', 'ArrayOfBildirim', 'BildirimCevap',
];

$functions = null; $types = null; $matched_types = []; $parsed = []; $err = null;

// ── POST: doğrulanmış alanları cache'e kaydet ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    if (($_POST['action'] ?? '') === 'store_fields') {
        $type_name = trim($_POST['type_name'] ?? '');
        $fields_json = $_POST['fields_json'] ?? '';
        $fields = json_decode((string)$fields_json, true);
        if ($type_name !== '' && is_array($fields) && $fields) {
            hks_wsdl_store_confirmed_fields($type_name, $fields);
            audit_log_event('hks_wsdl_fields_confirmed', 'hks_reference_cache', null, null,
                ['type' => $type_name, 'field_count' => count($fields)]);
            set_flash('success', $type_name . ' için ' . count($fields) . ' alan adı doğrulandı ve kaydedildi.');
        } else {
            set_flash('error', 'Kaydedilecek geçerli alan listesi bulunamadı.');
        }
        header('Location: wsdl_tipleri.php?service=' . $service); exit;
    }
}

// ── Introspeksiyon çalıştır (yalnız okuma) ──
$run = isset($_GET['run']) && $_GET['run'] === '1';
if ($run) {
    $fres = $client->getServiceFunctions($service);
    $tres = $client->getServiceTypes($service);
    if (empty($fres['ok'])) { $err = $fres['message'] ?? 'WSDL methodları okunamadı.'; }
    $functions = $fres['functions'] ?? [];
    if (!empty($tres['ok'])) {
        $types = $tres['types'] ?? [];
        foreach ($types as $t) {
            foreach ($type_keywords as $kw) {
                if (stripos($t, $kw) !== false) { $matched_types[] = $t; break; }
            }
        }
        // BildirimKayitIstek ve ilişkili tipleri parse et
        foreach (['BildirimKayitIstek', 'BildirimKayitCevap', 'BildirimKaydetCevap',
                  'BaseRequestMessageOf_ListOf_BildirimKayitIstek', 'ArrayOfBildirimKayitIstek'] as $tn) {
            $block = hks_find_wsdl_type($types, $tn);
            if ($block !== null) $parsed[$tn] = hks_parse_wsdl_struct_fields($block);
        }
    } elseif ($err === null) {
        $err = $tres['message'] ?? 'WSDL tipleri okunamadı.';
    }
}

// Mevcut mapping ile karşılaştırma için provizyon alan haritası
$field_map = hks_payload_field_map();
$confirmed_set = hks_wsdl_all_confirmed_field_names();

render_header('HKS WSDL Tipleri');
render_flash();
?>
<div class="hks-page" style="max-width:1080px;margin:0 auto">
<?php $hks_active_tab = 'wsdl_tipleri.php'; @include __DIR__ . '/views/_tabs.php'; ?>

<div class="page-head" style="margin-top:16px">
    <div>
        <h1>🧩 WSDL Tipleri</h1>
        <p class="muted">BildirimKayitIstek gerçek alan adlarını WSDL'den çıkarır — yalnız okuma, gönderim yapılmaz.</p>
    </div>
    <div class="page-head-actions">
        <a href="teknik.php" class="btn btn-ghost">← Teknik Panel</a>
        <a href="wsdl_tipleri.php?service=<?= hks_h($service) ?>&run=1" class="btn btn-primary">🔍 WSDL Oku</a>
    </div>
</div>

<div class="card" style="padding:12px 16px;margin-bottom:14px;display:flex;gap:18px;flex-wrap:wrap;font-size:.9rem">
    <span>Ortam: <strong><?= $env === 'live' ? '🔴 Canlı' : '🟡 Test' ?></strong></span>
    <span>Servis: <strong><?= hks_h($service) ?></strong></span>
    <span>Alan doğrulandı mı: <strong><?= hks_payload_fields_confirmed() ? '✅ Evet' : '❌ Hayır (gönderim kilitli)' ?></strong></span>
    <span style="margin-left:auto">
        Servis seç:
        <a href="wsdl_tipleri.php?service=bildirim&run=1">bildirim</a> ·
        <a href="wsdl_tipleri.php?service=genel&run=1">genel</a> ·
        <a href="wsdl_tipleri.php?service=urun&run=1">urun</a>
    </span>
</div>

<div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:11px 14px;margin-bottom:14px;font-size:.88rem;color:#1e40af">
    ℹ️ Bu ekran yalnızca <strong>__getFunctions() / __getTypes()</strong> okur. BildirimServisBildirimKaydet
    <strong>çağrılmaz</strong>, canlı gönderim açılmaz. Alanları "Doğrula ve Kaydet" ile kaydederseniz
    payload mapping bu gerçek alan adlarına göre işaretlenir.
</div>

<?php if (!hks_check_soap()): ?>
<div style="background:#fef2f2;border:1px solid var(--danger);border-radius:8px;padding:12px 16px;margin-bottom:12px">❌ PHP SOAP extension aktif değil — introspeksiyon yapılamaz.</div>
<?php elseif (!$client->hasSettings()): ?>
<div style="background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;padding:12px 16px;margin-bottom:12px">⚠️ HKS ayarları yapılandırılmamış. <a href="ayarlar.php">Ayarları girin.</a></div>
<?php endif; ?>

<?php if ($err): ?>
<div style="background:#fef2f2;border:1px solid var(--danger);border-radius:8px;padding:12px 16px;margin-bottom:12px">
    ❌ <?= hks_h($err) ?>
    <div style="font-size:.82rem;color:var(--muted);margin-top:6px">
        WSDL'e bu sunucudan erişilemiyor olabilir. Ayarlar → WSDL URL ve ağ erişimini kontrol edin.
    </div>
</div>
<?php endif; ?>

<?php if (!$run): ?>
<div class="card" style="padding:24px;text-align:center;color:var(--muted)">
    WSDL tiplerini görmek için <a href="wsdl_tipleri.php?service=<?= hks_h($service) ?>&run=1">🔍 WSDL Oku</a> butonuna basın.
</div>
<?php else: ?>

<!-- Bölüm 1 — Methodlar -->
<h3>1) Methodlar (__getFunctions)</h3>
<?php if (!empty($functions)): ?>
<div class="table-wrap"><table style="width:100%;border-collapse:collapse;font-size:.82rem">
    <tbody>
    <?php foreach ($functions as $fn): ?>
        <tr><td style="padding:5px 8px;border-bottom:1px solid var(--border);font-family:monospace"><?= hks_h($fn) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php else: ?>
<p class="muted">Method listesi alınamadı.</p>
<?php endif; ?>

<!-- Bölüm 2 — BildirimKaydet ile ilgili tipler -->
<h3 style="margin-top:20px">2) BildirimKaydet ile İlgili Tipler</h3>
<?php if (!empty($matched_types)): ?>
<?php foreach ($matched_types as $mt): ?>
<pre style="background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:10px;overflow:auto;font-size:.78rem;margin:0 0 8px"><?= hks_h($mt) ?></pre>
<?php endforeach; ?>
<?php else: ?>
<p class="muted">Anahtar kelimelere uyan tip bulunamadı. Aşağıdaki ham dump'ı inceleyin.</p>
<?php endif; ?>

<!-- Bölüm 3 — Parse edilmiş alanlar + mapping karşılaştırma -->
<h3 style="margin-top:20px">3) BildirimKayitIstek Alanları &amp; Mapping</h3>
<?php
$bki = $parsed['BildirimKayitIstek'] ?? null;
if ($bki && !empty($bki['fields'])):
    // WSDL'de geçen alan adlarını küçük harfli set yap
    $wsdl_names = [];
    foreach ($bki['fields'] as $f) $wsdl_names[mb_strtolower($f['name'])] = $f['type'];
    // local karşılık tahmini (provizyon HKS adımız WSDL'de var mı?)
?>
<div class="table-wrap"><table style="width:100%;border-collapse:collapse;font-size:.85rem">
    <thead><tr style="background:var(--bg)">
        <th style="padding:6px 8px;text-align:left">WSDL Alan</th>
        <th style="padding:6px 8px;text-align:left">Tip</th>
        <th style="padding:6px 8px;text-align:left">Bizim local karşılık</th>
        <th style="padding:6px 8px;text-align:left">Durum</th>
    </tr></thead>
    <tbody>
    <?php foreach ($bki['fields'] as $f):
        // Provizyon haritada bu HKS adına denk gelen local alanı bul
        $local_match = '';
        foreach ($field_map as [$lk, $hk, $rq, $rf, $hd]) {
            if (mb_strtolower($hk) === mb_strtolower($f['name'])) { $local_match = $lk; break; }
        }
    ?>
        <tr>
            <td style="padding:5px 8px;border-bottom:1px solid var(--border);font-family:monospace"><?= hks_h($f['name']) ?></td>
            <td style="padding:5px 8px;border-bottom:1px solid var(--border);color:var(--muted)"><?= hks_h($f['type']) ?></td>
            <td style="padding:5px 8px;border-bottom:1px solid var(--border)"><?= $local_match !== '' ? hks_h($local_match) : '<span class="muted">— eşleşme yok —</span>' ?></td>
            <td style="padding:5px 8px;border-bottom:1px solid var(--border)"><?= $local_match !== '' ? '<span style="color:#065f46">✓ haritada var</span>' : '<span style="color:#854d0e">⚠ haritada yok</span>' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>

<!-- Provizyon haritamızın WSDL karşılığı var mı? -->
<h4 style="margin-top:16px">Provizyon Mapping → WSDL Doğrulama</h4>
<div class="table-wrap"><table style="width:100%;border-collapse:collapse;font-size:.85rem">
    <thead><tr style="background:var(--bg)">
        <th style="padding:6px 8px;text-align:left">Local</th>
        <th style="padding:6px 8px;text-align:left">Provizyon HKS Alanı</th>
        <th style="padding:6px 8px;text-align:left">Zorunlu</th>
        <th style="padding:6px 8px;text-align:left">WSDL Durumu</th>
    </tr></thead>
    <tbody>
    <?php foreach ($field_map as [$lk, $hk, $rq, $rf, $hd]):
        $in_wsdl = isset($wsdl_names[mb_strtolower($hk)]);
    ?>
        <tr>
            <td style="padding:5px 8px;border-bottom:1px solid var(--border)"><?= hks_h($lk) ?></td>
            <td style="padding:5px 8px;border-bottom:1px solid var(--border);font-family:monospace"><?= hks_h($hk) ?></td>
            <td style="padding:5px 8px;border-bottom:1px solid var(--border)"><?= $rq ? 'Evet' : '—' ?></td>
            <td style="padding:5px 8px;border-bottom:1px solid var(--border)">
                <?= $in_wsdl ? '<span style="color:#065f46">✓ WSDL doğrulandı</span>' : '<span style="color:#991b1b">✗ WSDL\'de bulunamadı</span>' ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>

<form method="post" style="margin-top:14px">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="store_fields">
    <input type="hidden" name="type_name" value="BildirimKayitIstek">
    <input type="hidden" name="fields_json" value="<?= hks_h(json_encode($bki['fields'], JSON_UNESCAPED_UNICODE)) ?>">
    <button type="submit" class="btn btn-primary">💾 Bu Alanları Doğrula ve Kaydet</button>
    <span style="font-size:.82rem;color:var(--muted);margin-left:8px">Kaydedince payload mapping bu gerçek alan adlarına göre işaretlenir. Gönderim yine de live_send kapalı olduğu için yapılmaz.</span>
</form>

<?php else: ?>
<p class="muted">BildirimKayitIstek tipi parse edilemedi. Ham __getTypes dump'ını aşağıdaki accordion'dan inceleyin; tip adı farklı olabilir.</p>
<?php endif; ?>

<!-- Bölüm 4 — Ham __getTypes dump (accordion) -->
<details class="card" style="padding:0;margin-top:20px">
    <summary style="padding:12px 16px;cursor:pointer;font-weight:600;color:var(--muted)">🔧 Ham __getTypes() dump (<?= is_array($types) ? count($types) : 0 ?> tip)</summary>
    <div style="padding:0 16px 14px">
        <?php if (!empty($types)): ?>
        <pre style="background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:10px;overflow:auto;font-size:.74rem;margin:10px 0 0;max-height:520px"><?php
            foreach ($types as $t) { echo hks_h($t) . "\n\n"; }
        ?></pre>
        <?php else: ?>
        <p class="muted" style="padding-top:10px">Tip dökümü yok.</p>
        <?php endif; ?>
    </div>
</details>

<?php endif; ?>
</div>
<?php render_footer(); ?>
