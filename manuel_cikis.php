<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/pdks_faz8e.php';
require_once __DIR__ . '/config/auth.php';

$auth_user = require_login();
require_pdks_hakedis('entitlements_finalize');
$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_hakedis_sayfa_kapisi($pdo);
pdks_faz8b_sayfa_kapisi($pdo);

$depo = active_depot() ?? '';
if ($depo === '') forbidden('Aktif depo seçilmelidir.');
$periodId = filter_var($_GET['period_id'] ?? $_POST['period_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$sessionId = filter_var($_GET['session_id'] ?? $_POST['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$periodId || !$sessionId) forbidden('Geçersiz mesai dönemi.');

$st = $pdo->prepare(
    "SELECT p.id, p.session_id, p.entry_time, p.exit_time, p.exit_event_id, p.status,
            p.worker_type_name_snapshot, s.work_date, s.depo, s.foreman_name_snapshot,
            w.card_no, w.uid_decimal, w.canonical_uid
       FROM daily_worker_work_periods p
       JOIN daily_work_sessions s ON s.id = p.session_id
       JOIN worker_cards w ON w.id = p.worker_card_id
      WHERE p.id = ? AND p.session_id = ? AND s.depo = ? AND p.depo_snapshot = ?"
);
$st->execute([(int)$periodId, (int)$sessionId, $depo, $depo]);
$period = $st->fetch();
if (!$period) forbidden('Mesai dönemi seçili depoda bulunamadı.');

$ay = trim((string)($_GET['ay'] ?? $_POST['ay'] ?? ''));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ay)) $ay = substr((string)$period['work_date'], 0, 7);
$cavusId = filter_var($_GET['cavus'] ?? $_POST['cavus'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$geriUrl = 'cavus_toplu_dokum_detay.php?' . http_build_query([
    'session_id' => (int)$sessionId, 'ay' => $ay, 'cavus' => $cavusId ?: null,
]);
$open = $period['exit_time'] === null && $period['exit_event_id'] === null
    && in_array($period['status'], ['open', 'legacy_unresolved'], true);
$stEntitlement = $pdo->prepare("SELECT status FROM foreman_daily_entitlements WHERE session_id = ?");
$stEntitlement->execute([(int)$sessionId]);
$finalEntitlement = $stEntitlement->fetchColumn() === 'final';
$errors = [];
$tarih = (string)($_POST['cikis_tarihi'] ?? $period['work_date']);
$saat = (string)($_POST['cikis_saati'] ?? '');
$neden = (string)($_POST['neden'] ?? '');
$aciklama = (string)($_POST['aciklama'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    if (!$open) {
        $errors[] = 'Bu mesai dönemi zaten tamamlanmış.';
    } elseif (($_POST['onay'] ?? '') !== '1') {
        $errors[] = 'Kaydı onaylayın.';
    } else {
        $result = pdks_faz8e_manuel_cikis_kaydet(
            (int)$periodId, $depo, $tarih, $saat, $neden, $aciklama,
            (int)$auth_user['id'], $pdo
        );
        if ($result['ok']) {
            set_flash('success', 'Manuel çıkış kaydedildi. Finansal durum Faz 8B kurallarına göre yeniden değerlendirilmelidir.');
            header('Location: ' . $geriUrl);
            exit;
        }
        $errors[] = $result['hata'] ?? 'Manuel çıkış kaydedilemedi.';
    }
}

render_header('Manuel Çıkış');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
?>
<div class="page-head">
    <h1>Manuel Çıkış Yap</h1>
    <a class="btn btn-ghost" href="<?= h($geriUrl) ?>">← Kart Dökümüne Dön</a>
</div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= h($error) ?></div><?php endforeach; ?>
<?php if (!$open): ?>
<div class="flash flash-error">Bu dönem zaten tamamlanmış; manuel çıkış yapılamaz.</div>
<?php elseif ($finalEntitlement): ?>
<div class="flash flash-error">Bu mesainin hakedişi KESİN. Manuel çıkış için hakediş önce muhasebe/yönetici tarafından yeniden açılmalıdır.</div>
<?php else: ?>
<div class="card" style="padding:18px;max-width:640px">
    <p><strong>Kart No:</strong> <?= h($period['card_no']) ?></p>
    <p><strong>UID:</strong> <?= h(trim((string)$period['uid_decimal']) !== '' ? $period['uid_decimal'] : $period['canonical_uid']) ?></p>
    <p><strong>Çavuş:</strong> <?= h($period['foreman_name_snapshot']) ?></p>
    <p><strong>İşçi Tipi:</strong> <?= h($period['worker_type_name_snapshot']) ?></p>
    <p><strong>Giriş Tarih / Saat:</strong> <?= h(date('d.m.Y H:i:s', strtotime($period['entry_time']))) ?></p>
    <form method="post" id="manuelCikisForm">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="period_id" value="<?= (int)$periodId ?>">
        <input type="hidden" name="session_id" value="<?= (int)$sessionId ?>">
        <input type="hidden" name="ay" value="<?= h($ay) ?>">
        <input type="hidden" name="cavus" value="<?= (int)$cavusId ?>">
        <label class="form-label" for="cikisTarihi">Çıkış tarihi</label>
        <input id="cikisTarihi" type="date" name="cikis_tarihi" value="<?= h($tarih) ?>" required>
        <label class="form-label" for="cikisSaati">Çıkış saati</label>
        <input id="cikisSaati" type="time" name="cikis_saati" value="<?= h($saat) ?>" required>
        <label class="form-label" for="neden">Neden</label>
        <select id="neden" name="neden" required>
            <option value="">Seçin</option>
            <?php foreach (pdks_faz8e_nedenler() as $secenek): ?>
            <option value="<?= h($secenek) ?>" <?= $neden === $secenek ? 'selected' : '' ?>><?= h($secenek) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="form-label" for="aciklama">Açıklama (Diğer için zorunlu)</label>
        <textarea id="aciklama" name="aciklama" maxlength="1000" rows="3"><?= h($aciklama) ?></textarea>
        <label style="display:block;margin:14px 0">
            <input type="checkbox" name="onay" value="1" required>
            <strong>Bu çıkış tarih/saatini ve nedeni doğruluyorum.</strong>
        </label>
        <p class="muted">Bu işlem yalnız çıkış saatini tamamlar. Tam/Yarım/Fazla Mesai kararı mevcut değerlendirme kurallarına bağlıdır.</p>
        <button class="btn btn-primary" type="submit">Manuel Çıkışı Kaydet</button>
    </form>
</div>
<script>
document.getElementById('manuelCikisForm').addEventListener('submit', function (e) {
    if (!window.confirm('Manuel çıkışı bu tarih, saat ve nedenle kaydetmek istiyor musunuz?')) e.preventDefault();
});
</script>
<?php endif; ?>
<?php render_footer(); ?>
