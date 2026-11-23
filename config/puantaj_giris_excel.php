<?php
// =========================================================
// config/puantaj_giris_excel.php — Puantaj Girişi › Kişiler › "Excel'den ekle" (v555)
//
// Kişi listesini .xlsx / .csv dosyasından TOPLU ekler. Önizleme (kaydetmez) ve ekleme iki ayrı eylemdir
// (puantaj_giris_kaydet.php › excel_onizle / excel_ekle); ekleme dosyayı YENİDEN okuyup YENİDEN ayrıştırır
// (sunucuda oturumda/diskte tutulmaz). Güvenlik (Fiş Excel kurallarıyla aynı çizgi):
//   · yüklenen dosya DİSKE KAYDEDİLMEZ — yalnız PHP'nin geçici yükleme dosyasından okunur (move_uploaded_file / file_put_contents YOK)
//   · boyut ≤ 2 MB, en çok 500 veri satırı; xlsx: PK + zip denetimi (makro / dış bağlantı / gömülü nesne / zip bombası RED)
//   · okuma ReadDataOnly, YALNIZ ilk sayfa, satır + sütun süzgeci; formül ASLA hesaplanmaz (önbellek değeri varsa o, yoksa boş sayılır)
//   · serbest metin pg_metin_temizle() (denetim karakteri atılır, kırpılır); "=+-@" ile başlayan metin düz metin kalır
//     (ekranda h() ile, dışa aktarımda metin hücresi / CSV öneki ile korunur)
//   · kişiler yalnız puantaj_giris_kisiler'e eklenir (aktif depo damgası); başka tabloya dokunulmaz
// Saf ayrıştırıcılar DB bilmez → scripts/puantaj_cizelgesi_smoke.php.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/xlsx_export.php';

const PGE_MAX_BAYT = 2097152;        // 2 MB
const PGE_MAX_SATIR = 500;           // en çok veri satırı
const PGE_ONIZLEME_SATIR = 20;       // önizlemede gösterilen satır
const PGE_MAX_SUTUN = 10;            // yalnız ilk 10 sütun okunur
const PGE_BASLIK_ARAMA = 5;          // başlık satırı ilk kaç satırda aranır

/** Başlık takma adları (pc_fold + noktalama atılmış). */
function pge_ad_basliklari(): array
{
    return ['ad', 'adi', 'ad soyad', 'adi soyadi', 'ad soyadi', 'adsoyad', 'isim', 'isim soyisim', 'isim soyad', 'adi soyad', 'personel', 'personel adi', 'kisi', 'kisi adi', 'name', 'full name'];
}
function pge_gorev_basliklari(): array
{
    return ['gorev', 'gorevi', 'unvan', 'unvani', 'pozisyon', 'meslek', 'title', 'not', 'aciklama'];
}

/** v562: «bağlı çavuş» sütun başlığı takma adları. */
function pge_cavus_basliklari(): array
{
    return ['cavus', 'cavusu', 'bagli cavus', 'bagli cavusu', 'cavus adi', 'cavus ad', 'foreman'];
}

/**
 * v562: aktif çavuşlar → [katlanmış ad => id] (YALNIZ OKUNUR). Aynı katlanmış ada iki çavuş varsa o ad belirsizdir → eşleşmez.
 * @return array<string,int>
 */
function pge_cavus_harita(PDO $pdo): array
{
    $h = []; $cift = [];
    foreach (pg_cavuslar_oku($pdo) as $c) {
        if ($c['aktif'] !== 1) continue;
        $k = pc_fold($c['ad']);
        if ($k === '') continue;
        if (isset($h[$k])) $cift[$k] = true; else $h[$k] = $c['id'];
    }
    foreach ($cift as $k => $_) unset($h[$k]);
    return $h;
}

