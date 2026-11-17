<?php
// =========================================================
// scripts/ceksenet_gate_static_smoke.php — Çek-Senet: kapı / csrf / audit / dosya adı / değişmezlik / bağımlılık yönü STATİK denetimi
//   php scripts/ceksenet_gate_static_smoke.php     → çıkış kodu 0 = hepsi geçti
// Kaynağa bakar (yorumlar token_get_all ile atılır; çalıştırma YOK). Davranış testleri: ceksenet_core_smoke.php.
// Aşama 3 (arka uç) kapsamı: config/ceksenet_core.php + cek_kaydet.php + migrate.php kartı + dosya adları / sw.js. Aşama 4 parçaları
// (cek_dekontu / senet_dekontu / cek_tanimlari çalışan sayfalar, cek_cikti.php, config/ceksenet_ui.php, assets/ceksenet.css|js) MEVCUTSA denetlenir ("varsa denetle").
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
$sayfalar = ['cek_dekontu.php', 'senet_dekontu.php', 'cek_tanimlari.php'];

echo "=== 1. Dosya kümesi ve adlar ===\n";
$kok = array_map('basename', array_merge(glob($ROOT . '/cek_*.php') ?: [], glob($ROOT . '/senet_*.php') ?: [], glob($ROOT . '/ceksenet*.php') ?: []));
sort($kok);
$izinli = ['cek_cikti.php', 'cek_dekontu.php', 'cek_kaydet.php', 'cek_tanimlari.php', 'senet_dekontu.php'];
ok('kökte cek_* / senet_* ⊆ {cek_cikti, cek_dekontu, cek_kaydet, cek_tanimlari, senet_dekontu}; üç sayfa + kaydet var', !array_diff($kok, $izinli) && !array_diff([...$sayfalar, 'cek_kaydet.php'], $kok), json_encode($kok));
ok('api_cek*.php / api_senet*.php / _cek*.php kökte YOK', !glob($ROOT . '/api_cek*.php') && !glob($ROOT . '/api_senet*.php') && !glob($ROOT . '/_cek*.php') && !glob($ROOT . '/_senet*.php'));
ok('config/ceksenet_core.php var', is_file($ROOT . '/config/ceksenet_core.php'));
$sw = (string)file_get_contents($ROOT . '/sw.js');
ok('sw.js SW_MUHASEBE_RE bulundu', (bool)preg_match('#var SW_MUHASEBE_RE = /(.+)/;#', $sw, $rm));
$re = '#' . str_replace('\\/', '/', $rm[1] ?? 'x') . '#';
foreach ([...$sayfalar, 'cek_kaydet.php', 'cek_cikti.php', 'senet_yeni_sayfa.php'] as $f) ok("sw.js ağ-only kuralı /$f dosyasını kapsar (ticari veri önbelleğe girmez)", @preg_match($re, '/' . $f) === 1, $re);
ok('sw.js SHELL\'e ceksenet.css/js girmez', !preg_match('#SHELL[^;]*assets/ceksenet\.(css|js)#s', $sw));

echo "\n=== 2. Sayfa kabuğu (yer tutucu ya da — Aşama 4'ten sonra — çalışan modül) ===\n";
$calisan = []; $yerTutucu = [];
foreach (array_merge($sayfalar, is_file($ROOT . '/cek_cikti.php') ? ['cek_cikti.php'] : []) as $f) {
    $s = kod($ROOT . '/' . $f);
    if (str_contains($s, 'config/ceksenet_core.php')) { $calisan[] = $f; continue; }
    $yerTutucu[] = $f;
    ok("$f: yer tutucu — is_admin kapısı + 'hazırlanıyor', \$_POST yok, ceksenet_core çağrısı yok", (bool)preg_match('/if \(!is_admin\(\)\) \{ forbidden\(/', $s) && str_contains($s, 'hazırlanıyor') && !str_contains($s, '$_POST') && !str_contains($s, 'ceksenet_'));
}
ok('çalışan + yer tutucu sayfalar kümesi tam (' . count($calisan) . ' çalışan, ' . count($yerTutucu) . ' yer tutucu)', count($calisan) + count($yerTutucu) === count($sayfalar) + (int)is_file($ROOT . '/cek_cikti.php'));
foreach ($calisan as $f) {
    $s = kod($ROOT . '/' . $f);
    $ps = array_map(fn($a) => $pos($s, $a), ['require_login()', 'if (!is_admin()) { forbidden(', "ceksenet_kapi('read')", 'ceksenet_no_store()', 'ceksenet_sayfa_kapisi($pdo)', 'render_header(']);
    if ($f === 'cek_cikti.php') $ps = array_slice($ps, 0, 5);   // çıktı ucu sayfa gövdesi basmaz (A4 yazdır / xlsx)
    ok("$f: kabuk sırası require_login → is_admin → ceksenet_kapi('read') → ceksenet_no_store → ceksenet_sayfa_kapisi" . ($f === 'cek_cikti.php' ? '' : ' → render_header'), $sirali($ps), json_encode($ps));
    ok("$f: config/cari_core.php / kasa_core.php metni GEÇMEZ (çekirdek yalnız ceksenet_core)", !str_contains($s, 'config/cari_core.php') && !str_contains($s, 'config/kasa_core.php'));
    ok("$f: yalnız GET — \$_POST YOK, csrf_check YOK, Location YOK", !str_contains($s, '$_POST') && !str_contains($s, 'csrf_check(') && !preg_match("/header\\s*\\(\\s*['\"]Location/i", $s));
    ok("$f: SQL yazma YOK, ->prepare/->query YOK", !preg_match($SQL_YAZ, $s) && !preg_match('/->(prepare|query|exec)\(/', $s));
    ok("$f: 'hazırlanıyor' yer tutucu metni YOK", !str_contains($s, 'hazırlanıyor'));
}

