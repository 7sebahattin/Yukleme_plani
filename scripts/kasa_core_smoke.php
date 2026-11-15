<?php
// =========================================================
// scripts/kasa_core_smoke.php — Kasa çekirdeği (config/kasa_core.php) + tek yazma ucu (kasa_kaydet.php) davranış testleri — Aşama 1 (arka uç)
//   php scripts/kasa_core_smoke.php      → çıkış kodu 0 = hepsi geçti
// Geçici dosya SQLite + stub auth; canlı DB'ye HİÇ dokunmaz. Kapsam: şema/tohum/503 kapısı · kapı · tür/belge no/geri · kart CRUD ·
// hareket (yön, bakiye kuruşu kuruşuna, yetersiz bakiye + eksiye izin, tevsik uyarısı) · cari bağı + iptal zinciri + rollback · virman ·
// kaynaklı türler ($kaynak) + kaynaklı iptal (idempotent) · istek anahtarı · para birimi ayrımı · defter / liste / yürüyen bakiye ·
// depo · tanımlar · uç (gerçek kasa_kaydet.php: CSRF, kapı, 200/422/409/403/503, klasik 303) · audit.
// =========================================================
ini_set('error_log', '/dev/null');   // beklenen iç hatalarda error_log gürültüsü
require_once __DIR__ . '/_kasa_test_lib.php';
$db = db();
$IS_ADMIN = true; $UID = 1;
function say(string $t, string $w = '1=1', array $p = []): int { return siparis_test_say(db(), $t, $w, $p); }
function audit_say(string $eylem): int { return say('audit_log', "module = 'kasa' AND action = ?", [$eylem]); }
function uc(array $post, bool $json = true, array $ek = []): array
{
    return kasa_t_istek_cagri(['dosya' => 'kasa_kaydet.php', 'post' => $post + ['csrf' => 'test-csrf-belirteci'], 'server' => $json ? ['HTTP_X_REQUESTED_WITH' => 'fetch'] : []] + $ek);
}
function js(array $r): array { $j = json_decode(trim($r['govde']), true); return is_array($j) ? $j : ['_cozulemedi' => $r['govde']]; }
function baslik(array $r, string $ad): ?string { foreach ($r['basliklar'] as $b) if (stripos($b, $ad . ':') === 0) return trim(substr($b, strlen($ad) + 1)); return null; }
function ekle(int $kasa, string $tur, string $tutar, array $ek = [], ?array $kaynak = null): array { global $db; return kasa_hareket_ekle($db, kasa_t_hareket($kasa, $tur, $tutar, $ek), 1, $kaynak); }
function kart(string $ad, string $tip = 'nakit', string $pb = 'TRY', array $ek = []): int { global $db; $r = kasa_kart_kaydet($db, ['ad' => $ad, 'tip' => $tip, 'para_birimi' => $pb, 'aktif' => '1'] + $ek, 1); if (!$r['ok']) throw new RuntimeException($r['hata']); return (int)$r['id']; }
function gun(int $fark): string { return date('Y-m-d', strtotime(($fark >= 0 ? '+' : '') . $fark . ' days')); }
$Y = date('Y');

echo "=== 1. Şema / tohum / 503 kapısı ===\n";
ok('kurulum öncesi kasa_hazir() = false', !kasa_hazir($db));
ok('kasa_tablolar: 3 tablo, hepsi CREATE TABLE IF NOT EXISTS, FK/ALTER/ENUM/JSON/ON UPDATE/GENERATED yok', array_keys(kasa_tablolar()) === ['kasa_kartlar', 'kasa_tanimlar', 'kasa_hareketler']
    && !array_filter(kasa_tablolar(), fn($d) => !str_starts_with($d, 'CREATE TABLE IF NOT EXISTS') || preg_match('/FOREIGN KEY|ALTER TABLE|ENUM\(|\bJSON\b|ON UPDATE|GENERATED/i', $d)));
$r = uc(['islem' => 'hareket_ekle']);
ok('hiç şema yok: kasa_kaydet 503 JSON, eksikler = cari, kasa', $r['kod'] === 503 && js($r)['ok'] === false && js($r)['eksikler'] === ['cari', 'kasa'] && str_contains((string)js($r)['hata'], 'kurulmamış'), $r['govde']);
kasa_t_kanca(); cari_migrate($db);
$r = uc(['islem' => 'hareket_ekle']);
ok('cari var, kasa yok: 503, eksikler = [kasa]', $r['kod'] === 503 && js($r)['eksikler'] === ['kasa'], $r['govde']);
$tmp = sys_get_temp_dir() . '/kasa_t_yalniz_' . getmypid() . '.sqlite';
@unlink($tmp);
$tdb = new PDO('sqlite:' . $tmp); $tdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
siparis_test_diger_tablolar($tdb); kasa_migrate($tdb); $tdb = null;
$r = uc(['islem' => 'hareket_ekle'], true, ['db' => $tmp]);
ok('yalnız kasa şeması var (cari YOK): yine 503, eksikler = [cari]', $r['kod'] === 503 && js($r)['eksikler'] === ['cari'], $r['govde']);
$r = kasa_t_istek_cagri(['dosya' => 'kasa_kaydet.php', 'post' => ['islem' => 'hareket_ekle', 'csrf' => 'test-csrf-belirteci'], 'db' => $tmp]);
ok('klasik istekte 503 yerine uyarı kartı (migrate.php#cari bağlantısı; render_header + footer)', str_contains($r['govde'], 'migrate.php#cari') && !str_contains($r['govde'], 'migrate.php#kasa'));
@unlink($tmp);
$rap = kasa_migrate($db);
ok('kasa_migrate: 3 tablo oluşturuldu', count($rap) === 3 && !array_filter($rap, fn($x) => $x['durum'] !== 'olusturuldu'), json_encode($rap, JSON_UNESCAPED_UNICODE));
ok('kasa_hazir() = true; kasa_eksik_semalar boş', kasa_hazir($db) && kasa_eksik_semalar($db) === []);
ok('tohum: 2 gelir + 6 gider kalemi; kasa kartı tohumu YOK', say('kasa_tanimlar') === 8 && say('kasa_tanimlar', "tur = 'gelir'") === 2 && say('kasa_tanimlar', "tur = 'gider'") === 6 && say('kasa_kartlar') === 0 && count(kasa_tanim_tohumu()) === 8);
ok('tohum adları: Diğer Gelir, Faiz Geliri / Genel Gider, Yakıt, Yemek, Nakliye, Kırtasiye, Banka Masrafı', array_column(kasa_tanim_listesi($db, 'gelir'), 'ad') === ['Diğer Gelir', 'Faiz Geliri']
    && array_column(kasa_tanim_listesi($db, 'gider'), 'ad') === ['Genel Gider', 'Yakıt', 'Yemek', 'Nakliye', 'Kırtasiye', 'Banka Masrafı']);
$rap2 = kasa_migrate($db);
ok('ikinci migrate: hepsi "var", tohum çoğalmaz', !array_filter($rap2, fn($x) => $x['durum'] !== 'var') && say('kasa_tanimlar') === 8);
ok('hazır şemada kasa_sayfa_kapisi sessizce döner', (function () use ($db) { ob_start(); kasa_sayfa_kapisi($db); return ob_get_clean() === ''; })());
$ORN = kasa_t_ornek_veri($db);
$K = $ORN['kasa']['Merkez Kasa']; $B = $ORN['kasa']['Örnek Banka TL']; $D = $ORN['kasa']['Döviz Kasası']; $S = $ORN['kasa']['Şube Kasası'];
$C1 = $ORN['cari']['ÖRNEK TİCARET A.Ş.']; $C2 = $ORN['cari']['ÖRNEK TEDARİK LTD.'];
ok('örnek veri: 4 kasa (nakit/banka/USD/şube) + 2 cari, hareket yok', say('kasa_kartlar') === 4 && say('kasa_hareketler') === 0 && say('cari_kartlar') === 2);

echo "\n=== 2. Kapı ===\n";
ok('kasa_can(read|write|delete|admin) admin için true; bilinmeyen false', kasa_can('read') && kasa_can('write') && kasa_can('delete') && kasa_can('admin') && !kasa_can('sil') && !kasa_can(''));
$IS_ADMIN = false;
ok('yönetici değilse hepsi false (izinler olsa bile)', (function () { global $PERMS; $PERMS = ['stok.read', 'stok.write', 'reports.export', 'records.write']; $r = !kasa_can('read') && !kasa_can('write'); $PERMS = []; return $r; })());
ok('kasa_kapi: yönetici değilse forbidden', (function () { try { kasa_kapi('read'); return false; } catch (RuntimeException $e) { return str_starts_with($e->getMessage(), 'forbidden'); } })());
$IS_ADMIN = true;
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
ok('kasa_json_mi: X-Requested-With fetch', kasa_json_mi());
unset($_SERVER['HTTP_X_REQUESTED_WITH']);
ok('kasa_json_mi: düz istek false', !kasa_json_mi());

echo "\n=== 3. Türler / belge no / geri ===\n";
ok('kasa_hareket_turleri: 6 tür, sıra + yön + önek', array_keys(kasa_hareket_turleri()) === ['cari_tahsilat', 'cari_odeme', 'gelir', 'gider', 'acilis', 'virman']
    && array_column(kasa_hareket_turleri(), 'onek') === ['KTH', 'KOD', 'KGL', 'KGD', 'KAC', 'KVR'] && array_column(kasa_hareket_turleri(), 'yon') === ['G', 'C', 'G', 'C', 'G', 'cift']);
ok('cari bağı: cari_tahsilat → tahsilat, cari_odeme → odeme; kalem: gelir/gider; acilis hiçbiri', kasa_hareket_turleri()['cari_tahsilat']['cari'] === 'tahsilat' && kasa_hareket_turleri()['cari_odeme']['cari'] === 'odeme'
    && kasa_hareket_turleri()['gelir']['kalem'] === 'gelir' && kasa_hareket_turleri()['gider']['kalem'] === 'gider' && kasa_hareket_turleri()['acilis']['cari'] === null && kasa_hareket_turleri()['acilis']['kalem'] === null);
ok('dahili türler: kaynak_giris (G, KKG) + kaynak_cikis (C, KKC); kasa_hareket_turleri() içinde DEĞİL', array_keys(kasa_dahili_turler()) === ['kaynak_giris', 'kaynak_cikis'] && array_column(kasa_dahili_turler(), 'onek') === ['KKG', 'KKC']
    && !isset(kasa_hareket_turleri()['kaynak_giris']));
ok('kasa_tur_bilgi: dahili tür yalnız $dahili=true ile çözülür', kasa_tur_bilgi('kaynak_giris') === null && kasa_tur_bilgi('kaynak_giris', true)['yon'] === 'G' && kasa_tur_bilgi('gelir')['etiket'] === 'Gelir Fişi' && kasa_tur_bilgi('x', true) === null);
ok('kasa_belge_no → KTH-2026-000123, KKC, bilinmeyen tür KHR', kasa_belge_no('cari_tahsilat', '2026-11-13', 123) === 'KTH-2026-000123' && kasa_belge_no('kaynak_cikis', '2027-01-01', 5) === 'KKC-2027-000005' && kasa_belge_no('x', '2026-01-01', 1) === 'KHR-2026-000001');
foreach (['kasa_fisleri.php' => 'kasa_fisleri.php', 'kasa_fisleri.php?kasa=3&id=4' => 'kasa_fisleri.php?kasa=3&id=4', 'kasa_tanimlari.php?sekme=gelir' => 'kasa_tanimlari.php?sekme=gelir',
    'cari_kartlari.php' => 'kasa_fisleri.php', 'https://ornek.test/kasa_fisleri.php' => 'kasa_fisleri.php', "kasa_fisleri.php\r\nX: 1" => 'kasa_fisleri.php', 'kasa_cikti.php' => 'kasa_fisleri.php',
    '/kasa_fisleri.php' => 'kasa_fisleri.php', '../kasa_fisleri.php' => 'kasa_fisleri.php', 'kasa_x.php' => 'kasa_fisleri.php'] as $g => $bek) {
    ok('kasa_geri_guvenli(' . json_encode($g) . ") = $bek", kasa_geri_guvenli($g) === $bek);
}
ok('kasa_geri_guvenli(dizi) = varsayılan', kasa_geri_guvenli(['x']) === 'kasa_fisleri.php');
ok('kasa_para_db: 123456 → "1234.56", 5 → "0.05"', kasa_para_db(123456) === '1234.56' && kasa_para_db(5) === '0.05');
ok('kasa_tarih_coz: Y-m-d, d.m.Y; geçersiz/uzak gelecek null', kasa_tarih_coz('2026-03-04') === '2026-03-04' && kasa_tarih_coz('04.03.2026') === '2026-03-04' && kasa_tarih_coz('2026-02-30') === null && kasa_tarih_coz(gun(400)) === null && kasa_tarih_coz([]) === null);
ok('KASA_TEVSIK_SINIRI_KURUS = 3.000.000 (30.000 TL)', KASA_TEVSIK_SINIRI_KURUS === 3000000);

