<?php
// =========================================================
// config/fatura_ui.php — Fatura modülü render yardımcıları (include-only; yalnız fonksiyon bildirir)
//
// 4 belge sayfası (satis_faturalari / alis_faturalari / sevk_irsaliyeleri / alinan_siparis_fisleri) ortak gövdesi
// fatura_sayfa_govde($pdo, $sinif), Fatura Tanımları fatura_tanimlari_govde($pdo), yazdırma fatura_yazdir_html (fatura_cikti.php).
// Sınıf öneki `ft-`, kök `.fatura#fatura`, kancalar `ft*` / data-* (assets/fatura.js sözleşmesi).
// Burada SORGU YAZILMAZ (yalnız config/fatura_core.php okuyucuları), hiçbir yazma yoktur. HTML'e giren HER dinamik değer h() ile kaçışlanır.
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

// ── Varlıklar / küçük yardımcılar ───────────────────────────────────────────

function fatura_assets(): void
{
    echo '<link rel="stylesheet" href="assets/fatura.css?v=' . (int)@filemtime(dirname(__DIR__) . '/assets/fatura.css') . '">' . "\n";
}

function fatura_scripts(): void
{
    echo '<script src="assets/doviz_oto.js?v=' . (int)@filemtime(dirname(__DIR__) . '/assets/doviz_oto.js') . '"></script>' . "\n";
    echo '<script src="assets/fatura.js?v=' . (int)@filemtime(dirname(__DIR__) . '/assets/fatura.js') . '"></script>' . "\n";
}

/** <script type=application/json> içine güvenli JSON (</script> kaçışı). */
function fatura_json_script(string $id, mixed $v): string
{
    return '<script type="application/json" id="' . h($id) . '">' . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
}

