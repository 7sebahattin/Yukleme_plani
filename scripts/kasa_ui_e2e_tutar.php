<?php
// =========================================================
// scripts/kasa_ui_e2e_tutar.php — JS ↔ PHP tutar ayrıştırıcı eşitliği için sunucu tarafı yanıt (kasa_ui_smoke.js + ceksenet_ui_smoke.js kullanır).
//   php scripts/kasa_ui_e2e_tutar.php <geçici-db-yolu> '<JSON dizi: girdiler>'  → {girdi: {k: kuruş|null, belirsiz: bool, mesaj: string}}
// kasa_tutar_coz (cari_tutar_coz → hesap_parse_amount sarmalayıcısı) SAF kullanılır; DB'ye yazılmaz.
// =========================================================
$yol = (string)($argv[1] ?? '');
$in = json_decode((string)($argv[2] ?? '[]'), true);
if ($yol === '' || !is_array($in)) { fwrite(STDERR, "Kullanım: php scripts/kasa_ui_e2e_tutar.php <db-yolu> '<json>'\n"); exit(2); }
putenv('KASA_E2E_DB=' . $yol);
require_once __DIR__ . '/_kasa_test_lib.php';
$o = [];
foreach ($in as $g) {
    $r = kasa_tutar_coz((string)$g);
    $o[(string)$g] = is_array($r) ? ['k' => null, 'belirsiz' => true, 'mesaj' => (string)$r['mesaj']] : ['k' => $r, 'belirsiz' => false, 'mesaj' => ''];
}
echo json_encode($o, JSON_UNESCAPED_UNICODE);
