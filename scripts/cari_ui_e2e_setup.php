<?php
// =========================================================
// scripts/cari_ui_e2e_setup.php — scripts/cari_ui_smoke.js için KALICI dosya SQLite hazırlar (canlı DB'ye HİÇ dokunmaz).
//   php scripts/cari_ui_e2e_setup.php <db-yolu> [ornek|bos|sema]
//   ornek = şema + uydurma örnek veri (varsayılan) · bos = şema, veri yok · sema = tablo YOK (uyarı kartı senaryosu)
// =========================================================
$yol = (string)($argv[1] ?? '');
if ($yol === '') { fwrite(STDERR, "Kullanım: php scripts/cari_ui_e2e_setup.php <db-yolu> [ornek|bos|sema]\n"); exit(2); }
putenv('CARI_E2E_DB=' . $yol);
require_once __DIR__ . '/_cari_test_lib.php';
$mod = (string)($argv[2] ?? 'ornek');
if ($mod !== 'sema') {
    cari_t_sema_kur(db());
    if ($mod !== 'bos') cari_t_ornek_veri(db());
}
echo "hazir $mod\n";