function fatura_json_attr(mixed $v): string { return h((string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }

function fatura_tarih_tr(?string $d): string
{
    $d = (string)$d;
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) ? $m[3] . '.' . $m[2] . '.' . $m[1] : $d;
}

function fatura_para(int $kurus, string $pb): string { return cari_tutar_bicim($kurus, $pb); }

/** DECIMAL metni → kuruş (null korunur). */
function fatura_k(mixed $v): ?int { return $v === null || $v === '' ? null : cari_kurus($v); }

/** "18.450 kg" */
function fatura_miktar(int $milli, string $birim): string { return stok_miktar_bicim($milli, $birim) . ' ' . $birim; }

/** Bakiye çipi (tutar + B/A harfi; aria-label tam metin). */
function fatura_bk(int $kurus, string $pb): string
{
    $x = $kurus > 0 ? 'b' : ($kurus < 0 ? 'a' : 'd');
    $e = ['b' => 'borçlu', 'a' => 'alacaklı', 'd' => 'denk'][$x];
    return '<span class="ft-bk ft-bk--' . $x . '" data-pb="' . h($pb) . '" aria-label="' . h(cari_tutar_bicim(abs($kurus), $pb, false) . ' ' . $pb . ', ' . $e) . '">'
        . '<span>' . h(cari_tutar_bicim(abs($kurus), $pb)) . '</span><span class="ft-bk-yon" aria-hidden="true">' . strtoupper($x) . '</span></span>';
}

/** Sınıf metinleri. @return array{baslik:string,yeni:string,kisa:string,bos:string} */
function fatura_sinif_metin(string $sinif): array
{
    return [
        'satis'    => ['baslik' => 'Satış Faturaları', 'yeni' => 'Yeni Satış Faturası', 'kisa' => 'satış faturası', 'bos' => 'Henüz satış faturası yok'],
        'alis'     => ['baslik' => 'Alış Faturaları', 'yeni' => 'Yeni Alış Faturası', 'kisa' => 'alış faturası', 'bos' => 'Henüz alış faturası yok'],
        'irsaliye' => ['baslik' => 'Sevk İrsaliyeleri', 'yeni' => 'Yeni Sevk İrsaliyesi', 'kisa' => 'sevk irsaliyesi', 'bos' => 'Henüz sevk irsaliyesi yok'],
        'siparis'  => ['baslik' => 'Alınan Sipariş Fişleri', 'yeni' => 'Yeni Sipariş Fişi', 'kisa' => 'sipariş fişi', 'bos' => 'Henüz sipariş fişi yok'],
    ][$sinif] ?? ['baslik' => 'Fatura', 'yeni' => 'Yeni belge', 'kisa' => 'belge', 'bos' => 'Henüz belge yok'];
}

/** Sınıf içi tür etiketi (segment / çip). */
function fatura_tur_kisa(string $tur): string
{
    return ['satis' => 'Normal', 'satis_ihracat' => 'İhracat', 'satis_iade' => 'İade', 'alis' => 'Normal', 'alis_mustahsil' => 'Müstahsil', 'alis_iade' => 'İade',
        'irsaliye' => 'İrsaliye', 'siparis' => 'Sipariş'][$tur] ?? $tur;
}

/** Tür rozeti (renk + işaret + metin). */
function fatura_tur_rozet(string $tur): string
{
    [$sinif, $isaret, $metin] = match ($tur) {
        'satis' => ['satis', '&#9650;', 'Satış'], 'satis_ihracat' => ['ihracat', 'EXP', 'İhracat'], 'satis_iade', 'alis_iade' => ['iade', '&#8634;', 'İade'],
        'alis' => ['alis', '&#9660;', 'Alış'], 'alis_mustahsil' => ['mustahsil', '&#9660;', 'Müstahsil'], 'irsaliye' => ['irsaliye', '&#8674;', 'İrsaliye'],
        'siparis' => ['siparis', '&#9671;', 'Sipariş'], default => ['', '', $tur],
    };
    return '<span class="ft-rozet ft-rozet--' . $sinif . '"><span class="ft-isaret" aria-hidden="true">' . $isaret . '</span> ' . h($metin) . '</span>';
}

/** Akış durumu rozeti (sınıfa göre metin). '' = rozet yok. */
function fatura_akis_rozet(string $tur, string $akis): string
{
    if ($akis === 'iptal') return '<span class="ft-rozet ft-rozet--iptal">İPTAL</span>';
    $m = match (true) {
        $tur === 'siparis' => ['yok' => ['Açık', 'acik'], 'kismi' => ['Kısmi', 'kismi'], 'tam' => ['Tamamlandı', 'tamam'], 'kapali' => ['&#128274; Kapalı', 'kapali']],
        $tur === 'irsaliye' => ['yok' => ['Faturalanmadı', 'uyari'], 'kismi' => ['Kısmen faturalandı', 'kismi'], 'tam' => ['Faturalandı', 'tamam']],
        in_array($tur, ['satis', 'satis_ihracat', 'alis', 'alis_mustahsil'], true) => ['kismi' => ['Kısmi iade', 'kismi'], 'tam' => ['Tam iade', 'iade']],
        default => [],
    };
    if (!isset($m[$akis])) return '';
    return '<span class="ft-rozet ft-rozet--' . $m[$akis][1] . '">' . $m[$akis][0] . '</span>';
}

/** Üst sekme şeridi. $aktif: satis|alis|irsaliye|siparis|tanimlar */
function fatura_ust_menu(string $aktif): string
{
    $s = ['satis' => ['satis_faturalari.php', 'Satış Faturaları'], 'alis' => ['alis_faturalari.php', 'Alış Faturaları'], 'irsaliye' => ['sevk_irsaliyeleri.php', 'Sevk İrsaliyeleri'],
        'siparis' => ['alinan_siparis_fisleri.php', 'Alınan Siparişler'], 'tanimlar' => ['fatura_tanimlari.php', 'Tanımlar']];
    $o = '<nav class="ft-sekmeler" aria-label="Fatura">';
    foreach ($s as $k => [$url, $ad]) $o .= '<a class="ft-sekme" href="' . h($url) . '"' . ($k === $aktif ? ' aria-current="page"' : '') . '>' . h($ad) . '</a>';
    return $o . '<a class="ft-sekme ft-sekme--dis" href="cari_kartlari.php">Cari Ekstre</a></nav>';
}

/** Şema kurulmamış kartı (sekme şeridi YOK). $eksik: list{ad,kart} (fatura_eksik_semalar). */
function fatura_sema_uyari(array $eksik): string
{
    $lst = ''; $btn = '';
    foreach ($eksik as $e) {
        $lst .= '<li data-sema-eksik="' . h((string)$e['kart']) . '">' . h((string)$e['ad']) . '</li>';
        $btn .= '<a class="btn btn-primary" href="migrate.php#' . h((string)$e['kart']) . '">Şema Migrasyon &rsaquo; ' . h((string)$e['ad']) . '</a> ';
    }
    return '<div class="fatura" id="fatura" data-sayfa="sema"><div class="ft-uyari" role="alert" data-ft-sema><span class="ft-uyari-ikon" aria-hidden="true">&#9888;</span><div>'
        . '<h2>Fatura tabloları kurulmamış</h2>'
        . '<p>Fatura modülü için gerekli veritabanı tabloları bu sunucuda henüz oluşturulmadı. Yönetici: Şema Migrasyon sayfasındaki kartları bu sırayla çalıştırın (önce Cari, sonra Stok, en son Fatura):</p>'
        . '<ol class="ft-sema-liste">' . $lst . '</ol><p class="ft-sema-btn">' . $btn . '</p></div></div></div>';
}

function fatura_bos(string $baslik, string $ack, string $eylem = '', string $etiket = 'h2'): string
{
    return '<div class="ft-bos"><' . $etiket . '>' . h($baslik) . '</' . $etiket . '><p>' . h($ack) . '</p>' . ($eylem !== '' ? '<div class="ft-bos-eylem">' . $eylem . '</div>' : '') . '</div>';
}

// ── Liste ───────────────────────────────────────────────────────────────────

/** Süzgeç değerleri (GET). */
function fatura_filtre(string $sinif): array
{
    $turler = fatura_sinif_turleri($sinif);
    $tur = (string)($_GET['tur'] ?? '');
    if (!in_array($tur, $turler, true) || count($turler) < 2) $tur = '';
    $durum = (string)($_GET['durum'] ?? 'tumu');
    if (!in_array($durum, ['tumu', 'gecerli', 'iptal'], true)) $durum = 'tumu';
    $donem = (string)($_GET['donem'] ?? 'tumu');
    if (!in_array($donem, ['tumu', '30g', 'bu_ay', 'gecen_ay', 'ozel'], true)) $donem = 'tumu';
    $sdl = ['siparis' => ['acik', 'kismi', 'tamam', 'kapali'], 'irsaliye' => ['bekleyen', 'kismi', 'tam']][$sinif] ?? [];
    $sd = (string)($_GET['sd'] ?? '');
    if (!in_array($sd, $sdl, true)) $sd = '';
    $bas = $donem === 'ozel' ? (string)(stok_tarih_coz($_GET['bas'] ?? '') ?? '') : '';
    $bit = $donem === 'ozel' ? (string)(stok_tarih_coz($_GET['bit'] ?? '') ?? '') : '';
    $cari = max(0, (int)($_GET['cari'] ?? 0));
    $cariAra = mb_substr(trim((string)($_GET['cari_ara'] ?? '')), 0, 200);
    return ['ara' => mb_substr(trim((string)($_GET['ara'] ?? '')), 0, 100), 'tur' => $tur, 'durum' => $durum, 'donem' => $donem, 'bas' => $bas, 'bit' => $bit,
        'sd' => $sd, 'cari' => $cari, 'cari_ara' => $cariAra];
}

/** Süzgeç → GET sorgu dizisi (boşlar atılır). */
function fatura_filtre_sorgu(array $F, array $ek = []): array
{
    $q = ['ara' => $F['ara'], 'tur' => $F['tur'], 'durum' => $F['durum'] !== 'tumu' ? $F['durum'] : '', 'donem' => $F['donem'] !== 'tumu' ? $F['donem'] : '',
        'bas' => $F['bas'], 'bit' => $F['bit'], 'sd' => $F['sd'], 'cari' => $F['cari'] > 0 ? (string)$F['cari'] : ''];
    foreach ($ek as $k => $v) $q[$k] = $v;
    return array_filter($q, static fn($x) => $x !== '' && $x !== null);
}

/** fatura_listesi süzgeç dizisi. */
function fatura_liste_suzgec(array $F): array
{
    return ['ara' => $F['ara'], 'tur' => $F['tur'], 'durum' => $F['durum'], 'donem' => $F['donem'], 'bas' => $F['bas'], 'bit' => $F['bit'], 'sd' => $F['sd'], 'cari_id' => $F['cari']];
}

function fatura_liste_satiri(array $b, bool $secili, string $sayfa): string
{
    $tur = (string)$b['tur']; $iptal = $b['durum'] === 'iptal'; $id = (int)$b['id'];
    $bilgi = fatura_tur_bilgi($tur) ?? [];
    $pb = (string)$b['para_birimi'];
    $ilk = (string)($b['ilk_urun'] ?? '');
    $alt = array_filter([fatura_tarih_tr((string)$b['tarih']), (string)$b['cari_unvan'], (string)$b['depo'], $ilk !== '' ? $ilk . ((int)$b['satir_say'] > 1 ? ' +' . ((int)$b['satir_say'] - 1) : '') : ''], static fn($x) => $x !== '');
    if (($bilgi['cari'] ?? null) !== null) {
        $tut = '<b class="ft-tutar"' . ((int)$b['cari_tutar_kurus'] !== (int)$b['genel_toplam_kurus'] ? ' title="Genel ' . h(cari_tutar_bicim((int)$b['genel_toplam_kurus'], $pb)) . '"' : '') . '>' . h(fatura_para((int)$b['cari_tutar_kurus'], $pb)) . '</b>';
    } elseif ((int)$b['genel_toplam_kurus'] > 0) {
        $tut = '<b class="ft-tutar">' . h(fatura_para((int)$b['genel_toplam_kurus'], $pb)) . '</b>';
    } else {
        $tut = '<b class="ft-tutar">' . (int)$b['satir_say'] . ' satır</b>';
    }
    $roz = fatura_akis_rozet($tur, (string)$b['akis_durum']);
    $no = (string)$b['belge_no'];
    return '<li><a class="ft-satir' . ($iptal ? ' ft-satir--iptal' : '') . '" href="' . h($sayfa . '?id=' . $id) . '" data-id="' . $id . '" data-tur="' . h($tur) . '" data-durum="' . h((string)$b['durum']) . '"' . ($secili ? ' aria-current="true"' : '') . '>'
        . '<span class="ft-satir-ust"><span class="ft-no">' . h($no) . '</span> ' . fatura_tur_rozet($tur) . '</span>'
        . '<span class="ft-satir-alt">' . h(implode(' · ', $alt)) . (!empty($b['fatura_no']) ? ' · ' . h((string)$b['fatura_no']) : '') . '</span>'
        . '<span class="ft-satir-sag">' . $tut . $roz . (!$iptal && !empty($b['uyari']) ? '<span class="ft-rozet ft-rozet--uyari" title="' . h((string)$b['uyari']) . '">Eksi stok</span>' : '') . '</span></a>'
        . '<details class="ft-hizli"><summary aria-label="' . h($no) . ' hızlı eylemler">&#8943;</summary><div class="ft-hizli-menu">'
        . '<a href="fatura_cikti.php?id=' . $id . '" target="_blank" rel="noopener">Yazdır</a><a href="' . h($sayfa . '?kopya=' . $id) . '">Kopyala</a></div></details></li>';
}

/** Sol liste sütunu (E1). $L: fatura_listesi sonucu; $turSay: tur → adet (çipler). */
function fatura_liste_kol_html(string $sinif, array $F, array $L, array $turSay, ?int $seciliId, array $cariler): string
{
    $sayfa = fatura_sinif_sayfalari()[$sinif]; $M = fatura_sinif_metin($sinif);
    $turler = fatura_sinif_turleri($sinif);
    $cip = '';
    if (count($turler) > 1) {
        $cip .= '<a class="ft-cip" href="' . h($sayfa . '?' . http_build_query(fatura_filtre_sorgu($F, ['tur' => '']))) . '"' . ($F['tur'] === '' ? ' aria-current="true"' : '') . '>Tümü <span class="ft-cip-say">' . (int)array_sum($turSay) . '</span></a>';
        foreach ($turler as $t) $cip .= '<a class="ft-cip" href="' . h($sayfa . '?' . http_build_query(fatura_filtre_sorgu($F, ['tur' => $t]))) . '"' . ($F['tur'] === $t ? ' aria-current="true"' : '') . '>' . h(fatura_tur_kisa($t)) . ' <span class="ft-cip-say">' . (int)($turSay[$t] ?? 0) . '</span></a>';
        $cip = '<div class="ft-cipler" role="group" aria-label="Belge türü">' . $cip . '</div>';
    }
    $opt = static function (array $l, string $sec): string { $o = ''; foreach ($l as $v => $ad) $o .= '<option value="' . h((string)$v) . '"' . ((string)$v === $sec ? ' selected' : '') . '>' . h($ad) . '</option>'; return $o; };
    $durum = '<select name="durum" data-oto-gonder aria-label="Durum">' . $opt(['tumu' => 'Tüm durumlar', 'gecerli' => 'Geçerli', 'iptal' => 'İptal'], $F['durum']) . '</select>';
    $donem = '<select name="donem" data-oto-gonder aria-label="Dönem">' . $opt(['tumu' => 'Tüm tarihler', '30g' => 'Son 30 gün', 'bu_ay' => 'Bu ay', 'gecen_ay' => 'Geçen ay', 'ozel' => 'Özel aralık…'], $F['donem']) . '</select>';
    $ozel = '<div class="ft-ozel-tarih"' . ($F['donem'] === 'ozel' ? '' : ' hidden') . '><input type="date" name="bas" value="' . h($F['bas']) . '" aria-label="Başlangıç"><input type="date" name="bit" value="' . h($F['bit']) . '" aria-label="Bitiş"></div>';
    $sdl = ['siparis' => ['' => 'Tüm sevk durumları', 'acik' => 'Açık', 'kismi' => 'Kısmi', 'tamam' => 'Tamamlandı', 'kapali' => 'Kapalı'],
        'irsaliye' => ['' => 'Tüm fatura durumları', 'bekleyen' => 'Faturalanmadı', 'kismi' => 'Kısmen faturalandı', 'tam' => 'Faturalandı']][$sinif] ?? null;
    $sd = $sdl !== null ? '<select name="sd" data-oto-gonder aria-label="' . ($sinif === 'siparis' ? 'Sevk durumu' : 'Fatura durumu') . '">' . $opt($sdl, $F['sd']) . '</select>' : '';
    $cm = $F['cari_ara'];
    foreach ($cariler as $c) if ($c['id'] === $F['cari']) $cm = $c['kod'] . ' — ' . $c['unvan'];
    $cari = '<input type="hidden" name="cari" value="' . ($F['cari'] > 0 ? (int)$F['cari'] : '') . '"><input type="search" name="cari_ara" list="ftCariListe" value="' . h($cm) . '" placeholder="Cari" aria-label="Cari süzgeci" autocomplete="off" data-ft-cari-suz>';
    $liste = '';
    foreach ($L['satirlar'] as $b) $liste .= fatura_liste_satiri($b, $seciliId !== null && (int)$b['id'] === $seciliId, $sayfa);
    $filtreli = $F['ara'] !== '' || $F['tur'] !== '' || $F['durum'] !== 'tumu' || $F['donem'] !== 'tumu' || $F['sd'] !== '' || $F['cari'] > 0;
    $bos = '';
    if (!$L['satirlar']) {
        $bos = $filtreli ? '<p class="ft-liste-bos" id="ftSonucYok">Eşleşen belge yok. <a href="' . h($sayfa) . '">Filtreleri temizle</a></p>'
            : '<div id="ftSonucYok">' . fatura_bos($M['bos'], 'İlk belgeyi oluşturun; kayıtta cari dekontu ve (varsa) stok fişi otomatik yazılır.', '<a class="btn btn-primary" href="' . h($sayfa . '?yeni=1') . '">' . h($M['yeni']) . '</a>') . '</div>';
    }
    $pbs = '';
    $faturaSinifi = in_array($sinif, ['satis', 'alis'], true);
    foreach ($L['toplamlar_pb'] as $pb => $x) $pbs .= '<span class="ft-pb"><b>' . h((string)$pb) . '</b> ' . h(cari_tutar_bicim((int)($faturaSinifi ? $x['cari'] : $x['genel']), (string)$pb, false)) . ' <small>(' . (int)$x['adet'] . ')</small></span>';
    $disa = '';
    if (function_exists('can') && can('reports.export')) {
        $q = fatura_filtre_sorgu($F, ['sinif' => $sinif]);
        $disa = '<span class="ft-disa"><a href="' . h('fatura_cikti.php?' . http_build_query($q + ['bicim' => 'xlsx'])) . '">XLSX</a> · <a href="' . h('fatura_cikti.php?' . http_build_query($q + ['bicim' => 'csv'])) . '">CSV</a></span>';
    }
    $n = count($L['satirlar']);
    return '<div class="ft-liste-kol" id="ftListeKol">'
        . '<button type="button" class="ft-liste-ray" data-liste-ac aria-expanded="false" aria-controls="ftListeKol" title="Listeyi aç / kapat"><span aria-hidden="true">&#9776;</span><span class="ft-ray-metin">Liste</span><small>' . (int)$L['toplam_satir'] . '</small></button>'
        . '<div class="ft-liste-ic"><div class="ft-liste-ust"><form method="get" action="' . h($sayfa) . '" class="ft-filtre" role="search">'
        . '<div class="ft-arama-satir"><div class="ft-arama"><svg class="ft-arama-ikon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>'
        . '<label class="ft-sr" for="ftArama">Belge ara: belge no, fatura no, cari, ürün</label>'
        . '<input type="search" id="ftArama" name="ara" placeholder="No, cari, ürün" autocomplete="off" value="' . h($F['ara']) . '"><kbd class="ft-arama-ipucu" aria-hidden="true">/</kbd></div>'
        . '<a class="btn btn-primary" href="' . h($sayfa . '?yeni=1') . '" title="' . h($M['yeni']) . '" data-yeni-belge>+ Yeni</a></div>'
        . ($F['tur'] !== '' ? '<input type="hidden" name="tur" value="' . h($F['tur']) . '">' : '')
        . $cip
        . '<div class="ft-filtre-satir">' . $durum . $donem . $ozel . $cari . $sd . '</div>'
        . '<noscript><button type="submit" class="btn btn-sm">Uygula</button></noscript></form>'
        . '<div class="ft-pb-serit" id="ftToplamPb" aria-label="Para birimi başına toplamlar (geçerli belgeler)">' . $pbs . '</div></div>'
        . $bos
        . '<ul class="ft-liste" id="ftListe" aria-label="' . h($M['baslik']) . '">' . $liste . '</ul>'
        . '<div class="ft-liste-alt"><span id="ftSayi">' . $n . ($L['toplam_satir'] > $n ? ' / ' . (int)$L['toplam_satir'] : '') . ' belge</span>' . $disa . '</div></div></div>';
}

// ── Toplam kutusu (form + detay ortak bileşen) ─────────────────────────────

/**
 * #ftToplam. $t: brut,isk,net,kdv,genel,tevkifat,stopaj,bagkur,cari,kdv_gruplari (kuruş). $o: pb, kur (metin), salt (detay), tevkifat_etiket,
 * stopaj_etiket, bagkur_etiket, istisna, kesinti_html (form seçimleri), tur, faturali (cari etkisi var mı).
 */
function fatura_toplam_html(array $t, string $tur, bool $salt, array $o = []): string
{
    $pb = (string)($o['pb'] ?? 'TRY');
    $b = fatura_tur_bilgi($tur) ?? [];
    $faturali = ($b['cari'] ?? null) !== null;
    $p = static fn(int $k): string => h(cari_tutar_bicim($k, $pb, false));
    $grup = '';
    foreach (($t['kdv_gruplari'] ?? []) as $oran => $g) $grup .= '<tr data-kdv-grup="' . (int)$oran . '"><td>%' . (int)$oran . '</td><td class="ft-sayi">' . $p((int)$g['net']) . '</td><td class="ft-sayi">' . $p((int)$g['kdv']) . '</td></tr>';
    $kesinti = (string)($o['kesinti_html'] ?? '');
    $kur = (string)($o['kur'] ?? '');
    $tlGoster = $pb !== 'TRY' && $kur !== '' && $faturali;
    $tl = $tlGoster ? (int)round((int)$t['genel'] * (float)$kur) : 0;
    $gizli = static fn(bool $v): string => $v ? '' : ' hidden';
    $cariEt = !empty($b['mustahsil']) ? 'Net ödenecek (üreticiye)' : 'Cariye yansıyan';
    return '<section class="ft-toplam' . ($salt ? ' ft-toplam--salt' : '') . (!$faturali ? ' ft-toplam--bilgi' : '') . '" id="ftToplam" aria-labelledby="ftToplamBaslik">'
        . '<h3 class="ft-sr" id="ftToplamBaslik">Toplamlar</h3>'
        . '<div class="ft-top-kdv"><table class="ft-top-kdv-tablo"><caption class="ft-sr">KDV kırılımı</caption><thead><tr><th scope="col">Oran</th><th scope="col" class="ft-sayi">Matrah</th><th scope="col" class="ft-sayi">KDV</th></tr></thead><tbody data-kdv-gruplar>' . $grup . '</tbody></table></div>'
        . ($kesinti !== '' ? '<div class="ft-top-kesinti">' . $kesinti . '</div>' : '')
        . '<dl class="ft-top-liste">'
        . '<div><dt>Ara toplam</dt><dd data-top="brut">' . $p((int)$t['brut']) . '</dd></div>'
        . '<div><dt>İskonto</dt><dd data-top="isk">' . $p((int)$t['isk']) . '</dd></div>'
        . '<div><dt>KDV matrahı</dt><dd data-top="net">' . $p((int)$t['net']) . '</dd></div>'
        . '<div><dt>KDV</dt><dd data-top="kdv">' . $p((int)$t['kdv']) . '</dd></div>'
        . '<div class="ft-top-genel"><dt>' . ($faturali ? 'Genel toplam' : 'Tutar (bilgi)') . '</dt><dd data-top="genel">' . $p((int)$t['genel']) . ' <small data-top-pb>' . h($pb) . '</small></dd></div>'
        . '<div data-satir="tevkifat"' . $gizli((int)$t['tevkifat'] > 0) . '><dt data-et="tevkifat">' . h((string)($o['tevkifat_etiket'] ?? 'Tevkifat')) . '</dt><dd data-top="tevkifat">−' . $p((int)$t['tevkifat']) . '</dd></div>'
        . '<div data-satir="stopaj"' . $gizli((int)$t['stopaj'] > 0 || (!$salt && !empty($b['mustahsil']))) . '><dt data-et="stopaj">' . h((string)($o['stopaj_etiket'] ?? 'GV stopajı')) . '</dt><dd data-top="stopaj">−' . $p((int)$t['stopaj']) . '</dd></div>'
        . '<div data-satir="bagkur"' . $gizli((int)$t['bagkur'] > 0 || (!$salt && !empty($b['mustahsil']))) . '><dt data-et="bagkur">' . h((string)($o['bagkur_etiket'] ?? 'Bağ-Kur')) . '</dt><dd data-top="bagkur">−' . $p((int)$t['bagkur']) . '</dd></div>'
        . '<div class="ft-top-cari" data-satir="cari"' . $gizli($faturali || !$salt) . '><dt data-cari-etiket>' . h($cariEt) . '</dt><dd data-top="cari">' . $p((int)$t['cari']) . '</dd></div>'
        . '<div data-satir="tl"' . $gizli($tlGoster) . '><dt data-et="tl">TL karşılığı' . ($kur !== '' ? ' (kur ' . h(str_replace('.', ',', $kur)) . ')' : '') . '</dt><dd data-top="tl">' . h(cari_tutar_bicim($tl, 'TRY')) . '</dd></div>'
        . '</dl>'
        . (!empty($o['istisna']) ? '<p class="ft-not ft-istisna-not">KDV istisnası: ' . h((string)$o['istisna']) . '</p>' : '')
        . (!$faturali ? '<p class="ft-not ft-cari-yok">Cari hareketi oluşmaz.</p>' : '')
        . '<p class="ft-sr" aria-live="polite" data-top-duyuru></p></section>';
}

/** Belgenin (fatura_oku) toplam dizisi: başlık kolonları + satırlardan KDV kırılımı (gösterim). */
function fatura_belge_toplam(array $b): array
{
    $gr = [];
    foreach ($b['satirlar'] ?? [] as $s) {
        if ($s['tutar_kurus'] === null) continue;
        $o = (int)($s['kdv_orani'] ?? 0);
        $gr[$o] ??= ['net' => 0, 'kdv' => 0];
        $gr[$o]['net'] += (int)$s['tutar_kurus']; $gr[$o]['kdv'] += (int)($s['kdv_tutar_kurus'] ?? 0);
    }
    ksort($gr);
    $k = static fn(string $a): int => cari_kurus($b[$a] ?? 0);
    return ['brut' => $k('brut_toplam'), 'isk' => $k('iskonto_toplam'), 'net' => $k('net_toplam'), 'kdv' => $k('kdv_toplam'), 'genel' => $k('genel_toplam'),
        'tevkifat' => $k('tevkifat_tutar'), 'stopaj' => $k('stopaj_tutar'), 'bagkur' => $k('bagkur_tutar'), 'cari' => $k('cari_tutar'), 'kdv_gruplari' => $gr];
}

/** Belge kesinti etiketleri. */
function fatura_kesinti_etiketleri(array $b): array
{
    return ['tevkifat_etiket' => !empty($b['tevkifat_pay']) ? 'Tevkifat (' . (int)$b['tevkifat_pay'] . '/' . (int)$b['tevkifat_payda'] . ')' : 'Tevkifat',
        'stopaj_etiket' => !empty($b['stopaj_bp']) ? 'GV stopajı %' . fatura_bp_bicim((int)$b['stopaj_bp']) : 'GV stopajı',
        'bagkur_etiket' => !empty($b['bagkur_bp']) ? 'Bağ-Kur %' . fatura_bp_bicim((int)$b['bagkur_bp']) : 'Bağ-Kur'];
}

function fatura_kur_metin(mixed $kur): string { return $kur !== null && $kur !== '' ? rtrim(rtrim((string)$kur, '0'), '.') : ''; }

// ── Detay (E2) ──────────────────────────────────────────────────────────────

/** Bağlı belgeler zinciri. */
function fatura_zincir_html(array $b): string
{
    if (!$b['kaynaklar'] && !$b['hedefler']) return '';
    $oge = static function (array $x, bool $bu = false): string {
        $sayfa = fatura_tur_bilgi((string)$x['tur'])['sayfa'] ?? 'satis_faturalari.php';
        $ip = ($x['durum'] ?? '') === 'iptal';
        $isaret = ['satis' => '&#9650;', 'satis_ihracat' => 'EXP', 'satis_iade' => '&#8634;', 'alis_iade' => '&#8634;', 'alis' => '&#9660;', 'alis_mustahsil' => '&#9660;', 'irsaliye' => '&#8674;', 'siparis' => '&#9671;'][(string)$x['tur']] ?? '';
        return '<a class="ft-zincir-oge' . ($ip ? ' ft-zincir-oge--iptal' : '') . '" href="' . h($sayfa . '?id=' . (int)$x['id']) . '" data-zincir-tur="' . h((string)$x['tur']) . '"' . ($bu ? ' aria-current="page"' : '') . '>'
            . '<span aria-hidden="true">' . $isaret . '</span> ' . h((string)$x['belge_no']) . ($ip ? ' (iptal)' : '') . '</a>';
    };
    $li = '';
    if ($b['kaynaklar']) $li .= '<li>' . implode(', ', array_map($oge, $b['kaynaklar'])) . '</li>';
    $li .= '<li>' . $oge(['id' => $b['id'], 'tur' => $b['tur'], 'belge_no' => $b['belge_no'], 'durum' => $b['durum']], true) . '</li>';
    if ($b['hedefler']) $li .= '<li>' . implode(', ', array_map($oge, $b['hedefler'])) . '</li>';
    return '<div class="ft-zincir-kap" id="ftZincir"><span class="ft-zincir-et">Bağlı belgeler:</span><ol class="ft-zincir" aria-label="Bağlı belgeler">' . $li . '</ol></div>';
}

/** Satır tablosu + mobil kartları (detay). */
function fatura_detay_satirlar(array $b, array $kaynakBilgi): string
{
    $tur = (string)$b['tur']; $pb = (string)$b['para_birimi'];
    $fiyat = false;
    foreach ($b['satirlar'] as $s) if ($s['f4'] !== null) { $fiyat = true; break; }
    $sayac = match (true) { $tur === 'siparis' => 'siparis', $tur === 'irsaliye' => 'irsaliye', in_array($tur, ['satis', 'satis_ihracat', 'alis', 'alis_mustahsil'], true) => 'fatura', default => '' };
    $th = '<th scope="col" class="ft-sayi">#</th><th scope="col">Kod</th><th scope="col">Ürün / hizmet</th><th scope="col" class="ft-sayi">Miktar</th>'
        . ($fiyat ? '<th scope="col" class="ft-sayi">B.fiyat (' . h($pb) . ')</th><th scope="col" class="ft-sayi">İsk %</th><th scope="col" class="ft-sayi">KDV</th><th scope="col" class="ft-sayi">Tutar</th>' : '')
        . match ($sayac) { 'siparis' => '<th scope="col" class="ft-sayi">Karşılanan</th><th scope="col" class="ft-sayi">Faturalanan</th><th scope="col" class="ft-sayi">Kalan</th>',
            'irsaliye' => '<th scope="col" class="ft-sayi">Faturalanan</th><th scope="col" class="ft-sayi">Kalan</th>', 'fatura' => '<th scope="col" class="ft-sayi">İade edilen</th>', default => '' };
    $tr = ''; $kart = '';
    foreach ($b['satirlar'] as $n => $s) {
        $bm = (string)$s['birim']; $mik = h(fatura_miktar((int)$s['milli'], $bm));
        $kb = $s['kaynak_satir_id'] !== null ? ($kaynakBilgi[(int)$s['kaynak_satir_id']] ?? null) : null;
        $kroz = $kb !== null ? ' <span class="ft-rozet ft-rozet--kaynak">' . h($kb['belge_no'] . ' · ' . $kb['sira']) . '</span>' : '';
        $kartsiz = $s['kart_id'] === null ? ' <span class="ft-rozet">Kartsız</span>' : '';
        $ek = array_filter([!empty($s['lot']) ? 'Lot ' . $s['lot'] : '', !empty($s['gtip']) ? 'GTİP ' . $s['gtip'] : '', !empty($s['aciklama']) ? (string)$s['aciklama'] : '']);
        $kalanH = static fn(int $k) => '<td class="ft-sayi' . ($k > 0 ? ' ft-kalan--acik' : '') . '">' . h(fatura_miktar($k, $bm)) . '</td>';
        $sayH = match ($sayac) {
            'siparis' => '<td class="ft-sayi">' . h(fatura_miktar((int)$s['kullanilan_milli'], $bm)) . '</td><td class="ft-sayi">' . h(fatura_miktar((int)$s['faturalanan_milli'], $bm)) . '</td>' . $kalanH((int)$s['kalan_milli']),
            'irsaliye' => '<td class="ft-sayi">' . h(fatura_miktar((int)$s['faturalanan_milli'], $bm)) . '</td>' . $kalanH((int)$s['kalan_milli']),
            'fatura' => '<td class="ft-sayi">' . h(fatura_miktar((int)$s['iade_milli'], $bm)) . '</td>', default => '' };
        $tut = $s['tutar_kurus'] !== null ? h(fatura_para((int)$s['tutar_kurus'], $pb)) : '—';
        $tr .= '<tr><td class="ft-sayi">' . ($n + 1) . '</td><td class="ft-kod">' . h((string)($s['kart_kod'] ?? '')) . '</td><td>' . h((string)$s['urun_adi']) . $kartsiz . $kroz . ($ek ? '<small class="ft-ipucu"> · ' . h(implode(' · ', $ek)) . '</small>' : '') . '</td>'
            . '<td class="ft-sayi">' . $mik . '</td>'
            . ($fiyat ? '<td class="ft-sayi">' . ($s['f4'] !== null ? h(stok_fiyat_bicim((int)$s['f4'])) : '—') . '</td><td class="ft-sayi">' . ((int)$s['iskonto_bp'] > 0 ? h(fatura_bp_bicim((int)$s['iskonto_bp'])) : '—') . '</td>'
                . '<td class="ft-sayi">' . ($s['kdv_orani'] !== null ? '%' . (int)$s['kdv_orani'] : '—') . '</td><td class="ft-sayi">' . $tut . '</td>' : '')
            . $sayH . '</tr>';
        $kalanM = $sayac === 'siparis' || $sayac === 'irsaliye' ? '<span class="' . ((int)$s['kalan_milli'] > 0 ? 'ft-kalan--acik' : '') . '">Kalan ' . h(fatura_miktar((int)$s['kalan_milli'], $bm)) . '</span>' : ($sayac === 'fatura' && (int)$s['iade_milli'] > 0 ? '<span>İade ' . h(fatura_miktar((int)$s['iade_milli'], $bm)) . '</span>' : '');
        $kart .= '<article class="ft-hk"><div class="ft-hk-ust"><span><b>' . h((string)$s['urun_adi']) . '</b>' . $kartsiz . $kroz . '</span><span class="ft-miktar">' . $mik . '</span></div>'
            . '<div class="ft-hk-alt"><span>' . ($s['f4'] !== null ? h(stok_fiyat_bicim((int)$s['f4'])) . ' · ' . ($s['kdv_orani'] !== null ? 'KDV %' . (int)$s['kdv_orani'] . ' · ' : '') . '<b>' . $tut . '</b>' : 'Fiyatsız') . '</span>' . $kalanM . '</div></article>';
    }
    return '<h3 class="ft-bolum-baslik">Satırlar <small>' . count($b['satirlar']) . '</small></h3>'
        . '<div class="ft-dtablo-kaydir table-wrap"><table class="data-table ft-tablo ft-dtablo"><caption class="ft-sr">Belge satırları</caption><thead><tr>' . $th . '</tr></thead><tbody>' . $tr . '</tbody></table></div>'
        . '<div class="ft-hk-liste">' . $kart . '</div>';
}

/** Detay bölümü (#ftDetay). $b: fatura_oku sonucu + kaynak_bilgi (fatura_kaynak_satir_bilgileri) + cari_bakiye (opsiyonel). */
function fatura_detay_html(array $b, array $kaynakBilgi = []): string
{
    $tur = (string)$b['tur']; $id = (int)$b['id']; $iptal = $b['durum'] === 'iptal';
    $bilgi = fatura_tur_bilgi($tur) ?? [];
    $sayfa = (string)($bilgi['sayfa'] ?? 'satis_faturalari.php');
    $pb = (string)$b['para_birimi'];
    $akis = (string)$b['akis_durum'];
    $kur = fatura_kur_metin($b['kur'] ?? null);
    $meta1 = array_filter([!empty($b['fatura_no']) ? '<span class="ft-fno">' . h((string)$b['fatura_no']) . '</span>' : '', '<span>' . h(fatura_tarih_tr((string)$b['tarih'])) . '</span>',
        !empty($b['vade_tarihi']) ? '<span>Vade ' . h(fatura_tarih_tr((string)$b['vade_tarihi'])) . '</span>' : '', !empty($b['termin_tarihi']) ? '<span>Termin ' . h(fatura_tarih_tr((string)$b['termin_tarihi'])) . '</span>' : '']);
    $meta2 = '<span>Cari: <a href="cari_kartlari.php?id=' . (int)$b['cari_id'] . '">' . h((string)$b['cari_unvan']) . '</a></span><span>Depo: ' . h((string)$b['depo']) . '</span>'
        . '<span>' . h($pb) . ($kur !== '' ? ' · kur ' . h(str_replace('.', ',', $kur)) : '') . '</span>';
    $serit = $iptal ? '<div class="ft-iptal-serit" role="note"><b>İptal edildi:</b> ' . h((string)$b['iptal_neden']) . ' · ' . h(fatura_tarih_tr((string)$b['iptal_at'])) . '</div>' : '';
    $kapali = $tur === 'siparis' && (int)$b['kapali'] === 1 && !$iptal ? '<div class="ft-belge-uyari"><b>&#128274; Sipariş kapalı:</b> ' . h((string)$b['kapali_neden']) . '</div>' : '';
    $uyari = !empty($b['uyari']) ? '<div class="ft-belge-uyari"><span class="ft-rozet ft-rozet--uyari">Eksi stok</span> ' . h((string)$b['uyari']) . '</div>' : '';
    // İşlemler
    $kalanVar = false;
    foreach ($b['satirlar'] as $s) if ((int)$s['kalan_milli'] > 0) { $kalanVar = true; break; }
    $donusum = static function (string $etiket, string $kanca, string $url, bool $birincil) use ($iptal, $kalanVar, $tur, $b): string {
        $kapaliS = $tur === 'siparis' && (int)$b['kapali'] === 1;
        $neden = $iptal ? 'Belge iptal' : ($kapaliS ? 'Sipariş kapalı' : (!$kalanVar ? 'Kalan miktar yok' : ''));
        $cls = 'btn' . ($birincil ? ' btn-primary' : '');
        return $neden !== '' ? '<button type="button" class="' . $cls . '" ' . $kanca . ' disabled title="' . h($neden) . '">' . h($etiket) . '</button>'
            : '<a class="' . $cls . '" ' . $kanca . ' href="' . h($url) . '">' . h($etiket) . '</a>';
    };
    $yaz = '<a class="btn' . (in_array($tur, ['irsaliye', 'siparis'], true) ? '' : ' btn-primary') . '" href="fatura_cikti.php?id=' . $id . '" target="_blank" rel="noopener" data-yazdir>Yazdır</a>';
    $kop = '<a class="btn" href="' . h($sayfa . '?kopya=' . $id) . '" data-kopya>Kopyala</a>';
    $birincil = ''; $ikincil = [];
    if (in_array($tur, ['satis', 'satis_ihracat'], true)) { $birincil = $yaz; $ikincil = [$kop, $donusum('İade oluştur', 'data-iade', 'satis_faturalari.php?yeni=1&tur=satis_iade&kaynak=' . $id, false)]; }
    elseif (in_array($tur, ['alis', 'alis_mustahsil'], true)) { $birincil = $yaz; $ikincil = [$kop, $donusum('İade oluştur', 'data-iade', 'alis_faturalari.php?yeni=1&tur=alis_iade&kaynak=' . $id, false)]; }
    elseif ($tur === 'irsaliye') { $birincil = $donusum('Faturalaştır', 'data-faturalastir', 'satis_faturalari.php?yeni=1&tur=satis&kaynak=' . $id, true); $ikincil = [$yaz, $kop]; }
    elseif ($tur === 'siparis') {
        $birincil = $donusum('İrsaliye oluştur', 'data-irsaliye-olustur', 'sevk_irsaliyeleri.php?yeni=1&tur=irsaliye&kaynak=' . $id, true);
        $ikincil = [$donusum('Fatura oluştur', 'data-fatura-olustur', 'satis_faturalari.php?yeni=1&tur=satis&kaynak=' . $id, false), $yaz, $kop];
        if (!$iptal && fatura_can('edit')) $ikincil[] = '<button type="button" class="btn" data-ft-modal="kapat" data-id="' . $id . '" data-belge-no="' . h((string)$b['belge_no']) . '" data-kapali="' . ((int)$b['kapali'] === 1 ? '1' : '0') . '" data-geri="' . h($sayfa . '?id=' . $id) . '">' . ((int)$b['kapali'] === 1 ? 'Siparişi aç' : 'Siparişi kapat') . '</button>';
    } else { $birincil = $yaz; $ikincil = [$kop]; }
    $iptBtn = $iptal || !fatura_can('delete')
        ? '<button type="button" class="btn ft-tehlike" disabled title="' . ($iptal ? 'Belge zaten iptal edildi' : 'Yetkiniz yok') . '">İptal et</button>'
        : '<button type="button" class="btn ft-tehlike" data-ft-modal="iptal" data-id="' . $id . '" data-belge-no="' . h((string)$b['belge_no']) . '" data-tur="' . h($tur) . '" data-geri="' . h($sayfa . '?id=' . $id) . '">İptal et</button>';
    $islem = '<div class="ft-islemler" role="toolbar" aria-label="Belge işlemleri">' . $birincil . '<span class="ft-islem-ikincil">' . implode('', $ikincil) . '</span>'
        . '<details class="ft-diger-menu"><summary class="btn">&#8943; Diğer</summary><div class="ft-diger-ic">' . implode('', $ikincil) . $iptBtn . '</div></details>'
        . '<span class="ft-islem-tehlike">' . $iptBtn . '</span></div>';
    // Diğer bilgiler
    $dl = [];
    $ek = ['istisna_kod' => 'İstisna', 'odeme_sekli' => 'Ödeme şekli', 'teslim_sekli' => 'Teslim şekli', 'teslim_yeri' => 'Teslim yeri', 'beyanname_no' => 'Beyanname no', 'plaka' => 'Plaka', 'sofor_adi' => 'Şoför', 'sevk_adresi' => 'Sevk adresi'];
    foreach ($ek as $k => $ad) if (!empty($b[$k])) $dl[] = '<div><dt>' . h($ad) . '</dt><dd>' . h($k === 'istisna_kod' ? $b['istisna_kod'] . ' — ' . ($b['istisna_ad'] ?? '') : (string)$b[$k]) . '</dd></div>';
    foreach (['brut_kg' => 'Brüt kg', 'net_kg' => 'Net kg'] as $k => $ad) if ($b[$k] !== null && $b[$k] !== '') $dl[] = '<div><dt>' . $ad . '</dt><dd>' . h(stok_miktar_bicim(stok_milli($b[$k]), 'kg')) . '</dd></div>';
    if ($b['kap_adedi'] !== null && $b['kap_adedi'] !== '') $dl[] = '<div><dt>Kap adedi</dt><dd>' . (int)$b['kap_adedi'] . '</dd></div>';
    $diger = $dl ? '<dl class="ft-dl">' . implode('', $dl) . '</dl>' : '';
    // Bağlantılar
    $bag = [];
    if (!empty($b['stok_fis'])) {
        $sf = $b['stok_fis'];
        $ss = ($sf['tur'] ?? '') === 'giris' ? 'stok_giris_fisleri.php' : 'stok_cikis_fisleri.php';
        $bag[] = '<a data-bag="stok_fis" href="' . $ss . '?id=' . (int)$sf['id'] . '">Stok fişi ' . h((string)$sf['fis_no']) . '</a>' . (($sf['durum'] ?? '') === 'iptal' ? ' <span class="ft-rozet ft-rozet--iptal">İPTAL</span>' : '');
    } elseif (($bilgi['stok'] ?? null) !== null && $tur !== 'irsaliye') {
        $bag[] = '<span class="ft-ipucu">Stok hareketi yok' . ((int)$b['stok_etkisi'] === 0 ? ' (stok etkisi kapalı)' : ' (satırlar irsaliyeden ya da kartsız)') . '</span>';
    }
    if (!empty($b['cari_hareket'])) {
        $ch = $b['cari_hareket'];
        $bag[] = '<a data-bag="cari_hareket" href="cari_kartlari.php?id=' . (int)$b['cari_id'] . '">Cari dekont ' . h((string)$ch['belge_no']) . '</a>' . (($ch['durum'] ?? '') === 'iptal' ? ' <span class="ft-rozet ft-rozet--iptal">İPTAL</span>' : '');
    }
    if (($bilgi['cari'] ?? null) !== null) $bag[] = '<a data-bag="ekstre" href="cari_ekstre.php?id=' . (int)$b['cari_id'] . '" target="_blank" rel="noopener">Cari ekstre</a>';
    $bagH = $bag ? '<p class="ft-baglar"><b>Bağlantılar:</b> ' . implode(' · ', $bag) . '</p>' : '';
    $t = fatura_belge_toplam($b);
    $topH = ($bilgi['cari'] ?? null) !== null || $t['genel'] > 0
        ? fatura_toplam_html($t, $tur, true, ['pb' => $pb, 'kur' => $kur, 'istisna' => !empty($b['istisna_kod']) ? $b['istisna_kod'] . ' — ' . ($b['istisna_ad'] ?? '') : ''] + fatura_kesinti_etiketleri($b)) : '';
    return '<section class="ft-detay" id="ftDetay" data-id="' . $id . '" data-tur="' . h($tur) . '" data-durum="' . h((string)$b['durum']) . '" aria-label="Belge detayı"><div class="ft-detay-kart">'
        . '<a class="ft-geri" href="' . h($sayfa) . '"><span aria-hidden="true">&#8592;</span> Liste</a>'
        . '<header class="ft-baslik"><div class="ft-baslik-satir"><h2 class="ft-unvan">' . h((string)$b['belge_no']) . '</h2><span class="ft-baslik-rozet">' . fatura_tur_rozet($tur)
        . ($iptal ? ' <span class="ft-rozet ft-rozet--iptal">İPTAL</span>' : ' ' . (fatura_akis_rozet($tur, $akis) ?: '<span class="ft-rozet ft-rozet--gecerli">Geçerli</span>')) . '</span></div>'
        . '<div class="ft-meta">' . implode('', $meta1) . '</div><div class="ft-meta">' . $meta2 . '</div></header>'
        . $serit . $kapali . $uyari . $islem . fatura_zincir_html($b)
        . '<div class="ft-detay-icerik">' . fatura_detay_satirlar($b, $kaynakBilgi) . $topH . $diger . $bagH
        . (!empty($b['aciklama']) ? '<p class="ft-notlar"><b>Açıklama:</b> ' . nl2br(h((string)$b['aciklama'])) . '</p>' : '')
        . '</div></div></section>';
}

// ── Form (E3) ───────────────────────────────────────────────────────────────

/** data-ft-goster sarmalayıcı öznitelikleri + girdi disabled eki. @return array{0:string,1:string} */
function fatura_goster(array $turler, string $tur): array
{
    $on = in_array($tur, $turler, true);
    return [' data-ft-goster="' . h(implode(' ', $turler)) . '"' . ($on ? '' : ' hidden'), $on ? '' : ' disabled'];
}

/** KDV seçenekleri. */
function fatura_kdv_opt(array $oranlar, ?int $secili, bool $sifir): string
{
    if ($sifir) return '<option value="0" selected>%0</option>';
    $o = '';
    foreach ($oranlar as $k) $o .= '<option value="' . (int)$k . '"' . ($secili === (int)$k ? ' selected' : '') . '>%' . (int)$k . '</option>';
    return $o;
}

/** Birim seçenekleri: kartlıda ana ('') + çevrim; kartsızda '' (—) + tüm birimler. */
function fatura_birim_opt(?array $kart, string $secili): string
{
    if ($kart !== null) {
        $o = '<option value="">' . h((string)$kart['birim']) . '</option>';
        if (!empty($kart['cevrim_birim'])) $o .= '<option value="' . h((string)$kart['cevrim_birim']) . '"' . ($secili === $kart['cevrim_birim'] ? ' selected' : '') . '>' . h($kart['cevrim_birim'] . ' (×' . stok_miktar_bicim((int)round((float)$kart['cevrim_katsayi'] * 1000), 'lt') . ' ' . $kart['birim'] . ')') . '</option>';
        return $o;
    }
    $o = '<option value="">—</option>';
    foreach (stok_birimler() as $b => $ad) $o .= '<option value="' . h($b) . '"' . ($secili === $b ? ' selected' : '') . '>' . h($ad) . '</option>';
    return $o;
}

/**
 * Tek form satırı (PC tablo satırı / mobil kart). $s: kart_id, urun_adi, miktar, birim, birim_fiyat, iskonto, kdv_orani, gtip, satir_brut_kg, satir_net_kg, satir_kap,
 * lot, satir_aciklama, kaynak_satir_id, kalan_milli, kaynak_belge_no. $c: kdv (oranlar), kdv_vs, sifir, tur, sinifTurleri.
 */
function fatura_form_satiri(array $s, ?array $kart, int $no, array $c): string
{
    $kartMetin = $kart !== null ? $kart['kod'] . ' — ' . $kart['ad'] : (string)($s['urun_adi'] ?? '');
    $kdvSec = ($s['kdv_orani'] ?? '') !== '' ? (int)$s['kdv_orani'] : ($kart !== null && in_array((int)$kart['kdv'], $c['kdv'], true) ? (int)$kart['kdv'] : $c['kdv_vs']);
    $birim = $kart !== null ? (string)$kart['birim'] : (string)($s['birim'] ?? '');
    $ksid = (string)($s['kaynak_satir_id'] ?? '');
    $kalan = $ksid !== '' && isset($s['kalan_milli']) ? (int)$s['kalan_milli'] : null;
    $ayr = array_filter([(string)($s['lot'] ?? ''), (string)($s['satir_aciklama'] ?? ''), (string)($s['gtip'] ?? ''), (string)($s['satir_brut_kg'] ?? ''), (string)($s['satir_net_kg'] ?? ''), (string)($s['satir_kap'] ?? '')], static fn($x) => $x !== '');
    [$xG, $xD] = fatura_goster(['satis_ihracat'], $c['tur']);
    $v = static fn(string $a): string => h((string)($s[$a] ?? ''));
    $L = 'Satır ' . $no . ' ';
    return '<tr class="ft-fsatir' . ($ksid !== '' ? ' ft-fsatir--kaynakli' : '') . '" data-i="' . ($no - 1) . '" data-kaynak-satir="' . h($ksid) . '" data-kalan-milli="' . ($kalan !== null ? $kalan : '') . '">'
        . '<td class="ft-fs-no" aria-hidden="true">' . $no . '</td>'
        . '<td class="ft-fs-kart"><button type="button" class="ft-fs-ac" aria-label="' . h($L) . 'düzenle"><span class="ft-fs-ozet"></span></button>'
        . '<div class="ft-fs-gir"><input type="hidden" name="kart_id[]" value="' . ($kart !== null ? (int)$kart['id'] : '') . '"><input type="hidden" name="urun_adi[]" value="' . ($kart === null ? h((string)($s['urun_adi'] ?? '')) : '') . '">'
        . '<input type="hidden" name="kaynak_satir_id[]" value="' . h($ksid) . '">'
        . '<input type="text" name="kart_ara[]" class="ft-kart-ara" list="ftKartListe" value="' . h($kartMetin) . '" placeholder="Ürün / stok kartı (kod, ad, barkod)" autocomplete="off" spellcheck="false" aria-label="' . h($L) . 'ürün">'
        . '<small class="ft-fs-ipucu" aria-live="polite"></small>'
        . ($ksid !== '' && !empty($s['kaynak_belge_no']) ? '<span class="ft-rozet ft-rozet--kaynak">' . h((string)$s['kaynak_belge_no']) . '</span>' : '')
        . '<details class="ft-sx"' . ($ayr ? ' open' : '') . '><summary>Ayrıntı</summary><div class="ft-sx-ic">'
        . '<label><span>Lot</span><input type="text" name="lot[]" maxlength="60" value="' . $v('lot') . '" autocomplete="off"></label>'
        . '<label class="ft-sx-genis"><span>Satır açıklaması</span><input type="text" name="satir_aciklama[]" maxlength="200" value="' . $v('satir_aciklama') . '" autocomplete="off"></label>'
        . '<label' . $xG . '><span>GTİP</span><input type="text" name="gtip[]" maxlength="12" inputmode="numeric" value="' . $v('gtip') . '" autocomplete="off"' . $xD . '></label>'
        . '<label' . $xG . '><span>Brüt kg</span><input type="text" name="satir_brut_kg[]" inputmode="numeric" value="' . $v('satir_brut_kg') . '" autocomplete="off"' . $xD . '></label>'
        . '<label' . $xG . '><span>Net kg</span><input type="text" name="satir_net_kg[]" inputmode="numeric" value="' . $v('satir_net_kg') . '" autocomplete="off"' . $xD . '></label>'
        . '<label' . $xG . '><span>Kap</span><input type="text" name="satir_kap[]" inputmode="numeric" value="' . $v('satir_kap') . '" autocomplete="off"' . $xD . '></label>'
        . '</div></details></div></td>'
        . '<td class="ft-fs-miktar ft-fs-gir"><input type="text" name="miktar[]" value="' . $v('miktar') . '" inputmode="' . ($birim !== '' && !stok_birim_tam_mi($birim) ? 'decimal' : 'numeric') . '" autocomplete="off" aria-label="' . h($L) . 'miktar">'
        . '<small class="ft-cevrim" aria-live="polite"></small><small class="ft-kalan">' . ($kalan !== null ? 'kalan ' . h(fatura_miktar($kalan, $birim !== '' ? $birim : 'adet')) : '') . '</small></td>'
        . '<td class="ft-fs-birim ft-fs-gir"><select name="birim[]" aria-label="' . h($L) . 'birim">' . fatura_birim_opt($kart, (string)($s['birim'] ?? '')) . '</select></td>'
        . '<td class="ft-fs-fiyat ft-fs-gir"><input type="text" name="birim_fiyat[]" value="' . $v('birim_fiyat') . '" inputmode="decimal" autocomplete="off" aria-label="' . h($L) . 'birim fiyat (KDV hariç)"></td>'
        . '<td class="ft-fs-isk ft-fs-gir"><input type="text" name="iskonto[]" value="' . $v('iskonto') . '" inputmode="decimal" autocomplete="off" aria-label="' . h($L) . 'iskonto yüzde"></td>'
        . '<td class="ft-fs-kdv ft-fs-gir"><select name="kdv_orani[]" aria-label="' . h($L) . 'KDV oranı"' . ($c['sifir'] ? ' aria-readonly="true"' : '') . '>' . fatura_kdv_opt($c['kdv'], $kdvSec, $c['sifir']) . '</select></td>'
        . '<td class="ft-fs-tutar"><output class="ft-fs-tutar-deger">—</output></td>'
        . '<td class="ft-fs-menu"><button type="button" class="ft-menu-btn" data-satir-menu aria-label="' . h($L) . 'işlemleri" aria-haspopup="menu">&#8943;</button></td></tr>';
}

/** Kopya formu: snapshot adlarını aktif tanım id'lerine eşler. */
function fatura_kopya_eslestir(array $o, array $paket): array
{
    $bul = static function (string $tur, string $alan, string $deger) use ($paket): string {
        if ($deger === '') return '';
        foreach ($paket[$tur] ?? [] as $t) if ((string)($t[$alan] ?? '') === $deger) return (string)$t['id'];
        return '';
    };
    $o['tevkifat_id'] = $bul('tevkifat', 'ad', (string)($o['tevkifat_ad'] ?? ''));
    $o['stopaj_id'] = $bul('stopaj', 'ad', (string)($o['stopaj_ad'] ?? ''));
    $o['bagkur_id'] = $bul('bagkur', 'ad', (string)($o['bagkur_ad'] ?? ''));
    $o['istisna_id'] = $bul('istisna', 'kod', (string)($o['istisna_kod'] ?? ''));
    $o['teslim_id'] = $bul('teslim', 'kod', (string)($o['teslim_sekli'] ?? ''));
    return $o;
}

/** Tanım seçimi <select>. */
function fatura_tanim_select(string $ad, array $liste, string $secili, string $bos, string $dis, string $etiketAlan = 'ad', bool $varsayilanSec = false): string
{
    $o = '<option value="">' . h($bos) . '</option>';
    $vs = '';
    if ($secili === '' && $varsayilanSec) foreach ($liste as $t) if (!empty($t['varsayilan'])) { $vs = (string)$t['id']; break; }
    $sec = $secili !== '' ? $secili : $vs;
    foreach ($liste as $t) {
        $et = $etiketAlan === 'kod_ad' ? (string)$t['kod'] . ' — ' . $t['ad'] : (string)$t['ad'];
        $o .= '<option value="' . (int)$t['id'] . '"' . ((string)$t['id'] === $sec ? ' selected' : '') . ' data-d1="' . h((string)($t['deger1'] ?? '')) . '" data-d2="' . h((string)($t['deger2'] ?? '')) . '" data-kod="' . h((string)($t['kod'] ?? '')) . '">' . h($et) . '</option>';
    }
    return '<select name="' . h($ad) . '"' . $dis . '>' . $o . '</select>';
}

/**
 * Belge formu (#ftDetay içinde). $o: başlangıç değerleri (kopya / kaynak / boş): tur, tarih, depo, cari_id, para_birimi, kur, seri_id, fatura_no, vade_tarihi,
 * termin_tarihi, stok_etkisi, *_id tanım seçimleri, odeme_sekli, teslim_yeri, brut_kg, net_kg, kap_adedi, beyanname_no, plaka, sofor_adi, sevk_adresi, aciklama,
 * satirlar[], kaynak_bilgi, kopya_no.
 */
function fatura_form_html(PDO $pdo, string $sinif, array $o, array $cariler): string
{
    $turler = fatura_sinif_turleri($sinif);
    $tur = in_array((string)($o['tur'] ?? ''), $turler, true) ? (string)$o['tur'] : $turler[0];
    $bilgi = fatura_tur_bilgi($tur);
    $M = fatura_sinif_metin($sinif); $sayfa = fatura_sinif_sayfalari()[$sinif];
    $paket = fatura_tanimlar_paket($pdo);
    $kdvT = fatura_kdv_oranlari($pdo);
    $kartlar = [];
    foreach (stok_kart_secenekleri($pdo) as $k) $kartlar[$k['id']] = $k;
    $aktif = function_exists('active_depot') ? (string)(active_depot() ?? '') : '';
    $depo = (string)($o['depo'] ?? '') !== '' ? (string)$o['depo'] : $aktif;
    $depoOpt = '';
    foreach (depot_options() as $d) if (depot_visible_to_user((string)$d)) $depoOpt .= '<option value="' . h($d) . '"' . ($d === $depo ? ' selected' : '') . '>' . h($d) . '</option>';
    if ($depoOpt === '') $depoOpt = '<option value="">Depo tanımı yok</option>';
    $al = static fn(string $etiket, string $ic, string $sinif = '', string $ek = ''): string => '<label class="pm-label ft-alan' . ($sinif !== '' ? ' ' . $sinif : '') . '"' . $ek . '><span>' . $etiket . '</span>' . $ic . '</label>';
    $z = ' <b class="ft-zorunlu" aria-hidden="true">*</b>';
    $v = static fn(string $a): string => h((string)($o[$a] ?? ''));
    $faturaTurleri = array_values(array_filter($turler, static fn($t) => fatura_tur_bilgi($t)['cari'] !== null));

    // Cari
    $cid = (int)($o['cari_id'] ?? 0); $cm = '';
    foreach ($cariler as $c) if ($c['id'] === $cid) $cm = $c['kod'] . ' — ' . $c['unvan'];
    $cariEt = $sinif === 'siparis' ? 'Müşteri' : ($tur === 'alis_mustahsil' ? 'Cari / üretici' : 'Cari');
    $bas = $al($cariEt . $z, '<input type="hidden" name="cari_id" value="' . ($cid > 0 ? $cid : '') . '"><input type="text" name="cari_ara" list="ftCariListe" value="' . h($cm) . '" placeholder="Cari ara: kod ya da unvan" autocomplete="off" spellcheck="false" required data-ilk-odak>'
        . '<span class="ft-cari-ozet" id="ftCariOzet" aria-live="polite"></span>', 'ft-alan--cari');
    $bas .= $al(($sinif === 'irsaliye' ? 'Sevk tarihi' : 'Tarih') . $z, '<input type="date" name="tarih" value="' . h((string)($o['tarih'] ?? date('Y-m-d'))) . '" required>');
    $bas .= $al('Depo' . $z, '<select name="depo" required>' . $depoOpt . '</select>');
    // Seri (biz) — grup başına ayrı select; görünmeyen disabled
    $seriGrup = [];
    foreach ($turler as $t) { $tb = fatura_tur_bilgi($t); if ($tb['duzenleyen'] === 'biz') $seriGrup[(string)$tb['seri_grubu']][] = $t; }
    foreach ($seriGrup as $grup => $tl) {
        [$g, $d] = fatura_goster($tl, $tur);
        $liste = array_values(array_filter($paket['seri'], static fn($s) => (string)$s['belge_turu'] === $grup));
        if (!$liste) {
            $bas .= '<div class="ft-alan ft-uyari ft-uyari--ince" data-ft-on-sart="seri"' . $g . '>Bu tür için seri tanımlı değil — <a href="fatura_tanimlari.php?sekme=seri">Fatura Tanımları › Seriler</a></div>';
            continue;
        }
        $sec = (string)($o['seri_id'] ?? '');
        $bas .= $al('Seri', fatura_tanim_select('seri_id', $liste, $sec, '', $d, 'ad', true) . '<small class="ft-ipucu" data-seri-onizleme></small>', 'ft-alan--seri', $g);
    }
    // Karşı tarafın numarası
    $karsi = array_values(array_filter($turler, static fn($t) => fatura_tur_bilgi($t)['duzenleyen'] === 'karsi'));
    if ($karsi) {
        [$g, $d] = fatura_goster($karsi, $tur);
        $et = $sinif === 'siparis' ? 'Müşteri sipariş no' : ($sinif === 'alis' ? 'Fatura no (tedarikçinin)' . $z : 'İade faturası no (karşı tarafın)' . $z);
        $bas .= $al($et, '<input type="text" name="fatura_no" maxlength="60" value="' . $v('fatura_no') . '" autocomplete="off"' . $d . '>', '', $g);
    }
    if ($faturaTurleri) { [$g, $d] = fatura_goster($faturaTurleri, $tur); $bas .= $al('Vade', '<input type="date" name="vade_tarihi" value="' . $v('vade_tarihi') . '"' . $d . ' data-vade>', '', $g); }
    if ($sinif === 'siparis') $bas .= $al('Termin', '<input type="date" name="termin_tarihi" value="' . $v('termin_tarihi') . '">');
    $pb = (string)($o['para_birimi'] ?? 'TRY');
    $pbOpt = ''; foreach (cari_para_birimleri() as $p) $pbOpt .= '<option value="' . h($p) . '"' . ($p === $pb ? ' selected' : '') . '>' . h($p) . '</option>';
    $bas .= $al('Para birimi', '<select name="para_birimi">' . $pbOpt . '</select>', 'ft-alan--pb');
    $bas .= $al('Kur', '<input type="text" name="kur" value="' . $v('kur') . '" inputmode="decimal" autocomplete="off" placeholder="TL için boş"' . ($pb === 'TRY' ? ' disabled' : '') . '>', 'ft-alan--pb');
    // Stok etkisi
    if ($sinif === 'irsaliye') {
        $bas .= '<p class="ft-alan ft-not ft-stok-not">Stoktan düşer (her satırda stok kartı zorunlu).</p>';
    } elseif ($sinif !== 'siparis') {
        $se = array_key_exists('stok_etkisi', $o) ? (int)$o['stok_etkisi'] === 1 : $sinif === 'alis';
        $et = '';
        foreach ($turler as $t) {
            [$g] = fatura_goster([$t], $tur);
            $et .= '<span' . $g . '>' . h(['satis' => 'Stoktan düş (irsaliyesiz)', 'satis_ihracat' => 'Stoktan düş (irsaliyesiz)', 'satis_iade' => 'Stoğa geri al', 'alis' => 'Stoğa gir', 'alis_mustahsil' => 'Stoğa gir', 'alis_iade' => 'Stoktan düş'][$t] ?? 'Stok etkisi') . '</span>';
        }
        $bas .= '<label class="ft-alan ft-onay"><input type="checkbox" name="stok_etkisi" value="1"' . ($se ? ' checked' : '') . '>' . $et . '</label>';
    }
    // İstisna (ihracat)
    if (in_array('satis_ihracat', $turler, true)) {
        [$g, $d] = fatura_goster(['satis_ihracat'], $tur);
        $bas .= $al('KDV istisnası' . $z, fatura_tanim_select('istisna_id', $paket['istisna'], (string)($o['istisna_id'] ?? ''), 'Seçiniz', $d, 'ad', true), '', $g);
    }
    if ($sinif === 'irsaliye') {
        $bas .= $al('Plaka', '<input type="text" name="plaka" maxlength="60" value="' . $v('plaka') . '" autocomplete="off">')
            . $al('Şoför', '<input type="text" name="sofor_adi" maxlength="100" value="' . $v('sofor_adi') . '" autocomplete="off">')
            . $al('Sevk adresi', '<input type="text" name="sevk_adresi" maxlength="500" value="' . $v('sevk_adresi') . '" autocomplete="off">', 'ft-alan--genis');
    }

    // Diğer bilgiler
    $dg = ''; $doluSay = 0;
    foreach (['odeme_sekli', 'teslim_yeri', 'beyanname_no', 'plaka', 'sofor_adi', 'sevk_adresi', 'aciklama', 'brut_kg', 'net_kg', 'kap_adedi', 'teslim_id'] as $a) if ((string)($o[$a] ?? '') !== '') $doluSay++;
    if ($faturaTurleri) {
        [$g, $d] = fatura_goster($faturaTurleri, $tur);
        $os = '<option value="">—</option>';
        foreach ($paket['odeme_sekli'] as $ad) $os .= '<option value="' . h($ad) . '"' . ((string)($o['odeme_sekli'] ?? '') === $ad ? ' selected' : '') . '>' . h($ad) . '</option>';
        $dg .= $al('Ödeme şekli', '<select name="odeme_sekli"' . $d . '>' . $os . '</select>', '', $g);
    }
    $teslimTur = array_values(array_intersect(['satis_ihracat', 'siparis'], $turler));
    if ($teslimTur) {
        [$g, $d] = fatura_goster($teslimTur, $tur);
        $dg .= $al('Teslim şekli', fatura_tanim_select('teslim_id', $paket['teslim'], (string)($o['teslim_id'] ?? ''), '—', $d), '', $g)
            . $al('Teslim yeri', '<input type="text" name="teslim_yeri" maxlength="150" value="' . $v('teslim_yeri') . '" autocomplete="off"' . $d . '>', '', $g);
    }
    if (in_array('satis_ihracat', $turler, true)) {
        [$g, $d] = fatura_goster(['satis_ihracat'], $tur);
        [$g2, $d2] = fatura_goster(['satis', 'satis_ihracat'], $tur);
        $dg .= $al('Beyanname no', '<input type="text" name="beyanname_no" maxlength="60" value="' . $v('beyanname_no') . '" autocomplete="off"' . $d . '>', '', $g)
            . $al('Plaka', '<input type="text" name="plaka" maxlength="60" value="' . $v('plaka') . '" autocomplete="off"' . $d2 . '>', '', $g2)
            . $al('Şoför', '<input type="text" name="sofor_adi" maxlength="100" value="' . $v('sofor_adi') . '" autocomplete="off"' . $d2 . '>', '', $g2)
            . $al('Sevk adresi', '<input type="text" name="sevk_adresi" maxlength="500" value="' . $v('sevk_adresi') . '" autocomplete="off"' . $d . '>', 'ft-alan--genis', $g);
    }
    $kgTur = array_values(array_intersect(['satis_ihracat', 'irsaliye'], $turler));
    if ($kgTur) {
        [$g, $d] = fatura_goster($kgTur, $tur);
        $dg .= $al('Brüt kg', '<input type="text" name="brut_kg" inputmode="numeric" value="' . $v('brut_kg') . '" autocomplete="off"' . $d . '>', '', $g)
            . $al('Net kg', '<input type="text" name="net_kg" inputmode="numeric" value="' . $v('net_kg') . '" autocomplete="off"' . $d . '>', '', $g)
            . $al('Kap adedi', '<input type="text" name="kap_adedi" inputmode="numeric" value="' . $v('kap_adedi') . '" autocomplete="off"' . $d . '>', '', $g);
    }
    $sab = '<option value="">Şablon ekle…</option>';
    foreach ($paket['sablon'] as $s) $sab .= '<option value="' . (int)$s['id'] . '" data-belge-turu="' . h((string)($s['belge_turu'] ?? '')) . '" data-metin="' . h((string)$s['metin']) . '">' . h((string)$s['ad']) . '</option>';
    $dg .= $al('Açıklama', '<textarea name="aciklama" rows="2" maxlength="1000">' . $v('aciklama') . '</textarea><select class="ft-sablon-sec" data-sablon aria-label="Açıklama şablonu ekle">' . $sab . '</select>', 'ft-alan--genis');

    // Satırlar
    $sifir = !empty($bilgi['kdv_sifir']);
    $c = ['kdv' => $kdvT['oranlar'], 'kdv_vs' => $kdvT['varsayilan'], 'sifir' => $sifir, 'tur' => $tur];
    $trs = ''; $no = 0;
    foreach (($o['satirlar'] ?? []) as $s) {
        $k = !empty($s['kart_id']) ? ($kartlar[(int)$s['kart_id']] ?? null) : null;
        $trs .= fatura_form_satiri($s, $k, ++$no, $c);
    }
    if ($no === 0) $trs = fatura_form_satiri([], null, 1, $c);
    $sablon = fatura_form_satiri([], null, 1, $c);

    // Kesintiler (toplam paneli içinde)
    $kes = '';
    $tevT = array_values(array_filter($turler, static fn($t) => !empty(fatura_tur_bilgi($t)['tevkifat_izin'])));
    if ($tevT) { [$g, $d] = fatura_goster($tevT, $tur); $kes .= $al('Tevkifat', fatura_tanim_select('tevkifat_id', $paket['tevkifat'], (string)($o['tevkifat_id'] ?? ''), 'Yok', $d), '', $g); }
    if (in_array('alis_mustahsil', $turler, true)) {
        [$g, $d] = fatura_goster(['alis_mustahsil'], $tur);
        $kes .= $al('GV stopajı', fatura_tanim_select('stopaj_id', $paket['stopaj'], (string)($o['stopaj_id'] ?? ''), 'Yok', $d, 'ad', !isset($o['kopya_no'])), '', $g)
            . $al('Bağ-Kur', fatura_tanim_select('bagkur_id', $paket['bagkur'], (string)($o['bagkur_id'] ?? ''), 'Yok', $d, 'ad', !isset($o['kopya_no'])), '', $g);
    }
    $sifirT = ['brut' => 0, 'isk' => 0, 'net' => 0, 'kdv' => 0, 'genel' => 0, 'tevkifat' => 0, 'stopaj' => 0, 'bagkur' => 0, 'cari' => 0, 'kdv_gruplari' => []];
    $toplam = fatura_toplam_html($sifirT, $tur, false, ['pb' => $pb, 'kesinti_html' => $kes]);

    // Tür segmenti
    $seg = '';
    if (count($turler) > 1) {
        foreach ($turler as $t) $seg .= '<label class="ft-tur-oge"><input type="radio" name="tur" value="' . h($t) . '" id="ftTur_' . h($t) . '"' . ($t === $tur ? ' checked' : '') . '><span>' . h(fatura_tur_kisa($t)) . '</span></label>';
        $seg = '<fieldset class="ft-tur-sec"><legend class="ft-sr">Belge türü</legend>' . $seg . '</fieldset>';
    } else {
        $seg = '<input type="hidden" name="tur" value="' . h($tur) . '">';
    }
    $kaynakTur = array_values(array_filter($turler, static fn($t) => isset(fatura_gecisler()[$t])));
    $kaynakBtn = '';
    if ($kaynakTur) { [$g] = fatura_goster($kaynakTur, $tur); $kaynakBtn = '<button type="button" class="btn ft-kaynak-btn" data-kaynak-getir title="Kaynak belgeden satır getir (Alt+K)"' . $g . '>Kaynaktan getir <span class="ft-kaynak-say" hidden></span></button>'; }
    $bilgiMetin = !empty($o['kaynak_bilgi']) ? (string)$o['kaynak_bilgi'] : (!empty($o['kopya_no']) ? (string)$o['kopya_no'] . ' belgesinden kopyalandı; tarih bugüne alındı.' : '');
    $onSart = '';
    if (!$cariler) $onSart .= '<div class="ft-uyari ft-uyari--ince" data-ft-on-sart="cari">Önce cari kartı oluşturun — <a href="cari_kartlari.php?yeni=1">Cari Kartları</a></div>';
    if (!$kartlar && $sinif === 'irsaliye') $onSart .= '<div class="ft-uyari ft-uyari--ince" data-ft-on-sart="kart">İrsaliye için stok kartı gerekir — <a href="stok_kartlari.php?yeni=1">Stok Kartları</a></div>';
    $kapaliBas = $bilgiMetin !== '';

    // İstemci verisi
    $yil = (int)date('Y'); $seriOn = [];
    foreach ($paket['seri'] as $s) foreach ([$yil - 1, $yil, $yil + 1] as $y) $seriOn[(string)$s['id']][(string)$y] = fatura_seri_durum($pdo, ['id' => $s['id'], 'kod' => $s['kod']], $y)['sonraki'];
    $turBilgi = [];
    foreach ($turler as $t) { $tb = fatura_tur_bilgi($t); $turBilgi[$t] = array_intersect_key($tb, array_flip(['etiket', 'duzenleyen', 'seri_grubu', 'cari', 'stok', 'stok_zorunlu', 'fiyat_zorunlu', 'kdv_sifir', 'ihracat', 'mustahsil', 'tevkifat_izin', 'iade'])); }
    $veri = ['sinif' => $sinif, 'sayfa' => $sayfa, 'tur' => $tur, 'turler' => $turBilgi, 'kartlar' => array_values($kartlar), 'cariler' => $cariler, 'bakiye' => (object)stok_bakiye_haritasi($pdo),
        'kdv' => $kdvT, 'tanimlar' => $paket, 'seri_onizleme' => (object)$seriOn, 'satir_max' => FATURA_SATIR_MAX, 'gecisler' => fatura_gecisler(), 'birimler' => array_keys(stok_birimler())];
    $datalist = '<datalist id="ftKartListe">';
    foreach ($kartlar as $k) $datalist .= '<option value="' . h($k['kod'] . ' — ' . $k['ad']) . '"></option>';
    $datalist .= '</datalist>';

    $ozet = '<span class="ft-bas-ozet" data-bas-ozet></span>';
    return '<section class="ft-detay ft-form-detay" id="ftDetay" data-form="' . h($sinif) . '" aria-label="' . h($M['yeni']) . '"><div class="ft-detay-kart">'
        . '<a class="ft-geri" href="' . h($sayfa) . '"><span aria-hidden="true">&#8592;</span> Liste</a>'
        . '<form method="post" action="fatura_kaydet.php" class="ft-form" id="ftForm" data-ft-fatura data-tur="' . h($tur) . '" data-sinif="' . h($sinif) . '" novalidate>'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="islem" value="belge_kaydet">'
        . '<input type="hidden" name="istek_anahtari" value="' . h(bin2hex(random_bytes(12))) . '"><input type="hidden" name="eksiye_izin" value="0"><input type="hidden" name="geri" value="' . h($sayfa . '?yeni=1') . '">'
        . '<header class="ft-form-bas"><h2 class="ft-unvan" data-form-baslik>' . h($M['yeni']) . '</h2>' . $seg . $kaynakBtn
        . '<button type="button" class="btn btn-sm ft-daralt" data-bas-daralt aria-expanded="' . ($kapaliBas ? 'false' : 'true') . '" aria-controls="ftBasIc" title="Başlığı daralt / aç (Alt+B)"><span class="ft-daralt-ac">Başlığı aç &#9662;</span><span class="ft-daralt-kapa">Başlığı daralt &#9652;</span></button></header>'
        . '<div class="ft-kaynak-bilgi" data-kaynak-bilgi' . ($bilgiMetin === '' ? ' hidden' : '') . '>' . h($bilgiMetin) . '</div>'
        . ($kaynakTur ? '<div class="ft-kaynak-ipucu" hidden><span data-kaynak-ipucu-metin></span> <button type="button" class="btn btn-sm" data-kaynak-getir>Getir</button></div>' : '')
        . $onSart
        . '<div class="flash flash-error ft-form-hata ft-hata-ozet" id="ftHataOzet" role="alert" hidden></div>'
        . '<div class="ft-eksik" id="ftEksik" role="alertdialog" aria-labelledby="ftEksikBaslik" aria-describedby="ftEksikAck" hidden><h3 id="ftEksikBaslik">Yetersiz stok</h3>'
        . '<p id="ftEksikAck">Bu belge stoğu eksiye düşürecek. Düzeltin ya da onaylayıp yine de kaydedin (stok fişine uyarı yazılır).</p>'
        . '<div class="table-wrap"><table class="data-table ft-tablo ft-eksik-liste"><thead><tr><th scope="col">Kart</th><th scope="col">Depo</th><th scope="col" class="ft-sayi">İstenen</th><th scope="col" class="ft-sayi">Mevcut</th><th scope="col" class="ft-sayi">Eksik</th></tr></thead><tbody></tbody></table></div>'
        . '<div class="ft-eksik-eylem"><button type="button" class="btn" data-eksik-duzelt>Düzelt</button><button type="button" class="btn ft-btn-uyari" data-eksik-kaydet>Yine de kaydet</button></div></div>'
        . '<div class="ft-form-govde">'
        . '<section class="ft-bas' . ($kapaliBas ? ' ft-bas--ozet' : '') . '" id="ftBas" aria-expanded="' . ($kapaliBas ? 'false' : 'true') . '" aria-label="Belge başlığı">'
        . '<div class="ft-bas-ust"><h3 class="ft-bolum-baslik">Başlık</h3>' . $ozet . '</div>'
        . '<div class="ft-bas-ic" id="ftBasIc"><div class="ft-bas-grid">' . $bas
        . '<details class="ft-diger" id="ftDiger"><summary>Diğer bilgiler <small data-diger-say>' . ($doluSay > 0 ? '(' . $doluSay . ' dolu)' : '') . '</small></summary><div class="ft-bas-grid">' . $dg . '</div></details></div></div></section>'
        . '<section class="ft-satirlar-bolum" aria-label="Satırlar"><div class="ft-satir-bas"><h3 class="ft-bolum-baslik">Satırlar <small data-satir-say>(' . max(1, $no) . ')</small></h3><small class="ft-ipucu" data-fiyat-ipucu>fiyat KDV hariç, <span data-pb-etiket>' . h($pb) . '</span></small></div>'
        . '<div class="ft-satirlar-kaydir"><table class="ft-ftablo"><caption class="ft-sr">Belge satırları</caption><thead><tr>'
        . '<th scope="col" class="ft-fs-no">#</th><th scope="col" class="ft-fs-kart">Ürün / stok kartı</th><th scope="col" class="ft-fs-miktar">Miktar</th><th scope="col" class="ft-fs-birim">Birim</th>'
        . '<th scope="col" class="ft-fs-fiyat">B.fiyat</th><th scope="col" class="ft-fs-isk">İsk %</th><th scope="col" class="ft-fs-kdv">KDV %</th><th scope="col" class="ft-fs-tutar">Tutar</th><th scope="col" class="ft-fs-menu"><span class="ft-sr">İşlem</span></th>'
        . '</tr></thead><tbody class="ft-fsatirlar">' . $trs . '</tbody></table></div>'
        . '<div class="ft-satir-alt"><button type="button" class="btn ft-satir-ekle" data-satir-ekle title="Yeni satır (Ctrl+↓)">＋ Satır ekle</button><small class="ft-ipucu ft-satir-max" data-satir-max hidden>En çok ' . FATURA_SATIR_MAX . ' satır</small></div></section>'
        . '<details class="ft-toplam-ac"><summary>Toplam ayrıntısı (KDV, kesintiler)</summary></details>'
        . $toplam
        . '</div>'
        . '<noscript><label class="ft-onay"><input type="checkbox" name="eksiye_izin" value="1"><span>Stok yetersizse yine de kaydet (eksiye düşürür)</span></label></noscript>'
        . '<div class="ft-eylem-bar"><div class="ft-ozet-bar" aria-live="polite"><span>Genel <b data-ozet="genel">0,00</b></span><span data-ozet-cari-kap>' . (!empty($bilgi['mustahsil']) ? 'Net ödenecek' : 'Cariye') . ' <b data-ozet="cari">0,00</b></span></div>'
        . '<span class="ft-klavye">Enter sonraki · Ctrl+S kaydet · Esc</span>'
        . '<a class="btn ft-vazgec" href="' . h($sayfa) . '">Vazgeç</a><button type="submit" class="btn btn-primary" data-kaydet>Kaydet</button></div>'
        . '</form>' . $datalist
        . '<template id="ftSatirSablon">' . $sablon . '</template>'
        . fatura_json_script('ftVeri', $veri)
        . '</div></section>';
}

// ── Modallar ────────────────────────────────────────────────────────────────

function fatura_modal_iptal(string $sayfa, ?array $b): string
{
    $id = $b !== null ? (int)$b['id'] : 0;
    return '<div class="pm-overlay" id="ftModalIptal" hidden><div class="pm-dialog ft-dialog ft-dialog--dar" role="dialog" aria-modal="true" aria-labelledby="ftIptalBaslik">'
        . '<div class="pm-header"><span class="ft-tutamac" aria-hidden="true"></span><h2 class="pm-title" id="ftIptalBaslik">Belgeyi iptal et — <span data-iptal-no>' . h((string)($b['belge_no'] ?? '')) . '</span></h2>'
        . '<button type="button" class="pm-close" data-ft-kapat aria-label="Kapat">&#10005;</button></div>'
        . '<form method="post" action="fatura_kaydet.php" data-ft-ajax data-ok="Belge iptal edildi" novalidate>'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="islem" value="belge_iptal"><input type="hidden" name="id" value="' . ($id ?: '') . '">'
        . '<input type="hidden" name="geri" value="' . h($sayfa . ($id ? '?id=' . $id : '')) . '">'
        . '<div class="pm-body"><div class="flash flash-error ft-form-hata" role="alert" hidden></div>'
        . '<div class="ft-uyari ft-uyari--tehlike" role="note"><span class="ft-uyari-ikon" aria-hidden="true">&#9888;</span><div><p data-iptal-uyari>Cari dekontu ve (varsa) stok fişi tersine yazılır. Bu işlem geri alınamaz; düzeltme için iptalden sonra <b>Kopyala</b> ile yeni belge girin.</p></div></div>'
        . '<label class="pm-label"><span>İptal nedeni <b class="ft-zorunlu" aria-hidden="true">*</b></span><textarea name="neden" rows="3" minlength="3" maxlength="500" required data-ilk-odak data-iptal-neden aria-describedby="ftIptalSayac"></textarea>'
        . '<span class="ft-ipucu" id="ftIptalSayac" data-sayac>En az 3 karakter</span></label></div>'
        . '<div class="pm-footer"><span class="ft-klavye">Esc kapatır</span><button type="button" class="btn" data-ft-kapat>Vazgeç</button>'
        . '<button type="submit" class="btn ft-btn-tehlike" data-iptal-onay>Belgeyi iptal et</button></div></form></div></div>';
}

function fatura_modal_kapat(string $sayfa): string
{
    return '<div class="pm-overlay" id="ftModalKapat" hidden><div class="pm-dialog ft-dialog ft-dialog--dar" role="dialog" aria-modal="true" aria-labelledby="ftKapatBaslik">'
        . '<div class="pm-header"><span class="ft-tutamac" aria-hidden="true"></span><h2 class="pm-title" id="ftKapatBaslik"><span data-kapat-baslik>Siparişi kapat</span> — <span data-kapat-no></span></h2>'
        . '<button type="button" class="pm-close" data-ft-kapat aria-label="Kapat">&#10005;</button></div>'
        . '<form method="post" action="fatura_kaydet.php" data-ft-ajax data-ok="Sipariş güncellendi" novalidate>'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="islem" value="siparis_kapat"><input type="hidden" name="id" value=""><input type="hidden" name="kapali" value="1">'
        . '<input type="hidden" name="geri" value="' . h($sayfa) . '">'
        . '<div class="pm-body"><div class="flash flash-error ft-form-hata" role="alert" hidden></div>'
        . '<p class="ft-not" data-kapat-ack>Kapalı siparişten irsaliye/fatura oluşturulamaz; mevcut bağlı belgeler etkilenmez.</p>'
        . '<label class="pm-label" data-kapat-neden-kap><span>Kapatma nedeni <b class="ft-zorunlu" aria-hidden="true">*</b></span><textarea name="neden" rows="3" maxlength="500" data-kapat-neden data-ilk-odak></textarea></label></div>'
        . '<div class="pm-footer"><button type="button" class="btn" data-ft-kapat>Vazgeç</button><button type="submit" class="btn btn-primary" data-kapat-onay>Onayla</button></div></form></div></div>';
}

/** Kaynak seç modalı (2 adım; içerik JS ile ?parca=kaynaklar / kaynak_satirlari'ndan). JS kapalı yol: detaydaki dönüşüm bağlantıları. */
function fatura_modal_kaynak(string $sayfa): string
{
    return '<div class="pm-overlay" id="ftModalKaynak" hidden><div class="pm-dialog ft-dialog ft-dialog--genis ft-dialog--uzun" role="dialog" aria-modal="true" aria-labelledby="ftKaynakBaslik">'
        . '<div class="pm-header"><span class="ft-tutamac" aria-hidden="true"></span><h2 class="pm-title" id="ftKaynakBaslik">Kaynaktan getir</h2><button type="button" class="pm-close" data-ft-kapat aria-label="Kapat">&#10005;</button></div>'
        . '<form method="get" action="' . h($sayfa) . '" data-ft-kaynak-form><input type="hidden" name="yeni" value="1"><input type="hidden" name="tur" value="">'
        . '<div class="pm-body"><ol class="ft-adimlar"><li data-adim-etiket="1" aria-current="step">1 Belgeler</li><li data-adim-etiket="2">2 Satırlar</li></ol>'
        . '<div class="flash flash-error ft-form-hata" role="alert" hidden></div>'
        . '<div data-kaynak-adim="1"><p class="ft-ipucu" data-kaynak-cari></p><ul class="ft-kaynak-liste" aria-label="Kaynak belgeler" data-kaynak-liste></ul></div>'
        . '<div data-kaynak-adim="2" hidden><div class="table-wrap"><table class="data-table ft-tablo ft-kaynak-tablo"><thead><tr><th scope="col"><span class="ft-sr">Seç</span></th><th scope="col">Ürün</th><th scope="col">Belge</th><th scope="col" class="ft-sayi">Kalan</th><th scope="col" class="ft-sayi">Aktarılacak</th></tr></thead><tbody data-kaynak-satirlar></tbody></table></div></div>'
        . '</div><div class="pm-footer"><span class="ft-ipucu" data-kaynak-ozet></span><button type="button" class="btn" data-ft-kapat>Vazgeç</button>'
        . '<button type="button" class="btn" data-kaynak-geri hidden>&lsaquo; Geri</button><button type="button" class="btn btn-primary" data-kaynak-ileri>Satırları göster &rsaquo;</button>'
        . '<button type="button" class="btn btn-primary" data-kaynak-ekle hidden>Forma ekle</button></div></form></div></div>';
}

/** Mobil satır alt sayfası (#ftSatirSheet) — içinde <form> YOK; girdiler name'siz data-sh (gönderilmez). */
function fatura_modal_satir(): string
{
    $i = static fn(string $sh, string $et, string $ek = ''): string => '<label class="pm-label ft-sh-alan"><span>' . $et . '</span><input type="text" data-sh="' . $sh . '" autocomplete="off"' . $ek . '></label>';
    return '<div class="pm-overlay" id="ftSatirSheet" hidden><div class="pm-dialog ft-dialog ft-dialog--uzun ft-sheet" role="dialog" aria-modal="true" aria-labelledby="ftSheetBaslik">'
        . '<div class="pm-header"><span class="ft-tutamac" aria-hidden="true"></span><h2 class="pm-title" id="ftSheetBaslik">Satır</h2><button type="button" class="pm-close" data-sh-vazgec aria-label="Kapat">&#10005;</button></div>'
        . '<div class="pm-body">'
        . '<label class="pm-label"><span>Ürün</span><input type="search" data-sh="ara" placeholder="Ara: kod, ad, barkod" autocomplete="off" spellcheck="false"></label>'
        . '<div class="ft-son-cipler" data-sh-son hidden></div>'
        . '<div class="ft-sonuc" data-sh-sonuc></div>'
        . '<p class="ft-ipucu" data-sh-secili></p>'
        . '<div class="ft-sh-ikili"><label class="pm-label ft-sh-alan"><span>Miktar</span><span class="ft-adim-girdi"><button type="button" class="btn" data-sh-adim="-1" aria-label="Azalt">&minus;</button><input type="text" data-sh="miktar" inputmode="decimal" autocomplete="off"><button type="button" class="btn" data-sh-adim="1" aria-label="Artır">+</button></span></label>'
        . '<label class="pm-label ft-sh-alan"><span>Birim</span><select data-sh="birim"></select></label></div>'
        . '<label class="pm-label ft-sh-alan"><span>Birim fiyat <small data-sh-pb></small> (KDV hariç)</span><input type="text" data-sh="fiyat" inputmode="decimal" autocomplete="off"><small class="ft-ipucu" data-sh-son-fiyat></small></label>'
        . '<div class="ft-sh-ikili">' . $i('iskonto', 'İsk %', ' inputmode="decimal"') . '<label class="pm-label ft-sh-alan"><span>KDV</span><select data-sh="kdv"></select></label></div>'
        . '<details class="ft-sx"><summary>Ayrıntı (lot, açıklama, GTİP…)</summary><div class="ft-sx-ic">' . $i('lot', 'Lot') . $i('aciklama', 'Satır açıklaması')
        . '<div data-sh-ihracat>' . $i('gtip', 'GTİP', ' inputmode="numeric"') . $i('brut_kg', 'Brüt kg', ' inputmode="numeric"') . $i('net_kg', 'Net kg', ' inputmode="numeric"') . $i('kap', 'Kap', ' inputmode="numeric"') . '</div></div></details>'
        . '<div class="pm-calc" data-sh-hesap aria-live="polite">Tutar —</div>'
        . '</div><div class="pm-footer ft-sh-alt"><button type="button" class="btn ft-tehlike" data-sh-sil>Sil</button><button type="button" class="btn" data-sh-vazgec>Vazgeç</button>'
        . '<button type="button" class="btn btn-primary" data-sh-uygula>Uygula</button><button type="button" class="btn" data-sh-uygula-yeni>Uygula + yeni satır</button></div></div></div>';
}

// ── Kısmi yanıtlar / sayfa gövdesi ──────────────────────────────────────────

/** ?parca=detay|cari_ozet|kaynaklar|kaynak_satirlari (render_header'dan ÖNCE). true → yanıt basıldı. */
function fatura_parca(PDO $pdo, string $sinif): bool
{
    $parca = (string)($_GET['parca'] ?? '');
    if ($parca === '') return false;
    $json = static function (array $v, int $kod = 200): void {
        if ($kod !== 200) http_response_code($kod);
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode($v, JSON_UNESCAPED_UNICODE);
    };
    $turler = fatura_sinif_turleri($sinif);
    if ($parca === 'detay') {
        $b = fatura_oku($pdo, max(0, (int)($_GET['id'] ?? 0)));
        if ($b === null || !in_array((string)$b['tur'], $turler, true) || !fatura_gorunur($b)) { http_response_code(404); echo '<div class="flash flash-error" role="alert">Belge bulunamadı.</div>'; return true; }
        echo fatura_detay_html($b, fatura_kaynak_satir_bilgileri($pdo, array_filter(array_column($b['satirlar'], 'kaynak_satir_id'))));
        return true;
    }
    if ($parca === 'cari_ozet') {
        $cid = max(0, (int)($_GET['cari_id'] ?? 0));
        if ($cid <= 0) { $json(['ok' => false, 'hata' => 'Cari seçin.'], 400); return true; }
        $o = fatura_cari_ozet($pdo, $cid, $sinif);
        $json(['ok' => true, 'bakiyeler' => (object)$o['bakiyeler'], 'son_fiyatlar' => (object)$o['son_fiyatlar'], 'acik_kaynak_say' => (int)$o['acik_kaynak_say']]);
        return true;
    }
    $hedef = (string)($_GET['tur'] ?? '');
    if (!in_array($hedef, $turler, true)) { $json(['ok' => false, 'hata' => 'Geçersiz tür.'], 400); return true; }
    if ($parca === 'kaynaklar') {
        $json(['ok' => true, 'adaylar' => fatura_kaynak_adaylari($pdo, $hedef, max(0, (int)($_GET['cari_id'] ?? 0)))]);
        return true;
    }
    if ($parca === 'kaynak_satirlari') {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))), static fn(int $x): bool => $x > 0));
        $json(['ok' => true, 'satirlar' => fatura_kaynak_satirlari($pdo, array_slice($ids, 0, 50), $hedef)]);
        return true;
    }
    http_response_code(400);
    return true;
}

