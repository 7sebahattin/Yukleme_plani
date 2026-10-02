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
        try { $pdo->exec("ALTER TABLE `daily_worker_work_periods` ADD COLUMN `$c` $sql"); pdks_gunluk_kolon_onbellek_temizle($pdo, 'daily_worker_work_periods'); $r[] = ['adim'=>$c,'durum'=>'eklendi','mesaj'=>'Kolon eklendi.']; }
        catch (Throwable $e) { error_log('[pdks_faz8j_migrate] '.$c.': '.$e->getMessage()); $r[] = ['adim'=>$c,'durum'=>'hata','mesaj'=>'Kolon eklenemedi.']; }
    }
    return $r;
}
function pdks_faz8j_yetki(): ?string { return (!function_exists('is_admin') || !is_admin()) ? 'Bu işlem yalnızca sistem yöneticileri içindir.' : null; }
/**
 * Shared endpoints never trust a depot supplied only by a page.
 *
 * ⚠ Faz 9A: pdks_gunluk_depo_kontrol() (config/pdks_gunluk.php) TEK ve
 * PAYLAŞILAN kapıya taşındı (bkz. o fonksiyonun docblock'u — M-01 düzeltmesi,
 * diğer modüllerin de kullanabilmesi için). Bu sarmalayıcı YALNIZ Faz 8J'nin
 * KENDİ mesajını ve KENDİ (istisnai) boş-depo-de-reddet davranışını korur —
 * Faz 8J bir düzeltme/iptal işlemidir ve boş `$depo` HER ZAMAN reddedilir
 * (çağıranlar `active_depot()`'u ZATEN dolduruyor olmalı), genel yardımcının
 * "atanmamış veri her depoda erişilebilir" kuralı burada UYGULANMAZ.
 */
