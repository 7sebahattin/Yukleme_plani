<?php
// =========================================================
// config/cari_ui.php — Cari İşlemler render yardımcıları (include-only; yalnız fonksiyon bildirir)
//
// Sayfalar (cari_kartlari/fisler/dekontlar/tanimlari.php) ve cari_ekstre.php veriyi cari_core.php'den okur,
// işaretlemeyi BURADAN alır. İşaretleme tasarım şartnamesiyle (maket_*.html) birebir; sınıf öneki `cari-`,
// ID/data-* kancaları `cr*` (assets/cari.js sözleşmesi). Burada SORGU YAZILMAZ (yalnız cari_core okuyucuları çağrılır),
// hiçbir yazma yoktur. HTML'e giren HER dinamik değer h() ile kaçışlanır.
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

// ── Varlıklar / ortak parçalar ──────────────────────────────────────────────

function cari_assets(): void
{
    echo '<link rel="stylesheet" href="assets/cari.css?v=' . (int)@filemtime(dirname(__DIR__) . '/assets/cari.css') . '">' . "\n";
}

function cari_scripts(): void
{
    echo '<script src="assets/cari.js?v=' . (int)@filemtime(dirname(__DIR__) . '/assets/cari.js') . '"></script>' . "\n";
}

/** Üst sekme şeridi. $aktif: kartlar|fisler|dekontlar|tanimlar */
function cari_ust_menu(string $aktif): string
{
    $s = ['kartlar' => ['cari_kartlari.php', 'Kartlar'], 'fisler' => ['cari_fisler.php', 'Fişler'], 'dekontlar' => ['cari_dekontlar.php', 'Dekontlar'], 'tanimlar' => ['cari_tanimlari.php', 'Tanımlar']];
    $o = '<nav class="cari-sekmeler" aria-label="Cari İşlemler">';
    foreach ($s as $k => [$url, $ad]) {
        $o .= '<a class="cari-sekme" href="' . h($url) . '"' . ($k === $aktif ? ' aria-current="page"' : '') . '>' . h($ad) . '</a>';
    }
    return $o . '</nav>';
}

/** Şema yok kartı (sekme şeridi YOK). */
function cari_sema_uyari(): string
{
    return '<div class="cari"><div class="cari-uyari" role="alert"><span class="cari-uyari-ikon" aria-hidden="true">&#9888;</span><div>'
        . '<h2>Cari tabloları kurulmamış</h2>'
        . '<p>Cari İşlemler modülünün veritabanı tabloları bu sunucuda henüz oluşturulmadı. Yönetici: Şema Migrasyon sayfasındaki <b>Cari İşlemler</b> kartını çalıştırın.</p>'
        . '<a class="btn btn-primary" href="migrate.php#cari">Şema Migrasyon &rsaquo; Cari İşlemler</a></div></div></div>';
}

function cari_bos(string $baslik, string $ack, string $eylem = '', string $etiket = 'h2'): string
{
    return '<div class="cari-bos"><' . $etiket . '>' . h($baslik) . '</' . $etiket . '><p>' . h($ack) . '</p>'
        . ($eylem !== '' ? '<div class="cari-bos-eylem">' . $eylem . '</div>' : '') . '</div>';
}

function cari_pb_ad(string $pb): string
{
    return ['TRY' => 'Türk Lirası', 'USD' => 'ABD Doları', 'EUR' => 'Euro'][$pb] ?? $pb;
}

/** Bakiye yön harfi: b (borçlu) · a (alacaklı) · d (denk). */
function cari_yon_h(int $kurus): string { return $kurus > 0 ? 'b' : ($kurus < 0 ? 'a' : 'd'); }

/** Bakiye çipi: mutlak tutar + yön harfi; aria-label tam metin (renk tek taşıyıcı değil). */
function cari_bk(int $kurus, string $pb): string
{
    $x = cari_yon_h($kurus);
    $e = cari_bakiye_etiketi($kurus);
    return '<span class="cari-bk cari-bk--' . $x . '" aria-label="' . h(cari_tutar_bicim(abs($kurus), $pb, false) . ' ' . cari_pb_ad($pb) . ', ' . $e['metin']) . '">'
        . '<span>' . h(cari_tutar_bicim(abs($kurus), $pb)) . '</span><span class="cari-bk-yon" aria-hidden="true">' . strtoupper($x) . '</span></span>';
}

/** Ekstre yön hücresi: 40.000,00 + küçük B/A/D. */
function cari_yon_span(int $kurus): string
{
    $x = cari_yon_h($kurus);
    return '<span class="cari-yon-' . $x . '">' . h(cari_tutar_bicim(abs($kurus), 'TRY', false)) . '<small>' . strtoupper($x) . '</small></span>';
}

function cari_tarih_tr(string $d): string
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) ? $m[3] . '.' . $m[2] . '.' . $m[1] : $d;
}

/** TR doğru küçük harf (İ/I). */
function cari_kucuk(string $s): string { return mb_strtolower(strtr($s, ['İ' => 'i', 'I' => 'ı']), 'UTF-8'); }

function cari_slug(string $s): string { return (string)preg_replace('/\s+/u', '-', trim(cari_kucuk($s))); }

/** Tür etiketi (liste/rozet): açılış "Açılış / Devir". */
function cari_tur_kisa(string $t): string
{
    return ['tahsilat' => 'Tahsilat', 'odeme' => 'Ödeme', 'acilis' => 'Açılış / Devir', 'borc_dekont' => 'Borç Dekontu', 'alacak_dekont' => 'Alacak Dekontu', 'virman' => 'Virman'][$t] ?? $t;
}

