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
function pdks_faz8j_audit(PDO $pdo, int $user, string $action, int $id, array $old, array $new, string $module = 'daily_worker_work_periods'): void {
    $pdo->prepare('INSERT INTO audit_log (user_id,action,module,record_id,old_values,new_values,ip,user_agent) VALUES (?,?,?,?,?,?,?,?)')->execute([$user,$action,$module,$id,json_encode($old,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($new,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$_SERVER['REMOTE_ADDR']??null,substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255)]);
}
function pdks_faz8j_zaman(string $date, string $time): ?string {
    $x=$date.' '.$time.':00'; $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$x);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$time) && $d && $d->format('Y-m-d H:i:s')===$x ? $x : null;
}
/** @return array{ok:bool,exit:?string,hata?:string} */
/** Girdi alanını güvenle metne çevirir: dizi/nesne → '' (uyarı üretmez). */
function pdks_faz8j_metin($x): string { return is_scalar($x) ? trim((string)$x) : ''; }
function pdks_faz8j_cikis_zamani(array $veri): array {
    $tarih=pdks_faz8j_metin($veri['exit_date']??''); $saat=pdks_faz8j_metin($veri['exit_clock']??'');
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
/**
 * İptalin YAZMA çekirdeği — transaction AÇMAZ, yetki/depo kontrolü YAPMAZ.
 * Çağıran (pdks_faz8j_void / pdks_faz8j_toplu_geri_al) kendi transaction'ı
 * içinde, dönemi pdks_faz8j_donem() ile kilitledikten SONRA çağırır.
 * $ek: audit'e eklenecek ek alanlar (ör. toplu_id).
 */
function pdks_faz8j_void_uygula(PDO $pdo, array $p, int $periodId, int $sessionId, string $reason, int $user, array $ek = []): void {
    $now=date('Y-m-d H:i:s');
    // Faz 9C / H-02: overtime_approved_hours (OTORİTER onaylanan FM saati)
    // AYNI satırda, eski overtime_approved bayrağıyla BİRLİKTE sıfırlanır —
    // iptal edilen bir dönemde ESKİ bir onaylı saat değeri ASLA asılı kalmaz.
    $fmSaatSifirla = pdks_gunluk_kolon_var($pdo, 'daily_worker_work_periods', 'overtime_approved_hours') ? ',overtime_approved_hours=NULL' : '';
    $pdo->prepare('UPDATE daily_worker_work_periods SET is_voided=1,voided_at=?,voided_by_user_id=?,void_reason=?,approved_attendance_class=NULL,approved_by_user_id=NULL,approved_at=NULL,overtime_approved=NULL' . $fmSaatSifirla . ',overtime_approved_by_user_id=NULL,overtime_approved_at=NULL WHERE id=?')->execute([$now,$user,$reason,$periodId]);
    pdks_faz8j_yeniden_hesap_isaretle($pdo, $sessionId);
    pdks_faz8j_audit($pdo,$user,'puantaj_iptal',$periodId,$p,['session_id'=>$sessionId,'period_id'=>$periodId,'reason'=>$reason,'voided_at'=>$now] + $ek);
}
/** Taslak hakedişe "yeniden hesapla" + Çavuş Ücreti (Faz 8B eki) kardeş oturum işareti. */
function pdks_faz8j_yeniden_hesap_isaretle(PDO $pdo, int $sessionId): void {
    $pdo->prepare("UPDATE foreman_daily_entitlements SET needs_recalculation=1 WHERE session_id=? AND status='draft'")->execute([$sessionId]);
    pdks_faz8b_cavus_ucret_kardes_isaretle($sessionId, $pdo);
}
function pdks_faz8j_void(int $periodId, int $sessionId, string $depo, string $reason, int $user, ?PDO $pdo=null): array {
    $pdo=$pdo??db(); if($e=pdks_faz8j_yetki()) return ['ok'=>false,'hata'=>$e]; if($e=pdks_faz8j_aktif_depo_kontrol($depo)) return ['ok'=>false,'hata'=>$e];
    if(!pdks_faz8j_sema_hazir($pdo)) return ['ok'=>false,'hata'=>'Faz 8J şeması henüz hazır değil.']; $reason=trim($reason);
    if($reason===''||mb_strlen($reason)>500) return ['ok'=>false,'hata'=>'İptal nedeni zorunludur ve en fazla 500 karakter olabilir.'];
    try {
        $pdo->beginTransaction(); $p=pdks_faz8j_donem($pdo,$periodId,$sessionId,$depo);
        if(!$p) throw new RuntimeException('Mesai dönemi seçili oturumda veya depoda bulunamadı.'); if((int)$p['is_voided']) throw new RuntimeException('Kayıt zaten iptal edilmiş.');
        if(pdks_faz8j_entitlement($pdo,$sessionId)==='final') throw new RuntimeException('Bu mesainin kesinleşmiş hakedişi bulunmaktadır. Önce hakedişi yönetici tarafından yeniden açın.');
        pdks_faz8j_void_uygula($pdo,$p,$periodId,$sessionId,$reason,$user);
        $pdo->commit(); return ['ok'=>true];
    } catch(Throwable $x) { if($pdo->inTransaction())$pdo->rollBack(); return ['ok'=>false,'hata'=>$x->getMessage()]; }
}
/**
 * Düzeltmenin SATIR çekirdeği (v302) — tekil pdks_faz8j_duzelt() ve toplu
 * pdks_faz8j_toplu_duzelt() İKİSİ DE bunu çağırır; dönem UPDATE'inin TEK yeri.
 * Transaction AÇMAZ, yetki/depo/kesin hakediş kontrolü YAPMAZ: çağıran kendi
 * transaction'ında dönemi pdks_faz8j_donem() ile kilitleyip $p olarak verir.
 * Kurallar (zaman, kart, kartsız, tip, çakışma) → RuntimeException; yazma
 * kuralların HEPSİ geçtikten sonra başlar. $auditEk: satır audit'ine ek alanlar (toplu_id).
 */
function pdks_faz8j_duzelt_satir(PDO $pdo, array $p, int $sid, string $depo, int $card, int $type, string $entry, ?string $exit, string $reason, string $note, int $user, array $auditEk = []): void {
    $pid=(int)$p['id'];
    if(substr($entry,0,10)!==(string)$p['work_date']||$entry>date('Y-m-d H:i:s')||($exit!==null&&($exit<$entry||strtotime($exit)>strtotime($entry)+86400||$exit>date('Y-m-d H:i:s')))) throw new RuntimeException('Giriş/çıkış zamanı mesai tarihi, 24 saat ve gelecek kurallarına uymuyor.');
    $c=$pdo->prepare('SELECT card_no FROM worker_cards WHERE id=?'); $c->execute([$card]); $cardNo=$c->fetchColumn(); $tip=pdks_faz8j_desteklenen_tip($pdo,$type); $typeName=$tip['name']??null;
    // Faz 9B / görev talimatı §7: ayrı, AÇIK mesajlar — "kart yok" ile
    // "tip artık desteklenmiyor" (ör. tarihsel/başka kurulumdan gelen bir
    // satır) FARKLI durumlardır ve SESSİZCE aynı jenerik hataya
    // düşürülmemeli; hiçbiri SESSİZCE farklı bir tipe DÖNÜŞTÜRÜLMEZ.
    if(!$cardNo) throw new RuntimeException('Seçilen kart bulunamadı.');
    // Kartsız mesai: sanal kart YALNIZ kendi dönemine aittir — dönem başka
    // karta taşınamaz, sanal kart başka döneme verilemez, çıkışı silinemez.
    if($card!==(int)$p['worker_card_id']) {
        if(pdks_faz8j_kart_kartsiz_mi_id($pdo,(int)$p['worker_card_id'])) throw new RuntimeException('Kartsız mesai kaydı başka bir karta taşınamaz.');
        if(pdks_faz8j_kart_kartsiz_mi_id($pdo,$card)) throw new RuntimeException('Kartsız mesainin sanal kartı başka bir kayda bağlanamaz.');
        // v303: yeni kart başka çavuşa / depoya TANIMLIYSA taşıma reddedilir (kart kilidi altında okunur).
        pdks_gunluk_faz8a_kart_kilitle($pdo,$card);
        $so=$pdo->prepare('SELECT foreman_id, depo FROM daily_work_sessions WHERE id=?'); $so->execute([$sid]); $sr=$so->fetch();
        if(!$sr) throw new RuntimeException('Mesai bulunamadı.');
        if($e=pdks_faz8j_tanim_kart_engeli($pdo,$card,(int)$sr['foreman_id'],(string)$sr['depo'])) throw new RuntimeException($e);
    } elseif($exit===null && pdks_faz8j_kart_kartsiz_mi_id($pdo,$card)) throw new RuntimeException('Kartsız mesai kaydında çıkış zamanı zorunludur.');
    if(!$typeName) throw new RuntimeException('Seçilen işçi tipi artık desteklenmiyor veya pasif — bu dönem yalnız KADIN/ERKEK/RAMPACI\'ya yeniden atanarak düzeltilebilir.');
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
    pdks_faz8j_yeniden_hesap_isaretle($pdo, $sid);   // + Çavuş Ücreti (Faz 8B eki): kardeş oturum
    pdks_faz8j_audit($pdo,$user,'puantaj_duzeltme',$pid,$old,['session_id'=>$sid,'period_id'=>$pid,'worker_card_id'=>$card,'card_no'=>$cardNo,'worker_type_id_snapshot'=>$type,'worker_type_name_snapshot'=>$typeName,'entry_time'=>$entry,'exit_time'=>$exit,'status'=>$status,'reason'=>$reason,'note'=>$note,'corrected_at'=>date('Y-m-d H:i:s'),'user_id'=>$user] + $auditEk);
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
        pdks_faz8j_duzelt_satir($pdo,$p,$sid,$depo,$card,$type,$entry,$exit,$reason,$note,$user);
        $pdo->commit(); return ['ok'=>true];
    } catch(Throwable $x) { if($pdo->inTransaction())$pdo->rollBack(); return ['ok'=>false,'hata'=>$x->getMessage()]; }
}


// =========================================================
// v291 — GEÇMİŞE DÖNÜK ÇALIŞMA EKLE (yalnız yönetici)
// + KARTSIZ MESAİ, BUGÜN KURALLARI, TOPLU İŞLEM (Toplu İşlem eki)
// =========================================================
// Giriş yapmayı unutan personelin kaydı SONRADAN eklenir; rapor/puantaj/hakediş
// bu kaydı normal çalışma gibi sayar (hepsi daily_worker_work_periods'tan okur).
// Ham olay geçmişi korunur: GİRİŞ (ve varsa ÇIKIŞ) birer `manual` olay olarak
// yazılır, dönem `source='manual'` ile işaretlenir (detay ekranında "✍ Elle eklendi").
// Kart okutma yazma fonksiyonlarına (faz8a giris/cikis) DOKUNMAZ.
//
// TEK YAZMA YOLU: tekil ekleme (pdks_faz8j_gecmis_ekle) ve toplu ekleme
// (pdks_faz8j_toplu_ekle) AYNI çekirdekten geçer:
//   pdks_faz8j_oturum_coz()   → mesai çözümü (bugün: kiosk açılış yolu)
//   pdks_faz8j_satir_kontrol()→ satır kuralları (SALT OKUNUR — önizleme de kullanır)
//   pdks_faz8j_satir_yaz()    → olay + dönem INSERT + audit (TEK INSERT yeri)
//
// Mesai kuralları:
//   • Geçmiş gün: çavuşun o gün/depo mesaisi varsa (açık/kapalı) kullanılır; yoksa
//     KAPALI mesai oluşturulur. Her satırda çıkış zorunludur.
//   • Bugün: açık mesai varsa kullanılır; bugünkü mesai KAPATILMIŞSA reddedilir;
//     mesai yoksa kiosk yolu pdks_gunluk_oturum_ac_veya_getir() ile açılır
//     ("önceki gün açık mesai" kuralı dahil). Kartlı satır çıkışsız olabilir →
//     AÇIK dönem (kiosk girişi gibi; kiosk kart kuralları uygulanır).
//   • Kartsız satır HER ZAMAN kapalıdır (çıkış zorunlu).
// Kurallar: giriş günü = mesai günü, giriş ≤ şimdi, çıkış > giriş, ≤ 24 sa,
// çıkış ≤ şimdi, aynı kartın çakışan aktif dönemi yok, kesinleşmiş hakediş → ret.

const PDKS_FAZ8J_TOPLU_LIMIT = 250;
const PDKS_FAZ8J_KARTSIZ_KAYNAK = 'kartsiz';
const PDKS_FAZ8J_KARTSIZ_ONEK = 'KARTSIZ-';
const PDKS_FAZ8J_TEKRAR_HATA = 'Bu işlem zaten kaydedildi (tekrar gönderim).';
const PDKS_FAZ8J_ESZAMANLI_HATA = 'Bu çavuş için aynı anda başka bir işlem yapıldı; sayfayı yenileyip tekrar deneyin.';

/**
 * İdempotency (tekrar gönderim) anahtarı: istemci formu/pencereyi çizerken
 * üretilir, aynı gönderim tekrarlanırsa (çift tıklama, F5, yanıtı kaybolan
 * Kaydet) aynı değer gelir. Audit new_values içinde `istek_id` olarak saklanır
 * (migration YOK). Biçim: 16-64 küçük onaltılık karakter. Boş → null.
 * @return array{ok:bool, istek_id:?string}
 */