/** ?kaynak= (1,2 ya da kaynak[]) → id listesi. */
function fatura_kaynak_idler(mixed $ham): array
{
    $l = is_array($ham) ? $ham : explode(',', is_scalar($ham) ? (string)$ham : '');
    return array_slice(array_values(array_unique(array_filter(array_map('intval', $l), static fn(int $x): bool => $x > 0))), 0, 50);
}

/**
 * 4 belge sayfasının ortak gövdesi. ?id= detay · ?yeni=1[&tur=] form · ?kopya=ID · ?yeni=1&tur=X&kaynak=ID[,ID] (ya da kaynak[]) dönüşüm formu.
 * Süzgeç: ?ara= &tur= &durum= &donem= (&bas= &bit=) &cari= &sd=
 */
function fatura_sayfa_govde(PDO $pdo, string $sinif): string
{
    $sayfa = fatura_sinif_sayfalari()[$sinif];
    $turler = fatura_sinif_turleri($sinif);
    $F = fatura_filtre($sinif);
    $cariler = fatura_cari_secenekleri($pdo);
    if ($F['cari'] === 0 && $F['cari_ara'] !== '') {
        $kd = cari_fold(trim(explode(' — ', $F['cari_ara'])[0]));
        foreach ($cariler as $c) if (cari_fold($c['kod']) === $kd || cari_fold($c['unvan']) === cari_fold($F['cari_ara'])) { $F['cari'] = $c['id']; break; }
    }
    $L = fatura_listesi($pdo, $sinif, fatura_liste_suzgec($F), 100);
    $turSay = [];
    if (count($turler) > 1) foreach ($turler as $t) $turSay[$t] = fatura_listesi($pdo, $sinif, ['tur' => $t] + fatura_liste_suzgec($F), 1)['toplam_satir'];
    $id = max(0, (int)($_GET['id'] ?? 0));
    $kopyaId = max(0, (int)($_GET['kopya'] ?? 0));
    $yeni = ($_GET['yeni'] ?? '') === '1';
    $b = null; $sag = ''; $form = false; $not = '';
    if ($id > 0) {
        $b = fatura_oku($pdo, $id);
        if ($b !== null && (!in_array((string)$b['tur'], $turler, true) || !fatura_gorunur($b))) { $not = !in_array((string)$b['tur'], $turler, true) ? 'Bu belge başka bir sayfaya ait.' : 'Bu belge aktif deponuzda görünmüyor.'; $b = null; }
        elseif ($b === null) $not = 'Belge bulunamadı.';
    }
    if ($b !== null) {
        $sag = fatura_detay_html($b, fatura_kaynak_satir_bilgileri($pdo, array_filter(array_column($b['satirlar'], 'kaynak_satir_id'))));
    } elseif (($yeni || $kopyaId > 0) && fatura_can('create')) {
        $o = ['tur' => (string)($_GET['tur'] ?? '')];
        if ($kopyaId > 0) {
            $k = fatura_oku($pdo, $kopyaId);
            if ($k !== null && in_array((string)$k['tur'], $turler, true) && fatura_gorunur($k)) $o = fatura_kopya_eslestir(fatura_kopya($k), fatura_tanimlar_paket($pdo));
            else $not = 'Kopyalanacak belge bulunamadı.';
        }
        $kids = fatura_kaynak_idler($_GET['kaynak'] ?? '');
        if ($kids && in_array($o['tur'], $turler, true)) {
            $sat = fatura_kaynak_satirlari($pdo, $kids, $o['tur']);
            $ilk = null; // ön doldurma yalnız GÖRÜNÜR (aktif depo) ilk kaynak belgeden
            foreach ($kids as $kid) { $kb = fatura_ham($pdo, $kid); if ($kb !== null && fatura_gorunur($kb)) { $ilk = $kb; break; } }
            if ($sat && $ilk !== null) {
                $nos = array_values(array_unique(array_column($sat, 'kaynak_belge_no')));
                $o += ['cari_id' => (int)$ilk['cari_id'], 'depo' => (string)$ilk['depo'], 'para_birimi' => (string)$ilk['para_birimi'], 'kur' => fatura_kur_metin($ilk['kur'] ?? null),
                    'satirlar' => $sat, 'kaynak_bilgi' => implode(', ', $nos) . ' belgesinden ' . count($sat) . ' satır getirildi (kalan miktarlar).'];
                if (fatura_tur_bilgi($o['tur'])['cari'] === null || (string)$ilk['para_birimi'] === 'TRY') $o['kur'] = (string)$ilk['para_birimi'] === 'TRY' ? '' : $o['kur'];
                if ((string)$ilk['tur'] === 'irsaliye') $o += ['plaka' => (string)($ilk['plaka'] ?? ''), 'sofor_adi' => (string)($ilk['sofor_adi'] ?? '')];
            } else $not = 'Kaynak belgede aktarılacak kalan satır yok (iptal, kapalı ya da tamamı karşılanmış).';
        }
        $sag = fatura_form_html($pdo, $sinif, $o, $cariler);
        $form = true;
    } else {
        $M = fatura_sinif_metin($sinif);
        $sag = '<section class="ft-detay" id="ftDetay" aria-label="Belge detayı"><div class="ft-detay-bos">' . h($not !== '' ? $not . ' Soldan bir belge seçin' : 'Soldan bir belge seçin ya da yeni belge oluşturun')
            . '<p><a class="btn btn-primary" href="' . h($sayfa . '?yeni=1') . '">' . h($M['yeni']) . '</a></p></div></section>';
    }
    $detayAcik = $b !== null || $form;
    $cdl = '<datalist id="ftCariListe">';
    foreach ($cariler as $c) $cdl .= '<option value="' . h($c['kod'] . ' — ' . $c['unvan']) . '"></option>';
    $cdl .= '</datalist>';
    $tur = $b !== null ? (string)$b['tur'] : ($form ? (in_array((string)($_GET['tur'] ?? ''), $turler, true) ? (string)$_GET['tur'] : ($kopyaId > 0 && isset($o['tur']) ? (string)$o['tur'] : $turler[0])) : ($F['tur'] !== '' ? $F['tur'] : $turler[0]));
    return '<div class="fatura ft--tek-ekran" id="fatura" data-sayfa="' . ($form ? 'form' : ($b !== null ? 'detay' : 'liste')) . '" data-sinif="' . h($sinif) . '" data-tur="' . h($tur) . '">' . fatura_ust_menu($sinif)
        . ($not !== '' && $detayAcik ? '<div class="flash flash-error" role="alert">' . h($not) . '</div>' : '')
        . '<div class="ft-ana' . ($detayAcik ? ' ft-ana--detay' : '') . ($form ? ' ft-ana--form' : '') . '">'
        . fatura_liste_kol_html($sinif, $F, $L, $turSay, $b !== null ? (int)$b['id'] : null, $cariler)
        . $sag . '</div>' . $cdl
        . (fatura_can('delete') ? fatura_modal_iptal($sayfa, $b) : '')
        . ($sinif === 'siparis' && fatura_can('edit') ? fatura_modal_kapat($sayfa) : '')
        . ($form ? fatura_modal_kaynak($sayfa) . fatura_modal_satir() : '')
        . '</div>';
}

