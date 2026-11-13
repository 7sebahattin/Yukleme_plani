<?php
// =========================================================
// config/stok_ui.php — Stok (muhasebe) render yardımcıları (include-only; yalnız fonksiyon bildirir)
//
// Sayfalar (stok_kartlari / stok_tanimlari; Parça B: 4 fiş sayfası) veriyi config/stok_core.php'den okur,
// işaretlemeyi BURADAN alır. Sınıf öneki `st-`, kök `.stok`, ID/data-* kancaları `st*` (assets/stok.js sözleşmesi).
// Burada SORGU YAZILMAZ (yalnız stok_core okuyucuları), hiçbir yazma yoktur. HTML'e giren HER dinamik değer h() ile kaçışlanır.
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

// ── Varlıklar / ortak parçalar ──────────────────────────────────────────────

function stok_assets(): void
{
    echo '<link rel="stylesheet" href="assets/stok.css?v=' . (int)@filemtime(dirname(__DIR__) . '/assets/stok.css') . '">' . "\n";
}

function stok_scripts(): void
{
    echo '<script src="assets/stok.js?v=' . (int)@filemtime(dirname(__DIR__) . '/assets/stok.js') . '"></script>' . "\n";
}

/** Üst sekme şeridi. $aktif: kartlar|giris|cikis|transfer|donusum|tanimlar */
function stok_ust_menu(string $aktif): string
{
    $s = ['kartlar' => ['stok_kartlari.php', 'Stok Kartları'], 'giris' => ['stok_giris_fisleri.php', 'Giriş Fişi'], 'cikis' => ['stok_cikis_fisleri.php', 'Çıkış Fişi'],
        'transfer' => ['transfer_fisleri.php', 'Transfer'], 'donusum' => ['stok_donusum_fisleri.php', 'Dönüşüm'], 'tanimlar' => ['stok_tanimlari.php', 'Tanımlar']];
    $o = '<nav class="st-sekmeler" aria-label="Stok">';
    foreach ($s as $k => [$url, $ad]) $o .= '<a class="st-sekme" href="' . h($url) . '"' . ($k === $aktif ? ' aria-current="page"' : '') . '>' . h($ad) . '</a>';
    return $o . '<a class="st-sekme st-sekme--dis" href="kantar.php">Kantar (Mal Kabul)</a></nav>';
}

/** Şema yok kartı (sekme şeridi YOK). */
function stok_sema_uyari(): string
{
    return '<div class="stok"><div class="st-uyari" role="alert"><span class="st-uyari-ikon" aria-hidden="true">&#9888;</span><div>'
        . '<h2>Stok tabloları kurulmamış</h2>'
        . '<p>Stok modülünün veritabanı tabloları bu sunucuda henüz oluşturulmadı. Yönetici: Şema Migrasyon sayfasındaki <b>Stok (muhasebe)</b> kartını çalıştırın.</p>'
        . '<a class="btn btn-primary" href="migrate.php#stok">Şema Migrasyon &rsaquo; Stok</a></div></div></div>';
}

function stok_bos(string $baslik, string $ack, string $eylem = '', string $etiket = 'h2'): string
{
    return '<div class="st-bos"><' . $etiket . '>' . h($baslik) . '</' . $etiket . '><p>' . h($ack) . '</p>'
        . ($eylem !== '' ? '<div class="st-bos-eylem">' . $eylem . '</div>' : '') . '</div>';
}

/** "Bu defter bağımsızdır" bilgi satırı (iki stok doğrusu riski: plan §8-1). */
function stok_bagimsiz_not(): string
{
    return '<p class="st-not">Bu defter Ürün Stoğu (stok.php) ve Malzeme Stok ekranlarından bağımsızdır.</p>';
}

