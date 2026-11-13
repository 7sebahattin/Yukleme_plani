<?php
// =========================================================
// stok_kaydet.php — Stok (muhasebe): TÜM yazmaların TEK ucu (yalnız POST; GET → 405). cari_kaydet.php aynası.
// Sıra: yöntem → require_login → csrf_check (yazmadan ÖNCE) → şema kapısı → işleme göre stok_kapi → yalnız config/stok_core.php fonksiyonu.
// İşlemler (`islem`): kart_kaydet · kart_durum · kart_sil · tanim_kaydet · tanim_durum · tanim_sil · ice_aktar   (Parça B: + fis_kaydet · fis_iptal).
// Yanıt: fetch (X-Requested-With: fetch ya da ajax=1) → JSON {ok, id, fis_no, hata, alanlar{ad:hata}, uyari, yonlendir, kod, ...};
//        klasik form → set_flash + 303 `geri`'ye (YALNIZ ^(stok_[a-z_]+|transfer_fisleri)\.php(\?…)?$ yerel adres; aksi stok_kartlari.php).
// Her yazma stok_core'da audit_log_event'li. Defter tablosuna bu dosya DOĞRUDAN yazmaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/stok_core.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Yalnızca POST.';
    exit;
}
$auth_user = require_login();
stok_no_store();
$json = stok_json_mi();
if ($json) header('Content-Type: application/json; charset=utf-8');   // csrf_check başarısızlığı da JSON döner
csrf_check($_POST['csrf'] ?? null);
$pdo = db();
stok_sayfa_kapisi($pdo);

$islem = (string)($_POST['islem'] ?? '');
$uid   = (int)($auth_user['id'] ?? 0);
$geri  = stok_geri_guvenli($_POST['geri'] ?? '');
$P     = $_POST;
$yok   = ['ok' => false, 'hata' => 'Bilinmeyen işlem.'];
$id    = (int)($P['id'] ?? 0);

try {
    switch ($islem) {
        case 'kart_kaydet':
            stok_kapi('write');
            $P['aktif'] = isset($P['aktif']) && (string)$P['aktif'] !== '0' ? '1' : '0';   // onay kutusu: gelmediyse pasif
            $s = stok_kart_kaydet($pdo, $P, $uid, $id > 0 ? $id : null);
            if (!empty($s['ok']) && $id <= 0 && !str_contains($geri, 'id=')) $geri = 'stok_kartlari.php?id=' . (int)$s['id'];
            stok_cevap($s, $geri, $id > 0 ? 'Stok kartı güncellendi' : 'Stok kartı kaydedildi', $json);

        case 'kart_durum':
            stok_kapi('write');
            $s = stok_kart_durum($pdo, $id, (string)($P['aktif'] ?? '') === '1', $uid);
            stok_cevap($s, $geri, 'Stok kartı güncellendi', $json);

        case 'kart_sil':
            stok_kapi('delete');
            $s = stok_kart_sil($pdo, $id, $uid);
            if (!empty($s['ok'])) $geri = 'stok_kartlari.php';
            stok_cevap($s, $geri, 'Stok kartı silindi', $json);

        case 'tanim_kaydet':
            stok_kapi('admin');
            $s = stok_tanim_kaydet($pdo, (string)($P['tur'] ?? ''), (string)($P['ad'] ?? ''), (int)($P['sira'] ?? 0), $uid, $id > 0 ? $id : null);
            stok_cevap($s, $geri, $id > 0 ? 'Tanım güncellendi' : 'Tanım eklendi', $json);

        case 'tanim_durum':
            stok_kapi('admin');
            $s = stok_tanim_durum($pdo, $id, !empty($P['aktif']) && (string)$P['aktif'] !== '0', $uid);   // onay kutusu: gelmediyse pasif
            stok_cevap($s, $geri, 'Tanım güncellendi', $json);

        case 'tanim_sil':
            stok_kapi('admin');
            $s = stok_tanim_sil($pdo, $id, $uid);
            stok_cevap($s, $geri, 'Tanım silindi', $json);

        case 'ice_aktar':
            stok_kapi('admin');
            $s = stok_ice_aktar($pdo, $uid);
            stok_cevap($s, $geri, 'İçe aktarıldı: ' . (int)$s['eklenen'] . ' kart eklendi, ' . (int)$s['atlanan'] . ' atlandı', $json);

        default:
            stok_cevap($yok, $geri, '', $json);
    }
} catch (PDOException $e) {
    error_log('[stok_kaydet] ' . $islem . ': ' . $e->getMessage());
    if ($json) http_response_code(500);
    stok_cevap(['ok' => false, 'hata' => 'İşlem sırasında sunucu hatası oluştu.'], $geri, '', $json);
}
