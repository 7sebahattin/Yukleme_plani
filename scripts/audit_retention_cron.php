<?php
// =========================================================
// scripts/audit_retention_cron.php — audit_log Saklama ve Arşivleme (B-32)
//
// 365 günden eski audit_log kayıtlarını storage/audit_archive/audit_YYYY-MM.jsonl.gz
// dosyalarına aktarır ve YALNIZCA arşiv doğrulandıktan sonra --apply ile siler.
//
// Kullanım (yalnız CLI):
//   php scripts/audit_retention_cron.php                → kuru çalıştırma (silmez)
//   php scripts/audit_retention_cron.php --apply        → arşivle + sil
//   php scripts/audit_retention_cron.php --days=180 --apply
// =========================================================
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Yalnız komut satırından çalışır.');
}

/**
 * Verilen PDO üzerinde belirli günden eski audit_log kayıtlarını JSONL.GZ arşivine aktarır.
 * @return array{ok:bool,dry_run:bool,cutoff:string,aday:int,arsivlenen:int,silinen:int,dosyalar:list<string>}
 */
function audit_retention_calistir(PDO $pdo, string $archiveDir, int $days = 365, bool $apply = false, ?int $nowTs = null): array
{
    $days = max(30, $days);
    $cutoff = date('Y-m-d H:i:s', ($nowTs ?? time()) - ($days * 86400));
    $stCnt = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE created_at < ?');
    $stCnt->execute([$cutoff]);
    $aday = (int)$stCnt->fetchColumn();

    if ($aday === 0 || !$apply) {
        return [
            'ok' => true,
            'dry_run' => !$apply,
            'cutoff' => $cutoff,
            'aday' => $aday,
            'arsivlenen' => 0,
            'silinen' => 0,
            'dosyalar' => [],
        ];
    }

    if (!is_dir($archiveDir) && !@mkdir($archiveDir, 0750, true)) {
        throw new RuntimeException('Arşiv klasörü oluşturulamadı: ' . $archiveDir);
    }
    $htPath = rtrim($archiveDir, '/') . '/.htaccess';
    if (!is_file($htPath)) {
        @file_put_contents($htPath, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }

    $arsivlenen = 0;
    $silinen = 0;
    $dosyalar = [];
    $lastId = 0;

    while (true) {
        $st = $pdo->prepare(
            'SELECT * FROM audit_log WHERE created_at < ? AND id > ? ORDER BY id ASC LIMIT 1000'
        );
        $st->execute([$cutoff, $lastId]);
        $batch = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($batch === []) break;

        $byMonth = [];
        $ids = [];
        foreach ($batch as $row) {
            $id = (int)$row['id'];
            $lastId = max($lastId, $id);
            $ids[] = $id;
            $ym = preg_match('/^(\d{4}-\d{2})/', (string)($row['created_at'] ?? ''), $m) ? $m[1] : 'unknown';
            $byMonth[$ym][] = $row;
        }

        foreach ($byMonth as $ym => $rows) {
            $file = rtrim($archiveDir, '/') . '/audit_' . $ym . '.jsonl.gz';
            $gz = @gzopen($file, 'ab6');
            if ($gz === false) {
                throw new RuntimeException('Arşiv dosyası açılamadı: ' . $file);
            }
            $yazilan = 0;
            foreach ($rows as $row) {
                $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                if (gzwrite($gz, $line) === false) {
                    gzclose($gz);
                    throw new RuntimeException('Arşiv dosyasına yazılamadı: ' . $file);
                }
                $yazilan++;
            }
            gzclose($gz);
            @chmod($file, 0600);
            $arsivlenen += $yazilan;
            $dosyalar[$file] = true;
        }

        // Yalnızca gzip dosyasına yazım + kapanış başarıyla tamamlandıktan sonra sil
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $del = $pdo->prepare("DELETE FROM audit_log WHERE id IN ($ph)");
        $del->execute($ids);
        $silinen += $del->rowCount();
    }

    return [
        'ok' => true,
        'dry_run' => false,
        'cutoff' => $cutoff,
        'aday' => $aday,
        'arsivlenen' => $arsivlenen,
        'silinen' => $silinen,
        'dosyalar' => array_keys($dosyalar),
    ];
}

// Doğrudan CLI'dan çalıştırıldığında (include/require edilmediğinde) yürüt
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    require_once __DIR__ . '/../config/db.php';
    $apply = in_array('--apply', $argv ?? [], true);
    $days = 365;
    foreach ($argv ?? [] as $arg) {
        if (preg_match('/^--days=(\d+)$/', (string)$arg, $m)) {
            $days = max(30, (int)$m[1]);
        }
    }
    try {
        $dir = dirname(__DIR__) . '/storage/audit_archive';
        $res = audit_retention_calistir(db(), $dir, $days, $apply);
        echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'FAIL ' . $e->getMessage() . "\n");
        exit(1);
    }
}