function stok_json_attr(mixed $v): string
{
    return h((string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** <script type=application/json> içine güvenli JSON (</script> kaçışı). */
function stok_json_script(string $id, mixed $v): string
{
    return '<script type="application/json" id="' . h($id) . '">' . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
}

function stok_tarih_tr(string $d): string
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) ? $m[3] . '.' . $m[2] . '.' . $m[1] : $d;
}

/** TR doğru küçük harf (İ/I). */
function stok_kucuk(string $s): string { return mb_strtolower(strtr($s, ['İ' => 'i', 'I' => 'ı']), 'UTF-8'); }

/** "18.450 kg" */
function stok_miktar_birim(int $milli, string $birim): string { return stok_miktar_bicim($milli, $birim) . ' ' . $birim; }

/** Kuruş/birim (float) → "14,20 ₺/kg" */
function stok_ort_bicim(float $ortKurus, string $birim): string
{
    return cari_tutar_bicim((int)round($ortKurus), 'TRY') . '/' . $birim;
}

/** Fiş türü rozeti (renk + işaret + metin). */
function stok_tur_rozet(string $tur, string $neden = '', bool $ters = false): string
{
    if ($ters) return '<span class="st-rozet st-rozet--iptal">&#8634; İptal (ters)</span>';
    $fire = $tur === 'cikis' && cari_fold($neden) === cari_fold('Fire');
    return match ($tur) {
        'giris'    => '<span class="st-rozet st-rozet--giris"><span class="st-yon" aria-hidden="true">&#9650;</span> ' . h($neden !== '' ? $neden : 'Giriş') . '</span>',
        'cikis'    => '<span class="st-rozet ' . ($fire ? 'st-rozet--fire' : 'st-rozet--cikis') . '"><span class="st-yon" aria-hidden="true">&#9660;</span> ' . h($neden !== '' ? $neden : 'Çıkış') . '</span>',
        'transfer' => '<span class="st-rozet st-rozet--transfer"><span class="st-yon" aria-hidden="true">&#8644;</span> Transfer</span>',
        'donusum'  => '<span class="st-rozet st-rozet--donusum"><span class="st-yon" aria-hidden="true">&#9881;</span> Dönüşüm</span>',
        default    => '<span class="st-rozet">' . h($tur) . '</span>',
    };
}

// ── Kartlar: sol liste ──────────────────────────────────────────────────────

/** Liste süzgeci (sunucuda `hidden` olarak uygulanır; JS aynı kuralla yeniden süzer). */
function stok_kart_eslesir(array $k, array $F): bool
{
    $pasif = (int)$k['aktif'] !== 1;
    $d = $F['durum'];
    if ($d === 'aktif' && $pasif) return false;
    if ($d === 'pasif' && !$pasif) return false;
    if ($d === 'kritik' && ($pasif || empty($k['kritik']))) return false;
    if ($F['tip'] !== '' && (string)$k['tip'] !== $F['tip']) return false;
    if ($F['grup'] > 0 && (int)($k['grup_id'] ?? 0) !== $F['grup']) return false;
    if ($F['ara'] !== '' && !str_contains(stok_kucuk((string)$k['kod'] . ' ' . $k['ad'] . ' ' . ($k['barkod'] ?? '') . ' ' . ($k['grup_adi'] ?? '')), stok_kucuk($F['ara']))) return false;
    return true;
}

function stok_kart_satiri(array $k, bool $secili, bool $gizli): string
{
    $tipler = stok_kart_tipleri();
    $pasif = (int)$k['aktif'] !== 1;
    $kritik = !$pasif && !empty($k['kritik']);
    $grup = (string)($k['grup_adi'] ?? '');
    $ara = stok_kucuk(implode(' ', array_filter([(string)$k['kod'], (string)$k['ad'], (string)($k['barkod'] ?? ''), $grup], static fn($x) => $x !== '')));
    $alt = implode(' · ', array_filter([(string)$k['kod'], $tipler[$k['tip']] ?? '', $grup], static fn($x) => $x !== ''));
    $sag = '<span class="st-miktar' . ((int)$k['bakiye_milli'] < 0 ? ' st-miktar--eksi' : '') . '">' . h(stok_miktar_birim((int)$k['bakiye_milli'], (string)$k['ana_birim'])) . '</span>'
        . ($kritik ? '<span class="st-rozet st-rozet--kritik">Kritik</span>' : '')
        . ($pasif ? '<span class="st-rozet st-rozet--pasif">Pasif</span>' : '');
    return '<li' . ($gizli ? ' hidden' : '') . '><a class="st-satir' . ($pasif ? ' st-satir--pasif' : '') . '" href="stok_kartlari.php?id=' . (int)$k['id'] . '" data-id="' . (int)$k['id'] . '"'
        . ' data-ara="' . h($ara) . '" data-tip="' . h($k['tip']) . '" data-grup="' . (int)($k['grup_id'] ?? 0) . '" data-pasif="' . ($pasif ? '1' : '0') . '" data-kritik="' . ($kritik ? '1' : '0') . '"'
        . ($secili ? ' aria-current="true"' : '') . '>'
        . '<span class="st-satir-ust">' . h($k['ad']) . '</span><span class="st-satir-alt">' . h($alt) . '</span><span class="st-satir-sag">' . $sag . '</span></a></li>';
}

/**
 * Sol sütun: arama + çipler + tip/grup + özet + liste.
 * @param list<array> $kartlar stok_kart_listesi satırları (tümü)  @param list<array> $gruplar aktif grup tanımları
 * @param array{ara:string,tip:string,grup:int,durum:string} $F
 */
function stok_liste_kol_html(array $kartlar, array $gruplar, array $F, ?int $seciliId, bool $kesildi): string
{
    $say = ['aktif' => 0, 'pasif' => 0, 'tumu' => count($kartlar), 'kritik' => 0];
    foreach ($kartlar as $k) {
        if ((int)$k['aktif'] !== 1) { $say['pasif']++; continue; }
        $say['aktif']++;
        if (!empty($k['kritik'])) $say['kritik']++;
    }
    $cip = '';
    foreach (['aktif' => 'Aktif', 'pasif' => 'Pasif', 'tumu' => 'Tümü', 'kritik' => 'Kritik altı'] as $d => $ad) {
        $cip .= '<button type="button" class="st-cip' . ($d === 'kritik' ? ' st-cip--kritik' : '') . '" data-filtre="durum" data-deger="' . $d . '" aria-pressed="' . ($F['durum'] === $d ? 'true' : 'false') . '">'
            . h($ad) . ' <span class="st-cip-say">' . $say[$d] . '</span></button>';
    }
    $tipOpt = '<option value="">Tüm tipler</option>';
    foreach (stok_kart_tipleri() as $v => $ad) $tipOpt .= '<option value="' . h($v) . '"' . ($F['tip'] === $v ? ' selected' : '') . '>' . h($ad) . '</option>';
    $grpOpt = '<option value="">Tüm gruplar</option>';
    foreach ($gruplar as $g) $grpOpt .= '<option value="' . (int)$g['id'] . '"' . ($F['grup'] === (int)$g['id'] ? ' selected' : '') . '>' . h($g['ad']) . '</option>';

    $liste = ''; $gorunur = 0;
    foreach ($kartlar as $k) {
        $es = stok_kart_eslesir($k, $F);
        if ($es) $gorunur++;
        $liste .= stok_kart_satiri($k, $seciliId !== null && (int)$k['id'] === $seciliId, !$es);
    }
    $bos = '';
    if (!$kartlar) {
        $bos = stok_bos('Henüz stok kartı yok', 'İlk kartı ekleyin ya da Tanımlar’daki ürün ve malzemeleri tek seferde içe aktarın.',
            '<button type="button" class="btn btn-primary" data-st-modal="kart">Yeni kart</button><a class="btn" href="stok_tanimlari.php?sekme=ice_aktar">Tanımlardan içe aktar</a>');
    }
    return '<div class="st-liste-kol" id="stListeKol"><div class="st-liste-ust">'
        . '<div class="st-arama-satir"><div class="st-arama"><svg class="st-arama-ikon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>'
        . '<label class="st-sr" for="stArama">Stok kartı ara: kod, ad, barkod</label>'
        . '<input type="search" id="stArama" placeholder="Ara: kod, ad, barkod" autocomplete="off" aria-controls="stListe" value="' . h($F['ara']) . '"><kbd class="st-arama-ipucu" aria-hidden="true">/</kbd></div>'
        . '<button type="button" class="btn btn-primary" data-st-modal="kart" title="Yeni stok kartı">+ Yeni</button></div>'
        . '<div class="st-cipler" role="group" aria-label="Kart durumu">' . $cip . '</div>'
        . '<div class="st-filtre-satir"><select data-filtre="tip" aria-label="Kart tipi">' . $tipOpt . '</select>'
        . '<select data-filtre="grup" aria-label="Stok grubu">' . $grpOpt . '</select></div></div>'
        . $bos
        . '<ul class="st-liste" id="stListe" aria-label="Stok kartları">' . $liste . '</ul>'
        . '<p class="st-liste-bos" id="stSonucYok"' . ($kartlar && $gorunur === 0 ? '' : ' hidden') . '>Eşleşen kart yok. <a href="stok_kartlari.php?durum=tumu">Filtreleri temizle</a></p>'
        . '<div class="st-liste-alt"><span id="stSayi">' . $gorunur . ' kart</span><span>' . ($kesildi ? 'İlk 500 kart gösteriliyor, aramayı daraltın' : 'Bakiye: aktif depo') . '</span></div></div>';
}

// ── Kartlar: detay ──────────────────────────────────────────────────────────

/** Düzenleme modalı için kart alanları (#stKartVeri). */
function stok_kart_json(array $k): array
{
    $tam = stok_birim_tam_mi((string)$k['ana_birim']);
    return [
        'id' => (int)$k['id'], 'kod' => (string)$k['kod'], 'ad' => (string)$k['ad'], 'tip' => (string)$k['tip'],
        'grup_id' => $k['grup_id'] !== null && $k['grup_id'] !== '' ? (int)$k['grup_id'] : null, 'ana_birim' => (string)$k['ana_birim'],
        'cevrim_birim' => $k['cevrim_birim'] !== null ? (string)$k['cevrim_birim'] : '',
        'cevrim_katsayi' => $k['cevrim_katsayi'] !== null ? stok_miktar_bicim(stok_milli($k['cevrim_katsayi']), 'lt') : '',
        'kdv_orani' => (int)$k['kdv_orani'], 'barkod' => (string)($k['barkod'] ?? ''),
        'kritik_seviye' => $k['kritik_seviye'] !== null && $k['kritik_seviye'] !== '' ? stok_miktar_bicim(stok_milli($k['kritik_seviye']), $tam ? 'kg' : 'lt') : '',
        'notlar' => (string)($k['notlar'] ?? ''), 'aktif' => (int)$k['aktif'],
    ];
}

/** Hareket tablosu (≥768) + kartları (<768) — aynı veriden iki kez; CSS biri dışında gizler. */
function stok_hareket_html(array $hareketler, string $birim): string
{
    if (!$hareketler) {
        return stok_bos('Henüz stok hareketi yok', 'Giriş fişiyle ilk hareketi oluşturun; bakiye ve ortalama maliyet fişlerden hesaplanır.',
            '<a class="btn" href="stok_giris_fisleri.php">Giriş fişleri</a>', 'h3');
    }
    $fs = stok_fis_turleri();
    $trs = ''; $kartlar = '';
    foreach ($hareketler as $r) {
        $iptal = $r['iptal'] || $r['ters'];
        $link = $r['fis_no'] !== '' && isset($fs[$r['fis_tur']])
            ? '<a href="' . h($fs[$r['fis_tur']]['sayfa'] . '?id=' . $r['fis_id']) . '">' . h($r['fis_no']) . '</a>' : h($r['fis_no'] !== '' ? $r['fis_no'] : '#' . $r['fis_id']);
        $roz = stok_tur_rozet($r['fis_tur'], $r['neden_ad'], $r['ters']) . ($r['iptal'] && !$r['ters'] ? ' <span class="st-rozet st-rozet--iptal">İPTAL</span>' : '');
        $m = (int)$r['miktar_milli'];
        $mik = '<span class="st-miktar ' . ($m >= 0 ? 'st-miktar--art' : 'st-miktar--eksi') . '">' . ($m >= 0 ? '+' : '−') . h(stok_miktar_bicim(abs($m), $birim)) . '</span>';
        $bak = h(stok_miktar_bicim((int)$r['yuruyen_milli'], $birim));
        $trs .= '<tr' . ($iptal ? ' class="st-iptal"' : '') . ' data-id="' . (int)$r['id'] . '"><td class="st-tar">' . h(stok_tarih_tr($r['tarih'])) . '</td><td class="st-fisno">' . $link . '</td><td>' . $roz . '</td>'
            . '<td class="st-depo" title="' . h($r['depo']) . '">' . h($r['depo'] !== '' ? $r['depo'] : '—') . '</td><td class="st-sayi">' . $mik . '</td><td class="st-sayi">' . $bak . '</td></tr>';
        $kartlar .= '<article class="st-hk' . ($iptal ? ' st-iptal' : '') . '"><div class="st-hk-ust"><span>' . $link . '</span><span class="st-hk-roz">' . $roz . '</span></div>'
            . '<div class="st-hk-alt"><span>' . h(stok_tarih_tr($r['tarih'])) . ' · ' . h($r['depo'] !== '' ? $r['depo'] : '—') . '</span><span>' . $mik . ' <small>Bakiye ' . $bak . '</small></span></div></article>';
    }
    return '<div class="st-hareket-kaydir table-wrap"><table class="data-table st-tablo"><caption class="st-sr">Stok hareketleri (en yeni önce)</caption>'
        . '<thead><tr><th scope="col">Tarih</th><th scope="col">Fiş no</th><th scope="col">Tür</th><th scope="col">Depo</th><th scope="col" class="st-sayi" title="Ana birim: ' . h($birim) . '">Miktar</th><th scope="col" class="st-sayi">Bakiye</th></tr></thead>'
        . '<tbody>' . $trs . '</tbody></table></div>'
        . '<div class="st-hareket-kartlar" aria-label="Stok hareketleri">' . $kartlar . '</div>';
}

/** Detay bölümü (#stDetay). */
function stok_detay_html(PDO $pdo, array $k): string
{
    $id = (int)$k['id'];
    $pasif = (int)$k['aktif'] !== 1;
    $birim = (string)$k['ana_birim'];
    $tipler = stok_kart_tipleri();
    $B = stok_bakiye($pdo, $id);
    $hareketSay = stok_kart_hareket_say($pdo, $id);
    $km = $k['kritik_seviye'] !== null && $k['kritik_seviye'] !== '' ? stok_milli($k['kritik_seviye']) : null;
    $kritik = $km !== null && $B['toplam_milli'] <= $km;

    $meta = '<span>' . h($k['kod']) . '</span><span class="st-rozet st-rozet--tip">' . h($tipler[$k['tip']] ?? $k['tip']) . '</span>'
        . (!empty($k['grup_adi']) ? '<span class="st-rozet">' . h($k['grup_adi']) . '</span>' : '')
        . '<span>Ana birim: ' . h($birim) . '</span>'
        . ($k['cevrim_birim'] !== null && $k['cevrim_birim'] !== '' ? '<span>1 ' . h($k['cevrim_birim']) . ' = ' . h(stok_miktar_bicim(stok_milli($k['cevrim_katsayi']), 'lt')) . ' ' . h($birim) . '</span>' : '')
        . '<span>KDV %' . (int)$k['kdv_orani'] . '</span>'
        . (!empty($k['barkod']) ? '<span>Barkod: ' . h($k['barkod']) . '</span>' : '')
        . ($km !== null ? '<span>Kritik seviye: ' . h(stok_miktar_birim($km, $birim)) . '</span>' : '');

    $csrf = h(csrf_token());
    $geri = 'stok_kartlari.php?id=' . $id;
    $islemler = '<div class="st-islemler" role="toolbar" aria-label="Kart işlemleri">'
        . '<button type="button" class="btn btn-primary" data-st-modal="kart" data-duzenle="1">Düzenle</button>'
        . '<form method="post" action="stok_kaydet.php" data-st-ajax data-ok="' . ($pasif ? 'Kart aktifleştirildi' : 'Kart pasifleştirildi') . '" class="st-islem-form">'
        . '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="islem" value="kart_durum"><input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="aktif" value="' . ($pasif ? '1' : '0') . '"><input type="hidden" name="geri" value="' . h($geri) . '">'
        . '<button type="submit" class="btn" data-st-islem="kart_durum">' . ($pasif ? 'Aktifleştir' : 'Pasifleştir') . '</button></form>'
        . ($hareketSay > 0
            ? '<button type="button" class="btn st-tehlike" disabled title="Hareketi olan kart silinemez; pasifleştirin">Sil</button>'
            : '<form method="post" action="stok_kaydet.php" data-st-ajax data-ok="Kart silindi" data-onay="Bu stok kartı kalıcı olarak silinecek. Emin misiniz?" class="st-islem-form">'
              . '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="islem" value="kart_sil"><input type="hidden" name="id" value="' . $id . '">'
              . '<input type="hidden" name="geri" value="stok_kartlari.php"><button type="submit" class="btn st-tehlike" data-st-islem="kart_sil">Sil</button></form>')
        . '</div>';
    $uyari = $pasif ? '<div class="flash flash-error st-pasif-uyari">Bu kart pasif: yeni fişte seçilemez. Mevcut hareketler korunur.</div>' : '';

    $kutu = '<div class="st-bk-kutu' . ($kritik ? ' st-bk-kutu--kritik' : '') . '"><div class="st-bk-ad">Toplam' . ($kritik ? ' · <span class="st-rozet st-rozet--kritik">Kritik</span>' : '') . '</div>'
        . '<div class="st-bk-deger" data-milli="' . (int)$B['toplam_milli'] . '">' . h(stok_miktar_birim((int)$B['toplam_milli'], $birim)) . '</div></div>';
    foreach ($B['depolar'] as $d) {
        $kutu .= '<div class="st-bk-kutu st-bk-kutu--depo"><div class="st-bk-ad">' . h($d['depo'] !== '' ? $d['depo'] : 'Depo atanmamış') . '</div><div class="st-bk-deger">' . h(stok_miktar_birim((int)$d['milli'], $birim)) . '</div></div>';
    }
    $kutu .= '<div class="st-bk-kutu st-bk-kutu--maliyet"><div class="st-bk-ad">Ort. maliyet</div><div class="st-bk-deger st-maliyet">' . h(stok_ort_bicim($B['ort'], $birim)) . '</div>'
        . '<div class="st-bk-alt">Değer <b>' . h(cari_tutar_bicim((int)$B['deger_kurus'], 'TRY')) . '</b> <span class="st-ipucu">KDV hariç</span></div></div>';

    $hareketler = stok_kart_hareketleri($pdo, $id, [], 200);
    return '<section class="st-detay" id="stDetay" data-id="' . $id . '" aria-label="Stok kartı detayı"><div class="st-detay-kart">'
        . '<a class="st-geri" href="stok_kartlari.php"><span aria-hidden="true">&#8592;</span> Liste</a>'
        . '<header class="st-baslik"><div class="st-baslik-satir"><h2 class="st-unvan">' . h($k['ad']) . '</h2><span class="st-rozet ' . ($pasif ? 'st-rozet--pasif' : 'st-rozet--aktif') . '">' . ($pasif ? 'Pasif' : 'Aktif') . '</span></div>'
        . '<div class="st-meta">' . $meta . '</div></header>'
        . $islemler . $uyari
        . '<div class="st-bakiyeler" role="group" aria-label="Bakiye ve maliyet">' . $kutu . '</div>'
        . stok_bagimsiz_not()
        . '<h3 class="st-bolum-baslik">Hareketler <small>' . (count($hareketler) >= 200 ? 'son 200' : count($hareketler) . ' satır') . '</small></h3>'
        . '<div class="st-hareket">' . stok_hareket_html($hareketler, $birim) . '</div>'
        . (!empty($k['notlar']) ? '<p class="st-notlar"><b>Notlar:</b> ' . nl2br(h($k['notlar'])) . '</p>' : '')
        . stok_json_script('stKartVeri', stok_kart_json($k) + ['hareketli' => $hareketSay > 0])
        . '</div></section>';
}

// ── Modal ───────────────────────────────────────────────────────────────────

/** Kart modalı (#stModalKart). @param array{geri:string,gruplar:list<array>,kod_oneri:string} $o */
function stok_modal_kart(array $o): string
{
    $tipOpt = ''; foreach (stok_kart_tipleri() as $v => $ad) $tipOpt .= '<option value="' . h($v) . '">' . h($ad) . '</option>';
    $grpOpt = '<option value="">Seçiniz</option>'; foreach ($o['gruplar'] as $g) $grpOpt .= '<option value="' . (int)$g['id'] . '">' . h($g['ad']) . '</option>';
    $brOpt = ''; foreach (stok_birimler() as $v => $ad) $brOpt .= '<option value="' . h($v) . '">' . h($ad) . '</option>';
    $cbOpt = '<option value="">— yok —</option>' . $brOpt;
    $kdvOpt = ''; foreach (stok_kdv_oranlari() as $kd) $kdvOpt .= '<option value="' . $kd . '"' . ($kd === 1 ? ' selected' : '') . '>%' . $kd . '</option>';
    ob_start(); ?>
<div class="pm-overlay" id="stModalKart" hidden>
  <div class="pm-dialog st-dialog st-dialog--genis st-dialog--uzun" role="dialog" aria-modal="true" aria-labelledby="stKartBaslik">
    <div class="pm-header"><span class="st-tutamac" aria-hidden="true"></span>
      <h2 class="pm-title" id="stKartBaslik">Yeni stok kartı</h2>
      <button type="button" class="pm-close" data-st-kapat aria-label="Kapat">&#10005;</button></div>
    <form method="post" action="stok_kaydet.php" data-st-ajax data-ok="Stok kartı kaydedildi" novalidate>
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="islem" value="kart_kaydet"><input type="hidden" name="id" value=""><input type="hidden" name="geri" value="<?= h($o['geri']) ?>">
      <div class="pm-body">
        <div class="flash flash-error st-form-hata" role="alert" hidden></div>
        <fieldset class="st-bolum"><legend>Temel</legend>
          <div class="st-form-grid st-form-grid--2">
            <label class="pm-label st-span2"><span>Ad <b class="st-zorunlu" aria-hidden="true">*</b></span>
              <input type="text" name="ad" maxlength="200" required autocomplete="off" data-ilk-odak></label>
            <label class="pm-label"><span>Stok kodu</span><input type="text" name="kod" maxlength="30" value="<?= h($o['kod_oneri']) ?>" data-oneri="<?= h($o['kod_oneri']) ?>" autocomplete="off" style="text-transform:uppercase">
              <span class="st-ipucu">Boş bırakılırsa otomatik verilir</span></label>
            <label class="pm-label"><span>Kart tipi</span><select name="tip"><?= $tipOpt ?></select></label>
            <label class="pm-label"><span>Stok grubu</span><select name="grup_id"><?= $grpOpt ?></select></label>
            <label class="pm-label"><span>KDV oranı</span><select name="kdv_orani"><?= $kdvOpt ?></select></label>
            <label class="st-onay st-span2"><input type="checkbox" name="aktif" value="1" checked><span>Aktif (pasif kart yeni fişte seçilemez)</span></label>
          </div></fieldset>
        <fieldset class="st-bolum"><legend>Birim</legend>
          <div class="st-form-grid st-form-grid--3">
            <label class="pm-label"><span>Ana birim <b class="st-zorunlu" aria-hidden="true">*</b></span><select name="ana_birim"><?= $brOpt ?></select>
              <span class="st-ipucu" data-birim-kilit hidden>Hareketli kartta değiştirilemez</span></label>
            <label class="pm-label"><span>Çevrim birimi</span><select name="cevrim_birim"><?= $cbOpt ?></select></label>
            <label class="pm-label"><span>Katsayı (1 çevrim = ? ana)</span><input type="text" name="cevrim_katsayi" inputmode="decimal" autocomplete="off" placeholder="ör. 8"></label>
          </div></fieldset>
        <fieldset class="st-bolum"><legend>Diğer</legend>
          <div class="st-form-grid st-form-grid--2">
            <label class="pm-label"><span>Barkod</span><input type="text" name="barkod" maxlength="60" autocomplete="off" inputmode="numeric"></label>
            <label class="pm-label"><span>Kritik seviye (ana birim)</span><input type="text" name="kritik_seviye" inputmode="numeric" autocomplete="off" placeholder="ör. 1.000"></label>
            <label class="pm-label st-span2"><span>Notlar</span><textarea name="notlar" rows="3" maxlength="5000"></textarea></label>
          </div></fieldset>
      </div>
      <div class="pm-footer"><span class="st-klavye">Ctrl+Enter kaydeder · Esc kapatır</span>
        <button type="button" class="btn" data-st-kapat>Vazgeç</button>
        <button type="submit" class="btn btn-primary">Kaydet</button></div>
    </form>
  </div>
</div>
<?php
    return (string)ob_get_clean();
}

// ── Tanımlar ────────────────────────────────────────────────────────────────

function stok_tanim_panel(PDO $pdo, string $tur, string $panelId, string $tabId, bool $gizli, bool $yaz): string
{
    $grup = $tur === 'grup';
    $liste = stok_tanim_listesi($pdo, $tur);
    $csrf = h(csrf_token());
    $geri = 'stok_tanimlari.php?sekme=' . $tur;
    $max = 0; foreach ($liste as $t) $max = max($max, (int)$t['sira']);
    $oneri = $max + 10;
    $baslik = ['grup' => 'Stok Grupları', 'giris_neden' => 'Giriş Nedenleri', 'cikis_neden' => 'Çıkış Nedenleri'][$tur];
    $ack = ['grup' => 'Stok kartlarını sınıflandırır; listede filtre olarak kullanılır. Kartta kullanılan grup silinemez, pasifleştirilir.',
        'giris_neden' => 'Giriş fişinde seçilir (alış, açılış/devir…). Kilitli satırlar sistem nedenidir: adı değişmez, silinmez; pasifleştirilebilir.',
        'cikis_neden' => 'Çıkış fişinde seçilir (satış, sarf, fire…). Kilitli satırlar sistem nedenidir: adı değişmez, silinmez; pasifleştirilebilir.'][$tur];
    $h = '<section class="st-tanim-govde" id="' . $panelId . '" role="tabpanel" aria-labelledby="' . $tabId . '"' . ($gizli ? ' hidden' : '') . '><h2>' . h($baslik) . '</h2><p>' . h($ack) . '</p>';
    if ($yaz) {
        $h .= '<form class="st-tanim-ekle" method="post" action="stok_kaydet.php" data-st-ajax data-ok="Tanım eklendi" novalidate><input type="hidden" name="csrf" value="' . $csrf . '">'
            . '<input type="hidden" name="islem" value="tanim_kaydet"><input type="hidden" name="tur" value="' . h($tur) . '"><input type="hidden" name="geri" value="' . h($geri) . '">'
            . '<label class="pm-label"><span>Yeni ' . h(mb_strtolower(stok_tanim_turleri()[$tur])) . '</span><input type="text" name="ad" maxlength="100" autocomplete="off" required></label>'
            . '<label class="pm-label st-tanim-sira"><span>Sıra</span><input type="number" name="sira" value="' . $oneri . '" min="0" max="99999" inputmode="numeric"></label>'
            . '<button type="submit" class="btn btn-primary">+ Ekle</button></form>';
    }
    if (!$liste) return $h . stok_bos('Henüz tanım yok', 'Yukarıdan ilk kaydı ekleyin.', '', 'h3') . '</section>';
    $h .= '<ul class="st-tanim-liste">';
    foreach ($liste as $t) {
        $id = (int)$t['id']; $aktif = (int)$t['aktif'] === 1; $kul = (int)$t['kullanim']; $sistem = !empty($t['kod']);
        $alt = 'Sıra ' . (int)$t['sira'] . ($kul > 0 ? ' · ' . $kul . ($grup ? ' kartta kullanılıyor' : ' fişte kullanıldı') : '');
        $h .= '<li class="st-tanim-satir' . ($aktif ? '' : ' st-tanim-satir--pasif') . '" data-id="' . $id . '"><div class="st-tanim-ad">' . h($t['ad'])
            . ($sistem ? ' <span class="st-rozet st-rozet--kilit" title="Sistem nedeni: adı değişmez, silinmez">&#128274; Sistem</span>' : '') . '<small>' . h($alt) . '</small></div><div class="st-tanim-eylem">';
        if ($yaz) {
            $h .= '<form method="post" action="stok_kaydet.php" data-st-ajax data-ok="Tanım güncellendi" class="st-icerik"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="islem" value="tanim_durum">'
                . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="geri" value="' . h($geri) . '">'
                . '<label class="st-aktif"><input type="checkbox" role="switch" data-id="' . $id . '" name="aktif" value="1"' . ($aktif ? ' checked' : '') . ' aria-label="' . h($t['ad']) . ' aktif"><span>Aktif</span></label>'
                . '<noscript><button type="submit" class="btn btn-sm">Uygula</button></noscript></form>'
                . '<button type="button" class="btn btn-sm" data-tanim-duzen aria-label="' . h($t['ad']) . ' düzenle">Düzenle</button>';
            if (!$sistem && ($kul === 0)) {
                $h .= '<form method="post" action="stok_kaydet.php" data-st-ajax data-ok="Tanım silindi" data-onay="Bu tanım silinecek. Emin misiniz?" class="st-icerik"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="islem" value="tanim_sil">'
                    . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="geri" value="' . h($geri) . '">'
                    . '<button type="submit" class="btn btn-sm st-tehlike" aria-label="' . h($t['ad']) . ' sil">Sil</button></form>';
            }
        } else {
            $h .= '<span class="st-rozet ' . ($aktif ? 'st-rozet--aktif' : 'st-rozet--pasif') . '">' . ($aktif ? 'Aktif' : 'Pasif') . '</span>';
        }
        $h .= '</div></li>';
        if ($yaz) {
            $h .= '<li class="st-tanim-satir st-tanim-satir--duzen" hidden><form method="post" action="stok_kaydet.php" data-st-ajax data-ok="Tanım güncellendi" class="st-tanim-duzen-form" novalidate>'
                . '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="islem" value="tanim_kaydet"><input type="hidden" name="tur" value="' . h($tur) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="geri" value="' . h($geri) . '">'
                . '<label class="pm-label"><span>Ad' . ($sistem ? ' (sistem: kilitli)' : '') . '</span><input type="text" name="ad" value="' . h($t['ad']) . '" maxlength="100" required' . ($sistem ? ' readonly' : '') . '></label>'
                . '<label class="pm-label st-tanim-sira"><span>Sıra</span><input type="number" name="sira" value="' . (int)$t['sira'] . '" min="0" max="99999" inputmode="numeric"></label>'
                . '<div class="st-tanim-eylem"><button type="button" class="btn" data-tanim-vazgec>Vazgeç</button><button type="submit" class="btn btn-primary">Kaydet</button></div></form></li>';
        }
    }
    return $h . '</ul></section>';
}

/** İçe aktar paneli. */
function stok_tanim_ice_panel(PDO $pdo, bool $gizli, bool $yaz): string
{
    try { $on = stok_ice_aktar_onizle($pdo); $hata = ''; }
    catch (Throwable $e) { $on = []; $hata = 'Tanım kaynağı (ürün/malzeme tanımları) okunamadı.'; }
    $yeni = 0; foreach ($on as $o) if ($o['durum'] === 'yeni') $yeni++;
    $tipler = stok_kart_tipleri();
    $tbl = ''; $lst = '';
    foreach ($on as $o) {
        $roz = $o['durum'] === 'yeni' ? '<span class="st-rozet st-rozet--aktif">Yeni</span>' : '<span class="st-rozet">Kartı var</span>';
        $hedef = ($tipler[$o['hedef_tip']] ?? $o['hedef_tip']) . ' · ' . $o['ana_birim'];
        $tbl .= '<tr><td>' . h($o['ad']) . '</td><td>' . h($o['kaynak_tur']) . '</td><td>' . h($hedef) . '</td><td>' . $roz . '</td></tr>';
        $lst .= '<li class="st-tanim-satir"><div class="st-tanim-ad">' . h($o['ad']) . '<small>' . h($o['kaynak_tur'] . ' → ' . $hedef) . '</small></div><div class="st-tanim-eylem">' . $roz . '</div></li>';
    }
    $h = '<section class="st-tanim-govde" id="stPanelIce" role="tabpanel" aria-labelledby="stTabIce"' . ($gizli ? ' hidden' : '') . '><h2>Tanımlardan içe aktar</h2>'
        . '<p>Tanımlar ekranındaki aktif <b>ürün</b> (kg) ve <b>malzeme</b> (adet) kayıtlarından stok kartı oluşturur. İsteğe bağlıdır; tekrar çalıştırmak çift kart üretmez. '
        . 'Kart kaynağına bağlanır ama sonradan tanımda yapılan ad değişikliği karta <b>yansımaz</b>.</p>';
    if ($hata !== '') return $h . '<div class="flash flash-error">' . h($hata) . '</div></section>';
    if (!$on) return $h . stok_bos('İçe aktarılacak kayıt yok', 'Tanımlar ekranında aktif ürün ya da malzeme bulunmuyor.', '', 'h3') . '</section>';
    $h .= '<div class="table-wrap st-onizleme-tablo"><table class="data-table st-tablo"><caption class="st-sr">İçe aktarma önizlemesi</caption><thead><tr><th scope="col">Ad</th><th scope="col">Kaynak tür</th><th scope="col">Hedef</th><th scope="col">Durum</th></tr></thead><tbody>' . $tbl . '</tbody></table></div>'
        . '<ul class="st-tanim-liste st-onizleme-liste">' . $lst . '</ul>'
        . '<div class="st-ice-alt"><span class="st-ipucu">' . $yeni . ' yeni kart eklenecek · ' . (count($on) - $yeni) . ' mevcut atlanacak</span>';
    if ($yaz) {
        $h .= '<form method="post" action="stok_kaydet.php" data-st-ajax data-ok="İçe aktarıldı" data-onay="' . $yeni . ' stok kartı oluşturulacak. Devam edilsin mi?" class="st-icerik"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<input type="hidden" name="islem" value="ice_aktar"><input type="hidden" name="geri" value="stok_tanimlari.php?sekme=ice_aktar">'
            . '<button type="submit" class="btn btn-primary" data-ice-aktar' . ($yeni === 0 ? ' disabled' : '') . '>' . $yeni . ' kartı içe aktar</button></form>';
    }
    return $h . '</div></section>';
}

/** Tanımlar sayfası gövdesi. ?sekme=grup|giris_neden|cikis_neden|ice_aktar */
function stok_tanimlari_govde(PDO $pdo): string
{
    $yaz = stok_can('admin');
    $sekme = (string)($_GET['sekme'] ?? 'grup');
    if (!in_array($sekme, ['grup', 'giris_neden', 'cikis_neden', 'ice_aktar'], true)) $sekme = 'grup';
    try { $nYeni = count(array_filter(stok_ice_aktar_onizle($pdo), static fn($o) => $o['durum'] === 'yeni')); } catch (Throwable $e) { $nYeni = 0; }
    $sek = ['grup' => ['stTabGrup', 'stPanelGrup', 'Stok Grupları'], 'giris_neden' => ['stTabGiris', 'stPanelGiris', 'Giriş Nedenleri'], 'cikis_neden' => ['stTabCikis', 'stPanelCikis', 'Çıkış Nedenleri'], 'ice_aktar' => ['stTabIce', 'stPanelIce', 'İçe Aktar']];
    $nav = ''; $paneller = '';
    foreach ($sek as $k => [$tab, $panel, $ad]) {
        $say = $k === 'ice_aktar' ? $nYeni . ' yeni' : (string)count(stok_tanim_listesi($pdo, $k));
        $nav .= '<a class="st-tanim-nav-oge" role="tab" id="' . $tab . '" href="stok_tanimlari.php?sekme=' . $k . '" data-sekme="' . $k . '" aria-selected="' . ($sekme === $k ? 'true' : 'false') . '" aria-controls="' . $panel . '">' . h($ad) . ' <small>' . h($say) . '</small></a>';
        $paneller .= $k === 'ice_aktar' ? stok_tanim_ice_panel($pdo, $sekme !== $k, $yaz) : stok_tanim_panel($pdo, $k, $panel, $tab, $sekme !== $k, $yaz);
    }
    return '<div class="stok" id="stok">' . stok_ust_menu('tanimlar') . '<div class="st-ustsatir"><h1>Stok Tanımları</h1></div><div class="st-tanim">'
        . '<div class="st-tanim-nav" role="tablist" aria-label="Tanım türleri">' . $nav . '</div><div class="st-tanim-icerik">' . $paneller . '</div></div>'
        . '<p class="st-bilgi-kutu">Kart tipi, birim listesi ve KDV oranları sabittir; yalnız stok grubu ile giriş/çıkış nedenleri tanımlanır. ' . h('Bu defter Ürün Stoğu (stok.php) ve Malzeme Stok ekranlarından bağımsızdır.') . '</p></div>';
}
