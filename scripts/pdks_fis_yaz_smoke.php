<?php
// =========================================================
// scripts/pdks_fis_yaz_smoke.php — "Fiş Excel'i → mesai kayıtları" DB'li testleri (v446).
//
// Bellek içi SQLite + GERÇEK DDL çevirici + gerçek fonksiyonlar (scripts/_pdks_fis_harness.php).
// A: config/pdks_faz8j.php opt-in çekirdek değişikliği (coklu_grup / yazdiktan_sonra / audit_ek).
// B..L: config/pdks_fis.php önizleme + yazma (WP5/WP6'da eklenir).
//   php scripts/pdks_fis_yaz_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
require __DIR__ . '/_pdks_fis_harness.php';

require_once $ROOT . '/config/pdks_fis.php';

function istek32(): string { return bin2hex(random_bytes(16)); }

/** Normalize edilmiş fiş (pdks_fis_normalize çıktısı biçiminde). $say = ['ERKEK' => [tam, yarim], …]. */
function fisYap(array $say, array $o = []): array {
    global $cavus, $dun3;
    static $n = 0; $n++;
    $s = ['KADIN' => ['tam' => 0, 'yarim' => 0], 'ERKEK' => ['tam' => 0, 'yarim' => 0], 'RAMPACI' => ['tam' => 0, 'yarim' => 0]];
    foreach ($say as $k => [$t, $y]) $s[$k] = ['tam' => $t, 'yarim' => $y];
    $kova = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0];
    foreach (($o['fm_kova'] ?? []) as $sa => $c) $kova[$sa] = $c;
    unset($o['fm_kova']);
    $cv = $o['cavus'] ?? $cavus['A']; unset($o['cavus']);
    return $o + ['satir' => 1 + $n, 'fis_no' => 'T' . $n, 'fis_no_ham_sayi' => false, 'tarih' => $dun3, 'isletme' => '', 'cavus' => ['id' => $cv, 'kod' => '', 'ad' => '', 'yontem' => 'kod'],
                 'say' => $s, 'fm_kova' => $kova, 'fm_toplam' => null, 'fm_kisi' => null, 'fm_tipi' => null, 'servis' => ['buyuk' => 0, 'kucuk' => 0],
                 'giris' => '08:00', 'giris_varsayilan' => true, 'rev' => 0, 'not' => '', 'ham' => '', 'kontrol' => '', 'cakisma_dosya' => false, 'cakisma_sira' => 0,
                 'hatalar' => [], 'uyarilar' => []];
}
/** Çavuşa üç tip için FM fiyatı tanımlar. $birim: tip → birim ya da tek değer. */
function fiyatKur(int $cid, string $mod = 'hourly', $birim = '200', ?array $saatler = null): void {
    global $kadin, $erkek, $rampaci;
    foreach (['KADIN' => $kadin, 'ERKEK' => $erkek, 'RAMPACI' => $rampaci] as $kod => $tid) {
        $b = is_array($birim) ? $birim[$kod] : $birim;
        $r = pdks_faz8b_oran_ekle($cid, $tid, '1500', '900', $mod, $b, '2020-01-01', 'TRY', 1, db(), $saatler);
        if (!$r['ok']) throw new RuntimeException('fiyatKur: ' . j($r));
    }
}
function onizle1(array $fis, array $sec = []): array {
    return pdks_fis_degerlendir(db(), $fis, $sec, 1);
}
function hVar(array $m, string $parca): bool { foreach (array_merge($m['hatalar'], $m['engeller'], $m['uyarilar']) as $x) if (str_contains($x, $parca)) return true; return false; }

/** toplu girdisi: gruplar = [[tipId, adet, 'HH:MM giriş', 'HH:MM çıkış'], …] */
function fis_toplu_v(array $gruplar, array $ek = []): array {
    global $cavus, $dun2, $AKTIF_DEPO;
    $g = [];
    foreach ($gruplar as [$tip, $adet, $gir, $cik]) {
        $g[] = ['worker_type_id' => $tip, 'entry_clock' => $gir, 'exit_date' => $dun2, 'exit_clock' => $cik, 'kartsiz_adet' => $adet];
    }
    return $ek + ['foreman_id' => $cavus['A'], 'work_date' => $dun2, 'depo' => $AKTIF_DEPO, 'reason' => 'Fiş girişi — test', 'note' => '',
                  'gruplar' => $g, 'istek_id' => istek32()];
}

