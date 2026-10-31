<?php
// =========================================================
// scripts/pdks_fis_sifirla_smoke.php — «Çavuşun fiş yüklemelerini geri al» + «Etkilenen fişler raporu» testleri (v464, W4).
//
// Bellek içi SQLite + gerçek DDL çevirici + gerçek fonksiyonlar (scripts/_pdks_fis_harness.php; canlı DB'ye dokunmaz).
//   php scripts/pdks_fis_sifirla_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
require __DIR__ . '/_pdks_fis_harness.php';
require_once $ROOT . '/config/pdks_fis.php';

function istek32(): string { return bin2hex(random_bytes(16)); }
function fisYap(array $say, array $o = []): array {
    global $dun3;
    static $n = 0; $n++;
    $s = ['KADIN' => ['tam' => 0, 'yarim' => 0], 'ERKEK' => ['tam' => 0, 'yarim' => 0], 'RAMPACI' => ['tam' => 0, 'yarim' => 0], 'SERITCI' => ['tam' => 0, 'yarim' => 0]];
    foreach ($say as $k => [$t, $y]) $s[$k] = ['tam' => $t, 'yarim' => $y];
    $cv = $o['cavus']; unset($o['cavus']);
    return $o + ['satir' => 1 + $n, 'fis_no' => 'T' . $n, 'fis_no_ham_sayi' => false, 'tarih' => $dun3, 'isletme' => '', 'cavus' => ['id' => $cv, 'kod' => '', 'ad' => '', 'yontem' => 'kod'],
                 'say' => $s, 'fm_kova' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0], 'fm_toplam' => null, 'fm_kisi' => null, 'fm_tipi' => null, 'servis' => ['buyuk' => 0, 'kucuk' => 0],
                 'giris' => '08:00', 'giris_varsayilan' => true, 'rev' => 0, 'not' => '', 'ham' => '', 'kontrol' => '', 'cakisma_dosya' => false, 'cakisma_sira' => 0,
                 'hatalar' => [], 'uyarilar' => []];
}
$cavusSayac = 20;
function yeniCavus(string $ad): int {
    global $cavusSayac, $kadin, $erkek, $rampaci, $seritci; $cavusSayac++;
    $id = (int)pdks_gunluk_cavus_olustur(['code' => 'C0' . $cavusSayac, 'name' => $ad], 1, db())['id'];
    foreach ([$kadin, $erkek, $rampaci, $seritci] as $tid) pdks_faz8b_oran_ekle($id, $tid, '1500', '900', 'hourly', '200', '2020-01-01', 'TRY', 1, db());
    pdks_servis_ucret_ekle($id, '400', '250', '2020-01-01', 'TRY', 1, db());
    return $id;
}
function yaz1(array $fis, array $sec): array {
    $m = pdks_fis_degerlendir(db(), $fis, $sec, 1);
    return pdks_fis_yaz(db(), [$fis], $sec, 1, str_repeat('b', 64), [$fis['satir']], [$fis['satir'] => $m['hash']])['sonuclar'][0];
}
function aktifSay(int $sid): int { $st = db()->prepare('SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id = ? AND is_voided = 0'); $st->execute([$sid]); return (int)$st->fetchColumn(); }
function tumSay(): array { return ['donem' => say('daily_worker_work_periods'), 'mesai' => say('daily_work_sessions'), 'kart' => say('worker_cards'), 'olay' => say('daily_worker_card_events'), 'servis' => say('daily_session_services')]; }
function hVarS(array $dz, string $p): bool { foreach ($dz as $x) if (str_contains((string)$x, $p)) return true; return false; }

$sec = ['onay_yarim' => true, 'onay_fm' => true];
$gun = fn(int $n) => date('Y-m-d', strtotime("-$n day"));
$c1 = yeniCavus('Çavuş S1'); $c2 = yeniCavus('Çavuş S2');
$bas = $gun(10); $bit = $gun(2);

