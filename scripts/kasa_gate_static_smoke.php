<?php
// =========================================================
// scripts/kasa_gate_static_smoke.php — Kasa: kapı / csrf / audit / dosya adı / değişmezlik / bağımlılık yönü STATİK denetimi
//   php scripts/kasa_gate_static_smoke.php     → çıkış kodu 0 = hepsi geçti
// Kaynağa bakar (yorumlar token_get_all ile atılır; çalıştırma YOK). Davranış testleri: kasa_core_smoke.php.
// Aşama 1 (arka uç) kapsamı: config/kasa_core.php + kasa_kaydet.php + migrate.php kartı + dosya adları / sw.js. Aşama 2'de eklenecek parçalar
// (kasa_fisleri.php / kasa_tanimlari.php çalışan sayfalar, kasa_cikti.php, config/kasa_ui.php, assets/kasa.css|js) MEVCUTSA denetlenir
// ("varsa denetle"): yer tutucu sayfa hâlâ yer tutucu kuralını, çalışan sayfa çalışan modül kuralını sağlamalıdır.
// =========================================================
declare(strict_types=1);
$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $n, bool $v, string $d = ''): void { global $pass, $fail; if ($v) { $pass++; echo "PASS $n\n"; } else { $fail++; echo "FAIL $n" . ($d !== '' ? " :: $d" : '') . "\n"; } }
/** PHP kaynağından yorumları atar. */
function kod(string $yol): string
{
    $s = (string)file_get_contents($yol); $o = '';
    foreach (token_get_all($s) as $t) { if (is_array($t)) { if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue; $o .= $t[1]; } else $o .= $t; }
    return $o;
}
/** Fonksiyon gövdesi (süslü parantez dengesiyle). */
function govde(string $kod, string $ad): ?string
{
    if (!preg_match('/function\s+' . preg_quote($ad, '/') . '\s*\(/', $kod, $m, PREG_OFFSET_CAPTURE)) return null;
    $i = strpos($kod, '{', $m[0][1]); if ($i === false) return null;
    $d = 0;
    for ($j = $i, $n = strlen($kod); $j < $n; $j++) { if ($kod[$j] === '{') $d++; elseif ($kod[$j] === '}' && --$d === 0) return substr($kod, $i, $j - $i + 1); }
    return null;
}
$pos = fn(string $s, string $a): int => ($p = strpos($s, $a)) === false ? -1 : $p;
$sirali = fn(array $ps): bool => !in_array(-1, $ps, true) && $ps === array_values(array_unique($ps)) && $ps == array_values((function ($x) { sort($x); return $x; })($ps));
$SQL_YAZ = '/\b(INSERT\s+INTO|UPDATE\s+`?\w+`?\s+SET|DELETE\s+FROM)\b/i';
$sayfalar = ['kasa_fisleri.php', 'kasa_tanimlari.php'];

echo "=== 1. Dosya kümesi ve adlar ===\n";
$kok = array_map('basename', glob($ROOT . '/kasa_*.php'));
sort($kok);
$izinli = ['kasa_cikti.php', 'kasa_fisleri.php', 'kasa_kaydet.php', 'kasa_tanimlari.php'];
ok('kökte kasa_*.php ⊆ {kasa_cikti, kasa_fisleri, kasa_kaydet, kasa_tanimlari}; fisleri + kaydet + tanimlari var', !array_diff($kok, $izinli) && in_array('kasa_fisleri.php', $kok, true) && in_array('kasa_kaydet.php', $kok, true) && in_array('kasa_tanimlari.php', $kok, true), json_encode($kok));
ok('api_kasa*.php / _kasa*.php kökte YOK', !glob($ROOT . '/api_kasa*.php') && !glob($ROOT . '/_kasa*.php'));
ok('config/kasa_core.php var', is_file($ROOT . '/config/kasa_core.php'));
$sw = (string)file_get_contents($ROOT . '/sw.js');
ok('sw.js SW_MUHASEBE_RE bulundu', (bool)preg_match('#var SW_MUHASEBE_RE = /(.+)/;#', $sw, $rm));
$re = '#' . str_replace('\\/', '/', $rm[1] ?? 'x') . '#';
foreach ([...$sayfalar, 'kasa_kaydet.php', 'kasa_cikti.php', 'kasa_yeni_bir_sayfa.php'] as $f) ok("sw.js ağ-only kuralı /$f dosyasını kapsar (ticari veri önbelleğe girmez)", @preg_match($re, '/' . $f) === 1, $re);
ok('sw.js SHELL\'e kasa.css/js girmez', !preg_match('#SHELL[^;]*assets/kasa\.(css|js)#s', $sw));
ok('stok.php / kantar.php / malzeme_stok.php sw ağ-only kuralının KAPSAMI DIŞINDA kalır (kasa önekinden etkilenmez)', @preg_match($re, '/stok.php') !== 1 && @preg_match($re, '/kantar.php') !== 1);