echo "\n=== A. Faz 8J çekirdek opt-in (coklu_grup / yazdiktan_sonra / audit_ek) ===\n";
// A1: varsayılan davranış DEĞİŞMEZ
$v = fis_toplu_v([[$erkek, 2, '08:00', '17:00'], [$erkek, 1, '08:00', '19:00']]);
$h = pdks_faz8j_toplu_hazirla(db(), $v);
ok('A1) varsayılan: aynı tip iki grup → "Aynı işçi tipi" hatası', (bool)array_filter($h['hatalar'], fn($x) => str_contains($x, 'Aynı işçi tipi')), j($h['hatalar']));
$on = pdks_faz8j_toplu_onizle($v, 1, db());
ok('A1b) varsayılan: önizleme ok=false', $on['ok'] === false);

// A2: coklu_grup=true kabul eder; etiket hata metninde görünür
$h = pdks_faz8j_toplu_hazirla(db(), $v + ['coklu_grup' => true]);
ok('A2) coklu_grup: aynı tip iki grup KABUL (hata yok)', $h['hatalar'] === [], j($h['hatalar']));
ok('A2b) coklu_grup: 3 satır kuruldu (2 + 1), grup indeksleri 0/0/1', count($h['satirlar']) === 3 && array_column($h['satirlar'], 'grup') === [0, 0, 1], j(array_column($h['satirlar'], 'grup')));
$v2 = fis_toplu_v([[$erkek, 1, '08:00', '17:00'], [$erkek, 1, '99:99', '19:00']], ['coklu_grup' => true]);
$v2['gruplar'][1]['etiket'] = 'Erkek · FM 2 sa';
$h2 = pdks_faz8j_toplu_hazirla(db(), $v2);
ok('A2c) coklu_grup: grup etiketi hata mesajında', (bool)array_filter($h2['hatalar'], fn($x) => str_starts_with($x, 'Erkek · FM 2 sa:')), j($h2['hatalar']));
$v3 = fis_toplu_v([[$erkek, 1, '08:00', '17:00']]);
$v3['gruplar'][0]['etiket'] = 'ZARARLI';
$v3['gruplar'][0]['entry_clock'] = '99:99';
$h3 = pdks_faz8j_toplu_hazirla(db(), $v3);
ok('A2d) coklu_grup YOKKEN etiket anahtarı yok sayılır (etiket = tip adı)', (bool)array_filter($h3['hatalar'], fn($x) => str_starts_with($x, 'Erkek:')), j($h3['hatalar']));

// A3: yazdiktan_sonra RuntimeException → tx geri alınır
$once = sayimlar();
$cagrildi = 0;
$vh = fis_toplu_v([[$erkek, 2, '08:00', '17:00']], ['yazdiktan_sonra' => function () use (&$cagrildi) { $cagrildi++; throw new RuntimeException('Geri çağrı reddetti.'); }]);
$r = pdks_faz8j_toplu_ekle($vh, 1, db());
$sonra = sayimlar();
ok('A3) yazdiktan_sonra hata → ok=false + mesaj iletildi', $r['ok'] === false && ($r['hatalar'][0] ?? '') === 'Geri çağrı reddetti.', j($r['hatalar'] ?? null));
ok('A3b) geri çağrı tam 1 kez çağrıldı', $cagrildi === 1, (string)$cagrildi);
ok('A3c) tx geri alındı: kart/mesai/olay/dönem/audit/hakediş sayıları DEĞİŞMEDİ', $once === $sonra, j([$once, $sonra]));

