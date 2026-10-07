<?php
// =========================================================
// config/mail_sync.php — IMAP → veritabanı senkron motoru (M2)
//
// DEĞİŞMEZLER (docs/MAIL_CENTER_AGENT_BRIDGE.md AD-2):
//  • UNSEEN KULLANILMAZ; alma kararı sunucudaki okundu bayrağına BAĞLI DEĞİL.
//    Başka cihazdan okunmuş mail de alınır. Okundu bilgisi yalnız imap_seen olarak saklanır.
//  • Tekil anahtar (account_id, folder, uidvalidity, uid) + (account_id, folder, message_id_hash):
//    aynı mail iki kez DB'ye girmez (çift cron, yeniden deneme, UIDVALIDITY sıfırlanması).
//  • BODY.PEEK + EXAMINE: sunucuda hiçbir şey değiştirilmez.
//  • İmleç (last_uid) yalnız İŞLENMİŞ mesajların ardından ilerler; ağ kopması imleci geriye
//    ALMAZ ve işlenmiş veriyi bozmaz; yeniden çalışınca kaldığı yerden devam eder.
//  • UIDVALIDITY değişirse eski satırlar SİLİNMEZ; yeni dönem Message-ID ile süzülerek taranır.
//  • Bir hesap bozulursa diğerleri çalışır (mail_sync_tum).
// =========================================================
declare(strict_types=1);

const MAIL_SYNC_PARTI        = 20;
const MAIL_SYNC_RUN_LIMIT    = 200;        // bir çalıştırmada hesap başına en çok mesaj
const MAIL_SYNC_MAX_MESAJ    = 10485760;   // 10 MB üstü: yalnız başlık + ilk 256 KB gövde

// ── Kilit / depolama ────────────────────────────────────────────────────

