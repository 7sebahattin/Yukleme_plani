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

// Şifre KAYDETMEK için sadece HKS_CRED_KEY kabul edilir (HKS_ENC_KEY fallback yok)
function hks_can_save_passwords(): bool {
    return defined('HKS_CRED_KEY') && HKS_CRED_KEY !== '';
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

// Hassas alanları maskele (log yazmadan önce). Derinlemesine (nested Istek dahil).
//   - Kimlik bilgileri (UserName/Password/ServicePassword vb.) TAM maskelenir (***).
//   - TC/VKN alanları (MalinSahibiTcKimlikVergiNo, TcKimlikVergiNo vb.) KISMİ maskelenir
//     (ilk 4 + *** + son 4) → değerin gittiği teyit edilebilir ama tam görünmez.
function hks_mask_sensitive(array $data): array {
    static $full = [
        'username','password','servicepassword','securityword',
        'kullaniciadi','sifre','servissifre','guvenlikkelimesi',
        'password_enc','service_password_enc','security_word_enc',
    ];
    static $partial = [
        'malinsahibitckimlikvergino','tckimlikvergino','bildirimcitckimlikvergino',
        'ureticitckimlikvergino','tckimlikno','vergino',
    ];
    $walk = static function ($node) use (&$walk, $full, $partial) {
        if (!is_array($node)) return $node;
        $out = [];
        foreach ($node as $k => $v) {
            $lk = is_string($k) ? mb_strtolower($k) : '';
            if ($lk !== '' && in_array($lk, $full, true)) { $out[$k] = '***'; continue; }
            if ($lk !== '' && in_array($lk, $partial, true) && is_string($v) && strlen($v) > 6) {
                $out[$k] = substr($v, 0, 4) . '***' . substr($v, -4); continue;
            }
            $out[$k] = is_array($v) ? $walk($v) : $v;
        }
        return $out;
    };
    return $walk($data);
}

// JSON string içindeki hassas alanları maskele
function hks_mask_json(string $json): string {
    if ($json === '' || $json === '[]' || $json === '{}') return $json;
    $data = json_decode($json, true);
    if (!is_array($data)) return $json;
    $data = hks_mask_sensitive($data);
    return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
