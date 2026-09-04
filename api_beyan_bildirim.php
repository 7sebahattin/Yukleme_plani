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
    return null;
}

// ── Katalog listeleri (ÖNBELLEKTEN — canlı servise gidilmez) ──────────────
function bb_katalog(): array {
    $cache = hks_kv_oku('listeler_cache', null);
    if (!is_array($cache) || empty($cache['urunler'])) return [];
    $sade = fn($l) => array_map(
        fn($x) => ['id' => (string)($x['id'] ?? ''), 'ad' => (string)($x['ad'] ?? '')],
        array_values((array)$l)
    );
    return [
        'urunler'         => $sade($cache['urunler']         ?? []),
        'ulkeler'         => $sade($cache['ulkeler']         ?? []),
        'sifatlar'        => $sade($cache['sifatlar']        ?? []),
        'bildirimTurleri' => $sade($cache['bildirimTurleri'] ?? []),
        'isletmeTurleri'  => $sade($cache['isletmeTurleri']  ?? []),
        'zaman'           => (string)($cache['zaman'] ?? ''),
    ];
}

// Serbest metni katalogda arar: önce öğrenilmiş eşleme, sonra TAM ad eşleşmesi.
// KISMİ (substring) eşleşme KASITLI OLARAK YAPILMAZ — "Üretici" ile "Üretici
// Birliği" farklı kayıtlardır ve yanlış tahmin geri alınamaz bir bildirime
// dönüşür. Bulunamazsa kullanıcı modalde kendisi seçer.
function bb_tahmin(string $tip, string $metin, array $liste): array {
    $metin = trim($metin);
    if ($metin === '') return ['id' => '', 'ad' => '', 'kaynak' => 'yok'];

    $ogrenilen = hks_eslesme_bul($tip, $metin);
    if ($ogrenilen) {
        return ['id' => (string)$ogrenilen['hks_id'], 'ad' => (string)$ogrenilen['hks_ad'], 'kaynak' => 'ogrenilen'];
    }
    $norm = hks_eslesme_norm($metin);
    foreach ($liste as $x) {
        if (hks_eslesme_norm((string)$x['ad']) === $norm) {
            return ['id' => (string)$x['id'], 'ad' => (string)$x['ad'], 'kaynak' => 'katalog'];
        }
    }
    return ['id' => '', 'ad' => '', 'kaynak' => 'yok'];
}

// Beyana bağlı yükleme planı — ülke ve (plaka boşsa) plaka için ikincil kaynak.
function bb_bagli_plan(array $beyan): ?array {
    if (empty($beyan['loading_record_id'])) return null;
    $st = db()->prepare("SELECT id, parti_no, on_plaka, arka_plaka, gidecek_ulke, alici, urun
                         FROM loading_records WHERE id = ?");
    $st->execute([(int)$beyan['loading_record_id']]);
    return $st->fetch() ?: null;
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

    $plan  = bb_bagli_plan($beyan);
    $firma = [];
    try {
        foreach (hks_db()->query('SELECT id, ad, vergi_no FROM ' . hks_tablo('firmalar') . ' ORDER BY ad') as $f) {
            $firma[] = ['id' => $f['id'], 'ad' => $f['ad']];
        }
    } catch (PDOException $e) { $firma = []; }

    // Ülke: beyanda yok — bağlı yükleme planındaki "gidecek ülke" tek ipucudur.
    $ulkeMetni = trim((string)($plan['gidecek_ulke'] ?? ''));

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
        'firmalar'=> $firma,
        'katalog' => $katalog,
        'tahmin'  => [
            'urun' => bb_tahmin('urun', (string)($beyan['product_name'] ?? ''), $katalog['urunler']),
            'ulke' => bb_tahmin('ulke', $ulkeMetni, $katalog['ulkeler']),
        ],
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

    $firmaId = trim((string)($govde['firmaId'] ?? ''));
    $urunId  = trim((string)($govde['urunId']  ?? ''));
    $ulkeId  = trim((string)($govde['ulkeId']  ?? ''));
    $sifatId = trim((string)($govde['sifatId'] ?? ''));
    $turId   = trim((string)($govde['bildirimTuruId'] ?? ''));
    $fiyat   = num((string)($govde['fiyat'] ?? ''));      // Türkçe ondalık (1.234,56)
    $plaka   = mb_strtoupper(trim((string)($beyan['vehicle_plate'] ?? '')), 'UTF-8');
    $kg      = round((float)$beyan['net_kg'], 3);

    if ($firmaId === '') bb_cikti(['hata' => 'HKS firması seçilmedi.'], 400);
    if ($urunId  === '') bb_cikti(['hata' => 'HKS ürünü seçilmedi.'], 400);
    if ($ulkeId  === '') bb_cikti(['hata' => 'Ülke seçilmedi.'], 400);
    if ($sifatId === '') bb_cikti(['hata' => 'Bildirimci sıfatı seçilmedi.'], 400);
    if ($turId   === '') bb_cikti(['hata' => 'Bildirim türü seçilmedi.'], 400);
    if ($fiyat <= 0)     bb_cikti(['hata' => 'Birim fiyat girilmedi.'], 400);

    $katalog = bb_katalog();
    if (!$katalog) bb_cikti(['hata' => 'HKS katalog listeleri yok — Hal Kayıt panelinden güncelleyin.'], 409);
    $ad = function (array $liste, string $id): string {
        foreach ($liste as $x) if ((string)$x['id'] === $id) return (string)$x['ad'];
        return '';
    };
    $urunAd = $ad($katalog['urunler'], $urunId);
    $ulkeAd = $ad($katalog['ulkeler'], $ulkeId);
    if ($urunAd === '') bb_cikti(['hata' => 'Seçilen ürün katalogda bulunamadı — listeleri güncelleyin.'], 400);
    if ($ulkeAd === '') bb_cikti(['hata' => 'Seçilen ülke katalogda bulunamadı — listeleri güncelleyin.'], 400);

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

    // Eşlemeleri öğren — bir sonraki beyanda otomatik gelsin.
    hks_eslesme_yaz('urun', (string)($beyan['product_name'] ?? ''), $urunId, $urunAd);
    $plan = bb_bagli_plan($beyan);
    if ($plan && trim((string)$plan['gidecek_ulke']) !== '') {
        hks_eslesme_yaz('ulke', (string)$plan['gidecek_ulke'], $ulkeId, $ulkeAd);
    }

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
