<?php
// =========================================================
// scripts/puantaj_giris_cavus_smoke.php — v562: Puantaj Girişi «bağlı çavuş» (cavus_id → foremen.id; YALNIZ okunur)
//   php scripts/puantaj_giris_cavus_smoke.php   → çıkış kodu 0 = geçti
// Bellek içi SQLite; canlı DB'ye dokunmaz. Eski şema (kolonsuz) → pg_migrate ile kolon → ekle/düzenle/toplu/süzgeç/Excel/dışa aktarım.
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
$ROOT = dirname(__DIR__);
require_once $ROOT . '/config/puantaj_cizelgesi.php';
require_once $ROOT . '/config/puantaj_isaret.php';
require_once $ROOT . '/config/puantaj_giris.php';
require_once $ROOT . '/config/puantaj_giris_excel.php';

$gec = 0; $kal = 0;
function ok(string $ad, bool $c, string $ek = ''): void
{
    global $gec, $kal;
    if ($c) { $gec++; echo "PASS $ad\n"; } else { $kal++; echo "FAIL $ad" . ($ek !== '' ? " :: $ek" : '') . "\n"; }
}
function yeni(bool $eski): PDO
{
    $p = new PDO('sqlite::memory:');
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $p->exec('CREATE TABLE puantaj_giris_kisiler (id INTEGER PRIMARY KEY AUTOINCREMENT, ad TEXT NOT NULL, gorev TEXT NULL, depo TEXT NOT NULL DEFAULT "", aktif INTEGER NOT NULL DEFAULT 1, sira INTEGER NOT NULL DEFAULT 0, created_by INT NULL, created_at TEXT NULL, updated_at TEXT NULL' . ($eski ? '' : ', cavus_id INT NULL') . ')');
    $p->exec('CREATE TABLE puantaj_giris_gunler (id INTEGER PRIMARY KEY AUTOINCREMENT, kisi_id INT NOT NULL, tarih TEXT NOT NULL, kod TEXT NOT NULL, user_id INT NULL, created_at TEXT NULL, updated_at TEXT NULL)');
    $p->exec('CREATE TABLE foremen (id INTEGER PRIMARY KEY, name TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1)');
    $p->exec("INSERT INTO foremen (id, name, is_active) VALUES (1, 'Çavuş Ayşe', 1), (2, 'Çavuş Bekir', 1), (3, 'Çavuş Eski', 0), (4, 'Mükerrer', 1), (5, 'MÜKERRER', 1)");
    return $p;
}

echo "=== 1. Eski şema (kolon yok): arayüz kapalı, mevcut işlevler çalışır ===\n";
$p = yeni(true);
ok('kolon yok → pg_cavus_kolon_var false', pg_cavus_kolon_var($p) === false);
$r = pg_kisi_ekle($p, 'Örnek Kişi A', 'Paketçi', 'Depo A', 1);
ok('çavuşsuz ekleme eski şemada ÇALIŞIR', $r['ok'] === true && $r['id'] === 1);
$r = pg_kisi_ekle($p, 'Örnek Kişi B', '', 'Depo A', 1, 1);
ok('çavuş verilirse kolon yokken nazik hata (migrate.php işaret edilir), kayıt yazılmaz', $r['ok'] === false && str_contains($r['hata'], 'migrate.php') && (int)$p->query('SELECT COUNT(*) FROM puantaj_giris_kisiler')->fetchColumn() === 1);
$k = pg_kisiler_oku($p, true);
ok('okuyucu eski şemada çalışır: cavus_id null, cavus ""', count($k) === 1 && $k[0]['cavus_id'] === null && $k[0]['cavus'] === '');
ok('pg_tablo eski şemada çalışır, cavus_goster false (dışa aktarımda Çavuş sütunu YOK)', (function () use ($p) { $t = pg_tablo($p, date('Y-m'), '', true); return !isset($t['hata']) && $t['cavus_goster'] === false && pg_disa_baslik($t)[2] !== 'Çavuş'; })());
$u = pg_kisi_duzenle($p, $k[0], 'Örnek Kişi A', 'Yeni görev', 1);
ok('düzenle: çavuş verilirse eski şemada hata; gönderilmezse (false) görev yine güncellenir', $u['ok'] === false && pg_kisi_duzenle($p, $k[0], 'Örnek Kişi A', 'Yeni görev')['ok'] === true);

