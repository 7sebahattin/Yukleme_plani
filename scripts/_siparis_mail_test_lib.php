<?php
// =========================================================
// scripts/_siparis_mail_test_lib.php — "Mailden sipariş al" (v378) BİRLEŞİK test harness'i (include-only)
//
// İki harness (_siparis_test_lib.php ve _mail_test_lib.php) aynı global stub'ları (db(), can(), current_user()…) tanımladığı
// için bir süreçte birlikte yüklenemez. Bu dosya SİPARİŞ harness'ini temel alır ve üzerine Mail Merkezi'ni ekler:
//   config/mail_* + scripts/_mail_test_sema.php (stub'sız şema yardımcıları) + FakeMailStream + config/siparis_mail.php.
// Hepsi SENTETİK: adresler yalnız @ornek.test, PDF'ler siparis_test_pdf() etiketli küçük dosyalar (tür, parse kancasıyla
// scripts/fixtures/siparis/*.json'dan gelir). Canlı DB'ye, storage/mail ve storage/siparis'e HİÇ dokunmaz.
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

// Kilit dosyası klasörü: mail_sync.php yüklenmeden ÖNCE (mail_depo_dizini() sabiti çalışma anında okur ama erken tanımlamak güvenli).
$SMT_DIR = sys_get_temp_dir() . '/siparis_mail_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
@mkdir($SMT_DIR, 0700, true);
define('MAIL_STORAGE_DIR', $SMT_DIR . '/mail');
register_shutdown_function(function () use ($SMT_DIR): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($SMT_DIR, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($SMT_DIR);
});

require_once __DIR__ . '/_siparis_test_lib.php';        // stub'lar + siparis_* + ok()/$pass/$fail/siparis_test_bitir()
require_once $ROOT . '/config/mail_core.php';
require_once $ROOT . '/config/mail_imap.php';
require_once $ROOT . '/config/mail_mime.php';
require_once $ROOT . '/config/mail_sync.php';
require_once $ROOT . '/config/mail_view.php';
require_once $ROOT . '/config/mail_attach.php';
require_once __DIR__ . '/_mail_test_sema.php';
require_once __DIR__ . '/_mail_fake_imap.php';
require_once $ROOT . '/config/siparis_mail.php';
require_once $ROOT . '/_siparis_ortak.php';

/** Tüm şema + anahtar + hks_kv. */
function smt_kur(PDO $db): void
{
    siparis_test_diger_tablolar($db);
    siparis_test_sema_kur($db);
    mail_test_sema_kur($db);
    mail_test_anahtar_kur();
    $db->exec('CREATE TABLE hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)');
}

/** Sentetik posta kutusu (şifreli parola ile). @return int hesap id */
function smt_hesap(string $email, bool $aktif = true): int
{
    $db = db();
    $r = mail_hesap_kaydet(['label' => 'Kutu ' . explode('@', $email)[0], 'email' => $email, 'imap_host' => 'imap.ornek.test', 'imap_user' => $email, 'imap_pass' => 'imap-parola-ABC',
        'smtp_host' => 'smtp.ornek.test', 'smtp_user' => $email, 'smtp_pass' => 'smtp-parola-XYZ', 'sent_folder' => 'Sent'], null, 1, $db);
    if (!$r['ok']) throw new RuntimeException('hesap: ' . json_encode($r));
    if (!$aktif) $db->exec('UPDATE mail_accounts SET is_active = 0 WHERE id = ' . (int)$r['id']);
    return (int)$r['id'];
}

/** Kullanıcıyı hesaba ata (ACL). */
function smt_ata(int $hesap, int $uid): void
{
    db()->prepare('INSERT INTO mail_account_users (account_id, user_id) VALUES (?, ?)')->execute([$hesap, $uid]);
}

/** @var array<string,FakeMailStream> imap_user (e-posta) → sahte sunucu */
$SMT_SRV = [];
$SMT_BAGLANTI = 0;
$SMT_UID = 100;

/** Hesabın sahte IMAP sunucusu (ilk çağrıda oluşur). */
function smt_srv(string $email): FakeMailStream
{
    global $SMT_SRV;
    return $SMT_SRV[$email] ??= new FakeMailStream(['user' => $email, 'pass' => 'imap-parola-ABC', 'uidvalidity' => 1000, 'mesajlar' => []]);
}

/** mail_ek_hazirla'ya geçen istemci fabrikası: hesabın sunucusuna bağlanır, bağlantı sayar. */
function smt_fabrika(): callable
{
    return function (array $h): MailImapClient {
        global $SMT_BAGLANTI;
        $SMT_BAGLANTI++;
        $c = new MailImapClient(smt_srv((string)$h['imap_user'])->yeniBaglanti());
        $c->baslat(false);
        $c->girisYap((string)$h['imap_user'], (string)$h['imap_pass']);
        return $c;
    };
}

/** Bir PDF eki tanımı: bytes verilirse sunucuya da konur. */
function smt_ek(string $part, string $ad, ?string $bytes = null, string $mime = 'application/pdf', array $o = []): array
{
    return ['part' => $part, 'filename' => $ad, 'mime' => $mime, 'cte' => 'base64', 'size' => $o['size'] ?? ($bytes !== null ? strlen($bytes) : 1000),
            'inline' => $o['inline'] ?? false, 'cid' => null, '_bytes' => $bytes];
}

