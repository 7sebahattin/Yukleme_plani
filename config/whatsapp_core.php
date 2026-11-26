<?php
// =========================================================
// config/whatsapp_core.php — WhatsApp Yükleme Planı modülü ÇEKİRDEK
//
// Webhook (api_whatsapp_webhook.php) ve yönetim paneli (whatsapp_yonetim.php) AYNI kodu kullanır:
//   gürültü süzgeci · plaka normalizasyonu · mesaj metni çıkarma · ayarlar · kayıt hattı (log → AI → karar → taslak)
//
// Tablolar (docs/sql/whatsapp_migration.sql): whatsapp_mesaj_loglari · whatsapp_ayarlar
// Yükleme planına yazma TEK yerde: whatsapp_plana_aktar() → loading_records (durum='taslak'; yalnız yönetici onayıyla '' olur).
//
// Durumlar (whatsapp_mesaj_loglari.durum):
//   isleniyor   yer tutucu satır eklendi, AI henüz cevap vermedi (çökme olursa panelden yeniden ayrıştırılır)
//   ai_hata     AI çağrısı başarısız (kota/ağ/anahtar) — panelden yeniden denenir
//   alakasiz    yükleme planı değil (sohbet)
//   eksik_bilgi plan ama kritik alan eksik ya da güven eşiğin altında
//   hazir       kritik alanlar tam + güven yeterli (otomatik aktarım kapalıysa burada kalır)
//   aktarildi   loading_records'a taslak olarak yazıldı
// =========================================================
declare(strict_types=1);

require_once __DIR__ . '/whatsapp_config.php';
require_once __DIR__ . '/WhatsAppService.php';
require_once __DIR__ . '/AIParserService.php';

const WA_DURUMLAR = ['isleniyor', 'ai_hata', 'alakasiz', 'eksik_bilgi', 'hazir', 'aktarildi'];

// ── Saf yardımcılar ──────────────────────────────────────────

/**
 * Türkiye plakasını standart biçime çevirir: "34kl4589" → "34 KL 4589", "06-abc-12" → "06 ABC 12".
 * Biçim tanınmazsa (yabancı plaka, hatalı yazım) bilgi KAYBEDİLMEZ: harf/rakam dışı temizlenip büyük harfe çevrilir.
 */
if (!function_exists('normalizeTurkishPlate')) {
    function normalizeTurkishPlate(?string $plaka): ?string
    {
        if ($plaka === null) return null;
        $s = trim($plaka);
        if ($s === '') return null;
        // i/ı → I (küçük harfle yazılmış "34 ki 123" gibi girişler), sonra büyük harf
        $u = mb_strtoupper(strtr($s, ['i' => 'I', 'ı' => 'I']), 'UTF-8');
        $k = (string)preg_replace('/[\s\-\._]+/u', '', $u);
        // 01-81 il kodu + 1-3 harf (Türk plakasında yalnız A-Z) + 2-4 rakam
        if (preg_match('/^(0[1-9]|[1-7]\d|8[01])([A-Z]{1,3})(\d{2,4})$/', $k, $m)) {
            return $m[1] . ' ' . $m[2] . ' ' . $m[3];
        }
        $t = trim((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $u));
        return $t !== '' ? mb_substr($t, 0, 30, 'UTF-8') : null;
    }
}

/**
 * AI kotasını korur: 8 karakterden kısa VEYA ne rakam ne lojistik anahtar kelimesi içeren mesaj "gürültü"dür.
 * (Yanlış pozitif ucuz: fazladan bir AI çağrısı. Yanlış negatif pahalı: kaçan plan — bu yüzden anahtar kelimeler alt-dize eşleşir.)
 */
