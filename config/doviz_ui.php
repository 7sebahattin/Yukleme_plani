<?php
// =========================================================
// config/doviz_ui.php — Döviz & Günlük Kurlar render yardımcıları (include-only; yalnız fonksiyon bildirir)
//
// Sayfalar (gunluk_kurlar.php / doviz_tanimlari.php) veriyi config/doviz_core.php'den okur,
// işaretlemeyi BURADAN alır. Sınıf öneki `dv-`, kök `.doviz#doviz`, JS sözleşmesi assets/doviz.js.
// Burada VERİTABANI SORGUSU veya YAZMA YOKTUR. HTML'e giren HER dinamik değer h() ile kaçışlanır.
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

// ── Varlıklar ve Ortak Yardımcılar ──────────────────────────────────────────

function doviz_head_varlik(): void
{
    $p = dirname(__DIR__) . '/assets/doviz.css';
    $v = is_file($p) ? (string)@filemtime($p) : (defined('APP_SURUM') ? (string)APP_SURUM : '1');
    echo '<link rel="stylesheet" href="assets/doviz.css?v=' . rawurlencode($v) . '">' . "\n";
}

function doviz_scripts(): void
{
    $p = dirname(__DIR__) . '/assets/doviz.js';
    $v = is_file($p) ? (string)@filemtime($p) : (defined('APP_SURUM') ? (string)APP_SURUM : '1');
    echo '<script src="assets/doviz.js?v=' . rawurlencode($v) . '"></script>' . "\n";
}

/**
 * Şema kurulmamış uyarı kartı (migrate.php#doviz bağlantısı).
 * @param list<string> $eksik
 */
function doviz_sema_uyari(array $eksik): string
{
    $liste = $eksik ? ' (' . implode(', ', array_map(static fn(string $t): string => '<code>' . h($t) . '</code>', $eksik)) . ')' : '';
    return '<div class="doviz"><div class="dv-uyari" data-dv-sema role="alert">'
        . '<span class="dv-uyari-ikon" aria-hidden="true">&#9888;</span>'
        . '<div>'
        . '<h2>Döviz &amp; Kurlar tabloları kurulmamış</h2>'
        . '<p>Döviz &amp; Günlük Kurlar modülünün veritabanı tabloları' . $liste . ' bu sunucuda henüz oluşturulmadı. Yönetici: Şema Migrasyon sayfasında <b>Döviz &amp; Kurlar</b> kartını çalıştırın.</p>'
        . '<div class="dv-uyari-eylem"><a class="btn btn-primary" data-sema-eksik="doviz" href="migrate.php#doviz">Şema Migrasyon &rsaquo; Döviz &amp; Kurlar</a></div>'
        . '</div></div></div>';
}

/** <script type="application/json"> içine güvenli JSON (</script> kaçışlı). */
function doviz_json_script(string $id, array $veri): string
{
    $json = json_encode(
        $veri,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    return '<script type="application/json" id="' . h($id) . '">' . ($json !== false ? $json : '{}') . '</script>';
}

/** YYYY-MM-DD → DD.MM.YYYY */
function doviz_ui_tarih_tr(?string $d): string
{
    if ($d === null || $d === '') return '—';
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) ? ($m[3] . '.' . $m[2] . '.' . $m[1]) : $d;
}

/** Günlük değişim yüzdesi rozeti (her zaman ok + işaret + yüzde — yalnız renk değil). */
function doviz_ui_degisim_rozet(?float $yuzde): string
{
    if ($yuzde === null) {
        return '<span class="dv-degisim dv-degisim--yok" title="Önceki iş günü verisi yok">—</span>';
    }
    $abs = number_format(abs($yuzde), 2, ',', '.');
    if ($yuzde > 0.0001) {
        return '<span class="dv-degisim dv-degisim--artis"><span aria-hidden="true">&#9650;</span> +%' . h($abs) . '</span>';
    }
    if ($yuzde < -0.0001) {
        return '<span class="dv-degisim dv-degisim--dusus"><span aria-hidden="true">&#9660;</span> -%' . h($abs) . '</span>';
    }
    return '<span class="dv-degisim dv-degisim--sabit"><span aria-hidden="true">=</span> %0,00</span>';
}

/** Kaynak rozeti (TCMB / Manuel). */
function doviz_ui_kaynak_rozet(string $kaynak): string
{
    if ($kaynak === 'manuel') {
        return '<span class="dv-rozet dv-rozet--manuel">Manuel</span>';
    }
    return '<span class="dv-rozet dv-rozet--tcmb">TCMB</span>';
}

/** Çapraz kur gösterimi. */
function doviz_ui_capraz(array $r): string
{
    $cu = $r['capraz_usd'] ?? null;
    $cd = $r['capraz_diger'] ?? null;
    if ($cu !== null && $cu !== '' && is_numeric($cu)) {
        return '<span class="dv-sayi" title="1 USD karşılığı">' . h(doviz_bicim((string)$cu, 4)) . '</span> <small class="dv-capraz-etiket">USD</small>';
    }
    if ($cd !== null && $cd !== '' && is_numeric($cd)) {
        return '<span class="dv-sayi" title="USD karşılığı">' . h(doviz_bicim((string)$cd, 4)) . '</span> <small class="dv-capraz-etiket">Çapraz</small>';
    }
    return '<span class="dv-soluk">—</span>';
}

// ── Günlük Kurlar Sayfası ───────────────────────────────────────────────────

/**
 * Günlük Kurlar sayfası gövdesini render eder.
 * @param array<string,mixed> $ctx
 */
