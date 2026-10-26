<?php
// =========================================================
// scripts/siparis_invoice_smoke.php — OGL Invoice üretici (v442, config/siparis_invoice.php)
//   php scripts/siparis_invoice_smoke.php   → çıkış kodu 0 = tüm testler geçti
// Bellek içi SQLite + geçici belge klasörü + SENTETİK veri (gerçek IBAN / REX / kişi YOK); canlı DB'ye ve
// storage/siparis/'e dokunmaz. Kapsam: hesap, girdi doğrulama, ayar (hks_kv + audit'te IBAN/REX YOK), PKL özeti,
// çapraz kontroller, HTML (menşe beyanı HER ZAMAN, XSS), PDF, üretim → belge → bağ → yeniden üretim,
// eksik REX izni, e-fatura (görüntü-yalnız PDF + metin katmanlı PDF), yan etkiler (belge türü etiketleri, ZIP).
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/siparis_invoice.php';
ini_set('memory_limit', '512M');

$db = db();
siparis_test_diger_tablolar($db);
siparis_test_sema_kur($db);

// ── sentetik IBAN / REX (bu dosyaya özgü; kod ve audit'te aranır) ──
const T_IBAN = 'TR330006100519786457841326';   // yaygın bilinen geçerli TEST IBAN (sentetik)
const T_REX = 'TRREXTEST000001';
const T_SWIFT = 'TESTTRXX';

echo "=== 1. Statik ===\n";
$kaynak = (string)file_get_contents($ROOT . '/config/siparis_invoice.php');
ok('declare(strict_types=1)', str_contains(substr($kaynak, 0, 2000), 'declare(strict_types=1)'));
ok('exec / shell / eval / PhpOffice yok', !preg_match('/\b(shell_exec|passthru|proc_open|popen|eval)\s*\(|(?<!->)\bexec\s*\(|PhpOffice|pdftotext\s/', preg_replace('#//.*$#m', '', $kaynak) ?? $kaynak));
ok('kodda IBAN / REX değeri yok', !preg_match('/\bTR\d{2}\s?\d{4}/', $kaynak) && !preg_match('/REX\s*(no)?\s*[:=]\s*[A-Z0-9]{8,}/', $kaynak));
ok('dompdf ayarları: uzak kaynak + PHP kapalı, DejaVu', str_contains($kaynak, "set('isRemoteEnabled', false)") && str_contains($kaynak, "set('isPhpEnabled', false)") && str_contains($kaynak, "DejaVu Sans"));
ok('menşe beyanı şablonda koşulsuz: sabit metin + COUNTRY OF ORIGIN TURKEY', str_contains($kaynak, 'declares that, except where otherwise clearly indicated, these products are of Turkey preferential origin')
    && str_contains($kaynak, "['COUNTRY OF ORIGIN', 'TURKEY']"));
ok('sevkiyat belge türü etiketleri: invoice + efatura', (siparis_belge_tur_etiketleri()['invoice'] ?? '') === 'Invoice' && (siparis_belge_tur_etiketleri()['efatura'] ?? '') === 'e-Fatura');
ok("olay türü 'invoice_uretildi' tanımlı", in_array('invoice_uretildi', SIPARIS_OLAY_TURLERI, true));
$zip = (string)file_get_contents($ROOT . '/siparis_zip.php');
ok('ZIP: üretilen invoice dosya adına ikinci önek eklenmez', str_contains($zip, "in_array((string)\$b['tur'], ['pkl', 'invoice'], true)"));

echo "\n=== 2. Hesap (TEK yer) ===\n";
$h = siparis_inv_hesapla(['fiyat' => '1.37', 'iskonto' => 4.0], ['net_kg' => 18200]);
ok('18.200 kg × 1,37 = 24.934,00', $h['tutar_kurus'] === 2493400 && $h['tutar'] === 24934.0);
ok('iskonto %4 = 997,36; toplam 23.936,64', $h['iskonto_kurus'] === 99736 && $h['toplam_kurus'] === 2393664 && abs($h['iskonto'] - 997.36) < 1e-9 && abs($h['toplam'] - 23936.64) < 1e-9);
$h = siparis_inv_hesapla(['fiyat' => '0.335', 'iskonto' => 0], ['net_kg' => 3]);
ok('kuruşa yarım yukarı: 3 kg × 0,335 = 1,005 → 1,01', $h['tutar_kurus'] === 101 && $h['toplam_kurus'] === 101);
$h = siparis_inv_hesapla(['fiyat' => '1.37', 'iskonto' => 0], ['net_kg' => 18200.5]);
ok('ondalıklı net kg (g duyarlığı): 18.200,5 × 1,37 = 24.934,685 → 24.934,69', $h['tutar_kurus'] === 2493469, (string)$h['tutar_kurus']);
$h = siparis_inv_hesapla(['fiyat' => '1.37', 'iskonto' => 4.5], ['net_kg' => 18200]);
ok('iskonto %4,5 = 1.122,03 (yarım yukarı)', $h['iskonto_kurus'] === 112203 && $h['toplam_kurus'] === 2493400 - 112203, (string)$h['iskonto_kurus']);
$h = siparis_inv_hesapla(['fiyat' => '1.37', 'iskonto' => 100], ['net_kg' => 18200]);
ok('iskonto %100 → toplam 0', $h['toplam_kurus'] === 0);
$h = siparis_inv_hesapla(['fiyat' => '1.3750', 'iskonto' => 0], ['net_kg' => 1000]);
ok('4 ondalıklı fiyat: 1.000 kg × 1,375 = 1.375,00', $h['tutar_kurus'] === 137500);
ok('toplam = tutar − iskonto (kuruş tutarlılığı)', siparis_inv_hesapla(['fiyat' => '1.37', 'iskonto' => 3.33], ['net_kg' => 12345])['toplam_kurus']
    === siparis_inv_hesapla(['fiyat' => '1.37', 'iskonto' => 3.33], ['net_kg' => 12345])['tutar_kurus'] - siparis_inv_hesapla(['fiyat' => '1.37', 'iskonto' => 3.33], ['net_kg' => 12345])['iskonto_kurus']);

echo "\n=== 3. Girdi doğrulama ===\n";
$t = fn(array $p) => siparis_inv_girdi_temizle($p + ['no' => 'IHR2026000000340', 'tarih' => '05.10.2026', 'fiyat' => '1,37', 'iskonto' => '4', 'efatura_toplam' => '']);
$r = $t([]);
ok('geçerli girdi: tarih Y-m-d, fiyat 1.37, iskonto 4.0', $r['ok'] && $r['veri']['tarih'] === '2026-10-05' && $r['veri']['fiyat'] === '1.37' && $r['veri']['iskonto'] === 4.0 && $r['veri']['efatura_toplam'] === '' && $r['hata'] === [], json_encode($r));
ok('fiyat "1.37" (noktalı) = 1,37', $t(['fiyat' => '1.37'])['veri']['fiyat'] === '1.37');
ok('fiyat "1,37" (virgüllü) = 1,37 (100× HATA YOK)', $t(['fiyat' => '1,37'])['veri']['fiyat'] === '1.37');
ok('fiyat "1.370" birim fiyatta ondalık (1370 DEĞİL)', $t(['fiyat' => '1.370'])['veri']['fiyat'] === '1.37');
ok('fiyat "€ 1,3755" → 1.3755 (4 ondalık korunur); "€ 1,3750" → 1.375', $t(['fiyat' => '€ 1,3755'])['veri']['fiyat'] === '1.3755' && $t(['fiyat' => '€ 1,3750'])['veri']['fiyat'] === '1.375');
ok('fiyat "2" → 2.00', $t(['fiyat' => '2'])['veri']['fiyat'] === '2.00');
foreach (['' => 'boş', '0' => 'sıfır', 'abc' => 'harf', '1,37891' => '5 ondalık', '1.234,56' => '≥ 1000', '-1' => 'negatif', '1,2,3' => 'çoklu ayraç'] as $kotu => $ad) {
    ok("fiyat reddedilir: $ad ('$kotu')", isset($t(['fiyat' => (string)$kotu])['hata']['fiyat']) && !$t(['fiyat' => (string)$kotu])['ok']);
}
ok('e-fatura toplamı "1.234,56" = 1234.56', $t(['efatura_toplam' => '1.234,56'])['veri']['efatura_toplam'] === '1234.56');
ok('e-fatura toplamı "12.500" tutarda binlik = 12500.00', $t(['efatura_toplam' => '12.500'])['veri']['efatura_toplam'] === '12500.00');
ok('e-fatura toplamı "23.936,64"', $t(['efatura_toplam' => '23.936,64'])['veri']['efatura_toplam'] === '23936.64');
ok('e-fatura toplamı geçersiz reddedilir', isset($t(['efatura_toplam' => '12x'])['hata']['efatura_toplam']));
ok('tarih Y-m-d de kabul; geçersiz reddedilir', $t(['tarih' => '2026-10-05'])['veri']['tarih'] === '2026-10-05' && isset($t(['tarih' => '31.02.2026'])['hata']['tarih']) && isset($t(['tarih' => ''])['hata']['tarih']));
ok('iskonto boş = 0; 4,5 = 4.5; 101 ve negatif reddedilir; 3 ondalık reddedilir', $t(['iskonto' => ''])['veri']['iskonto'] === 0.0 && $t(['iskonto' => '4,5'])['veri']['iskonto'] === 4.5
    && isset($t(['iskonto' => '101'])['hata']['iskonto']) && isset($t(['iskonto' => '-1'])['hata']['iskonto']) && isset($t(['iskonto' => '4,123'])['hata']['iskonto']));
