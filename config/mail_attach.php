<?php
// =========================================================
// config/mail_attach.php — Ek indirme (M3)
//
// Ek gövdesi ASLA diske/DB'ye yazılmaz: indirme anında yalnız o MIME parçası IMAP'tan çekilir.
// Güvenlik (docs/MAIL_CENTER_AGENT_BRIDGE.md AD-11):
//  • mesaj id → hesap → ACL zinciri; parça numarası mesajın KENDİ ek listesinde olmalı (beyaz liste)
//  • UIDVALIDITY değişmişse (sunucu yeniden indekslemiş) UID artık başka mesajı gösterebilir → İNDİRME REDDEDİLİR
//  • her zaman "attachment"; güvenli olmayan/bilinmeyen türler application/octet-stream'e zorlanır
//  • boyut sınırı (15 MB); dosya adı mail_mime_gonder_adi() ile temizlenmiş halde saklanır
// =========================================================
declare(strict_types=1);

const MAIL_EK_MAX = 15728640;

/** Tarayıcıda "olduğu gibi" tanınmasına izin verilen (zararsız, aktif içerik taşımayan) türler. */
function mail_ek_guvenli_mime(string $mime): bool
{
    return (bool)preg_match('#^(application/pdf|image/(png|jpeg|gif|webp)|text/(plain|csv)|application/zip|application/vnd\.openxmlformats-officedocument\.(wordprocessingml\.document|spreadsheetml\.sheet|presentationml\.presentation)|application/(msword|vnd\.ms-excel|vnd\.ms-powerpoint)|application/vnd\.oasis\.opendocument\.(text|spreadsheet|presentation))$#i', $mime);
}

/**
 * @param list<int> $hesapIds görünür hesaplar
 * @param array $opt istemci: callable(array $hesapCred): MailImapClient (test enjeksiyonu)
 * @return array{ok:true,ad:string,mime:string,veri:string,tehlikeli:bool}|array{ok:false,kod:int,mesaj:string}
 */
function mail_ek_hazirla(PDO $pdo, int $msgId, string $parca, array $hesapIds, array $opt = []): array
{
    $hata = static fn(int $kod, string $mesaj): array => ['ok' => false, 'kod' => $kod, 'mesaj' => $mesaj];
    $m = mail_mesaj_getir($pdo, $msgId, $hesapIds);
    if ($m === null) return $hata(404, 'Ek bulunamadı.');
    $ek = null;
    foreach ($m['ekler'] as $e) if ((string)($e['part'] ?? '') === $parca) { $ek = $e; break; }
    if ($ek === null || !preg_match('/^\d+(\.\d+)*$/', $parca)) return $hata(404, 'Ek bulunamadı.');
    if ((int)($ek['size'] ?? 0) > MAIL_EK_MAX) return $hata(413, 'Ek çok büyük (en çok ' . mail_boyut_fmt(MAIL_EK_MAX) . ').');
    $h = mail_hesap_cred_oku((int)$m['account_id'], $pdo);
    if ($h === null) return $hata(503, 'Hesap kimlik bilgisi çözülemedi.');
    $c = null;
    try {
        $c = isset($opt['istemci']) ? ($opt['istemci'])($h) : mail_imap_baglan($h, 15.0);
        $k = $c->klasorAc((string)$m['folder']);
        if ($k['uidvalidity'] !== (int)$m['uidvalidity']) {
            return $hata(409, 'Sunucudaki posta kutusu yeniden indekslenmiş; bu ek güvenli biçimde indirilemez (yeni bir senkron bekleyin).');
        }
        $ham = $c->uidParca((int)$m['uid'], $parca, MAIL_EK_MAX * 2);   // base64 şişmesi payı
        if ($ham === null) return $hata(404, 'Ek sunucuda bulunamadı (silinmiş olabilir).');
    } catch (Throwable $e) {
        return $hata(502, 'Ek sunucudan alınamadı: ' . mail_hata_metni($e));
    } finally {
        if ($c instanceof MailImapClient) $c->cikis();
    }
    $veri = mail_mime_govde_coz($ham, (string)($ek['cte'] ?? '7bit'));
    if (strlen($veri) > MAIL_EK_MAX) return $hata(413, 'Ek çok büyük.');
    $ad = mail_mime_gonder_adi((string)($ek['filename'] ?? 'ek')) ?: 'ek';
    $mime = (string)($ek['mime'] ?? 'application/octet-stream');
    $tehlikeli = mail_ek_tehlikeli($ad, $mime);
    if ($tehlikeli || !mail_ek_guvenli_mime($mime)) $mime = 'application/octet-stream';
    return ['ok' => true, 'ad' => $ad, 'mime' => $mime, 'veri' => $veri, 'tehlikeli' => $tehlikeli];
}
