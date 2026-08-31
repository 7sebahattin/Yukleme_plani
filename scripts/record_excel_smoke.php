<?php
// =========================================================
// scripts/record_excel_smoke.php — "Excel İndir" GERÇEK kod yolu testi
//
// SADECE CLI. Canlı veritabanına HİÇ dokunmaz: bellek içi SQLite + stub auth
// (record_excel_smoke_env.php) ile record_excel_template.php'yi GERÇEKTEN
// çalıştırır, ürettiği .xlsx'i geri okuyup doğrular.
//
//   php scripts/record_excel_smoke.php    → çıkış kodu 0 = tüm testler geçti
//
// NEDEN VAR: 4'ten fazla farklı SIZE olduğunda çalışan "tabloyu aşağı uzat"
// kolu, elle yazılmış TAKLİT testlerde geçiyordu; ama gerçek dosyada
// getRowDimension()'a (string) cast'i vardı ve canlıda TypeError → 500
// veriyordu. Taklit kod test etmez — bu betik gerçek dosyayı çalıştırır.
//
// Her senaryo AYRI ALT SÜREÇTE koşar, çünkü record_excel_template.php
// php://output'a yazıp exit() çağırır (tek süreçte ilk senaryo testi bitirirdi).
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);

$GECEN = 0; $KALAN = 0;
function ok(string $ad, bool $kosul, string $detay = ''): void {
    global $GECEN, $KALAN;
    if ($kosul) { $GECEN++; echo "✓ $ad\n"; }
    else { $KALAN++; echo "✗ $ad" . ($detay !== '' ? " — $detay" : '') . "\n"; }
}

require_once $ROOT . '/vendor/autoload.php';

echo "record_excel_template.php — GERÇEK kod yolu testi\n";
echo str_repeat('=', 54) . "\n";

// Size senaryoları: şablonda 4 sabit yuva (38-41) var.
$senaryolar = [
    'tek size'             => ['14'],
    'tam 4 size'           => ['8', '9', '12', '14'],
    '6 size (tablo uzamalı)' => ['8', '9', '10', '12', '14', '16'],
    'hiç size yok'         => ['', '', ''],
    'aynı size tekrarlı'   => ['14', '14', '9', '14'],
];

foreach ($senaryolar as $ad => $sizes) {
    // record_excel_template.php ilk iki satırında config/db.php + config/auth.php
    // require eder; ikisi de canlı MySQL'e bağlanır ve db() fonksiyonunu bizim
    // stub'ımızla çakıştırır. Yalnız bu İKİ BOOTSTRAP satırını devre dışı bırakıp
    // dosyanın GERİ KALANINI (tüm iş mantığı) aynen çalıştırıyoruz.
    // Geçici kopya PROJE KÖKÜNDE oluşturulur — dosya __DIR__ ile şablon ve
    // vendor yollarını çözüyor, /tmp'de __DIR__ yanlış olurdu.
    $gercek = file_get_contents($ROOT . '/record_excel_template.php');
    $kopya  = str_replace(
        ["require_once __DIR__ . '/config/db.php';", "require_once __DIR__ . '/config/auth.php';"],
        ['/* smoke: bootstrap devre dışı */', '/* smoke: bootstrap devre dışı */'],
        $gercek
    );
    if ($kopya === $gercek) {
        ok("[$ad] bootstrap satırları bulundu", false,
            'record_excel_template.php başındaki require satırları değişmiş — test güncellenmeli');
        continue;
    }

    $tmpPhp = $ROOT . '/.smoke_record_excel_' . getmypid() . '.php';
    $tmpOut = tempnam(sys_get_temp_dir(), 'xlsmoke') . '.xlsx';
    $tmpErr = $tmpOut . '.err';
    file_put_contents($tmpPhp, $kopya);

    $kosucu = '<?php' . "\n"
        . '$root  = getenv("SMOKE_ROOT");' . "\n"
        . '$sizes = json_decode(getenv("SMOKE_SIZES"), true);' . "\n"
        . 'require $root . "/scripts/record_excel_smoke_env.php";' . "\n"
        . 'smoke_env_kur($root, $sizes);' . "\n"
        . '$_GET = ["id" => "1"];' . "\n"
        . 'include ' . var_export($tmpPhp, true) . ';' . "\n";
    $tmpRun = tempnam(sys_get_temp_dir(), 'xlsmokerun') . '.php';
    file_put_contents($tmpRun, $kosucu);

    $cmd = 'SMOKE_ROOT=' . escapeshellarg($ROOT)
         . ' SMOKE_SIZES=' . escapeshellarg((string)json_encode($sizes))
         . ' php ' . escapeshellarg($tmpRun)
         . ' > ' . escapeshellarg($tmpOut)
         . ' 2> ' . escapeshellarg($tmpErr);
    exec($cmd, $_cikti, $rc);

    // header() CLI'da "Cannot modify header information" uyarısı VERMEZ (CLI SAPI'de
    // header çağrıları sessizce yok sayılır), o yüzden stderr gerçekten boş olmalı.
    $err = trim((string)@file_get_contents($tmpErr));

    if ($rc !== 0 || $err !== '') {
        ok("[$ad] hatasız çalıştı", false, $err !== '' ? substr($err, 0, 300) : "çıkış kodu $rc");
        @unlink($tmpPhp); @unlink($tmpRun); @unlink($tmpOut); @unlink($tmpErr);
        continue;
    }
    ok("[$ad] hatasız çalıştı", true);

    // Beklenen size listesi: boşlar atılır, tekrarlar teklenir, natsort.
    $beklenen = [];
    foreach ($sizes as $s) {
        $s = trim((string)$s);
        if ($s !== '' && !in_array($s, $beklenen, true)) $beklenen[] = $s;
    }
    natsort($beklenen);
    $beklenen = array_values($beklenen);

    try {
        $sh = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpOut)->getActiveSheet();

        // 4 sabit yuva + gerekirse uzayan satırlar; fazladan bir satır da okuyup
        // "kullanılmayan yuva temizlendi mi" kontrol ediyoruz.
        $tara = max(4, count($beklenen)) + 1;
        $yazilan = [];
        for ($k = 0; $k < $tara; $k++) {
            $v = trim((string)$sh->getCell('H' . (38 + $k))->getValue());
            if ($v !== '') $yazilan[] = $v;
        }

        ok("[$ad] size değerleri doğru", $yazilan === $beklenen,
            'beklenen=' . json_encode($beklenen, JSON_UNESCAPED_UNICODE)
            . ' gelen=' . json_encode($yazilan, JSON_UNESCAPED_UNICODE));

        if (count($beklenen) > 4) {
            $son = 38 + count($beklenen) - 1;
            ok("[$ad] tablo $son. satıra uzadı",
                trim((string)$sh->getCell("H$son")->getValue()) !== '');
            // Uzayan satırda formül de yazılmış olmalı (yalnız etiket değil)
            ok("[$ad] uzayan satırda formül var",
                str_starts_with((string)$sh->getCell("I$son")->getValue(), '='));
        }
    } catch (Throwable $e) {
        ok("[$ad] üretilen dosya okunabilir", false, $e->getMessage());
    }

    @unlink($tmpPhp); @unlink($tmpRun); @unlink($tmpOut); @unlink($tmpErr);
}

echo str_repeat('=', 54) . "\n";
echo $KALAN === 0 ? "TÜMÜ GEÇTİ ($GECEN)\n" : "BAŞARISIZ — $KALAN hata, $GECEN geçti\n";
exit($KALAN === 0 ? 0 : 1);