if (!function_exists('isNoiseMessage')) {
    function isNoiseMessage(string $text): bool
    {
        $t = trim($text);
        if (mb_strlen($t, 'UTF-8') < 8) return true;
        if (preg_match('/\d/', $t)) return false;
        // Türkçe küçük harf + ASCII sadeleştirme: "YÜK" / "Tır" / "İrsaliye" hepsi eşleşsin
        $f = mb_strtolower(strtr($t, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
        $f = strtr($f, ['ı' => 'i', 'ş' => 's', 'ç' => 'c', 'ğ' => 'g', 'ö' => 'o', 'ü' => 'u']);
        foreach (['plaka', 'ton', 'yuk', 'tir', 'kamyon', 'dorse', 'sevk', 'depo', 'fabrika', 'irsaliye'] as $k) {
            if (str_contains($f, $k)) return false;
        }
        return true;
    }
}

/** Evolution `message` nesnesinden düz metni çıkarır (sohbet, alıntılı, resim/video/belge açıklaması, geçici mesaj sarmalayıcıları). */
function whatsapp_mesaj_metni(?array $m): string
{
    if (!is_array($m)) return '';
    for ($i = 0; $i < 4; $i++) {    // ephemeral/viewOnce sarmalayıcılarını aç
        $acildi = false;
        foreach (['ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2', 'documentWithCaptionMessage'] as $w) {
            if (isset($m[$w]['message']) && is_array($m[$w]['message'])) { $m = $m[$w]['message']; $acildi = true; break; }
        }
        if (!$acildi) break;
    }
    foreach ([
        $m['conversation'] ?? null,
        $m['extendedTextMessage']['text'] ?? null,
        $m['imageMessage']['caption'] ?? null,
        $m['videoMessage']['caption'] ?? null,
        $m['documentMessage']['caption'] ?? null,
    ] as $c) {
        if (is_string($c) && trim($c) !== '') return trim($c);
    }
    return '';
}

/** "905321234567@s.whatsapp.net" / "905321234567:12@s.whatsapp.net" → "905321234567"; @lid (telefon değil) → ''. */
function whatsapp_jid_telefon(string $jid): string
{
    if ($jid === '' || !str_contains($jid, '@') || str_ends_with($jid, '@lid') || str_ends_with($jid, '@g.us')) return '';
    $n = preg_replace('/\D/', '', explode(':', explode('@', $jid)[0])[0]) ?? '';
    return (strlen($n) >= 8 && strlen($n) <= 15) ? $n : '';
}

/** "messageTimestamp" (int | numerik metin | {low,high}) → 'Y-m-d' ; geçersizse bugün. */
function whatsapp_mesaj_tarihi($ts): string
{
    if (is_array($ts)) $ts = $ts['low'] ?? 0;
    return (is_numeric($ts) && (int)$ts > 946684800) ? date('Y-m-d', (int)$ts) : date('Y-m-d');
}

/** Kritik alan kuralı: plaka VE (firma_adi VEYA yukleme_yeri). */
function whatsapp_kritik_dolu(array $p): bool
{
    return ($p['plaka'] ?? null) !== null && (($p['firma_adi'] ?? null) !== null || ($p['yukleme_yeri'] ?? null) !== null);
}

/** Webhook gizli anahtarı: `X-Webhook-Secret` | `Authorization: Bearer` | `?token=` — sabit zamanlı karşılaştırma; anahtar tanımsızsa HİÇBİR istek geçmez. */
function whatsapp_webhook_yetkili(array $server, array $get): bool
{
    $beklenen = (string)WEBHOOK_SECRET_TOKEN;
    if ($beklenen === '') return false;
    $adaylar = [(string)($server['HTTP_X_WEBHOOK_SECRET'] ?? ''), (string)($get['token'] ?? '')];
    $auth = (string)($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (stripos($auth, 'Bearer ') === 0) $adaylar[] = trim(substr($auth, 7));
    foreach ($adaylar as $a) {
        if ($a !== '' && hash_equals($beklenen, $a)) return true;
    }
    return false;
}

// ── Veritabanı yardımcıları ──────────────────────────────────

function wa_simdi(): string { return date('Y-m-d H:i:s'); }

function wa_kolon_var(PDO $db, string $tablo, string $kolon): bool
{
    try {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($db->query('PRAGMA table_info(`' . str_replace('`', '', $tablo) . '`)') as $c) {
                if ($c['name'] === $kolon) return true;
            }
            return false;
        }
        $st = $db->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $tablo) . '` LIKE ?');
        $st->execute([$kolon]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** whatsapp_mesaj_loglari + whatsapp_ayarlar var mı? (yoksa sayfa kurulum notu gösterir, webhook 200 + 'sema' döner) */
function whatsapp_tablolar_hazir(PDO $db): bool
{
    try {
        $db->query('SELECT 1 FROM whatsapp_mesaj_loglari LIMIT 0');
        $db->query('SELECT 1 FROM whatsapp_ayarlar LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Etkin ayarlar: tablodaki satır (id=1) → yoksa/boşsa config sabitleri.
 * @return array{instance_adi:string, secili_gruplar:list<array{jid:string,ad:string}>, ai_saglayici:string, otomatik_taslak:bool, kayitli:bool}
 */
function whatsapp_ayarlar_oku(PDO $db): array
{
    $a = ['instance_adi' => (string)EVOLUTION_INSTANCE_NAME, 'secili_gruplar' => [], 'ai_saglayici' => (string)AI_PROVIDER, 'otomatik_taslak' => true, 'kayitli' => false];
    try {
        $r = $db->query('SELECT instance_adi, secili_gruplar, ai_saglayici, otomatik_taslak FROM whatsapp_ayarlar WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $r = false;
    }
    if (is_array($r)) {
        $a['kayitli'] = true;
        if (trim((string)$r['instance_adi']) !== '') $a['instance_adi'] = trim((string)$r['instance_adi']);
        if (in_array((string)$r['ai_saglayici'], ['groq', 'gemini'], true)) $a['ai_saglayici'] = (string)$r['ai_saglayici'];
        $a['otomatik_taslak'] = (int)$r['otomatik_taslak'] === 1;
        $j = json_decode((string)($r['secili_gruplar'] ?? ''), true);
        if (is_array($j)) {
            foreach ($j as $g) {
                $jid = is_array($g) ? (string)($g['jid'] ?? '') : (is_string($g) ? $g : '');
                if (str_ends_with($jid, '@g.us')) $a['secili_gruplar'][] = ['jid' => $jid, 'ad' => is_array($g) ? (string)($g['ad'] ?? $jid) : $jid];
            }
        }
    }
    if ($a['secili_gruplar'] === []) {   // panelden seçim yoksa sabit listeye düş
        foreach ((array)ALLOWED_GROUP_JIDS as $jid) {
            if (is_string($jid) && str_ends_with($jid, '@g.us')) $a['secili_gruplar'][] = ['jid' => $jid, 'ad' => $jid];
        }
    }
    return $a;
}

/** @param list<array{jid:string,ad:string}> $gruplar */
function whatsapp_ayarlar_kaydet(PDO $db, array $gruplar, string $saglayici, bool $otomatik, ?int $kullaniciId): void
{
    $temiz = [];
    foreach ($gruplar as $g) {
        $jid = (string)($g['jid'] ?? '');
        if (!preg_match('/^[0-9A-Za-z._\-]+@g\.us$/', $jid)) continue;
        $temiz[$jid] = ['jid' => $jid, 'ad' => mb_substr(trim((string)($g['ad'] ?? $jid)), 0, 120, 'UTF-8')];
    }
    $json = json_encode(array_values($temiz), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $saglayici = in_array($saglayici, ['groq', 'gemini'], true) ? $saglayici : 'groq';
    $var = (bool)$db->query('SELECT 1 FROM whatsapp_ayarlar WHERE id = 1')->fetchColumn();
    if ($var) {
        $db->prepare('UPDATE whatsapp_ayarlar SET secili_gruplar = ?, ai_saglayici = ?, otomatik_taslak = ?, guncelleyen_id = ?, updated_at = ? WHERE id = 1')
           ->execute([$json, $saglayici, $otomatik ? 1 : 0, $kullaniciId, wa_simdi()]);
    } else {
        $db->prepare('INSERT INTO whatsapp_ayarlar (id, instance_adi, secili_gruplar, ai_saglayici, otomatik_taslak, guncelleyen_id, updated_at) VALUES (1, ?, ?, ?, ?, ?, ?)')
           ->execute([(string)EVOLUTION_INSTANCE_NAME, $json, $saglayici, $otomatik ? 1 : 0, $kullaniciId, wa_simdi()]);
    }
}

/** Dinlenecek JID'ler; boş dizi = tüm gruplar. */
function whatsapp_izinli_jidler(array $ayar): array
{
    return array_values(array_map(static fn(array $g): string => $g['jid'], $ayar['secili_gruplar']));
}

function whatsapp_denetle(string $eylem, string $modul, ?int $id, ?array $eski, ?array $yeni, ?int $kullaniciId = null): void
{
    if (function_exists('audit_log_event')) audit_log_event($eylem, $modul, $id, $eski, $yeni, $kullaniciId);
}

// ── Kayıt hattı ──────────────────────────────────────────────

/**
 * Evolution webhook gövdesini işler (idempotent). HİÇBİR koşulda istisna fırlatmaz.
 * @return array{ok:bool, sonuclar:list<array{message_id:string, sonuc:string}>}
 *   sonuc: yok_sayildi:<neden> | mukerrer | gurultu | <durum>
 */
function whatsapp_webhook_isle(PDO $db, array $payload, ?AIParserService $ai = null, ?DateTimeImmutable $simdi = null): array
{
    $sonuclar = [];
    $olay = strtolower(str_replace('_', '.', (string)($payload['event'] ?? '')));
    if ($olay !== 'messages.upsert') return ['ok' => true, 'sonuclar' => [['message_id' => '', 'sonuc' => 'yok_sayildi:olay']]];

    if (!whatsapp_tablolar_hazir($db)) {
        error_log('[whatsapp] tablolar yok — docs/sql/whatsapp_migration.sql çalıştırılmalı');
        return ['ok' => false, 'sonuclar' => [['message_id' => '', 'sonuc' => 'yok_sayildi:sema']]];
    }
    $ayar = whatsapp_ayarlar_oku($db);

    // Başka bir instance'ın olayı (aynı Evolution'da birden çok hat) → yok say
    $inst = (string)($payload['instance'] ?? '');
    if ($inst !== '' && $inst !== $ayar['instance_adi']) return ['ok' => true, 'sonuclar' => [['message_id' => '', 'sonuc' => 'yok_sayildi:instance']]];

    $data = $payload['data'] ?? null;
    if (!is_array($data)) return ['ok' => true, 'sonuclar' => [['message_id' => '', 'sonuc' => 'yok_sayildi:veri']]];
    if (isset($data['key'])) $mesajlar = [$data];
    elseif (isset($data['messages']) && is_array($data['messages'])) $mesajlar = $data['messages'];
    else $mesajlar = array_is_list($data) ? $data : [];

    $izinli = whatsapp_izinli_jidler($ayar);
    foreach (array_slice($mesajlar, 0, 20) as $m) {
        if (!is_array($m)) continue;
        try {
            $sonuclar[] = whatsapp_tek_mesaj_isle($db, $m, $izinli, $ayar, $ai, $simdi);
        } catch (Throwable $e) {
            error_log('[whatsapp] mesaj işlenemedi: ' . $e->getMessage());
            $sonuclar[] = ['message_id' => (string)($m['key']['id'] ?? ''), 'sonuc' => 'yok_sayildi:hata'];
        }
    }
    return ['ok' => true, 'sonuclar' => $sonuclar];
}

/** @return array{message_id:string, sonuc:string} */
function whatsapp_tek_mesaj_isle(PDO $db, array $m, array $izinli, array $ayar, ?AIParserService $ai, ?DateTimeImmutable $simdi): array
{
    $key = is_array($m['key'] ?? null) ? $m['key'] : [];
    $id  = trim((string)($key['id'] ?? ''));
    $r   = static fn(string $s): array => ['message_id' => $id, 'sonuc' => $s];

    // 1) Olay doğrulama: kendi mesajımız / grup değil / izin dışı grup / metin yok
    if (($key['fromMe'] ?? false) === true) return $r('yok_sayildi:kendi_mesaj');
    $jid = (string)($key['remoteJid'] ?? '');
    if ($id === '' || strlen($id) > 120 || $jid === '') return $r('yok_sayildi:anahtar');
    if (!str_ends_with($jid, '@g.us')) return $r('yok_sayildi:grup_degil');           // kişisel sohbetler HİÇ okunmaz
    if ($izinli !== [] && !in_array($jid, $izinli, true)) return $r('yok_sayildi:grup_izinsiz');
    $metin = whatsapp_mesaj_metni(is_array($m['message'] ?? null) ? $m['message'] : null);
    if ($metin === '') return $r('yok_sayildi:metin_yok');

    // 2) Idempotency
    $v = $db->prepare('SELECT 1 FROM whatsapp_mesaj_loglari WHERE message_id = ?');
    $v->execute([$id]);
    if ($v->fetchColumn()) return $r('mukerrer');

    // 3) Gürültü süzgeci — AI çağrılmaz, satır da saklanmaz (özel sohbet birikmesin)
    if (isNoiseMessage($metin)) return $r('gurultu');

    // Gerçek gönderen: grupta key.participant
    $katilimci = (string)($key['participant'] ?? $m['participant'] ?? '');
    $telefon = whatsapp_jid_telefon($katilimci);
    if ($telefon === '') {
        foreach (['participantPn', 'senderPn', 'participantAlt'] as $alan) {
            $t = whatsapp_jid_telefon((string)($key[$alan] ?? $m[$alan] ?? ''));
            if ($t !== '') { $telefon = $t; break; }
        }
    }
    $ad = mb_substr(trim((string)($m['pushName'] ?? '')), 0, 150, 'UTF-8');
    $mesajTarihi = whatsapp_mesaj_tarihi($m['messageTimestamp'] ?? null);

    // 4) Yer tutucu satır (eşzamanlı çift teslimde AI iki kez çağrılmasın): UNIQUE(message_id) kazananı seçer
    try {
        $db->prepare('INSERT INTO whatsapp_mesaj_loglari (message_id, remote_jid, is_group, sender_name, sender_phone, raw_message, durum, created_at)
                      VALUES (?, ?, 1, ?, ?, ?, \'isleniyor\', ?)')
           ->execute([$id, $jid, $ad, $telefon, mb_substr($metin, 0, 4000, 'UTF-8'), wa_simdi()]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') return $r('mukerrer');
        throw $e;
    }
    $logId = (int)$db->lastInsertId();

    // 5) AI ayrıştırma
    return $r(whatsapp_kaydi_ayristir($db, $logId, $metin, $mesajTarihi, $ayar, $ai, $simdi));
}

/**
 * Var olan log satırını AI ile ayrıştırır, kararı verir ve (açıksa) taslağı oluşturur. Yeniden ayrıştırma da bunu kullanır.
 * @return string son durum
 */
function whatsapp_kaydi_ayristir(PDO $db, int $logId, string $metin, string $mesajTarihi, array $ayar, ?AIParserService $ai = null, ?DateTimeImmutable $simdi = null): string
{
    try {
        $ai ??= AIParserService::fromConfig($ayar['ai_saglayici']);
        $p = $ai->parseMessage($metin, $simdi);
    } catch (Throwable $e) {   // AIParserException + beklenmeyen her şey: satır 'isleniyor'da ASILI KALMASIN
        $db->prepare('UPDATE whatsapp_mesaj_loglari SET durum = \'ai_hata\', hata_mesaji = ?, updated_at = ? WHERE id = ?')
           ->execute([mb_substr($e->getMessage(), 0, 250, 'UTF-8'), wa_simdi(), $logId]);
        error_log('[whatsapp] AI hatası (' . ($e instanceof AIParserException ? $e->tur : get_class($e)) . '): ' . $e->getMessage());
        return 'ai_hata';
    }

    // PHP plaka normalizasyonu (model biçimine güvenme)
    $p['plaka']       = normalizeTurkishPlate($p['plaka']);
    $p['dorse_plaka'] = normalizeTurkishPlate($p['dorse_plaka']);

    $esik = (int)AI_MIN_CONFIDENCE;
    if (!$p['is_loading_plan'])                                             $durum = 'alakasiz';
    elseif (whatsapp_kritik_dolu($p) && $p['guven_skoru'] >= $esik)         $durum = 'hazir';
    else                                                                    $durum = 'eksik_bilgi';

    $p['_meta'] = ['ai' => $ai->saglayici(), 'model' => $ai->model(), 'esik' => $esik, 'mesaj_tarihi' => $mesajTarihi];
    $db->prepare('UPDATE whatsapp_mesaj_loglari SET parsed_data = ?, durum = ?, plaka = ?, yukleme_tarihi = ?, guven_skoru = ?, hata_mesaji = NULL, updated_at = ? WHERE id = ?')
       ->execute([
           json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
           $durum, $p['plaka'], $p['yukleme_tarihi'], $p['guven_skoru'], wa_simdi(), $logId,
       ]);

    if ($durum === 'hazir' && $ayar['otomatik_taslak']) {
        $a = whatsapp_plana_aktar($db, $logId, null, true);
        if ($a['ok']) return 'aktarildi';
    }
    return $durum;
}

/**
 * Log satırındaki ayrıştırılmış planı loading_records'a TASLAK olarak yazar (durum='taslak').
 * Aynı plaka + aynı yükleme tarihi için daha önce aktarılmış (ve hâlâ var olan) bir taslak/plan varsa yenisini AÇMAZ,
 * mevcut kayda bağlar (mükerrer WhatsApp mesajı = aynı sefer).
 * @return array{ok:bool, hata?:string, record_id?:int, mukerrer?:bool}
 */
function whatsapp_plana_aktar(PDO $db, int $logId, ?int $kullaniciId, bool $otomatik = false): array
{
    $st = $db->prepare('SELECT * FROM whatsapp_mesaj_loglari WHERE id = ?');
    $st->execute([$logId]);
    $log = $st->fetch(PDO::FETCH_ASSOC);
    if (!$log) return ['ok' => false, 'hata' => 'Kayıt bulunamadı.'];
    if (!empty($log['loading_record_id'])) return ['ok' => false, 'hata' => 'Bu mesaj zaten yükleme planına aktarılmış.'];

    $p = json_decode((string)($log['parsed_data'] ?? ''), true);
    if (!is_array($p) || empty($p['is_loading_plan'])) return ['ok' => false, 'hata' => 'Bu mesaj yükleme planı olarak ayrıştırılmamış.'];
    if (($p['plaka'] ?? null) === null && ($p['firma_adi'] ?? null) === null && ($p['yukleme_yeri'] ?? null) === null && ($p['malzeme_cinsi'] ?? null) === null) {
        return ['ok' => false, 'hata' => 'Aktarılacak anlamlı veri yok (plaka, firma, yer ya da malzeme gerekli).'];
    }

    $tarih = (string)($p['yukleme_tarihi'] ?? '');
    if ($tarih === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih)) $tarih = (string)($p['_meta']['mesaj_tarihi'] ?? date('Y-m-d'));

    // Mükerrer: aynı plaka + tarih daha önce aktarılmış ve kayıt hâlâ duruyor
    if (($p['plaka'] ?? null) !== null) {
        $d = $db->prepare('SELECT l.loading_record_id FROM whatsapp_mesaj_loglari l
                           JOIN loading_records r ON r.id = l.loading_record_id
                           WHERE l.plaka = ? AND l.id <> ? AND l.loading_record_id IS NOT NULL AND r.tarih = ?
                           ORDER BY l.id DESC LIMIT 1');
        $d->execute([$p['plaka'], $logId, $tarih]);
        $mevcut = (int)$d->fetchColumn();
        if ($mevcut > 0) {
            $u = $db->prepare('UPDATE whatsapp_mesaj_loglari SET loading_record_id = ?, durum = \'aktarildi\', updated_at = ? WHERE id = ? AND loading_record_id IS NULL');
            $u->execute([$mevcut, wa_simdi(), $logId]);
            return ['ok' => true, 'record_id' => $mevcut, 'mukerrer' => true];
        }
    }

    $buyuk = static fn(?string $s): string => $s === null ? '' : (function_exists('tr_upper') ? tr_upper($s) : mb_strtoupper($s, 'UTF-8'));
    $plakaBitisik = static fn(?string $s): string => $s === null ? '' : (string)preg_replace('/\s+/u', '', $buyuk($s));   // plan modülündeki biçim: boşluksuz ("34KL4589")

    $kolon = [
        'type'      => 'yukleme',
        'tarih'     => $tarih,
        'firma'     => $buyuk($p['firma_adi'] ?? null),
        'bolge'     => $buyuk($p['yukleme_yeri'] ?? null),
        'urun'      => $buyuk($p['malzeme_cinsi'] ?? null),
        'on_plaka'  => $plakaBitisik($p['plaka'] ?? null),
        'arka_plaka'=> $plakaBitisik($p['dorse_plaka'] ?? null),
        'sofor_adi' => $buyuk($p['sofor_adi'] ?? null),
        'telefon'   => (string)($p['sofor_telefon'] ?? ''),
        'durum'     => 'taslak',
    ];
    if (wa_kolon_var($db, 'loading_records', 'gidecek_ulke')) $kolon['gidecek_ulke'] = $buyuk($p['bosaltma_yeri'] ?? null);

    try {
        $db->beginTransaction();
        $adlar = array_keys($kolon);
        $db->prepare('INSERT INTO loading_records (' . implode(', ', $adlar) . ') VALUES (' . implode(', ', array_fill(0, count($adlar), '?')) . ')')
           ->execute(array_values($kolon));
        $rid = (int)$db->lastInsertId();
        $u = $db->prepare('UPDATE whatsapp_mesaj_loglari SET loading_record_id = ?, durum = \'aktarildi\', updated_at = ? WHERE id = ? AND loading_record_id IS NULL');
        $u->execute([$rid, wa_simdi(), $logId]);
        if ($u->rowCount() !== 1) { $db->rollBack(); return ['ok' => false, 'hata' => 'Bu mesaj az önce başka bir işlemle aktarıldı.']; }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[whatsapp] plana aktarım hatası: ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Yükleme planına yazılamadı.'];
    }

    // Audit: ham mesaj METNİ yazılmaz (grup sohbeti); yalnız bağ + alan özeti
    whatsapp_denetle($otomatik ? 'whatsapp_taslak_otomatik' : 'whatsapp_taslak_manuel', 'records', $rid, null, [
        'durum' => 'taslak', 'kaynak' => 'whatsapp', 'log_id' => $logId, 'plaka' => $p['plaka'] ?? null,
        'tarih' => $tarih, 'guven_skoru' => (int)($p['guven_skoru'] ?? 0),
    ], $kullaniciId);
    return ['ok' => true, 'record_id' => $rid];
}

/** Taslak planı onaylar: durum 'taslak' → '' (normal "Planlandı" kaydı). Yalnız hâlâ taslaksa. */
function whatsapp_taslak_onayla(PDO $db, int $logId, ?int $kullaniciId): array
{
    $st = $db->prepare('SELECT loading_record_id FROM whatsapp_mesaj_loglari WHERE id = ?');
    $st->execute([$logId]);
    $rid = (int)$st->fetchColumn();
    if ($rid <= 0) return ['ok' => false, 'hata' => 'Bu mesajın bağlı bir planı yok.'];
    $u = $db->prepare("UPDATE loading_records SET durum = '' WHERE id = ? AND durum = 'taslak'");
    $u->execute([$rid]);
    if ($u->rowCount() !== 1) return ['ok' => false, 'hata' => 'Plan artık taslak değil ya da bulunamadı.'];
    whatsapp_denetle('status_change', 'records', $rid, ['durum' => 'taslak'], ['durum' => '', 'kaynak' => 'whatsapp', 'log_id' => $logId], $kullaniciId);
    return ['ok' => true, 'record_id' => $rid];
}

/** Panel için son N kayıt (en yeni önce) + bağlı planın güncel durumu. */
function whatsapp_son_mesajlar(PDO $db, int $limit = 25): array
{
    $limit = max(1, min(100, $limit));
    $st = $db->query('SELECT l.id, l.message_id, l.remote_jid, l.sender_name, l.sender_phone, l.raw_message, l.parsed_data, l.durum,
                             l.guven_skoru, l.loading_record_id, l.hata_mesaji, l.created_at, r.durum AS plan_durum
                      FROM whatsapp_mesaj_loglari l
                      LEFT JOIN loading_records r ON r.id = l.loading_record_id
                      ORDER BY l.id DESC LIMIT ' . $limit);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['parsed'] = json_decode((string)($row['parsed_data'] ?? ''), true);
        if (!is_array($row['parsed'])) $row['parsed'] = null;
        $out[] = $row;
    }
    return $out;
}
