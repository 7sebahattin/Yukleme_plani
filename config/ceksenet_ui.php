<?php
// =========================================================
// config/ceksenet_ui.php — Çek-Senet render yardımcıları (include-only; yalnız fonksiyon bildirir)
//
// Sayfalar (cek_dekontu / senet_dekontu / cek_tanimlari / cek_cikti) veriyi config/ceksenet_core.php FONKSİYONLARINDAN okur, işaretlemeyi BURADAN alır.
// Sınıf öneki `cs-`, kök `.ceksenet#ceksenet`, ID/data-* kancaları `cs*` (assets/ceksenet.js sözleşmesi). Burada SORGU YAZILMAZ, hiçbir yazma yoktur.
// İşlem modalının alanları ceksenet_islemler() yapısından (JSON) türetilir — durum makinesi elle çoğaltılmaz.
// HTML'e giren HER dinamik değer h() ile kaçışlanır. Para kuruş tam sayıdır; para birimleri ASLA toplanmaz.
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

// ── Varlıklar / ortak parçalar ──────────────────────────────────────────────

function ceksenet_assets(): void
{
    echo '<link rel="stylesheet" href="assets/ceksenet.css?v=' . (int)@filemtime(dirname(__DIR__) . '/assets/ceksenet.css') . '">' . "\n";
}

function ceksenet_scripts(): void
{
    echo '<script src="assets/ceksenet.js?v=' . (int)@filemtime(dirname(__DIR__) . '/assets/ceksenet.js') . '"></script>' . "\n";
}

/** Üst sekme şeridi. $aktif: cek|senet|tanim */
function ceksenet_ust_menu(string $aktif): string
{
    $s = ['cek' => ['cek_dekontu.php', 'Çek Dekontu'], 'senet' => ['senet_dekontu.php', 'Senet Dekontu'], 'tanim' => ['cek_tanimlari.php', 'Çek Tanımları']];
    $o = '<nav class="cs-sekmeler" aria-label="Çek-Senet">';
    foreach ($s as $k => [$url, $ad]) $o .= '<a class="cs-sekme" href="' . h($url) . '"' . ($k === $aktif ? ' aria-current="page"' : '') . '>' . h($ad) . '</a>';
    return $o . '<a class="cs-sekme cs-sekme--dis" href="kasa_fisleri.php">Kasa</a><a class="cs-sekme cs-sekme--dis2" href="cari_kartlari.php">Cari İşlemler</a></nav>';
}

/** Şema yok kartı. $eksik: ceksenet_eksik_semalar() — sıra: Cari, Kasa, Çek-Senet. @param list<array{ad:string,kart:string}> $eksik */
function ceksenet_sema_uyari(array $eksik): string
{
    $lnk = '';
    foreach ($eksik as $e) $lnk .= '<a class="btn btn-primary" data-sema-eksik="' . h($e['kart']) . '" href="migrate.php#' . h($e['kart']) . '">Şema Migrasyon &rsaquo; ' . h($e['ad']) . '</a> ';
    return '<div class="ceksenet"><div class="cs-uyari" data-cs-sema role="alert"><span class="cs-uyari-ikon" aria-hidden="true">&#9888;</span><div>'
        . '<h2>Çek-Senet tabloları kurulmamış</h2>'
        . '<p>Çek-Senet modülünün veritabanı tabloları bu sunucuda henüz oluşturulmadı. Yönetici: Şema Migrasyon sayfasında sırasıyla <b>Cari İşlemler</b>, <b>Kasa</b> ve <b>Çek-Senet</b> kartlarını çalıştırın.</p>'
        . '<div class="cs-uyari-eylem">' . $lnk . '</div></div></div></div>';
}

function ceksenet_bos(string $baslik, string $ack, string $eylem = '', string $etiket = 'h2'): string
{
    return '<div class="cs-bos"><' . $etiket . '>' . h($baslik) . '</' . $etiket . '><p>' . h($ack) . '</p>' . ($eylem !== '' ? '<div class="cs-bos-eylem">' . $eylem . '</div>' : '') . '</div>';
}

function ceksenet_json_script(string $id, mixed $v): string
{
    return '<script type="application/json" id="' . h($id) . '">' . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
}