function pdks_faz8j_istek_id($x): array {
    $v = pdks_faz8j_metin($x);
    if ($v === '') return ['ok' => true, 'istek_id' => null];
    return preg_match('/^[a-f0-9]{16,64}$/D', $v) ? ['ok' => true, 'istek_id' => $v] : ['ok' => false, 'istek_id' => null];
}
/** Bu istek_id ile daha önce puantaj_ekle / puantaj_toplu_ekle yazıldı mı? */
function pdks_faz8j_istek_kayitli(PDO $pdo, string $istekId): bool {
    $st = $pdo->prepare("SELECT 1 FROM audit_log WHERE action IN ('puantaj_ekle', 'puantaj_toplu_ekle') AND new_values LIKE ? LIMIT 1");
    $st->execute(['%"istek_id":"' . $istekId . '"%']);
    return (bool)$st->fetchColumn();
}
/**
 * Çavuş + gün + depo mesai satırını kilitler (MySQL: SELECT … FOR UPDATE;
 * SQLite testleri tek bağlantılıdır). Yazma transaction'ının İLK sorgusu
 * olmalıdır: InnoDB REPEATABLE READ okuma görüntüsü ilk TUTARLI okumada
 * oluşur, kilit önce alınınca sonraki okumalar (tekrar gönderim kontrolü,
 * kiosk'un eşzamanlı "Mesaiyi Kapat"ı) en son commit edilmiş veriyi görür.
 * Mesai henüz yoksa (geçmiş gün) eşzamanlı ikinci INSERT UNIQUE anahtarda düşer.
 */
function pdks_faz8j_oturum_kilitle(PDO $pdo, int $foremanId, string $workDate, string $depo): void {
    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->prepare('SELECT id, status FROM daily_work_sessions WHERE foreman_id = ? AND work_date = ? AND depo = ?' . $lock)->execute([$foremanId, $workDate, $depo]);
}
/** Eşzamanlılık kaynaklı DB hatası mı (UNIQUE 23000, deadlock/lock wait 40001/HY000-1205)? */
function pdks_faz8j_eszamanli_hata(Throwable $x): bool {
    if (!$x instanceof PDOException) return false;
    $kod = (string)$x->getCode(); $ic = (int)($x->errorInfo[1] ?? 0);
    return $kod === '23000' || $kod === '40001' || in_array($ic, [1205, 1213], true);
}

/**
 * Kartsız mesai kaydının SANAL kartı mı? (enrolled_source='kartsiz')
 * Sanal kart her kartsız kayıt için ayrı oluşturulur, status='disabled'dır ve
 * canonical_uid'si 'KARTSIZ' önekiyle başlar — onaltılık olmayan harfler (K,R,S,Z)
 * içerdiği için hiçbir UID normalizasyonu (pdks_uid_hex_normalize /
 * pdks_uid_from_decimal / pdks_uid_from_web_nfc) bu değeri ÜRETEMEZ: kart
 * okutularak asla çözülmez.
 */
function pdks_faz8j_kartsiz_mi(array $kart): bool {
    return (string)($kart['enrolled_source'] ?? '') === PDKS_FAZ8J_KARTSIZ_KAYNAK;
}
function pdks_faz8j_kart_kartsiz_mi_id(PDO $pdo, int $cardId): bool {
    if (!pdks_gunluk_kolon_var($pdo, 'worker_cards', 'enrolled_source')) return false;   // eski/asgari şema: kartsız kart olamaz
    $st = $pdo->prepare('SELECT enrolled_source FROM worker_cards WHERE id = ?'); $st->execute([$cardId]);
    return (string)($st->fetchColumn() ?: '') === PDKS_FAZ8J_KARTSIZ_KAYNAK;
}

/**
 * Bu tarihte (iptal edilmemiş) çalışma dönemi OLMAYAN, kullanılabilir kartlar (sanal kartsız kartlar HARİÇ).
 * v303: her satır 'tanim_foreman_id' (null = tanımsız), 'tanim_depo', 'tanim_worker_type_id',
 * 'tanim_tip_adi', 'tanim_foreman_name' taşır (pdks_faz8j_kart_tanim_suz). $foremanId verilirse
 * başka çavuşa tanımlı kartlar DÖNMEZ; $depo verilirse başka depoya tanımlı kartlar DÖNMEZ.
 * Liste yalnız kolaylıktır — kural sunucuda pdks_faz8j_tanim_kart_engeli()'dedir.
 */
