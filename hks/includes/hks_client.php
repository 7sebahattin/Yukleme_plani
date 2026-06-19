<?php
declare(strict_types=1);
// HKS SOAP istemcisi — tüm web servis çağrıları burada yönetilir

class HksClient {

    private ?array $settings = null;
    private HksRepository $repo;

    public function __construct(HksRepository $repo) {
        $this->repo     = $repo;
        $this->settings = $repo->getSettings();
    }

    public function hasSettings(): bool {
        return $this->settings !== null && ($this->settings['username'] ?? '') !== '';
    }

    public function isLiveSendEnabled(): bool {
        return $this->hasSettings() && (int)($this->settings['live_send_enabled'] ?? 0) === 1;
    }

    public function getEnvironment(): string {
        return $this->settings['environment'] ?? 'test';
    }

    private function genelWsdl(): string {
        $u = trim((string)($this->settings['genel_wsdl_url'] ?? ''));
        if ($u !== '' && !$this->isStaleWsdl($u)) {
            return $u;
        }
        return $this->getEnvironment() === 'live' ? HKS_WSDL_LIVE_GENEL : HKS_WSDL_TEST_GENEL;
    }

    private function bildirimWsdl(): string {
        $u = trim((string)($this->settings['bildirim_wsdl_url'] ?? ''));
        if ($u !== '' && !$this->isStaleWsdl($u)) {
            return $u;
        }
        return $this->getEnvironment() === 'live' ? HKS_WSDL_LIVE_BILDIRIM : HKS_WSDL_TEST_BILDIRIM;
    }

    // Eskiden config'de olan, DNS'te çözülmeyen/yanlış yollu adresleri reddet —
    // DB'ye kaydedilmiş olsa bile düzeltilmiş varsayılana düşülsün.
    private function isStaleWsdl(string $url): bool {
        return stripos($url, 'hkstest.hal.gov.tr') !== false
            || stripos($url, '/HKSService/') !== false;
    }

    private function timeout(): int {
        return max(5, (int)($this->settings['timeout_seconds'] ?? HKS_DEFAULT_TIMEOUT));
    }

    private function soapOptions(): array {
        $ctx = stream_context_create([
            'ssl'  => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
            'http' => ['user_agent' => 'PHP-SOAP/HKS-Client'],
        ]);
        return [
            'connection_timeout' => $this->timeout(),
            'cache_wsdl'         => WSDL_CACHE_NONE,
            'exceptions'         => true,
            'trace'              => true,
            'encoding'           => 'UTF-8',
            'soap_version'       => SOAP_1_1,
            'stream_context'     => $ctx,
        ];
    }

    /**
     * WSDL URL'sini indirip geçici dosyaya yazar, local path döner.
     * Sırayla cURL → file_get_contents (allow_url_fopen) → eski önbellek denenir.
     * Hiçbiri olmazsa, hangi yöntemin neden başarısız olduğunu açıklayan hata fırlatır.
     */
    private function loadWsdl(string $url): string {
        // Zaten local dosya yolu ise dokunma
        if (!preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $cacheFile = sys_get_temp_dir() . '/hks_wsdl_' . md5($url) . '.xml';
        $errors    = [];

        // 1) cURL — allow_url_fopen=Off ortamlarında tek seçenek
        if (function_exists('curl_init')) {
            [$content, $err] = $this->httpGetCurl($url);
            if ($content !== null) {
                @file_put_contents($cacheFile, $content);
                return $cacheFile;
            }
            $errors[] = 'cURL: ' . $err;
        } else {
            $errors[] = 'cURL: extension yüklü değil';
        }

        // 2) file_get_contents — allow_url_fopen açıksa
        if (filter_var((string)ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $ctx = stream_context_create([
                'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
                'http' => ['timeout' => $this->timeout(), 'user_agent' => 'PHP-SOAP/HKS-Client'],
            ]);
            $content = @file_get_contents($url, false, $ctx);
            if ($content !== false && strlen($content) > 100) {
                @file_put_contents($cacheFile, $content);
                return $cacheFile;
            }
            $errors[] = 'file_get_contents: yanıt alınamadı';
        } else {
            $errors[] = 'file_get_contents: allow_url_fopen=Off';
        }

        // 3) Eski önbellek varsa onu kullan (en azından çalışmaya devam etsin)
        if (is_file($cacheFile) && (int)@filesize($cacheFile) > 100) {
            return $cacheFile;
        }

        throw new \RuntimeException(
            "WSDL indirilemedi. Sunucu '{$url}' adresine ulaşamıyor. Denenen yöntemler → "
            . implode(' | ', $errors)
        );
    }

    /**
     * cURL ile HTTP GET. Başarıda [içerik, ''] , başarısızlıkta [null, 'sebep'] döner.
     * 3. eleman olarak HTTP kodu da döndürülür.
     */
    private function httpGetCurl(string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT        => $this->timeout(),
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout()),
            CURLOPT_USERAGENT      => 'PHP-SOAP/HKS-Client/1.0',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_HTTPHEADER     => ['Accept: text/xml, application/xml'],
        ]);
        $content = curl_exec($ch);
        $code    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err     = curl_error($ch);
        curl_close($ch);

