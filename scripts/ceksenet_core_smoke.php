<?php
// =========================================================
// scripts/ceksenet_core_smoke.php — Çek-Senet çekirdeği (config/ceksenet_core.php) + tek yazma ucu (cek_kaydet.php) davranış testleri — Aşama 3 (arka uç)
//   php scripts/ceksenet_core_smoke.php      → çıkış kodu 0 = hepsi geçti
// Geçici dosya SQLite + stub auth; canlı DB'ye HİÇ dokunmaz. Kapsam: şema/tohum/503 · durum makinesi meta · ortalama vade · bordro (giriş/çıkış, doğrulama, mükerrer, atomiklik) ·
// her geçiş + cari/kasa etkisi (kuruşu kuruşuna) · geçersiz geçiş / toplu atomiklik · yetersiz bakiye · iptal zinciri (yalnız son hareket, tam geri dönüş, çift dekont) ·
// istek anahtarı · liste/özet/okuma · tanımlar · uç (gerçek cek_kaydet.php: CSRF, kapı, 200/422/409/403/503, klasik 303) · tutarlılık.
// =========================================================
ini_set('error_log', '/dev/null');
require_once __DIR__ . '/_ceksenet_test_lib.php';
$db = db();
$IS_ADMIN = true; $UID = 1;
function say(string $t, string $w = '1=1', array $p = []): int { return siparis_test_say(db(), $t, $w, $p); }
function audit_say(string $eylem): int { return say('audit_log', "module = 'ceksenet' AND action = ?", [$eylem]); }
function uc(array $post, bool $json = true, array $ek = []): array
{
    return ceksenet_t_istek_cagri(['dosya' => 'cek_kaydet.php', 'post' => $post + ['csrf' => 'test-csrf-belirteci'], 'server' => $json ? ['HTTP_X_REQUESTED_WITH' => 'fetch'] : []] + $ek);
}
function js(array $r): array { $j = json_decode(trim($r['govde']), true); return is_array($j) ? $j : ['_cozulemedi' => $r['govde']]; }
function baslik(array $r, string $ad): ?string { foreach ($r['basliklar'] as $b) if (stripos($b, $ad . ':') === 0) return trim(substr($b, strlen($ad) + 1)); return null; }
function gun(int $f): string { return date('Y-m-d', strtotime(($f >= 0 ? '+' : '') . $f . ' days')); }
/** cari bakiyesi (kuruş; + = cari bize borçlu) */
function cb(int $cari, string $pb = 'TRY'): int { global $db; return (int)(cari_bakiye($db, $cari)[$pb]['bakiye'] ?? 0); }
function kb(int $kasa): int { global $db; return kasa_bakiye($db, $kasa); }
/** bordro POST'u: satırlar = [['seri_no'=>..,'banka_ad'=>..,'vade'=>..,'tutar'=>..], …] */
function bordro(string $sinif, string $yon, int $cari, array $satirlar, array $ek = []): array
{
    global $db;
    $g = ['sinif' => $sinif, 'yon' => $yon, 'cari_id' => (string)$cari, 'tarih' => date('Y-m-d')] + $ek;
    foreach (['seri_no', 'banka_ad', 'sube', 'hesap_no', 'borclu', 'kefil', 'yer_adi', 'duzenleme_tarihi', 'vade', 'tutar', 'para_birimi', 'karne_id'] as $a) $g[$a] = array_map(fn($s) => (string)($s[$a] ?? ''), $satirlar);
    return ceksenet_bordro_kaydet($db, $g, 1);
}
function cek1(int $cari, string $no, string $tutar, int $vadeGun = 30, array $ek = []): array
{
    $r = bordro('cek', 'alinan', $cari, [['seri_no' => $no, 'banka_ad' => 'Akbank', 'hesap_no' => 'H-1', 'vade' => gun($vadeGun), 'tutar' => $tutar] + $ek]);
    if (!$r['ok']) throw new RuntimeException('cek1: ' . $r['hata']);
    return $r;
}
function gecis(string $islem, array $ids, array $ek = []): array
{
    global $db;
    return ceksenet_islem_uygula($db, ['gecis' => $islem, 'ids' => array_map('strval', $ids), 'tarih' => date('Y-m-d')] + $ek, 1);
}
function durum(int $id): string { return (string)ceksenet_kayit_oku(db(), $id)['durum']; }
function kart(string $ad, string $tip = 'nakit', string $pb = 'TRY', array $ek = []): int { global $db; $r = kasa_kart_kaydet($db, ['ad' => $ad, 'tip' => $tip, 'para_birimi' => $pb, 'aktif' => '1'] + $ek, 1); if (!$r['ok']) throw new RuntimeException($r['hata']); return (int)$r['id']; }

echo "=== 1. Şema / tohum / 503 kapısı ===\n";
ok('kurulum öncesi ceksenet_hazir() = false', !ceksenet_hazir($db));
ok('ceksenet_tablolar: 3 tablo, hepsi CREATE TABLE IF NOT EXISTS, FK/ALTER/ENUM/JSON/ON UPDATE/GENERATED yok', array_keys(ceksenet_tablolar()) === ['ceksenet_kayitlar', 'ceksenet_hareketler', 'ceksenet_tanimlar']
    && !array_filter(ceksenet_tablolar(), fn($d) => !str_starts_with($d, 'CREATE TABLE IF NOT EXISTS') || preg_match('/FOREIGN KEY|ALTER TABLE|ENUM\(|\bJSON\b|ON UPDATE|GENERATED/i', $d)));
$r = uc(['islem' => 'bordro_kaydet']);
ok('hiç şema yok: 503 JSON, eksikler = cari, kasa, ceksenet', $r['kod'] === 503 && js($r)['eksikler'] === ['cari', 'kasa', 'ceksenet'], $r['govde']);
ceksenet_t_kanca(); cari_migrate($db);
$r = uc(['islem' => 'bordro_kaydet']);
ok('yalnız cari var: eksikler = kasa, ceksenet', $r['kod'] === 503 && js($r)['eksikler'] === ['kasa', 'ceksenet'], $r['govde']);
kasa_migrate($db);
$r = uc(['islem' => 'bordro_kaydet']);
ok('cari + kasa var: eksikler = ceksenet', $r['kod'] === 503 && js($r)['eksikler'] === ['ceksenet']);
$r = uc(['islem' => 'bordro_kaydet'], false);
ok('klasik istekte 503 yerine uyarı kartı (migrate.php#ceksenet bağlantısı)', str_contains($r['govde'], 'migrate.php#ceksenet') && !str_contains($r['govde'], 'migrate.php#kasa'));
$rap = ceksenet_migrate($db);
ok('ceksenet_migrate: 3 tablo oluşturuldu', count($rap) === 3 && !array_filter($rap, fn($x) => $x['durum'] !== 'olusturuldu'), json_encode($rap));
ok('ceksenet_hazir() = true; eksik şema yok', ceksenet_hazir($db) && ceksenet_eksik_semalar($db) === []);
ok('tohum: 10 banka, karne YOK', say('ceksenet_tanimlar') === 10 && say('ceksenet_tanimlar', "tur = 'banka'") === 10 && count(ceksenet_tanim_tohumu()) === 10
    && array_column(ceksenet_tanim_listesi($db, 'banka'), 'ad') === ['Ziraat Bankası', 'Halkbank', 'VakıfBank', 'İş Bankası', 'Garanti BBVA', 'Akbank', 'Yapı Kredi', 'QNB', 'DenizBank', 'TEB']);
ok('ikinci migrate: hepsi "var", tohum çoğalmaz', !array_filter(ceksenet_migrate($db), fn($x) => $x['durum'] !== 'var') && say('ceksenet_tanimlar') === 10);
ok('hazır şemada ceksenet_sayfa_kapisi sessizce döner', (function () use ($db) { ob_start(); ceksenet_sayfa_kapisi($db); return ob_get_clean() === ''; })());
$ORN = ceksenet_t_ornek_veri($db);
$MUS = $ORN['cari']['ÖRNEK TİCARET A.Ş.']; $TED = $ORN['cari']['ÖRNEK TEDARİK LTD.']; $TED2 = $ORN['cari']['ÖRNEK NAKLİYAT LTD.'];
$KASA = $ORN['kasa']['Merkez Kasa']; $BANKA = $ORN['kasa']['Örnek Banka TL']; $DOVIZ = $ORN['kasa']['Döviz Kasası']; $BANKAUSD = $ORN['kasa']['Örnek Banka USD'];

echo "\n=== 2. Kapı / meta / durum makinesi ===\n";
ok('ceksenet_can: admin için true; bilinmeyen eylem false', ceksenet_can('read') && ceksenet_can('write') && ceksenet_can('delete') && ceksenet_can('admin') && !ceksenet_can('sil'));
$IS_ADMIN = false;
ok('yönetici değilse hepsi false; ceksenet_kapi forbidden', !ceksenet_can('read') && (function () { try { ceksenet_kapi('read'); return false; } catch (RuntimeException $e) { return str_starts_with($e->getMessage(), 'forbidden'); } })());
$IS_ADMIN = true;
ok('siniflar: cek + senet, önekler ÇA/ÇV/SA/SV', array_keys(ceksenet_siniflar()) === ['cek', 'senet'] && ceksenet_siniflar()['cek']['onek_alinan'] === 'ÇA' && ceksenet_siniflar()['senet']['onek_verilen'] === 'SV');
ok('portföy no: ÇA-/ÇV-/SA-/SV-YYYY-000123 ve bordro BRD-', ceksenet_portfoy_no('cek', 'alinan', '2026-03-01', 123) === 'ÇA-2026-000123' && ceksenet_portfoy_no('cek', 'verilen', '2026-03-01', 5) === 'ÇV-2026-000005'
    && ceksenet_portfoy_no('senet', 'alinan', '2027-01-01', 7) === 'SA-2027-000007' && ceksenet_portfoy_no('senet', 'verilen', '2026-01-01', 8) === 'SV-2026-000008' && ceksenet_bordro_no('2026-03-01', 123) === 'BRD-2026-000123');
ok('islemler: 12 işlem (9 alınan + 3 verilen), her birinde yeni durum tanımlı durumlardan', array_keys(ceksenet_islemler()) === ['giris', 'ciro', 'tahsile_ver', 'teminata_ver', 'portfoye_al', 'tahsil_kasa', 'tahsil_banka', 'karsiliksiz', 'iade', 'cikis', 'odendi', 'iade_alindi']
    && !array_filter(ceksenet_islemler(), fn($t) => !isset(ceksenet_durumlar()[$t['yeni']])));
$izin = fn(string $yon, string $d) => ceksenet_izinli_islemler(['yon' => $yon, 'durum' => $d]);
ok('izinli: alınan portföy → ciro, tahsile_ver, teminata_ver, tahsil_kasa, karsiliksiz, iade', $izin('alinan', 'portfoy') === ['ciro', 'tahsile_ver', 'teminata_ver', 'tahsil_kasa', 'karsiliksiz', 'iade']);
ok('izinli: tahsilde → portfoye_al, tahsil_banka, karsiliksiz; teminatta → portfoye_al; ciro → karsiliksiz; karsiliksiz → iade', $izin('alinan', 'tahsilde') === ['portfoye_al', 'tahsil_banka', 'karsiliksiz'] && $izin('alinan', 'teminatta') === ['portfoye_al']
    && $izin('alinan', 'ciro') === ['karsiliksiz'] && $izin('alinan', 'karsiliksiz') === ['iade']);
ok('izinli: verildi → odendi, iade_alindi; tahsil / iade / odendi / iptal → boş; verilen kayıtta alınan işlem yok', $izin('verilen', 'verildi') === ['odendi', 'iade_alindi'] && !$izin('alinan', 'tahsil') && !$izin('alinan', 'iade') && !$izin('verilen', 'odendi') && !$izin('alinan', 'iptal') && !$izin('verilen', 'portfoy'));
ok('senette karşılıksız = "Protesto" (durum + işlem etiketi)', ceksenet_durum_etiketi('karsiliksiz', 'senet') === 'Protesto' && ceksenet_durum_etiketi('karsiliksiz', 'cek') === 'Karşılıksız' && ceksenet_islem_etiketi('karsiliksiz', 'senet') === 'Protesto' && ceksenet_islem_etiketi('ciro', 'senet') === 'Ciro Et');
ok('açık durumlar: portfoy, tahsilde, teminatta, verildi', ceksenet_acik_durumlar() === ['portfoy', 'tahsilde', 'teminatta', 'verildi']);
foreach (['cek_dekontu.php' => 'cek_dekontu.php', 'senet_dekontu.php?yon=verilen&id=3' => 'senet_dekontu.php?yon=verilen&id=3', 'cek_tanimlari.php?sekme=karne' => 'cek_tanimlari.php?sekme=karne', 'kasa_fisleri.php' => 'cek_dekontu.php',
    'https://ornek.test/cek_dekontu.php' => 'cek_dekontu.php', "cek_dekontu.php\r\nX: 1" => 'cek_dekontu.php', 'cek_cikti.php' => 'cek_dekontu.php', '../cek_dekontu.php' => 'cek_dekontu.php'] as $g => $bek) {
    ok('ceksenet_geri_guvenli(' . json_encode($g) . ") = $bek", ceksenet_geri_guvenli($g) === $bek);
}
ok('geri(dizi) = varsayılan; para_db 123456 → "1234.56"', ceksenet_geri_guvenli(['x']) === 'cek_dekontu.php' && ceksenet_para_db(123456) === '1234.56' && ceksenet_para_db(5) === '0.05');
ok('vade_coz: geniş aralık (5 yıl ileri geçerli; 2000 öncesi / 11 yıl sonrası null), d.m.Y', ceksenet_vade_coz(gun(1800)) !== null && ceksenet_vade_coz('1999-12-31') === null && ceksenet_vade_coz(gun(4000)) === null && ceksenet_vade_coz('15.01.2027') === '2027-01-15' && ceksenet_vade_coz('2027-02-30') === null);

echo "\n=== 3. Ortalama vade (saf formül) ===\n";
$ov = ceksenet_ortalama_vade([['vade' => '2026-02-01', 'kurus' => 100000, 'para_birimi' => 'TRY'], ['vade' => '2026-03-02', 'kurus' => 300000, 'para_birimi' => 'TRY']], '2026-01-01');
ok('ağırlıklı: (1000×31 + 3000×60) / 4000 = 52,75 → 53 gün; tarih = baz + 53', $ov !== null && $ov['TRY']['gun'] === 53 && $ov['TRY']['tarih'] === '2026-02-23' && $ov['TRY']['toplam_kurus'] === 400000 && $ov['TRY']['adet'] === 2, json_encode($ov));
$ov = ceksenet_ortalama_vade([['vade' => '2026-01-11', 'tutar' => '100.00'], ['vade' => '2026-01-12', 'tutar' => '100.00']], '2026-01-01');
ok('yarım gün (10,5) yukarı yuvarlanır → 11; tutar metninden kuruş', $ov['TRY']['gun'] === 11 && $ov['TRY']['tarih'] === '2026-01-12');
$ov = ceksenet_ortalama_vade([['vade' => '2025-12-22', 'kurus' => 100], ['vade' => '2025-12-21', 'kurus' => 100]], '2026-01-01');
ok('negatif (vadesi geçmiş): -10,5 → -11 (sıfırdan uzağa)', $ov['TRY']['gun'] === -11 && $ov['TRY']['tarih'] === '2025-12-21');
$ov = ceksenet_ortalama_vade([['vade' => '2026-01-31', 'kurus' => 5000, 'para_birimi' => 'USD'], ['vade' => '2026-01-11', 'kurus' => 1000, 'para_birimi' => 'TRY']], '2026-01-01');
ok('para birimi başına AYRI (TRY, USD sırası); karışmaz', array_keys($ov) === ['TRY', 'USD'] && $ov['TRY']['gun'] === 10 && $ov['USD']['gun'] === 30);
ok('tek satır = kendi vadesi; boş / geçersiz baz / geçersiz vade → null', ceksenet_ortalama_vade([['vade' => '2026-02-01', 'kurus' => 7]], '2026-01-01')['TRY']['gun'] === 31 && ceksenet_ortalama_vade([], '2026-01-01') === null
    && ceksenet_ortalama_vade([['vade' => '2026-02-01', 'kurus' => 7]], 'x') === null && ceksenet_ortalama_vade([['vade' => 'bozuk', 'kurus' => 7]], '2026-01-01') === null);
