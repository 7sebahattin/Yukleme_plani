<?php
// =========================================================
// scripts/ceksenet_ui_e2e_setup.php — scripts/ceksenet_ui_smoke.js için KALICI dosya SQLite hazırlar (canlı DB'ye HİÇ dokunmaz).
//   php scripts/ceksenet_ui_e2e_setup.php <db-yolu> [ornek|fisler|bos|sema|kasasiz]
//   ornek = cari + kasa + çek-senet şeması + 3 cari + 4 kasa + 1 çek karnesi (Örnek Banka TL; hareket YOK) · fisler = ornek + örnek bordrolar (gerçek ceksenet_bordro_kaydet / ceksenet_islem_uygula) ·
//   bos = şema, cari + kasa YOK · kasasiz = şema + 1 cari, kasa YOK · sema = tablo YOK (uyarı kartı senaryosu)
// =========================================================
$yol = (string)($argv[1] ?? '');
if ($yol === '') { fwrite(STDERR, "Kullanım: php scripts/ceksenet_ui_e2e_setup.php <db-yolu> [ornek|fisler|bos|sema|kasasiz]\n"); exit(2); }
putenv('CEKSENET_E2E_DB=' . $yol);
require_once __DIR__ . '/_ceksenet_test_lib.php';
$mod = (string)($argv[2] ?? 'ornek');
if ($mod !== 'sema') {
    $db = db();
    ceksenet_t_sema_kur($db);
    if ($mod === 'kasasiz') {
        $r = cari_kart_kaydet($db, ['unvan' => 'ÖRNEK TİCARET A.Ş.', 'tip' => 'musteri', 'para_birimi' => 'TRY', 'aktif' => '1'], 1);
    } elseif ($mod === 'ornek' || $mod === 'fisler') {
        $O = ceksenet_t_ornek_veri($db);
        $K = $O['kasa']; $C = $O['cari'];
        $hep = static function (array $r) { if (empty($r['ok'])) throw new RuntimeException('örnek: ' . json_encode($r, JSON_UNESCAPED_UNICODE)); return $r; };
        $hep(ceksenet_tanim_kaydet($db, ['tur' => 'karne', 'ad' => 'Karne A', 'kasa_id' => (string)$K['Örnek Banka TL'], 'ilk_no' => '1000001', 'yaprak' => '25', 'sira' => '10'], 1));
        if ($mod === 'fisler') {
            $gun = static fn(int $f): string => date('Y-m-d', strtotime(($f >= 0 ? '+' : '') . $f . ' days'));
            $satir = static function (array $satirlar): array { $g = []; foreach ($satirlar as $s) foreach ($s as $k => $v) $g[$k][] = $v; return $g; };
            $cek = static fn(string $no, string $banka, string $tutar, int $vade, string $kes = 'ÖRNEK KEŞİDECİ A.Ş.') => ['seri_no' => $no, 'banka_ad' => $banka, 'sube' => 'Merkez', 'hesap_no' => '123456', 'borclu' => $kes, 'yer_adi' => 'İSTANBUL', 'duzenleme_tarihi' => '', 'vade' => $gun($vade), 'tutar' => $tutar, 'para_birimi' => 'TRY', 'karne_id' => '', 'kefil' => ''];
            $r1 = $hep(ceksenet_bordro_kaydet($db, ['sinif' => 'cek', 'yon' => 'alinan', 'cari_id' => (string)$C['ÖRNEK TİCARET A.Ş.'], 'tarih' => $gun(0), 'aciklama' => 'Örnek giriş bordrosu'] + $satir([
                $cek('2000011', 'Ziraat Bankası', '12.500,00', -9), $cek('2000012', 'Halkbank', '8.250,50', 4), $cek('2000013', 'Garanti BBVA', '30.000,00', 45), $cek('2000014', 'Akbank', '5.000,00', 90, 'ÖRNEK BORÇLU LTD.')]), 1));
            $hep(ceksenet_bordro_kaydet($db, ['sinif' => 'cek', 'yon' => 'alinan', 'cari_id' => (string)$C['ÖRNEK TEDARİK LTD.'], 'tarih' => $gun(0)] + $satir([
                ['seri_no' => '3000001', 'banka_ad' => 'İş Bankası', 'sube' => '', 'hesap_no' => '', 'borclu' => 'ÖRNEK KEŞİDECİ A.Ş.', 'yer_adi' => '', 'duzenleme_tarihi' => '', 'vade' => $gun(20), 'tutar' => '1.200,00', 'para_birimi' => 'USD', 'karne_id' => '', 'kefil' => '']]), 1));
            $ids = $r1['idler'];
            $hep(ceksenet_islem_uygula($db, ['gecis' => 'ciro', 'ids' => [$ids[3]], 'tarih' => $gun(0), 'cari_id' => (string)$C['ÖRNEK NAKLİYAT LTD.']], 1));
            $hep(ceksenet_islem_uygula($db, ['gecis' => 'tahsile_ver', 'ids' => [$ids[2]], 'tarih' => $gun(0), 'kasa_id' => (string)$K['Örnek Banka TL']], 1));
            $sen = static fn(string $no, string $tutar, int $vade) => ['seri_no' => $no, 'banka_ad' => '', 'sube' => '', 'hesap_no' => '', 'borclu' => 'ÖRNEK BORÇLU LTD.', 'kefil' => 'ÖRNEK KEFİL', 'yer_adi' => 'ANKARA', 'duzenleme_tarihi' => $gun(-10), 'vade' => $gun($vade), 'tutar' => $tutar, 'para_birimi' => 'TRY', 'karne_id' => ''];
            $hep(ceksenet_bordro_kaydet($db, ['sinif' => 'senet', 'yon' => 'alinan', 'cari_id' => (string)$C['ÖRNEK TİCARET A.Ş.'], 'tarih' => $gun(0)] + $satir([$sen('S-001', '15.000,00', 30), $sen('S-002', '7.500,00', 60)]), 1));
            $kr = ceksenet_karne_secenekleri($db)[0];
            $hep(ceksenet_bordro_kaydet($db, ['sinif' => 'cek', 'yon' => 'verilen', 'cari_id' => (string)$C['ÖRNEK TEDARİK LTD.'], 'tarih' => $gun(0)] + $satir([
                ['seri_no' => '1000001', 'banka_ad' => '', 'sube' => '', 'hesap_no' => '', 'borclu' => '', 'yer_adi' => '', 'duzenleme_tarihi' => '', 'vade' => $gun(15), 'tutar' => '9.000,00', 'para_birimi' => 'TRY', 'karne_id' => (string)$kr['id'], 'kefil' => '']]), 1));
        }
    }
}
echo "hazir $mod\n";
