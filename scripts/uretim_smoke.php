<?php
declare(strict_types=1);
// =========================================================
// scripts/uretim_smoke.php — Günlük Üretim (v507) davranış + statik testi.
// Bellek içi SQLite; Excel'ler PhpSpreadsheet ile geçici dosyaya üretilir (canlı DB/dosyaya dokunmaz).
//   php scripts/uretim_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);
$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

$pass = 0; $fail = 0;
function okU(string $ad, bool $v, string $detay = ''): void {
    global $pass, $fail;
    if ($v) { $pass++; echo "PASS $ad\n"; } else { $fail++; echo "FAIL $ad" . ($detay !== '' ? " :: $detay" : '') . "\n"; }
}

// ── Personel tarafı için sahte (stub) fonksiyonlar: gerçek şema gerekmez ──
$GLOBALS['PERSONEL_VAR'] = true;
function pdks_gunluk_faz8a_sema_hazir($pdo = null): bool { return $GLOBALS['PERSONEL_VAR']; }
function pdks_faz8b_sema_hazir($pdo = null): bool { return $GLOBALS['PERSONEL_VAR']; }
function pdks_rapor_gunluk_trend($s, $e, $d, $f, $t, $pdo = null): array {
    return [
        ['tarih' => '2026-11-07', 'toplam_calisan' => 63, 'aktif_cavus' => 2],
        ['tarih' => '2026-11-08', 'toplam_calisan' => 50, 'aktif_cavus' => 2],   // mal girilmemiş gün
        ['tarih' => '2026-11-09', 'toplam_calisan' => 0, 'aktif_cavus' => 0],
    ];
}
function pdks_faz8b_gun_mesai_ozeti(array $ids, $pdo = null): ?array { return ['calisma_dk' => 611 * 60, 'fm_saat' => 44]; }

require_once $root . '/config/xlsx_export.php';
require_once $root . '/config/uretim.php';

// ── 1) Sayı / tarih / metin yardımcıları ──
okU('1a) uretim_sayi: "2   " → 2', uretim_sayi('2   ') === 2);
okU('1b) uretim_sayi: "1.575" → 1575', uretim_sayi('1.575') === 1575);
okU('1c) uretim_sayi: 94697.0 → 94697', uretim_sayi(94697.0) === 94697);
okU('1d) uretim_sayi: "abc" → null', uretim_sayi('abc') === null);
okU('1e) uretim_sayi: "12,5" → 13 (yuvarlanır)', uretim_sayi('12,5') === 13);
okU('1f) uretim_tarih: 07.11.2026', uretim_tarih('07.11.2026') === '2026-11-07');
okU('1g) uretim_tarih: 2026-11-07', uretim_tarih('2026-11-07') === '2026-11-07');
okU('1h) uretim_tarih: 31.02.2026 geçersiz', uretim_tarih('31.02.2026') === null);
okU('1i) uretim_tarih: Excel seri 46333 → 2026-11-07', uretim_tarih(46333) === '2026-11-07', (string)uretim_tarih(46333));
okU('1j) uretim_metin: NBSP + fazla boşluk temizlenir', uretim_metin("  NAR\u{00A0}K-10   İHRACAT  ") === 'NAR K-10 İHRACAT');

// ── 2) Toplu yapıştırma ──
$p = uretim_toplu_ayristir("Tarih;Net Kg;Kasa;Palet\n01.11.2026 ; 88.120 ; 17900 ; 108\n02.11.2026\t91340\t18500\t112\n02.11.2026;1;1;1\nbozuk;5\n03.11.2026;0\n");
okU('2a) başlık atlanır, 2 geçerli gün', count($p['gunler']) === 2, json_encode($p['gunler']));
okU('2b) "88.120" → 88120 kg', ($p['gunler'][0]['net_kg'] ?? 0) === 88120);
okU('2c) tekrar eden gün, bozuk tarih ve sıfır kg uyarı üretir', count($p['hatalar']) === 3, json_encode($p['hatalar'], JSON_UNESCAPED_UNICODE));

