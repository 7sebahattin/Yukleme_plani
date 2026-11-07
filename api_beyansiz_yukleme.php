<?php
// =============================================================================
// api_beyansiz_yukleme.php — "Yükleme Bildir" (beyansız yükleme) JSON ucu — v498
// =============================================================================
// EYLEMLER:
//   hazirla       → modalin listeleri (firma/ülke/ürün/yurt içi firma)
//   plaka_kontrol → bugün aynı plakaya kayıt var mı (SALT OKUNUR)
//   olustur       → doğrular, beyansiz_yukleme satırı + HKS TASLAĞI yazar
//
// TASARIM:
//  • HKS'e HİÇBİR ŞEY GÖNDERİLMEZ; yalnız taslak açılır. Gönderim Hal Kayıt'ta,
//    fiyat o anda girilir (ortak.fiyatBekliyor). Taslak yazmanın TEK yolu
//    halkayit/taslak_lib.php hks_taslak_olustur().
//  • Sunucu istemciden YALNIZ id alır; ad, TC, cep, doğum katalog/havuzdan
//    sunucuda çözülür (istemcinin gönderdiği ad/TC KULLANILMAZ).
//  • Yurt dışı (ülke seçildi) = ihracat; yurt içi (kişi/firma seçildi) EKSİK
//    taslak (ortak.tamamlanacak) — Hal Kayıt'ta Düzenle ile tamamlanır.
// =============================================================================
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/beyansiz_yukleme.php';
require_once __DIR__ . '/halkayit/taslak_lib.php';

header('Content-Type: application/json; charset=utf-8');

