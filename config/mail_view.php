<?php
// =========================================================
// config/mail_view.php — Mail Merkezi gelen kutusu: sorgular, ACL, durum değişiklikleri (M3)
//
// HER sorgu çağıranın verdiği görünür hesap listesiyle ($hesapIds) sınırlıdır — bu liste
// mail_gorunur_hesap_idleri()'nden gelir (fail-closed). Boş liste = hiçbir şey döner.
// Mesaj id → hesap → ACL zinciri mail_mesaj_getir()'de; yetkisiz erişim "yok" gibi görünür (varlık sızmaz).
// =========================================================
declare(strict_types=1);

const MAIL_SAYFA_BOYUTU = 30;

/** @return array<string,string> anahtar → etiket (görev §5 filtreleri) */
function mail_filtreler(): array
{
    return ['gelen' => 'Gelen', 'okunmamis' => 'Okunmamış', 'cevap' => 'Cevap Bekleyen',
        'taslak' => 'Taslak / Bekleyen', 'gonderilen' => 'Gönderilen', 'hatali' => 'Hatalı'];
}

/** Giden kutusu (mail_outbox) filtreleri. */
function mail_filtre_outbox_mu(string $f): bool { return in_array($f, ['taslak', 'gonderilen', 'hatali'], true); }

function mail_filtre_gecerli(string $f): string { return array_key_exists($f, mail_filtreler()) ? $f : 'gelen'; }

