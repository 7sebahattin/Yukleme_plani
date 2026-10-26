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

require_once __DIR__ . '/pdks_faz8j.php';
require_once __DIR__ . '/pdks_faz8b.php';
require_once __DIR__ . '/pdks_servis.php';

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