echo "\n=== 2. pg_migrate: kolon eklenir, idempotent, veri korunur ===\n";
$r1 = pg_migrate($p); $r2 = pg_migrate($p);
$d1 = array_column($r1, 'durum', 'tablo'); $d2 = array_column($r2, 'durum', 'tablo');
ok('ilk çalıştırma: iki tablo "var", puantaj_giris_kisiler.cavus_id "olusturuldu"', ($d1['puantaj_giris_kisiler.cavus_id'] ?? '') === 'olusturuldu' && $d1['puantaj_giris_kisiler'] === 'var', json_encode($r1, JSON_UNESCAPED_UNICODE));
ok('ikinci çalıştırma: kolon "var" (idempotent)', ($d2['puantaj_giris_kisiler.cavus_id'] ?? '') === 'var');
ok('kolon artık var; mevcut kişi ve görevi korundu', pg_cavus_kolon_var($p) === true && pg_kisi_bul($p, 1)['gorev'] === 'Yeni görev');
ok('kolon DDL: yabancı anahtar / DROP YOK', !preg_match('/FOREIGN|REFERENCES|DROP/i', implode(' ', pg_tablolar())));

echo "\n=== 3. Doğrulama: aktif çavuş / pasif / bilinmeyen / geçersiz ===\n";
$p = yeni(false);
ok('boş / null / 0 / "0" → bağ yok', array_map(fn($v) => pg_cavus_dogrula($p, $v)['id'], [null, '', 0, '0']) === [null, null, null, null]);
ok('aktif çavuş (int ve metin) kabul', pg_cavus_dogrula($p, 1)['id'] === 1 && pg_cavus_dogrula($p, '2')['id'] === 2);
ok('PASİF çavuş reddedilir; zaten bağlı olduğu pasif çavuş ($mevcut) kabul', pg_cavus_dogrula($p, 3)['ok'] === false && pg_cavus_dogrula($p, 3, 3)['id'] === 3);
ok('bilinmeyen id, negatif, ondalık, dizi reddedilir', pg_cavus_dogrula($p, 99)['ok'] === false && pg_cavus_dogrula($p, -1)['ok'] === false && pg_cavus_dogrula($p, '1.5')['ok'] === false && pg_cavus_dogrula($p, [1])['ok'] === false);
ok('pg_cavus_suz_dogrula: yok / sayı / çöp', pg_cavus_suz_dogrula('yok') === 'yok' && pg_cavus_suz_dogrula('7') === '7' && pg_cavus_suz_dogrula('abc') === '' && pg_cavus_suz_dogrula(['x']) === '' && pg_cavus_suz_dogrula('0') === '');

echo "\n=== 4. Ekle / düzenle ===\n";
$a = pg_kisi_ekle($p, 'Örnek Kişi A', 'Paketçi', 'Depo A', 1, 1);
$b = pg_kisi_ekle($p, 'Örnek Kişi B', '', 'Depo A', 1, '2');
$c = pg_kisi_ekle($p, 'Örnek Kişi C', '', 'Depo A', 1);
$z = pg_kisi_ekle($p, 'Örnek Kişi Z', '', 'Depo A', 1, 3);
ok('ekleme: çavuşlu / metin id / çavuşsuz başarılı; pasif çavuş reddi', $a['ok'] && $b['ok'] && $c['ok'] && !$z['ok'] && $z['hata'] === 'Pasif çavuşa bağlanamaz.');
ok('audit: çavuş kimliği var (ad YOK), çavuşsuzda anahtar yok', $a['audit'][3]['cavus_id'] === 1 && !array_key_exists('cavus_id', $c['audit'][3]) && !str_contains(json_encode($a['audit'], JSON_UNESCAPED_UNICODE), 'Örnek'));
$ka = pg_kisi_bul($p, $a['id']);
ok('okuma: cavus_id + çavuş adı (cavus_pasif false)', $ka['cavus_id'] === 1 && $ka['cavus'] === 'Çavuş Ayşe' && $ka['cavus_pasif'] === false);
$d = pg_kisi_duzenle($p, $ka, 'Örnek Kişi A', 'Paketçi', 2);
ok('düzenle: çavuş değişir (audit eski→yeni kimlik)', $d['ok'] && $d['islem'] === 'update' && $d['audit'][2]['cavus_id'] === 1 && $d['audit'][3]['cavus_id'] === 2 && pg_kisi_bul($p, $a['id'])['cavus_id'] === 2);
$ka = pg_kisi_bul($p, $a['id']);
ok('düzenle: çavuş alanı GÖNDERİLMEZSE (false) çavuş değişmez; hiçbir şey değişmediyse "degismedi"', pg_kisi_duzenle($p, $ka, 'Örnek Kişi A', 'Başka görev')['ok'] && pg_kisi_bul($p, $a['id'])['cavus_id'] === 2 && pg_kisi_duzenle($p, pg_kisi_bul($p, $a['id']), 'Örnek Kişi A', 'Başka görev')['islem'] === 'degismedi');
$ka = pg_kisi_bul($p, $a['id']);
ok('düzenle: ""/null → bağ kaldırılır', pg_kisi_duzenle($p, $ka, 'Örnek Kişi A', 'Başka görev', '')['ok'] && pg_kisi_bul($p, $a['id'])['cavus_id'] === null && pg_kisi_bul($p, $a['id'])['cavus'] === '');
ok('düzenle: pasif çavuş seçilemez; geçersiz reddedilir', pg_kisi_duzenle($p, pg_kisi_bul($p, $a['id']), 'Örnek Kişi A', 'Başka görev', 3)['ok'] === false && pg_kisi_duzenle($p, pg_kisi_bul($p, $a['id']), 'Örnek Kişi A', 'Başka görev', 'x')['ok'] === false);
// çavuş sonradan pasife alındı: bağlı kişide görünür kalır, ad aynen kalır, kaydedilebilir
$p->exec('UPDATE foremen SET is_active = 0 WHERE id = 2');
$kb = pg_kisi_bul($p, $b['id']);
ok('bağlı çavuş sonradan PASİF olursa kişide görünür kalır (cavus_pasif) ve aynı çavuşla yeniden kaydedilebilir', $kb['cavus'] === 'Çavuş Bekir' && $kb['cavus_pasif'] === true && pg_kisi_duzenle($p, $kb, 'Örnek Kişi B', 'Yeni', 2)['ok'] === true);
$p->exec('UPDATE foremen SET is_active = 1 WHERE id = 2');

