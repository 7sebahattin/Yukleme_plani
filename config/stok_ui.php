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

// ═════════════════════════════════════════════════════════════════════════════
// Parça B — Fiş sayfaları (stok_giris_fisleri / stok_cikis_fisleri / transfer_fisleri / stok_donusum_fisleri)
// Ortak gövde: stok_fis_sayfa_govde($pdo, $tur). Sol liste (GET süzgeç) + sağ panel: ?id= detay · ?yeni=1 / ?kopya=ID / ?kantar_id= form.
// Kısmi yanıt: stok_fis_parca() (?parca=detay | kantar_oneri). Kancalar (assets/stok.js): #stFisForm[data-st-fis] · #stFisVeri · .st-fsatir ·
// [data-satir-ekle] · [data-satir-sil] · [data-st-modal="iptal"|"kantar"] · #stEksik · [data-depo-takas] · #stModalIptal · #stModalKantar.
// ═════════════════════════════════════════════════════════════════════════════

/** Tür başına ekran metinleri. */
function stok_fis_metin(string $tur): array
{
    return [
        'giris'    => ['baslik' => 'Stok Giriş Fişleri', 'yeni' => 'Yeni Giriş Fişi', 'kisa' => 'giriş', 'sekme' => 'giris'],
        'cikis'    => ['baslik' => 'Stok Çıkış Fişleri', 'yeni' => 'Yeni Çıkış Fişi', 'kisa' => 'çıkış', 'sekme' => 'cikis'],
        'transfer' => ['baslik' => 'Transfer Fişleri', 'yeni' => 'Yeni Transfer Fişi', 'kisa' => 'transfer', 'sekme' => 'transfer'],
        'donusum'  => ['baslik' => 'Dönüşüm Fişleri', 'yeni' => 'Yeni Dönüşüm Fişi', 'kisa' => 'dönüşüm', 'sekme' => 'donusum'],
    ][$tur] ?? ['baslik' => 'Stok Fişleri', 'yeni' => 'Yeni Fiş', 'kisa' => 'fiş', 'sekme' => ''];
}

/** Sayfa adı (tür → kök dosya). */
function stok_fis_sayfa(string $tur): string { return stok_fis_turleri()[$tur]['sayfa'] ?? 'stok_giris_fisleri.php'; }

/** ?parca= kısmi yanıtları (header/footer YOK). Yanıt verildiyse true (çağıran exit eder). */
function stok_fis_parca(PDO $pdo, string $tur): bool
{
    $parca = (string)($_GET['parca'] ?? '');
    if ($parca === '') return false;
    if ($parca === 'detay') {
        $f = stok_fis_oku($pdo, max(0, (int)($_GET['id'] ?? 0)));
        if ($f === null || $f['tur'] !== $tur || !stok_fis_gorunur($f)) { http_response_code(404); echo '<div class="flash flash-error" role="alert">Fiş bulunamadı.</div>'; return true; }
        echo stok_fis_detay_html($pdo, $f);
        return true;
    }
    if ($parca === 'kantar_oneri' && $tur === 'giris') {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        $o = stok_kantar_oneri($pdo, max(0, (int)($_GET['kantar_id'] ?? 0)));
        if ($o === null) http_response_code(404);
        echo json_encode(['ok' => $o !== null, 'oneri' => $o], JSON_UNESCAPED_UNICODE);
        return true;
    }
    http_response_code(400);
    return true;
}

/** "18.450 kg" ya da birim karışıksa yalnız sayı. */
function stok_fis_miktar_ozet(int $milli, string $birim): string
{
    return $birim !== '' ? stok_miktar_birim($milli, $birim) : stok_miktar_bicim($milli, 'lt');
}

/** Transfer için aktif depoya göre Giden / Gelen etiketi ('' = belirsiz). */
function stok_transfer_yonu(array $f): string
{
    $a = function_exists('active_depot') ? (string)(active_depot() ?? '') : '';
    if ($a === '') return '';
    if (depo_fold((string)$f['depo']) === depo_fold($a)) return 'Giden';
    if (depo_fold((string)($f['hedef_depo'] ?? '')) === depo_fold($a)) return 'Gelen';
    return '';
}

function stok_fis_liste_satiri(array $f, bool $secili): string
{
    $tur = (string)$f['tur']; $iptal = $f['durum'] === 'iptal';
    $depo = $tur === 'transfer' ? $f['depo'] . ' → ' . ($f['hedef_depo'] ?? '') : (string)$f['depo'];
    $alt = array_filter([stok_tarih_tr((string)$f['tarih']), $depo, (string)($f['neden_ad'] ?? ''), (string)($f['cari_unvan'] ?? '')], static fn($x) => $x !== '');
    $ilk = (string)($f['ilk_kart'] ?? '');
    if ($ilk !== '') $alt[] = $ilk . ((int)$f['satir_say'] > 1 ? ' +' . ((int)$f['satir_say'] - 1) : '');
    $yon = $tur === 'transfer' ? stok_transfer_yonu($f) : '';
    $tut = $f['toplam_tutar'] !== null && $f['toplam_tutar'] !== '' ? cari_kurus($f['toplam_tutar']) + cari_kurus($f['toplam_kdv'] ?? 0) : null;
    $sag = '<span class="st-miktar">' . h(stok_fis_miktar_ozet((int)$f['toplam_milli'], (string)$f['birim'])) . '</span>'
        . ($tut !== null ? '<small class="st-satir-tutar">' . h(cari_tutar_bicim($tut, (string)$f['para_birimi'])) . '</small>' : '')
        . ($iptal ? '<span class="st-rozet st-rozet--iptal">İPTAL</span>' : '')
        . (!$iptal && !empty($f['uyari']) ? '<span class="st-rozet st-rozet--uyari" title="' . h($f['uyari']) . '">Eksi</span>' : '');
    return '<li><a class="st-satir st-fis-satir' . ($iptal ? ' st-satir--iptal' : '') . '" href="' . h(stok_fis_sayfa($tur) . '?id=' . (int)$f['id']) . '" data-id="' . (int)$f['id'] . '"' . ($secili ? ' aria-current="true"' : '') . '>'
        . '<span class="st-satir-ust"><span class="st-fisno-metin">' . h($f['fis_no']) . '</span> ' . stok_tur_rozet($tur, (string)($f['neden_ad'] ?? '')) . ($yon !== '' ? ' <span class="st-rozet st-rozet--yon">' . h($yon) . '</span>' : '') . '</span>'
        . '<span class="st-satir-alt">' . h(implode(' · ', $alt)) . '</span><span class="st-satir-sag">' . $sag . '</span></a></li>';
}