function pdks_faz8j_bos_kartlar(string $workDate, ?PDO $pdo = null, ?int $foremanId = null, ?string $depo = null): array {
    $pdo = $pdo ?? db();
    $kartsizHaric = pdks_gunluk_kolon_var($pdo, 'worker_cards', 'enrolled_source') ? " AND enrolled_source <> '" . PDKS_FAZ8J_KARTSIZ_KAYNAK . "'" : '';
    $st = $pdo->prepare("SELECT id, card_no FROM worker_cards WHERE status <> 'disabled'" . $kartsizHaric . "
        AND id NOT IN (SELECT worker_card_id FROM daily_worker_work_periods WHERE work_date_snapshot = ? AND " . pdks_gunluk_faz8j_etkin_kosul($pdo) . ")
        ORDER BY card_no");
    $st->execute([$workDate]);
    return pdks_faz8j_kart_tanim_suz($pdo, $st->fetchAll(), $foremanId, $depo);
}

/**
 * v303 — kart listesine aktif tanım alanlarını ekler ve süzer (SALT OKUNUR, TEK yer).
 * $foremanId verilirse başka çavuşa tanımlı kart çıkar; $depo verilirse başka depoya
 * (TR-duyarsız) tanımlı kart çıkar. $herZaman: süzülse bile KALACAK kart id'leri
 * (Düzenle'de dönemin mevcut kartı). Tanım tablosu yoksa alanlar null, süzme yok.
 */
function pdks_faz8j_kart_tanim_suz(PDO $pdo, array $kartlar, ?int $foremanId = null, ?string $depo = null, array $herZaman = []): array {
    $tanimlar = $kartlar ? pdks_gunluk_kart_tanim_listesi($pdo, array_column($kartlar, 'id')) : [];
    $herZaman = array_flip(array_map('intval', $herZaman));
    $depoFold = $depo !== null ? pdks_gunluk_depo_fold(trim($depo)) : null;
    $out = [];
    foreach ($kartlar as $k) {
        $t = $tanimlar[(int)$k['id']] ?? null;
        $k['tanim_foreman_id']     = $t ? (int)$t['foreman_id'] : null;
        $k['tanim_foreman_name']   = $t ? (string)$t['foreman_name'] : null;
        $k['tanim_depo']           = $t ? (string)$t['depo'] : null;
        $k['tanim_worker_type_id'] = $t ? (int)$t['worker_type_id'] : null;
        $k['tanim_tip_adi']        = $t ? (string)$t['tip_adi'] : null;
        if ($t && !isset($herZaman[(int)$k['id']])) {
            if ($foremanId !== null && (int)$t['foreman_id'] !== $foremanId) continue;
            if ($depoFold !== null && pdks_gunluk_depo_fold(trim((string)$t['depo'])) !== $depoFold) continue;
        }
        $out[] = $k;
    }
    return $out;
}

/**
 * v303 — ELLE EKLEME / KART DEĞİŞTİREN DÜZELTME kapısı (TEK yardımcı): kart başka
 * bir çavuşa ya da başka bir depoya TANIMLIYSA ret metni, değilse null. Aynı çavuş +
 * aynı depo, farklı tip → ENGEL DEĞİL (pdks_faz8j_tanim_uyarilari uyarır).
 * Normal kiosk kapısı (pdks_gunluk_kart_tanim_engeli) DEĞİŞMEDİ — o tip uyuşmazlığını da reddeder.
 * Tanım tablosu yoksa null. Yazma yolunda kart kilidi ALTINDA çağrılır.
 */
function pdks_faz8j_tanim_kart_engeli(PDO $pdo, int $kartId, int $foremanId, string $depo): ?string {
    $t = pdks_gunluk_kart_tanim_aktif($pdo, $kartId);
    if (!$t) return null;
    $depoFarkli = pdks_gunluk_depo_fold(trim((string)$t['depo'])) !== pdks_gunluk_depo_fold(trim($depo));
    if (!$depoFarkli && (int)$t['foreman_id'] === $foremanId) return null;
    return 'Bu kart ' . ($depoFarkli ? trim((string)$t['depo']) . ' deposunda ' : '') . pdks_gunluk_kart_tanim_kime($t)
         . ' tanımlı — yalnız o çavuşun mesaisine eklenebilir.';
}

/** Giriş/çıkış zaman kuralı (TEK yer). $exit null = açık dönem (çağıran izin vermiş olmalı). */
function pdks_faz8j_zaman_kurali(string $workDate, string $entry, ?string $exit, string $simdi): ?string {
    $hata = 'Giriş/çıkış zamanı mesai tarihi, 24 saat ve gelecek kurallarına uymuyor.';
    if (substr($entry, 0, 10) !== $workDate || $entry > $simdi) return $hata;
    if ($exit !== null && ($exit <= $entry || strtotime($exit) > strtotime($entry) + 86400 || $exit > $simdi)) return $hata;
    return null;
}

/**
 * Mesai çözümü (tekil + toplu ortak). $yaz=false → HİÇBİR yan etki yok (önizleme):
 * mesai yoksa oluşturulacağı bildirilir (oturum=null, yeni=true), kiosk yolunun
 * reddedeceği durumlar salt okunur sorgularla önceden raporlanır.
 * $yaz=true → transaction İÇİNDE çağrılır: geçmiş gün için KAPALI mesai yazar,
 * bugün için pdks_gunluk_oturum_ac_veya_getir() (kiosk yolu) çağrılır.
 * $kapali: ['acilis' => en erken giriş, 'kapanis' => en geç çıkış, 'reason' => sebep]
 *
 * @return array{ok:bool, hata?:string, kod?:string, oturum:?array, yeni:bool}
 */
function pdks_faz8j_oturum_coz(PDO $pdo, int $foremanId, string $workDate, string $depo, int $user, bool $yaz, array $kapali = []): array {
    $red = fn(string $h, string $k = 'hata') => ['ok' => false, 'hata' => $h, 'kod' => $k, 'oturum' => null, 'yeni' => false];
    $bugun = date('Y-m-d');
    if ($workDate > $bugun) return $red('Mesai tarihi gelecekte olamaz.', 'gelecek');
    $stF = $pdo->prepare('SELECT * FROM daily_work_sessions WHERE foreman_id = ? AND work_date = ? AND depo = ?');
    $stF->execute([$foremanId, $workDate, $depo]);
    $oturum = $stF->fetch();
    if ($oturum) {
        if ($workDate === $bugun && (string)$oturum['status'] !== 'open') {
            return $red('Bu çavuşun bugünkü mesaisi kapatılmış; kapalı mesaiye bugün için kayıt eklenemez.', 'oturum_kapali_zaten');
        }
        return ['ok' => true, 'oturum' => $oturum, 'yeni' => false];
    }
    $stC = $pdo->prepare('SELECT id, name, code, is_active FROM foremen WHERE id = ?'); $stC->execute([$foremanId]);
    $cavus = $stC->fetch();
    if (!$cavus) return $red('Çavuş bulunamadı.', 'cavus_yok');
    // YENİ mesai açılacaksa pasif çavuş reddedilir — kiosk yolunun (bugün) kuralı,
    // geçmiş gün için de aynı (mevcut mesaiye ekleme etkilenmez).
    if (!(int)$cavus['is_active']) return $red('Bu çavuş pasif — önce aktifleştirin.', 'cavus_pasif');

    if ($workDate === $bugun) {
        if (!$yaz) {
            // Kiosk yolunun (pdks_gunluk_oturum_ac_veya_getir) ret kurallarının
            // SALT OKUNUR ön kontrolü — mesajlar o fonksiyonla aynı.
            $eskiler = pdks_gunluk_eski_acik_oturumlar($depo, $foremanId, $pdo);
            if (!empty($eskiler)) {
                return $red('Bu çavuşun ' . date('d.m.Y', strtotime((string)$eskiler[0]['work_date']))
                    . ' tarihli mesaisi kapatılmamış. Yeni gün açılmadan önce o mesaiyi kapatın.', 'onceki_mesai_acik');
            }
            return ['ok' => true, 'oturum' => null, 'yeni' => true];
        }
        $r = pdks_gunluk_oturum_ac_veya_getir($foremanId, $user, $pdo);
        if (empty($r['ok'])) return $red((string)($r['hata'] ?? 'Mesai açılamadı.'), (string)($r['kod'] ?? 'hata'));
        return ['ok' => true, 'oturum' => $r['session'], 'yeni' => (bool)($r['yeni'] ?? false)];
    }

    if (!$yaz) return ['ok' => true, 'oturum' => null, 'yeni' => true];
    $normalDk = 540;
    if (pdks_gunluk_kolon_var($pdo, 'foremen', 'normal_work_minutes')) {
        $sn = $pdo->prepare('SELECT normal_work_minutes FROM foremen WHERE id = ?'); $sn->execute([$foremanId]);
        $nv = $sn->fetchColumn(); if ($nv !== false && $nv !== null) $normalDk = (int)$nv;
    }
    $acilis = (string)($kapali['acilis'] ?? ($workDate . ' 00:00:00'));
    $kapanis = (string)($kapali['kapanis'] ?? $acilis);
    $kol = 'foreman_id, foreman_name_snapshot, foreman_code_snapshot, work_date, depo, status, opened_at, opened_by_user_id, closed_at, closed_by_user_id, notes';
    $par = [$foremanId, (string)$cavus['name'], (string)$cavus['code'], $workDate, $depo, 'closed', $acilis, $user, $kapanis, $user, 'Geçmişe dönük elle oluşturuldu: ' . (string)($kapali['reason'] ?? '')];
    if (pdks_gunluk_kolon_var($pdo, 'daily_work_sessions', 'normal_work_minutes_snapshot')) {
        $kol .= ', normal_work_minutes_snapshot'; $par[] = $normalDk;
    }
    try {
        $pdo->prepare('INSERT INTO daily_work_sessions (' . $kol . ') VALUES (' . implode(',', array_fill(0, count($par), '?')) . ')')->execute($par);
    } catch (PDOException $e) {
        // UNIQUE(foreman_id, work_date, depo): eşzamanlı başka işlem bu mesaiyi az önce oluşturdu.
        if (pdks_faz8j_eszamanli_hata($e)) return $red(PDKS_FAZ8J_ESZAMANLI_HATA, 'eszamanli');
        throw $e;
    }
    $sid = (int)$pdo->lastInsertId();
    $stF->execute([$foremanId, $workDate, $depo]); $oturum = $stF->fetch();
    if (function_exists('audit_log_event')) {
        audit_log_event('create', 'daily_work_sessions', $sid, null, ['foreman_id' => $foremanId, 'work_date' => $workDate, 'depo' => $depo, 'kaynak' => 'gecmise_donuk_ekle']);
    }
    return ['ok' => true, 'oturum' => $oturum, 'yeni' => true];
}

/**
 * Bir satırın kuralları — SALT OKUNUR (önizleme ve yazma öncesi AYNI fonksiyon).
 * $satir: ['kartsiz'=>bool, 'kart'=>?array (worker_cards satırı), 'entry'=>string, 'exit'=>?string]
 * $sessionId: hedef mesai (henüz oluşturulmadıysa null). Yazma yolunda kart
 * kilitlendikten SONRA çağrılır.
 * v303 $hedef: ['foreman_id' => int, 'depo' => string] — hedef mesainin çavuşu/deposu
 * (tanımlı kart kapısı). Verilmezse $sessionId'den okunur; ikisi de yoksa tanımlı
 * kart REDDEDİLİR (fail-closed).
 */
function pdks_faz8j_satir_kontrol(PDO $pdo, ?int $sessionId, string $workDate, array $satir, ?array $hedef = null): ?string {
    $entry = (string)$satir['entry']; $exit = $satir['exit'] ?? null;
    if (!empty($satir['kartsiz'])) {
        return $exit === null ? 'Kartsız mesaide çıkış tarihi ve saati zorunludur.' : null;
    }
    $kart = $satir['kart'] ?? null;
    if (!$kart) return 'Seçilen kart bulunamadı.';
    if (pdks_faz8j_kartsiz_mi($kart)) return 'Kartsız mesainin sanal kartı başka bir kayda bağlanamaz.';
    $cardId = (int)$kart['id'];
    // Kayıp / devre dışı fiziksel kart hiçbir satırda kullanılamaz (kiosk kuralı).
    if ((string)$kart['status'] === 'lost') return 'Bu kart KAYIP olarak işaretli.';
    if ((string)$kart['status'] === 'disabled') return 'Bu kart DEVRE DIŞI.';
    // v303: başka çavuşa / depoya TANIMLI kart bu mesaiye eklenemez.
    if ($hedef === null && $sessionId !== null) {
        $so = $pdo->prepare('SELECT foreman_id, depo FROM daily_work_sessions WHERE id = ?'); $so->execute([$sessionId]);
        $hedef = $so->fetch() ?: null;
    }
    if ($hedef === null) {
        if (pdks_gunluk_kart_tanim_aktif($pdo, $cardId)) return 'Tanımlı kart için hedef mesai belirlenemedi.';
    } elseif ($e = pdks_faz8j_tanim_kart_engeli($pdo, $cardId, (int)$hedef['foreman_id'], (string)$hedef['depo'])) {
        return $e;
    }
    if ($exit === null) {
        // Bugün, çıkışsız → AÇIK dönem: kiosk girişinin kart kuralları.
        if ($workDate !== date('Y-m-d')) return 'Geçmişe dönük eklemede çıkış tarihi ve saati zorunludur.';
        $acik = pdks_gunluk_faz8a_kart_acik_donemi($pdo, $cardId);
        if ($acik !== null) {
            return ($sessionId !== null && (int)$acik['session_id'] === $sessionId)
                ? 'Bu kart zaten bu mesaide içeride (çıkışı yapılmamış).'
                : 'Bu kart ' . $acik['foreman_name'] . ' mesaisinde açık görünüyor.';
        }
        $eksik = pdks_gunluk_faz8a_kart_eksik_cikisli_donemi($pdo, $cardId);
        if ($eksik && (string)$eksik['work_date'] === $workDate) {
            return pdks_gunluk_eksik_cikis_uyari_metni($eksik) . ' Aynı gün başka mesaiye giriş yapılamaz.';
        }
        $ov = $pdo->prepare("SELECT id FROM daily_worker_work_periods WHERE worker_card_id = ? AND is_voided = 0 AND exit_time IS NOT NULL AND exit_time > ? LIMIT 1");
        $ov->execute([$cardId, $entry]);
    } else {
        $ov = $pdo->prepare("SELECT id FROM daily_worker_work_periods WHERE worker_card_id = ? AND is_voided = 0 AND entry_time < ? AND COALESCE(exit_time,'9999-12-31 23:59:59') > ? LIMIT 1");
        $ov->execute([$cardId, $exit, $entry]);
    }
    if ($ov->fetchColumn()) return 'Seçilen kartın bu saatlerle çakışan aktif bir çalışma dönemi var.';
    return null;
}

/**
 * Kartsız mesai için YENİ sanal kart (transaction İÇİNDE). Kart no satır id'sinden
 * türetilir ('KARTSIZ-' + 6 haneli id): geçici benzersiz numarayla INSERT, sonra
 * UPDATE — MySQL REPEATABLE READ görüntüsünde "en büyük numara + 1" eşzamanlı iki
 * işlemde aynı çıkabilirdi, id ise her zaman benzersizdir. '^<harf>\d+$' desenine
 * uymadığı için pdks_gunluk_sonraki_kart_no() ile çakışmaz. Hata yutulmaz.
 */
function pdks_faz8j_kartsiz_kart_olustur(PDO $pdo, int $workerTypeId, int $user): array {
    $geciciNo = PDKS_FAZ8J_KARTSIZ_ONEK . 'T' . bin2hex(random_bytes(8));
    $uid = 'KARTSIZ' . strtoupper(bin2hex(random_bytes(12)));   // onaltılık olmayan önek: okutulamaz
    $pdo->prepare('INSERT INTO worker_cards (card_no, worker_type_id, canonical_uid, uid_bytes, uid_decimal, enrolled_source, status, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$geciciNo, $workerTypeId, $uid, 0, null, PDKS_FAZ8J_KARTSIZ_KAYNAK, 'disabled', 'Kartsız mesai (sanal kart) — elle eklendi', $user]);
    $id = (int)$pdo->lastInsertId();
    $cardNo = PDKS_FAZ8J_KARTSIZ_ONEK . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE worker_cards SET card_no = ? WHERE id = ?')->execute([$cardNo, $id]);
    if (function_exists('audit_log_event')) {
        audit_log_event('create', 'worker_cards', $id, null, ['card_no' => $cardNo, 'worker_type_id' => $workerTypeId, 'kaynak' => PDKS_FAZ8J_KARTSIZ_KAYNAK, 'kartsiz' => true]);
    }
    $s2 = $pdo->prepare('SELECT * FROM worker_cards WHERE id = ?'); $s2->execute([$id]);
    return $s2->fetch();
}

/**
 * TEK satır yazma (TEK INSERT yeri): GİRİŞ [+ ÇIKIŞ] olayı source='manual',
 * dönem source='manual' (çıkış yoksa status='open'), audit `puantaj_ekle`.
 * Transaction İÇİNDE, kart kilitlenip pdks_faz8j_satir_kontrol() geçtikten sonra
 * çağrılır. Hakediş işaretini çağıran (oturum başına bir kez) koyar.
 * $satir['kart'] dolu olmalı (kartsız satırda sanal kart önceden oluşturulur).
 * $meta: reason, note, yeni_mesai, simdi, [toplu_id]
 */
function pdks_faz8j_satir_yaz(PDO $pdo, array $oturum, array $satir, int $user, array $meta): int {
    $sid = (int)$oturum['id']; $workDate = (string)$oturum['work_date']; $depo = (string)$oturum['depo'];
    $kart = $satir['kart']; $card = (int)$kart['id']; $tip = $satir['tip'];
    $type = (int)$tip['id']; $typeName = (string)$tip['name'];
    $entry = (string)$satir['entry']; $exit = $satir['exit'] ?? null;
    $insE = $pdo->prepare("INSERT INTO daily_worker_card_events (session_id, worker_card_id, event_type, source, canonical_uid_snapshot, worker_type_id_snapshot, worker_type_name_snapshot, work_date_snapshot, depo_snapshot, recorded_by_user_id, server_event_time) VALUES (?,?,?,'manual',?,?,?,?,?,?,?)");
    $evPar = fn(string $tur, string $zaman) => [$sid, $card, $tur, (string)$kart['canonical_uid'], $type, $typeName, $workDate, $depo, $user, $zaman];
    $insE->execute($evPar('GIRIS', $entry)); $girisEv = (int)$pdo->lastInsertId();
    $cikisEv = null;
    if ($exit !== null) { $insE->execute($evPar('CIKIS', $exit)); $cikisEv = (int)$pdo->lastInsertId(); }
    $status = $exit !== null ? 'closed' : 'open';
    $pdo->prepare("INSERT INTO daily_worker_work_periods (session_id, worker_card_id, worker_type_id_snapshot, worker_type_name_snapshot, entry_event_id, exit_event_id, entry_time, exit_time, declared_attendance_class, work_date_snapshot, depo_snapshot, status, source) VALUES (?,?,?,?,?,?,?,?,'auto',?,?,?,'manual')")
        ->execute([$sid, $card, $type, $typeName, $girisEv, $cikisEv, $entry, $exit, $workDate, $depo, $status]);
    $pid = (int)$pdo->lastInsertId();
    $kayit = [
        'session_id' => $sid, 'period_id' => $pid, 'worker_card_id' => $card, 'card_no' => (string)$kart['card_no'],
        'worker_type_id_snapshot' => $type, 'worker_type_name_snapshot' => $typeName,
        'entry_time' => $entry, 'exit_time' => $exit, 'status' => $status, 'work_date' => $workDate,
        'yeni_mesai' => (bool)($meta['yeni_mesai'] ?? false), 'kartsiz' => pdks_faz8j_kartsiz_mi($kart),
        'reason' => (string)($meta['reason'] ?? ''), 'note' => (string)($meta['note'] ?? ''),
        'added_at' => (string)($meta['simdi'] ?? date('Y-m-d H:i:s')), 'user_id' => $user,
    ];
    if (!empty($meta['toplu_id'])) $kayit['toplu_id'] = (string)$meta['toplu_id'];
    if (!empty($meta['istek_id'])) $kayit['istek_id'] = (string)$meta['istek_id'];
    pdks_faz8j_audit($pdo, $user, 'puantaj_ekle', $pid, [], $kayit);
    return $pid;
}

/** @return array{ok:bool, hata?:string, session_id?:int, period_id?:int, yeni_mesai?:bool, kartsiz?:bool, card_no?:string, acik?:bool} */
function pdks_faz8j_gecmis_ekle(array $v, int $user, ?PDO $pdo = null): array {
    $pdo = $pdo ?? db();
    if ($e = pdks_faz8j_yetki()) return ['ok' => false, 'hata' => $e];
    $m = 'pdks_faz8j_metin';
    $depo = $m($v['depo'] ?? '');
    if ($e = pdks_faz8j_aktif_depo_kontrol($depo)) return ['ok' => false, 'hata' => $e];
    if (!pdks_faz8j_sema_hazir($pdo) || !pdks_gunluk_faz8a_sema_hazir($pdo)) {
        return ['ok' => false, 'hata' => 'Puantaj düzeltme şeması henüz hazır değil.'];
    }
    $foremanId = (int)$m($v['foreman_id'] ?? 0);
    $workDate  = $m($v['work_date'] ?? '');
    $kartsiz   = !in_array($m($v['kartsiz'] ?? ''), ['', '0'], true);
    $card      = $kartsiz ? 0 : (int)$m($v['worker_card_id'] ?? 0);
    $type      = (int)$m($v['worker_type_id'] ?? 0);
    $reason    = $m($v['reason'] ?? '');
    $note      = $m($v['note'] ?? '');
    $entry     = pdks_faz8j_zaman($m($v['entry_date'] ?? ''), $m($v['entry_clock'] ?? ''));
    $ist       = pdks_faz8j_istek_id($v['istek_id'] ?? '');
    if (!$ist['ok']) return ['ok' => false, 'hata' => 'Geçersiz istek anahtarı; sayfayı yenileyip tekrar deneyin.'];
    $istekId   = $ist['istek_id'];
    $cikis     = pdks_faz8j_cikis_zamani($v);
    if (!$cikis['ok']) return ['ok' => false, 'hata' => $cikis['hata']];
    $exit = $cikis['exit'];
    if ($foremanId < 1 || (!$kartsiz && $card < 1) || $type < 1 || !$entry || $reason === '' || mb_strlen($reason) > 500 || mb_strlen($note) > 1000
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $workDate) || !strtotime($workDate)) {
        return ['ok' => false, 'hata' => 'Alanları kontrol edin: çavuş, tarih, kart, işçi tipi, giriş saati ve sebep zorunludur.'];
    }
    $simdi = date('Y-m-d H:i:s');
    if ($workDate > date('Y-m-d')) return ['ok' => false, 'hata' => 'Mesai tarihi gelecekte olamaz.'];
    if ($exit === null) {
        if ($kartsiz) return ['ok' => false, 'hata' => 'Kartsız mesaide çıkış tarihi ve saati zorunludur.'];
        if ($workDate !== date('Y-m-d')) return ['ok' => false, 'hata' => 'Geçmişe dönük eklemede çıkış tarihi ve saati zorunludur.'];
    }
    if ($e = pdks_faz8j_zaman_kurali($workDate, $entry, $exit, $simdi)) return ['ok' => false, 'hata' => $e];
    if ($istekId !== null && pdks_faz8j_istek_kayitli($pdo, $istekId)) return ['ok' => false, 'hata' => PDKS_FAZ8J_TEKRAR_HATA, 'tekrar' => true];

    try {
        $pdo->beginTransaction();
        pdks_faz8j_oturum_kilitle($pdo, $foremanId, $workDate, $depo);   // İLK sorgu — bkz. docblock
        if ($istekId !== null && pdks_faz8j_istek_kayitli($pdo, $istekId)) throw new RuntimeException(PDKS_FAZ8J_TEKRAR_HATA);
        $kart = null;
        if (!$kartsiz) {
            $c = $pdo->prepare('SELECT * FROM worker_cards WHERE id = ?'); $c->execute([$card]);
            $kart = $c->fetch() ?: null;
            if (!$kart) throw new RuntimeException('Seçilen kart bulunamadı.');
        }
        $tip = pdks_faz8j_desteklenen_tip($pdo, $type);
        if (!$tip) throw new RuntimeException('Seçilen işçi tipi bulunamadı, pasif veya günlük işçi girişinde desteklenmiyor.');

        $oc = pdks_faz8j_oturum_coz($pdo, $foremanId, $workDate, $depo, $user, true, ['acilis' => $entry, 'kapanis' => $exit ?? $entry, 'reason' => $reason]);
        if (!$oc['ok']) throw new RuntimeException((string)$oc['hata']);
        $oturum = $oc['oturum']; $yeni = $oc['yeni']; $sid = (int)$oturum['id'];
        if (pdks_faz8j_entitlement($pdo, $sid) === 'final') {
            throw new RuntimeException('Bu mesainin kesinleşmiş hakedişi bulunmaktadır. Önce hakedişi yönetici tarafından yeniden açın.');
        }

        if (!$kartsiz) pdks_gunluk_faz8a_kart_kilitle($pdo, $card);
        $satir = ['kartsiz' => $kartsiz, 'kart' => $kart, 'tip' => $tip, 'entry' => $entry, 'exit' => $exit];
        if ($h = pdks_faz8j_satir_kontrol($pdo, $sid, (string)$oturum['work_date'], $satir, ['foreman_id' => (int)$oturum['foreman_id'], 'depo' => (string)$oturum['depo']])) throw new RuntimeException($h);
        if ($kartsiz) $satir['kart'] = pdks_faz8j_kartsiz_kart_olustur($pdo, (int)$tip['id'], $user);
        $pid = pdks_faz8j_satir_yaz($pdo, $oturum, $satir, $user, ['reason' => $reason, 'note' => $note, 'yeni_mesai' => $yeni, 'simdi' => $simdi, 'istek_id' => $istekId]);
        pdks_faz8j_yeniden_hesap_isaretle($pdo, $sid);
        $pdo->commit();
        // v298: kart başka çavuş/tip/depoya tanımlıysa ENGEL DEĞİL, uyarı.
        $uyarilar = $kartsiz ? [] : pdks_faz8j_tanim_uyarilari($pdo, (int)$oturum['foreman_id'], (string)$oturum['depo'], [$satir]);
        return ['ok' => true, 'session_id' => $sid, 'period_id' => $pid, 'yeni_mesai' => $yeni,
                'kartsiz' => $kartsiz, 'card_no' => (string)$satir['kart']['card_no'], 'acik' => $exit === null,
                'uyarilar' => $uyarilar];
    } catch (Throwable $x) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($x instanceof RuntimeException && !$x instanceof PDOException) return ['ok' => false, 'hata' => $x->getMessage()] + ($x->getMessage() === PDKS_FAZ8J_TEKRAR_HATA ? ['tekrar' => true] : []);
        return ['ok' => false, 'hata' => pdks_faz8j_eszamanli_hata($x) ? PDKS_FAZ8J_ESZAMANLI_HATA : 'Kayıt sırasında teknik bir hata oluştu. Lütfen tekrar deneyin.'];
    }
}

