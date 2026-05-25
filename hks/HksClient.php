<?php
// hks/HksClient.php — HKS SOAP istemcisi
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/HksRepository.php';

class HksClient {

    private ?array $settings = null;

    public function __construct(private readonly HksRepository $repo) {
        $this->settings = $repo->getSettings();
    }

    public function hasSettings(): bool {
        return $this->settings !== null && $this->settings['username'] !== '';
    }

    // ── Bağlantı testi ───────────────────────────────────────
    public function testConnection(): array {
        if (!$this->hasSettings()) {
            return ['ok' => false, 'message' => 'HKS ayarları yapılandırılmamış.'];
        }
        $wsdl = $this->settings['genel_wsdl_url'];
        if (!$wsdl) {
            return ['ok' => false, 'message' => 'GenelService WSDL URL boş.'];
        }
        try {
            $opts = [
                'connection_timeout' => 10,
                'cache_wsdl'         => WSDL_CACHE_NONE,
                'exceptions'         => true,
                'trace'              => true,
            ];
            new SoapClient($wsdl, $opts);
            $this->repo->addLog(null, 'test_connection', 'Bağlantı testi başarılı: ' . $wsdl);
            return ['ok' => true, 'message' => 'WSDL başarıyla yüklendi.'];
        } catch (SoapFault $e) {
            $msg = 'SOAP hatası: ' . $e->faultstring;
            $this->repo->addLog(null, 'test_connection_fail', $msg);
            return ['ok' => false, 'message' => $msg];
        } catch (Throwable $e) {
            $msg = 'Bağlantı hatası: ' . $e->getMessage();
            $this->repo->addLog(null, 'test_connection_fail', $msg);
            return ['ok' => false, 'message' => $msg];
        }
    }

    // ── Bildirim gönder ──────────────────────────────────────
    public function sendNotification(int $notificationId): array {
        $notification = $this->repo->getNotification($notificationId);
        if (!$notification) {
            return ['ok' => false, 'message' => 'Bildirim bulunamadı.'];
        }
        if (!$this->hasSettings()) {
            return ['ok' => false, 'message' => 'HKS ayarları yapılandırılmamış.'];
        }

        // TODO: Gerçek HKS method adları ve parametreleri belirlendiğinde burası tamamlanacak.
        // Şimdilik yapı hazır, method eşleştirilmemiş.
        $requestXml = $this->buildRequestXml($notification);
        $logMsg = 'HKS gönderim metodu henüz eşleştirilmedi. Bildirim ID: ' . $notificationId;
        $this->repo->addLog($notificationId, 'send_pending', $logMsg, $requestXml);
        $this->repo->updateStatus($notificationId, 'error', [
            'last_error'   => $logMsg,
            'request_xml'  => $requestXml,
            'response_xml' => null,
        ]);

        return ['ok' => false, 'message' => $logMsg];

        /* ── Gerçek entegrasyon taslağı (aktif etmek için TODO'yu kaldır):
        try {
            $client = new SoapClient($this->settings['bildirim_wsdl_url'], [
                'connection_timeout' => 30,
                'cache_wsdl'         => WSDL_CACHE_NONE,
                'exceptions'         => true,
                'trace'              => true,
            ]);
            $username  = $this->settings['username'];
            $password  = hks_decrypt($this->settings['password_enc']);
            $svcPass   = hks_decrypt($this->settings['service_password_enc']);

            // TODO: Gerçek HKS method adını buraya yaz
            $params = [
                // TODO: HKS servisinin beklediği parametre yapısı
            ];
            $result = $client->__soapCall('TODO_METHOD_NAME', [$params]);
            $responseXml = $client->__getLastResponse();

            $notificationNo = $result->TODO_NOTIFICATION_NO ?? null;
            $tagNo          = $result->TODO_TAG_NO ?? null;

            $this->repo->updateStatus($notificationId, 'sent', [
                'hks_notification_no' => $notificationNo,
                'hks_tag_no'          => $tagNo,
                'request_xml'         => $requestXml,
                'response_xml'        => $responseXml,
                'sent_at'             => date('Y-m-d H:i:s'),
                'last_error'          => null,
            ]);
            $this->repo->addLog($notificationId, 'send_success',
                'Gönderildi. Bildirim No: ' . $notificationNo, $requestXml, $responseXml);
            return ['ok' => true, 'notification_no' => $notificationNo, 'tag_no' => $tagNo];

        } catch (SoapFault $e) {
            $err = $e->faultstring;
            $this->repo->updateStatus($notificationId, 'error', ['last_error' => $err]);
            $this->repo->addLog($notificationId, 'send_error', $err);
            return ['ok' => false, 'message' => 'Gönderim hatası. Lütfen log kaydını inceleyin.'];
        }
        */
    }

    private function buildRequestXml(array $n): string {
        $username = hks_h($this->settings['username'] ?? '');
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!-- HKS Bildirim İsteği — ID: {$n['id']} -->
<HksBildirim>
  <KullaniciAdi>{$username}</KullaniciAdi>
  <BildirimTipi>{$n['notification_type']}</BildirimTipi>
  <UrunAdi>{$n['product_name']}</UrunAdi>
  <UrunKodu>{$n['product_code']}</UrunKodu>
  <Miktar>{$n['quantity']}</Miktar>
  <Birim>{$n['unit']}</Birim>
  <AmbalajTipi>{$n['package_type']}</AmbalajTipi>
  <Tedarikci>{$n['supplier_name']}</Tedarikci>
  <Alici>{$n['buyer_name']}</Alici>
  <SevkTarihi>{$n['shipment_date']}</SevkTarihi>
  <AracPlaka>{$n['vehicle_plate']}</AracPlaka>
  <SoforAdi>{$n['driver_name']}</SoforAdi>
  <CikisYeri>{$n['origin_place']}</CikisYeri>
  <VarisYeri>{$n['destination_place']}</VarisYeri>
  <Not>{$n['note']}</Not>
</HksBildirim>
XML;
    }
}