echo "\n=== 3. cek_kaydet.php: tek yazma ucu ===\n";
$k = kod($ROOT . '/cek_kaydet.php');
$p405 = $pos($k, '405'); $pCsrf = $pos($k, 'csrf_check(');
ok('POST dışı → 405 + Allow, require_login ve csrf_check\'ten ÖNCE', $p405 > 0 && $p405 < $pos($k, 'require_login()') && $p405 < $pCsrf && str_contains($k, "'POST'") && str_contains($k, "header('Allow: POST')"));
ok('sıra: require_login → ceksenet_no_store → JSON başlığı → csrf_check($_POST[\'csrf\'])', $sirali([$pos($k, 'require_login()'), $pos($k, 'ceksenet_no_store()'), $pos($k, 'application/json'), $pCsrf]) && str_contains($k, "csrf_check(\$_POST['csrf']"));
ok('sıra: csrf_check → $pdo = db() → ceksenet_sayfa_kapisi → ilk ceksenet_kapi', $sirali([$pCsrf, $pos($k, '$pdo = db()'), $pos($k, 'ceksenet_sayfa_kapisi($pdo)'), $pos($k, 'ceksenet_kapi(')]));
ok('yalnız config/ceksenet_core.php yüklenir (cari_core / kasa_core doğrudan değil)', str_contains($k, 'config/ceksenet_core.php') && !str_contains($k, 'config/cari_core.php') && !str_contains($k, 'config/kasa_core.php'));
$yazFn = ['ceksenet_bordro_kaydet(', 'ceksenet_islem_uygula(', 'ceksenet_hareket_iptal(', 'ceksenet_tanim_kaydet(', 'ceksenet_tanim_durum(', 'ceksenet_tanim_sil('];
$ilkYaz = PHP_INT_MAX;
foreach ($yazFn as $fn) { $p = $pos($k, $fn); if ($p >= 0) $ilkYaz = min($ilkYaz, $p); }
ok('csrf_check, ilk ceksenet_ yazma çağrısından ÖNCE', $pCsrf > 0 && $pCsrf < $ilkYaz);
preg_match_all("/case '([a-z_]+)':(.*?)(?=case '|default:)/s", $k, $cm, PREG_SET_ORDER);
$isl = array_map(fn($m) => $m[1], $cm);
ok('işlem dalları (6, bu sırayla): bordro_kaydet · islem_uygula · hareket_iptal · tanim_kaydet · tanim_durum · tanim_sil', $isl === ['bordro_kaydet', 'islem_uygula', 'hareket_iptal', 'tanim_kaydet', 'tanim_durum', 'tanim_sil'], json_encode($isl));
$yetki = ['bordro_kaydet' => 'write', 'islem_uygula' => 'write', 'hareket_iptal' => 'delete', 'tanim_kaydet' => 'admin', 'tanim_durum' => 'admin', 'tanim_sil' => 'admin'];
$cagri = ['bordro_kaydet' => 'ceksenet_bordro_kaydet(', 'islem_uygula' => 'ceksenet_islem_uygula(', 'hareket_iptal' => 'ceksenet_hareket_iptal(', 'tanim_kaydet' => 'ceksenet_tanim_kaydet(', 'tanim_durum' => 'ceksenet_tanim_durum(', 'tanim_sil' => 'ceksenet_tanim_sil('];
foreach ($cm as $m) {
    $y = $yetki[$m[1]] ?? '?';
    ok("dal $m[1]: ceksenet_kapi('$y') İLK ifade; tek ceksenet_kapi; yalnız ceksenet_ fonksiyonu (cari_/kasa_ çağrısı YOK); ceksenet_cevap", (bool)preg_match("/^\\s*ceksenet_kapi\\('" . $y . "'\\);/", $m[2]) && substr_count($m[2], 'ceksenet_kapi(') === 1
        && !preg_match('/\b(cari|kasa|stok|fatura)_[a-z_]+\(/', $m[2]) && str_contains($m[2], $cagri[$m[1]] ?? '#') && str_contains($m[2], 'ceksenet_cevap($s, $geri,'), substr($m[2], 0, 80));
}
ok('`geri` yerel beyaz liste: ceksenet_geri_guvenli($_POST[\'geri\']) + çekirdekte (cek_dekontu|senet_dekontu|cek_tanimlari) regex\'i', str_contains($k, "ceksenet_geri_guvenli(\$_POST['geri']")
    && str_contains((string)file_get_contents($ROOT . '/config/ceksenet_core.php'), '^(cek_dekontu|senet_dekontu|cek_tanimlari)\.php(\?[^\r\n]*)?$'));
