<?php
// =========================================================
// config/WhatsAppService.php — Evolution API v2 (Baileys) istemcisi (cURL)
//
// Yalnız HTTP katmanı: veritabanı / oturum / yetki BİLMEZ (çağıran sayfa kapıyı kurar).
// Her çağrı: bağlantı 5 sn + toplam zaman aşımı, yönlendirme takip edilmez, yalnız http(s),
// yanıt boyutu sınırlı. Ağ hatası → WhatsAppException(httpStatus=0); HTTP ≥ 400 → WhatsAppException(httpStatus=kod).
// Testte HTTP katmanı `$transport` ile değiştirilir: fn(string $method, string $url, array $headers, ?string $body, int $timeout): array{status:int, body:string}
// =========================================================
declare(strict_types=1);

final class WhatsAppException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $httpStatus, $previous);
    }
}

final class WhatsAppService
{
    private const MAX_YANIT = 8388608; // 8 MB (fetchAllGroups büyük olabilir)

    /** @var callable */
    private $transport;

    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private string $instance,
        ?callable $transport = null,
        private int $timeout = 15
    ) {
        $this->baseUrl   = rtrim($baseUrl, '/');
        $this->transport = $transport ?? [self::class, 'curlTransport'];
    }

    /** config/whatsapp_config.php sabitlerinden kurar (instance adı panelden verilirse onu kullanır). */
    public static function fromConfig(?string $instance = null, ?callable $transport = null): self
    {
        $ad = trim((string)$instance) !== '' ? (string)$instance : (string)EVOLUTION_INSTANCE_NAME;
        return new self((string)EVOLUTION_API_URL, (string)EVOLUTION_API_KEY, $ad, $transport);
    }

    public function instanceAdi(): string { return $this->instance; }

    // ── Oturum ───────────────────────────────────────────────

    /**
     * Oturum (instance) yoksa oluşturur. Zaten varsa hata vermez.
     * @return array{olusturuldu:bool, qr:?string, ham:array}  qr = "data:image/png;base64,…" ya da null
     */
    public function createInstance(): array
    {
        $r = $this->istek('POST', '/instance/create', [
            'instanceName' => $this->instance,
            'qrcode'       => true,
            'integration'  => 'WHATSAPP-BAILEYS',
        ], [403, 409]);   // "instance already in use" → mevcut
        if ($r['status'] === 403 || $r['status'] === 409) {
            return ['olusturuldu' => false, 'qr' => null, 'ham' => $r['data']];
        }
        return ['olusturuldu' => true, 'qr' => self::qrCikar($r['data']), 'ham' => $r['data']];
    }

    /**
     * Bağlanmak için QR kodu üretir (oturum yoksa önce oluşturur).
     * Zaten bağlıysa null döner (QR gerekmez).
     * @return string|null  "data:image/png;base64,…"
     */
    public function getQrCode(): ?string
    {
        $durum = $this->getConnectionState();
        if ($durum === 'open') return null;

        $r = $this->istek('GET', '/instance/connect/' . rawurlencode($this->instance), null, [404]);
        if ($r['status'] === 404) {                 // oturum hiç yok → oluştur (yanıtta QR gelir)
            $c = $this->createInstance();
            if ($c['qr'] !== null) return $c['qr'];
            $r = $this->istek('GET', '/instance/connect/' . rawurlencode($this->instance));
        }
        return self::qrCikar($r['data']);
    }

    /**
     * Bağlantı durumu: 'open' | 'connecting' | 'close'. Oturum yoksa 'close'.
     */
    public function getConnectionState(): string
    {
        $r = $this->istek('GET', '/instance/connectionState/' . rawurlencode($this->instance), null, [404]);
        if ($r['status'] === 404) return 'close';
        $d = $r['data'];
        $s = $d['instance']['state'] ?? $d['state'] ?? '';
        $s = strtolower(is_string($s) ? $s : '');
        return in_array($s, ['open', 'connecting', 'close'], true) ? $s : 'close';
    }

    // ── Gruplar / mesajlar ────────────────────────────────────

    /**
     * Hattın üye olduğu WhatsApp grupları (@g.us), ada göre sıralı.
     * @return list<array{jid:string, ad:string, uye:int}>
     */
    public function fetchGroups(): array
    {
        $r = $this->istek('GET', '/group/fetchAllGroups/' . rawurlencode($this->instance) . '?getParticipants=false');
        $liste = is_array($r['data']) ? $r['data'] : [];
        $out = [];
        foreach ($liste as $g) {
            if (!is_array($g)) continue;
            $jid = (string)($g['id'] ?? '');
            if (!str_ends_with($jid, '@g.us')) continue;
            $out[] = [
                'jid' => $jid,
                'ad'  => trim((string)($g['subject'] ?? '')) !== '' ? trim((string)$g['subject']) : $jid,
                'uye' => (int)($g['size'] ?? (is_array($g['participants'] ?? null) ? count($g['participants']) : 0)),
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcasecmp($a['ad'], $b['ad']));
        return $out;
    }

    /**
     * Evolution'ın kayıtlı sohbet listesi (/chat/findChats): kişisel (@s.whatsapp.net / @lid) ve grup (@g.us)
     * sohbetleri, son etkinlik zamanıyla. Evolution'da DATABASE_SAVE_DATA_CHATS=true olmalı; uç yoksa/eskiyse [] döner.
     * @return list<array{jid:string, ad:string, tur:string, telefon:string, son:int}>  en son etkinlik önce
     */
    public function fetchChats(): array
    {
        $r = $this->istek('POST', '/chat/findChats/' . rawurlencode($this->instance), ['where' => new stdClass()], [400, 404]);
        if ($r['status'] === 400 || $r['status'] === 404) return [];
        $liste = $r['data'];
        if (isset($liste['chats']) && is_array($liste['chats'])) $liste = $liste['chats'];
        if (!is_array($liste) || ($liste !== [] && !array_is_list($liste))) return [];
        $out = [];
        foreach ($liste as $c) {
            if (!is_array($c)) continue;
            $jid = trim((string)($c['remoteJid'] ?? $c['id'] ?? ''));
            if (!preg_match('/^[0-9A-Za-z._:\-]+@(g\.us|s\.whatsapp\.net|lid)$/', $jid) || isset($out[$jid])) continue;
            $grup = str_ends_with($jid, '@g.us');
            $son  = $c['lastMessage'] ?? null;
            $ad   = trim((string)($c['name'] ?? $c['pushName'] ?? ''));
            if ($ad === '' && !$grup && is_array($son) && (($son['key']['fromMe'] ?? false) !== true)) {
                $ad = trim((string)($son['pushName'] ?? ''));
            }
            $tel = '';
            if (!$grup) {
                foreach ([$jid, (string)($c['remoteJidAlt'] ?? ''), (string)($son['key']['remoteJidAlt'] ?? '')] as $aday) {
                    $n = self::jidTelefon($aday);
                    if ($n !== '') { $tel = $n; break; }
                }
            }
            $ts = 0;
            if (is_array($son)) {
                $t = $son['messageTimestamp'] ?? 0;
                if (is_array($t)) $t = $t['low'] ?? 0;
                $ts = is_numeric($t) ? (int)$t : 0;
            }
            if ($ts <= 0 && is_string($c['updatedAt'] ?? null)) $ts = (int)(strtotime($c['updatedAt']) ?: 0);
            $out[$jid] = [
                'jid'     => $jid,
                'ad'      => mb_substr($ad !== '' ? $ad : ($tel !== '' ? '+' . $tel : $jid), 0, 150, 'UTF-8'),
                'tur'     => $grup ? 'grup' : 'kisi',
                'telefon' => $tel,
                'son'     => $ts,
            ];
        }
        $out = array_values($out);
        usort($out, static fn(array $a, array $b): int => ($b['son'] <=> $a['son']) ?: strcasecmp($a['ad'], $b['ad']));
        return $out;
    }

    /** WhatsApp oturumunu kapatır (yeniden QR okutunca telefon son mesaj geçmişini tekrar gönderir). */
    public function logout(): void
    {
        $this->istek('DELETE', '/instance/logout/' . rawurlencode($this->instance), null, [400, 404]);
    }

    /** "905321234567@s.whatsapp.net" → "905321234567"; @lid / grup / geçersiz → ''. */
    private static function jidTelefon(string $jid): string
    {
        if (!str_ends_with($jid, '@s.whatsapp.net')) return '';
        $n = preg_replace('/\D/', '', explode(':', explode('@', $jid)[0])[0]) ?? '';
        return (strlen($n) >= 8 && strlen($n) <= 15) ? $n : '';
    }

    /**
     * Geçmiş mesajları /chat/findMessages ile çeker (Evolution'da DATABASE_SAVE_DATA_NEW_MESSAGE=true olmalı).
     * @return list<array>  Evolution kayıtları (key, pushName, message, messageTimestamp …), en yeni önce
     */
    public function fetchMessages(string $remoteJid, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $r = $this->istek('POST', '/chat/findMessages/' . rawurlencode($this->instance), [
            'where'  => ['key' => ['remoteJid' => $remoteJid]],
            'page'   => 1,
            'offset' => $limit,
        ]);
        $d = $r['data'];
        // v2: {messages:{total,pages,currentPage,records:[…]}} · eski: doğrudan liste
        if (isset($d['messages']['records']) && is_array($d['messages']['records'])) $d = $d['messages']['records'];
        elseif (isset($d['records']) && is_array($d['records'])) $d = $d['records'];
        elseif (isset($d['messages']) && is_array($d['messages'])) $d = $d['messages'];
        if (!is_array($d) || ($d !== [] && array_is_list($d) === false)) return [];
        $d = array_values(array_filter($d, 'is_array'));
        usort($d, static fn(array $a, array $b): int => (int)($b['messageTimestamp'] ?? 0) <=> (int)($a['messageTimestamp'] ?? 0));
        return array_slice($d, 0, $limit);
    }

    /**
     * Kişiye ya da gruba düz metin gönderir.
     * @param string $toJid  grup JID'i (…@g.us) ya da telefon (905xxxxxxxxx)
     * @return array  Evolution yanıtı
     */
    public function sendTextMessage(string $toJid, string $text): array
    {
        $toJid = trim($toJid);
        $text  = trim($text);
        if ($toJid === '' || $text === '') throw new WhatsAppException('Alıcı ve metin zorunlu.');
        return $this->istek('POST', '/message/sendText/' . rawurlencode($this->instance), [
            'number' => $toJid,
            'text'   => mb_substr($text, 0, 4000),
        ])['data'];
    }

    // ── HTTP ─────────────────────────────────────────────────

    /**
     * @param list<int> $kabulEdilenHatalar  bu HTTP kodları istisna atmaz (çağıran status'a bakar)
     * @return array{status:int, data:mixed}
     */
    private function istek(string $metod, string $yol, ?array $govde = null, array $kabulEdilenHatalar = []): array
    {
        if ($this->baseUrl === '' || !preg_match('#^https?://#i', $this->baseUrl)) {
            throw new WhatsAppException('EVOLUTION_API_URL geçersiz (http:// ya da https:// ile başlamalı).');
        }
        if ($this->apiKey === '') throw new WhatsAppException('EVOLUTION_API_KEY tanımlı değil (config/local.php).');

        $basliklar = ['apikey: ' . $this->apiKey, 'Accept: application/json'];
        $json = null;
        if ($govde !== null) {
            $json = json_encode($govde, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $basliklar[] = 'Content-Type: application/json';
        }
        try {
            $y = ($this->transport)($metod, $this->baseUrl . $yol, $basliklar, $json, $this->timeout);
        } catch (WhatsAppException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new WhatsAppException('Evolution API\'ye ulaşılamadı: ' . $e->getMessage(), 0, $e);
        }
        $status = (int)($y['status'] ?? 0);
        $data   = json_decode((string)($y['body'] ?? ''), true);
        if ($status >= 400 && !in_array($status, $kabulEdilenHatalar, true)) {
            $ozet = is_array($data) ? (string)($data['response']['message'][0] ?? $data['message'] ?? $data['error'] ?? '') : '';
            if (is_array($data['response']['message'] ?? null)) $ozet = implode(' ', array_map('strval', $data['response']['message']));
            throw new WhatsAppException("Evolution API HTTP {$status}" . ($ozet !== '' ? ': ' . mb_substr($ozet, 0, 160) : ''), $status);
        }
        return ['status' => $status, 'data' => is_array($data) ? $data : []];
    }

    /** Varsayılan cURL aktarımı. */
    public static function curlTransport(string $metod, string $url, array $basliklar, ?string $govde, int $timeout): array
    {
        if (!function_exists('curl_init')) throw new WhatsAppException('PHP cURL eklentisi yok.');
        $ch = curl_init($url);
        $cikti = ''; $asildi = false;
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $metod,
            CURLOPT_HTTPHEADER     => $basliklar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => max(3, $timeout),
            CURLOPT_USERAGENT      => 'AsyaFresh-WhatsApp/1',
            CURLOPT_WRITEFUNCTION  => static function ($c, $d) use (&$cikti, &$asildi) {
                if (strlen($cikti) + strlen($d) > self::MAX_YANIT) { $asildi = true; return 0; }
                $cikti .= $d;
                return strlen($d);
            },
        ]);
        if ($govde !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $govde);
        $ok   = curl_exec($ch);
        $kod  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hata = curl_error($ch);
        curl_close($ch);
        if ($asildi) throw new WhatsAppException('Evolution API yanıtı çok büyük.');
        if ($ok === false && $kod === 0) throw new WhatsAppException('Evolution API\'ye ulaşılamadı: ' . mb_substr($hata, 0, 160));
        return ['status' => $kod, 'body' => $cikti];
    }

    /** Evolution yanıtındaki QR'ı "data:image/png;base64,…" biçimine çevirir. */
    private static function qrCikar(array $d): ?string
    {
        $b = $d['qrcode']['base64'] ?? $d['base64'] ?? null;
        if (!is_string($b) || $b === '') return null;
        return str_starts_with($b, 'data:image') ? $b : 'data:image/png;base64,' . $b;
    }
}