echo "\n=== 4. Kart CRUD ===\n";
$a0 = audit_say('create');
ok('kasa_kod_oner: dört örnek kart sonrası KS-005', kasa_kod_oner($db) === 'KS-005');
$r = kasa_kart_kaydet($db, ['ad' => 'Deneme Kasa', 'tip' => 'nakit', 'para_birimi' => 'TRY'], 1);
$kD = $r['ok'] ? kasa_kart_oku($db, (int)$r['id']) : null;
ok('kart_kaydet: kod boş → KS-005, aktif=1, eksi_izin=0, depo boş', $r['ok'] && $kD['kod'] === 'KS-005' && (int)$kD['aktif'] === 1 && (int)$kD['eksi_izin'] === 0 && $kD['depo'] === '', json_encode($r, JSON_UNESCAPED_UNICODE));
ok('audit create +1 (kayit=kart)', audit_say('create') === $a0 + 1);
$idD = (int)$r['id'];
$r = kasa_kart_kaydet($db, ['ad' => 'Banka Hesabı X', 'kod' => 'bnk-x', 'tip' => 'banka', 'para_birimi' => 'EUR', 'banka_adi' => 'Örnek Banka', 'sube' => 'Merkez', 'iban' => 'tr33 0006 1005 1978 6457 8413 26', 'eksi_izin' => '1', 'aciklama' => 'Deneme'], 1);
$kb = $r['ok'] ? kasa_kart_oku($db, (int)$r['id']) : null;
ok('banka kartı: kod büyük harf, IBAN boşluksuz büyük, banka alanları tutulur, eksi_izin=1', $kb && $kb['kod'] === 'BNK-X' && $kb['iban'] === 'TR330006100519786457841326' && $kb['banka_adi'] === 'Örnek Banka' && (int)$kb['eksi_izin'] === 1 && $kb['para_birimi'] === 'EUR', json_encode($r, JSON_UNESCAPED_UNICODE));
$idBX = (int)$r['id'];
$r = kasa_kart_kaydet($db, ['ad' => 'Nakit Y', 'tip' => 'nakit', 'banka_adi' => 'Olmamalı', 'sube' => 'Olmamalı', 'iban' => 'TR330006100519786457841326'], 1);
$ky = $r['ok'] ? kasa_kart_oku($db, (int)$r['id']) : null;
ok('nakit kartta banka_adi / şube / iban NULL tutulur', $ky && $ky['banka_adi'] === null && $ky['sube'] === null && $ky['iban'] === null);
$idY = (int)$r['id'];
$hatali = [
    'ad kısa' => [['ad' => 'X'], 'ad'], 'ad çok uzun' => [['ad' => str_repeat('a', 151)], 'ad'], 'tip geçersiz' => [['ad' => 'Xx Kasa', 'tip' => 'kredi'], 'tip'],
    'pb geçersiz' => [['ad' => 'Xx Kasa', 'para_birimi' => 'GBP'], 'para_birimi'], 'kod geçersiz karakter' => [['ad' => 'Xx Kasa', 'kod' => 'A B'], 'kod'],
    'kod çakışması' => [['ad' => 'Xx Kasa', 'kod' => 'ks-001'], 'kod'], 'IBAN bozuk' => [['ad' => 'Xx Kasa', 'tip' => 'banka', 'iban' => 'TR12'], 'iban'],
    'depo geçersiz' => [['ad' => 'Xx Kasa', 'depo' => 'Olmayan Depo'], 'depo'], 'banka adı çok uzun' => [['ad' => 'Xx Kasa', 'tip' => 'banka', 'banka_adi' => str_repeat('b', 101)], 'banka_adi'],
];
foreach ($hatali as $ad => [$g, $alan]) { $r = kasa_kart_kaydet($db, $g, 1); ok("kart doğrulama: $ad → alanlar[$alan]", !$r['ok'] && isset($r['alanlar'][$alan]), json_encode($r, JSON_UNESCAPED_UNICODE)); }
$r = kasa_kart_kaydet($db, ['ad' => 'Xx Kasa', 'kod' => 'ks-001'], 1);
ok('kod çakışması: kod=kullanimda (uç 409)', ($r['kod'] ?? '') === 'kullanimda');
ok('hatalı kayıtlar kart sayısını değiştirmedi (7)', say('kasa_kartlar') === 7);
$r = kasa_kart_kaydet($db, ['ad' => 'Deneme Kasa 2', 'kod' => 'KS-005', 'tip' => 'nakit', 'para_birimi' => 'TRY'], 1, $idD);
ok('kendi koduyla güncelleme çakışma sayılmaz + ad değişti', $r['ok'] && kasa_kart_oku($db, $idD)['ad'] === 'Deneme Kasa 2');
$u0 = audit_say('update');
kasa_kart_kaydet($db, ['ad' => 'Deneme Kasa 2', 'kod' => 'KS-005', 'tip' => 'nakit', 'para_birimi' => 'TRY'], 1, $idD);
ok('değişiklik yoksa audit update yazılmaz', audit_say('update') === $u0);
$r = kasa_kart_kaydet($db, ['ad' => 'Deneme Kasa 2', 'kod' => 'KS-005', 'tip' => 'nakit', 'para_birimi' => 'USD'], 1, $idD);
ok('hareket yokken para birimi değişir (USD)', $r['ok'] && kasa_kart_oku($db, $idD)['para_birimi'] === 'USD');
ok('kart bulunamadı (güncelle)', !kasa_kart_kaydet($db, ['ad' => 'Abc'], 1, 99999)['ok']);
$r = kasa_kart_kaydet($db, ['ad' => 'Şube Kasası', 'tip' => 'nakit', 'depo' => 'şube depo'], 1, $S);
ok('depo harf-duyarsız eşleşir ve listedeki yazıma normalize olur ("şube depo" → "Şube Depo")', $r['ok'] && kasa_kart_oku($db, $S)['depo'] === 'Şube Depo', json_encode($r, JSON_UNESCAPED_UNICODE));

$r = kasa_kart_durum($db, $idY, false, 1);
ok('durum: pasife alma (bakiye 0 → uyarı yok)', $r['ok'] && $r['uyari'] === null && (int)kasa_kart_oku($db, $idY)['aktif'] === 0);
ok('pasif kasaya hareket girilemez', !ekle($idY, 'acilis', '10,00')['ok'] && str_contains((string)ekle($idY, 'acilis', '10,00')['hata'], 'Pasif'));
kasa_kart_durum($db, $idY, true, 1);
ok('durum: aktife alma', (int)kasa_kart_oku($db, $idY)['aktif'] === 1);
ok('durum: kart bulunamadı', !kasa_kart_durum($db, 99999, true, 1)['ok']);

echo "\n=== 5. Hareket: yönler, bakiye (kuruşu kuruşuna), yetersiz bakiye, tevsik ===\n";
$kY = kart('Hareket Kasası'); $ac = audit_say('create');
$r = ekle($kY, 'acilis', '1.000,00', ['tarih' => gun(-40)]);
ok('açılış: ok, belge KAC-yıl-id, tekrar=false, bakiye 1.000,00', $r['ok'] && $r['tekrar'] === false && preg_match('/^KAC-\d{4}-\d{6}$/', $r['belge_no']) === 1 && kasa_bakiye($db, $kY) === 100000, json_encode($r, JSON_UNESCAPED_UNICODE));
$satir = kasa_hareket_oku($db, (int)$r['id']);
ok('satır: yön G, tutar 1000.00, pb TRY (kasadan), durum gecerli, depo = aktif depo, cari/kalem NULL, belge_no id tabanlı', $satir['yon'] === 'G' && (float)$satir['tutar'] === 1000.0 && $satir['para_birimi'] === 'TRY'
    && $satir['durum'] === 'gecerli' && $satir['depo'] === 'Merkez Depo' && $satir['cari_id'] === null && $satir['kalem_id'] === null && $satir['belge_no'] === kasa_belge_no('acilis', gun(-40), (int)$r['id']));
