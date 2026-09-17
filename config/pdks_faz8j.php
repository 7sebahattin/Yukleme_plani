<?php
// Faz 8J — ham okutma olaylarına dokunmadan operasyonel dönem düzeltmesi.
declare(strict_types=1);
require_once __DIR__ . '/pdks_gunluk.php';
require_once __DIR__ . '/pdks_faz8e.php';

function pdks_faz8j_sema_hazir(?PDO $pdo = null): bool {
    $pdo = $pdo ?? db();
    return pdks_gunluk_tablo_var($pdo, 'daily_worker_work_periods')
        && pdks_gunluk_faz8j_kolon_var($pdo, 'daily_worker_work_periods', 'is_voided')
        && pdks_gunluk_faz8j_kolon_var($pdo, 'daily_worker_work_periods', 'voided_at')
        && pdks_gunluk_faz8j_kolon_var($pdo, 'daily_worker_work_periods', 'voided_by_user_id')
        && pdks_gunluk_faz8j_kolon_var($pdo, 'daily_worker_work_periods', 'void_reason');
}
function pdks_faz8j_migrate(?PDO $pdo = null): array {
    $pdo = $pdo ?? db(); $r = [];
    foreach ([
        ['is_voided', 'TINYINT(1) NOT NULL DEFAULT 0'], ['voided_at', 'DATETIME NULL DEFAULT NULL'],
        ['voided_by_user_id', 'INT NULL DEFAULT NULL'], ['void_reason', 'VARCHAR(500) NULL DEFAULT NULL'],
    ] as [$c, $sql]) {
        if (pdks_gunluk_faz8j_kolon_var($pdo, 'daily_worker_work_periods', $c)) { $r[] = ['adim'=>$c,'durum'=>'var','mesaj'=>'Kolon zaten var.']; continue; }
        try { $pdo->exec("ALTER TABLE `daily_worker_work_periods` ADD COLUMN `$c` $sql"); $r[] = ['adim'=>$c,'durum'=>'eklendi','mesaj'=>'Kolon eklendi.']; }
        catch (Throwable $e) { error_log('[pdks_faz8j_migrate] '.$c.': '.$e->getMessage()); $r[] = ['adim'=>$c,'durum'=>'hata','mesaj'=>'Kolon eklenemedi.']; }
    }
    return $r;
}
function pdks_faz8j_yetki(): ?string { return (!function_exists('is_admin') || !is_admin()) ? 'Bu işlem yalnızca sistem yöneticileri içindir.' : null; }
/** Shared endpoints never trust a depot supplied only by a page. */
function pdks_faz8j_aktif_depo_kontrol(string $depo): ?string {
    if (!function_exists('active_depot')) return null;
    $aktif = trim((string)(active_depot() ?? ''));
    return ($aktif === '' || trim($depo) === '' || $aktif !== $depo) ? 'Mesai dönemi aktif depoya ait değil.' : null;
}
function pdks_faz8j_entitlement(PDO $pdo, int $sid): ?string { $s=$pdo->prepare('SELECT status FROM foreman_daily_entitlements WHERE session_id=?'); $s->execute([$sid]); return $s->fetchColumn() ?: null; }
function pdks_faz8j_donem(PDO $pdo, int $periodId, int $sessionId, string $depo): ?array {
    $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $s=$pdo->prepare("SELECT p.*,s.work_date,s.depo,w.card_no FROM daily_worker_work_periods p JOIN daily_work_sessions s ON s.id=p.session_id JOIN worker_cards w ON w.id=p.worker_card_id WHERE p.id=? AND p.session_id=? AND s.depo=? AND p.depo_snapshot=?$lock");
    $s->execute([$periodId,$sessionId,$depo,$depo]); return $s->fetch() ?: null;
}
function pdks_faz8j_audit(PDO $pdo, int $user, string $action, int $id, array $old, array $new): void {
    $pdo->prepare('INSERT INTO audit_log (user_id,action,module,record_id,old_values,new_values,ip,user_agent) VALUES (?,?,?,?,?,?,?,?)')->execute([$user,$action,'daily_worker_work_periods',$id,json_encode($old,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($new,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$_SERVER['REMOTE_ADDR']??null,substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255)]);
}
function pdks_faz8j_zaman(string $date, string $time): ?string {
    $x=$date.' '.$time.':00'; $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$x);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$time) && $d && $d->format('Y-m-d H:i:s')===$x ? $x : null;
}
/** @return array{ok:bool,exit:?string,hata?:string} */
function pdks_faz8j_cikis_zamani(array $veri): array {
    $tarih=trim((string)($veri['exit_date']??'')); $saat=trim((string)($veri['exit_clock']??''));
    if ($tarih==='' && $saat==='') return ['ok'=>true,'exit'=>null];
    if ($tarih==='' || $saat==='') return ['ok'=>false,'exit'=>null,'hata'=>'Çıkış tarihi ve saati birlikte girilmelidir.'];
    $cikis=pdks_faz8j_zaman($tarih,$saat);
    return $cikis===null ? ['ok'=>false,'exit'=>null,'hata'=>'Geçerli bir çıkış tarihi ve saati girin.'] : ['ok'=>true,'exit'=>$cikis];
}
/** Only the active KADIN/ERKEK scan types may be stored as a correction snapshot. */
function pdks_faz8j_desteklenen_tip(PDO $pdo, int $typeId): ?array {
    $s=$pdo->prepare("SELECT id,name,code FROM worker_types WHERE id=? AND is_active=1 AND code IN ('KADIN','ERKEK')"); $s->execute([$typeId]); return $s->fetch() ?: null;
}
function pdks_faz8j_void(int $periodId, int $sessionId, string $depo, string $reason, int $user, ?PDO $pdo=null): array {
    $pdo=$pdo??db(); if($e=pdks_faz8j_yetki()) return ['ok'=>false,'hata'=>$e]; if($e=pdks_faz8j_aktif_depo_kontrol($depo)) return ['ok'=>false,'hata'=>$e];
    if(!pdks_faz8j_sema_hazir($pdo)) return ['ok'=>false,'hata'=>'Faz 8J şeması henüz hazır değil.']; $reason=trim($reason);
    if($reason===''||mb_strlen($reason)>500) return ['ok'=>false,'hata'=>'İptal nedeni zorunludur ve en fazla 500 karakter olabilir.'];
    try {
        $pdo->beginTransaction(); $p=pdks_faz8j_donem($pdo,$periodId,$sessionId,$depo);
        if(!$p) throw new RuntimeException('Mesai dönemi seçili oturumda veya depoda bulunamadı.'); if((int)$p['is_voided']) throw new RuntimeException('Kayıt zaten iptal edilmiş.');
        if(pdks_faz8j_entitlement($pdo,$sessionId)==='final') throw new RuntimeException('Bu mesainin kesinleşmiş hakedişi bulunmaktadır. Önce hakedişi yönetici tarafından yeniden açın.');
        $now=date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE daily_worker_work_periods SET is_voided=1,voided_at=?,voided_by_user_id=?,void_reason=?,approved_attendance_class=NULL,approved_by_user_id=NULL,approved_at=NULL,overtime_approved=NULL,overtime_approved_by_user_id=NULL,overtime_approved_at=NULL WHERE id=?')->execute([$now,$user,$reason,$periodId]);
        $pdo->prepare("UPDATE foreman_daily_entitlements SET needs_recalculation=1 WHERE session_id=? AND status='draft'")->execute([$sessionId]);
        pdks_faz8j_audit($pdo,$user,'puantaj_iptal',$periodId,$p,['session_id'=>$sessionId,'period_id'=>$periodId,'reason'=>$reason,'voided_at'=>$now]);
        $pdo->commit(); return ['ok'=>true];
    } catch(Throwable $x) { if($pdo->inTransaction())$pdo->rollBack(); return ['ok'=>false,'hata'=>$x->getMessage()]; }
}
function pdks_faz8j_duzelt(array $v, int $user, ?PDO $pdo=null): array {
    $pdo=$pdo??db(); if($e=pdks_faz8j_yetki()) return ['ok'=>false,'hata'=>$e];
    $pid=(int)($v['period_id']??0); $sid=(int)($v['session_id']??0); $depo=(string)($v['depo']??''); $reason=trim((string)($v['reason']??'')); $note=trim((string)($v['note']??''));
    $card=(int)($v['worker_card_id']??0); $type=(int)($v['worker_type_id']??0); $entry=pdks_faz8j_zaman((string)($v['entry_date']??''),(string)($v['entry_clock']??'')); $cikisSonuc=pdks_faz8j_cikis_zamani($v);
    if(!$cikisSonuc['ok']) return ['ok'=>false,'hata'=>$cikisSonuc['hata']]; $exit=$cikisSonuc['exit'];
    if($e=pdks_faz8j_aktif_depo_kontrol($depo)) return ['ok'=>false,'hata'=>$e]; if(!pdks_faz8j_sema_hazir($pdo)) return ['ok'=>false,'hata'=>'Faz 8J şeması henüz hazır değil.'];
    if(!$pid||!$sid||!$card||!$type||!$entry||$reason===''||mb_strlen($reason)>500||mb_strlen($note)>1000) return ['ok'=>false,'hata'=>'Düzeltme alanlarını kontrol edin.'];
    try {
        $pdo->beginTransaction(); $p=pdks_faz8j_donem($pdo,$pid,$sid,$depo);
        if(!$p||(int)$p['is_voided']) throw new RuntimeException('Mesai dönemi bulunamadı veya iptal edilmiş.');
        if(pdks_faz8j_entitlement($pdo,$sid)==='final') throw new RuntimeException('Bu mesainin kesinleşmiş hakedişi bulunmaktadır. Önce hakedişi yönetici tarafından yeniden açın.');
        if(substr($entry,0,10)!==(string)$p['work_date']||$entry>date('Y-m-d H:i:s')||($exit!==null&&($exit<$entry||strtotime($exit)>strtotime($entry)+86400||$exit>date('Y-m-d H:i:s')))) throw new RuntimeException('Giriş/çıkış zamanı mesai tarihi, 24 saat ve gelecek kurallarına uymuyor.');
        $c=$pdo->prepare('SELECT card_no FROM worker_cards WHERE id=?'); $c->execute([$card]); $cardNo=$c->fetchColumn(); $tip=pdks_faz8j_desteklenen_tip($pdo,$type); $typeName=$tip['name']??null;
        if(!$cardNo||!$typeName) throw new RuntimeException('Kart veya desteklenen aktif işçi tipi bulunamadı.');
        $ov=$pdo->prepare("SELECT id FROM daily_worker_work_periods WHERE worker_card_id=? AND id<>? AND is_voided=0 AND entry_time < COALESCE(?, '9999-12-31 23:59:59') AND COALESCE(exit_time,'9999-12-31 23:59:59') > ? LIMIT 1");
        $ov->execute([$card,$pid,$exit,$entry]); if($ov->fetchColumn()) throw new RuntimeException('Seçilen kartın çakışan aktif bir çalışma dönemi var.');
        $eventId=$p['exit_event_id'];
        if($p['exit_time']===null && $exit!==null) {
            $manualPeriod=$p; $manualPeriod['worker_card_id']=$card; $manualPeriod['worker_type_id_snapshot']=$type; $manualPeriod['worker_type_name_snapshot']=$typeName;
            $eventId=pdks_faz8e_manuel_cikis_olayi_ekle($pdo,$manualPeriod,$card,$depo,$exit,$user);
        }
        if($exit===null) $eventId=null;
        $status=$exit!==null?'closed':((string)$p['status']==='open'&&$p['work_date']===date('Y-m-d')?'open':'legacy_unresolved');
        $old=['worker_card_id'=>$p['worker_card_id'],'card_no'=>$p['card_no'],'worker_type_id_snapshot'=>$p['worker_type_id_snapshot'],'worker_type_name_snapshot'=>$p['worker_type_name_snapshot'],'entry_time'=>$p['entry_time'],'exit_time'=>$p['exit_time'],'status'=>$p['status']];
        $pdo->prepare('UPDATE daily_worker_work_periods SET worker_card_id=?,worker_type_id_snapshot=?,worker_type_name_snapshot=?,entry_time=?,exit_time=?,status=?,exit_event_id=?,approved_attendance_class=NULL,approved_by_user_id=NULL,approved_at=NULL,overtime_approved=NULL,overtime_approved_by_user_id=NULL,overtime_approved_at=NULL WHERE id=?')->execute([$card,$type,$typeName,$entry,$exit,$status,$eventId,$pid]);
        $pdo->prepare("UPDATE foreman_daily_entitlements SET needs_recalculation=1 WHERE session_id=? AND status='draft'")->execute([$sid]);
        pdks_faz8j_audit($pdo,$user,'puantaj_duzeltme',$pid,$old,['session_id'=>$sid,'period_id'=>$pid,'worker_card_id'=>$card,'card_no'=>$cardNo,'worker_type_id_snapshot'=>$type,'worker_type_name_snapshot'=>$typeName,'entry_time'=>$entry,'exit_time'=>$exit,'status'=>$status,'reason'=>$reason,'note'=>$note,'corrected_at'=>date('Y-m-d H:i:s'),'user_id'=>$user]);
        $pdo->commit(); return ['ok'=>true];
    } catch(Throwable $x) { if($pdo->inTransaction())$pdo->rollBack(); return ['ok'=>false,'hata'=>$x->getMessage()]; }
}
