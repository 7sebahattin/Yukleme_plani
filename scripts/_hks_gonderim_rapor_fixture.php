<?php
// =========================================================
// scripts/_hks_gonderim_rapor_fixture.php — Gönderim Raporu (v494) testlerinin ortak
// SENTETİK verisi (bellek içi SQLite). Kişisel veri yok. Fonksiyon tanımlar, çıktı basmaz.
// =========================================================

function hks_grapor_test_kur(PDO $db): void
{
    $db->exec('CREATE TABLE hks_gonderilenler (id TEXT PRIMARY KEY, zaman TEXT NOT NULL, firma_id TEXT, firma_ad TEXT,
      plaka TEXT, belge_no TEXT, ulke_ad TEXT, urun_ad TEXT, adet INTEGER, toplam_kg REAL, fiyat REAL, rusum REAL,
      hata_sayisi INTEGER, genel_hata TEXT, bildirim_turu TEXT, veri TEXT)');
    $db->exec('CREATE TABLE hks_firmalar (id TEXT PRIMARY KEY, ad TEXT NOT NULL)');
    $db->exec("CREATE TABLE hks_kisiler (id INTEGER PRIMARY KEY AUTOINCREMENT, tc TEXT UNIQUE, ad TEXT DEFAULT '')");
    foreach ([['f1', 'Firma A'], ['f2', 'Firma B'], ['f3', '<img src=x onerror=alert(1)>']] as $f)
        $db->prepare('INSERT INTO hks_firmalar (id, ad) VALUES (?, ?)')->execute($f);
    $db->prepare('INSERT INTO hks_kisiler (tc, ad) VALUES (?, ?)')->execute(['12345678901', 'Örnek Müstahsil']);

    $ok = fn(float $m) => ['yeniKunyeNo' => (string)random_int(100000, 999999), 'hataKodu' => 0, 'miktar' => $m];
    $hata = fn(float $m) => ['yeniKunyeNo' => '', 'hataKodu' => 21, 'miktar' => $m];
    $kismi = json_encode(['sonuclar' => [$ok(10000), $ok(10000), $hata(10000)]]);
    $tamHata = json_encode(['sonuclar' => [$hata(4000), $hata(4000)]]);
    $ekle = $db->prepare('INSERT INTO hks_gonderilenler (id, zaman, firma_id, firma_ad, plaka, belge_no, ulke_ad, urun_ad, adet,
      toplam_kg, fiyat, rusum, hata_sayisi, genel_hata, bildirim_turu, veri) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ([
        ['g1', '2026-10-05 10:00:00', 'f1', 'Firma A', '33ABC01', '', 'Rusya', 'Nar', 3, 21000, 1.5, 100, 0, null, 'SATIS', '{}'],
        ['g2', '2026-10-06 10:00:00', 'f1', 'Firma A', '33 abc-01', '', 'Rusya', 'Nar', 2, 19000, 1.5, 90, 0, null, 'SATIS', '{}'],
        ['g3', '2026-10-07 10:00:00', 'f1', 'Firma A', '07XYZ99', '', 'Satın Alım ← 12345678901', 'Limon', 1, 5000, 1, 20, 0, null, 'SATIN_ALIM', '{}'],
        ['g4', '2026-10-08 10:00:00', 'f1', 'Firma A', '07XYZ99', '', 'Irak', 'Limon', 0, 8000, 1, 30, 2, 'HKS hata', 'SATIS', $tamHata],
        ['g5', '2026-10-09 10:00:00', 'f1', 'Firma A', '33ABC01', '', 'Irak', 'Nar', 3, 30000, 1, 50, 1, null, 'SATIS', $kismi],
        ['g6', '2026-10-10 10:00:00', 'f2', 'Firma B', '06DEF1', '', 'Rusya', 'Nar', 1, 4000, 1, 10, 0, null, 'SATIS', '{}'],
        ['g7', '2026-09-15 10:00:00', 'f1', 'Firma A', '33ABC01', '', 'Rusya', 'Nar', 1, 9999, 1, 5, 0, null, 'SATIS', '{}'],
        ['g8', '2026-10-11 10:00:00', '', 'Firma A', '', 'B-77', 'Yurt içi → Örnek Firma (Mersin/Merkez)', 'Nar', 1, 1000, 0, 0, 0, null, 'SEVK_ETME', '{}'],
        ['g9', '2026-10-20 23:59:59', 'f1', 'Firma A', '', '', 'Üreticiden Sevk Alım ← Üretici X', 'Nar', 1, 500, 0, 0, 0, null, null, '{}'],
        ['g10', '2026-10-21 00:00:00', 'f1', 'Firma A', '33ABC01', '', 'Rusya', 'Nar', 1, 7777, 1, 5, 0, null, 'SATIS', '{}'],
        ['g11', '2026-10-12 10:00:00', '', 'Firma C', '33ABC01', '', 'Rusya', 'Nar', 1, 3333, 1, 5, 0, null, 'SATIS', '{}'],
    ] as $r) $ekle->execute($r);
}
