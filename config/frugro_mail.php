<?php
// =========================================================
// config/frugro_mail.php — Frugro Siparişler ← Mail Merkezi: "Mailden al" (OGL siparis_mail.php'nin Frugro eşi; migration YOK)
//
// Frugro sipariş maili: konu "Frugro / Nar / PO 26-1623-44081 / Ingiltere", eki "… PO 26-xxxx-xxxxx FRUGRO.xlsx" (Order Log sayfası).
// Aynı sipariş farklı adrese "Geri Gönderim" diye ikinci kez gelebilir → PO no ile tekilleştirme frg_siparis_kaydet()'tedir (zaten/fark).
//
// Değişmezler (değiştirmeden önce oku):
//  • YENİ YAZMA YOLU YOK: ek bellekten/temp'ten frg_xlsx_oku() → frg_toplu_kaydet() (kaynak='mail', kaynak_mail_id). Dosya KALICI diske
//    kaydedilmez (temp dosya finally'de silinir; yalnız sha256 saklanır — frg_siparis_kaydet yapar).
//  • ŞEMA YOK: ayar + çalışan tarama + son sonuç hks_kv'de (frugro_mail_ayar / _tarama / _son); defter = audit_log 'frugro_mail_ek'
//    (module frugro_mail, record_id = mail id). Gerçek adres repoya GİRMEZ (ayar DB'de). Defter/audit'e adres/konu/dosya adı yazılmaz.
//  • OGL tarafının parçalı tarama / kilit / aday sorgusu / IMAP (EXAMINE + BODY.PEEK) altyapısı YENİDEN KULLANILIR (siparis_mail_adaylar,
//    siparis_mail_kv_*, mail_ek_hazirla…); bu dosyada IMAP istemci metodu çağrılmaz. Kilit ve tarama kaydı OGL'den AYRIDIR
//    (mail_kilit_al('frugro_mail'), 'frugro_mail_tarama') — iki sorgu birbirini bloklamaz/ezmez.
//  • Eşleşme yalnız mail_messages.from_addr TAM adres (alan adı / Reply-To YOK). Ek yalnız .xlsx/.xlsm; içerikte 'Order Log' sayfası +
//    PO deseni (\d{2}-\d{4}-\d{5}) yoksa 'gecersiz' (kayıt AÇILMAZ). Silinmiş PO geri getirilmez.
//  • Çıktı basmaz / exit etmez; kullanıcı yetkisi uç dosyada + frgm_yetkisi() ile.
// =========================================================
declare(strict_types=1);

require_once __DIR__ . '/frugro_core.php';
require_once __DIR__ . '/siparis_mail.php';   // altyapı (aday sorgusu, hks_kv yardımcıları, yetki) + mail_core/mail_view

const FRGM_GUN = 15;                    // sabit pencere (ekranda yazar)
const FRGM_ADIM_SN = 20;                // tek HTTP adımının süre bütçesi
const FRGM_ADIM_EK = 6;                 // tek adımda en çok ek
const FRGM_MAX_MAIL = 300;              // aday mail sınırı
const FRGM_MAX_EK = 100;                // tek taramada indirilecek en çok ek
const FRGM_KIRA_SN = 120;               // başka kullanıcının taraması en az bu kadar sessizse devralınabilir
const FRGM_SON_SATIR = 150;             // saklanan sonuç satırı sınırı
const FRGM_GONDEREN_MAX = 10;           // en çok gönderen adresi
const FRGM_MAX_DOSYA = 8388608;         // 8 MB (Order Log küçüktür)
const FRGM_SURUM = 'f1';                // ayrıştırma/tanıma sürümü (gecersiz kararı yalnız aynı sürümle son sayılır)
const FRGM_KV_AYAR = 'frugro_mail_ayar';
const FRGM_KV_TARAMA = 'frugro_mail_tarama';
const FRGM_KV_SON = 'frugro_mail_son';

// ── YETKİ + HAZIRLIK ───────────────────────────────────────────────────

/** Mailden alma: sipariş yazma + Mail Merkezi okuma + Roller › Frugro Siparişler › Ekle. */
function frgm_yetkisi(): bool
{
    // frugro_ui.php frgui_yetki('create') ile aynı matris kuralı (sayfa anahtarı 'frugro')
    return siparis_mail_yetkisi() && (!function_exists('role_matrix_page_can') || role_matrix_page_can('siparis', 'frugro', 'create'));
}

/** Ayar düzenleme: sipariş silme + Mail Merkezi yönetimi (OGL ile aynı) + Roller › Frugro Siparişler › Sil. */
function frgm_ayar_yetkisi(): bool
{
    return siparis_mail_ayar_yetkisi() && (!function_exists('role_matrix_page_can') || role_matrix_page_can('siparis', 'frugro', 'delete'));
}

/** Özellik kurulu mu: OGL mail şeması/anahtarı/hks_kv + Frugro tabloları. */
function frgm_hazir(PDO $pdo): bool
{
    return siparis_mail_hazir($pdo) && frg_hazir($pdo);
}

// ── AYAR ───────────────────────────────────────────────────────────────

