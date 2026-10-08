<?php
// =========================================================
// scripts/mail_translate_smoke.php — Çeviri katmanı (M4): dil tespiti, metin hazırlama, parçalama, sağlayıcılar
// (sahte HTTP), kuyruk/yeniden deneme/duraklatma, veri çıkışı minimizasyonu, mail kaybolmuyor.
//   php scripts/mail_translate_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
require_once __DIR__ . '/_mail_test_lib.php';
require_once $ROOT . '/config/mail_imap.php';
require_once $ROOT . '/config/mail_mime.php';
require_once $ROOT . '/config/mail_sync.php';
require_once $ROOT . '/config/mail_view.php';
require_once $ROOT . '/config/mail_translate.php';
require_once __DIR__ . '/_mail_fake_imap.php';

$db = db(); mail_test_diger_tablolar($db); mail_test_sema_kur($db); mail_test_anahtar_kur();
$DIZIN = sys_get_temp_dir() . '/mail_tr_' . getmypid(); @mkdir($DIZIN, 0700, true);

echo "=== 1. Yerel dil tespiti ===\n";
$ornekler = [
    'en' => "Dear Sir, please find attached the payment confirmation for your order. We will ship the goods with the next vessel. Best regards",
    'tr' => "Sayın yetkili, siparişiniz için ödeme yapılmıştır. Lütfen yüklemeyi bir an önce planlayınız. İyi günler dileriz, saygılarımızla",
    'ru' => "Здравствуйте! Подтверждаем оплату заказа и просим прислать документы на груз как можно скорее. С уважением",
    'uk' => "Вітаю! Підтверджуємо оплату замовлення, будь ласка, надішліть документи на вантаж якомога швидше, їх потрібно ще сьогодні",
    'de' => "Sehr geehrte Damen und Herren, anbei erhalten Sie die Zahlung für die Bestellung. Bitte bestätigen Sie den Versand. Freundliche Grüße",
    'fr' => "Bonjour, veuillez trouver ci-joint le paiement de la commande. Nous vous remercions pour votre confiance. Cordialement",
    'es' => "Estimado cliente, adjunto el pago del pedido. Por favor confirme el envío de la mercancía. Gracias y saludos cordiales",
    'it' => "Gentile cliente, in allegato il pagamento dell'ordine. Grazie per la vostra fiducia, cordiali saluti, buongiorno",
    'ar' => "مرحبا، نرجو تأكيد استلام الدفعة الخاصة بالطلب وإرسال الوثائق في أقرب وقت ممكن، شكرا لكم",
    'fa' => "سلام، لطفاً پرداخت سفارش را تأیید کنید و اسناد را هرچه زودتر برای ما بفرستید، چگونه است",
    'he' => "שלום רב, אנא אשרו את קבלת התשלום עבור ההזמנה ושלחו את המסמכים בהקדם האפשרי",
    'el' => "Καλησπέρα σας, παρακαλούμε επιβεβαιώστε την πληρωμή της παραγγελίας και στείλτε τα έγγραφα",
    'zh' => "您好，请确认订单付款已收到，并尽快发送货物相关文件，谢谢您的合作与支持。",
    'ja' => "お世話になっております。ご注文のお支払いを確認いただき、書類をお送りください。よろしくお願いいたします。",
    'ko' => "안녕하세요. 주문 결제를 확인해 주시고 서류를 가능한 빨리 보내주시기 바랍니다. 감사합니다.",
    'nl' => "Geachte heer, hierbij de betaling voor de bestelling. Wij verzoeken u de verzending te bevestigen. Bedankt en groeten",
    'pl' => "Szanowni państwo, w załączniku płatność za zamówienie. Proszę o potwierdzenie wysyłki. Dziękuję i pozdrawiam",
];
foreach ($ornekler as $dil => $metin) ok("dil tespiti: $dil", mail_dil_tespit($metin) === $dil, 'bulunan: ' . var_export(mail_dil_tespit($metin), true));
ok('çok kısa metin → null', mail_dil_tespit('ok') === null && mail_dil_tespit('') === null);
ok('belirsiz/rakam metni → null', mail_dil_tespit('12345 67890 111 222 333 444') === null);
ok('karışık yazı sistemi → null', mail_dil_tespit('Hello Привет 你好 مرحبا שלום Γεια xyz abc def') === null);
ok('yalnız imza/kod parçası yanlış dil uydurmaz', mail_dil_tespit('Tel: +90 555 123 45 67 / Fax: +90 212') === null);

