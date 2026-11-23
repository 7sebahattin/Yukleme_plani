<?php
// scripts/pdks_elle_onay_sadece_fm_smoke.php — v560: elle girilenler KAYIT ANINDA onaylı · «Sadece fazla mesai» sınıfı ·
// Toplu İşlem: tarih / servis / «daha önce ekleme» koruması · çavuş listesi FM+servis toplamı · gün ◀ ▶ · Buçuklu → onay butonu
//   php scripts/pdks_elle_onay_sadece_fm_smoke.php   → çıkış kodu 0 = tüm testler geçti
// Bellek içi SQLite + gerçek DDL çevirici + gerçek fonksiyonlar (canlı DB'ye dokunmaz).
// =========================================================
declare(strict_types=1);
require __DIR__ . '/_pdks_fis_harness.php';
require_once $ROOT . '/config/pdks_toplu_onay.php';
require_once $ROOT . '/config/pdks_toplu_hizli.php';
require_once $ROOT . '/config/pdks_liste_ui.php';

$tarih = date('Y-m-d', strtotime('-4 day'));
$cA = $cavus['A'];
foreach ([$kadin, $erkek] as $tid) {
    $r = pdks_faz8b_oran_ekle($cA, $tid, '1500', '900', 'hourly', '200', '2020-01-01', 'TRY', 1, db());
    if (empty($r['ok'])) { echo "kurulum: oran_ekle başarısız " . j($r) . "\n"; exit(2); }
}
pdks_servis_ucret_ekle($cA, '400', '250', '2020-01-01', 'TRY', 1, db());

function donem(int $id): array {
    $st = db()->prepare('SELECT * FROM daily_worker_work_periods WHERE id = ?'); $st->execute([$id]); return $st->fetch() ?: [];
}
function toplu(array $ek): array {
    global $cA, $tarih;
    static $n = 0; $n++;
    return pdks_faz8j_toplu_ekle($ek + ['foreman_id' => $cA, 'work_date' => $tarih, 'depo' => 'Depo A', 'reason' => 'Test', 'note' => '',
        'istek_id' => bin2hex(random_bytes(16)), 'coklu_grup' => true], 1, db());
}
$kodFn = static function (int $tid): ?string { $t = pdks_faz8j_desteklenen_tip(db(), $tid); return $t ? (string)$t['code'] : null; };
function hz(array $hizli, array $ek = []): array {
    global $tarih, $kodFn;
    $x = pdks_toplu_hizli_isle($hizli, $tarih, [], $kodFn);
    if ($x['hata'] !== null) return ['hata' => $x['hata']];
    return $ek + $x['ek'] + ['gruplar' => $x['gruplar']];
}

