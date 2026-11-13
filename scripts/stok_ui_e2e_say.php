<?php
// =========================================================
// scripts/stok_ui_e2e_say.php — scripts/stok_ui_smoke.js için DB GERÇEĞİ (tarayıcı görünümüyle karşılaştırılır). Yalnız okur.
//   php scripts/stok_ui_e2e_say.php <db-yolu>   →  JSON {kart, aktif, kartlar{id:{kod,ad,aktif,birim,bakiye}}, tanim{tur:adet}, tanimlar[…], hareket}
// =========================================================
$yol = (string)($argv[1] ?? '');
if ($yol === '' || !is_file($yol)) { fwrite(STDERR, "Kullanım: php scripts/stok_ui_e2e_say.php <db-yolu>\n"); exit(2); }
putenv('STOK_E2E_DB=' . $yol);
require_once __DIR__ . '/_stok_test_lib.php';
$db = db();
$one = static fn(string $q): int => (int)$db->query($q)->fetchColumn();
$kartlar = [];
foreach ($db->query('SELECT id, kod, ad, aktif, ana_birim, kritik_seviye, cevrim_birim, cevrim_katsayi, grup_id FROM stok_kartlar ORDER BY id')->fetchAll() as $k) {
    $kartlar[(int)$k['id']] = ['kod' => $k['kod'], 'ad' => $k['ad'], 'aktif' => (int)$k['aktif'], 'birim' => $k['ana_birim'], 'kritik' => $k['kritik_seviye'] !== null ? stok_milli($k['kritik_seviye']) : null,
        'cevrim_birim' => $k['cevrim_birim'], 'cevrim' => $k['cevrim_katsayi'] !== null ? stok_milli($k['cevrim_katsayi']) : null, 'grup_id' => $k['grup_id'] !== null ? (int)$k['grup_id'] : null,
        'bakiye' => stok_bakiye($db, (int)$k['id'])['toplam_milli']];
}
$tanim = []; foreach ($db->query('SELECT tur, COUNT(*) n FROM stok_tanimlar GROUP BY tur')->fetchAll() as $r) $tanim[$r['tur']] = (int)$r['n'];
echo json_encode(['kart' => $one('SELECT COUNT(*) FROM stok_kartlar'), 'aktif' => $one('SELECT COUNT(*) FROM stok_kartlar WHERE aktif = 1'), 'kartlar' => $kartlar, 'tanim' => $tanim,
    'tanimlar' => $db->query('SELECT id, tur, ad, kod, sira, aktif FROM stok_tanimlar ORDER BY id')->fetchAll(), 'hareket' => $one('SELECT COUNT(*) FROM stok_hareketler'),
    'audit' => $one("SELECT COUNT(*) FROM audit_log WHERE module = 'stok'")], JSON_UNESCAPED_UNICODE);
