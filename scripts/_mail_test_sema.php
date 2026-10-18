<?php
// =========================================================
// scripts/_mail_test_sema.php — Mail testlerinin ŞEMA yardımcıları (include-only; stub TANIMLAMAZ).
// _mail_test_lib.php (mail_*_smoke) ve _siparis_mail_test_lib.php (siparis_mail_smoke) ortak kullanır:
// iki harness aynı süreçte yüklenemez (global db()/can() stub'ları çakışır), şema çevirisi ise paylaşılır.
// Önkoşul: config/mail_core.php yüklü (mail_tablolar()). MySQL kipi: mail_test_mysql() tanımlıysa ve true dönerse.
// =========================================================
declare(strict_types=1);

// ── MySQL DDL → SQLite çevirici (pdks_cavus_b_smoke.php'nin sadeleştirilmişi) ──
function mail_ddl_sqlite(string $mysql): array
{
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m)) {
        throw new RuntimeException('DDL ayrıştırılamadı');
    }
    $tablo = $m[1]; $govde = $m[2];
    $parcalar = []; $buf = ''; $d = 0;
    for ($i = 0, $n = strlen($govde); $i < $n; $i++) {
        $c = $govde[$i];
        if ($c === '(') $d++;
        if ($c === ')') $d--;
        if ($c === ',' && $d === 0) { $parcalar[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $parcalar[] = trim($buf);
    $kolonlar = []; $indeksler = [];
    foreach ($parcalar as $p) {
        $p = preg_replace('/\s+/', ' ', $p);
        if (preg_match('/^UNIQUE KEY `([^`]+)` \((.+)\)$/i', $p, $mm)) { $indeksler[] = "CREATE UNIQUE INDEX `{$mm[1]}` ON `{$tablo}` ({$mm[2]})"; continue; }
        if (preg_match('/^(?:INDEX|KEY) `([^`]+)` \((.+)\)$/i', $p, $mm)) { $indeksler[] = "CREATE INDEX `{$mm[1]}` ON `{$tablo}` ({$mm[2]})"; continue; }
        if (preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY/i', $p)) continue;
        $p = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
        $kolonlar[] = $p;
    }
    return ["CREATE TABLE `{$tablo}` (\n  " . implode(",\n  ", $kolonlar) . "\n)", $indeksler];
}
/** mail_migrate() gerçek akışını SQLite'ta çalıştırmak için: DDL'i çevirip uygular. */
function mail_test_sema_kur(PDO $db): void
{
    if (function_exists('mail_test_mysql') && mail_test_mysql()) {   // gerçek DDL, olduğu gibi (FK'ler dahil)
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (array_reverse(array_keys(mail_tablolar())) as $t) $db->exec("DROP TABLE IF EXISTS `$t`");
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
        foreach (mail_tablolar() as $sql) $db->exec($sql);
        return;
    }
    foreach (mail_tablolar() as $sql) {
        [$create, $ix] = mail_ddl_sqlite($sql);
        $db->exec($create);
        foreach ($ix as $x) $db->exec($x);
    }
}

/** Yalnız test: sahte (üretilmiş) anahtar; gerçek anahtar ASLA kullanılmaz. */
function mail_test_anahtar_kur(): string
{
    $b64 = base64_encode(str_repeat("\x07", 16) . random_bytes(16));
    putenv('MAIL_MASTER_KEY=' . $b64);
    return $b64;
}