/** Süzgeç değerleri (GET). @return array{ara:string,durum:string,donem:string,neden:int,bas:string,bit:string} */
function stok_fis_filtre(): array
{
    $durum = (string)($_GET['durum'] ?? 'tumu');
    $donem = (string)($_GET['donem'] ?? 'tumu');
    if (!in_array($durum, ['tumu', 'gecerli', 'iptal'], true)) $durum = 'tumu';
    if (!in_array($donem, ['tumu', '30g', 'bu_ay', 'gecen_ay'], true)) $donem = 'tumu';
    [$bas, $bit] = match ($donem) {
        '30g' => [date('Y-m-d', strtotime('-30 days')), ''],
        'bu_ay' => [date('Y-m-01'), date('Y-m-t')],
        'gecen_ay' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
        default => ['', ''],
    };
    return ['ara' => mb_substr(trim((string)($_GET['ara'] ?? '')), 0, 100), 'durum' => $durum, 'donem' => $donem, 'neden' => max(0, (int)($_GET['neden'] ?? 0)), 'bas' => $bas, 'bit' => $bit];
}

function stok_fis_liste_kol_html(PDO $pdo, string $tur, array $F, array $L, ?int $seciliId): string
{
    $sayfa = stok_fis_sayfa($tur); $M = stok_fis_metin($tur);
    $q = static fn(array $ek): string => $sayfa . '?' . http_build_query(array_filter(['ara' => $F['ara'], 'durum' => $F['durum'] !== 'tumu' ? $F['durum'] : '', 'donem' => $F['donem'] !== 'tumu' ? $F['donem'] : '', 'neden' => $F['neden'] ?: ''] , static fn($x) => $x !== '' && $x !== 0) + $ek);
    $cip = '';
    foreach (['tumu' => 'Tümü', 'gecerli' => 'Geçerli', 'iptal' => 'İptal'] as $d => $ad) {
        $cip .= '<a class="st-cip" href="' . h($q(['durum' => $d === 'tumu' ? null : $d])) . '"' . ($F['durum'] === $d ? ' aria-current="true"' : '') . '>' . h($ad) . '</a>';
    }
    $don = '';
    foreach (['tumu' => 'Tüm tarihler', '30g' => 'Son 30 gün', 'bu_ay' => 'Bu ay', 'gecen_ay' => 'Geçen ay'] as $v => $ad) $don .= '<option value="' . $v . '"' . ($F['donem'] === $v ? ' selected' : '') . '>' . h($ad) . '</option>';
    $ned = '';
    if ($tur === 'giris' || $tur === 'cikis') {
        $ned = '<select name="neden" data-oto-gonder aria-label="Neden"><option value="">Tüm nedenler</option>';
        foreach (stok_tanim_listesi($pdo, $tur === 'giris' ? 'giris_neden' : 'cikis_neden') as $n) $ned .= '<option value="' . (int)$n['id'] . '"' . ($F['neden'] === (int)$n['id'] ? ' selected' : '') . '>' . h($n['ad']) . '</option>';
        $ned .= '</select>';
    }
    $liste = '';
    foreach ($L['satirlar'] as $f) $liste .= stok_fis_liste_satiri($f, $seciliId !== null && (int)$f['id'] === $seciliId);
    $filtreli = $F['ara'] !== '' || $F['durum'] !== 'tumu' || $F['donem'] !== 'tumu' || $F['neden'] > 0;
    $bos = !$L['satirlar']
        ? ($filtreli ? '<p class="st-liste-bos">Eşleşen fiş yok. <a href="' . h($sayfa) . '">Filtreleri temizle</a></p>'
            : stok_bos('Henüz ' . $M['kisa'] . ' fişi yok', 'İlk fişi oluşturun; her fiş stok defterine hareket yazar.', '<a class="btn btn-primary" href="' . h($sayfa . '?yeni=1') . '">' . h($M['yeni']) . '</a>'))
        : '';
    return '<div class="st-liste-kol" id="stListeKol"><div class="st-liste-ust"><form method="get" action="' . h($sayfa) . '" class="st-fis-filtre" role="search">'
        . '<div class="st-arama-satir"><div class="st-arama"><svg class="st-arama-ikon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>'
        . '<label class="st-sr" for="stArama">Fiş ara: fiş no, evrak, cari, ürün</label>'
        . '<input type="search" id="stArama" name="ara" placeholder="Fiş no, evrak, cari, ürün" autocomplete="off" value="' . h($F['ara']) . '"><kbd class="st-arama-ipucu" aria-hidden="true">/</kbd></div>'
        . '<a class="btn btn-primary" href="' . h($sayfa . '?yeni=1') . '" title="' . h($M['yeni']) . '" data-yeni-fis>+ Yeni</a></div>'
        . '<div class="st-cipler" role="group" aria-label="Fiş durumu">' . $cip . '</div>'
        . '<div class="st-filtre-satir' . ($ned === '' ? ' st-filtre-satir--tek' : '') . '"><select name="donem" data-oto-gonder aria-label="Dönem">' . $don . '</select>' . $ned . '</div>'
        . ($F['durum'] !== 'tumu' ? '<input type="hidden" name="durum" value="' . h($F['durum']) . '">' : '')
        . '<noscript><button type="submit" class="btn btn-sm">Uygula</button></noscript></form></div>'
        . $bos
        . '<ul class="st-liste" id="stListe" aria-label="' . h($M['baslik']) . '">' . $liste . '</ul>'
        . '<div class="st-liste-alt"><span id="stSayi">' . count($L['satirlar']) . ($L['toplam_satir'] > count($L['satirlar']) ? ' / ' . (int)$L['toplam_satir'] : '') . ' fiş</span><span>Aktif depo</span></div></div>';
}

// ── Fiş detayı ──────────────────────────────────────────────────────────────

