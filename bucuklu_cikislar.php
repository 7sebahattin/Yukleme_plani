<?php
// =========================================================
// bucuklu_cikislar.php — Buçuklu çıkışlar (v503, sahip isteği)
//
// Çıkış dakikası :30 olan (örn. 20:30) dönemleri tarih aralığıyla listeler; satıra tıklayınca çıkış saati
// düzeltilir. YALNIZ yönetici (Kart Hareketleri düzenlemesiyle AYNI kapı).
// İKİNCİ YAZMA YOLU YOK: düzeltme pdks_faz8j_duzelt() çağrısıdır (aktif depo kapısı, kesin hakediş reddi, çakışma /
// zaman kuralları, taslak hakedişi needs_recalculation yapma, audit `puantaj_duzeltme`, CSRF burada).
// Dönem/mesai/kart/tip/giriş sunucuda DB'den okunur — istemciden yalnız period_id + yeni çıkış saati + gerekçe gelir.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_faz8h.php';
require_once __DIR__ . '/config/pdks_faz8j.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_gunluk('daily_reports');
if (!function_exists('is_admin') || !is_admin()) forbidden('Bu sayfa yalnızca sistem yöneticileri içindir.');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
$depo = function_exists('active_depot') ? (string)(active_depot() ?? '') : '';
$faz8jHazir = pdks_faz8j_sema_hazir($pdo);

$gecerliTarih = static fn($v): bool => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && strtotime($v) !== false;
$bitis = $gecerliTarih($_GET['bitis'] ?? null) ? (string)$_GET['bitis'] : date('Y-m-d');
$baslangic = $gecerliTarih($_GET['baslangic'] ?? null) ? (string)$_GET['baslangic'] : date('Y-m-d', strtotime('-7 days'));
if ($baslangic > $bitis) { [$baslangic, $bitis] = [$bitis, $baslangic]; }
if ((strtotime($bitis) - strtotime($baslangic)) / 86400 > 366) $baslangic = date('Y-m-d', strtotime($bitis . ' -366 days'));
$geriUrl = 'bucuklu_cikislar.php?' . http_build_query(['baslangic' => $baslangic, 'bitis' => $bitis]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bucuklu_duzelt') {
    csrf_check($_POST['csrf'] ?? null);
    $pid = filter_var($_POST['period_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $sonuc = ['ok' => false, 'hata' => 'Geçersiz mesai dönemi.'];
    if ($pid && $faz8jHazir && $depo !== '') {
        $st = $pdo->prepare(
            "SELECT p.id, p.session_id, p.worker_card_id, p.worker_type_id_snapshot, p.entry_time, p.exit_time
               FROM daily_worker_work_periods p
               JOIN daily_work_sessions s ON s.id = p.session_id
              WHERE p.id = ? AND s.depo = ? AND p.depo_snapshot = ? AND p.exit_time IS NOT NULL AND " . pdks_gunluk_faz8j_etkin_kosul($pdo, 'p')
        );
        $st->execute([(int)$pid, $depo, $depo]);
        $d = $st->fetch();
        $yeniSaat = trim((string)($_POST['exit_clock'] ?? ''));
        if (!$d) {
            $sonuc = ['ok' => false, 'hata' => 'Dönem bulunamadı, iptal edilmiş ya da çıkışı yok.'];
        } elseif (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $yeniSaat)) {
            $sonuc = ['ok' => false, 'hata' => 'Geçerli bir çıkış saati girin (SS:DD).'];
        } else {
            $sonuc = pdks_faz8j_duzelt([
                'period_id' => (int)$d['id'],
                'session_id' => (int)$d['session_id'],
                'depo' => $depo,
                'worker_card_id' => (int)$d['worker_card_id'],
                'worker_type_id' => (int)$d['worker_type_id_snapshot'],
                'entry_date' => substr((string)$d['entry_time'], 0, 10),
                'entry_clock' => substr((string)$d['entry_time'], 11, 5),
                'exit_date' => substr((string)$d['exit_time'], 0, 10),
                'exit_clock' => $yeniSaat,
                'reason' => (string)($_POST['reason'] ?? ''),
                'note' => 'Buçuklu çıkış düzeltmesi (bucuklu_cikislar.php): ' . substr((string)$d['exit_time'], 11, 5) . ' → ' . $yeniSaat,
            ], (int)$auth_user['id'], $pdo);
        }
    }
    set_flash($sonuc['ok'] ? 'success' : 'error', $sonuc['ok'] ? 'Çıkış saati düzeltildi.' : (string)$sonuc['hata']);
    header('Location: ' . $geriUrl); exit;
}

$satirlar = [];
if ($faz8jHazir && $depo !== '') {
    $st = $pdo->prepare(
        "SELECT p.id, p.session_id, p.entry_time, p.exit_time, p.worker_type_name_snapshot, w.card_no,
                s.work_date, s.foreman_name_snapshot
           FROM daily_worker_work_periods p
           JOIN daily_work_sessions s ON s.id = p.session_id
           JOIN worker_cards w ON w.id = p.worker_card_id
          WHERE s.depo = ? AND p.depo_snapshot = ? AND s.work_date BETWEEN ? AND ?
            AND p.exit_time IS NOT NULL AND SUBSTR(p.exit_time, 15, 2) = '30'
            AND " . pdks_gunluk_faz8j_etkin_kosul($pdo, 'p') . "
          ORDER BY s.work_date DESC, p.exit_time DESC, p.id DESC LIMIT 500"
    );
    $st->execute([$depo, $depo, $baslangic, $bitis]);
    $satirlar = $st->fetchAll();
}

render_header('Buçuklu Çıkışlar');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>
<div class="page-head">
    <h1>🕧 Buçuklu Çıkışlar</h1>
    <div class="page-head-actions">
        <a href="personel_takip.php" class="btn btn-geri btn-geri-ptak">← Personel Takibi</a>
    </div>
</div>

<form method="get" class="pdks-filter-bar" data-oto-filtre>
    <input type="date" name="baslangic" value="<?= h($baslangic) ?>" aria-label="Başlangıç">
    <input type="date" name="bitis" value="<?= h($bitis) ?>" aria-label="Bitiş">
    <noscript><button class="btn">Filtrele</button></noscript>
    <span class="pdks-badge pdks-badge-acik" title="Depo değiştirmek için üst çubuktaki depo seçiciyi kullanın">Depo: <?= h($depo !== '' ? $depo : '—') ?></span>
</form>

<?php if (!$faz8jHazir): ?>
<div class="card"><p>Puantaj düzeltme şeması henüz hazır değil.</p></div>
<?php elseif ($depo === ''): ?>
<div class="card"><p>Aktif depo seçilmelidir.</p></div>
<?php else: ?>
<p class="pdks-row-sub">Çıkış saatinin dakikası <strong>:30</strong> olan dönemler (<?= count($satirlar) ?> kayıt). Satıra tıklayıp çıkış saatini düzeltin; kesinleşmiş hakedişi olan mesai önce yeniden açılmalıdır.</p>
<div class="table-wrap">
<table class="table">
    <thead><tr><th>Tarih</th><th>Çavuş</th><th>Kart</th><th>İşçi tipi</th><th>Giriş</th><th>Çıkış</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($satirlar as $r): ?>
    <tr class="pdks-satir-link" tabindex="0" data-bck-ac="<?= (int)$r['id'] ?>" data-bck-baslik="<?= h($r['foreman_name_snapshot'] . ' · ' . $r['card_no']) ?>" data-bck-cikis="<?= h(substr((string)$r['exit_time'], 11, 5)) ?>">
        <td><?= h(date('d.m.Y', strtotime((string)$r['work_date']))) ?></td>
        <td><?= h($r['foreman_name_snapshot']) ?></td>
        <td><?= h($r['card_no']) ?></td>
        <td><?= h($r['worker_type_name_snapshot']) ?></td>
        <td><?= h(date('H:i', strtotime((string)$r['entry_time']))) ?></td>
        <td><strong><?= h(date('d.m H:i', strtotime((string)$r['exit_time']))) ?></strong></td>
        <td><a class="btn btn-sm" href="gunluk_isci_puantaj_detay.php?id=<?= (int)$r['session_id'] ?>">Mesai</a></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$satirlar): ?><tr><td colspan="7" class="pdks-row-sub">Bu aralıkta buçuklu çıkış yok.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>