/** Başlık karşılaştırması için katlama: TR duyarsız, noktalama → boşluk, tek boşluk. */
function pge_baslik_kat(string $s): string
{
    $s = pc_fold($s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';
    return trim(preg_replace('/\s+/', ' ', $s) ?? '');
}

/** Hücre değerini metne çevirir (formül/başlangıç karakteri DEĞİŞTİRİLMEZ; denetim karakterleri atılır). Skaler değilse ''. */
function pge_hucre_metin($v): string
{
    if (is_bool($v) || !is_scalar($v)) return '';
    if (is_float($v)) $v = (floor($v) === $v && abs($v) < 1e15) ? (string)(int)$v : rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
    return pg_metin_temizle((string)$v);
}

// ── Dosya doğrulama (PhpSpreadsheet'e girmeden) ─────────────

/**
 * Yüklenen dosyayı doğrular. Test kancası: $yuklemeDenetimi (tmp yolunu alır, gerçek HTTP yüklemesi mi?) — varsayılan is_uploaded_file.
 * @return array{ok:bool,hata?:string,yol?:string,tur?:string,bayt?:int}
 */
function pge_dosya_dogrula(array $dosya, ?callable $yuklemeDenetimi = null): array
{
    $err = (int)($dosya['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        $m = match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Dosya çok büyük (en çok 2 MB).',
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
    if ($bayt > PGE_MAX_BAYT) return ['ok' => false, 'hata' => 'Dosya çok büyük (en çok 2 MB).'];
    $ext = strtolower(pathinfo((string)($dosya['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        $bas = (string)@file_get_contents($tmp, false, null, 0, 4096);
        if (str_contains($bas, "\0") || strncmp($bas, "PK\x03\x04", 4) === 0) return ['ok' => false, 'hata' => 'Bu dosya CSV değil. Excel dosyasıysa .xlsx olarak kaydedin.'];
        return ['ok' => true, 'yol' => $tmp, 'tur' => 'csv', 'bayt' => $bayt];
    }
    if ($ext !== 'xlsx') return ['ok' => false, 'hata' => 'Yalnız .xlsx ya da .csv kabul edilir (.xls / .xlsm değil). Excel\'de Farklı Kaydet › Excel Çalışma Kitabı (.xlsx) seçin.'];
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
    return ['ok' => true, 'yol' => $tmp, 'tur' => 'xlsx', 'bayt' => $bayt];
}

// ── Okuma ───────────────────────────────────────────────────

/**
 * CSV içeriğini dizi satırlarına çevirir: UTF-8 (BOM'lu/BOM'suz) ya da Windows-1254; ayraç ; , ya da sekme (ilk dolu satırdan sezilir).
 * Bellekte işlenir (disk yok). Dönüş: list<list<string>> — her satır en çok PGE_MAX_SUTUN hücre; tamamen boş satırlar atılır.
 * @return list<list<string>>
 */
function pge_csv_coz(string $icerik): array
{
    if (strncmp($icerik, "\xEF\xBB\xBF", 3) === 0) $icerik = substr($icerik, 3);
    if (!mb_check_encoding($icerik, 'UTF-8')) $icerik = (string)mb_convert_encoding($icerik, 'UTF-8', 'Windows-1254');
    $ilk = '';
    foreach (preg_split('/\r\n|\r|\n/', $icerik) ?: [] as $satir) { if (trim($satir) !== '') { $ilk = $satir; break; } }
    $ayrac = ';';
    $say = [';' => substr_count($ilk, ';'), ',' => substr_count($ilk, ','), "\t" => substr_count($ilk, "\t")];
    arsort($say);
    if ((int)reset($say) > 0) $ayrac = (string)array_key_first($say);
    $h = fopen('php://memory', 'r+');
    if ($h === false) return [];
    fwrite($h, $icerik);
    rewind($h);
    $out = [];
    $okunan = 0;
    while (($r = fgetcsv($h, 0, $ayrac, '"', '\\')) !== false) {
        if (++$okunan > PGE_MAX_SATIR + PGE_BASLIK_ARAMA + 20) break;   // aşırı büyük dosya: sınır denetimi pge_satirlari_coz'de
        $r = array_slice(array_map(static fn($v) => pge_hucre_metin($v), $r), 0, PGE_MAX_SUTUN);
        if (implode('', $r) === '') continue;
        $out[] = $r;
    }
    fclose($h);
    return $out;
}

/**
 * XLSX'in İLK sayfasını güvenle okur (ReadDataOnly, satır/sütun süzgeci, formül HESAPLANMAZ). Dönüş: list<list<string>>.
 * Hata: ['hata' => mesaj] (satır dizisi yerine).
 * @return array{hata?:string}|list<list<string>>
 */
function pge_xlsx_oku(string $yol): array
{
    xlsx_motor_yukle();
    $lim = ini_get('memory_limit');
    if ($lim !== false && $lim !== '-1' && xlsx_bayt((string)$lim) < 256 * 1048576) @ini_set('memory_limit', '256M');
    @set_time_limit(60);
    $ss = null;
    try {
        $r = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
        $r->setReadDataOnly(true);
        $r->setReadEmptyCells(false);
        $adlar = $r->listWorksheetNames($yol);
        if (!$adlar) return ['hata' => 'Excel dosyasında sayfa bulunamadı.'];
        $r->setLoadSheetsOnly([(string)$adlar[0]]);
        $enSatir = PGE_MAX_SATIR + PGE_BASLIK_ARAMA + 20;
        $r->setReadFilter(new class($enSatir) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
            public function __construct(private int $enSatir) {}
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->enSatir && \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress) <= PGE_MAX_SUTUN;
            }
        });
        $ss = $r->load($yol);
        $ws = $ss->getActiveSheet();
        $hucre = [];
        foreach ($ws->getCoordinates() as $koor) {
            $c = $ws->getCell($koor);
            [$harf, $satir] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($koor);
            $sutun = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($harf) - 1;
            if ($c->getDataType() === \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA) {
                $onbellek = $c->getOldCalculatedValue();   // HESAPLANMAZ; yalnız dosyadaki önbellek değeri
                $hucre[(int)$satir][$sutun] = ($onbellek !== null && is_scalar($onbellek)) ? pge_hucre_metin($onbellek) : '';
                continue;
            }
            $v = $c->getValue();
            if (is_object($v)) $v = method_exists($v, 'getPlainText') ? $v->getPlainText() : '';
            $hucre[(int)$satir][$sutun] = pge_hucre_metin($v);
        }
        ksort($hucre);
        $out = [];
        foreach ($hucre as $sutunlar) {
            $satir = [];
            for ($i = 0; $i < PGE_MAX_SUTUN; $i++) $satir[] = $sutunlar[$i] ?? '';
            if (implode('', $satir) === '') continue;
            $out[] = $satir;
        }
        return $out;
    } catch (Throwable $e) {
        error_log('[puantaj_giris_excel] oku: ' . $e->getMessage());
        return ['hata' => 'Excel dosyası okunamadı (bozuk ya da desteklenmeyen biçim).'];
    } finally {
        if ($ss !== null) $ss->disconnectWorksheets();
    }
}

/** Doğrulanmış dosyadan ham satırlar. @return array{hata?:string}|list<list<string>> */
function pge_dosya_oku(array $dogrulanmis): array
{
    if (($dogrulanmis['tur'] ?? '') === 'csv') {
        $icerik = @file_get_contents((string)$dogrulanmis['yol']);
        if ($icerik === false) return ['hata' => 'Dosya okunamadı.'];
        return pge_csv_coz($icerik);
    }
    return pge_xlsx_oku((string)$dogrulanmis['yol']);
}

// ── Ayrıştırma (saf) ────────────────────────────────────────

/**
 * Ham satırlardan başlık satırını bulur (ilk PGE_BASLIK_ARAMA satırda "Ad / Ad Soyad / İsim" + isteğe bağlı "Görev / Ünvan");
 * bulunamazsa başlıksız: 1. sütun ad, 2. sütun görev, ilk satırdan itibaren veri.
 * @return array{baslik:bool,ad:int,gorev:int,cavus:int,bas:int}   bas = ilk veri satırının dizin numarası
 */
function pge_baslik_bul(array $satirlar): array
{
    $adS = array_flip(pge_ad_basliklari());
    $gorS = array_flip(pge_gorev_basliklari());
    $cavS = array_flip(pge_cavus_basliklari());
    for ($i = 0, $n = min(count($satirlar), PGE_BASLIK_ARAMA); $i < $n; $i++) {
        $ad = -1; $gorev = -1; $cavus = -1;
        foreach ($satirlar[$i] as $j => $v) {
            $k = pge_baslik_kat((string)$v);
            if ($k === '') continue;
            if ($ad < 0 && isset($adS[$k])) $ad = (int)$j;
            elseif ($gorev < 0 && isset($gorS[$k])) $gorev = (int)$j;
            elseif ($cavus < 0 && isset($cavS[$k])) $cavus = (int)$j;
        }
        if ($ad >= 0) return ['baslik' => true, 'ad' => $ad, 'gorev' => $gorev, 'cavus' => $cavus, 'bas' => $i + 1];
    }
    return ['baslik' => false, 'ad' => 0, 'gorev' => 1, 'cavus' => -1, 'bas' => 0];
}

/**
 * Ham satırları kişi satırlarına çevirir ve sınıflar.
 * durum: gecerli | bos (ad yok) | uzun (ad > 120 ya da görev > 80) | mukerrer_mevcut | mukerrer_dosya.
 * Mükerrer anahtarı: TR duyarsız katlanmış ad (aynı depoda zaten görünen kişilerle + dosya içinde). İlk geçerli satır kalır.
 * $mevcutAdlar: katlanmış ad listesi. v562: $cavusHarita (pge_cavus_harita; null = «bağlı çavuş» kurulu değil) verilirse dosyadaki «Çavuş» sütunu
 * adla eşlenir: eşleşirse kişi o çavuşa bağlanır ('cavus_durum'=eslesti), eşleşmezse satır YİNE eklenir ama çavuşsuz ('eslesmedi'; ozet.cavus_eslesmedi). Tamamen boş satırlar sayılmaz. 500'den çok veri satırı → ['hata'].
 * @return array{hata?:string,satirlar?:list<array>,ozet?:array,baslik?:bool}
 */
function pge_satirlari_coz(array $ham, array $mevcutAdlar, ?array $cavusHarita = null): array
{
    $b = pge_baslik_bul($ham);
    $veri = array_values(array_filter(array_slice($ham, $b['bas']), static fn($r) => implode('', array_map('strval', $r)) !== ''));   // tamamen boş satırlar sayılmaz
    if (count($veri) > PGE_MAX_SATIR) return ['hata' => 'Dosyada en çok ' . PGE_MAX_SATIR . ' satır olabilir (' . count($veri) . ' satır bulundu). Dosyayı bölüp ayrı ayrı yükleyin.'];
    $mevcut = array_flip($mevcutAdlar);
    $goruldu = [];
    $satirlar = [];
    $ozet = ['toplam' => 0, 'gecerli' => 0, 'bos' => 0, 'uzun' => 0, 'mukerrer' => 0, 'mukerrer_mevcut' => 0, 'mukerrer_dosya' => 0, 'cavus_eslesmedi' => 0];
    foreach ($veri as $i => $r) {
        $ad = pge_hucre_metin($r[$b['ad']] ?? '');
        $gorev = $b['gorev'] >= 0 ? pge_hucre_metin($r[$b['gorev']] ?? '') : '';
        $cavusMetin = ($cavusHarita !== null && $b['cavus'] >= 0) ? pge_hucre_metin($r[$b['cavus']] ?? '') : '';
        $cavusId = null; $cavusDurum = '';
        if ($cavusMetin !== '') {
            $cavusId = $cavusHarita[pc_fold($cavusMetin)] ?? null;
            $cavusDurum = $cavusId !== null ? 'eslesti' : 'eslesmedi';
        }
        $satir = $b['bas'] + $i + 1;   // boş satırlar atlandığı için dosyadaki satır no'ya YAKLAŞIK sıra no; yalnız gösterim
        if ($ad === '') $durum = 'bos';
        elseif (mb_strlen($ad, 'UTF-8') > PG_AD_MAX || mb_strlen($gorev, 'UTF-8') > PG_GOREV_MAX) $durum = 'uzun';
        else {
            $anahtar = pc_fold($ad);
            if (isset($mevcut[$anahtar])) $durum = 'mukerrer_mevcut';
            elseif (isset($goruldu[$anahtar])) $durum = 'mukerrer_dosya';
            else { $durum = 'gecerli'; $goruldu[$anahtar] = true; }
        }
        $ozet['toplam']++;
        if ($durum === 'gecerli') { $ozet['gecerli']++; if ($cavusDurum === 'eslesmedi') $ozet['cavus_eslesmedi']++; }
        elseif ($durum === 'bos') $ozet['bos']++;
        elseif ($durum === 'uzun') $ozet['uzun']++;
        else { $ozet['mukerrer']++; $ozet[$durum]++; }
        $satirlar[] = ['satir' => $satir, 'ad' => $ad, 'gorev' => $gorev, 'durum' => $durum, 'cavus' => mb_substr($cavusMetin, 0, 150, 'UTF-8'), 'cavus_id' => $cavusId, 'cavus_durum' => $cavusDurum];
    }
    return ['satirlar' => $satirlar, 'ozet' => $ozet, 'baslik' => $b['baslik']];
}

/** Önizleme yanıtı: özet + ilk PGE_ONIZLEME_SATIR satır (uzun metinler 150 karaktere kırpılır; istemci textContent ile basar). */
function pge_onizleme_yaniti(array $cozum): array
{
    $ilk = [];
    foreach (array_slice($cozum['satirlar'], 0, PGE_ONIZLEME_SATIR) as $s) {
        $ilk[] = ['satir' => $s['satir'], 'ad' => mb_substr($s['ad'], 0, 150, 'UTF-8'), 'gorev' => mb_substr($s['gorev'], 0, 150, 'UTF-8'), 'durum' => $s['durum'], 'cavus' => (string)($s['cavus'] ?? ''), 'cavus_durum' => (string)($s['cavus_durum'] ?? '')];
    }
    return ['ozet' => $cozum['ozet'], 'baslik_bulundu' => (bool)$cozum['baslik'], 'ilk' => $ilk, 'gosterilen' => count($ilk)];
}

/** Mevcut kişi adları (görünür depo kuralı; pasifler dahil) → katlanmış ad listesi. */
function pge_mevcut_adlar(PDO $pdo): array
{
    return array_values(array_unique(array_map(static fn($k) => pc_fold($k['ad']), pg_kisiler_oku($pdo, true))));
}

// ── Yazma ───────────────────────────────────────────────────

/**
 * Geçerli satırları TEK transaction'da ekler (aktif depo damgası). Dönüş: ['ok','hata','eklenen'].
 * Çağıran audit'i tek özet olarak yazar (ad YOK).
 */
function pge_kisileri_ekle(PDO $pdo, array $satirlar, string $depo, ?int $userId): array
{
    $tx = !$pdo->inTransaction();
    $eklenen = 0;
    try {
        if ($tx) $pdo->beginTransaction();
        $simdi = date('Y-m-d H:i:s');
        $kolonVar = pg_cavus_kolon_var($pdo);
        $ekle = $kolonVar
            ? $pdo->prepare('INSERT INTO puantaj_giris_kisiler (ad, gorev, cavus_id, depo, aktif, sira, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, 1, 0, ?, ?, ?)')
            : $pdo->prepare('INSERT INTO puantaj_giris_kisiler (ad, gorev, depo, aktif, sira, created_by, created_at, updated_at) VALUES (?, ?, ?, 1, 0, ?, ?, ?)');
        foreach ($satirlar as $s) {
            if (($s['durum'] ?? '') !== 'gecerli') continue;
            $ad = pg_ad_dogrula($s['ad']); $gorev = pg_gorev_dogrula($s['gorev']);
            if ($ad === null || $gorev === null) continue;   // ayrıştırıcı zaten eler; kemer + askı
            if ($kolonVar) $ekle->execute([$ad, $gorev === '' ? null : $gorev, isset($s['cavus_id']) && (int)$s['cavus_id'] > 0 ? (int)$s['cavus_id'] : null, $depo, $userId, $simdi, $simdi]);
            else $ekle->execute([$ad, $gorev === '' ? null : $gorev, $depo, $userId, $simdi, $simdi]);
            $eklenen++;
        }
        if ($tx) $pdo->commit();
        return ['ok' => true, 'hata' => null, 'eklenen' => $eklenen];
    } catch (Throwable $e) {
        if ($tx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[puantaj_giris_excel] ekle: ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Kişiler eklenemedi.', 'eklenen' => 0];
    }
}

// ── Şablon ──────────────────────────────────────────────────

/** İndirilebilir şablon: "Kişiler" sayfası (başlık + 2 sentetik örnek satır) + "Açıklama" sayfası (okunmaz). */
function pge_sablon_uret(bool $cavus = false): \PhpOffice\PhpSpreadsheet\Spreadsheet
{
    xlsx_motor_yukle();
    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $ss->getActiveSheet();
    $ws->setTitle('Kişiler');
    $metin = \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;
    $sablon = $cavus ? [['Ad Soyad', 'Görev', 'Çavuş'], ['Örnek Kişi A', 'Paketçi', ''], ['Örnek Kişi B', '', '']] : [['Ad Soyad', 'Görev'], ['Örnek Kişi A', 'Paketçi'], ['Örnek Kişi B', '']];
    foreach ($sablon as $i => $satir) {
        foreach ($satir as $j => $v) $ws->setCellValueExplicit([$j + 1, $i + 1], $v, $metin);
    }
    $son = $cavus ? 'C' : 'B';
    $ws->getStyle('A1:' . $son . '1')->getFont()->setBold(true);
    $ws->getStyle('A1:' . $son . '1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB(XLSX_RENK_BASLIK_DOLGU);
    $ws->getColumnDimension('A')->setWidth(36);
    $ws->getColumnDimension('B')->setWidth(28);
    if ($cavus) $ws->getColumnDimension('C')->setWidth(28);
    $ws->freezePane('A2');
    $ac = $ss->createSheet();
    $ac->setTitle('Açıklama');
    $satirlar = [
        'Puantaj Girişi — Excel\'den kişi ekleme',
        '1) "Kişiler" sayfasındaki örnek satırları silin, kendi kişilerinizi yazın: A sütunu Ad Soyad (zorunlu), B sütunu Görev (isteğe bağlı).',
        '2) Başlık satırı (Ad Soyad / Görev) kalabilir; olmasa da ilk sütun ad, ikinci sütun görev kabul edilir.',
        '3) Dosyayı .xlsx (ya da .csv) kaydedip Kişiler › "Excel\'den ekle" ile yükleyin; önce önizleme gösterilir, onaylarsanız eklenir.',
        '4) En çok 500 satır ve 2 MB. Ad en çok 120, görev en çok 80 karakter. Aynı ad (büyük/küçük harf ve Türkçe harf farkı gözetilmez) zaten varsa atlanır.',
        '5) Formüller hesaplanmaz; hücreler düz metin olarak okunur.',
    ];
    if ($cavus) $satirlar[] = '6) Çavuş sütunu isteğe bağlıdır: Personel Takibi\'ndeki AKTİF bir çavuşun adını yazın (büyük/küçük harf ve Türkçe harf farkı gözetilmez); eşleşirse kişi o çavuşa bağlanır, eşleşmezse kişi yine eklenir ama çavuşsuz kalır.';
    foreach ($satirlar as $i => $m) $ac->setCellValueExplicit([1, $i + 1], $m, $metin);
    $ac->getColumnDimension('A')->setWidth(120);
    $ac->getStyle('A1')->getFont()->setBold(true);
    $ss->setActiveSheetIndex(0);
    return $ss;
}
