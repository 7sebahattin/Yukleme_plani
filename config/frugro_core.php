<?php
// =========================================================
// config/frugro_core.php — Frugro Siparişler çekirdeği (Frugro Ltd., İngiltere alıcı; include-only)
//
// OGL siparişleriyle AYNI mantık: alıcının gönderdiği "Order Log" Excel'i okunur, PO no'ya göre TEK kayıt tutulur.
// Yetki: yeni yetki YOK — can_siparis() (siparis.read / write / delete).
// Şema yalnız migrate.php "Frugro Siparişler" kartından kurulur (frg_migrate; ALTER yok, 3 tablo, GO kuralı).
// Excel okuyucu SAF'tır (frg_xlsx_oku: DB yok, dosyayı diske KAYDETMEZ, yalnız sha256 alır; salt veri okuma, formül hesaplama YOK).
// Fiyat on-binde TAM SAYI (4,6 => 46000); tutar kuruş TAM SAYI; para birimi siparişte YOK (fatura ekranında seçilir).
// =========================================================
declare(strict_types=1);

// Doğrudan istek koruması (tam yol; basename DEĞİL — bkz. siparis_yazdir.php v385 dersi)
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

const FRG_SAYFA_ADEDI = 50;
const FRG_PO_DESEN    = '/^\d{2}-\d{4}-\d{5}$/';

/** Sipariş durumları (sıra = ilerleme). @return array<string,string> kod → etiket */
function frg_durumlar(): array
{
    return ['yeni' => 'Yeni', 'pkl_hazir' => 'PKL hazır', 'invoice_hazir' => 'Invoice hazır',
        'yola_cikti' => 'Yola çıktı', 'kapali' => 'Kapalı', 'iptal' => 'İptal'];
}