ok('çok büyük tutar × gün taşmaz (999.999.999.999,99 × 3650 gün)', ceksenet_ortalama_vade([['vade' => gun(3650), 'kurus' => 99999999999999]], date('Y-m-d'))['TRY']['gun'] === 3650);

$osv = []; for ($i = 0; $i < 30; $i++) $osv[] = ['vade' => gun(3650), 'kurus' => 99999999999999, 'para_birimi' => 'TRY'];
try { $ov = ceksenet_ortalama_vade($osv, date('Y-m-d')); $ovh = null; } catch (Throwable $e) { $ov = null; $ovh = get_class($e) . ': ' . $e->getMessage(); }
ok('ortalama vade: Σ(kuruş×gün) int sınırını aşsa da istisna YOK (TypeError yok); gün 3650, adet 30', $ovh === null && ($ov['TRY']['gun'] ?? null) === 3650 && ($ov['TRY']['adet'] ?? null) === 30 && $ov['TRY']['tarih'] === gun(3650), (string)$ovh . json_encode($ov));
$ov2 = ceksenet_ortalama_vade([['vade' => gun(-4000), 'kurus' => 99999999999999, 'para_birimi' => 'TRY'], ['vade' => gun(-4000), 'kurus' => 99999999999999, 'para_birimi' => 'TRY'], ['vade' => gun(-4000), 'kurus' => 99999999999999, 'para_birimi' => 'TRY']], date('Y-m-d'));
ok('ortalama vade: büyük tutar + negatif gün (vade geçmiş) de istisnasız ve doğru işaretli (-4000)', ($ov2['TRY']['gun'] ?? null) === -4000);

echo "\n=== 4. Bordro: alınan çek girişi ===\n";
$a0 = audit_say('create');
$r = bordro('cek', 'alinan', $MUS, [
    ['seri_no' => '100001', 'banka_ad' => 'Akbank', 'sube' => 'Merkez', 'hesap_no' => 'H-1', 'borclu' => 'ÖRNEK KEŞİDECİ', 'yer_adi' => 'ANKARA', 'vade' => gun(31), 'tutar' => '1.000,00'],
    ['seri_no' => '100002', 'banka_ad' => 'Akbank', 'hesap_no' => 'H-1', 'vade' => gun(60), 'tutar' => '3.000,50'],
], ['aciklama' => 'Deneme bordrosu']);
ok('giriş bordrosu: ok, 2 kayıt, bordro_no BRD-yıl-id, tekrar=false', $r['ok'] && count($r['idler']) === 2 && preg_match('/^BRD-\d{4}-\d{6}$/', $r['bordro_no']) === 1 && $r['tekrar'] === false, json_encode($r, JSON_UNESCAPED_UNICODE));
[$c1, $c2] = $r['idler'];
$k1 = ceksenet_kayit_oku($db, $c1);
ok('kayıt: portfoy durumu, ÇA-yıl-id, yön alinan, pb TRY, tutar 1000.00, depo = aktif depo, bordro_no yazıldı, yer boş', $k1['durum'] === 'portfoy' && $k1['portfoy_no'] === 'ÇA-' . date('Y') . '-' . str_pad((string)$c1, 6, '0', STR_PAD_LEFT)
    && $k1['yon'] === 'alinan' && $k1['para_birimi'] === 'TRY' && (float)$k1['tutar'] === 1000.0 && $k1['depo'] === 'Merkez Depo' && $k1['bordro_no'] === $r['bordro_no'] && $k1['yer_cari_id'] === null && $k1['yer_kasa_id'] === null);
ok('hareket: giris, onceki NULL → portfoy, belge CSH-, bordro ortak, cari dekont bağlı', say('ceksenet_hareketler', "kayit_id = $c1 AND islem = 'giris' AND onceki_durum IS NULL AND yeni_durum = 'portfoy' AND belge_no LIKE 'CSH-%' AND bordro_no = ? AND cari_hareket_id IS NOT NULL", [$r['bordro_no']]) === 1);
ok('cari: kayıt carisine ALACAK dekontu, bakiye -4.000,50 (kuruşu kuruşuna), kaynak ceksenet', cb($MUS) === -400050 && say('cari_hareketler', "kaynak_modul = 'ceksenet' AND tur = 'alacak_dekont' AND yon = 'A' AND cari_id = $MUS") === 2);
$ch = $db->query("SELECT aciklama, evrak_no FROM cari_hareketler WHERE kaynak_modul = 'ceksenet' ORDER BY id LIMIT 1")->fetch();
ok('dekont açıklaması: "Çek girişi ÇA-… · Vade dd.mm.yyyy · Akbank 100001"; evrak_no = seri no', str_contains($ch['aciklama'], 'Çek girişi ÇA-') && str_contains($ch['aciklama'], 'Vade ' . date('d.m.Y', strtotime(gun(31)))) && str_contains($ch['aciklama'], 'Akbank 100001') && $ch['evrak_no'] === '100001', json_encode($ch, JSON_UNESCAPED_UNICODE));
ok('kasa etkisi YOK; toplam 400050 TRY ×2; ortalama vade (1000×31 + 3000,50×60)/4000,50 → 53', say('kasa_hareketler') === 0 && $r['toplamlar']['TRY'] === ['kurus' => 400050, 'adet' => 2] && $r['ortalama_vade']['TRY']['gun'] === 53, json_encode($r['ortalama_vade']));
ok('audit create +1 (bordro)', audit_say('create') === $a0 + 1);
$r2 = bordro('cek', 'alinan', $MUS, [['seri_no' => '100001', 'banka_ad' => 'Akbank', 'hesap_no' => 'H-1', 'vade' => gun(10), 'tutar' => '5,00'], ['seri_no' => '100003', 'banka_ad' => 'Akbank', 'hesap_no' => 'H-1', 'vade' => gun(10), 'tutar' => '7,00']]);
ok('mükerrer (aynı banka + hesap + seri): kod mukerrer, satır 0 hatası, HİÇBİR satır yazılmadı (100003 dahil), cari değişmedi', !$r2['ok'] && $r2['kod'] === 'mukerrer' && isset($r2['satir_hatalari'][0]['seri_no']) && say('ceksenet_kayitlar') === 2 && cb($MUS) === -400050, json_encode($r2, JSON_UNESCAPED_UNICODE));
ok('farklı banka aynı seri mükerrer DEĞİL', bordro('cek', 'alinan', $MUS, [['seri_no' => '100001', 'banka_ad' => 'Halkbank', 'hesap_no' => 'H-1', 'vade' => gun(10), 'tutar' => '5,00']])['ok']);
$r2 = bordro('cek', 'alinan', $MUS, [['seri_no' => '777', 'banka_ad' => 'Akbank', 'hesap_no' => 'H-9', 'vade' => gun(10), 'tutar' => '5,00'], ['seri_no' => '777', 'banka_ad' => 'akbank', 'hesap_no' => 'h-9', 'vade' => gun(11), 'tutar' => '6,00']]);
ok('aynı bordroda tekrar eden numara (büyük/küçük harf duyarsız) mükerrer', !$r2['ok'] && $r2['kod'] === 'mukerrer' && isset($r2['satir_hatalari'][1]['seri_no']));
$n0 = say('ceksenet_kayitlar'); $cn0 = say('cari_hareketler');
$hata = [
    'cari yok' => [['cari_id' => '0'], 'cari_id'], 'cari olmayan' => [['cari_id' => '99999'], 'cari_id'], 'tarih bozuk' => [['tarih' => '2026-13-40'], 'tarih'], 'sınıf bozuk' => [['sinif' => 'x'], 'sinif'], 'yön bozuk' => [['yon' => 'x'], 'yon'],
    'açıklama 501' => [['aciklama' => str_repeat('a', 501)], 'aciklama'], 'istek anahtarı bozuk' => [['istek_anahtari' => 'a b!'], 'istek_anahtari'],
];
foreach ($hata as $ad => [$ek, $alan]) { $g = $ek + ['sinif' => 'cek', 'yon' => 'alinan', 'cari_id' => (string)$MUS, 'tarih' => gun(0), 'seri_no' => ['9'], 'banka_ad' => ['Akbank'], 'vade' => [gun(5)], 'tutar' => ['1']]; $r = ceksenet_bordro_kaydet($db, $g, 1);
    ok("başlık doğrulama: $ad → alanlar[$alan], yazılmadı", !$r['ok'] && isset($r['alanlar'][$alan]) && say('ceksenet_kayitlar') === $n0, json_encode($r, JSON_UNESCAPED_UNICODE)); }
$sat = [
    'seri no yok' => [['banka_ad' => 'A', 'vade' => gun(5), 'tutar' => '1'], 'seri_no'], 'tutar 0' => [['seri_no' => '5', 'banka_ad' => 'A', 'vade' => gun(5), 'tutar' => '0'], 'tutar'],
    'tutar 4 ondalık' => [['seri_no' => '5', 'banka_ad' => 'A', 'vade' => gun(5), 'tutar' => '1,2345'], 'tutar'], 'vade yok' => [['seri_no' => '5', 'banka_ad' => 'A', 'tutar' => '1'], 'vade'],
    'vade bozuk' => [['seri_no' => '5', 'banka_ad' => 'A', 'vade' => '31.02.2027', 'tutar' => '1'], 'vade'], 'banka yok (çek)' => [['seri_no' => '5', 'vade' => gun(5), 'tutar' => '1'], 'banka_ad'],
    'pb geçersiz' => [['seri_no' => '5', 'banka_ad' => 'A', 'vade' => gun(5), 'tutar' => '1', 'para_birimi' => 'GBP'], 'para_birimi'],
    'düzenleme > vade' => [['seri_no' => '5', 'banka_ad' => 'A', 'vade' => gun(5), 'tutar' => '1', 'duzenleme_tarihi' => gun(9)], 'duzenleme_tarihi'], 'seri 41 karakter' => [['seri_no' => str_repeat('1', 41), 'banka_ad' => 'A', 'vade' => gun(5), 'tutar' => '1'], 'seri_no'],
];
foreach ($sat as $ad => [$s, $alan]) { $nb = say('ceksenet_kayitlar'); $r = bordro('cek', 'alinan', $MUS, [$s]); ok("satır doğrulama: $ad → satir_hatalari[0][$alan], yazılmadı", !$r['ok'] && isset($r['satir_hatalari'][0][$alan]) && say('ceksenet_kayitlar') === $nb, json_encode($r, JSON_UNESCAPED_UNICODE)); }
$r = bordro('cek', 'alinan', $MUS, [['seri_no' => '', 'vade' => ''], ['seri_no' => '8001', 'banka_ad' => 'A', 'vade' => gun(5), 'tutar' => '1,00']]);
ok('boş satır atılır (indeks korunur): tek kayıt yazıldı', $r['ok'] && count($r['idler']) === 1);
$r = bordro('cek', 'alinan', $MUS, []);
ok('satırsız bordro → alanlar[satir]', !$r['ok'] && isset($r['alanlar']['satir']));
$rows = []; for ($i = 0; $i < 31; $i++) $rows[] = ['seri_no' => (string)(5000 + $i), 'banka_ad' => 'Akbank', 'vade' => gun(5), 'tutar' => '1,00'];
$n1 = say('ceksenet_kayitlar'); $r = bordro('cek', 'alinan', $MUS, $rows);
ok('31 satır → reddedilir (CEKSENET_SATIR_MAX = 30), yazılmadı', CEKSENET_SATIR_MAX === 30 && !$r['ok'] && isset($r['alanlar']['satir']) && say('ceksenet_kayitlar') === $n1);
$r = bordro('cek', 'alinan', $MUS, array_slice($rows, 0, 30));
ok('30 satır → kabul; 30 dekont', $r['ok'] && count($r['idler']) === 30);
// pasif cari → rollback
$pasif = (int)cari_kart_kaydet($db, ['unvan' => 'PASİF CARİ', 'tip' => 'musteri', 'para_birimi' => 'TRY', 'aktif' => '1'], 1)['id'];
$n1 = say('ceksenet_kayitlar');
cari_kart_durum($db, $pasif, false, 1);
$r = bordro('cek', 'alinan', $pasif, [['seri_no' => '6001', 'banka_ad' => 'A', 'vade' => gun(5), 'tutar' => '1,00']]);
ok('pasif cariye bordro reddedilir (alanlar[cari_id]); yazılmadı', !$r['ok'] && isset($r['alanlar']['cari_id']) && say('ceksenet_kayitlar') === $n1);
// istek anahtarı
$rt = bordro('cek', 'alinan', $MUS, [['seri_no' => '9101', 'banka_ad' => 'Akbank', 'vade' => gun(5), 'tutar' => '2,00']], ['istek_anahtari' => 'brd-1']);
$say0 = say('ceksenet_kayitlar'); $cb0 = cb($MUS);
$rt2 = bordro('cek', 'alinan', $MUS, [['seri_no' => '9101', 'banka_ad' => 'Akbank', 'vade' => gun(5), 'tutar' => '2,00']], ['istek_anahtari' => 'brd-1']);
ok('aynı istek anahtarı: tekrar=true, aynı bordro/id, yeni kayıt / dekont YOK', $rt['ok'] && $rt2['ok'] && $rt2['tekrar'] === true && $rt2['bordro_no'] === $rt['bordro_no'] && $rt2['idler'] === $rt['idler'] && say('ceksenet_kayitlar') === $say0 && cb($MUS) === $cb0);

echo "\n=== 5. Senet bordrosu ===\n";
$r = bordro('senet', 'alinan', $MUS, [['seri_no' => 'S-1', 'banka_ad' => 'OLMAMALI', 'hesap_no' => 'X', 'borclu' => 'ÖRNEK BORÇLU', 'kefil' => 'ÖRNEK KEFİL', 'yer_adi' => 'İZMİR', 'duzenleme_tarihi' => gun(-5), 'vade' => gun(90), 'tutar' => '500,00', 'para_birimi' => 'USD']]);
$sk = $r['ok'] ? ceksenet_kayit_oku($db, $r['idler'][0]) : [];
ok('senet: SA- no, banka/hesap alanları tutulmaz, kefil + borçlu + ödeme yeri + düzenleme tarihi tutulur', $r['ok'] && str_starts_with($sk['portfoy_no'], 'SA-') && $sk['banka_ad'] === null && $sk['hesap_no'] === null && $sk['kefil'] === 'ÖRNEK KEFİL' && $sk['borclu'] === 'ÖRNEK BORÇLU' && $sk['yer_adi'] === 'İZMİR' && $sk['duzenleme_tarihi'] === gun(-5), json_encode($r, JSON_UNESCAPED_UNICODE));
ok('senet USD: cari USD alacak -500,00 (TRY etkilenmez)', cb($MUS, 'USD') === -50000);
$sid = $r['idler'][0];
$r = bordro('senet', 'alinan', $MUS, [['seri_no' => 'S-1', 'borclu' => 'X', 'vade' => gun(90), 'tutar' => '1,00']]);
ok('senette numara mükerrer kontrolü YOK (aynı no ikinci kez girilebilir)', $r['ok']);
$r = bordro('senet', 'alinan', $MUS, [['seri_no' => 'S-2', 'vade' => gun(10), 'tutar' => '1,00']]);
ok('senet: borçlu / banka zorunlu DEĞİL', $r['ok']);