ok('invoice no zorunlu; bozuk karakter reddedilir', isset($t(['no' => ''])['hata']['no']) && isset($t(['no' => 'IHR<script>'])['hata']['no']) && $t(['no' => '  IHR2026000000340  '])['veri']['no'] === 'IHR2026000000340');
ok('formül enjeksiyonu / HTML invoice noda reddedilir', isset($t(['no' => '=HYPERLINK("x")'])['hata']['no']));

echo "\n=== 4. Kalibre açıklaması + yardımcılar ===\n";
ok('10,12 → "10-12 PC."', siparis_inv_kalibre_aralik(['12', '10']) === '10-12 PC.');
ok('tek kalibre → "10 PC."', siparis_inv_kalibre_aralik(['10']) === '10 PC.' && siparis_inv_kalibre_aralik(['10', '10']) === '10 PC.');
ok('10,12,14 → "10-14 PC." (sayısal sıra: 9 < 10)', siparis_inv_kalibre_aralik(['14', '9', '12']) === '9-14 PC.');
ok('sayısal değilse olduğu gibi (alfabetik)', siparis_inv_kalibre_aralik(['XL', 'L']) === 'L-XL PC.' && siparis_inv_kalibre_aralik(['L']) === 'L PC.');
ok('kalibre yok → boş', siparis_inv_kalibre_aralik([]) === '' && siparis_inv_kalibre_aralik(['', ' ']) === '');
ok('ürün adı: POMEGRANATE → FRESH POMEGRANATE; zaten FRESH ise dokunulmaz; boş → ""', siparis_inv_urun_ad('pomegranate') === 'FRESH POMEGRANATE'
    && siparis_inv_urun_ad('FRESH POMEGRANATE') === 'FRESH POMEGRANATE' && siparis_inv_urun_ad('') === '');
ok('dosya adı INV-<ref>-<no>-<gg.aa.yyyy>.pdf', siparis_inv_dosya_adi('TC418932.10', 'AsyaDoc003', '2026-10-05') === 'INV-TC418932.10-AsyaDoc003-05.10.2026.pdf');
ok('dosya adı: belge no yoksa atlanır; parçalar temizlenir', siparis_inv_dosya_adi('TC1/../x_', '', '2026-10-05') === 'INV-TC1..x-05.10.2026.pdf');
ok('Incoterms kodu çıkarımı', siparis_inv_incoterm_kodu('INCOTERMS 2020 DAP') === 'DAP' && siparis_inv_incoterm_kodu('exw') === 'EXW' && siparis_inv_incoterm_kodu('serbest') === '');
ok('gösterim: tutar 1.234,56; fiyat 1,37 / 1,3750; tarih gg.aa.yyyy', siparis_inv_tutar_goster(1234.56) === '1.234,56' && siparis_inv_fiyat_goster('1.37') === '1,37'
    && siparis_inv_fiyat_goster('1.3755') === '1,3755' && siparis_inv_tarih_goster('2026-10-05') === '05.10.2026');

echo "\n=== 5. Ayar (hks_kv) + audit ===\n";
$v = siparis_inv_ayar_varsayilan();
ok('hks_kv yokken: varsayılan ayar (teslim DAP, iskonto 0) ve eksikler', siparis_inv_ayar_oku($db) === $v && $v['teslim'] === 'INCOTERMS 2020 DAP' && $v['iskonto_varsayilan'] === 0.0
    && count(siparis_inv_ayar_eksikler($v)) === 6);
$x = siparis_inv_ayar_kaydet($db, ['rex_no' => T_REX], 1);
ok('hks_kv yokken kaydetme reddedilir (hata, ayar varsayılan)', !$x['ok'] && str_contains((string)$x['hata'], 'hks_kv') && $x['ayar'] === $v);
$db->exec('CREATE TABLE hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)');
ok('hks_kv varken ayar yok → varsayılan', siparis_inv_ayar_oku($db) === $v);
foreach ([['iban_eur' => 'TR12'], ['iban_eur' => 'XX'], ['swift' => 'ABC'], ['swift' => 'TESTTRXXXXXX'], ['rex_no' => 'AB-12'], ['rex_no' => str_repeat('A', 31)],
          ['iskonto_varsayilan' => '101'], ['iskonto_varsayilan' => 'x'], ['banka_ad' => str_repeat('b', 101)]] as $kotu) {
    $x = siparis_inv_ayar_kaydet($db, $kotu, 1);
    ok('ayar reddedilir: ' . json_encode($kotu), !$x['ok'] && !empty($x['hata']));
}
ok('reddedilen ayar hiçbir şey yazmaz', siparis_inv_ayar_oku($db) === $v && siparis_test_say($db, 'hks_kv') === 0);
$x = siparis_inv_ayar_kaydet($db, ['rex_no' => ' trrex test-ish ', 'banka_ad' => 'TEST BANKASI A.S.'], 1);
ok('REX boşluk/tire içeriyorsa reddedilir (alfasayısal)', !$x['ok']);
$x = siparis_inv_ayar_kaydet($db, ['rex_no' => ' trrextest000001 ', 'banka_ad' => 'TEST BANKASI A.S.', 'banka_adres' => 'Test Mah. Test Bulv. No:1, Samsun / TURKEY', 'sube' => 'TEST ŞUBESİ',
    'swift' => ' testtrxx ', 'iban_eur' => strtolower(substr(T_IBAN, 0, 4)) . ' ' . substr(T_IBAN, 4, 4) . ' ' . substr(T_IBAN, 8), 'teslim' => '', 'iskonto_varsayilan' => '4'], 7);
ok('ayar kaydedildi: REX/IBAN/SWIFT büyük harf + boşluksuz, teslim boşsa varsayılan', $x['ok'] && $x['ayar']['rex_no'] === T_REX && $x['ayar']['iban_eur'] === T_IBAN && $x['ayar']['swift'] === T_SWIFT
    && $x['ayar']['teslim'] === 'INCOTERMS 2020 DAP' && $x['ayar']['iskonto_varsayilan'] === 4.0, json_encode($x));
