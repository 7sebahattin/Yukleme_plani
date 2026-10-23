<?php
// =========================================================
// config/siparis_eslestir.php — Belge işleme + sevkiyata bağlama + alan otoritesi
//
// Akış (siparis_belge_isle): belge türü İÇERİKTEN (PO / LL / PC / PKL / diğer) →
// birimler (PO: her loading; LL: her shipment; PC: order_ref grupları; PKL:
// order_refs) → sevkiyat bul/oluştur (tedarikçi + order_ref; geliş sırası
// önemsiz, LL/PC önce gelirse "PO bekleniyor" taslağı açılır) → bağ + revizyon
// (aynı türün daha eski belgesi bağlanır ama gecerli=0, değer UYGULAMAZ) →
// alan uygulama (siparis_alan_uygula, otorite tablosu siparis_alan_tanimlari)
// → yeniden hesap (siparis_sevkiyat_yenile).
//
// Kurallar:
//  • ELLE girilen değer ASLA ezilmez: elle_alanlar kilidi; sonraki belge yalnız
//    siparis_catismalar satırı açar. Boş gelen değer veri SİLMEZ.
//  • Miktarlar çatışma ÜRETMEZ: kaynak başına ayrı kolon (po_*, ll_*, pc_koli);
//    etkin = elle > PC > LL > PO; kalibre bölümü farklıysa split_differs uyarısı.
//  • İdempotent: aynı belge yeniden işlenince satır/olay sayısı değişmez.
//  • Hiçbir belge sevkiyat SİLMEZ; belgede olmayan kalem yalnız _yok ile işaretlenir.
//
// TEST KANCASI (YALNIZ testler): $GLOBALS['_siparis_parse_override'] = fn(string $yol): array
//   tanımlıysa siparis_belge_isle() PDF için siparis_pdf_isle(), XLSX için siparis_pkl_oku()
//   yerine onun döndürdüğü diziyi kullanır. Üretim kodunda ASLA atanmaz.
// =========================================================
declare(strict_types=1);

require_once __DIR__ . '/siparis_core.php';
require_once __DIR__ . '/siparis_pdf.php';
require_once __DIR__ . '/siparis_pkl.php';

/** Belge türü etiketleri (olay/ekran metinleri). */
function siparis_belge_tur_etiketleri(): array
{
    return ['po' => 'PO', 'll' => 'Loading List', 'pc' => 'Price Confirmation', 'pkl' => 'PKL', 'diger' => 'Diğer', 'bilinmiyor' => 'Tanınmayan'];
}

/**
 * Düzenlenebilir alanlar + kaynak otoritesi (büyük sayı = daha yetkili; eşit sıra farklı kaynak = çatışma).
 * Düzenlenemez: po_*, ll_*, pc_koli, cw_*, po_belge_id, var_*.
 * @return array{sevkiyat:array<string,array>, kalem:array<string,array>}
 */
function siparis_alan_tanimlari(): array
{
    $a = fn(string $etiket, string $tip, int $max, array $otorite, bool $elle = true): array
        => ['etiket' => $etiket, 'tip' => $tip, 'max' => $max, 'otorite' => $otorite, 'elle' => $elle];
    return [
        'sevkiyat' => [
            'tur_no'              => $a('Tur no', 'metin', 30, ['po' => 1, 'll' => 1]),
            'yukleme_tarihi'      => $a('Yükleme tarihi', 'tarih', 10, ['po' => 1, 'll' => 2]),
            'yukleme_yeri'        => $a('Yükleme yeri', 'metin', 150, ['po' => 1, 'll' => 2]),
            'bosaltma_tarihi'     => $a('Boşaltma tarihi', 'tarih', 10, ['ll' => 1, 'pc' => 2]),
            'bosaltma_yerleri'    => $a('Boşaltma yerleri', 'metin', 255, ['po' => 1, 'll' => 2]),
            'alici_kodu'          => $a('Alıcı kodu', 'metin', 20, ['po' => 1]),
            'alici_adi'           => $a('Alıcı', 'metin', 100, ['po' => 1]),
            'sold_to'             => $a('Satış yapılan (sold-to)', 'metin', 150, ['po' => 1, 'pc' => 2]),
            'gumruk'              => $a('Yurt dışı gümrük', 'metin', 150, ['po' => 1]),     // v387: PO "Customs" = yurt dışı (sınır) gümrüğü
            'yurtici_gumruk'      => $a('Yurt içi gümrük', 'metin', 150, []),          // v387: yalnız elle; kolon migrate.php kartından
            'kdv_no'              => $a('KDV no', 'metin', 40, ['po' => 1]),
            'incoterm'            => $a('Incoterm', 'metin', 10, ['po' => 1]),
            'planlanan_nakliyeci' => $a('Planlanan nakliyeci', 'metin', 150, ['po' => 1]),
            'planlanan_plakalar'  => $a('Planlanan plaka', 'plaka', 60, ['po' => 1]),
            'nakliyeci'           => $a('Nakliyeci', 'metin', 150, ['ll' => 1]),
            'plakalar'            => $a('Plakalar', 'plaka', 60, ['ll' => 1]),
            'navlun_no'           => $a('Navlun (Freight Order) no', 'metin', 30, ['ll' => 1]),
            'notlar'              => $a('Notlar', 'metin', 2000, []),          // yalnız elle (kaynak sistemi dışı)
            'sofor_adi'           => $a('Şoför adı', 'metin', 100, []),         // v377: yalnız elle (kaynak sistemi dışı); kolonlar migrate.php kartından
            'sofor_telefon'       => $a('Şoför telefonu', 'telefon', 40, []),
        ],
        'kalem' => [
            'urun'        => $a('Ürün', 'metin', 80, ['po' => 1, 'pc' => 1]),
            'aciklama'    => $a('Açıklama', 'metin', 150, ['po' => 1, 'pc' => 1]),
            'cesit'       => $a('Çeşit', 'metin', 60, ['po' => 1, 'pc' => 1]),
            'artikel_no'  => $a('Artikel no', 'metin', 20, ['po' => 1]),
            'koli_icerik' => $a('Koli içeriği', 'metin', 20, ['po' => 1]),
            'ambalaj'     => $a('Ambalaj', 'metin', 100, ['po' => 1]),
            'palet_tipi'  => $a('Palet tipi', 'metin', 10, ['po' => 1, 'll' => 1]),
            'pf'          => $a('Palet başına koli (PF)', 'sayi', 10, ['po' => 1, 'll' => 1]),
            'fiyat'       => $a('Fiyat', 'ondalik', 20, ['pc' => 1]),
            'para_birimi' => $a('Para birimi', 'metin', 3, ['pc' => 1]),
            'fiyat_birim' => $a('Fiyat birimi', 'metin', 10, ['pc' => 1]),
            'etkin_palet' => $a('Etkin palet', 'sayi', 10, []),                // elle kilit; değilse hesaplanır
            'etkin_koli'  => $a('Etkin koli', 'sayi', 10, []),
        ],
    ];
}

// ── DEĞER NORMALLEŞTİRME ────────────────────────────────────────────────

/** Tarih → Y-m-d, sayı → kanonik metin, metin → kırpılmış/boşluk teklenmiş; boş → null. */
function siparis_deger_normalize(mixed $v, string $tip): ?string
{
    if ($v === null || is_array($v) || is_bool($v)) return null;
    if (is_string($v) && trim($v) === '') return null;
    switch ($tip) {
        case 'tarih':
            return siparis_tarih_coz(is_int($v) ? (string)$v : (string)$v);
        case 'sayi':
            if (is_int($v)) return (string)$v;
            if (is_float($v)) return is_finite($v) ? (string)(int)round($v) : null;
            $s = trim((string)$v);
            if (preg_match('/^-?\d+$/', $s)) return (string)(int)$s;
            if (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) return (string)(int)str_replace('.', '', $s);   // TR binlik "1.600"
            if (is_numeric($s)) return (string)(int)round((float)$s);
            if (!preg_match('/^-?[\d.,\s]+$/', $s)) return null;
            return (string)(int)round(num($s));
        case 'ondalik':
            if (is_int($v) || is_float($v)) $f = (float)$v;
            else {
                $s = trim((string)$v);
                if (is_numeric($s)) $f = (float)$s;                     // DB / ayrıştırıcı: nokta ondalık
                elseif (preg_match('/^-?[\d.,\s]+$/', $s)) $f = num($s); // kullanıcı: TR biçimi
                else return null;
            }
            if (!is_finite($f)) return null;
            $t = rtrim(rtrim(sprintf('%.4F', $f), '0'), '.');
            return $t === '-0' ? '0' : $t;
        case 'plaka':
            return siparis_plaka_normalize((string)$v)['deger'];
        case 'telefon':          // v377: yalnız rakam ve + ( ) - . boşluk; 7–15 hane, aksi geçersiz (null)
            $t = trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[^\d+()\-.\s]/u', '', (string)$v)));
            $hane = preg_match_all('/\d/', $t);
            return ($hane >= 7 && $hane <= 15) ? $t : null;
        default:
            $s = trim((string)preg_replace('/\s+/u', ' ', (string)$v));
            return $s === '' ? null : $s;
    }
}

/** Normalleştirilmiş iki değer eşit mi (metin/plaka büyük-küçük harf duyarsız). */
function siparis_deger_esit(?string $a, ?string $b, string $tip): bool
{
    if ($a === null || $b === null) return $a === $b;
    if ($tip === 'sayi' || $tip === 'ondalik') return is_numeric($a) && is_numeric($b) ? abs((float)$a - (float)$b) < 1e-9 : $a === $b;
    if ($tip === 'tarih') return $a === $b;
    return mb_strtoupper($a, 'UTF-8') === mb_strtoupper($b, 'UTF-8');
}

/** varlık → tablo (beyaz liste). */
function siparis_varlik_tablo(string $varlik): string
{
    return match ($varlik) {
        'sevkiyat' => 'siparis_sevkiyatlar',
        'kalem'    => 'siparis_kalemler',
        default    => throw new InvalidArgumentException('Geçersiz varlık: ' . $varlik),
    };
}

/** Kaynağın alan üzerindeki otorite sırası ('elle' her zaman en üstte; yetkisiz kaynak 0). */
function siparis_kaynak_sira(array $tanim, ?string $kaynak): int
{
    if ($kaynak === null) return 0;
    if ($kaynak === 'elle') return 99;
    return (int)($tanim['otorite'][$kaynak] ?? 0);
}

/** Kaynak etiketi (olay metinleri). */
function siparis_kaynak_etiket(?string $k): string
{
    return match ($k) { 'po' => 'PO', 'll' => 'LL', 'pc' => 'PC', 'pkl' => 'PKL', 'elle' => 'Elle', default => '—' };
}

// ── ÇATIŞMA ─────────────────────────────────────────────────────────────

/**
 * Çatışma upsert: (sevkiyat, kalem, alan) başına en çok bir 'acik' satır; varsa gelen_* ve belge
 * tazelenir. Aynı gelen_deger daha önce 'red' edildiyse yeni satır AÇILMAZ. @return bool yeni satır açıldı mı
 */
function siparis_catisma_ac(PDO $pdo, int $sid, ?int $kalemId, string $alan, ?string $mevcut, ?string $mevcutKaynak, ?string $gelen, string $gelenKaynak, ?int $belgeId): bool
{
    $kalemSql = $kalemId === null ? 'kalem_id IS NULL' : 'kalem_id = ?';
    $par = $kalemId === null ? [$sid, $alan] : [$sid, $alan, $kalemId];
    $st = $pdo->prepare("SELECT id, durum, gelen_deger FROM siparis_catismalar WHERE sevkiyat_id = ? AND alan = ? AND $kalemSql AND durum IN ('acik', 'red') ORDER BY id DESC");
    $st->execute($par);
    $acik = null;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['durum'] === 'acik' && $acik === null) $acik = $r;
        if ($r['durum'] === 'red' && (string)$r['gelen_deger'] === (string)$gelen) return false;   // reddedilmiş aynı değer
    }
    if ($acik !== null) {
        $pdo->prepare('UPDATE siparis_catismalar SET mevcut_deger = ?, mevcut_kaynak = ?, gelen_deger = ?, gelen_kaynak = ?, belge_id = ? WHERE id = ?')
            ->execute([siparis_kirp($mevcut), $mevcutKaynak, siparis_kirp($gelen), $gelenKaynak, $belgeId, (int)$acik['id']]);
        return false;
    }
    $pdo->prepare('INSERT INTO siparis_catismalar (sevkiyat_id, kalem_id, alan, mevcut_deger, mevcut_kaynak, gelen_deger, gelen_kaynak, belge_id, durum, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$sid, $kalemId, $alan, siparis_kirp($mevcut), $mevcutKaynak, siparis_kirp($gelen), $gelenKaynak, $belgeId, 'acik', siparis_simdi()]);
    siparis_olay($pdo, $sid, 'catisma', $mevcut, $gelen, siparis_alan_etiketi($kalemId === null ? 'sevkiyat' : 'kalem', $alan)
        . ': ' . siparis_kaynak_etiket($mevcutKaynak) . ' ≠ ' . siparis_kaynak_etiket($gelenKaynak), $belgeId, null);
    return true;
}

/** Alanın Türkçe etiketi. */
function siparis_alan_etiketi(string $varlik, string $alan): string
{
    return (string)(siparis_alan_tanimlari()[$varlik][$alan]['etiket'] ?? $alan);
}

// ── ALAN UYGULAMA ───────────────────────────────────────────────────────

/** v375 — aynı belgenin aynı kaynaktan açtığı, artık gelmeyen değerli AÇIK çatışmayı siler (kabul/red'e dokunmaz). */
function siparis_catisma_bayat_sil(PDO $pdo, int $sid, ?int $kalemId, string $alan, string $kaynak, int $belgeId, string $yeni): void
{
    if ($belgeId <= 0) return;
    $kalemSql = $kalemId === null ? 'kalem_id IS NULL' : 'kalem_id = ?';
    $par = [$sid, $alan, $kaynak, $belgeId, $yeni];
    if ($kalemId !== null) $par[] = $kalemId;
    $pdo->prepare("DELETE FROM siparis_catismalar WHERE sevkiyat_id = ? AND alan = ? AND gelen_kaynak = ? AND belge_id = ? AND durum = 'acik' AND (gelen_deger IS NULL OR gelen_deger <> ?) AND $kalemSql")->execute($par);
}

/**
 * Belgeden gelen değeri otorite kurallarıyla uygular:
 *  1 boş gelen → 'bos' (veri SİLİNMEZ)   2 kaynaklar[alan][src] = {v,b,t} HER ZAMAN saklanır
 *  · kaynak bu alanda yetkisizse → 'saklandi'
 *  3 elle kilitli + farklı → çatışma (mevcut_kaynak 'elle')   4 mevcut boş → uygula
 *  5 eşit → 'ayni'   6 daha yetkili kaynak → uygula + olay   7 aynı kaynak, yeni ya da eşit sıra → uygula + olay
 *  8 daha az yetkili kaynak ilk kez / aynı değeri tekrar → 'saklandi'; kendi değerini değiştirdiyse
 *    ya da eşit sıra farklı kaynaksa → çatışma.
 * @return 'uygulandi'|'ayni'|'catisma'|'saklandi'|'bos'
 */
