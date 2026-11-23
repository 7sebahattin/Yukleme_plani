<?php
// =========================================================
// cek_kaydet.php — Çek-Senet: TÜM yazmaların TEK ucu (yalnız POST; GET → 405). kasa_kaydet.php / fatura_kaydet.php aynası.
// Sıra: yöntem → require_login → no-store → JSON başlığı → csrf_check (yazmadan ÖNCE) → şema kapısı (çek-senet + cari + kasa) →
//       işleme göre ceksenet_kapi → yalnız config/ceksenet_core.php fonksiyonu → ceksenet_cevap.
// İşlemler (`islem`, bu sırayla): bordro_kaydet (write) · islem_uygula (write) · hareket_iptal (delete) · tanim_kaydet · tanim_durum · tanim_sil (admin).
//   bordro_kaydet: sinif, yon, cari_id, tarih, aciklama?, istek_anahtari? + paralel diziler (seri_no[] banka_ad[] sube[] hesap_no[] borclu[] kefil[] yer_adi[]
//   duzenleme_tarihi[] vade[] tutar[] para_birimi[] karne_id[]).
//   islem_uygula: ids[] (ya da id), gecis (durum geçişi adı; `islem` bu uçta yönlendirme anahtarıdır), tarih, cari_id?, kasa_id?, aciklama?, istek_anahtari?, eksiye_izin?
//   hareket_iptal: id (HAREKET id), neden. tanim_*: id?, tur, ad, sube | kasa_id, ilk_no, yaprak, sira.
//   409: yetersiz_bakiye (aynı istek_anahtari + eksiye_izin=1 ile yeniden) · mukerrer · gecersiz_gecis (+ kayitlar[]) · son_degil · kullanimda.
// Yanıt: fetch (X-Requested-With: fetch ya da ajax=1) → JSON {ok, id, kayit_id, idler, bordro_no, belge_no, hata, alanlar, satir_hatalari, kayitlar, uyari, yonlendir, tekrar, kod,
//        bakiye, eksik, toplamlar, ortalama_vade, yeni_durum, sinif, yon}; 200 / 422 / 409 / 403 / 503 / 500. Klasik form → set_flash + 303 `geri`
//        (YALNIZ ^(cek_dekontu|senet_dekontu|cek_tanimlari)\.php(\?…)?$; aksi cek_dekontu.php).
// Her yazma ceksenet_core'da audit_log_event'li. Bu dosya hiçbir tabloya DOĞRUDAN yazmaz; $_GET kullanmaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/ceksenet_core.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Yalnızca POST.';
    exit;
}
$auth_user = require_login();
ceksenet_no_store();
$json = ceksenet_json_mi();
if ($json) header('Content-Type: application/json; charset=utf-8');   // csrf_check başarısızlığı da JSON döner
csrf_check($_POST['csrf'] ?? null);
$pdo = db();
ceksenet_sayfa_kapisi($pdo);

$islem = (string)($_POST['islem'] ?? '');
$geri  = ceksenet_geri_guvenli($_POST['geri'] ?? '');
$P     = $_POST;
$uid   = (int)($auth_user['id'] ?? 0);
$yok   = ['ok' => false, 'hata' => 'Bilinmeyen işlem.'];
$id    = (int)($P['id'] ?? 0);
$sayfa = static fn(mixed $sinif): string => (string)$sinif === 'senet' ? 'senet_dekontu.php' : 'cek_dekontu.php';

try {
    switch ($islem) {
        case 'bordro_kaydet':
            ceksenet_kapi('create');
            $s = ceksenet_bordro_kaydet($pdo, $P, $uid);
            if (!empty($s['ok'])) $geri = $sayfa($s['sinif'] ?? '') . '?yon=' . urlencode((string)($s['yon'] ?? 'alinan'));
            ceksenet_cevap($s, $geri, !empty($s['tekrar']) ? 'Bordro zaten kaydedilmişti' : 'Bordro kaydedildi', $json);

        case 'islem_uygula':
            ceksenet_kapi('edit');
            $s = ceksenet_islem_uygula($pdo, $P, $uid);
            if (!empty($s['ok'])) $geri = $sayfa($s['sinif'] ?? '') . '?yon=' . urlencode((string)($s['yon'] ?? 'alinan'));
            ceksenet_cevap($s, $geri, !empty($s['tekrar']) ? 'İşlem zaten kaydedilmişti' : 'İşlem kaydedildi', $json);

        case 'hareket_iptal':
            ceksenet_kapi('delete');
            $s = ceksenet_hareket_iptal($pdo, $id, (string)($P['neden'] ?? ''), $uid);
            if (!empty($s['ok'])) $geri = $sayfa($s['sinif'] ?? '') . '?yon=' . urlencode((string)($s['yon'] ?? 'alinan')) . '&id=' . (int)$s['kayit_id'];   // yön taşınır: verilen evrak Alınan sekmesine dönmesin
            ceksenet_cevap($s, $geri, 'Hareket iptal edildi', $json);

        case 'tanim_kaydet':
            ceksenet_kapi($id > 0 ? 'edit' : 'create');
            $s = ceksenet_tanim_kaydet($pdo, $P, $uid, $id > 0 ? $id : null);
            ceksenet_cevap($s, $geri, $id > 0 ? 'Tanım güncellendi' : 'Tanım eklendi', $json);

        case 'tanim_durum':
            ceksenet_kapi('edit');
            $s = ceksenet_tanim_durum($pdo, $id, !empty($P['aktif']) && (string)$P['aktif'] !== '0', $uid);   // onay kutusu: gelmediyse pasif
            ceksenet_cevap($s, $geri, 'Tanım güncellendi', $json);

        case 'tanim_sil':
            ceksenet_kapi('delete');
            $s = ceksenet_tanim_sil($pdo, $id, $uid);
            ceksenet_cevap($s, $geri, 'Tanım silindi', $json);

        default:
            ceksenet_cevap($yok, $geri, '', $json);
    }
} catch (PDOException $e) {
    error_log('[cek_kaydet] ' . $islem . ': ' . $e->getMessage());
    if ($json) http_response_code(500);
    ceksenet_cevap(['ok' => false, 'hata' => 'İşlem sırasında sunucu hatası oluştu.', 'kod' => 'sunucu_hatasi'], $geri, '', $json);
}
