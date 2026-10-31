<?php
// =========================================================
// scripts/pdks_fis_ustune_yaz_smoke.php — Fiş Excel'i «ÜSTÜNE YAZ» testleri (v464, W4).
//
// Bellek içi SQLite + gerçek DDL çevirici + gerçek fonksiyonlar (scripts/_pdks_fis_harness.php; canlı DB'ye dokunmaz).
// Senaryo: eski kuralla yanlış yazılmış fiş («12 Erkek Tam + 1 Erkek Yarım») → yeni fişle (12 Erkek + 1 Şeritçi) üstüne yaz.
//   php scripts/pdks_fis_ustune_yaz_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
require __DIR__ . '/_pdks_fis_harness.php';
require_once $ROOT . '/config/pdks_fis.php';

function istek32(): string { return bin2hex(random_bytes(16)); }
function fisYap(array $say, array $o = []): array {
    global $cavus, $dun3;
    static $n = 0; $n++;
    $s = ['KADIN' => ['tam' => 0, 'yarim' => 0], 'ERKEK' => ['tam' => 0, 'yarim' => 0], 'RAMPACI' => ['tam' => 0, 'yarim' => 0], 'SERITCI' => ['tam' => 0, 'yarim' => 0]];
    foreach ($say as $k => $v) $s[$k] = is_array($v) && isset($v['kisi']) ? $v : ['tam' => $v[0], 'yarim' => $v[1]];
    $kova = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0];
    foreach (($o['fm_kova'] ?? []) as $sa => $c) $kova[$sa] = $c;
    unset($o['fm_kova']);
    $cv = $o['cavus'] ?? $cavus['A']; unset($o['cavus']);
    return $o + ['satir' => 1 + $n, 'fis_no' => 'T' . $n, 'fis_no_ham_sayi' => false, 'tarih' => $dun3, 'isletme' => '', 'cavus' => ['id' => $cv, 'kod' => '', 'ad' => '', 'yontem' => 'kod'],
                 'say' => $s, 'fm_kova' => $kova, 'fm_toplam' => null, 'fm_kisi' => null, 'fm_tipi' => null, 'servis' => ['buyuk' => 0, 'kucuk' => 0],
                 'giris' => '08:00', 'giris_varsayilan' => true, 'rev' => 0, 'not' => '', 'ham' => '', 'kontrol' => '', 'cakisma_dosya' => false, 'cakisma_sira' => 0,
                 'hatalar' => [], 'uyarilar' => []];
}
function fiyatTum(int $cid): void {
    global $kadin, $erkek, $rampaci, $seritci;
    foreach ([$kadin, $erkek, $rampaci, $seritci] as $tid) {
        $r = pdks_faz8b_oran_ekle($cid, $tid, '1500', '900', 'hourly', '200', '2020-01-01', 'TRY', 1, db());
        if (!$r['ok']) throw new RuntimeException('fiyatTum: ' . j($r));
    }
}
$cavusSayac = 20;
function yeniCavus(string $ad): int {
    global $cavusSayac; $cavusSayac++;
    $id = (int)pdks_gunluk_cavus_olustur(['code' => 'C0' . $cavusSayac, 'name' => $ad], 1, db())['id'];
    fiyatTum($id);
    pdks_servis_ucret_ekle($id, '400', '250', '2020-01-01', 'TRY', 1, db());
    return $id;
}
function yaz1(array $fis, array $sec): array {
    $m = pdks_fis_degerlendir(db(), $fis, $sec, 1);
    $r = pdks_fis_yaz(db(), [$fis], $sec, 1, str_repeat('b', 64), [$fis['satir']], [$fis['satir'] => $m['hash']]);
    return $r['sonuclar'][0];
}
function donemler(int $sid): array { return pdks_faz8b_oturum_donemleri($sid, db()); }
function aktifTipSay(int $sid): array {
    $st = db()->prepare('SELECT worker_type_name_snapshot AS ad, COUNT(*) AS n FROM daily_worker_work_periods WHERE session_id = ? AND is_voided = 0 GROUP BY worker_type_name_snapshot ORDER BY ad');
    $st->execute([$sid]); $o = [];
    foreach ($st->fetchAll() as $r) $o[$r['ad']] = (int)$r['n'];
    return $o;
}
function iptalSay(int $sid): int { $st = db()->prepare('SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id = ? AND is_voided = 1'); $st->execute([$sid]); return (int)$st->fetchColumn(); }
function fisYukleAudit(int $sid): array {
    $st = db()->prepare("SELECT new_values FROM audit_log WHERE action = 'fis_yukle' AND record_id = ? ORDER BY id ASC"); $st->execute([$sid]);
    return array_map(fn($x) => json_decode((string)$x, true), $st->fetchAll(PDO::FETCH_COLUMN));
}
function svAktif(int $sid): array { return pdks_servis_toplamlar($sid, db()); }

$sec = ['onay_yarim' => true, 'onay_fm' => true];
$ust = ['onay_yarim' => true, 'onay_fm' => true, 'ustune_yaz' => true, 'ustune_gerekce' => 'Eski 12+1 kuralı düzeltildi'];

