<?php
// =========================================================
// config/siparis_yazdir.php — "Açık Sevkiyatlar" YAZDIRMA / PDF verisi (v382; include-only)
//
// siparis_yazdir.php (GET JSON ucu) bunu çağırır; çıktı/exit/yazma YOK (audit uçta). _siparis_ortak.php YÜKLÜ varsayılır
// (sipui_kalem_coz / sipui_kalem_satirlari / sipui_tarih / sipui_sayi). Filtre, ekranla AYNI fonksiyondan: siparis_liste_filtre().
// Tüm hücreler HAM METİN döner (HTML yok); kaçış istemcide (assets/siparis_yazdir.js). Sütun sırası/başlıkları sahibindir.
// YENİ SÜTUN = yalnız siparis_yazdir_veri() (ve siparis_yazdir_kolonlari()); ülke = siparis_yazdir_ulke_haritasi().
// =========================================================
declare(strict_types=1);
// Doğrudan istek → 404. TAM YOL karşılaştırılır: uç dosyası kökte AYNI ADI taşır (siparis_yazdir.php); basename karşılaştırması
// ucu da 404'e düşürüyordu (v385 — yazdırma "Oturum sona ermiş olabilir" diyordu).
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

if (!defined('SIPARIS_YAZDIR_LIMIT')) define('SIPARIS_YAZDIR_LIMIT', 500);

/**
 * Mal alıcı ID (siparis_sevkiyatlar.alici_kodu = PO "destination" id'si) → ülke. Kaynak: docs/OGL_ULKE_REHBERI.md
 * (içerikle doğrulandı: GB 1038512 / RS 1038529). Ad Türkçe, doğru büyük harfle (CSS text-transform KULLANILMAZ).
 * Yeni OGL ülkesi = buraya bir satır.
 * @return array<string,array{kod:string,ad:string}>
 */
function siparis_yazdir_ulke_haritasi(): array
{
    return [
        '1038500' => ['kod' => 'DE', 'ad' => 'ALMANYA'],
        '1038508' => ['kod' => 'NL', 'ad' => 'HOLLANDA'],
        '1038521' => ['kod' => 'PL', 'ad' => 'POLONYA'],
        '1038512' => ['kod' => 'GB', 'ad' => 'İNGİLTERE'],
        '1038519' => ['kod' => 'BG', 'ad' => 'BULGARİSTAN'],
        '1038504' => ['kod' => 'RO', 'ad' => 'ROMANYA'],
        '1038529' => ['kod' => 'RS', 'ad' => 'SIRBİSTAN'],
        '1038515' => ['kod' => 'HR', 'ad' => 'HIRVATİSTAN'],
        '1038505' => ['kod' => 'HU', 'ad' => 'MACARİSTAN'],
        '1038524' => ['kod' => 'FR', 'ad' => 'FRANSA'],
        '1038514' => ['kod' => 'SK', 'ad' => 'SLOVAKYA'],
        '1038501' => ['kod' => 'CZ', 'ad' => 'ÇEKYA'],
    ];
}

/**
 * Ülke (Türkçe ad): (1) alici_kodu haritada → ad; (2) yoksa alici_adi'nin SONUNDAKİ iki büyük harf ("LIDL RS") haritadaki bir ISO kodu ise ad;
 * (3) aksi boş. Beyan ülkesi ve PO dosya adı KULLANILMAZ (izin/yazım farkı; dosya adı elle değişebilir).
 */
function siparis_yazdir_ulke(?string $aliciKodu, ?string $aliciAdi): string
{
    $h = siparis_yazdir_ulke_haritasi();
    $k = trim((string)$aliciKodu);
    if ($k !== '' && isset($h[$k])) return $h[$k]['ad'];
    if (preg_match('/\b([A-Z]{2})$/', trim((string)$aliciAdi), $m)) {
        foreach ($h as $v) if ($v['kod'] === $m[1]) return $v['ad'];
    }
    return '';
}