echo "\n=== 5. Toplu bağlama ===\n";
$ids = [$a['id'], $b['id'], $c['id']];
$kis = pg_kisiler_idlerle($p, $ids);
ok('pg_kisiler_idlerle: çavuş adı dolu, anahtar = kimlik', isset($kis[$b['id']]) && $kis[$b['id']]['cavus'] === 'Çavuş Bekir');
$t = pg_kisi_cavus_toplu($p, $kis, 1);
ok('3 kişi → Çavuş Ayşe: etkilenen 3', $t['ok'] && $t['etkilenen'] === 3 && $t['atlanan'] === 0 && $t['cavus_id'] === 1);
$t = pg_kisi_cavus_toplu($p, pg_kisiler_idlerle($p, $ids), 1);
ok('aynı işlem tekrar: hepsi atlanır (etkilenen 0, atlanan 3)', $t['ok'] && $t['etkilenen'] === 0 && $t['atlanan'] === 3);
$p->exec('UPDATE puantaj_giris_kisiler SET aktif = 0 WHERE id = ' . (int)$c['id']);
$t = pg_kisi_cavus_toplu($p, pg_kisiler_idlerle($p, $ids), 2);
ok('PASİF kişi de güncellenir (3 etkilenen)', $t['ok'] && $t['etkilenen'] === 3 && pg_kisi_bul($p, $c['id'])['cavus_id'] === 2);
$t = pg_kisi_cavus_toplu($p, pg_kisiler_idlerle($p, [$a['id']]), '');
ok('boş çavuş = bağı kaldır', $t['ok'] && $t['cavus_id'] === null && pg_kisi_bul($p, $a['id'])['cavus_id'] === null);
$kotu = pg_kisi_cavus_toplu($p, pg_kisiler_idlerle($p, $ids), 3);
ok('pasif / bilinmeyen çavuş → hata, HİÇBİR kişi değişmez (hepsi ya da hiçbiri)', $kotu['ok'] === false && pg_kisi_bul($p, $b['id'])['cavus_id'] === 2 && pg_kisi_cavus_toplu($p, pg_kisiler_idlerle($p, $ids), 99)['ok'] === false);