/** @return array{hesaplar:list<int>,gonderen:list<string>} okunamayan/bozuk ayar → boş (fail-closed). */
function frgm_ayar_oku(PDO $pdo): array
{
    $j = siparis_mail_kv_oku($pdo, FRGM_KV_AYAR);
    $out = ['hesaplar' => [], 'gonderen' => []];
    if ($j === null) return $out;
    $h = [];
    foreach ((array)($j['hesaplar'] ?? []) as $v) if (is_numeric($v) && (int)$v > 0) $h[(int)$v] = true;
    $out['hesaplar'] = array_keys($h);
    $g = [];
    foreach ((array)($j['gonderen'] ?? []) as $a) {
        $a = strtolower(trim((string)$a));
        if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL) !== false) $g[$a] = true;
    }
    $out['gonderen'] = array_slice(array_keys($g), 0, FRGM_GONDEREN_MAX);
    return $out;
}

function frgm_ayarli_mi(array $ayar): bool
{
    return !empty($ayar['hesaplar']) && !empty($ayar['gonderen']);
}

/**
 * Ayarı doğrular ve yazar. $girdi: ['hesaplar'=>[id…], 'gonderen'=>metin|dizi]. Geçersiz e-posta / pasif hesap / >10 adres REDDEDİLİR.
 * Audit yalnız SAYILARI yazar (adres YAZILMAZ).
 * @return array{ok:bool,hata?:string}
 */
function frgm_ayar_kaydet(PDO $pdo, array $girdi, int $uid): array
{
    if (!frgm_ayar_yetkisi()) return ['ok' => false, 'hata' => 'Frugro mail ayarlarını değiştirme yetkiniz yok.'];
    $aktif = array_column(siparis_mail_hesaplar($pdo), 'id');
    $hesaplar = [];
    foreach ((array)($girdi['hesaplar'] ?? []) as $v) {
        if (!is_numeric($v) || !in_array((int)$v, $aktif, true)) return ['ok' => false, 'hata' => 'Geçersiz posta kutusu seçimi.'];
        $hesaplar[(int)$v] = true;
    }
    $l = [];
    foreach (siparis_mail_liste_ayir($girdi['gonderen'] ?? '') as $a) {
        $a = strtolower($a);
        if (filter_var($a, FILTER_VALIDATE_EMAIL) === false || strlen($a) > 255) return ['ok' => false, 'hata' => 'Geçersiz e-posta adresi: ' . siparis_kirp($a, 60)];
        $l[$a] = true;
    }
    if (count($l) > FRGM_GONDEREN_MAX) return ['ok' => false, 'hata' => 'En çok ' . FRGM_GONDEREN_MAX . ' gönderen adresi girilebilir.'];
    if (!siparis_mail_kv_yaz($pdo, FRGM_KV_AYAR, ['hesaplar' => array_keys($hesaplar), 'gonderen' => array_keys($l)])) {
        return ['ok' => false, 'hata' => 'Ayar kaydedilemedi (hks_kv tablosu yazılamadı).'];
    }
    if (function_exists('audit_log_event')) {
        audit_log_event('frugro_mail_ayar', 'frugro_mail', null, null, ['hesap_sayisi' => count($hesaplar), 'gonderen_sayisi' => count($l)], $uid);
    }
    return ['ok' => true];
}

// ── EK SEÇİMİ (xlsx/xlsm) ──────────────────────────────────────────────

/**
 * Mailin ek listesinden Excel eklerini seçer (SAF; DB/IMAP yok). Excel ⇔ ad .xlsx/.xlsm ile biter VEYA mime spreadsheetml/macroEnabled.
 * 'iletilen' = message/rfc822 (açılmaz). 'sorun' = 'ek_eksik': Excel/iletilen yok ama mail kesik okunmuş.
 * @return array{ekler:list<array{part:string,filename:string,size:int,mime:string}>,iletilen:list<array{part:string,filename:string}>,sorun:?string}
 */
function frgm_excel_ekleri(array $mail): array
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
        if (preg_match('/\.xls[xm]\s*$/i', $ad) || str_contains($mime, 'spreadsheetml') || str_contains($mime, 'macroenabled')) {
            $ekler[] = ['part' => $part, 'filename' => $ad, 'size' => (int)($e['size'] ?? 0), 'mime' => $mime];
        }
    }
    $sorun = null;
    $yerTutucu = str_starts_with(trim((string)($mail['subject'] ?? '')), '(işlenemedi');
    if (!$ekler && !$iletilen && ((int)($mail['body_truncated'] ?? 0) === 1 || $yerTutucu)) $sorun = 'ek_eksik';
    return ['ekler' => $ekler, 'iletilen' => $iletilen, 'sorun' => $sorun];
}

/** Dosyada 'Order Log' adlı sayfa var mı (workbook.xml; hücre okumadan, ucuz). ZipArchive yoksa true (asıl kapı PO deseni). */
function frgm_order_log_var_mi(string $yol): bool
{
    if (!class_exists('ZipArchive')) return true;
    $z = new ZipArchive();
    if ($z->open($yol) !== true) return false;
    $wb = (string)$z->getFromName('xl/workbook.xml');
    $z->close();
    return (bool)preg_match('/<sheet\b[^>]*\bname="Order Log"/i', $wb);
}

// ── DEFTER (audit_log 'frugro_mail_ek') ────────────────────────────────