echo "\n=== 1. Temel akış: 12 Erkek Tam + 1 Erkek Yarım → 12 Erkek + 1 Şeritçi ===\n";
$c1 = yeniCavus('Çavuş U1');
$eski = fisYap(['ERKEK' => [12, 1]], ['cavus' => $c1, 'fis_no' => 'U-001', 'servis' => ['buyuk' => 0, 'kucuk' => 1]]);
$r0 = yaz1($eski, $sec);
$sid1 = (int)$r0['session_id'];
ok('1a) eski kuralla yazıldı (13 aktif dönem, 1 Yarım, Şeritçi yok)', $r0['durum'] === 'yazildi' && aktifTipSay($sid1) === ['Erkek' => 13] && svAktif($sid1)['KUCUK'] === 1, j([$r0['mesaj'] ?? '', aktifTipSay($sid1)]));
// taslak hakediş (yeniden hesaplama bayrağı testi için)
db()->prepare("INSERT INTO foreman_daily_entitlements (session_id, foreman_id, work_date, depo, status, needs_recalculation) VALUES (?, ?, ?, 'Depo A', 'draft', 0)")->execute([$sid1, $c1, $eski['tarih']]);
$yeni = fisYap(['ERKEK' => [12, 0], 'SERITCI' => [1, 0]], ['cavus' => $c1, 'fis_no' => 'U-001', 'servis' => ['buyuk' => 0, 'kucuk' => 1]]);
$yeni['satir'] = $eski['satir'] + 100;

// ustune KAPALI → mevcut davranış: zaten yüklü
$m0 = pdks_fis_degerlendir(db(), $yeni, $sec, 1);
ok('1b) üstüne yaz KAPALI: aynı fiş no+rev → "zaten" (davranış DEĞİŞMEDİ)', $m0['durum'] === 'zaten', $m0['durum']);

$m = pdks_fis_degerlendir(db(), $yeni, $ust, 1);
$once = sayimlar();
ok('1c) önizleme durumu "ustune", seçilebilir ama varsayılan SEÇİLİ DEĞİL', $m['durum'] === 'ustune' && $m['secilebilir'] && !$m['varsayilan_secili'], j([$m['durum'], $m['hatalar'], $m['engeller']]));
ok('1c2) «aynı saatlerde kartsız kayıt zaten var» uyarısı üstüne yazda GÖSTERİLMEZ (kayıtlar iptal edilecek)', !hVarU($m, 'tekrar eklemediğinizden'), j($m['uyarilar']));
ok('1d) otomatik revizyon: Excel rev 0 → efektif rev 1', (int)$m['rev'] === 1 && $m['ustune']['hedef_rev'] === 1 && $m['ustune']['onceki_rev'] === 0, j($m['ustune'] ?? null));
$u = $m['ustune'];
ok('1e) ESKİ özet: Erkek 13 kişi (12 Tam + 1 Yarım) · YENİ: Erkek 12, Şeritçi 1', ($u['eski']['Erkek']['kisi'] ?? 0) === 13 && ($u['eski']['Erkek']['yarim'] ?? 0) === 1 && ($u['eski']['Erkek']['tam'] ?? 0) === 12 && ($u['yeni']['Erkek']['kisi'] ?? 0) === 12 && ($u['yeni']['Şeritçi']['kisi'] ?? 0) === 1, j([$u['eski'], $u['yeni']]));
ok('1f) okunur satırlar: «Erkek: 13 kişi (12 Tam, 1 Yarım)»', in_array('Erkek: 13 kişi (12 Tam, 1 Yarım)', pdks_fis_ozet_satirlari($u['eski']), true), j(pdks_fis_ozet_satirlari($u['eski'])));
ok('1g) önizleme YAN ETKİSİZ (hiçbir sayım değişmedi)', $once === sayimlar());
ok('1h) iptal edilecek servis: 1 Küçük kayıt (fiş etiketli)', count($u['servis_iptal']) === 1 && $u['servis_eski']['KUCUK'] === 1, j($u));

