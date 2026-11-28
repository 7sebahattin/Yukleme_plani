<?php
// =========================================================
// scripts/yukleme_planlari_liste_smoke.php
// Yükleme Planları listesi (records.php + assets/records-liste.js +
// assets/style.css + config/kullanici_tercih.php + config/kullanici_ayar.php)
// uçtan uca statik ve bellek-içi SQLite duman testi.
// Canlı DB'ye DOKUNMAZ.
// =========================================================
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$KOK = dirname(__DIR__);
$gecen = 0;
$kalan = 0;

function ok(string $ad, bool $kosul, string $detay = ''): void {
    global $gecen, $kalan;
    if ($kosul) {
        $gecen++;
        echo "PASS  {$ad}\n";
    } else {
        $kalan++;
        echo "FAIL  {$ad}" . ($detay !== '' ? " — {$detay}" : '') . "\n";
    }
}

$recPhp   = file_get_contents($KOK . '/records.php') ?: '';
$recJs    = file_get_contents($KOK . '/assets/records-liste.js') ?: '';
$styleCss = file_get_contents($KOK . '/assets/style.css') ?: '';

// ── 1) Filtre hapları ve işlendi/yüklendi/işlemler kaldırıldı ──
ok('records.php: filter-pills kaldırıldı', !str_contains($recPhp, 'filter-pills'));
ok('records.php: hızlı tarih hapları (Bugün/Bu hafta/Bu ay) kaldırıldı',
    !str_contains($recPhp, 'Bu hafta') && !str_contains($recPhp, 'Bu ay') && !str_contains($recPhp, 'tarih_bas='));
ok('records.php: WhatsApp Taslak / İşlendi / Yüklendi durum hapları kaldırıldı',
    !str_contains($recPhp, 'WhatsApp Taslak') && !str_contains($recPhp, 'durum=islendi') && !str_contains($recPhp, 'durum=yuklendi'));
ok('records.php: yüklendi/işlendi işlevleri (#yukleModal, record_durum.php, data-durum-action) kaldırıldı',
    !str_contains($recPhp, 'yukleModal') && !str_contains($recPhp, 'record_durum.php') && !str_contains($recPhp, 'data-durum-action'));
ok('records.php: İşlemler sütunu ve satır butonları (actions-col, pc-kebab) kaldırıldı',
    !str_contains($recPhp, 'actions-col') && !str_contains($recPhp, 'pc-kebab'));

// ── 2) Yeşil +F2 Yeni butonu + F2 kısayolu ──
ok('records.php: +F2 Yeni yeşil buton mevcut',
    str_contains($recPhp, 'id="recAddBtn"')
    && str_contains($recPhp, 'rec-btn-yeni')
    && str_contains($recPhp, 'pf-tb-ekle')
    && str_contains($recPhp, '>+F2 Yeni</a>'));
ok('assets/records-liste.js: F2 kısayolu record_create.php yönlendirmesi mevcut',
    str_contains($recJs, "e.key !== 'F2'") && str_contains($recJs, "window.location.href = 'record_create.php'"));
ok('assets/style.css: .rec-btn-yeni yeşil renk (#16a34a) tanımlı',
    str_contains($styleCss, '.rec-btn-yeni') && str_contains($styleCss, '#16a34a'));

// ── 3) Açılır alt detay üçgeni + satır tıklayınca görüntüleme ──
ok('records.php: detay açma üçgeni (.rec-exp-btn, ▸) ve .rec-detail-row mevcut',
    str_contains($recPhp, 'class="rec-exp-td"')
    && str_contains($recPhp, 'class="rec-exp-btn"')
    && str_contains($recPhp, '>▸</button>')
    && str_contains($recPhp, 'class="rec-detail-row"'));
ok('records.php: satırlar data-record-id ve data-href="record_view.php?id=..." taşıyor',
    str_contains($recPhp, 'data-record-id=') && str_contains($recPhp, 'data-href="record_view.php?id='));
ok('assets/records-liste.js: üçgen tıklaması detayı aç/kapa, satır tıklaması record_view ekranına gider',
    str_contains($recJs, "e.target.closest('.rec-exp-btn, .rec-exp-td')")
    && str_contains($recJs, 'detayAcKapa(')
    && str_contains($recJs, 'planaGit(row)'));

// ── 4) İlk satırda süz kutuları ──
ok('records.php: #recFiltreSatiri içinde placeholder="süz" arama kutuları mevcut',
    str_contains($recPhp, 'id="recFiltreSatiri"')
    && str_contains($recPhp, 'data-f="')
    && str_contains($recPhp, 'placeholder="süz"'));
ok('assets/records-liste.js: Türkçe büyük/küçük harf duyarsız süzme ve detay senkronu mevcut',
    str_contains($recJs, "toLocaleLowerCase('tr-TR')")
    && str_contains($recJs, 'sonucuGuncelle')
    && str_contains($recJs, 'detayAcKapa(r, false)'));