echo "=== A. SINIFLANDIRICI: Sadece FM (beyan 'fm') ===\n";
$f = static fn(string $g, string $c, array $ek = []) => pdks_faz8b_donem_siniflandir($ek + ['entry_time' => "$tarih $g:00", 'exit_time' => "$tarih $c:00", 'normal_work_minutes_snapshot' => 540, 'declared_attendance_class' => 'fm']);
$a1 = $f('17:00', '18:00');
ok('A1) 1 saat → sınıf fm, FM 1 sa, karar gerekmez, onay bekliyor (hazır değil)', $a1['etkin_sinif'] === 'fm' && $a1['fazla_mesai_saat'] === 1 && $a1['sinif_onayi_gerekli'] === false && $a1['fazla_mesai_durum'] === 'bekliyor' && $a1['finans_hazir'] === false, j($a1));
$a2 = $f('17:00', '18:16');
ok('A2) 1s16dk → 2 sa (aynı FM kuralı: 15 dk tolerans + başlayan saat)', $a2['fazla_mesai_saat'] === 2, j($a2['fazla_mesai_saat']));
// etkin saatler: 17:00-18:14 → 18:14 saat başına çekilir (≤15 dk) = 1 sa
ok('A2b) 17:00–18:14 → saat başı toleransı sonrası 1 sa', $f('17:00', '18:14')['fazla_mesai_saat'] === 1);
ok('A3) 4 sa (3 saat üstü) beyan fm → yine Sadece FM, FM 4 sa (Yarım olmaz)', $f('17:00', '21:00')['etkin_sinif'] === 'fm' && $f('17:00', '21:00')['fazla_mesai_saat'] === 4);
ok('A4) 10 dk → FM 0 → finans_hazir (ücret doğmaz)', $f('17:00', '17:10')['finans_hazir'] === true && $f('17:00', '17:10')['fazla_mesai_saat'] === 0);
$a5 = pdks_faz8b_donem_siniflandir(['entry_time' => "$tarih 17:00:00", 'exit_time' => null, 'normal_work_minutes_snapshot' => 540, 'declared_attendance_class' => 'fm']);
ok('A5) çıkışsız → hazır DEĞİL (süre yok)', $a5['etkin_sinif'] === 'fm' && $a5['finans_hazir'] === false);
$a6 = pdks_faz8b_donem_siniflandir(['entry_time' => "$tarih 17:00:00", 'exit_time' => "$tarih 18:00:00", 'normal_work_minutes_snapshot' => 540, 'declared_attendance_class' => 'auto', 'approved_attendance_class' => 'fm']);
ok('A6) onaylı sınıf fm + kısa süre → Sadece FM', $a6['etkin_sinif'] === 'fm' && $a6['fazla_mesai_saat'] === 1);
$a7 = pdks_faz8b_donem_siniflandir(['entry_time' => "$tarih 08:00:00", 'exit_time' => "$tarih 17:00:00", 'normal_work_minutes_snapshot' => 540, 'declared_attendance_class' => 'auto', 'approved_attendance_class' => 'fm']);
ok('A7) onaylı fm ama süre Tam eşiğinde → otomatik Tam kazanır', $a7['etkin_sinif'] === 'tam');
ok('A8) etiket: Sadece FM', pdks_faz8b_sinif_etiketi('fm') === 'Sadece FM' && str_contains(pdks_faz8b_mesai_tanimi_etiketi($a6, false, false), 'Sadece FM'));

echo "\n=== B. TOPLU İŞLEM → KAYIT ANINDA ONAY (Tam / Yarım / Çift yok) + SADECE FM KUTUSU ===\n";
$r1 = toplu(hz(['tam' => [$kadin => 3], 'yarim' => [$kadin => 2], 'sadece_fm' => [$kadin => ['kisi' => 2, 'saat' => '1'], $erkek => ['kisi' => 1, 'saat' => '2.5']]]));
ok('B1) toplu ekleme başarılı (3 Tam + 2 Yarım + 2 K·1sa + 1 E·2,5sa sadece FM)', !empty($r1['ok']) && $r1['eklenen'] === 8, j($r1['hatalar'] ?? $r1));
$sidB = (int)($r1['session_id'] ?? 0);
$p = db()->query("SELECT * FROM daily_worker_work_periods WHERE session_id = $sidB ORDER BY id")->fetchAll();
$say = ['tam' => 0, 'yarim' => 0, 'fm' => 0, 'beyan_fm' => 0, 'bekleyen' => 0];
foreach ($p as $d) {
    $z = pdks_faz8b_donem_getir((int)$d['id'], db()); $zf = $z['faz8b'];
    if (!$zf['finans_hazir']) $say['bekleyen']++;
    if ($zf['etkin_sinif'] === 'tam') $say['tam']++;
    if ($zf['etkin_sinif'] === 'yarim') $say['yarim']++;
    if ($zf['etkin_sinif'] === 'fm') $say['fm']++;
    if ($d['declared_attendance_class'] === 'fm') $say['beyan_fm']++;
}
ok('B2) HİÇ bekleyen yok (hepsi kayıt anında onaylı)', $say['bekleyen'] === 0, j($say));
ok('B3) 3 Tam · 2 Yarım · 3 Sadece FM', $say['tam'] === 3 && $say['yarim'] === 2 && $say['fm'] === 3 && $say['beyan_fm'] === 3, j($say));
$fmRows = array_values(array_filter($p, static fn($d) => $d['declared_attendance_class'] === 'fm'));
ok('B4) Sadece FM dönemleri 17:00 giriş, onaylı FM saatleri 1,1,3 (2,5 sa → 3: başlayan saat yukarı)', array_column($fmRows, 'overtime_approved_hours') == [1, 1, 3] && str_ends_with($fmRows[0]['entry_time'], '17:00:00'), j(array_column($fmRows, 'overtime_approved_hours')));
$yarimRows = array_values(array_filter($p, static fn($d) => str_ends_with((string)$d['exit_time'], '12:30:00')));
ok('B5) Yarım dönemleri approved=yarim (gerçek tekli yolla; onaylayan yazıldı)', count($yarimRows) === 2 && $yarimRows[0]['approved_attendance_class'] === 'yarim' && (int)$yarimRows[0]['approved_by_user_id'] === 1);
ok('B6) audit: kayıt anı onayı YALNIZ onay gereken 5 dönem için (2 Yarım + 3 Sadece FM); otomatik Tam\'lara yazma yok', count(array_filter($AUDIT, static fn($a) => $a[0] === 'update' && $a[1] === 'daily_worker_work_periods')) === 5);
$hk = pdks_faz8b_hakedis_hesapla($sidB, 1, db());
ok('B7) hakediş HESAPLANDI (beklemeden)', !empty($hk['ok']), j($hk));
$lines = db()->query("SELECT * FROM foreman_daily_entitlement_lines WHERE entitlement_id = " . (int)($hk['entitlement_id'] ?? 0))->fetchAll();
$tamSatir = array_filter($lines, static fn($l) => $l['attendance_class_snapshot'] === 'tam');
$fmSatir = array_values(array_filter($lines, static fn($l) => $l['attendance_class_snapshot'] === 'fm'));
ok('B8) Sadece FM satırları: temel ücret 0, worker_count 0, yalnız FM ücreti (200 × saat)', count($fmSatir) === 3 && (float)$fmSatir[0]['unit_rate'] === 0.0 && (int)$fmSatir[0]['worker_count'] === 0
    && (float)$fmSatir[0]['line_total'] === 200.0 && (float)$fmSatir[2]['line_total'] === 600.0, j($fmSatir));
