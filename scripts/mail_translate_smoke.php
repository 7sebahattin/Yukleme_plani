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
require_once $ROOT . '/config/mail_smtp.php';
require_once $ROOT . '/config/mail_outbox.php';
require_once __DIR__ . '/_mail_fake_imap.php';

$db = db(); mail_test_diger_tablolar($db); mail_test_sema_kur($db); mail_test_anahtar_kur();
$DIZIN = sys_get_temp_dir() . '/mail_tr_' . getmypid(); @mkdir($DIZIN, 0700, true);
mail_ceviri_ayar(['dizin' => $DIZIN, 'limit' => 0]);   // sayaç/sınır testte KENDİ klasörüne yazar; sınır varsayılan kapalı

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
// v369: varsayılan — otomatik çeviri KAPALI: sağlayıcı açık olsa bile cron hiçbir maili sağlayıcıya göndermez.
$bekleyenOnce = (int)$db->query("SELECT COUNT(*) FROM mail_messages WHERE tr_status = 'pending'")->fetchColumn();
$r = mail_cron_calistir($db, ['kilit_dizin' => $DIZIN, 'simdi' => $NOW + 30 * 3600, 'ceviri_saglayici' => $sag, 'istemci' => function ($h) { throw new MailImapException('connect', 'x'); }]);
ok('v369: otomatik çeviri KAPALIYKEN cron sağlayıcıya HİÇBİR ŞEY göndermez, pending mailler bekler', $sag->cagrilar === [] && !preg_grep('/^CEVIRI/', $r['satirlar'])
    && $bekleyenOnce > 0 && (int)$db->query("SELECT COUNT(*) FROM mail_messages WHERE tr_status = 'pending'")->fetchColumn() === $bekleyenOnce, json_encode($r['satirlar']));
ok('v369: MAIL_TRANSLATE_AUTO tanımsız → mail_ceviri_otomatik_acik() false', !defined('MAIL_TRANSLATE_AUTO') && mail_ceviri_otomatik_acik() === false);
$r = mail_cron_calistir($db, ['kilit_dizin' => $DIZIN, 'simdi' => $NOW + 30 * 3600, 'ceviri_saglayici' => $sag, 'ceviri_otomatik' => true, 'istemci' => function ($h) { throw new MailImapException('connect', 'x'); }]);
ok('otomatik çeviri AÇIKKEN cron çıktısında CEVIRI satırı + sayılar', (bool)preg_grep('/^CEVIRI durum=ok ceviri=\d+/', $r['satirlar']), json_encode($r['satirlar']));
$r = mail_cron_calistir($db, ['kilit_dizin' => $DIZIN, 'simdi' => $NOW, 'ceviri_saglayici' => null, 'ceviri_otomatik' => true, 'istemci' => function ($h) { throw new MailImapException('connect', 'x'); }]);
ok('çeviri kapalıyken cron\'da CEVIRI satırı YOK', !preg_grep('/^CEVIRI/', $r['satirlar']));
$sag = new SahteSaglayici(fn() => throw new MailTranslateException('config', 'anahtar'));
$mz = mesaj($A, 'cron cfg', 'Dear Sir, we kindly ask you to confirm the payment for the order. Best regards', ['tarih' => '2026-10-06 11:30:00']);
$r = mail_cron_calistir($db, ['kilit_dizin' => $DIZIN, 'simdi' => $NOW + 20 * 3600, 'ceviri_saglayici' => $sag, 'ceviri_otomatik' => true, 'istemci' => function ($h) { throw new MailImapException('connect', 'x'); }]);
ok('çeviri yapılandırma hatası cron çıkış kodunu ETKİLEMEZ (senkron sonucu belirler)', (bool)preg_grep('/^CEVIRI durum=/', $r['satirlar']));

