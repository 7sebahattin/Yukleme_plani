<?php
// =========================================================
// scripts/siparis_mail_smoke.php — "Mailden sipariş al" (v378) davranış testleri
//   php scripts/siparis_mail_smoke.php   → çıkış kodu 0 = tüm testler geçti
//
// Birleşik harness (_siparis_mail_test_lib.php): bellek içi SQLite + sahte IMAP (FakeMailStream) + GERÇEK config/siparis_mail.php,
// siparis_dosya_yukle_isle(), mail_ek_hazirla(). Her şey SENTETİK (@ornek.test, etiketli küçük PDF'ler); gerçek ayrıştırıcı yerine
// test kancası ($GLOBALS['_siparis_parse_override']) scripts/fixtures/siparis/*.json'u döndürür.
// A ayar/kapı · B aday · C PDF seçimi · D içe aktarım · E hatalar · F parçalı yürütme · G yan etkisizlik · H render
// =========================================================
require_once __DIR__ . '/_siparis_mail_test_lib.php';

ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', ''); session_start();   // csrf_token() (blok render)
$db = db();
smt_kur($db);
smt_parse_kancasi_kur();
$IS_ADMIN = true; $PERMS = []; $UID = 1; $AKTIF_DEPO = 'DEPO1';
$SIMDI = '2026-10-18 12:00:00';
$denetle = static fn(string $s): int => (int)$db->query($s)->fetchColumn();

$A = smt_hesap('export@ornek.test'); $B = smt_hesap('iletisim@ornek.test'); $C = smt_hesap('pasif@ornek.test', false); $D = smt_hesap('baska@ornek.test');
$MAIL = [$A => 'export@ornek.test', $B => 'iletisim@ornek.test', $D => 'baska@ornek.test'];