// Kurulum: S1 için aralıkta (-9, -8, -7, -6) 4 gün fiş; -6 gününde ayrıca fiş-dışı kayıt; -7 günü KESİN hakediş; aralık dışı (-20) 1 gün; S2 aynı aralıkta 1 gün
$f = [];
foreach ([9 => 'G9', 8 => 'G8', 7 => 'G7', 6 => 'G6', 20 => 'G20'] as $n => $no) {
    $f[$n] = fisYap(['ERKEK' => [3, 1]], ['cavus' => $c1, 'fis_no' => $no, 'tarih' => $gun($n), 'servis' => $n === 9 ? ['buyuk' => 1, 'kucuk' => 0] : ['buyuk' => 0, 'kucuk' => 0]]);
    $r = yaz1($f[$n], $sec);
    if ($r['durum'] !== 'yazildi') throw new RuntimeException('kurulum: ' . j($r));
    $f[$n]['_sid'] = (int)$r['session_id']; $f[$n]['_toplu'] = $r['toplu_id'];
}
$fS2 = fisYap(['ERKEK' => [2, 0]], ['cavus' => $c2, 'fis_no' => 'S2-1', 'tarih' => $gun(8)]);
$rS2 = yaz1($fS2, $sec); $sidS2 = (int)$rS2['session_id'];
// -6 gününe fiş-dışı 2 Kadın (elle toplu ekleme: kaynak yok)
$elle = pdks_faz8j_toplu_ekle(['foreman_id' => $c1, 'work_date' => $gun(6), 'depo' => $AKTIF_DEPO, 'reason' => 'elle', 'istek_id' => istek32(),
    'gruplar' => [['worker_type_id' => $kadin, 'entry_clock' => '08:00', 'exit_date' => $gun(6), 'exit_clock' => '17:00', 'kartsiz_adet' => 2]]], 1, db());
if (empty($elle['ok'])) throw new RuntimeException('fiş-dışı: ' . j($elle));
// -7 günü kesin hakediş
db()->prepare("INSERT INTO foreman_daily_entitlements (session_id, foreman_id, work_date, depo, status, needs_recalculation) VALUES (?, ?, ?, 'Depo A', 'final', 0)")->execute([$f[7]['_sid'], $c1, $gun(7)]);
// -8 günü taslak hakediş
db()->prepare("INSERT INTO foreman_daily_entitlements (session_id, foreman_id, work_date, depo, status, needs_recalculation) VALUES (?, ?, ?, 'Depo A', 'draft', 0)")->execute([$f[8]['_sid'], $c1, $gun(8)]);
// başka depoda bir mesai (S1, -5 günü, Depo B)
$AKTIF_DEPO = 'Depo B';
$fB = fisYap(['ERKEK' => [2, 0]], ['cavus' => $c1, 'fis_no' => 'GB', 'tarih' => $gun(5)]);
$rB = yaz1($fB, $sec); $sidB = (int)($rB['session_id'] ?? 0);
$AKTIF_DEPO = 'Depo A';

