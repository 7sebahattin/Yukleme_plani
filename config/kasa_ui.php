<?php
// =========================================================
// config/kasa_ui.php — Kasa render yardımcıları (include-only; yalnız fonksiyon bildirir)
//
// Sayfalar (kasa_fisleri / kasa_tanimlari / kasa_cikti) veriyi config/kasa_core.php'den okur, işaretlemeyi BURADAN alır.
// Sınıf öneki `ks-`, kök `.kasa#kasa`, ID/data-* kancaları `ks*` (assets/kasa.js sözleşmesi). Burada SORGU YAZILMAZ (yalnız kasa_core
// okuyucuları + cari_core okuyucuları), hiçbir yazma yoktur. HTML'e giren HER dinamik değer h() ile kaçışlanır.
// Para kuruş tam sayıdır; para birimleri ASLA toplanmaz (her biri ayrı satır/rozet).
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

// ── Varlıklar / ortak parçalar ──────────────────────────────────────────────

function kasa_assets(): void
{
    echo '<link rel="stylesheet" href="assets/kasa.css?v=' . (int)@filemtime(dirname(__DIR__) . '/assets/kasa.css') . '">' . "\n";
}

function kasa_scripts(): void
{
    echo '<script src="assets/kasa.js?v=' . (int)@filemtime(dirname(__DIR__) . '/assets/kasa.js') . '"></script>' . "\n";
}

/** Üst sekme şeridi. $aktif: fisler|tanimlar */
function kasa_ust_menu(string $aktif): string
{
    $s = ['fisler' => ['kasa_fisleri.php', 'Kasa Fişleri'], 'tanimlar' => ['kasa_tanimlari.php', 'Kasa Tanımları']];
    $o = '<nav class="ks-sekmeler" aria-label="Kasa">';
    foreach ($s as $k => [$url, $ad]) $o .= '<a class="ks-sekme" href="' . h($url) . '"' . ($k === $aktif ? ' aria-current="page"' : '') . '>' . h($ad) . '</a>';
    return $o . '<a class="ks-sekme ks-sekme--dis" href="cari_kartlari.php">Cari İşlemler</a></nav>';
}

/**
 * Şema yok kartı (sekme şeridi YOK). $eksik: kasa_eksik_semalar() — sıra: Cari önce, Kasa sonra.
 * @param list<array{ad:string,kart:string}> $eksik
 */
function kasa_sema_uyari(array $eksik): string
{
    $lnk = '';
    foreach ($eksik as $e) $lnk .= '<a class="btn btn-primary" data-sema-eksik="' . h($e['kart']) . '" href="migrate.php#' . h($e['kart']) . '">Şema Migrasyon &rsaquo; ' . h($e['ad']) . '</a> ';
    return '<div class="kasa"><div class="ks-uyari" data-ks-sema role="alert"><span class="ks-uyari-ikon" aria-hidden="true">&#9888;</span><div>'
        . '<h2>Kasa tabloları kurulmamış</h2>'
        . '<p>Kasa modülünün veritabanı tabloları bu sunucuda henüz oluşturulmadı. Yönetici: Şema Migrasyon sayfasında önce <b>Cari İşlemler</b>, ardından <b>Kasa</b> kartını çalıştırın.</p>'
        . '<div class="ks-uyari-eylem">' . $lnk . '</div></div></div></div>';
}

function kasa_bos(string $baslik, string $ack, string $eylem = '', string $etiket = 'h2'): string
{
    return '<div class="ks-bos"><' . $etiket . '>' . h($baslik) . '</' . $etiket . '><p>' . h($ack) . '</p>'
        . ($eylem !== '' ? '<div class="ks-bos-eylem">' . $eylem . '</div>' : '') . '</div>';
}

/** <script type=application/json> içine güvenli JSON (</script> kaçışı). */
function kasa_json_script(string $id, mixed $v): string
{
    return '<script type="application/json" id="' . h($id) . '">' . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
}