echo "\n=== 6. Alınan evrak geçişleri: cari + kasa etkisi (kuruşu kuruşuna) ===\n";
// ciro
$x = cek1($MUS, '201', '1.000,00'); [$id] = $x['idler']; $m0 = cb($MUS); $t0 = cb($TED);
$r = gecis('ciro', [$id], ['cari_id' => (string)$TED]);
$k = ceksenet_kayit_oku($db, $id);
ok('ciro: portfoy → ciro, yer_cari_id = hedef; hedef cariye BORÇ -1.000,00 → bakiye +1.000,00; kayıt carisi değişmez', $r['ok'] && $k['durum'] === 'ciro' && (int)$k['yer_cari_id'] === $TED && cb($TED) === $t0 + 100000 && cb($MUS) === $m0 && say('kasa_hareketler') === 0, json_encode($r, JSON_UNESCAPED_UNICODE));
ok('ciro hareketi: onceki portfoy, cari_id = hedef cari, bordro_no dolu, 1 cari dekontu (borc_dekont)', say('ceksenet_hareketler', "kayit_id = $id AND islem = 'ciro' AND onceki_durum = 'portfoy' AND cari_id = $TED AND bordro_no <> '' AND cari_hareket_id IS NOT NULL AND cari_hareket2_id IS NULL") === 1
    && say('cari_hareketler', "kaynak_modul = 'ceksenet' AND kaynak_id = " . $r['sonuclar'][0]['hareket_id'] . " AND tur = 'borc_dekont' AND cari_id = $TED") === 1);
$r = gecis('ciro', [$id], ['cari_id' => (string)$TED]);
ok('ciro edilmiş evrak tekrar ciro: 409 gecersiz_gecis, kayitlar[0] neden + id', !$r['ok'] && $r['kod'] === 'gecersiz_gecis' && $r['kayitlar'][0]['id'] === $id && str_contains($r['kayitlar'][0]['neden'], 'yapılamaz'));
$r = gecis('ciro', [cek1($MUS, '202', '1,00')['idler'][0]]);
ok('ciro: hedef cari zorunlu (alanlar[cari_id])', !$r['ok'] && isset($r['alanlar']['cari_id']));
$pid = (int)cari_kart_kaydet($db, ['unvan' => 'PASİF HEDEF', 'tip' => 'tedarikci', 'para_birimi' => 'TRY', 'aktif' => '1'], 1)['id']; cari_kart_durum($db, $pid, false, 1);
$e1 = cek1($MUS, '203', '1,00')['idler'][0];
$r = gecis('ciro', [$e1], ['cari_id' => (string)$pid]);
ok('ciro: pasif hedef cari reddedilir; durum portfoy kalır', !$r['ok'] && isset($r['alanlar']['cari_id']) && durum($e1) === 'portfoy');
// tahsile ver / tahsil banka
$x = cek1($MUS, '211', '2.500,25'); [$id] = $x['idler']; $m0 = cb($MUS); $b0 = kb($BANKA);
$r = gecis('tahsile_ver', [$id], ['kasa_id' => (string)$BANKA]);
$k = ceksenet_kayit_oku($db, $id);
ok('tahsile_ver: portfoy → tahsilde, yer_kasa_id = banka; cari ve kasa bakiyesi DEĞİŞMEZ, dekont / kasa hareketi yok', $r['ok'] && $k['durum'] === 'tahsilde' && (int)$k['yer_kasa_id'] === $BANKA && cb($MUS) === $m0 && kb($BANKA) === $b0
    && $r['sonuclar'][0]['cari_hareket_id'] === null && $r['sonuclar'][0]['kasa_hareket_id'] === null);
$id2 = cek1($MUS, '212', '1,00')['idler'][0];
$r = gecis('tahsile_ver', [$id2], ['kasa_id' => (string)$KASA]);
ok('tahsile_ver: nakit kasa reddedilir (yalnız banka tipi); durum aynı', !$r['ok'] && isset($r['alanlar']['kasa_id']) && durum($id2) === 'portfoy');
$r = gecis('tahsile_ver', [$id2]);
ok('tahsile_ver: kasa zorunlu', !$r['ok'] && isset($r['alanlar']['kasa_id']));
$r = gecis('tahsile_ver', [$id2], ['kasa_id' => (string)$BANKAUSD]);
ok('tahsile_ver: para birimi uyumsuz banka (USD) reddedilir', !$r['ok'] && str_contains($r['hata'], 'para birimi') && durum($id2) === 'portfoy');
$m0 = cb($MUS); $r = gecis('tahsil_banka', [$id]);
ok('tahsil_banka: tahsilat bankasına KAYNAK GİRİŞİ +2.500,25 (kasa bakiyesi kuruşu kuruşuna), durum tahsil, cari etkisi yok', $r['ok'] && durum($id) === 'tahsil' && kb($BANKA) === $b0 + 250025 && cb($MUS) === $m0 && $r['sonuclar'][0]['kasa_hareket_id'] !== null);
$kh = kasa_hareket_oku($db, (int)$r['sonuclar'][0]['kasa_hareket_id']);
ok('kasa hareketi: tur kaynak_giris, G, KKG-, kaynak_modul ceksenet + kaynak_id = çek-senet HAREKET id, cari bağı yok', $kh['tur'] === 'kaynak_giris' && $kh['yon'] === 'G' && str_starts_with($kh['belge_no'], 'KKG-') && $kh['kaynak_modul'] === 'ceksenet' && (int)$kh['kaynak_id'] === (int)$r['sonuclar'][0]['hareket_id'] && $kh['cari_id'] === null && $kh['para_birimi'] === 'TRY');
// teminat / portföye al
$id3 = cek1($MUS, '221', '10,00')['idler'][0];
$r = gecis('teminata_ver', [$id3]);
ok('teminata_ver: kasa isteğe bağlı (kasasız): portfoy → teminatta, yer_kasa NULL', $r['ok'] && durum($id3) === 'teminatta' && ceksenet_kayit_oku($db, $id3)['yer_kasa_id'] === null);
$r = gecis('portfoye_al', [$id3]);
ok('portfoye_al (teminatta → portfoy): yer temizlenir', $r['ok'] && durum($id3) === 'portfoy');
$r = gecis('teminata_ver', [$id3], ['kasa_id' => (string)$BANKA]); $r2 = gecis('portfoye_al', [$id3]);
ok('teminata_ver (bankalı) + portfoye_al: yer_kasa_id önce banka, sonra NULL; para hareketi yok', $r['ok'] && $r2['ok'] && ceksenet_kayit_oku($db, $id3)['yer_kasa_id'] === null && kb($BANKA) === $b0 + 250025);
$r = gecis('teminata_ver', [$id3], ['kasa_id' => (string)$KASA]);
ok('teminata_ver: nakit kasa seçilirse reddedilir', !$r['ok']);
$r = gecis('tahsile_ver', [$id3], ['kasa_id' => (string)$BANKA]); $r2 = gecis('portfoye_al', [$id3]);
ok('tahsilde → portfoye_al da çalışır', $r['ok'] && $r2['ok'] && durum($id3) === 'portfoy');
// tahsil kasa
$id4 = cek1($MUS, '231', '300,00')['idler'][0]; $k0 = kb($KASA);
$r = gecis('tahsil_kasa', [$id4], ['kasa_id' => (string)$KASA]);
ok('tahsil_kasa: nakit kasaya +300,00, durum tahsil, yer_kasa_id = kasa', $r['ok'] && durum($id4) === 'tahsil' && kb($KASA) === $k0 + 30000 && (int)ceksenet_kayit_oku($db, $id4)['yer_kasa_id'] === $KASA);
$id5 = cek1($MUS, '232', '300,00')['idler'][0];
$r = gecis('tahsil_kasa', [$id5], ['kasa_id' => (string)$DOVIZ]);
ok('tahsil_kasa: para birimi uyumsuz kasa (USD) reddedilir; kasa / durum değişmez', !$r['ok'] && str_contains($r['hata'], 'para birimi') && durum($id5) === 'portfoy' && kb($DOVIZ) === 0);
$r = gecis('tahsil_kasa', [$id5]);
ok('tahsil_kasa: kasa zorunlu; tahsilden tahsil_kasa yapılamaz', !$r['ok'] && isset($r['alanlar']['kasa_id']) && !gecis('tahsil_kasa', [$id4], ['kasa_id' => (string)$KASA])['ok']);
$r = gecis('tahsil_banka', [$id5]);
ok('tahsil_banka portföydeki evraka yapılamaz (yalnız tahsilde)', !$r['ok'] && $r['kod'] === 'gecersiz_gecis');
// karşılıksız
$x = cek1($MUS, '241', '1.000,00'); [$id] = $x['idler']; $m0 = cb($MUS);
$r = gecis('karsiliksiz', [$id]);
ok('karşılıksız (portföyden): kayıt carisine BORÇ +1.000,00 (geri borç), tek dekont, durum karsiliksiz', $r['ok'] && durum($id) === 'karsiliksiz' && cb($MUS) === $m0 + 100000 && $r['sonuclar'][0]['cari_hareket2_id'] === null);
$r = gecis('iade', [$id]);
ok('karşılıksızdan iade: cari etkisi YOK, durum iade', $r['ok'] && durum($id) === 'iade' && cb($MUS) === $m0 + 100000 && $r['sonuclar'][0]['cari_hareket_id'] === null);
$x = cek1($MUS, '242', '400,00'); [$id] = $x['idler']; $m0 = cb($MUS); $b0 = kb($BANKA);
gecis('tahsile_ver', [$id], ['kasa_id' => (string)$BANKA]);
$r = gecis('karsiliksiz', [$id]);
ok('tahsildeyken karşılıksız: kayıt carisine borç +400,00; banka bakiyesi değişmez', $r['ok'] && durum($id) === 'karsiliksiz' && cb($MUS) === $m0 + 40000 && kb($BANKA) === $b0);
$x = cek1($MUS, '243', '600,00'); [$id] = $x['idler']; $m0 = cb($MUS); $t0 = cb($TED);
gecis('ciro', [$id], ['cari_id' => (string)$TED]);
ok('ciro sonrası bakiyeler: tedarikçi +600,00 (borç)', cb($TED) === $t0 + 60000);
$r = gecis('karsiliksiz', [$id]);
ok('ciro edilmişken karşılıksız: kayıt carisine BORÇ +600,00 VE ciro carisine ALACAK -600,00 (iki dekont: cari_hareket_id + cari_hareket2_id)', $r['ok'] && cb($MUS) === $m0 + 60000 && cb($TED) === $t0 && $r['sonuclar'][0]['cari_hareket_id'] !== null && $r['sonuclar'][0]['cari_hareket2_id'] !== null, json_encode($r['sonuclar'] ?? $r, JSON_UNESCAPED_UNICODE));
$hid = $r['sonuclar'][0]['hareket_id'];
ok('iki dekont AYNI kaynak id\'yi taşır (kaynak_id = hareket id) ve doğru yönde', say('cari_hareketler', "kaynak_modul = 'ceksenet' AND kaynak_id = $hid") === 2 && say('cari_hareketler', "kaynak_id = $hid AND cari_id = $MUS AND tur = 'borc_dekont'") === 1 && say('cari_hareketler', "kaynak_id = $hid AND cari_id = $TED AND tur = 'alacak_dekont'") === 1);
ok('karşılıksız hareketinde yer_cari bilgisi saklı: onceki_yer_cari_id = ciro carisi; kayıtta yer_cari_id korunur', say('ceksenet_hareketler', "id = $hid AND onceki_durum = 'ciro' AND onceki_yer_cari_id = $TED") === 1 && (int)ceksenet_kayit_oku($db, $id)['yer_cari_id'] === $TED);
$x = cek1($MUS, '244', '50,00'); [$id] = $x['idler']; $m0 = cb($MUS);
$r = gecis('iade', [$id]);
ok('portföyden iade: kayıt carisine BORÇ +50,00 (alacak geri alınır), durum iade', $r['ok'] && durum($id) === 'iade' && cb($MUS) === $m0 + 5000);
ok('iade edilmiş evrak üzerinde hiçbir işlem yapılamaz', !gecis('ciro', [$id], ['cari_id' => (string)$TED])['ok'] && !gecis('karsiliksiz', [$id])['ok'] && !gecis('iade', [$id])['ok']);
ok('geçersiz işlem adı / oluşturma işlemi (giris) geçiş olarak reddedilir', !gecis('uc_nokta', [$id])['ok'] && !gecis('giris', [$id])['ok'] && !gecis('', [$id])['ok'] && !gecis('cikis', [$id])['ok']);

echo "\n=== 7. Toplu işlem ve atomiklik ===\n";
$x = bordro('cek', 'alinan', $MUS, [['seri_no' => '301', 'banka_ad' => 'Akbank', 'vade' => gun(10), 'tutar' => '100,00'], ['seri_no' => '302', 'banka_ad' => 'Akbank', 'vade' => gun(20), 'tutar' => '200,00'], ['seri_no' => '303', 'banka_ad' => 'Akbank', 'vade' => gun(30), 'tutar' => '300,00']]);
[$a, $b, $c] = $x['idler']; $t0 = cb($TED);
$r = gecis('ciro', [$a, $b, $c], ['cari_id' => (string)$TED]);
ok('toplu ciro (3): hepsi ciro, tek bordro_no, 3 dekont, hedef cari +600,00', $r['ok'] && count($r['idler']) === 3 && durum($a) === 'ciro' && durum($c) === 'ciro' && cb($TED) === $t0 + 60000
    && say('ceksenet_hareketler', "bordro_no = '" . $r['bordro_no'] . "' AND islem = 'ciro'") === 3);
$x = bordro('cek', 'alinan', $MUS, [['seri_no' => '311', 'banka_ad' => 'Akbank', 'vade' => gun(10), 'tutar' => '100,00'], ['seri_no' => '312', 'banka_ad' => 'Akbank', 'vade' => gun(20), 'tutar' => '200,00']]);
[$a, $b] = $x['idler']; gecis('tahsile_ver', [$b], ['kasa_id' => (string)$BANKA]);
$cn = say('cari_hareketler'); $hn = say('ceksenet_hareketler'); $t0 = cb($TED);
$r = gecis('ciro', [$a, $b], ['cari_id' => (string)$TED]);
ok('toplu atomiklik: biri uymazsa (tahsilde olan) HİÇBİRİ yazılmaz — 409 gecersiz_gecis, kayitlar yalnız uymayanı gösterir', !$r['ok'] && $r['kod'] === 'gecersiz_gecis' && count($r['kayitlar']) === 1 && $r['kayitlar'][0]['id'] === $b && durum($a) === 'portfoy'
    && say('cari_hareketler') === $cn && say('ceksenet_hareketler') === $hn && cb($TED) === $t0);
$r = gecis('ciro', [$a, 999999], ['cari_id' => (string)$TED]);
ok('olmayan kayıt id → gecersiz_gecis ("Kayıt bulunamadı"), geçerli olan da yazılmaz', !$r['ok'] && $r['kod'] === 'gecersiz_gecis' && durum($a) === 'portfoy');
$s1 = bordro('senet', 'alinan', $MUS, [['seri_no' => 'T-1', 'vade' => gun(10), 'tutar' => '1,00']])['idler'][0];
$r = gecis('ciro', [$a, $s1], ['cari_id' => (string)$TED]);
ok('toplu: çek + senet karışık → reddedilir (aynı sınıf/yön şartı), hiçbiri yazılmaz', !$r['ok'] && $r['kod'] === 'gecersiz_gecis' && durum($a) === 'portfoy' && durum($s1) === 'portfoy');
$vc = (int)bordro('cek', 'verilen', $TED, [['seri_no' => '9901', 'banka_ad' => 'Akbank', 'vade' => gun(10), 'tutar' => '1,00']])['idler'][0];
$r = gecis('ciro', [$a, $vc], ['cari_id' => (string)$TED]);
ok('toplu: alınan + verilen karışık → reddedilir', !$r['ok'] && $r['kod'] === 'gecersiz_gecis' && durum($a) === 'portfoy' && durum($vc) === 'verildi');
$r = gecis('ciro', [], ['cari_id' => (string)$TED]);
ok('kayıt seçilmedi → alanlar[ids]; 101 kayıt → reddedilir', !$r['ok'] && isset($r['alanlar']['ids']) && !gecis('ciro', range(1, 101), ['cari_id' => (string)$TED])['ok']);
$r = ceksenet_islem_uygula($db, ['gecis' => 'ciro', 'ids' => [(string)$a], 'tarih' => 'bozuk', 'cari_id' => (string)$TED], 1);
ok('işlem tarihi bozuk → alanlar[tarih]', !$r['ok'] && isset($r['alanlar']['tarih']));
// toplu iç hata → tam geri (ikinci kayıtta kasa para birimi hatası değil; ortada cari pasifleşir)
$x = bordro('cek', 'alinan', $MUS, [['seri_no' => '321', 'banka_ad' => 'Akbank', 'vade' => gun(10), 'tutar' => '100,00'], ['seri_no' => '322', 'banka_ad' => 'Akbank', 'vade' => gun(20), 'tutar' => '200,00']]);
[$a, $b] = $x['idler']; $cn = say('cari_hareketler'); $hn = say('ceksenet_hareketler');
$GLOBALS['_ic_hata_sayac'] = 0;
cari_kart_durum($db, $MUS, false, 1);   // kayıt carisi pasif: karşılıksız dekontu (kayıt carisine) yazılamaz → iç hata → HEPSİ geri
$r = gecis('karsiliksiz', [$a, $b]);
cari_kart_durum($db, $MUS, true, 1);
ok('iç hata (cari dekontu yazılamadı) → tüm işlem geri: hareket / dekont / durum değişmedi', !$r['ok'] && str_contains($r['hata'], 'Cari hareketi yazılamadı') && say('cari_hareketler') === $cn && say('ceksenet_hareketler') === $hn && durum($a) === 'portfoy' && durum($b) === 'portfoy', json_encode($r, JSON_UNESCAPED_UNICODE));