// ── 5) Palet Fişleri tarzı sütun genişliği + sürükle-bırak taşıma + sütun gizleme ──
ok('records.php: palet-fisleri.css ve #recSutunDialog mevcut',
    str_contains($recPhp, 'assets/palet-fisleri.css')
    && str_contains($recPhp, 'id="recSutunDialog"')
    && str_contains($recPhp, 'assets/records-liste.js'));
ok('assets/records-liste.js: .pf-rs sütun genişlik sürükleme + çift tık otomatik sığdırma',
    str_contains($recJs, "tutac.className = 'pf-rs'")
    && str_contains($recJs, 'hepsiniDaralt')
    && str_contains($recJs, 'icerikGenisligi'));
ok('assets/records-liste.js: sürükle-bırak sütun taşıma (.pf-hayalet, .pf-birak, moveColumn, Escape iptal)',
    str_contains($recJs, 'pf-hayalet')
    && str_contains($recJs, 'pf-birak')
    && str_contains($recJs, 'function moveColumn(')
    && str_contains($recJs, "e.key === 'Escape'"));
ok('assets/records-liste.js: sütun gizleme penceresi + detay colspan güncelleme',
    str_contains($recJs, 'sutunPenceresiAc')
    && str_contains($recJs, 'td.colSpan = colspan'));

// ── 6) Performans & güvenlik değişmezleri ──
ok('records.php: açık kolon listesi ($_rec_sel), SELECT r.* ve r.etiket_foto YOK',
    !str_contains($recPhp, 'SELECT r.*,')
    && str_contains($recPhp, '$_rec_sel')
    && !str_contains($recPhp, "'r.etiket_foto'"));
ok('records.php: tercih_kaydet POST CSRF + records.tablo kapılı',
    str_contains($recPhp, "csrf_check(\$_POST['csrf'] ?? null)")
    && str_contains($recPhp, "\$anahtar === 'records.tablo'"));
ok('assets/style.css: yasaklı .st- öneki yok ve html overflow-x: clip korunuyor',
    !preg_match('/\.st-[a-z0-9_-]+/i', $styleCss)
    && str_contains($styleCss, 'overflow-x: clip'));

// ── 7) Bellek-içi SQLite: kullanici_tercihleri ('records.tablo') tam döngü ──
require_once $KOK . '/config/kullanici_tercih.php';
require_once $KOK . '/config/kullanici_ayar.php';

$tabloListesi = ka_tablo_tercihleri();
ok('ka_tablo_tercihleri() records.tablo anahtarını içeriyor',
    isset($tabloListesi['records.tablo'])
    && $tabloListesi['records.tablo'][0] === 'Yükleme Planları'
    && $tabloListesi['records.tablo'][1] === 'asya.records.tablo.v1');

$dogrulanan = kt_dogrula('records.tablo', [
    'genislik' => ['firma' => 220, 'net' => 110, 'gecersiz_kolon' => 999, 'tarih' => 10],
    'sira'     => ['firma', 'tarih', 'net', 'sahte_kolon', 'firma'],
    'gizli'    => ['bolge', 'casus_no', 'sahte_kolon'],
]);
ok('kt_dogrula(records.tablo) geçerli genişlik/sıra/gizli döndürür, sahte kolonları eler',
    is_array($dogrulanan)
    && ($dogrulanan['genislik']['firma'] ?? 0) === 220
    && ($dogrulanan['genislik']['tarih'] ?? 0) === 30 // min 30 clamp
    && !isset($dogrulanan['genislik']['gecersiz_kolon'])
    && $dogrulanan['sira'] === ['firma', 'tarih', 'net']
    && $dogrulanan['gizli'] === ['bolge', 'casus_no']);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE kullanici_tercihleri (
    user_id INTEGER NOT NULL,
    anahtar TEXT NOT NULL,
    deger TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (user_id, anahtar)
)");
ok('SQLite kt_hazir() true döner', kt_hazir($pdo) === true);
ok('SQLite kt_yaz(records.tablo) başarılı', kt_yaz($pdo, 42, 'records.tablo', $dogrulanan) === true);
$okunan = kt_oku($pdo, 42, 'records.tablo');
ok('SQLite kt_oku(records.tablo) yazılan sırayı ve genişliği geri verir',
    is_array($okunan)
    && ($okunan['genislik']['firma'] ?? 0) === 220
    && $okunan['sira'] === ['firma', 'tarih', 'net']
    && $okunan['gizli'] === ['bolge', 'casus_no']);
ok('SQLite kt_sil(records.tablo) tercihi siler',
    kt_sil($pdo, 42, 'records.tablo') === true && kt_oku($pdo, 42, 'records.tablo') === null);

echo "\nToplam: " . ($gecen + $kalan) . " kontrol, {$gecen} geçti, {$kalan} başarısız.\n";
exit($kalan === 0 ? 0 : 1);