// ── 3) Excel — günlük «Sipariş Durum Listesi» (kullanıcının dosyasının yapısı, anonim müstahsil) ──
function gunlukExcelUret(string $yol, string $tarihSatiri, bool $bozukToplam = false): void {
    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $ss->getActiveSheet();
    $ws->setCellValue('A1', 'Sipariş Durum Listesi       ...\\URT027 ');
    $ws->setCellValue('A2', $tarihSatiri);
    foreach (['Ürün', 'Parti No', 'Ambalaj', 'Müstahsil', 'Palet Toplamı', 'Adet', 'Net Kilo'] as $i => $b) $ws->setCellValue([$i + 1, 3], $b);
    $satirlar = [
        ['NAR K-10 İHRACAT                       ', 'FRUITER             ', '30X40 PLASTİK KASA     ', 'FİRMA A                  ', '2                  ', 380, 1575],
        ['NAR K-12 İHRACAT                       ', '61-255              ', '11-12 AYAK PLASTİK KASA', 'FİRMA A                  ', '4                  ', 608, 3239],
        ['NAR K-9 İHRACAT                        ', 'ASYA TAHTA          ', 'TAHTA KASA 11-12 VİYOL ', 'ÜRETİCİ B                ', '1                  ', 200, 868],
    ];
    foreach ($satirlar as $r => $s) foreach ($s as $c => $v) $ws->setCellValue([$c + 1, 4 + $r], $v);
    $ws->setCellValue('F7', $bozukToplam ? 9999 : 1188);
    $ws->setCellValue('G7', $bozukToplam ? 1 : 5682);
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($yol);
}
$tmp = sys_get_temp_dir() . '/uretim_smoke_' . getmypid();
@mkdir($tmp);
$f1 = $tmp . '/gunluk.xlsx';
gunlukExcelUret($f1, 'İlk Tarih:07.11.2026 Son Tarih:07.11.2026 ');
$o = uretim_excel_oku($f1, 'gunluk.xlsx');
okU('3a) günlük Excel okunur', $o['ok'] === true, (string)($o['hata'] ?? ''));
okU('3b) mod = gunluk, tek gün, tarih 2026-11-07', ($o['mod'] ?? '') === 'gunluk' && count($o['gunler']) === 1 && $o['gunler'][0]['tarih'] === '2026-11-07');
okU('3c) toplam 5.682 kg · 1.188 kasa · 7 palet', $o['gunler'][0]['net_kg'] === 5682 && $o['gunler'][0]['kasa'] === 1188 && $o['gunler'][0]['palet'] === 7, json_encode($o['gunler'][0]['net_kg'] ?? null));
okU('3d) 3 kalem; boşluklar temizlendi, palet metni sayıya döndü', count($o['gunler'][0]['kalemler']) === 3 && $o['gunler'][0]['kalemler'][0]['urun'] === 'NAR K-10 İHRACAT' && $o['gunler'][0]['kalemler'][0]['palet'] === 2);
okU('3e) alt toplam tutuyorsa uyarı yok', $o['uyarilar'] === []);
$f2 = $tmp . '/bozuk.xlsx';
gunlukExcelUret($f2, 'İlk Tarih:07.11.2026 Son Tarih:07.11.2026 ', true);
$o2 = uretim_excel_oku($f2);
okU('3f) alt toplam tutmazsa uyarı verir (reddetmez)', $o2['ok'] === true && count($o2['uyarilar']) === 1);
$f3 = $tmp . '/cokgun.xlsx';
gunlukExcelUret($f3, 'İlk Tarih:01.11.2026 Son Tarih:07.11.2026 ');
$o3 = uretim_excel_oku($f3);
okU('3g) çok günlü liste reddedilir', $o3['ok'] === false && str_contains((string)$o3['hata'], 'birden çok gün'));
$f4 = $tmp . '/tarihsiz.xlsx';
gunlukExcelUret($f4, 'rapor');
okU('3h) tarih satırı yoksa reddedilir', uretim_excel_oku($f4)['ok'] === false);
file_put_contents($tmp . '/sahte.xlsx', 'bu excel degil');
okU('3i) bozuk dosya reddedilir (istisna sızmaz)', uretim_excel_oku($tmp . '/sahte.xlsx')['ok'] === false);

