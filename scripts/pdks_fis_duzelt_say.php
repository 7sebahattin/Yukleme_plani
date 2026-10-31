<?php
// scripts/pdks_fis_duzelt_say.php <db.sqlite> — pdks_fis_duzelt_smoke.js'in DB doğrulaması (salt okunur sayımlar, JSON).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
$db = new PDO('sqlite:' . (string)($argv[1] ?? ''));
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$say = fn(string $sql) => (int)$db->query($sql)->fetchColumn();
$tip = fn(int $sid) => $db->query("SELECT worker_type_name_snapshot AS ad, COUNT(*) AS n FROM daily_worker_work_periods WHERE session_id = $sid AND is_voided = 0 GROUP BY worker_type_name_snapshot ORDER BY ad")->fetchAll(PDO::FETCH_KEY_PAIR);
$sids = array_map('intval', array_slice($argv, 2));
$out = [
    'donem_toplam' => $say('SELECT COUNT(*) FROM daily_worker_work_periods'),
    'aktif' => $say('SELECT COUNT(*) FROM daily_worker_work_periods WHERE is_voided = 0'),
    'iptal' => $say('SELECT COUNT(*) FROM daily_worker_work_periods WHERE is_voided = 1'),
    'servis_toplam' => $say('SELECT COUNT(*) FROM daily_session_services'),
    'servis_aktif' => $say('SELECT COALESCE(SUM(quantity),0) FROM daily_session_services WHERE is_voided = 0'),
    'mesai' => $say('SELECT COUNT(*) FROM daily_work_sessions'),
    'kart' => $say('SELECT COUNT(*) FROM worker_cards'),
    'fis_sifirla' => $say("SELECT COUNT(*) FROM audit_log WHERE action = 'fis_sifirla'"),
    'fis_yukle' => $say("SELECT COUNT(*) FROM audit_log WHERE action = 'fis_yukle'"),
    'sifre_onay_engel' => $say("SELECT COUNT(*) FROM audit_log WHERE action = 'sifre_onay_engel'"),
    'tipler' => [],
];
foreach ($sids as $sid) $out['tipler'][(string)$sid] = $tip($sid);
echo json_encode($out, JSON_UNESCAPED_UNICODE) . "\n";
