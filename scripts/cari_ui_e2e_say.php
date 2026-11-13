<?php
// =========================================================
// scripts/cari_ui_e2e_say.php — scripts/cari_ui_smoke.js için DB GERÇEĞİ (tarayıcı görünümüyle karşılaştırılır). Yalnız okur.
//   php scripts/cari_ui_e2e_say.php <db-yolu>   →  JSON {kart, hareket, gecerli, iptal, bakiye{id:{pb:kurus}}, tur{tur:adet}, tanim{tur:adet}, son:[…]}
// =========================================================
$yol = (string)($argv[1] ?? '');
if ($yol === '' || !is_file($yol)) { fwrite(STDERR, "Kullanım: php scripts/cari_ui_e2e_say.php <db-yolu>\n"); exit(2); }
putenv('CARI_E2E_DB=' . $yol);
require_once __DIR__ . '/_cari_test_lib.php';
$db = db();
$one = static fn(string $q): int => (int)$db->query($q)->fetchColumn();
$bak = []; $kartlar = [];
foreach ($db->query('SELECT id, unvan, aktif FROM cari_kartlar ORDER BY id')->fetchAll() as $k) {
    $kartlar[(int)$k['id']] = ['unvan' => $k['unvan'], 'aktif' => (int)$k['aktif']];
    foreach (cari_bakiye($db, (int)$k['id']) as $pb => $b) $bak[(int)$k['id']][$pb] = (int)$b['bakiye'];
}
$tur = []; foreach ($db->query("SELECT tur, COUNT(*) n FROM cari_hareketler GROUP BY tur")->fetchAll() as $r) $tur[$r['tur']] = (int)$r['n'];
$tanim = []; foreach ($db->query("SELECT tur, COUNT(*) n FROM cari_tanimlar GROUP BY tur")->fetchAll() as $r) $tanim[$r['tur']] = (int)$r['n'];
$son = $db->query("SELECT id, cari_id, tur, yon, tutar, para_birimi, durum, belge_no, aciklama FROM cari_hareketler ORDER BY id DESC LIMIT 6")->fetchAll();
echo json_encode(['kart' => $one('SELECT COUNT(*) FROM cari_kartlar'), 'hareket' => $one('SELECT COUNT(*) FROM cari_hareketler'), 'gecerli' => $one("SELECT COUNT(*) FROM cari_hareketler WHERE durum='gecerli'"),
    'iptal' => $one("SELECT COUNT(*) FROM cari_hareketler WHERE durum='iptal'"), 'kartlar' => $kartlar, 'bakiye' => $bak, 'tur' => $tur, 'tanim' => $tanim, 'son' => $son], JSON_UNESCAPED_UNICODE);
