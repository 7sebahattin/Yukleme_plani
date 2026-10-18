<?php
// =========================================================
// config/siparis_mail.php — Siparişler ← Mail Merkezi: "Sipariş / Lojistik / Fiyat sorgula" (v378)
//
// Tek tık = tara + içe aktar (önizleme adımı YOK). Mail Merkezi'nin DB'deki kayıtlarından (cron doldurur) son 15 günün
// aday mailleri seçilir (ayarlı posta kutusu + From TAM adres allowlist), PDF ekleri mail_ek_hazirla() ile o an IMAP'tan
// çekilir ve İKİNCİ YOL AÇILMADAN siparis_dosya_yukle_isle() (siparis_yukle.php ile ORTAK) ile içe aktarılır.
//
// Değişmezler (değiştirmeden önce oku):
//  • ŞEMA YOK: ayar + çalışan tarama + son sonuç hks_kv'de (siparis_mail_ayar / _tarama / _son_<tur>); defter = audit_log
//    'siparis_mail_ek' (module siparis_mail, record_id = mail id). Gerçek adres/hesap repoya GİRMEZ (ayar DB'de).
//  • Buton YALNIZ filtredir (gönderen listesi + beklenen belge türü); kesin tür PDF İÇERİĞİNDEN gelir. Türü uyuşmayan,
//    tanınmayan ya da okunamayan PDF için belge satırı AÇILMAZ; silinmiş belge GERİ GETİRİLMEZ (elle yükleme getirir).
//  • Eşleşme yalnız mail_messages.from_addr TAM adres (alan adı / Reply-To YOK). "X adına Y" için iki adres de listeye girer.
//  • Posta kutusuna YAZMA yok: IMAP'a yalnız mail_ek_hazirla() (EXAMINE + BODY.PEEK) üzerinden gidilir; bu dosyada
//    IMAP istemci metodu çağrılmaz (statik test denetler). \Seen / is_read değişmez.
//  • YENİ gelen-kutusu sorgusu mail_gonderilen_disla_sql() içermeli (siparis_mail_adaylar).
//  • Çıktı basmaz / exit etmez; kullanıcı yetkisi uç dosyada + siparis_mail_yetkisi() ile.
// =========================================================
declare(strict_types=1);

require_once __DIR__ . '/siparis_core.php';
require_once __DIR__ . '/siparis_eslestir.php';
require_once __DIR__ . '/mail_core.php';
require_once __DIR__ . '/mail_view.php';

const SIPARIS_MAIL_GUN = 15;            // sabit pencere (ekranda yazar)
const SIPARIS_MAIL_ADIM_SN = 20;        // tek HTTP adımının süre bütçesi
const SIPARIS_MAIL_ADIM_EK = 6;         // tek adımda en çok ek
const SIPARIS_MAIL_MAX_MAIL = 300;      // aday mail sınırı
const SIPARIS_MAIL_MAX_EK = 100;        // tek taramada indirilecek en çok ek
const SIPARIS_MAIL_KIRA_SN = 120;       // başka kullanıcının taraması en az bu kadar sessizse devralınabilir
const SIPARIS_MAIL_SON_SATIR = 150;     // saklanan sonuç satırı sınırı
const SIPARIS_MAIL_BAYAT_DK = 30;       // posta kutusu senkronu bundan eskiyse uyarı
const SIPARIS_MAIL_GONDEREN_MAX = 10;   // tür başına en çok gönderen adresi
const SIPARIS_MAIL_KV_AYAR = 'siparis_mail_ayar';
const SIPARIS_MAIL_KV_TARAMA = 'siparis_mail_tarama';   // son sonuç: 'siparis_mail_son_<tur>'
const SIPARIS_MAIL_KV_SON = 'siparis_mail_son_';

// ── TÜRLER + YETKİ ─────────────────────────────────────────────────────

/** @return array<string,array{etiket:string,dugme:string,belge:string}> tur → etiketler + beklenen belge türü (po|ll|pc) */
function siparis_mail_turleri(): array
{
    return [
        'siparis'  => ['etiket' => 'Sipariş',  'dugme' => '📥 Sipariş sorgula',  'belge' => 'po'],
        'lojistik' => ['etiket' => 'Lojistik', 'dugme' => '🚚 Lojistik sorgula', 'belge' => 'll'],
        'fiyat'    => ['etiket' => 'Fiyat',    'dugme' => '💶 Fiyat sorgula',    'belge' => 'pc'],
    ];
}

/** Belge türü koduna (po/ll/pc) karşılık gelen düğmenin türü; yoksa null. */
function siparis_mail_belge_turu(string $belge): ?string
{
    foreach (siparis_mail_turleri() as $t => $m) if ($m['belge'] === $belge) return $t;
    return null;
}

/** Mailden alma yetkisi: sipariş yazma + Mail Merkezi okuma (yeni yetki YOK, sessiz köprü YOK; fail-closed). */
function siparis_mail_yetkisi(): bool
{
    return function_exists('can_siparis') && function_exists('can_mail') && can_siparis('write') && can_mail('read');
}

/** Ayar düzenleme: sipariş silme (= yönetici düzeyi) + Mail Merkezi yönetimi. */
function siparis_mail_ayar_yetkisi(): bool
{
    return function_exists('can_siparis') && function_exists('can_mail') && can_siparis('delete') && can_mail('admin');
}

// ── hks_kv (şemasız saklama) ───────────────────────────────────────────

/** KV tablo adı — beyan_suggest_kv_table() emsali (HKS_TABLO_ON önekli). */
function siparis_mail_kv_tablo(): string
{
    $on = defined('HKS_TABLO_ON') ? (string)HKS_TABLO_ON : 'hks_';
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $on)) throw new RuntimeException('Geçersiz KV tablo adı.');
    return $on . 'kv';
}

function siparis_mail_kv_oku(PDO $pdo, string $anahtar): ?array
{
    try {
        $st = $pdo->prepare('SELECT deger FROM `' . siparis_mail_kv_tablo() . '` WHERE anahtar = ?');
        $st->execute([$anahtar]);
        $d = $st->fetchColumn();
        if ($d === false || $d === null) return null;
        $j = json_decode((string)$d, true);
        return is_array($j) ? $j : null;
    } catch (Throwable $e) {
        return null;
    }
}

