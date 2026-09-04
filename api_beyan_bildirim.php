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
$beyan_id = (int)($govde['beyan_id'] ?? $_GET['beyan_id'] ?? 0);
if ($beyan_id <= 0) bb_cikti(['hata' => 'Geçersiz beyan.'], 400);

$st = db()->prepare("SELECT * FROM customs_declarations WHERE id = ?");
$st->execute([$beyan_id]);
$beyan = $st->fetch();
if (!$beyan)                    bb_cikti(['hata' => 'Beyan bulunamadı.'], 404);
if (!empty($beyan['deleted_at'])) bb_cikti(['hata' => 'Arşivlenmiş beyan için bildirim yapılamaz.'], 400);

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
        // Ön-seçimler: sıfat/tür kural tabanlı, firma son kullanılandan.
        'varsayilan' => bb_varsayilanlar($katalog) + ['firmaId' => bb_son_firma()],
    ]);
}

// ── TASLAK OLUŞTUR ────────────────────────────────────────────────────────
case 'taslak_olustur': {
    csrf_check($govde['csrf'] ?? null);   // JSON-aware: 403 + JSON döner

    $hata = bb_on_kosul($beyan);
    if ($hata) bb_cikti(['hata' => $hata], 400);

    // "Her ürün için 1 kez bildirim" — 1 beyan = 1 ürün olduğundan, aktif
    // (taslak|gonderildi) bir bağ varsa ikincisi açılmaz. İptal/hata satırları
    // aktif sayılmaz; kullanıcı düzeltip yeniden deneyebilir.
    $aktif = beyan_hks_aktif($beyan_id);
    if ($aktif) {
        bb_cikti([
            'hata'  => $aktif['durum'] === 'gonderildi'
                ? 'Bu beyan için bildirim ZATEN GÖNDERİLDİ (' . fmt_datetime($aktif['created_at']) .
                  '). Mükerrer bildirim rüsum doğurur — tekrar gönderilmedi.'
                : 'Bu beyan için bekleyen bir HKS taslağı var. Hal Kayıt ekranından gönderin ' .
                  'ya da silin, sonra tekrar deneyin.',
            'aktif' => $aktif,
        ], 409);
    }

    // ── EŞLEŞTİRME: BEYANDAN okunur, istemciden DEĞİL ────────────────────
    // Kalıcı alanlar beyan formunda girilir. İstemcinin gönderdiği bir firma/
    // ürün/ülke kabul edilseydi, beyanda görünenden BAŞKA bir bildirim
    // oluşturulabilirdi — geri alınamaz bir çağrıda bu kabul edilemez.
    // (bb_on_kosul üçünün de dolu olduğunu yukarıda doğruladı.)
    $firmaId = trim((string)$beyan['hks_firma_id']);
    $urunId  = trim((string)$beyan['hks_urun_id']);
    $ulkeId  = trim((string)$beyan['hks_ulke_id']);

    // Bu ekranda girilenler — işlem anına ait.
    $sifatId = trim((string)($govde['sifatId'] ?? ''));
    $turId   = trim((string)($govde['bildirimTuruId'] ?? ''));
    $fiyat   = num((string)($govde['fiyat'] ?? ''));      // Türkçe ondalık (1.234,56)
    $plaka   = mb_strtoupper(trim((string)($beyan['vehicle_plate'] ?? '')), 'UTF-8');
    $kg      = round((float)$beyan['net_kg'], 3);

    if ($sifatId === '') bb_cikti(['hata' => 'Bildirimci sıfatı seçilmedi.'], 400);
    if ($turId   === '') bb_cikti(['hata' => 'Bildirim türü seçilmedi.'], 400);
    if ($fiyat <= 0)     bb_cikti(['hata' => 'Birim fiyat girilmedi.'], 400);

    $katalog = bb_katalog();
    if (!$katalog) bb_cikti(['hata' => 'HKS katalog listeleri yok — Hal Kayıt panelinden güncelleyin.'], 409);

    // "Alım" türü bu ekranda seçilemez (bb_katalog listeden çıkarır); gövdeden
    // elle gönderilmiş bir id de kabul edilmemeli — liste dışı id reddedilir.
    $katalogda = function (array $liste, string $id): bool {
        foreach ($liste as $x) if ((string)$x['id'] === $id) return true;
        return false;
    };
    if (!$katalogda($katalog['sifatlar'], $sifatId)) {
        bb_cikti(['hata' => 'Geçersiz bildirimci sıfatı — listeleri güncelleyin.'], 400);
    }
    if (!$katalogda($katalog['bildirimTurleri'], $turId)) {
        bb_cikti(['hata' => 'Bu bildirim türü beyan ekranından kullanılamaz ' .
                            '(alım türleri referanssızdır — Hal Kayıt panelinden yapılır).'], 400);
    }

    // Ürün/ülke ADI beyanda saklı; katalog güncellendiyse tazelenir.
    $ad = function (array $liste, string $id, string $yedek): string {
        foreach ($liste as $x) if ((string)$x['id'] === $id) return (string)$x['ad'];
        return $yedek;
    };
    $urunAd = $ad($katalog['urunler'], $urunId, (string)$beyan['hks_urun_ad']);
    $ulkeAd = $ad($katalog['ulkeler'], $ulkeId, (string)$beyan['hks_ulke_ad']);
    if ($urunAd === '') bb_cikti(['hata' => 'Beyandaki HKS ürünü katalogda bulunamadı — beyanı düzenleyip yeniden seçin.'], 400);
    if ($ulkeAd === '') bb_cikti(['hata' => 'Beyandaki ülke katalogda bulunamadı — beyanı düzenleyip yeniden seçin.'], 400);

    // İşletme türü: yurt dışı ihracat akışında app.html'in `oto.isletmeTuruId`
    // ile yaptığının aynısı — katalogdan "yurt dışı" adlı kayıt bulunur.
    $isletmeTuruId = '';
    foreach ($katalog['isletmeTurleri'] as $x) {
        $n = hks_eslesme_norm((string)$x['ad']);
        if (strpos($n, 'yurt dışı') !== false || strpos($n, 'yurt disi') !== false || strpos($n, 'yurtdışı') !== false) {
            $isletmeTuruId = (string)$x['id']; break;
        }
    }
    if ($isletmeTuruId === '') {
        bb_cikti(['hata' => 'Katalogda "Yurt Dışı" işletme türü bulunamadı — Hal Kayıt panelinden listeleri güncelleyin.'], 409);
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
            'kaynak' => ['tip' => 'beyan', 'beyanId' => $beyan_id,
                         'partiNo' => (string)($beyan['party_no'] ?? '')],
        ],
    ];

    // TEK yazma yolu — doğrulama dahil (halkayit/taslak_lib.php)
    $sonuc = hks_taslak_olustur($g);
    if (!empty($sonuc['hata'])) bb_cikti(['hata' => $sonuc['hata']], $sonuc['kod'] ?? 400);

    // Bağ kaydı
    $ins = db()->prepare("INSERT INTO beyan_hks_bildirim
        (beyan_id, hks_firma_id, hks_firma_ad, taslak_id, durum,
         urun_id, urun_ad, ulke_id, ulke_ad, plaka, kg, fiyat, created_by, created_at)
        VALUES (?,?,?,?,'taslak',?,?,?,?,?,?,?,?,NOW())");
    $ins->execute([$beyan_id, $firmaId, $sonuc['firmaAd'], $sonuc['id'],
        $urunId, $urunAd, $ulkeId, $ulkeAd, $plaka, $kg, $fiyat,
        (int)($auth_user['id'] ?? 0)]);
    beyan_hks_durum_tazele($beyan_id);

    // NOT: Eşleme öğrenmesi (hks_eslesme) artık BEYAN KAYDEDİLİRKEN yapılıyor
    // (beyan_create.php / beyan_edit.php) — seçim orada yapıldığı için. Burada
    // tekrarlanmaz; iki yazıcı olsa aynı anahtar farklı anlarda ezilirdi.

    // Rüsum doğuran zincirin ilk halkası — audit ŞART.
    audit_log_event('hks_taslak_olustur', 'beyan', $beyan_id, null, [
        'taslak_id' => $sonuc['id'], 'firma_id' => $firmaId,
        'urun' => $urunAd, 'ulke' => $ulkeAd, 'plaka' => $plaka,
        'kg' => $kg, 'fiyat' => $fiyat,
    ]);

    bb_cikti([
        'tamam'    => true,
        'taslakId' => $sonuc['id'],
        'mesaj'    => 'HKS taslağı oluşturuldu. Gönderim Hal Kayıt ekranından yapılır — ' .
                      'bildirim GÖNDERİLMEDİ.',
    ]);
}

default:
    bb_cikti(['hata' => 'Bilinmeyen eylem.'], 400);
}
