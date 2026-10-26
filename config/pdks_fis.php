<?php
declare(strict_types=1);
// =========================================================
// config/pdks_fis.php — "Fiş Excel'i → mesai kayıtları" (v446)
//
// Kâğıt "İŞLETME İŞÇİ FİŞİ"nin dış bir yapay zekâyla Excel'e (şablon) döküldüğü dosyadan, fiş başına
// kartsız mesai kayıtları (Tam / FM-k / Yarım gruplar) + servis yazar. OCR/AI uygulamanın İÇİNDE YOK.
//
// Bu dosya: sütun tanımı (TEK kaynak) · dosya doğrulama/okuma · ayrıştırma · çavuş eşleştirme ·
// saf plan (kova/dağıtım/çıkış saatleri) · DB'li değerlendirme · yazma orkestratörü · şablon üretimi.
//
// DEĞİŞMEZLER (CLAUDE.md "Fiş Excel'i Yükle (v446)"):
//  - İKİNCİ YAZMA YOLU YOK: dönem/olay/sanal kart yazımı yalnız pdks_faz8j_toplu_ekle() (opt-in
//    coklu_grup + yazdiktan_sonra + audit_ek), servis yalnız pdks_servis_ekle(), Tam/Yarım/FM onayı
//    yalnız pdks_faz8b_degerlendirme_kaydet(), sınıf/FM hesabı yalnız pdks_faz8b_donem_siniflandir().
//  - Rastgelelik YOK (random_int/mt_rand/shuffle): dağıtım tamamen tamsayı aritmetiği, aynı girdi
//    her zaman aynı sonuç. Excel hücreleri ASLA formül olarak değerlendirilmez (getCalculatedValue YOK).
//  - Excel'den gelen serbest metin pdks_fis_metin() ile temizlenir; Ham Metin / Kontrol Notu
//    DB'ye ve audit'e YAZILMAZ.
//  - Şema/migration YOK, yeni yetki YOK (kapı: pdks_faz8j_yetki() = is_admin()).
// =========================================================

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

require_once __DIR__ . '/pdks_faz8j.php';
require_once __DIR__ . '/pdks_faz8b.php';
require_once __DIR__ . '/pdks_servis.php';
require_once __DIR__ . '/xlsx_export.php';

if (!defined('PDKS_FIS_MAX_BAYT'))        define('PDKS_FIS_MAX_BAYT', 2097152);      // 2 MB
if (!defined('PDKS_FIS_MAX_FIS'))         define('PDKS_FIS_MAX_FIS', 150);
if (!defined('PDKS_FIS_SAYFA'))           define('PDKS_FIS_SAYFA', 'Fişler');
if (!defined('PDKS_FIS_VARSAYILAN_GIRIS')) define('PDKS_FIS_VARSAYILAN_GIRIS', '08:00');
if (!defined('PDKS_FIS_FM_KOVA_MAX'))     define('PDKS_FIS_FM_KOVA_MAX', 6);
if (!defined('PDKS_FIS_FM_KISI_UST_SAAT')) define('PDKS_FIS_FM_KISI_UST_SAAT', 3); // türetilen K için kişi başı üst
if (!defined('PDKS_FIS_TOKEN_TTL'))       define('PDKS_FIS_TOKEN_TTL', 1800);
if (!defined('PDKS_FIS_SABLON_SURUMU'))   define('PDKS_FIS_SABLON_SURUMU', 1);
if (!defined('PDKS_FIS_ETIKET'))          define('PDKS_FIS_ETIKET', '[FIS ');          // servis not öneki

// =========================================================
// A. SÜTUN TANIMI (TEK KAYNAK) + KATLAMA
// =========================================================

/**
 * Şablonun "Fişler" sayfası sütunları (sıralı; harf = indeks + 1). Şablon üretimi, Açıklama sayfası,
 * başlık eşleme ve testler HEPSİ buradan beslenir.
 * tip: metin | tarih | tam | saat | liste.
 * @return list<array{anahtar:string,baslik:string,tip:string,zorunlu:bool,genislik:int,maks:?int,liste:?array,ipucu:string,takma:list<string>}>
 */
function pdks_fis_sutunlar(): array
{
    static $s = null;
    if ($s !== null) return $s;
    $k = static function (string $anahtar, string $baslik, string $tip, bool $zorunlu, int $genislik, ?int $maks, ?array $liste, string $ipucu, array $takma = []): array {
        return compact('anahtar', 'baslik', 'tip', 'zorunlu', 'genislik', 'maks', 'liste', 'ipucu', 'takma');
    };
    $s = [
        $k('fis_no', 'Fiş No', 'metin', true, 12, 30, null, 'Fişteki numara; METİN olarak yazın, baştaki sıfırı koruyun (01410).', ['Fis No', 'Fiş Numarası', 'Fis Numarasi', 'Fiş No.']),
        $k('tarih', 'Tarih', 'tarih', true, 12, null, null, 'GG.AA.YYYY. Bugünden ÖNCE olmalı (bugünün fişi desteklenmez).', ['Fiş Tarihi', 'Fis Tarihi']),
        $k('isletme', 'İşletme', 'metin', false, 16, 150, null, 'Fişte yazdığı gibi; yalnız kontrol amaçlı (aktif depoyla karşılaştırılır).', ['Isletme']),
        $k('cavus_kodu', 'Çavuş Kodu', 'liste', false, 12, 40, null, 'Çavuşlar sayfasındaki kod (birincil eşleştirme).', ['Cavus Kodu', 'Çavuş Kod']),
        $k('cavus_adi', 'Çavuş Adı', 'metin', false, 20, 150, null, 'Fişteki çavuş; Çavuş Kodu boşsa eşleştirme için kullanılır.', ['Cavus Adi', 'Çavuş', 'Cavus']),
        $k('kadin_tam', 'Kadın Tam', 'tam', false, 9, 250, null, 'Bayan sayısı (Tam gün).', ['Kadin Tam', 'Bayan Tam']),
        $k('kadin_yarim', 'Kadın Yarım', 'tam', false, 9, 250, null, 'Yarım gün çalışan kadın sayısı.', ['Kadin Yarim', 'Bayan Yarım', 'Bayan Yarim']),
        $k('erkek_tam', 'Erkek Tam', 'tam', false, 9, 250, null, 'Erkek sayısı (Tam gün).', []),
        $k('erkek_yarim', 'Erkek Yarım', 'tam', false, 9, 250, null, 'Yarım gün çalışan erkek sayısı.', ['Erkek Yarim']),
        $k('rampaci_tam', 'Rampacı Tam', 'tam', false, 9, 250, null, 'Rampacı sayısı (Tam gün).', ['Rampaci Tam']),
        $k('rampaci_yarim', 'Rampacı Yarım', 'tam', false, 9, 250, null, 'Yarım gün çalışan rampacı sayısı.', ['Rampaci Yarim']),
        $k('fm1', 'FM 1 Sa (Kişi)', 'tam', false, 9, 250, null, 'Tam 1 saat fazla mesai yapan KİŞİ sayısı.', ['FM 1 Saat', 'FM 1 Sa']),
        $k('fm2', 'FM 2 Sa (Kişi)', 'tam', false, 9, 250, null, 'Tam 2 saat fazla mesai yapan KİŞİ sayısı.', ['FM 2 Saat', 'FM 2 Sa']),
        $k('fm3', 'FM 3 Sa (Kişi)', 'tam', false, 9, 250, null, 'Tam 3 saat fazla mesai yapan KİŞİ sayısı.', ['FM 3 Saat', 'FM 3 Sa']),
        $k('fm4', 'FM 4 Sa (Kişi)', 'tam', false, 9, 250, null, 'Tam 4 saat fazla mesai yapan KİŞİ sayısı.', ['FM 4 Saat', 'FM 4 Sa']),
        $k('fm5', 'FM 5 Sa (Kişi)', 'tam', false, 9, 250, null, 'Tam 5 saat fazla mesai yapan KİŞİ sayısı.', ['FM 5 Saat', 'FM 5 Sa']),
        $k('fm6', 'FM 6 Sa (Kişi)', 'tam', false, 9, 250, null, 'Tam 6 saat fazla mesai yapan KİŞİ sayısı (7+ saat desteklenmez).', ['FM 6 Saat', 'FM 6 Sa']),
        $k('fm_toplam', 'Toplam FM Saati', 'tam', false, 10, 1000, null, 'YALNIZ toplam saat biliniyorsa; sistem kişilere dağıtır. Kova sütunlarıyla birlikte doldurmayın.', ['Toplam Mesai (Saat)', 'Toplam FM', 'Toplam Mesai', 'Toplam Fazla Mesai']),
        $k('fm_kisi', 'FM Kişi Sayısı', 'tam', false, 9, 250, null, 'Toplam FM Saati için isteğe bağlı kişi sayısı (sabit FM fiyatında ZORUNLU).', ['FM Kisi Sayisi', 'FM Kişi']),
        $k('fm_tipi', 'FM Tipi', 'liste', false, 14, 40, ['Tümü', 'Kadın', 'Erkek', 'Rampacı', 'Erkek+Rampacı'], 'FM yapanların tipi; boşsa yükleme sayfasındaki seçenek.', ['FM Tipi', 'Fm Tipi']),
        $k('servis_buyuk', 'Servis Büyük', 'tam', false, 9, 99, null, 'Büyük servis adedi.', ['Servis Buyuk']),
        $k('servis_kucuk', 'Servis Küçük', 'tam', false, 9, 99, null, 'Küçük servis adedi ("1 küçük" → 1).', ['Servis Kucuk']),
        $k('giris', 'Giriş Saati', 'saat', false, 9, null, null, 'SS:DD; boşsa 08:00.', ['Giris Saati', 'Giriş']),
        $k('rev', 'Revizyon', 'tam', false, 9, 9, null, 'Boş = 0. Toplu Geri Al sonrası yeniden yüklemede +1.', ['Revizyon No']),
        $k('not', 'Palet / Not', 'metin', false, 22, 500, null, '"İşlenen Palet Adedi" alanındaki yazı (Rampa, Döküme araba…). Kayıt notuna girer.', ['Palet', 'Not', 'Palet/Not', 'Palet Not']),
        $k('ham', 'Ham Metin', 'metin', false, 30, 500, null, 'Fişteki alanların aynen yazılmış hâli (yalnız kontrol; kaydedilmez).', ['Ham']),
        $k('kontrol', 'Kontrol Notu', 'metin', false, 30, 500, null, 'Yapay zekânın belirsiz bıraktıkları (önizlemede gösterilir; kaydedilmez).', ['Kontrol']),
    ];
    return $s;
}

/** Sütun anahtarı → tanım. */
function pdks_fis_sutun(string $anahtar): ?array
{
    foreach (pdks_fis_sutunlar() as $c) if ($c['anahtar'] === $anahtar) return $c;
    return null;
}

/**
 * TR-katlı karşılaştırma anahtarı: küçük harf, ç→c ğ→g ı→i ö→o ş→s ü→u, harf/rakam dışı → tek boşluk, trim.
 * (Başlık eşleme, çavuş adı, fiş no + depo katlama için; kendi saf sürümü — istek_id sabitliği için
 * pdks_gunluk_depo_fold()/depo_fold()'a BAĞLI DEĞİL.)
 */
