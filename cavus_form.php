<?php
// Çavuş oluşturma/düzenleme ve tarihli normal çalışma süresi tanımları.
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/pdks_cavus_sure.php';
require_once __DIR__ . '/config/auth.php';

$auth_user = require_login();
require_pdks_gunluk('foremen');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$faz8bHazir = pdks_faz8b_sema_hazir($pdo);
$record = ['code' => '', 'name' => '', 'phone' => '', 'notes' => '', 'is_active' => 1];
if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM foremen WHERE id = ?');
    $st->execute([$id]);
    $eski = $st->fetch();
    if (!$eski) {
        set_flash('error', 'Çavuş bulunamadı.');
        header('Location: cavuslar.php');
        exit;
    }
    $record = $eski;
} else {
    $record['code'] = pdks_gunluk_sonraki_cavus_kodu($pdo);
}

$errors = [];
$sureInput = [
    'saat' => trim((string)($_POST['saat'] ?? '9')),
    'valid_from' => trim((string)($_POST['valid_from'] ?? date('Y-m-d'))),
    'valid_to' => trim((string)($_POST['valid_to'] ?? '')),
    'kapsam' => trim((string)($_POST['kapsam'] ?? 'suresiz')),
    'gerekce' => trim((string)($_POST['gerekce'] ?? '')),
];
$sureHazir = false;
try {
    $sureHazir = pdks_cavus_sure_hazir($pdo);
} catch (Throwable $e) {
    // Eksik audit şeması sayfayı bozmaz; aşağıda kullanıcıya bilgi verilir.
    $sureHazir = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_gunluk('foremen');
    $action = trim((string)($_POST['action'] ?? 'save'));

    if ($action === 'save') {
        $veri = [
            'code' => trim((string)($_POST['code'] ?? '')),
            'name' => trim((string)($_POST['name'] ?? '')),
            'phone' => trim((string)($_POST['phone'] ?? '')),
            'notes' => trim((string)($_POST['notes'] ?? '')),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        // Yeni çavuşta eski canlı varsayılan süre ilk değer olarak korunur.
        // Önce doğrula; profil kaydı başarılı olduktan sonra süre hatası vermemek için.
        $ilkSureDk = null;
        if ($id === 0 && $faz8bHazir) {
            $ilkSureDk = pdks_faz8b_saat_girdi_dk($_POST['saat'] ?? '');
            if ($ilkSureDk === null || $ilkSureDk < PDKS_FAZ8B_SURE_MIN_DK || $ilkSureDk > PDKS_FAZ8B_SURE_MAX_DK) {
                $errors[] = 'Normal günlük çalışma süresi 1-24 saat arasında olmalıdır.';
            }
        }

        if (!$errors) {
            $sonuc = ($id > 0)
                ? pdks_gunluk_cavus_guncelle($id, $veri, (int)$auth_user['id'], $pdo)
                : pdks_gunluk_cavus_olustur($veri, (int)$auth_user['id'], $pdo);

            if ($sonuc['ok']) {
                $kayitId = ($id > 0) ? $id : (int)$sonuc['id'];
                if ($id === 0 && $faz8bHazir && $ilkSureDk !== null) {
                    $sureSonuc = pdks_faz8b_cavus_normal_sure_guncelle($kayitId, $ilkSureDk, (int)$auth_user['id'], $pdo);
                    if (!$sureSonuc['ok']) {
                        $errors[] = 'Çavuş profili oluşturuldu ancak ilk normal süre kaydedilemedi: ' . ($sureSonuc['hata'] ?? 'Bilinmeyen hata.');
                        $id = $kayitId;
                        $st = $pdo->prepare('SELECT * FROM foremen WHERE id = ?');
                        $st->execute([$id]);
                        $record = $st->fetch() ?: $record;
                    } else {
                        header('Location: cavus_form.php?id=' . $kayitId . '&ok=' . urlencode('Çavuş oluşturuldu.'));
                        exit;
                    }
                } else {
                    header('Location: cavus_form.php?id=' . $kayitId . '&ok=' . urlencode('Çavuş kaydedildi.'));
                    exit;
                }
            } else {
                $errors = $sonuc['hatalar'] ?? [$sonuc['hata'] ?? 'Kaydedilemedi.'];
                $record = array_merge($record, $veri, ['id' => $id]);
            }
        } else {
            $record = array_merge($record, $veri, ['id' => $id]);
        }
    } elseif ($action === 'sure_kaydet' && $id > 0) {
        require_pdks_gunluk('foremen');
        if (!$sureHazir) {
            $errors[] = 'Günlük işçi ve işlem geçmişi tabloları hazır olmadığı için süre tanımı kaydedilemiyor.';
        } else {
            $scope = trim((string)($_POST['kapsam'] ?? ''));
            $sureInput['saat'] = trim((string)($_POST['saat'] ?? ''));
            $sureInput['valid_from'] = trim((string)($_POST['valid_from'] ?? ''));
            $sureInput['valid_to'] = trim((string)($_POST['valid_to'] ?? ''));
            $sureInput['kapsam'] = $scope;
            $sureInput['gerekce'] = trim((string)($_POST['gerekce'] ?? ''));

            $dakika = pdks_faz8b_saat_girdi_dk($sureInput['saat']);
            $tarihGecerli = static function (string $tarih): bool {
                $d = DateTimeImmutable::createFromFormat('!Y-m-d', $tarih);
                return $d !== false && $d->format('Y-m-d') === $tarih;
            };
            if ($dakika === null || $dakika < PDKS_FAZ8B_SURE_MIN_DK || $dakika > PDKS_FAZ8B_SURE_MAX_DK) {
                $errors[] = 'Normal günlük çalışma süresi 1-24 saat arasında olmalıdır.';
            }
            if (!$tarihGecerli($sureInput['valid_from'])) {
                $errors[] = 'Başlangıç tarihi geçerli bir tarih olmalıdır.';
            }
            if (!in_array($scope, ['suresiz', 'aralik'], true)) {
                $errors[] = 'Süre kapsamı geçersiz.';
            }
            $validTo = null;
            if ($scope === 'aralik') {
                if (!$tarihGecerli($sureInput['valid_to'])) {
                    $errors[] = 'Bitiş tarihi geçerli bir tarih olmalıdır.';
                } else {
                    $validTo = $sureInput['valid_to'];
                    if ($tarihGecerli($sureInput['valid_from']) && $validTo < $sureInput['valid_from']) {
                        $errors[] = 'Bitiş tarihi başlangıç tarihinden önce olamaz.';
                    }
                }
            }
            $gerekceUzunluk = function_exists('mb_strlen') ? mb_strlen($sureInput['gerekce'], 'UTF-8') : strlen($sureInput['gerekce']);
            if ($sureInput['gerekce'] === '' || $gerekceUzunluk > 500) {
                $errors[] = 'Gerekçe zorunludur ve en fazla 500 karakter olabilir.';
            }

            if (!$errors && $dakika !== null) {
                $sonuc = pdks_cavus_sure_kaydet(
                    $id,
                    $dakika,
                    $sureInput['valid_from'],
                    $validTo,
                    $sureInput['gerekce'],
                    (int)$auth_user['id'],
                    $pdo
                );
                if (!empty($sonuc['ok'])) {
                    $degisen = (int)($sonuc['oturum_sayisi'] ?? 0);
                    $isaretlenen = (int)($sonuc['isaretlenen'] ?? 0);
                    $mesaj = sprintf(
                        'Süre tanımı kaydedildi. %d mesainin süresi güncellendi ve önceki onayları kaldırıldı; %d taslak hakediş yeniden hesaplanmalı.',
                        $degisen,
                        $isaretlenen
                    );
                    header('Location: cavus_form.php?id=' . $id . '&sure_ok=' . urlencode($mesaj));
                    exit;
                }
                $errors[] = $sonuc['hata'] ?? 'Süre tanımı kaydedilemedi.';
            }
        }
    } elseif ($action === 'aktiflik' && $id > 0) {
        $aktif = ($_POST['aktif'] ?? '') === '1';
        $sonuc = pdks_gunluk_cavus_aktiflik($id, $aktif, (int)$auth_user['id'], $pdo);
        if ($sonuc['ok']) {
            header('Location: cavus_form.php?id=' . $id . '&ok=' . urlencode($aktif ? 'Çavuş aktifleştirildi.' : 'Çavuş pasifleştirildi.'));
            exit;
        }
        $errors[] = $sonuc['hata'] ?? 'İşlem yapılamadı.';
    }
}

$basari = isset($_GET['ok']) ? trim((string)$_GET['ok']) : '';
$sureBasari = isset($_GET['sure_ok']) ? trim((string)$_GET['sure_ok']) : '';
$gecmis = [];
$bugunDk = null;
if ($id > 0 && $sureHazir) {
    try {
        $gecmis = pdks_cavus_sure_gecmis($id, $pdo);
        $bugunDk = pdks_cavus_sure_dk($id, date('Y-m-d'), $pdo);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $sureInput['saat'] = pdks_faz8b_dk_girdi($bugunDk);
        }
    } catch (Throwable $e) {
        $sureHazir = false;
        $gecmis = [];
    }
}

