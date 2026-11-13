<?php
// =========================================================
// scripts/_stok_test_lib.php — Stok (muhasebe) testleri/render için ORTAK harness (include-only; yalnız CLI).
//   _siparis_test_lib.php (stub auth + gerçek helpers) + gerçek config/cari_core.php · stok_core.php · stok_ui.php.
//   DB: DOSYA tabanlı geçici SQLite (alt süreçler — scripts/_stok_istek.php — aynı DB'yi açar); çıkışta silinir.
//   STOK_E2E_DB verilirse (scripts/stok_ui_smoke.js köprüsü) o dosya kullanılır ve SİLİNMEZ.
//   stok_t_istek(['dosya'=>'stok_kartlari.php','get'=>[…],'post'=>[…],'server'=>[…],'admin'=>true]) → ['govde','kod','basliklar','flash','hata'].
//   stok_t_fis(): Parça A örnek verisinin YALNIZ TEST defter yazıcısı (maliyet motoru gerçek stok_maliyet_uygula); Parça B testleri GERÇEK stok_fis_kaydet kullanır.
//   stok_t_cari_kur() / stok_t_kantar_kur(): cari şeması (cari_migrate) + salt okunur kantar_fisleri test tablosu (kantardan doldur).
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/cari_core.php';
require_once $ROOT . '/config/stok_core.php';
require_once $ROOT . '/config/stok_ui.php';

$STOK_T_DB = (string)(getenv('STOK_E2E_DB') ?: '');
if ($STOK_T_DB === '') {
    $STOK_T_DB = sys_get_temp_dir() . '/stok_t_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '.sqlite';
    register_shutdown_function(static function () use ($STOK_T_DB): void { @unlink($STOK_T_DB); });
}
$STOK_T_MEVCUT = is_file($STOK_T_DB) && filesize($STOK_T_DB) > 0;
$AKTIF_DEPO = 'Merkez Depo';                          // örnek verinin depoları (transfer için iki seçenek)
$DEPO_SECENEK = ['Merkez Depo', 'Şube Depo'];
$PDO_TEST = new PDO('sqlite:' . $STOK_T_DB);
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
if (!$STOK_T_MEVCUT) {
    siparis_test_diger_tablolar($PDO_TEST);   // users + audit_log
    $PDO_TEST->exec('CREATE TABLE material_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, name TEXT, is_active INT DEFAULT 1)');
}

/** Alt süreçte tek istek çalıştırır. @return array{govde:string,kod:int,basliklar:list<string>,flash:?array,hata:?string} */
function stok_t_istek(array $spec): array
{
    global $STOK_T_DB, $ROOT;
    $spec += ['get' => [], 'post' => [], 'server' => [], 'admin' => true, 'perms' => ['reports.export', 'records.read'], 'uid' => 1];
    $spec['db'] = $STOK_T_DB;
    $cmd = 'STOK_T_SPEC=' . escapeshellarg(base64_encode((string)json_encode($spec, JSON_UNESCAPED_UNICODE))) . ' php ' . escapeshellarg($ROOT . '/scripts/_stok_istek.php') . ' 2>&1';
    $cikti = (string)shell_exec($cmd);
    $p = strrpos($cikti, "\n@@STOK@@");
    if ($p === false) return ['govde' => $cikti, 'kod' => 0, 'basliklar' => [], 'flash' => null, 'hata' => 'alt süreç çıktısı çözülemedi'];
    $r = json_decode(substr($cikti, $p + 9), true);
    if (!is_array($r)) return ['govde' => $cikti, 'kod' => 0, 'basliklar' => [], 'flash' => null, 'hata' => 'alt süreç JSON bozuk'];
    $r['govde'] = substr($cikti, 0, $p) . (string)$r['govde'];
    return $r;
}

/** Şemayı stok_migrate ile kurar (SQLite çeviricisi + tanım tohumu). */
function stok_t_sema_kur(PDO $db): array
{
    $GLOBALS['_stok_ddl_calistir'] = function (PDO $p, string $ad, string $ddl): void {
        [$c, $ix] = siparis_ddl_sqlite($ddl);
        $p->exec($c);
        foreach ($ix as $x) $p->exec($x);
    };
    return stok_migrate($db);
}