/** @return array<string,string> tablo adı → CREATE */
function frg_tablolar(): array
{
    return [
        'frugro_siparisler' => "CREATE TABLE IF NOT EXISTS `frugro_siparisler` (
            `id`                 INT AUTO_INCREMENT PRIMARY KEY,
            `po_no`              VARCHAR(30)   NOT NULL,
            `cikis_tarihi`       DATE          NULL DEFAULT NULL,
            `varis_tarihi`       DATE          NULL DEFAULT NULL,
            `varis_saati`        VARCHAR(30)   NULL DEFAULT NULL,
            `ihracatci`          VARCHAR(150)  NULL DEFAULT NULL,
            `paketlemeci`        VARCHAR(150)  NULL DEFAULT NULL,
            `giris_limani`       VARCHAR(150)  NULL DEFAULT NULL,
            `teslim_adresi`      TEXT          NULL,
            `son_musteri`        VARCHAR(150)  NULL DEFAULT NULL,
            `son_musteri_po`     VARCHAR(100)  NULL DEFAULT NULL,
            `urun`               VARCHAR(100)  NULL DEFAULT NULL,
            `cesit`              VARCHAR(100)  NULL DEFAULT NULL,
            `mense`              VARCHAR(60)   NULL DEFAULT NULL,
            `boy`                VARCHAR(30)   NULL DEFAULT NULL,
            `fruit_agirlik_notu` TEXT          NULL,
            `dis_paket`          VARCHAR(255)  NULL DEFAULT NULL,
            `ic_paket`           VARCHAR(255)  NULL DEFAULT NULL,
            `kutu`               INT           NULL DEFAULT NULL,
            `kutu_palet`         INT           NULL DEFAULT NULL,
            `palet`              INT           NULL DEFAULT NULL,
            `palet_tipi`         VARCHAR(60)   NULL DEFAULT NULL,
            `incoterm`           VARCHAR(10)   NULL DEFAULT NULL,
            `fiyat_kutu_birim`   BIGINT        NULL DEFAULT NULL,
            `para_birimi`        VARCHAR(3)    NULL DEFAULT NULL,
            `parti_no`           VARCHAR(30)   NULL DEFAULT NULL,
            `plaka`              VARCHAR(80)   NULL DEFAULT NULL,
            `sofor_adi`          VARCHAR(100)  NULL DEFAULT NULL,
            `sofor_telefon`      VARCHAR(40)   NULL DEFAULT NULL,
            `nakliyeci`          VARCHAR(150)  NULL DEFAULT NULL,
            `logger_id`          VARCHAR(60)   NULL DEFAULT NULL,
            `sicaklik`           VARCHAR(30)   NULL DEFAULT NULL,
            `karton_kg`          DECIMAL(6,3)  NULL DEFAULT NULL,
            `palet_diger_kg`     DECIMAL(6,2)  NULL DEFAULT NULL,
            `bolge`              VARCHAR(100)  NULL DEFAULT NULL,
            `hasat_yili`         VARCHAR(10)   NULL DEFAULT NULL,
            `fatura_no`          VARCHAR(40)   NULL DEFAULT NULL,
            `durum`              VARCHAR(20)   NOT NULL DEFAULT 'yeni',
            `depo`               VARCHAR(100)  NOT NULL DEFAULT '',
            `kaynak`             VARCHAR(10)   NOT NULL DEFAULT 'elle',
            `kaynak_mail_id`     INT           NULL DEFAULT NULL,
            `ham_json`           MEDIUMTEXT    NULL,
            `dosya_sha`          VARCHAR(64)   NULL DEFAULT NULL,
            `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by`         INT           NULL DEFAULT NULL,
            `updated_at`         DATETIME      NULL DEFAULT NULL,
            `deleted_at`         DATETIME      NULL DEFAULT NULL,
            UNIQUE KEY `uq_frg_po` (`po_no`),
            KEY `idx_frg_durum` (`durum`),
            KEY `idx_frg_depo` (`depo`),
            KEY `idx_frg_cikis` (`cikis_tarihi`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
        'frugro_palet' => "CREATE TABLE IF NOT EXISTS `frugro_palet` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `siparis_id` INT           NOT NULL,
            `sira`       INT           NOT NULL DEFAULT 0,
            `palet_no`   VARCHAR(10)   NULL DEFAULT NULL,
            `konum`      VARCHAR(30)   NULL DEFAULT NULL,
            `kalibre`    VARCHAR(20)   NULL DEFAULT NULL,
            `lot`        VARCHAR(30)   NULL DEFAULT NULL,
            `ggn_no`     VARCHAR(20)   NULL DEFAULT NULL,
            `ggn_adi`    VARCHAR(100)  NULL DEFAULT NULL,
            `kutu`       INT           NULL DEFAULT NULL,
            `brut_kg`    DECIMAL(10,3) NULL DEFAULT NULL,
            `net_kg`     DECIMAL(10,3) NULL DEFAULT NULL,
            KEY `idx_frgp_siparis` (`siparis_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
        'frugro_olaylar' => "CREATE TABLE IF NOT EXISTS `frugro_olaylar` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `siparis_id` INT          NOT NULL,
            `tur`        VARCHAR(30)  NOT NULL,
            `metin`      TEXT         NULL,
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by` INT          NULL DEFAULT NULL,
            KEY `idx_frgo_siparis` (`siparis_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
    ];
}

/** Üç tablo da var mı? (süreç başına önbelleksiz; ucuz LIMIT 0 sorguları) */
function frg_hazir(PDO $pdo): bool
{
    static $var = [];
    $k = spl_object_id($pdo);
    if (isset($var[$k])) return true;
    try {
        foreach (array_keys(frg_tablolar()) as $t) $pdo->query("SELECT 1 FROM `$t` LIMIT 0");
        return $var[$k] = true;
    } catch (PDOException $e) {
        return false;
    }
}

/** Yalnız CREATE TABLE IF NOT EXISTS (ALTER YOK). Yalnız migrate.php kartından çağrılır. @return list<array{tablo:string,durum:string,mesaj:string}> */
function frg_migrate(PDO $pdo): array
{
    $rapor = [];
    foreach (frg_tablolar() as $ad => $ddl) {
        $var = false;
        try { $pdo->query("SELECT 1 FROM `$ad` LIMIT 0"); $var = true; } catch (PDOException $e) { $var = false; }
        if ($var) { $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.']; continue; }
        try {
            $pdo->exec($ddl);
            $rapor[] = ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.'];
        } catch (PDOException $e) {
            error_log('[frg_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'Tablo oluşturulamadı (ayrıntı sunucu günlüğünde).'];
        }
    }
    return $rapor;
}

// ── Excel okuyucu (SAF) ──────────────────────────────────────────────────────

/** Başlık metnini karşılaştırma anahtarına çevirir: küçük harf, yalnız a-z0-9 ("Fruit/ weight…" → "fruitweight…"). */
function frg_baslik_norm(string $s): string
{
    $s = strtr($s, ['İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ç' => 'c', 'ç' => 'c', 'Ş' => 's', 'ş' => 's', 'Ğ' => 'g', 'ğ' => 'g', 'Ü' => 'u', 'ü' => 'u', 'Ö' => 'o', 'ö' => 'o']);
    return (string)preg_replace('/[^a-z0-9]+/', '', strtolower($s));
}

/** İngilizce başlık (normalize) → alan. Önce TAM eşleşme, yoksa başlangıç (uzun önek önce). @return array{0:?string} */
function frg_baslik_alan(string $norm): ?string
{
    static $tam = [
        'poordernumber' => 'po_no', 'departuredate' => 'cikis_tarihi', 'arrivaldate' => 'varis_tarihi', 'arrivaltime' => 'varis_saati',
        'supplier' => 'ihracatci', 'grower' => 'paketlemeci', 'awbentryport' => 'giris_limani', 'deliveryaddress' => 'teslim_adresi',
        'customerordernumber' => 'son_musteri_po', 'customer' => 'son_musteri', 'product' => 'urun', 'variety' => 'cesit',
        'coo' => 'mense', 'outerandfleshcolour' => 'renk', 'size' => 'boy', 'outerpackaging' => 'dis_paket', 'innerpackaging' => 'ic_paket',
        'volume' => 'kutu', 'casesperpallet' => 'kutu_palet', 'pallets' => 'palet', 'pallettype' => 'palet_tipi',
    ];
    if (isset($tam[$norm])) return $tam[$norm];
    $onek = ['fruit' => 'fruit_agirlik_notu', 'incoterm' => 'incoterm', 'invoiceprice' => 'fiyat_kutu_birim'];
    foreach ($onek as $o => $alan) if (strncmp($norm, $o, strlen($o)) === 0) return $alan;
    return null;
}

/** Hücre → 'Y-m-d' | null. Excel seri sayısı, datetime metni, gg.aa.yyyy, gg/aa/yyyy. */
function frg_tarih($v): ?string
{
    if ($v === null || $v === '') return null;
    if ($v instanceof DateTimeInterface) return $v->format('Y-m-d');
    if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^\d+(\.\d+)?$/', trim($v)))) {
        $n = (float)$v;
        if ($n < 20000 || $n > 80000) return null;   // makul seri aralığı (1954–2119)
        return gmdate('Y-m-d', (int)round(($n - 25569) * 86400));
    }
    $s = trim((string)$v);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return "$m[1]-$m[2]-$m[3]";
    if (preg_match('#^(\d{1,2})[./](\d{1,2})[./](\d{4})#', $s, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    return null;
}

/** Fiyat hücresi → on-binde tamsayı (4,6 → 46000); okunamazsa null. */
function frg_fiyat_birim($v): ?int
{
    if ($v === null || $v === '') return null;
    if (is_string($v)) {
        $v = trim($v);
        if (!preg_match('/^\d+([.,]\d+)?$/', $v)) return null;
        $v = (float)str_replace(',', '.', $v);
    }
    if (!is_int($v) && !is_float($v)) return null;
    return (int)round(((float)$v) * 10000);
}

/** on-binde fiyat → "4,60" (en az 2, en çok 4 ondalık). */
function frg_fiyat_metin(?int $f): string
{
    if ($f === null) return '';
    $s = number_format($f / 10000, 4, ',', '');
    $s = rtrim($s, '0');
    $ond = strlen(substr($s, (int)strpos($s, ',') + 1));
    return $ond < 2 ? $s . str_repeat('0', 2 - $ond) : $s;
}

/** Basit formül çözücü: "=B4+8" (aynı sayfadaki hücre + gün). Çözülemezse null. */
function frg_formul_tarih(string $f, array $satirlar): ?string
{
    if (!preg_match('/^=\s*([A-Z]{1,3})(\d+)\s*\+\s*(\d+)\s*$/', $f, $m)) return null;
    $hedef = $satirlar[(int)$m[2]][$m[1]] ?? null;
    $t = is_string($hedef) && ($hedef[0] ?? '') === '=' ? null : frg_tarih($hedef);
    if ($t === null) return null;
    return gmdate('Y-m-d', strtotime($t . ' UTC') + (int)$m[3] * 86400);
}

/** Hücre metni: kırp, boşlukları tekle (NUL/denetim karakteri at). */
function frg_metin($v): ?string
{
    if ($v === null) return null;
    if (is_float($v) && floor($v) === $v) $v = (int)$v;
    $s = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$v));
    $s = (string)preg_replace('/[ \t]+/u', ' ', $s);
    return $s === '' ? null : $s;
}

/** Hücre → tam sayı (formül/metin → null). */
function frg_tam($v): ?int
{
    if (is_int($v)) return $v;
    if (is_float($v)) return (int)round($v);
    if (is_string($v) && preg_match('/^\s*\d+\s*$/', $v)) return (int)trim($v);
    return null;
}

/** "SUM(S4:S6)" formülünden satır aralığı [a,b] | null. */
function frg_toplam_aralik($f, string $kol): ?array
{
    if (is_string($f) && preg_match('/^=\s*SUM\(\s*' . $kol . '(\d+)\s*:\s*' . $kol . '(\d+)\s*\)\s*$/i', $f, $m)) return [(int)$m[1], (int)$m[2]];
    return null;
}

/**
 * Frugro "Order Log" xlsx'ini okur. SAF: DB yok, dosyaya yazmaz. Yalnız PO no'su \d{2}-\d{4}-\d{5} olan satırlar alınır
 * (geçen yıldan kalma şablon/örnek satırlar yok sayılır). Başlıklar ADA göre eşlenir (sütun konumuna güvenilmez).
 * @return array{ok:bool,hata:?string,dosya_sha:string,siparisler:list<array<string,mixed>>,uyarilar:list<string>,notlar:list<string>,atlanan:int}
 */
function frg_xlsx_oku(string $yol): array
{
    $son = ['ok' => false, 'hata' => null, 'dosya_sha' => '', 'siparisler' => [], 'uyarilar' => [], 'notlar' => [], 'atlanan' => 0];
    if (!is_file($yol) || !is_readable($yol)) { $son['hata'] = 'Dosya okunamadı.'; return $son; }
    $son['dosya_sha'] = (string)hash_file('sha256', $yol);
    $bas = (string)@file_get_contents($yol, false, null, 0, 4);
    if (strncmp($bas, "PK\x03\x04", 4) !== 0) { $son['hata'] = 'Geçerli bir Excel (xlsx) dosyası değil.'; return $son; }
    if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
        $oto = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($oto)) require_once $oto;
    }
    try {
        $r = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
        $r->setReadDataOnly(true);           // formül hesabı YOK
        $xl = $r->load($yol);
        $ws = $xl->getSheetByName('Order Log') ?? $xl->getSheet(0);
        $satirlar = $ws->toArray(null, false, false, true);   // calculateFormulas=false: formüller metin kalır
    } catch (Throwable $e) {
        error_log('[frg_xlsx_oku] ' . $e->getMessage());
        $son['hata'] = 'Excel okunamadı.';
        return $son;
    }

    // Başlık satırı: ilk 15 satırda "(PO) Order Number" hücresi
    $bSatir = null; $kolon = [];
    foreach ($satirlar as $no => $row) {
        if ($no > 15) break;
        $harita = [];
        foreach ($row as $k => $v) {
            if (!is_string($v) || trim($v) === '') continue;
            $a = frg_baslik_alan(frg_baslik_norm($v));
            if ($a !== null && !in_array($a, $harita, true)) $harita[$k] = $a;
        }
        if (in_array('po_no', $harita, true) && count($harita) >= 6) { $bSatir = (int)$no; $kolon = $harita; break; }
    }
    if ($bSatir === null) { $son['hata'] = "Başlık satırı bulunamadı ('(PO) Order Number' sütunu yok)."; return $son; }
    $ters = array_flip($kolon);   // alan → sütun harfi

    $toplamSatir = null;
    $kutuKol = $ters['kutu'] ?? null; $paletKol = $ters['palet'] ?? null;
    foreach ($satirlar as $no => $row) {
        if ($no <= $bSatir) continue;
        foreach ($row as $v) {
            if (is_string($v) && preg_match('/^\s*(total|toplam)\s*:?\s*$/i', $v)) { $toplamSatir = (int)$no; break 2; }
        }
    }

    $gecerli = [];
    foreach ($satirlar as $no => $row) {
        $no = (int)$no;
        if ($no <= $bSatir + 1 || ($toplamSatir !== null && $no >= $toplamSatir)) continue;   // +1: Türkçe başlık satırı
        $po = frg_metin($row[$ters['po_no']] ?? null);
        if ($po === null || !preg_match(FRG_PO_DESEN, $po)) {
            // PO'suz satır = şablon/örnek: sessizce yok say. PO yazılı ama biçim bozuksa uyar.
            if ($po !== null && frg_metin($row[$ters['urun'] ?? 'K'] ?? null) !== null) {
                $son['uyarilar'][] = "Satır $no: PO no biçimi geçersiz ('$po'), satır yok sayıldı (beklenen 26-1622-44080).";
            }
            $son['atlanan']++;
            continue;
        }
        $s = ['po_no' => $po, 'uyarilar' => [], 'satir' => $no];
        $ham = [];
        foreach ($ters as $alan => $kol) $ham[$alan] = $row[$kol] ?? null;
        foreach (['cikis_tarihi', 'varis_tarihi'] as $a) {
            $v = $ham[$a] ?? null;
            if (is_string($v) && ($v[0] ?? '') === '=') {
                $s[$a] = frg_formul_tarih($v, $satirlar) ?? ($a === 'varis_tarihi' ? null : null);
                if ($s[$a] === null) $s['uyarilar'][] = "$po: $a formülü çözülemedi ('$v').";
            } else {
                $s[$a] = frg_tarih($v);
                if ($s[$a] === null && $v !== null && $v !== '') $s['uyarilar'][] = "$po: $a okunamadı ('" . frg_metin($v) . "').";
            }
        }
        foreach (['varis_saati', 'ihracatci', 'paketlemeci', 'giris_limani', 'teslim_adresi', 'son_musteri', 'son_musteri_po', 'urun', 'cesit',
                  'mense', 'boy', 'fruit_agirlik_notu', 'dis_paket', 'ic_paket', 'palet_tipi', 'incoterm'] as $a) {
            $s[$a] = frg_metin($ham[$a] ?? null);
        }
        if ($s['incoterm'] !== null) $s['incoterm'] = mb_substr(strtoupper(preg_replace('/[^A-Za-z]/', '', $s['incoterm'])), 0, 10) ?: null;
        $s['kutu_palet'] = frg_tam($ham['kutu_palet'] ?? null);
        $s['palet'] = frg_tam($ham['palet'] ?? null);
        // Kutu: formül (=T*U) ise çarpımdan; sayı ise o değerden, çarpımla tutarlılığı denetlenir
        $hamKutu = $ham['kutu'] ?? null;
        $carp = ($s['kutu_palet'] !== null && $s['palet'] !== null) ? $s['kutu_palet'] * $s['palet'] : null;
        $kutuSayi = frg_tam($hamKutu);
        if ($kutuSayi !== null && $carp !== null && $kutuSayi !== $carp) {
            $s['uyarilar'][] = "$po: kutu ($kutuSayi) ≠ palet × kutu/palet ($carp).";
        }
        $s['kutu'] = $kutuSayi ?? $carp;
        $s['fiyat_kutu_birim'] = frg_fiyat_birim($ham['fiyat_kutu_birim'] ?? null);
        if (isset($ham['renk'])) $s['renk'] = frg_metin($ham['renk']);

        foreach (['cikis_tarihi' => 'çıkış tarihi', 'varis_tarihi' => 'varış tarihi', 'giris_limani' => 'giriş limanı', 'son_musteri' => 'müşteri',
                  'urun' => 'ürün', 'kutu_palet' => 'kutu/palet', 'palet' => 'palet', 'fiyat_kutu_birim' => 'fiyat', 'incoterm' => 'incoterm'] as $a => $ad) {
            if ($s[$a] === null) $s['uyarilar'][] = "$po: zorunlu alan eksik ($ad).";
        }
        if ($s['cikis_tarihi'] !== null && $s['varis_tarihi'] !== null && $s['cikis_tarihi'] > $s['varis_tarihi']) {
            $s['uyarilar'][] = "$po: çıkış tarihi varış tarihinden sonra.";
        }
        $gecerli[] = $s;
    }

    // Toplam satırı: SUM formülü aralığını kendimiz topla (sayfadaki şablon satırları 0 katkı verir) ya da sabit sayıyı al
    if ($toplamSatir !== null) {
        $tr = $satirlar[$toplamSatir];
        foreach (['kutu' => $kutuKol, 'palet' => $paletKol] as $alan => $kol) {
            if ($kol === null) continue;
            $h = $tr[$kol] ?? null;
            $beklenen = null;
            if (($ar = frg_toplam_aralik($h, $kol)) !== null) {
                $beklenen = 0;
                for ($i = $ar[0]; $i <= $ar[1]; $i++) {
                    $rw = $satirlar[$i] ?? [];
                    if ($alan === 'palet') { $beklenen += (int)(frg_tam($rw[$kol] ?? null) ?? 0); continue; }
                    $kp = frg_tam($rw[$ters['kutu_palet'] ?? ''] ?? null); $pl = frg_tam($rw[$ters['palet'] ?? ''] ?? null);
                    $beklenen += frg_tam($rw[$kol] ?? null) ?? (($kp !== null && $pl !== null) ? $kp * $pl : 0);
                }
            } elseif (frg_tam($h) !== null) {
                $beklenen = frg_tam($h);
            }
            if ($beklenen === null) continue;
            $okunan = 0;
            foreach ($gecerli as $g) $okunan += (int)($g[$alan] ?? 0);
            if ($okunan !== $beklenen) $son['uyarilar'][] = "Toplam satırı uyuşmuyor ($alan): dosya toplamı $beklenen, okunan PO satırları $okunan.";
        }
    }

    // Altta kalan notlar (A sütunu düz metin) — bilgi amaçlı
    if ($toplamSatir !== null) {
        foreach ($satirlar as $no => $row) {
            if ($no <= $toplamSatir) continue;
            $t = frg_metin($row[array_key_first($row)] ?? null);
            if ($t !== null) $son['notlar'][] = $t;
        }
    }
    foreach ($gecerli as $g) foreach ($g['uyarilar'] as $u) $son['uyarilar'][] = $u;
    if (!$gecerli) $son['uyarilar'][] = 'Dosyada geçerli PO satırı bulunamadı.';
    $son['siparisler'] = $gecerli;
    $son['ok'] = true;
    return $son;
}

// ── Hesap ────────────────────────────────────────────────────────────────────

/**
 * Toplam kutu / palet / tutar. Tutar = kutu × fiyat (on-binde) → KURUŞ (yarım yukarı), TAM SAYI.
 * Para birimi ayrıdır; farklı para birimleri toplanmaz (bu fonksiyon tek sipariş içindir).
 * @return array{kutu:int,palet:int,tutar_kurus:int,para_birimi:?string,fiyat_var:bool}
 */
function frg_hesap(array $s): array
{
    $kp = (int)($s['kutu_palet'] ?? 0); $pl = (int)($s['palet'] ?? 0);
    $kutu = isset($s['kutu']) && $s['kutu'] !== null && $s['kutu'] !== '' ? (int)$s['kutu'] : $kp * $pl;
    $f = isset($s['fiyat_kutu_birim']) && $s['fiyat_kutu_birim'] !== null && $s['fiyat_kutu_birim'] !== '' ? (int)$s['fiyat_kutu_birim'] : null;
    // kutu × f (1/10000 birim) → kuruş (1/100): ÷100, yarım yukarı
    $kurus = $f === null ? 0 : intdiv($kutu * $f + 50, 100);
    $pb = isset($s['para_birimi']) && $s['para_birimi'] !== '' ? (string)$s['para_birimi'] : null;
    return ['kutu' => $kutu, 'palet' => $pl, 'tutar_kurus' => $kurus, 'para_birimi' => $pb, 'fiyat_var' => $f !== null];
}

// ── Okuma ────────────────────────────────────────────────────────────────────

/**
 * Liste. Filtre: q (PO / müşteri / ürün), durum, bas/bit (çıkış tarihi). Soft silinenler hariç; aktif depo kuralı depo_sql_column.
 * @return list<array<string,mixed>>
 */
function frg_liste(PDO $pdo, array $filtre = []): array
{
    [$dSql, $par] = depo_sql_column('depo');
    $where = 'deleted_at IS NULL' . $dSql;
    $q = trim((string)($filtre['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($q, 0, 80)) . '%';
        $where .= " AND (po_no LIKE :fq0 ESCAPE '!' OR son_musteri LIKE :fq1 ESCAPE '!' OR son_musteri_po LIKE :fq2 ESCAPE '!' OR urun LIKE :fq3 ESCAPE '!')";
        $par[':fq0'] = $par[':fq1'] = $par[':fq2'] = $par[':fq3'] = $like;
    }
    $durum = (string)($filtre['durum'] ?? '');
    if ($durum !== '' && isset(frg_durumlar()[$durum])) { $where .= ' AND durum = :fdurum'; $par[':fdurum'] = $durum; }
    foreach (['bas' => '>=', 'bit' => '<='] as $k => $op) {
        $t = frg_tarih($filtre[$k] ?? null);
        if ($t !== null) { $where .= " AND cikis_tarihi $op :f$k"; $par[":f$k"] = $t; }
    }
    $lim = max(1, min(500, (int)($filtre['limit'] ?? 200)));
    $st = $pdo->prepare("SELECT * FROM frugro_siparisler WHERE $where ORDER BY (cikis_tarihi IS NULL), cikis_tarihi DESC, id DESC LIMIT $lim");
    $st->execute($par);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Tek sipariş + paletleri; yok/silinmiş → null. Depo kapısını ÇAĞIRAN uygular (frg_gorunur). */
function frg_getir(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM frugro_siparisler WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $p = $pdo->prepare('SELECT * FROM frugro_palet WHERE siparis_id = ? ORDER BY sira, id');
    $p->execute([$id]);
    $r['paletler'] = $p->fetchAll(PDO::FETCH_ASSOC);
    return $r;
}

/** Aktif depo kuralıyla (boş depo hep görünür) bu kayıt kullanıcıya açık mı? */
function frg_gorunur(array $s): bool
{
    return depot_visible_to_user((string)($s['depo'] ?? ''));
}

/** Olay günlüğü. @return list<array<string,mixed>> */
function frg_olaylar(PDO $pdo, int $siparisId, int $limit = 100): array
{
    $st = $pdo->prepare('SELECT * FROM frugro_olaylar WHERE siparis_id = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
    $st->execute([$siparisId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function frg_olay_yaz(PDO $pdo, int $siparisId, string $tur, string $metin, ?int $userId): void
{
    $pdo->prepare('INSERT INTO frugro_olaylar (siparis_id, tur, metin, created_at, created_by) VALUES (?,?,?,?,?)')
        ->execute([$siparisId, mb_substr($tur, 0, 30), mb_substr($metin, 0, 4000), date('Y-m-d H:i:s'), $userId]);
}

// ── Yazma (TEK yol) ──────────────────────────────────────────────────────────

/** Excel'den gelen, upsert'te karşılaştırılan/yazılan alanlar. @return list<string> */
function frg_excel_alanlari(): array
{
    return ['cikis_tarihi', 'varis_tarihi', 'varis_saati', 'ihracatci', 'paketlemeci', 'giris_limani', 'teslim_adresi', 'son_musteri',
        'son_musteri_po', 'urun', 'cesit', 'mense', 'boy', 'fruit_agirlik_notu', 'dis_paket', 'ic_paket', 'kutu', 'kutu_palet', 'palet',
        'palet_tipi', 'incoterm', 'fiyat_kutu_birim'];
}

/** PKL başlığı / elle alanlar: Excel okuyucu üretmez; yeni kayıtta $okunan'da varsa yazılır. @return list<string> */
function frg_pkl_alanlari(): array
{
    return ['parti_no', 'plaka', 'sofor_adi', 'sofor_telefon', 'nakliyeci', 'logger_id', 'sicaklik', 'karton_kg', 'palet_diger_kg',
        'bolge', 'hasat_yili', 'fatura_no', 'para_birimi'];
}

/** Karşılaştırma için tek biçim. */
function frg_norm_deger($v): string
{
    return $v === null ? '' : trim((string)$v);
}

/**
 * Bir PO satırını (frg_xlsx_oku()['siparisler'][i] biçimi) kaydeder — TEK yazma yolu.
 *  - Yeni PO → satır açar (durum 'yeni').
 *  - Aynı PO ikinci kez (mail "Geri Gönderim" çifti): satır AÇILMAZ. dosya_sha aynıysa 'zaten'; farklıysa alanlar karşılaştırılır:
 *    fark yoksa 'zaten'; fark varsa yalnız frugro_olaylar'a yazılır ('fark'). Durum 'yeni' İSE farklı alanlar dosyadakiyle güncellenir
 *    ('guncellendi'); durum ilerlemişse HİÇBİR alan ezilmez (elle düzeltme korunur).
 *  - Soft silinmiş PO geri getirilmez ('silinmis').
 * $ctx: user_id, depo (boşsa active_depot()), kaynak ('mail'|'yukleme'|'elle'), kaynak_mail_id, dosya_sha, ham (ham_json için dizi).
 * @return array{durum:string,id:?int,farklar:list<array{alan:string,eski:string,yeni:string}>,mesaj:string}
 */
function frg_siparis_kaydet(PDO $pdo, array $okunan, array $ctx = []): array
{
    $po = trim((string)($okunan['po_no'] ?? ''));
    if (!preg_match(FRG_PO_DESEN, $po)) return ['durum' => 'hata', 'id' => null, 'farklar' => [], 'mesaj' => 'PO no biçimi geçersiz.'];
    $uid = isset($ctx['user_id']) ? (int)$ctx['user_id'] : (function_exists('current_user') && ($u = current_user()) ? (int)$u['id'] : null);
    $sha = (string)($ctx['dosya_sha'] ?? $okunan['dosya_sha'] ?? '');
    $kaynak = in_array($ctx['kaynak'] ?? 'elle', ['mail', 'yukleme', 'elle'], true) ? (string)($ctx['kaynak'] ?? 'elle') : 'elle';
    $simdi = date('Y-m-d H:i:s');

    $st = $pdo->prepare('SELECT * FROM frugro_siparisler WHERE po_no = ?');
    $st->execute([$po]);
    $var = $st->fetch(PDO::FETCH_ASSOC);

    if ($var) {
        $id = (int)$var['id'];
        if ($var['deleted_at'] !== null) return ['durum' => 'silinmis', 'id' => $id, 'farklar' => [], 'mesaj' => 'Bu PO silinmiş; geri getirilmedi.'];
        if ($sha !== '' && (string)$var['dosya_sha'] === $sha) return ['durum' => 'zaten', 'id' => $id, 'farklar' => [], 'mesaj' => 'Aynı dosya daha önce alınmış.'];
        $farklar = [];
        foreach (frg_excel_alanlari() as $a) {
            if (!array_key_exists($a, $okunan)) continue;
            $e = frg_norm_deger($var[$a] ?? null); $y = frg_norm_deger($okunan[$a]);
            if ($e !== $y) $farklar[] = ['alan' => $a, 'eski' => $e, 'yeni' => $y];
        }
        if (!$farklar) return ['durum' => 'zaten', 'id' => $id, 'farklar' => [], 'mesaj' => 'Aynı içerik; değişiklik yok.'];
        $ozet = implode('; ', array_map(static fn($f) => $f['alan'] . ': ' . ($f['eski'] === '' ? '—' : $f['eski']) . ' → ' . ($f['yeni'] === '' ? '—' : $f['yeni']), $farklar));
        $guncelle = ((string)$var['durum'] === 'yeni');
        $sahip = !$pdo->inTransaction();
        if ($sahip) $pdo->beginTransaction();
        try {
            if ($guncelle) {
                $set = []; $par = [];
                foreach ($farklar as $f) { $set[] = '`' . $f['alan'] . '` = ?'; $par[] = $okunan[$f['alan']]; }
                $set[] = 'dosya_sha = ?'; $par[] = $sha !== '' ? $sha : null;
                $set[] = 'updated_at = ?'; $par[] = $simdi;
                $par[] = $id;
                $pdo->prepare('UPDATE frugro_siparisler SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($par);
            }
            frg_olay_yaz($pdo, $id, $guncelle ? 'guncellendi' : 'fark', ($kaynak === 'mail' ? '(mailden) ' : '') . ($guncelle ? 'Güncellendi: ' : 'Yeni dosyada fark var, alanlar EZİLMEDİ: ') . $ozet, $uid);
            if ($sahip) $pdo->commit();
        } catch (Throwable $e) {
            if ($sahip && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        if (function_exists('audit_log_event')) audit_log_event($guncelle ? 'update' : 'frugro_fark', 'frugro_siparis', $id, null, ['po_no' => $po, 'alanlar' => array_column($farklar, 'alan'), 'kaynak' => $kaynak]);
        return ['durum' => $guncelle ? 'guncellendi' : 'fark', 'id' => $id, 'farklar' => $farklar, 'mesaj' => $guncelle ? 'Alanlar güncellendi.' : 'Fark olay olarak yazıldı; alanlar korundu.'];
    }

    $depo = (string)($ctx['depo'] ?? (function_exists('active_depot') ? (active_depot() ?? '') : ''));
    $kol = ['po_no' => $po];
    foreach (array_merge(frg_excel_alanlari(), frg_pkl_alanlari()) as $a) $kol[$a] = $okunan[$a] ?? null;
    $ham = $ctx['ham'] ?? $okunan;
    unset($ham['uyarilar']);
    $kol += ['durum' => 'yeni', 'depo' => $depo, 'kaynak' => $kaynak, 'kaynak_mail_id' => isset($ctx['kaynak_mail_id']) ? (int)$ctx['kaynak_mail_id'] : null,
        'ham_json' => json_encode($ham, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), 'dosya_sha' => $sha !== '' ? $sha : null,
        'created_at' => $simdi, 'created_by' => $uid];
    $sahip = !$pdo->inTransaction();
    if ($sahip) $pdo->beginTransaction();
    try {
        $ad = array_keys($kol);
        $pdo->prepare('INSERT INTO frugro_siparisler (`' . implode('`,`', $ad) . '`) VALUES (' . implode(',', array_fill(0, count($ad), '?')) . ')')->execute(array_values($kol));
        $id = (int)$pdo->lastInsertId();
        frg_olay_yaz($pdo, $id, 'olustu', ($kaynak === 'mail' ? '(mailden) ' : '') . 'Sipariş alındı: ' . $po, $uid);
        if ($sahip) $pdo->commit();
    } catch (Throwable $e) {
        if ($sahip && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    if (function_exists('audit_log_event')) audit_log_event('create', 'frugro_siparis', $id, null, ['po_no' => $po, 'kaynak' => $kaynak, 'kutu' => $kol['kutu'], 'palet' => $kol['palet']]);
    return ['durum' => 'olustu', 'id' => $id, 'farklar' => [], 'mesaj' => 'Sipariş oluşturuldu.'];
}

/** frg_xlsx_oku() sonucunun tüm PO satırlarını kaydeder. @return array{sonuclar:list<array<string,mixed>>,sayac:array<string,int>} */
function frg_toplu_kaydet(PDO $pdo, array $okuma, array $ctx = []): array
{
    $sonuc = []; $say = [];
    foreach ($okuma['siparisler'] ?? [] as $s) {
        $r = frg_siparis_kaydet($pdo, $s, $ctx + ['dosya_sha' => (string)($okuma['dosya_sha'] ?? '')]);
        $r['po_no'] = (string)($s['po_no'] ?? '');
        $sonuc[] = $r;
        $say[$r['durum']] = ($say[$r['durum']] ?? 0) + 1;
    }
    return ['sonuclar' => $sonuc, 'sayac' => $say];
}

/**
 * Siparişe bağlı palet satırlarını değiştirir (PKL ekranı kullanır; her çağrıda hepsi yeniden yazılır).
 * Satır: palet_no (metin: '1', '1-DOOR'), konum (SOL/ORTA/SAĞ ya da 1-DOOR…), kalibre, lot, ggn_no, ggn_adi, kutu, brut_kg, net_kg.
 */
function frg_palet_kaydet(PDO $pdo, int $siparisId, array $paletler): int
{
    $sahip = !$pdo->inTransaction();
    if ($sahip) $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM frugro_palet WHERE siparis_id = ?')->execute([$siparisId]);
        $ek = $pdo->prepare('INSERT INTO frugro_palet (siparis_id, sira, palet_no, konum, kalibre, lot, ggn_no, ggn_adi, kutu, brut_kg, net_kg) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $n = 0;
        foreach (array_values($paletler) as $i => $p) {
            $ek->execute([$siparisId, $i + 1, frg_metin($p['palet_no'] ?? null) === null ? null : mb_substr((string)frg_metin($p['palet_no']), 0, 10),
                isset($p['konum']) ? mb_substr((string)frg_metin($p['konum']), 0, 30) ?: null : null,
                isset($p['kalibre']) ? mb_substr((string)frg_metin($p['kalibre']), 0, 20) ?: null : null,
                isset($p['lot']) ? mb_substr((string)frg_metin($p['lot']), 0, 30) ?: null : null,
                isset($p['ggn_no']) ? mb_substr((string)frg_metin($p['ggn_no']), 0, 20) ?: null : null,
                isset($p['ggn_adi']) ? mb_substr((string)frg_metin($p['ggn_adi']), 0, 100) ?: null : null,
                isset($p['kutu']) && $p['kutu'] !== '' ? (int)$p['kutu'] : null,
                isset($p['brut_kg']) && $p['brut_kg'] !== '' ? round((float)$p['brut_kg'], 3) : null,
                isset($p['net_kg']) && $p['net_kg'] !== '' ? round((float)$p['net_kg'], 3) : null]);
            $n++;
        }
        if ($sahip) $pdo->commit();
        return $n;
    } catch (Throwable $e) {
        if ($sahip && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
