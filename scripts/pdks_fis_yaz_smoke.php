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

function istek32(): string { return bin2hex(random_bytes(16)); }

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

echo "\n--- Sonuç: $gecen geçti, $fail kaldı ---\n";
exit($fail > 0 ? 1 : 0);