$r1 = pdks_fis_yaz(db(), [$yeni], $ust, 1, str_repeat('c', 64), [$yeni['satir']], [$yeni['satir'] => $m['hash']])['sonuclar'][0];
ok('1i) yazıldı: durum yazildi', $r1['durum'] === 'yazildi', j($r1));
ok('1j) aynı mesai (session_id aynı); sonuç ekranı EFEKTİF revizyonu gösterir (rev 1)', (int)$r1['session_id'] === $sid1 && (int)$r1['rev'] === 1, j([$r1['session_id'], $r1['rev'] ?? null]));
ok('1k) aktif dönemler: Erkek 12 + Şeritçi 1; eski 13 dönem İPTAL (silinmedi)', aktifTipSay($sid1) === ['Erkek' => 12, 'Şeritçi' => 1] && iptalSay($sid1) === 13, j([aktifTipSay($sid1), iptalSay($sid1)]));
ok('1l) toplam dönem satırı 26 (13 eski + 13 yeni; HARD DELETE yok)', (int)db()->query("SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id = $sid1")->fetchColumn() === 26);
$vr = db()->query("SELECT DISTINCT void_reason FROM daily_worker_work_periods WHERE session_id = $sid1 AND is_voided = 1")->fetchAll(PDO::FETCH_COLUMN);
ok('1m) void_reason gerekçeyi taşır', $vr === ['Fiş üstüne yazıldı: Eski 12+1 kuralı düzeltildi'], j($vr));
ok('1n) iptal edilenlerde onay alanları sıfır (Yarım kararı kalmadı)', (int)db()->query("SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id = $sid1 AND is_voided = 1 AND approved_attendance_class IS NOT NULL")->fetchColumn() === 0);
$ozet = $r1['yazilan'];
ok('1o) sonuç: ustune_iptal=13, servis_iptal=1, kisi=13', $ozet['ustune_iptal'] === 13 && $ozet['servis_iptal'] === 1 && $ozet['kisi'] === 13, j($ozet));
ok('1p) servis: eski iptal, YENİ 1 Küçük yazıldı (aktif toplam KÜÇÜK=1, 2 kayıt)', svAktif($sid1)['KUCUK'] === 1 && count(pdks_servis_listele($sid1, db())) === 2, j(pdks_servis_listele($sid1, db())));
$svNot = array_values(array_filter(pdks_servis_listele($sid1, db()), fn($s) => (int)$s['is_voided'] === 0));
ok('1q) yeni servis [FIS U-001] etiketli', count($svNot) === 1 && str_starts_with((string)$svNot[0]['note'], '[FIS U-001] '));
$ay = fisYukleAudit($sid1);
$son = end($ay);
ok('1r) fis_yukle audit: rev 1, ustune_yaz, degisen_toplu_idler=[eski toplu], onceki_rev 0', ($son['rev'] ?? -1) === 1 && !empty($son['ustune_yaz']) && ($son['degisen_toplu_idler'] ?? []) === [$r0['toplu_id']] && ($son['onceki_rev'] ?? null) === 0 && ($son['ustune_iptal_donem'] ?? 0) === 13, j($son));
ok('1s) istek_id yeni revizyona ait', ($son['istek_id'] ?? '') === pdks_fis_istek_idleri('Depo A', 'U-001', 1)['fis']);
$tl = pdks_faz8j_toplu_listele($sid1, db());
$byId = []; foreach ($tl as $t) $byId[$t['toplu_id']] = $t;
ok('1t) Toplu işlem listesi: eski toplu «geri alındı» (aktif 0), yeni toplu aktif 13', ($byId[$r0['toplu_id']]['geri_alindi'] ?? false) === true && ($byId[$r0['toplu_id']]['aktif'] ?? -1) === 0 && ($byId[$r1['toplu_id']]['aktif'] ?? -1) === 13 && ($byId[$r1['toplu_id']]['geri_alindi'] ?? true) === false, j($byId));
ok('1u) audit: eski dönemler için 13 puantaj_iptal (ustune_yaz işaretli, toplu_id eski) + 1 puantaj_toplu_geri_al', (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE action='puantaj_iptal' AND new_values LIKE '%\"ustune_yaz\":true%'")->fetchColumn() === 13 && (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE action='puantaj_toplu_geri_al' AND record_id = $sid1")->fetchColumn() === 1);
ok('1v) taslak hakediş «yeniden hesapla» bayrağı alındı', (int)db()->query("SELECT needs_recalculation FROM foreman_daily_entitlements WHERE session_id = $sid1")->fetchColumn() === 1);
$ent = pdks_faz8b_gun_mesai_ozeti([$sid1], db());
ok('1w) Mesai Özeti: Erkek Tam 12 · Yarım 0 · Şeritçi Tam 1', ($ent['tanim']['Erkek']['tam'] ?? 0) === 12 && ($ent['tanim']['Erkek']['yarim'] ?? 0) === 0 && ($ent['tanim']['Şeritçi']['tam'] ?? 0) === 1, j($ent['tanim'] ?? $ent));
$m2 = pdks_fis_degerlendir(db(), $yeni, $sec, 1);
ok('1x) ustune KAPALI ve Excel rev 0: rev0 istek hâlâ "zaten"', $m2['durum'] === 'zaten');

echo "\n=== 2. Hata → TÜM transaction geri (eski kayıtlar aktif, yeni yok) ===\n";
$c2 = yeniCavus('Çavuş U2');
$e2 = fisYap(['ERKEK' => [5, 1]], ['cavus' => $c2, 'fis_no' => 'U-002', 'servis' => ['buyuk' => 1, 'kucuk' => 0]]);
$r2a = yaz1($e2, $sec); $sid2 = (int)$r2a['session_id'];
$y2 = fisYap(['ERKEK' => [5, 0], 'SERITCI' => [1, 0]], ['cavus' => $c2, 'fis_no' => 'U-002', 'servis' => ['buyuk' => 1, 'kucuk' => 0]]);
$m2 = pdks_fis_degerlendir(db(), $y2, $ust, 1);
db()->exec("CREATE TRIGGER ust_kir BEFORE UPDATE OF is_voided ON daily_worker_work_periods WHEN NEW.is_voided = 1 BEGIN SELECT RAISE(ABORT, 'simule iptal hatasi'); END");
$once = sayimlar();
$r2b = pdks_fis_yaz(db(), [$y2], $ust, 1, str_repeat('c', 64), [$y2['satir']], [$y2['satir'] => $m2['hash']])['sonuclar'][0];
$sonra = sayimlar();
db()->exec('DROP TRIGGER ust_kir');
ok('2a) iptal adımı hata verince fiş yazılmaz (durum hata)', $r2b['durum'] === 'hata', j($r2b));
ok('2b) HİÇBİR sayım değişmedi (dönem/olay/kart/mesai/audit/servis/iptal)', $once === $sonra, j([$once, $sonra]));
ok('2c) eski kayıtlar HÂLÂ aktif (Erkek 6), Şeritçi yok, eski servis aktif', aktifTipSay($sid2) === ['Erkek' => 6] && svAktif($sid2)['BUYUK'] === 1);
ok('2d) fis_yukle izi eklenmedi (yalnız ilk yazım)', count(fisYukleAudit($sid2)) === 1);
$r2c = pdks_fis_yaz(db(), [$y2], $ust, 1, str_repeat('c', 64), [$y2['satir']], [$y2['satir'] => $m2['hash']])['sonuclar'][0];
ok('2e) sorun giderilince aynı önizleme hash\'iyle yazılır (rev 1)', $r2c['durum'] === 'yazildi' && aktifTipSay($sid2) === ['Erkek' => 5, 'Şeritçi' => 1], j($r2c));