/** Satır tablosu + kartları (detay). $satirlar: stok_fis_oku satırları. */
function stok_fis_satir_tablo(array $satirlar, string $tur, string $pb, string $baslik): string
{
    $fiyat = false;
    foreach ($satirlar as $s) if ($s['birim_fiyat'] !== null && $s['birim_fiyat'] !== '') { $fiyat = true; break; }
    $trs = ''; $kart = ''; $topT = 0; $topK = 0; $topM = 0; $birimler = []; $topMil = 0;
    foreach ($satirlar as $n => $s) {
        $b = (string)($s['ana_birim'] ?? ''); $birimler[$b] = true; $topMil += (int)$s['milli'];
        $t = $s['tutar'] !== null ? cari_kurus($s['tutar']) : null; $kd = $s['kdv_tutar'] !== null ? cari_kurus($s['kdv_tutar']) : null;
        $topT += (int)$t; $topK += (int)$kd; $topM += (int)($s['maliyet_kurus'] ?? 0);
        $mik = h(stok_miktar_birim((int)$s['milli'], $b));
        $fy = $s['birim_fiyat'] !== null && $s['birim_fiyat'] !== '' ? stok_fiyat_bicim((int)round((float)$s['birim_fiyat'] * 10000)) : '—';
        $mal = $s['maliyet_kurus'] !== null ? cari_tutar_bicim((int)$s['maliyet_kurus'], 'TRY') : '—';
        $lot = (string)($s['lot'] ?? '');
        $trs .= '<tr><td class="st-sayi">' . ($n + 1) . '</td><td class="st-fisno">' . h($s['kod'] ?? '') . '</td><td>' . h($s['ad'] ?? ('#' . $s['kart_id'])) . ($lot !== '' ? ' <small class="st-ipucu">Lot ' . h($lot) . '</small>' : '') . '</td>'
            . '<td class="st-sayi">' . $mik . '</td>'
            . ($fiyat ? '<td class="st-sayi">' . h($fy) . '</td><td class="st-sayi">' . ($s['kdv_orani'] !== null ? '%' . (int)$s['kdv_orani'] : '—') . '</td><td class="st-sayi">' . ($t !== null ? h(cari_tutar_bicim($t, $pb)) : '—') . '</td>' : '')
            . '<td class="st-sayi" title="Defter değeri (TRY, KDV hariç)">' . h($mal) . '</td></tr>';
        $kart .= '<article class="st-hk"><div class="st-hk-ust"><span><b>' . h($s['ad'] ?? '') . '</b> <small class="st-ipucu">' . h($s['kod'] ?? '') . '</small></span><span class="st-miktar">' . $mik . '</span></div>'
            . '<div class="st-hk-alt"><span>' . ($fiyat && $t !== null ? 'Fiyat ' . h($fy) . ' · Tutar ' . h(cari_tutar_bicim($t, $pb)) : 'Maliyet ' . h($mal)) . '</span>' . ($lot !== '' ? '<span>Lot ' . h($lot) . '</span>' : '') . '</div></article>';
    }
    $tekBirim = count($birimler) === 1 ? (string)array_key_first($birimler) : '';
    $foot = '<tr><th scope="row" colspan="3">Toplam</th><td class="st-sayi">' . h(stok_fis_miktar_ozet($topMil, $tekBirim)) . '</td>'
        . ($fiyat ? '<td></td><td class="st-sayi" title="KDV">' . h(cari_tutar_bicim($topK, $pb)) . '</td><td class="st-sayi">' . h(cari_tutar_bicim($topT, $pb)) . '</td>' : '')
        . '<td class="st-sayi">' . h(cari_tutar_bicim($topM, 'TRY')) . '</td></tr>';
    return '<div class="st-hareket-kaydir table-wrap"><table class="data-table st-tablo st-fis-tablo"><caption class="st-sr">' . h($baslik) . '</caption><thead><tr><th scope="col" class="st-sayi">#</th><th scope="col">Kod</th><th scope="col">Stok kartı</th><th scope="col" class="st-sayi">Miktar</th>'
        . ($fiyat ? '<th scope="col" class="st-sayi">Birim fiyat</th><th scope="col" class="st-sayi">KDV</th><th scope="col" class="st-sayi">Tutar</th>' : '')
        . '<th scope="col" class="st-sayi">Maliyet ₺</th></tr></thead><tbody>' . $trs . '</tbody><tfoot>' . $foot . '</tfoot></table></div>'
        . '<div class="st-hareket-kartlar" aria-label="' . h($baslik) . '">' . $kart . '</div>';
}

