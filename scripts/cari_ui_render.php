<?php
// =========================================================
// scripts/cari_ui_render.php — Cari İşlemler sayfalarını TARAYICI kontrolü için GERÇEK HTML olarak basar
// (geçici dosya SQLite + uydurma örnek veri; canlı DB'ye HİÇ dokunmaz; stub auth = admin).
//
// Kullanım:  php scripts/cari_ui_render.php <sayfa> [parametre=deger ...] > _test_cari_<sayfa>.html
//   sayfa: kartlar | fisler | dekontlar | tanimlar | ekstre     (ekstre = yazdır görünümü; xlsx/csv için bicim=xlsx|csv verilirse ham çıktı)
//   Örnekler:
//     php scripts/cari_ui_render.php kartlar id=1 > _test_cari_kartlar.html           (seçili kart + ekstre; mobilde detay görünür)
//     php scripts/cari_ui_render.php kartlar > _test_cari_kartlar_liste.html          (seçimsiz: sol liste + "Soldan bir cari seçin")
//     php scripts/cari_ui_render.php kartlar id=1 sekme=bilgiler pb=USD iptal=1
//     php scripts/cari_ui_render.php kartlar id=10                                    (PASİF kart)  ·  id=13 (hareketsiz kart → boş durum)
//     php scripts/cari_ui_render.php kartlar id=1 parca=detay | parca=ekstre | parca=kart   (kısmi yanıtlar)
//     php scripts/cari_ui_render.php fisler | dekontlar [bas= bit= tur= pb= cari_q= durum= q= s= yeni=tahsilat]
//     php scripts/cari_ui_render.php tanimlari sekme=odeme_sekli|ice_aktar            (sayfa adı "tanimlar" da olur)
//     php scripts/cari_ui_render.php ekstre id=1                                      (yazdır görünümü)
//   Durum anahtarları (parametre yerine ilk/son argüman): --sema (tablolar YOK → şema uyarı kartı) · --bos (tablolar var, veri YOK) · --yonetici-degil (forbidden)
//   Örnek verideki cari id'leri (13 kart): 1 Örnek Tarım Ltd. (TRY+USD+EUR, iptal + virman) · 2 Ambalaj · 3 Nakliyat (TRY+EUR) · 4 Gıda İhracat (EUR+USD) ·
//     5 Hal Komisyoncusu (bakiye 0) · 6 Üretici Birliği (virman hedefi) · 7 Soğuk Depo · 8 GmbH (EUR) · 9 Çavuş Grubu · 10 PASİF · 11 Kasa Üretim · 12 Lojistik (USD, iptalli) · 13 hareketsiz.
//   Çıktıdaki assets/ yolları mutlak file:// olarak yeniden yazılır (yerel dosyadan açılınca CSS/JS çözülür). Üretilen HTML'deki fetch/POST
//   adresleri göreli kalır (cari_kaydet.php …): tarayıcı testi bunları route ile yakalamalı ya da _cari_istek.php'ye yönlendirmelidir.
// =========================================================
require_once __DIR__ . '/_cari_test_lib.php';

$args = array_slice($argv, 1);
$bayrak = []; $get = []; $sayfa = '';
foreach ($args as $a) {
    if (str_starts_with($a, '--')) { $bayrak[substr($a, 2)] = true; continue; }
    if (str_contains($a, '=')) { [$k, $v] = explode('=', $a, 2); $get[$k] = $v; continue; }
    if ($sayfa === '') $sayfa = $a;
}
$dosyalar = ['kartlar' => 'cari_kartlari.php', 'fisler' => 'cari_fisler.php', 'dekontlar' => 'cari_dekontlar.php', 'tanimlar' => 'cari_tanimlari.php', 'tanimlari' => 'cari_tanimlari.php', 'ekstre' => 'cari_ekstre.php'];
if (!isset($dosyalar[$sayfa])) { fwrite(STDERR, "Kullanım: php scripts/cari_ui_render.php <kartlar|fisler|dekontlar|tanimlar|ekstre> [parametre=deger ...] [--sema|--bos|--yonetici-degil]\n"); exit(2); }

$db = db();
if (!isset($bayrak['sema'])) {
    cari_t_sema_kur($db);
    if (!isset($bayrak['bos'])) cari_t_ornek_veri($db);
}
$r = cari_t_istek(['dosya' => $dosyalar[$sayfa], 'get' => $get, 'admin' => !isset($bayrak['yonetici-degil']),
    'server' => isset($get['parca']) ? ['HTTP_X_REQUESTED_WITH' => 'fetch'] : []]);
$html = $r['govde'];
$html = preg_replace('#(href|src)="(?:\.\.?/)?assets/#', '$1="file://' . $ROOT . '/assets/', (string)$html);
fwrite(STDERR, $dosyalar[$sayfa] . ' → HTTP ' . $r['kod'] . ', ' . strlen($html) . " bayt" . ($r['hata'] ? ' · HATA: ' . $r['hata'] : '') . "\n");
echo $html;
