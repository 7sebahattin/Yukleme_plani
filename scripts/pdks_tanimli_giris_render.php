<?php
// =========================================================
// scripts/pdks_tanimli_giris_render.php — v298 TANIMLI GİRİŞ tarayıcı testi
// (pdks_tanimli_giris_smoke.js) için gunluk_isci_giris_cikis.php'yi GERÇEK CSS
// ile tek HTML dosyasına basar. pdks_ortak_cikis_render.php'yi tanım tablosu
// KURULU olarak (PDKS_TANIMLI=1) çalıştırır — ikinci bir render kurgusu yok.
// Canlı DB'ye dokunmaz (bellek içi SQLite).
//
//   php scripts/pdks_tanimli_giris_render.php > _test_tanimli_giris.html
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
putenv('PDKS_TANIMLI=1');
require __DIR__ . '/pdks_ortak_cikis_render.php';
