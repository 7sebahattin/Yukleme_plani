<?php
// =========================================================
// config/xlsx_export.php — Ortak XLSX dışa aktarım yardımcısı
//
// "⬇ Excel İndir ▾" menüsünün XLSX seçeneği buradan üretilir. Uç noktalar
// CSV için hazırladıkları satır dizisini AYNEN buraya verir; sorgu yazmaz.
// CSV çıktıları BİLEREK değiştirilmedi (başka yazılıma aktarılabilir) —
// bu dosya yalnız XLSX'i üretir.
//
// KAPSAM DIŞI: record_excel_template.php (hazır şablonlu yükleme planı) ve
// rapor_malzeme.php'nin kendi XLSX bloğu bu yardımcıyı KULLANMAZ; ikisi de
// kendi testleriyle sabitlenmiş durumda, dokunulmadı.
//
// Sayfa tanımı ($sayfalar listesinin her öğesi):
//   'ad'       => sekme adı (31 karaktere kırpılır, yasak karakterler atılır)
//   'baslik'   => A1'deki rapor başlığı
//   'aciklama' => A2'deki filtre özeti (isteğe bağlı)
//   'bilgi'    => [[etiket, değer, tip?], ...] tablo üstü özet blok (isteğe bağlı)
//   'bloklar'  => [[ 'baslik' => ?, 'sutunlar' => [...], 'satirlar' => [...], 'toplam' => bool ], ...]
//                 Kısayol: 'sutunlar' / 'satirlar' / 'toplam' doğrudan sayfaya
//                 yazılırsa tek blok sayılır.
// Sütun: ['baslik' => 'Net KG', 'tip' => 'kg', 'topla' => true]
// Tipler: metin · tamsayi · kg · ondalik · tutar · sayi (biçimsiz sayı) · tarih · tarihsaat
//
// GÜVENLİK: metin hücreleri setCellValueExplicit(TYPE_STRING) ile yazılır —
// "=" ile başlayan kullanıcı verisi (firma adı, not…) formül olarak
// ÇALIŞMAZ (formül enjeksiyonu). Formül yalnız toplam satırında, bizim
// ürettiğimiz SUBTOTAL ile yazılır.
// =========================================================
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsxDate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Bellek emniyeti: PhpSpreadsheet her hücreyi bellekte nesne olarak tutar
// (CSV satırları akıtarak yazar). Bu sınırı aşan istek XLSX yerine
// "filtreyi daraltın / CSV indirin" sayfası görür — sunucu bellek hatasıyla
// yarım dosya indirmez. Ölçüm (PHP 8.4, 10 sütun, kenarlıklı):
//   50 bin hücre → 61 MB / 2 sn · 150 bin → 130 MB / 7 sn · 300 bin → 248 MB / 19 sn
// 150 bin; ~26 sütunlu detay raporda ≈5.700, 11 sütunlu listede ≈13.600 satır.
if (!defined('XLSX_MAX_HUCRE')) define('XLSX_MAX_HUCRE', 150000);

const XLSX_GENISLIK_ORNEK    = 500;
const XLSX_RENK_BASLIK_DOLGU = 'FFE8EEF4';   // rapor_malzeme.php XLSX'iyle aynı
const XLSX_RENK_KENAR        = 'FFB8C4CF';
const XLSX_RENK_TOPLAM       = 'FFF2F5F8';
const XLSX_RENK_BLOK         = 'FF1F4E78';
const XLSX_RENK_SOLUK        = 'FF666666';

function xlsx_motor_yukle(): void
{
    if (class_exists(Spreadsheet::class)) return;
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) { http_response_code(500); die('Excel motoru (PhpSpreadsheet) bulunamadı.'); }
    require_once $autoload;
}

/** Sayfa tanımını blok listesine normalize eder. */
function xlsx_sayfa_bloklari(array $sayfa): array
{
    if (isset($sayfa['bloklar'])) return $sayfa['bloklar'];
    return [[
        'baslik'   => null,
        'sutunlar' => $sayfa['sutunlar'] ?? [],
        'satirlar' => $sayfa['satirlar'] ?? [],
        'toplam'   => $sayfa['toplam'] ?? false,
    ]];
}