/** Detay bölümü (#stDetay). $f: stok_fis_oku sonucu. */
function stok_fis_detay_html(PDO $pdo, array $f): string
{
    $tur = (string)$f['tur']; $id = (int)$f['id']; $iptal = $f['durum'] === 'iptal';
    $sayfa = stok_fis_sayfa($tur);
    $pb = (string)$f['para_birimi'];
    $meta = '<span>' . h(stok_tarih_tr((string)$f['tarih'])) . '</span>'
        . ($tur === 'transfer'
            ? '<span class="st-depo-yol"><b>' . h($f['depo']) . '</b> <span aria-hidden="true">&#8594;</span><span class="st-sr">hedef</span> <b>' . h($f['hedef_depo'] ?? '') . '</b></span>' . (($y = stok_transfer_yonu($f)) !== '' ? '<span class="st-rozet st-rozet--yon">' . h($y) . '</span>' : '')
            : '<span>Depo: ' . h($f['depo']) . '</span>')
        . (!empty($f['cari']) ? '<span>Cari: <a href="cari_kartlari.php?id=' . (int)$f['cari']['id'] . '">' . h($f['cari']['unvan']) . '</a></span>' : '')
        . (!empty($f['evrak_no']) ? '<span>Evrak: ' . h($f['evrak_no']) . '</span>' : '')
        . ($pb !== 'TRY' ? '<span>' . h($pb) . ($f['kur'] !== null ? ' · kur ' . h(rtrim(rtrim((string)$f['kur'], '0'), '.')) : '') . '</span>' : '')
        . (!empty($f['kantar']) ? '<span class="st-rozet">Kantar #' . h($f['kantar']['fis_no']) . '</span>' : ($f['kaynak_modul'] === 'kantar' ? '<span class="st-rozet">Kantar #' . (int)$f['kaynak_id'] . '</span>' : ''));
    $serit = $iptal ? '<div class="st-iptal-serit" role="note"><b>İptal edildi:</b> ' . h((string)$f['iptal_neden']) . ' · ' . h(stok_tarih_tr((string)$f['iptal_at'])) . '</div>' : '';
    $uyari = !empty($f['uyari']) ? '<div class="st-fis-uyari"><span class="st-rozet st-rozet--uyari">Eksiye düşürüldü</span> ' . h((string)$f['uyari']) . '</div>' : '';
    $cariH = !empty($f['cari_hareket']) ? '<p class="st-not">Cari hareketi: <a href="cari_kartlari.php?id=' . (int)$f['cari_hareket']['cari_id'] . '">' . h((string)$f['cari_hareket']['belge_no']) . '</a>'
        . ($f['cari_hareket']['durum'] === 'iptal' ? ' <span class="st-rozet st-rozet--iptal">İPTAL</span>' : '') . ' · ' . h(cari_tutar_bicim(cari_kurus($f['cari_hareket']['tutar']), (string)$f['cari_hareket']['para_birimi'])) . '</p>' : '';
    $islem = '<div class="st-islemler" role="toolbar" aria-label="Fiş işlemleri">'
        . '<a class="btn btn-primary" href="' . h($sayfa . '?kopya=' . $id) . '" data-kopya>Kopyala</a>'
        . ($iptal || !stok_can('delete')
            ? '<button type="button" class="btn st-tehlike" disabled title="' . ($iptal ? 'Fiş zaten iptal edildi' : 'Yetkiniz yok') . '">İptal et</button>'
            : '<button type="button" class="btn st-tehlike" data-st-modal="iptal" data-id="' . $id . '" data-fis-no="' . h($f['fis_no']) . '" data-geri="' . h($sayfa . '?id=' . $id) . '">İptal et</button>')
        . '<button type="button" class="btn" data-yazdir>Yazdır</button></div>';
    if ($tur === 'donusum') {
        $C = array_values(array_filter($f['satirlar'], static fn($s) => $s['yon'] === 'C'));
        $G = array_values(array_filter($f['satirlar'], static fn($s) => $s['yon'] === 'G'));
        $cm = array_sum(array_map(static fn($s) => (int)$s['milli'], $C)); $gm = array_sum(array_map(static fn($s) => (int)$s['milli'], $G));
        $cv = array_sum(array_map(static fn($s) => (int)($s['maliyet_kurus'] ?? 0), $C));
        $fark = $cm - $gm;
        $satir = '<div class="st-donusum"><section class="st-donusum-kol st-donusum-kol--girdi"><h3 class="st-bolum-baslik"><span class="st-yon" aria-hidden="true">&#9660;</span> Girdiler <small>çıkar</small></h3>'
            . stok_fis_satir_tablo($C, $tur, $pb, 'Girdiler') . '</section>'
            . '<section class="st-donusum-kol st-donusum-kol--cikti"><h3 class="st-bolum-baslik"><span class="st-yon" aria-hidden="true">&#9650;</span> Çıktılar <small>girer</small></h3>'
            . stok_fis_satir_tablo($G, $tur, $pb, 'Çıktılar') . '</section></div>'
            . '<p class="st-fire">Fire / fark: <b>' . h(stok_miktar_bicim($fark, 'lt')) . '</b>' . ($cm > 0 ? ' (%' . h(number_format($fark / $cm * 100, 1, ',', '.')) . ')' : '')
            . ' · Girdi maliyeti <b>' . h(cari_tutar_bicim($cv, 'TRY')) . '</b>' . ($gm > 0 ? ' · Çıktı birim maliyeti <b>' . h(cari_tutar_bicim((int)round($cv / ($gm / 1000)), 'TRY')) . '</b>' : '') . ' <span class="st-ipucu">miktarlar ana birimde</span></p>';
    } else {
        $satir = '<h3 class="st-bolum-baslik">Satırlar <small>' . count($f['satirlar']) . ' satır</small></h3><div class="st-hareket">' . stok_fis_satir_tablo($f['satirlar'], $tur, $pb, 'Fiş satırları') . '</div>';
    }
    return '<section class="st-detay st-fis-detay" id="stDetay" data-id="' . $id . '" data-tur="' . h($tur) . '" aria-label="Fiş detayı"><div class="st-detay-kart">'
        . '<a class="st-geri" href="' . h($sayfa) . '"><span aria-hidden="true">&#8592;</span> Liste</a>'
        . '<header class="st-baslik"><div class="st-baslik-satir"><h2 class="st-unvan">' . h($f['fis_no']) . '</h2><span class="st-baslik-rozet">' . stok_tur_rozet($tur, (string)($f['neden_ad'] ?? ''))
        . ($iptal ? ' <span class="st-rozet st-rozet--iptal">İPTAL</span>' : '') . '</span></div><div class="st-meta">' . $meta . '</div></header>'
        . $serit . $uyari . $islem
        . '<div class="st-fis-icerik">' . $satir
        . (!empty($f['aciklama']) ? '<p class="st-notlar"><b>Açıklama:</b> ' . nl2br(h((string)$f['aciklama'])) . '</p>' : '')
        . $cariH . stok_bagimsiz_not() . '</div>'
        . '</div></section>';
}

// ── Fiş formu ───────────────────────────────────────────────────────────────

/** Birim seçenekleri (kart biliniyorsa ana + çevrim). value '' = ana birim. */
function stok_fis_birim_opt(?array $kart, string $secili): string
{
    if ($kart === null) return '<option value="">—</option>';
    $o = '<option value="">' . h($kart['birim']) . '</option>';
    if (!empty($kart['cevrim_birim'])) {
        $o .= '<option value="' . h($kart['cevrim_birim']) . '"' . ($secili === $kart['cevrim_birim'] ? ' selected' : '') . '>' . h($kart['cevrim_birim'] . ' (×' . stok_miktar_bicim((int)round((float)$kart['cevrim_katsayi'] * 1000), 'lt') . ' ' . $kart['birim'] . ')') . '</option>';
    }
    return $o;
}

