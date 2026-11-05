<?php
// =========================================================
// scripts/siparis_debit_smoke.php — OGL DEBIT NOTE (v492): PDF → hks_kv → sevkiyat eşlemesi → kalan fark
//   php scripts/siparis_debit_smoke.php              → ağsız, tüm veri SENTETİK (fixture: scripts/fixtures/siparis/debit_note_pages.json)
//   DEBIT_ORNEK_PDF=<gerçek debit.pdf> php …          → ek olarak gerçek PDF ile ayrıştırma (dosya repoda YOKTUR; env yoksa ATLANIR)
// Birleşik harness (_siparis_mail_test_lib.php): bellek içi SQLite + sahte IMAP + GERÇEK config/siparis_mail.php, siparis_debit.php.
// A ayrıştırıcı · B kayıt (isle) · C eşleme · D harita · E Mailden al akışı · F UI HTML · G statik/güvenlik · H gerçek PDF
// =========================================================
require_once __DIR__ . '/_siparis_mail_test_lib.php';
require_once $ROOT . '/config/siparis_debit.php';

ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', ''); session_start();
$db = db();
smt_kur($db);
smt_parse_kancasi_kur();
$IS_ADMIN = true; $PERMS = ['reports.export']; $UID = 1; $AKTIF_DEPO = 'DEPO1';
$SIMDI = '2026-11-03 12:00:00';
$denetle = static fn(string $s): int => (int)$db->query($s)->fetchColumn();
$fx = json_decode((string)file_get_contents($ROOT . '/scripts/fixtures/siparis/debit_note_pages.json'), true)['pages'];
$pages = []; foreach ($fx as $n => $sg) $pages[(int)$n] = $sg;

echo "=== A. Ayrıştırıcı ===\n";
ok('tür tanıma: debit_note (sayfa 1 «Invoice-Debit Note» + «We debit you the following amount»)', siparis_pdf_belge_turu($pages) === 'debit_note');
$doc = siparis_pdf_parse_debit_note($pages);
ok('3 sayfa → 3 belge, hatasız', ($doc['ok'] ?? false) === true && count($doc['notes']) === 3 && $doc['sayfa_hata'] === 0, json_encode($doc['sayfa_hatalar'] ?? null));
$n1 = $doc['notes'][0]; $n2 = $doc['notes'][1]; $n3 = $doc['notes'][2];
ok('belge 1: no, tarih, fatura (DI), tutar 74,88 EUR, 2 kalem, LKW, fiyat farkı (4,58 → 4,60)', $n1['no'] === '2029-100001' && $n1['date'] === '2026-11-02' && $n1['invoice_no'] === 'IHR2026000000901DI' && $n1['amount_kurus'] === 7488 && $n1['pb'] === 'EUR'
    && count($n1['items']) === 2 && $n1['lkw'] === ['900001.10'] && $n1['fiyat_farki'] === true && $n1['agreed_price'] === 45800 && $n1['invoiced_price'] === 46000, json_encode($n1['agreed_price']));