/** Toplam hücre sayısı — bellek emniyeti kararı için. */
function xlsx_hucre_sayisi(array $sayfalar): int
{
    $n = 0;
    foreach ($sayfalar as $s) {
        $n += count($s['bilgi'] ?? []) * 2;
        foreach (xlsx_sayfa_bloklari($s) as $b) {
            $n += (count($b['satirlar']) + 2) * max(1, count($b['sutunlar']));
        }
    }
    return $n;
}

/** Excel sekme adı: yasak karakterler atılır, 31 karakter, tekil. */
function xlsx_sekme_adi(string $ad, array &$kullanilan): string
{
    $ad = trim(preg_replace('/[\[\]:\*\?\/\\\\]+/u', ' ', $ad) ?? '');
    $ad = trim($ad, "' ");
    if ($ad === '') $ad = 'Sayfa';
    $ad = mb_substr($ad, 0, 31, 'UTF-8');
    $aday = $ad; $i = 2;
    while (in_array(mb_strtolower($aday, 'UTF-8'), $kullanilan, true)) {
        $ek   = ' (' . $i++ . ')';
        $aday = mb_substr($ad, 0, 31 - mb_strlen($ek, 'UTF-8'), 'UTF-8') . $ek;
    }
    $kullanilan[] = mb_strtolower($aday, 'UTF-8');
    return $aday;
}

function xlsx_bicim_kodu(string $tip): ?string
{
    return match ($tip) {
        'tamsayi'   => '#,##0',
        'kg'        => '#,##0',          // KG ekranda tam sayı; değer ondalığı KORUR (formül çubuğunda görünür)
        'ondalik'   => '#,##0.000',
        'tutar'     => '#,##0.00',
        'tarih'     => 'dd.mm.yyyy',
        'tarihsaat' => 'dd.mm.yyyy hh:mm',
        default     => null,
    };
}

/**
 * Tarih metnini Excel seri numarasına çevirir; çözülemezse null.
 * Hızlı yol: DB'nin "Y-m-d[ H:i[:s]]" ve ekranın "d.m.Y[ H:i]" biçimi
 * regex + gmmktime ile çözülür (PHPToExcel her hücrede saat dilimi nesnesi
 * kurduğu için on binlerce satırda belirgin yavaştı — ölçüldü).
 */
function xlsx_tarih_degeri($v): ?float
{
    if ($v instanceof DateTimeInterface) return (float)XlsxDate::PHPToExcel($v);
    $s = trim((string)$v);
    if ($s === '') return null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $s, $m)) {
        [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } elseif (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})(?: (\d{2}):(\d{2})(?::(\d{2}))?)?$/', $s, $m)) {
        [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } else {
        return null;
    }
    if ($y < 1900 || !checkdate($mo, $d, $y)) return null;   // 0000-00-00 dahil
    $saat = (int)($m[4] ?? 0); $dk = (int)($m[5] ?? 0); $sn = (int)($m[6] ?? 0);
    if ($saat > 23 || $dk > 59 || $sn > 59) return null;
    // Excel seri: 1970-01-01 = 25569
    return gmmktime(0, 0, 0, $mo, $d, $y) / 86400 + 25569 + ($saat * 3600 + $dk * 60 + $sn) / 86400;
}

/**
 * Değeri tipine göre Excel değerine çevirir: [değer, DataType] ya da boşsa null.
 * Sayı/tarih olarak çözülemeyen değer METİN olarak kalır — veri kaybolmaz.
 */
function xlsx_hucre_degeri($v, string $tip): ?array
{
    if ($v === null || $v === '') return null;
    switch ($tip) {
        case 'tamsayi':
        case 'kg':
        case 'ondalik':
        case 'tutar':
        case 'sayi':
            if (is_numeric($v)) return [$tip === 'tamsayi' ? (int)round((float)$v) : (float)$v, DataType::TYPE_NUMERIC];
            break;
        case 'tarih':
        case 'tarihsaat':
            $seri = xlsx_tarih_degeri($v);
            if ($seri !== null) return [$seri, DataType::TYPE_NUMERIC];
            break;
    }
    return [(string)$v, DataType::TYPE_STRING];
}