ok('cek_kaydet.php: $_GET kullanmaz; doğrudan SQL yazmaz; ->prepare yok', !str_contains($k, '$_GET') && !preg_match($SQL_YAZ, $k) && !preg_match('/->(prepare|query|exec)\(/', $k));
ok('PDOException → 500 genel mesaj (ayrıntı yalnız error_log)', str_contains($k, 'catch (PDOException $e)') && str_contains($k, 'http_response_code(500)') && str_contains($k, 'error_log(') && str_contains($k, "'kod' => 'sunucu_hatasi'"));
ok('uç geçiş adını `gecis` alanından okur (`islem` yönlendirme anahtarı; core da `gecis` önceliklidir)', str_contains((string)govde(kod($ROOT . '/config/ceksenet_core.php'), 'ceksenet_islem_uygula'), "\$g['gecis'] ?? \$g['islem']"));

echo "\n=== 4. Değişmezlik (yalnız çekirdek yazar) ===\n";
$ihlal = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $yol = $f->getPathname();
    if (!str_ends_with($yol, '.php') || preg_match('#/(vendor|scripts|storage|\.git|\.claude|node_modules)/#', $yol)) continue;
    if ($yol === $ROOT . '/config/ceksenet_core.php') continue;
    if (preg_match('/\b(INSERT\s+(?:IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+`?ceksenet_(kayitlar|hareketler|tanimlar)\b/i', kod($yol))) $ihlal[] = substr($yol, strlen($ROOT) + 1);
}
ok('ceksenet_* tablolarına INSERT/UPDATE/DELETE yazan başka dosya YOK (config/ceksenet_core.php hariç; migrate.php yalnız CREATE)', !$ihlal, implode(',', $ihlal));
$c = kod($ROOT . '/config/ceksenet_core.php');
ok('core: ceksenet_hareketler ve ceksenet_kayitlar\'de DELETE YOK (INSERT-only defter)', !preg_match('/DELETE\s+FROM\s+`?ceksenet_(hareketler|kayitlar)/i', $c));
$setler = function (string $tablo) use ($c): array { preg_match_all('/UPDATE\s+`?' . $tablo . '`?\s+SET\s+(.+?)\s+WHERE/i', $c, $um); return array_values(array_unique(array_map(fn($x) => preg_replace('/\s+/', ' ', trim($x)), $um[1]))); };
$beyazK = ['portfoy_no = ?, bordro_no = ?', 'durum = ?, yer_cari_id = ?, yer_kasa_id = ?, updated_at = ?, updated_by = ?'];
$sk = $setler('ceksenet_kayitlar');
ok('core: ceksenet_kayitlar UPDATE\'leri YALNIZ beyaz liste (2 kalıp, tam metin) ve ikisi de var', count($sk) === 2 && !array_diff($sk, $beyazK) && !array_diff($beyazK, $sk), json_encode($sk));
ok('core: kayıt UPDATE\'inde tutar|vade|cari_id|para_birimi|seri_no|banka_ad|sinif|yon SET edilmez (evrakın kimliği değişmez)', !preg_match('/UPDATE\s+ceksenet_kayitlar\s+SET[^;]*\b(tutar|vade|cari_id|para_birimi|seri_no|banka_ad|sinif|yon)\s*=/i', $c));
$beyazH = ['belge_no = ?, bordro_no = ?, cari_hareket_id = ?, cari_hareket2_id = ?, kasa_hareket_id = ?', "durum = 'iptal', iptal_at = ?, iptal_by = ?, iptal_neden = ?"];
$sh = $setler('ceksenet_hareketler');
ok('core: ceksenet_hareketler UPDATE\'leri YALNIZ beyaz liste (2 kalıp, tam metin) ve ikisi de var', count($sh) === 2 && !array_diff($sh, $beyazH) && !array_diff($beyazH, $sh), json_encode($sh));
ok("core: hareket iptal UPDATE'i yalnız durum='gecerli' satırlara ve tek yerde (ceksenet_hareket_iptal)", substr_count($c, "iptal_neden = ? WHERE id = ? AND durum = 'gecerli'") === 1 && str_contains((string)govde($c, 'ceksenet_hareket_iptal'), "iptal_neden = ? WHERE id = ? AND durum = 'gecerli'"));
ok('core: INSERT INTO ceksenet_hareketler tam 2 kez (bordro_kaydet + islem_uygula); INSERT INTO ceksenet_kayitlar tek kez (bordro_kaydet)', substr_count($c, 'INSERT INTO ceksenet_hareketler') === 2 && str_contains((string)govde($c, 'ceksenet_bordro_kaydet'), 'INSERT INTO ceksenet_hareketler')
    && str_contains((string)govde($c, 'ceksenet_islem_uygula'), 'INSERT INTO ceksenet_hareketler') && substr_count($c, 'INSERT INTO ceksenet_kayitlar') === 1 && str_contains((string)govde($c, 'ceksenet_bordro_kaydet'), 'INSERT INTO ceksenet_kayitlar'));