/** Mail başına ek kararları: [mailId][parca] => {sonuc, surum, po[], mesaj} (aynı ek için SON kayıt geçerli). @param list<int> $mailIds */
function frgm_defter(PDO $pdo, array $mailIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $mailIds), static fn(int $i): bool => $i > 0 && $i <= 2147483647)));
    $out = [];
    foreach (array_chunk($ids, 400) as $parca) {
        $in = implode(',', array_fill(0, count($parca), '?'));
        $st = $pdo->prepare("SELECT record_id, new_values FROM audit_log WHERE module = 'frugro_mail' AND action = 'frugro_mail_ek' AND record_id IN ($in) ORDER BY id ASC");
        $st->execute($parca);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $j = json_decode((string)$r['new_values'], true);
            if (!is_array($j) || !isset($j['parca'])) continue;
            $out[(int)$r['record_id']][(string)$j['parca']] = [
                'sonuc' => (string)($j['sonuc'] ?? ''), 'surum' => isset($j['surum']) ? (string)$j['surum'] : null,
                'po' => is_array($j['po'] ?? null) ? $j['po'] : [], 'mesaj' => isset($j['mesaj']) ? (string)$j['mesaj'] : null,
            ];
        }
    }
    return $out;
}

/** Kararı deftere yazar (adres/konu/dosya adı YAZILMAZ; PO no iş verisidir ve yazılır). */
function frgm_defter_yaz(int $mailId, string $parca, string $sonuc, int $hesapId, int $uid, array $ek = []): void
{
    if ($mailId <= 0 || $mailId > 2147483647 || !function_exists('audit_log_event')) return;
    audit_log_event('frugro_mail_ek', 'frugro_mail', $mailId, null, ['parca' => $parca, 'sonuc' => $sonuc, 'surum' => FRGM_SURUM, 'hesap_id' => $hesapId] + $ek, $uid);
}

/** Defter kaydı SON karar mı? alindi/zaten/fark/silinmis/ek_yok → evet · gecersiz → yalnız aynı sürümle · hata/diğer → hayır (yeniden denenir). */
function frgm_defter_son_mu(array $d): bool
{
    $s = (string)($d['sonuc'] ?? '');
    if (in_array($s, ['alindi', 'zaten', 'fark', 'silinmis', 'ek_yok'], true)) return true;
    return $s === 'gecersiz' && (string)($d['surum'] ?? '') === FRGM_SURUM;
}

// ── PLAN ───────────────────────────────────────────────────────────────

function frgm_sayac_bos(): array
{
    return ['mail' => 0, 'ek' => 0, 'alindi' => 0, 'zaten' => 0, 'fark' => 0, 'onceden' => 0, 'atlandi' => 0, 'hata' => 0];
}

function frgm_sayac_anahtari(string $durum): string
{
    return match ($durum) {
        'alindi' => 'alindi', 'zaten' => 'zaten', 'fark' => 'fark', 'onceden' => 'onceden', 'hata' => 'hata',
        default => 'atlandi',
    };
}

function frgm_satir(array $oge, string $durum, array $ek = []): array
{
    return $ek + ['m' => (int)$oge['m'], 'p' => (string)$oge['p'], 'dosya' => (string)($oge['dosya'] ?? ''), 'durum' => $durum, 'po' => [], 'mesajlar' => [], 'hata' => null, 'kopya' => (int)($oge['kopya'] ?? 1)];
}

/**
 * Planı DB'den kurar (IMAP YOK, anında): oge[] = indirilecek ekler, satirlar = ön-çözülmüş (onceden / iletilmis / ek_eksik / cok_buyuk).
 * @param list<int> $hesapIds
 */