// =========================================================
// TOPLU İŞLEM — bir çavuş + bir gün + aktif depo, yalnız yönetici
// =========================================================
// Önizleme (pdks_faz8j_toplu_onizle) HİÇBİR yan etki üretmez. Ekleme
// (pdks_faz8j_toplu_ekle) hep-ya-hiç: tek satır bile hatalıysa HİÇBİR ŞEY yazılmaz.
// Satırlar tekil eklemeyle AYNI çekirdekten geçer (oturum_coz / satir_kontrol /
// satir_yaz) — ikinci bir yazma yolu YOK. Toplu kimlik 'TP' + Ymd + 8 hex;
// her satır audit'inde `toplu_id`, ayrıca mesai (daily_work_sessions) modülünde
// TEK özet audit `puantaj_toplu_ekle`. Geri alma `puantaj_toplu_geri_al`.

/** Toplu girdiyi ayrıştırır/doğrular (yalnız okuma). Satır spesifikasyonları + genel hatalar. */
function pdks_faz8j_toplu_hazirla(PDO $pdo, array $v): array {
    $m = 'pdks_faz8j_metin';
    $h = [
        'foreman_id' => (int)$m($v['foreman_id'] ?? 0), 'work_date' => $m($v['work_date'] ?? ''),
        'depo' => $m($v['depo'] ?? ''), 'reason' => $m($v['reason'] ?? ''),
        'note' => $m($v['note'] ?? ''), 'hatalar' => [], 'satirlar' => [],
    ];
    $wd = $h['work_date']; $bugun = date('Y-m-d'); $simdi = date('Y-m-d H:i:s');
    if ($h['foreman_id'] < 1) $h['hatalar'][] = 'Çavuş seçin.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $wd) || !DateTimeImmutable::createFromFormat('!Y-m-d', $wd) || DateTimeImmutable::createFromFormat('!Y-m-d', $wd)->format('Y-m-d') !== $wd) {
        $h['hatalar'][] = 'Geçerli bir mesai tarihi girin.'; return $h;
    }
    if ($wd > $bugun) { $h['hatalar'][] = 'Mesai tarihi gelecekte olamaz.'; return $h; }
    if ($h['reason'] === '' || mb_strlen($h['reason']) > 500) $h['hatalar'][] = 'Sebep zorunludur ve en fazla 500 karakter olabilir.';
    if (mb_strlen($h['note']) > 1000) $h['hatalar'][] = 'Not en fazla 1000 karakter olabilir.';
    $gruplar = $v['gruplar'] ?? null;
    if (!is_array($gruplar) || $gruplar === []) { $h['hatalar'][] = 'En az bir işçi tipi grubu girin.'; return $h; }

    // Önce sayım (limit), satırlar ancak sınır içindeyse kurulur.
    $toplam = 0;
    foreach ($gruplar as $g) {
        if (!is_array($g)) continue;
        $toplam += count(is_array($g['kart_ids'] ?? null) ? $g['kart_ids'] : []) + max(0, (int)$m($g['kartsiz_adet'] ?? 0));
    }
    if ($toplam < 1) { $h['hatalar'][] = 'En az bir kart ya da kartsız kişi seçin.'; return $h; }
    if ($toplam > PDKS_FAZ8J_TOPLU_LIMIT) { $h['hatalar'][] = 'Bir toplu işlemde en fazla ' . PDKS_FAZ8J_TOPLU_LIMIT . ' kayıt eklenebilir (seçilen: ' . $toplam . ').'; return $h; }

    $tipGoruldu = []; $kartGoruldu = []; $tumKartIds = [];
    foreach ($gruplar as $g) {
        foreach ((is_array($g) && is_array($g['kart_ids'] ?? null) ? $g['kart_ids'] : []) as $k) if ((int)$m($k) > 0) $tumKartIds[(int)$m($k)] = true;
    }
    $kartlar = [];
    if ($tumKartIds) {
        $ids = array_keys($tumKartIds);
        $st = $pdo->prepare('SELECT * FROM worker_cards WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) $kartlar[(int)$r['id']] = $r;
    }
    foreach (array_values($gruplar) as $i => $g) {
        $g = is_array($g) ? $g : [];
        $tip = pdks_faz8j_desteklenen_tip($pdo, (int)$m($g['worker_type_id'] ?? 0));
        $etiket = $tip ? (string)$tip['name'] : (($i + 1) . '. grup');
        $grupHata = null;
        if (!$tip) $grupHata = 'İşçi tipi bulunamadı, pasif veya desteklenmiyor (yalnız KADIN/ERKEK/RAMPACI).';
        elseif (isset($tipGoruldu[(int)$tip['id']])) $grupHata = 'Aynı işçi tipi için birden fazla grup girilemez.';
        if ($tip) $tipGoruldu[(int)$tip['id']] = true;
        $entry = pdks_faz8j_zaman($wd, $m($g['entry_clock'] ?? ''));
        $cikis = pdks_faz8j_cikis_zamani($g);
        $exit = $cikis['ok'] ? $cikis['exit'] : null;
        $kartsizAdet = (int)$m($g['kartsiz_adet'] ?? 0);
        if ($grupHata === null) {
            if ($kartsizAdet < 0) $grupHata = 'Kartsız kişi sayısı geçersiz.';
            elseif (!$entry) $grupHata = 'Geçerli bir giriş saati girin.';
            elseif (!$cikis['ok']) $grupHata = (string)$cikis['hata'];
            elseif ($exit === null && $wd !== $bugun) $grupHata = 'Geçmişe dönük eklemede çıkış tarihi ve saati zorunludur.';
            elseif ($exit === null && $kartsizAdet > 0) $grupHata = 'Kartsız mesaide çıkış tarihi ve saati zorunludur (çıkışsız grup yalnız kartlı olabilir).';
            elseif ($e = pdks_faz8j_zaman_kurali($wd, (string)$entry, $exit, $simdi)) $grupHata = $e;
        }
        if ($grupHata !== null) $h['hatalar'][] = $etiket . ': ' . $grupHata;
        $tipSatir = $tip ?: ['id' => (int)$m($g['worker_type_id'] ?? 0), 'name' => $etiket];
        $grupKart = [];
        foreach ((is_array($g['kart_ids'] ?? null) ? $g['kart_ids'] : []) as $k) {
            $k = (int)$m($k); $hata = $grupHata;
            $kart = $kartlar[$k] ?? null;
            if ($hata === null && $k < 1) $hata = 'Geçersiz kart.';
            elseif ($hata === null && isset($grupKart[$k])) $hata = 'Aynı kart grupta iki kez seçildi.';
            elseif ($hata === null && isset($kartGoruldu[$k])) {
                $hata = 'Aynı kart birden fazla grupta seçildi.';
                $h['hatalar'][] = 'Aynı kart birden fazla grupta seçildi: ' . ($kart['card_no'] ?? ('#' . $k)) . '.';
            }
            $grupKart[$k] = true; $kartGoruldu[$k] = true;
            $h['satirlar'][] = ['grup' => $i, 'kartsiz' => false, 'kart_id' => $k, 'kart' => $kart, 'tip' => $tipSatir,
                                'entry' => (string)($entry ?? ''), 'exit' => $exit, 'hata' => $hata];
        }
        for ($n = 0; $n < $kartsizAdet; $n++) {
            $h['satirlar'][] = ['grup' => $i, 'kartsiz' => true, 'kart_id' => 0, 'kart' => null, 'tip' => $tipSatir,
                                'entry' => (string)($entry ?? ''), 'exit' => $exit, 'hata' => $grupHata];
        }
    }
    $h['hatalar'] = array_values(array_unique($h['hatalar']));
    return $h;
}

/** Satır spesifikasyonu → dış çıktı satırı. */
function pdks_faz8j_toplu_satir_cikti(array $s): array {
    return [
        'tip' => (string)($s['tip']['name'] ?? ''), 'worker_type_id' => (int)($s['tip']['id'] ?? 0),
        'kart_id' => $s['kartsiz'] ? null : (int)$s['kart_id'],
        'kart_no' => $s['kartsiz'] ? 'KARTSIZ' : (string)($s['kart']['card_no'] ?? ('#' . (int)$s['kart_id'])),
        'kartsiz' => (bool)$s['kartsiz'],
        'giris' => $s['entry'] !== '' ? (string)$s['entry'] : null, 'cikis' => $s['exit'],
        'durum' => $s['hata'] === null ? 'ok' : 'hata', 'hata' => $s['hata'],
    ];
}

/** Tip başına kartlı/kartsız sayım. */
function pdks_faz8j_toplu_ozet(array $satirlar): array {
    $o = [];
    foreach ($satirlar as $s) {
        $k = (int)($s['tip']['id'] ?? 0);
        $o[$k] ??= ['worker_type_id' => $k, 'tip' => (string)($s['tip']['name'] ?? ''), 'kartli' => 0, 'kartsiz' => 0, 'toplam' => 0];
        $o[$k][$s['kartsiz'] ? 'kartsiz' : 'kartli']++; $o[$k]['toplam']++;
    }
    return array_values($o);
}

/** Ortak kapılar (yetki/depo/şema). Hata metni ya da null. */
function pdks_faz8j_toplu_kapi(PDO $pdo, string $depo): ?string {
    if ($e = pdks_faz8j_yetki()) return $e;
    if ($e = pdks_faz8j_aktif_depo_kontrol($depo)) return $e;
    if (!pdks_faz8j_sema_hazir($pdo) || !pdks_gunluk_faz8a_sema_hazir($pdo)) return 'Puantaj düzeltme şeması henüz hazır değil.';
    return null;
}

/**
 * Toplu ekleme ÖNİZLEMESİ — HİÇBİR yan etki yok (yazma/oturum açma/audit yok).
 * @return array{ok:bool, satirlar:array, ozet:array, hatalar:array, yeni_mesai:bool, session_id:?int}
 */
