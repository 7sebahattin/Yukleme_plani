<?php
// =========================================================
// config/siparis_yazdir.php — Sevkiyat YAZDIRMA / PDF / WP resmi verisi (v382, v403: üç bölüm + kalibre sütunları; include-only)
//
// siparis_yazdir.php (GET JSON ucu) bunu çağırır; çıktı/exit/yazma YOK (audit uçta). _siparis_ortak.php YÜKLÜ varsayılır
// (sipui_kalem_coz / sipui_kalem_satirlari / sipui_tarih / sipui_sayi). Filtre, ekranla AYNI fonksiyondan: siparis_liste_filtre().
// Tüm hücreler HAM METİN döner (HTML yok); kaçış istemcide (assets/siparis_yazdir.js). Sütun sırası/başlıkları sahibindir.
// YENİ SÜTUN = yalnız siparis_yazdir_veri() (ve siparis_yazdir_kolonlari()); ülke = siparis_yazdir_ulke_haritasi().
// v403: bölüm = acik|evrak|kapali (siparis_yazdir_bolumler()); kalibre sütunları siparis_yazdir_kalibreler() (14/12/10 her zaman).
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
 * v403 — kalibre sütun anahtarı: 'kal_' + yalnız [0-9A-Za-z] (istemci anahtarı CSS/JS'te güvenle kullanır).
 * Temizlenince boş kalan kalibre (ör. "-") için kısa özet eklenir; çakışma siparis_yazdir_kalibreler()'de çözülür.
 */
function siparis_yazdir_kalibre_anahtar(string $kalibre): string
{
    $t = (string)preg_replace('/[^0-9A-Za-z]/', '', $kalibre);
    return 'kal_' . ($t !== '' ? $t : 'x' . substr(md5($kalibre), 0, 8));
}

/**
 * v403 — basılacak kalibre sütunları: HER ZAMAN 14, 12, 10 + kalemlerde (sipui_kalem_coz() null olmayan) geçen diğer boş olmayan
 * kalibreler. Sıra: sayısal AZALAN, sayısal olmayanlar sonda doğal sıra. Süzgeç TEK yer: sipui_kalem_coz().
 * @param array<int,list<array<string,mixed>>> $kalemHarita sevkiyat id → kalemler
 * @return array<string,string> kalibre (ham, trim) → sütun anahtarı
 */
function siparis_yazdir_kalibreler(array $kalemHarita): array
{
    $set = ['14' => true, '12' => true, '10' => true];
    foreach ($kalemHarita as $kalemler) {
        foreach ((array)$kalemler as $k) {
            $c = sipui_kalem_coz($k);
            if ($c === null || $c['kalibre'] === '') continue;
            $set[$c['kalibre']] = true;
        }
    }
    $liste = array_map('strval', array_keys($set));
    usort($liste, static function (string $a, string $b): int {
        $na = is_numeric($a); $nb = is_numeric($b);
        if ($na && $nb) return (float)$b <=> (float)$a ?: strnatcmp($a, $b);
        if ($na !== $nb) return $na ? -1 : 1;
        return strnatcmp($a, $b);
    });
    $out = []; $kullanilan = [];
    foreach ($liste as $kal) {
        $a = siparis_yazdir_kalibre_anahtar($kal); $temel = $a; $i = 2;
        while (isset($kullanilan[$a])) $a = $temel . '_' . $i++;
        $kullanilan[$a] = true;
        $out[$kal] = $a;
    }
    return $out;
}

/**
 * Sütun sözleşmesi (v403; sıra sahibinindir, DEĞİŞTİRME). k = anahtar, l = başlık, t = tür (metin|sayi), w = genişlik % (toplam 100).
 * Kalibre sütunları Ürün bilgileri ile Toplam palet ARASINDA; genişlikleri sütun sayısına göre bölünür, kalan Ürün'e gider.
 * @param array<string,string> $kalibreler siparis_yazdir_kalibreler() sonucu
 * @return list<array{k:string,l:string,t:string,w:float}>
 */
function siparis_yazdir_kolonlari(array $kalibreler = ['14' => 'kal_14', '12' => 'kal_12', '10' => 'kal_10']): array
{
    $n = count($kalibreler);
    $kalW = $n > 0 ? max(3.0, min(5.5, 24.0 / $n)) : 0.0;     // sabit sütunlar 66; Ürün en az 10
    $urunW = max(10.0, 100 - 66 - $kalW * $n);
    $bas = [
        ['k' => 'tarih',   'l' => 'Yükleme tarihi', 't' => 'metin', 'w' => 7.0],
        ['k' => 'parti',   'l' => 'Parti no',       't' => 'metin', 'w' => 7.0],
        ['k' => 'siparis', 'l' => 'Sipariş no',     't' => 'metin', 'w' => 8.0],
        ['k' => 'ulke',    'l' => 'Alıcı ülke',     't' => 'metin', 'w' => 7.0],
        ['k' => 'tel',     'l' => 'Şoför Tel',      't' => 'metin', 'w' => 9.0],
        ['k' => 'plaka',   'l' => 'Plaka',          't' => 'metin', 'w' => 9.0],
        ['k' => 'gumruk',  'l' => 'Gümrük',         't' => 'metin', 'w' => 9.0],
        ['k' => 'urun',    'l' => 'Ürün bilgileri', 't' => 'metin', 'w' => $urunW],
    ];
    foreach ($kalibreler as $kal => $a) $bas[] = ['k' => $a, 'l' => 'Kalibre ' . $kal . ' adet', 't' => 'sayi', 'w' => $kalW];
    $bas[] = ['k' => 'palet', 'l' => 'Toplam palet', 't' => 'sayi', 'w' => 5.0];
    $bas[] = ['k' => 'kasa',  'l' => 'Toplam kasa',  't' => 'sayi', 'w' => 5.0];
    $top = array_sum(array_column($bas, 'w'));               // çok kalibrede 100'ü aşar → orantılı küçült (toplam 100)
    foreach ($bas as &$c) $c['w'] = round($c['w'] * 100 / $top, 2);
    unset($c);
    return $bas;
}

/** Başlık: tek durum filtresi → "Sevkiyatlar — <durum etiketi / Araç bekleyen>"; değilse bölüm adı (v403). */
function siparis_yazdir_baslik(array $F, string $kapsam = 'acik'): string
{
    if (!empty($F['tek'])) {
        $d = (string)($F['f_durum'] ?? '');
        $et = $d === 'arac_bekleyen' ? 'Araç bekleyen' : (string)(siparis_durumlar()[$d]['etiket'] ?? $d);
        return 'Sevkiyatlar — ' . $et;
    }
    return siparis_yazdir_bolumler()[$kapsam]['baslik'] ?? 'İşlem Bekleyen Sevkiyatlar';
}

/**
 * v403 — yazdırılabilir bölümler: başlık, dosya öneki, F bayrağı, F SQL anahtarı, sıra (ekrandaki ile aynı).
 * @return array<string,array{baslik:string,dosya:string,bayrak:string,sql:string,sira:string}>
 */
function siparis_yazdir_bolumler(): array
{
    $artan = 's.yukleme_tarihi IS NULL, s.yukleme_tarihi, s.id';
    return [
        'acik'   => ['baslik' => 'İşlem Bekleyen Sevkiyatlar',        'dosya' => 'Islem-Bekleyen-Sevkiyatlar-', 'bayrak' => 'bolumAcik',   'sql' => 'acikSql',   'sira' => $artan],
        'evrak'  => ['baslik' => 'Evrak Takımı Bekleyen Sevkiyatlar', 'dosya' => 'Evrak-Bekleyen-Sevkiyatlar-', 'bayrak' => 'bolumEvrak',  'sql' => 'evrakSql',  'sira' => $artan],
        'kapali' => ['baslik' => 'Kapanan Sevkiyatlar',               'dosya' => 'Kapanan-Sevkiyatlar-',        'bayrak' => 'bolumKapali', 'sql' => 'kapaliSql', 'sira' => 's.yukleme_tarihi DESC, s.id DESC'],
    ];
}

/**
 * Yazdırılacak sevkiyatlar + kalemleri + parti no'ları (SALT OKUNUR). $F = siparis_liste_filtre() sonucu.
 * v403 kapsam: tek durum filtresi (`tek`) varsa $bolum yok sayılır → 'tek' (tekSql). Değilse $bolum = acik|evrak|kapali
 * (bilinmeyen → acik) ilgili bölümün SQL'iyle; bölüm bu filtreyle görünmüyorsa RuntimeException('kapsam_yok').
 * Sıra ekranla aynı (acik/evrak/tek: tarih artan, boşlar sonda; kapali: tarih azalan). En çok SIPARIS_YAZDIR_LIMIT satır (LIMIT +1 → `kesildi`).
 * Şoför / yurt içi gümrük kolonları YALNIZ *_hazir() ise SELECT'e girer (kolon yokken SQL hatası olmasın).
 * @return array{rows:list<array<string,mixed>>,kesildi:bool,kalem:array<int,list<array<string,mixed>>>,parti:array<int,array<string,mixed>>,sofor:bool,gumruk:bool,kapsam:string}
 */
function siparis_yazdir_oku(PDO $pdo, array $F, string $bolum = 'acik'): array
{
    $tek = !empty($F['tek']);
    $bolumler = siparis_yazdir_bolumler();
    if (!isset($bolumler[$bolum])) $bolum = 'acik';
    if ($tek) { $kapsam = 'tek'; $ekSql = (string)$F['tekSql']; $sira = $bolumler['acik']['sira']; }
    else {
        $b = $bolumler[$bolum];
        if (empty($F[$b['bayrak']])) throw new RuntimeException('kapsam_yok');
        $kapsam = $bolum; $ekSql = (string)$F[$b['sql']]; $sira = $b['sira'];
    }
    $sofor = siparis_sofor_hazir($pdo);
    $gumruk = siparis_yurtici_gumruk_hazir($pdo);
    $cols = 's.id, s.order_ref, s.alici_kodu, s.alici_adi, s.yukleme_tarihi, s.plakalar, s.toplam_palet, s.toplam_koli'
          . ($sofor ? ', s.sofor_telefon' : '')
          . ($gumruk ? ', s.yurtici_gumruk' : '');
    $w = (string)$F['temelWhere'] . ' AND ' . $ekSql;
    $st = $pdo->prepare('SELECT ' . $cols . ' FROM siparis_sevkiyatlar s WHERE ' . $w . ' ORDER BY ' . $sira . ' LIMIT ' . (SIPARIS_YAZDIR_LIMIT + 1));
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
    return ['rows' => $rows, 'kesildi' => $kesildi, 'kalem' => $kalem, 'parti' => siparis_beyan_parti_haritasi($pdo, $ids), 'sofor' => $sofor, 'gumruk' => $gumruk, 'kapsam' => $kapsam];
}

/**
 * v403 — "Ürün bilgileri" hücresi: süzgeçten (sipui_kalem_coz) geçen kalemlerin ürün adları TEKİL (önce `urun`, boşsa `aciklama`;
 * büyük/küçük harf duyarsız), ilk görülen yazım korunur, ", " ile TEK satır.
 * @param list<array<string,mixed>> $kalemler
 */
function siparis_yazdir_urunler(array $kalemler): string
{
    $gor = []; $out = [];
    foreach ($kalemler as $k) {
        if (sipui_kalem_coz($k) === null) continue;
        $ad = trim((string)($k['urun'] ?? ''));
        if ($ad === '') $ad = trim((string)($k['aciklama'] ?? ''));
        if ($ad === '') continue;
        $a = mb_strtolower($ad, 'UTF-8');
        if (isset($gor[$a])) continue;
        $gor[$a] = true; $out[] = $ad;
    }
    return implode(', ', $out);
}

/**
 * v403 — kalibre başına palet toplamı (süzgeçten geçen kalemler; palet = sipui_kalem_coz()['palet']). Kalibrede kalem yoksa ya da
 * hiçbir kalemin paleti sayısal değilse o kalibre haritada YOK (hücre "-").
 * @param list<array<string,mixed>> $kalemler
 * @return array<string,float> kalibre → palet toplamı
 */
function siparis_yazdir_kalibre_palet(array $kalemler): array
{
    $t = [];
    foreach ($kalemler as $k) {
        $c = sipui_kalem_coz($k);
        if ($c === null || $c['kalibre'] === '' || $c['palet'] === null || !is_numeric($c['palet'])) continue;
        $t[$c['kalibre']] = ($t[$c['kalibre']] ?? 0) + (float)$c['palet'];
    }
    return $t;
}

/**
 * Saf: okunan veriyi yazdırma JSON sözleşmesine çevirir (DB/oturum yok).
 * $ctx: bugun (Y-m-d), basim (d.m.Y H:i), depo, baslik, filtre_ozet, parti_yetki (bool).
 * Sarı zemin + " ✓" YALNIZ acik/tek kapsamında (yukleme_tarihi < bugün); evrak/kapali'de gecmis hep false. bugun → kalın.
 * Boş hücre BOŞ döner; kalibre hücresinde değer yoksa "-". Plaka YALNIZ gerçek (s.plakalar). Gümrük = YALNIZ yurt içi gümrük kolonu (PO gümrüğü yurt dışıdır).
 * @return array<string,mixed>
 */
function siparis_yazdir_veri(array $okunan, array $ctx): array
{
    $bugun = (string)($ctx['bugun'] ?? date('Y-m-d'));
    $basim = (string)($ctx['basim'] ?? date('d.m.Y H:i'));
    $kapsam = (string)($okunan['kapsam'] ?? 'acik');
    $tek = $kapsam === 'tek';
    $isaretle = $tek || $kapsam === 'acik';
    $kalibreler = siparis_yazdir_kalibreler((array)($okunan['kalem'] ?? []));
    $satirlar = []; $gecmisSay = 0; $topPalet = null; $topKasa = null; $topKal = [];
    foreach ($okunan['rows'] as $r) {
        $sid = (int)$r['id'];
        $gun = ($r['yukleme_tarihi'] ?? null) !== null ? siparis_tarih_coz((string)$r['yukleme_tarihi']) : null;
        $gecmis = $isaretle && $gun !== null && $gun < $bugun;
        if ($gecmis) $gecmisSay++;
        $tarih = $gun !== null ? sipui_tarih($gun) . ($gecmis ? ' ✓' : '') : '';
        $kalemler = (array)($okunan['kalem'][$sid] ?? []);
        $parti = implode('/', array_map('strval', (array)($okunan['parti'][$sid]['liste'] ?? [])));
        $palet = ($r['toplam_palet'] ?? null) !== null && $r['toplam_palet'] !== '' ? (int)$r['toplam_palet'] : null;
        $kasa = ($r['toplam_koli'] ?? null) !== null && $r['toplam_koli'] !== '' ? (int)$r['toplam_koli'] : null;
        if ($palet !== null) $topPalet = ($topPalet ?? 0) + $palet;
        if ($kasa !== null) $topKasa = ($topKasa ?? 0) + $kasa;
        $kp = siparis_yazdir_kalibre_palet($kalemler);
        $h = [
            $tarih,
            $parti,
            trim((string)($r['order_ref'] ?? '')),
            siparis_yazdir_ulke($r['alici_kodu'] ?? null, $r['alici_adi'] ?? null),
            !empty($okunan['sofor']) ? trim((string)($r['sofor_telefon'] ?? '')) : '',
            trim((string)($r['plakalar'] ?? '')),
            !empty($okunan['gumruk']) ? trim((string)($r['yurtici_gumruk'] ?? '')) : '',
            siparis_yazdir_urunler($kalemler),
        ];
        foreach ($kalibreler as $kal => $a) {
            if (isset($kp[$kal])) { $h[] = sipui_sayi($kp[$kal]); $topKal[$a] = ($topKal[$a] ?? 0) + $kp[$kal]; }
            else $h[] = '-';
        }
        $h[] = $palet !== null ? sipui_sayi($palet) : '';
        $h[] = $kasa !== null ? sipui_sayi($kasa) : '';
        $satirlar[] = ['h' => $h, 'gecmis' => $gecmis, 'bugun' => $gun !== null && $gun === $bugun, 'gun' => $gun];
    }
    $notlar = [];
    if (!empty($okunan['kesildi'])) $notlar[] = 'İlk ' . SIPARIS_YAZDIR_LIMIT . ' sevkiyat basıldı — filtreyi daraltın.';
    if (empty($ctx['parti_yetki'])) $notlar[] = 'Parti no için beyan okuma yetkisi gerekir.';
    if (empty($okunan['sofor'])) $notlar[] = 'Şoför alanları kurulmadı.';
    if (empty($okunan['gumruk'])) $notlar[] = 'Gümrük alanı kurulmadı.';
    $bolumler = siparis_yazdir_bolumler();
    $baslik = (string)($ctx['baslik'] ?? ($tek ? 'Sevkiyatlar' : ($bolumler[$kapsam]['baslik'] ?? 'İşlem Bekleyen Sevkiyatlar')));
    $tarihEk = substr($basim, 0, 10);
    $toplam = ['palet' => $topPalet !== null ? sipui_sayi($topPalet) : '', 'kasa' => $topKasa !== null ? sipui_sayi($topKasa) : ''];
    foreach ($kalibreler as $a) $toplam[$a] = isset($topKal[$a]) ? sipui_sayi($topKal[$a]) : '';
    return [
        'ok' => true,
        'kapsam' => $kapsam,
        'baslik' => $baslik,
        'dosya' => ($tek ? 'Sevkiyatlar-' : ($bolumler[$kapsam]['dosya'] ?? 'Islem-Bekleyen-Sevkiyatlar-')) . $tarihEk,
        'basim' => $basim,
        'depo' => (string)($ctx['depo'] ?? ''),
        'filtre' => (string)($ctx['filtre_ozet'] ?? ''),
        'adet' => count($satirlar),
        'gecmis_say' => $gecmisSay,
        'kesildi' => !empty($okunan['kesildi']),
        'limit' => SIPARIS_YAZDIR_LIMIT,
        'notlar' => $notlar,
        'kolonlar' => siparis_yazdir_kolonlari($kalibreler),
        'satirlar' => $satirlar,
        'toplam' => $toplam,
    ];
}