function frgm_plan(PDO $pdo, array $hesapIds, array $ayar, string $simdi): array
{
    $sayac = frgm_sayac_bos();
    $uyarilar = [];
    $plan = ['oge' => [], 'satirlar' => [], 'sayac' => $sayac, 'uyarilar' => $uyarilar, 'eksiz' => 0];
    $t = strtotime($simdi);
    $bas = date('Y-m-d 00:00:00', strtotime('-' . FRGM_GUN . ' days', $t !== false ? $t : time()));
    $satirlar = siparis_mail_adaylar($pdo, $hesapIds, (array)$ayar['gonderen'], $bas, FRGM_MAX_MAIL);
    if (count($satirlar) >= FRGM_MAX_MAIL) $uyarilar[] = 'Son ' . FRGM_GUN . ' günde ' . FRGM_MAX_MAIL . ' mailden fazlası var; en eskileri işlendi — kalanlar için düğmeye yeniden basın.';

    $sira = array_flip(array_map('intval', $hesapIds));
    usort($satirlar, static fn(array $a, array $b): int => [(string)$a['received_at'], (int)($sira[(int)$a['account_id']] ?? 99), (int)$a['id']] <=> [(string)$b['received_at'], (int)($sira[(int)$b['account_id']] ?? 99), (int)$b['id']]);
    $gruplar = [];
    foreach ($satirlar as $s) $gruplar[(string)$s['message_id_hash'] . '|' . (string)($s['message_id_hash'] === '' ? $s['id'] : '')][] = $s;
    $defter = frgm_defter($pdo, array_map(static fn(array $s): int => (int)$s['id'], $satirlar));

    foreach ($gruplar as $kopyalar) {
        usort($kopyalar, static fn(array $a, array $b): int => ((int)($sira[(int)$a['account_id']] ?? 99) <=> (int)($sira[(int)$b['account_id']] ?? 99)) ?: ((int)$a['id'] <=> (int)$b['id']));
        $asil = $kopyalar[0];
        $plan['sayac']['mail']++;
        $sec = frgm_excel_ekleri($asil);
        if ($sec['sorun'] === 'ek_eksik') { $plan['satirlar'][] = frgm_satir(['m' => $asil['id'], 'p' => '', 'dosya' => '(ek listesi eksik)'], 'ek_eksik'); continue; }
        if (!$sec['ekler'] && !$sec['iletilen']) { $plan['eksiz']++; continue; }
        foreach ($sec['iletilen'] as $il) $plan['satirlar'][] = frgm_satir(['m' => $asil['id'], 'p' => $il['part'], 'dosya' => $il['filename'], 'kopya' => count($kopyalar)], 'iletilmis');
        foreach ($sec['ekler'] as $ek) {
            $plan['sayac']['ek']++;
            $ad = $ek['filename'] !== '' ? $ek['filename'] : ('ek-' . $ek['part'] . '.xlsx');
            $oge = ['m' => (int)$asil['id'], 'h' => (int)$asil['account_id'], 'p' => $ek['part'], 'dosya' => siparis_kirp(basename(str_replace('\\', '/', $ad)), 120),
                    'size' => $ek['size'], 'alt' => [], 'kopya' => count($kopyalar)];
            foreach (array_slice($kopyalar, 1) as $k) {
                foreach (frgm_excel_ekleri($k)['ekler'] as $ke) {
                    if ($ke['part'] === $ek['part']) { $oge['alt'][] = ['m' => (int)$k['id'], 'h' => (int)$k['account_id'], 'p' => $ke['part']]; break; }
                }
            }
            if ($ek['size'] > FRGM_MAX_DOSYA) { $plan['satirlar'][] = frgm_satir($oge, 'cok_buyuk'); continue; }
            $karar = null;
            foreach ($kopyalar as $k) {
                $d = $defter[(int)$k['id']][$ek['part']] ?? null;
                if ($d !== null && frgm_defter_son_mu($d)) { $karar = $d; break; }
            }
            if ($karar !== null) {
                $durum = in_array($karar['sonuc'], ['alindi', 'zaten', 'fark'], true) ? 'onceden' : $karar['sonuc'];
                $plan['satirlar'][] = frgm_satir($oge, $durum, ['po' => $karar['po'], 'hata' => $karar['mesaj']]);
                continue;
            }
            $plan['oge'][] = $oge;
        }
    }
    if (count($plan['oge']) > FRGM_MAX_EK) {
        $uyarilar[] = 'İlk ' . FRGM_MAX_EK . ' ek işlendi; kalanlar için düğmeye yeniden basın.';
        $plan['oge'] = array_slice($plan['oge'], 0, FRGM_MAX_EK);
    }
    foreach ($plan['satirlar'] as $s) $plan['sayac'][frgm_sayac_anahtari($s['durum'])]++;
    $plan['uyarilar'] = $uyarilar;
    return $plan;
}

// ── TEK EKİN İŞLENİŞİ ──────────────────────────────────────────────────

/**
 * Tek eki işler: boyut → mail_ek_hazirla (+yedek kopya) → temp → xlsx/Order Log/PO kapısı → frg_toplu_kaydet → defter. Temp finally'de silinir.
 * @param array $opt istemci: callable (mail_ek_hazirla test kancası)
 */