echo "\n=== 2. Sayfa kabuğu (yer tutucu ya da — Aşama 2'den sonra — çalışan modül) ===\n";
$calisan = []; $yerTutucu = [];
foreach (array_merge($sayfalar, is_file($ROOT . '/kasa_cikti.php') ? ['kasa_cikti.php'] : []) as $f) {
    $s = kod($ROOT . '/' . $f);
    if (str_contains($s, 'config/kasa_core.php')) { $calisan[] = $f; continue; }
    $yerTutucu[] = $f;
    ok("$f: yer tutucu — is_admin kapısı + 'hazırlanıyor', \$_POST yok, kasa_core çağrısı yok", (bool)preg_match('/if \(!is_admin\(\)\) \{ forbidden\(/', $s) && str_contains($s, 'hazırlanıyor') && !str_contains($s, '$_POST') && !str_contains($s, 'kasa_'));
}
ok('çalışan + yer tutucu sayfalar kümesi tam (' . count($calisan) . ' çalışan, ' . count($yerTutucu) . ' yer tutucu)', count($calisan) + count($yerTutucu) === count($sayfalar) + (int)is_file($ROOT . '/kasa_cikti.php'));
foreach ($calisan as $f) {
    $s = kod($ROOT . '/' . $f);
    $ps = array_map(fn($a) => $pos($s, $a), ['require_login()', 'if (!is_admin()) { forbidden(', "kasa_kapi('read')", 'kasa_no_store()', 'kasa_sayfa_kapisi($pdo)', 'render_header(']);
    if ($f === 'kasa_cikti.php') $ps = array_slice($ps, 0, 5);   // çıktı ucu sayfa gövdesi basmaz (A4 yazdır / xlsx)
    ok("$f: kabuk sırası require_login → is_admin → kasa_kapi('read') → kasa_no_store → kasa_sayfa_kapisi" . ($f === 'kasa_cikti.php' ? '' : ' → render_header'), $sirali($ps), json_encode($ps));
    ok("$f: config/kasa_core.php yüklenir; config/cari_core.php metni GEÇMEZ", !str_contains($s, 'config/cari_core.php'));
    ok("$f: yalnız GET — \$_POST YOK, csrf_check YOK, Location YOK", !str_contains($s, '$_POST') && !str_contains($s, 'csrf_check(') && !preg_match("/header\\s*\\(\\s*['\"]Location/i", $s));
    ok("$f: SQL yazma YOK, ->prepare/->query YOK", !preg_match($SQL_YAZ, $s) && !preg_match('/->(prepare|query|exec)\(/', $s));
    ok("$f: 'hazırlanıyor' yer tutucu metni YOK", !str_contains($s, 'hazırlanıyor'));
}

echo "\n=== 3. kasa_kaydet.php: tek yazma ucu ===\n";
$k = kod($ROOT . '/kasa_kaydet.php');
$p405 = $pos($k, '405'); $pCsrf = $pos($k, 'csrf_check(');
ok('POST dışı → 405 + Allow, require_login ve csrf_check\'ten ÖNCE', $p405 > 0 && $p405 < $pos($k, 'require_login()') && $p405 < $pCsrf && str_contains($k, "'POST'") && str_contains($k, "header('Allow: POST')"));
ok('sıra: require_login → kasa_no_store → JSON başlığı → csrf_check($_POST[\'csrf\'])', $sirali([$pos($k, 'require_login()'), $pos($k, 'kasa_no_store()'), $pos($k, 'application/json'), $pCsrf]) && str_contains($k, "csrf_check(\$_POST['csrf']"));
ok('sıra: csrf_check → $pdo = db() → kasa_sayfa_kapisi → ilk kasa_kapi', $sirali([$pCsrf, $pos($k, '$pdo = db()'), $pos($k, 'kasa_sayfa_kapisi($pdo)'), $pos($k, 'kasa_kapi(')]));
ok('yalnız config/kasa_core.php yüklenir (cari_core doğrudan değil)', str_contains($k, 'config/kasa_core.php') && !str_contains($k, 'config/cari_core.php'));
$yazFn = ['kasa_kart_kaydet(', 'kasa_kart_durum(', 'kasa_kart_sil(', 'kasa_tanim_kaydet(', 'kasa_tanim_durum(', 'kasa_tanim_sil(', 'kasa_hareket_ekle(', 'kasa_virman_ekle(', 'kasa_hareket_iptal('];
$ilkYaz = PHP_INT_MAX;
foreach ($yazFn as $fn) { $p = $pos($k, $fn); if ($p >= 0) $ilkYaz = min($ilkYaz, $p); }
ok('csrf_check, ilk kasa_ yazma çağrısından ÖNCE', $pCsrf > 0 && $pCsrf < $ilkYaz);
preg_match_all("/case '([a-z_]+)':(.*?)(?=case '|default:)/s", $k, $cm, PREG_SET_ORDER);
$isl = array_map(fn($m) => $m[1], $cm);
ok('işlem dalları (9, bu sırayla): kart_kaydet · kart_durum · kart_sil · tanim_kaydet · tanim_durum · tanim_sil · hareket_ekle · virman_ekle · hareket_iptal',
    $isl === ['kart_kaydet', 'kart_durum', 'kart_sil', 'tanim_kaydet', 'tanim_durum', 'tanim_sil', 'hareket_ekle', 'virman_ekle', 'hareket_iptal'], json_encode($isl));