ok('core: DELETE yalnız ceksenet_tanimlar (ceksenet_tanim_sil, kullanılmayan)', preg_match_all('/DELETE\s+FROM\s+`?(\w+)/i', $c, $dm) === 1 && $dm[1] === ['ceksenet_tanimlar'] && str_contains((string)govde($c, 'ceksenet_tanim_sil'), 'DELETE FROM ceksenet_tanimlar'));
ok('core: ceksenet_tanim_sil kullanım sayısına bakar DELETE\'ten ÖNCE', $pos((string)govde($c, 'ceksenet_tanim_sil'), 'COUNT(*)') > 0 && $pos((string)govde($c, 'ceksenet_tanim_sil'), 'COUNT(*)') < $pos((string)govde($c, 'ceksenet_tanim_sil'), 'DELETE FROM'));
$cariKilit = "SELECT id FROM cari_kartlar WHERE id = ? FOR UPDATE";   // TEK istisna: bordro tx'inin başında cari satırı kilidi (yazmaz; cari_core'da kilit fonksiyonu yok)
ok('core: cari_* ve kasa_* TABLOLARINA hiç SQL yok (okuma dahil; yalnız cari_core / kasa_core fonksiyonları) — tek istisna: bordro cari satırı FOR UPDATE kilidi', substr_count($c, $cariKilit) === 1 && str_contains((string)govde($c, 'ceksenet_bordro_kaydet'), $cariKilit)
    && !preg_match('/\b(FROM|INTO|UPDATE|JOIN)\s+`?(cari|kasa)_(hareketler|kartlar|tanimlar)\b/i', str_replace($cariKilit, '', $c)));
ok('core: named parametre (:ad) kullanılmaz — pozisyonel ?', !preg_match('/[ (,=]:[a-z_]{2,}\b/', $c));
ok('core: depo filtresi yalnız depo_sql_in (ceksenet_depo_filtre) / depot_visible_to_user / active_depot (depo_sql_column / depo_sql_records YOK)', str_contains($c, 'depo_sql_in(') && str_contains($c, 'depot_visible_to_user(') && !str_contains($c, 'depo_sql_column(') && !str_contains($c, 'depo_sql_records'));
$yazanlar = ['ceksenet_bordro_kaydet', 'ceksenet_islem_uygula', 'ceksenet_hareket_iptal', 'ceksenet_tanim_kaydet', 'ceksenet_tanim_durum', 'ceksenet_tanim_sil'];
foreach ($yazanlar as $fn) ok("core $fn: audit_log_event(…, 'ceksenet', …) var", ($g = govde($c, $fn)) !== null && (bool)preg_match("/audit_log_event\\([^;]*?, 'ceksenet', /", $g));
$yetkili = [];
foreach ([...$yazanlar, 'ceksenet_bordro_dogrula', 'ceksenet_dekont_yaz', 'ceksenet_kasa_coz', 'ceksenet_tanim_dogrula'] as $fn) if (preg_match('/\b(can|is_admin|require_perm|ceksenet_can|ceksenet_kapi|cari_can|cari_kapi|kasa_can|kasa_kapi)\s*\(/', (string)govde($c, $fn))) $yetkili[] = $fn;
ok('core: yazan / doğrulayan fonksiyonlarda yetki kontrolü YOK (uçların işi)', !$yetkili, implode(',', $yetkili));
ok('core: ceksenet_can yalnız is_admin() (tek değişim noktası; izin kataloğuna bağlanmadı)', ($g = govde($c, 'ceksenet_can')) !== null && str_contains($g, 'is_admin()') && !preg_match('/\bcan\(/', $g));
foreach (['ceksenet_portfoy_no', 'ceksenet_bordro_no', 'ceksenet_belge_no', 'ceksenet_para_db', 'ceksenet_tarih_coz', 'ceksenet_vade_coz', 'ceksenet_onay', 'ceksenet_geri_guvenli', 'ceksenet_siniflar', 'ceksenet_durumlar', 'ceksenet_islemler', 'ceksenet_izinli_islemler', 'ceksenet_ortalama_vade', 'ceksenet_durum_etiketi'] as $fn) {
    ok("core $fn SAF (PDO/sorgu/audit/\$GLOBALS YOK)", ($g = govde($c, $fn)) !== null && !preg_match('/PDO|->|audit_log_event|\$GLOBALS/', $g));
}
ok('core: para kuruş TAM SAYI — ortalama vade tam sayı aritmetiği (intdiv), float bölme yok', str_contains((string)govde($c, 'ceksenet_ortalama_vade'), 'intdiv(') && !preg_match('/round\(/', (string)govde($c, 'ceksenet_ortalama_vade')));

