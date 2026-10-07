<?php
// =========================================================
// config/mail_outbox.php — Cevap taslağı → çeviri önizleme → ONAY → gönderim durum makinesi (M5)
//
// DURUMLAR:  draft → translated → approved → sending → sent
//                                               ↘ failed   (kabul EDİLMEDİ — kullanıcı açıkça tekrar dener)
//                                               ↘ unknown  (kabul edilmiş OLABİLİR — otomatik tekrar YOK; insan doğrular)
//            iptal: cancelled (draft | translated | approved | failed'dan)
//
// DEĞİŞMEZLER (docs/MAIL_CENTER_AGENT_BRIDGE.md AD-6/AD-7, ChatGPT M0 onayı):
//  1. SMTP'ye giden TEK yol mail_outbox_gonder(); o da yalnız status='approved' ∧ approved_by dolu ∧ Message-ID atanmış
//     satırı ATOMİK sahiplenir (UPDATE … WHERE status='approved' → rowCount=1). Onaysız hiçbir kod SMTP çağırmaz.
//  2. Onay, kullanıcının GÖRDÜĞÜ içeriğe bağlıdır: content_hash (alıcı + konu + çeviri + alıntı + hedef dil + thread başlıkları).
//     İstemci hash'i, saklı hash'i ve DB'deki içeriğin yeniden hesaplanmış hash'i eşit olmalı (TOCTOU/kurcalama yok).
//  3. Çift gönderim yok: UNIQUE(hesap, idempotency_key) + atomik sahiplenme + aynı içerik için ikinci onay reddi.
//  4. Belirsizlikte (son "." sonrası yanıt yok / süreç ölümü) satır 'unknown' olur; Message-ID ve send_token korunur;
//     ASLA otomatik yeniden gönderilmez. 'sending'de takılı kalan satır 10 dk sonra 'unknown' sayılır.
//  5. Çeviri sağlayıcısı arızası taslağı SİLMEZ/GİZLEMEZ; kullanıcı çeviriyi elle girebilir.
//  6. Audit'e yalnız id/durum/sayı yazılır (gövde, adres, şifre YOK).
// =========================================================
declare(strict_types=1);

const MAIL_OUTBOX_TAKILI_SN = 600;
const MAIL_CEVAP_MAX = 20000;

/** @return array<string,string> durum → etiket */
function mail_outbox_durumlari(): array
{
    return ['draft' => 'Taslak', 'translated' => 'Çeviri hazır — onay bekliyor', 'approved' => 'Onaylandı — gönderilmeyi bekliyor', 'sending' => 'Gönderiliyor',
        'sent' => 'Gönderildi', 'failed' => 'Gönderilemedi', 'unknown' => 'Belirsiz — kontrol edin', 'cancelled' => 'İptal edildi'];
}