/** LIKE joker karakterlerini kaçırır (kaçış karakteri '!'). */
function mail_like(string $q): string { return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%'; }

/** Gövde özeti: tek satır, kısaltılmış. */
function mail_oz(?string $metin, int $n = 110): string
{
    $t = trim((string)preg_replace('/\s+/u', ' ', (string)$metin));
    $t = (string)preg_replace('/^(>\s*)+/', '', $t);
    return mb_strlen($t) > $n ? mb_substr($t, 0, $n - 1) . '…' : $t;
}

function mail_boyut_fmt(int $b): string
{
    if ($b >= 1048576) return number_format($b / 1048576, 1, ',', '.') . ' MB';
    if ($b >= 1024) return number_format($b / 1024, 0, ',', '.') . ' KB';
    return $b . ' B';
}

/** Çalıştırılabilir / betik / aktif içerik taşıyabilen ek türleri (indirmede uyarı + octet-stream zorlaması). */
function mail_ek_tehlikeli(string $ad, string $mime = ''): bool
{
    static $uzanti = ['exe', 'com', 'bat', 'cmd', 'scr', 'pif', 'msi', 'msp', 'js', 'jse', 'vbs', 'vbe', 'wsf', 'wsh', 'ps1', 'psm1', 'jar',
        'hta', 'cpl', 'lnk', 'reg', 'dll', 'sys', 'html', 'htm', 'xhtml', 'svg', 'xml', 'iso', 'img', 'dmg', 'apk', 'docm', 'xlsm', 'pptm',
        'dotm', 'xlam', 'ppam', 'sh', 'py', 'pl', 'php', 'asp', 'aspx', 'jsp', 'chm', 'inf', 'gadget', 'app', 'command', 'url', 'one', 'swf'];
    $uz = strtolower((string)pathinfo($ad, PATHINFO_EXTENSION));
    if (in_array($uz, $uzanti, true)) return true;
    return (bool)preg_match('#^(text/html|application/(x-)?(msdownload|javascript|x-sh|x-executable|vnd\.microsoft\.portable-executable|xhtml\+xml)|image/svg\+xml)#i', $mime);
}

/**
 * Liste sorgusu (gelen kutusu tarafı). @return array{satirlar:list<array>,toplam:int}
 * @param list<int> $hesapIds
 */
function mail_mesaj_listele(PDO $pdo, array $hesapIds, string $filtre, string $q, int $sayfa, int $limit = MAIL_SAYFA_BOYUTU): array
{
    if (!$hesapIds) return ['satirlar' => [], 'toplam' => 0];
    $in = implode(',', array_fill(0, count($hesapIds), '?'));
    $w = "account_id IN ($in)"; $p = array_map('intval', $hesapIds);
    if ($filtre === 'okunmamis') $w .= ' AND is_read = 0';
    elseif ($filtre === 'cevap') $w .= ' AND needs_reply = 1 AND replied_at IS NULL';
    if ($q !== '') {
        $w .= " AND (subject LIKE ? ESCAPE '!' OR from_addr LIKE ? ESCAPE '!' OR from_name LIKE ? ESCAPE '!')";
        $l = mail_like($q); array_push($p, $l, $l, $l);
    }
    $c = $pdo->prepare("SELECT COUNT(*) FROM mail_messages WHERE $w");
    $c->execute($p);
    $toplam = (int)$c->fetchColumn();
    $off = max(0, ($sayfa - 1) * $limit);
    $st = $pdo->prepare("SELECT id, account_id, thread_id, from_name, from_addr, subject, received_at, is_read, has_attachments, tr_status,
            needs_reply, replied_at, SUBSTR(body_text, 1, 300) AS ozet
        FROM mail_messages WHERE $w ORDER BY received_at DESC, id DESC LIMIT " . (int)$limit . ' OFFSET ' . (int)$off);
    $st->execute($p);
    return ['satirlar' => $st->fetchAll(PDO::FETCH_ASSOC), 'toplam' => $toplam];
}

/** Giden kutusu listesi. @return array{satirlar:list<array>,toplam:int} */
function mail_outbox_listele(PDO $pdo, array $hesapIds, string $filtre, string $q, int $sayfa, int $limit = MAIL_SAYFA_BOYUTU): array
{
    if (!$hesapIds || !mail_tablo_var($pdo, 'mail_outbox')) return ['satirlar' => [], 'toplam' => 0];
    $durumlar = ['taslak' => ['draft', 'translated', 'approved', 'sending'], 'gonderilen' => ['sent'], 'hatali' => ['failed', 'unknown']][$filtre] ?? [];
    if (!$durumlar) return ['satirlar' => [], 'toplam' => 0];
    $in = implode(',', array_fill(0, count($hesapIds), '?'));
    $dn = implode(',', array_fill(0, count($durumlar), '?'));
    $w = "account_id IN ($in) AND status IN ($dn)"; $p = array_merge(array_map('intval', $hesapIds), $durumlar);
    if ($q !== '') { $w .= " AND (subject LIKE ? ESCAPE '!' OR to_addr LIKE ? ESCAPE '!')"; $l = mail_like($q); array_push($p, $l, $l); }
    $c = $pdo->prepare("SELECT COUNT(*) FROM mail_outbox WHERE $w"); $c->execute($p);
    $off = max(0, ($sayfa - 1) * $limit);
    $st = $pdo->prepare("SELECT id, account_id, in_reply_to_msg_id, status, to_addr, subject, body_tr, body_out, last_error,
            COALESCE(sent_at, updated_at, created_at) AS zaman
        FROM mail_outbox WHERE $w ORDER BY COALESCE(sent_at, updated_at, created_at) DESC, id DESC LIMIT " . (int)$limit . ' OFFSET ' . (int)$off);
    $st->execute($p);
    return ['satirlar' => $st->fetchAll(PDO::FETCH_ASSOC), 'toplam' => (int)$c->fetchColumn()];
}

/** Çevirisi başarısız gelen mailler ("Hatalı" görünümünün ikinci bloğu). */
function mail_ceviri_hatalilari(PDO $pdo, array $hesapIds, int $limit = 20): array
{
    if (!$hesapIds) return [];
    $in = implode(',', array_fill(0, count($hesapIds), '?'));
    $st = $pdo->prepare("SELECT id, account_id, from_name, from_addr, subject, received_at, tr_error FROM mail_messages
        WHERE account_id IN ($in) AND tr_status = 'failed' ORDER BY received_at DESC, id DESC LIMIT " . (int)$limit);
    $st->execute(array_map('intval', $hesapIds));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Filtre rozet sayıları. @return array<string,int> */
function mail_filtre_sayilari(PDO $pdo, array $hesapIds): array
{
    $o = array_fill_keys(array_keys(mail_filtreler()), 0);
    if (!$hesapIds) return $o;
    $in = implode(',', array_fill(0, count($hesapIds), '?'));
    $p = array_map('intval', $hesapIds);
    $q = static function (string $sql, array $par) use ($pdo): int { $s = $pdo->prepare($sql); $s->execute($par); return (int)$s->fetchColumn(); };
    $o['gelen']     = $q("SELECT COUNT(*) FROM mail_messages WHERE account_id IN ($in)", $p);
    $o['okunmamis'] = $q("SELECT COUNT(*) FROM mail_messages WHERE account_id IN ($in) AND is_read = 0", $p);
    $o['cevap']     = $q("SELECT COUNT(*) FROM mail_messages WHERE account_id IN ($in) AND needs_reply = 1 AND replied_at IS NULL", $p);
    if (mail_tablo_var($pdo, 'mail_outbox')) {
        $o['taslak']     = $q("SELECT COUNT(*) FROM mail_outbox WHERE account_id IN ($in) AND status IN ('draft','translated','approved','sending')", $p);
        $o['gonderilen'] = $q("SELECT COUNT(*) FROM mail_outbox WHERE account_id IN ($in) AND status = 'sent'", $p);
        $o['hatali']     = $q("SELECT COUNT(*) FROM mail_outbox WHERE account_id IN ($in) AND status IN ('failed','unknown')", $p)
                         + $q("SELECT COUNT(*) FROM mail_messages WHERE account_id IN ($in) AND tr_status = 'failed'", $p);
    }
    return $o;
}

/**
 * Tek mesaj + ACL. Hesap görünür değilse null (404 gibi davranılır — varlık sızmaz).
 * @param list<int> $hesapIds
 */
function mail_mesaj_getir(PDO $pdo, int $id, array $hesapIds): ?array
{
    if ($id <= 0 || !$hesapIds) return null;
    $st = $pdo->prepare('SELECT * FROM mail_messages WHERE id = ?');
    $st->execute([$id]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m || !in_array((int)$m['account_id'], array_map('intval', $hesapIds), true)) return null;
    $m['to_list'] = json_decode((string)$m['to_addrs'], true) ?: [];
    $m['cc_list'] = json_decode((string)$m['cc_addrs'], true) ?: [];
    $m['ekler']   = json_decode((string)$m['attachments_json'], true) ?: [];
    return $m;
}

/** Aynı konuşmadaki (thread) diğer mesajlar. */
function mail_thread_mesajlari(PDO $pdo, array $m, int $limit = 25): array
{
    if (empty($m['thread_id'])) return [];
    $st = $pdo->prepare('SELECT id, from_name, from_addr, subject, received_at FROM mail_messages
        WHERE account_id = ? AND thread_id = ? AND id <> ? ORDER BY received_at DESC, id DESC LIMIT ' . (int)$limit);
    $st->execute([(int)$m['account_id'], (int)$m['thread_id'], (int)$m['id']]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Okundu/okunmadı. Yalnız görünür hesaptaki mesaj; etkilenen satır sayısı döner. */
function mail_okundu_yaz(PDO $pdo, int $id, int $userId, bool $okundu, array $hesapIds): bool
{
    if (!mail_mesaj_getir($pdo, $id, $hesapIds)) return false;
    if ($okundu) {
        $pdo->prepare('UPDATE mail_messages SET is_read = 1, read_by = ?, read_at = ? WHERE id = ? AND is_read = 0')->execute([$userId, date('Y-m-d H:i:s'), $id]);
    } else {
        $pdo->prepare('UPDATE mail_messages SET is_read = 0, read_by = NULL, read_at = NULL WHERE id = ?')->execute([$id]);
    }
    return true;
}

/** "Cevap bekliyor" ↔ "cevaplandı say" (elle). */
function mail_cevap_durumu_yaz(PDO $pdo, int $id, bool $bekliyor, array $hesapIds): bool
{
    if (!mail_mesaj_getir($pdo, $id, $hesapIds)) return false;
    if ($bekliyor) $pdo->prepare('UPDATE mail_messages SET needs_reply = 1, replied_at = NULL WHERE id = ?')->execute([$id]);
    else $pdo->prepare('UPDATE mail_messages SET needs_reply = 0, replied_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $id]);
    return true;
}

/** Hesap başına son senkron durumu (yalnız görünür hesaplar). @return array<int,array> */
function mail_senkron_durumlari(PDO $pdo, array $hesapIds): array
{
    if (!$hesapIds || !mail_tablo_var($pdo, 'mail_sync_state')) return [];
    $in = implode(',', array_fill(0, count($hesapIds), '?'));
    $st = $pdo->prepare("SELECT account_id, last_ok_at, last_error, consecutive_failures FROM mail_sync_state WHERE account_id IN ($in)");
    $st->execute(array_map('intval', $hesapIds));
    $o = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $o[(int)$r['account_id']] = $r;
    return $o;
}

/** Liste zamanı: bugün → "14:05", bu yıl → "05.10 14:05", eski → "05.10.2025". */
function mail_zaman_fmt(?string $z): string
{
    $t = $z ? strtotime($z) : false;
    if ($t === false) return '';
    if (date('Y-m-d', $t) === date('Y-m-d')) return date('H:i', $t);
    if (date('Y', $t) === date('Y')) return date('d.m H:i', $t);
    return date('d.m.Y', $t);
}

/** Gönderen görünen adı. */
function mail_gonderen_adi(array $m): string
{
    $ad = trim((string)($m['from_name'] ?? ''));
    return $ad !== '' ? $ad : ((string)($m['from_addr'] ?? '') ?: '(bilinmeyen)');
}

function mail_ceviri_durum_etiketi(string $d): string
{
    return ['pending' => 'Çeviri bekleniyor', 'translated' => 'Çevrildi', 'failed' => 'Çeviri başarısız', 'skipped' => 'Çeviri kapalı'][$d] ?? $d;
}

/** mail.css / mail.js — yalnız mail sayfalarında (hesap_assets() emsali; style.css/app.js'e dokunmaz). */
function mail_assets(): void
{
    $v = @filemtime(dirname(__DIR__) . '/assets/mail.css') ?: time();
    echo '<link rel="stylesheet" href="assets/mail.css?v=' . $v . '">' . "\n";
}
function mail_scripts(): void
{
    $v = @filemtime(dirname(__DIR__) . '/assets/mail.js') ?: time();
    echo '<script src="assets/mail.js?v=' . $v . '" defer></script>' . "\n";
}

/**
 * mail.php POST işlemleri (CSRF mail.php'de ÖNCEDEN doğrulanır). exit/header YOK → testte doğrudan çağrılır.
 * @param list<int> $hesapIds
 * @return array{ok:bool,mesaj:string,yasak:?string} yasak doluysa çağıran forbidden() verir
 */
function mail_post_isle(PDO $pdo, int $uid, string $islem, int $mId, int $aSecili, array $hesapIds, bool $yonetici, bool $cevapYetki, array $opt = []): array
{
    $ok = false; $mesaj = '';
    if ($islem === 'oku' || $islem === 'okunmadi') {
        $ok = mail_okundu_yaz($pdo, $mId, $uid, $islem === 'oku', $hesapIds);
        $mesaj = $ok ? ($islem === 'oku' ? 'Okundu olarak işaretlendi.' : 'Okunmadı olarak işaretlendi.') : 'Mesaj bulunamadı.';
    } elseif ($islem === 'cevap_bekliyor' || $islem === 'cevaplandi') {
        if (!$cevapYetki) return ['ok' => false, 'mesaj' => '', 'yasak' => 'Bu işlem için mail.reply yetkisi gerekir.'];
        $ok = mail_cevap_durumu_yaz($pdo, $mId, $islem === 'cevap_bekliyor', $hesapIds);
        $mesaj = $ok ? ($islem === 'cevap_bekliyor' ? 'Cevap bekleyen olarak işaretlendi.' : 'Cevaplandı olarak işaretlendi.') : 'Mesaj bulunamadı.';
    } elseif ($islem === 'senkron') {
        if (!$yonetici) return ['ok' => false, 'mesaj' => '', 'yasak' => 'Bu işlem için mail.admin yetkisi gerekir.'];
        if ($aSecili && in_array($aSecili, $hesapIds, true) && mail_crypto_hazir()) {
            $r = mail_sync_hesap($pdo, $aSecili, ['sure' => 20.0, 'limit' => 100] + $opt);
            $ok = $r['ok'];
            $mesaj = $r['busy'] ? 'Bu hesap şu an başka bir süreç tarafından senkronlanıyor.'
                : ($r['ok'] ? "Senkron tamam: {$r['inserted']} yeni mail." . ($r['kalan'] > 0 ? " ({$r['kalan']} mail sonraki turda)" : '') : 'Senkron başarısız: ' . ($r['error'] ?? '?'));
            audit_log_event('mail_sync_manual', 'mail_accounts', $aSecili, null, ['inserted' => $r['inserted'], 'ok' => $r['ok'] ? 1 : 0]);
        } else {
            $mesaj = $aSecili ? 'MAIL_MASTER_KEY tanımlı değil.' : 'Önce bir hesap seçin.';
        }
    }
    return ['ok' => $ok, 'mesaj' => $mesaj, 'yasak' => null];
}
