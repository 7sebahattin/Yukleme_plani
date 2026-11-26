<?php
// =========================================================
// erp_op_kaydet.php — ERP Faz 2 TÜM yazmaların TEK ucu (yalnız POST; GET → 405).
// Kapsam: WMS Stok Profil/Raf/Lot · Araç Yükleme v2 · Satın Alma & 3-Way Match · Üretim BOM/İş Emri
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/wms_stok_core.php';
require_once __DIR__ . '/config/yukleme_v2_core.php';
require_once __DIR__ . '/config/satinalma_core.php';
require_once __DIR__ . '/config/uretim_bom_core.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Yalnızca POST.';
    exit;
}

$auth_user = require_login();
erp_op_no_store();
$json = erp_op_json_istek();
if ($json) header('Content-Type: application/json; charset=utf-8');
csrf_check($_POST['csrf'] ?? null);

$pdo   = db();
$P     = $_POST;
$islem = trim((string)($P['islem'] ?? ''));
$geri  = erp_op_geri_guvenli((string)($P['geri'] ?? 'wms_stok_yonetim.php'));
$uid   = (int)($auth_user['id'] ?? 0);
$id    = (int)($P['id'] ?? 0);

try {
    switch ($islem) {
        case 'wms_profil_kaydet':
            erp_op_kapi('edit');
            wms_stok_sayfa_kapisi($pdo, $json);
            $s = wms_stok_profil_kaydet($pdo, $P, $uid);
            erp_op_cevap($s, $geri, 'Stok WMS profili kaydedildi.', $json);

        case 'wms_raf_kaydet':
            erp_op_kapi('create');
            wms_stok_sayfa_kapisi($pdo, $json);
            $s = wms_raf_kaydet($pdo, $P, $uid);
            erp_op_cevap($s, $geri, 'Raf lokasyonu kaydedildi.', $json);

        case 'wms_lot_giris':
            erp_op_kapi('create');
            wms_stok_sayfa_kapisi($pdo, $json);
            $s = wms_lot_giris($pdo, $P, $uid);
            erp_op_cevap($s, $geri, 'Lot girişi kaydedildi.', $json);

        case 'yukleme_v2_kaydet':
            erp_op_kapi($id > 0 ? 'edit' : 'create');
            yukleme_v2_sayfa_kapisi($pdo, $json);
            $paletler = [];
            if (is_array($P['paletler'] ?? null)) {
                $paletler = $P['paletler'];
            } else {
                $pNoArr = (array)($P['palet_no'] ?? []);
                $uIdArr = (array)($P['urun_stok_id'] ?? []);
                $uAdArr = (array)($P['urun_ad'] ?? []);
                $lotArr = (array)($P['lot_no'] ?? []);
                $kaArr  = (array)($P['kasa_adeti'] ?? []);
                $kdArr  = (array)($P['kasa_dara_kg'] ?? []);
                $pdArr  = (array)($P['palet_dara_kg'] ?? []);
                $brArr  = (array)($P['brut_kg'] ?? []);
                foreach ($pNoArr as $i => $pn) {
                    $br = erp_op_sayi($brArr[$i] ?? 0);
                    $ua = trim((string)($uAdArr[$i] ?? ''));
                    if ($br <= 0.0 && $ua === '') continue;
                    $paletler[] = [
                        'palet_no'      => (string)$pn,
                        'urun_stok_id'  => (int)($uIdArr[$i] ?? 0),
                        'urun_ad'       => $ua,
                        'lot_no'        => (string)($lotArr[$i] ?? ''),
                        'kasa_adeti'    => (int)($kaArr[$i] ?? 0),
                        'kasa_dara_kg'  => $kdArr[$i] ?? 0,
                        'palet_dara_kg' => $pdArr[$i] ?? 22,
                        'brut_kg'       => $br,
                    ];
                }
            }
            $P['paletler'] = $paletler;
            $s = yukleme_v2_fis_kaydet($pdo, $P, $uid);
            if (!empty($s['ok']) && !empty($s['id'])) $geri = 'yukleme_v2.php?id=' . (int)$s['id'];
            erp_op_cevap($s, $geri, 'Yükleme fişi kaydedildi.', $json);

        case 'yukleme_v2_kilitle':
            erp_op_kapi('edit');
            yukleme_v2_sayfa_kapisi($pdo, $json);
            $s = yukleme_v2_kilitle_ve_sevk($pdo, $id, $uid, true);
            erp_op_cevap($s, $geri, 'Yükleme fişi kilitlendi.', $json);

        case 'yukleme_v2_revizyon':
            erp_op_kapi('edit');
            yukleme_v2_sayfa_kapisi($pdo, $json);
            $s = yukleme_v2_revizyon_ac($pdo, $id, (string)($P['neden'] ?? ''), $uid);
            erp_op_cevap($s, $geri, 'Fiş revizyona açıldı.', $json);

        case 'sa_talep_kaydet':
            erp_op_kapi('create');
            satinalma_sayfa_kapisi($pdo, $json);
            $satirlar = [];
            if (is_array($P['satirlar'] ?? null)) {
                $satirlar = $P['satirlar'];
            } else {
                $adArr = (array)($P['kalem_ad'] ?? []);
                $brArr = (array)($P['kalem_birim'] ?? []);
                $mkArr = (array)($P['kalem_miktar'] ?? []);
                $fyArr = (array)($P['kalem_fiyat'] ?? []);
                foreach ($adArr as $i => $ad) {
                    if (trim((string)$ad) === '') continue;
                    $satirlar[] = [
                        'urun_ad'     => (string)$ad,
                        'birim'       => (string)($brArr[$i] ?? 'kg'),
                        'miktar'      => $mkArr[$i] ?? 0,
                        'hedef_fiyat' => $fyArr[$i] ?? 0,
                    ];
                }
            }
            $P['satirlar'] = $satirlar;
            $s = sa_talep_kaydet($pdo, $P, $uid);
            erp_op_cevap($s, $geri, 'Satın alma talebi kaydedildi.', $json);

        case 'sa_teklif_ekle':
            erp_op_kapi('create');
            satinalma_sayfa_kapisi($pdo, $json);
            $s = sa_teklif_ekle($pdo, $P, $uid);
            erp_op_cevap($s, $geri, 'Teklif eklendi.', $json);

        case 'sa_siparis_olustur':
            erp_op_kapi('create');
            satinalma_sayfa_kapisi($pdo, $json);
            $s = sa_siparis_olustur($pdo, $P, $uid);
            erp_op_cevap($s, $geri, 'Satın alma siparişi oluşturuldu.', $json);

        case 'sa_mal_kabul_kaydet':
            erp_op_kapi('create');
            satinalma_sayfa_kapisi($pdo, $json);
            $s = sa_mal_kabul_kaydet($pdo, $P, $uid);
            erp_op_cevap($s, $geri, 'Mal kabul kaydedildi.', $json);

        case 'sa_uc_yonlu_eslestir':
            erp_op_kapi('edit');
            satinalma_sayfa_kapisi($pdo, $json);
            if (!empty($P['kabul_pair']) && str_contains((string)$P['kabul_pair'], ':')) {
                [$sidP, $kidP] = explode(':', (string)$P['kabul_pair'], 2);
                $P['siparis_id'] = (int)$sidP;
                $P['kabul_id']   = (int)$kidP;
            }
            $s = sa_uc_yonlu_eslestir($pdo, $P, $uid);
            erp_op_cevap($s, $geri, '3-Way Match tamamlandı.', $json);

        case 'uretim_bom_kaydet':
            erp_op_kapi('create');
            uretim_bom_sayfa_kapisi($pdo, $json);
            $satirlar = [];
            if (is_array($P['satirlar'] ?? null)) {
                $satirlar = $P['satirlar'];
            } else {
                $sArr = (array)($P['bil_stok'] ?? []);
                $aArr = (array)($P['bil_ad'] ?? []);
                $tArr = (array)($P['bil_tip'] ?? []);
                $mArr = (array)($P['bil_miktar'] ?? []);
                $fArr = (array)($P['bil_fire'] ?? []);
                foreach ($sArr as $i => $bsid) {
                    if ((int)$bsid <= 0) continue;
                    $satirlar[] = [
                        'bilesen_stok_id' => (int)$bsid,
                        'bilesen_ad'      => (string)($aArr[$i] ?? ''),
                        'bilesen_tip'     => (string)($tArr[$i] ?? 'hammadde'),
                        'miktar'          => $mArr[$i] ?? 0,
                        'fire_yuzde'      => $fArr[$i] ?? 0,
                    ];
                }
            }
            $P['satirlar'] = $satirlar;
            $s = uretim_bom_kaydet($pdo, $P, $uid);
            erp_op_cevap($s, $geri, 'Üretim reçetesi kaydedildi.', $json);

        case 'uretim_is_emri_ac':
            erp_op_kapi('create');
            uretim_bom_sayfa_kapisi($pdo, $json);
            $s = uretim_is_emri_ac($pdo, $P, $uid);
            erp_op_cevap($s, $geri, 'İş emri açıldı.', $json);

        case 'uretim_is_emri_tamamla':
            erp_op_kapi('edit');
            uretim_bom_sayfa_kapisi($pdo, $json);
            $s = uretim_is_emri_tamamla($pdo, $id, $P, $uid);
            erp_op_cevap($s, $geri, 'İş emri tamamlandı.', $json);

        default:
            erp_op_cevap(['ok' => false, 'hata' => 'Bilinmeyen işlem.', 'kod' => 422], $geri, '', $json);
    }
} catch (Throwable $e) {
    error_log('[erp_op_kaydet] ' . $e->getMessage());
    erp_op_cevap(['ok' => false, 'hata' => 'İşlem sırasında beklenmeyen bir hata oluştu.', 'kod' => 500], $geri, '', $json);
}