function pdks_faz8j_aktif_depo_kontrol(string $depo): ?string {
    if (!function_exists('active_depot')) return null;
    $aktif = trim((string)(active_depot() ?? ''));
    if ($aktif === '' || trim($depo) === '') return 'Mesai dönemi aktif depoya ait değil.';
    return pdks_gunluk_depo_kontrol($depo, $aktif) !== null ? 'Mesai dönemi aktif depoya ait değil.' : null;
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
/**
 * Only the actively-supported daily-worker scan types may be stored as a
 * correction snapshot.
 *
 * ⚠ Faz 9B / H-01 kapanışı: `code IN ('KADIN','ERKEK')` BURADA artık
 * TEKRARLANMAZ — config/pdks_gunluk.php'deki TEK paylaşılan politikayı
 * (pdks_gunluk_desteklenen_tip_coz()) SARAR, tıpkı pdks_faz8j_aktif_depo_kontrol()'ün
 * Faz 9A'da pdks_gunluk_depo_kontrol()'ü SARDIĞI desenin aynısı. Düzeltme
 * açılır listesi (gunluk_isci_puantaj_detay.php → pdks_gunluk_desteklenen_tip_listele())
 * ile BU fonksiyonun kabul ettiği küme HER ZAMAN AYNIDIR — UI'nin sunduğu
 * bir tip backend'de asla reddedilmez.
 */
function pdks_faz8j_desteklenen_tip(PDO $pdo, int $typeId): ?array {
    return function_exists('pdks_gunluk_desteklenen_tip_coz') ? pdks_gunluk_desteklenen_tip_coz($typeId, $pdo) : null;
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
        // Faz 9C / H-02: overtime_approved_hours (OTORİTER onaylanan FM saati)
        // AYNI satırda, eski overtime_approved bayrağıyla BİRLİKTE sıfırlanır —
        // iptal edilen bir dönemde ESKİ bir onaylı saat değeri ASLA asılı kalmaz.
        $fmSaatSifirla = pdks_gunluk_kolon_var($pdo, 'daily_worker_work_periods', 'overtime_approved_hours') ? ',overtime_approved_hours=NULL' : '';
        $pdo->prepare('UPDATE daily_worker_work_periods SET is_voided=1,voided_at=?,voided_by_user_id=?,void_reason=?,approved_attendance_class=NULL,approved_by_user_id=NULL,approved_at=NULL,overtime_approved=NULL' . $fmSaatSifirla . ',overtime_approved_by_user_id=NULL,overtime_approved_at=NULL WHERE id=?')->execute([$now,$user,$reason,$periodId]);
        $pdo->prepare("UPDATE foreman_daily_entitlements SET needs_recalculation=1 WHERE session_id=? AND status='draft'")->execute([$sessionId]);
        pdks_faz8b_cavus_ucret_kardes_isaretle($sessionId, $pdo);   // Çavuş Ücreti (Faz 8B eki): kardeş oturum
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
        // Faz 9B / görev talimatı §7: ayrı, AÇIK mesajlar — "kart yok" ile
        // "tip artık desteklenmiyor" (ör. tarihsel/başka kurulumdan gelen bir
        // satır) FARKLI durumlardır ve SESSİZCE aynı jenerik hataya
        // düşürülmemeli; hiçbiri SESSİZCE farklı bir tipe DÖNÜŞTÜRÜLMEZ.
        if(!$cardNo) throw new RuntimeException('Seçilen kart bulunamadı.');
        if(!$typeName) throw new RuntimeException('Seçilen işçi tipi artık desteklenmiyor veya pasif — bu dönem yalnız KADIN/ERKEK\'e yeniden atanarak düzeltilebilir.');
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
        // Faz 9C / H-02: entry/exit veya işçi tipi DEĞİŞTİĞİNDE geçen süre
        // (ve dolayısıyla Tam/FM adayı) DEĞİŞMİŞ olabilir — overtime_approved_hours
        // eski overtime_approved bayrağıyla BİRLİKTE sıfırlanır, eski bir
        // onaylı FM saati YENİ süreye SESSİZCE taşınmaz.
        $fmSaatSifirla = pdks_gunluk_kolon_var($pdo, 'daily_worker_work_periods', 'overtime_approved_hours') ? ',overtime_approved_hours=NULL' : '';
        $pdo->prepare('UPDATE daily_worker_work_periods SET worker_card_id=?,worker_type_id_snapshot=?,worker_type_name_snapshot=?,entry_time=?,exit_time=?,status=?,exit_event_id=?,approved_attendance_class=NULL,approved_by_user_id=NULL,approved_at=NULL,overtime_approved=NULL' . $fmSaatSifirla . ',overtime_approved_by_user_id=NULL,overtime_approved_at=NULL WHERE id=?')->execute([$card,$type,$typeName,$entry,$exit,$status,$eventId,$pid]);
        $pdo->prepare("UPDATE foreman_daily_entitlements SET needs_recalculation=1 WHERE session_id=? AND status='draft'")->execute([$sid]);
        pdks_faz8b_cavus_ucret_kardes_isaretle($sid, $pdo);   // Çavuş Ücreti (Faz 8B eki): kardeş oturum
        pdks_faz8j_audit($pdo,$user,'puantaj_duzeltme',$pid,$old,['session_id'=>$sid,'period_id'=>$pid,'worker_card_id'=>$card,'card_no'=>$cardNo,'worker_type_id_snapshot'=>$type,'worker_type_name_snapshot'=>$typeName,'entry_time'=>$entry,'exit_time'=>$exit,'status'=>$status,'reason'=>$reason,'note'=>$note,'corrected_at'=>date('Y-m-d H:i:s'),'user_id'=>$user]);
        $pdo->commit(); return ['ok'=>true];
    } catch(Throwable $x) { if($pdo->inTransaction())$pdo->rollBack(); return ['ok'=>false,'hata'=>$x->getMessage()]; }
}

// =========================================================
// v291 — GEÇMİŞE DÖNÜK ÇALIŞMA EKLE (yalnız yönetici)
// =========================================================
// Giriş yapmayı unutan personelin kaydı SONRADAN eklenir; rapor/puantaj/hakediş
// bu kaydı normal çalışma gibi sayar (hepsi daily_worker_work_periods'tan okur).
// Ham olay geçmişi korunur: GİRİŞ ve ÇIKIŞ birer `manual` olay olarak yazılır,
// dönem `source='manual'` ile işaretlenir (detay ekranında "✍ Elle eklendi").
// Kart okutma yazma fonksiyonlarına (faz8a giris/cikis_kaydet) DOKUNMAZ.
//
// Kurallar pdks_faz8j_duzelt() ile AYNI: yalnız yönetici, aktif depo, kesinleşmiş
// hakediş varsa reddedilir, giriş mesai günüyle aynı gün, çıkış girişten sonra ve
// en çok 24 saat içinde, gelecekte değil, aynı kartın çakışan aktif dönemi yok.
// Çavuşun o gün/depo mesaisi yoksa GEÇMİŞ bir gün için KAPALI mesai oluşturulur
// (bugün için oluşturulmaz — bugünün mesaisi kart okutarak açılır).

