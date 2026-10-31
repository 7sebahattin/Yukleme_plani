<?php
// =========================================================
// scripts/pdks_yevmiye_smoke.php — Rampacı YEVMİYE (v463): daily_worker_work_periods.yevmiye_adedi
//
// Bellek içi SQLite + GERÇEK DDL çevirici + gerçek fonksiyonlar (scripts/_pdks_fis_harness.php) — canlı DB'ye dokunmaz.
//   A. Kolon migrate/hazır çifti (idempotent, genel sema_hazir'e EKLENMEZ)
//   B. Sınıflandırıcı (SAF): yevmiye > 0 sonucu EZER; yevmiye yok/0 = bugünkü davranış birebir
//   C. Hakediş: worker_count = y, unit_rate = Tam tarife, line_total = tarife × y (yuvarlama yok); Yarım/Çift/FM fiyatı gerekmez
//   D. Yöntem B: kişi-gün = Σ worker_count = Σ yevmiye
//   E. Faz 8J guard'ları (tip değişimi RED, Yarım/FM değerlendirmesi RED, HTTP ucu yevmiyeyi GEÇİRMEZ)
//   F. Statik güvenceler (rastgelelik yok, tek INSERT, migrate kartı, ikinci yazma yolu yok)
//   G. KOLONSUZ senaryo (alt süreç: PDKS_FIS_HARNESS_YEVMIYE=0): yevmiyeli fiş net hata + sıfır yazım, diğer fişler normal,
//      kiosk/saat bazlı Rampacı etkilenmez; migrate sonrası aynı süreçte özellik açılır.
//   php scripts/pdks_yevmiye_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
$KOLONSUZ = in_array('--kolonsuz', $argv ?? [], true);
if ($KOLONSUZ) putenv('PDKS_FIS_HARNESS_YEVMIYE=0');
require __DIR__ . '/_pdks_fis_harness.php';
require_once $ROOT . '/config/pdks_fis.php';
require_once $ROOT . '/config/pdks_faz8b_cavus_b.php';

function istek32(): string { return bin2hex(random_bytes(16)); }
function yeniCavus(string $ad): int {
    static $n = 60; $n++;
    return (int)pdks_gunluk_cavus_olustur(['code' => 'C0' . $n, 'name' => $ad], 1, db())['id'];
}
/** Üç tip için yalnız TAM günlük tarife (Yarım/FM/Çift fiyatı YOK). */
function sadeceTamFiyat(int $cid, string $tam = '1000', ?array $tipler = null): void {
    global $kadin, $erkek, $rampaci;
    foreach ($tipler ?? [$kadin, $erkek, $rampaci] as $tid) {
        db()->prepare("INSERT INTO foreman_worker_rates (is_active, foreman_id, worker_type_id, daily_rate, half_day_rate, overtime_mode, overtime_rate, currency, valid_from, created_by_user_id) VALUES (1,?,?,?,NULL,NULL,NULL,'TRY','2020-01-01',1)")->execute([$cid, $tid, $tam]);
    }
}
function oku1(array $kv, int $cid, string $tarihIso): array {
    $hu = ['tarih' => ['v' => date('d.m.Y', strtotime($tarihIso)), 't' => 's']];
    foreach ($kv as $a => $v) $hu[$a] = ['v' => $v, 't' => is_int($v) ? 'n' : 's'];
    $r = pdks_fis_normalize(['ok' => true, 'uyarilar' => [], 'satirlar' => [['satir' => 2, 'hucreler' => $hu]], 'bilinmeyen' => []], ['id' => $cid, 'code' => 'X', 'name' => 'X', 'is_active' => 1], 'Depo A');
    return $r['fisler'][0];
}
function yaz1(array $fis, array $sec = []): array {
    $m = pdks_fis_degerlendir(db(), $fis, $sec, 1);
    $r = pdks_fis_yaz(db(), [$fis], $sec, 1, str_repeat('c', 64), [$fis['satir']], [$fis['satir'] => $m['hash']]);
    return $r['sonuclar'][0];
}
function hesapSayi(int $sid): array {
    return db()->query("SELECT l.worker_count, l.unit_rate, l.line_total, l.worker_type_name_snapshot FROM foreman_daily_entitlement_lines l JOIN foreman_daily_entitlements e ON e.id = l.entitlement_id WHERE e.session_id = $sid ORDER BY l.id")->fetchAll();
}
function tutar(string $v): string { return number_format((float)$v, 2, '.', ''); }
/** Faz 8J üzerinden doğrudan kartsız grup ekler (kiosk/saat bazlı Rampacı gibi: yevmiye anahtarı YOK). */
function dogrudanEkle(int $cid, string $gun, array $gruplar, bool $coklu = false): array {
    global $AKTIF_DEPO;
    return pdks_faz8j_toplu_ekle(['foreman_id' => $cid, 'work_date' => $gun, 'depo' => $AKTIF_DEPO, 'reason' => 'yevmiye test', 'istek_id' => istek32(), 'coklu_grup' => $coklu, 'gruplar' => $gruplar], 1, db());
}
$sec = [];