$yetki = ['kart_kaydet' => 'write', 'kart_durum' => 'write', 'kart_sil' => 'delete', 'tanim_kaydet' => 'admin', 'tanim_durum' => 'admin', 'tanim_sil' => 'admin', 'hareket_ekle' => 'write', 'virman_ekle' => 'write', 'hareket_iptal' => 'delete'];
$cagri = ['kart_kaydet' => 'kasa_kart_kaydet(', 'kart_durum' => 'kasa_kart_durum(', 'kart_sil' => 'kasa_kart_sil(', 'tanim_kaydet' => 'kasa_tanim_kaydet(', 'tanim_durum' => 'kasa_tanim_durum(', 'tanim_sil' => 'kasa_tanim_sil(',
    'hareket_ekle' => 'kasa_hareket_ekle(', 'virman_ekle' => 'kasa_virman_ekle(', 'hareket_iptal' => 'kasa_hareket_iptal('];
foreach ($cm as $m) {
    $y = $yetki[$m[1]] ?? '?';
    ok("dal $m[1]: kasa_kapi('$y') İLK ifade; tek kasa_kapi; yalnız kasa_ fonksiyonu (cari_ çağrısı YOK); kasa_cevap", (bool)preg_match("/^\\s*kasa_kapi\\('" . $y . "'\\);/", $m[2]) && substr_count($m[2], 'kasa_kapi(') === 1
        && !preg_match('/\b(cari|stok|fatura)_[a-z_]+\(/', $m[2]) && str_contains($m[2], $cagri[$m[1]] ?? '#') && str_contains($m[2], 'kasa_cevap($s, $geri,'), substr($m[2], 0, 80));
}
$he = '';
foreach ($cm as $m) if ($m[1] === 'hareket_ekle') $he = $m[2];
ok('hareket_ekle dalı: kasa_hareket_ekle($pdo, $P, $uid) — TAM 3 argüman (kaynak parametresi GEÇİLMEZ; dahili türler POST\'tan reddedilir)', str_contains($he, 'kasa_hareket_ekle($pdo, $P, $uid)') && substr_count($k, 'kasa_hareket_ekle(') === 1 && !str_contains($k, 'kaynak_giris') && !str_contains($k, 'kaynak_cikis') && !str_contains($k, 'kaynak_modul'));
ok('`geri` yerel beyaz liste: kasa_geri_guvenli($_POST[\'geri\']) + çekirdekte kasa_(fisleri|tanimlari) regex\'i', str_contains($k, "kasa_geri_guvenli(\$_POST['geri']")
    && str_contains((string)file_get_contents($ROOT . '/config/kasa_core.php'), '^kasa_(fisleri|tanimlari)\.php(\?[^\r\n]*)?$'));
ok('kasa_kaydet.php: $_GET kullanmaz; doğrudan SQL yazmaz; ->prepare yok', !str_contains($k, '$_GET') && !preg_match($SQL_YAZ, $k) && !preg_match('/->(prepare|query|exec)\(/', $k));
ok('PDOException → 500 genel mesaj (ayrıntı yalnız error_log)', str_contains($k, 'catch (PDOException $e)') && str_contains($k, 'http_response_code(500)') && str_contains($k, 'error_log(') && str_contains($k, "'kod' => 'sunucu_hatasi'"));