/** Tek form satırı (PC tablo satırı / mobil kart). $s: kart_id, miktar, birim, birim_fiyat, kdv_orani, lot, satir_aciklama. */
function stok_fis_form_satiri(string $tur, string $yon, array $s, ?array $kart, int $no): string
{
    $fiyatli = $tur === 'giris' || $tur === 'cikis';
    $kartMetin = $kart !== null ? $kart['kod'] . ' — ' . $kart['ad'] : (string)($s['kart_ara'] ?? '');
    $birim = $kart !== null ? (string)$kart['birim'] : 'kg';
    $lot = (string)($s['lot'] ?? '');
    $kdvOpt = '<option value="" title="Kartın KDV oranı">—</option>';
    foreach (stok_kdv_oranlari() as $k) $kdvOpt .= '<option value="' . $k . '"' . ((string)($s['kdv_orani'] ?? '') === (string)$k ? ' selected' : '') . '>%' . $k . '</option>';
    return '<tr class="st-fsatir" data-yon="' . h($yon) . '">'
        . '<td class="st-fs-no" aria-hidden="true">' . $no . '</td>'
        . '<td class="st-fs-kart"><span class="st-fs-etiket">Stok kartı</span><input type="hidden" name="yon[]" value="' . h($yon) . '"><input type="hidden" name="kart_id[]" value="' . ($kart !== null ? (int)$kart['id'] : '') . '">'
        . '<input type="text" name="kart_ara[]" class="st-kart-ara" list="stKartListe" value="' . h($kartMetin) . '" placeholder="Kart ara… (kod, ad, barkod)" autocomplete="off" aria-label="Stok kartı" spellcheck="false">'
        . '<small class="st-fs-ipucu" aria-live="polite"></small>'
        . '<div class="st-fs-lot' . ($lot === '' ? ' st-fs-lot--kapali' : '') . '"><input type="text" name="lot[]" maxlength="60" value="' . h($lot) . '" placeholder="Lot / parti (isteğe bağlı)" aria-label="Lot" autocomplete="off"></div>'
        . '<button type="button" class="st-lot-ac" data-lot-ac' . ($lot !== '' ? ' hidden' : '') . '>+ Lot</button>'
        . '<input type="hidden" name="satir_aciklama[]" value="' . h((string)($s['satir_aciklama'] ?? '')) . '"></td>'
        . '<td class="st-fs-miktar"><span class="st-fs-etiket">Miktar</span><input type="text" name="miktar[]" value="' . h((string)($s['miktar'] ?? '')) . '" inputmode="' . (stok_birim_tam_mi($birim) ? 'numeric' : 'decimal') . '" autocomplete="off" aria-label="Miktar"><small class="st-cevrim" aria-live="polite"></small></td>'
        . '<td class="st-fs-birim"><span class="st-fs-etiket">Birim</span><select name="birim[]" aria-label="Birim">' . stok_fis_birim_opt($kart, (string)($s['birim'] ?? '')) . '</select></td>'
        . ($fiyatli
            ? '<td class="st-fs-fiyat"><span class="st-fs-etiket">Birim fiyat</span><input type="text" name="birim_fiyat[]" value="' . h((string)($s['birim_fiyat'] ?? '')) . '" inputmode="decimal" autocomplete="off" aria-label="Birim fiyat (ana birim, KDV hariç)"></td>'
              . '<td class="st-fs-kdv"><span class="st-fs-etiket">KDV %</span><select name="kdv_orani[]" aria-label="KDV oranı">' . $kdvOpt . '</select></td>'
              . '<td class="st-fs-tutar"><span class="st-fs-etiket">Tutar</span><output class="st-fs-tutar-deger">—</output></td>'
            : '')
        . '<td class="st-fs-sil"><button type="button" class="st-fsatir-sil" data-satir-sil aria-label="Satırı sil" title="Satırı sil (Ctrl+Del)">&#10005;</button></td></tr>';
}

/** Satır tablosu (form). */
function stok_fis_form_tablo(string $tur, string $yon, array $satirlar, array $kartlar, string $baslik): string
{
    $fiyatli = $tur === 'giris' || $tur === 'cikis';
    $tr = ''; $no = 0;
    foreach ($satirlar as $s) {
        if (($tur === 'donusum') && ($s['yon'] ?? '') !== $yon) continue;
        $tr .= stok_fis_form_satiri($tur, $yon, $s, $kartlar[(int)($s['kart_id'] ?? 0)] ?? null, ++$no);
    }
    if ($no === 0) $tr = stok_fis_form_satiri($tur, $yon, [], null, 1);
    return '<div class="st-satirlar" data-yon="' . h($yon) . '"><div class="st-ftablo-kaydir"><table class="st-ftablo"><caption class="st-sr">' . h($baslik) . '</caption><thead><tr>'
        . '<th scope="col" class="st-fs-no">#</th><th scope="col" class="st-fs-kart">Stok kartı</th><th scope="col" class="st-fs-miktar">Miktar</th><th scope="col" class="st-fs-birim">Birim</th>'
        . ($fiyatli ? '<th scope="col" class="st-fs-fiyat">Birim fiyat</th><th scope="col" class="st-fs-kdv">KDV</th><th scope="col" class="st-fs-tutar">Tutar</th>' : '')
        . '<th scope="col" class="st-fs-sil"><span class="st-sr">Sil</span></th></tr></thead><tbody class="st-fsatirlar">' . $tr . '</tbody></table></div>'
        . '<button type="button" class="btn st-satir-ekle" data-satir-ekle="' . h($yon) . '">＋ Satır ekle</button></div>';
}

/**
 * Fiş formu (#stDetay içinde). $o: başlangıç değerleri (kopya / kantar önerisi / boş): tarih, depo, hedef_depo, neden_id, cari_id, para_birimi, kur, evrak_no, aciklama,
 *   satirlar[], kaynak_modul, kaynak_id, kaynak_bilgi, kopya_no.
 */