/** Tek hücreyi tipine göre yazar (küçük alanlar için: özet bilgi bloğu). */
function xlsx_hucre_yaz(Worksheet $sh, string $adr, $v, string $tip): void
{
    $d = xlsx_hucre_degeri($v, $tip);
    if ($d !== null) $sh->setCellValueExplicit($adr, $d[0], $d[1]);
}

/**
 * Veri hücresi stili (ince kenarlık + tipin sayı biçimi) kitaba BİR KEZ
 * kaydedilir, hücrelere yalnız indeksi verilir. getStyle('A5:J30004') aralık
 * çağrısı her hücreyi tek tek dolaşıyordu: 300 bin hücrede ~14 sn (ölçüldü).
 */
function xlsx_veri_stili(Spreadsheet $ss, string $tip, array &$onbellek): int
{
    if (isset($onbellek[$tip])) return $onbellek[$tip];
    $st = new \PhpOffice\PhpSpreadsheet\Style\Style();
    $dizi = [
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => XLSX_RENK_KENAR]]],
        'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
    ];
    if ($kod = xlsx_bicim_kodu($tip)) $dizi['numberFormat'] = ['formatCode' => $kod];
    $st->applyFromArray($dizi);
    $ss->addCellXf($st);
    return $onbellek[$tip] = $st->getIndex();
}

/** Görünen metin uzunluğu — sütun genişliği tahmini için. */
function xlsx_gorunen_uzunluk($v, string $tip): int
{
    if ($v === null || $v === '') return 0;
    return match ($tip) {
        'tarih'     => 10,
        'tarihsaat' => 16,
        'tamsayi', 'kg' => is_numeric($v) ? strlen(number_format((float)$v, 0, ',', '.')) : mb_strlen((string)$v, 'UTF-8'),
        'ondalik'   => is_numeric($v) ? strlen(number_format((float)$v, 3, ',', '.')) : mb_strlen((string)$v, 'UTF-8'),
        'tutar'     => is_numeric($v) ? strlen(number_format((float)$v, 2, ',', '.')) : mb_strlen((string)$v, 'UTF-8'),
        default     => max(array_map(fn($l) => mb_strlen($l, 'UTF-8'), explode("\n", (string)$v))),
    };
}

/**
 * Çalışma kitabını üretir (dosyaya/çıktıya YAZMAZ — test edilebilsin diye).
 * $ozellik: ['baslik' => belge başlığı]
 */