// ── Fatura Tanımları (E4) ───────────────────────────────────────────────────

/** Sekmeler: ad → [başlık, tanım türleri]. */
function fatura_tanim_sekmeleri(): array
{
    return ['kdv' => ['KDV', ['kdv']], 'tevkifat' => ['Tevkifat', ['tevkifat']], 'kesinti' => ['Kesintiler', ['stopaj', 'bagkur']], 'istisna' => ['İstisna', ['istisna']],
        'seri' => ['Seriler', ['seri']], 'teslim' => ['Teslim şekilleri', ['teslim']], 'sablon' => ['Açıklama şablonları', ['sablon']], 'odeme' => ['Ödeme şekilleri', []]];
}

/** Tanım alan girdileri (ekle / düzenle ortak). $t: mevcut tanım ya da []. */
function fatura_tanim_alanlari(string $tur, array $t, bool $kilit): string
{
    $v = static fn(string $a): string => h((string)($t[$a] ?? ''));
    $al = static fn(string $et, string $ic, string $cls = ''): string => '<label class="pm-label ft-tanim-alan' . ($cls !== '' ? ' ' . $cls : '') . '"><span>' . $et . '</span>' . $ic . '</label>';
    $ad = $al('Ad', '<input type="text" name="ad" maxlength="150" value="' . $v('ad') . '" required autocomplete="off">', 'ft-tanim-alan--ad');
    $sira = $al('Sıra', '<input type="text" name="sira" inputmode="numeric" value="' . $v('sira') . '" autocomplete="off">', 'ft-tanim-alan--dar');
    $vs = '<label class="ft-onay ft-tanim-vs"><input type="checkbox" name="varsayilan" value="1"' . (!empty($t['varsayilan']) ? ' checked' : '') . ' data-varsayilan><span>Varsayılan</span></label>';
    $ro = $kilit ? ' readonly aria-readonly="true"' : '';
    return match ($tur) {
        'kdv' => $ad . $al('Oran %', '<input type="text" name="deger1" inputmode="numeric" value="' . $v('deger1') . '" required autocomplete="off">', 'ft-tanim-alan--dar') . $sira . $vs,
        'tevkifat' => $ad . $al('Pay (/10)', '<input type="text" name="deger1" inputmode="numeric" maxlength="1" value="' . $v('deger1') . '" required autocomplete="off"><input type="hidden" name="deger2" value="10">', 'ft-tanim-alan--dar')
            . $al('GİB kodu', '<input type="text" name="kod" maxlength="20" value="' . $v('kod') . '" autocomplete="off">', 'ft-tanim-alan--dar') . $sira,
        'stopaj', 'bagkur' => $ad . $al('Oran %', '<input type="text" name="deger1" inputmode="decimal" value="' . (isset($t['deger1']) && $t['deger1'] !== null ? h(fatura_bp_bicim((int)$t['deger1'])) : '') . '" required autocomplete="off">', 'ft-tanim-alan--dar') . $sira . $vs,
        'istisna' => $al('Kod', '<input type="text" name="kod" maxlength="20" value="' . $v('kod') . '" required autocomplete="off">', 'ft-tanim-alan--dar') . $ad . $sira . $vs,
        'seri' => $al('Önek', '<input type="text" name="kod" maxlength="3" pattern="[A-Z]{3}" value="' . $v('kod') . '" required autocomplete="off" class="ft-buyuk"' . $ro . '>', 'ft-tanim-alan--dar')
            . $al('Belge grubu', $kilit ? '<input type="hidden" name="belge_turu" value="' . $v('belge_turu') . '"><input type="text" value="' . h(fatura_seri_gruplari()[(string)($t['belge_turu'] ?? '')] ?? '') . '" readonly aria-readonly="true">'
                : '<select name="belge_turu" required>' . implode('', array_map(static fn($k, $a) => '<option value="' . h($k) . '"' . ((string)($t['belge_turu'] ?? '') === $k ? ' selected' : '') . '>' . h($a) . '</option>', array_keys(fatura_seri_gruplari()), fatura_seri_gruplari())) . '</select>')
            . $ad . $vs,
        'teslim' => $al('Kod', '<input type="text" name="kod" maxlength="3" value="' . $v('kod') . '" required autocomplete="off" class="ft-buyuk">', 'ft-tanim-alan--dar') . $ad . $sira,
        'sablon' => $ad . $al('Belge türü', '<select name="belge_turu"><option value="">Tümü</option>' . implode('', array_map(static fn($k, $b) => '<option value="' . h($k) . '"' . ((string)($t['belge_turu'] ?? '') === $k ? ' selected' : '') . '>' . h($b['etiket']) . '</option>', array_keys(fatura_turleri()), fatura_turleri())) . '</select>')
            . $al('Metin', '<textarea name="metin" rows="2" maxlength="500" required data-sayac-kaynak>' . $v('metin') . '</textarea>', 'ft-tanim-alan--genis'),
        default => $ad,
    };
}