// A4: callback argümanları + audit_ek (çekirdek anahtarlar ezilmez)
$arg = null;
$vk = fis_toplu_v([[$erkek, 2, '08:00', '17:00'], [$kadin, 1, '08:00', '17:00']], [
    'coklu_grup' => true,
    'audit_ek' => ['kaynak' => 'fis_excel', 'fis_no' => 'T-1', 'istek_id' => 'ezilmemeli', 'toplu_id' => 'ezilmemeli', 'session_id' => -5],
    'yazdiktan_sonra' => function (PDO $pdo, int $sid, array $pids, array $specs, array $oturum, int $user) use (&$arg) { $arg = compact('sid', 'pids', 'specs', 'oturum', 'user'); },
]);
$istekA4 = $vk['istek_id'];
$r = pdks_faz8j_toplu_ekle($vk, 1, db());
ok('A4) callback varken yazma başarılı (3 dönem)', $r['ok'] === true && $r['eklenen'] === 3, j($r['hatalar'] ?? $r));
ok('A4b) callback: sid/pids/specs/oturum/user doğru', $arg && $arg['sid'] === $r['session_id'] && $arg['pids'] === $r['period_ids'] && count($arg['specs']) === 3 && (int)$arg['oturum']['id'] === $r['session_id'] && $arg['user'] === 1, j($arg ? array_keys($arg) : null));
$aud = db()->query("SELECT new_values FROM audit_log WHERE action='puantaj_toplu_ekle' ORDER BY id DESC LIMIT 1")->fetchColumn();
$nv = json_decode((string)$aud, true) ?: [];
ok('A4c) audit_ek: kaynak/fis_no özet audit\'te', ($nv['kaynak'] ?? '') === 'fis_excel' && ($nv['fis_no'] ?? '') === 'T-1', (string)$aud);
ok('A4d) audit_ek çekirdek anahtarı EZMEZ (istek_id/toplu_id/session_id)', ($nv['istek_id'] ?? '') === $istekA4 && preg_match('/^TP\d{8}[0-9a-f]{8}$/D', (string)($nv['toplu_id'] ?? '')) && (int)($nv['session_id'] ?? 0) === $r['session_id'], (string)$aud);
ok('A4e) istek_id audit\'te TEK kez ("istek_id": kalıbı)', substr_count((string)$aud, '"istek_id"') === 1);

// A5: önizleme callback ÇAĞIRMAZ, yan etkisiz
$once = sayimlar(); $cagrildi = 0;
$vo = fis_toplu_v([[$erkek, 1, '08:00', '17:00']], ['coklu_grup' => true, 'yazdiktan_sonra' => function () use (&$cagrildi) { $cagrildi++; }]);
$vo['work_date'] = $dun3; foreach ($vo['gruplar'] as &$gg) $gg['exit_date'] = $dun3; unset($gg);
$on = pdks_faz8j_toplu_onizle($vo, 1, db());
ok('A5) önizleme: ok + callback ÇAĞRILMADI + sayımlar aynı', $on['ok'] === true && $cagrildi === 0 && $once === sayimlar(), j($on['hatalar'] ?? null));

// A6: HTTP ucundan gelemez
$ajax = file_get_contents($ROOT . '/_puantaj_toplu_ajax.php');
ok('A6) _puantaj_toplu_ajax.php coklu_grup/yazdiktan_sonra/audit_ek geçirmez', !preg_match('/coklu_grup|yazdiktan_sonra|audit_ek/', $ajax));

echo "\n=== B. Önizleme (pdks_fis_degerlendir / pdks_fis_onizle) ===\n";
// Çavuş A: fiyat kurulu (hourly, 3 tipte eşit). Çavuş B: fiyat YOK. Çavuş C: fixed. Çavuş D: farklı birim. Çavuş E: çift yevmiye.
fiyatKur($cavus['A']);
fiyatKur($cavus['C'], 'fixed', '300');
fiyatKur($cavus['D'], 'hourly', ['KADIN' => '200', 'ERKEK' => '250', 'RAMPACI' => '200']);
fiyatKur($cavus['E'], 'hourly', '200', ['full_day' => '9', 'double_day' => '12', 'double_day_rate' => '2000']);
ok('B0) servis fiyatı (Çavuş A) tanımlandı', pdks_servis_ucret_ekle($cavus['A'], '400', '250', '2020-01-01', 'TRY', 1, db())['ok'] === true);
db()->exec('UPDATE foremen SET is_active = 0 WHERE id = ' . (int)$cavus['H']);
$once = sayimlar();
$sec = ['onay_yarim' => true, 'onay_fm' => true];

