<?php
// =========================================================
// config/edonusum_elogo.php — e-Logo (Logo Özel Entegratör) SOAP 1.1 Adaptörü
//
// Özellikler:
//   - Harici kütüphane / ext-soap bağımlılığı YOK (saf PHP cURL + DOMDocument + ZipArchive)
//   - Canlı: https://pb.elogo.com.tr/PostBoxService.svc
//   - Test : https://pb-demo.elogo.com.tr/PostBoxService.svc
//   - Kimlik şifresi: HKS_CRED_KEY / EDONUSUM_CRED_KEY ile AES-256-GCM şifreli (config/local.php)
//   - SSRF koruması: yalnız HTTPS + *.elogo.com.tr / *.logo.com.tr host beyaz listesi
//   - Desteklenen işlemler:
//       Login, Logout, GetValidateGIBUser, SendDocument (e-Fatura / e-Arşiv / e-İrsaliye / e-Müstahsil
//       ve Ticari Kabul/Red ApplicationResponse), GetDocumentStatus, GetDocumentList, GetDocumentData (UBL / PDF)
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

const ELOGO_URL_CANLI = 'https://pb.elogo.com.tr/PostBoxService.svc';
const ELOGO_URL_TEST  = 'https://pb-demo.elogo.com.tr/PostBoxService.svc';
const ELOGO_SOAP_NS   = 'http://tempuri.org/';
const ELOGO_EFAT_NS   = 'http://schemas.datacontract.org/2004/07/eFaturaWebService';
const ELOGO_ARR_NS    = 'http://schemas.microsoft.com/2003/10/Serialization/Arrays';
const ELOGO_AAD       = 'edonusum_elogo_v1';

// ── Anahtar & AES-256-GCM Şifreleme ────────────────────────────────────────

function edonusum_env(string $ad, string $varsayilan = ''): string
{
    if (defined($ad) && is_string(constant($ad)) && constant($ad) !== '') {
        return (string)constant($ad);
    }
    $v = getenv($ad);
    return ($v === false || $v === '') ? $varsayilan : (string)$v;
}

function edonusum_master_key_raw(): ?string
{
    if (!defined('EDONUSUM_CRED_KEY') && !defined('HKS_CRED_KEY') && is_file(__DIR__ . '/local.php')) {
        require_once __DIR__ . '/local.php';
    }
    foreach (['EDONUSUM_CRED_KEY', 'HKS_CRED_KEY'] as $ad) {
        $k = edonusum_env($ad);
        if (strlen($k) >= 16) {
            return hash_hmac('sha256', 'edonusum_elogo_cred_v1', $k, true);
        }
    }
    return null;
}

function edonusum_sifre_sifrele(string $duz): ?string
{
    if ($duz === '') return '';
    $key = edonusum_master_key_raw();
    if ($key === null || !function_exists('openssl_encrypt')) return null;
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($duz, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, ELOGO_AAD, 16);
    if ($ct === false || strlen($tag) !== 16) return null;
    return 'v1:' . base64_encode($iv . $tag . $ct);
}

function edonusum_sifre_coz(?string $sifreli): ?string
{
    if ($sifreli === null || $sifreli === '') return '';
    if (!str_starts_with($sifreli, 'v1:')) return null;
    $key = edonusum_master_key_raw();
    if ($key === null || !function_exists('openssl_decrypt')) return null;
    $ham = base64_decode(substr($sifreli, 3), true);
    if ($ham === false || strlen($ham) < 29) return null;
    $iv  = substr($ham, 0, 12);
    $tag = substr($ham, 12, 16);
    $ct  = substr($ham, 28);
    $duz = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, ELOGO_AAD);
    return $duz === false ? null : $duz;
}

// ── SSRF Korumalı URL Doğrulama ────────────────────────────────────────────

function edonusum_elogo_url_guvenli(string $url): ?string
{
    $url = trim($url);
    if ($url === '') return null;
    $p = parse_url($url);
    if (!is_array($p)) return null;
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    $host   = strtolower((string)($p['host'] ?? ''));
    if ($scheme !== 'https' || $host === '') return null;
    if (!preg_match('/^(?:[a-z0-9-]+\.)?(?:elogo|logo)\.com\.tr$/', $host)) {
        return null;
    }
    $path = (string)($p['path'] ?? '/PostBoxService.svc');
    if ($path === '' || $path === '/') $path = '/PostBoxService.svc';
    $port = isset($p['port']) ? (':' . (int)$p['port']) : '';
    return 'https://' . $host . $port . $path;
}

function edonusum_elogo_varsayilan_url(string $ortam): string
{
    return $ortam === 'canli' ? ELOGO_URL_CANLI : ELOGO_URL_TEST;
}

// ── ZIP & UBL Yardımcıları ─────────────────────────────────────────────────

/** @return array{ok:bool,zip?:string,hash?:string,hata?:string} */
function edonusum_zip_olustur(string $xmlIcerik, string $icDosyaAdi): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'hata' => 'Sunucuda PHP ZipArchive eklentisi (ext-zip) yüklü değil.'];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'asya_elogo_');
    if ($tmp === false) {
        return ['ok' => false, 'hata' => 'Geçici ZIP dosyası oluşturulamadı.'];
    }
    $zip = new ZipArchive();
    $res = $zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE);
    if ($res !== true) {
        @unlink($tmp);
        return ['ok' => false, 'hata' => 'ZIP arşivi açılamadı (kod: ' . $res . ').'];
    }
    $zip->addFromString($icDosyaAdi, $xmlIcerik);
    $zip->close();
    $bytes = @file_get_contents($tmp);
    @unlink($tmp);
    if (!is_string($bytes) || $bytes === '') {
        return ['ok' => false, 'hata' => 'ZIP içeriği okunamadı.'];
    }
    return ['ok' => true, 'zip' => $bytes, 'hash' => md5($bytes)];
}

