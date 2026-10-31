<?php
// =========================================================
// personel_kayit_sec.php — Kayıt seçici (Mesai Değerlendirme / Manuel Çıkış)
//
// Mesai Değerlendirme ve Manuel Çıkış sayfaları gerçek bir kayıt kimliği ister
// (?session_id= / ?period_id=). Personel Takibi'nde bu iki sayfa KART olarak
// durur; kart bu seçiciyi açar, kullanıcı son kayıtlardan birini seçer.
// Salt okunur (yalnız SELECT); hedef sayfalar kendi yetki/depo kapılarını AYNEN
// korur — bu sayfa bypass için bir yol DEĞİLDİR. Kapı, eski hub'daki seçicinin
// birebir aynısıdır: ilgili Gör izni + hakediş "Çavuş fiyatları Gör".
// (Bu dosya role_pdks_routes()'ta tanımlı değildir; kapıyı kendisi uygular.)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
$auth_user = require_login();

$tur = ($_GET['tur'] ?? '') === 'cikis' ? 'cikis' : 'mesai';
$anahtar = $tur === 'cikis' ? 'manual_exit' : 'mesai';
if (!role_pdks_can($anahtar) || !pdks_hakedis_can('entitlements_finalize')) {
    forbidden('Bu sayfaya erişim yetkiniz yok.');
}

$satirlar = []; $kullanilamiyor = false;
$depo = trim((string)(function_exists('active_depot') ? active_depot() : ''));
if ($depo !== '' && function_exists('depo_sql_column')) {
    try {
        if ($tur === 'mesai') {
            [$kapsamSql, $kapsamPrm] = depo_sql_column('s.depo', ':pcs');
            $st = db()->prepare('SELECT s.id, s.foreman_name_snapshot, s.work_date FROM daily_work_sessions s WHERE s.depo = :pcs_active' . $kapsamSql . ' ORDER BY s.work_date DESC, s.id DESC LIMIT 30');
            $st->execute([':pcs_active' => $depo] + $kapsamPrm);
        } else {
            [$oturumSql, $oturumPrm] = depo_sql_column('s.depo', ':pcm');
            [$donemSql, $donemPrm] = depo_sql_column('p.depo_snapshot', ':pcp');
            $st = db()->prepare("SELECT p.id, p.session_id, w.card_no, s.foreman_name_snapshot, s.work_date FROM daily_worker_work_periods p JOIN daily_work_sessions s ON s.id = p.session_id JOIN worker_cards w ON w.id = p.worker_card_id WHERE s.depo = :pcm_active AND p.depo_snapshot = :pcp_active AND p.exit_time IS NULL AND p.exit_event_id IS NULL AND p.status IN ('open','legacy_unresolved')" . $oturumSql . $donemSql . ' AND ' . pdks_gunluk_faz8j_etkin_kosul(db(), 'p') . ' ORDER BY s.work_date DESC, p.id DESC LIMIT 30');
            $st->execute([':pcm_active' => $depo, ':pcp_active' => $depo] + $oturumPrm + $donemPrm);
        }
        $satirlar = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $kullanilamiyor = true; }
} else {
    $kullanilamiyor = true;
}

$baslik = $tur === 'cikis' ? 'Manuel Çıkış' : 'Mesai Değerlendirme';
render_header($baslik . ' — Kayıt Seç');
echo '<link rel="stylesheet" href="' . base_url() . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>
<div class="pdks-mobile-shell">
  <div class="pdks-mobile-hero">
    <h1><?= h($baslik) ?></h1>
    <a href="personel_takip.php" class="btn btn-geri btn-geri-ptak">← Personel Takibi</a>
  </div>
  <div class="card">
    <p class="muted"><?= $tur === 'cikis' ? 'Seçili deponun çıkışı bekleyen son 30 kaydı. Açmak istediğiniz kaydı seçin.' : 'Seçili deponun son 30 mesaisi. Açmak istediğiniz kaydı seçin.' ?></p>
    <?php if ($satirlar): ?>
    <ul class="pdks-kayit-sec">
    <?php foreach ($satirlar as $r): ?>
      <?php if ($tur === 'cikis'): ?>
      <li><a href="manuel_cikis.php?period_id=<?= (int)$r['id'] ?>&amp;session_id=<?= (int)$r['session_id'] ?>"><?= h($r['work_date']) ?> · <?= h($r['foreman_name_snapshot']) ?> · <?= h($r['card_no']) ?></a></li>
      <?php else: ?>
      <li><a href="mesai_degerlendirme.php?session_id=<?= (int)$r['id'] ?>"><?= h($r['work_date']) ?> · <?= h($r['foreman_name_snapshot']) ?></a></li>
      <?php endif; ?>
    <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="muted"><?= $kullanilamiyor
        ? ($tur === 'cikis' ? 'Çıkış bekleyen kayıtlar şu anda listelenemiyor. Depo seçimini ve modül kurulumunu kontrol edin.' : 'Mesai listesi şu anda kullanılamıyor. Depo seçimini ve modül kurulumunu kontrol edin.')
        : ($tur === 'cikis' ? 'Seçili depoda çıkışı bekleyen kayıt bulunamadı.' : 'Seçili depoda görüntülenebilecek mesai bulunamadı.') ?></p>
    <?php endif; ?>
  </div>
</div>
<?php render_footer(); ?>