function pdks_fis_fold(string $s): string
{
    $s = strtr($s, ['İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ş' => 's', 'ş' => 's', 'Ğ' => 'g', 'ğ' => 'g', 'Ü' => 'u', 'ü' => 'u',
                    'Ö' => 'o', 'ö' => 'o', 'Ç' => 'c', 'ç' => 'c', 'Â' => 'a', 'â' => 'a', 'Î' => 'i', 'î' => 'i', 'Û' => 'u', 'û' => 'u']);
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? '';
    return trim($s);
}

// =========================================================
// C. AYRIŞTIRMA (SAF)
// =========================================================

/**
 * Serbest metni eşleştirme için temizler (formül öneki EKLEMEZ): skaler değilse '', denetim karakterleri atılır
 * (satır sonu/sekme kalır), trim, $maks karaktere kırpılır.
 */
function pdks_fis_metin_duz($x, int $maks = 500): string
{
    if (!is_scalar($x) || is_bool($x)) return '';
    $s = is_float($x) ? rtrim(rtrim(sprintf('%.10F', $x), '0'), '.') : (string)$x;
    $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    if ($t === null) return '';   // geçersiz UTF-8
    return mb_substr(trim($t), 0, max(0, $maks), 'UTF-8');
}

/**
 * Serbest metni DB/audit/ekran için temizler: pdks_fis_metin_duz + formül/CSV enjeksiyonuna karşı ilk karakter
 * = + - @ ise başına ' eklenir (toplam $maks karakteri geçmez).
 */
function pdks_fis_metin($x, int $maks = 500): string
{
    $t = pdks_fis_metin_duz($x, $maks);
    if ($t !== '' && strpbrk($t[0], '=+-@') !== false) $t = mb_substr("'" . $t, 0, max(0, $maks), 'UTF-8');
    return $t;
}

/**
 * Tam sayı ayrıştırır. Boş/'-'/'—'/'yok' → null (hata yok). "12,0"/"12.0" → 12; "3 kişi" → 3;
 * "12,5", negatif, "12 + 1" (birden çok sayı), $maks aşımı → null + $hata.
 */
function pdks_fis_say($x, int $maks, ?string &$hata): ?int
{
    $hata = null;
    if ($x === null) return null;
    if (is_bool($x)) { $hata = 'sayı olmalı.'; return null; }
    if (is_int($x) || is_float($x)) {
        if (is_float($x) && (is_nan($x) || is_infinite($x) || floor($x) !== $x)) { $hata = '"' . pdks_fis_metin($x, 40) . '" tam sayı olmalı.'; return null; }
        $n = (int)$x;
    } elseif (is_string($x)) {
        $s = trim(str_replace("\xC2\xA0", ' ', $x));
        if ($s === '' || $s === '-' || $s === '—' || $s === '–' || mb_strtolower($s, 'UTF-8') === 'yok') return null;
        $s = preg_replace('/\s*(kişi|kisi|adet|saat|sa)\.?$/iu', '', $s) ?? $s;
        $s = trim($s);
        $g = pdks_fis_metin($x, 40);
        if (preg_match('/^\d+$/D', $s)) $n = (int)$s;
        elseif (preg_match('/^(\d+)[.,]0+$/D', $s, $m)) $n = (int)$m[1];
        elseif (preg_match('/^\d+[.,]\d+$/D', $s)) { $hata = '"' . $g . '" tam sayı olmalı.'; return null; }
        elseif (preg_match('/^-\s*\d+([.,]\d+)?$/D', $s)) { $hata = '"' . $g . '" negatif olamaz.'; return null; }
        elseif (preg_match('/^[\d.,]+\s*[eE]\s*[+-]?\d+$/D', $s)) { $hata = '"' . $g . '" geçerli bir tam sayı değil.'; return null; }
        elseif (preg_match_all('/\d+/', $s) > 1) { $hata = '"' . $g . '" tek sayı değil — Birden çok sayı var; Tam ve Yarım sayılarını ayrı sütunlara yazın.'; return null; }
        else { $hata = '"' . $g . '" sayı değil.'; return null; }
    } else {
        $hata = 'sayı olmalı.'; return null;
    }
    if ($n < 0) { $hata = 'negatif olamaz.'; return null; }
    if ($n > $maks) { $hata = $n . ' çok büyük (en çok ' . $maks . ').'; return null; }
    return $n;
}

/** Excel seri numarası → Y-m-d (1900 sistemi; ≥61 için kesin). */
function pdks_fis_seri_tarih(float $seri): ?string
{
    if ($seri < 30000 || $seri >= 70000) return null;
    return (new DateTimeImmutable('1899-12-30 00:00:00'))->modify('+' . (int)floor($seri) . ' days')->format('Y-m-d');
}

/** Tarih → Y-m-d. Kabul: Excel seri, d.m.Y, d/m/Y, d-m-Y, Y-m-d, d.m.y (yy→20yy). Yıl 2020–2100. Boş → null (hata yok). */
function pdks_fis_tarih($x, ?string &$hata): ?string
{
    $hata = null;
    if ($x === null) return null;
    if ($x instanceof DateTimeInterface) $x = $x->format('Y-m-d');
    $g = pdks_fis_metin($x, 40);
    $y = $mo = $d = null;
    if (is_int($x) || is_float($x) || (is_string($x) && preg_match('/^\s*\d{5}(\.\d+)?\s*$/D', $x))) {
        $t = pdks_fis_seri_tarih((float)$x);
        if ($t === null) { $hata = '"' . $g . '" geçerli bir tarih değil.'; return null; }
        [$y, $mo, $d] = array_map('intval', explode('-', $t));
    } elseif (is_string($x)) {
        $s = trim($x);
        if ($s === '') return null;
        $s = preg_replace('/[ T]\d{1,2}:\d{2}(:\d{2})?(\.\d+)?Z?$/', '', $s) ?? $s;
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/D', $s, $m)) [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        elseif (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4}|\d{2})$/D', $s, $m)) {
            [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
            if ($y < 100) $y += 2000;
        } else { $hata = '"' . $g . '" geçerli bir tarih değil (GG.AA.YYYY yazın).'; return null; }
    } else { $hata = 'geçerli bir tarih değil.'; return null; }
    if (!checkdate((int)$mo, (int)$d, (int)$y)) { $hata = '"' . $g . '" geçerli bir tarih değil.'; return null; }
    if ($y < 2020 || $y > 2100) { $hata = '"' . $g . '" 2020–2100 arasında olmalı.'; return null; }
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

/** Saat → HH:MM. Kabul: Excel kesri, H, HH, H:MM, HH:MM, HH:MM:SS, HH.MM (iki haneli dakika), HHMM. "8,5" belirsiz → hata. */
function pdks_fis_saat($x, ?string &$hata): ?string
{
    $hata = null;
    if ($x === null) return null;
    if ($x instanceof DateTimeInterface) $x = $x->format('H:i');
    $g = pdks_fis_metin($x, 20);
    $sa = $dk = null;
    if (is_float($x) || is_int($x)) {
        $f = (float)$x;
        if ($f < 0) { $hata = '"' . $g . '" geçerli bir saat değil.'; return null; }
        if (is_float($x) && $f < 1.0 || ($f >= 1.0 && floor($f) !== $f)) {
            $kesir = $f - floor($f);
            $topDk = (int)round($kesir * 1440);
            if ($topDk >= 1440) { $hata = '"' . $g . '" geçerli bir saat değil.'; return null; }
            [$sa, $dk] = [intdiv($topDk, 60), $topDk % 60];
        } else { [$sa, $dk] = [(int)$f, 0]; }
    } elseif (is_string($x)) {
        $s = trim($x);
        if ($s === '') return null;
        if (preg_match('/^(\d{1,2})[:.](\d{2})(:\d{2})?$/D', $s, $m)) [$sa, $dk] = [(int)$m[1], (int)$m[2]];
        elseif (preg_match('/^0[.,]\d{3,}$/D', $s)) {
            $topDk = (int)round((float)str_replace(',', '.', $s) * 1440);
            if ($topDk >= 1440) { $hata = '"' . $g . '" geçerli bir saat değil.'; return null; }
            [$sa, $dk] = [intdiv($topDk, 60), $topDk % 60];
        }
        elseif (preg_match('/^(\d{2})(\d{2})$/D', $s, $m)) [$sa, $dk] = [(int)$m[1], (int)$m[2]];
        elseif (preg_match('/^\d{1,2}$/D', $s)) [$sa, $dk] = [(int)$s, 0];
        elseif (preg_match('/^\d{1,2}[.,]\d$/D', $s)) { $hata = '"' . $g . '" belirsiz — SS:DD yazın (örn. 08:30).'; return null; }
        else { $hata = '"' . $g . '" geçerli bir saat değil (SS:DD yazın).'; return null; }
    } else { $hata = 'geçerli bir saat değil.'; return null; }
    if ($sa < 0 || $sa > 23 || $dk < 0 || $dk > 59) { $hata = '"' . $g . '" geçerli bir saat değil (00:00–23:59).'; return null; }
    return sprintf('%02d:%02d', $sa, $dk);
}

/** Fiş no normalizasyonu: boşluklar atılır, büyük harf, yalnız [A-Z0-9._/-], 1–30 karakter; aksi null. Baştaki sıfır KORUNUR. */
function pdks_fis_fisno_norm(string $s): ?string
{
    $s = preg_replace('/\s+/u', '', trim($s)) ?? '';
    $s = mb_strtoupper($s, 'UTF-8');
    return preg_match('/^[A-Z0-9._\/-]{1,30}$/D', $s) ? $s : null;
}

// =========================================================
// E. SAF PLAN YARDIMCILARI
// =========================================================

/**
 * Fişin kimlikleri — tekillik = depo + fiş no + revizyon (tarih ve çavuş HARİÇ: OCR hatasıyla düzeltilmiş
 * aynı fiş "yeni" sayılıp çift yazılmasın). 32 küçük hex (pdks_faz8j_istek_id kalıbı).
 * @return array{fis:string, servis:string}
 */
function pdks_fis_istek_idleri(string $depo, string $fisNo, int $rev): array
{
    $no = pdks_fis_fisno_norm($fisNo) ?? mb_strtoupper(preg_replace('/\s+/u', '', trim($fisNo)) ?? '', 'UTF-8');
    $govde = pdks_fis_fold($depo) . '|' . $no . '|r' . $rev;
    return [
        'fis' => substr(hash('sha256', 'fis|' . $govde), 0, 32),
        'servis' => substr(hash('sha256', 'fis-servis|' . $govde), 0, 32),
    ];
}

/** Yarım gün süresi (dk): Tam'ın yarısı, 30 dk'ya aşağı yuvarlı, en az 30. 540 → 270. */
function pdks_fis_yarim_dk(int $tamDk): int
{
    return max(30, intdiv(intdiv($tamDk, 2), 30) * 30);
}