function frgm_ek_isle(PDO $pdo, array $oge, int $uid, string $depo, array $hesapIds, array $opt = []): array
{
    $m = (int)$oge['m']; $h = (int)($oge['h'] ?? 0); $p = (string)$oge['p'];
    if ((int)($oge['size'] ?? 0) > FRGM_MAX_DOSYA) return frgm_satir($oge, 'cok_buyuk');

    $hazirOpt = isset($opt['istemci']) ? ['istemci' => $opt['istemci']] : [];
    $denemeler = array_merge([['m' => $m, 'h' => $h, 'p' => $p]], (array)($oge['alt'] ?? []));
    $r = ['ok' => false, 'kod' => 404, 'mesaj' => 'Ek bulunamadı.'];
    foreach ($denemeler as $d) {
        $r = mail_ek_hazirla($pdo, (int)$d['m'], (string)$d['p'], $hesapIds, $hazirOpt);
        if ($r['ok']) { $m = (int)$d['m']; $h = (int)$d['h']; break; }
        if (($r['kod'] ?? 0) === 413) break;
    }
    if (!$r['ok']) {
        if (($r['kod'] ?? 0) === 413) return frgm_satir($oge, 'cok_buyuk');
        if ((int)($r['kod'] ?? 0) === 404) {   // KALICI: deftere yazılır; 409/502/503 geçicidir (defter YOK, yeniden denenir)
            frgm_defter_yaz($m, $p, 'ek_yok', $h, $uid, ['mesaj' => siparis_kirp((string)$r['mesaj'], 120)]);
            return frgm_satir($oge, 'ek_yok', ['hata' => (string)$r['mesaj']]);
        }
        return frgm_satir($oge, 'hata', ['hata' => (string)$r['mesaj']]);
    }

    $ad = siparis_kirp(basename(str_replace('\\', '/', (string)($r['ad'] ?? $oge['dosya'] ?? 'ek.xlsx'))), 120);
    $tmp = (string)tempnam(sys_get_temp_dir(), 'frgm');
    try {
        if ($tmp === '' || file_put_contents($tmp, (string)$r['veri']) === false) return frgm_satir($oge, 'hata', ['hata' => 'Geçici dosya yazılamadı.']);
        unset($r['veri']);
        $kaydet = static function (string $sonuc, array $ek = [], array $defterEk = []) use ($oge, $m, $p, $h, $uid, $ad): array {
            $satir = frgm_satir($oge, $sonuc, ['dosya' => $ad] + $ek);
            frgm_defter_yaz($m, $p, $sonuc, $h, $uid, $defterEk + ['po' => array_map(static fn(array $x): array => ['po' => $x['po'], 'durum' => $x['durum']], $satir['po'])]);
            return $satir;
        };
        // Kapı 1: Order Log sayfası (ucuz). Kapı 2: PO'lu satır (frg_xlsx_oku). Biri tutmazsa kayıt AÇILMAZ.
        if (!frgm_order_log_var_mi($tmp)) return $kaydet('gecersiz', ['hata' => "'Order Log' sayfası yok."], ['mesaj' => "Order Log sayfası yok"]);
        $okuma = frg_xlsx_oku($tmp);
        if (!$okuma['ok']) return $kaydet('gecersiz', ['hata' => (string)$okuma['hata']], ['mesaj' => siparis_kirp((string)$okuma['hata'], 120)]);
        if (!$okuma['siparisler']) return $kaydet('gecersiz', ['hata' => 'Dosyada geçerli PO satırı yok.'], ['mesaj' => 'PO satırı yok']);

        $toplu = frg_toplu_kaydet($pdo, $okuma, ['user_id' => $uid, 'depo' => $depo, 'kaynak' => 'mail', 'kaynak_mail_id' => $m]);
        $po = [];
        $durumlar = [];
        foreach ($toplu['sonuclar'] as $x) {
            $po[] = ['po' => (string)$x['po_no'], 'durum' => (string)$x['durum'], 'id' => $x['id']];
            $durumlar[(string)$x['durum']] = true;
        }
        $uyari = array_slice(array_map('strval', (array)$okuma['uyarilar']), 0, 3);
        $ek = ['po' => $po, 'mesajlar' => $uyari];
        if (isset($durumlar['hata'])) return frgm_satir($oge, 'hata', ['dosya' => $ad, 'hata' => 'PO kaydedilemedi: ' . siparis_kirp((string)(array_values(array_filter($toplu['sonuclar'], static fn($x) => $x['durum'] === 'hata'))[0]['mesaj'] ?? ''), 100)] + $ek);   // defter YOK
        if (isset($durumlar['olustu']) || isset($durumlar['guncellendi'])) return $kaydet('alindi', $ek);
        if (isset($durumlar['fark'])) return $kaydet('fark', $ek);
        if (isset($durumlar['zaten'])) return $kaydet('zaten', $ek);
        return $kaydet('silinmis', $ek);   // yalnız silinmiş PO'lar
    } catch (Throwable $e) {
        error_log('[frugro_mail] ek işlenemedi: ' . get_class($e) . ': ' . mail_redact($e->getMessage()));
        return frgm_satir($oge, 'hata', ['hata' => 'Ek işlenemedi. Teknik ayrıntılar sunucu günlüğüne kaydedildi.']);
    } finally {
        if ($tmp !== '') @unlink($tmp);
    }
}

// ── TARAMA (parçalı yürütme) ───────────────────────────────────────────

/** Gövdeyi 'frugro_mail' dosya kilidi içinde çalıştırır (OGL kilidinden AYRI). Kilit alınamazsa 409. */
function frgm_kilitli(callable $fn): array
{
    try {
        $fh = mail_kilit_al('frugro_mail');
    } catch (MailKilitHatasi $e) {
        return siparis_mail_hata(503, 'kilit', 'Kilit dosyası açılamadı (storage/mail yazılabilir mi?).');
    }
    if ($fh === null) return siparis_mail_hata(409, 'mesgul', 'Başka bir Frugro mail sorgusu şu an çalışıyor. Birkaç saniye sonra yeniden deneyin.');
    try {
        return $fn();
    } finally {
        mail_kilit_birak($fh);
    }
}

function frgm_yanit(array $k, bool $bitti): array
{
    return ['ok' => true, 'http' => 200, 'tarama_id' => (string)$k['id'], 'tur' => 'frugro', 'i' => (int)$k['i'], 'toplam' => count($k['oge']),
            'sayac' => $k['sayac'], 'uyarilar' => $k['uyarilar'], 'bitti' => $bitti];
}

/** Son sonuç kaydını yazar, çalışan tarama kaydını siler, audit atar (yalnız sayılar). */
function frgm_bitir(PDO $pdo, array $k, string $durum, int $simdiTs): array
{
    $satirlar = $k['satirlar'];
    $gizli = 0;
    if (count($satirlar) > FRGM_SON_SATIR) {
        $onemli = array_values(array_filter($satirlar, static fn(array $s): bool => $s['durum'] !== 'onceden'));
        $onceden = array_values(array_filter($satirlar, static fn(array $s): bool => $s['durum'] === 'onceden'));
        $kes = array_slice(array_merge($onemli, $onceden), 0, FRGM_SON_SATIR);
        $gizli = count($satirlar) - count($kes);
        $satirlar = $kes;
    }
    $uyarilar = $k['uyarilar'];
    if ($gizli > 0) $uyarilar[] = $gizli . ' satır gösterilmiyor (en çok ' . FRGM_SON_SATIR . ' satır saklanır).';
    if ($durum === 'yarim' || $durum === 'vazgecildi') $uyarilar[] = 'Sorgu tamamlanmadan bırakıldı; kalan ekler için düğmeye yeniden basın.';
    $son = ['tarama_id' => $k['id'], 'uid' => (int)$k['uid'], 'depo' => $k['depo'], 'tur' => 'frugro', 'basla' => (int)$k['basla'], 'bitis' => $simdiTs,
            'durum' => $durum, 'sayac' => $k['sayac'], 'eksiz' => (int)($k['eksiz'] ?? 0), 'uyarilar' => $uyarilar, 'satirlar' => $satirlar, 'i' => (int)$k['i'], 'toplam' => count($k['oge'])];
    siparis_mail_kv_yaz($pdo, FRGM_KV_SON, $son);
    siparis_mail_kv_sil($pdo, FRGM_KV_TARAMA);
    if (function_exists('audit_log_event')) {
        audit_log_event('frugro_mail_tara', 'frugro_mail', null, null, ['gun' => FRGM_GUN, 'hesap_sayisi' => count((array)$k['hesaplar']), 'sayac' => $k['sayac'],
            'sure_sn' => max(0, $simdiTs - (int)$k['basla']), 'durum' => $durum], (int)$k['uid']);
    }
    return $son;
}

