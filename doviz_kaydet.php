<?php
// =========================================================
// doviz_kaydet.php — Döviz & Günlük Kurlar: TÜM yazmaların TEK ucu (yalnız POST; GET → 405). kasa_kaydet.php aynası.
// Sıra: yöntem → require_login → no-store → JSON başlığı → csrf_check (yazmadan ÖNCE) → şema kapısı →
//       işleme göre doviz_kapi → yalnız config/doviz_core.php fonksiyonu → doviz_cevap.
// İşlemler (`islem`):
//   tcmb_cek (write) · tcmb_aralik_cek (write) · kur_kaydet (write) · kur_sil (delete) ·
//   tanim_kaydet (admin) · tanim_durum (admin) · tanim_sil (admin).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/doviz_core.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Yalnızca POST.';
    exit;
}

$auth_user = require_login();
doviz_no_store();
$json = doviz_json_istek();
if ($json) header('Content-Type: application/json; charset=utf-8'); // csrf_check başarısızlığı da JSON döner
csrf_check($_POST['csrf'] ?? null);
$pdo = db();
doviz_sayfa_kapisi($pdo);

$islem = (string)($_POST['islem'] ?? '');
$geri  = doviz_geri_guvenli($_POST['geri'] ?? '');
$P     = $_POST;
$uid   = (int)($auth_user['id'] ?? 0);
$id    = (int)($P['id'] ?? 0);

try {
    switch ($islem) {
        case 'tcmb_cek':
            doviz_kapi('write');
            $tarih      = trim((string)($P['tarih'] ?? ''));
            $uzerineYaz = !empty($P['uzerine_yaz']) && (string)$P['uzerine_yaz'] !== '0';
            $s = doviz_tcmb_senkron($pdo, $tarih !== '' ? $tarih : null, $uid, $uzerineYaz, true);
            $bultenTarih = (string)($s['tarih'] ?? '');
            if (!empty($s['ok'])) {
                $bultenTr = date('d.m.Y', strtotime($bultenTarih));
                $istatistik = sprintf(
                    '%d eklendi, %d güncellendi%s',
                    (int)($s['eklenen'] ?? 0),
                    (int)($s['guncellenen'] ?? 0),
                    !empty($s['atlanan']) ? ', ' . (int)$s['atlanan'] . ' manuel korundu' : ''
                );
                if ($tarih !== '' && $bultenTarih !== '' && $bultenTarih !== $tarih) {
                    $istenenTr = date('d.m.Y', strtotime($tarih));
                    $mesaj = sprintf(
                        'TCMB %s bülteni henüz yayımlanmadı (iş günleri 15:30\'da yayımlanır). En güncel bülten olan %s yüklendi (%s).',
                        $istenenTr,
                        $bultenTr,
                        $istatistik
                    );
                } else {
                    $mesaj = sprintf('TCMB kurları güncellendi (%s · %s)', $bultenTr, $istatistik);
                }
            } else {
                $mesaj = '';
            }
            if (!empty($s['ok']) && !str_contains($geri, 'doviz_tanimlari.php')) {
                $geri = 'gunluk_kurlar.php?tarih=' . rawurlencode($bultenTarih);
            }
            doviz_cevap($s, $geri, $mesaj, $json);

        case 'tcmb_aralik_cek':
            doviz_kapi('write');
            $gunSayisi = (int)($P['gun_sayisi'] ?? 7);
            $s = doviz_tcmb_aralik_senkron($pdo, $gunSayisi, $uid);
            $mesaj = !empty($s['ok'])
                ? sprintf(
                    'Toplu TCMB senkronu tamamlandı: %d gün çekildi, %d gün zaten mevcuttu.',
                    (int)($s['senkron_gun'] ?? 0),
                    (int)($s['mevcut_gun'] ?? 0)
                )
                : '';
            doviz_cevap($s, $geri, $mesaj, $json);

        case 'kur_kaydet':
            doviz_kapi('write');
            $s = doviz_kur_kaydet($pdo, $P, $uid);
            if (!empty($s['ok']) && !empty($s['tarih']) && !str_contains($geri, 'doviz_tanimlari.php')) {
                $geri = 'gunluk_kurlar.php?tarih=' . rawurlencode((string)$s['tarih']);
            }
            doviz_cevap($s, $geri, 'Manuel kur kaydedildi', $json);

        case 'kur_sil':
            doviz_kapi('delete');
            $s = doviz_kur_sil($pdo, $id, $uid);
            doviz_cevap($s, $geri, 'Kur kaydı silindi', $json);

        case 'tanim_kaydet':
            doviz_kapi('admin');
            $P['aktif']      = isset($P['aktif']) && (string)$P['aktif'] !== '0' ? '1' : '0';
            $P['tcmb_aktif'] = isset($P['tcmb_aktif']) && (string)$P['tcmb_aktif'] !== '0' ? '1' : '0';
            $s = doviz_tanim_kaydet($pdo, $P, $uid);
            doviz_cevap($s, $geri, $id > 0 ? 'Para birimi güncellendi' : 'Para birimi eklendi', $json);

        case 'tanim_durum':
            doviz_kapi('admin');
            $alan  = (string)($P['alan'] ?? 'aktif');
            $deger = !empty($P['deger']) && (string)$P['deger'] !== '0' ? 1 : 0;
            $s = doviz_tanim_durum($pdo, $id, $alan, $deger, $uid);
            doviz_cevap($s, $geri, 'Para birimi durumu güncellendi', $json);

        case 'tanim_sil':
            doviz_kapi('admin');
            $s = doviz_tanim_sil($pdo, $id, $uid);
            doviz_cevap($s, $geri, 'Para birimi silindi', $json);

        default:
            doviz_cevap(['ok' => false, 'hata' => 'Bilinmeyen işlem.'], $geri, '', $json);
    }
} catch (PDOException $e) {
    error_log('[doviz_kaydet] ' . $islem . ': ' . $e->getMessage());
    doviz_cevap(['ok' => false, 'hata' => 'İşlem sırasında sunucu hatası oluştu.', 'kod' => 'sunucu_hatasi'], $geri, '', $json);
}