/**
 * Yalnız TOPLAM FM saatinden kişi başı saatleri üretir (tamamen aritmetik, RASTGELE YOK):
 * K verilmemişse cap = min(kisiUstSaat, kmaxSis) ve K = ceil(H/cap); verilmişse cap = kmaxSis.
 * q = H div K, r = H mod K → r kişi (q+1) sa, K−r kişi q sa (azalan sıralı).
 * $kmaxSis: kişi başı en çok FM saati (çift yevmiye / 24 sa sınırı); $havuz: FM'ye girebilecek Tam kişi sayısı.
 * @return array{ok:bool, hata?:string, saatler?:int[], k?:int}
 */
function pdks_fis_fm_toplam_dagit(int $h, ?int $k, int $kisiUstSaat, int $kmaxSis = 15, ?int $havuz = null): array
{
    if ($h < 1) return ['ok' => false, 'hata' => 'Toplam FM saati en az 1 olmalı.'];
    if ($kmaxSis < 1) return ['ok' => false, 'hata' => 'Bu çavuşun süre/çift yevmiye eşikleri FM verilmesine izin vermiyor.'];
    if ($k === null) {
        $cap = max(1, min($kisiUstSaat, $kmaxSis));
        $k = (int)ceil($h / $cap);
    } else {
        $cap = $kmaxSis;
        if ($k < 1) return ['ok' => false, 'hata' => 'FM kişi sayısı en az 1 olmalı.'];
    }
    if ($k > $h) return ['ok' => false, 'hata' => "FM'ye giren her kişi en az 1 saat yapar; kişi sayısı (K=$k) toplam saati (H=$h) aşamaz."];
    if ($havuz !== null && $k > $havuz) return ['ok' => false, 'hata' => "FM kişi sayısı ($k) Tam kişi sayısını ($havuz) aşıyor — FM kova sütunlarını doldurun."];
    if ($h > $k * $cap) return ['ok' => false, 'hata' => "$k kişi × en çok $cap sa = " . ($k * $cap) . " < $h; kişi sayısını artırın ya da FM kova sütunlarını doldurun."];
    $q = intdiv($h, $k); $r = $h % $k;
    $saatler = array_merge(array_fill(0, $r, $q + 1), array_fill(0, $k - $r, $q));
    return ['ok' => true, 'saatler' => $saatler, 'k' => $k];
}

/**
 * FM'ye giren kişileri (saat listesi) tip havuzlarına (Tam kişi sayısı ağırlıklı) dağıtır — tamsayı aritmetiği,
 * en büyük kalan yöntemi, eşitlikte sabit tip sırası KADIN < ERKEK < RAMPACI; kişi→tip: kalan kotası en büyük,
 * eşitlikte o ana kadar atanan saat toplamı küçük, sonra tip sırası.
 * @param int[] $saatler kişi başı saat; @param array<string,int> $havuz KOD → Tam kişi sayısı
 * @return array{kota:array<string,int>, dagilim:array<string,array<int,int>>, saat:array<string,int>}
 */
function pdks_fis_fm_tip_dagit(array $saatler, array $havuz): array
{
    $sira = ['KADIN' => 0, 'ERKEK' => 1, 'RAMPACI' => 2];
    $havuz = array_filter(array_map('intval', $havuz), fn($w) => $w > 0);
    $tipler = array_keys($havuz);
    usort($tipler, fn($a, $b) => ($sira[$a] ?? 99) <=> ($sira[$b] ?? 99) ?: strcmp($a, $b));
    $saatler = array_values(array_map('intval', $saatler));
    rsort($saatler, SORT_NUMERIC);
    $K = count($saatler); $W = array_sum($havuz);
    $kota = []; $kalan = [];
    foreach ($tipler as $t) { $kota[$t] = $W > 0 ? intdiv($K * $havuz[$t], $W) : 0; $kalan[$t] = $W > 0 ? ($K * $havuz[$t]) % $W : 0; }
    $eksik = $K - array_sum($kota);
    $siraTip = $tipler;
    usort($siraTip, fn($a, $b) => $kalan[$b] <=> $kalan[$a] ?: ($sira[$a] ?? 99) <=> ($sira[$b] ?? 99) ?: strcmp($a, $b));
    foreach ($siraTip as $t) {
        if ($eksik > 0 && $kota[$t] < $havuz[$t]) { $kota[$t]++; $eksik--; }
    }
    $kalanKota = $kota; $atanan = array_fill_keys($tipler, 0);
    $dagilim = array_fill_keys($tipler, []);
    foreach ($saatler as $s) {
        $en = null;
        foreach ($tipler as $t) {
            if ($kalanKota[$t] < 1) continue;
            if ($en === null || $kalanKota[$t] > $kalanKota[$en] || ($kalanKota[$t] === $kalanKota[$en] && $atanan[$t] < $atanan[$en])) $en = $t;
        }
        if ($en === null) break;
        $kalanKota[$en]--; $atanan[$en] += $s;
        $dagilim[$en][$s] = ($dagilim[$en][$s] ?? 0) + 1;
    }
    foreach ($dagilim as $t => &$d) krsort($d, SORT_NUMERIC);
    unset($d);
    $dagilim = array_filter($dagilim, fn($d) => $d !== []);
    return ['kota' => $kota, 'dagilim' => $dagilim, 'saat' => $atanan];
}

// =========================================================
// D. ÇAVUŞ EŞLEŞTİRME + NORMALİZASYON
// =========================================================

/** foremen tümü (tek sorgu), aktifler önce. @return list<array{id:int,code:string,name:string,is_active:int}> */
function pdks_fis_cavuslar(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT id, code, name, is_active FROM foremen ORDER BY is_active DESC, name') as $r) {
        $out[] = ['id' => (int)$r['id'], 'code' => (string)$r['code'], 'name' => (string)$r['name'], 'is_active' => (int)$r['is_active']];
    }
    return $out;
}

/**
 * Çavuşu kod (birincil) ya da ada göre çözer. Kod doluysa YALNIZ kod aranır (bulunamazsa HATA, ada düşülmez);
 * kod+ad ikisi de varsa ad sistemdekiyle uyuşmuyorsa UYARI (kod esas alınır). Kod boşsa: ad TAM eşleşmesi →
 * yoksa " - " ile bölünmüş parça eşleşmesi → TAM 1 aday kabul; 0 → "bulunamadı", ≥2 → hata + aday listesi.
 * Pasif çavuş: ok kalır + uyarı (gerçek ret toplu_onizle'den gelir).
 * @return array{ok:bool,id?:int,kod?:string,ad?:string,yontem?:string,hata?:string,uyari?:string}
 */
function pdks_fis_cavus_coz(array $cavuslar, string $kod, string $ad): array
{
    $kodF = pdks_fis_fold($kod); $adF = pdks_fis_fold($ad);
    if ($kodF === '' && $adF === '') return ['ok' => false, 'hata' => 'Çavuş Kodu ya da Çavuş Adı gerekli.'];
    $adaylar = []; $yontem = 'kod'; $uyari = null;
    if ($kodF !== '') {
        foreach ($cavuslar as $c) if (pdks_fis_fold((string)$c['code']) === $kodF) $adaylar[] = $c;
        if (!$adaylar) return ['ok' => false, 'hata' => '"' . pdks_fis_metin_duz($kod, 40) . '" kodlu çavuş bulunamadı.'];
        if (count($adaylar) > 1) return ['ok' => false, 'hata' => '"' . pdks_fis_metin_duz($kod, 40) . '" kodu birden çok çavuşa ait.'];
        if ($adF !== '') {
            $sis = pdks_fis_fold((string)$adaylar[0]['name']);
            if (!str_contains($sis, $adF) && !str_contains($adF, $sis)) {
                $uyari = 'Fişteki ad «' . pdks_fis_metin_duz($ad, 60) . '» sistemdeki «' . pdks_fis_metin_duz((string)$adaylar[0]['name'], 60) . '» ile uyuşmuyor; kod esas alındı.';
            }
        }
    } else {
        $yontem = 'ad';
        foreach ($cavuslar as $c) if (pdks_fis_fold((string)$c['name']) === $adF) $adaylar[] = $c;
        if (!$adaylar) {
            foreach ($cavuslar as $c) {
                foreach (preg_split('/\s+[-–—]\s+/u', (string)$c['name']) ?: [] as $parca) {
                    if (pdks_fis_fold($parca) === $adF) { $adaylar[] = $c; break; }
                }
            }
        }
        if (!$adaylar) return ['ok' => false, 'hata' => '"' . pdks_fis_metin_duz($ad, 60) . '" adlı çavuş bulunamadı (Çavuş Kodunu yazın).'];
        if (count($adaylar) > 1) {
            $liste = array_map(fn($c) => $c['code'] . ' · ' . $c['name'], array_slice($adaylar, 0, 5));
            return ['ok' => false, 'hata' => '"' . pdks_fis_metin_duz($ad, 60) . '" birden çok çavuşla eşleşiyor — Çavuş Kodunu yazın: ' . implode('; ', $liste)];
        }
    }
    $c = $adaylar[0];
    if (empty($c['is_active'])) {
        $u = 'Çavuş pasif — yeni mesai açılamaz, mesai varsa eklenir.';
        $uyari = $uyari === null ? $u : $uyari . ' ' . $u;
    }
    $r = ['ok' => true, 'id' => (int)$c['id'], 'kod' => (string)$c['code'], 'ad' => (string)$c['name'], 'yontem' => $yontem];
    if ($uyari !== null) $r['uyari'] = $uyari;
    return $r;
}

/** Sistem işçi tipi kodları (sıra SABİT: dağıtım ve grup sırası bunu izler). */
function pdks_fis_tip_kodlari(): array { return ['KADIN', 'ERKEK', 'RAMPACI']; }

/** FM tipi hücresi → kod listesi (null = boş). Tanınmazsa false. */
function pdks_fis_fm_tipi_coz($v)
{
    $f = pdks_fis_fold(pdks_fis_metin_duz($v, 60));
    if ($f === '') return null;
    $harita = ['tumu' => ['KADIN', 'ERKEK', 'RAMPACI'], 'hepsi' => ['KADIN', 'ERKEK', 'RAMPACI'], 'tum' => ['KADIN', 'ERKEK', 'RAMPACI'],
               'kadin' => ['KADIN'], 'bayan' => ['KADIN'], 'erkek' => ['ERKEK'], 'rampaci' => ['RAMPACI'],
               'erkek rampaci' => ['ERKEK', 'RAMPACI'], 'erkek ve rampaci' => ['ERKEK', 'RAMPACI'], 'rampaci erkek' => ['ERKEK', 'RAMPACI']];
    return $harita[$f] ?? false;
}

/**
 * pdks_fis_oku() çıktısını fiş modellerine çevirir (SAF). Hata biçimi: "Satır {n}, «{Başlık}»: {mesaj}".
 * Hücre tipleri: s (metin) · n (sayı) · b · e (boş) · f (değeri olmayan formül → hata) · fc (önbellekli formül → uyarı).
 * @return array{fisler:list<array>, genel_hatalar:string[]}
 */