function xlsx_olustur(array $sayfalar, array $ozellik = []): Spreadsheet
{
    xlsx_motor_yukle();
    $ss = new Spreadsheet();
    $ss->getProperties()
        ->setCreator('Asya Fresh')
        ->setTitle((string)($ozellik['baslik'] ?? ($sayfalar[0]['baslik'] ?? 'Rapor')));
    $ss->removeSheetByIndex(0);
    $kullanilan   = [];
    $stilOnbellek = [];
    $olusturma    = date('d.m.Y H:i');

    if (!$sayfalar) $sayfalar = [['ad' => 'Rapor', 'baslik' => 'Rapor', 'sutunlar' => [], 'satirlar' => []]];

    foreach ($sayfalar as $sayfa) {
        $sh = $ss->createSheet();
        $sh->setTitle(xlsx_sekme_adi((string)($sayfa['ad'] ?? 'Sayfa'), $kullanilan));
        $bloklar = xlsx_sayfa_bloklari($sayfa);

        $maxSutun = 2;
        foreach ($bloklar as $b) $maxSutun = max($maxSutun, count($b['sutunlar']));
        $sonHarf  = Coordinate::stringFromColumnIndex($maxSutun);
        $genislik = array_fill(1, $maxSutun, 8);

        // ── Başlık + açıklama ──
        $sh->setCellValueExplicit('A1', (string)($sayfa['baslik'] ?? ''), DataType::TYPE_STRING);
        $sh->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $aciklama = trim((string)($sayfa['aciklama'] ?? ''));
        $sh->setCellValueExplicit('A2', ($aciklama !== '' ? $aciklama . ' · ' : '') . 'Oluşturulma: ' . $olusturma, DataType::TYPE_STRING);
        $sh->getStyle('A2')->getFont()->setItalic(true)->getColor()->setARGB(XLSX_RENK_SOLUK);
        $r = 4;

        // ── Özet bilgi bloğu (etiket / değer) ──
        if (!empty($sayfa['bilgi'])) {
            foreach ($sayfa['bilgi'] as $bi) {
                $sh->setCellValueExplicit('A' . $r, (string)$bi[0], DataType::TYPE_STRING);
                $sh->getStyle('A' . $r)->getFont()->setBold(true);
                $tip = $bi[2] ?? 'metin';
                xlsx_hucre_yaz($sh, 'B' . $r, $bi[1] ?? '', $tip);
                if ($kod = xlsx_bicim_kodu($tip)) $sh->getStyle('B' . $r)->getNumberFormat()->setFormatCode($kod);
                $sh->getStyle('B' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                $genislik[1] = max($genislik[1], mb_strlen((string)$bi[0], 'UTF-8'));
                $genislik[2] = max($genislik[2], xlsx_gorunen_uzunluk($bi[1] ?? '', $tip));
                $r++;
            }
            $r++;
        }

        $tekBlok      = count($bloklar) === 1;
        $baslikSatiri = null;

        foreach ($bloklar as $b) {
            $sutunlar = array_values($b['sutunlar']);
            $n = count($sutunlar);
            if ($n === 0) continue;
            $son = Coordinate::stringFromColumnIndex($n);

            if (!empty($b['baslik'])) {
                $sh->setCellValueExplicit('A' . $r, (string)$b['baslik'], DataType::TYPE_STRING);
                $sh->getStyle('A' . $r)->getFont()->setBold(true)->setSize(12)->getColor()->setARGB(XLSX_RENK_BLOK);
                $r++;
            }

            // Başlık satırı
            foreach ($sutunlar as $i => $s) {
                $sh->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1) . $r, (string)$s['baslik'], DataType::TYPE_STRING);
                $genislik[$i + 1] = max($genislik[$i + 1], min(24, mb_strlen((string)$s['baslik'], 'UTF-8')));
            }
            $st = $sh->getStyle("A{$r}:{$son}{$r}");
            $st->getFont()->setBold(true);
            $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(XLSX_RENK_BASLIK_DOLGU);
            $st->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(XLSX_RENK_KENAR);
            $sh->getRowDimension($r)->setRowHeight(30);
            $baslikSatiri = $r;
            $r++;

            // Veri satırları
            // Genişlik tahmini ilk XLSX_GENISLIK_ORNEK satırdan yapılır — tüm
            // satırları ölçmek büyük raporda yazmanın kendisi kadar sürüyordu.
            $ilk    = $r;
            $harfler = [];
            $tipler  = [];
            foreach ($sutunlar as $i => $s) {
                $harfler[$i] = Coordinate::stringFromColumnIndex($i + 1);
                $tipler[$i]  = $s['tip'] ?? 'metin';
            }
            $sira = 0;
            $xf = [];
            foreach ($tipler as $i => $tip) $xf[$i] = xlsx_veri_stili($ss, $tip, $stilOnbellek);
            foreach ($b['satirlar'] as $satir) {
                $satir  = array_values($satir);
                $olcume = $sira++ < XLSX_GENISLIK_ORNEK;
                foreach ($harfler as $i => $harf) {
                    $v    = $satir[$i] ?? null;
                    $d    = xlsx_hucre_degeri($v, $tipler[$i]);
                    $cell = $sh->getCell($harf . $r);
                    if ($d !== null) $cell->setValueExplicit($d[0], $d[1]);
                    $cell->setXfIndex($xf[$i]);
                    if ($olcume) $genislik[$i + 1] = max($genislik[$i + 1], xlsx_gorunen_uzunluk($v, $tipler[$i]));
                }
                $r++;
            }
            $sonVeri = $r - 1;

            // Toplam satırı — SUBTOTAL(9,…): Excel'de filtre uygulanınca yalnız
            // görünen satırları toplar (düz SUM gizli satırları da sayardı).
            if (!empty($b['toplam'])) {
                $sh->setCellValueExplicit('A' . $r, 'TOPLAM', DataType::TYPE_STRING);
                foreach ($sutunlar as $i => $s) {
                    if (empty($s['topla'])) continue;
                    $harf = Coordinate::stringFromColumnIndex($i + 1);
                    $sh->setCellValue($harf . $r, $sonVeri >= $ilk ? "=SUBTOTAL(9,{$harf}{$ilk}:{$harf}{$sonVeri})" : 0);
                    if ($kod = xlsx_bicim_kodu($s['tip'] ?? 'metin')) $sh->getStyle($harf . $r)->getNumberFormat()->setFormatCode($kod);
                }
                $ts = $sh->getStyle("A{$r}:{$son}{$r}");
                $ts->getFont()->setBold(true);
                $ts->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(XLSX_RENK_TOPLAM);
                $ts->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB(XLSX_RENK_KENAR);
                $r++;
            }

            if ($tekBlok && $baslikSatiri !== null) {
                $sh->freezePane('A' . ($baslikSatiri + 1));
                $sh->setAutoFilter("A{$baslikSatiri}:{$son}" . max($baslikSatiri, $sonVeri));
                $sh->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($baslikSatiri, $baslikSatiri);
            }
            $r++;   // bloklar arası boş satır
        }

        // Sütun genişlikleri — içerikten, makul aralıkta (çok uzun not sayfayı taşırmasın)
        foreach ($genislik as $i => $w) {
            $sh->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(max(8, min(48, $w + 2)));
        }

        // Yazdırma: yatay, genişliğe sığdır
        $ps = $sh->getPageSetup();
        $ps->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4);
        $ps->setFitToWidth(1)->setFitToHeight(0);
        $sh->getPageMargins()->setLeft(0.4)->setRight(0.4)->setTop(0.5)->setBottom(0.5);
        $sh->setSelectedCell('A1');
    }

    $ss->setActiveSheetIndex(0);
    return $ss;
}

