<?php
// =========================================================
// config/mail_translate.php — Çeviri katmanı (M4)
//
// İş mantığı sağlayıcıyı BİLMEZ: MailTranslationProviderInterface arkasında DeepL / LibreTranslate / MyMemory
// (ve ileride Google vb.) takılır. Varsayılan sağlayıcı "none" — ÇEVİRİ MAİL İÇERİĞİNİ ÜÇÜNCÜ TARAFA GÖNDERİR;
// sahibin kararıyla config/local.php'den açılır:
//     define('MAIL_TRANSLATE_PROVIDER', 'deepl');        // none | deepl | libretranslate | mymemory
//     define('MAIL_TRANSLATE_KEY', '…');                 // deepl / libretranslate için (git'e GİRMEZ)
//     define('MAIL_TRANSLATE_URL', 'https://…');         // yalnız libretranslate (https zorunlu)
//     define('MAIL_TRANSLATE_EMAIL', '…');               // mymemory için isteğe bağlı (kota artırır)
//
// VERİ ÇIKIŞI MİNİMİZASYONU: sağlayıcıya yalnız gövde METNİ (alıntılar ve "… yazdı:" zincirleri kırpılmış, en çok
// 12 000 karakter) ve konu gider. Gönderen/alıcı adresleri, başlıklar, ekler, hesap bilgisi ASLA gitmez.
//
// DAYANIKLILIK: çeviri hatası maili ASLA etkilemez (yalnız tr_* alanları). Durum: pending → translated | failed | skipped.
// Geçici hata → artan bekleme ile yeniden deneme (5 deneme); kalıcı hata → failed; yapılandırma/anahtar hatası →
// kuyruk DURUR, mailler pending kalır; kota → saatlerce duraklatılır, deneme hakkı yenmez.
// =========================================================
declare(strict_types=1);

const MAIL_CEVIRI_MAX_KARAKTER = 12000;
const MAIL_CEVIRI_MAX_DENEME   = 5;
const MAIL_CEVIRI_GERI_TARIH   = 7 * 86400;   // 7 günden eski pending mailler çevrilmez (kota/veri çıkışı korunur)

final class MailTranslateException extends RuntimeException
{
    /** temp (yeniden dene) | perm (bu mesaj çevrilemez) | quota (duraklat) | config (anahtar/ayar — kuyruğu durdur) */
    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }
}

interface MailTranslationProviderInterface
{
    public function ad(): string;
    /** Tek istekte güvenle gönderilecek en çok BAYT. */
    public function parcaLimiti(): int;
    /**
     * @param string|null $kaynak ISO 639-1 (null = sağlayıcı otomatik tespit etsin)
     * @return array{metin:string,tespit:?string}
     * @throws MailTranslateException
     */
    public function cevir(string $metin, ?string $kaynak, string $hedef): array;
}

// ── HTTP ────────────────────────────────────────────────────────────────

/**
 * Giden HTTPS isteği (cURL). Yalnız https; yönlendirme TAKİP EDİLMEZ; TLS doğrulamalı; zaman aşımı + 1 MB yanıt sınırı.
 * @param list<string> $baslik "Ad: değer" satırları
 * @return array{kod:int,govde:string}
 * @throws MailTranslateException ağ hatasında (temp)
 */
function mail_http_istek(string $metod, string $url, array $baslik, ?string $govde, float $timeout = 20.0): array
{
    if (!preg_match('#^https://[^/\s]+#i', $url)) throw new MailTranslateException('config', 'Çeviri adresi https:// olmalı.');
    if (!function_exists('curl_init')) throw new MailTranslateException('config', 'PHP cURL eklentisi yok.');
    $ch = curl_init($url);
    $cikti = ''; $asildi = false;
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $metod, CURLOPT_HTTPHEADER => $baslik, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => (int)$timeout, CURLOPT_USERAGENT => 'AsyaFresh-MailCenter/1',
        CURLOPT_WRITEFUNCTION => static function ($c, $d) use (&$cikti, &$asildi) {
            if (strlen($cikti) + strlen($d) > 1048576) { $asildi = true; return 0; }
            $cikti .= $d; return strlen($d);
        },
    ]);
    if ($govde !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $govde);
    $ok = curl_exec($ch);
    $kod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hata = curl_error($ch);
    curl_close($ch);
    if ($asildi) throw new MailTranslateException('temp', 'Sağlayıcı yanıtı çok büyük.');
    if ($ok === false && $kod === 0) throw new MailTranslateException('temp', 'Çeviri servisine ulaşılamadı: ' . mb_substr(mail_redact($hata), 0, 120));
    return ['kod' => $kod, 'govde' => $cikti];
}

