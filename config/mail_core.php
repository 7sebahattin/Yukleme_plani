<?php
// =========================================================
// config/mail_core.php — Mail Merkezi çekirdeği (M1)
//
// İçerik: şema (tablolar/migrate/sema_hazir), kimlik bilgisi şifreleme
// (AES-256-GCM + AAD), sır maskeleme, hesap deposu (CRUD + kullanıcı ACL).
// IMAP/SMTP/MIME/çeviri YOK — onlar sonraki milestone'ların dosyaları.
//
// Kurallar (docs/MAIL_CENTER_AGENT_BRIDGE.md):
//  • Tablolar YALNIZ migrate.php kartından kurulur (mail_migrate()); hiçbir
//    sayfa isteği şema değiştirmez. Tablo yoksa özellik GİZLİ / fail-closed.
//  • Master key repoda/DB'de YOK: config/local.php `MAIL_MASTER_KEY` (base64,
//    32 bayt) ya da ortam değişkeni. YEDEK/SABİT ANAHTAR YOK — anahtar yoksa
//    hesap kaydetme/senkron/gönderim REDDEDİLİR.
//  • Şifreler ekrana/loga/audit'e ASLA yazılmaz (mail_redact()).
//  • Yetki kapısı can_mail() (config/helpers.php) — burada TEKRAR yazılmaz.
// =========================================================
declare(strict_types=1);

const MAIL_BLOB_SURUM   = 'v1';
const MAIL_SIFRE_ALANLARI = ['imap_pass', 'smtp_pass'];

// ── ŞEMA ────────────────────────────────────────────────────────────────

