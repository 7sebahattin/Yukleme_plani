<?php
// scripts/pdks_fis_e2e_say.php <db.sqlite> — e2e testinin DB doğrulaması (salt okunur sayımlar, JSON).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
$db = new PDO('sqlite:' . (string)($argv[1] ?? ''));
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$say = fn(string $sql) => (int)$db->query($sql)->fetchColumn();
echo json_encode([
    'mesai' => $say('SELECT COUNT(*) FROM daily_work_sessions'),
    'donem' => $say('SELECT COUNT(*) FROM daily_worker_work_periods WHERE is_voided = 0'),
    'servis' => $say('SELECT COALESCE(SUM(quantity),0) FROM daily_session_services WHERE is_voided = 0'),
    'fis_yukle' => $say("SELECT COUNT(*) FROM audit_log WHERE action = 'fis_yukle'"),
    'toplu' => $say("SELECT COUNT(*) FROM audit_log WHERE action = 'puantaj_toplu_ekle'"),
    'fm_onayli' => $say('SELECT COUNT(*) FROM daily_worker_work_periods WHERE overtime_approved_hours IS NOT NULL AND overtime_approved_hours > 0'),
    'yarim_onayli' => $say("SELECT COUNT(*) FROM daily_worker_work_periods WHERE approved_attendance_class = 'yarim'"),
    'fis_nolar' => $db->query("SELECT new_values FROM audit_log WHERE action = 'fis_yukle' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN),
], JSON_UNESCAPED_UNICODE) . "\n";