echo "=== A. Ayar + kapı ===\n";
$r = siparis_mail_baslat($db, 'lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
ok('ayarsız → 400 ayarsiz (Lojistik sorgusu için … ayarlanmadı)', !$r['ok'] && $r['kod'] === 'ayarsiz' && $r['http'] === 400 && str_contains($r['mesaj'], 'Lojistik'), json_encode($r));
ok('geçersiz tür → 400', siparis_mail_baslat($db, 'x', 1, 'DEPO1')['kod'] === 'tur');
$kombo = [];
foreach ([[false, false], [true, false], [false, true], [true, true]] as [$sw, $mr]) {
    $IS_ADMIN = false; $PERMS = array_merge(['siparis.read'], $sw ? ['siparis.write'] : [], $mr ? ['mail.read'] : []);
    $kombo[] = siparis_mail_yetkisi();
}
ok('siparis_mail_yetkisi: yalnız siparis.write VE mail.read birlikte', $kombo === [false, false, false, true], json_encode($kombo));
$IS_ADMIN = false; $PERMS = ['siparis.read', 'siparis.delete'];
$k1 = siparis_mail_ayar_yetkisi();
$PERMS = ['mail.read', 'mail.admin'];
$k2 = siparis_mail_ayar_yetkisi();
$PERMS = ['siparis.read', 'siparis.delete', 'mail.admin'];
$k3 = siparis_mail_ayar_yetkisi();
$IS_ADMIN = true; $PERMS = [];
ok('ayar yetkisi: sipariş silme + mail yönetimi ikisi birlikte (admin hepsini açar)', !$k1 && !$k2 && $k3 && siparis_mail_ayar_yetkisi());
$IS_ADMIN = false; $PERMS = ['siparis.read', 'siparis.write', 'mail.read'];
$y = siparis_mail_ayar_kaydet($db, ['hesaplar' => [$A], 'gonderen' => ['siparis' => 'a@ornek.test']], 1);
ok('yetkisiz kullanıcı ayarı DEĞİŞTİREMEZ', !$y['ok'] && siparis_mail_kv_oku($db, SIPARIS_MAIL_KV_AYAR) === null);
$IS_ADMIN = true; $PERMS = [];
ok('geçersiz e-posta reddedilir', !siparis_mail_ayar_kaydet($db, ['hesaplar' => [$A], 'gonderen' => ['lojistik' => 'bu bir adres degil']], 1)['ok']);
ok('olmayan posta kutusu reddedilir', !siparis_mail_ayar_kaydet($db, ['hesaplar' => [9999], 'gonderen' => []], 1)['ok']);
ok('pasif posta kutusu reddedilir', !siparis_mail_ayar_kaydet($db, ['hesaplar' => [$C], 'gonderen' => []], 1)['ok']);
$cok = implode("\n", array_map(fn($i) => "u$i@ornek.test", range(1, 11)));
ok('tür başına en çok 10 gönderen', !siparis_mail_ayar_kaydet($db, ['hesaplar' => [$A], 'gonderen' => ['fiyat' => $cok]], 1)['ok']);
ok('geçersiz ayarlar KV\'ye yazılmadı', siparis_mail_kv_oku($db, SIPARIS_MAIL_KV_AYAR) === null);
$y = siparis_mail_ayar_kaydet($db, ['hesaplar' => [$A, $B, $A], 'gonderen' => ['siparis' => "PO@Ornek.Test\n po@ornek.test, \nkisi@ornek.test;", 'lojistik' => ['LOG@ornek.test'], 'fiyat' => '']], 1);
$ay = siparis_mail_ayar_oku($db);
ok('ayar kaydedildi; adresler küçük harf + tekil + sıralı', $y['ok'] && $ay['hesaplar'] === [$A, $B] && $ay['gonderen']['siparis'] === ['po@ornek.test', 'kisi@ornek.test'] && $ay['gonderen']['lojistik'] === ['log@ornek.test'] && $ay['gonderen']['fiyat'] === [], json_encode($ay));
ok('ayarli_mi: hesap + o türün gönderen listesi', siparis_mail_ayarli_mi($ay, 'siparis') && siparis_mail_ayarli_mi($ay, 'lojistik') && !siparis_mail_ayarli_mi($ay, 'fiyat'));
$aud = $db->query("SELECT new_values FROM audit_log WHERE action = 'siparis_mail_ayar'")->fetchAll(PDO::FETCH_COLUMN);
ok('audit siparis_mail_ayar yazıldı ve yalnız SAYILAR içerir (adres geçmez)', count($aud) === 1 && !str_contains($aud[0], 'ornek.test') && !str_contains($aud[0], '@') && str_contains($aud[0], '"hesap_sayisi":2'), json_encode($aud));
$db->exec("UPDATE hks_kv SET deger = '{bozuk' WHERE anahtar = 'siparis_mail_ayar'");
ok('bozuk ayar → boş (fail-closed)', siparis_mail_ayar_oku($db)['hesaplar'] === [] && !siparis_mail_ayarli_mi(siparis_mail_ayar_oku($db), 'siparis'));
smt_ayar([$A, $B], ['siparis' => ['po@ornek.test'], 'lojistik' => ['log@ornek.test', 'kisi@ornek.test'], 'fiyat' => ['po@ornek.test']]);
ok('siparis_mail_hazir: şema + anahtar + hks_kv', siparis_mail_hazir($db));
ok('hks_kv tablo adı beyan öneri emsaliyle aynı', siparis_mail_kv_tablo() === beyan_suggest_kv_table());

echo "\n=== B. Aday seçimi ===\n";
$ek = fn() => [smt_ek('2', 'a.pdf', smt_pdf('ll-b'))];
$m1 = smt_mail($A, $MAIL[$A], 'LOG@ORNEK.TEST', $ek(), '2026-10-17 09:00:00');                          // BÜYÜK harfli From
$m2 = smt_mail($A, $MAIL[$A], 'baska@ornek.test', $ek(), '2026-10-17 09:00:00');                        // aynı alan adı, farklı adres
$m3 = smt_mail($A, $MAIL[$A], 'yabanci@ornek.test', $ek(), '2026-10-17 09:00:00', ['reply_to' => 'log@ornek.test']);   // yalnız Reply-To eşleşir
$m4 = smt_mail($A, $MAIL[$A], 'log@ornek.test', $ek(), '2026-10-02 23:59:59');                           // 16. gün (pencere dışı)
$m5 = smt_mail($A, $MAIL[$A], 'log@ornek.test', $ek(), '2026-10-03 00:00:00');                           // 15. günün başı (içeride)
$m6 = smt_mail($A, $MAIL[$A], 'log@ornek.test', $ek(), '2026-10-17 10:00:00', ['folder' => 'Sent']);    // Gönderilenler klasörü
$m7 = smt_mail($D, $MAIL[$D], 'log@ornek.test', $ek(), '2026-10-17 10:00:00');                           // ayarlı olmayan kutu
$m8 = smt_mail($A, $MAIL[$A], 'log@ornek.test', [], '2026-10-17 10:00:00');                              // eki yok
$m9 = smt_mail($B, $MAIL[$B], 'kisi@ornek.test', $ek(), '2026-10-17 11:00:00');                          // ikinci ayarlı kutu
$adaylar = siparis_mail_adaylar($db, [$A, $B], ['log@ornek.test', 'kisi@ornek.test'], '2026-10-03 00:00:00', 300);
$idler = array_map(fn($x) => (int)$x['id'], $adaylar);
sort($idler);
ok('aday: tam adres (BÜYÜK harfli From dahil) + 15. gün başı + ikinci kutu', $idler === [$m1, $m5, $m9], json_encode($idler));
ok('aday: alan adı eşleşmesi YOK', !in_array($m2, $idler, true));
ok('aday: yalnız Reply-To eşleşen mail aday DEĞİL', !in_array($m3, $idler, true));
ok('aday: 16 gün önceki dışarıda', !in_array($m4, $idler, true));
ok('aday: Gönderilenler klasörü dışarıda (mail_gonderilen_disla_sql)', !in_array($m6, $idler, true) && str_contains(file_get_contents($ROOT . '/config/siparis_mail.php'), 'mail_gonderilen_disla_sql()'));
ok('aday: ayarlı olmayan kutu dışarıda', !in_array($m7, $idler, true));
ok('aday: eki olmayan (has_attachments=0) dışarıda', !in_array($m8, $idler, true));
ok('aday: boş gönderen / boş hesap listesi → boş', siparis_mail_adaylar($db, [$A], [], '2026-10-03 00:00:00', 10) === [] && siparis_mail_adaylar($db, [], ['log@ornek.test'], '2026-10-03 00:00:00', 10) === []);
// görünmeyen kutu: kullanıcı 2 yalnız A'ya atanmış → B taranmaz + uyarı
smt_ata($A, 2);
$IS_ADMIN = false; $PERMS = ['siparis.read', 'siparis.write', 'mail.read']; $UID = 2;
$r = siparis_mail_baslat($db, 'lojistik', 2, 'DEPO1', ['simdi' => $SIMDI]);
ok('görünmeyen kutu taranmaz + "size atanmamış" uyarısı', $r['ok'] && (bool)preg_grep('/size atanmamış/', $r['uyarilar']), json_encode($r));
siparis_mail_vazgec($db, (string)$r['tarama_id'], 2);
$plan = siparis_mail_plan($db, 'lojistik', siparis_mail_tarama_hesaplari($db, siparis_mail_ayar_oku($db), 2), siparis_mail_ayar_oku($db), $SIMDI);
$planIds = array_merge(array_column($plan['oge'], 'm'), array_column($plan['satirlar'], 'm'));
ok('plan: B kutusunun maili (kullanıcı 2\'ye görünmez) planda YOK', !in_array($m9, $planIds, true) && in_array($m1, $planIds, true) && in_array($m5, $planIds, true));
$r3 = siparis_mail_baslat($db, 'lojistik', 3, 'DEPO1', ['simdi' => $SIMDI]);
ok('hiçbir ayarlı kutu atanmamışsa → 400 hesap', !$r3['ok'] && $r3['kod'] === 'hesap' && $r3['http'] === 400);
$IS_ADMIN = true; $PERMS = []; $UID = 1;

echo "\n=== C. PDF seçimi ===\n";
$e = fn(string $part, string $ad, string $mime, array $o = []) => ['part' => $part, 'filename' => $ad, 'mime' => $mime, 'size' => $o['size'] ?? 5000, 'inline' => $o['inline'] ?? false];
$sec = siparis_mail_pdf_ekleri(['attachments_json' => json_encode([
    $e('2', 'LL.pdf', 'application/pdf'), $e('3', 'DOSYA.PDF', 'application/octet-stream'), $e('4', 'ic.pdf', 'application/pdf', ['inline' => true]),
    $e('5', 'foto.png', 'image/png'), $e('6', 'ek-6', 'application/pdf'), $e('7', 'x.pdf', 'application/x-pdf'), $e('2]x', 'kotu.pdf', 'application/pdf'),
    $e('8', 'eski.msg', 'message/rfc822'), $e('9', 'sozlesme.docx', 'application/octet-stream')]), 'body_truncated' => 0, 'subject' => 'k']);
ok('PDF seçimi: pdf, octet-stream+.PDF, INLINE pdf, adsız pdf (mime), x-pdf; png/docx/geçersiz parça hariç', array_column($sec['ekler'], 'part') === ['2', '3', '4', '6', '7'], json_encode($sec['ekler']));
ok('message/rfc822 → iletilen (PDF listesinde DEĞİL)', array_column($sec['iletilen'], 'part') === ['8'] && $sec['sorun'] === null);
$kesik = siparis_mail_pdf_ekleri(['attachments_json' => json_encode([$e('2', 'foto.png', 'image/png')]), 'body_truncated' => 1, 'subject' => 'k']);
ok('kesik mail + PDF yok → ek_eksik', $kesik['sorun'] === 'ek_eksik' && $kesik['ekler'] === []);
ok('yer tutucu konu "(işlenemedi" + ek yok → ek_eksik', siparis_mail_pdf_ekleri(['attachments_json' => null, 'body_truncated' => 0, 'subject' => '(işlenemedi — mail çok büyük'])['sorun'] === 'ek_eksik');
ok('kesik değilse PDF yok → sorun yok (özet sayısına girer)', siparis_mail_pdf_ekleri(['attachments_json' => json_encode([$e('2', 'foto.png', 'image/png')]), 'body_truncated' => 0, 'subject' => 'k'])['sorun'] === null);
ok('bozuk attachments_json → boş', siparis_mail_pdf_ekleri(['attachments_json' => '{bozuk', 'body_truncated' => 0, 'subject' => ''])['ekler'] === []);

// Entegre: temiz dünya (ayrı gönderen) — adsız PDF, dev ek, iletilmiş, kesik
$db->exec('DELETE FROM mail_messages');
smt_ayar([$A], ['lojistik' => ['c@ornek.test']]);
$cAdsiz = smt_mail($A, $MAIL[$A], 'c@ornek.test', [smt_ek('2', 'ek-2', smt_pdf('ll-adsiz'))], '2026-10-16 08:00:00');
$cDev = smt_mail($A, $MAIL[$A], 'c@ornek.test', [smt_ek('2', 'buyuk.pdf', null, 'application/pdf', ['size' => 20 * 1048576])], '2026-10-16 08:01:00');
$cIlet = smt_mail($A, $MAIL[$A], 'c@ornek.test', [smt_ek('2', 'Weitergeleitet.eml', null, 'message/rfc822')], '2026-10-16 08:02:00');
$cKesik = smt_mail($A, $MAIL[$A], 'c@ornek.test', [smt_ek('2', 'logo.png', null, 'image/png')], '2026-10-16 08:03:00', ['body_truncated' => 1]);
$cPdfsiz = smt_mail($A, $MAIL[$A], 'c@ornek.test', [smt_ek('2', 'logo.png', null, 'image/png')], '2026-10-16 08:04:00');
smt_sayac_sifirla();
$r = smt_tara('lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
$st = array_column($r['son']['satirlar'], 'durum', 'm');
ok('adsız PDF parçası ".pdf" eklenip kaydedildi (alindi)', ($st[$cAdsiz] ?? '') === 'alindi', json_encode($st));
$belge = $db->query("SELECT orijinal_ad, kaynak FROM siparis_belgeler ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ok('belge adı .pdf ile biter, kaynak=mail', preg_match('/\.pdf$/i', (string)$belge['orijinal_ad']) === 1 && $belge['kaynak'] === 'mail', json_encode($belge));
ok('>15 MB ek → cok_buyuk, o ek için IMAP bağlantısı açılmadı', ($st[$cDev] ?? '') === 'cok_buyuk');
ok('message/rfc822 → iletilmis', ($st[$cIlet] ?? '') === 'iletilmis');
ok('kesik mail + PDF yok → ek_eksik', ($st[$cKesik] ?? '') === 'ek_eksik');
ok('PDF eki olmayan mail satır AÇMAZ, yalnız özet sayısı', !isset($st[$cPdfsiz]) && $r['son']['pdfsiz'] === 1);
global $SMT_BAGLANTI;
ok('yalnız indirilecek TEK ek için IMAP bağlantısı (dev/iletilmiş/kesik için 0)', $SMT_BAGLANTI === 1, "bağlantı: $SMT_BAGLANTI");
ok('sayaçlar: mail 5, pdf 2, alindi 1, atlandi 3', $r['son']['sayac']['mail'] === 5 && $r['son']['sayac']['pdf'] === 2 && $r['son']['sayac']['alindi'] === 1 && $r['son']['sayac']['atlandi'] === 3, json_encode($r['son']['sayac']));

echo "\n=== D. İçe aktarım ===\n";
$db->exec('DELETE FROM mail_messages');
$db->exec("DELETE FROM audit_log WHERE action IN ('siparis_mail_ek','siparis_belge_yukle')");
smt_ayar([$A, $B], ['siparis' => ['po@ornek.test'], 'lojistik' => ['log@ornek.test'], 'fiyat' => ['po@ornek.test']]);
$dLL = smt_mail($A, $MAIL[$A], 'log@ornek.test', [smt_ek('2', 'LOADLIST.pdf', smt_pdf('ll-d1'))], '2026-10-16 08:00:00');
smt_sayac_sifirla();
$belgeOnce = $denetle('SELECT COUNT(*) FROM siparis_belgeler');
$r = smt_tara('lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
ok('LL / Lojistik → alindi', smt_durumlar($r) === ['alindi'], json_encode(smt_durumlar($r)));
$b = $db->query('SELECT * FROM siparis_belgeler ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
ok('belge: kaynak=mail, depo=aktif depo, yükleyen=kullanıcı, tür ll', $b['kaynak'] === 'mail' && $b['depo'] === 'DEPO1' && (int)$b['yukleyen'] === 1 && $b['tur'] === 'll' && $b['durum'] === 'islendi', json_encode($b));
$sev = siparis_test_sev($db, 'TC900001.10');
ok('sevkiyat açıldı (tedarikçi/order_ref) ve aktif depoyla damgalandı', $sev !== null && $sev['depo'] === 'DEPO1');
$by = json_decode((string)$db->query("SELECT new_values FROM audit_log WHERE action = 'siparis_belge_yukle' ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
ok('audit siparis_belge_yukle: islem=mail + mail_id/hesap_id/parca', ($by['islem'] ?? '') === 'mail' && (int)($by['mail_id'] ?? 0) === $dLL && (int)($by['hesap_id'] ?? 0) === $A && ($by['parca'] ?? '') === '2', json_encode($by));
$defter = siparis_mail_defter($db, [$dLL]);
ok('defter: siparis_mail_ek (alindi, belge_id, tür, sürüm)', ($defter[$dLL]['2']['sonuc'] ?? '') === 'alindi' && (int)$defter[$dLL]['2']['belge_id'] === (int)$b['id'] && $defter[$dLL]['2']['surum'] === SIPARIS_PDF_SURUM && $defter[$dLL]['2']['tur'] === 'll', json_encode($defter));
$dd = (string)$db->query("SELECT new_values FROM audit_log WHERE action = 'siparis_mail_ek'")->fetchColumn();
ok('defter kaydında adres / konu / dosya adı YOK', !str_contains($dd, 'ornek.test') && !str_contains($dd, 'LOADLIST') && !str_contains($dd, 'Konu'), $dd);
$olay = (string)$db->query("SELECT aciklama FROM siparis_olaylar WHERE tur = 'belge_eklendi' ORDER BY id DESC LIMIT 1")->fetchColumn();
ok('sevkiyat zaman çizelgesi: "(mailden)"', str_contains($olay, '(mailden)'), $olay);
ok('sonuç satırı: sevkiyat bağlantısı + belge', $r['son']['satirlar'][0]['sevkiyatlar'][0]['order_ref'] === 'TC900001.10' && (int)$r['son']['satirlar'][0]['belge_id'] === (int)$b['id']);

smt_sayac_sifirla();
$r = smt_tara('lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
ok('2. tarama → onceden (Daha önce alınmış)', smt_durumlar($r) === ['onceden'], json_encode(smt_durumlar($r)));
ok('2. taramada IMAP bağlantısı 0', $SMT_BAGLANTI === 0, "bağlantı: $SMT_BAGLANTI");
ok('2. taramada belge sayısı artmadı', $denetle('SELECT COUNT(*) FROM siparis_belgeler') === $belgeOnce + 1);

// PO + PC, ortak gönderen: Sipariş düğmesi PC'yi ALMAZ, Fiyat düğmesi PO'yu ALMAZ
$dPO = smt_mail($A, $MAIL[$A], 'po@ornek.test', [smt_ek('2', 'PO.pdf', smt_pdf('po-d1'))], '2026-10-16 09:00:00');
$dPC = smt_mail($A, $MAIL[$A], 'po@ornek.test', [smt_ek('2', 'PC.pdf', smt_pdf('pc-d1'))], '2026-10-16 09:30:00');
$belgeOnce = $denetle('SELECT COUNT(*) FROM siparis_belgeler');
smt_sayac_sifirla();
$r = smt_tara('siparis', 1, 'DEPO1', ['simdi' => $SIMDI]);
$st = array_column($r['son']['satirlar'], 'durum', 'm');
ok('Sipariş düğmesi: PO → alindi, PC → baska_tur', ($st[$dPO] ?? '') === 'alindi' && ($st[$dPC] ?? '') === 'baska_tur', json_encode($st));
$bt = array_values(array_filter($r['son']['satirlar'], fn($s) => $s['durum'] === 'baska_tur'))[0];
ok('baska_tur: tür=pc kaydedildi, BELGE SATIRI AÇILMADI (yalnız PO için +1)', $bt['tur'] === 'pc' && $denetle('SELECT COUNT(*) FROM siparis_belgeler') === $belgeOnce + 1);
ok('baska_tur metni doğru düğmeyi söyler', str_contains(sipui_mail_durum_html($bt), 'Fiyat sorgula'), sipui_mail_durum_html($bt));
smt_sayac_sifirla();
$r = smt_tara('fiyat', 1, 'DEPO1', ['simdi' => $SIMDI]);
$st = array_column($r['son']['satirlar'], 'durum', 'm');
ok('Fiyat düğmesi: PC → alindi; PO daha önce alınmış (onceden)', ($st[$dPC] ?? '') === 'alindi' && ($st[$dPO] ?? '') === 'onceden', json_encode($st));
ok('Fiyat taramasında yalnız PC için IMAP bağlantısı (PO defterden)', $SMT_BAGLANTI === 1, "bağlantı: $SMT_BAGLANTI");
smt_sayac_sifirla();
$r = smt_tara('siparis', 1, 'DEPO1', ['simdi' => $SIMDI]);
ok('Sipariş yeniden: PC artık alındı (defterde son karar alindi) → onceden, IMAP 0', smt_durumlar($r) === ['onceden', 'onceden'] && $SMT_BAGLANTI === 0, json_encode(smt_durumlar($r)) . " bağlantı:$SMT_BAGLANTI");

// Tanınmayan PDF
$dX = smt_mail($A, $MAIL[$A], 'log@ornek.test', [smt_ek('2', 'rapor.pdf', smt_pdf('bilinmiyor-1'))], '2026-10-16 10:00:00');
$belgeOnce = $denetle('SELECT COUNT(*) FROM siparis_belgeler');
$r = smt_tara('lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
$stx = array_column($r['son']['satirlar'], 'durum', 'm');
ok('tanınmayan PDF → taninmadi, belge satırı AÇILMADI', ($stx[$dX] ?? '') === 'taninmadi' && $denetle('SELECT COUNT(*) FROM siparis_belgeler') === $belgeOnce);
smt_sayac_sifirla();
$r = smt_tara('lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
ok('taninmadi aynı ayrıştırıcı sürümüyle tekrar indirilmez', $SMT_BAGLANTI === 0 && (array_column($r['son']['satirlar'], 'durum', 'm')[$dX] ?? '') === 'taninmadi');
$db->exec("UPDATE audit_log SET new_values = REPLACE(new_values, '\"surum\":\"" . SIPARIS_PDF_SURUM . "\"', '\"surum\":\"v0\"') WHERE action = 'siparis_mail_ek' AND record_id = $dX");
smt_sayac_sifirla();
$r = smt_tara('lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
ok('defterdeki ayrıştırıcı sürümü farklıysa taninmadi YENİDEN denenir', $SMT_BAGLANTI === 1, "bağlantı: $SMT_BAGLANTI");

// Elle yüklenmiş aynı PDF → zaten (kaynak yukleme kalır)
$manuel = siparis_dosya_yukle_isle($db, siparis_test_dosya(smt_pdf('ll-elle'), 'elle.pdf'), 1, 'DEPO1', null, siparis_test_mover());
ok('elle yükleme (ortak fonksiyon) → islendi', $manuel['durum'] === 'islendi' && $manuel['tur'] === 'll', json_encode($manuel));
$dEl = smt_mail($A, $MAIL[$A], 'log@ornek.test', [smt_ek('2', 'AYNI.pdf', smt_pdf('ll-elle'))], '2026-10-16 11:00:00');
$r = smt_tara('lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
$ste = array_column($r['son']['satirlar'], 'durum', 'm');
ok('elle yüklenmiş aynı içerik → zaten (belge kaynağı yukleme KALIR)', ($ste[$dEl] ?? '') === 'zaten' && (string)$db->query("SELECT kaynak FROM siparis_belgeler WHERE id = " . (int)$manuel['belge_id'])->fetchColumn() === 'yukleme');
ok('zaten metni: "Zaten vardı (daha önce yüklenmiş)"', str_contains(sipui_mail_durum_html(array_values(array_filter($r['son']['satirlar'], fn($s) => $s['m'] === $dEl))[0]), 'Zaten vardı (daha önce yüklenmiş)'));

// Silinmiş belge dirilmez
$dSil = smt_mail($A, $MAIL[$A], 'log@ornek.test', [smt_ek('2', 'sil.pdf', smt_pdf('ll-sil'))], '2026-10-16 12:00:00');
$r = smt_tara('lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
$sBelge = (int)$db->query("SELECT id FROM siparis_belgeler WHERE orijinal_ad = 'sil.pdf'")->fetchColumn();
$db->exec("UPDATE siparis_belgeler SET deleted_at = '2026-10-17 10:00:00', deleted_by = 1 WHERE id = $sBelge");
$db->exec("DELETE FROM audit_log WHERE action = 'siparis_mail_ek' AND record_id = $dSil");
$sevOnce = json_encode($db->query('SELECT * FROM siparis_sevkiyatlar ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
$bagOnce = $denetle('SELECT COUNT(*) FROM siparis_belge_bag');
$r = smt_tara('lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
$stS = array_column($r['son']['satirlar'], 'durum', 'm');
ok('silinmiş belge → silinmis (geri GETİRİLMEDİ)', ($stS[$dSil] ?? '') === 'silinmis', json_encode($stS));
ok('silinmiş belge: deleted_at HÂLÂ dolu, sevkiyat değerleri ve bağlar değişmedi', $db->query("SELECT deleted_at FROM siparis_belgeler WHERE id = $sBelge")->fetchColumn() !== null
    && json_encode($db->query('SELECT * FROM siparis_sevkiyatlar ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)) === $sevOnce && $denetle('SELECT COUNT(*) FROM siparis_belge_bag') === $bagOnce);
smt_sayac_sifirla();
$r = smt_tara('lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
ok('silinmis sonucu deftere yazıldı: sonraki taramada indirme YOK', $SMT_BAGLANTI === 0);
$geri = siparis_dosya_yukle_isle($db, siparis_test_dosya(smt_pdf('ll-sil'), 'sil.pdf'), 1, 'DEPO1', null, siparis_test_mover());
ok('ELLE yükleme silinmiş belgeyi hâlâ geri getirir (davranış korundu)', $geri['durum'] === 'islendi' && $db->query("SELECT deleted_at FROM siparis_belgeler WHERE id = $sBelge")->fetchColumn() === null);

// Aynı Message-ID iki kutuda
$db->exec('DELETE FROM mail_messages');
$ikiA = smt_mail($A, $MAIL[$A], 'log@ornek.test', [smt_ek('2', 'IKI.pdf', smt_pdf('ll-iki'))], '2026-10-16 13:00:00', ['mid' => 'ortak-mesaj', 'uidv' => 9999]);   // asıl kopya: UIDVALIDITY farklı → 409
$ikiB = smt_mail($B, $MAIL[$B], 'log@ornek.test', [smt_ek('2', 'IKI.pdf', smt_pdf('ll-iki'))], '2026-10-16 13:00:00', ['mid' => 'ortak-mesaj']);
$belgeOnce = $denetle('SELECT COUNT(*) FROM siparis_belgeler');
$r = smt_tara('lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
ok('aynı Message-ID iki kutuda → TEK satır, TEK içe aktarım', count($r['son']['satirlar']) === 1 && $r['son']['satirlar'][0]['durum'] === 'alindi' && $denetle('SELECT COUNT(*) FROM siparis_belgeler') === $belgeOnce + 1, json_encode(smt_durumlar($r)));
ok('asıl kopya 409 (UIDVALIDITY) → yedek kopyadan alındı + not', (int)$r['son']['satirlar'][0]['kopya'] === 2 && str_contains(sipui_mail_durum_html($r['son']['satirlar'][0]), '2 posta kutusunda'));
ok('defter yedek kopyanın mail id\'siyle yazıldı', ($defter = siparis_mail_defter($db, [$ikiA, $ikiB])) && isset($defter[$ikiB]['2']) && !isset($defter[$ikiA]));
smt_sayac_sifirla();
$r = smt_tara('lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
ok('kopyanın defteri asılla birlikte sayılır → 2. taramada onceden, IMAP 0', smt_durumlar($r) === ['onceden'] && $SMT_BAGLANTI === 0, json_encode(smt_durumlar($r)));

echo "\n=== E. Hatalar ===\n";
$db->exec('DELETE FROM mail_messages');
$db->exec("DELETE FROM audit_log WHERE action = 'siparis_mail_ek'");
$eU = smt_mail($A, $MAIL[$A], 'log@ornek.test', [smt_ek('2', 'UV.pdf', smt_pdf('ll-uv'))], '2026-10-16 14:00:00', ['uidv' => 4321]);
$r = smt_tara('lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
$s0 = $r['son']['satirlar'][0];
ok('UIDVALIDITY farkı → hata (metin), defter YOK', $s0['durum'] === 'hata' && str_contains((string)$s0['hata'], 'yeniden indekslendi') && siparis_mail_defter($db, [$eU]) === [], json_encode($s0));
ok('hata sayacı + metin "Sonraki sorguda yeniden denenir"', $r['son']['sayac']['hata'] === 1 && str_contains(sipui_mail_durum_html($s0), 'yeniden denenir'));
$db->exec("UPDATE mail_messages SET uidvalidity = 1000 WHERE id = $eU");
$r = smt_tara('lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
ok('hata kalıcı değil: sorun giderilince sonraki taramada alındı', smt_durumlar($r) === ['alindi'], json_encode(smt_durumlar($r)));
$eB = smt_mail($A, $MAIL[$A], 'log@ornek.test', [smt_ek('2', 'BG.pdf', smt_pdf('ll-bg'))], '2026-10-16 14:30:00');
smt_srv($MAIL[$A])->cfg['pass'] = 'YANLIS';
$r = smt_tara('lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
$sb = array_values(array_filter($r['son']['satirlar'], fn($s) => $s['m'] === $eB))[0];
ok('bağlantı/kimlik hatası → hata, parola metne SIZMAZ', $sb['durum'] === 'hata' && !str_contains(json_encode($sb), 'imap-parola-ABC') && !str_contains(json_encode($sb), 'YANLIS'), json_encode($sb));
smt_srv($MAIL[$A])->cfg['pass'] = 'imap-parola-ABC';
$eN = smt_mail($A, $MAIL[$A], 'log@ornek.test', [smt_ek('2', 'sahte.pdf', 'Bu bir PDF degil')], '2026-10-16 15:00:00');
$eZ = smt_mail($A, $MAIL[$A], 'log@ornek.test', [smt_ek('2', 'bozuk.pdf', smt_pdf('bozuk-1'))], '2026-10-16 15:30:00');
$belgeOnce = $denetle('SELECT COUNT(*) FROM siparis_belgeler');
$r = smt_tara('lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
$st = array_column($r['son']['satirlar'], 'durum', 'm');
ok('%PDF- ile başlamayan ek → gecersiz', ($st[$eN] ?? '') === 'gecersiz', json_encode($st));
ok('ayrıştırıcı error → gecersiz, belge satırı AÇILMADI', ($st[$eZ] ?? '') === 'gecersiz' && $denetle('SELECT COUNT(*) FROM siparis_belgeler') === $belgeOnce + (($st[$eB] ?? '') === 'alindi' ? 1 : 0));
smt_sayac_sifirla();
$r = smt_tara('lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
ok('hata olan ek (eB) sonraki taramada yeniden denendi ve alındı', ($st[$eB] ?? '') === 'alindi');
ok('gecersiz deftere yazıldı: sonraki taramada indirilmez, durum görünür kalır (IMAP 0)', ($stt = array_column($r['son']['satirlar'], 'durum', 'm')) && ($stt[$eN] ?? '') === 'gecersiz' && ($stt[$eZ] ?? '') === 'gecersiz' && ($stt[$eB] ?? '') === 'onceden' && $SMT_BAGLANTI === 0, json_encode($stt) . " bağlantı:$SMT_BAGLANTI");

// Sunucuda artık olmayan ek (404) KALICIDIR: bir kez denenir, deftere yazılır, sonraki sorgularda IMAP'a gidilmez
$eY = smt_mail($A, $MAIL[$A], 'log@ornek.test', [smt_ek('2', 'yok.pdf', smt_pdf('ll-yok'))], '2026-10-16 16:00:00');
unset(smt_srv($MAIL[$A])->mesajlar[(int)$db->query("SELECT uid FROM mail_messages WHERE id = $eY")->fetchColumn()]);   // sunucudan silinmiş (EXPUNGE)
smt_sayac_sifirla();
$r = smt_tara('lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
$stY = array_column($r['son']['satirlar'], 'durum', 'm');
ok('sunucuda olmayan ek → ek_yok (hata DEĞİL), defter yazıldı', ($stY[$eY] ?? '') === 'ek_yok' && (siparis_mail_defter($db, [$eY])[$eY]['2']['sonuc'] ?? '') === 'ek_yok', json_encode($stY));
smt_sayac_sifirla();
$r = smt_tara('lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
$stY = array_column($r['son']['satirlar'], 'durum', 'm');
ok('ek_yok sonraki taramada yeniden denenmez (IMAP 0), durum görünür kalır', ($stY[$eY] ?? '') === 'ek_yok' && $SMT_BAGLANTI === 0, json_encode($stY) . " bağlantı:$SMT_BAGLANTI");
ok('ek_yok metni tekrar denenmeyeceğini söyler', str_contains(sipui_mail_durum_html(['durum' => 'ek_yok']), 'tekrar denenmez'));

// Sayfa render'ı: yalnız siparis_mail.php yüklüyken (siparisler.php gibi) sonuç kartı fatal vermemeli (mail_mime_mojibake_duzelt)
$kod = 'require ' . var_export($ROOT . '/config/siparis_mail.php', true) . '; echo function_exists("mail_mime_mojibake_duzelt") ? "OK" : "YOK";';
$cikti = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -r ' . escapeshellarg($kod) . ' 2>&1');
ok('config/siparis_mail.php tek başına mail_mime yardımcılarını yükler (render fatal regresyonu)', trim($cikti) === 'OK', $cikti);

echo "\n=== F. Parçalı yürütme ===\n";
$db->exec('DELETE FROM mail_messages');
$db->exec("DELETE FROM audit_log WHERE action = 'siparis_mail_ek'");
foreach (['fx' => 'fx@ornek.test', 'fy' => 'fy@ornek.test'] as $p => $adr) {
    for ($i = 1; $i <= 8; $i++) smt_mail($A, $MAIL[$A], $adr, [smt_ek('2', "$p$i.pdf", smt_pdf("ll-$p-$i"))], '2026-10-15 10:0' . $i . ':00');
}
smt_ayar([$A], ['lojistik' => ['fx@ornek.test']]);
$r = siparis_mail_baslat($db, 'lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
ok('baslat: toplam 8 ek, i=0, bitti değil', $r['ok'] && $r['toplam'] === 8 && $r['i'] === 0 && $r['bitti'] === false, json_encode($r));
$r1 = siparis_mail_adim($db, (string)$r['tarama_id'], 1, 'DEPO1', ['istemci' => smt_fabrika()]);
ok('adım başına en çok 6 ek → bitti=false, imleç 6', $r1['ok'] && $r1['i'] === 6 && $r1['bitti'] === false, json_encode($r1));
$yarim = siparis_mail_yarim($db, 1);
ok('siparis_mail_yarim: bu kullanıcının yarım taraması', $yarim !== null && $yarim['tarama_id'] === $r['tarama_id'] && $yarim['i'] === 6 && siparis_mail_yarim($db, 2) === null);
$bx = siparis_mail_adim($db, (string)$r['tarama_id'], 2, 'DEPO1', ['istemci' => smt_fabrika()]);
ok('başka kullanıcı devam EDEMEZ → 409', !$bx['ok'] && $bx['http'] === 409);
$bd = siparis_mail_adim($db, (string)$r['tarama_id'], 1, 'DEPO2', ['istemci' => smt_fabrika()]);
ok('depo değişince → 409 (sorguyu yeniden başlatın)', !$bd['ok'] && $bd['http'] === 409 && str_contains($bd['mesaj'], 'depo'));
$r2 = siparis_mail_adim($db, (string)$r['tarama_id'], 1, 'DEPO1', ['istemci' => smt_fabrika()]);
ok('2. adım tamamlar: bitti=true, kayıt silinir, sonuç saklanır', $r2['ok'] && $r2['bitti'] === true && siparis_mail_kv_oku($db, SIPARIS_MAIL_KV_TARAMA) === null && siparis_mail_son($db, 'lojistik')['durum'] === 'bitti');
$sayacX = $r2['son']['sayac'];
ok('toplam: 8 alındı', $sayacX['alindi'] === 8 && $sayacX['hata'] === 0, json_encode($sayacX));
// sahte saat: her çağrıda 25 sn → adım başına tek ek
smt_ayar([$A], ['lojistik' => ['fy@ornek.test']]);
$t = 0.0; $saat = function () use (&$t): float { $t += 25.0; return $t; };
$r = siparis_mail_baslat($db, 'lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
$adimlar = 0; $i0 = []; 
while ($r['ok'] && !$r['bitti'] && $adimlar < 30) { $r = siparis_mail_adim($db, (string)$r['tarama_id'], 1, 'DEPO1', ['istemci' => smt_fabrika(), 'saat' => $saat]); $adimlar++; $i0[] = $r['i']; }
ok('süre bütçesi: sahte saatle adım başına 1 ek (8 adım), imleç sürekli ilerler', $adimlar === 8 && $i0 === [1, 2, 3, 4, 5, 6, 7, 8], json_encode($i0));
ok('parçalı yürütmenin toplamları tek seferlik çalıştırmayla AYNI', $r['ok'] && $r['son']['sayac'] === $sayacX, json_encode($r['son']['sayac']) . ' vs ' . json_encode($sayacX));

// kira + vazgeç + kilit
$db->exec('DELETE FROM mail_messages');
smt_ayar([$A], ['lojistik' => ['fz@ornek.test']]);
for ($i = 1; $i <= 3; $i++) smt_mail($A, $MAIL[$A], 'fz@ornek.test', [smt_ek('2', "fz$i.pdf", smt_pdf("ll-fz-$i"))], '2026-10-15 11:0' . $i . ':00');
$IS_ADMIN = true;
$ra = siparis_mail_baslat($db, 'lojistik', 1, 'DEPO1', ['simdi' => '2026-10-18 12:00:00']);
$rb = siparis_mail_baslat($db, 'lojistik', 2, 'DEPO1', ['simdi' => '2026-10-18 12:00:30']);
ok('başka kullanıcının taraması 120 sn dolmadan → 409 (kullanıcı adı + süre)', $ra['ok'] && !$rb['ok'] && $rb['http'] === 409 && $rb['kod'] === 'mesgul' && str_contains($rb['mesaj'], 'Kullanıcı 1') && str_contains($rb['mesaj'], '30 sn'), json_encode($rb));
$rc = siparis_mail_baslat($db, 'lojistik', 2, 'DEPO1', ['simdi' => '2026-10-18 12:02:10']);
$eskiSon = siparis_mail_son($db, 'lojistik');
ok('kira dolunca (130 sn) yeni tarama alır; eskisi "yarım kaldı" olarak saklanır', $rc['ok'] && $rc['tarama_id'] !== $ra['tarama_id'] && ($eskiSon['durum'] ?? '') === 'yarim' && (int)$eskiSon['uid'] === 1);
ok('eski taramanın id\'siyle devam artık 409', !siparis_mail_adim($db, (string)$ra['tarama_id'], 1, 'DEPO1', ['istemci' => smt_fabrika()])['ok']);
$rv = siparis_mail_adim($db, (string)$rc['tarama_id'], 2, 'DEPO1', ['istemci' => smt_fabrika(), 'saat' => (function () { $t = 0.0; return function () use (&$t) { $t += 25.0; return $t; }; })()]);
$vz = siparis_mail_vazgec($db, (string)$rc['tarama_id'], 2);
$sonV = siparis_mail_son($db, 'lojistik');
ok('vazgeç: o ana kadarki sonuç saklanır (durum vazgecildi, 1 alındı), tarama kaydı silinir', $vz['ok'] && $sonV['durum'] === 'vazgecildi' && $sonV['sayac']['alindi'] === 1 && siparis_mail_kv_oku($db, SIPARIS_MAIL_KV_TARAMA) === null, json_encode($sonV['sayac']));
$fh = mail_kilit_al('siparis_mail');
$kk = siparis_mail_baslat($db, 'lojistik', 1, 'DEPO1', ['simdi' => $SIMDI]);
mail_kilit_birak($fh);
ok('kilit tutulurken → 409 (aynı anda tek tarama)', !$kk['ok'] && $kk['http'] === 409 && $kk['kod'] === 'mesgul', json_encode($kk));
$ta = $db->query("SELECT new_values FROM audit_log WHERE action = 'siparis_mail_tara' ORDER BY id DESC LIMIT 1")->fetchColumn();
ok('audit siparis_mail_tara: tür, gün, hesap sayısı, sayaç, süre, durum (adres yok)', $ta && str_contains((string)$ta, '"gun":15') && str_contains((string)$ta, '"durum"') && !str_contains((string)$ta, 'ornek.test'), (string)$ta);

echo "\n=== G. Posta kutusuna yazma YOK ===\n";
$tum = smt_komutlar();
$kotu = array_filter($tum, fn($c) => !preg_match('/^\S+ (LOGIN|AUTHENTICATE PLAIN|CAPABILITY|EXAMINE|LOGOUT|UID FETCH \d+ \(UID BODY\.PEEK\[\d+(\.\d+)*\]\))(\s|$)/', $c) && $c !== '[auth-data]');
ok('IMAP günlüğü: yalnız oturum açma/EXAMINE/UID FETCH BODY.PEEK[n]/LOGOUT (' . count($tum) . ' komut)', count($tum) > 10 && !$kotu, json_encode(array_values($kotu)));
ok('günlükte STORE / SELECT / COPY / EXPUNGE / APPEND yok', !array_filter($tum, fn($c) => (bool)preg_match('/\b(STORE|SELECT|COPY|EXPUNGE|APPEND|MOVE|DELETE)\b/i', $c)));
ok('günlükte \\Seen yazan komut yok', !array_filter($tum, fn($c) => str_contains($c, 'Seen')));
ok('mail_messages.is_read hiç değişmedi', $denetle('SELECT COUNT(*) FROM mail_messages WHERE is_read <> 0') === 0);

echo "\n=== H. Render ===\n";
$db->exec('DELETE FROM mail_messages');
$hGor = smt_mail($A, $MAIL[$A], 'log@ornek.test', [smt_ek('2', 'ok.pdf', smt_pdf('ll-h'))], '2026-10-16 08:00:00', ['subject' => '<script>alert(1)</script> Konu', 'from_name' => 'Ayşe <b>Yılmaz</b>']);
$hGiz = smt_mail($B, $MAIL[$B], 'log@ornek.test', [smt_ek('2', 'gizli.pdf', smt_pdf('ll-hg'))], '2026-10-16 08:05:00', ['subject' => 'GIZLI KONU', 'from_name' => 'Gizli Gönderen']);
$son = ['tarama_id' => 'x', 'uid' => 1, 'depo' => 'DEPO1', 'tur' => 'lojistik', 'basla' => strtotime($SIMDI), 'bitis' => strtotime($SIMDI), 'durum' => 'bitti',
    'sayac' => ['mail' => 2, 'pdf' => 2, 'alindi' => 1, 'zaten' => 0, 'onceden' => 1, 'atlandi' => 0, 'hata' => 0], 'pdfsiz' => 3, 'uyarilar' => ['Uyarı <i>metni</i>'],
    'satirlar' => [
        siparis_mail_satir(['m' => $hGor, 'p' => '2', 'dosya' => '<img src=x onerror=alert(2)>.pdf'], 'alindi', ['tur' => 'll', 'sevkiyatlar' => [['id' => 1, 'order_ref' => 'TC900001.10', 'yeni' => true]], 'mesajlar' => ['m<b>sg</b>']]),
        siparis_mail_satir(['m' => $hGiz, 'p' => '2', 'dosya' => 'gizli.pdf'], 'onceden', ['belge_id' => 777]),
        siparis_mail_satir(['m' => 999999, 'p' => '2', 'dosya' => 'yok.pdf'], 'hata', ['hata' => 'x <u>y</u>']),
    ]];
$html = sipui_mail_sonuc_html($db, $son, [$A]);
ok('görünmeyen kutunun maili: gönderen / konu "—", içerik SIZMAZ', !str_contains($html, 'GIZLI KONU') && !str_contains($html, 'Gizli Gönderen') && str_contains($html, 'gizli.pdf'));
ok('görünür kutunun gönderen adı ve konusu gösterilir (kaçışlı)', str_contains($html, 'Konu') && str_contains($html, 'Ayşe'));
ok('kullanıcı verisi KAÇIŞLI: ham <script> / <img onerror> / <b> yok', !str_contains($html, '<script>') && !str_contains($html, '<img') && !str_contains($html, '<b>Yılmaz') && !str_contains($html, '<i>metni') && !str_contains($html, '<u>y</u>') && str_contains($html, '&lt;script&gt;'));
$denge = true; $detay = '';
foreach (['div', 'section', 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'a', 'strong', 'span', 'p', 'button'] as $t) {
    $ac = preg_match_all('/<' . $t . '[\s>]/', $html); $ka = preg_match_all('/<\/' . $t . '>/', $html);
    if ($ac !== $ka) { $denge = false; $detay .= " $t:$ac/$ka"; }
}
ok('HTML etiket dengesi', $denge, $detay);
ok('başlık: tür · son 15 gün · tarih · kullanıcı', str_contains($html, 'Mail sorgusu — Lojistik · son 15 gün · 18.10.2026 12:00 · Kullanıcı 1'));
ok('"Daha önce alınmış" satırları gizli (düğme + sınıf)', str_contains($html, 'data-sip-mail-onceden') && str_contains($html, 'sip-mail-onceden'));
ok('özet çipleri + PDF eki olmayan mail sayısı', str_contains($html, 'Bulunan mail') && str_contains($html, 'PDF eki olmayan 3 mail'));
ok('görünür belge bağlantısı yalnız görünür/varlıksız belge için verilmez (777 yok → düz metin)', !str_contains($html, 'siparis_belge.php?id=777'));
$blok = sipui_mail_blok_html(['hazir' => true, 'ayarli' => ['siparis' => true, 'lojistik' => false, 'fiyat' => true], 'ayar_link' => true, 'mail_admin' => true, 'yarim' => ['tarama_id' => 'abcd', 'tur' => 'lojistik', 'i' => 5, 'toplam' => 12], 'son' => []]);
ok('blok: 3 düğme, ayarsız olan pasif', substr_count($blok, 'data-sip-mail="') === 3 && (bool)preg_match('/data-sip-mail="lojistik" disabled/', $blok) && !preg_match('/data-sip-mail="siparis" disabled/', $blok));
ok('blok: yarım tarama şeridi (Yarım kalan mail sorgusu (Lojistik, 5/12))', str_contains($blok, 'Yarım kalan mail sorgusu (Lojistik, 5/12)') && str_contains($blok, 'data-sip-mail-devam="abcd"'));
ok('blok: hazır değilse düğme YOK; yalnız mail yöneticisine not', !str_contains(sipui_mail_blok_html(['hazir' => false, 'mail_admin' => false]), 'data-sip-mail') && sipui_mail_blok_html(['hazir' => false, 'mail_admin' => false]) === '' && str_contains(sipui_mail_blok_html(['hazir' => false, 'mail_admin' => true]), 'kurulu değil'));

echo "\n=== I. Ortak yazma yolu ===\n";
$k = siparis_dosya_kaydet($db, siparis_test_dosya(smt_pdf('po-kaynak1'), 'k1.pdf'), 1, 'DEPO1', siparis_test_mover(), 'mail');
$k2 = siparis_dosya_kaydet($db, siparis_test_dosya(smt_pdf('po-kaynak2'), 'k2.pdf'), 1, 'DEPO1', siparis_test_mover(), 'saldirgan');
$k3 = siparis_dosya_kaydet($db, siparis_test_dosya(smt_pdf('po-kaynak3'), 'k3.pdf'), 1, 'DEPO1', siparis_test_mover());
$kk = fn($id) => (string)$db->query('SELECT kaynak FROM siparis_belgeler WHERE id = ' . (int)$id)->fetchColumn();
ok('siparis_dosya_kaydet kaynak beyaz liste: mail kabul, bilinmeyen/varsayılan → yukleme', $kk($k['belge_id']) === 'mail' && $kk($k2['belge_id']) === 'yukleme' && $kk($k3['belge_id']) === 'yukleme');
$hata = siparis_dosya_yukle_isle($db, siparis_test_dosya('PDF degil', 'x.pdf'), 1, 'DEPO1', null, siparis_test_mover());
ok('ortak fonksiyon: geçersiz dosya → hata durumu (elle yükleme ile aynı metin)', $hata['durum'] === 'hata' && $hata['hata'] === 'Dosya içeriği uzantısıyla uyuşmuyor.', json_encode($hata));
$src = file_get_contents($ROOT . '/config/siparis_mail.php');
ok('config/siparis_mail.php siparis_dosya_kaydet / siparis_belge_isle\'yi DOĞRUDAN çağırmıyor (tek yazma yolu)', !preg_match('/\b(siparis_dosya_kaydet|siparis_belge_isle)\s*\(/', $src));

siparis_test_bitir();