echo "\n=== 2. Metin hazırlama (alıntı/veri çıkışı) ===\n";
$h = mail_ceviri_hazirla("Merhaba,\nSipariş onaylandı.\n\nOn Mon, 5 Oct 2026 at 10:00, Ivan <ivan@x.ru> wrote:\n> eski mesaj\n> ikinci satır\nimza");
ok('"… wrote:" ve sonrası kırpılır', $h['metin'] === "Merhaba,\nSipariş onaylandı." && $h['kirpildi']);
$h = mail_ceviri_hazirla("Yeni cevap\n> alıntı satırı\nson");
ok('"> " alıntı satırları atılır', $h['metin'] === "Yeni cevap\nson" && $h['kirpildi']);
$h = mail_ceviri_hazirla("Hello\n-----Original Message-----\nFrom: a@b.com\nSecret old stuff");
ok('Original Message ayracı sonrası kırpılır', $h['metin'] === 'Hello' && !str_contains($h['metin'], 'Secret'));
$h = mail_ceviri_hazirla("> yalnız alıntı\n> başka");
ok('yalnız alıntıdan oluşan mail boş kalmaz (içerik korunur)', trim($h['metin']) !== '');
$h = mail_ceviri_hazirla(str_repeat('a', 13000));
ok('12 000 karakter sınırı + kırpıldı bayrağı', mb_strlen($h['metin']) === MAIL_CEVIRI_MAX_KARAKTER && $h['kirpildi']);
$h = mail_ceviri_hazirla("Satır\r\nsonu\r\n\r\n\r\n\r\nçok boşluk");
ok('CRLF + fazla boş satır toparlanır', $h['metin'] === "Satır\nsonu\n\nçok boşluk");

echo "\n=== 3. Parçalama ===\n";
$uzun = implode("\n\n", array_map(fn($i) => "Paragraf $i. " . str_repeat('Bu bir cümledir. ', 12), range(1, 6)));
foreach ([450, 1000, 8000] as $lim) {
    $p = mail_ceviri_parcala($uzun, $lim);
    $hepsi = true; $birlesik = '';
    foreach ($p as [$t, $a]) { if (strlen($t) > $lim) $hepsi = false; $birlesik .= $t . $a; }
    ok("parçalama limit=$lim: her parça ≤ limit", $hepsi && count($p) >= 1);
    ok("parçalama limit=$lim: birleşince içerik KAYBOLMUYOR (boşluk farkı hariç)", preg_replace('/\s+/', ' ', trim($birlesik)) === preg_replace('/\s+/', ' ', trim($uzun)), substr($birlesik, 0, 80));
}
$p = mail_ceviri_parcala(str_repeat('x', 1000), 300);
ok('boşluksuz dev kelime bayttan bölünür, her parça ≤ limit', count($p) >= 4 && !array_filter($p, fn($x) => strlen($x[0]) > 300));
$p = mail_ceviri_parcala(str_repeat('Çağrı ', 400), 100);
ok('çok baytlı karakterler ORTADAN bölünmez (geçerli UTF-8)', !array_filter($p, fn($x) => !mb_check_encoding($x[0], 'UTF-8')));
ok('boş metin → parça yok', mail_ceviri_parcala('', 100) === []);

echo "\n=== 4. Sağlayıcılar (sahte HTTP) ===\n";
$KAYIT = [];
$sahte = function (array $yanit) use (&$KAYIT) {
    return function (string $metod, string $url, array $baslik, ?string $govde, float $t) use (&$KAYIT, $yanit) { $KAYIT[] = compact('metod', 'url', 'baslik', 'govde'); return $yanit; };
};
$sirDeepl = 'abcd1234-secret-key:fx';
$d = new MailTranslateDeepL($sirDeepl, $sahte(['kod' => 200, 'govde' => json_encode(['translations' => [['detected_source_language' => 'EN', 'text' => 'Merhaba dünya']]])]));
$r = $d->cevir('Hello world', null, 'tr');
ok('DeepL: çeviri + tespit edilen dil', $r === ['metin' => 'Merhaba dünya', 'tespit' => 'en']);
$k = $KAYIT[0];
ok('DeepL: free uç + Authorization başlığı + POST form (source_lang yok)', str_starts_with($k['url'], 'https://api-free.deepl.com/') && in_array('Authorization: DeepL-Auth-Key ' . $sirDeepl, $k['baslik'], true) && str_contains($k['govde'], 'target_lang=TR') && !str_contains($k['govde'], 'source_lang'));
$KAYIT = []; $d->cevir('Hi', 'en', 'tr');
ok('DeepL: kaynak dil verilirse source_lang=EN', str_contains($KAYIT[0]['govde'], 'source_lang=EN'));
$KAYIT = []; (new MailTranslateDeepL('pro-key', $sahte(['kod' => 200, 'govde' => '{"translations":[{"text":"x"}]}'])))->cevir('Hi', 'tr', 'en');
ok('DeepL: pro anahtar → api.deepl.com, EN hedefi EN-GB', str_starts_with($KAYIT[0]['url'], 'https://api.deepl.com/') && str_contains($KAYIT[0]['govde'], 'target_lang=EN-GB'));
foreach ([403 => 'config', 401 => 'config', 456 => 'quota', 429 => 'temp', 500 => 'temp', 503 => 'temp', 400 => 'perm', 413 => 'perm'] as $kod => $tur) {
    $e = null; try { (new MailTranslateDeepL('k:fx', $sahte(['kod' => $kod, 'govde' => 'x'])))->cevir('a', null, 'tr'); } catch (MailTranslateException $x) { $e = $x; }
    ok("DeepL: HTTP $kod → $tur", $e && $e->kind === $tur);
}
$e = null; try { (new MailTranslateDeepL('k:fx', $sahte(['kod' => 200, 'govde' => 'bozuk json'])))->cevir('a', null, 'tr'); } catch (MailTranslateException $x) { $e = $x; }
ok('DeepL: bozuk yanıt → temp (çökmez)', $e && $e->kind === 'temp');
$e = null; try { (new MailTranslateDeepL($sirDeepl, $sahte(['kod' => 403, 'govde' => "key $sirDeepl invalid"])))->cevir('a', null, 'tr'); } catch (MailTranslateException $x) { $e = $x; }
ok('DeepL: hata mesajında ANAHTAR YOK (yanıt gövdesi yansıtılsa bile)', $e && !str_contains($e->getMessage(), $sirDeepl) && !str_contains(mail_redact($e->getMessage()), $sirDeepl));