$toplam = (float)db()->query("SELECT total_amount FROM foreman_daily_entitlements WHERE id = " . (int)$hk['entitlement_id'])->fetchColumn();
ok('B9) toplam = 3×1500 + 2×900 + (200+200+600) FM', abs($toplam - (4500 + 1800 + 1000)) < 0.01, (string)$toplam);

echo "\n=== C. ‘DAHA ÖNCE EKLEME’ KORUMASI + TARİH + SERVİS ===\n";
$c0 = pdks_faz8j_toplu_onizle(hz(['tam' => [$kadin => 1]]) + ['foreman_id' => $cA, 'work_date' => $tarih, 'depo' => 'Depo A', 'reason' => 'Test', 'note' => '', 'coklu_grup' => true], 1, db());
ok('C1) önizleme: onceki_ekleme adet 8 + tip dağılımı + son zaman', ($c0['onceki_ekleme']['adet'] ?? 0) === 8 && ($c0['onceki_ekleme']['tipler']['Kadın'] ?? 0) >= 1 && !empty($c0['onceki_ekleme']['son']), j($c0['onceki_ekleme'] ?? null));
$c1 = toplu(hz(['tam' => [$kadin => 1]]) + ['tekrar_kontrol' => true]);
ok('C2) onay YOK → sunucu REDDEDER, hiçbir şey yazılmadı', empty($c1['ok']) && str_contains(implode(' ', $c1['hatalar'] ?? []), 'Yine de ekle') && (int)db()->query("SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id = $sidB")->fetchColumn() === 8);
$c2 = toplu(hz(['tam' => [$kadin => 1]]) + ['tekrar_kontrol' => true, 'onceki_onay' => true, 'servis' => ['buyuk' => 2, 'kucuk' => 1]]);
ok('C3) onay VAR → eklendi (+1) ve servis AYNI işlemde yazıldı', !empty($c2['ok']) && (int)db()->query("SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id = $sidB")->fetchColumn() === 9, j($c2['hatalar'] ?? $c2));
$sv = pdks_servis_toplamlar($sidB, db());
ok('C4) servis adetleri: 2 Büyük + 1 Küçük', $sv['BUYUK'] === 2 && $sv['KUCUK'] === 1, j($sv));
$c3 = toplu(hz(['tam' => [$kadin => 1]]));   // tekrar_kontrol opt-in değil (kod yolu / Fiş Excel emsali) → engellenmez
ok('C5) tekrar_kontrol KAPALIYKEN (kod yolu) önceki kayıt engel değil', !empty($c3['ok']));
$c4 = pdks_faz8j_toplu_onizle(hz(['tam' => [$kadin => 1]]) + ['foreman_id' => $cA, 'work_date' => $tarih, 'depo' => 'Depo A', 'reason' => 'Test', 'note' => '', 'coklu_grup' => true, 'servis' => ['buyuk' => 1, 'kucuk' => 0]], 1, db());
ok('C6) önizleme servis özetini döner', ($c4['servis']['buyuk'] ?? 0) === 1, j($c4['servis'] ?? null));
$c5 = pdks_faz8j_toplu_onizle(hz(['tam' => [$kadin => 1]]) + ['foreman_id' => $cA, 'work_date' => $tarih, 'depo' => 'Depo A', 'reason' => 'Test', 'note' => '', 'coklu_grup' => true, 'servis' => ['buyuk' => 100, 'kucuk' => 0]], 1, db());
ok('C7) servis > 99 → hata', str_contains(implode(' ', $c5['hatalar'] ?? []), 'Servis adedi'));
// Farklı TARİH: önceki kontrol o güne bakar (temiz gün → uyarı yok)
$baska = date('Y-m-d', strtotime('-6 day'));
$tx = pdks_toplu_hizli_isle(['tam' => [$kadin => 1]], $baska, [], $kodFn);
$c6 = pdks_faz8j_toplu_onizle($tx['ek'] + ['gruplar' => $tx['gruplar'], 'foreman_id' => $cA, 'work_date' => $baska, 'depo' => 'Depo A', 'reason' => 'Test', 'note' => ''], 1, db());
ok('C8) başka tarih: önceki ekleme yok (adet 0), kayıt o tarihe', ($c6['onceki_ekleme']['adet'] ?? -1) === 0 && !empty($c6['ok']), j($c6['hatalar'] ?? $c6['onceki_ekleme'] ?? null));
$c7 = toplu($tx['ek'] + ['gruplar' => $tx['gruplar'], 'work_date' => $baska, 'tekrar_kontrol' => true]);
$wd = db()->query("SELECT work_date FROM daily_work_sessions WHERE id = " . (int)($c7['session_id'] ?? 0))->fetchColumn();
ok('C9) başka tarihe ekleme: mesai o güne açıldı (onay kutusu gerekmedi)', !empty($c7['ok']) && $wd === $baska, j($c7['hatalar'] ?? $wd));