function pdks_faz8j_toplu_onizle(array $v, int $user, ?PDO $pdo = null): array {
    $pdo = $pdo ?? db();
    $bos = ['ok' => false, 'satirlar' => [], 'ozet' => [], 'hatalar' => [], 'uyarilar' => [], 'yeni_mesai' => false, 'session_id' => null];
    if ($e = pdks_faz8j_toplu_kapi($pdo, pdks_faz8j_metin($v['depo'] ?? ''))) return ['hatalar' => [$e]] + $bos;
    $h = pdks_faz8j_toplu_hazirla($pdo, $v);
    $sonuc = pdks_faz8j_toplu_degerlendir($pdo, $h, null);
    unset($sonuc['_specs']);
    return $sonuc;
}

/**
 * Hazırlanmış girdiyi mesaiyle birlikte değerlendirir. $oturum verilirse (yazma
 * yolunda, transaction içinde) mesai yeniden çözülmez. SALT OKUNUR.
 */
function pdks_faz8j_toplu_degerlendir(PDO $pdo, array $h, ?array $oturum, ?bool $yeni = null): array {
    $hatalar = $h['hatalar']; $sid = $oturum ? (int)$oturum['id'] : null; $yeniMesai = (bool)$yeni;
    if ($oturum === null && $h['foreman_id'] > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $h['work_date']) && $h['work_date'] <= date('Y-m-d')) {
        $oc = pdks_faz8j_oturum_coz($pdo, $h['foreman_id'], $h['work_date'], $h['depo'], 0, false);
        if (!$oc['ok']) $hatalar[] = (string)$oc['hata'];
        else { $yeniMesai = $oc['yeni']; if ($oc['oturum']) $sid = (int)$oc['oturum']['id']; }
    }
    if ($sid !== null && pdks_faz8j_entitlement($pdo, $sid) === 'final') {
        $hatalar[] = 'Bu mesainin kesinleşmiş hakedişi bulunmaktadır. Önce hakedişi yönetici tarafından yeniden açın.';
    }
    $specs = $h['satirlar'];
    $hedef = $oturum ? ['foreman_id' => (int)$oturum['foreman_id'], 'depo' => (string)$oturum['depo']]
                     : ['foreman_id' => (int)$h['foreman_id'], 'depo' => (string)$h['depo']];
    foreach ($specs as &$s) {
        if ($s['hata'] === null) $s['hata'] = pdks_faz8j_satir_kontrol($pdo, $sid, $h['work_date'], $s, $hedef);
    }
    unset($s);
    $satirHatasi = count(array_filter($specs, fn($s) => $s['hata'] !== null));
    if ($satirHatasi > 0 && $hatalar === []) $hatalar[] = $satirHatasi . ' satırda hata var; hiçbir kayıt eklenmedi.';
    return [
        'ok' => $hatalar === [] && $specs !== [],
        'satirlar' => array_map('pdks_faz8j_toplu_satir_cikti', $specs),
        'ozet' => pdks_faz8j_toplu_ozet($specs),
        'hatalar' => array_values(array_unique($hatalar)),
        'uyarilar' => array_merge(
            $sid !== null ? pdks_faz8j_kartsiz_tekrar_uyarilari($pdo, $sid, $specs) : [],
            pdks_faz8j_tanim_uyarilari($pdo, (int)$h['foreman_id'], (string)$h['depo'], $specs)
        ),
        'yeni_mesai' => $yeniMesai, 'session_id' => $sid,
        '_specs' => $specs,
    ];
}

/**
 * ENGELLEMEYEN uyarı: mesaide istenen kartsız grupla AYNI tip + AYNI giriş/çıkış
 * saatlerinde zaten aktif kartsız kayıt varsa (olası tekrar ekleme) bildirir.
 * Kartlı satırlar çakışma kuralıyla zaten korunur; kartsızda kart yoktur.
 */
function pdks_faz8j_kartsiz_tekrar_uyarilari(PDO $pdo, int $sid, array $specs): array {
    $gruplar = [];
    foreach ($specs as $s) {
        if (empty($s['kartsiz']) || $s['exit'] === null || (string)$s['entry'] === '') continue;
        $k = (int)$s['tip']['id'] . '|' . $s['entry'] . '|' . $s['exit'];
        $gruplar[$k] ??= $s;
    }
    if (!$gruplar || !pdks_gunluk_kolon_var($pdo, 'worker_cards', 'enrolled_source')) return [];
    $st = $pdo->prepare("SELECT COUNT(*) FROM daily_worker_work_periods p JOIN worker_cards w ON w.id = p.worker_card_id
        WHERE p.session_id = ? AND " . pdks_gunluk_faz8j_etkin_kosul($pdo, 'p') . " AND w.enrolled_source = ?
          AND p.worker_type_id_snapshot = ? AND p.entry_time = ? AND p.exit_time = ?");
    $out = [];
    foreach ($gruplar as $s) {
        $st->execute([$sid, PDKS_FAZ8J_KARTSIZ_KAYNAK, (int)$s['tip']['id'], $s['entry'], $s['exit']]);
        $n = (int)$st->fetchColumn();
        if ($n > 0) {
            $out[] = 'Bu mesaide aynı saatlerde ' . $n . ' kartsız ' . mb_strtoupper((string)$s['tip']['name'], 'UTF-8')
                   . ' kaydı zaten var — tekrar eklemediğinizden emin olun.';
        }
    }
    return $out;
}

/**
 * v298 — ENGELLEMEYEN uyarı: kartlı satırın kartı (Kart Havuzu'nda) AYNI çavuş +
 * AYNI depoda FARKLI bir tipe tanımlıysa bildirir (kiosk bunu reddeder; yönetici
 * eklemesi engellenmez — yalnız `uyarilar`). v303: başka çavuş / başka depo artık
 * ENGELDİR (pdks_faz8j_tanim_kart_engeli, satir_kontrol içinde) — uyarı üretmez.
 * Tanım tablosu yoksa boş.
 */
function pdks_faz8j_tanim_uyarilari(PDO $pdo, int $foremanId, string $depo, array $specs): array {
    $kartIds = [];
    foreach ($specs as $s) if (empty($s['kartsiz']) && !empty($s['kart']['id'])) $kartIds[] = (int)$s['kart']['id'];
    $tanimlar = $kartIds && function_exists('pdks_gunluk_kart_tanim_listesi') ? pdks_gunluk_kart_tanim_listesi($pdo, $kartIds) : [];
    if (!$tanimlar) return [];
    $out = [];
    foreach ($specs as $s) {
        if (!empty($s['kartsiz']) || empty($s['kart']['id'])) continue;
        $cid = (int)$s['kart']['id'];
        $t = $tanimlar[$cid] ?? null;
        if (!$t || isset($out[$cid])) continue;
        if (pdks_gunluk_kart_tanim_engeli($t, ['foreman_id' => $foremanId, 'depo' => $depo], (int)($s['tip']['id'] ?? 0)) === null) continue;
        // v303: başka çavuş / depo artık ENGEL (pdks_faz8j_tanim_kart_engeli) — uyarı yalnız aynı çavuşta farklı tip.
        if ((int)$t['foreman_id'] !== $foremanId || pdks_gunluk_depo_fold(trim((string)$t['depo'])) !== pdks_gunluk_depo_fold(trim($depo))) continue;
        $out[$cid] = (string)$s['kart']['card_no'] . ' kartı ' . trim((string)$t['depo']) . ' deposunda '
                   . pdks_gunluk_kart_tanim_kime($t) . ' tanımlı — kayıt yine de eklenir.';
    }
    return array_values($out);
}

/**
 * Toplu ekleme — hep-ya-hiç. `istek_id` ZORUNLU (tekrar gönderim koruması). Başarıda:
 * @return array{ok:true, session_id:int, toplu_id:string, period_ids:int[], eklenen:int, yeni_mesai:bool, satirlar:array, ozet:array, hatalar:array}
 * Hatada önizlemeyle aynı biçim (ok=false, satirlar/hatalar/ozet).
 */
function pdks_faz8j_toplu_ekle(array $v, int $user, ?PDO $pdo = null): array {
    $pdo = $pdo ?? db();
    $bos = ['ok' => false, 'satirlar' => [], 'ozet' => [], 'hatalar' => [], 'uyarilar' => [], 'yeni_mesai' => false, 'session_id' => null];
    if ($e = pdks_faz8j_toplu_kapi($pdo, pdks_faz8j_metin($v['depo'] ?? ''))) return ['hatalar' => [$e]] + $bos;
    $ist = pdks_faz8j_istek_id($v['istek_id'] ?? '');
    if (!$ist['ok'] || $ist['istek_id'] === null) return ['hatalar' => ['Geçersiz ya da eksik istek anahtarı; pencereyi kapatıp yeniden açın.']] + $bos;
    $istekId = $ist['istek_id'];
    if (pdks_faz8j_istek_kayitli($pdo, $istekId)) return ['hatalar' => [PDKS_FAZ8J_TEKRAR_HATA], 'tekrar' => true] + $bos;
    $h = pdks_faz8j_toplu_hazirla($pdo, $v);
    $on = pdks_faz8j_toplu_degerlendir($pdo, $h, null);
    if (!$on['ok']) { unset($on['_specs']); return $on; }
    $simdi = date('Y-m-d H:i:s');
    $specs = $on['_specs'];
    $girisler = array_column($specs, 'entry'); $cikislar = array_filter(array_column($specs, 'exit'));
    try {
        $pdo->beginTransaction();
        pdks_faz8j_oturum_kilitle($pdo, $h['foreman_id'], $h['work_date'], $h['depo']);   // İLK sorgu
        if (pdks_faz8j_istek_kayitli($pdo, $istekId)) throw new RuntimeException(PDKS_FAZ8J_TEKRAR_HATA);
        $oc = pdks_faz8j_oturum_coz($pdo, $h['foreman_id'], $h['work_date'], $h['depo'], $user, true,
            ['acilis' => min($girisler), 'kapanis' => $cikislar ? max($cikislar) : min($girisler), 'reason' => $h['reason']]);
        if (!$oc['ok']) throw new RuntimeException((string)$oc['hata']);
        $oturum = $oc['oturum']; $sid = (int)$oturum['id'];
        if (pdks_faz8j_entitlement($pdo, $sid) === 'final') {
            throw new RuntimeException('Bu mesainin kesinleşmiş hakedişi bulunmaktadır. Önce hakedişi yönetici tarafından yeniden açın.');
        }
        // Kartlar ARTAN id sırasıyla kilitlenir (eşzamanlı toplu işlemlerde kilitlenme/deadlock önlemi).
        $kilit = array_values(array_unique(array_map(fn($s) => (int)$s['kart_id'], array_filter($specs, fn($s) => !$s['kartsiz']))));
        sort($kilit, SORT_NUMERIC);
        foreach ($kilit as $cid) pdks_gunluk_faz8a_kart_kilitle($pdo, $cid);
        // Kilit ALTINDA yeniden doğrula (önizleme ile yazma arasında değişen veri:
        // kart satırları da yeniden okunur).
        $h = pdks_faz8j_toplu_hazirla($pdo, $v);
        $tekrar = pdks_faz8j_toplu_degerlendir($pdo, $h, $oturum, $oc['yeni']);
        if (!$tekrar['ok']) { $pdo->rollBack(); unset($tekrar['_specs']); return $tekrar; }
        $topluId = 'TP' . date('Ymd') . bin2hex(random_bytes(4));
        $pids = []; $kartli = 0; $kartsiz = 0;
        foreach ($tekrar['_specs'] as $s) {
            if ($s['kartsiz']) { $s['kart'] = pdks_faz8j_kartsiz_kart_olustur($pdo, (int)$s['tip']['id'], $user); $kartsiz++; } else { $kartli++; }
            $pids[] = pdks_faz8j_satir_yaz($pdo, $oturum, $s, $user,
                ['reason' => $h['reason'], 'note' => $h['note'], 'yeni_mesai' => $oc['yeni'], 'simdi' => $simdi, 'toplu_id' => $topluId, 'istek_id' => $istekId]);
        }
        pdks_faz8j_yeniden_hesap_isaretle($pdo, $sid);
        pdks_faz8j_audit($pdo, $user, 'puantaj_toplu_ekle', $sid, [], [
            'session_id' => $sid, 'toplu_id' => $topluId, 'period_ids' => $pids, 'eklenen' => count($pids),
            'kartli' => $kartli, 'kartsiz' => $kartsiz, 'ozet' => $tekrar['ozet'], 'work_date' => (string)$oturum['work_date'],
            'yeni_mesai' => $oc['yeni'], 'reason' => $h['reason'], 'note' => $h['note'], 'added_at' => $simdi, 'user_id' => $user,
            'istek_id' => $istekId,
        ], 'daily_work_sessions');
        $pdo->commit();
        unset($tekrar['_specs']);
        return ['ok' => true, 'session_id' => $sid, 'toplu_id' => $topluId, 'period_ids' => $pids, 'eklenen' => count($pids),
                'yeni_mesai' => $oc['yeni']] + $tekrar;
    } catch (Throwable $x) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        unset($on['_specs']);
        $on['ok'] = false;
        $on['hatalar'] = [($x instanceof RuntimeException && !$x instanceof PDOException) ? $x->getMessage()
            : (pdks_faz8j_eszamanli_hata($x) ? PDKS_FAZ8J_ESZAMANLI_HATA : 'Kayıt sırasında teknik bir hata oluştu. Lütfen tekrar deneyin.')];
        if ($on['hatalar'][0] === PDKS_FAZ8J_TEKRAR_HATA) $on['tekrar'] = true;
        return $on;
    }
}