/** Cari şeması (gerçek cari_migrate, SQLite çeviricisi). */
function stok_t_cari_kur(PDO $db): void
{
    $GLOBALS['_cari_ddl_calistir'] = function (PDO $p, string $ad, string $ddl): void {
        [$c, $ix] = siparis_ddl_sqlite($ddl);
        $p->exec($c);
        foreach ($ix as $x) $p->exec($x);
    };
    cari_migrate($db);
}

/** kantar_fisleri test tablosu (config/helpers.php şemasının stok için gereken kolonları; YALNIZ okunur). */
function stok_t_kantar_kur(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS kantar_fisleri (id INTEGER PRIMARY KEY AUTOINCREMENT, fis_no TEXT NOT NULL DEFAULT '', giris_tarih TEXT NOT NULL DEFAULT '', cikis_tarih TEXT NOT NULL DEFAULT '',
        plaka TEXT NOT NULL DEFAULT '', firma_adi TEXT NOT NULL DEFAULT '', malin_cinsi TEXT NOT NULL DEFAULT '', net_kg REAL NOT NULL DEFAULT 0, kasa_dara REAL NOT NULL DEFAULT 0,
        palet_dara REAL NOT NULL DEFAULT 0, kasa_sayisi INT NOT NULL DEFAULT 0, palet_sayisi INT NOT NULL DEFAULT 0, depo TEXT NOT NULL DEFAULT '')");
}

