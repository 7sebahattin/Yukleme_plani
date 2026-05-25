<?php
// hks/helpers.php — HKS modülü yardımcıları (v2)
declare(strict_types=1);

// ── Tip etiketleri ────────────────────────────────────────
function hks_type_label(string $type): string {
    return match($type) {
        'alis'     => '📥 Alış',
        'satis'    => '📤 Satış',
        'ihracat'  => '🌍 İhracat',
        'transfer' => '🔄 Transfer',
        'iade'     => '↩ İade',
        default    => $type,
    };
}

function hks_type_options(string $selected = ''): string {
    $opts = ['alis' => 'Alış', 'satis' => 'Satış', 'ihracat' => 'İhracat', 'transfer' => 'Transfer', 'iade' => 'İade'];
    $out = '';
    foreach ($opts as $v => $l) {
        $sel = $selected === $v ? ' selected' : '';
        $out .= '<option value="' . hks_h($v) . '"' . $sel . '>' . hks_h($l) . '</option>';
    }
    return $out;
}

// Şifreleme anahtarı: HKS_ENC_KEY sabitinden al, yoksa DB_NAME'den türet
function hks_enc_key(): string {
    if (defined('HKS_ENC_KEY')) return HKS_ENC_KEY;
    // config/db.php'deki DB_NAME + sabit suffix kombinasyonunu türet
    $base = (defined('DB_NAME') ? DB_NAME : 'yukleme') . '_hks_enc_2024';
    return hash('sha256', $base, true); // 32 byte binary
}

function hks_encrypt(string $plain): string {
    if ($plain === '') return '';
    $key = hks_enc_key();
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}

function hks_decrypt(string $encoded): string {
    if ($encoded === '') return '';
    try {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 17) return '';
        $key = hks_enc_key();
        $iv  = substr($raw, 0, 16);
        $enc = substr($raw, 16);
        $dec = openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $dec === false ? '' : $dec;
    } catch (Throwable) {
        return '';
    }
}

function hks_plate(string $plate): string {
    return strtoupper(preg_replace('/\s+/', '', trim($plate)));
}

function hks_qty(mixed $v): float {
    return round((float)str_replace(',', '.', (string)$v), 3);
}

function hks_h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hks_status_label(string $status): string {
    return match($status) {
        'draft'  => 'Taslak',
        'sent'   => 'Gönderildi',
        'error'  => 'Hata',
        default  => $status,
    };
}

function hks_status_class(string $status): string {
    return match($status) {
        'draft'  => 'hks-badge-draft',
        'sent'   => 'hks-badge-sent',
        'error'  => 'hks-badge-error',
        default  => '',
    };
}