ok('belge 2: DII soneki, 16,13 EUR, anlaşılan 4,56', $n2['invoice_no'] === 'IHR2026000000902DII' && $n2['amount_kurus'] === 1613 && $n2['agreed_price'] === 45600);
ok('belge 3: kalite nedenli debit → fiyat_farki=false (hesaba girmez)', $n3['fiyat_farki'] === false && $n3['reason'] === 'QUALITY CLAIM' && $n3['amount_kurus'] === 10000);
$bozuk = $pages; foreach ($bozuk[2] as &$sg) if ($sg['text'] === 'Amount EUR 16,13') $sg['text'] = 'Amount EUR 16,14'; unset($sg);
$d2 = siparis_pdf_parse_debit_note($bozuk);
ok('tutar tutmayan sayfa reddedilir, diğer 2 sayfa alınır (kısmi)', $d2['ok'] === true && count($d2['notes']) === 2 && $d2['sayfa_hata'] === 1 && str_contains($d2['sayfa_hatalar'][0]['hata'], 'tutmuyor'), json_encode($d2['sayfa_hatalar']));
$kdv = $pages; foreach ($kdv[1] as &$sg) if ($sg['text'] === 'Total amount EUR') $sg['text'] = 'Total amount EUR'; elseif ($sg['y'] == 819 && $sg['x'] > 700) $sg['text'] = '89,10'; unset($sg);
ok('«Amount» ≠ «Total amount» (KDV) → sayfa reddedilir', siparis_pdf_parse_debit_note($kdv)['sayfa_hata'] === 1);
$gonder = $pages; foreach ($gonder[1] as &$sg) if (str_contains($sg['text'], 'Asya Fresh')) $sg['text'] = 'Baska Firma'; unset($sg);
ok('alıcı Asya Fresh değil → sayfa reddedilir', siparis_pdf_parse_debit_note($gonder)['sayfa_hata'] === 1);
$fno = $pages; foreach ($fno[1] as &$sg) if (str_contains($sg['text'], 'Your Invoice')) $sg['text'] = 'Your Invoice ABC'; unset($sg);
ok('fatura numarası okunamazsa sayfa reddedilir', siparis_pdf_parse_debit_note($fno)['sayfa_hata'] === 1);
$bos = siparis_pdf_parse_debit_note([1 => []]);
ok('boş sayfa → ok=false, hata_kodu debit_okunamadi', $bos['ok'] === false && $bos['hata_kodu'] === 'debit_okunamadi');
$kopya = [1 => $pages[1], 2 => $pages[1]];
ok('aynı belge numarası iki sayfada → bir kez', count(siparis_pdf_parse_debit_note($kopya)['notes']) === 1);
$av = json_decode((string)file_get_contents($ROOT . '/scripts/fixtures/siparis/odeme_avizosu_pages.json'), true)['pages']; $avp = []; foreach ($av as $n => $sg) $avp[(int)$n] = $sg;
ok('ödeme avizosu hâlâ payment_advice (debit kuralı onu yutmaz)', siparis_pdf_belge_turu($avp) === 'payment_advice');
ok('mail-katmanı tür kodu: debit_note → odeme', siparis_mail_belge_tur_kodu($doc) === 'odeme' && siparis_mail_belge_tur_kodu(['doc_type' => 'payment_advice']) === 'odeme' && siparis_mail_belge_tur_kodu(['doc_type' => 'purchase_order']) === 'po');

echo "\n=== B. Kayıt (siparis_debit_isle) ===\n";
$odemeOnce = $denetle('SELECT COUNT(*) FROM siparis_odemeler'); $belgeOnce = $denetle('SELECT COUNT(*) FROM siparis_belgeler');
$x = siparis_debit_isle($db, $doc, ['m' => 5, 'h' => 1, 'p' => '2'], 1);
ok('1. işleme: alindi, 3 yeni; bilgi: adet 3, kurus 19.101, fiyat_farki 2, debit=true', $x['sonuc'] === 'alindi' && $x['odeme']['debit'] === true && $x['odeme']['adet'] === 3 && $x['odeme']['yeni'] === 3 && $x['odeme']['kurus'] === 7488 + 1613 + 10000 && $x['odeme']['fiyat_farki'] === 2, json_encode($x));
$kv1 = siparis_debit_oku($db, '2029-100001');
ok('hks_kv siparis_debit_<no>: tarih, fatura, tutar, LKW, kalemler, mail_id; kişisel veri/IBAN yok', is_array($kv1) && $kv1['tarih'] === '2026-11-02' && $kv1['invoice_no'] === 'IHR2026000000901DI' && $kv1['amount_kurus'] === 7488 && $kv1['lkw'] === ['900001.10'] && count($kv1['kalemler']) === 2 && $kv1['mail_id'] === 5 && $kv1['fiyat_farki'] === true
    && !str_contains((string)json_encode($kv1), 'IBAN') && !str_contains((string)json_encode($kv1), 'ornek'));
