<?php
// =========================================================
// fatura_kaydet.php — Fatura: TÜM yazmaların TEK ucu (yalnız POST; GET → 405). stok_kaydet.php aynası.
// Sıra: yöntem → require_login → no-store → JSON başlığı → csrf_check (yazmadan ÖNCE) → şema kapısı (fatura + cari + stok) →
//       işleme göre fatura_kapi → yalnız config/fatura_core.php fonksiyonu → fatura_cevap.
// İşlemler (`islem`, bu sırayla): belge_kaydet (write) · belge_iptal (delete) · siparis_kapat (write) · tanim_kaydet · tanim_durum · tanim_sil (admin).
//   belge_kaydet: başlık + satırlar paralel dizilerle (kart_id[] kart_ara[] urun_adi[] miktar[] birim[] birim_fiyat[] iskonto[] kdv_orani[] gtip[]
//   satir_brut_kg[] satir_net_kg[] satir_kap[] lot[] satir_aciklama[] kaynak_satir_id[]); 409 yetersiz_stok → aynı istek_anahtari + eksiye_izin=1.
// Yanıt: fetch (X-Requested-With: fetch ya da ajax=1) → JSON {ok, id, belge_no, fatura_no, hata, alanlar, satir_hatalari, uyari, yonlendir, tekrar, kod,
//        eksikler, stok_fis_no, cari_belge_no}; 200 / 422 / 409 / 403 / 503 / 500. Klasik form → set_flash + 303 `geri`
//        (YALNIZ ^(alis_faturalari|satis_faturalari|sevk_irsaliyeleri|alinan_siparis_fisleri|fatura_tanimlari)\.php(\?…)?$; aksi satis_faturalari.php).
// Her yazma fatura_core'da audit_log_event'li. Bu dosya hiçbir tabloya DOĞRUDAN yazmaz; $_GET kullanmaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/fatura_core.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Yalnızca POST.';
    exit;
}
$auth_user = require_login();
fatura_no_store();
$json = fatura_json_mi();
if ($json) header('Content-Type: application/json; charset=utf-8');   // csrf_check başarısızlığı da JSON döner
csrf_check($_POST['csrf'] ?? null);
$pdo = db();
fatura_sayfa_kapisi($pdo);

$islem = (string)($_POST['islem'] ?? '');
$geri  = fatura_geri_guvenli($_POST['geri'] ?? '');
$P     = $_POST;
$uid   = (int)($auth_user['id'] ?? 0);
$yok   = ['ok' => false, 'hata' => 'Bilinmeyen işlem.'];
$id    = (int)($P['id'] ?? 0);

try {
    switch ($islem) {
        case 'belge_kaydet':
            fatura_kapi('write');
            $s = fatura_kaydet($pdo, $P, $uid);
            if (!empty($s['ok'])) $geri = (fatura_tur_bilgi((string)($P['tur'] ?? ''))['sayfa'] ?? 'satis_faturalari.php') . '?id=' . (int)$s['id'];
            fatura_cevap($s, $geri, !empty($s['tekrar']) ? 'Belge zaten kaydedilmişti' : 'Belge kaydedildi', $json);

        case 'belge_iptal':
            fatura_kapi('delete');
            $s = fatura_iptal($pdo, $id, (string)($P['neden'] ?? ''), $uid);
            fatura_cevap($s, $geri, 'Belge iptal edildi', $json);

        case 'siparis_kapat':
            fatura_kapi('write');
            $s = fatura_siparis_kapat($pdo, $id, (string)($P['kapali'] ?? '') === '1', (string)($P['neden'] ?? ''), $uid);
            fatura_cevap($s, $geri, (string)($P['kapali'] ?? '') === '1' ? 'Sipariş kapatıldı' : 'Sipariş yeniden açıldı', $json);

        case 'tanim_kaydet':
            fatura_kapi('admin');
            $s = fatura_tanim_kaydet($pdo, $P, $uid, $id > 0 ? $id : null);
            fatura_cevap($s, $geri, $id > 0 ? 'Tanım güncellendi' : 'Tanım eklendi', $json);

        case 'tanim_durum':
            fatura_kapi('admin');
            $s = fatura_tanim_durum($pdo, $id, !empty($P['aktif']) && (string)$P['aktif'] !== '0', $uid);   // onay kutusu: gelmediyse pasif
            fatura_cevap($s, $geri, 'Tanım güncellendi', $json);

        case 'tanim_sil':
            fatura_kapi('admin');
            $s = fatura_tanim_sil($pdo, $id, $uid);
            fatura_cevap($s, $geri, 'Tanım silindi', $json);

        default:
            fatura_cevap($yok, $geri, '', $json);
    }
} catch (PDOException $e) {
    error_log('[fatura_kaydet] ' . $islem . ': ' . $e->getMessage());
    if ($json) http_response_code(500);
    fatura_cevap(['ok' => false, 'hata' => 'İşlem sırasında sunucu hatası oluştu.', 'kod' => 'sunucu_hatasi'], $geri, '', $json);
}
