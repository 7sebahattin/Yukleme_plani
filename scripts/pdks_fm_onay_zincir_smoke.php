<?php
declare(strict_types=1);
// FM onay zinciri: sınıflandırıcı kırpma + Mesai Tanımı etiketi (DB yok).  php scripts/pdks_fm_onay_zincir_smoke.php
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
function db(): PDO { return new PDO('sqlite::memory:'); }
require_once $root . '/config/pdks_gunluk.php';
require_once $root . '/config/pdks_hakedis.php';
require_once $root . '/config/pdks_faz8b.php';
$fail = 0;
function ck(string $n, bool $v, string $d = ''): void { global $fail; echo ($v ? 'PASS ' : 'FAIL ') . $n . ($v ? '' : " :: $d") . "\n"; if (!$v) $fail++; }
function d(string $g, string $c, $onay): array { return ['entry_time'=>"2026-10-08 $g:00",'exit_time'=>"2026-10-08 $c:00",'normal_work_minutes_snapshot'=>540,'overtime_approved_hours'=>$onay]; }

// 1) aday: 07:57-18:11 -> etkin 08:00-18:00 = 600dk -> FM 1
$f = pdks_faz8b_donem_siniflandir(d('07:57','18:11',null));
ck('aday 1, onay yok -> bekliyor', $f['fazla_mesai_saat']===1 && $f['fazla_mesai_durum']==='bekliyor' && $f['odenecek_fm_saat']===0);
ck('etiket bekliyor', pdks_faz8b_mesai_tanimi_etiketi($f,false,false)==='Tam · FM 1 s (bekliyor)', pdks_faz8b_mesai_tanimi_etiketi($f,false,false));

// 2) v317 öncesi ham: 07:50-18:20 ham=630dk(FM 2) onay 2; etkin 07:50-18:20 degişmez... asıl: 07:57-19:11 ham 674 -> FM 3; etkin 08:00-19:00=660 -> FM 2
$f = pdks_faz8b_donem_siniflandir(d('07:57','19:11',3));
ck('eski onay 3, aday 2 -> kırpılır 2', $f['fazla_mesai_saat']===2 && $f['fazla_mesai_onay_saat']===2 && $f['fazla_mesai_onay_kirpildi'] && $f['odenecek_fm_saat']===2, json_encode([$f['fazla_mesai_saat'],$f['fazla_mesai_onay_saat'],$f['odenecek_fm_saat']]));
// 3) aday 0'a düşerse
$f = pdks_faz8b_donem_siniflandir(d('07:57','17:16',2));
$f2 = pdks_faz8b_donem_siniflandir(d('08:14','17:14',2));
ck('aday 0 iken eski onay -> ödenecek 0, durum yok', $f2['fazla_mesai_saat']===0 && $f2['odenecek_fm_saat']===0 && $f2['fazla_mesai_durum']==='yok', json_encode($f2['fazla_mesai_durum']));
// 4) sunucu üst sınır
$src = file_get_contents($root.'/config/pdks_faz8b.php');
ck('sunucu aday üstünü reddeder', str_contains($src, '$overtimeApprovedHours > $fmSaat'));
exit($fail ? 1 : 0);
