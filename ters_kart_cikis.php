<?php
// =========================================================
// ters_kart_cikis.php — v355: Ters Kart Takip'in çıkış noktası artık ORTAK ÇIKIŞ.
//
// Sahip kararı: ters ve normal sistem TEK yerden çıkar. Bu adres (yer imleri,
// eski bağlantılar) Günlük İşçi Giriş/Çıkış ekranını doğrudan Ortak Çıkış
// modunda açar (gunluk_isci_giris_cikis.php?mod=ortak). Ortak Çıkış kartın
// giriş kaynağını (ters / tanımlı / elle) sonuç kartında gösterir.
// POST ?ajax=cikis yalnız ESKİ önbellekli sayfalar için korunur — aynı
// pdks_gunluk_ortak_cikis_kaydet() yolunu çağırır (ikinci çıkış motoru YOK).
// Yetki: attendance.daily_scan.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_ters.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
pdks_ters_require(['tarama']);

if (($_GET['ajax'] ?? '') === 'cikis' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $g = pdks_ters_json_govde();
    pdks_ters_json(pdks_ters_cikis_kaydet(pdks_ters_metin($g['ham_uid'] ?? ''), pdks_ters_metin($g['kaynak'] ?? ''), (int)$auth_user['id'], db()));
}

header('Location: ' . base_url() . 'gunluk_isci_giris_cikis.php?mod=ortak', true, 302);
exit;