echo "\n=== 5. Zorunlu imzalar ve yazma sırası ===\n";
foreach ([
    'function ceksenet_bordro_kaydet(PDO $pdo, array $g, int $uid): array',
    'function ceksenet_islem_uygula(PDO $pdo, array $g, int $uid): array',
    'function ceksenet_hareket_iptal(PDO $pdo, int $hid, string $neden, int $uid): array',
    'function ceksenet_oku(PDO $pdo, int $id): ?array',
    'function ceksenet_listesi(PDO $pdo, string $sinif, array $f = [], int $limit = 100, int $offset = 0): array',
    'function ceksenet_ozet(PDO $pdo, string $sinif, ?string $yon = null, ?string $bugun = null): array',
    'function ceksenet_ortalama_vade(array $satirlar, string $bazTarih): ?array',
    'function ceksenet_izinli_islemler(array $kayit): array',
    'function ceksenet_karne_siradaki(PDO $pdo, int $karneId): ?array',
    'function ceksenet_tanim_listesi(PDO $pdo, string $tur, bool $yalnizAktif = false): array',
    'function ceksenet_tanim_kaydet(PDO $pdo, array $g, int $uid, ?int $id = null): array', 'function ceksenet_tanim_durum(PDO $pdo, int $id, bool $aktif, int $uid): array', 'function ceksenet_tanim_sil(PDO $pdo, int $id, int $uid): array',
    'function ceksenet_tablolar(): array', 'function ceksenet_hazir(PDO $pdo): bool', 'function ceksenet_migrate(PDO $pdo): array', 'function ceksenet_sayfa_kapisi(PDO $pdo): void', 'function ceksenet_can(string $eylem): bool', 'function ceksenet_kapi(string $eylem): void',
    'function ceksenet_geri_guvenli(mixed $g): string', 'function ceksenet_cevap(array $s, string $geri, string $okMesaj, bool $json): never', 'function ceksenet_islemler(): array', 'function ceksenet_siniflar(): array', 'function ceksenet_durumlar(): array',
] as $imza) ok('imza SABİT (ön yüz buna dayanır): ' . substr($imza, 9, strpos($imza, '(') - 9), str_contains($c, $imza));
ok('CEKSENET_SATIR_MAX = 30; CEKSENET_IC_HATA sabiti', str_contains($c, 'const CEKSENET_SATIR_MAX = 30;') && str_contains($c, 'const CEKSENET_IC_HATA = '));
$bk = (string)govde($c, 'ceksenet_bordro_kaydet'); $iu = (string)govde($c, 'ceksenet_islem_uygula'); $hi = (string)govde($c, 'ceksenet_hareket_iptal'); $dy = (string)govde($c, 'ceksenet_dekont_yaz');
ok("cari bağı: cari_hareket_ekle( TEK çağrı (ceksenet_dekont_yaz içinde) + ['modul' => 'ceksenet', 'id' => \$hid]; cari tabloya SQL yok", substr_count($c, 'cari_hareket_ekle(') === 1 && str_contains($dy, "\$uid, ['modul' => 'ceksenet', 'id' => \$hid]);") && str_contains($dy, "'alacak_dekont'") && str_contains($dy, "'borc_dekont'"));
ok("kasa bağı: kasa_hareket_ekle( TEK çağrı (islem_uygula) + ['modul' => 'ceksenet', 'id' => \$hid]; tur kaynak_giris | kaynak_cikis", substr_count($c, 'kasa_hareket_ekle(') === 1 && str_contains($iu, "\$uid, ['modul' => 'ceksenet', 'id' => \$hid]);") && str_contains($iu, "'kaynak_giris'") && str_contains($iu, "'kaynak_cikis'"));
ok('iç hata (cari / kasa !ok) → RuntimeException(CEKSENET_IC_HATA); catch kodu ayırır (bordro + işlem + iptal)', substr_count($c, '$e->getCode() !== CEKSENET_IC_HATA') === 3 && str_contains($iu, "if (empty(\$kr['ok']))") && str_contains($bk, 'CEKSENET_IC_HATA)'));
ok('hareket önce INSERT, sonra cari / kasa (kaynak id = hareket id), son olarak kayıt UPDATE; bordro no sonda', $sirali([$pos($iu, '$harIns->execute('), $pos($iu, 'ceksenet_dekont_yaz('), $pos($iu, 'kasa_hareket_ekle('), $pos($iu, '$upK->execute('), $pos($iu, '$upH->execute(')]));
ok('işlem_uygula: kayıtlar kilitli okunur (FOR UPDATE) → izin kontrolü → kasa çözümü → yazma; toplu: yazmadan ÖNCE tüm kayıtlar doğrulanır (gecersiz_gecis)', $sirali([$pos($iu, 'ceksenet_kayitlari_kilitle_oku('), $pos($iu, "'kod' => 'gecersiz_gecis'"), $pos($iu, 'ceksenet_kasa_coz('), $pos($iu, 'INSERT INTO ceksenet_hareketler')]) && str_contains((string)govde($c, 'ceksenet_kayitlari_kilitle_oku'), 'FOR UPDATE'));
ok('işlem_uygula: audit commit SONRASI (ceksenet_tx bloğundan sonra); bordro_kaydet aynı', $pos($iu, "audit_log_event('update', 'ceksenet'") > $pos($iu, '} catch (PDOException $e)') && $pos($bk, "audit_log_event('create', 'ceksenet'") > $pos($bk, '} catch (PDOException $e)'));
ok('hareket_iptal: yalnız SON geçerli hareket (son_degil) → hareket UPDATE → cari_kaynakli_iptal(\$pdo, \'ceksenet\', \$hid → kasa_kaynakli_iptal(…\'ceksenet\', \$hid → kayıt UPDATE', $sirali([$pos($hi, "'kod' => 'son_degil'"), $pos($hi, "SET durum = 'iptal'"), $pos($hi, "cari_kaynakli_iptal(\$pdo, 'ceksenet', \$hid"), $pos($hi, "kasa_kaynakli_iptal(\$pdo, 'ceksenet', \$hid"), $pos($hi, 'UPDATE ceksenet_kayitlar SET durum')])
    && substr_count($c, 'cari_kaynakli_iptal(') === 1 && substr_count($c, 'kasa_kaynakli_iptal(') === 1);