echo "\n=== 8. Verilen çek / senet: çıkış, ödendi, iade alındı ===\n";
// karne tanımı (kendi banka hesabı: bakiye senaryosu bağımsız olsun)
$KB = kart('Karne Banka', 'banka', 'TRY', ['banka_adi' => 'Örnek Banka']);
$tk = ceksenet_tanim_kaydet($db, ['tur' => 'karne', 'ad' => 'Örnek Karne 1', 'kasa_id' => (string)$KB, 'ilk_no' => '0005001', 'yaprak' => '10'], 1);
ok('karne tanımı: kaydedildi (banka hesabı + ilk no + yaprak)', $tk['ok'], json_encode($tk, JSON_UNESCAPED_UNICODE));
$KARNE = (int)$tk['id'];
$sd = ceksenet_karne_siradaki($db, $KARNE);
ok('karne sıradaki: 0005001 (ilk_no uzunluğunda sıfır dolgulu), kalan 10, son 0005010', $sd === ['no' => '0005001', 'kalan' => 10, 'ilk_no' => '0005001', 'son_no' => '0005010'], json_encode($sd));
$m0 = cb($TED);
$r = bordro('cek', 'verilen', $TED, [['seri_no' => '0005001', 'karne_id' => (string)$KARNE, 'vade' => gun(45), 'tutar' => '750,00']]);
$vk = $r['ok'] ? ceksenet_kayit_oku($db, $r['idler'][0]) : [];
ok('verilen çek (karneli): ÇV-, durum verildi, banka karnenin hesabından (Örnek Banka), karne_id, BORÇ dekontu: tedarikçi bakiyesi +750,00; kasa etkisi yok', $r['ok'] && str_starts_with($vk['portfoy_no'], 'ÇV-') && $vk['durum'] === 'verildi' && $vk['banka_ad'] === 'Örnek Banka' && (int)$vk['karne_id'] === $KARNE
    && cb($TED) === $m0 + 75000 && say('cari_hareketler', "kaynak_modul = 'ceksenet' AND tur = 'borc_dekont' AND cari_id = $TED AND kaynak_id IN (SELECT id FROM ceksenet_hareketler WHERE islem = 'cikis')") >= 1, json_encode($r, JSON_UNESCAPED_UNICODE));