function stok_fis_form_html(PDO $pdo, string $tur, array $o): string
{
    $M = stok_fis_metin($tur); $sayfa = stok_fis_sayfa($tur);
    $fiyatli = $tur === 'giris' || $tur === 'cikis';
    $kartlar = [];
    $ort = stok_ortlar_toplu($pdo);
    foreach (stok_kart_secenekleri($pdo) as $k) $kartlar[$k['id']] = $k + ['ort' => $ort[$k['id']] ?? 0.0];
    $cariler = $fiyatli ? stok_cari_secenekleri($pdo) : [];
    $aktif = function_exists('active_depot') ? (string)(active_depot() ?? '') : '';
    $depolar = depot_options();
    $depo = (string)($o['depo'] ?? '') !== '' ? (string)$o['depo'] : $aktif;
    $depoOpt = '';
    foreach ($depolar as $d) if (depot_visible_to_user((string)$d)) $depoOpt .= '<option value="' . h($d) . '"' . ($d === $depo ? ' selected' : '') . '>' . h($d) . '</option>';
    if ($depoOpt === '') $depoOpt = '<option value="">Depo tanımı yok</option>';
    $alan = static fn(string $etiket, string $ic, string $sinif = ''): string => '<label class="pm-label st-alan' . ($sinif !== '' ? ' ' . $sinif : '') . '"><span>' . $etiket . '</span>' . $ic . '</label>';
    $z = ' <b class="st-zorunlu" aria-hidden="true">*</b>';
    $bas = $alan('Tarih' . $z, '<input type="date" name="tarih" value="' . h((string)($o['tarih'] ?? date('Y-m-d'))) . '" required>');
    if ($tur === 'transfer') {
        $hedef = (string)($o['hedef_depo'] ?? '');
        $hOpt = '<option value="">Seçiniz</option>';
        foreach ($depolar as $d) $hOpt .= '<option value="' . h($d) . '"' . ($d === $hedef ? ' selected' : '') . '>' . h($d) . '</option>';
        $bas .= $alan('Kaynak depo' . $z, '<select name="depo" required>' . $depoOpt . '</select>')
            . '<div class="st-alan st-takas-kutu"><button type="button" class="btn st-takas" data-depo-takas aria-label="Kaynak ve hedef depoyu değiştir" title="Depoları takas et">&#8644;</button></div>'
            . $alan('Hedef depo' . $z, '<select name="hedef_depo" required>' . $hOpt . '</select>');
    } else {
        $bas .= $alan('Depo' . $z, '<select name="depo" required>' . $depoOpt . '</select>');
    }
    if ($fiyatli) {
        $nOpt = '<option value="">Seçiniz</option>';
        foreach (stok_tanim_listesi($pdo, $tur === 'giris' ? 'giris_neden' : 'cikis_neden', true) as $n) $nOpt .= '<option value="' . (int)$n['id'] . '"' . ((int)($o['neden_id'] ?? 0) === (int)$n['id'] ? ' selected' : '') . '>' . h($n['ad']) . '</option>';
        $bas .= $alan('Neden' . $z, '<select name="neden_id" required>' . $nOpt . '</select>');
        $bas .= $alan('Evrak no', '<input type="text" name="evrak_no" maxlength="60" value="' . h((string)($o['evrak_no'] ?? '')) . '" autocomplete="off">');
        if ($cariler || cari_hazir($pdo)) {
            $cid = (int)($o['cari_id'] ?? 0); $cm = '';
            foreach ($cariler as $c) if ($c['id'] === $cid) $cm = $c['kod'] . ' — ' . $c['unvan'];
            $bas .= $alan('Cari <small class="st-ipucu">' . ($tur === 'giris' ? 'alışta' : 'satışta') . ' dekont yazılır</small>', '<input type="hidden" name="cari_id" value="' . ($cid > 0 ? $cid : '') . '">'
                . '<input type="text" name="cari_ara" list="stCariListe" value="' . h($cm) . '" placeholder="Cari ara (isteğe bağlı)" autocomplete="off" spellcheck="false">', 'st-alan--cari');
        }
        $pb = (string)($o['para_birimi'] ?? 'TRY');
        $pbOpt = ''; foreach (cari_para_birimleri() as $p) $pbOpt .= '<option value="' . h($p) . '"' . ($p === $pb ? ' selected' : '') . '>' . h($p) . '</option>';
        $bas .= $alan('Para birimi', '<select name="para_birimi">' . $pbOpt . '</select>', 'st-alan--pb')
            . $alan('Kur', '<input type="text" name="kur" value="' . h((string)($o['kur'] ?? '')) . '" inputmode="decimal" autocomplete="off" placeholder="TL için boş"' . ($pb === 'TRY' ? ' disabled' : '') . '>', 'st-alan--pb');
    }
    if (!$fiyatli) $bas .= $alan('Evrak no', '<input type="text" name="evrak_no" maxlength="60" value="' . h((string)($o['evrak_no'] ?? '')) . '" autocomplete="off">');
    $bas .= $alan('Açıklama', '<input type="text" name="aciklama" maxlength="500" value="' . h((string)($o['aciklama'] ?? '')) . '" autocomplete="off">', 'st-alan--genis');

    $sat = $o['satirlar'] ?? [];
    if ($tur === 'donusum') {
        $govde = '<div class="st-donusum st-donusum--form"><section class="st-donusum-kol st-donusum-kol--girdi"><h4 class="st-donusum-bas"><span class="st-yon" aria-hidden="true">&#9660;</span> Girdiler <small>(çıkar)</small></h4>'
            . stok_fis_form_tablo($tur, 'C', $sat, $kartlar, 'Girdiler') . '</section><div class="st-donusum-ok" aria-hidden="true">&#8594;</div>'
            . '<section class="st-donusum-kol st-donusum-kol--cikti"><h4 class="st-donusum-bas"><span class="st-yon" aria-hidden="true">&#9650;</span> Çıktılar <small>(girer)</small></h4>'
            . stok_fis_form_tablo($tur, 'G', $sat, $kartlar, 'Çıktılar') . '</section></div>'
            . '<p class="st-fire" data-fire>Fire / fark: <b data-top="fark">0</b> · Çıktı birim maliyeti (tahmini): <b data-top="cikti_ort">—</b></p>';
    } else {
        $govde = stok_fis_form_tablo($tur, $tur === 'giris' ? 'G' : 'C', $sat, $kartlar, 'Fiş satırları');
    }
    $kaynak = '';
    if (!empty($o['kaynak_modul'])) {
        $kaynak = '<input type="hidden" name="kaynak_modul" value="' . h((string)$o['kaynak_modul']) . '"><input type="hidden" name="kaynak_id" value="' . (int)$o['kaynak_id'] . '">';
    } elseif ($tur === 'giris') {
        $kaynak = '<input type="hidden" name="kaynak_modul" value=""><input type="hidden" name="kaynak_id" value="">';
    }
    $bilgi = !empty($o['kaynak_bilgi']) ? (string)$o['kaynak_bilgi'] : (!empty($o['kopya_no']) ? (string)$o['kopya_no'] . ' fişinden kopyalandı; tarih bugüne alındı.' : '');
    $fiyatsiz = $tur === 'cikis' && empty($o['cari_id']);
    $veri = ['tur' => $tur, 'kartlar' => array_values($kartlar), 'bakiye' => (object)stok_bakiye_haritasi($pdo), 'cariler' => $cariler, 'kdv' => stok_kdv_oranlari()];
    $datalist = '<datalist id="stKartListe">';
    foreach ($kartlar as $k) $datalist .= '<option value="' . h($k['kod'] . ' — ' . $k['ad']) . '"></option>';
    $datalist .= '</datalist>';
    if ($cariler) { $datalist .= '<datalist id="stCariListe">'; foreach ($cariler as $c) $datalist .= '<option value="' . h($c['kod'] . ' — ' . $c['unvan']) . '"></option>'; $datalist .= '</datalist>'; }
    $sablonSatir = stok_fis_form_satiri($tur, $tur === 'giris' ? 'G' : 'C', [], null, 1);
    return '<section class="st-detay st-fis-form-detay" id="stDetay" data-form="' . h($tur) . '" aria-label="' . h($M['yeni']) . '"><div class="st-detay-kart">'
        . '<a class="st-geri" href="' . h($sayfa) . '"><span aria-hidden="true">&#8592;</span> Liste</a>'
        . '<form method="post" action="stok_kaydet.php" class="st-form st-fis-form st-fis-form--' . h($tur) . ($fiyatsiz ? ' st-form--fiyatsiz' : '') . '" id="stFisForm" data-st-fis data-tur="' . h($tur) . '" novalidate>'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="islem" value="fis_kaydet"><input type="hidden" name="tur" value="' . h($tur) . '">'
        . '<input type="hidden" name="istek_anahtari" value="' . h(bin2hex(random_bytes(12))) . '"><input type="hidden" name="eksiye_izin" value="0"><input type="hidden" name="geri" value="' . h($sayfa . '?yeni=1') . '">' . $kaynak
        . '<header class="st-form-bas"><h2 class="st-unvan">' . h($M['yeni']) . ' ' . stok_tur_rozet($tur) . '</h2>'
        . ($tur === 'giris' ? '<button type="button" class="btn" data-st-modal="kantar">Kantardan doldur</button>' : '') . '</header>'
        . '<div class="st-kaynak-bilgi" data-kaynak-bilgi' . ($bilgi === '' ? ' hidden' : '') . '>' . h($bilgi) . '</div>'
        . '<div class="flash flash-error st-form-hata" role="alert" hidden></div>'
        . '<div class="st-eksik" id="stEksik" role="alertdialog" aria-labelledby="stEksikBaslik" aria-describedby="stEksikAck" hidden><h3 id="stEksikBaslik">Yetersiz stok</h3>'
        . '<p id="stEksikAck">Bu kayıt stoğu eksiye düşürecek. Düzeltin ya da onaylayıp yine de kaydedin (fişe uyarı yazılır).</p>'
        . '<div class="table-wrap"><table class="data-table st-tablo st-eksik-liste"><thead><tr><th scope="col">Kart</th><th scope="col">Depo</th><th scope="col" class="st-sayi">İstenen</th><th scope="col" class="st-sayi">Mevcut</th><th scope="col" class="st-sayi">Eksik</th></tr></thead><tbody></tbody></table></div>'
        . '<div class="st-eksik-eylem"><button type="button" class="btn" data-eksik-duzelt>Düzelt</button><button type="button" class="btn st-btn-uyari" data-eksik-kaydet>Yine de kaydet</button></div></div>'
        . '<div class="st-form-govde">'
        . '<section class="st-adim-bolum"><h3 class="st-adim-bas"><span class="st-adim" aria-hidden="true">1</span> Başlık</h3><div class="st-form-grid st-fis-bas">' . $bas . '</div></section>'
        . '<section class="st-adim-bolum"><h3 class="st-adim-bas"><span class="st-adim" aria-hidden="true">2</span> Satırlar <small class="st-ipucu">' . ($fiyatli ? 'fiyat ana birim başına, KDV hariç' : 'maliyet ortalamadan otomatik') . '</small></h3>' . $govde . '</section>'
        . '<noscript><label class="st-onay"><input type="checkbox" name="eksiye_izin" value="1"><span>Stok yetersizse yine de kaydet (eksiye düşürür)</span></label></noscript>'
        . '</div>'
        . '<div class="st-eylem-bar"><div class="st-ozet-bar" aria-live="polite"><span class="st-ozet-adim"><span class="st-adim" aria-hidden="true">3</span></span><span>Toplam <b data-top="miktar">0</b></span>'
        . ($fiyatli ? '<span class="st-fs-fiyat-ozet">Tutar <b data-top="tutar">0,00</b></span><span class="st-fs-fiyat-ozet">KDV <b data-top="kdv">0,00</b></span>' : '') . '</div>'
        . '<span class="st-klavye">Enter sonraki alan · Ctrl+S kaydet · Esc</span>'
        . '<a class="btn st-vazgec" href="' . h($sayfa) . '">Vazgeç</a><button type="submit" class="btn btn-primary" data-kaydet>Kaydet</button></div>'
        . '</form>' . $datalist
        . '<template id="stSatirSablon">' . $sablonSatir . '</template>'
        . stok_json_script('stFisVeri', $veri)
        . '</div></section>';
}