function mail_depo_dizini(): string
{
    $d = defined('MAIL_STORAGE_DIR') ? (string)MAIL_STORAGE_DIR : dirname(__DIR__) . '/storage/mail';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    $ht = $d . '/.htaccess';
    if (is_dir($d) && !is_file($ht)) {
        @file_put_contents($ht, "Options -Indexes\nRequire all denied\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return $d;
}

/** @return resource|null alınamazsa (başka süreç tutuyor) null */
function mail_kilit_al(string $ad, ?string $dizin = null)
{
    if (!preg_match('/^[a-z0-9_]+$/', $ad)) return null;
    $dizin = $dizin ?? mail_depo_dizini();
    $fh = @fopen($dizin . '/.' . $ad . '.lock', 'c');
    if (!$fh) return null;
    if (!@flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return null; }
    return $fh;
}

function mail_kilit_birak($fh): void
{
    if (is_resource($fh)) { @flock($fh, LOCK_UN); @fclose($fh); }
}

// ── Thread ──────────────────────────────────────────────────────────────

/**
 * Mesajın thread'ini bulur/oluşturur. Başlık tabanlı (konu tahmini YOK — yanlış birleştirme riski).
 * 1) In-Reply-To / References'taki bir mesaj bu hesapta varsa onun thread'i.
 * 2) Yoksa anahtar = sha1(kök Message-ID): kök = References'ın ilki, yoksa In-Reply-To, yoksa kendi id'si.
 *    Cevap, ebeveyninden ÖNCE gelirse bile ebeveyn sonradan aynı anahtara düşer.
 */
function mail_thread_coz(PDO $pdo, int $hesapId, array $m, string $alindi): int
{
    $adaylar = [];
    if (!empty($m['in_reply_to'])) $adaylar[] = $m['in_reply_to'];
    foreach (array_reverse((array)$m['references']) as $r) $adaylar[] = $r;
    $adaylar = array_values(array_unique($adaylar));
    if ($adaylar) {
        $hash = array_map(static fn($id) => sha1('id:' . $id), array_slice($adaylar, 0, 31));
        $in = implode(',', array_fill(0, count($hash), '?'));
        $st = $pdo->prepare("SELECT thread_id FROM mail_messages WHERE account_id = ? AND thread_id IS NOT NULL AND message_id_hash IN ($in) ORDER BY id LIMIT 1");
        $st->execute(array_merge([$hesapId], $hash));
        $t = $st->fetchColumn();
        if ($t !== false) return (int)$t;
    }
    $kok = ((array)$m['references'])[0] ?? ($m['in_reply_to'] ?? ($m['message_id'] ?? null));
    $anahtar = sha1($kok !== null ? 'id:' . $kok : 'hash:' . $m['message_id_hash']);
    $st = $pdo->prepare('SELECT id FROM mail_threads WHERE account_id = ? AND thread_key = ?');
    $st->execute([$hesapId, $anahtar]);
    $t = $st->fetchColumn();
    if ($t !== false) return (int)$t;
    $konuNorm = mb_substr(mail_konu_norm((string)$m['subject']), 0, 255);
    $pdo->prepare('INSERT INTO mail_threads (account_id, thread_key, subject_norm, last_message_at, message_count) VALUES (?, ?, ?, ?, 0)')
        ->execute([$hesapId, $anahtar, $konuNorm, $alindi]);
    return (int)$pdo->lastInsertId();
}

/** "Re: Fwd: AW: SV: Ответ: Konu" → "Konu" (yalnız görüntü/arama; thread kararına KATILMAZ). */
function mail_konu_norm(string $s): string
{
    $s = trim($s);
    for ($i = 0; $i < 10; $i++) {
        $y = preg_replace('/^\s*(re|fwd?|aw|sv|wg|tr|ref|ответ|отв|回复)\s*(\[\d+\])?\s*:\s*/iu', '', $s);
        if ($y === null || $y === $s) break;
        $s = $y;
    }
    return trim($s);
}

// ── Kayıt ──

/** Adres listesini TEXT (65 535 bayt) sınırı içinde JSON'a çevirir: önce 60 kayıt, sığmazsa isimler atılır. */
function mail_adres_json(array $liste): string
{
    $liste = array_slice($liste, 0, 60);
    $j = json_encode($liste, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($j !== false && strlen($j) <= 60000) return $j;
    $j = json_encode(array_map(static fn($a) => ['name' => '', 'email' => $a['email'] ?? ''], $liste), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    return ($j !== false && strlen($j) <= 60000) ? $j : '[]';
}

/** Ayrıştırılamayan / kaydedilemeyen mesaj için görünür yer tutucu (zehirli mail hesabı durduramaz). */
function mail_mesaj_stub(int $hesapId, string $klasor, int $uidv, int $uid, int $boyut, string $konu, string $ek = ''): array
{
    return ['message_id' => null, 'message_id_hash' => sha1('stub:' . $ek . ':' . $hesapId . ':' . $klasor . ':' . $uidv . ':' . $uid),
        'in_reply_to' => null, 'references' => [], 'from_addr' => '', 'from_name' => '', 'reply_to_addr' => '', 'to' => [], 'cc' => [],
        'subject' => $konu, 'date' => null, 'body_text' => '', 'body_html_safe' => '', 'body_truncated' => 1,
        'attachments' => [], 'size' => $boyut];
}

/** INTERNALDATE ("01-Oct-2026 10:00:00 +0000") → yerel 'Y-m-d H:i:s' ya da null. */
function mail_ic_tarih(?string $s): ?string
{
    if ($s === null || $s === '') return null;
    return mail_mime_tarih(strtotime($s));
}

/**
 * Tek mesajı yazar. @return 'eklendi'|'tekrar'
 * @param array $hesap hesap satırı (id, email, translate_enabled …)
 */
function mail_mesaj_kaydet(PDO $pdo, array $hesap, string $klasor, int $uidvalidity, int $uid, array $m, array $meta, bool $ilkTarama, string $simdi, ?int $oncekiEpoch = null): string
{
    $hid = (int)$hesap['id'];
    $st = $pdo->prepare('SELECT id FROM mail_messages WHERE account_id = ? AND folder = ? AND uidvalidity = ? AND uid = ?');
    $st->execute([$hid, $klasor, $uidvalidity, $uid]);
    if ($st->fetchColumn() !== false) return 'tekrar';

    // Dönem (UIDVALIDITY) sıfırlanması: aynı mesaj yeni UID'le geri gelir. Çiftleme YALNIZ bir geri tarama sürerken
    // ($oncekiEpoch dolu) ve YALNIZ bir önceki dönemin satırlarına karşı yapılır; eşleşen satır yeni (uidvalidity,uid)'e
    // TAŞINIR → aynı satır bir daha eşleşemez. Normal çalışmada hiçbir Message-ID dedupe'u YOKTUR: aynı dönemde aynı
    // Message-ID'yi taşıyan farklı UID'li mesajlar sunucuda AYRI mesajlardır ve sessizce atılmamalı (bozuk gönderici
    // betikleri sabit Message-ID basar; yeni mailin eski satırın UID'sini ele geçirmesi de engellenir).
    if ($oncekiEpoch !== null) {
        $st = $pdo->prepare('SELECT id FROM mail_messages WHERE account_id = ? AND folder = ? AND message_id_hash = ? AND uidvalidity = ? ORDER BY id LIMIT 1');
        $st->execute([$hid, $klasor, $m['message_id_hash'], $oncekiEpoch]);
        $var = $st->fetchColumn();
        if ($var !== false) {
            $pdo->prepare('UPDATE mail_messages SET uidvalidity = ?, uid = ? WHERE id = ?')->execute([$uidvalidity, $uid, (int)$var]);
            return 'tekrar';
        }
    }

    $alindi = mail_ic_tarih($meta['date'] ?? null) ?? ($m['date'] ?? null) ?? $simdi;
    // Dil YEREL tespit edilir (sunucudan çıkmaz). Mail zaten hedef dildeyse çeviri kuyruğuna hiç girmez.
    $dil = mail_dil_tespit((string)$m['body_text']);
    $hedefDil = (string)($hesap['target_lang'] ?? 'tr');
    $trDurum = empty($hesap['translate_enabled']) || ($dil !== null && $dil === $hedefDil) ? 'skipped' : 'pending';
    $seen = in_array('\\Seen', (array)($meta['flags'] ?? []), true) ? 1 : 0;
    $kendi = $m['from_addr'] !== '' && strtolower($m['from_addr']) === strtolower((string)$hesap['email']);
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $thread = mail_thread_coz($pdo, $hid, $m, $alindi);
        $pdo->prepare('INSERT INTO mail_messages
            (account_id, thread_id, folder, uidvalidity, uid, message_id, message_id_hash, in_reply_to, references_hdr,
             from_addr, from_name, reply_to_addr, to_addrs, cc_addrs, subject, date_header, received_at,
             body_text, body_html_safe, body_truncated, tr_status, lang, attachments_json, has_attachments, size_bytes,
             imap_seen, is_read, needs_reply)
            VALUES (?,?,?,?,?,?,?,?,?, ?,?,?,?,?,?,?,?, ?,?,?,?,?,?,?,?, ?,?,?)')
            ->execute([
                $hid, $thread, $klasor, $uidvalidity, $uid,
                $m['message_id'] !== null ? mb_substr($m['message_id'], 0, 500) : null, $m['message_id_hash'],
                $m['in_reply_to'] !== null ? mb_substr($m['in_reply_to'], 0, 500) : null, implode(' ', (array)$m['references']),
                mb_substr($m['from_addr'], 0, 255), mb_substr($m['from_name'], 0, 255), mb_substr($m['reply_to_addr'], 0, 255),
                mail_adres_json($m['to']), mail_adres_json($m['cc']),
                $m['subject'], $m['date'], $alindi,
                $m['body_text'], $m['body_html_safe'] !== '' ? $m['body_html_safe'] : null, (int)$m['body_truncated'],
                $trDurum, $dil,
                $m['attachments'] ? (json_encode($m['attachments'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: null) : null, $m['attachments'] ? 1 : 0, (int)$m['size'],
                $seen, $ilkTarama ? $seen : 0, $kendi ? 0 : 1,
            ]);
        $pdo->prepare('UPDATE mail_threads SET message_count = message_count + 1,
            last_message_at = CASE WHEN last_message_at IS NULL OR last_message_at < ? THEN ? ELSE last_message_at END WHERE id = ?')
            ->execute([$alindi, $alindi, $thread]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return 'eklendi';
}

// ── Durum + günlük ──────────────────────────────────────────────────────

function mail_sync_durum_oku(PDO $pdo, int $hesapId, string $klasor): array
{
    $st = $pdo->prepare('SELECT * FROM mail_sync_state WHERE account_id = ? AND folder = ?');
    $st->execute([$hesapId, $klasor]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) return $r;
    $pdo->prepare('INSERT INTO mail_sync_state (account_id, folder, last_uid) VALUES (?, ?, 0)')->execute([$hesapId, $klasor]);
    return ['account_id' => $hesapId, 'folder' => $klasor, 'uidvalidity' => null, 'last_uid' => 0, 'consecutive_failures' => 0];
}

function mail_sync_imlec_yaz(PDO $pdo, int $hesapId, string $klasor, int $uidvalidity, int $sonUid, string $simdi): void
{
    $pdo->prepare('UPDATE mail_sync_state SET uidvalidity = ?, last_uid = ?, last_sync_at = ? WHERE account_id = ? AND folder = ?')
        ->execute([$uidvalidity, $sonUid, $simdi, $hesapId, $klasor]);
}

/** Hata metnini DB/log için güvenli ve kısa tutar. */
function mail_hata_metni(Throwable $e): string
{
    $kind = ($e instanceof MailImapException || $e instanceof MailSmtpException) ? $e->kind : (get_class($e) === 'RuntimeException' ? 'runtime' : 'diger');
    if ($e instanceof PDOException) return 'Veritabanı hatası (ayrıntı sunucu günlüğünde).';
    return mb_substr(mail_redact($kind . ': ' . $e->getMessage()), 0, 250);
}

// ── Ana akış ────────────────────────────────────────────────────────────

/**
 * Tek hesabı senkronlar.
 * @param array $opt istemci: callable(array $hesapCred): MailImapClient · kilit_dizin · sure (sn) · simdi (ts) · limit
 * @return array{ok:bool,busy:bool,fetched:int,inserted:int,skipped:int,kalan:int,error:?string,notlar:list<string>}
 */
function mail_sync_hesap(PDO $pdo, int $hesapId, array $opt = []): array
{
    $s = ['ok' => false, 'busy' => false, 'fetched' => 0, 'inserted' => 0, 'skipped' => 0, 'kalan' => 0, 'error' => null, 'notlar' => []];
    $simdiTs = (int)($opt['simdi'] ?? time());
    $simdi = date('Y-m-d H:i:s', $simdiTs);
    $kilit = mail_kilit_al('account_' . $hesapId, $opt['kilit_dizin'] ?? null);
    if ($kilit === null) { $s['busy'] = true; $s['ok'] = true; return $s; }

    $logId = 0; $istemci = null; $klasor = 'INBOX'; $uidv = 0; $imlec = null;
    try {
        $pdo->prepare('INSERT INTO mail_sync_log (account_id, started_at, status) VALUES (?, ?, ?)')->execute([$hesapId, $simdi, 'running']);
        $logId = (int)$pdo->lastInsertId();

        // Durum satırı bağlantıdan ÖNCE var olmalı: kimlik doğrulama/bağlantı hatası da sayaca işlenebilsin.
        $kf = $pdo->prepare('SELECT sync_folder FROM mail_accounts WHERE id = ?');
        $kf->execute([$hesapId]);
        $kfv = $kf->fetchColumn();
        if ($kfv !== false) { $klasor = (string)$kfv; mail_sync_durum_oku($pdo, $hesapId, $klasor); }
        $h = mail_hesap_cred_oku($hesapId, $pdo);
        if ($h === null) throw new RuntimeException('Kimlik bilgisi çözülemedi (MAIL_MASTER_KEY eksik/yanlış ya da hesap yok).');
        if ((int)$h['is_active'] !== 1) { $s['notlar'][] = 'Hesap pasif — atlandı.'; $s['ok'] = true; throw new MailSyncAtla(); }
        $klasor = (string)$h['sync_folder'];
        $limit = (int)($opt['limit'] ?? MAIL_SYNC_RUN_LIMIT);
        $bitis = microtime(true) + (float)($opt['sure'] ?? 100.0);

        $istemci = isset($opt['istemci']) ? ($opt['istemci'])($h) : mail_imap_baglan($h);
        $k = $istemci->klasorAc($klasor);
        $uidv = $k['uidvalidity'];
        $durum = mail_sync_durum_oku($pdo, $hesapId, $klasor);
        $yeniEpoch = $durum['uidvalidity'] !== null && (int)$durum['uidvalidity'] !== $uidv;
        $sonUid = $yeniEpoch ? 0 : (int)$durum['last_uid'];
        // Geri tarama bayrağı KALICIDIR (state.rescan_from_epoch): tarama birkaç çalıştırmaya yayılsa da, bitene kadar
        // yalnız önceki dönemin satırlarına karşı çiftleme yapılır; tarama tamamlanınca temizlenir.
        $oncekiEpoch = $yeniEpoch ? (int)$durum['uidvalidity'] : (isset($durum['rescan_from_epoch']) && $durum['rescan_from_epoch'] !== null ? (int)$durum['rescan_from_epoch'] : null);
        if ($yeniEpoch) {
            $s['notlar'][] = 'UIDVALIDITY değişti: eski kayıtlar korundu, klasör yeniden tarandı (Message-ID ile çiftleme engellendi).';
            $pdo->prepare('UPDATE mail_sync_state SET rescan_from_epoch = ? WHERE account_id = ? AND folder = ?')->execute([$oncekiEpoch, $hesapId, $klasor]);
        }
        $ilkTarama = $sonUid === 0;
        $imlec = $sonUid;

        if ($ilkTarama) {
            $pencere = $simdiTs - ((int)$h['initial_days']) * 86400;
            // Dönem değişimi kesinti sonrasına denk gelebilir (süresi dolan şifre + sunucu taşıma): son BAŞARILI
            // senkrondan 1 gün öncesine kadar geri git, yoksa aradaki mailler kalıcı kaybolur.
            if ($yeniEpoch && !empty($durum['last_ok_at']) && ($okTs = strtotime((string)$durum['last_ok_at'])) !== false) {
                $pencere = min($pencere, $okTs - 86400);
            }
            $uids = $istemci->uidAra('SINCE ' . mail_imap_tarih($pencere));
        } elseif ($k['uidnext'] > 0 && $k['uidnext'] - 1 <= $sonUid) {
            $uids = [];   // sunucuda yeni UID yok
        } else {
            // "UID n:*" tuzağı: n son UID'den büyükse sunucu YİNE son mesajı döndürür → süz.
            $uids = array_values(array_filter($istemci->uidAra('UID ' . ($sonUid + 1) . ':*'), static fn($u) => $u > $sonUid));
        }
        $s['kalan'] = max(0, count($uids) - $limit);
        $uids = array_slice($uids, 0, $limit);

        foreach (array_chunk($uids, MAIL_SYNC_PARTI) as $parti) {
            if (microtime(true) > $bitis) { $s['kalan'] += count($parti); $s['notlar'][] = 'Zaman bütçesi doldu; kalanı sonraki çalıştırmada.'; break; }
            $meta = $istemci->uidMeta($parti);
            foreach ($parti as $uid) {
                $ms = $meta[$uid] ?? null;
                $s['fetched']++;
                if ($ms === null) { $s['skipped']++; $imlec = max($imlec, $uid); continue; }   // arada silinmiş
                $r = $istemci->uidMesaj($uid, (int)$ms['size'], MAIL_SYNC_MAX_MESAJ);
                if ($r === null) { $s['skipped']++; $imlec = max($imlec, $uid); continue; }
                try {
                    $m = mail_mime_mesaj($r['raw'], $r['truncated']);
                } catch (Throwable $e) {
                    error_log('[mail_sync] ayrıştırma hatası uid=' . $uid . ': ' . mail_redact($e->getMessage()));
                    $m = mail_mesaj_stub($hesapId, $klasor, $uidv, $uid, strlen($r['raw']), '(ayrıştırılamadı)', 'parse');
                }
                try {
                    $sonuc = mail_mesaj_kaydet($pdo, $h, $klasor, $uidv, $uid, $m, $ms, $ilkTarama, $simdi, $oncekiEpoch);
                } catch (PDOException $e) {
                    // ZEHİRLİ MAİL KORUMASI: tek bir mesajın kaydı reddedilirse (strict mod aralık/kodlama hatası) hesap
                    // SONSUZA DEK takılmasın. Yer tutucu satır yazılabiliyorsa DB sağlıklıdır → sorun mesajın verisindedir,
                    // imleç ilerler. Yer tutucu da yazılamıyorsa DB sorunudur → istisna yukarı çıkar, imleç İLERLEMEZ.
                    error_log('[mail_sync] kayıt reddedildi uid=' . $uid . ': ' . mail_redact($e->getMessage()));
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $stub = mail_mesaj_stub($hesapId, $klasor, $uidv, $uid, (int)$ms['size'], '(kaydedilemedi — veri uyumsuz)', 'save');
                    mail_mesaj_kaydet($pdo, $h, $klasor, $uidv, $uid, $stub, $ms, $ilkTarama, $simdi, null);
                    $s['notlar'][] = 'uid ' . $uid . ' kaydedilemedi, yer tutucu yazıldı.';
                    $sonuc = 'tekrar';
                }
                $sonuc === 'eklendi' ? $s['inserted']++ : $s['skipped']++;
                $imlec = max($imlec, $uid);
            }
            mail_sync_imlec_yaz($pdo, $hesapId, $klasor, $uidv, (int)$imlec, $simdi);   // parti sonunda imleç (işlenmiş veri korunur)
        }
        if ($uids === [] && $ilkTarama) mail_sync_imlec_yaz($pdo, $hesapId, $klasor, $uidv, 0, $simdi);
        elseif ($imlec !== null && $imlec > $sonUid) mail_sync_imlec_yaz($pdo, $hesapId, $klasor, $uidv, (int)$imlec, $simdi);
        else mail_sync_imlec_yaz($pdo, $hesapId, $klasor, $uidv, $sonUid, $simdi);

        $pdo->prepare('UPDATE mail_sync_state SET last_ok_at = ?, last_error = NULL, consecutive_failures = 0 WHERE account_id = ? AND folder = ?')
            ->execute([$simdi, $hesapId, $klasor]);
        if ($oncekiEpoch !== null && $s['kalan'] === 0) {   // geri tarama bitti → epoch çiftlemesi kapanır
            $pdo->prepare('UPDATE mail_sync_state SET rescan_from_epoch = NULL WHERE account_id = ? AND folder = ?')->execute([$hesapId, $klasor]);
        }
        $s['ok'] = true;
    } catch (MailSyncAtla $e) {
        // pasif hesap: hata değil
    } catch (Throwable $e) {
        $s['ok'] = false;
        $s['error'] = mail_hata_metni($e);
        error_log('[mail_sync] hesap=' . $hesapId . ' ' . mail_redact(get_class($e) . ': ' . $e->getMessage()));
        try {
            // Başarıyla işlenmiş mesajların imleci KAYBOLMASIN (ağ kopması ortasında).
            if ($imlec !== null && $uidv > 0 && $imlec > 0) mail_sync_imlec_yaz($pdo, $hesapId, $klasor, $uidv, (int)$imlec, $simdi);
            $pdo->prepare('UPDATE mail_sync_state SET last_error = ?, consecutive_failures = consecutive_failures + 1, last_sync_at = ? WHERE account_id = ? AND folder = ?')
                ->execute([$s['error'], $simdi, $hesapId, $klasor]);
        } catch (Throwable $e2) { error_log('[mail_sync] durum yazılamadı: ' . mail_redact($e2->getMessage())); }
    } finally {
        if ($istemci instanceof MailImapClient) $istemci->cikis();
        if ($logId > 0) {
            try {
                $pdo->prepare('UPDATE mail_sync_log SET finished_at = ?, status = ?, fetched = ?, inserted = ?, skipped = ?, error = ? WHERE id = ?')
                    ->execute([date('Y-m-d H:i:s'), $s['ok'] ? 'ok' : 'error', $s['fetched'], $s['inserted'], $s['skipped'], $s['error'], $logId]);
            } catch (Throwable $e3) { error_log('[mail_sync] günlük yazılamadı'); }
        }
        mail_kilit_birak($kilit);
    }
    return $s;
}

final class MailSyncAtla extends RuntimeException {}

/**
 * Tüm AKTİF hesapları sırayla senkronlar; biri patlarsa diğerleri devam eder.
 * @return array<int,array> hesapId => mail_sync_hesap() sonucu
 */
function mail_sync_tum(PDO $pdo, array $opt = []): array
{
    $out = [];
    try {
        $ids = array_map('intval', $pdo->query('SELECT id FROM mail_accounts WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        error_log('[mail_sync_tum] hesaplar okunamadı: ' . mail_redact($e->getMessage()));
        return [];
    }
    foreach ($ids as $id) {
        try {
            $out[$id] = mail_sync_hesap($pdo, $id, $opt);
        } catch (Throwable $e) {
            $out[$id] = ['ok' => false, 'busy' => false, 'fetched' => 0, 'inserted' => 0, 'skipped' => 0, 'kalan' => 0,
                'error' => mail_hata_metni($e), 'notlar' => []];
        }
    }
    return $out;
}

/**
 * "Bağlantıyı Test Et": bağlan + giriş + klasörü aç. Sunucuda HİÇBİR ŞEY değiştirmez.
 * @param array $opt istemci: callable(array $hesapCred): MailImapClient (test enjeksiyonu)
 * @return array{ok:bool,mesaj:string}
 */
function mail_imap_test(PDO $pdo, int $hesapId, array $opt = []): array
{
    $h = mail_hesap_cred_oku($hesapId, $pdo);
    if ($h === null) return ['ok' => false, 'mesaj' => 'Kimlik bilgisi çözülemedi (MAIL_MASTER_KEY eksik/yanlış ya da hesap yok).'];
    $c = null;
    try {
        $c = isset($opt['istemci']) ? ($opt['istemci'])($h) : mail_imap_baglan($h, 15.0);
        $k = $c->klasorAc((string)$h['sync_folder']);
        return ['ok' => true, 'mesaj' => 'Bağlantı başarılı: "' . $h['sync_folder'] . '" klasöründe ' . (int)$k['exists'] . ' mesaj var.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'mesaj' => mail_hata_metni($e)];
    } finally {
        if ($c instanceof MailImapClient) $c->cikis();
    }
}

/**
 * Cron çalıştırıcısı (scripts/mail_sync_cron.php ince sarmalayıcıdır; mantık burada ki test edilebilsin).
 * Global kilit: aynı anda tek örnek — ikinci cron BUSY döner, hiçbir şeye dokunmaz.
 * @param array $opt mail_sync_tum() seçenekleri + kilit_dizin + log_gun (varsayılan 30)
 * @return array{kod:int,satirlar:list<string>}
 */
function mail_cron_calistir(PDO $pdo, array $opt = []): array
{
    $kilit = mail_kilit_al('sync_all', $opt['kilit_dizin'] ?? null);
    if ($kilit === null) return ['kod' => 0, 'satirlar' => ['BUSY başka bir senkron çalışıyor']];
    try {
        if (!mail_sema_hazir($pdo)) return ['kod' => 1, 'satirlar' => ['FAIL mail tabloları kurulu değil (migrate.php)']];
        if (!mail_crypto_hazir()) return ['kod' => 1, 'satirlar' => ['FAIL MAIL_MASTER_KEY tanımlı/geçerli değil']];
        $sonuc = mail_sync_tum($pdo, $opt);
        $satirlar = []; $basarili = 0; $hatali = 0;
        // Çeviri kuyruğu (varsa): hata/kapalı durumu senkronu ve çıkış kodunu ETKİLEMEZ.
        $ceviriSatiri = null;
        if (function_exists('mail_ceviri_isle')) {
            try {
                $cs = mail_ceviri_isle($pdo, array_key_exists('ceviri_saglayici', $opt) ? $opt['ceviri_saglayici'] : mail_ceviri_saglayici(), ['dizin' => $opt['kilit_dizin'] ?? null, 'simdi' => $opt['simdi'] ?? null, 'sure' => 60.0]);
                if ($cs['durum'] !== 'kapali') {
                    $ceviriSatiri = "CEVIRI durum={$cs['durum']} ceviri={$cs['ceviri']} atlandi={$cs['atlandi']} hata={$cs['hata']} beklemede={$cs['beklemede']}"
                        . ($cs['mesaj'] ? ' mesaj=' . mb_substr(mail_redact((string)$cs['mesaj']), 0, 120) : '');
                }
            } catch (Throwable $e) { $ceviriSatiri = 'CEVIRI durum=hata mesaj=' . mb_substr(mail_redact($e->getMessage()), 0, 120); }
        }
        foreach ($sonuc as $id => $r) {
            if ($r['busy']) { $satirlar[] = "BUSY hesap=$id"; continue; }
            if ($r['ok']) { $basarili++; $satirlar[] = "OK hesap=$id fetched={$r['fetched']} inserted={$r['inserted']} skipped={$r['skipped']} kalan={$r['kalan']}"; }
            else { $hatali++; $satirlar[] = "FAIL hesap=$id error=" . ($r['error'] ?? '?'); }
        }
        if (!$sonuc) $satirlar[] = 'OK aktif hesap yok';
        if ($ceviriSatiri !== null) $satirlar[] = $ceviriSatiri;
        // 'sending'de takılı kalan (süreç ölümü) giden cevaplar → 'unknown'. ASLA otomatik yeniden gönderilmez.
        if (function_exists('mail_outbox_takili_isaretle')) {
            try { $tk = mail_outbox_takili_isaretle($pdo, $opt['simdi'] ?? null); if ($tk > 0) $satirlar[] = "UYARI belirsiz_gonderim=$tk (elle doğrulayın)"; } catch (Throwable $e) { /* bakım hatası senkronu bozmasın */ }
        }
        try {   // günlük bakımı: eski senkron günlüğü silinir (hesap verisine dokunmaz)
            $pdo->prepare('DELETE FROM mail_sync_log WHERE started_at < ?')
                ->execute([date('Y-m-d H:i:s', time() - ((int)($opt['log_gun'] ?? 30)) * 86400)]);
        } catch (Throwable $e) { /* bakım hatası senkronu bozmasın */ }
        // Çıkış kodu yalnız HİÇBİR hesap başarılı değilse 1 (kısmi hata cron'u kırmızıya boyamasın; ayrıntı satırlarda).
        return ['kod' => ($hatali > 0 && $basarili === 0) ? 1 : 0, 'satirlar' => $satirlar];
    } finally {
        mail_kilit_birak($kilit);
    }
}