render_header($id > 0 ? (string)$record['name'] : 'Yeni Çavuş');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head">
    <h1><?= $id > 0 ? h((string)$record['name']) : 'Yeni Çavuş' ?></h1>
    <div class="page-head-actions"><a href="cavuslar.php" class="btn btn-geri">← Çavuşlar</a></div>
</div>

<?php if ($basari !== ''): ?><div class="flash flash-success"><?= h($basari) ?></div><?php endif; ?>
<?php if ($sureBasari !== ''): ?><div class="flash flash-success"><?= h($sureBasari) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="flash flash-error"><?= h((string)$e) ?></div><?php endforeach; ?>

<div class="card" style="padding:18px 20px;margin-bottom:20px">
    <h2 style="margin-top:0;font-size:1rem">Çavuş profili</h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <?php if ($id > 0): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
        <div class="pdks-form-grid">
            <label><span class="form-label">Çavuş Kodu *</span><input type="text" name="code" required maxlength="20" value="<?= h((string)$record['code']) ?>"></label>
            <label><span class="form-label">Ad Soyad *</span><input type="text" name="name" required maxlength="150" value="<?= h((string)$record['name']) ?>" placeholder="Çavuş adı"></label>
            <label><span class="form-label">Telefon</span><input type="tel" name="phone" maxlength="30" value="<?= h((string)($record['phone'] ?? '')) ?>"></label>
            <label><span class="form-label">Durum</span><span style="display:flex;align-items:center;gap:8px;margin-top:8px;font-weight:400"><input type="checkbox" name="is_active" value="1" <?= !empty($record['is_active']) ? 'checked' : '' ?>> Aktif</span></label>
            <label class="span-2"><span class="form-label">Notlar</span><textarea name="notes" rows="3" maxlength="2000"><?= h((string)($record['notes'] ?? '')) ?></textarea></label>
            <?php if ($id === 0 && $faz8bHazir): ?>
            <label><span class="form-label">İlk normal günlük çalışma süresi (saat) *</span><input type="text" inputmode="decimal" name="saat" required value="<?= h($sureInput['saat']) ?>" placeholder="9 veya 9:30"></label>
            <?php endif; ?>
        </div>
        <?php if ($id === 0 && $faz8bHazir): ?><p class="muted" style="font-size:.8rem;margin-top:8px">Tarihli ve geçmişe dönük süre tanımları, çavuş oluşturulduktan sonra bu ekrandan eklenir.</p><?php endif; ?>
        <button type="submit" class="btn btn-primary" style="margin-top:14px"><?= $id > 0 ? 'Profili Kaydet' : 'Çavuşu Oluştur' ?></button>
    </form>