function doviz_gunluk_sayfa_render(array $ctx): string
{
    $gorunum        = (string)($ctx['gorunum'] ?? 'gunluk');
    $tarih          = (string)($ctx['tarih'] ?? date('Y-m-d'));
    $bugun          = (string)($ctx['bugun'] ?? date('Y-m-d'));
    $oncekiGun      = (string)($ctx['onceki_gun'] ?? '');
    $sonrakiGun     = (string)($ctx['sonraki_gun'] ?? '');
    $tumu           = !empty($ctx['tumu']);
    $bultenNo       = (string)($ctx['bulten_no'] ?? '');
    $kurlar         = is_array($ctx['kurlar'] ?? null) ? $ctx['kurlar'] : [];
    $kpiKurlar      = is_array($ctx['kpi_kurlar'] ?? null) ? $ctx['kpi_kurlar'] : [];
    $mevcutTarihler = is_array($ctx['mevcut_tarihler'] ?? null) ? $ctx['mevcut_tarihler'] : [];
    $tanimlar       = is_array($ctx['tanimlar'] ?? null) ? $ctx['tanimlar'] : [];
    $kurTipleri     = is_array($ctx['kur_tipleri'] ?? null) ? $ctx['kur_tipleri'] : [];
    $gecmis         = is_array($ctx['gecmis'] ?? null) ? $ctx['gecmis'] : [];
    $gecmisKod      = (string)($ctx['gecmis_kod'] ?? '');
    $gecmisBas      = (string)($ctx['gecmis_bas'] ?? '');
    $gecmisBit      = (string)($ctx['gecmis_bit'] ?? '');
    $yazabilir      = !empty($ctx['yazabilir']);
    $silebilir      = !empty($ctx['silebilir']);
    $tanimIzni      = !empty($ctx['tanim_izni']);
    $csrf           = (string)($ctx['csrf'] ?? '');
    $geriUrl        = (string)($ctx['geri_url'] ?? 'gunluk_kurlar.php');

    // Çevirici & JS için hafif kur dizisi
    $jsKurlar = [];
    foreach ($kurlar as $r) {
        $jsKurlar[] = [
            'kod'           => (string)$r['kod'],
            'isim'          => (string)($r['ad'] ?? $r['kod']),
            'sembol'        => (string)($r['sembol'] ?? $r['kod']),
            'birim'         => max(1, (int)($r['birim'] ?? 1)),
            'ondalik'       => max(2, min(6, (int)($r['ondalik'] ?? 4))),
            'doviz_alis'    => $r['doviz_alis'] !== null ? (float)$r['doviz_alis'] : null,
            'doviz_satis'   => $r['doviz_satis'] !== null ? (float)$r['doviz_satis'] : null,
            'efektif_alis'  => $r['efektif_alis'] !== null ? (float)$r['efektif_alis'] : null,
            'efektif_satis' => $r['efektif_satis'] !== null ? (float)$r['efektif_satis'] : null,
        ];
    }

    $jsVeri = [
        'sayfa'  => 'gunluk',
        'csrf'   => $csrf,
        'tarih'  => $tarih,
        'bugun'  => $bugun,
        'geri'   => $geriUrl,
        'kurlar' => $jsKurlar,
    ];

    $h = '<div class="doviz dv-gunluk" id="doviz" data-sayfa="gunluk">';

    // 1. Üst Başlık ve Eylem Şeridi
    $h .= '<header class="dv-ust">';
    $h .= '<div class="dv-ust-baslik">';
    $h .= '<div class="dv-baslik-satir">';
    $h .= '<h1 class="dv-sayfa-baslik">Günlük Kurlar</h1>';
    if ($gorunum === 'gunluk') {
        $h .= '<span class="dv-rozet dv-rozet--tarih">' . h(doviz_ui_tarih_tr($tarih)) . '</span>';
        if ($bultenNo !== '') {
            $h .= '<span class="dv-rozet dv-rozet--bulten">Bülten: ' . h($bultenNo) . '</span>';
        }
    }
    $h .= '</div>';

    // Görünüm sekmeleri
    $gunlukHref = 'gunluk_kurlar.php?gorunum=gunluk&tarih=' . rawurlencode($tarih) . ($tumu ? '&tumu=1' : '');
    $gecmisHref = 'gunluk_kurlar.php?gorunum=gecmis';
    $h .= '<nav class="dv-sekmeler" aria-label="Kur görünümleri">';
    $h .= '<a class="dv-sekme" href="' . h($gunlukHref) . '"' . ($gorunum === 'gunluk' ? ' aria-current="page"' : '') . '>Günlük Kurlar</a>';
    $h .= '<a class="dv-sekme" href="' . h($gecmisHref) . '"' . ($gorunum === 'gecmis' ? ' aria-current="page"' : '') . '>Tarihçe (Gün Gün)</a>';
    if ($tanimIzni) {
        $h .= '<a class="dv-sekme dv-sekme--dis" href="doviz_tanimlari.php">Döviz Tanımları</a>';
    }
    $h .= '</nav>';
    $h .= '</div>';

    // Eylem düğmeleri
    $h .= '<div class="dv-eylemler">';
    if ($yazabilir) {
        $h .= '<button type="button" class="btn btn-primary dv-btn" data-dv-tcmb-cek data-tarih="' . h($tarih) . '">'
            . '<span class="dv-btn-ikon" aria-hidden="true">&#8635;</span> <span>TCMB\'den Çek</span></button>';
        $h .= '<button type="button" class="btn dv-btn" data-dv-aralik-cek data-gun="7">'
            . '<span>Son 7 İş Günü</span></button>';
        $h .= '<button type="button" class="btn dv-btn" data-dv-kur-ekle>'
            . '<span class="dv-btn-ikon" aria-hidden="true">+</span> <span>Manuel Kur Ekle</span></button>';
    }
    $h .= '</div>';
    $h .= '</header>';

    if ($gorunum === 'gecmis') {
        $h .= doviz_ui_gecmis_bolum($gecmis, $tanimlar, $gecmisKod, $gecmisBas, $gecmisBit);
    } else {
        // Tarih navigasyonu
        $h .= doviz_ui_tarih_cubugu($tarih, $bugun, $oncekiGun, $sonrakiGun, $tumu, $mevcutTarihler);

        // KPI Öne Çıkanlar
        if ($kpiKurlar) {
            $h .= doviz_ui_kpi_seridi($kpiKurlar);
        }

        // Canlı Kur Çevirici
        if ($jsKurlar) {
            $h .= doviz_ui_cevirici($jsKurlar, $kurTipleri);
        }

        // Arama & Filtre Çubuğu
        $h .= doviz_ui_filtre_cubugu($tarih, $tumu, count($kurlar));

        // Günlük Kur Tablosu + Mobil Kartlar / Boş Durum
        if ($kurlar) {
            $h .= doviz_ui_gunluk_liste($kurlar, $yazabilir, $silebilir, $tarih);
        } else {
            $sonTarih = isset($ctx['son_tarih']) && is_string($ctx['son_tarih']) ? $ctx['son_tarih'] : null;
            $h .= doviz_ui_gunluk_bos($tarih, $yazabilir, $tumu, $bugun, $sonTarih);
        }
    }

    // Manuel Kur Modalı
    if ($yazabilir) {
        $h .= doviz_ui_modal_kur($tarih, $tanimlar, $csrf, $geriUrl);
    }

    $h .= doviz_json_script('dvVeri', $jsVeri);
    $h .= '</div>';

    return $h;
}