<dialog id="bckDuzelt" class="pm-dialog isk-card-modal">
    <div class="pm-header"><h2 class="pm-title">Çıkış Saatini Düzelt</h2><button type="button" class="pm-close" onclick="this.closest('dialog').close()">✕</button></div>
    <form method="post" class="isk-card-modal-body" action="<?= h($geriUrl) ?>">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="bucuklu_duzelt">
        <input type="hidden" name="period_id" value="">
        <p class="pdks-row-sub" data-bck-baslik></p>
        <div class="pdks-form-grid">
            <label><span class="form-label">Yeni çıkış saati *</span><input type="time" name="exit_clock" required></label>
            <label class="span-2"><span class="form-label">Düzeltme nedeni *</span><textarea name="reason" maxlength="500" required></textarea></label>
        </div>
        <div class="isk-card-form-actions"><button class="btn btn-primary">Kaydet</button><button type="button" class="btn" onclick="this.closest('dialog').close()">Vazgeç</button></div>
    </form>
</dialog>
<script>
(function () {
    var dlg = document.getElementById('bckDuzelt');
    if (!dlg || typeof dlg.showModal !== 'function') return;
    var f = dlg.querySelector('form');
    function ac(tr) {
        f.elements.period_id.value = tr.dataset.bckAc;
        f.elements.exit_clock.value = tr.dataset.bckCikis || '';
        f.elements.reason.value = '';
        dlg.querySelector('[data-bck-baslik]').textContent = tr.dataset.bckBaslik || '';
        dlg.showModal();
        f.elements.exit_clock.focus();
    }
    document.addEventListener('click', function (e) {
        if (e.target.closest('a,button,input,select,textarea')) return;
        var tr = e.target.closest('tr[data-bck-ac]');
        if (tr) ac(tr);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        var tr = e.target.closest && e.target.closest('tr[data-bck-ac]');
        if (tr && e.target === tr) ac(tr);
    });
})();
</script>
<?php endif; ?>
<?php
if (function_exists('pdks_liste_ui_js')) pdks_liste_ui_js();
render_footer();
