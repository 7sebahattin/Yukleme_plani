<?php
// =========================================================
// config/uretim.php — Günlük Üretim (v507, Faz 1)
//
// Günlük İŞLENEN MAL (net kg) + personel takibinden çekilen işçi/saat ile
// işçi başına verim. Veri girişi dışarıdan (Excel / yapıştırma / elle).
//
//  • Tablolar: uretim_gunluk (gün+depo başına TEK satır), uretim_gunluk_kalem (Excel satırları;
//    Faz 3'te fiyatla çarpılacak). Kurulum YALNIZ migrate.php › «Günlük Üretim» kartı.
//    Tablo yoksa sayfalar net mesaj verir, hiçbir şey kırılmaz.
//  • İşçi sayısı/saat TEK kaynaktan: pdks_rapor_gunluk_trend() + pdks_faz8b_gun_mesai_ozeti()
//    — burada süre/FM hesabı YAZILMAZ.
//  • Excel okuma: ReadDataOnly, formül HESAPLANMAZ, dosya diske KALICI yazılmaz.
//  • Yetki: uretim.read / uretim.write / uretim.delete (admin her zaman). write+delete okumayı da ister.
// =========================================================
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

const URETIM_TABLO = 'uretim_gunluk';
const URETIM_KALEM = 'uretim_gunluk_kalem';
const URETIM_MAX_BAYT = 2097152;     // 2 MB / dosya
const URETIM_MAX_DOSYA = 40;         // tek seferde en çok dosya
const URETIM_MAX_SATIR = 600;        // dosya başına okunacak en çok satır
const URETIM_MAX_KG = 5000000;       // bir günün makul üst sınırı (yazım hatası koruması)

// ---------------------------------------------------------
// Yetki — TEK kaynak (sayfalar + menü aynı fonksiyonu çağırır)
// ---------------------------------------------------------
function can_uretim(string $perm): bool
{
    if (!function_exists('can')) return false;                       // fail-closed
    if (function_exists('is_admin') && is_admin()) return in_array($perm, ['read', 'write', 'delete'], true);
    $oku = can('uretim.read');
    return match ($perm) {
        'read'   => $oku,
        'write'  => $oku && can('uretim.write'),
        'delete' => $oku && can('uretim.delete'),
        default  => false,
    };
}