echo "\n=== D. ELLE ÇALIŞMA EKLE + DÜZELT + ESKİ BEKLEYEN ===\n";
pdks_gunluk_kart_olustur(['card_no' => 'KX1', 'ham_uid' => '900000001', 'kaynak' => 'usb_decimal'], 1, db());
$kart1 = (int)db()->query("SELECT id FROM worker_cards WHERE card_no='KX1'")->fetchColumn();
$d1 = pdks_faz8j_gecmis_ekle(['depo' => 'Depo A', 'foreman_id' => $cA, 'work_date' => $dun, 'worker_card_id' => $kart1, 'worker_type_id' => $kadin, 'reason' => 'Test',
    'entry_date' => $dun, 'entry_clock' => '17:00', 'exit_date' => $dun, 'exit_clock' => '18:00'], 1, db());
$pid1 = (int)($d1['period_id'] ?? 0);
$z1 = $pid1 ? pdks_faz8b_donem_getir($pid1, db())['faz8b'] : [];
ok('D1) tekli Çalışma Ekle 1 saat (17:00–18:00) → kayıt anında Sadece FM 1 sa onaylı (hazır)', !empty($d1['ok']) && ($z1['etkin_sinif'] ?? '') === 'fm' && ($z1['finans_hazir'] ?? false) === true && (int)($z1['odenecek_fm_saat'] ?? 0) === 1, j([$d1, $z1['etkin_sinif'] ?? null]));
$dz = pdks_faz8j_duzelt(['period_id' => $pid1, 'session_id' => (int)$d1['session_id'], 'depo' => 'Depo A', 'reason' => 'Test', 'worker_card_id' => $kart1, 'worker_type_id' => $kadin,
    'entry_date' => $dun, 'entry_clock' => '08:00', 'exit_date' => $dun, 'exit_clock' => '13:30'], 1, db());