/**
 * e-Logo'dan gelen ZIP (veya ham) verisini güvenli biçimde açar (zip-bomb sınırı: 10 MB).
 * @return array{ok:bool,icerik?:string,dosya_adi?:string,hata?:string}
 */
function edonusum_zip_ac(string $hamBytes, string $tercihUzanti = 'xml'): array
{
    if ($hamBytes === '') {
        return ['ok' => false, 'hata' => 'Boş belge verisi.'];
    }
    // Ham PDF veya ham XML olarak dönerse doğrudan ver
    if ($tercihUzanti === 'pdf' && str_starts_with($hamBytes, '%PDF-')) {
        return ['ok' => true, 'icerik' => $hamBytes, 'dosya_adi' => 'belge.pdf'];
    }
    if ($tercihUzanti === 'xml' && str_starts_with(ltrim($hamBytes), '<')) {
        return ['ok' => true, 'icerik' => $hamBytes, 'dosya_adi' => 'belge.xml'];
    }
    if (!str_starts_with($hamBytes, "PK\x03\x04")) {
        return ['ok' => false, 'hata' => 'Beklenen ZIP/belge biçimi alınamadı.'];
    }
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'hata' => 'PHP ZipArchive eklentisi yüklü değil.'];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'asya_elogo_in_');
    if ($tmp === false) return ['ok' => false, 'hata' => 'Geçici dosya oluşturulamadı.'];
    file_put_contents($tmp, $hamBytes);
    $zip = new ZipArchive();
    $opened = $zip->open($tmp);
    if ($opened !== true) {
        @unlink($tmp);
        return ['ok' => false, 'hata' => 'ZIP arşivi bozuk (kod: ' . $opened . ').'];
    }
    $secilenIdx = -1;
    $ilkIdx = -1;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat)) continue;
        $ad = (string)($stat['name'] ?? '');
        if ($ad === '' || str_ends_with($ad, '/')) continue;
        if ((int)($stat['size'] ?? 0) > 10 * 1024 * 1024) continue;
        if ($ilkIdx < 0) $ilkIdx = $i;
        if (str_ends_with(strtolower($ad), '.' . strtolower($tercihUzanti))) {
            $secilenIdx = $i;
            break;
        }
    }
    $idx = $secilenIdx >= 0 ? $secilenIdx : $ilkIdx;
    if ($idx < 0) {
        $zip->close();
        @unlink($tmp);
        return ['ok' => false, 'hata' => 'ZIP içinde uygun dosya bulunamadı.'];
    }
    $ad = (string)$zip->getNameIndex($idx);
    $icerik = $zip->getFromIndex($idx, 10 * 1024 * 1024);
    $zip->close();
    @unlink($tmp);
    if (!is_string($icerik) || $icerik === '') {
        return ['ok' => false, 'hata' => 'ZIP içeriği okunamadı.'];
    }
    return ['ok' => true, 'icerik' => $icerik, 'dosya_adi' => basename($ad)];
}

/**
 * Gelen UBL-TR 1.2 XML belgesinden (Invoice / DespatchAdvice) başlık ve tutar özetini XXE-kapalı olarak çıkarır.
 * @return array<string,mixed>
 */
function edonusum_ubl_ozet_coz(string $ublXml, string $yedekEttn = '', string $yedekTur = 'efatura'): array
{
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $loaded = @$dom->loadXML($ublXml, LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$loaded || !$dom->documentElement) {
        return [
            'ettn'           => $yedekEttn,
            'belge_turu'     => $yedekTur,
            'senaryo'        => 'TICARIFATURA',
            'belge_no'       => $yedekEttn !== '' ? substr($yedekEttn, 0, 16) : 'BELGE',
            'tarih'          => date('Y-m-d'),
            'gonderen_vkn'   => '',
            'gonderen_unvan' => 'Bilinmeyen Gönderen',
            'para_birimi'    => 'TRY',
            'ara_toplam'     => 0.0,
            'kdv_toplam'     => 0.0,
            'genel_toplam'   => 0.0,
            'ubl_xml'        => $ublXml,
        ];
    }

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
    $xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

    $val = static function (string $expr) use ($xp): string {
        $n = $xp->query($expr);
        if (!$n || $n->length === 0) return '';
        return trim((string)$n->item(0)?->textContent);
    };

    $rootName = (string)$dom->documentElement->localName;
    $belgeTuru = $rootName === 'DespatchAdvice' ? 'eirsaliye' : $yedekTur;
    $ettn = $val('/*[1]/cbc:UUID') ?: $yedekEttn;
    $belgeNo = $val('/*[1]/cbc:ID') ?: substr($ettn, 0, 16);
    $tarih = $val('/*[1]/cbc:IssueDate');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih)) $tarih = date('Y-m-d');
    $senaryo = $val('/*[1]/cbc:ProfileID') ?: ($belgeTuru === 'eirsaliye' ? 'TEMELIRSALIYE' : 'TICARIFATURA');
    $pb = strtoupper($val('/*[1]/cbc:DocumentCurrencyCode') ?: 'TRY');
    if (!preg_match('/^[A-Z]{3}$/', $pb)) $pb = 'TRY';

    $supplierPath = $belgeTuru === 'eirsaliye'
        ? '/*[1]/cac:DespatchSupplierParty/cac:Party'
        : '/*[1]/cac:AccountingSupplierParty/cac:Party';
    $vkn = preg_replace('/\D/', '', $val($supplierPath . "/cac:PartyIdentification/cbc:ID[@schemeID='VKN' or @schemeID='TCKN']") ?: $val($supplierPath . '/cac:PartyIdentification/cbc:ID')) ?? '';
    $unvan = $val($supplierPath . '/cac:PartyName/cbc:Name');
    if ($unvan === '') {
        $unvan = trim($val($supplierPath . '/cac:Person/cbc:FirstName') . ' ' . $val($supplierPath . '/cac:Person/cbc:FamilyName'));
    }
    if ($unvan === '') $unvan = 'Gönderen (' . ($vkn ?: 'VKN yok') . ')';

    $ara   = (float)str_replace(',', '.', $val('/*[1]/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount') ?: $val('/*[1]/cac:LegalMonetaryTotal/cbc:LineExtensionAmount') ?: '0');
    $kdv   = (float)str_replace(',', '.', $val('/*[1]/cac:TaxTotal/cbc:TaxAmount') ?: '0');
    $genel = (float)str_replace(',', '.', $val('/*[1]/cac:LegalMonetaryTotal/cbc:PayableAmount') ?: $val('/*[1]/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount') ?: '0');
    if ($genel <= 0.0 && ($ara > 0.0 || $kdv > 0.0)) $genel = round($ara + $kdv, 2);

    return [
        'ettn'           => $ettn,
        'belge_turu'     => $belgeTuru,
        'senaryo'        => $senaryo,
        'belge_no'       => mb_substr($belgeNo, 0, 40, 'UTF-8'),
        'tarih'          => $tarih,
        'gonderen_vkn'   => mb_substr($vkn, 0, 20, 'UTF-8'),
        'gonderen_unvan' => mb_substr($unvan, 0, 200, 'UTF-8'),
        'para_birimi'    => $pb,
        'ara_toplam'     => round($ara, 2),
        'kdv_toplam'     => round($kdv, 2),
        'genel_toplam'   => round($genel, 2),
        'ubl_xml'        => $ublXml,
    ];
}

