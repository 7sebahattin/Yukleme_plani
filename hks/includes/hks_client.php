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

    // UrunService — ayrı bir WSDL alanı yok; genel WSDL override'ı varsa ondan türet,
    // yoksa ortam sabitini kullan.
    private function urunWsdl(): string {
        $g = trim((string)($this->settings['genel_wsdl_url'] ?? ''));
        if ($g !== '' && !$this->isStaleWsdl($g) && stripos($g, 'GenelService.svc') !== false) {
            return str_ireplace('GenelService.svc', 'UrunService.svc', $g);
        }
        return $this->getEnvironment() === 'live' ? HKS_WSDL_LIVE_URUN : HKS_WSDL_TEST_URUN;
    }

    // Eskiden config'de olan; DNS'te çözülmeyen, erişilemeyen IP'li veya yanlış
    // yollu adresleri reddet — DB'ye kaydedilmiş olsa bile canlı domain varsayılanına düşülür.
    //   • hkstest.hal.gov.tr → DNS'te yok
    //   • 95.0.51.130        → bu sunucudan TCP:443 timeout (erişilemiyor)
    //   • /HKSService/       → yanlış servis yolu (doğrusu /WebServices/)
    private function isStaleWsdl(string $url): bool {
        return stripos($url, 'hkstest.hal.gov.tr') !== false
            || stripos($url, '95.0.51.130') !== false
            || stripos($url, '/HKSService/') !== false;
    }

    private function timeout(): int {
        return max(5, (int)($this->settings['timeout_seconds'] ?? HKS_DEFAULT_TIMEOUT));
    }

    private function soapOptions(): array {
        ini_set('soap.wsdl_cache_enabled', '0');
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
            'features'           => SOAP_SINGLE_ELEMENT_ARRAYS,
        ];
    }

    /**
     * WSDL URL'sini indirip geçici dosyaya yazar, local path döner.
     * İndirilen WSDL tekrarlayan XSD tanımlarından arındırılır (_dedup önbelleği).
     * Sırayla cURL → file_get_contents (allow_url_fopen) → eski önbellek denenir.
     * Hiçbiri olmazsa, hangi yöntemin neden başarısız olduğunu açıklayan hata fırlatır.
     */
    private function loadWsdl(string $url): string {
        // Zaten local dosya yolu ise dokunma
        if (!preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $cacheRaw   = sys_get_temp_dir() . '/hks_wsdl_' . md5($url) . '.xml';
        $cacheClean = sys_get_temp_dir() . '/hks_wsdl_' . md5($url) . '_dedup.xml';
        $errors     = [];

        // 1) cURL — allow_url_fopen=Off ortamlarında tek seçenek
        if (function_exists('curl_init')) {
            [$content, $err] = $this->httpGetCurl($url);
            if ($content !== null) {
                @file_put_contents($cacheRaw, $content);
                $cleaned = $this->deduplicateWsdl($content);
                @file_put_contents($cacheClean, $cleaned);
                return $cacheClean;
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
                @file_put_contents($cacheRaw, $content);
                $cleaned = $this->deduplicateWsdl($content);
                @file_put_contents($cacheClean, $cleaned);
                return $cacheClean;
            }
            $errors[] = 'file_get_contents: yanıt alınamadı';
        } else {
            $errors[] = 'file_get_contents: allow_url_fopen=Off';
        }

        // 3) Temizlenmiş önbellek varsa kullan
        if (is_file($cacheClean) && (int)@filesize($cacheClean) > 100) {
            return $cacheClean;
        }

        // 4) Ham önbellek varsa — temizleyip yeni _dedup dosyasını oluştur
        if (is_file($cacheRaw) && (int)@filesize($cacheRaw) > 100) {
            $raw = @file_get_contents($cacheRaw);
            if ($raw !== false && strlen($raw) > 100) {
                $cleaned = $this->deduplicateWsdl($raw);
                @file_put_contents($cacheClean, $cleaned);
                return $cacheClean;
            }
        }

        throw new \RuntimeException(
            "WSDL indirilemedi. Sunucu '{$url}' adresine ulaşamıyor. Denenen yöntemler → "
            . implode(' | ', $errors)
        );
    }

    /**
     * WSDL XML içindeki tekrarlayan XSD import ve element/type tanımlarını kaldırır.
     * PHP SoapClient "Parsing Schema: element already defined" hatasını önler.
     * WCF tabanlı servisler genellikle her port için aynı şemayı içe aktarır — bu metod
     * tekrarlayan import'ları ve inline çift tanımları temizler.
     */
    private function deduplicateWsdl(string $xml): string {
        if (!class_exists('DOMDocument')) {
            return $xml;
        }
        $dom  = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok   = $dom->loadXML($xml, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            return $xml;
        }

        $remove = [];

        // — Adım 1: Tekrarlayan <xs:import> / <xsd:import> bildirimleri
        // Aynı namespace+schemaLocation çiftini ikinci kez yükleyen import'ları kaldır.
        $seenImports = [];
        $importList  = $dom->getElementsByTagName('import');
        $imports     = [];
        for ($i = 0; $i < $importList->length; $i++) {
            $imports[] = $importList->item($i);
        }
        foreach ($imports as $node) {
            if (!($node instanceof \DOMElement)) {
                continue;
            }
            $ns  = $node->getAttribute('namespace')      ?: '';
            $loc = $node->getAttribute('schemaLocation') ?: '';
            $key = $ns . '|||' . $loc;
            if ($key === '|||') {
                continue;
            }
            if (isset($seenImports[$key])) {
                $remove[] = $node;
            } else {
                $seenImports[$key] = true;
            }
        }

        // — Adım 2: Tekrarlayan top-level XSD tanımlamaları (element, complexType, vb.)
        // <schema> doğrudan çocuğu olan, aynı targetNamespace+kind+name sahip ikinci tanımı kaldır.
        $seenDefs = [];
        $topKinds = ['element', 'complexType', 'simpleType', 'group', 'attributeGroup'];
        foreach ($topKinds as $kind) {
            $nodeList = $dom->getElementsByTagName($kind);
            $nodes    = [];
            for ($i = 0; $i < $nodeList->length; $i++) {
                $nodes[] = $nodeList->item($i);
            }
            foreach ($nodes as $node) {
                if (!($node instanceof \DOMElement)) {
                    continue;
                }
                $parent = $node->parentNode;
                if (!($parent instanceof \DOMElement) || $parent->localName !== 'schema') {
                    continue;
                }
                $name = $node->getAttribute('name');
                if ($name === '') {
                    continue;
                }
                $tns = $parent->getAttribute('targetNamespace') ?: '';
                $key = $tns . '|||' . $kind . '|||' . $name;
                if (isset($seenDefs[$key])) {
                    $remove[] = $node;
                } else {
                    $seenDefs[$key] = true;
                }
            }
        }

        foreach ($remove as $node) {
            if ($node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }

        $result = $dom->saveXML();
        return ($result !== false && strlen($result) > 100) ? $result : $xml;
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

    /**
     * SoapClient oluşturma — ?singleWsdl → ?wsdl → bare URL sırasıyla dener.
     * WCF servislerinde ?singleWsdl genellikle "already defined" parse hatasını önler.
     * Her adımda WSDL indirilip dedup edilir; ilk başarılı SoapClient döndürülür.
     */
    private function createSoapClientFromUrl(string $url): SoapClient {
        $base       = preg_replace('/\?.*$/i', '', $url);
        $candidates = [
            $base . '?singleWsdl',
            $base . '?wsdl',
            $base,
        ];

        $lastError = null;
        foreach ($candidates as $candidate) {
            try {
                $path   = $this->loadWsdl($candidate);
                $client = new SoapClient($path, $this->soapOptions());
                return $client;
            } catch (SoapFault $e) {
                $lastError = $e;
                // Sonraki adayı yalnızca schema parse hataları için dene
                if (stripos($e->faultstring, 'Parsing Schema') === false
                    && stripos($e->faultstring, 'already defined') === false
                    && stripos($e->faultstring, "element '") === false) {
                    break;
                }
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }

        if ($lastError instanceof SoapFault) {
            throw $lastError;
        }
        throw new SoapFault('Client', $lastError ? $lastError->getMessage() : 'WSDL yüklenemedi.');
    }

    /**
     * SoapClient ile WSDL parse edilemediğinde ham cURL ile SOAP POST çağrısı yapar.
     * WCF SOAPAction formatı: {ns}/I{ServiceName}/{OperationName}
     * İstek zarfı: UserName / Password / ServicePassword / Istek (kılavuz formatı)
     */
    private function manualSoapCall(string $serviceUrl, string $operation): array {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => 'Manuel SOAP: cURL extension gerekli'];
        }
        $ns       = 'http://www.gtb.gov.tr//WebServices';
        $endpoint = preg_replace('/\?.*$/i', '', $serviceUrl);

        $svcBasename = basename(parse_url($endpoint, PHP_URL_PATH) ?? '');
        $svcName     = preg_replace('/\.svc$/i', '', $svcBasename) ?: 'GenelService';
        $soapAction  = $ns . '/I' . $svcName . '/' . $operation;

        $creds = $this->credentials();
        $esc   = static fn(string $v): string =>
            htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $inner = "<tns:UserName>{$esc($creds['username'])}</tns:UserName>"
               . "<tns:Password>{$esc($creds['password'])}</tns:Password>"
               . "<tns:ServicePassword>{$esc($creds['service_password'])}</tns:ServicePassword>"
               . '<tns:Istek/>';

        $envelope = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n"
            . "<soap:Envelope xmlns:soap=\"http://schemas.xmlsoap.org/soap/envelope/\" xmlns:tns=\"{$ns}\">\n"
            . "  <soap:Body>\n"
            . "    <tns:{$operation}>\n"
            . "      <tns:servisIstek>{$inner}</tns:servisIstek>\n"
            . "    </tns:{$operation}>\n"
            . "  </soap:Body>\n"
            . "</soap:Envelope>";

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $envelope,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: "' . $soapAction . '"',
                'Accept: text/xml, application/xml',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT        => $this->timeout(),
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout()),
            CURLOPT_USERAGENT      => 'PHP-SOAP/HKS-Client/1.0',
        ]);

        $t0       = microtime(true);
        $response = curl_exec($ch);
        $ms       = (int)round((microtime(true) - $t0) * 1000);
        $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false || $code === 0) {
            return ['ok' => false, 'message' => 'cURL hatası: ' . $curlErr, 'duration_ms' => $ms];
        }

        $resp      = (string)$response;
        $islemKodu = '';
        if (preg_match('/<(?:\w+:)?IslemKodu[^>]*>([^<]+)<\/(?:\w+:)?IslemKodu>/i', $resp, $m)) {
            $islemKodu = trim($m[1]);
        }
        $hataMesaji = '';
        if (preg_match('/<(?:\w+:)?(?:HataKodlari|HataMesaji|faultstring)[^>]*>([^<]+)<\//i', $resp, $m)) {
            $hataMesaji = trim($m[1]);
        }

        if ($islemKodu === 'GTBWSRV0000001') {
            return [
                'ok'          => true,
                'message'     => 'Manuel SOAP başarılı (IslemKodu: ' . $islemKodu . ')',
                'duration_ms' => $ms,
                'islem_kodu'  => $islemKodu,
                'method'      => 'manual_soap',
            ];
        }
        if ($islemKodu !== '' || $hataMesaji !== '') {
            return [
                'ok'          => false,
                'message'     => 'HKS Hata: ' . ($hataMesaji ?: $islemKodu),
                'duration_ms' => $ms,
                'islem_kodu'  => $islemKodu,
                'method'      => 'manual_soap',
            ];
        }
        if (stripos($resp, 'faultstring') !== false) {
            return [
                'ok'          => false,
                'message'     => 'SOAP Fault — HTTP ' . $code,
                'duration_ms' => $ms,
                'method'      => 'manual_soap',
            ];
        }
        return [
            'ok'          => $code === 200,
            'message'     => 'Manuel SOAP: HTTP ' . $code . ', ' . strlen($resp) . ' bayt',
            'duration_ms' => $ms,
            'method'      => 'manual_soap',
        ];
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

        // — Adım 1: SoapClient ile dene (?singleWsdl → ?wsdl → bare)
        try {
            $client   = $this->createSoapClientFromUrl($wsdl);
            $duration = (int)((microtime(true) - $start) * 1000);

            $this->logService('GenelService', 'testConnection', $env,
                ['wsdl' => $wsdl], ['status' => 'wsdl_loaded'],
                true, null, null, $duration);

            $this->repo->updateTestResult(true, 'HKS servis tanımı başarıyla yüklendi. ' . $wsdl);
            return [
                'ok'          => true,
                'message'     => 'HKS servis tanımı başarıyla yüklendi.',
                'duration_ms' => $duration,
                'method'      => 'wsdl',
            ];

        } catch (SoapFault $e) {
            $isParseError = stripos($e->faultstring, 'Parsing Schema') !== false
                         || stripos($e->faultstring, 'already defined') !== false;

            // — Adım 2: Schema parse hatası → manuel SOAP fallback
            if ($isParseError && function_exists('curl_init')) {
                $fallback = $this->manualSoapCall($wsdl, 'GenelServisIller');
                if (!$fallback['ok']) {
                    $fallback = $this->manualSoapCall($wsdl, 'GenelServisUlkeler');
                }
                $duration = (int)((microtime(true) - $start) * 1000);
                if ($fallback['ok']) {
                    $msg = 'HKS servis tanımı PHP tarafından okunamadı; ancak servis '
                         . 'manuel SOAP ile başarıyla yanıt verdi. ('
                         . ($fallback['message'] ?? '') . ')';
                    $this->logService('GenelService', 'testConnection', $env,
                        ['wsdl' => $wsdl], ['status' => 'manual_soap_ok'], true, null, null, $duration);
                    $this->repo->updateTestResult(true, $msg);
                    return ['ok' => true, 'message' => $msg, 'duration_ms' => $duration, 'method' => 'manual_soap'];
                }
                $diag = $this->diagnostics();
                $msg  = 'HKS servis tanımı PHP tarafından okunamadı. Sistem alternatif bağlantı '
                      . 'yöntemiyle denedi ancak sonuç alınamadı. ('
                      . ($fallback['message'] ?? 'bilinmeyen hata') . ')'
                      . ' — [' . $this->diagSummary($diag) . ']';
                $this->logService('GenelService', 'testConnection', $env,
                    ['wsdl' => $wsdl], null, false, 'parse_error', $e->faultstring, $duration);
                $this->repo->updateTestResult(false, $msg);
                return ['ok' => false, 'message' => $msg, 'duration_ms' => $duration, 'diag' => $diag];
            }

            $duration = (int)((microtime(true) - $start) * 1000);
            $diag     = $this->diagnostics();
            $msg      = 'HKS servis hatası: ' . $e->faultstring
                      . ' — [' . $this->diagSummary($diag) . ']';
            $this->logService('GenelService', 'testConnection', $env,
                ['wsdl' => $wsdl], null, false, $e->faultcode, $e->faultstring, $duration);
            $this->repo->updateTestResult(false, $msg);
            return ['ok' => false, 'message' => $msg, 'duration_ms' => $duration, 'diag' => $diag];

        } catch (Throwable $e) {
            $duration = (int)((microtime(true) - $start) * 1000);
            $diag     = $this->diagnostics();
            $msg      = 'Bağlantı hatası: ' . $e->getMessage()
                      . ' — [' . $this->diagSummary($diag) . ']';
            $this->logService('GenelService', 'testConnection', $env,
                ['wsdl' => $wsdl], null, false, null, $e->getMessage(), $duration);
            $this->repo->updateTestResult(false, $msg);
            return ['ok' => false, 'message' => $msg, 'duration_ms' => $duration, 'diag' => $diag];
        }
    }

    // ── Referans Servisleri ──────────────────────────────────
    // Metod adları ve servis dağılımı HKS Geliştirici Kılavuzu'na (sürüm 0.1.7) göredir.
    //   GenelService    → /GenelService.svc     (ülke, il, ilçe, depo, şube)
    //   UrunService     → /UrunService.svc       (ürün, birim, cins, malın niteliği, üretim şekli)
    //   BildirimService → /BildirimService.svc   (bildirim türü, sıfat, referans künye, sorgu)
    // İstek nesneleri liste servislerinde boştur ("create edilip gönderilmesi yeterlidir").

    public function getUlkeler(): array {
        return $this->callService($this->genelWsdl(), 'GenelService', 'GenelServisUlkeler');
    }

    public function getIller(): array {
        return $this->callService($this->genelWsdl(), 'GenelService', 'GenelServisIller');
    }

    public function getIlceler(string $il_kodu): array {
        // İl koduna göre ilçeler — İstek nesnesi içine il bilgisi konur (alan adı kılavuzdan doğrulanmalı)
        return $this->callService($this->genelWsdl(), 'GenelService', 'GenelServisIlceler', ['IlId' => $il_kodu]);
    }

    public function getDepolar(): array {
        return $this->callService($this->genelWsdl(), 'GenelService', 'GenelServisDepolar');
    }

    public function getSubeler(): array {
        return $this->callService($this->genelWsdl(), 'GenelService', 'GenelServisSubeler');
    }

    public function getBeldeler(string $ilce_id = ''): array {
        $istek = $ilce_id !== '' ? ['IlceId' => $ilce_id] : [];
        return $this->callService($this->genelWsdl(), 'GenelService', 'GenelServisBeldeler', $istek);
    }

    public function getHalIciIsyerleri(string $tc_vergi_no = ''): array {
        $istek = $tc_vergi_no !== '' ? ['TcKimlikVergiNo' => $tc_vergi_no] : [];
        return $this->callService($this->genelWsdl(), 'GenelService', 'GenelServisHalIciIsyeri', $istek);
    }

    public function getIsletmeTurleri(): array {
        return $this->callService($this->genelWsdl(), 'GenelService', 'GenelServisIsletmeTurleri');
    }

    public function getUrunler(): array {
        return $this->callService($this->urunWsdl(), 'UrunService', 'UrunServiceUrunler');
    }

    public function getUrunBirimleri(): array {
        return $this->callService($this->urunWsdl(), 'UrunService', 'UrunServiceUrunBirimleri');
    }

    public function getUrunCinsleri(): array {
        return $this->callService($this->urunWsdl(), 'UrunService', 'UrunServiceUrunCinsleri');
    }

    public function getMalinNiteligi(): array {
        return $this->callService($this->urunWsdl(), 'UrunService', 'UrunServiceMalinNiteligi');
    }

    public function getUretimSekli(): array {
        return $this->callService($this->urunWsdl(), 'UrunService', 'UrunServiceUretimSekilleri');
    }

    public function getBildirimTurleri(): array {
        return $this->callService($this->bildirimWsdl(), 'BildirimService', 'BildirimServisBildirimTurleri');
    }

    public function getSifatlar(): array {
        return $this->callService($this->bildirimWsdl(), 'BildirimService', 'BildirimServisSifatListesi');
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

        switch ($service) {
            case 'bildirim': $wsdl = $this->bildirimWsdl(); $svcLabel = 'BildirimService'; break;
            case 'urun':     $wsdl = $this->urunWsdl();     $svcLabel = 'UrunService';     break;
            default:         $wsdl = $this->genelWsdl();    $svcLabel = 'GenelService';     break;
        }

        try {
            $client    = $this->createSoapClientFromUrl($wsdl);
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
            $duration     = (int)((microtime(true) - $start) * 1000);
            $isParseError = stripos($e->faultstring, 'Parsing Schema') !== false
                         || stripos($e->faultstring, 'already defined') !== false;
            $friendlyMsg  = $isParseError
                ? 'HKS servis tanımı PHP tarafından okunamadı. Manuel SOAP fallback kullanılacak.'
                : 'HKS servis hatası: ' . $e->faultstring;
            $this->logService($svcLabel, 'inspectWsdl', $env,
                ['wsdl' => $wsdl], null, false, $e->faultcode, $e->faultstring, $duration);
            return [
                'ok'          => false,
                'message'     => $friendlyMsg,
                'technical'   => $e->faultstring,
                'methods'     => [],
                'duration_ms' => $duration,
            ];

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

        // Kılavuz metodu: BildirimServisBildirimSorgu. Farklı WSDL sürümleri için yedekler bırakıldı.
        $candidates = ['BildirimServisBildirimSorgu', 'GetBildirimDetay', 'BildirimSorgu', 'GetBildirim'];
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

        $requestParams = $this->buildRequest($params);

        try {
            $client   = $this->createSoapClientFromUrl($this->bildirimWsdl());
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

        // Künye/referans künye sorgusu kılavuzda BildirimService altındadır (ReferansKunyeler).
        $candidates = ['BildirimServisReferansKunyeler', 'GetKunyeDetay', 'KunyeSorgu', 'GetKunye'];
        $method = $this->discoverMethod($this->bildirimWsdl(), $candidates);

        if ($method === null) {
            $inspect = $this->inspectWsdl('bildirim');
            return [
                'ok'               => false,
                'message'          => 'Künye sorgu methodu bulunamadı. WSDL\'i inceleyin.',
                'methods_available' => $inspect['methods'] ?? [],
                'hint'             => 'Aşağıdaki methodlardan uygun olanı hks_client.php\'deki $candidates dizisine ekleyin.',
            ];
        }

        $requestParams = $this->buildRequest(['KunyeNo' => $kunye_no]);

        try {
            $client   = $this->createSoapClientFromUrl($this->bildirimWsdl());
            $result   = $client->__soapCall($method, [$requestParams]);
            $duration = (int)((microtime(true) - $start) * 1000);
            $data     = $this->extractResultArray($result, $method);

            $this->logService('BildirimService', $method, $env,
                $requestParams, ['data' => $data], true, null, null, $duration);

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

    // ── İç Yardımcılar ──────────────────────────────────────

    // HKS istek zarfı — her request UserName/Password/ServicePassword + Istek alanı taşır.
    // (Kılavuz: BaseRequestMessageOf_XxxIstek). Eski KullaniciAdi/Sifre/ServisSifre adları YANLIŞTI.
    private function buildRequest(array $istek = []): array {
        $creds = $this->credentials();
        return [
            'UserName'        => $creds['username'],
            'Password'        => $creds['password'],
            'ServicePassword' => $creds['service_password'],
            'Istek'           => (object)$istek,
        ];
    }

    // Verilen servise tek bir liste/sorgu metodu çağırır.
    private function callService(string $wsdl, string $serviceLabel, string $method, array $istek = []): array {
        $start = microtime(true);
        $env   = $this->getEnvironment();

        if (!hks_check_soap()) {
            return ['ok' => false, 'message' => 'PHP SOAP extension aktif değil.', 'data' => []];
        }
        if (!$this->hasSettings()) {
            return ['ok' => false, 'message' => 'HKS ayarları yapılandırılmamış.', 'data' => []];
        }

        $requestParams = $this->buildRequest($istek);

        try {
            $client   = $this->createSoapClientFromUrl($wsdl);
            $result   = $client->__soapCall($method, [$requestParams]);
            $duration = (int)((microtime(true) - $start) * 1000);

            $data = $this->extractResultArray($result, $method);
            $this->logService($serviceLabel, $method, $env,
                $requestParams, ['count' => count($data)], true, null, null, $duration);

            return ['ok' => true, 'data' => $data, 'duration_ms' => $duration];

        } catch (SoapFault $e) {
            $duration = (int)((microtime(true) - $start) * 1000);
            $this->logService($serviceLabel, $method, $env,
                $requestParams, null, false, $e->faultcode, $e->faultstring, $duration);
            return ['ok' => false, 'message' => 'SOAP hatası: ' . $e->faultstring, 'data' => []];

        } catch (Throwable $e) {
            $duration = (int)((microtime(true) - $start) * 1000);
            $this->logService($serviceLabel, $method, $env,
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
            $client    = $this->createSoapClientFromUrl($wsdl);
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