echo "\n=== 4. Değişmezlik (yalnız çekirdek yazar) ===\n";
$ihlal = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $yol = $f->getPathname();
    if (!str_ends_with($yol, '.php') || preg_match('#/(vendor|scripts|storage|\.git|\.claude|node_modules)/#', $yol)) continue;
    if ($yol === $ROOT . '/config/kasa_core.php') continue;
    if (preg_match('/\b(INSERT\s+(?:IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+`?kasa_(kartlar|hareketler|tanimlar)\b/i', kod($yol))) $ihlal[] = substr($yol, strlen($ROOT) + 1);
}
ok('kasa_* tablolarına INSERT/UPDATE/DELETE yazan başka dosya YOK (config/kasa_core.php hariç; migrate.php yalnız CREATE)', !$ihlal, implode(',', $ihlal));
$c = kod($ROOT . '/config/kasa_core.php');
ok('core: kasa_hareketler\'de DELETE YOK (INSERT-only defter)', !preg_match('/DELETE\s+FROM\s+`?kasa_hareketler/i', $c));
preg_match_all('/UPDATE\s+`?kasa_hareketler`?\s+SET\s+(.+?)\s+WHERE/i', $c, $um);
$setler = array_values(array_unique(array_map(fn($x) => preg_replace('/\s+/', ' ', trim($x)), $um[1])));
$beyaz = ['belge_no = ?, cari_hareket_id = ?', 'belge_no = ?, bagli_id = ?', "durum = 'iptal', iptal_at = ?, iptal_by = ?, iptal_neden = ?"];
ok('core: kasa_hareketler UPDATE\'leri YALNIZ beyaz liste (3 kalıp, tam metin) ve üçü de var', count($setler) === 3 && !array_diff($setler, $beyaz) && !array_diff($beyaz, $setler), json_encode($setler, JSON_UNESCAPED_UNICODE));
ok("core: iptal UPDATE'i yalnız durum='gecerli' satırlara (WHERE … AND durum = 'gecerli'); iki yerde (hareket_iptal, kaynakli_iptal)", substr_count($c, "iptal_neden = ? WHERE id = ? AND durum = 'gecerli'") === 1 && substr_count($c, "iptal_neden = ? WHERE kaynak_modul = ? AND kaynak_id = ? AND durum = 'gecerli'") === 1);
ok('core: INSERT INTO kasa_hareketler tam 2 kez (kasa_hareket_ekle + kasa_virman_ekle içinde)', substr_count($c, 'INSERT INTO kasa_hareketler') === 2 && str_contains((string)govde($c, 'kasa_hareket_ekle'), 'INSERT INTO kasa_hareketler') && str_contains((string)govde($c, 'kasa_virman_ekle'), 'INSERT INTO kasa_hareketler'));
ok('core: DELETE yalnız kasa_kartlar (kasa_kart_sil, hareketsiz) ve kasa_tanimlar (kasa_tanim_sil, kullanılmayan)', preg_match_all('/DELETE\s+FROM\s+`?(\w+)/i', $c, $dm) === 2 && $dm[1] === ['kasa_kartlar', 'kasa_tanimlar']
    && str_contains((string)govde($c, 'kasa_kart_sil'), 'DELETE FROM kasa_kartlar') && str_contains((string)govde($c, 'kasa_tanim_sil'), 'DELETE FROM kasa_tanimlar'));
