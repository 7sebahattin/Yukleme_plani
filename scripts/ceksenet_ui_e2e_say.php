<?php
// =========================================================
// scripts/ceksenet_ui_e2e_say.php — scripts/ceksenet_ui_smoke.js için DB GERÇEĞİ (tarayıcı görünümüyle karşılaştırılır). Yalnız okur.
//   php scripts/ceksenet_ui_e2e_say.php <db-yolu> [id,id,…]  →  (ikinci argüman: seçim özeti ceksenet_secim_ozeti → 'secim')   JSON {kayit, kayitlar[…], hareket, gecerli, hareketler[…], tanim{tur:adet}, kasalar{id:{ad,tip,pb,bakiye}}, cari_hareketler[…], kasa_hareketler[…], cariler[…], ortalama{bordro_no:{pb:{gun,tarih}}}, audit}
// =========================================================
$yol = (string)($argv[1] ?? '');
if ($yol === '' || !is_file($yol)) { fwrite(STDERR, "Kullanım: php scripts/ceksenet_ui_e2e_say.php <db-yolu>\n"); exit(2); }
putenv('CEKSENET_E2E_DB=' . $yol);
require_once __DIR__ . '/_ceksenet_test_lib.php';
$db = db();
$one = static fn(string $q): int => (int)$db->query($q)->fetchColumn();
$kasalar = [];
foreach ($db->query('SELECT id, kod, ad, tip, para_birimi FROM kasa_kartlar ORDER BY id')->fetchAll() as $k) $kasalar[(int)$k['id']] = ['ad' => $k['ad'], 'tip' => $k['tip'], 'pb' => $k['para_birimi'], 'bakiye' => kasa_bakiye($db, (int)$k['id'])];
$tanim = []; foreach ($db->query('SELECT tur, COUNT(*) n FROM ceksenet_tanimlar GROUP BY tur')->fetchAll() as $r) $tanim[$r['tur']] = (int)$r['n'];
$kayitlar = $db->query('SELECT id, sinif, yon, portfoy_no, seri_no, banka_ad, vade, tutar, para_birimi, cari_id, durum, yer_cari_id, yer_kasa_id, karne_id, bordro_no FROM ceksenet_kayitlar ORDER BY id')->fetchAll();
// Bordro başına sunucu ortalama vadesi (iptal olmayan satırlar; bordro tarihi baz)
$ort = [];
foreach ($db->query("SELECT DISTINCT bordro_no, tarih FROM ceksenet_hareketler WHERE bordro_no <> '' AND islem IN ('giris','cikis') ORDER BY id")->fetchAll() as $b) {
    $s = [];
    foreach ($kayitlar as $k) if ($k['bordro_no'] === $b['bordro_no'] && $k['durum'] !== 'iptal') $s[] = ['vade' => $k['vade'], 'tutar' => $k['tutar'], 'para_birimi' => $k['para_birimi']];
    $ort[$b['bordro_no']] = ceksenet_ortalama_vade($s, (string)$b['tarih']) ?? [];
}
$secimIds = array_values(array_filter(array_map('intval', explode(',', (string)($argv[2] ?? '')))));
echo json_encode(['secim' => $secimIds ? ceksenet_secim_ozeti($db, $secimIds) : null, 'kayit' => count($kayitlar), 'kayitlar' => $kayitlar, 'hareket' => $one('SELECT COUNT(*) FROM ceksenet_hareketler'), 'gecerli' => $one("SELECT COUNT(*) FROM ceksenet_hareketler WHERE durum = 'gecerli'"),
    'hareketler' => $db->query('SELECT id, kayit_id, islem, tarih, onceki_durum, yeni_durum, cari_id, kasa_id, cari_hareket_id, cari_hareket2_id, kasa_hareket_id, belge_no, bordro_no, durum, iptal_neden FROM ceksenet_hareketler ORDER BY id')->fetchAll(),
    'tanim' => $tanim, 'tanimlar' => $db->query('SELECT id, tur, ad, aktif, kasa_id, ilk_no, yaprak FROM ceksenet_tanimlar ORDER BY id')->fetchAll(), 'kasalar' => $kasalar,
    'kasa_hareketler' => $db->query('SELECT id, kasa_id, tur, yon, tutar, para_birimi, durum, kaynak_modul, kaynak_id FROM kasa_hareketler ORDER BY id')->fetchAll(),
    'cari_hareketler' => $db->query('SELECT id, cari_id, tur, yon, tutar, para_birimi, durum, belge_no, kaynak_modul, kaynak_id FROM cari_hareketler ORDER BY id')->fetchAll(),
    'cariler' => $db->query('SELECT id, kod, unvan FROM cari_kartlar ORDER BY id')->fetchAll(), 'ortalama' => $ort,
    'audit' => $one("SELECT COUNT(*) FROM audit_log WHERE module = 'ceksenet'")], JSON_UNESCAPED_UNICODE);