echo "\n=== 1. Önizleme (salt okunur döküm) ===\n";
$once = sayimlar();
$on = pdks_fis_sifirla_onizle(db(), $c1, $bas, $bit, 'Depo A');
ok('1a) hata yok', $on['hata'] === null, (string)$on['hata']);
ok('1b) uygun mesailer: -9, -8, -6 (3 mesai); toplam 12 + 4 + 4... dönem', count($on['mesailer']) === 3 && array_column($on['mesailer'], 'tarih') === [$gun(9), $gun(8), $gun(6)], j(array_column($on['mesailer'], 'tarih')));
ok('1c) toplam: 3 mesai · 12 dönem (3×4) · 1 servis', $on['toplam']['mesai'] === 3 && $on['toplam']['donem'] === 12 && $on['toplam']['servis'] === 1 && $on['toplam']['toplu'] === 3, j($on['toplam']));
$atl = []; foreach ($on['atlanan'] as $a) $atl[$a['durum']][] = $a['tarih'];
ok('1d) atlananlar: kesin hakediş (-7) + başka depo (-5)', ($atl['kesin'] ?? []) === [$gun(7)] && ($atl['baska_depo'] ?? []) === [$gun(5)], j($atl));
ok('1e) fiş-dışı kayıtlar belirtilir (-6: 2 kayıt) ama listede iptal sayısına GİRMEZ', array_values(array_filter($on['mesailer'], fn($m) => $m['tarih'] === $gun(6)))[0]['fis_disi'] === 2 && array_values(array_filter($on['mesailer'], fn($m) => $m['tarih'] === $gun(6)))[0]['donem'] === 4);
ok('1f) aralık dışı (-20) ve başka çavuş (S2) listede YOK', !in_array($gun(20), array_column($on['mesailer'], 'tarih'), true) && !in_array($sidS2, array_column($on['mesailer'], 'session_id'), true));
ok('1g) fiş numaraları: G9, G8, G6', $on['fis_nolari'] === ['G9', 'G8', 'G6'], j($on['fis_nolari']));
ok('1h) ödemesi yok → odeme_var false', $on['odeme_var'] === false);
ok('1i) önizleme YAN ETKİSİZ', $once === sayimlar());
ok('1j) hash 16 hex', (bool)preg_match('/^[a-f0-9]{16}$/D', $on['hash']));
$h1 = pdks_fis_sifirla_onizle(db(), $c1, $bas, $bit, 'Depo A')['hash'];
ok('1k) hash kararlı (aynı durumda aynı)', $h1 === $on['hash']);
foreach ([['', $bit], [$bas, ''], ['2026-13-40', $bit], [$bit, $bas]] as $i => [$b1, $b2]) {
    ok('1l' . $i . ') geçersiz/ters tarih → hata', pdks_fis_sifirla_onizle(db(), $c1, $b1, $b2, 'Depo A')['hata'] !== null);
}
ok('1m) bitiş bugün/gelecek → hata', str_contains((string)pdks_fis_sifirla_onizle(db(), $c1, $bas, date('Y-m-d'), 'Depo A')['hata'], 'bugünden önce'));
ok('1n) aralık 366 günü aşarsa hata', str_contains((string)pdks_fis_sifirla_onizle(db(), $c1, date('Y-m-d', strtotime('-500 day')), $bit, 'Depo A')['hata'], '366'));
ok('1o) çavuş yok (0) → hata', pdks_fis_sifirla_onizle(db(), 0, $bas, $bit, 'Depo A')['hata'] !== null);

echo "\n=== 2. Ödemeli çavuş uyarısı ===\n";
db()->exec("CREATE TABLE foreman_payments (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, status TEXT DEFAULT 'valid')");
db()->prepare('INSERT INTO foreman_payments (foreman_id, status) VALUES (?, ?)')->execute([$c1, 'valid']);
ok('2a) geçerli ödeme varsa odeme_var=true', pdks_fis_sifirla_onizle(db(), $c1, $bas, $bit, 'Depo A')['odeme_var'] === true);
ok('2b) başka çavuşun ödemesi etkilemez', pdks_fis_sifirla_onizle(db(), $c2, $bas, $bit, 'Depo A')['odeme_var'] === false);