// ── Modallar ────────────────────────────────────────────────────────────────

function stok_modal_iptal(string $sayfa, ?array $f): string
{
    $id = $f !== null ? (int)$f['id'] : 0;
    return '<div class="pm-overlay" id="stModalIptal" hidden><div class="pm-dialog st-dialog st-dialog--dar" role="dialog" aria-modal="true" aria-labelledby="stIptalBaslik">'
        . '<div class="pm-header"><span class="st-tutamac" aria-hidden="true"></span><h2 class="pm-title" id="stIptalBaslik">Fişi iptal et — <span data-iptal-no>' . h($f['fis_no'] ?? '') . '</span></h2>'
        . '<button type="button" class="pm-close" data-st-kapat aria-label="Kapat">&#10005;</button></div>'
        . '<form method="post" action="stok_kaydet.php" data-st-ajax data-ok="Fiş iptal edildi" novalidate>'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="islem" value="fis_iptal"><input type="hidden" name="id" value="' . ($id ?: '') . '">'
        . '<input type="hidden" name="geri" value="' . h($sayfa . ($id ? '?id=' . $id : '')) . '">'
        . '<div class="pm-body"><div class="flash flash-error st-form-hata" role="alert" hidden></div>'
        . '<div class="st-uyari st-uyari--tehlike" role="note"><span class="st-uyari-ikon" aria-hidden="true">&#9888;</span><div><p>Stok hareketleri tersine yazılır, bağlı cari hareketi iptal edilir. Bu işlem geri alınamaz; düzeltme için iptalden sonra <b>Kopyala</b> ile yeni fiş girin.</p></div></div>'
        . '<label class="pm-label"><span>İptal nedeni <b class="st-zorunlu" aria-hidden="true">*</b></span><textarea name="neden" rows="3" minlength="3" maxlength="500" required data-ilk-odak data-iptal-neden aria-describedby="stIptalSayac"></textarea>'
        . '<span class="st-ipucu" id="stIptalSayac" data-sayac>En az 3 karakter</span></label></div>'
        . '<div class="pm-footer"><span class="st-klavye">Esc kapatır</span><button type="button" class="btn" data-st-kapat>Vazgeç</button>'
        . '<button type="submit" class="btn st-btn-tehlike" data-iptal-onay>Fişi iptal et</button></div></form></div></div>';
}

