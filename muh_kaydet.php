<?php
// =========================================================
// muh_kaydet.php — Genel Muhasebe, e-Dönüşüm & ERP-11a TÜM yazmaların TEK ucu (yalnız POST; GET → 405).
// Sıra: yöntem → require_login → no-store → JSON başlığı → csrf_check → işleme göre muh_kapi + şema kapısı → core → muh_cevap.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/muhasebe_core.php';
require_once __DIR__ . '/config/edonusum_core.php';
require_once __DIR__ . '/config/erp_mutabakat_core.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Yalnızca POST.';
    exit;
}

$auth_user = require_login();
muh_no_store();
$json = muh_json_istek();
if ($json) header('Content-Type: application/json; charset=utf-8');
csrf_check($_POST['csrf'] ?? null);

$pdo   = db();
$P     = $_POST;
$islem = trim((string)($P['islem'] ?? ''));
$geri  = muh_geri_guvenli((string)($P['geri'] ?? 'muh_yevmiye.php'));
$uid   = (int)($auth_user['id'] ?? 0);
$id    = (int)($P['id'] ?? 0);

try {
    switch ($islem) {
        case 'hesap_kaydet':
            muh_kapi($id > 0 ? 'edit' : 'create');
            muh_sayfa_kapisi($pdo, $json);
            $P['hareket_gorebilir'] = isset($P['hareket_gorebilir']) && (string)$P['hareket_gorebilir'] !== '0' ? 1 : 0;
            $s = muh_hesap_kaydet($pdo, $P, $uid);
            muh_cevap($s, $geri, 'Hesap kaydedildi.', $json);

        case 'hesap_durum':
            muh_kapi('edit');
            muh_sayfa_kapisi($pdo, $json);
            $s = muh_hesap_durum($pdo, $id, (int)($P['aktif'] ?? 0), $uid);
            muh_cevap($s, $geri, 'Hesap durumu güncellendi.', $json);

        case 'fis_kaydet':
            muh_kapi('create');
            muh_sayfa_kapisi($pdo, $json);
            $satirlar = [];
            if (is_array($P['satirlar'] ?? null)) {
                $satirlar = $P['satirlar'];
            } else {
                $hArr = (array)($P['satir_hesap'] ?? []);
                $bArr = (array)($P['satir_borc'] ?? []);
                $aArr = (array)($P['satir_alacak'] ?? []);
                $blArr = (array)($P['satir_belge'] ?? []);
                $acArr = (array)($P['satir_aciklama'] ?? []);
                foreach ($hArr as $i => $hk) {
                    $kod = trim((string)$hk);
                    $bv = muh_tutar_coz($bArr[$i] ?? 0);
                    $av = muh_tutar_coz($aArr[$i] ?? 0);
                    if ($kod === '' && $bv <= 0.0 && $av <= 0.0) continue;
                    $satirlar[] = [
                        'hesap_kod' => $kod,
                        'borc'      => $bv,
                        'alacak'    => $av,
                        'belge_no'  => (string)($blArr[$i] ?? ''),
                        'aciklama'  => (string)($acArr[$i] ?? ''),
                    ];
                }
            }
            $s = muh_fis_kaydet($pdo, [
                'tarih'    => (string)($P['tarih'] ?? ''),
                'tur'      => (string)($P['tur'] ?? 'mahsup'),
                'aciklama' => (string)($P['aciklama'] ?? ''),
                'depo'     => function_exists('active_depot') ? (string)(active_depot() ?? '') : '',
                'satirlar' => $satirlar,
            ], $uid);
            if (!empty($s['ok']) && !empty($s['id'])) {
                $geri = 'muh_yevmiye.php?fis_id=' . (int)$s['id'];
            }
            muh_cevap($s, $geri, 'Yevmiye fişi kaydedildi.', $json);

        case 'fis_iptal':
            muh_kapi('delete');
            muh_sayfa_kapisi($pdo, $json);
            $s = muh_fis_ters_iptal($pdo, $id, (string)($P['neden'] ?? ''), $uid);
            muh_cevap($s, $geri, 'Ters fiş oluşturuldu ve iptal edildi.', $json);

        case 'donem_ac':
            muh_kapi('create');
            muh_sayfa_kapisi($pdo, $json);
            $s = muh_donem_ac($pdo, (int)($P['yil'] ?? 0), $uid);
            muh_cevap($s, $geri, 'Mali dönem açıldı.', $json);

        case 'donem_kapat':
            muh_kapi('delete');
            muh_sayfa_kapisi($pdo, $json);
            $s = muh_donem_kapat($pdo, (int)($P['yil'] ?? 0), $uid);
            muh_cevap($s, $geri, 'Mali dönem kapatıldı.', $json);

        case 'alt_defter_mahsup':
            muh_kapi('create');
            muh_sayfa_kapisi($pdo, $json);
            $s = muh_alt_defter_mahsup($pdo, (string)($P['bas'] ?? ''), (string)($P['bit'] ?? ''), $uid);
            muh_cevap($s, $geri, 'Alt defter mahsubu tamamlandı.', $json);

        case 'edonusum_olustur':
            muh_kapi('create');
            edonusum_sayfa_kapisi($pdo, $json);
            $s = edonusum_zarf_olustur($pdo, $P, $uid);
            muh_cevap($s, $geri, 'e-Dönüşüm taslağı oluşturuldu.', $json);

        case 'edonusum_gonder':
            muh_kapi('edit');
            edonusum_sayfa_kapisi($pdo, $json);
            $s = edonusum_zarf_gonder($pdo, $id, $uid);
            muh_cevap($s, $geri, 'e-Dönüşüm zarfı gönderildi.', $json);

        case 'edonusum_durum':
            muh_kapi('read');
            edonusum_sayfa_kapisi($pdo, $json);
            $s = edonusum_zarf_durum_sorgula($pdo, $id);
            muh_cevap($s, $geri, 'Durum sorgulandı.', $json);

        case 'edonusum_gelen_yanit':
            muh_kapi('edit');
            edonusum_sayfa_kapisi($pdo, $json);
            $s = edonusum_gelen_yanitla($pdo, $id, (string)($P['yanit'] ?? ''), (string)($P['neden'] ?? ''), $uid);
            muh_cevap($s, $geri, 'Gelen belge yanıtlandı.', $json);

        case 'edonusum_ayar_kaydet':
            if (!is_admin()) { forbidden(); }
            edonusum_sayfa_kapisi($pdo, $json);
            $s = edonusum_ayar_kaydet($pdo, $P, $uid);
            muh_cevap($s, 'edonusum_belgeler.php?sekme=ayarlar', 'e-Logo entegratör ayarları kaydedildi.', $json);

        case 'edonusum_baglanti_test':
            muh_kapi('edit');
            edonusum_sayfa_kapisi($pdo, $json);
            $s = edonusum_baglanti_test($pdo);
            muh_cevap($s, 'edonusum_belgeler.php?sekme=ayarlar', 'e-Logo bağlantısı doğrulandı.', $json);

        case 'edonusum_gelen_cek':
            muh_kapi('edit');
            edonusum_sayfa_kapisi($pdo, $json);
            $s = edonusum_gelen_senkronize(
                $pdo,
                trim((string)($P['bas'] ?? date('Y-m-01'))),
                trim((string)($P['bit'] ?? date('Y-m-d'))),
                trim((string)($P['belge_turu'] ?? 'efatura')),
                $uid
            );
            muh_cevap($s, 'edonusum_belgeler.php?sekme=gelen', 'Gelen kutusu senkronize edildi.', $json);

        case 'risk_limit_kaydet':
            muh_kapi('edit');
            erp_mutabakat_sayfa_kapisi($pdo, $json);
            $s = cari_risk_limit_kaydet($pdo, $P, $uid);
            muh_cevap($s, $geri, 'Cari risk limiti kaydedildi.', $json);

        case 'kur_farki_uygula':
            muh_kapi('create');
            muh_sayfa_kapisi($pdo, $json);
            erp_mutabakat_sayfa_kapisi($pdo, $json);
            $s = erp_kur_farki_uygula($pdo, (string)($P['donem_kodu'] ?? ''), (string)($P['tarih'] ?? ''), $uid);
            muh_cevap($s, $geri, 'Kur farkı değerleme fişi oluşturuldu.', $json);

        default:
            muh_cevap(['ok' => false, 'hata' => 'Bilinmeyen işlem.', 'kod' => 422], $geri, '', $json);
    }
} catch (Throwable $e) {
    error_log('[muh_kaydet] ' . $e->getMessage());
    muh_cevap(['ok' => false, 'hata' => 'İşlem sırasında beklenmeyen bir hata oluştu.', 'kod' => 500], $geri, '', $json);
}