ok('ÖDEME satırı ve BELGE satırı AÇILMADI (debit para hareketi değildir)', $denetle('SELECT COUNT(*) FROM siparis_odemeler') === $odemeOnce && $denetle('SELECT COUNT(*) FROM siparis_belgeler') === $belgeOnce);
ok('audit siparis_debit yalnız sayılar', $denetle("SELECT COUNT(*) FROM audit_log WHERE action = 'siparis_debit'") === 1 && !str_contains((string)$db->query("SELECT new_values FROM audit_log WHERE action = 'siparis_debit'")->fetchColumn(), 'IHR'));
$x2 = siparis_debit_isle($db, $doc, ['m' => 6, 'h' => 1, 'p' => '2'], 1);
ok('2. işleme (aynı belgeler): zaten, yeni kayıt yok, ilk kayıt EZİLMEDİ (mail_id 5)', $x2['sonuc'] === 'zaten' && $x2['odeme']['yeni'] === 0 && $x2['odeme']['zaten'] === 3 && siparis_debit_oku($db, '2029-100001')['mail_id'] === 5 && count(siparis_debit_hepsi($db)) === 3);
$docY = siparis_pdf_parse_debit_note((function () use ($pages) { $p = $pages; foreach ($p[1] as &$sg) $sg['text'] = str_replace('2029-100001', '2029-100099', $sg['text']); return $p; })());
$x3 = siparis_debit_isle($db, $docY, ['m' => 7, 'h' => 1, 'p' => '2'], 1);
ok('karışık: 1 yeni + 2 zaten → alindi, mesaj "1 debit note eklendi, 2 zaten vardı"', $x3['sonuc'] === 'alindi' && $x3['odeme']['yeni'] === 1 && $x3['odeme']['zaten'] === 2 && str_contains($x3['mesaj'], '1 debit note eklendi'), json_encode($x3));
$db->exec("DELETE FROM hks_kv WHERE anahtar = 'siparis_debit_2029-100099'");
ok('okunamayan doküman → okunamadi (kalıcı)', siparis_debit_isle($db, ['ok' => false, 'hata' => 'x'], [], 1)['sonuc'] === 'okunamadi' && siparis_debit_isle($db, $bos, [], 1)['sonuc'] === 'okunamadi');
$db->exec('ALTER TABLE hks_kv RENAME TO hks_kv_yedek');
ok('hks_kv yokken GEÇİCİ hata (yeniden denenir), istisna yok', siparis_debit_isle($db, $doc, [], 1)['sonuc'] === 'hata' && siparis_debit_hepsi($db) === [] && siparis_debit_harita($db) === []);
$db->exec('ALTER TABLE hks_kv_yedek RENAME TO hks_kv');
ok('geçersiz belge numarası kaydedilmez', siparis_debit_kaydet($db, '../x', ['amount_kurus' => 1]) === false && siparis_debit_oku($db, 'abc') === null);