$z2 = pdks_faz8b_donem_getir($pid1, db())['faz8b'];
ok('D2) düzeltme (08:00–13:30, buçuklu) → yeniden otomatik onay: Yarım hazır', !empty($dz['ok']) && $z2['etkin_sinif'] === 'yarim' && $z2['finans_hazir'] === true, j([$dz, $z2['etkin_sinif']]));
// Eski bekleyen (doğrudan INSERT, onaysız) → Toplu Onay planı Yarım / Sadece FM
db()->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,normal_work_minutes_snapshot,work_date,depo,status,opened_at) VALUES (900,$cA,'Çavuş A','C001',540,'$dun2','Depo A','closed','$dun2 08:00:00')");
$kEski = [];
foreach ([['08:00', '12:30'], ['17:00', '18:00'], ['08:00', '17:00']] as $i => [$g, $c]) {
    pdks_gunluk_kart_olustur(['card_no' => 'KE' . $i, 'ham_uid' => (string)(910000000 + $i), 'kaynak' => 'usb_decimal'], 1, db());
    $kid = (int)db()->query("SELECT id FROM worker_cards WHERE card_no='KE$i'")->fetchColumn();
    db()->prepare("INSERT INTO daily_worker_card_events (session_id, worker_card_id, event_type, source, canonical_uid_snapshot, worker_type_id_snapshot, worker_type_name_snapshot, work_date_snapshot, depo_snapshot, recorded_by_user_id, server_event_time) VALUES (900,?,'GIRIS','manual','x',?,'Kadın',?,'Depo A',1,?)")->execute([$kid, $kadin, $dun2, "$dun2 $g:00"]);
    $ev = (int)db()->lastInsertId();
    db()->prepare("INSERT INTO daily_worker_work_periods (session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_event_id,entry_time,exit_time,declared_attendance_class,work_date_snapshot,depo_snapshot,status,source) VALUES (900,?,?,'Kadın',?,?,?,'auto',?, 'Depo A','closed','manual')")
        ->execute([$kid, $kadin, $ev, "$dun2 $g:00", "$dun2 $c:00", $dun2]);
    $kEski[] = (int)db()->lastInsertId();
}
$on = pdks_toplu_onay_calistir($kEski, null, null, 1, 'Depo A', false, db(), true);
ok('D3) eski bekleyen otomatik önizleme: 1 Yarım (4,5 sa), 1 Sadece FM (1 sa); 9 saatlik zaten otomatik Tam (atla); karar 0', ($on['sayac']['yarim'] ?? 0) === 1 && ($on['sayac']['sadece_fm'] ?? 0) === 1 && ($on['sayac']['onay'] ?? 0) === 2 && ($on['sayac']['atla'] ?? 0) === 1 && ($on['sayac']['karar'] ?? -1) === 0, j($on['sayac']));
$up = pdks_toplu_onay_calistir($kEski, null, null, 1, 'Depo A', true, db(), true, 2);
ok('D4) uygula: 2 onay yazıldı; Sadece FM approved=fm + FM 1 sa', !empty($up['uygulandi']) && donem($kEski[1])['approved_attendance_class'] === 'fm' && (int)donem($kEski[1])['overtime_approved_hours'] === 1 && donem($kEski[0])['approved_attendance_class'] === 'yarim');

echo "\n=== E. ÇAVUŞ LİSTESİ: FM + SERVİS TOPLAMI ===\n";
$ft = pdks_faz8b_oturum_fm_servis_toplu([$sidB, 900], db());
ok('E1) oturum başına FM onaylı (5 sa = 1+1+3 + Tam FM 0), sadece_fm 3, servis 2/1', $ft[$sidB]['fm_onayli'] === 5 && $ft[$sidB]['sadece_fm'] === 3 && $ft[$sidB]['servis_buyuk'] === 2 && $ft[$sidB]['servis_kucuk'] === 1, j($ft[$sidB] ?? null));
ok('E2) ikinci oturum (900): FM 1 sa, servis 0', ($ft[900]['fm_onayli'] ?? -1) === 1 && ($ft[900]['servis_buyuk'] ?? -1) === 0, j($ft[900] ?? null));
ok('E3) Mesai Özeti: Sadece FM tip sayacı (tanim satır biçimi DEĞİŞMEDİ)', (pdks_faz8b_gun_mesai_ozeti([$sidB], db())['sadece_fm']['Kadın'] ?? 0) === 2 && !array_key_exists('sadece_fm', pdks_faz8b_gun_mesai_ozeti([$sidB], db())['tanim']['Kadın']));