$fA = fisYap(['KADIN' => [8, 0], 'ERKEK' => [12, 1]], ['fm_kova' => [2 => 5], 'fm_tipi' => ['ERKEK'], 'servis' => ['buyuk' => 0, 'kucuk' => 1], 'not' => 'Rampa']);
$mA = onizle1($fA, $sec);
ok('B1) Örnek A: durum hazır, hata/engel yok', $mA['durum'] === 'hazir' && $mA['hatalar'] === [] && $mA['engeller'] === [], j([$mA['durum'], $mA['hatalar'], $mA['uyarilar']]));
ok('B1b) Örnek A: 21 kişi, gruplar Kadın tam 8 · Erkek tam 7 · fm2 5 · yarım 1', $mA['kisi'] === 21 && array_map(fn($g) => $g['kod'] . $g['profil'] . $g['adet'], $mA['plan']['gruplar']) === ['KADINtam8', 'ERKEKtam7', 'ERKEKfm5', 'ERKEKyarim1'], j($mA['plan']['gruplar'] ?? null));
ok('B1c) Örnek A: servis hedef 0/1, yazılacak 0/1; istek kimlikleri 32 hex', $mA['servis']['yazilacak'] === ['buyuk' => 0, 'kucuk' => 1] && preg_match('/^[a-f0-9]{32}$/D', $mA['istek']['fis']) === 1);
ok('B1d) Örnek A: seçilebilir + varsayılan seçili; yazma durumu yeni', $mA['secilebilir'] && $mA['varsayilan_secili'] && $mA['yazma']['durum'] === 'yeni');
ok('B1e) önizleme YAN ETKİSİZ (sayımlar aynı)', $once === sayimlar(), j([$once, sayimlar()]));
ok('B1f) mesai henüz yok: mevcut.mesai=false', $mA['mevcut']['mesai'] === false && $mA['mevcut']['aktif_donem'] === 0);

$fB = fisYap(['KADIN' => [8, 0], 'ERKEK' => [12, 0], 'RAMPACI' => [2, 1]], ['fm_toplam' => 25, 'servis' => ['buyuk' => 1, 'kucuk' => 0]]);
$mB = onizle1($fB, $sec);
ok('B2) Örnek B (Toplam FM 25, hourly eşit): hazır, 9 kişi 25 sa, nötr', $mB['durum'] === 'hazir' && $mB['plan']['fm']['toplam_saat'] === 25 && $mB['plan']['fm']['kisi'] === 9 && $mB['plan']['fm']['notr'] === true, j([$mB['durum'], $mB['hatalar'], $mB['plan']['fm'] ?? null]));
ok('B2b) Örnek B: kişi_toplam 23', $mB['kisi'] === 23);
ok('B2c) aynı fiş iki kez değerlendirilince AYNI hash', onizle1($fB, $sec)['hash'] === $mB['hash'] && $mB['hash'] !== '');

$mh = onizle1(fisYap(['ERKEK' => [3, 0]], ['hatalar' => ['Satır 5, «Erkek Tam»: x']]));
ok('B3) normalize hatalı fiş → durum hata, seçilemez', $mh['durum'] === 'hata' && !$mh['secilebilir'] && $mh['hatalar'] === ['Satır 5, «Erkek Tam»: x']);