// ── 4) Excel — toplu tablo ──
$ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$ws = $ss->getActiveSheet();
foreach (['Tarih', 'Net Kilo', 'Adet', 'Palet'] as $i => $b) $ws->setCellValue([$i + 1, 1], $b);
$ws->setCellValue('A2', '01.11.2026'); $ws->setCellValue('B2', 88120); $ws->setCellValue('C2', 17900); $ws->setCellValue('D2', 108);
$ws->setCellValue('A3', '02.11.2026'); $ws->setCellValueExplicit('B3', '91.340', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);   // metin hücresi (binder sayıya çevirmesin)
$ws->setCellValue('A4', '03.11.2026'); $ws->setCellValue('B4', 0);
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($tmp . '/toplu.xlsx');
$ot = uretim_excel_oku($tmp . '/toplu.xlsx');
okU('4a) toplu Excel: mod = toplu, 2 gün (sıfır kg atlanır, uyarı verir)', ($ot['mod'] ?? '') === 'toplu' && count($ot['gunler']) === 2 && count($ot['uyarilar']) === 1);
okU('4b) "91.340" → 91340', ($ot['gunler'][1]['net_kg'] ?? 0) === 91340);

// ── 5) Kayıt / üzerine yaz / silme (SQLite) ──
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function sqliteCevir(string $mysql): array {
    preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m);
    $tablo = $m[1]; $kol = []; $idx = [];
    foreach (preg_split('/,\s*\n/', trim($m[2])) as $p) {
        $p = trim(preg_replace('/\s+/', ' ', $p));
        if (preg_match('/^UNIQUE KEY `([^`]+)` \((.+)\)$/i', $p, $mm)) { $idx[] = "CREATE UNIQUE INDEX `{$mm[1]}` ON `$tablo` ({$mm[2]})"; continue; }
        if (preg_match('/^(?:INDEX|KEY) `([^`]+)` \((.+)\)$/i', $p, $mm)) { $idx[] = "CREATE INDEX `{$mm[1]}` ON `$tablo` ({$mm[2]})"; continue; }
        $kol[] = preg_replace('/\bINT AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
    }
    return ["CREATE TABLE `$tablo` (" . implode(', ', $kol) . ')', $idx];
}
okU('5a) tablolar yokken uretim_hazir() false', uretim_hazir($db) === false);
$g = ['tarih' => '2026-11-07', 'net_kg' => 94697, 'kasa' => 19286, 'palet' => 117, 'kaynak' => 'excel', 'dosya' => 'k.xlsx', 'kalemler' => $o['gunler'][0]['kalemler']];
$r0 = uretim_gun_kaydet($db, $g, 'FİNİKE', 1, false);
okU('5b) tablo yokken kayıt temiz hata verir (istisna yok)', $r0['ok'] === false && str_contains((string)$r0['hata'], 'kurulu değil'));
foreach (uretim_tablolar() as $sql) { [$c, $ix] = sqliteCevir($sql); $db->exec($c); foreach ($ix as $i) $db->exec($i); }
okU('5c) tablolar kurulunca uretim_hazir() true', uretim_hazir($db) === true);
$r1 = uretim_gun_kaydet($db, $g, 'FİNİKE', 1, false);
okU('5d) ilk kayıt: create', $r1['ok'] === true && ($r1['eylem'] ?? '') === 'create');
okU('5e) kalemler yazıldı', (int)$db->query('SELECT COUNT(*) FROM uretim_gunluk_kalem')->fetchColumn() === 3);
$r2 = uretim_gun_kaydet($db, ['net_kg' => 100000] + $g, 'FİNİKE', 1, false);
okU('5f) aynı gün üzerine yaz işaretsiz → reddedilir (var=true), veri değişmez', $r2['ok'] === false && !empty($r2['var']) && (int)$db->query('SELECT net_kg FROM uretim_gunluk')->fetchColumn() === 94697);
$r3 = uretim_gun_kaydet($db, ['net_kg' => 100000, 'kalemler' => []] + $g, 'FİNİKE', 1, true);
okU('5g) üzerine yaz: update, kalemler yenilendi', $r3['ok'] === true && $r3['eylem'] === 'update' && (int)$db->query('SELECT COUNT(*) FROM uretim_gunluk_kalem')->fetchColumn() === 0 && (int)$db->query('SELECT net_kg FROM uretim_gunluk')->fetchColumn() === 100000);
$r4 = uretim_gun_kaydet($db, ['net_kg' => 5] + $g, 'BAŞKA DEPO', 1, false);
okU('5h) başka depoya aynı gün ayrı kayıt (depo bazlı tekil)', $r4['ok'] === true && (int)$db->query('SELECT COUNT(*) FROM uretim_gunluk')->fetchColumn() === 2);
okU('5i) gelecek tarih reddedilir', uretim_gun_kaydet($db, ['tarih' => date('Y-m-d', strtotime('+2 day'))] + $g, 'FİNİKE', 1, false)['ok'] === false);
okU('5j) 0 kg reddedilir', uretim_gun_kaydet($db, ['net_kg' => 0, 'tarih' => '2026-11-01'] + $g, 'FİNİKE', 1, false)['ok'] === false);
okU('5k) 5 milyon kg üstü (yazım hatası) reddedilir', uretim_gun_kaydet($db, ['net_kg' => 94697000, 'tarih' => '2026-11-01'] + $g, 'FİNİKE', 1, false)['ok'] === false);
okU('5l) başka depoda silme denemesi etkisiz', uretim_gun_sil($db, (int)$r1['id'], 'YANLIŞ') === false);
$db->exec("DELETE FROM uretim_gunluk WHERE depo='BAŞKA DEPO'");
uretim_gun_kaydet($db, $g, 'FİNİKE', 1, true);