/** Onaylanan içeriğin parmak izi. Kullanıcının GÖRDÜĞÜ her şeyi + thread başlıklarını kapsar. */
function mail_outbox_hash(array $o): string
{
    return hash('sha256', json_encode([
        'v' => 1, 'hesap' => (int)$o['account_id'], 'to' => strtolower((string)$o['to_addr']), 'cc' => (string)($o['cc_addr'] ?? ''),
        'konu' => (string)$o['subject'], 'govde' => (string)$o['body_out'], 'alinti' => (string)($o['quote_text'] ?? ''),
        'hedef' => (string)($o['target_lang'] ?? ''), 'ebeveyn' => (int)($o['in_reply_to_msg_id'] ?? 0),
        'irt' => (string)($o['hdr_in_reply_to'] ?? ''), 'refs' => (string)($o['hdr_references'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
}

/** ACL'li giden kayıt (hesap görünür değilse null). */
function mail_outbox_getir(PDO $pdo, int $id, array $hesapIds): ?array
{
    if ($id <= 0 || !$hesapIds) return null;
    $st = $pdo->prepare('SELECT * FROM mail_outbox WHERE id = ?');
    $st->execute([$id]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o || !in_array((int)$o['account_id'], array_map('intval', $hesapIds), true)) return null;
    return $o;
}

function mail_outbox_yaz(PDO $pdo, int $id, array $set, ?string $kosulDurum = null, ?string $token = null): bool
{
    $kol = []; $par = [];
    foreach ($set as $k => $v) { $kol[] = "$k = ?"; $par[] = $v; }
    $kol[] = 'updated_at = ?'; $par[] = date('Y-m-d H:i:s');
    $sql = 'UPDATE mail_outbox SET ' . implode(', ', $kol) . ' WHERE id = ?'; $par[] = $id;
    if ($kosulDurum !== null) { $sql .= ' AND status = ?'; $par[] = $kosulDurum; }
    if ($token !== null) { $sql .= ' AND send_token = ?'; $par[] = $token; }
    $st = $pdo->prepare($sql); $st->execute($par);
    return $st->rowCount() === 1;
}

/**
 * 1. adım: cevap taslağı. Aynı (hesap, idempotency_key) ikinci kez gelirse YENİ satır açmaz, mevcudu döner.
 * @param array $in body_tr, target_lang?, quote?
 * @return array{ok:bool,id:int,mesaj:string,mevcut?:bool}
 */
function mail_outbox_taslak(PDO $pdo, int $msgId, array $hesapIds, int $uid, string $idemKey, array $in): array
{
    $hata = static fn(string $m): array => ['ok' => false, 'id' => 0, 'mesaj' => $m];
    if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $idemKey)) return $hata('Form anahtarı geçersiz; sayfayı yenileyin.');
    $m = mail_mesaj_getir($pdo, $msgId, $hesapIds);
    if ($m === null) return $hata('Mesaj bulunamadı.');
    $a = $pdo->prepare('SELECT id, email, is_active, smtp_pass_enc FROM mail_accounts WHERE id = ?'); $a->execute([(int)$m['account_id']]);
    $hesap = $a->fetch(PDO::FETCH_ASSOC);
    if (!$hesap || (int)$hesap['is_active'] !== 1) return $hata('Hesap pasif.');
    if (($hesap['smtp_pass_enc'] ?? '') === '') return $hata('Hesapta SMTP şifresi tanımlı değil.');

    $var = $pdo->prepare('SELECT id, in_reply_to_msg_id FROM mail_outbox WHERE account_id = ? AND idempotency_key = ?');
    $var->execute([(int)$m['account_id'], $idemKey]);
    if ($r = $var->fetch(PDO::FETCH_ASSOC)) {
        return (int)$r['in_reply_to_msg_id'] === $msgId ? ['ok' => true, 'id' => (int)$r['id'], 'mesaj' => 'Taslak zaten kayıtlı.', 'mevcut' => true] : $hata('Form anahtarı başka bir cevaba ait.');
    }
    $govde = trim(mail_mime_temiz((string)($in['body_tr'] ?? '')));
    if ($govde === '') return $hata('Cevap metni boş olamaz.');
    if (mb_strlen($govde) > MAIL_CEVAP_MAX) return $hata('Cevap en çok ' . MAIL_CEVAP_MAX . ' karakter olabilir.');
    $hedef = strtolower(trim((string)($in['target_lang'] ?? '')));
    if ($hedef === '') $hedef = (string)($m['lang'] ?: 'en');
    if (!preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $hedef)) return $hata('Hedef dil kodu geçersiz.');
    $to = trim((string)($m['reply_to_addr'] ?: $m['from_addr']));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return $hata('Cevaplanacak adres geçersiz ya da yok.');

    $irt = $m['message_id'] !== null ? '<' . $m['message_id'] . '>' : null;
    $refler = $m['message_id'] !== null ? mail_references_zinciri(array_filter(explode(' ', (string)$m['references_hdr'])), (string)$m['message_id']) : [];
    try {
        $pdo->prepare('INSERT INTO mail_outbox (account_id, in_reply_to_msg_id, thread_id, idempotency_key, status, to_addr, subject, body_tr, target_lang, quote_original, hdr_in_reply_to, hdr_references, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([(int)$m['account_id'], $msgId, $m['thread_id'] !== null ? (int)$m['thread_id'] : null, $idemKey, 'draft', strtolower($to), mb_substr(mail_yanit_konusu((string)$m['subject']), 0, 500),
                $govde, $hedef, !empty($in['quote']) ? 1 : 0, $irt, $refler ? implode(' ', $refler) : null, $uid, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
    } catch (PDOException $e) {
        // Yarış: aynı anahtarla eşzamanlı ikinci istek UNIQUE'e çarptı → mevcut satırı döndür (çift taslak yok).
        $var->execute([(int)$m['account_id'], $idemKey]);
        if ($r = $var->fetch(PDO::FETCH_ASSOC)) return ['ok' => true, 'id' => (int)$r['id'], 'mesaj' => 'Taslak zaten kayıtlı.', 'mevcut' => true];
        error_log('[mail_outbox_taslak] ' . mail_redact($e->getMessage()));
        return $hata('Taslak kaydedilemedi.');
    }
    $id = (int)$pdo->lastInsertId();
    audit_log_event('mail_reply_draft', 'mail_outbox', $id, null, ['mesaj_id' => $msgId, 'karakter' => mb_strlen($govde)]);
    return ['ok' => true, 'id' => $id, 'mesaj' => 'Taslak kaydedildi.'];
}

/**
 * 2. adım: çeviri önizleme. Sağlayıcı arızası/kapalıysa taslak KORUNUR (draft), kullanıcı çeviriyi elle girebilir.
 * @param array $in body_tr?, target_lang?, body_out_manual? (kullanıcının kendi çevirisi/düzeltmesi), quote?
 * @return array{ok:bool,mesaj:string,hash?:string}
 */
function mail_outbox_onizle(PDO $pdo, int $id, array $hesapIds, int $uid, ?MailTranslationProviderInterface $p, array $in): array
{
    $o = mail_outbox_getir($pdo, $id, $hesapIds);
    if ($o === null) return ['ok' => false, 'mesaj' => 'Taslak bulunamadı.'];
    if (!in_array($o['status'], ['draft', 'translated'], true)) return ['ok' => false, 'mesaj' => 'Bu cevap artık düzenlenemez (durum: ' . (mail_outbox_durumlari()[$o['status']] ?? $o['status']) . ').'];
    $m = mail_mesaj_getir($pdo, (int)$o['in_reply_to_msg_id'], $hesapIds);
    if ($m === null) return ['ok' => false, 'mesaj' => 'Cevaplanan mesaj bulunamadı.'];

    $govde = array_key_exists('body_tr', $in) ? trim(mail_mime_temiz((string)$in['body_tr'])) : (string)$o['body_tr'];
    if ($govde === '') return ['ok' => false, 'mesaj' => 'Cevap metni boş olamaz.'];
    if (mb_strlen($govde) > MAIL_CEVAP_MAX) return ['ok' => false, 'mesaj' => 'Cevap çok uzun.'];
    $hedef = strtolower(trim((string)($in['target_lang'] ?? $o['target_lang'])));
    if (!preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $hedef)) return ['ok' => false, 'mesaj' => 'Hedef dil kodu geçersiz.'];
    $manuel = trim(mail_mime_temiz((string)($in['body_out_manual'] ?? '')));

    $cikti = null; $saglayici = null;
    if ($manuel !== '') { $cikti = $manuel; $saglayici = 'manual'; }
    elseif ($hedef === 'tr') { $cikti = $govde; $saglayici = 'none'; }
    else {
        if ($p === null || $p->ad() === 'none') {
            mail_outbox_yaz($pdo, $id, ['body_tr' => $govde, 'target_lang' => $hedef], null);
            return ['ok' => false, 'mesaj' => 'Çeviri sağlayıcısı kapalı. Çeviriyi aşağıdaki "Gönderilecek çeviri" kutusuna kendiniz yazabilirsiniz.'];
        }
        try {
            $r = mail_ceviri_cevir($p, $govde, 'tr', $hedef);
            $cikti = $r['metin']; $saglayici = $p->ad();
        } catch (MailTranslateException $e) {
            // Taslak (Türkçe metin) KAYBOLMAZ; kullanıcı yeniden dener ya da çeviriyi elle girer.
            mail_outbox_yaz($pdo, $id, ['body_tr' => $govde, 'target_lang' => $hedef, 'last_error' => mb_substr(mail_redact($e->getMessage()), 0, 250)], null);
            return ['ok' => false, 'mesaj' => 'Çeviri yapılamadı: ' . mb_substr(mail_redact($e->getMessage()), 0, 160) . ' — Türkçe metniniz saklandı; tekrar deneyebilir ya da çeviriyi elle yazabilirsiniz.'];
        }
    }
    if (trim((string)$cikti) === '') return ['ok' => false, 'mesaj' => 'Çeviri boş döndü.'];

    $alinti = !empty($in['quote']) || (!array_key_exists('quote', $in) && (int)$o['quote_original'] === 1) ? mail_alinti_olustur($m) : null;
    $yeni = ['body_tr' => $govde, 'body_out' => $cikti, 'quote_text' => $alinti, 'quote_original' => $alinti !== null ? 1 : 0, 'target_lang' => $hedef,
        'tr_provider' => $saglayici, 'last_error' => null] + $o;
    $hash = mail_outbox_hash($yeni);
    $ok = mail_outbox_yaz($pdo, $id, ['body_tr' => $govde, 'body_out' => $cikti, 'quote_text' => $alinti, 'quote_original' => $alinti !== null ? 1 : 0,
        'target_lang' => $hedef, 'tr_provider' => $saglayici, 'content_hash' => $hash, 'status' => 'translated', 'last_error' => null,
        'approved_by' => null, 'approved_at' => null]);
    if (!$ok) return ['ok' => false, 'mesaj' => 'Taslak güncellenemedi.'];
    audit_log_event('mail_reply_preview', 'mail_outbox', $id, null, ['saglayici' => $saglayici, 'hedef_dil' => $hedef, 'karakter' => mb_strlen($govde)]);
    return ['ok' => true, 'mesaj' => 'Çeviri hazır. Lütfen iki metni de kontrol edip onaylayın.', 'hash' => $hash];
}

/** Aynı cevabın (aynı ebeveyn + aynı içerik) başka satırda zaten onaylı/gönderilmiş/belirsiz olup olmadığı. */
function mail_outbox_ikiz_var(PDO $pdo, array $o): bool
{
    $st = $pdo->prepare("SELECT 1 FROM mail_outbox WHERE account_id = ? AND in_reply_to_msg_id = ? AND content_hash = ? AND id <> ? AND status IN ('approved','sending','sent','unknown') LIMIT 1");
    $st->execute([(int)$o['account_id'], (int)$o['in_reply_to_msg_id'], (string)$o['content_hash'], (int)$o['id']]);
    return (bool)$st->fetchColumn();
}

/**
 * 3. adım: "✅ Onayla ve Gönder". Yalnız $sendYetkisi=true (can_mail('send')) ile. Onay + gönderim TEK çağrı,
 * ama onay ile SMTP arasında atomik sahiplenme vardır. İkinci çağrı hiçbir şey göndermez.
 * @param array $opt smtp: callable(array $hesapCred): MailSmtpClient · imap: callable (APPEND) · simdi
 * @return array{ok:bool,durum:string,mesaj:string}
 */
function mail_outbox_onayla(PDO $pdo, int $id, array $hesapIds, int $uid, bool $sendYetkisi, string $istemciHash, array $opt = []): array
{
    if (!$sendYetkisi) return ['ok' => false, 'durum' => '', 'mesaj' => 'Göndermek için mail.send yetkisi gerekir.'];
    $o = mail_outbox_getir($pdo, $id, $hesapIds);
    if ($o === null) return ['ok' => false, 'durum' => '', 'mesaj' => 'Taslak bulunamadı.'];
    if ($o['status'] !== 'translated') {
        $d = mail_outbox_durumlari()[$o['status']] ?? $o['status'];
        return ['ok' => false, 'durum' => (string)$o['status'], 'mesaj' => "Bu cevap zaten işleme alınmış ya da onaya hazır değil (durum: $d). Tekrar gönderilmedi."];
    }
    if ($istemciHash === '' || !hash_equals((string)$o['content_hash'], $istemciHash)) {
        return ['ok' => false, 'durum' => 'translated', 'mesaj' => 'Ekrandaki içerik değişmiş; güncel metni kontrol edip yeniden onaylayın (gönderilmedi).'];
    }
    if (!hash_equals(mail_outbox_hash($o), (string)$o['content_hash'])) {
        return ['ok' => false, 'durum' => 'translated', 'mesaj' => 'Kayıtlı içerik bütünlük kontrolünden geçemedi (gönderilmedi). Önizlemeyi yenileyin.'];
    }
    if (trim((string)$o['body_out']) === '') return ['ok' => false, 'durum' => 'translated', 'mesaj' => 'Gönderilecek çeviri boş.'];
    if (mail_outbox_ikiz_var($pdo, $o)) return ['ok' => false, 'durum' => 'translated', 'mesaj' => 'Aynı cevap zaten onaylanmış/gönderilmiş. Çift gönderim engellendi.'];
    $a = $pdo->prepare('SELECT email, is_active FROM mail_accounts WHERE id = ?'); $a->execute([(int)$o['account_id']]);
    $hesap = $a->fetch(PDO::FETCH_ASSOC);
    if (!$hesap || (int)$hesap['is_active'] !== 1) return ['ok' => false, 'durum' => 'translated', 'mesaj' => 'Hesap pasif.'];

    $mid = mail_yeni_message_id((string)$hesap['email']);
    $ok = mail_outbox_yaz($pdo, $id, ['status' => 'approved', 'approved_by' => $uid, 'approved_at' => date('Y-m-d H:i:s', (int)($opt['simdi'] ?? time())), 'out_message_id' => $mid],
        'translated');
    if (!$ok) return ['ok' => false, 'durum' => '', 'mesaj' => 'Bu cevap başka bir istekle işleme alındı. Tekrar gönderilmedi.'];
    audit_log_event('mail_send_approve', 'mail_outbox', $id, null, ['onaylayan' => $uid]);
    return mail_outbox_gonder($pdo, $id, $opt);
}

/**
 * SMTP'ye giden TEK yol. status='approved' ∧ approved_by dolu ∧ Message-ID atanmış satırı atomik sahiplenir;
 * aksi hâlde HİÇBİR ŞEY yapmaz (SMTP çağrılmaz).
 * @return array{ok:bool,durum:string,mesaj:string}
 */
function mail_outbox_gonder(PDO $pdo, int $id, array $opt = []): array
{
    $simdi = (int)($opt['simdi'] ?? time());
    $token = bin2hex(random_bytes(16));
    $st = $pdo->prepare("UPDATE mail_outbox SET status = 'sending', send_token = ?, send_started_at = ?, attempts = attempts + 1, updated_at = ?
        WHERE id = ? AND status = 'approved' AND approved_by IS NOT NULL AND out_message_id IS NOT NULL AND content_hash IS NOT NULL");
    $st->execute([$token, date('Y-m-d H:i:s', $simdi), date('Y-m-d H:i:s', $simdi), $id]);
    if ($st->rowCount() !== 1) return ['ok' => false, 'durum' => '', 'mesaj' => 'Gönderim için uygun (onaylı) kayıt yok ya da başka süreç gönderiyor. Hiçbir şey gönderilmedi.'];

    $q = $pdo->prepare('SELECT * FROM mail_outbox WHERE id = ?'); $q->execute([$id]);
    $o = $q->fetch(PDO::FETCH_ASSOC);
    $bitir = function (string $durum, string $mesaj, ?string $hata = null, array $ek = []) use ($pdo, $id, $token): array {
        mail_outbox_yaz($pdo, $id, ['status' => $durum, 'last_error' => $hata !== null ? mb_substr(mail_redact($hata), 0, 250) : null] + $ek, 'sending', $token);
        return ['ok' => $durum === 'sent', 'durum' => $durum, 'mesaj' => $mesaj];
    };
    // Sahiplenme sonrası bütünlük: içerik onaydan beri değiştiyse HİÇ gönderme.
    if (!hash_equals(mail_outbox_hash($o), (string)$o['content_hash'])) {
        audit_log_event('mail_send_failed', 'mail_outbox', $id, null, ['neden' => 'butunluk']);
        return $bitir('failed', 'Gönderilmedi: içerik bütünlük kontrolünden geçemedi.', 'İçerik onaydan sonra değişmiş (bütünlük).');
    }
    $h = mail_hesap_cred_oku((int)$o['account_id'], $pdo);
    if ($h === null) return $bitir('failed', 'Gönderilmedi: hesap kimlik bilgisi çözülemedi (MAIL_MASTER_KEY).', 'Kimlik bilgisi çözülemedi.');
    $a = $pdo->prepare('SELECT * FROM mail_accounts WHERE id = ?'); $a->execute([(int)$o['account_id']]);
    $hesap = $a->fetch(PDO::FETCH_ASSOC);
    try {
        $ham = mail_giden_mesaj(['from_email' => $hesap['email'], 'from_name' => (string)($hesap['display_name'] ?? ''), 'to' => [['name' => '', 'email' => $o['to_addr']]],
            'reply_to' => $hesap['reply_to'] ?: null, 'subject' => $o['subject'], 'body' => $o['body_out'], 'quote' => $o['quote_text'],
            'message_id' => $o['out_message_id'], 'in_reply_to' => $o['hdr_in_reply_to'],
            'references' => $o['hdr_references'] ? explode(' ', (string)$o['hdr_references']) : [], 'date' => $simdi]);
    } catch (InvalidArgumentException $e) {
        return $bitir('failed', 'Gönderilmedi: mesaj oluşturulamadı (' . $e->getMessage() . ').', $e->getMessage());
    }

    $smtp = null;
    try {
        $smtp = isset($opt['smtp']) ? ($opt['smtp'])($h) : mail_smtp_baglan($h, 20.0);
        $r = $smtp->gonder((string)$hesap['email'], [(string)$o['to_addr']], $ham);
    } catch (Throwable $e) {
        $dataFazi = $smtp instanceof MailSmtpClient && $smtp->dataFazi;
        $belirsiz = ($e instanceof MailSmtpException && $e->kind === 'unknown') || ($dataFazi && !($e instanceof MailSmtpException && $e->kind === 'data_reject'));
        if ($smtp instanceof MailSmtpClient) $smtp->kapatZorla();
        $mesaj = mail_hata_metni($e);
        if ($belirsiz) {
            audit_log_event('mail_send_unknown', 'mail_outbox', $id, null, ['neden' => $e instanceof MailSmtpException ? $e->kind : 'baglanti']);
            return $bitir('unknown', 'Belirsiz: mesaj sunucuya iletilmiş olabilir. OTOMATİK TEKRAR GÖNDERİLMEZ — Gönderilenler klasörünü/müşteriyi kontrol edin.', $mesaj);
        }
        audit_log_event('mail_send_failed', 'mail_outbox', $id, null, ['tur' => $e instanceof MailSmtpException ? $e->kind : 'baglanti']);
        return $bitir('failed', 'Gönderilemedi (mesaj sunucu tarafından kabul EDİLMEDİ): ' . $mesaj, $mesaj);
    } finally {
        if ($smtp instanceof MailSmtpClient) $smtp->cikis();
    }

    $sonuc = $bitir('sent', 'Cevap gönderildi.', null, ['sent_at' => date('Y-m-d H:i:s', $simdi)]);
    audit_log_event('mail_send', 'mail_outbox', $id, null, ['mesaj_id' => (int)$o['in_reply_to_msg_id'], 'alici_sayisi' => 1, 'red' => count($r['red'])]);
    if ($o['in_reply_to_msg_id']) {   // orijinal "cevaplandı" sayılır
        try { $pdo->prepare('UPDATE mail_messages SET needs_reply = 0, replied_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', $simdi), (int)$o['in_reply_to_msg_id']]); } catch (Throwable $e) { /* gönderim sonucu bozulmasın */ }
    }
    // Gönderilen kopya: best-effort, YALNIZ hesapta açıksa. Hata gönderimi geri almaz/etkilemez.
    if ((int)$hesap['append_sent'] === 1 && trim((string)$hesap['sent_folder']) !== '') {
        $imap = null;
        try {
            $imap = isset($opt['imap']) ? ($opt['imap'])($h) : mail_imap_baglan($h, 15.0);
            $imap->ekle((string)$hesap['sent_folder'], $ham, '\\Seen');
        } catch (Throwable $e) {
            audit_log_event('mail_send_append_failed', 'mail_outbox', $id, null, ['neden' => mb_substr(mail_hata_metni($e), 0, 80)]);
        } finally { if ($imap instanceof MailImapClient) $imap->cikis(); }
    }
    return $sonuc;
}

/** failed → kullanıcı AÇIKÇA tekrar dener (aynı onaylı içerik + aynı Message-ID). */
function mail_outbox_tekrar(PDO $pdo, int $id, array $hesapIds, int $uid, bool $sendYetkisi, array $opt = []): array
{
    if (!$sendYetkisi) return ['ok' => false, 'durum' => '', 'mesaj' => 'Göndermek için mail.send yetkisi gerekir.'];
    $o = mail_outbox_getir($pdo, $id, $hesapIds);
    if ($o === null) return ['ok' => false, 'durum' => '', 'mesaj' => 'Kayıt bulunamadı.'];
    if ($o['status'] !== 'failed') return ['ok' => false, 'durum' => (string)$o['status'], 'mesaj' => 'Yalnız "Gönderilemedi" durumundaki cevap yeniden denenebilir.'];
    if (!$o['out_message_id'] || !$o['content_hash'] || !hash_equals(mail_outbox_hash($o), (string)$o['content_hash'])) {
        return ['ok' => false, 'durum' => 'failed', 'mesaj' => 'Kayıtlı içerik doğrulanamadı; önizlemeyi yenileyip yeniden onaylayın.'];
    }
    if (mail_outbox_ikiz_var($pdo, $o)) return ['ok' => false, 'durum' => 'failed', 'mesaj' => 'Aynı cevap zaten onaylanmış/gönderilmiş. Çift gönderim engellendi.'];
    if (!mail_outbox_yaz($pdo, $id, ['status' => 'approved', 'approved_by' => $uid, 'approved_at' => date('Y-m-d H:i:s', (int)($opt['simdi'] ?? time()))], 'failed')) {
        return ['ok' => false, 'durum' => '', 'mesaj' => 'Kayıt başka bir istekle değiştirildi.'];
    }
    audit_log_event('mail_send_approve', 'mail_outbox', $id, null, ['onaylayan' => $uid, 'tekrar' => 1]);
    return mail_outbox_gonder($pdo, $id, $opt);
}

/**
 * 'unknown' çözümü — İNSAN kararı: "Gönderildi" ya da "Gönderilmedi (tekrar denenebilir)".
 * @param string $karar gonderildi | gonderilmedi
 */
function mail_outbox_belirsiz_coz(PDO $pdo, int $id, array $hesapIds, int $uid, bool $sendYetkisi, string $karar): array
{
    if (!$sendYetkisi) return ['ok' => false, 'mesaj' => 'Bu karar için mail.send yetkisi gerekir.'];
    $o = mail_outbox_getir($pdo, $id, $hesapIds);
    if ($o === null) return ['ok' => false, 'mesaj' => 'Kayıt bulunamadı.'];
    if ($o['status'] !== 'unknown') return ['ok' => false, 'mesaj' => 'Yalnız "Belirsiz" durumdaki kayıt çözülebilir.'];
    if ($karar === 'gonderildi') {
        $ok = mail_outbox_yaz($pdo, $id, ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s'), 'last_error' => 'Belirsizlik, kullanıcı tarafından "gönderildi" olarak doğrulandı.'], 'unknown');
        if ($ok && $o['in_reply_to_msg_id']) $pdo->prepare('UPDATE mail_messages SET needs_reply = 0, replied_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), (int)$o['in_reply_to_msg_id']]);
    } elseif ($karar === 'gonderilmedi') {
        $ok = mail_outbox_yaz($pdo, $id, ['status' => 'failed', 'last_error' => 'Belirsizlik, kullanıcı tarafından "gönderilmedi" olarak doğrulandı; tekrar denenebilir.'], 'unknown');
    } else return ['ok' => false, 'mesaj' => 'Geçersiz karar.'];
    if ($ok) audit_log_event('mail_send_resolve', 'mail_outbox', $id, null, ['karar' => $karar, 'kullanici' => $uid]);
    return ['ok' => $ok, 'mesaj' => $ok ? ($karar === 'gonderildi' ? 'Gönderildi olarak işaretlendi.' : 'Gönderilmedi olarak işaretlendi; yeniden deneyebilirsiniz.') : 'Kayıt başka bir istekle değiştirildi.'];
}

function mail_outbox_iptal(PDO $pdo, int $id, array $hesapIds, int $uid): array
{
    $o = mail_outbox_getir($pdo, $id, $hesapIds);
    if ($o === null) return ['ok' => false, 'mesaj' => 'Kayıt bulunamadı.'];
    foreach (['draft', 'translated', 'approved', 'failed'] as $d) {
        if ($o['status'] === $d && mail_outbox_yaz($pdo, $id, ['status' => 'cancelled', 'approved_by' => null], $d)) {
            audit_log_event('mail_reply_cancel', 'mail_outbox', $id, null, ['onceki' => $d, 'kullanici' => $uid]);
            return ['ok' => true, 'mesaj' => 'Cevap iptal edildi (gönderilmedi).'];
        }
    }
    return ['ok' => false, 'mesaj' => 'Bu durumdaki cevap iptal edilemez (' . (mail_outbox_durumlari()[$o['status']] ?? $o['status']) . ').'];
}

/** 'sending'de 10 dk'dan uzun takılı kalan (süreç ölümü) satırları 'unknown' yapar. Otomatik TEKRAR YOK. @return int etkilenen */
function mail_outbox_takili_isaretle(PDO $pdo, ?int $simdi = null): int
{
    if (!mail_tablo_var($pdo, 'mail_outbox')) return 0;
    $esik = date('Y-m-d H:i:s', ($simdi ?? time()) - MAIL_OUTBOX_TAKILI_SN);
    $st = $pdo->prepare("UPDATE mail_outbox SET status = 'unknown', updated_at = ?, last_error = ? WHERE status = 'sending' AND send_started_at < ?");
    $st->execute([date('Y-m-d H:i:s', $simdi ?? time()), 'Gönderim sırasında süreç kesildi; mesaj iletilmiş olabilir — elle doğrulayın.', $esik]);
    $n = $st->rowCount();
    if ($n > 0) audit_log_event('mail_send_unknown', 'mail_outbox', null, null, ['neden' => 'takili_surec', 'adet' => $n]);
    return $n;
}