ok("hareket_iptal: giriş/çıkış iptali kayıt durumu 'iptal'; diğerlerinde onceki_durum + onceki_yer_* geri yazılır", str_contains($hi, "in_array((string)\$h['islem'], ['giris', 'cikis'], true)") && str_contains($hi, "\$h['onceki_yer_cari_id']") && str_contains($hi, "\$h['onceki_yer_kasa_id']") && str_contains($hi, "(string)\$h['onceki_durum']"));
ok('ceksenet_cevap: 409 kodları (yetersiz_bakiye, mukerrer, gecersiz_gecis, son_degil, kullanimda); sunucu_hatasi → 500', str_contains((string)govde($c, 'ceksenet_cevap'), "['yetersiz_bakiye', 'mukerrer', 'gecersiz_gecis', 'son_degil', 'kullanimda']") && str_contains((string)govde($c, 'ceksenet_cevap'), "'sunucu_hatasi') http_response_code(500)"));
ok('ceksenet_core: cari_core + kasa_core require_once; stok/fatura require YOK', str_contains($c, "require_once __DIR__ . '/cari_core.php';") && str_contains($c, "require_once __DIR__ . '/kasa_core.php';") && !preg_match("/require(_once)?\s+__DIR__\s*\.\s*'\/(stok|fatura)/", $c) && !preg_match('/\b(stok|fatura)_[a-z_]+\(/', $c));
ok('işlem tablosu (ceksenet_islemler) durum makinesinin TEK kaynağı: core\'da başka yerde durum adı karşılaştırma zinciri yok', substr_count($c, "'tahsil_banka'") <= 3);

echo "\n=== 6. Bağımlılık yönü ===\n";
foreach (['config/cari_core.php', 'config/stok_core.php', 'config/fatura_core.php', 'config/kasa_core.php', 'config/cari_ui.php', 'config/stok_ui.php', 'config/fatura_ui.php', 'config/kasa_ui.php', 'stok_kaydet.php', 'cari_kaydet.php', 'fatura_kaydet.php', 'kasa_kaydet.php'] as $f) {
    if (!is_file($ROOT . '/' . $f)) continue;
    ok("$f çek-senet modülünü bilmez ('ceksenet' / 'cek_kaydet' sözcüğü GEÇMEZ)", stripos((string)file_get_contents($ROOT . '/' . $f), 'ceksenet') === false && !str_contains((string)file_get_contents($ROOT . '/' . $f), 'cek_kaydet'));
}

echo "\n=== 7. Şema yalnız migrate.php'den ===\n";
$gecerli = [];
foreach (glob($ROOT . '/*.php') as $f) if (preg_match('/\bceksenet_migrate\s*\(/', kod($f))) $gecerli[] = basename($f);
ok('ceksenet_migrate( tek çağıran migrate.php (kök sayfalar)', $gecerli === ['migrate.php'], json_encode($gecerli));
$db = (string)file_get_contents($ROOT . '/config/db.php');
ok('config/db.php\'de ceksenet_ CREATE yok (otomatik migrasyon YOK)', !str_contains($db, 'ceksenet_'));
ok('çek-senet tablolarında FOREIGN KEY / ALTER / ENUM / JSON / ON UPDATE / GENERATED yok', ($g = govde($c, 'ceksenet_tablolar')) !== null && !preg_match('/FOREIGN\s+KEY|ALTER\s+TABLE|ENUM\(|\bJSON\b|ON\s+UPDATE|GENERATED/i', $g));
ok('ceksenet_hareketler: istek_anahtari UNIQUE + (kayit_id, durum, id) + bordro indeksleri; kayıtlarda liste / cari / seri / bordro indeksleri; tanımlarda (tur, ad) UNIQUE',
    str_contains($c, 'UNIQUE KEY `uq_csh_istek` (`istek_anahtari`)') && str_contains($c, 'KEY `idx_csh_kayit` (`kayit_id`, `durum`, `id`)') && str_contains($c, 'KEY `idx_csh_bordro` (`bordro_no`)')
    && str_contains($c, 'KEY `idx_cs_liste` (`sinif`, `yon`, `durum`, `vade`)') && str_contains($c, 'KEY `idx_cs_cari` (`cari_id`)') && str_contains($c, 'UNIQUE KEY `uq_cst_tur_ad` (`tur`, `ad`)'));
