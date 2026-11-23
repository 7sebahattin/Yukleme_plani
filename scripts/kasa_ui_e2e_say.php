<?php
// =========================================================
// scripts/kasa_ui_e2e_say.php — scripts/kasa_ui_smoke.js için DB GERÇEĞİ (tarayıcı görünümüyle karşılaştırılır). Yalnız okur.
//   php scripts/kasa_ui_e2e_say.php <db-yolu>  →  JSON {kasa, kasalar{id:{kod,ad,tip,pb,aktif,eksi_izin,bakiye}}, hareket, gecerli, hareketler[…], tanim{tur:adet}, cari_hareket, cari_hareketler[…], audit}
// =========================================================
$yol = (string)($argv[1] ?? '');
if ($yol === '' || !is_file($yol)) { fwrite(STDERR, "Kullanım: php scripts/kasa_ui_e2e_say.php <db-yolu>\n"); exit(2); }
putenv('KASA_E2E_DB=' . $yol);
require_once __DIR__ . '/_kasa_test_lib.php';
$db = db();
$one = static fn(string $q): int => (int)$db->query($q)->fetchColumn();
$kasalar = [];
foreach ($db->query('SELECT id, kod, ad, tip, para_birimi, aktif, eksi_izin, depo FROM kasa_kartlar ORDER BY id')->fetchAll() as $k) {
    $kasalar[(int)$k['id']] = ['kod' => $k['kod'], 'ad' => $k['ad'], 'tip' => $k['tip'], 'pb' => $k['para_birimi'], 'aktif' => (int)$k['aktif'], 'eksi_izin' => (int)$k['eksi_izin'], 'depo' => $k['depo'], 'bakiye' => kasa_bakiye($db, (int)$k['id'])];
}
$tanim = []; foreach ($db->query('SELECT tur, COUNT(*) n FROM kasa_tanimlar GROUP BY tur')->fetchAll() as $r) $tanim[$r['tur']] = (int)$r['n'];
$cariVar = cari_hazir($db);
echo json_encode(['kasa' => $one('SELECT COUNT(*) FROM kasa_kartlar'), 'kasalar' => $kasalar, 'hareket' => $one('SELECT COUNT(*) FROM kasa_hareketler'), 'gecerli' => $one("SELECT COUNT(*) FROM kasa_hareketler WHERE durum = 'gecerli'"),
    'hareketler' => $db->query('SELECT id, kasa_id, tur, yon, tutar, para_birimi, belge_no, cari_id, cari_hareket_id, kalem_id, aciklama, bagli_id, durum, iptal_neden, istek_anahtari FROM kasa_hareketler ORDER BY id')->fetchAll(),
    'tanim' => $tanim, 'tanimlar' => $db->query('SELECT id, tur, ad, aktif FROM kasa_tanimlar ORDER BY id')->fetchAll(),
    'cari_hareket' => $cariVar ? $one('SELECT COUNT(*) FROM cari_hareketler') : 0,
    'cari_hareketler' => $cariVar ? $db->query('SELECT id, cari_id, tur, yon, tutar, para_birimi, durum, belge_no, kaynak_modul, kaynak_id FROM cari_hareketler ORDER BY id')->fetchAll() : [],
    'cariler' => $cariVar ? $db->query('SELECT id, kod, unvan FROM cari_kartlar ORDER BY id')->fetchAll() : [],
    'audit' => $one("SELECT COUNT(*) FROM audit_log WHERE module = 'kasa'")], JSON_UNESCAPED_UNICODE);