function pdks_fis_normalize(array $oku, array $cavuslar): array
{
    $satirlar = array_values($oku['satirlar'] ?? []);
    if (!$satirlar) return ['fisler' => [], 'genel_hatalar' => ['Dosyada okunacak fiş satırı bulunamadı.']];
    if (count($satirlar) > PDKS_FIS_MAX_FIS) {
        return ['fisler' => [], 'genel_hatalar' => ['En çok ' . PDKS_FIS_MAX_FIS . ' fiş yüklenebilir (dosyada ' . count($satirlar) . ' satır var); dosyayı bölün.']];
    }
    $bugun = date('Y-m-d');
    $baslik = [];
    foreach (pdks_fis_sutunlar() as $c) $baslik[$c['anahtar']] = $c['baslik'];
    $fisler = [];
    foreach ($satirlar as $sat) {
        $n = (int)($sat['satir'] ?? 0);
        $hu = is_array($sat['hucreler'] ?? null) ? $sat['hucreler'] : [];
        $dolu = false;
        foreach ($hu as $c) { $v = $c['v'] ?? null; if ($v !== null && trim((string)(is_scalar($v) ? $v : 'x')) !== '') { $dolu = true; break; } }
        if (!$dolu) continue;
        $hatalar = []; $uyarilar = [];
        $H = fn(string $a, string $m) => "Satır $n, «" . ($baslik[$a] ?? $a) . "»: $m";
        // Hücre değeri (formül kuralı burada)
        $al = function (string $a) use ($hu, &$hatalar, &$uyarilar, $H) {
            $c = $hu[$a] ?? null;
            if (!is_array($c)) return null;
            $t = (string)($c['t'] ?? 's');
            if ($t === 'f') { $hatalar[] = $H($a, 'Formül yerine değer yazın.'); return null; }
            if ($t === 'fc') $uyarilar[] = $H($a, 'formül önbellek değeriyle okundu — kontrol edin.');
            return $c['v'] ?? null;
        };
        $say = function (string $a, int $maks) use ($al, &$hatalar, $H): ?int {
            $v = $al($a);
            $h = null; $r = pdks_fis_say($v, $maks, $h);
            if ($h !== null) $hatalar[] = $H($a, $h);
            return $r;
        };

        // Fiş No
        $ham = $hu['fis_no'] ?? null; $fisNo = '';
        $fisNoHamSayi = false;
        $fv = $al('fis_no');
        if (is_int($fv) || is_float($fv)) {
            $fisNoHamSayi = true;
            $fisNo = (string)(is_float($fv) && floor($fv) === $fv ? (int)$fv : $fv);
            $uyarilar[] = $H('fis_no', 'sayı olarak gelmiş; baştaki sıfır kaybolmuş olabilir (örn. 01410) — METİN olarak yazın.');
        } else {
            $fisNo = pdks_fis_metin_duz($fv, 60);
        }
        $fisNoN = null;
        if ($fisNo === '') $hatalar[] = $H('fis_no', 'Fiş No boş.');
        else {
            $fisNoN = pdks_fis_fisno_norm($fisNo);
            if ($fisNoN === null) $hatalar[] = $H('fis_no', '"' . pdks_fis_metin_duz($fisNo, 40) . '" geçersiz (harf, rakam ve . _ / - ; en çok 30 karakter).');
            elseif (str_starts_with($fisNoN, 'ORNEK-')) $hatalar[] = $H('fis_no', 'Örnek satırı silinmemiş (ORNEK- ile başlayan Fiş No).');
        }

        // Tarih
        $tarih = null; $th = null;
        $tv = $al('tarih');
        $tarih = pdks_fis_tarih($tv, $th);
        if ($th !== null) $hatalar[] = $H('tarih', $th);
        elseif ($tarih === null) $hatalar[] = $H('tarih', 'Tarih boş.');
        elseif ($tarih >= $bugun) $hatalar[] = $H('tarih', 'bugün veya gelecek tarihli fişler desteklenmez.');

        $isletme = pdks_fis_metin($al('isletme'), 150);

        // Çavuş
        $kod = pdks_fis_metin_duz($al('cavus_kodu'), 40); $ad = pdks_fis_metin_duz($al('cavus_adi'), 150);
        $cr = pdks_fis_cavus_coz($cavuslar, $kod, $ad);
        $cavus = ['id' => null, 'kod' => $kod, 'ad' => $ad, 'yontem' => null];
        if (!$cr['ok']) $hatalar[] = $H($kod !== '' ? 'cavus_kodu' : 'cavus_adi', (string)$cr['hata']);
        else {
            $cavus = ['id' => $cr['id'], 'kod' => $cr['kod'], 'ad' => $cr['ad'], 'yontem' => $cr['yontem']];
            if (!empty($cr['uyari'])) { $cavus['uyari'] = $cr['uyari']; $uyarilar[] = $H($kod !== '' ? 'cavus_kodu' : 'cavus_adi', $cr['uyari']); }
        }

        // Sayılar
        $sy = [];
        foreach (['KADIN' => 'kadin', 'ERKEK' => 'erkek', 'RAMPACI' => 'rampaci'] as $KOD => $on) {
            $sy[$KOD] = ['tam' => $say($on . '_tam', 250) ?? 0, 'yarim' => $say($on . '_yarim', 250) ?? 0];
        }
        $kova = [];
        for ($k = 1; $k <= PDKS_FIS_FM_KOVA_MAX; $k++) $kova[$k] = $say('fm' . $k, 250) ?? 0;
        $fmToplam = $say('fm_toplam', 1000);
        $fmKisi = $say('fm_kisi', 250);
        $ft = pdks_fis_fm_tipi_coz($al('fm_tipi'));
        if ($ft === false) { $hatalar[] = $H('fm_tipi', '"' . pdks_fis_metin_duz($hu['fm_tipi']['v'] ?? '', 40) . '" tanınmadı (Tümü, Kadın, Erkek, Rampacı, Erkek+Rampacı).'); $ft = null; }
        $maksSv = defined('PDKS_SERVIS_MAX_ADET') ? (int)PDKS_SERVIS_MAX_ADET : 99;
        $servis = ['buyuk' => $say('servis_buyuk', $maksSv) ?? 0, 'kucuk' => $say('servis_kucuk', $maksSv) ?? 0];

        // Giriş + revizyon
        $gh = null; $giris = pdks_fis_saat($al('giris'), $gh);
        if ($gh !== null) $hatalar[] = $H('giris', $gh);
        $girisVars = $giris === null;
        if ($giris === null) $giris = PDKS_FIS_VARSAYILAN_GIRIS;
        $rev = $say('rev', 9) ?? 0;

        $fisler[] = [
            'satir' => $n, 'fis_no' => $fisNoN ?? $fisNo, 'fis_no_ham_sayi' => $fisNoHamSayi, 'tarih' => $tarih, 'isletme' => $isletme,
            'cavus' => $cavus, 'say' => $sy, 'fm_kova' => $kova, 'fm_toplam' => $fmToplam, 'fm_kisi' => $fmKisi, 'fm_tipi' => $ft,
            'servis' => $servis, 'giris' => $giris, 'giris_varsayilan' => $girisVars, 'rev' => $rev,
            'not' => pdks_fis_metin($al('not'), 500), 'ham' => pdks_fis_metin($al('ham'), 500), 'kontrol' => pdks_fis_metin($al('kontrol'), 500),
            'cakisma_dosya' => false, 'cakisma_sira' => 0, 'hatalar' => $hatalar, 'uyarilar' => $uyarilar,
        ];
    }
    // Dosya düzeyi: aynı Fiş No
    $noSatirlar = [];
    foreach ($fisler as $i => $f) if ($f['fis_no'] !== '') $noSatirlar[$f['fis_no']][] = $i;
    foreach ($noSatirlar as $no => $idx) {
        if (count($idx) < 2) continue;
        $satirNolari = implode(', ', array_map(fn($i) => $fisler[$i]['satir'], $idx));
        foreach ($idx as $i) $fisler[$i]['hatalar'][] = "Fiş $no: aynı Fiş No dosyada birden çok satırda (satır $satirNolari).";
    }
    // Aynı çavuş + gün birden çok fiş
    $cg = [];
    foreach ($fisler as $i => $f) if ($f['cavus']['id'] !== null && $f['tarih'] !== null) $cg[$f['cavus']['id'] . '|' . $f['tarih']][] = $i;
    foreach ($cg as $idx) {
        if (count($idx) < 2) continue;
        $satirNolari = implode(', ', array_map(fn($i) => $fisler[$i]['satir'], $idx));
        foreach ($idx as $sira => $i) {
            $fisler[$i]['cakisma_dosya'] = true; $fisler[$i]['cakisma_sira'] = $sira;
            $fisler[$i]['uyarilar'][] = "Aynı dosyada aynı çavuş + gün için " . count($idx) . " fiş var (satır $satirNolari); birden çok fiş aynı mesaiye eklenir.";
        }
    }
    return ['fisler' => $fisler, 'genel_hatalar' => []];
}

// =========================================================
// E. FM ÜCRET NÖTRLÜĞÜ + SAF PLAN + ZAMANLAMA
// =========================================================

/**
 * FM dağılımının tutarı etkileyip etkilemediği. $tipler[KOD]['oran'] = [var, fm_modu, fm_birim, para].
 * ≤1 tip → true. Hepsinin oranı var, mod aynı ∈ {hourly,fixed}, birim ve para eşitse true; oranı olmayan varsa
 * null (bilinmiyor); diğer durumda false.
 * @return array{notr:?bool, mod:?string, neden:string}
 */
function pdks_fis_fm_notr(array $baglamTipler, array $izinliKodlar): array
{
    $izinli = array_values(array_unique($izinliKodlar));
    $mod0 = isset($izinli[0]) ? ($baglamTipler[$izinli[0]]['oran']['fm_modu'] ?? null) : null;
    if (count($izinli) <= 1) return ['notr' => true, 'mod' => $mod0, 'neden' => 'Tek tip — dağılım tutarı değiştirmez.'];
    $modlar = []; $birimler = []; $paralar = [];
    foreach ($izinli as $kod) {
        $o = $baglamTipler[$kod]['oran'] ?? null;
        if (!is_array($o) || empty($o['var'])) return ['notr' => null, 'mod' => null, 'neden' => 'Fiyat tanımı eksik/belirsiz (' . $kod . ').'];
        $modlar[(string)($o['fm_modu'] ?? '')] = true;
        $birimler[sprintf('%.4F', (float)($o['fm_birim'] ?? 0))] = true;
        $paralar[(string)($o['para'] ?? '')] = true;
    }
    $mod = (string)array_key_first($modlar);
    if (count($modlar) === 1 && in_array($mod, ['hourly', 'fixed'], true) && count($birimler) === 1 && count($paralar) === 1) {
        return ['notr' => true, 'mod' => $mod, 'neden' => 'FM modu ve birim tutarı tüm tiplerde aynı.'];
    }
    return ['notr' => false, 'mod' => null, 'neden' => 'FM modu/birimi/para birimi tipler arasında farklı.'];
}

/** Grup etiketi: "Erkek · FM 2 sa". */
function pdks_fis_grup_etiketi(array $g): string
{
    $p = $g['profil'] === 'fm' ? 'FM ' . (int)$g['fm_saat'] . ' sa' : ($g['profil'] === 'yarim' ? 'Yarım' : 'Tam');
    return (string)($g['tip_ad'] ?? $g['kod']) . ' · ' . $p;
}