/**
 * Mail kaydı (doğrudan INSERT, mail_messages) + (ek baytları varsa) hesabın sahte sunucusuna parça. @return int mail id
 * $o: folder, mid (Message-ID özeti anahtarı), subject, from_name, reply_to, body_truncated, uidv, has_attachments
 */
function smt_mail(int $hesap, string $email, string $from, array $ekler, string $alinma, array $o = []): int
{
    global $SMT_UID;
    $db = db();
    $uid = ++$SMT_UID;
    $o += ['folder' => 'INBOX', 'mid' => 'm' . $uid, 'subject' => 'Konu ' . $uid, 'from_name' => 'Gönderen ' . $uid, 'reply_to' => '', 'body_truncated' => 0, 'uidv' => 1000,
           'has_attachments' => $ekler ? 1 : 0];
    $json = array_map(static function (array $e): array { unset($e['_bytes']); return $e; }, $ekler);
    $db->prepare("INSERT INTO mail_messages (account_id, folder, uidvalidity, uid, message_id_hash, subject, from_name, from_addr, reply_to_addr, received_at, body_text,
            body_truncated, is_read, needs_reply, tr_status, attachments_json, has_attachments, to_addrs, cc_addrs)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'govde', ?, 0, 1, 'skipped', ?, ?, '[]', '[]')")
        ->execute([$hesap, $o['folder'], $o['uidv'], $uid, sha1('id:' . strtolower((string)$o['mid'])), $o['subject'], $o['from_name'], $from, $o['reply_to'], $alinma,
                   $o['body_truncated'], $json ? json_encode($json) : null, $o['has_attachments']]);
    $id = (int)$db->lastInsertId();
    $parcalar = [];
    foreach ($ekler as $e) if (($e['_bytes'] ?? null) !== null) $parcalar[(string)$e['part']] = chunk_split(base64_encode((string)$e['_bytes']));
    $srv = smt_srv($email);
    $srv->mesajlar[$uid] = ['raw' => "From: x\r\n\r\n", 'flags' => [], 'date' => $alinma, 'parcalar' => $parcalar];
    return $id;
}

/** Test PDF içeriği: etiket dosya içinde kalır (parse kancası türü buradan çözer). */
function smt_pdf(string $etiket): string { return siparis_test_pdf($etiket); }

/**
 * Parse kancası: PDF içindeki etikete göre sentetik ayrıştırıcı çıktısı.
 *  po1/po2.. → po_iki_sevkiyat · ll* → ll_plakali · pc* → pc_bolum_farkli · bilinmiyor* → doc_type unknown · bozuk* → doc_type error
 */
function smt_parse_kancasi_kur(): void
{
    $GLOBALS['_siparis_parse_override'] = function (string $yol): array {
        $ic = (string)file_get_contents($yol);
        $e = preg_match('/siparis test (\S+)/', $ic, $m) ? $m[1] : '';
        if (str_starts_with($e, 'po')) return siparis_test_fixture('po_iki_sevkiyat');
        if (str_starts_with($e, 'll')) return siparis_test_fixture('ll_plakali');
        if (str_starts_with($e, 'pc')) return siparis_test_fixture('pc_bolum_farkli');
        if (str_starts_with($e, 'bozuk')) return ['doc_type' => 'error', 'error' => 'sentetik'];
        return ['doc_type' => 'unknown', 'checks' => [], 'warnings' => []];
    };
}

/** Sunucuların aldığı TÜM IMAP komutları (alt bağlantılar dahil). @return list<string> */
function smt_komutlar(): array
{
    global $SMT_SRV;
    $o = [];
    foreach ($SMT_SRV as $s) foreach ($s->received as $c) $o[] = $c;
    return $o;
}

/** Komut günlüğünü ve bağlantı sayacını sıfırlar. */
function smt_sayac_sifirla(): void
{
    global $SMT_SRV, $SMT_BAGLANTI;
    $SMT_BAGLANTI = 0;
    foreach ($SMT_SRV as $s) $s->received = [];
}

/** Sentetik ayarı yazar (hks_kv). */
function smt_ayar(array $hesaplar, array $gonderen): void
{
    siparis_mail_kv_yaz(db(), SIPARIS_MAIL_KV_AYAR, ['hesaplar' => $hesaplar, 'gonderen' => $gonderen + ['siparis' => [], 'lojistik' => [], 'fiyat' => []]]);
}

/** Tarama bitene kadar adım adım yürütür. @return array son yanıt */
function smt_tara(string $tur, int $uid = 1, string $depo = 'DEPO1', array $opt = []): array
{
    $db = db();
    $r = siparis_mail_baslat($db, $tur, $uid, $depo, $opt);
    $n = 0;
    while ($r['ok'] && empty($r['bitti']) && $n++ < 100) $r = siparis_mail_adim($db, (string)$r['tarama_id'], $uid, $depo, $opt + ['istemci' => smt_fabrika()]);
    return $r;
}

/** Mail satırının sonuç durumları: durum listesi (sıra korunur). */
function smt_durumlar(array $r): array
{
    return array_map(static fn(array $s): string => (string)$s['durum'], (array)($r['son']['satirlar'] ?? []));
}
