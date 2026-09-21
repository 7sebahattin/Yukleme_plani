<?php
// =========================================================
// cavus_odeme.php — Çavuş Ödeme Kaydı (Günlük İşçi, Faz 5)
//
// Ödeme DEĞİŞMEZ (kullanıcının açık talimatı): kaydedildikten sonra tutar/
// çavuş/tarih/para birimi SESSİZCE düzenlenemez — yalnız kontrollü İPTAL
// (gerekçeli, satır SİLİNMEZ). Aşım (mevcut borcu aşan ödeme) SESSİZCE
// ENGELLENMEZ ama AÇIK ONAY ister — Faz 4'ün eksik-çıkış onay deseniyle
// AYNI ilke: gerekçesiz/onaysız istek REDDEDİLİR, işaretli checkbox'la
// AYNI form yeniden gönderilince kabul edilir.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_cari.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_cari('payments');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_hakedis_sayfa_kapisi($pdo);
pdks_cari_sayfa_kapisi($pdo);

$cavusId = filter_var($_GET['cavus'] ?? '', FILTER_VALIDATE_INT) ?: null;
$errors = [];
$uyari = null;

// Ödeme Geçmişi tablosunda/kartlarında döviz ödemesinin TL karşılığını
// gösterir — tabloya/karta İKİNCİ BİR SÜTUN AÇMADAN (masaüstü tablo +
// mobil kart + ekstre ile AYNI desen, bkz. pdks_cari_ekstre()).
// function_exists() kapısı: scripts/pdks_cari_ui_smoke.php bu sayfayı
// TEK process içinde birden çok kez render eder (beyanlar.php'nin
// render_liste() ile AYNI ihtiyaç) — kapısız ikinci render'da
// "Cannot redeclare function" ile çökerdi.
if (!function_exists('pdks_odeme_tl_karsiligi_etiket')):
function pdks_odeme_tl_karsiligi_etiket(array $o): string
{
    if ((string)$o['currency'] === 'TRY') return '';
    $tl = $o['try_equivalent'] ?? null;
    if ($tl === null) return '';
    $kur = $o['exchange_rate'] ?? null;
    return '≈ ' . number_format((float)$tl, 2, ',', '.') . ' TRY'
         . ($kur !== null ? ' (kur: ' . number_format((float)$kur, 4, ',', '.') . ')' : '');
}
endif;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_cari('payments');   // savunma derinliği
    $action = trim($_POST['action'] ?? '');

    if ($action === 'odeme_kaydet') {
        $cavusId = filter_var($_POST['foreman_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
        $tarih = trim((string)($_POST['payment_date'] ?? ''));
        $tutarHam = trim((string)($_POST['amount'] ?? ''));
        $currency = strtoupper(trim((string)($_POST['currency'] ?? 'TRY'))) ?: 'TRY';
        $kurHam = trim((string)($_POST['exchange_rate'] ?? ''));
        $yontem = trim((string)($_POST['payment_method'] ?? 'OTHER'));
        $referansNo = trim((string)($_POST['reference_no'] ?? ''));
        $aciklama = trim((string)($_POST['description'] ?? ''));
        $asimOnay = isset($_POST['asim_onay']);

        if (!$cavusId) {
            $errors[] = 'Çavuş seçilmedi.';
        } elseif (!array_key_exists($currency, pdks_para_birimleri())) {
            $errors[] = 'Geçersiz para birimi seçildi.';
        } elseif ($currency !== 'TRY' && pdks_cari_kur_mikro($kurHam) === null) {
            // Savunma derinliği — pdks_cari_odeme_ekle() de AYNI kontrolü
            // yapar (tek yetkili kapı orasıdır), burası yalnız kullanıcıya
            // aşım onayı ekranına düşmeden ERKEN ve net bir hata gösterir.
            $errors[] = 'Döviz ödemesi için geçerli bir kur girilmelidir. Örnek: 32,45';
        } else {
            // Aşım kontrolü — SUNUCU tarafında, istemci hesaplamasına GÜVENİLMEZ.
            // pdks_cari_odeme_onizleme() sayfa VE testler ARASINDA PAYLAŞILAN
            // TEK mantıktır (paralel/tekrarlanan bir hesap YOK).
            $kurusOnizleme = pdks_hakedis_girdi_kurus($tutarHam);
            $onizleme = $kurusOnizleme !== null && $kurusOnizleme > 0 ? pdks_cari_odeme_onizleme($cavusId, $kurusOnizleme, $currency, $pdo) : null;

            if ($onizleme !== null && $onizleme['asim'] && !$asimOnay) {
                $uyari = 'Bu ödeme mevcut borç bakiyesini aşmaktadır. İşlem sonrası çavuş avansı/fazla ödeme oluşacaktır. '
                        . '(Sonuç: ' . h(pdks_hakedis_kurus_tl(abs($onizleme['yeni_bakiye_kurus']))) . ' ' . h($currency) . ' avans.) '
                        . 'Devam etmek için aşağıdaki onay kutusunu işaretleyip tekrar kaydedin.';
            } else {
                $sonuc = pdks_cari_odeme_ekle($cavusId, $tarih, $tutarHam, $currency, $yontem, $referansNo, $aciklama, (int)$auth_user['id'], $pdo, $kurHam);
                if ($sonuc['ok']) {
                    header('Location: cavus_odeme.php?cavus=' . $cavusId . '&ok=' . urlencode('Ödeme kaydedildi.'));
                    exit;
                }
                $errors[] = $sonuc['hata'] ?? 'Kaydedilemedi.';
            }
        }
    } elseif ($action === 'iptal') {
        $paymentId = filter_var($_POST['payment_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
        $cavusId = filter_var($_POST['foreman_id'] ?? '', FILTER_VALIDATE_INT) ?: $cavusId;
        $sebep = trim((string)($_POST['sebep'] ?? ''));
        $sonuc = $paymentId ? pdks_cari_odeme_iptal($paymentId, $sebep, (int)$auth_user['id'], $pdo) : ['ok' => false, 'hata' => 'Ödeme bulunamadı.'];
        if ($sonuc['ok']) {
            header('Location: cavus_odeme.php?cavus=' . $cavusId . '&ok=' . urlencode('Ödeme iptal edildi.'));
            exit;
        }
        $errors[] = $sonuc['hata'] ?? 'İptal edilemedi.';
    }
}

$basari = '';
if (empty($errors) && isset($_GET['ok'])) $basari = trim($_GET['ok']);

$cavuslar = $pdo->query("SELECT id, code, name, is_active FROM foremen ORDER BY is_active DESC, name ASC")->fetchAll();
$seciliCavus = null; $bakiyeler = []; $odemeler = [];
if ($cavusId !== null) {
    foreach ($cavuslar as $c) { if ((int)$c['id'] === $cavusId) { $seciliCavus = $c; break; } }
    if ($seciliCavus) {
        $bakiyeler = pdks_cari_bakiye($cavusId, $pdo);
        $odemeler = pdks_cari_odeme_listesi($cavusId, $pdo);
    }
}

render_header('Çavuş Ödeme');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
if ($basari !== '') echo '<div class="flash flash-success">' . h($basari) . '</div>';
foreach ($errors as $e) echo '<div class="flash flash-error">' . h($e) . '</div>';
if ($uyari) echo '<div class="flash flash-error" style="border-color:var(--warn);background:var(--warn-soft);color:var(--warn)">⚠️ ' . h($uyari) . '</div>';
?>

<div class="page-head">
    <h1>💸 Çavuş Ödeme</h1>
    <div class="page-head-actions">
        <a href="cavus_cari.php" class="btn">📒 Çavuş Cari</a>
        <a href="cavus_hakedis.php" class="btn">🧾 Hakediş</a>
        <?php if ($seciliCavus): ?>
        <a href="cavus_odeme_yazdir.php?cavus=<?= (int)$seciliCavus['id'] ?>" class="btn btn-ghost">🖨️ Yazdır</a>
        <?php endif; ?>
    </div>
</div>

<form method="get" class="pdks-filter-bar">
    <select name="cavus" onchange="this.form.submit()">
        <option value="">— Çavuş seçin —</option>
        <?php foreach ($cavuslar as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $cavusId === (int)$c['id'] ? 'selected' : '' ?>>
            <?= h($c['name']) ?> (<?= h($c['code']) ?>)<?= $c['is_active'] ? '' : ' — pasif' ?>
        </option>
        <?php endforeach; ?>
    </select>
    <noscript><button type="submit" class="btn">Seç</button></noscript>
</form>

<?php if (!$seciliCavus): ?>
<div class="pdks-empty">
    <span class="pdks-empty-icon" aria-hidden="true">💸</span>
    <p>Ödeme kaydetmek için önce bir çavuş seçin.</p>
</div>
<?php else: ?>

<div class="pdks-kiosk-counters" style="margin:0 0 18px">
    <h3><?= h($seciliCavus['name']) ?> — Bakiye</h3>
    <?php if (empty($bakiyeler)): ?>
    <div class="pdks-kiosk-counter-row muted"><span>Bu çavuşun henüz KESİN hakedişi veya ödemesi yok.</span></div>
    <?php else: foreach ($bakiyeler as $cur => $b): ?>
    <div class="pdks-kiosk-counter-totals" style="margin-bottom:8px">
        <div class="pdks-kiosk-counter-box"><div class="lbl">Kesinleşmiş Hakediş (<?= h($cur) ?>)</div><div class="val"><?= h(number_format((float)$b['hakedis_toplam'], 2, ',', '.')) ?></div></div>
        <div class="pdks-kiosk-counter-box"><div class="lbl">Toplam Ödeme</div><div class="val"><?= h(number_format((float)$b['odeme_toplam'], 2, ',', '.')) ?></div></div>
        <div class="pdks-kiosk-counter-box <?= $b['durum'] === 'borc' ? 'eksik' : '' ?>"><div class="lbl"><?= h($b['durum_etiket']) ?></div><div class="val" id="pdksBakiye<?= h($cur) ?>" data-bakiye-kurus="<?= (int)$b['bakiye_kurus'] ?>"><?= h(number_format(abs((float)$b['bakiye']), 2, ',', '.')) ?></div></div>
    </div>
    <?php endforeach; endif; ?>
</div>

<div class="card" style="padding:18px 20px;margin-bottom:20px">
    <h2 style="margin-top:0;font-size:1rem">Yeni Ödeme</h2>
    <form method="post" id="pdksOdemeForm">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="odeme_kaydet">
        <input type="hidden" name="foreman_id" value="<?= (int)$seciliCavus['id'] ?>">
        <div class="pdks-form-grid">
            <label>
                <span class="form-label">Ödeme Tarihi *</span>
                <input type="date" name="payment_date" required value="<?= h(date('Y-m-d')) ?>">
            </label>
            <label>
                <span class="form-label">Tutar *</span>
                <input type="text" name="amount" id="pdksOdemeTutar" required inputmode="decimal" placeholder="ör. 20000 veya 20000,50">
            </label>
            <label>
                <span class="form-label">Para Birimi</span>
                <select name="currency" id="pdksOdemeParaBirimi">
                    <?php foreach (pdks_para_birimleri() as $pbKod => $pbAd): ?>
                    <option value="<?= h($pbKod) ?>" <?= $pbKod === 'TRY' ? 'selected' : '' ?>><?= h($pbAd) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label id="pdksOdemeKurWrap" hidden>
                <span class="form-label">Kur (1 birim = ? TRY) *</span>
                <input type="text" name="exchange_rate" id="pdksOdemeKur" inputmode="decimal" placeholder="ör. 32,45">
            </label>
            <label>
                <span class="form-label">Ödeme Yöntemi</span>
                <select name="payment_method">
                    <option value="BANK">Banka / Havale</option>
                    <option value="CASH">Nakit</option>
                    <option value="OTHER" selected>Diğer</option>
                </select>
            </label>
            <label>
                <span class="form-label">Referans No</span>
                <input type="text" name="reference_no" maxlength="100" placeholder="ör. HAVALE-123">
            </label>
            <label class="span-2">
                <span class="form-label">Açıklama</span>
                <textarea name="description" rows="2" maxlength="1000"></textarea>
            </label>
        </div>
        <p class="muted" id="pdksOdemeKurOnizleme" style="font-size:.9rem;margin-top:6px"></p>
        <p class="muted" id="pdksOdemeOnizleme" style="font-size:.9rem;margin-top:10px"></p>
        <?php if ($uyari): ?>
        <label style="display:flex;align-items:center;gap:8px;font-weight:400;margin-top:10px">
            <input type="checkbox" name="asim_onay" value="1" required>
            Aşım bakiyesini onaylıyorum, ödemeyi yine de kaydet.
        </label>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary" style="margin-top:14px">ÖDEME KAYDET</button>
    </form>
</div>

<script>
(function () {
    var tutarEl = document.getElementById('pdksOdemeTutar');
    var paraEl = document.getElementById('pdksOdemeParaBirimi');
    var kurWrapEl = document.getElementById('pdksOdemeKurWrap');
    var kurEl = document.getElementById('pdksOdemeKur');
    var onizlemeEl = document.getElementById('pdksOdemeOnizleme');
    var kurOnizlemeEl = document.getElementById('pdksOdemeKurOnizleme');

    function guncelle() {
        var para = (paraEl.value || 'TRY').trim();
        var bakiyeEl = document.getElementById('pdksBakiye' + para);
        if (!bakiyeEl) { onizlemeEl.textContent = ''; return; }
        var mevcutKurus = parseInt(bakiyeEl.getAttribute('data-bakiye-kurus') || '0', 10);
        var ham = (tutarEl.value || '').replace(',', '.');
        var tutar = parseFloat(ham);
        if (!ham || isNaN(tutar) || tutar <= 0) { onizlemeEl.textContent = ''; return; }
        var tutarKurus = Math.round(tutar * 100);
        var kalanKurus = mevcutKurus - tutarKurus;
        var kalanTl = (Math.abs(kalanKurus) / 100).toLocaleString('tr-TR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        if (kalanKurus >= 0) {
            onizlemeEl.textContent = 'Mevcut borç: ' + (mevcutKurus / 100).toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' ' + para + ' — Ödeme sonrası kalan: ' + kalanTl + ' ' + para;
        } else {
            onizlemeEl.textContent = 'Ödeme sonrası ÇAVUŞ AVANSI oluşacak: ' + kalanTl + ' ' + para;
        }
    }

    // Kur alanı yalnız döviz seçiliyken görünür + zorunlu — TRY'de kur
    // anlamsızdır (bkz. config/pdks_cari.php: pdks_cari_odeme_ekle()).
    // Sunucu AYNI kuralı tekrar doğrular (istemci yalnız kolaylık).
    function kurAlanGuncelle() {
        var dovizMi = paraEl.value !== 'TRY';
        kurWrapEl.hidden = !dovizMi;
        kurEl.required = dovizMi;
        if (!dovizMi) { kurEl.value = ''; kurOnizlemeEl.textContent = ''; }
        kurTlOnizleGuncelle();
    }

    function kurTlOnizleGuncelle() {
        if (paraEl.value === 'TRY') { kurOnizlemeEl.textContent = ''; return; }
        var tutar = parseFloat((tutarEl.value || '').replace(',', '.'));
        var kur = parseFloat((kurEl.value || '').replace(',', '.'));
        if (!tutar || isNaN(tutar) || tutar <= 0 || !kur || isNaN(kur) || kur <= 0) {
            kurOnizlemeEl.textContent = '';
            return;
        }
        var tl = (tutar * kur).toLocaleString('tr-TR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        kurOnizlemeEl.textContent = 'TL karşılığı ≈ ' + tl + ' TRY';
    }

    tutarEl.addEventListener('input', function () { guncelle(); kurTlOnizleGuncelle(); });
    paraEl.addEventListener('change', function () { guncelle(); kurAlanGuncelle(); });
    kurEl.addEventListener('input', kurTlOnizleGuncelle);
    kurAlanGuncelle();
})();
</script>

<h2 style="font-size:1.05rem">Ödeme Geçmişi</h2>
<?php if (empty($odemeler)): ?>
<div class="pdks-empty">
    <span class="pdks-empty-icon" aria-hidden="true">💸</span>
    <p>Bu çavuş için henüz ödeme kaydı yok.</p>
</div>
<?php else: ?>

<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr>
    <th>Tarih</th><th>Tutar</th><th>Yöntem</th><th>Referans</th><th>Açıklama</th><th>Durum</th><th class="actions-col">İşlem</th>
</tr></thead>
<tbody>
<?php foreach ($odemeler as $o): ?>
<tr>
    <td class="muted"><?= h(date('d.m.Y', strtotime($o['payment_date']))) ?></td>
    <td>
        <strong><?= h(number_format((float)$o['amount'], 2, ',', '.')) ?> <?= h($o['currency']) ?></strong>
        <?php $tlEtiket = pdks_odeme_tl_karsiligi_etiket($o); ?>
        <?php if ($tlEtiket !== ''): ?>
        <div class="pdks-row-sub"><?= h($tlEtiket) ?></div>
        <?php endif; ?>
    </td>
    <td><?= h(pdks_cari_odeme_yontem_etiketi($o['payment_method'])) ?></td>
    <td><?= h($o['reference_no'] ?: '—') ?></td>
    <td><?= h($o['description'] ?: '—') ?></td>
    <td>
        <?php if ($o['status'] === 'cancelled'): ?>
        <span class="pdks-badge pdks-badge-pasif">İPTAL</span>
        <div class="pdks-row-sub" style="color:var(--danger)">Gerekçe: <?= h($o['cancellation_reason']) ?></div>
        <?php else: ?>
        <span class="pdks-badge pdks-badge-aktif">Geçerli</span>
        <?php endif; ?>
    </td>
    <td class="actions-col">
        <?php if ($o['status'] === 'valid'): ?>
        <details>
            <summary class="btn btn-sm">İptal Et</summary>
            <form method="post" style="margin-top:8px">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="iptal">
                <input type="hidden" name="payment_id" value="<?= (int)$o['id'] ?>">
                <input type="hidden" name="foreman_id" value="<?= (int)$cavusId ?>">
                <textarea name="sebep" rows="2" required placeholder="İptal gerekçesi *" style="width:220px"></textarea><br>
                <button type="submit" class="btn btn-sm">Onayla</button>
            </form>
        </details>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="pdks-cards mobile-only">
<?php foreach ($odemeler as $o): ?>
<div class="pdks-card-item">
    <div class="pdks-card-top">
        <div class="pdks-card-meta">
            <div class="pdks-row-name"><?= h(number_format((float)$o['amount'], 2, ',', '.')) ?> <?= h($o['currency']) ?></div>
            <div class="pdks-row-sub"><?= h(date('d.m.Y', strtotime($o['payment_date']))) ?> · <?= h(pdks_cari_odeme_yontem_etiketi($o['payment_method'])) ?><?= $o['reference_no'] ? ' · ' . h($o['reference_no']) : '' ?></div>
            <?php $tlEtiketKart = pdks_odeme_tl_karsiligi_etiket($o); ?>
            <?php if ($tlEtiketKart !== ''): ?>
            <div class="pdks-row-sub"><?= h($tlEtiketKart) ?></div>
            <?php endif; ?>
        </div>
        <?php if ($o['status'] === 'cancelled'): ?>
        <span class="pdks-badge pdks-badge-pasif">İPTAL</span>
        <?php else: ?>
        <span class="pdks-badge pdks-badge-aktif">Geçerli</span>
        <?php endif; ?>
    </div>
    <?php if ($o['status'] === 'cancelled'): ?>
    <div class="pdks-row-sub" style="color:var(--danger)">Gerekçe: <?= h($o['cancellation_reason']) ?></div>
    <?php else: ?>
    <details style="margin-top:8px">
        <summary class="btn btn-sm">İptal Et</summary>
        <form method="post" style="margin-top:8px">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="iptal">
            <input type="hidden" name="payment_id" value="<?= (int)$o['id'] ?>">
            <input type="hidden" name="foreman_id" value="<?= (int)$cavusId ?>">
            <textarea name="sebep" rows="2" required placeholder="İptal gerekçesi *"></textarea><br>
            <button type="submit" class="btn btn-sm" style="margin-top:6px">Onayla</button>
        </form>
    </details>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>
<?php endif; ?>

<?php render_footer(); ?>