$mi = onizle1(fisYap(['ERKEK' => [3, 0]], ['isletme' => 'Bambaşka Depo']), $sec);
ok('B4) işletme uyuşmazlığı → durum onay (işaretsiz, seçilebilir)', $mi['durum'] === 'onay' && $mi['isletme_uyusmaz'] && $mi['secilebilir'] && !$mi['varsayilan_secili'], j([$mi['durum'], $mi['uyarilar']]));
$mi2 = onizle1(fisYap(['ERKEK' => [3, 0]], ['isletme' => 'Bambaşka Depo']), $sec + ['isletme_onay' => true]);
ok('B4b) isletme_onay=true → onay değil (uyarı)', $mi2['durum'] === 'uyari', $mi2['durum']);
$mi3 = onizle1(fisYap(['ERKEK' => [3, 0]], ['isletme' => 'DEPO a']), $sec);
ok('B4c) işletme "DEPO a" ≡ aktif depo "Depo A" → uyuşur', $mi3['durum'] !== 'onay' && !$mi3['isletme_uyusmaz'], $mi3['durum']);
$mi4 = onizle1(fisYap(['ERKEK' => [3, 0]], ['isletme' => 'Depo']), $sec);
ok('B4d) işletme "Depo" (aktif depoda geçer) → uyuşur', !$mi4['isletme_uyusmaz']);

// yarım: sınıf doğrulaması
$my = onizle1(fisYap(['ERKEK' => [0, 4]]), $sec);
ok('B5) yalnız Yarım fiş: hazır; yarım grup 270 dk (4 sa 30 dk)', $my['durum'] === 'hazir' && $my['plan']['gruplar'][0]['profil'] === 'yarim' && $my['plan']['gruplar'][0]['sure_dk'] === 270 && $my['plan']['gruplar'][0]['exit_clock'] === '12:30', j([$my['durum'], $my['hatalar'], $my['plan']['gruplar'] ?? null]));

// FM fiyat nötrlüğü
$mf = onizle1(fisYap(['KADIN' => [6, 0], 'ERKEK' => [6, 0]], ['cavus' => $cavus['B'], 'fm_toplam' => 6]), $sec);
ok('B6) fiyat YOK + (b) Toplam FM → hata (nötrlük doğrulanamaz)', $mf['durum'] === 'hata' && hVar($mf, 'dağıtım'), j([$mf['durum'], $mf['hatalar']]));
$mf = onizle1(fisYap(['KADIN' => [6, 0], 'ERKEK' => [6, 0]], ['cavus' => $cavus['B'], 'fm_kova' => [2 => 2]]), $sec);
ok('B6b) fiyat YOK + (a) kova → UYARI (hata değil)', $mf['durum'] === 'uyari' && hVar($mf, 'fiyat'), j([$mf['durum'], $mf['hatalar'], $mf['uyarilar']]));
$mf = onizle1(fisYap(['ERKEK' => [12, 0]], ['cavus' => $cavus['A'], 'fm_toplam' => 7]), $sec);
ok('B6c) hourly eşit birim + (b) → hazır', $mf['durum'] === 'hazir' && $mf['plan']['fm']['notr'] === true, j([$mf['durum'], $mf['hatalar']]));
$mf = onizle1(fisYap(['ERKEK' => [12, 0]], ['cavus' => $cavus['C'], 'fm_toplam' => 7]), $sec);
ok('B6d) fixed + (b) + FM Kişi Sayısı boş → hata', $mf['durum'] === 'hata' && hVar($mf, 'FM Kişi Sayısı'), j([$mf['durum'], $mf['hatalar']]));
$mf = onizle1(fisYap(['ERKEK' => [12, 0]], ['cavus' => $cavus['C'], 'fm_toplam' => 7, 'fm_kisi' => 3]), $sec);
ok('B6e) fixed + (b) + FM Kişi Sayısı=3 → hazır [3,2,2]', $mf['durum'] === 'hazir' && $mf['plan']['fm']['saatler'] === [3, 2, 2], j([$mf['durum'], $mf['hatalar']]));
$mf = onizle1(fisYap(['KADIN' => [6, 0], 'ERKEK' => [6, 0]], ['cavus' => $cavus['D'], 'fm_toplam' => 6]), $sec);
ok('B6f) farklı birim + (b) → hata', $mf['durum'] === 'hata', j([$mf['durum'], $mf['hatalar']]));
$mf = onizle1(fisYap(['KADIN' => [6, 0], 'ERKEK' => [6, 0]], ['cavus' => $cavus['D'], 'fm_kova' => [2 => 2]]), $sec);
ok('B6g) farklı birim + (a) + FM Tipi boş → hata', $mf['durum'] === 'hata' && hVar($mf, 'FM Tipi'), j([$mf['durum'], $mf['hatalar']]));
$mf = onizle1(fisYap(['KADIN' => [6, 0], 'ERKEK' => [6, 0]], ['cavus' => $cavus['D'], 'fm_kova' => [2 => 2], 'fm_tipi' => ['ERKEK']]), $sec);
ok('B6h) farklı birim + (a) + FM Tipi=Erkek → hazır', $mf['durum'] === 'hazir', j([$mf['durum'], $mf['hatalar']]));
$mf = onizle1(fisYap(['KADIN' => [6, 0], 'ERKEK' => [6, 0]], ['cavus' => $cavus['D'], 'fm_kova' => [2 => 2]]), $sec + ['fm_tip' => 'erkek']);
ok('B6i) sayfa seçeneği fm_tip=erkek de aynı işi görür', $mf['durum'] === 'hazir', j([$mf['durum'], $mf['hatalar']]));

