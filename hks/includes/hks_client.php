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
        return [
            'connection_timeout' => $this->timeout(),
            'cache_wsdl'         => WSDL_CACHE_NONE,
            'exceptions'         => true,
            'trace'              => true,
            'encoding'           => 'UTF-8',
            'soap_version'       => SOAP_1_1,
        ];
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
            $client = new SoapClient($wsdl, $this->soapOptions());
            $duration = (int)((microtime(true) - $start) * 1000);

            $this->logService('GenelService', 'testConnection', $env,
                ['wsdl' => $wsdl], ['status' => 'wsdl_loaded'],
                true, null, null, $duration);

            $this->repo->updateTestResult(true, 'WSDL başarıyla yüklendi. ' . $wsdl);
            return ['ok' => true, 'message' => 'WSDL başarıyla yüklendi.', 'duration_ms' => $duration];

        } catch (SoapFault $e) {
            $msg = 'SOAP hatası: ' . $e->faultstring;
            $duration = (int)((microtime(true) - $start) * 1000);
            $this->logService('GenelService', 'testConnection', $env,
                ['wsdl' => $wsdl], null, false, $e->faultcode, $e->faultstring, $duration);
            $this->repo->updateTestResult(false, $msg);
            return ['ok' => false, 'message' => $msg, 'duration_ms' => $duration];

        } catch (Throwable $e) {
            $msg = 'Bağlantı hatası: ' . $e->getMessage();
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

    // ── Bildirim Servisleri ─────────────────────────────────
    // HKS kılavuzu method adları doğrulanmadan canlı çağrı YOK.

    public function saveBildirim(array $payload): array {
        return [
            'ok'      => false,
            'message' => 'HKS Bildirim Kaydet servisi henüz eşlenmedi. '
                       . 'Kılavuzdan method adı doğrulandıktan sonra aktif edilecek.',
            'pending' => true,
        ];
    }

    public function queryBildirim(array $params): array {
        return $this->notMapped('BildirimSorgu');
    }

    public function queryKunye(string $kunye_no): array {
        return $this->notMapped('KunyeSorgu');
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
            $client  = new SoapClient($this->genelWsdl(), $this->soapOptions());
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