/** FM'ye giren tiplerin izinli kümesi: fişin FM Tipi, yoksa sayfa seçeneği. */
function pdks_fis_fm_izinli_kodlar(?array $fisTipi, string $secenek): array
{
    if ($fisTipi) return array_values(array_intersect(pdks_fis_tip_kodlari(), $fisTipi));
    if ($secenek === 'erkek') return ['ERKEK'];
    if ($secenek === 'erkek_rampaci') return ['ERKEK', 'RAMPACI'];
    return pdks_fis_tip_kodlari();
}

/**
 * Fişten kayıt planı (SAF — DB yok; eşikler $baglam'dan, o da sınıflandırıcıdan gelir). Grup sırası SABİT:
 * tip (KADIN, ERKEK, RAMPACI) × profil (tam, fm 1..n artan, yarim). FM kişileri Tam kişilerden düşülür; Yarım'a
 * FM verilmez. $baglam = ['tipler'=>[KOD=>['id','ad','tam_dk','fm_bas_dk','cift_dk','oran'=>[var,fm_modu,fm_birim,para]]],
 * 'fm_tip_secenegi'=>'tumu'|'erkek'|'erkek_rampaci'].
 * @return array{ok:bool,hatalar:string[],uyarilar:string[],gruplar:list<array>,fm:array,servis:array,kisi_toplam:int,hash:string}
 */
function pdks_fis_plan(array $fis, array $baglam): array
{
    $hatalar = []; $uyarilar = [];
    $tipler = is_array($baglam['tipler'] ?? null) ? $baglam['tipler'] : [];
    $secenek = (string)($baglam['fm_tip_secenegi'] ?? 'tumu');
    $fmYok = ['mod' => 'yok', 'saatler' => [], 'tip_dagilim' => [], 'toplam_saat' => 0, 'kisi' => 0, 'notr' => null, 'aciklama' => ''];
    $servis = ['buyuk' => (int)($fis['servis']['buyuk'] ?? 0), 'kucuk' => (int)($fis['servis']['kucuk'] ?? 0)];
    $sonuc = function () use (&$hatalar, &$uyarilar, &$gruplar, &$fm, &$servis, &$kisiToplam, $fis, $secenek) {
        $kanon = ['g' => (string)($fis['giris'] ?? ''), 'sv' => [$servis['buyuk'], $servis['kucuk']], 'fmt' => $secenek,
                  'gr' => array_map(fn($g) => [$g['kod'], $g['profil'], $g['fm_saat'], $g['adet'], $g['sure_dk']], $gruplar),
                  'fm' => $fm['saatler'] ?? []];
        return ['ok' => $hatalar === [], 'hatalar' => $hatalar, 'uyarilar' => $uyarilar, 'gruplar' => $gruplar, 'fm' => $fm, 'servis' => $servis,
                'kisi_toplam' => $kisiToplam, 'hash' => substr(hash('sha256', json_encode($kanon, JSON_UNESCAPED_UNICODE)), 0, 16)];
    };
    $gruplar = []; $fm = $fmYok; $kisiToplam = 0;

    // 1) kişi sayıları + bağlamda tip var mı
    $sayi = [];
    foreach (pdks_fis_tip_kodlari() as $kod) {
        $t = (int)($fis['say'][$kod]['tam'] ?? 0); $y = (int)($fis['say'][$kod]['yarim'] ?? 0);
        $sayi[$kod] = ['tam' => $t, 'yarim' => $y];
        $kisiToplam += $t + $y;
        if (($t + $y) > 0 && !isset($tipler[$kod])) $hatalar[] = ucfirst(mb_strtolower($kod, 'UTF-8')) . ' işçi tipi sistemde bulunamadı ya da pasif (tip tanımı yok).';
    }
    if ($kisiToplam === 0) {
        $hatalar[] = ($servis['buyuk'] + $servis['kucuk']) > 0
            ? 'Yalnız servisli fiş desteklenmez — mesaide en az bir işçi kaydı olmalı (servisi Mesai Detayı\'ndan girin).'
            : 'Boş fiş: hiç işçi sayısı girilmemiş.';
        return $sonuc();
    }
    if ($kisiToplam > PDKS_FAZ8J_TOPLU_LIMIT) {
        $hatalar[] = 'Bir fişte en çok ' . PDKS_FAZ8J_TOPLU_LIMIT . ' kişi kaydı yazılabilir (fişte: ' . $kisiToplam . ').';
        return $sonuc();
    }
    if ($hatalar) return $sonuc();

    // 2) FM biçimi
    $kova = []; $kovaKisi = 0; $kovaSaat = 0;
    for ($k = 1; $k <= PDKS_FIS_FM_KOVA_MAX; $k++) {
        $nk = (int)($fis['fm_kova'][$k] ?? 0);
        $kova[$k] = $nk; $kovaKisi += $nk; $kovaSaat += $nk * $k;
    }
    $H = isset($fis['fm_toplam']) ? (int)$fis['fm_toplam'] : null;
    $K = isset($fis['fm_kisi']) ? (int)$fis['fm_kisi'] : null;
    $mod = 'yok';
    if ($kovaKisi > 0) {
        $mod = 'kova';
        if ($H !== null && $H > 0 && $H !== $kovaSaat) $hatalar[] = "«Toplam FM Saati» ($H) FM kova sütunlarının toplamıyla ($kovaSaat sa) uyuşmuyor — yalnız birini doldurun.";
        elseif ($H !== null && $H > 0) $uyarilar[] = "«Toplam FM Saati» ($H) kova toplamıyla tutarlı; yok sayıldı.";
        if ($K !== null && $K > 0 && $K !== $kovaKisi) $hatalar[] = "«FM Kişi Sayısı» ($K) FM kova sütunlarındaki kişi toplamıyla ($kovaKisi) uyuşmuyor.";
    } elseif ($H !== null && $H > 0) {
        $mod = 'toplam';
    } elseif ($K !== null && $K > 0) {
        $hatalar[] = '«FM Kişi Sayısı» dolu ama «Toplam FM Saati» ve FM kova sütunları boş.';
    }

    $dagilim = []; $saatler = [];
    if ($mod !== 'yok' && !$hatalar) {
        $izinli = pdks_fis_fm_izinli_kodlar(is_array($fis['fm_tipi'] ?? null) ? $fis['fm_tipi'] : null, $secenek);
        $havuz = [];
        foreach ($izinli as $kod) if ($sayi[$kod]['tam'] > 0) $havuz[$kod] = $sayi[$kod]['tam'];
        $W = array_sum($havuz);
        if ($W < 1) {
            $adlar = implode('/', array_map(fn($c) => ucfirst(mb_strtolower($c, 'UTF-8')), $izinli));
            $hatalar[] = "FM için izinli tiplerde ($adlar) Tam kişi yok — «FM Tipi» sütununu kontrol edin.";
        } else {
            $nt = pdks_fis_fm_notr($tipler, array_keys($havuz));
            if ($mod === 'kova') {
                foreach ($kova as $k => $nk) for ($i = 0; $i < $nk; $i++) $saatler[] = $k;
                rsort($saatler, SORT_NUMERIC);
                if (count($saatler) > $W) $hatalar[] = 'FM\'ye giren kişi sayısı (' . count($saatler) . ') Tam kişi sayısını (' . $W . ') aşıyor.';
                elseif ($nt['notr'] === false) $hatalar[] = 'FM fiyatları tipler arasında farklı (' . $nt['neden'] . '): «FM Tipi» sütununda tek tip seçin.';
                elseif ($nt['notr'] === null) $uyarilar[] = 'FM fiyat tanımı eksik/belirsiz; tipler arası dağılım tutarı etkileyebilir (' . $nt['neden'] . ')';
            } else {
                if ($nt['notr'] !== true) {
                    $hatalar[] = 'Toplam FM dağıtımı yapılamaz: ' . $nt['neden'] . ' FM kova sütunlarını doldurun.';
                } elseif ($nt['mod'] === 'fixed' && ($K === null || $K < 1)) {
                    $hatalar[] = 'Sabit tutarlı (kişi başı) FM fiyatında «FM Kişi Sayısı» zorunludur — kişi sayısı ödemeyi değiştirir.';
                } else {
                    $kmax = 15;
                    foreach (array_keys($havuz) as $kod) {
                        $fb = (int)$tipler[$kod]['fm_bas_dk'];
                        $kmax = min($kmax, intdiv(1440 - $fb, 60));
                        if (($tipler[$kod]['cift_dk'] ?? null) !== null) $kmax = min($kmax, intdiv((int)$tipler[$kod]['cift_dk'] - $fb - 1, 60));
                    }
                    $d = pdks_fis_fm_toplam_dagit((int)$H, $K, PDKS_FIS_FM_KISI_UST_SAAT, $kmax, $W);
                    if (!$d['ok']) $hatalar[] = 'Toplam FM dağıtımı: ' . $d['hata'];
                    else $saatler = $d['saatler'];
                }
            }
            if (!$hatalar && $saatler) {
                $dd = pdks_fis_fm_tip_dagit($saatler, $havuz);
                $dagilim = $dd['dagilim'];
                foreach ($dagilim as $kod => $sk) {
                    foreach ($sk as $saat => $nKisi) {
                        $ct = $tipler[$kod]['cift_dk'] ?? null;
                        if ($ct !== null && (int)$tipler[$kod]['fm_bas_dk'] + 60 * (int)$saat >= (int)$ct) {
                            $uyarilar[] = 'Çift yevmiye eşiğine girer: ' . ($tipler[$kod]['ad'] ?? $kod) . ' · FM ' . $saat . ' sa (' . $nKisi . ' kişi) — bu kişiler çift yevmiye sayılabilir.';
                        }
                    }
                }
                $kisi = count($saatler); $topS = array_sum($saatler);
                $parcalar = [];
                $say2 = array_count_values($saatler); krsort($say2, SORT_NUMERIC);
                foreach ($say2 as $saat => $nKisi) $parcalar[] = $nKisi . '×' . $saat . ' sa';
                $fm = ['mod' => $mod, 'saatler' => $saatler, 'tip_dagilim' => $dagilim, 'toplam_saat' => $topS, 'kisi' => $kisi, 'notr' => $nt['notr'],
                       'aciklama' => $topS . ' sa → ' . $kisi . ' kişi: ' . implode(' + ', $parcalar)];
            }
        }
    }
    if ($hatalar) return $sonuc();

    // 3) gruplar (sıra sabit)
    foreach (pdks_fis_tip_kodlari() as $kod) {
        $t = $tipler[$kod] ?? null;
        if (!$t) continue;
        $fmT = array_sum($dagilim[$kod] ?? []);
        $tamSaf = $sayi[$kod]['tam'] - $fmT;
        if ($tamSaf < 0) { $hatalar[] = 'İç tutarsızlık: ' . $kod . ' FM kişisi Tam kişiyi aşıyor.'; continue; }
        $mk = static fn(string $profil, int $fmSaat, int $adet, int $sure) => ['kod' => $kod, 'tip_id' => (int)$t['id'], 'tip_ad' => (string)$t['ad'],
            'profil' => $profil, 'fm_saat' => $fmSaat, 'adet' => $adet, 'sure_dk' => $sure];
        if ($tamSaf > 0) $gruplar[] = $mk('tam', 0, $tamSaf, (int)$t['tam_dk']);
        $sk = $dagilim[$kod] ?? []; ksort($sk, SORT_NUMERIC);
        foreach ($sk as $saat => $nKisi) $gruplar[] = $mk('fm', (int)$saat, (int)$nKisi, (int)$t['fm_bas_dk'] + 60 * (int)$saat);
        if ($sayi[$kod]['yarim'] > 0) $gruplar[] = $mk('yarim', 0, $sayi[$kod]['yarim'], pdks_fis_yarim_dk((int)$t['tam_dk']));
    }
    return $sonuc();
}