function siparis_mail_kv_yaz(PDO $pdo, string $anahtar, array $deger): bool
{
    try {
        $pdo->prepare('REPLACE INTO `' . siparis_mail_kv_tablo() . '` (anahtar, deger) VALUES (?, ?)')
            ->execute([$anahtar, siparis_json($deger)]);
        return true;
    } catch (Throwable $e) {
        error_log('[siparis_mail] kv yazılamadı: ' . get_class($e));
        return false;
    }
}

function siparis_mail_kv_sil(PDO $pdo, string $anahtar): void
{
    try {
        $pdo->prepare('DELETE FROM `' . siparis_mail_kv_tablo() . '` WHERE anahtar = ?')->execute([$anahtar]);
    } catch (Throwable $e) {
        error_log('[siparis_mail] kv silinemedi: ' . get_class($e));
    }
}

/** Özellik kurulu mu: sipariş + mail şeması, MAIL_MASTER_KEY, hks_kv tablosu. Kurulu değilse düğmeler HİÇ çizilmez. */
function siparis_mail_hazir(PDO $pdo): bool
{
    try {
        if (!siparis_sema_hazir($pdo) || !mail_sema_hazir($pdo) || !mail_crypto_hazir()) return false;
        $pdo->query('SELECT 1 FROM `' . siparis_mail_kv_tablo() . '` LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

// ── AYAR ───────────────────────────────────────────────────────────────

/** Satır/virgül/noktalı virgül/boşlukla ayrılmış metin ya da dizi → ham öğe listesi. */
function siparis_mail_liste_ayir(mixed $girdi): array
{
    if (is_array($girdi)) $parcalar = array_map('strval', $girdi);
    else $parcalar = preg_split('/[\s,;]+/u', (string)$girdi) ?: [];
    return array_values(array_filter(array_map('trim', $parcalar), static fn(string $s): bool => $s !== ''));
}

/** @return array{hesaplar:list<int>,gonderen:array<string,list<string>>} okunamayan/bozuk ayar → boş yapı (fail-closed). */
function siparis_mail_ayar_oku(PDO $pdo): array
{
    $bos = ['hesaplar' => [], 'gonderen' => array_fill_keys(array_keys(siparis_mail_turleri()), [])];
    $j = siparis_mail_kv_oku($pdo, SIPARIS_MAIL_KV_AYAR);
    if ($j === null) return $bos;
    $h = [];
    foreach ((array)($j['hesaplar'] ?? []) as $v) if (is_numeric($v) && (int)$v > 0) $h[(int)$v] = true;
    $out = ['hesaplar' => array_keys($h), 'gonderen' => $bos['gonderen']];
    foreach (array_keys($out['gonderen']) as $t) {
        $l = [];
        foreach ((array)($j['gonderen'][$t] ?? []) as $a) {
            $a = strtolower(trim((string)$a));
            if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL) !== false) $l[$a] = true;
        }
        $out['gonderen'][$t] = array_slice(array_keys($l), 0, SIPARIS_MAIL_GONDEREN_MAX);
    }
    return $out;
}

/** Ayar ekranı için seçilebilir (aktif) posta kutuları. @return list<array{id:int,label:string,email:string}> */
function siparis_mail_hesaplar(PDO $pdo): array
{
    try {
        $r = $pdo->query('SELECT id, label, email FROM mail_accounts WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    return array_map(static fn(array $x): array => ['id' => (int)$x['id'], 'label' => (string)$x['label'], 'email' => (string)$x['email']], $r);
}

/**
 * Ayarı doğrular ve yazar. $girdi: ['hesaplar'=>[id…], 'gonderen'=>[tur => metin|dizi]].
 * Gönderen adresleri küçük harfe çevrilir, tekilleştirilir, tür başına ≤ 10; geçersiz e-posta / olmayan (pasif) hesap REDDEDİLİR.
 * Audit yalnız SAYILARI yazar (adres YAZILMAZ).
 * @return array{ok:bool,hata?:string}
 */
function siparis_mail_ayar_kaydet(PDO $pdo, array $girdi, int $uid): array
{
    if (!siparis_mail_ayar_yetkisi()) return ['ok' => false, 'hata' => 'Mail sorgu ayarlarını değiştirme yetkiniz yok.'];
    $aktif = array_column(siparis_mail_hesaplar($pdo), 'id');
    $hesaplar = [];
    foreach ((array)($girdi['hesaplar'] ?? []) as $v) {
        if (!is_numeric($v) || !in_array((int)$v, $aktif, true)) return ['ok' => false, 'hata' => 'Geçersiz posta kutusu seçimi.'];
        $hesaplar[(int)$v] = true;
    }
    $gonderen = [];
    foreach (siparis_mail_turleri() as $tur => $m) {
        $l = [];
        foreach (siparis_mail_liste_ayir($girdi['gonderen'][$tur] ?? '') as $a) {
            $a = strtolower($a);
            if (filter_var($a, FILTER_VALIDATE_EMAIL) === false || strlen($a) > 255) {
                return ['ok' => false, 'hata' => $m['etiket'] . ' için geçersiz e-posta adresi: ' . siparis_kirp($a, 60)];
            }
            $l[$a] = true;
        }
        if (count($l) > SIPARIS_MAIL_GONDEREN_MAX) return ['ok' => false, 'hata' => $m['etiket'] . ' için en çok ' . SIPARIS_MAIL_GONDEREN_MAX . ' gönderen adresi girilebilir.'];
        $gonderen[$tur] = array_keys($l);
    }
    if (!siparis_mail_kv_yaz($pdo, SIPARIS_MAIL_KV_AYAR, ['hesaplar' => array_keys($hesaplar), 'gonderen' => $gonderen])) {
        return ['ok' => false, 'hata' => 'Ayar kaydedilemedi (hks_kv tablosu yazılamadı).'];
    }
    siparis_audit('siparis_mail_ayar', null, null, ['hesap_sayisi' => count($hesaplar), 'gonderen_sayisi' => array_map('count', $gonderen)], $uid);
    return ['ok' => true];
}

function siparis_mail_ayarli_mi(array $ayar, string $tur): bool
{
    return !empty($ayar['hesaplar']) && !empty($ayar['gonderen'][$tur]);
}

// ── ADAY SEÇİMİ + PDF EKLERİ ───────────────────────────────────────────

/**
 * Pencere içindeki aday mailler (Gönderilenler klasörü DIŞLANIR — mail_gonderilen_disla_sql ZORUNLU).
 * From TAM adres eşleşmesi (küçük harf); Reply-To / alan adı kullanılmaz.
 * @param list<int> $hesapIds @param list<string> $gonderenler @return list<array<string,mixed>>
 */
function siparis_mail_adaylar(PDO $pdo, array $hesapIds, array $gonderenler, string $baslangic, int $limit): array
{
    $hesapIds = array_values(array_unique(array_map('intval', $hesapIds)));
    $gonderenler = array_values(array_unique(array_map(static fn($a): string => strtolower(trim((string)$a)), $gonderenler)));
    if (!$hesapIds || !$gonderenler) return [];
    $inH = implode(',', array_fill(0, count($hesapIds), '?'));
    $inG = implode(',', array_fill(0, count($gonderenler), '?'));
    $st = $pdo->prepare("SELECT id, account_id, folder, message_id_hash, from_addr, subject, received_at, attachments_json, body_truncated, has_attachments
        FROM mail_messages
        WHERE account_id IN ($inH) AND has_attachments = 1 AND received_at >= ? AND LOWER(from_addr) IN ($inG)"
        . mail_gonderilen_disla_sql() . ' ORDER BY received_at ASC, id ASC LIMIT ' . max(1, $limit));
    $st->execute(array_merge($hesapIds, [$baslangic], $gonderenler));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Mailin ek listesinden PDF'leri seçer (SAF; DB/IMAP yok). PDF ⇔ mime application/pdf|x-pdf VEYA ad .pdf ile biter
 * (octet-stream dahil). `inline` bayrağına BAKILMAZ (bazı istemciler PDF'i inline gönderir).
 * 'iletilen' = message/rfc822 parçaları (bu sürümde açılmaz). 'sorun' = 'ek_eksik': PDF/iletilen yok ama mail kesik okunmuş
 * (>10 MB ya da çökme yer tutucusu) — ek listesi eksik olabilir.
 * @return array{ekler:list<array{part:string,filename:string,size:int,mime:string}>,iletilen:list<array{part:string,filename:string}>,sorun:?string}
 */
function siparis_mail_pdf_ekleri(array $mail): array
{
    $ekler = []; $iletilen = [];
    $liste = json_decode((string)($mail['attachments_json'] ?? ''), true);
    foreach (is_array($liste) ? $liste : [] as $e) {
        if (!is_array($e)) continue;
        $part = (string)($e['part'] ?? '');
        if (!preg_match('/^\d+(\.\d+)*$/', $part)) continue;
        $mime = strtolower(trim((string)($e['mime'] ?? '')));
        $ad = (string)($e['filename'] ?? '');
        if ($mime === 'message/rfc822') { $iletilen[] = ['part' => $part, 'filename' => $ad]; continue; }
        if (in_array($mime, ['application/pdf', 'application/x-pdf'], true) || preg_match('/\.pdf\s*$/i', $ad)) {
            $ekler[] = ['part' => $part, 'filename' => $ad, 'size' => (int)($e['size'] ?? 0), 'mime' => $mime];
        }
    }
    $sorun = null;
    $yerTutucu = str_starts_with(trim((string)($mail['subject'] ?? '')), '(işlenemedi');
    if (!$ekler && !$iletilen && ((int)($mail['body_truncated'] ?? 0) === 1 || $yerTutucu)) $sorun = 'ek_eksik';
    return ['ekler' => $ekler, 'iletilen' => $iletilen, 'sorun' => $sorun];
}

// ── DEFTER (audit_log 'siparis_mail_ek') ───────────────────────────────

/**
 * Mail başına ek kararları: [mailId][parca] => {sonuc, belge_id, tur, surum, mesaj} (aynı ek için SON kayıt geçerli).
 * @param list<int> $mailIds
 */
function siparis_mail_defter(PDO $pdo, array $mailIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $mailIds), static fn(int $i): bool => $i > 0 && $i <= 2147483647)));
    $out = [];
    foreach (array_chunk($ids, 400) as $parca) {
        $in = implode(',', array_fill(0, count($parca), '?'));
        $st = $pdo->prepare("SELECT record_id, new_values FROM audit_log WHERE module = 'siparis_mail' AND action = 'siparis_mail_ek' AND record_id IN ($in) ORDER BY id ASC");
        $st->execute($parca);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $j = json_decode((string)$r['new_values'], true);
            if (!is_array($j) || !isset($j['parca'])) continue;
            $out[(int)$r['record_id']][(string)$j['parca']] = [
                'sonuc' => (string)($j['sonuc'] ?? ''), 'belge_id' => isset($j['belge_id']) ? (int)$j['belge_id'] : null,
                'tur' => isset($j['tur']) ? (string)$j['tur'] : null, 'surum' => isset($j['surum']) ? (string)$j['surum'] : null,
                'mesaj' => isset($j['mesaj']) ? (string)$j['mesaj'] : null,
            ];
        }
    }
    return $out;
}

