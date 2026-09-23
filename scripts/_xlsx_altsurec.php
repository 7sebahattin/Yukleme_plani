<?php
// =========================================================
// scripts/_xlsx_altsurec.php — testler için ortak yardımcı (SADECE CLI)
//
// Bir *_ui_smoke.php testinin CSV alt-süreç kodunu (sahte ortam + gerçek
// sayfa) ALDIRIR, $_GET'teki CSV anahtarını XLSX anahtarıyla değiştirip
// yeniden çalıştırır ve çıkan dosyayı PhpSpreadsheet ile GERİ OKUR.
// Böylece "⬇ Excel İndir ▾ → XLSX İndir" kolu gerçek sayfa koduyla test
// edilir (tanımsız değişken / yanlış anahtar php -l ile yakalanmaz).
// =========================================================
declare(strict_types=1);

/**
 * @return array{ok:bool, hata:string, kitap:?\PhpOffice\PhpSpreadsheet\Spreadsheet}
 */
function xlsx_altsurec_calistir(string $altSurecKodu, string $csvGet, string $xlsxGet, string $etiket): array
{
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    if (!str_contains($altSurecKodu, $csvGet)) {
        return ['ok' => false, 'hata' => "alt-süreç kodunda '$csvGet' bulunamadı — test güncellenmeli", 'kitap' => null];
    }
    $kod  = str_replace($csvGet, $xlsxGet, $altSurecKodu);
    $tmp  = sys_get_temp_dir() . '/xlsx_altsurec_' . $etiket . '_' . getmypid();
    file_put_contents("$tmp.php", $kod);
    exec('php ' . escapeshellarg("$tmp.php") . ' > ' . escapeshellarg("$tmp.xlsx") . ' 2> ' . escapeshellarg("$tmp.err"), $_, $rc);
    $err  = (string)@file_get_contents("$tmp.err");
    $bas  = (string)@file_get_contents("$tmp.xlsx", false, null, 0, 4);
    $sonuc = ['ok' => false, 'hata' => '', 'kitap' => null];
    if ($rc !== 0 || str_contains($err, 'Fatal') || str_contains($err, 'Warning')) {
        $sonuc['hata'] = "rc=$rc " . trim($err) . ' ' . substr((string)@file_get_contents("$tmp.xlsx"), 0, 400);
    } elseif ($bas !== "PK\x03\x04") {
        $sonuc['hata'] = 'çıktı XLSX (zip) değil: ' . substr((string)@file_get_contents("$tmp.xlsx"), 0, 400);
    } else {
        try {
            $sonuc['kitap'] = \PhpOffice\PhpSpreadsheet\IOFactory::load("$tmp.xlsx");
            $sonuc['ok'] = true;
        } catch (Throwable $e) {
            $sonuc['hata'] = 'XLSX okunamadı: ' . $e->getMessage();
        }
    }
    foreach (['php', 'xlsx', 'err'] as $u) @unlink("$tmp.$u");
    return $sonuc;
}

/** Sayfada, verilen metni içeren ilk satırın numarası (yoksa 0). */
function xlsx_satir_bul(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sh, string $metin): int
{
    foreach ($sh->getRowIterator() as $row) {
        foreach ($row->getCellIterator() as $c) {
            if ((string)$c->getValue() === $metin) return $row->getRowIndex();
        }
    }
    return 0;
}

/** Başlık satırında verilen başlığın sütun harfi (yoksa ''). */
function xlsx_sutun_bul(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sh, int $baslikSatiri, string $baslik): string
{
    foreach ($sh->getRowIterator($baslikSatiri, $baslikSatiri) as $row) {
        foreach ($row->getCellIterator() as $c) {
            if ((string)$c->getValue() === $baslik) return $c->getColumn();
        }
    }
    return '';
}