echo "\n=== 3. Hash kapsamı / çift tıklama / ardışık üstüne yaz ===\n";
$c3 = yeniCavus('Çavuş U3');
$e3 = fisYap(['ERKEK' => [4, 1]], ['cavus' => $c3, 'fis_no' => 'U-003']);
$r3a = yaz1($e3, $sec); $sid3 = (int)$r3a['session_id'];
$y3 = fisYap(['ERKEK' => [4, 0], 'SERITCI' => [1, 0]], ['cavus' => $c3, 'fis_no' => 'U-003']);
$m3 = pdks_fis_degerlendir(db(), $y3, $ust, 1);
$y3b = fisYap(['ERKEK' => [4, 0], 'SERITCI' => [1, 0]], ['cavus' => $c3, 'fis_no' => 'U-003']);   // aynı veri, ikinci istemci
$p1 = pdks_fis_yaz(db(), [$y3], $ust, 1, 'x', [$y3['satir']], [$y3['satir'] => $m3['hash']])['sonuclar'][0];
ok('3a) birinci yazma başarılı', $p1['durum'] === 'yazildi');
$p2 = pdks_fis_yaz(db(), [$y3], $ust, 1, 'x', [$y3['satir']], [$y3['satir'] => $m3['hash']])['sonuclar'][0];
ok('3b) AYNI önizleme hash\'iyle ikinci yazma → "degisti" (üçüncü yazım YOK)', $p2['durum'] === 'degisti', j($p2));
ok('3c) dönem kümesi değişmedi: Erkek 4 + Şeritçi 1 aktif, 10 satır', aktifTipSay($sid3) === ['Erkek' => 4, 'Şeritçi' => 1] && (int)db()->query("SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id = $sid3")->fetchColumn() === 10);
$m3b = pdks_fis_degerlendir(db(), $y3, $ust, 1);
ok('3d) yeniden önizleme: ESKİ=rev 1 kayıtları, hedef rev 2 (bilinçli ikinci üstüne yaz serbest)', $m3b['durum'] === 'ustune' && $m3b['ustune']['hedef_rev'] === 2 && $m3b['ustune']['onceki_rev'] === 1, j($m3b['ustune'] ?? $m3b));
ok('3e) hash önceki önizlemeden FARKLI (küme + rev hash\'e girer)', $m3b['hash'] !== $m3['hash']);
// E kümesi değişti: toplu geri al → etkin batch yok → üstüne yaz uygulanmaz, hash değişir
$sec3 = pdks_faz8j_toplu_geri_al($p1['toplu_id'], 'test', 1, db());
$m3c = pdks_fis_degerlendir(db(), $y3, $ust, 1);
ok('3f) etkin fiş batch\'i kalmayınca ustune uygulanmaz + açıklayıcı uyarı', $sec3['ok'] && $m3c['durum'] !== 'ustune' && hVarU($m3c, 'üstüne yazılacak bir şey bulunamadı'), j([$m3c['durum'], $m3c['uyarilar']]));
function hVarU(array $m, string $p): bool { foreach (array_merge($m['hatalar'], $m['engeller'], $m['uyarilar']) as $x) if (str_contains($x, $p)) return true; return false; }
$p3 = pdks_fis_yaz(db(), [$y3], $ust, 1, 'x', [$y3['satir']], [$y3['satir'] => $m3b['hash']])['sonuclar'][0];
ok('3g) eski önizleme hash\'i (E değişti) → "degisti", hiçbir şey yazılmadı', $p3['durum'] === 'degisti' && aktifTipSay($sid3) === [], j([$p3, aktifTipSay($sid3)]));
$m3d = pdks_fis_degerlendir(db(), $y3, $ust, 1);
$p4 = pdks_fis_yaz(db(), [$y3], $ust, 1, 'x', [$y3['satir']], [$y3['satir'] => $m3d['hash']])['sonuclar'][0];
$ay3 = fisYukleAudit($sid3); $son3 = end($ay3);
ok('3h) geri alınmış fişi üstüne yaz: revizyon OTOMATİK atlanır (rev 2), yazılır', $p4['durum'] === 'yazildi' && ($son3['rev'] ?? -1) === 2, j([$p4['durum'], $son3['rev'] ?? null]));