/**
 * Taramayı başlatır: ayar/hesap kapısı → kilit → kira → DB'den plan (IMAP yok). Plan boşsa anında biter.
 * @param array $opt simdi ('Y-m-d H:i:s')
 */
function frgm_baslat(PDO $pdo, int $uid, string $depo, array $opt = []): array
{
    if (!frgm_yetkisi()) return siparis_mail_hata(403, 'yetki', 'Frugro mail sorgusu için sipariş yazma ve Mail Merkezi okuma yetkisi gerekir.');
    if (!frgm_hazir($pdo)) return siparis_mail_hata(409, 'kurulmadi', 'Frugro mail sorgusu kurulu değil (Frugro / Mail Merkezi şeması, MAIL_MASTER_KEY ya da hks_kv eksik).');
    $ayar = frgm_ayar_oku($pdo);
    if (!frgm_ayarli_mi($ayar)) return siparis_mail_hata(400, 'ayarsiz', 'Frugro sorgusu için gönderen adresi ya da posta kutusu ayarlanmadı.');
    $hesapIds = siparis_mail_tarama_hesaplari($pdo, $ayar, $uid);
    if (!$hesapIds) return siparis_mail_hata(400, 'hesap', 'Ayarlı posta kutularından hiçbiri size atanmamış.');
    $simdi = (string)($opt['simdi'] ?? date('Y-m-d H:i:s'));
    $ts = strtotime($simdi) ?: time();

    return frgm_kilitli(function () use ($pdo, $uid, $depo, $ayar, $hesapIds, $simdi, $ts): array {
        $eski = siparis_mail_kv_oku($pdo, FRGM_KV_TARAMA);
        if ($eski !== null) {
            $yas = $ts - (int)($eski['guncel'] ?? 0);
            if ((int)$eski['uid'] !== $uid && $yas < FRGM_KIRA_SN) {
                return siparis_mail_hata(409, 'mesgul', 'Başka bir Frugro mail sorgusu şu an çalışıyor (' . siparis_mail_kullanici_adi($pdo, (int)$eski['uid']) . ', ' . max(0, $yas) . ' sn önce). Bitince yeniden deneyin.');
            }
            frgm_bitir($pdo, $eski, 'yarim', $ts);
        }
        $plan = frgm_plan($pdo, $hesapIds, $ayar, $simdi);
        $uyarilar = $plan['uyarilar'];
        $atanmamis = count(array_diff(array_map('intval', $ayar['hesaplar']), $hesapIds));
        if ($atanmamis > 0) array_unshift($uyarilar, 'Ayarlı ' . $atanmamis . ' posta kutusu size atanmamış — taranmadı.');
        foreach (siparis_mail_bayatlik_uyarilari($pdo, $hesapIds, $ts) as $u) array_unshift($uyarilar, $u);
        $k = ['id' => bin2hex(random_bytes(8)), 'uid' => $uid, 'depo' => $depo, 'hesaplar' => $hesapIds, 'basla' => $ts, 'guncel' => $ts, 'i' => 0,
              'oge' => $plan['oge'], 'satirlar' => $plan['satirlar'], 'sayac' => $plan['sayac'], 'uyarilar' => $uyarilar, 'eksiz' => (int)$plan['eksiz']];
        if (!$k['oge']) {
            $son = frgm_bitir($pdo, $k, 'bitti', $ts);
            return frgm_yanit($k, true) + ['son' => $son];
        }
        if (!siparis_mail_kv_yaz($pdo, FRGM_KV_TARAMA, $k)) return siparis_mail_hata(500, 'kv', 'Tarama durumu kaydedilemedi (hks_kv).');
        return frgm_yanit($k, false);
    });
}