function stok_modal_kantar(PDO $pdo): string
{
    $ad = stok_kantar_adaylari($pdo, 50);
    $lst = '';
    foreach ($ad as $k) {
        $akt = $k['aktarildi'];
        $lst .= '<li><label class="st-kaynak-satir' . ($akt ? ' st-kaynak-satir--pasif' : '') . '" data-ara="' . h(stok_kucuk($k['kantar_no'] . ' ' . $k['firma'] . ' ' . $k['urun'])) . '">'
            . '<input type="radio" name="kantar_id" value="' . (int)$k['kantar_id'] . '"' . ($akt ? ' disabled' : '') . ' data-oneri="' . stok_json_attr($k) . '">'
            . '<span class="st-kaynak-ust"><b>Kantar #' . h($k['kantar_no']) . '</b> <span>' . h(stok_tarih_tr($k['tarih'])) . '</span></span>'
            . '<span class="st-kaynak-alt">' . h(implode(' · ', array_filter([$k['firma'], $k['urun'], $k['depo']], static fn($x) => $x !== ''))) . '</span>'
            . '<span class="st-kaynak-sag"><span class="st-miktar">' . h(stok_miktar_birim((int)$k['miktar_milli'], 'kg')) . '</span>'
            . ($akt ? '<span class="st-rozet">Aktarıldı · ' . h($akt['fis_no']) . '</span>' : '') . '</span></label></li>';
    }
    $govde = $ad
        ? '<label class="st-sr" for="stKantarAra">Kantar fişi ara</label><input type="search" id="stKantarAra" class="st-kantar-ara" placeholder="Ara: kantar no, firma, ürün" autocomplete="off" data-ilk-odak>'
          . '<p class="st-ipucu">Net kg = tartım neti − kasa darası − palet darası (Ürün Stoğu ile aynı formül). Kantar kaydı DEĞİŞMEZ; yalnız form doldurulur.</p>'
          . '<ul class="st-kaynak-liste" aria-label="Kantar fişleri">' . $lst . '</ul>'
        : stok_bos('Aktarılmamış kantar fişi yok', 'Aktif depoda kantar (mal kabul) kaydı bulunmuyor.', '<a class="btn" href="kantar.php">Mal Kabul</a>', 'h3');
    return '<div class="pm-overlay" id="stModalKantar" hidden><div class="pm-dialog st-dialog st-dialog--genis st-dialog--uzun" role="dialog" aria-modal="true" aria-labelledby="stKantarBaslik">'
        . '<div class="pm-header"><span class="st-tutamac" aria-hidden="true"></span><h2 class="pm-title" id="stKantarBaslik">Kantar fişinden doldur</h2><button type="button" class="pm-close" data-st-kapat aria-label="Kapat">&#10005;</button></div>'
        . '<form method="get" action="stok_giris_fisleri.php" data-st-kantar-form><input type="hidden" name="yeni" value="1"><div class="pm-body">' . $govde . '</div>'
        . '<div class="pm-footer"><span class="st-klavye">Enter seçer · Esc kapatır</span><button type="button" class="btn" data-st-kapat>Vazgeç</button>'
        . '<button type="submit" class="btn btn-primary"' . ($ad ? '' : ' disabled') . '>Seç</button></div></form></div></div>';
}

/**
 * Fiş sayfası ortak gövdesi (4 tür). ?id= detay · ?yeni=1 boş form · ?kopya=ID kopya formu · ?yeni=1&kantar_id=ID kantar önerili form (giriş).
 * Süzgeç: ?ara= &durum=tumu|gecerli|iptal &donem=tumu|30g|bu_ay|gecen_ay &neden=<id>
 */
function stok_fis_sayfa_govde(PDO $pdo, string $tur): string
{
    $M = stok_fis_metin($tur); $sayfa = stok_fis_sayfa($tur);
    $F = stok_fis_filtre();
    $L = stok_fis_listesi($pdo, $tur, ['bas' => $F['bas'], 'bit' => $F['bit'], 'durum' => $F['durum'], 'neden_id' => $F['neden'], 'ara' => $F['ara']], 100);
    $id = max(0, (int)($_GET['id'] ?? 0));
    $kopyaId = max(0, (int)($_GET['kopya'] ?? 0));
    $yeni = ($_GET['yeni'] ?? '') === '1';
    $fis = null; $sag = ''; $form = false; $not = '';
    if ($id > 0) {
        $fis = stok_fis_oku($pdo, $id);
        if ($fis !== null && ($fis['tur'] !== $tur || !stok_fis_gorunur($fis))) { $not = $fis['tur'] !== $tur ? 'Bu fiş başka bir türe ait.' : 'Bu fiş aktif deponuzda görünmüyor.'; $fis = null; }
        elseif ($fis === null) $not = 'Fiş bulunamadı.';
    }
    if ($fis !== null) {
        $sag = stok_fis_detay_html($pdo, $fis);
    } elseif (($yeni || $kopyaId > 0) && stok_can('write')) {
        $o = [];
        if ($kopyaId > 0) {
            $k = stok_fis_oku($pdo, $kopyaId);
            if ($k !== null && $k['tur'] === $tur && stok_fis_gorunur($k)) $o = stok_fis_kopya($k);
        }
        $kid = max(0, (int)($_GET['kantar_id'] ?? 0));
        if ($tur === 'giris' && $kid > 0 && ($kn = stok_kantar_oneri($pdo, $kid)) !== null) {
            if (!empty($kn['aktarildi'])) $not = 'Bu kantar fişi zaten ' . $kn['aktarildi']['fis_no'] . ' ile aktarılmış.';
            else $o = ['tarih' => $kn['tarih'], 'depo' => $kn['depo'], 'cari_id' => $kn['cari_id'], 'kaynak_modul' => 'kantar', 'kaynak_id' => $kn['kantar_id'],
                'kaynak_bilgi' => 'Kantar #' . $kn['kantar_no'] . '’ten dolduruldu · ' . stok_miktar_birim($kn['miktar_milli'], 'kg') . ' net' . ($kn['kart_id'] === null && $kn['urun'] !== '' ? ' · ürün: ' . $kn['urun'] . ' (kartı seçin)' : ''),
                'satirlar' => [['kart_id' => $kn['kart_id'], 'kart_ara' => $kn['kart_id'] === null ? $kn['urun'] : '', 'miktar' => stok_miktar_bicim($kn['miktar_milli'], 'kg'), 'yon' => 'G']]];
        }
        $sag = stok_fis_form_html($pdo, $tur, $o);
        $form = true;
    } else {
        $sag = '<section class="st-detay" id="stDetay" aria-label="Fiş detayı"><div class="st-detay-bos">' . h($not !== '' ? $not . ' Soldan bir fiş seçin' : 'Soldan bir fiş seçin ya da yeni fiş oluşturun')
            . '<p><a class="btn btn-primary" href="' . h($sayfa . '?yeni=1') . '">' . h($M['yeni']) . '</a></p></div></section>';
    }
    $detayAcik = $fis !== null || $form;
    return '<div class="stok st--tek-ekran st--fis" id="stok" data-sayfa="fis" data-tur="' . h($tur) . '">' . stok_ust_menu($M['sekme'])
        . ($not !== '' && $detayAcik ? '<div class="flash flash-error" role="alert">' . h($not) . '</div>' : '')
        . '<div class="st-ana' . ($detayAcik ? ' st-ana--detay' : '') . ($form ? ' st-ana--form' : '') . '">'
        . stok_fis_liste_kol_html($pdo, $tur, $F, $L, $fis !== null ? (int)$fis['id'] : null)
        . $sag . '</div>'
        . (stok_can('delete') ? stok_modal_iptal($sayfa, $fis) : '')
        . ($form && $tur === 'giris' ? stok_modal_kantar($pdo) : '')
        . '</div>';
}