ok('ceksenet_kayitlar tutar DECIMAL(14,2); tohum yalnız ceksenet_tanimlar YENİ kurulduysa', str_contains($c, '`tutar`            DECIMAL(14,2)') && str_contains((string)govde($c, 'ceksenet_migrate'), "\$ad === 'ceksenet_tanimlar' && (int)\$pdo->query('SELECT COUNT(*) FROM `ceksenet_tanimlar`')->fetchColumn() === 0"));
$mg = (string)file_get_contents($ROOT . '/migrate.php');
ok('migrate.php: require ceksenet_core + mg_kart_ac(\'ceksenet\') + ne=ceksenet dalı (csrf + ceksenet_migrate + audit migrate)', str_contains($mg, 'config/ceksenet_core.php') && str_contains($mg, "mg_kart_ac('ceksenet')")
    && (bool)preg_match("/=== 'ceksenet'\)\s*\{\s*\n\s*csrf_check[^}]*ceksenet_migrate\(\\\$pdo\)[^}]*audit_log_event\('migrate', 'ceksenet'/s", $mg));
ok('migrate.php: ceksenet kartı kasa kartından SONRA; ✓/✗ durum + form ne=ceksenet + cari / kasa önkoşul uyarısı', $pos($mg, "mg_kart_ac('kasa')") > 0 && $pos($mg, "mg_kart_ac('ceksenet')") > $pos($mg, "mg_kart_ac('kasa')")
    && str_contains($mg, "\$csHazir ? '✓ Tablolar var' : '✗ Tablolar eksik'") && str_contains($mg, 'name="ne" value="ceksenet"') && str_contains($mg, "cari_hazir(\$pdo) ? null : 'Cari İşlemler'") && str_contains($mg, "kasa_hazir(\$pdo) ? null : 'Kasa'"));

echo "\n=== 8. Aşama 4 parçaları (varsa) ===\n";
if (is_file($ROOT . '/config/ceksenet_ui.php')) {
    $ui = kod($ROOT . '/config/ceksenet_ui.php');
    ok('config/ceksenet_ui.php: SORGU / yazma YOK, $_POST yok', !preg_match('/->(prepare|query|exec)\(/', $ui) && !preg_match($SQL_YAZ, $ui) && !str_contains($ui, '$_POST'));
}
if (is_file($ROOT . '/assets/ceksenet.js')) {
    $js = (string)file_get_contents($ROOT . '/assets/ceksenet.js');
    ok('ceksenet.js: yazmalar yalnız cek_kaydet.php; localStorage YOK; innerHTML en çok 1 (htmlParca)', str_contains($js, 'cek_kaydet.php') && !str_contains($js, 'localStorage') && substr_count($js, 'innerHTML') <= 1 && !str_contains($js, 'parseFloat'));
}
if (is_file($ROOT . '/assets/ceksenet.css')) {
    $cssKod = preg_replace('#/\*.*?\*/#s', '', (string)file_get_contents($ROOT . '/assets/ceksenet.css')) ?? '';
    ok('ceksenet.css: çıplak html / body / input / .btn / .card seçicisi YOK; overflow-x: hidden YOK', !preg_match('/(^|[},]\s*)(html|body|input|\.btn|\.card)\s*[{,]/m', $cssKod) && !preg_match('/overflow-x\s*:\s*hidden/', $cssKod));
}
ok('style.css\'te .cs- yok; app.js\'te cek_kaydet yok (ortak dosyalara dokunulmadı)', !str_contains((string)file_get_contents($ROOT . '/assets/style.css'), '.cs-') && !str_contains((string)file_get_contents($ROOT . '/assets/app.js'), 'cek_kaydet'));