$KAYIT = [];
$l = new MailTranslateLibre('https://lt.example.org/', 'LT-ANAHTAR-99', $sahte(['kod' => 200, 'govde' => '{"translatedText":"Selam","detectedLanguage":{"language":"en"}}']));
$r = $l->cevir('Hi', null, 'tr');
$g = json_decode($KAYIT[0]['govde'], true);
ok('Libre: /translate, source=auto, format=text, api_key gövdede', $KAYIT[0]['url'] === 'https://lt.example.org/translate' && $g['source'] === 'auto' && $g['format'] === 'text' && $g['api_key'] === 'LT-ANAHTAR-99' && $r === ['metin' => 'Selam', 'tespit' => 'en']);
$e = null; try { (new MailTranslateLibre('https://x.org', '', $sahte(['kod' => 400, 'govde' => '{"error":"Russian is not supported"}'])))->cevir('a', 'ru', 'tr'); } catch (MailTranslateException $x) { $e = $x; }
ok('Libre: 400 → perm', $e && $e->kind === 'perm');

$KAYIT = [];
$mm = new MailTranslateMyMemory('ben@example.com', $sahte(['kod' => 200, 'govde' => json_encode(['responseData' => ['translatedText' => 'Merhaba &amp; selam'], 'responseStatus' => 200])]));
$r = $mm->cevir('Hello & hi', 'en', 'tr');
ok('MyMemory: GET + langpair + de=e-posta, HTML varlıkları çözülür', str_contains($KAYIT[0]['url'], 'langpair=en%7Ctr') && str_contains($KAYIT[0]['url'], 'de=ben%40example.com') && $KAYIT[0]['metod'] === 'GET' && $r['metin'] === 'Merhaba & selam');
foreach ([['quotaFinished' => true, 'responseData' => ['translatedText' => 'x'], 'responseStatus' => 200], ['responseData' => ['translatedText' => 'MYMEMORY WARNING: YOU USED ALL'], 'responseStatus' => 200], ['responseData' => ['translatedText' => ''], 'responseStatus' => 429]] as $i => $y) {
    $e = null; try { (new MailTranslateMyMemory('', $sahte(['kod' => 200, 'govde' => json_encode($y)])))->cevir('a', 'en', 'tr'); } catch (MailTranslateException $x) { $e = $x; }
    ok("MyMemory: kota işareti #$i → quota", $e && $e->kind === 'quota');
}
ok('MyMemory: parça limiti 450 bayt (API sınırı)', (new MailTranslateMyMemory())->parcaLimiti() === 450);

// DeepSeek: ayrı Chat Completions adaptörü; dış ağ yerine sahte HTTP ile sözleşme testi.
$KAYIT = [];
$sirDeepseek = 'sk-test-private-do-not-print';
$ds = new MailTranslateDeepSeek($sirDeepseek, $sahte(['kod' => 200, 'govde' => json_encode([
    'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Merhaba, siparişiniz onaylandı.']]],
])])); 
$r = $ds->cevir('Hello, your order was approved.', 'en', 'tr');
$k = $KAYIT[0]; $json = json_decode((string)$k['govde'], true);
ok('DeepSeek: Chat Completions / bearer / POST JSON', $k['metod'] === 'POST'
    && $k['url'] === 'https://api.deepseek.com/chat/completions'
    && in_array('Authorization: Bearer ' . $sirDeepseek, $k['baslik'], true)
    && $json['model'] === 'deepseek-flash'
    && ($json['thinking']['type'] ?? '') === 'disabled'
    && ($json['stream'] ?? true) === false);