/**
 * GRAMAJ hücresi: sipui_kalem_satirlari() ile AYNI süzgeçten geçen kalemlerin kalibreleri — boş olmayan, tekil, sayısal artan
 * (sayısal değilse doğal sıra), "--" ile birleşik ("10--12--14"); yok → ''. SQLite/MySQL sıralama farkı burada PHP'de çözülür.
 * Süzgeç TEK yerde: sipui_kalem_coz() (_siparis_ortak.php) — liste ve WP kartıyla aynı kalemler.
 * @param list<array<string,mixed>> $kalemler
 */
function siparis_yazdir_gramaj(array $kalemler): string
{
    $set = [];
    foreach ($kalemler as $k) {
        if (sipui_kalem_coz($k) === null) continue;
        $kal = trim((string)($k['kalibre'] ?? ''));
        if ($kal !== '') $set[$kal] = true;
    }
    $liste = array_map('strval', array_keys($set));
    usort($liste, static function (string $a, string $b): int {
        if (is_numeric($a) && is_numeric($b)) return (float)$a <=> (float)$b ?: strnatcmp($a, $b);
        return strnatcmp($a, $b);
    });
    return implode('--', $liste);
}

/**
 * Sütun sözleşmesi (sıra sahibindir, DEĞİŞTİRME). k = anahtar, l = başlık, t = tür (metin|sayi), w = genişlik % (toplam 100; TEL telefonu tek satırda, GRAMAJ başlığı bölünmeden sığsın diye §2 tablosundan 1,5 puan Ürün'den alındı).
 * @return list<array{k:string,l:string,t:string,w:float}>
 */
function siparis_yazdir_kolonlari(): array
{
    return [
        ['k' => 'tarih',    'l' => 'ÇIKIŞ TARİHİ',   't' => 'metin', 'w' => 7],
        ['k' => 'parti',    'l' => 'PARTİ NO',       't' => 'metin', 'w' => 6.5],
        ['k' => 'navlun',   'l' => 'NAVLUN NO',      't' => 'metin', 'w' => 7.5],
        ['k' => 'siparis',  'l' => 'SİPARİŞ NO',     't' => 'metin', 'w' => 7.5],
        ['k' => 'sofor',    'l' => 'ŞOFÖR ADI',      't' => 'metin', 'w' => 9],
        ['k' => 'tel',      'l' => 'TEL',            't' => 'metin', 'w' => 9],
        ['k' => 'plaka',    'l' => 'PLAKA',          't' => 'metin', 'w' => 7.5],
        ['k' => 'lojistik', 'l' => 'LOJİSTİK FİRMA', 't' => 'metin', 'w' => 9.5],
        ['k' => 'ulke',     'l' => 'ÜLKE',           't' => 'metin', 'w' => 6.5],
        ['k' => 'urun',     'l' => 'ÜRÜN BİLGİSİ',   't' => 'metin', 'w' => 16.5],
        ['k' => 'palet',    'l' => 'PALET SAYISI',   't' => 'sayi',  'w' => 4],
        ['k' => 'kasa',     'l' => 'KASA SAYISI',    't' => 'sayi',  'w' => 4.5],
        ['k' => 'gramaj',   'l' => 'GRAMAJ',         't' => 'metin', 'w' => 5],
    ];
}

/** Başlık: tek durum filtresi yoksa "Açık Sevkiyatlar", varsa "Sevkiyatlar — <durum etiketi / Araç bekleyen>". */
function siparis_yazdir_baslik(array $F): string
{
    if (empty($F['tek'])) return 'Açık Sevkiyatlar';
    $d = (string)($F['f_durum'] ?? '');
    $et = $d === 'arac_bekleyen' ? 'Araç bekleyen' : (string)(siparis_durumlar()[$d]['etiket'] ?? $d);
    return 'Sevkiyatlar — ' . $et;
}