/** Bu tarihte (iptal edilmemiş) çalışma dönemi OLMAYAN, kullanılabilir kartlar. */
function pdks_faz8j_bos_kartlar(string $workDate, ?PDO $pdo = null): array {
    $pdo = $pdo ?? db();
    $st = $pdo->prepare("SELECT id, card_no FROM worker_cards WHERE status <> 'disabled'
        AND id NOT IN (SELECT worker_card_id FROM daily_worker_work_periods WHERE work_date_snapshot = ? AND " . pdks_gunluk_faz8j_etkin_kosul($pdo) . ")
        ORDER BY card_no");
    $st->execute([$workDate]);
    return $st->fetchAll();
}

/** @return array{ok:bool, hata?:string, session_id?:int, period_id?:int, yeni_mesai?:bool} */
function pdks_faz8j_gecmis_ekle(array $v, int $user, ?PDO $pdo = null): array {
    $pdo = $pdo ?? db();
    if ($e = pdks_faz8j_yetki()) return ['ok' => false, 'hata' => $e];
    $depo = trim((string)($v['depo'] ?? ''));
    if ($e = pdks_faz8j_aktif_depo_kontrol($depo)) return ['ok' => false, 'hata' => $e];
    if (!pdks_faz8j_sema_hazir($pdo) || !pdks_gunluk_faz8a_sema_hazir($pdo)) {
        return ['ok' => false, 'hata' => 'Puantaj düzeltme şeması henüz hazır değil.'];
    }
    $foremanId = (int)($v['foreman_id'] ?? 0);
    $workDate  = trim((string)($v['work_date'] ?? ''));
    $card      = (int)($v['worker_card_id'] ?? 0);
    $type      = (int)($v['worker_type_id'] ?? 0);
    $reason    = trim((string)($v['reason'] ?? ''));
    $note      = trim((string)($v['note'] ?? ''));
    $entry     = pdks_faz8j_zaman((string)($v['entry_date'] ?? ''), (string)($v['entry_clock'] ?? ''));
    $cikis     = pdks_faz8j_cikis_zamani($v);
    if (!$cikis['ok']) return ['ok' => false, 'hata' => $cikis['hata']];
    $exit = $cikis['exit'];
    if ($foremanId < 1 || $card < 1 || $type < 1 || !$entry || $reason === '' || mb_strlen($reason) > 500 || mb_strlen($note) > 1000
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $workDate) || !strtotime($workDate)) {
        return ['ok' => false, 'hata' => 'Alanları kontrol edin: çavuş, tarih, kart, işçi tipi, giriş saati ve sebep zorunludur.'];
    }
    if ($exit === null) return ['ok' => false, 'hata' => 'Geçmişe dönük eklemede çıkış tarihi ve saati zorunludur.'];
    $simdi = date('Y-m-d H:i:s');
    if ($workDate > date('Y-m-d')) return ['ok' => false, 'hata' => 'Mesai tarihi gelecekte olamaz.'];
    if (substr($entry, 0, 10) !== $workDate || $entry > $simdi || $exit <= $entry
        || strtotime($exit) > strtotime($entry) + 86400 || $exit > $simdi) {
        return ['ok' => false, 'hata' => 'Giriş/çıkış zamanı mesai tarihi, 24 saat ve gelecek kurallarına uymuyor.'];
    }

    try {
        $pdo->beginTransaction();
        $c = $pdo->prepare('SELECT id, card_no, canonical_uid FROM worker_cards WHERE id = ?'); $c->execute([$card]);
        $kart = $c->fetch();
        if (!$kart) throw new RuntimeException('Seçilen kart bulunamadı.');
        $tip = pdks_faz8j_desteklenen_tip($pdo, $type);
        if (!$tip) throw new RuntimeException('Seçilen işçi tipi bulunamadı, pasif veya günlük işçi girişinde desteklenmiyor.');
        $typeName = (string)$tip['name'];

        // Çavuşun o gün/depo mesaisi: varsa kullan, yoksa (yalnız GEÇMİŞ gün) kapalı mesai oluştur.
        $stF = $pdo->prepare('SELECT * FROM daily_work_sessions WHERE foreman_id = ? AND work_date = ? AND depo = ?');
        $stF->execute([$foremanId, $workDate, $depo]);
        $oturum = $stF->fetch();
        $yeni = false;
        if (!$oturum) {
            if ($workDate >= date('Y-m-d')) {
                throw new RuntimeException('Bugün için mesai yok: kartı okutarak mesaiyi açın, sonra saatleri düzeltin.');
            }
            $stC = $pdo->prepare('SELECT id, name, code FROM foremen WHERE id = ?'); $stC->execute([$foremanId]);
            $cavus = $stC->fetch();
            if (!$cavus) throw new RuntimeException('Çavuş bulunamadı.');
            $normalDk = 540;
            if (pdks_gunluk_kolon_var($pdo, 'foremen', 'normal_work_minutes')) {
                $sn = $pdo->prepare('SELECT normal_work_minutes FROM foremen WHERE id = ?'); $sn->execute([$foremanId]);
                $nv = $sn->fetchColumn(); if ($nv !== false && $nv !== null) $normalDk = (int)$nv;
            }
            $kol = 'foreman_id, foreman_name_snapshot, foreman_code_snapshot, work_date, depo, status, opened_at, opened_by_user_id, closed_at, closed_by_user_id, notes';
            $par = [$foremanId, (string)$cavus['name'], (string)$cavus['code'], $workDate, $depo, 'closed', $entry, $user, $exit, $user, 'Geçmişe dönük elle oluşturuldu: ' . $reason];
            if (pdks_gunluk_kolon_var($pdo, 'daily_work_sessions', 'normal_work_minutes_snapshot')) {
                $kol .= ', normal_work_minutes_snapshot'; $par[] = $normalDk;
            }
            $pdo->prepare('INSERT INTO daily_work_sessions (' . $kol . ') VALUES (' . implode(',', array_fill(0, count($par), '?')) . ')')->execute($par);
            $sid = (int)$pdo->lastInsertId();
            $stF->execute([$foremanId, $workDate, $depo]); $oturum = $stF->fetch();
            $yeni = true;
            if (function_exists('audit_log_event')) {
                audit_log_event('create', 'daily_work_sessions', $sid, null, ['foreman_id' => $foremanId, 'work_date' => $workDate, 'depo' => $depo, 'kaynak' => 'gecmise_donuk_ekle']);
            }
        }
        $sid = (int)$oturum['id'];
        if (pdks_faz8j_entitlement($pdo, $sid) === 'final') {
            throw new RuntimeException('Bu mesainin kesinleşmiş hakedişi bulunmaktadır. Önce hakedişi yönetici tarafından yeniden açın.');
        }

        pdks_gunluk_faz8a_kart_kilitle($pdo, $card);
        $ov = $pdo->prepare("SELECT id FROM daily_worker_work_periods WHERE worker_card_id = ? AND is_voided = 0 AND entry_time < ? AND COALESCE(exit_time,'9999-12-31 23:59:59') > ? LIMIT 1");
        $ov->execute([$card, $exit, $entry]);
        if ($ov->fetchColumn()) throw new RuntimeException('Seçilen kartın bu saatlerle çakışan aktif bir çalışma dönemi var.');

        $insE = $pdo->prepare("INSERT INTO daily_worker_card_events (session_id, worker_card_id, event_type, source, canonical_uid_snapshot, worker_type_id_snapshot, worker_type_name_snapshot, work_date_snapshot, depo_snapshot, recorded_by_user_id, server_event_time) VALUES (?,?,?,'manual',?,?,?,?,?,?,?)");
        $evPar = fn(string $tur, string $zaman) => [$sid, $card, $tur, (string)$kart['canonical_uid'], $type, $typeName, $workDate, $depo, $user, $zaman];
        $insE->execute($evPar('GIRIS', $entry)); $girisEv = (int)$pdo->lastInsertId();
        $insE->execute($evPar('CIKIS', $exit));  $cikisEv = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO daily_worker_work_periods (session_id, worker_card_id, worker_type_id_snapshot, worker_type_name_snapshot, entry_event_id, exit_event_id, entry_time, exit_time, declared_attendance_class, work_date_snapshot, depo_snapshot, status, source) VALUES (?,?,?,?,?,?,?,?,'auto',?,?,'closed','manual')")
            ->execute([$sid, $card, $type, $typeName, $girisEv, $cikisEv, $entry, $exit, $workDate, $depo]);
        $pid = (int)$pdo->lastInsertId();

        $pdo->prepare("UPDATE foreman_daily_entitlements SET needs_recalculation = 1 WHERE session_id = ? AND status = 'draft'")->execute([$sid]);
        pdks_faz8b_cavus_ucret_kardes_isaretle($sid, $pdo);
        pdks_faz8j_audit($pdo, $user, 'puantaj_ekle', $pid, [], [
            'session_id' => $sid, 'period_id' => $pid, 'worker_card_id' => $card, 'card_no' => (string)$kart['card_no'],
            'worker_type_id_snapshot' => $type, 'worker_type_name_snapshot' => $typeName,
            'entry_time' => $entry, 'exit_time' => $exit, 'work_date' => $workDate, 'yeni_mesai' => $yeni,
            'reason' => $reason, 'note' => $note, 'added_at' => $simdi, 'user_id' => $user,
        ]);
        $pdo->commit();
        return ['ok' => true, 'session_id' => $sid, 'period_id' => $pid, 'yeni_mesai' => $yeni];
    } catch (Throwable $x) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'hata' => $x instanceof RuntimeException ? $x->getMessage() : 'Kayıt sırasında teknik bir hata oluştu. Lütfen tekrar deneyin.'];
    }
}
