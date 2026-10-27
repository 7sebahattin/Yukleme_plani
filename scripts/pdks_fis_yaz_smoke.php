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

// ---------------------------------------------------------------------------------------------
echo "\n=== C. Yazma — Örnek A (Tam / FM-k / Yarım gruplar, onaylar, servis, audit) ===\n";
function yeniCavus(string $ad, bool $fiyat = true, bool $servisFiyat = true): int {
    static $n = 20; $n++;
    $id = (int)pdks_gunluk_cavus_olustur(['code' => 'C0' . $n, 'name' => $ad], 1, db())['id'];
    if ($fiyat) fiyatKur($id);
    if ($servisFiyat) pdks_servis_ucret_ekle($id, '400', '250', '2020-01-01', 'TRY', 1, db());
    return $id;
}
function yaz1(array $fis, array $sec): array {
    $m = pdks_fis_degerlendir(db(), $fis, $sec, 1);
    $r = pdks_fis_yaz(db(), [$fis], $sec, 1, str_repeat('b', 64), [$fis['satir']], [$fis['satir'] => $m['hash']]);
    return $r['sonuclar'][0];
}
function donemler(int $sid): array { return pdks_faz8b_oturum_donemleri($sid, db()); }
function hhmm(string $dt): string { return substr($dt, 11, 5); }
function auditSay(string $a): int { global $AUDIT; return count(array_filter($AUDIT, fn($x) => $x[0] === $a)); }

$cA = yeniCavus('Çavuş CA');
$fisA = fisYap(['KADIN' => [8, 0], 'ERKEK' => [12, 1]], ['cavus' => $cA, 'fm_kova' => [2 => 5], 'fm_tipi' => ['ERKEK'], 'servis' => ['buyuk' => 0, 'kucuk' => 1], 'not' => 'Rampa', 'fis_no' => 'A-001']);
$audit0 = ['ekle' => fis_audit_say('puantaj_ekle'), 'toplu' => fis_audit_say('puantaj_toplu_ekle'), 'servis' => fis_audit_say('servis_ekle'), 'fis' => fis_audit_say('fis_yukle'), 'upd' => auditSay('update')];
$r = yaz1($fisA, $sec);
ok('C1) Örnek A yazıldı', $r['durum'] === 'yazildi' && $r['yazilan']['kisi'] === 21, j($r));
$sidA = (int)$r['session_id'];
$dA = donemler($sidA);
ok('C2) 21 aktif dönem', count($dA) === 21, (string)count($dA));
$say2 = []; foreach ($dA as $d) { $k = $d['worker_type_name_snapshot'] . ' ' . hhmm($d['entry_time']) . '→' . hhmm($d['exit_time']); $say2[$k] = ($say2[$k] ?? 0) + 1; }
ksort($say2);
ok('C3) Kadın 8×(08:00→17:00) · Erkek 7×17:00, 5×19:00, 1×12:30', $say2 === ['Erkek 08:00→12:30' => 1, 'Erkek 08:00→17:00' => 7, 'Erkek 08:00→19:00' => 5, 'Kadın 08:00→17:00' => 8], j($say2));
$kaynak = db()->query('SELECT DISTINCT source FROM daily_worker_work_periods WHERE session_id = ' . $sidA)->fetchAll(PDO::FETCH_COLUMN);
ok('C4) dönem kaynağı source=manual (kartsız sanal kart)', $kaynak === ['manual'], j($kaynak));
$kartsiz = (int)db()->query("SELECT COUNT(*) FROM daily_worker_work_periods p JOIN worker_cards w ON w.id = p.worker_card_id WHERE p.session_id = $sidA AND w.enrolled_source = 'kartsiz'")->fetchColumn();
ok('C5) hepsi kartsız (KARTSIZ-… sanal kart)', $kartsiz === 21);
$ms = db()->query("SELECT * FROM daily_work_sessions WHERE id = $sidA")->fetch();
ok('C6) mesai KAPALI, açılış 08:00, kapanış 19:00 (geçmiş gün yolu)', $ms['status'] === 'closed' && hhmm((string)$ms['opened_at']) === '08:00' && hhmm((string)$ms['closed_at']) === '19:00', j([$ms['status'], $ms['opened_at'], $ms['closed_at']]));
$tamOto = count(array_filter($dA, fn($d) => $d['faz8b']['otomatik_sinif'] === 'tam'));
$fmOnayli = count(array_filter($dA, fn($d) => $d['faz8b']['fazla_mesai_saat'] === 2 && (int)$d['overtime_approved_hours'] === 2 && $d['faz8b']['fazla_mesai_durum'] === 'onayli'));
$yarimOnayli = count(array_filter($dA, fn($d) => $d['approved_attendance_class'] === 'yarim' && $d['faz8b']['etkin_sinif'] === 'yarim'));
ok('C7) sınıflandırıcı: 20 otomatik Tam · 5 FM(2) onaylı · 1 Yarım onaylı', $tamOto === 20 && $fmOnayli === 5 && $yarimOnayli === 1, j([$tamOto, $fmOnayli, $yarimOnayli]));
ok('C8) finans_hazir 21/21', count(array_filter($dA, fn($d) => $d['faz8b']['finans_hazir'])) === 21);
ok('C9) Tam dönemlere onay yazılmadı (approved_attendance_class boş)', count(array_filter($dA, fn($d) => $d['approved_attendance_class'] === null || $d['approved_attendance_class'] === '')) === 20);
$oz = pdks_faz8b_gun_mesai_ozeti([$sidA], db());
$e = $oz['tanim']['Erkek'] ?? []; $k = $oz['tanim']['Kadın'] ?? [];
ok('C10) Mesai Özeti: Kadın Tam 8 · Erkek Tam 12 (7+5 FM) · Yarım 1 · FM2=5 · Bekliyor 0', ($k['tam'] ?? 0) === 8 && ($e['tam'] ?? 0) === 12 && ($e['yarim'] ?? 0) === 1 && ($e['fm'][2] ?? 0) === 5 && ($e['bekliyor'] ?? 0) === 0 && $oz['fm_saat'] === 10, j($oz['tanim'] ?? $oz));
ok('C11) audit: 21 puantaj_ekle + 1 puantaj_toplu_ekle + 1 servis_ekle + 1 fis_yukle', fis_audit_say('puantaj_ekle') - $audit0['ekle'] === 21 && fis_audit_say('puantaj_toplu_ekle') - $audit0['toplu'] === 1 && fis_audit_say('servis_ekle') - $audit0['servis'] === 1 && fis_audit_say('fis_yukle') - $audit0['fis'] === 1);
ok('C12) onay güncellemeleri: 6 update (5 FM onayı + 1 Yarım kararı; Tam satırlarında çağrı YOK)', auditSay('update') - $audit0['upd'] === 6, (string)(auditSay('update') - $audit0['upd']));
$tp = json_decode((string)db()->query("SELECT new_values FROM audit_log WHERE action='puantaj_toplu_ekle' AND record_id = $sidA")->fetchColumn(), true);
ok('C13) toplu özet audit: kaynak=fis_excel, fis_no, dosya_sha256, reason', ($tp['kaynak'] ?? '') === 'fis_excel' && ($tp['fis_no'] ?? '') === 'A-001' && ($tp['dosya_sha256'] ?? '') === str_repeat('b', 64) && ($tp['reason'] ?? '') === 'Fiş girişi — Fiş No A-001 (Excel)' && ($tp['note'] ?? '') === 'Rampa', j($tp));
$fy = json_decode((string)db()->query("SELECT new_values FROM audit_log WHERE action='fis_yukle' AND record_id = $sidA")->fetchColumn(), true);
ok('C14) fis_yukle audit: durum tamam, toplu_id, istek_id TEK kez, servis_istek_id ayrı', ($fy['durum'] ?? '') === 'tamam' && ($fy['toplu_id'] ?? '') === $r['toplu_id'] && ($fy['istek_id'] ?? '') === pdks_fis_istek_idleri('Depo A', 'A-001', 0)['fis'] && ($fy['servis_istek_id'] ?? '') === pdks_fis_istek_idleri('Depo A', 'A-001', 0)['servis'] && substr_count((string)db()->query("SELECT new_values FROM audit_log WHERE action='fis_yukle' AND record_id = $sidA")->fetchColumn(), '"istek_id"') === 1, j($fy));
$sv = pdks_servis_toplamlar($sidA, db()); $svl = pdks_servis_listele($sidA, db());
ok('C15) servis: KÜÇÜK=1, notu [FIS A-001] önekli', $sv['KUCUK'] === 1 && $sv['BUYUK'] === 0 && str_starts_with((string)$svl[0]['note'], '[FIS A-001] Fiş Excel') && str_contains((string)$svl[0]['note'], 'Rampa'), j($svl));
$hak = db()->query("SELECT COUNT(*) FROM foreman_daily_entitlements WHERE session_id = $sidA")->fetchColumn();
ok('C16) sonuç: yazilan.fm_onayli=5 yarim=1 servis küçük 1', $r['yazilan']['fm_onayli'] === 5 && $r['yazilan']['yarim'] === 1 && $r['yazilan']['servis'] === ['buyuk' => 0, 'kucuk' => 1], j($r['yazilan']));
$r2 = yaz1($fisA, $sec);
ok('C17) aynı fiş ikinci kez → zaten_yuklu, hiçbir kayıt çiftlenmedi', $r2['durum'] === 'zaten_yuklu' && count(donemler($sidA)) === 21 && pdks_servis_toplamlar($sidA, db())['KUCUK'] === 1, j($r2));

