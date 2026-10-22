<?php
// =========================================================
// scripts/hks_gonderim_audit_smoke.php — Hal Kayıt gönderim audit'i + gönderen (v417)
//
// SADECE CLI. Canlı DB'ye DOKUNMAZ, ağ çağrısı YAPMAZ. halkayit/tekrar_gonder_lib.php'nin
// GERÇEK hks_gonderim_audit_* yardımcıları sahte bir audit_log_event ile sınanır;
// api.php taslak_gonder kuralları kaynakta (statik) denetlenir. Örnekler sentetiktir.
//
//   php scripts/hks_gonderim_audit_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$KOK = dirname(__DIR__);

// Sahte audit_log_event — lib yüklenmeden önce tanımlı olmalı (function_exists kapısı).
$GLOBALS['AUDIT'] = [];
$GLOBALS['AUDIT_FIRLAT'] = null;
function audit_log_event(string $action, string $module, ?int $record_id = null, ?array $old = null, ?array $new = null, ?int $uid = null): void {
    if ($GLOBALS['AUDIT_FIRLAT'] !== null) { $c = $GLOBALS['AUDIT_FIRLAT']; throw new $c('sahte hata TC 12345678901'); }
    $GLOBALS['AUDIT'][] = compact('action', 'module', 'record_id', 'new');
}

require_once "$KOK/halkayit/tekrar_gonder_lib.php";

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail;
    if (!$c) $fail++;
    printf("%-78s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ipucu);
}

// ── Sentetik veri: mesajlar/alanlar kişisel veri taşıyor (sızmamalı) ─────────
$TC = '12345678901'; $DOGUM = '11.02.1959'; $CEP = '5551234567';
$t = ['id' => 't1', 'firma_id' => 'f1', 'firma_ad' => 'Örnek Firma A.Ş.'];
$ortak = ['plaka' => '33NCN99', 'belgeNo' => 'B-1', 'ulkeAd' => "Yurt içi → $TC", 'ikinciTc' => $TC, 'ikinciAd' => 'Örnek Kişi',
    'ikinciCep' => $CEP, 'ikinciDogumTarihi' => $DOGUM, 'kaynak' => ['tip' => 'beyan', 'beyanId' => 42]];
$satirlar = [['miktar' => 10000], ['miktar' => 9500], ['miktar' => 5000]];
$ctx = hks_gonderim_audit_ctx($t, $ortak, $satirlar, false, 'abcdef0123456789', 'SATIS');

$S_OK1 = ['yeniKunyeNo' => '111', 'hataKodu' => 0, 'miktar' => 10000, 'rusum' => 12.5, 'mesaj' => "TC $TC Mernis"];
$S_OK2 = ['yeniKunyeNo' => '222', 'hataKodu' => 0, 'miktar' => 9500, 'rusum' => 11.0, 'mesaj' => ''];
$S_HATA = ['yeniKunyeNo' => '', 'hataKodu' => 21, 'miktar' => 5000, 'rusum' => 0, 'mesaj' => "Tc kimlik numarası $TC Mernis sisteminde bulunamadı"];
$LEAK = ['ham' => "<x>$TC</x>", 'hamIstek' => "<TC>$TC</TC><Dogum>$DOGUM</Dogum><Cep>$CEP</Cep>", 'genelHata' => "Hata $TC",
    'gonderilenKimlik' => ['tc' => $TC, 'ad' => 'Örnek Kişi', 'cep' => $CEP, 'dogum' => $DOGUM]];

echo "── ctx ──\n";
ok('ctx: istenen kg satırların toplamı', $ctx['istenen_kg'] === 24500.0, (string)$ctx['istenen_kg']);
ok('ctx: beyan_id kaynak izinden', $ctx['beyan_id'] === 42);
ok('ctx: satır sayısı', $ctx['satir_sayisi'] === 3);
$ctxPlan = hks_gonderim_audit_ctx($t, ['planKg' => 20000, 'plaka' => 'X'], [], true, 'r', 'SATIS');
ok('ctx: satır yoksa planKg', $ctxPlan['istenen_kg'] === 20000.0 && $ctxPlan['plan'] === true);