/** Bir adım ilerletir (≤ FRGM_ADIM_SN sn / ≤ FRGM_ADIM_EK ek). @param array $opt istemci, saat (callable(): float), simdi */
function frgm_adim(PDO $pdo, string $taramaId, int $uid, string $depo, array $opt = []): array
{
    if (!frgm_yetkisi()) return siparis_mail_hata(403, 'yetki', 'Frugro mail sorgusu için yetkiniz yok.');
    return frgm_kilitli(function () use ($pdo, $taramaId, $uid, $depo, $opt): array {
        $k = siparis_mail_kv_oku($pdo, FRGM_KV_TARAMA);
        if ($k === null || (string)$k['id'] !== $taramaId || (int)$k['uid'] !== $uid) return siparis_mail_hata(409, 'sona_erdi', 'Bu sorgu başka bir kullanıcıya ait ya da sona ermiş.');
        if ((string)$k['depo'] !== $depo) return siparis_mail_hata(409, 'depo', 'Aktif depo değişti — sorguyu yeniden başlatın.');
        $saat = isset($opt['saat']) && is_callable($opt['saat']) ? $opt['saat'] : static fn(): float => microtime(true);
        $t0 = (float)$saat();
        $simdi = isset($opt['simdi']) ? (strtotime((string)$opt['simdi']) ?: time()) : time();
        $gorunur = mail_gorunur_hesap_idleri($uid, $pdo);
        $hesapIds = array_values(array_filter(array_map('intval', (array)$k['hesaplar']), static fn(int $i): bool => in_array($i, $gorunur, true)));
        $bu = 0;
        while ($k['i'] < count($k['oge'])) {
            if ($bu > 0 && ($bu >= FRGM_ADIM_EK || (float)$saat() - $t0 >= FRGM_ADIM_SN)) break;
            $oge = $k['oge'][$k['i']];
            $satir = $hesapIds ? frgm_ek_isle($pdo, $oge, $uid, $depo, $hesapIds, $opt) : frgm_satir($oge, 'hata', ['hata' => 'Posta kutusu artık size atanmamış.']);
            $k['satirlar'][] = $satir;
            $k['sayac'][frgm_sayac_anahtari($satir['durum'])]++;
            $k['i']++; $bu++;
        }
        $k['guncel'] = $simdi;
        if ($k['i'] >= count($k['oge'])) {
            $son = frgm_bitir($pdo, $k, 'bitti', $simdi);
            return frgm_yanit($k, true) + ['son' => $son];
        }
        if (!siparis_mail_kv_yaz($pdo, FRGM_KV_TARAMA, $k)) return siparis_mail_hata(500, 'kv', 'Tarama durumu kaydedilemedi (hks_kv).');
        return frgm_yanit($k, false);
    });
}

/** Taramayı bırakır: o ana kadarki sonuç saklanır. */
function frgm_vazgec(PDO $pdo, string $taramaId, int $uid): array
{
    if (!frgm_yetkisi()) return siparis_mail_hata(403, 'yetki', 'Frugro mail sorgusu için yetkiniz yok.');
    return frgm_kilitli(function () use ($pdo, $taramaId, $uid): array {
        $k = siparis_mail_kv_oku($pdo, FRGM_KV_TARAMA);
        if ($k === null || (string)$k['id'] !== $taramaId || (int)$k['uid'] !== $uid) return siparis_mail_hata(409, 'sona_erdi', 'Bu sorgu başka bir kullanıcıya ait ya da sona ermiş.');
        $son = frgm_bitir($pdo, $k, 'vazgecildi', time());
        return frgm_yanit($k, true) + ['son' => $son];
    });
}

function frgm_son(PDO $pdo): ?array
{
    return siparis_mail_kv_oku($pdo, FRGM_KV_SON);
}

/** Bu kullanıcının yarım kalmış taraması. */
function frgm_yarim(PDO $pdo, int $uid): ?array
{
    $k = siparis_mail_kv_oku($pdo, FRGM_KV_TARAMA);
    if ($k === null || (int)($k['uid'] ?? 0) !== $uid) return null;
    if (time() - (int)($k['guncel'] ?? 0) > 86400) return null;
    return ['tarama_id' => (string)$k['id'], 'i' => (int)$k['i'], 'toplam' => count((array)$k['oge']), 'depo' => (string)$k['depo']];
}

// ── SONUÇ HTML (sunucuda tek yerde çizilir; kullanıcı verisi h() ile kaçırılır) ──

