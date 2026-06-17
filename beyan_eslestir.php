<?php
// =========================================================
// beyan_eslestir.php — Beyan ↔ Yükleme Planı Eşleştirme
// (Sprint Beyan-Yukleme-Eslesme-01)
//
// Beyan detayından seçilen "yüklendi olmayan" bir yükleme planını
// beyan bilgileriyle günceller, yükleme planını "yuklendi" yapar
// ve beyan durumunu "yuklendi" olarak işaretler.
//
// Tüm işlem tek transaction içinde, FOR UPDATE kilidiyle yapılır.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

$auth_user = require_login();
if (!can_beyan('write')) {
    forbidden('Eşleştirme için yetkiniz yok.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash('error', 'Geçersiz istek.');
    header('Location: beyanlar.php');
    exit;
}

csrf_check($_POST['csrf'] ?? null);

$action      = (string)($_POST['action'] ?? '');
$beyan_id    = (int)($_POST['beyan_id'] ?? 0);
$loading_id  = (int)($_POST['loading_record_id'] ?? 0);

if ($action !== 'match_loading_record' || $beyan_id <= 0) {
    set_flash('error', 'Geçersiz istek.');
    header('Location: beyanlar.php');
    exit;
}

$redirect = 'beyan_view.php?id=' . $beyan_id;

if ($loading_id <= 0) {
    set_flash('error', 'Seçilen yükleme planı bulunamadı.');
    header('Location: ' . $redirect);
    exit;
}

// Beyandan yükleme planına aktarılacak alan eşlemesi
//   loading_records sütunu  =>  customs_declarations sütunu
// NOT: Beyandaki "Şirket Adı" (company_name) alanı, yükleme planındaki
//      "Gümrük" (gumruk) alanına karşılık gelir.
$field_map = [
    'parti_no' => 'party_no',
    'alici'    => 'buyer_name',
    'ulasim'   => 'transport_type',
    'gumruk'   => 'company_name',
];

$user_id = (int)($auth_user['id'] ?? 0);
$pdo     = db();

try {
    $pdo->beginTransaction();

    // 1) Beyanı kilitle
    $bs = $pdo->prepare("SELECT * FROM customs_declarations WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
    $bs->execute([$beyan_id]);
    $beyan = $bs->fetch();
    if (!$beyan) {
        $pdo->rollBack();
        set_flash('error', 'Beyan kaydı bulunamadı.');
        header('Location: beyanlar.php');
        exit;
    }

    // Beyan zaten yüklendi / bir yüklemeye bağlı mı?
    if ((string)($beyan['status'] ?? '') === 'yuklendi') {
        $pdo->rollBack();
        set_flash('error', 'Bu beyan zaten yüklendi olarak işaretlenmiş.');
        header('Location: ' . $redirect);
        exit;
    }
    if (!empty($beyan['loading_record_id']) && (int)$beyan['loading_record_id'] !== $loading_id) {
        $pdo->rollBack();
        set_flash('error', 'Bu beyan zaten başka bir yükleme planıyla eşleştirilmiş.');
        header('Location: ' . $redirect);
        exit;
    }

    // 2) Yükleme planını kilitle
    $ls = $pdo->prepare("SELECT * FROM loading_records WHERE id = ? AND type = 'yukleme' FOR UPDATE");
    $ls->execute([$loading_id]);
    $rec = $ls->fetch();
    if (!$rec) {
        $pdo->rollBack();
        set_flash('error', 'Seçilen yükleme planı bulunamadı.');
        header('Location: ' . $redirect);
        exit;
    }

    // 3) Yükleme planı bu arada yüklendi / kilitlendi mi?
    if ((string)($rec['durum'] ?? '') === 'yuklendi' || !empty($rec['locked_at'])) {
        $pdo->rollBack();
        set_flash('error', 'Bu yükleme planı zaten yüklendi.');
        header('Location: ' . $redirect);
        exit;
    }

    // 4) Aktarılacak alanları topla (yalnızca beyan verisi doluysa)
    $set_parts = [];
    $set_vals  = [];
    $applied   = [];
    foreach ($field_map as $lr_col => $cd_col) {
        $val = trim((string)($beyan[$cd_col] ?? ''));
        if ($val === '') continue;            // beyan boşsa mevcut değeri koru
        $set_parts[]      = "`$lr_col` = ?";
        $set_vals[]       = $val;
        $applied[$lr_col] = $val;
    }

    // 5) Yükleme planını güncelle + yuklendi olarak işaretle (kilitle)
    $set_parts[] = "`durum` = ?";
    $set_vals[]  = 'yuklendi';
    $set_parts[] = "`locked_at` = NOW()";
    $set_parts[] = "`locked_by` = ?";
    $set_vals[]  = $user_id;
    $set_parts[] = "`updated_at` = NOW()";

    $set_vals[] = $loading_id;   // WHERE
    $sql = "UPDATE loading_records SET " . implode(', ', $set_parts) . " WHERE id = ?";
    $pdo->prepare($sql)->execute($set_vals);

    // 6) Beyan durumunu yuklendi yap + bağlantıyı kur
    $pdo->prepare("UPDATE customs_declarations
                   SET status = 'yuklendi', loading_record_id = ?, updated_by = ?, updated_at = NOW()
                   WHERE id = ?")
        ->execute([$loading_id, $user_id, $beyan_id]);

    // 7) Audit
    audit_log_event('beyan_loading_matched', 'declarations', $beyan_id,
        ['status' => (string)($beyan['status'] ?? ''), 'loading_record_id' => $beyan['loading_record_id'] ?? null],
        ['status' => 'yuklendi', 'loading_record_id' => $loading_id, 'applied_fields' => $applied]
    );
    audit_log_event('loading_record_marked_loaded_from_beyan', 'records', $loading_id,
        ['durum' => (string)($rec['durum'] ?? '')],
        ['durum' => 'yuklendi', 'locked_by' => $user_id, 'from_beyan_id' => $beyan_id, 'applied_fields' => $applied]
    );

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[Beyan-Eslesme] ' . $e->getMessage());
    set_flash('error', 'Eşleştirme sırasında bir hata oluştu, işlem geri alındı.');
    header('Location: ' . $redirect);
    exit;
}

set_flash('success', 'Yükleme planı beyan bilgileriyle eşleştirildi, yükleme durumu Yüklendi yapıldı.');
header('Location: ' . $redirect);
exit;