/** Bir toplu kimliğin özet audit satırı (puantaj_toplu_ekle) ya da null. */
function pdks_faz8j_toplu_bul(PDO $pdo, string $topluId): ?array {
    if (!preg_match('/^TP\d{8}[0-9a-f]{8}$/D', $topluId)) return null;
    $st = $pdo->prepare("SELECT id, record_id, user_id, new_values, created_at FROM audit_log WHERE module = 'daily_work_sessions' AND action = 'puantaj_toplu_ekle' AND new_values LIKE ? ORDER BY id DESC");
    $st->execute(['%"toplu_id":"' . $topluId . '"%']);
    foreach ($st->fetchAll() as $r) {
        $nv = json_decode((string)$r['new_values'], true) ?: [];
        if (($nv['toplu_id'] ?? '') === $topluId) { $r['veri'] = $nv; return $r; }
    }
    return null;
}

/**
 * Bir mesainin toplu işlemleri (audit'ten), en yeni önce.
 * @return list<array{toplu_id:string, created_at:string, user_id:?int, kullanici:string, eklenen:int, kartli:int, kartsiz:int, aktif:int, iptal:int, period_ids:int[], reason:string, note:string, geri_alindi:bool}>
 */
function pdks_faz8j_toplu_listele(int $sessionId, ?PDO $pdo = null): array {
    $pdo = $pdo ?? db();
    if (!pdks_gunluk_tablo_var($pdo, 'audit_log')) return [];
    $st = $pdo->prepare("SELECT action, user_id, new_values, created_at FROM audit_log WHERE module = 'daily_work_sessions' AND record_id = ? AND action IN ('puantaj_toplu_ekle', 'puantaj_toplu_geri_al') ORDER BY id DESC");
    $st->execute([$sessionId]);
    $rows = $st->fetchAll(); $geriAlinan = [];
    foreach ($rows as $r) if ($r['action'] === 'puantaj_toplu_geri_al') { $nv = json_decode((string)$r['new_values'], true) ?: []; $geriAlinan[(string)($nv['toplu_id'] ?? '')] = true; }
    $out = [];
    foreach ($rows as $r) {
        if ($r['action'] !== 'puantaj_toplu_ekle') continue;
        $nv = json_decode((string)$r['new_values'], true) ?: [];
        $pids = array_values(array_filter(array_map('intval', (array)($nv['period_ids'] ?? [])), fn($x) => $x > 0));
        $iptal = 0;
        if ($pids) {
            $q = $pdo->prepare('SELECT COUNT(*) FROM daily_worker_work_periods WHERE is_voided = 1 AND id IN (' . implode(',', array_fill(0, count($pids), '?')) . ')');
            $q->execute($pids); $iptal = (int)$q->fetchColumn();
        }
        $tid = (string)($nv['toplu_id'] ?? '');
        $out[] = [
            'toplu_id' => $tid, 'created_at' => (string)$r['created_at'],
            'user_id' => $r['user_id'] !== null ? (int)$r['user_id'] : null,
            'kullanici' => pdks_gunluk_kullanici_adi($r['user_id'] !== null ? (int)$r['user_id'] : null, $pdo),
            'eklenen' => count($pids), 'kartli' => (int)($nv['kartli'] ?? 0), 'kartsiz' => (int)($nv['kartsiz'] ?? 0),
            'aktif' => count($pids) - $iptal, 'iptal' => $iptal, 'period_ids' => $pids,
            'reason' => (string)($nv['reason'] ?? ''), 'note' => (string)($nv['note'] ?? ''),
            'geri_alindi' => isset($geriAlinan[$tid]),
        ];
    }
    return $out;
}

/**
 * Bir toplu işlemi geri alır: hâlâ aktif dönemlerin HEPSİ tek transaction'da iptal
 * edilir (pdks_faz8j_void_uygula — tekil iptalle AYNI çekirdek). Zaten iptal
 * edilmiş dönemler atlanır ve sayılır. Kesinleşmiş hakediş varsa reddedilir.
 * @return array{ok:bool, hata?:string, session_id?:int, toplu_id?:string, iptal_edilen?:int, atlanan?:int, period_ids?:int[]}
 */
function pdks_faz8j_toplu_geri_al(string $topluId, string $reason, int $user, ?PDO $pdo = null): array {
    $pdo = $pdo ?? db();
    if ($e = pdks_faz8j_yetki()) return ['ok' => false, 'hata' => $e];
    if (!pdks_faz8j_sema_hazir($pdo)) return ['ok' => false, 'hata' => 'Faz 8J şeması henüz hazır değil.'];
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 500) return ['ok' => false, 'hata' => 'Geri alma nedeni zorunludur ve en fazla 500 karakter olabilir.'];
    $kayit = pdks_faz8j_toplu_bul($pdo, trim($topluId));
    if (!$kayit) return ['ok' => false, 'hata' => 'Toplu işlem bulunamadı.'];
    $sid = (int)$kayit['record_id'];
    $st = $pdo->prepare('SELECT * FROM daily_work_sessions WHERE id = ?'); $st->execute([$sid]);
    $oturum = $st->fetch();
    if (!$oturum) return ['ok' => false, 'hata' => 'Toplu işlemin mesaisi bulunamadı.'];
    $depo = (string)$oturum['depo'];
    if ($e = pdks_faz8j_aktif_depo_kontrol($depo)) return ['ok' => false, 'hata' => $e];
    $pids = array_values(array_filter(array_map('intval', (array)($kayit['veri']['period_ids'] ?? [])), fn($x) => $x > 0));
    try {
        $pdo->beginTransaction();
        if (pdks_faz8j_entitlement($pdo, $sid) === 'final') {
            throw new RuntimeException('Bu mesainin kesinleşmiş hakedişi bulunmaktadır. Önce hakedişi yönetici tarafından yeniden açın.');
        }
        $iptal = []; $atlanan = 0;
        foreach ($pids as $pid) {
            $p = pdks_faz8j_donem($pdo, $pid, $sid, $depo);
            if (!$p || (int)$p['is_voided']) { $atlanan++; continue; }
            pdks_faz8j_void_uygula($pdo, $p, $pid, $sid, $reason, $user, ['toplu_id' => $topluId]);
            $iptal[] = $pid;
        }
        if ($iptal === []) throw new RuntimeException('Bu toplu işlemdeki kayıtların hepsi zaten iptal edilmiş.');
        pdks_faz8j_audit($pdo, $user, 'puantaj_toplu_geri_al', $sid, [], [
            'session_id' => $sid, 'toplu_id' => $topluId, 'period_ids' => $iptal, 'iptal_edilen' => count($iptal),
            'atlanan' => $atlanan, 'reason' => $reason, 'voided_at' => date('Y-m-d H:i:s'), 'user_id' => $user,
        ], 'daily_work_sessions');
        $pdo->commit();
        return ['ok' => true, 'session_id' => $sid, 'toplu_id' => $topluId, 'iptal_edilen' => count($iptal), 'atlanan' => $atlanan, 'period_ids' => $iptal];
    } catch (Throwable $x) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'hata' => ($x instanceof RuntimeException && !$x instanceof PDOException) ? $x->getMessage() : 'İşlem sırasında teknik bir hata oluştu. Lütfen tekrar deneyin.'];
    }
}

// =========================================================
// v295 — KARIŞIK GİRİŞ → OTOMATİK ATA (yalnız yönetici)
// =========================================================
// Kiosk'ta "KARIŞIK" ile giren dönemler (worker_type = KARISIK) burada,
// verilen Kadın/Erkek sayısı kadar RASTGELE KADIN/ERKEK'e atanır. KISMİ atama
// serbesttir (kalan Karışık kalır). Açık (içeride) dönemler HAVUZA DAHİLDİR,
// iptal edilmiş dönemler değildir. Yalnız dönemin worker_type_id_snapshot +
// worker_type_name_snapshot alanları değişir — Tam/Yarım ve FM onayları
// KORUNUR (pdks_faz8j_duzelt BİLEREK kullanılmaz: o onayları sıfırlar), ham
// kart olay satırlarına DOKUNULMAZ. Atama kimliği 'KA' + Ymd + 8 hex; audit
// `karisik_ata` (module daily_work_sessions, record_id = mesai id) her dönemin
// eski→yeni tipini taşır; geri alma `karisik_geri_al` bu kayıttan okur.

const PDKS_FAZ8J_KARISIK_TEKRAR_HATA = 'Bu atama zaten kaydedildi (tekrar gönderim).';

/** Mesainin Karışık havuzu (salt okunur): karisik_kalan, acik (içeride), kapali. */
function pdks_faz8j_karisik_ozet(int $sessionId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $bos = ['karisik_kalan' => 0, 'acik' => 0, 'kapali' => 0];
    $kid = pdks_gunluk_karisik_tip_id($pdo);
    if ($kid === null || !pdks_gunluk_tablo_var($pdo, 'daily_worker_work_periods')) return $bos;
    $st = $pdo->prepare("SELECT SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS acik, COUNT(*) AS n
        FROM daily_worker_work_periods WHERE session_id = ? AND worker_type_id_snapshot = ? AND " . pdks_gunluk_faz8j_etkin_kosul($pdo));
    $st->execute([$sessionId, $kid]);
    $r = $st->fetch() ?: [];
    $n = (int)($r['n'] ?? 0); $acik = (int)($r['acik'] ?? 0);
    return ['karisik_kalan' => $n, 'acik' => $acik, 'kapali' => $n - $acik];
}

/** Bu istek_id ile karisik_ata yazıldı mı? */
function pdks_faz8j_karisik_istek_kayitli(PDO $pdo, string $istekId): bool
{
    $st = $pdo->prepare("SELECT 1 FROM audit_log WHERE action = 'karisik_ata' AND new_values LIKE ? LIMIT 1");
    $st->execute(['%"istek_id":"' . $istekId . '"%']);
    return (bool)$st->fetchColumn();
}

/** Mesai satırını kilitler (MySQL FOR UPDATE; SQLite tek bağlantı) ve döner. */
function pdks_faz8j_mesai_kilitle(PDO $pdo, int $sessionId): ?array
{
    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $st = $pdo->prepare('SELECT * FROM daily_work_sessions WHERE id = ?' . $lock);
    $st->execute([$sessionId]);
    return $st->fetch() ?: null;
}

/** Desteklenen KADIN/ERKEK satırı (aktif) — kod ile. */
function pdks_faz8j_tip_kodla(PDO $pdo, string $kod): ?array
{
    foreach (pdks_gunluk_desteklenen_tip_listele($pdo) as $t) if ((string)$t['code'] === $kod) return $t;
    return null;
}

/**
 * Karışık dönemleri rastgele KADIN/ERKEK'e atar.
 * @return array{ok:bool, hata?:string, tekrar?:bool, atama_id?:string, kadin?:int, erkek?:int, kalan?:int}
 */
function pdks_faz8j_karisik_ata(int $sessionId, int $kadin, int $erkek, string $reason, string $istekId, int $user, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if ($e = pdks_faz8j_yetki()) return ['ok' => false, 'hata' => $e];
    if (!pdks_faz8j_sema_hazir($pdo)) return ['ok' => false, 'hata' => 'Puantaj düzeltme şeması henüz hazır değil.'];
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 500) return ['ok' => false, 'hata' => 'Atama nedeni zorunludur ve en fazla 500 karakter olabilir.'];
    $ist = pdks_faz8j_istek_id($istekId);
    if (!$ist['ok'] || $ist['istek_id'] === null) return ['ok' => false, 'hata' => 'Geçersiz ya da eksik istek anahtarı; pencereyi kapatıp yeniden açın.'];
    $istekId = $ist['istek_id'];
    if ($kadin < 0 || $erkek < 0 || $kadin + $erkek < 1) return ['ok' => false, 'hata' => 'Kadın + Erkek sayısı en az 1 olmalıdır.'];
    $st = $pdo->prepare('SELECT * FROM daily_work_sessions WHERE id = ?'); $st->execute([$sessionId]);
    $oturum = $st->fetch();
    if (!$oturum) return ['ok' => false, 'hata' => 'Mesai bulunamadı.'];
    if ($e = pdks_faz8j_aktif_depo_kontrol((string)$oturum['depo'])) return ['ok' => false, 'hata' => $e];
    $kid = pdks_gunluk_karisik_tip_id($pdo);
    if ($kid === null) return ['ok' => false, 'hata' => 'Bu mesaide atanmamış Karışık kayıt yok.'];
    $tKadin = $kadin > 0 ? pdks_faz8j_tip_kodla($pdo, 'KADIN') : null;
    $tErkek = $erkek > 0 ? pdks_faz8j_tip_kodla($pdo, 'ERKEK') : null;
    if (($kadin > 0 && !$tKadin) || ($erkek > 0 && !$tErkek)) return ['ok' => false, 'hata' => 'Kadın/Erkek işçi tipi bulunamadı veya pasif.'];
    if (pdks_faz8j_karisik_istek_kayitli($pdo, $istekId)) return ['ok' => false, 'hata' => PDKS_FAZ8J_KARISIK_TEKRAR_HATA, 'tekrar' => true];

    try {
        $pdo->beginTransaction();
        pdks_faz8j_mesai_kilitle($pdo, $sessionId);   // İLK sorgu — bkz. pdks_faz8j_oturum_kilitle docblock
        if (pdks_faz8j_karisik_istek_kayitli($pdo, $istekId)) throw new RuntimeException(PDKS_FAZ8J_KARISIK_TEKRAR_HATA);
        if (pdks_faz8j_entitlement($pdo, $sessionId) === 'final') {
            throw new RuntimeException('Bu mesainin kesinleşmiş hakedişi bulunmaktadır. Önce hakedişi yönetici tarafından yeniden açın.');
        }
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $sp = $pdo->prepare('SELECT id, worker_type_id_snapshot, worker_type_name_snapshot FROM daily_worker_work_periods
            WHERE session_id = ? AND worker_type_id_snapshot = ? AND ' . pdks_gunluk_faz8j_etkin_kosul($pdo) . ' ORDER BY id' . $lock);
        $sp->execute([$sessionId, $kid]);
        $havuz = $sp->fetchAll();
        $n = count($havuz);
        if ($n < 1) throw new RuntimeException('Bu mesaide atanmamış Karışık kayıt yok.');
        if ($kadin + $erkek > $n) throw new RuntimeException('Kadın + Erkek (' . ($kadin + $erkek) . ') atanmamış Karışık kayıt sayısını (' . $n . ') aşıyor.');
        // Fisher–Yates (random_int — kriptografik kaynak).
        for ($i = $n - 1; $i > 0; $i--) { $j = random_int(0, $i); [$havuz[$i], $havuz[$j]] = [$havuz[$j], $havuz[$i]]; }
        $upd = $pdo->prepare('UPDATE daily_worker_work_periods SET worker_type_id_snapshot = ?, worker_type_name_snapshot = ? WHERE id = ? AND worker_type_id_snapshot = ?');
        $donemler = [];
        foreach (array_slice($havuz, 0, $kadin + $erkek) as $i => $p) {
            $hedef = $i < $kadin ? $tKadin : $tErkek;
            $upd->execute([(int)$hedef['id'], (string)$hedef['name'], (int)$p['id'], $kid]);
            if ($upd->rowCount() !== 1) throw new RuntimeException(PDKS_FAZ8J_ESZAMANLI_HATA);
            $donemler[] = ['period_id' => (int)$p['id'],
                'eski_tip_id' => (int)$p['worker_type_id_snapshot'], 'eski_tip' => (string)$p['worker_type_name_snapshot'],
                'yeni_tip_id' => (int)$hedef['id'], 'yeni_tip' => (string)$hedef['name'], 'yeni_kod' => (string)$hedef['code']];
        }
        pdks_faz8j_yeniden_hesap_isaretle($pdo, $sessionId);
        $atamaId = 'KA' . date('Ymd') . bin2hex(random_bytes(4));
        $kalan = $n - $kadin - $erkek;
        pdks_faz8j_audit($pdo, $user, 'karisik_ata', $sessionId, ['karisik_havuz' => $n], [
            'session_id' => $sessionId, 'atama_id' => $atamaId, 'istek_id' => $istekId, 'reason' => $reason,
            'kadin' => $kadin, 'erkek' => $erkek, 'havuz' => $n, 'kalan' => $kalan,
            'donemler' => $donemler, 'assigned_at' => date('Y-m-d H:i:s'), 'user_id' => $user,
        ], 'daily_work_sessions');
        $pdo->commit();
        return ['ok' => true, 'atama_id' => $atamaId, 'kadin' => $kadin, 'erkek' => $erkek, 'kalan' => $kalan];
    } catch (Throwable $x) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($x instanceof RuntimeException && !$x instanceof PDOException) {
            return ['ok' => false, 'hata' => $x->getMessage()] + ($x->getMessage() === PDKS_FAZ8J_KARISIK_TEKRAR_HATA ? ['tekrar' => true] : []);
        }
        return ['ok' => false, 'hata' => pdks_faz8j_eszamanli_hata($x) ? PDKS_FAZ8J_ESZAMANLI_HATA : 'Atama sırasında teknik bir hata oluştu. Lütfen tekrar deneyin.'];
    }
}