/** HTTP durum kodunu hata türüne çevirir. */
function mail_ceviri_http_hata(int $kod, string $govde = ''): MailTranslateException
{
    $ozet = mb_substr(mail_redact(trim((string)preg_replace('/\s+/', ' ', strip_tags($govde)))), 0, 100);
    return match (true) {
        $kod === 401 || $kod === 403 => new MailTranslateException('config', "Çeviri servisi kimlik doğrulamasını reddetti (HTTP $kod) — anahtarı kontrol edin."),
        $kod === 456 || $kod === 402 => new MailTranslateException('quota', 'Çeviri kotası doldu.'),
        $kod === 429 => new MailTranslateException('temp', 'Çeviri servisi hız sınırı (429); sonra tekrar denenecek.'),
        $kod >= 500 => new MailTranslateException('temp', "Çeviri servisi hatası (HTTP $kod)."),
        $kod === 400 || $kod === 404 || $kod === 413 || $kod === 422 => new MailTranslateException('perm', "Çeviri isteği reddedildi (HTTP $kod): $ozet"),
        default => new MailTranslateException('temp', "Beklenmeyen yanıt (HTTP $kod)."),
    };
}

// ── Sağlayıcılar ────────────────────────────────────────────────────────

/** Kapalı sağlayıcı: hiçbir şey dışarı gitmez. */
final class MailTranslateNone implements MailTranslationProviderInterface
{
    public function ad(): string { return 'none'; }
    public function parcaLimiti(): int { return 1000; }
    public function cevir(string $metin, ?string $kaynak, string $hedef): array { throw new MailTranslateException('config', 'Çeviri sağlayıcısı kapalı.'); }
}

/** DeepL API (Free planı ayda 500 000 karakter; anahtar ":fx" ile biterse api-free). */
final class MailTranslateDeepL implements MailTranslationProviderInterface
{
    /** @var callable */
    private $http;
    public function __construct(private string $anahtar, ?callable $http = null)
    {
        mail_redact_sirlar($anahtar);
        $this->http = $http ?? 'mail_http_istek';
    }
    public function ad(): string { return 'deepl'; }
    public function parcaLimiti(): int { return 8000; }
    private static function dil(string $d, bool $hedef): string
    {
        $d = strtolower($d);
        if ($hedef) return ['en' => 'EN-GB', 'pt' => 'PT-PT', 'zh' => 'ZH'][$d] ?? strtoupper($d);
        return strtoupper($d);
    }
    public function cevir(string $metin, ?string $kaynak, string $hedef): array
    {
        $url = str_ends_with($this->anahtar, ':fx') ? 'https://api-free.deepl.com/v2/translate' : 'https://api.deepl.com/v2/translate';
        $alan = ['text' => $metin, 'target_lang' => self::dil($hedef, true), 'split_sentences' => '1', 'preserve_formatting' => '1'];
        if ($kaynak !== null && $kaynak !== '') $alan['source_lang'] = self::dil($kaynak, false);
        $r = ($this->http)('POST', $url, ['Authorization: DeepL-Auth-Key ' . $this->anahtar, 'Content-Type: application/x-www-form-urlencoded'], http_build_query($alan), 25.0);
        if ($r['kod'] !== 200) throw mail_ceviri_http_hata($r['kod'], $r['govde']);
        $j = json_decode($r['govde'], true);
        $t = $j['translations'][0] ?? null;
        if (!is_array($t) || !is_string($t['text'] ?? null)) throw new MailTranslateException('temp', 'DeepL yanıtı çözülemedi.');
        return ['metin' => $t['text'], 'tespit' => isset($t['detected_source_language']) ? strtolower((string)$t['detected_source_language']) : null];
    }
}