/** Ticari e-Fatura Kabul/Red için standart UBL-TR 1.2 ApplicationResponse XML üretir. */
function edonusum_application_response_xml(string $hedefEttn, string $yanit, string $neden = '', array $firma = [], ?string $aliciVkn = null, ?string $aliciUnvan = null): string
{
    $e = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $appUuid = edonusum_uuid_v4();
    $appId   = 'UY' . date('YmdHis');
    $tarih   = date('Y-m-d');
    $saat    = date('H:i:s');
    $kod     = strtolower($yanit) === 'kabul' ? 'KABUL' : 'RED';
    $aciklama = trim($neden) !== '' ? trim($neden) : ($kod === 'KABUL' ? 'Fatura kabul edilmiştir.' : 'Fatura reddedilmiştir.');

    $gVkn    = preg_replace('/\D/', '', (string)($firma['firma_vkn'] ?? edonusum_env('EDONUSUM_FIRMA_VKN', '1111111111'))) ?: '1111111111';
    $gUnvan  = (string)($firma['firma_unvan'] ?? edonusum_env('EDONUSUM_FIRMA_UNVAN', 'ASYA FRESH TARIM GIDA İHRACAT LTD. ŞTİ.'));
    $aVkn    = preg_replace('/\D/', '', (string)($aliciVkn ?? '')) ?: '1111111111';
    $aUnvan  = trim((string)($aliciUnvan ?? '')) ?: 'KARŞI TARAF';
    $gScheme = strlen($gVkn) === 11 ? 'TCKN' : 'VKN';
    $aScheme = strlen($aVkn) === 11 ? 'TCKN' : 'VKN';

    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<ApplicationResponse xmlns="urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2"'
        . ' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
        . ' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">' . "\n"
        . '  <cbc:UBLVersionID>2.1</cbc:UBLVersionID>' . "\n"
        . '  <cbc:CustomizationID>TR1.2</cbc:CustomizationID>' . "\n"
        . '  <cbc:ProfileID>TICARIFATURA</cbc:ProfileID>' . "\n"
        . '  <cbc:ID>' . $e($appId) . '</cbc:ID>' . "\n"
        . '  <cbc:UUID>' . $e($appUuid) . '</cbc:UUID>' . "\n"
        . '  <cbc:IssueDate>' . $e($tarih) . '</cbc:IssueDate>' . "\n"
        . '  <cbc:IssueTime>' . $e($saat) . '</cbc:IssueTime>' . "\n"
        . '  <cac:SenderParty><cac:PartyIdentification><cbc:ID schemeID="' . $gScheme . '">' . $e($gVkn) . '</cbc:ID></cac:PartyIdentification>'
        . '<cac:PartyName><cbc:Name>' . $e($gUnvan) . '</cbc:Name></cac:PartyName></cac:SenderParty>' . "\n"
        . '  <cac:ReceiverParty><cac:PartyIdentification><cbc:ID schemeID="' . $aScheme . '">' . $e($aVkn) . '</cbc:ID></cac:PartyIdentification>'
        . '<cac:PartyName><cbc:Name>' . $e($aUnvan) . '</cbc:Name></cac:PartyName></cac:ReceiverParty>' . "\n"
        . '  <cac:DocumentResponse>'
        . '<cac:Response><cbc:ReferenceID>' . $e($appUuid) . '</cbc:ReferenceID><cbc:ResponseCode>' . $kod . '</cbc:ResponseCode><cbc:Description>' . $e($aciklama) . '</cbc:Description></cac:Response>'
        . '<cac:DocumentReference><cbc:ID>' . $e($hedefEttn) . '</cbc:ID><cbc:IssueDate>' . $e($tarih) . '</cbc:IssueDate><cbc:DocumentTypeCode>INVOICE</cbc:DocumentTypeCode></cac:DocumentReference>'
        . '<cac:LineResponse><cac:LineReference><cbc:LineID>1</cbc:LineID></cac:LineReference><cac:Response><cbc:ReferenceID>' . $e($appUuid) . '</cbc:ReferenceID><cbc:ResponseCode>' . $kod . '</cbc:ResponseCode><cbc:Description>' . $e($aciklama) . '</cbc:Description></cac:Response></cac:LineResponse>'
        . '</cac:DocumentResponse>' . "\n"
        . '</ApplicationResponse>' . "\n";
}