$rCron = $r;
echo "\n=== 13. Yalnız konuyu çevir ===\n";
$K1 = hesap('konu-a@asya.com'); $K2 = hesap('konu-kapali@asya.com', ['translate_enabled' => 0]);
$sagK = new SahteSaglayici();
$mk = mesaj($K1, 'Payment confirmation Order 77', 'Dear Sir, please find attached the payment confirmation for your order. Best regards', ['lang' => 'en']);
$onceSatir = satir($mk);
$r = mail_ceviri_konu($db, $sagK, $mk, [$K1], $NOW);
$sk = satir($mk);
ok('konu çevrildi: subject_tr yazıldı', $r['ok'] && $r['degisti'] && $sk['subject_tr'] === 'TR:Payment confirmation Order 77', json_encode($r));
ok('YALNIZ subject_tr değişti (tr_status/body_tr/lang/attempts aynı)', $sk['tr_status'] === 'pending' && $sk['body_tr'] === $onceSatir['body_tr'] && $sk['lang'] === 'en' && (int)$sk['tr_attempts'] === 0);
ok('sağlayıcıya TEK çağrı, yalnız konu metni (gövde/adres YOK)', $sagK->cagrilar === [['Payment confirmation Order 77', 'en', 'tr']]);
ok('karakter sayısı döndü (denetim kaydı için)', $r['karakter'] === mb_strlen('Payment confirmation Order 77'));
$n = count($sagK->cagrilar); $r = mail_ceviri_konu($db, $sagK, $mk, [$K1], $NOW);
ok('konu zaten çevrilmişse sağlayıcıya tekrar gidilmez', $r['ok'] && !$r['degisti'] && count($sagK->cagrilar) === $n);
$r = mail_ceviri_simdi($db, $sagK, $mk, [$K1], $NOW);
$sk = satir($mk);
ok('sonradan tam çeviri yine çalışır ve subject_tr\'yi günceller', $r['ok'] && $sk['tr_status'] === 'translated' && str_contains($sk['body_tr'], 'TR:') && $sk['subject_tr'] === 'TR:Payment confirmation Order 77');
$mt = mesaj($K1, 'Sipariş onayı', 'Sayın yetkili, siparişiniz için ödeme yapılmıştır. Lütfen yüklemeyi planlayınız, iyi günler dileriz.', ['lang' => 'tr']);
$sagK2 = new SahteSaglayici(); $r = mail_ceviri_konu($db, $sagK2, $mt, [$K1], $NOW);
ok('mail zaten hedef dilde: sağlayıcıya HİÇ gidilmez, subject_tr yazılmaz', $r['ok'] && !$r['degisti'] && $sagK2->cagrilar === [] && satir($mt)['subject_tr'] === null && str_contains($r['mesaj'], 'hedef dilde'), json_encode($r));
$mk2 = mesaj($K2, 'Closed account', 'Dear Sir, please confirm the order and payment for goods. Thanks and regards');
$r = mail_ceviri_konu($db, $sagK2, $mk2, [$K2], $NOW);
ok('hesapta çeviri kapalı → reddedilir, sağlayıcıya gidilmez', !$r['ok'] && $sagK2->cagrilar === []);
$r = mail_ceviri_konu($db, $sagK2, $mk2, [$K1], $NOW);
ok('başka hesabın maili (ACL) → bulunamadı', !$r['ok'] && $sagK2->cagrilar === [] && satir($mk2)['subject_tr'] === null);
ok('sağlayıcı kapalı → reddedilir', !mail_ceviri_konu($db, null, $mk, [$K1])['ok']);
$mhata = mesaj($K1, 'Temp hata konu', 'Dear Sir, please find attached the payment confirmation for your order. Best regards', ['lang' => 'en']);
$r = mail_ceviri_konu($db, new SahteSaglayici(fn() => throw new MailTranslateException('temp', 'HTTP 503')), $mhata, [$K1], $NOW);
ok('sağlayıcı hatası: mail bozulmaz, subject_tr boş, mesaj anlaşılır', !$r['ok'] && satir($mhata)['subject_tr'] === null && satir($mhata)['tr_status'] === 'pending' && str_contains($r['mesaj'], 'çevrilemedi'));
$muzun = mesaj($K1, str_repeat('Uzun konu ', 60), 'Dear Sir, please find attached the payment confirmation for your order. Best regards', ['lang' => 'en']);
$sagU = new SahteSaglayici(); mail_ceviri_konu($db, $sagU, $muzun, [$K1], $NOW);
ok('konu en çok 300 karakter gönderilir', $sagU->cagrilar !== [] && array_sum(array_map(fn($c) => mb_strlen($c[0]), $sagU->cagrilar)) <= MAIL_CEVIRI_KONU_MAX);
$sagP = new SahteSaglayici(); $mp = mesaj($K1, 'Konu post', 'Dear Sir, please find attached the payment confirmation for your order. Best regards', ['lang' => 'en']);
$r = mail_post_isle($db, ['uid' => 2, 'hesapIds' => [$K1], 'yonetici' => false, 'cevap' => false, 'send' => false, 'a' => 0, 'm' => $mp, 'o' => 0], 'ceviri_konu', [], ['sagl' => $sagP]);
$au = $db->query("SELECT * FROM audit_log WHERE action = 'mail_translate_subject' ORDER BY id DESC LIMIT 1")->fetch();
ok('mail_post_isle ceviri_konu + audit mail_translate_subject yalnız id ve karakter sayısı', $r['ok'] && $r['yasak'] === null && $au && (int)$au['record_id'] === $mp
    && !str_contains(json_encode($au), 'Konu post') && str_contains(json_encode($au), (string)mb_strlen('Konu post')), json_encode($au));