function kasa_json_attr(mixed $v): string
{
    return h((string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function kasa_tarih_tr(string $d): string
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) ? $m[3] . '.' . $m[2] . '.' . $m[1] : $d;
}

/** "1.234,56 ₺" */
function kasa_para(int $kurus, string $pb): string { return cari_tutar_bicim($kurus, $pb); }

/** Bakiye metni (eksi → kırmızı sınıf). */
function kasa_bakiye_html(int $kurus, string $pb, string $sinif = ''): string
{
    return '<span class="ks-tutar' . ($kurus < 0 ? ' ks-eksi' : '') . ($sinif !== '' ? ' ' . $sinif : '') . '">' . h(kasa_para($kurus, $pb)) . '</span>';
}

/** Hareket türü rozeti (renk + işaret + metin). */
function kasa_tur_rozet(string $tur, string $yon = ''): string
{
    $b = kasa_tur_bilgi($tur, true);
    $ad = $b['etiket'] ?? $tur;
    $yon = $yon !== '' ? $yon : ($b['yon'] ?? '');
    if ($tur === 'virman') return '<span class="ks-rozet ks-rozet--virman"><span class="ks-yon" aria-hidden="true">&#8644;</span> ' . h($ad) . '</span>';
    if ($yon === 'C') return '<span class="ks-rozet ks-rozet--cikis"><span class="ks-yon" aria-hidden="true">&#9660;</span> ' . h($ad) . '</span>';
    return '<span class="ks-rozet ks-rozet--giris"><span class="ks-yon" aria-hidden="true">&#9650;</span> ' . h($ad) . '</span>';
}

function kasa_tip_rozet(string $tip): string
{
    return '<span class="ks-rozet ks-rozet--tip">' . ($tip === 'banka' ? '&#127974; ' : '&#128181; ') . h(kasa_tipler()[$tip] ?? $tip) . '</span>';
}

/** Sayfa başına fiş (liste). */
function kasa_ui_sayfa_boyu(): int { return 100; }

// ── Süzgeç ──────────────────────────────────────────────────────────────────

/** Süzgeç değerleri (GET). @return array{kasa:int,ara:string,tur:string,durum:string,donem:string,bas:string,bit:string,sayfa:int} */
function kasa_filtre(): array
{
    $durum = (string)($_GET['durum'] ?? 'gecerli');
    if (!in_array($durum, ['tumu', 'gecerli', 'iptal'], true)) $durum = 'gecerli';
    $donem = (string)($_GET['donem'] ?? 'tumu');
    if (!in_array($donem, ['tumu', 'bugun', '30g', 'bu_ay', 'gecen_ay', 'ozel'], true)) $donem = 'tumu';
    $tur = (string)($_GET['tur'] ?? '');
    if ($tur !== '' && kasa_tur_bilgi($tur, true) === null) $tur = '';
    return ['kasa' => max(0, (int)($_GET['kasa'] ?? 0)), 'ara' => mb_substr(trim((string)($_GET['ara'] ?? '')), 0, 100), 'tur' => $tur, 'durum' => $durum, 'donem' => $donem,
        'bas' => $donem === 'ozel' ? (kasa_tarih_coz($_GET['bas'] ?? '') ?? '') : '', 'bit' => $donem === 'ozel' ? (kasa_tarih_coz($_GET['bit'] ?? '') ?? '') : '',
        'sayfa' => max(1, (int)($_GET['sayfa'] ?? 1))];
}

/** Süzgeç → sorgu dizgesi (varsayılanlar atılır). @param array<string,mixed> $ek ek/ezen parametreler (null → çıkar) */
function kasa_sorgu(array $F, array $ek = []): string
{
    $q = ['kasa' => $F['kasa'] ?: '', 'ara' => $F['ara'], 'tur' => $F['tur'], 'durum' => $F['durum'] !== 'gecerli' ? $F['durum'] : '', 'donem' => $F['donem'] !== 'tumu' ? $F['donem'] : '',
        'bas' => $F['bas'], 'bit' => $F['bit'], 'sayfa' => $F['sayfa'] > 1 ? $F['sayfa'] : ''];
    foreach ($ek as $k => $v) $q[$k] = $v;
    $q = array_filter($q, static fn($x) => $x !== '' && $x !== null && $x !== 0);
    return http_build_query($q);
}

function kasa_url(array $F, array $ek = []): string
{
    $s = kasa_sorgu($F, $ek);
    return 'kasa_fisleri.php' . ($s !== '' ? '?' . $s : '');
}

// ── Sol sütun: kasa kartları ────────────────────────────────────────────────

/** Para birimi başına genel özet (aktif kasalar; pb'ler ASLA toplanmaz). @param array<string,array{toplam:int,nakit:int,banka:int,kasa_say:int}> $ozet */
function kasa_genel_ozet_html(array $ozet): string
{
    if (!$ozet) return '';
    $o = '<div class="ks-genel" role="group" aria-label="Genel kasa toplamı (para birimleri ayrı)">';
    foreach ($ozet as $pb => $x) {
        $o .= '<div class="ks-genel-kutu" data-pb="' . h((string)$pb) . '"><div class="ks-genel-ad">Toplam · ' . h((string)$pb) . ' <small>' . (int)$x['kasa_say'] . ' kasa</small></div>'
            . '<div class="ks-genel-deger">' . kasa_bakiye_html((int)$x['toplam'], (string)$pb) . '</div>'
            . '<div class="ks-genel-alt"><span>Nakit ' . h(kasa_para((int)$x['nakit'], (string)$pb)) . '</span><span>Banka ' . h(kasa_para((int)$x['banka'], (string)$pb)) . '</span></div></div>';
    }
    return $o . '</div>';
}

function kasa_kasa_satiri(array $k, array $F, bool $secili): string
{
    $pasif = (int)$k['aktif'] !== 1;
    $pb = (string)$k['para_birimi'];
    $alt = array_filter([(string)$k['kod'], $k['tip'] === 'banka' ? (string)($k['banka_adi'] ?? '') : '', $pb], static fn($x) => $x !== '');
    return '<li><a class="ks-kasa' . ($pasif ? ' ks-kasa--pasif' : '') . '" href="' . h('kasa_fisleri.php?kasa=' . (int)$k['id']) . '" data-id="' . (int)$k['id'] . '"' . ($secili ? ' aria-current="true"' : '') . '>'
        . '<span class="ks-kasa-ust">' . h($k['ad']) . '</span><span class="ks-kasa-alt">' . h(implode(' · ', $alt)) . '</span>'
        . '<span class="ks-kasa-sag">' . kasa_bakiye_html((int)$k['bakiye'], $pb) . ($pasif ? '<span class="ks-rozet ks-rozet--pasif">Pasif</span>' : ($k['tip'] === 'banka' ? '<span class="ks-rozet ks-rozet--tip">Banka</span>' : '')) . '</span></a></li>';
}

/**
 * Sol sütun (≥1024) + mobil/tablet kasa seçici (<1024): aynı veri iki yerde.
 * @param list<array> $kartlar kasa_kart_listesi satırları
 */
function kasa_sol_html(array $kartlar, array $ozet, array $F, ?array $secili): string
{
    $sid = $secili !== null ? (int)$secili['id'] : 0;
    $liste = '<li><a class="ks-kasa ks-kasa--tumu" href="kasa_fisleri.php" data-id="0"' . ($sid === 0 ? ' aria-current="true"' : '') . '><span class="ks-kasa-ust">Tüm kasalar</span><span class="ks-kasa-alt">' . count($kartlar) . ' kasa</span><span class="ks-kasa-sag"></span></a></li>';
    foreach ($kartlar as $k) $liste .= kasa_kasa_satiri($k, $F, (int)$k['id'] === $sid);
    $opt = '<option value="">Tüm kasalar</option>';
    foreach ($kartlar as $k) $opt .= '<option value="' . (int)$k['id'] . '"' . ((int)$k['id'] === $sid ? ' selected' : '') . '>' . h($k['ad'] . ' (' . $k['para_birimi'] . ')' . ((int)$k['aktif'] !== 1 ? ' — pasif' : '')) . '</option>';
    $sec = '<form method="get" action="kasa_fisleri.php" class="ks-kasa-sec" role="search"><label class="ks-sr" for="ksKasaSec">Kasa seçin</label>'
        . '<select id="ksKasaSec" name="kasa" data-oto-gonder aria-label="Kasa">' . $opt . '</select><noscript><button type="submit" class="btn btn-sm">Göster</button></noscript></form>';
    return '<aside class="ks-sol" id="ksSol" aria-label="Kasalar">' . kasa_genel_ozet_html($ozet) . $sec
        . '<div class="ks-sol-baslik">Kasalar</div><ul class="ks-kasa-liste" id="ksKasaListe">' . $liste . '</ul>'
        . '<div class="ks-sol-alt"><a href="kasa_tanimlari.php?sekme=kasa">Kasa tanımları &rsaquo;</a></div></aside>';
}

// ── Fiş listesi ─────────────────────────────────────────────────────────────

/** Satır açıklaması: ana metin + ikincil metin. @return array{0:string,1:string} */
function kasa_satir_aciklama(array $r): array
{
    $tur = (string)$r['tur'];
    if ($tur === 'virman') {
        $k = (string)($r['karsi_kasa_ad'] ?? '');
        if ($k === '') return [(string)$r['aciklama'] !== '' ? (string)$r['aciklama'] : 'Virman', ''];   // defter satırlarında karşı kasa adı yoktur
        return [$r['yon'] === 'C' ? '→ ' . $k : '← ' . $k, (string)$r['aciklama']];
    }
    if (!empty($r['cari_unvan'])) return [(string)$r['cari_unvan'], (string)$r['aciklama']];
    if (!empty($r['kalem_ad'])) return [(string)$r['kalem_ad'], (string)$r['aciklama']];
    return [(string)$r['aciklama'] !== '' ? (string)$r['aciklama'] : (kasa_tur_bilgi($tur, true)['etiket'] ?? $tur), ''];
}

/**
 * Fiş tablosu (≥768) + kartları (<768) — aynı veriden iki kez; CSS biri dışında gizler.
 * @param list<array> $satirlar @param bool $yurume yürüyen bakiye sütunu (yalnız tek kasa + geçerli) @param bool $kasaSutun "Tüm kasalar" görünümünde kasa adı
 */
function kasa_liste_html(array $satirlar, bool $yurume, bool $kasaSutun, array $F): string
{
    $trs = ''; $kartlar = '';
    foreach ($satirlar as $r) {
        $iptal = $r['durum'] === 'iptal'; $pb = (string)$r['para_birimi'];
        [$ana, $alt] = kasa_satir_aciklama($r);
        $url = h(kasa_url($F, ['id' => (int)$r['id']]));
        $giris = $r['yon'] === 'G' ? kasa_para((int)$r['tutar_kurus'], $pb) : '';
        $cikis = $r['yon'] === 'C' ? kasa_para((int)$r['tutar_kurus'], $pb) : '';
        $roz = kasa_tur_rozet((string)$r['tur'], (string)$r['yon']) . ($iptal ? ' <span class="ks-rozet ks-rozet--iptal">İPTAL</span>' : '')
            . (!empty($r['kaynak_modul']) ? ' <span class="ks-rozet ks-rozet--kaynak" title="Kaynak belgeden iptal edilir">Kaynak belge</span>' : '');
        $bak = $yurume && isset($r['yurume']) ? kasa_bakiye_html((int)$r['yurume'], $pb) : '';
        $trs .= '<tr class="ks-satir' . ($iptal ? ' ks-iptal' : '') . '" data-id="' . (int)$r['id'] . '" data-belge="' . h($r['belge_no']) . '">'
            . '<td class="ks-tar">' . h(kasa_tarih_tr((string)$r['tarih'])) . '</td>'
            . '<td class="ks-belge"><a href="' . $url . '" data-ks-detay data-id="' . (int)$r['id'] . '">' . h($r['belge_no'] !== '' ? $r['belge_no'] : '#' . $r['id']) . '</a><span class="ks-belge-roz">' . $roz . '</span></td>'
            . ($kasaSutun ? '<td class="ks-kasa-hucre" title="' . h($r['kasa_ad']) . '">' . h($r['kasa_ad']) . '</td>' : '')
            . '<td class="ks-acik"><span class="ks-acik-ana">' . h($ana) . '</span>' . ($alt !== '' ? '<small>' . h($alt) . '</small>' : '') . '</td>'
            . '<td class="ks-sayi ks-giris">' . h($giris) . '</td><td class="ks-sayi ks-cikis">' . h($cikis) . '</td>'
            . ($yurume ? '<td class="ks-sayi">' . $bak . '</td>' : '') . '</tr>';
        $kartlar .= '<article class="ks-hk' . ($iptal ? ' ks-iptal' : '') . '" data-id="' . (int)$r['id'] . '"><a class="ks-hk-baglanti" href="' . $url . '" data-ks-detay data-id="' . (int)$r['id'] . '" aria-label="' . h('Hareket ' . $r['belge_no'] . ' ayrıntısı') . '">'
            . '<span class="ks-hk-ust"><span class="ks-hk-belge">' . h($r['belge_no'] !== '' ? $r['belge_no'] : '#' . $r['id']) . '</span><span class="ks-hk-tutar ' . ($r['yon'] === 'G' ? 'ks-giris' : 'ks-cikis') . '">' . ($r['yon'] === 'G' ? '+' : '&minus;') . h(kasa_para((int)$r['tutar_kurus'], $pb)) . '</span></span>'
            . '<span class="ks-hk-orta"><span class="ks-hk-roz">' . $roz . '</span></span>'
            . '<span class="ks-hk-ana">' . h($ana) . '</span>' . ($alt !== '' ? '<span class="ks-hk-alt">' . h($alt) . '</span>' : '')
            . '<span class="ks-hk-dip"><span>' . h(kasa_tarih_tr((string)$r['tarih'])) . ($kasaSutun ? ' · ' . h($r['kasa_ad']) : '') . '</span>' . ($bak !== '' ? '<span>Bakiye ' . $bak . '</span>' : '') . '</span></a></article>';
    }
    return '<div class="ks-tablo-kaydir table-wrap"><table class="data-table ks-tablo"><caption class="ks-sr">Kasa hareketleri (en yeni önce)</caption><thead><tr>'
        . '<th scope="col">Tarih</th><th scope="col">Belge no / Tür</th>' . ($kasaSutun ? '<th scope="col">Kasa</th>' : '') . '<th scope="col">Açıklama</th>'
        . '<th scope="col" class="ks-sayi">Giriş</th><th scope="col" class="ks-sayi">Çıkış</th>' . ($yurume ? '<th scope="col" class="ks-sayi" title="Satırdan sonraki kasa bakiyesi">Bakiye</th>' : '')
        . '</tr></thead><tbody>' . $trs . '</tbody></table></div><div class="ks-hk-liste" aria-label="Kasa hareketleri">' . $kartlar . '</div>';
}

/** Süzgeç formu + durum çipleri. */
function kasa_filtre_html(array $F, ?array $secili): string
{
    $cip = '';
    foreach (['gecerli' => 'Geçerli', 'iptal' => 'İptal', 'tumu' => 'Tümü'] as $d => $ad) {
        $cip .= '<a class="ks-cip" href="' . h(kasa_url($F, ['durum' => $d === 'gecerli' ? '' : $d, 'sayfa' => ''])) . '"' . ($F['durum'] === $d ? ' aria-current="true"' : '') . '>' . h($ad) . '</a>';
    }
    $don = '';
    foreach (['tumu' => 'Tüm tarihler', 'bugun' => 'Bugün', '30g' => 'Son 30 gün', 'bu_ay' => 'Bu ay', 'gecen_ay' => 'Geçen ay', 'ozel' => 'Tarih aralığı'] as $v => $ad) $don .= '<option value="' . $v . '"' . ($F['donem'] === $v ? ' selected' : '') . '>' . h($ad) . '</option>';
    $tur = '<option value="">Tüm türler</option>';
    foreach (kasa_hareket_turleri() + kasa_dahili_turler() as $k => $b) $tur .= '<option value="' . h($k) . '"' . ($F['tur'] === $k ? ' selected' : '') . '>' . h($b['etiket']) . '</option>';
    return '<form method="get" action="kasa_fisleri.php" class="ks-filtre" role="search">'
        . ($secili !== null ? '<input type="hidden" name="kasa" value="' . (int)$secili['id'] . '">' : '')
        . ($F['durum'] !== 'gecerli' ? '<input type="hidden" name="durum" value="' . h($F['durum']) . '">' : '')
        . '<div class="ks-arama"><svg class="ks-arama-ikon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>'
        . '<label class="ks-sr" for="ksArama">Ara: belge no, cari, açıklama, kalem</label><input type="search" id="ksArama" name="ara" placeholder="Belge no, cari, açıklama" autocomplete="off" value="' . h($F['ara']) . '"><kbd class="ks-arama-ipucu" aria-hidden="true">/</kbd></div>'
        . '<select name="tur" data-oto-gonder aria-label="Hareket türü">' . $tur . '</select>'
        . '<select name="donem" data-oto-gonder aria-label="Dönem" data-ks-donem>' . $don . '</select>'
        . '<div class="ks-ozel-tarih"' . ($F['donem'] === 'ozel' ? '' : ' hidden') . '><label><span class="ks-sr">Başlangıç</span><input type="date" name="bas" value="' . h($F['bas']) . '" aria-label="Başlangıç tarihi"></label>'
        . '<label><span class="ks-sr">Bitiş</span><input type="date" name="bit" value="' . h($F['bit']) . '" aria-label="Bitiş tarihi"></label><button type="submit" class="btn btn-sm">Uygula</button></div>'
        . '<noscript><button type="submit" class="btn btn-sm">Uygula</button></noscript>'
        . '<div class="ks-cipler" role="group" aria-label="Hareket durumu">' . $cip . '</div></form>';
}

/** Dönem özeti: para birimi başına giriş / çıkış / net (filtreli geçerli satırlar). @param array<string,array{giris:int,cikis:int,adet:int}> $ozet */
function kasa_ozet_html(array $ozet, int $toplam, int $gorunen, string $durum = 'gecerli', string $ozetDurum = 'gecerli'): string
{
    // Etiket süzgeci söyler: iptal sekmesi → "İptal edilen"; "Tümü" sekmesi → toplamlar yalnız geçerlileri kapsar
    $iptal = $ozetDurum === 'iptal';
    $on = $iptal ? 'İptal edilen' : ($durum === 'tumu' ? 'Geçerli hareketler' : '');
    $o = '';
    foreach ($ozet as $pb => $x) {
        $net = (int)$x['giris'] - (int)$x['cikis'];
        $o .= '<span class="ks-ozet-pb" data-pb="' . h((string)$pb) . '"><b>' . h((string)$pb) . '</b> ' . ($on !== '' ? h($on) . ': ' : '') . 'Giriş <span class="ks-giris">' . h(kasa_para((int)$x['giris'], (string)$pb)) . '</span> · Çıkış <span class="ks-cikis">' . h(kasa_para((int)$x['cikis'], (string)$pb))
            . '</span>' . ($iptal ? '' : ' · Net ' . kasa_bakiye_html($net, (string)$pb)) . '</span>';
    }
    $bos = $iptal ? 'İptal edilen hareket yok' : 'Geçerli hareket yok';
    return '<div class="ks-ozet" id="ksOzet" data-ozet="' . ($iptal ? 'iptal' : 'gecerli') . '"><span class="ks-ozet-say" id="ksSayi">' . $gorunen . ($toplam > $gorunen ? ' / ' . $toplam : '') . ' hareket</span>' . ($o !== '' ? $o : '<span class="ks-ozet-pb">' . $bos . '</span>') . '</div>';
}

// ── Detay (modal içeriği; ?parca=detay) ─────────────────────────────────────

/** Hareketi okur; kasa aktif depoda görünmüyorsa null. */
function kasa_hareket_gorunur_oku(PDO $pdo, int $id): ?array
{
    $h = kasa_hareket_oku($pdo, $id);
    if ($h === null) return null;
    $kasa = kasa_kart_oku($pdo, (int)$h['kasa_id']);
    return $kasa !== null && kasa_gorunur($kasa) ? $h : null;
}

function kasa_detay_html(PDO $pdo, array $h): string
{
    $id = (int)$h['id']; $pb = (string)$h['para_birimi']; $iptal = $h['durum'] === 'iptal';
    $kurus = cari_kurus($h['tutar']);
    $satir = static fn(string $et, string $deger): string => '<div class="ks-dl-satir"><dt>' . h($et) . '</dt><dd>' . $deger . '</dd></div>';
    $d = '';
    $d .= $satir('Kasa', h($h['kasa_ad'] ?? '') . ' <small class="ks-ipucu">' . h($h['kasa_kod'] ?? '') . '</small>');
    $d .= $satir('Tarih', h(kasa_tarih_tr((string)$h['tarih'])));
    $d .= $satir('Tutar', '<b class="ks-detay-tutar ' . ($h['yon'] === 'G' ? 'ks-giris' : 'ks-cikis') . '">' . ($h['yon'] === 'G' ? '+' : '&minus;') . h(kasa_para($kurus, $pb)) . '</b>'
        . ($h['kur'] !== null && $h['kur'] !== '' ? ' <small class="ks-ipucu">kur ' . h(rtrim(rtrim((string)$h['kur'], '0'), '.')) . '</small>' : ''));
    if (!empty($h['cari_id'])) {
        $c = kasa_cari_ad($pdo, (int)$h['cari_id']);
        if ($c !== null) $d .= $satir('Cari', '<a href="cari_kartlari.php?id=' . (int)$h['cari_id'] . '">' . h($c['unvan']) . '</a> <small class="ks-ipucu">' . h($c['kod']) . '</small>');
    }
    if (!empty($h['cari_hareket_id'])) {
        $ch = cari_hareket_oku($pdo, (int)$h['cari_hareket_id']);
        if ($ch !== null) {
            $d .= $satir('Cari belgesi', '<a href="cari_kartlari.php?id=' . (int)$ch['cari_id'] . '">' . h((string)$ch['belge_no']) . '</a>'
                . ($ch['durum'] === 'iptal' ? ' <span class="ks-rozet ks-rozet--iptal">İPTAL</span>' : '') . ' <small class="ks-ipucu">' . h(cari_tur_bilgi((string)$ch['tur'])['etiket'] ?? (string)$ch['tur']) . '</small>');
        }
    }
    if (!empty($h['kalem_ad'])) $d .= $satir('Kalem', h($h['kalem_ad']));
    if (!empty($h['bagli_id'])) {
        $b = kasa_hareket_oku($pdo, (int)$h['bagli_id']);
        if ($b !== null) $d .= $satir($h['yon'] === 'C' ? 'Hedef kasa' : 'Kaynak kasa', h($b['kasa_ad'] ?? '') . ' <small class="ks-ipucu">' . h($b['belge_no']) . ($b['durum'] === 'iptal' ? ' · iptal' : '') . '</small>');
    }
    if (!empty($h['evrak_no'])) $d .= $satir('Evrak no', h($h['evrak_no']));
    if ((string)$h['aciklama'] !== '') $d .= $satir('Açıklama', nl2br(h((string)$h['aciklama'])));
    if (!empty($h['depo'])) $d .= $satir('Depo', h($h['depo']));
    if (!empty($h['kaynak_modul'])) $d .= $satir('Kaynak', '<span class="ks-rozet ks-rozet--kaynak">Kaynak belge #' . (int)$h['kaynak_id'] . '</span> <small class="ks-ipucu">kaynak belgeden iptal edilir</small>');
    $serit = $iptal ? '<div class="ks-iptal-serit" role="note"><b>İptal edildi:</b> ' . h((string)$h['iptal_neden']) . ' · ' . h(kasa_tarih_tr((string)$h['iptal_at'])) . '</div>' : '';
    $iptalEdilir = !$iptal && empty($h['kaynak_modul']) && kasa_can('delete');
    return '<div class="ks-detay" id="ksDetay" data-id="' . $id . '" data-belge="' . h($h['belge_no']) . '" data-iptal="' . ($iptalEdilir ? '1' : '0') . '" data-durum="' . h($h['durum']) . '">'
        . '<div class="ks-detay-ust"><h3 class="ks-detay-belge">' . h($h['belge_no'] !== '' ? $h['belge_no'] : '#' . $id) . '</h3><span class="ks-detay-rozet">' . kasa_tur_rozet((string)$h['tur'], (string)$h['yon']) . ($iptal ? ' <span class="ks-rozet ks-rozet--iptal">İPTAL</span>' : '') . '</span></div>'
        . $serit . '<dl class="ks-dl">' . $d . '</dl></div>';
}

/** ?parca=detay&id= kısmi yanıtı (header/footer YOK). Yanıt verildiyse true (çağıran exit eder). */
function kasa_parca(PDO $pdo): bool
{
    $parca = (string)($_GET['parca'] ?? '');
    if ($parca === '') return false;
    if ($parca === 'detay') {
        $h = kasa_hareket_gorunur_oku($pdo, max(0, (int)($_GET['id'] ?? 0)));
        if ($h === null) { http_response_code(404); echo '<div class="flash flash-error" role="alert">Hareket bulunamadı.</div>'; return true; }
        echo kasa_detay_html($pdo, $h);
        return true;
    }
    http_response_code(400);
    return true;
}

// ── Modallar ────────────────────────────────────────────────────────────────

function kasa_modal_detay(?string $icerik, string $kapatUrl): string
{
    $acik = $icerik !== null;
    $iptalVar = $acik && str_contains($icerik, 'data-iptal="1"');
    return '<div class="pm-overlay" id="ksModalDetay"' . ($acik ? '' : ' hidden') . '><div class="pm-dialog ks-dialog ks-dialog--detay" role="dialog" aria-modal="true" aria-labelledby="ksDetayBaslik">'
        . '<div class="pm-header"><span class="ks-tutamac" aria-hidden="true"></span><h2 class="pm-title" id="ksDetayBaslik">Hareket ayrıntısı</h2><a class="pm-close" href="' . h($kapatUrl) . '" data-ks-kapat aria-label="Kapat">&#10005;</a></div>'
        . '<div class="pm-body" id="ksDetayKap">' . ($icerik ?? '') . '</div>'
        . '<div class="pm-footer"><span class="ks-klavye">Esc kapatır</span><a class="btn" href="' . h($kapatUrl) . '" data-ks-kapat>Kapat</a>'
        . '<button type="button" class="btn ks-btn-tehlike" data-ks-iptal-ac' . ($iptalVar ? '' : ' hidden') . '>İptal et</button></div></div></div>';
}

function kasa_modal_iptal(): string
{
    return '<div class="pm-overlay" id="ksModalIptal" hidden><div class="pm-dialog ks-dialog ks-dialog--dar" role="dialog" aria-modal="true" aria-labelledby="ksIptalBaslik">'
        . '<div class="pm-header"><span class="ks-tutamac" aria-hidden="true"></span><h2 class="pm-title" id="ksIptalBaslik">Hareketi iptal et &mdash; <span data-iptal-no></span></h2>'
        . '<button type="button" class="pm-close" data-ks-kapat aria-label="Kapat">&#10005;</button></div>'
        . '<form method="post" action="kasa_kaydet.php" data-ks-ajax data-ok="Hareket iptal edildi" novalidate>'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="islem" value="hareket_iptal"><input type="hidden" name="id" value=""><input type="hidden" name="geri" value="kasa_fisleri.php">'
        . '<div class="pm-body"><div class="flash flash-error ks-form-hata" role="alert" hidden></div>'
        . '<div class="ks-uyari ks-uyari--tehlike" role="note"><span class="ks-uyari-ikon" aria-hidden="true">&#9888;</span><div><p>Hareket silinmez; iptal olarak işaretlenir. Bağlı cari hareketi ve virman eşi de iptal edilir. Düzeltme için iptalden sonra yeni hareket girin.</p></div></div>'
        . '<label class="pm-label"><span>İptal nedeni <b class="ks-zorunlu" aria-hidden="true">*</b></span><textarea name="neden" rows="3" minlength="3" maxlength="500" required data-ilk-odak data-iptal-neden aria-describedby="ksIptalSayac"></textarea>'
        . '<span class="ks-ipucu" id="ksIptalSayac" data-sayac>En az 3 karakter</span></label></div>'
        . '<div class="pm-footer"><span class="ks-klavye">Esc kapatır</span><button type="button" class="btn" data-ks-kapat>Vazgeç</button>'
        . '<button type="submit" class="btn ks-btn-tehlike" data-iptal-onay>Hareketi iptal et</button></div></form></div></div>';
}

/** Hareket / virman formu (#ksModalForm). Tek ortak form; alan görünürlüğü tura göre JS'te (data-turler). */
function kasa_modal_form(array $aktifKasalar, array $gelirKalemleri, array $giderKalemleri, array $cariler, int $seciliKasa): string
{
    $kasaOpt = static function (string $ad) use ($aktifKasalar): string {
        $o = '<option value="">Seçiniz</option>';
        foreach ($aktifKasalar as $k) $o .= '<option value="' . (int)$k['id'] . '" data-pb="' . h((string)$k['para_birimi']) . '">' . h($k['ad'] . ' · ' . $k['para_birimi']) . '</option>';
        return $o;
    };
    $kalemOpt = static function (array $l): string {
        $o = '<option value="">Seçiniz</option>';
        foreach ($l as $t) if ((int)$t['aktif'] === 1) $o .= '<option value="' . (int)$t['id'] . '">' . h($t['ad']) . '</option>';
        return $o;
    };
    $z = ' <b class="ks-zorunlu" aria-hidden="true">*</b>';
    $dl = '<datalist id="ksCariListe">';
    foreach ($cariler as $c) $dl .= '<option value="' . h($c['kod'] . ' — ' . $c['unvan']) . '"></option>';
    $dl .= '</datalist>';
    return '<div class="pm-overlay" id="ksModalForm" hidden><div class="pm-dialog ks-dialog ks-dialog--genis ks-dialog--uzun" role="dialog" aria-modal="true" aria-labelledby="ksFormBaslik">'
        . '<div class="pm-header"><span class="ks-tutamac" aria-hidden="true"></span><h2 class="pm-title" id="ksFormBaslik">Yeni hareket</h2><button type="button" class="pm-close" data-ks-kapat aria-label="Kapat">&#10005;</button></div>'
        . '<form method="post" action="kasa_kaydet.php" id="ksForm" data-ks-form novalidate>'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="islem" value="hareket_ekle"><input type="hidden" name="tur" value="">'
        . '<input type="hidden" name="istek_anahtari" value=""><input type="hidden" name="eksiye_izin" value="0"><input type="hidden" name="geri" value="kasa_fisleri.php">'
        . '<div class="pm-body">'
        . '<div class="flash flash-error ks-form-hata" role="alert" hidden></div>'
        . '<div class="ks-eksik" id="ksEksik" role="alertdialog" aria-labelledby="ksEksikBaslik" aria-describedby="ksEksikAck" hidden><h3 id="ksEksikBaslik">Yetersiz bakiye</h3>'
        . '<p id="ksEksikAck">Bu çıkış kasayı eksiye düşürecek.</p>'
        . '<dl class="ks-eksik-dl"><div><dt>Mevcut</dt><dd data-eksik="bakiye">—</dd></div><div><dt>İstenen</dt><dd data-eksik="istenen">—</dd></div><div><dt>Eksik</dt><dd data-eksik="eksik">—</dd></div></dl>'
        . '<div class="ks-eksik-eylem"><button type="button" class="btn" data-eksik-duzelt>Düzelt</button><button type="button" class="btn ks-btn-uyari" data-eksik-kaydet>Yine de kaydet</button></div></div>'
        . '<div class="ks-uyari ks-uyari--bilgi" data-ks-tevsik hidden role="note"><span class="ks-uyari-ikon" aria-hidden="true">&#9432;</span><div><p>30.000 TL ve üzeri nakit tahsilat/ödemeler banka aracılığıyla yapılmalıdır (VUK GT 575). Kayıt yine de yapılır, uyarı olarak saklanır.</p></div></div>'
        . '<div class="ks-form-grid">'
        . '<label class="pm-label ks-alan ks-span2" data-turler="cari_tahsilat cari_odeme gelir gider acilis"><span>Kasa' . $z . '</span><select name="kasa_id" data-ilk-odak>' . $kasaOpt('') . '</select><span class="ks-ipucu" data-bakiye-ipucu aria-live="polite"></span></label>'
        . '<label class="pm-label ks-alan" data-turler="virman"><span>Kaynak kasa' . $z . '</span><select name="kaynak_kasa_id">' . $kasaOpt('') . '</select><span class="ks-ipucu" data-bakiye-ipucu aria-live="polite"></span></label>'
        . '<label class="pm-label ks-alan" data-turler="virman"><span>Hedef kasa' . $z . '</span><select name="hedef_kasa_id">' . $kasaOpt('') . '</select><span class="ks-ipucu" data-hedef-ipucu aria-live="polite"></span></label>'
        . '<label class="pm-label ks-alan"><span>Tarih' . $z . '</span><input type="date" name="tarih" value="' . h(date('Y-m-d')) . '" required></label>'
        . '<label class="pm-label ks-alan"><span>Tutar' . $z . ' <small class="ks-pb" data-pb-etiket></small></span><input type="text" name="tutar" inputmode="decimal" autocomplete="off" placeholder="0,00" required><span class="ks-ipucu ks-tutar-onizleme" data-tutar-onizleme aria-live="polite"></span></label>'
        . '<label class="pm-label ks-alan" data-turler="cari_tahsilat cari_odeme gelir gider acilis" data-kur hidden><span>Kur <small class="ks-ipucu">döviz kasası</small></span><input type="text" name="kur" inputmode="decimal" autocomplete="off" placeholder="isteğe bağlı"></label>'
        . '<label class="pm-label ks-alan ks-span2" data-turler="cari_tahsilat cari_odeme"><span>Cari' . $z . '</span><input type="hidden" name="cari_id" value="">'
        . '<input type="text" name="cari_ara" list="ksCariListe" placeholder="Cari ara (kod ya da unvan)" autocomplete="off" spellcheck="false"><span class="ks-ipucu" data-cari-ipucu aria-live="polite"></span></label>'
        . '<label class="pm-label ks-alan ks-span2" data-turler="gelir"><span>Gelir kalemi' . $z . '</span><select name="kalem_id">' . $kalemOpt($gelirKalemleri) . '</select></label>'
        . '<label class="pm-label ks-alan ks-span2" data-turler="gider"><span>Gider kalemi' . $z . '</span><select name="kalem_id">' . $kalemOpt($giderKalemleri) . '</select></label>'
        . '<label class="pm-label ks-alan" data-turler="cari_tahsilat cari_odeme gelir gider"><span>Evrak no</span><input type="text" name="evrak_no" maxlength="60" autocomplete="off"></label>'
        . '<label class="pm-label ks-alan ks-span2"><span>Açıklama</span><input type="text" name="aciklama" maxlength="500" autocomplete="off"></label>'
        . '</div></div>'
        . '<div class="pm-footer"><span class="ks-klavye">Ctrl+Enter kaydeder · Esc kapatır</span><button type="button" class="btn" data-ks-kapat>Vazgeç</button><button type="submit" class="btn btn-primary" data-kaydet>Kaydet</button></div>'
        . '</form>' . $dl . '</div></div>';
}

// ── Fiş sayfası gövdesi ─────────────────────────────────────────────────────

/**
 * Kasa Fişleri gövdesi. ?kasa=ID (yoksa tümü) · ?id=N hareket detayı (modal açık) · süzgeç: ara, tur, durum (varsayılan gecerli), donem (+bas/bit), sayfa.
 * Yürüyen bakiye sütunu yalnız tek kasa + durum=gecerli + tür/ara süzgeci yokken.
 */
function kasa_fisleri_govde(PDO $pdo): string
{
    $F = kasa_filtre();
    $kartlar = kasa_kart_listesi($pdo)['satirlar'];
    $ust = kasa_ust_menu('fisler');
    if (!$kartlar) {
        return '<div class="kasa" id="kasa" data-sayfa="fis" data-bos="1">' . $ust
            . kasa_bos('Henüz kasa yok', 'Hareket girmek için önce bir kasa ya da banka hesabı tanımlayın.', '<a class="btn btn-primary" href="kasa_tanimlari.php?sekme=kasa&amp;yeni=1">İlk kasanızı ekleyin</a>') . '</div>';
    }
    $secili = null;
    foreach ($kartlar as $k) if ((int)$k['id'] === $F['kasa']) $secili = $k;
    $not = $F['kasa'] > 0 && $secili === null ? 'Bu kasa bulunamadı ya da aktif deponuzda görünmüyor; tüm kasalar gösteriliyor.' : '';
    $aktifler = array_values(array_filter($kartlar, static fn(array $k): bool => (int)$k['aktif'] === 1));
    $ozet = kasa_genel_ozet($pdo);

    $sf = ['kasa_id' => $secili !== null ? (int)$secili['id'] : 0, 'tur' => $F['tur'], 'durum' => $F['durum'], 'donem' => $F['donem'], 'bas' => $F['bas'], 'bit' => $F['bit'], 'ara' => $F['ara']];
    $boy = kasa_ui_sayfa_boyu();
    $L = kasa_hareket_listesi($pdo, $sf, $boy, ($F['sayfa'] - 1) * $boy);
    if (!$L['satirlar'] && $L['toplam_satir'] > 0 && $F['sayfa'] > 1) {   // son sayfadan büyük ?sayfa=N → son sayfaya sınırla (yanlış "Henüz hareket yok" olmasın)
        $F['sayfa'] = (int)ceil($L['toplam_satir'] / $boy);
        $L = kasa_hareket_listesi($pdo, $sf, $boy, ($F['sayfa'] - 1) * $boy);
    }
    $tek = $secili !== null;
    $F['kasa'] = $tek ? (int)$secili['id'] : 0;

    $sanDefter = '';
    if ($tek) {
        [$dbas, $dbit] = kasa_donem_araligi($sf);
        $dq = http_build_query(array_filter(['kasa' => (int)$secili['id'], 'bas' => $dbas, 'bit' => $dbit], static fn($x) => $x !== '' && $x !== 0));
        $sanDefter = '<a class="btn btn-sm" href="' . h('kasa_cikti.php?' . $dq) . '" target="_blank" rel="noopener" data-ks-defter>Kasa Defteri</a>'
            . (can('reports.export') ? '<span class="ks-disa"><a href="' . h('kasa_cikti.php?' . $dq . '&bicim=xlsx') . '">XLSX</a> · <a href="' . h('kasa_cikti.php?' . $dq . '&bicim=csv') . '">CSV</a></span>' : '');
    }
    // Başlık
    if ($tek) {
        $pb = (string)$secili['para_birimi'];
        $meta = array_filter([(string)$secili['kod'], $secili['tip'] === 'banka' ? trim((string)($secili['banka_adi'] ?? '') . ' ' . (string)($secili['sube'] ?? '')) : '', $secili['tip'] === 'banka' ? (string)($secili['iban'] ?? '') : '',
            (string)$secili['depo'] !== '' ? 'Depo: ' . $secili['depo'] : 'Tüm depolar'], static fn($x) => $x !== '');
        $baslik = '<header class="ks-baslik"><div class="ks-baslik-satir"><h1 class="ks-unvan">' . h($secili['ad']) . '</h1>' . kasa_tip_rozet((string)$secili['tip'])
            . '<span class="ks-rozet">' . h($pb) . '</span>' . ((int)$secili['aktif'] !== 1 ? '<span class="ks-rozet ks-rozet--pasif">Pasif</span>' : '') . ((int)$secili['eksi_izin'] === 1 ? '<span class="ks-rozet ks-rozet--uyari" title="Eksi bakiyeye uyarısız izin verilir">Eksiye izinli</span>' : '') . '</div>'
            . '<div class="ks-baslik-alt"><div class="ks-bakiye-kutu"><span class="ks-bakiye-ad">Kasa bakiyesi</span><span class="ks-bakiye-deger" data-bakiye="' . (int)$secili['bakiye'] . '">' . kasa_bakiye_html((int)$secili['bakiye'], $pb) . '</span></div>'
            . '<div class="ks-sag-bilgi"><div class="ks-meta">' . h(implode(' · ', $meta)) . '</div>' . ($sanDefter !== '' ? '<div class="ks-defter">' . $sanDefter . '</div>' : '') . '</div></div></header>';
    } else {
        $baslik = '<header class="ks-baslik"><div class="ks-baslik-satir"><h1 class="ks-unvan">Tüm kasalar</h1><span class="ks-rozet">' . count($kartlar) . ' kasa</span></div>'
            . '<div class="ks-baslik-alt"><div class="ks-meta">Bakiye için soldan bir kasa seçin; para birimleri birbirine eklenmez.</div></div></header>';
    }

    // Eylemler (PC/tablet satır içi; <768 alt çubuk)
    $tur = ['cari_tahsilat' => ['Tahsilat', '&#9650;', 'giris'], 'cari_odeme' => ['Ödeme', '&#9660;', 'cikis'], 'gelir' => ['Gelir', '&#9650;', 'giris'], 'gider' => ['Gider', '&#9660;', 'cikis'],
        'virman' => ['Virman', '&#8644;', 'virman'], 'acilis' => ['Açılış', '&#9650;', 'giris']];
    $yazabilir = kasa_can('create') && $aktifler;
    $btn = '';
    foreach ($tur as $k => [$ad, $ikon, $sn]) {
        $dis = !$yazabilir || ($k === 'virman' && count($aktifler) < 2);
        $btn .= '<button type="button" class="btn ks-eylem ks-eylem--' . $sn . '" data-ks-form="' . $k . '"' . ($dis ? ' disabled' : '') . '><span aria-hidden="true">' . $ikon . '</span> ' . h($ad) . '</button>';
    }
    $eylemler = '<div class="ks-eylemler" role="toolbar" aria-label="Kasa işlemleri">' . $btn . '</div>';
    $altBar = '<div class="ks-eylem-bar" role="toolbar" aria-label="Kasa işlemleri"><button type="button" class="btn btn-primary ks-eylem" data-ks-form="cari_tahsilat"' . ($yazabilir ? '' : ' disabled') . '>&#9650; Tahsilat</button>'
        . '<button type="button" class="btn ks-eylem" data-ks-form="cari_odeme"' . ($yazabilir ? '' : ' disabled') . '>&#9660; Ödeme</button>'
        . '<button type="button" class="btn ks-eylem-daha" data-ks-daha aria-expanded="false" aria-controls="ksDaha">Diğer &#9662;</button></div>'
        . '<div class="ks-daha" id="ksDaha" hidden>';
    foreach (['gelir', 'gider', 'virman', 'acilis'] as $k) $altBar .= '<button type="button" class="btn ks-eylem" data-ks-form="' . $k . '"' . ($yazabilir && !($k === 'virman' && count($aktifler) < 2) ? '' : ' disabled') . '>' . $tur[$k][1] . ' ' . h($tur[$k][0]) . '</button>';
    if ($tek) {
        $altBar .= '<a class="btn" href="' . h('kasa_cikti.php?' . $dq) . '" target="_blank" rel="noopener">Kasa Defteri</a>';
        if (can('reports.export')) $altBar .= '<a class="btn" href="' . h('kasa_cikti.php?' . $dq . '&bicim=xlsx') . '">Excel (XLSX)</a><a class="btn" href="' . h('kasa_cikti.php?' . $dq . '&bicim=csv') . '">CSV indir</a>';
    }
    $altBar .= '</div>';

    // Liste
    $yurume = $L['yurume_var'] && $tek;
    $filtreli = $F['ara'] !== '' || $F['tur'] !== '' || $F['durum'] !== 'gecerli' || $F['donem'] !== 'tumu';
    if ($L['satirlar']) $liste = kasa_liste_html($L['satirlar'], $yurume, !$tek, $F);
    elseif ($filtreli) $liste = '<p class="ks-liste-bos">Eşleşen hareket yok. <a href="' . h('kasa_fisleri.php' . ($tek ? '?kasa=' . (int)$secili['id'] : '')) . '">Filtreleri temizle</a></p>';
    else $liste = kasa_bos('Henüz hareket yok', $tek ? 'Bu kasada henüz hareket yok. Açılış bakiyesi ya da ilk tahsilatı girin.' : 'Henüz kasa hareketi yok. Soldan bir kasa seçip ilk hareketi girin.',
        $yazabilir ? '<button type="button" class="btn btn-primary" data-ks-form="' . ($tek ? 'acilis' : 'cari_tahsilat') . '">' . ($tek ? 'Açılış bakiyesi gir' : 'Tahsilat gir') . '</button>' : '');
    $sayfaSay = (int)ceil($L['toplam_satir'] / $boy);
    $sayfalama = $sayfaSay > 1 ? '<nav class="ks-sayfalama" aria-label="Sayfalar">' . ($F['sayfa'] > 1 ? '<a class="btn btn-sm" href="' . h(kasa_url($F, ['sayfa' => $F['sayfa'] > 2 ? $F['sayfa'] - 1 : ''])) . '">&lsaquo; Yeni</a>' : '')
        . '<span>' . $F['sayfa'] . ' / ' . $sayfaSay . '</span>' . ($F['sayfa'] < $sayfaSay ? '<a class="btn btn-sm" href="' . h(kasa_url($F, ['sayfa' => $F['sayfa'] + 1])) . '">Eski &rsaquo;</a>' : '') . '</nav>' : '';

    // Detay (?id=)
    $detay = null; $detayId = max(0, (int)($_GET['id'] ?? 0));
    if ($detayId > 0) {
        $dh = kasa_hareket_gorunur_oku($pdo, $detayId);
        if ($dh !== null) $detay = kasa_detay_html($pdo, $dh); else $not = $not !== '' ? $not : 'Hareket bulunamadı ya da aktif deponuzda görünmüyor.';
    }
    $kapat = kasa_url($F);

    // JS verisi (formlar)
    $bakiyeler = [];
    foreach ($aktifler as $k) $bakiyeler[] = ['id' => (int)$k['id'], 'ad' => (string)$k['ad'], 'pb' => (string)$k['para_birimi'], 'tip' => (string)$k['tip'], 'bakiye' => (int)$k['bakiye'], 'eksi_izin' => (int)$k['eksi_izin']];
    $cariler = [];
    if (cari_hazir($pdo) && $yazabilir) foreach (cari_kart_listesi($pdo, ['aktif' => 1])['satirlar'] as $c) $cariler[] = ['id' => (int)$c['id'], 'kod' => (string)$c['kod'], 'unvan' => (string)$c['unvan']];
    $veri = ['kasalar' => $bakiyeler, 'secili' => $tek ? (int)$secili['id'] : 0, 'cariler' => $cariler, 'tevsik' => KASA_TEVSIK_SINIRI_KURUS];
    $pbUyari = '';
    return '<div class="kasa ks--tek-ekran" id="kasa" data-sayfa="fis" data-kasa="' . ($tek ? (int)$secili['id'] : 0) . '">' . $ust
        . ($not !== '' ? '<div class="flash flash-error" role="alert">' . h($not) . '</div>' : '')
        . '<div class="ks-ana">' . kasa_sol_html($kartlar, $ozet, $F, $secili)
        . '<section class="ks-sag" aria-label="Kasa hareketleri">' . $baslik . $eylemler . kasa_filtre_html($F, $secili)
        . '<div class="ks-liste-govde" id="ksListe">' . $liste . $sayfalama . '</div>' . kasa_ozet_html($L['ozet'], $L['toplam_satir'], count($L['satirlar']), $F['durum'], (string)$L['ozet_durum']) . '</section></div>'
        . ($yazabilir ? $altBar : '')
        . $pbUyari
        . (kasa_can('create') && $aktifler ? kasa_modal_form($aktifler, kasa_tanim_listesi($pdo, 'gelir'), kasa_tanim_listesi($pdo, 'gider'), $cariler, $tek ? (int)$secili['id'] : 0) : '')
        . (kasa_can('delete') ? kasa_modal_iptal() : '')
        . kasa_modal_detay($detay, $kapat)
        . kasa_json_script('ksVeri', $veri)
        . '</div>';
}

// ── Tanımlar ────────────────────────────────────────────────────────────────

/** Kart düzenleme verisi (düğmenin data-kart özniteliği). */
function kasa_kart_json(array $k): array
{
    return ['id' => (int)$k['id'], 'kod' => (string)$k['kod'], 'ad' => (string)$k['ad'], 'tip' => (string)$k['tip'], 'para_birimi' => (string)$k['para_birimi'], 'depo' => (string)$k['depo'],
        'banka_adi' => (string)($k['banka_adi'] ?? ''), 'sube' => (string)($k['sube'] ?? ''), 'iban' => (string)($k['iban'] ?? ''), 'eksi_izin' => (int)$k['eksi_izin'], 'aciklama' => (string)($k['aciklama'] ?? ''),
        'aktif' => (int)$k['aktif'], 'hareketli' => (int)$k['hareket_say'] > 0];
}

/** Kasa kartı modalı (#ksModalKart). */
function kasa_modal_kart(string $kodOneri): string
{
    $tipOpt = ''; foreach (kasa_tipler() as $v => $ad) $tipOpt .= '<option value="' . h($v) . '">' . h($ad) . '</option>';
    $pbOpt = ''; foreach (cari_para_birimleri() as $p) $pbOpt .= '<option value="' . h($p) . '">' . h($p) . '</option>';
    $depoOpt = '<option value="">Tüm depolar</option>';
    foreach (depot_options() as $d) if (depot_visible_to_user((string)$d)) $depoOpt .= '<option value="' . h($d) . '">' . h($d) . '</option>';
    return '<div class="pm-overlay" id="ksModalKart" hidden><div class="pm-dialog ks-dialog ks-dialog--genis ks-dialog--uzun" role="dialog" aria-modal="true" aria-labelledby="ksKartBaslik">'
        . '<div class="pm-header"><span class="ks-tutamac" aria-hidden="true"></span><h2 class="pm-title" id="ksKartBaslik">Yeni kasa</h2><button type="button" class="pm-close" data-ks-kapat aria-label="Kapat">&#10005;</button></div>'
        . '<form method="post" action="kasa_kaydet.php" data-ks-ajax data-ok="Kasa kaydedildi" novalidate>'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="islem" value="kart_kaydet"><input type="hidden" name="id" value=""><input type="hidden" name="geri" value="kasa_tanimlari.php?sekme=kasa">'
        . '<div class="pm-body"><div class="flash flash-error ks-form-hata" role="alert" hidden></div>'
        . '<fieldset class="ks-bolum"><legend>Temel</legend><div class="ks-form-grid">'
        . '<label class="pm-label ks-span2"><span>Ad <b class="ks-zorunlu" aria-hidden="true">*</b></span><input type="text" name="ad" maxlength="150" required autocomplete="off" data-ilk-odak></label>'
        . '<label class="pm-label"><span>Kasa kodu</span><input type="text" name="kod" maxlength="30" value="' . h($kodOneri) . '" data-oneri="' . h($kodOneri) . '" autocomplete="off" style="text-transform:uppercase"><span class="ks-ipucu">Boş bırakılırsa otomatik verilir</span></label>'
        . '<label class="pm-label"><span>Tip</span><select name="tip" data-ks-tip>' . $tipOpt . '</select></label>'
        . '<label class="pm-label"><span>Para birimi</span><select name="para_birimi">' . $pbOpt . '</select><span class="ks-ipucu" data-pb-kilit hidden>Hareketli kasada değiştirilemez</span></label>'
        . '<label class="pm-label"><span>Depo</span><select name="depo">' . $depoOpt . '</select><span class="ks-ipucu">Boş = tüm depolarda görünür</span></label>'
        . '</div></fieldset>'
        . '<fieldset class="ks-bolum ks-banka" data-banka hidden><legend>Banka</legend><div class="ks-form-grid">'
        . '<label class="pm-label"><span>Banka adı</span><input type="text" name="banka_adi" maxlength="100" autocomplete="off"></label>'
        . '<label class="pm-label"><span>Şube</span><input type="text" name="sube" maxlength="100" autocomplete="off"></label>'
        . '<label class="pm-label ks-span2"><span>IBAN <small class="ks-ipucu">isteğe bağlı</small></span><input type="text" name="iban" maxlength="40" autocomplete="off" spellcheck="false" placeholder="TR00 0000 0000 0000 0000 0000 00" style="text-transform:uppercase"></label>'
        . '</div></fieldset>'
        . '<fieldset class="ks-bolum"><legend>Diğer</legend><div class="ks-form-grid">'
        . '<label class="ks-onay ks-span2"><input type="checkbox" name="eksi_izin" value="1"><span>Eksi bakiyeye izin ver (uyarı/engel çıkmaz)</span></label>'
        . '<label class="ks-onay ks-span2"><input type="checkbox" name="aktif" value="1" checked><span>Aktif (pasif kasa yeni hareket almaz)</span></label>'
        . '<label class="pm-label ks-span2"><span>Açıklama</span><textarea name="aciklama" rows="2" maxlength="500"></textarea></label>'
        . '</div></fieldset></div>'
        . '<div class="pm-footer"><span class="ks-klavye">Ctrl+Enter kaydeder · Esc kapatır</span><button type="button" class="btn" data-ks-kapat>Vazgeç</button><button type="submit" class="btn btn-primary">Kaydet</button></div>'
        . '</form></div></div>';
}

/** Kasa kartı listesi paneli (Tanımlar › Kasalar). */
function kasa_tanim_kasa_panel(PDO $pdo, bool $gizli, bool $yaz): string
{
    $kartlar = kasa_kart_listesi($pdo)['satirlar'];
    $csrf = h(csrf_token());
    $h = '<section class="ks-tanim-govde" id="ksPanelKasa" role="tabpanel" aria-labelledby="ksTabKasa"' . ($gizli ? ' hidden' : '') . '><div class="ks-panel-ust"><div><h2>Kasa ve banka hesapları</h2>'
        . '<p>Banka hesabı da bir kasa kartıdır (tip: Banka Hesabı). Hareketi olan kasanın para birimi değişmez, kasa silinmez; pasifleştirilir.</p></div>'
        . ($yaz ? '<button type="button" class="btn btn-primary" data-ks-modal="kart">+ Yeni kasa</button>' : '') . '</div>';
    if (!$kartlar) return $h . kasa_bos('İlk kasanızı ekleyin', 'Nakit kasa ya da banka hesabı tanımlayın; hareketler bu kartlara işlenir.', $yaz ? '<button type="button" class="btn btn-primary" data-ks-modal="kart">İlk kasanızı ekleyin</button>' : '', 'h3') . '</section>';
    $h .= '<ul class="ks-kk-liste">';
    foreach ($kartlar as $k) {
        $id = (int)$k['id']; $pasif = (int)$k['aktif'] !== 1; $pb = (string)$k['para_birimi']; $hareketli = (int)$k['hareket_say'] > 0;
        $alt = array_filter([(string)$k['kod'], $k['tip'] === 'banka' ? trim((string)($k['banka_adi'] ?? '') . ' ' . (string)($k['sube'] ?? '')) : '', $k['tip'] === 'banka' ? (string)($k['iban'] ?? '') : '',
            (string)$k['depo'] !== '' ? $k['depo'] : 'Tüm depolar', $hareketli ? (int)$k['hareket_say'] . ' hareket' : 'hareket yok'], static fn($x) => $x !== '');
        $h .= '<li class="ks-kk' . ($pasif ? ' ks-kk--pasif' : '') . '" data-id="' . $id . '"><div class="ks-kk-bas"><div class="ks-kk-ad">' . h($k['ad']) . '</div><div class="ks-kk-roz">' . kasa_tip_rozet((string)$k['tip']) . '<span class="ks-rozet">' . h($pb) . '</span>'
            . ($pasif ? '<span class="ks-rozet ks-rozet--pasif">Pasif</span>' : '') . ((int)$k['eksi_izin'] === 1 ? '<span class="ks-rozet ks-rozet--uyari">Eksiye izinli</span>' : '') . '</div></div>'
            . '<div class="ks-kk-alt">' . h(implode(' · ', $alt)) . '</div><div class="ks-kk-bak"><span class="ks-bakiye-ad">Bakiye</span>' . kasa_bakiye_html((int)$k['bakiye'], $pb) . '</div><div class="ks-kk-eylem">'
            . '<a class="btn btn-sm" href="' . h('kasa_fisleri.php?kasa=' . $id) . '">Fişler</a>';
        if ($yaz) {
            $h .= '<button type="button" class="btn btn-sm" data-ks-modal="kart" data-duzenle="1" data-kart="' . kasa_json_attr(kasa_kart_json($k)) . '">Düzenle</button>'
                . '<form method="post" action="kasa_kaydet.php" data-ks-ajax data-ok="' . ($pasif ? 'Kasa aktifleştirildi' : 'Kasa pasifleştirildi') . '" class="ks-icerik"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="islem" value="kart_durum">'
                . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="aktif" value="' . ($pasif ? '1' : '0') . '"><input type="hidden" name="geri" value="kasa_tanimlari.php?sekme=kasa">'
                . '<button type="submit" class="btn btn-sm" data-ks-islem="kart_durum">' . ($pasif ? 'Aktifleştir' : 'Pasifleştir') . '</button></form>'
                . ($hareketli
                    ? '<button type="button" class="btn btn-sm ks-tehlike" disabled title="Hareketi olan kasa silinemez; pasifleştirin">Sil</button>'
                    : '<form method="post" action="kasa_kaydet.php" data-ks-ajax data-ok="Kasa silindi" data-onay="Bu kasa kalıcı olarak silinecek. Emin misiniz?" class="ks-icerik"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="islem" value="kart_sil">'
                      . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="geri" value="kasa_tanimlari.php?sekme=kasa"><button type="submit" class="btn btn-sm ks-tehlike" data-ks-islem="kart_sil">Sil</button></form>');
        }
        $h .= '</div></li>';
    }
    return $h . '</ul></section>';
}

/** Gelir / gider kalemi paneli. */
function kasa_tanim_panel(PDO $pdo, string $tur, string $panelId, string $tabId, bool $gizli, bool $yaz): string
{
    $liste = kasa_tanim_listesi($pdo, $tur);
    $csrf = h(csrf_token());
    $geri = 'kasa_tanimlari.php?sekme=' . $tur;
    $max = 0; foreach ($liste as $t) $max = max($max, (int)$t['sira']);
    $baslik = ['gelir' => 'Gelir Kalemleri', 'gider' => 'Gider Kalemleri'][$tur];
    $ack = ['gelir' => 'Gelir fişinde seçilir (faiz geliri, diğer gelir…). Kullanılan kalem silinemez, pasifleştirilir.', 'gider' => 'Gider fişinde seçilir (yakıt, yemek, nakliye…). Kullanılan kalem silinemez, pasifleştirilir.'][$tur];
    $h = '<section class="ks-tanim-govde" id="' . $panelId . '" role="tabpanel" aria-labelledby="' . $tabId . '"' . ($gizli ? ' hidden' : '') . '><h2>' . h($baslik) . '</h2><p>' . h($ack) . '</p>';
    if ($yaz) {
        $h .= '<form class="ks-tanim-ekle" method="post" action="kasa_kaydet.php" data-ks-ajax data-ok="Tanım eklendi" novalidate><input type="hidden" name="csrf" value="' . $csrf . '">'
            . '<input type="hidden" name="islem" value="tanim_kaydet"><input type="hidden" name="tur" value="' . h($tur) . '"><input type="hidden" name="geri" value="' . h($geri) . '">'
            . '<label class="pm-label"><span>Yeni ' . h(mb_strtolower(kasa_tanim_turleri()[$tur])) . '</span><input type="text" name="ad" maxlength="100" autocomplete="off" required></label>'
            . '<label class="pm-label ks-tanim-sira"><span>Sıra</span><input type="number" name="sira" value="' . ($max + 10) . '" min="0" max="99999" inputmode="numeric"></label>'
            . '<button type="submit" class="btn btn-primary">+ Ekle</button></form>';
    }
    if (!$liste) return $h . kasa_bos('Henüz kalem yok', 'Yukarıdan ilk kalemi ekleyin.', '', 'h3') . '</section>';
    $h .= '<ul class="ks-tanim-liste">';
    foreach ($liste as $t) {
        $id = (int)$t['id']; $aktif = (int)$t['aktif'] === 1; $kul = (int)$t['kullanim'];
        $alt = 'Sıra ' . (int)$t['sira'] . ($kul > 0 ? ' · ' . $kul . ' harekette kullanıldı' : '');
        $h .= '<li class="ks-tanim-satir' . ($aktif ? '' : ' ks-tanim-satir--pasif') . '" data-id="' . $id . '"><div class="ks-tanim-ad">' . h($t['ad']) . '<small>' . h($alt) . '</small></div><div class="ks-tanim-eylem">';
        if ($yaz) {
            $h .= '<form method="post" action="kasa_kaydet.php" data-ks-ajax data-ok="Tanım güncellendi" class="ks-icerik"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="islem" value="tanim_durum">'
                . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="geri" value="' . h($geri) . '">'
                . '<label class="ks-aktif"><input type="checkbox" role="switch" data-id="' . $id . '" name="aktif" value="1"' . ($aktif ? ' checked' : '') . ' aria-label="' . h($t['ad']) . ' aktif"><span>Aktif</span></label>'
                . '<noscript><button type="submit" class="btn btn-sm">Uygula</button></noscript></form>'
                . '<button type="button" class="btn btn-sm" data-tanim-duzen aria-label="' . h($t['ad']) . ' düzenle">Düzenle</button>';
            if ($kul === 0) {
                $h .= '<form method="post" action="kasa_kaydet.php" data-ks-ajax data-ok="Tanım silindi" data-onay="Bu kalem silinecek. Emin misiniz?" class="ks-icerik"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="islem" value="tanim_sil">'
                    . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="geri" value="' . h($geri) . '"><button type="submit" class="btn btn-sm ks-tehlike" aria-label="' . h($t['ad']) . ' sil">Sil</button></form>';
            }
        } else {
            $h .= '<span class="ks-rozet ' . ($aktif ? 'ks-rozet--aktif' : 'ks-rozet--pasif') . '">' . ($aktif ? 'Aktif' : 'Pasif') . '</span>';
        }
        $h .= '</div></li>';
        if ($yaz) {
            $h .= '<li class="ks-tanim-satir ks-tanim-satir--duzen" hidden><form method="post" action="kasa_kaydet.php" data-ks-ajax data-ok="Tanım güncellendi" class="ks-tanim-duzen-form" novalidate>'
                . '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="islem" value="tanim_kaydet"><input type="hidden" name="tur" value="' . h($tur) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="geri" value="' . h($geri) . '">'
                . '<label class="pm-label"><span>Ad</span><input type="text" name="ad" value="' . h($t['ad']) . '" maxlength="100" required></label>'
                . '<label class="pm-label ks-tanim-sira"><span>Sıra</span><input type="number" name="sira" value="' . (int)$t['sira'] . '" min="0" max="99999" inputmode="numeric"></label>'
                . '<div class="ks-tanim-eylem"><button type="button" class="btn" data-tanim-vazgec>Vazgeç</button><button type="submit" class="btn btn-primary">Kaydet</button></div></form></li>';
        }
    }
    return $h . '</ul></section>';
}

/** Tanımlar sayfası gövdesi. ?sekme=kasa|gelir|gider · ?yeni=1 (kasa sekmesinde yeni kasa modalı açık) */
function kasa_tanimlari_govde(PDO $pdo): string
{
    $yaz = kasa_can('tanim');
    $sekme = (string)($_GET['sekme'] ?? 'kasa');
    if (!in_array($sekme, ['kasa', 'gelir', 'gider'], true)) $sekme = 'kasa';
    $sek = ['kasa' => ['ksTabKasa', 'ksPanelKasa', 'Kasalar'], 'gelir' => ['ksTabGelir', 'ksPanelGelir', 'Gelir Kalemleri'], 'gider' => ['ksTabGider', 'ksPanelGider', 'Gider Kalemleri']];
    $nav = ''; $paneller = '';
    foreach ($sek as $k => [$tab, $panel, $ad]) {
        $say = $k === 'kasa' ? count(kasa_kart_listesi($pdo)['satirlar']) : count(kasa_tanim_listesi($pdo, $k));
        $nav .= '<a class="ks-tanim-nav-oge" role="tab" id="' . $tab . '" href="kasa_tanimlari.php?sekme=' . $k . '" data-sekme="' . $k . '" aria-selected="' . ($sekme === $k ? 'true' : 'false') . '" aria-controls="' . $panel . '">' . h($ad) . ' <small>' . $say . '</small></a>';
        $paneller .= $k === 'kasa' ? kasa_tanim_kasa_panel($pdo, $sekme !== $k, $yaz) : kasa_tanim_panel($pdo, $k, $panel, $tab, $sekme !== $k, kasa_can('tanim'));
    }
    $yeni = $yaz && $sekme === 'kasa' && ($_GET['yeni'] ?? '') === '1';
    return '<div class="kasa" id="kasa" data-sayfa="tanim"' . ($yeni ? ' data-yeni="kart"' : '') . '>' . kasa_ust_menu('tanimlar') . '<div class="ks-ustsatir"><h1>Kasa Tanımları</h1></div><div class="ks-tanim">'
        . '<div class="ks-tanim-nav" role="tablist" aria-label="Tanım türleri">' . $nav . '</div><div class="ks-tanim-icerik">' . $paneller . '</div></div>'
        . '<p class="ks-bilgi-kutu">Para birimi başına bakiye tutulur; farklı para birimleri birbirine eklenmez. Kasa hareketleri silinmez, düzeltme iptal + yeni kayıtla yapılır.</p>'
        . ($yaz ? kasa_modal_kart(kasa_kod_oner($pdo)) : '') . '</div>';
}

// ── Kasa defteri çıktısı (kasa_cikti.php) ───────────────────────────────────

/**
 * A4 dikey kasa defteri gövdesi (siyah-beyaz). $d: kasa_defter() sonucu; $firma: siparis_inv_ihracatci().
 * @param array{kasa:array,pb:string,bas:string,bit:string,devir:int,satirlar:list<array>,donem_giris:int,donem_cikis:int,kapanis:int} $d
 */
function kasa_defter_yazdir_html(array $d, array $firma): string
{
    $pb = $d['pb']; $k = $d['kasa'];
    $donem = ($d['bas'] !== '' ? kasa_tarih_tr($d['bas']) : 'başlangıç') . ' – ' . ($d['bit'] !== '' ? kasa_tarih_tr($d['bit']) : kasa_tarih_tr(date('Y-m-d')));
    $trs = '<tr class="kasa-yz-devir"><td colspan="5">DEVİR (dönem başı bakiye)</td><td class="ks-sayi"></td><td class="ks-sayi"></td><td class="ks-sayi">' . h(kasa_para($d['devir'], $pb)) . '</td></tr>';
    foreach ($d['satirlar'] as $r) {
        [$ana, $alt] = kasa_satir_aciklama($r);
        $trs .= '<tr><td class="ks-tar">' . h(kasa_tarih_tr((string)$r['tarih'])) . '</td><td>' . h($r['belge_no']) . '</td><td>' . h(kasa_tur_bilgi((string)$r['tur'], true)['etiket'] ?? (string)$r['tur']) . '</td>'
            . '<td>' . h($ana) . ($alt !== '' ? '<br><small>' . h($alt) . '</small>' : '') . '</td><td>' . h((string)($r['evrak_no'] ?? '')) . '</td>'
            . '<td class="ks-sayi">' . ($r['yon'] === 'G' ? h(kasa_para((int)$r['tutar_kurus'], $pb)) : '') . '</td><td class="ks-sayi">' . ($r['yon'] === 'C' ? h(kasa_para((int)$r['tutar_kurus'], $pb)) : '') . '</td>'
            . '<td class="ks-sayi">' . h(kasa_para((int)$r['yurume'], $pb)) . '</td></tr>';
    }
    if (!$d['satirlar']) $trs .= '<tr><td colspan="8" class="kasa-yz-bos">Bu dönemde hareket yok.</td></tr>';
    $meta = array_filter([(string)$k['kod'], kasa_tipler()[$k['tip']] ?? '', $pb, $k['tip'] === 'banka' ? trim((string)($k['banka_adi'] ?? '') . ' ' . (string)($k['sube'] ?? '')) : '', $k['tip'] === 'banka' ? (string)($k['iban'] ?? '') : ''], static fn($x) => $x !== '');
    return '<div class="kasa-yz"><div class="kasa-yz-ust"><div class="kasa-yz-firma"><b>' . h($firma['unvan'] ?? '') . '</b></div>'
        . '<div class="kasa-yz-belge"><div class="kasa-yz-tur">KASA DEFTERİ</div><table><tr><th>Kasa</th><td>' . h($k['ad']) . '</td></tr><tr><th>Bilgi</th><td>' . h(implode(' · ', $meta)) . '</td></tr>'
        . '<tr><th>Dönem</th><td>' . h($donem) . '</td></tr><tr><th>Basım</th><td>' . h(date('d.m.Y H:i')) . '</td></tr></table></div></div>'
        . '<table class="kasa-yz-satir"><thead><tr><th>Tarih</th><th>Belge No</th><th>Tür</th><th>Açıklama</th><th>Evrak</th><th class="ks-sayi">Giriş</th><th class="ks-sayi">Çıkış</th><th class="ks-sayi">Bakiye</th></tr></thead><tbody>' . $trs . '</tbody></table>'
        . '<table class="kasa-yz-toplam"><tr><th>Devir</th><td data-yz="devir">' . h(kasa_para($d['devir'], $pb)) . '</td></tr><tr><th>Dönem girişi</th><td data-yz="giris">' . h(kasa_para($d['donem_giris'], $pb)) . '</td></tr>'
        . '<tr><th>Dönem çıkışı</th><td data-yz="cikis">' . h(kasa_para($d['donem_cikis'], $pb)) . '</td></tr><tr class="kasa-yz-buyuk"><th>KAPANIŞ BAKİYESİ</th><td data-yz="kapanis">' . h(kasa_para($d['kapanis'], $pb)) . '</td></tr></table>'
        . '<div class="kasa-yz-imza"><div>Hazırlayan</div><div>Kontrol / Onay</div></div>'
        . '<div class="kasa-yz-alt">İptal edilen hareketler deftere dahil değildir. Bu çıktı bilgi amaçlıdır, resmî defter değildir.</div></div>';
}