echo "\n=== 9. Aşama 4 (ön yüz) — dosyalar zorunlu + UI kuralları ===\n";
foreach (['config/ceksenet_ui.php', 'assets/ceksenet.css', 'assets/ceksenet.js', 'cek_cikti.php', 'cek_dekontu.php', 'senet_dekontu.php', 'cek_tanimlari.php'] as $f) ok("Aşama 4: $f var", is_file($ROOT . '/' . $f));
$ui = kod($ROOT . '/config/ceksenet_ui.php');
$uiHam = (string)file_get_contents($ROOT . '/config/ceksenet_ui.php');
$uiFn = ['ceksenet_assets', 'ceksenet_scripts', 'ceksenet_sema_uyari', 'ceksenet_parca', 'ceksenet_sayfa_govde', 'ceksenet_tanimlari_govde', 'ceksenet_bordro_yazdir_html', 'ceksenet_modal_islem', 'ceksenet_bordro_form_html'];
ok('ceksenet_ui: ' . implode('/', $uiFn) . ' var', !array_diff($uiFn, array_map(fn($fn) => govde($ui, $fn) !== null ? $fn : '', $uiFn)));
ok('ceksenet_ui: require YOK (yalnız fonksiyon bildirir; çekirdekler sayfa dosyasından yüklenir)', !preg_match('/\brequire(_once)?\b/', $ui) && !str_contains($ui, 'include'));
ok('ceksenet_ui: cari / kasa tablolarına SQL yok; veri yalnız cari_core / kasa_core / ceksenet_core fonksiyonlarından', !preg_match('/\b(FROM|JOIN|INTO|UPDATE)\s+`?(cari|kasa|ceksenet)_/i', $ui) && str_contains($ui, 'cari_kart_listesi(') && str_contains($ui, 'kasa_secenekleri('));
ok('ceksenet_ui: cek_kaydet.php dışında yazma ucu yok; her POST formunda csrf', substr_count($uiHam, 'method="post"') === substr_count($uiHam, 'action="cek_kaydet.php"') && substr_count($uiHam, 'name="csrf"') >= substr_count($uiHam, 'method="post"'));
ok('ceksenet_ui: ham $_GET çıktısı yok; işlem modalı alanları ceksenet_islemler() yapısından (ceksenet_islem_meta)', !preg_match('/(echo|\.)\s*\$_(GET|POST|REQUEST)\[/', $ui) && str_contains((string)govde($ui, 'ceksenet_islem_meta'), 'ceksenet_islemler()') && str_contains($ui, "'islemler' => ceksenet_islem_meta()"));
ok('ceksenet_ui: bordro formu paralel dizi adları ceksenet_satir_topla ile aynı 12 alan (seri_no … karne_id)', (bool)preg_match('/\$tum = \[\'seri_no\', \'banka_ad\', \'sube\', \'hesap_no\', \'borclu\', \'kefil\', \'yer_adi\', \'duzenleme_tarihi\', \'vade\', \'tutar\', \'para_birimi\', \'karne_id\'\];/', $ui));
ok('ceksenet_ui: satır girdileri disabled EDİLMEZ (paralel dizi hizası; yalnız readonly / hidden)', !preg_match('/<(input|select)[^>]*name="[a-z_]+\[\]"[^>]*\bdisabled\b/', $ui));
ok('ceksenet_ui: durum rozeti `cs-d-*` sınıfı çekirdekten (durum_sinif); vade geçmiş / yakın sınıfları var', str_contains($ui, "durum_sinif") && str_contains($ui, 'cs-vade--gecmis') && str_contains($ui, 'cs-vade--yakin'));
$ct = kod($ROOT . '/cek_cikti.php');
ok("cek_cikti: dışa aktarım require_perm('reports.export') + export_audit('ceksenet', 'liste', …) + xlsx_indir; siparis_inv_ihracatci tek kaynak", str_contains($ct, "require_perm('reports.export')") && str_contains($ct, "export_audit('ceksenet', 'liste',") && str_contains($ct, 'xlsx_indir(') && str_contains($ct, 'siparis_inv_ihracatci()'));
ok('cek_cikti: require_perm → export_audit → xlsx_indir sırası; CSV `;` + BOM', $pos($ct, "require_perm('reports.export')") < $pos($ct, "export_audit('ceksenet'") && $pos($ct, "export_audit('ceksenet'") < $pos($ct, 'xlsx_indir(') && str_contains($ct, '\xEF\xBB\xBF') && str_contains($ct, "';', '\"', '\\\\'"));
$js = (string)file_get_contents($ROOT . '/assets/ceksenet.js'); $cssH = (string)file_get_contents($ROOT . '/assets/ceksenet.css');
ok("ceksenet.js: yonRe = ceksenet_geri_guvenli ile aynı üç sayfa; yazma yalnız cek_kaydet.php; Math.random yalnız crypto yedeği", str_contains($js, 'yonRe: /^(cek_dekontu|senet_dekontu|cek_tanimlari)\.php') && str_contains($js, "kaydet: 'cek_kaydet.php'") && substr_count($js, 'Math.random') === 2 && !str_contains($js, 'XMLHttpRequest'));
ok('ceksenet.js: işlem geçişi `gecis` alanıyla gönderilir (`islem` yönlendirme anahtarı)', str_contains($js, 'f.elements.gecis.value') && str_contains((string)govde($ui, 'ceksenet_modal_islem'), 'name="gecis"'));
ok('ceksenet.css: <768 girdi 16px + --bn-h ile alt çubuk payı + safe-area (sabit px yok); karanlık mod tokenları; durum rozetleri cs-d-*', str_contains($cssH, 'font-size: 16px') && str_contains($cssH, 'var(--bn-h)') && str_contains($cssH, 'env(safe-area-inset-bottom') && str_contains($cssH, 'html[data-theme="dark"] .ceksenet') && str_contains($cssH, '.cs-d-karsiliksiz'));
ok('ceksenet.css: alt eylem / seçim çubuğuna z-index VERİLMEZ; panel + modal ≥ 600', !preg_match('/\.cs-(eylem-bar|secim-bar)\s*\{[^}]*z-index/', $cssH) && (bool)preg_match('/\.cs-panel--acik\s*\{[^}]*z-index:\s*600/', $cssH));
ok('ceksenet.css: .pm-dialog > form zincirine dokunulmaz; .cs-dialog max-height tanımlı', str_contains($cssH, '.cs-dialog {') && !str_contains(preg_replace('#/\*.*?\*/#s', '', $cssH) ?? '', '.pm-dialog > form'));
ok('bağımlılık yönü: kasa_ui / kasa_core "ceksenet" sözcüğünü bilmez', !is_file($ROOT . '/config/kasa_ui.php') || stripos((string)file_get_contents($ROOT . '/config/kasa_ui.php'), 'ceksenet') === false);

echo "\n$pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
