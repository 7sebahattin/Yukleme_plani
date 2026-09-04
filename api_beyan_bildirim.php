<?php
// =============================================================================
// api_beyan_bildirim.php — Beyan → Hal Kayıt (HKS) bildirim köprüsü (JSON)
// Sprint Beyan-Bildirim-01
// =============================================================================
// İKİ EYLEM:
//   action=hazirla        → modalin ihtiyacı olan her şey (önizleme + katalog
//                           listeleri + öğrenilmiş eşlemeler + eksik alanlar)
//   action=taslak_olustur → doğrulayıp HKS TASLAĞI yazar ve bağ kaydını açar
//
// TASARIM KARARLARI — değiştirmeden önce okuyun:
//  • BU UÇ HKS'E HİÇBİR ŞEY GÖNDERMEZ. BildirimKaydet geri alınamaz ve rüsum
//    doğurur; gönderim kararı yalnız Hal Kayıt ekranında, oradaki atomik
//    mükerrer-gönderim koruması ile verilir. Buradan yalnız TASLAK açılır.
//  • Canlı SOAP çağrısı YAPILMAZ. Katalog listeleri hks_kv'deki `listeler_cache`
//    önbelleğinden okunur → modal anında açılır. Önbellek boşsa özellik
//    fail-closed davranır ve kullanıcıyı "Listeleri Güncelle"ye yönlendirir.
//  • Taslak PLAN TASLAĞI olarak yazılır (künye YOK, planKg VAR): künyeler
//    gönderim anında canlı stoktan çözülür (halkayit/api.php hks_plan_kunye_coz).
//    Beyan ekranında künye seçtirmek 300 sn'lik SOAP turları demekti.
// =============================================================================
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/halkayit/taslak_lib.php';   // TEK yazma yolu + doğrulama

header('Content-Type: application/json; charset=utf-8');

// Toplu işlemde tek istekte işlenecek üst sınır. HKS'in kendi 100 bildirim
// sınırıyla ilgisi yoktur (her satır AYRI taslak olur); buradaki sınır isteğin
// makul sürede bitmesi içindir.
const BB_TOPLU_LIMIT = 50;