// ---------------------------------------------------------
// Şema
// ---------------------------------------------------------
/** @return array<string,string> tablo => CREATE TABLE */
function uretim_tablolar(): array
{
    $t = [];
    $t[URETIM_TABLO] = "CREATE TABLE IF NOT EXISTS `uretim_gunluk` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `work_date`  DATE          NOT NULL,
        `depo`       VARCHAR(150)  NOT NULL DEFAULT '',
        `net_kg`     INT           NOT NULL DEFAULT 0,
        `kasa`       INT           NOT NULL DEFAULT 0,
        `palet`      INT           NOT NULL DEFAULT 0,
        `kaynak`     VARCHAR(20)   NOT NULL DEFAULT 'elle',
        `dosya_adi`  VARCHAR(190)  NOT NULL DEFAULT '',
        `notu`       VARCHAR(255)  NOT NULL DEFAULT '',
        `created_by` INT           NULL DEFAULT NULL,
        `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_by` INT           NULL DEFAULT NULL,
        `updated_at` DATETIME      NULL DEFAULT NULL,
        UNIQUE KEY `uq_uretim_gun_depo` (`work_date`, `depo`),
        INDEX `idx_uretim_depo_tarih` (`depo`, `work_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $t[URETIM_KALEM] = "CREATE TABLE IF NOT EXISTS `uretim_gunluk_kalem` (
        `id`        INT AUTO_INCREMENT PRIMARY KEY,
        `gunluk_id` INT           NOT NULL,
        `urun`      VARCHAR(120)  NOT NULL DEFAULT '',
        `parti`     VARCHAR(120)  NOT NULL DEFAULT '',
        `ambalaj`   VARCHAR(160)  NOT NULL DEFAULT '',
        `mustahsil` VARCHAR(160)  NOT NULL DEFAULT '',
        `palet`     INT           NOT NULL DEFAULT 0,
        `adet`      INT           NOT NULL DEFAULT 0,
        `net_kg`    INT           NOT NULL DEFAULT 0,
        INDEX `idx_uretim_kalem_gunluk` (`gunluk_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    return $t;
}

function uretim_tablo_var(PDO $pdo, string $tablo): bool
{
    try {
        $pdo->query('SELECT 1 FROM `' . $tablo . '` LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function uretim_hazir(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? db();
    return uretim_tablo_var($pdo, URETIM_TABLO) && uretim_tablo_var($pdo, URETIM_KALEM);
}

/** Yalnız ekleyici (CREATE TABLE IF NOT EXISTS). @return list<array{adim:string,durum:string,mesaj:string}> */
function uretim_migrate(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $rapor = [];
    foreach (uretim_tablolar() as $ad => $sql) {
        $var = uretim_tablo_var($pdo, $ad);
        try {
            $pdo->exec($sql);
            $rapor[] = ['adim' => $ad, 'durum' => $var ? 'zaten_var' : 'olusturuldu', 'mesaj' => $var ? 'Tablo zaten var.' : 'Tablo oluşturuldu.'];
        } catch (Throwable $e) {
            $rapor[] = ['adim' => $ad, 'durum' => 'hata', 'mesaj' => $e->getMessage()];
        }
    }
    return $rapor;
}

// ---------------------------------------------------------
// Metin / sayı yardımcıları
// ---------------------------------------------------------
/** Boşlukları (NBSP dahil) tek boşluğa indirir, kırpar, uzunluğu sınırlar; kontrol karakterlerini atar. */
function uretim_metin($v, int $max = 160): string
{
    $s = is_scalar($v) ? (string)$v : '';
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    $s = preg_replace('/[\s\x{00A0}]+/u', ' ', $s) ?? '';
    $s = trim($s);
    return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8') : $s;
}

/** "1.575" · "1575" · "1575 " · 1575.0 → 1575; geçersiz → null. Kilo/adet tam sayıdır (ondalık yuvarlanır). */
function uretim_sayi($v): ?int
{
    if (is_int($v)) return $v;
    if (is_float($v)) return is_finite($v) ? (int)round($v) : null;
    $s = uretim_metin($v, 40);
    if ($s === '') return null;
    $s = str_replace(' ', '', $s);
    if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) $s = str_replace(['.', ','], ['', '.'], $s);   // 1.575,5
    elseif (preg_match('/^\d+,\d+$/', $s)) $s = str_replace(',', '.', $s);                             // 12,5
    return is_numeric($s) ? (int)round((float)$s) : null;
}

/** "07.11.2026" · "2026-11-07" · "7/11/2026" → "2026-11-07"; geçersiz/gelecek değil mi denetimi çağırana. */
function uretim_tarih($v): ?string
{
    if ($v instanceof DateTimeInterface) return $v->format('Y-m-d');
    if (is_int($v) || is_float($v)) {   // Excel seri tarihi
        if ($v < 36526 || $v > 80000) return null;
        return gmdate('Y-m-d', (int)(($v - 25569) * 86400));
    }
    $s = uretim_metin($v, 30);
    if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', $s, $m)) [$g, $a, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) [$y, $a, $g] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    else return null;
    if ($y < 2020 || !checkdate($a, $g, $y)) return null;
    return sprintf('%04d-%02d-%02d', $y, $a, $g);
}

function uretim_fold(string $s): string
{
    $s = mb_strtolower(strtr(uretim_metin($s, 80), ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
    return strtr($s, ['ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c']);
}

// ---------------------------------------------------------
// Yapıştırılan toplu metin: "tarih ; net kg ; kasa ; palet" (kasa/palet isteğe bağlı)
// ---------------------------------------------------------
/** @return array{gunler:list<array>,hatalar:list<string>} */
function uretim_toplu_ayristir(string $metin): array
{
    $gunler = []; $hatalar = []; $goruldu = [];
    $satirlar = preg_split('/\R/u', $metin) ?: [];
    foreach ($satirlar as $i => $ham) {
        if (trim($ham) === '') continue;
        if (count($gunler) + count($hatalar) >= 800) { $hatalar[] = 'En çok 800 satır işlenir; fazlası atlandı.'; break; }
        $no = $i + 1;
        $p = preg_split('/[;\t]+/u', trim($ham)) ?: [];
        if (count($p) < 2) $p = preg_split('/\s{2,}|,(?=\d)/u', trim($ham)) ?: $p;
        if ($no === 1 && uretim_tarih($p[0] ?? '') === null && !is_numeric(trim((string)($p[0] ?? '')))) continue;   // başlık satırı
        $tarih = uretim_tarih($p[0] ?? '');
        $kg = uretim_sayi($p[1] ?? null);
        if ($tarih === null) { $hatalar[] = "Satır $no: tarih okunamadı («" . uretim_metin($p[0] ?? '', 30) . '»).'; continue; }
        if ($kg === null || $kg <= 0) { $hatalar[] = "Satır $no: net kg okunamadı veya sıfır."; continue; }
        if (isset($goruldu[$tarih])) { $hatalar[] = "Satır $no: $tarih tarihi listede birden fazla — ilki alındı."; continue; }
        $goruldu[$tarih] = true;
        $gunler[] = ['tarih' => $tarih, 'net_kg' => $kg, 'kasa' => max(0, (int)(uretim_sayi($p[2] ?? null) ?? 0)),
                     'palet' => max(0, (int)(uretim_sayi($p[3] ?? null) ?? 0)), 'kalemler' => [], 'kaynak' => 'toplu', 'dosya' => ''];
    }
    return ['gunler' => $gunler, 'hatalar' => $hatalar];
}

// ---------------------------------------------------------
// Excel okuma — iki biçim otomatik tanınır:
//   (a) «Sipariş Durum Listesi»: 2. satırda "İlk Tarih:gg.aa.yyyy Son Tarih:gg.aa.yyyy", sütunlar
//       Ürün·Parti No·Ambalaj·Müstahsil·Palet Toplamı·Adet·Net Kilo  → TEK gün + kalemler
//   (b) Toplu tablo: başlıkta Tarih + Net Kilo (+ Adet/Kasa, Palet)  → gün başına bir satır
// ---------------------------------------------------------
/**
 * @return array{ok:bool,hata?:string,mod?:string,gunler:list<array>,uyarilar:list<string>}
 */
function uretim_excel_oku(string $yol, string $dosyaAdi = ''): array
{
    $son = ['ok' => false, 'gunler' => [], 'uyarilar' => []];
    if (!function_exists('xlsx_motor_yukle')) return ['hata' => 'Excel motoru yüklü değil.'] + $son;
    xlsx_motor_yukle();
    $lim = ini_get('memory_limit');
    if ($lim !== false && $lim !== '-1' && function_exists('xlsx_bayt') && xlsx_bayt((string)$lim) < 192 * 1048576) @ini_set('memory_limit', '192M');
    @set_time_limit(60);
    $ss = null;
    try {
        $r = IOFactory::createReader('Xlsx');
        $r->setReadDataOnly(true);
        $r->setReadEmptyCells(false);
        $adlar = $r->listWorksheetNames($yol);
        if (!$adlar) return ['hata' => 'Dosyada sayfa bulunamadı.'] + $son;
        $r->setLoadSheetsOnly([(string)$adlar[0]]);
        $r->setReadFilter(new class(URETIM_MAX_SATIR) implements IReadFilter {
            public function __construct(private int $en) {}
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->en && Coordinate::columnIndexFromString($columnAddress) <= 14;
            }
        });
        $ss = $r->load($yol);
        $ws = $ss->getActiveSheet();
        $h = [];   // [satır][sütun] = değer
        foreach ($ws->getCoordinates() as $koor) {
            $c = $ws->getCell($koor);
            if ($c->getDataType() === DataType::TYPE_FORMULA) {          // formül HESAPLANMAZ; yalnız önbellek
                $v = $c->getOldCalculatedValue();
            } else {
                $v = $c->getValue();
            }
            if (is_object($v)) $v = method_exists($v, 'getPlainText') ? $v->getPlainText() : '';
            if ($v === null || $v === '' || !is_scalar($v)) continue;
            [$sh, $sr] = Coordinate::coordinateFromString($koor);
            $h[(int)$sr][Coordinate::columnIndexFromString($sh)] = $v;
        }
        ksort($h);
    } catch (Throwable $e) {
        return ['hata' => 'Excel dosyası okunamadı (geçerli bir .xlsx mi?).'] + $son;
    } finally {
        if ($ss !== null) { $ss->disconnectWorksheets(); unset($ss); }
    }
    if (!$h) return ['hata' => 'Dosya boş görünüyor.'] + $son;

    // Başlık satırı: ilk 8 satırda «Net Kilo/Net Kg» + («Ürün» ya da «Tarih»)
    $baslik = null; $harita = [];
    foreach ($h as $sr => $hucreler) {
        if ($sr > 8) break;
        $m = [];
        foreach ($hucreler as $si => $v) {
            $f = uretim_fold((string)$v);
            $k = match (true) {
                $f === 'urun' => 'urun',
                in_array($f, ['parti no', 'parti'], true) => 'parti',
                $f === 'ambalaj' => 'ambalaj',
                $f === 'mustahsil' => 'mustahsil',
                in_array($f, ['palet toplami', 'palet'], true) => 'palet',
                in_array($f, ['adet', 'kasa', 'kasa adedi', 'kasa adet'], true) => 'adet',
                in_array($f, ['net kilo', 'net kg', 'net', 'kg'], true) => 'net_kg',
                $f === 'tarih' => 'tarih',
                default => null,
            };
            if ($k !== null && !isset($m[$k])) $m[$k] = $si;
        }
        if (isset($m['net_kg']) && (isset($m['urun']) || isset($m['tarih']))) { $baslik = $sr; $harita = $m; break; }
    }
    if ($baslik === null) return ['hata' => 'Başlık satırı bulunamadı — «Ürün … Net Kilo» (günlük liste) ya da «Tarih · Net Kilo» (toplu tablo) başlıkları gerekir.'] + $son;
    $al = static fn(array $hc, string $k) => isset($harita[$k]) ? ($hc[$harita[$k]] ?? null) : null;

    // ── (b) Toplu tablo ──────────────────────────────────────────────
    if (isset($harita['tarih'])) {
        $gunler = []; $uyari = []; $goruldu = [];
        foreach ($h as $sr => $hc) {
            if ($sr <= $baslik) continue;
            $tr = $al($hc, 'tarih');
            if ($tr === null) continue;
            $tarih = uretim_tarih($tr);
            $kg = uretim_sayi($al($hc, 'net_kg'));
            if ($tarih === null) { $uyari[] = "Satır $sr: tarih okunamadı, atlandı."; continue; }
            if ($kg === null || $kg <= 0) { $uyari[] = "Satır $sr ($tarih): net kg boş/sıfır, atlandı."; continue; }
            if (isset($goruldu[$tarih])) { $uyari[] = "Satır $sr: $tarih birden fazla — ilki alındı."; continue; }
            $goruldu[$tarih] = true;
            $gunler[] = ['tarih' => $tarih, 'net_kg' => $kg, 'kasa' => max(0, (int)(uretim_sayi($al($hc, 'adet')) ?? 0)),
                         'palet' => max(0, (int)(uretim_sayi($al($hc, 'palet')) ?? 0)), 'kalemler' => [], 'kaynak' => 'toplu', 'dosya' => uretim_metin($dosyaAdi, 190)];
        }
        if (!$gunler) return ['hata' => 'Toplu tabloda geçerli gün satırı bulunamadı.', 'uyarilar' => $uyari] + $son;
        return ['ok' => true, 'mod' => 'toplu', 'gunler' => $gunler, 'uyarilar' => $uyari];
    }

    // ── (a) Günlük liste: tarih 1–3. satırlarda "İlk Tarih:… Son Tarih:…" ──────
    $ilk = null; $sonT = null;
    foreach ($h as $sr => $hc) {
        if ($sr > $baslik) break;
        // «İlk» büyük noktalı İ ile gelir; PCRE /i bunu «ilk»e eşlemez → önce Türkçe-duyarsız katla.
        $satirMetni = uretim_fold(implode(' ', array_map(static fn($v) => (string)$v, $hc)));
        if (preg_match('/ilk\s*tarih\s*:?\s*(\S+)\s+son\s*tarih\s*:?\s*(\S+)/u', $satirMetni, $mm)) { $ilk = uretim_tarih($mm[1]); $sonT = uretim_tarih($mm[2]); break; }
    }
    if ($ilk === null || $sonT === null) return ['hata' => 'Tarih bulunamadı — 2. satırda «İlk Tarih:gg.aa.yyyy Son Tarih:gg.aa.yyyy» olmalı.'] + $son;
    if ($ilk !== $sonT) return ['hata' => "Bu liste birden çok günü kapsıyor ($ilk – $sonT). Günlere bölünemez; her gün için ayrı liste alın ya da «Toplu» sekmesini kullanın."] + $son;

    $kalemler = []; $uyari = []; $sPalet = 0; $sAdet = 0; $sKg = 0; $altToplam = null;
    foreach ($h as $sr => $hc) {
        if ($sr <= $baslik) continue;
        $urun = uretim_metin($al($hc, 'urun'), 120);
        $kg = uretim_sayi($al($hc, 'net_kg'));
        $adet = uretim_sayi($al($hc, 'adet'));
        if ($urun === '') {                                           // ürünsüz + sayılı satır = alt toplam
            if ($kg !== null || $adet !== null) $altToplam = ['adet' => (int)($adet ?? 0), 'net_kg' => (int)($kg ?? 0)];
            continue;
        }
        if ($kg === null || $kg < 0) { $uyari[] = "Satır $sr ($urun): net kilo okunamadı, atlandı."; continue; }
        $pal = uretim_sayi($al($hc, 'palet')) ?? 0;
        $kalemler[] = ['urun' => $urun, 'parti' => uretim_metin($al($hc, 'parti'), 120), 'ambalaj' => uretim_metin($al($hc, 'ambalaj'), 160),
                       'mustahsil' => uretim_metin($al($hc, 'mustahsil'), 160), 'palet' => max(0, $pal), 'adet' => max(0, (int)($adet ?? 0)), 'net_kg' => $kg];
        $sPalet += max(0, $pal); $sAdet += max(0, (int)($adet ?? 0)); $sKg += $kg;
    }
    if (!$kalemler) return ['hata' => 'Listede ürün satırı bulunamadı.', 'uyarilar' => $uyari] + $son;
    if ($altToplam !== null && ($altToplam['net_kg'] !== $sKg || $altToplam['adet'] !== $sAdet)) {
        $uyari[] = 'Dosyadaki alt toplam (' . number_format($altToplam['net_kg'], 0, ',', '.') . ' kg / ' . number_format($altToplam['adet'], 0, ',', '.') . ' kasa) satırların toplamıyla ('
                 . number_format($sKg, 0, ',', '.') . ' kg / ' . number_format($sAdet, 0, ',', '.') . ' kasa) TUTMUYOR — kontrol edin.';
    }
    return ['ok' => true, 'mod' => 'gunluk', 'uyarilar' => $uyari, 'gunler' => [[
        'tarih' => $ilk, 'net_kg' => $sKg, 'kasa' => $sAdet, 'palet' => $sPalet, 'kalemler' => $kalemler, 'kaynak' => 'excel', 'dosya' => uretim_metin($dosyaAdi, 190),
    ]]];
}

// ---------------------------------------------------------
// Doğrulama + kayıt
// ---------------------------------------------------------
/** Bir gün kaydını yazmadan önce denetler. @return list<string> hata listesi */
function uretim_gun_dogrula(array $g): array
{
    $e = [];
    $t = (string)($g['tarih'] ?? '');
    if (uretim_tarih($t) !== $t) $e[] = 'Tarih geçersiz.';
    elseif ($t > date('Y-m-d')) $e[] = $t . ': gelecek tarihli üretim girilemez.';
    $kg = (int)($g['net_kg'] ?? 0);
    if ($kg <= 0) $e[] = ($t ?: 'Gün') . ': net kg sıfırdan büyük olmalı.';
    elseif ($kg > URETIM_MAX_KG) $e[] = $t . ': net kg ' . number_format($kg, 0, ',', '.') . ' çok büyük (en çok ' . number_format(URETIM_MAX_KG, 0, ',', '.') . ') — yazım hatası mı?';
    foreach (['kasa', 'palet'] as $a) if ((int)($g[$a] ?? 0) < 0) $e[] = $t . ': ' . $a . ' negatif olamaz.';
    return $e;
}

/**
 * Bir günü yazar. Aynı gün+depo varsa $ustune=false → ['ok'=>false,'var'=>true]; true → üzerine yazar (kalemler yenilenir).
 * Tx/yetki/CSRF/audit ÇAĞIRANDA değildir: bu fonksiyon tx'i kendisi açar; yetki+CSRF çağıranda, audit burada.
 * @return array{ok:bool,id?:int,var?:bool,hata?:string,eylem?:string}
 */
function uretim_gun_kaydet(PDO $pdo, array $g, string $depo, ?int $userId, bool $ustune): array
{
    $hata = uretim_gun_dogrula($g);
    if ($hata) return ['ok' => false, 'hata' => implode(' ', $hata)];
    if (!uretim_hazir($pdo)) return ['ok' => false, 'hata' => 'Günlük Üretim tabloları kurulu değil (migrate.php › Günlük Üretim).'];

    $tarih = (string)$g['tarih'];
    $kaynak = in_array($g['kaynak'] ?? '', ['excel', 'toplu', 'elle'], true) ? (string)$g['kaynak'] : 'elle';
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM ' . URETIM_TABLO . ' WHERE work_date = ? AND depo = ?');
        $st->execute([$tarih, $depo]);
        $eski = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($eski !== null && !$ustune) { if ($own) $pdo->rollBack(); return ['ok' => false, 'var' => true]; }

        $vals = [(int)$g['net_kg'], max(0, (int)($g['kasa'] ?? 0)), max(0, (int)($g['palet'] ?? 0)), $kaynak,
                 uretim_metin($g['dosya'] ?? '', 190), uretim_metin($g['notu'] ?? '', 255)];
        if ($eski === null) {
            $pdo->prepare('INSERT INTO ' . URETIM_TABLO . ' (work_date, depo, net_kg, kasa, palet, kaynak, dosya_adi, notu, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute(array_merge([$tarih, $depo], $vals, [$userId]));
            $id = (int)$pdo->lastInsertId();
            $eylem = 'create';
        } else {
            $id = (int)$eski['id'];
            $pdo->prepare('UPDATE ' . URETIM_TABLO . ' SET net_kg=?, kasa=?, palet=?, kaynak=?, dosya_adi=?, notu=?, updated_by=?, updated_at=? WHERE id=?')
                ->execute(array_merge($vals, [$userId, date('Y-m-d H:i:s'), $id]));
            $pdo->prepare('DELETE FROM ' . URETIM_KALEM . ' WHERE gunluk_id = ?')->execute([$id]);
            $eylem = 'update';
        }
        $kalemler = is_array($g['kalemler'] ?? null) ? array_slice($g['kalemler'], 0, 300) : [];
        $ik = $pdo->prepare('INSERT INTO ' . URETIM_KALEM . ' (gunluk_id, urun, parti, ambalaj, mustahsil, palet, adet, net_kg) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($kalemler as $k) {
            $ik->execute([$id, uretim_metin($k['urun'] ?? '', 120), uretim_metin($k['parti'] ?? '', 120), uretim_metin($k['ambalaj'] ?? '', 160),
                          uretim_metin($k['mustahsil'] ?? '', 160), max(0, (int)($k['palet'] ?? 0)), max(0, (int)($k['adet'] ?? 0)), max(0, (int)($k['net_kg'] ?? 0))]);
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'hata' => 'Kayıt yazılamadı.'];
    }
    if (function_exists('audit_log_event')) {
        audit_log_event($eylem, 'uretim_gunluk', $id,
            $eski === null ? null : ['net_kg' => (int)$eski['net_kg'], 'kasa' => (int)$eski['kasa'], 'palet' => (int)$eski['palet']],
            ['work_date' => $tarih, 'depo' => $depo, 'net_kg' => (int)$g['net_kg'], 'kasa' => (int)($g['kasa'] ?? 0), 'palet' => (int)($g['palet'] ?? 0), 'kaynak' => $kaynak, 'kalem' => count($kalemler)]);
    }
    return ['ok' => true, 'id' => $id, 'eylem' => $eylem];
}

/** Bir gün kaydını (ve kalemlerini) siler; audit yazar. */
function uretim_gun_sil(PDO $pdo, int $id, string $depo): bool
{
    $st = $pdo->prepare('SELECT * FROM ' . URETIM_TABLO . ' WHERE id = ? AND depo = ?');
    $st->execute([$id, $depo]);
    $eski = $st->fetch(PDO::FETCH_ASSOC);
    if (!$eski) return false;
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM ' . URETIM_KALEM . ' WHERE gunluk_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM ' . URETIM_TABLO . ' WHERE id = ?')->execute([$id]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return false;
    }
    if (function_exists('audit_log_event')) {
        audit_log_event('delete', 'uretim_gunluk', $id, ['work_date' => $eski['work_date'], 'depo' => $eski['depo'], 'net_kg' => (int)$eski['net_kg']], null);
    }
    return true;
}

// ---------------------------------------------------------
// Rapor verisi
// ---------------------------------------------------------
/** "2026-11" doğrulaması; geçersizse null. */
function uretim_ay_gecerli(string $ay): ?string
{
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ay) && substr($ay, 0, 4) >= '2020' ? $ay : null;
}

/** @return array{0:string,1:string} ayın ilk ve son günü */
function uretim_ay_araligi(string $ay): array
{
    $ilk = $ay . '-01';
    return [$ilk, date('Y-m-t', strtotime($ilk))];
}

/** Bölme: payda 0/null → null. */
function uretim_oran($pay, $payda, int $ondalik = 0): ?float
{
    if ($payda === null || (float)$payda <= 0 || $pay === null) return null;
    return round((float)$pay / (float)$payda, $ondalik);
}

/**
 * Bir ayın gün gün tablosu: üretim (uretim_gunluk) + personel (işçi sayısı / çalışma saati).
 * Çalışma saati yalnız Faz 8B şeması hazırsa dolar (aksi halde null → kg/saat "—").
 *
 * @return array{gunler:list<array>,ozet:array,personel_var:bool,saat_var:bool}
 */
function uretim_ay_verisi(PDO $pdo, string $ay, string $depo): array
{
    [$bas, $bit] = uretim_ay_araligi($ay);
    $gun = [];
    for ($t = $bas; $t <= $bit; $t = date('Y-m-d', strtotime($t . ' +1 day'))) {
        $gun[$t] = ['tarih' => $t, 'net_kg' => null, 'kasa' => null, 'palet' => null, 'kaynak' => '', 'id' => null, 'notu' => '',
                    'isci' => 0, 'cavus' => 0, 'saat' => null, 'fm_saat' => null];
    }
    if (uretim_hazir($pdo)) {
        $st = $pdo->prepare('SELECT id, work_date, net_kg, kasa, palet, kaynak, notu FROM ' . URETIM_TABLO . ' WHERE depo = ? AND work_date BETWEEN ? AND ?');
        $st->execute([$depo, $bas, $bit]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $g = &$gun[$r['work_date']];
            $g['id'] = (int)$r['id']; $g['net_kg'] = (int)$r['net_kg']; $g['kasa'] = (int)$r['kasa']; $g['palet'] = (int)$r['palet'];
            $g['kaynak'] = (string)$r['kaynak']; $g['notu'] = (string)$r['notu'];
            unset($g);
        }
    }

    $personelVar = false; $saatVar = false;
    try {
        if (function_exists('pdks_gunluk_faz8a_sema_hazir') && pdks_gunluk_faz8a_sema_hazir($pdo) && function_exists('pdks_rapor_gunluk_trend')) {
            $personelVar = true;
            foreach (pdks_rapor_gunluk_trend($bas, $bit, $depo, null, null, $pdo) as $tr) {
                if (!isset($gun[$tr['tarih']])) continue;
                $gun[$tr['tarih']]['isci'] = (int)$tr['toplam_calisan'];
                $gun[$tr['tarih']]['cavus'] = (int)$tr['aktif_cavus'];
            }
        }
        if ($personelVar && function_exists('pdks_faz8b_sema_hazir') && pdks_faz8b_sema_hazir($pdo) && function_exists('pdks_faz8b_gun_mesai_ozeti')) {
            $saatVar = true;
            $st = $pdo->prepare('SELECT id, work_date FROM daily_work_sessions WHERE depo = ? AND work_date BETWEEN ? AND ?');
            $st->execute([$depo, $bas, $bit]);
            $oturum = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $oturum[$r['work_date']][] = (int)$r['id'];
            foreach ($oturum as $t => $ids) {
                if (!isset($gun[$t])) continue;
                $oz = pdks_faz8b_gun_mesai_ozeti($ids, $pdo);
                if ($oz === null) continue;
                $gun[$t]['saat'] = round(((int)$oz['calisma_dk']) / 60, 1);
                $gun[$t]['fm_saat'] = (int)$oz['fm_saat'];
            }
        }
    } catch (Throwable $e) {
        error_log('[uretim] personel verisi okunamadı: ' . $e->getMessage());
    }

    // Günlük oranlar + eşleşme durumu
    $top = ['kg' => 0, 'kasa' => 0, 'palet' => 0, 'isci' => 0, 'saat' => 0.0, 'gun' => 0, 'eslesen_kg' => 0, 'eslesen_isci' => 0, 'eslesen_saat' => 0.0, 'eslesen_gun' => 0,
            'uretimsiz_isci_gun' => 0, 'personelsiz_gun' => 0];
    foreach ($gun as &$g) {
        $g['kg_isci'] = uretim_oran($g['net_kg'], $g['isci']);
        $g['kg_saat'] = uretim_oran($g['net_kg'], $g['saat']);
        $g['kasa_isci'] = uretim_oran($g['kasa'], $g['isci']);
        $g['durum'] = match (true) {
            $g['net_kg'] === null && $g['isci'] === 0 => 'bos',
            $g['net_kg'] === null => 'uretim_yok',       // işçi çalışmış ama mal girilmemiş
            $g['isci'] === 0 => 'personel_yok',          // mal girilmiş ama işçi kaydı yok
            default => 'tamam',
        };
        if ($g['net_kg'] !== null) { $top['kg'] += $g['net_kg']; $top['kasa'] += (int)$g['kasa']; $top['palet'] += (int)$g['palet']; $top['gun']++; }
        if ($g['durum'] === 'uretim_yok') $top['uretimsiz_isci_gun']++;
        if ($g['durum'] === 'personel_yok') $top['personelsiz_gun']++;
        if ($g['durum'] === 'tamam') {
            // Ağırlıklı ortalama YALNIZ hem üretimi hem işçisi olan günlerden (yarım veriyle oran şişmesin).
            $top['eslesen_kg'] += $g['net_kg']; $top['eslesen_isci'] += $g['isci']; $top['eslesen_gun']++;
            if ($g['saat'] !== null) { $top['eslesen_saat'] += $g['saat']; }
        }
        $top['isci'] += $g['isci'];
    }
    unset($g);
    $saatliKg = 0;
    foreach ($gun as $g) if ($g['durum'] === 'tamam' && $g['saat'] !== null && $g['saat'] > 0) $saatliKg += $g['net_kg'];
    $top['kg_isci'] = uretim_oran($top['eslesen_kg'], $top['eslesen_isci']);
    $top['kg_saat'] = $top['eslesen_saat'] > 0 ? uretim_oran($saatliKg, $top['eslesen_saat']) : null;
    $top['kasa_isci'] = null;
    $kasaE = 0;
    foreach ($gun as $g) if ($g['durum'] === 'tamam') $kasaE += (int)$g['kasa'];
    $top['kasa_isci'] = uretim_oran($kasaE, $top['eslesen_isci']);
    $top['gunluk_ort_kg'] = uretim_oran($top['kg'], $top['gun']);
    return ['gunler' => array_values($gun), 'ozet' => $top, 'personel_var' => $personelVar, 'saat_var' => $saatVar];
}

/**
 * Son N ayın aylık özeti (kg, gün, işçi, kg/işçi). Saat HESAPLANMAZ (12 ay için pahalı); ay detayında vardır.
 * @return list<array{ay:string,kg:int,gun:int,isci:int,kg_isci:?float}>
 */
function uretim_aylik_ozet(PDO $pdo, string $depo, string $bitisAy, int $adet = 12): array
{
    $adet = max(1, min(24, $adet));
    $aylar = [];
    $c = strtotime($bitisAy . '-01');
    for ($i = $adet - 1; $i >= 0; $i--) $aylar[] = date('Y-m', strtotime("-$i month", $c));
    $out = [];
    foreach ($aylar as $a) $out[$a] = ['ay' => $a, 'kg' => 0, 'gun' => 0, 'isci' => 0, 'eslesen_kg' => 0, 'eslesen_isci' => 0, 'kg_isci' => null];
    if (!uretim_hazir($pdo)) return array_values($out);
    $bas = $aylar[0] . '-01'; $bit = date('Y-m-t', $c);
    $st = $pdo->prepare('SELECT work_date, net_kg FROM ' . URETIM_TABLO . ' WHERE depo = ? AND work_date BETWEEN ? AND ?');
    $st->execute([$depo, $bas, $bit]);
    $kg = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $a = substr((string)$r['work_date'], 0, 7);
        $kg[(string)$r['work_date']] = (int)$r['net_kg'];
        if (isset($out[$a])) { $out[$a]['kg'] += (int)$r['net_kg']; $out[$a]['gun']++; }
    }
    try {
        if (function_exists('pdks_gunluk_faz8a_sema_hazir') && pdks_gunluk_faz8a_sema_hazir($pdo) && function_exists('pdks_rapor_gunluk_trend')) {
            foreach (pdks_rapor_gunluk_trend($bas, $bit, $depo, null, null, $pdo) as $tr) {
                $a = substr((string)$tr['tarih'], 0, 7);
                if (!isset($out[$a])) continue;
                $out[$a]['isci'] += (int)$tr['toplam_calisan'];
                if (isset($kg[$tr['tarih']]) && (int)$tr['toplam_calisan'] > 0) { $out[$a]['eslesen_kg'] += $kg[$tr['tarih']]; $out[$a]['eslesen_isci'] += (int)$tr['toplam_calisan']; }
            }
        }
    } catch (Throwable $e) {
        error_log('[uretim] aylık personel verisi okunamadı: ' . $e->getMessage());
    }
    foreach ($out as &$o) { $o['kg_isci'] = uretim_oran($o['eslesen_kg'], $o['eslesen_isci']); }
    unset($o);
    return array_values($out);
}