// ── 6) Aylık hesap: 07.11 → 94697 / 63 işçi / 611 sa ──
$db->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, work_date TEXT, depo TEXT)");
$db->exec("INSERT INTO daily_work_sessions (work_date, depo) VALUES ('2026-11-07','FİNİKE'),('2026-11-07','FİNİKE')");
$v = uretim_ay_verisi($db, '2026-11', 'FİNİKE');
$gun = array_column($v['gunler'], null, 'tarih');
okU('6a) ay 30 gün listeler', count($v['gunler']) === 30);
okU('6b) 07.11: 63 işçi, kg/işçi = 1503', $gun['2026-11-07']['isci'] === 63 && $gun['2026-11-07']['kg_isci'] === 1503.0, json_encode($gun['2026-11-07']['kg_isci']));
okU('6c) 07.11: kg/saat = 155 (611 sa)', $gun['2026-11-07']['kg_saat'] === 155.0 && $gun['2026-11-07']['saat'] === 611.0, json_encode($gun['2026-11-07']['kg_saat']));
okU('6d) 07.11: kasa/işçi = 306', $gun['2026-11-07']['kasa_isci'] === 306.0);
okU('6e) 08.11: işçi var, mal yok → uretim_yok', $gun['2026-11-08']['durum'] === 'uretim_yok' && $gun['2026-11-08']['kg_isci'] === null);
okU('6f) 09.11: boş gün', $gun['2026-11-09']['durum'] === 'bos');
okU('6g) ay özeti yalnız eşleşen günlerden: 94697 kg ÷ 63', ($v['ozet']['kg_isci'] ?? 0) === 1503.0 && $v['ozet']['uretimsiz_isci_gun'] === 1);
okU('6h) ay toplam kg = 94697 (gün kg değil, ham toplam)', $v['ozet']['kg'] === 94697 && $v['ozet']['gun'] === 1);
$GLOBALS['PERSONEL_VAR'] = false;
$vp = uretim_ay_verisi($db, '2026-11', 'FİNİKE');
okU('6i) personel verisi yoksa kırılmaz: personel_var=false, oran null', $vp['personel_var'] === false && $vp['gunler'][6]['kg_isci'] === null && $vp['ozet']['kg'] === 94697);
$GLOBALS['PERSONEL_VAR'] = true;
okU('6j) uretim_oran: payda 0 → null', uretim_oran(10, 0) === null && uretim_oran(null, 5) === null);
$ay = uretim_aylik_ozet($db, 'FİNİKE', '2026-11', 12);
okU('6k) son 12 ay: 12 satır, son ay Kasım, kg 94697', count($ay) === 12 && $ay[11]['ay'] === '2026-11' && $ay[11]['kg'] === 94697 && $ay[0]['ay'] === '2025-12');
okU('6l) ay doğrulama', uretim_ay_gecerli('2026-11') === '2026-11' && uretim_ay_gecerli('2026-13') === null && uretim_ay_gecerli("2026-11'; DROP") === null);