ok('audit: hareket create yazıldı (kayit=hareket)', audit_say('create') === $ac + 1);
$gelirK = (int)kasa_tanim_listesi($db, 'gelir')[0]['id']; $giderK = (int)kasa_tanim_listesi($db, 'gider')[1]['id'];   // Diğer Gelir / Yakıt
$r = ekle($kY, 'gelir', '250,50', ['kalem_id' => (string)$gelirK]);
ok('gelir: G, kalem anlık görüntüsü "Diğer Gelir", bakiye 1.250,50', $r['ok'] && kasa_hareket_oku($db, (int)$r['id'])['kalem_ad'] === 'Diğer Gelir' && str_starts_with($r['belge_no'], 'KGL-') && kasa_bakiye($db, $kY) === 125050);
$r = ekle($kY, 'gider', '100,25', ['kalem_id' => (string)$giderK]);
ok('gider: C, kalem "Yakıt", bakiye 1.150,25', $r['ok'] && kasa_hareket_oku($db, (int)$r['id'])['yon'] === 'C' && kasa_hareket_oku($db, (int)$r['id'])['kalem_ad'] === 'Yakıt' && str_starts_with($r['belge_no'], 'KGD-') && kasa_bakiye($db, $kY) === 115025);
$n0 = say('kasa_hareketler');
$hata = [
    'gider kalemi yok' => [['tur' => 'gider'], 'kalem_id'], 'gelir fişine gider kalemi' => [['tur' => 'gelir', 'kalem_id' => (string)$giderK], 'kalem_id'],
    'cari tahsilat cari yok' => [['tur' => 'cari_tahsilat'], 'cari_id'], 'cari ödeme olmayan cari' => [['tur' => 'cari_odeme', 'cari_id' => '99999'], 'cari_id'],
    'tutar 0' => [['tutar' => '0'], 'tutar'], 'tutar eksi' => [['tutar' => '-5'], 'tutar'], 'tutar metin' => [['tutar' => 'abc'], 'tutar'], 'tutar 3+ ondalık' => [['tutar' => '1,2345'], 'tutar'],
    'tutar boş' => [['tutar' => ''], 'tutar'], 'TL için kur' => [['kur' => '1,5'], 'kur'], 'tarih bozuk' => [['tarih' => '2026-13-40'], 'tarih'], 'tarih uzak gelecek' => [['tarih' => gun(400)], 'tarih'],
    'açıklama 501' => [['aciklama' => str_repeat('a', 501)], 'aciklama'], 'evrak 61' => [['evrak_no' => str_repeat('a', 61)], 'evrak_no'], 'istek anahtarı bozuk' => [['istek_anahtari' => 'a b!'], 'istek_anahtari'],
];
foreach ($hata as $ad => [$ek, $alan]) { $g = kasa_t_hareket($kY, $ek['tur'] ?? 'acilis', '10,00', $ek); $r = kasa_hareket_ekle($db, $g, 1); ok("doğrulama: $ad → alanlar[$alan], satır yazılmadı", !$r['ok'] && isset($r['alanlar'][$alan]) && say('kasa_hareketler') === $n0, json_encode($r, JSON_UNESCAPED_UNICODE)); }
foreach (['virman' => 'virman', 'bilinmeyen' => 'xyz', 'boş' => '', 'dahili giriş (kaynaksız)' => 'kaynak_giris', 'dahili çıkış (kaynaksız)' => 'kaynak_cikis'] as $ad => $t) {
    $r = ekle($kY, $t, '10,00'); ok("tür reddi: $ad", !$r['ok'] && isset($r['alanlar']['tur']) && say('kasa_hareketler') === $n0);
}
ok('kasa seçilmedi / bulunamadı', !kasa_hareket_ekle($db, ['tur' => 'acilis', 'tutar' => '1', 'tarih' => gun(0)], 1)['ok'] && isset(ekle(99999, 'acilis', '1,00')['alanlar']['kasa_id']));
$r = ekle($kY, 'gelir', '5,00', ['kalem_id' => (string)$gelirK, 'cari_id' => (string)$C1]);
ok('gelir fişinde gönderilen cari_id yok sayılır (cari_id NULL, cari dekontu YOK)', $r['ok'] && kasa_hareket_oku($db, (int)$r['id'])['cari_id'] === null && say('cari_hareketler') === 0);
kasa_hareket_iptal($db, (int)$r['id'], 'Deneme iptali', 1);
ok('bakiye toplamı: 115.025 (5,00 gelir sonradan iptal)', kasa_bakiye($db, $kY) === 115025);
// yetersiz bakiye
$n1 = say('kasa_hareketler');
$r = ekle($kY, 'gider', '1.150,26', ['kalem_id' => (string)$giderK, 'istek_anahtari' => 'yet-1']);
ok('yetersiz bakiye: ok=false, kod yetersiz_bakiye, bakiye 115025, eksik 1, satır YAZILMADI', !$r['ok'] && $r['kod'] === 'yetersiz_bakiye' && $r['bakiye'] === 115025 && $r['eksik'] === 1 && say('kasa_hareketler') === $n1, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = ekle($kY, 'gider', '1.150,26', ['kalem_id' => (string)$giderK, 'istek_anahtari' => 'yet-1', 'eksiye_izin' => '1']);
ok('eksiye_izin=1 + AYNI istek anahtarı: kayıt + uyari "eksiye düştü" (anahtar ilk redde harcanmamıştı)', $r['ok'] && $r['tekrar'] === false && str_contains((string)$r['uyari'], 'eksiye düştü') && kasa_bakiye($db, $kY) === -1, json_encode($r, JSON_UNESCAPED_UNICODE));
ekle($kY, 'acilis', '0,01');
ok('bakiye tam 0 (kuruş)', kasa_bakiye($db, $kY) === 0);
$r = ekle($kY, 'gider', '0,00');
ok('sınır: 0,00 tutar reddi', !$r['ok']);
$r = ekle($kY, 'gider', '0,01', ['kalem_id' => (string)$giderK]);
ok('bakiye 0 iken 0,01 çıkış → yetersiz_bakiye (bakiye 0, eksik 1)', !$r['ok'] && $r['kod'] === 'yetersiz_bakiye' && $r['bakiye'] === 0 && $r['eksik'] === 1);
$r = ekle($kY, 'acilis', '1,00'); $r2 = ekle($kY, 'gider', '1,00', ['kalem_id' => (string)$giderK]);
ok('bakiye tutara EŞİT çıkış: izinli, uyarı yok, bakiye 0', $r2['ok'] && $r2['uyari'] === null && kasa_bakiye($db, $kY) === 0);
// eksi_izin kasa
$r = ekle($B, 'gider', '50,00', ['kalem_id' => (string)$giderK]);
ok('banka kartı eksi_izin=0 → yetersiz; kart eksi_izin=1 yapılınca uyarısız eksiye iner', !$r['ok'] && $r['kod'] === 'yetersiz_bakiye'
    && kasa_kart_kaydet($db, ['ad' => 'Örnek Banka TL', 'tip' => 'banka', 'para_birimi' => 'TRY', 'banka_adi' => 'Örnek Banka', 'sube' => 'Merkez Şube', 'iban' => 'TR330006100519786457841326', 'eksi_izin' => '1'], 1, $B)['ok']);
$r = ekle($B, 'gider', '50,00', ['kalem_id' => (string)$giderK]);
ok('eksi_izin=1 kasa: kayıt, uyarı YOK, bakiye -50,00', $r['ok'] && $r['uyari'] === null && kasa_bakiye($db, $B) === -5000, json_encode($r, JSON_UNESCAPED_UNICODE));
ekle($B, 'acilis', '50,00');
// tevsik
$kT = kart('Tevsik Kasası'); $kTB = kart('Tevsik Banka', 'banka'); $kTU = kart('Tevsik USD', 'nakit', 'USD');
ekle($kT, 'acilis', '500.000,00'); ekle($kTB, 'acilis', '500.000,00'); ekle($kTU, 'acilis', '500.000,00');
$tv = 'banka aracılığıyla yapılmalıdır (VUK GT 575)';
$r = ekle($kT, 'cari_tahsilat', '30.000,00', ['cari_id' => (string)$C1]);
ok('tevsik: nakit TRY cari tahsilat 30.000,00 → kayıt YAPILIR + uyarı (VUK GT 575)', $r['ok'] && str_contains((string)$r['uyari'], '30.000 TL ve üzeri tahsilat/ödemeler banka aracılığıyla yapılmalıdır (VUK GT 575).'), json_encode($r, JSON_UNESCAPED_UNICODE));
$r = ekle($kT, 'cari_tahsilat', '29.999,99', ['cari_id' => (string)$C1]);
ok('tevsik: 29.999,99 → uyarı YOK', $r['ok'] && $r['uyari'] === null);
$r = ekle($kT, 'cari_odeme', '45.000,00', ['cari_id' => (string)$C2]);
ok('tevsik: nakit cari ödeme 45.000,00 → uyarı', $r['ok'] && str_contains((string)$r['uyari'], $tv));
ok('tevsik: BANKA tipi 30.000,00 cari tahsilat → uyarı YOK', ekle($kTB, 'cari_tahsilat', '30.000,00', ['cari_id' => (string)$C1])['uyari'] === null);
ok('tevsik: USD nakit 30.000,00 → uyarı YOK', ekle($kTU, 'cari_tahsilat', '30.000,00', ['cari_id' => (string)$C1])['uyari'] === null);
ok('tevsik: nakit TRY gelir fişi 50.000,00 (cari değil) → uyarı YOK', ekle($kT, 'gelir', '50.000,00', ['kalem_id' => (string)$gelirK])['uyari'] === null);

echo "\n=== 6. Cari bağı + iptal zinciri + rollback ===\n";
$kC = kart('Cari Kasası');
$c0 = say('cari_hareketler');
$cb0 = cari_bakiye($db, $C1)['TRY']['bakiye'] ?? 0;
$r = ekle($kC, 'cari_tahsilat', '500,00', ['cari_id' => (string)$C1, 'evrak_no' => 'MKB-1', 'aciklama' => 'Peşin tahsilat']);
$tahId = (int)$r['id']; $satir = kasa_hareket_oku($db, $tahId);
$cs = $satir['cari_hareket_id'] !== null ? cari_hareket_oku($db, (int)$satir['cari_hareket_id']) : null;
ok('cari_tahsilat: kasa G + cari "tahsilat" Alacak satırı (kaynak kasa:id, TRY, aynı tutar, belge TAH-), cari_belge_no döner', $r['ok'] && $cs !== null && $cs['tur'] === 'tahsilat' && $cs['yon'] === 'A' && (float)$cs['tutar'] === 500.0 && $cs['para_birimi'] === 'TRY'
    && $cs['kaynak_modul'] === 'kasa' && (int)$cs['kaynak_id'] === $tahId && str_starts_with($cs['belge_no'], 'TAH-') && $r['cari_belge_no'] === $cs['belge_no'] && $cs['evrak_no'] === 'MKB-1' && $cs['odeme_sekli'] === null && (int)$cs['cari_id'] === $C1, json_encode([$r, $cs], JSON_UNESCAPED_UNICODE));
ok('cari açıklaması otomatik: etiket + belge no + kasa adı + kullanıcı notu', str_contains($cs['aciklama'], 'Cari Tahsilat') && str_contains($cs['aciklama'], $r['belge_no']) && str_contains($cs['aciklama'], 'Cari Kasası') && str_contains($cs['aciklama'], 'Peşin tahsilat'));
ok('cari bakiyesi: tahsilat C1 bakiyesini tam 500,00 azaltır (Alacak yönü)', cari_bakiye($db, $C1)['TRY']['bakiye'] === $cb0 - 50000);
$odeId = (int)ekle($kC, 'cari_odeme', '200,00', ['cari_id' => (string)$C2])['id'];
$co = cari_hareket_oku($db, (int)kasa_hareket_oku($db, $odeId)['cari_hareket_id']);
ok('cari_odeme: kasa C + cari "odeme" Borç satırı, kasa bakiyesi 300,00', $co['tur'] === 'odeme' && $co['yon'] === 'B' && kasa_bakiye($db, $kC) === 30000 && $co['kaynak_modul'] === 'kasa');
ok('cari ekranından kaynaklı satır iptali 409 kaynakli (kasa belgesinden iptal edilir)', (cari_hareket_iptal($db, (int)$cs['id'], 'Deneme', 1)['kod'] ?? '') === 'kaynakli' && cari_hareket_oku($db, (int)$cs['id'])['durum'] === 'gecerli');
ok('pasif cari: alanlar[cari_id], kasa satırı yazılmadı', (function () use ($db, $kC, $C2) { cari_kart_durum($db, $C2, false, 1); $n = say('kasa_hareketler'); $r = ekle($kC, 'cari_odeme', '1,00', ['cari_id' => (string)$C2]); cari_kart_durum($db, $C2, true, 1); return !$r['ok'] && isset($r['alanlar']['cari_id']) && say('kasa_hareketler') === $n; })());
// rollback: cari yazımı iç hatayla başarısız olursa kasa satırı da geri alınır
$db->exec("CREATE TRIGGER kasa_t_tetik BEFORE INSERT ON cari_hareketler WHEN NEW.tutar = 777.77 BEGIN SELECT RAISE(ABORT, 'test'); END");
$n = say('kasa_hareketler'); $cn = say('cari_hareketler');
$r = ekle($kC, 'cari_tahsilat', '777,77', ['cari_id' => (string)$C1, 'istek_anahtari' => 'rb-1']);
ok('rollback: cari yazımı patlarsa kasa satırı DA yazılmaz, bakiye aynı', !$r['ok'] && str_contains((string)$r['hata'], 'Cari hareketi yazılamadı') && say('kasa_hareketler') === $n && say('cari_hareketler') === $cn && kasa_bakiye($db, $kC) === 30000, json_encode($r, JSON_UNESCAPED_UNICODE));
$db->exec('DROP TRIGGER kasa_t_tetik');
$r = ekle($kC, 'cari_tahsilat', '777,77', ['cari_id' => (string)$C1, 'istek_anahtari' => 'rb-1']);
ok('rollback sonrası aynı istek anahtarı yeniden kullanılabilir (ilk deneme anahtarı harcamadı)', $r['ok'] && $r['tekrar'] === false);
ok('iptal doğrulama: neden kısa / 501 / bulunamadı', !kasa_hareket_iptal($db, $tahId, 'ab', 1)['ok'] && isset(kasa_hareket_iptal($db, $tahId, 'ab', 1)['alanlar']['neden']) && !kasa_hareket_iptal($db, $tahId, str_repeat('x', 501), 1)['ok'] && !kasa_hareket_iptal($db, 99999, 'Yok böyle', 1)['ok']);
$i0 = audit_say('cancel');
$r = kasa_hareket_iptal($db, $tahId, 'Yanlış cari seçildi', 1);
ok('iptal: kasa satırı + bağlı cari satırı İPTAL (neden/kim/ne zaman), iptal_idler, audit cancel', $r['ok'] && kasa_hareket_oku($db, $tahId)['durum'] === 'iptal' && kasa_hareket_oku($db, $tahId)['iptal_neden'] === 'Yanlış cari seçildi'
    && (int)kasa_hareket_oku($db, $tahId)['iptal_by'] === 1 && kasa_hareket_oku($db, $tahId)['iptal_at'] !== null && cari_hareket_oku($db, (int)$cs['id'])['durum'] === 'iptal' && count($r['cari_iptal']) === 1 && audit_say('cancel') === $i0 + 1, json_encode($r, JSON_UNESCAPED_UNICODE));
ok('iptal sonrası kasa bakiyesi: 500,00 tahsilat düştü → 777,77 tahsilat − 200,00 ödeme = 577,77', kasa_bakiye($db, $kC) === 77777 - 20000);
$r = kasa_hareket_iptal($db, $tahId, 'Tekrar deneme', 1);
ok('zaten iptal → kod zaten_iptal', !$r['ok'] && $r['kod'] === 'zaten_iptal');
$kE = kart('Eksilme Kasası'); $e1 = ekle($kE, 'acilis', '100,00'); ekle($kE, 'gider', '100,00', ['kalem_id' => (string)$giderK]);
$r = kasa_hareket_iptal($db, (int)$e1['id'], 'Açılış hatalıydı', 1);
ok('giriş iptali kasayı eksiye düşürürse YİNE yapılır + uyari (eksi_izin=0)', $r['ok'] && str_contains((string)$r['uyari'], 'eksiye düştü') && kasa_bakiye($db, $kE) === -10000 && kasa_hareket_oku($db, (int)$e1['id'])['durum'] === 'iptal', json_encode($r, JSON_UNESCAPED_UNICODE));
ok('cari iç çift: iptal edilen kasa satırı kayıt olarak KALIR (INSERT-only; DELETE yok)', kasa_hareket_oku($db, $tahId) !== null);

echo "\n=== 7. Virman ===\n";
$vA = kart('Virman A'); $vB = kart('Virman B'); ekle($vA, 'acilis', '1.000,00');
$r = kasa_virman_ekle($db, ['kaynak_kasa_id' => (string)$vA, 'hedef_kasa_id' => (string)$vB, 'tarih' => gun(0), 'tutar' => '400,00', 'aciklama' => 'Kasalar arası'], 1);
$l1 = kasa_hareket_oku($db, (int)$r['id']); $l2 = kasa_hareket_oku($db, (int)$r['id2']);
ok('virman: iki bacak (kaynak C, hedef G), ortak KVR- belge no, bagli_id çapraz, bakiyeler 600 / 400', $r['ok'] && $l1['yon'] === 'C' && $l2['yon'] === 'G' && (int)$l1['kasa_id'] === $vA && (int)$l2['kasa_id'] === $vB && $l1['belge_no'] === $l2['belge_no']
    && str_starts_with($l1['belge_no'], 'KVR-') && (int)$l1['bagli_id'] === (int)$l2['id'] && (int)$l2['bagli_id'] === (int)$l1['id'] && $l1['tur'] === 'virman' && $l2['tur'] === 'virman' && kasa_bakiye($db, $vA) === 60000 && kasa_bakiye($db, $vB) === 40000, json_encode([$r, $l1, $l2], JSON_UNESCAPED_UNICODE));
ok('virman: cari defterine YAZMAZ', say('cari_hareketler', "kaynak_modul = 'kasa' AND kaynak_id IN (?, ?)", [$l1['id'], $l2['id']]) === 0);
$vid = (int)$r['id'];
$hataV = [
    'aynı kasa' => [['kaynak_kasa_id' => (string)$vA, 'hedef_kasa_id' => (string)$vA], 'hedef_kasa_id'], 'kasa seçilmedi' => [['kaynak_kasa_id' => '', 'hedef_kasa_id' => ''], 'kaynak_kasa_id'],
    'farklı para birimi (USD)' => [['kaynak_kasa_id' => (string)$vA, 'hedef_kasa_id' => (string)$D], 'hedef_kasa_id'], 'tutar 0' => [['tutar' => '0'], 'tutar'], 'tarih bozuk' => [['tarih' => 'x'], 'tarih'],
];
$nV = say('kasa_hareketler');
foreach ($hataV as $ad => [$ek, $alan]) { $r = kasa_virman_ekle($db, $ek + ['kaynak_kasa_id' => (string)$vA, 'hedef_kasa_id' => (string)$vB, 'tarih' => gun(0), 'tutar' => '10,00'], 1); ok("virman doğrulama: $ad → alanlar[$alan]", !$r['ok'] && isset($r['alanlar'][$alan]) && say('kasa_hareketler') === $nV, json_encode($r, JSON_UNESCAPED_UNICODE)); }
ok('virman: kasa bulunamadı / pasif hedef', !kasa_virman_ekle($db, ['kaynak_kasa_id' => (string)$vA, 'hedef_kasa_id' => '99999', 'tarih' => gun(0), 'tutar' => '1,00'], 1)['ok']
    && (function () use ($db, $vA, $vB) { kasa_kart_durum($db, $vB, false, 1); $r = kasa_virman_ekle($db, ['kaynak_kasa_id' => (string)$vA, 'hedef_kasa_id' => (string)$vB, 'tarih' => gun(0), 'tutar' => '1,00'], 1); kasa_kart_durum($db, $vB, true, 1); return !$r['ok'] && str_contains((string)$r['hata'], 'Pasif'); })());
$r = kasa_virman_ekle($db, ['kaynak_kasa_id' => (string)$vA, 'hedef_kasa_id' => (string)$vB, 'tarih' => gun(0), 'tutar' => '600,01', 'istek_anahtari' => 'vir-1'], 1);
ok('virman yetersiz kaynak bakiye: 409 kodu, bakiye/eksik, HİÇBİR bacak yazılmadı', !$r['ok'] && $r['kod'] === 'yetersiz_bakiye' && $r['bakiye'] === 60000 && $r['eksik'] === 1 && say('kasa_hareketler') === $nV);
$r = kasa_virman_ekle($db, ['kaynak_kasa_id' => (string)$vA, 'hedef_kasa_id' => (string)$vB, 'tarih' => gun(0), 'tutar' => '600,01', 'istek_anahtari' => 'vir-1', 'eksiye_izin' => '1'], 1);
ok('virman eksiye_izin=1: kayıt + uyari, kaynak -0,01', $r['ok'] && str_contains((string)$r['uyari'], 'eksiye düştü') && kasa_bakiye($db, $vA) === -1 && kasa_bakiye($db, $vB) === 100001);
$rT = kasa_virman_ekle($db, ['kaynak_kasa_id' => (string)$vA, 'hedef_kasa_id' => (string)$vB, 'tarih' => gun(0), 'tutar' => '600,01', 'istek_anahtari' => 'vir-1', 'eksiye_izin' => '1'], 1);
ok('virman aynı istek anahtarı: tekrar=true, aynı id/belge, ek satır YOK', $rT['ok'] && $rT['tekrar'] === true && $rT['id'] === $r['id'] && $rT['belge_no'] === $r['belge_no'] && $rT['id2'] === $r['id2'] && say('kasa_hareketler') === $nV + 2);
$vid2 = (int)$r['id'];
$ir = kasa_hareket_iptal($db, (int)$r['id2'], 'Virman yanlıştı', 1);
ok('virman iptali HEDEF bacaktan: iki bacak birlikte iptal, bakiyeler geri (A 600,00 → B 400,00)', $ir['ok'] && count($ir['iptal_idler']) === 2 && kasa_hareket_oku($db, $vid2)['durum'] === 'iptal' && kasa_hareket_oku($db, (int)$r['id2'])['durum'] === 'iptal' && kasa_bakiye($db, $vA) === 60000 && kasa_bakiye($db, $vB) === 40000, json_encode($ir, JSON_UNESCAPED_UNICODE));
$ir = kasa_hareket_iptal($db, $vid, 'Virman yanlıştı', 1);
ok('virman iptali KAYNAK bacaktan; hedef eksiye düşmez (400→0) ve ilk virman iki bacak iptal', $ir['ok'] && count($ir['iptal_idler']) === 2 && kasa_bakiye($db, $vA) === 100000 && kasa_bakiye($db, $vB) === 0);
ok('virman iptali eksiye düşüren hedefte uyari (hedef harcanmışsa)', (function () use ($db, $vA, $vB, $giderK) {
    $r = kasa_virman_ekle($db, ['kaynak_kasa_id' => (string)$vA, 'hedef_kasa_id' => (string)$vB, 'tarih' => gun(0), 'tutar' => '300,00'], 1);
    ekle($vB, 'gider', '300,00', ['kalem_id' => (string)$giderK]);
    $i = kasa_hareket_iptal($db, (int)$r['id'], 'Geri alınıyor', 1);
    return $i['ok'] && str_contains((string)$i['uyari'], 'eksiye düştü') && kasa_bakiye($db, $vB) === -30000;
})());

echo "\n=== 8. Kaynaklı türler (\$kaynak) + kaynaklı iptal ===\n";
$kK = kart('Kaynak Kasası'); ekle($kK, 'acilis', '1.000,00');
$cd0 = say('cari_hareketler'); $n = say('kasa_hareketler');
$r = ekle($kK, 'kaynak_giris', '250,00', ['aciklama' => 'Çek tahsili'], ['modul' => 'ceksenet', 'id' => 7]);
$sk = $r['ok'] ? kasa_hareket_oku($db, (int)$r['id']) : null;
ok('kaynak_giris ($kaynak ile): G, belge KKG-, kaynak_modul/kaynak_id damgası, cari dekontu YAZILMAZ', $r['ok'] && $sk['yon'] === 'G' && str_starts_with($r['belge_no'], 'KKG-') && $sk['kaynak_modul'] === 'ceksenet' && (int)$sk['kaynak_id'] === 7 && $sk['cari_hareket_id'] === null && say('cari_hareketler') === $cd0 && kasa_bakiye($db, $kK) === 125000, json_encode($r, JSON_UNESCAPED_UNICODE));
$r8 = ekle($kK, 'kaynak_cikis', '300,00', ['aciklama' => 'Çek ödemesi'], ['modul' => 'ceksenet', 'id' => 8]);
ok('kaynak_cikis: C, belge KKC-, bakiye 950,00', $r8['ok'] && str_starts_with($r8['belge_no'], 'KKC-') && kasa_bakiye($db, $kK) === 95000);
$n2 = say('kasa_hareketler');
foreach (['kaynak_giris', 'kaynak_cikis'] as $t) { $r = ekle($kK, $t, '1,00', ['aciklama' => 'Sızma denemesi']); ok("POST yolu (\$kaynak === null): $t REDDEDİLİR", !$r['ok'] && isset($r['alanlar']['tur']) && say('kasa_hareketler') === $n2); }
ok('kaynak verilse bile virman reddedilir', !ekle($kK, 'virman', '1,00', ['aciklama' => 'xx'], ['modul' => 'ceksenet', 'id' => 9])['ok']);
$r = ekle($kK, 'kaynak_giris', '1,00', [], ['modul' => 'ceksenet', 'id' => 9]);
ok('kaynaklı türde açıklama ZORUNLU', !$r['ok'] && isset($r['alanlar']['aciklama']));
ok('geçersiz kaynak bağı: modül adı / id', !ekle($kK, 'kaynak_giris', '1,00', ['aciklama' => 'abc'], ['modul' => 'Çek-Senet', 'id' => 1])['ok'] && !ekle($kK, 'kaynak_giris', '1,00', ['aciklama' => 'abc'], ['modul' => 'ceksenet', 'id' => 0])['ok'] && !ekle($kK, 'kaynak_giris', '1,00', ['aciklama' => 'abc'], ['modul' => 'x', 'id' => 1])['ok']);
$r = ekle($kK, 'kaynak_cikis', '5.000,00', ['aciklama' => 'Büyük çıkış', 'istek_anahtari' => 'kay-1'], ['modul' => 'ceksenet', 'id' => 10]);
ok('kaynaklı çıkışta da yetersiz bakiye kuralı: 409 kodu + bakiye/eksik', !$r['ok'] && $r['kod'] === 'yetersiz_bakiye' && $r['bakiye'] === 95000 && $r['eksik'] === 405000);
$r = ekle($kK, 'kaynak_cikis', '5.000,00', ['aciklama' => 'Büyük çıkış', 'istek_anahtari' => 'kay-1', 'eksiye_izin' => '1'], ['modul' => 'ceksenet', 'id' => 10]);
ok('kaynaklı çıkış + eksiye_izin: kayıt + uyari', $r['ok'] && str_contains((string)$r['uyari'], 'eksiye düştü') && kasa_bakiye($db, $kK) === -405000);
$r = kasa_hareket_iptal($db, (int)ekle($kK, 'kaynak_giris', '10,00', ['aciklama' => 'Deneme'], ['modul' => 'ceksenet', 'id' => 11])['id'], 'Kasadan iptal', 1);
ok('kasa ekranından kaynaklı satır iptali → 409 kaynakli (kaynak belgesinden iptal edin)', !$r['ok'] && $r['kod'] === 'kaynakli' && str_contains($r['hata'], 'kaynak belgesinden iptal edin'));
$a1 = audit_say('cancel');
$r = kasa_kaynakli_iptal($db, 'ceksenet', 7, 'Çek iadesi yapıldı', 1);
ok('kasa_kaynakli_iptal: satır iptal, iptal_idler, neden damgası, audit cancel +1', $r['ok'] && count($r['iptal_idler']) === 1 && kasa_hareket_oku($db, $r['iptal_idler'][0])['durum'] === 'iptal' && kasa_hareket_oku($db, $r['iptal_idler'][0])['iptal_neden'] === 'Çek iadesi yapıldı' && audit_say('cancel') === $a1 + 1, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = kasa_kaynakli_iptal($db, 'ceksenet', 7, 'Çek iadesi yapıldı', 1);
ok('kaynaklı iptal IDEMPOTENT: tekrar → ok + boş liste, ek audit YOK', $r['ok'] && $r['iptal_idler'] === [] && audit_say('cancel') === $a1 + 1);
ok('kaynaklı iptal: kaynağı olmayan → ok + boş', kasa_kaynakli_iptal($db, 'ceksenet', 5555, 'Yok bu kaynak', 1)['iptal_idler'] === []);
ok('kaynaklı iptal: geçersiz modül / id / kısa neden / uzun neden → hata', !kasa_kaynakli_iptal($db, 'Çek', 7, 'Yeterli neden', 1)['ok'] && !kasa_kaynakli_iptal($db, 'ceksenet', 0, 'Yeterli neden', 1)['ok'] && !kasa_kaynakli_iptal($db, 'ceksenet', 8, 'ab', 1)['ok'] && !kasa_kaynakli_iptal($db, 'ceksenet', 8, str_repeat('x', 501), 1)['ok']);
$r = kasa_kaynakli_iptal($db, 'ceksenet', 10, 'Çıkış geri alındı', 1);
ok('kaynaklı iptal eksiye düşürmeyen / düşüren: iptal HER ZAMAN yapılır (çıkış iptali bakiyeyi artırır)', $r['ok'] && $r['uyari'] === null && kasa_bakiye($db, $kK) === 71000, json_encode($r, JSON_UNESCAPED_UNICODE));
$kay2 = ekle($kK, 'kaynak_giris', '10,00', ['aciklama' => 'İki satır 1'], ['modul' => 'ceksenet', 'id' => 20]); $kay3 = ekle($kK, 'kaynak_cikis', '5,00', ['aciklama' => 'İki satır 2'], ['modul' => 'ceksenet', 'id' => 20]);
$r = kasa_kaynakli_iptal($db, 'ceksenet', 20, 'Aynı kaynağın iki satırı', 1);
ok('aynı kaynağa bağlı TÜM geçerli satırlar iptal edilir (2 satır)', count($r['iptal_idler']) === 2 && kasa_hareket_oku($db, (int)$kay2['id'])['durum'] === 'iptal' && kasa_hareket_oku($db, (int)$kay3['id'])['durum'] === 'iptal');
ok('kaynaklı iptal dış transaction içinde çalışır (üst tx rollback → iptal de geri)', (function () use ($db, $kK) {
    $yeni = ekle($kK, 'kaynak_giris', '10,00', ['aciklama' => 'Tx testi'], ['modul' => 'ceksenet', 'id' => 30]);
    $db->beginTransaction(); kasa_kaynakli_iptal($db, 'ceksenet', 30, 'Üst tx geri alınacak', 1); $db->rollBack();
    return kasa_hareket_oku($db, (int)$yeni['id'])['durum'] === 'gecerli';
})());

echo "\n=== 9. İstek anahtarı (çift gönderim) ===\n";
$kI = kart('İstek Kasası'); $n = say('kasa_hareketler'); $cn = say('cari_hareketler');
$g = kasa_t_hareket($kI, 'cari_tahsilat', '100,00', ['cari_id' => (string)$C1, 'istek_anahtari' => 'ist-abc_1']);
$r1 = kasa_hareket_ekle($db, $g, 1); $r2 = kasa_hareket_ekle($db, $g, 1);
ok('aynı istek anahtarı: ikinci çağrı tekrar=true, aynı id + belge_no; ne kasa ne cari ek satır', $r1['ok'] && $r2['ok'] && $r2['tekrar'] === true && $r1['id'] === $r2['id'] && $r1['belge_no'] === $r2['belge_no'] && say('kasa_hareketler') === $n + 1 && say('cari_hareketler') === $cn + 1);
ok('istek anahtarı hareketin kendisinde saklanır (UNIQUE)', kasa_hareket_oku($db, (int)$r1['id'])['istek_anahtari'] === 'ist-abc_1' && say('kasa_hareketler', 'istek_anahtari = ?', ['ist-abc_1']) === 1);
ok('anahtarsız iki hareket serbest (NULL çoğul)', ekle($kI, 'acilis', '1,00')['ok'] && ekle($kI, 'acilis', '1,00')['ok']);
ok('DB düzeyinde son savunma: istek_anahtari UNIQUE indeksi (yarışta ikinci INSERT reddedilir)', (function () use ($db, $kI) {
    $g = kasa_t_hareket($kI, 'acilis', '3,00', ['istek_anahtari' => 'yaris-1']); $a = kasa_hareket_ekle($db, $g, 1);
    $n = say('kasa_hareketler');
    try { $db->exec("INSERT INTO kasa_hareketler (kasa_id, tarih, tur, yon, tutar, belge_no, istek_anahtari) VALUES ($kI, '" . gun(0) . "', 'acilis', 'G', 1, 'X', 'yaris-1')"); return false; } catch (PDOException $e) { return $a['ok'] && say('kasa_hareketler') === $n; }
})());

echo "\n=== 10. Para birimi ayrımı ===\n";
$r = ekle($D, 'cari_tahsilat', '1.000,00', ['cari_id' => (string)$C1, 'kur' => '32,5']);
$cdv = cari_hareket_oku($db, (int)kasa_hareket_oku($db, (int)$r['id'])['cari_hareket_id']);
ok('USD kasa: hareket pb USD (kasadan), kur 32.500000, cari dekontu USD + aynı kur', $r['ok'] && kasa_hareket_oku($db, (int)$r['id'])['para_birimi'] === 'USD' && $cdv['para_birimi'] === 'USD' && (float)$cdv['kur'] === 32.5 && (float)kasa_hareket_oku($db, (int)$r['id'])['kur'] === 32.5, json_encode($r, JSON_UNESCAPED_UNICODE));
ok('USD kasa kur bozuk → alanlar[kur]; kurlu TRY zaten reddedilir', isset(ekle($D, 'acilis', '1,00', ['kur' => 'abc'])['alanlar']['kur']) && isset(ekle($K, 'acilis', '1,00', ['kur' => '2'])['alanlar']['kur']));
ok('USD kasa kurlu/kursuz: kursuz kabul', ekle($D, 'acilis', '5,00')['ok']);
ok('"pb" parametresi yok sayılır: kasa USD iken para_birimi=TRY gönderilse de USD', kasa_hareket_oku($db, (int)ekle($D, 'acilis', '7,00', ['para_birimi' => 'TRY'])['id'])['para_birimi'] === 'USD');
$oz = kasa_genel_ozet($db);
$usdKasalar = array_filter(kasa_kart_listesi($db, ['aktif' => 1])['satirlar'], fn($x) => $x['para_birimi'] === 'USD');
ok('kasa_genel_ozet: pb anahtarları TRY, USD, EUR sırasıyla; toplam = nakit + banka; USD toplamı yalnız USD kasaların bakiyesi (para birimleri toplanmaz)', array_keys($oz) === ['TRY', 'USD', 'EUR'] && $oz['TRY']['toplam'] === $oz['TRY']['nakit'] + $oz['TRY']['banka']
    && $oz['USD']['toplam'] === array_sum(array_column($usdKasalar, 'bakiye')) && $oz['USD']['kasa_say'] === count($usdKasalar) && $oz['USD']['banka'] === 0, json_encode($oz));
$bk = kasa_bakiyeler_toplu($db, [$D, $K, 99999, $D]);
ok('kasa_bakiyeler_toplu: id → kuruş, hareketsiz 0, bilinmeyen id yok sayılmaz ama 0', $bk[$D] === kasa_bakiye($db, $D) && $bk[$K] === 0 && ($bk[99999] ?? null) === 0 && kasa_bakiyeler_toplu($db, []) === []);
ok('kasa_secenekleri: pb süzgeci yalnız o para birimini döner; alanlar id/kod/ad/tip/pb', (function () use ($db, $D) { $l = kasa_secenekleri($db, 'USD'); return count($l) >= 1 && !array_filter($l, fn($x) => $x['pb'] !== 'USD') && in_array($D, array_column($l, 'id'), true) && array_keys($l[0]) === ['id', 'kod', 'ad', 'tip', 'pb', 'eksi_izin', 'banka_adi', 'depo']; })());
ok('kasa_secenekleri: pasif kasa varsayılan gizli, $yalnizAktif=false ile gelir', (function () use ($db, $idY) { kasa_kart_durum($db, $idY, false, 1); $a = in_array($idY, array_column(kasa_secenekleri($db), 'id'), true); $b = in_array($idY, array_column(kasa_secenekleri($db, null, false), 'id'), true); kasa_kart_durum($db, $idY, true, 1); return !$a && $b; })());

echo "\n=== 11. Defter / liste / yürüyen bakiye ===\n";
$kF = kart('Defter Kasası');
ekle($kF, 'acilis', '1.000,00', ['tarih' => gun(-60)]);
ekle($kF, 'gelir', '200,00', ['tarih' => gun(-30), 'kalem_id' => (string)$gelirK]);
ekle($kF, 'gider', '50,00', ['tarih' => gun(-10), 'kalem_id' => (string)$giderK]);
ekle($kF, 'gider', '25,00', ['tarih' => gun(-10), 'kalem_id' => (string)$giderK]);
$iptalGelir = ekle($kF, 'gelir', '999,00', ['tarih' => gun(-20), 'kalem_id' => (string)$gelirK]); kasa_hareket_iptal($db, (int)$iptalGelir['id'], 'Hatalı giriş', 1);
$cf = ekle($kF, 'cari_tahsilat', '10,00', ['tarih' => gun(0), 'cari_id' => (string)$C1]);
$df = kasa_defter($db, $kF, gun(-30), gun(-10));
ok('defter: devir 1.000,00 (bas öncesi), 3 dönem satırı (iptal YOK), giriş 200,00 çıkış 75,00, kapanış 1.125,00', $df !== null && $df['devir'] === 100000 && count($df['satirlar']) === 3 && $df['donem_giris'] === 20000 && $df['donem_cikis'] === 7500 && $df['kapanis'] === 112500 && $df['pb'] === 'TRY', json_encode($df['satirlar'] ?? null, JSON_UNESCAPED_UNICODE));
ok('defter: yürüyen bakiye 1.200,00 → 1.150,00 → 1.125,00 (tarih, id sırası)', array_column($df['satirlar'], 'yurume') === [120000, 115000, 112500] && array_column($df['satirlar'], 'tutar_kurus') === [20000, 5000, 2500]);
$dt = kasa_defter($db, $kF, null, null);
ok('defter: bas/bit boş → devir 0, tüm geçerli satırlar, kapanış = kasa_bakiye', $dt['devir'] === 0 && count($dt['satirlar']) === 5 && $dt['kapanis'] === kasa_bakiye($db, $kF) && $dt['kapanis'] === 100000 + 20000 - 5000 - 2500 + 1000);
ok('defter: cari satırında cari unvanı çözülür (cari tablosuna SQL değil, cari_kart_oku)', end($dt['satirlar'])['cari_unvan'] === 'ÖRNEK TİCARET A.Ş.');
ok('defter: kasa yok → null', kasa_defter($db, 99999, null, null) === null);
$l = kasa_hareket_listesi($db, ['kasa_id' => $kF, 'durum' => 'gecerli']);
ok('liste (tek kasa + gecerli): 5 satır en yeni önce, yurume_var, ilk satır yurume = bakiye, son satır yurume = açılış', $l['toplam_satir'] === 5 && $l['yurume_var'] === true && $l['satirlar'][0]['yurume'] === kasa_bakiye($db, $kF) && $l['satirlar'][4]['yurume'] === 100000
    && $l['satirlar'][0]['tur'] === 'cari_tahsilat' && array_column($l['satirlar'], 'yurume') === [113500, 112500, 115000, 120000, 100000]);
ok('liste: tumu → 6 satır (iptal dahil), yurume YOK; iptal → 1 satır', ($t = kasa_hareket_listesi($db, ['kasa_id' => $kF]))['toplam_satir'] === 6 && $t['yurume_var'] === false && !isset($t['satirlar'][0]['yurume']) && kasa_hareket_listesi($db, ['kasa_id' => $kF, 'durum' => 'iptal'])['toplam_satir'] === 1);
ok('liste: tür / ara süzgeci → yurume YOK; tür=gider 2 satır', ($t = kasa_hareket_listesi($db, ['kasa_id' => $kF, 'durum' => 'gecerli', 'tur' => 'gider']))['toplam_satir'] === 2 && $t['yurume_var'] === false);
ok('liste: ara = kalem adı (Yakıt) / belge no / açıklama', kasa_hareket_listesi($db, ['kasa_id' => $kF, 'ara' => 'Yakıt'])['toplam_satir'] === 2 && kasa_hareket_listesi($db, ['kasa_id' => $kF, 'ara' => $cf['belge_no']])['toplam_satir'] === 1);
ok('liste: ara = cari unvanı (cari_core fonksiyonuyla çözülür)', kasa_hareket_listesi($db, ['kasa_id' => $kF, 'ara' => 'TİCARET'])['toplam_satir'] === 1);
ok('liste: cari_id süzgeci', kasa_hareket_listesi($db, ['kasa_id' => $kF, 'cari_id' => $C1])['toplam_satir'] === 1);
ok('liste: dönem bugun → 1, ozel(-30..-10) → 3 (iptal dahil olmadan: gecerli)', kasa_hareket_listesi($db, ['kasa_id' => $kF, 'donem' => 'bugun'])['toplam_satir'] === 1 && kasa_hareket_listesi($db, ['kasa_id' => $kF, 'donem' => 'ozel', 'bas' => gun(-30), 'bit' => gun(-10), 'durum' => 'gecerli'])['toplam_satir'] === 3);
ok('liste: dönem aralığı ile yürüyen bakiye hâlâ kasa bütününe göre (dönem sonu satırı)', ($t = kasa_hareket_listesi($db, ['kasa_id' => $kF, 'durum' => 'gecerli', 'donem' => 'ozel', 'bas' => gun(-30), 'bit' => gun(-10)]))['yurume_var'] === true && $t['satirlar'][0]['yurume'] === 112500);
$t = kasa_hareket_listesi($db, ['kasa_id' => $kF, 'durum' => 'gecerli'], 2, 2);
ok('liste: sayfalama limit 2 offset 2 → 2 satır, toplam 5, yurume sayfa başı doğru', count($t['satirlar']) === 2 && $t['toplam_satir'] === 5 && $t['satirlar'][0]['yurume'] === 115000);
$o = kasa_hareket_listesi($db, ['kasa_id' => $kF, 'durum' => 'tumu'])['ozet'];
ok('liste özeti: yalnız GEÇERLİ satırlar; TRY giriş 1.210,00, çıkış 75,00, adet 5', $o['TRY'] === ['giris' => 121000, 'cikis' => 7500, 'adet' => 5], json_encode($o));
ok('liste: kasa süzgeci yokken pb başına ayrı özet (TRY + USD karışmaz)', (function () use ($db) { $o = kasa_hareket_listesi($db, ['durum' => 'gecerli'])['ozet']; return isset($o['TRY'], $o['USD']) && array_keys($o) === ['TRY', 'USD']; })());
ok('liste satırı virman eşi kasası: karsi_kasa_ad', (function () use ($db, $vA, $vB) { kasa_virman_ekle($db, ['kaynak_kasa_id' => (string)$vA, 'hedef_kasa_id' => (string)$vB, 'tarih' => gun(0), 'tutar' => '1,00'], 1); $s = kasa_hareket_listesi($db, ['kasa_id' => $vA, 'tur' => 'virman', 'durum' => 'gecerli'])['satirlar']; return $s && $s[0]['karsi_kasa_ad'] === 'Virman B'; })());
ok('liste: bilinmeyen durum → tumu', kasa_hareket_listesi($db, ['kasa_id' => $kF, 'durum' => 'x'])['toplam_satir'] === 6);

echo "\n=== 12. Depo ===\n";
$DEPOLAR = ['Şube Depo'];
$ls = array_column(kasa_kart_listesi($db)['satirlar'], 'ad');
ok('kart listesi: aktif depo (Şube) + boş depolu kasalar; Merkez Depo kasası GİZLİ', in_array('Şube Kasası', $ls, true) && in_array('Örnek Banka TL', $ls, true) && in_array('Döviz Kasası', $ls, true) && !in_array('Merkez Kasa', $ls, true));
ok('kasa_secenekleri aynı süzgeci uygular', !in_array($K, array_column(kasa_secenekleri($db), 'id'), true) && in_array($S, array_column(kasa_secenekleri($db), 'id'), true));
$n = say('kasa_hareketler');
$r = ekle($K, 'acilis', '1,00');
ok('görünmeyen kasaya hareket: hata "aktif deponuzda görünmüyor", yazılmadı', !$r['ok'] && str_contains((string)$r['hata'], 'aktif deponuzda görünmüyor') && say('kasa_hareketler') === $n);
ok('virman: kaynak/hedef görünmüyorsa reddedilir', !kasa_virman_ekle($db, ['kaynak_kasa_id' => (string)$K, 'hedef_kasa_id' => (string)$S, 'tarih' => gun(0), 'tutar' => '1,00'], 1)['ok']);
ok('defter görünmeyen kasa için null; liste o kasa için 0 satır', kasa_defter($db, $K, null, null) === null && kasa_hareket_listesi($db, ['kasa_id' => $K])['toplam_satir'] === 0);
ok('boş depolu kasa (Banka) her depoda kullanılır', ekle($B, 'acilis', '1,00')['ok']);
$b0 = kasa_bakiye($db, $S);
$AKTIF_DEPO = 'Şube Depo'; $rs1 = ekle($S, 'acilis', '10,00');
$AKTIF_DEPO = 'Merkez Depo'; $rs2 = ekle($S, 'acilis', '5,00');   // ikinci hareket BAŞKA depo damgalı
ok('kasa_bakiye depo süzgeci UYGULAMAZ: farklı depo damgalı iki hareket de bakiyeye girer (+15,00), bakiye int', $rs1['ok'] && $rs2['ok'] && kasa_hareket_oku($db, (int)$rs1['id'])['depo'] === 'Şube Depo' && kasa_hareket_oku($db, (int)$rs2['id'])['depo'] === 'Merkez Depo'
    && kasa_bakiye($db, $S) === $b0 + 1500 && is_int(kasa_bakiye($db, $S)) && kasa_bakiye_kilitli($db, $S) === kasa_bakiye($db, $S));
$DEPOLAR = null;
ok('kısıtsız kullanıcı hepsini görür', in_array('Merkez Kasa', array_column(kasa_kart_listesi($db)['satirlar'], 'ad'), true));
ok('hareket deposu = active_depot() damgası (kasa kartı deposundan bağımsız)', (function () use ($db, $S) { global $AKTIF_DEPO; $AKTIF_DEPO = 'Şube Depo'; $r = ekle($S, 'acilis', '2,00'); $d = kasa_hareket_oku($db, (int)$r['id'])['depo']; $AKTIF_DEPO = 'Merkez Depo'; return $d === 'Şube Depo'; })());

echo "\n=== 13. Kart listesi / silme ===\n";
$l = kasa_kart_listesi($db, ['tip' => 'banka']);
ok('kart listesi tip süzgeci + bakiye + hareket_say alanları', $l['satirlar'] && !array_filter($l['satirlar'], fn($x) => $x['tip'] !== 'banka') && isset($l['satirlar'][0]['bakiye']) && isset($l['satirlar'][0]['hareket_say']) && $l['kesildi'] === false);
ok('kart listesi: ara (kod/ad/banka/iban) + pb + aktif süzgeçleri', count(kasa_kart_listesi($db, ['ara' => 'Deneme'])['satirlar']) === 1 && count(kasa_kart_listesi($db, ['ara' => 'TR3300061005'])['satirlar']) >= 2 && !array_filter(kasa_kart_listesi($db, ['pb' => 'USD'])['satirlar'], fn($x) => $x['para_birimi'] !== 'USD')
    && !array_filter(kasa_kart_listesi($db, ['aktif' => 1])['satirlar'], fn($x) => (int)$x['aktif'] !== 1));
$r = kasa_kart_sil($db, $kY, 1);
ok('hareketi olan kasa silinemez: 409 kodu hareketli', !$r['ok'] && $r['kod'] === 'hareketli' && kasa_kart_oku($db, $kY) !== null);
$r = kasa_kart_kaydet($db, ['ad' => 'Hareket Kasası', 'tip' => 'nakit', 'para_birimi' => 'USD'], 1, $kY);
ok('hareketi olan kasanın para birimi değişmez (alanlar[para_birimi], kod hareketli)', !$r['ok'] && isset($r['alanlar']['para_birimi']) && $r['kod'] === 'hareketli' && kasa_kart_oku($db, $kY)['para_birimi'] === 'TRY');
$sil = kart('Silinecek Kasa'); $d0 = audit_say('delete');
$r = kasa_kart_sil($db, $sil, 1);
ok('hareketsiz kasa silinir + audit delete', $r['ok'] && kasa_kart_oku($db, $sil) === null && audit_say('delete') === $d0 + 1);
ok('silinecek kasa bulunamadı', !kasa_kart_sil($db, 99999, 1)['ok']);
ok('yalnız İPTAL edilmiş hareketi olan kasa da silinemez (iptaller dahil sayılır)', (function () use ($db) { $k = kart('Sadece İptal'); $r = ekle($k, 'acilis', '1,00'); kasa_hareket_iptal($db, (int)$r['id'], 'Deneme', 1); return (kasa_kart_sil($db, $k, 1)['kod'] ?? '') === 'hareketli'; })());
$kP = kart('Bakiyeli Kasa'); ekle($kP, 'acilis', '10,00');
$r = kasa_kart_durum($db, $kP, false, 1);
ok('bakiyesi olan kasa pasife alınır + uyarı', $r['ok'] && str_contains((string)$r['uyari'], 'bakiyesi sıfır değil'));

echo "\n=== 14. Tanımlar (gelir / gider kalemleri) ===\n";
ekle($K, 'acilis', '100,00');
ok('kasa_tanim_listesi: geçersiz tür boş; kullanım sayısı (Yakıt kullanımda)', kasa_tanim_listesi($db, 'x') === [] && (int)array_values(array_filter(kasa_tanim_listesi($db, 'gider'), fn($t) => $t['ad'] === 'Yakıt'))[0]['kullanim'] > 0);
$r = kasa_tanim_kaydet($db, 'gider', 'Su Faturası', 70, 1);
ok('tanım ekle: aktif, sıra 70, audit', $r['ok'] && kasa_tanim_bul($db, (int)$r['id'])['sira'] == 70 && (int)kasa_tanim_bul($db, (int)$r['id'])['aktif'] === 1);
$tid = (int)$r['id'];
ok('tanım ad TR-duyarsız tekil ("SU FATURASI" / "su faturası")', !kasa_tanim_kaydet($db, 'gider', 'SU FATURASI', 1, 1)['ok'] && !kasa_tanim_kaydet($db, 'gider', 'su faturası', 1, 1)['ok'] && !kasa_tanim_kaydet($db, 'gelir', 'diğer gelir', 1, 1)['ok']);
ok('aynı ad FARKLI türde serbest (gelir: Su Faturası)', kasa_tanim_kaydet($db, 'gelir', 'Su Faturası', 1, 1)['ok']);
ok('tanım doğrulama: boş ad / 101 karakter / geçersiz tür', !kasa_tanim_kaydet($db, 'gider', ' ', 1, 1)['ok'] && !kasa_tanim_kaydet($db, 'gider', str_repeat('a', 101), 1, 1)['ok'] && !kasa_tanim_kaydet($db, 'cari', 'Xyz', 1, 1)['ok']);
$r = kasa_tanim_kaydet($db, 'gelir', 'Su Gideri', 5, 1, $tid);
ok('düzenleme: ad + sıra güncellenir, TÜR DEĞİŞMEZ (gider kalır)', $r['ok'] && kasa_tanim_bul($db, $tid)['ad'] === 'Su Gideri' && kasa_tanim_bul($db, $tid)['tur'] === 'gider' && kasa_tanim_bul($db, $tid)['sira'] == 5);
ok('düzenleme: bulunamadı', !kasa_tanim_kaydet($db, 'gider', 'Abc', 1, 1, 99999)['ok']);
$hk = ekle($K, 'gider', '0,50', ['kalem_id' => (string)$tid]);
ok('kullanılan kalemin adı sonradan değişse de hareket anlık görüntüsü KALIR', $hk['ok'] && (function () use ($db, $tid, $hk) { kasa_tanim_kaydet($db, 'gider', 'Su Gideri 2', 5, 1, $tid); return kasa_hareket_oku($db, (int)$hk['id'])['kalem_ad'] === 'Su Gideri'; })());
$r = kasa_tanim_sil($db, $tid, 1);
ok('kullanılan kalem silinemez: 409 kodu kullanimda (pasifleştirin)', !$r['ok'] && $r['kod'] === 'kullanimda' && kasa_tanim_bul($db, $tid) !== null);
ok('tanım durum: pasif kalem yeni harekete SEÇİLEMEZ; yeniden aktifleşince seçilir', (function () use ($db, $tid, $K) { kasa_tanim_durum($db, $tid, false, 1); $r = ekle($K, 'gider', '0,01', ['kalem_id' => (string)$tid]); kasa_tanim_durum($db, $tid, true, 1); return !$r['ok'] && isset($r['alanlar']['kalem_id']) && ekle($K, 'gider', '0,01', ['kalem_id' => (string)$tid])['ok']; })());
$r = kasa_tanim_kaydet($db, 'gelir', 'Kullanılmayan', 90, 1); $r2 = kasa_tanim_sil($db, (int)$r['id'], 1);
ok('kullanılmayan kalem silinir', $r2['ok'] && kasa_tanim_bul($db, (int)$r['id']) === null && !kasa_tanim_sil($db, 99999, 1)['ok']);
ok('kasa_tanim_listesi yalnızAktif süzgeci', !array_filter(kasa_tanim_listesi($db, 'gider', true), fn($t) => (int)$t['aktif'] !== 1));

echo "\n=== 15. Uç (gerçek kasa_kaydet.php) ===\n";
$r = kasa_t_istek_cagri(['dosya' => 'kasa_kaydet.php', 'get' => ['islem' => 'hareket_ekle']]);
ok('GET → 405 + Allow: POST', $r['kod'] === 405 && baslik($r, 'Allow') === 'POST');
$r = kasa_t_istek_cagri(['dosya' => 'kasa_kaydet.php', 'post' => ['islem' => 'hareket_ekle'], 'server' => ['HTTP_X_REQUESTED_WITH' => 'fetch']]);
ok('CSRF belirteci yok → 403 (yazma yok; JSON başlığı gerçek istekte csrf_check\'i JSON yapar — CLI harness başlık yakalar)', $r['kod'] === 403 && str_contains($r['govde'], 'CSRF') && say('kasa_hareketler', "aciklama = 'csrf'") === 0);
$n = say('kasa_hareketler');
$r = uc(['islem' => 'hareket_ekle'] + kasa_t_hareket($K, 'acilis', '1,00'), true, ['admin' => false, 'perms' => ['reports.export', 'records.write', 'stok.write']]);
ok('yönetici olmayan: 403 JSON "yetkiniz yok", satır yazılmadı', $r['kod'] === 403 && js($r)['ok'] === false && str_contains((string)js($r)['hata'], 'yetkiniz yok') && say('kasa_hareketler') === $n, $r['govde']);
$tum = ['kart_kaydet' => ['ad' => 'Yetkisiz Kasa'], 'kart_durum' => ['id' => (string)$K, 'aktif' => '0'], 'kart_sil' => ['id' => (string)$sil], 'tanim_kaydet' => ['tur' => 'gelir', 'ad' => 'Yetkisiz Kalem'], 'tanim_durum' => ['id' => '1', 'aktif' => '0'],
    'tanim_sil' => ['id' => '1'], 'hareket_ekle' => kasa_t_hareket($K, 'acilis', '1,00'), 'virman_ekle' => ['kaynak_kasa_id' => (string)$vA, 'hedef_kasa_id' => (string)$vB, 'tarih' => gun(0), 'tutar' => '1,00'], 'hareket_iptal' => ['id' => (string)$kay3['id'], 'neden' => 'Yetkisiz iptal']];
$snap = fn() => [say('kasa_kartlar'), say('kasa_tanimlar'), say('kasa_hareketler'), say('kasa_hareketler', "durum = 'iptal'"), say('kasa_kartlar', 'aktif = 1')];
$s0 = $snap(); $hepsi403 = true;
foreach ($tum as $islem => $post) { $r = uc(['islem' => $islem] + $post, true, ['admin' => false]); if ($r['kod'] !== 403) { $hepsi403 = false; echo "  ($islem → {$r['kod']})\n"; } }
ok('9 işlemin HEPSİ yönetici dışına 403; hiçbir veri değişmedi', $hepsi403 && $snap() === $s0);
$r = uc(['islem' => 'kart_kaydet', 'ad' => 'Uç Kasası', 'tip' => 'banka', 'para_birimi' => 'TRY', 'banka_adi' => 'Örnek Banka', 'eksi_izin' => '1', 'aktif' => '1']);
$j = js($r); $uK = (int)($j['id'] ?? 0);
ok('kart_kaydet JSON 200: ok, id, boş alanlar, yonlendir (varsayılan kasa_fisleri.php)', $r['kod'] === 200 && $j['ok'] === true && $uK > 0 && $j['yonlendir'] === 'kasa_fisleri.php' && $j['hata'] === null && kasa_kart_oku($db, $uK)['tip'] === 'banka' && (int)kasa_kart_oku($db, $uK)['eksi_izin'] === 1, $r['govde']);
$r = uc(['islem' => 'kart_kaydet', 'ad' => 'U']);
ok('kart_kaydet doğrulama: 422 + alanlar{ad}', $r['kod'] === 422 && js($r)['ok'] === false && isset(js($r)['alanlar']['ad']), $r['govde']);
$r = uc(['islem' => 'kart_kaydet', 'ad' => 'Uç Kasası 2', 'kod' => 'KS-001']);
ok('kod çakışması: 409 kod=kullanimda + alanlar{kod}', $r['kod'] === 409 && js($r)['kod'] === 'kullanimda' && isset(js($r)['alanlar']['kod']), $r['govde']);
$r = uc(['islem' => 'kart_kaydet', 'id' => (string)$uK, 'ad' => 'Uç Kasası', 'tip' => 'banka', 'para_birimi' => 'TRY']);
ok('kart_kaydet düzenleme (checkbox gelmedi → eksi_izin=0, aktif=0)', $r['kod'] === 200 && (int)kasa_kart_oku($db, $uK)['eksi_izin'] === 0 && (int)kasa_kart_oku($db, $uK)['aktif'] === 0);
$r = uc(['islem' => 'kart_durum', 'id' => (string)$uK, 'aktif' => '1']);
ok('kart_durum aktif=1 → 200', $r['kod'] === 200 && (int)kasa_kart_oku($db, $uK)['aktif'] === 1);
$ik = 'uc-' . bin2hex(random_bytes(3));
$r = uc(['islem' => 'hareket_ekle'] + kasa_t_hareket($uK, 'acilis', '100,00', ['istek_anahtari' => $ik]));
$j = js($r);
ok('hareket_ekle JSON 200: belge_no KAC-, tekrar=false, yonlendir kasa_fisleri.php?kasa=ID', $r['kod'] === 200 && $j['ok'] && preg_match('/^KAC-\d{4}-\d{6}$/', (string)$j['belge_no']) === 1 && $j['tekrar'] === false && $j['yonlendir'] === 'kasa_fisleri.php?kasa=' . $uK, $r['govde']);
$r = uc(['islem' => 'hareket_ekle'] + kasa_t_hareket($uK, 'acilis', '100,00', ['istek_anahtari' => $ik]));
ok('uç: aynı istek anahtarı → 200 tekrar=true, ek satır YOK', $r['kod'] === 200 && js($r)['tekrar'] === true && say('kasa_hareketler', 'istek_anahtari = ?', [$ik]) === 1);
$r = uc(['islem' => 'hareket_ekle'] + kasa_t_hareket($uK, 'gider', '100,01', ['kalem_id' => (string)$giderK, 'istek_anahtari' => 'uc-yet']));
$j = js($r);
ok('uç: yetersiz bakiye → 409 kod=yetersiz_bakiye + bakiye 10000 + eksik 1 (JSON tam sayı)', $r['kod'] === 409 && $j['kod'] === 'yetersiz_bakiye' && $j['bakiye'] === 10000 && $j['eksik'] === 1 && $j['ok'] === false, $r['govde']);
$r = uc(['islem' => 'hareket_ekle'] + kasa_t_hareket($uK, 'gider', '100,01', ['kalem_id' => (string)$giderK, 'istek_anahtari' => 'uc-yet', 'eksiye_izin' => '1']));
ok('uç: "Yine de kaydet" (aynı istek_anahtari + eksiye_izin=1) → 200 + uyari', $r['kod'] === 200 && js($r)['ok'] && str_contains((string)js($r)['uyari'], 'eksiye düştü'), $r['govde']);
foreach (['kaynak_giris', 'kaynak_cikis'] as $t) { $n = say('kasa_hareketler'); $r = uc(['islem' => 'hareket_ekle'] + kasa_t_hareket($uK, $t, '1,00', ['aciklama' => 'Sızma'])); ok("uç: dahili tür $t → 422 (POST'tan reddedilir), yazma yok", $r['kod'] === 422 && isset(js($r)['alanlar']['tur']) && say('kasa_hareketler') === $n, $r['govde']); }
$r = uc(['islem' => 'hareket_ekle'] + kasa_t_hareket($uK, 'acilis', '1,00', ['kaynak_modul' => 'ceksenet', 'kaynak_id' => '5', 'kaynak' => 'ceksenet', 'aciklama' => 'x']));
$sat = kasa_hareket_oku($db, (int)js($r)['id']);
ok('uç: POST\'taki kaynak_modul/kaynak_id YOK SAYILIR (satır kaynaksız → kasa ekranından iptal edilebilir)', $r['kod'] === 200 && $sat['kaynak_modul'] === null && $sat['kaynak_id'] === null);
$r = uc(['islem' => 'hareket_ekle'] + kasa_t_hareket($uK, 'cari_tahsilat', '5,00', ['cari_id' => (string)$C1]));
ok('uç: cari_tahsilat → 200 + cari_belge_no TAH-', $r['kod'] === 200 && str_starts_with((string)js($r)['cari_belge_no'], 'TAH-'), $r['govde']);
$r = uc(['islem' => 'virman_ekle', 'kaynak_kasa_id' => (string)$uK, 'hedef_kasa_id' => (string)$vB, 'tarih' => gun(0), 'tutar' => '1,00', 'eksiye_izin' => '1']);
$j = js($r);
ok('virman_ekle JSON 200: id + id2 + KVR- belge; yonlendir kaynak kasaya', $r['kod'] === 200 && $j['ok'] && $j['id2'] > 0 && str_starts_with((string)$j['belge_no'], 'KVR-') && $j['yonlendir'] === 'kasa_fisleri.php?kasa=' . $uK, $r['govde']);
$r = uc(['islem' => 'virman_ekle', 'kaynak_kasa_id' => (string)$uK, 'hedef_kasa_id' => (string)$D, 'tarih' => gun(0), 'tutar' => '1,00']);
ok('virman farklı para birimi → 422 + alanlar{hedef_kasa_id}', $r['kod'] === 422 && isset(js($r)['alanlar']['hedef_kasa_id']));
$r = uc(['islem' => 'hareket_iptal', 'id' => (string)$kay3['id'], 'neden' => 'Deneme']);
ok('uç: hareket_iptal zaten iptal edilmiş kaynaklı → 422 zaten_iptal ya da 409 kaynakli (kaynaklı satır hiçbir yoldan kasa ucundan iptal edilemez)', in_array($r['kod'], [409, 422], true) && js($r)['ok'] === false);
$kaynakli = ekle($kK, 'kaynak_giris', '3,00', ['aciklama' => 'Uç kaynaklı'], ['modul' => 'ceksenet', 'id' => 40]);
$r = uc(['islem' => 'hareket_iptal', 'id' => (string)$kaynakli['id'], 'neden' => 'Ucundan iptal']);
ok('uç: kaynaklı satır iptali → 409 kod=kaynakli; satır geçerli kalır', $r['kod'] === 409 && js($r)['kod'] === 'kaynakli' && kasa_hareket_oku($db, (int)$kaynakli['id'])['durum'] === 'gecerli', $r['govde']);
$r = uc(['islem' => 'hareket_iptal', 'id' => (string)$sat['id'], 'neden' => 'Deneme iptal']);
ok('uç: hareket_iptal → 200; kısa neden → 422', $r['kod'] === 200 && kasa_hareket_oku($db, (int)$sat['id'])['durum'] === 'iptal' && uc(['islem' => 'hareket_iptal', 'id' => '1', 'neden' => 'a'])['kod'] === 422);
$r = uc(['islem' => 'kart_sil', 'id' => (string)$uK]);
ok('uç: hareketli kasa silinemez → 409 kod=hareketli', $r['kod'] === 409 && js($r)['kod'] === 'hareketli', $r['govde']);
$r = uc(['islem' => 'tanim_kaydet', 'tur' => 'gelir', 'ad' => 'Uç Kalemi', 'sira' => '15']);
$uT = (int)(js($r)['id'] ?? 0);
ok('tanim_kaydet 200 (id); aynı ad → 422', $r['kod'] === 200 && $uT > 0 && uc(['islem' => 'tanim_kaydet', 'tur' => 'gelir', 'ad' => 'UÇ KALEMİ'])['kod'] === 422);
ok('tanim_durum (checkbox yok → pasif) → 200', uc(['islem' => 'tanim_durum', 'id' => (string)$uT])['kod'] === 200 && (int)kasa_tanim_bul($db, $uT)['aktif'] === 0);
$r = uc(['islem' => 'tanim_sil', 'id' => (string)$gelirK]);
ok('tanim_sil kullanılan → 409 kullanimda; kullanılmayan → 200', $r['kod'] === 409 && js($r)['kod'] === 'kullanimda' && uc(['islem' => 'tanim_sil', 'id' => (string)$uT])['kod'] === 200 && kasa_tanim_bul($db, $uT) === null);
$r = uc(['islem' => 'yok_boyle']);
ok('bilinmeyen işlem → 422 "Bilinmeyen işlem."', $r['kod'] === 422 && js($r)['hata'] === 'Bilinmeyen işlem.');
$r = uc(['islem' => 'hareket_ekle'] + kasa_t_hareket($uK, 'acilis', '2,00'), false, ['server' => []]);
ok('klasik form: 303 + Location kasa_fisleri.php?kasa=ID + flash success (belge no içerir)', $r['kod'] === 303 && baslik($r, 'Location') === 'kasa_fisleri.php?kasa=' . $uK && ($r['flash']['type'] ?? '') === 'success' && str_contains((string)($r['flash']['msg'] ?? ''), 'KAC-'), json_encode([$r['kod'], $r['basliklar'], $r['flash']], JSON_UNESCAPED_UNICODE));
$r = uc(['islem' => 'hareket_ekle', 'geri' => 'https://kotu.example/kasa_fisleri.php'] + kasa_t_hareket($uK, 'acilis', '0'), false);
ok('klasik form + dış `geri` → kasa_fisleri.php\'ye 303; hata flash error', $r['kod'] === 303 && baslik($r, 'Location') === 'kasa_fisleri.php' && ($r['flash']['type'] ?? '') === 'error');
$r = uc(['islem' => 'tanim_kaydet', 'tur' => 'gider', 'ad' => 'Klasik Kalem', 'geri' => 'kasa_tanimlari.php?sekme=gider'], false);
ok('klasik form + yerel `geri` (kasa_tanimlari.php?sekme=gider) korunur', $r['kod'] === 303 && baslik($r, 'Location') === 'kasa_tanimlari.php?sekme=gider');
$r = uc(['islem' => 'hareket_ekle', 'geri' => 'cari_kartlari.php'] + kasa_t_hareket($uK, 'acilis', '0'), false);
ok('klasik form + başka modül sayfası `geri` → kasa_fisleri.php', baslik($r, 'Location') === 'kasa_fisleri.php');
$r = uc(['islem' => 'hareket_ekle'] + kasa_t_hareket($uK, 'gider', '99999,00', ['kalem_id' => (string)$giderK]), false);
ok('klasik form yetersiz bakiye: 303 + error flash (mesaj tutarları içerir)', $r['kod'] === 303 && ($r['flash']['type'] ?? '') === 'error' && str_contains((string)($r['flash']['msg'] ?? ''), 'yetersiz'));

echo "\n=== 15b. Denetim düzeltmeleri (depo kapsamı, kod/tip koruma, PDO hatası, UNIQUE) ===\n";
$DEPOLAR = null; $AKTIF_DEPO = 'Merkez Depo';
$dM = kart('Dz Merkez Kasa', 'nakit', 'TRY', ['depo' => 'Merkez Depo']); $dS = kart('Dz Şube Kasa', 'nakit', 'TRY', ['depo' => 'Şube Depo']); $dB = kart('Dz Boş Depolu', 'nakit', 'TRY');
$AKTIF_DEPO = 'Şube Depo'; $hS = ekle($dS, 'acilis', '100,00'); $hSk = ekle($dS, 'kaynak_giris', '7,00', ['aciklama' => 'dz kaynakli giris'], ['modul' => 'dz_test', 'id' => 901]); $AKTIF_DEPO = 'Merkez Depo';
$hB = ekle($dB, 'acilis', '50,00');
$DEPOLAR = ['Merkez Depo'];
$r = kasa_hareket_iptal($db, (int)$hS['id'], 'baska depo kasasi', 1);
ok('depo dışı kasada hareket iptali reddedilir (kod depo; mesaj hangi depo olduğunu söyler); hareket geçerli kalır', !$r['ok'] && ($r['kod'] ?? '') === 'depo' && str_contains((string)$r['hata'], 'Şube Depo') && kasa_hareket_oku($db, (int)$hS['id'])['durum'] === 'gecerli', json_encode($r, JSON_UNESCAPED_UNICODE));
$r = kasa_kart_kaydet($db, ['ad' => 'Degisti', 'tip' => 'nakit', 'para_birimi' => 'TRY', 'depo' => 'Şube Depo'], 1, $dS);
ok('depo dışı kasanın kartı düzenlenemez (ad değişmedi)', !$r['ok'] && ($r['kod'] ?? '') === 'depo' && kasa_kart_oku($db, $dS)['ad'] === 'Dz Şube Kasa');
ok('depo dışı kasa pasife alınamaz / silinemez (kart değişmedi)', !kasa_kart_durum($db, $dS, false, 1)['ok'] && (int)kasa_kart_oku($db, $dS)['aktif'] === 1 && !kasa_kart_sil($db, $dS, 1)['ok'] && kasa_kart_oku($db, $dS) !== null);
$r = kasa_hareket_iptal($db, (int)$hB['id'], 'bos depolu kasa serbest', 1);
ok('boş depolu kasada iptal her depoda serbest', $r['ok']);
$r = kasa_kaynakli_iptal($db, 'dz_test', 901, 'kaynak belge iptali', 1);
ok('kasa_kaynakli_iptal depo denetimi YAPMAZ (kaynak modül çağırır): depo dışı kasadaki kaynaklı satır iptal edilir', $r['ok'] && $r['iptal_idler'] === [(int)$hSk['id']] && kasa_hareket_oku($db, (int)$hSk['id'])['durum'] === 'iptal');
$DEPOLAR = null;
// kod / tip / para birimi düzenlemede korunur
$kd = kasa_kart_kaydet($db, ['ad' => 'Kod Koru', 'kod' => 'KORU-1', 'tip' => 'banka', 'para_birimi' => 'USD', 'banka_adi' => 'Örnek'], 1);
$ku = kasa_kart_kaydet($db, ['ad' => 'Kod Koru 2'], 1, (int)$kd['id']); $ko = kasa_kart_oku($db, (int)$kd['id']);
ok('düzenleme: kod boş/yok → ESKİ kod korunur (yeni KS-nnn üretilmez); tip + para birimi gelmezse eski değer korunur', $kd['ok'] && $ku['ok'] && $ko['kod'] === 'KORU-1' && $ko['tip'] === 'banka' && $ko['para_birimi'] === 'USD' && $ko['ad'] === 'Kod Koru 2', json_encode([$ku, $ko], JSON_UNESCAPED_UNICODE));
$ku = kasa_kart_kaydet($db, ['ad' => 'Kod Koru 3', 'kod' => '', 'tip' => '', 'para_birimi' => ''], 1, (int)$kd['id']); $ko = kasa_kart_oku($db, (int)$kd['id']);
ok('düzenleme: kod / tip / para birimi BOŞ metin gelse de korunur', $ku['ok'] && $ko['kod'] === 'KORU-1' && $ko['tip'] === 'banka' && $ko['para_birimi'] === 'USD');
ok('yeni kartta kod boşsa hâlâ otomatik üretilir', ($ky = kasa_kart_kaydet($db, ['ad' => 'Kodsuz Yeni', 'tip' => 'nakit', 'para_birimi' => 'TRY'], 1))['ok'] && str_starts_with((string)kasa_kart_oku($db, (int)$ky['id'])['kod'], 'KS-'));
// PDO hatası: istisna SIZMAZ (PDOException catch'i RuntimeException'dan ÖNCE), tx geri alınır
$n0 = say('kasa_hareketler');
$db->exec("CREATE TRIGGER dz_t1 BEFORE INSERT ON kasa_hareketler BEGIN SELECT RAISE(ABORT, 'zorla'); END");
$f = [];
foreach (['hareket' => fn() => kasa_hareket_ekle($db, kasa_t_hareket($dB, 'acilis', '5,00', ['istek_anahtari' => 'dz-x1']), 1),
          'virman' => fn() => kasa_virman_ekle($db, ['kaynak_kasa_id' => (string)$dB, 'hedef_kasa_id' => (string)$dM, 'tarih' => gun(0), 'tutar' => '5,00', 'eksiye_izin' => '1'], 1)] as $ad => $fn) {
    try { $x = $fn(); $f[$ad] = !$x['ok'] && empty($x['tekrar']) ? 'ok' : 'beklenmeyen: ' . json_encode($x, JSON_UNESCAPED_UNICODE); } catch (Throwable $e) { $f[$ad] = 'FIRLATTI ' . get_class($e); }
}
$db->exec('DROP TRIGGER dz_t1');
ok('PDO hatasında istisna sızmaz: hareket_ekle + virman_ekle ok=false döner; hiçbir satır yazılmadı; tx açık kalmadı', $f === ['hareket' => 'ok', 'virman' => 'ok'] && say('kasa_hareketler') === $n0 && !$db->inTransaction(), json_encode($f));
$kc = file_get_contents(__DIR__ . '/../config/kasa_core.php');
foreach (['kasa_hareket_ekle', 'kasa_virman_ekle'] as $fn) {
    $g = (string)preg_match('/function ' . $fn . '\(.*?\n}\n/s', $kc, $mm) ? $mm[0] : '';
    $pp = strpos($g, 'catch (PDOException'); $rp = strpos($g, 'catch (RuntimeException'); $kp = strpos($g, 'kasa_kart_kilitle(');
    ok("$fn: catch (PDOException) catch (RuntimeException)'dan ÖNCE (yoksa PDOException ölü dalda kalır); kilit sonrası kasa_istek_bul yeniden denenir", $pp !== false && ($rp === false || $pp < $rp) && $kp !== false && strpos($g, 'kasa_istek_bul(', $kp) !== false);
}
// tanım UNIQUE ihlali → 500 değil kullanimda
$db->exec("CREATE TRIGGER dz_t2 BEFORE INSERT ON kasa_tanimlar BEGIN SELECT RAISE(ABORT, 'UNIQUE zorla'); END");
try { $x = kasa_tanim_kaydet($db, 'gelir', 'Dz Yeni Kalem', 1, 1); $tg = $x; } catch (Throwable $e) { $tg = 'FIRLATTI ' . get_class($e); }
$db->exec('DROP TRIGGER dz_t2');
ok('kasa_tanim_kaydet: DB UNIQUE/PDO hatasında istisna yok → ok=false, kod kullanimda', is_array($tg) && !$tg['ok'] && ($tg['kod'] ?? '') === 'kullanimda', json_encode($tg, JSON_UNESCAPED_UNICODE));

echo "\n=== 16. Denetim izi ===\n";
ok('audit: create / update / cancel / delete / activate-deactivate kayıtları var, hepsi module=kasa', audit_say('create') > 20 && audit_say('update') >= 3 && audit_say('cancel') >= 6 && audit_say('delete') >= 2 && (audit_say('deactivate') + audit_say('activate')) >= 4);
ok('audit: hassas alan yok (IBAN kart create kaydında olabilir ama csrf/password yok)', say('audit_log', "module = 'kasa' AND (new_values LIKE '%csrf%' OR new_values LIKE '%password%')") === 0);
ok('hiçbir kasa kaydı DELETE edilmedi: kasa_hareketler satır sayısı yalnız artar (iptaller kalır)', say('kasa_hareketler', "durum = 'iptal'") >= 8);
ok('her iptal satırında neden + kim + ne zaman dolu', say('kasa_hareketler', "durum = 'iptal' AND (iptal_neden IS NULL OR iptal_by IS NULL OR iptal_at IS NULL)") === 0);
ok('bakiye tutarlılığı: tüm kasalar için kasa_bakiye = Σ imzalı geçerli satırlar (kuruş)', (function () use ($db) {
    foreach ($db->query('SELECT id FROM kasa_kartlar')->fetchAll(PDO::FETCH_COLUMN) as $kid) {
        $t = 0; foreach ($db->query("SELECT yon, tutar FROM kasa_hareketler WHERE kasa_id = $kid AND durum = 'gecerli'")->fetchAll(PDO::FETCH_ASSOC) as $x) $t += (int)round(((float)$x['tutar']) * 100) * ($x['yon'] === 'G' ? 1 : -1);
        if (kasa_bakiye($db, (int)$kid) !== $t) return false;
    }
    return true;
})());
ok('cari tutarlılığı: iptal edilmeyen her kasa-kaynaklı cari satırının kasa karşılığı geçerli', (function () use ($db) {
    foreach ($db->query("SELECT kaynak_id, durum FROM cari_hareketler WHERE kaynak_modul = 'kasa'")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $k = kasa_hareket_oku($db, (int)$c['kaynak_id']);
        if ($k === null || $k['durum'] !== $c['durum']) return false;
    }
    return true;
})());

siparis_test_bitir();