/** @return array<string,string> tablo adı → CREATE TABLE (yalnız ekleyici, ALTER yok). */
function mail_tablolar(): array
{
    $t = [];
    // ROW_FORMAT=DYNAMIC AÇIKÇA: geniş VARCHAR/TEXT sütunlu tablolar COMPACT satır biçiminde "1118 Row size too large" ile kurulamaz (MySQL 5.6 / innodb_default_row_format=compact).
    $son = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC";

    $t['mail_accounts'] = "CREATE TABLE IF NOT EXISTS `mail_accounts` (
        `id`               INT AUTO_INCREMENT PRIMARY KEY,
        `label`            VARCHAR(100) NOT NULL,
        `email`            VARCHAR(190) NOT NULL,
        `display_name`     VARCHAR(150) NULL DEFAULT NULL,
        `imap_host`        VARCHAR(190) NOT NULL,
        `imap_port`        INT          NOT NULL DEFAULT 993,
        `imap_security`    VARCHAR(10)  NOT NULL DEFAULT 'ssl',
        `imap_user`        VARCHAR(190) NOT NULL,
        `imap_pass_enc`    TEXT         NULL,
        `smtp_host`        VARCHAR(190) NOT NULL,
        `smtp_port`        INT          NOT NULL DEFAULT 465,
        `smtp_security`    VARCHAR(10)  NOT NULL DEFAULT 'ssl',
        `smtp_user`        VARCHAR(190) NOT NULL,
        `smtp_pass_enc`    TEXT         NULL,
        `reply_to`         VARCHAR(190) NULL DEFAULT NULL,
        `sync_folder`      VARCHAR(100) NOT NULL DEFAULT 'INBOX',
        `sent_folder`      VARCHAR(100) NULL DEFAULT NULL,
        `append_sent`      TINYINT(1)   NOT NULL DEFAULT 0,
        `initial_days`     INT          NOT NULL DEFAULT 30,
        `translate_enabled` TINYINT(1)  NOT NULL DEFAULT 0,
        `target_lang`      VARCHAR(10)  NOT NULL DEFAULT 'tr',
        `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
        `created_by`       INT          NULL DEFAULT NULL,
        `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`       DATETIME     NULL DEFAULT NULL,
        UNIQUE KEY `uq_ma_email` (`email`)
    )" . $son;

    $t['mail_account_users'] = "CREATE TABLE IF NOT EXISTS `mail_account_users` (
        `account_id` INT      NOT NULL,
        `user_id`    INT      NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`account_id`, `user_id`),
        INDEX `idx_mau_user` (`user_id`),
        CONSTRAINT `fk_mau_account` FOREIGN KEY (`account_id`)
            REFERENCES `mail_accounts`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT
    )" . $son;

    $t['mail_threads'] = "CREATE TABLE IF NOT EXISTS `mail_threads` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `account_id`      INT          NOT NULL,
        `thread_key`      CHAR(40)     NOT NULL,
        `subject_norm`    VARCHAR(255) NULL DEFAULT NULL,
        `last_message_at` DATETIME     NULL DEFAULT NULL,
        `message_count`   INT          NOT NULL DEFAULT 0,
        `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_mt_key` (`account_id`, `thread_key`),
        INDEX `idx_mt_last` (`account_id`, `last_message_at`),
        CONSTRAINT `fk_mt_account` FOREIGN KEY (`account_id`)
            REFERENCES `mail_accounts`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT
    )" . $son;

    $t['mail_messages'] = "CREATE TABLE IF NOT EXISTS `mail_messages` (
        `id`               BIGINT AUTO_INCREMENT PRIMARY KEY,
        `account_id`       INT          NOT NULL,
        `thread_id`        INT          NULL DEFAULT NULL,
        `folder`           VARCHAR(100) NOT NULL,
        `uidvalidity`      BIGINT       NOT NULL,
        `uid`              BIGINT       NOT NULL,
        `message_id`       VARCHAR(500) NULL DEFAULT NULL,
        `message_id_hash`  CHAR(40)     NOT NULL,
        `in_reply_to`      VARCHAR(500) NULL DEFAULT NULL,
        `references_hdr`   TEXT         NULL,
        `from_addr`        VARCHAR(255) NULL DEFAULT NULL,
        `from_name`        VARCHAR(255) NULL DEFAULT NULL,
        `reply_to_addr`    VARCHAR(255) NULL DEFAULT NULL,
        `to_addrs`         TEXT         NULL,
        `cc_addrs`         TEXT         NULL,
        `subject`          VARCHAR(500) NULL DEFAULT NULL,
        `date_header`      DATETIME     NULL DEFAULT NULL,
        `received_at`      DATETIME     NOT NULL,
        `body_text`        MEDIUMTEXT   NULL,
        `body_html_safe`   MEDIUMTEXT   NULL,
        `body_truncated`   TINYINT(1)   NOT NULL DEFAULT 0,
        `lang`             VARCHAR(10)  NULL DEFAULT NULL,
        `subject_tr`       VARCHAR(500) NULL DEFAULT NULL,
        `body_tr`          MEDIUMTEXT   NULL,
        `tr_status`        VARCHAR(12)  NOT NULL DEFAULT 'pending',
        `tr_attempts`      INT          NOT NULL DEFAULT 0,
        `tr_next_at`       DATETIME     NULL DEFAULT NULL,
        `tr_error`         VARCHAR(255) NULL DEFAULT NULL,
        `attachments_json` MEDIUMTEXT   NULL,
        `has_attachments`  TINYINT(1)   NOT NULL DEFAULT 0,
        `size_bytes`       INT          NOT NULL DEFAULT 0,
        `imap_seen`        TINYINT(1)   NOT NULL DEFAULT 0,
        `is_read`          TINYINT(1)   NOT NULL DEFAULT 0,
        `read_by`          INT          NULL DEFAULT NULL,
        `read_at`          DATETIME     NULL DEFAULT NULL,
        `needs_reply`      TINYINT(1)   NOT NULL DEFAULT 1,
        `replied_at`       DATETIME     NULL DEFAULT NULL,
        `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_mm_uid` (`account_id`, `folder`, `uidvalidity`, `uid`),
        INDEX `idx_mm_msgid` (`account_id`, `message_id_hash`),
        INDEX `idx_mm_recv` (`account_id`, `received_at`),
        INDEX `idx_mm_thread` (`thread_id`),
        INDEX `idx_mm_tr` (`tr_status`, `tr_next_at`),
        CONSTRAINT `fk_mm_account` FOREIGN KEY (`account_id`)
            REFERENCES `mail_accounts`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT
    )" . $son;

    $t['mail_outbox'] = "CREATE TABLE IF NOT EXISTS `mail_outbox` (
        `id`                  BIGINT AUTO_INCREMENT PRIMARY KEY,
        `account_id`          INT          NOT NULL,
        `in_reply_to_msg_id`  BIGINT       NULL DEFAULT NULL,
        `thread_id`           INT          NULL DEFAULT NULL,
        `idempotency_key`     VARCHAR(64)  NOT NULL,
        `status`              VARCHAR(16)  NOT NULL DEFAULT 'draft',
        `to_addr`             VARCHAR(255) NOT NULL,
        `cc_addr`             TEXT         NULL,
        `subject`             VARCHAR(500) NOT NULL,
        `body_tr`             MEDIUMTEXT   NULL,
        `body_out`            MEDIUMTEXT   NULL,
        `quote_text`          MEDIUMTEXT   NULL,
        `target_lang`         VARCHAR(10)  NULL DEFAULT NULL,
        `tr_provider`         VARCHAR(30)  NULL DEFAULT NULL,
        `quote_original`      TINYINT(1)   NOT NULL DEFAULT 1,
        `content_hash`        CHAR(64)     NULL DEFAULT NULL,
        `approved_hash`       CHAR(64)     NULL DEFAULT NULL,
        `dedupe_key`          VARCHAR(80)  NULL DEFAULT NULL,
        `out_message_id`      VARCHAR(190) NULL DEFAULT NULL,
        `hdr_in_reply_to`     VARCHAR(500) NULL DEFAULT NULL,
        `hdr_references`      TEXT         NULL,
        `approved_by`         INT          NULL DEFAULT NULL,
        `approved_at`         DATETIME     NULL DEFAULT NULL,
        `send_token`          VARCHAR(64)  NULL DEFAULT NULL,
        `send_started_at`     DATETIME     NULL DEFAULT NULL,
        `sent_at`             DATETIME     NULL DEFAULT NULL,
        `attempts`            INT          NOT NULL DEFAULT 0,
        `last_error`          VARCHAR(255) NULL DEFAULT NULL,
        `created_by`          INT          NULL DEFAULT NULL,
        `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`          DATETIME     NULL DEFAULT NULL,
        UNIQUE KEY `uq_mo_idem` (`account_id`, `idempotency_key`),
        UNIQUE KEY `uq_mo_msgid` (`out_message_id`),
        UNIQUE KEY `uq_mo_dedupe` (`dedupe_key`),
        INDEX `idx_mo_status` (`status`),
        INDEX `idx_mo_reply` (`in_reply_to_msg_id`),
        CONSTRAINT `fk_mo_account` FOREIGN KEY (`account_id`)
            REFERENCES `mail_accounts`(`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
    )" . $son;

    $t['mail_sync_state'] = "CREATE TABLE IF NOT EXISTS `mail_sync_state` (
        `id`                   INT AUTO_INCREMENT PRIMARY KEY,
        `account_id`           INT          NOT NULL,
        `folder`               VARCHAR(100) NOT NULL,
        `uidvalidity`          BIGINT       NULL DEFAULT NULL,
        `last_uid`             BIGINT       NOT NULL DEFAULT 0,
        `last_sync_at`         DATETIME     NULL DEFAULT NULL,
        `last_ok_at`           DATETIME     NULL DEFAULT NULL,
        `last_error`           VARCHAR(255) NULL DEFAULT NULL,
        `consecutive_failures` INT          NOT NULL DEFAULT 0,
        `rescan_from_epoch`    BIGINT       NULL DEFAULT NULL,
        UNIQUE KEY `uq_mss_folder` (`account_id`, `folder`),
        CONSTRAINT `fk_mss_account` FOREIGN KEY (`account_id`)
            REFERENCES `mail_accounts`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT
    )" . $son;

    $t['mail_sync_log'] = "CREATE TABLE IF NOT EXISTS `mail_sync_log` (
        `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
        `account_id`  INT          NULL DEFAULT NULL,
        `started_at`  DATETIME     NOT NULL,
        `finished_at` DATETIME     NULL DEFAULT NULL,
        `status`      VARCHAR(10)  NOT NULL DEFAULT 'running',
        `fetched`     INT          NOT NULL DEFAULT 0,
        `inserted`    INT          NOT NULL DEFAULT 0,
        `skipped`     INT          NOT NULL DEFAULT 0,
        `error`       VARCHAR(255) NULL DEFAULT NULL,
        INDEX `idx_msl_acc` (`account_id`, `started_at`)
    )" . $son;

    return $t;
}

/** Tablo var mı — MySQL + SQLite'ta çalışır, istek içinde yalnız "var" önbelleklenir. */
function mail_tablo_var(PDO $pdo, string $tablo): bool
{
    static $var = [];
    $k = spl_object_id($pdo) . ':' . $tablo;
    if (isset($var[$k])) return true;
    if (!preg_match('/^[a-z_]+$/', $tablo)) return false;
    try {
        $pdo->query("SELECT 1 FROM `{$tablo}` LIMIT 0");
        return $var[$k] = true;
    } catch (PDOException $e) {
        return false;
    }
}

/** Tüm mail tabloları kurulu mu. */
function mail_sema_hazir(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? db();
    foreach (array_keys(mail_tablolar()) as $ad) {
        if (!mail_tablo_var($pdo, $ad)) return false;
    }
    return true;
}

/**
 * İDEMPOTENT, yalnız CREATE TABLE. YALNIZ migrate.php (admin, CSRF, audit) çağırır.
 * @return list<array{tablo:string,durum:string,mesaj:string}>
 */
function mail_migrate(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $rapor = [];
    foreach (mail_tablolar() as $ad => $sql) {
        if (mail_tablo_var($pdo, $ad)) {
            $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.'];
            continue;
        }
        try {
            $pdo->exec($sql);
            $rapor[] = mail_tablo_var($pdo, $ad)
                ? ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.']
                : ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'CREATE çalıştı ama tablo görünmüyor.'];
        } catch (PDOException $e) {
            error_log('[mail_migrate] ' . $ad . ': ' . mail_redact($e->getMessage()));
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata',
                        'mesaj' => 'İşlem tamamlanamadı. Teknik ayrıntılar sunucu günlüğüne kaydedildi.'];
        }
    }
    return $rapor;
}

// ── SIR MASKELEME ───────────────────────────────────────────────────────

/** @param string|null $ekle kaydedilecek sır (boşsa yalnız liste döner) @return list<string> */
function mail_redact_sirlar(?string $ekle = null, bool $sifirla = false): array
{
    static $liste = [];
    if ($sifirla) $liste = [];
    if ($ekle !== null && strlen($ekle) >= 4 && !in_array($ekle, $liste, true)) $liste[] = $ekle;
    return $liste;
}

/**
 * Hata/log metninden sırları temizler: kayıtlı sırlar + IMAP LOGIN / AUTHENTICATE
 * argümanları + password=… + uzun base64 jetonları. Log/audit/ekrana giden HER
 * dış kaynaklı hata metni buradan geçmelidir.
 */
function mail_redact(string $s): string
{
    foreach (mail_redact_sirlar() as $sir) {
        $s = str_replace($sir, '***', $s);
        $s = str_replace(base64_encode($sir), '***', $s);
    }
    $s = preg_replace('/\b(LOGIN)\s+("(?:[^"\\\\]|\\\\.)*"|\S+)\s+("(?:[^"\\\\]|\\\\.)*"|\S+)/i', '$1 *** ***', $s) ?? $s;
    $s = preg_replace('/\b(AUTH(?:ENTICATE)?\s+(?:PLAIN|LOGIN|XOAUTH2))\s+\S+/i', '$1 ***', $s) ?? $s;
    $s = preg_replace('/\b(pass(?:word)?|pwd|secret|token|api[_-]?key)\s*[=:]\s*\S+/i', '$1=***', $s) ?? $s;
    $s = preg_replace('/[A-Za-z0-9+\/=_-]{40,}/', '***', $s) ?? $s;
    return $s;
}

// ── KİMLİK BİLGİSİ ŞİFRELEME (AES-256-GCM + AAD) ───────────────────────

/** 32 baytlık ham master key ya da null. Yedek/sabit anahtar YOK. */
function mail_master_key(): ?string
{
    // Canlı dağıtımda config/db.php sunucuya özeldir; eski sürümleri local.php'yi
    // otomatik yüklemeyebilir. Anahtarı mail modülü kendi içinde de yükler.
    // require_once nedeniyle db.php zaten yüklediyse tekrar çalışmaz.
    if (!defined('MAIL_MASTER_KEY') && is_file(__DIR__ . '/local.php')) {
        require_once __DIR__ . '/local.php';
    }
    $b64 = defined('MAIL_MASTER_KEY') ? (string)MAIL_MASTER_KEY : (string)(getenv('MAIL_MASTER_KEY') ?: '');
    $b64 = trim($b64);
    if ($b64 === '') return null;
    $ham = base64_decode($b64, true);
    if ($ham === false || strlen($ham) !== 32) return null;
    return $ham;
}

function mail_crypto_hazir(): bool
{
    return mail_master_key() !== null && function_exists('openssl_encrypt')
        && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
}

/** Anahtarın kısa, sızdırmayan parmak izi (blob'a yazılır; yanlış anahtarı erkenden yakalar). */
function mail_anahtar_kimligi(string $key): string
{
    return substr(hash_hmac('sha256', 'mail-key-id', $key), 0, 8);
}

function mail_aad(int $hesapId, string $alan): string
{
    return 'mail_accounts:' . $hesapId . ':' . $alan;
}

/** @return string 'v1:<kid>:<b64(nonce‖tag‖ct)>' — anahtar yoksa RuntimeException. */
function mail_sifrele(string $duz, string $aad, ?string $anahtar = null): string
{
    $key = $anahtar ?? mail_master_key();
    if ($key === null) throw new RuntimeException('MAIL_MASTER_KEY tanımlı değil.');
    $nonce = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($duz, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
    if ($ct === false || strlen($tag) !== 16) throw new RuntimeException('Şifreleme başarısız.');
    return MAIL_BLOB_SURUM . ':' . mail_anahtar_kimligi($key) . ':' . base64_encode($nonce . $tag . $ct);
}

/** @return string|null çözülen düz metin; anahtar/AAD/blob uyuşmazsa null (asla istisna sızdırmaz). */
function mail_coz(?string $blob, string $aad, ?string $anahtar = null): ?string
{
    if ($blob === null || $blob === '') return null;
    $key = $anahtar ?? mail_master_key();
    if ($key === null) return null;
    $p = explode(':', $blob, 3);
    if (count($p) !== 3 || $p[0] !== MAIL_BLOB_SURUM || !hash_equals(mail_anahtar_kimligi($key), $p[1])) return null;
    $ham = base64_decode($p[2], true);
    if ($ham === false || strlen($ham) < 12 + 16) return null;
    $duz = openssl_decrypt(substr($ham, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
        substr($ham, 0, 12), substr($ham, 12, 16), $aad);
    if ($duz === false) return null;
    mail_redact_sirlar($duz);
    return $duz;
}

/** Base64 biçimindeki 32 baytlık anahtarı ham bayta çevirir; geçersizse null. */
function mail_anahtar_coz_b64(string $b64): ?string
{
    $ham = base64_decode(trim($b64), true);
    return ($ham !== false && strlen($ham) === 32) ? $ham : null;
}

/**
 * MASTER KEY ROTASYONU: tüm hesap şifre blob'larını ESKİ anahtarla çözüp YENİ anahtarla yeniden şifreler.
 * Tek transaction, hep-ya-hiç: herhangi bir blob eski anahtarla çözülemezse HİÇBİR şey yazılmaz.
 * Yazmadan önce her yeni blob geri çözülüp doğrulanır. $uygula=false → yalnız denetim (kuru çalıştırma).
 * Anahtarlar parametre olarak gelir (komut satırı argümanına DEĞİL, ortam değişkenine konur — süreç listesinde görünmesin).
 * @return array{ok:bool,hesap:int,alan:int,mesaj:string}
 */
function mail_anahtar_donustur(PDO $pdo, string $eskiB64, string $yeniB64, bool $uygula = false): array
{
    $eski = mail_anahtar_coz_b64($eskiB64); $yeni = mail_anahtar_coz_b64($yeniB64);
    if ($eski === null || $yeni === null) return ['ok' => false, 'hesap' => 0, 'alan' => 0, 'mesaj' => 'Anahtarlardan biri geçerli değil (32 bayt, base64).'];
    if (hash_equals($eski, $yeni)) return ['ok' => false, 'hesap' => 0, 'alan' => 0, 'mesaj' => 'Eski ve yeni anahtar aynı.'];
    if (!mail_tablo_var($pdo, 'mail_accounts')) return ['ok' => false, 'hesap' => 0, 'alan' => 0, 'mesaj' => 'Mail tabloları kurulu değil.'];
    // Uygulama kipinde OKUMA da transaction içinde ve kilitli (FOR UPDATE): okuma ile yazma arasında kaydedilen yeni parola ezilmez,
    // araya eklenen hesap atlanmaz (MySQL'de eklemeyi de bekletir). Kuru çalıştırma yazmadığı için kilitsiz okur.
    $tx = $uygula;
    $hata = function (string $mesaj) use ($pdo, $tx): array {
        if ($tx && $pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'hesap' => 0, 'alan' => 0, 'mesaj' => $mesaj];
    };
    if ($tx) $pdo->beginTransaction();
    try {
        $kilit = ($tx && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') ? ' FOR UPDATE' : '';
        $satirlar = $pdo->query('SELECT id, imap_pass_enc, smtp_pass_enc FROM mail_accounts ORDER BY id' . $kilit)->fetchAll(PDO::FETCH_ASSOC);
        $plan = []; $alan = 0;
        foreach ($satirlar as $r) {
            $yeniBlob = [];
            foreach (MAIL_SIFRE_ALANLARI as $f) {
                $blob = $r[$f . '_enc'];
                if ($blob === null || $blob === '') { $yeniBlob[$f] = null; continue; }
                $aad = mail_aad((int)$r['id'], $f);
                $duz = mail_coz($blob, $aad, $eski);
                if ($duz === null) return $hata('Hesap #' . (int)$r['id'] . ' (' . $f . ') ESKİ anahtarla çözülemedi — yanlış eski anahtar ya da bozuk kayıt. HİÇBİR ŞEY değiştirilmedi.');
                $nb = mail_sifrele($duz, $aad, $yeni);
                if (mail_coz($nb, $aad, $yeni) !== $duz) return $hata('Yeniden şifreleme doğrulanamadı. HİÇBİR ŞEY değiştirilmedi.');
                $yeniBlob[$f] = $nb; $alan++;
            }
            $plan[(int)$r['id']] = $yeniBlob;
        }
        if (!$uygula) return ['ok' => true, 'hesap' => count($plan), 'alan' => $alan, 'mesaj' => 'Kuru çalıştırma: tüm blob\'lar eski anahtarla çözüldü; yazılmadı.'];
        $up = $pdo->prepare('UPDATE mail_accounts SET imap_pass_enc = ?, smtp_pass_enc = ?, updated_at = ? WHERE id = ?');
        foreach ($plan as $id => $b) $up->execute([$b['imap_pass'], $b['smtp_pass'], date('Y-m-d H:i:s'), $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        return $hata('Yazma başarısız, geri alındı: ' . mail_redact($e->getMessage()));
    }
    return ['ok' => true, 'hesap' => count($plan), 'alan' => $alan, 'mesaj' => 'Yeniden şifrelendi. ŞİMDİ config/local.php içindeki MAIL_MASTER_KEY değerini YENİ anahtarla değiştirin.'];
}

// ── SAYFA KAPISI ──

/** Posta içeriği taşıyan her yanıt tarayıcı/ara katman önbelleğine YAZILMAZ (sw.js de mail yollarını atlar). */
function mail_no_store(): void
{
    if (headers_sent()) return;
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/**
 * Mail sayfası/AJAX kapısı: oturum → aktif depo → can_mail($perm). Başarısızsa
 * 403 (forbidden() AJAX-aware). Sidebar/bottomnav/index kartı AYNI can_mail()'i
 * çağırdığı için "görünür ama 403" oluşmaz.
 */
function require_mail(string $perm): void
{
    if (current_user() === null) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . (function_exists('base_url') ? base_url() : '') . 'login.php' . ($next ? '?next=' . $next : ''));
        exit;
    }
    enforce_active_depot();
    if (!can_mail($perm)) {
        forbidden("Bu sayfaya erişim yetkiniz yok. (Gerekli yetki: mail.{$perm})");
    }
}

// ── GÖRÜNÜRLÜK (hesap ACL — fail-closed) ───────────────────────────────

/** Tüm hesapları yöneten kullanıcı mı (admin ya da mail.admin). */
function mail_tum_hesaplar_mi(): bool
{
    return function_exists('can_mail') && can_mail('admin');
}

/**
 * Kullanıcının görebildiği hesap id'leri. Yönetici: hepsi. Diğerleri: YALNIZ
 * mail_account_users'ta atanmış + AKTİF hesaplar (satır yoksa boş = fail-closed).
 * Okuma yetkisi (can_mail('read')) AYRICA denetlenir — bu fonksiyon yalnız kapsamdır.
 * @return list<int>
 */
function mail_gorunur_hesap_idleri(int $userId, ?PDO $pdo = null, ?bool $yonetici = null): array
{
    $pdo = $pdo ?? db();
    if (!mail_tablo_var($pdo, 'mail_accounts')) return [];
    $yonetici = $yonetici ?? mail_tum_hesaplar_mi();
    try {
        if ($yonetici) {
            return array_map('intval', $pdo->query("SELECT id FROM mail_accounts ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
        }
        if (!mail_tablo_var($pdo, 'mail_account_users')) return [];
        $st = $pdo->prepare("SELECT a.id FROM mail_accounts a
            JOIN mail_account_users u ON u.account_id = a.id
            WHERE u.user_id = ? AND a.is_active = 1 ORDER BY a.id");
        $st->execute([$userId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        return [];
    }
}

function mail_hesap_gorunur_mu(int $hesapId, int $userId, ?PDO $pdo = null, ?bool $yonetici = null): bool
{
    return in_array($hesapId, mail_gorunur_hesap_idleri($userId, $pdo, $yonetici), true);
}

// ── HESAP DEPOSU ────────────────────────────────────────────────────────

function mail_guvenlik_modlari(): array { return ['ssl', 'starttls']; }

/** Hesap formu girdisini doğrular. @return array{ok:bool,hatalar:list<string>,veri:array} */
function mail_hesap_dogrula(array $in, bool $yeni): array
{
    $h = [];
    $s = static fn(string $k, int $max): string => mb_substr(trim((string)($in[$k] ?? '')), 0, $max);
    $v = [
        'label'         => $s('label', 100),
        'email'         => strtolower($s('email', 190)),
        'display_name'  => $s('display_name', 150),
        'imap_host'     => strtolower($s('imap_host', 190)),
        'imap_port'     => (int)($in['imap_port'] ?? 993),
        'imap_security' => $s('imap_security', 10) ?: 'ssl',
        'imap_user'     => $s('imap_user', 190),
        'smtp_host'     => strtolower($s('smtp_host', 190)),
        'smtp_port'     => (int)($in['smtp_port'] ?? 465),
        'smtp_security' => $s('smtp_security', 10) ?: 'ssl',
        'smtp_user'     => $s('smtp_user', 190),
        'reply_to'      => strtolower($s('reply_to', 190)),
        'sync_folder'   => $s('sync_folder', 100) ?: 'INBOX',
        'sent_folder'   => $s('sent_folder', 100),
        'append_sent'   => !empty($in['append_sent']) ? 1 : 0,
        'initial_days'  => max(1, min(365, (int)($in['initial_days'] ?? 30))),
        'translate_enabled' => !empty($in['translate_enabled']) ? 1 : 0,
        'target_lang'   => strtolower($s('target_lang', 10)) ?: 'tr',
        'imap_pass'     => (string)($in['imap_pass'] ?? ''),
        'smtp_pass'     => (string)($in['smtp_pass'] ?? ''),
    ];
    if ($v['label'] === '') $h[] = 'Etiket zorunlu.';
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $h[] = 'Geçerli bir e-posta adresi girin.';
    if ($v['reply_to'] !== '' && !filter_var($v['reply_to'], FILTER_VALIDATE_EMAIL)) $h[] = 'Reply-To adresi geçersiz.';
    foreach (['imap', 'smtp'] as $p) {
        // Host: yalnız ad/IP karakterleri — URL, boşluk, CRLF, şema yok.
        if (!preg_match('/^[a-z0-9]([a-z0-9.-]{0,188}[a-z0-9])?$/', $v[$p . '_host'])) $h[] = strtoupper($p) . ' sunucu adı geçersiz.';
        if ($v[$p . '_port'] < 1 || $v[$p . '_port'] > 65535) $h[] = strtoupper($p) . ' portu geçersiz.';
        if (!in_array($v[$p . '_security'], mail_guvenlik_modlari(), true)) $h[] = strtoupper($p) . ' güvenlik modu geçersiz (yalnız ssl / starttls).';
        if ($v[$p . '_user'] === '' || preg_match('/[\r\n\0]/', $v[$p . '_user'])) $h[] = strtoupper($p) . ' kullanıcı adı geçersiz.';
        if (preg_match('/[\r\n\0]/', $v[$p . '_pass'])) $h[] = strtoupper($p) . ' şifresi geçersiz karakter içeriyor.';
        if ($yeni && $v[$p . '_pass'] === '') $h[] = strtoupper($p) . ' şifresi zorunlu.';
    }
    if (preg_match('/[\r\n\0]/', $v['sync_folder'] . $v['sent_folder'] . $v['label'] . $v['display_name'])) $h[] = 'Alanlarda satır sonu karakteri olamaz.';
    if (!preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $v['target_lang'])) $h[] = 'Hedef dil kodu geçersiz.';
    return ['ok' => $h === [], 'hatalar' => $h, 'veri' => $v];
}

/**
 * Hesap oluşturur/günceller. Şifre alanı boş bırakılırsa MEVCUT şifre korunur.
 * Tek transaction: satır yazılır, şifreler id'ye bağlı AAD ile şifrelenip güncellenir.
 * @return array{ok:bool,id:int,hatalar:list<string>}
 */
function mail_hesap_kaydet(array $in, ?int $id, ?int $userId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!mail_sema_hazir($pdo)) return ['ok' => false, 'id' => 0, 'hatalar' => ['Mail tabloları kurulu değil (migrate.php).']];
    if (!mail_crypto_hazir()) return ['ok' => false, 'id' => 0, 'hatalar' => ['MAIL_MASTER_KEY tanımlı/geçerli değil — şifre saklanamaz.']];
    $d = mail_hesap_dogrula($in, $id === null);
    if (!$d['ok']) return ['ok' => false, 'id' => 0, 'hatalar' => $d['hatalar']];
    $v = $d['veri'];
    foreach (MAIL_SIFRE_ALANLARI as $a) mail_redact_sirlar($v[$a]);

    $alanlar = ['label','email','display_name','imap_host','imap_port','imap_security','imap_user',
        'smtp_host','smtp_port','smtp_security','smtp_user','reply_to','sync_folder','sent_folder',
        'append_sent','initial_days','translate_enabled','target_lang'];
    $nullable = ['display_name','reply_to','sent_folder'];

    // Kayıtlı parola YALNIZ aynı sunucu/kullanıcıyla kullanılabilir: host/port/güvenlik/kullanıcı değişirse parola yeniden girilmeli.
    // Aksi hâlde delege edilmiş bir mail.admin, sunucuyu kendi kontrolündeki bir adrese çevirip "Bağlantıyı Test Et" ile düz parolayı alabilirdi.
    if ($id !== null) {
        $eski = $pdo->prepare('SELECT imap_host, imap_port, imap_security, imap_user, imap_pass_enc, smtp_host, smtp_port, smtp_security, smtp_user, smtp_pass_enc FROM mail_accounts WHERE id = ?');
        $eski->execute([$id]);
        $e0 = $eski->fetch(PDO::FETCH_ASSOC);
        if ($e0) {
            foreach (['imap' => 'IMAP', 'smtp' => 'SMTP'] as $on => $ad) {
                $degisti = strcasecmp((string)$e0[$on . '_host'], (string)$v[$on . '_host']) !== 0 || (int)$e0[$on . '_port'] !== (int)$v[$on . '_port']
                    || (string)$e0[$on . '_security'] !== (string)$v[$on . '_security'] || (string)$e0[$on . '_user'] !== (string)$v[$on . '_user'];
                if ($degisti && $v[$on . '_pass'] === '' && ($e0[$on . '_pass_enc'] ?? '') !== '') {
                    return ['ok' => false, 'id' => 0, 'hatalar' => [$ad . ' sunucu/kullanıcı bilgisi değişti: güvenlik gereği ' . $ad . ' parolasını yeniden girin.']];
                }
            }
        }
    }

    try {
        $pdo->beginTransaction();
        if ($id === null) {
            $cols = $alanlar; $ph = array_fill(0, count($cols), '?');
            $vals = [];
            foreach ($cols as $c) $vals[] = (in_array($c, $nullable, true) && $v[$c] === '') ? null : $v[$c];
            $cols[] = 'created_by'; $ph[] = '?'; $vals[] = $userId;
            $pdo->prepare('INSERT INTO mail_accounts (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')')->execute($vals);
            $id = (int)$pdo->lastInsertId();
        } else {
            $set = []; $vals = [];
            foreach ($alanlar as $c) { $set[] = "$c = ?"; $vals[] = (in_array($c, $nullable, true) && $v[$c] === '') ? null : $v[$c]; }
            $set[] = 'updated_at = ?'; $vals[] = date('Y-m-d H:i:s');
            $vals[] = $id;
            $st = $pdo->prepare('UPDATE mail_accounts SET ' . implode(', ', $set) . ' WHERE id = ?');
            $st->execute($vals);
            $var = $pdo->prepare('SELECT 1 FROM mail_accounts WHERE id = ?'); $var->execute([$id]);
            if (!$var->fetchColumn()) { $pdo->rollBack(); return ['ok' => false, 'id' => 0, 'hatalar' => ['Hesap bulunamadı.']]; }
        }
        foreach (MAIL_SIFRE_ALANLARI as $a) {
            if ($v[$a] === '') continue; // boş = değiştirme
            $pdo->prepare("UPDATE mail_accounts SET {$a}_enc = ? WHERE id = ?")
                ->execute([mail_sifrele($v[$a], mail_aad($id, $a)), $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = $e instanceof PDOException && ($e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE'))
            ? 'Bu e-posta adresi zaten kayıtlı.' : 'Hesap kaydedilemedi.';
        error_log('[mail_hesap_kaydet] ' . mail_redact($e->getMessage()));
        return ['ok' => false, 'id' => 0, 'hatalar' => [$msg]];
    }
    return ['ok' => true, 'id' => $id, 'hatalar' => []];
}

/**
 * Senkron/gönderim için çözülmüş kimlik bilgileriyle hesap (YALNIZ sunucu içi).
 * Dönen diziyi ASLA ekrana/loga/audit'e verme. Anahtar/blob sorunluysa null döner.
 */
function mail_hesap_cred_oku(int $id, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare('SELECT * FROM mail_accounts WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $ip = mail_coz($r['imap_pass_enc'] ?? null, mail_aad($id, 'imap_pass'));
    $sp = mail_coz($r['smtp_pass_enc'] ?? null, mail_aad($id, 'smtp_pass'));
    if ($ip === null || $sp === null) return null;
    unset($r['imap_pass_enc'], $r['smtp_pass_enc']);
    $r['imap_pass'] = $ip;
    $r['smtp_pass'] = $sp;
    return $r;
}

/** Ekrana güvenli hesap satırı (şifre alanları HİÇ yok) + şifre kayıtlı mı bayrakları. */
function mail_hesap_goster(int $id, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare('SELECT * FROM mail_accounts WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['imap_pass_var'] = ($r['imap_pass_enc'] ?? '') !== '' ? 1 : 0;
    $r['smtp_pass_var'] = ($r['smtp_pass_enc'] ?? '') !== '' ? 1 : 0;
    unset($r['imap_pass_enc'], $r['smtp_pass_enc']);
    return $r;
}

/** Hesaba erişecek kullanıcıların TAM listesini değiştirir (yalnız mail.admin çağırır). */
function mail_hesap_kullanici_ata(int $hesapId, array $userIds, ?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($i) => $i > 0)));
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM mail_account_users WHERE account_id = ?')->execute([$hesapId]);
        $ins = $pdo->prepare('INSERT INTO mail_account_users (account_id, user_id) VALUES (?, ?)');
        foreach ($ids as $u) $ins->execute([$hesapId, $u]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** @return list<int> */
function mail_hesap_kullanicilari(int $hesapId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare('SELECT user_id FROM mail_account_users WHERE account_id = ? ORDER BY user_id');
    $st->execute([$hesapId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Görünür hesaplar için okunmamış sayısı: [hesapId => sayı]. Tablo yoksa []. */
function mail_okunmamis_sayilari(int $userId, ?PDO $pdo = null, ?bool $yonetici = null): array
{
    $pdo = $pdo ?? db();
    $ids = mail_gorunur_hesap_idleri($userId, $pdo, $yonetici);
    if ($ids === [] || !mail_tablo_var($pdo, 'mail_messages')) return [];
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT account_id, COUNT(*) c FROM mail_messages WHERE is_read = 0 AND account_id IN ($in) GROUP BY account_id");
        $st->execute($ids);
        $out = array_fill_keys($ids, 0);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['account_id']] = (int)$r['c'];
        return $out;
    } catch (PDOException $e) {
        return [];
    }
}
