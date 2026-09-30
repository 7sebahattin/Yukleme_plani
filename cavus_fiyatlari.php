<?php
// =========================================================
// cavus_fiyatlari.php — Çavuş Fiyat Yönetimi
// Faz 8B şeması hazırsa Tam/Yarım/FM fiyatları; hazır değilse Faz 4'ün
// mevcut tek günlük ücret davranışı aynen devam eder.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/pdks_faz8b_cavus_b.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_hakedis('rates');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_hakedis_sayfa_kapisi($pdo);
$faz8bHazir = pdks_faz8b_sema_hazir($pdo);

// Çavuş Ücreti (Faz 8B eki): tablo yoksa BİR KEZ otomatik oluşturmayı dene
// (idempotent migrasyon fonksiyonu) — başarısızsa aşağıda uyarı kartı gösterilir.
if (!pdks_faz8b_cavus_ucret_sema_hazir($pdo)) {
    pdks_faz8b_cavus_ucret_migrate($pdo);
}
$cavusUcretHazir = pdks_faz8b_cavus_ucret_sema_hazir($pdo);

// Çavuş Ücreti Yöntem B: kendi 3 yeni tablosu, AYNI otomatik-deneme deseni.
if ($cavusUcretHazir && !pdks_faz8b_cavus_ucret_b_sema_hazir($pdo)) {
    pdks_faz8b_cavus_ucret_b_migrate($pdo);
}
$cavusBHazir = pdks_faz8b_cavus_ucret_b_sema_hazir($pdo);

$paraBirimleri = [
    'TRY' => 'Türk Lirası (TRY)',
    'EUR' => 'Euro (EUR)',
    'USD' => 'ABD Doları (USD)',
    'GBP' => 'İngiliz Sterlini (GBP)',
];

