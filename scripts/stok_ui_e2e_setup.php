<?php
// =========================================================
// scripts/stok_ui_e2e_setup.php — scripts/stok_ui_smoke.js için KALICI dosya SQLite hazırlar (canlı DB'ye HİÇ dokunmaz).
//   php scripts/stok_ui_e2e_setup.php <db-yolu> [ornek|fisler|bos|sema]
//   ornek = şema + uydurma örnek veri (varsayılan) · fisler = ornek + Parça B örnek fişleri (gerçek stok_fis_kaydet) ·
//   bos = şema, veri yok · sema = tablo YOK (uyarı kartı senaryosu)
// =========================================================
$yol = (string)($argv[1] ?? '');
if ($yol === '') { fwrite(STDERR, "Kullanım: php scripts/stok_ui_e2e_setup.php <db-yolu> [ornek|bos|sema]\n"); exit(2); }
putenv('STOK_E2E_DB=' . $yol);
require_once __DIR__ . '/_stok_test_lib.php';
$mod = (string)($argv[2] ?? 'ornek');
if ($mod !== 'sema') {
    stok_t_sema_kur(db());
    if ($mod !== 'bos') { $O = stok_t_ornek_veri(db()); if ($mod === 'fisler') stok_t_ornek_fisler(db(), $O); }
}
echo "hazir $mod\n";