echo "\n=== 4. Fiş-dışı (kiosk/elle/ters) kayıtlara DOKUNULMAZ ===\n";
$c4 = yeniCavus('Çavuş U4');
$e4 = fisYap(['ERKEK' => [3, 0]], ['cavus' => $c4, 'fis_no' => 'U-004']);
$r4a = yaz1($e4, $sec); $sid4 = (int)$r4a['session_id'];
// aynı mesaiye FİŞ DIŞI (plain toplu ekleme: kaynak yok) 2 Kadın kayıt
$elle = pdks_faz8j_toplu_ekle(['foreman_id' => $c4, 'work_date' => $e4['tarih'], 'depo' => $AKTIF_DEPO, 'reason' => 'elle', 'istek_id' => istek32(),
    'gruplar' => [['worker_type_id' => $kadin, 'entry_clock' => '08:00', 'exit_date' => $e4['tarih'], 'exit_clock' => '17:00', 'kartsiz_adet' => 2]]], 1, db());
ok('4a) fiş-dışı 2 kayıt eklendi', !empty($elle['ok']) && aktifTipSay($sid4) === ['Erkek' => 3, 'Kadın' => 2], j($elle['hatalar'] ?? aktifTipSay($sid4)));
$y4 = fisYap(['ERKEK' => [2, 0], 'SERITCI' => [1, 0]], ['cavus' => $c4, 'fis_no' => 'U-004']);
$m4 = pdks_fis_degerlendir(db(), $y4, $ust, 1);
ok('4b) EK yaz kapalı → ENGEL: fiş dışı 2 kayıt belirtilir', $m4['durum'] === 'engel' && hVarU($m4, 'fiş dışı 2 aktif kayıt'), j([$m4['durum'], $m4['engeller']]));
$s4 = pdks_fis_yaz(db(), [$y4], $ust, 1, 'x', [$y4['satir']], [$y4['satir'] => $m4['hash']])['sonuclar'][0];
ok('4c) engelliyken yazılmaz (hiçbir dönem değişmedi)', $s4['durum'] === 'hata' && aktifTipSay($sid4) === ['Erkek' => 3, 'Kadın' => 2]);
$ust4 = $ust + ['ek_yaz' => true];
$m4b = pdks_fis_degerlendir(db(), $y4, $ust4, 1);
ok('4d) EK yaz açık → ustune (fiş dışı korunur uyarısı)', $m4b['durum'] === 'ustune' && $m4b['ustune']['fis_disi'] === 2 && $m4b['ustune']['fis_aktif'] === 3 && hVarU($m4b, 'korunuyor'), j([$m4b['durum'], $m4b['uyarilar']]));
$s4b = pdks_fis_yaz(db(), [$y4], $ust4, 1, 'x', [$y4['satir']], [$y4['satir'] => $m4b['hash']])['sonuclar'][0];
ok('4e) yazıldı: Kadın 2 (fiş dışı) AKTİF kaldı, Erkek 2 + Şeritçi 1 yeni, eski Erkek 3 iptal', $s4b['durum'] === 'yazildi' && aktifTipSay($sid4) === ['Erkek' => 2, 'Kadın' => 2, 'Şeritçi' => 1] && iptalSay($sid4) === 3, j([$s4b['mesaj'] ?? '', aktifTipSay($sid4), iptalSay($sid4)]));
ok('4f) fiş-dışı dönemlerin is_voided=0 ve void_reason boş', (int)db()->query("SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id = $sid4 AND worker_type_name_snapshot = 'Kadın' AND is_voided = 0 AND void_reason IS NULL")->fetchColumn() === 2);

echo "\n=== 5. Kesinleşmiş hakediş → net ret, hiçbir yazım ===\n";
$c5 = yeniCavus('Çavuş U5');
$e5 = fisYap(['ERKEK' => [3, 1]], ['cavus' => $c5, 'fis_no' => 'U-005']);
$r5a = yaz1($e5, $sec); $sid5 = (int)$r5a['session_id'];
db()->prepare("INSERT INTO foreman_daily_entitlements (session_id, foreman_id, work_date, depo, status, needs_recalculation) VALUES (?, ?, ?, 'Depo A', 'final', 0)")->execute([$sid5, $c5, $e5['tarih']]);
$y5 = fisYap(['ERKEK' => [3, 0], 'SERITCI' => [1, 0]], ['cavus' => $c5, 'fis_no' => 'U-005']);
$once = sayimlar();
$m5 = pdks_fis_degerlendir(db(), $y5, $ust, 1);
ok('5a) önizleme: HATA + kesinleşmiş hakediş mesajı (ek hakediş akışı yok notu ödemesi yokken YOK)', $m5['durum'] === 'hata' && hVarU($m5, 'kesinleşmiş hakedişi var') && !hVarU($m5, 'ek hakediş'), j($m5['hatalar']));
$s5 = pdks_fis_yaz(db(), [$y5], $ust, 1, 'x', [$y5['satir']], [$y5['satir'] => 'z'])['sonuclar'][0];
ok('5b) yazma reddedildi, HİÇBİR sayım değişmedi', $s5['durum'] === 'hata' && $once === sayimlar());
// ödemesi olan çavuş: uyarı metni
db()->exec("CREATE TABLE foreman_payments (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, status TEXT DEFAULT 'valid')");
db()->prepare('INSERT INTO foreman_payments (foreman_id, status) VALUES (?, ?)')->execute([$c5, 'valid']);
$m5b = pdks_fis_degerlendir(db(), $y5, $ust, 1);
ok('5c) çavuşun geçerli ödemesi varsa mesaj: yeniden açılamaz + DEĞİŞTİRİLEMEZ + ek hakediş akışı yok', hVarU($m5b, 'ödemesi varsa') && hVarU($m5b, 'DEĞİŞTİRİLEMEZ') && hVarU($m5b, 'ek hakediş'), j($m5b['hatalar']));