/**
 * Yazdırılacak sevkiyatlar + kalemleri + parti no'ları (SALT OKUNUR). $F = siparis_liste_filtre() sonucu.
 * Kapsam: durum boş/acik → açık küme; arac_bekleyen / tekil durum → `tek`; durum=kapali → RuntimeException('kapsam_yok').
 * Sıra ekranla aynı (yükleme tarihi, boşlar sonda, id). En çok SIPARIS_YAZDIR_LIMIT satır (LIMIT +1 ile `kesildi`).
 * Şoför kolonları YALNIZ siparis_sofor_hazir() ise SELECT'e girer (kolon yokken SQL hatası olmasın).
 * @return array{rows:list<array<string,mixed>>,kesildi:bool,kalem:array<int,list<array<string,mixed>>>,parti:array<int,array<string,mixed>>,sofor:bool,kapsam:string}
 */
function siparis_yazdir_oku(PDO $pdo, array $F): array
{
    $tek = !empty($F['tek']);
    if (!$tek && empty($F['bolumAcik'])) throw new RuntimeException('kapsam_yok');
    $sofor = siparis_sofor_hazir($pdo);
    $cols = 's.id, s.order_ref, s.tur_no, s.navlun_no, s.alici_kodu, s.alici_adi, s.yukleme_tarihi, s.plakalar, s.nakliyeci, s.toplam_palet, s.toplam_koli'
          . ($sofor ? ', s.sofor_adi, s.sofor_telefon' : '');
    $w = (string)$F['temelWhere'] . ' AND ' . ($tek ? (string)$F['tekSql'] : (string)$F['acikSql']);
    $st = $pdo->prepare('SELECT ' . $cols . ' FROM siparis_sevkiyatlar s WHERE ' . $w . ' ORDER BY s.yukleme_tarihi IS NULL, s.yukleme_tarihi, s.id LIMIT ' . (SIPARIS_YAZDIR_LIMIT + 1));
    $st->execute((array)$F['temelPar']);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $kesildi = count($rows) > SIPARIS_YAZDIR_LIMIT;
    if ($kesildi) $rows = array_slice($rows, 0, SIPARIS_YAZDIR_LIMIT);

    $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
    $kalem = [];
    if ($ids) {
        $kq = $pdo->prepare('SELECT sevkiyat_id, kalibre, sira, urun, aciklama, cesit, koli_icerik, pf, po_palet, etkin_palet, etkin_koli FROM siparis_kalemler
            WHERE sevkiyat_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY sevkiyat_id, sira, (kalibre + 0), kalibre, id');
        $kq->execute($ids);
        foreach ($kq->fetchAll(PDO::FETCH_ASSOC) as $k) $kalem[(int)$k['sevkiyat_id']][] = $k;
    }
    return ['rows' => $rows, 'kesildi' => $kesildi, 'kalem' => $kalem, 'parti' => siparis_beyan_parti_haritasi($pdo, $ids), 'sofor' => $sofor, 'kapsam' => $tek ? 'tek' : 'acik'];
}

/**
 * Saf: okunan veriyi yazdırma JSON sözleşmesine çevirir (DB/oturum yok).
 * $ctx: bugun (Y-m-d), basim (d.m.Y H:i), depo, baslik, filtre_ozet, parti_yetki (bool).
 * Sarı zemin: yukleme_tarihi < bugün → gecmis + tarihe " ✓"; bugün → bugun (kalın); gelecek / boş → düz.
 * Boş hücre BOŞ döner (tire yok). Plaka / lojistik firma YALNIZ gerçek (s.plakalar / s.nakliyeci); planlanan_* basılmaz.
 * @return array<string,mixed>
 */
function siparis_yazdir_veri(array $okunan, array $ctx): array
{
    $bugun = (string)($ctx['bugun'] ?? date('Y-m-d'));
    $basim = (string)($ctx['basim'] ?? date('d.m.Y H:i'));
    $tek = ($okunan['kapsam'] ?? 'acik') === 'tek';
    $satirlar = []; $gecmisSay = 0; $topPalet = null; $topKasa = null;
    foreach ($okunan['rows'] as $r) {
        $sid = (int)$r['id'];
        $gun = ($r['yukleme_tarihi'] ?? null) !== null ? siparis_tarih_coz((string)$r['yukleme_tarihi']) : null;
        $gecmis = $gun !== null && $gun < $bugun;
        if ($gecmis) $gecmisSay++;
        $tarih = $gun !== null ? sipui_tarih($gun) . ($gecmis ? ' ✓' : '') : '';
        $kalemler = (array)($okunan['kalem'][$sid] ?? []);
        $urun = [];
        foreach (sipui_kalem_satirlari($kalemler, 1000)['satirlar'] as $s) $urun[] = $s['ad'] . ($s['meta'] !== '' ? ($s['ad'] !== '' ? ' · ' : '') . $s['meta'] : '');
        $parti = implode('/', array_map('strval', (array)($okunan['parti'][$sid]['liste'] ?? [])));
        $palet = ($r['toplam_palet'] ?? null) !== null && $r['toplam_palet'] !== '' ? (int)$r['toplam_palet'] : null;
        $kasa = ($r['toplam_koli'] ?? null) !== null && $r['toplam_koli'] !== '' ? (int)$r['toplam_koli'] : null;
        if ($palet !== null) $topPalet = ($topPalet ?? 0) + $palet;
        if ($kasa !== null) $topKasa = ($topKasa ?? 0) + $kasa;
        $satirlar[] = [
            'h' => [
                $tarih,
                $parti,
                trim((string)($r['navlun_no'] ?? '')),
                trim((string)($r['order_ref'] ?? '')),
                !empty($okunan['sofor']) ? trim((string)($r['sofor_adi'] ?? '')) : '',
                !empty($okunan['sofor']) ? trim((string)($r['sofor_telefon'] ?? '')) : '',
                trim((string)($r['plakalar'] ?? '')),
                trim((string)($r['nakliyeci'] ?? '')),
                siparis_yazdir_ulke($r['alici_kodu'] ?? null, $r['alici_adi'] ?? null),
                implode("\n", $urun),
                $palet !== null ? sipui_sayi($palet) : '',
                $kasa !== null ? sipui_sayi($kasa) : '',
                siparis_yazdir_gramaj($kalemler),
            ],
            'gecmis' => $gecmis,
            'bugun' => $gun !== null && $gun === $bugun,
            'gun' => $gun,
        ];
    }
    $notlar = [];
    if (!empty($okunan['kesildi'])) $notlar[] = 'İlk ' . SIPARIS_YAZDIR_LIMIT . ' sevkiyat basıldı — filtreyi daraltın.';
    if (empty($ctx['parti_yetki'])) $notlar[] = 'Parti no için beyan okuma yetkisi gerekir.';
    if (empty($okunan['sofor'])) $notlar[] = 'Şoför alanları kurulmadı.';
    $notlar[] = 'Kişisel veri içerir (şoför adı/telefonu) — yalnız ilgili kişilerle paylaşın.';
    $baslik = (string)($ctx['baslik'] ?? ($tek ? 'Sevkiyatlar' : 'Açık Sevkiyatlar'));
    $tarihEk = substr($basim, 0, 10);
    return [
        'ok' => true,
        'baslik' => $baslik,
        'dosya' => ($tek ? 'Sevkiyatlar-' : 'Acik-Sevkiyatlar-') . $tarihEk,
        'basim' => $basim,
        'depo' => (string)($ctx['depo'] ?? ''),
        'filtre' => (string)($ctx['filtre_ozet'] ?? ''),
        'adet' => count($satirlar),
        'gecmis_say' => $gecmisSay,
        'kesildi' => !empty($okunan['kesildi']),
        'limit' => SIPARIS_YAZDIR_LIMIT,
        'notlar' => $notlar,
        'kolonlar' => siparis_yazdir_kolonlari(),
        'satirlar' => $satirlar,
        'toplam' => ['palet' => $topPalet !== null ? sipui_sayi($topPalet) : '', 'kasa' => $topKasa !== null ? sipui_sayi($topKasa) : ''],
    ];
}