if (!$KOLONSUZ) {
    echo "\n=== A. Kolon migrate / hazır çifti ===\n";
    ok('A1) harness kolonu kurdu: pdks_yevmiye_hazir() true', pdks_yevmiye_hazir(db()) === true);
    $r = pdks_yevmiye_kolon_migrate(db());
    ok('A2) ikinci migrate IDEMPOTENT (durum «var»)', count($r) === 1 && $r[0]['durum'] === 'var' && $r[0]['adim'] === 'daily_worker_work_periods.yevmiye_adedi', j($r));
    ok('A3) kolon tanımı tek ADD COLUMN: yevmiye_adedi TINYINT UNSIGNED NULL', pdks_yevmiye_kolon_tanimi() === [['yevmiye_adedi', 'TINYINT UNSIGNED NULL DEFAULT NULL']]);
    $src8b = file_get_contents($ROOT . '/config/pdks_faz8b.php');
    $i = strpos($src8b, 'function pdks_faz8b_sema_hazir('); $govde = substr($src8b, $i, strpos($src8b, "\n}\n", $i) - $i);
    ok('A4) pdks_faz8b_sema_hazir() yevmiye kolonunu İSTEMEZ (genel sema_hazir\'e eklenmez)', !str_contains($govde, 'yevmiye'));

    echo "\n=== B. Sınıflandırıcı (saf) ===\n";
    $satir = fn(array $ek = []) => $ek + ['entry_time' => '2026-10-01 08:00:00', 'exit_time' => '2026-10-01 09:00:00', 'normal_work_minutes_snapshot' => 540];
    $f0 = pdks_faz8b_donem_siniflandir($satir());
    ok('B1) yevmiyesiz kısa dönem (1 sa): BUGÜNKÜ davranış — karar bekliyor (sınıf yok), yevmiye=0', $f0['etkin_sinif'] === null && $f0['sinif_onayi_gerekli'] === true && $f0['finans_hazir'] === false && $f0['yevmiye'] === 0, j($f0));
    foreach ([['3', 3], [3, 3], ['', 0], [null, 0], [0, 0], ['0', 0]] as [$girdi, $beklenen]) {
        $f = pdks_faz8b_donem_siniflandir($satir(['yevmiye_adedi' => $girdi]));
        if ($beklenen > 0) {
            ok('B2) yevmiye_adedi=' . var_export($girdi, true) . ' → etkin Tam (otomatik, onay gerekmez), FM yok, finans_hazir, kaynak=yevmiye', $f['yevmiye'] === 3 && $f['etkin_sinif'] === 'tam' && $f['otomatik_sinif'] === 'tam' && $f['sinif_kaynak'] === 'yevmiye' && $f['sinif_onayi_gerekli'] === false && $f['fazla_mesai_saat'] === 0 && $f['fazla_mesai_durum'] === 'yok' && $f['odenecek_fm_saat'] === 0 && $f['cift'] === false && $f['finans_hazir'] === true, j($f));
        } else {
            ok('B3) yevmiye_adedi=' . var_export($girdi, true) . ' → yevmiye YOK (eski davranış)', $f['yevmiye'] === 0 && $f['etkin_sinif'] === null, j($f));
        }
    }
    // Uzun mesai + çift eşiği + onaylı FM: yevmiye hepsini EZER
    $oran = ['full_day_minutes' => 480, 'overtime_start_minutes' => 480, 'double_day_minutes' => 600, 'double_day_rate' => '3000', 'overtime_mode' => 'hourly', 'overtime_rate' => '200'];
    $uzun = ['entry_time' => '2026-10-01 07:00:00', 'exit_time' => '2026-10-01 23:00:00', 'normal_work_minutes_snapshot' => 540, 'overtime_approved_hours' => 3, 'approved_attendance_class' => 'yarim'];
    $fn = pdks_faz8b_donem_siniflandir($uzun, $oran);
    $fy = pdks_faz8b_donem_siniflandir($uzun + ['yevmiye_adedi' => 2], $oran);
    ok('B4) yevmiyesiz 16 sa + çift eşiği + FM onayı: çift/FM hesaplanır (kontrol)', $fn['etkin_sinif'] === 'cift' && $fn['fazla_mesai_saat'] > 0, j([$fn['etkin_sinif'], $fn['fazla_mesai_saat']]));
    ok('B5) AYNI dönem yevmiye=2: Tam, çift YOK, FM 0/yok, odenecek_fm 0, yarım onayı yok sayılır, süre alanları korunur', $fy['etkin_sinif'] === 'tam' && $fy['cift'] === false && $fy['cift_aday'] === false && $fy['fazla_mesai_saat'] === 0 && $fy['odenecek_fm_saat'] === 0 && $fy['yevmiye'] === 2 && $fy['toplam_dk'] === $fn['toplam_dk'], j($fy));
    $fc = pdks_faz8b_donem_siniflandir(['entry_time' => '2026-10-01 08:00:00', 'exit_time' => null, 'yevmiye_adedi' => 2, 'normal_work_minutes_snapshot' => 540]);
    ok('B6) çıkışsız (içeride) yevmiyeli dönem de Tam/hazır (süreye bağlı değil)', $fc['etkin_sinif'] === 'tam' && $fc['finans_hazir'] === true && $fc['yevmiye'] === 2, j($fc));
    $lbl = pdks_faz8b_mesai_tanimi_etiketi($fy, false, false);
    ok('B7) Mesai Tanımı etiketi «Yevmiye ×2»', $lbl === 'Yevmiye ×2', $lbl);

    echo "\n=== C. Hakediş: worker_count = y · unit_rate = tarife · line_total = tarife × y ===\n";
    $cC = yeniCavus('Çavuş Y1'); sadeceTamFiyat($cC, '1000');
    $rC = yaz1(oku1(['fis_no' => 'Y-001', 'rampaci_kisi' => 2, 'rampaci_yevmiye' => 2, 'kadin_tam' => 1], $cC, $dun3), $sec);
    ok('C0) fiş yazıldı (2 kişi 2 yevmiye + 1 Kadın)', $rC['durum'] === 'yazildi', j($rC));
    $sidC = (int)$rC['session_id'];
    $hC = pdks_faz8b_hakedis_hesapla($sidC, 1, db());
    ok('C1) Yarım/Çift/FM fiyatı OLMADAN hesaplanır: 1×1000,00 (Kadın) + 2×1000,00 (Rampacı yevmiye) = 3000.00', !empty($hC['ok']) && $hC['total_amount'] === '3000.00', j($hC));
    $sat = hesapSayi($sidC);
    $ramp = array_values(array_filter($sat, fn($r) => $r['worker_type_name_snapshot'] === 'Rampacı'));
    ok('C2) Rampacı: 2 satır, her biri worker_count=1, unit_rate=1000.00, line_total=1000.00 (y=1,1)', count($ramp) === 2 && array_unique(array_map(fn($r) => (int)$r['worker_count'], $ramp)) === [1] && tutar($ramp[0]['unit_rate']) === '1000.00' && tutar($ramp[0]['line_total']) === '1000.00', j($ramp));
    $cC2 = yeniCavus('Çavuş Y2'); sadeceTamFiyat($cC2, '1000.00');
    $rC2 = yaz1(oku1(['fis_no' => 'Y-002', 'rampaci_kisi' => 1, 'rampaci_yevmiye' => 2], $cC2, $dun3), $sec);
    $sidC2 = (int)$rC2['session_id'];
    $hC2 = pdks_faz8b_hakedis_hesapla($sidC2, 1, db());
    $s2 = hesapSayi($sidC2);
    ok('C3) 1 kişi 2 yevmiye: TEK satır worker_count=2 · unit_rate=1000.00 · line_total=2000.00 (2×1.000,00 = 2.000,00), toplam 2000.00', !empty($hC2['ok']) && count($s2) === 1 && (int)$s2[0]['worker_count'] === 2 && tutar($s2[0]['unit_rate']) === '1000.00' && tutar($s2[0]['line_total']) === '2000.00' && $hC2['total_amount'] === '2000.00', j([$hC2, $s2]));
    ok('C3b) tutarlılık: Σ satır = toplam; her satırda unit_rate × worker_count = line_total', array_sum(array_map(fn($r) => (float)$r['line_total'], $s2)) == (float)$hC2['total_amount'] && (float)$s2[0]['unit_rate'] * (int)$s2[0]['worker_count'] == (float)$s2[0]['line_total']);
    $cC3 = yeniCavus('Çavuş Y3'); sadeceTamFiyat($cC3, '1234.57');
    $rC3 = yaz1(oku1(['fis_no' => 'Y-003', 'rampaci_kisi' => 3, 'rampaci_yevmiye' => 7], $cC3, $dun3), $sec);
    $hC3 = pdks_faz8b_hakedis_hesapla((int)$rC3['session_id'], 1, db());
    ok('C4) kuruşlu tarife: 7 × 1234,57 = 8641.99 (tam sayı kuruş çarpımı, yuvarlama yok)', !empty($hC3['ok']) && $hC3['total_amount'] === '8641.99', j($hC3));
    $cC4 = yeniCavus('Çavuş Y4');
    $rC4 = yaz1(oku1(['fis_no' => 'Y-004', 'rampaci_kisi' => 2, 'rampaci_yevmiye' => 3], $cC4, $dun3), $sec);
    $hC4 = pdks_faz8b_hakedis_hesapla((int)$rC4['session_id'], 1, db());
    ok('C5) Rampacı Tam tarifesi YOK → «geçerli fiyat yok», hakediş DURUR (fail-closed)', empty($hC4['ok']) && str_contains(j($hC4), 'geçerli fiyat yok'), j($hC4));
    // Rampacı daily_rate boş (tarife satırı var ama Tam fiyat yok)
    $cC5 = yeniCavus('Çavuş Y5');
    db()->prepare("INSERT INTO foreman_worker_rates (is_active, foreman_id, worker_type_id, daily_rate, half_day_rate, currency, valid_from, created_by_user_id) VALUES (1,?,?,NULL,'500','TRY','2020-01-01',1)")->execute([$cC5, $rampaci]);
    $rC5 = yaz1(oku1(['fis_no' => 'Y-005', 'rampaci_kisi' => 1, 'rampaci_yevmiye' => 1], $cC5, $dun3), $sec);
    $hC5 = pdks_faz8b_hakedis_hesapla((int)$rC5['session_id'], 1, db());
    ok('C6) Tam fiyat boş (Yarım fiyatı var): yevmiye YARIM fiyatla ödenmez → eksik, hesap DURUR', empty($hC5['ok']) && str_contains(j($hC5), 'Tam Mesai fiyatı yok'), j($hC5));

    echo "\n=== D. Yöntem B: kişi-gün = Σ worker_count = Σ yevmiye ===\n";
    foreach (pdks_faz8b_cavus_ucret_b_tablolar() as $sqlB) fis_ddl_kur(db(), $sqlB);   // Yöntem B tabloları (aday_kalemler NOT EXISTS alt sorgusu)
    db()->prepare("UPDATE foreman_daily_entitlements SET status='final', finalized_at=? WHERE session_id=?")->execute([date('Y-m-d H:i:s'), $sidC2]);
    $kal = pdks_faz8b_cavus_ucret_b_aday_kalemler($cC2, [['method' => 'B', 'effective_at' => '2020-01-01 00:00:00']], db());
    ok('D1) final hakediş (1 kişi 2 yevmiye) → aday kalem kişi_gün = 2 (yevmiye = kişi-gün)', count($kal) === 1 && $kal[0]['kisi_gun'] === 2, j($kal));
    db()->prepare("UPDATE foreman_daily_entitlements SET status='final', finalized_at=? WHERE session_id=?")->execute([date('Y-m-d H:i:s'), $sidC]);
    $kal2 = pdks_faz8b_cavus_ucret_b_aday_kalemler($cC, [['method' => 'B', 'effective_at' => '2020-01-01 00:00:00']], db());
    ok('D2) karışık (1 Kadın + 2 kişi × 1 yevmiye) → kişi_gün = 3', count($kal2) === 1 && $kal2[0]['kisi_gun'] === 3, j($kal2));

    echo "\n=== E. Faz 8J guard'ları ===\n";
    $cE = yeniCavus('Çavuş Y6'); sadeceTamFiyat($cE, '1000');
    // Kiosk/saat bazlı Rampacı (yevmiye anahtarı yok) DEĞİŞMEDİ: Tam/FM/Yarım sınıflandırması
    $kE = dogrudanEkle($cE, $dun3, [['worker_type_id' => $rampaci, 'entry_clock' => '08:00', 'exit_date' => $dun3, 'exit_clock' => '17:00', 'kartsiz_adet' => 2]]);
    $sidE = (int)$kE['session_id'];
    ok('E1) saat bazlı Rampacı (yevmiyesiz) yazılır; yevmiye_adedi NULL; sınıflandırıcı Tam/süre bazlı', !empty($kE['ok']) && (int)db()->query("SELECT COUNT(*) FROM daily_worker_work_periods WHERE session_id=$sidE AND yevmiye_adedi IS NOT NULL")->fetchColumn() === 0 && pdks_faz8b_oturum_donemleri($sidE, db())[0]['faz8b']['yevmiye'] === 0 && pdks_faz8b_oturum_donemleri($sidE, db())[0]['faz8b']['sinif_kaynak'] === 'otomatik');
    $hE = pdks_faz8b_hakedis_hesapla($sidE, 1, db());
    ok('E1b) saat bazlı Rampacı hakediş: 2 × 1000 (Tam, worker_count=1 satırları) = 2000.00', !empty($hE['ok']) && $hE['total_amount'] === '2000.00', j($hE));
    // Aynı mesaiye yevmiyeli Rampacı da (opt-in kod yolu) → karışık hesap
    $yE = dogrudanEkle($cE, $dun3, [['worker_type_id' => $rampaci, 'entry_clock' => '08:00', 'exit_date' => $dun3, 'exit_clock' => '17:00', 'kartsiz_adet' => 1, 'yevmiye' => 3, 'etiket' => 'Rampacı · 3 yevmiye']], true);
    ok('E2) opt-in (coklu_grup) yevmiyeli grup yazılır; dönem yevmiye_adedi=3', !empty($yE['ok']) && (int)db()->query("SELECT yevmiye_adedi FROM daily_worker_work_periods WHERE id=" . (int)$yE['period_ids'][0])->fetchColumn() === 3, j($yE['hatalar'] ?? $yE));
    $hE2 = pdks_faz8b_hakedis_hesapla($sidE, 1, db());
    ok('E2b) karışık mesai: 2 saat bazlı Tam (2×1000) + 3 yevmiye (3×1000) = 5000.00', !empty($hE2['ok']) && $hE2['total_amount'] === '5000.00', j($hE2));
    foreach ([[0, 'yevmiye=0 → yevmiyesiz kayıt'], [11, '11 > üst sınır'], [-2, 'negatif']] as [$yv, $ad]) {
        $r = pdks_faz8j_toplu_onizle(['foreman_id' => $cE, 'work_date' => $dun2, 'depo' => $AKTIF_DEPO, 'reason' => 't', 'coklu_grup' => true,
            'gruplar' => [['worker_type_id' => $rampaci, 'entry_clock' => '08:00', 'exit_date' => $dun2, 'exit_clock' => '17:00', 'kartsiz_adet' => 1, 'yevmiye' => $yv]]], 1, db());
        if ($yv === 0) ok("E3) $ad: önizleme OK", !empty($r['ok']), j($r['hatalar'] ?? []));
        else ok("E3) $ad → önizleme REDDEDER", empty($r['ok']) && str_contains(j($r), 'Yevmiye adedi'), j($r['hatalar'] ?? []));
    }
    $pid = (int)$yE['period_ids'][0];
    $dE = pdks_faz8b_donem_getir($pid, db());
    $rd = pdks_faz8b_degerlendirme_kaydet($pid, 'yarim', null, 1, db());
    ok('E4) degerlendirme_kaydet (Yarım) yevmiyeli dönemde RED', empty($rd['ok']) && str_contains((string)$rd['hata'], 'Yevmiyeli kayıt'), j($rd));
    $rd2 = pdks_faz8b_degerlendirme_kaydet($pid, null, 1, 1, db());
    ok('E4b) degerlendirme_kaydet (FM onayı) yevmiyeli dönemde RED', empty($rd2['ok']) && str_contains((string)$rd2['hata'], 'Yevmiyeli kayıt'), j($rd2));
    $duz = pdks_faz8j_duzelt(['period_id' => $pid, 'session_id' => $sidE, 'depo' => $AKTIF_DEPO, 'reason' => 't', 'note' => '', 'worker_card_id' => (int)$dE['worker_card_id'], 'worker_type_id' => $kadin,
        'entry_date' => $dun3, 'entry_clock' => '08:00', 'exit_date' => $dun3, 'exit_clock' => '17:00'], 1, db());
    ok('E5) tek düzeltme: yevmiyeli kaydı Kadın\'a çevirme RED', empty($duz['ok']) && str_contains((string)($duz['hata'] ?? ''), 'tipi değiştirilemez'), j($duz));
    $duz2 = pdks_faz8j_toplu_duzelt($sidE, [['period_id' => $pid, 'worker_type_id' => $kadin, 'entry_clock' => '08:00', 'exit_clock' => '17:00']], 'toplu test', '', istek32(), 1, db());
    ok('E6) TOPLU düzeltme de aynı kuralı uygular (yevmiyeli kaydın tipi değişmez, hiçbir şey yazılmaz)', empty($duz2['ok']) && (int)db()->query("SELECT worker_type_id_snapshot FROM daily_worker_work_periods WHERE id=$pid")->fetchColumn() === $rampaci, j($duz2));
    $ip = pdks_faz8j_toplu_geri_al((string)$yE['toplu_id'], 'geri al', 1, db());
    ok('E7) iptal (toplu geri al) yevmiyeli dönemi iptal eder; hakediş yalnız kalan 2 saat bazlı Rampacı (2000.00)', !empty($ip['ok']) && pdks_faz8b_hakedis_hesapla($sidE, 1, db())['total_amount'] === '2000.00');
    // Faz 4 eski motor guard'ı
    require_once $ROOT . '/config/pdks_hakedis.php';
    $cF = yeniCavus('Çavuş Y7'); sadeceTamFiyat($cF, '1000');
    $rF = yaz1(oku1(['fis_no' => 'Y-F4', 'rampaci_kisi' => 1, 'rampaci_yevmiye' => 1], $cF, $dun3), $sec);
    $h4 = pdks_hakedis_hesapla((int)$rF['session_id'], 1, db());
    ok('E8) Faz 4 eski motoru yevmiyeli oturumu REDDEDER (kod yevmiye_faz8b_gerekli), hiçbir hakediş satırı yazmaz', empty($h4['ok']) && ($h4['kod'] ?? '') === 'yevmiye_faz8b_gerekli' && (int)db()->query("SELECT COUNT(*) FROM foreman_daily_entitlements WHERE session_id=" . (int)$rF['session_id'])->fetchColumn() === 0, j($h4));
    // Mesai Özeti
    $oz = pdks_faz8b_gun_mesai_ozeti([$sidE], db());
    ok('E9) Mesai Özeti: yevmiye iptal sonrası 0', ($oz['yevmiye'] ?? -1) === 0);

    echo "\n=== F. Statik güvenceler ===\n";
    $fis = file_get_contents($ROOT . '/config/pdks_fis.php');
    $i = strpos($fis, 'function pdks_fis_rampaci_dagit('); $dagit = substr($fis, $i, strpos($fis, "\n}\n", $i) - $i);
    ok('F1) pdks_fis_rampaci_dagit: rastgelelik YOK (random_int|mt_rand|shuffle|rand()', !preg_match('/\b(random_int|mt_rand|shuffle|array_rand)\b|[^a-z_]rand\(/', $dagit));
    $j8 = file_get_contents($ROOT . '/config/pdks_faz8j.php');
    ok('F2) dönem INSERT\'ü TEK yerde (pdks_faz8j_satir_yaz; yevmiye dinamik kolon)', substr_count($j8, 'INSERT INTO daily_worker_work_periods') === 1 && str_contains($j8, ', yevmiye_adedi'));
    $ajax = file_get_contents($ROOT . '/_puantaj_toplu_ajax.php');
    ok('F3) HTTP ucu (_puantaj_toplu_ajax.php) yevmiye / coklu_grup / yazdiktan_sonra / audit_ek anahtarını GEÇİRMEZ', !preg_match('/yevmiye|coklu_grup|yazdiktan_sonra|audit_ek/', $ajax));
    $mig = file_get_contents($ROOT . '/migrate.php');
    ok('F4) migrate.php: «Rampacı yevmiye alanı» kartı (rampaci-yevmiye) + POST ne=pdks_yevmiye + pdks_yevmiye_kolon_migrate + csrf', str_contains($mig, "mg_kart_ac('rampaci-yevmiye')") && str_contains($mig, "'pdks_yevmiye'") && str_contains($mig, 'pdks_yevmiye_kolon_migrate($pdo)') && preg_match("/'pdks_yevmiye'\)\s*\{\s*csrf_check/", $mig) === 1);
    ok('F5) ekranlarda migrate ÇAĞRILMAZ (pdks_yevmiye_kolon_migrate yalnız migrate.php\'de çağrılır)', (function () use ($ROOT) {
        foreach (glob($ROOT . '/*.php') as $f) { if (basename($f) === 'migrate.php') continue; if (str_contains((string)file_get_contents($f), 'pdks_yevmiye_kolon_migrate')) return false; }
        return true;
    })());
    $hk = file_get_contents($ROOT . '/config/pdks_hakedis.php');
    ok('F6) Faz 4 guard function_exists()\'e güvenmez: kolon doğrudan sorgulanır', str_contains($hk, "yevmiye_adedi IS NOT NULL AND yevmiye_adedi > 0") && str_contains($hk, 'yevmiye_faz8b_gerekli'));
    $b8 = file_get_contents($ROOT . '/config/pdks_faz8b.php');
    $i = strpos($b8, 'function pdks_faz8b_hakedis_hesapla('); $hesap = substr($b8, $i);
    ok('F7) hakediş yevmiye dalı: worker_count = $yev, line_total = baseKurus × yev (yuvarlama yok)', str_contains($hesap, "'worker_count' => \$yev") && str_contains($hesap, '$yKurus * $yev') && !preg_match('/round\(.*\$yev/', $hesap));
    $kont = file_get_contents($ROOT . '/config/pdks_faz8b_cavus_b.php');
    ok('F8) Yöntem B kodu DEĞİŞMEDİ: kişi-gün SUM(worker_count) (yevmiye bilmez)', str_contains($kont, 'SUM(l.worker_count)') && !str_contains($kont, 'yevmiye'));

    echo "\n=== F2. Mesai Detayı render (rozet · Mesai Tanımı · Mesai Özeti notu) ===\n";
    $cmdR = 'PUANTAJ_FAZ8B=1 PUANTAJ_YEVMIYE=1 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/pdks_puantaj_dialog_render.php') . ' 2>/dev/null';
    $htmlR = (string)shell_exec($cmdR);
    ok('F9) Mesai Detayı render edildi (boş değil)', strlen($htmlR) > 5000, (string)strlen($htmlR));
    ok('F9b) yevmiyeli dönem: «↻ 3 yevmiye» rozeti (masaüstü tablo + mobil kart) ve Mesai Tanımı «Yevmiye ×3»', substr_count($htmlR, 'pdks-badge-yevmiye') >= 2 && str_contains($htmlR, '↻ 3 yevmiye') && str_contains($htmlR, 'Yevmiye ×3'));
    ok('F9c) Mesai Özeti: «Rampacı yevmiye: 3» notu (yevmiyeli dönem Tam kişi sayılır, sentetik süre özete katılmaz)', str_contains($htmlR, 'Rampacı yevmiye:') && preg_match('#Rampacı yevmiye:</strong>\s*3#u', $htmlR) === 1);
    $htmlN = (string)shell_exec('PUANTAJ_FAZ8B=1 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/pdks_puantaj_dialog_render.php') . ' 2>/dev/null');
    ok('F9d) yevmiyesiz (varsayılan) render: rozet / not YOK (eski görünüm birebir)', strlen($htmlN) > 5000 && !str_contains($htmlN, 'pdks-badge-yevmiye') && !str_contains($htmlN, 'Rampacı yevmiye:') && !str_contains($htmlN, 'Yevmiye ×'));

    echo "\n=== G. KOLONSUZ senaryo (alt süreç, yevmiye_adedi kolonu YOK) ===\n";
    $cmd = 'PDKS_FIS_HARNESS_YEVMIYE=0 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --kolonsuz 2>&1';
    $cikti = []; $kod = 0;
    exec($cmd, $cikti, $kod);
    $govde = implode("\n", $cikti);
    foreach ($cikti as $satir) if (preg_match('/\*\*\* HATA|Fatal|Warning|Notice|Deprecated/', $satir)) echo '    ' . $satir . "\n";
    preg_match('/Sonuç: (\d+) geçti, (\d+) kaldı/', $govde, $m);
    ok('G) kolonsuz alt süreç: tüm kontroller geçti (' . ($m[1] ?? '?') . ' geçti, ' . ($m[2] ?? '?') . ' kaldı), çıkış kodu 0', $kod === 0 && isset($m[2]) && (int)$m[2] === 0 && (int)$m[1] >= 10, mb_substr($govde, -1200));
} else {
    // ------------------------------------------------------------------
    echo "\n[kolonsuz] pdks_yevmiye_hazir() = " . var_export(pdks_yevmiye_hazir(db()), true) . "\n";
    ok('G1) kolon YOK: pdks_yevmiye_hazir() false', pdks_yevmiye_hazir(db()) === false);
    $f = pdks_faz8b_donem_siniflandir(['entry_time' => '2026-10-01 08:00:00', 'exit_time' => '2026-10-01 17:00:00', 'normal_work_minutes_snapshot' => 540]);
    ok('G2) sınıflandırıcı kolonsuz satırda eski davranış (Tam, yevmiye=0)', $f['etkin_sinif'] === 'tam' && $f['yevmiye'] === 0 && $f['sinif_kaynak'] === 'otomatik');
    $cG = yeniCavus('Çavuş K1'); sadeceTamFiyat($cG, '1000');
    // Yevmiyeli fiş: net hata, HİÇBİR yazım
    $fy = oku1(['fis_no' => 'K-001', 'rampaci_kisi' => 3, 'rampaci_yevmiye' => 5], $cG, $dun3);
    ok('G3) normalize kolon durumunu bilmez (hata yok) — kapı plan/önizlemededir', $fy['hatalar'] === [], j($fy['hatalar']));
    $once = sayimlar();
    $m = pdks_fis_degerlendir(db(), $fy, $sec, 1);
    ok('G4) önizleme: HATA «Rampacı yevmiye alanı kurulmadı (migrate.php › «Rampacı yevmiye alanı»)», seçilemez', $m['durum'] === 'hata' && !$m['secilebilir'] && str_contains(implode(' ', $m['hatalar']), 'Rampacı yevmiye alanı kurulmadı') && str_contains(implode(' ', $m['hatalar']), 'migrate.php'), j($m['hatalar']));
    $r = pdks_fis_yaz(db(), [$fy], $sec, 1, str_repeat('d', 64), [$fy['satir']], []);
    ok('G5) yazma da REDDEDİLİR ve HİÇBİR yazım yok (mesai/dönem/olay/audit sayımları aynı)', $r['sonuclar'][0]['durum'] === 'hata' && $once === sayimlar(), j([$r['sonuclar'][0], $once, sayimlar()]));
    // Diğer tipler etkilenmez
    $fd = oku1(['fis_no' => 'K-002', 'kadin_tam' => 2, 'erkek_tam' => 1], $cG, $dun3);
    $rd = yaz1($fd, $sec);
    $hd = pdks_faz8b_hakedis_hesapla((int)$rd['session_id'], 1, db());
    ok('G6) yevmiyesiz fiş KOLONSUZ da yazılır; hakediş 3 × 1000 = 3000.00 (regresyon yok)', $rd['durum'] === 'yazildi' && !empty($hd['ok']) && $hd['total_amount'] === '3000.00', j([$rd['durum'], $hd]));
    require_once $ROOT . '/config/pdks_hakedis.php';
    $h4b = pdks_hakedis_hesapla((int)$rd['session_id'], 1, db());
    ok('G7) Faz 4 guard kolonsuzken YEVMİYE nedeniyle reddetmez (PDOException yutulur)', ($h4b['kod'] ?? '') !== 'yevmiye_faz8b_gerekli', j($h4b));
    // Faz 8J yevmiye spec'i reddedilir
    $y = pdks_faz8j_toplu_onizle(['foreman_id' => $cG, 'work_date' => $dun2, 'depo' => $AKTIF_DEPO, 'reason' => 't', 'coklu_grup' => true,
        'gruplar' => [['worker_type_id' => $rampaci, 'entry_clock' => '08:00', 'exit_date' => $dun2, 'exit_clock' => '17:00', 'kartsiz_adet' => 1, 'yevmiye' => 2]]], 1, db());
    ok('G8) Faz 8J: yevmiyeli grup kolonsuzken REDDEDİLİR («kolon kuruluysa»)', empty($y['ok']) && str_contains(j($y), 'kolon kuruluysa'), j($y['hatalar'] ?? $y));
    // Kiosk/saat bazlı Rampacı (yevmiyesiz) kolonsuzken normal
    $k = dogrudanEkle($cG, $dun2, [['worker_type_id' => $rampaci, 'entry_clock' => '08:00', 'exit_date' => $dun2, 'exit_clock' => '17:00', 'kartsiz_adet' => 2]]);
    $hk = pdks_faz8b_hakedis_hesapla((int)$k['session_id'], 1, db());
    ok('G9) saat bazlı Rampacı kolonsuz: yazılır, hakediş 2 × 1000 = 2000.00', !empty($k['ok']) && !empty($hk['ok']) && $hk['total_amount'] === '2000.00', j([$k['hatalar'] ?? null, $hk]));
    // Karışık fiş (Kadın + yevmiyeli Rampacı) tamamen reddedilir, Kadın kısmı da yazılmaz (hep-ya-hiç fiş başına)
    $fk = oku1(['fis_no' => 'K-003', 'kadin_tam' => 2, 'rampaci_kisi' => 1, 'rampaci_yevmiye' => 1], $cG, date('Y-m-d', strtotime($dun3 . ' -1 day')));
    $once2 = sayimlar();
    $rk = pdks_fis_yaz(db(), [$fk], $sec, 1, str_repeat('e', 64), [$fk['satir']], []);
    ok('G10) Kadın + yevmiyeli Rampacı aynı fişte: fiş hatalı, KADIN kısmı da yazılmaz', $rk['sonuclar'][0]['durum'] === 'hata' && $once2 === sayimlar(), j($rk['sonuclar'][0]));
    // Migrate: aynı süreçte kolon eklenir, özellik açılır
    $mg = pdks_yevmiye_kolon_migrate(db());
    ok('G11) migrate kolonu ekler (durum «eklendi») ve pdks_yevmiye_hazir() true olur', $mg[0]['durum'] === 'eklendi' && pdks_yevmiye_hazir(db()) === true, j($mg));
    $mg2 = pdks_yevmiye_kolon_migrate(db());
    ok('G12) ikinci migrate «var» (idempotent)', $mg2[0]['durum'] === 'var', j($mg2));
    $cG2 = yeniCavus('Çavuş K2'); sadeceTamFiyat($cG2, '1000');   // aynı fiş (K-001, hiç yazılmamıştı) temiz bir mesaiye
    $fy2 = oku1(['fis_no' => 'K-001', 'rampaci_kisi' => 3, 'rampaci_yevmiye' => 5], $cG2, $dun3);
    $ry = yaz1($fy2, $sec);
    $hy = pdks_faz8b_hakedis_hesapla((int)$ry['session_id'], 1, db());
    ok('G13) migrate SONRASI aynı yevmiyeli fiş yazılır: 3 kişi 5 yevmiye → hakediş 5 × 1000 = 5000.00', $ry['durum'] === 'yazildi' && !empty($hy['ok']) && $hy['total_amount'] === '5000.00', j([$ry['durum'], $ry['mesaj'] ?? null, $hy]));
}

echo "\n--- Sonuç: $gecen geçti, $fail kaldı ---\n";
exit($fail > 0 ? 1 : 0);