ok('DeepSeek: gelen İngilizce→Türkçe, yalnız metin yanıtı',
    $r === ['metin' => 'Merhaba, siparişiniz onaylandı.', 'tespit' => null]
    && str_contains($json['messages'][1]['content'], 'Target language: Turkish')
    && str_contains($json['messages'][1]['content'], 'Source language: English'));
$KAYIT = []; $ds->cevir('Sipariş onaylandı.', 'tr', 'ru');
$json = json_decode((string)$KAYIT[0]['govde'], true);
ok('DeepSeek: giden Türkçe→Rusça seçilen dil; adres/ek/kimlik metadata alanı YOK',
    str_contains($json['messages'][1]['content'], 'Source language: Turkish')
    && str_contains($json['messages'][1]['content'], 'Target language: Russian')
    && !isset($json['user_id'], $json['attachments'])
    && !str_contains($KAYIT[0]['govde'], 'imap_pass')
    && !str_contains($KAYIT[0]['govde'], 'smtp_pass'));
$KAYIT = []; $ds->cevir('Hello!', null, 'tr');
ok('DeepSeek: kaynak dil bilinmiyorsa auto-detect', str_contains((string)$KAYIT[0]['govde'], 'Source language: auto-detect'));
ok('DeepSeek: parça limiti 3500 bayt', $ds->parcaLimiti() === 3500);
foreach ([401=>'config', 403=>'config', 402=>'quota', 429=>'temp', 500=>'temp', 400=>'config', 422=>'config'] as $kod=>$kind) {
    $e = null;
    try { (new MailTranslateDeepSeek('sk-test', $sahte(['kod' => $kod, 'govde' => 'failed'])))->cevir('Hi', 'en', 'tr'); }
    catch (MailTranslateException $ex) { $e = $ex; }
    ok("DeepSeek: HTTP $kod → $kind", $e && $e->kind === $kind);
}
foreach ([
    '{"choices":[]}' => 'eksik içerik',
    '{"choices":[{"finish_reason":"length","message":{"content":"Kısmi"}}]}' => 'kesilmiş çıktı',
    'bozuk-json' => 'JSON hatası',
] as $cevap=>$ad) {
    $e = null;
    try { (new MailTranslateDeepSeek('sk-test', $sahte(['kod' => 200, 'govde' => $cevap])))->cevir('Hi', 'en', 'tr'); }
    catch (MailTranslateException $ex) { $e = $ex; }
    ok("DeepSeek: $ad fail-closed", $e && $e->kind === 'temp');
}
$e = null;
try { (new MailTranslateDeepSeek('sk-test', $sahte(['kod' => 200, 'govde' => '{"choices":[]}'])))->cevir('a', 'en', 'unsupported'); }
catch (MailTranslateException $ex) { $e = $ex; }
ok('DeepSeek: desteklenmeyen hedef reddedildi', $e && $e->kind === 'config');
$e = null;
try { new MailTranslateDeepSeek(' '); } catch (MailTranslateException $ex) { $e = $ex; }
ok('DeepSeek: boş key reddedilir', $e && $e->kind === 'config');

echo "\n=== 5. Sağlayıcı fabrikası + HTTP güvenliği ===\n";
ok('varsayılan/boş/bilinmeyen → null (KAPALI, veri çıkmaz)', mail_ceviri_saglayici(['provider' => 'none']) === null && mail_ceviri_saglayici(['provider' => '']) === null && mail_ceviri_saglayici(['provider' => 'google-x']) === null);
ok('deepseek anahtarsız → null; anahtarla deepseek provider', mail_ceviri_saglayici(['provider' => 'deepseek', 'key' => '']) === null && mail_ceviri_saglayici(['provider' => 'deepseek', 'key' => 'sk-test'])?->ad() === 'deepseek');
ok('deepl anahtarsız → null', mail_ceviri_saglayici(['provider' => 'deepl', 'key' => '']) === null);
ok('deepl anahtarla → sağlayıcı', mail_ceviri_saglayici(['provider' => 'DeepL', 'key' => 'k:fx'])?->ad() === 'deepl');
ok('libretranslate http:// (düz metin) REDDEDİLİR', mail_ceviri_saglayici(['provider' => 'libretranslate', 'url' => 'http://lt.local']) === null && mail_ceviri_saglayici(['provider' => 'libretranslate', 'url' => 'https://lt.local'])?->ad() === 'libretranslate');
ok('mymemory anahtarsız çalışır; geçersiz e-posta yok sayılır', mail_ceviri_saglayici(['provider' => 'mymemory', 'email' => 'bozuk'])?->ad() === 'mymemory');
$e = null; try { mail_http_istek('GET', 'http://example.com/', [], null); } catch (MailTranslateException $x) { $e = $x; }
ok('mail_http_istek: http:// adresine İSTEK YAPILMAZ', $e && $e->kind === 'config');
$e = null; try { mail_http_istek('GET', 'file:///etc/passwd', [], null); } catch (MailTranslateException $x) { $e = $x; }
ok('mail_http_istek: file:// reddedilir', $e && $e->kind === 'config');
$src = php_strip_whitespace($ROOT . '/config/mail_translate.php');
ok('HTTP: yönlendirme takip edilmez, yalnız HTTPS protokolü, TLS doğrulamalı', str_contains($src, 'CURLOPT_FOLLOWLOCATION=>false') || str_contains($src, 'CURLOPT_FOLLOWLOCATION => false'));
ok('HTTP: CURLPROTO_HTTPS + VERIFYPEER + VERIFYHOST=2', str_contains($src, 'CURLOPT_PROTOCOLS => CURLPROTO_HTTPS') && str_contains($src, 'CURLOPT_SSL_VERIFYPEER => true') && str_contains($src, 'CURLOPT_SSL_VERIFYHOST => 2'));
ok('kapalı sağlayıcı özeti', str_contains(mail_ceviri_yapilandirma_ozeti(), 'KAPALI'));