ok('core: kasa_kart_sil hareket sayısına bakar (kasa_kart_hareket_say) DELETE\'ten ÖNCE', $pos((string)govde($c, 'kasa_kart_sil'), 'kasa_kart_hareket_say(') < $pos((string)govde($c, 'kasa_kart_sil'), 'DELETE FROM'));
ok('core: cari_* TABLOLARINA hiç SQL yok (okuma dahil; yalnız cari_core fonksiyonları)', !preg_match('/\b(FROM|INTO|UPDATE|JOIN)\s+`?cari_(hareketler|kartlar|tanimlar)\b/i', $c));
ok('core: named parametre (:ad) kullanılmaz — pozisyonel ?', !preg_match('/[ (,=]:[a-z_]{2,}\b/', $c));
ok('core: depo filtresi yalnız depo_sql_in (kasa_depo_filtre) / depot_visible_to_user (depo_sql_column / depo_sql_records YOK)', str_contains($c, 'depo_sql_in(') && str_contains($c, 'depot_visible_to_user(') && !str_contains($c, 'depo_sql_column(') && !str_contains($c, 'depo_sql_records'));
ok('core: kasa_bakiye / kasa_bakiyeler_toplu / kasa_defter hareket depo süzgeci UYGULAMAZ (kasa bakiyesi bütündür)', !str_contains((string)govde($c, 'kasa_bakiye'), 'depo') && !str_contains((string)govde($c, 'kasa_bakiyeler_toplu'), 'depo') && !str_contains((string)govde($c, 'kasa_defter'), 'depo_sql'));
$yazanlar = ['kasa_kart_kaydet', 'kasa_kart_durum', 'kasa_kart_sil', 'kasa_tanim_kaydet', 'kasa_tanim_durum', 'kasa_tanim_sil', 'kasa_hareket_ekle', 'kasa_virman_ekle', 'kasa_hareket_iptal', 'kasa_kaynakli_iptal'];
foreach ($yazanlar as $fn) ok("core $fn: audit_log_event(…, 'kasa', …) var", ($g = govde($c, $fn)) !== null && (bool)preg_match("/audit_log_event\\([^;]*?, 'kasa', /", $g));
$yetkili = [];
foreach ([...$yazanlar, 'kasa_hareket_ortak', 'kasa_kart_dogrula', 'kasa_eksi_uyarisi', 'kasa_eksik_hesapla'] as $fn) if (preg_match('/\b(can|is_admin|require_perm|kasa_can|kasa_kapi|cari_can|cari_kapi)\s*\(/', (string)govde($c, $fn))) $yetkili[] = $fn;
ok('core: yazan / doğrulayan fonksiyonlarda yetki kontrolü YOK (uçların işi; kaynakli iptal yetki kontrolü yapmaz)', !$yetkili, implode(',', $yetkili));
ok('core: kasa_can yalnız is_admin() (tek değişim noktası; izin kataloğuna bağlanmadı)', ($g = govde($c, 'kasa_can')) !== null && str_contains($g, 'is_admin()') && !preg_match('/\bcan\(/', $g));
foreach (['kasa_belge_no', 'kasa_para_db', 'kasa_tarih_coz', 'kasa_onay', 'kasa_tur_bilgi', 'kasa_hareket_turleri', 'kasa_dahili_turler', 'kasa_geri_guvenli'] as $fn) ok("core $fn SAF (PDO/sorgu/audit/\$GLOBALS YOK)", ($g = govde($c, $fn)) !== null && !preg_match('/PDO|->|audit_log_event|\$GLOBALS/', $g));
ok('core: para kuruş TAM SAYI — bakiye fonksiyonları cari_kurus ile (float toplama sonucu yuvarlanır); bakiye dönüş tipi int', str_contains($c, 'function kasa_bakiye(PDO $pdo, int $kasaId, ?string $bitis = null): int') && str_contains((string)govde($c, 'kasa_bakiye'), 'cari_kurus('));