function ceksenet_json_attr(mixed $v): string { return h((string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }

function ceksenet_tarih_tr(?string $d): string
{
    return $d !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) ? $m[3] . '.' . $m[2] . '.' . $m[1] : (string)$d;
}

/** "1.234,56 ₺" */
function ceksenet_para(int $kurus, string $pb): string { return cari_tutar_bicim($kurus, $pb); }

/** Durum rozeti (renk + metin). $k: ceksenet_listesi / ceksenet_oku kayıt satırı. */
function ceksenet_durum_rozet(array $k): string
{
    return '<span class="cs-rozet ' . h((string)($k['durum_sinif'] ?? 'cs-d-iptal')) . '">' . h((string)($k['durum_etiket'] ?? $k['durum'] ?? '')) . '</span>';
}

/** Vade metni: geçmiş açık evrak kırmızı, ≤7 gün sarı (renk + gün metni). */
function ceksenet_vade_html(array $k): string
{
    $sn = ''; $not = '';
    $g = (int)$k['vade_gun'];
    if (!empty($k['vade_gecmis'])) { $sn = ' cs-vade--gecmis'; $not = abs($g) . ' gün geçti'; }
    elseif (!empty($k['vade_yakin'])) { $sn = ' cs-vade--yakin'; $not = $g === 0 ? 'bugün' : $g . ' gün kaldı'; }
    return '<span class="cs-vade' . $sn . '">' . h(ceksenet_tarih_tr((string)$k['vade'])) . ($not !== '' ? ' <small>' . h($not) . '</small>' : '') . '</span>';
}

/** Evrak başlığı (satır ana metni): çekte banka + seri, senette borçlu + seri. */
function ceksenet_evrak_baslik(array $k): array
{
    if ((string)$k['sinif'] === 'cek') return [trim((string)($k['banka_ad'] ?? '') . ' ' . (string)$k['seri_no']), trim((string)($k['borclu'] ?? '')) !== '' ? 'Keşideci: ' . $k['borclu'] : ''];
    return [trim((string)($k['borclu'] ?? '') . ' ' . (string)$k['seri_no']), (string)($k['kefil'] ?? '') !== '' ? 'Kefil: ' . $k['kefil'] : ''];
}

/** pb başına metin satırları: "<b>1.234,00 ₺</b> · 3 adet". @param array<string,array{adet?:int,kurus?:int,toplam_kurus?:int}> $m */
function ceksenet_pb_satirlari(array $m, string $kls = 'cs-pb-satir'): string
{
    $o = '';
    foreach ($m as $pb => $x) {
        $k = (int)($x['kurus'] ?? $x['toplam_kurus'] ?? 0);
        $o .= '<span class="' . $kls . '" data-pb="' . h((string)$pb) . '"><b>' . h(ceksenet_para($k, (string)$pb)) . '</b>' . (isset($x['adet']) ? ' <small>' . (int)$x['adet'] . ' adet</small>' : '') . '</span>';
    }
    return $o;
}

// ── Süzgeç ──────────────────────────────────────────────────────────────────

/** Süzgeç değerleri (GET). @return array{yon:string,ara:string,durum:string,donem:string,bas:string,bit:string,cari:int,sayfa:int} */
function ceksenet_filtre(): array
{
    $yon = (string)($_GET['yon'] ?? 'alinan');
    if (!isset(ceksenet_yonler()[$yon])) $yon = 'alinan';
    $durum = (string)($_GET['durum'] ?? 'acik');
    if ($durum !== 'acik' && $durum !== 'tumu' && !isset(ceksenet_durumlar()[$durum])) $durum = 'acik';
    $donem = (string)($_GET['donem'] ?? 'tumu');
    if (!in_array($donem, ['tumu', 'gecmis', '7g', '30g', 'bu_ay', 'ozel'], true)) $donem = 'tumu';
    return ['yon' => $yon, 'ara' => mb_substr(trim((string)($_GET['ara'] ?? '')), 0, 100), 'durum' => $durum, 'donem' => $donem,
        'bas' => $donem === 'ozel' ? (ceksenet_vade_coz($_GET['bas'] ?? '') ?? '') : '', 'bit' => $donem === 'ozel' ? (ceksenet_vade_coz($_GET['bit'] ?? '') ?? '') : '',
        'cari' => max(0, (int)($_GET['cari'] ?? 0)), 'sayfa' => max(1, (int)($_GET['sayfa'] ?? 1))];
}

/** Süzgeç → ceksenet_listesi $f. */
function ceksenet_liste_suzgec(array $F): array
{
    return ['yon' => $F['yon'], 'durum' => $F['durum'], 'cari' => $F['cari'], 'ara' => $F['ara'], 'donem' => $F['donem'], 'bas' => $F['bas'], 'bit' => $F['bit']];
}

/** Süzgeç → sorgu dizgesi (varsayılanlar atılır; yon HER ZAMAN yazılır). @param array<string,mixed> $ek ek/ezen parametreler */
function ceksenet_sorgu(array $F, array $ek = []): string
{
    $q = ['yon' => $F['yon'], 'ara' => $F['ara'], 'durum' => $F['durum'] !== 'acik' ? $F['durum'] : '', 'donem' => $F['donem'] !== 'tumu' ? $F['donem'] : '',
        'bas' => $F['bas'], 'bit' => $F['bit'], 'cari' => $F['cari'] ?: '', 'sayfa' => $F['sayfa'] > 1 ? $F['sayfa'] : ''];
    foreach ($ek as $k => $v) $q[$k] = $v;
    return http_build_query(array_filter($q, static fn($x) => $x !== '' && $x !== null && $x !== 0));
}

function ceksenet_url(string $sinif, array $F, array $ek = []): string
{
    $s = ceksenet_sorgu($F, $ek);
    return (ceksenet_siniflar()[$sinif]['sayfa'] ?? 'cek_dekontu.php') . ($s !== '' ? '?' . $s : '');
}

function ceksenet_ui_sayfa_boyu(): int { return 100; }

// ── Özet kartları ───────────────────────────────────────────────────────────

/** @param array $oz ceksenet_ozet() sonucu */
function ceksenet_ozet_html(string $sinif, array $F, array $oz): string
{
    $yon = $F['yon'];
    $d = $oz['durum'];
    $kart = static function (string $ad, array $m, string $url, string $sn, string $anahtar) use ($sinif): string {
        $adet = 0; foreach ($m as $x) $adet += (int)$x['adet'];
        return '<a class="cs-ozet-kart ' . $sn . '" href="' . h($url) . '" data-ozet="' . h($anahtar) . '"><span class="cs-ozet-ad">' . h($ad) . '</span>'
            . '<span class="cs-ozet-deger">' . ($m ? ceksenet_pb_satirlari($m) : '<b class="cs-ozet-yok">—</b>') . '</span><span class="cs-ozet-adet">' . $adet . ' adet</span></a>';
    };
    $u = static fn(array $ek): string => ceksenet_url($sinif, $F, $ek + ['sayfa' => '']);
    if ($yon === 'alinan') {
        $o = $kart('Portföyde', $d['portfoy'] ?? [], $u(['durum' => 'portfoy', 'donem' => '']), 'cs-ozet--portfoy', 'portfoy')
            . $kart('Tahsilde', $d['tahsilde'] ?? [], $u(['durum' => 'tahsilde', 'donem' => '']), 'cs-ozet--tahsilde', 'tahsilde');
        if (!empty($d['teminatta'])) $o .= $kart('Teminatta', $d['teminatta'], $u(['durum' => 'teminatta', 'donem' => '']), 'cs-ozet--teminatta', 'teminatta');
        $o .= $kart('Ciro edilen', $d['ciro'] ?? [], $u(['durum' => 'ciro', 'donem' => '']), 'cs-ozet--ciro', 'ciro');
    } else {
        $o = $kart('Ödenecek', $d['verildi'] ?? [], $u(['durum' => 'verildi', 'donem' => '']), 'cs-ozet--verildi', 'verildi')
            . $kart('Ödenen', $d['odendi'] ?? [], $u(['durum' => 'odendi', 'donem' => '']), 'cs-ozet--odendi', 'odendi');
    }
    $o .= $kart('Vadesi geçen', $oz['vadesi_gecmis'], $u(['durum' => 'acik', 'donem' => 'gecmis']), 'cs-ozet--gecmis', 'gecmis');
    return '<div class="cs-ozet" role="group" aria-label="Özet (para birimleri ayrı)">' . $o . '</div>';
}

// ── Liste ───────────────────────────────────────────────────────────────────

function ceksenet_liste_html(array $satirlar, string $sinif, array $F, bool $yazabilir): string
{
    $trs = ''; $kartlar = '';
    foreach ($satirlar as $k) {
        $id = (int)$k['id']; $iptal = (string)$k['durum'] === 'iptal'; $pb = (string)$k['para_birimi'];
        [$ana, $alt] = ceksenet_evrak_baslik($k);
        $url = h(ceksenet_url($sinif, $F, ['id' => $id]));
        $oznitelik = ' data-id="' . $id . '" data-vade="' . h((string)$k['vade']) . '" data-kurus="' . (int)$k['tutar_kurus'] . '" data-pb="' . h($pb) . '" data-izinli="' . h(implode(' ', $k['izinli'])) . '" data-durum="' . h((string)$k['durum']) . '"'
            . ' data-karne="' . (int)($k['karne_id'] ?? 0) . '" data-portfoy="' . h((string)$k['portfoy_no']) . '"';
        $sec = ($yazabilir && !$iptal && $k['izinli']) ? '<input type="checkbox" class="cs-sec" data-cs-sec value="' . $id . '" aria-label="' . h($k['portfoy_no'] . ' seç') . '">' : '';
        $cari = (string)($k['cari_unvan'] ?? '');
        $yer = (string)($k['yer_cari_unvan'] ?? '') !== '' ? '→ ' . $k['yer_cari_unvan'] : ((string)($k['yer_kasa_ad'] ?? '') !== '' ? '→ ' . $k['yer_kasa_ad'] : '');
        $trs .= '<tr class="cs-satir' . ($iptal ? ' cs-iptal' : '') . '"' . $oznitelik . '>'
            . '<td class="cs-sec-hucre">' . $sec . '</td>'
            . '<td class="cs-evrak"><span class="cs-evrak-ana">' . h($ana) . '</span><small class="cs-no"><a href="' . $url . '" data-cs-detay data-id="' . $id . '">' . h((string)$k['portfoy_no']) . '</a></small>' . ($alt !== '' ? '<small>' . h($alt) . '</small>' : '') . '</td>'
            . '<td class="cs-cari" title="' . h($cari) . '">' . h($cari) . ($yer !== '' ? '<small>' . h($yer) . '</small>' : '') . '</td>'
            . '<td class="cs-vade-hucre">' . ceksenet_vade_html($k) . '</td>'
            . '<td class="cs-sayi">' . h(ceksenet_para((int)$k['tutar_kurus'], $pb)) . '</td>'
            . '<td class="cs-durum-hucre">' . ceksenet_durum_rozet($k) . '</td></tr>';
        $kartlar .= '<article class="cs-hk' . ($iptal ? ' cs-iptal' : '') . '"' . $oznitelik . '>' . ($sec !== '' ? '<label class="cs-hk-sec">' . $sec . '</label>' : '<span class="cs-hk-sec"></span>')
            . '<a class="cs-hk-baglanti" href="' . $url . '" data-cs-detay data-id="' . $id . '" aria-label="' . h($k['portfoy_no'] . ' ayrıntısı') . '">'
            . '<span class="cs-hk-ust"><span class="cs-hk-no">' . h((string)$k['portfoy_no']) . '</span><span class="cs-hk-tutar">' . h(ceksenet_para((int)$k['tutar_kurus'], $pb)) . '</span></span>'
            . '<span class="cs-hk-ana">' . h($ana) . '</span>' . ($cari !== '' ? '<span class="cs-hk-alt">' . h($cari) . '</span>' : '')
            . '<span class="cs-hk-dip">' . ceksenet_vade_html($k) . ceksenet_durum_rozet($k) . '</span></a></article>';
    }
    return '<div class="cs-tablo-kaydir table-wrap"><table class="data-table cs-tablo"><caption class="cs-sr">' . h(ceksenet_siniflar()[$sinif]['cogul']) . ' (vadeye göre)</caption><thead><tr>'
        . '<th scope="col" class="cs-sec-hucre"><input type="checkbox" class="cs-sec" data-cs-hepsi aria-label="Görünenlerin hepsini seç"></th><th scope="col">' . ($sinif === 'cek' ? 'Banka / çek no' : 'Borçlu / senet no') . '</th><th scope="col">Cari</th>'
        . '<th scope="col">Vade</th><th scope="col" class="cs-sayi">Tutar</th><th scope="col">Durum</th></tr></thead><tbody>' . $trs . '</tbody></table></div>'
        . '<div class="cs-hk-liste" aria-label="' . h(ceksenet_siniflar()[$sinif]['cogul']) . '">' . $kartlar . '</div>';
}

function ceksenet_filtre_html(string $sinif, array $F, ?string $cariAd): string
{
    $sayfa = ceksenet_siniflar()[$sinif]['sayfa'];
    $cip = '';
    foreach (['acik' => 'Açık', 'tumu' => 'Tümü'] as $d => $ad) $cip .= '<a class="cs-cip" href="' . h(ceksenet_url($sinif, $F, ['durum' => $d === 'acik' ? '' : $d, 'sayfa' => ''])) . '"' . ($F['durum'] === $d && $F['donem'] !== 'gecmis' ? ' aria-current="true"' : '') . '>' . h($ad) . '</a>';
    $cip .= '<a class="cs-cip cs-cip--gecmis" href="' . h(ceksenet_url($sinif, $F, ['durum' => '', 'donem' => 'gecmis', 'sayfa' => ''])) . '"' . ($F['donem'] === 'gecmis' ? ' aria-current="true"' : '') . '>Vadesi geçmiş</a>';
    if ($F['cari'] > 0) $cip .= '<a class="cs-cip cs-cip--cari" href="' . h(ceksenet_url($sinif, $F, ['cari' => '', 'sayfa' => ''])) . '" aria-label="Cari süzgecini kaldır">' . h($cariAd ?? ('Cari #' . $F['cari'])) . ' &#10005;</a>';
    $durOpt = '<option value="acik"' . ($F['durum'] === 'acik' ? ' selected' : '') . '>Açık durumlar</option><option value="tumu"' . ($F['durum'] === 'tumu' ? ' selected' : '') . '>Tüm durumlar</option>';
    foreach (ceksenet_durumlar() as $ad => $d) {
        if ($d['yon'] !== 'her' && $d['yon'] !== $F['yon']) continue;
        $durOpt .= '<option value="' . h($ad) . '"' . ($F['durum'] === $ad ? ' selected' : '') . '>' . h(ceksenet_durum_etiketi($ad, $sinif)) . '</option>';
    }
    $donOpt = '';
    foreach (['tumu' => 'Tüm vadeler', 'gecmis' => 'Vadesi geçmiş', '7g' => 'Önümüzdeki 7 gün', '30g' => 'Önümüzdeki 30 gün', 'bu_ay' => 'Bu ay', 'ozel' => 'Vade aralığı'] as $v => $ad) $donOpt .= '<option value="' . $v . '"' . ($F['donem'] === $v ? ' selected' : '') . '>' . h($ad) . '</option>';
    return '<form method="get" action="' . h($sayfa) . '" class="cs-filtre" role="search"><input type="hidden" name="yon" value="' . h($F['yon']) . '">' . ($F['cari'] > 0 ? '<input type="hidden" name="cari" value="' . $F['cari'] . '">' : '')
        . '<div class="cs-arama"><svg class="cs-arama-ikon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>'
        . '<label class="cs-sr" for="csArama">Ara: numara, banka, borçlu, cari, bordro</label><input type="search" id="csArama" name="ara" placeholder="Numara, banka, borçlu, cari, bordro" autocomplete="off" value="' . h($F['ara']) . '"><kbd class="cs-arama-ipucu" aria-hidden="true">/</kbd></div>'
        . '<select name="durum" data-oto-gonder aria-label="Durum">' . $durOpt . '</select><select name="donem" data-oto-gonder aria-label="Vade dönemi" data-cs-donem>' . $donOpt . '</select>'
        . '<div class="cs-ozel-tarih"' . ($F['donem'] === 'ozel' ? '' : ' hidden') . '><label><span class="cs-sr">Vade başlangıç</span><input type="date" name="bas" value="' . h($F['bas']) . '" aria-label="Vade başlangıcı"></label>'
        . '<label><span class="cs-sr">Vade bitiş</span><input type="date" name="bit" value="' . h($F['bit']) . '" aria-label="Vade bitişi"></label><button type="submit" class="btn btn-sm">Uygula</button></div>'
        . '<noscript><button type="submit" class="btn btn-sm">Uygula</button></noscript><div class="cs-cipler" role="group" aria-label="Hızlı süzgeç">' . $cip . '</div></form>';
}

// ── Detay (panel içeriği; ?parca=detay) ─────────────────────────────────────

/** Kaydı okur (aktif depoda görünmüyorsa null). ceksenet_oku() zaten depo süzgeçlidir. */
function ceksenet_detay_oku(PDO $pdo, int $id): ?array { return $id > 0 ? ceksenet_oku($pdo, $id) : null; }

function ceksenet_detay_html(array $o): string
{
    $k = $o['kayit']; $har = $o['hareketler'];
    $id = (int)$k['id']; $sinif = (string)$k['sinif']; $pb = (string)$k['para_birimi'];
    $sat = static fn(string $et, string $deger): string => $deger === '' ? '' : '<div class="cs-dl-satir"><dt>' . h($et) . '</dt><dd>' . $deger . '</dd></div>';
    $d = '';
    $d .= $sat('Tutar', '<b class="cs-detay-tutar">' . h(ceksenet_para((int)$k['tutar_kurus'], $pb)) . '</b>');
    $d .= $sat('Vade', ceksenet_vade_html($k));
    if ((string)$k['duzenleme_tarihi'] !== '') $d .= $sat('Düzenleme', h(ceksenet_tarih_tr((string)$k['duzenleme_tarihi'])));
    $d .= $sat($sinif === 'cek' ? 'Çek no' : 'Senet no', h((string)$k['seri_no']));
    if ($sinif === 'cek') {
        $d .= $sat('Banka', h(trim((string)$k['banka_ad'] . ((string)$k['sube'] !== '' ? ' · ' . $k['sube'] : ''))));
        $d .= $sat('Hesap / IBAN', h((string)$k['hesap_no']));
        $d .= $sat('Keşideci', h((string)$k['borclu']));
        $d .= $sat('Keşide yeri', h((string)$k['yer_adi']));
    } else {
        $d .= $sat('Borçlu', h((string)$k['borclu']));
        $d .= $sat('Kefil', h((string)$k['kefil']));
        $d .= $sat('Ödeme yeri', h((string)$k['yer_adi']));
    }
    if ((string)($k['cari_unvan'] ?? '') !== '') $d .= $sat($k['yon'] === 'alinan' ? 'Alındığı cari' : 'Verildiği cari', '<a href="cari_kartlari.php?id=' . (int)$k['cari_id'] . '">' . h($k['cari_unvan']) . '</a>');
    if ((string)($k['yer_cari_unvan'] ?? '') !== '') $d .= $sat('Ciro edilen', '<a href="cari_kartlari.php?id=' . (int)$k['yer_cari_id'] . '">' . h($k['yer_cari_unvan']) . '</a>');
    if ((string)($k['yer_kasa_ad'] ?? '') !== '') $d .= $sat('Banka', h($k['yer_kasa_ad']));
    if ((string)$k['bordro_no'] !== '') $d .= $sat('Bordro', '<a href="cek_cikti.php?bordro=' . h(rawurlencode((string)$k['bordro_no'])) . '" target="_blank" rel="noopener">' . h((string)$k['bordro_no']) . '</a>');
    if ((string)$k['aciklama'] !== '') $d .= $sat('Açıklama', nl2br(h((string)$k['aciklama'])));

    $btn = '';
    if (ceksenet_can('write')) foreach ($k['izinli'] as $i) $btn .= '<button type="button" class="btn cs-islem-btn cs-islem-btn--' . h($i) . '" data-cs-islem="' . h($i) . '">' . h(ceksenet_islem_etiketi($i, $sinif)) . '</button>';
    $btn = $btn !== '' ? '<div class="cs-islemler" role="toolbar" aria-label="İşlemler">' . $btn . '</div>' : '';

    $zc = '';
    foreach ($har as $h) {
        $iptal = (string)$h['durum'] === 'iptal';
        $gecis = ($h['onceki_durum'] !== null ? h((string)$h['onceki_durum_etiket']) . ' &rarr; ' : '') . '<b>' . h((string)$h['yeni_durum_etiket']) . '</b>';
        $bagli = '';
        foreach ([['cari_belge_no', 'Cari'], ['cari_belge_no2', 'Cari'], ['kasa_belge_no', 'Kasa']] as [$a, $et]) if (!empty($h[$a])) $bagli .= '<span class="cs-zc-bag">' . $et . ' ' . h((string)$h[$a]) . '</span>';
        if ((string)($h['cari_unvan'] ?? '') !== '') $bagli .= '<span class="cs-zc-bag">' . h((string)$h['cari_unvan']) . '</span>';
        if ((string)($h['kasa_ad'] ?? '') !== '') $bagli .= '<span class="cs-zc-bag">' . h((string)$h['kasa_ad']) . '</span>';
        $zc .= '<li class="cs-zc' . ($iptal ? ' cs-zc--iptal' : '') . '" data-hareket="' . (int)$h['id'] . '"><div class="cs-zc-ust"><b>' . h((string)$h['islem_etiket']) . '</b><span>' . h(ceksenet_tarih_tr((string)$h['tarih'])) . '</span></div>'
            . '<div class="cs-zc-gecis">' . $gecis . ($iptal ? ' <span class="cs-rozet cs-d-iptal">İPTAL</span>' : '') . '</div>'
            . ((string)$h['belge_no'] !== '' || (string)($h['bordro_no'] ?? '') !== '' ? '<div class="cs-zc-belge">' . h((string)$h['belge_no'])
                . ((string)($h['bordro_no'] ?? '') !== '' ? ((string)$h['belge_no'] !== '' ? ' · ' : '') . '<a href="cek_cikti.php?bordro=' . h(rawurlencode((string)$h['bordro_no'])) . '" target="_blank" rel="noopener" data-bordro-bag>' . h((string)$h['bordro_no']) . '</a>' : '') . '</div>' : '')
            . ($bagli !== '' ? '<div class="cs-zc-bagli">' . $bagli . '</div>' : '')
            . ((string)$h['aciklama'] !== '' ? '<div class="cs-zc-ack">' . h((string)$h['aciklama']) . '</div>' : '')
            . ($iptal ? '<div class="cs-zc-neden">İptal nedeni: ' . h((string)$h['iptal_neden']) . '</div>' : '')
            . (!empty($h['son']) && ceksenet_can('delete') ? '<button type="button" class="btn btn-sm cs-tehlike" data-cs-iptal-ac data-hareket="' . (int)$h['id'] . '" data-belge="' . h((string)$h['belge_no']) . '">Bu hareketi iptal et</button>' : '')
            . '</li>';
    }
    return '<div class="cs-detay" id="csDetay" data-id="' . $id . '" data-sinif="' . h($sinif) . '" data-yon="' . h((string)$k['yon']) . '" data-pb="' . h($pb) . '" data-durum="' . h((string)$k['durum']) . '" data-karne="' . (int)($k['karne_id'] ?? 0) . '" data-portfoy="' . h((string)$k['portfoy_no']) . '">'
        . '<div class="cs-detay-ust"><h3 class="cs-detay-no">' . h((string)$k['portfoy_no']) . '</h3>' . ceksenet_durum_rozet($k) . '</div>'
        . $btn . '<dl class="cs-dl">' . $d . '</dl><h4 class="cs-alt-baslik">Hareket geçmişi</h4><ol class="cs-zaman">' . $zc . '</ol></div>';
}

/** Panel boş durumu. */
function ceksenet_panel_bos(): string
{
    return '<div class="cs-panel-bos"><p><b>Evrak seçin</b></p><p>Soldaki listeden bir çek / senet seçince ayrıntısı, hareket geçmişi ve yapılabilecek işlemler burada görünür.</p></div>';
}

/** Açık çek-senet özeti (cari kartı parçası; ?parca=cari_ozet&cari=ID). */
function ceksenet_cari_ozet_html(PDO $pdo, int $cariId): string
{
    $satirlar = ceksenet_cari_ozet($pdo, $cariId);
    if (!$satirlar) return '<div class="cs-cari-ozet cs-cari-ozet--bos" data-cari="' . $cariId . '">Bu carinin açık çek / senedi yok.</div>';
    $o = '';
    foreach ($satirlar as $r) {
        $o .= '<li><span>' . h(ceksenet_siniflar()[$r['sinif']]['etiket'] . ' · ' . ceksenet_yonler()[$r['yon']]) . '</span><b>' . h(ceksenet_para((int)$r['kurus'], (string)$r['pb'])) . '</b> <small>' . (int)$r['adet'] . ' adet'
            . ((int)$r['gecmis_adet'] > 0 ? ' · <span class="cs-vade--gecmis">' . (int)$r['gecmis_adet'] . ' vadesi geçmiş</span>' : '') . '</small></li>';
    }
    return '<div class="cs-cari-ozet" data-cari="' . $cariId . '"><div class="cs-cari-ozet-bas">Açık çek-senet</div><ul>' . $o . '</ul></div>';
}

/** ?parca=detay&id= · ?parca=cari_ozet&cari= kısmi yanıtı (header/footer YOK). Yanıt verildiyse true (çağıran exit eder). */
function ceksenet_parca(PDO $pdo, string $sinif): bool
{
    $parca = (string)($_GET['parca'] ?? '');
    if ($parca === '') return false;
    if ($parca === 'detay') {
        $o = ceksenet_detay_oku($pdo, max(0, (int)($_GET['id'] ?? 0)));
        if ($o === null || (string)$o['kayit']['sinif'] !== $sinif) { http_response_code(404); echo '<div class="flash flash-error" role="alert">Evrak bulunamadı.</div>'; return true; }
        echo ceksenet_detay_html($o);
        return true;
    }
    if ($parca === 'cari_ozet') {
        $c = ceksenet_cari_ad($pdo, max(0, (int)($_GET['cari'] ?? 0)));
        if ($c === null) { http_response_code(404); echo '<div class="cs-cari-ozet cs-cari-ozet--bos">Cari bulunamadı.</div>'; return true; }
        echo ceksenet_cari_ozet_html($pdo, (int)$_GET['cari']);
        return true;
    }
    http_response_code(400);
    return true;
}

// ── Modallar ────────────────────────────────────────────────────────────────

function ceksenet_modal_iptal(string $geri): string
{
    return '<div class="pm-overlay" id="csModalIptal" hidden><div class="pm-dialog cs-dialog cs-dialog--dar" role="dialog" aria-modal="true" aria-labelledby="csIptalBaslik">'
        . '<div class="pm-header"><span class="cs-tutamac" aria-hidden="true"></span><h2 class="pm-title" id="csIptalBaslik">Hareketi iptal et &mdash; <span data-iptal-no></span></h2>'
        . '<button type="button" class="pm-close" data-cs-kapat aria-label="Kapat">&#10005;</button></div>'
        . '<form method="post" action="cek_kaydet.php" data-cs-ajax data-ok="Hareket iptal edildi" novalidate>'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="islem" value="hareket_iptal"><input type="hidden" name="id" value=""><input type="hidden" name="geri" value="' . h($geri) . '">'
        . '<div class="pm-body"><div class="flash flash-error cs-form-hata" role="alert" hidden></div>'
        . '<div class="cs-uyari cs-uyari--tehlike" role="note"><span class="cs-uyari-ikon" aria-hidden="true">&#9888;</span><div><p>Yalnız evrakın <b>son hareketi</b> iptal edilebilir. Evrak önceki durumuna döner; bağlı cari dekontları ve kasa hareketi de iptal edilir. Giriş / çıkış hareketinin iptali evrakı iptal eder.</p></div></div>'
        . '<label class="pm-label"><span>İptal nedeni <b class="cs-zorunlu" aria-hidden="true">*</b></span><textarea name="neden" rows="3" minlength="3" maxlength="500" required data-ilk-odak data-iptal-neden aria-describedby="csIptalSayac"></textarea>'
        . '<span class="cs-ipucu" id="csIptalSayac" data-sayac>En az 3 karakter</span></label></div>'
        . '<div class="pm-footer"><span class="cs-klavye">Esc kapatır</span><button type="button" class="btn" data-cs-kapat>Vazgeç</button>'
        . '<button type="submit" class="btn cs-btn-tehlike" data-iptal-onay>Hareketi iptal et</button></div></form></div></div>';
}

/**
 * İşlem modalı (#csModalIslem): tek ortak form; hangi alanların görüneceği #csVeri.islemler (ceksenet_islemler()) yapısından JS ile türetilir.
 * @param list<array> $kasalar kasa_secenekleri satırları (aktif)
 */
function ceksenet_modal_islem(array $kasalar, array $cariler, string $geri): string
{
    $kopt = '<option value="">Seçiniz</option>';
    foreach ($kasalar as $k) $kopt .= '<option value="' . (int)$k['id'] . '" data-pb="' . h((string)$k['pb']) . '" data-tip="' . h((string)$k['tip']) . '">' . h($k['ad'] . ' · ' . $k['pb'] . ($k['tip'] === 'banka' ? ' · banka' : '')) . '</option>';
    $z = ' <b class="cs-zorunlu" aria-hidden="true">*</b>';
    $dl = '<datalist id="csCariListe">';
    foreach ($cariler as $c) $dl .= '<option value="' . h($c['kod'] . ' — ' . $c['unvan']) . '"></option>';
    $dl .= '</datalist>';
    return '<div class="pm-overlay" id="csModalIslem" hidden><div class="pm-dialog cs-dialog cs-dialog--genis" role="dialog" aria-modal="true" aria-labelledby="csIslemBaslik">'
        . '<div class="pm-header"><span class="cs-tutamac" aria-hidden="true"></span><h2 class="pm-title" id="csIslemBaslik">İşlem</h2><button type="button" class="pm-close" data-cs-kapat aria-label="Kapat">&#10005;</button></div>'
        . '<form method="post" action="cek_kaydet.php" id="csIslemForm" novalidate>'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="islem" value="islem_uygula"><input type="hidden" name="gecis" value="">'
        . '<input type="hidden" name="istek_anahtari" value=""><input type="hidden" name="eksiye_izin" value="0"><input type="hidden" name="geri" value="' . h($geri) . '">'
        . '<div class="pm-body"><div class="flash flash-error cs-form-hata" role="alert" hidden></div>'
        . '<div class="cs-eksik" id="csEksik" role="alertdialog" aria-labelledby="csEksikBaslik" aria-describedby="csEksikAck" hidden><h3 id="csEksikBaslik">Yetersiz bakiye</h3>'
        . '<p id="csEksikAck">Bu çıkış kasa / banka hesabını eksiye düşürecek.</p>'
        . '<dl class="cs-eksik-dl"><div><dt>Mevcut</dt><dd data-eksik="bakiye">—</dd></div><div><dt>İstenen</dt><dd data-eksik="istenen">—</dd></div><div><dt>Eksik</dt><dd data-eksik="eksik">—</dd></div></dl>'
        . '<div class="cs-eksik-eylem"><button type="button" class="btn" data-eksik-duzelt>Düzelt</button><button type="button" class="btn cs-btn-uyari" data-eksik-kaydet>Yine de kaydet</button></div></div>'
        . '<div class="cs-islem-ozet" data-islem-ozet></div><p class="cs-islem-etki" data-islem-etki></p>'
        . '<ul class="cs-gecersiz" data-gecersiz hidden></ul>'
        . '<div class="cs-form-grid">'
        . '<label class="pm-label cs-alan cs-span2" data-alan="cari" hidden><span>Cari' . $z . '</span><input type="hidden" name="cari_id" value=""><input type="text" name="cari_ara" list="csCariListe" placeholder="Cari ara (kod ya da unvan)" autocomplete="off" spellcheck="false"><span class="cs-ipucu" data-cari-ipucu aria-live="polite"></span></label>'
        . '<label class="pm-label cs-alan cs-span2" data-alan="kasa" hidden><span data-kasa-etiket>Kasa / banka hesabı</span><select name="kasa_id">' . $kopt . '</select><span class="cs-ipucu" data-kasa-ipucu aria-live="polite"></span></label>'
        . '<label class="pm-label cs-alan"><span>Tarih' . $z . '</span><input type="date" name="tarih" value="' . h(date('Y-m-d')) . '" required></label>'
        . '<label class="pm-label cs-alan cs-span2"><span>Açıklama</span><input type="text" name="aciklama" maxlength="500" autocomplete="off"></label>'
        . '</div></div>'
        . '<div class="pm-footer"><span class="cs-klavye">Ctrl+Enter kaydeder · Esc kapatır</span><button type="button" class="btn" data-cs-kapat>Vazgeç</button><button type="submit" class="btn btn-primary" data-kaydet>Kaydet</button></div>'
        . '</form>' . $dl . '</div></div>';
}

// ── Bordro formu ────────────────────────────────────────────────────────────

/** Bordro formu sütunları: sınıf + yöne göre görünen alanlar (diğerleri aynı adla gizli taşınır — paralel dizi hizası). @return list<string> */
function ceksenet_form_kolonlari(string $sinif, string $yon): array
{
    if ($sinif === 'senet') return ['seri_no', 'borclu', 'kefil', 'yer_adi', 'duzenleme_tarihi', 'vade', 'tutar', 'para_birimi'];
    if ($yon === 'verilen') return ['karne_id', 'seri_no', 'banka_ad', 'vade', 'tutar', 'para_birimi'];
    return ['seri_no', 'banka_ad', 'sube', 'hesap_no', 'borclu', 'yer_adi', 'vade', 'tutar', 'para_birimi'];
}

/** @return array<string,array{0:string,1:string}> alan → [başlık, giriş tipi] */
function ceksenet_form_alan_meta(string $sinif): array
{
    return [
        'karne_id' => ['Karne', 'select'], 'seri_no' => [$sinif === 'cek' ? 'Çek no' : 'Senet no', 'text'], 'banka_ad' => ['Banka', 'banka'], 'sube' => ['Şube', 'text'], 'hesap_no' => ['Hesap / IBAN', 'text'],
        'borclu' => [$sinif === 'cek' ? 'Keşideci' : 'Borçlu', 'text'], 'kefil' => ['Kefil', 'text'], 'yer_adi' => [$sinif === 'cek' ? 'Keşide yeri' : 'Ödeme yeri', 'text'],
        'duzenleme_tarihi' => ['Düzenleme', 'date'], 'vade' => ['Vade', 'date'], 'tutar' => ['Tutar', 'money'], 'para_birimi' => ['PB', 'pb'],
    ];
}

/** Tek form satırı (PC tablo satırı / mobil kart). $s: alan → değer. */
function ceksenet_form_satiri(string $sinif, string $yon, array $s, array $karneler, int $no): string
{
    $kol = ceksenet_form_kolonlari($sinif, $yon);
    $meta = ceksenet_form_alan_meta($sinif);
    $tum = ['seri_no', 'banka_ad', 'sube', 'hesap_no', 'borclu', 'kefil', 'yer_adi', 'duzenleme_tarihi', 'vade', 'tutar', 'para_birimi', 'karne_id'];
    $hucre = ''; $gizli = '';
    foreach ($tum as $a) {
        $v = (string)($s[$a] ?? '');
        if (!in_array($a, $kol, true)) { $gizli .= '<input type="hidden" name="' . $a . '[]" value="' . h($v) . '">'; continue; }
        [$et, $tip] = $meta[$a];
        $ad = $a . '[]';
        if ($tip === 'select') {
            $opt = '<option value="">— Karnesiz —</option>';
            foreach ($karneler as $kr) $opt .= '<option value="' . (int)$kr['id'] . '"' . ((string)$kr['id'] === $v ? ' selected' : '') . '>' . h($kr['ad'] . ' · ' . ($kr['kasa_ad'] ?? '') . ' (' . ($kr['kasa_pb'] ?? '') . ')') . '</option>';
            $in = '<select name="' . $ad . '" aria-label="' . h($et) . '" data-cs-karne>' . $opt . '</select>';
        } elseif ($tip === 'pb') {
            $opt = ''; foreach (cari_para_birimleri() as $p) $opt .= '<option value="' . h($p) . '"' . ($p === ($v !== '' ? $v : 'TRY') ? ' selected' : '') . '>' . h($p) . '</option>';
            $in = '<select name="' . $ad . '" aria-label="Para birimi">' . $opt . '</select>';
        } elseif ($tip === 'banka') {
            $in = '<input type="text" name="' . $ad . '" list="csBankaListe" value="' . h($v) . '" maxlength="100" autocomplete="off" aria-label="Banka">';
        } elseif ($tip === 'date') {
            $in = '<input type="date" name="' . $ad . '" value="' . h($v) . '" aria-label="' . h($et) . '">';
        } elseif ($tip === 'money') {
            $in = '<input type="text" name="' . $ad . '" value="' . h($v) . '" inputmode="decimal" autocomplete="off" placeholder="0,00" aria-label="Tutar"><small class="cs-tutar-onizleme" data-tutar-onizleme aria-live="polite"></small>';
        } else {
            $in = '<input type="text" name="' . $ad . '" value="' . h($v) . '" maxlength="150" autocomplete="off" aria-label="' . h($et) . '">';
        }
        $hucre .= '<td class="cs-fs cs-fs-' . h(str_replace('_', '-', $a)) . '"><span class="cs-fs-etiket">' . h($et) . '</span>' . $in . ($a === 'seri_no' ? '<small class="cs-fs-ipucu" aria-live="polite"></small>' : '') . '</td>';
    }
    return '<tr class="cs-fsatir"><td class="cs-fs-no" aria-hidden="true">' . $no . '</td>' . $hucre
        . '<td class="cs-fs-sil">' . $gizli . '<button type="button" class="cs-fsatir-sil" data-satir-sil aria-label="Satırı sil" title="Satırı sil (Ctrl+Del)">&#10005;</button></td></tr>';
}

/** Bordro formu gövdesi. */
function ceksenet_bordro_form_html(PDO $pdo, string $sinif, string $yon, array $kasalar, array $cariler): string
{
    $S = ceksenet_siniflar()[$sinif]; $sayfa = $S['sayfa'];
    $verilen = $yon === 'verilen';
    $karneler = ($verilen && $sinif === 'cek') ? ceksenet_karne_secenekleri($pdo) : [];
    $baslik = ($verilen ? 'Yeni çıkış bordrosu' : 'Yeni giriş bordrosu') . ' — ' . $S['etiket'] . ($verilen ? ' (verilen)' : ' (alınan)');
    $z = ' <b class="cs-zorunlu" aria-hidden="true">*</b>';
    $kol = ceksenet_form_kolonlari($sinif, $yon); $meta = ceksenet_form_alan_meta($sinif);
    $th = '<th scope="col" class="cs-fs-no">#</th>';
    foreach ($kol as $a) $th .= '<th scope="col" class="cs-fs cs-fs-' . h(str_replace('_', '-', $a)) . '">' . h($meta[$a][0]) . '</th>';
    $th .= '<th scope="col" class="cs-fs-sil"><span class="cs-sr">Sil</span></th>';
    $kmap = [];
    foreach ($kasalar as $k) $kmap[(int)$k['id']] = $k;
    $veriKarne = [];
    foreach ($karneler as $kr) {
        $ks = $kmap[(int)$kr['kasa_id']] ?? null;
        $veriKarne[] = ['id' => (int)$kr['id'], 'ad' => (string)$kr['ad'], 'pb' => (string)($kr['kasa_pb'] ?? 'TRY'), 'siradaki' => $kr['siradaki'], 'kalan' => (int)$kr['kalan'], 'ilk_no' => (string)$kr['ilk_no'], 'yaprak' => (int)$kr['yaprak'],
            'banka' => $ks !== null ? ((string)($ks['banka_adi'] ?? '') !== '' ? (string)$ks['banka_adi'] : (string)$ks['ad']) : ''];
    }
    $dlB = '<datalist id="csBankaListe">';
    foreach (ceksenet_banka_secenekleri($pdo) as $b) $dlB .= '<option value="' . h($b) . '"></option>';
    $dlB .= '</datalist><datalist id="csCariListeForm">';
    foreach ($cariler as $c) $dlB .= '<option value="' . h($c['kod'] . ' — ' . $c['unvan']) . '"></option>';
    $dlB .= '</datalist>';
    $ornek = ceksenet_form_satiri($sinif, $yon, [], $karneler, 1);
    $veri = ['sinif' => $sinif, 'yon' => $yon, 'cariler' => $cariler, 'karneler' => $veriKarne, 'satirMax' => CEKSENET_SATIR_MAX, 'bugun' => date('Y-m-d')];
    $not = '';
    if ($verilen && $sinif === 'cek' && !$karneler) $not = '<div class="cs-uyari cs-uyari--bilgi" role="note"><span class="cs-uyari-ikon" aria-hidden="true">&#9432;</span><div><p>Tanımlı çek karnesi yok; banka adını elle yazabilirsiniz. Karne tanımlarsanız sıradaki çek numarası otomatik önerilir ve ödeme bankası seçilir. <a href="cek_tanimlari.php?sekme=karne">Çek Tanımları</a></p></div></div>';
    return '<section class="cs-form-sayfa" id="csFormSayfa"><a class="cs-geri" href="' . h($sayfa . '?yon=' . $yon) . '"><span aria-hidden="true">&#8592;</span> Liste</a>'
        . '<form method="post" action="cek_kaydet.php" class="cs-form" id="csBordroForm" data-cs-bordro data-sinif="' . h($sinif) . '" data-yon="' . h($yon) . '" novalidate>'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="islem" value="bordro_kaydet"><input type="hidden" name="sinif" value="' . h($sinif) . '"><input type="hidden" name="yon" value="' . h($yon) . '">'
        . '<input type="hidden" name="istek_anahtari" value="' . h(bin2hex(random_bytes(12))) . '"><input type="hidden" name="geri" value="' . h($sayfa . '?yon=' . $yon) . '">'
        . '<header class="cs-form-bas"><h2 class="cs-unvan">' . h($baslik) . '</h2></header>'
        . '<div class="flash flash-error cs-form-hata" role="alert" hidden></div>' . $not
        . '<div class="cs-form-govde">'
        . '<section class="cs-adim-bolum"><h3 class="cs-adim-bas"><span class="cs-adim" aria-hidden="true">1</span> Başlık</h3><div class="cs-fis-bas">'
        . '<label class="pm-label cs-alan cs-alan--cari"><span>Cari' . $z . ' <small class="cs-ipucu">' . ($verilen ? 'verildiği' : 'alındığı') . ' cari</small></span><input type="hidden" name="cari_id" value=""><input type="text" name="cari_ara" list="csCariListeForm" placeholder="Cari ara (kod ya da unvan)" autocomplete="off" spellcheck="false" required><span class="cs-ipucu" data-cari-ipucu aria-live="polite"></span></label>'
        . '<label class="pm-label cs-alan"><span>Bordro tarihi' . $z . '</span><input type="date" name="tarih" value="' . h(date('Y-m-d')) . '" required></label>'
        . '<label class="pm-label cs-alan cs-alan--genis"><span>Açıklama</span><input type="text" name="aciklama" maxlength="500" autocomplete="off"></label>'
        . '<div class="cs-cari-ozet-kap" data-cari-ozet-kap></div></div></section>'
        . '<section class="cs-adim-bolum"><h3 class="cs-adim-bas"><span class="cs-adim" aria-hidden="true">2</span> ' . h($S['cogul']) . ' <small class="cs-ipucu">en çok ' . CEKSENET_SATIR_MAX . ' satır · boş satır atılır</small></h3>'
        . '<div class="cs-satirlar"><div class="cs-ftablo-kaydir"><table class="cs-ftablo"><caption class="cs-sr">Bordro satırları</caption><thead><tr>' . $th . '</tr></thead><tbody class="cs-fsatirlar">' . $ornek . '</tbody></table></div>'
        . '<button type="button" class="btn cs-satir-ekle" data-satir-ekle>＋ Satır ekle</button></div></section></div>'
        . '<div class="cs-eylem-bar cs-form-bar"><div class="cs-ozet-bar" aria-live="polite"><span class="cs-ozet-adim"><span class="cs-adim" aria-hidden="true">3</span></span><span>Adet <b data-top="adet">0</b></span>'
        . '<span data-top-pb>Toplam <b>—</b></span><span data-top-ort>Ort. vade <b>—</b></span></div>'
        . '<span class="cs-klavye">Enter sonraki alan · Ctrl+S kaydet · Esc</span>'
        . '<a class="btn cs-vazgec" href="' . h($sayfa . '?yon=' . $yon) . '">Vazgeç</a><button type="submit" class="btn btn-primary" data-kaydet>Kaydet</button></div>'
        . '</form>' . $dlB . '<template id="csSatirSablon">' . $ornek . '</template>' . ceksenet_json_script('csFormVeri', $veri) . '</section>';
}

// ── Sayfa gövdesi (cek_dekontu / senet_dekontu) ─────────────────────────────

/** İşlem tablosunun JS'e giden yalın hâli (durum makinesi TEK kaynak: ceksenet_islemler()). */
function ceksenet_islem_meta(): array
{
    $o = [];
    foreach (ceksenet_islemler() as $ad => $t) {
        if (!$t['onceki']) continue;   // giriş / çıkış bordro formundan
        $o[$ad] = ['etiket' => (string)$t['etiket'], 'etiket_senet' => (string)($t['etiket_senet'] ?? $t['etiket']), 'yon' => (string)$t['yon'], 'onceki' => $t['onceki'], 'yeni' => (string)$t['yeni'],
            'cari_zorunlu' => (bool)$t['cari_zorunlu'], 'kasa_sec' => $t['kasa_sec'], 'para' => $t['para'], 'etki' => (string)$t['etki'], 'toplu' => (bool)$t['toplu']];
    }
    return $o;
}

function ceksenet_sayfa_govde(PDO $pdo, string $sinif): string
{
    $S = ceksenet_siniflar()[$sinif] ?? null;
    if ($S === null) return '';
    $F = ceksenet_filtre();
    $yazabilir = ceksenet_can('write');
    $kasalar = kasa_secenekleri($pdo, null, true);
    $cariler = [];
    if ($yazabilir) foreach (cari_kart_listesi($pdo, ['aktif' => 1])['satirlar'] as $c) $cariler[] = ['id' => (int)$c['id'], 'kod' => (string)$c['kod'], 'unvan' => (string)$c['unvan']];
    $ust = ceksenet_ust_menu($sinif);

    // Bordro formu
    if ($yazabilir && (string)($_GET['yeni'] ?? '') === '1') {
        return '<div class="ceksenet cs--form" id="ceksenet" data-sayfa="form" data-sinif="' . h($sinif) . '" data-yon="' . h($F['yon']) . '">' . $ust . ceksenet_bordro_form_html($pdo, $sinif, $F['yon'], $kasalar, $cariler) . '</div>';
    }

    $boy = ceksenet_ui_sayfa_boyu();
    $L = ceksenet_listesi($pdo, $sinif, ceksenet_liste_suzgec($F), $boy, ($F['sayfa'] - 1) * $boy);
    if (!$L['satirlar'] && $L['toplam_satir'] > 0 && $F['sayfa'] > 1) {   // son sayfadan büyük ?sayfa=N → son sayfaya sınırla (yanlış "Henüz kayıt yok" olmasın)
        $F['sayfa'] = (int)ceil($L['toplam_satir'] / $boy);
        $L = ceksenet_listesi($pdo, $sinif, ceksenet_liste_suzgec($F), $boy, ($F['sayfa'] - 1) * $boy);
    }
    $oz = ceksenet_ozet($pdo, $sinif, $F['yon']);
    $cariAd = $F['cari'] > 0 ? (ceksenet_cari_ad($pdo, $F['cari'])['unvan'] ?? null) : null;
    $verilen = $F['yon'] === 'verilen';

    $yonTab = '<nav class="cs-yon-sekme" aria-label="Yön">';
    foreach (ceksenet_yonler() as $y => $ad) $yonTab .= '<a class="cs-yon" href="' . h(ceksenet_url($sinif, ['yon' => $y] + $F, ['yon' => $y, 'durum' => '', 'donem' => '', 'sayfa' => '', 'cari' => $F['cari'] ?: ''])) . '"' . ($F['yon'] === $y ? ' aria-current="true"' : '') . '>' . h($ad) . '</a>';
    $yonTab .= '</nav>';
    $yeniUrl = $S['sayfa'] . '?yeni=1&yon=' . $F['yon'];
    $disa = '';
    if (can('reports.export')) {
        $dq = ceksenet_sorgu($F, ['sinif' => $sinif, 'id' => '', 'sayfa' => '']);
        $disa = '<span class="cs-disa"><a href="' . h('cek_cikti.php?' . $dq . '&bicim=xlsx') . '">XLSX</a> · <a href="' . h('cek_cikti.php?' . $dq . '&bicim=csv') . '">CSV</a></span>';
    }
    $yeniBtn = $yazabilir ? '<a class="btn btn-primary cs-yeni" href="' . h($yeniUrl) . '" data-cs-yeni>＋ ' . ($verilen ? 'Yeni çıkış bordrosu' : 'Yeni giriş bordrosu') . '</a>' : '';
    $baslik = '<header class="cs-baslik"><div class="cs-baslik-satir"><h1 class="cs-unvan">' . h($S['etiket'] . ' Dekontu') . '</h1>' . $yonTab . '<span class="cs-baslik-eylem">' . $yeniBtn . $disa . '</span></div></header>';

    $filtreli = $F['ara'] !== '' || $F['durum'] !== 'acik' || $F['donem'] !== 'tumu' || $F['cari'] > 0;
    $kayitVar = (bool)array_filter($oz['durum'], static fn($m) => (bool)$m);
    if ($L['satirlar']) $liste = ceksenet_liste_html($L['satirlar'], $sinif, $F, $yazabilir);
    elseif ($filtreli) $liste = '<p class="cs-liste-bos">Eşleşen ' . h(mb_strtolower($S['etiket'], 'UTF-8')) . ' yok. <a href="' . h($S['sayfa'] . '?yon=' . $F['yon']) . '">Süzgeçleri temizle</a></p>';
    else $liste = ceksenet_bos('Henüz ' . mb_strtolower($S['etiket'], 'UTF-8') . ' kaydı yok', ($verilen ? 'Kendi verdiğiniz' : 'Müşteriden aldığınız') . ' ' . mb_strtolower($S['cogul'], 'UTF-8') . ' bordro ile girilir; her satır cari dekontu yazar.',
        $yeniBtn);
    $sayfaSay = (int)ceil($L['toplam_satir'] / $boy);
    $sayfalama = $sayfaSay > 1 ? '<nav class="cs-sayfalama" aria-label="Sayfalar">' . ($F['sayfa'] > 1 ? '<a class="btn btn-sm" href="' . h(ceksenet_url($sinif, $F, ['sayfa' => $F['sayfa'] > 2 ? $F['sayfa'] - 1 : ''])) . '">&lsaquo; Önceki</a>' : '')
        . '<span>' . $F['sayfa'] . ' / ' . $sayfaSay . '</span>' . ($F['sayfa'] < $sayfaSay ? '<a class="btn btn-sm" href="' . h(ceksenet_url($sinif, $F, ['sayfa' => $F['sayfa'] + 1])) . '">Sonraki &rsaquo;</a>' : '') . '</nav>' : '';
    $listeOz = '<div class="cs-liste-ozet" id="csListeOzet"><span>' . count($L['satirlar']) . ($L['toplam_satir'] > count($L['satirlar']) ? ' / ' . $L['toplam_satir'] : '') . ' kayıt</span>'
        . ($L['ozet'] ? '<span class="cs-liste-ozet-pb">' . ceksenet_pb_satirlari($L['ozet'], 'cs-pb-satir cs-pb-satir--kucuk') . '</span>' : '') . '<small>' . (!empty($L['ozet_iptal']) ? 'bu toplam yalnız iptal edilen kayıtlara aittir' : 'iptal edilenler toplama girmez') . '</small></div>';

    // Detay (?id=)
    $detay = null; $detayId = max(0, (int)($_GET['id'] ?? 0)); $not = '';
    if ($detayId > 0) {
        $o = ceksenet_detay_oku($pdo, $detayId);
        if ($o !== null && (string)$o['kayit']['sinif'] === $sinif) $detay = ceksenet_detay_html($o); else $not = 'Evrak bulunamadı ya da aktif deponuzda görünmüyor.';
    }
    $kapat = ceksenet_url($sinif, $F);
    $panel = '<aside class="cs-panel' . ($detay !== null ? ' cs-panel--acik' : '') . '" id="csPanel" aria-label="Evrak ayrıntısı"><div class="cs-panel-ust"><span class="cs-tutamac" aria-hidden="true"></span><h2 class="cs-panel-baslik">Evrak ayrıntısı</h2>'
        . '<a class="pm-close cs-panel-kapat" href="' . h($kapat) . '" data-cs-panel-kapat aria-label="Kapat">&#10005;</a></div><div class="cs-panel-govde" id="csPanelGovde">' . ($detay ?? ceksenet_panel_bos()) . '</div></aside>';

    $eylemBar = '';
    if ($yazabilir) $eylemBar = '<div class="cs-eylem-bar cs-eylem-bar--liste" id="csEylemBar" role="toolbar" aria-label="İşlemler"><a class="btn btn-primary" href="' . h($yeniUrl) . '">＋ ' . ($verilen ? 'Çıkış bordrosu' : 'Giriş bordrosu') . '</a></div>';
    $secimBar = $yazabilir ? '<div class="cs-secim-bar" id="csSecimBar" role="region" aria-label="Seçili evraklar" hidden><div class="cs-secim-ozet"><b data-secim="adet">0</b> seçili'
        . '<span class="cs-secim-tutar" data-secim="toplam"></span><span class="cs-secim-ort" data-secim="ort"></span></div><div class="cs-secim-eylem"><button type="button" class="btn btn-primary" data-cs-toplu-ac aria-expanded="false" aria-controls="csTopluMenu">Toplu işlem &#9662;</button>'
        . '<button type="button" class="btn" data-cs-sec-temizle>Temizle</button></div><div class="cs-toplu-menu" id="csTopluMenu" hidden></div></div>' : '';

    $kasaNot = '';
    if ($yazabilir && !$kasalar) $kasaNot = '<div class="cs-uyari cs-uyari--bilgi cs-kasa-not" role="note"><span class="cs-uyari-ikon" aria-hidden="true">&#9432;</span><div><p>Tahsil, tahsile verme ve ödeme işlemleri için kasa / banka hesabı gerekir. <a class="btn btn-sm" href="kasa_tanimlari.php?sekme=kasa&amp;yeni=1">Kasa Tanımları\'ndan banka hesabı ekleyin</a></p></div></div>';
    elseif ($yazabilir && !array_filter($kasalar, static fn(array $k): bool => $k['tip'] === 'banka')) $kasaNot = '<div class="cs-uyari cs-uyari--bilgi cs-kasa-not" role="note"><span class="cs-uyari-ikon" aria-hidden="true">&#9432;</span><div><p>Tahsile verme için banka tipi hesap gerekir. <a href="kasa_tanimlari.php?sekme=kasa&amp;yeni=1">Banka hesabı ekleyin</a></p></div></div>';

    $kmap = [];
    foreach ($kasalar as $k) $kmap[(int)$k['id']] = $k;
    $karneKasa = [];
    foreach (ceksenet_karne_secenekleri($pdo) as $kr) $karneKasa[(int)$kr['id']] = (int)$kr['kasa_id'];
    $veri = ['sinif' => $sinif, 'yon' => $F['yon'], 'bugun' => date('Y-m-d'), 'islemler' => ceksenet_islem_meta(), 'cariler' => $cariler, 'karneKasa' => (object)$karneKasa,
        'kasalar' => array_values(array_map(static fn(array $k): array => ['id' => $k['id'], 'ad' => $k['ad'], 'pb' => $k['pb'], 'tip' => $k['tip'], 'eksi_izin' => $k['eksi_izin']], $kasalar))];
    return '<div class="ceksenet cs--tek-ekran" id="ceksenet" data-sayfa="liste" data-sinif="' . h($sinif) . '" data-yon="' . h($F['yon']) . '" data-bugun="' . h(date('Y-m-d')) . '">' . $ust
        . ($not !== '' ? '<div class="flash flash-error" role="alert">' . h($not) . '</div>' : '') . $baslik . ceksenet_ozet_html($sinif, $F, $oz) . $kasaNot . ceksenet_filtre_html($sinif, $F, $cariAd)
        . '<div class="cs-ana"><section class="cs-sol" aria-label="' . h($S['cogul']) . '"><div class="cs-liste-govde" id="csListe">' . $liste . $sayfalama . '</div>' . $listeOz . '</section>' . $panel . '</div>'
        . $secimBar . $eylemBar
        . ($yazabilir ? ceksenet_modal_islem($kasalar, $cariler, ceksenet_url($sinif, $F)) : '')
        . (ceksenet_can('delete') ? ceksenet_modal_iptal(ceksenet_url($sinif, $F)) : '')
        . ceksenet_json_script('csVeri', $veri) . '</div>';
}

// ── Tanımlar (cek_tanimlari.php) ────────────────────────────────────────────

function ceksenet_modal_tanim(array $bankaKasalar): string
{
    $kopt = '<option value="">Seçiniz</option>';
    foreach ($bankaKasalar as $k) $kopt .= '<option value="' . (int)$k['id'] . '">' . h($k['ad'] . ' · ' . $k['pb']) . '</option>';
    $z = ' <b class="cs-zorunlu" aria-hidden="true">*</b>';
    return '<div class="pm-overlay" id="csModalTanim" hidden><div class="pm-dialog cs-dialog cs-dialog--genis cs-dialog--uzun" role="dialog" aria-modal="true" aria-labelledby="csTanimBaslik">'
        . '<div class="pm-header"><span class="cs-tutamac" aria-hidden="true"></span><h2 class="pm-title" id="csTanimBaslik">Yeni tanım</h2><button type="button" class="pm-close" data-cs-kapat aria-label="Kapat">&#10005;</button></div>'
        . '<form method="post" action="cek_kaydet.php" data-cs-ajax data-ok="Tanım kaydedildi" novalidate>'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="islem" value="tanim_kaydet"><input type="hidden" name="id" value=""><input type="hidden" name="tur" value="banka"><input type="hidden" name="geri" value="cek_tanimlari.php">'
        . '<div class="pm-body"><div class="flash flash-error cs-form-hata" role="alert" hidden></div><div class="cs-form-grid">'
        . '<label class="pm-label cs-span2"><span>Ad' . $z . '</span><input type="text" name="ad" maxlength="100" required autocomplete="off" data-ilk-odak></label>'
        . '<label class="pm-label cs-span2" data-tur="banka"><span>Şube</span><input type="text" name="sube" maxlength="100" autocomplete="off"></label>'
        . '<label class="pm-label cs-span2" data-tur="karne" hidden><span>Banka hesabı' . $z . '</span><select name="kasa_id">' . $kopt . '</select><span class="cs-ipucu">Kasa Tanımları\'ndaki banka tipi hesaplar</span></label>'
        . '<label class="pm-label" data-tur="karne" hidden><span>İlk çek no' . $z . '</span><input type="text" name="ilk_no" inputmode="numeric" maxlength="20" autocomplete="off"></label>'
        . '<label class="pm-label" data-tur="karne" hidden><span>Yaprak sayısı' . $z . '</span><input type="text" name="yaprak" inputmode="numeric" maxlength="4" autocomplete="off" value="25"></label>'
        . '<label class="pm-label"><span>Sıra</span><input type="text" name="sira" inputmode="numeric" maxlength="5" value="0" autocomplete="off"></label>'
        . '</div></div><div class="pm-footer"><span class="cs-klavye">Ctrl+Enter kaydeder · Esc kapatır</span><button type="button" class="btn" data-cs-kapat>Vazgeç</button><button type="submit" class="btn btn-primary">Kaydet</button></div>'
        . '</form></div></div>';
}

/** Tanım listesi paneli (banka | karne). */
function ceksenet_tanim_panel(PDO $pdo, string $tur, bool $gizli, bool $yaz, array $bankaKasalar): string
{
    $liste = ceksenet_tanim_listesi($pdo, $tur);
    $csrf = h(csrf_token());
    $geri = 'cek_tanimlari.php?sekme=' . $tur;
    $baslik = $tur === 'banka' ? 'Bankalar' : 'Çek karneleri';
    $ack = $tur === 'banka' ? 'Çek / senet formlarında banka adı listesi olarak sunulur. Kayıtlarda kullanılan banka silinmez, pasifleştirilir.'
        : 'Verilen (kendi) çekler için karne: banka hesabı + ilk çek numarası + yaprak sayısı. Sıradaki numara önerilir; ödeme bankası karneden seçilir.';
    $yeniBtn = $yaz ? '<button type="button" class="btn btn-primary" data-cs-tanim-ac="' . h($tur) . '"' . ($tur === 'karne' && !$bankaKasalar ? ' disabled' : '') . '>＋ ' . ($tur === 'banka' ? 'Yeni banka' : 'Yeni karne') . '</button>' : '';
    $h = '<section class="cs-tanim-govde" id="csPanel' . ucfirst($tur) . '" role="tabpanel" data-tur="' . h($tur) . '"' . ($gizli ? ' hidden' : '') . '><div class="cs-panel-ust2"><div><h2>' . h($baslik) . '</h2><p>' . h($ack) . '</p></div>' . $yeniBtn . '</div>';
    if ($tur === 'karne' && !$bankaKasalar) {
        $h .= '<div class="cs-uyari cs-uyari--bilgi" role="note"><span class="cs-uyari-ikon" aria-hidden="true">&#9432;</span><div><p>Önce Kasa Tanımları\'ndan banka hesabı ekleyin. <a class="btn btn-sm" href="kasa_tanimlari.php?sekme=kasa&amp;yeni=1">Banka hesabı ekle</a></p></div></div>';
    }
    if (!$liste) return $h . ceksenet_bos($tur === 'banka' ? 'Henüz banka yok' : 'Henüz karne yok', $yaz ? 'Yukarıdan ilk kaydı ekleyin.' : '', '', 'h3') . '</section>';
    $h .= '<ul class="cs-tanim-liste">';
    foreach ($liste as $t) {
        $id = (int)$t['id']; $aktif = (int)$t['aktif'] === 1; $kul = (int)$t['kullanim'];
        if ($tur === 'banka') {
            $alt = array_filter([(string)($t['sube'] ?? ''), 'Sıra ' . (int)$t['sira'], $kul > 0 ? $kul . ' kayıtta kullanıldı' : ''], static fn($x) => $x !== '');
            $json = ['id' => $id, 'tur' => 'banka', 'ad' => (string)$t['ad'], 'sube' => (string)($t['sube'] ?? ''), 'sira' => (int)$t['sira']];
        } else {
            $alt = array_filter([(string)($t['kasa_ad'] ?? '—') . ' (' . ($t['kasa_pb'] ?? '') . ')', 'No ' . $t['ilk_no'] . ' · ' . (int)$t['yaprak'] . ' yaprak', 'Kullanılan ' . $kul . ' · Kalan ' . (int)$t['kalan'],
                $t['siradaki'] !== null ? 'Sıradaki: ' . $t['siradaki'] : 'Karne bitti'], static fn($x) => $x !== '');
            $json = ['id' => $id, 'tur' => 'karne', 'ad' => (string)$t['ad'], 'kasa_id' => (int)$t['kasa_id'], 'ilk_no' => (string)$t['ilk_no'], 'yaprak' => (int)$t['yaprak'], 'sira' => (int)$t['sira']];
        }
        $h .= '<li class="cs-tanim-satir' . ($aktif ? '' : ' cs-tanim-satir--pasif') . '" data-id="' . $id . '"><div class="cs-tanim-ad">' . h($t['ad']) . (!$aktif ? ' <span class="cs-rozet cs-d-iptal">Pasif</span>' : '')
            . '<small>' . h(implode(' · ', $alt)) . '</small></div><div class="cs-tanim-eylem">';
        if ($yaz) {
            $h .= '<form method="post" action="cek_kaydet.php" data-cs-ajax data-ok="Tanım güncellendi" class="cs-icerik"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="islem" value="tanim_durum"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="geri" value="' . h($geri) . '">'
                . '<label class="cs-aktif"><input type="checkbox" role="switch" name="aktif" value="1"' . ($aktif ? ' checked' : '') . ' aria-label="' . h($t['ad']) . ' aktif"><span>Aktif</span></label><noscript><button type="submit" class="btn btn-sm">Uygula</button></noscript></form>'
                . '<button type="button" class="btn btn-sm" data-cs-tanim-ac="' . h($tur) . '" data-tanim="' . ceksenet_json_attr($json) . '" aria-label="' . h($t['ad']) . ' düzenle">Düzenle</button>';
            if ($kul === 0) $h .= '<form method="post" action="cek_kaydet.php" data-cs-ajax data-ok="Tanım silindi" data-onay="Bu tanım silinecek. Emin misiniz?" class="cs-icerik"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="islem" value="tanim_sil"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="geri" value="' . h($geri) . '">'
                . '<button type="submit" class="btn btn-sm cs-tehlike" aria-label="' . h($t['ad']) . ' sil">Sil</button></form>';
        }
        $h .= '</div></li>';
    }
    return $h . '</ul></section>';
}

function ceksenet_tanimlari_govde(PDO $pdo): string
{
    $yaz = ceksenet_can('admin');
    $sekme = (string)($_GET['sekme'] ?? 'banka');
    if (!isset(ceksenet_tanim_turleri()[$sekme])) $sekme = 'banka';
    $bankaKasalar = array_values(array_filter(kasa_secenekleri($pdo, null, true), static fn(array $k): bool => $k['tip'] === 'banka'));
    $nav = ''; $pan = '';
    foreach (['banka' => 'Bankalar', 'karne' => 'Çek Karneleri'] as $k => $ad) {
        $say = count(ceksenet_tanim_listesi($pdo, $k));
        $nav .= '<a class="cs-tanim-nav-oge" role="tab" href="cek_tanimlari.php?sekme=' . $k . '" data-sekme="' . $k . '" aria-selected="' . ($sekme === $k ? 'true' : 'false') . '" aria-controls="csPanel' . ucfirst($k) . '">' . h($ad) . ' <small>' . $say . '</small></a>';
        $pan .= ceksenet_tanim_panel($pdo, $k, $sekme !== $k, $yaz, $bankaKasalar);
    }
    return '<div class="ceksenet" id="ceksenet" data-sayfa="tanim">' . ceksenet_ust_menu('tanim') . '<div class="cs-ustsatir"><h1>Çek Tanımları</h1></div><div class="cs-tanim">'
        . '<div class="cs-tanim-nav" role="tablist" aria-label="Tanım türleri">' . $nav . '</div><div class="cs-tanim-icerik">' . $pan . '</div></div>'
        . '<p class="cs-bilgi-kutu">Çek karneleri verilen (kendi) çekler içindir. Banka hesabı = Kasa Tanımları\'nda tipi "Banka Hesabı" olan kart.</p>'
        . ($yaz ? ceksenet_modal_tanim($bankaKasalar) : '') . '</div>';
}

// ── Bordro çıktısı (cek_cikti.php) ──────────────────────────────────────────

/** Türkçe büyük harf ("Ciro" → "CİRO"). */
function ceksenet_buyuk(string $s): string { return mb_strtoupper(strtr($s, ['i' => 'İ', 'ı' => 'I']), 'UTF-8'); }

/** Bordro başlığı: giriş/çıkış bordroları "ÇEK GİRİŞ BORDROSU" / "ÇEK ÇIKIŞ BORDROSU"; işlem bordroları işlem adıyla ("ÇEK CİRO ET BORDROSU"). */
function ceksenet_bordro_baslik(array $b): string
{
    $sinif = (string)$b['sinif']; $S = ceksenet_siniflar()[$sinif];
    $islem = (string)($b['islem'] ?? '');
    if ($islem === 'giris' || $islem === 'cikis' || $islem === '') $ad = $b['yon'] === 'alinan' ? 'GİRİŞ' : 'ÇIKIŞ';
    else $ad = ceksenet_buyuk((string)($b['islem_etiket'] ?? ceksenet_islem_etiketi($islem, $sinif)));
    return ceksenet_buyuk($S['etiket']) . ' ' . $ad . ' BORDROSU';
}

/** Bordro cari satırı etiketi (işleme uygun): ciro → "Ciro edilen cari". */
function ceksenet_bordro_cari_etiket(array $b): string
{
    return match ((string)($b['islem'] ?? '')) { 'ciro' => 'Ciro edilen cari', default => 'Cari' };
}

/** A4 dikey bordro gövdesi. $b: ceksenet_bordro_oku() sonucu; $firma: siparis_inv_ihracatci(). */
function ceksenet_bordro_yazdir_html(array $b, array $firma): string
{
    $sinif = (string)$b['sinif']; $S = ceksenet_siniflar()[$sinif];
    $trs = ''; $n = 0;
    foreach ($b['satirlar'] as $k) {
        $iptal = ($k['hareket_durum'] ?? '') === 'iptal';
        [$ana, $alt] = ceksenet_evrak_baslik($k);
        $n++;
        $trs .= '<tr class="' . ($iptal ? 'cs-yz-iptal' : '') . '"><td class="cs-sayi">' . $n . '</td><td>' . h((string)$k['portfoy_no']) . '</td><td>' . h((string)$k['seri_no']) . '</td><td>' . h($ana) . ($alt !== '' ? '<br><small>' . h($alt) . '</small>' : '') . '</td>'
            . '<td>' . h(ceksenet_tarih_tr((string)$k['vade'])) . '</td><td class="cs-sayi">' . h(ceksenet_para((int)$k['tutar_kurus'], (string)$k['para_birimi'])) . '</td><td>' . ($iptal ? 'İPTAL' : h((string)$k['durum_etiket'])) . '</td></tr>';
    }
    $tp = '';
    foreach ($b['toplamlar'] as $pb => $x) $tp .= '<tr><th>Toplam ' . h((string)$pb) . ' (' . (int)$x['adet'] . ' adet)</th><td>' . h(ceksenet_para((int)$x['kurus'], (string)$pb)) . '</td></tr>';
    foreach ($b['ortalama_vade'] as $pb => $x) $tp .= '<tr><th>Ortalama vade ' . h((string)$pb) . '</th><td>' . (int)$x['gun'] . ' gün · ' . h(ceksenet_tarih_tr((string)$x['tarih'])) . '</td></tr>';
    return '<div class="cs-yz"><div class="cs-yz-ust"><div class="cs-yz-firma"><b>' . h($firma['unvan'] ?? '') . '</b></div>'
        . '<div class="cs-yz-belge"><div class="cs-yz-tur">' . h(ceksenet_bordro_baslik($b)) . '</div><table><tr><th>Bordro no</th><td>' . h($b['bordro_no']) . '</td></tr>'
        . ((string)$b['cari_unvan'] !== '' ? '<tr><th>' . h(ceksenet_bordro_cari_etiket($b)) . '</th><td>' . h((string)$b['cari_unvan']) . '</td></tr>' : '') . '<tr><th>Tarih</th><td>' . h(ceksenet_tarih_tr($b['tarih'])) . '</td></tr><tr><th>Basım</th><td>' . h(date('d.m.Y H:i')) . '</td></tr></table></div></div>'
        . '<table class="cs-yz-satir"><thead><tr><th>#</th><th>Portföy no</th><th>' . ($sinif === 'cek' ? 'Çek no' : 'Senet no') . '</th><th>' . ($sinif === 'cek' ? 'Banka / keşideci' : 'Borçlu / kefil') . '</th><th>Vade</th><th class="cs-sayi">Tutar</th><th>Durum</th></tr></thead><tbody>' . $trs . '</tbody></table>'
        . '<table class="cs-yz-toplam">' . $tp . '</table>'
        . '<div class="cs-yz-imza"><div>Teslim eden</div><div>Teslim alan</div></div>'
        . '<div class="cs-yz-alt">' . ((int)$b['iptal_say'] > 0 ? (int)$b['iptal_say'] . ' satır iptal edilmiştir (toplamlara dahil değil). ' : '') . 'Bu çıktı bilgi amaçlıdır; para birimleri birbirine eklenmez.</div></div>';
}