echo "\n=== F. GÜN ◀ ▶ + STATİK ===\n";
$ge = pdks_gun_gezin('geri', '2026-11-23', ['cavus' => 5, 'durum' => ''], 'x.php');
$il = pdks_gun_gezin('ileri', '2026-11-23', ['cavus' => 5], 'x.php');
ok('F1) geri: bir önceki takvim günü + filtre korunur, boş filtre atılır', str_contains($ge, 'tarih=2026-11-22') && str_contains($ge, 'cavus=5') && !str_contains($ge, 'durum='), $ge);
ok('F2) ileri: bugünden ilerisi pasif span (bağlantı yok)', str_contains(pdks_gun_gezin('ileri', date('Y-m-d')), 'is-pasif') && !str_contains(pdks_gun_gezin('ileri', date('Y-m-d')), '<a '));
ok('F3) ileri: geçmiş günde bağlantı', str_contains(pdks_gun_gezin('ileri', date('Y-m-d', strtotime('-3 day'))), '<a '));
ok('F3b) geçersiz tarih bugüne düşer (hata yok)', str_contains(pdks_gun_gezin('geri', 'zzz'), date('Y-m-d', strtotime('-1 day'))));
$src = static fn(string $f) => (string)file_get_contents($ROOT . '/' . $f);
foreach (['gunluk_isci_puantaj.php', 'cavus_hakedis.php', '_toplu_onay_ekran.php'] as $sf) {
    ok("F4) {$sf} tarih kutusunun iki yanında pdks_gun_gezin", str_contains($src($sf), "pdks_gun_gezin('geri'") && str_contains($src($sf), "pdks_gun_gezin('ileri'"));
}
ok('F5) Günlük Puantaj + Çavuş Hakediş FM/Servis sütunları (tek toplu sorgu)', str_contains($src('gunluk_isci_puantaj.php'), 'pdks_faz8b_oturum_fm_servis_toplu(') && str_contains($src('cavus_hakedis.php'), 'pdks_faz8b_oturum_fm_servis_toplu(')
    && str_contains($src('cavus_hakedis.php'), 'Fazla Mesai (sa)') && str_contains($src('cavus_hakedis.php'), 'Servis (B / K)') && str_contains($src('gunluk_isci_puantaj.php'), 'Servis (B / K)'));
ok('F6) Buçuklu Çıkışlar → Toplu Mesai Onayı butonu (bitiş tarihiyle, yetki kapılı)', str_contains($src('bucuklu_cikislar.php'), "mesai_toplu_onay.php?") && str_contains($src('bucuklu_cikislar.php'), "'tarih' => \$bitis") && str_contains($src('bucuklu_cikislar.php'), "pdks_hakedis_can('entitlements_finalize')"));
$aj = $src('_puantaj_toplu_ajax.php'); $tp = $src('_puantaj_toplu.php');
ok('F7) ajax: istemci tarihi yalnız geçerli + ≤ bugün; fm/yarım/oto_onay iç anahtarları HTTP ucundan geçmez', str_contains($aj, '$__tpIstTarih <= date(\'Y-m-d\')') && !str_contains($aj, "'fm' =>") && !str_contains($aj, "'oto_onay'") && str_contains($aj, "'tekrar_kontrol' => true"));
ok('F8) pencere: Tarih kutusu + Sadece FM + Servis + Yine de ekle onayı', str_contains($tp, 'id="tpTarih"') && str_contains($tp, 'data-sfm-kisi') && str_contains($tp, 'id="tpSvBuyuk"') && str_contains($tp, 'tpTekrarOnay'));
ok('F9) Fiş Excel genel kayıt-anı onayını KAPATIR (kendi onay seçenekleri var)', str_contains($src('config/pdks_fis.php'), "'oto_onay' => false"));
$cfg = $src('config/pdks_toplu_onay.php'); $cfgKod = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $cfg);
ok('F10) kayıt anı onayı İKİNCİ YAZMA YOLU DEĞİL: toplu_onay config gövdesinde UPDATE/INSERT yok, tek yol degerlendirme_kaydet', !preg_match('/\b(UPDATE|INSERT)\b/i', $cfgKod) && str_contains($cfgKod, 'pdks_faz8b_degerlendirme_kaydet('));
ok('F11) satir_yaz yalnız source=manual + çıkışlı dönemde hook çağırır', (bool)preg_match("/\\\$kaynak === 'manual' && \\\$exit !== null && \\(\\\$meta\\['oto_onay'\\] \\?\\? true\\) !== false/", $src('config/pdks_faz8j.php')));

echo "\n--- Sonuç: $gecen geçti, $fail kaldı ---\n";
exit($fail ? 1 : 0);