$ayar = siparis_inv_ayar_oku($db);
ok('okuma kayıtlı ayarı verir; eksik kalmadı', $ayar === $x['ayar'] && siparis_inv_ayar_eksikler($ayar) === []);
$x = siparis_inv_ayar_kaydet($db, ['sube' => 'BAŞKA ŞUBE'], 7);
ok('gönderilmeyen anahtar mevcut değerini korur', $x['ok'] && $x['ayar']['rex_no'] === T_REX && $x['ayar']['sube'] === 'BAŞKA ŞUBE');
siparis_inv_ayar_kaydet($db, ['sube' => 'TEST ŞUBESİ'], 7);
siparis_inv_ayar_kaydet($db, ['sube' => 'TEST ŞUBESİ'], 7);          // değişiklik yok → audit yok
$aud = array_values(array_filter(siparis_test_audit($db), fn($a) => $a['action'] === 'siparis_invoice_ayar'));
ok('audit: yalnız değişen alan ADLARI (ilk kayıt 8 alan, sonra sube, sube; değişmeyen çağrı yazmaz)', count($aud) === 3
    && json_decode($aud[0]['new_values'], true)['degisen_alanlar'] === ['rex_no', 'banka_ad', 'banka_adres', 'sube', 'swift', 'iban_eur', 'iskonto_varsayilan']
    && json_decode($aud[1]['new_values'], true)['degisen_alanlar'] === ['sube'], json_encode(array_column($aud, 'new_values')));
