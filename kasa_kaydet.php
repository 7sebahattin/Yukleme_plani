<?php
// =========================================================
// kasa_kaydet.php — Kasa: TÜM yazmaların TEK ucu (yalnız POST; GET → 405). fatura_kaydet.php / stok_kaydet.php aynası.
// Sıra: yöntem → require_login → no-store → JSON başlığı → csrf_check (yazmadan ÖNCE) → şema kapısı (kasa + cari) →
//       işleme göre kasa_kapi → yalnız config/kasa_core.php fonksiyonu → kasa_cevap.
// İşlemler (`islem`, bu sırayla): kart_kaydet (write) · kart_durum (write) · kart_sil (delete) · tanim_kaydet · tanim_durum · tanim_sil (admin) ·
//   hareket_ekle (write) · virman_ekle (write) · hareket_iptal (delete).
//   hareket_ekle: kasa_id, tur (cari_tahsilat|cari_odeme|gelir|gider|acilis), tarih, tutar, kur?, evrak_no?, cari_id?, kalem_id?, aciklama?, istek_anahtari?, eksiye_izin?
//   (kaynak_giris / kaynak_cikis dahili türleri POST'tan REDDEDİLİR: kasa_hareket_ekle'nin 4. parametresi bu uçta HİÇ geçilmez).
//   409 yetersiz_bakiye → aynı istek_anahtari + eksiye_izin=1 ile yeniden.
// Yanıt: fetch (X-Requested-With: fetch ya da ajax=1) → JSON {ok, id, id2, belge_no, cari_belge_no, hata, alanlar, uyari, yonlendir, tekrar, kod, bakiye, eksik};
//        200 / 422 / 409 / 403 / 503 / 500. Klasik form → set_flash + 303 `geri` (YALNIZ ^kasa_(fisleri|tanimlari)\.php(\?…)?$; aksi kasa_fisleri.php).
// Her yazma kasa_core'da audit_log_event'li. Bu dosya hiçbir tabloya DOĞRUDAN yazmaz; $_GET kullanmaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/kasa_core.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Yalnızca POST.';
    exit;
}
$auth_user = require_login();
kasa_no_store();
$json = kasa_json_mi();
if ($json) header('Content-Type: application/json; charset=utf-8');   // csrf_check başarısızlığı da JSON döner
csrf_check($_POST['csrf'] ?? null);
$pdo = db();
kasa_sayfa_kapisi($pdo);

$islem = (string)($_POST['islem'] ?? '');
$geri  = kasa_geri_guvenli($_POST['geri'] ?? '');
$P     = $_POST;
$uid   = (int)($auth_user['id'] ?? 0);
$yok   = ['ok' => false, 'hata' => 'Bilinmeyen işlem.'];
$id    = (int)($P['id'] ?? 0);

try {
    switch ($islem) {
        case 'kart_kaydet':
            kasa_kapi($id > 0 ? 'edit' : 'create');
            $P['aktif'] = isset($P['aktif']) && (string)$P['aktif'] !== '0' ? '1' : '0';           // onay kutusu: gelmediyse pasif
            $P['eksi_izin'] = isset($P['eksi_izin']) && (string)$P['eksi_izin'] !== '0' ? '1' : '0';   // onay kutusu: gelmediyse eksiye izin yok
            $s = kasa_kart_kaydet($pdo, $P, $uid, $id > 0 ? $id : null);
            kasa_cevap($s, $geri, $id > 0 ? 'Kasa güncellendi' : 'Kasa kaydedildi', $json);

        case 'kart_durum':
            kasa_kapi('edit');
            $s = kasa_kart_durum($pdo, $id, (string)($P['aktif'] ?? '') === '1', $uid);
            kasa_cevap($s, $geri, 'Kasa güncellendi', $json);

        case 'kart_sil':
            kasa_kapi('delete');
            $s = kasa_kart_sil($pdo, $id, $uid);
            kasa_cevap($s, $geri, 'Kasa silindi', $json);

        case 'tanim_kaydet':
            kasa_kapi($id > 0 ? 'edit' : 'create');
            $s = kasa_tanim_kaydet($pdo, (string)($P['tur'] ?? ''), (string)($P['ad'] ?? ''), (int)($P['sira'] ?? 0), $uid, $id > 0 ? $id : null);
            kasa_cevap($s, $geri, $id > 0 ? 'Tanım güncellendi' : 'Tanım eklendi', $json);

        case 'tanim_durum':
            kasa_kapi('edit');
            $s = kasa_tanim_durum($pdo, $id, !empty($P['aktif']) && (string)$P['aktif'] !== '0', $uid);   // onay kutusu: gelmediyse pasif
            kasa_cevap($s, $geri, 'Tanım güncellendi', $json);

        case 'tanim_sil':
            kasa_kapi('delete');
            $s = kasa_tanim_sil($pdo, $id, $uid);
            kasa_cevap($s, $geri, 'Tanım silindi', $json);

        case 'hareket_ekle':
            kasa_kapi('create');
            $s = kasa_hareket_ekle($pdo, $P, $uid);
            if (!empty($s['ok']) && (int)($P['kasa_id'] ?? 0) > 0) $geri = 'kasa_fisleri.php?kasa=' . (int)$P['kasa_id'];
            kasa_cevap($s, $geri, !empty($s['tekrar']) ? 'Hareket zaten kaydedilmişti' : 'Hareket kaydedildi', $json);

        case 'virman_ekle':
            kasa_kapi('create');
            $s = kasa_virman_ekle($pdo, $P, $uid);
            if (!empty($s['ok']) && (int)($P['kaynak_kasa_id'] ?? 0) > 0) $geri = 'kasa_fisleri.php?kasa=' . (int)$P['kaynak_kasa_id'];
            kasa_cevap($s, $geri, !empty($s['tekrar']) ? 'Virman zaten kaydedilmişti' : 'Virman kaydedildi', $json);

        case 'hareket_iptal':
            kasa_kapi('delete');
            $s = kasa_hareket_iptal($pdo, $id, (string)($P['neden'] ?? ''), $uid);
            kasa_cevap($s, $geri, 'Hareket iptal edildi', $json);

        default:
            kasa_cevap($yok, $geri, '', $json);
    }
} catch (PDOException $e) {
    error_log('[kasa_kaydet] ' . $islem . ': ' . $e->getMessage());
    if ($json) http_response_code(500);
    kasa_cevap(['ok' => false, 'hata' => 'İşlem sırasında sunucu hatası oluştu.', 'kod' => 'sunucu_hatasi'], $geri, '', $json);
}
