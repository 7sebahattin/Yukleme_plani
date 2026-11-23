<?php
// =========================================================
// scripts/_fatura_test_lib.php — Fatura testleri/render için ORTAK harness (include-only; yalnız CLI).
//   _stok_test_lib.php (stub auth + gerçek helpers + gerçek cari_core/stok_core/stok_ui; dosya tabanlı geçici SQLite) + gerçek config/fatura_core.php
//   (+ varsa config/fatura_ui.php — Dalga B). FATURA_E2E_DB verilirse o dosya kullanılır ve SİLİNMEZ (JS köprüsü için).
//   fatura_t_istek_cagri(['dosya'=>'fatura_kaydet.php','post'=>[…],'server'=>[…],'admin'=>true, 'db'?]) → ['govde','kod','basliklar','flash','hata'] (alt süreç: _fatura_istek.php).
//   fatura_t_sema_kur(): stok + cari + fatura şeması (gerçek *_migrate; SQLite çeviricisi). fatura_t_istek(): belge_kaydet POST biçimi.
//   fatura_t_ornek_veri(): stok_t_ornek_veri + 2 cari + bakiye için GERÇEK stok_fis_kaydet giriş fişleri (uydurma; kişisel veri YOK).
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
if (($e2e = getenv('FATURA_E2E_DB')) !== false && $e2e !== '') putenv('STOK_E2E_DB=' . $e2e);
require_once __DIR__ . '/_stok_test_lib.php';
require_once $ROOT . '/config/fatura_core.php';
if (is_file($ROOT . '/config/fatura_ui.php')) require_once $ROOT . '/config/fatura_ui.php';

/** Alt süreçte tek istek (gerçek exit/http_response_code). spec: dosya, get, post, server, admin, perms, uid, db?, aktif_depo?, depolar?, depo_secenek?, sid? */
function fatura_t_istek_cagri(array $spec): array
{
    global $STOK_T_DB, $ROOT;
    $spec += ['get' => [], 'post' => [], 'server' => [], 'admin' => true, 'perms' => ['reports.export', 'records.read'], 'uid' => 1];
    $spec['db'] ??= $STOK_T_DB;
    $cmd = 'FATURA_T_SPEC=' . escapeshellarg(base64_encode((string)json_encode($spec, JSON_UNESCAPED_UNICODE))) . ' php ' . escapeshellarg($ROOT . '/scripts/_fatura_istek.php') . ' 2>&1';
    $cikti = (string)shell_exec($cmd);
    $p = strrpos($cikti, "\n@@FATURA@@");
    if ($p === false) return ['govde' => $cikti, 'kod' => 0, 'basliklar' => [], 'flash' => null, 'hata' => 'alt süreç çıktısı çözülemedi'];
    $r = json_decode(substr($cikti, $p + 11), true);
    if (!is_array($r)) return ['govde' => $cikti, 'kod' => 0, 'basliklar' => [], 'flash' => null, 'hata' => 'alt süreç JSON bozuk'];
    $r['govde'] = substr($cikti, 0, $p) . (string)$r['govde'];
    return $r;
}

/** SQLite DDL kancası (fatura_migrate testi). */
function fatura_t_kanca(): void
{
    $GLOBALS['_fatura_ddl_calistir'] = function (PDO $p, string $ad, string $ddl): void {
        [$c, $ix] = siparis_ddl_sqlite($ddl);
        $p->exec($c);
        foreach ($ix as $x) $p->exec($x);
    };
}

/** Stok + cari + fatura şemaları (gerçek migrate fonksiyonları). @return list<array> fatura_migrate raporu */
function fatura_t_sema_kur(PDO $db): array
{
    stok_t_sema_kur($db);
    stok_t_cari_kur($db);
    fatura_t_kanca();
    return fatura_migrate($db);
}

/**
 * belge_kaydet POST biçimi. $satirlar: list<array> (anahtarlar: kart_id, kart_ara, urun_adi, miktar, birim, birim_fiyat, iskonto, kdv_orani,
 * gtip, satir_brut_kg, satir_net_kg, satir_kap, lot, satir_aciklama, kaynak_satir_id). $ek: başlık alanları (tarih/depo varsayılanı bugün/Merkez Depo).
 */
function fatura_t_istek(string $tur, array $satirlar, array $ek = []): array
{
    $alanlar = ['kart_id', 'kart_ara', 'urun_adi', 'miktar', 'birim', 'birim_fiyat', 'iskonto', 'kdv_orani', 'gtip', 'satir_brut_kg', 'satir_net_kg', 'satir_kap', 'lot', 'satir_aciklama', 'kaynak_satir_id'];
    $g = ['tur' => $tur, 'tarih' => $ek['tarih'] ?? date('Y-m-d'), 'depo' => $ek['depo'] ?? 'Merkez Depo'] + $ek;
    foreach ($alanlar as $a) $g[$a] = [];
    foreach ($satirlar as $s) foreach ($alanlar as $a) $g[$a][] = (string)($s[$a] ?? '');
    return $g;
}

/** Bir tanımın id'si (tür + ad). */
function fatura_t_tanim(PDO $db, string $tur, string $ad): int
{
    foreach (fatura_tanim_listesi($db, $tur) as $t) if ($t['ad'] === $ad) return (int)$t['id'];
    return 0;
}

/**
 * Uydurma örnek veri (fatura_t_sema_kur'dan SONRA): stok_t_ornek_veri (8 kart, 3 cari, kantar…) + 2 cari (vadeli müşteri, üretici) + GERÇEK stok giriş fişleri
 * (Domates 5.000 kg, Kasa 2.000 adet; Merkez Depo). @return array stok_t_ornek_veri dizisi + ['fatura_cari' => ad → id]
 */
function fatura_t_ornek_veri(PDO $db): array
{
    global $UID, $IS_ADMIN;
    $UID = 1; $IS_ADMIN = true;
    $O = stok_t_ornek_veri($db);   // şema ÖNCE fatura_t_sema_kur ile kurulmuş olmalı
    $ek = [];
    foreach ([['Örnek Market A.Ş.', 'musteri', 'TRY', '30'], ['Örnek Üretici Kooperatifi', 'tedarikci', 'TRY', '']] as [$u, $t, $pb, $vade]) {
        $r = cari_kart_kaydet($db, ['unvan' => $u, 'tip' => $t, 'para_birimi' => $pb, 'vade_gun' => $vade, 'aktif' => '1'], 1);
        if (!$r['ok']) throw new RuntimeException('örnek cari: ' . $r['hata']);
        $ek[$u] = (int)$r['id'];
    }
    $alis = fatura_stok_neden_id($db, 'giris', 'alis');
    $r = stok_fis_kaydet($db, stok_t_fis_istek('giris', 'Merkez Depo', [[$O['kart']['Domates (Salkım)'], '5.000', '10,00'], [$O['kart']['Kasa 6\'lı Plastik'], '2.000', '1,50']],
        ['neden_id' => (string)$alis, 'aciklama' => 'Fatura testleri açılış']), 1);
    if (!$r['ok']) throw new RuntimeException('örnek stok girişi: ' . $r['hata']);
    return $O + ['fatura_cari' => $ek];
}