/** Tarih gezinti çubuğu (Önceki / Tarih input / Bugün / Sonraki / Kayıtlı tarihler). */
function doviz_ui_tarih_cubugu(
    string $tarih,
    string $bugun,
    string $oncekiGun,
    string $sonrakiGun,
    bool $tumu,
    array $mevcutTarihler
): string {
    $tumuEk = $tumu ? '&tumu=1' : '';
    $h = '<section class="dv-tarih-bar" aria-label="Tarih seçimi">';
    $h .= '<div class="dv-tarih-nav">';
    $h .= '<a class="btn btn-sm dv-nav-btn" href="gunluk_kurlar.php?tarih=' . rawurlencode($oncekiGun) . $tumuEk . '" aria-label="Önceki gün">&lsaquo; Önceki</a>';
    $h .= '<form method="get" action="gunluk_kurlar.php" class="dv-tarih-form" data-dv-tarih-form>';
    if ($tumu) {
        $h .= '<input type="hidden" name="tumu" value="1">';
    }
    $h .= '<label class="dv-sr" for="dvTarihInput">Kur tarihi</label>';
    $h .= '<input type="date" id="dvTarihInput" name="tarih" value="' . h($tarih) . '" max="' . h($bugun) . '" class="dv-tarih-input" data-dv-tarih-input>';
    $h .= '<noscript><button type="submit" class="btn btn-sm">Git</button></noscript>';
    $h .= '</form>';
    $h .= '<a class="btn btn-sm dv-nav-btn' . ($tarih === $bugun ? ' dv-nav-btn--aktif' : '') . '" href="gunluk_kurlar.php?tarih=' . rawurlencode($bugun) . $tumuEk . '">Bugün</a>';
    if ($sonrakiGun !== '' && $sonrakiGun <= $bugun) {
        $h .= '<a class="btn btn-sm dv-nav-btn" href="gunluk_kurlar.php?tarih=' . rawurlencode($sonrakiGun) . $tumuEk . '" aria-label="Sonraki gün">Sonraki &rsaquo;</a>';
    } else {
        $h .= '<span class="btn btn-sm dv-nav-btn dv-nav-btn--pasif" aria-disabled="true">Sonraki &rsaquo;</span>';
    }
    $h .= '</div>';

    if ($mevcutTarihler) {
        $h .= '<div class="dv-kayitli-sec">';
        $h .= '<label class="dv-sr" for="dvKayitliTarih">Kayıtlı bülten tarihleri</label>';
        $h .= '<select id="dvKayitliTarih" class="dv-select" data-dv-tarih-sec data-tumu="' . ($tumu ? '1' : '0') . '">';
        $h .= '<option value="">Kayıtlı tarihler (' . count($mevcutTarihler) . ')</option>';
        foreach ($mevcutTarihler as $mt) {
            $tVal   = (string)$mt['tarih'];
            $etiket = doviz_ui_tarih_tr($tVal);
            if (!empty($mt['bulten_no'])) {
                $etiket .= ' · Bülten ' . (string)$mt['bulten_no'];
            }
            $etiket .= ' (' . (int)$mt['kur_sayisi'] . ' kur)';
            $h .= '<option value="' . h($tVal) . '"' . ($tVal === $tarih ? ' selected' : '') . '>' . h($etiket) . '</option>';
        }
        $h .= '</select>';
        $h .= '</div>';
    }

    $h .= '</section>';
    return $h;
}

/** KPI kartları şeridi (en fazla 4 öne çıkan döviz). */
function doviz_ui_kpi_seridi(array $kpiKurlar): string
{
    $h = '<section class="dv-kpi-grid" aria-label="Öne çıkan döviz kurları">';
    foreach ($kpiKurlar as $r) {
        $kod     = (string)$r['kod'];
        $sembol  = (string)($r['sembol'] ?? $kod);
        $isim    = (string)($r['ad'] ?? $kod);
        $birim   = max(1, (int)($r['birim'] ?? 1));
        $ondalik = max(2, min(6, (int)($r['ondalik'] ?? 4)));
        $degisim = isset($r['degisim_yuzde']) && $r['degisim_yuzde'] !== null ? (float)$r['degisim_yuzde'] : null;

        $h .= '<article class="dv-kpi-kart">';
        $h .= '<div class="dv-kpi-ust">';
        $h .= '<div class="dv-kpi-kimlik">';
        $h .= '<span class="dv-sembol-kutu" aria-hidden="true">' . h($sembol) . '</span>';
        $h .= '<div class="dv-kpi-metin">';
        $h .= '<strong class="dv-kpi-kod">' . h($kod) . ($birim > 1 ? ' <span class="dv-birim-rozet">' . $birim . '</span>' : '') . '</strong>';
        $h .= '<span class="dv-kpi-isim">' . h($isim) . '</span>';
        $h .= '</div></div>';
        $h .= doviz_ui_degisim_rozet($degisim);
        $h .= '</div>';

        $h .= '<div class="dv-kpi-ana">';
        $h .= '<div class="dv-kpi-sutun"><span class="dv-kpi-etiket">Döviz Alış</span><strong class="dv-kpi-deger dv-sayi">' . h(doviz_bicim($r['doviz_alis'] ?? null, $ondalik)) . '</strong></div>';
        $h .= '<div class="dv-kpi-sutun"><span class="dv-kpi-etiket">Döviz Satış</span><strong class="dv-kpi-deger dv-kpi-deger--vurgu dv-sayi">' . h(doviz_bicim($r['doviz_satis'] ?? null, $ondalik)) . '</strong></div>';
        $h .= '</div>';

        $h .= '<div class="dv-kpi-alt">';
        $h .= '<span>Ef. Alış: <b class="dv-sayi">' . h(doviz_bicim($r['efektif_alis'] ?? null, $ondalik)) . '</b></span>';
        $h .= '<span>Ef. Satış: <b class="dv-sayi">' . h(doviz_bicim($r['efektif_satis'] ?? null, $ondalik)) . '</b></span>';
        $h .= '</div>';
        $h .= '</article>';
    }
    $h .= '</section>';
    return $h;
}

/** Hızlı canlı döviz çevirici şeridi. */
function doviz_ui_cevirici(array $jsKurlar, array $kurTipleri): string
{
    $secenekler = '<option value="TRY">TRY — Türk Lirası (₺)</option>';
    $ilkYabanci = 'USD';
    $usdVar = false;
    foreach ($jsKurlar as $k) {
        if ($k['kod'] === 'USD') $usdVar = true;
    }
    if (!$usdVar && isset($jsKurlar[0]['kod'])) {
        $ilkYabanci = (string)$jsKurlar[0]['kod'];
    }

    $kaynakOpt = '<option value="TRY">TRY (₺) — Türk Lirası</option>';
    $hedefOpt  = '<option value="TRY" selected>TRY (₺) — Türk Lirası</option>';
    foreach ($jsKurlar as $k) {
        $kod    = (string)$k['kod'];
        $etiket = $kod . ' (' . (string)$k['sembol'] . ') — ' . (string)$k['isim'];
        $kaynakOpt .= '<option value="' . h($kod) . '"' . ($kod === $ilkYabanci ? ' selected' : '') . '>' . h($etiket) . '</option>';
        $hedefOpt  .= '<option value="' . h($kod) . '">' . h($etiket) . '</option>';
    }

    $tipOpt = '';
    foreach ($kurTipleri as $tk => $tEtiket) {
        $tipOpt .= '<option value="' . h($tk) . '"' . ($tk === 'doviz_satis' ? ' selected' : '') . '>' . h($tEtiket) . '</option>';
    }

    $h = '<section class="dv-cevirici" aria-label="Hızlı döviz çevirici" data-dv-cevirici>';
    $h .= '<div class="dv-cevirici-baslik"><h2>Hızlı Çevirici</h2><span class="dv-cevirici-ipucu">Seçili günün kurlarıyla anlık hesaplama</span></div>';
    $h .= '<div class="dv-cevirici-grid">';
    $h .= '<label class="dv-alan"><span>Tutar</span><input type="text" inputmode="decimal" value="100" class="dv-input dv-sayi" data-dv-cev-tutar autocomplete="off"></label>';
    $h .= '<label class="dv-alan"><span>Kaynak</span><select class="dv-select" data-dv-cev-kaynak>' . $kaynakOpt . '</select></label>';
    $h .= '<div class="dv-cev-takas-sar"><button type="button" class="btn dv-takas-btn" data-dv-cev-takas aria-label="Para birimlerini değiştir" title="Değiştir">&#8644;</button></div>';
    $h .= '<label class="dv-alan"><span>Hedef</span><select class="dv-select" data-dv-cev-hedef>' . $hedefOpt . '</select></label>';
    $h .= '<label class="dv-alan"><span>Kur Tipi</span><select class="dv-select" data-dv-cev-tip>' . $tipOpt . '</select></label>';
    $h .= '<div class="dv-cev-sonuc-kutu" aria-live="polite">';
    $h .= '<span class="dv-cev-sonuc-etiket">Sonuç</span>';
    $h .= '<strong class="dv-cev-sonuc-deger dv-sayi" data-dv-cev-sonuc>—</strong>';
    $h .= '<small class="dv-cev-birim dv-sayi" data-dv-cev-birim></small>';
    $h .= '</div>';
    $h .= '</div>';
    $h .= '</section>';
    return $h;
}