/**
 * Plana her grup için entry_clock / exit_date / exit_clock ekler (giriş + sure_dk; gece taşması ertesi gün).
 * Geçersiz giriş saati ya da 24 saati aşan süre plan hatasıdır.
 */
function pdks_fis_zamanla(array $plan, string $tarih, string $giris): array
{
    $gh = null; $g = pdks_fis_saat($giris, $gh);
    $t0 = $g !== null ? DateTimeImmutable::createFromFormat('!Y-m-d H:i', $tarih . ' ' . $g) : false;
    if ($g === null || !$t0) {
        $plan['ok'] = false;
        $plan['hatalar'][] = 'Geçersiz giriş saati ya da tarih.';
        return $plan;
    }
    foreach ($plan['gruplar'] as &$gr) {
        $c = $t0->modify('+' . (int)$gr['sure_dk'] . ' minutes');
        $gr['entry_clock'] = $g; $gr['exit_date'] = $c->format('Y-m-d'); $gr['exit_clock'] = $c->format('H:i');
        if ((int)$gr['sure_dk'] > 1440) {
            $plan['ok'] = false;
            $plan['hatalar'][] = pdks_fis_grup_etiketi($gr) . ': süre 24 saati aşıyor (' . intdiv((int)$gr['sure_dk'], 60) . ' sa ' . ((int)$gr['sure_dk'] % 60) . ' dk).';
        }
    }
    unset($gr);
    return $plan;
}

// =========================================================
// B. DOSYA DOĞRULAMA + OKUMA
// =========================================================

/**
 * Yüklenen dosyayı PhpSpreadsheet'e GİRMEDEN doğrular (siparis_dosya_dogrula örüntüsü; kopya değil, aynı denetimler):
 * UPLOAD_ERR eşleme · yükleme denetimi (varsayılan is_uploaded_file) · boyut 1..2 MB · uzantı YALNIZ .xlsx · PK imzası ·
 * finfo MIME · zip: ≤100 parça, ".." / mutlak yol yok, [Content_Types].xml + xl/workbook.xml var, makro/dış bağlantı/
 * gömülü nesne/ActiveX RED, parça ≤20 MB, toplam ≤50 MB, sıkıştırma oranı ≤200 (zip bombası). Dosya diske KALICI yazılmaz
 * (tmp_name'den okunur); yalnız sha256 audit'e girer.
 * @param callable|null $yuklemeDenetimi tmp yolunu alır, gerçek HTTP yüklemesi mi? (testler `fn() => true` verir)
 * @return array{ok:bool, hata?:string, yol?:string, sha256?:string, bayt?:int}
 */
function pdks_fis_dosya_dogrula(array $dosya, ?callable $yuklemeDenetimi = null): array
{
    $err = (int)($dosya['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        $m = match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Dosya çok büyük (en çok ' . (int)(PDKS_FIS_MAX_BAYT / 1048576) . ' MB).',
            UPLOAD_ERR_PARTIAL => 'Dosya yarım yüklendi — yeniden deneyin.',
            UPLOAD_ERR_NO_FILE => 'Dosya seçilmedi.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Sunucu dosyayı geçici olarak kaydedemedi.',
            UPLOAD_ERR_EXTENSION => 'Dosya sunucu tarafından engellendi.',
            default => 'Dosya yüklenemedi.',
        };
        return ['ok' => false, 'hata' => $m];
    }
    $tmp = (string)($dosya['tmp_name'] ?? '');
    $denetim = $yuklemeDenetimi ?? 'is_uploaded_file';
    if ($tmp === '' || !is_file($tmp) || !$denetim($tmp)) return ['ok' => false, 'hata' => 'Dosya yükleme isteğiyle gelmedi ya da okunamadı — yeniden yükleyin.'];
    $bayt = (int)@filesize($tmp);
    if ($bayt < 1) return ['ok' => false, 'hata' => 'Dosya boş.'];
    if ($bayt > PDKS_FIS_MAX_BAYT) return ['ok' => false, 'hata' => 'Dosya çok büyük (en çok ' . (int)(PDKS_FIS_MAX_BAYT / 1048576) . ' MB).'];
    $ext = strtolower(pathinfo((string)($dosya['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') return ['ok' => false, 'hata' => 'Yalnız .xlsx kabul edilir (.xls/.xlsm/.csv değil). Excel\'de Farklı Kaydet › Excel Çalışma Kitabı (.xlsx) seçin.'];
    $bas = (string)@file_get_contents($tmp, false, null, 0, 4);
    if (strncmp($bas, "PK\x03\x04", 4) !== 0 || !class_exists('ZipArchive')) return ['ok' => false, 'hata' => 'Geçerli bir Excel (xlsx) dosyası değil.'];
    if (class_exists('finfo')) {
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream', 'application/x-zip-compressed'], true)) {
            return ['ok' => false, 'hata' => 'Dosya içeriği Excel (xlsx) ile uyuşmuyor.'];
        }
    }
    $z = new ZipArchive();
    if ($z->open($tmp, ZipArchive::RDONLY) !== true) return ['ok' => false, 'hata' => 'Excel dosyası açılamadı (bozuk olabilir).'];
    $sorun = null;
    if ($z->numFiles > 100) $sorun = 'Excel dosyası beklenmedik sayıda parça içeriyor.';
    else {
        $toplam = 0;
        for ($i = 0; $i < $z->numFiles && $sorun === null; $i++) {
            $st = $z->statIndex($i);
            if ($st === false) { $sorun = 'Excel dosyası okunamadı (bozuk olabilir).'; break; }
            $ad = (string)$st['name'];
            $kucuk = strtolower($ad);
            if (str_contains($ad, '..') || str_starts_with($ad, '/') || str_contains($ad, '\\')) { $sorun = 'Excel dosyası geçersiz parça yolu içeriyor.'; break; }
            if ($kucuk === 'xl/vbaproject.bin' || str_starts_with($kucuk, 'xl/externallinks/') || str_starts_with($kucuk, 'xl/embeddings/') || str_starts_with($kucuk, 'xl/activex/')) {
                $sorun = 'Makro, dış bağlantı ya da gömülü nesne içeren dosya kabul edilmez — değerleri yapıştırıp temiz bir .xlsx olarak kaydedin.'; break;
            }
            $boy = (int)$st['size']; $sik = (int)$st['comp_size'];
            if ($boy > 20 * 1048576) { $sorun = 'Excel dosyasındaki bir parça çok büyük.'; break; }
            if ($sik > 0 && $boy / $sik > 200 && $boy > 1048576) { $sorun = 'Excel dosyası olağandışı sıkıştırılmış (zip bombası şüphesi).'; break; }
            $toplam += $boy;
        }
        if ($sorun === null && $toplam > 50 * 1048576) $sorun = 'Excel dosyası açıldığında çok büyük.';
        if ($sorun === null && ($z->locateName('[Content_Types].xml') === false || $z->locateName('xl/workbook.xml') === false)) $sorun = 'Geçerli bir Excel (xlsx) dosyası değil.';
    }
    $z->close();
    if ($sorun !== null) return ['ok' => false, 'hata' => $sorun];
    return ['ok' => true, 'yol' => $tmp, 'sha256' => (string)hash_file('sha256', $tmp), 'bayt' => $bayt];
}

/**
 * "Fişler" sayfasını güvenle okur: ReadDataOnly, yalnız bu sayfa, satır/sütun okuma filtresi, formül HESAPLANMAZ
 * (getCalculatedValue ASLA çağrılmaz). Başlıkları ada göre (TR-katlı, takma adlarla) eşler — sıra bağımsız.
 * Hücre: anahtar → ['v' => ham değer, 't' => s|n|b|e|x|f|fc]; f = değeri olmayan formül, fc = önbellekli formül.
 * @return array{ok:bool, hata?:string, uyarilar:string[], satirlar:list<array{satir:int,hucreler:array<string,array{v:mixed,t:string}>}>, bilinmeyen:string[]}
 */
function pdks_fis_oku(string $yol): array
{
    $son = ['ok' => false, 'uyarilar' => [], 'satirlar' => [], 'bilinmeyen' => []];
    xlsx_motor_yukle();
    $lim = ini_get('memory_limit');
    if ($lim !== false && $lim !== '-1' && xlsx_bayt((string)$lim) < 256 * 1048576) @ini_set('memory_limit', '256M');
    @set_time_limit(60);
    $ss = null;
    try {
        $r = IOFactory::createReader('Xlsx');
        $r->setReadDataOnly(true);
        $r->setReadEmptyCells(false);
        $ad = null;
        foreach ($r->listWorksheetNames($yol) as $sn) if (pdks_fis_fold((string)$sn) === pdks_fis_fold(PDKS_FIS_SAYFA)) { $ad = (string)$sn; break; }
        if ($ad === null) return ['hata' => '«' . PDKS_FIS_SAYFA . '» sayfası bulunamadı — şablonu indirip aynı sayfayı doldurun.'] + $son;
        $r->setLoadSheetsOnly([$ad]);
        $enSatir = 5 + PDKS_FIS_MAX_FIS + 2;     // başlık araması (5) + fiş + 1 taşma tespiti + başlık
        $r->setReadFilter(new class($enSatir) implements IReadFilter {
            public function __construct(private int $enSatir) {}
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->enSatir && \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress) <= 30;
            }
        });
        $ss = $r->load($yol);
        $ws = $ss->getSheetByName($ad) ?? $ss->getActiveSheet();
        $hucre = [];   // [satır][sütun idx] = [v, t]
        foreach ($ws->getCoordinates() as $koor) {
            $c = $ws->getCell($koor);
            [$sutunHarf, $satir] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($koor);
            $sutun = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sutunHarf);
            $tip = $c->getDataType();
            if ($tip === DataType::TYPE_FORMULA) {
                $onbellek = $c->getOldCalculatedValue();
                $hucre[(int)$satir][$sutun] = ($onbellek !== null && $onbellek !== '' && is_scalar($onbellek)) ? [$onbellek, 'fc'] : [null, 'f'];
                continue;
            }
            $v = $c->getValue();
            if (is_object($v)) $v = method_exists($v, 'getPlainText') ? $v->getPlainText() : (string)$v;
            if ($tip === DataType::TYPE_ERROR) { $hucre[(int)$satir][$sutun] = [null, 'x']; continue; }
            if ($v === null || $v === '') { continue; }
            $hucre[(int)$satir][$sutun] = [$v, is_bool($v) ? 'b' : (is_int($v) || is_float($v) ? 'n' : 's')];
        }
        ksort($hucre);
        // Başlık satırı: ilk 5 satırda Fiş No ve Tarih başlıkları
        $eslesme = [];   // katlanmış başlık → anahtar
        foreach (pdks_fis_sutunlar() as $c) {
            $eslesme[pdks_fis_fold($c['baslik'])] = $c['anahtar'];
            foreach ($c['takma'] as $t) $eslesme[pdks_fis_fold($t)] = $c['anahtar'];
        }
        $baslikSatir = null; $harita = [];
        for ($sr = 1; $sr <= 5; $sr++) {
            $bulunan = [];
            foreach ($hucre[$sr] ?? [] as $sutun => [$v, $t]) {
                $f = is_string($v) ? pdks_fis_fold($v) : '';
                if ($f !== '' && isset($eslesme[$f])) $bulunan[$eslesme[$f]] = true;
            }
            if (isset($bulunan['fis_no'], $bulunan['tarih'])) { $baslikSatir = $sr; break; }
        }
        if ($baslikSatir === null) return ['hata' => 'Başlık satırı bulunamadı (ilk 5 satırda «Fiş No» ve «Tarih» sütun başlıkları olmalı).'] + $son;
        $uyarilar = []; $bilinmeyen = []; $gorulen = [];
        foreach ($hucre[$baslikSatir] as $sutun => [$v, $t]) {
            $metin = pdks_fis_metin_duz($v, 80);
            if ($metin === '') continue;
            $a = $eslesme[pdks_fis_fold($metin)] ?? null;
            if ($a === null) { $bilinmeyen[] = $metin; $uyarilar[] = 'Tanınmayan sütun yok sayıldı: «' . $metin . '».'; continue; }
            if (isset($gorulen[$a])) return ['hata' => '«' . pdks_fis_sutun($a)['baslik'] . '» başlığı iki kez var — sütunları tekilleştirin.'] + $son;
            $gorulen[$a] = true; $harita[$sutun] = $a;
        }
        if (!isset($gorulen['cavus_kodu']) && !isset($gorulen['cavus_adi'])) return ['hata' => 'Zorunlu sütun yok: «Çavuş Kodu» ya da «Çavuş Adı».'] + $son;
        $satirlar = [];
        foreach ($hucre as $sr => $sutunlar) {
            if ($sr <= $baslikSatir) continue;
            $hu = []; $dolu = false;
            foreach ($harita as $sutun => $a) {
                if (!isset($sutunlar[$sutun])) continue;
                [$v, $t] = $sutunlar[$sutun];
                if (is_string($v) && trim($v) === '' && $t === 's') continue;
                $hu[$a] = ['v' => $v, 't' => $t]; $dolu = true;
            }
            if ($dolu) $satirlar[] = ['satir' => (int)$sr, 'hucreler' => $hu];
        }
        return ['ok' => true, 'uyarilar' => $uyarilar, 'satirlar' => $satirlar, 'bilinmeyen' => $bilinmeyen];
    } catch (Throwable $e) {
        error_log('[pdks_fis_oku] ' . $e->getMessage());
        return ['hata' => 'Dosya okunamadı: bozuk ya da desteklenmeyen Excel dosyası.'] + $son;
    } finally {
        if ($ss instanceof Spreadsheet) $ss->disconnectWorksheets();
    }
}

