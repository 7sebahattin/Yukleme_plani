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
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/pdks_faz8b_cavus_b.php';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_cari('payments');   // savunma derinliği
    $action = trim($_POST['action'] ?? '');

    if ($action === 'odeme_kaydet') {
        $cavusId = filter_var($_POST['foreman_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
        $tarih = trim((string)($_POST['payment_date'] ?? ''));
        $tutarHam = trim((string)($_POST['amount'] ?? ''));
        $currency = trim((string)($_POST['currency'] ?? 'TRY')) ?: 'TRY';
        $yontem = trim((string)($_POST['payment_method'] ?? 'OTHER'));
        $referansNo = trim((string)($_POST['reference_no'] ?? ''));
        $aciklama = trim((string)($_POST['description'] ?? ''));
        $asimOnay = isset($_POST['asim_onay']);

        if (!$cavusId) {
            $errors[] = 'Çavuş seçilmedi.';
        } else {
            // Aşım kontrolü — SUNUCU tarafında, istemci hesaplamasına GÜVENİLMEZ.
            // pdks_cari_odeme_onizleme() sayfa VE testler ARASINDA PAYLAŞILAN
            // TEK mantıktır (paralel/tekrarlanan bir hesap YOK).
            $kurusOnizleme = pdks_hakedis_girdi_kurus($tutarHam);
            $onizleme = $kurusOnizleme !== null && $kurusOnizleme > 0 ? pdks_faz8b_cavus_ucret_odeme_onizleme($cavusId, $kurusOnizleme, $currency, $tarih, $pdo) : null;

            if ($onizleme !== null && $onizleme['asim'] && !$asimOnay) {
                $uyari = 'Bu ödeme mevcut borç bakiyesini aşmaktadır. İşlem sonrası çavuş avansı/fazla ödeme oluşacaktır. '
                        . '(Sonuç: ' . h(pdks_hakedis_kurus_tl(abs($onizleme['yeni_bakiye_kurus']))) . ' ' . h($currency) . ' avans.) '
                        . 'Devam etmek için aşağıdaki onay kutusunu işaretleyip tekrar kaydedin.';
            } else {
                $sonuc = pdks_faz8b_cavus_ucret_odeme_kaydet($cavusId, $tarih, $tutarHam, $currency, $yontem, $referansNo, $aciklama, (int)$auth_user['id'], $pdo);
                if ($sonuc['ok']) {
                    $mesaj = 'Ödeme kaydedildi.';
                    $kap = $sonuc['kapanis'] ?? null;
                    if ($kap !== null && !empty($kap['kapanis_id'])) {
                        $o = $kap['onizleme'];
                        $mesaj .= ' Çavuş Hakedişi dönemi kapandı (CVH-' . str_pad((string)$kap['kapanis_id'], 6, '0', STR_PAD_LEFT) . '): '
                            . (int)$o['donem_kisi_gun'] . ' kişi-gün + ' . (int)$o['devir_giren'] . ' devir → ' . (int)$o['adet'] . ' hakediş = '
                            . pdks_faz8b_cavus_ucret_b_para((int)$o['tutar_kurus']) . ' ' . $o['currency'] . ', devir ' . (int)$o['devir_cikan'] . '.';
                    } elseif ($kap !== null && ($kap['onizleme']['durum'] ?? '') === 'ucret_yok') {
                        $o = $kap['onizleme'];
                        header('Location: cavus_odeme.php?cavus=' . $cavusId . '&ok=' . urlencode($mesaj) . '&uyari=' . urlencode(
                            'Çavuş ücreti tanımsız olduğu için Çavuş Hakedişi dönem kapanışı YAPILMADI; ' . (int)$o['donem_kisi_gun'] . ' kişi-gün + '
                            . (int)$o['devir_giren'] . ' devir açıkta bekliyor. Ücreti Çavuş Ücretleri ekranından tanımlayın; sonraki ödemede kapanış yapılır.'
                        ));
                        exit;
                    }
                    header('Location: cavus_odeme.php?cavus=' . $cavusId . '&ok=' . urlencode($mesaj));
                    exit;
                }
                $errors[] = $sonuc['hata'] ?? 'Kaydedilemedi.';
            }
        }
    } elseif ($action === 'iptal') {
        $paymentId = filter_var($_POST['payment_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
        $cavusId = filter_var($_POST['foreman_id'] ?? '', FILTER_VALIDATE_INT) ?: $cavusId;
        $sebep = trim((string)($_POST['sebep'] ?? ''));
        $sonuc = $paymentId ? pdks_faz8b_cavus_ucret_odeme_iptal($paymentId, $sebep, (int)$auth_user['id'], $pdo) : ['ok' => false, 'hata' => 'Ödeme bulunamadı.'];
        if ($sonuc['ok']) {
            $mesaj = 'Ödeme iptal edildi.';
            if (!empty($sonuc['kapanis_iptal'])) {
                $mesaj .= ' Bağlı Çavuş Hakedişi kapanışı (CVH-' . str_pad((string)$sonuc['kapanis_iptal'], 6, '0', STR_PAD_LEFT) . ') geri alındı; '
                    . 'kişi-günler yeniden açığa düştü ve sonraki ödemede tekrar sayılacak.';
            }
            header('Location: cavus_odeme.php?cavus=' . $cavusId . '&ok=' . urlencode($mesaj));
            exit;
        }
        $errors[] = $sonuc['hata'] ?? 'İptal edilemedi.';
    }
}

$uyariGet = isset($_GET['uyari']) ? trim((string)$_GET['uyari']) : '';

$basari = '';
if (empty($errors) && isset($_GET['ok'])) $basari = trim($_GET['ok']);

$cavuslar = $pdo->query("SELECT id, code, name, is_active FROM foremen ORDER BY is_active DESC, name ASC")->fetchAll();
$seciliCavus = null; $bakiyeler = []; $odemeler = [];
$bHazir = pdks_faz8b_cavus_ucret_b_sema_hazir($pdo);
$bOnizleme = null; $kapanisHaritasi = []; $sonKapanis = null;
if ($cavusId !== null) {
    foreach ($cavuslar as $c) { if ((int)$c['id'] === $cavusId) { $seciliCavus = $c; break; } }
    if ($seciliCavus) {
        $bakiyeler = pdks_cari_bakiye($cavusId, $pdo);
        $odemeler = pdks_cari_odeme_listesi($cavusId, $pdo);
        if ($bHazir) {
            $bOnizleme = pdks_faz8b_cavus_ucret_b_onizle($cavusId, date('Y-m-d'), $pdo);
            $kapanisHaritasi = pdks_faz8b_cavus_ucret_b_odeme_kapanis_haritasi($cavusId, $pdo);
            $sonKapanis = pdks_faz8b_cavus_ucret_b_son_kapanis($cavusId, $pdo);
        }
    }
}

render_header('Çavuş Ödeme');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
if ($basari !== '') echo '<div class="flash flash-success">' . h($basari) . '</div>';
foreach ($errors as $e) echo '<div class="flash flash-error">' . h($e) . '</div>';
if ($uyari) echo '<div class="flash flash-error" style="border-color:var(--warn);background:var(--warn-soft);color:var(--warn)">⚠️ ' . h($uyari) . '</div>';
if ($uyariGet !== '') echo '<div class="flash flash-error" style="border-color:var(--warn);background:var(--warn-soft);color:var(--warn)">⚠️ ' . h($uyariGet) . '</div>';
?>

<div class="page-head">
    <h1>💸 Çavuş Ödeme</h1>
    <div class="page-head-actions">
        <?php if ($seciliCavus): ?>
        <a href="cavus_odeme_yazdir.php?cavus=<?= (int)$seciliCavus['id'] ?>" class="btn btn-ghost">🖨️ Yazdır</a>
        <?php endif; ?>
        <a href="personel_takip.php" class="btn btn-ghost">← Personel Takibi</a>
    </div>
</div>

<form method="get" class="pdks-filter-bar" data-oto-filtre>
    <select name="cavus">
        <option value="">— Çavuş seçin —</option>
        <?php foreach ($cavuslar as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $cavusId === (int)$c['id'] ? 'selected' : '' ?>>
            <?= h($c['name']) ?> (<?= h($c['code']) ?>)<?= $c['is_active'] ? '' : ' — pasif' ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?= pdks_oto_filtre_noscript('Seç') ?>
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
        <div class="pdks-kiosk-counter-box"><div class="lbl">Kesinleşmiş Hakediş (<?= h($cur) ?>)</div><div class="val"><?= h(number_format((float)$b['hakedis_toplam'], 2, ',', '.')) ?></div>
            <?php if ((int)($b['cavus_hakedis_kurus'] ?? 0) !== 0): ?>
            <div class="pdks-row-sub">içinde Çavuş Hakedişi (Yöntem B): <?= h(number_format((float)$b['cavus_hakedis_toplam'], 2, ',', '.')) ?></div>
            <?php endif; ?>
        </div>
        <div class="pdks-kiosk-counter-box"><div class="lbl">Toplam Ödeme</div><div class="val"><?= h(number_format((float)$b['odeme_toplam'], 2, ',', '.')) ?></div></div>
        <?php if (($b['duzeltme_kurus'] ?? 0) !== 0): ?>
        <!-- Fix 9 (Personel Takibi denetimi): Faz 9D düzeltme/mahsup varsa Bakiye
             artık Hakediş-Ödeme'ye TAM eşit değildir (Bakiye = Hakediş + Düzeltme -
             Ödeme, bkz. pdks_cari_bakiye()) — bu kutu olmadan fark "tutmuyor"
             görünüyordu. Düzeltme YOKSA (çoğu çavuş) kutu HİÇ gösterilmez. -->
        <div class="pdks-kiosk-counter-box"><div class="lbl">Düzeltme (Net)</div><div class="val"><?= h(number_format((float)$b['duzeltme_toplam'], 2, ',', '.')) ?></div></div>
        <?php endif; ?>
        <div class="pdks-kiosk-counter-box <?= $b['durum'] === 'borc' ? 'eksik' : '' ?>"><div class="lbl"><?= h($b['durum_etiket']) ?></div><div class="val" id="pdksBakiye<?= h($cur) ?>" data-bakiye-kurus="<?= (int)$b['bakiye_kurus'] ?>"><?= h(number_format(abs((float)$b['bakiye']), 2, ',', '.')) ?></div></div>
    </div>
    <?php endforeach; endif; ?>
</div>

<?php
$bOnizlemeDurum = $bOnizleme['durum'] ?? 'sema_yok';
$bOnizlemeGoster = $bOnizleme !== null && $bOnizlemeDurum !== 'sema_yok'
    && ($bOnizlemeDurum === 'kapanacak' || $bOnizlemeDurum === 'ucret_yok' || (int)($bOnizleme['donem_kisi_gun'] ?? 0) + (int)($bOnizleme['devir_giren'] ?? 0) > 0);
if ($bOnizlemeGoster):
    $bKapanisKurus = $bOnizlemeDurum === 'kapanacak' ? (int)$bOnizleme['tutar_kurus'] : 0;
    $bUyariMi = $bOnizlemeDurum === 'ucret_yok';
?>
<div id="pdksBOnizleme" data-kapanis-kurus="<?= $bKapanisKurus ?>" data-kapanis-para="<?= h((string)($bOnizleme['currency'] ?? 'TRY')) ?>"
     class="card" style="padding:14px 16px;margin-bottom:16px<?= $bUyariMi ? ';border-color:var(--warn);background:var(--warn-soft);color:var(--warn)' : '' ?>">
    <strong>🧮 Çavuş Hakedişi (Yöntem B)</strong>
    <p style="margin:6px 0 0;font-size:.88rem"><?= h($bOnizleme['mesaj']) ?></p>
    <p class="muted" style="margin:4px 0 0;font-size:.8rem">Kapanış, kayıt anında formdaki ödeme tarihinde geçerli ücretle yapılır.</p>
    <div id="pdksBParaNotu" class="muted" style="margin:4px 0 0;font-size:.8rem"></div>
</div>
<?php endif; ?>

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
                <input type="text" name="currency" id="pdksOdemeParaBirimi" maxlength="10" value="TRY">
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
    var onizlemeEl = document.getElementById('pdksOdemeOnizleme');
    var bEl = document.getElementById('pdksBOnizleme');
    var bParaNotuEl = document.getElementById('pdksBParaNotu');
    function guncelle() {
        var para = (paraEl.value || 'TRY').trim();
        var bakiyeEl = document.getElementById('pdksBakiye' + para);
        if (bParaNotuEl) bParaNotuEl.textContent = '';
        if (!bakiyeEl) { onizlemeEl.textContent = ''; return; }
        var mevcutKurus = parseInt(bakiyeEl.getAttribute('data-bakiye-kurus') || '0', 10);
        if (bEl) {
            var bKurus = parseInt(bEl.getAttribute('data-kapanis-kurus') || '0', 10);
            var bPara = bEl.getAttribute('data-kapanis-para') || '';
            if (bKurus > 0) {
                if (bPara && bPara !== para) {
                    if (bParaNotuEl) bParaNotuEl.textContent = 'Not: Çavuş hakedişi ' + bPara + ' bakiyesine yazılır; bu ödeme ' + para + ' bakiyesinden düşer.';
                } else {
                    mevcutKurus += bKurus;
                }
            }
        }
        var ham = (tutarEl.value || '').replace(',', '.');
        var tutar = parseFloat(ham);
        if (!ham || isNaN(tutar) || tutar <= 0) { onizlemeEl.textContent = ''; return; }
        var tutarKurus = Math.round(tutar * 100);
        var kalanKurus = mevcutKurus - tutarKurus;
        var kalanTl = (Math.abs(kalanKurus) / 100).toLocaleString('tr-TR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        if (kalanKurus >= 0) {
            onizlemeEl.textContent = 'Kapanış dahil mevcut borç: ' + (mevcutKurus / 100).toLocaleString('tr-TR', {minimumFractionDigits: 2}) + ' ' + para + ' — Ödeme sonrası kalan: ' + kalanTl + ' ' + para;
        } else {
            onizlemeEl.textContent = 'Ödeme sonrası ÇAVUŞ AVANSI oluşacak: ' + kalanTl + ' ' + para;
        }
    }
    tutarEl.addEventListener('input', guncelle);
    paraEl.addEventListener('input', guncelle);
    guncelle();
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
    <td><strong><?= h(number_format((float)$o['amount'], 2, ',', '.')) ?> <?= h($o['currency']) ?></strong></td>
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
        <?php $ok = $kapanisHaritasi[(int)$o['id']] ?? null; if ($ok): ?>
        <div class="pdks-row-sub">Çavuş Hakedişi: CVH-<?= str_pad((string)$ok['id'], 6, '0', STR_PAD_LEFT) ?> — <?= (int)$ok['earned_units'] ?> hakediş, devir <?= (int)$ok['carry_out'] ?><?= $ok['status'] === 'cancelled' ? ' (geri alındı)' : '' ?></div>
        <?php endif; ?>
    </td>
    <td class="actions-col">
        <?php if ($o['status'] === 'valid'):
            $okG = $kapanisHaritasi[(int)$o['id']] ?? null;
            $sonKapanisId = $sonKapanis ? (int)$sonKapanis['id'] : null;
            $engelli = $okG && $okG['status'] === 'valid' && $sonKapanisId !== (int)$okG['id'];
        ?>
        <?php if ($engelli): ?>
        <p class="muted" style="font-size:.78rem;max-width:220px">İptal için önce daha yeni ödemeyi iptal edin (sonrasında daha yeni bir Çavuş Hakedişi kapanışı var).</p>
        <?php else: ?>
        <details>
            <summary class="btn btn-sm">İptal Et</summary>
            <form method="post" style="margin-top:8px">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="iptal">
                <input type="hidden" name="payment_id" value="<?= (int)$o['id'] ?>">
                <input type="hidden" name="foreman_id" value="<?= (int)$cavusId ?>">
                <?php if ($okG && $okG['status'] === 'valid'): ?>
                <p class="muted" style="font-size:.78rem">Bu ödeme iptal edilirse bağlı Çavuş Hakedişi kapanışı da geri alınır.</p>
                <?php endif; ?>
                <textarea name="sebep" rows="2" required placeholder="İptal gerekçesi *" style="width:220px"></textarea><br>
                <button type="submit" class="btn btn-sm">Onayla</button>
            </form>
        </details>
        <?php endif; ?>
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
        </div>
        <?php if ($o['status'] === 'cancelled'): ?>
        <span class="pdks-badge pdks-badge-pasif">İPTAL</span>
        <?php else: ?>
        <span class="pdks-badge pdks-badge-aktif">Geçerli</span>
        <?php endif; ?>
    </div>
    <?php $okM = $kapanisHaritasi[(int)$o['id']] ?? null; if ($okM): ?>
    <div class="pdks-row-sub">Çavuş Hakedişi: CVH-<?= str_pad((string)$okM['id'], 6, '0', STR_PAD_LEFT) ?> — <?= (int)$okM['earned_units'] ?> hakediş, devir <?= (int)$okM['carry_out'] ?><?= $okM['status'] === 'cancelled' ? ' (geri alındı)' : '' ?></div>
    <?php endif; ?>
    <?php if ($o['status'] === 'cancelled'): ?>
    <div class="pdks-row-sub" style="color:var(--danger)">Gerekçe: <?= h($o['cancellation_reason']) ?></div>
    <?php else:
        $sonKapanisId = $sonKapanis ? (int)$sonKapanis['id'] : null;
        $engelliM = $okM && $okM['status'] === 'valid' && $sonKapanisId !== (int)$okM['id'];
    ?>
    <?php if ($engelliM): ?>
    <p class="muted" style="font-size:.78rem;margin-top:8px">İptal için önce daha yeni ödemeyi iptal edin (sonrasında daha yeni bir Çavuş Hakedişi kapanışı var).</p>
    <?php else: ?>
    <details style="margin-top:8px">
        <summary class="btn btn-sm">İptal Et</summary>
        <form method="post" style="margin-top:8px">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="iptal">
            <input type="hidden" name="payment_id" value="<?= (int)$o['id'] ?>">
            <input type="hidden" name="foreman_id" value="<?= (int)$cavusId ?>">
            <?php if ($okM && $okM['status'] === 'valid'): ?>
            <p class="muted" style="font-size:.78rem">Bu ödeme iptal edilirse bağlı Çavuş Hakedişi kapanışı da geri alınır.</p>
            <?php endif; ?>
            <textarea name="sebep" rows="2" required placeholder="İptal gerekçesi *"></textarea><br>
            <button type="submit" class="btn btn-sm" style="margin-top:6px">Onayla</button>
        </form>
    </details>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>
<?php endif; ?>

<?php pdks_liste_ui_js(); ?>
<?php render_footer(); ?>
