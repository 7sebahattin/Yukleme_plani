<?php
// =========================================================
// scripts/fatura_ui_e2e_setup.php — scripts/fatura_ui_smoke.js / fatura_ui_render.php için KALICI dosya SQLite hazırlar (canlı DB'ye HİÇ dokunmaz).
//   php scripts/fatura_ui_e2e_setup.php <db-yolu> [ornek|bos|sema] [cok]   (cok: + Deneme Gıda için 40 sipariş — uzun kaynak listesi)
//   ornek = şema + uydurma örnek veri (fatura_t_ornek_veri) + GERÇEK fatura_kaydet ile örnek belgeler:
//           sipariş (Domates 1.000 kg + Kasa 100) → irsaliye (Domates 400 kg) · satış (2 satır) · alış · İhracat USD
//   bos   = şema + örnek kart/cari, belge YOK · sema = tablo YOK (uyarı kartı senaryosu)
// Çıktı: "hazir <mod>" + JSON {siparis, irsaliye, satis, alis, ihracat, cari{…}, kart{…}}
// =========================================================
$yol = (string)($argv[1] ?? '');
if ($yol === '') { fwrite(STDERR, "Kullanım: php scripts/fatura_ui_e2e_setup.php <db-yolu> [ornek|bos|sema]\n"); exit(2); }
putenv('FATURA_E2E_DB=' . $yol);
require_once __DIR__ . '/_fatura_test_lib.php';
$mod = (string)($argv[2] ?? 'ornek');
$db = db();
$R = [];
if ($mod !== 'sema') {
    fatura_t_sema_kur($db);
    $O = fatura_t_ornek_veri($db);
    $K = $O['kart']; $C = $O['fatura_cari'] + $O['cari'];
    $R['kart'] = $K; $R['cari'] = $C;
    if ($mod === 'ornek') {
        $kaydet = static function (string $tur, array $sat, array $ek) use ($db): array {
            $r = fatura_kaydet($db, fatura_t_istek($tur, $sat, $ek), 1);
            if (empty($r['ok'])) throw new RuntimeException("örnek $tur: " . ($r['hata'] ?? '?') . ' ' . json_encode($r, JSON_UNESCAPED_UNICODE));
            return $r;
        };
        $dom = $K['Domates (Salkım)']; $kasa = $K["Kasa 6'lı Plastik"];
        $market = $C['Örnek Market A.Ş.'];
        $sp = $kaydet('siparis', [['kart_id' => $dom, 'miktar' => '1.000', 'birim_fiyat' => '12,50', 'kdv_orani' => '1'], ['kart_id' => $kasa, 'miktar' => '100', 'birim_fiyat' => '2,00', 'kdv_orani' => '20']],
            ['cari_id' => (string)$market, 'fatura_no' => 'MS-1001', 'termin_tarihi' => date('Y-m-d', strtotime('+7 days'))]);
        $R['siparis'] = $sp['id'];
        $ss = fatura_oku($db, (int)$sp['id'])['satirlar'];
        $ir = $kaydet('irsaliye', [['kart_id' => $dom, 'miktar' => '400', 'birim_fiyat' => '12,50', 'kdv_orani' => '1', 'kaynak_satir_id' => (string)$ss[0]['id']]],
            ['cari_id' => (string)$market, 'plaka' => '00 TST 001']);
        $R['irsaliye'] = $ir['id'];
        $st = $kaydet('satis', [['kart_id' => $dom, 'miktar' => '1.250', 'birim_fiyat' => '12,3456', 'iskonto' => '2,5', 'kdv_orani' => '1'], ['urun_adi' => 'Nakliye hizmeti', 'birim' => 'adet', 'miktar' => '3', 'birim_fiyat' => '0,3333', 'kdv_orani' => '20']],
            ['cari_id' => (string)$market, 'odeme_sekli' => '']);
        $R['satis'] = $st['id'];
        $al = $kaydet('alis', [['kart_id' => $dom, 'miktar' => '500', 'birim_fiyat' => '9,00', 'kdv_orani' => '1']], ['cari_id' => (string)$C['Örnek Tarım Ltd.'], 'fatura_no' => 'TED-2026-77', 'stok_etkisi' => '1']);
        $R['alis'] = $al['id'];
        $ist = fatura_t_tanim($db, 'istisna', '301 — KDV 11/1-a Mal ihracatı');
        $ih = $kaydet('satis_ihracat', [['kart_id' => $dom, 'miktar' => '2.000', 'birim_fiyat' => '1,15', 'kdv_orani' => '0']], ['cari_id' => (string)$C['Örnek İhracat Ltd.'], 'para_birimi' => 'USD', 'kur' => '34,5678', 'istisna_id' => (string)$ist]);
        $R['ihracat'] = $ih['id'];
        if (in_array('cok', array_slice($argv, 3), true)) {   // uzun kaynak listesi (modal kaydırma testi): 40 sipariş
            $dg = $C['Deneme Gıda A.Ş.'];
            for ($i = 1; $i <= 40; $i++) $kaydet('siparis', [['kart_id' => $kasa, 'miktar' => (string)(10 + $i), 'birim_fiyat' => '2,00', 'kdv_orani' => '20']], ['cari_id' => (string)$dg, 'fatura_no' => 'DG-' . $i]);
        }
    }
}
echo "hazir $mod\n" . json_encode($R, JSON_UNESCAPED_UNICODE) . "\n";
