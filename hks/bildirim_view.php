<?php
// HKS Bildirim Görüntüleme + Kontrol / Gönderim Güvenlik Akışı
declare(strict_types=1);
require_once __DIR__ . '/includes/hks_bootstrap.php';
$auth_user = require_login();
if (function_exists('can') && !can('hks.read') && !can('records.write')) {
    http_response_code(403); die('Erişim yetkiniz yok.');
}

$repo = new HksRepository(db());
$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: bildirimler.php'); exit; }

$n = $repo->getNotification($id);
if (!$n) { set_flash('error', 'Bildirim bulunamadı.'); header('Location: bildirimler.php'); exit; }

$can_write = !function_exists('can') || can('hks.write') || can('records.write');
$can_send  = function_exists('can') && can('hks.send');
$settings  = $repo->getSettings();

// ── POST işlemleri ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'];

    // İptal
    if ($action === 'cancel' && $can_write) {
        if (in_array($n['status'], ['draft','ready','checked','failed'], true)) {
            $repo->cancelNotification($id);
            audit_log_event('hks_notification_cancelled', 'hks_notifications', $id, null, ['status' => 'cancelled']);
            set_flash('success', 'Bildirim iptal edildi.');
        } else {
            set_flash('error', 'Bu durumdaki kayıt iptal edilemez.');
        }
        header('Location: bildirim_view.php?id=' . $id); exit;
    }

    // Kontrol Edildi olarak işaretle
    if ($action === 'check' && $can_write) {
        if (!in_array($n['status'], ['draft','ready','failed'], true)) {
            set_flash('error', 'Bu kayıt kontrol edilemez (durum: ' . $n['status'] . ').');
            header('Location: bildirim_view.php?id=' . $id); exit;
        }
        $errors = hks_validate_notification($n);
        audit_log_event('hks_notification_validated', 'hks_notifications', $id, null, ['error_count' => count($errors)]);
        if (!empty($errors)) {
            $repo->setValidation($id, $errors);
            set_flash('error', 'Eksik/hatalı alanlar var. Kayıt kontrol edildi olarak işaretlenemedi.');
        } else {
            $repo->markChecked($id, isset($auth_user['id']) ? (int)$auth_user['id'] : null);
            audit_log_event('hks_notification_checked', 'hks_notifications', $id, null, ['checked_by' => $auth_user['username'] ?? '']);
            set_flash('success', 'Bildirim "Kontrol Edildi" olarak işaretlendi.');
        }
        header('Location: bildirim_view.php?id=' . $id); exit;
    }

    // Canlı gönderim
    if ($action === 'send') {
        // Onay checkbox zorunlu
        if (empty($_POST['onay'])) {
            set_flash('error', 'Gönderim için onay kutusunu işaretlemelisiniz.');
            header('Location: bildirim_view.php?id=' . $id); exit;
        }
        // Tüm engelleyici koşullar boş olmalı
        $blockers = hks_send_blockers($n, $settings);
        if (!empty($blockers)) {
            set_flash('error', 'Gönderilemez: ' . implode(' ', $blockers));
            header('Location: bildirim_view.php?id=' . $id); exit;
        }

        // Mükerrer içerik uyarısı — kullanıcı zorlamadıysa engelle
        $dup = $repo->findContentDuplicateSent($n, $id, 30);
        if ($dup && empty($_POST['dup_ack'])) {
            audit_log_event('hks_notification_duplicate_blocked', 'hks_notifications', $id, null, ['matched' => $dup['local_no']]);
            set_flash('error', 'Son 30 günde benzer içerikli gönderilmiş kayıt var (' . $dup['local_no'] . '). Mükerrer onayı verilmedi.');
            header('Location: bildirim_view.php?id=' . $id); exit;
        }

        // Transaction + FOR UPDATE ile kilitle (çift tıklama / mükerrer engeli)
        $lock = $repo->lockForSend($id);
        if (!$lock['ok']) {
            if (!empty($lock['duplicate'])) {
                audit_log_event('hks_notification_duplicate_blocked', 'hks_notifications', $id, null, ['reason' => $lock['reason']]);
            }
            set_flash('error', $lock['reason'] ?? 'Gönderim kilidi alınamadı.');
            header('Location: bildirim_view.php?id=' . $id); exit;
        }

        audit_log_event('hks_notification_send_attempt', 'hks_notifications', $id, null, ['local_no' => $n['local_no']]);

        $client = new HksClient($repo);
        $payload = hks_notification_payload_preview($n);
        $result  = $client->saveBildirim($payload);

        if (!empty($result['ok'])) {
            $repo->finalizeSent(
                $id,
                $result['hks_bildirim_no'] ?? null,
                $result['hks_kunye_no'] ?? null,
                isset($result['data']) ? json_encode($result['data'], JSON_UNESCAPED_UNICODE) : null
            );
            audit_log_event('hks_notification_sent', 'hks_notifications', $id, null, ['hks_bildirim_no' => $result['hks_bildirim_no'] ?? '']);
            set_flash('success', 'Bildirim HKS\'ye gönderildi.');
        } elseif (!empty($result['pending'])) {
            // Servis eşlenmedi — kilidi geri al, hata değil
            $repo->revertToChecked($id);
            set_flash('info', $result['message'] ?? 'HKS servis eşlemesi tamamlanmadı. Gönderim yapılmadı.');
        } else {
            $repo->markFailed($id, $result['message'] ?? 'Bilinmeyen HKS hatası', isset($result['data']) ? json_encode($result['data'], JSON_UNESCAPED_UNICODE) : null);
            audit_log_event('hks_notification_failed', 'hks_notifications', $id, null, ['error' => $result['message'] ?? '']);
            set_flash('error', 'HKS gönderimi başarısız: ' . ($result['message'] ?? ''));
        }
        header('Location: bildirim_view.php?id=' . $id); exit;
    }
}