// ── 7) Statik denetimler ──
$kaynak = static fn(string $f): string => (string)file_get_contents($root . '/' . $f);
$yukle = $kaynak('gunluk_uretim_yukle.php'); $rapor = $kaynak('gunluk_uretim.php'); $cfg = $kaynak('config/uretim.php');
okU('7a) yukle: csrf_check + can_uretim(write) + forbidden', str_contains($yukle, 'csrf_check(') && str_contains($yukle, "can_uretim('write')") && str_contains($yukle, 'forbidden('));
okU('7b) yukle: silme ayrı yetki (uretim.delete)', str_contains($yukle, "can_uretim('delete')"));
okU('7c) yukle: dosya diske kaydedilmez (move_uploaded_file YOK)', !str_contains($yukle, 'move_uploaded_file'));
okU('7d) yukle: yalnız .xlsx + PK sihirli baytı', str_contains($yukle, "'xlsx'") && str_contains($yukle, 'PK\\x03\\x04'));
okU('7e) rapor: can_uretim(read) kapısı', str_contains($rapor, "can_uretim('read')"));
okU('7f) rapor: JSON etiket kaçışı (HEX_TAG)', str_contains($rapor, 'JSON_HEX_TAG'));
okU('7g) okuyucu: ReadDataOnly + getCalculatedValue YOK', str_contains($cfg, 'setReadDataOnly(true)') && !str_contains($cfg, 'getCalculatedValue('));
okU('7h) audit: create/update/delete', substr_count($cfg, 'audit_log_event(') >= 2);
okU('7i) kayıt kurulumu yalnız migrate.php kartında', str_contains($kaynak('migrate.php'), "'uretim'") && !str_contains($rapor . $yukle, 'uretim_migrate('));
okU('7j) yetkiler katalogda + seed\'de', str_contains($kaynak('config/auth.php'), "'uretim.read'") && str_contains($kaynak('config/helpers.php'), "'uretim.read','uretim.write','uretim.delete'"));
okU('7k) nav ailesinde iki sayfa', str_contains($kaynak('config/helpers.php'), "'gunluk_uretim.php', 'gunluk_uretim_yukle.php'"));
okU('7l) Chart.js yerel dosya, CDN yok', is_file($root . '/assets/chart.umd.min.js') && !str_contains($rapor, 'cdn.') && !str_contains($rapor, 'jsdelivr'));
okU('7m) SW sürümü = APP_SURUM', preg_match("/yukleme-plani-(v\\d+)/", $kaynak('sw.js'), $a1) && preg_match("/define\\('APP_SURUM', '(v\\d+)'\\)/", $kaynak('config/helpers.php'), $a2) && $a1[1] === $a2[1]);

foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmp);
echo "\n$pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