echo "\n── sonuç türleri ──\n";
$tam = ['sonuclar' => [$S_OK1, $S_OK2, array_merge($S_OK2, ['yeniKunyeNo' => '333'])]] + $LEAK;
$kismi = ['sonuclar' => [$S_OK1, $S_OK2, $S_HATA]] + $LEAK;
$basarisiz = ['sonuclar' => [$S_HATA], 'genelHata' => null] + $LEAK;
$bos = ['sonuclar' => [], 'genelHata' => null];
$bosGenel = ['sonuclar' => [], 'genelHata' => 'GTB hata'];
ok('tür: hepsi başarılı → basarili', hks_gonderim_sonuc_turu($tam) === 'basarili');
ok('tür: karışık → kismi', hks_gonderim_sonuc_turu($kismi) === 'kismi');
ok('tür: hepsi hatalı → tam_basarisiz', hks_gonderim_sonuc_turu($basarisiz) === 'tam_basarisiz');
ok('tür: sonuç satırı ve genel hata yok → yanit_bos', hks_gonderim_sonuc_turu($bos) === 'yanit_bos');
ok('tür: sonuç yok ama genel hata var → tam_basarisiz', hks_gonderim_sonuc_turu($bosGenel) === 'tam_basarisiz');

echo "\n── payload ──\n";
$pk = hks_gonderim_audit_payload('kismi', $ctx + ['gonderim_id' => 'g123'], $kismi);
ok('kısmi: künye sayısı 2, numaralar', $pk['kunye_sayisi'] === 2 && $pk['kunyeler'] === ['111', '222']);
ok('kısmi: başarılı kg/adet yalnız başarılılardan', $pk['basarili_kg'] === 19500.0 && $pk['basarili_adet'] === 2, json_encode($pk));
ok('kısmi: hata sayısı 1, kodlar [21], genel_hata_var bayrağı', $pk['hata_sayisi'] === 1 && $pk['hata_kodlari'] === [21] && $pk['genel_hata_var'] === true);
ok('kısmi: rüsum, gönderim id, beyan id, firma, plaka', $pk['rusum'] === 23.5 && $pk['gonderim_id'] === 'g123'
    && $pk['beyan_id'] === 42 && $pk['firma_id'] === 'f1' && $pk['plaka'] === '33NCN99' && $pk['belge_no'] === 'B-1' && $pk['bildirim_turu'] === 'SATIS');
$pb = hks_gonderim_audit_payload('basarili', $ctx, $tam);
ok('başarılı: 3 künye, hata 0', $pb['kunye_sayisi'] === 3 && $pb['hata_sayisi'] === 0 && $pb['hata_kodlari'] === []);
$pt = hks_gonderim_audit_payload('tam_basarisiz', $ctx, $basarisiz);
ok('tam başarısız: künye 0, hata 1', $pt['kunye_sayisi'] === 0 && $pt['hata_sayisi'] === 1 && $pt['kunyeler'] === [] && $pt['basarili_kg'] === 0.0);
$pbl = hks_gonderim_audit_payload('belirsiz', $ctx + ['exception_sinifi' => 'RuntimeException'], null);
ok('belirsiz: yalnız istisna sınıfı, künye alanları yok', ($pbl['exception_sinifi'] ?? '') === 'RuntimeException' && !isset($pbl['kunyeler']));
$pbs = hks_gonderim_audit_payload('basla', $ctx + ['exception_sinifi' => 'X'], null);
ok('basla: niyet alanları, sonuç/istisna alanı yok', $pbs['sonuc_turu'] === 'basla' && $pbs['istenen_kg'] === 24500.0 && !isset($pbs['exception_sinifi']) && !isset($pbs['kunye_sayisi']));
ok('hepsinde gonderim_ref ortak', $pk['gonderim_ref'] === 'abcdef0123456789' && $pbs['gonderim_ref'] === $pk['gonderim_ref']);