/** LibreTranslate (kendi sunucunuz ya da anahtarlı örnek). */
final class MailTranslateLibre implements MailTranslationProviderInterface
{
    /** @var callable */
    private $http;
    public function __construct(private string $adres, private string $anahtar = '', ?callable $http = null)
    {
        if ($anahtar !== '') mail_redact_sirlar($anahtar);
        $this->http = $http ?? 'mail_http_istek';
    }
    public function ad(): string { return 'libretranslate'; }
    public function parcaLimiti(): int { return 4000; }
    public function cevir(string $metin, ?string $kaynak, string $hedef): array
    {
        $g = ['q' => $metin, 'source' => ($kaynak !== null && $kaynak !== '') ? strtolower($kaynak) : 'auto', 'target' => strtolower($hedef), 'format' => 'text'];
        if ($this->anahtar !== '') $g['api_key'] = $this->anahtar;
        $r = ($this->http)('POST', rtrim($this->adres, '/') . '/translate', ['Content-Type: application/json', 'Accept: application/json'], json_encode($g, JSON_UNESCAPED_UNICODE), 25.0);
        if ($r['kod'] !== 200) throw mail_ceviri_http_hata($r['kod'], $r['govde']);
        $j = json_decode($r['govde'], true);
        if (!is_array($j) || !is_string($j['translatedText'] ?? null)) throw new MailTranslateException('temp', 'LibreTranslate yanıtı çözülemedi.');
        return ['metin' => $j['translatedText'], 'tespit' => isset($j['detectedLanguage']['language']) ? strtolower((string)$j['detectedLanguage']['language']) : null];
    }
}

/** MyMemory (anahtarsız; günlük kotası düşük: ~5 000 karakter, e-posta ile ~50 000; istek başına ≤500 bayt). */
final class MailTranslateMyMemory implements MailTranslationProviderInterface
{
    /** @var callable */
    private $http;
    public function __construct(private string $eposta = '', ?callable $http = null) { $this->http = $http ?? 'mail_http_istek'; }
    public function ad(): string { return 'mymemory'; }
    public function parcaLimiti(): int { return 450; }
    public function cevir(string $metin, ?string $kaynak, string $hedef): array
    {
        $kaynakDil = ($kaynak !== null && $kaynak !== '') ? strtolower($kaynak) : 'autodetect';
        $q = ['q' => $metin, 'langpair' => $kaynakDil . '|' . strtolower($hedef)];
        if ($this->eposta !== '') $q['de'] = $this->eposta;
        $r = ($this->http)('GET', 'https://api.mymemory.translated.net/get?' . http_build_query($q), ['Accept: application/json'], null, 20.0);
        if ($r['kod'] !== 200) throw mail_ceviri_http_hata($r['kod'], $r['govde']);
        $j = json_decode($r['govde'], true);
        if (!is_array($j)) throw new MailTranslateException('temp', 'MyMemory yanıtı çözülemedi.');
        $t = (string)($j['responseData']['translatedText'] ?? '');
        $durum = (int)($j['responseStatus'] ?? 200);
        if (!empty($j['quotaFinished']) || $durum === 429 || stripos($t, 'MYMEMORY WARNING') !== false) throw new MailTranslateException('quota', 'MyMemory günlük kotası doldu.');
        if ($durum !== 200 || $t === '') throw new MailTranslateException($durum >= 500 ? 'temp' : 'perm', 'MyMemory çevirisi başarısız (durum ' . $durum . ').');
        return ['metin' => html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'tespit' => null];
    }
}

/**
 * Yapılandırmadan sağlayıcıyı kurar; kapalı/eksik/geçersizse null (çeviri hiç çalışmaz, veri çıkmaz).
 * @param array|null $cfg test enjeksiyonu: provider, key, url, email, http
 */
function mail_ceviri_saglayici(?array $cfg = null): ?MailTranslationProviderInterface
{
    $c = $cfg ?? [
        'provider' => defined('MAIL_TRANSLATE_PROVIDER') ? (string)MAIL_TRANSLATE_PROVIDER : 'none',
        'key'      => defined('MAIL_TRANSLATE_KEY') ? (string)MAIL_TRANSLATE_KEY : '',
        'url'      => defined('MAIL_TRANSLATE_URL') ? (string)MAIL_TRANSLATE_URL : '',
        'email'    => defined('MAIL_TRANSLATE_EMAIL') ? (string)MAIL_TRANSLATE_EMAIL : '',
    ];
    $http = $c['http'] ?? null;
    switch (strtolower(trim((string)($c['provider'] ?? 'none')))) {
        case 'deepl':
            return trim((string)$c['key']) !== '' ? new MailTranslateDeepL(trim((string)$c['key']), $http) : null;
        case 'libretranslate':
            return preg_match('#^https://[^/\s]+#i', (string)($c['url'] ?? '')) ? new MailTranslateLibre((string)$c['url'], trim((string)($c['key'] ?? '')), $http) : null;
        case 'mymemory':
            $e = trim((string)($c['email'] ?? ''));
            return new MailTranslateMyMemory($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '', $http);
        default:
            return null;
    }
}