echo "\n=== 14. Harcama sayacı ===\n";
@unlink($DIZIN . '/.translate_usage.json');
$sagM = new SahteSaglayici(fn($m) => ['metin' => 'TR:' . $m, 'tespit' => 'en', 'kullanim' => ['giris' => 100, 'cikis' => 40]]);
$r = mail_ceviri_cevir($sagM, 'Hello world, this is a test.', 'en', 'tr', $NOW);
$oz = mail_ceviri_kullanim_ozeti($NOW, $DIZIN);
ok('sayaç: çağrı/karakter/token bugün+ay toplamına yazıldı', $oz['bugun']['cagri'] === 1 && $oz['bugun']['karakter'] === 28 && $oz['bugun']['giris'] === 100 && $oz['bugun']['cikis'] === 40 && $oz['ay']['cagri'] === 1, json_encode($oz));
ok('mail_ceviri_cevir sonucu kullanim döner (arayüz bozulmadı: metin/tespit duruyor)', $r['metin'] === 'TR:Hello world, this is a test.' && $r['tespit'] === 'en' && $r['kullanim']['karakter'] === 28 && $r['kullanim']['giris'] === 100);
$dosya = file_get_contents($DIZIN . '/.translate_usage.json');
ok('sayaç dosyası 0600 ve mail içeriği/konu/adres İÇERMEZ', (fileperms($DIZIN . '/.translate_usage.json') & 0777) === 0600 && !str_contains($dosya, 'Hello') && !str_contains($dosya, '@') && str_contains($dosya, 'sahte'), $dosya);
mail_ceviri_cevir(new SahteSaglayici(), 'Short text one.', 'en', 'tr', $NOW);
$oz = mail_ceviri_kullanim_ozeti($NOW, $DIZIN);
ok('token vermeyen sağlayıcı: yalnız karakter/çağrı artar', $oz['bugun']['cagri'] === 2 && $oz['bugun']['giris'] === 100 && $oz['bugun']['karakter'] === 28 + 15);
try { mail_ceviri_cevir(new SahteSaglayici(fn() => throw new MailTranslateException('temp', 'x')), 'Failing text.', 'en', 'tr', $NOW); } catch (MailTranslateException $e) {}
$oz = mail_ceviri_kullanim_ozeti($NOW, $DIZIN);
ok('hatalı çağrı: hata sayacı artar, karakter artmaz', $oz['bugun']['hata'] === 1 && $oz['bugun']['karakter'] === 43);
mail_ceviri_kullanim_kaydet('sahte', 10, 0, 0, false, $NOW - 86400 * 3, $DIZIN);
mail_ceviri_kullanim_kaydet('sahte', 10, 0, 0, false, $NOW - 86400 * 90, $DIZIN);
mail_ceviri_kullanim_kaydet('sahte', 1, 0, 0, false, $NOW, $DIZIN);
$oz = mail_ceviri_kullanim_ozeti($NOW, $DIZIN); $tum = mail_ceviri_kullanim_tum($DIZIN);
ok('ay toplamı bugünden büyük; 60 günden eski gün silindi', $oz['ay']['karakter'] > $oz['bugun']['karakter'] && !isset($tum[date('Y-m-d', $NOW - 86400 * 90)]) && isset($tum[date('Y-m-d', $NOW - 86400 * 3)]));
$bozuk = sys_get_temp_dir() . '/mail_tr_bozuk_' . getmypid(); @mkdir($bozuk, 0700, true); file_put_contents($bozuk . '/.translate_usage.json', '{bozuk');
ok('bozuk sayaç dosyası okumayı çökertmez', mail_ceviri_kullanim_ozeti($NOW, $bozuk)['bugun']['cagri'] === 0);
$mq = mesaj($K1, 'Meter konu', 'Dear Sir, please find attached the payment confirmation for your order. Best regards', ['lang' => 'en']);
$once = mail_ceviri_kullanim_ozeti($NOW, $DIZIN)['bugun']['cagri'];
mail_ceviri_konu($db, new SahteSaglayici(), $mq, [$K1], $NOW);
ok('konu çevirisi de sayılır', mail_ceviri_kullanim_ozeti($NOW, $DIZIN)['bugun']['cagri'] === $once + 1);

