<?php
declare(strict_types=1);
// scripts/pdks_bucuklu_cikis_smoke.php — v503 Buçuklu Çıkışlar sayfası (statik kapı denetimi + :30 süzgeci SQL'i).
//   php scripts/pdks_bucuklu_cikis_smoke.php
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function okb(string $n, bool $v, string $d = ''): void { global $pass, $fail; if ($v) { $pass++; echo "PASS $n\n"; } else { $fail++; echo "FAIL $n" . ($d !== '' ? " :: $d" : '') . "\n"; } }
$src = (string)file_get_contents($root . '/bucuklu_cikislar.php');
okb('1) yalnız yönetici (is_admin kapısı render_header\'dan önce)', (bool)preg_match('/is_admin\(\)\).*?forbidden\(/s', $src) && strpos($src, 'is_admin()') < strpos($src, 'render_header('));
okb('2) require_login + require_pdks_gunluk', str_contains($src, 'require_login()') && str_contains($src, "require_pdks_gunluk('daily_reports')"));
okb('3) POST csrf_check', (bool)preg_match("/bucuklu_duzelt'\)\s*\{\s*csrf_check\(/", $src) && str_contains($src, 'name="csrf"'));
okb('4) yazma YALNIZ pdks_faz8j_duzelt (UPDATE/INSERT/DELETE yok)', str_contains($src, 'pdks_faz8j_duzelt(') && !preg_match('/\b(UPDATE|INSERT|DELETE)\s/i', $src));
okb('5) dönem/mesai/kart/tip/giriş istemciden ALINMAZ (yalnız period_id + exit_clock + reason)', !preg_match('/\$_POST\[\'(session_id|worker_card_id|worker_type_id|entry_clock|entry_date|depo)\'\]/', $src));
okb('6) aktif depo süzgeci (s.depo + p.depo_snapshot) ve iptal edilmiş dönem dışlanır', str_contains($src, 's.depo = ? AND p.depo_snapshot = ?') && str_contains($src, 'pdks_gunluk_faz8j_etkin_kosul'));
okb('7) :30 süzgeci SUBSTR(exit_time,15,2)=\'30\' + tarih aralığı BETWEEN', str_contains($src, "SUBSTR(p.exit_time, 15, 2) = '30'") && str_contains($src, 'BETWEEN ? AND ?'));
okb('8) nav: nav_ptak_sayfalari listesinde', (bool)preg_match("/function nav_ptak_sayfalari.*?'bucuklu_cikislar\.php'/s", file_get_contents($root . '/config/helpers.php')));
okb('9) Günlük Puantaj sayfasından bağlantı', str_contains(file_get_contents($root . '/gunluk_isci_puantaj.php'), 'href="bucuklu_cikislar.php"'));
okb('10) Tarih aralığı filtresi otomatik gönderilir (data-oto-filtre) + tıklanabilir satır', str_contains($src, 'data-oto-filtre') && str_contains($src, 'data-bck-ac'));
// SQL süzgeci gerçekten yalnız :30'u seçer
$db = new PDO('sqlite::memory:'); $db->exec("CREATE TABLE t (id INTEGER, exit_time TEXT)");
foreach (['2026-01-01 20:30:00', '2026-01-01 20:00:00', '2026-01-01 19:45:00', '2026-01-01 08:30:59', '2026-01-02 21:31:00'] as $i => $z) $db->prepare('INSERT INTO t VALUES (?,?)')->execute([$i + 1, $z]);
$ids = $db->query("SELECT id FROM t WHERE SUBSTR(exit_time, 15, 2) = '30' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
okb('11) süzgeç yalnız dakikası 30 olanları seçer (1 ve 4)', array_map('intval', $ids) === [1, 4], json_encode($ids));
echo "\nSONUÇ: $pass geçti, $fail hata\n"; exit($fail === 0 ? 0 : 1);
