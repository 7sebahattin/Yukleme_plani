<?php
declare(strict_types=1);
// HKS modülü genel yardımcı fonksiyonları

function hks_h(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hks_qty(mixed $v): float {
    return round((float)str_replace(',', '.', (string)($v ?? 0)), 3);
}

function hks_plate(string $plate): string {
    return strtoupper(preg_replace('/\s+/', '', trim($plate)));
}

function hks_check_soap(): bool {
    return extension_loaded('soap');
}

// ── Etiket fonksiyonları ──────────────────────────────────

function hks_status_label(string $status): string {
    return match($status) {
        'draft'     => 'Taslak',
        'ready'     => 'Gönderime Hazır',
        'sent'      => 'Gönderildi',
        'failed'    => 'Hata',
        'cancelled' => 'İptal',
        default     => $status,
    };
}

function hks_status_badge(string $status): string {
    $label = hks_status_label($status);
    $cls   = match($status) {
        'draft'     => 'hks-badge-draft',
        'ready'     => 'hks-badge-ready',
        'sent'      => 'hks-badge-sent',
        'failed'    => 'hks-badge-failed',
        'cancelled' => 'hks-badge-cancelled',
        default     => '',
    };
    return '<span class="hks-badge ' . $cls . '">' . hks_h($label) . '</span>';
}

function hks_ref_type_label(string $type): string {
    return match($type) {
        'ulke'           => 'Ülke',
        'il'             => 'İl',
        'ilce'           => 'İlçe',
        'belde'          => 'Belde',
        'depo'           => 'Depo',
        'sube'           => 'Şube',
        'isletme_turu'   => 'İşletme Türü',
        'urun'           => 'Ürün',
        'urun_birim'     => 'Ürün Birimi',
        'urun_cins'      => 'Ürün Cinsi',
        'malin_niteligi' => 'Malın Niteliği',
        'uretim_sekli'   => 'Üretim Şekli',
        'bildirim_turu'  => 'Bildirim Türü',
        'sifat'          => 'Sıfat',
        'referans_kunye' => 'Referans Künye',
        default          => $type,
    };
}

function hks_env_label(string $env): string {
    return $env === 'live' ? '🔴 Canlı' : '🟡 Test';
}

// ── Dropdown yardımcıları ────────────────────────────────

function hks_ref_options(array $refs, string $selected = '', bool $include_empty = true): string {
    $out = $include_empty ? '<option value="">— Seçin —</option>' : '';
    foreach ($refs as $r) {
        $val = hks_h($r['ref_code']);
        $lbl = hks_h($r['ref_name']);
        $sel = $selected === (string)$r['ref_code'] ? ' selected' : '';
        $out .= "<option value=\"{$val}\"{$sel}>{$lbl}</option>";
    }
    return $out;
}

// ── Bildirim doğrulama ───────────────────────────────────

function hks_validate_notification(array $n): array {
    $errors = [];
    $required = [
        'notification_type' => 'Bildirim türü',
        'firma'             => 'Firma',
        'urun'              => 'Ürün',
        'miktar'            => 'Miktar',
        'birim'             => 'Birim',
        'depo'              => 'Depo',
        'il'                => 'İl',
        'sevk_tarihi'       => 'Sevk tarihi',
        'arac_plaka'        => 'Araç plaka',
    ];
    foreach ($required as $field => $label) {
        if (empty($n[$field])) {
            $errors[] = $label . ' zorunludur.';
        }
    }
    if (!empty($n['miktar']) && (float)$n['miktar'] <= 0) {
        $errors[] = 'Miktar sıfırdan büyük olmalıdır.';
    }
    return $errors;
}

// ── Yükleme planından taslak oluştur ───────────────────

function hks_create_draft_from_loading_record(int $loading_record_id, HksRepository $repo, PDO $pdo): ?int {
    $st = $pdo->prepare("SELECT * FROM loading_records WHERE id = ?");
    $st->execute([$loading_record_id]);
    $rec = $st->fetch();
    if (!$rec) return null;

    $stNet = $pdo->prepare("SELECT COALESCE(SUM(net_kg),0) FROM loading_pallets WHERE loading_record_id = ?");
    $stNet->execute([$loading_record_id]);
    $miktar = (float)$stNet->fetchColumn();

    $on_plaka  = trim($rec['on_plaka']  ?? '');
    $arka_plaka = trim($rec['arka_plaka'] ?? '');
    $plaka = $on_plaka . ($arka_plaka && $arka_plaka !== $on_plaka ? ' / ' . $arka_plaka : '');

    return $repo->createNotification([
        'source_type'  => 'loading_record',
        'source_id'    => $loading_record_id,
        'firma'        => $rec['firma'] ?? '',
        'urun'         => $rec['urun'] ?? '',
        'miktar'       => $miktar,
        'birim'        => 'KG',
        'alici_ad'     => $rec['alici'] ?? '',
        'belge_no'     => $rec['parti_no'] ?? '',
        'arac_plaka'   => hks_plate($plaka),
        'sevk_tarihi'  => date('Y-m-d'),
        'status'       => 'draft',
        'created_by'   => $_SESSION['user_id'] ?? null,
    ]);
}