echo "\n=== 3. Uygulama ===\n";
$sayim0 = tumSay();
$istek = istek32();
$sonuc = pdks_fis_sifirla_uygula(db(), $c1, $bas, $bit, 'Yanlış yorumlandı', $istek, 1, 'Depo A');
ok('3a) ok=true, 3 mesai işlendi, 12 dönem + 1 servis iptal, hatalı 0', $sonuc['ok'] === true && $sonuc['ozet'] === ['mesai' => 3, 'donem' => 12, 'servis' => 1, 'hatali' => 0], j($sonuc['ozet'] ?? $sonuc));
ok('3b) -9, -8 mesailerinde aktif dönem 0', aktifSay($f[9]['_sid']) === 0 && aktifSay($f[8]['_sid']) === 0);
ok('3c) -6 mesaisinde yalnız FİŞ-DIŞI 2 Kadın aktif kaldı', aktifSay($f[6]['_sid']) === 2 && (int)db()->query("SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id = {$f[6]['_sid']} AND is_voided = 0 AND worker_type_name_snapshot = 'Kadın'")->fetchColumn() === 2);
ok('3d) KESİN hakedişli -7 günü DOKUNULMADI (4 aktif)', aktifSay($f[7]['_sid']) === 4);
ok('3e) aralık dışı -20 günü DOKUNULMADI', aktifSay($f[20]['_sid']) === 4);
ok('3f) başka çavuş (S2) DOKUNULMADI', aktifSay($sidS2) === 2);
ok('3g) başka depo (-5) DOKUNULMADI', $sidB > 0 && aktifSay($sidB) === 2);
ok('3h) servis iptal edildi (aktif 0), kayıt SİLİNMEDİ', pdks_servis_toplamlar($f[9]['_sid'], db())['BUYUK'] === 0 && count(pdks_servis_listele($f[9]['_sid'], db())) === 1);
$sayim1 = tumSay();
ok('3i) HARD DELETE YOK: hiçbir tablodaki satır sayısı azalmadı (dönem/mesai/kart/olay/servis AYNI)', $sayim0 === $sayim1, j([$sayim0, $sayim1]));
$vr = db()->query("SELECT DISTINCT void_reason FROM daily_worker_work_periods WHERE session_id = {$f[9]['_sid']} AND is_voided = 1")->fetchAll(PDO::FETCH_COLUMN);
ok('3j) void_reason gerekçeyi taşır', $vr === ['Fiş yüklemesi geri alındı: Yanlış yorumlandı'], j($vr));
ok('3k) audit: 3 fis_sifirla (mesai başına) + 1 fis_sifirla_ozet, istek_id içerir, kullanıcı/gerekçe var', fis_audit_say('fis_sifirla') === 3 && fis_audit_say('fis_sifirla_ozet') === 1 && (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE action IN ('fis_sifirla','fis_sifirla_ozet') AND new_values LIKE '%\"istek_id\":\"$istek\"%'")->fetchColumn() === 4);
$fa = json_decode((string)db()->query("SELECT new_values FROM audit_log WHERE action='fis_sifirla' ORDER BY id ASC LIMIT 1")->fetchColumn(), true);
ok('3l) fis_sifirla audit: iptal_donem, toplu_idler, fis_nolari, durum tamam; şifre/parola alanı YOK', ($fa['iptal_donem'] ?? 0) === 4 && ($fa['toplu_idler'] ?? []) === [$f[9]['_toplu']] && ($fa['durum'] ?? '') === 'tamam' && !preg_match('/sifre|password|parola/i', json_encode($fa)), j($fa));
$tl = pdks_faz8j_toplu_listele($f[9]['_sid'], db());
ok('3m) Toplu işlem listesinde «geri alındı»', $tl[0]['geri_alindi'] === true && $tl[0]['aktif'] === 0);
ok('3n) taslak hakediş -8 «yeniden hesapla» bayrağı', (int)db()->query("SELECT needs_recalculation FROM foreman_daily_entitlements WHERE session_id = {$f[8]['_sid']}")->fetchColumn() === 1);
$tekrar = pdks_fis_sifirla_uygula(db(), $c1, $bas, $bit, 'Yanlış yorumlandı', $istek, 1, 'Depo A');
ok('3o) aynı istek_id ile ikinci gönderim → tekrar (hiçbir şey yapılmaz)', empty($tekrar['ok']) && !empty($tekrar['tekrar']) && tumSay() === $sayim1);
$yine = pdks_fis_sifirla_uygula(db(), $c1, $bas, $bit, 'Yanlış yorumlandı', istek32(), 1, 'Depo A');
ok('3p) yeni istek ile tekrar çalıştırma: yapılacak fiş kaydı kalmadı (0 mesai), hata yok', $yine['ok'] === true && $yine['ozet']['mesai'] === 0 && count($yine['atlanan']) === 2, j($yine['ozet'] ?? $yine));
$kopya = pdks_fis_etkin_batchler(pdks_fis_oturum_batchleri(db(), $f[9]['_sid']));
ok('3q) geri alınan mesaide etkin fiş batch\'i yok', $kopya === []);

echo "\n=== 4. Doğrulamalar ===\n";
$sayim2 = tumSay();
$r = pdks_fis_sifirla_uygula(db(), $c2, $bas, $bit, '   ', istek32(), 1, 'Depo A');
ok('4a) gerekçe boş → hata, hiçbir şey yapılmaz', empty($r['ok']) && str_contains((string)$r['hata'], 'Gerekçe') && tumSay() === $sayim2 && aktifSay($sidS2) === 2);
$r = pdks_fis_sifirla_uygula(db(), $c2, $bas, $bit, 'x', 'gecersiz', 1, 'Depo A');
ok('4b) geçersiz istek anahtarı → hata', empty($r['ok']) && str_contains((string)$r['hata'], 'istek'));
$r = pdks_fis_sifirla_uygula(db(), $c2, $bas, $bit, 'x', istek32(), 1, 'Depo B');
ok('4c) aktif depo ≠ parametre depo → hata (başka depoya sızmaz)', empty($r['ok']) && aktifSay($sidS2) === 2, j($r['hata'] ?? $r));
$ADMIN = false;
$r = pdks_fis_sifirla_uygula(db(), $c2, $bas, $bit, 'x', istek32(), 1, 'Depo A');
$ADMIN = true;
ok('4d) admin değilken reddedilir', empty($r['ok']) && aktifSay($sidS2) === 2, j($r['hata'] ?? $r));

echo "\n=== 5. Kısmi başarı + kaldığı yerden devam ===\n";
$c3 = yeniCavus('Çavuş S3');
$k = [];
foreach ([9 => 'K9', 8 => 'K8'] as $n => $no) {
    $k[$n] = fisYap(['ERKEK' => [2, 1]], ['cavus' => $c3, 'fis_no' => $no, 'tarih' => $gun($n), 'servis' => ['buyuk' => 0, 'kucuk' => 1]]);
    $rr = yaz1($k[$n], $sec); $k[$n]['_sid'] = (int)$rr['session_id'];
}
db()->exec("CREATE TRIGGER sv_kir BEFORE UPDATE OF is_voided ON daily_session_services BEGIN SELECT RAISE(ABORT, 'simule'); END");
$r1 = pdks_fis_sifirla_uygula(db(), $c3, $bas, $bit, 'Deneme', istek32(), 1, 'Depo A');
db()->exec('DROP TRIGGER sv_kir');
ok('5a) servis iptali başarısız: sonuç hatalı=2 ama işlem raporlandı (ok=true)', $r1['ok'] === true && $r1['ozet']['hatali'] === 2, j($r1['ozet']));
ok('5b) dönemler iptal EDİLDİ, servisler aktif kaldı (kısmi)', aktifSay($k[9]['_sid']) === 0 && pdks_servis_toplamlar($k[9]['_sid'], db())['KUCUK'] === 1);
$onD = pdks_fis_sifirla_onizle(db(), $c3, $bas, $bit, 'Depo A');
ok('5c) önizleme kalan işi (yalnız servis) gösterir: 2 mesai · 0 dönem · 2 servis', $onD['toplam']['mesai'] === 2 && $onD['toplam']['donem'] === 0 && $onD['toplam']['servis'] === 2, j($onD['toplam']));
$r2 = pdks_fis_sifirla_uygula(db(), $c3, $bas, $bit, 'Deneme', istek32(), 1, 'Depo A');
ok('5d) yeni istekle tekrar → kalan servisler iptal, hata 0 (idempotent)', $r2['ok'] === true && $r2['ozet'] === ['mesai' => 2, 'donem' => 0, 'servis' => 2, 'hatali' => 0], j($r2['ozet']));
ok('5e) sonunda hiç aktif dönem/servis yok; hiçbir satır silinmedi', aktifSay($k[9]['_sid']) + aktifSay($k[8]['_sid']) === 0 && pdks_servis_toplamlar($k[9]['_sid'], db())['KUCUK'] === 0);

echo "\n=== 6. Etkilenen fişler raporu (salt okunur) ===\n";
$once = sayimlar();
$rp = pdks_fis_etkilenen_rapor(db(), $c2, $gun(30), $gun(1), true, false);
ok('6a) S2: 1 fiş ama Yarım=0 → «yalnız Yarım > 0» süzgeci listelemez', $rp === []);
$rp = pdks_fis_etkilenen_rapor(db(), $c2, $gun(30), $gun(1), false, false);
ok('6b) yalnızYarim=false → S2 fişi listelenir (durum aktif)', count($rp) === 1 && $rp[0]['fis_no'] === 'S2-1' && $rp[0]['durum'] === 'aktif', j($rp));
// S4: yarım içeren fiş (Erkek yarım) → bayrak
$c4 = yeniCavus('Çavuş S4');
$f4 = fisYap(['ERKEK' => [5, 1], 'KADIN' => [2, 1]], ['cavus' => $c4, 'fis_no' => 'R-1', 'tarih' => $gun(4)]);
$r4 = yaz1($f4, $sec); $sid4 = (int)$r4['session_id'];
$rp = pdks_fis_etkilenen_rapor(db(), $c4, $gun(30), $gun(1), true, false);
$yt = $rp[0]['yarim_tip'] ?? []; ksort($yt);
ok('6c) R-1 listelenir: Yarım 2 · tip kırılımı Erkek 1 + Kadın 1 · ✱ Erkek Yarım bayrağı', count($rp) === 1 && $rp[0]['yarim'] === 2 && $yt === ['Erkek' => 1, 'Kadın' => 1] && $rp[0]['erkek_yarim'] === true && $rp[0]['session_id'] === $sid4, j($rp));
$f5 = fisYap(['KADIN' => [4, 2]], ['cavus' => $c4, 'fis_no' => 'R-2', 'tarih' => $gun(3)]);
yaz1($f5, $sec);
$rp = pdks_fis_etkilenen_rapor(db(), $c4, $gun(30), $gun(1), true, false);
$byNo = []; foreach ($rp as $x) $byNo[$x['fis_no']] = $x;
ok('6d) yalnız Kadın Yarım olan fişte ✱ YOK', isset($byNo['R-2']) && $byNo['R-2']['erkek_yarim'] === false && $byNo['R-2']['yarim_tip'] === ['Kadın' => 2], j($byNo['R-2'] ?? null));
$rpTar = pdks_fis_etkilenen_rapor(db(), $c4, $gun(3), $gun(1), true, false);
ok('6e) tarih süzgeci (fiş günü): yalnız R-2', array_column($rpTar, 'fis_no') === ['R-2'], j(array_column($rpTar, 'fis_no')));
pdks_faz8j_toplu_geri_al($r4['toplu_id'], 'test', 1, db());
$rpG = pdks_fis_etkilenen_rapor(db(), $c4, $gun(30), $gun(1), true, false);
$rpG2 = pdks_fis_etkilenen_rapor(db(), $c4, $gun(30), $gun(1), true, true);
$byNo = []; foreach ($rpG as $x) $byNo[$x['fis_no']] = $x;
ok('6f) geri alınan fiş durumu «geri», tip kırılımı boş; «geri alınanları gizle» listeden çıkarır', ($byNo['R-1']['durum'] ?? '') === 'geri' && $byNo['R-1']['yarim_tip'] === [] && array_column($rpG2, 'fis_no') === ['R-2'], j([$byNo['R-1'] ?? null, array_column($rpG2, 'fis_no')]));
db()->prepare("INSERT INTO foreman_daily_entitlements (session_id, foreman_id, work_date, depo, status, needs_recalculation) VALUES (?, ?, ?, 'Depo A', 'final', 0)")->execute([(int)$byNo['R-2']['session_id'], $c4, $gun(3)]);
$rpK = pdks_fis_etkilenen_rapor(db(), $c4, $gun(30), $gun(1), true, true);
ok('6g) kesin hakedişli fiş durumu «kesin»', ($rpK[0]['durum'] ?? '') === 'kesin', j($rpK));
$tum = pdks_fis_etkilenen_rapor(db(), null, $gun(30), $gun(1), true, false);
ok('6h) çavuş süzgeci yoksa tüm çavuşlar', count(array_unique(array_column($tum, 'cavus_id'))) >= 1 && count($tum) >= 2);
$sayimR = sayimlar();
pdks_fis_etkilenen_rapor(db(), null, $gun(30), $gun(1), false, false);
ok('6i) rapor SALT OKUNUR (hiçbir sayım değişmedi)', $sayimR === sayimlar());

echo "\n=== 7. Token (sıfırla onay yükü) ===\n";
@session_start();
db()->exec('CREATE TABLE hks_kv (anahtar TEXT PRIMARY KEY, deger TEXT)');
$tk = pdks_fis_sifirla_token_olustur(['foreman_id' => $c1, 'bas' => $bas, 'bit' => $bit, 'gerekce' => 'x', 'istek_id' => istek32(), 'hash' => 'abcd']);
ok('7a) token 32 hex', (bool)preg_match('/^[a-f0-9]{32}$/D', $tk));
$ty = pdks_fis_sifirla_token_al($tk, $nd);
ok('7b) token çözülür (kullanıcı + depo + TTL)', is_array($ty) && (int)$ty['foreman_id'] === $c1 && $nd === null);
ok('7c) geçersiz biçim → null/bicim', pdks_fis_sifirla_token_al('xyz', $nd2) === null && $nd2 === 'bicim');
$AKTIF_DEPO = 'Depo B';
ok('7d) başka depo → null/depo', pdks_fis_sifirla_token_al($tk, $nd3) === null && $nd3 === 'depo');
$AKTIF_DEPO = 'Depo A';
$sesYedek = $_SESSION['fis_sifirla'] ?? []; unset($_SESSION['fis_sifirla']);
$tyKv = pdks_fis_sifirla_token_al($tk, $ndKv);
$_SESSION['fis_sifirla'] = $sesYedek;
ok('7d2) oturum kaybolsa da hks_kv YEDEĞİNDEN çözülür (PHP oturumuna bağımlı değil)', is_array($tyKv) && (int)$tyKv['foreman_id'] === $c1, (string)$ndKv);
pdks_fis_sifirla_token_sil($tk);
ok('7e) silinen token çözülmez', pdks_fis_sifirla_token_al($tk, $nd4) === null);
ok('7f) token önizleme token\'ından AYRI anahtar uzayı (fis_zk_)', PDKS_FIS_KV_SIFIRLA === 'fis_zk_' && PDKS_FIS_KV_SIFIRLA !== PDKS_FIS_KV_ONIZLE && strlen(PDKS_FIS_KV_SIFIRLA . $tk) <= 60);

echo "\n=== 8. Statik güvenceler ===\n";
$fk = file_get_contents($ROOT . '/config/pdks_fis.php');
$bolumJ = substr($fk, (int)strpos($fk, '// J. DÜZELTME (v464)'));
$gb = fn(string $ad) => (function () use ($fk, $ad) { $i = strpos($fk, 'function ' . $ad . '('); $kk = strpos($fk, "\n}\n", $i); return substr($fk, $i, $kk - $i); })();
$sifirla = $gb('pdks_fis_sifirla_uygula');
ok('8a) sıfırla yalnız pdks_faz8j_toplu_geri_al + pdks_servis_iptal çağırır; kendi UPDATE/DELETE yazmaz', str_contains($sifirla, 'pdks_faz8j_toplu_geri_al(') && str_contains($sifirla . $gb('pdks_fis_servis_iptal_idler'), 'pdks_servis_iptal(') && !preg_match('/\b(UPDATE|DELETE\s+FROM|INSERT\s+INTO)\b/i', $sifirla));
ok('8b) uygula yetki + depo + şema kapılarını ilk işi olarak çağırır', strpos($sifirla, 'pdks_faz8j_yetki()') < strpos($sifirla, 'pdks_fis_sifirla_onizle(') && str_contains($sifirla, 'pdks_faz8j_aktif_depo_kontrol('));
ok('8c) şifre/parola/password kelimesi uygulama fonksiyonunda YOK (doğrulama sayfada)', !preg_match('/sifre|password|parola/i', $sifirla));
$sayfa = file_get_contents($ROOT . '/cavus_fis_geri_al.php'); $rapor = file_get_contents($ROOT . '/cavus_fis_rapor.php');
ok('8d) sayfa: require_login → is_admin kapısı → forbidden; header no-store', strpos($sayfa, 'require_login()') < strpos($sayfa, '!is_admin()') && str_contains($sayfa, 'forbidden(') && str_contains($sayfa, "Cache-Control: no-store"));
ok('8e) sayfa: her POST csrf_check; eylem ayrımından ÖNCE', strpos($sayfa, "csrf_check(\$_POST['csrf']") !== false && strpos($sayfa, "csrf_check(\$_POST['csrf']") < strpos($sayfa, "\$eylem === 'iptal'"));
ok('8f) sayfa: uygulamadan ÖNCE sifre_onay_dogrula (bağlam fis_sifirla); kullanıcı kimliği oturumdan ($auth_user)', strpos($sayfa, 'sifre_onay_dogrula(') < strpos($sayfa, 'pdks_fis_sifirla_uygula(') && str_contains($sayfa, "sifre_onay_dogrula(\$pdo, \$uid,") && str_contains($sayfa, "\$uid = (int)\$auth_user['id']") && !preg_match('/\$_(POST|GET)\[\'(uid|user_id|kullanici)/', $sayfa));
ok('8g) sayfa: çavuş kodu yazarak onay + önizleme hash\'i yeniden doğrulanır + istek_id token\'dan', str_contains($sayfa, "cavus_kod") && str_contains($sayfa, 'hash_equals(') && str_contains($sayfa, "\$yuk['istek_id']"));
ok('8h) sayfa: şifre PRG\'de HİÇ yankılanmaz (value= ile sifre alanı doldurulmaz), flash\'a yazılmaz', !preg_match('/name="sifre"[^>]*value=/', $sayfa) && !preg_match('/set_flash\([^;]*\$_POST\[\'sifre\'\]/', $sayfa));
ok('8i) sayfa ve rapor DELETE/DROP/TRUNCATE içermez', !preg_match('/\b(DELETE\s+FROM|DROP\s+TABLE|TRUNCATE)\b/i', $sayfa . $rapor));
ok('8j) rapor: POST YOK, yazma yok (INSERT/UPDATE/DELETE yok), admin kapılı', !str_contains($rapor, '$_POST') && !preg_match('/\b(INSERT\s+INTO|UPDATE\s|DELETE\s+FROM)\b/i', $rapor) && str_contains($rapor, '!is_admin()'));
ok('8k) kullanıcı verisi h() ile; ham echo yok', !preg_match('/echo\s+\$_(POST|GET)|<\?=\s*\$_(POST|GET)/', $sayfa . $rapor));
ok('8l) sayfalar nav_ptak_sayfalari() listesinde + sw.js bypass', str_contains(file_get_contents($ROOT . '/config/helpers.php'), "'cavus_fis_geri_al.php', 'cavus_fis_rapor.php'") && str_contains(file_get_contents($ROOT . '/sw.js'), 'cavus_fis_'));
$swre = '#\/cavus_fis_[a-z_]+\.php(\/|$)#';
ok('8m) sw bypass regex iki sayfayı yakalar, cavus_cari.php vb. diğerlerini yakalamaz', preg_match($swre, '/cavus_fis_geri_al.php') === 1 && preg_match($swre, '/cavus_fis_rapor.php') === 1 && preg_match($swre, '/cavus_cari.php') === 0 && preg_match($swre, '/gunluk_fis_yukle.php') === 0);
ok('8n) cavuslar.php: bağlantılar YALNIZ is_admin() bloğunda', (bool)preg_match('/is_admin\(\)\): \?>\s*<a href="cavus_fis_rapor\.php"/s', file_get_contents($ROOT . '/cavuslar.php')) && (bool)preg_match('/is_admin\(\)\): \?>\s*<a href="cavus_fis_geri_al\.php\?cavus=/s', file_get_contents($ROOT . '/cavuslar.php')));
ok('8o) sifre_onay.php require edilir (sayfa)', str_contains($sayfa, "config/sifre_onay.php"));
ok('8p) ekran kilidi/PIN dosyalarına DOKUNULMADI/atıf yok', !str_contains($sayfa . $rapor . $bolumJ, 'ekran_kilit') && !str_contains($sayfa . $rapor . $bolumJ, 'ekran_pin'));
foreach (['cavus_fis_geri_al.php', 'cavus_fis_rapor.php', 'config/sifre_onay.php'] as $d) {
    ok("8q) php -l $d", str_contains((string)shell_exec('php -l ' . escapeshellarg($ROOT . '/' . $d) . ' 2>&1'), 'No syntax errors'));
}

echo "\n--- Sonuç: $gecen geçti, $fail kaldı ---\n";
exit($fail > 0 ? 1 : 0);