echo "\n=== 5. Zorunlu imzalar ve yazma sırası ===\n";
foreach ([
    'function kasa_hareket_ekle(PDO $pdo, array $g, int $uid, ?array $kaynak = null): array',
    'function kasa_virman_ekle(PDO $pdo, array $g, int $uid): array',
    'function kasa_hareket_iptal(PDO $pdo, int $id, string $neden, int $uid): array',
    'function kasa_kaynakli_iptal(PDO $pdo, string $modul, int $kaynakId, string $neden, int $uid): array',
    'function kasa_bakiye(PDO $pdo, int $kasaId, ?string $bitis = null): int',
    'function kasa_bakiyeler_toplu(PDO $pdo, array $ids): array',
    'function kasa_defter(PDO $pdo, int $kasaId, ?string $bas, ?string $bit): ?array',
    'function kasa_hareket_listesi(PDO $pdo, array $f, int $limit = 100, int $offset = 0): array',
    'function kasa_secenekleri(PDO $pdo, ?string $pb = null, bool $yalnizAktif = true): array',
    'function kasa_kart_kaydet(PDO $pdo, array $g, int $uid, ?int $id = null): array', 'function kasa_kart_durum(PDO $pdo, int $id, bool $aktif, int $uid): array', 'function kasa_kart_sil(PDO $pdo, int $id, int $uid): array',
    'function kasa_kart_listesi(PDO $pdo, array $f = []): array', 'function kasa_tanim_listesi(PDO $pdo, string $tur, bool $yalnizAktif = false): array',
    'function kasa_tanim_kaydet(PDO $pdo, string $tur, string $ad, int $sira, int $uid, ?int $id = null): array', 'function kasa_tanim_durum(PDO $pdo, int $id, bool $aktif, int $uid): array', 'function kasa_tanim_sil(PDO $pdo, int $id, int $uid): array',
    'function kasa_tablolar(): array', 'function kasa_hazir(PDO $pdo): bool', 'function kasa_migrate(PDO $pdo): array', 'function kasa_sayfa_kapisi(PDO $pdo): void', 'function kasa_can(string $eylem): bool', 'function kasa_kapi(string $eylem): void',
    'function kasa_geri_guvenli(mixed $g): string', 'function kasa_cevap(array $s, string $geri, string $okMesaj, bool $json): never',
] as $imza) ok('imza SABİT (çek-senet modülü buna dayanır): ' . substr($imza, 9, strpos($imza, '(') - 9), str_contains($c, $imza));
ok('KASA_TEVSIK_SINIRI_KURUS = 3_000_000 (30.000 TL) ve uyarı metni VUK GT 575', str_contains($c, 'const KASA_TEVSIK_SINIRI_KURUS = 3000000;') && str_contains($c, '30.000 TL ve üzeri tahsilat/ödemeler banka aracılığıyla yapılmalıdır (VUK GT 575).'));
$hk = (string)govde($c, 'kasa_hareket_ekle');
ok("kasa_hareket_ekle: dahili tür yalnız kasa_tur_bilgi(\$tur, \$kaynak !== null) ile çözülür (POST yolu \$kaynak === null → reddedilir)", str_contains($hk, 'kasa_tur_bilgi($tur, $kaynak !== null)') && str_contains($hk, "|| \$tur === 'virman'"));
ok('kasa_hareket_ekle: para birimi KASADAN ($pb = (string)$kasa[para_birimi]); $g[para_birimi] OKUNMAZ', str_contains($hk, "\$pb = (string)\$kasa['para_birimi']") && !str_contains($hk, "\$g['para_birimi']"));
ok('kasa_hareket_ekle: cari_hareket_ekle( tek çağrı + [\'modul\' => \'kasa\', \'id\' => $hid]; yalnız bilgi[\'cari\'] doluyken', substr_count($c, 'cari_hareket_ekle(') === 1 && str_contains($hk, "\$uid, ['modul' => 'kasa', 'id' => \$hid]);") && str_contains($hk, "if (\$bilgi['cari'] !== null) {\n                \$cr = cari_hareket_ekle("));
ok('kasa_hareket_ekle: cari iç !ok → RuntimeException(KASA_IC_HATA), catch kodu ayırır', str_contains($hk, "if (empty(\$cr['ok']))") && str_contains($hk, 'KASA_IC_HATA)') && str_contains($hk, '$e->getCode() !== KASA_IC_HATA'));
ok('kasa_hareket_ekle: kilit (kasa_kart_kilitle) → bakiye kontrolü → INSERT → cari → UPDATE sırası', $sirali([$pos($hk, 'kasa_kart_kilitle('), $pos($hk, 'kasa_eksik_hesapla('), $pos($hk, 'INSERT INTO kasa_hareketler'), $pos($hk, 'cari_hareket_ekle('), $pos($hk, 'UPDATE kasa_hareketler SET belge_no')]));
ok('kasa_hareket_ekle: audit commit SONRASI (kasa_tx bloğundan sonra)', $pos($hk, "audit_log_event('create', 'kasa'") > $pos($hk, '} catch (PDOException $e)'));
ok('kasa_hareket_ekle: yetersiz bakiye → kod yetersiz_bakiye (bakiye + eksik); eksiye_izin + kasa.eksi_izin birlikte denetlenir', str_contains($hk, "'kod' => 'yetersiz_bakiye'") && str_contains($hk, "(int)\$taze['eksi_izin'] !== 1") && str_contains($hk, '$eksiyeIzin'));
$vk = (string)govde($c, 'kasa_virman_ekle');
ok('kasa_virman_ekle: iki kasa kilitli, aynı pb, farklı kasa; cari defterine YAZMAZ', str_contains($vk, 'kasa_kart_kilitle($pdo, [$kid, $hid])') && str_contains($vk, "(string)\$kk['para_birimi'] !== (string)\$hk['para_birimi']") && !preg_match('/cari_(hareket|virman|kaynakli|kart)/', $vk));
$hi = (string)govde($c, 'kasa_hareket_iptal');
ok("kasa_hareket_iptal: kaynaklı satır → kod kaynakli; cari_kaynakli_iptal(\$pdo, 'kasa', \$id; tx içinde !ok → KASA_IC_HATA", str_contains($hi, "'kod' => 'kaynakli'") && str_contains($hi, "cari_kaynakli_iptal(\$pdo, 'kasa', \$id") && str_contains($hi, 'KASA_IC_HATA)') && substr_count($c, 'cari_kaynakli_iptal(') === 1);
$ki = (string)govde($c, 'kasa_kaynakli_iptal');
ok('kasa_kaynakli_iptal: idempotent (satır yoksa ok + iptal_idler boş) ve yetki / csrf çağrısı yok', str_contains($ki, "'iptal_idler' => []") && str_contains($ki, "durum = 'gecerli'") && !str_contains($ki, 'csrf'));
ok('kasa_cevap: 409 kodları (yetersiz_bakiye, kaynakli, hareketli, kullanimda); sunucu_hatasi → 500', str_contains((string)govde($c, 'kasa_cevap'), "['yetersiz_bakiye', 'kaynakli', 'hareketli', 'kullanimda']") && str_contains((string)govde($c, 'kasa_cevap'), "'sunucu_hatasi') http_response_code(500)"));
ok('kasa_core: cari_core require_once; başka modül (stok/fatura/çek-senet) require YOK', str_contains($c, "require_once __DIR__ . '/cari_core.php';") && !preg_match("/require(_once)?\s+__DIR__\s*\.\s*'\/(stok|fatura|ceksenet)/", $c));