/** Çalışma kitabını tarayıcıya dosya olarak gönderir ve çıkar. */
function xlsx_gonder(Spreadsheet $ss, string $dosyaAdi): never
{
    // Önceki tampon içeriği dosyayı bozmasın (rapor_malzeme.php ile aynı)
    while (ob_get_level() > 0) { ob_end_clean(); }
    $dosyaAdi = preg_replace('/[^A-Za-z0-9._-]+/', '_', $dosyaAdi) ?: 'rapor.xlsx';
    if (!str_ends_with(strtolower($dosyaAdi), '.xlsx')) $dosyaAdi .= '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $dosyaAdi . '"');
    header('Cache-Control: max-age=0, no-cache');
    try {
        IOFactory::createWriter($ss, 'Xlsx')->save('php://output');
    } catch (Throwable $e) {
        error_log('[xlsx] yazma hatasi: ' . $e->getMessage());
    }
    $ss->disconnectWorksheets();
    exit;
}

/**
 * Tek çağrıda: bellek emniyeti → üret → gönder.
 * $csvUrl verilirse sınır aşıldığında kullanıcıya CSV bağlantısı gösterilir.
 */
function xlsx_indir(string $dosyaAdi, array $sayfalar, string $csvUrl = '', array $ozellik = []): never
{
    $hucre = xlsx_hucre_sayisi($sayfalar);
    if ($hucre > XLSX_MAX_HUCRE) xlsx_cok_buyuk($hucre, $csvUrl);
    // Sınır içindeki en büyük rapor için pay bırak (yalnız düşükse yükselt)
    $lim = ini_get('memory_limit');
    if ($lim !== false && $lim !== '-1' && xlsx_bayt((string)$lim) < 256 * 1048576) @ini_set('memory_limit', '256M');
    @set_time_limit(120);
    xlsx_gonder(xlsx_olustur($sayfalar, $ozellik), $dosyaAdi);
}

function xlsx_bayt(string $v): int
{
    $v = trim($v);
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
}