/** Yönetici ekranı için okunur durum. */
function mail_ceviri_yapilandirma_ozeti(): string
{
    $p = defined('MAIL_TRANSLATE_PROVIDER') ? strtolower((string)MAIL_TRANSLATE_PROVIDER) : 'none';
    if ($p === '' || $p === 'none') return 'KAPALI — mail içeriği hiçbir dış servise gönderilmiyor.';
    if (mail_ceviri_saglayici() === null) return "Sağlayıcı \"$p\" seçili ama yapılandırma eksik/geçersiz (anahtar veya https adres) — çeviri çalışmıyor.";
    return "AÇIK — sağlayıcı: $p. Yalnız gövde metni ve konu gönderilir (adres/başlık/ek gönderilmez).";
}

// ── Metin hazırlama + parçalama ─────────────────────────────────────────

/**
 * Çeviriye gidecek metni hazırlar: alıntı satırları ("> …") ve "… yazdı:" / "-----Original Message-----" sonrası
 * kırpılır (hem maliyet hem veri çıkışı azalır); 12 000 karaktere sınırlanır.
 * @return array{metin:string,kirpildi:bool}
 */
function mail_ceviri_hazirla(string $t): array
{
    $t = str_replace("\r", '', $t);
    $satirlar = explode("\n", $t); $o = []; $kirpildi = false;
    $baslik = '/^\s*(?:-{2,}\s*(?:original message|forwarded message|orijinal mesaj|ursprüngliche nachricht|message d\'origine|mensaje original|исходное сообщение)\b.*|_{5,}|(?:on|am|le|el|il|op)\b.{5,200}\b(?:wrote|schrieb|a écrit|escribió|ha scritto|schreef|yazdı)\s*:?|(?:from|von|de|от|kimden|gönderen)\s*:\s*.+@.+|[\p{L} ]{2,30}\s+\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}.{0,60}(?:yazdı|wrote|schrieb)\s*:?)\s*$/iu';
    foreach ($satirlar as $s) {
        if (preg_match($baslik, $s)) { $kirpildi = true; break; }
        if (preg_match('/^\s*>/', $s)) { $kirpildi = true; continue; }
        $o[] = $s;
    }
    $t = trim((string)preg_replace("/\n{3,}/", "\n\n", implode("\n", $o)));
    if ($t === '') { $t = trim(implode("\n", array_filter($satirlar, static fn($x) => trim((string)preg_replace('/^[>\s]+/', '', $x)) !== ''))); $kirpildi = false; }
    if (mb_strlen($t) > MAIL_CEVIRI_MAX_KARAKTER) { $t = mb_substr($t, 0, MAIL_CEVIRI_MAX_KARAKTER); $kirpildi = true; }
    return ['metin' => $t, 'kirpildi' => $kirpildi];
}

/**
 * Metni parçalara böler (paragraf → cümle → kelime → bayt sırasıyla). Her parça ≤ $maxBayt.
 * @return list<array{0:string,1:string}> [parça, ardından gelen ayraç]
 */