echo "\n── kişisel veri sızıntısı ──\n";
$tumJson = '';
foreach ([$pk, $pb, $pt, $pbl, $pbs, hks_gonderim_audit_payload('yanit_bos', $ctx, $bos)] as $p) {
    $j = json_encode($p, JSON_UNESCAPED_UNICODE);
    ok('json_encode başarılı', $j !== false);
    $tumJson .= (string)$j;
}
foreach ([$TC, $DOGUM, $CEP, 'Mernis', 'Örnek Kişi', 'Yurt içi'] as $yasak) {
    ok("payload '$yasak' içermez", strpos($tumJson, $yasak) === false);
}
foreach (['"mesaj"', '"ham"', '"hamIstek"', '"ulkeAd"', '"ikinciTc"', '"gonderilenKimlik"', '"genelHata"', '"satirlar"'] as $anahtar) {
    ok("payload $anahtar anahtarı içermez", strpos($tumJson, $anahtar) === false);
}
// Geçersiz UTF-8 json_encode'u bozmaz
$bozuk = hks_gonderim_audit_payload('kismi', ['ref' => "ab\xC3\x28", 'firma_ad' => "Fi\xFFrma", 'plaka' => str_repeat('A', 500)] + $ctx, $kismi);
ok('geçersiz UTF-8 / uzun girdi → json_encode false olmaz', json_encode($bozuk, JSON_UNESCAPED_UNICODE) !== false);
ok('uzun plaka kısaltılır', mb_strlen($bozuk['plaka']) <= 30);
// künye listesi sınırı, sayı gerçek
$cok = ['sonuclar' => array_map(fn($i) => ['yeniKunyeNo' => (string)(1000 + $i), 'hataKodu' => 0, 'miktar' => 1, 'rusum' => 0], range(1, 150))];
$pc = hks_gonderim_audit_payload('basarili', $ctx, $cok);
ok('150 künye: liste 100 ile sınırlı, kunye_sayisi gerçek 150', count($pc['kunyeler']) === 100 && $pc['kunye_sayisi'] === 150);

echo "\n── hks_gonderim_audit_yaz (hata yutar) ──\n";
hks_gonderim_audit_yaz('hks_gonderim_sonuc', 'kismi', $ctx, $kismi);
$a = $GLOBALS['AUDIT'][0] ?? null;
ok('audit_log_event çağrıldı: modül hks_gonderim, record_id null', $a && $a['module'] === 'hks_gonderim' && $a['record_id'] === null && $a['action'] === 'hks_gonderim_sonuc');
ok('action adı ≤40, modül ≤40', strlen('hks_gonderim_sonuc') <= 40 && strlen('hks_gonderim_basla') <= 40);
foreach (['TypeError', 'RuntimeException', 'PDOException', 'Error'] as $cls) {
    $GLOBALS['AUDIT_FIRLAT'] = $cls;
    $dis = false;
    try { hks_gonderim_audit_yaz('hks_gonderim_sonuc', 'kismi', $ctx, $kismi); } catch (Throwable $e) { $dis = true; }
    ok("audit_log_event $cls fırlatırsa dışarı SIZMAZ", !$dis);
}
$GLOBALS['AUDIT_FIRLAT'] = null;
ok('kullanıcı kimliğini audit_log_event ekler (explicit_user_id GEÇİLMEZ)', strpos(
    (string)file_get_contents(__DIR__ . '/../halkayit/tekrar_gonder_lib.php'),
    'audit_log_event($action, HKS_GONDERIM_AUDIT_MODUL, null, null, hks_gonderim_audit_payload($tur, $ctx, $sonuc));') !== false);

echo "\n── gönderen ──\n";
$g = hks_gonderim_gonderen(['id' => '7', 'display_name' => 'Örnek Kullanıcı', 'username' => 'ornek']);
ok('gönderen: id int + display_name', $g === ['id' => 7, 'ad' => 'Örnek Kullanıcı']);
ok('gönderen: display_name boşsa username', hks_gonderim_gonderen(['id' => 3, 'display_name' => '  ', 'username' => 'ornek'])['ad'] === 'ornek');
ok('gönderen: null kullanıcı', hks_gonderim_gonderen(null) === ['id' => 0, 'ad' => '']);
ok('gönderen: uzun ad kısaltılır', mb_strlen(hks_gonderim_gonderen(['id' => 1, 'display_name' => str_repeat('Ş', 400)])['ad']) === 150);