echo "\n=== 6. Kuyruk ===\n";
function hesap(string $e, array $ek = []): int {
    global $db;
    return mail_hesap_kaydet(array_merge(['label' => $e, 'email' => $e, 'imap_host' => 'i.t.com', 'imap_user' => $e, 'imap_pass' => 'p1-x', 'smtp_host' => 's.t.com', 'smtp_user' => $e, 'smtp_pass' => 'p2-x', 'translate_enabled' => 1], $ek), null, 1, $db)['id'];
}
$NOW = strtotime('2026-10-06 12:00:00'); $simdi = date('Y-m-d H:i:s', $NOW);
function mesaj(int $acc, string $konu, string $govde, array $o = []): int {
    global $db, $simdi;
    static $uid = 0; $uid++;
    $o += ['tarih' => date('Y-m-d H:i:s', strtotime('2026-10-06 11:00:00')), 'lang' => null, 'tr' => 'pending', 'addr' => 'ivan.private@musteri.ru', 'to' => 'info@asya.com'];
    $db->prepare("INSERT INTO mail_messages (account_id, folder, uidvalidity, uid, message_id_hash, subject, from_name, from_addr, received_at, body_text, lang, tr_status, to_addrs, cc_addrs)
        VALUES (?, 'INBOX', 1, ?, ?, ?, 'Ivan Private Name', ?, ?, ?, ?, ?, ?, '[]')")->execute([$acc, $uid, sha1("m$uid"), $konu, $o['addr'], $o['tarih'], $govde, $o['lang'], $o['tr'], json_encode([['name' => 'X', 'email' => $o['to']]])]);
    return (int)$db->lastInsertId();
}
final class SahteSaglayici implements MailTranslationProviderInterface {
    public array $cagrilar = []; public $davranis;
    public function __construct(?callable $d = null) { $this->davranis = $d ?? fn($metin, $k, $h) => ['metin' => 'TR:' . $metin, 'tespit' => 'en']; }
    public function ad(): string { return 'sahte'; }
    public function parcaLimiti(): int { return 120; }
    public function cevir(string $metin, ?string $kaynak, string $hedef): array { $this->cagrilar[] = [$metin, $kaynak, $hedef]; return ($this->davranis)($metin, $kaynak, $hedef); }
}
function satir(int $id): array { global $db; return $db->query("SELECT * FROM mail_messages WHERE id = $id")->fetch(); }
$A = hesap('ceviri@asya.com'); $B = hesap('kapali@asya.com', ['translate_enabled' => 0]);
$m1 = mesaj($A, 'Payment confirmation', "Dear Sir, please find attached the payment confirmation for your order. Best regards\n\nOn Mon, 5 Oct 2026, Ivan <ivan.private@musteri.ru> wrote:\n> eski özel yazışma GİZLİ");
$m2 = mesaj($A, 'Sipariş', 'Sayın yetkili, siparişiniz için ödeme yapılmıştır. Lütfen yüklemeyi planlayınız, iyi günler dileriz.');
$m3 = mesaj($B, 'Kapalı hesap', 'Dear Sir, please confirm the order and payment for goods. Thanks and regards');
$m4 = mesaj($A, 'Eski mail', 'Dear Sir, please find the payment attached for the order. Best regards', ['tarih' => '2026-09-01 10:00:00']);
$sag = new SahteSaglayici();
$r = mail_ceviri_isle($db, $sag, ['dizin' => $DIZIN, 'simdi' => $NOW]);
$s1 = satir($m1);
ok('pending İngilizce mail çevrildi', $s1['tr_status'] === 'translated' && str_contains($s1['body_tr'], 'TR:') && $s1['subject_tr'] === 'TR:Payment confirmation' && $s1['lang'] === 'en', json_encode($s1));
ok('zaten Türkçe mail çeviri GÖNDERİLMEDEN skipped', satir($m2)['tr_status'] === 'skipped' && satir($m2)['lang'] === 'tr' && !array_filter($sag->cagrilar, fn($c) => str_contains($c[0], 'Sayın yetkili')));
ok('çeviri kapalı hesabın maili DOKUNULMADI', satir($m3)['tr_status'] === 'pending' && !array_filter($sag->cagrilar, fn($c) => str_contains($c[0], 'Kapalı') || str_contains($c[0], 'confirm the order')));
ok('7 günden eski mail çevrilmez (skipped)', satir($m4)['tr_status'] === 'skipped');
ok('sonuç sayıları', $r['ceviri'] === 1 && $r['atlandi'] >= 1 && $r['durum'] === 'ok', json_encode($r));
$gidenTumu = implode("\n", array_map(fn($c) => $c[0], $sag->cagrilar));
ok('VERİ ÇIKIŞI: alıntılanan eski yazışma sağlayıcıya GİTMEDİ', !str_contains($gidenTumu, 'GİZLİ') && !str_contains($gidenTumu, 'wrote'));
ok('VERİ ÇIKIŞI: e-posta adresi / gönderen adı / alıcı sağlayıcıya GİTMEDİ', !str_contains($gidenTumu, 'ivan.private') && !str_contains($gidenTumu, 'Ivan Private') && !str_contains($gidenTumu, 'info@asya.com') && !str_contains($gidenTumu, '@'));
ok('her istek parça limitini (120 bayt) aşmıyor', !array_filter($sag->cagrilar, fn($c) => strlen($c[0]) > 120));
ok('kaynak dil yerel tespitle verildi, hedef tr', array_unique(array_column($sag->cagrilar, 1)) === ['en'] && array_unique(array_column($sag->cagrilar, 2)) === ['tr']);
$say = count($sag->cagrilar);
mail_ceviri_isle($db, $sag, ['dizin' => $DIZIN, 'simdi' => $NOW]);
ok('çevrilmiş mail ikinci turda YENİDEN çevrilmez (kota)', count($sag->cagrilar) === $say);

echo "\n=== 7. Hata türleri: mail kaybolmaz ===\n";
$m5 = mesaj($A, 'Temp hata', 'Dear Sir, please confirm the payment for the order that we have discussed. Best regards');
$sag = new SahteSaglayici(fn() => throw new MailTranslateException('temp', 'Çeviri servisi hatası (HTTP 503).'));
$r = mail_ceviri_isle($db, $sag, ['dizin' => $DIZIN, 'simdi' => $NOW]);
$s5 = satir($m5);
ok('geçici hata: pending kalır, deneme=1, bekleme zamanı yazıldı, hata metni var', $s5['tr_status'] === 'pending' && (int)$s5['tr_attempts'] === 1 && $s5['tr_next_at'] === date('Y-m-d H:i:s', $NOW + 300) && str_contains($s5['tr_error'], '503'), json_encode($s5));
ok('MAİL ERİŞİLEBİLİR KALDI (orijinal gövde + konu sağlam)', str_contains($s5['body_text'], 'confirm the payment') && $s5['subject'] === 'Temp hata');
ok('mail listede/okuyucuda açılabiliyor (mail_mesaj_getir)', mail_mesaj_getir($db, $m5, [$A])['subject'] === 'Temp hata' && in_array($m5, array_column(mail_mesaj_listele($db, [$A], 'gelen', '', 1)['satirlar'], 'id')));
$n = count($sag->cagrilar);
mail_ceviri_isle($db, $sag, ['dizin' => $DIZIN, 'simdi' => $NOW + 100]);
ok('bekleme dolmadan YENİDEN DENENMEZ', count($sag->cagrilar) === $n);
foreach ([2 => 900, 3 => 3600, 4 => 14400] as $deneme => $bekleme) {
    $t = $NOW + 50000 * $deneme;
    mail_ceviri_isle($db, $sag, ['dizin' => $DIZIN, 'simdi' => $t]);
    $s = satir($m5);
    ok("deneme $deneme: geri çekilme $bekleme sn", (int)$s['tr_attempts'] === $deneme && $s['tr_next_at'] === date('Y-m-d H:i:s', $t + $bekleme) && $s['tr_status'] === 'pending', json_encode([$s['tr_attempts'], $s['tr_next_at']]));
}
mail_ceviri_isle($db, $sag, ['dizin' => $DIZIN, 'simdi' => $NOW + 250000]);
$s5 = satir($m5);
ok('5. başarısız denemede failed (sonsuz döngü yok), mail hâlâ okunabilir', $s5['tr_status'] === 'failed' && (int)$s5['tr_attempts'] === 5 && str_contains($s5['body_text'], 'confirm the payment'));
$n = count($sag->cagrilar);
mail_ceviri_isle($db, $sag, ['dizin' => $DIZIN, 'simdi' => $NOW + 300000]);
ok('failed mail otomatik tekrar denenmez', count($sag->cagrilar) === $n);

$m6 = mesaj($A, 'Kalıcı hata', 'Dear Sir, we kindly ask you to confirm the payment for the order. Best regards');
$sag = new SahteSaglayici(fn() => throw new MailTranslateException('perm', 'Dil desteklenmiyor.'));
mail_ceviri_isle($db, $sag, ['dizin' => $DIZIN, 'simdi' => $NOW]);
ok('kalıcı hata: ilk denemede failed', satir($m6)['tr_status'] === 'failed' && (int)satir($m6)['tr_attempts'] === 1);

$m7 = mesaj($A, 'Anahtar hatası', 'Dear Sir, we kindly ask you to confirm the payment for the order. Best regards');
$m8 = mesaj($A, 'Sonraki', 'Dear Sir, we kindly ask you to send the documents for the order. Best regards');
$sag = new SahteSaglayici(fn() => throw new MailTranslateException('config', 'Çeviri servisi kimlik doğrulamasını reddetti (HTTP 403) — anahtarı kontrol edin.'));
$r = mail_ceviri_isle($db, $sag, ['dizin' => $DIZIN, 'simdi' => $NOW]);
ok('yapılandırma/anahtar hatası: kuyruk DURUR, mailler pending KALIR, deneme hakkı yenmez', $r['durum'] === 'yapilandirma_hatasi' && satir($m7)['tr_status'] === 'pending' && (int)satir($m7)['tr_attempts'] === 0 && satir($m8)['tr_status'] === 'pending' && count($sag->cagrilar) === 1);
ok('durum dosyası yönetici için yazıldı', (mail_ceviri_durum_oku($DIZIN)['durum'] ?? '') === 'yapilandirma_hatasi');

$sag = new SahteSaglayici(fn() => throw new MailTranslateException('quota', 'Çeviri kotası doldu.'));
$r = mail_ceviri_isle($db, $sag, ['dizin' => $DIZIN, 'simdi' => $NOW]);
ok('kota: kuyruk duraklar, deneme hakkı yenmez', $r['durum'] === 'kota' && (int)satir($m7)['tr_attempts'] === 0);
$sag2 = new SahteSaglayici();
$r = mail_ceviri_isle($db, $sag2, ['dizin' => $DIZIN, 'simdi' => $NOW + 60]);
ok('duraklatılmışken sağlayıcıya HİÇ istek gitmez', $r['durum'] === 'duraklatildi' && $sag2->cagrilar === []);
$r = mail_ceviri_isle($db, $sag2, ['dizin' => $DIZIN, 'simdi' => $NOW + 7 * 3600]);
ok('duraklatma süresi dolunca devam eder, bekleyenler çevrilir', $r['durum'] === 'ok' && satir($m7)['tr_status'] === 'translated' && satir($m8)['tr_status'] === 'translated');

$r = mail_ceviri_isle($db, null, ['dizin' => $DIZIN, 'simdi' => $NOW]);
ok('sağlayıcı yoksa (kapalı) hiçbir şey yapılmaz', $r['durum'] === 'kapali');

echo "\n=== 8. Sağlayıcı kendi dilini bildirirse ===\n";
$m9 = mesaj($A, 'x', 'Kısa xyz', ['lang' => null]);   // yerel tespit edemez
$sag = new SahteSaglayici(fn($t) => ['metin' => $t, 'tespit' => 'tr']);
mail_ceviri_isle($db, $sag, ['dizin' => $DIZIN, 'simdi' => $NOW + 8 * 3600]);
ok('sağlayıcı "zaten Türkçe" derse çeviri saklanmaz, skipped', satir($m9)['tr_status'] === 'skipped' && satir($m9)['lang'] === 'tr' && $db->query("SELECT body_tr FROM mail_messages WHERE id = $m9")->fetchColumn() === null);

echo "\n=== 9. Elle çeviri (Şimdi çevir / Tekrar dene) + ACL ===\n";
$X = hesap('acl-a@asya.com'); $Y = hesap('acl-b@asya.com');
$mx = mesaj($X, 'ACL', 'Dear Sir, please confirm the payment for the order and ship the goods. Best regards', ['tr' => 'failed']);
$my = mesaj($Y, 'ACL B', 'Dear Sir, please confirm the payment for the order and ship the goods. Best regards', ['tr' => 'skipped']);
$sag = new SahteSaglayici();
$r = mail_ceviri_simdi($db, $sag, $mx, [$X], $NOW);
ok('failed mail "Tekrar dene" ile çevrilir', $r['ok'] && satir($mx)['tr_status'] === 'translated' && (int)satir($mx)['tr_attempts'] === 1);
$r = mail_ceviri_simdi($db, $sag, $my, [$X], $NOW);
ok('başka hesabın maili elle ÇEVRİLEMEZ (ACL)', !$r['ok'] && satir($my)['tr_status'] === 'skipped');
$say = count($sag->cagrilar); mail_ceviri_simdi($db, $sag, $my, [$X], $NOW);
ok('yetkisiz denemede sağlayıcıya yeni istek gitmedi', count($sag->cagrilar) === $say);
ok('sağlayıcı kapalıyken elle çeviri reddedilir', mail_ceviri_simdi($db, null, $mx, [$X])['ok'] === false);
ok('elle çeviri hata verirse mail bozulmaz', (function () use ($db, $my, $Y) { $s = new SahteSaglayici(fn() => throw new MailTranslateException('temp', 'x')); $r = mail_ceviri_simdi($db, $s, $my, [$Y], strtotime('2026-10-06 12:00:00')); return !$r['ok'] && satir($my)['body_text'] !== ''; })());
$r = mail_post_isle($db, ['uid' => 2, 'hesapIds' => [$X], 'yonetici' => false, 'cevap' => true, 'send' => false, 'a' => 0, 'm' => $mx, 'o' => 0], 'ceviri_simdi', [], ['sagl' => $sag]);
ok('mail_post_isle ceviri_simdi (zaten çevrilmiş → ok)', $r['ok'] && $r['yasak'] === null);

echo "\n=== 10. Senkron entegrasyonu: dil + durum ===\n";
$C = hesap('sync@asya.com');
$srv = new FakeMailStream(['user' => 'sync@asya.com', 'pass' => 'p1-x', 'mesajlar' => [
    1 => ['raw' => mail_test_raw(['msgid' => '<tr@x>', 'subject' => 'Sipariş', 'body' => 'Sayın yetkili, siparişiniz için ödeme yapılmıştır. Lütfen yüklemeyi planlayınız, iyi günler dileriz.']), 'flags' => [], 'date' => '2026-10-05 10:00:00'],
    2 => ['raw' => mail_test_raw(['msgid' => '<en@x>', 'subject' => 'Order', 'body' => 'Dear Sir, please find attached the payment confirmation for your order. Best regards']), 'flags' => [], 'date' => '2026-10-05 11:00:00'],
]]);
mail_sync_hesap($db, $C, ['kilit_dizin' => $DIZIN, 'simdi' => $NOW, 'istemci' => function ($h) use ($srv) { $c = new MailImapClient($srv->yeniBaglanti()); $c->baslat(false); $c->girisYap($h['imap_user'], $h['imap_pass']); return $c; }]);
$d = $db->query("SELECT uid, lang, tr_status FROM mail_messages WHERE account_id = $C ORDER BY uid")->fetchAll();
ok('senkronda dil yerel tespit edilir; Türkçe mail kuyruğa girmez, İngilizce pending', $d[0]['lang'] === 'tr' && $d[0]['tr_status'] === 'skipped' && $d[1]['lang'] === 'en' && $d[1]['tr_status'] === 'pending', json_encode($d));

echo "\n=== 11. Cron entegrasyonu ===\n";
$sag = new SahteSaglayici();
$r = mail_cron_calistir($db, ['kilit_dizin' => $DIZIN, 'simdi' => $NOW + 30 * 3600, 'ceviri_saglayici' => $sag, 'istemci' => function ($h) { throw new MailImapException('connect', 'x'); }]);
ok('cron çıktısında CEVIRI satırı + sayılar', (bool)preg_grep('/^CEVIRI durum=ok ceviri=\d+/', $r['satirlar']), json_encode($r['satirlar']));
$r = mail_cron_calistir($db, ['kilit_dizin' => $DIZIN, 'simdi' => $NOW, 'ceviri_saglayici' => null, 'istemci' => function ($h) { throw new MailImapException('connect', 'x'); }]);
ok('çeviri kapalıyken cron\'da CEVIRI satırı YOK', !preg_grep('/^CEVIRI/', $r['satirlar']));
$sag = new SahteSaglayici(fn() => throw new MailTranslateException('config', 'anahtar'));
$mz = mesaj($A, 'cron cfg', 'Dear Sir, we kindly ask you to confirm the payment for the order. Best regards', ['tarih' => '2026-10-06 11:30:00']);
$r = mail_cron_calistir($db, ['kilit_dizin' => $DIZIN, 'simdi' => $NOW + 20 * 3600, 'ceviri_saglayici' => $sag, 'istemci' => function ($h) { throw new MailImapException('connect', 'x'); }]);
ok('çeviri yapılandırma hatası cron çıkış kodunu ETKİLEMEZ (senkron sonucu belirler)', (bool)preg_grep('/^CEVIRI durum=/', $r['satirlar']));

echo "\n=== 12. Sızıntı ===\n";
$hepsi = json_encode($db->query('SELECT * FROM mail_messages')->fetchAll()) . json_encode($db->query('SELECT * FROM audit_log')->fetchAll()) . implode("\n", $r['satirlar']) . (string)@file_get_contents($DIZIN . '/.translate_status.json');
foreach (['abcd1234-secret-key', 'LT-ANAHTAR-99', 'pro-key'] as $sir) ok("durum/audit/log '$sir' İÇERMİYOR", !str_contains($hepsi, $sir));
mail_test_bitir();
