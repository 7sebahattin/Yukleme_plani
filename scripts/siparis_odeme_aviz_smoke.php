<?php
// =========================================================
// scripts/siparis_odeme_aviz_smoke.php — "Ödeme sorgula" (v490): OGL ödeme avizosu → Ödemeler
//   php scripts/siparis_odeme_aviz_smoke.php              → ağsız, tüm veri SENTETİK (fixture: scripts/fixtures/siparis/odeme_avizosu_pages.json)
//   ODEME_ORNEK_PDF=<gerçek avizo.pdf> php …              → ek olarak gerçek PDF ile uçtan uca (dosya repoda YOKTUR; env yoksa ATLANIR)
// Birleşik harness (_siparis_mail_test_lib.php): bellek içi SQLite + sahte IMAP + GERÇEK config/siparis_mail.php, siparis_odeme_aviz.php, siparis_odeme.php.
// A no_coz · B istek_id · C fatura eşlemesi · D Mailden al akışı · E defter tuzakları · F tekrar/iptal/değer koruması · G yetki · H UI HTML · I gerçek PDF
// =========================================================
require_once __DIR__ . '/_siparis_mail_test_lib.php';

ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', ''); session_start();   // csrf_token() (blok render)
$db = db();
smt_kur($db);
smt_parse_kancasi_kur();
$IS_ADMIN = true; $PERMS = ['reports.export']; $UID = 1; $AKTIF_DEPO = 'DEPO1';
$SIMDI = '2026-11-02 12:00:00';
$denetle = static fn(string $s): int => (int)$db->query($s)->fetchColumn();

echo "=== A. OP-Nummer çözümü ===\n";
$tablo = [
    ['26-IHR2026000000339', 'ana', 'IHR2026000000339', ''], ['26/IHR2026000000170', 'ana', 'IHR2026000000170', ''], ['IHR2026000000339', 'ana', 'IHR2026000000339', ''],
    ['27-IHR2026000000339', 'ana', 'IHR2026000000339', ''], ['IHR2026000000339DI', 'duzeltme', 'IHR2026000000339', 'DI'], ['IHR2026000000164DV', 'duzeltme', 'IHR2026000000164', 'DV'],
    ['IHR2026000000164DXI', 'duzeltme', 'IHR2026000000164', 'DXI'], ['2027-138760', 'ogl_ic', null, ''], ['2026-3057RO', 'ro', null, ''], ['2026-1000045718BG', 'bg', null, ''],
    ['IHR2026000000164DA', 'diger', null, ''], ['IHR20260000003', 'diger', null, ''], ['26-IHR2026000000339X', 'diger', null, ''], ['ABC-1', 'diger', null, ''],
];
$hatali = [];
foreach ($tablo as [$no, $tur, $govde, $sonek]) {
    $c = siparis_odeme_aviz_no_coz($no);
    if ($c['tur'] !== $tur || $c['govde'] !== $govde || $c['sonek'] !== $sonek) $hatali[] = $no . ' → ' . json_encode($c);
}
ok('no_coz: 14 örnek (ana/düzeltme/ogl_ic/ro/bg/diger; IHR+13 hane şart, D+Roma)', $hatali === [], implode(' | ', $hatali));

echo "\n=== B. istek_id ===\n";
$base = smt_odeme_doc('odeme1');
ok('taban avizo ayrıştı (15 kalem, 553775)', ($base['ok'] ?? false) === true && count($base['rows']) === 15 && $base['total']['amount_kurus'] === 553775);
$i0 = siparis_odeme_aviz_istek_id($base);
ok('istek_id biçimi avz-<32 hex> (36 karakter)', strlen($i0) === 36 && (bool)preg_match('/^avz-[0-9a-f]{32}$/', $i0));
$kar = $base; shuffle($kar['rows']);
ok('satır sırası karışık → AYNI istek_id', siparis_odeme_aviz_istek_id($kar) === $i0);
$b2 = $base; $b2['_file'] = 'baska.pdf'; $b2['meta'] = ['x' => 1];
ok('dosya adı / meta / bayt farkı → AYNI istek_id (dosya sha\'sına bağlı değil)', siparis_odeme_aviz_istek_id($b2) === $i0);
$b3 = $base; $b3['header']['date'] = '2026-10-27';
$b4 = $base; $b4['total']['amount_kurus'] += 1;
$b5 = $base; $b5['rows'][3]['zahlung_kurus'] += 1;
ok('tarih / toplam / bir kuruş farkı → FARKLI istek_id', siparis_odeme_aviz_istek_id($b3) !== $i0 && siparis_odeme_aviz_istek_id($b4) !== $i0 && siparis_odeme_aviz_istek_id($b5) !== $i0);