// ── e-Logo SOAP 1.1 Sağlayıcısı ────────────────────────────────────────────

final class EdonusumElogoProvider implements EdonusumProviderInterface
{
    private string $ortam;
    private string $servisUrl;
    private string $kullaniciAdi;
    private string $sifre;
    /** @var array<string,mixed> */
    private array $ayar;
    /** @var ?callable */
    private $transport;

    /**
     * @param array<string,mixed> $ayar
     * @param ?callable $transport Testler için isteğe bağlı sahte HTTP/SOAP katmanı
     */
    public function __construct(array $ayar, ?callable $transport = null)
    {
        $this->ayar = $ayar;
        $this->ortam = ((string)($ayar['ortam'] ?? 'test')) === 'canli' ? 'canli' : 'test';
        $hamUrl = trim((string)($ayar['servis_url'] ?? ''));
        $guvenli = $hamUrl !== '' ? edonusum_elogo_url_guvenli($hamUrl) : null;
        $this->servisUrl = $guvenli ?? edonusum_elogo_varsayilan_url($this->ortam);
        $this->kullaniciAdi = trim((string)($ayar['kullanici_adi'] ?? ''));
        $this->sifre = (string)($ayar['sifre'] ?? '');
        $this->transport = $transport;
    }

    public function ad(): string
    {
        return $this->ortam === 'canli' ? 'elogo_canli' : 'elogo_test';
    }

    public function servisUrl(): string
    {
        return $this->servisUrl;
    }