echo "\n=== 6. Servis: çökme sonrası idempotent devam ===\n";
$c6 = yeniCavus('Çavuş U6');
$e6 = fisYap(['ERKEK' => [4, 1]], ['cavus' => $c6, 'fis_no' => 'U-006', 'servis' => ['buyuk' => 0, 'kucuk' => 1]]);
$r6a = yaz1($e6, $sec); $sid6 = (int)$r6a['session_id'];
$y6 = fisYap(['ERKEK' => [4, 0], 'SERITCI' => [1, 0]], ['cavus' => $c6, 'fis_no' => 'U-006', 'servis' => ['buyuk' => 0, 'kucuk' => 2]]);
$m6 = pdks_fis_degerlendir(db(), $y6, $ust, 1);
ok('6a) önizleme: servis yazılacak = 2 Küçük (eski 1 düşüldükten sonra)', $m6['servis']['yazilacak'] === ['buyuk' => 0, 'kucuk' => 2] && $m6['servis']['hedef']['kucuk'] === 2, j($m6['servis']));
db()->exec("CREATE TRIGGER sv_iptal_kir BEFORE UPDATE OF is_voided ON daily_session_services BEGIN SELECT RAISE(ABORT, 'simule servis iptal hatasi'); END");
$s6 = pdks_fis_yaz(db(), [$y6], $ust, 1, 'x', [$y6['satir']], [$y6['satir'] => $m6['hash']])['sonuclar'][0];
ok('6b) servis iptali çöktü: işçi kayıtları YAZILI, mesaj yeniden yükleme yönergesi verir', $s6['durum'] === 'hata' && str_contains($s6['mesaj'], 'Üstüne yaz') && aktifTipSay($sid6) === ['Erkek' => 4, 'Şeritçi' => 1], j([$s6, aktifTipSay($sid6)]));
ok('6c) eski servis hâlâ aktif (iptal edilemedi), fis_yukle izi YOK (yarım)', svAktif($sid6)['KUCUK'] === 1 && count(fisYukleAudit($sid6)) === 1);
$tb = pdks_faz8j_toplu_bul(db(), $s6['toplu_id']);
ok('6d) özet audit servis_iptal_idler\'i taşır (devam için)', count($tb['veri']['ustune_yaz']['servis_iptal_idler'] ?? []) === 1, j($tb['veri']['ustune_yaz'] ?? null));
db()->exec('DROP TRIGGER sv_iptal_kir');
$m6b = pdks_fis_degerlendir(db(), $y6, $ust, 1);
ok('6e) yeniden önizleme: durum "devam" (aynı revizyon sürdürülür, ikinci iptal YOK)', $m6b['durum'] === 'devam' && (int)$m6b['rev'] === 1, j([$m6b['durum'], $m6b['rev'], $m6b['hatalar']]));
ok('6f) devam önizlemesinde servis hesabı bekleyen iptali düşer (yazılacak 2 Küçük)', $m6b['servis']['yazilacak'] === ['buyuk' => 0, 'kucuk' => 2], j($m6b['servis']));
$once6 = (int)db()->query("SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id = $sid6")->fetchColumn();
$s6b = pdks_fis_yaz(db(), [$y6], $ust, 1, 'x', [$y6['satir']], [$y6['satir'] => $m6b['hash']])['sonuclar'][0];
ok('6g) devam_tamamlandi: dönem sayısı DEĞİŞMEDİ, eski servis iptal, yeni 2 Küçük aktif', $s6b['durum'] === 'devam_tamamlandi' && (int)db()->query("SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id = $sid6")->fetchColumn() === $once6 && svAktif($sid6)['KUCUK'] === 2, j([$s6b, svAktif($sid6)]));
$ay6 = fisYukleAudit($sid6); $f6 = end($ay6);
ok('6h) tamamlanma izi: rev 1, ustune_yaz, eski toplu', ($f6['rev'] ?? -1) === 1 && !empty($f6['ustune_yaz']) && ($f6['degisen_toplu_idler'] ?? []) === [$r6a['toplu_id']] && ($f6['servis_iptal_adet'] ?? 0) === 1, j($f6));
$s6c = pdks_fis_yaz(db(), [$y6], $ust, 1, 'x', [$y6['satir']], [])['sonuclar'][0];
ok('6i) tamamlanmış sonrası aynı dosya + üstüne yaz → yeni üstüne yaz (rev 2) — servis sayısı 2 kalır', $s6c['durum'] === 'yazildi' && svAktif($sid6)['KUCUK'] === 2, j([$s6c['durum'], $s6c['mesaj'] ?? '', svAktif($sid6)]));

