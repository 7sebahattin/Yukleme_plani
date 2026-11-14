<?php
// =========================================================
// scripts/fatura_ui_e2e_say.php — scripts/fatura_ui_smoke.js için DB GERÇEĞİ (tarayıcı görünümüyle karşılaştırılır). Yalnız okur.
//   php scripts/fatura_ui_e2e_say.php <db-yolu> → JSON {belge, belgeler[…], satir, stok_fis, stok_fisler[…], stok_hareket, cari_hareket, cari_hareketler[…], tanim, tanimlar[…]}
// =========================================================
$yol = (string)($argv[1] ?? '');
if ($yol === '' || !is_file($yol)) { fwrite(STDERR, "Kullanım: php scripts/fatura_ui_e2e_say.php <db-yolu>\n"); exit(2); }
putenv('FATURA_E2E_DB=' . $yol);
require_once __DIR__ . '/_fatura_test_lib.php';
$db = db();
$one = static fn(string $q): int => (int)$db->query($q)->fetchColumn();
echo json_encode([
    'belge' => $one('SELECT COUNT(*) FROM fatura_belgeler'),
    'belgeler' => $db->query('SELECT id, tur, belge_no, fatura_no, durum, kapali, cari_id, para_birimi, genel_toplam, cari_tutar, stok_fis_id, cari_hareket_id, uyari, istek_anahtari FROM fatura_belgeler ORDER BY id')->fetchAll(),
    'satir' => $one('SELECT COUNT(*) FROM fatura_satirlar'),
    'satirlar' => $db->query('SELECT id, belge_id, kart_id, urun_adi, miktar, birim_fiyat, tutar, kdv_tutar, kaynak_satir_id, stoklu FROM fatura_satirlar ORDER BY id')->fetchAll(),
    'stok_fis' => $one('SELECT COUNT(*) FROM stok_fisler'),
    'stok_fisler' => $db->query('SELECT id, tur, fis_no, durum, kaynak_modul, kaynak_id, uyari FROM stok_fisler ORDER BY id')->fetchAll(),
    'stok_hareket' => $one('SELECT COUNT(*) FROM stok_hareketler'),
    'cari_hareket' => $one('SELECT COUNT(*) FROM cari_hareketler'),
    'cari_hareketler' => $db->query('SELECT id, tur, cari_id, tutar, para_birimi, durum, kaynak_modul, kaynak_id FROM cari_hareketler ORDER BY id')->fetchAll(),
    'tanim' => $one('SELECT COUNT(*) FROM fatura_tanimlar'),
    'tanimlar' => $db->query('SELECT id, tur, ad, kod, deger1, varsayilan, aktif FROM fatura_tanimlar ORDER BY id')->fetchAll(),
    'audit' => $one("SELECT COUNT(*) FROM audit_log WHERE module = 'fatura'"),
], JSON_UNESCAPED_UNICODE);