echo "\n=== D. Onay kutuları kapalı ===\n";
$cD = yeniCavus('Çavuş CD');
$u0 = auditSay('update');
$fisD = fisYap(['ERKEK' => [6, 1]], ['cavus' => $cD, 'fm_kova' => [2 => 2], 'fis_no' => 'D-001']);
$r = yaz1($fisD, ['onay_yarim' => false, 'onay_fm' => false]);
$dD = donemler((int)$r['session_id']);
ok('D1) onay_yarim=false → Yarım "Bekliyor" (etkin sınıf null)', $r['durum'] === 'yazildi' && count(array_filter($dD, fn($d) => $d['faz8b']['etkin_sinif'] === null && $d['faz8b']['sinif_onayi_gerekli'])) === 1, j($r));
ok('D2) onay_fm=false → FM adayları "bekliyor"', count(array_filter($dD, fn($d) => $d['faz8b']['fazla_mesai_durum'] === 'bekliyor')) === 2);
ok('D3) ikisi de kapalıyken degerlendirme_kaydet HİÇ çağrılmaz (audit update yok)', auditSay('update') === $u0);
$r = yaz1(fisYap(['ERKEK' => [4, 1]], ['cavus' => yeniCavus('Çavuş CD2'), 'fm_kova' => [1 => 1], 'fis_no' => 'D-002']), ['onay_yarim' => true, 'onay_fm' => false]);
$dD2 = donemler((int)$r['session_id']);
ok('D4) yalnız onay_yarim → Yarım onaylı, FM bekliyor', count(array_filter($dD2, fn($d) => $d['faz8b']['etkin_sinif'] === 'yarim')) === 1 && count(array_filter($dD2, fn($d) => $d['faz8b']['fazla_mesai_durum'] === 'bekliyor')) === 1);

