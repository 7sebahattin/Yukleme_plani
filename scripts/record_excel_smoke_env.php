<?php
// =========================================================
// scripts/record_excel_smoke_env.php — smoke testin sahte ortamı
// record_excel_smoke.php tarafından ALT SÜREÇTE include edilir.
// Canlı DB'ye HİÇ dokunmaz: bellek içi SQLite + stub auth/helper.
// =========================================================
declare(strict_types=1);

$GLOBALS['PDO_TEST'] = null;

function db(): PDO { return $GLOBALS['PDO_TEST']; }
function require_login(): array { return ['id' => 1, 'username' => 'test']; }
function require_perm(string $p): void {}
function is_admin(): bool { return false; }
function can(string $p): bool { return true; }
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
// config/helpers.php'deki ile aynı davranış
function fmt_date(?string $d): string {
    if (!$d) return '';
    $ts = strtotime($d);
    if (!$ts) return h($d);
    return date('d.m.Y', $ts);
}
function tr_upper(string $s): string {
    return mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], $s), 'UTF-8');
}
function definition_types(): array {
    return ['kasa_cinsi' => 'Kasa Cinsi', 'palet_tipi' => 'Palet Tipi', 'sapka' => 'Şapka'];
}
// config/calc.php'deki ile aynı kural: kasa etiketi ve viyol kasa bazlı.
function material_calc_basis(string $type, string $name): string {
    return in_array($type, ['kasa_etiketi', 'viyol'], true) ? 'kasa' : 'palet';
}

/**
 * Şemayı kurar ve verilen size listesiyle tek bir yükleme kaydı üretir.
 * @param string[] $sizes Palet satırlarının SIZE değerleri (boş string olabilir)
 */
function smoke_env_kur(string $root, array $sizes): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $GLOBALS['PDO_TEST'] = $pdo;

    // Kolonlar record_excel_template.php'nin GERÇEKTEN okuduğu alanlarla birebir
    // (grep ile çıkarıldı) — eksik kolon "Undefined array key" uyarısı üretir ve
    // test bunu hata sayar, böylece şema kayması fark edilir.
    $pdo->exec("CREATE TABLE loading_records (
        id INTEGER PRIMARY KEY, tarih TEXT, firma TEXT DEFAULT '', parti_no TEXT DEFAULT '',
        urun TEXT DEFAULT '', alici TEXT DEFAULT '', ulasim TEXT DEFAULT '',
        gidecek_ulke TEXT DEFAULT '', gumruk TEXT DEFAULT '', brand TEXT DEFAULT '',
        etiket TEXT DEFAULT '', urun_sahibi_id INTEGER, type TEXT DEFAULT 'yukleme',
        bolge TEXT DEFAULT '', casus_no TEXT DEFAULT '', nakliye_sirketi TEXT DEFAULT '',
        on_plaka TEXT DEFAULT '', arka_plaka TEXT DEFAULT '', sofor_adi TEXT DEFAULT '',
        telefon TEXT DEFAULT '')");
    $pdo->exec("CREATE TABLE loading_pallets (
        id INTEGER PRIMARY KEY, loading_record_id INT, sira_no INT, size TEXT DEFAULT '',
        urun_cinsi TEXT DEFAULT '', kasa_adeti INT DEFAULT 0, kasa_cinsi_id INT,
        palet_tipi_id INT, brut_kg REAL DEFAULT 0, depo TEXT DEFAULT '',
        palet_no TEXT DEFAULT '')");
    $pdo->exec("CREATE TABLE pallet_materials (
        id INTEGER PRIMARY KEY, loading_pallet_id INT, material_id INT, quantity REAL DEFAULT 0)");
    $pdo->exec("CREATE TABLE material_definitions (
        id INTEGER PRIMARY KEY, type TEXT, name TEXT, unit_dara_kg REAL DEFAULT 0)");
    $pdo->exec("INSERT INTO material_definitions (id,type,name,unit_dara_kg) VALUES
        (1,'kasa_cinsi','C-5 KASA',0.5),(2,'palet_tipi','İHRACAT PALET',20),(3,'sapka','ŞAPKA',0)");

    $pdo->exec("INSERT INTO loading_records
        (id,tarih,firma,parti_no,urun,gidecek_ulke,alici,ulasim,gumruk,bolge,
         on_plaka,arka_plaka,sofor_adi,telefon,nakliye_sirketi,casus_no)
        VALUES (1,'2026-08-20','ASYA FRESH','423','ÜZÜM','RUSYA FEDERASYONU','ALICI A.Ş.',
                'KARAYOLU','KAPIKULE','EGE','55TD215','55AB123','SÜRÜCÜ','05001112233',
                'NAKLİYE LTD','C-100')");

    $i = 0;
    foreach ($sizes as $sz) {
        $i++;
        $pdo->prepare("INSERT INTO loading_pallets
            (id,loading_record_id,sira_no,size,urun_cinsi,kasa_adeti,kasa_cinsi_id,
             palet_tipi_id,brut_kg,palet_no)
            VALUES (?,1,?,?,'ÜZÜM BEYAZ',100,1,2,1000,?)")
            ->execute([$i, $i, (string)$sz, 'P' . $i]);
        $pdo->prepare("INSERT INTO pallet_materials (loading_pallet_id,material_id,quantity) VALUES (?,3,2)")
            ->execute([$i]);
    }
}