/** Sınır aşıldı: yarım dosya indirmek yerine açıklama sayfası. */
function xlsx_cok_buyuk(int $hucre, string $csvUrl): never
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    $esc  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $geri = $_SERVER['HTTP_REFERER'] ?? '';
    $geri = (is_string($geri) && preg_match('#^https?://#i', $geri) && parse_url($geri, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '')) ? $geri : '';
    $mesaj = 'Bu rapor XLSX için çok büyük (' . number_format($hucre, 0, ',', '.') . ' hücre; sınır '
           . number_format(XLSX_MAX_HUCRE, 0, ',', '.') . '). Tarih aralığını ya da filtreyi daraltıp tekrar deneyin'
           . ($csvUrl !== '' ? ' veya aynı veriyi CSV olarak indirin.' : '.');
    if (function_exists('render_header') && function_exists('render_footer')) {
        render_header('Excel İndir');
        echo '<div class="card" style="max-width:640px;margin:24px auto">'
           . '<h2 style="margin-top:0">📊 XLSX oluşturulamadı</h2>'
           . '<p>' . $esc($mesaj) . '</p>'
           . '<div style="display:flex;gap:8px;flex-wrap:wrap">'
           . ($csvUrl !== '' ? '<a class="btn btn-primary" href="' . $esc($csvUrl) . '">⬇ CSV İndir</a>' : '')
           . ($geri !== '' ? '<a class="btn btn-ghost" href="' . $esc($geri) . '">← Geri</a>' : '')
           . '</div></div>';
        render_footer();
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $mesaj;
    }
    exit;
}

// ── Arayüz: "⬇ Excel İndir ▾" menüsü ─────────────────────────
// Tıklanınca iki seçenek açılır: CSV İndir · XLSX İndir. CSV'nin gerekli
// olduğu (başka yazılıma aktarılabilecek) her dışa aktarımda bu menü kullanılır.
//
// Yetki: reports.export — TÜM dışa aktarımların ortak kapısı. Yetkisi
// olmayana menü hiç basılmaz; uç noktalar da aynı yetkiyi ayrıca ister
// (buton gizlemek tek başına koruma değildir).
//
// Açılır liste mevcut kebab altyapısını kullanır (.pc-dropdown + app.js):
// position:fixed ile açılır, overflow:auto kapsayıcıda (mobil .rpt-actions)
// KESİLMEZ; dışarı tıklayınca / kaydırınca kapanır. JS kapalıysa <noscript>
// içindeki iki düz bağlantı görünür.
function export_menu(string $csv_url, string $xlsx_url, string $etiket = 'Excel İndir', string $btn_class = 'btn btn-sm btn-ghost'): string
{
    if (!can('reports.export')) return '';
    $csv  = h($csv_url);
    $xlsx = h($xlsx_url);
    $cls  = h($btn_class);
    return '<div class="pc-kebab-wrap dl-menu no-print rpt-no-print">'
        . '<button type="button" class="' . $cls . ' dl-menu-btn" aria-haspopup="menu" title="Dosya biçimini seçin">⬇ ' . h($etiket) . ' <span class="dl-caret" aria-hidden="true">▾</span></button>'
        . '<div class="pc-dropdown dl-menu-list" role="menu" hidden>'
        . '<a href="' . $csv . '" role="menuitem">📄 CSV İndir <small>düz metin · aktarım için</small></a>'
        . '<a href="' . $xlsx . '" role="menuitem">📊 XLSX İndir <small>biçimli Excel</small></a>'
        . '</div>'
        . '<noscript><a href="' . $csv . '" class="' . $cls . '">CSV</a> <a href="' . $xlsx . '" class="' . $cls . '">XLSX</a></noscript>'
        . '</div>';
}

/** Dışa aktarım audit kaydı — tüm uç noktalarda aynı alanlar. */
function export_audit(string $modul, string $rapor, string $bicim, int $satir, array $filtre = []): void
{
    if (!function_exists('audit_log_event')) return;
    audit_log_event('export', $modul, null, null, [
        'report'    => $rapor,
        'format'    => $bicim,
        'row_count' => $satir,
        'filters'   => array_filter($filtre, fn($v) => $v !== '' && $v !== null),
    ]);
}