/** Bir atama kimliğinin audit satırı (karisik_ata) ya da null. */
function pdks_faz8j_karisik_atama_bul(PDO $pdo, string $atamaId): ?array
{
    if (!preg_match('/^KA\d{8}[0-9a-f]{8}$/D', $atamaId)) return null;
    $st = $pdo->prepare("SELECT id, record_id, user_id, new_values, created_at FROM audit_log WHERE module = 'daily_work_sessions' AND action = 'karisik_ata' AND new_values LIKE ? ORDER BY id DESC");
    $st->execute(['%"atama_id":"' . $atamaId . '"%']);
    foreach ($st->fetchAll() as $r) {
        $nv = json_decode((string)$r['new_values'], true) ?: [];
        if (($nv['atama_id'] ?? '') === $atamaId) { $r['veri'] = $nv; return $r; }
    }
    return null;
}

/** Atama zaten geri alındı mı? */
function pdks_faz8j_karisik_geri_alindi_mi(PDO $pdo, string $atamaId): bool
{
    $st = $pdo->prepare("SELECT 1 FROM audit_log WHERE module = 'daily_work_sessions' AND action = 'karisik_geri_al' AND new_values LIKE ? LIMIT 1");
    $st->execute(['%"atama_id":"' . $atamaId . '"%']);
    return (bool)$st->fetchColumn();
}

/**
 * Bir mesainin Karışık atamaları (audit'ten), en yeni önce.
 * @return list<array{atama_id:string, created_at:string, user_id:?int, kullanici:string, kadin:int, erkek:int, reason:string, geri_alindi:bool, geri_alinabilir:int}>
 */
function pdks_faz8j_karisik_atamalar(int $sessionId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_gunluk_tablo_var($pdo, 'audit_log')) return [];
    $st = $pdo->prepare("SELECT action, user_id, new_values, created_at FROM audit_log WHERE module = 'daily_work_sessions' AND record_id = ? AND action IN ('karisik_ata', 'karisik_geri_al') ORDER BY id DESC");
    $st->execute([$sessionId]);
    $rows = $st->fetchAll(); $geri = [];
    foreach ($rows as $r) if ($r['action'] === 'karisik_geri_al') { $nv = json_decode((string)$r['new_values'], true) ?: []; $geri[(string)($nv['atama_id'] ?? '')] = true; }
    $kontrol = $pdo->prepare('SELECT worker_type_id_snapshot, ' . (pdks_gunluk_faz8j_kolon_var($pdo, 'daily_worker_work_periods', 'is_voided') ? 'is_voided' : '0 AS is_voided') . ' FROM daily_worker_work_periods WHERE id = ?');
    $out = [];
    foreach ($rows as $r) {
        if ($r['action'] !== 'karisik_ata') continue;
        $nv = json_decode((string)$r['new_values'], true) ?: [];
        $aid = (string)($nv['atama_id'] ?? '');
        $geriAlindi = isset($geri[$aid]);
        $alinabilir = 0;
        if (!$geriAlindi) {
            foreach ((array)($nv['donemler'] ?? []) as $d) {
                $kontrol->execute([(int)($d['period_id'] ?? 0)]);
                $p = $kontrol->fetch();
                if ($p && !(int)$p['is_voided'] && (int)$p['worker_type_id_snapshot'] === (int)($d['yeni_tip_id'] ?? 0)) $alinabilir++;
            }
        }
        $out[] = [
            'atama_id' => $aid, 'created_at' => (string)$r['created_at'],
            'user_id' => $r['user_id'] !== null ? (int)$r['user_id'] : null,
            'kullanici' => pdks_gunluk_kullanici_adi($r['user_id'] !== null ? (int)$r['user_id'] : null, $pdo),
            'kadin' => (int)($nv['kadin'] ?? 0), 'erkek' => (int)($nv['erkek'] ?? 0),
            'reason' => (string)($nv['reason'] ?? ''), 'geri_alindi' => $geriAlindi, 'geri_alinabilir' => $alinabilir,
        ];
    }
    return $out;
}

/**
 * Bir Karışık atamasını geri alır: atamanın dönemleri, şu anki tipleri hâlâ
 * atamanın verdiği tipse KARIŞIK'a döner; elle değiştirilmiş / iptal edilmiş
 * dönemler atlanır ve sayılır. Kesinleşmiş hakediş engeller. Bir atama bir kez geri alınır.
 * @return array{ok:bool, hata?:string, session_id?:int, atama_id?:string, geri_alinan?:int, atlanan?:int}
 */
function pdks_faz8j_karisik_geri_al(string $atamaId, string $reason, int $user, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if ($e = pdks_faz8j_yetki()) return ['ok' => false, 'hata' => $e];
    if (!pdks_faz8j_sema_hazir($pdo)) return ['ok' => false, 'hata' => 'Puantaj düzeltme şeması henüz hazır değil.'];
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 500) return ['ok' => false, 'hata' => 'Geri alma nedeni zorunludur ve en fazla 500 karakter olabilir.'];
    $atamaId = trim($atamaId);
    $kayit = pdks_faz8j_karisik_atama_bul($pdo, $atamaId);
    if (!$kayit) return ['ok' => false, 'hata' => 'Atama bulunamadı.'];
    $sid = (int)$kayit['record_id'];
    $st = $pdo->prepare('SELECT * FROM daily_work_sessions WHERE id = ?'); $st->execute([$sid]);
    $oturum = $st->fetch();
    if (!$oturum) return ['ok' => false, 'hata' => 'Atamanın mesaisi bulunamadı.'];
    if ($e = pdks_faz8j_aktif_depo_kontrol((string)$oturum['depo'])) return ['ok' => false, 'hata' => $e];
    try {
        $pdo->beginTransaction();
        pdks_faz8j_mesai_kilitle($pdo, $sid);   // İLK sorgu
        if (pdks_faz8j_karisik_geri_alindi_mi($pdo, $atamaId)) throw new RuntimeException('Bu atama zaten geri alınmış.');
        if (pdks_faz8j_entitlement($pdo, $sid) === 'final') {
            throw new RuntimeException('Bu mesainin kesinleşmiş hakedişi bulunmaktadır. Önce hakedişi yönetici tarafından yeniden açın.');
        }
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $sel = $pdo->prepare('SELECT id, worker_type_id_snapshot, is_voided FROM daily_worker_work_periods WHERE id = ? AND session_id = ?' . $lock);
        $upd = $pdo->prepare('UPDATE daily_worker_work_periods SET worker_type_id_snapshot = ?, worker_type_name_snapshot = ? WHERE id = ? AND worker_type_id_snapshot = ?');
        $geri = []; $atlanan = 0;
        foreach ((array)($kayit['veri']['donemler'] ?? []) as $d) {
            $pid = (int)($d['period_id'] ?? 0); $yeni = (int)($d['yeni_tip_id'] ?? 0);
            $sel->execute([$pid, $sid]); $p = $sel->fetch();
            if (!$p || (int)$p['is_voided'] || (int)$p['worker_type_id_snapshot'] !== $yeni) { $atlanan++; continue; }
            $upd->execute([(int)$d['eski_tip_id'], (string)$d['eski_tip'], $pid, $yeni]);
            if ($upd->rowCount() === 1) $geri[] = $pid; else $atlanan++;
        }
        if ($geri === []) throw new RuntimeException('Bu atamada geri alınabilecek kayıt kalmadı (hepsi değiştirilmiş ya da iptal edilmiş).');
        pdks_faz8j_yeniden_hesap_isaretle($pdo, $sid);
        pdks_faz8j_audit($pdo, $user, 'karisik_geri_al', $sid, [], [
            'session_id' => $sid, 'atama_id' => $atamaId, 'period_ids' => $geri, 'geri_alinan' => count($geri),
            'atlanan' => $atlanan, 'reason' => $reason, 'reverted_at' => date('Y-m-d H:i:s'), 'user_id' => $user,
        ], 'daily_work_sessions');
        $pdo->commit();
        return ['ok' => true, 'session_id' => $sid, 'atama_id' => $atamaId, 'geri_alinan' => count($geri), 'atlanan' => $atlanan];
    } catch (Throwable $x) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'hata' => ($x instanceof RuntimeException && !$x instanceof PDOException) ? $x->getMessage() : 'İşlem sırasında teknik bir hata oluştu. Lütfen tekrar deneyin.'];
    }
}

// =========================================================
// v302 — TOPLU DÜZENLE / TOPLU İPTAL (Kart Hareketleri, yalnız yönetici)
// =========================================================
// Mesai Detayı'nda SEÇİLEN dönemlerin tipi / giriş / çıkış saati tek seferde
// düzeltilir ya da iptal edilir. İKİNCİ YAZMA YOLU YOK:
//   düzeltme → pdks_faz8j_duzelt_satir() (tekil Düzenle ile AYNI çekirdek)
//   iptal    → pdks_faz8j_void_uygula()  (tekil İptal ile AYNI çekirdek)
// Kurallar: HEP-YA-HİÇ tek transaction; mesai kilidi tx'in İLK sorgusu;
// istek_id (tekrar gönderim) zorunlu; kesin hakediş → ret; kart DEĞİŞMEZ;
// giriş günü = mesainin work_date'i (istemciden tarih alınmaz); DEĞİŞMEYEN
// satır (tip + giriş + çıkış dakika düzeyinde aynı) ATLANIR — yazılmaz,
// Tam/Yarım/FM onayları sıfırlanmaz. Satır hataları toplanır; biri bile
// hatalıysa rollback, hiçbir şey yazılmaz.

const PDKS_FAZ8J_TOPLU_DUZELT_TEKRAR_HATA = 'Bu toplu işlem zaten kaydedildi (tekrar gönderim).';

/** Bu istek_id ile verilen audit eylemlerinden biri yazıldı mı? (eylem bazlı tekrar gönderim yardımcısı) */
function pdks_faz8j_istek_kayitli_eylem(PDO $pdo, string $istekId, array $eylemler): bool {
    if ($eylemler === []) return false;
    $st = $pdo->prepare('SELECT 1 FROM audit_log WHERE action IN (' . implode(',', array_fill(0, count($eylemler), '?')) . ') AND new_values LIKE ? LIMIT 1');
    $st->execute(array_merge(array_values($eylemler), ['%"istek_id":"' . $istekId . '"%']));
    return (bool)$st->fetchColumn();
}

/**
 * Toplu düzenle/iptal ortak giriş kapısı (salt okunur). Başarıda mesai satırı + istek_id döner.
 * @return array{hata:?string, oturum?:array, istek_id?:string, tekrar?:bool}
 */
