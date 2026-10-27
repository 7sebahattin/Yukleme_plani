<?php
// =========================================================
// scripts/pdks_fis_static_smoke.php — "Fiş Excel'i" STATİK + partial render testleri (v446).
//
// Kaynak taraması (kapılar, CSRF, PRG, ikinci yazma yolu yok, nav, sw regex, CSS) + _fis_onizle.php'nin
// el yapımı fixture ile render'ı (rozetler, onay kutusu varsayılanları, XSS kaçışı, devre dışı kutular).
//   php scripts/pdks_fis_static_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
error_reporting(E_ALL);
ini_set('display_errors', '1');
$ROOT = dirname(__DIR__);

function db(): PDO { throw new RuntimeException('Bu test DB kullanmaz.'); }
function active_depot(): ?string { return 'Depo A'; }
function is_admin(): bool { return true; }
function can(string $p): bool { return true; }
function audit_log_event(string $a, string $m, ?int $id = null, ?array $o = null, ?array $n = null, ?int $u = null): void {}
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

require_once $ROOT . '/config/pdks_fis.php';

$gecen = 0; $fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $gecen, $fail;
    $c ? $gecen++ : $fail++;
    printf("%-92s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}
function oku(string $yol): string { global $ROOT; $s = @file_get_contents($ROOT . '/' . $yol); return $s === false ? '' : $s; }
function say_alt(string $a, string $b): int { return substr_count($a, $b); }

$sayfa = oku('gunluk_fis_yukle.php'); $partial = oku('_fis_onizle.php'); $lib = oku('config/pdks_fis.php');

echo "\n=== 1. gunluk_fis_yukle.php — kapılar ve akış ===\n";
ok('1) dosya var ve php -l temiz', $sayfa !== '' && str_contains(shell_exec('php -l ' . escapeshellarg($ROOT . '/gunluk_fis_yukle.php') . ' 2>&1'), 'No syntax errors'));
$sira = fn(string $iğne) => strpos($sayfa, $iğne);
ok('1) require_login() → require_pdks_gunluk(\'daily_reports\') → is_admin() kapısı sırayla', $sira('require_login()') !== false && $sira("require_pdks_gunluk('daily_reports')") > $sira('require_login()') && $sira('!is_admin()') > $sira("require_pdks_gunluk('daily_reports')") && str_contains($sayfa, 'forbidden('));
ok('1) pdks_gunluk_sayfa_kapisi + pdks_faz8b_sayfa_kapisi', str_contains($sayfa, 'pdks_gunluk_sayfa_kapisi($pdo)') && str_contains($sayfa, 'pdks_faz8b_sayfa_kapisi($pdo)'));
ok('1) her POST csrf_check ile korunur (POST bloğunda tek kapı, eylem ayrımından ÖNCE)', $sira("csrf_check(\$_POST['csrf']") !== false && $sira("csrf_check(\$_POST['csrf']") < $sira("\$eylem === 'onizle'"));
ok('1) post_max_size ön kontrolü csrf_check\'ten ÖNCE', $sira('post_max_size') !== false && $sira('post_max_size') < $sira("csrf_check(\$_POST['csrf']"));
ok('1) yükleme formu enctype multipart + accept .xlsx', str_contains($sayfa, 'enctype="multipart/form-data"') && str_contains($sayfa, 'accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"'));
ok('1) PRG: her POST dalı Location + exit ile biter', say_alt($sayfa, "header('Location:") >= 8 && preg_match('/\$eylem === \'yaz\'.*?header\(\'Location: \' \. \$self \. \'\?sonuc=\'/s', $sayfa) === 1);
ok('1) şablon indirme GET + export_audit + xlsx_gonder, çıktıdan (render_header) ÖNCE', $sira("(\$_GET['sablon'] ?? '') === '1'") !== false && $sira('export_audit(') < $sira('render_header(') && $sira('xlsx_gonder(') < $sira('render_header('));
ok('1) önizleme POST\'ta dosya doğrulama → okuma → normalize → token (dosya saklanmaz: move_uploaded_file / copy YOK)', $sira('pdks_fis_dosya_dogrula(') < $sira('pdks_fis_oku(') && $sira('pdks_fis_oku(') < $sira('pdks_fis_normalize(') && $sira('pdks_fis_normalize(') < $sira('pdks_fis_token_olustur(') && !preg_match('/move_uploaded_file|copy\(|file_put_contents\(/', $sayfa));
ok('1) yazma yalnız token\'dan: fişler pdks_fis_token_al() ile, kova/saat/sayı POST\'tan OKUNMAZ', str_contains($sayfa, 'pdks_fis_token_al($token, $tokenNeden)') && !preg_match('/\$_POST\[\'(fm_(?!tip)|kova|saat|say|kadin|erkek|rampaci|servis)/', $sayfa));
ok('1) seçim: tablo ∩ kart kesişimi + token\'daki bilinen satırlarla kesiştirilir', str_contains($sayfa, 'array_intersect($a, $b, $bilinen)'));
ok('1) onay kutuları POST\'tan (onay_yarim/onay_fm/ek_yaz/isletme_onay), fm_tip token\'dan', str_contains($sayfa, "!empty(\$_POST['onay_yarim'])") && str_contains($sayfa, "\$yuk['secenek']['fm_tip']"));
ok('1) kullanıcı verisi h(); ham $_POST/$_GET echo YOK', say_alt($sayfa, 'h(') >= 8 && !preg_match('/echo\s+\$_(POST|GET|REQUEST)|<\?=\s*\$_(POST|GET|REQUEST)/', $sayfa . $partial));
ok('1) onayda confirm (onsubmit) — partial', str_contains($partial, 'onsubmit="return confirm('));
ok('1) <dialog> / modal YOK', !str_contains($sayfa . $partial, '<dialog') && !str_contains($sayfa . $partial, 'pm-dialog'));
ok('1) geri bağlantısı "← Günlük Puantaj" btn-geri (btn-geri-ptak YOK)', str_contains($sayfa, '<a href="gunluk_isci_puantaj.php" class="btn btn-geri">← Günlük Puantaj</a>') && !str_contains($sayfa, 'btn-geri-ptak'));
ok('1) token biçimi: 32 hex (sonuç + önizleme)', str_contains($sayfa, '/^[a-f0-9]{32}$/D') && str_contains($lib, "'/^[a-f0-9]{32}\$/D'"));
ok('1) migration / şema / ALTER YOK', !preg_match('/CREATE TABLE|ALTER TABLE|_migrate\(/i', $sayfa . $partial . $lib));
ok('1) sayfa PHP kodu fonksiyon TANIMLAMAZ (satır içi <script> hariç)', !preg_match('/^\s*function\s+\w+\(/m', preg_replace('#<script>.*?</script>#s', '', $sayfa)));

echo "\n=== 2. Gezinme / ikinci yazma yolu yok / CSS ===\n";
ok('2) nav_ptak_sayfalari() gunluk_fis_yukle.php içerir', (bool)preg_match("/function nav_ptak_sayfalari.*?'gunluk_fis_yukle\.php'/s", oku('config/helpers.php')));
$puantaj = oku('gunluk_isci_puantaj.php');
ok('2) gunluk_isci_puantaj.php bağlantısı $ekleYetkili bloğunda', (bool)preg_match('/<\?php if \(\$ekleYetkili\): \?>.*?href="gunluk_fis_yukle\.php".*?<\?php endif; \?>/s', $puantaj));
$swRe = preg_match('/var SW_MUHASEBE_RE = (\/.*\/);/', oku('sw.js'), $mm) ? $mm[1] : '';
ok('2) sw.js SW_MUHASEBE_RE bulundu', $swRe !== '');
$phpRe = $swRe !== '' ? '~' . str_replace('~', '\~', substr($swRe, 1, -1)) . '~' : '~yok~';
ok('2) SW_MUHASEBE_RE "gunluk_fis_yukle.php" ile EŞLEŞMİYOR (gunluk_kurlar çakışmaz)', preg_match($phpRe, '/gunluk_fis_yukle.php') === 0 && preg_match($phpRe, '/gunluk_kurlar.php') === 1);
ok('2) pdks.css "v446" Fiş Excel\'i bloğu var', str_contains(oku('assets/pdks.css'), 'v446 — Fiş Excel\'i Yükle'));
ok('2) tek CSS/JS kuralı: fy- stilleri / fisYukle YALNIZ pdks.css + sayfada (style.css, app.js, pdks.js temiz)', !str_contains(oku('assets/style.css'), '.fy-') && !str_contains(oku('assets/app.js'), 'fisYukle') && !str_contains(oku('assets/pdks.js'), 'fisYukle') && !str_contains(oku('assets/app.js'), 'gunluk_fis_yukle'));
ok('2) pdks.css fy- stilleri: <768px kuralı yalnız fy-* sınıfları', (function () use ($ROOT) {
    $css = oku('assets/pdks.css'); $i = strpos($css, 'v446 — Fiş Excel');
    if ($i === false) return false;
    $blok = substr($css, $i);
    preg_match_all('/@media \(max-width: 767px\) \{(.*?)\n\}/s', $blok, $m);
    foreach ($m[1] as $icerik) foreach (explode('}', $icerik) as $kural) { $sel = trim(explode('{', $kural)[0] ?? ''); if ($sel !== '' && !str_contains($sel, '.fy-')) return false; }
    return true;
})());
$ajax = oku('_puantaj_toplu_ajax.php');
ok('2) _puantaj_toplu_ajax.php coklu_grup / yazdiktan_sonra / audit_ek GEÇİRMEZ (HTTP\'den gelemez)', !preg_match('/coklu_grup|yazdiktan_sonra|audit_ek/', $ajax));
ok('2) pdks_fis.php dönem/olay/servis INSERT ve dönem UPDATE\'i YOK', !preg_match('/INSERT\s+INTO\s+(daily_worker_card_events|daily_worker_work_periods|daily_session_services|worker_cards)|UPDATE\s+daily_worker_work_periods/i', $lib));
ok('2) pdks_fis.php TEK yazma yollarını çağırır', str_contains($lib, 'pdks_faz8j_toplu_ekle(') && str_contains($lib, 'pdks_servis_ekle(') && str_contains($lib, 'pdks_faz8b_degerlendirme_kaydet(') && str_contains($lib, 'pdks_faz8b_donemleri_siniflandir('));
ok('2) sınıf/FM hesabı kopyası YOK (pdks_faz8b_sure_karari / saat_basi / fm_saat( çağrısı yok)', !preg_match('/pdks_faz8b_(sure_karari|saat_basi|fm_saat|esikler)\(/', $lib));
$tum = oku('scripts/tum_testler.sh');
ok('2) yeni testler *_smoke.php adlandırmasıyla tum_testler.sh tarafından OTOMATİK alınır', str_contains($tum, '*_smoke.php') || str_contains($tum, '_smoke.php'));
ok('2) Mesai Detayı denetim geçmişi fis_yukle etiketini tanır', str_contains(oku('config/pdks_gunluk.php'), "'fis_yukle'"));

echo "\n=== 3. _fis_onizle.php — include-only + render ===\n";
ok('3) fonksiyon TANIMLAMAZ', !preg_match('/^\s*function\s+\w+\(/m', $partial));
$out = shell_exec('php ' . escapeshellarg($ROOT . '/_fis_onizle.php') . ' 2>&1; echo "[exit=$?]"');
ok('3) $model yokken doğrudan çalıştırma çıktı basmaz (404 + exit)', trim((string)$out) === '[exit=0]' || trim((string)$out) === '', (string)$out);
ok('3) doğrudan istek guard\'ı ($model / SCRIPT_FILENAME) kaynakta', str_contains($partial, '!isset($model)') && str_contains($partial, 'realpath((string)$_SERVER[\'SCRIPT_FILENAME\']) === __FILE__') && str_contains($partial, 'http_response_code(404)'));

function fixture(string $durum, int $satir, array $o = []): array {
    return $o + [
        'satir' => $satir, 'fis_no' => 'T-' . $satir, 'rev' => 0, 'tarih' => '2026-10-20', 'giris' => '08:00', 'isletme' => '', 'not' => '',
        'cavus' => ['id' => 1, 'kod' => 'C001', 'ad' => 'Çavuş A', 'yontem' => 'kod'], 'durum' => $durum, 'hatalar' => [], 'engeller' => [], 'uyarilar' => [],
        'kontrol' => '', 'ham' => '', 'hash' => 'abcdef0123456789', 'kisi' => 3,
        'plan' => ['gruplar' => [['kod' => 'ERKEK', 'tip_ad' => 'Erkek', 'profil' => 'tam', 'fm_saat' => 0, 'adet' => 3, 'entry_clock' => '08:00', 'exit_date' => '2026-10-20', 'exit_clock' => '17:00']],
                   'fm' => ['mod' => 'yok', 'tip_dagilim' => [], 'notr' => null, 'aciklama' => '']],
        'servis' => ['hedef' => ['buyuk' => 0, 'kucuk' => 0], 'yazilacak' => ['buyuk' => 0, 'kucuk' => 0]],
        'mevcut' => ['mesai' => false, 'aktif_donem' => 0, 'servis' => ['BUYUK' => 0, 'KUCUK' => 0], 'kesin' => false],
        'secilebilir' => !in_array($durum, ['hata', 'zaten'], true), 'varsayilan_secili' => in_array($durum, ['hazir', 'uyari', 'devam'], true),
    ];
}
$XSS = '<script>alert(1)</script>';
$fisler = [
    fixture('hazir', 2),
    fixture('uyari', 3, ['uyarilar' => ['Servis fiyatı <b>yok</b>'], 'not' => 'Rampa']),
    fixture('engel', 4, ['engeller' => ['Bu mesaide 4 aktif kayıt var']]),
    fixture('onay', 5, ['uyarilar' => ['İşletme uyuşmuyor']]),
    fixture('hata', 6, ['hatalar' => ['Satır 6, «Erkek Tam»: "12 + 1" tek sayı değil'], 'cavus' => ['id' => null, 'kod' => $XSS, 'ad' => '<img src=x onerror=alert(1)>', 'yontem' => null]]),
    fixture('zaten', 7),
    fixture('devam', 8, ['kontrol' => $XSS, 'ham' => '"><svg onload=alert(2)>']),
    fixture('hazir', 9, ['plan' => ['gruplar' => [['kod' => 'ERKEK', 'tip_ad' => 'Erkek', 'profil' => 'fm', 'fm_saat' => 2, 'adet' => 5, 'entry_clock' => '08:00', 'exit_date' => '2026-10-21', 'exit_clock' => '01:00']],
        'fm' => ['mod' => 'toplam', 'tip_dagilim' => ['ERKEK' => [2 => 5]], 'notr' => true, 'aciklama' => '10 sa → 5 kişi: 5×2 sa']]]),
];
$model = [
    'mod' => 'onizle', 'token' => str_repeat('a', 32), 'csrf' => 'CSRF"TOKEN', 'url' => 'gunluk_fis_yukle.php', 'depo' => 'Depo A',
    'dosya_ad' => $XSS . '.xlsx', 'sha' => str_repeat('f', 64), 'dosya_uyarilar' => ['Tanınmayan sütun yok sayıldı: «' . $XSS . '».'],
    'varsayilan' => ['onay_yarim' => true, 'onay_fm' => true, 'ek_yaz' => false, 'isletme_onay' => false],
    'onizle' => ['fisler' => $fisler, 'ozet' => ['fis' => 8, 'durum' => ['hazir' => 2, 'uyari' => 1, 'engel' => 1, 'onay' => 1, 'hata' => 1, 'zaten' => 1, 'devam' => 1], 'kisi' => 24, 'fm_saat' => 10, 'servis' => ['buyuk' => 0, 'kucuk' => 1]]],
];
ob_start(); include $ROOT . '/_fis_onizle.php'; $html = (string)ob_get_clean();
ok('3) önizleme render edildi (boş değil, #fisYukle kapsayıcı)', strlen($html) > 2000 && str_contains($html, 'id="fisYukle"'), (string)strlen($html));
ok('3) tüm rozet sınıfları: hazır/uyarı/engel/hata/zaten/devam', (bool)preg_match('/fy-b-hazir.*fy-b-uyari.*fy-b-engel.*fy-b-hata.*fy-b-zaten.*fy-b-devam/s', $html));
ok('3) onay_yarim ve onay_fm varsayılan CHECKED; ek_yaz ve isletme_onay işaretsiz', (bool)preg_match('/name="onay_yarim" value="1" checked/', $html) && (bool)preg_match('/name="onay_fm" value="1" checked/', $html) && (bool)preg_match('/name="ek_yaz" value="1" >/', $html) && (bool)preg_match('/name="isletme_onay" value="1" >/', $html));
ok('3) gizli alanlar: csrf (kaçışlı), eylem=yaz, token', str_contains($html, 'name="csrf" value="CSRF&quot;TOKEN"') && str_contains($html, 'name="eylem" value="yaz"') && str_contains($html, 'name="token" value="' . str_repeat('a', 32) . '"'));
ok('3) kova/saat/sayı için GİZLİ ALAN YOK (veri istemciden gelmez)', !preg_match('/name="(fm|kova|saat|say|kadin|erkek|rampaci|servis)[^"]*"/', $html));
ok('3) XSS: <script>, <img onerror>, <svg onload> ham basılmadı', !str_contains($html, '<script>alert') && !str_contains($html, '<img src=x') && !str_contains($html, '<svg onload') && str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'));
ok('3) XSS: uyarı metnindeki <b> kaçışlı', !str_contains($html, 'Servis fiyatı <b>') && str_contains($html, 'Servis fiyatı &lt;b&gt;yok&lt;/b&gt;'));
// seçim kutuları: hata/zaten disabled, hazır/uyarı/devam checked, engel/onay işaretsiz ama etkin
$kutu = function (string $html, string $ad, int $satir): string { return preg_match('/<input type="checkbox" name="' . preg_quote($ad, '/') . '" value="' . $satir . '"[^>]*>/', $html, $m) ? $m[0] : ''; };
foreach ([2, 3, 8, 9] as $s) {
    $k = $kutu($html, 'secilen[]', $s);
    ok("3) satır $s: tablo kutusu CHECKED ve etkin", $k !== '' && (bool)preg_match('/\schecked\b/', $k) && !preg_match('/\sdisabled\b/', $k), $k);
}
foreach ([4, 5] as $s) { $k = $kutu($html, 'secilen[]', $s); ok("3) satır $s (engel/onay): işaretsiz AMA etkinleştirilebilir", $k !== '' && !preg_match('/\schecked\b/', $k) && !preg_match('/\sdisabled\b/', $k), $k); }
foreach ([6, 7] as $s) { $k = $kutu($html, 'secilen[]', $s); ok("3) satır $s (hata/zaten): disabled", $k !== '' && (bool)preg_match('/\sdisabled\b/', $k), $k); }
foreach ([2, 6] as $s) { $k = $kutu($html, 'secilen_m[]', $s); ok("3) satır $s: mobil kart kutusu da aynı durumda", $k !== '' && ((bool)preg_match('/\sdisabled\b/', $k)) === ($s === 6), $k); }
ok('3) mobil kart kutuları (secilen_m[]) aynı veri için de var', say_alt($html, 'name="secilen_m[]"') === 8 && say_alt($html, 'name="secilen[]"') === 8);
ok('3) .table-wrap.pc-only tablo + .mobile-only kartlar', str_contains($html, 'class="table-wrap pc-only"') && str_contains($html, 'class="mobile-only fy-kartlar"'));
ok('3) gece taşması çıkışı "(+1 gün)" gösterilir', str_contains($html, '08:00→01:00 (+1 gün)') || str_contains($html, '08:00→01:00') && str_contains($html, '(+1 gün)'));
ok('3) FM bölümü: toplam dağıtımı + "Ücret nötr ✓" rozeti', str_contains($html, 'Toplam saat → dağıtım') && str_contains($html, 'Ücret nötr ✓') && str_contains($html, '5×2 sa'));
ok('3) "Fişten okunan" ayrıntısı (kontrol notu + ham metin) kaçışlı', str_contains($html, 'Fişten okunan') && str_contains($html, '&quot;&gt;&lt;svg onload=alert(2)&gt;'));
ok('3) Vazgeç ayrı form: eylem=iptal + token + csrf', (bool)preg_match('/class="fy-vazgec".*?name="eylem" value="iptal".*?name="token"/s', $html));
ok('3) yaz düğmesi ve sayaç yeri', str_contains($html, 'id="fyYazBtn"') && str_contains($html, 'data-fy-sayi'));
ok('3) "Tümünü seç" kutusu formun İÇİNDE', (bool)preg_match('/<form[^>]*id="fyYazForm".*data-fy-hepsi.*<\/form>/s', $html));
ok('3) mesajlar: hata kırmızı / engel / uyarı ayrı sınıflar', str_contains($html, 'fy-not-hata') && str_contains($html, 'fy-not-engel') && str_contains($html, 'fy-not-uyari'));
ok('3) dosya adı + sha ilk 12 karakter, dosya uyarısı kaçışlı', str_contains($html, str_repeat('f', 12)) && !str_contains($html, str_repeat('f', 13)) && str_contains($html, 'Tanınmayan sütun yok sayıldı'));
ok('3) plan hash\'in ilk 8 karakteri yazılır (tam hash değil)', str_contains($html, 'plan abcdef01') && !str_contains($html, 'abcdef012'));

$sonucModel = ['mod' => 'sonuc', 'url' => 'gunluk_fis_yukle.php', 'dosya_ad' => 'a.xlsx', 'sha' => str_repeat('1', 64), 'sonuc' => ['sonuclar' => [
    ['satir' => 2, 'fis_no' => 'T-2', 'rev' => 0, 'durum' => 'yazildi', 'mesaj' => 'Yazıldı — 3 kişi kaydı', 'session_id' => 17, 'toplu_id' => 'TP2026102000abcdef', 'yazilan' => ['kisi' => 3, 'fm_onayli' => 2, 'fm_saat' => 4, 'yarim' => 1, 'servis' => ['buyuk' => 0, 'kucuk' => 1]], 'uyarilar' => ['Servis fiyatı ' . $XSS]],
    ['satir' => 3, 'fis_no' => 'T-3', 'rev' => 1, 'durum' => 'hata', 'mesaj' => 'Aktif kayıt var ' . $XSS],
    ['satir' => 4, 'fis_no' => 'T-4', 'rev' => 0, 'durum' => 'zaten_yuklu', 'mesaj' => 'Daha önce yazılmış'],
], 'ozet' => ['islenen' => 3, 'yazildi' => 1, 'devam_tamamlandi' => 0, 'zaten_yuklu' => 1, 'degisti' => 0, 'hata' => 1, 'kisi' => 3, 'fm_onayli' => 2, 'servis' => ['buyuk' => 0, 'kucuk' => 1]]]];
$model = $sonucModel;
ob_start(); include $ROOT . '/_fis_onizle.php'; $html2 = (string)ob_get_clean();
ok('3) sonuç ekranı: özet + fiş satırları + Mesai Detayı bağlantısı + toplu işlem kimliği', str_contains($html2, 'gunluk_isci_puantaj_detay.php?id=17') && str_contains($html2, 'TP2026102000abcdef') && str_contains($html2, '3</b> fiş işlendi'));
ok('3) sonuç ekranı: hatalı fiş için düzeltme yönergesi + yeni dosya bağlantısı', str_contains($html2, 'aynı dosyayı') && str_contains($html2, 'Yeni dosya yükle'));
ok('3) sonuç ekranı XSS kaçışlı', !str_contains($html2, '<script>alert') && str_contains($html2, '&lt;script&gt;'));
ok('3) sonuç ekranı: FM/Yarım/servis özetleri', str_contains($html2, '2 FM (4 sa) onaylı') && str_contains($html2, '1 yarım') && str_contains($html2, 'Servis: 0 büyük · 1 küçük'));

echo "\n--- Sonuç: $gecen geçti, $fail kaldı ---\n";
exit($fail > 0 ? 1 : 0);