echo "\n=== C. Sevkiyat eşlemesi ===\n";
function dbt_sev(PDO $db, string $ref, string $depo = 'DEPO1'): int
{
    $db->prepare("INSERT INTO siparis_sevkiyatlar (tedarikci_no, order_ref, durum, depo, var_po) VALUES ('', ?, 'mail_gonderildi', ?, 1)")->execute([$ref, $depo]);
    return (int)$db->lastInsertId();
}
function dbt_inv(PDO $db, int $sid, string $no, int $belge): void
{
    $db->prepare("INSERT INTO siparis_belgeler (id, tur, kaynak, durum, sha256, disk_adi, orijinal_ad, mime, parse_json) VALUES (?, 'invoice', 'uretim', 'islendi', ?, ?, ?, 'application/pdf', ?)")
        ->execute([$belge, str_pad((string)$belge, 64, '0', STR_PAD_LEFT), 'd' . $belge . '.pdf', 'INV-' . $belge . '.pdf', json_encode(['invoice_no' => $no, 'toplam_kurus' => 100000])]);
    $db->prepare('INSERT INTO siparis_belge_bag (belge_id, sevkiyat_id, gecerli) VALUES (?, ?, 1)')->execute([$belge, $sid]);
    $db->prepare('REPLACE INTO hks_kv (anahtar, deger) VALUES (?, ?)')->execute(['siparis_invoice_' . $sid, json_encode(['no' => $no, 'tarih' => '2026-10-02', 'fiyat' => '1.37', 'iskonto' => '0', 'uretildi_at' => '2026-10-03 10:00:00', 'belge_id' => $belge])]);
}
$S1 = dbt_sev($db, 'TC900001.10'); $S2 = dbt_sev($db, 'TC900002.10'); $S3 = dbt_sev($db, 'TC9900003.10'); $S4 = dbt_sev($db, 'TC900777.10');
$notlar = siparis_debit_hepsi($db);
$esl = siparis_debit_esle($db, $notlar);
ok('LKW eşlemesi: 900001.10 → TC900001.10 (tek aday)', $esl['2029-100001']['durum'] === 'eslesti' && $esl['2029-100001']['sid'] === $S1 && $esl['2029-100001']['yontem'] === 'lkw' && $esl['2029-100001']['ref'] === 'TC900001.10');
ok('LKW son-eki rakamla başlayan numarayla KARIŞMAZ: 900003.10 ≠ TC9900003.10 → yok', $esl['2029-100003']['durum'] === 'yok' && $esl['2029-100003']['sid'] === null);
$S5 = dbt_sev($db, 'AB900001.10');
$esl = siparis_debit_esle($db, $notlar);
ok('aynı LKW iki sevkiyatta → belirsiz (hesaba GİRMEZ)', $esl['2029-100001']['durum'] === 'belirsiz' && $esl['2029-100001']['sid'] === null);
dbt_inv($db, $S1, 'IHR2026000000901', 601);   // fatura no → TC900001.10 (invoice üretilmiş): belirsiz LKW'yi çözer
$esl = siparis_debit_esle($db, $notlar);
ok('fatura no eşlemesi LKW\'dan ÖNCE gelir: IHR…901 → S1 (yontem=fatura), belirsiz LKW çözüldü', $esl['2029-100001']['durum'] === 'eslesti' && $esl['2029-100001']['sid'] === $S1 && $esl['2029-100001']['yontem'] === 'fatura');
dbt_inv($db, $S4, 'IHR2026000000902', 602);   // fatura 902DII → S4 (LKW 900002.10 ise S2): fatura kazanır
$esl = siparis_debit_esle($db, $notlar);
ok('fatura ve LKW farklı sevkiyatları gösterirse FATURA kazanır (902DII → S4, LKW S2 değil)', $esl['2029-100002']['sid'] === $S4 && $esl['2029-100002']['yontem'] === 'fatura');
$db->exec("DELETE FROM siparis_sevkiyatlar WHERE id = $S5");
$db->prepare('UPDATE siparis_sevkiyatlar SET deleted_at = ? WHERE id = ?')->execute(['2026-11-01 00:00:00', $S2]);
$db->exec("DELETE FROM hks_kv WHERE anahtar = 'siparis_invoice_$S4'");
$esl = siparis_debit_esle($db, $notlar);
ok('silinmiş sevkiyat eşleşmez (LKW → yok)', $esl['2029-100002']['durum'] === 'yok');
$db->prepare('UPDATE siparis_sevkiyatlar SET deleted_at = NULL WHERE id = ?')->execute([$S2]);