/** Arama ve Aktif/Tüm TCMB kurları filtre şeridi. */
function doviz_ui_filtre_cubugu(string $tarih, bool $tumu, int $toplam): string
{
    $aktifUrl = 'gunluk_kurlar.php?tarih=' . rawurlencode($tarih) . '&tumu=0';
    $tumuUrl  = 'gunluk_kurlar.php?tarih=' . rawurlencode($tarih) . '&tumu=1';

    $h = '<div class="dv-filtre-bar">';
    $h .= '<div class="dv-arama-kutu">';
    $h .= '<label class="dv-sr" for="dvAra">Döviz ara</label>';
    $h .= '<input type="search" id="dvAra" class="dv-input" placeholder="Döviz kodu veya ismi ara (USD, Euro...)" data-dv-ara autocomplete="off">';
    $h .= '</div>';
    $h .= '<div class="dv-filtre-sag">';
    $h .= '<div class="dv-segment" role="group" aria-label="Döviz kapsamı">';
    $h .= '<a class="dv-segment-btn' . (!$tumu ? ' dv-segment-btn--aktif' : '') . '" href="' . h($aktifUrl) . '">Sadece Aktif Dövizler</a>';
    $h .= '<a class="dv-segment-btn' . ($tumu ? ' dv-segment-btn--aktif' : '') . '" href="' . h($tumuUrl) . '">Tüm TCMB Kurları</a>';
    $h .= '</div>';
    $h .= '<span class="dv-sayac" data-dv-sayac>' . $toplam . ' döviz</span>';
    $h .= '</div>';
    $h .= '</div>';
    return $h;
}