$vid = $r['idler'][0];
ok('karne sıradaki 0005002, kalan 9, karne listesinde kullanım 1', ceksenet_karne_siradaki($db, $KARNE)['no'] === '0005002' && ceksenet_tanim_listesi($db, 'karne')[0]['kullanim'] === 1 && ceksenet_tanim_listesi($db, 'karne')[0]['kalan'] === 9);
$r = bordro('cek', 'verilen', $TED, [['seri_no' => '0005001', 'karne_id' => (string)$KARNE, 'vade' => gun(45), 'tutar' => '1,00']]);
ok('verilen çek mükerrer (aynı karne + seri no): 409 mukerrer', !$r['ok'] && $r['kod'] === 'mukerrer');
$r = bordro('cek', 'verilen', $TED, [['seri_no' => '0009999', 'karne_id' => (string)$KARNE, 'vade' => gun(45), 'tutar' => '1,00']]);
ok('karne aralığı dışı numara: KAYDEDİLİR + uyari (doğrulanmaz)', $r['ok'] && str_contains((string)$r['uyari'], 'aralığında değil'));
$r = bordro('cek', 'verilen', $TED, [['seri_no' => '0005003', 'karne_id' => (string)$KARNE, 'vade' => gun(45), 'tutar' => '1,00', 'para_birimi' => 'USD']]);
ok('karne banka hesabıyla (TRY) uyumsuz para birimi reddedilir', !$r['ok'] && isset($r['satir_hatalari'][0]['para_birimi']));
$r = bordro('cek', 'verilen', $TED, [['seri_no' => '0005003', 'karne_id' => '99999', 'vade' => gun(45), 'tutar' => '1,00']]);
ok('olmayan karne reddedilir', !$r['ok'] && isset($r['satir_hatalari'][0]['karne_id']));
$r = bordro('cek', 'verilen', $TED, [['seri_no' => '7001', 'banka_ad' => 'Halkbank', 'vade' => gun(45), 'tutar' => '1,00']]);
ok('karnesiz verilen çek de olur (banka adı zorunlu)', $r['ok'] && !bordro('cek', 'verilen', $TED, [['seri_no' => '7002', 'vade' => gun(45), 'tutar' => '1,00']])['ok']);
kasa_hareket_ekle($db, kasa_t_hareketx($KB, 'acilis', '1.000,00'), 1);
function kasa_t_hareketx(int $k, string $tur, string $tutar): array { return ['kasa_id' => (string)$k, 'tur' => $tur, 'tutar' => $tutar, 'tarih' => date('Y-m-d')]; }
$b0 = kb($KB); $m0 = cb($TED);
$r = gecis('odendi', [$vid]);
ok('ödendi (karnenin banka hesabı varsayılan): kasadan KAYNAK ÇIKIŞI -750,00 (banka 1.000 → 250), durum odendi; cari bakiye değişmez', $r['ok'] && durum($vid) === 'odendi' && kb($KB) === $b0 - 75000 && cb($TED) === $m0 && (int)ceksenet_kayit_oku($db, $vid)['yer_kasa_id'] === $KB, json_encode($r, JSON_UNESCAPED_UNICODE));
$kh = kasa_hareket_oku($db, (int)$r['sonuclar'][0]['kasa_hareket_id']);
ok('kasa hareketi kaynak_cikis (C, KKC-), kaynak ceksenet', $kh['tur'] === 'kaynak_cikis' && $kh['yon'] === 'C' && str_starts_with($kh['belge_no'], 'KKC-') && $kh['kaynak_modul'] === 'ceksenet');
$x = bordro('cek', 'verilen', $TED, [['seri_no' => '7101', 'banka_ad' => 'Halkbank', 'vade' => gun(5), 'tutar' => '900,00']]); $v2 = $x['idler'][0]; $b0 = kb($KB);
$r = gecis('odendi', [$v2], ['kasa_id' => (string)$KB]);
ok('yetersiz bakiye (250 < 900): 409 yetersiz_bakiye + bakiye + eksik; HİÇBİR şey yazılmadı (durum verildi, kasa aynı)', !$r['ok'] && $r['kod'] === 'yetersiz_bakiye' && $r['bakiye'] === 25000 && $r['eksik'] === 65000 && durum($v2) === 'verildi' && kb($KB) === $b0, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = gecis('odendi', [$v2], ['kasa_id' => (string)$KB, 'eksiye_izin' => '1']);
ok('eksiye_izin=1 ile ödendi: kayıt + uyari "eksiye düştü", banka -650,00', $r['ok'] && str_contains((string)$r['uyari'], 'eksiye düştü') && kb($KB) === -65000 && durum($v2) === 'odendi');
$r = gecis('odendi', [bordro('cek', 'verilen', $TED, [['seri_no' => '7102', 'banka_ad' => 'X', 'vade' => gun(5), 'tutar' => '1,00']])['idler'][0]]);
ok('karnesiz verilen çek ödendi: kasa zorunlu', !$r['ok'] && isset($r['alanlar']['kasa_id']));
$r = gecis('odendi', [bordro('cek', 'verilen', $TED, [['seri_no' => '7103', 'banka_ad' => 'X', 'vade' => gun(5), 'tutar' => '1,00']])['idler'][0]], ['kasa_id' => (string)$DOVIZ]);
ok('ödendi: kasa para birimi uyumsuz (USD) reddedilir', !$r['ok'] && str_contains($r['hata'], 'para birimi'));
$sv = bordro('senet', 'verilen', $TED2, [['seri_no' => 'BS-1', 'borclu' => 'KENDİ', 'vade' => gun(60), 'tutar' => '200,00']]); $sv1 = $sv['idler'][0];
ok('borç senedi çıkışı: SV-, verildi, tedarikçi-2 +200,00', $sv['ok'] && str_starts_with(ceksenet_kayit_oku($db, $sv1)['portfoy_no'], 'SV-') && cb($TED2) === 20000);
kasa_hareket_ekle($db, kasa_t_hareketx($KASA, 'acilis', '500,00'), 1); $k0 = kb($KASA);
$r = gecis('odendi', [$sv1], ['kasa_id' => (string)$KASA]);
ok('borç senedi ödendi (nakit kasa seçilir): -200,00', $r['ok'] && kb($KASA) === $k0 - 20000 && durum($sv1) === 'odendi');
$sv2 = bordro('senet', 'verilen', $TED2, [['seri_no' => 'BS-2', 'vade' => gun(60), 'tutar' => '80,00']])['idler'][0]; $m0 = cb($TED2);
$r = gecis('iade_alindi', [$sv2]);
ok('iade alındı: kayıt carisine ALACAK (bakiye -80,00), durum iade; kasa yok', $r['ok'] && durum($sv2) === 'iade' && cb($TED2) === $m0 - 8000 && $r['sonuclar'][0]['kasa_hareket_id'] === null);
ok('verilen evrakta alınan işlemleri / alınanda verilen işlemleri reddedilir', !gecis('ciro', [$sv1], ['cari_id' => (string)$TED])['ok'] && !gecis('odendi', [cek1($MUS, '8801', '1,00')['idler'][0]], ['kasa_id' => (string)$KASA])['ok']);

echo "\n=== 9. İptal: yalnız son hareket, tam geri dönüş ===\n";
$x = cek1($MUS, '401', '1.000,00'); [$id] = $x['idler']; $m0 = cb($MUS); $b0 = kb($BANKA);
$g1 = gecis('tahsile_ver', [$id], ['kasa_id' => (string)$BANKA]); $g2 = gecis('tahsil_banka', [$id]);
ok('hazırlık: giris → tahsile_ver → tahsil_banka; banka +1.000,00', durum($id) === 'tahsil' && kb($BANKA) === $b0 + 100000);
$girisHid = (int)$db->query("SELECT id FROM ceksenet_hareketler WHERE kayit_id = $id AND islem = 'giris'")->fetchColumn();
$r = ceksenet_hareket_iptal($db, $g1['sonuclar'][0]['hareket_id'], 'Deneme', 1);
ok('son olmayan hareket iptal edilemez: 409 son_degil; hiçbir şey değişmez', !$r['ok'] && $r['kod'] === 'son_degil' && durum($id) === 'tahsil' && kb($BANKA) === $b0 + 100000);
$r = ceksenet_hareket_iptal($db, $girisHid, 'Deneme', 1);
ok('giriş hareketi (sonuncu değil) iptal edilemez', !$r['ok'] && $r['kod'] === 'son_degil');
ok('iptal nedeni: 2 karakter → alanlar[neden]; 501 karakter reddedilir; olmayan hareket', !ceksenet_hareket_iptal($db, $g2['sonuclar'][0]['hareket_id'], 'ab', 1)['ok'] && !ceksenet_hareket_iptal($db, $g2['sonuclar'][0]['hareket_id'], str_repeat('a', 501), 1)['ok'] && !ceksenet_hareket_iptal($db, 999999, 'neden', 1)['ok']);
$c0 = audit_say('cancel');
$r = ceksenet_hareket_iptal($db, $g2['sonuclar'][0]['hareket_id'], 'Yanlış tahsilat', 1);
$kk = ceksenet_kayit_oku($db, $id);
ok('tahsil_banka iptali: durum tahsilde\'ye döner, yer_kasa_id = banka; kasa hareketi iptal → banka bakiyesi tam eski değer', $r['ok'] && $kk['durum'] === 'tahsilde' && (int)$kk['yer_kasa_id'] === $BANKA && kb($BANKA) === $b0 && $r['yeni_durum'] === 'tahsilde' && count($r['kasa_iptal']) === 1);
ok('iptal edilen hareket: durum iptal + neden + kim + ne zaman; audit cancel +1', say('ceksenet_hareketler', "id = " . $g2['sonuclar'][0]['hareket_id'] . " AND durum = 'iptal' AND iptal_neden = 'Yanlış tahsilat' AND iptal_by = 1 AND iptal_at IS NOT NULL") === 1 && audit_say('cancel') === $c0 + 1);
$r = ceksenet_hareket_iptal($db, $g2['sonuclar'][0]['hareket_id'], 'Tekrar', 1);
ok('aynı hareket ikinci kez: zaten_iptal', !$r['ok'] && $r['kod'] === 'zaten_iptal');
$r = ceksenet_hareket_iptal($db, $g1['sonuclar'][0]['hareket_id'], 'Tahsile vermeyi geri al', 1); $kk = ceksenet_kayit_oku($db, $id);
ok('tahsile_ver iptali (artık son): portfoy, yer_kasa NULL; kasa / cari bakiyesi etkilenmez', $r['ok'] && $kk['durum'] === 'portfoy' && $kk['yer_kasa_id'] === null && kb($BANKA) === $b0 && cb($MUS) === $m0);
$r = ceksenet_hareket_iptal($db, $girisHid, 'Hatalı giriş', 1); $kk = ceksenet_kayit_oku($db, $id);
ok('giriş iptali: kayıt durumu iptal; cari alacak dekontu iptal → cari bakiyesi tam eski değer', $r['ok'] && $kk['durum'] === 'iptal' && $r['yeni_durum'] === 'iptal' && cb($MUS) === $m0 + 100000, json_encode($r, JSON_UNESCAPED_UNICODE));
ok('iptal kayıt: izinli işlem yok; üzerinde geçiş yapılamaz; mükerrer kontrolünde sayılmaz (aynı numara yeniden girilebilir)', ceksenet_izinli_islemler($kk) === [] && !gecis('ciro', [$id], ['cari_id' => (string)$TED])['ok'] && bordro('cek', 'alinan', $MUS, [['seri_no' => '401', 'banka_ad' => 'Akbank', 'hesap_no' => 'H-1', 'vade' => gun(30), 'tutar' => '1,00']])['ok']);
// karşılıksız (ciro sonrası) iptali: iki dekont birden
$x = cek1($MUS, '411', '700,00'); [$id] = $x['idler']; $m0 = cb($MUS); $t0 = cb($TED);
gecis('ciro', [$id], ['cari_id' => (string)$TED]); $ks = gecis('karsiliksiz', [$id]);
ok('hazırlık: ciro + karşılıksız (iki dekont): MUS borç +700, TED alacak (net 0)', cb($MUS) === $m0 + 70000 && cb($TED) === $t0);
$r = ceksenet_hareket_iptal($db, $ks['sonuclar'][0]['hareket_id'], 'Karşılıksız hatalı', 1);
ok('karşılıksız (ciro sonrası) iptali: İKİ dekont birden iptal (cari_iptal 2 id), durum ciro, yer_cari_id = hedef; bakiyeler: MUS eski, TED +700,00 (ciro hâlâ geçerli)', $r['ok'] && count($r['cari_iptal']) === 2 && durum($id) === 'ciro' && (int)ceksenet_kayit_oku($db, $id)['yer_cari_id'] === $TED && cb($MUS) === $m0 && cb($TED) === $t0 + 70000);
$r = ceksenet_hareket_iptal($db, (int)$db->query("SELECT id FROM ceksenet_hareketler WHERE kayit_id = $id AND islem = 'ciro'")->fetchColumn(), 'Ciro geri', 1);
ok('ciro iptali: portfoy, yer_cari NULL; TED bakiyesi tam eski değer', $r['ok'] && durum($id) === 'portfoy' && ceksenet_kayit_oku($db, $id)['yer_cari_id'] === null && cb($TED) === $t0);
// verilen: ödendi iptali
$x = bordro('cek', 'verilen', $TED, [['seri_no' => '9201', 'banka_ad' => 'Halkbank', 'vade' => gun(9), 'tutar' => '60,00']]); [$vid] = $x['idler']; $k0 = kb($KASA); $m0 = cb($TED);
$go = gecis('odendi', [$vid], ['kasa_id' => (string)$KASA]);
$r = ceksenet_hareket_iptal($db, $go['sonuclar'][0]['hareket_id'], 'Ödeme yanlış', 1);
ok('ödendi iptali: durum verildi, yer_kasa NULL, kasa bakiyesi eski; sonra çıkış iptali: cari bakiyesi eski', $r['ok'] && durum($vid) === 'verildi' && kb($KASA) === $k0 && ceksenet_hareket_iptal($db, (int)$x['hareket_idler'][0], 'Çıkış yanlış', 1)['ok'] && durum($vid) === 'iptal' && cb($TED) === $m0 - 6000 + 0);
// tahsil iptali kasayı eksiye düşürürse uyarı
$x = cek1($MUS, '421', '100,00'); [$id] = $x['idler']; $kz = kart('Küçük Kasa'); $gt = gecis('tahsil_kasa', [$id], ['kasa_id' => (string)$kz]);
$cy = kasa_hareket_ekle($db, kasa_t_hareketx($kz, 'gider', '100,00') + ['kalem_id' => (string)(kasa_tanim_listesi($db, 'gider')[0]['id'])], 1, null);
ok('hazırlık: kasaya tahsilat girdi, sonra gider çıkışıyla boşaltıldı (gider tahsilattan sonra → 0)', $gt['ok'] && ($cy['ok'] ?? false) && kb($kz) === 0, json_encode($cy, JSON_UNESCAPED_UNICODE));
$r = ceksenet_hareket_iptal($db, $gt['sonuclar'][0]['hareket_id'], 'Tahsil yanlış', 1);
ok('tahsil iptali kasayı eksiye düşürürse yine yapılır + uyari', $r['ok'] && durum($id) === 'portfoy' && kb($kz) === -10000 && str_contains((string)$r['uyari'], 'eksiye düştü'), json_encode($r, JSON_UNESCAPED_UNICODE));

echo "\n=== 10. İstek anahtarı (geçişler) ===\n";
$x = cek1($MUS, '501', '10,00'); [$id] = $x['idler']; $m0 = cb($TED);
$r1 = gecis('ciro', [$id], ['cari_id' => (string)$TED, 'istek_anahtari' => 'gec-1']); $hn = say('ceksenet_hareketler'); $cn = say('cari_hareketler');
$r2 = gecis('ciro', [$id], ['cari_id' => (string)$TED, 'istek_anahtari' => 'gec-1']);
ok('aynı istek anahtarı: tekrar=true, aynı bordro, yeni hareket / dekont YOK, bakiye bir kez', $r1['ok'] && $r2['ok'] && $r2['tekrar'] === true && $r2['bordro_no'] === $r1['bordro_no'] && say('ceksenet_hareketler') === $hn && say('cari_hareketler') === $cn && cb($TED) === $m0 + 1000);
$x = bordro('cek', 'verilen', $TED, [['seri_no' => '9301', 'banka_ad' => 'Halkbank', 'vade' => gun(5), 'tutar' => '99999,00']]); [$vid] = $x['idler'];
$ra = gecis('odendi', [$vid], ['kasa_id' => (string)$KASA, 'istek_anahtari' => 'yet-1']);
$rb = gecis('odendi', [$vid], ['kasa_id' => (string)$KASA, 'istek_anahtari' => 'yet-1', 'eksiye_izin' => '1']);
ok('409 yetersiz_bakiye reddinde istek anahtarı HARCANMAZ: aynı anahtar + eksiye_izin=1 ile kayıt olur', !$ra['ok'] && $ra['kod'] === 'yetersiz_bakiye' && $rb['ok'] && $rb['tekrar'] === false);
ok('istek anahtarı bozuk → alanlar[istek_anahtari]', isset(gecis('ciro', [cek1($MUS, '502', '1,00')['idler'][0]], ['cari_id' => (string)$TED, 'istek_anahtari' => 'a b!'])['alanlar']['istek_anahtari']));

echo "\n=== 11. Liste / özet / okuma / bordro / cari özeti ===\n";
$ids = [];
$L = ceksenet_listesi($db, 'cek', ['yon' => 'alinan'], 1000);
ok('liste: yalnız çek sınıfı + alınan; vadeye göre artan; satırda cari unvanı, durum etiketi, izinli işlemler', $L['toplam_satir'] === count($L['satirlar']) && !array_filter($L['satirlar'], fn($s) => $s['sinif'] !== 'cek' || $s['yon'] !== 'alinan')
    && $L['satirlar'] === array_values(array_filter($L['satirlar'])) && (function () use ($L) { $v = array_column($L['satirlar'], 'vade'); $s = $v; sort($s); return $v === $s; })() && $L['satirlar'][0]['cari_unvan'] !== null && isset($L['satirlar'][0]['durum_etiket'], $L['satirlar'][0]['izinli']));
$Lo = ceksenet_listesi($db, 'cek', ['yon' => 'alinan', 'durum' => 'acik'], 1000);
ok('durum=acik: yalnız portfoy/tahsilde/teminatta; ozet iptalsiz pb bazında', !array_filter($Lo['satirlar'], fn($s) => !in_array($s['durum'], ['portfoy', 'tahsilde', 'teminatta'], true)) && isset($Lo['ozet']['TRY']['kurus']) && array_keys($Lo['ozet']) === ['TRY']);
$vx = cek1($MUS, '601', '12,34', -3); $vy = cek1($MUS, '602', '1,00', 3); $vz = cek1($MUS, '603', '1,00', 20);
$Lg = ceksenet_listesi($db, 'cek', ['yon' => 'alinan', 'donem' => 'gecmis', 'durum' => 'acik'], 1000);
ok('vade dönemi gecmis: 601 var, 602 yok; vade_gecmis=true (kırmızı), vade_gun -3', in_array($vx['idler'][0], array_column($Lg['satirlar'], 'id') ? array_map('intval', array_column($Lg['satirlar'], 'id')) : [], true) && !in_array($vy['idler'][0], array_map('intval', array_column($Lg['satirlar'], 'id')), true)
    && (function () use ($Lg, $vx) { foreach ($Lg['satirlar'] as $s) if ((int)$s['id'] === $vx['idler'][0]) return $s['vade_gecmis'] === true && $s['vade_gun'] === -3; return false; })());
$L7 = ceksenet_listesi($db, 'cek', ['yon' => 'alinan', 'donem' => '7g', 'durum' => 'acik'], 1000); $ids7 = array_map('intval', array_column($L7['satirlar'], 'id'));
ok('vade dönemi 7g: 602 (3 gün) var, 603 (20 gün) yok; vade_yakin=true', in_array($vy['idler'][0], $ids7, true) && !in_array($vz['idler'][0], $ids7, true) && (function () use ($L7, $vy) { foreach ($L7['satirlar'] as $s) if ((int)$s['id'] === $vy['idler'][0]) return $s['vade_yakin'] === true; return false; })());
$Lm = ceksenet_listesi($db, 'cek', ['yon' => 'alinan', 'donem' => 'ozel', 'bas' => gun(19), 'bit' => gun(21)], 1000);
ok('özel vade aralığı + ara (seri no / cari unvanı / bordro no)', in_array($vz['idler'][0], array_map('intval', array_column($Lm['satirlar'], 'id')), true)
    && ceksenet_listesi($db, 'cek', ['ara' => '603'])['toplam_satir'] === 1 && ceksenet_listesi($db, 'cek', ['ara' => 'TİCARET', 'yon' => 'alinan'], 5)['toplam_satir'] > 5 && ceksenet_listesi($db, 'cek', ['ara' => $vz['bordro_no']])['toplam_satir'] === 1);
ok('durum süzgeci tek durum (karsiliksiz) ve limit / offset', ceksenet_listesi($db, 'cek', ['durum' => 'karsiliksiz'], 1000)['toplam_satir'] >= 1 && count(ceksenet_listesi($db, 'cek', [], 2)['satirlar']) === 2 && ceksenet_listesi($db, 'cek', [], 2, 2)['satirlar'][0]['id'] !== ceksenet_listesi($db, 'cek', [], 2)['satirlar'][0]['id']);
ok('liste: cari süzgeci; bilinmeyen sınıf boş', ceksenet_listesi($db, 'cek', ['cari' => $TED2])['toplam_satir'] === 0 && ceksenet_listesi($db, 'x')['satirlar'] === []);
$O = ceksenet_ozet($db, 'cek', 'alinan');
$say = function (string $d, string $pb = 'TRY') use ($db) { return (int)$db->query("SELECT COUNT(*) FROM ceksenet_kayitlar WHERE sinif = 'cek' AND yon = 'alinan' AND durum = '$d' AND para_birimi = '$pb'")->fetchColumn(); };
$topl = function (string $d, string $pb = 'TRY') use ($db) { $s = 0; foreach ($db->query("SELECT tutar FROM ceksenet_kayitlar WHERE sinif = 'cek' AND yon = 'alinan' AND durum = '$d' AND para_birimi = '$pb'")->fetchAll(PDO::FETCH_COLUMN) as $t) $s += cari_kurus($t); return $s; };
ok('özet: durum × pb adet ve kuruş toplamları SQL ile birebir (portfoy / ciro / tahsil / karsiliksiz); iptal özette yok', $O['durum']['portfoy']['TRY'] === ['adet' => $say('portfoy'), 'kurus' => $topl('portfoy')] && $O['durum']['ciro']['TRY']['adet'] === $say('ciro') && $O['durum']['tahsil']['TRY']['kurus'] === $topl('tahsil')
    && $O['durum']['karsiliksiz']['TRY']['adet'] === $say('karsiliksiz') && !isset($O['durum']['iptal']));
ok('özet: vadesi geçmiş açık ≥ 1 (601: 12,34 dahil), açık toplamı = portföy + tahsilde + teminatta', $O['vadesi_gecmis']['TRY']['adet'] >= 1 && $O['vadesi_gecmis']['TRY']['kurus'] >= 1234
    && $O['acik']['TRY']['adet'] === $say('portfoy') + $say('tahsilde') + $say('teminatta'));
$Ov = ceksenet_ozet($db, 'cek', 'verilen');
ok('özet verilen: verildi + odendi; yön süzgeci işler; senet özeti ayrı', isset($Ov['durum']['verildi']) && isset($Ov['durum']['odendi']) && !isset($Ov['durum']['portfoy']) && isset(ceksenet_ozet($db, 'senet')['durum']['portfoy']['USD']) && ceksenet_ozet($db, 'x')['durum'] === []);
$S = ceksenet_secim_ozeti($db, [$vx['idler'][0], $vy['idler'][0], 999999], gun(0));
ok('seçim özeti: iptal ve olmayan atlanır; adet + toplam; ortalama vade baz bugün', $S['adet'] === 2 && $S['toplamlar']['TRY'] === ['adet' => 2, 'kurus' => 1334] && is_int($S['ortalama_vade']['TRY']['gun']));
$D = ceksenet_oku($db, $vx['idler'][0]);
ok('oku: kayıt (zenginleştirilmiş) + hareket geçmişi (giris), son=true; izinli işlemler', $D !== null && count($D['hareketler']) === 1 && $D['hareketler'][0]['son'] === true && $D['hareketler'][0]['islem_etiket'] === 'Giriş' && $D['kayit']['izinli'] === ['ciro', 'tahsile_ver', 'teminata_ver', 'tahsil_kasa', 'karsiliksiz', 'iade']
    && str_starts_with((string)$D['hareketler'][0]['cari_belge_no'], 'ADK-') && $D['hareketler'][0]['kasa_belge_no'] === null && ceksenet_oku($db, 999999) === null);
$Dk = ceksenet_oku($db, (int)$db->query("SELECT kayit_id FROM ceksenet_hareketler WHERE islem = 'karsiliksiz' AND onceki_durum = 'ciro' AND durum = 'iptal' ORDER BY id LIMIT 1")->fetchColumn());
ok('oku: iptal edilmiş hareketler geçmişte kalır (durum iptal, son=false); iki dekont no görünür', $Dk !== null && count(array_filter($Dk['hareketler'], fn($h) => $h['durum'] === 'iptal')) >= 1 && count(array_filter($Dk['hareketler'], fn($h) => $h['islem'] === 'karsiliksiz' && $h['cari_belge_no2'] !== null)) === 1);
$B = ceksenet_bordro_oku($db, $rt['bordro_no']);
ok('bordro_oku: satırlar + toplam + ortalama vade; BRD- biçimi dışı null', $B !== null && $B['satirlar'] !== [] && isset($B['toplamlar']['TRY']) && $B['islem'] === 'giris' && ceksenet_bordro_oku($db, 'x') === null && ceksenet_bordro_oku($db, 'BRD-2026-999999') === null);
$CO = ceksenet_cari_ozet($db, $MUS);
ok('cari özeti: sınıf × yön × pb açık adet/kuruş; vadesi geçmiş adet', $CO !== [] && $CO[0]['sinif'] === 'cek' && $CO[0]['yon'] === 'alinan' && $CO[0]['adet'] > 0 && $CO[0]['gecmis_adet'] >= 1);

echo "\n=== 12. Tanımlar ===\n";
$r = ceksenet_tanim_kaydet($db, ['tur' => 'banka', 'ad' => 'Örnek Banka A.Ş.', 'sube' => 'Merkez'], 1);
ok('banka ekle (şube ile)', $r['ok'] && say('ceksenet_tanimlar', "tur = 'banka'") === 11); $bId = (int)$r['id'];
ok('banka ad tekil (TR-duyarsız): "ÖRNEK BANKA A.Ş." çakışır', !ceksenet_tanim_kaydet($db, ['tur' => 'banka', 'ad' => 'ÖRNEK BANKA A.Ş.'], 1)['ok']);
ok('banka ad boş / 101 karakter / bilinmeyen tür reddedilir', !ceksenet_tanim_kaydet($db, ['tur' => 'banka', 'ad' => ''], 1)['ok'] && !ceksenet_tanim_kaydet($db, ['tur' => 'banka', 'ad' => str_repeat('a', 101)], 1)['ok'] && !ceksenet_tanim_kaydet($db, ['tur' => 'x', 'ad' => 'abc'], 1)['ok']);
ok('banka güncelle (ad + şube; tür değişmez)', ceksenet_tanim_kaydet($db, ['tur' => 'karne', 'ad' => 'Örnek Banka B', 'sube' => 'Yeni'], 1, $bId)['ok'] && ceksenet_tanim_bul($db, $bId)['tur'] === 'banka' && ceksenet_tanim_bul($db, $bId)['sube'] === 'Yeni');
$kh = [
    'kasa yok' => [['kasa_id' => ''], 'kasa_id'], 'nakit kasa seçildi' => [['kasa_id' => (string)$KASA], 'kasa_id'], 'ilk no harf' => [['ilk_no' => 'A1'], 'ilk_no'], 'yaprak 0' => [['yaprak' => '0'], 'yaprak'], 'yaprak 1001' => [['yaprak' => '1001'], 'yaprak'],
];
foreach ($kh as $ad => [$ek, $alan]) { $r = ceksenet_tanim_kaydet($db, $ek + ['tur' => 'karne', 'ad' => 'Hatalı Karne', 'kasa_id' => (string)$BANKA, 'ilk_no' => '100', 'yaprak' => '10'], 1); ok("karne doğrulama: $ad → alanlar[$alan]", !$r['ok'] && isset($r['alanlar'][$alan])); }
ok('karne silme: kullanılmışsa 409 kullanimda; kullanılmayan karne silinir', ceksenet_tanim_sil($db, $KARNE, 1)['kod'] === 'kullanimda' && ($ek = ceksenet_tanim_kaydet($db, ['tur' => 'karne', 'ad' => 'Boş Karne', 'kasa_id' => (string)$BANKA, 'ilk_no' => '1', 'yaprak' => '5'], 1))['ok'] && ceksenet_tanim_sil($db, (int)$ek['id'], 1)['ok']);
ok('banka silme: kayıtlarda kullanılan ("Akbank") → 409 kullanimda; kullanılmayan silinir; pasifleştirme çalışır', ceksenet_tanim_sil($db, (int)$db->query("SELECT id FROM ceksenet_tanimlar WHERE ad = 'Akbank'")->fetchColumn(), 1)['kod'] === 'kullanimda' && ceksenet_tanim_sil($db, $bId, 1)['ok']
    && ceksenet_tanim_durum($db, (int)$db->query("SELECT id FROM ceksenet_tanimlar WHERE ad = 'TEB'")->fetchColumn(), false, 1)['ok'] && !in_array('TEB', ceksenet_banka_secenekleri($db), true) && in_array('Akbank', ceksenet_banka_secenekleri($db), true));
ok('karne seçenekleri: aktif karne + sıradaki no + kasa adı', ceksenet_karne_secenekleri($db)[0]['kasa_ad'] === 'Karne Banka' && ceksenet_karne_secenekleri($db)[0]['siradaki'] !== null);

echo "\n=== 13. Uç (gerçek cek_kaydet.php) ===\n";
$r = ceksenet_t_istek_cagri(['dosya' => 'cek_kaydet.php', 'post' => [], 'server' => ['REQUEST_METHOD' => 'GET']]);
ok('GET → 405 (Allow: POST)', $r['kod'] === 405 && baslik($r, 'Allow') === 'POST');
$r = ceksenet_t_istek_cagri(['dosya' => 'cek_kaydet.php', 'post' => ['islem' => 'tanim_kaydet', 'csrf' => 'yanlis'], 'server' => ['HTTP_X_REQUESTED_WITH' => 'fetch']]);
ok('CSRF yanlış → 403 JSON, hiçbir şey yazılmadı', $r['kod'] === 403 && say('ceksenet_tanimlar', "ad = 'Csrf Banka'") === 0);
$P = ['sinif' => 'cek', 'yon' => 'alinan', 'cari_id' => (string)$MUS, 'tarih' => gun(0), 'seri_no' => ['9701', '9702'], 'banka_ad' => ['Akbank', 'Akbank'], 'hesap_no' => ['U-1', 'U-1'], 'vade' => [gun(10), gun(20)], 'tutar' => ['100,00', '200,00'], 'para_birimi' => ['TRY', 'TRY'], 'karne_id' => ['', '']];
$r = uc(['islem' => 'bordro_kaydet'] + $P);
$j = js($r);
ok('bordro_kaydet JSON: 200, ok, idler 2, bordro_no, yonlendir cek_dekontu.php?yon=alinan, toplamlar + ortalama_vade nesne', $r['kod'] === 200 && $j['ok'] === true && count($j['idler']) === 2 && preg_match('/^BRD-/', (string)$j['bordro_no']) === 1 && $j['yonlendir'] === 'cek_dekontu.php?yon=alinan' && $j['toplamlar']['TRY']['kurus'] === 30000 && $j['ortalama_vade']['TRY']['gun'] === 17 && $j['sinif'] === 'cek' && $j['yon'] === 'alinan', $r['govde']);
$r = uc(['islem' => 'bordro_kaydet'] + $P); $j = js($r);
ok('aynı mükerrer çek → 409 kod mukerrer + satir_hatalari nesnesi', $r['kod'] === 409 && $j['kod'] === 'mukerrer' && isset($j['satir_hatalari']['0']['seri_no']), $r['govde']);
$r = uc(['islem' => 'bordro_kaydet', 'sinif' => 'cek', 'yon' => 'alinan']); $j = js($r);
ok('eksik alan → 422 + alanlar nesnesi', $r['kod'] === 422 && $j['ok'] === false && isset($j['alanlar']['tarih']));
$y1 = (int)$db->query("SELECT id FROM ceksenet_kayitlar WHERE seri_no = '9701'")->fetchColumn();
$r = uc(['islem' => 'islem_uygula', 'gecis' => 'ciro', 'ids' => [(string)$y1], 'cari_id' => (string)$TED, 'tarih' => gun(0)]); $j = js($r);
ok('islem_uygula JSON (gecis=ciro): 200, yeni_durum ciro, bordro_no, yonlendir', $r['kod'] === 200 && $j['ok'] && $j['yeni_durum'] === 'ciro' && $j['idler'] === [$y1] && $j['yonlendir'] === 'cek_dekontu.php?yon=alinan', $r['govde']);
$r = uc(['islem' => 'islem_uygula', 'gecis' => 'ciro', 'ids' => [(string)$y1], 'cari_id' => (string)$TED, 'tarih' => gun(0)]); $j = js($r);
ok('geçersiz geçiş → 409 gecersiz_gecis + kayitlar[]', $r['kod'] === 409 && $j['kod'] === 'gecersiz_gecis' && $j['kayitlar'][0]['id'] === $y1);
$r = uc(['islem' => 'islem_uygula', 'ids' => [(string)$y1], 'tarih' => gun(0)]); $j = js($r);
ok('`islem` yönlendirme anahtarı geçiş adı olarak YOK SAYILIR: gecis verilmezse 422 "Geçersiz işlem"', $r['kod'] === 422 && $j['ok'] === false);
$vb = (int)bordro('cek', 'verilen', $TED, [['seri_no' => '9801', 'banka_ad' => 'Halkbank', 'vade' => gun(5), 'tutar' => '88888,00']])['idler'][0];
$r = uc(['islem' => 'islem_uygula', 'gecis' => 'odendi', 'ids' => [(string)$vb], 'kasa_id' => (string)$KASA, 'tarih' => gun(0), 'istek_anahtari' => 'uc-yet']); $j = js($r);
ok('yetersiz bakiye → 409 yetersiz_bakiye + bakiye + eksik (kuruş)', $r['kod'] === 409 && $j['kod'] === 'yetersiz_bakiye' && is_int($j['bakiye']) && $j['eksik'] > 0, $r['govde']);
$r = uc(['islem' => 'islem_uygula', 'gecis' => 'odendi', 'ids' => [(string)$vb], 'kasa_id' => (string)$KASA, 'tarih' => gun(0), 'istek_anahtari' => 'uc-yet', 'eksiye_izin' => '1']); $j = js($r);
ok('"Yine de kaydet" (aynı anahtar + eksiye_izin=1) → 200 + uyari', $r['kod'] === 200 && $j['ok'] && str_contains((string)$j['uyari'], 'eksiye düştü'));
$hY = (int)$db->query("SELECT id FROM ceksenet_hareketler WHERE kayit_id = $y1 AND islem = 'giris'")->fetchColumn();
$r = uc(['islem' => 'hareket_iptal', 'id' => (string)$hY, 'neden' => 'Deneme']); $j = js($r);
ok('hareket_iptal: son olmayan → 409 son_degil', $r['kod'] === 409 && $j['kod'] === 'son_degil');
$hc = (int)$db->query("SELECT id FROM ceksenet_hareketler WHERE kayit_id = $y1 AND islem = 'ciro'")->fetchColumn();
$r = uc(['islem' => 'hareket_iptal', 'id' => (string)$hc, 'neden' => 'Ciro yanlış']); $j = js($r);
ok('hareket_iptal: 200, kayit_id, yeni_durum portfoy, yonlendir ?id=', $r['kod'] === 200 && $j['ok'] && $j['kayit_id'] === $y1 && $j['yeni_durum'] === 'portfoy' && $j['yonlendir'] === 'cek_dekontu.php?yon=alinan&id=' . $y1, $r['govde']);
$r = uc(['islem' => 'hareket_iptal', 'id' => (string)$hc, 'neden' => 'Tekrar']); ok('hareket_iptal zaten iptal → 422 kod zaten_iptal', $r['kod'] === 422 && js($r)['kod'] === 'zaten_iptal');
$r = uc(['islem' => 'hareket_iptal', 'id' => (string)$hc, 'neden' => 'x']); ok('hareket_iptal neden kısa → 422', $r['kod'] === 422);
$r = uc(['islem' => 'tanim_kaydet', 'tur' => 'banka', 'ad' => 'Uç Banka', 'sube' => 'X']); $j = js($r); $ub = (int)$j['id'];
ok('tanim_kaydet JSON 200 + id', $r['kod'] === 200 && $j['ok'] && $ub > 0);
$r = uc(['islem' => 'tanim_durum', 'id' => (string)$ub, 'aktif' => '0']); ok('tanim_durum: onay kutusu gelmediyse pasif', $r['kod'] === 200 && (int)ceksenet_tanim_bul($db, $ub)['aktif'] === 0);
$r = uc(['islem' => 'tanim_sil', 'id' => (string)$ub]); ok('tanim_sil 200; kullanılan banka 409 kullanimda', $r['kod'] === 200 && js(uc(['islem' => 'tanim_sil', 'id' => (string)(int)$db->query("SELECT id FROM ceksenet_tanimlar WHERE ad = 'Akbank'")->fetchColumn()]))['kod'] === 'kullanimda');
$r = uc(['islem' => 'bilinmeyen']); ok('bilinmeyen işlem → 422', $r['kod'] === 422);
foreach (['bordro_kaydet', 'islem_uygula', 'hareket_iptal', 'tanim_kaydet', 'tanim_durum', 'tanim_sil'] as $isl) { $r = uc(['islem' => $isl], true, ['admin' => false]); ok("yönetici değil: $isl → 403 JSON", $r['kod'] === 403 && js($r)['ok'] === false); }
$r = uc(['islem' => 'bordro_kaydet', 'geri' => 'https://kotu.example/cek_dekontu.php'] + $P, false);
ok('klasik form: yerel olmayan `geri` yok sayılır; sonuç 303 (başarılıysa cek_dekontu.php?yon=alinan, mükerrerse cek_dekontu.php + hata flash)', $r['kod'] === 303 && in_array(baslik($r, 'Location'), ['cek_dekontu.php?yon=alinan', 'cek_dekontu.php'], true));
ok('klasik form: Location ile flash tipi tutarlı — başarı → yon=alinan, hata (mükerrer) → cek_dekontu.php + flash error', $r['kod'] === 303 && (baslik($r, 'Location') === 'cek_dekontu.php?yon=alinan' ? ($r['flash']['type'] ?? '') !== 'error' : (baslik($r, 'Location') === 'cek_dekontu.php' && ($r['flash']['type'] ?? '') === 'error')));
$r = uc(['islem' => 'tanim_kaydet', 'tur' => 'banka', 'ad' => 'Klasik Banka', 'geri' => 'cek_tanimlari.php?sekme=banka'], false);
ok('klasik form + yerel `geri` (cek_tanimlari.php?sekme=banka) korunur', $r['kod'] === 303 && baslik($r, 'Location') === 'cek_tanimlari.php?sekme=banka');
$r = uc(['islem' => 'tanim_kaydet', 'tur' => 'banka', 'ad' => 'X', 'geri' => 'kasa_fisleri.php'], false);
ok('klasik form: başka modül sayfası `geri` → cek_dekontu.php', baslik($r, 'Location') === 'cek_dekontu.php');
$sb = ceksenet_t_istek_cagri(['dosya' => 'cek_kaydet.php', 'post' => ['islem' => 'bordro_kaydet', 'csrf' => 'test-csrf-belirteci', 'sinif' => 'senet', 'yon' => 'verilen', 'cari_id' => (string)$TED, 'tarih' => gun(0), 'seri_no' => ['US-1'], 'vade' => [gun(30)], 'tutar' => ['10,00']], 'server' => ['HTTP_X_REQUESTED_WITH' => 'fetch']]);
ok('senet verilen bordro → yonlendir senet_dekontu.php?yon=verilen', js($sb)['yonlendir'] === 'senet_dekontu.php?yon=verilen' && $sb['kod'] === 200, $sb['govde']);

echo "\n=== 13b. Denetim düzeltmeleri (K1 yarım bordro, Ö1 PDO, Ö2 iptal, karne kasası) ===\n";
function sayim(): array { global $db; return [say('ceksenet_kayitlar'), say('ceksenet_hareketler'), say('cari_hareketler', "kaynak_modul = 'ceksenet'"), say('kasa_hareketler', "kaynak_modul = 'ceksenet'")]; }
$vd = gun(30); $c0 = cb($TED); $s0 = sayim();
// K1 senaryo 1: verilen + karnesiz, aynı banka + aynı seri, hesaplardan biri BOŞ (v528 düzeltmesi: iki dolu FARKLI hesap artık meşru → aşağıda)
$r = bordro('cek', 'verilen', $TED, [['seri_no' => '111', 'banka_ad' => 'Akbank', 'hesap_no' => 'H-1', 'vade' => $vd, 'tutar' => '1000,00'], ['seri_no' => '111', 'banka_ad' => 'Akbank', 'hesap_no' => '', 'vade' => $vd, 'tutar' => '2000,00']]);
ok('K1/1 verilen karnesiz aynı banka+seri (hesaplardan biri boş): 409 mukerrer, satır 2 işaretli; DB\'de 0 kayıt / 0 hareket / 0 cari dekont; cari bakiye değişmedi', !$r['ok'] && $r['kod'] === 'mukerrer' && isset($r['satir_hatalari'][1]['seri_no']) && sayim() === $s0 && cb($TED) === $c0, json_encode($r, JSON_UNESCAPED_UNICODE));
// K1 senaryo 2: karneli + karnesiz (karnenin bankası, harf farkıyla) aynı seri — her iki sırada
$r = bordro('cek', 'verilen', $TED, [['seri_no' => '0800501', 'karne_id' => (string)$KARNE, 'vade' => $vd, 'tutar' => '10,00'], ['seri_no' => '0800501', 'banka_ad' => 'ÖRNEK BANKA', 'vade' => $vd, 'tutar' => '20,00']]);
ok('K1/2 karneli + karnesiz (karnenin bankası, harf-duyarsız) aynı seri: 409 mukerrer; 0 kayıt / 0 hareket / 0 dekont', !$r['ok'] && $r['kod'] === 'mukerrer' && sayim() === $s0 && cb($TED) === $c0, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = bordro('cek', 'verilen', $TED, [['seri_no' => '0800502', 'banka_ad' => 'Örnek Banka', 'vade' => $vd, 'tutar' => '20,00'], ['seri_no' => '0800502', 'karne_id' => (string)$KARNE, 'vade' => $vd, 'tutar' => '10,00']]);
ok('K1/2b karnesiz + karneli (ters sıra) aynı seri: 409 mukerrer, 0 yazım', !$r['ok'] && $r['kod'] === 'mukerrer' && sayim() === $s0 && cb($TED) === $c0, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = bordro('cek', 'alinan', $MUS, [['seri_no' => 'ab-77', 'banka_ad' => 'Akbank', 'hesap_no' => 'x-1', 'vade' => $vd, 'tutar' => '5,00'], ['seri_no' => 'AB-77', 'banka_ad' => 'AKBANK', 'hesap_no' => 'X-1', 'vade' => $vd, 'tutar' => '6,00']]);
ok('alınan çek: seri / banka / hesap no harf-duyarsız aynı sayılır (bordro içi mükerrer) → 409, 0 yazım', !$r['ok'] && $r['kod'] === 'mukerrer' && sayim() === $s0);
$r1 = bordro('cek', 'alinan', $MUS, [['seri_no' => 'ab-78', 'banka_ad' => 'Akbank', 'hesap_no' => 'x-1', 'vade' => $vd, 'tutar' => '5,00']]); $s1 = sayim();
$r = bordro('cek', 'alinan', $MUS, [['seri_no' => 'AB-78', 'banka_ad' => 'AKBANK', 'hesap_no' => 'X-1', 'vade' => $vd, 'tutar' => '6,00']]);
ok('DB karşılaştırması da harf-duyarsız: önceden kayıtlı "ab-78" varken "AB-78" mükerrer', $r1['ok'] && !$r['ok'] && $r['kod'] === 'mukerrer' && sayim() === $s1);
$mm = static fn(string $sn, string $b1, string $h1, ?int $k1, string $b2, string $h2, ?int $k2, string $seri = '1'): bool => ceksenet_mukerrer_mu($sn, ['banka' => $b1, 'hesap' => $h1, 'seri' => $seri, 'kasa' => $k1], ['banka' => $b2, 'hesap' => $h2, 'seri' => $seri, 'kasa' => $k2]);
ok('mükerrer kuralı TEK yardımcıdan (ceksenet_mukerrer_mu): banka+seri eşit VE (hesap boş YA DA eşit); iki dolu farklı hesap meşru; boşluk/tire/harf farkı yok sayılır; senet hiç mükerrer değil',
    $mm('cek', 'Akbank', 'H-1', null, 'AKBANK', 'h 1', null) && !$mm('cek', 'Akbank', 'H-1', null, 'Akbank', 'H-2', null) && $mm('cek', 'Akbank', '', null, 'Akbank', 'H-2', null) && $mm('cek', 'Akbank', 'H-1', null, 'Akbank', '', null)
    && !$mm('cek', 'Akbank', 'H-1', null, 'Garanti', 'H-1', null) && $mm('cek', 'Akbank', '', null, 'Akbank', '', null) && !$mm('senet', 'a', 'b', null, 'a', 'b', null)
    && ceksenet_mukerrer_mu('cek', ['banka' => 'A', 'hesap' => '', 'seri' => '1', 'kasa' => null], ['banka' => 'A', 'hesap' => '', 'seri' => '2', 'kasa' => null]) === false);
ok('mükerrer kuralı: iki FARKLI karne-kasası (IBAN boş olsa da) farklı hesap; aynı kasa mükerrer; karneli ↔ karnesiz hesapsız mükerrer; karneli IBAN ↔ karnesiz farklı hesap meşru',
    !$mm('cek', 'X', '', 5, 'X', '', 6) && $mm('cek', 'X', '', 5, 'X', '', 5) && $mm('cek', 'X', 'TR11', 5, 'X', '', null) && !$mm('cek', 'X', 'TR11', 5, 'X', 'TR22', null) && $mm('cek', 'X', 'TR 11', 5, 'X', 'tr-11', null));
// v528 mükerrer kuralı: aynı bankada iki FARKLI banka-kasalı karne → aynı seri KABUL; aynı karne aynı seri RED
$KBX = kart('Mk Banka X', 'banka', 'TRY', ['banka_adi' => 'Mk Banka']); $KBY = kart('Mk Banka Y', 'banka', 'TRY', ['banka_adi' => 'Mk Banka']);
$KX = (int)ceksenet_tanim_kaydet($db, ['tur' => 'karne', 'ad' => 'Mk Karne X', 'kasa_id' => (string)$KBX, 'ilk_no' => '7000', 'yaprak' => '50'], 1)['id'];
$KY = (int)ceksenet_tanim_kaydet($db, ['tur' => 'karne', 'ad' => 'Mk Karne Y', 'kasa_id' => (string)$KBY, 'ilk_no' => '7000', 'yaprak' => '50'], 1)['id'];
$r = bordro('cek', 'verilen', $TED, [['seri_no' => '7001', 'karne_id' => (string)$KX, 'vade' => $vd, 'tutar' => '10,00'], ['seri_no' => '7001', 'karne_id' => (string)$KY, 'vade' => $vd, 'tutar' => '20,00']]);
ok('aynı bankada İKİ FARKLI banka-kasalı karnede aynı seri (aynı bordro): KABUL, 2 kayıt', $r['ok'] && count($r['idler']) === 2, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = bordro('cek', 'verilen', $TED, [['seri_no' => '7002', 'karne_id' => (string)$KX, 'vade' => $vd, 'tutar' => '10,00']]);
$r2 = bordro('cek', 'verilen', $TED, [['seri_no' => '7002', 'karne_id' => (string)$KY, 'vade' => $vd, 'tutar' => '10,00']]);
ok('aynı seri, diğer karne-kasası, ayrı bordro (DB karşılaştırması): KABUL', $r['ok'] && $r2['ok'], json_encode($r2, JSON_UNESCAPED_UNICODE));
$sx = sayim();
$r = bordro('cek', 'verilen', $TED, [['seri_no' => '7002', 'karne_id' => (string)$KX, 'vade' => $vd, 'tutar' => '10,00']]);
ok('AYNI karnede aynı seri (DB): 409 mukerrer, 0 yazım', !$r['ok'] && $r['kod'] === 'mukerrer' && sayim() === $sx, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = bordro('cek', 'verilen', $TED, [['seri_no' => '7003', 'karne_id' => (string)$KX, 'vade' => $vd, 'tutar' => '10,00'], ['seri_no' => '7003', 'karne_id' => (string)$KX, 'vade' => $vd, 'tutar' => '10,00']]);
ok('AYNI karnede aynı seri (bordro içi): 409 mukerrer, 0 yazım', !$r['ok'] && $r['kod'] === 'mukerrer' && sayim() === $sx);
$r = bordro('cek', 'verilen', $TED, [['seri_no' => '7001', 'banka_ad' => 'MK BANKA', 'vade' => $vd, 'tutar' => '10,00']]);
ok('karneli (kayıtlı) + karnesiz HESAPSIZ aynı banka+seri: 409 mukerrer', !$r['ok'] && $r['kod'] === 'mukerrer' && sayim() === $sx, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = bordro('cek', 'verilen', $TED, [['seri_no' => '7004', 'banka_ad' => 'Mk Banka', 'hesap_no' => 'TR0001', 'vade' => $vd, 'tutar' => '10,00'], ['seri_no' => '7004', 'banka_ad' => 'Mk Banka', 'hesap_no' => 'TR0002', 'vade' => $vd, 'tutar' => '10,00']]);
ok('verilen karnesiz, aynı banka+seri, iki dolu FARKLI hesap: KABUL', $r['ok'], json_encode($r, JSON_UNESCAPED_UNICODE));
// alınan çek: aynı banka+hesap+seri RED, farklı hesap KABUL
$r1 = bordro('cek', 'alinan', $MUS, [['seri_no' => 'MA-1', 'banka_ad' => 'Mk Banka', 'hesap_no' => 'TR-100', 'vade' => $vd, 'tutar' => '5,00']]); $sa = sayim();
$r = bordro('cek', 'alinan', $MUS, [['seri_no' => 'MA-1', 'banka_ad' => 'Mk Banka', 'hesap_no' => 'tr 100', 'vade' => $vd, 'tutar' => '5,00']]);
ok('alınan çek: aynı banka+hesap+seri (boşluk/harf farkı) → 409 mukerrer, 0 yazım', $r1['ok'] && !$r['ok'] && $r['kod'] === 'mukerrer' && sayim() === $sa);
$r = bordro('cek', 'alinan', $MUS, [['seri_no' => 'MA-1', 'banka_ad' => 'Mk Banka', 'hesap_no' => 'TR-200', 'vade' => $vd, 'tutar' => '5,00']]);
ok('alınan çek: aynı banka+seri, FARKLI hesap → KABUL', $r['ok'], json_encode($r, JSON_UNESCAPED_UNICODE));
$c0 = cb($TED);   // v528 mükerrer testleri cari bakiyeyi değiştirdi: yeniden ölç
// v528: hareket iptali sonrası yonlendir kaydın YÖNÜNÜ taşır (verilen evrak Alınan sekmesine dönmez)
$vyb = bordro('cek', 'verilen', $TED, [['seri_no' => 'YN-1', 'banka_ad' => 'Yn Banka', 'vade' => $vd, 'tutar' => '12,00']]); $vyk = (int)$vyb['idler'][0];
$vyh = (int)$db->query("SELECT id FROM ceksenet_hareketler WHERE kayit_id = $vyk AND islem = 'cikis'")->fetchColumn();
$r = uc(['islem' => 'hareket_iptal', 'id' => (string)$vyh, 'neden' => 'Yön denemesi']); $j = js($r);
ok('hareket_iptal (VERİLEN evrak): yonlendir cek_dekontu.php?yon=verilen&id=<kayıt> (Alınan sekmesine dönmez)', $r['kod'] === 200 && $j['ok'] && $j['yonlendir'] === 'cek_dekontu.php?yon=verilen&id=' . $vyk && $j['yon'] === 'verilen', $r['govde']);
// v528: tutar belirsizliği ("1,500" = 1.500,00 mü 1,50 mi?) — bordro satırında 422/alan hatası; diğer biçimler mevcut ayrıştırıcıdan aynen
$s3 = sayim();
$r = bordro('senet', 'alinan', $MUS, [['seri_no' => 'TB-1', 'vade' => $vd, 'tutar' => '1,500']]);
ok('bordro tutarı "1,500" BELİRSİZ: ok=false, satir_hatalari[0][tutar] "Tutar belirsiz: 1.500 …", 0 yazım', !$r['ok'] && str_contains((string)($r['satir_hatalari'][0]['tutar'] ?? ''), 'Tutar belirsiz: 1.500 için nokta kullanın') && sayim() === $s3, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = uc(['islem' => 'bordro_kaydet', 'sinif' => 'senet', 'yon' => 'alinan', 'cari_id' => (string)$MUS, 'tarih' => gun(0), 'seri_no' => ['TB-2'], 'vade' => [$vd], 'tutar' => ['12,345']]); $j = js($r);
ok('uç: belirsiz tutar → 422 + satir_hatalari nesnesinde tutar hatası (12,345), yazım yok', $r['kod'] === 422 && isset($j['satir_hatalari']['0']['tutar']) && sayim() === $s3, $r['govde']);
foreach (['1.500' => 150000, '1.500,00' => 150000, '1500' => 150000, '1,50' => 150, '1,5' => 150, '0,500' => 50, '1,234.56' => 123456, '12.345,67' => 1234567, '2,50' => 250, '1 500,25' => 150025] as $girdi => $beklenen) {
    $r = bordro('senet', 'alinan', $MUS, [['seri_no' => 'TB-' . md5($girdi), 'vade' => $vd, 'tutar' => (string)$girdi]]);
    $k = $r['ok'] ? (int)round((float)$db->query('SELECT tutar FROM ceksenet_kayitlar WHERE id = ' . (int)$r['idler'][0])->fetchColumn() * 100) : null;
    ok("tutar \"$girdi\" → $beklenen kuruş (mevcut davranış korunur)", $r['ok'] && $k === $beklenen, json_encode($r, JSON_UNESCAPED_UNICODE));
}
ok('kasa_tutar_coz: belirsiz = dizi (belirsiz=true, mesaj); "0,500" ve "1.500" belirsiz DEĞİL; geçersiz = null', is_array(kasa_tutar_coz('1,500')) && kasa_tutar_coz('1,500')['belirsiz'] === true && kasa_tutar_coz('999,999') !== null && is_array(kasa_tutar_coz(' 12,345 ₺ '))
    && kasa_tutar_coz('0,500') === 50 && kasa_tutar_coz('1.500') === 150000 && kasa_tutar_coz('abc') === null && kasa_tutar_coz('') === null && kasa_tutar_coz('-5') === null && kasa_tutar_coz('0,005') === null);
// K1: tx İÇİNDE mükerrer (yarış benzetimi: ilk dekont yazılınca "başka istek" aynı seriyi işler) → HEPSİ geri alınır
$db->exec("CREATE TRIGGER dz_race AFTER INSERT ON cari_hareketler WHEN NEW.kaynak_modul = 'ceksenet' AND NOT EXISTS (SELECT 1 FROM ceksenet_kayitlar WHERE seri_no = 'R-2')
    BEGIN INSERT INTO ceksenet_kayitlar (sinif, yon, portfoy_no, seri_no, banka_ad, vade, tutar, para_birimi, cari_id, durum, created_at) VALUES ('cek', 'verilen', 'RACE-1', 'R-2', 'Rc Banka', '$vd', 1, 'TRY', 1, 'verildi', '2026-01-01 00:00:00'); END");
$s2 = sayim();
$r = bordro('cek', 'verilen', $TED, [['seri_no' => 'R-1', 'banka_ad' => 'Rc Banka', 'vade' => $vd, 'tutar' => '30,00'], ['seri_no' => 'R-2', 'banka_ad' => 'Rc Banka', 'vade' => $vd, 'tutar' => '40,00']]);
$db->exec('DROP TRIGGER dz_race');
ok('K1 tx-içi mükerrer (yarış): ok=false, kod mukerrer + satir_hatalari DÖNER; kayıt + hareket + cari dekontu HEPSİ geri alındı (yarım bordro yok); cari bakiye değişmedi', !$r['ok'] && ($r['kod'] ?? '') === 'mukerrer' && isset($r['satir_hatalari'][1]['seri_no']) && sayim() === $s2 && cb($TED) === $c0 && !$db->inTransaction(), json_encode($r, JSON_UNESCAPED_UNICODE));
ok('K1 geri alınan bordro numara tüketmedi: aynı satırlar yarış bitince başarıyla kaydedilir, portföy / bordro / belge no dolu, cari_hareket_id dolu', ($rr = bordro('cek', 'verilen', $TED, [['seri_no' => 'R-1', 'banka_ad' => 'Rc Banka', 'vade' => $vd, 'tutar' => '30,00'], ['seri_no' => 'R-2', 'banka_ad' => 'Rc Banka', 'vade' => $vd, 'tutar' => '40,00']]))['ok']
    && !$db->query("SELECT 1 FROM ceksenet_kayitlar WHERE seri_no IN ('R-1','R-2') AND (portfoy_no = '' OR bordro_no = '')")->fetch() && !$db->query("SELECT 1 FROM ceksenet_hareketler WHERE id IN (" . implode(',', array_map('intval', $rr['hareket_idler'])) . ") AND (belge_no = '' OR cari_hareket_id IS NULL OR bordro_no = '')")->fetch() && cb($TED) === $c0 + 7000, json_encode($rr, JSON_UNESCAPED_UNICODE));
// Bordroda 2. satırda cari yazımı patlar → tam rollback
$c1 = cb($TED); $s3 = sayim();
$db->exec("CREATE TRIGGER dz_cari BEFORE INSERT ON cari_hareketler WHEN NEW.kaynak_modul = 'ceksenet' AND EXISTS (SELECT 1 FROM ceksenet_kayitlar WHERE seri_no = 'P-2') BEGIN SELECT RAISE(ABORT, 'cari zorla'); END");
try { $r = bordro('cek', 'verilen', $TED, [['seri_no' => 'P-1', 'banka_ad' => 'Pz Banka', 'vade' => $vd, 'tutar' => '11,00'], ['seri_no' => 'P-2', 'banka_ad' => 'Pz Banka', 'vade' => $vd, 'tutar' => '12,00']]); $pe = null; } catch (Throwable $e) { $r = null; $pe = get_class($e); }
$db->exec('DROP TRIGGER dz_cari');
ok('2. satırda cari yazımı patlar: istisna SIZMAZ, ok=false; 1. satırın kayıt/hareket/dekontu dahil HİÇBİR şey kalmadı; tx kapalı', $pe === null && is_array($r) && !$r['ok'] && sayim() === $s3 && cb($TED) === $c1 && !$db->inTransaction(), (string)$pe . json_encode($r, JSON_UNESCAPED_UNICODE));
// Ö1: PDO hatası istisna sızmaz (PDOException catch'i RuntimeException'dan ÖNCE)
$id = cek1($MUS, 'DZ-A1', '10,00')['id']; $s4 = sayim();
$db->exec("CREATE TRIGGER dz_h BEFORE INSERT ON ceksenet_hareketler BEGIN SELECT RAISE(ABORT, 'zorla'); END");
$f = [];
foreach (['bordro' => fn() => bordro('cek', 'alinan', $MUS, [['seri_no' => 'DZ-A2', 'banka_ad' => 'Akbank', 'hesap_no' => 'H-9', 'vade' => $vd, 'tutar' => '1,00']], ['istek_anahtari' => 'dz-b1']),
          'islem' => fn() => gecis('tahsile_ver', [$id], ['kasa_id' => (string)$BANKA, 'istek_anahtari' => 'dz-i1'])] as $ad => $fn) {
    try { $x = $fn(); $f[$ad] = !$x['ok'] && empty($x['tekrar']) ? 'ok' : 'beklenmeyen ' . json_encode($x, JSON_UNESCAPED_UNICODE); } catch (Throwable $e) { $f[$ad] = 'FIRLATTI ' . get_class($e); }
}
$db->exec('DROP TRIGGER dz_h');
ok('PDO hatasında istisna sızmaz: bordro_kaydet + islem_uygula ok=false; hiçbir şey yazılmadı; kayıt durumu portfoy; tx kapalı', $f === ['bordro' => 'ok', 'islem' => 'ok'] && sayim() === $s4 && durum($id) === 'portfoy' && !$db->inTransaction(), json_encode($f));
$cc = file_get_contents(__DIR__ . '/../config/ceksenet_core.php');
foreach (['ceksenet_bordro_kaydet', 'ceksenet_islem_uygula'] as $fn) {
    preg_match('/function ' . $fn . '\(.*?\n}\n/s', $cc, $mm); $g = $mm[0] ?? '';
    $pp = strpos($g, 'catch (PDOException'); $rp = strpos($g, 'catch (RuntimeException'); $ip = strpos($g, 'ceksenet_istek_bul(', (int)strpos($g, 'ceksenet_tx('));
    ok("$fn: catch (PDOException) RuntimeException'dan ÖNCE; tx içinde (kilit/ilk okuma sonrası) istek anahtarı yeniden denenir; tekrar sonucu audit'i çiftlemez", $pp !== false && $rp !== false && $pp < $rp && $ip !== false && str_contains($g, "!empty(\$s['tekrar'])"));
}
$gb = (preg_match('/function ceksenet_bordro_kaydet\(.*?\n}\n/s', $cc, $mm) ? $mm[0] : '');
ok('bordro: COMMIT sonrası hesap try/catch (Throwable) içinde; tx closure\'ında `return ceksenet_hata` YOK (yazmadan sonra return-hata commit ederdi)', str_contains($gb, 'catch (Throwable $e)') && !preg_match('/function \(\) use .*?\n(?:.*\n)*?\s+return ceksenet_hata\(\'Satır/', $gb));
// İptal: kaynaklı cari / kasa satırlarını HER ZAMAN temizler (hareket satırındaki *_hareket_id boş kalmış olsa da)
$rv = bordro('cek', 'verilen', $TED, [['seri_no' => 'DZ-V1', 'banka_ad' => 'Akbank', 'hesap_no' => 'H-1', 'vade' => $vd, 'tutar' => '77,00']]); $hv = (int)$rv['hareket_idler'][0];
$cb1 = cb($TED); $cariSatir = (int)$db->query("SELECT cari_hareket_id FROM ceksenet_hareketler WHERE id = $hv")->fetchColumn();
$db->exec("UPDATE ceksenet_hareketler SET cari_hareket_id = NULL WHERE id = $hv");   // eski yarım yazımı taklit: bağ boş
$ip = ceksenet_hareket_iptal($db, $hv, 'yetim dekont temizligi', 1);
$db->exec("UPDATE ceksenet_hareketler SET cari_hareket_id = $cariSatir WHERE id = $hv");
ok('iptal: hareketin cari_hareket_id boş olsa da kaynaklı cari dekontu iptal edilir (cari_iptal dolu, bakiye -77,00 geri)', $ip['ok'] && count($ip['cari_iptal']) === 1 && cb($TED) === $cb1 - 7700 && say('cari_hareketler', "kaynak_modul = 'ceksenet' AND kaynak_id = $hv AND durum = 'gecerli'") === 0, json_encode($ip));
$ik2 = cek1($MUS, 'DZ-K2', '300,00')['id']; $k0 = kb($KASA);
$rk = gecis('tahsil_kasa', [$ik2], ['kasa_id' => (string)$KASA]); $hk = (int)$rk['hareket_idler'][0];
$kSonra = kb($KASA); $kasaSatir = (int)$db->query("SELECT kasa_hareket_id FROM ceksenet_hareketler WHERE id = $hk")->fetchColumn();
$db->exec("UPDATE ceksenet_hareketler SET kasa_hareket_id = NULL WHERE id = $hk");
$ip = ceksenet_hareket_iptal($db, $hk, 'yetim kasa satiri', 1);
$db->exec("UPDATE ceksenet_hareketler SET kasa_hareket_id = $kasaSatir WHERE id = $hk");   // bağ geri konur (tutarlılık bölümü bağları denetler)
ok('iptal: kasa_hareket_id boş olsa da kaynaklı kasa satırı iptal edilir (kasa bakiyesi geri döner)', $rk['ok'] && $kSonra === $k0 + 30000 && $ip['ok'] && count($ip['kasa_iptal']) === 1 && kb($KASA) === $k0 && durum($ik2) === 'portfoy', json_encode($ip));
// Ö2a: kayıt UPDATE'i AND durum = ? — kayıt, hareketin bıraktığı durumda değilse HEPSİ geri
$ik3 = cek1($MUS, 'DZ-K3', '20,00')['id']; $rk = gecis('tahsil_kasa', [$ik3], ['kasa_id' => (string)$KASA]); $hk = (int)$rk['hareket_idler'][0]; $k1 = kb($KASA);
$db->exec("UPDATE ceksenet_kayitlar SET durum = 'tahsilde' WHERE id = $ik3");   // başka işlem durumu değiştirmiş gibi
$ip = ceksenet_hareket_iptal($db, $hk, 'durum degismis', 1);
ok('iptal: kayıt durumu hareketin yeni_durumundan farklıysa 409 son_degil ve HİÇBİR şey değişmez (hareket + kasa satırı geçerli kalır)', !$ip['ok'] && ($ip['kod'] ?? '') === 'son_degil' && ceksenet_hareket_oku($db, $hk)['durum'] === 'gecerli' && kb($KASA) === $k1 && !$db->inTransaction(), json_encode($ip, JSON_UNESCAPED_UNICODE));
$db->exec("UPDATE ceksenet_kayitlar SET durum = 'tahsil' WHERE id = $ik3");
ok('…durum düzelince aynı iptal başarılı', ceksenet_hareket_iptal($db, $hk, 'durum duzeldi', 1)['ok'] && durum($ik3) === 'portfoy' && kb($KASA) === $k1 - 2000);
ok('bakiye okuması kilitli: kasa_eksik_hesapla kasa_bakiye_kilitli kullanır (MySQL FOR UPDATE)', str_contains(file_get_contents(__DIR__ . '/../config/kasa_core.php'), 'kasa_bakiye_kilitli($pdo, (int)$kasa[\'id\'])'));
// Karne kasası doğrulaması
$DEPOLAR = null;
$kbP = kart('Dz Karne Pasif', 'banka', 'TRY', ['banka_adi' => 'Dz Banka']); $kbSube = kart('Dz Karne Şube', 'banka', 'TRY', ['depo' => 'Şube Depo']); $kbN = kart('Dz Karne Nakit', 'nakit');
kasa_kart_durum($db, $kbP, false, 1);
$r = ceksenet_tanim_kaydet($db, ['tur' => 'karne', 'ad' => 'Dz Karne X', 'kasa_id' => (string)$kbP, 'ilk_no' => '100', 'yaprak' => '10'], 1);
ok('karne tanımı: PASİF banka hesabı → hata alanlar[kasa_id]', !$r['ok'] && isset($r['alanlar']['kasa_id']));
$DEPOLAR = ['Merkez Depo'];
$r = ceksenet_tanim_kaydet($db, ['tur' => 'karne', 'ad' => 'Dz Karne Y', 'kasa_id' => (string)$kbSube, 'ilk_no' => '100', 'yaprak' => '10'], 1);
ok('karne tanımı: aktif depoda GÖRÜNMEYEN banka hesabı → hata alanlar[kasa_id]', !$r['ok'] && isset($r['alanlar']['kasa_id']));
$DEPOLAR = null;
$r = ceksenet_tanim_kaydet($db, ['tur' => 'karne', 'ad' => 'Dz Karne Z', 'kasa_id' => (string)$kbN, 'ilk_no' => '100', 'yaprak' => '10'], 1);
ok('karne tanımı: nakit kasa hâlâ reddedilir; aktif + görünür banka hesabı kabul edilir', !$r['ok'] && isset($r['alanlar']['kasa_id']) && ($kt2 = ceksenet_tanim_kaydet($db, ['tur' => 'karne', 'ad' => 'Dz Karne OK', 'kasa_id' => (string)$kbSube, 'ilk_no' => '5000', 'yaprak' => '10'], 1))['ok']);
$kk = (int)$kt2['id']; $KBA = (int)kart('Dz Karne Aktif', 'banka', 'TRY', ['banka_adi' => 'Dz Banka 2']);
$db->exec("UPDATE ceksenet_tanimlar SET kasa_id = $KBA WHERE id = $kk");
$rv = bordro('cek', 'verilen', $TED, [['seri_no' => '5001', 'karne_id' => (string)$kk, 'vade' => $vd, 'tutar' => '9,00']]); $vid = (int)$rv['idler'][0];
kasa_kart_durum($db, $KBA, false, 1);   // karnenin banka hesabı sonradan pasife alındı
$r = bordro('cek', 'verilen', $TED, [['seri_no' => '5002', 'karne_id' => (string)$kk, 'vade' => $vd, 'tutar' => '9,00']]);
ok('bordro: karnenin banka hesabı PASİFse açık satır hatası (karne_id), yazım yok', $rv['ok'] && !$r['ok'] && isset($r['satir_hatalari'][0]['karne_id']) && str_contains($r['satir_hatalari'][0]['karne_id'], 'pasif'), json_encode($r, JSON_UNESCAPED_UNICODE));
$r = gecis('odendi', [$vid]);
ok('ödendi (kasa seçilmedi, karnenin kasası pasif): açık hata "Karnenin banka hesabı pasif …", alanlar[kasa_id]; kayıt değişmedi', !$r['ok'] && str_contains((string)$r['hata'], 'Karnenin banka hesabı pasif') && isset($r['alanlar']['kasa_id']) && durum($vid) === 'verildi', json_encode($r, JSON_UNESCAPED_UNICODE));
$db->exec("UPDATE ceksenet_tanimlar SET kasa_id = 999999 WHERE id = $kk");
$r = gecis('odendi', [$vid]);
ok('ödendi (karnenin kasası artık YOK): açık hata "Karnenin banka hesabı bulunamadı …"', !$r['ok'] && str_contains((string)$r['hata'], 'Karnenin banka hesabı bulunamadı') && durum($vid) === 'verildi', json_encode($r, JSON_UNESCAPED_UNICODE));
$r = bordro('cek', 'verilen', $TED, [['seri_no' => '5003', 'karne_id' => (string)$kk, 'vade' => $vd, 'tutar' => '9,00']]);
ok('bordro: karnenin kasası yok → "Karnenin banka hesabı bulunamadı" (satır hatası)', !$r['ok'] && isset($r['satir_hatalari'][0]['karne_id']));

echo "\n=== 14. Tutarlılık ve denetim izi ===\n";
ok('audit: create / update / cancel / activate-deactivate / delete var, hepsi module=ceksenet', audit_say('create') >= 3 && audit_say('update') >= 3 && audit_say('cancel') >= 3 && audit_say('deactivate') >= 1 && audit_say('delete') >= 1);
ok('audit: csrf / password yok', say('audit_log', "module = 'ceksenet' AND (new_values LIKE '%csrf%' OR new_values LIKE '%password%')") === 0);
ok('her iptal satırında neden + kim + ne zaman dolu', say('ceksenet_hareketler', "durum = 'iptal' AND (iptal_neden IS NULL OR iptal_by IS NULL OR iptal_at IS NULL)") === 0 && say('ceksenet_hareketler', "durum = 'iptal'") >= 8);
ok('hiçbir hareket DELETE edilmedi: her kayıtta en az bir hareket; hareket sayısı yalnız artar', say('ceksenet_kayitlar', 'id NOT IN (SELECT kayit_id FROM ceksenet_hareketler)') === 0);
ok('cari tutarlılığı: ceksenet kaynaklı her cari satırının durumu = çek-senet hareketinin durumu (iptal ↔ iptal)', (function () use ($db) {
    $n = 0;
    foreach ($db->query("SELECT kaynak_id, durum FROM cari_hareketler WHERE kaynak_modul = 'ceksenet'")->fetchAll(PDO::FETCH_ASSOC) as $c) { $h = ceksenet_hareket_oku($db, (int)$c['kaynak_id']); if ($h === null || $h['durum'] !== $c['durum']) return false; $n++; }
    return $n > 20;
})());
ok('kasa tutarlılığı: ceksenet kaynaklı her kasa satırının durumu = hareketin durumu; kasa_hareket_id bağları doğru', (function () use ($db) {
    $n = 0;
    foreach ($db->query("SELECT id, kaynak_id, durum, tur FROM kasa_hareketler WHERE kaynak_modul = 'ceksenet'")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $h = ceksenet_hareket_oku($db, (int)$c['kaynak_id']);
        if ($h === null || $h['durum'] !== $c['durum'] || (int)$h['kasa_hareket_id'] !== (int)$c['id'] || !in_array($c['tur'], ['kaynak_giris', 'kaynak_cikis'], true)) return false;
        $n++;
    }
    return $n >= 4;
})());
ok('kayıt durumu = son geçerli hareketin yeni_durumu (iptal kayıt hariç) — tüm kayıtlar', (function () use ($db) {
    foreach ($db->query('SELECT id, durum FROM ceksenet_kayitlar')->fetchAll(PDO::FETCH_ASSOC) as $k) {
        $st = $db->prepare("SELECT yeni_durum FROM ceksenet_hareketler WHERE kayit_id = ? AND durum = 'gecerli' ORDER BY id DESC LIMIT 1"); $st->execute([$k['id']]);
        $son = $st->fetchColumn();
        if ($son === false ? $k['durum'] !== 'iptal' : $k['durum'] !== $son) return false;
    }
    return true;
})());
ok('bakiye tutarlılığı: her cari için geçerli ceksenet dekontları = kayıt durumlarından türetilen net etki', (function () use ($db, $MUS) {
    $net = 0;
    foreach ($db->query("SELECT yon, tutar, para_birimi FROM cari_hareketler WHERE kaynak_modul = 'ceksenet' AND durum = 'gecerli' AND cari_id = $MUS AND para_birimi = 'TRY'")->fetchAll(PDO::FETCH_ASSOC) as $x) $net += cari_kurus($x['tutar']) * ($x['yon'] === 'B' ? 1 : -1);
    // beklenen: portföyde/tahsilde/teminatta/tahsil edilen alınan TRY çekler Alacak (-), karşılıksız/iade ile geri alınanlar net 0 (Alacak + Borç)
    $beklenen = 0;
    foreach ($db->query("SELECT durum, tutar FROM ceksenet_kayitlar WHERE cari_id = $MUS AND yon = 'alinan' AND para_birimi = 'TRY'")->fetchAll(PDO::FETCH_ASSOC) as $k) if (in_array($k['durum'], ['portfoy', 'tahsilde', 'teminatta', 'tahsil', 'ciro'], true)) $beklenen -= cari_kurus($k['tutar']);
    return $net === $beklenen;
})());

siparis_test_bitir();