</div>

<?php if ($id > 0): ?>
<?php if ($sureHazir): ?>
<div class="card" style="padding:18px 20px;margin-bottom:20px">
    <h2 style="margin-top:0;font-size:1rem">Tarihli normal çalışma süresi</h2>
    <?php if ($bugunDk !== null): ?><p style="margin-top:0"><strong>Bugün geçerli:</strong> <?= h(pdks_faz8b_dakika_etiket((int)$bugunDk)) ?></p><?php endif; ?>
    <p class="muted" style="font-size:.86rem">Seçilen tarihler dahil olmak üzere geçmişe dönük uygulanır ve KADIN/ERKEK/RAMPACI/SERITCI dahil tüm işçi türlerini etkiler. Bu süre, seçilen günlerde fiyat dönemindeki Tam mesai ve fazla mesai başlangıç saatlerinin önüne geçer; ücretler değişmez. Normal sürenin üstündeki 15 dakika tolerans kuralı geçerlidir. Çakışan tanımlarda en son kaydedilen tanım geçerlidir. Etkilenen günlerin mesai onayları yeniden verilmelidir. Kesinleşmiş hakediş bulunan dönemlerde kaydetmeden önce ilgili hakedişleri yeniden açın.</p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="sure_kaydet">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="pdks-form-grid">
            <label><span class="form-label">Normal çalışma süresi (saat) *</span><input type="text" inputmode="decimal" name="saat" required value="<?= h($sureInput['saat']) ?>" placeholder="9 veya 9:30"></label>
            <label><span class="form-label">Başlangıç tarihi (dahil) *</span><input type="date" name="valid_from" required value="<?= h($sureInput['valid_from']) ?>"></label>
            <label><span class="form-label">Uygulama süresi *</span><select name="kapsam" id="sure-kapsam" required><option value="suresiz" <?= $sureInput['kapsam'] === 'suresiz' ? 'selected' : '' ?>>Bu tarihten itibaren süresiz</option><option value="aralik" <?= $sureInput['kapsam'] === 'aralik' ? 'selected' : '' ?>>Seçilen tarih aralığı</option></select></label>
            <label id="sure-bitis-alani"><span class="form-label">Bitiş tarihi (dahil) *</span><input type="date" name="valid_to" id="sure-bitis" value="<?= h($sureInput['valid_to']) ?>"></label>
            <label class="span-2"><span class="form-label">Gerekçe * <small class="muted">(en fazla 500 karakter)</small></span><textarea name="gerekce" rows="3" maxlength="500" required><?= h($sureInput['gerekce']) ?></textarea></label>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:14px">Süre tanımını kaydet ve oturumları yeniden değerlendir</button>
    </form>