/** Kararı deftere yazar (kişisel veri YOK: adres/konu/dosya adı yazılmaz). id > INT sınırı → atlanır (her taramada yeniden indirilir; zararsız). */
function siparis_mail_defter_yaz(int $mailId, string $parca, string $sonuc, int $hesapId, int $uid, array $ek = []): void
{
    if ($mailId <= 0 || $mailId > 2147483647) return;
    audit_log_event('siparis_mail_ek', 'siparis_mail', $mailId, null, [
        'parca' => $parca, 'sonuc' => $sonuc, 'surum' => SIPARIS_PDF_SURUM, 'hesap_id' => $hesapId,
    ] + $ek, $uid);
}

/**
 * Defter kaydı bu düğme için SON karar mı? (değilse ek yeniden indirilir/denenir)
 * alindi/zaten/silinmis/gecersiz → evet · taninmadi → yalnız aynı ayrıştırıcı sürümüyle · baska_tur → yalnız defterdeki tür
 * bu düğmenin türünden FARKLIYSA (aynıysa doğru düğmede alınabilir) · hata/diğer → hayır.
 */
function siparis_mail_defter_son_mu(array $d, string $beklenen): bool
{
    $s = (string)($d['sonuc'] ?? '');
    if (in_array($s, ['alindi', 'zaten', 'silinmis', 'gecersiz'], true)) return true;
    if ($s === 'taninmadi') return (string)($d['surum'] ?? '') === SIPARIS_PDF_SURUM;
    if ($s === 'baska_tur') return isset($d['tur']) && $d['tur'] !== null && $d['tur'] !== $beklenen;
    return false;
}