echo "\n=== C. Fatura eşlemesi (yalnız bilgi) ===\n";
function od_sev(PDO $db, string $ref, string $depo = 'DEPO1'): int
{
    $db->prepare("INSERT INTO siparis_sevkiyatlar (tedarikci_no, order_ref, durum, depo, var_po) VALUES ('', ?, 'mail_gonderildi', ?, 1)")->execute([$ref, $depo]);
    return (int)$db->lastInsertId();
}
/** Üretilmiş invoice: belge + bağ + hks_kv girdisi. $tk null → parse_json'da toplam_kurus yok. */
function od_inv(PDO $db, int $sid, string $no, int $belge, ?int $tk, ?string $silindi = null): void
{
    $pj = $tk === null ? json_encode(['invoice_no' => $no]) : json_encode(['invoice_no' => $no, 'toplam_kurus' => $tk]);
    $db->prepare("INSERT INTO siparis_belgeler (id, tur, kaynak, durum, sha256, disk_adi, orijinal_ad, mime, parse_json, deleted_at) VALUES (?, 'invoice', 'uretim', 'islendi', ?, ?, ?, 'application/pdf', ?, ?)")
        ->execute([$belge, str_pad((string)$belge, 64, '0', STR_PAD_LEFT), 'd' . $belge . '.pdf', 'INV-' . $belge . '.pdf', $pj, $silindi]);
    $db->prepare('INSERT INTO siparis_belge_bag (belge_id, sevkiyat_id, gecerli) VALUES (?, ?, 1)')->execute([$belge, $sid]);
    $db->prepare('REPLACE INTO hks_kv (anahtar, deger) VALUES (?, ?)')->execute(['siparis_invoice_' . $sid, json_encode(['no' => $no, 'tarih' => '2026-10-02', 'fiyat' => '1.37', 'iskonto' => '0', 'uretildi_at' => '2026-10-03 10:00:00', 'belge_id' => $belge])]);
}
$S1 = od_sev($db, 'TC900011.10'); od_inv($db, $S1, 'IHR2026000009001', 101, 100000);
$S2 = od_sev($db, 'TC900012.10'); od_inv($db, $S2, 'IHR2026000009002', 102, 250000);
$S3 = od_sev($db, 'TC900013.10'); od_inv($db, $S3, 'IHR2026000009003', 103, null);
$S4 = od_sev($db, 'TC900014.10'); od_inv($db, $S4, 'IHR2026000009004', 104, 7500);
$S5 = od_sev($db, 'TC900015.10'); od_inv($db, $S5, 'IHR2026000009006', 105, 30000);
$S6 = od_sev($db, 'TC900016.10'); od_inv($db, $S6, 'IHR2026000009006', 106, 30000);        // aynı fatura no iki sevkiyatta → belirsiz
$S7 = od_sev($db, 'TC900017.10'); od_inv($db, $S7, 'IHR2026000009007', 107, 90000, '2026-10-05 10:00:00');   // belge silinmiş → eşleşmez
$S8 = od_sev($db, 'TC900018.10'); od_inv($db, $S8, 'IHR2026000009001DI', 108, 5000);       // düzeltme no'lu girdi ana fatura adayı DEĞİL
$db->prepare('REPLACE INTO hks_kv (anahtar, deger) VALUES (?, ?)')->execute(['siparis_invoice_ayar', json_encode(['no' => 'IHR2026000009005'])]);   // ayar anahtarı elenir
$esl = siparis_odeme_aviz_esle($db, $base['rows'], 'EUR');
$by = []; foreach ($esl['satirlar'] as $r) $by[$r['n']] = $r;
ok('özet: kalem 15, fatura 7, eşleşen 4, eşleşmeyen 2, belirsiz 1, tutar farkı 1, diğer 4', $esl['ozet'] === ['kalem' => 15, 'fatura' => 7, 'eslesen' => 4, 'eslesmeyen' => 2, 'belirsiz' => 1, 'tutar_fark' => 1, 'diger' => 4, 'esleme_hata' => false], json_encode($esl['ozet']));
ok('9001 → TC900011.10, Rechnung == fatura toplamı (k=ok)', ($by['26-IHR2026000009001']['e'] ?? '') === 'eslesti' && $by['26-IHR2026000009001']['v'][0]['ref'] === 'TC900011.10' && $by['26-IHR2026000009001']['k'] === 'ok');
ok('9002 → k=fark (Rechnung 2.500,50 ≠ fatura 2.500,00)', ($by['26-IHR2026000009002']['k'] ?? '') === 'fark');
ok('9003 → eşleşti, fatura toplamı bilinmiyor (k=?)', ($by['26-IHR2026000009003']['e'] ?? '') === 'eslesti' && ($by['26-IHR2026000009003']['k'] ?? '') === '?');
ok('9004 → yalnız düzeltme satırı var, eşleşti (k yok)', ($by['IHR2026000009004DI']['e'] ?? '') === 'eslesti' && !isset($by['IHR2026000009004DI']['k']));
ok('düzeltme satırı da gövdesiyle eşleşir (9001DI → TC900011.10), 9001DI girdisi ana aday olmadı', ($by['IHR2026000009001DI']['v'][0]['ref'] ?? '') === 'TC900011.10');
ok('9005 (kayıt yok) → eşleşmedi · 9007 (belge silinmiş) → eşleşmedi', ($by['26/IHR2026000009005']['e'] ?? '') === 'eslesmedi' && ($by['26-IHR2026000009007']['e'] ?? '') === 'eslesmedi');
ok('9006 iki sevkiyatta → belirsiz (otomatik atanmaz, 2 aday listelenir)', ($by['26-IHR2026000009006']['e'] ?? '') === 'belirsiz' && count($by['26-IHR2026000009006']['v']) === 2);
ok('ogl_ic / ro / bg satırları eşleme adayı değil (e=yok)', ($by['2027-900001']['e'] ?? '') === 'yok' && ($by['2026-9002RO']['e'] ?? '') === 'yok' && ($by['2026-9003BG']['e'] ?? '') === 'yok');
$esl2 = siparis_odeme_aviz_esle($db, $base['rows'], 'USD');
ok('EUR dışı avizoda eşleme yapılmaz (hepsi eşleşmedi/yok)', $esl2['ozet']['eslesen'] === 0 && $esl2['ozet']['fatura'] === 7);
$db->exec('DROP TABLE hks_kv');
$esl3 = siparis_odeme_aviz_esle($db, $base['rows'], 'EUR');
ok('hks_kv yokken eşleme İSTİSNA atmaz (esleme_hata=true, satırlar eşleşmedi)', $esl3['ozet']['esleme_hata'] === true && $esl3['ozet']['eslesen'] === 0 && count($esl3['satirlar']) === 15);
$db->exec('CREATE TABLE hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)');
// tohum yeniden (tablo yeniden kuruldu)
foreach ([[$S1, 'IHR2026000009001', 101], [$S2, 'IHR2026000009002', 102], [$S3, 'IHR2026000009003', 103], [$S4, 'IHR2026000009004', 104], [$S5, 'IHR2026000009006', 105], [$S6, 'IHR2026000009006', 106], [$S7, 'IHR2026000009007', 107]] as [$sid, $no, $bid]) {
    $db->prepare('REPLACE INTO hks_kv (anahtar, deger) VALUES (?, ?)')->execute(['siparis_invoice_' . $sid, json_encode(['no' => $no, 'tarih' => '2026-10-02', 'fiyat' => '1.37', 'iskonto' => '0', 'uretildi_at' => '2026-10-03 10:00:00', 'belge_id' => $bid])]);
}

