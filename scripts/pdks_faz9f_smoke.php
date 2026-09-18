<?php
// Faz 9F: request-local schema metadata and unchanged calculation contracts.
declare(strict_types=1);

require_once __DIR__ . '/../config/pdks_faz8b.php';
require_once __DIR__ . '/../config/pdks_rapor.php';

final class Faz9fCountingPdo extends PDO
{
    public int $metadataQueries = 0;

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (str_starts_with(strtoupper(trim($query)), 'PRAGMA TABLE_INFO')) $this->metadataQueries++;
        return parent::query($query);
    }
}

$failed = 0;
function ok9f(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? 'OK ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) $failed++;
}

$db = new Faz9fCountingPdo('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE one (a INTEGER, b TEXT)');
$db->exec('CREATE TABLE two (a INTEGER)');

ok9f('existing column', pdks_gunluk_kolon_var($db, 'one', 'a'));
ok9f('repeated column uses one metadata query', pdks_faz8b_kolon_var($db, 'one', 'a') && $db->metadataQueries === 1);
ok9f('different column resolved independently', pdks_gunluk_faz8j_kolon_var($db, 'one', 'b') && $db->metadataQueries === 2);
ok9f('different table resolved independently', pdks_gunluk_kolon_var($db, 'two', 'a') && $db->metadataQueries === 3);
ok9f('missing column fails closed', !pdks_gunluk_kolon_var($db, 'one', 'missing') && $db->metadataQueries === 4);
ok9f('missing column is cached', !pdks_faz8b_kolon_var($db, 'one', 'missing') && $db->metadataQueries === 4);
$other = new Faz9fCountingPdo('sqlite::memory:');
$other->exec('CREATE TABLE one (a INTEGER)');
ok9f('SQLite connections are isolated', !pdks_gunluk_kolon_var($other, 'one', 'b') && $other->metadataQueries === 1);
$db->exec('ALTER TABLE one ADD COLUMN missing TEXT');
pdks_gunluk_kolon_onbellek_temizle($db, 'one');
ok9f('migration invalidation refreshes metadata', pdks_gunluk_kolon_var($db, 'one', 'missing') && $db->metadataQueries === 5);
ok9f('attendance duration unchanged', pdks_faz8b_sure_karari('2026-01-01 08:00:00', '2026-01-01 17:16:00', 540)['fazla_mesai_saat'] === 1);
ok9f('entitlement kuruş arithmetic unchanged', pdks_hakedis_tl_kurus('123.45') === 12345 && pdks_hakedis_kurus_tl(12345) === '123.45');
ok9f('report calculation unchanged', pdks_rapor_yuzde_degisim(200, 250) === ['fark' => 50, 'yuzde' => 25.0, 'yeni_mi' => false]);
exit($failed ? 1 : 0);