// ── PLAN ───────────────────────────────────────────────────────────────

function siparis_mail_sayac_bos(): array
{
    return ['mail' => 0, 'pdf' => 0, 'alindi' => 0, 'zaten' => 0, 'onceden' => 0, 'atlandi' => 0, 'hata' => 0];
}

/** Sonuç durumunu sayaç anahtarına çevirir. */
function siparis_mail_sayac_anahtari(string $durum): string
{
    return match ($durum) {
        'alindi' => 'alindi', 'zaten' => 'zaten', 'onceden' => 'onceden', 'hata' => 'hata',
        default => 'atlandi',
    };
}

function siparis_mail_satir(array $oge, string $durum, array $ek = []): array
{
    return $ek + [
        'm' => (int)$oge['m'], 'p' => (string)$oge['p'], 'dosya' => (string)($oge['dosya'] ?? ''), 'durum' => $durum, 'tur' => null,
        'belge_id' => null, 'sevkiyatlar' => [], 'catisma' => 0, 'uyari' => 0, 'mesajlar' => [], 'hata' => null, 'kopya' => (int)($oge['kopya'] ?? 1),
    ];
}

/**
 * Planı DB'den kurar (IMAP YOK, anında). Dönüş:
 *  oge[]     indirilecek ekler (en çok 100): {m,h,p,dosya,size,alt:[{m,h,p}…],kopya}
 *  satirlar  ön-çözülmüş satırlar (onceden / iletilmis / ek_eksik / cok_buyuk / defterden gelen atlananlar)
 *  sayac, uyarilar
 * @param list<int> $hesapIds ayar ∩ görünür hesaplar (ayar sırası korunur)
 */
function siparis_mail_plan(PDO $pdo, string $tur, array $hesapIds, array $ayar, string $simdi): array
{
    $turler = siparis_mail_turleri();
    $beklenen = $turler[$tur]['belge'] ?? '';
    $sayac = siparis_mail_sayac_bos();
    $uyarilar = [];
    $plan = ['oge' => [], 'satirlar' => [], 'sayac' => $sayac, 'uyarilar' => $uyarilar];
    if ($beklenen === '') return $plan;
    $t = strtotime($simdi);
    $bas = date('Y-m-d 00:00:00', strtotime('-' . SIPARIS_MAIL_GUN . ' days', $t !== false ? $t : time()));
    $satirlar = siparis_mail_adaylar($pdo, $hesapIds, (array)($ayar['gonderen'][$tur] ?? []), $bas, SIPARIS_MAIL_MAX_MAIL);
    if (count($satirlar) >= SIPARIS_MAIL_MAX_MAIL) $uyarilar[] = 'Son ' . SIPARIS_MAIL_GUN . ' günde ' . SIPARIS_MAIL_MAX_MAIL . ' mailden fazlası var; en eskileri işlendi — kalanlar için düğmeye yeniden basın.';

    // Aynı Message-ID birden çok kutuda/klasörde: ilk (ayar sırasındaki) hesap asıl, diğerleri yedek kopya
    $sira = array_flip(array_map('intval', $hesapIds));
    usort($satirlar, static function (array $a, array $b) use ($sira): int {
        return [(string)$a['received_at'], (int)($sira[(int)$a['account_id']] ?? 99), (int)$a['id']] <=> [(string)$b['received_at'], (int)($sira[(int)$b['account_id']] ?? 99), (int)$b['id']];
    });
    $gruplar = [];
    foreach ($satirlar as $s) $gruplar[(string)$s['message_id_hash'] . '|' . (string)($s['message_id_hash'] === '' ? $s['id'] : '')][] = $s;
    $defter = siparis_mail_defter($pdo, array_map(static fn(array $s): int => (int)$s['id'], $satirlar));

    foreach ($gruplar as $kopyalar) {
        // asıl kopya: hesap sırası önce gelen
        usort($kopyalar, static fn(array $a, array $b): int => ((int)($sira[(int)$a['account_id']] ?? 99) <=> (int)($sira[(int)$b['account_id']] ?? 99)) ?: ((int)$a['id'] <=> (int)$b['id']));
        $asil = $kopyalar[0];
        $plan['sayac']['mail']++;
        $ekSec = siparis_mail_pdf_ekleri($asil);
        if ($ekSec['sorun'] === 'ek_eksik') {
            $plan['satirlar'][] = siparis_mail_satir(['m' => $asil['id'], 'p' => '', 'dosya' => '(ek listesi eksik)'], 'ek_eksik');
            continue;
        }
        if (!$ekSec['ekler'] && !$ekSec['iletilen']) { $plan['pdfsiz'] = ($plan['pdfsiz'] ?? 0) + 1; continue; }
        foreach ($ekSec['iletilen'] as $il) {
            $plan['satirlar'][] = siparis_mail_satir(['m' => $asil['id'], 'p' => $il['part'], 'dosya' => $il['filename'], 'kopya' => count($kopyalar)], 'iletilmis');
        }
        foreach ($ekSec['ekler'] as $ek) {
            $plan['sayac']['pdf']++;
            $ad = $ek['filename'] !== '' ? $ek['filename'] : ('ek-' . $ek['part'] . '.pdf');
            $oge = ['m' => (int)$asil['id'], 'h' => (int)$asil['account_id'], 'p' => $ek['part'], 'dosya' => siparis_kirp(basename(str_replace('\\', '/', $ad)), 120),
                    'size' => $ek['size'], 'alt' => [], 'kopya' => count($kopyalar)];
            // yedek kopyalar: aynı parça numarasında PDF taşıyan kopyalar
            foreach (array_slice($kopyalar, 1) as $k) {
                foreach (siparis_mail_pdf_ekleri($k)['ekler'] as $ke) {
                    if ($ke['part'] === $ek['part']) { $oge['alt'][] = ['m' => (int)$k['id'], 'h' => (int)$k['account_id'], 'p' => $ke['part']]; break; }
                }
            }
            if ($ek['size'] > SIPARIS_MAX_DOSYA) { $plan['satirlar'][] = siparis_mail_satir($oge, 'cok_buyuk'); continue; }
            // defter: grubun herhangi bir kopyasında bu düğme için SON karar varsa indirme YOK
            $karar = null;
            foreach ($kopyalar as $k) {
                $d = $defter[(int)$k['id']][$ek['part']] ?? null;
                if ($d !== null && siparis_mail_defter_son_mu($d, $beklenen)) { $karar = $d; break; }
            }
            if ($karar !== null) {
                $durum = in_array($karar['sonuc'], ['alindi', 'zaten'], true) ? 'onceden' : $karar['sonuc'];
                $plan['satirlar'][] = siparis_mail_satir($oge, $durum, ['tur' => $karar['tur'], 'belge_id' => $karar['belge_id'], 'hata' => $karar['mesaj']]);
                continue;
            }
            $plan['oge'][] = $oge;
        }
    }
    if (count($plan['oge']) > SIPARIS_MAIL_MAX_EK) {
        $uyarilar[] = 'İlk ' . SIPARIS_MAIL_MAX_EK . ' ek işlendi; kalanlar için düğmeye yeniden basın.';
        $plan['oge'] = array_slice($plan['oge'], 0, SIPARIS_MAIL_MAX_EK);
    }
    foreach ($plan['satirlar'] as $s) $plan['sayac'][siparis_mail_sayac_anahtari($s['durum'])]++;
    $plan['uyarilar'] = $uyarilar;
    return $plan;
}