function pdks_faz8j_toplu_secim_kapi(PDO $pdo, int $sessionId, array $periodIds, string $reason, string $istekId, string $nedenEtiketi = 'Düzeltme'): array {
    if ($e = pdks_faz8j_yetki()) return ['hata' => $e];
    if (!pdks_faz8j_sema_hazir($pdo)) return ['hata' => 'Faz 8J şeması henüz hazır değil.'];
    if ($reason === '' || mb_strlen($reason) > 500) return ['hata' => $nedenEtiketi . ' nedeni zorunludur ve en fazla 500 karakter olabilir.'];
    $ist = pdks_faz8j_istek_id($istekId);
    if (!$ist['ok'] || $ist['istek_id'] === null) return ['hata' => 'Geçersiz ya da eksik istek anahtarı; pencereyi kapatıp yeniden açın.'];
    if ($periodIds === []) return ['hata' => 'En az bir kayıt seçin.'];
    if (count($periodIds) > PDKS_FAZ8J_TOPLU_LIMIT) return ['hata' => 'Tek seferde en fazla ' . PDKS_FAZ8J_TOPLU_LIMIT . ' kayıt işlenebilir.'];
    foreach ($periodIds as $pid) if ($pid <= 0) return ['hata' => 'Geçersiz kayıt seçimi.'];
    if (count(array_unique($periodIds)) !== count($periodIds)) return ['hata' => 'Aynı kayıt birden fazla kez seçilmiş.'];
    $st = $pdo->prepare('SELECT * FROM daily_work_sessions WHERE id = ?'); $st->execute([$sessionId]);
    $oturum = $st->fetch();
    if (!$oturum) return ['hata' => 'Mesai bulunamadı.'];
    if ($e = pdks_faz8j_aktif_depo_kontrol((string)$oturum['depo'])) return ['hata' => $e];
    if (pdks_faz8j_istek_kayitli_eylem($pdo, $ist['istek_id'], ['puantaj_toplu_duzeltme', 'puantaj_toplu_iptal'])) {
        return ['hata' => PDKS_FAZ8J_TOPLU_DUZELT_TEKRAR_HATA, 'tekrar' => true];
    }
    return ['hata' => null, 'oturum' => $oturum, 'istek_id' => $ist['istek_id']];
}

/** Toplu düzenle/iptal hata dönüşü (Throwable → kullanıcı mesajı). */
function pdks_faz8j_toplu_secim_hata(Throwable $x): array {
    if ($x instanceof RuntimeException && !$x instanceof PDOException) {
        return ['ok' => false, 'hata' => $x->getMessage(), 'hatalar' => []] + ($x->getMessage() === PDKS_FAZ8J_TOPLU_DUZELT_TEKRAR_HATA ? ['tekrar' => true] : []);
    }
    return ['ok' => false, 'hata' => pdks_faz8j_eszamanli_hata($x) ? PDKS_FAZ8J_ESZAMANLI_HATA : 'İşlem sırasında teknik bir hata oluştu. Lütfen tekrar deneyin.', 'hatalar' => []];
}

/**
 * Seçilen dönemleri toplu düzeltir (tip + giriş/çıkış saati; kart DEĞİŞMEZ).
 * $satirlar: [['period_id'=>int,'worker_type_id'=>int,'entry_clock'=>'HH:MM','exit_date'=>'YYYY-MM-DD'|'','exit_clock'=>'HH:MM'|''], …]
 * @return array{ok:bool, guncellenen?:int, atlanan?:int, toplu_id?:string, period_ids?:int[], hata?:string, hatalar?:array, tekrar?:bool}
 */
function pdks_faz8j_toplu_duzelt(int $sessionId, array $satirlar, string $reason, string $note, string $istekId, int $user, ?PDO $pdo = null): array {
    $pdo = $pdo ?? db();
    $reason = trim($reason); $note = trim($note);
    $norm = [];
    foreach ($satirlar as $s) {
        $s = is_array($s) ? $s : [];
        $norm[] = [
            'period_id' => is_scalar($s['period_id'] ?? null) ? (int)$s['period_id'] : 0,
            'worker_type_id' => is_scalar($s['worker_type_id'] ?? null) ? (int)$s['worker_type_id'] : 0,
            'entry_clock' => pdks_faz8j_metin($s['entry_clock'] ?? ''),
            'exit_date' => pdks_faz8j_metin($s['exit_date'] ?? ''), 'exit_clock' => pdks_faz8j_metin($s['exit_clock'] ?? ''),
        ];
    }
    $kapi = pdks_faz8j_toplu_secim_kapi($pdo, $sessionId, array_column($norm, 'period_id'), $reason, $istekId);
    if ($kapi['hata'] !== null) return ['ok' => false, 'hata' => $kapi['hata'], 'hatalar' => []] + (!empty($kapi['tekrar']) ? ['tekrar' => true] : []);
    if (mb_strlen($note) > 1000) return ['ok' => false, 'hata' => 'Açıklama en fazla 1000 karakter olabilir.', 'hatalar' => []];
    $istekId = $kapi['istek_id']; $depo = (string)$kapi['oturum']['depo'];
    // Dönem kilitleri ARTAN id sırasıyla alınır (eşzamanlı işlemlerde deadlock önlemi).
    usort($norm, fn($a, $b) => $a['period_id'] <=> $b['period_id']);
    try {
        $pdo->beginTransaction();
        $oturum = pdks_faz8j_mesai_kilitle($pdo, $sessionId);   // İLK sorgu — bkz. pdks_faz8j_oturum_kilitle docblock
        if (!$oturum) throw new RuntimeException('Mesai bulunamadı.');
        if (pdks_faz8j_istek_kayitli_eylem($pdo, $istekId, ['puantaj_toplu_duzeltme', 'puantaj_toplu_iptal'])) throw new RuntimeException(PDKS_FAZ8J_TOPLU_DUZELT_TEKRAR_HATA);
        if (pdks_faz8j_entitlement($pdo, $sessionId) === 'final') {
            throw new RuntimeException('Bu mesainin kesinleşmiş hakedişi bulunmaktadır. Önce hakedişi yönetici tarafından yeniden açın.');
        }
        $workDate = (string)$oturum['work_date'];
        $topluId = 'TD' . date('Ymd') . bin2hex(random_bytes(4));
        $hatalar = []; $guncellenen = []; $atlanan = 0;
        foreach ($norm as $s) {
            $pid = $s['period_id'];
            $p = pdks_faz8j_donem($pdo, $pid, $sessionId, $depo);
            $satirHata = static fn(string $m) => ['period_id' => $pid, 'card_no' => (string)($p['card_no'] ?? ''), 'hata' => $m];
            if (!$p || (int)$p['is_voided']) { $hatalar[] = $satirHata('Mesai dönemi bu mesaide bulunamadı veya iptal edilmiş.'); continue; }
            $entry = pdks_faz8j_zaman($workDate, $s['entry_clock']);
            if ($entry === null) { $hatalar[] = $satirHata('Geçerli bir giriş saati girin.'); continue; }
            $cikis = pdks_faz8j_cikis_zamani($s);
            if (!$cikis['ok']) { $hatalar[] = $satirHata((string)$cikis['hata']); continue; }
            $exit = $cikis['exit'];
            if ($s['worker_type_id'] <= 0) { $hatalar[] = $satirHata('İşçi tipi seçin.'); continue; }
            // Dakika düzeyinde aynı olan zaman ESKİ değeriyle (saniyesiyle) korunur —
            // yalnız tip değişen satırda kiosk saniyesi kırpılmaz.
            $eskiEntry = (string)$p['entry_time']; $eskiExit = $p['exit_time'] !== null ? (string)$p['exit_time'] : null;
            if (substr($eskiEntry, 0, 16) === substr($entry, 0, 16)) $entry = $eskiEntry;
            if ($exit !== null && $eskiExit !== null && substr($eskiExit, 0, 16) === substr($exit, 0, 16)) $exit = $eskiExit;
            if ($s['worker_type_id'] === (int)$p['worker_type_id_snapshot'] && $entry === $eskiEntry && $exit === $eskiExit) { $atlanan++; continue; }
            // Önceki bir satır hatalı olsa da bu satır çekirdekten geçirilir: hatası da
            // raporlansın diye (yazdığı her şey sonda rollback ile geri alınır).
            try {
                pdks_faz8j_duzelt_satir($pdo, $p, $sessionId, $depo, (int)$p['worker_card_id'], $s['worker_type_id'], $entry, $exit,
                    $reason, $note, $user, ['toplu_id' => $topluId]);
                $guncellenen[] = $pid;
            } catch (PDOException $x) {
                throw $x;
            } catch (RuntimeException $x) {
                $hatalar[] = $satirHata($x->getMessage());
            }
        }
        if ($hatalar !== []) {
            $pdo->rollBack();
            return ['ok' => false, 'hata' => count($hatalar) . ' satırda hata var; hiçbir değişiklik kaydedilmedi.', 'hatalar' => $hatalar];
        }
        if ($guncellenen === []) throw new RuntimeException('Seçilen kayıtlarda değişiklik yok.');
        pdks_faz8j_audit($pdo, $user, 'puantaj_toplu_duzeltme', $sessionId, [], [
            'session_id' => $sessionId, 'toplu_id' => $topluId, 'istek_id' => $istekId, 'period_ids' => $guncellenen,
            'guncellenen' => count($guncellenen), 'atlanan' => $atlanan, 'reason' => $reason, 'note' => $note,
            'corrected_at' => date('Y-m-d H:i:s'), 'user_id' => $user,
        ], 'daily_work_sessions');
        $pdo->commit();
        return ['ok' => true, 'guncellenen' => count($guncellenen), 'atlanan' => $atlanan, 'toplu_id' => $topluId, 'period_ids' => $guncellenen];
    } catch (Throwable $x) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return pdks_faz8j_toplu_secim_hata($x);
    }
}

/**
 * Seçilen dönemleri toplu iptal eder (pdks_faz8j_void_uygula — tekil iptalle AYNI çekirdek).
 * Zaten iptal edilmiş dönem atlanır ve sayılır; bu mesaide olmayan dönem satır hatasıdır.
 * @return array{ok:bool, iptal_edilen?:int, atlanan?:int, toplu_id?:string, period_ids?:int[], hata?:string, hatalar?:array, tekrar?:bool}
 */
function pdks_faz8j_toplu_iptal(int $sessionId, array $periodIds, string $reason, string $istekId, int $user, ?PDO $pdo = null): array {
    $pdo = $pdo ?? db();
    $reason = trim($reason);
    $ids = array_map(static fn($x) => is_scalar($x) ? (int)$x : 0, array_values($periodIds));
    $kapi = pdks_faz8j_toplu_secim_kapi($pdo, $sessionId, $ids, $reason, $istekId, 'İptal');
    if ($kapi['hata'] !== null) return ['ok' => false, 'hata' => $kapi['hata'], 'hatalar' => []] + (!empty($kapi['tekrar']) ? ['tekrar' => true] : []);
    $istekId = $kapi['istek_id']; $depo = (string)$kapi['oturum']['depo'];
    sort($ids, SORT_NUMERIC);
    try {
        $pdo->beginTransaction();
        if (!pdks_faz8j_mesai_kilitle($pdo, $sessionId)) throw new RuntimeException('Mesai bulunamadı.');   // İLK sorgu
        if (pdks_faz8j_istek_kayitli_eylem($pdo, $istekId, ['puantaj_toplu_duzeltme', 'puantaj_toplu_iptal'])) throw new RuntimeException(PDKS_FAZ8J_TOPLU_DUZELT_TEKRAR_HATA);
        if (pdks_faz8j_entitlement($pdo, $sessionId) === 'final') {
            throw new RuntimeException('Bu mesainin kesinleşmiş hakedişi bulunmaktadır. Önce hakedişi yönetici tarafından yeniden açın.');
        }
        $topluId = 'TI' . date('Ymd') . bin2hex(random_bytes(4));
        $hatalar = []; $iptal = []; $atlanan = 0;
        foreach ($ids as $pid) {
            $p = pdks_faz8j_donem($pdo, $pid, $sessionId, $depo);
            if (!$p) { $hatalar[] = ['period_id' => $pid, 'card_no' => '', 'hata' => 'Mesai dönemi bu mesaide bulunamadı.']; continue; }
            if ((int)$p['is_voided']) { $atlanan++; continue; }
            if ($hatalar !== []) continue;   // hata varsa yazma boşunadır (rollback) — yalnız diğer satırlar denetlenir
            pdks_faz8j_void_uygula($pdo, $p, $pid, $sessionId, $reason, $user, ['toplu_id' => $topluId]);
            $iptal[] = $pid;
        }
        if ($hatalar !== []) {
            $pdo->rollBack();
            return ['ok' => false, 'hata' => count($hatalar) . ' satırda hata var; hiçbir kayıt iptal edilmedi.', 'hatalar' => $hatalar];
        }
        if ($iptal === []) throw new RuntimeException('Seçilen kayıtların hepsi zaten iptal edilmiş.');
        pdks_faz8j_audit($pdo, $user, 'puantaj_toplu_iptal', $sessionId, [], [
            'session_id' => $sessionId, 'toplu_id' => $topluId, 'istek_id' => $istekId, 'period_ids' => $iptal,
            'iptal_edilen' => count($iptal), 'atlanan' => $atlanan, 'reason' => $reason,
            'voided_at' => date('Y-m-d H:i:s'), 'user_id' => $user,
        ], 'daily_work_sessions');
        $pdo->commit();
        return ['ok' => true, 'iptal_edilen' => count($iptal), 'atlanan' => $atlanan, 'toplu_id' => $topluId, 'period_ids' => $iptal];
    } catch (Throwable $x) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return pdks_faz8j_toplu_secim_hata($x);
    }
}