echo "\n=== 6. Süzgeç + tablo + arama + dışa aktarım ===\n";
pg_kisi_cavus_toplu($p, pg_kisiler_idlerle($p, [$a['id']]), 1);   // A → Ayşe, B → Bekir, C → Bekir (pasif kişi)
$hepsi = pg_kisiler_oku($p, true);
$adlar = static fn(array $l): array => array_column($l, 'ad');
ok('süzgeç: Ayşe', $adlar(pg_cavus_suz($hepsi, '1')) === ['Örnek Kişi A']);
ok('süzgeç: Bekir (pasif kişi dahil, aktif/pasif ayrımı çağıranda)', count(pg_cavus_suz($hepsi, '2')) === 2);
ok('süzgeç: Çavuşsuz → boş; hepsi → tümü', pg_cavus_suz($hepsi, 'yok') === [] && count(pg_cavus_suz($hepsi, '')) === 3);
pg_kisi_cavus_toplu($p, pg_kisiler_idlerle($p, [$c['id']]), '');
ok('süzgeç: Çavuşsuz → yalnız C', $adlar(pg_cavus_suz(pg_kisiler_oku($p, true), 'yok')) === ['Örnek Kişi C']);
$t = pg_tablo($p, date('Y-m'), '', true);
$sa = array_column($t['satirlar'], null, 'ad');
ok('pg_tablo: satırda cavus adı + alt = görev (düzenleme penceresi görevi bozulmaz)', !isset($t['hata']) && $t['cavus_goster'] === true && $sa['Örnek Kişi A']['cavus'] === 'Çavuş Ayşe' && $sa['Örnek Kişi A']['alt'] === 'Başka görev');
ok('pg_tablo: çavuş süzgeci', array_column(pg_tablo($p, date('Y-m'), '', true, '2')['satirlar'], 'ad') === ['Örnek Kişi B'] && array_column(pg_tablo($p, date('Y-m'), '', true, 'yok')['satirlar'], 'ad') === ['Örnek Kişi C']);
ok('arama çavuş adıyla da bulur (TR duyarsız: "bekir")', array_column(pg_tablo($p, date('Y-m'), 'bekir', true)['satirlar'], 'ad') === ['Örnek Kişi B']);
ok('kişiler listesi süzgeci + arama', $adlar(pg_kisiler_filtrele($hepsi, '', 'hepsi', '1')) === ['Örnek Kişi A'] && count(pg_kisiler_filtrele(pg_kisiler_oku($p, true), 'ayse', 'hepsi')) === 1);
$bs = pg_disa_baslik($t); $rows = pg_disa_satirlar($t);
ok('dışa aktarım: Ad Soyad · Görev · Çavuş; satırda çavuş adı; "o gün çalışan"/genel toplam satırları aynı genişlikte', array_slice($bs, 0, 3) === ['Ad Soyad', 'Görev', 'Çavuş'] && count($rows[0]) === count($bs) && count($rows[count($rows) - 1]) === count($bs) && count($rows[count($rows) - 2]) === count($bs) && in_array('Çavuş Ayşe', array_column($rows, 2), true));
ok('xlsx sayfası: Çavuş sütunu', array_column(pg_xlsx_sayfa($t)['sutunlar'], 'baslik')[2] === 'Çavuş');