/** Tanım satırı alt metni. */
function fatura_tanim_alt(string $tur, array $t, ?array $seriDurum): string
{
    $p = [];
    $p[] = match ($tur) {
        'kdv' => '%' . (int)$t['deger1'], 'tevkifat' => (int)$t['deger1'] . '/' . (int)$t['deger2'] . ' · kod ' . ((string)($t['kod'] ?? '') !== '' ? $t['kod'] : '—'),
        'stopaj', 'bagkur' => '%' . fatura_bp_bicim((int)$t['deger1']), 'istisna', 'teslim' => (string)$t['kod'],
        'seri' => (string)$t['kod'] . ' · ' . (fatura_seri_gruplari()[(string)$t['belge_turu']] ?? '') . ' · ' . (int)$t['kullanim'] . ' belge' . ($seriDurum !== null ? ' · ' . ($seriDurum['son_sira'] > 0 ? 'son …' . str_pad((string)$seriDurum['son_sira'], 3, '0', STR_PAD_LEFT) . ' · ' : '') . 'sonraki ' . $seriDurum['sonraki'] : ''),
        'sablon' => (((string)($t['belge_turu'] ?? '') !== '' ? (fatura_tur_bilgi((string)$t['belge_turu'])['etiket'] ?? '') : 'Tümü') . ' · ' . mb_substr((string)$t['metin'], 0, 80)),
        default => '',
    };
    if ((int)$t['varsayilan'] === 1) $p[] = '★ Varsayılan';
    $p[] = 'Sıra ' . (int)$t['sira'];
    return implode(' · ', array_filter($p, static fn($x) => $x !== ''));
}