// =========================================================
// H. OCR KOMUTU + ŞABLON ÜRETİMİ
// =========================================================

/**
 * Kullanıcının fotoğraflarla birlikte dış yapay zekâya verdiği komut metni (TEK yer: Açıklama sayfası ve
 * yükleme sayfasındaki kutu bunu kullanır). "YORUM KURALLARI" bölümü sahibin cevaplarına göre güncellenecek
 * TEK yerdir (özellikle "12 + 1" ve "N kişi : M yarım"); kod mantığı bundan bağımsızdır.
 */
function pdks_fis_ocr_komutu(): string
{
    return <<<'KOMUT_SONU'
GÖREV: Ekteki fotoğraflarda kâğıt "İŞLETME İŞÇİ FİŞİ" belgeleri var (her biri ayrı bir fiş; bir fotoğrafta birden çok fiş olabilir, aynı fiş iki fotoğrafta da çıkabilir). Sana verdiğim "Fis_Excel_Sablonu" dosyasının "Fişler" sayfasını doldur ve DOLU XLSX DOSYASINI indirilebilir olarak ver. Dosya üretemiyorsan, ilk satırı şablon başlıklarıyla BİREBİR aynı olan, TAB ile ayrılmış bir tablo ver.

BİÇİM KURALLARI
1. Her fiş = "Fişler" sayfasında TEK satır. 1. satırdaki başlıkları değiştirme; sütun ekleme, silme, sıra değiştirme yapma; başka sayfaya dokunma. Aynı fiş iki fotoğraftaysa tek satır yaz ve Kontrol Notu'na "iki fotoğraf" yaz.
2. HİÇBİR ŞEY UYDURMA. Okuyamadığın ya da emin olmadığın hücreyi BOŞ bırak ve "Kontrol Notu" sütununa neyin neden belirsiz olduğunu yaz (örn. "Erkek: 12 mi 17 mi okunamadı"). Üstü çizilmiş, silinmiş ya da ikinci kalemle düzeltilmiş yazı varsa onu da Kontrol Notu'na yaz.
3. "Fiş No": METİN olarak yaz, baştaki sıfırları koru (örn. 01410). Sayıya çevirme.
4. "Tarih": GG.AA.YYYY biçiminde.
5. "İşletme" ve "Çavuş Adı": fişte yazdığı gibi yaz, düzeltme yapma. "Çavuş Kodu": "Çavuşlar" sayfasında, fişteki çavuş adıyla AYNI kişiyi gösteren satırın kodunu yaz; tereddüt varsa boş bırak.
6. Sayı sütunlarına (Kadın/Erkek/Rampacı Tam-Yarım, FM..., Servis...) yalnız TAM SAYI yaz. "kişi" gibi sözcük, "+" işareti ya da formül KULLANMA. Sıfır ise boş bırakabilirsin.
7. Hiçbir hücrede formül kullanma; yalnız değer yaz.
8. "Ham Metin": fişteki alanları AYNEN ve etiketleriyle yaz. Örnek: Bayan: 8 | Erkek: 12 + 1 | Toplam Mesai: 5 kişi 2 saat | Servis: 1 küçük | Palet: Rampa
9. "Palet / Not": "İşlenen Palet Adedi" alanındaki yazıyı aynen yaz ("Rampa", "Döküme araba" gibi).
10. "Giriş Saati" ve "Revizyon": fişte yoksa BOŞ bırak.

YORUM KURALLARI
A. Bayan sayısı -> "Kadın Tam". Erkek sayısı -> "Erkek Tam". Alanda "A + B" yazıyorsa A -> Tam, B -> Yarım ("Erkek Yarım").
B. Rampacı için fişte ayrı bir sayı yoksa Rampacı sütunlarını BOŞ bırak ve Kontrol Notu'na "Rampacı belirtilmemiş" yaz.
C. "Toplam Mesai" (fazla mesai) alanı:
   - Yalnız toplam saat varsa ("25 saat mesai tır çıktı") -> "Toplam FM Saati" = 25. Kişi sayısı yazılmamışsa "FM Kişi Sayısı" boş.
   - "N kişi X saat" ise (örn. "5 kişi 2 saat") -> "FM X Sa (Kişi)" sütununa N.
   - "3 kişi : 1 yarım", "2 kişi : 2 yarım" gibi anlamı belirsiz yazımlarda HİÇBİR FM/Yarım sütununu doldurma; ham metne aynen yaz ve Kontrol Notu'na "Toplam Mesai yorumlanamadı" yaz.
   - Fişte fazla mesai yoksa FM sütunları boş.
D. "Servis": "1 küçük" -> Servis Küçük = 1; "2 büyük" -> Servis Büyük = 2.
E. FM için iki yöntemden YALNIZ BİRİNİ kullan: "FM n Sa (Kişi)" kovaları ya da "Toplam FM Saati". Aynı bilgiyi ikisine birden yazma.

BİTİŞ: Kaç fiş okuduğunu ve kaç satıra Kontrol Notu yazdığını tek cümleyle bildir.
KOMUT_SONU;
}

/**
 * Şablon çalışma kitabı: Fişler (başlık + biçimli/doğrulamalı 150 satır) · Çavuşlar (güncel liste) · Açıklama ·
 * Örnek. Tüm metin hücreleri setCellValueExplicit(TYPE_STRING) — formül YOK. Doğrulamalar yardımcıdır; asıl güvenlik
 * sunucu okumasındadır (doğrulama yapıştırmayla atlanabilir).
 */