echo "\n=== 15. DeepSeek: usage ayrıştırma + sözlük ===\n";
$KAYIT = [];
$dsU = new MailTranslateDeepSeek('sk-x', $sahte(['kod' => 200, 'govde' => json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Selam']]], 'usage' => ['prompt_tokens' => 321, 'completion_tokens' => 12, 'total_tokens' => 333]])]));
$r = $dsU->cevir('Hello', 'en', 'tr');
ok('DeepSeek usage → kullanim giris/cikis', ($r['kullanim'] ?? null) === ['giris' => 321, 'cikis' => 12] && $r['metin'] === 'Selam');
$dsN = new MailTranslateDeepSeek('sk-x', $sahte(['kod' => 200, 'govde' => json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Selam']]]])]));
ok('usage yoksa kullanim alanı yok (eski sözleşme)', !array_key_exists('kullanim', $dsN->cevir('Hello', 'en', 'tr')));
@unlink($DIZIN . '/.translate_usage.json');
mail_ceviri_cevir($dsU, 'Hello', 'en', 'tr', $NOW);
$oz = mail_ceviri_kullanim_ozeti($NOW, $DIZIN)['bugun'];
ok('DeepSeek token sayaca işlendi', $oz['giris'] === 321 && $oz['cikis'] === 12 && $oz['karakter'] === 5);
$sistem = json_decode((string)$KAYIT[count($KAYIT) - 1]['govde'], true)['messages'][0]['content'];
$eksik = [];
foreach (['pallet', 'паллета', 'crate', 'ящик', 'CMR', 'phytosanitary certificate', 'фитосанитарный сертификат', 'HS code', 'Kapya', 'Çarliston', 'domates', 'salatalık', 'limon', 'mandalina', 'nar = pomegranate'] as $t) if (!str_contains($sistem, $t)) $eksik[] = $t;
ok('sözlük DeepSeek sistem isteminde (palet/kasa/CMR/fitosanitar/GTİP/biber/ürünler)', $eksik === [], implode(',', $eksik));
ok('sözlük kısa (≤ 1500 karakter) ve sabit dizi', mb_strlen(implode('; ', MAIL_CEVIRI_SOZLUK)) <= 1500 && count(MAIL_CEVIRI_SOZLUK) >= 10);

echo "\n=== 16. Günlük sınır ===\n";
@unlink($DIZIN . '/.translate_usage.json');
mail_ceviri_ayar(['dizin' => $DIZIN, 'limit' => 0]);
$o = mail_ceviri_kullanim_ozeti();
ok('sınır 0 = sınırsız', $o['limit'] === 0 && $o['kalan'] === null);
mail_ceviri_ayar(['dizin' => $DIZIN, 'limit' => 150]);
$sagL = new SahteSaglayici();
$ml1 = mesaj($K1, 'Limit konu', 'Dear Sir, please find attached the payment confirmation for your order and ship the goods today. Best regards', ['lang' => 'en']);
$r = mail_ceviri_simdi($db, $sagL, $ml1, [$K1], time());
ok('sınır altında çeviri çalışır', $r['ok'] && $sagL->cagrilar !== [], json_encode($r));
$kul = mail_ceviri_kullanim_ozeti()['bugun']['karakter'];
ok('sayaç sınıra yaklaştı', $kul > 50 && $kul <= 150, (string)$kul);
$ml2 = mesaj($K1, 'Limit konu 2', 'Dear Sir, please find attached the payment confirmation for your order and ship the goods today, thank you very much. Best regards', ['lang' => 'en']);
$n = count($sagL->cagrilar);
$r = mail_ceviri_simdi($db, $sagL, $ml2, [$K1], time());
ok('sınır aşılacaksa mail_ceviri_simdi sağlayıcıya GİTMEDEN reddeder (net Türkçe mesaj)', !$r['ok'] && count($sagL->cagrilar) === $n && str_contains($r['mesaj'], 'Günlük çeviri sınırı') && satir($ml2)['tr_status'] === 'pending' && (int)satir($ml2)['tr_attempts'] === 0, json_encode($r));
mail_ceviri_ayar(['dizin' => $DIZIN, 'limit' => 5]);
$r = mail_ceviri_konu($db, $sagL, $ml2, [$K1], time());
ok('konu: sınır dolu → sağlayıcıya gidilmez, mesaj açık', !$r['ok'] && count($sagL->cagrilar) === $n && str_contains($r['mesaj'], 'Günlük çeviri sınırı'), json_encode($r));
$r = mail_ceviri_isle($db, $sagL, ['dizin' => $DIZIN, 'simdi' => time()]);
ok('otomatik kuyruk: sınır doluysa sağlayıcıya yaklaşmaz (gunluk_limit), mailler pending kalır', $r['durum'] === 'gunluk_limit' && count($sagL->cagrilar) === $n && satir($ml2)['tr_status'] === 'pending', json_encode($r));
ok('durum dosyası gunluk_limit yazıldı', (mail_ceviri_durum_oku($DIZIN)['durum'] ?? '') === 'gunluk_limit');
mail_ceviri_ayar(['dizin' => $DIZIN, 'limit' => mail_ceviri_kullanim_ozeti()['bugun']['karakter'] + 20]);
$mb = mesaj($K1, 'Konu sığmaz uzun bir konu satiri', 'Dear Sir, thanks. Best regards', ['lang' => 'en']);
$n = count($sagL->cagrilar);
$r = mail_ceviri_simdi($db, $sagL, $mb, [$K1], time());
ok('gövde sığıp konu sığmazsa YARIM çeviri yok (sağlayıcı çağrılmadı)', !$r['ok'] && count($sagL->cagrilar) === $n && satir($mb)['body_tr'] === null, json_encode($r));
mail_ceviri_ayar(['dizin' => $DIZIN, 'limit' => 5]);
$H = [$K1];
$mr = mesaj($K1, 'Order 99', 'Dear Sir, where is my order?', ['lang' => 'en']);
$t = mail_outbox_taslak($db, $mr, $H, 1, bin2hex(random_bytes(16)), ['body_tr' => 'Merhaba, mallar pazartesi yola çıkıyor ve ödemeyi bekliyoruz.', 'target_lang' => 'en']);
ok('(cevap akışı) taslak açıldı', $t['ok'], json_encode($t));
$n = count($sagL->cagrilar);
$p = mail_outbox_onizle($db, $t['id'], $H, 1, $sagL, ['body_tr' => 'Merhaba, mallar pazartesi yola çıkıyor ve ödemeyi bekliyoruz.', 'target_lang' => 'en']);
$o = $db->query('SELECT * FROM mail_outbox WHERE id = ' . (int)$t['id'])->fetch();
ok('(cevap akışı) sınır dolu: sağlayıcıya gidilmez, Türkçe taslak KORUNUR, elle çeviri önerilir', !$p['ok'] && count($sagL->cagrilar) === $n && str_contains($p['mesaj'], 'Günlük çeviri sınırı') && str_contains($p['mesaj'], 'elle') && $o['body_tr'] === 'Merhaba, mallar pazartesi yola çıkıyor ve ödemeyi bekliyoruz.' && $o['status'] === 'draft', json_encode($p));
$p = mail_outbox_onizle($db, $t['id'], $H, 1, $sagL, ['body_out_manual' => 'Hello, goods leave on Monday.', 'target_lang' => 'en', 'mod' => 'manuel']);
ok('(cevap akışı) elle çeviri sınırdan etkilenmez', $p['ok'] && count($sagL->cagrilar) === $n, json_encode($p));
mail_ceviri_ayar(['dizin' => $DIZIN, 'limit' => 0]);

echo "\n=== 12. Sızıntı ===\n";
$hepsi = json_encode($db->query('SELECT * FROM mail_messages')->fetchAll()) . json_encode($db->query('SELECT * FROM audit_log')->fetchAll()) . implode("\n", $rCron['satirlar']) . (string)@file_get_contents($DIZIN . '/.translate_status.json');
foreach (['abcd1234-secret-key', 'LT-ANAHTAR-99', 'pro-key'] as $sir) ok("durum/audit/log '$sir' İÇERMİYOR", !str_contains($hepsi, $sir));
mail_test_bitir();