function fatura_tanim_liste_html(PDO $pdo, string $tur, bool $yaz): string
{
    $geri = 'fatura_tanimlari.php?sekme=' . (in_array($tur, ['stopaj', 'bagkur'], true) ? 'kesinti' : $tur);
    $csrf = '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="geri" value="' . h($geri) . '">';
    $lst = '';
    $yil = (int)date('Y');
    foreach (fatura_tanim_listesi($pdo, $tur) as $t) {
        $id = (int)$t['id']; $aktif = (int)$t['aktif'] === 1;
        $kul = $tur === 'seri' && (int)$t['kullanim'] > 0;
        $sd = $tur === 'seri' ? fatura_seri_durum($pdo, $t, $yil) : null;
        $alt = fatura_tanim_alt($tur, $t, $sd);
        $eylem = '';
        if ($yaz) {
            $eylem = '<form method="post" action="fatura_kaydet.php" data-ft-ajax data-ok="Tanım güncellendi" class="ft-tanim-form-satir">' . $csrf . '<input type="hidden" name="islem" value="tanim_durum"><input type="hidden" name="id" value="' . $id . '">'
                . '<label class="ft-aktif"><input type="checkbox" role="switch" name="aktif" value="1" data-id="' . $id . '"' . ($aktif ? ' checked' : '') . ' aria-label="' . h((string)$t['ad']) . ' aktif"><span>Aktif</span></label>'
                . '<noscript><button type="submit" class="btn btn-sm">Kaydet</button></noscript></form>'
                . '<button type="button" class="btn btn-sm" data-tanim-duzen>Düzenle</button>'
                . ($kul ? '<span class="ft-rozet ft-rozet--kilit" title="Kullanılan seri silinemez; pasifleştirin">&#128274; kullanımda</span>'
                    : '<form method="post" action="fatura_kaydet.php" data-ft-ajax data-ok="Tanım silindi" data-onay="Bu tanım silinsin mi?" class="ft-tanim-form-satir">' . $csrf . '<input type="hidden" name="islem" value="tanim_sil"><input type="hidden" name="id" value="' . $id . '"><button type="submit" class="btn btn-sm ft-tehlike">Sil</button></form>');
        }
        $lst .= '<li class="ft-tanim-satir' . ($aktif ? '' : ' ft-tanim-satir--pasif') . '" data-id="' . $id . '"><span class="ft-tanim-ad">' . h((string)$t['ad']) . '<small>' . h($alt) . (!$aktif ? ' · Pasif' : '') . '</small></span><span class="ft-tanim-eylem">' . $eylem . '</span></li>';
        if ($yaz) $lst .= '<li class="ft-tanim-satir ft-tanim-satir--duzen" hidden><form method="post" action="fatura_kaydet.php" data-ft-ajax data-ok="Tanım güncellendi" class="ft-tanim-duzen-form">' . $csrf
            . '<input type="hidden" name="islem" value="tanim_kaydet"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="tur" value="' . h($tur) . '">'
            . '<div class="flash flash-error ft-form-hata" role="alert" hidden></div>' . fatura_tanim_alanlari($tur, $t, $kul)
            . '<span class="ft-tanim-eylem"><button type="button" class="btn btn-sm" data-tanim-vazgec>Vazgeç</button><button type="submit" class="btn btn-sm btn-primary">Kaydet</button></span></form></li>';
    }
    if ($lst === '') $lst = '<li class="ft-liste-bos">Bu türde tanım yok.</li>';
    $ekle = $yaz ? '<form method="post" action="fatura_kaydet.php" data-ft-ajax data-ok="Tanım eklendi" class="ft-tanim-ekle">' . $csrf . '<input type="hidden" name="islem" value="tanim_kaydet"><input type="hidden" name="tur" value="' . h($tur) . '">'
        . '<div class="flash flash-error ft-form-hata" role="alert" hidden></div>' . fatura_tanim_alanlari($tur, [], false) . '<button type="submit" class="btn btn-primary">+ Ekle</button></form>' : '';
    return $ekle . '<ul class="ft-tanim-liste" aria-label="' . h(fatura_tanim_turleri()[$tur]['etiket']) . '">' . $lst . '</ul>';
}