</div>

<div class="card" style="padding:18px 20px;margin-bottom:20px">
    <h2 style="margin-top:0;font-size:1rem">Süre tanımı geçmişi</h2>
    <?php if (!$gecmis): ?><p class="muted" style="margin-bottom:0">Henüz tarihli süre tanımı yok.</p><?php else: ?>
    <div style="overflow-x:auto">
        <table class="pdks-table" style="width:100%;min-width:680px">
            <thead><tr><th>Kaydedilme</th><th>Geçerlilik</th><th>Süre</th><th>Gerekçe</th><th>Kaydeden</th></tr></thead>
            <tbody>
            <?php foreach ($gecmis as $satir):
                $bitis = $satir['valid_to'] ?? null;
                $aralik = (string)($satir['valid_from'] ?? '') . ' – ' . ($bitis ? (string)$bitis : 'Süresiz');
                $kaydeden = trim((string)($satir['user_name'] ?? ''));
                if ($kaydeden === '') $kaydeden = '#' . (int)($satir['user_id'] ?? 0);
            ?>
                <tr>
                    <td><?= h((string)($satir['created_at'] ?? '')) ?></td>
                    <td><?= h($aralik) ?></td>
                    <td><?= h(pdks_faz8b_dakika_etiket((int)($satir['normal_work_minutes'] ?? 0))) ?></td>
                    <td><?= h((string)($satir['gerekce'] ?? '')) ?></td>
                    <td><?= h($kaydeden) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="card" style="padding:16px 18px;margin-bottom:20px"><p class="muted" style="margin:0">Günlük işçi ve işlem geçmişi tabloları hazır olmadığı için tarihli süre tanımları kullanılamıyor.</p></div>
<?php endif; ?>

<div class="card" style="padding:16px 18px;margin-bottom:20px">
    <h2 style="margin-top:0;font-size:1rem">Durum</h2>
    <form method="post" style="display:inline">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="aktiflik"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="aktif" value="<?= !empty($record['is_active']) ? '0' : '1' ?>">
        <button type="submit" class="btn <?= !empty($record['is_active']) ? '' : 'btn-primary' ?>"><?= !empty($record['is_active']) ? '⏸ Pasifleştir' : '▶ Aktifleştir' ?></button>
    </form>
    <p class="muted" style="margin-top:10px;font-size:.85rem">Çavuş kaydı silinemez — yalnız aktif/pasif yapılabilir.</p>
</div>
<?php endif; ?>

<script>
(() => {
    const scope = document.getElementById('sure-kapsam');
    const end = document.getElementById('sure-bitis');
    const wrap = document.getElementById('sure-bitis-alani');
    if (!scope || !end || !wrap) return;
    const update = () => {
        const interval = scope.value === 'aralik';
        end.required = interval;
        end.disabled = !interval;
        wrap.hidden = !interval;
    };
    scope.addEventListener('change', update);
    update();
})();
</script>
<?php render_footer(); ?>