/** Günlük kurlar masaüstü tablosu ve mobil kart listesi. */
function doviz_ui_gunluk_liste(array $kurlar, bool $yazabilir, bool $silebilir, string $tarih): string
{
    $eylemSutun = $yazabilir || $silebilir;

    // Masaüstü Tablo
    $tablo = '<div class="dv-tablo-sar"><table class="dv-tablo"><thead><tr>'
        . '<th>Döviz</th>'
        . '<th class="dv-sag">Döviz Alış</th>'
        . '<th class="dv-sag">Döviz Satış</th>'
        . '<th class="dv-sag">Efektif Alış</th>'
        . '<th class="dv-sag">Efektif Satış</th>'
        . '<th class="dv-sag">Çapraz Kur</th>'
        . '<th class="dv-sag">Günlük Değişim</th>'
        . '<th>Kaynak</th>'
        . ($eylemSutun ? '<th class="dv-sag">İşlemler</th>' : '')
        . '</tr></thead><tbody>';

    // Mobil Kartlar
    $kartlar = '<div class="dv-kartlar">';

    foreach ($kurlar as $r) {
        $id      = (int)$r['id'];
        $kod     = (string)$r['kod'];
        $isim    = (string)($r['ad'] ?? $kod);
        $sembol  = (string)($r['sembol'] ?? $kod);
        $birim   = max(1, (int)($r['birim'] ?? 1));
        $ondalik = max(2, min(6, (int)($r['ondalik'] ?? 4)));
        $kaynak  = (string)($r['kaynak'] ?? 'tcmb');
        $degisim = isset($r['degisim_yuzde']) && $r['degisim_yuzde'] !== null ? (float)$r['degisim_yuzde'] : null;

        $da = doviz_bicim($r['doviz_alis'] ?? null, $ondalik);
        $ds = doviz_bicim($r['doviz_satis'] ?? null, $ondalik);
        $ea = doviz_bicim($r['efektif_alis'] ?? null, $ondalik);
        $es = doviz_bicim($r['efektif_satis'] ?? null, $ondalik);

        $araMetin = mb_strtolower($kod . ' ' . $isim . ' ' . $sembol, 'UTF-8');

        $duzenleJson = json_encode([
            'tarih'         => (string)($r['tarih'] ?? $tarih),
            'kod'           => $kod,
            'birim'         => $birim,
            'doviz_alis'    => $r['doviz_alis'] !== null ? number_format((float)$r['doviz_alis'], $ondalik, ',', '') : '',
            'doviz_satis'   => $r['doviz_satis'] !== null ? number_format((float)$r['doviz_satis'], $ondalik, ',', '') : '',
            'efektif_alis'  => $r['efektif_alis'] !== null ? number_format((float)$r['efektif_alis'], $ondalik, ',', '') : '',
            'efektif_satis' => $r['efektif_satis'] !== null ? number_format((float)$r['efektif_satis'], $ondalik, ',', '') : '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $eylemHtml = '';
        if ($yazabilir) {
            $eylemHtml .= '<button type="button" class="btn btn-sm dv-ikon-btn" data-dv-kur-duzenle="' . h((string)$duzenleJson) . '" aria-label="' . h($kod) . ' kurunu düzenle" title="Düzenle">&#9998;</button>';
        }
        if ($silebilir) {
            $eylemHtml .= '<button type="button" class="btn btn-sm dv-ikon-btn dv-tehlike" data-dv-kur-sil="' . $id . '" data-dv-kod="' . h($kod) . '" aria-label="' . h($kod) . ' kurunu sil" title="Sil">&#128465;</button>';
        }

        $birimRozet = $birim > 1 ? ' <span class="dv-birim-rozet">' . $birim . ' ' . h($kod) . '</span>' : '';

        $tablo .= '<tr data-dv-satir data-dv-filtre="' . h($araMetin) . '">'
            . '<td><div class="dv-hucre-kimlik"><span class="dv-sembol-kutu dv-sembol-kutu--sm" aria-hidden="true">' . h($sembol) . '</span>'
            . '<div><strong class="dv-kod">' . h($kod) . '</strong>' . $birimRozet
            . '<div class="dv-isim">' . h($isim) . '</div></div></div></td>'
            . '<td class="dv-sag dv-sayi">' . h($da) . '</td>'
            . '<td class="dv-sag dv-sayi dv-vurgu">' . h($ds) . '</td>'
            . '<td class="dv-sag dv-sayi">' . h($ea) . '</td>'
            . '<td class="dv-sag dv-sayi">' . h($es) . '</td>'
            . '<td class="dv-sag">' . doviz_ui_capraz($r) . '</td>'
            . '<td class="dv-sag">' . doviz_ui_degisim_rozet($degisim) . '</td>'
            . '<td>' . doviz_ui_kaynak_rozet($kaynak) . '</td>'
            . ($eylemSutun ? '<td class="dv-sag"><div class="dv-satir-eylem">' . $eylemHtml . '</div></td>' : '')
            . '</tr>';

        $kartlar .= '<article class="dv-kart" data-dv-satir data-dv-filtre="' . h($araMetin) . '">';
        $kartlar .= '<div class="dv-kart-ust">';
        $kartlar .= '<div class="dv-hucre-kimlik"><span class="dv-sembol-kutu dv-sembol-kutu--sm" aria-hidden="true">' . h($sembol) . '</span>'
            . '<div><strong class="dv-kod">' . h($kod) . '</strong>' . $birimRozet
            . '<div class="dv-isim">' . h($isim) . '</div></div></div>';
        $kartlar .= '<div class="dv-kart-ust-sag">' . doviz_ui_degisim_rozet($degisim) . doviz_ui_kaynak_rozet($kaynak) . '</div>';
        $kartlar .= '</div>';

        $kartlar .= '<div class="dv-kart-grid">';
        $kartlar .= '<div><span>Döviz Alış</span><strong class="dv-sayi">' . h($da) . '</strong></div>';
        $kartlar .= '<div><span>Döviz Satış</span><strong class="dv-sayi dv-vurgu">' . h($ds) . '</strong></div>';
        $kartlar .= '<div><span>Efektif Alış</span><strong class="dv-sayi">' . h($ea) . '</strong></div>';
        $kartlar .= '<div><span>Efektif Satış</span><strong class="dv-sayi">' . h($es) . '</strong></div>';
        $kartlar .= '</div>';

        if ($eylemSutun) {
            $kartlar .= '<div class="dv-kart-alt">'
                . '<div class="dv-kart-capraz">Çapraz: ' . doviz_ui_capraz($r) . '</div>'
                . '<div class="dv-satir-eylem">' . $eylemHtml . '</div>'
                . '</div>';
        }
        $kartlar .= '</article>';
    }

    $tablo   .= '</tbody></table></div>';
    $kartlar .= '</div>';

    $aramaBos = '<div class="dv-bos dv-arama-bos" data-dv-arama-bos hidden><h3>Eşleşen döviz bulunamadı</h3><p>Arama metnini temizleyerek tüm kurları görebilirsiniz.</p></div>';

    return $tablo . $kartlar . $aramaBos;
}

/** Günlük kurlar boş durum kartı. */
function doviz_ui_gunluk_bos(string $tarih, bool $yazabilir, bool $tumu, string $bugun = '', ?string $sonTarih = null): string
{
    $h = '<div class="dv-bos">';
    $h .= '<h2>' . h(doviz_ui_tarih_tr($tarih)) . ' tarihi için kayıtlı kur yok</h2>';
    if ($bugun !== '' && $tarih === $bugun) {
        $h .= '<p>TCMB günlük kurları iş günleri saat <strong>15:30</strong>\'da yayımlanır; hafta sonu ve resmî tatillerde bülten yayımlanmaz. Bugünün bülteni henüz yayımlanmadıysa en güncel bülteni görüntüleyebilir veya manuel kur ekleyebilirsiniz.</p>';
    } elseif (!$tumu) {
        $h .= '<p>Seçili tarihte aktif para birimlerine ait kur kaydı bulunamadı. TCMB\'den kurları çekebilir veya tüm TCMB kurlarını görüntüleyebilirsiniz.</p>';
    } else {
        $h .= '<p>Seçili tarihte henüz kur verisi kaydedilmemiş. TCMB\'den anında çekebilir ya da manuel kur ekleyebilirsiniz.</p>';
    }
    $h .= '<div class="dv-bos-eylem">';
    if ($sonTarih !== null && $sonTarih !== '' && $sonTarih !== $tarih) {
        $h .= '<a class="btn btn-primary dv-btn" href="gunluk_kurlar.php?tarih=' . rawurlencode($sonTarih) . ($tumu ? '&tumu=1' : '') . '">En Güncel Bültene Git (' . h(doviz_ui_tarih_tr($sonTarih)) . ')</a>';
    }
    if ($yazabilir) {
        $btnSinif = ($sonTarih !== null && $sonTarih !== '' && $sonTarih !== $tarih) ? 'btn dv-btn' : 'btn btn-primary dv-btn';
        $h .= '<button type="button" class="' . $btnSinif . '" data-dv-tcmb-cek data-tarih="' . h($tarih) . '">&#8635; TCMB\'den Çek</button>';
        $h .= '<button type="button" class="btn dv-btn" data-dv-kur-ekle>+ Manuel Kur Ekle</button>';
    }
    if (!$tumu) {
        $h .= '<a class="btn dv-btn" href="gunluk_kurlar.php?tarih=' . rawurlencode($tarih) . '&tumu=1">Tüm TCMB Kurlarını Göster</a>';
    }
    $h .= '</div></div>';
    return $h;
}

/** Tarihçe (Gün Gün) görünümü. */
function doviz_ui_gecmis_bolum(array $gecmis, array $tanimlar, string $kod, string $bas, string $bit): string
{
    $kodOpt = '<option value="">Tüm Dövizler</option>';
    foreach ($tanimlar as $t) {
        $tk = (string)$t['kod'];
        if ($tk === 'TRY') continue;
        $kodOpt .= '<option value="' . h($tk) . '"' . ($tk === $kod ? ' selected' : '') . '>'
            . h($tk . ' — ' . (string)$t['ad']) . '</option>';
    }

    $h = '<section class="dv-gecmis-filtre" aria-label="Tarihçe filtresi">';
    $h .= '<form method="get" action="gunluk_kurlar.php" class="dv-gecmis-form">';
    $h .= '<input type="hidden" name="gorunum" value="gecmis">';
    $h .= '<label class="dv-alan"><span>Döviz</span><select name="kod" class="dv-select">' . $kodOpt . '</select></label>';
    $h .= '<label class="dv-alan"><span>Başlangıç</span><input type="date" name="bas" value="' . h($bas) . '" class="dv-input"></label>';
    $h .= '<label class="dv-alan"><span>Bitiş</span><input type="date" name="bit" value="' . h($bit) . '" class="dv-input"></label>';
    $h .= '<div class="dv-gecmis-eylem"><button type="submit" class="btn btn-primary dv-btn">Filtrele</button>';
    if ($kod !== '' || $bas !== '' || $bit !== '') {
        $h .= '<a class="btn dv-btn" href="gunluk_kurlar.php?gorunum=gecmis">Temizle</a>';
    }
    $h .= '</div></form></section>';

    if (!$gecmis) {
        return $h . '<div class="dv-bos"><h2>Kayıtlı geçmiş kur bulunamadı</h2>'
            . '<p>Seçilen filtrelere uygun kur kaydı yok. "Son 7 İş Günü" düğmesiyle TCMB arşivinden geçmiş kurları çekebilirsiniz.</p></div>';
    }

    $tablo = '<div class="dv-tablo-sar"><table class="dv-tablo"><thead><tr>'
        . '<th>Tarih</th><th>Döviz</th><th class="dv-sag">Birim</th>'
        . '<th class="dv-sag">Döviz Alış</th><th class="dv-sag">Döviz Satış</th>'
        . '<th class="dv-sag">Efektif Alış</th><th class="dv-sag">Efektif Satış</th>'
        . '<th>Bülten No</th><th>Kaynak</th>'
        . '</tr></thead><tbody>';

    $kartlar = '<div class="dv-kartlar">';

    foreach ($gecmis as $r) {
        $rKod    = (string)$r['kod'];
        $rIsim   = (string)($r['tanim_ad'] ?? $rKod);
        $rTarih  = (string)$r['tarih'];
        $birim   = max(1, (int)($r['birim'] ?? 1));
        $ondalik = max(2, min(6, (int)($r['tanim_ondalik'] ?? 4)));
        $bulten  = (string)($r['bulten_no'] ?? '');
        $kaynak  = (string)($r['kaynak'] ?? 'tcmb');

        $da = doviz_bicim($r['doviz_alis'] ?? null, $ondalik);
        $ds = doviz_bicim($r['doviz_satis'] ?? null, $ondalik);
        $ea = doviz_bicim($r['efektif_alis'] ?? null, $ondalik);
        $es = doviz_bicim($r['efektif_satis'] ?? null, $ondalik);

        $gunLink = 'gunluk_kurlar.php?tarih=' . rawurlencode($rTarih);

        $tablo .= '<tr>'
            . '<td><a href="' . h($gunLink) . '" class="dv-tarih-link dv-sayi">' . h(doviz_ui_tarih_tr($rTarih)) . '</a></td>'
            . '<td><strong class="dv-kod">' . h($rKod) . '</strong> <span class="dv-soluk">' . h($rIsim) . '</span></td>'
            . '<td class="dv-sag dv-sayi">' . $birim . '</td>'
            . '<td class="dv-sag dv-sayi">' . h($da) . '</td>'
            . '<td class="dv-sag dv-sayi dv-vurgu">' . h($ds) . '</td>'
            . '<td class="dv-sag dv-sayi">' . h($ea) . '</td>'
            . '<td class="dv-sag dv-sayi">' . h($es) . '</td>'
            . '<td class="dv-sayi">' . ($bulten !== '' ? h($bulten) : '<span class="dv-soluk">—</span>') . '</td>'
            . '<td>' . doviz_ui_kaynak_rozet($kaynak) . '</td>'
            . '</tr>';

        $kartlar .= '<article class="dv-kart">';
        $kartlar .= '<div class="dv-kart-ust">'
            . '<div><a href="' . h($gunLink) . '" class="dv-tarih-link dv-sayi">' . h(doviz_ui_tarih_tr($rTarih)) . '</a> · <strong class="dv-kod">' . h($rKod) . '</strong>'
            . ($birim > 1 ? ' <span class="dv-birim-rozet">' . $birim . '</span>' : '') . '</div>'
            . doviz_ui_kaynak_rozet($kaynak)
            . '</div>';
        $kartlar .= '<div class="dv-kart-grid">'
            . '<div><span>Döviz Alış</span><strong class="dv-sayi">' . h($da) . '</strong></div>'
            . '<div><span>Döviz Satış</span><strong class="dv-sayi dv-vurgu">' . h($ds) . '</strong></div>'
            . '<div><span>Efektif Alış</span><strong class="dv-sayi">' . h($ea) . '</strong></div>'
            . '<div><span>Efektif Satış</span><strong class="dv-sayi">' . h($es) . '</strong></div>'
            . '</div>';
        if ($bulten !== '') {
            $kartlar .= '<div class="dv-kart-alt"><span class="dv-soluk">Bülten: ' . h($bulten) . '</span></div>';
        }
        $kartlar .= '</article>';
    }

    $tablo   .= '</tbody></table></div>';
    $kartlar .= '</div>';

    return $h . $tablo . $kartlar;
}

/** Manuel Kur Ekle / Düzenle modalı (#dvModalKur). */
function doviz_ui_modal_kur(string $tarih, array $tanimlar, string $csrf, string $geriUrl): string
{
    $kodOpt = '';
    foreach ($tanimlar as $t) {
        $tk = (string)$t['kod'];
        if ($tk === 'TRY') continue;
        $kodOpt .= '<option value="' . h($tk) . '" data-birim="' . (int)($t['birim'] ?? 1) . '">'
            . h($tk . ' — ' . (string)$t['ad']) . '</option>';
    }

    return '<div class="pm-overlay" id="dvModalKur" hidden>'
        . '<div class="pm-dialog dv-dialog" role="dialog" aria-modal="true" aria-labelledby="dvModalKurBaslik">'
        . '<div class="pm-header">'
        . '<h2 class="pm-title" id="dvModalKurBaslik">Manuel Kur Ekle</h2>'
        . '<button type="button" class="pm-close" data-dv-kapat aria-label="Kapat">&#10005;</button>'
        . '</div>'
        . '<form method="post" action="doviz_kaydet.php" data-dv-form="kur_kaydet" novalidate>'
        . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
        . '<input type="hidden" name="islem" value="kur_kaydet">'
        . '<input type="hidden" name="geri" value="' . h($geriUrl) . '">'
        . '<div class="pm-body">'
        . '<div class="flash flash-error dv-form-hata" role="alert" hidden></div>'
        . '<div class="dv-form-grid">'
        . '<label class="dv-alan"><span>Tarih <b class="dv-zorunlu" aria-hidden="true">*</b></span><input type="date" name="tarih" value="' . h($tarih) . '" required class="dv-input"></label>'
        . '<label class="dv-alan"><span>Döviz Kodu <b class="dv-zorunlu" aria-hidden="true">*</b></span><select name="kod" required class="dv-select" data-dv-kur-kod>' . $kodOpt . '</select></label>'
        . '<label class="dv-alan"><span>Birim</span><input type="number" name="birim" value="1" min="1" max="10000" inputmode="numeric" class="dv-input dv-sayi"></label>'
        . '<div class="dv-alan dv-alan--bos" aria-hidden="true"></div>'
        . '<label class="dv-alan"><span>Döviz Alış</span><input type="text" name="doviz_alis" inputmode="decimal" placeholder="0,0000" autocomplete="off" class="dv-input dv-sayi"></label>'
        . '<label class="dv-alan"><span>Döviz Satış</span><input type="text" name="doviz_satis" inputmode="decimal" placeholder="0,0000" autocomplete="off" class="dv-input dv-sayi"></label>'
        . '<label class="dv-alan"><span>Efektif Alış</span><input type="text" name="efektif_alis" inputmode="decimal" placeholder="0,0000" autocomplete="off" class="dv-input dv-sayi"></label>'
        . '<label class="dv-alan"><span>Efektif Satış</span><input type="text" name="efektif_satis" inputmode="decimal" placeholder="0,0000" autocomplete="off" class="dv-input dv-sayi"></label>'
        . '</div>'
        . '<p class="dv-ipucu">En az bir kur alanı doldurulmalıdır. Manuel girilen kurlar TCMB otomatik güncellemesinde korunur.</p>'
        . '</div>'
        . '<div class="pm-footer">'
        . '<button type="button" class="btn" data-dv-kapat>Vazgeç</button>'
        . '<button type="submit" class="btn btn-primary">Kaydet</button>'
        . '</div>'
        . '</form></div></div>';
}

// ── Döviz Tanımları Sayfası ─────────────────────────────────────────────────

/**
 * Döviz Tanımları sayfası gövdesini render eder.
 * @param array<string,mixed> $ctx
 */
function doviz_tanimlar_sayfa_render(array $ctx): string
{
    $tanimlar   = is_array($ctx['tanimlar'] ?? null) ? $ctx['tanimlar'] : [];
    $kurTipleri = is_array($ctx['kur_tipleri'] ?? null) ? $ctx['kur_tipleri'] : [];
    $katalog    = is_array($ctx['katalog'] ?? null) ? $ctx['katalog'] : [];
    $yonetici   = !empty($ctx['yonetici']);
    $csrf       = (string)($ctx['csrf'] ?? '');

    $mevcutKodlar = [];
    foreach ($tanimlar as $t) {
        $mevcutKodlar[(string)$t['kod']] = true;
    }

    $jsKatalog = [];
    foreach ($katalog as $kk => $kv) {
        $jsKatalog[$kk] = [
            'kod'    => (string)$kk,
            'isim'   => (string)$kv['ad'],
            'sembol' => (string)$kv['sembol'],
            'birim'  => (int)$kv['birim'],
        ];
    }

    $jsVeri = [
        'sayfa'   => 'tanimlar',
        'csrf'    => $csrf,
        'geri'    => 'doviz_tanimlari.php',
        'katalog' => $jsKatalog,
    ];

    $h = '<div class="doviz dv-tanimlar" id="doviz" data-sayfa="tanimlar">';

    // Üst şerit
    $h .= '<header class="dv-ust">';
    $h .= '<div class="dv-ust-baslik">';
    $h .= '<div class="dv-baslik-satir">';
    $h .= '<h1 class="dv-sayfa-baslik">Döviz Tanımları</h1>';
    $h .= '<span class="dv-rozet dv-rozet--bulten">' . count($tanimlar) . ' para birimi</span>';
    $h .= '</div>';
    $h .= '<nav class="dv-sekmeler" aria-label="Döviz sayfaları">';
    $h .= '<a class="dv-sekme" href="gunluk_kurlar.php">Günlük Kurlar</a>';
    $h .= '<a class="dv-sekme" href="doviz_tanimlari.php" aria-current="page">Döviz Tanımları</a>';
    $h .= '</nav>';
    $h .= '</div>';

    $h .= '<div class="dv-eylemler">';
    if ($yonetici) {
        $katOpt = '<option value="">TCMB Kataloğundan Ekle...</option>';
        foreach ($katalog as $kk => $kv) {
            if (isset($mevcutKodlar[$kk])) continue;
            $katOpt .= '<option value="' . h($kk) . '">' . h($kk . ' — ' . (string)$kv['ad'] . ' (' . (string)$kv['sembol'] . ')') . '</option>';
        }
        $h .= '<label class="dv-sr" for="dvKatalogSec">TCMB kataloğundan hızlı ekle</label>';
        $h .= '<select id="dvKatalogSec" class="dv-select dv-katalog-sec" data-dv-katalog-sec>' . $katOpt . '</select>';
        $h .= '<button type="button" class="btn btn-primary dv-btn" id="dvYeniTanimBtn" data-dv-tanim-ekle>+ Yeni Döviz Ekle</button>';
    }
    $h .= '<a class="btn dv-btn" href="gunluk_kurlar.php">&lsaquo; Günlük Kurlar</a>';
    $h .= '</div>';
    $h .= '</header>';

    // Liste (Tablo + Mobil Kartlar)
    if (!$tanimlar) {
        $h .= '<div class="dv-bos"><h2>Henüz döviz tanımı yok</h2><p>Yukarıdan yeni para birimi tanımlayabilirsiniz.</p></div>';
    } else {
        $tablo = '<div class="dv-tablo-sar"><table class="dv-tablo"><thead><tr>'
            . '<th>Döviz</th>'
            . '<th class="dv-sag">Birim</th>'
            . '<th class="dv-sag">Ondalık</th>'
            . '<th>Varsayılan Kur Tipi</th>'
            . '<th>TCMB Senkron</th>'
            . '<th>Aktif</th>'
            . '<th class="dv-sag">Sıra</th>'
            . ($yonetici ? '<th class="dv-sag">İşlemler</th>' : '')
            . '</tr></thead><tbody>';

        $kartlar = '<div class="dv-kartlar">';

        foreach ($tanimlar as $t) {
            $id        = (int)$t['id'];
            $kod       = (string)$t['kod'];
            $isim      = (string)$t['ad'];
            $sembol    = (string)($t['sembol'] ?? $kod);
            $birim     = (int)($t['birim'] ?? 1);
            $ondalik   = (int)($t['ondalik'] ?? 4);
            $tip       = (string)($t['varsayilan_kur_tipi'] ?? 'doviz_satis');
            $tipEtiket = (string)($kurTipleri[$tip] ?? $tip);
            $tcmbAktif = (int)($t['tcmb_aktif'] ?? 0) === 1;
            $aktif     = (int)($t['aktif'] ?? 0) === 1;
            $sira      = (int)($t['sira'] ?? 0);
            $kurSayisi = (int)($t['kur_sayisi'] ?? 0);
            $tryMi     = $kod === 'TRY';

            $tJson = json_encode([
                'id'                  => $id,
                'kod'                 => $kod,
                'isim'                => $isim,
                'sembol'              => $sembol,
                'birim'               => $birim,
                'ondalik'             => $ondalik,
                'varsayilan_kur_tipi' => $tip,
                'tcmb_aktif'          => $tcmbAktif ? 1 : 0,
                'aktif'               => $aktif ? 1 : 0,
                'sira'                => $sira,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // Switchler
            if ($yonetici) {
                $tcmbSw = '<label class="dv-switch' . ($tryMi ? ' dv-switch--kilit' : '') . '">'
                    . '<input type="checkbox" role="switch" data-dv-durum="tcmb_aktif" data-id="' . $id . '"'
                    . ($tcmbAktif ? ' checked' : '') . ($tryMi ? ' disabled' : '')
                    . ' aria-label="' . h($kod) . ' TCMB senkron">'
                    . '<span class="dv-switch-ray" aria-hidden="true"></span></label>';

                $aktifSw = '<label class="dv-switch' . ($tryMi ? ' dv-switch--kilit' : '') . '">'
                    . '<input type="checkbox" role="switch" data-dv-durum="aktif" data-id="' . $id . '"'
                    . ($aktif ? ' checked' : '') . ($tryMi ? ' disabled' : '')
                    . ' aria-label="' . h($kod) . ' aktif">'
                    . '<span class="dv-switch-ray" aria-hidden="true"></span></label>';
            } else {
                $tcmbSw  = '<span class="dv-rozet ' . ($tcmbAktif ? 'dv-rozet--tcmb' : 'dv-rozet--pasif') . '">' . ($tcmbAktif ? 'Açık' : 'Kapalı') . '</span>';
                $aktifSw = '<span class="dv-rozet ' . ($aktif ? 'dv-rozet--aktif' : 'dv-rozet--pasif') . '">' . ($aktif ? 'Aktif' : 'Pasif') . '</span>';
            }

            $eylemHtml = '';
            if ($yonetici) {
                $eylemHtml .= '<button type="button" class="btn btn-sm dv-ikon-btn" data-dv-tanim-duzenle="' . h((string)$tJson) . '" aria-label="' . h($kod) . ' düzenle" title="Düzenle">&#9998;</button>';
                if (!$tryMi && $kurSayisi === 0) {
                    $eylemHtml .= '<button type="button" class="btn btn-sm dv-ikon-btn dv-tehlike" data-dv-tanim-sil="' . $id . '" data-dv-kod="' . h($kod) . '" aria-label="' . h($kod) . ' sil" title="Sil">&#128465;</button>';
                }
            }

            $rozetEk = $tryMi ? ' <span class="dv-rozet dv-rozet--ana">Ana Para</span>' : '';
            $kurBilgi = $kurSayisi > 0 ? '<div class="dv-alt-not">' . $kurSayisi . ' kur kaydı</div>' : '';

            $tablo .= '<tr class="' . ($aktif ? '' : 'dv-satir--pasif') . '">'
                . '<td><div class="dv-hucre-kimlik"><span class="dv-sembol-kutu dv-sembol-kutu--sm" aria-hidden="true">' . h($sembol) . '</span>'
                . '<div><strong class="dv-kod">' . h($kod) . '</strong>' . $rozetEk
                . '<div class="dv-isim">' . h($isim) . '</div>' . $kurBilgi . '</div></div></td>'
                . '<td class="dv-sag dv-sayi">' . $birim . '</td>'
                . '<td class="dv-sag dv-sayi">' . $ondalik . '</td>'
                . '<td>' . h($tipEtiket) . '</td>'
                . '<td>' . $tcmbSw . '</td>'
                . '<td>' . $aktifSw . '</td>'
                . '<td class="dv-sag dv-sayi">' . $sira . '</td>'
                . ($yonetici ? '<td class="dv-sag"><div class="dv-satir-eylem">' . $eylemHtml . '</div></td>' : '')
                . '</tr>';

            $kartlar .= '<article class="dv-kart' . ($aktif ? '' : ' dv-satir--pasif') . '">';
            $kartlar .= '<div class="dv-kart-ust">'
                . '<div class="dv-hucre-kimlik"><span class="dv-sembol-kutu dv-sembol-kutu--sm" aria-hidden="true">' . h($sembol) . '</span>'
                . '<div><strong class="dv-kod">' . h($kod) . '</strong>' . $rozetEk
                . '<div class="dv-isim">' . h($isim) . '</div></div></div>'
                . '<span class="dv-soluk dv-sayi">Sıra: ' . $sira . '</span>'
                . '</div>';
            $kartlar .= '<div class="dv-kart-grid">'
                . '<div><span>Birim / Ondalık</span><strong class="dv-sayi">' . $birim . ' / ' . $ondalik . ' hane</strong></div>'
                . '<div><span>Varsayılan Tip</span><strong>' . h($tipEtiket) . '</strong></div>'
                . '<div><span>TCMB Senkron</span><div>' . $tcmbSw . '</div></div>'
                . '<div><span>Aktif</span><div>' . $aktifSw . '</div></div>'
                . '</div>';
            if ($yonetici) {
                $kartlar .= '<div class="dv-kart-alt">'
                    . '<span class="dv-soluk">' . ($kurSayisi > 0 ? $kurSayisi . ' kur kaydı' : 'Kur kaydı yok') . '</span>'
                    . '<div class="dv-satir-eylem">' . $eylemHtml . '</div>'
                    . '</div>';
            }
            $kartlar .= '</article>';
        }

        $tablo   .= '</tbody></table></div>';
        $kartlar .= '</div>';
        $h .= $tablo . $kartlar;
    }

    if ($yonetici) {
        $h .= doviz_ui_modal_tanim($kurTipleri, $csrf);
    }

    $h .= doviz_json_script('dvVeri', $jsVeri);
    $h .= '</div>';

    return $h;
}

/** Para Birimi Tanımı Ekle / Düzenle modalı (#dvModalTanim). */
function doviz_ui_modal_tanim(array $kurTipleri, string $csrf): string
{
    $tipOpt = '';
    foreach ($kurTipleri as $tk => $tEtiket) {
        $tipOpt .= '<option value="' . h($tk) . '">' . h($tEtiket) . '</option>';
    }

    return '<div class="pm-overlay" id="dvModalTanim" hidden>'
        . '<div class="pm-dialog dv-dialog" role="dialog" aria-modal="true" aria-labelledby="dvModalTanimBaslik">'
        . '<div class="pm-header">'
        . '<h2 class="pm-title" id="dvModalTanimBaslik">Yeni Döviz Ekle</h2>'
        . '<button type="button" class="pm-close" data-dv-kapat aria-label="Kapat">&#10005;</button>'
        . '</div>'
        . '<form method="post" action="doviz_kaydet.php" data-dv-form="tanim_kaydet" novalidate>'
        . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
        . '<input type="hidden" name="islem" value="tanim_kaydet">'
        . '<input type="hidden" name="id" value="0">'
        . '<input type="hidden" name="geri" value="doviz_tanimlari.php">'
        . '<div class="pm-body">'
        . '<div class="flash flash-error dv-form-hata" role="alert" hidden></div>'
        . '<div class="dv-form-grid">'
        . '<label class="dv-alan"><span>ISO Kod <b class="dv-zorunlu" aria-hidden="true">*</b></span><input type="text" name="kod" maxlength="10" required autocomplete="off" placeholder="USD" class="dv-input dv-ustharf"></label>'
        . '<label class="dv-alan"><span>Sembol</span><input type="text" name="sembol" maxlength="10" autocomplete="off" placeholder="$" class="dv-input"></label>'
        . '<label class="dv-alan dv-span2"><span>Para Birimi İsmi <b class="dv-zorunlu" aria-hidden="true">*</b></span><input type="text" name="ad" maxlength="100" required autocomplete="off" placeholder="ABD Doları" class="dv-input"></label>'
        . '<label class="dv-alan"><span>Birim</span><input type="number" name="birim" value="1" min="1" max="10000" inputmode="numeric" class="dv-input dv-sayi"></label>'
        . '<label class="dv-alan"><span>Ondalık Basamak</span><input type="number" name="ondalik" value="4" min="2" max="6" inputmode="numeric" class="dv-input dv-sayi"></label>'
        . '<label class="dv-alan"><span>Varsayılan Kur Tipi</span><select name="varsayilan_kur_tipi" class="dv-select">' . $tipOpt . '</select></label>'
        . '<label class="dv-alan"><span>Sıra</span><input type="number" name="sira" value="90" min="0" max="9999" inputmode="numeric" class="dv-input dv-sayi"></label>'
        . '</div>'
        . '<div class="dv-form-switchler">'
        . '<label class="dv-check-satir"><input type="checkbox" name="tcmb_aktif" value="1" checked> <span>TCMB Otomatik Senkron</span></label>'
        . '<label class="dv-check-satir"><input type="checkbox" name="aktif" value="1" checked> <span>Aktif Para Birimi</span></label>'
        . '</div>'
        . '</div>'
        . '<div class="pm-footer">'
        . '<button type="button" class="btn" data-dv-kapat>Vazgeç</button>'
        . '<button type="submit" class="btn btn-primary">Kaydet</button>'
        . '</div>'
        . '</form></div></div>';
}