function pdks_fis_sablon_uret(PDO $pdo): Spreadsheet
{
    xlsx_motor_yukle();
    $sut = pdks_fis_sutunlar();
    $son = PDKS_FIS_MAX_FIS + 1;
    $metin = static fn($ws, $hucre, $v) => $ws->setCellValueExplicit($hucre, (string)$v, DataType::TYPE_STRING);

    $ss = new Spreadsheet();
    $ws = $ss->getActiveSheet();
    $ws->setTitle(PDKS_FIS_SAYFA);
    $ws->getTabColor()->setARGB('FF1F4E78');

    // ---- başlık + biçimler ----
    $sonHarf = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($sut));
    foreach ($sut as $i => $c) {
        $harf = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $metin($ws, $harf . '1', $c['baslik']);
        $ws->getColumnDimension($harf)->setWidth((float)$c['genislik']);
        $biçim = match ($c['tip']) { 'metin', 'liste' => '@', 'tarih' => 'dd.mm.yyyy', 'saat' => 'hh:mm', default => '0' };
        $ws->getStyle("{$harf}2:{$harf}{$son}")->getNumberFormat()->setFormatCode($biçim);
    }
    $ws->getStyle("A1:{$sonHarf}1")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => XLSX_RENK_BLOK]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ]);
    $ws->getRowDimension(1)->setRowHeight(34);
    $ws->freezePane('B2');

    // ---- çavuş listesi (Çavuşlar sayfası) ----
    $cavuslar = pdks_fis_cavuslar($pdo);
    $aktif = array_values(array_filter($cavuslar, fn($c) => !empty($c['is_active'])));
    $pasif = array_values(array_filter($cavuslar, fn($c) => empty($c['is_active'])));
    $kodSira = fn($a, $b) => strcmp($a['code'], $b['code']);
    usort($aktif, $kodSira); usort($pasif, $kodSira);
    $cv = $ss->createSheet();
    $cv->setTitle('Çavuşlar');
    $cv->getTabColor()->setARGB('FF666666');
    foreach (['Kod', 'Ad', 'Durum'] as $i => $b) $metin($cv, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . '1', $b);
    $cv->getStyle('A1:C1')->getFont()->setBold(true);
    $r = 2;
    foreach ([[$aktif, 'Aktif'], [$pasif, 'Pasif']] as [$liste, $durum]) {
        foreach ($liste as $c) {
            $metin($cv, "A$r", pdks_fis_metin($c['code'], 40)); $metin($cv, "B$r", pdks_fis_metin($c['name'], 150)); $metin($cv, "C$r", $durum);
            $r++;
        }
    }
    $cv->getColumnDimension('A')->setWidth(14); $cv->getColumnDimension('B')->setWidth(34); $cv->getColumnDimension('C')->setWidth(10);
    $cv->freezePane('A2');
    $aktifSon = 1 + count($aktif);

    // ---- veri doğrulamaları ----
    $dvKur = function (string $harf, DataValidation $dv) use ($ws, $son): void { $ws->setDataValidation("{$harf}2:{$harf}{$son}", $dv); };
    foreach ($sut as $i => $c) {
        $harf = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $dv = new DataValidation();
        if ($c['tip'] === 'tam' && $c['maks'] !== null) {
            $dv->setType(DataValidation::TYPE_WHOLE)->setOperator(DataValidation::OPERATOR_BETWEEN)->setFormula1('0')->setFormula2((string)$c['maks'])
               ->setAllowBlank(true)->setShowErrorMessage(true)->setErrorStyle(DataValidation::STYLE_STOP)
               ->setErrorTitle('Geçersiz sayı')->setError('0 ile ' . $c['maks'] . ' arasında bir tam sayı girin.');
            $dvKur($harf, $dv);
        } elseif ($c['anahtar'] === 'tarih') {
            $dv->setType(DataValidation::TYPE_DATE)->setOperator(DataValidation::OPERATOR_GREATERTHANOREQUAL)->setFormula1('43831')
               ->setAllowBlank(true)->setShowErrorMessage(true)->setErrorStyle(DataValidation::STYLE_STOP)
               ->setErrorTitle('Geçersiz tarih')->setError('01.01.2020 veya sonrası bir tarih girin (GG.AA.YYYY; bugünden önce olmalı).');
            $dvKur($harf, $dv);
        } elseif ($c['anahtar'] === 'cavus_kodu' && $aktifSon > 1) {
            $dv->setType(DataValidation::TYPE_LIST)->setFormula1("'Çavuşlar'!\$A\$2:\$A\${$aktifSon}")
               ->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(true)->setErrorStyle(DataValidation::STYLE_WARNING)
               ->setErrorTitle('Çavuş listede yok')->setError('Bu kod «Çavuşlar» sayfasında yok (pasif ya da yeni çavuş olabilir). Yine de devam edilsin mi?');
            $dvKur($harf, $dv);
        } elseif ($c['anahtar'] === 'fm_tipi') {
            $dv->setType(DataValidation::TYPE_LIST)->setFormula1('"' . implode(',', $c['liste']) . '"')
               ->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(true)->setErrorStyle(DataValidation::STYLE_STOP)
               ->setErrorTitle('FM Tipi')->setError('Listeden seçin: ' . implode(', ', $c['liste']) . '.');
            $dvKur($harf, $dv);
        } elseif (in_array($c['anahtar'], ['giris', 'fis_no'], true)) {
            $dv->setType(DataValidation::TYPE_NONE)->setShowInputMessage(true)->setPromptTitle($c['baslik'])->setPrompt($c['ipucu']);
            $dvKur($harf, $dv);
        }
    }

    // ---- Açıklama ----
    $ac = $ss->createSheet();
    $ac->setTitle('Açıklama');
    $ac->getTabColor()->setARGB('FFB45309');
    $ac->getColumnDimension('A')->setWidth(26); $ac->getColumnDimension('B')->setWidth(11); $ac->getColumnDimension('C')->setWidth(110);
    $ar = 1;
    $satirYaz = function (string $t, bool $kalin = false, ?float $yukseklik = null) use ($ac, &$ar, $metin): void {
        $metin($ac, "A$ar", $t);
        $ac->mergeCells("A$ar:C$ar");
        $ac->getStyle("A$ar")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        if ($kalin) $ac->getStyle("A$ar")->getFont()->setBold(true)->setSize(12);
        $ac->getRowDimension($ar)->setRowHeight($yukseklik ?? max(16.0, 15.0 * (int)ceil(mb_strlen($t, 'UTF-8') / 150) + 2));
        $ar++;
    };
    $satirYaz('Fiş Excel\'i — şablon açıklaması', true);
    $satirYaz('1) Fişlerin fotoğraflarını yapay zekâya verin (aşağıdaki KOMUT metniyle) ve bu dosyanın «Fişler» sayfasını doldurmasını isteyin.');
    $satirYaz('2) Dolu dosyayı Günlük Puantaj › «📥 Fiş Excel\'i Yükle» sayfasında yükleyin, önizlemeyi kontrol edin, ardından «Yaz» deyin.');
    $satirYaz('3) Hata çıkan satırları Excel\'de düzeltip AYNI dosyayı yeniden yükleyin: yazılmış fişler «zaten yüklü» diye atlanır, çiftlenmez.');
    $ar++;
    $satirYaz('Sütunlar', true);
    foreach (['Başlık', 'Zorunlu', 'Ne yazılır'] as $i => $b) { $h = ['A', 'B', 'C'][$i] . $ar; $metin($ac, $h, $b); }
    $ac->getStyle("A$ar:C$ar")->getFont()->setBold(true);
    $ar++;
    foreach ($sut as $c) {
        $zorunlu = $c['anahtar'] === 'cavus_kodu' ? 'Kod ya da Ad' : ($c['anahtar'] === 'cavus_adi' ? 'Kod ya da Ad' : ($c['zorunlu'] ? 'Evet' : 'Hayır'));
        $metin($ac, "A$ar", $c['baslik']); $metin($ac, "B$ar", $zorunlu); $metin($ac, "C$ar", $c['ipucu']);
        $ac->getStyle("C$ar")->getAlignment()->setWrapText(true);
        $ar++;
    }
    $ar++;
    $satirYaz('Sistem ne yapar', true);
    foreach ([
        'Giriş saati boşsa 08:00 alınır. Tam gün çıkışı = giriş + çavuşun normal süresi (genelde 9 sa → 17:00). N saat fazla mesai = Tam çıkışından N saat sonra. Yarım gün = Tam süresinin yarısı (9 sa → 4 sa 30 dk).',
        'FM'.'\'ye giren kişiler Tam kişilerden sayılır: Erkek Tam 12, «FM 2 Sa (Kişi)» 5 → 7 kişi Tam, 5 kişi Tam + 2 saat fazla mesai. Yarım gün çalışanlara FM verilmez.',
        'Fazla mesai iki biçimde girilir (YALNIZ BİRİNİ seçin): «FM n Sa (Kişi)» sütunları (o kadar saat yapan kişi sayısı) ya da «Toplam FM Saati» (+ isteğe bağlı «FM Kişi Sayısı»): sistem saatleri kişilere belirli bir kuralla (rastgele değil) dağıtır. FM fiyatları tipler arasında farklıysa toplam saat yolu KAPALIDIR (kova sütunlarını doldurun).',
        'Yarım gün kayıtları «Yarım» olarak yazılır (önizlemedeki onay kutusu ile). Fazla mesai önizlemedeki onay kutusuyla onaylı yazılır.',
        'Servis Büyük/Küçük adetleri mesaiye yazılır; mesaide daha önce girilmiş servis varsa yalnız eksik fark eklenir.',
        'Aynı Fiş No + Revizyon ikinci kez yazılmaz. Yazılmış fişi düzeltmek için: Mesai Detayı › Toplu İşlemler › Geri Al, sonra bu fişin Revizyon\'unu 1 artırıp yeniden yükleyin.',
        'Yalnız bugünden ÖNCEKİ günlerin fişleri yazılır; en çok ' . PDKS_FIS_MAX_FIS . ' fiş, en çok 2 MB, yalnız .xlsx. Mesaide aktif kayıt varsa fiş durur («Mevcut kayıtlara EK yaz» kutusuyla eklenebilir).',
        'Ham Metin ve Kontrol Notu yalnız önizlemede gösterilir; veritabanına yazılmaz.',
    ] as $kural) $satirYaz('• ' . $kural);
    $ar++;
    $satirYaz('Yapay zekâya verilecek KOMUT (metni hücreye tıklayıp formül çubuğundan ya da yükleme sayfasındaki «Kopyala» düğmesiyle alın):', true);
    $metin($ac, "A$ar", pdks_fis_ocr_komutu());
    $ac->mergeCells("A$ar:C$ar");
    $ac->getStyle("A$ar")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
    $ac->getRowDimension($ar)->setRowHeight(409);
    $ar += 2;
    $satirYaz('Şablon sürümü: ' . PDKS_FIS_SABLON_SURUMU);

    // ---- Örnek ----
    $ork = $ss->createSheet();
    $ork->setTitle('Örnek');
    $ork->getTabColor()->setARGB('FF7A8794');
    foreach ($sut as $i => $c) {
        $harf = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $metin($ork, $harf . '1', $c['baslik']);
        $ork->getColumnDimension($harf)->setWidth((float)$c['genislik']);
    }
    $ork->getStyle("A1:{$sonHarf}1")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => XLSX_RENK_BLOK]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ]);
    $ork->getRowDimension(1)->setRowHeight(34);
    $ornek = [
        ['fis_no' => 'ORNEK-1', 'tarih' => '01.10.2026', 'isletme' => 'Örnek İşletme', 'cavus_kodu' => 'C001', 'cavus_adi' => 'Örnek Çavuş A', 'kadin_tam' => 8, 'erkek_tam' => 12, 'erkek_yarim' => 1,
         'fm2' => 5, 'fm_tipi' => 'Erkek', 'servis_kucuk' => 1, 'giris' => '08:00', 'not' => 'Rampa', 'ham' => 'Bayan 8 | Erkek 12 + 1 | Toplam Mesai: 5 kişi 2 saat | Servis: 1 küçük | Palet: Rampa'],
        ['fis_no' => 'ORNEK-2', 'tarih' => '01.10.2026', 'isletme' => 'Örnek İşletme', 'cavus_kodu' => 'C002', 'cavus_adi' => 'Örnek Çavuş B', 'kadin_tam' => 8, 'erkek_tam' => 12, 'rampaci_tam' => 2, 'rampaci_yarim' => 1,
         'fm_toplam' => 25, 'servis_buyuk' => 1, 'ham' => 'Bayan 8 | Erkek 12 | Rampacı 2 + 1 | Toplam Mesai: 25 saat mesai tır çıktı | Servis: 1 büyük', 'kontrol' => 'Rampacı sayısı fişte yok, kullanıcı ekledi'],
        ['fis_no' => 'ORNEK-3', 'tarih' => '01.10.2026', 'isletme' => 'Örnek İşletme', 'cavus_kodu' => 'C003', 'cavus_adi' => 'Örnek Çavuş C', 'kadin_tam' => 5, 'giris' => '09:00'],
    ];
    foreach ($ornek as $ri => $o) {
        $satir = 2 + $ri;
        foreach ($sut as $i => $c) {
            if (!array_key_exists($c['anahtar'], $o)) continue;
            $v = $o[$c['anahtar']];
            $h = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . $satir;
            if (is_int($v)) $ork->setCellValue($h, $v);
            else $metin($ork, $h, $v);
        }
    }
    $metin($ork, 'A6', 'Bu satırlar yalnız örnektir; «Fişler» sayfasına KOPYALAMAYIN (ORNEK- ile başlayan Fiş No reddedilir).');
    $ork->getStyle('A6')->getFont()->setItalic(true)->getColor()->setARGB(XLSX_RENK_SOLUK);

    $ss->setActiveSheetIndex(0);
    return $ss;
}