// ── Görüntüleme verisi ───────────────────────────────────
$validation_errors = !empty($n['validation_errors_json']) ? json_decode($n['validation_errors_json'], true) : [];
$live_send_on = $settings && (int)($settings['live_send_enabled'] ?? 0) === 1;
$blockers     = hks_send_blockers($n, $settings);
$dup_warn     = $repo->findContentDuplicateSent($n, $id, 30);
$creator      = $repo->userName(isset($n['created_by']) ? (int)$n['created_by'] : null);
$checker      = $repo->userName(isset($n['checked_by']) ? (int)$n['checked_by'] : null);
$preview      = hks_notification_payload_preview($n);

render_header('HKS Bildirim #' . $n['local_no']);
render_flash();
?>

<style>
.hks-badge { display:inline-block; padding:2px 8px; border-radius:12px; font-size:.78rem; font-weight:600; }
.hks-badge-draft     { background:#e5e7eb; color:#374151; }
.hks-badge-ready     { background:#dbeafe; color:#1e40af; }
.hks-badge-checked   { background:#ede9fe; color:#5b21b6; }
.hks-badge-pending   { background:#fef9c3; color:#854d0e; }
.hks-badge-sent      { background:#d1fae5; color:#065f46; }
.hks-badge-failed    { background:#fee2e2; color:#991b1b; }
.hks-badge-cancelled { background:#f3f4f6; color:#6b7280; }
.hks-statebox { border-radius:8px; padding:12px 16px; margin-bottom:12px; font-size:.9rem; border:1px solid; }
.hks-sb-draft     { background:#f9fafb; border-color:#d1d5db; color:#374151; }
.hks-sb-ready     { background:#eff6ff; border-color:#93c5fd; color:#1e40af; }
.hks-sb-checked   { background:#f5f3ff; border-color:#c4b5fd; color:#5b21b6; }
.hks-sb-pending   { background:#fefce8; border-color:#fde047; color:#854d0e; }
.hks-sb-sent      { background:#f0fdf4; border-color:var(--success); color:#065f46; }
.hks-sb-failed    { background:#fef2f2; border-color:var(--danger); color:#991b1b; }
.hks-sb-cancelled { background:#f3f4f6; border-color:#d1d5db; color:#6b7280; }
.hks-check-table { width:100%; border-collapse:collapse; font-size:.88rem; }
.hks-check-table td { padding:6px 10px; border-bottom:1px solid var(--border); }
.hks-check-table td:first-child { color:var(--muted); width:42%; }
.hks-check-table td:last-child { font-weight:600; }
.hks-send-panel { background:var(--card); border:2px solid #c4b5fd; border-radius:10px; padding:18px 20px; margin:16px 0; }
.hks-blocker-list { margin:8px 0 0; padding-left:20px; }
.hks-blocker-list li { margin:3px 0; color:#991b1b; font-size:.88rem; }
.hks-onay-row { display:flex; align-items:flex-start; gap:8px; margin:14px 0; font-size:.9rem; }
.hks-onay-row input { margin-top:3px; }
</style>

<div style="max-width:1000px;margin:0 auto">

<div class="page-head" style="margin-top:16px">
    <div>
        <h1>🏛 <?= hks_h($n['local_no']) ?></h1>
        <?= hks_status_badge($n['status']) ?>
        <?php if ($n['status'] === 'sent' && $n['hks_bildirim_no']): ?>
        <span style="font-size:.85rem;color:var(--muted)">HKS No: <?= hks_h($n['hks_bildirim_no']) ?></span>
        <?php endif; ?>
    </div>
    <div class="page-head-actions">
        <a href="bildirimler.php" class="btn btn-ghost">← Liste</a>
        <?php
        // Yeni e-Bildirim kayıtları 3-adımlı operasyon formunda düzenlenir (checked dahil);
        // eski kayıtlar eski formda kalır.
        $is_eb_record  = hks_is_ebildirim_record($n);
        $edit_statuses = $is_eb_record ? ['draft','ready','checked','failed'] : ['draft','ready','failed'];
        $edit_url      = $is_eb_record ? ('operasyon/bildirim_yeni.php?id=' . $id) : ('bildirim_form.php?id=' . $id);
        ?>
        <?php if ($can_write && in_array($n['status'], $edit_statuses, true)): ?>
        <a href="<?= hks_h($edit_url) ?>" class="btn btn-ghost">✏️ Düzenle</a>
        <?php endif; ?>
    </div>
</div>

<!-- Duruma özel renkli kutu -->
<?php
$state_msgs = [
    'draft'        => ['hks-sb-draft',     'Bu kayıt henüz taslak. HKS\'ye gönderilemez. Eksik alanları tamamlayın.'],
    'ready'        => ['hks-sb-ready',     'Zorunlu alanlar tamamlandı. Gönderimden önce "Kontrol Edildi" olarak işaretlenmeli.'],
    'checked'      => ['hks-sb-checked',   'Bu kayıt kontrol edildi. Yetkili kullanıcı (hks.send) HKS\'ye gönderebilir.'],
    'send_pending' => ['hks-sb-pending',   'Bu kayıt gönderim için kilitlendi. Lütfen bekleyin.'],
    'sent'         => ['hks-sb-sent',      'Bu kayıt HKS\'ye gönderildi. Tekrar gönderilemez.'],
    'failed'       => ['hks-sb-failed',    'HKS gönderimi başarısız. Düzeltip yeniden kontrol edin (otomatik tekrar yapılmaz).'],
    'cancelled'    => ['hks-sb-cancelled', 'Bu kayıt iptal edildi. Gönderilemez.'],
];
$sm = $state_msgs[$n['status']] ?? null;
if ($sm): ?>
<div class="hks-statebox <?= $sm[0] ?>"><?= hks_h($sm[1]) ?></div>
<?php endif; ?>

<!-- Doğrulama hataları -->
<?php if (!empty($validation_errors)): ?>
<div class="hks-statebox hks-sb-failed">
    <strong>⚠️ Eksik / Hatalı Alanlar — HKS'ye gönderilemez:</strong>
    <ul style="margin:6px 0 0;padding-left:18px">
        <?php foreach ($validation_errors as $e): ?>
        <li><?= hks_h($e) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Son hata -->
<?php if ($n['status'] === 'failed' && $n['last_error']): ?>
<div class="hks-statebox hks-sb-failed">
    <strong>Son HKS Hatası:</strong> <?= hks_h($n['last_error']) ?>
</div>
<?php endif; ?>

<!-- Mükerrer uyarısı -->
<?php if ($dup_warn && !in_array($n['status'], ['sent','cancelled'], true)): ?>
<div class="hks-statebox hks-sb-pending">
    🔁 <strong>Mükerrer uyarısı:</strong> Son 30 günde aynı firma/ürün/miktar/belge ile gönderilmiş bir kayıt var
    (<?= hks_h($dup_warn['local_no']) ?>). Göndermeden önce kontrol edin.
</div>
<?php endif; ?>

<!-- Detay kartları — resmi e-Bildirim alanlarına göre bölümlenmiş -->
<?php
// Bölüm kartı yardımcısı: boş alanlarda "—" gösterir.
$dir_label = ['giris' => 'Giriş', 'cikis' => 'Çıkış'][$n['direction'] ?? ''] ?? ($n['direction'] ?? '');
$toplam_tutar = hks_notification_total_amount($n);
$birim_fiyat_disp = (float)($n['birim_fiyat'] ?? 0) > 0
    ? number_format((float)$n['birim_fiyat'], 2, ',', '.') . ' ' . (trim((string)($n['para_birimi'] ?? 'TL')) ?: 'TL')
    : '';
$dogum_disp = !empty($n['dogum_tarihi']) ? date('d.m.Y', strtotime((string)$n['dogum_tarihi'])) : '';

if (!function_exists('hks_detail_card')):
function hks_detail_card(string $title, array $rows): void {
    echo '<div class="card" style="padding:16px">';
    echo '<div style="font-size:.78rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px">' . hks_h($title) . '</div>';
    foreach ($rows as $k => $v) {
        $v = trim((string)$v);
        echo '<div style="display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px solid var(--border);font-size:.88rem">';
        echo '<span style="color:var(--muted);white-space:nowrap">' . hks_h($k) . ':</span>';
        echo '<span style="font-weight:600;text-align:right;max-width:62%">' . ($v !== '' ? hks_h($v) : '<span style="color:var(--muted);font-weight:400">—</span>') . '</span>';
        echo '</div>';
    }
    echo '</div>';
}
endif;
?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;margin-bottom:16px">

    <?php
    // A) Bildirimciye Ait Bilgiler
    hks_detail_card('Bildirimciye Ait Bilgiler', [
        'TC / VKN'      => $n['bildirimci_tc_vkn'] ?? '',
        'Sıfat'         => $n['sifat'] ?? '',
        'Ad Soyad / Ünvan' => $n['firma'] ?? '',
        'Bildirim Türü' => $n['notification_type'] ?? '',
        'Yön'           => $dir_label,
    ]);

    // B) Karşı Taraf / Kimden veya Kime Bilgileri
    hks_detail_card('Karşı Taraf / Kimden veya Kime', [
        'Karşı Taraf Adı / Ünvan' => $n['alici_ad'] ?? '',
        'Karşı Taraf TC / VKN'    => $n['alici_tc_vkn'] ?? '',
        'Karşı Taraf Sıfatı'      => $n['karsi_sifat'] ?? '',
        'Yurt Dışı'               => (int)($n['yurt_disi'] ?? 0) === 1 ? 'Evet' : '',
        'GSM'                     => $n['gsm'] ?? '',
        'Doğum Tarihi'            => $dogum_disp,
        'E-posta'                 => $n['eposta'] ?? '',
    ]);

    // C) Referans Künye
    hks_detail_card('Referans Künye', [
        'Künye No' => $n['reference_kunye_no'] ?? '',
    ]);

    // D) Mala İlişkin Bilgiler
    hks_detail_card('Mala İlişkin Bilgiler', [
        'Malın Niteliği' => $n['malin_niteligi'] ?? '',
        'Malın Türü'     => $n['malin_turu'] ?? '',
        'Malın Adı'      => $n['urun'] ?? '',
        'Malın Cinsi'    => $n['urun_cinsi'] ?? '',
        'Miktar'         => $n['miktar'] ? number_format((float)$n['miktar'], 3, ',', '.') . ' ' . $n['birim'] : '',
        'Birim Fiyat'    => $birim_fiyat_disp,
        'Toplam Tutar'   => $toplam_tutar,
        'Üretici Adı'    => $n['uretici_ad'] ?? '',
        'Üretici TC/VKN' => $n['uretici_tc_vkn'] ?? '',
        'Analize Gönder' => (int)($n['analize_gonder'] ?? 0) === 1 ? 'Evet' : '',
    ]);

    // E) Gideceği Yer / Sevk Bilgileri
    $gidecek_yer_disp = trim((string)($n['gidecek_yer'] ?? ''));
    if (mb_strtolower($gidecek_yer_disp) === mb_strtolower('Yurt Dışı') && !empty($n['ihracat_ulke'])) {
        $gidecek_yer_disp .= ' — ' . $n['ihracat_ulke'];
    }
    hks_detail_card('Gideceği Yer / Sevk Bilgileri', [
        'Gideceği Yer'         => $gidecek_yer_disp,
        'Gid. Yer Sahibi TC/VKN' => $n['gidecek_sahibi_tc'] ?? '',
        'Kayıtlı Değil'        => (int)($n['gidecek_kayitli_degil'] ?? 0) === 1 ? 'Evet' : '',
        'Araç Plaka'           => $n['arac_plaka'] ?? '',
        'Belge No'             => $n['belge_no'] ?? '',
        'Belge Tipi'           => $n['belge_tipi'] ?? '',
        'Depo / Şube'          => $n['depo'] ?? '',
        'İl'                   => $n['il'] ?? '',
        'İlçe'                 => $n['ilce'] ?? '',
        'Belde'                => $n['belde'] ?? '',
        'Sevk Tarihi'          => $n['sevk_tarihi'] ? date('d.m.Y', strtotime((string)$n['sevk_tarihi'])) : '',
    ]);

    // F) Durum / Kontrol
    hks_detail_card('Durum / Kontrol', [
        'Durum'           => hks_status_label($n['status']),
        'Oluşturan'       => $creator,
        'Kontrol Eden'    => $checker,
        'Kontrol Zamanı'  => !empty($n['checked_at']) ? date('d.m.Y H:i', strtotime((string)$n['checked_at'])) : '',
        'Gönderim Zamanı' => !empty($n['sent_at']) ? date('d.m.Y H:i', strtotime((string)$n['sent_at'])) : '',
        'HKS Bildirim No' => $n['hks_bildirim_no'] ?? '',
        'HKS Künye No'    => $n['hks_kunye_no'] ?? '',
    ]);
    ?>

</div>

<!-- Teknik Detay (accordion) — JSON ana ekrana basılmaz -->
<?php if (!empty($n['request_json']) || !empty($n['response_json']) || !empty($n['validation_errors_json'])): ?>
<details class="card" style="padding:0;margin-bottom:16px">
    <summary style="padding:12px 16px;cursor:pointer;font-weight:600;font-size:.9rem;color:var(--muted)">🔧 Teknik Detay (JSON)</summary>
    <div style="padding:0 16px 14px">
        <?php foreach ([
            'Doğrulama (validation_errors_json)' => $n['validation_errors_json'] ?? '',
            'İstek (request_json)'               => $n['request_json'] ?? '',
            'Yanıt (response_json)'              => $n['response_json'] ?? '',
        ] as $lbl => $jsonv): if (trim((string)$jsonv) === '') continue; ?>
        <div style="margin-top:10px">
            <div style="font-size:.8rem;font-weight:600;color:var(--muted);margin-bottom:4px"><?= hks_h($lbl) ?></div>
            <pre style="background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:10px;overflow:auto;font-size:.78rem;margin:0;max-height:280px"><?= hks_h($jsonv) ?></pre>
        </div>
        <?php endforeach; ?>
    </div>
</details>
<?php endif; ?>

<!-- HKS Gönderim Önizlemesi — yalnızca e-Bildirim kayıtlarında -->
<?php if ($is_eb_record):
    $pay_check  = hks_validate_bildirim_payload_mapping($n, $settings ?: []);
    $pay_body   = hks_build_bildirim_kaydet_payload($n, $settings ?: []);
    $pay_masked = hks_mask_payload_sensitive_fields($pay_body);
    $pay_json   = json_encode($pay_masked, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // Önizleme üretimi audit'e — kimlik bilgisi yok, yalnızca özet hash.
    audit_log_event('hks_payload_preview_generated', 'hks_notifications', $id, null, [
        'ready'        => $pay_check['ready'] ? 1 : 0,
        'error_count'  => count($pay_check['errors']),
        'payload_hash' => substr(hash('sha256', (string)$pay_json), 0, 16),
    ]);
    $live_send_on = $settings && (int)($settings['live_send_enabled'] ?? 0) === 1;
?>
<div class="card" style="padding:18px 20px;margin-bottom:16px">
    <h3 style="margin-top:0">📤 HKS Gönderim Önizlemesi</h3>

    <!-- 1) Gönderime hazır mı? -->
    <?php if (!$live_send_on): ?>
    <div class="hks-statebox hks-sb-draft">
        🔒 HKS canlı gönderim şu anda <strong>kapalı</strong>. Bu ekranda yalnızca gönderilecek veri önizleniyor; bu sprintte hiçbir koşulda HKS'ye gönderim yapılmaz.
    </div>
    <?php endif; ?>
    <?php if ($pay_check['ready']): ?>
    <div class="hks-statebox hks-sb-checked">✅ Payload hazır.<?= !$live_send_on ? ' Ancak canlı gönderim güvenlik nedeniyle kapalı.' : '' ?></div>
    <?php else: ?>
    <div class="hks-statebox hks-sb-failed">⚠️ HKS gönderimi için eksikler/uyumsuzluklar tamamlanmalıdır (aşağıda).</div>
    <?php endif; ?>

    <!-- 2) Eksikler -->
    <?php if (!empty($pay_check['errors'])): ?>
    <div style="margin-top:10px">
        <div style="font-weight:600;color:#991b1b;margin-bottom:4px">Eksik / Uyumsuz Alanlar</div>
        <ul style="margin:0;padding-left:18px">
            <?php foreach ($pay_check['errors'] as $e): ?><li style="color:#991b1b;font-size:.88rem"><?= hks_h($e) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- 3) Uyarılar -->
    <?php if (!empty($pay_check['warnings'])): ?>
    <div style="margin-top:10px">
        <div style="font-weight:600;color:#854d0e;margin-bottom:4px">Uyarılar</div>
        <ul style="margin:0;padding-left:18px">
            <?php foreach ($pay_check['warnings'] as $w): ?><li style="color:#854d0e;font-size:.88rem"><?= hks_h($w) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- 4) Mapping tablosu -->
    <div style="margin-top:14px;overflow:auto">
        <table class="hks-check-table" style="min-width:560px">
            <thead>
                <tr>
                    <th style="text-align:left">Bizim Alan</th>
                    <th style="text-align:left">HKS Alanı</th>
                    <th style="text-align:left">Değer</th>
                    <th style="text-align:left">Durum</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $st_map = [
                'ok'           => ['✓ Eşleşti', '#065f46'],
                'empty'        => ['— Boş (opsiyonel)', '#6b7280'],
                'missing'      => ['✗ Eksik (zorunlu)', '#991b1b'],
                'code_missing' => ['✗ HKS kodu yok', '#991b1b'],
                'ref_unsynced' => ['⚠ Liste senkron değil', '#854d0e'],
                'invalid'      => ['✗ Geçersiz format', '#991b1b'],
            ];
            foreach ($pay_check['mapping'] as $m):
                [$st_lbl, $st_col] = $st_map[$m['status']] ?? [$m['status'], '#374151'];
                $hks_field_disp = $m['hks_field'] . ($m['certain'] ? '' : ' (KESİN DEĞİL)');
            ?>
                <tr>
                    <td><?= hks_h($m['local']) ?></td>
                    <td style="font-family:monospace;font-size:.82rem<?= $m['certain'] ? '' : ';color:#854d0e' ?>"><?= hks_h($hks_field_disp) ?></td>
                    <td><?= trim((string)$m['value']) !== '' ? hks_h($m['value']) : '<span style="color:var(--muted)">—</span>' ?></td>
                    <td style="color:<?= $st_col ?>;white-space:nowrap;font-size:.84rem"><?= hks_h($st_lbl) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="font-size:.78rem;color:var(--muted);margin:8px 0 0">
            "KESİN DEĞİL" etiketli HKS alan adları WSDL'den doğrulanana kadar geçicidir; bu yüzden gönderim bloke edilir.
        </p>
    </div>

    <!-- 5) Teknik JSON (maskeli) -->
    <details style="margin-top:12px">
        <summary style="cursor:pointer;font-weight:600;font-size:.9rem;color:var(--muted)">🔧 Teknik SOAP Payload (maskeli JSON)</summary>
        <pre style="background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:10px;overflow:auto;font-size:.78rem;margin:8px 0 0;max-height:360px"><?= hks_h($pay_json) ?></pre>
        <p style="font-size:.76rem;color:var(--muted);margin:6px 0 0">UserName / Password / ServicePassword bu önizlemeye dahil edilmez; gerçek gönderim anında HksClient ekler.</p>
    </details>
</div>
<?php endif; ?>

<!-- Son Kontrol bloğu (düzenlenebilir durumlar) -->
<?php if ($can_write && in_array($n['status'], ['draft','ready','failed'], true)): ?>
<div class="card" style="padding:18px 20px;margin-bottom:16px">
    <h3 style="margin-top:0">📋 HKS'ye Gönderilmeden Önce Kontrol Edilecek Bilgiler</h3>
    <table class="hks-check-table">
        <?php foreach ($preview as $k => $v): ?>
        <tr><td><?= hks_h($k) ?></td><td><?= $v !== '' ? hks_h($v) : '<span style="color:var(--muted);font-weight:400">—</span>' ?></td></tr>
        <?php endforeach; ?>
        <tr><td>Oluşturan</td><td><?= hks_h($creator ?: '—') ?></td></tr>
        <tr><td>Son Güncelleme</td><td><?= hks_h($n['updated_at'] ? date('d.m.Y H:i', strtotime($n['updated_at'])) : '—') ?></td></tr>
    </table>

    <?php if (!empty($validation_errors)): ?>
    <div style="margin-top:14px;color:#991b1b;font-weight:600">❌ HKS'ye gönderilemez — eksik/hatalı alanları düzeltin.</div>
    <?php else: ?>
    <form method="post" style="margin-top:14px">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="check">
        <button type="submit" class="btn btn-primary">✅ Kontrol Edildi Olarak İşaretle</button>
        <span style="font-size:.82rem;color:var(--muted);margin-left:8px">Doğrulama tekrar çalışır; hata yoksa durum "Kontrol Edildi" olur.</span>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Gönderim paneli (checked durumu) -->
<?php if ($n['status'] === 'checked'): ?>
<div class="hks-send-panel">
    <h3 style="margin-top:0">🚀 HKS'ye Gönderim</h3>
    <?php if (!empty($checker) || $n['checked_at']): ?>
    <p style="font-size:.84rem;color:var(--muted);margin-top:0">
        Kontrol eden: <strong><?= hks_h($checker ?: '—') ?></strong>
        <?= $n['checked_at'] ? ' · ' . hks_h(date('d.m.Y H:i', strtotime($n['checked_at']))) : '' ?>
    </p>
    <?php endif; ?>

    <?php if (!empty($blockers)): ?>
        <div style="color:#991b1b;font-weight:600">Bu kayıt şu an HKS'ye gönderilemez:</div>
        <ul class="hks-blocker-list">
            <?php foreach ($blockers as $b): ?><li><?= hks_h($b) ?></li><?php endforeach; ?>
        </ul>
        <div class="hks-statebox hks-sb-draft" style="margin-top:10px">
            ℹ️ Canlı HKS bildirim gönderimi şu an kapalıdır. Bilgiler kaydedildi ve kontrol edildi; gönderim eşlemesi tamamlandığında bu ekrandan gönderilebilecektir.
        </div>
    <?php else: ?>
        <p style="font-size:.88rem;color:var(--muted)">Aşağıdaki bilgiler HKS'ye gönderilecek (şifreler gösterilmez):</p>
        <table class="hks-check-table">
            <?php foreach ($preview as $k => $v): if ($v === '') continue; ?>
            <tr><td><?= hks_h($k) ?></td><td><?= hks_h($v) ?></td></tr>
            <?php endforeach; ?>
        </table>
        <form method="post" id="sendForm" style="margin-top:14px">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="send">
            <?php if ($dup_warn): ?>
            <label class="hks-onay-row" style="color:#854d0e">
                <input type="checkbox" name="dup_ack" value="1">
                <span>Mükerrer uyarısını gördüm (<?= hks_h($dup_warn['local_no']) ?>), yine de göndermek istiyorum.</span>
            </label>
            <?php endif; ?>
            <label class="hks-onay-row">
                <input type="checkbox" name="onay" value="1" id="onayCheck">
                <span>Bilgileri kontrol ettim, HKS'ye gönderilmesini onaylıyorum.</span>
            </label>
            <button type="submit" class="btn btn-primary" id="sendBtn" disabled>
                🚀 Bu Bilgilerle HKS'ye Gönder
            </button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- İptal işlemi -->
<?php if ($can_write && in_array($n['status'], ['draft','ready','checked','failed'], true)): ?>
<div style="border-top:1px solid var(--border);padding-top:14px">
    <form method="post" style="display:inline">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="btn btn-ghost"
                onclick="return confirm('Bu bildirimi iptal etmek istediğinizden emin misiniz?')"
                style="color:var(--danger)">
            ✕ Bildirimi İptal Et
        </button>
    </form>
</div>
<?php endif; ?>

</div>

<script>
// Onay checkbox işaretlenmeden gönder butonu aktif olmaz (asıl güvenlik backend'de)
(function() {
    var chk = document.getElementById('onayCheck');
    var btn = document.getElementById('sendBtn');
    var frm = document.getElementById('sendForm');
    if (chk && btn) {
        chk.addEventListener('change', function() { btn.disabled = !chk.checked; });
    }
    // Çift tıklama engeli — submit sonrası butonu kilitle (asıl mükerrer engeli backend transaction'da)
    if (frm && btn) {
        frm.addEventListener('submit', function() {
            setTimeout(function() { btn.disabled = true; btn.textContent = 'Gönderiliyor...'; }, 0);
        });
    }
})();
</script>

<?php render_footer(); ?>