function mail_ceviri_parcala(string $metin, int $maxBayt): array
{
    $maxBayt = max(50, $maxBayt);
    $out = [];
    $paragraflar = preg_split('/(\n{2,})/', $metin, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$metin];
    for ($i = 0; $i < count($paragraflar); $i += 2) {
        $p = $paragraflar[$i]; $ayrac = $paragraflar[$i + 1] ?? '';
        if ($p === '') { if ($out) $out[count($out) - 1][1] .= $ayrac; continue; }
        if (strlen($p) <= $maxBayt) { $out[] = [$p, $ayrac]; continue; }
        $cumleler = preg_split('/(?<=[.!?…。])(\s+)/u', $p, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$p];
        $buf = ''; $bufAyrac = '';
        $bosalt = function (string $son) use (&$out, &$buf, &$bufAyrac) { if ($buf !== '') $out[] = [$buf, $son]; $buf = ''; $bufAyrac = ''; };
        for ($j = 0; $j < count($cumleler); $j += 2) {
            $c = $cumleler[$j]; $bos = $cumleler[$j + 1] ?? '';
            while (strlen($c) > $maxBayt) {       // tek cümle bile sığmıyor: kelimeden, o da olmazsa bayttan böl
                $kes = mb_strcut($c, 0, $maxBayt, 'UTF-8');
                $sp = strrpos($kes, ' ');
                if ($sp !== false && $sp > $maxBayt / 3) $kes = substr($kes, 0, $sp);
                $bosalt(' ');
                $out[] = [$kes, ' ']; $c = ltrim(substr($c, strlen($kes)));
                if ($kes === '') break;
            }
            if ($buf !== '' && strlen($buf) + strlen($bufAyrac) + strlen($c) > $maxBayt) $bosalt(' ');
            $buf = $buf === '' ? $c : $buf . $bufAyrac . $c; $bufAyrac = $bos !== '' ? $bos : ' ';
        }
        $bosalt($ayrac);
    }
    if ($out) $out[count($out) - 1][1] = '';
    return $out;
}

/**
 * Parçala → her parçayı çevir → birleştir. İlk hata istisna olarak yükselir (kısmi sonuç DÖNMEZ).
 * @return array{metin:string,tespit:?string}
 */
function mail_ceviri_cevir(MailTranslationProviderInterface $p, string $metin, ?string $kaynak, string $hedef): array
{
    $sonuc = ''; $tespit = null;
    foreach (mail_ceviri_parcala($metin, $p->parcaLimiti()) as [$parca, $ayrac]) {
        if (trim($parca) === '') { $sonuc .= $parca . $ayrac; continue; }
        $r = $p->cevir($parca, $kaynak, $hedef);
        if (!is_string($r['metin'] ?? null)) throw new MailTranslateException('temp', 'Boş çeviri yanıtı.');
        $sonuc .= $r['metin'] . $ayrac;
        $tespit ??= $r['tespit'] ?? null;
    }
    return ['metin' => trim($sonuc), 'tespit' => $tespit];
}

// ── Duraklatma / durum dosyası ──────────────────────────────────────────