    private function xmlEsc(string $v): string
    {
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function docTypeMap(string $belgeTuru): string
    {
        return match ($belgeTuru) {
            'earsiv'     => 'EARCHIVE',
            'eirsaliye'  => 'DESPATCHADVICE',
            'emustahsil' => 'PRODUCERRECEIPT',
            default      => 'EINVOICE',
        };
    }

    /** @param list<string> $params */
    private function paramListXml(array $params): string
    {
        $s = '';
        foreach ($params as $p) {
            $s .= '<arr:string>' . $this->xmlEsc($p) . '</arr:string>';
        }
        return '<tem:paramList>' . $s . '</tem:paramList>';
    }

    /** @return array{ok:bool,dom?:DOMDocument,xp?:DOMXPath,hata?:string,kod?:int} */
    private function soapCall(string $metot, string $bodyInner): array
    {
        $guvenliUrl = edonusum_elogo_url_guvenli($this->servisUrl);
        if ($guvenliUrl === null) {
            return ['ok' => false, 'hata' => 'Geçersiz veya izin verilmeyen e-Logo servis adresi (yalnız HTTPS *.elogo.com.tr).'];
        }
        $envelope = '<?xml version="1.0" encoding="utf-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' xmlns:tem="' . ELOGO_SOAP_NS . '"'
            . ' xmlns:efat="' . ELOGO_EFAT_NS . '"'
            . ' xmlns:arr="' . ELOGO_ARR_NS . '">'
            . '<soapenv:Header/>'
            . '<soapenv:Body>' . $bodyInner . '</soapenv:Body>'
            . '</soapenv:Envelope>';
        $soapAction = ELOGO_SOAP_NS . 'IPostBoxService/' . $metot;

        if ($this->transport !== null) {
            $resp = ($this->transport)($guvenliUrl, $soapAction, $envelope, $metot);
        } else {
            $resp = $this->httpPost($guvenliUrl, $soapAction, $envelope);
        }

        if (empty($resp['ok'])) {
            return ['ok' => false, 'hata' => (string)($resp['hata'] ?? 'e-Logo web servisine bağlanılamadı.'), 'kod' => (int)($resp['kod'] ?? 0)];
        }

        $govde = (string)($resp['govde'] ?? '');
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = @$dom->loadXML($govde, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            return ['ok' => false, 'hata' => 'e-Logo servis yanıtı geçerli XML değil (HTTP ' . (int)($resp['kod'] ?? 0) . ').'];
        }

        $xp = new DOMXPath($dom);
        $fault = $this->ilkMetin($xp, "//*[local-name()='Fault']/*[local-name()='faultstring']")
              ?: $this->ilkMetin($xp, "//*[local-name()='Fault']//*[local-name()='Text']");
        if ($fault !== '') {
            return ['ok' => false, 'hata' => 'e-Logo SOAP Hatası: ' . $fault];
        }

        return ['ok' => true, 'dom' => $dom, 'xp' => $xp];
    }

    /** @return array{ok:bool,kod:int,govde:string,hata?:string} */
    private function httpPost(string $url, string $soapAction, string $envelope): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'kod' => 0, 'govde' => '', 'hata' => 'Sunucuda PHP cURL eklentisi yüklü değil.'];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'kod' => 0, 'govde' => '', 'hata' => 'cURL başlatılamadı.'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $envelope,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: "' . $soapAction . '"',
                'Content-Length: ' . strlen($envelope),
                'User-Agent: AsyaFreshERP-eLogo/1.0',
            ],
        ]);
        $govde = curl_exec($ch);
        $err   = curl_error($ch);
        $kod   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($govde === false || $err !== '') {
            return ['ok' => false, 'kod' => $kod, 'govde' => '', 'hata' => 'e-Logo bağlantı hatası: ' . ($err ?: 'Yanıt alınamadı')];
        }
        if ($kod >= 400 && !str_contains((string)$govde, 'Envelope')) {
            return ['ok' => false, 'kod' => $kod, 'govde' => (string)$govde, 'hata' => 'e-Logo HTTP ' . $kod . ' hatası döndü.'];
        }
        return ['ok' => true, 'kod' => $kod, 'govde' => (string)$govde];
    }

    private function ilkMetin(DOMXPath $xp, string $expr): string
    {
        $n = $xp->query($expr);
        if (!$n || $n->length === 0) return '';
        return trim((string)$n->item(0)?->textContent);
    }

    /** @return list<string> */
    private function tumMetinler(DOMXPath $xp, string $expr): array
    {
        $n = $xp->query($expr);
        if (!$n || $n->length === 0) return [];
        $out = [];
        foreach ($n as $el) {
            $t = trim((string)$el->textContent);
            if ($t !== '') $out[] = $t;
        }
        return $out;
    }

    /** @return array{ok:bool,session_id?:string,hata?:string} */
    private function login(): array
    {
        if ($this->kullaniciAdi === '' || $this->sifre === '') {
            return ['ok' => false, 'hata' => 'e-Logo Web Servis kullanıcı adı veya şifresi tanımlı değil. "Entegratör Ayarları" sekmesinden kaydedin.'];
        }
        $surum = defined('APP_SURUM') ? (string)APP_SURUM : 'v596';
        $body = '<tem:Login><tem:login>'
            . '<efat:appStr>AsyaFreshERP</efat:appStr>'
            . '<efat:passWord>' . $this->xmlEsc($this->sifre) . '</efat:passWord>'
            . '<efat:source>0</efat:source>'
            . '<efat:userName>' . $this->xmlEsc($this->kullaniciAdi) . '</efat:userName>'
            . '<efat:version>' . $this->xmlEsc($surum) . '</efat:version>'
            . '</tem:login></tem:Login>';
        $res = $this->soapCall('Login', $body);
        if (empty($res['ok'])) return ['ok' => false, 'hata' => (string)($res['hata'] ?? 'e-Logo girişi başarısız.')];

        $xp = $res['xp'];
        $okFlag = strtolower($this->ilkMetin($xp, "//*[local-name()='LoginResult']"));
        $sid    = $this->ilkMetin($xp, "//*[local-name()='sessionID']");
        if ($okFlag !== 'true' || $sid === '') {
            $msg = $this->ilkMetin($xp, "//*[local-name()='resultMsg']") ?: 'Kullanıcı adı veya Web Servis şifresi hatalı.';
            return ['ok' => false, 'hata' => 'e-Logo oturumu açılamadı: ' . $msg];
        }
        return ['ok' => true, 'session_id' => $sid];
    }

    private function logout(string $sessionId): void
    {
        if ($sessionId === '') return;
        $this->soapCall('Logout', '<tem:Logout><tem:sessionID>' . $this->xmlEsc($sessionId) . '</tem:sessionID></tem:Logout>');
    }

    /**
     * Tek oturum açar, geri çağırmayı çalıştırır ve her durumda Logout yapar.
     * @template T
     * @param callable(string):array<string,mixed> $fn
     * @return array<string,mixed>
     */
    private function oturumla(callable $fn): array
    {
        $lg = $this->login();
        if (empty($lg['ok'])) {
            return ['ok' => false, 'hata' => (string)($lg['hata'] ?? 'e-Logo girişi başarısız.')];
        }
        $sid = (string)$lg['session_id'];
        try {
            return $fn($sid);
        } finally {
            $this->logout($sid);
        }
    }

    /** @return array{ok:bool,mesaj?:string,hata?:string,ortam:string,servis_url:string} */
    public function baglantiTest(): array
    {
        $res = $this->oturumla(function (string $sid): array {
            return [
                'ok'    => true,
                'mesaj' => 'e-Logo Web Servis bağlantısı başarılı (' . ($this->ortam === 'canli' ? 'CANLI' : 'TEST/DEMO') . ' · oturum doğrulandı).',
            ];
        });
        $res['ortam'] = $this->ortam;
        $res['servis_url'] = $this->servisUrl;
        return $res;
    }

    /** @return array{ok:bool,kayitli:bool,posta_kutusu:?string,gb_etiket?:?string,unvan?:string,hata?:string} */
    public function mukellefSorgula(string $vknTckn, string $belgeTuru = 'efatura'): array
    {
        $v = preg_replace('/\D/', '', $vknTckn) ?? '';
        if (strlen($v) !== 10 && strlen($v) !== 11) {
            return ['ok' => false, 'kayitli' => false, 'posta_kutusu' => null, 'hata' => 'VKN 10, TCKN 11 hane olmalıdır.'];
        }
        $docType = $belgeTuru === 'eirsaliye' ? 'DESPATCHADVICE' : 'EINVOICE';

        $res = $this->oturumla(function (string $sid) use ($v, $docType): array {
            $body = '<tem:GetValidateGIBUser>'
                . '<tem:sessionID>' . $this->xmlEsc($sid) . '</tem:sessionID>'
                . $this->paramListXml(['VKN=' . $v, 'DOCUMENTTYPE=' . $docType])
                . '</tem:GetValidateGIBUser>';
            $r = $this->soapCall('GetValidateGIBUser', $body);
            if (empty($r['ok'])) {
                return ['ok' => false, 'kayitli' => false, 'posta_kutusu' => null, 'hata' => (string)($r['hata'] ?? 'Mükellef sorgulanamadı.')];
            }
            $xp = $r['xp'];
            $rc  = $this->ilkMetin($xp, "//*[local-name()='resultCode']");
            $msg = $this->ilkMetin($xp, "//*[local-name()='resultMsg']");
            if ($rc !== '' && $rc !== '1' && $rc !== '0') {
                return ['ok' => false, 'kayitli' => false, 'posta_kutusu' => null, 'hata' => 'e-Logo mükellef sorgu hatası (' . $rc . '): ' . $msg];
            }
            $satirlar = $this->tumMetinler($xp, "//*[local-name()='outputList']/*[local-name()='string']");
            $kv = [];
            foreach ($satirlar as $sat) {
                $pos = strpos($sat, '=');
                if ($pos !== false) {
                    $k = strtoupper(trim(substr($sat, 0, $pos)));
                    $val = trim(substr($sat, $pos + 1));
                    if (!isset($kv[$k]) || $kv[$k] === '') $kv[$k] = $val;
                }
            }
            $pk = $kv['PKALIAS'] ?? $kv['ALIAS'] ?? $kv['POSTBOXALIAS'] ?? null;
            $gb = $kv['GBALIAS'] ?? null;
            $unvan = $kv['TITLE'] ?? $kv['UNVAN'] ?? $kv['REGISTERNAME'] ?? '';
            $isGib = $kv['ISGIBUSER'] ?? '';
            $kayitli = ($isGib === '1' || strtolower($isGib) === 'true' || ($pk !== null && $pk !== ''));
            return [
                'ok'           => true,
                'kayitli'      => $kayitli,
                'posta_kutusu' => $kayitli && $pk !== '' ? $pk : null,
                'gb_etiket'    => $gb ?: null,
                'unvan'        => $unvan,
            ];
        });

        return $res + ['kayitli' => false, 'posta_kutusu' => null];
    }

    /** @return array{ok:bool,zarf_uuid?:string,kod?:string,mesaj?:string,hata?:string} */
    public function zarfGonder(array $zarf, string $ublXml): array
    {
        $ettn = trim((string)($zarf['ettn'] ?? ''));
        if ($ettn === '' || trim($ublXml) === '' || !str_contains($ublXml, $ettn)) {
            return ['ok' => false, 'hata' => 'UBL-TR XML doğrulaması başarısız (ETTN eksik).'];
        }
        $zip = edonusum_zip_olustur($ublXml, $ettn . '.xml');
        if (empty($zip['ok'])) {
            return ['ok' => false, 'hata' => (string)($zip['hata'] ?? 'ZIP sıkıştırma hatası.')];
        }
        $tur = (string)($zarf['belge_turu'] ?? 'efatura');
        $docType = $this->docTypeMap($tur);
        $params = [
            'DOCUMENTTYPE=' . $docType,
            'SIGNED=' . (!empty($this->ayar['otomatik_imza']) ? '1' : '0'),
        ];
        $aliciPk = trim((string)($zarf['alici_pk'] ?? ''));
        if ($aliciPk !== '' && in_array($tur, ['efatura', 'eirsaliye'], true)) {
            $params[] = 'ALIAS=' . $aliciPk;
        }
        $xsltKey = match ($tur) {
            'earsiv'    => 'xslt_earsiv_uuid',
            'eirsaliye' => 'xslt_eirsaliye_uuid',
            default     => 'xslt_efatura_uuid',
        };
        $xsltUuid = trim((string)($this->ayar[$xsltKey] ?? ''));
        if ($xsltUuid !== '') {
            $params[] = 'XSLTUUID=' . $xsltUuid;
        }

        return $this->oturumla(function (string $sid) use ($ettn, $zip, $params): array {
            $now = date('Y-m-d\TH:i:s');
            $body = '<tem:SendDocument>'
                . '<tem:sessionID>' . $this->xmlEsc($sid) . '</tem:sessionID>'
                . $this->paramListXml($params)
                . '<tem:document>'
                . '<efat:binaryData><efat:Value>' . base64_encode((string)$zip['zip']) . '</efat:Value></efat:binaryData>'
                . '<efat:currentDate>' . $now . '</efat:currentDate>'
                . '<efat:fileName>' . $this->xmlEsc($ettn . '.zip') . '</efat:fileName>'
                . '<efat:hash>' . $this->xmlEsc((string)$zip['hash']) . '</efat:hash>'
                . '</tem:document>'
                . '</tem:SendDocument>';
            $r = $this->soapCall('SendDocument', $body);
            if (empty($r['ok'])) {
                return ['ok' => false, 'hata' => (string)($r['hata'] ?? 'SendDocument çağrısı başarısız.')];
            }
            $xp = $r['xp'];
            $rc    = $this->ilkMetin($xp, "//*[local-name()='resultCode']");
            $msg   = $this->ilkMetin($xp, "//*[local-name()='resultMsg']");
            $refId = $this->ilkMetin($xp, "//*[local-name()='refId']");
            if ($rc !== '1') {
                return ['ok' => false, 'kod' => $rc, 'hata' => 'e-Logo gönderim reddi (' . ($rc ?: '?') . '): ' . ($msg ?: 'Bilinmeyen hata')];
            }
            return [
                'ok'        => true,
                'zarf_uuid' => $refId !== '' ? ('elogo-ref-' . $refId) : $ettn,
                'kod'       => $rc,
                'mesaj'     => 'e-Logo kuyruğa alındı' . ($refId !== '' ? ' (Ref #' . $refId . ')' : '') . ($msg !== '' ? ' · ' . $msg : ''),
            ];
        });
    }

    /** @return array{ok:bool,durum:string,kod?:string,mesaj?:string,hata?:string} */
    public function durumSorgula(string $ettn, ?string $zarfUuid = null, string $belgeTuru = 'efatura'): array
    {
        $docType = $this->docTypeMap($belgeTuru);
        $res = $this->oturumla(function (string $sid) use ($ettn, $docType): array {
            $body = '<tem:GetDocumentStatus>'
                . '<tem:sessionID>' . $this->xmlEsc($sid) . '</tem:sessionID>'
                . '<tem:uuid>' . $this->xmlEsc($ettn) . '</tem:uuid>'
                . $this->paramListXml(['DOCUMENTTYPE=' . $docType])
                . '</tem:GetDocumentStatus>';
            $r = $this->soapCall('GetDocumentStatus', $body);
            if (empty($r['ok'])) {
                return ['ok' => false, 'durum' => 'gonderildi', 'hata' => (string)($r['hata'] ?? 'Durum sorgulanamadı.')];
            }
            $xp = $r['xp'];
            $rc   = $this->ilkMetin($xp, "//*[local-name()='resultCode']");
            $rmsg = $this->ilkMetin($xp, "//*[local-name()='resultMsg']");
            if ($rc !== '' && $rc !== '1') {
                return ['ok' => false, 'durum' => 'gonderildi', 'kod' => $rc, 'hata' => 'e-Logo durum sorgu hatası (' . $rc . '): ' . $rmsg];
            }
            $gibKod = $this->ilkMetin($xp, "//*[local-name()='statusInfo']/*[local-name()='code']");
            $status = $this->ilkMetin($xp, "//*[local-name()='statusInfo']/*[local-name()='status']");
            $desc   = $this->ilkMetin($xp, "//*[local-name()='statusInfo']/*[local-name()='description']") ?: $rmsg;
            $envId  = $this->ilkMetin($xp, "//*[local-name()='statusInfo']/*[local-name()='envelopeId']");

            $durum = $this->gibDurumEsle($gibKod, $status, $desc);
            $mesaj = trim(($gibKod !== '' ? '[' . $gibKod . '] ' : '') . ($desc ?: 'Durum: ' . $durum));
            return [
                'ok'        => true,
                'durum'     => $durum,
                'kod'       => $gibKod !== '' ? $gibKod : $status,
                'mesaj'     => $mesaj,
                'zarf_uuid' => $envId !== '' ? $envId : null,
            ];
        });
        return $res + ['durum' => 'gonderildi'];
    }

    private function gibDurumEsle(string $gibKod, string $status, string $desc): string
    {
        $k = (int)$gibKod;
        $s = (int)$status;
        $dUp = mb_strtoupper($desc, 'UTF-8');
        if ($k === 1300 || ($s === 2 && ($k === 0 || $k >= 1200))) return 'onaylandi';
        if (str_contains($dUp, 'İPTAL') || str_contains($dUp, 'IPTAL') || str_contains($dUp, 'RED')) return 'iptal';
        if ($s < 0 || ($k >= 1110 && $k <= 1195) || $k === 1215 || $k === 1230 || $k >= 2000) return 'hata';
        return 'gonderildi';
    }

    /** @return array{ok:bool,belgeler:list<array<string,mixed>>,hata?:string} */
    public function gelenKutusuCek(string $bas, string $bit, string $belgeTuru = 'efatura'): array
    {
        $docType = $this->docTypeMap($belgeTuru);
        $res = $this->oturumla(function (string $sid) use ($bas, $bit, $docType, $belgeTuru): array {
            $body = '<tem:GetDocumentList>'
                . '<tem:sessionID>' . $this->xmlEsc($sid) . '</tem:sessionID>'
                . $this->paramListXml([
                    'DOCUMENTTYPE=' . $docType,
                    'BEGINDATE=' . $bas,
                    'ENDDATE=' . $bit,
                    'OPTYPE=2',
                    'DATEBY=0',
                ])
                . '</tem:GetDocumentList>';
            $r = $this->soapCall('GetDocumentList', $body);
            if (empty($r['ok'])) {
                return ['ok' => false, 'belgeler' => [], 'hata' => (string)($r['hata'] ?? 'Gelen listesi alınamadı.')];
            }
            $xp = $r['xp'];
            $rc = $this->ilkMetin($xp, "//*[local-name()='resultCode']");
            $rmsg = $this->ilkMetin($xp, "//*[local-name()='resultMsg']");
            if ($rc !== '' && $rc !== '1') {
                return ['ok' => false, 'belgeler' => [], 'hata' => 'e-Logo gelen kutusu hatası (' . $rc . '): ' . $rmsg];
            }

            $nodes = $xp->query("//*[local-name()='docList']/*[local-name()='DocumentInfo'] | //*[local-name()='docList']/*[local-name()='Document']");
            $uuidler = [];
            if ($nodes && $nodes->length > 0) {
                foreach ($nodes as $node) {
                    $u = '';
                    foreach ($node->childNodes as $ch) {
                        if ($ch instanceof DOMElement && in_array(strtolower($ch->localName), ['documentuuid', 'uuid', 'ettn'], true)) {
                            $u = trim((string)$ch->textContent);
                            break;
                        }
                    }
                    if ($u !== '') $uuidler[] = $u;
                }
            }
            // Alternatif olarak doğrudan <documentUuid> düğümleri
            if ($uuidler === []) {
                $uuidler = $this->tumMetinler($xp, "//*[local-name()='documentUuid'] | //*[local-name()='UUID']");
            }
            $uuidler = array_values(array_unique(array_slice($uuidler, 0, 50)));

            $belgeler = [];
            foreach ($uuidler as $uuid) {
                $dBody = '<tem:GetDocumentData>'
                    . '<tem:sessionID>' . $this->xmlEsc($sid) . '</tem:sessionID>'
                    . '<tem:uuid>' . $this->xmlEsc($uuid) . '</tem:uuid>'
                    . $this->paramListXml(['DOCUMENTTYPE=' . $docType, 'DATAFORMAT=UBL'])
                    . '</tem:GetDocumentData>';
                $dr = $this->soapCall('GetDocumentData', $dBody);
                if (empty($dr['ok'])) continue;
                $b64 = $this->ilkMetin($dr['xp'], "//*[local-name()='binaryData']/*[local-name()='Value']")
                    ?: $this->ilkMetin($dr['xp'], "//*[local-name()='binaryData']");
                if ($b64 === '') continue;
                $ham = base64_decode($b64, true);
                if ($ham === false) continue;
                $ac = edonusum_zip_ac($ham, 'xml');
                if (empty($ac['ok'])) continue;
                $belgeler[] = edonusum_ubl_ozet_coz((string)$ac['icerik'], $uuid, $belgeTuru);
            }
            return ['ok' => true, 'belgeler' => $belgeler];
        });
        return $res + ['belgeler' => []];
    }

    /** @return array{ok:bool,kod?:string,mesaj?:string,hata?:string} */
    public function ticariYanitGonder(string $ettn, string $yanit, string $neden = ''): array
    {
        $kod = strtolower($yanit) === 'kabul' ? 'KABUL' : 'RED';
        $appXml = edonusum_application_response_xml($ettn, $yanit, $neden, $this->ayar);
        $zip = edonusum_zip_olustur($appXml, $ettn . '_yanit.xml');
        if (empty($zip['ok'])) {
            return ['ok' => false, 'hata' => (string)($zip['hata'] ?? 'Yanıt ZIP oluşturulamadı.')];
        }

        $params = [
            'DOCUMENTTYPE=APPLICATIONRESPONSE',
            'UUID=' . $ettn,
            'RESPONSE=' . $kod,
            'SIGNED=0',
        ];
        if (trim($neden) !== '') {
            $params[] = 'DESCRIPTION=' . mb_substr(trim($neden), 0, 200, 'UTF-8');
        }

        return $this->oturumla(function (string $sid) use ($ettn, $zip, $params, $kod): array {
            $body = '<tem:SendDocument>'
                . '<tem:sessionID>' . $this->xmlEsc($sid) . '</tem:sessionID>'
                . $this->paramListXml($params)
                . '<tem:document>'
                . '<efat:binaryData><efat:Value>' . base64_encode((string)$zip['zip']) . '</efat:Value></efat:binaryData>'
                . '<efat:currentDate>' . date('Y-m-d\TH:i:s') . '</efat:currentDate>'
                . '<efat:fileName>' . $this->xmlEsc($ettn . '_yanit.zip') . '</efat:fileName>'
                . '<efat:hash>' . $this->xmlEsc((string)$zip['hash']) . '</efat:hash>'
                . '</tem:document>'
                . '</tem:SendDocument>';
            $r = $this->soapCall('SendDocument', $body);
            if (empty($r['ok'])) {
                return ['ok' => false, 'hata' => (string)($r['hata'] ?? 'Ticari yanıt gönderilemedi.')];
            }
            $xp = $r['xp'];
            $rc  = $this->ilkMetin($xp, "//*[local-name()='resultCode']");
            $msg = $this->ilkMetin($xp, "//*[local-name()='resultMsg']");
            if ($rc !== '1') {
                return ['ok' => false, 'kod' => $rc, 'hata' => 'e-Logo yanıt reddi (' . ($rc ?: '?') . '): ' . ($msg ?: 'Bilinmeyen hata')];
            }
            return [
                'ok'    => true,
                'kod'   => $kod,
                'mesaj' => 'e-Logo ticari fatura yanıtı iletildi (' . $kod . ')' . ($msg !== '' ? ' · ' . $msg : ''),
            ];
        });
    }

    /** @return array{ok:bool,pdf?:string,hata?:string} */
    public function belgePdfGetir(string $ettn, string $belgeTuru = 'efatura'): array
    {
        $docType = $this->docTypeMap($belgeTuru);
        return $this->oturumla(function (string $sid) use ($ettn, $docType): array {
            $body = '<tem:GetDocumentData>'
                . '<tem:sessionID>' . $this->xmlEsc($sid) . '</tem:sessionID>'
                . '<tem:uuid>' . $this->xmlEsc($ettn) . '</tem:uuid>'
                . $this->paramListXml(['DOCUMENTTYPE=' . $docType, 'DATAFORMAT=PDF'])
                . '</tem:GetDocumentData>';
            $r = $this->soapCall('GetDocumentData', $body);
            if (empty($r['ok'])) {
                return ['ok' => false, 'hata' => (string)($r['hata'] ?? 'PDF indirilemedi.')];
            }
            $xp = $r['xp'];
            $rc = $this->ilkMetin($xp, "//*[local-name()='resultCode']");
            $msg = $this->ilkMetin($xp, "//*[local-name()='resultMsg']");
            if ($rc !== '' && $rc !== '1') {
                return ['ok' => false, 'hata' => 'e-Logo PDF hatası (' . $rc . '): ' . $msg];
            }
            $b64 = $this->ilkMetin($xp, "//*[local-name()='binaryData']/*[local-name()='Value']")
                ?: $this->ilkMetin($xp, "//*[local-name()='binaryData']");
            if ($b64 === '') {
                return ['ok' => false, 'hata' => 'e-Logo PDF verisi boş döndü.'];
            }
            $ham = base64_decode($b64, true);
            if ($ham === false) return ['ok' => false, 'hata' => 'e-Logo PDF base64 çözülemedi.'];
            $ac = edonusum_zip_ac($ham, 'pdf');
            if (empty($ac['ok'])) return ['ok' => false, 'hata' => (string)($ac['hata'] ?? 'PDF açılamadı.')];
            return ['ok' => true, 'pdf' => (string)$ac['icerik']];
        });
    }
}