echo "\n=== 7. Gerekçe zorunlu · yetki · aynı dosyada iki fiş ===\n";
$c7 = yeniCavus('Çavuş U7');
$e7 = fisYap(['ERKEK' => [2, 1]], ['cavus' => $c7, 'fis_no' => 'U-007']);
$r7a = yaz1($e7, $sec); $sid7 = (int)$r7a['session_id'];
$y7 = fisYap(['ERKEK' => [2, 0], 'SERITCI' => [1, 0]], ['cavus' => $c7, 'fis_no' => 'U-007']);
$m7 = pdks_fis_degerlendir(db(), $y7, ['ustune_yaz' => true, 'ustune_gerekce' => '   '], 1);
ok('7a) gerekçe boş → HATA «gerekçe zorunludur»', $m7['durum'] === 'hata' && hVarU($m7, 'gerekçe zorunludur'), j($m7['hatalar']));
$s7 = pdks_fis_yaz(db(), [$y7], ['ustune_yaz' => true, 'ustune_gerekce' => ''], 1, 'x', [$y7['satir']], [])['sonuclar'][0];
ok('7b) gerekçesiz yazma reddedildi, kayıtlar aynı', $s7['durum'] === 'hata' && aktifTipSay($sid7) === ['Erkek' => 3]);
$kes = pdks_fis_secenek(['ustune_yaz' => 1, 'ustune_gerekce' => str_repeat('ğ', 900)]);
ok('7c) gerekçe ≤ ' . PDKS_FIS_USTUNE_GEREKCE_MAX . ' karakter (void_reason ≤ 500 payı)', mb_strlen($kes['ustune_gerekce']) === PDKS_FIS_USTUNE_GEREKCE_MAX);
$ADMIN = false;
$m7b = pdks_fis_degerlendir(db(), $y7, $ust, 1);
$s7b = pdks_fis_yaz(db(), [$y7], $ust, 1, 'x', [$y7['satir']], [])['sonuclar'][0];
$ADMIN = true;
ok('7d) admin DEĞİLKEN yazma reddedilir (Faz 8J yetki kapısı), kayıtlar aynı', $s7b['durum'] === 'hata' && aktifTipSay($sid7) === ['Erkek' => 3], j($s7b));
// aynı dosyada aynı çavuş+gün iki fiş: üstüne yaz YALNIZ ilkinde
$c8 = yeniCavus('Çavuş U8');
$e8 = fisYap(['ERKEK' => [3, 1]], ['cavus' => $c8, 'fis_no' => 'U-008']);
$r8a = yaz1($e8, $sec); $sid8 = (int)$r8a['session_id'];
$f1 = fisYap(['ERKEK' => [3, 0], 'SERITCI' => [1, 0]], ['cavus' => $c8, 'fis_no' => 'U-008', 'cakisma_dosya' => true, 'cakisma_sira' => 0]);
$f2 = fisYap(['KADIN' => [2, 0]], ['cavus' => $c8, 'fis_no' => 'U-009', 'cakisma_dosya' => true, 'cakisma_sira' => 1]);
$mf1 = pdks_fis_degerlendir(db(), $f1, $ust, 1); $mf2 = pdks_fis_degerlendir(db(), $f2, $ust, 1);
ok('8a) ilk fiş: ustune · ikinci fiş: ustune DEĞİL (engel + açıklayıcı uyarı)', $mf1['durum'] === 'ustune' && $mf2['durum'] === 'engel' && hVarU($mf2, 'ilk fiş üstüne yazar'), j([$mf1['durum'], $mf2['durum'], $mf2['uyarilar']]));
$rr = pdks_fis_yaz(db(), [$f1, $f2], $ust, 1, 'x', [$f1['satir'], $f2['satir']], [$f1['satir'] => $mf1['hash'], $f2['satir'] => $mf2['hash']]);
ok('8b) yazma: ilk fiş yazıldı, ikinci ENGEL (ilk fişin yeni kayıtlarını iptal ETMEDİ)', $rr['sonuclar'][0]['durum'] === 'yazildi' && $rr['sonuclar'][1]['durum'] === 'hata' && aktifTipSay($sid8) === ['Erkek' => 3, 'Şeritçi' => 1], j([array_column($rr['sonuclar'], 'durum'), aktifTipSay($sid8)]));
// ikinci fiş EK ile eklenir, ilkinin kayıtları yine korunur
$mf2b = pdks_fis_degerlendir(db(), $f2, $ust + ['ek_yaz' => true], 1);
$rr2 = pdks_fis_yaz(db(), [$f2], $ust + ['ek_yaz' => true], 1, 'x', [$f2['satir']], [$f2['satir'] => $mf2b['hash']])['sonuclar'][0];
ok('8c) ikinci fiş «EK yaz» ile eklenir; ilk fişin yeni kayıtları aktif kalır', $rr2['durum'] === 'yazildi' && aktifTipSay($sid8) === ['Erkek' => 3, 'Kadın' => 2, 'Şeritçi' => 1], j([$rr2['durum'], $rr2['mesaj'] ?? '', aktifTipSay($sid8)]));