echo "\n=== D. Mailden al — Ödeme sorgula ===\n";
$A = smt_hesap('finans@ornek.test'); $MAIL = [$A => 'finans@ornek.test'];
smt_ayar([$A], ['siparis' => ['both@ornek.test'], 'lojistik' => [], 'odeme' => ['muhasebe@ornek.test', 'both@ornek.test']]);
ok('Ödeme düğmesi türü tanımlı ve ayarlı', isset(siparis_mail_turleri()['odeme']) && siparis_mail_ayarli_mi(siparis_mail_ayar_oku($db), 'odeme'));
$mk = static fn(string $etiket, string $zaman, string $from = 'muhasebe@ornek.test'): int => smt_mail($A, $MAIL[$A], $from, [smt_ek('2', 'aviz.pdf', smt_pdf($etiket))], $zaman);
$m = [];
foreach (['odeme1', 'odeme1b', 'po1', 'bilinmiyor1', 'odeme-toplam', 'odeme-gelecek', 'odeme-chf', 'odeme-sifir', 'odeme-gonderen'] as $i => $et) $m[$et] = $mk($et, '2026-10-30 0' . $i . ':00:00');
$belgeOnce = $denetle('SELECT COUNT(*) FROM siparis_belgeler');
smt_sayac_sifirla();
$r = smt_tara('odeme', 1, 'DEPO1', ['simdi' => $SIMDI]);
ok('1. Ödeme taraması: alindi, zaten, baska_tur, taninmadi, okunamadi, hata, okunamadi ×3', smt_durumlar($r) === ['alindi', 'zaten', 'baska_tur', 'taninmadi', 'okunamadi', 'hata', 'okunamadi', 'okunamadi', 'okunamadi'], json_encode(smt_durumlar($r)));
$od = $db->query('SELECT * FROM siparis_odemeler ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
ok('tek ödeme satırı: 2026-10-28, 553775 kuruş, EUR, AVZ-20261028, oluşturan 1', count($od) === 1 && $od[0]['odeme_tarihi'] === '2026-10-28' && (int)$od[0]['tutar_kurus'] === 553775 && $od[0]['para_birimi'] === 'EUR' && $od[0]['dekont_no'] === 'AVZ-20261028' && (int)$od[0]['created_by'] === 1, json_encode($od));
ok('açıklama "OGL ödeme avizosu 28.10.2026 · mailden · 15 kalem" ve istek_id biçimi', $od[0]['aciklama'] === 'OGL ödeme avizosu 28.10.2026 · mailden · 15 kalem' && (bool)preg_match('/^avz-[0-9a-f]{32}$/', (string)$od[0]['istek_id']) && $od[0]['istek_id'] === $i0);
$kv = siparis_odeme_aviz_oku($db, (int)$od[0]['id']);
ok('hks_kv siparis_odeme_aviz_<id>: 15 satır + özet (7 fatura, 4 eşleşti, 2 eşleşmedi, 1 belirsiz) + kaynak mail', is_array($kv) && count($kv['satirlar']) === 15 && $kv['ozet']['fatura'] === 7 && $kv['ozet']['eslesen'] === 4 && $kv['ozet']['eslesmeyen'] === 2 && $kv['ozet']['belirsiz'] === 1
    && $kv['kaynak'] === 'mail' && (int)$kv['mail_id'] === $m['odeme1'] && $kv['toplam_kurus'] === 553775 && $kv['rechnung_kurus'] === 556275 && $kv['bagli_manuel'] === false);
ok('belge satırı AÇILMADI (siparis_belgeler değişmedi, avizo belge değil)', $denetle('SELECT COUNT(*) FROM siparis_belgeler') === $belgeOnce);
ok('audit: siparis_odeme_aviz 1, siparis_odeme_ekle 1 (yalnız sayılar; adres/tutar metni yok)', $denetle("SELECT COUNT(*) FROM audit_log WHERE action = 'siparis_odeme_aviz'") === 1 && $denetle("SELECT COUNT(*) FROM audit_log WHERE action = 'siparis_odeme_ekle'") === 1
    && !str_contains((string)$db->query("SELECT new_values FROM audit_log WHERE action = 'siparis_odeme_aviz'")->fetchColumn(), 'ornek.test'));
$sat = array_column($r['son']['satirlar'], null, 'm');
ok('alindi satırı: odeme.id, eslesen 4 / eslesmeyen 2 / belirsiz 1, tur=odeme', ($sat[$m['odeme1']]['odeme']['id'] ?? 0) === (int)$od[0]['id'] && $sat[$m['odeme1']]['odeme']['eslesen'] === 4 && $sat[$m['odeme1']]['odeme']['eslesmeyen'] === 2 && $sat[$m['odeme1']]['odeme']['belirsiz'] === 1 && $sat[$m['odeme1']]['tur'] === 'odeme');
ok('zaten satırı (aynı içerik, farklı bayt): aynı ödeme, yeni satır yok', ($sat[$m['odeme1b']]['durum'] ?? '') === 'zaten' && ($sat[$m['odeme1b']]['odeme']['id'] ?? 0) === (int)$od[0]['id']);
ok('okunamadi satırları kalıcı mesaj taşır (toplam tutmuyor / CHF / sıfır / gönderen)', str_contains((string)$sat[$m['odeme-toplam']]['hata'], 'tutmuyor') && str_contains((string)$sat[$m['odeme-chf']]['hata'], 'CHF') && str_contains((string)$sat[$m['odeme-sifir']]['hata'], 'sıfır') && str_contains((string)$sat[$m['odeme-gonderen']]['hata'], 'OGL Food Trade'));
ok('gelecek tarihli avizo → hata (geçici, ödeme yazılmadı)', str_contains((string)$sat[$m['odeme-gelecek']]['hata'], 'bugünden ileri') && $denetle('SELECT COUNT(*) FROM siparis_odemeler') === 1);
ok('defter: hata YAZILMADI (yeniden denenir), diğerleri aviz_surum a1 taşır', !isset(siparis_mail_defter($db, [$m['odeme-gelecek']])[$m['odeme-gelecek']]) && (siparis_mail_defter($db, [$m['odeme-toplam']])[$m['odeme-toplam']]['2']['aviz_surum'] ?? '') === SIPARIS_ODEME_AVIZO_SURUM);
ok('sayaç: okunamadi/baska_tur/taninmadi "atlandi" sayılır, hata 1, alindi 1, zaten 1', $r['son']['sayac']['alindi'] === 1 && $r['son']['sayac']['zaten'] === 1 && $r['son']['sayac']['hata'] === 1 && $r['son']['sayac']['atlandi'] === 6, json_encode($r['son']['sayac']));
smt_sayac_sifirla();
$r = smt_tara('odeme', 1, 'DEPO1', ['simdi' => $SIMDI]);
global $SMT_BAGLANTI;
ok('2. Ödeme taraması: önceden çözülenler (onceden ×2, baska_tur, taninmadi, okunamadi ×4) + yalnız hata satırı yeniden indirilir (1 bağlantı)', smt_durumlar($r) === ['onceden', 'onceden', 'baska_tur', 'taninmadi', 'okunamadi', 'okunamadi', 'okunamadi', 'okunamadi', 'hata'] && $SMT_BAGLANTI === 1, json_encode(smt_durumlar($r)) . " bağlantı=$SMT_BAGLANTI");

echo "\n=== E. Defter tuzakları ===\n";
// E1: yalnız Sipariş taramasının gördüğü avizo → baska_tur(odeme), belge satırı yok; sonra Ödeme taramasında alınır
$eA = $mk('odeme-e', '2026-10-31 08:00:00', 'both@ornek.test');
$bo = $denetle('SELECT COUNT(*) FROM siparis_belgeler'); $oo = $denetle('SELECT COUNT(*) FROM siparis_odemeler');
$r = smt_tara('siparis', 1, 'DEPO1', ['simdi' => $SIMDI]);
$satA = array_column($r['son']['satirlar'], null, 'm')[$eA] ?? [];
ok('Sipariş taraması avizoyu baska_tur (tur=odeme) yazar; belge satırı ve ödeme AÇILMAZ', smt_durumlar($r) === ['baska_tur'] && ($satA['tur'] ?? '') === 'odeme' && $denetle('SELECT COUNT(*) FROM siparis_belgeler') === $bo && $denetle('SELECT COUNT(*) FROM siparis_odemeler') === $oo, json_encode(smt_durumlar($r)));
ok('baska_tur(odeme) satırı Sipariş kartında «Ödeme sorgula» ipucu verir (ödeme yetkisi var)', str_contains(sipui_mail_durum_html($satA), 'Ödeme sorgula'));
$r = smt_tara('odeme', 1, 'DEPO1', ['simdi' => $SIMDI]);
$satA = array_column($r['son']['satirlar'], null, 'm')[$eA] ?? [];
ok('sonra Ödeme taramasında aynı avizo ALINIR (baska_tur(odeme) Ödeme düğmesi için son değil)', ($satA['durum'] ?? '') === 'alindi' && $denetle('SELECT COUNT(*) FROM siparis_odemeler') === $oo + 1, json_encode($satA['durum'] ?? null));
// E2: eski (aviz_surum yok) `taninmadi` kaydı: Sipariş için hâlâ son, Ödeme için bir kez yeniden indirilir
$eB = $mk('odeme-e2', '2026-10-31 09:00:00', 'both@ornek.test');
audit_log_event('siparis_mail_ek', 'siparis_mail', $eB, null, ['parca' => '2', 'sonuc' => 'taninmadi', 'surum' => SIPARIS_PDF_SURUM, 'hesap_id' => $A], 1);
smt_sayac_sifirla();
$r = smt_tara('siparis', 1, 'DEPO1', ['simdi' => $SIMDI]);
$satB = array_column($r['son']['satirlar'], null, 'm')[$eB] ?? [];
ok('eski taninmadi kaydı Sipariş taramasında SON (indirme yok)', ($satB['durum'] ?? '') === 'taninmadi' && $SMT_BAGLANTI === 0, json_encode($satB['durum'] ?? null) . " bağlantı=$SMT_BAGLANTI");
smt_sayac_sifirla();
$r = smt_tara('odeme', 1, 'DEPO1', ['simdi' => $SIMDI]);
$satB = array_column($r['son']['satirlar'], null, 'm')[$eB] ?? [];
ok('aynı kayıt Ödeme taramasında yeniden indirilir ve alınır (bağlantı: bu ek + gelecek tarihli hata satırı = 2)', ($satB['durum'] ?? '') === 'alindi' && $SMT_BAGLANTI === 2, json_encode($satB['durum'] ?? null) . " bağlantı=$SMT_BAGLANTI");
smt_sayac_sifirla();
$r = smt_tara('odeme', 1, 'DEPO1', ['simdi' => $SIMDI]);
ok('sonraki Ödeme taramasında indirme YOK (defter güncellendi)', $SMT_BAGLANTI === 1 /* yalnız hata veren odeme-gelecek */, "bağlantı=$SMT_BAGLANTI");
$tablo = [
    [['sonuc' => 'taninmadi', 'surum' => SIPARIS_PDF_SURUM], true, false],
    [['sonuc' => 'taninmadi', 'surum' => SIPARIS_PDF_SURUM, 'aviz_surum' => SIPARIS_ODEME_AVIZO_SURUM], true, true],
    [['sonuc' => 'taninmadi', 'surum' => 'eski'], false, false],
    [['sonuc' => 'okunamadi', 'aviz_surum' => SIPARIS_ODEME_AVIZO_SURUM], true, true],
    [['sonuc' => 'okunamadi'], true, false],
    [['sonuc' => 'baska_tur', 'tur' => 'odeme'], true, false],
    [['sonuc' => 'baska_tur', 'tur' => 'po'], false, true],
];
$hatali = [];
foreach ($tablo as $i => [$d, $beklenenPoPc, $beklenenOdeme]) {
    if (siparis_mail_defter_son_mu($d, ['po', 'pc']) !== $beklenenPoPc) $hatali[] = "#$i po/pc";
    if (siparis_mail_defter_son_mu($d, ['odeme']) !== $beklenenOdeme) $hatali[] = "#$i odeme";
}
ok('siparis_mail_defter_son_mu tablosu (7 kayıt × 2 düğme)', $hatali === [], implode(', ', $hatali));

echo "\n=== F. Tekrar / iptal / değer koruması ===\n";
$say = fn() => $denetle('SELECT COUNT(*) FROM siparis_odemeler');
$once = $say();
$mc = $mk('odeme1', '2026-10-31 10:00:00');       // aynı içerik, yeni mail
$r = smt_tara('odeme', 1, 'DEPO1', ['simdi' => $SIMDI]);
$sc = array_column($r['son']['satirlar'], null, 'm')[$mc] ?? [];
ok('aynı içerikli yeni mail → zaten, iptal=false, yeni satır yok', ($sc['durum'] ?? '') === 'zaten' && ($sc['odeme']['iptal'] ?? null) === false && $say() === $once);
$ma = $mk('odeme-ayni', '2026-10-31 11:00:00');   // tarih+toplam aynı, bir OP-Nummer farklı → istek_id farklı → değer koruması
$r = smt_tara('odeme', 1, 'DEPO1', ['simdi' => $SIMDI]);
$sa = array_column($r['son']['satirlar'], null, 'm')[$ma] ?? [];
ok('tarih+toplam aynı, kalem listesi farklı → zaten + deger_eslesme (yeni satır yok)', ($sa['durum'] ?? '') === 'zaten' && ($sa['odeme']['deger_eslesme'] ?? null) === true && $say() === $once, json_encode($sa['odeme'] ?? null));
$ilkId = (int)$od[0]['id'];
$iptal = siparis_odeme_iptal($db, $ilkId, 'test', 1);
$md = $mk('odeme1', '2026-10-31 12:00:00');       // iptal edilen avizonun aynısı
$r = smt_tara('odeme', 1, 'DEPO1', ['simdi' => $SIMDI]);
$sd = array_column($r['son']['satirlar'], null, 'm')[$md] ?? [];
ok('iptal edilmiş ödemenin avizosu → zaten + iptal=true; DİRİLTİLMEZ, yeni satır yok', $iptal['ok'] && ($sd['durum'] ?? '') === 'zaten' && ($sd['odeme']['iptal'] ?? null) === true && $say() === $once
    && $denetle("SELECT COUNT(*) FROM siparis_odemeler WHERE id = $ilkId AND iptal_at IS NOT NULL") === 1);
ok('iptal metni "İPTAL" içerir', str_contains(sipui_mail_durum_html($sd, [], 'odeme'), 'İPTAL'));
$man = siparis_odeme_ekle($db, ['tutar' => '5.537,75', 'tarih' => '2026-10-29', 'para_birimi' => 'EUR', 'aciklama' => 'elle'], 1);
$once = $say();
$my = $mk('odeme-yeni', '2026-10-31 13:00:00');
$r = smt_tara('odeme', 1, 'DEPO1', ['simdi' => $SIMDI]);
$sy = array_column($r['son']['satirlar'], null, 'm')[$my] ?? [];
$kvm = siparis_odeme_aviz_oku($db, (int)$man['id']);
ok('elle girilmiş aynı tarih+tutar ödeme → zaten + deger_eslesme, yeni satır YOK', $man['ok'] && ($sy['durum'] ?? '') === 'zaten' && ($sy['odeme']['deger_eslesme'] ?? null) === true && ($sy['odeme']['id'] ?? 0) === (int)$man['id'] && $say() === $once, json_encode($sy['odeme'] ?? null));
ok('avizo kalemleri elle girilen ödemeye bağlandı (bagli_manuel=true)', is_array($kvm) && $kvm['bagli_manuel'] === true && count($kvm['satirlar']) === 15);
$eId = (int)$denetle("SELECT id FROM siparis_odemeler WHERE istek_id = '" . siparis_odeme_aviz_istek_id(smt_odeme_doc('odeme-e')) . "'");
$db->prepare('DELETE FROM hks_kv WHERE anahtar = ?')->execute([siparis_odeme_aviz_anahtar($eId)]);
$mo = $mk('odeme-e', '2026-10-31 14:00:00');
$r = smt_tara('odeme', 1, 'DEPO1', ['simdi' => $SIMDI]);
$so = array_column($r['son']['satirlar'], null, 'm')[$mo] ?? [];
ok('onarım: ödeme var, avizo kalem kaydı yok → zaten, kalem kaydı yeniden yazıldı', $eId > 0 && ($so['durum'] ?? '') === 'zaten' && is_array(siparis_odeme_aviz_oku($db, $eId)) && count(siparis_odeme_aviz_oku($db, $eId)['satirlar']) === 15 && $say() === $once);

echo "\n=== G. Yetki ===\n";
smt_ata($A, 1);
$kombo = [];
foreach ([[true, ['reports.export'], 'admin+export'], [true, [], 'admin, export yok'], [false, ['reports.export', 'siparis.read', 'siparis.write', 'mail.read'], 'export var, hesap.pay yok'],
          [false, ['reports.export', 'hesap.pay', 'siparis.read', 'siparis.write', 'mail.read'], 'export + hesap.pay'], [false, ['hesap.pay', 'siparis.read', 'siparis.write', 'mail.read'], 'hesap.pay var, export yok'],
          [false, ['siparis.read', 'siparis.write', 'mail.read'], 'yalnız sipariş+mail']] as [$adm, $perm, $ad]) {
    $IS_ADMIN = $adm; $PERMS = $perm;
    $kombo[$ad] = [siparis_mail_tur_yetkisi('odeme'), siparis_mail_tur_yetkisi('siparis'), siparis_mail_tur_yetkisi('fiyat')];
}
ok('tür yetkisi: ödeme için export + (admin|hesap.pay) + mail yetkisi; sipariş türü etkilenmez; bilinmeyen tür yok', $kombo === [
    'admin+export' => [true, true, false], 'admin, export yok' => [false, true, false], 'export var, hesap.pay yok' => [false, true, false],
    'export + hesap.pay' => [true, true, false], 'hesap.pay var, export yok' => [false, true, false], 'yalnız sipariş+mail' => [false, true, false]], json_encode($kombo));
$mg = $mk('odeme-g', '2026-10-31 14:00:00');
$IS_ADMIN = true; $PERMS = ['reports.export'];
$r0 = siparis_mail_baslat($db, 'odeme', 1, 'DEPO1', ['simdi' => $SIMDI]);
ok('yetkili: Ödeme taraması başladı (yarım)', $r0['ok'] && empty($r0['bitti']), json_encode($r0));
$IS_ADMIN = false; $PERMS = ['reports.export', 'siparis.read', 'siparis.write', 'mail.read'];
$rb = siparis_mail_baslat($db, 'odeme', 1, 'DEPO1', ['simdi' => $SIMDI]);
$ra = siparis_mail_adim($db, (string)$r0['tarama_id'], 1, 'DEPO1', ['istemci' => smt_fabrika()]);
$rv = siparis_mail_vazgec($db, (string)$r0['tarama_id'], 1);
ok('yetkisiz (hesap.pay yok): baslat / adim / vazgec → 403 yetki; ödeme yazılmadı', !$rb['ok'] && $rb['kod'] === 'yetki' && $rb['http'] === 403 && !$ra['ok'] && $ra['kod'] === 'yetki' && $ra['http'] === 403 && !$rv['ok'] && $rv['kod'] === 'yetki' && $say() === $once + 0, json_encode([$rb, $ra, $rv]));
$IS_ADMIN = true; $PERMS = ['reports.export'];
$rv = siparis_mail_vazgec($db, (string)$r0['tarama_id'], 1);
ok('yetki gelince vazgeç çalışır', $rv['ok'] ?? false, json_encode($rv));
$IS_ADMIN = false; $PERMS = ['reports.export', 'siparis.read', 'siparis.write', 'mail.read'];
$rs = siparis_mail_baslat($db, 'siparis', 1, 'DEPO1', ['simdi' => $SIMDI]);
ok('aynı yetkisiz kullanıcı Sipariş türünü başlatabilir (yetki sürer)', $rs['ok'], json_encode($rs));
if ($rs['ok'] && empty($rs['bitti'])) siparis_mail_vazgec($db, (string)$rs['tarama_id'], 1);
$IS_ADMIN = true; $PERMS = ['reports.export'];

echo "\n=== H. UI HTML ===\n";
$blok = sipui_mail_blok_html(['hazir' => true, 'ayarli' => ['siparis' => true, 'lojistik' => true, 'odeme' => true], 'ayar_link' => true, 'mail_admin' => true, 'son' => []]);
ok('3 düğme: ... Ödeme sorgula (mor sınıf) + ödeme notu', substr_count($blok, 'data-sip-mail="') === 3 && str_contains($blok, 'sip-mail-btn--odeme') && str_contains($blok, '💶 Ödeme sorgula') && str_contains($blok, 'sip-mail-odeme-not'));
$blok2 = sipui_mail_blok_html(['hazir' => true, 'ayarli' => ['siparis' => true, 'lojistik' => true], 'ayar_link' => true, 'mail_admin' => true, 'son' => []]);
ok('ayarli yalnız 2 anahtar → 2 düğme, ödeme notu YOK (yetkisiz kullanıcı görmez)', substr_count($blok2, 'data-sip-mail="') === 2 && !str_contains($blok2, 'sip-mail-odeme-not') && !str_contains($blok2, 'Ödeme sorgula'));
$blok3 = sipui_mail_blok_html(['hazir' => true, 'ayarli' => ['siparis' => true, 'lojistik' => true, 'odeme' => false], 'ayar_link' => true, 'mail_admin' => true, 'son' => []]);
ok('ödeme ayarsızsa düğme pasif (disabled)', (bool)preg_match('/data-sip-mail="odeme" disabled/', $blok3));
$sonA = ['tur' => 'odeme', 'uid' => 1, 'bitis' => 1792400000, 'sayac' => ['mail' => 1, 'pdf' => 1, 'alindi' => 1], 'uyarilar' => [], 'depo' => 'DEPO1', 'satirlar' => [
    siparis_mail_satir(['m' => $mg, 'p' => '2', 'dosya' => 'aviz.pdf'], 'alindi', ['tur' => 'odeme', 'odeme' => ['id' => 7, 'tutar_kurus' => 553775, 'pb' => 'EUR', 'tarih' => '2026-10-28', 'kalem' => 15, 'fatura' => 7, 'eslesen' => 4, 'eslesmeyen' => 2, 'belirsiz' => 1, 'tutar_fark' => 1, 'iptal' => false, 'deger_eslesme' => false]]),
    siparis_mail_satir(['m' => $mg, 'p' => '3', 'dosya' => 'x.pdf'], 'okunamadi', ['tur' => 'odeme', 'hata' => 'Satır <b>toplamı</b> tutmuyor']),
]];
$h2 = sipui_mail_sonuc_html($db, $sonA, [$A]);
ok('Ödeme sonuç kartı: "Ödeme eklendi" chip\'i, "Eklenen ödeme: 5.537,75 EUR", Çatışma/Uyarı sütunları YOK', str_contains($h2, 'sip-mail-chip-et">Ödeme eklendi') && str_contains($h2, 'Eklenen ödeme: <strong>5.537,75 EUR</strong>') && !str_contains($h2, '<th class="num">Çatışma</th>') && !str_contains($h2, '<th class="num">Uyarı</th>'), substr($h2, 0, 400));
ok('Sipariş sonuç kartı DEĞİŞMEDİ: Alındı chip\'i + Çatışma/Uyarı sütunları', (function () use ($db, $A, $sonA): bool { $x = $sonA; $x['tur'] = 'siparis'; $x['satirlar'] = []; return str_contains(sipui_mail_sonuc_html($db, $x, [$A]), 'Alındı'); })());
ok('alindi satırı: tutar · tarih · kalem, "7 fatura: 4 eşleşti · 2 eşleşmedi · 1 belirsiz · 1 tutar farkı", avizo bağlantısı', str_contains($h2, '5.537,75 EUR') && str_contains($h2, '28.10.2026') && str_contains($h2, '15 kalem')
    && str_contains($h2, '7 fatura: 4 eşleşti · 2 eşleşmedi · 1 belirsiz · 1 tutar farkı') && str_contains($h2, 'siparis_odemeler.php?aviz=7#odAviz'), $h2);
ok('okunamadi mesajı kaçışlı (&lt;b&gt;)', str_contains($h2, 'otomatik alınmadı') && !str_contains($h2, '<b>toplamı</b>') && str_contains($h2, '&lt;b&gt;'));
ok('taninmadi (Ödeme kartı) metni Ödemeler sayfasına yönlendirir', str_contains(sipui_mail_durum_html(['durum' => 'taninmadi'], [], 'odeme'), 'Ödeme avizosu olarak tanınmadı'));
$IS_ADMIN = false; $PERMS = ['siparis.read', 'siparis.write', 'mail.read'];
ok('baska_tur(odeme) ipucu: ödeme yetkisi YOKSA «Ödeme sorgula» önerilmez', !str_contains(sipui_mail_durum_html(['durum' => 'baska_tur', 'tur' => 'odeme']), 'Ödeme sorgula'));
$IS_ADMIN = true; $PERMS = ['reports.export'];
ok('sipui_yukle_ozet_html: Ödeme "N eklendi"', (function (): bool { $o = sipui_yukle_ozet_html([['tur' => 'odeme', 'bitis' => 1792400000, 'sayac' => ['alindi' => 1]]], null, true); return str_contains($o, 'Son sorgu: Ödeme') && str_contains($o, '1 eklendi'); })());
// Avizo kalemleri paneli
$odId = (int)$man['id'];
$pnl = sipui_odeme_aviz_html($db, $odId);
ok('panel: 15 kalem, Σ Rechnung, eşleşen sevkiyat bağlantısı (görünür depo), ogl_ic/ro/bg satırları', substr_count($pnl, '<tr><td>') === 15 && str_contains($pnl, 'siparis_view.php?id=' . $S1) && str_contains($pnl, 'TC900011.10') && str_contains($pnl, 'Σ Rechnung') && str_contains($pnl, 'Birden çok sevkiyat (2)'));
ok('panel: eşleşmeyi yalnız-bilgi notu + tutar farkı işareti', str_contains($pnl, 'ödeme sevkiyata bağlanmaz') && str_contains($pnl, 'tutar farkı'));
$DEPOLAR = ['DEPOX'];
$pnl2 = sipui_odeme_aviz_html($db, $odId);
ok('panel: görünmeyen depodaki sevkiyat bağlantısız ("Başka depo")', !str_contains($pnl2, 'siparis_view.php?id=' . $S1) && str_contains($pnl2, 'Başka depo'));
$DEPOLAR = null;
$kvx = siparis_odeme_aviz_oku($db, $odId); $kvx['satirlar'][0]['n'] = '<script>alert(1)</script>';
siparis_odeme_aviz_kaydet($db, $odId, $kvx);
$pnl3 = sipui_odeme_aviz_html($db, $odId);
ok('panel: kullanıcı verisi kaçışlı (<script> yok, &lt;script&gt; var)', !str_contains($pnl3, '<script>') && str_contains($pnl3, '&lt;script&gt;'));
ok('panel: kayıtsız (elle girilmiş) ödeme için kısa not', str_contains(sipui_odeme_aviz_html($db, 99999), 'bulunamadı') && str_contains((function () use ($db): string {
    $x = siparis_odeme_ekle($db, ['tutar' => '1,00', 'tarih' => '2026-10-20', 'para_birimi' => 'EUR'], 1); return sipui_odeme_aviz_html($db, (int)$x['id']); })(), 'avizo kalemi kaydı yok'));
$vi = siparis_odeme_aviz_var_idleri($db);
ok('var_idleri: yalnız avizo kv anahtarları (ayar/invoice anahtarları sayılmaz)', isset($vi[$odId]) && !isset($vi[99999]) && count($vi) === $denetle("SELECT COUNT(*) FROM hks_kv WHERE anahtar LIKE 'siparis_odeme_aviz_%'"));

echo "\n=== I. Gerçek PDF (opsiyonel) ===\n";
$gercek = (string)getenv('ODEME_ORNEK_PDF');
if ($gercek !== '' && is_file($gercek)) {
    $dg = siparis_pdf_isle($gercek);
    ok('GERÇEK PDF: payment_advice, ok, 80 kalem, Σ Zahlung 62.377,44 = başlık = toplam, tarih 2026-10-27, gönderen/alıcı',
        ($dg['doc_type'] ?? '') === 'payment_advice' && ($dg['ok'] ?? false) === true && count($dg['rows']) === 80 && $dg['sums']['zahlung_kurus'] === 6237744 && $dg['header']['amount_kurus'] === 6237744
        && $dg['total']['amount_kurus'] === 6237744 && $dg['header']['date'] === '2026-10-27' && $dg['header']['sender_ok'] && $dg['header']['recipient_ok'], json_encode($dg['hata'] ?? null));
    $sayim = array_count_values(array_map(static fn(array $x): string => siparis_odeme_aviz_no_coz($x['no'])['tur'], $dg['rows']));
    ok('GERÇEK PDF: tekil IHR gövdesi 35', count(array_unique(array_filter(array_map(static fn(array $x): ?string => siparis_odeme_aviz_no_coz($x['no'])['govde'], $dg['rows'])))) === 35, json_encode($sayim));
    $x = siparis_odeme_aviz_isle($db, $dg, ['m' => 1, 'h' => $A, 'p' => '2'], 1);
    ok('GERÇEK PDF: orkestratör ödemeyi yazar (62.377,44 EUR)', in_array($x['sonuc'], ['alindi', 'zaten'], true) && ($x['odeme']['tutar_kurus'] ?? 0) === 6237744, json_encode($x));
} else echo "ATLANDI (ODEME_ORNEK_PDF yok)\n";

siparis_test_bitir();