echo "\n=== E. (b) Toplam FM — Örnek B (V1), determinizm ===\n";
$cB1 = yeniCavus('Çavuş CB1'); $cB2 = yeniCavus('Çavuş CB2');
$fisB1 = fisYap(['KADIN' => [8, 0], 'ERKEK' => [12, 0], 'RAMPACI' => [2, 1]], ['cavus' => $cB1, 'fm_toplam' => 25, 'servis' => ['buyuk' => 1, 'kucuk' => 0], 'fis_no' => 'B-001', 'tarih' => $dun2]);
$fisB2 = $fisB1; $fisB2['cavus'] = ['id' => $cB2, 'kod' => '', 'ad' => '', 'yontem' => 'kod']; $fisB2['fis_no'] = 'B-002'; $fisB2['tarih'] = $dun;
$rB1 = yaz1($fisB1, $sec); $rB2 = yaz1($fisB2, $sec);
ok('E1) Örnek B iki çavuşa yazıldı', $rB1['durum'] === 'yazildi' && $rB2['durum'] === 'yazildi', j([$rB1['mesaj'] ?? '', $rB2['mesaj'] ?? '']));
$profil = function (int $sid): array {
    $o = [];
    foreach (donemler($sid) as $d) {
        $fm = (int)((strtotime($d['exit_time']) - strtotime($d['entry_time'])) / 60 - 540) / 60;
        $anahtar = $d['worker_type_name_snapshot'] . ($d['faz8b']['etkin_sinif'] === null || $d['faz8b']['sinif_onayi_gerekli'] ? ' yarım' : ($fm > 0 ? " fm{$fm}" : ' tam'));
        $o[$anahtar] = ($o[$anahtar] ?? 0) + 1;
    }
    ksort($o); return $o;
};
$p1 = $profil((int)$rB1['session_id']); $p2 = $profil((int)$rB2['session_id']);
ok('E2) dağılım V1: Kadın fm3×2+fm2×1 · Erkek fm3×4+fm2×1 · Rampacı fm3×1 (+ Tam / Yarım)', $p1 === ['Erkek fm2' => 1, 'Erkek fm3' => 4, 'Erkek tam' => 7, 'Kadın fm2' => 1, 'Kadın fm3' => 2, 'Kadın tam' => 5, 'Rampacı fm3' => 1, 'Rampacı tam' => 1, 'Rampacı yarım' => 1], j($p1));
ok('E3) iki bağımsız yazma BİREBİR aynı dağılım (deterministik)', $p1 === $p2, j([$p1, $p2]));
$oz = pdks_faz8b_gun_mesai_ozeti([(int)$rB1['session_id']], db());
ok('E4) Mesai Özeti toplam FM 25 sa; Rampacı yarım 1', $oz['fm_saat'] === 25 && ($oz['tanim']['Rampacı']['yarim'] ?? 0) === 1, j([$oz['fm_saat'], $oz['tanim']['Rampacı'] ?? null]));
ok('E5) toplam FM onaylı 9 kişi', $rB1['yazilan']['fm_onayli'] === 9 && $rB1['yazilan']['fm_saat'] === 25, j($rB1['yazilan']));
ok('E6) servis BÜYÜK=1', pdks_servis_toplamlar((int)$rB1['session_id'], db())['BUYUK'] === 1);

echo "\n=== F. Servis ===\n";
$ff = pdks_fis_servis_fark(['buyuk' => 2, 'kucuk' => 1], ['servis_toplam' => ['BUYUK' => 1, 'KUCUK' => 0], 'servis_fis' => ['BUYUK' => 0, 'KUCUK' => 0]], false);
ok('F1) fark: hedef 2 / mevcut 1 (EK kapalı) → +1 Büyük, Küçük +1', $ff['yazilacak'] === ['buyuk' => 1, 'kucuk' => 1], j($ff));
$ff = pdks_fis_servis_fark(['buyuk' => 1, 'kucuk' => 0], ['servis_toplam' => ['BUYUK' => 3, 'KUCUK' => 0], 'servis_fis' => ['BUYUK' => 0, 'KUCUK' => 0]], false);
ok('F2) hedef < mevcut → yazılacak 0 + uyarı', $ff['yazilacak'] === ['buyuk' => 0, 'kucuk' => 0] && $ff['uyarilar'] !== [], j($ff));
$ff = pdks_fis_servis_fark(['buyuk' => 0, 'kucuk' => 1], ['servis_toplam' => ['BUYUK' => 0, 'KUCUK' => 1], 'servis_fis' => ['BUYUK' => 0, 'KUCUK' => 0]], true);
ok('F3) EK açık: elle (etiketsiz) servis hesaba katılmaz → +1', $ff['yazilacak']['kucuk'] === 1, j($ff));
$cF = yeniCavus('Çavuş CF');
$rr = yaz1(fisYap(['ERKEK' => [2, 0]], ['cavus' => $cF, 'fis_no' => 'F-001']), $sec);
$sidF2 = (int)$rr['session_id'];
pdks_faz8j_toplu_geri_al((string)$rr['toplu_id'], 'test', 1, db());
pdks_servis_ekle($sidF2, 0, 1, 'elle girilmiş', istek32(), 1, db());
$rr2 = yaz1(fisYap(['ERKEK' => [2, 0]], ['cavus' => $cF, 'fis_no' => 'F-002', 'servis' => ['buyuk' => 0, 'kucuk' => 1]]), $sec);
ok('F4) elle girilmiş (etiketsiz) servis 1 + hedef 1 → servis YAZILMAZ (çift sayılmaz)', $rr2['durum'] === 'yazildi' && pdks_servis_toplamlar($sidF2, db())['KUCUK'] === 1 && $rr2['yazilan']['servis'] === ['buyuk' => 0, 'kucuk' => 0], j([$rr2['mesaj'] ?? '', pdks_servis_toplamlar($sidF2, db())]));
// servis tabloları yok + servis>0 → fiş hata, işçi yazılmaz
$cF3 = yeniCavus('Çavuş CF3');
$once = sayimlar();
db()->exec('ALTER TABLE daily_session_services RENAME TO daily_session_services_x');
$rr3 = yaz1(fisYap(['ERKEK' => [2, 0]], ['cavus' => $cF3, 'fis_no' => 'F-003', 'servis' => ['buyuk' => 1, 'kucuk' => 0]]), $sec);
db()->exec('ALTER TABLE daily_session_services_x RENAME TO daily_session_services');
ok('F5) servis tabloları yok + servis>0 → hata ve işçi YAZILMAZ', $rr3['durum'] === 'hata' && str_contains($rr3['mesaj'], 'Servis') && $once === sayimlar(), j([$rr3, $once, sayimlar()]));
$rr4 = yaz1(fisYap(['ERKEK' => [2, 0]], ['cavus' => yeniCavus('Çavuş CF4', true, false), 'fis_no' => 'F-004', 'servis' => ['buyuk' => 0, 'kucuk' => 2]]), $sec);
ok('F6) servis fiyatı yok → yine yazılır + fiyatsız bilgisi (uyarı)', $rr4['durum'] === 'yazildi' && $rr4['yazilan']['servis'] === ['buyuk' => 0, 'kucuk' => 2] && (bool)array_filter($rr4['uyarilar'] ?? [], fn($x) => str_contains($x, 'fiyatı tanımlı değil')), j($rr4));
$rr5 = yaz1(fisYap([], ['cavus' => $cF, 'fis_no' => 'F-005', 'servis' => ['buyuk' => 1, 'kucuk' => 0]]), $sec);
ok('F7) yalnız-servis fiş → hata', $rr5['durum'] === 'hata');