/** @param list<int> $hesapIds izleyenin görünür hesapları (gönderen/konu yalnız bunlardan gösterilir) */
function frgm_sonuc_html(PDO $pdo, array $son, array $hesapIds): string
{
    $satirlar = array_values(array_filter((array)($son['satirlar'] ?? []), 'is_array'));
    $mailIds = array_values(array_unique(array_map(static fn(array $s): int => (int)$s['m'], $satirlar)));
    $mailler = [];
    $hesapIds = array_values(array_map('intval', $hesapIds));
    if ($mailIds && $hesapIds) {
        foreach (array_chunk($mailIds, 300) as $parca) {
            $st = $pdo->prepare('SELECT id, account_id, from_name, from_addr, subject, received_at FROM mail_messages WHERE id IN (' . implode(',', array_fill(0, count($parca), '?')) . ') AND account_id IN (' . implode(',', array_fill(0, count($hesapIds), '?')) . ')');
            $st->execute(array_merge($parca, $hesapIds));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $mm) $mailler[(int)$mm['id']] = mail_gorunum_charset_duzelt($mm, ['subject', 'from_name']);
        }
    }
    $sy = (array)($son['sayac'] ?? []);
    $bitis = (int)($son['bitis'] ?? 0);
    $baslik = 'Frugro mail sorgusu · son ' . FRGM_GUN . ' gün' . ($bitis > 0 ? ' · ' . date('d.m.Y H:i', $bitis) : '') . ' · ' . siparis_mail_kullanici_adi($pdo, (int)($son['uid'] ?? 0));
    $chip = static fn(string $et, int $say, string $sinif = ''): string => '<span class="frgm-chip' . ($sinif !== '' ? ' frgm-chip--' . $sinif : '') . '"><span>' . h($et) . '</span> <b>' . $say . '</b></span>';
    $o = '<section class="card frgm-sonuc-kart" aria-label="Frugro mail sorgusu sonucu"><div class="card-head"><h2>' . h($baslik) . '</h2></div>';
    $o .= '<div class="frgm-ozet">' . $chip('Bulunan mail', (int)($sy['mail'] ?? 0)) . $chip('Excel ek', (int)($sy['ek'] ?? 0)) . $chip('Alındı', (int)($sy['alindi'] ?? 0), 'ok')
        . $chip('Zaten vardı', (int)($sy['zaten'] ?? 0)) . $chip('Fark var', (int)($sy['fark'] ?? 0), ((int)($sy['fark'] ?? 0)) > 0 ? 'uyari' : '')
        . $chip('Daha önce alınmış', (int)($sy['onceden'] ?? 0)) . $chip('Atlandı', (int)($sy['atlandi'] ?? 0), ((int)($sy['atlandi'] ?? 0)) > 0 ? 'uyari' : '')
        . $chip('Hata', (int)($sy['hata'] ?? 0), ((int)($sy['hata'] ?? 0)) > 0 ? 'hata' : '') . '</div>';
    foreach ((array)($son['uyarilar'] ?? []) as $u) $o .= '<p class="frgm-uyari" role="note">' . h((string)$u) . '</p>';
    if ((int)($son['eksiz'] ?? 0) > 0) $o .= '<p class="frgm-soluk">' . (int)$son['eksiz'] . ' mailde Excel eki yoktu (atlandı).</p>';
    $etiket = ['alindi' => 'Alındı', 'zaten' => 'Zaten vardı', 'fark' => 'Fark var (alanlar korundu)', 'onceden' => 'Daha önce alınmış', 'gecersiz' => 'Order Log değil',
               'silinmis' => 'Silinmiş PO', 'ek_yok' => 'Ek sunucuda yok', 'iletilmis' => 'İletilmiş mail (açılmadı)', 'ek_eksik' => 'Ek listesi eksik', 'cok_buyuk' => 'Çok büyük', 'hata' => 'Hata'];
    if ($satirlar) {
        $o .= '<div class="table-wrap"><table class="frgm-tablo"><thead><tr><th>Mail</th><th>Ek</th><th>PO</th><th>Durum</th></tr></thead><tbody>';
        foreach ($satirlar as $s) {
            $mm = $mailler[(int)$s['m']] ?? null;
            $mt = $mm ? h(trim((string)$mm['subject'])) . '<br><span class="frgm-soluk">' . h((string)$mm['from_addr']) . ' · ' . h((string)$mm['received_at']) . '</span>' : '<span class="frgm-soluk">#' . (int)$s['m'] . '</span>';
            $pol = [];
            foreach ((array)($s['po'] ?? []) as $x) if (is_array($x)) $pol[] = h((string)($x['po'] ?? '')) . (($x['durum'] ?? '') !== '' ? ' <span class="frgm-soluk">(' . h((string)$x['durum']) . ')</span>' : '');
            $d = (string)($s['durum'] ?? '');
            $not = '';
            if (!empty($s['hata'])) $not .= '<br><span class="frgm-soluk">' . h((string)$s['hata']) . '</span>';
            foreach ((array)($s['mesajlar'] ?? []) as $ms) $not .= '<br><span class="frgm-soluk">' . h((string)$ms) . '</span>';
            $o .= '<tr class="frgm-satir frgm-satir--' . h($d) . '"><td data-label="Mail">' . $mt . '</td><td data-label="Ek">' . h((string)($s['dosya'] ?? '')) . '</td><td data-label="PO">' . ($pol ? implode('<br>', $pol) : '—')
                . '</td><td data-label="Durum">' . h($etiket[$d] ?? $d) . $not . '</td></tr>';
        }
        $o .= '</tbody></table></div>';
    } else {
        $o .= '<p class="frgm-soluk">Taranan maillerde alınacak Excel eki bulunamadı.</p>';
    }
    return $o . '</section>';
}

// ── PARTIAL İÇİN VERİ (sorgu burada; _frugro_mail_kart.php yalnız çizer) ──

/**
 * _frugro_mail_kart.php partial'ının beklediği $frgMailVeri.
 * @return array{goster:bool,hazir:bool,ayarli:bool,ayar_yetkisi:bool,csrf:string,url:string,ayar_url:string,son_html:string,yarim:?array,uyari:string}
 */
function frgm_kart_veri(PDO $pdo, int $uid): array
{
    $v = ['goster' => false, 'hazir' => false, 'ayarli' => false, 'ayar_yetkisi' => false, 'csrf' => '', 'url' => 'frugro_mail.php', 'ayar_url' => 'frugro_ayarlar.php',
          'son_html' => '', 'yarim' => null, 'uyari' => ''];
    if (!frgm_yetkisi()) return $v;
    $v['goster'] = true;
    $v['ayar_yetkisi'] = frgm_ayar_yetkisi();
    $v['csrf'] = function_exists('csrf_token') ? (string)csrf_token() : '';
    $v['hazir'] = frgm_hazir($pdo);
    if (!$v['hazir']) { $v['uyari'] = 'Mail sorgusu kurulu değil.'; return $v; }
    $ayar = frgm_ayar_oku($pdo);
    $v['ayarli'] = frgm_ayarli_mi($ayar);
    if (!$v['ayarli']) { $v['uyari'] = 'Gönderen adresi ya da posta kutusu ayarlanmadı.'; return $v; }
    $v['yarim'] = frgm_yarim($pdo, $uid);
    $son = frgm_son($pdo);
    if ($son !== null && (int)($son['uid'] ?? 0) === $uid) $v['son_html'] = frgm_sonuc_html($pdo, $son, mail_gorunur_hesap_idleri($uid, $pdo));
    return $v;
}