echo "\n=== 9. Yevmiyeli Rampacı üstüne yaz (v463 profili) ===\n";
if (pdks_yevmiye_hazir(db())) {
    $c9 = yeniCavus('Çavuş U9');
    $e9 = fisYap(['ERKEK' => [2, 0], 'RAMPACI' => ['tam' => 0, 'yarim' => 0, 'kisi' => 3, 'yevmiye' => 5]], ['cavus' => $c9, 'fis_no' => 'U-010']);
    $r9a = yaz1($e9, $sec); $sid9 = (int)$r9a['session_id'];
    ok('9a) yevmiyeli Rampacı yazıldı (3 kişi, 5 yevmiye)', $r9a['durum'] === 'yazildi' && ($r9a['yazilan']['yevmiye'] ?? 0) === 5, j($r9a));
    $y9 = fisYap(['ERKEK' => [2, 0], 'RAMPACI' => ['tam' => 0, 'yarim' => 0, 'kisi' => 3, 'yevmiye' => 6]], ['cavus' => $c9, 'fis_no' => 'U-010']);
    $m9 = pdks_fis_degerlendir(db(), $y9, $ust, 1);
    $satirEski = pdks_fis_ozet_satirlari($m9['ustune']['eski'] ?? []); $satirYeni = pdks_fis_ozet_satirlari($m9['ustune']['yeni'] ?? []);
    ok('9b) ESKİ/YENİ özet Rampacı yevmiyesini gösterir (5 → 6 yevmiye)', in_array('Rampacı: 3 kişi (3 Tam, 5 yevmiye)', $satirEski, true) && in_array('Rampacı: 3 kişi (3 Tam, 6 yevmiye)', $satirYeni, true), j([$satirEski, $satirYeni]));
    $s9 = pdks_fis_yaz(db(), [$y9], $ust, 1, 'x', [$y9['satir']], [$y9['satir'] => $m9['hash']])['sonuclar'][0];
    $ys = (int)db()->query("SELECT COALESCE(SUM(yevmiye_adedi),0) FROM daily_worker_work_periods WHERE session_id = $sid9 AND is_voided = 0 AND yevmiye_adedi IS NOT NULL")->fetchColumn();
    ok('9c) yazıldı: aktif yevmiye toplamı 6; eski yevmiyeli dönemler iptal', $s9['durum'] === 'yazildi' && $ys === 6 && (int)db()->query("SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id = $sid9 AND is_voided = 1 AND yevmiye_adedi IS NOT NULL")->fetchColumn() === 3, j([$s9['mesaj'] ?? '', $ys]));
} else {
    ok('9) yevmiye kolonu kurulu değil — bölüm atlandı', true);
}

echo "\n=== 10. Statik güvenceler (HARD DELETE yok, ikinci void yolu yok) ===\n";
$fk = file_get_contents($ROOT . '/config/pdks_fis.php');
$j0 = strpos($fk, '// J. DÜZELTME (v464)');
$bolumJ = substr($fk, $j0);
ok('10a) J bölümü bulundu', $j0 !== false && strlen($bolumJ) > 3000);
ok('10b) J bölümünde dönem/olay/servis/kart tablolarına DOĞRUDAN INSERT/UPDATE/DELETE YOK', !preg_match('/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?(daily_worker_work_periods|daily_worker_card_events|daily_session_services|daily_work_sessions|worker_cards|foreman_daily_entitlements|foreman_daily_entitlement_lines)\b/i', $bolumJ));
ok('10c) J bölümünde HİÇ "DELETE FROM daily_" / worker_cards silmesi yok', !preg_match('/DELETE\s+FROM\s+`?(daily_|worker_cards)/i', $fk));
ok('10d) iptal yalnız pdks_faz8j_void_uygula / pdks_faz8j_toplu_geri_al / pdks_servis_iptal ile', str_contains($bolumJ, 'pdks_faz8j_void_uygula(') && str_contains($bolumJ, 'pdks_faz8j_toplu_geri_al(') && str_contains($bolumJ, 'pdks_servis_iptal('));
ok('10e) pdks_fis.php kaynağında is_voided yazımı (SET is_voided) YOK', !preg_match('/SET\s+is_voided/i', $fk));
$gb = fn(string $ad) => (function () use ($fk, $ad) { $i = strpos($fk, 'function ' . $ad . '('); $k = strpos($fk, "\n}\n", $i); return substr($fk, $i, $k - $i); })();
ok('10f) üstüne yaz geri çağrısı kendi transaction\'ını AÇMAZ (beginTransaction/commit/rollBack yok)', !preg_match('/beginTransaction|->commit\(|rollBack/', $gb('pdks_fis_ustune_geri_cagrisi')));
ok('10g) servis iptali tx içinde DEĞİL: ustune geri çağrısı pdks_servis_iptal çağırmaz', !str_contains($gb('pdks_fis_ustune_geri_cagrisi'), 'pdks_servis_iptal('));
ok('10h) gunluk_fis_yukle üstüne yaz seçeneğini token\'dan okur (POST\'tan DEĞİL)', !preg_match('/\$_POST\[\'ustune_gerekce\'\]\s*\)\s*;\s*\n\s*\$secenek/', file_get_contents($ROOT . '/gunluk_fis_yukle.php')) && str_contains(file_get_contents($ROOT . '/gunluk_fis_yukle.php'), "\$yuk['secenek']['ustune_yaz']"));
ok('10i) HTTP ucu (_puantaj_toplu_ajax.php) ustune/yevmiye/yazdiktan_sonra GEÇİRMEZ', !preg_match('/ustune|yazdiktan_sonra|audit_ek/', file_get_contents($ROOT . '/_puantaj_toplu_ajax.php')));

echo "\n--- Sonuç: $gecen geçti, $fail kaldı ---\n";
exit($fail > 0 ? 1 : 0);
