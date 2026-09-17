<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$root=dirname(__DIR__); $read=fn($f)=>(string)file_get_contents($root.'/'.$f);
$j=$read('config/pdks_faz8j.php'); $g=$read('config/pdks_gunluk.php'); $b=$read('config/pdks_faz8b.php'); $e=$read('config/pdks_faz8e.php'); $h=$read('config/pdks_hakedis.php'); $r=$read('config/pdks_rapor.php'); $d=$read('gunluk_isci_puantaj_detay.php'); $scan=$read('gunluk_isci_giris_cikis.php');
$pass=0;$fail=0; function ok8j($n,$v){global $pass,$fail; $v?$pass++:$fail++; printf("%-88s %s\n",$n,$v?'OK':'HATA');}
$checks=[
'non-admin edit blocked'=>str_contains($j,'pdks_faz8j_yetki()'), 'non-admin void blocked'=>substr_count($j,'pdks_faz8j_yetki()')>=2,
'admin correction transaction'=>str_contains($j,'beginTransaction()'), 'admin void transaction'=>str_contains($j,"'puantaj_iptal'"),
'active-depot period ownership'=>str_contains($j,'s.depo=? AND p.depo_snapshot=?'), 'period id used'=>str_contains($j,'period_id'),
'wrong session rejected'=>str_contains($j,'p.session_id=?'), 'historical dates not today-restricted'=>!str_contains($j,"work_date'] === date('Y-m-d')"),
'type id corrected'=>str_contains($j,'worker_type_id_snapshot=?'), 'type name corrected'=>str_contains($j,'worker_type_name_snapshot=?'),
'master card type untouched'=>!str_contains($j,'worker_cards.worker_type_id'), 'entry correction'=>str_contains($j,'entry_time=?'),
'exit correction'=>str_contains($j,'exit_time=?'), 'malformed date validation'=>str_contains($j,'DateTimeImmutable::createFromFormat'),
'exit before entry rejected'=>str_contains($j,'$exit<$entry'), '24 hour exit cap'=>str_contains($j,'+86400'),
'future correction rejected'=>str_contains($j,"date('Y-m-d H:i:s')"), 'card reassignment'=>str_contains($j,'worker_card_id=?'),
'overlap protection'=>str_contains($j,"COALESCE(exit_time,'9999-12-31 23:59:59')"), 'sequential reuse allowed'=>str_contains($j,'entry_time < COALESCE'),
'raw entry never updated'=>!preg_match('/UPDATE\s+daily_worker_card_events/i',$j), 'raw events never deleted'=>!preg_match('/DELETE\s+FROM\s+daily_worker_card_events/i',$j),
'manual exit shared helper'=>str_contains($j,'pdks_faz8e_manuel_cikis_olayi_ekle'), 'Faz8E uses shared helper'=>str_contains($e,'pdks_faz8e_manuel_cikis_olayi_ekle'),
'manual exit source'=>str_contains($e,"'CIKIS','manual'"), 'cleared operational exit unlinks event'=>str_contains($j,'if($exit===null)$eventId=null'),
'closed historical clear is unresolved'=>str_contains($j,"'legacy_unresolved'"), 'open lock ignores void'=>str_contains($g,'pdks_gunluk_faz8j_etkin_kosul($pdo, \'p\')'),
'exit matcher ignores void'=>str_contains($g,"status = 'open' AND \" . pdks_gunluk_faz8j_etkin_kosul"), 'summary ignores void'=>str_contains($g,'$etkin = pdks_gunluk_faz8j_etkin_kosul'),
'female male counters shared filter'=>str_contains($g,'worker_type_name_snapshot AS tip, COUNT(*)'), 'first last shared filter'=>str_contains($g,'MIN(entry_time) AS ilk_giris'),
'missing list filter'=>str_contains($g,'pdks_gunluk_faz8j_etkin_kosul')&&str_contains($g,"p.status IN ('open','legacy_unresolved')"), 'Faz8B queue ignores void'=>str_contains($b,'pdks_gunluk_faz8j_etkin_kosul'),
'Faz8B individual evaluation ignores void'=>str_contains($b,'WHERE id = ? AND '), 'approval reset'=>str_contains($j,'approved_attendance_class=NULL'),
'FM reset'=>str_contains($j,'overtime_approved=NULL'), 'Faz8B engine retained'=>str_contains($b,'pdks_faz8b_sure_karari'),
'draft marked stale'=>str_contains($j,'needs_recalculation=1'), 'final edit blocked'=>str_contains($j,"==='final'"), 'final void blocked'=>substr_count($j,"==='final'")>=2,
'void metadata'=>str_contains($j,"'is_voided'")&&str_contains($j,"'void_reason'"), 'void reason required'=>str_contains($j,"İptal nedeni zorunludur"),
'void no period delete'=>!preg_match('/DELETE\s+FROM\s+daily_worker_work_periods/i',$j), 'void audit'=>str_contains($j,"'puantaj_iptal'"),
'edit audit'=>str_contains($j,"'puantaj_duzeltme'"), 'audit in transaction'=>str_contains($j,'pdks_faz8j_audit'),
'report KPI filter'=>str_contains($r,'pdks_gunluk_faz8j_etkin_kosul'), 'report trend filter'=>str_contains($r,'$whereP = [\'p.work_date_snapshot BETWEEN ? AND ?\','),
'report foreman filter'=>substr_count($r,'pdks_gunluk_faz8j_etkin_kosul')>=5, 'print data filter'=>str_contains($r,'WHERE p.session_id = ? AND '),
'hakediş guard filter'=>str_contains($h,'pdks_gunluk_faz8j_etkin_kosul'), 'manual exit UI filter'=>str_contains($read('manuel_cikis.php'),'pdks_gunluk_faz8j_etkin_kosul'),
'evaluation UI filter'=>str_contains($read('mesai_degerlendirme.php'),'pdks_gunluk_faz8j_etkin_kosul'), 'admin void history'=>str_contains($d,'İptal Edilen Kayıtlar'),
'void history fields'=>str_contains($d,'void_reason')&&str_contains($d,'voided_at'), 'desktop controls'=>str_contains($d,'Kaydı İptal Et'),
'mobile controls'=>substr_count($d,'Kaydı İptal Et')>=2, 'pink Kadın class'=>str_contains($scan,'pdks-kiosk-typebtn-kadin'),
'Erkek not pink'=>str_contains($scan, '$t[\'code\'] === \'KADIN\''), 'pink scoped css'=>str_contains($read('assets/pdks.css'),'.pdks-kiosk-typebtn-kadin'),
'scan JS unchanged'=>!str_contains($scan,'new NDEFReader'), 'payment/cari untouched'=>trim((string)shell_exec('cd '.escapeshellarg($root).' && git diff --stat -- config/pdks_cari.php'))===''
]; foreach($checks as $n=>$v)ok8j($n,$v); printf("\n=== SONUÇ: %d geçti, %d hata ===\n",$pass,$fail); exit($fail?1:0);