echo "\n=== 7. Excel: «Çavuş» sütunu ===\n";
$harita = pge_cavus_harita($p);
ok('harita: yalnız AKTİF çavuşlar, katlanmış ad; aynı ada iki çavuş (Mükerrer/MÜKERRER) → BELİRSİZ, dışarıda', $harita === ['cavus ayse' => 1, 'cavus bekir' => 2], json_encode($harita, JSON_UNESCAPED_UNICODE));
$ham = [['Ad Soyad', 'Görev', 'Çavuş'], ['Örnek Kişi P', 'x', 'çavuş ayşe'], ['Örnek Kişi Q', '', 'ÇAVUŞ BEKİR'], ['Örnek Kişi R', '', 'Çavuş Eski'], ['Örnek Kişi S', '', 'Mükerrer'], ['Örnek Kişi T', '', ''], ['Örnek Kişi U', '', 'Yok Böyle']];
$cz = pge_satirlari_coz($ham, [], $harita);
$sat = array_column($cz['satirlar'], null, 'ad');
ok('eşleşen çavuşlar bağlanır (TR duyarsız); eşleşmeyen / pasif / belirsiz → çavuşsuz ama satır GEÇERLİ', $sat['Örnek Kişi P']['cavus_id'] === 1 && $sat['Örnek Kişi Q']['cavus_id'] === 2 && $sat['Örnek Kişi R']['cavus_id'] === null && $sat['Örnek Kişi R']['cavus_durum'] === 'eslesmedi' && $sat['Örnek Kişi S']['cavus_durum'] === 'eslesmedi' && $sat['Örnek Kişi U']['durum'] === 'gecerli');
ok('boş çavuş hücresi durum "" (uyarı yok); özet.cavus_eslesmedi = 3', $sat['Örnek Kişi T']['cavus_durum'] === '' && $cz['ozet']['cavus_eslesmedi'] === 3 && $cz['ozet']['gecerli'] === 6);
$on = pge_onizleme_yaniti($cz);
ok('önizleme yanıtı çavuş + durum taşır', $on['ilk'][0]['cavus'] === 'çavuş ayşe' && $on['ilk'][2]['cavus_durum'] === 'eslesmedi');
$cn = pge_satirlari_coz($ham, [], null);
ok('çavuş özelliği kapalıyken (harita null) çavuş sütunu YOK SAYILIR', $cn['satirlar'][0]['cavus_id'] === null && $cn['satirlar'][0]['cavus'] === '' && $cn['ozet']['cavus_eslesmedi'] === 0);
ok('başlıksız dosyada çavuş sütunu aranmaz (cavus = -1)', pge_baslik_bul([['A', 'B', 'C']])['cavus'] === -1 && pge_baslik_bul([['Ad Soyad', 'Bağlı Çavuş']])['cavus'] === 1);
$p->exec('DELETE FROM puantaj_giris_kisiler');
$e = pge_kisileri_ekle($p, $cz['satirlar'], 'Depo A', 1);
$ek = array_column(pg_kisiler_oku($p, true), null, 'ad');
ok('pge_kisileri_ekle: eşleşen çavuş kimliği yazılır, diğerleri NULL', $e['ok'] && $e['eklenen'] === 6 && $ek['Örnek Kişi P']['cavus_id'] === 1 && $ek['Örnek Kişi Q']['cavus'] === 'Çavuş Bekir' && $ek['Örnek Kişi R']['cavus_id'] === null);
$ss = pge_sablon_uret(true); $ws = $ss->getSheet(0);
ok('şablon (çavuşlu): C1 "Çavuş"; çavuşsuz şablonda C1 boş', $ws->getCell('C1')->getValue() === 'Çavuş' && pge_sablon_uret(false)->getSheet(0)->getCell('C1')->getValue() === null);

echo "\n=== 8. Statik: kaydet ucu / arayüz / migrate ===\n";
$kaydet = (string)file_get_contents($ROOT . '/puantaj_giris_kaydet.php');
ok("kaydet: 'kisi_cavus' beyaz listede + toplu uygulayıcı + audit; çavuş alanı dizi/nesne reddi", str_contains($kaydet, "'kisi_cavus'") && str_contains($kaydet, 'pg_kisi_cavus_toplu(') && str_contains($kaydet, "'mod' => 'kisi_cavus'") && str_contains($kaydet, "'Geçersiz çavuş.'"));
$ui = (string)file_get_contents($ROOT . '/config/puantaj_giris_ui.php') . (string)file_get_contents($ROOT . '/config/puantaj_giris_kisiler_ui.php');
ok('arayüz: çavuş alanları YALNIZ $cavuslar !== null iken (kolon yoksa gizli); pasif çavuş seçeneği disabled', substr_count($ui, '$cavuslar !== null') >= 5 && str_contains($ui, "' disabled'") && str_contains($ui, 'id="pgCavus"') && str_contains($ui, 'id="pkCavusSec"'));
$sayfa = (string)file_get_contents($ROOT . '/puantaj_cizelgesi.php');
ok('sayfa: kolon yoksa çavuş listesi null (süzgeç/alan çizilmez); süzgeç GET parametresi doğrulanır', str_contains($sayfa, '$cavusListe = ($sekme === \'giris\' && pg_hazir($pdo) && pg_cavus_kolon_var($pdo))') && str_contains($sayfa, 'pg_cavus_suz_dogrula($_GET[\'cavus\']'));
$mig = (string)file_get_contents($ROOT . '/migrate.php');
ok('migrate kartı: kolon durumu ✓/✗ + düğme "Oluştur / Güncelle"', str_contains($mig, '✓ Bağlı çavuş kolonu var') && str_contains($mig, '✗ Bağlı çavuş kolonu eksik') && str_contains($mig, 'Oluştur / Güncelle'));
ok('foremen\'e YAZAN SQL yok (puantaj_giris* dosyaları)', !preg_match('/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?foremen/i', (string)file_get_contents($ROOT . '/config/puantaj_giris.php') . (string)file_get_contents($ROOT . '/config/puantaj_giris_excel.php') . $kaydet));

echo "\n----------------------------------------------\nGeçen: $gec   Kalan: $kal\n";
exit($kal === 0 ? 0 : 1);
