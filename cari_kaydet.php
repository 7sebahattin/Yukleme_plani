<?php
// =========================================================
// cari_kaydet.php — Cari İşlemler: TÜM yazmaların TEK ucu (yalnız POST; GET → 405).
// Sıra: yöntem → require_login → csrf_check (yazmadan ÖNCE) → şema kapısı → işleme göre cari_kapi → yalnız config/cari_core.php fonksiyonu.
// İşlemler (`islem`): kart_kaydet · kart_durum · kart_sil · hareket_ekle (tur=virman + hedef_id → virman) · virman_ekle · hareket_iptal ·
//                    tanim_kaydet · tanim_durum · tanim_sil · ice_aktar.
// Yanıt: fetch (X-Requested-With: fetch ya da ajax=1) → JSON {ok, id, belge_no, hata, alanlar{ad:hata}, uyari, yonlendir, ...};
//        klasik form → set_flash + 303 `geri`'ye (YALNIZ ^cari_[a-z]+\.php(\?…)?$ yerel adres; aksi cari_kartlari.php — açık yönlendirme YOK).
// Her yazma cari_core'da audit_log_event'li. Hareket tablosuna bu dosya DOĞRUDAN yazmaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/cari_core.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Yalnızca POST.';
    exit;
}
$auth_user = require_login();
cari_no_store();
$json = cari_json_mi();
if ($json) header('Content-Type: application/json; charset=utf-8');   // csrf_check başarısızlığı da JSON döner
csrf_check($_POST['csrf'] ?? null);
$pdo = db();
cari_sayfa_kapisi($pdo);

$islem = (string)($_POST['islem'] ?? '');
$uid   = (int)($auth_user['id'] ?? 0);
$geri  = cari_geri_guvenli($_POST['geri'] ?? '');
$P     = $_POST;
$yok   = ['ok' => false, 'hata' => 'Bilinmeyen işlem.'];
$id    = (int)($P['id'] ?? 0);

try {
    switch ($islem) {
        case 'kart_kaydet':
            cari_kapi('write');
            $P['aktif'] = isset($P['aktif']) && (string)$P['aktif'] !== '0' ? '1' : '0';   // onay kutusu: gelmediyse pasif
            $s = cari_kart_kaydet($pdo, $P, $uid, $id > 0 ? $id : null);
            if (!empty($s['ok']) && $id <= 0 && !str_contains($geri, 'id=')) $geri = 'cari_kartlari.php?id=' . (int)$s['id'];
            cari_cevap($s, $geri, $id > 0 ? 'Cari güncellendi' : 'Cari kaydedildi', $json);

        case 'kart_durum':
            cari_kapi('write');
            $s = cari_kart_durum($pdo, $id, (string)($P['aktif'] ?? '') === '1', $uid);
            cari_cevap($s, $geri, 'Cari güncellendi', $json);

        case 'kart_sil':
            cari_kapi('delete');
            $s = cari_kart_sil($pdo, $id, $uid);
            if (!empty($s['ok'])) $geri = 'cari_kartlari.php';
            cari_cevap($s, $geri, 'Cari silindi', $json);

        case 'hareket_ekle':
            cari_kapi('write');
            if ((string)($P['tur'] ?? '') === 'virman' && (int)($P['hedef_id'] ?? 0) > 0) {   // JS'siz yedek: tek modal → virman
                $P['kaynak_id'] = $P['kaynak_id'] ?? ($P['cari_id'] ?? 0);
                $s = cari_virman_ekle($pdo, $P, $uid);
            } else {
                $s = cari_hareket_ekle($pdo, $P, $uid);
            }
            cari_cevap($s, $geri, 'Kaydedildi', $json);

        case 'virman_ekle':
            cari_kapi('write');
            $P['kaynak_id'] = $P['kaynak_id'] ?? ($P['cari_id'] ?? 0);
            $s = cari_virman_ekle($pdo, $P, $uid);
            cari_cevap($s, $geri, 'Virman kaydedildi', $json);

        case 'hareket_iptal':
            cari_kapi('delete');
            $s = cari_hareket_iptal($pdo, $id, (string)($P['neden'] ?? ''), $uid);
            cari_cevap($s, $geri, 'Belge iptal edildi', $json);

        case 'tanim_kaydet':
            cari_kapi('admin');
            $s = cari_tanim_kaydet($pdo, (string)($P['tur'] ?? ''), (string)($P['ad'] ?? ''), (int)($P['sira'] ?? 0), $uid, $id > 0 ? $id : null);
            cari_cevap($s, $geri, $id > 0 ? 'Tanım güncellendi' : 'Tanım eklendi', $json);

        case 'tanim_durum':
            cari_kapi('admin');
            $s = cari_tanim_durum($pdo, $id, !empty($P['aktif']) && (string)$P['aktif'] !== '0', $uid);   // onay kutusu: gelmediyse pasif
            cari_cevap($s, $geri, 'Tanım güncellendi', $json);

        case 'tanim_sil':
            cari_kapi('admin');
            $s = cari_tanim_sil($pdo, $id, $uid);
            cari_cevap($s, $geri, 'Tanım silindi', $json);

        case 'ice_aktar':
            cari_kapi('admin');
            $s = cari_ice_aktar($pdo, $uid);
            cari_cevap($s, $geri, 'İçe aktarıldı: ' . (int)$s['eklenen'] . ' kart eklendi, ' . (int)$s['atlanan'] . ' atlandı', $json);

        default:
            cari_cevap($yok, $geri, '', $json);
    }
} catch (PDOException $e) {
    error_log('[cari_kaydet] ' . $islem . ': ' . $e->getMessage());
    if ($json) http_response_code(500);
    cari_cevap(['ok' => false, 'hata' => 'İşlem sırasında sunucu hatası oluştu.'], $geri, '', $json);
}