echo "\n=== 6. Bağımlılık yönü ===\n";
foreach (['config/cari_core.php', 'config/stok_core.php', 'config/stok_ui.php', 'config/cari_ui.php', 'config/fatura_core.php', 'config/fatura_ui.php', 'stok_kaydet.php', 'cari_kaydet.php', 'fatura_kaydet.php'] as $f) {
    ok("$f kasa çekirdeğini / ucunu bilmez ('kasa_core', 'kasa_kaydet', 'kasa_hareket' yok)", is_file($ROOT . '/' . $f) && !preg_match('/kasa_core|kasa_kaydet|kasa_hareket|kasa_virman/i', (string)file_get_contents($ROOT . '/' . $f)));
}
foreach (['config/kasa_core.php', 'config/kasa_ui.php'] as $f) {
    if (!is_file($ROOT . '/' . $f)) continue;
    $raw = (string)file_get_contents($ROOT . '/' . $f);
    ok("$f: çek-senet modülünü bilmez ('ceksenet' / 'çek-senet' sözcüğü GEÇMEZ)", stripos($raw, 'ceksenet') === false && stripos($raw, 'çek-senet') === false && stripos($raw, 'cek-senet') === false);
}
ok('core: stok_ / fatura_ fonksiyonu çağrısı YOK (yalnız cari_ + kendi kasa_)', !preg_match('/\b(stok|fatura)_[a-z_]+\(/', $c));

echo "\n=== 7. Şema yalnız migrate.php'den ===\n";
$gecerli = [];
foreach (glob($ROOT . '/*.php') as $f) if (preg_match('/\bkasa_migrate\s*\(/', kod($f))) $gecerli[] = basename($f);
ok('kasa_migrate( tek çağıran migrate.php (kök sayfalar)', $gecerli === ['migrate.php'], json_encode($gecerli));
$db = (string)file_get_contents($ROOT . '/config/db.php');
ok('config/db.php\'de kasa_ CREATE yok (otomatik migrasyon YOK)', !preg_match('/CREATE\s+TABLE[^;]*kasa_(kartlar|hareketler|tanimlar)/i', $db) && !str_contains($db, 'kasa_hareketler'));
ok('kasa tablolarında FOREIGN KEY / ALTER / ENUM / JSON / ON UPDATE / GENERATED yok', ($g = govde($c, 'kasa_tablolar')) !== null && !preg_match('/FOREIGN\s+KEY|ALTER\s+TABLE|ENUM\(|\bJSON\b|ON\s+UPDATE|GENERATED/i', $g));
ok('kasa_hareketler: istek_anahtari UNIQUE + (kasa_id, tarih, id) + (kaynak_modul, kaynak_id) + (cari_id) indeksleri; kasa_kartlar kod UNIQUE; kasa_tanimlar (tur, ad) UNIQUE',
    str_contains($c, 'UNIQUE KEY `uq_kasah_istek` (`istek_anahtari`)') && str_contains($c, 'KEY `idx_kasah_kasa` (`kasa_id`, `tarih`, `id`)') && str_contains($c, 'KEY `idx_kasah_kaynak` (`kaynak_modul`, `kaynak_id`)') && str_contains($c, 'KEY `idx_kasah_cari` (`cari_id`)')
    && str_contains($c, 'UNIQUE KEY `uq_kasa_kod` (`kod`)') && str_contains($c, 'UNIQUE KEY `uq_kasat_tur_ad` (`tur`, `ad`)'));
