<?php
// =========================================================
// scripts/cari_ui_e2e_setup.php — scripts/cari_ui_smoke.js için KALICI dosya SQLite hazırlar (canlı DB'ye HİÇ dokunmaz).
//   php scripts/cari_ui_e2e_setup.php <db-yolu> [ornek|bos|sema]
//   ornek = şema + uydurma örnek veri (varsayılan) · bos = şema, veri yok · sema = tablo YOK (uyarı kartı senaryosu)
//   ek = şema + v611 örnekleri (hızlı eklenen müstahsil / müşteri kartı + normal müstahsil kartı; kimlikler SENTETİK)
// =========================================================
$yol = (string)($argv[1] ?? '');
if ($yol === '') { fwrite(STDERR, "Kullanım: php scripts/cari_ui_e2e_setup.php <db-yolu> [ornek|bos|sema]\n"); exit(2); }
putenv('CARI_E2E_DB=' . $yol);
require_once __DIR__ . '/_cari_test_lib.php';
$mod = (string)($argv[2] ?? 'ornek');
if ($mod !== 'sema') {
    cari_t_sema_kur(db());
    if ($mod === 'ek') {
        // v611 örnekleri: kimlik numaraları algoritmayla ÜRETİLMİŞ sentetik değerlerdir (gerçek kişi/firma değil)
        cari_kart_hizli_ekle(db(), ['unvan' => 'Hızlı Müstahsil Örnek', 'tip' => 'mustahsil'], 1);
        cari_kart_hizli_ekle(db(), ['unvan' => 'Hızlı Müşteri Örnek', 'tip' => 'musteri', 'telefon' => '0 555 000 00 01'], 1);
        cari_kart_kaydet(db(), ['unvan' => 'Normal Müstahsil Örnek', 'tip' => 'mustahsil', 'telefon' => '0 555 000 00 02', 'dogum_tarihi' => '1970-01-02', 'tckn' => '10000000146', 'para_birimi' => 'TRY', 'aktif' => '1'], 1);
    } elseif ($mod !== 'bos') {
        cari_t_ornek_veri(db());
    }
}
echo "hazir $mod\n";