echo "\n=== G. Kısmi başarı / idempotency ===\n";
$cG = yeniCavus('Çavuş CG');
$fisG = fisYap(['ERKEK' => [3, 0]], ['cavus' => $cG, 'fis_no' => 'G-001', 'servis' => ['buyuk' => 0, 'kucuk' => 1]]);
db()->exec("CREATE TRIGGER sv_kir BEFORE INSERT ON daily_session_services BEGIN SELECT RAISE(ABORT, 'simule servis hatasi'); END");
$rg = yaz1($fisG, $sec);
$sidG2 = (int)($rg['session_id'] ?? 0);
ok('G1) servis aşaması başarısız: durum hata, işçi kayıtları YAZILI', $rg['durum'] === 'hata' && $sidG2 > 0 && count(donemler($sidG2)) === 3, j($rg));
ok('G2) mesaj: yeniden yükleyin yönergesi + toplu_id', str_contains($rg['mesaj'], 'yeniden yükleyerek') && str_contains($rg['mesaj'], 'TP'), $rg['mesaj']);
ok('G3) fis_yukle izi YOK (tamamlanmadı)', (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE action='fis_yukle' AND record_id = $sidG2")->fetchColumn() === 0);
$mG = pdks_fis_degerlendir(db(), $fisG, $sec, 1);
ok('G4) önizleme durumu "devam" (servis tamamlanacak)', $mG['durum'] === 'devam' && $mG['varsayilan_secili'], j([$mG['durum'], $mG['hatalar']]));
db()->exec('DROP TRIGGER sv_kir');
$rg2 = yaz1($fisG, $sec);
ok('G5) tablo düzelince aynı fiş → devam_tamamlandi: servis yazıldı, dönem sayısı DEĞİŞMEDİ, fis_yukle yazıldı', $rg2['durum'] === 'devam_tamamlandi' && count(donemler($sidG2)) === 3 && pdks_servis_toplamlar($sidG2, db())['KUCUK'] === 1 && (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE action='fis_yukle' AND record_id = $sidG2")->fetchColumn() === 1, j($rg2));
$rg3 = yaz1($fisG, $sec);
ok('G6) üçüncü kez → zaten_yuklu', $rg3['durum'] === 'zaten_yuklu');
// çift tıklama: istek_id ile toplu_ekle "tekrar" → yarım yoluna düşer
$cG2 = yeniCavus('Çavuş CG2');
$fisG2 = fisYap(['ERKEK' => [2, 0]], ['cavus' => $cG2, 'fis_no' => 'G-002', 'servis' => ['buyuk' => 1, 'kucuk' => 0]]);
$mG2 = pdks_fis_degerlendir(db(), $fisG2, $sec, 1);
$girdi = pdks_fis_toplu_girdisi($fisG2, $mG2['plan'], pdks_fis_secenek($sec), $mG2['istek']['fis'], str_repeat('c', 64));
$on1 = pdks_faz8j_toplu_ekle($girdi, 1, db());     // "yarışta" diğer istek işçi kayıtlarını yazdı
ok('G7) (çift tıklama kurulumu) işçi kayıtları yazıldı', $on1['ok'] === true);
$rg4 = pdks_fis_yaz_fis(db(), $fisG2, pdks_fis_secenek($sec), 1, str_repeat('c', 64), null);
ok('G8) aynı istek_id ile ikinci istek: devam_tamamlandi, ÇİFT KAYIT YOK', $rg4['durum'] === 'devam_tamamlandi' && say('daily_worker_work_periods') > 0 && count(donemler((int)$rg4['session_id'])) === 2 && pdks_servis_toplamlar((int)$rg4['session_id'], db())['BUYUK'] === 1, j($rg4));
$mG3 = pdks_fis_degerlendir(db(), $fisG2, $sec, 1);
$rG5 = pdks_fis_yaz(db(), [$fisG2], $sec, 1, 'x', [$fisG2['satir']], [$fisG2['satir'] => 'yanlis-hash']);
ok('G9) tamamlanmış fişte hash uyuşmazlığı → yine zaten_yuklu (yeniden yazılmaz)', $rG5['sonuclar'][0]['durum'] === 'zaten_yuklu');

echo "\n=== H. Mevcut kayıt / EK yaz ===\n";
$cH = yeniCavus('Çavuş CH');
$rh = yaz1(fisYap(['ERKEK' => [3, 0]], ['cavus' => $cH, 'fis_no' => 'H-001']), $sec);
$sidH = (int)$rh['session_id'];
$once = sayimlar();
$fisH2 = fisYap(['KADIN' => [2, 0]], ['cavus' => $cH, 'fis_no' => 'H-002']);
$rh2 = yaz1($fisH2, $sec);
ok('H1) aktif dönem varken başka fiş → hata (engel), HİÇBİR ŞEY yazılmadı', $rh2['durum'] === 'hata' && str_contains($rh2['mesaj'], 'EK yaz') && $once === sayimlar(), j([$rh2, $once, sayimlar()]));
$rh3 = yaz1($fisH2, $sec + ['ek_yaz' => true]);
ok('H2) ek_yaz → yazılır, mevcut 3 kayıt KORUNUR (3 + 2)', $rh3['durum'] === 'yazildi' && count(donemler($sidH)) === 5, j([$rh3['mesaj'] ?? '', count(donemler($sidH))]));
// hepsi iptal → engel yok
$cH2 = yeniCavus('Çavuş CH2');
$rh4 = yaz1(fisYap(['ERKEK' => [2, 0]], ['cavus' => $cH2, 'fis_no' => 'H-003']), $sec);
pdks_faz8j_toplu_geri_al((string)$rh4['toplu_id'], 'test', 1, db());
$rh5 = yaz1(fisYap(['ERKEK' => [2, 0]], ['cavus' => $cH2, 'fis_no' => 'H-004']), $sec);
ok('H3) mevcut dönemlerin hepsi iptal → engel yok, yazılır', $rh5['durum'] === 'yazildi', j($rh5));
// aynı dosyada aynı çavuş+gün iki fiş: dosya sırası, ikinci engel; EK ile yazılır
$cH3 = yeniCavus('Çavuş CH3');
$f1 = fisYap(['ERKEK' => [2, 0]], ['cavus' => $cH3, 'fis_no' => 'H-005', 'cakisma_dosya' => true, 'cakisma_sira' => 0]);
$f2 = fisYap(['KADIN' => [2, 0]], ['cavus' => $cH3, 'fis_no' => 'H-006', 'cakisma_dosya' => true, 'cakisma_sira' => 1]);
$hh = [$f1['satir'] => pdks_fis_degerlendir(db(), $f1, $sec, 1)['hash'], $f2['satir'] => pdks_fis_degerlendir(db(), $f2, $sec, 1)['hash']];
$ry = pdks_fis_yaz(db(), [$f1, $f2], $sec, 1, 'x', [$f1['satir'], $f2['satir']], $hh);
ok('H4) aynı dosyada aynı çavuş+gün: 1. yazılır, 2. engel (EK yaz gerekli)', $ry['sonuclar'][0]['durum'] === 'yazildi' && $ry['sonuclar'][1]['durum'] === 'hata' && $ry['ozet']['yazildi'] === 1 && $ry['ozet']['hata'] === 1, j(array_column($ry['sonuclar'], 'durum')));
$ry2 = pdks_fis_yaz(db(), [$f2], $sec + ['ek_yaz' => true], 1, 'x', [$f2['satir']], [$f2['satir'] => pdks_fis_degerlendir(db(), $f2, $sec + ['ek_yaz' => true], 1)['hash']]);
ok('H5) EK yaz ile 2. fiş de yazılır', $ry2['sonuclar'][0]['durum'] === 'yazildi', j($ry2['sonuclar'][0]));

echo "\n=== I. Çoklu fiş / bağımsız transaction ===\n";
$c1 = yeniCavus('Çavuş CI1'); $c2 = yeniCavus('Çavuş CI2'); $c3 = yeniCavus('Çavuş CI3');
$i1 = fisYap(['ERKEK' => [2, 0]], ['cavus' => $c1, 'fis_no' => 'I-001']);
$i2 = fisYap(['ERKEK' => [2, 0]], ['cavus' => $c2, 'fis_no' => 'I-002', 'hatalar' => ['Satır 9, «Erkek Tam»: hatalı']]);
$i3 = fisYap(['KADIN' => [3, 0]], ['cavus' => $c3, 'fis_no' => 'I-003']);
$hh = []; foreach ([$i1, $i3] as $f) $hh[$f['satir']] = pdks_fis_degerlendir(db(), $f, $sec, 1)['hash'];
$ri = pdks_fis_yaz(db(), [$i1, $i2, $i3], $sec, 1, 'x', [$i1['satir'], $i2['satir'], $i3['satir']], $hh);
ok('I1) 3 fişten 1\'i hatalı: diğer 2 yazılır, sonuç listesi doğru', array_column($ri['sonuclar'], 'durum') === ['yazildi', 'hata', 'yazildi'] && $ri['ozet']['yazildi'] === 2 && $ri['ozet']['hata'] === 1 && $ri['ozet']['kisi'] === 5, j($ri));
$i2d = $i2; $i2d['hatalar'] = [];
$hh[$i2d['satir']] = pdks_fis_degerlendir(db(), $i2d, $sec, 1)['hash'];
$ri2 = pdks_fis_yaz(db(), [$i1, $i2d, $i3], $sec, 1, 'x', array_keys($hh), $hh);
ok('I2) düzeltilmiş dosya yeniden: ilk ikisi zaten_yuklu, üçüncü yazıldı', array_column($ri2['sonuclar'], 'durum') === ['zaten_yuklu', 'yazildi', 'zaten_yuklu'], j(array_column($ri2['sonuclar'], 'durum')));
$i4 = fisYap(['ERKEK' => [2, 0]], ['cavus' => $cavus['F'], 'fis_no' => 'I-004']);
$i5 = fisYap(['ERKEK' => [2, 0]], ['cavus' => yeniCavus('Çavuş CI5'), 'fis_no' => 'I-005']);
$ri3 = pdks_fis_yaz(db(), [$i4, $i5], $sec, 1, 'x', [$i4['satir'], $i5['satir']], []);
ok('I3) kesin hakedişli günün fişi hata, diğerleri etkilenmez', $ri3['sonuclar'][0]['durum'] === 'hata' && str_contains($ri3['sonuclar'][0]['mesaj'], 'kesinleşmiş') && $ri3['sonuclar'][1]['durum'] === 'yazildi', j($ri3['sonuclar']));
$ri4 = pdks_fis_yaz(db(), [$i5], $sec, 1, 'x', [], []);
ok('I4) seçilmeyen fiş işlenmez', $ri4['sonuclar'] === [] && $ri4['ozet']['islenen'] === 0);
$i6 = fisYap(['ERKEK' => [2, 0]], ['cavus' => yeniCavus('Çavuş CI6'), 'fis_no' => 'I-006']);
$ri5 = pdks_fis_yaz(db(), [$i6], $sec, 1, 'x', [$i6['satir']], [$i6['satir'] => 'eski-hash']);
ok('I5) önizleme hash\'i ile yazma anı hash\'i farklı → "degisti", YAZILMAZ', $ri5['sonuclar'][0]['durum'] === 'degisti' && say('daily_worker_work_periods') === say('daily_worker_work_periods') && count(donemler((int)(db()->query("SELECT id FROM daily_work_sessions WHERE foreman_id = {$i6['cavus']['id']}")->fetchColumn() ?: 0))) === 0, j($ri5['sonuclar'][0]));
$i7 = fisYap(['ERKEK' => [2, 0]], ['cavus' => yeniCavus('Çavuş CI7'), 'fis_no' => 'I-007', 'isletme' => 'Bambaşka']);
$ri6 = pdks_fis_yaz(db(), [$i7], $sec, 1, 'x', [$i7['satir']], []);
ok('I6) işletme uyuşmazlığı onaysız → hata; onayla → yazılır', $ri6['sonuclar'][0]['durum'] === 'hata' && str_contains($ri6['sonuclar'][0]['mesaj'], 'uyuşmazlığı') && pdks_fis_yaz(db(), [$i7], $sec + ['isletme_onay' => true], 1, 'x', [$i7['satir']], [])['sonuclar'][0]['durum'] === 'yazildi');

echo "\n=== J. Geri al + revizyon ===\n";
$cJ = yeniCavus('Çavuş CJ');
$fJ = fisYap(['ERKEK' => [4, 0]], ['cavus' => $cJ, 'fis_no' => 'J-001', 'servis' => ['buyuk' => 1, 'kucuk' => 0]]);
$rj = yaz1($fJ, $sec); $sidJ = (int)$rj['session_id'];
$gj = pdks_faz8j_toplu_geri_al((string)$rj['toplu_id'], 'Fiş yanlış okunmuş', 1, db());
ok('J1) Toplu Geri Al → tüm dönemler iptal', $gj['ok'] === true && count(donemler($sidJ)) === 0, j($gj));
$mj = pdks_fis_degerlendir(db(), $fJ, $sec, 1);
ok('J2) aynı Rev ile yükleme → zaten (geri alınmış ifadesi)', $mj['durum'] === 'zaten' && (bool)array_filter($mj['uyarilar'], fn($x) => str_contains($x, 'GERİ ALINMIŞ')), j([$mj['durum'], $mj['uyarilar']]));
$fJ1 = $fJ; $fJ1['rev'] = 1; $fJ1['servis'] = ['buyuk' => 2, 'kucuk' => 0];
$rj1 = yaz1($fJ1, $sec);
ok('J3) Rev=1 → yazılır; servis ÇİFTLENMEZ (mevcut 1, hedef 2 → +1 = 2)', $rj1['durum'] === 'yazildi' && $rj1['yazilan']['servis'] === ['buyuk' => 1, 'kucuk' => 0] && pdks_servis_toplamlar($sidJ, db())['BUYUK'] === 2, j([$rj1['mesaj'] ?? '', pdks_servis_toplamlar($sidJ, db())]));
$fy1 = json_decode((string)db()->query("SELECT new_values FROM audit_log WHERE action='fis_yukle' AND record_id = $sidJ ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
ok('J4) fis_yukle rev=1, istek_id revizyona bağlı', ($fy1['rev'] ?? null) === 1 && $fy1['istek_id'] === pdks_fis_istek_idleri('Depo A', 'J-001', 1)['fis'] && $fy1['istek_id'] !== pdks_fis_istek_idleri('Depo A', 'J-001', 0)['fis'], j($fy1));
ok('J5) geri alma onayları SIFIRLAR: yeni dönemler taze (Rev=1 toplam 4 aktif)', count(donemler($sidJ)) === 4);

echo "\n=== K. Kapılar / token ===\n";
$cK = yeniCavus('Çavuş CK');
$fK = fisYap(['ERKEK' => [2, 0]], ['cavus' => $cK, 'fis_no' => 'K-001']);
$once = sayimlar();
$ADMIN = false;
$rk = pdks_fis_yaz(db(), [$fK], $sec, 1, 'x', [$fK['satir']], []);
$ADMIN = true;
ok('K1) admin değil → yetki hatası, hiçbir yazma', $rk['sonuclar'][0]['durum'] === 'hata' && $once === sayimlar(), j($rk['sonuclar'][0]));
$AKTIF_DEPO = '';
$rk = pdks_fis_yaz(db(), [$fK], $sec, 1, 'x', [$fK['satir']], []);
$AKTIF_DEPO = 'Depo A';
ok('K2) aktif depo yok → hata, hiçbir yazma', $rk['sonuclar'][0]['durum'] === 'hata' && $once === sayimlar());
$AKTIF_DEPO = 'Depo B';
$mkB = pdks_fis_degerlendir(db(), $fK, $sec, 1);
ok('K3) aktif depo B: mevcut A mesaisi/dönemleri görülmez (depo kapsamlı), yeni B mesaisi yazılacak', $mkB['mevcut']['mesai'] === false, j($mkB['mevcut']));
$AKTIF_DEPO = 'Depo A';
// token (v447: oturum birincil + hks_kv yedek)
db()->exec('CREATE TABLE IF NOT EXISTS hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)');
$_SESSION = [];
$t = pdks_fis_token_olustur(['sha' => 'x', 'fisler' => [1], 'secenek' => [], 'hashler' => []]);
ok('K4) token 32 hex; geri alınır; kullanıcı + depo bağlı', preg_match('/^[a-f0-9]{32}$/D', $t) === 1 && (pdks_fis_token_al($t)['sha'] ?? '') === 'x');
$AKTIF_DEPO = 'Depo B';
ok('K5) farklı aktif depoda token geçersiz', pdks_fis_token_al($t) === null);
$AKTIF_DEPO = 'Depo A';
ok('K6) bozuk biçimli / olmayan token → null', pdks_fis_token_al('zz') === null && pdks_fis_token_al(str_repeat('a', 32)) === null && pdks_fis_token_al('../../etc') === null);
$_SESSION['fis_yukle'][$t]['t'] = time() - PDKS_FIS_TOKEN_TTL - 5;
ok('K7) TTL (30 dk) dolunca token geçersiz ve silinir', pdks_fis_token_al($t) === null && !isset($_SESSION['fis_yukle'][$t]));
$_SESSION = []; $ts = [];
for ($i = 0; $i < 5; $i++) $ts[] = pdks_fis_token_olustur(['sha' => (string)$i]);
ok('K8) oturumda en çok 3 token (en eskiler oturumdan atılır)', count($_SESSION['fis_yukle']) === 3 && !isset($_SESSION['fis_yukle'][$ts[0]]) && pdks_fis_token_al($ts[4]) !== null);
pdks_fis_token_sil($ts[4]);
ok('K9) token_sil (oturum + yedek)', pdks_fis_token_al($ts[4]) === null && pdks_fis_kv_oku(PDKS_FIS_KV_ONIZLE, $ts[4]) === null);

// v447 — oturum kaybı: önizleme GET'i oturumu göremese de yedekten çözülür
$_SESSION = [];
$t2 = pdks_fis_token_olustur(['sha' => 'yedek', 'fisler' => [['satir' => 2]], 'secenek' => ['fm_tip' => 'tumu'], 'hashler' => []]);
$_SESSION = [];   // oturum yazımı kaçırıldı / yeni oturum
$g = pdks_fis_token_al($t2, $neden);
ok('K10) oturum boşken token hks_kv yedeğinden çözülür (kullanıcı + depo + TTL aynı kurallar)', $g !== null && ($g['sha'] ?? '') === 'yedek' && $neden === null, j($g));
pdks_fis_token_hashler($t2, [2 => 'abc']);
$_SESSION = [];
ok('K11) hash\'ler yedeğe de yazılır (yazma anında karşılaştırma oturumsuz da çalışır)', (pdks_fis_token_al($t2)['hashler'][2] ?? '') === 'abc');
$AKTIF_DEPO = 'Depo B';
$_SESSION = [];
$gb = pdks_fis_token_al($t2, $nedenB);
ok('K12) yedekten gelen token da depoya bağlı (neden=depo)', $gb === null && $nedenB === 'depo');
$AKTIF_DEPO = 'Depo A';
$kv = pdks_fis_kv_oku(PDKS_FIS_KV_ONIZLE, $t2); $kv['uid'] = 99999;
pdks_fis_kv_yaz(PDKS_FIS_KV_ONIZLE, $t2, $kv); $_SESSION = [];
$gu = pdks_fis_token_al($t2, $nedenU);
ok('K13) yedekten gelen token başka kullanıcıya ait → null (neden=kullanici)', $gu === null && $nedenU === 'kullanici');
$kv['uid'] = pdks_fis_kullanici_id(); $kv['t'] = time() - PDKS_FIS_TOKEN_TTL - 5;
pdks_fis_kv_yaz(PDKS_FIS_KV_ONIZLE, $t2, $kv); $_SESSION = [];
$gs = pdks_fis_token_al($t2, $nedenS);
ok('K14) yedekte TTL dolunca null (neden=sure) ve yedek silinir', $gs === null && $nedenS === 'sure' && pdks_fis_kv_oku(PDKS_FIS_KV_ONIZLE, $t2) === null);
pdks_fis_token_al(str_repeat('b', 32), $nedenY);
ok('K15) hiçbir yerde yok → neden=yok; bozuk biçim → neden=bicim', $nedenY === 'yok' && (pdks_fis_token_al('zz', $nedenZ) === null) && $nedenZ === 'bicim');
ok('K16) hata metinleri nedene göre ayrışır', pdks_fis_token_hata_metni('sure') !== pdks_fis_token_hata_metni('yok') && str_contains(pdks_fis_token_hata_metni('yok'), 'bulunamadı') && str_contains(pdks_fis_token_hata_metni('depo'), 'depo'));
// 2 saatten eski yedek kayıtları temizlenir; taze olanlara dokunulmaz
$eskiTok = bin2hex(random_bytes(16)); $tazeTok = bin2hex(random_bytes(16));
db()->prepare('REPLACE INTO hks_kv (anahtar, deger) VALUES (?, ?)')->execute([PDKS_FIS_KV_ONIZLE . $eskiTok, json_encode(['t' => time() - 8000, 'x' => 1])]);
pdks_fis_kv_yaz(PDKS_FIS_KV_ONIZLE, $tazeTok, ['t' => time(), 'uid' => 1, 'depo' => 'x']);
ok('K17) eski (>2 sa) yedek temizlenir, taze kalır', pdks_fis_kv_oku(PDKS_FIS_KV_ONIZLE, $eskiTok) === null && pdks_fis_kv_oku(PDKS_FIS_KV_ONIZLE, $tazeTok) !== null);
// hks_kv tablosu yoksa özellik oturumla çalışmaya devam eder (fail soft)
db()->exec('DROP TABLE hks_kv'); $_SESSION = [];
$t3 = pdks_fis_token_olustur(['sha' => 'tabloyok']);
ok('K18) hks_kv yokken token yalnız oturumla çalışır (hata/istisna yok)', (pdks_fis_token_al($t3)['sha'] ?? '') === 'tabloyok');
db()->exec('CREATE TABLE hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)');

echo "\n=== L. Gerçek sınıflandırıcı — yazma sonrası (çavuş süresi / fiyat dönemi / çift yevmiye) ===\n";
// Çavuş B: tarihli süre 480 (B bölümünde kaydedildi); fiyatı yok → servis/FM nötrlüğü (a) uyarı ile yazılır
$rl = yaz1(fisYap(['KADIN' => [3, 1]], ['cavus' => $cavus['B'], 'fis_no' => 'L-001', 'fm_kova' => [2 => 1], 'fm_tipi' => ['KADIN'], 'tarih' => $dun2]), $sec);
$dl = $rl['durum'] === 'yazildi' ? donemler((int)$rl['session_id']) : [];
ok('L1) süre 480: Tam 16:00 · FM2 18:00 · yarım 12:00 — yazılan dönemler plan ile örtüşür', $rl['durum'] === 'yazildi' && array_count_values(array_map(fn($d) => hhmm($d['exit_time']), $dl)) == ['16:00' => 2, '18:00' => 1, '12:00' => 1] && count(array_filter($dl, fn($d) => $d['faz8b']['finans_hazir'])) === 4, j([$rl, array_map(fn($d) => hhmm($d['exit_time']), $dl)]));
// Çavuş D (Erkek dönemi Tam 10 sa, FM başı 11 sa)
$rl2 = yaz1(fisYap(['ERKEK' => [2, 0]], ['cavus' => $cavus['D'], 'fis_no' => 'L-002', 'fm_kova' => [2 => 1]]), $sec);
$dl2 = $rl2['durum'] === 'yazildi' ? donemler((int)$rl2['session_id']) : [];
ok('L2) fiyat dönemi Tam 10 sa / FM başı 11 sa: Tam 18:00 · FM2 21:00', $rl2['durum'] === 'yazildi' && array_count_values(array_map(fn($d) => hhmm($d['exit_time']), $dl2)) == ['18:00' => 1, '21:00' => 1], j([$rl2, array_map(fn($d) => hhmm($d['exit_time']), $dl2)]));
// Çavuş E: çift yevmiye 12 sa → (b) K artar, çift eşiğine girilmez
$rl3 = yaz1(fisYap(['ERKEK' => [20, 0]], ['cavus' => $cavus['E'], 'fis_no' => 'L-003', 'fm_toplam' => 25]), $sec);
$dl3 = $rl3['durum'] === 'yazildi' ? donemler((int)$rl3['session_id']) : [];
ok('L3) çift eşiği 12 sa: (b) 12 kişi×2 sa + 1 kişi×1 sa; hiçbir dönem "çift" sınıfına girmez; toplam FM 25', $rl3['durum'] === 'yazildi' && count(array_filter($dl3, fn($d) => $d['faz8b']['etkin_sinif'] === 'cift')) === 0 && array_sum(array_map(fn($d) => $d['faz8b']['odenecek_fm_saat'], $dl3)) === 25, j([$rl3, array_sum(array_map(fn($d) => $d['faz8b']['odenecek_fm_saat'], $dl3))]));
// çavuş E (a) FM6 → çift eşiği: UYARI ile yazılır; onaylı FM6 → çift sınıfı
$rl4 = yaz1(fisYap(['ERKEK' => [3, 0]], ['cavus' => $cavus['E'], 'fis_no' => 'L-004', 'fm_kova' => [6 => 1], 'tarih' => $dun2]), $sec);
$dl4 = $rl4['durum'] === 'yazildi' ? donemler((int)$rl4['session_id']) : [];
ok('L4) (a) FM6 çift eşiğine girer: UYARI ile yazıldı; sınıflandırıcı bu kişiyi "çift" sayar', $rl4['durum'] === 'yazildi' && (bool)array_filter($rl4['uyarilar'], fn($x) => str_contains($x, 'Çift yevmiye')) && count(array_filter($dl4, fn($d) => $d['faz8b']['etkin_sinif'] === 'cift')) === 1, j([$rl4, array_map(fn($d) => $d['faz8b']['etkin_sinif'], $dl4)]));
ok('L5) yazma-içi doğrulama: sınıflandırıcı farklı çıkarsa tx geri alınır (sentetik bozulma)', (function () use ($cavus, $erkek, $sec) {
    // plan Tam 17:00 bekler; geri çağrıyı bilerek yanlış plan süresiyle çağırıp RuntimeException beklenir
    $cx = yeniCavus('Çavuş CL5');
    $f = fisYap(['ERKEK' => [2, 0]], ['cavus' => $cx, 'fis_no' => 'L-005']);
    $m = pdks_fis_degerlendir(db(), $f, $sec, 1);
    $plan = $m['plan']; $plan['gruplar'][0]['exit_clock'] = '15:00';   // plan yalan söylüyor (Tam değil)
    $girdi = pdks_fis_toplu_girdisi($f, $plan, pdks_fis_secenek($sec), $m['istek']['fis'], 'x');
    $girdi['yazdiktan_sonra'] = pdks_fis_onay_geri_cagrisi($plan, pdks_fis_secenek($sec), new ArrayObject(['fm_onayli' => 0, 'yarim' => 0, 'fm_saat' => 0]));
    $once = sayimlar();
    $r = pdks_faz8j_toplu_ekle($girdi, 1, db());
    return $r['ok'] === false && $once === sayimlar() && (str_contains(implode(' ', $r['hatalar']), 'Hiçbir şey yazılmadı') || str_contains(implode(' ', $r['hatalar']), 'uyuşm'));
})());

echo "\n=== M. Statik güvenceler ===\n";
$fk = file_get_contents($ROOT . '/config/pdks_fis.php');
ok('M1) pdks_fis.php toplu_ekle / servis_ekle / degerlendirme_kaydet / donemleri_siniflandir çağırır', str_contains($fk, 'pdks_faz8j_toplu_ekle(') && str_contains($fk, 'pdks_servis_ekle(') && str_contains($fk, 'pdks_faz8b_degerlendirme_kaydet(') && str_contains($fk, 'pdks_faz8b_donemleri_siniflandir('));
$govde = function (string $ad) use ($fk): string { $i = strpos($fk, 'function ' . $ad . '('); $j = strpos($fk, "\n}\n", $i); return substr($fk, $i, $j - $i); };
ok('M2) servis yalnız commit sonrası: pdks_servis_ekle toplu_ekle callback\'inde ÇAĞRILMAZ', !str_contains($govde('pdks_fis_onay_geri_cagrisi'), 'pdks_servis_ekle(') && str_contains($govde('pdks_fis_servis_yaz'), 'pdks_servis_ekle('));
ok('M3) fis_yukle audit\'inde istek_id anahtarı bir kez', substr_count(substr($fk, strpos($fk, "'fis_yukle', \$sid"), 900), "'istek_id'") === 1);

echo "\n--- Sonuç: $gecen geçti, $fail kaldı ---\n";
exit($fail > 0 ? 1 : 0);