// çift yevmiye
$me = onizle1(fisYap(['ERKEK' => [10, 0]], ['cavus' => $cavus['E'], 'fm_kova' => [6 => 1]]), $sec);
ok('B7) çift eşiği (12 sa) + (a) FM6 → UYARI "Çift yevmiye"', $me['durum'] === 'uyari' && hVar($me, 'Çift yevmiye'), j([$me['durum'], $me['hatalar'], $me['uyarilar']]));
$me = onizle1(fisYap(['ERKEK' => [20, 0]], ['cavus' => $cavus['E'], 'fm_toplam' => 25]), $sec);
ok('B7b) çift eşiği + (b) → V5 dağılımı (12×2 sa + 1×1 sa), çift eşiğine girmez', $me['durum'] === 'hazir' && $me['plan']['fm']['saatler'] === array_merge(array_fill(0, 12, 2), [1]) && !hVar($me, 'Çift yevmiye'), j([$me['durum'], $me['hatalar'], $me['plan']['fm']['saatler'] ?? null]));

// tarih / çavuş / hakediş kapıları
$mt = onizle1(fisYap(['ERKEK' => [3, 0]], ['tarih' => $bugun]), $sec);
ok('B8) bugünün tarihi → hata', $mt['durum'] === 'hata' && hVar($mt, 'Bugün'));
$mt = onizle1(fisYap(['ERKEK' => [3, 0]], ['tarih' => date('Y-m-d', strtotime('+2 day'))]), $sec);
ok('B8b) gelecek tarih → hata', $mt['durum'] === 'hata');
$mp = onizle1(fisYap(['ERKEK' => [3, 0]], ['cavus' => $cavus['H']]), $sec);
ok('B9) pasif çavuş + mesai yok → hata (Faz 8J oturum_coz)', $mp['durum'] === 'hata' && hVar($mp, 'pasif'), j([$mp['durum'], $mp['hatalar']]));
$mc = onizle1(fisYap(['ERKEK' => [3, 0]], ['cavus' => 99999]), $sec);
ok('B9b) olmayan çavuş id → hata', $mc['durum'] === 'hata', j($mc['hatalar']));

// kesin hakediş: mesai + final hakediş
db()->prepare("INSERT INTO daily_work_sessions (foreman_id, foreman_name_snapshot, foreman_code_snapshot, normal_work_minutes_snapshot, work_date, depo, status, opened_at, closed_at) VALUES (?,?,?,?,?,?,?,?,?)")
    ->execute([$cavus['F'], 'Çavuş F', 'C006', 540, $dun3, 'Depo A', 'closed', $dun3 . ' 08:00:00', $dun3 . ' 17:00:00']);