echo "\n=== D. Harita (Cari Döküm / Denge girdisi) ===\n";
$esl = siparis_debit_esle($db, $notlar);
$h = siparis_debit_harita($db);
ok('S1: yalnız fiyat farkı debit\'i (7.488) kurus; S2: 1.613; kalite debit\'i (S3 eşleşmedi) haritada YOK', ($h[$S1]['kurus'] ?? 0) === 7488 && ($h[$S2]['kurus'] ?? 0) === 1613 && !isset($h[$S3]) && count($h) === 2, json_encode(array_keys($h)));
ok('liste: belge no, fatura, neden, anlaşılan/kesilen fiyat', ($h[$S1]['liste'][0]['no'] ?? '') === '2029-100001' && ($h[$S1]['liste'][0]['anlasilan'] ?? 0) === 45800 && ($h[$S1]['liste'][0]['kesilen'] ?? 0) === 46000 && ($h[$S1]['liste'][0]['fiyat_farki'] ?? false) === true);
// kalite debit'i bir sevkiyata eşleşirse `diger_kurus`a gider, kuruş'a (kalan farka) GİRMEZ
$S6 = dbt_sev($db, 'TC900003.10');
$h = siparis_debit_harita($db);
ok('kalite nedenli debit eşleşince diger_kurus (10.000) — kurus 0: kalan farkı DEĞİŞTİRMEZ', ($h[$S6]['kurus'] ?? -1) === 0 && ($h[$S6]['diger_kurus'] ?? 0) === 10000);
$db->exec("UPDATE hks_kv SET deger = REPLACE(deger, '\"pb\":\"EUR\"', '\"pb\":\"USD\"') WHERE anahtar = 'siparis_debit_2029-100001'");
ok('EUR dışı debit hesaba GİRMEZ (haritada yok)', !isset(siparis_debit_harita($db)[$S1]));
$db->exec("UPDATE hks_kv SET deger = REPLACE(deger, '\"pb\":\"USD\"', '\"pb\":\"EUR\"') WHERE anahtar = 'siparis_debit_2029-100001'");

echo "\n=== E. Mailden al — Ödeme sorgula (debit note de) ===\n";
$A = smt_hesap('finans@ornek.test'); $MAIL = [$A => 'finans@ornek.test'];
smt_ayar([$A], ['siparis' => ['both@ornek.test'], 'lojistik' => [], 'odeme' => ['muhasebe@ornek.test', 'both@ornek.test']]);
$db->exec("DELETE FROM hks_kv WHERE anahtar LIKE 'siparis_debit_%'");
$mk = static fn(string $etiket, string $zaman, string $from = 'muhasebe@ornek.test'): int => smt_mail($A, $MAIL[$A], $from, [smt_ek('2', 'debit.pdf', smt_pdf($etiket))], $zaman);
$mD = $mk('debit1', '2026-11-02 08:00:00'); $mB = $mk('debit-bozuk', '2026-11-02 09:00:00'); $mO = $mk('odeme1', '2026-11-02 10:00:00');
$odemeOnce = $denetle('SELECT COUNT(*) FROM siparis_odemeler'); $belgeOnce = $denetle('SELECT COUNT(*) FROM siparis_belgeler');
smt_sayac_sifirla();
$r = smt_tara('odeme', 1, 'DEPO1', ['simdi' => $SIMDI]);
$sat = array_column($r['son']['satirlar'], null, 'm');
ok('Ödeme taraması: debit PDF alindi, bozuk-sayfalı debit PDF zaten (aynı 2 belge), avizo alindi', smt_durumlar($r) === ['alindi', 'zaten', 'alindi'], json_encode(smt_durumlar($r)));
ok('debit satırı: tur=odeme, odeme.debit=true, adet 3, eşleşen/eşleşmeyen sayıları', ($sat[$mD]['tur'] ?? '') === 'odeme' && ($sat[$mD]['odeme']['debit'] ?? false) === true && $sat[$mD]['odeme']['adet'] === 3 && $sat[$mD]['odeme']['yeni'] === 3);
ok('hks_kv\'de 3 debit kaydı; avizo ödemesi (1) dışında ÖDEME satırı eklenmedi; belge satırı yok', count(siparis_debit_hepsi($db)) === 3 && $denetle('SELECT COUNT(*) FROM siparis_odemeler') === $odemeOnce + 1 && $denetle('SELECT COUNT(*) FROM siparis_belgeler') === $belgeOnce);
ok('defter: debit satırı alindi + tur=odeme; odeme bilgisi (debit=true) yeniden yüklenebilir', (siparis_mail_defter($db, [$mD])[$mD]['2']['sonuc'] ?? '') === 'alindi' && (siparis_mail_defter($db, [$mD])[$mD]['2']['odeme']['debit'] ?? false) === true);
smt_sayac_sifirla();
$r = smt_tara('odeme', 1, 'DEPO1', ['simdi' => $SIMDI]);
global $SMT_BAGLANTI;
ok('2. Ödeme taraması: üçü de önceden çözülmüş, IMAP\'a gidilmez', smt_durumlar($r) === ['onceden', 'onceden', 'onceden'] && $SMT_BAGLANTI === 0, json_encode(smt_durumlar($r)) . " bağlantı=$SMT_BAGLANTI");
$mS = $mk('debit-yeni', '2026-11-02 11:00:00', 'both@ornek.test');
smt_sayac_sifirla();
$r = smt_tara('siparis', 1, 'DEPO1', ['simdi' => $SIMDI]);
$satS = array_column($r['son']['satirlar'], null, 'm')[$mS] ?? [];
ok('Sipariş taraması debit PDF\'i baska_tur (tur=odeme) yazar; hks_kv\'ye/ödemeye/belgeye DOKUNMAZ', ($satS['durum'] ?? '') === 'baska_tur' && ($satS['tur'] ?? '') === 'odeme' && count(siparis_debit_hepsi($db)) === 3 && $denetle('SELECT COUNT(*) FROM siparis_belgeler') === $belgeOnce, json_encode($satS['durum'] ?? null));
$r = smt_tara('odeme', 1, 'DEPO1', ['simdi' => $SIMDI]);
$satS = array_column($r['son']['satirlar'], null, 'm')[$mS] ?? [];
ok('sonra Ödeme taramasında alınır: 1 yeni (2029-100099) + 2 zaten', ($satS['durum'] ?? '') === 'alindi' && $satS['odeme']['yeni'] === 1 && $satS['odeme']['zaten'] === 2 && count(siparis_debit_hepsi($db)) === 4, json_encode($satS['odeme'] ?? null));
$PERMS = []; $IS_ADMIN = false;
ok('yetkisiz kullanıcı (reports.export yok) Ödeme sorgusu başlatamaz → debit okunmaz', !siparis_mail_tur_yetkisi('odeme'));
$PERMS = ['reports.export']; $IS_ADMIN = true;

