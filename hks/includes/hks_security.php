<?php
declare(strict_types=1);
// HKS şifreleme ve güvenlik fonksiyonları — web'den direkt erişilememeli

function hks_enc_key(): string {
    if (defined('HKS_CRED_KEY') && HKS_CRED_KEY !== '') {
        return substr(hash('sha256', HKS_CRED_KEY, false), 0, 32);
    }
    if (defined('HKS_ENC_KEY') && HKS_ENC_KEY !== '') {
        return substr(HKS_ENC_KEY . str_repeat("\0", 32), 0, 32);
    }
    // Fallback: DB_NAME bazlı türetme — HKS_CRED_KEY tercih edilmeli
    $base = (defined('DB_NAME') ? DB_NAME : 'yukleme') . '_hks_enc_2024';
    return hash('sha256', $base, true);
}

function hks_has_secure_key(): bool {
    return (defined('HKS_CRED_KEY') && HKS_CRED_KEY !== '')
        || (defined('HKS_ENC_KEY') && HKS_ENC_KEY !== '');
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

// Hassas alanları maskele (log yazmadan önce)
function hks_mask_sensitive(array $data): array {
    static $sensitive = [
        'UserName','Password','ServicePassword','SecurityWord',
        'KullaniciAdi','Sifre','ServisSifre','GuvenlikKelimesi',
        'username','password','service_password','security_word',
        'password_enc','service_password_enc','security_word_enc',
    ];
    foreach ($sensitive as $key) {
        if (array_key_exists($key, $data)) {
            $data[$key] = '***';
        }
    }
    return $data;
}

// JSON string içindeki hassas alanları maskele
function hks_mask_json(string $json): string {
    if ($json === '' || $json === '[]' || $json === '{}') return $json;
    $data = json_decode($json, true);
    if (!is_array($data)) return $json;
    $data = hks_mask_sensitive($data);
    return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