function cari_json_attr(mixed $v): string
{
    return h((string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** <script type=application/json> içine güvenli JSON (</script> kaçışı). */
function cari_json_script(string $id, mixed $v): string
{
    return '<script type="application/json" id="' . h($id) . '">' . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
}

// ── Dönem / filtre durumu ───────────────────────────────────────────────────

/** @return array{0:string,1:string} [bas, bit] ; tumu = ['','']. */
function cari_donem_araligi(string $d): array
{
    $y = date('Y');
    return match ($d) {
        'ay'    => [date('Y-m-01'), date('Y-m-t')],
        'gecen' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
        'yil'   => [$y . '-01-01', $y . '-12-31'],
        default => ['', ''],
    };
}

function cari_donem_tani(string $bas, string $bit): string
{
    foreach (['ay', 'gecen', 'yil'] as $d) if (cari_donem_araligi($d) === [$bas, $bit]) return $d;
    return $bas === '' && $bit === '' ? 'tumu' : 'ozel';
}

function cari_tarih_ya_bos(mixed $v): string
{
    $s = trim((string)$v);
    return ($s !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && checkdate((int)substr($s, 5, 2), (int)substr($s, 8, 2), (int)substr($s, 0, 4))) ? $s : '';
}

/**
 * Kart ekstresi filtre durumu. `bas`/`bit` anahtarı HİÇ gelmediyse "Bu yıl"; boş gelirse "Tümü".
 * @return array{bas:string,bit:string,pb:string,tur:string,iptal:bool,pbler:list<string>,donem:string}
 */
function cari_ekstre_durum(PDO $pdo, array $kart, array $g): array
{
    if (!array_key_exists('bas', $g) && !array_key_exists('bit', $g)) { [$bas, $bit] = cari_donem_araligi('yil'); }
    else { $bas = cari_tarih_ya_bos($g['bas'] ?? ''); $bit = cari_tarih_ya_bos($g['bit'] ?? ''); }
    $pbler = cari_kart_pbler($pdo, (int)$kart['id']);
    $pb = (string)($g['pb'] ?? '');
    if (!in_array($pb, $pbler, true)) $pb = in_array((string)$kart['para_birimi'], $pbler, true) ? (string)$kart['para_birimi'] : ($pbler[0] ?? '');
    $tur = (string)($g['tur'] ?? '');
    if (!isset(cari_hareket_turleri()[$tur])) $tur = '';
    return ['bas' => $bas, 'bit' => $bit, 'pb' => $pb, 'tur' => $tur, 'iptal' => !empty($g['iptal']), 'pbler' => $pbler, 'donem' => cari_donem_tani($bas, $bit)];
}

// ── Kartlar: sol liste ──────────────────────────────────────────────────────

/** İptal düğmesi: satır özeti + "iptal et ve düzelt" için eski değerler (data-kopya JSON). $r: normalize hareket satırı. */
function cari_iptal_dugme(array $r): string
{
    $pb = (string)$r['pb'];
    $kopya = [
        'tur' => $r['tur'], 'cari_id' => $r['cari_id'], 'tarih' => $r['tarih'], 'tutar' => cari_tutar_bicim((int)$r['kurus'], $pb, false), 'para_birimi' => $pb,
        'kur' => $r['kur'] ?? null, 'yon' => $r['yon'], 'evrak_no' => $r['evrak_no'] ?? '', 'odeme_sekli' => $r['odeme_sekli'] ?? '', 'aciklama' => $r['aciklama'] ?? '',
    ];
    if ($r['tur'] === 'virman') {
        $kopya['kaynak_id'] = $r['yon'] === 'A' ? $r['cari_id'] : ($r['bagli_cari_id'] ?? null);
        $kopya['hedef_id']  = $r['yon'] === 'A' ? ($r['bagli_cari_id'] ?? null) : $r['cari_id'];
    }
    return '<button type="button" class="cari-iptal-dugme" data-cr-modal="iptal" data-id="' . (int)$r['id'] . '" data-belge="' . h($r['belge_no']) . '"'
        . ' data-tarih="' . h(cari_tarih_tr((string)$r['tarih'])) . '" data-tur-etiket="' . h(cari_tur_kisa((string)$r['tur'])) . '" data-cari="' . h($r['cari_unvan'] ?? '') . '"'
        . ' data-tutar="' . h(cari_tutar_bicim((int)$r['kurus'], $pb)) . '" data-aciklama="' . h($r['aciklama'] ?? '') . '" data-virman="' . ($r['tur'] === 'virman' ? '1' : '0') . '"'
        . ' data-kopya="' . cari_json_attr($kopya) . '">İptal</button>';
}

/** Hareket satırı (DB) → iptal düğmesi/ekstre için normalize dizi. */
function cari_satir_norm(array $h, string $cariUnvan = ''): array
{
    return [
        'id' => (int)$h['id'], 'tarih' => (string)$h['tarih'], 'tur' => (string)$h['tur'], 'yon' => (string)$h['yon'], 'kurus' => cari_kurus($h['tutar']),
        'pb' => (string)$h['para_birimi'], 'kur' => $h['kur'] ?? null, 'belge_no' => (string)$h['belge_no'], 'evrak_no' => (string)($h['evrak_no'] ?? ''),
        'odeme_sekli' => (string)($h['odeme_sekli'] ?? ''), 'aciklama' => (string)$h['aciklama'], 'cari_id' => (int)$h['cari_id'],
        'cari_unvan' => $cariUnvan !== '' ? $cariUnvan : (string)($h['unvan'] ?? ''),
        'bagli_cari_id' => isset($h['bagli_cari_id']) && $h['bagli_cari_id'] !== null ? (int)$h['bagli_cari_id'] : null, 'bagli_cari_unvan' => (string)($h['bagli_cari_unvan'] ?? ''),
        'durum' => (string)$h['durum'], 'iptal_neden' => (string)($h['iptal_neden'] ?? ''), 'kaynak_modul' => $h['kaynak_modul'] ?? null,
    ];
}

function cari_kart_satiri(array $k, bool $secili): string
{
    $tipler = cari_tipler();
    $bak = $k['bakiyeler'] ?? [];
    $harfler = [];
    foreach ($bak as $b) $harfler[cari_yon_h((int)$b)] = true;
    if (!$harfler) $harfler['d'] = true;
    $hs = implode(' ', array_keys(array_intersect_key(['a' => 1, 'b' => 1, 'd' => 1], $harfler)));
    $grup = (string)($k['grup_adi'] ?? '');
    $ara = cari_kucuk(implode(' ', array_filter([(string)$k['unvan'], (string)$k['kod'], $grup, (string)($k['telefon'] ?? ''), (string)($k['vergi_no'] ?? ''), $tipler[$k['tip']] ?? ''], static fn($x) => $x !== '')));
    $pasif = (int)$k['aktif'] !== 1;
    $alt = implode(' · ', array_filter([(string)$k['kod'], $tipler[$k['tip']] ?? '', $grup], static fn($x) => $x !== ''));
    $bak = cari_pb_sirala($bak);
    $chip = '';
    foreach ($bak as $pb => $b) $chip .= cari_bk((int)$b, (string)$pb);
    if ($chip === '') $chip = cari_bk(0, (string)$k['para_birimi']);
    if ($pasif) $chip .= '<span class="cari-rozet cari-rozet--pasif">Pasif</span>';
    return '<li><a class="cari-kart' . ($pasif ? ' cari-kart--pasif' : '') . '" href="cari_kartlari.php?id=' . (int)$k['id'] . '" data-id="' . (int)$k['id'] . '"'
        . ' data-ara="' . h($ara) . '" data-bakiye="' . h($hs) . '" data-tip="' . h($k['tip']) . '" data-grup="' . h($grup !== '' ? cari_slug($grup) : '') . '" data-pasif="' . ($pasif ? '1' : '0') . '"'
        . ($secili ? ' aria-current="true"' : '') . '>'
        . '<span class="cari-kart-ad">' . h($k['unvan']) . '</span><span class="cari-kart-alt">' . h($alt) . '</span><span class="cari-kart-bk">' . $chip . '</span></a></li>';
}

/**
 * Sol sütun: arama + çipler + özet + liste.
 * @param list<array> $kartlar (bakiyeler dolu)  @param array $genel cari_genel_ozet  @param list<array> $gruplar aktif grup tanımları
 * @param array{q:string,tip:string,grup:string,bakiye:string,durum:string,kesildi:bool} $f
 */
function cari_liste_kol_html(array $kartlar, array $genel, array $gruplar, array $f, ?int $seciliId, int $genelToplam): string
{
    $say = ['tumu' => 0, 'b' => 0, 'a' => 0, 'pasif' => 0];
    foreach ($kartlar as $k) {
        if ((int)$k['aktif'] !== 1) { $say['pasif']++; continue; }
        $say['tumu']++;
        foreach ($k['bakiyeler'] ?? [] as $b) { if ($b > 0) { $say['b']++; break; } }
        foreach ($k['bakiyeler'] ?? [] as $b) { if ($b < 0) { $say['a']++; break; } }
    }
    $bas = $f['durum'] === 'pasif' ? 'pasif' : ($f['bakiye'] === 'borclu' ? 'b' : ($f['bakiye'] === 'alacakli' ? 'a' : ''));
    $cip = '';
    foreach (['' => ['Tümü', $say['tumu']], 'b' => ['Borçlu', $say['b']], 'a' => ['Alacaklı', $say['a']], 'pasif' => ['Pasif', $say['pasif']]] as $d => [$ad, $n]) {
        $cip .= '<button type="button" class="cari-cip" data-filtre="bakiye" data-deger="' . h($d) . '" aria-pressed="' . ($d === $bas ? 'true' : 'false') . '">' . h($ad) . ' <span class="cari-cip-say">' . $n . '</span></button>';
    }
    $tipOpt = '<option value="">Tüm tipler</option>';
    foreach (cari_tipler() as $v => $ad) $tipOpt .= '<option value="' . h($v) . '"' . ($f['tip'] === $v ? ' selected' : '') . '>' . h($ad) . '</option>';
    $grpOpt = '<option value="">Tüm gruplar</option>';
    foreach ($gruplar as $g) { $sl = cari_slug((string)$g['ad']); $grpOpt .= '<option value="' . h($sl) . '"' . ($f['grup'] === $sl ? ' selected' : '') . '>' . h($g['ad']) . '</option>'; }

    $ozet = '';
    foreach (['borclu_toplam' => ['Toplam borçlu', 1], 'alacakli_toplam' => ['Toplam alacaklı', -1]] as $alan => [$ad, $isr]) {
        $c = '';
        $genel = cari_pb_sirala($genel);
        foreach ($genel as $pb => $o) if ((int)$o[$alan] > 0) $c .= cari_bk($isr * (int)$o[$alan], (string)$pb);
        $ozet .= '<div class="cari-ozet-kutu"><b>' . h($ad) . '</b>' . ($c !== '' ? $c : '<span class="cari-ozet-not">—</span>') . '</div>';
    }

    $liste = '';
    foreach ($kartlar as $k) $liste .= cari_kart_satiri($k, $seciliId !== null && (int)$k['id'] === $seciliId);
    $bos = '';
    if ($genelToplam === 0) {
        $bos = cari_bos('Henüz cari yok', 'İlk cariyi ekleyin ya da Tanımlar’daki firma ve tedarikçileri tek seferde içe aktarın.',
            '<button type="button" class="btn btn-primary" data-cr-modal="kart">İlk cariyi ekle</button><a class="btn" href="cari_tanimlari.php#ice-aktar">Tanımlardan içe aktar</a>');
    }
    $filtreli = $f['q'] !== '' || $f['tip'] !== '' || $f['grup'] !== '' || $f['bakiye'] !== '' || $f['durum'] !== '';
    $yok = $genelToplam > 0 && !$kartlar;

    return '<div class="cari-liste-kol" id="crListeKol"><div class="cari-liste-ust">'
        . '<div class="cari-arama-satir"><div class="cari-arama"><svg class="cari-arama-ikon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>'
        . '<label class="cari-sr" for="crArama">Cari ara: ünvan, kod, vergi no</label>'
        . '<input type="search" id="crArama" placeholder="Ünvan, kod veya vergi no ara" autocomplete="off" aria-controls="crListe" value="' . h($f['q']) . '"><kbd class="cari-arama-ipucu" aria-hidden="true">/</kbd></div>'
        . '<button type="button" class="btn" data-cr-modal="kart" title="Yeni cari (N)">+ Yeni</button></div>'
        . '<div class="cari-cipler" role="group" aria-label="Bakiye durumu">' . $cip . '</div>'
        . '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:8px">'
        . '<select data-filtre="tip" aria-label="Cari tipi" style="min-height:44px">' . $tipOpt . '</select>'
        . '<select data-filtre="grup" aria-label="Cari grubu" style="min-height:44px">' . $grpOpt . '</select></div></div>'
        . '<div class="cari-ozet" aria-label="Toplam bakiyeler (aktif kartlar, para birimi ayrı)">' . $ozet . '</div>'
        . $bos
        . '<ul class="cari-liste" id="crListe" aria-label="Cari listesi">' . $liste . '</ul>'
        . '<p class="cari-liste-bos" id="crSonucYok"' . ($yok && $filtreli ? '' : ' hidden') . '>Eşleşen cari yok. <a href="cari_kartlari.php">Filtreleri temizle</a></p>'
        . '<div class="cari-liste-alt"><span id="crSayi">' . $say['tumu'] . ' cari</span><span>B = Borçlu · A = Alacaklı'
        . ($f['kesildi'] ? ' · İlk 2000 kart gösteriliyor, aramayı daraltın' : '') . '</span></div></div>';
}

// ── Kartlar: detay ──────────────────────────────────────────────────────────

/** Düzenleme modalı için kart alanları (#crKartVeri + parca=kart JSON). */
function cari_kart_json(array $k): array
{
    $a = [];
    foreach (['id', 'kod', 'unvan', 'tip', 'grup_id', 'vergi_dairesi', 'vergi_no', 'yetkili', 'telefon', 'eposta', 'adres', 'il', 'ulke', 'iban', 'para_birimi', 'vade_gun', 'notlar', 'aktif'] as $f) {
        $a[$f] = $k[$f] ?? null;
    }
    $a['id'] = (int)$k['id']; $a['aktif'] = (int)$k['aktif'];
    $a['grup_id'] = $k['grup_id'] !== null && $k['grup_id'] !== '' ? (int)$k['grup_id'] : null;
    $a['kayit'] = 'Oluşturma: ' . cari_tarih_tr((string)substr((string)($k['created_at'] ?? ''), 0, 10)) . ' ' . substr((string)($k['created_at'] ?? ''), 11, 5)
        . ($k['md_id'] !== null && $k['md_id'] !== '' ? ' · Tanımlardan içe aktarıldı (#' . (int)$k['md_id'] . ')' : '');
    return $a;
}

function cari_bilgi_oge(string $dt, string $dd, bool $tam = false): string
{
    return '<div' . ($tam ? ' style="grid-column:1/-1"' : '') . '><dt>' . h($dt) . '</dt><dd>' . $dd . '</dd></div>';
}

/** Ekstre paneli (#crPanelEkstre). $E: cari_ekstre_durum() çıktısı. */
function cari_ekstre_panel_html(PDO $pdo, array $kart, array $E): string
{
    $id = (int)$kart['id'];
    $pasif = (int)$kart['aktif'] !== 1;
    $dis = $pasif ? ' disabled' : '';
    $oPanel = '<div class="cari-panel" id="crPanelEkstre" role="tabpanel" aria-labelledby="crSekmeEkstre"';
    if (!$E['pbler']) {
        return $oPanel . '>' . '<div class="cari-bos" style="margin-top:12px"><h3>Bu carinin henüz hareketi yok</h3><p>İlk tahsilatı ya da ödemeyi ekleyerek ekstreyi başlatın.</p>'
            . '<div class="cari-bos-eylem"><button type="button" class="btn btn-primary" data-cr-modal="hareket" data-tur="tahsilat" data-cari="' . $id . '"' . $dis . '>İlk tahsilatı ekle</button>'
            . '<button type="button" class="btn" data-cr-modal="hareket" data-tur="odeme" data-cari="' . $id . '"' . $dis . '>İlk ödemeyi ekle</button></div></div></div>';
    }
    $pb = $E['pb'];
    $veri = cari_ekstre($pdo, $id, ['bas' => $E['bas'], 'bit' => $E['bit'], 'pb' => $pb, 'tur' => $E['tur'], 'iptaller_gorunsun' => $E['iptal']]);
    $x = $veri[$pb] ?? ['devir' => 0, 'satirlar' => [], 'donem_borc' => 0, 'donem_alacak' => 0, 'kapanis' => 0];

    // filtre formu
    $don = '';
    foreach (['ay' => 'Bu ay', 'gecen' => 'Geçen ay', 'yil' => 'Bu yıl', 'tumu' => 'Tümü', 'ozel' => 'Özel…'] as $d => $ad) {
        $don .= '<button type="button" class="cari-cip" data-donem="' . $d . '" aria-pressed="' . ($E['donem'] === $d ? 'true' : 'false') . '">' . h($ad) . '</button>';
    }
    $seg = '';
    foreach ($E['pbler'] as $p) $seg .= '<label class="cari-seg"><input type="radio" name="pb" value="' . h($p) . '"' . ($p === $pb ? ' checked' : '') . '><span>' . h($p) . '</span></label>';
    $turOpt = '<option value="">Tüm türler</option>';
    foreach (cari_hareket_turleri() as $t => $b) $turOpt .= '<option value="' . h($t) . '"' . ($E['tur'] === $t ? ' selected' : '') . '>' . h(cari_tur_kisa($t)) . '</option>';
    $ozelGoster = $E['donem'] === 'ozel';
    $form = '<form class="cari-ekstre-filtre" id="crEkstreForm" method="get" action="cari_kartlari.php"><input type="hidden" name="id" value="' . $id . '">'
        . '<div class="cari-cipler" role="group" aria-label="Dönem">' . $don . '</div>'
        . '<div class="cari-ozel-tarih"' . ($ozelGoster ? '' : ' hidden') . ' style="display:flex;gap:8px;flex:1 1 100%"><label class="pm-label" style="flex:1"><span>Başlangıç</span><input type="date" name="bas" value="' . h($E['bas']) . '"></label>'
        . '<label class="pm-label" style="flex:1"><span>Bitiş</span><input type="date" name="bit" value="' . h($E['bit']) . '"></label></div>'
        . '<div class="cari-segment" role="radiogroup" aria-label="Para birimi">' . $seg . '</div>'
        . '<select name="tur" aria-label="Belge türü">' . $turOpt . '</select>'
        . '<noscript><button type="submit" class="btn">Uygula</button></noscript></form>';

    $iptalSay = 0; foreach ($x['satirlar'] as $r) if ($r['durum'] === 'iptal') $iptalSay++;
    $aralik = ($E['bas'] !== '' || $E['bit'] !== '') ? ($E['bas'] !== '' ? cari_tarih_tr($E['bas']) : '…') . ' – ' . ($E['bit'] !== '' ? cari_tarih_tr($E['bit']) : '…') . ' · ' : '';
    $ozet = '<div class="cari-ekstre-ozet"><span><span class="cari-sadece-pc">' . h($aralik) . '</span>' . count($x['satirlar']) . ' hareket' . ($iptalSay ? ' (' . $iptalSay . ' iptal)' : '') . '</span>'
        . '<label class="cari-onay"><input type="checkbox" name="iptal" value="1" form="crEkstreForm"' . ($E['iptal'] ? ' checked' : '') . '><span>İptalleri göster</span></label></div>';

    // tablo + kartlar (aynı veriden iki kez; CSS biri dışında gizler)
    $trs = '';
    if ($E['bas'] !== '') {
        $trs .= '<tr class="cari-devir"><td class="cari-tar">' . h(cari_tarih_tr($E['bas'])) . '</td><td>Devir (dönem öncesi bakiye)</td><td class="cari-tut"></td><td class="cari-tut"></td><td class="cari-tut">' . cari_yon_span((int)$x['devir']) . '</td><td></td></tr>';
    }
    $kartlar = '';
    foreach ($x['satirlar'] as $r) {
        $iptal = $r['durum'] === 'iptal';
        $n = cari_satir_norm(['id' => $r['id'], 'tarih' => $r['tarih'], 'tur' => $r['tur'], 'yon' => $r['yon'], 'tutar' => number_format(($r['borc'] + $r['alacak']) / 100, 2, '.', ''),
            'para_birimi' => $pb, 'kur' => $r['kur'], 'belge_no' => $r['belge_no'], 'evrak_no' => $r['evrak_no'], 'odeme_sekli' => $r['odeme_sekli'], 'aciklama' => $r['aciklama'],
            'cari_id' => $id, 'bagli_cari_id' => $r['bagli_cari_id'], 'bagli_cari_unvan' => $r['bagli_cari_unvan'], 'durum' => $r['durum'], 'iptal_neden' => $r['iptal_neden'], 'kaynak_modul' => $r['kaynak_modul']], (string)$kart['unvan']);
        $btn = (!$iptal && empty($r['kaynak_modul'])) ? cari_iptal_dugme($n) : '';
        $meta = h(implode(' · ', array_filter([$r['belge_no'], $r['tur_etiket'], (string)($r['evrak_no'] ?? ''), (string)($r['odeme_sekli'] ?? '')], static fn($v) => $v !== '')));
        $metaK = h(implode(' · ', array_filter([$r['tur_etiket'], (string)($r['evrak_no'] ?? ''), (string)($r['odeme_sekli'] ?? '')], static fn($v) => $v !== '')));
        $karsi = ($r['tur'] === 'virman' && $r['bagli_cari_id'] !== null)
            ? '<span class="cari-satir-meta">Karşı cari: <a href="cari_kartlari.php?id=' . (int)$r['bagli_cari_id'] . '">' . h($r['bagli_cari_unvan']) . '</a></span>' : '';
        $iptalMeta = $iptal ? '<span class="cari-satir-meta"><span class="cari-rozet cari-rozet--iptal" style="display:inline-flex">İptal</span> ' . h((string)$r['iptal_neden']) . '</span>' : '';
        $bak = $iptal ? '<span class="cari-yon-d">—</span>' : cari_yon_span((int)$r['yuruyen']);
        $trs .= '<tr class="' . ($iptal ? 'cari-iptal' : '') . '" data-id="' . (int)$r['id'] . '"><td class="cari-tar">' . h(cari_tarih_tr($r['tarih'])) . '</td>'
            . '<td class="cari-aciklama-hucre"><span class="cari-satir-ad" title="' . h($r['aciklama']) . '">' . h($r['aciklama'] !== '' ? $r['aciklama'] : $r['tur_etiket']) . '</span><span class="cari-satir-meta">' . $meta . '</span>' . $karsi . $iptalMeta . '</td>'
            . '<td class="cari-tut">' . ($r['borc'] ? h(cari_tutar_bicim((int)$r['borc'], $pb, false)) : '') . '</td><td class="cari-tut">' . ($r['alacak'] ? h(cari_tutar_bicim((int)$r['alacak'], $pb, false)) : '') . '</td>'
            . '<td class="cari-tut">' . $bak . '</td><td class="cari-islem-hucre">' . $btn . '</td></tr>';
        $tutarK = $r['borc'] ? [cari_tutar_bicim((int)$r['borc'], $pb), 'Borç'] : [cari_tutar_bicim((int)$r['alacak'], $pb), 'Alacak'];
        $kartlar .= '<article class="cari-ekstre-satir' . ($iptal ? ' cari-iptal' : '') . '" data-id="' . (int)$r['id'] . '"><div class="cari-ekstre-ust"><span>' . h(cari_tarih_tr($r['tarih']) . ' · ' . $r['belge_no']) . '</span>' . $btn . '</div>'
            . '<div class="cari-ekstre-orta"><div class="cari-satir-ad">' . h($r['aciklama'] !== '' ? $r['aciklama'] : $r['tur_etiket']) . '<span class="cari-satir-meta">' . $metaK . '</span>' . $karsi . $iptalMeta . '</div>'
            . '<div class="cari-ekstre-tutar"><strong>' . h($tutarK[0]) . ' <small>' . $tutarK[1] . '</small></strong><span>'
            . ($iptal ? 'iptal edildi' : 'Bakiye ' . h(cari_tutar_bicim(abs((int)$r['yuruyen']), $pb, false)) . ' ' . strtoupper(cari_yon_h((int)$r['yuruyen']))) . '</span></div></div></article>';
    }
    $e = cari_bakiye_etiketi((int)$x['kapanis']);
    $tablo = '<div class="cari-ekstre-kaydir table-wrap"><table class="data-table cari-tablo"><thead><tr><th>Tarih</th><th>Açıklama / Belge</th><th class="cari-tut">Borç</th><th class="cari-tut">Alacak</th><th class="cari-tut">Bakiye</th><th><span class="cari-sr">İşlem</span></th></tr></thead>'
        . '<tbody>' . $trs . '</tbody><tfoot><tr><td colspan="2">Dönem toplamı (' . h($pb) . ')</td><td class="cari-tut">' . h(cari_tutar_bicim((int)$x['donem_borc'], $pb, false)) . '</td><td class="cari-tut">' . h(cari_tutar_bicim((int)$x['donem_alacak'], $pb, false)) . '</td>'
        . '<td class="cari-tut">' . cari_yon_span((int)$x['kapanis']) . '</td><td></td></tr></tfoot></table></div>';
    $toplam = '<div class="cari-ekstre-toplam" style="margin-top:8px"><div><span>Dönem borç</span><span>' . h(cari_tutar_bicim((int)$x['donem_borc'], $pb)) . '</span></div>'
        . '<div><span>Dönem alacak</span><span>' . h(cari_tutar_bicim((int)$x['donem_alacak'], $pb)) . '</span></div>'
        . '<div><strong>Kapanış</strong><strong class="cari-yon-' . cari_yon_h((int)$x['kapanis']) . '">' . h(cari_tutar_bicim(abs((int)$x['kapanis']), $pb) . ' ' . $e['metin']) . '</strong></div></div>';
    $bos = !$x['satirlar'] ? '<p class="cari-liste-bos">Bu filtreyle hareket yok.</p>' : '';
    return $oPanel . '>' . $form . $ozet . $tablo . '<div class="cari-ekstre-kartlar" aria-label="Ekstre satırları">' . $kartlar . '</div>' . $bos . $toplam . '</div>';
}

/** Detay bölümü (#crDetay). $sekme: ekstre|bilgiler. */
function cari_detay_html(PDO $pdo, array $k, array $E, string $sekme = 'ekstre'): string
{
    $id = (int)$k['id'];
    $pasif = (int)$k['aktif'] !== 1;
    $dis = $pasif ? ' disabled' : '';
    $tipler = cari_tipler();
    $bak = cari_pb_sirala(cari_bakiye($pdo, $id));
    $meta = '<span>' . h($k['kod']) . '</span><span class="cari-rozet cari-rozet--tip">' . h($tipler[$k['tip']] ?? $k['tip']) . '</span>'
        . (!empty($k['grup_adi']) ? '<span class="cari-rozet">' . h($k['grup_adi']) . '</span>' : '')
        . (!empty($k['telefon']) ? '<a href="tel:' . h(preg_replace('/[^0-9+]/', '', (string)$k['telefon'])) . '">' . h($k['telefon']) . '</a>' : '');
    $kutu = '';
    foreach ($bak as $pb => $b) {
        $x = cari_yon_h((int)$b['bakiye']);
        $e = cari_bakiye_etiketi((int)$b['bakiye']);
        $kutu .= '<div class="cari-bk-kutu cari-bk-kutu--' . $x . ' cari-bakiye" data-kurus="' . (int)$b['bakiye'] . '" data-pb="' . h($pb) . '"><div class="cari-bk-pb">' . h($pb . ' · ' . cari_pb_ad($pb)) . '</div>'
            . '<div class="cari-bk-tutar">' . h(cari_tutar_bicim(abs((int)$b['bakiye']), $pb)) . '</div><div class="cari-bk-yontxt">' . h($e['metin']) . ' <small>'
            . ($x === 'b' ? '(bize borçlu)' : ($x === 'a' ? '(biz borçluyuz)' : '(bakiye yok)')) . '</small></div></div>';
    }
    if ($kutu === '') {
        $pb = (string)$k['para_birimi'];
        $kutu = '<div class="cari-bk-kutu cari-bk-kutu--d cari-bakiye" data-kurus="0" data-pb="' . h($pb) . '"><div class="cari-bk-pb">' . h($pb . ' · ' . cari_pb_ad($pb)) . '</div><div class="cari-bk-tutar">' . h(cari_tutar_bicim(0, $pb)) . '</div><div class="cari-bk-yontxt">Denk <small>(bakiye yok)</small></div></div>';
    }
    $ekstreUrl = 'cari_ekstre.php?id=' . $id;
    $xlsx = can('reports.export') ? '<a class="btn cari-islem-pc" href="' . h($ekstreUrl . '&bicim=xlsx') . '">Excel</a>' : '';
    $islemler = '<div class="cari-islemler" role="toolbar" aria-label="Hızlı işlemler">'
        . '<button type="button" class="btn btn-primary" data-cr-modal="hareket" data-tur="tahsilat" data-cari="' . $id . '"' . $dis . '>Tahsilat</button>'
        . '<button type="button" class="btn" data-cr-modal="hareket" data-tur="odeme" data-cari="' . $id . '"' . $dis . '>Ödeme</button>'
        . '<button type="button" class="btn" data-cari-menu-ac aria-haspopup="menu" aria-expanded="false" aria-controls="crMenuDekont"' . $dis . '>Dekont <span aria-hidden="true">&#9662;</span></button>'
        . '<div class="cari-islem-ek"><a class="btn cari-islem-pc" href="' . h($ekstreUrl . '&bicim=yazdir') . '" target="_blank" rel="noopener">Ekstre Yazdır</a>' . $xlsx
        . '<button type="button" class="btn cari-menu-dugme--dar" data-cari-menu-ac aria-haspopup="menu" aria-expanded="false" aria-controls="crMenuDiger" aria-label="Diğer işlemler">&#8943;</button></div></div>';
    $uyari = $pasif ? '<div class="flash flash-error" style="margin:0 0 8px">Bu cari pasif: yeni hareket girilemez. Mevcut hareketler iptal edilebilir.</div>' : '';
    $bilgSek = $sekme === 'bilgiler';
    $altsekme = '<div class="cari-altsekmeler" role="tablist" aria-label="Cari bölümleri">'
        . '<button type="button" class="cari-altsekme" role="tab" id="crSekmeEkstre" aria-selected="' . ($bilgSek ? 'false' : 'true') . '" aria-controls="crPanelEkstre">Ekstre</button>'
        . '<button type="button" class="cari-altsekme" role="tab" id="crSekmeBilgi" aria-selected="' . ($bilgSek ? 'true' : 'false') . '" aria-controls="crPanelBilgi">Bilgiler</button></div>';

    $ep = cari_ekstre_panel_html($pdo, $k, $E);
    if ($bilgSek) $ep = preg_replace('/^<div class="cari-panel" id="crPanelEkstre"/', '<div class="cari-panel" id="crPanelEkstre" hidden', $ep, 1);
    $adres = implode(', ', array_filter([(string)($k['adres'] ?? ''), (string)($k['il'] ?? ''), (string)($k['ulke'] ?? '')], static fn($v) => $v !== ''));
    $yok = '<span class="cari-ipucu">—</span>';
    $iban = (string)($k['iban'] ?? '');
    $ibanGor = trim(chunk_split($iban, 4, ' '));
    $bil = '<dl class="cari-bilgi">'
        . cari_bilgi_oge('Ünvan', h($k['unvan'])) . cari_bilgi_oge('Cari kodu', h($k['kod']))
        . cari_bilgi_oge('Tip / Grup', h(($tipler[$k['tip']] ?? $k['tip']) . (!empty($k['grup_adi']) ? ' · ' . $k['grup_adi'] : ''))) . cari_bilgi_oge('Varsayılan para birimi', h($k['para_birimi']))
        . cari_bilgi_oge('Yetkili', !empty($k['yetkili']) ? h($k['yetkili']) : $yok)
        . cari_bilgi_oge('Telefon', !empty($k['telefon']) ? '<a href="tel:' . h(preg_replace('/[^0-9+]/', '', (string)$k['telefon'])) . '">' . h($k['telefon']) . '</a>' : $yok)
        . cari_bilgi_oge('E-posta', !empty($k['eposta']) ? '<a href="mailto:' . h($k['eposta']) . '">' . h($k['eposta']) . '</a>' : $yok)
        . cari_bilgi_oge('Vade', $k['vade_gun'] !== null && $k['vade_gun'] !== '' ? h((string)$k['vade_gun']) . ' gün' : $yok)
        . cari_bilgi_oge('Vergi dairesi / no', h(implode(' · ', array_filter([(string)($k['vergi_dairesi'] ?? ''), (string)($k['vergi_no'] ?? '')], static fn($v) => $v !== ''))) ?: $yok)
        . cari_bilgi_oge('Adres', $adres !== '' ? h($adres) : $yok)
        . cari_bilgi_oge('IBAN', $iban !== '' ? '<span class="cari-bilgi-satir"><span>' . h($ibanGor) . '</span><button type="button" class="cari-kopyala" data-kopyala="' . h($iban) . '">Kopyala</button></span>' : $yok, true)
        . cari_bilgi_oge('Notlar', !empty($k['notlar']) ? nl2br(h($k['notlar'])) : $yok, true)
        . '</dl>';
    $bilgPanel = '<div class="cari-panel" id="crPanelBilgi" role="tabpanel" aria-labelledby="crSekmeBilgi"' . ($bilgSek ? '' : ' hidden') . '>'
        . '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;gap:8px"><h3 style="margin:0">Kart bilgileri</h3>'
        . '<button type="button" class="btn" data-cr-modal="kart" data-duzenle="1">Düzenle</button></div>' . $bil . '</div>';

    // Pasifleştir/Sil menü düğmeleri bu forma `form="crIslemForm"` ile bağlıdır (menüler kök seviyesinde; id her zaman AKTİF detaydan gelir)
    $islemForm = '<form id="crIslemForm" method="post" action="cari_kaydet.php" data-cr-ajax data-ok="Cari güncellendi" hidden>'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="islem" value="kart_durum"><input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="aktif" value="' . ($pasif ? '1' : '0') . '"><input type="hidden" name="geri" value="cari_kartlari.php?id=' . $id . '"></form>';

    return '<section class="cari-detay" id="crDetay" data-id="' . $id . '" aria-label="Cari detayı"><div class="cari-detay-kart">'
        . '<a class="cari-geri" href="cari_kartlari.php"><span aria-hidden="true">&#8592;</span> Cariler</a>'
        . '<header class="cari-baslik"><div class="cari-baslik-satir"><h2 class="cari-unvan">' . h($k['unvan']) . '</h2><span class="cari-rozet ' . ($pasif ? 'cari-rozet--pasif' : 'cari-rozet--aktif') . '">' . ($pasif ? 'Pasif' : 'Aktif') . '</span></div>'
        . '<div class="cari-meta">' . $meta . '</div></header>'
        . '<div class="cari-bakiyeler" role="group" aria-label="Para birimi başına bakiye">' . $kutu . '</div>'
        . $islemler . $uyari . $altsekme . $ep . $bilgPanel . $islemForm
        . cari_json_script('crKartVeri', cari_kart_json($k))
        . '</div></section>';
}

/** Kök seviyesi menüler (#crMenuDekont, #crMenuDiger). parca=detay yanıtında da bölümün ARDINDAN gelir (yenilenmeleri için). */
function cari_detay_menuleri(array $k, bool $hareketli): string
{
    $id = (int)$k['id'];
    $pasif = (int)$k['aktif'] !== 1;
    $dek = '';
    foreach (['borc_dekont' => 'Borç Dekontu', 'alacak_dekont' => 'Alacak Dekontu', 'virman' => 'Virman (başka cariye)'] as $t => $ad) {
        $dek .= '<li role="none"><button type="button" role="menuitem" class="cari-menu-oge" data-cr-modal="hareket" data-tur="' . $t . '" data-cari="' . $id . '">' . h($ad) . '</button></li>';
    }
    $dek .= '<li class="cari-menu-ayrac" role="separator"></li><li role="none"><button type="button" role="menuitem" class="cari-menu-oge" data-cr-modal="hareket" data-tur="acilis" data-cari="' . $id . '">Açılış / Devir Fişi</button></li>';
    $mob = '<li class="cari-menu-baslik cari-menu-mobil" role="presentation">Ekstre</li>'
        . '<li class="cari-menu-mobil" role="none"><a role="menuitem" class="cari-menu-oge" href="' . h('cari_ekstre.php?id=' . $id . '&bicim=yazdir') . '" target="_blank" rel="noopener">Ekstre Yazdır</a></li>'
        . (can('reports.export') ? '<li class="cari-menu-mobil" role="none"><a role="menuitem" class="cari-menu-oge" href="' . h('cari_ekstre.php?id=' . $id . '&bicim=xlsx') . '">Excel İndir</a></li>' : '')
        . '<li class="cari-menu-ayrac cari-menu-mobil" role="separator"></li>';
    $diger = $mob
        . '<li role="none"><button type="button" role="menuitem" class="cari-menu-oge" data-cr-modal="kart" data-duzenle="1">Düzenle</button></li>'
        . '<li role="none"><button type="submit" form="crIslemForm" name="islem" value="kart_durum" role="menuitem" class="cari-menu-oge" data-cr-islem="kart_durum" data-aktif="' . ($pasif ? '1' : '0') . '">' . ($pasif ? 'Aktifleştir' : 'Pasifleştir') . '</button></li>'
        . '<li role="none">' . ($hareketli
            ? '<button type="button" role="menuitem" class="cari-menu-oge cari-menu-oge--tehlike" disabled title="Hareketi olan cari silinemez">Sil (hareketi var)</button>'
            : '<button type="submit" form="crIslemForm" name="islem" value="kart_sil" role="menuitem" class="cari-menu-oge cari-menu-oge--tehlike" data-cr-islem="kart_sil" onclick="return confirm(\'Bu cari kart kalıcı olarak silinecek. Emin misiniz?\')">Sil</button>') . '</li>';
    return '<ul class="cari-menu-liste" id="crMenuDekont" role="menu" aria-label="Dekont türleri" hidden>' . $dek . '</ul>'
        . '<ul class="cari-menu-liste" id="crMenuDiger" role="menu" aria-label="Diğer işlemler" hidden>' . $diger . '</ul>';
}

// ── Modallar ────────────────────────────────────────────────────────────────

/** Cari seçici veri kümesi (yalnız aktif kartlar): id, kod, unvan, pb, bakiye (metin), bakiyeler (pb → kuruş). */
function cari_veri_dizisi(array $kartlar): array
{
    $o = [];
    foreach ($kartlar as $k) {
        if ((int)$k['aktif'] !== 1) continue;
        $pb = (string)$k['para_birimi'];
        $b = (int)(($k['bakiyeler'] ?? [])[$pb] ?? 0);
        $o[] = ['id' => (int)$k['id'], 'kod' => (string)$k['kod'], 'unvan' => (string)$k['unvan'], 'pb' => $pb,
            'bakiye' => cari_tutar_bicim(abs($b), $pb) . ' ' . cari_bakiye_etiketi($b)['metin'], 'bakiyeler' => (object)($k['bakiyeler'] ?? [])];
    }
    return $o;
}

/**
 * Hareket modalı (#crModalHareket) — 6 türü + virmanı kapsar.
 * @param array{geri:string,tur:string,kilitli:?array,odeme:list<string>} $o  kilitli: seçili kart (+ 'bakiye_metin') ya da null (combobox)
 */
function cari_modal_hareket(array $o): string
{
    $T = isset(cari_hareket_turleri()[$o['tur']]) ? $o['tur'] : 'tahsilat';
    $meta = [
        'tahsilat' => ['Tahsilat Fişi', '<b>Alacak</b> yazılır: cariden para aldık, bakiyesi <b>azalır</b>.'],
        'odeme' => ['Ödeme Fişi', '<b>Borç</b> yazılır: cariye para ödedik, bakiyesi <b>artar</b> (bize borçlu yönüne).'],
        'acilis' => ['Açılış / Devir', 'Önceki dönemden devreden bakiye. <b>Yönü siz seçersiniz</b>; açıklama zorunlu.'],
        'borc_dekont' => ['Borç Dekontu', '<b>Borç</b> yazılır: cari bize borçlanır; açıklama zorunlu.'],
        'alacak_dekont' => ['Alacak Dekontu', '<b>Alacak</b> yazılır: cari alacaklanır; açıklama zorunlu.'],
        'virman' => ['Cariler Arası Virman', 'Kaynak <b>alacaklanır</b>, hedef <b>borçlanır</b>. Aynı para birimi; açıklama zorunlu.'],
    ][$T];
    $fis = in_array($T, ['tahsilat', 'odeme'], true);
    $turRadyo = '';
    foreach (['tahsilat' => 'Tahsilat', 'odeme' => 'Ödeme', 'acilis' => 'Açılış / Devir', 'borc_dekont' => 'Borç Dekontu', 'alacak_dekont' => 'Alacak Dekontu', 'virman' => 'Virman'] as $t => $ad) {
        $turRadyo .= '<label class="cari-tur-sec"><input type="radio" name="tur" value="' . $t . '"' . ($t === $T ? ' checked' : '') . '><span>' . h($ad) . '</span></label>';
    }
    $pbRadyo = '';
    foreach (cari_para_birimleri() as $i => $pb) $pbRadyo .= '<label class="cari-seg"><input type="radio" name="para_birimi" value="' . $pb . '"' . ($i === 0 ? ' checked' : '') . '><span>' . $pb . '</span></label>';
    $odOpt = '<option value="">Seçiniz</option>';
    foreach ($o['odeme'] as $ad) $odOpt .= '<option value="' . h($ad) . '">' . h($ad) . '</option>';
    $k = $o['kilitli'];
    $secici = static function (string $alan, string $liste, string $etiket, bool $zorunlu): string {
        return '<div class="cari-span2" data-cari-secici data-alan="' . $alan . '"><label class="pm-label"><span>' . $etiket . ($zorunlu ? ' <span class="cari-zorunlu" aria-hidden="true">*</span>' : '') . '</span>'
            . '<input type="text" role="combobox" aria-expanded="false" aria-controls="' . $liste . '" aria-autocomplete="list" autocomplete="off" placeholder="Ünvan, kod veya vergi no yazın" required></label>'
            . '<ul class="cari-secici-liste" id="' . $liste . '" role="listbox" hidden></ul><input type="hidden" name="' . $alan . '" value="">'
            . '<div class="cari-secili" hidden><div class="cari-secili-ad"></div><button type="button" class="btn btn-sm">Değiştir</button></div></div>';
    };
    if ($k !== null) {
        $cariAlan = '<div class="cari-span2" data-cari-secici data-alan="cari_id" data-kilitli><span class="pm-label" hidden><span><span data-cari-etiket>Cari</span></span>'
            . '<input type="text" role="combobox" aria-expanded="false" aria-controls="crSec1" aria-autocomplete="list" autocomplete="off"></span><ul class="cari-secici-liste" id="crSec1" role="listbox" hidden></ul>'
            . '<input type="hidden" name="cari_id" value="' . (int)$k['id'] . '"><div class="pm-label"><span><span data-cari-etiket>Cari</span> <span class="cari-zorunlu" aria-hidden="true">*</span></span>'
            . '<div class="cari-secili"><div class="cari-secili-ad"><span>' . h($k['unvan']) . '</span><small>' . h($k['kod'] . ' · ' . ($k['bakiye_metin'] ?? '')) . '</small></div></div></div></div>';
    } else {
        $cariAlan = $secici('cari_id', 'crSec1', '<span data-cari-etiket>Cari</span>', true);
    }
    $virman = '<div class="cari-span2" data-alan-grup="virman"' . ($T === 'virman' ? '' : ' hidden') . '>' . $secici('hedef_id', 'crSec2', 'Hedef cari', true) . '</div>';
    ob_start(); ?>
<div class="pm-overlay" id="crModalHareket" hidden>
  <div class="pm-dialog cari-dialog" role="dialog" aria-modal="true" aria-labelledby="crHareketBaslik">
    <div class="pm-header"><span class="cari-tutamac" aria-hidden="true"></span>
      <h2 class="pm-title" id="crHareketBaslik"><?= h($meta[0]) ?></h2>
      <button type="button" class="pm-close" data-cr-kapat aria-label="Kapat">&#10005;</button></div>
    <form method="post" action="cari_kaydet.php" data-cr-ajax data-ok="Kaydedildi" novalidate>
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="islem" value="<?= $T === 'virman' ? 'virman_ekle' : 'hareket_ekle' ?>"><input type="hidden" name="istek_anahtari" value="<?= h(bin2hex(random_bytes(8))) ?>">
      <input type="hidden" name="geri" value="<?= h($o['geri']) ?>">
      <div class="pm-body">
        <div class="flash flash-error cari-form-hata" role="alert" hidden></div>
        <fieldset class="cari-fieldset"><legend>Belge türü</legend>
          <div class="cari-turler" role="radiogroup"><?= $turRadyo ?></div>
          <p class="cari-tur-ipucu" aria-live="polite"><?= $meta[1] ?></p></fieldset>
        <div class="cari-form-grid cari-form-grid--2">
          <?= $cariAlan ?>
          <?= $virman ?>
          <div class="cari-span2 cari-tutar-satir">
            <label class="pm-label"><span>Tutar <b class="cari-zorunlu" aria-hidden="true">*</b></span>
              <input type="text" class="cari-tutar" name="tutar" inputmode="decimal" autocomplete="off" placeholder="0,00" required data-ilk-odak aria-describedby="crTutarIpucu"></label>
            <div class="pm-label"><span id="crPbEtiket">Para birimi</span>
              <div class="cari-segment" role="radiogroup" aria-labelledby="crPbEtiket"><?= $pbRadyo ?></div></div>
          </div>
          <p class="cari-ipucu cari-span2" id="crTutarIpucu" style="margin:-6px 0 0">En çok 2 ondalık: 1.234,56</p>
          <label class="pm-label" data-kur hidden><span>Kur <span class="cari-ipucu">(isteğe bağlı)</span></span>
            <input type="text" name="kur" inputmode="decimal" autocomplete="off" placeholder="örn. 34,2150"></label>
          <label class="pm-label"><span>Tarih <b class="cari-zorunlu" aria-hidden="true">*</b></span>
            <input type="date" name="tarih" value="<?= h(date('Y-m-d')) ?>" required></label>
          <label class="pm-label" data-alan-grup="odeme"<?= $fis ? '' : ' hidden' ?>><span>Ödeme şekli</span>
            <select name="odeme_sekli"><?= $odOpt ?></select></label>
          <div class="pm-label" data-alan-grup="yon"<?= $T === 'acilis' ? '' : ' hidden' ?>><span id="crYonEtiket">Yön <b class="cari-zorunlu" aria-hidden="true">*</b></span>
            <div class="cari-segment cari-segment--tam" role="radiogroup" aria-labelledby="crYonEtiket">
              <label class="cari-seg"><input type="radio" name="yon" value="B"><span>Borç</span></label>
              <label class="cari-seg"><input type="radio" name="yon" value="A"><span>Alacak</span></label></div>
            <span class="cari-ipucu">Borç = cari bize borçlu · Alacak = biz borçluyuz</span></div>
          <label class="pm-label"><span>Evrak no <span class="cari-ipucu">(isteğe bağlı)</span></span>
            <input type="text" name="evrak_no" maxlength="60" autocomplete="off" placeholder="Dış belge / dekont no"></label>
          <label class="pm-label cari-span2"><span>Açıklama <b class="cari-zorunlu" data-zorunlu-aciklama aria-hidden="true"<?= $fis ? ' hidden' : '' ?>>*</b></span>
            <textarea name="aciklama" rows="2" maxlength="500" placeholder="Ör. Ekim sevkiyatı tahsilatı"<?= $fis ? '' : ' required' ?>></textarea></label>
          <div class="cari-onizleme cari-span2" data-virman-onizleme data-alan-grup="virman"<?= $T === 'virman' ? '' : ' hidden' ?> aria-live="polite">
            <div><span>Kaynak (alacaklanır)</span><span data-onizleme-kaynak>—</span></div>
            <div><span>Hedef (borçlanır)</span><span data-onizleme-hedef>—</span></div>
            <div style="color:var(--muted)"><span>İşlem sonrası (tutar girilince hesaplanır)</span><span>—</span></div>
          </div>
        </div>
      </div>
      <div class="pm-footer"><span class="cari-klavye">Ctrl+Enter kaydeder · Esc kapatır</span>
        <button type="button" class="btn" data-cr-kapat>Vazgeç</button>
        <button type="submit" class="btn btn-primary">Kaydet</button></div>
    </form>
  </div>
</div>
<?php
    return (string)ob_get_clean();
}

/** Kart modalı (#crModalKart): yeni/düzenle. $o: geri, gruplar (aktif tanımlar), kod_oneri. */
function cari_modal_kart(array $o): string
{
    $tipOpt = ''; foreach (cari_tipler() as $v => $ad) $tipOpt .= '<option value="' . h($v) . '">' . h($ad) . '</option>';
    $grpOpt = '<option value="">Seçiniz</option>'; foreach ($o['gruplar'] as $g) $grpOpt .= '<option value="' . (int)$g['id'] . '">' . h($g['ad']) . '</option>';
    $pbR = ''; foreach (cari_para_birimleri() as $i => $pb) $pbR .= '<label class="cari-seg"><input type="radio" name="para_birimi" value="' . $pb . '"' . ($i === 0 ? ' checked' : '') . '><span>' . $pb . '</span></label>';
    ob_start(); ?>
<div class="pm-overlay" id="crModalKart" hidden>
  <div class="pm-dialog cari-dialog cari-dialog--genis cari-dialog--uzun" role="dialog" aria-modal="true" aria-labelledby="crKartBaslik">
    <div class="pm-header"><span class="cari-tutamac" aria-hidden="true"></span>
      <h2 class="pm-title" id="crKartBaslik">Yeni cari</h2>
      <button type="button" class="pm-close" data-cr-kapat aria-label="Kapat">&#10005;</button></div>
    <form method="post" action="cari_kaydet.php" data-cr-ajax data-ok="Cari kaydedildi" novalidate>
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="islem" value="kart_kaydet"><input type="hidden" name="id" value=""><input type="hidden" name="geri" value="<?= h($o['geri']) ?>">
      <div class="pm-body">
        <div class="flash flash-error cari-form-hata" role="alert" hidden></div>
        <fieldset class="cari-bolum"><legend>Temel</legend>
          <div class="cari-form-grid cari-form-grid--2">
            <label class="pm-label cari-span2"><span>Ünvan <b class="cari-zorunlu" aria-hidden="true">*</b></span>
              <input type="text" name="unvan" maxlength="200" required autocomplete="off" data-ilk-odak></label>
            <label class="pm-label"><span>Cari kodu</span><input type="text" name="kod" maxlength="30" value="<?= h($o['kod_oneri']) ?>" autocomplete="off" style="text-transform:uppercase">
              <span class="cari-ipucu">Boş bırakılırsa otomatik verilir</span></label>
            <label class="pm-label"><span>Cari tipi <b class="cari-zorunlu" aria-hidden="true">*</b></span><select name="tip"><?= $tipOpt ?></select></label>
            <label class="pm-label"><span>Cari grubu</span><select name="grup_id"><?= $grpOpt ?></select></label>
            <div class="pm-label"><span id="crKpbEtiket">Varsayılan para birimi</span><div class="cari-segment cari-segment--tam" role="radiogroup" aria-labelledby="crKpbEtiket"><?= $pbR ?></div></div>
            <label class="cari-onay cari-span2"><input type="checkbox" name="aktif" value="1" checked><span>Aktif (pasif cariye yeni hareket girilemez)</span></label>
          </div></fieldset>
        <fieldset class="cari-bolum"><legend>İletişim</legend>
          <div class="cari-form-grid cari-form-grid--2">
            <label class="pm-label"><span>Yetkili</span><input type="text" name="yetkili" maxlength="150" autocomplete="off"></label>
            <label class="pm-label"><span>Telefon</span><input type="tel" name="telefon" maxlength="40" autocomplete="off" inputmode="tel"></label>
            <label class="pm-label cari-span2"><span>E-posta</span><input type="email" name="eposta" maxlength="150" autocomplete="off" inputmode="email"></label>
            <label class="pm-label cari-span2"><span>Adres</span><textarea name="adres" rows="2" maxlength="500"></textarea></label>
            <label class="pm-label"><span>İl</span><input type="text" name="il" maxlength="60" autocomplete="off"></label>
            <label class="pm-label"><span>Ülke</span><input type="text" name="ulke" maxlength="60" autocomplete="off" placeholder="Türkiye"></label>
          </div></fieldset>
        <fieldset class="cari-bolum"><legend>Vergi ve banka</legend>
          <div class="cari-form-grid cari-form-grid--2">
            <label class="pm-label"><span>Vergi dairesi</span><input type="text" name="vergi_dairesi" maxlength="100" autocomplete="off"></label>
            <label class="pm-label"><span>Vergi / TC no</span><input type="text" name="vergi_no" maxlength="11" inputmode="numeric" autocomplete="off"><span class="cari-ipucu">10 veya 11 rakam</span></label>
            <label class="pm-label cari-span2"><span>IBAN</span><input type="text" name="iban" maxlength="40" autocomplete="off" placeholder="TR00 0000 0000 0000 0000 0000 00" style="text-transform:uppercase"></label>
            <label class="pm-label"><span>Vade (gün)</span><input type="number" name="vade_gun" min="0" max="365" inputmode="numeric"></label>
          </div></fieldset>
        <fieldset class="cari-bolum"><legend>Diğer</legend>
          <div class="cari-form-grid">
            <label class="pm-label"><span>Notlar</span><textarea name="notlar" rows="3"></textarea></label>
          </div>
          <p class="cari-kayit-bilgi" hidden></p></fieldset>
      </div>
      <div class="pm-footer"><span class="cari-klavye">Ctrl+Enter kaydeder · Esc kapatır</span>
        <button type="button" class="btn" data-cr-kapat>Vazgeç</button>
        <button type="submit" class="btn btn-primary">Kaydet</button></div>
    </form>
  </div>
</div>
<?php
    return (string)ob_get_clean();
}

/** İptal onay modalı (#crModalIptal). */
function cari_modal_iptal(string $geri): string
{
    ob_start(); ?>
<div class="pm-overlay" id="crModalIptal" hidden>
  <div class="pm-dialog cari-dialog" role="dialog" aria-modal="true" aria-labelledby="crIptalBaslik">
    <div class="pm-header"><span class="cari-tutamac" aria-hidden="true"></span>
      <h2 class="pm-title" id="crIptalBaslik">Belgeyi iptal et</h2>
      <button type="button" class="pm-close" data-cr-kapat aria-label="Kapat">&#10005;</button></div>
    <form method="post" action="cari_kaydet.php" data-cr-ajax data-ok="Belge iptal edildi" novalidate>
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="islem" value="hareket_iptal"><input type="hidden" name="id" value=""><input type="hidden" name="geri" value="<?= h($geri) ?>">
      <div class="pm-body">
        <div class="flash flash-error cari-form-hata" role="alert" hidden></div>
        <div class="cari-iptal-ozet" data-iptal-ozet></div>
        <p class="cari-tur-ipucu" style="margin-top:0">Kayıt silinmez; <b>iptal edildi</b> olarak kalır ve bakiyeye girmez. Virman iptalinde <b>iki cari satırı birlikte</b> iptal edilir.</p>
        <label class="pm-label" style="margin-top:12px"><span>İptal nedeni <b class="cari-zorunlu" aria-hidden="true">*</b></span>
          <textarea name="neden" rows="3" required minlength="3" maxlength="500" placeholder="En az 3 karakter" data-ilk-odak></textarea></label>
        <label class="cari-onay" style="margin-top:6px"><input type="checkbox" name="duzelt" value="1"><span>İptalden sonra düzeltme için yeni belge aç (eski değerlerle dolu)</span></label>
      </div>
      <div class="pm-footer"><button type="button" class="btn" data-cr-kapat>Vazgeç</button>
        <button type="submit" class="btn btn-danger">İptal et</button></div>
    </form>
  </div>
</div>
<?php
    return (string)ob_get_clean();
}

// ── Fişler / Dekontlar ──────────────────────────────────────────────────────

/** Liste filtresi (Fişler/Dekontlar). `bas`/`bit` hiç gelmediyse "Bu yıl". @return array<string,mixed> */
function cari_filtre_oku(array $g, string $sinif): array
{
    if (!array_key_exists('bas', $g) && !array_key_exists('bit', $g)) { [$bas, $bit] = cari_donem_araligi('yil'); }
    else { $bas = cari_tarih_ya_bos($g['bas'] ?? ''); $bit = cari_tarih_ya_bos($g['bit'] ?? ''); }
    $turler = [];
    foreach (cari_hareket_turleri() as $t => $b) if ($b['sinif'] === $sinif) $turler[] = $t;
    $tur = (string)($g['tur'] ?? '');
    $pb = (string)($g['pb'] ?? '');
    $durum = (string)($g['durum'] ?? 'tumu');
    return [
        'sinif' => $sinif, 'bas' => $bas, 'bit' => $bit, 'tur' => in_array($tur, $turler, true) ? $tur : '',
        'pb' => in_array($pb, cari_para_birimleri(), true) ? $pb : '', 'cari_q' => mb_substr(trim((string)($g['cari_q'] ?? '')), 0, 100),
        'durum' => in_array($durum, ['gecerli', 'iptal', 'tumu'], true) ? $durum : 'tumu', 'q' => mb_substr(trim((string)($g['q'] ?? '')), 0, 100),
        's' => max(1, (int)($g['s'] ?? 1)), 'donem' => cari_donem_tani($bas, $bit), 'turler' => $turler, 'virman_tek' => true,
    ];
}

/** Süzgeç → sorgu dizgisi (dışa aktarma/sayfalama). Boş/varsayılan alanlar atılır. */
function cari_filtre_sorgu(array $F, array $ek = []): string
{
    $q = ['bas' => $F['bas'], 'bit' => $F['bit'], 'tur' => $F['tur'], 'pb' => $F['pb'], 'cari_q' => $F['cari_q'], 'durum' => $F['durum'], 'q' => $F['q']];
    $q = array_filter($q + $ek, static fn($v) => $v !== '' && $v !== null);
    if (($F['bas'] ?? '') === '' && ($F['bit'] ?? '') === '') $q['bas'] = $q['bit'] = '';   // "Tümü" açıkça korunur
    return http_build_query($q);
}

function cari_filtre_say(array $F): int
{
    $n = 0;
    foreach (['tur', 'pb', 'cari_q', 'q'] as $a) if ($F[$a] !== '') $n++;
    if ($F['durum'] !== 'tumu') $n++;
    if ($F['donem'] !== 'yil') $n++;
    return $n;
}

/** Liste sayfası gövdesi (Fişler: sinif=fis · Dekontlar: sinif=dekont). Yalnız GET. */
function cari_hareket_sayfa_govde(PDO $pdo, string $sinif): string
{
    $fis = $sinif === 'fis';
    $sayfa = $fis ? 'cari_fisler.php' : 'cari_dekontlar.php';
    $F = cari_filtre_oku($_GET, $sinif);
    $L = cari_hareket_listesi($pdo, $F, 100, ($F['s'] - 1) * 100);
    $kartlar = cari_kart_listesi($pdo, ['aktif' => 1])['satirlar'];
    $odeme = array_map(static fn($t) => (string)$t['ad'], cari_tanim_listesi($pdo, 'odeme_sekli', true));
    $yeni = (string)($_GET['yeni'] ?? '');
    $varTur = $fis ? ['tahsilat', 'odeme', 'acilis'] : ['borc_dekont', 'alacak_dekont', 'virman'];
    if (!in_array($yeni, $varTur, true)) $yeni = '';
    $yeniCari = (int)($_GET['cari'] ?? 0);
    $ilk = $varTur[0];

    // üst satır
    $btn = '';
    foreach ($varTur as $i => $t) {
        $ad = ['tahsilat' => '+ Tahsilat', 'odeme' => '+ Ödeme', 'acilis' => '+ Açılış / Devir', 'borc_dekont' => '+ Borç Dekontu', 'alacak_dekont' => '+ Alacak Dekontu', 'virman' => '+ Virman'][$t];
        $btn .= '<button type="button" class="btn ' . ($i === 0 ? 'btn-primary' : 'cari-ustsatir-ek') . '" data-cr-modal="hareket" data-tur="' . $t . '">' . h($ad) . '</button>';
    }
    $sorgu = cari_filtre_sorgu($F, ['kapsam' => 'liste', 'sinif' => $sinif]);
    $disa = '<div class="cari-ustsatir-ek">' . export_menu('cari_ekstre.php?' . $sorgu . '&bicim=csv', 'cari_ekstre.php?' . $sorgu . '&bicim=xlsx', 'Excel İndir', 'btn') . '</div>';
    $ust = '<div class="cari-ustsatir"><h1>' . ($fis ? 'Cari Fişler' : 'Cari Dekontlar') . '</h1><div class="page-head-actions">' . $btn
        . '<button type="button" class="btn cari-filtre-ac" aria-expanded="false" aria-controls="crFiltre">Filtrele (' . cari_filtre_say($F) . ')</button>' . $disa . '</div></div>';

    // filtre formu
    $don = '';
    foreach (['ay' => 'Bu ay', 'gecen' => 'Geçen ay', 'yil' => 'Bu yıl', 'tumu' => 'Tümü'] as $d => $ad) {
        $don .= '<button type="button" class="cari-cip" data-donem="' . $d . '" aria-pressed="' . ($F['donem'] === $d ? 'true' : 'false') . '">' . h($ad) . '</button>';
    }
    $turOpt = '<option value="">Tüm türler</option>'; foreach ($F['turler'] as $t) $turOpt .= '<option value="' . $t . '"' . ($F['tur'] === $t ? ' selected' : '') . '>' . h(cari_tur_kisa($t)) . '</option>';
    $pbOpt = '<option value="">Tümü</option>'; foreach (cari_para_birimleri() as $p) $pbOpt .= '<option' . ($F['pb'] === $p ? ' selected' : '') . '>' . $p . '</option>';
    $duOpt = ''; foreach (['gecerli' => 'Geçerli', 'iptal' => 'İptal', 'tumu' => 'Tümü'] as $v => $ad) $duOpt .= '<option value="' . $v . '"' . ($F['durum'] === $v ? ' selected' : '') . '>' . $ad . '</option>';
    $filtre = '<form class="cari-filtre" id="crFiltre" method="get" action="' . $sayfa . '" aria-label="Filtreler">'
        . '<div class="cari-filtre-baslik"><span>Filtreler</span><button type="button" class="pm-close" data-filtre-kapat aria-label="Kapat" style="min-width:44px;min-height:44px">&#10005;</button></div>'
        . '<div class="cari-cipler cari-filtre-cipler" role="group" aria-label="Dönem">' . $don . '</div>'
        . '<div class="cari-filtre-izgara">'
        . '<label class="pm-label"><span>Başlangıç</span><input type="date" name="bas" value="' . h($F['bas']) . '"></label>'
        . '<label class="pm-label"><span>Bitiş</span><input type="date" name="bit" value="' . h($F['bit']) . '"></label>'
        . '<label class="pm-label"><span>Tür</span><select name="tur">' . $turOpt . '</select></label>'
        . '<label class="pm-label"><span>Para birimi</span><select name="pb">' . $pbOpt . '</select></label>'
        . '<label class="pm-label cari-span2"><span>Cari</span><input type="search" name="cari_q" placeholder="Ünvan veya kod" autocomplete="off" value="' . h($F['cari_q']) . '"></label>'
        . '<label class="pm-label"><span>Durum</span><select name="durum">' . $duOpt . '</select></label>'
        . '<label class="pm-label cari-span2"><span>Metin (belge no, evrak, açıklama)</span><input type="search" name="q" autocomplete="off" value="' . h($F['q']) . '"></label>'
        . '<div class="cari-filtre-eylem"><a class="btn" href="' . $sayfa . '">Temizle</a><button type="submit" class="btn cari-filtre-uygula">Uygula</button></div></div></form>'
        . '<div class="cari-filtre-ortu"></div>';

    // özet şeridi (para birimi ayrı; tür kırılımlı; yalnız geçerli)
    $ozet = '';
    foreach (cari_para_birimleri() as $pb) {
        if (empty($L['ozet'][$pb])) continue;
        $s = '<div class="cari-ozet-pb"><b>' . h($pb . ' · ' . cari_pb_ad($pb)) . '</b>';
        foreach ($F['turler'] as $t) {
            if (!isset($L['ozet'][$pb][$t])) continue;
            $o = $L['ozet'][$pb][$t];
            $s .= '<div><span>' . h(($t === 'acilis' ? 'Açılış' : cari_tur_kisa($t)) . ' (' . $o['adet'] . ')') . '</span><strong>' . h(cari_tutar_bicim((int)$o['kurus'], $pb)) . '</strong></div>';
        }
        $ozet .= $s . '</div>';
    }
    $ozetHtml = $ozet !== '' ? '<div class="cari-ozet-seridi" aria-label="Para birimi başına toplamlar (geçerli kayıtlar)">' . $ozet . '</div>' : '';

    // satırlar
    $trs = ''; $kts = ''; $iptalSay = 0;
    foreach ($L['satirlar'] as $h) {
        $n = cari_satir_norm($h);
        $iptal = $n['durum'] === 'iptal'; if ($iptal) $iptalSay++;
        $virman = $n['tur'] === 'virman';
        $btnI = (!$iptal && empty($n['kaynak_modul'])) ? cari_iptal_dugme($n) : '';
        $cariTd = '<a href="cari_kartlari.php?id=' . $n['cari_id'] . '">' . h($n['cari_unvan']) . '</a>'
            . ($virman && $n['bagli_cari_id'] !== null ? ' <span aria-label="aktarıldı">&#8594;</span> <a href="cari_kartlari.php?id=' . $n['bagli_cari_id'] . '">' . h($n['bagli_cari_unvan']) . '</a>' : '');
        $cariK = $virman && $n['bagli_cari_id'] !== null ? h($n['cari_unvan']) . ' &#8594; ' . h($n['bagli_cari_unvan']) : h($n['cari_unvan']);
        $metaP = $fis ? [$n['odeme_sekli'], $n['evrak_no']] : [$n['evrak_no']];
        $meta = h(implode(' · ', array_filter($metaP, static fn($v) => $v !== '')));
        $iptalMeta = $iptal ? '<span class="cari-satir-meta"><span class="cari-rozet cari-rozet--iptal" style="display:inline-flex">İptal</span> ' . h($n['iptal_neden']) . '</span>' : '';
        $tutar = cari_tutar_bicim($n['kurus'], $n['pb']);
        $trs .= '<tr class="' . ($iptal ? 'cari-iptal' : '') . '" data-id="' . $n['id'] . '"><td class="cari-tar">' . h(cari_tarih_tr($n['tarih'])) . '</td>'
            . '<td><span class="cari-satir-ad">' . h($n['belge_no']) . '</span><span class="cari-satir-meta"><span class="cari-rozet">' . h(cari_tur_kisa($n['tur'])) . '</span></span></td>'
            . '<td>' . $cariTd . '</td><td><span class="cari-satir-ad">' . h($n['aciklama']) . '</span><span class="cari-satir-meta">' . $meta . '</span>' . $iptalMeta . '</td>'
            . '<td class="cari-tut"><strong>' . h($tutar) . '</strong></td><td class="cari-islem-hucre">' . $btnI . '</td></tr>';
        $kts .= '<article class="cari-fis' . ($iptal ? ' cari-iptal' : '') . '" data-id="' . $n['id'] . '"><div class="cari-fis-ust"><span>' . h(cari_tarih_tr($n['tarih'])) . '</span><span>' . h($n['belge_no']) . '</span></div>'
            . '<a class="cari-fis-cari" href="cari_kartlari.php?id=' . $n['cari_id'] . '">' . $cariK . '</a>'
            . '<p class="cari-fis-aciklama">' . h(implode(' · ', array_filter([$n['aciklama'], ...$metaP], static fn($v) => $v !== ''))) . '</p>'
            . '<div class="cari-fis-alt"><div><span class="cari-fis-tutar">' . h($tutar) . '</span><br><span class="cari-rozet">' . h(cari_tur_kisa($n['tur'])) . '</span></div>'
            . '<div class="cari-fis-eylem">' . ($iptal ? '<span class="cari-rozet cari-rozet--iptal">İptal</span>' : $btnI) . '</div></div></article>';
    }
    if (!$L['satirlar']) {
        $liste = cari_bos('Bu filtreyle kayıt yok', 'Tarih aralığını genişletin ya da yeni kayıt ekleyin.', '<a class="btn" href="' . $sayfa . '">Filtreleri temizle</a>');
    } else {
        $liste = '<div class="table-wrap pc-only"><table class="data-table cari-tablo"><thead><tr><th>Tarih</th><th>Belge</th><th>Cari</th><th>Açıklama</th><th class="cari-tut">Tutar</th><th><span class="cari-sr">İşlem</span></th></tr></thead><tbody>' . $trs . '</tbody></table></div>'
            . '<div class="cari-fis-kartlari mobile-only" aria-label="Kayıt listesi">' . $kts . '</div>';
    }

    // sayfalama
    $topSay = max(1, (int)ceil($L['toplam_satir'] / 100));
    $nav = '';
    $link = static function (int $p, string $et, bool $aktif = false) use ($F, $sayfa): string {
        $q = cari_filtre_sorgu($F, ['s' => $p > 1 ? $p : null]);
        return '<a href="' . h($sayfa . ($q !== '' ? '?' . $q : '')) . '"' . ($aktif ? ' aria-current="page"' : '') . '>' . $et . '</a>';
    };
    $nav .= $F['s'] > 1 ? $link($F['s'] - 1, '&#8249;') : '<span aria-disabled="true">&#8249;</span>';
    for ($p = max(1, $F['s'] - 2); $p <= min($topSay, $F['s'] + 2); $p++) $nav .= $p === $F['s'] ? '<span aria-current="page">' . $p . '</span>' : $link($p, (string)$p);
    $nav .= $F['s'] < $topSay ? $link($F['s'] + 1, '&#8250;') : '<span aria-disabled="true">&#8250;</span>';
    $sayfaHtml = '<div class="cari-sayfa"><span>' . $L['toplam_satir'] . ' kayıt' . ($iptalSay ? ' (' . $iptalSay . ' iptal)' : '') . ' · Sayfa ' . $F['s'] . ' / ' . $topSay . '</span><nav class="cari-sayfa-nav" aria-label="Sayfalama">' . $nav . '</nav></div>';

    $kilit = null;
    $yeniAttr = $yeni !== '' ? ' data-yeni="' . h($yeni) . '"' . ($yeniCari > 0 ? ' data-yeni-cari="' . $yeniCari . '"' : '') : '';
    return '<div class="cari" id="cari"' . $yeniAttr . '>' . cari_ust_menu($fis ? 'fisler' : 'dekontlar') . $ust . $filtre . $ozetHtml . $liste . $sayfaHtml
        . cari_modal_hareket(['geri' => $sayfa, 'tur' => $yeni !== '' ? $yeni : $ilk, 'kilitli' => $kilit, 'odeme' => $odeme])
        . cari_modal_iptal($sayfa . ($F['s'] > 1 || cari_filtre_sorgu($F) !== '' ? '?' . cari_filtre_sorgu($F, ['s' => $F['s'] > 1 ? $F['s'] : null]) : ''))
        . cari_json_script('crCariVeri', cari_veri_dizisi($kartlar)) . '</div>';
}

// ── Tanımlar ────────────────────────────────────────────────────────────────

/** Tanım paneli (grup | odeme_sekli): ekleme formu + liste. */
function cari_tanim_panel(PDO $pdo, string $tur, string $panelId, string $tabId, bool $gizli, bool $yaz): string
{
    $grup = $tur === 'grup';
    $liste = cari_tanim_listesi($pdo, $tur);
    $csrf = h(csrf_token());
    $geri = 'cari_tanimlari.php?sekme=' . $tur;
    $max = 0; foreach ($liste as $t) $max = max($max, (int)$t['sira']);
    $oneri = $max + ($max > 0 && $max % 10 === 0 ? 10 : 1);
    $h = '<section class="cari-tanim-govde" id="' . $panelId . '" role="tabpanel" aria-labelledby="' . $tabId . '"' . ($gizli ? ' hidden' : '') . '>'
        . '<h2>' . ($grup ? 'Cari Grupları' : 'Ödeme Şekilleri') . '</h2><p>'
        . ($grup ? 'Cari kartlarını sınıflandırır; listede filtre olarak kullanılır. Kartta kullanılan grup silinemez, pasifleştirilir.'
                 : 'Tahsilat ve ödeme fişlerinde seçilir. Adı değişirse eski fişler değişmez (fişe yazıldığı ad saklanır).') . '</p>';
    if ($yaz) {
        $h .= '<form class="cari-tanim-ekle" method="post" action="cari_kaydet.php" data-cr-ajax data-ok="Tanım eklendi" novalidate><input type="hidden" name="csrf" value="' . $csrf . '">'
            . '<input type="hidden" name="islem" value="tanim_kaydet"><input type="hidden" name="tur" value="' . $tur . '"><input type="hidden" name="geri" value="' . h($geri) . '">'
            . '<label class="pm-label"><span>' . ($grup ? 'Yeni grup adı' : 'Yeni ödeme şekli') . '</span><input type="text" name="ad" maxlength="100" autocomplete="off" required></label>'
            . '<label class="pm-label"><span>Sıra</span><input type="number" name="sira" value="' . $oneri . '" inputmode="numeric"></label>'
            . '<button type="submit" class="btn btn-primary">+ Ekle</button></form>';
    }
    if (!$liste) return $h . cari_bos('Henüz tanım yok', 'Yukarıdan ilk kaydı ekleyin.', '', 'h3') . '</section>';
    $h .= '<ul class="cari-tanim-liste">';
    foreach ($liste as $t) {
        $id = (int)$t['id']; $aktif = (int)$t['aktif'] === 1; $kul = (int)$t['kullanim'];
        $alt = 'Sıra ' . (int)$t['sira'] . ($kul > 0 ? ' · ' . $kul . ($grup ? ' kartta kullanılıyor' : ' harekette kullanıldı') : '');
        $h .= '<li class="cari-tanim-satir' . ($aktif ? '' : ' cari-tanim-satir--pasif') . '" data-id="' . $id . '"><div class="cari-tanim-ad">' . h($t['ad']) . '<small>' . h($alt) . '</small></div><div class="cari-tanim-eylem">';
        if ($yaz) {
            $h .= '<form method="post" action="cari_kaydet.php" data-cr-ajax data-ok="Tanım güncellendi" style="display:contents"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="islem" value="tanim_durum">'
                . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="geri" value="' . h($geri) . '">'
                . '<label class="cari-aktif"><input type="checkbox" role="switch" data-id="' . $id . '" name="aktif" value="1"' . ($aktif ? ' checked' : '') . ' aria-label="' . h($t['ad']) . ' aktif"><span>Aktif</span></label>'
                . '<noscript><button type="submit" class="btn btn-sm">Uygula</button></noscript></form>'
                . '<button type="button" class="btn btn-sm" data-tanim-duzen aria-label="' . h($t['ad']) . ' düzenle">Düzenle</button>';
            if (!$grup || $kul === 0) {
                $h .= '<form method="post" action="cari_kaydet.php" data-cr-ajax data-ok="Tanım silindi" style="display:contents"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="islem" value="tanim_sil">'
                    . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="geri" value="' . h($geri) . '">'
                    . '<button type="submit" class="btn btn-sm" aria-label="' . h($t['ad']) . ' sil" onclick="return confirm(\'Bu tanım silinecek. Emin misiniz?\')">Sil</button></form>';
            }
        } else {
            $h .= '<span class="cari-rozet ' . ($aktif ? 'cari-rozet--aktif' : 'cari-rozet--pasif') . '">' . ($aktif ? 'Aktif' : 'Pasif') . '</span>';
        }
        $h .= '</div></li>';
        if ($yaz) {
            $h .= '<li class="cari-tanim-satir cari-tanim-satir--duzen" hidden><form method="post" action="cari_kaydet.php" data-cr-ajax data-ok="Tanım güncellendi" style="display:contents" novalidate>'
                . '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="islem" value="tanim_kaydet"><input type="hidden" name="tur" value="' . $tur . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="geri" value="' . h($geri) . '">'
                . '<label class="pm-label"><span>Ad</span><input type="text" name="ad" value="' . h($t['ad']) . '" maxlength="100" required></label>'
                . '<label class="pm-label"><span>Sıra</span><input type="number" name="sira" value="' . (int)$t['sira'] . '" inputmode="numeric"></label>'
                . '<div class="cari-tanim-eylem"><button type="button" class="btn" data-tanim-vazgec>Vazgeç</button><button type="submit" class="btn btn-primary">Kaydet</button></div></form></li>';
        }
    }
    return $h . '</ul></section>';
}

/** İçe aktar paneli. */
function cari_tanim_ice_panel(PDO $pdo, bool $gizli, bool $yaz): string
{
    try { $on = cari_ice_aktar_onizle($pdo); $hata = ''; }
    catch (Throwable $e) { $on = []; $hata = 'Tanım kaynağı (firma/tedarikçi) okunamadı.'; }
    $yeni = 0; foreach ($on as $o) if ($o['durum'] === 'yeni') $yeni++;
    $tr = ['firma' => 'firma', 'tedarikci' => 'tedarikçi'];
    $tbl = ''; $lst = '';
    $tipler = cari_tipler();
    foreach ($on as $o) {
        $kaynak = implode(' + ', array_map(static fn($x) => $tr[$x] ?? $x, $o['kaynak_turler']));
        $hedef = $tipler[$o['hedef_tip']] ?? $o['hedef_tip'];
        $roz = $o['durum'] === 'yeni' ? '<span class="cari-rozet cari-rozet--aktif">Yeni</span>' : '<span class="cari-rozet">Kartı var</span>';
        $tbl .= '<tr><td>' . h($o['ad']) . '</td><td>' . h($kaynak) . '</td><td>' . h($hedef) . '</td><td>' . $roz . '</td></tr>';
        $lst .= '<li class="cari-tanim-satir"><div class="cari-tanim-ad">' . h($o['ad']) . '<small>' . h($kaynak . ' → ' . $hedef) . '</small></div><div class="cari-tanim-eylem">' . $roz . '</div></li>';
    }
    $h = '<section class="cari-tanim-govde" id="crPanelIce" role="tabpanel" aria-labelledby="crTabIce"' . ($gizli ? ' hidden' : '') . '><h2>Firmalardan içe aktar</h2>'
        . '<p>Tanımlar ekranındaki aktif <b>firma</b> ve <b>tedarikçi</b> kayıtlarından cari kart oluşturur. Tek seferliktir; tekrar çalıştırmak çift kart üretmez, tanımlar değişmez.</p>';
    if ($hata !== '') return $h . '<div class="flash flash-error">' . h($hata) . '</div></section>';
    if (!$on) return $h . cari_bos('İçe aktarılacak kayıt yok', 'Tanımlar ekranında aktif firma ya da tedarikçi bulunmuyor.', '', 'h3') . '</section>';
    $h .= '<div class="table-wrap pc-only cari-onizleme-tablo"><table class="data-table cari-tablo"><thead><tr><th>Ad</th><th>Kaynak tür</th><th>Hedef cari tipi</th><th>Durum</th></tr></thead><tbody>' . $tbl . '</tbody></table></div>'
        . '<ul class="cari-tanim-liste mobile-only">' . $lst . '</ul>'
        . '<div class="cari-filtre-eylem" style="justify-content:space-between;align-items:center;margin-top:12px"><span class="muted">' . $yeni . ' yeni kart eklenecek · ' . (count($on) - $yeni) . ' mevcut atlanacak</span>';
    if ($yaz) {
        $h .= '<form method="post" action="cari_kaydet.php" data-cr-ajax data-ok="İçe aktarıldı" style="display:contents"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<input type="hidden" name="islem" value="ice_aktar"><input type="hidden" name="geri" value="cari_tanimlari.php?sekme=ice_aktar">'
            . '<button type="submit" class="btn btn-primary" data-ice-aktar' . ($yeni === 0 ? ' disabled' : '') . '>' . $yeni . ' kartı içe aktar</button></form>';
    }
    return $h . '</div></section>';
}

/** Tanımlar sayfası gövdesi. */
function cari_tanimlari_govde(PDO $pdo): string
{
    $yaz = cari_can('admin');
    $sekme = (string)($_GET['sekme'] ?? 'grup');
    if (!in_array($sekme, ['grup', 'odeme_sekli', 'ice_aktar'], true)) $sekme = 'grup';
    $nGrup = count(cari_tanim_listesi($pdo, 'grup'));
    $nOde = count(cari_tanim_listesi($pdo, 'odeme_sekli'));
    try { $nYeni = count(array_filter(cari_ice_aktar_onizle($pdo), static fn($o) => $o['durum'] === 'yeni')); } catch (Throwable $e) { $nYeni = 0; }
    $tab = static fn(string $id, string $panel, string $ad, string $say, bool $sec): string =>
        '<button type="button" class="cari-tanim-nav-oge" role="tab" id="' . $id . '" aria-selected="' . ($sec ? 'true' : 'false') . '" aria-controls="' . $panel . '">' . h($ad) . ' <small>' . h($say) . '</small></button>';
    return '<div class="cari" id="cari">' . cari_ust_menu('tanimlar') . '<div class="cari-ustsatir"><h1>Cari Tanımları</h1></div><div class="cari-tanim">'
        . '<div class="cari-tanim-nav" role="tablist" aria-label="Tanım türleri">'
        . $tab('crTabGrup', 'crPanelGrup', 'Cari Grupları', (string)$nGrup, $sekme === 'grup')
        . $tab('crTabOdeme', 'crPanelOdeme', 'Ödeme Şekilleri', (string)$nOde, $sekme === 'odeme_sekli')
        . '<span class="cari-tanim-ayrac" role="presentation"></span>'
        . $tab('crTabIce', 'crPanelIce', 'İçe Aktar', $nYeni . ' yeni', $sekme === 'ice_aktar') . '</div><div>'
        . cari_tanim_panel($pdo, 'grup', 'crPanelGrup', 'crTabGrup', $sekme !== 'grup', $yaz)
        . cari_tanim_panel($pdo, 'odeme_sekli', 'crPanelOdeme', 'crTabOdeme', $sekme !== 'odeme_sekli', $yaz)
        . cari_tanim_ice_panel($pdo, $sekme !== 'ice_aktar', $yaz)
        . '</div></div><p class="cari-bilgi-kutu">Cari tipi, para birimi ve hareket türleri sabittir; yalnız cari grubu ile ödeme şekli tanımlanır.</p></div>';
}