echo "\n── api.php taslak_gonder (kaynak denetimi) ──\n";
$api = (string)file_get_contents("$KOK/halkayit/api.php");
$pG = strpos($api, "case 'taslak_gonder':");
$pGSon = $pG !== false ? strpos($api, "case 'gonderilenler':", $pG) : false;
$g = ($pG !== false && $pGSon !== false) ? substr($api, $pG, $pGSon - $pG) : '';
ok('taslak_gonder gövdesi bulundu', $g !== '');
$pBasla = strpos($g, "hks_gonderim_audit_yaz('hks_gonderim_basla'");
$pKaydet = strpos($g, '$sonuc = hks_bildirim_kaydet(');
ok("'basla' niyet kaydı hks_bildirim_kaydet'ten ÖNCE", $pBasla !== false && $pKaydet !== false && $pBasla < $pKaydet);
$pBel = strpos($g, "'belirsiz'");
$pDogum = strpos($g, 'hks_dogum_deneme_kaydet($__dogumKaydi + [\'bicim\' => hks_dogum_varyant_coz');
ok("'belirsiz' audit'i catch içinde hks_dogum_deneme_kaydet'ten ÖNCE", $pBel !== false && $pDogum !== false && $pBel < $pDogum);
ok("'belirsiz' audit'i istisna SINIFINI yazar, mesajını DEĞİL", strpos($g, "'exception_sinifi' => get_class(\$__e)") !== false
    && !preg_match("/exception_sinifi'\s*=>[^\n]*getMessage/", $g));
$pBos = strpos($g, 'if (count($basarili) === 0) {');
$pTam = strpos($g, "hks_gonderim_audit_yaz('hks_gonderim_sonuc', hks_gonderim_sonuc_turu(\$sonuc), \$__actx, \$sonuc);", (int)$pBos);
$pGeriKoy = $pBos !== false ? strpos($g, '$taslagiGeriKoy();', $pBos) : false;
ok("tam başarısız/yanit_bos audit'i taslağı geri koymadan ÖNCE", $pBos !== false && $pTam !== false && $pGeriKoy !== false && $pTam < $pGeriKoy);
$pGid = strpos($g, "\$gid = 'g' . round(microtime(true) * 1000);");
$pBasAudit = strpos($g, "'gonderim_id' => \$gid], \$sonuc);");
$pSon = strpos($g, 'hks_son_guncelle($ortak, $t[\'firma_id\']);');
$pHavuz = strpos($g, 'hks_kisi_havuzuna_isle(');
$pIns = strpos($g, "INSERT INTO ' . hks_tablo('gonderilenler')");
ok("\$gid ve başarılı/kısmi audit'i hks_son_guncelle / havuz / INSERT'ten ÖNCE",
    $pGid !== false && $pBasAudit !== false && $pSon !== false && $pGid < $pBasAudit && $pBasAudit < $pSon && $pSon < $pHavuz && $pHavuz < $pIns);
ok('$gid tek kez tanımlanır', substr_count($g, "\$gid = 'g' . round(") === 1);
ok("audit'e \$sonuc/\$ortak doğrudan geçirilmez (yalnız hks_gonderim_audit_yaz üzerinden)", strpos($g, 'audit_log_event(') === false);
ok("taslak_gonder'de yeni 'hks_gonderilen_' alt dizesi / 'ayni_plaka' yok", strpos($g, 'hks_gonderilen_') === false && strpos($g, 'ayni_plaka') === false);
ok('hks_bildirim_kaydet çağrısı tek (ikinci gönderim yolu yok)', substr_count($g, '= hks_bildirim_kaydet(') === 1);
ok('taslak yazan yol eklenmedi (hks_taslak_olustur çağrısı yok bu uçta)', strpos($g, 'hks_taslak_olustur(') === false);
ok("Gönderilenler veri JSON'una 'gonderen' girer", strpos($g, "'gonderen' => hks_gonderim_gonderen(\$__hks_user)") !== false);

$lib = (string)file_get_contents("$KOK/halkayit/tekrar_gonder_lib.php");
$kopyaAnahtar = '';
if (preg_match('/function hks_kopya_ortak_anahtarlari\(\)[^{]*\{(.*?)\n\}/s', $lib, $m)) $kopyaAnahtar = $m[1];
ok('kopya beyaz listesinde gönderen YOK', $kopyaAnahtar !== '' && stripos($kopyaAnahtar, 'gonderen') === false);
$pTohum = strpos($lib, 'function hks_gonderim_tohumu(');
$tohumGov = $pTohum !== false ? substr($lib, $pTohum, 4000) : '';
ok('tohum (tekrar gönder) gönderen okumaz', $tohumGov !== '' && strpos($tohumGov, 'gonderen') === false);

echo $fail === 0 ? "\n>>> TÜM TESTLER GEÇTİ\n" : "\n>>> $fail TEST BAŞARISIZ\n";
exit($fail === 0 ? 0 : 1);