function bb_cikti(array $veri, int $kod = 200): void {
    http_response_code($kod);
    echo json_encode($veri, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Oturum + yetki (JSON-güvenli: redirect etmez) ─────────────────────────
$auth_user = function_exists('current_user') ? current_user() : null;
if ($auth_user === null) bb_cikti(['hata' => 'Oturum gerekli. Lütfen tekrar giriş yapın.'], 401);

// ÇİFT KAPI: beyanı düzenleme yetkisi TEK BAŞINA yetmez. Bildirim, HKS'te
// rüsum doğuran bir zincirin ilk halkasıdır; Hal Kayıt panelinin kapısı olan
// records.write da aranır. (Aksi hâlde yalnız beyan.read+write olan bir rol
// bildirim üretebilirdi.)
if (!(can_beyan('write') && (can('records.write') || is_admin()))) {
    bb_cikti(['hata' => 'Bu işlem için yetkiniz yok (beyan.write + records.write gerekli).'], 403);
}

$govde = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($govde)) $govde = [];
$action = (string)($_GET['action'] ?? ($govde['action'] ?? ''));

// ── Beyanı yükle ──────────────────────────────────────────────────────────
// Tekil eylemler tek beyanla çalışır; toplu eylemler kendi id listesini okur.
function bb_beyan_yukle(int $id): array {
    $st = db()->prepare("SELECT * FROM customs_declarations WHERE id = ?");
    $st->execute([$id]);
    $b = $st->fetch();
    if (!$b) bb_cikti(['hata' => 'Beyan bulunamadı.'], 404);
    if (!empty($b['deleted_at'])) bb_cikti(['hata' => 'Arşivlenmiş beyan için bildirim yapılamaz.'], 400);
    return $b;
}

$topluMu  = in_array($action, ['toplu_hazirla', 'toplu_olustur'], true);
$beyan_id = 0;
$beyan    = [];
if (!$topluMu) {
    $beyan_id = (int)($govde['beyan_id'] ?? $_GET['beyan_id'] ?? 0);
    if ($beyan_id <= 0) bb_cikti(['hata' => 'Geçersiz beyan.'], 400);
    $beyan = bb_beyan_yukle($beyan_id);
}

// ── Ortak ön koşullar ─────────────────────────────────────────────────────
// Sırayla kontrol edilir; ilk eksik olan kullanıcıya söylenir.
function bb_on_kosul(array $beyan): ?string {
    if (!in_array((string)$beyan['status'], beyan_hks_uygun_durumlar(), true)) {
        return 'Bu beyan durumunda (' . ($beyan['status'] ?? '') . ') bildirim yapılamaz.';
    }
    if (trim((string)($beyan['vehicle_plate'] ?? '')) === '') {
        return 'Araç plakası girilmeden bildirim yapılamaz. Beyanı düzenleyip plakayı girin.';
    }
    $kg = (float)($beyan['net_kg'] ?? 0);
    // Brüte DÜŞÜLMEZ: bildirim net üzerinden yapılır, brütle göndermek yanlış
    // rüsum demektir. Net yoksa kullanıcı beyanı düzeltmelidir.
    if ($kg <= 0) return 'Net KG girilmeden bildirim yapılamaz (brüt kullanılmaz — rüsum net üzerinden hesaplanır).';
    if (trim((string)($beyan['product_name'] ?? '')) === '') return 'Ürün adı boş — bildirim yapılamaz.';
    // Plaka DOLU olsa bile eşleştirme eksikse geçilmez (beyan formundaki
    // "🏛 Hal Bildirim Bilgileri" bölümü). Sunucu tarafındaki kapı budur;
    // beyan_view'daki buton kapısı bunun görsel karşılığıdır.
    if (!beyan_hks_eslesme_tam($beyan)) {
        return 'Hal Bildirim bilgileri eksik (HKS firması / ürünü / ülke). '
             . 'Beyanı düzenleyip "🏛 Hal Bildirim Bilgileri" bölümünü doldurun.';
    }
    return null;
}

// NOT: HKS katalog/eşleştirme yardımcıları (bb_katalog, bb_varsayilanlar,
// bb_tahmin, bb_alim_turu_mu, bb_son_firma...) config/helpers.php'ye TAŞINDI —
// beyan FORMLARI da aynı listeleri kullanıyor (kalıcı eşleştirme alanları).

// NOT: bb_bagli_plan / bb_ulke_adaylari / bb_ulke_tahmin de helpers.php'de —
// beyan formu ülke alanını aynı ipucu zinciriyle ön-dolduruyor.

// ── BİRİM FİYAT ÖNERİLERİ ─────────────────────────────────────────────────
// HKS'in `MalinSatisFiyat` alanında PARA BİRİMİ YOKTUR (bkz. hks_soap.php:395)
// — gönderilen sayı HKS'in kendi birimindedir ve rüsum bunun üzerinden
// hesaplanır. Bu yüzden hiçbir öneri alana KENDİLİĞİNDEN YAZILMAZ: kullanıcı
// hangi rakamı, nereden ve hangi para biriminden aldığını görerek seçer.
//
// İki kaynak, güvenilirlik sırasıyla:
//   1. gonderilen — bu firmaya + bu ürüne yapılmış EN SON HKS bildiriminin
//      fiyatı. HKS'e daha önce GİTMİŞ bir değerdir; birim belirsizliği yoktur.
//   2. maliyet    — aynı yükleme planına bağlı maliyet hesabının satış birim
//      fiyatı. KENDİ para biriminde tutulur (varsayılan EUR), bu yüzden hem
//      ham hâli hem de kur varsa çevrilmiş hâli AYRI AYRI sunulur; hangisinin
//      doğru olduğuna kullanıcı karar verir.
function bb_fiyat_onerileri(array $beyan): array {
    $oneriler = [];
    $on = defined('HKS_TABLO_ON') ? HKS_TABLO_ON : 'hks_';

    // 1) Aynı firma + aynı ürün için en son gönderilen bildirim
    try {
        $st = db()->prepare("SELECT fiyat, zaman FROM `{$on}gonderilenler`
                             WHERE firma_id = ? AND urun_ad = ? AND fiyat > 0
                             ORDER BY zaman DESC LIMIT 1");
        $st->execute([(string)$beyan['hks_firma_id'], (string)$beyan['hks_urun_ad']]);
        if ($r = $st->fetch()) {
            $oneriler[] = [
                'kaynak'   => 'gonderilen',
                'deger'    => (float)$r['fiyat'],
                'etiket'   => 'Son HKS bildirimi',
                'aciklama' => (string)($beyan['hks_urun_ad'] ?? '') . ' · ' . fmt_datetime($r['zaman']),
            ];
        }
    } catch (PDOException $e) {}

    // 2) Bağlı yükleme planının maliyet hesabı — yalnız maliyet.read yetkisiyle.
    if (!empty($beyan['loading_record_id']) && (can('maliyet.read') || is_admin())) {
        try {
            $st = db()->prepare("SELECT sheet_no, sale_unit_price, currency_code, currency_rate
                                 FROM cost_sheets
                                 WHERE record_id = ? AND deleted_at IS NULL AND sale_unit_price > 0
                                 ORDER BY id DESC LIMIT 1");
            $st->execute([(int)$beyan['loading_record_id']]);
            if ($c = $st->fetch()) {
                $ham  = (float)$c['sale_unit_price'];
                $kur  = (float)$c['currency_rate'];
                $bir  = (string)($c['currency_code'] ?: 'EUR');
                $no   = (string)($c['sheet_no'] ?: '');
                $oneriler[] = [
                    'kaynak'   => 'maliyet',
                    'deger'    => $ham,
                    'etiket'   => 'Maliyet hesabı (' . $bir . ')',
                    'aciklama' => ($no !== '' ? $no . ' · ' : '') . 'satış birim fiyatı, ' . $bir . ' cinsinden',
                ];
                // Kur girilmişse çevrilmiş hâli AYRI bir öneri olarak sunulur —
                // otomatik çevirmek, hangi birimin doğru olduğu varsayımı olurdu.
                if ($kur > 0) {
                    $oneriler[] = [
                        'kaynak'   => 'maliyet_kur',
                        'deger'    => round($ham * $kur, 4),
                        'etiket'   => 'Maliyet hesabı × kur',
                        'aciklama' => rtrim(rtrim(number_format($ham, 4, ',', '.'), '0'), ',') . ' ' . $bir
                                    . ' × ' . rtrim(rtrim(number_format($kur, 4, ',', '.'), '0'), ','),
                    ];
                }
            }
        } catch (PDOException $e) {}
    }
    return $oneriler;
}

// ── TASLAK KURULUMU — tekil ve toplu akışın ORTAK gövdesi ────────────────
// Tek beyan için: ön koşul → mükerrer kontrolü → doğrulama → taslak → bağ →
// audit. Toplu akış bunu satır satır çağırır; İKİNCİ BİR KOPYA ÇIKARMAYIN.
// Dönüş: ['tamam' => true, 'taslakId' => ...] veya ['hata' => ..., 'kod' => ...]
function bb_taslak_kur(array $beyan, string $sifatId, string $turId, float $fiyat, int $userId): array {
    $bid = (int)$beyan['id'];
    $hata = bb_on_kosul($beyan);
    if ($hata) return ['kod' => 400, 'hata' => $hata];

    // "Her ürün için 1 kez bildirim" — 1 beyan = 1 ürün olduğundan, aktif
    // (taslak|gonderildi) bir bağ varsa ikincisi açılmaz. İptal/hata satırları
    // aktif sayılmaz; kullanıcı düzeltip yeniden deneyebilir.
    $aktif = beyan_hks_aktif($bid);
    if ($aktif) {
        return [
            'kod'   => 409,
            'hata'  => $aktif['durum'] === 'gonderildi'
                ? 'Bu beyan için bildirim ZATEN GÖNDERİLDİ (' . fmt_datetime($aktif['created_at']) .
                  '). Mükerrer bildirim rüsum doğurur — tekrar gönderilmedi.'
                : 'Bu beyan için bekleyen bir HKS taslağı var. Hal Kayıt ekranından gönderin ' .
                  'ya da silin, sonra tekrar deneyin.',
            'aktif' => $aktif,
        ];
    }

    // ── EŞLEŞTİRME: BEYANDAN okunur, istemciden DEĞİL ────────────────────
    // Kalıcı alanlar beyan formunda girilir. İstemcinin gönderdiği bir firma/
    // ürün/ülke kabul edilseydi, beyanda görünenden BAŞKA bir bildirim
    // oluşturulabilirdi — geri alınamaz bir çağrıda bu kabul edilemez.
    // (bb_on_kosul üçünün de dolu olduğunu yukarıda doğruladı.)
    $firmaId = trim((string)$beyan['hks_firma_id']);
    $urunId  = trim((string)$beyan['hks_urun_id']);
    $ulkeId  = trim((string)$beyan['hks_ulke_id']);

    $plaka = mb_strtoupper(trim((string)($beyan['vehicle_plate'] ?? '')), 'UTF-8');
    $kg    = round((float)$beyan['net_kg'], 3);

    if ($sifatId === '') return ['kod' => 400, 'hata' => 'Bildirimci sıfatı seçilmedi.'];
    if ($turId   === '') return ['kod' => 400, 'hata' => 'Bildirim türü seçilmedi.'];
    if ($fiyat <= 0)     return ['kod' => 400, 'hata' => 'Birim fiyat girilmedi.'];

    $katalog = bb_katalog();
    if (!$katalog) return ['kod' => 409, 'hata' => 'HKS katalog listeleri yok — Hal Kayıt panelinden güncelleyin.'];

    // "Alım" türü bu ekranda seçilemez (bb_katalog listeden çıkarır); gövdeden
    // elle gönderilmiş bir id de kabul edilmemeli — liste dışı id reddedilir.
    $katalogda = function (array $liste, string $id): bool {
        foreach ($liste as $x) if ((string)$x['id'] === $id) return true;
        return false;
    };
    if (!$katalogda($katalog['sifatlar'], $sifatId)) {
        return ['kod' => 400, 'hata' => 'Geçersiz bildirimci sıfatı — listeleri güncelleyin.'];
    }
    if (!$katalogda($katalog['bildirimTurleri'], $turId)) {
        return ['kod' => 400, 'hata' => 'Bu bildirim türü beyan ekranından kullanılamaz ' .
                            '(alım türleri referanssızdır — Hal Kayıt panelinden yapılır).'];
    }

    // Ürün/ülke ADI beyanda saklı; katalog güncellendiyse tazelenir.
    $ad = function (array $liste, string $id, string $yedek): string {
        foreach ($liste as $x) if ((string)$x['id'] === $id) return (string)$x['ad'];
        return $yedek;
    };
    $urunAd = $ad($katalog['urunler'], $urunId, (string)$beyan['hks_urun_ad']);
    $ulkeAd = $ad($katalog['ulkeler'], $ulkeId, (string)$beyan['hks_ulke_ad']);
    if ($urunAd === '') return ['kod' => 400, 'hata' => 'Beyandaki HKS ürünü katalogda bulunamadı — beyanı düzenleyip yeniden seçin.'];
    if ($ulkeAd === '') return ['kod' => 400, 'hata' => 'Beyandaki ülke katalogda bulunamadı — beyanı düzenleyip yeniden seçin.'];

    // İşletme türü: yurt dışı ihracat akışında app.html'in `oto.isletmeTuruId`
    // ile yaptığının aynısı (kural helpers.php'de — teşhis sayfası da AYNI
    // fonksiyonu kullanır, iki taraf ayrışmasın).
    $isletmeTuruId = bb_yurtdisi_isletme_turu($katalog);
    if ($isletmeTuruId === '') {
        return ['kod' => 409, 'hata' => 'Katalogda "Yurt Dışı" işletme türü bulunamadı — Hal Kayıt panelinden listeleri güncelleyin.'];
    }

    // PLAN TASLAĞI gövdesi — halkayit SPA'sındaki btnPlanKaydet ile AYNI şekil.
    $g = [
        'firmaId'  => $firmaId,
        'satirlar' => [],                     // künyeler gönderim anında çözülür
        'ortak'    => [
            'sifatId'        => $sifatId,
            'bildirimTuruId' => $turId,
            'urunId'         => $urunId,
            'urunAd'         => $urunAd,
            'plaka'          => $plaka,
            'belgeNo'        => '',
            'belgeTipiId'    => 0,
            'fiyat'          => $fiyat,
            'isletmeTuruId'  => $isletmeTuruId,
            'ulkeId'         => $ulkeId,
            'ulkeAd'         => $ulkeAd,
            'planKg'         => $kg,
            'planSorgu'      => [
                'urunId'        => $urunId,
                'aySayisi'      => 12,
                'isletmeTuruId' => 0,
                'sirala'        => 'azalan',
            ],
            // KÖPRÜ İZİ: taslak gönderilince satır silinip yeni id ile
            // `gonderilenler`e doğar. Beyan bağı bu yüzden taslağın İÇİNDE
            // taşınır; halkayit/api.php gönderim sonunda bunu okur.
            'kaynak' => ['tip' => 'beyan', 'beyanId' => $bid,
                         'partiNo' => (string)($beyan['party_no'] ?? '')],
        ],
    ];

    // TEK yazma yolu — doğrulama dahil (halkayit/taslak_lib.php)
    $sonuc = hks_taslak_olustur($g);
    if (!empty($sonuc['hata'])) return ['kod' => $sonuc['kod'] ?? 400, 'hata' => $sonuc['hata']];

    // Bağ kaydı
    $ins = db()->prepare("INSERT INTO beyan_hks_bildirim
        (beyan_id, hks_firma_id, hks_firma_ad, taslak_id, durum,
         urun_id, urun_ad, ulke_id, ulke_ad, plaka, kg, fiyat, created_by, created_at)
        VALUES (?,?,?,?,'taslak',?,?,?,?,?,?,?,?,NOW())");
    $ins->execute([$bid, $firmaId, $sonuc['firmaAd'], $sonuc['id'],
        $urunId, $urunAd, $ulkeId, $ulkeAd, $plaka, $kg, $fiyat,
        $userId]);
    beyan_hks_durum_tazele($bid);

    // NOT: Eşleme öğrenmesi (hks_eslesme) artık BEYAN KAYDEDİLİRKEN yapılıyor
    // (beyan_create.php / beyan_edit.php) — seçim orada yapıldığı için. Burada
    // tekrarlanmaz; iki yazıcı olsa aynı anahtar farklı anlarda ezilirdi.

    // Rüsum doğuran zincirin ilk halkası — audit ŞART.
    audit_log_event('hks_taslak_olustur', 'beyan', $bid, null, [
        'taslak_id' => $sonuc['id'], 'firma_id' => $firmaId,
        'urun' => $urunAd, 'ulke' => $ulkeAd, 'plaka' => $plaka,
        'kg' => $kg, 'fiyat' => $fiyat,
    ]);

    return ['tamam' => true, 'taslakId' => $sonuc['id']];
}

// =============================================================================
switch ($action) {

// ── HAZIRLA: modalin gösterdiği her şey ───────────────────────────────────
case 'hazirla': {
    $katalog = bb_katalog();
    if (!$katalog) {
        bb_cikti([
            'hata'          => 'HKS katalog listeleri henüz indirilmemiş. Hal Kayıt panelini açıp ' .
                               'bir firma seçin ve "Listeleri Güncelle"ye basın.',
            'katalogYok'    => true,
        ], 409);
    }

    $plan = bb_bagli_plan($beyan);

    // Eşleştirme BEYANDAN gelir (kalıcı alanlar) — bu ekranda seçilmez.
    // Firma adı hks_firmalar'dan çözülür; firma silinmişse id gösterilir.
    $firmaAd = '';
    try {
        $on = defined('HKS_TABLO_ON') ? HKS_TABLO_ON : 'hks_';
        $fs = db()->prepare("SELECT ad FROM `{$on}firmalar` WHERE id = ?");
        $fs->execute([(string)$beyan['hks_firma_id']]);
        $firmaAd = (string)($fs->fetchColumn() ?: '');
    } catch (PDOException $e) {}

    bb_cikti([
        'beyan' => [
            'id'        => (int)$beyan['id'],
            'partiNo'   => (string)($beyan['party_no'] ?? ''),
            'urunAdi'   => (string)($beyan['product_name'] ?? ''),
            'cesit'     => (string)($beyan['product_variety'] ?? ''),
            'netKg'     => (float)($beyan['net_kg'] ?? 0),
            'brutKg'    => (float)($beyan['gross_kg'] ?? 0),
            'palet'     => $beyan['pallet_count'] !== null ? (int)$beyan['pallet_count'] : null,
            'kasa'      => $beyan['crate_count']  !== null ? (int)$beyan['crate_count']  : null,
            'plaka'     => trim((string)($beyan['vehicle_plate'] ?? '')),
            'alici'     => (string)($beyan['buyer_name'] ?? ''),
            'marka'     => (string)($beyan['brand'] ?? ''),
            'depo'      => (string)($beyan['exit_depot'] ?? ''),
            'durum'     => (string)($beyan['status'] ?? ''),
        ],
        'plan'    => $plan ? ['id' => (int)$plan['id'], 'partiNo' => $plan['parti_no'],
                              'ulke' => $ulkeMetni, 'plaka' => $plan['on_plaka']] : null,
        'onKosul' => bb_on_kosul($beyan),                 // null = bildirim yapılabilir
        'aktif'   => beyan_hks_aktif($beyan_id),          // doluysa "zaten bildirim var"
        'gecmis'  => beyan_hks_gecmis($beyan_id),
        'katalog' => $katalog,
        'eslesme' => [
            'firmaId' => (string)$beyan['hks_firma_id'],
            'firmaAd' => $firmaAd !== '' ? $firmaAd : ('#' . $beyan['hks_firma_id']),
            'urunId'  => (string)$beyan['hks_urun_id'],
            'urunAd'  => (string)$beyan['hks_urun_ad'],
            'ulkeId'  => (string)$beyan['hks_ulke_id'],
            'ulkeAd'  => (string)$beyan['hks_ulke_ad'],
        ],
        // Ön-seçimler: sıfat/tür kural tabanlı.
        'varsayilan'    => bb_varsayilanlar($katalog),
        // Birim fiyat ÖNERİLERİ — hiçbiri kendiliğinden yazılmaz.
        'fiyatOnerileri' => bb_fiyat_onerileri($beyan),
    ]);
}

// ── TASLAK OLUŞTUR ────────────────────────────────────────────────────────
case 'taslak_olustur': {
    csrf_check($govde['csrf'] ?? null);   // JSON-aware: 403 + JSON döner

    $sonuc = bb_taslak_kur(
        $beyan,
        trim((string)($govde['sifatId'] ?? '')),
        trim((string)($govde['bildirimTuruId'] ?? '')),
        num((string)($govde['fiyat'] ?? '')),          // Türkçe ondalık (1.234,56)
        (int)($auth_user['id'] ?? 0)
    );
    if (!empty($sonuc['hata'])) bb_cikti($sonuc, $sonuc['kod'] ?? 400);
    bb_cikti([
        'tamam'    => true,
        'taslakId' => $sonuc['taslakId'],
        'mesaj'    => 'HKS taslağı oluşturuldu. Gönderim Hal Kayıt ekranından yapılır — ' .
                      'bildirim GÖNDERİLMEDİ.',
    ]);
}

// ── TOPLU HAZIRLA: seçilen beyanların önizlemesi ─────────────────────────
// Her satır kendi uygunluğunu ve fiyat önerisini taşır; uygun OLMAYANLAR da
// döner (sebebiyle birlikte) — sessizce listeden düşürmek, kullanıcının neyi
// kaçırdığını göremediği için daha kötüdür.
case 'toplu_hazirla': {
    $idler = array_values(array_unique(array_filter(
        array_map('intval', (array)($govde['beyan_ids'] ?? [])), fn($i) => $i > 0)));
    if (!$idler) bb_cikti(['hata' => 'Beyan seçilmedi.'], 400);
    if (count($idler) > BB_TOPLU_LIMIT) {
        bb_cikti(['hata' => 'Tek seferde en fazla ' . BB_TOPLU_LIMIT . ' beyan seçilebilir.'], 400);
    }

    $katalog = bb_katalog();
    if (!$katalog) {
        bb_cikti(['hata' => 'HKS katalog listeleri henüz indirilmemiş. Hal Kayıt panelini açıp ' .
                            'bir firma seçin ve "Listeleri Güncelle"ye basın.', 'katalogYok' => true], 409);
    }

    $satirlar = [];
    foreach ($idler as $bid) {
        $b = bb_beyan_yukle($bid);
        $engel = bb_on_kosul($b);
        if (!$engel && beyan_hks_aktif($bid)) $engel = 'Bu beyan için zaten bir bildirim var.';
        $oneriler = $engel ? [] : bb_fiyat_onerileri($b);
        $satirlar[] = [
            'id'       => $bid,
            'partiNo'  => (string)($b['party_no'] ?? ''),
            'urunAd'   => (string)($b['hks_urun_ad'] ?? ''),
            'ulkeAd'   => (string)($b['hks_ulke_ad'] ?? ''),
            'plaka'    => (string)($b['vehicle_plate'] ?? ''),
            'netKg'    => (float)($b['net_kg'] ?? 0),
            'engel'    => $engel,
            'oneriler' => $oneriler,
            'fiyat'    => $oneriler ? $oneriler[0]['deger'] : null,
        ];
    }
    bb_cikti([
        'satirlar'   => $satirlar,
        'katalog'    => ['sifatlar' => $katalog['sifatlar'], 'bildirimTurleri' => $katalog['bildirimTurleri']],
        'varsayilan' => bb_varsayilanlar($katalog),
    ]);
}

// ── TOPLU OLUŞTUR ────────────────────────────────────────────────────────
// Her satır BAĞIMSIZ değerlendirilir: biri başarısız olursa diğerleri devam
// eder ve sonuç satır satır döner. "Hep ya da hiç" DEĞİLDİR — her taslak ayrı
// bir HKS bildirimine karşılık gelir; birini kurmamak diğerini geçersiz kılmaz
// ve zaten hiçbiri GÖNDERİLMEZ (gönderim Hal Kayıt ekranında).
case 'toplu_olustur': {
    csrf_check($govde['csrf'] ?? null);

    $sifatId = trim((string)($govde['sifatId'] ?? ''));
    $turId   = trim((string)($govde['bildirimTuruId'] ?? ''));
    $girdi   = (array)($govde['satirlar'] ?? []);
    if (!$girdi) bb_cikti(['hata' => 'Beyan seçilmedi.'], 400);
    if (count($girdi) > BB_TOPLU_LIMIT) {
        bb_cikti(['hata' => 'Tek seferde en fazla ' . BB_TOPLU_LIMIT . ' beyan işlenebilir.'], 400);
    }

    $userId  = (int)($auth_user['id'] ?? 0);
    $sonuclar = [];
    $basarili = 0;
    foreach ($girdi as $satir) {
        $bid = (int)($satir['beyan_id'] ?? 0);
        if ($bid <= 0) continue;
        $b = bb_beyan_yukle($bid);
        $r = bb_taslak_kur($b, $sifatId, $turId, num((string)($satir['fiyat'] ?? '')), $userId);
        if (!empty($r['tamam'])) $basarili++;
        $sonuclar[] = [
            'id'       => $bid,
            'partiNo'  => (string)($b['party_no'] ?? ''),
            'tamam'    => !empty($r['tamam']),
            'taslakId' => $r['taslakId'] ?? null,
            'hata'     => $r['hata'] ?? null,
        ];
    }
    bb_cikti([
        'tamam'    => true,
        'basarili' => $basarili,
        'toplam'   => count($sonuclar),
        'sonuclar' => $sonuclar,
        'mesaj'    => $basarili . ' / ' . count($sonuclar) . ' beyan için HKS taslağı oluşturuldu. ' .
                      'Gönderim Hal Kayıt ekranından yapılır — bildirim GÖNDERİLMEDİ.',
    ]);
}

default:
    bb_cikti(['hata' => 'Bilinmeyen eylem.'], 400);
}