        if ($content !== false && $code === 200 && strlen((string)$content) > 100) {
            return [(string)$content, '', $code];
        }
        $reason = $err !== '' ? $err : ('HTTP ' . $code . ', ' . strlen((string)$content) . ' bayt');
        return [null, $reason, $code];
    }

    // ── Sunucu Tanılama ──────────────────────────────────────
    // Web servis bağlantı sorunlarının kaynağını tespit eder.
    // Hosting ortamı (curl/allow_url_fopen/DNS/firewall/SSL) hakkında somut bilgi verir.
    public function diagnostics(): array {
        $wsdl = $this->genelWsdl();
        $host = (string)(parse_url($wsdl, PHP_URL_HOST) ?: '');
        $port = (int)(parse_url($wsdl, PHP_URL_PORT) ?: 443);

        $d = [
            'php_version'     => PHP_VERSION,
            'soap_ext'        => extension_loaded('soap'),
            'curl_ext'        => function_exists('curl_init'),
            'openssl_ext'     => extension_loaded('openssl'),
            'allow_url_fopen' => filter_var((string)ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN),
            'environment'     => $this->getEnvironment(),
            'wsdl_url'        => $wsdl,
            'host'            => $host,
            'port'            => $port,
        ];

        // DNS çözümleme
        $ip = $host !== '' ? @gethostbyname($host) : '';
        $d['dns_resolved'] = ($ip !== '' && $ip !== $host);
        $d['dns_ip']       = $d['dns_resolved'] ? $ip : '';

        // TCP/TLS bağlantısı (firewall tespiti)
        $d['tcp_connect'] = false;
        $d['tcp_error']   = '';
        if ($host !== '') {
            $errno = 0; $errstr = '';
            $fp = @fsockopen('ssl://' . $host, $port, $errno, $errstr, min(8, $this->timeout()));
            if ($fp) { $d['tcp_connect'] = true; fclose($fp); }
            else     { $d['tcp_error'] = trim('errno ' . $errno . ': ' . $errstr); }
        }

        // Ham HTTP GET (cURL ile)
        $d['http_code']  = null;
        $d['http_error'] = '';
        $d['http_bytes'] = 0;
        if ($d['curl_ext']) {
            [$content, $err, $code] = $this->httpGetCurl($wsdl);
            $d['http_code']  = $code;
            $d['http_error'] = $err;
            $d['http_bytes'] = $content !== null ? strlen($content) : 0;
        }

        return $d;
    }

    // Tanılama dizisini tek satırlık özet metne çevirir (test mesajına eklenir)
    private function diagSummary(array $d): string {
        $parts = [];
        $parts[] = 'curl=' . ($d['curl_ext'] ? 'var' : 'YOK');
        $parts[] = 'allow_url_fopen=' . ($d['allow_url_fopen'] ? 'açık' : 'KAPALI');
        $parts[] = 'DNS=' . ($d['dns_resolved'] ? $d['dns_ip'] : 'ÇÖZÜLEMEDİ');
        $parts[] = 'TCP:443=' . ($d['tcp_connect'] ? 'açık' : ('KAPALI' . ($d['tcp_error'] ? ' (' . $d['tcp_error'] . ')' : '')));
        if ($d['http_code'] !== null) {
            $parts[] = 'HTTP=' . $d['http_code'] . ($d['http_error'] ? ' (' . $d['http_error'] . ')' : '');
        }
        return implode(' · ', $parts);
    }

    private function credentials(): array {
        return [
            'username'         => $this->settings['username'] ?? '',
            'password'         => hks_decrypt($this->settings['password_enc'] ?? ''),
            'service_password' => hks_decrypt($this->settings['service_password_enc'] ?? ''),
        ];
    }

    // ── Bağlantı Testi ───────────────────────────────────────

    public function testConnection(): array {
        $start = microtime(true);
        $env   = $this->getEnvironment();

        if (!hks_check_soap()) {
            return $this->testFail('PHP SOAP extension aktif değil. php.ini\'de extension=soap etkinleştirin.');
        }
        if (!$this->hasSettings()) {
            return $this->testFail('HKS ayarları yapılandırılmamış.');
        }

        $wsdl = $this->genelWsdl();
        if (!$wsdl) {
            return $this->testFail('WSDL URL tanımlanmamış.');
        }

        try {
            $localWsdl = $this->loadWsdl($wsdl);
            $client    = new SoapClient($localWsdl, $this->soapOptions());
            $duration  = (int)((microtime(true) - $start) * 1000);

            $this->logService('GenelService', 'testConnection', $env,
                ['wsdl' => $wsdl], ['status' => 'wsdl_loaded'],
                true, null, null, $duration);

            $this->repo->updateTestResult(true, 'WSDL başarıyla yüklendi. ' . $wsdl);
            return ['ok' => true, 'message' => 'WSDL başarıyla yüklendi.', 'duration_ms' => $duration];

        } catch (SoapFault $e) {
            $duration = (int)((microtime(true) - $start) * 1000);
            $diag     = $this->diagnostics();
            $msg      = 'SOAP hatası: ' . $e->faultstring . ' — [' . $this->diagSummary($diag) . ']';
            $this->logService('GenelService', 'testConnection', $env,
                ['wsdl' => $wsdl], null, false, $e->faultcode, $e->faultstring, $duration);
            $this->repo->updateTestResult(false, $msg);
            return ['ok' => false, 'message' => $msg, 'duration_ms' => $duration, 'diag' => $diag];

        } catch (Throwable $e) {
            $duration = (int)((microtime(true) - $start) * 1000);
            $diag     = $this->diagnostics();
            $msg      = 'Bağlantı hatası: ' . $e->getMessage() . ' — [' . $this->diagSummary($diag) . ']';
            $this->logService('GenelService', 'testConnection', $env,
                ['wsdl' => $wsdl], null, false, null, $e->getMessage(), $duration);
            $this->repo->updateTestResult(false, $msg);
            return ['ok' => false, 'message' => $msg, 'duration_ms' => $duration, 'diag' => $diag];
        }
    }

    // ── Referans Servisleri ──────────────────────────────────
    // NOT: Aşağıdaki method adları HKS kılavuzuna göre güncellenmelidir.
    // Servis eşlemesi tamamlanana kadar placeholder olarak işaretlendi.

    public function getUlkeler(): array {
        return $this->callGenelService('GetUlkeler', [], 'ulke');
    }

    public function getIller(): array {
        return $this->callGenelService('GetIller', [], 'il');
    }

    public function getIlceler(string $il_kodu): array {
        return $this->callGenelService('GetIlceler', ['IlKodu' => $il_kodu], 'ilce');
    }

    public function getDepolar(): array {
        return $this->callGenelService('GetDepolar', [], 'depo');
    }

    public function getSubeler(): array {
        return $this->callGenelService('GetSubeler', [], 'sube');
    }

    public function getUrunler(): array {
        return $this->callGenelService('GetUrunler', [], 'urun');
    }

    public function getUrunBirimleri(): array {
        return $this->callGenelService('GetUrunBirimleri', [], 'urun_birim');
    }

    public function getUrunCinsleri(): array {
        return $this->callGenelService('GetUrunCinsleri', [], 'urun_cins');
    }

    public function getBildirimTurleri(): array {
        return $this->callGenelService('GetBildirimTurleri', [], 'bildirim_turu');
    }

    public function getSifatlar(): array {
        return $this->callGenelService('GetSifatlar', [], 'sifat');
    }

    public function getMalinNiteligi(): array {
        return $this->callGenelService('GetMalinNiteligi', [], 'malin_niteligi');
    }

    public function getUretimSekli(): array {
        return $this->callGenelService('GetUretimSekli', [], 'uretim_sekli');
    }

    // ── WSDL İnceleme ───────────────────────────────────────

    public function inspectWsdl(string $service = 'genel'): array {
        $start = microtime(true);
        $env   = $this->getEnvironment();

        if (!hks_check_soap()) {
            return ['ok' => false, 'message' => 'PHP SOAP extension aktif değil.', 'methods' => []];
        }
        if (!$this->hasSettings()) {
            return ['ok' => false, 'message' => 'HKS ayarları yapılandırılmamış.', 'methods' => []];
        }

        $wsdl     = $service === 'bildirim' ? $this->bildirimWsdl() : $this->genelWsdl();
        $svcLabel = $service === 'bildirim' ? 'BildirimService' : 'GenelService';

        try {
            $localWsdl = $this->loadWsdl($wsdl);
            $client    = new SoapClient($localWsdl, $this->soapOptions());
            $functions = $client->__getFunctions() ?? [];
            $duration  = (int)((microtime(true) - $start) * 1000);

            $this->logService($svcLabel, 'inspectWsdl', $env,
                ['wsdl' => $wsdl], ['method_count' => count($functions)],
                true, null, null, $duration);

            return [
                'ok'          => true,
                'wsdl'        => $wsdl,
                'service'     => $service,
                'methods'     => $functions,
                'duration_ms' => $duration,
            ];

        } catch (SoapFault $e) {
            $duration = (int)((microtime(true) - $start) * 1000);
            $this->logService($svcLabel, 'inspectWsdl', $env,
                ['wsdl' => $wsdl], null, false, $e->faultcode, $e->faultstring, $duration);
            return ['ok' => false, 'message' => 'SOAP hatası: ' . $e->faultstring, 'methods' => [], 'duration_ms' => $duration];

        } catch (Throwable $e) {
            $duration = (int)((microtime(true) - $start) * 1000);
            return ['ok' => false, 'message' => $e->getMessage(), 'methods' => [], 'duration_ms' => $duration];
        }
    }

    // ── Bildirim Servisleri ─────────────────────────────────
    // Canlı bildirim gönderimi KAPALI — sadece sorgu metodları aktif.

    public function saveBildirim(array $payload): array {
        return [
            'ok'      => false,
            'message' => 'HKS Bildirim Kaydet servisi henüz eşlenmedi. '
                       . 'Kılavuzdan method adı doğrulandıktan sonra aktif edilecek.',
            'pending' => true,
        ];
    }

    public function queryBildirim(array $params): array {
        $start = microtime(true);
        $env   = $this->getEnvironment();

        if (!hks_check_soap() || !$this->hasSettings()) {
            return ['ok' => false, 'message' => 'Servis kullanılamıyor.'];
        }

        $candidates = ['GetBildirimDetay', 'BildirimSorgu', 'GetBildirim', 'BildirimDetayGetir'];
        $method = $this->discoverMethod($this->bildirimWsdl(), $candidates);

        if ($method === null) {
            $inspect = $this->inspectWsdl('bildirim');
            return [
                'ok'               => false,
                'message'          => 'Bildirim sorgu methodu bulunamadı. WSDL\'i inceleyin.',
                'methods_available' => $inspect['methods'] ?? [],
                'hint'             => 'Aşağıdaki methodlardan uygun olanı hks_client.php\'deki $candidates dizisine ekleyin.',
            ];
        }

        $creds = $this->credentials();
        $requestParams = array_merge([
            'KullaniciAdi' => $creds['username'],
            'Sifre'        => $creds['password'],
            'ServisSifre'  => $creds['service_password'],
        ], $params);

        try {
            $client   = new SoapClient($this->loadWsdl($this->bildirimWsdl()), $this->soapOptions());
            $result   = $client->__soapCall($method, [$requestParams]);
            $duration = (int)((microtime(true) - $start) * 1000);
            $data     = $this->extractResultArray($result, $method);

            $this->logService('BildirimService', $method, $env,
                $requestParams, ['count' => count($data)], true, null, null, $duration);

            return ['ok' => true, 'data' => $data, 'method_used' => $method, 'duration_ms' => $duration];

        } catch (SoapFault $e) {
            $duration = (int)((microtime(true) - $start) * 1000);
            $this->logService('BildirimService', $method, $env,
                $requestParams, null, false, $e->faultcode, $e->faultstring, $duration);
            return ['ok' => false, 'message' => 'SOAP hatası: ' . $e->faultstring, 'duration_ms' => $duration];

        } catch (Throwable $e) {
            $duration = (int)((microtime(true) - $start) * 1000);
            return ['ok' => false, 'message' => $e->getMessage(), 'duration_ms' => $duration];
        }
    }

    public function queryKunye(string $kunye_no): array {
        $start = microtime(true);
        $env   = $this->getEnvironment();

        if (!hks_check_soap() || !$this->hasSettings()) {
            return ['ok' => false, 'message' => 'Servis kullanılamıyor.'];
        }

        $candidates = ['GetKunyeDetay', 'KunyeSorgu', 'GetKunye', 'MalSorgu', 'KunyeliMalSorgu'];
        $method = $this->discoverMethod($this->genelWsdl(), $candidates);

        if ($method === null) {
            $inspect = $this->inspectWsdl('genel');
            return [
                'ok'               => false,
                'message'          => 'Künye sorgu methodu bulunamadı. WSDL\'i inceleyin.',
                'methods_available' => $inspect['methods'] ?? [],
                'hint'             => 'Aşağıdaki methodlardan uygun olanı hks_client.php\'deki $candidates dizisine ekleyin.',
            ];
        }

        $creds = $this->credentials();
        $requestParams = [
            'KullaniciAdi' => $creds['username'],
            'Sifre'        => $creds['password'],
            'ServisSifre'  => $creds['service_password'],
            'KunyeNo'      => $kunye_no,
        ];

        try {
            $client   = new SoapClient($this->loadWsdl($this->genelWsdl()), $this->soapOptions());
            $result   = $client->__soapCall($method, [$requestParams]);
            $duration = (int)((microtime(true) - $start) * 1000);
            $data     = $this->extractResultArray($result, $method);

            $this->logService('GenelService', $method, $env,
                $requestParams, ['data' => $data], true, null, null, $duration);

            return ['ok' => true, 'data' => $data, 'method_used' => $method, 'duration_ms' => $duration];

        } catch (SoapFault $e) {
            $duration = (int)((microtime(true) - $start) * 1000);
            $this->logService('GenelService', $method, $env,
                $requestParams, null, false, $e->faultcode, $e->faultstring, $duration);
            return ['ok' => false, 'message' => 'SOAP hatası: ' . $e->faultstring, 'duration_ms' => $duration];

        } catch (Throwable $e) {
            $duration = (int)((microtime(true) - $start) * 1000);
            return ['ok' => false, 'message' => $e->getMessage(), 'duration_ms' => $duration];
        }
    }

    // ── İç Yardımcılar ──────────────────────────────────────

    private function callGenelService(string $method, array $params, string $context): array {
        $start = microtime(true);
        $env   = $this->getEnvironment();

        if (!hks_check_soap()) {
            return ['ok' => false, 'message' => 'PHP SOAP extension aktif değil.', 'data' => []];
        }
        if (!$this->hasSettings()) {
            return ['ok' => false, 'message' => 'HKS ayarları yapılandırılmamış.', 'data' => []];
        }

        $creds = $this->credentials();
        $requestParams = array_merge([
            'KullaniciAdi' => $creds['username'],
            'Sifre'        => $creds['password'],
            'ServisSifre'  => $creds['service_password'],
        ], $params);

        try {
            $client  = new SoapClient($this->loadWsdl($this->genelWsdl()), $this->soapOptions());
            $result  = $client->__soapCall($method, [$requestParams]);
            $duration = (int)((microtime(true) - $start) * 1000);

            $data = $this->extractResultArray($result, $method);
            $this->logService('GenelService', $method, $env,
                $requestParams, ['count' => count($data)], true, null, null, $duration);

            return ['ok' => true, 'data' => $data, 'duration_ms' => $duration];

        } catch (SoapFault $e) {
            $duration = (int)((microtime(true) - $start) * 1000);
            $this->logService('GenelService', $method, $env,
                $requestParams, null, false, $e->faultcode, $e->faultstring, $duration);
            return ['ok' => false, 'message' => 'SOAP hatası: ' . $e->faultstring, 'data' => []];

        } catch (Throwable $e) {
            $duration = (int)((microtime(true) - $start) * 1000);
            $this->logService('GenelService', $method, $env,
                $requestParams, null, false, null, $e->getMessage(), $duration);
            return ['ok' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    private function extractResultArray(mixed $result, string $method): array {
        if (is_array($result)) return $result;
        if (is_object($result)) {
            // Yaygın HKS response yapısı: ->GetXxxResult veya ->return
            $prop = $method . 'Result';
            if (isset($result->$prop)) {
                $v = $result->$prop;
                return is_array($v) ? $v : (is_object($v) ? [(array)$v] : []);
            }
            if (isset($result->return)) {
                $v = $result->return;
                return is_array($v) ? $v : (is_object($v) ? [(array)$v] : []);
            }
            return [(array)$result];
        }
        return [];
    }

    // Verilen WSDL'den aday method adlarından birini keşfeder
    private function discoverMethod(string $wsdl, array $candidates): ?string {
        try {
            $client    = new SoapClient($this->loadWsdl($wsdl), $this->soapOptions());
            $functions = $client->__getFunctions() ?? [];
            $available = [];
            foreach ($functions as $sig) {
                if (preg_match('/\b(\w+)\(/', $sig, $m)) {
                    $available[] = $m[1];
                }
            }
            foreach ($candidates as $candidate) {
                if (in_array($candidate, $available, true)) {
                    return $candidate;
                }
            }
        } catch (Throwable) {}
        return null;
    }

    private function notMapped(string $service): array {
        return [
            'ok'      => false,
            'message' => "'{$service}' servisi henüz eşlenmedi. Kılavuzdan method adı doğrulandıktan sonra aktif edilecek.",
            'pending' => true,
        ];
    }

    private function testFail(string $msg): array {
        $this->repo->updateTestResult(false, $msg);
        return ['ok' => false, 'message' => $msg, 'duration_ms' => 0];
    }

    private function logService(
        string  $service,
        string  $method,
        string  $env,
        array   $request,
        ?array  $response,
        bool    $ok,
        ?string $errCode,
        ?string $errMsg,
        int     $duration
    ): void {
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $this->repo->addServiceLog($service, $method, $env, $request, $response,
            $ok, $errCode, $errMsg, $duration, $userId);
    }
}