// ── TEK EKİN İŞLENİŞİ ──────────────────────────────────────────────────

/** Ek adı .pdf ile bitmiyorsa ekler (siparis_dosya_dogrula uzantıyı DOSYA ADINDAN okur; adsız parçalara 'ek-N' verilir). */
function siparis_mail_pdf_adi(string $ad): string
{
    $ad = trim(basename(str_replace('\\', '/', $ad)));
    if ($ad === '') $ad = 'ek';
    return preg_match('/\.pdf$/i', $ad) ? $ad : ($ad . '.pdf');
}

/**
 * Tek eki işler (sıra SABİT): boyut → mail_ek_hazirla (+yedek kopya) → geçici dosya → doğrulama → sha (silinmiş/var) →
 * ayrıştır + tür kapısı → ortak yükleme fonksiyonu → defter.
 * @param array $oge {m,h,p,dosya,alt[],kopya,size} @param list<int> $hesapIds görünür hesaplar
 * @param array $opt istemci: callable (mail_ek_hazirla test kancası)
 * @return array satır (siparis_mail_satir biçimi)
 */
function siparis_mail_ek_isle(PDO $pdo, array $oge, string $beklenen, int $uid, string $depo, array $hesapIds, array $opt = []): array
{
    $m = (int)$oge['m']; $h = (int)($oge['h'] ?? 0); $p = (string)$oge['p'];
    if ((int)($oge['size'] ?? 0) > SIPARIS_MAX_DOSYA) return siparis_mail_satir($oge, 'cok_buyuk');   // IMAP'a hiç gidilmez

    // 2) IMAP'tan tek parça (asıl kopya, olmazsa yedek kopyalar)
    $hazirOpt = isset($opt['istemci']) ? ['istemci' => $opt['istemci']] : [];
    $denemeler = array_merge([['m' => $m, 'h' => $h, 'p' => $p]], (array)($oge['alt'] ?? []));
    $r = ['ok' => false, 'kod' => 404, 'mesaj' => 'Ek bulunamadı.'];
    foreach ($denemeler as $d) {
        $r = mail_ek_hazirla($pdo, (int)$d['m'], (string)$d['p'], $hesapIds, $hazirOpt);
        if ($r['ok']) { $m = (int)$d['m']; $h = (int)$d['h']; break; }
        if (($r['kod'] ?? 0) === 413) break;
    }
    if (!$r['ok']) {
        if (($r['kod'] ?? 0) === 413) return siparis_mail_satir($oge, 'cok_buyuk');
        return siparis_mail_satir($oge, 'hata', ['hata' => (string)$r['mesaj']]);   // defter YOK: sonraki sorguda yeniden denenir
    }

    $ad = siparis_mail_pdf_adi((string)($r['ad'] ?? ''));
    $tmp = (string)tempnam(sys_get_temp_dir(), 'sipm');
    try {
        if ($tmp === '' || file_put_contents($tmp, (string)$r['veri']) === false) return siparis_mail_satir($oge, 'hata', ['hata' => 'Geçici dosya yazılamadı.']);
        unset($r['veri']);
        $dosya = ['name' => $ad, 'type' => 'application/pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => (int)filesize($tmp)];
        $kaydet = static function (string $sonuc, array $ek = [], array $defterEk = []) use ($oge, $m, $p, $h, $uid, $ad): array {
            $satir = siparis_mail_satir($oge, $sonuc, ['dosya' => siparis_kirp($ad, 120)] + $ek);
            siparis_mail_defter_yaz($m, $p, $sonuc, $h, $uid, $defterEk + ['belge_id' => $satir['belge_id'], 'tur' => $satir['tur']]);
            return $satir;
        };

        // 3) geçerli PDF mi (boyut, uzantı, finfo, %PDF-)
        $dg = siparis_dosya_dogrula($dosya);
        if (!$dg['ok']) return $kaydet('gecersiz', ['hata' => (string)($dg['hata'] ?? 'Geçerli bir PDF değil.')], ['mesaj' => siparis_kirp((string)($dg['hata'] ?? ''), 120)]);

        // 4) aynı içerik zaten var mı
        $sha = (string)hash_file('sha256', $tmp);
        $o = $pdo->prepare('SELECT id, deleted_at FROM siparis_belgeler WHERE sha256 = ?');
        $o->execute([$sha]);
        $var = $o->fetch(PDO::FETCH_ASSOC);
        if ($var && $var['deleted_at'] !== null) return $kaydet('silinmis', ['belge_id' => (int)$var['id']], ['sha256' => $sha]);

        // 5) yeni içerik: tür kapısı (belge satırı AÇILMADAN)
        if (!$var) {
            $override = $GLOBALS['_siparis_parse_override'] ?? null;     // YALNIZ TEST KANCASI (siparis_belge_isle ile aynı)
            $doc = is_callable($override) ? (array)$override($tmp) : siparis_pdf_isle($tmp);
            if (($doc['doc_type'] ?? '') === 'error') {
                return $kaydet('gecersiz', ['hata' => 'PDF okunamadı (bozuk, şifreli ya da desteklenmeyen biçim).'], ['sha256' => $sha, 'mesaj' => 'PDF okunamadı']);
            }
            $t = siparis_pdf_tur_kodu($doc);
            if ($t === 'bilinmiyor') return $kaydet('taninmadi', ['tur' => null], ['sha256' => $sha]);
            if ($t !== $beklenen) return $kaydet('baska_tur', ['tur' => $t], ['sha256' => $sha]);
        }

        // 6) ORTAK yazma yolu (siparis_yukle.php ile aynı fonksiyon)
        $x = siparis_dosya_yukle_isle($pdo, $dosya, $uid, $depo, null, 'copy', [
            'kaynak' => 'mail', 'islem' => 'mail', 'silineni_geri_getir' => false,
            'audit_ek' => ['mail_id' => $m, 'hesap_id' => $h, 'parca' => $p],
        ]);
        $ortak = ['tur' => $x['tur'], 'belge_id' => $x['belge_id'], 'sevkiyatlar' => $x['sevkiyatlar'], 'catisma' => $x['catisma'], 'uyari' => $x['uyari'],
                  'mesajlar' => array_slice($x['mesajlar'], 0, 3)];
        return match ($x['durum']) {
            'islendi' => $kaydet('alindi', $ortak, ['sha256' => $sha]),
            'zaten' => $kaydet('zaten', $ortak, ['sha256' => $sha]),
            'silinmis' => $kaydet('silinmis', $ortak, ['sha256' => $sha]),
            default => siparis_mail_satir($oge, 'hata', ['dosya' => siparis_kirp($ad, 120), 'hata' => (string)($x['hata'] ?? 'Belge işlenemedi.')] + $ortak),
        };
    } catch (Throwable $e) {
        error_log('[siparis_mail] ek işlenemedi: ' . get_class($e) . ': ' . mail_redact($e->getMessage()));
        return siparis_mail_satir($oge, 'hata', ['hata' => 'Ek işlenemedi. Teknik ayrıntılar sunucu günlüğüne kaydedildi.']);
    } finally {
        if ($tmp !== '') @unlink($tmp);
    }
}

// ── TARAMA (parçalı yürütme) ───────────────────────────────────────────

/** @return array{ok:false,http:int,kod:string,mesaj:string} */
function siparis_mail_hata(int $http, string $kod, string $mesaj): array
{
    return ['ok' => false, 'http' => $http, 'kod' => $kod, 'mesaj' => $mesaj];
}

/** Tarama gövdesini 'siparis_mail' dosya kilidi içinde çalıştırır (aynı anda tek tarama adımı). Kilit alınamazsa 409. */
function siparis_mail_kilitli(callable $fn): array
{
    try {
        $fh = mail_kilit_al('siparis_mail');
    } catch (MailKilitHatasi $e) {
        return siparis_mail_hata(503, 'kilit', 'Kilit dosyası açılamadı (storage/mail yazılabilir mi?).');
    }
    if ($fh === null) return siparis_mail_hata(409, 'mesgul', 'Başka bir mail sorgusu şu an çalışıyor. Birkaç saniye sonra yeniden deneyin.');
    try {
        return $fn();
    } finally {
        mail_kilit_birak($fh);
    }
}

function siparis_mail_kullanici_adi(PDO $pdo, int $uid): string
{
    try {
        $st = $pdo->prepare('SELECT display_name, username FROM users WHERE id = ?');
        $st->execute([$uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) return trim((string)($r['display_name'] ?? '')) !== '' ? (string)$r['display_name'] : (string)($r['username'] ?? ('#' . $uid));
    } catch (Throwable $e) {}
    return '#' . $uid;
}

/** Ayarlı ∩ görünür hesaplar (ayar sırası korunur). @return list<int> */
function siparis_mail_tarama_hesaplari(PDO $pdo, array $ayar, int $uid): array
{
    $gorunur = mail_gorunur_hesap_idleri($uid, $pdo);
    return array_values(array_filter(array_map('intval', (array)$ayar['hesaplar']), static fn(int $i): bool => in_array($i, $gorunur, true)));
}

/** Son senkron bayatlığı uyarısı (cron durduysa DB bayattır). @return list<string> */
function siparis_mail_bayatlik_uyarilari(PDO $pdo, array $hesapIds, int $simdiTs): array
{
    $d = mail_senkron_durumlari($pdo, $hesapIds);
    $enEski = null; $hic = false;
    foreach ($hesapIds as $id) {
        $ok = $d[$id]['last_ok_at'] ?? null;
        $t = $ok ? strtotime((string)$ok) : false;
        if ($t === false) { $hic = true; continue; }
        if ($enEski === null || $t < $enEski) $enEski = $t;
    }
    if ($hic) return ['Posta kutusu henüz hiç senkronlanmamış görünüyor — yeni mailler henüz gelmemiş olabilir.'];
    if ($enEski !== null && $simdiTs - $enEski > SIPARIS_MAIL_BAYAT_DK * 60) {
        return ['Posta kutusu en son ' . max(1, intdiv($simdiTs - $enEski, 60)) . ' dk önce senkronlandı — yeni mailler henüz gelmemiş olabilir.'];
    }
    return [];
}

function siparis_mail_yanit(array $k, bool $bitti): array
{
    return ['ok' => true, 'http' => 200, 'tarama_id' => (string)$k['id'], 'tur' => (string)$k['tur'], 'i' => (int)$k['i'], 'toplam' => count($k['oge']),
            'sayac' => $k['sayac'], 'uyarilar' => $k['uyarilar'], 'bitti' => $bitti];
}

/** Son sonuç kaydını yazar, çalışan tarama kaydını siler, audit atar. */
function siparis_mail_bitir(PDO $pdo, array $k, string $durum, int $simdiTs): array
{
    $satirlar = $k['satirlar'];
    $gizli = 0;
    if (count($satirlar) > SIPARIS_MAIL_SON_SATIR) {   // önce "daha önce alınmış" satırları budanır
        $onemli = array_values(array_filter($satirlar, static fn(array $s): bool => $s['durum'] !== 'onceden'));
        $onceden = array_values(array_filter($satirlar, static fn(array $s): bool => $s['durum'] === 'onceden'));
        $kes = array_slice(array_merge($onemli, $onceden), 0, SIPARIS_MAIL_SON_SATIR);
        $gizli = count($satirlar) - count($kes);
        $satirlar = $kes;
    }
    $uyarilar = $k['uyarilar'];
    if ($gizli > 0) $uyarilar[] = $gizli . ' satır gösterilmiyor (en çok ' . SIPARIS_MAIL_SON_SATIR . ' satır saklanır).';
    if ($durum === 'yarim') $uyarilar[] = 'Sorgu tamamlanmadan bırakıldı; kalan ekler için düğmeye yeniden basın.';
    if ($durum === 'vazgecildi') $uyarilar[] = 'Sorgu yarıda bırakıldı; kalan ekler için düğmeye yeniden basın.';
    $son = ['tarama_id' => $k['id'], 'uid' => (int)$k['uid'], 'depo' => $k['depo'], 'tur' => $k['tur'], 'basla' => (int)$k['basla'], 'bitis' => $simdiTs,
            'durum' => $durum, 'sayac' => $k['sayac'], 'pdfsiz' => (int)($k['pdfsiz'] ?? 0), 'uyarilar' => $uyarilar, 'satirlar' => $satirlar, 'i' => (int)$k['i'], 'toplam' => count($k['oge'])];
    siparis_mail_kv_yaz($pdo, SIPARIS_MAIL_KV_SON . $k['tur'], $son);
    siparis_mail_kv_sil($pdo, SIPARIS_MAIL_KV_TARAMA);
    siparis_audit('siparis_mail_tara', null, null, ['tur' => $k['tur'], 'gun' => SIPARIS_MAIL_GUN, 'hesap_sayisi' => count((array)$k['hesaplar']), 'sayac' => $k['sayac'],
        'sure_sn' => max(0, $simdiTs - (int)$k['basla']), 'durum' => $durum], (int)$k['uid']);
    return $son;
}

/**
 * Taramayı başlatır: kapı (ayar/hesap) → kilit → kira → DB'den plan (IMAP yok). Plan boşsa anında biter.
 * @param array $opt simdi ('Y-m-d H:i:s')
 * @return array {ok,http?,kod?,mesaj?} | {ok:true,tarama_id,tur,i,toplam,sayac,uyarilar,bitti,son?}
 */
function siparis_mail_baslat(PDO $pdo, string $tur, int $uid, string $depo, array $opt = []): array
{
    if (!isset(siparis_mail_turleri()[$tur])) return siparis_mail_hata(400, 'tur', 'Geçersiz sorgu türü.');
    $etiket = siparis_mail_turleri()[$tur]['etiket'];
    $ayar = siparis_mail_ayar_oku($pdo);
    if (!siparis_mail_ayarli_mi($ayar, $tur)) return siparis_mail_hata(400, 'ayarsiz', $etiket . ' sorgusu için gönderen adresi ya da posta kutusu ayarlanmadı.');
    $hesapIds = siparis_mail_tarama_hesaplari($pdo, $ayar, $uid);
    if (!$hesapIds) return siparis_mail_hata(400, 'hesap', 'Ayarlı posta kutularından hiçbiri size atanmamış.');
    $simdi = (string)($opt['simdi'] ?? date('Y-m-d H:i:s'));
    $ts = strtotime($simdi) ?: time();

    return siparis_mail_kilitli(function () use ($pdo, $tur, $uid, $depo, $ayar, $hesapIds, $simdi, $ts, $etiket): array {
        $eski = siparis_mail_kv_oku($pdo, SIPARIS_MAIL_KV_TARAMA);
        if ($eski !== null) {
            $yas = $ts - (int)($eski['guncel'] ?? 0);
            if ((int)$eski['uid'] !== $uid && $yas < SIPARIS_MAIL_KIRA_SN) {
                return siparis_mail_hata(409, 'mesgul', 'Başka bir mail sorgusu şu an çalışıyor (' . siparis_mail_kullanici_adi($pdo, (int)$eski['uid']) . ', ' . max(0, $yas) . ' sn önce). Bitince yeniden deneyin.');
            }
            siparis_mail_bitir($pdo, $eski, 'yarim', $ts);   // yarım kalan eski tarama sonuç olarak saklanır
        }
        $plan = siparis_mail_plan($pdo, $tur, $hesapIds, $ayar, $simdi);
        $uyarilar = $plan['uyarilar'];
        $atanmamis = count(array_diff(array_map('intval', $ayar['hesaplar']), $hesapIds));
        if ($atanmamis > 0) array_unshift($uyarilar, 'Ayarlı ' . $atanmamis . ' posta kutusu size atanmamış — taranmadı.');
        foreach (siparis_mail_bayatlik_uyarilari($pdo, $hesapIds, $ts) as $u) array_unshift($uyarilar, $u);
        $k = ['id' => bin2hex(random_bytes(8)), 'tur' => $tur, 'uid' => $uid, 'depo' => $depo, 'hesaplar' => $hesapIds, 'basla' => $ts, 'guncel' => $ts, 'i' => 0,
              'oge' => $plan['oge'], 'satirlar' => $plan['satirlar'], 'sayac' => $plan['sayac'], 'uyarilar' => $uyarilar, 'pdfsiz' => (int)($plan['pdfsiz'] ?? 0)];
        if (!$k['oge']) {
            $son = siparis_mail_bitir($pdo, $k, 'bitti', $ts);
            return siparis_mail_yanit($k, true) + ['son' => $son];
        }
        if (!siparis_mail_kv_yaz($pdo, SIPARIS_MAIL_KV_TARAMA, $k)) return siparis_mail_hata(500, 'kv', 'Tarama durumu kaydedilemedi (hks_kv).');
        return siparis_mail_yanit($k, false);
    });
}

/**
 * Bir adım ilerletir (≤ SIPARIS_MAIL_ADIM_SN sn / ≤ SIPARIS_MAIL_ADIM_EK ek). Her adımda görünür hesaplar yeniden hesaplanır.
 * @param array $opt istemci (mail_ek_hazirla kancası), saat (callable(): float), simdi (string)
 */
function siparis_mail_adim(PDO $pdo, string $taramaId, int $uid, string $depo, array $opt = []): array
{
    return siparis_mail_kilitli(function () use ($pdo, $taramaId, $uid, $depo, $opt): array {
        $k = siparis_mail_kv_oku($pdo, SIPARIS_MAIL_KV_TARAMA);
        if ($k === null || (string)$k['id'] !== $taramaId || (int)$k['uid'] !== $uid) {
            return siparis_mail_hata(409, 'sona_erdi', 'Bu sorgu başka bir kullanıcıya ait ya da sona ermiş.');
        }
        if ((string)$k['depo'] !== $depo) return siparis_mail_hata(409, 'depo', 'Aktif depo değişti — sorguyu yeniden başlatın.');
        $saat = isset($opt['saat']) && is_callable($opt['saat']) ? $opt['saat'] : static fn(): float => microtime(true);
        $t0 = (float)$saat();
        $simdi = isset($opt['simdi']) ? (strtotime((string)$opt['simdi']) ?: time()) : time();
        $gorunur = mail_gorunur_hesap_idleri($uid, $pdo);
        $hesapIds = array_values(array_filter(array_map('intval', (array)$k['hesaplar']), static fn(int $i): bool => in_array($i, $gorunur, true)));
        $beklenen = siparis_mail_turleri()[$k['tur']]['belge'];
        $bu = 0;
        while ($k['i'] < count($k['oge'])) {
            if ($bu > 0 && ($bu >= SIPARIS_MAIL_ADIM_EK || (float)$saat() - $t0 >= SIPARIS_MAIL_ADIM_SN)) break;
            $oge = $k['oge'][$k['i']];
            $satir = $hesapIds
                ? siparis_mail_ek_isle($pdo, $oge, $beklenen, $uid, $depo, $hesapIds, $opt)
                : siparis_mail_satir($oge, 'hata', ['hata' => 'Posta kutusu artık size atanmamış.']);
            $k['satirlar'][] = $satir;
            $k['sayac'][siparis_mail_sayac_anahtari($satir['durum'])]++;
            $k['i']++; $bu++;
        }
        $k['guncel'] = $simdi;
        if ($k['i'] >= count($k['oge'])) {
            $son = siparis_mail_bitir($pdo, $k, 'bitti', $simdi);
            return siparis_mail_yanit($k, true) + ['son' => $son];
        }
        if (!siparis_mail_kv_yaz($pdo, SIPARIS_MAIL_KV_TARAMA, $k)) return siparis_mail_hata(500, 'kv', 'Tarama durumu kaydedilemedi (hks_kv).');
        return siparis_mail_yanit($k, false);
    });
}

/** Taramayı bırakır: o ana kadarki sonuç saklanır. */
function siparis_mail_vazgec(PDO $pdo, string $taramaId, int $uid): array
{
    return siparis_mail_kilitli(function () use ($pdo, $taramaId, $uid): array {
        $k = siparis_mail_kv_oku($pdo, SIPARIS_MAIL_KV_TARAMA);
        if ($k === null || (string)$k['id'] !== $taramaId || (int)$k['uid'] !== $uid) {
            return siparis_mail_hata(409, 'sona_erdi', 'Bu sorgu başka bir kullanıcıya ait ya da sona ermiş.');
        }
        $son = siparis_mail_bitir($pdo, $k, 'vazgecildi', time());
        return siparis_mail_yanit($k, true) + ['son' => $son];
    });
}

/** Son sonuç (düğme türü başına). */
function siparis_mail_son(PDO $pdo, string $tur): ?array
{
    return isset(siparis_mail_turleri()[$tur]) ? siparis_mail_kv_oku($pdo, SIPARIS_MAIL_KV_SON . $tur) : null;
}

/** Bu kullanıcının yarım kalmış taraması (başkası devralmadıysa kayıt durur; tarama kaydı tek). */
function siparis_mail_yarim(PDO $pdo, int $uid): ?array
{
    $k = siparis_mail_kv_oku($pdo, SIPARIS_MAIL_KV_TARAMA);
    if ($k === null || (int)($k['uid'] ?? 0) !== $uid || !isset(siparis_mail_turleri()[(string)($k['tur'] ?? '')])) return null;
    if (time() - (int)($k['guncel'] ?? 0) > 86400) return null;
    return ['tarama_id' => (string)$k['id'], 'tur' => (string)$k['tur'], 'i' => (int)$k['i'], 'toplam' => count((array)$k['oge']), 'depo' => (string)$k['depo']];
}