$sidF = (int)db()->lastInsertId();
db()->prepare("INSERT INTO foreman_daily_entitlements (session_id, foreman_id, work_date, depo, status) VALUES (?,?,?,?, 'final')")->execute([$sidF, $cavus['F'], $dun3, 'Depo A']);
$mk = onizle1(fisYap(['ERKEK' => [3, 0]], ['cavus' => $cavus['F']]), $sec + ['ek_yaz' => true]);
ok('B10) kesin hakediş → hata (EK yaz AŞAMAZ)', $mk['durum'] === 'hata' && hVar($mk, 'kesinleşmiş'), j([$mk['durum'], $mk['hatalar']]));

// mevcut aktif dönem → engel; EK → uyarı
db()->prepare("INSERT INTO daily_work_sessions (foreman_id, foreman_name_snapshot, foreman_code_snapshot, normal_work_minutes_snapshot, work_date, depo, status, opened_at, closed_at) VALUES (?,?,?,?,?,?,?,?,?)")
    ->execute([$cavus['G'], 'Çavuş G', 'C007', 540, $dun3, 'Depo A', 'closed', $dun3 . ' 08:00:00', $dun3 . ' 17:00:00']);
$sidG = (int)db()->lastInsertId();
pdks_faz8j_toplu_ekle(['foreman_id' => $cavus['G'], 'work_date' => $dun3, 'depo' => 'Depo A', 'reason' => 'Elle girilmiş', 'note' => '', 'istek_id' => istek32(),
    'gruplar' => [['worker_type_id' => $erkek, 'entry_clock' => '08:00', 'exit_date' => $dun3, 'exit_clock' => '17:00', 'kartsiz_adet' => 4]]], 1, db());
$mg = onizle1(fisYap(['ERKEK' => [3, 0]], ['cavus' => $cavus['G']]), $sec);
ok('B11) mesaide aktif dönem var → engel (EK yaz gerekli)', $mg['durum'] === 'engel' && $mg['mevcut']['aktif_donem'] === 4 && hVar($mg, 'EK yaz') && !$mg['varsayilan_secili'] && $mg['secilebilir'], j([$mg['durum'], $mg['engeller']]));
$mg2 = onizle1(fisYap(['ERKEK' => [3, 0]], ['cavus' => $cavus['G']]), $sec + ['ek_yaz' => true]);
ok('B11b) EK yaz → uyarı ("mevcut 4 kayıt korunuyor")', $mg2['durum'] === 'uyari' && hVar($mg2, '4 mevcut kayıt korunuyor'), j([$mg2['durum'], $mg2['uyarilar']]));
// tüm dönemler iptal → engel yok
db()->exec('UPDATE daily_worker_work_periods SET is_voided = 1 WHERE session_id = ' . $sidG);
$mg3 = onizle1(fisYap(['ERKEK' => [3, 0]], ['cavus' => $cavus['G']]), $sec);
ok('B11c) mevcut dönemlerin hepsi iptal → engel YOK', $mg3['durum'] !== 'engel' && $mg3['mevcut']['aktif_donem'] === 0, j([$mg3['durum'], $mg3['engeller']]));
// dosya içi çakışma
$mcak = onizle1(fisYap(['ERKEK' => [3, 0]], ['cavus' => $cavus['A'], 'cakisma_dosya' => true, 'cakisma_sira' => 1]), $sec);
ok('B12) aynı dosyada aynı çavuş+gün 2. fiş → engel', $mcak['durum'] === 'engel' && hVar($mcak, 'Aynı dosyada'), j([$mcak['durum'], $mcak['engeller']]));
$mcak0 = onizle1(fisYap(['ERKEK' => [3, 0]], ['cavus' => $cavus['A'], 'cakisma_dosya' => true, 'cakisma_sira' => 0]), $sec);
ok('B12b) aynı dosyadaki İLK fiş normal (engel değil)', $mcak0['durum'] !== 'engel');

