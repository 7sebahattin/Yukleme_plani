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
        if (!empty($this->settings['genel_wsdl_url'])) {
            return $this->settings['genel_wsdl_url'];
        }
        return $this->getEnvironment() === 'live' ? HKS_WSDL_LIVE_GENEL : HKS_WSDL_TEST_GENEL;
    }

    private function bildirimWsdl(): string {
        if (!empty($this->settings['bildirim_wsdl_url'])) {
            return $this->settings['bildirim_wsdl_url'];
        }
        return $this->getEnvironment() === 'live' ? HKS_WSDL_LIVE_BILDIRIM : HKS_WSDL_TEST_BILDIRIM;
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
     * WSDL URL'sini cURL ile çekip geçici dosyaya yazar, local path döner.
     * allow_url_fopen=Off olan hosting ortamlarında SoapClient URL'den yükleyemez;
     * cURL bu kısıtı bypass eder.
     */
    private function loadWsdl(string $url): string {
        // Local dosya / zaten önbelleklenmiş
        if (!preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $cacheFile = sys_get_temp_dir() . '/hks_wsdl_' . md5($url) . '.xml';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT        => $this->timeout(),
                CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout()),
                CURLOPT_USERAGENT      => 'PHP-SOAP/HKS-Client/1.0',
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_HTTPHEADER     => ['Accept: text/xml, application/xml'],
            ]);
            $content  = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($content !== false && $httpCode === 200 && strlen((string)$content) > 100) {
                file_put_contents($cacheFile, $content);
                return $cacheFile;
            }

            $detail = $curlErr ?: "HTTP {$httpCode}";
            throw new \RuntimeException("WSDL indirilemedi ({$detail}). Sunucu bu URL'ye ulaşamıyor olabilir: {$url}");
        }

        // cURL yoksa SoapClient'in kendi stream'ini kullan (allow_url_fopen açıksa çalışır)
        return $url;
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
            $msg      = 'SOAP hatası: ' . $e->faultstring;
            $duration = (int)((microtime(true) - $start) * 1000);
            $this->logService('GenelService', 'testConnection', $env,
                ['wsdl' => $wsdl], null, false, $e->faultcode, $e->faultstring, $duration);
            $this->repo->updateTestResult(false, $msg);
            return ['ok' => false, 'message' => $msg, 'duration_ms' => $duration];

        } catch (Throwable $e) {
            $msg      = 'Bağlantı hatası: ' . $e->getMessage();
            $duration = (int)((microtime(true) - $start) * 1000);
            $this->logService('GenelService', 'testConnection', $env,
                ['wsdl' => $wsdl], null, false, null, $e->getMessage(), $duration);
            $this->repo->updateTestResult(false, $msg);
            return ['ok' => false, 'message' => $msg, 'duration_ms' => $duration];
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