$cavusId = filter_var($_GET['cavus'] ?? '', FILTER_VALIDATE_INT) ?: null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'cavus_ucret') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_hakedis('rates');
    $cavusId = filter_var($_POST['foreman_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
    $ucret = trim((string)($_POST['cavus_daily_rate'] ?? ''));
    $ccy = strtoupper(trim((string)($_POST['cavus_currency'] ?? 'TRY'))) ?: 'TRY';
    $vf = trim((string)($_POST['cavus_valid_from'] ?? ''));

    if (!$cavusId) {
        $errors[] = 'Çavuş seçilmedi.';
    } elseif (!array_key_exists($ccy, $paraBirimleri)) {
        $errors[] = 'Geçersiz para birimi seçildi.';
    } else {
        $sonuc = pdks_faz8b_cavus_ucret_ekle($cavusId, $ucret, $vf, $ccy, (int)$auth_user['id'], $pdo);
        if ($sonuc['ok']) {
            header('Location: cavus_fiyatlari.php?cavus=' . $cavusId . '&ok=' . urlencode('Çavuş ücreti eklendi.'));
            exit;
        }
        $errors[] = $sonuc['hata'] ?? 'Kaydedilemedi.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'cavus_yontem') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_hakedis('rates');
    $cavusId = filter_var($_POST['foreman_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
    $yeniYontem = strtoupper(trim((string)($_POST['cavus_yontem'] ?? '')));

    if (!$cavusId) {
        $errors[] = 'Çavuş seçilmedi.';
    } else {
        $sonuc = pdks_faz8b_cavus_ucret_yontem_degistir($cavusId, $yeniYontem, (int)$auth_user['id'], $pdo);
        if ($sonuc['ok']) {
            if (!$sonuc['degisti']) {
                $msg = 'Hesaplama yöntemi zaten seçili; değişiklik yapılmadı.';
            } elseif ($sonuc['yontem'] === 'B') {
                $msg = 'Hesaplama yöntemi Yöntem B (25 kişi-gün = 1 hakediş) olarak kaydedildi.';
            } else {
                $msg = "Hesaplama yöntemi Yöntem A (günlük sabit ücret) olarak kaydedildi. Yöntem B'den bekleyen kişi-günler silinmedi; çavuş tekrar B'ye alınınca sayılmaya devam eder.";
            }
            header('Location: cavus_fiyatlari.php?cavus=' . $cavusId . '&ok=' . urlencode($msg));
            exit;
        }
        $errors[] = $sonuc['hata'] ?? 'Kaydedilemedi.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_hakedis('rates');
    $cavusId = filter_var($_POST['foreman_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
    $workerTypeId = filter_var($_POST['worker_type_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
    $tamUcret = trim((string)($_POST['daily_rate'] ?? ''));
    $validFrom = trim((string)($_POST['valid_from'] ?? ''));
    $currency = strtoupper(trim((string)($_POST['currency'] ?? 'TRY'))) ?: 'TRY';

    if (!$cavusId || !$workerTypeId) {
        $errors[] = 'Çavuş ve işçi tipi zorunludur.';
    } elseif (!array_key_exists($currency, $paraBirimleri)) {
        $errors[] = 'Geçersiz para birimi seçildi.';
    } else {
        if ($faz8bHazir) {
            $yarimUcret = trim((string)($_POST['half_day_rate'] ?? ''));
            $fmMode = trim((string)($_POST['overtime_mode'] ?? ''));
            $fmUcret = trim((string)($_POST['overtime_rate'] ?? ''));
            $sonuc = pdks_faz8b_oran_ekle(
                $cavusId,
                $workerTypeId,
                $tamUcret,
                $yarimUcret,
                $fmMode,
                $fmUcret,
                $validFrom,
                $currency,
                (int)$auth_user['id'],
                $pdo
            );
        } else {
            $sonuc = pdks_hakedis_oran_ekle(
                $cavusId,
                $workerTypeId,
                $tamUcret,
                $validFrom,
                $currency,
                (int)$auth_user['id'],
                $pdo
            );
        }

        if ($sonuc['ok']) {
            header('Location: cavus_fiyatlari.php?cavus=' . $cavusId . '&ok=' . urlencode('Yeni fiyat dönemi eklendi.'));
            exit;
        }
        $errors[] = $sonuc['hata'] ?? 'Kaydedilemedi.';
    }
}

$basari = '';
if (empty($errors) && isset($_GET['ok'])) $basari = trim((string)$_GET['ok']);
$seciliParaBirimi = strtoupper(trim((string)($_POST['currency'] ?? 'TRY')));
if (!array_key_exists($seciliParaBirimi, $paraBirimleri)) $seciliParaBirimi = 'TRY';

$cavuslar = $pdo->query("SELECT id, code, name, is_active FROM foremen ORDER BY is_active DESC, name ASC")->fetchAll();
// ⚠ Faz 9B / H-01 kapanışı: YENİ oran tanımlama açılır listesi TEK
// paylaşılan politikadan (config/pdks_gunluk.php) gelir — yalnız KADIN/ERKEK.
// Mevcut/tarihsel oranlar (aşağıdaki liste, pdks_hakedis_oran_gecmisi())
// durum/tip fark etmeksizin AYNEN görüntülenmeye devam eder.
$tipler = pdks_gunluk_desteklenen_tip_listele($pdo);
$seciliCavus = null;
$oranlar = [];
if ($cavusId !== null) {
    foreach ($cavuslar as $c) {
        if ((int)$c['id'] === $cavusId) { $seciliCavus = $c; break; }
    }
    if ($seciliCavus) $oranlar = pdks_hakedis_oran_gecmisi($cavusId, $pdo);
}
$cavusUcretGecmisi = ($seciliCavus && $cavusUcretHazir) ? pdks_faz8b_cavus_ucret_gecmisi($cavusId, $pdo) : [];
$cavusYontem = ($seciliCavus && $cavusBHazir) ? pdks_faz8b_cavus_ucret_yontem($cavusId, $pdo) : 'A';
$cavusYontemGecmisi = ($seciliCavus && $cavusBHazir)
    ? array_reverse(pdks_faz8b_cavus_ucret_yontem_gecmisi($cavusId, $pdo))
    : [];

render_header('Çavuş Ücretleri');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head">
    <h1>💰 Çavuş Ücretleri</h1>
    <div class="page-head-actions">
        <a href="personel_takip.php" class="btn btn-ghost">← Personel Takibi</a>
    </div>
</div>

<?php if ($basari !== ''): ?><div class="flash flash-success"><?= h($basari) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="flash flash-error"><?= h($e) ?></div><?php endforeach; ?>
<?php if (!$faz8bHazir): ?><div class="flash flash-warning">Faz 8B şeması henüz çalıştırılmadı. Bu ekran mevcut tek Günlük Ücret modeliyle güvenli biçimde devam ediyor. Yönetici <a href="migrate.php">Şema Migrasyon</a> ekranından Faz 8B migrasyonunu çalıştırabilir.</div><?php endif; ?>

<form method="get" class="pdks-filter-bar">
    <select name="cavus" onchange="this.form.submit()">
        <option value="">— Çavuş seçin —</option>
        <?php foreach ($cavuslar as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $cavusId === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?> (<?= h($c['code']) ?>)<?= $c['is_active'] ? '' : ' — pasif' ?></option>
        <?php endforeach; ?>
    </select>
    <noscript><button type="submit" class="btn">Seç</button></noscript>
</form>

<?php if (!$seciliCavus): ?>
<div class="pdks-empty"><span class="pdks-empty-icon" aria-hidden="true">💰</span><p>Fiyatları görmek/eklemek için önce bir çavuş seçin.</p></div>
<?php else: ?>

<div class="card" style="padding:18px 20px;margin:18px 0">
    <h2 style="margin-top:0;font-size:1rem">Yeni Etkin Fiyat Dönemi — <?= h($seciliCavus['name']) ?></h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="foreman_id" value="<?= (int)$seciliCavus['id'] ?>">
        <input type="hidden" name="form" value="oran">
        <div class="pdks-form-grid">
            <label>
                <span class="form-label">İşçi Tipi *</span>
                <select name="worker_type_id" required>
                    <option value="">— Seçin —</option>
                    <?php foreach ($tipler as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>
                <span class="form-label"><?= $faz8bHazir ? 'Tam Mesai Ücreti *' : 'Günlük Ücret *' ?></span>
                <input type="text" name="daily_rate" required inputmode="decimal" placeholder="ör. 1500 veya 1500,50">
            </label>
            <?php if ($faz8bHazir): ?>
            <label>
                <span class="form-label">Yarım Mesai Ücreti *</span>
                <input type="text" name="half_day_rate" required inputmode="decimal" placeholder="ör. 900">
            </label>
            <label>
                <span class="form-label">Fazla Mesai Tipi *</span>
                <select name="overtime_mode" required>
                    <option value="hourly">Saatlik</option>
                    <option value="fixed">Sabit Toplam</option>
                </select>
            </label>
            <label>
                <span class="form-label">Fazla Mesai Ücreti *</span>
                <input type="text" name="overtime_rate" required inputmode="decimal" placeholder="ör. 200">
                <small class="muted">Saatlik: 15 dk tolerans sonrası başlayan her saat yukarı yuvarlanır. Sabit: onaylanan FM için bir kez uygulanır.</small>
            </label>
            <?php endif; ?>
            <label>
                <span class="form-label">Para Birimi *</span>
                <select name="currency" required>
                    <?php foreach ($paraBirimleri as $kod => $etiket): ?>
                    <option value="<?= h($kod) ?>" <?= $seciliParaBirimi === $kod ? 'selected' : '' ?>><?= h($etiket) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span class="form-label">Geçerlilik Başlangıcı *</span><input type="date" name="valid_from" required value="<?= h(date('Y-m-d')) ?>"></label>
        </div>
        <p class="muted" style="font-size:.85rem;margin:10px 0 0">Yeni dönem eklenince önceki açık fiyat dönemi bir gün öncesinde kapanır. Eski fiyatlar silinmez.</p>
        <button type="submit" class="btn btn-primary" style="margin-top:14px">+ Fiyat Dönemi Ekle</button>
    </form>
</div>

<h2 style="font-size:1.05rem">Fiyat Geçmişi</h2>
<?php if (empty($oranlar)): ?>
<div class="pdks-empty"><p>Bu çavuş için henüz bir fiyat tanımlanmadı.</p></div>
<?php else: ?>
<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr><th>İşçi Tipi</th><th><?= $faz8bHazir ? 'Tam' : 'Günlük Ücret' ?></th><?php if ($faz8bHazir): ?><th>Yarım</th><th>Fazla Mesai</th><?php endif; ?><th>Geçerlilik</th><th>Durum</th></tr></thead>
<tbody>
<?php foreach ($oranlar as $o): ?>
<tr>
    <td class="pdks-row-name"><?= h($o['worker_type_name']) ?></td>
    <td><strong><?= h(number_format((float)$o['daily_rate'], 2, ',', '.')) ?> <?= h($o['currency']) ?></strong></td>
    <?php if ($faz8bHazir): ?>
    <td><?= ($o['half_day_rate'] ?? null) !== null ? h(number_format((float)$o['half_day_rate'], 2, ',', '.') . ' ' . $o['currency']) : '—' ?></td>
    <td><?php if (($o['overtime_rate'] ?? null) !== null): ?><?= h(number_format((float)$o['overtime_rate'], 2, ',', '.') . ' ' . $o['currency']) ?> · <?= h(($o['overtime_mode'] ?? '') === 'fixed' ? 'Sabit' : 'Saatlik') ?><?php else: ?>—<?php endif; ?></td>
    <?php endif; ?>
    <td class="muted"><?= h(date('d.m.Y', strtotime($o['valid_from']))) ?> → <?= $o['valid_to'] ? h(date('d.m.Y', strtotime($o['valid_to']))) : 'devam ediyor' ?></td>
    <td><span class="pdks-badge <?= $o['is_active'] ? 'pdks-badge-aktif' : 'pdks-badge-pasif' ?>"><?= $o['is_active'] ? 'Aktif' : 'Pasif' ?></span></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>

<div class="pdks-cards mobile-only">
<?php foreach ($oranlar as $o): ?>
<div class="pdks-card-item">
    <div class="pdks-card-top"><div class="pdks-card-meta"><div class="pdks-row-name"><?= h($o['worker_type_name']) ?></div><div class="pdks-row-sub"><?= h(date('d.m.Y', strtotime($o['valid_from']))) ?> → <?= $o['valid_to'] ? h(date('d.m.Y', strtotime($o['valid_to']))) : 'devam ediyor' ?></div></div><span class="pdks-badge <?= $o['is_active'] ? 'pdks-badge-aktif' : 'pdks-badge-pasif' ?>"><?= $o['is_active'] ? 'Aktif' : 'Pasif' ?></span></div>
    <div class="pdks-row-sub"><?= $faz8bHazir ? 'Tam' : 'Günlük' ?>: <strong><?= h(number_format((float)$o['daily_rate'],2,',','.')) ?> <?= h($o['currency']) ?></strong></div>
    <?php if ($faz8bHazir): ?><div class="pdks-row-sub">Yarım: <?= ($o['half_day_rate'] ?? null) !== null ? h(number_format((float)$o['half_day_rate'],2,',','.') . ' ' . $o['currency']) : '—' ?></div><div class="pdks-row-sub">FM: <?= ($o['overtime_rate'] ?? null) !== null ? h(number_format((float)$o['overtime_rate'],2,',','.') . ' ' . $o['currency'] . ' · ' . (($o['overtime_mode'] ?? '') === 'fixed' ? 'Sabit' : 'Saatlik')) : '—' ?></div><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$cavusUcretHazir): ?>
<div class="flash flash-warning">Çavuş Ücreti tablosu henüz oluşturulamadı. Yönetici <a href="migrate.php">migrate.php</a>'den oluşturabilir.</div>
<?php else: ?>

<div class="card" style="padding:18px 20px;margin:18px 0">
    <h2 style="margin-top:0;font-size:1rem">Çavuş Ücreti — <?= h($seciliCavus['name']) ?></h2>

    <?php if ($cavusBHazir): ?>
    <div style="border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin:0 0 14px">
        <div style="margin-bottom:8px">Hesaplama Yöntemi:
            <span class="pdks-badge <?= $cavusYontem === 'B' ? 'pdks-badge-acik' : 'pdks-badge-aktif' ?>"><?= h(pdks_faz8b_cavus_ucret_yontem_etiketi($cavusYontem)) ?></span>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="foreman_id" value="<?= (int)$seciliCavus['id'] ?>">
            <input type="hidden" name="form" value="cavus_yontem">
            <label style="display:flex;align-items:flex-start;gap:8px;font-weight:400;margin-bottom:8px">
                <input type="radio" name="cavus_yontem" value="A" <?= $cavusYontem !== 'B' ? 'checked' : '' ?> style="margin-top:4px">
                <span>Yöntem A — Günlük sabit ücret. Çavuşun işçi çalıştırdığı her gün hakedişe bir "Çavuş Ücreti" satırı eklenir.</span>
            </label>
            <label style="display:flex;align-items:flex-start;gap:8px;font-weight:400;margin-bottom:8px">
                <input type="radio" name="cavus_yontem" value="B" <?= $cavusYontem === 'B' ? 'checked' : '' ?> style="margin-top:4px">
                <span>Yöntem B — 25 kişi-gün = 1 hakediş. Kesinleşmiş günlerdeki kişi-gün toplamı her 25'te 1 hakediş kazandırır; çavuşa yapılan para verişi Çavuş Cari ekranında kaydedildiğinde dönem otomatik kapanır, kalan kişi-gün devreder.</span>
            </label>
            <p class="muted" style="font-size:.82rem;margin:6px 0">Yöntem değişikliği geriye dönük değildir: değişiklikten önce kesinleşmiş günler eski yöntemle kalır. B'den A'ya geçişte bekleyen kişi-günler ve devir silinmez.</p>
            <button type="submit" class="btn" style="margin-top:6px">Yöntemi Kaydet</button>
        </form>
        <?php if ($cavusYontemGecmisi): ?>
        <p class="muted" style="font-size:.8rem;margin:10px 0 0">Son değişiklikler:
            <?php foreach (array_slice($cavusYontemGecmisi, 0, 5) as $i => $y): ?>
            <?= $i > 0 ? ' · ' : '' ?><?= h(date('d.m.Y H:i', strtotime((string)$y['effective_at']))) ?> → <?= h((string)$y['method']) ?>
            <?php endforeach; ?>
        </p>
        <?php endif; ?>
    </div>
    <?php elseif ($cavusUcretHazir): ?>
    <div class="flash flash-warning" style="margin:0 0 14px">Yöntem B tabloları henüz oluşturulamadı — yönetici migrate.php'den oluşturabilir.</div>
    <?php endif; ?>

    <p class="muted" style="font-size:.85rem"><?php if ($cavusYontem === 'B'): ?>Yöntem B seçili: aşağıdaki ücret artık GÜNLÜK işlenmez — her 25 kişi-günlük hakedişin BİRİM ÜCRETİDİR. Dönem kapanışında, çavuşa para verilen günün tarihinde geçerli ücret uygulanır. Ücret tanımsızsa para verişi yine kaydedilir ama dönem kapanışı yapılmaz.<?php else: ?>Bu çavuşun kendi günlük çalışma ücreti. Zorunlu
       değil — boş bırakılırsa hakedişe hiçbir satır eklenmez.<?php endif; ?></p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="foreman_id" value="<?= (int)$seciliCavus['id'] ?>">
        <input type="hidden" name="form" value="cavus_ucret">
        <div class="pdks-form-grid">
            <label><span class="form-label"><?= $cavusYontem === 'B' ? 'Hakediş Birim Ücreti (25 kişi-gün) *' : 'Günlük Ücret *' ?></span>
                <input type="text" name="cavus_daily_rate" required inputmode="decimal" placeholder="ör. 1500 veya 1500,50"></label>
            <label><span class="form-label">Para Birimi *</span>
                <select name="cavus_currency" required>
                    <?php foreach ($paraBirimleri as $kod => $etiket): ?>
                    <option value="<?= h($kod) ?>"><?= h($etiket) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label><span class="form-label">Geçerlilik Başlangıcı *</span>
                <input type="date" name="cavus_valid_from" required value="<?= h(date('Y-m-d')) ?>"></label>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:14px"><?= $cavusYontem === 'B' ? '+ Birim Ücret Dönemi Ekle' : '+ Çavuş Ücreti Dönemi Ekle' ?></button>
    </form>
</div>

<h2 style="font-size:1.05rem">Çavuş Ücreti Geçmişi</h2>
<?php if (empty($cavusUcretGecmisi)): ?>
<div class="pdks-empty"><p><?= $cavusYontem === 'B' ? 'Bu çavuş için henüz bir birim ücret tanımlanmadı.' : 'Bu çavuş için henüz bir günlük ücret tanımlanmadı.' ?></p></div>
<?php else: ?>
<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr><th><?= $cavusYontem === 'B' ? 'Birim Ücret (25 kişi-gün)' : 'Ücret' ?></th><th>Geçerlilik</th><th>Durum</th></tr></thead>
<tbody>
<?php foreach ($cavusUcretGecmisi as $cu): ?>
<tr>
    <td><strong><?= h(number_format((float)$cu['daily_rate'], 2, ',', '.')) ?> <?= h($cu['currency']) ?></strong></td>
    <td class="muted"><?= h(date('d.m.Y', strtotime($cu['valid_from']))) ?> → <?= $cu['valid_to'] ? h(date('d.m.Y', strtotime($cu['valid_to']))) : 'devam ediyor' ?></td>
    <td><span class="pdks-badge <?= $cu['is_active'] ? 'pdks-badge-aktif' : 'pdks-badge-pasif' ?>"><?= $cu['is_active'] ? 'Aktif' : 'Pasif' ?></span></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>

<div class="pdks-cards mobile-only">
<?php foreach ($cavusUcretGecmisi as $cu): ?>
<div class="pdks-card-item">
    <div class="pdks-card-top"><div class="pdks-card-meta"><div class="pdks-row-name"><?= h(number_format((float)$cu['daily_rate'],2,',','.')) ?> <?= h($cu['currency']) ?></div><div class="pdks-row-sub"><?= h(date('d.m.Y', strtotime($cu['valid_from']))) ?> → <?= $cu['valid_to'] ? h(date('d.m.Y', strtotime($cu['valid_to']))) : 'devam ediyor' ?></div></div><span class="pdks-badge <?= $cu['is_active'] ? 'pdks-badge-aktif' : 'pdks-badge-pasif' ?>"><?= $cu['is_active'] ? 'Aktif' : 'Pasif' ?></span></div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php endif; ?>

<?php render_footer(); ?>