$tumAudit = json_encode($db->query('SELECT * FROM audit_log')->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
ok('audit\'te IBAN / REX / SWIFT / banka DEĞERİ YOK', !str_contains($tumAudit, T_IBAN) && !str_contains($tumAudit, T_REX) && !str_contains($tumAudit, T_SWIFT) && !str_contains($tumAudit, 'TEST BANKASI') && !str_contains($tumAudit, 'Test Mah'));
$ham = (string)$db->query("SELECT deger FROM hks_kv WHERE anahtar = 'siparis_invoice_ayar'")->fetchColumn();
ok('ayar hks_kv anahtarı siparis_invoice_ayar (JSON)', is_array(json_decode($ham, true)) && str_contains($ham, 'rex_no'));
$db->exec("REPLACE INTO hks_kv (anahtar, deger) VALUES ('siparis_invoice_ayar', '{bozuk')");
ok('bozuk JSON → varsayılan (fail soft)', siparis_inv_ayar_oku($db) === $v);
$db->exec("DELETE FROM hks_kv");
siparis_inv_ayar_kaydet($db, ['rex_no' => T_REX, 'banka_ad' => 'TEST BANKASI A.S.', 'banka_adres' => 'Test Mah. Test Bulv. No:1, Samsun / TURKEY', 'sube' => 'TEST ŞUBESİ',
    'swift' => T_SWIFT, 'iban_eur' => T_IBAN, 'iskonto_varsayilan' => '4'], 7);
$ayar = siparis_inv_ayar_oku($db);

echo "\n=== 6. Sevkiyat + PKL özeti ===\n";
$r = siparis_test_isle($db, siparis_test_fixture('po_uc_kalibre'), 'po3');
ok('sentetik PO işlendi (2 sevkiyat)', $r['isle']['ok'] && count($r['isle']['sevkiyatlar']) === 2);
$sidPkl = (int)siparis_test_sev($db, 'TC900012.10')['id'];       // PKL'li sevkiyat (kalibre 10 + 12)
$sidYok = (int)siparis_test_sev($db, 'TC900011.10')['id'];       // PKL'siz sevkiyat (kalem yedeği)
$db->exec("UPDATE siparis_kalemler SET etkin_palet = 0, etkin_koli = 0 WHERE sevkiyat_id = $sidPkl AND kalibre = '14'");
$db->exec("UPDATE siparis_kalemler SET etkin_palet = 8, etkin_koli = 1600 WHERE sevkiyat_id = $sidPkl AND kalibre = '10'");
$db->exec("UPDATE siparis_sevkiyatlar SET plakalar = '34TST001-34TST002', navlun_no = '6100000001' WHERE id = $sidPkl");
$sev0 = siparis_sevkiyat($db, $sidPkl);
$v0 = siparis_inv_varsayilan($db, $sev0);
ok('PKL yokken: kaynak "kalem", brüt 0, uyarı notu, palet 26 / koli 5200 / net 18.200 (koli × 3,5)', $v0['ozet']['kaynak'] === 'kalem' && $v0['ozet']['palet'] === 26 && $v0['ozet']['koli'] === 5200
    && (float)$v0['ozet']['brut_kg'] === 0.0 && (float)$v0['ozet']['net_kg'] === 18200.0 && count($v0['notlar']) === 1 && str_contains($v0['notlar'][0], 'PKL yok'), json_encode($v0));
ok('varsayılan girdi: tarih bugün, iskonto ayar varsayılanı (4), no/fiyat boş', $v0['girdi'] === ['no' => '', 'tarih' => date('Y-m-d'), 'fiyat' => '', 'iskonto' => 4.0, 'efatura_toplam' => '', 'net_kg' => '', 'brut_kg' => '']);
$d = siparis_pkl_durum($db, $sidPkl)['veri'];
foreach ($d['bloklar'] as &$bl) { $bl['M'] = 820; $bl['N'] = 700; } unset($bl);
ok('PKL taslağı (brüt 820 / net 700 palet başına) kaydedildi', siparis_pkl_kaydet_taslak($db, $sidPkl, $d, [], 1)['ok']);
$x = siparis_pkl_uret($db, $sidPkl, 1, true);
ok('PKL üretildi (kısmi)', $x['ok'], json_encode($x));
$v1 = siparis_inv_varsayilan($db, siparis_sevkiyat($db, $sidPkl));
$o = $v1['ozet'];
ok('PKL özeti: kaynak pkl, 26 palet, 5.200 koli, brüt 21.320, net 18.200', $o['kaynak'] === 'pkl' && $o['palet'] === 26 && $o['koli'] === 5200 && (float)$o['brut_kg'] === 21320.0 && (float)$o['net_kg'] === 18200.0 && $v1['notlar'] === [], json_encode($o));
ok('kalibreler 10, 12; açıklama "FRESH TESTFRUIT 10-12 PC."', $o['kalibreler'] === ['10', '12'] && $o['aciklama'] === 'FRESH TESTFRUIT 10-12 PC.');
ok('belge no AsyaDoc001, harvest 2026, Incoterms EXW (PKL), plaka PKL C8, order_ref', $o['belge_no'] === 'AsyaDoc001' && $o['harvest'] === '2026' && $o['incoterms_pkl'] === 'EXW'
    && $o['plaka'] === '34TST001-34TST002' && $o['order_ref'] === 'TC900012.10' && $o['paket'] === 'CARTON BOXES');
$pkd = siparis_pkl_durum($db, $sidPkl)['pkl'];
ok('PKL ozeti kayıtlı PKL satırıyla tutarlı (belge no)', $pkd['belge_no'] === $o['belge_no']);
$db->exec("UPDATE siparis_belgeler SET deleted_at = '2026-01-01 00:00:00' WHERE tur = 'pkl'");
ok('PKL belgesi silinmişse özet kalem yedeğine düşer', siparis_inv_varsayilan($db, siparis_sevkiyat($db, $sidPkl))['ozet']['kaynak'] === 'kalem');
$db->exec("UPDATE siparis_belgeler SET deleted_at = NULL WHERE tur = 'pkl'");

echo "\n=== 7. Çapraz kontroller ===\n";
$sevP = siparis_sevkiyat($db, $sidPkl);
$gv = ['no' => 'IHR2026000000340', 'tarih' => '2026-10-05', 'fiyat' => '1.37', 'iskonto' => 4.0, 'efatura_toplam' => '23936.64'];
$kod = fn(array $k): array => array_column($k, 'seviye', 'kod');
$k = siparis_inv_kontrol($db, $sevP, $gv, $o, $ayar);
ok('tam ve tutarlı girdi: hata YOK, e-fatura farkı YOK, PKL yok uyarısı YOK', !array_filter($k, fn($x) => $x['seviye'] === 'hata') && !isset($kod($k)['efatura_fark']) && !isset($kod($k)['pkl_yok']), json_encode($k));
ok('Incoterms uyuşmazlığı (PKL EXW ≠ ayar DAP) → bilgi', ($kod($k)['incoterms'] ?? '') === 'bilgi');
ok('seviye anahtarları yalnız hata|uyari|bilgi; her satırda mesaj', !array_filter($k, fn($x) => !in_array($x['seviye'], ['hata', 'uyari', 'bilgi'], true) || $x['mesaj'] === ''));
$k = siparis_inv_kontrol($db, $sevP, ['efatura_toplam' => '23936.00'] + $gv, $o, $ayar);
ok('e-fatura toplamı ≠ hesaplanan → uyarı (engel değil)', ($kod($k)['efatura_fark'] ?? '') === 'uyari' && !array_filter($k, fn($x) => $x['seviye'] === 'hata'));
$k = siparis_inv_kontrol($db, $sevP, ['no' => '12345'] + $gv, $o, $ayar);
ok('invoice no IHR\\d{13} değilse uyarı', ($kod($k)['no_bicim'] ?? '') === 'uyari');
$k = siparis_inv_kontrol($db, $sevP, ['fiyat' => '', 'tarih' => ''] + $gv, $o, $ayar);
ok('fiyat / tarih eksikse HATA', ($kod($k)['girdi_fiyat'] ?? '') === 'hata' && ($kod($k)['girdi_tarih'] ?? '') === 'hata');
$k = siparis_inv_kontrol($db, $sevP, $gv, ['plaka' => ''] + $o, $ayar);
ok('plaka boş → uyarı', ($kod($k)['plaka_yok'] ?? '') === 'uyari');
$k = siparis_inv_kontrol($db, $sevP, $gv, $o, ['rex_no' => ''] + $ayar);
ok('REX eksik → uyarı (rex_eksik)', ($kod($k)['rex_eksik'] ?? '') === 'uyari');
$k = siparis_inv_kontrol($db, $sevP, $gv, $o, ['iban_eur' => '', 'swift' => ''] + $ayar);
ok('banka ayarı eksik → uyarı (banka_eksik)', ($kod($k)['banka_eksik'] ?? '') === 'uyari');
$k = siparis_inv_kontrol($db, $sevP, $gv, ['net_kg' => 0.0] + $o, $ayar);
ok('net kg 0 → HATA', ($kod($k)['net_yok'] ?? '') === 'hata');
$k = siparis_inv_kontrol($db, $sevP, $gv, ['brut_kg' => 100.0] + $o, $ayar);
ok('brüt < net → HATA', ($kod($k)['brut_net'] ?? '') === 'hata');
$k = siparis_inv_kontrol($db, $sevP, $gv, ['net_kg' => 15000.0] + $o, $ayar);
ok('PKL net ≠ nominal (koli × içerik, > %0,5) → uyarı net_nominal (net_fark kalktı)', ($kod($k)['net_nominal'] ?? '') === 'uyari' && !isset($kod($k)['net_fark']));
$k = siparis_inv_kontrol($db, $sevP, $gv, ['palet' => 25] + $o, $ayar);
ok('PKL palet ≠ kalem toplamı → uyarı', ($kod($k)['palet_fark'] ?? '') === 'uyari');
$k = siparis_inv_kontrol($db, siparis_sevkiyat($db, $sidYok), $gv, $v0['ozet'], $ayar);
ok('PKL yok → uyarı (pkl_yok), net tahmini var → engel değil', ($kod($k)['pkl_yok'] ?? '') === 'uyari' && !array_filter($k, fn($x) => $x['seviye'] === 'hata'));
$k = siparis_inv_kontrol($db, $sevP, $gv, $o, ['teslim' => 'INCOTERMS 2020 EXW'] + $ayar);
ok('Incoterms aynıysa bilgi YOK', !isset($kod($k)['incoterms']));


echo "\n=== 7b. Net / brüt override (PKL toplamı ≠ nominal) ===\n";
// PKL: net 18.200 (= nominal); gerçek veride PKL 18.486 / brüt 20.826 iken e-fatura 18.200 / 21.300 kullanır.
$oG = ['net_kg' => 18486.0, 'brut_kg' => 20826.0, 'net_nominal_kg' => 18200.0] + $o;
$gvG = ['net_kg' => '', 'brut_kg' => ''] + $gv;
unset($gvG['efatura_toplam']);
ok('özet: net_nominal_kg = koli × koli içeriği (5.200 × 3,5 = 18.200)', (float)$o['net_nominal_kg'] === 18200.0 && (float)$v1['ozet']['net_nominal_kg'] === 18200.0 && (float)$v0['ozet']['net_nominal_kg'] === 18200.0);
$k = siparis_inv_kontrol($db, $sevP, $gvG, $oG, $ayar);
ok('override yok, PKL net 18.486 ≠ nominal 18.200 → net_nominal UYARI (mesaj iki rakamı da söyler)', ($kod($k)['net_nominal'] ?? '') === 'uyari'
    && (bool)array_filter($k, fn($x) => $x['kod'] === 'net_nominal' && str_contains($x['mesaj'], '18.486') && str_contains($x['mesaj'], '18.200') && str_contains($x['mesaj'], 'hangisini kullanıyorsa')) && !isset($kod($k)['net_elle']));
$gvO = ['net_kg' => '18.200', 'brut_kg' => '21300'] + $gvG;
$tO = siparis_inv_girdi_temizle($gvO);
ok('girdi: net_kg "18.200" → 18200, brüt "21300" → 21300 (tam sayı)', $tO['ok'] && $tO['veri']['net_kg'] === 18200 && $tO['veri']['brut_kg'] === 21300, json_encode($tO));
ok('girdi: boş net/brüt → "" (PKL toplamı); ondalık / 0 / negatif / harf reddedilir', siparis_inv_girdi_temizle(['net_kg' => '', 'brut_kg' => ' '] + $gvG)['veri']['net_kg'] === ''
    && isset(siparis_inv_girdi_temizle(['net_kg' => '18200,5'] + $gvG)['hata']['net_kg']) && isset(siparis_inv_girdi_temizle(['net_kg' => '0'] + $gvG)['hata']['net_kg'])
    && isset(siparis_inv_girdi_temizle(['brut_kg' => '-5'] + $gvG)['hata']['brut_kg']) && isset(siparis_inv_girdi_temizle(['net_kg' => 'abc'] + $gvG)['hata']['net_kg']));
$hO = siparis_inv_hesapla($tO['veri'], $oG);
ok('override ile hesap: 18.200 × 1,37 = 24.934,00; %4 → 23.936,64 (PKL 18.486 DEĞİL)', $hO['tutar_kurus'] === 2493400 && $hO['toplam_kurus'] === 2393664, json_encode($hO));
ok('override yoksa hesap PKL net\'iyle: 18.486 × 1,37 = 25.325,82', siparis_inv_hesapla($gvG, $oG)['tutar_kurus'] === 2532582);
$k = siparis_inv_kontrol($db, $sevP, $gvO, $oG, $ayar);
ok('override nominale eşit: net_nominal UYARI YOK; net_elle + brut_elle BİLGİ', !isset($kod($k)['net_nominal']) && ($kod($k)['net_elle'] ?? '') === 'bilgi' && ($kod($k)['brut_elle'] ?? '') === 'bilgi'
    && !array_filter($k, fn($x) => $x['seviye'] === 'hata'), json_encode($k));
ok('net_elle mesajı "Net ağırlık PKL\'den farklı: elle girildi"', (bool)array_filter($k, fn($x) => $x['kod'] === 'net_elle' && str_starts_with($x['mesaj'], "Net ağırlık PKL'den farklı: elle girildi")));
$k = siparis_inv_kontrol($db, $sevP, ['net_kg' => '18486'] + $gvG, $oG, $ayar);
ok('override = PKL net: net_elle YOK, net_nominal uyarısı sürer', !isset($kod($k)['net_elle']) && ($kod($k)['net_nominal'] ?? '') === 'uyari');
$k = siparis_inv_kontrol($db, $sevP, ['net_kg' => '30000'] + $gvG, $oG, $ayar);
ok('elle net, PKL brütünden büyükse HATA (brut_net)', ($kod($k)['brut_net'] ?? '') === 'hata');
$k = siparis_inv_kontrol($db, $sevP, ['net_kg' => '18200', 'brut_kg' => '18000'] + $gvG, $oG, $ayar);
ok('elle brüt < net → HATA', ($kod($k)['brut_net'] ?? '') === 'hata');
$k = siparis_inv_kontrol($db, $sevP, ['net_kg' => '18200', 'efatura_toplam' => '23.936,64'] + $gvG, $oG, $ayar);
ok('e-fatura toplamı override\'lı net ile eşleşir (efatura_fark yok)', !isset($kod($k)['efatura_fark']));
$k = siparis_inv_kontrol($db, $sevP, ['efatura_toplam' => '23.936,64'] + $gvG, $oG, $ayar);
ok('override yokken e-fatura toplamı PKL net\'iyle uyuşmaz → efatura_fark', ($kod($k)['efatura_fark'] ?? '') === 'uyari');
$ozE = siparis_inv_ozet_etkin($tO['veri'], $oG);
ok('ozet_etkin: net / brüt override, PKL değerleri net_pkl_kg / brut_pkl_kg\'de; idempotent', (float)$ozE['net_kg'] === 18200.0 && (float)$ozE['brut_kg'] === 21300.0 && (float)$ozE['net_pkl_kg'] === 18486.0
    && (float)$ozE['brut_pkl_kg'] === 20826.0 && siparis_inv_ozet_etkin($tO['veri'], $ozE) === $ozE);
$htmlO = siparis_inv_html($tO['veri'], $oG, $ayar, $hO);
ok('HTML override\'ı basar: 18.200 KG / 21.300 KG / € 24.934,00 / € 23.936,64 (PKL 18.486 YOK)', str_contains($htmlO, '18.200 KG') && str_contains($htmlO, '21.300 KG') && str_contains($htmlO, '€ 24.934,00')
    && str_contains($htmlO, '€ 23.936,64') && !str_contains($htmlO, '18.486') && !str_contains($htmlO, '20.826'));

echo "\n=== 8. HTML şablonu ===\n";
$hes = siparis_inv_hesapla($gv, $o);
$html = siparis_inv_html($gv, $o, $ayar, $hes);
ok('menşe beyanı + REX dolu', str_contains($html, 'declares that, except where otherwise clearly indicated, these products are of Turkey preferential origin') && str_contains($html, 'Customs Authorisation No. ' . T_REX . ')'));
ok('COUNTRY OF ORIGIN : TURKEY', (bool)preg_match('#COUNTRY OF ORIGIN</td><td class="d">:</td><td class="v">TURKEY<#', $html));
$htmlB = siparis_inv_html($gv, $o, ['rex_no' => ''] + $ayar, $hes);
ok('REX BOŞ da menşe beyanı + TURKEY basılır; REX yerine "______"', str_contains($htmlB, 'Customs Authorisation No. ______)') && str_contains($htmlB, 'Turkey preferential origin') && str_contains($htmlB, '>TURKEY<'));
$htmlC = siparis_inv_html($gv, $o, ['rex_no' => '   '] + $ayar, $hes);
ok('REX yalnız boşluksa da "______"', str_contains($htmlC, 'Customs Authorisation No. ______)'));
ok('başlıklar: TOTAL EURO (USD yok), PRICE KG EURO, DESCRIPTION OF GOODS', str_contains($html, 'TOTAL<br>EURO') && !str_contains($html, 'USD') && str_contains($html, 'PRICE KG<br>EURO') && str_contains($html, 'DESCRIPTION OF GOODS'));
ok('açıklama "10-12 PC." (10-124 yazım hatası yok); çift nokta yok', str_contains($html, 'FRESH TESTFRUIT 10-12 PC.') && !str_contains($html, '10-124') && !str_contains($html, '..2026') && str_contains($html, '05.10.2026'));
ok('rakamlar: 26 / 5.200 / 21.320 KG / 18.200 KG / € 1,37 / € 24.934,00 / 4,00% / € 997,36 / € 23.936,64', str_contains($html, '>26<') && str_contains($html, '>5.200<') && str_contains($html, '21.320 KG') && str_contains($html, '18.200 KG')
    && str_contains($html, '€ 1,37') && str_contains($html, '€ 24.934,00') && str_contains($html, '4,00%') && str_contains($html, '€ 997,36') && str_contains($html, '€ 23.936,64'));
ok('alt liste: PO, HARVEST, HS CODE 8109075, TRADE MARK, DELIVERY TERMS (ayar), banka, TRUCK / TIR', str_contains($html, 'TC900012.10') && str_contains($html, '8109075') && str_contains($html, 'ASYA FRESH</td>') && str_contains($html, 'INCOTERMS 2020 DAP')
    && str_contains($html, T_IBAN) && str_contains($html, T_SWIFT) && str_contains($html, '34TST001-34TST002') && str_contains($html, 'HARVEST'));
ok('sabit firma bilgileri + önemli notlar (alıcı ithalatçı yükümlülük metni)', str_contains($html, 'Gaziler Vergi Dairesi VAT: 8330514103') && str_contains($html, 'OGL FOOD TRADE LEBENSMİTTEL VERTRIEB GMBH.')
    && str_contains($html, 'The Importer shall discharge the goods immediately upon arrival'));
$htmlK = siparis_inv_html($gv, ['brut_kg' => 0.0] + $o, $ayar, $hes);
ok('brüt bilinmiyorsa "—" basılır (0 KG DEĞİL)', str_contains($htmlK, '>—<') && !str_contains($htmlK, '>0 KG<'));
ok('HTML\'de text-transform YOK (Türkçe i/İ)', !str_contains($html, 'text-transform'));
$xss = '<script>alert(1)</script>';
$htmlX = siparis_inv_html(['no' => $xss, 'tarih' => '2026-10-05', 'fiyat' => '1.37', 'iskonto' => 4.0] , ['order_ref' => $xss, 'harvest' => $xss, 'plaka' => $xss, 'aciklama' => '<img src=x onerror=alert(1)>', 'paket' => $xss, 'palet' => 1, 'koli' => 1, 'brut_kg' => 1.0, 'net_kg' => 1.0],
    ['rex_no' => $xss, 'banka_ad' => $xss, 'banka_adres' => '<b>x</b>', 'sube' => $xss, 'swift' => $xss, 'iban_eur' => $xss, 'teslim' => $xss], $hes);
ok('XSS: kullanıcı verisi kaçırılır (ham <script> / <img / <b> yok)', !str_contains($htmlX, '<script>') && !str_contains($htmlX, '<img') && !str_contains($htmlX, '<b>x</b>') && str_contains($htmlX, '&lt;script&gt;alert(1)&lt;/script&gt;'));
ok('XSS: REX alanında bile menşe beyanı kaçırılmış değerle durur', str_contains($htmlX, 'Customs Authorisation No. &lt;script&gt;'));

echo "\n=== 9. PDF ===\n";
$pdf = siparis_inv_pdf($html);
ok('PDF baytı %PDF ile başlar', strncmp($pdf, '%PDF', 4) === 0 && strlen($pdf) > 4000);
$pdfDosya = $SIPARIS_TEST_DIR . '/t_inv.pdf';
file_put_contents($pdfDosya, $pdf);
preg_match_all('#/Type\s*/Page\b#', $pdf, $mm);
ok('PDF tek sayfa (A4 dikey)', count($mm[0]) === 1 && str_contains($pdf, '595.28') , (string)count($mm[0]));
$metin = siparis_inv_pdf_metin($pdfDosya);
ok('PDF metin katmanı: INVOICE NUMBER, tutarlar, menşe beyanı, TURKEY', str_contains($metin, 'INVOICE NUMBER') && str_contains($metin, 'IHR2026000000340') && str_contains($metin, '23.936,64')
    && str_contains($metin, 'Customs Authorisation No.') && str_contains($metin, 'preferential origin') && str_contains($metin, 'TURKEY'), substr($metin, 0, 400));
ok('PDF (REX boş): "______" ve menşe beyanı yine var', str_contains(siparis_inv_pdf_metin((function () use ($htmlB, $SIPARIS_TEST_DIR) { $p = $SIPARIS_TEST_DIR . '/t_inv_b.pdf'; file_put_contents($p, siparis_inv_pdf($htmlB)); return $p; })()), 'No. ______'));

echo "\n=== 10. Üretim → belge → bağ → yeniden üretim ===\n";
$girdi = ['no' => 'IHR2026000000340', 'tarih' => '05.10.2026', 'fiyat' => '1,37', 'iskonto' => '4', 'efatura_toplam' => '23.936,64'];
$st0 = siparis_inv_durum($db, $sidPkl);
ok('üretim öncesi durum: yok', $st0 === ['var' => false, 'belge_id' => null, 'dosya_adi' => null, 'uretildi_at' => null, 'uretim_sayisi' => 0, 'girdi' => [], 'efatura_belge_id' => null], json_encode($st0));
$x = siparis_inv_uret($db, $sidPkl, 1, ['fiyat' => ''] + $girdi, true);
ok('hata seviyesinde kontrol (fiyat boş) → ÜRETİLMEZ, belge yok', !$x['ok'] && str_contains((string)$x['hata'], 'Birim fiyat') && siparis_test_say($db, 'siparis_belgeler', "tur = 'invoice'") === 0);
ok('reddedilen üretim girdi KV yazmaz', siparis_test_say($db, 'hks_kv', "anahtar = ?", ['siparis_invoice_' . $sidPkl]) === 0);
$x = siparis_inv_uret($db, $sidPkl, 1, ['no' => ''] + $girdi, true);
ok('invoice no boş → üretilmez', !$x['ok'] && siparis_test_say($db, 'siparis_belgeler', "tur = 'invoice'") === 0);
// eksik REX izin kuralı
siparis_inv_ayar_kaydet($db, ['rex_no' => ''], 7);
$x = siparis_inv_uret($db, $sidPkl, 1, $girdi, false);
ok('REX eksik + izin YOK → üretilmez (mesaj REX no\'yu söyler)', !$x['ok'] && str_contains((string)$x['hata'], 'REX no') && siparis_test_say($db, 'siparis_belgeler', "tur = 'invoice'") === 0, json_encode($x));
$x = siparis_inv_uret($db, $sidPkl, 1, $girdi, true);
ok('REX eksik + izin VAR → üretilir', $x['ok'] && $x['belge_id'] > 0 && $x['dosya_adi'] === 'INV-TC900012.10-AsyaDoc001-05.10.2026.pdf', json_encode($x));
$b1 = $db->query("SELECT * FROM siparis_belgeler WHERE id = " . (int)$x['belge_id'])->fetch();
ok('belge: tur invoice, kaynak uretim, durum islendi, mime pdf, depo sevkiyatın depo\'su', $b1['tur'] === 'invoice' && $b1['kaynak'] === 'uretim' && $b1['durum'] === 'islendi' && $b1['mime'] === 'application/pdf' && $b1['depo'] === 'DEPO1' && (int)$b1['yukleyen'] === 1);
$yol1 = siparis_dosya_yolu($b1);
ok('dosya diskte (depo klasöründe, 32 hex .pdf) ve %PDF', $yol1 !== null && preg_match('/^[a-f0-9]{32}\.pdf$/', $b1['disk_adi']) && strncmp((string)file_get_contents($yol1), '%PDF', 4) === 0 && (int)$b1['boyut'] === filesize($yol1));
ok('REX boş üretilen PDF\'te menşe beyanı yine var', str_contains(siparis_inv_pdf_metin($yol1), 'Customs Authorisation No.') && str_contains(siparis_inv_pdf_metin($yol1), 'preferential origin'));
$bag = $db->query("SELECT * FROM siparis_belge_bag WHERE belge_id = " . (int)$x['belge_id'])->fetchAll();
ok('sevkiyata bağlı ve geçerli', count($bag) === 1 && (int)$bag[0]['sevkiyat_id'] === $sidPkl && (int)$bag[0]['gecerli'] === 1);
$st1 = siparis_inv_durum($db, $sidPkl);
ok('durum: var, belge_id, dosya adı, uretim_sayisi 1, kayıtlı girdi', $st1['var'] && $st1['belge_id'] === (int)$x['belge_id'] && $st1['dosya_adi'] === $x['dosya_adi'] && $st1['uretim_sayisi'] === 1 && $st1['uretildi_at'] !== null
    && $st1['girdi']['no'] === 'IHR2026000000340' && $st1['girdi']['fiyat'] === '1.37' && $st1['girdi']['tarih'] === '2026-10-05' && (float)$st1['girdi']['iskonto'] === 4.0 && $st1['girdi']['efatura_toplam'] === '23936.64', json_encode($st1));
$vk = siparis_inv_varsayilan($db, siparis_sevkiyat($db, $sidPkl));
ok('varsayılan(): kayıtlı girdi formu doldurur', $vk['girdi']['no'] === 'IHR2026000000340' && $vk['girdi']['fiyat'] === '1.37' && $vk['girdi']['tarih'] === '2026-10-05');
ok('olay invoice_uretildi + belge_eklendi(Invoice)', siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND tur = 'invoice_uretildi'", [$sidPkl]) === 1
    && siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND tur = 'belge_eklendi' AND aciklama LIKE 'Invoice: INV-%'", [$sidPkl]) === 1);
$ev = $db->query("SELECT aciklama FROM siparis_olaylar WHERE tur = 'invoice_uretildi'")->fetchColumn();
ok('olay açıklaması ayar eksikliğini söyler', str_contains((string)$ev, 'REX no'));
$audI = array_values(array_filter(siparis_test_audit($db), fn($a) => $a['action'] === 'siparis_invoice_uret'));
$nv = json_decode((string)$audI[0]['new_values'], true);
ok('audit siparis_invoice_uret: no + toplam kuruş; IBAN / REX / banka YOK', count($audI) === 1 && $nv['invoice_no'] === 'IHR2026000000340' && $nv['toplam_kurus'] === 2393664 && $nv['rex_var'] === false
    && !str_contains((string)$audI[0]['new_values'], T_IBAN) && !str_contains((string)$audI[0]['new_values'], T_REX));
ok('üretim PKL / sevkiyat durumunu değiştirmez (var_pkl, durum)', (int)siparis_sevkiyat($db, $sidPkl)['var_pkl'] === 1 && siparis_sevkiyat($db, $sidPkl)['durum'] === 'pkl_hazir');
ok('invoice var_* bayrağı / belge sayacı eklemez (PKL + PO belgeleri bozulmadı)', siparis_test_say($db, 'siparis_belge_bag', "sevkiyat_id = ? AND gecerli = 1", [$sidPkl]) === 3);   // PO + PKL + invoice

// yeniden üretim
siparis_inv_ayar_kaydet($db, ['rex_no' => T_REX], 7);
$x2 = siparis_inv_uret($db, $sidPkl, 1, ['fiyat' => '1,40'] + $girdi, false);
ok('REX tamamlanınca izinsiz üretilir (yeniden üretim, farklı fiyat)', $x2['ok'] && $x2['belge_id'] !== $x['belge_id'], json_encode($x2));
$gec = $db->query("SELECT bb.belge_id, bb.gecerli FROM siparis_belge_bag bb JOIN siparis_belgeler b ON b.id = bb.belge_id WHERE bb.sevkiyat_id = $sidPkl AND b.tur = 'invoice' ORDER BY bb.belge_id")->fetchAll();
ok('yeniden üretim: eskinin bağı gecerli=0, yeninin gecerli=1', count($gec) === 2 && (int)$gec[0]['gecerli'] === 0 && (int)$gec[1]['gecerli'] === 1 && (int)$gec[1]['belge_id'] === (int)$x2['belge_id']);
ok('eski belge silinmez (dosya diskte, satır yaşıyor)', siparis_dosya_yolu($db->query("SELECT * FROM siparis_belgeler WHERE id = " . (int)$x['belge_id'])->fetch()) !== null);
ok('eski sürüm olayı yazıldı', siparis_test_say($db, 'siparis_olaylar', "sevkiyat_id = ? AND tur = 'belge_eski_surum' AND aciklama LIKE 'Yeni invoice%'", [$sidPkl]) === 1);
$st2 = siparis_inv_durum($db, $sidPkl);
ok('durum: güncel belge yeni, uretim_sayisi 2, kayıtlı girdi güncel fiyat', $st2['belge_id'] === (int)$x2['belge_id'] && $st2['uretim_sayisi'] === 2 && $st2['girdi']['fiyat'] === '1.40');
$audI = array_values(array_filter(siparis_test_audit($db), fn($a) => $a['action'] === 'siparis_invoice_uret'));
ok('ikinci audit: rex_var true; yine IBAN/REX değeri yok', count($audI) === 2 && json_decode((string)$audI[1]['new_values'], true)['rex_var'] === true && !str_contains((string)$audI[1]['new_values'], T_REX));
$pdfYeni = siparis_inv_pdf_metin((string)siparis_dosya_yolu($db->query("SELECT * FROM siparis_belgeler WHERE id = " . (int)$x2['belge_id'])->fetch()));
ok('yeni PDF REX no\'yu basar ve toplamı yeni fiyatla hesaplar (18.200 × 1,40 = 25.480 − %4 = 24.460,80)', str_contains($pdfYeni, T_REX) && str_contains($pdfYeni, '24.460,80'), substr($pdfYeni, 0, 300));
$kk = siparis_inv_uret($db, $sidPkl, 1, ['fiyat' => '1,40'] + $girdi, false);
ok('AYNI girdiyle tekrar üretim çökmez (aynı bayt → mevcut belge yeniden kullanılır ya da yeni belge)', $kk['ok'] === true);
ok('her zaman TEK geçerli invoice bağı', (int)$db->query("SELECT COUNT(*) FROM siparis_belge_bag bb JOIN siparis_belgeler b ON b.id = bb.belge_id WHERE bb.sevkiyat_id = $sidPkl AND bb.gecerli = 1 AND b.tur = 'invoice'")->fetchColumn() === 1);
$x3 = siparis_inv_uret($db, 99999, 1, $girdi, true);
ok('olmayan sevkiyat → hata, belge yok', !$x3['ok'] && $x3['hata'] === 'Sevkiyat bulunamadı.');
ok('transaction içinde çağrılırsa istisna dışarı atılır (çağıranın tx\'i)', (function () use ($db, $sidPkl, $girdi): bool {
    $db->beginTransaction();
    try { siparis_inv_uret($db, $sidPkl, 1, ['fiyat' => ''] + $girdi, true); $ok = false; } catch (RuntimeException $e) { $ok = true; }
    if ($db->inTransaction()) $db->rollBack();
    return $ok;
})());
// net / brüt override'lı üretim: PDF override'ı basar, kayıtlı girdi taşır
$xo = siparis_inv_uret($db, $sidPkl, 1, ['net_kg' => '15.000', 'brut_kg' => '17000', 'fiyat' => '2'] + $girdi, false);
$po = $xo['ok'] ? siparis_inv_pdf_metin((string)siparis_dosya_yolu($db->query("SELECT * FROM siparis_belgeler WHERE id = " . (int)$xo['belge_id'])->fetch())) : '';
ok('override\'lı üretim: PDF 15.000 KG / 17.000 KG / € 30.000,00 basar; net_elle bilgisi uyarılarda', $xo['ok'] && str_contains($po, '15.000 KG') && str_contains($po, '17.000 KG') && str_contains($po, '30.000,00')
    && in_array('net_elle', array_column($xo['uyarilar'], 'kod'), true), json_encode($xo));
ok('kayıtlı girdi net_kg / brut_kg taşır; varsayılan() formu doldurur', siparis_inv_durum($db, $sidPkl)['girdi']['net_kg'] === 15000 && siparis_inv_varsayilan($db, siparis_sevkiyat($db, $sidPkl))['girdi']['brut_kg'] === 17000);
$xo2 = siparis_inv_uret($db, $sidPkl, 1, ['net_kg' => '16000', 'brut_kg' => '15000'] + $girdi, false);
ok('elle brüt < net → üretilmez', !$xo2['ok'] && str_contains((string)$xo2['hata'], 'Brüt ağırlık'));
siparis_inv_uret($db, $sidPkl, 1, ['fiyat' => '1,40'] + $girdi, false);          // override'sız son üretim (sonraki testler 1,40 bekler)
// PKL'siz sevkiyat: kalem yedeğiyle üretilir (uyarı), belge no yok → dosya adında atlanır
$xk = siparis_inv_uret($db, $sidYok, 1, $girdi, true);
ok('PKL\'siz sevkiyat: uyarıyla üretilir; dosya adı belge no\'suz', $xk['ok'] && $xk['dosya_adi'] === 'INV-TC900011.10-05.10.2026.pdf' && in_array('pkl_yok', array_column($xk['uyarilar'], 'kod'), true), json_encode($xk));

echo "\n=== 11. Yeniden işleme koruması (invoice / efatura) ===\n";
$yi = siparis_belge_isle($db, (int)$x2['belge_id'], 1);
ok('invoice belgesi siparis_belge_isle ile yeniden sınıflanmaz (hata, tur korunur)', !$yi['ok'] && str_contains((string)$yi['hata'], 'yeniden işlenmez')
    && $db->query("SELECT tur FROM siparis_belgeler WHERE id = " . (int)$x2['belge_id'])->fetchColumn() === 'invoice');

echo "\n=== 12. E-fatura ===\n";
// görüntü-yalnız PDF (metin katmanı yok): 1×1 PNG'den
$png = (function (): string { $im = imagecreatetruecolor(40, 40); imagefilledrectangle($im, 0, 0, 39, 39, imagecolorallocate($im, 200, 200, 200)); ob_start(); imagepng($im); return (string)ob_get_clean(); })();
$goruntuPdf = siparis_inv_pdf('<html><body><img src="data:image/png;base64,' . base64_encode($png) . '" width="300" height="300"></body></html>');
ok('görüntü-yalnız test PDF\'inde metin katmanı yok', siparis_inv_pdf_metin((function () use ($goruntuPdf, $SIPARIS_TEST_DIR) { $p = $SIPARIS_TEST_DIR . '/t_img.pdf'; file_put_contents($p, $goruntuPdf); return $p; })()) === '');
$f = siparis_test_dosya($goruntuPdf, 'IHR2026000000340.pdf');
$e = siparis_inv_efatura_yukle($db, $sidPkl, $f, 1, siparis_test_mover());
ok('görüntü-yalnız e-fatura: ok, metin_var=false, alanlar boş (uydurma YOK)', $e['ok'] && $e['metin_var'] === false && $e['alanlar'] === [] && $e['belge_id'] > 0, json_encode($e));
$eb = $db->query("SELECT * FROM siparis_belgeler WHERE id = " . (int)$e['belge_id'])->fetch();
ok('belge tur efatura, durum islendi, sevkiyata bağlı + geçerli', $eb['tur'] === 'efatura' && $eb['durum'] === 'islendi' && siparis_test_say($db, 'siparis_belge_bag', 'belge_id = ? AND sevkiyat_id = ? AND gecerli = 1', [(int)$e['belge_id'], $sidPkl]) === 1);
ok('özet "metin okunamadı" notu', str_contains((string)$eb['ozet'], 'metin okunamadı'));
ok('durum(): efatura_belge_id', siparis_inv_durum($db, $sidPkl)['efatura_belge_id'] === (int)$e['belge_id']);
$eu = siparis_test_audit($db);
$ea = array_values(array_filter($eu, fn($a) => $a['action'] === 'siparis_efatura_yukle'));
ok('audit siparis_efatura_yukle: metin_var false', count($ea) === 1 && json_decode((string)$ea[0]['new_values'], true)['metin_var'] === false);
$e2 = siparis_inv_efatura_yukle($db, $sidPkl, siparis_test_dosya($goruntuPdf, 'IHR2026000000340-kopya.pdf'), 1, siparis_test_mover());
ok('aynı e-fatura tekrar yüklenince aynı belge (sha), çift bağ yok', $e2['ok'] && $e2['belge_id'] === $e['belge_id'] && siparis_test_say($db, 'siparis_belge_bag', 'belge_id = ?', [(int)$e['belge_id']]) === 1);
// metin katmanlı PDF
$metinPdf = siparis_inv_pdf('<html><body style="font-family:DejaVu Sans"><p>Fatura No: IHR2026000000999</p><p>Fatura Tarihi: 05.10.2026</p><p>Birim Fiyat 1,37 EUR</p><p>İskonto Oranı %4,00</p><p>Ödenecek Tutar 23.936,64 EUR</p></body></html>');
$e3 = siparis_inv_efatura_yukle($db, $sidPkl, siparis_test_dosya($metinPdf, 'efatura-metin.pdf'), 1, siparis_test_mover());
ok('metin katmanlı e-fatura: metin_var=true, alanlar çıkarıldı (no, tarih, fiyat, iskonto, toplam)', $e3['ok'] && $e3['metin_var'] === true
    && $e3['alanlar'] === ['no' => 'IHR2026000000999', 'tarih' => '2026-10-05', 'fiyat' => '1.37', 'iskonto' => '4', 'efatura_toplam' => '23936.64'], json_encode($e3));
ok('çıkarılan alanlar girdi doğrulayıcısından geçer (forma ön-doldurma uyumlu)', siparis_inv_girdi_temizle($e3['alanlar'])['ok']);
$gec = $db->query("SELECT bb.belge_id, bb.gecerli FROM siparis_belge_bag bb JOIN siparis_belgeler b ON b.id = bb.belge_id WHERE bb.sevkiyat_id = $sidPkl AND b.tur = 'efatura' ORDER BY bb.belge_id")->fetchAll();
ok('yeni e-fatura gelince eskisi gecerli=0, yenisi geçerli', count($gec) === 2 && (int)$gec[0]['gecerli'] === 0 && (int)$gec[1]['gecerli'] === 1 && siparis_inv_durum($db, $sidPkl)['efatura_belge_id'] === (int)$e3['belge_id']);
// belirsiz metin → alan boş
$belirsiz = siparis_inv_efatura_alanlar('Fatura No: IHR2026000000111 Fatura No: IHR2026000000222 Fatura Tarihi: 05.10.2026 Fatura Tarihi: 06.10.2026 Birim Fiyat 1,37 Birim Fiyat 2,10');
ok('belirsiz (birden çok farklı aday) alan BOŞ bırakılır', $belirsiz === [], json_encode($belirsiz));
ok('aynı değer tekrarında tek aday sayılır', siparis_inv_efatura_alanlar('IHR2026000000111 ... IHR2026000000111')['no'] === 'IHR2026000000111');
ok('metinsiz / boş metin → boş', siparis_inv_efatura_alanlar('') === [] && siparis_inv_efatura_alanlar('merhaba dünya') === []);
// reddedilenler
$xl = siparis_test_dosya("PK\x03\x04bozuk", 'x.xlsx');
$er = siparis_inv_efatura_yukle($db, $sidPkl, $xl, 1, siparis_test_mover());
ok('xlsx / geçersiz dosya reddedilir', !$er['ok'] && !empty($er['hata']) && $er['metin_var'] === false);
$er = siparis_inv_efatura_yukle($db, $sidPkl, siparis_test_dosya(siparis_test_pdf('x'), 'x.png'), 1, siparis_test_mover());
ok('uzantı/içerik uyuşmazlığı reddedilir', !$er['ok']);
$invBytes = (string)file_get_contents((string)siparis_dosya_yolu($db->query("SELECT * FROM siparis_belgeler WHERE id = " . (int)$x2['belge_id'])->fetch()));
$er = siparis_inv_efatura_yukle($db, $sidPkl, siparis_test_dosya($invBytes, 'sahte-efatura.pdf'), 1, siparis_test_mover());
ok('üretilmiş invoice PDF\'i e-fatura diye yüklenemez (başka türde kayıtlı)', !$er['ok'] && str_contains((string)$er['hata'], 'Invoice') && $db->query("SELECT tur FROM siparis_belgeler WHERE id = " . (int)$x2['belge_id'])->fetchColumn() === 'invoice', json_encode($er));
$er = siparis_inv_efatura_yukle($db, 99999, siparis_test_dosya($goruntuPdf, 'a.pdf'), 1, siparis_test_mover());
ok('olmayan sevkiyat reddedilir', !$er['ok'] && $er['hata'] === 'Sevkiyat bulunamadı.');
$hepsi = json_encode($db->query('SELECT * FROM audit_log')->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
ok('tüm audit satırlarında IBAN / REX / banka değeri YOK (üretim + ayar + e-fatura)', !str_contains($hepsi, T_IBAN) && !str_contains($hepsi, T_REX) && !str_contains($hepsi, 'TEST BANKASI'));

echo "\n=== 13. Gerçek PKL ile elle doğrulama (isteğe bağlı: INVOICE_ORNEK_PKL=<xlsx>) ===\n";
$ornek = (string)(getenv('INVOICE_ORNEK_PKL') ?: '');
if ($ornek !== '' && is_file($ornek)) {
    // gerçek PKL'i geçici sevkiyata ekle (depo klasörüne kopya; repoya GİRMEZ)
    $ad = bin2hex(random_bytes(16)) . '.xlsx';
    copy($ornek, SIPARIS_DEPO_DIR . $ad);
    $db->exec("INSERT INTO siparis_sevkiyatlar (tedarikci_no, order_ref, durum, depo, plakalar, created_at) VALUES ('1', 'TC-ORNEK.10', 'pkl_hazir', 'DEPO1', '', datetime('now'))");
    $sidO = (int)$db->lastInsertId();
    $db->exec("INSERT INTO siparis_belgeler (tur, kaynak, durum, sha256, disk_adi, orijinal_ad, mime, boyut, depo, created_at) VALUES ('pkl', 'uretim', 'islendi', '" . hash('sha256', $ad) . "', '$ad', 'PKL.xlsx', 'x', 1, 'DEPO1', datetime('now'))");
    $bid = (int)$db->lastInsertId();
    $db->exec("INSERT INTO siparis_pkl (sevkiyat_id, durum, sezon, dosya_belge_id, belge_no) VALUES ($sidO, 'yuklendi', '2026', $bid, 'AsyaDoc003')");
    $oz = siparis_inv_varsayilan($db, siparis_sevkiyat($db, $sidO))['ozet'];
    ok('gerçek PKL (xlsx\'in gerçek toplamları; elle hazırlanmış invoice nominal 21.300/18.200 kullanmıştı): 26 palet, 5.200 koli, brüt 20.826, net 18.486, kalibre 10 + 12, açıklama FRESH POMEGRANATE 10-12 PC.', $oz['palet'] === 26 && $oz['koli'] === 5200 && (float)$oz['brut_kg'] === 20826.0 && (float)$oz['net_kg'] === 18486.0
        && $oz['kalibreler'] === ['10', '12'] && $oz['aciklama'] === 'FRESH POMEGRANATE 10-12 PC.' && $oz['plaka'] === '31AFJ082-31ACF204' && $oz['harvest'] === '2026' && $oz['incoterms_pkl'] === 'EXW', json_encode($oz));
    $hh = siparis_inv_hesapla(['fiyat' => '1.37', 'iskonto' => 4.0], $oz);
    ok('gerçek PKL net 18.486 × 1,37 = 25.325,82; %4 → 24.312,79', $hh['tutar_kurus'] === 2532582 && $hh['iskonto_kurus'] === 101303 && $hh['toplam_kurus'] === 2431279, json_encode($hh));
} else {
    echo "ATLANDI (INVOICE_ORNEK_PKL verilmedi)\n";
}

siparis_test_bitir();