function siparis_alan_uygula(PDO $pdo, string $varlik, int $id, string $alan, mixed $deger, string $kaynak, int $belgeId, string $belgeSira): string
{
    $tanim = siparis_alan_tanimlari()[$varlik][$alan] ?? null;
    if ($tanim === null) throw new InvalidArgumentException('Bilinmeyen alan: ' . $varlik . '.' . $alan);
    $tablo = siparis_varlik_tablo($varlik);
    $yeni = siparis_deger_normalize($deger, $tanim['tip']);
    if ($yeni === null) return 'bos';
    $yeni = siparis_kirp($yeni, max(1, (int)$tanim['max']));
    $st = $pdo->prepare("SELECT * FROM `$tablo` WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Kayıt bulunamadı: ' . $tablo . '#' . $id);
    $sid = $varlik === 'sevkiyat' ? $id : (int)$row['sevkiyat_id'];
    $kalemId = $varlik === 'kalem' ? $id : null;
    $kay = siparis_json_coz($row['kaynaklar']);
    $elle = siparis_json_coz($row['elle_alanlar']);
    $s = isset($kay[$alan]['_s']) ? (string)$kay[$alan]['_s'] : null;
    $sT = $s !== null ? (string)($kay[$alan][$s]['t'] ?? '') : '';
    if (!isset($kay[$alan]) || !is_array($kay[$alan])) $kay[$alan] = [];
    $onceki = isset($kay[$alan][$kaynak]['v']) ? siparis_deger_normalize($kay[$alan][$kaynak]['v'], $tanim['tip']) : null;
    $kay[$alan][$kaynak] = ['v' => $yeni, 'b' => $belgeId > 0 ? $belgeId : null, 't' => $belgeSira];
    $mevcut = siparis_deger_normalize($row[$alan] ?? null, $tanim['tip']);
    $kaydet = function (?string $deger, bool $degerYaz) use ($pdo, $tablo, $alan, $id, &$kay): void {
        if ($degerYaz) {
            $pdo->prepare("UPDATE `$tablo` SET `$alan` = ?, kaynaklar = ?, updated_at = ? WHERE id = ?")->execute([$deger, siparis_json($kay), siparis_simdi(), $id]);
        } else {
            $pdo->prepare("UPDATE `$tablo` SET kaynaklar = ? WHERE id = ?")->execute([siparis_json($kay), $id]);
        }
    };
    $etiket = $tanim['etiket'] . ($kalemId !== null ? ' (kalibre ' . $row['kalibre'] . ')' : '');
    $sira = siparis_kaynak_sira($tanim, $kaynak);
    if ($sira === 0) { $kaydet(null, false); return 'saklandi'; }

    if (isset($elle[$alan])) {
        $kaydet(null, false);
        if (siparis_deger_esit($mevcut, $yeni, $tanim['tip'])) return 'ayni';
        siparis_catisma_ac($pdo, $sid, $kalemId, $alan, $mevcut, 'elle', $yeni, $kaynak, $belgeId > 0 ? $belgeId : null);
        return 'catisma';
    }
    if ($mevcut === null) {
        $kay[$alan]['_s'] = $kaynak;
        $kaydet($yeni, true);
        siparis_catisma_bayat_sil($pdo, $sid, $kalemId, $alan, $kaynak, $belgeId, $yeni);
        return 'uygulandi';
    }
    if (siparis_deger_esit($mevcut, $yeni, $tanim['tip'])) {
        if ($sira > siparis_kaynak_sira($tanim, $s)) $kay[$alan]['_s'] = $kaynak;    // aynı değer daha yetkili kaynaktan
        $kaydet(null, false);
        siparis_catisma_bayat_sil($pdo, $sid, $kalemId, $alan, $kaynak, $belgeId, $yeni);
        return 'ayni';
    }
    if ($sira > siparis_kaynak_sira($tanim, $s) || ($kaynak === $s && strcmp($belgeSira, $sT) >= 0)) {
        $kay[$alan]['_s'] = $kaynak;
        $kaydet($yeni, true);
        siparis_olay($pdo, $sid, 'alan_guncellendi', $mevcut, $yeni, $etiket . ' — ' . siparis_kaynak_etiket($kaynak), $belgeId > 0 ? $belgeId : null, null);
        siparis_catisma_bayat_sil($pdo, $sid, $kalemId, $alan, $kaynak, $belgeId, $yeni);
        return 'uygulandi';
    }
    // Daha az yetkili kaynak: değeri İLK kez getiriyorsa ya da kendi önceki değerini TEKRARLIYORSA
    // çatışma açılmaz (yetkili kaynak zaten geçersiz kılmıştı — geliş sırasından bağımsız aynı sonuç);
    // yalnız KENDİ değerini DEĞİŞTİRDİYSE (yeni bilgi) çatışma açılır. Eşit sıra farklı kaynak → çatışma.
    if ($sira < siparis_kaynak_sira($tanim, $s) && ($onceki === null || siparis_deger_esit($onceki, $yeni, $tanim['tip']))) {
        $kaydet(null, false);
        return 'saklandi';
    }
    $kaydet(null, false);
    siparis_catisma_ac($pdo, $sid, $kalemId, $alan, $mevcut, $s, $yeni, $kaynak, $belgeId > 0 ? $belgeId : null);
    return 'catisma';
}

/** Sevkiyatın kendi açık çatışmalarını (alan için) 'red' olarak kapatır. */
function siparis_catisma_kapat(PDO $pdo, int $sid, ?int $kalemId, string $alan, int $uid): void
{
    $kalemSql = $kalemId === null ? 'kalem_id IS NULL' : 'kalem_id = ?';
    $par = [$uid, siparis_simdi(), $sid, $alan];
    if ($kalemId !== null) $par[] = $kalemId;
    $pdo->prepare("UPDATE siparis_catismalar SET durum = 'red', cozen = ?, cozuldu_at = ? WHERE sevkiyat_id = ? AND alan = ? AND $kalemSql AND durum = 'acik'")->execute($par);
}

/** Varlık satırını + sevkiyat id'sini getirir (silinmiş sevkiyat → null). */
function siparis_varlik_getir(PDO $pdo, string $varlik, int $id): ?array
{
    $tablo = siparis_varlik_tablo($varlik);
    $st = $pdo->prepare("SELECT * FROM `$tablo` WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $sid = $varlik === 'sevkiyat' ? $id : (int)$row['sevkiyat_id'];
    $sev = siparis_sevkiyat($pdo, $sid);
    if ($sev === null) return null;
    return ['row' => $row, 'sid' => $sid, 'sev' => $sev];
}

/**
 * ELLE düzeltme: beyaz liste + tip doğrulaması (plaka: siparis_plaka_normalize), değeri yazar,
 * KİLİTLER (_s='elle'), alanın açık çatışmalarını 'red' kapatır, olay alan_elle + audit
 * siparis_alan_duzenle {alan, varlik, id, order_ref}, sevkiyatı yeniden hesaplar.
 * @return array{ok:bool,hata?:string}
 */
function siparis_alan_elle(PDO $pdo, string $varlik, int $id, string $alan, mixed $deger, int $uid): array
{
    $tanim = siparis_alan_tanimlari()[$varlik][$alan] ?? null;
    if ($tanim === null || empty($tanim['elle'])) return ['ok' => false, 'hata' => 'Bu alan elle düzenlenemez.'];
    $ham = is_scalar($deger) ? trim((string)$deger) : '';
    $yeni = siparis_deger_normalize($ham, $tanim['tip']);
    if ($ham !== '' && $yeni === null) {
        return ['ok' => false, 'hata' => match ($tanim['tip']) {
            'tarih' => 'Tarih geçersiz (gg.aa.yyyy).', 'sayi' => 'Tam sayı girin.', 'ondalik' => 'Sayı girin.', 'telefon' => 'Telefon 7–15 haneli olmalı.', default => 'Değer geçersiz.' }];
    }
    if ($yeni !== null && $tanim['tip'] === 'sayi' && (int)$yeni < 0) return ['ok' => false, 'hata' => 'Negatif sayı girilemez.'];
    if ($yeni !== null && mb_strlen($yeni, 'UTF-8') > (int)$tanim['max']) return ['ok' => false, 'hata' => 'Değer çok uzun (en çok ' . $tanim['max'] . ' karakter).'];
    if ($yeni !== null && $alan === 'para_birimi') {
        $yeni = mb_strtoupper($yeni, 'UTF-8');
        if (!preg_match('/^[A-Z]{3}$/', $yeni)) return ['ok' => false, 'hata' => 'Para birimi 3 harfli kod olmalı (örn. EUR).'];
    }
    $uyari = $tanim['tip'] === 'plaka' && $ham !== '' ? siparis_plaka_normalize($ham)['uyari'] : null;
    try {
        $r = siparis_tx($pdo, function () use ($pdo, $varlik, $id, $alan, $yeni, $uid, $tanim): array {
            $v = siparis_varlik_getir($pdo, $varlik, $id);
            if ($v === null) return ['ok' => false, 'hata' => 'Kayıt bulunamadı.'];
            $row = $v['row']; $sid = $v['sid'];
            $tablo = siparis_varlik_tablo($varlik);
            $kay = siparis_json_coz($row['kaynaklar']);
            $elle = siparis_json_coz($row['elle_alanlar']);
            $simdi = siparis_simdi();
            if (!isset($kay[$alan]) || !is_array($kay[$alan])) $kay[$alan] = [];
            $kay[$alan]['elle'] = ['v' => $yeni, 'b' => null, 't' => $simdi];
            $kay[$alan]['_s'] = 'elle';
            $elle[$alan] = ['by' => $uid, 'at' => $simdi];
            $eski = siparis_deger_normalize($row[$alan] ?? null, $tanim['tip']);
            $ek = $varlik === 'sevkiyat' ? ', updated_by = ' . (int)$uid : '';
            $pdo->prepare("UPDATE `$tablo` SET `$alan` = ?, kaynaklar = ?, elle_alanlar = ?, updated_at = ?$ek WHERE id = ?")
                ->execute([$yeni, siparis_json($kay), siparis_json($elle), $simdi, $id]);
            siparis_catisma_kapat($pdo, $sid, $varlik === 'kalem' ? $id : null, $alan, $uid);
            siparis_olay($pdo, $sid, 'alan_elle', $eski, $yeni, $tanim['etiket'] . ($varlik === 'kalem' ? ' (kalibre ' . $row['kalibre'] . ')' : '') . ' elle düzeltildi', null, $uid);
            siparis_audit('siparis_alan_duzenle', $sid, null, ['alan' => $alan, 'varlik' => $varlik, 'id' => $id, 'order_ref' => $v['sev']['order_ref']], $uid);
            siparis_sevkiyat_yenile($pdo, $sid);
            return ['ok' => true];
        });
    } catch (PDOException $e) {
        error_log('[siparis_alan_elle] ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Değer kaydedilemedi.'];
    }
    if ($r['ok'] && $uyari !== null) $r['uyari'] = $uyari;
    return $r;
}

/**
 * Elle kilidi kaldırır ve en yetkili kaynağın (eşitlikte en yeni) değerini geri yükler (yoksa null).
 * Olay kilit_kaldirildi + audit siparis_kilit_kaldir. @return array{ok:bool,hata?:string}
 */
function siparis_alan_kilidi_kaldir(PDO $pdo, string $varlik, int $id, string $alan, int $uid): array
{
    $tanim = siparis_alan_tanimlari()[$varlik][$alan] ?? null;
    if ($tanim === null) return ['ok' => false, 'hata' => 'Bilinmeyen alan.'];
    try {
        return siparis_tx($pdo, function () use ($pdo, $varlik, $id, $alan, $uid, $tanim): array {
            $v = siparis_varlik_getir($pdo, $varlik, $id);
            if ($v === null) return ['ok' => false, 'hata' => 'Kayıt bulunamadı.'];
            $row = $v['row']; $sid = $v['sid'];
            $elle = siparis_json_coz($row['elle_alanlar']);
            if (!isset($elle[$alan])) return ['ok' => false, 'hata' => 'Bu alan kilitli değil.'];
            unset($elle[$alan]);
            $kay = siparis_json_coz($row['kaynaklar']);
            $en = null; $enSira = -1; $enT = '';
            foreach ((array)($kay[$alan] ?? []) as $src => $x) {
                if ($src === '_s' || $src === 'elle' || !is_array($x)) continue;
                $r = siparis_kaynak_sira($tanim, (string)$src);
                if ($r < 1) continue;
                $t = (string)($x['t'] ?? '');
                if ($r > $enSira || ($r === $enSira && strcmp($t, $enT) > 0)) { $en = (string)$src; $enSira = $r; $enT = $t; }
            }
            $deger = $en !== null ? siparis_deger_normalize($kay[$alan][$en]['v'] ?? null, $tanim['tip']) : null;
            if ($en !== null) $kay[$alan]['_s'] = $en; elseif (isset($kay[$alan])) unset($kay[$alan]['_s']);
            if (isset($kay[$alan]['elle'])) unset($kay[$alan]['elle']);
            $tablo = siparis_varlik_tablo($varlik);
            $eski = siparis_deger_normalize($row[$alan] ?? null, $tanim['tip']);
            $pdo->prepare("UPDATE `$tablo` SET `$alan` = ?, kaynaklar = ?, elle_alanlar = ?, updated_at = ? WHERE id = ?")
                ->execute([$deger, siparis_json($kay), $elle ? siparis_json($elle) : null, siparis_simdi(), $id]);
            siparis_olay($pdo, $sid, 'kilit_kaldirildi', $eski, $deger, $tanim['etiket'] . ': kilit kaldırıldı, kaynak ' . siparis_kaynak_etiket($en), null, $uid);
            siparis_audit('siparis_kilit_kaldir', $sid, null, ['alan' => $alan, 'varlik' => $varlik, 'id' => $id, 'order_ref' => $v['sev']['order_ref']], $uid);
            siparis_sevkiyat_yenile($pdo, $sid);
            return ['ok' => true];
        });
    } catch (PDOException $e) {
        error_log('[siparis_alan_kilidi_kaldir] ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Kilit kaldırılamadı.'];
    }
}

/**
 * Çatışma kararı: 'kabul' → gelen değer yazılır, _s = gelen kaynak, elle kilit kalkar;
 * 'red' → mevcut korunur. Olay + audit siparis_catisma_coz, yeniden hesap. @return array{ok:bool,hata?:string}
 */
function siparis_catisma_coz(PDO $pdo, int $catismaId, string $karar, int $uid): array
{
    if (!in_array($karar, ['kabul', 'red'], true)) return ['ok' => false, 'hata' => 'Geçersiz karar.'];
    try {
        return siparis_tx($pdo, function () use ($pdo, $catismaId, $karar, $uid): array {
            $st = $pdo->prepare("SELECT * FROM siparis_catismalar WHERE id = ? AND durum = 'acik'" . siparis_kilit_sql($pdo));
            $st->execute([$catismaId]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if (!$c) return ['ok' => false, 'hata' => 'Açık çatışma bulunamadı (başka biri çözmüş olabilir).'];
            $sid = (int)$c['sevkiyat_id'];
            $varlik = $c['kalem_id'] !== null ? 'kalem' : 'sevkiyat';
            $id = $varlik === 'kalem' ? (int)$c['kalem_id'] : $sid;
            $alan = (string)$c['alan'];
            $tanim = siparis_alan_tanimlari()[$varlik][$alan] ?? null;
            $v = siparis_varlik_getir($pdo, $varlik, $id);
            if ($tanim === null || $v === null) return ['ok' => false, 'hata' => 'Çatışmanın kaydı bulunamadı.'];
            $simdi = siparis_simdi();
            if ($karar === 'kabul') {
                $row = $v['row'];
                $kay = siparis_json_coz($row['kaynaklar']);
                $elle = siparis_json_coz($row['elle_alanlar']);
                unset($elle[$alan]);
                if (!isset($kay[$alan]) || !is_array($kay[$alan])) $kay[$alan] = [];
                $kay[$alan]['_s'] = (string)$c['gelen_kaynak'];
                unset($kay[$alan]['elle']);
                $deger = siparis_deger_normalize($c['gelen_deger'], $tanim['tip']);
                $tablo = siparis_varlik_tablo($varlik);
                $pdo->prepare("UPDATE `$tablo` SET `$alan` = ?, kaynaklar = ?, elle_alanlar = ?, updated_at = ? WHERE id = ?")
                    ->execute([$deger, siparis_json($kay), $elle ? siparis_json($elle) : null, $simdi, $id]);
            }
            $pdo->prepare('UPDATE siparis_catismalar SET durum = ?, cozen = ?, cozuldu_at = ? WHERE id = ?')->execute([$karar, $uid, $simdi, $catismaId]);
            siparis_olay($pdo, $sid, $karar === 'kabul' ? 'catisma_kabul' : 'catisma_red', $c['mevcut_deger'], $c['gelen_deger'],
                $tanim['etiket'] . ($karar === 'kabul' ? ': gelen değer kabul edildi' : ': mevcut değer korundu'), $c['belge_id'] !== null ? (int)$c['belge_id'] : null, $uid);
            siparis_audit('siparis_catisma_coz', $sid, null, ['catisma_id' => $catismaId, 'alan' => $alan, 'karar' => $karar, 'order_ref' => $v['sev']['order_ref']], $uid);
            siparis_sevkiyat_yenile($pdo, $sid);
            return ['ok' => true];
        });
    } catch (PDOException $e) {
        error_log('[siparis_catisma_coz] ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Çatışma kaydedilemedi.'];
    }
}

// ── SEVKİYAT BUL / OLUŞTUR ──────────────────────────────────────────────

/**
 * (tedarikçi, order_ref) ile bulur (silinmişler dahil — silinmiş olan GERİ GETİRİLİR); yoksa
 * açar (durum siparis_alindi, depo parametreden). Tur no başka canlı sevkiyatta varsa iki tarafa
 * da uyarı olayı (ASLA birleştirilmez). $belgeId 0 = belgesiz (elle).
 * @return array{id:int,yeni:bool,geri:bool,uyari?:string}
 */
function siparis_sevkiyat_bul_olustur(PDO $pdo, string $tedarikciNo, string $orderRef, ?string $turNo, int $uid, string $depo, int $belgeId, string $kaynak): array
{
    $bid = $belgeId > 0 ? $belgeId : null;
    $st = $pdo->prepare('SELECT id, deleted_at FROM siparis_sevkiyatlar WHERE tedarikci_no = ? AND order_ref = ?');
    $st->execute([$tedarikciNo, $orderRef]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $id = (int)$r['id'];
        if ($r['deleted_at'] === null) return ['id' => $id, 'yeni' => false, 'geri' => false];
        $pdo->prepare('UPDATE siparis_sevkiyatlar SET deleted_at = NULL, updated_at = ? WHERE id = ?')->execute([siparis_simdi(), $id]);
        siparis_olay($pdo, $id, 'geri_getirildi', null, null, 'Silinmiş sevkiyat yeni belgeyle geri getirildi', $bid, $uid);
        return ['id' => $id, 'yeni' => false, 'geri' => true, 'uyari' => "Silinmiş sevkiyat {$orderRef} yeni belgeyle geri getirildi."];
    }
    $turNo = $turNo !== null && trim($turNo) !== '' ? siparis_kirp(trim($turNo), 30) : null;
    $yeni = true;
    try {
        $pdo->prepare('INSERT INTO siparis_sevkiyatlar (tedarikci_no, order_ref, tur_no, durum, durum_at, depo, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$tedarikciNo, $orderRef, $turNo, 'siparis_alindi', siparis_simdi(), $depo, $uid, siparis_simdi()]);
        $id = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        if (!siparis_tekrar_mi($e)) throw $e;
        $st->execute([$tedarikciNo, $orderRef]);
        $id = (int)$st->fetchColumn();
        $yeni = false;
    }
    if (!$yeni) return ['id' => $id, 'yeni' => false, 'geri' => false];
    siparis_olay($pdo, $id, 'olusturuldu', null, $orderRef, match ($kaynak) {
        'po' => 'PO ile', 'elle' => 'Elle açıldı — PO bekleniyor', default => siparis_kaynak_etiket($kaynak) . ' ile — PO bekleniyor (taslak sevkiyat)' }, $bid, $uid);
    $sonuc = ['id' => $id, 'yeni' => true, 'geri' => false];
    if ($turNo !== null) {
        $t = $pdo->prepare('SELECT id, order_ref FROM siparis_sevkiyatlar WHERE tur_no = ? AND id <> ? AND deleted_at IS NULL');
        $t->execute([$turNo, $id]);
        $diger = $t->fetchAll(PDO::FETCH_ASSOC);
        foreach ($diger as $d) {
            siparis_olay($pdo, $id, 'uyari', null, null, "Tur {$turNo} başka sevkiyatta da var: {$d['order_ref']} (birleştirilmedi)", $bid, null);
            siparis_olay($pdo, (int)$d['id'], 'uyari', null, null, "Tur {$turNo} başka sevkiyatta da var: {$orderRef} (birleştirilmedi)", $bid, null);
            siparis_sevkiyat_yenile($pdo, (int)$d['id']);
        }
        if ($diger) $sonuc['uyari'] = "Tur {$turNo} başka sevkiyatta da kayıtlı (" . implode(', ', array_column($diger, 'order_ref')) . ') — birleştirilmedi, kontrol edin.';
    }
    return $sonuc;
}

// ── BAĞ + REVİZYON ──────────────────────────────────────────────────────

/** Revizyon sıralama anahtarı: belge_tarihi | pdf_olusturma | id (10 hane). */
function siparis_belge_sira(array $belge): string
{
    return (string)($belge['belge_tarihi'] ?? '') . '|' . (string)($belge['pdf_olusturma'] ?? '') . '|' . str_pad((string)(int)$belge['id'], 10, '0', STR_PAD_LEFT);
}

/** Belge ↔ sevkiyat bağını yazar (varsa dokunmaz). İlk ekleniş 'belge_eklendi' olayı yazar. @return bool yeni bağ */
function siparis_bag_yaz(PDO $pdo, int $belgeId, int $sid, ?int $uid): bool
{
    $st = $pdo->prepare('SELECT id FROM siparis_belge_bag WHERE belge_id = ? AND sevkiyat_id = ?');
    $st->execute([$belgeId, $sid]);
    if ($st->fetchColumn() !== false) return false;
    try {
        $pdo->prepare('INSERT INTO siparis_belge_bag (belge_id, sevkiyat_id, gecerli, created_at) VALUES (?, ?, 1, ?)')->execute([$belgeId, $sid, siparis_simdi()]);
    } catch (PDOException $e) {
        if (!siparis_tekrar_mi($e)) throw $e;
        return false;
    }
    $b = $pdo->prepare('SELECT tur, orijinal_ad, kaynak FROM siparis_belgeler WHERE id = ?');
    $b->execute([$belgeId]);
    $br = $b->fetch(PDO::FETCH_ASSOC) ?: ['tur' => '', 'orijinal_ad' => '', 'kaynak' => ''];
    // v378: mailden alınan belge zaman çizelgesinde "(mailden)" ile anılır (yeni olay türü YOK)
    siparis_olay($pdo, $sid, 'belge_eklendi', null, (string)$br['tur'], (siparis_belge_tur_etiketleri()[(string)$br['tur']] ?? 'Belge') . ': ' . (string)$br['orijinal_ad']
        . ((string)($br['kaynak'] ?? '') === 'mail' ? ' (mailden)' : ''), $belgeId, $uid);
    return true;
}

/**
 * Aynı türün diğer geçerli bağlarıyla karşılaştırır: bu belge daha yeniyse diğerleri gecerli=0
 * (olay belge_eski_surum); değilse BU bağ gecerli=0 (olay; değerler uygulanmaz). @return bool bu belge güncel mi
 */
function siparis_revizyon_isle(PDO $pdo, array $belge, int $sid, ?int $uid): bool
{
    $bid = (int)$belge['id'];
    $sira = siparis_belge_sira($belge);
    $st = $pdo->prepare('SELECT bb.id AS bag_id, bb.gecerli, b.id, b.belge_tarihi, b.pdf_olusturma FROM siparis_belge_bag bb JOIN siparis_belgeler b ON b.id = bb.belge_id
        WHERE bb.sevkiyat_id = ? AND b.tur = ? AND b.deleted_at IS NULL AND b.id <> ? AND bb.gecerli = 1');
    $st->execute([$sid, (string)$belge['tur'], $bid]);
    $digerleri = $st->fetchAll(PDO::FETCH_ASSOC);
    $kendi = $pdo->prepare('SELECT gecerli FROM siparis_belge_bag WHERE belge_id = ? AND sevkiyat_id = ?');
    $kendi->execute([$bid, $sid]);
    $kendiGecerli = (int)$kendi->fetchColumn() === 1;
    $guncel = true;
    foreach ($digerleri as $d) if (strcmp(siparis_belge_sira($d), $sira) > 0) { $guncel = false; break; }
    if ($guncel) {
        foreach ($digerleri as $d) {
            $pdo->prepare('UPDATE siparis_belge_bag SET gecerli = 0 WHERE id = ?')->execute([(int)$d['bag_id']]);
            siparis_olay($pdo, $sid, 'belge_eski_surum', null, null, 'Daha yeni ' . siparis_belge_tur_etiketleri()[(string)$belge['tur']] . ' geldi; önceki sürüm eski', (int)$d['id'], $uid);
        }
        if (!$kendiGecerli) $pdo->prepare('UPDATE siparis_belge_bag SET gecerli = 1 WHERE belge_id = ? AND sevkiyat_id = ?')->execute([$bid, $sid]);
        return true;
    }
    if ($kendiGecerli) {
        $pdo->prepare('UPDATE siparis_belge_bag SET gecerli = 0 WHERE belge_id = ? AND sevkiyat_id = ?')->execute([$bid, $sid]);
        siparis_olay($pdo, $sid, 'belge_eski_surum', null, null, 'Eski sürüm — değerler uygulanmadı', $bid, $uid);
    }
    return false;
}

// ── KALEM YARDIMCILARI ──────────────────────────────────────────────────

/** (sevkiyat, ian, kalibre) kalemini bulur ya da açar; $siraYaz ise sira güncellenir (PO sırası). */
function siparis_kalem_hazirla(PDO $pdo, int $sid, string $ian, string $kalibre, int $sira, bool $siraYaz): int
{
    $ian = siparis_kirp(trim($ian), 20) ?? '';
    $kalibre = siparis_kirp(trim($kalibre), 10) ?? '';
    if ($ian === '') {
        // Ayrıştırıcı IAN'ı her satıra basmayabilir (PC'de grup başına bir kez): aynı kalibrede TEK kalem varsa o kullanılır.
        $t = $pdo->prepare('SELECT ian FROM siparis_kalemler WHERE sevkiyat_id = ? AND kalibre = ?');
        $t->execute([$sid, $kalibre]);
        $adaylar = array_values(array_unique($t->fetchAll(PDO::FETCH_COLUMN)));
        if (count($adaylar) === 1) $ian = (string)$adaylar[0];
    }
    $st = $pdo->prepare('SELECT id, sira FROM siparis_kalemler WHERE sevkiyat_id = ? AND ian = ? AND kalibre = ?');
    $st->execute([$sid, $ian, $kalibre]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        if ($siraYaz && (int)$r['sira'] !== $sira) $pdo->prepare('UPDATE siparis_kalemler SET sira = ? WHERE id = ?')->execute([$sira, (int)$r['id']]);
        return (int)$r['id'];
    }
    try {
        $pdo->prepare('INSERT INTO siparis_kalemler (sevkiyat_id, ian, kalibre, sira, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$sid, $ian, $kalibre, $sira, siparis_simdi()]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        if (!siparis_tekrar_mi($e)) throw $e;
        $st->execute([$sid, $ian, $kalibre]);
        return (int)$st->fetchColumn();
    }
}

/**
 * Kaynağa ait miktar kolonlarını + yerler_json[src]'yi yazar. Aynı kaynağın daha YENİ belgesi zaten
 * yazdıysa hiçbir şey yapmaz (kaynaklar._miktar[src].t). Miktarlar çatışma üretmez.
 */
function siparis_kalem_miktar(PDO $pdo, int $kid, string $src, array $kolonlar, array $yerler, int $belgeId, string $belgeSira): void
{
    $st = $pdo->prepare('SELECT kaynaklar, yerler_json FROM siparis_kalemler WHERE id = ?');
    $st->execute([$kid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return;
    $kay = siparis_json_coz($r['kaynaklar']);
    $onceki = (string)($kay['_miktar'][$src]['t'] ?? '');
    if ($onceki !== '' && strcmp($belgeSira, $onceki) < 0) return;
    $kay['_miktar'][$src] = ['b' => $belgeId, 't' => $belgeSira];
    $yj = siparis_json_coz($r['yerler_json']);
    $yj[$src] = $yerler;
    $izinli = ['po_palet', 'po_koli', 'll_palet', 'll_koli', 'pc_koli'];
    $set = []; $par = [];
    foreach ($kolonlar as $k => $v) {
        if (!in_array($k, $izinli, true)) continue;
        $set[] = "`$k` = ?"; $par[] = $v === null ? null : (int)$v;
    }
    $set[] = 'kaynaklar = ?'; $par[] = siparis_json($kay);
    $set[] = 'yerler_json = ?'; $par[] = siparis_json($yj);
    $set[] = 'updated_at = ?'; $par[] = siparis_simdi();
    $par[] = $kid;
    $pdo->prepare('UPDATE siparis_kalemler SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($par);
}

/** Bu belgede GÖRÜLMEYEN kalemlere kaynaklar._yok[] = src, görülenlerden kaldırır (kalem SİLİNMEZ). */
function siparis_yok_isaretle(PDO $pdo, int $sid, string $src, array $gorulenIds): void
{
    $st = $pdo->prepare('SELECT id, kaynaklar FROM siparis_kalemler WHERE sevkiyat_id = ?');
    $st->execute([$sid]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $kay = siparis_json_coz($r['kaynaklar']);
        $yok = array_values(array_filter((array)($kay['_yok'] ?? []), 'is_string'));
        $var = in_array((int)$r['id'], $gorulenIds, true);
        $yeni = $var ? array_values(array_diff($yok, [$src])) : array_values(array_unique(array_merge($yok, [$src])));
        sort($yeni);
        $eskiSirali = $yok; sort($eskiSirali);
        if ($yeni === $eskiSirali) continue;
        if ($yeni) $kay['_yok'] = $yeni; else unset($kay['_yok']);
        $pdo->prepare('UPDATE siparis_kalemler SET kaynaklar = ? WHERE id = ?')->execute([siparis_json($kay), (int)$r['id']]);
    }
}

// ── BİRİM UYGULAYICILARI (C.7) ──────────────────────────────────────────

/** @return int çatışma sayısı */
function siparis_uygula_sayi(array $sonuclar): int
{
    return count(array_filter($sonuclar, fn($x) => $x === 'catisma'));
}

/** PO loading bloğu → sevkiyat + kalemler. @return int çatışma sayısı */
function siparis_po_uygula(PDO $pdo, int $sid, array $belge, array $doc, array $o): int
{
    $bid = (int)$belge['id']; $sira = siparis_belge_sira($belge);
    $u = fn(string $alan, mixed $v): string => siparis_alan_uygula($pdo, 'sevkiyat', $sid, $alan, $v, 'po', $bid, $sira);
    $plan = siparis_plaka_normalize(implode('-', array_map('strval', (array)($o['planned_plates'] ?? []))))['deger'];
    $yerler = array_values(array_filter(array_map(fn($x) => trim((string)($x['name'] ?? '')), (array)($o['totals']['by_location'] ?? []))));
    $dest = is_array($doc['destination'] ?? null) ? $doc['destination'] : [];
    $r = [
        $u('tur_no', $o['tour'] ?? null),
        $u('alici_kodu', $dest['id'] ?? null),
        $u('alici_adi', $dest['name'] ?? null),
        $u('sold_to', $o['sold_to'] ?? null),
        $u('yukleme_tarihi', $o['loading_date'] ?? null),
        $u('yukleme_yeri', $o['loading_point'] ?? null),
        $u('gumruk', ($o['customs_office'] ?? null) ?? ($o['customs_raw'] ?? null)),
        $u('kdv_no', $o['vat_no'] ?? null),
        $u('incoterm', $o['incoterm'] ?? null),
        $u('planlanan_nakliyeci', $o['carrier_name'] ?? null),
        $u('planlanan_plakalar', $plan),
        $u('bosaltma_yerleri', $yerler ? implode(', ', array_unique($yerler)) : null),
    ];
    $h = (array)($doc['header'] ?? []);
    $cw = isset($h['cw']) && is_numeric($h['cw']) ? (int)$h['cw'] : null;
    $cwy = isset($h['cw_year']) && is_numeric($h['cw_year']) ? (int)$h['cw_year'] : null;
    $pdo->prepare('UPDATE siparis_sevkiyatlar SET cw_hafta = ?, cw_yil = ?, po_belge_id = ?, updated_at = ? WHERE id = ?')
        ->execute([$cw, $cwy, $bid, siparis_simdi(), $sid]);

    // kalemler: anahtar (ian, pcs_per_box); aynı anahtar iki kez gelirse toplanır
    $grup = [];
    foreach ((array)($o['items'] ?? []) as $idx => $it) {
        $k = (string)($it['ian'] ?? '') . '|' . (string)($it['pcs_per_box'] ?? '');
        if (!isset($grup[$k])) { $grup[$k] = ['it' => $it, 'idx' => (int)$idx, 'palet' => 0, 'koli' => 0, 'yer' => []]; }
        $grup[$k]['palet'] += (int)($it['pallets'] ?? 0);
        $grup[$k]['koli'] += (int)($it['quantity'] ?? 0);
        foreach ((array)($it['locations'] ?? []) as $l) {
            $grup[$k]['yer'][] = ['ad' => (string)($l['name'] ?? ''), 'kod' => $l['code'] ?? null, 'palet' => (int)($l['pallets'] ?? 0), 'koli' => (int)($l['cartons'] ?? 0)];
        }
    }
    $gorulen = [];
    foreach ($grup as $g) {
        [$kid, $kr] = siparis_po_kalem_uygula($pdo, $sid, $bid, $sira, $doc, $g);
        $gorulen[] = $kid;
        array_push($r, ...$kr);
    }
    siparis_yok_isaretle($pdo, $sid, 'po', $gorulen);
    return siparis_uygula_sayi($r);
}

/**
 * PO'daki TEK kalem grubunu (ian|kalibre) sevkiyata yazar: kalem + po miktarı + kalem alanları (urun, açıklama, çeşit…).
 * siparis_po_uygula() ve siparis_po_yetim_ekle() ortak kullanır (kopya YOK). $g = ['it' => satır, 'idx' => sıra, 'palet', 'koli', 'yer' => []].
 * @return array{0:int,1:list<string>} [kalem id, alan uygulama sonuçları]
 */
function siparis_po_kalem_uygula(PDO $pdo, int $sid, int $bid, string $sira, array $doc, array $g): array
{
    $it = $g['it'];
    $sum = [];
    foreach ((array)($doc['items'] ?? []) as $x) {
        if ((string)($x['ian'] ?? '') === (string)($it['ian'] ?? '') && (string)($x['pcs_per_box'] ?? '') === (string)($it['pcs_per_box'] ?? '')) { $sum = $x; break; }
    }
    $kid = siparis_kalem_hazirla($pdo, $sid, (string)($it['ian'] ?? ''), (string)($it['pcs_per_box'] ?? ''), (int)$g['idx'], true);
    siparis_kalem_miktar($pdo, $kid, 'po', ['po_palet' => $g['palet'], 'po_koli' => $g['koli']], $g['yer'], $bid, $sira);
    $amb = trim((string)($sum['packaging'] ?? ''));
    if ($amb !== '' && !empty($sum['box_dims'])) $amb .= ', ' . $sum['box_dims'];
    $k = fn(string $alan, mixed $v): string => siparis_alan_uygula($pdo, 'kalem', $kid, $alan, $v, 'po', $bid, $sira);
    return [$kid, [
        $k('urun', $it['product'] ?? ($sum['product'] ?? null)),
        $k('aciklama', $it['description'] ?? ($sum['description'] ?? null)),
        $k('cesit', $sum['variety'] ?? ($it['variety'] ?? null)),
        $k('artikel_no', $sum['article_no'] ?? null),
        $k('koli_icerik', $sum['box_weight'] ?? ($it['box_weight'] ?? null)),
        $k('ambalaj', $amb !== '' ? $amb : null),
        $k('palet_tipi', $it['pallet_type'] ?? null),
        $k('pf', $it['pf'] ?? ($sum['pf'] ?? null))]];
}

/** Bir PO ayrıştırma satırını kalem grubuna çevirir (palet/koli/yerler). */
function siparis_po_kalem_grubu(array $it, int $idx): array
{
    $g = ['it' => $it, 'idx' => $idx, 'palet' => (int)($it['pallets'] ?? 0), 'koli' => (int)($it['quantity'] ?? 0), 'yer' => []];
    foreach ((array)($it['locations'] ?? []) as $l) $g['yer'][] = ['ad' => (string)($l['name'] ?? ''), 'kod' => $l['code'] ?? null, 'palet' => (int)($l['pallets'] ?? 0), 'koli' => (int)($l['cartons'] ?? 0)];
    return $g;
}

/**
 * PO "eksik okunmuş" mu? (v420+) Ayrıştırıcının kaydettiği bağlanamayan satırlar ya da özet ↔ yükleme blokları toplam denetiminin
 * başarısızlığı. $yuklemeTarihi verilirse bağlanamayan satır yalnız AYNI tarihli sevkiyata yazılır; denetim hatası PO'nun tüm sevkiyatlarına.
 * Saf (DB yok). @return ?string kullanıcıya gösterilecek mesaj; sorun yoksa null.
 */
function siparis_po_eksik_okuma(array $doc, ?string $yuklemeTarihi = null): ?string
{
    $y = [];
    foreach ((array)($doc['orphan_items'] ?? []) as $x) {
        if (!is_array($x)) continue;
        if ($yuklemeTarihi !== null && (string)($x['loading_date'] ?? '') !== $yuklemeTarihi) continue;
        $it = (array)($x['item'] ?? []);
        $y[] = ($it['pcs_per_box'] ?? '?') . ' PC · ' . (int)($it['pallets'] ?? 0) . ' palet';
    }
    if ($y) return 'PO\'da bu siparişe bağlanamayan kalem var (' . implode('; ', array_unique($y)) . ') — kalemler eksik olabilir. Belge sayfasından kalemi ekleyin ya da "Kalem ekle" ile tamamlayın.';
    foreach ((array)($doc['checks'] ?? []) as $c) {
        if (!is_array($c) || !empty($c['ok']) || !in_array((string)($c['id'] ?? ''), ['sum_blocks_pallets', 'sum_blocks_quantity'], true)) continue;
        return 'PO eksik okunmuş olabilir: özet toplamı ile yükleme blokları tutmuyor (' . (string)($c['scope'] ?? '') . ' — beklenen ' . json_encode($c['expected'] ?? null) . ', bulunan ' . json_encode($c['actual'] ?? null)
            . '). Kalemleri PO ile karşılaştırın; eksik kalemi "Kalem ekle" ile tamamlayın.';
    }
    return null;
}

/**
 * "PO eksik okunmuş" uyarısını bu sevkiyat için onaylar (kaynaklar._eksik_okuma_onay = {b: PO belge id}). Onay YALNIZ o PO belgesi için
 * geçerlidir — PO yeniden yüklenirse (yeni belge id) uyarı, sorun sürüyorsa yeniden çıkar. Kalem eklenince de otomatik yazılır.
 */
function siparis_eksik_okuma_onay_yaz(PDO $pdo, int $sid): void
{
    $st = $pdo->prepare('SELECT kaynaklar, po_belge_id FROM siparis_sevkiyatlar WHERE id = ?');
    $st->execute([$sid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || empty($r['po_belge_id'])) return;
    $kay = siparis_json_coz($r['kaynaklar']);
    $kay['_eksik_okuma_onay'] = ['b' => (int)$r['po_belge_id'], 't' => siparis_simdi()];
    $pdo->prepare('UPDATE siparis_sevkiyatlar SET kaynaklar = ? WHERE id = ?')->execute([siparis_json($kay), $sid]);
}

/** Kullanıcı kalemleri PO ile karşılaştırıp sorun olmadığını onaylar. @return array{ok:bool,hata?:string} */
function siparis_eksik_okuma_onayla(PDO $pdo, int $sid, int $uid): array
{
    $sev = siparis_sevkiyat($pdo, $sid);
    if ($sev === null) return ['ok' => false, 'hata' => 'Sevkiyat bulunamadı.'];
    siparis_tx($pdo, function () use ($pdo, $sid, $uid, $sev): void {
        siparis_eksik_okuma_onay_yaz($pdo, $sid);
        siparis_olay($pdo, $sid, 'uyari', null, 'onaylandi', 'PO eksik okuma uyarısı kontrol edildi, sorun yok olarak işaretlendi', null, $uid);
        siparis_audit('siparis_eksik_okuma_onay', $sid, null, ['order_ref' => $sev['order_ref']], $uid);
        siparis_sevkiyat_yenile($pdo, $sid);
    });
    return ['ok' => true];
}

/**
 * Belgenin ayrıştırıcıda bağlanamayan (orphan_items[$idx]) kalemini seçilen sevkiyata PO kalemi olarak ekler.
 * Yalnız o belgeye BAĞLI sevkiyata; aynı (ian, kalibre) kalemi PO miktarıyla zaten varsa reddeder (çift ekleme yok).
 * @return array{ok:bool,hata?:string,kalibre?:string}
 */
function siparis_po_yetim_ekle(PDO $pdo, int $belgeId, int $idx, int $sid, int $uid): array
{
    $st = $pdo->prepare('SELECT * FROM siparis_belgeler WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$belgeId]);
    $belge = $st->fetch(PDO::FETCH_ASSOC);
    if (!$belge || (string)$belge['tur'] !== 'po') return ['ok' => false, 'hata' => 'PO belgesi bulunamadı.'];
    $doc = siparis_json_coz($belge['parse_json'] ?? null);
    $yetim = array_values((array)($doc['orphan_items'] ?? []));
    $it = isset($yetim[$idx]) && is_array($yetim[$idx]) ? (array)($yetim[$idx]['item'] ?? []) : [];
    if (!$it) return ['ok' => false, 'hata' => 'Bağlanamayan kalem bulunamadı.'];
    $bg = $pdo->prepare('SELECT 1 FROM siparis_belge_bag WHERE belge_id = ? AND sevkiyat_id = ? AND gecerli = 1');
    $bg->execute([$belgeId, $sid]);
    if (!$bg->fetchColumn()) return ['ok' => false, 'hata' => 'Seçilen sevkiyat bu PO\'ya bağlı değil.'];
    $sev = siparis_sevkiyat($pdo, $sid);
    if ($sev === null) return ['ok' => false, 'hata' => 'Sevkiyat bulunamadı.'];
    $kal = (string)($it['pcs_per_box'] ?? ''); $ian = (string)($it['ian'] ?? '');
    $var = $pdo->prepare('SELECT id, po_palet FROM siparis_kalemler WHERE sevkiyat_id = ? AND kalibre = ? AND ian = ?');
    $var->execute([$sid, $kal, $ian]);
    $r = $var->fetch(PDO::FETCH_ASSOC);
    if ($r && $r['po_palet'] !== null && (int)$r['po_palet'] > 0) return ['ok' => false, 'hata' => 'Kalibre ' . $kal . ' bu sevkiyatta PO miktarıyla zaten var.'];
    $sira = (int)$pdo->query('SELECT COALESCE(MAX(sira), 0) + 1 FROM siparis_kalemler WHERE sevkiyat_id = ' . (int)$sid)->fetchColumn();
    try {
        siparis_tx($pdo, function () use ($pdo, $sid, $belge, $doc, $it, $sira, $uid, $kal, $sev): void {
            $g = siparis_po_kalem_grubu($it, $sira);
            siparis_po_kalem_uygula($pdo, $sid, (int)$belge['id'], siparis_belge_sira($belge), $doc, $g);
            siparis_olay($pdo, $sid, 'alan_elle', null, $kal, 'Kalibre ' . $kal . ' kalemi PO\'daki bağlanamayan satırdan eklendi (' . (int)$g['palet'] . ' palet)', (int)$belge['id'], $uid);
            siparis_eksik_okuma_onay_yaz($pdo, $sid);
            siparis_audit('siparis_kalem_yetim_ekle', $sid, null, ['belge_id' => (int)$belge['id'], 'kalibre' => $kal, 'palet' => (int)$g['palet'], 'order_ref' => $sev['order_ref']], $uid);
            siparis_sevkiyat_yenile($pdo, $sid);
        });
    } catch (PDOException $e) {
        error_log('[siparis_po_yetim_ekle] ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Kalem eklenemedi.'];
    }
    return ['ok' => true, 'kalibre' => $kal];
}

/**
 * Sevkiyata ELLE kalem ekler (ayrıştırıcı bir kalemi kaçırdığında çıkış yolu). Palet/koli/PF "elle" kilitli yazılır — belge yeniden
 * okunsa da ezilmez. Aynı kalibre (ve IAN çakışmıyorsa) zaten varsa reddeder → ✎ Düzenle kullanılır.
 * @return array{ok:bool,hata?:string}
 */
function siparis_kalem_elle_ekle(PDO $pdo, int $sid, string $kalibre, string $ian, string $palet, string $koli, string $pf, int $uid): array
{
    $sev = siparis_sevkiyat($pdo, $sid);
    if ($sev === null) return ['ok' => false, 'hata' => 'Sevkiyat bulunamadı.'];
    $kalibre = trim($kalibre); $ian = trim($ian);
    if ($kalibre === '' || !preg_match('/^[0-9A-Za-z]{1,10}$/', $kalibre)) return ['ok' => false, 'hata' => 'Kalibre gerekli (en çok 10 harf/rakam, örn. 14).'];
    if ($ian !== '' && !preg_match('/^\d{1,20}$/', $ian)) return ['ok' => false, 'hata' => 'IAN yalnız rakam olmalı.'];
    $sayi = function (string $v): array {
        $v = trim($v);
        if ($v === '') return [true, null];
        $n = siparis_deger_normalize($v, 'sayi');
        return $n === null || (int)$n < 0 ? [false, null] : [true, (int)$n];
    };
    [$o1, $p] = $sayi($palet); [$o2, $k] = $sayi($koli); [$o3, $f] = $sayi($pf);
    if (!$o1 || !$o2 || !$o3) return ['ok' => false, 'hata' => 'Palet, koli ve PF tam sayı olmalı.'];
    if ($k === null && $p !== null && $f !== null) $k = $p * $f;
    if ($p === null && $k !== null && $f !== null && $f > 0 && $k % $f === 0) $p = intdiv($k, $f);
    if ($p === null && $k === null) return ['ok' => false, 'hata' => 'En az palet ya da koli girin.'];
    $var = $pdo->prepare('SELECT ian FROM siparis_kalemler WHERE sevkiyat_id = ? AND kalibre = ?');
    $var->execute([$sid, $kalibre]);
    foreach ($var->fetchAll(PDO::FETCH_COLUMN) as $x) if ($ian === '' || (string)$x === '' || (string)$x === $ian) return ['ok' => false, 'hata' => 'Kalibre ' . $kalibre . ' için kalem zaten var — satırdaki ✎ Düzenle ile değiştirin.'];
    $sira = (int)$pdo->query('SELECT COALESCE(MAX(sira), 0) + 1 FROM siparis_kalemler WHERE sevkiyat_id = ' . (int)$sid)->fetchColumn();
    try {
        $sonuc = siparis_tx($pdo, function () use ($pdo, $sid, $kalibre, $ian, $p, $k, $f, $sira, $uid, $sev): array {
            $kid = siparis_kalem_hazirla($pdo, $sid, $ian, $kalibre, $sira, true);
            foreach (['etkin_palet' => $p, 'etkin_koli' => $k, 'pf' => $f] as $alan => $v) {
                if ($v === null) continue;
                $r = siparis_alan_elle($pdo, 'kalem', $kid, $alan, (string)$v, $uid);
                if (!$r['ok']) throw new RuntimeException((string)($r['hata'] ?? 'Değer kaydedilemedi.'));
            }
            siparis_olay($pdo, $sid, 'alan_elle', null, $kalibre, 'Kalibre ' . $kalibre . ' kalemi elle eklendi (' . ($p ?? '?') . ' palet / ' . ($k ?? '?') . ' koli)', null, $uid);
            siparis_eksik_okuma_onay_yaz($pdo, $sid);
            siparis_audit('siparis_kalem_ekle', $sid, null, ['kalibre' => $kalibre, 'palet' => $p, 'koli' => $k, 'order_ref' => $sev['order_ref']], $uid);
            siparis_sevkiyat_yenile($pdo, $sid);
            return ['ok' => true];
        });
    } catch (RuntimeException $e) {
        return ['ok' => false, 'hata' => $e->getMessage()];
    } catch (PDOException $e) {
        error_log('[siparis_kalem_elle_ekle] ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Kalem eklenemedi.'];
    }
    return $sonuc;
}

/** LL shipment → sevkiyat + kalemler. @return int çatışma sayısı */
function siparis_ll_uygula(PDO $pdo, int $sid, array $belge, array $doc, array $sh): int
{
    $bid = (int)$belge['id']; $sira = siparis_belge_sira($belge);
    $u = fn(string $alan, mixed $v): string => siparis_alan_uygula($pdo, 'sevkiyat', $sid, $alan, $v, 'll', $bid, $sira);
    $h = (array)($doc['header'] ?? []);
    // v374: bir yükleme listesinde birden çok tır (Transport bloğu) olabilir — taşıma bilgisi SEVKİYATIN kendi bloğundan; eski parse_json'da başlıktan
    foreach (['transport_no', 'transport_company', 'plates', 'additional_info'] as $f) if (array_key_exists($f, $sh)) $h[$f] = $sh[$f];
    $plakalar = array_values(array_filter(array_map('strval', (array)($h['plates'] ?? [])), fn($x) => trim($x) !== ''));
    $yerler = [];
    foreach ((array)($sh['items'] ?? []) as $it) { $y = trim((string)($it['unloading_location'] ?? '')); if ($y !== '' && !in_array($y, $yerler, true)) $yerler[] = $y; }
    $r = [
        $u('tur_no', $sh['tour_id'] ?? null),
        $u('yukleme_tarihi', $h['loading_date'] ?? null),
        $u('yukleme_yeri', $h['loading_location'] ?? null),
        $u('nakliyeci', $h['transport_company'] ?? null),
        $u('navlun_no', $h['transport_no'] ?? null),
        $u('bosaltma_tarihi', $sh['unloading_date'] ?? null),
        $u('bosaltma_yerleri', $yerler ? implode(', ', $yerler) : null),
    ];
    if ($plakalar) $r[] = $u('plakalar', siparis_plaka_normalize(implode('-', $plakalar))['deger']);
    // Bu belge önceki (hatalı) okumada bu tıra değer yazdıysa ve artık boş geliyorsa: yalnız BU belgenin değeri geri alınır
    foreach (['nakliyeci' => $h['transport_company'] ?? null, 'navlun_no' => $h['transport_no'] ?? null, 'plakalar' => $plakalar ? 'x' : null] as $alan => $v) {
        if ($v === null || trim((string)$v) === '') siparis_alan_belge_geri_al($pdo, 'sevkiyat', $sid, $alan, 'll', $bid);
    }
    $ref = (string)($sh['order_ref'] ?? '');
    $grup = [];
    foreach ((array)($sh['items'] ?? []) as $it) {
        $k = (string)($it['ian'] ?? '') . '|' . (string)($it['pcs_per_box'] ?? '');
        if (!isset($grup[$k])) $grup[$k] = ['it' => $it, 'palet' => 0, 'koli' => 0, 'yer' => [], 'tip' => null];
        $grup[$k]['palet'] += (int)($it['pallets'] ?? 0);
        $grup[$k]['koli'] += (int)($it['colli'] ?? 0);
        $grup[$k]['yer'][] = ['ad' => (string)($it['unloading_location'] ?? ''), 'seq' => $it['seq'] ?? null, 'palet' => (int)($it['pallets'] ?? 0),
                              'koli' => (int)($it['colli'] ?? 0), 'bosaltma' => $sh['unloading_date'] ?? null];
    }
    foreach ((array)($doc['rows'] ?? []) as $row) {
        if ((string)($row['order_ref'] ?? '') !== $ref) continue;
        $k = (string)($row['ian'] ?? '') . '|' . (string)($row['pcs_per_box'] ?? '');
        if (isset($grup[$k]) && $grup[$k]['tip'] === null && !empty($row['pallet_type'])) $grup[$k]['tip'] = (string)$row['pallet_type'];
    }
    $gorulen = []; $n = 0;
    foreach ($grup as $g) {
        $it = $g['it'];
        $kid = siparis_kalem_hazirla($pdo, $sid, (string)($it['ian'] ?? ''), (string)($it['pcs_per_box'] ?? ''), 900 + $n++, false);
        $gorulen[] = $kid;
        siparis_kalem_miktar($pdo, $kid, 'll', ['ll_palet' => $g['palet'], 'll_koli' => $g['koli']], $g['yer'], $bid, $sira);
        $r[] = siparis_alan_uygula($pdo, 'kalem', $kid, 'palet_tipi', $g['tip'], 'll', $bid, $sira);
        $r[] = siparis_alan_uygula($pdo, 'kalem', $kid, 'pf', $it['pf'] ?? null, 'll', $bid, $sira);
    }
    siparis_yok_isaretle($pdo, $sid, 'll', $gorulen);
    siparis_kalem_belge_temizle($pdo, $sid, 'll', $bid, $gorulen);
    return siparis_uygula_sayi($r);
}

/**
 * v374 — aynı belge yeniden işlenince, belgenin ÖNCEKİ okumasının bu alana yazdığı değeri geri alır
 * (yeni okumada alan boş). Elle kilitli alana ve başka kaynağın/belgenin değerine DOKUNMAZ; başka kaynağın
 * saklı değeri varsa en yetkilisine döner, yoksa alan boşalır.
 */
function siparis_alan_belge_geri_al(PDO $pdo, string $varlik, int $id, string $alan, string $kaynak, int $belgeId): void
{
    $tanim = siparis_alan_tanimlari()[$varlik][$alan] ?? null;
    if ($tanim === null || $belgeId <= 0) return;
    $tablo = siparis_varlik_tablo($varlik);
    $st = $pdo->prepare("SELECT * FROM `$tablo` WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;
    $kay = siparis_json_coz($row['kaynaklar']);
    $elle = siparis_json_coz($row['elle_alanlar']);
    if (!isset($kay[$alan][$kaynak]) || (int)($kay[$alan][$kaynak]['b'] ?? 0) !== $belgeId) return;
    unset($kay[$alan][$kaynak]);
    $deger = $row[$alan] ?? null; $degerYaz = false;
    if (!isset($elle[$alan]) && ($kay[$alan]['_s'] ?? null) === $kaynak) {
        $en = null;
        foreach ($kay[$alan] as $k => $e) {
            if ($k === '_s' || !is_array($e) || siparis_deger_normalize($e['v'] ?? null, $tanim['tip']) === null) continue;
            if ($en === null || siparis_kaynak_sira($tanim, (string)$k) > siparis_kaynak_sira($tanim, $en)) $en = (string)$k;
        }
        if ($en !== null) { $kay[$alan]['_s'] = $en; $deger = siparis_deger_normalize($kay[$alan][$en]['v'], $tanim['tip']); }
        else { unset($kay[$alan]['_s']); $deger = null; }
        $degerYaz = true;
    }
    if (!array_diff(array_keys($kay[$alan]), ['_s'])) unset($kay[$alan]);
    $pdo->prepare("UPDATE `$tablo` SET `$alan` = ?, kaynaklar = ?, updated_at = ? WHERE id = ?")
        ->execute([$degerYaz ? $deger : $row[$alan], $kay ? siparis_json($kay) : null, siparis_simdi(), $id]);
    if ($degerYaz) siparis_olay($pdo, $varlik === 'sevkiyat' ? $id : (int)$row['sevkiyat_id'], 'alan_guncellendi', $row[$alan], $deger,
        $tanim['etiket'] . ' — ' . siparis_kaynak_etiket($kaynak) . ' (yeniden okuma)', $belgeId, null);
}

/**
 * v374 — aynı belge yeniden işlenince, belgenin önceki okumasından kalan ama artık GÖRÜLMEYEN kalemlerin
 * bu kaynaktaki miktarlarını siler. Kalemin başka kaynakta miktarı, elle değeri ya da PKL'de kullanımı yoksa
 * (hatalı okumayla doğmuş kalem) kalem de silinir.
 */
function siparis_kalem_belge_temizle(PDO $pdo, int $sid, string $src, int $belgeId, array $gorulenIds): void
{
    $kol = ['po' => ['po_palet', 'po_koli'], 'll' => ['ll_palet', 'll_koli'], 'pc' => ['pc_koli']];
    if (!isset($kol[$src]) || $belgeId <= 0) return;
    $st = $pdo->prepare('SELECT * FROM siparis_kalemler WHERE sevkiyat_id = ?');
    $st->execute([$sid]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $k) {
        $kid = (int)$k['id'];
        if (in_array($kid, $gorulenIds, true)) continue;
        $kay = siparis_json_coz($k['kaynaklar']);
        if ((int)($kay['_miktar'][$src]['b'] ?? 0) !== $belgeId) continue;
        unset($kay['_miktar'][$src]);
        if (!$kay['_miktar']) unset($kay['_miktar']);
        $yj = siparis_json_coz($k['yerler_json']); unset($yj[$src]);
        $baskaMiktar = false;
        foreach ($kol as $s2 => $cs) { if ($s2 === $src) continue; foreach ($cs as $c) if ($k[$c] !== null && (int)$k[$c] !== 0) $baskaMiktar = true; }
        $pkl = $pdo->prepare('SELECT COUNT(*) FROM siparis_pkl WHERE veri_json LIKE ? OR veri_json LIKE ?');
        $pkl->execute(['%"kalem_id":' . $kid . ',%', '%"kalem_id":' . $kid . '}%']);
        if (!$baskaMiktar && !siparis_json_coz($k['elle_alanlar']) && (int)$pkl->fetchColumn() === 0) {
            $pdo->prepare("DELETE FROM siparis_catismalar WHERE kalem_id = ? AND durum = 'acik'")->execute([$kid]);
            $pdo->prepare('DELETE FROM siparis_kalemler WHERE id = ?')->execute([$kid]);
            siparis_olay($pdo, $sid, 'uyari', null, null, 'Kalem (IAN ' . $k['ian'] . ', kalibre ' . $k['kalibre'] . ') belgenin yeniden okumasında yok — kaldırıldı', $belgeId, null);
            continue;
        }
        $set = []; foreach ($kol[$src] as $c) $set[] = "`$c` = NULL";
        $pdo->prepare('UPDATE siparis_kalemler SET ' . implode(', ', $set) . ', kaynaklar = ?, yerler_json = ?, updated_at = ? WHERE id = ?')
            ->execute([$kay ? siparis_json($kay) : null, $yj ? siparis_json($yj) : null, siparis_simdi(), $kid]);
    }
}

/** PC satırları (bir order_ref) → sevkiyat + kalemler. @return int çatışma sayısı */
function siparis_pc_uygula(PDO $pdo, int $sid, array $belge, array $rows): int
{
    $bid = (int)$belge['id']; $sira = siparis_belge_sira($belge);
    $ilk = $rows[0] ?? [];
    $r = [
        siparis_alan_uygula($pdo, 'sevkiyat', $sid, 'sold_to', $ilk['sold_to'] ?? null, 'pc', $bid, $sira),
        siparis_alan_uygula($pdo, 'sevkiyat', $sid, 'bosaltma_tarihi', $ilk['pricing_date'] ?? null, 'pc', $bid, $sira),
    ];
    $grup = [];
    foreach ($rows as $row) {
        $k = (string)($row['ian'] ?? '') . '|' . (string)($row['pcs_per_box'] ?? '');
        if (!isset($grup[$k])) $grup[$k] = ['row' => $row, 'koli' => 0];
        $grup[$k]['koli'] += (int)($row['quantity'] ?? 0);
    }
    $gorulen = []; $n = 0;
    foreach ($grup as $g) {
        $row = $g['row'];
        $kid = siparis_kalem_hazirla($pdo, $sid, (string)($row['ian'] ?? ''), (string)($row['pcs_per_box'] ?? ''), 900 + $n++, false);
        $gorulen[] = $kid;
        siparis_kalem_miktar($pdo, $kid, 'pc', ['pc_koli' => $g['koli']], [], $bid, $sira);
        $k = fn(string $alan, mixed $v): string => siparis_alan_uygula($pdo, 'kalem', $kid, $alan, $v, 'pc', $bid, $sira);
        array_push($r,
            $k('urun', $row['product'] ?? null),
            $k('aciklama', $row['description'] ?? null),
            $k('cesit', $row['variety'] ?? null),
            $k('fiyat', $row['price'] ?? null),
            $k('para_birimi', $row['currency'] ?? null),
            $k('fiyat_birim', $row['price_unit'] ?? null));
        $pdo->prepare('UPDATE siparis_kalemler SET fiyat_miras = ?, fiyat_tarihi = ? WHERE id = ?')
            ->execute([!empty($row['price_inherited']) ? 1 : 0, siparis_tarih_coz((string)($row['pricing_date'] ?? '')), $kid]);
    }
    siparis_yok_isaretle($pdo, $sid, 'pc', $gorulen);
    return siparis_uygula_sayi($r);
}

/**
 * Yüklenen müşteri PKL'i → siparis_pkl satırı: yoksa / taslaksa / yüklenmişse 'yuklendi' olarak yazılır
 * (belge no, palet aralığı, dosya); sezon sayaçları son = max(son, değer). Üretilmiş PKL farklı numaralıysa
 * yalnız 'uyari' olayı. @return list<string> mesajlar
 */
function siparis_pkl_yuklenen_uygula(PDO $pdo, int $sid, array $belge, array $pk, ?int $uid, bool $yeniBag): array
{
    $mesaj = [];
    $sev = siparis_sevkiyat($pdo, $sid);
    if (!$sev) return $mesaj;
    $st = $pdo->prepare('SELECT * FROM siparis_pkl WHERE sevkiyat_id = ?' . siparis_kilit_sql($pdo));
    $st->execute([$sid]);
    $pkl = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $bno = isset($pk['belge_no']) && $pk['belge_no'] !== null && $pk['belge_no'] !== '' ? (string)$pk['belge_no'] : null;
    $bsayi = $bno !== null && preg_match('/(\d+)\s*$/', $bno, $m) ? (int)$m[1] : null;
    $c5 = isset($pk['baslik']['C5']) ? siparis_tarih_coz((string)$pk['baslik']['C5']) : null;
    $sezon = $pkl && (string)$pkl['sezon'] !== '' ? (string)$pkl['sezon'] : siparis_sezon($sev['yukleme_tarihi'] ?? $c5);
    $bid = (int)$belge['id'];
    if ($pkl && $pkl['durum'] === 'uretildi') {
        if ($yeniBag && $bno !== null && (string)$pkl['belge_no'] !== $bno) {
            siparis_olay($pdo, $sid, 'uyari', (string)$pkl['belge_no'], $bno, "Yüklenen PKL'in numarası üretilen PKL'den farklı — kontrol edin", $bid, $uid);
            $mesaj[] = "Yüklenen PKL ({$bno}) sistemde üretilen PKL'den ({$pkl['belge_no']}) farklı numaralı.";
        }
        return $mesaj;
    }
    if ($pkl && $pkl['durum'] === 'yuklendi' && (int)$pkl['dosya_belge_id'] === $bid) return $mesaj;    // aynı dosya: değişiklik yok
    $simdi = siparis_simdi();
    $alanlar = ['durum' => 'yuklendi', 'sezon' => $sezon, 'belge_no' => $bno, 'belge_no_sayi' => $bsayi,
                'palet_bas' => $pk['palet_min'] ?? null, 'palet_bit' => $pk['palet_max'] ?? null,
                'dosya_belge_id' => $bid, 'dosya_adi' => siparis_kirp((string)$belge['orijinal_ad'], 150), 'updated_by' => $uid, 'updated_at' => $simdi];
    $yaz = function (array $a) use ($pdo, $pkl, $sid, $uid, $simdi): void {
        if ($pkl) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($a)));
            $pdo->prepare("UPDATE siparis_pkl SET $set WHERE id = ?")->execute(array_merge(array_values($a), [(int)$pkl['id']]));
        } else {
            $a['sevkiyat_id'] = $sid; $a['created_by'] = $uid; $a['created_at'] = $simdi;
            $pdo->prepare('INSERT INTO siparis_pkl (' . implode(', ', array_keys($a)) . ') VALUES (' . implode(', ', array_fill(0, count($a), '?')) . ')')->execute(array_values($a));
        }
    };
    // başka PKL'de aynı numara varsa (uq_sp_no) numarasız yaz — DB hatası transaction'ı bozmasın diye önce sor
    if ($bno !== null) {
        $c = $pdo->prepare('SELECT id FROM siparis_pkl WHERE sezon = ? AND belge_no = ? AND sevkiyat_id <> ?');
        $c->execute([$sezon, $bno, $sid]);
        if ($c->fetchColumn() !== false) {
            $mesaj[] = "PKL numarası {$bno} bu sezonda başka bir sevkiyatta kayıtlı — numara aktarılmadı.";
            $alanlar['belge_no'] = null; $alanlar['belge_no_sayi'] = null; $bsayi = null;
        }
    }
    $yaz($alanlar);
    foreach (['palet_' . $sezon => $pk['palet_max'] ?? null, 'belge_no_' . $sezon => $bsayi] as $ad => $deger) {
        if ($deger === null) continue;
        $son = siparis_sayac_kilitle($pdo, $ad);
        if ((int)$deger > $son) $pdo->prepare('UPDATE siparis_sayaclar SET son_deger = ?, updated_at = ? WHERE ad = ?')->execute([(int)$deger, $simdi, $ad]);
    }
    siparis_olay($pdo, $sid, 'pkl_yuklendi', null, $alanlar['belge_no'], 'Müşteri PKL Excel\'i yüklendi' . (isset($pk['palet_min']) && $pk['palet_min'] !== null ? " (palet {$pk['palet_min']}–{$pk['palet_max']})" : ''), $bid, $uid);
    return $mesaj;
}

// ── BELGE İŞLEME ────────────────────────────────────────────────────────

/** v375 — belge eski okuyucuyla okunmuş mu? (PDF okuması parser_surum yazar; PKL/görsel NULL kalır → asla "eski" sayılmaz) */
function siparis_belge_surum_eski(array $belge): bool
{
    $s = $belge['parser_surum'] ?? null;
    return $s !== null && (string)$s !== '' && (string)$s !== SIPARIS_PDF_SURUM && ($belge['durum'] ?? '') === 'islendi';
}

/**
 * PDF ayrıştırıcı çıktısının belge türü kodu (po/ll/pc; diğeri hedef sevkiyat varsa 'diger', yoksa 'bilinmiyor').
 * TEK eşleme: siparis_belge_isle() ve mail yolunun ön tür kapısı (config/siparis_mail.php) aynı fonksiyonu çağırır.
 */
function siparis_pdf_tur_kodu(array $doc, bool $hedefVar = false): string
{
    return match ((string)($doc['doc_type'] ?? '')) {
        'purchase_order' => 'po', 'loading_list' => 'll', 'price_confirmation' => 'pc',
        default => $hedefVar ? 'diger' : 'bilinmiyor',
    };
}

/**
 * Belgeyi sınıflar, ayrıştırır, sevkiyatlara bağlar ve değerleri uygular (tek transaction).
 * İdempotent. Hata → rollback + belge durum='hata' (ayrı ifade), ok=false.
 * @return array{ok:bool, tur:string, ozet:string, sevkiyatlar:list<array{id:int,order_ref:string,yeni:bool}>, catisma:int, uyari:int, mesajlar:list<string>, hata?:string}
 */
function siparis_belge_isle(PDO $pdo, int $belgeId, int $uid, ?int $hedefSevkiyatId = null): array
{
    $sonuc = ['ok' => false, 'tur' => 'bilinmiyor', 'ozet' => '', 'sevkiyatlar' => [], 'catisma' => 0, 'uyari' => 0, 'mesajlar' => []];
    $st = $pdo->prepare('SELECT * FROM siparis_belgeler WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$belgeId]);
    $belge = $st->fetch(PDO::FETCH_ASSOC);
    if (!$belge) return $sonuc + ['hata' => 'Belge bulunamadı.'];
    $sonuc['tur'] = (string)$belge['tur'];
    $hataYaz = function (string $mesaj) use ($pdo, $belgeId, &$sonuc): array {
        try {
            $pdo->prepare("UPDATE siparis_belgeler SET durum = 'hata', hata_mesaj = ? WHERE id = ?")->execute([siparis_kirp($mesaj), $belgeId]);
        } catch (PDOException $e) {
            error_log('[siparis_belge_isle] hata durumu yazılamadı: ' . $e->getMessage());
        }
        return $sonuc + ['hata' => $mesaj];
    };
    $hedef = null;
    if ($hedefSevkiyatId !== null) {
        $hedef = siparis_sevkiyat($pdo, $hedefSevkiyatId);
        if ($hedef === null) return $sonuc + ['hata' => 'Hedef sevkiyat bulunamadı.'];
    }
    $yol = siparis_dosya_yolu($belge);
    if ($yol === null) return $hataYaz('Belge dosyası bulunamadı.');
    $ext = strtolower(pathinfo((string)$belge['disk_adi'], PATHINFO_EXTENSION));
    $cins = siparis_dosya_uzantilari()[$ext] ?? 'bilinmiyor';
    $override = $GLOBALS['_siparis_parse_override'] ?? null;     // YALNIZ TEST KANCASI
    @set_time_limit(60);

    // — ayrıştırma (saf; transaction dışında) —
    $doc = null; $pk = null;
    if ($cins === 'pdf') {
        $doc = is_callable($override) ? (array)$override($yol) : siparis_pdf_isle($yol);
        if (($doc['doc_type'] ?? '') === 'error') {
            error_log('[siparis_belge_isle] belge #' . $belgeId . ' ayrıştırılamadı: ' . (string)($doc['error'] ?? ''));
            try {
                $pdo->prepare('UPDATE siparis_belgeler SET parser_surum = ?, parse_json = ? WHERE id = ?')->execute([SIPARIS_PDF_SURUM, siparis_json($doc), $belgeId]);
            } catch (PDOException $e) {}
            return $hataYaz('PDF okunamadı (bozuk, şifreli ya da desteklenmeyen biçim).');
        }
    } elseif ($cins === 'xlsx') {
        $pk = is_callable($override) ? (array)$override($yol) : siparis_pkl_oku($yol);
        if (empty($pk['ok'])) return $hataYaz((string)($pk['hata'] ?? 'Excel dosyası okunamadı.'));
        if (empty($pk['pkl'])) return $hataYaz("Yalnız müşteri PKL Excel'i kabul edilir.");
    } elseif ($cins === 'resim') {
        if ($hedef === null) return $hataYaz('Görsel yalnız sevkiyat sayfasından yüklenir.');
    } else {
        return $hataYaz('Desteklenmeyen dosya türü.');
    }

    $sahip = !$pdo->inTransaction();
    if ($sahip) $pdo->beginTransaction();
    try {
        $tur = 'bilinmiyor'; $belgeTarihi = $belge['belge_tarihi']; $pdfOl = null; $cwH = null; $cwY = null;
        $ted = SIPARIS_VARSAYILAN_TEDARIKCI; $ozet = ''; $uyariSay = 0; $parseJson = null; $surum = null;
        $tedCoz = fn($v): string => is_scalar($v) && preg_match('/^\d{5,10}$/', trim((string)$v)) ? trim((string)$v) : SIPARIS_VARSAYILAN_TEDARIKCI;
        if ($doc !== null) {
            $tur = siparis_pdf_tur_kodu($doc, $hedef !== null);
            $h = (array)($doc['header'] ?? []);
            $meta = (array)($doc['meta'] ?? []);
            $pdfOl = isset($meta['created']) && is_string($meta['created']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $meta['created']) ? $meta['created'] : null;
            $hd = siparis_tarih_coz((string)($h['date'] ?? ''));
            $belgeTarihi = match ($tur) {
                'po' => $hd !== null ? $hd . ' ' . (preg_match('/^\d{2}:\d{2}:\d{2}$/', (string)($h['time'] ?? '')) ? $h['time'] : '00:00:00') : $pdfOl,
                'pc' => $hd !== null ? $hd . ' 00:00:00' : $pdfOl,
                'll' => $pdfOl,
                default => $pdfOl,
            };
            if ($tur === 'po') { $cwH = $h['cw'] ?? null; $cwY = $h['cw_year'] ?? null; }
            if ($tur === 'pc') { $cwH = $doc['cw']['week'] ?? ($h['cw'] ?? null); $cwY = $doc['cw']['year'] ?? ($h['cw_year'] ?? null); }
            $ted = $tedCoz($h['supplier_no'] ?? null);
            $uyariSay = count(array_filter((array)($doc['warnings'] ?? []), fn($w) => in_array($w['level'] ?? '', ['warn', 'error'], true)));
            $parseJson = siparis_json($doc);
            $surum = SIPARIS_PDF_SURUM;
        } elseif ($pk !== null) {
            $tur = 'pkl';
            $ted = $tedCoz($pk['baslik']['C2'] ?? null);
            $belgeTarihi = $belgeTarihi ?? siparis_simdi();        // kararlı sıra anahtarı: ilk işlenişte sabitlenir
            $parseJson = siparis_json($pk);
        } else {
            $tur = 'diger';
            $belgeTarihi = $belgeTarihi ?? siparis_simdi();
        }
        $belge['tur'] = $tur; $belge['belge_tarihi'] = $belgeTarihi; $belge['pdf_olusturma'] = $pdfOl;
        $sonuc['tur'] = $tur;

        $etkilenen = [];              // sid => order_ref
        $sevListe = [];
        $ekle = function (int $sid, string $ref, bool $yeni) use (&$etkilenen, &$sevListe): void {
            if (!isset($etkilenen[$sid])) { $etkilenen[$sid] = $ref; $sevListe[] = ['id' => $sid, 'order_ref' => $ref, 'yeni' => $yeni]; }
        };
        $catisma = 0;
        $refGecerli = fn($r): bool => is_string($r) && preg_match('/^[A-Z]{1,3}\d{5,}\.\d{1,3}$/', $r) === 1;
        $birim = function (string $ref, ?string $turNo, string $src) use ($pdo, $ted, $uid, $belge, $belgeId, &$sonuc, $ekle): array {
            $r = siparis_sevkiyat_bul_olustur($pdo, $ted, $ref, $turNo, $uid, (string)$belge['depo'], $belgeId, $src);
            if (isset($r['uyari'])) $sonuc['mesajlar'][] = $r['uyari'];
            $ekle($r['id'], $ref, $r['yeni']);
            $yeniBag = siparis_bag_yaz($pdo, $belgeId, $r['id'], $uid);
            $guncel = siparis_revizyon_isle($pdo, $belge, $r['id'], $uid);
            if (!$guncel) $sonuc['mesajlar'][] = "{$ref}: bu belge daha yeni bir " . siparis_belge_tur_etiketleri()[$belge['tur']] . "'den eski — bağlandı, değerleri uygulanmadı.";
            return [$r['id'], $guncel, $yeniBag];
        };

        if ($tur === 'po') {
            $sayi = 0;
            foreach ((array)($doc['loadings'] ?? []) as $o) {
                $ref = (string)($o['order_ref'] ?? '');
                if (!$refGecerli($ref)) { $sonuc['mesajlar'][] = 'Order ref okunamayan yükleme bloğu atlandı.'; continue; }
                [$sid, $guncel] = $birim($ref, isset($o['tour']) ? (string)$o['tour'] : null, 'po');
                if ($guncel) $catisma += siparis_po_uygula($pdo, $sid, $belge, $doc, $o);
                $sayi++;
            }
            $dest = trim((string)($doc['destination']['name'] ?? ''));
            $ozet = 'PO CW' . ($cwH ?? '?') . '.' . ($cwY ?? '?') . ($dest !== '' ? ' · ' . $dest : '') . ' · ' . $sayi . ' sevkiyat';
        } elseif ($tur === 'll') {
            $sayi = 0;
            // Aynı order ref birden çok kez gelebilir (ör. iki tur no): TEK birimde uygulanır — yoksa sonraki kayıt, öncekinin
            // kalemlerini "bu belgede yok" sayardı. İlk kaydın tur/taşıma bilgisi geçerli, kalemler birleşir.
            $llSev = [];
            foreach ((array)($doc['shipments'] ?? []) as $sh) {
                $ref = (string)($sh['order_ref'] ?? '');
                if (!isset($llSev[$ref])) { $llSev[$ref] = $sh; continue; }
                $llSev[$ref]['items'] = array_merge((array)($llSev[$ref]['items'] ?? []), (array)($sh['items'] ?? []));
                foreach (['pallets', 'colli', 'row_count'] as $f) $llSev[$ref][$f] = (int)($llSev[$ref][$f] ?? 0) + (int)($sh[$f] ?? 0);
            }
            foreach ($llSev as $sh) {
                $ref = (string)($sh['order_ref'] ?? '');
                if (!$refGecerli($ref)) { $sonuc['mesajlar'][] = 'Order ref okunamayan Loading List satırı atlandı.'; continue; }
                [$sid, $guncel] = $birim($ref, isset($sh['tour_id']) ? (string)$sh['tour_id'] : null, 'll');
                if ($guncel) $catisma += siparis_ll_uygula($pdo, $sid, $belge, $doc, $sh);
                $sayi++;
            }
            $h = (array)($doc['header'] ?? []);
            $tlar = array_values(array_filter((array)($h['transports'] ?? []), 'is_array')) ?: [$h];   // v374: tır başına bir Transport bloğu
            $ozet = 'LL ' . implode(' + ', array_map(fn($b) => trim((string)($b['transport_no'] ?? '?')) . (trim((string)($b['transport_company'] ?? '')) !== '' ? ' · ' . trim((string)$b['transport_company']) : ''), $tlar)) . ' · ' . $sayi . ' sevkiyat';
        } elseif ($tur === 'pc') {
            $gruplar = [];
            // PC satırlarında IAN yalnız grubun ilk satırında basılı olabilir: aynı ürün/sold-to'nun bilinen IAN'ı eksik satırlara taşınır
            $ianBilinen = [];
            foreach ((array)($doc['rows'] ?? []) as $row) {
                if (!empty($row['ian'])) $ianBilinen[(string)($row['sold_to'] ?? '') . '|' . (string)($row['product'] ?? '')] ??= (string)$row['ian'];
            }
            foreach ((array)($doc['rows'] ?? []) as $row) {
                if (empty($row['ian'])) $row['ian'] = $ianBilinen[(string)($row['sold_to'] ?? '') . '|' . (string)($row['product'] ?? '')] ?? null;
                $ref = (string)($row['order_ref'] ?? '');
                if (!$refGecerli($ref)) { $sonuc['mesajlar'][] = 'Order ref okunamayan fiyat satırı atlandı.'; continue; }
                $gruplar[$ref][] = $row;
            }
            foreach ($gruplar as $ref => $rows) {
                [$sid, $guncel] = $birim((string)$ref, null, 'pc');
                if ($guncel) $catisma += siparis_pc_uygula($pdo, $sid, $belge, $rows);
            }
            $ozet = 'PC CW' . ($cwH ?? '?') . '.' . ($cwY ?? '?') . ' · ' . count($gruplar) . ' sevkiyat';
        } elseif ($tur === 'pkl') {
            foreach ((array)($pk['order_refs'] ?? []) as $ref) {
                $ref = (string)$ref;
                $f = $pdo->prepare('SELECT id FROM siparis_sevkiyatlar WHERE tedarikci_no = ? AND order_ref = ? AND deleted_at IS NULL');
                $f->execute([$ted, $ref]);
                $sid = $f->fetchColumn();
                if ($sid === false) { $sonuc['mesajlar'][] = "PKL'deki {$ref} için sevkiyat yok — önce PO ya da Loading List yükleyin."; continue; }
                $sid = (int)$sid;
                $ekle($sid, $ref, false);
                $yeniBag = siparis_bag_yaz($pdo, $belgeId, $sid, $uid);
                if (siparis_revizyon_isle($pdo, $belge, $sid, $uid)) {
                    foreach (siparis_pkl_yuklenen_uygula($pdo, $sid, $belge, $pk, $uid, $yeniBag) as $m) $sonuc['mesajlar'][] = $m;
                    siparis_sevkiyat_yenile($pdo, $sid);
                    siparis_durum_ilerlet($pdo, $sid, 'pkl_hazir', $uid, 'PKL yüklendi', $belgeId);
                }
            }
            $ozet = 'PKL ' . ($pk['belge_no'] ?? '?') . ' · ' . (int)($pk['satir'] ?? 0) . ' palet'
                . (isset($pk['palet_min']) && $pk['palet_min'] !== null ? ' (' . $pk['palet_min'] . '–' . $pk['palet_max'] . ')' : '');
        } elseif ($tur === 'bilinmiyor') {
            $ozet = 'Tanınmayan belge';
            $sonuc['mesajlar'][] = 'Belge türü tanınmadı (PO, Loading List, Price Confirmation değil) — sevkiyata bağlanmadı.';
        } else {
            $ozet = $cins === 'resim' ? 'Görsel' : 'Diğer belge';
        }
        // hedef sevkiyat: içerik başka sevkiyata aitse yine içeriğe göre işlenir, kullanıcıya söylenir
        if ($hedef !== null) {
            $hid = (int)$hedef['id'];
            if (!isset($etkilenen[$hid])) {
                if (in_array($tur, ['diger', 'bilinmiyor'], true) || $etkilenen === []) {
                    siparis_bag_yaz($pdo, $belgeId, $hid, $uid);
                    $ekle($hid, (string)$hedef['order_ref'], false);
                } else {
                    $sonuc['mesajlar'][] = 'Bu belge ' . implode(', ', array_values($etkilenen)) . ' içeriyor; o sevkiyata bağlandı (' . $hedef['order_ref'] . ' değil).';
                }
            }
        }
        $pdo->prepare('UPDATE siparis_belgeler SET tur = ?, durum = ?, belge_tarihi = ?, pdf_olusturma = ?, cw_hafta = ?, cw_yil = ?, tedarikci_no = ?, ozet = ?,
                parser_surum = ?, parse_json = ?, uyari_sayisi = ?, hata_mesaj = NULL WHERE id = ?')
            ->execute([$tur, 'islendi', $belgeTarihi, $pdfOl, is_numeric($cwH) ? (int)$cwH : null, is_numeric($cwY) ? (int)$cwY : null,
                       $ted, siparis_kirp($ozet), $surum, $parseJson, $uyariSay, $belgeId]);
        foreach (array_keys($etkilenen) as $sid) siparis_sevkiyat_yenile($pdo, (int)$sid);
        if ($sahip) $pdo->commit();
        $sonuc['ok'] = true; $sonuc['ozet'] = $ozet; $sonuc['sevkiyatlar'] = $sevListe;
        $sonuc['catisma'] = $catisma; $sonuc['uyari'] = $uyariSay;
        return $sonuc;
    } catch (Throwable $e) {
        if ($sahip && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[siparis_belge_isle] belge #' . $belgeId . ': ' . get_class($e) . ': ' . $e->getMessage());
        if (!$sahip) throw $e;
        $sonuc['sevkiyatlar'] = []; $sonuc['mesajlar'] = [];
        return $hataYaz('Belge işlenemedi. Teknik ayrıntılar sunucu günlüğüne kaydedildi.');
    }
}

// ── ORTAK YÜKLEME YOLU (v378) ───────────────────────────────────────────

/**
 * Tek dosyayı kaydeder + (gerekiyorsa) işler + audit 'siparis_belge_yukle'. siparis_yukle.php (elle yükleme) ve
 * config/siparis_mail.php (mailden alma) AYNI fonksiyonu çağırır — İKİNCİ YAZMA YOLU AÇMA.
 * $opt: kaynak ('yukleme'|'mail'), islem (audit 'islem'; varsayılan 'yukle'), audit_ek (array; kişisel veri YOK: mail_id, hesap_id, parca),
 *       silineni_geri_getir (bool, varsayılan true — mail yolu false geçer: silinmiş belge DİRİLTİLMEZ, durum 'silinmis').
 * @return array{dosya:string,durum:'islendi'|'zaten'|'silinmis'|'hata',tur:?string,sevkiyatlar:list<array{id:int,order_ref:string,yeni:bool}>,
 *               catisma:int,uyari:int,mesajlar:list<string>,belge_id:?int,yeni:bool,yeniden?:int,eski_surum?:?string,hata?:string}
 */
function siparis_dosya_yukle_isle(PDO $pdo, array $dosya, int $uid, string $depo, ?array $hedef, ?callable $mover, array $opt = []): array
{
    $r = ['dosya' => siparis_kirp(basename(str_replace('\\', '/', (string)$dosya['name'])), 120), 'durum' => 'hata', 'tur' => null, 'sevkiyatlar' => [], 'catisma' => 0, 'uyari' => 0, 'mesajlar' => [],
          'belge_id' => null, 'yeni' => false];
    $kaynak = (string)($opt['kaynak'] ?? 'yukleme');
    $geriGetir = ($opt['silineni_geri_getir'] ?? true) !== false;
    try {
        // Aynı içerik daha önce silinmiş miydi? (kaydet geri getirir; sevkiyat etkisi yeniden uygulanmalı)
        $silinmisti = false;
        if (is_file((string)$dosya['tmp_name'])) {
            $sha = (string)hash_file('sha256', $dosya['tmp_name']);
            $o = $pdo->prepare('SELECT id, deleted_at FROM siparis_belgeler WHERE sha256 = ?');
            $o->execute([$sha]);
            $oncekiSatir = $o->fetch(PDO::FETCH_ASSOC);
            $silinmisti = $oncekiSatir && $oncekiSatir['deleted_at'] !== null;
            if ($silinmisti && !$geriGetir) {   // mail yolu: kullanıcının bilerek sildiği belge her taramada dirilmez
                $r['durum'] = 'silinmis';
                $r['belge_id'] = (int)$oncekiSatir['id'];
                return $r;
            }
        }
        $k = siparis_dosya_kaydet($pdo, $dosya, $uid, $depo, $mover, $kaynak);
        if (!$k['ok']) { $r['hata'] = (string)($k['hata'] ?? 'Dosya kaydedilemedi.'); return $r; }
        $belgeId = (int)$k['belge_id'];
        $r['belge_id'] = $belgeId;
        $r['yeni'] = (bool)$k['yeni'];
        $b = $pdo->prepare('SELECT id, depo, durum, tur, parser_surum FROM siparis_belgeler WHERE id = ?');
        $b->execute([$belgeId]);
        $bs = $b->fetch(PDO::FETCH_ASSOC) ?: ['id' => $belgeId, 'depo' => '', 'durum' => 'yuklendi', 'tur' => 'bilinmiyor', 'parser_surum' => null];
        $baglar = $pdo->prepare('SELECT s.id, s.order_ref FROM siparis_belge_bag bb JOIN siparis_sevkiyatlar s ON s.id = bb.sevkiyat_id WHERE bb.belge_id = ? AND s.deleted_at IS NULL');
        $baglar->execute([$belgeId]);
        $mevcutBaglar = $baglar->fetchAll(PDO::FETCH_ASSOC);
        $hedefeBagli = $hedef === null || in_array((int)$hedef['id'], array_map('intval', array_column($mevcutBaglar, 'id')), true);
        // v375: aynı dosya eski okuyucuyla okunmuşsa (parser_surum ≠ güncel) yeniden okunur — aynı belge id, ikinci satır YOK
        $yenidenOku = !$k['yeni'] && !$silinmisti && siparis_belge_surum_eski($bs) && siparis_belge_gorunur($pdo, $bs);
        $eskiSurum = $yenidenOku ? (string)$bs['parser_surum'] : null;
        $isle = $k['yeni'] || $silinmisti || $bs['durum'] !== 'islendi' || !$hedefeBagli || $yenidenOku;
        $r['tur'] = (string)$bs['tur'];
        if (!$isle) {
            $r['durum'] = 'zaten';
            foreach ($mevcutBaglar as $m) $r['sevkiyatlar'][] = ['id' => (int)$m['id'], 'order_ref' => (string)$m['order_ref'], 'yeni' => false];
            return $r;
        }
        $x = siparis_belge_isle($pdo, $belgeId, $uid, $hedef !== null ? (int)$hedef['id'] : null);
        $r['tur'] = (string)$x['tur'];
        if (!$x['ok']) {
            $r['hata'] = (string)($x['hata'] ?? 'Belge işlenemedi.');
        } else {
            $r['durum'] = 'islendi';
            if ($yenidenOku) { $r['yeniden'] = 1; $r['eski_surum'] = $eskiSurum; }
            $r['sevkiyatlar'] = $x['sevkiyatlar'];
            $r['catisma'] = (int)$x['catisma'];
            $r['uyari'] = (int)$x['uyari'];
            $r['mesajlar'] = array_slice($x['mesajlar'], 0, 8);
        }
        $islem = $yenidenOku ? 'surum_yenile' : (string)($opt['islem'] ?? 'yukle');
        audit_log_event('siparis_belge_yukle', 'siparis', $belgeId, null, [
            'belge_id' => $belgeId, 'tur' => $r['tur'], 'yeni' => $k['yeni'] ? 1 : 0, 'ok' => $x['ok'] ? 1 : 0,
            'sevkiyat_ids' => array_map(fn($s) => (int)$s['id'], $x['sevkiyatlar']),
        ] + ['islem' => $islem] + ($yenidenOku ? ['eski_surum' => $eskiSurum] : []) + (array)($opt['audit_ek'] ?? []));
    } catch (Throwable $e) {
        error_log('[siparis_yukle] ' . get_class($e) . ': ' . $e->getMessage());
        $r['durum'] = 'hata';
        $r['hata'] = 'Dosya işlenemedi. Teknik ayrıntılar sunucu günlüğüne kaydedildi.';
    }
    return $r;
}

// ── YENİDEN HESAP (C.8) ─────────────────────────────────────────────────

/** "2.400" — koli/palet ekran biçimi. */
function siparis_sayi_bicim(?int $n): string
{
    return $n === null ? '—' : number_format($n, 0, ',', '.');
}

/**
 * Etkin miktarlar (elle > PC > LL > PO; PC varken PC'de olmayan kalem = 0), toplamlar, var_*
 * bayrakları, uyarilar_json, uyari_sayisi, acik_catisma; plaka geldiyse durum → arac_atandi.
 */
function siparis_sevkiyat_yenile(PDO $pdo, int $sid): void
{
    $sev = siparis_sevkiyat($pdo, $sid);
    if ($sev === null) return;
    $var = ['po' => 0, 'll' => 0, 'pc' => 0, 'pkl' => 0];
    $st = $pdo->prepare('SELECT DISTINCT b.tur FROM siparis_belge_bag bb JOIN siparis_belgeler b ON b.id = bb.belge_id
        WHERE bb.sevkiyat_id = ? AND bb.gecerli = 1 AND b.deleted_at IS NULL');
    $st->execute([$sid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) if (isset($var[(string)$t]) && $t !== 'pkl') $var[(string)$t] = 1;
    $pk = $pdo->prepare("SELECT durum FROM siparis_pkl WHERE sevkiyat_id = ?");
    $pk->execute([$sid]);
    $var['pkl'] = in_array((string)$pk->fetchColumn(), ['uretildi', 'yuklendi'], true) ? 1 : 0;

    $uy = [];
    $uyari = function (string $kod, string $mesaj, ?string $kalibre = null, string $seviye = 'uyari') use (&$uy): void {
        $u = ['kod' => $kod, 'seviye' => $seviye, 'mesaj' => $mesaj];
        if ($kalibre !== null) $u['kalibre'] = $kalibre;
        $uy[] = $u;
    };
    $kalemler = siparis_kalemler($pdo, $sid);
    $topP = null; $topK = null;
    $kaynakToplam = ['po' => 0, 'll' => 0, 'pc' => 0];
    $simdi = siparis_simdi();
    foreach ($kalemler as $k) {
        $elle = $k['_elle'];
        $yok = array_values(array_filter((array)($k['_kaynak']['_yok'] ?? []), 'is_string'));
        $mK = isset($elle['etkin_koli']) && $k['etkin_koli'] !== null ? (int)$k['etkin_koli'] : null;
        $mP = isset($elle['etkin_palet']) && $k['etkin_palet'] !== null ? (int)$k['etkin_palet'] : null;
        $pcYok = $var['pc'] === 1 && $k['pc_koli'] === null && in_array('pc', $yok, true);
        $pf = $k['pf'] !== null ? (int)$k['pf'] : 0;
        if ($mK !== null) { $koli = $mK; $kay = 'elle'; }
        elseif ($k['pc_koli'] !== null) { $koli = (int)$k['pc_koli']; $kay = 'pc'; }
        elseif ($pcYok) { $koli = 0; $kay = 'pc'; }
        elseif ($k['ll_koli'] !== null) { $koli = (int)$k['ll_koli']; $kay = 'll'; }
        elseif ($k['po_koli'] !== null) { $koli = (int)$k['po_koli']; $kay = 'po'; }
        else { $koli = null; $kay = null; }
        if ($mP !== null) $palet = $mP;
        elseif ($k['pc_koli'] !== null && $pf > 0) {
            $palet = (int)ceil((int)$k['pc_koli'] / $pf);
            if ((int)$k['pc_koli'] % $pf !== 0) $uyari('pc_palet_bolunmuyor', 'Kalibre ' . $k['kalibre'] . ': PC miktarı (' . siparis_sayi_bicim((int)$k['pc_koli']) . ') palet başına koliye (' . $pf . ') tam bölünmüyor — palet yukarı yuvarlandı.', (string)$k['kalibre']);
        }
        elseif ($pcYok) $palet = 0;
        elseif ($k['ll_palet'] !== null) $palet = (int)$k['ll_palet'];
        elseif ($k['po_palet'] !== null) $palet = (int)$k['po_palet'];
        else $palet = null;
        $pdo->prepare('UPDATE siparis_kalemler SET etkin_palet = ?, etkin_koli = ?, etkin_kaynak = ? WHERE id = ?')->execute([$palet, $koli, $kay, (int)$k['id']]);
        if ($palet !== null) $topP = ($topP ?? 0) + $palet;
        if ($koli !== null) $topK = ($topK ?? 0) + $koli;
        // kaynak bazında bölüm farkı
        $deg = [];
        foreach (['po' => 'po_koli', 'll' => 'll_koli', 'pc' => 'pc_koli'] as $src => $kol) {
            if ($var[$src] !== 1) continue;
            if ($k[$kol] !== null) { $deg[$src] = (int)$k[$kol]; $kaynakToplam[$src] += (int)$k[$kol]; }
            elseif (in_array($src, $yok, true)) $deg[$src] = null;            // bu belgede kalem yok
        }
        $dolu = array_filter($deg, fn($x) => $x !== null);
        $eksikVar = count($dolu) > 0 && count($dolu) < count($deg);
        if (count(array_unique($dolu)) > 1 || $eksikVar) {
            $parca = [];
            foreach ($deg as $src => $x) $parca[] = strtoupper($src) . ' ' . ($x === null ? 'yok' : siparis_sayi_bicim($x));
            $uyari('split_differs', 'Kalibre ' . $k['kalibre'] . ': ' . implode(' · ', $parca) . " koli — PKL'de kullanılacak miktarı kontrol edin.", (string)$k['kalibre']);
        }
        $cPo = $k['_kaynak']['cesit']['po']['v'] ?? null; $cPc = $k['_kaynak']['cesit']['pc']['v'] ?? null;
        if ($cPo !== null && $cPc !== null && mb_strtoupper((string)$cPo, 'UTF-8') !== mb_strtoupper((string)$cPc, 'UTF-8')) {
            $uyari('cesit_farkli', 'Kalibre ' . $k['kalibre'] . ': çeşit PO\'da "' . $cPo . '", PC\'de "' . $cPc . '".', (string)$k['kalibre']);
        }
    }
    $mevcutKaynaklar = array_filter(['po' => $var['po'], 'll' => $var['ll'], 'pc' => $var['pc']]);
    $toplamlar = array_intersect_key($kaynakToplam, $mevcutKaynaklar);
    if (count($toplamlar) > 1 && count(array_unique($toplamlar)) > 1) {
        $parca = [];
        foreach ($toplamlar as $src => $x) $parca[] = strtoupper($src) . ' ' . siparis_sayi_bicim($x);
        $uyari('toplam_farkli', 'Toplam koli belgeler arasında farklı: ' . implode(' · ', $parca) . '.');
    }
    $kay = $sev['_kaynak'];
    foreach (['tur_no' => ['tur_farkli', 'Tur no'], 'yukleme_tarihi' => ['yukleme_tarihi_farkli', 'Yükleme tarihi'], 'bosaltma_tarihi' => ['bosaltma_tarihi_farkli', 'Boşaltma tarihi']] as $alan => [$kod, $et]) {
        $vals = [];
        foreach (['po', 'll', 'pc'] as $src) if (isset($kay[$alan][$src]['v']) && $kay[$alan][$src]['v'] !== null) $vals[$src] = (string)$kay[$alan][$src]['v'];
        if (count(array_unique($vals)) > 1) {
            $parca = [];
            foreach ($vals as $src => $v) $parca[] = strtoupper($src) . ' ' . $v;
            $uyari($kod, $et . ' belgeler arasında farklı: ' . implode(' · ', $parca) . '.');
        }
    }
    // v421: PO eksik okunmuş (bağlanamayan satır / özet ↔ blok toplamı tutmuyor) → listede kırmızı "PO eksik okunmuş" rozeti
    if ($var['po'] === 1 && !empty($sev['po_belge_id'])) {
        $pj = $pdo->prepare('SELECT parse_json FROM siparis_belgeler WHERE id = ? AND deleted_at IS NULL');
        $pj->execute([(int)$sev['po_belge_id']]);
        $eksik = siparis_po_eksik_okuma(siparis_json_coz($pj->fetchColumn() ?: null), $sev['yukleme_tarihi'] !== null ? (string)$sev['yukleme_tarihi'] : null);
        if ($eksik !== null && (int)($sev['_kaynak']['_eksik_okuma_onay']['b'] ?? 0) !== (int)$sev['po_belge_id']) $uyari('po_eksik_okuma', $eksik, null, 'hata');
    }
    if ($var['po'] !== 1) $uyari('po_yok', 'PO henüz yüklenmedi — bu sevkiyat ' . ($var['ll'] || $var['pc'] ? 'LL/PC ile' : 'elle') . ' açıldı.');
    if ($sev['tur_no'] !== null && $sev['tur_no'] !== '') {
        $t = $pdo->prepare('SELECT order_ref FROM siparis_sevkiyatlar WHERE tur_no = ? AND id <> ? AND deleted_at IS NULL');
        $t->execute([(string)$sev['tur_no'], $sid]);
        $diger = $t->fetchAll(PDO::FETCH_COLUMN);
        if ($diger) $uyari('tur_cakismasi', 'Tur ' . $sev['tur_no'] . ' başka sevkiyatta da kayıtlı: ' . implode(', ', $diger) . ' (birleştirilmedi).');
    }
    $c = $pdo->prepare("SELECT COUNT(*) FROM siparis_catismalar WHERE sevkiyat_id = ? AND durum = 'acik'");
    $c->execute([$sid]);
    $acik = (int)$c->fetchColumn();
    $pdo->prepare('UPDATE siparis_sevkiyatlar SET var_po = ?, var_ll = ?, var_pc = ?, var_pkl = ?, toplam_palet = ?, toplam_koli = ?,
            uyarilar_json = ?, uyari_sayisi = ?, acik_catisma = ?, updated_at = ? WHERE id = ?')
        ->execute([$var['po'], $var['ll'], $var['pc'], $var['pkl'], $topP, $topK, siparis_json($uy), count($uy), $acik, $simdi, $sid]);
    if (trim((string)($sev['plakalar'] ?? '')) !== '') siparis_durum_ilerlet($pdo, $sid, 'arac_atandi', null, 'Araç plakası geldi');
}

// ── SİLME / ELLE AÇMA ───────────────────────────────────────────────────

/**
 * Belgeyi YUMUŞAK siler. Uygulanmış değerler GERİ ALINMAZ (belge_silindi olayı bunu söyler).
 * Silinen belge bir sevkiyatta geçerli sürümse aynı türün en yeni kalan belgesi geçerli olur;
 * PKL dosyasıysa PKL satırı taslağa döner (numara/aralık korunur). Audit siparis_belge_sil.
 * @return array{ok:bool,hata?:string}
 */
function siparis_belge_sil(PDO $pdo, int $belgeId, int $uid): array
{
    try {
        return siparis_tx($pdo, function () use ($pdo, $belgeId, $uid): array {
            $st = $pdo->prepare('SELECT * FROM siparis_belgeler WHERE id = ? AND deleted_at IS NULL' . siparis_kilit_sql($pdo));
            $st->execute([$belgeId]);
            $b = $st->fetch(PDO::FETCH_ASSOC);
            if (!$b) return ['ok' => false, 'hata' => 'Belge bulunamadı.'];
            $simdi = siparis_simdi();
            $pdo->prepare('UPDATE siparis_belgeler SET deleted_at = ?, deleted_by = ? WHERE id = ?')->execute([$simdi, $uid, $belgeId]);
            $bg = $pdo->prepare('SELECT sevkiyat_id, gecerli FROM siparis_belge_bag WHERE belge_id = ?');
            $bg->execute([$belgeId]);
            $sidler = [];
            foreach ($bg->fetchAll(PDO::FETCH_ASSOC) as $bag) {
                $sid = (int)$bag['sevkiyat_id'];
                $sidler[] = $sid;
                if ((int)$bag['gecerli'] === 1) {
                    $k = $pdo->prepare('SELECT bb.id AS bag_id, b.id, b.belge_tarihi, b.pdf_olusturma FROM siparis_belge_bag bb JOIN siparis_belgeler b ON b.id = bb.belge_id
                        WHERE bb.sevkiyat_id = ? AND b.tur = ? AND b.deleted_at IS NULL AND b.id <> ?');
                    $k->execute([$sid, (string)$b['tur'], $belgeId]);
                    $en = null;
                    foreach ($k->fetchAll(PDO::FETCH_ASSOC) as $x) if ($en === null || strcmp(siparis_belge_sira($x), siparis_belge_sira($en)) > 0) $en = $x;
                    if ($en !== null) $pdo->prepare('UPDATE siparis_belge_bag SET gecerli = 1 WHERE id = ?')->execute([(int)$en['bag_id']]);
                    if ($b['tur'] === 'po') {
                        $pdo->prepare('UPDATE siparis_sevkiyatlar SET po_belge_id = ? WHERE id = ? AND po_belge_id = ?')->execute([$en !== null ? (int)$en['id'] : null, $sid, $belgeId]);
                    }
                }
                $pdo->prepare("UPDATE siparis_pkl SET durum = 'taslak', dosya_belge_id = NULL, dosya_adi = NULL, updated_by = ?, updated_at = ? WHERE sevkiyat_id = ? AND dosya_belge_id = ?")
                    ->execute([$uid, $simdi, $sid, $belgeId]);
                siparis_olay($pdo, $sid, 'belge_silindi', (string)$b['orijinal_ad'], null, (siparis_belge_tur_etiketleri()[(string)$b['tur']] ?? 'Belge') . ' silindi — uygulanmış değerler geri alınmadı', $belgeId, $uid);
                siparis_sevkiyat_yenile($pdo, $sid);
            }
            siparis_audit('siparis_belge_sil', $belgeId, null, ['belge_id' => $belgeId, 'tur' => $b['tur'], 'sevkiyat_ids' => $sidler], $uid);
            return ['ok' => true];
        });
    } catch (PDOException $e) {
        error_log('[siparis_belge_sil] ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Belge silinemedi.'];
    }
}

/**
 * Belgesiz (elle) sevkiyat açar: order_ref biçimi denetlenir, depo = active_depot(), tur no ve
 * yükleme tarihi ELLE (kilitli) yazılır. Audit siparis_sevkiyat_ac. @return array{ok:bool,hata?:string,id?:int}
 */
function siparis_sevkiyat_elle_ac(PDO $pdo, string $orderRef, ?string $turNo, ?string $yuklemeTarihi, int $uid): array
{
    $ref = strtoupper(trim($orderRef));
    if (!preg_match('/^[A-Z]{1,3}\d{5,}\.\d{1,3}$/', $ref)) return ['ok' => false, 'hata' => 'Sipariş numarası "TC426865.10" biçiminde olmalı.'];
    $tur = $turNo !== null ? trim($turNo) : '';
    if ($tur !== '' && !preg_match('/^[A-Za-z0-9\-]{1,30}$/', $tur)) return ['ok' => false, 'hata' => 'Tur numarası geçersiz.'];
    $tarih = null;
    if ($yuklemeTarihi !== null && trim($yuklemeTarihi) !== '') {
        $tarih = siparis_tarih_coz($yuklemeTarihi);
        if ($tarih === null) return ['ok' => false, 'hata' => 'Yükleme tarihi geçersiz.'];
    }
    try {
        return siparis_tx($pdo, function () use ($pdo, $ref, $tur, $tarih, $uid): array {
            $st = $pdo->prepare('SELECT id FROM siparis_sevkiyatlar WHERE tedarikci_no = ? AND order_ref = ? AND deleted_at IS NULL');
            $st->execute([SIPARIS_VARSAYILAN_TEDARIKCI, $ref]);
            if ($st->fetchColumn() !== false) return ['ok' => false, 'hata' => "{$ref} zaten kayıtlı."];
            $depo = function_exists('active_depot') ? (string)(active_depot() ?? '') : '';
            $r = siparis_sevkiyat_bul_olustur($pdo, SIPARIS_VARSAYILAN_TEDARIKCI, $ref, null, $uid, $depo, 0, 'elle');
            $id = $r['id'];
            if ($tur !== '') { $x = siparis_alan_elle($pdo, 'sevkiyat', $id, 'tur_no', $tur, $uid); if (!$x['ok']) throw new RuntimeException((string)$x['hata']); }
            if ($tarih !== null) { $x = siparis_alan_elle($pdo, 'sevkiyat', $id, 'yukleme_tarihi', $tarih, $uid); if (!$x['ok']) throw new RuntimeException((string)$x['hata']); }
            siparis_sevkiyat_yenile($pdo, $id);
            siparis_audit('siparis_sevkiyat_ac', $id, null, ['id' => $id, 'order_ref' => $ref], $uid);
            return ['ok' => true, 'id' => $id];
        });
    } catch (PDOException $e) {
        error_log('[siparis_sevkiyat_elle_ac] ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Sevkiyat açılamadı.'];
    } catch (RuntimeException $e) {
        return ['ok' => false, 'hata' => $e->getMessage()];
    }
}

/** Sevkiyatı YUMUŞAK siler (gerekçe ≥ 3). Olay silindi + audit siparis_sevkiyat_sil. @return array{ok:bool,hata?:string} */
function siparis_sevkiyat_sil(PDO $pdo, int $sid, int $uid, string $gerekce): array
{
    $gerekce = trim($gerekce);
    if (mb_strlen($gerekce, 'UTF-8') < 3) return ['ok' => false, 'hata' => 'Silme gerekçesi yazın (en az 3 karakter).'];
    try {
        return siparis_tx($pdo, function () use ($pdo, $sid, $uid, $gerekce): array {
            $sev = siparis_sevkiyat($pdo, $sid);
            if ($sev === null) return ['ok' => false, 'hata' => 'Sevkiyat bulunamadı.'];
            $simdi = siparis_simdi();
            $pdo->prepare('UPDATE siparis_sevkiyatlar SET deleted_at = ?, updated_by = ?, updated_at = ? WHERE id = ?')->execute([$simdi, $uid, $simdi, $sid]);
            siparis_olay($pdo, $sid, 'silindi', null, null, $gerekce, null, $uid);
            siparis_audit('siparis_sevkiyat_sil', $sid, ['order_ref' => $sev['order_ref'], 'durum' => $sev['durum']], null, $uid);
            return ['ok' => true];
        });
    } catch (PDOException $e) {
        error_log('[siparis_sevkiyat_sil] ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Sevkiyat silinemedi.'];
    }
}
