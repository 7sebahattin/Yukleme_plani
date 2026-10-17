<?php
// =========================================================
// config/mail_beyan.php — Mail Merkezi → "Beyan Taslağı Oluştur" (Dalga 3A)
//
// Mail okuyucudaki düğme, mailin METNİYLE yeni beyan formunu (beyan_create.php) açar. HİÇBİR ŞEY kaydetmez:
// metin yalnız forma "Kaynak metin" olarak dolar, kullanıcı gözden geçirip Kaydet'e kendisi basar.
//
// Güvenlik:
//  • Mail gövdesi URL'ye YAZILMAZ: uç yalnız mail id'sini POST (CSRF) alır, mail sunucuda hesap ACL'iyle
//    (mail_mesaj_getir + mail_gorunur_hesap_idleri) yeniden okunur; görünür olmayan hesabın maili "bulunamadı".
//  • Metin oturumda TEK KULLANIMLIK taşınır (10 dk TTL, mail id + kullanıcı id bağlı); beyan formu GET'te tüketir.
//  • Gövde güvenilmez veri: kontrol karakterleri atılır, 20.000 karakterle kırpılır; ekrana h() ile basılır
//    (beyan formu zaten <textarea> içine h() ile yazar). Audit'e YALNIZ mail id (+ uzunluk) yazılır.
//  • Yetki: can_mail('read') + can_beyan('create') + can_beyan('source.write') — kaynak metin alanını
//    yazamayan kullanıcının POST'u beyan_protect_input() ile zaten düşürüleceği için düğme ona hiç çıkmaz.
// =========================================================
declare(strict_types=1);

const MAIL_BEYAN_OTURUM = 'mail_beyan_taslak';
const MAIL_BEYAN_MAX    = 20000;   // karakter
const MAIL_BEYAN_TTL    = 600;     // saniye

/** Düğmenin ve ucun TEK yetki kapısı. */
function mail_beyan_taslak_yetkisi(): bool
{
    return function_exists('can_mail') && function_exists('can_beyan')
        && can_mail('read') && can_beyan('create') && can_beyan('source.write');
}

/**
 * Beyan "Kaynak metin" alanına konacak düz metin. Çevrilmiş (Türkçe) gövde varsa o tercih edilir — beyan ayrıştırıcısı
 * Türkçe/İngilizce etiket bekler (PARTİ, ÜRÜN, NET KG …); yoksa özgün düz metin; o da yoksa güvenli HTML'den etiketsiz metin.
 * @param array<string,mixed> $m mail_messages satırı
 */
function mail_beyan_metni(array $m): string
{
    $tr = (string)($m['body_tr'] ?? '');
    $ham = ((string)($m['tr_status'] ?? '') === 'translated' && trim($tr) !== '') ? $tr : (string)($m['body_text'] ?? '');
    if (trim($ham) === '' && trim((string)($m['body_html_safe'] ?? '')) !== '') {
        $h = (string)preg_replace('#<(br|/p|/div|/tr|/li|/h[1-6])\b[^>]*>#i', "\n", (string)$m['body_html_safe']);
        $ham = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $ham = mb_scrub($ham, 'UTF-8');
    $ham = str_replace(["\r\n", "\r"], "\n", $ham);
    $ham = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $ham);
    $ham = (string)preg_replace("/\n{4,}/", "\n\n\n", trim($ham));
    return mb_substr($ham, 0, MAIL_BEYAN_MAX, 'UTF-8');
}

/**
 * Metni hazırlayıp oturuma koyar (exit/header YOK → testte doğrudan çağrılır). Çağıran CSRF + mail_beyan_taslak_yetkisi() ÖNCEDEN denetler.
 * @param list<int> $hesapIds kullanıcının görünür hesapları
 * @return array{ok:bool,mesaj:string}
 */
function mail_beyan_taslak_hazirla(PDO $pdo, int $mailId, int $userId, array $hesapIds): array
{
    $m = mail_mesaj_getir($pdo, $mailId, $hesapIds);   // ACL: yabancı hesap → null (varlık sızmaz)
    if ($m === null) return ['ok' => false, 'mesaj' => 'Mail bulunamadı.'];
    $metin = mail_beyan_metni($m);
    if (trim($metin) === '') return ['ok' => false, 'mesaj' => 'Bu mailde beyan formuna aktarılacak metin yok.'];
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $_SESSION[MAIL_BEYAN_OTURUM] = ['m' => (int)$m['id'], 'u' => $userId, 'metin' => $metin, 't' => time()];
    audit_log_event('mail_beyan_taslak', 'mail', (int)$m['id'], null, ['mail_id' => (int)$m['id'], 'karakter' => mb_strlen($metin, 'UTF-8')]);
    return ['ok' => true, 'mesaj' => ''];
}

/**
 * Beyan formu için TEK KULLANIMLIK okuma: kayıt silinir; süre dolmuş / başka kullanıcıya ait / mail yetkisi kalkmışsa null.
 * @return array{m:int,metin:string}|null
 */
function mail_beyan_taslak_al(int $userId): ?array
{
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $t = $_SESSION[MAIL_BEYAN_OTURUM] ?? null;
    unset($_SESSION[MAIL_BEYAN_OTURUM]);
    if (!is_array($t) || (int)($t['u'] ?? 0) !== $userId || (time() - (int)($t['t'] ?? 0)) > MAIL_BEYAN_TTL) return null;
    if (!function_exists('can_mail') || !can_mail('read')) return null;
    $metin = (string)($t['metin'] ?? '');
    return $metin === '' ? null : ['m' => (int)$t['m'], 'metin' => mb_substr($metin, 0, MAIL_BEYAN_MAX, 'UTF-8')];
}
