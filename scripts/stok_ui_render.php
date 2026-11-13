<?php
// =========================================================
// scripts/stok_ui_render.php — Stok sayfalarını TARAYICI kontrolü için GERÇEK HTML olarak basar
// (geçici dosya SQLite + uydurma örnek veri; canlı DB'ye HİÇ dokunmaz; stub auth = admin). cari_ui_render.php aynası.
//
// Kullanım:  php scripts/stok_ui_render.php <sayfa> [parametre=deger ...] > _test_stok_<sayfa>.html
//   sayfa: kartlar | tanimlar | giris | cikis | transfer | donusum (Parça B fiş sayfaları; yeni=1 form, id=N detay, kopya=N, kantar_id=N)
//   Örnekler:
//     php scripts/stok_ui_render.php kartlar id=1 > _test_stok_kartlar.html    (seçili kart + hareketler)
//     php scripts/stok_ui_render.php kartlar                                    (seçimsiz liste)
//     php scripts/stok_ui_render.php kartlar id=1 parca=detay                   (kısmi yanıt)
//     php scripts/stok_ui_render.php tanimlar sekme=cikis_neden|ice_aktar
//     php scripts/stok_ui_render.php giris yeni=1 > _test_stok_giris.html       (giriş fişi formu)
//     php scripts/stok_ui_render.php transfer id=8                              (örnek transfer fişi; --fisler örnek B fişlerini ekler)
//   Durum anahtarları: --sema (tablolar YOK → şema uyarı kartı) · --bos (tablolar var, veri YOK) · --yonetici-degil (forbidden)
//   Örnek kart id'leri: 1 Domates (2 depo, kasa çevrimi, iptalli fiş) · 2 Biber · 3 Patlıcan (KRİTİK) · 4 Kasa · 5 Euro Palet (KRİTİK) ·
//     6 Limon (PASİF) · 7 Streç Film (hareketsiz → Sil açık) · 8 Sıvı Gübre (lt, kesirli).
//   Çıktıdaki assets/ yolları mutlak file:// olarak yeniden yazılır.
// =========================================================
require_once __DIR__ . '/_stok_test_lib.php';

$args = array_slice($argv, 1);
$bayrak = []; $get = []; $sayfa = '';
foreach ($args as $a) {
    if (str_starts_with($a, '--')) { $bayrak[substr($a, 2)] = true; continue; }
    if (str_contains($a, '=')) { [$k, $v] = explode('=', $a, 2); $get[$k] = $v; continue; }
    if ($sayfa === '') $sayfa = $a;
}
$dosyalar = ['kartlar' => 'stok_kartlari.php', 'tanimlar' => 'stok_tanimlari.php', 'tanimlari' => 'stok_tanimlari.php',
    'giris' => 'stok_giris_fisleri.php', 'cikis' => 'stok_cikis_fisleri.php', 'transfer' => 'transfer_fisleri.php', 'donusum' => 'stok_donusum_fisleri.php'];
if (!isset($dosyalar[$sayfa])) { fwrite(STDERR, "Kullanım: php scripts/stok_ui_render.php <kartlar|tanimlar|giris|cikis|transfer|donusum> [parametre=deger ...] [--sema|--bos|--yonetici-degil]\n"); exit(2); }

$db = db();
if (!isset($bayrak['sema'])) {
    stok_t_sema_kur($db);
    if (!isset($bayrak['bos'])) { $O = stok_t_ornek_veri($db); if (isset($bayrak['fisler'])) stok_t_ornek_fisler($db, $O); }
}
$r = stok_t_istek(['dosya' => $dosyalar[$sayfa], 'get' => $get, 'admin' => !isset($bayrak['yonetici-degil']),
    'server' => isset($get['parca']) ? ['HTTP_X_REQUESTED_WITH' => 'fetch'] : []]);
$html = preg_replace('#(href|src)="(?:\.\.?/)?assets/#', '$1="file://' . $ROOT . '/assets/', (string)$r['govde']);
fwrite(STDERR, $dosyalar[$sayfa] . ' → HTTP ' . $r['kod'] . ', ' . strlen((string)$html) . " bayt" . ($r['hata'] ? ' · HATA: ' . $r['hata'] : '') . "\n");
echo $html;