function mail_ceviri_duraklat(int $sn, ?string $dizin = null, ?int $simdi = null): void
{
    @file_put_contents(($dizin ?? mail_depo_dizini()) . '/.translate_pause', (string)(($simdi ?? time()) + $sn));
}
function mail_ceviri_duraklatildi_mi(?string $dizin = null, ?int $simdi = null): bool
{
    $f = ($dizin ?? mail_depo_dizini()) . '/.translate_pause';
    return is_file($f) && (int)@file_get_contents($f) > ($simdi ?? time());
}
function mail_ceviri_durum_yaz(array $d, ?string $dizin = null): void
{
    @file_put_contents(($dizin ?? mail_depo_dizini()) . '/.translate_status.json', json_encode($d + ['zaman' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE));
}
function mail_ceviri_durum_oku(?string $dizin = null): ?array
{
    $f = ($dizin ?? mail_depo_dizini()) . '/.translate_status.json';
    $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return is_array($j) ? $j : null;
}

// ── Mesaj çevirisi ──────────────────────────────────────────────────────

/** Yeniden deneme bekleme süreleri (sn), deneme sayısına göre. */
function mail_ceviri_bekleme(int $deneme): int { return [300, 900, 3600, 14400, 43200][max(0, min(4, $deneme - 1))]; }

/**
 * Tek mesajı çevirir ve YALNIZ tr_* alanlarını yazar (mesaj verisine dokunmaz).
 * @param array $m mail_messages satırı + hesap alanları (target_lang)
 * @return array{durum:string,hata:?string} durum: translated | skipped | failed | retry | quota | config
 */
function mail_ceviri_mesaj(PDO $pdo, MailTranslationProviderInterface $p, array $m, int $simdiTs): array
{
    $id = (int)$m['id']; $hedef = (string)($m['target_lang'] ?: 'tr');
    $yaz = static function (array $set) use ($pdo, $id): void {
        $kol = []; $par = [];
        foreach ($set as $k => $v) { $kol[] = "$k = ?"; $par[] = $v; }
        $par[] = $id;
        $pdo->prepare('UPDATE mail_messages SET ' . implode(', ', $kol) . " WHERE id = ? AND tr_status <> 'translated'")->execute($par);
    };
    $kaynak = $m['lang'] ?: mail_dil_tespit((string)$m['body_text']);
    if ($kaynak !== null && $kaynak === $hedef) {
        $yaz(['lang' => $kaynak, 'tr_status' => 'skipped', 'tr_error' => null, 'tr_next_at' => null]);
        return ['durum' => 'skipped', 'hata' => null];
    }
    $h = mail_ceviri_hazirla((string)$m['body_text']);
    if ($h['metin'] === '' && trim((string)$m['subject']) === '') {
        $yaz(['tr_status' => 'skipped', 'tr_error' => null, 'tr_next_at' => null]);
        return ['durum' => 'skipped', 'hata' => null];
    }
    try {
        $govde = $h['metin'] !== '' ? mail_ceviri_cevir($p, $h['metin'], $kaynak, $hedef) : ['metin' => '', 'tespit' => null];
        $konu = trim((string)$m['subject']) !== '' ? mail_ceviri_cevir($p, mb_substr((string)$m['subject'], 0, 300), $kaynak ?? $govde['tespit'], $hedef) : ['metin' => '', 'tespit' => null];
    } catch (MailTranslateException $e) {
        $hata = mb_substr(mail_redact($e->getMessage()), 0, 250);
        if ($e->kind === 'config') return ['durum' => 'config', 'hata' => $hata];          // mesaj pending kalır, kuyruk durur
        if ($e->kind === 'quota')  return ['durum' => 'quota', 'hata' => $hata];           // deneme hakkı yenmez
        $deneme = (int)$m['tr_attempts'] + 1;
        if ($e->kind === 'perm' || $deneme >= MAIL_CEVIRI_MAX_DENEME) {
            $yaz(['tr_status' => 'failed', 'tr_attempts' => $deneme, 'tr_error' => $hata, 'tr_next_at' => null, 'lang' => $kaynak]);
            return ['durum' => 'failed', 'hata' => $hata];
        }
        $yaz(['tr_attempts' => $deneme, 'tr_error' => $hata, 'tr_next_at' => date('Y-m-d H:i:s', $simdiTs + mail_ceviri_bekleme($deneme))]);
        return ['durum' => 'retry', 'hata' => $hata];
    }
    $dil = $kaynak ?? $govde['tespit'] ?? $konu['tespit'];
    if ($dil !== null && $dil === $hedef) {   // sağlayıcı kaynak dilin zaten hedef olduğunu söyledi
        $yaz(['lang' => $dil, 'tr_status' => 'skipped', 'tr_error' => null, 'tr_next_at' => null]);
        return ['durum' => 'skipped', 'hata' => null];
    }
    $yaz(['tr_status' => 'translated', 'body_tr' => $govde['metin'], 'subject_tr' => mb_substr($konu['metin'], 0, 500), 'lang' => $dil,
        'tr_error' => null, 'tr_next_at' => null, 'tr_attempts' => (int)$m['tr_attempts'] + 1]);
    return ['durum' => 'translated', 'hata' => null];
}

/**
 * Kuyruk: çeviri açık hesaplardaki pending mailler (7 günden yeniler), yeniden deneme zamanı gelmiş olanlar.
 * Yapılandırma hatasında DURUR (mailler pending kalır); kotada duraklatır.
 * @param array $opt limit (20) · sure (60 sn) · simdi (ts) · dizin (durum dosyaları)
 * @return array{ceviri:int,atlandi:int,hata:int,beklemede:int,durum:string,mesaj:?string}
 */
function mail_ceviri_isle(PDO $pdo, ?MailTranslationProviderInterface $p, array $opt = []): array
{
    $s = ['ceviri' => 0, 'atlandi' => 0, 'hata' => 0, 'beklemede' => 0, 'durum' => 'ok', 'mesaj' => null];
    $dizin = $opt['dizin'] ?? null;
    $simdi = (int)($opt['simdi'] ?? time());
    if ($p === null || $p->ad() === 'none') { $s['durum'] = 'kapali'; return $s; }
    if (mail_ceviri_duraklatildi_mi($dizin, $simdi)) { $s['durum'] = 'duraklatildi'; return $s; }
    $bitis = microtime(true) + (float)($opt['sure'] ?? 60.0);
    $limit = (int)($opt['limit'] ?? 20);
    // 7 günden eski bekleyenler çevrilmez (kota + veri çıkışı): sessizce skipped yapılır.
    $pdo->prepare("UPDATE mail_messages SET tr_status = 'skipped' WHERE tr_status = 'pending' AND received_at < ?")
        ->execute([date('Y-m-d H:i:s', $simdi - MAIL_CEVIRI_GERI_TARIH)]);
    $st = $pdo->prepare("SELECT m.*, a.target_lang FROM mail_messages m JOIN mail_accounts a ON a.id = m.account_id
        WHERE m.tr_status = 'pending' AND a.translate_enabled = 1 AND a.is_active = 1 AND (m.tr_next_at IS NULL OR m.tr_next_at <= ?)
        ORDER BY m.id ASC LIMIT " . max(1, $limit));
    $st->execute([date('Y-m-d H:i:s', $simdi)]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
        if (microtime(true) > $bitis) { $s['beklemede']++; continue; }
        $r = mail_ceviri_mesaj($pdo, $p, $m, $simdi);
        switch ($r['durum']) {
            case 'translated': $s['ceviri']++; break;
            case 'skipped':    $s['atlandi']++; break;
            case 'failed':     $s['hata']++; break;
            case 'retry':      $s['beklemede']++; break;
            case 'quota':
                mail_ceviri_duraklat(6 * 3600, $dizin, $simdi);
                $s['durum'] = 'kota'; $s['mesaj'] = $r['hata']; mail_ceviri_durum_yaz($s, $dizin);
                return $s;
            case 'config':
                $s['durum'] = 'yapilandirma_hatasi'; $s['mesaj'] = $r['hata']; mail_ceviri_durum_yaz($s, $dizin);
                return $s;
        }
    }
    mail_ceviri_durum_yaz($s, $dizin);
    return $s;
}

/**
 * Kullanıcı isteğiyle tek mesajı HEMEN çevirir ("Şimdi çevir" / "Tekrar dene"). ACL: yalnız görünür hesap.
 * @return array{ok:bool,mesaj:string}
 */
function mail_ceviri_simdi(PDO $pdo, ?MailTranslationProviderInterface $p, int $msgId, array $hesapIds, ?int $simdi = null): array
{
    if ($p === null || $p->ad() === 'none') return ['ok' => false, 'mesaj' => 'Çeviri sağlayıcısı kapalı (yönetici yapılandırmalı).'];
    $m = mail_mesaj_getir($pdo, $msgId, $hesapIds);
    if ($m === null) return ['ok' => false, 'mesaj' => 'Mesaj bulunamadı.'];
    if ($m['tr_status'] === 'translated') return ['ok' => true, 'mesaj' => 'Zaten çevrilmiş.'];
    $a = $pdo->prepare('SELECT target_lang FROM mail_accounts WHERE id = ?'); $a->execute([(int)$m['account_id']]);
    $m['target_lang'] = (string)($a->fetchColumn() ?: 'tr');
    $m['tr_attempts'] = 0;
    $pdo->prepare("UPDATE mail_messages SET tr_status = 'pending', tr_attempts = 0, tr_next_at = NULL, tr_error = NULL WHERE id = ?")->execute([$msgId]);
    $r = mail_ceviri_mesaj($pdo, $p, $m, $simdi ?? time());
    return match ($r['durum']) {
        'translated' => ['ok' => true, 'mesaj' => 'Çevrildi.'],
        'skipped'    => ['ok' => true, 'mesaj' => 'Mail zaten hedef dilde; çeviri gerekmedi.'],
        'retry'      => ['ok' => false, 'mesaj' => 'Çeviri şu an başarısız; otomatik olarak tekrar denenecek: ' . $r['hata']],
        default      => ['ok' => false, 'mesaj' => 'Çeviri başarısız: ' . ($r['hata'] ?? '?')],
    };
}