function bsz_cikti(array $veri, int $kod = 200): void {
    http_response_code($kod);
    echo json_encode($veri, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Kapı sırası: oturum 401 → yetki 403 → sonra gövde/DB ──────────────────
$auth_user = function_exists('current_user') ? current_user() : null;
if ($auth_user === null) bsz_cikti(['hata' => 'Oturum gerekli. Lütfen tekrar giriş yapın.'], 401);
if (!can_yukleme_bildir()) bsz_cikti(['hata' => 'Bu işlem için yetkiniz yok (Yükleme Bildir).'], 403);

$govde = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($govde)) $govde = [];
$action = (string)($_GET['action'] ?? ($govde['action'] ?? ''));

function bsz_katalogda(array $liste, string $id): ?array {
    foreach ($liste as $x) if ((string)$x['id'] === $id) return $x;
    return null;
}

function bsz_katalog_hata(): array {
    return ['hata' => 'HKS katalog listeleri henüz indirilmemiş. Hal Kayıt panelini açıp ' .
                      'bir firma seçin ve "Listeleri Güncelle"ye basın.', 'katalogYok' => true];
}

switch ($action) {

// ── HAZIRLA ───────────────────────────────────────────────────────────────
case 'hazirla': {
    $tabloVar = beyansiz_tablo_var();
    $katalog = bb_katalog();
    $firmalar = bsz_firma_secenekleri();
    bsz_cikti([
        'tabloVar'   => $tabloVar,
        'katalogYok' => !$katalog,
        'firmalar'   => $firmalar,
        'ulkeler'    => $katalog['ulkeler'] ?? [],
        'urunler'    => $katalog['urunler'] ?? [],
        'kisiler'    => $tabloVar && $katalog ? bsz_kisi_secenekleri() : [],
    ]);
}

// ── PLAKA KONTROL (salt okunur) ───────────────────────────────────────────
case 'plaka_kontrol': {
    $plaka = beyan_plaka_normalize((string)($govde['plaka'] ?? $_GET['plaka'] ?? ''));
    bsz_cikti(['plaka' => $plaka, 'liste' => $plaka === '' ? [] : bsz_ayni_plaka_bugun($plaka)]);
}

// ── OLUŞTUR ───────────────────────────────────────────────────────────────
case 'olustur': {
    csrf_check($govde['csrf'] ?? null);   // JSON-aware: 403 + JSON
    if (!beyansiz_tablo_var()) {
        bsz_cikti(['hata' => 'Beyansız yükleme tablosu kurulu değil — yönetici migrate.php sayfasından kurmalı.', 'tabloYok' => true], 409);
    }
    $katalog = bb_katalog();
    if (!$katalog) bsz_cikti(bsz_katalog_hata(), 409);

    $userId = (int)($auth_user['id'] ?? 0);
    $firmaId = trim((string)($govde['firmaId'] ?? ''));
    $yon = (string)($govde['yon'] ?? '') === BSZ_YON_ICI ? BSZ_YON_ICI : BSZ_YON_DISI;
    $urunId = trim((string)($govde['urunId'] ?? ''));
    $ulkeId = trim((string)($govde['ulkeId'] ?? ''));
    $kisiId = (int)($govde['kisiId'] ?? 0);
    $yeniAd = trim(preg_replace('/\s+/u', ' ', (string)($govde['yeniFirmaAd'] ?? '')));   // yalnız yurt içi: listede olmayan firma (yalnız ad)
    $plaka = beyan_plaka_normalize((string)($govde['plaka'] ?? ''));
    $partiNo = trim((string)($govde['partiNo'] ?? ''));
    $istekKodu = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($govde['istek_kodu'] ?? ''));
    $plakaOnay = !empty($govde['plakaOnay']);
    // Net KG: tam ya da ondalık sayı (Türkçe 1.234,5 → num())
    $kgHam = $govde['kg'] ?? '';
    $kg = (is_int($kgHam) || is_float($kgHam)) ? (float)$kgHam : num((string)$kgHam);   // metin: Türkçe 1.234,5
    $kg = round($kg, 3);

    if ($firmaId === '') bsz_cikti(['hata' => 'HKS firması seçilmedi.'], 400);
    $firmaAd = '';
    foreach (bsz_firma_secenekleri() as $f) if ($f['id'] === $firmaId) { $firmaAd = $f['ad']; break; }
    if ($firmaAd === '') bsz_cikti(['hata' => 'HKS firması bulunamadı.'], 400);
    $urun = bsz_katalogda($katalog['urunler'], $urunId);
    if (!$urun) bsz_cikti(['hata' => 'Ürün seçilmedi ya da katalogda yok.'], 400);
    if ($kg <= 0 || $kg >= 1.0e9) bsz_cikti(['hata' => 'Net KG geçersiz.'], 400);
    if ($plaka === '' || strlen($plaka) > 30) bsz_cikti(['hata' => 'Araç plakası girilmeli.'], 400);
    if (strlen($partiNo) > 60) bsz_cikti(['hata' => 'Parti No çok uzun (en çok 60).'], 400);
    if ($istekKodu === '' || strlen($istekKodu) > 40) bsz_cikti(['hata' => 'Geçersiz istek kodu — sayfayı yenileyin.'], 400);

    // Varsayılanlar: sıfat "İhracat", tür "Satış" (kural tabanlı; id UYDURULMAZ)
    $var = bb_varsayilanlar($katalog);
    if ($var['bildirimTuruId'] === '') bsz_cikti(['hata' => 'Katalogda "Satış" bildirim türü bulunamadı — Hal Kayıt panelinden listeleri güncelleyin.'], 409);

    $ulkeAd = ''; $kisi = null; $isletmeTuruId = '';
    if ($yon === BSZ_YON_DISI) {
        $ulke = bsz_katalogda($katalog['ulkeler'], $ulkeId);
        if (!$ulke) bsz_cikti(['hata' => 'Ülke seçilmedi ya da katalogda yok.'], 400);
        $ulkeAd = (string)$ulke['ad'];
        if ($var['sifatId'] === '') bsz_cikti(['hata' => 'Katalogda "İhracat" sıfatı bulunamadı — Hal Kayıt panelinden listeleri güncelleyin.'], 409);
        $isletmeTuruId = bb_yurtdisi_isletme_turu($katalog);
        if ($isletmeTuruId === '') bsz_cikti(['hata' => 'Katalogda "Yurt Dışı" işletme türü bulunamadı — Hal Kayıt panelinden listeleri güncelleyin.'], 409);
    } else {
        // İşletme türü (gidilen yer) BURADA seçilmez: fiyatı giren kişi Hal Kayıt'ta seçer.
        if ($yeniAd !== '') {
            if (mb_strlen($yeniAd) < 3 || mb_strlen($yeniAd) > 150) bsz_cikti(['hata' => 'Firma adı 3-150 karakter olmalı.'], 400);
            $kisiId = 0;                 // yeni firma: yalnız ad; diğer bilgiler taslakta tamamlanır
        } elseif ($kisiId <= 0) {
            bsz_cikti(['hata' => 'Firma seçilmedi.'], 400);
        }
        if ($kisiId > 0) try {
            $st = db()->prepare("SELECT id, tc, ad, cep, dogum, sifat_id FROM `" . bsz_hks_on_ek() . "kisiler` WHERE id = ?");
            $st->execute([$kisiId]);
            $kisi = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { $kisi = null; }
        if ($kisiId > 0 && (!$kisi || strlen(preg_replace('/\D/', '', (string)$kisi['tc'])) !== 10)) bsz_cikti(['hata' => 'Firma Kişi Havuzu\'nda bulunamadı (yalnız firmalar seçilebilir).'], 400);
    }

    // Aynı gün aynı plaka: ENGEL DEĞİL, onay ister
    if (!$plakaOnay) {
        $ayni = bsz_ayni_plaka_bugun($plaka);
        if ($ayni) bsz_cikti(['hata' => 'Bugün bu plakaya daha önce kayıt yapılmış.', 'kod' => 'ayni_plaka', 'liste' => $ayni], 409);
    }

    // Çift tıklama / yeniden gönderim: istek_kodu UNIQUE ile atomik
    $pdo = db();
    try {
        $ins = $pdo->prepare("INSERT INTO beyansiz_yukleme
            (parti_no, hks_firma_id, hks_firma_ad, yon, ulke_id, ulke_ad, karsi_kisi_id, karsi_kisi_ad,
             urun_id, urun_ad, kg, plaka, durum, istek_kodu, created_by, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'taslak',?,?,NOW())");
        $ins->execute([
            $partiNo !== '' ? $partiNo : null, $firmaId, $firmaAd, $yon,
            $yon === BSZ_YON_DISI ? $ulkeId : null, $yon === BSZ_YON_DISI ? $ulkeAd : null,
            $kisi ? (int)$kisi['id'] : null, $kisi ? (string)$kisi['ad'] : ($yon === BSZ_YON_ICI ? $yeniAd : null),
            $urunId, (string)$urun['ad'], $kg, $plaka, $istekKodu, $userId ?: null,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') bsz_cikti(['hata' => 'Bu istek zaten işlendi. Listeyi yenileyin.', 'kod' => 'tekrar'], 409);
        error_log('[beyansiz] insert: ' . get_class($e));
        bsz_cikti(['hata' => 'Kayıt yazılamadı.'], 500);
    }
    $kayitId = (int)$pdo->lastInsertId();

    // Ortak alan
    if ($yon === BSZ_YON_DISI) {
        $ortak = bsz_ortak_yurtdisi([
            'sifatId' => $var['sifatId'], 'turId' => $var['bildirimTuruId'], 'urunId' => $urunId,
            'urunAd' => (string)$urun['ad'], 'plaka' => $plaka, 'isletmeTuruId' => $isletmeTuruId,
            'ulkeId' => $ulkeId, 'ulkeAd' => $ulkeAd, 'kg' => $kg, 'kayitId' => $kayitId, 'partiNo' => $partiNo,
        ]);
    } else {
        $dg = '';
        if (!empty($kisi['dogum']) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string)$kisi['dogum'], $m)) $dg = "$m[3].$m[2].$m[1]";
        $turAd = (string)(bsz_katalogda($katalog['bildirimTurleri'], $var['bildirimTuruId'])['ad'] ?? '');
        $ortak = bsz_ortak_yurtici([
            'turId' => $var['bildirimTuruId'], 'turAd' => $turAd, 'urunId' => $urunId, 'urunAd' => (string)$urun['ad'],
            'plaka' => $plaka, 'kg' => $kg, 'kayitId' => $kayitId, 'partiNo' => $partiNo,
            'ikinciTc' => (string)($kisi['tc'] ?? ''), 'ikinciAd' => $kisi ? (string)$kisi['ad'] : $yeniAd,
            'ikinciCep' => (string)($kisi['cep'] ?? ''),
            'ikinciDogumTarihi' => $dg, 'ikinciSifatId' => $kisi['sifat_id'] ?? '',
        ]);
    }

    // TEK yazma yolu (doğrulama dahil)
    $sonuc = hks_taslak_olustur(['firmaId' => $firmaId, 'satirlar' => [], 'ortak' => $ortak]);
    if (!empty($sonuc['hata'])) {
        try { $pdo->prepare("DELETE FROM beyansiz_yukleme WHERE id = ?")->execute([$kayitId]); } catch (Throwable $e) {}
        bsz_cikti(['hata' => $sonuc['hata']], (int)($sonuc['kod'] ?? 400));
    }
    try {
        $pdo->prepare("UPDATE beyansiz_yukleme SET taslak_id = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$sonuc['id'], $kayitId]);
    } catch (Throwable $e) {
        error_log('[beyansiz] taslak_id yazilamadi: ' . get_class($e));
    }
    bb_son_firma_yaz($firmaId);

    // Kişisel veri YOK: ad/TC/cep/doğum audit'e yazılmaz
    audit_log_event('beyansiz_yukleme_olustur', 'beyansiz_yukleme', $kayitId, null, [
        'taslak_id' => $sonuc['id'], 'firma_id' => $firmaId, 'yon' => $yon,
        'urun' => (string)$urun['ad'], 'kg' => $kg, 'plaka' => $plaka,
    ]);
    bsz_cikti([
        'tamam'    => true,
        'kayitId'  => $kayitId,
        'taslakId' => $sonuc['id'],
        'mesaj'    => $yon === BSZ_YON_ICI
            ? 'Eksik HKS taslağı oluşturuldu. Hal Kayıt\'ta Düzenle ile tamamlayıp fiyatla gönderin — bildirim GÖNDERİLMEDİ.'
            : 'HKS taslağı oluşturuldu. Fiyat Hal Kayıt\'ta gönderimde girilir — bildirim GÖNDERİLMEDİ.',
    ]);
}

default:
    bsz_cikti(['hata' => 'Bilinmeyen eylem.'], 400);
}