// servis kapıları
$mv = onizle1(fisYap(['ERKEK' => [3, 0]], ['cavus' => $cavus['B'], 'servis' => ['buyuk' => 0, 'kucuk' => 1]]), $sec);
ok('B13) servis fiyatı tanımsız → UYARI (fiyatsız bilgisi), yazılacak 0/1', $mv['durum'] === 'uyari' && hVar($mv, 'servis fiyatı tanımlı değil') && $mv['servis']['yazilacak'] === ['buyuk' => 0, 'kucuk' => 1], j([$mv['durum'], $mv['uyarilar']]));
$mo = onizle1(fisYap([], ['servis' => ['buyuk' => 1, 'kucuk' => 0]]), $sec);
ok('B13b) yalnız servisli fiş → hata', $mo['durum'] === 'hata' && hVar($mo, 'servis'));
$mo = onizle1(fisYap([]), $sec);
ok('B13c) boş fiş → hata', $mo['durum'] === 'hata');

// çavuş tarihli normal süre (v351) ve fiyat dönemi saatleri — sınıflandırıcıdan çözülür, plan buna uyar
$rs = pdks_cavus_sure_kaydet($cavus['B'], 480, '2020-01-01', null, 'Test: 8 saatlik anlaşma', 1, db());
ok('L0) çavuş tarihli normal süre (480 dk) kaydedildi', ($rs['ok'] ?? false) === true, j($rs));
$ml = onizle1(fisYap(['KADIN' => [3, 1]], ['cavus' => $cavus['B'], 'fm_kova' => [2 => 1], 'fm_tipi' => ['KADIN']]), $sec);
ok('L1) süre 480: Tam çıkış 16:00 · FM2 18:00 · yarım 4 sa (12:00)', $ml['durum'] !== 'hata' && array_column($ml['plan']['gruplar'], 'exit_clock') === ['16:00', '18:00', '12:00'], j([$ml['durum'], $ml['hatalar'], array_column($ml['plan']['gruplar'] ?? [], 'exit_clock')]));
$cid = $cavus['D'];
$rl = pdks_faz8b_oran_ekle($cid, $erkek, '1500', '900', 'hourly', '250', '2026-01-01', 'TRY', 1, db(), ['full_day' => '10', 'overtime_start' => '11']);
ok('L2) fiyat dönemi (Tam 10 sa, FM başı 11 sa) eklendi', ($rl['ok'] ?? false) === true, j($rl));
$ml = onizle1(fisYap(['ERKEK' => [2, 0]], ['cavus' => $cid, 'fm_kova' => [2 => 1]]), $sec);
ok('L3) fiyat dönemi: Tam çıkış 18:00 · FM2 çıkışı 08:00 + 11 sa + 2 sa = 21:00', $ml['durum'] !== 'hata' && array_column($ml['plan']['gruplar'], 'exit_clock') === ['18:00', '21:00'], j([$ml['durum'], $ml['hatalar'], array_column($ml['plan']['gruplar'] ?? [], 'exit_clock')]));

// pdks_fis_onizle toplu
$on = pdks_fis_onizle(db(), [$fA, $fB, fisYap(['ERKEK' => [3, 0]], ['hatalar' => ['x']])], $sec, 1);
ok('B14) pdks_fis_onizle: fiş sayısı + durum sayıları + kişi/FM/servis toplamı', $on['ozet']['fis'] === 3 && $on['ozet']['durum'] === ['hazir' => 2, 'hata' => 1] && $on['ozet']['kisi'] === 44 && $on['ozet']['fm_saat'] === 35 && $on['ozet']['servis'] === ['buyuk' => 1, 'kucuk' => 1], j($on['ozet']));
ok('B15) önizleme sonunda da YAN ETKİ yok (dönem sayısı = A4 + elle girilen 4)', say('daily_worker_work_periods') === 7 && say('daily_session_services') === 0, j(sayimlar()));
ok('B16) pdks_fis_durum: bilinmeyen istek → yeni', pdks_fis_durum(db(), str_repeat('a', 32)) === ['durum' => 'yeni']);

echo "\n--- Sonuç: $gecen geçti, $fail kaldı ---\n";
exit($fail > 0 ? 1 : 0);