function fatura_tanimlari_govde(PDO $pdo): string
{
    $sek = fatura_tanim_sekmeleri();
    $aktif = (string)($_GET['sekme'] ?? 'kdv');
    if (!isset($sek[$aktif])) $aktif = 'kdv';
    $yaz = fatura_can('tanim');
    $nav = ''; $pan = '';
    $aciklama = ['kdv' => 'Satırda seçilebilen KDV oranları.', 'tevkifat' => 'Başlık bazında KDV tevkifatı (pay/10). GİB kodlarını mali müşavirle doldurun.', 'kesinti' => 'Müstahsil makbuzunda net tutardan düşülen kesintiler.',
        'istisna' => 'İhracat faturasında zorunlu KDV istisna kodu.', 'seri' => 'Bizim düzenlediğimiz belgelerin resmî numara serisi (3 harf + yıl + 9 hane). Kullanılan serinin önek ve grubu değişmez.',
        'teslim' => 'Incoterm teslim şekilleri.', 'sablon' => 'Formda "Şablon ekle" ile açıklamaya eklenen hazır metinler.', 'odeme' => 'Ödeme şekilleri Cari Tanımları\'ndan okunur (tek kaynak).'];
    foreach ($sek as $k => [$baslik, $tl]) {
        $ad = ucfirst($k);
        $say = 0; foreach ($tl as $t) $say += count(fatura_tanim_listesi($pdo, $t));
        if ($k === 'odeme') $say = count(fatura_tanimlar_paket($pdo)['odeme_sekli']);
        $nav .= '<a class="ft-tanim-nav-oge" role="tab" id="ftTab' . $ad . '" href="fatura_tanimlari.php?sekme=' . $k . '" aria-controls="ftPanel' . $ad . '" aria-selected="' . ($k === $aktif ? 'true' : 'false') . '">' . h($baslik) . ' <small>' . $say . '</small></a>';
        $ic = '';
        if ($k === 'odeme') {
            $li = '';
            foreach (fatura_tanimlar_paket($pdo)['odeme_sekli'] as $a) $li .= '<li class="ft-tanim-satir"><span class="ft-tanim-ad">' . h($a) . '</span></li>';
            $ic = '<ul class="ft-tanim-liste">' . ($li !== '' ? $li : '<li class="ft-liste-bos">Cari Tanımları\'nda ödeme şekli yok.</li>') . '</ul><p><a class="btn" href="cari_tanimlari.php?sekme=odeme_sekli">Cari Tanımları\'nda düzenle &#8599;</a></p>';
        } elseif ($k === 'kesinti') {
            $ic = '<h3 class="ft-alt-baslik">GV stopajı</h3>' . fatura_tanim_liste_html($pdo, 'stopaj', $yaz) . '<h3 class="ft-alt-baslik">Bağ-Kur</h3>' . fatura_tanim_liste_html($pdo, 'bagkur', $yaz);
        } else {
            $ic = fatura_tanim_liste_html($pdo, $tl[0], $yaz);
        }
        $uy = in_array($k, ['kdv', 'tevkifat', 'kesinti', 'istisna'], true) ? '<div class="ft-uyari ft-uyari--ince" role="note">&#9888; Oranları ve kodları mali müşavirinizle teyit edin (başlangıç değerleri doğrulanmadı).</div>' : '';
        $pan .= '<section class="ft-tanim-govde" role="tabpanel" id="ftPanel' . $ad . '" aria-labelledby="ftTab' . $ad . '"' . ($k === $aktif ? '' : ' hidden') . '><h2>' . h($baslik) . '</h2><p>' . h($aciklama[$k]) . '</p>' . $uy . $ic . '</section>';
    }
    return '<div class="fatura" id="fatura" data-sayfa="tanimlar">' . fatura_ust_menu('tanimlar')
        . '<div class="ft-tanim"><nav class="ft-tanim-nav" role="tablist" aria-label="Tanım türleri">' . $nav . '</nav><div class="ft-tanim-icerik">' . $pan
        . '<p class="ft-bilgi-kutu">Belgeler tanımların kopyasını taşır; tanımı değiştirmek ya da silmek eski belgeleri etkilemez.</p></div></div></div>';
}