ok('kasa_hareketler tutar DECIMAL(14,2); kasa_kartlar eksi_izin + para_birimi; tohum yalnız kasa_tanimlar YENİ kurulduysa', str_contains($c, '`tutar`           DECIMAL(14,2)') && str_contains($c, '`eksi_izin`') && str_contains((string)govde($c, 'kasa_migrate'), "\$ad === 'kasa_tanimlar' && (int)\$pdo->query('SELECT COUNT(*) FROM `kasa_tanimlar`')->fetchColumn() === 0"));
$mg = (string)file_get_contents($ROOT . '/migrate.php');
ok('migrate.php: require kasa_core + mg_kart_ac(\'kasa\') + ne=kasa dalı (csrf + kasa_migrate + audit migrate)', str_contains($mg, 'config/kasa_core.php') && str_contains($mg, "mg_kart_ac('kasa')")
    && (bool)preg_match("/=== 'kasa'\)\s*\{\s*\n\s*csrf_check[^}]*kasa_migrate\(\\\$pdo\)[^}]*audit_log_event\('migrate', 'kasa'/s", $mg));
ok('migrate.php: kasa kartı fatura kartından SONRA; ✓/✗ durum satırı + form ne=kasa + cari önkoşul uyarısı', $pos($mg, "mg_kart_ac('fatura')") > 0 && $pos($mg, "mg_kart_ac('kasa')") > $pos($mg, "mg_kart_ac('fatura')")
    && str_contains($mg, "\$kasaHazir ? '✓ Tablolar var' : '✗ Tablolar eksik'") && str_contains($mg, 'name="ne" value="kasa"') && str_contains($mg, "cari_hazir(\$pdo) ? null : 'Cari İşlemler'"));

echo "\n=== 8. Aşama 2 parçaları (varsa) ===\n";
if (is_file($ROOT . '/config/kasa_ui.php')) {
    $ui = kod($ROOT . '/config/kasa_ui.php');
    ok('config/kasa_ui.php: SORGU / yazma YOK, $_POST yok', !preg_match('/->(prepare|query|exec)\(/', $ui) && !preg_match($SQL_YAZ, $ui) && !str_contains($ui, '$_POST'));
}
if (is_file($ROOT . '/assets/kasa.js')) {
    $js = (string)file_get_contents($ROOT . '/assets/kasa.js');
    ok('kasa.js: yazmalar yalnız kasa_kaydet.php; localStorage YOK; innerHTML en çok 1 (htmlParca)', str_contains($js, 'kasa_kaydet.php') && !str_contains($js, 'localStorage') && substr_count($js, 'innerHTML') <= 1 && !str_contains($js, 'parseFloat'));
}
if (is_file($ROOT . '/assets/kasa.css')) {
    $cssKod = preg_replace('#/\*.*?\*/#s', '', (string)file_get_contents($ROOT . '/assets/kasa.css')) ?? '';
    ok('kasa.css: çıplak html / body / input / .btn / .card seçicisi YOK; overflow-x: hidden YOK', !preg_match('/(^|[},]\s*)(html|body|input|\.btn|\.card)\s*[{,]/m', $cssKod) && !preg_match('/overflow-x\s*:\s*hidden/', $cssKod));
}
ok('style.css\'te .ks- yok; app.js\'te kasa_kaydet yok (ortak dosyalara dokunulmadı)', !str_contains((string)file_get_contents($ROOT . '/assets/style.css'), '.ks-') && !str_contains((string)file_get_contents($ROOT . '/assets/app.js'), 'kasa_kaydet'));
ok('Aşama 1 notu: kasa.css/js ve kasa_ui.php henüz yoksa bu bölüm hiçbir şeyi atlamaz (varsa denetlenir)', true);

echo "\n=== 9. Sürüm eşitliği (≥527) ===\n";
preg_match("/define\('APP_SURUM', 'v(\d+)'\)/", (string)file_get_contents($ROOT . '/config/helpers.php'), $a1);
preg_match("/CACHE_NAME = 'yukleme-plani-v(\d+)'/", $sw, $a2);
preg_match('/\*\*SW Cache:\*\* `yukleme-plani-v(\d+)`/', (string)file_get_contents($ROOT . '/CLAUDE.md'), $a3);
ok('APP_SURUM = sw.js CACHE_NAME = CLAUDE.md SW Cache ve ≥ 527', isset($a1[1], $a2[1], $a3[1]) && $a1[1] === $a2[1] && $a2[1] === $a3[1] && (int)$a1[1] >= 527, json_encode([$a1[1] ?? null, $a2[1] ?? null, $a3[1] ?? null]));

echo "\n$pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