/** Test kantar fişi. @return int id */
function stok_t_kantar(PDO $db, string $no, string $tarih, string $firma, string $urun, string $depo, float $net, int $kasa = 0, float $kasaDara = 0, int $palet = 0, float $paletDara = 0): int
{
    $db->prepare('INSERT INTO kantar_fisleri (fis_no, giris_tarih, firma_adi, malin_cinsi, depo, net_kg, kasa_sayisi, kasa_dara, palet_sayisi, palet_dara) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$no, $tarih, $firma, $urun, $depo, $net, $kasa, $kasaDara, $palet, $paletDara]);
    return (int)$db->lastInsertId();
}

/** Fiş isteği (gerçek stok_fis_kaydet girdisi) kısayolu: satırlar [kart_id, miktar metni, fiyat metni?, yon?]. */
function stok_t_fis_istek(string $tur, string $depo, array $satirlar, array $ek = []): array
{
    $g = ['tur' => $tur, 'tarih' => $ek['tarih'] ?? date('Y-m-d'), 'depo' => $depo] + $ek;
    foreach ($satirlar as $s) {
        $g['kart_id'][] = (string)$s[0]; $g['miktar'][] = (string)$s[1]; $g['birim_fiyat'][] = (string)($s[2] ?? '');
        $g['yon'][] = (string)($s[3] ?? ''); $g['birim'][] = (string)($s[4] ?? ''); $g['kdv_orani'][] = (string)($s[5] ?? ''); $g['lot'][] = ''; $g['kart_ara'][] = '';
    }
    return $g;
}

/**
 * YALNIZ TEST: bir fiş + satırları + defter satırlarını yazar (Parça B stok_fis_kaydet'in yerine; maliyet motoru gerçek).
 * $satirlar: list<[kart_id, depo, miktar_milli (işaretli), deger_kurus|null]>. Giriş için deger = tutar (TRY), çıkış için null (ortalamadan).
 * @return int fiş id
 */
function stok_t_fis(PDO $db, string $tur, string $tarih, string $depo, array $satirlar, string $neden = '', ?string $hedef = null): int
{
    $toplam = 0; foreach ($satirlar as $s) $toplam += abs((int)$s[2]);
    $db->prepare('INSERT INTO stok_fisler (tur, fis_no, tarih, depo, hedef_depo, neden_ad, para_birimi, toplam_miktar, durum, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$tur, '', $tarih, $depo, $hedef, $neden !== '' ? $neden : null, 'TRY', stok_milli_db($toplam), 'gecerli', date('Y-m-d H:i:s'), 1]);
    $fid = (int)$db->lastInsertId();
    $db->prepare('UPDATE stok_fisler SET fis_no = ? WHERE id = ?')->execute([stok_fis_no($tur, $tarih, $fid), $fid]);
    foreach ($satirlar as $i => [$kart, $d, $q, $deger]) {
        $db->prepare('INSERT INTO stok_fis_satirlar (fis_id, sira, kart_id, yon, miktar, tutar) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$fid, $i + 1, $kart, $q > 0 ? 'G' : 'C', stok_milli_db(abs($q)), $deger !== null ? number_format(abs($deger) / 100, 2, '.', '') : null]);
        stok_t_defter($db, $fid, (int)$db->lastInsertId(), (int)$kart, $tarih, (string)$d, (int)$q, $deger, null);
    }
    return $fid;
}

/** YALNIZ TEST: tek defter satırı (maliyet anlık görüntüsüyle). */
function stok_t_defter(PDO $db, int $fid, ?int $satirId, int $kart, string $tarih, string $depo, int $q, ?int $deger, ?int $tersId): int
{
    $d = stok_maliyet_durumu($db, $kart);
    $r = stok_maliyet_uygula($d, $q, $deger);
    $db->prepare('INSERT INTO stok_hareketler (fis_id, satir_id, kart_id, tarih, depo, miktar, deger, q_sonra, v_sonra, ort_sonra, ters_id, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$fid, $satirId, $kart, $tarih, $depo, stok_milli_db($q), number_format($r['deger'] / 100, 2, '.', ''), stok_milli_db($r['d']['q']),
            number_format($r['d']['v'] / 100, 2, '.', ''), number_format($r['d']['ort'] / 100, 6, '.', ''), $tersId, date('Y-m-d H:i:s'), 1]);
    return (int)$db->lastInsertId();
}

/** YALNIZ TEST: fişi iptal eder (durum + ters satırlar). */
function stok_t_iptal(PDO $db, int $fid, string $neden): void
{
    $db->prepare("UPDATE stok_fisler SET durum = 'iptal', iptal_at = ?, iptal_by = ?, iptal_neden = ? WHERE id = ? AND durum = 'gecerli'")->execute([date('Y-m-d H:i:s'), 1, $neden, $fid]);
    $st = $db->prepare('SELECT * FROM stok_hareketler WHERE fis_id = ? AND ters_id IS NULL ORDER BY id DESC');
    $st->execute([$fid]);
    foreach ($st->fetchAll() as $h) stok_t_defter($db, $fid, null, (int)$h['kart_id'], (string)$h['tarih'], (string)$h['depo'], -stok_milli($h['miktar']), -cari_kurus($h['deger']), (int)$h['id']);
}

/**
 * Uydurma örnek veri (kişisel veri YOK): 8 kart (6 = pasif, 7 = hareketsiz), 3 grup, defter hareketleri (1 iptal), içe aktar kaynağı.
 * @return array{kart:array<string,int>,grup:array<string,int>,fis:array<string,int>,cari:array<string,int>,kantar:array<string,int>}
 */
function stok_t_ornek_veri(PDO $db): array
{
    global $UID, $IS_ADMIN;
    $UID = 1; $IS_ADMIN = true;
    foreach (['Taze Ürün', 'Ambalaj'] as $i => $g) stok_tanim_kaydet($db, 'grup', $g, 20 + $i * 10, 1);
    $grup = []; foreach (stok_tanim_listesi($db, 'grup') as $t) $grup[$t['ad']] = (int)$t['id'];
    $kartlar = [
        ['Domates (Salkım)', 'urun', 'Taze Ürün', 'kg', ['cevrim_birim' => 'kasa', 'cevrim_katsayi' => '8', 'kritik_seviye' => '1.000', 'barkod' => '8690000000012']],
        ['Biber (Dolmalık)', 'urun', 'Taze Ürün', 'kg', []],
        ['Patlıcan (Kemer)', 'urun', 'Taze Ürün', 'kg', ['kritik_seviye' => '500']],
        ['Kasa 6\'lı Plastik', 'malzeme', 'Ambalaj', 'adet', ['kdv_orani' => '20']],
        ['Euro Palet', 'malzeme', 'Ambalaj', 'adet', ['kritik_seviye' => '100', 'kdv_orani' => '20']],
        ['Limon (Yerli)', 'urun', 'Taze Ürün', 'kg', []],
        ['Streç Film', 'malzeme', 'Ambalaj', 'adet', ['kdv_orani' => '20']],
        ['Sıvı Gübre', 'diger', 'Genel', 'lt', ['kdv_orani' => '20']],
    ];
    $kart = [];
    foreach ($kartlar as [$ad, $tip, $g, $birim, $ek]) {
        $r = stok_kart_kaydet($db, ['ad' => $ad, 'tip' => $tip, 'grup_id' => $grup[$g], 'ana_birim' => $birim, 'aktif' => '1'] + $ek, 1);
        if (!$r['ok']) throw new RuntimeException('örnek kart: ' . $r['hata']);
        $kart[$ad] = (int)$r['id'];
    }
    $K = static fn(string $a): int => $kart[$a];
    $y = date('Y');
    $f = [];
    $f['g1'] = stok_t_fis($db, 'giris', "$y-11-02", 'Merkez Depo', [[$K('Domates (Salkım)'), 'Merkez Depo', 9800000, 13720000], [$K('Kasa 6\'lı Plastik'), 'Merkez Depo', 3400000, 6120000]], 'Alış');
    $f['g2'] = stok_t_fis($db, 'giris', "$y-11-04", 'Şube Depo', [[$K('Domates (Salkım)'), 'Şube Depo', 7250000, 10875000], [$K('Euro Palet'), 'Şube Depo', 86000, 1548000]], 'Alış');
    $f['g3'] = stok_t_fis($db, 'giris', "$y-11-05", 'Merkez Depo', [[$K('Patlıcan (Kemer)'), 'Merkez Depo', 240000, 360000], [$K('Biber (Dolmalık)'), 'Merkez Depo', 2120000, 4240000]], 'Açılış/Devir');
    $f['c1'] = stok_t_fis($db, 'cikis', "$y-11-06", 'Merkez Depo', [[$K('Domates (Salkım)'), 'Merkez Depo', -350000, null]], 'Fire');
    $f['g4'] = stok_t_fis($db, 'giris', "$y-11-08", 'Merkez Depo', [[$K('Domates (Salkım)'), 'Merkez Depo', 1200000, 1800000]], 'Alış');
    stok_t_iptal($db, $f['g4'], 'Yanlış depo seçildi');
    $f['c2'] = stok_t_fis($db, 'cikis', "$y-11-12", 'Merkez Depo', [[$K('Domates (Salkım)'), 'Merkez Depo', -2400000, null]], 'Satış/Sevk');
    $f['g5'] = stok_t_fis($db, 'giris', "$y-11-10", 'Merkez Depo', [[$K('Sıvı Gübre'), 'Merkez Depo', 12500, 62500]], 'Alış');
    stok_kart_durum($db, $K('Limon (Yerli)'), false, 1);
    // Cari (uydurma firmalar) + kantar (salt okunur kaynak) — Parça B fiş formu
    stok_t_cari_kur($db);
    $cari = [];
    foreach ([['Örnek Tarım Ltd.', 'tedarikci', 'TRY'], ['Deneme Gıda A.Ş.', 'musteri', 'TRY'], ['Örnek İhracat Ltd.', 'musteri', 'USD']] as [$u, $t, $pb]) {
        $r = cari_kart_kaydet($db, ['unvan' => $u, 'tip' => $t, 'para_birimi' => $pb, 'aktif' => '1'], 1);
        if (!$r['ok']) throw new RuntimeException('örnek cari: ' . $r['hata']);
        $cari[$u] = (int)$r['id'];
    }
    stok_t_kantar_kur($db);
    $kantar = [];
    $kantar['k1'] = stok_t_kantar($db, '1201', "$y-11-11 08:40", 'Örnek Tarım Ltd.', 'Domates (Salkım)', 'Merkez Depo', 6480.0, 60, 2.0, 2, 120.0);   // net 6480 − 120 − 240 = 6.120
    $kantar['k2'] = stok_t_kantar($db, '1202', "$y-11-12 10:15", 'Bilinmeyen Üretici', 'Karpuz', 'Merkez Depo', 3000.0);
    $kantar['k3'] = stok_t_kantar($db, '1203', "$y-11-12 11:00", 'Örnek Tarım Ltd.', 'Biber (Dolmalık)', 'Şube Depo', 900.0);   // başka depo: aktif Merkez'de görünmez
    foreach ([['Domates (Salkım)', 'urun'], ['Kayısı', 'urun'], ['Köşebent 120 cm', 'kosebent'], ['Şerit 15 mm', 'serit'], ['Firma Örnek', 'firma'], ['Pasif Ürün', 'urun', 0]] as $m) {
        $db->prepare('INSERT INTO material_definitions (type, name, is_active) VALUES (?, ?, ?)')->execute([$m[1], $m[0], $m[2] ?? 1]);
    }
    return ['kart' => $kart, 'grup' => $grup, 'fis' => $f, 'cari' => $cari, 'kantar' => $kantar];
}

/**
 * Parça B örnek fişleri — GERÇEK stok_fis_kaydet ile (stok_t_ornek_veri'den SONRA): 1 transfer (Domates Merkez → Şube 1.000 kg),
 * 1 dönüşüm (Domates 500 kg → Biber 450 kg; 50 kg fire), 1 cari bağlı alış (Kasa 200 adet × 1,80 ₺, %20 KDV).
 * @return array<string,int> fiş id'leri
 */
function stok_t_ornek_fisler(PDO $db, array $O): array
{
    global $UID, $IS_ADMIN;
    $UID = 1; $IS_ADMIN = true;
    $K = $O['kart']; $y = date('Y');
    $nd = static function (string $tur, string $ad) use ($db): int { foreach (stok_tanim_listesi($db, $tur) as $t) if ($t['ad'] === $ad) return (int)$t['id']; return 0; };
    $f = [];
    $r = stok_fis_kaydet($db, stok_t_fis_istek('transfer', 'Merkez Depo', [[$K['Domates (Salkım)'], '1.000']], ['tarih' => "$y-11-13", 'hedef_depo' => 'Şube Depo', 'aciklama' => 'Şubeye sevk']), 1);
    if (!$r['ok']) throw new RuntimeException('örnek transfer: ' . $r['hata']);
    $f['transfer'] = (int)$r['id'];
    $r = stok_fis_kaydet($db, stok_t_fis_istek('donusum', 'Merkez Depo', [[$K['Domates (Salkım)'], '500', '', 'C'], [$K['Biber (Dolmalık)'], '450', '', 'G']], ['tarih' => "$y-11-13"]), 1);
    if (!$r['ok']) throw new RuntimeException('örnek dönüşüm: ' . $r['hata']);
    $f['donusum'] = (int)$r['id'];
    $r = stok_fis_kaydet($db, stok_t_fis_istek('giris', 'Merkez Depo', [[$K['Kasa 6\'lı Plastik'], '200', '1,80', 'G', '', '20']],
        ['tarih' => "$y-11-13", 'neden_id' => (string)$nd('giris_neden', 'Alış'), 'cari_id' => (string)$O['cari']['Örnek Tarım Ltd.'], 'evrak_no' => 'IRS-1001']), 1);
    if (!$r['ok']) throw new RuntimeException('örnek cari alış: ' . $r['hata']);
    $f['cari_alis'] = (int)$r['id'];
    return $f;
}