echo "\n=== F. UI HTML ===\n";
$rowA = ['durum' => 'alindi', 'tur' => 'odeme', 'odeme' => ['debit' => true, 'adet' => 3, 'yeni' => 3, 'zaten' => 0, 'kurus' => 19101, 'pb' => 'EUR', 'fiyat_farki' => 2, 'eslesen' => 2, 'eslesmeyen' => 1, 'sayfa_hata' => 1]];
$hA = sipui_mail_durum_html($rowA, [], 'odeme');
ok('sonuç satırı: «Debit note eklendi», 3 belge · 191,01 EUR · 2 fiyat farkı, 2 sevkiyata eşleşti · 1 eşleşmedi · 1 sayfa okunamadı, liste bağlantısı', str_contains($hA, 'Debit note eklendi') && str_contains($hA, '3 belge · 191,01 EUR · 2 fiyat farkı') && str_contains($hA, '2 sevkiyata eşleşti · 1 eşleşmedi · 1 sayfa okunamadı') && str_contains($hA, 'siparis_odemeler.php#odDebit'), $hA);
ok('zaten / onceden debit metinleri', str_contains(sipui_mail_durum_html(['durum' => 'zaten'] + $rowA, [], 'odeme'), 'Debit note zaten kayıtlı') && str_contains(sipui_mail_durum_html(['durum' => 'onceden'] + $rowA, [], 'odeme'), 'Daha önce alındı (debit note)'));
ok('okunamadi metni avizo / debit ortak', str_contains(sipui_mail_durum_html(['durum' => 'okunamadi', 'hata' => 'x'], [], 'odeme'), 'debit note otomatik alınmadı'));
$son = ['tur' => 'odeme', 'sayac' => ['mail' => 1, 'pdf' => 1, 'alindi' => 1, 'zaten' => 0, 'onceden' => 0, 'atlandi' => 0, 'hata' => 0], 'satirlar' => [['m' => 1, 'p' => '2', 'durum' => 'alindi', 'tur' => 'odeme', 'dosya' => 'd.pdf', 'odeme' => $rowA['odeme']]], 'bitis' => 1792400000, 'tarama_id' => 'x'];
$sonH = sipui_mail_sonuc_html($db, $son, [$A]);
ok('sonuç kartı: debit eklenen tutarı "Eklenen ödeme" toplamına KATILMAZ', !str_contains($sonH, 'Eklenen ödeme'));
$panel = sipui_debit_html($db);
ok('Ödemeler paneli: 4 debit note, para birimi başına toplam, eşleşen sevkiyat + yöntem, «Kalan farka girer»', str_contains($panel, '4 debit note') && str_contains($panel, 'TC900001.10') && str_contains($panel, 'Kalan farka girer') && str_contains($panel, 'Fiyat farkı değil'), substr(strip_tags($panel), 0, 400));
ok('panel XSS: kullanıcı verisi h() ile kaçırılır', !str_contains($panel, '<script'));
$bosP = (function () use ($db): string { $db->exec("DELETE FROM hks_kv WHERE anahtar LIKE 'siparis_debit_%'"); return sipui_debit_html($db); })();
ok('debit yokken panel yönlendirici metin verir', str_contains($bosP, 'Kayıtlı debit note yok'));