// ── Yazdırma (E5) ───────────────────────────────────────────────────────────

/** A4 yazdırma görünümü. $b: fatura_oku sonucu; $firma: düzenleyen firma bilgisi (siparis_inv_ihracatci). */
function fatura_yazdir_html(array $b, array $firma, array $kaynakBilgi = []): string
{
    $tur = (string)$b['tur']; $bilgi = fatura_tur_bilgi($tur) ?? [];
    $pb = (string)$b['para_birimi']; $kur = fatura_kur_metin($b['kur'] ?? null);
    $baslik = match ($tur) { 'satis' => 'SATIŞ FATURASI', 'satis_ihracat' => 'İHRACAT FATURASI', 'satis_iade' => 'SATIŞ İADE FATURASI', 'alis' => 'ALIŞ FATURASI',
        'alis_mustahsil' => 'MÜSTAHSİL MAKBUZU', 'alis_iade' => 'ALIŞ İADE FATURASI', 'irsaliye' => 'SEVK İRSALİYESİ', 'siparis' => 'SİPARİŞ FİŞİ', default => 'BELGE' };
    $fiyatli = false; foreach ($b['satirlar'] as $s) if ($s['f4'] !== null) { $fiyatli = true; break; }
    $kdvSut = $fiyatli && empty($bilgi['kdv_sifir']);
    $ihr = !empty($bilgi['ihracat']);
    $p = static fn(int $k): string => h(cari_tutar_bicim($k, $pb, false));
    $sag = '';
    $sr = static function (string $e, string $v) use (&$sag): void { if ($v !== '') $sag .= '<tr><th>' . h($e) . '</th><td>' . h($v) . '</td></tr>'; };
    $sr($tur === 'siparis' ? 'Müşteri sipariş no' : ($tur === 'irsaliye' ? 'İrsaliye no' : 'Belge no'), (string)($b['fatura_no'] ?? ''));
    $sr('Tarih', fatura_tarih_tr((string)$b['tarih']));
    $sr('Vade', fatura_tarih_tr((string)($b['vade_tarihi'] ?? '')));
    $sr('Termin', fatura_tarih_tr((string)($b['termin_tarihi'] ?? '')));
    $sr('Sistem no', (string)$b['belge_no']);
    $sr('Para birimi', $pb . ($kur !== '' ? ' · kur ' . str_replace('.', ',', $kur) : ''));
    $taraf = '<div class="fatura-yz-kutu"><div class="fatura-yz-et">' . ($tur === 'alis_mustahsil' ? 'ÜRETİCİ' : (in_array($tur, ['alis', 'satis_iade'], true) ? 'SATICI' : 'SAYIN / ALICI')) . '</div>'
        . '<b>' . h((string)$b['cari_unvan']) . '</b>' . (!empty($b['cari_adres']) ? '<br>' . h((string)$b['cari_adres']) : '')
        . (!empty($b['cari_il']) || !empty($b['cari_ulke']) ? '<br>' . h(implode(' / ', array_filter([(string)($b['cari_il'] ?? ''), (string)($b['cari_ulke'] ?? '')]))) : '')
        . (!empty($b['cari_vergi_no']) ? '<br>' . h(trim((string)($b['cari_vergi_dairesi'] ?? '') . ' VD · ' . ($tur === 'alis_mustahsil' ? 'TCKN/VKN ' : 'VKN/TCKN ') . $b['cari_vergi_no'])) : '') . '</div>';
    $sevk = [];
    foreach (['teslim_sekli' => 'Teslim', 'teslim_yeri' => 'Teslim yeri', 'plaka' => 'Plaka', 'sofor_adi' => 'Şoför', 'sevk_adresi' => 'Sevk adresi', 'beyanname_no' => 'Beyanname'] as $k => $e) if (!empty($b[$k])) $sevk[] = h($e) . ': ' . h((string)$b[$k]);
    foreach (['brut_kg' => 'Brüt kg', 'net_kg' => 'Net kg'] as $k => $e) if ($b[$k] !== null && $b[$k] !== '') $sevk[] = $e . ': ' . h(stok_miktar_bicim(stok_milli($b[$k]), 'kg'));
    if ($b['kap_adedi'] !== null && $b['kap_adedi'] !== '') $sevk[] = 'Kap: ' . (int)$b['kap_adedi'];
    $sevkH = $sevk ? '<div class="fatura-yz-kutu"><div class="fatura-yz-et">SEVK / TESLİM</div>' . implode('<br>', $sevk) . '</div>' : '';
    $th = '<th>Sıra</th><th>Kod</th><th>Ürün / hizmet</th>' . ($ihr ? '<th>GTİP</th><th class="ft-sayi">Kap</th><th class="ft-sayi">Net kg</th>' : '') . ($tur === 'irsaliye' ? '<th>Lot</th>' : '')
        . '<th class="ft-sayi">Miktar</th><th>Birim</th>' . ($fiyatli ? '<th class="ft-sayi">B.fiyat</th><th class="ft-sayi">İsk%</th>' . ($kdvSut ? '<th class="ft-sayi">KDV%</th>' : '') . '<th class="ft-sayi">Tutar</th>' : '') . ($tur === 'irsaliye' ? '<th>Açıklama</th>' : '');
    $tr = ''; $topMil = [];
    foreach ($b['satirlar'] as $n => $s) {
        $bm = (string)$s['birim']; $topMil[$bm] = ($topMil[$bm] ?? 0) + (int)$s['milli'];
        $tr .= '<tr><td>' . ($n + 1) . '</td><td>' . h((string)($s['kart_kod'] ?? '')) . '</td><td>' . h((string)$s['urun_adi']) . ($tur !== 'irsaliye' && !empty($s['aciklama']) ? '<br><small>' . h((string)$s['aciklama']) . '</small>' : '') . '</td>'
            . ($ihr ? '<td>' . h((string)($s['gtip'] ?? '')) . '</td><td class="ft-sayi">' . ($s['kap_adedi'] !== null && $s['kap_adedi'] !== '' ? (int)$s['kap_adedi'] : '') . '</td><td class="ft-sayi">' . ($s['net_kg'] !== null && $s['net_kg'] !== '' ? h(stok_miktar_bicim(stok_milli($s['net_kg']), 'kg')) : '') . '</td>' : '')
            . ($tur === 'irsaliye' ? '<td>' . h((string)($s['lot'] ?? '')) . '</td>' : '')
            . '<td class="ft-sayi">' . h(stok_miktar_bicim((int)$s['milli'], $bm)) . '</td><td>' . h($bm) . '</td>'
            . ($fiyatli ? '<td class="ft-sayi">' . ($s['f4'] !== null ? h(stok_fiyat_bicim((int)$s['f4'])) : '') . '</td><td class="ft-sayi">' . ((int)$s['iskonto_bp'] > 0 ? h(fatura_bp_bicim((int)$s['iskonto_bp'])) : '') . '</td>'
                . ($kdvSut ? '<td class="ft-sayi">' . ($s['kdv_orani'] !== null ? (int)$s['kdv_orani'] : '') . '</td>' : '') . '<td class="ft-sayi">' . ($s['tutar_kurus'] !== null ? $p((int)$s['tutar_kurus']) : '') . '</td>' : '')
            . ($tur === 'irsaliye' ? '<td>' . h((string)($s['aciklama'] ?? '')) . '</td>' : '') . '</tr>';
    }
    $t = fatura_belge_toplam($b); $ke = fatura_kesinti_etiketleri($b);
    $top = '';
    $ts = static function (string $e, string $v, string $yz, string $cls = '') use (&$top): void { $top .= '<tr' . ($cls !== '' ? ' class="' . $cls . '"' : '') . ' data-yz="' . h($yz) . '"><th>' . h($e) . '</th><td class="ft-sayi">' . $v . '</td></tr>'; };
    if ($tur === 'alis_mustahsil') {
        $ts('Brüt mal bedeli', $p($t['net']), 'net');
        if ($t['stopaj'] > 0) $ts($ke['stopaj_etiket'] . ' (−)', $p($t['stopaj']), 'stopaj');
        if ($t['bagkur'] > 0) $ts($ke['bagkur_etiket'] . ' (−)', $p($t['bagkur']), 'bagkur');
        $ts('NET ÖDENECEK', $p($t['cari']) . ' ' . h($pb), 'cari', 'fatura-yz-buyuk');
    } elseif ($fiyatli) {
        $ts('Ara toplam', $p($t['brut']), 'brut');
        if ($t['isk'] > 0) $ts('İskonto', $p($t['isk']), 'isk');
        $ts('KDV matrahı', $p($t['net']), 'net');
        foreach ($t['kdv_gruplari'] as $o => $g) if ($kdvSut) $ts('KDV %' . (int)$o, $p((int)$g['kdv']), 'kdv-' . (int)$o);
        $ts('GENEL TOPLAM', $p($t['genel']) . ' ' . h($pb), 'genel', 'fatura-yz-buyuk');
        if ($t['tevkifat'] > 0) { $ts($ke['tevkifat_etiket'] . ' (−)', $p($t['tevkifat']), 'tevkifat'); $ts('ÖDENECEK', $p($t['cari']) . ' ' . h($pb), 'cari', 'fatura-yz-buyuk'); }
        elseif (($bilgi['cari'] ?? null) !== null) $top .= '<tr class="ft-sr" data-yz="cari"><th>Ödenecek</th><td>' . $p($t['cari']) . '</td></tr>';
        if ($pb !== 'TRY' && $kur !== '') $ts('TL karşılığı (kur ' . str_replace('.', ',', $kur) . ')', h(cari_tutar_bicim((int)round($t['genel'] * (float)$kur), 'TRY')), 'tl');
    }
    if (!$fiyatli || $tur === 'irsaliye') foreach ($topMil as $bm => $m) $ts('Toplam miktar', h(fatura_miktar($m, $bm)), 'miktar-' . $bm);
    $not = [];
    if (!empty($b['aciklama'])) $not[] = '<b>Açıklama:</b> ' . nl2br(h((string)$b['aciklama']));
    if (!empty($b['istisna_kod'])) $not[] = '<b>İstisna:</b> ' . h($b['istisna_kod'] . ' — ' . ($b['istisna_ad'] ?? ''));
    if (!empty($b['odeme_sekli'])) $not[] = '<b>Ödeme:</b> ' . h((string)$b['odeme_sekli']) . (!empty($b['vade_tarihi']) ? ' · Vade ' . h(fatura_tarih_tr((string)$b['vade_tarihi'])) : '');
    if (!empty($bilgi['iade']) && $b['kaynaklar']) $not[] = '<b>İade edilen belge:</b> ' . h(implode(', ', array_map(static fn($x) => (string)($x['fatura_no'] ?: $x['belge_no']), $b['kaynaklar'])));
    elseif ($b['kaynaklar']) $not[] = '<b>Kaynak belge:</b> ' . h(implode(', ', array_column($b['kaynaklar'], 'belge_no')));
    $imza = in_array($tur, ['irsaliye', 'alis_mustahsil'], true)
        ? '<div class="fatura-yz-imza"><div>' . ($tur === 'irsaliye' ? 'Teslim eden' : 'Üretici') . '</div><div>' . ($tur === 'irsaliye' ? 'Teslim alan' : 'Alıcı') . '</div></div>' : '';
    $iptal = $b['durum'] === 'iptal' ? '<div class="fatura-yz-filigran" aria-hidden="true">İPTAL</div><p class="fatura-yz-iptal"><b>İPTAL EDİLDİ:</b> ' . h((string)$b['iptal_neden']) . '</p>' : '';
    $firmaH = '<div class="fatura-yz-firma"><b>' . h((string)($firma['unvan'] ?? '')) . '</b><br>' . h((string)($firma['adres'] ?? '')) . '<br>' . h((string)($firma['ilce_il'] ?? ''))
        . (!empty($firma['vergi_no']) ? '<br>' . h((string)($firma['vergi_dairesi'] ?? '')) . ' VD · VKN ' . h((string)$firma['vergi_no']) : '') . '</div>';
    return '<div class="fatura-yz">' . $iptal
        . '<header class="fatura-yz-ust">' . $firmaH . '<div class="fatura-yz-belge"><div class="fatura-yz-tur">' . h($baslik) . '</div><table>' . $sag . '</table></div></header>'
        . '<div class="fatura-yz-taraf">' . $taraf . $sevkH . '</div>'
        . '<table class="fatura-yz-satir"><thead><tr>' . $th . '</tr></thead><tbody>' . $tr . '</tbody></table>'
        . '<div class="fatura-yz-alt-blok"><div class="fatura-yz-not">' . implode('<br>', $not) . '</div>'
        . ($top !== '' ? '<table class="fatura-yz-toplam">' . $top . '</table>' : '') . '</div>'
        . $imza
        . '<footer class="fatura-yz-alt">Bu çıktı bilgi amaçlıdır, resmî e-belge değildir.</footer></div>';
}
