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
 * Serbest metni DB/audit/ekran için temizler: skaler değilse '', denetim karakterleri atılır (satır sonu/sekme
 * kalır), trim, formül/CSV enjeksiyonuna karşı ilk karakter = + - @ ise başına ' eklenir, $maks karaktere kırpılır.
 */
function pdks_fis_metin($x, int $maks = 500): string
{
    if (!is_scalar($x) || is_bool($x)) return '';
    $s = is_float($x) ? rtrim(rtrim(sprintf('%.10F', $x), '0'), '.') : (string)$x;
    $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    if ($t === null) return '';   // geçersiz UTF-8
    $t = trim($t);
    if ($t !== '' && strpbrk($t[0], '=+-@') !== false) $t = "'" . $t;
    return mb_substr($t, 0, max(0, $maks), 'UTF-8');
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