echo "\n=== G. Statik / güvenlik ===\n";
$dk = (string)file_get_contents($ROOT . '/config/siparis_debit.php');
ok('config/siparis_debit.php: siparis_odemeler / siparis_belgeler yazımı YOK; ödeme ekleme (siparis_odeme_ekle) çağrısı YOK', !preg_match('/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?siparis_(odemeler|belgeler|sevkiyatlar)/i', $dk) && !str_contains($dk, 'siparis_odeme_ekle(') && !str_contains($dk, 'siparis_dosya_yukle_isle('));
ok('config/siparis_debit.php: mail_ek_hazirla / CREATE|ALTER|DROP TABLE / eval / exec YOK; doğrudan istek 404 (tam yol)', !str_contains($dk, 'mail_ek_hazirla(') && !preg_match('/\b(CREATE|ALTER|DROP)\s+TABLE\b/i', $dk) && !preg_match('/\b(eval|exec|shell_exec|passthru|system|popen|proc_open|unserialize)\s*\(/', $dk) && str_contains($dk, "realpath((string)(\$_SERVER['SCRIPT_FILENAME']"));
ok('e-posta literali yok (yalnız @ornek.test sentetik)', !preg_match('/[A-Za-z0-9._%+-]+@(?!ornek\.test)[A-Za-z0-9.-]+\.[a-z]{2,}/', $dk));
ok('SIPARIS_PDF_SURUM DEĞİŞMEDİ (v4); avizo/debit kapı sürümü a2', SIPARIS_PDF_SURUM === 'v4' && SIPARIS_ODEME_AVIZO_SURUM === 'a2');
ok('siparis_pdf_tur_kodu() debit_note / odeme İÇERMİYOR', !preg_match('/function siparis_pdf_tur_kodu\b.*?debit_note.*?\n\}/s', (string)file_get_contents($ROOT . '/config/siparis_eslestir.php')));

echo "\n=== H. Gerçek PDF (opsiyonel) ===\n";
$gercek = (string)getenv('DEBIT_ORNEK_PDF');
if ($gercek !== '' && is_file($gercek)) {
    $dg = siparis_pdf_isle($gercek);
    ok('GERÇEK PDF: debit_note, ok, tüm sayfalar okundu, her belge EUR + fatura no + tutar > 0',
        ($dg['doc_type'] ?? '') === 'debit_note' && ($dg['ok'] ?? false) === true && ($dg['sayfa_hata'] ?? 1) === 0 && count($dg['notes']) >= 1
        && !array_filter($dg['notes'], static fn(array $n): bool => $n['pb'] !== 'EUR' || $n['amount_kurus'] <= 0 || !preg_match('/^IHR\d{13}D[IVX]+$/', $n['invoice_no'])), json_encode($dg['sayfa_hatalar'] ?? null));
    $top = array_sum(array_column($dg['notes'], 'amount_kurus'));
    echo "  bilgi: " . count($dg['notes']) . ' belge, toplam ' . siparis_odeme_aviz_para((int)$top) . " EUR\n";
} else echo "ATLANDI (DEBIT_ORNEK_PDF yok)\n";

siparis_test_bitir();
