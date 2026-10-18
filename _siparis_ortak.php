<?php
// =========================================================
// _siparis_ortak.php — Siparişler sayfalarının ORTAK görünüm yardımcıları (include-only)
//
// Sayfalar (siparisler / siparis_view / siparis_pkl / siparis_belge / siparis_ayarlar ve uçlar)
// require_once ile yükler: fonksiyonlar bir kez tanımlanır (aynı süreçte sayfa iki kez
// çalıştırılsa bile). Hiçbir DB yazması, çıktı ya da exit YOKTUR — yalnız saf biçimleme.
// Kullanıcı verisi her yerde h() ile kaçırılır. Doğrudan istek → 404.
// =========================================================
if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) { http_response_code(404); exit; }

/** gg.aa.yyyy (Y-m-d ya da tarih-saat) ; boş → '—'. */
function sipui_tarih(?string $v): string
{
    $d = $v !== null ? siparis_tarih_coz($v) : null;
    return $d !== null ? date('d.m.Y', (int)strtotime($d . ' 12:00:00')) : '—';
}

/** gg.aa.yyyy ss:dd ; boş → '—'. */
function sipui_zaman(?string $v): string
{
    if ($v === null || trim($v) === '') return '—';
    $t = strtotime($v);
    return $t !== false ? date('d.m.Y H:i', $t) : '—';
}

/** Tam sayı, binlik nokta ("2.400"); null → '—'. */
function sipui_sayi(mixed $n): string
{
    return ($n === null || $n === '' || !is_numeric($n)) ? '—' : number_format((int)round((float)$n), 0, ',', '.');
}

/** Fiyat: 2–4 ondalık + para birimi; null → '—'. */
function sipui_fiyat(mixed $f, ?string $pb): string
{
    if ($f === null || $f === '' || !is_numeric($f)) return '—';
    $s = rtrim(rtrim(number_format((float)$f, 4, ',', '.'), '0'), ',');
    if (!str_contains($s, ',')) $s .= ',00';
    elseif (strlen($s) - strrpos($s, ',') - 1 < 2) $s .= '0';
    return $s . ($pb ? ' ' . $pb : '');
}

/** Boyut: KB / MB. */
function sipui_boyut(int $b): string
{
    if ($b >= 1048576) return number_format($b / 1048576, 1, ',', '.') . ' MB';
    return number_format(max(1, (int)round($b / 1024)), 0, ',', '.') . ' KB';
}

/** php.ini boyut değeri ("20M") → bayt. */
function sipui_ini_bayt(string $v): int
{
    $v = trim($v);
    if ($v === '') return 0;
    $n = (float)$v;
    return match (strtolower(substr($v, -1))) { 'g' => (int)($n * 1073741824), 'm' => (int)($n * 1048576), 'k' => (int)($n * 1024), default => (int)$n };
}

/** CSRF gizli alanı. */
function sipui_csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

/** data-* özniteliği için güvenli JSON. */
function sipui_json_attr(mixed $v): string
{
    return htmlspecialchars((string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
}

/** Kaynak rozeti: PO / LL / PC / PKL / Elle (+ kilit). */
function sipui_kaynak_chip(?string $k, bool $kilit = false): string
{
    $et = ['po' => 'PO', 'll' => 'LL', 'pc' => 'PC', 'pkl' => 'PKL', 'elle' => 'Elle'];
    if ($k === null || !isset($et[$k])) return $kilit ? '<span class="sip-src sip-src--elle" title="Elle kilitli">🔒 Elle</span>' : '';
    $t = $k === 'elle' ? 'Elle girildi' : ($et[$k] . ' belgesinden');
    return '<span class="sip-src sip-src--' . h($k) . '" title="' . h($t) . '">' . ($kilit ? '🔒 ' : '') . h($et[$k]) . '</span>';
}

/** Belge türü rozeti. */
function sipui_tur_rozeti(string $tur): string
{
    $et = siparis_belge_tur_etiketleri();
    $k = isset($et[$tur]) ? $tur : 'bilinmiyor';
    return '<span class="sip-tur sip-tur--' . h($k) . '">' . h($et[$k]) . '</span>';
}

/** Dört mini belge rozeti: PO / LL / PC / PKL (✓ var, – eksik; renge tek başına güvenmez). */
function sipui_dok_rozetleri(array $sev): string
{
    $o = '<span class="sip-doks" role="group" aria-label="Belge durumu">';
    $ad = ['po' => 'Purchase Order (sipariş)', 'll' => 'Loading List (yükleme listesi / araç bilgisi)',
           'pc' => 'Price Confirmation (fiyat onayı)', 'pkl' => 'Packing List (PKL Excel)'];
    foreach (['po' => 'PO', 'll' => 'LL', 'pc' => 'PC', 'pkl' => 'PKL'] as $k => $et) {
        $var = (int)($sev['var_' . $k] ?? 0) === 1;
        $o .= '<span class="sip-dok ' . ($var ? 'sip-dok--var' : 'sip-dok--yok') . '" title="' . h($ad[$k] . ($var ? ' — yüklü' : ' — eksik')) . '">'
            . h($et) . ' <span aria-hidden="true">' . ($var ? '✓' : '–') . '</span><span class="sip-sr">' . ($var ? ' var' : ' eksik') . '</span></span>';
    }
    return $o . '</span>';
}

/** İlerleme çubuğu + "3/6". */
function sipui_ilerleme_html(array $sev): string
{
    $p = siparis_ilerleme($sev);
    return '<span class="sip-prog-kap"><span class="sip-prog" role="progressbar" aria-label="Süreç ilerlemesi" aria-valuemin="0" aria-valuemax="' . (int)$p['toplam']
        . '" aria-valuenow="' . (int)$p['adim'] . '"><span style="width:' . (int)$p['yuzde'] . '%"></span></span><span class="sip-prog-txt">'
        . (int)$p['adim'] . '/' . (int)$p['toplam'] . '</span></span>';
}

/** Plaka metni ya da "—". */
function sipui_arac(array $sev): string
{
    $p = trim((string)($sev['plakalar'] ?? ''));
    if ($p !== '') return h($p);
    $pl = trim((string)($sev['planlanan_plakalar'] ?? ''));
    return $pl !== '' ? '<span class="sip-soluk" title="Planlanan plaka">' . h($pl) . '</span>' : '—';
}

/** Navlun (Freight Order) numarası — araç sütununda plakaların altında ince satır; boşsa ''. */
function sipui_navlun_html(array $sev): string
{
    $n = trim((string)($sev['navlun_no'] ?? ''));
    return $n === '' ? '' : '<div class="sip-navlun" title="Navlun (Freight Order) numarası">Navlun ' . h($n) . '</div>';
}

/**
 * Sipariş sütunu alt satırı: aktif beyan bağı varsa "Parti 12345 (+N)", yoksa "Tur 9000…"; ikisi de yoksa ''.
 * $parti = siparis_beyan_parti_haritasi() sonucu (sevkiyat id → ['parti','fazla']). $sar: ön ek (mobilde " · ").
 */
function sipui_tur_parti_html(array $sev, array $parti, bool $satir = true): string
{
    $sid = (int)($sev['id'] ?? 0);
    $ic = '';
    if (isset($parti[$sid]) && (string)$parti[$sid]['parti'] !== '') {
        $fz = (int)($parti[$sid]['fazla'] ?? 0);
        $ic = '<span title="Bağlı beyanın parti numarası">Parti ' . h((string)$parti[$sid]['parti']) . ($fz > 0 ? ' +' . $fz : '') . '</span>';
    } elseif (trim((string)($sev['tur_no'] ?? '')) !== '') {
        $ic = '<span>Tur ' . h((string)$sev['tur_no']) . '</span>';
    }
    if ($ic === '') return '';
    return $satir ? '<div class="sip-soluk nowrap sip-turparti">' . $ic . '</div>' : '<span class="sip-soluk sip-turparti"> · ' . $ic . '</span>';
}

/**
 * Liste için ürün satırları (PO "Article specification" + palet + PF). $kalemler: siparis_kalemler satırları
 * (sira sırasıyla). Palet: etkin_palet varsa o, yoksa po_palet. Hiç gönderilmeyecek kalem (etkin palet 0 ve etkin koli 0/boş)
 * atlanır. En çok $max satır; kalanı "+N daha".
 * @return array{satirlar:list<array{ad:string,meta:string}>,fazla:int}
 */
function sipui_kalem_satirlari(array $kalemler, int $max = 3): array
{
    $tum = [];
    foreach ($kalemler as $k) {
        $pal = ($k['etkin_palet'] ?? null) !== null && $k['etkin_palet'] !== '' ? $k['etkin_palet'] : ($k['po_palet'] ?? null);
        $koli = $k['etkin_koli'] ?? null;
        if ($pal !== null && is_numeric($pal) && (int)$pal === 0 && ($koli === null || $koli === '' || (int)$koli === 0)) continue;
        $ad = trim((string)($k['aciklama'] ?? ''));
        if ($ad === '') $ad = trim(trim((string)($k['urun'] ?? '')) . ' ' . trim((string)($k['kalibre'] ?? '')));
        $parca = array_values(array_filter([$ad, trim((string)($k['cesit'] ?? '')), trim((string)($k['koli_icerik'] ?? ''))], fn($x) => $x !== ''));
        $meta = [];
        if ($pal !== null && is_numeric($pal)) $meta[] = 'Pal. ' . sipui_sayi($pal);
        if (($k['pf'] ?? null) !== null && is_numeric($k['pf'])) $meta[] = 'PF ' . sipui_sayi($k['pf']);
        if (!$parca && !$meta) continue;
        $tum[] = ['ad' => implode(' · ', $parca), 'meta' => implode(' · ', $meta)];
    }
    return ['satirlar' => array_slice($tum, 0, max(1, $max)), 'fazla' => max(0, count($tum) - max(1, $max))];
}

/** Ürün / palet / PF hücresi (masaüstü sütunu ve mobil kart bölümü ortak); kalem yoksa ''. */
function sipui_kalem_html(array $kalemler): string
{
    $k = sipui_kalem_satirlari($kalemler);
    if (!$k['satirlar']) return '';
    $o = '<div class="sip-urunler">';
    foreach ($k['satirlar'] as $s) {
        $o .= '<div class="sip-urun" title="' . h($s['ad'] . ($s['meta'] !== '' ? ' — ' . $s['meta'] : '')) . '"><span class="sip-urun-ad">' . h($s['ad']) . '</span>'
            . ($s['meta'] !== '' ? '<span class="sip-urun-meta">' . h($s['meta']) . '</span>' : '') . '</div>';
    }
    if ($k['fazla'] > 0) $o .= '<div class="sip-urun-fazla">+' . (int)$k['fazla'] . ' daha</div>';
    return $o . '</div>';
}

/** Zaman çizelgesi olay etiketleri: tür → [simge, metin]. */
function sipui_olay_etiketleri(): array
{
    return [
        'olusturuldu' => ['🆕', 'Sevkiyat oluşturuldu'], 'belge_eklendi' => ['📎', 'Belge eklendi'], 'belge_eski_surum' => ['🕘', 'Eski sürüm olarak işaretlendi'],
        'belge_silindi' => ['🗑', 'Belge silindi'], 'alan_guncellendi' => ['✎', 'Alan belgeden güncellendi'], 'alan_elle' => ['✍', 'Alan elle düzeltildi'],
        'kilit_kaldirildi' => ['🔓', 'Elle kilit kaldırıldı'], 'catisma' => ['⚠', 'Belgeler arasında çatışma'], 'catisma_kabul' => ['✔', 'Çatışma: gelen değer kabul edildi'],
        'catisma_red' => ['✖', 'Çatışma: mevcut değer korundu'], 'durum' => ['🔄', 'Durum değişti'], 'pkl_taslak' => ['📝', 'PKL taslağı kaydedildi'],
        'pkl_uretildi' => ['📄', 'PKL üretildi'], 'pkl_yuklendi' => ['📤', 'PKL yüklendi'], 'mail_gonderildi' => ['✅', 'Mail gönderildi olarak işaretlendi'],
        'beyan_baglandi' => ['🏛', 'Beyan bağlandı'], 'beyan_kaldirildi' => ['🏛', 'Beyan bağı kaldırıldı'], 'geri_getirildi' => ['♻', 'Sevkiyat geri getirildi'],
        'silindi' => ['🗑', 'Sevkiyat silindi'], 'uyari' => ['⚠', 'Uyarı'],
    ];
}

/** Olay satırı → düz metin cümle (HTML DEĞİL; çağıran h() ile kaçırır). */
function sipui_olay_cumle(array $o): string
{
    $et = sipui_olay_etiketleri()[(string)$o['tur']] ?? ['•', (string)$o['tur']];
    $dd = siparis_durumlar();
    $eski = (string)($o['eski'] ?? ''); $yeni = (string)($o['yeni'] ?? '');
    if ($o['tur'] === 'durum' || $o['tur'] === 'mail_gonderildi') {
        $eski = $dd[$eski]['etiket'] ?? $eski; $yeni = $dd[$yeni]['etiket'] ?? $yeni;
        $s = $et[1] . ($eski !== '' || $yeni !== '' ? ': ' . ($eski !== '' ? $eski . ' → ' : '') . $yeni : '');
    } else {
        $s = $et[1];
        if (($o['aciklama'] ?? '') === '' && ($eski !== '' || $yeni !== '')) $s .= ': ' . ($eski !== '' ? $eski . ' → ' : '') . $yeni;
    }
    $ac = trim((string)($o['aciklama'] ?? ''));
    return $ac !== '' ? $s . ($o['tur'] === 'durum' || $o['tur'] === 'mail_gonderildi' ? ' · ' : ' — ') . $ac : $s;
}

/** Ayrıştırıcı uyarı kodları → Türkçe. Bilinmeyen kod: ham mesaj. */
function sipui_uyari_kodu_etiketi(string $kod): ?string
{
    static $m = [
        'no_plates' => 'Belgede plaka yok', 'no_tour' => 'Tur numarası okunamadı', 'no_article_no' => 'Artikel numarası okunamadı',
        'price_missing' => 'Fiyat okunamadı', 'pricing_date_missing' => 'Fiyat tarihi okunamadı', 'transport_missing' => 'Nakliye bilgisi okunamadı',
        'split_differs' => 'Kalibre bölümü belgeler arasında farklı', 'multiple_destinations' => 'Birden çok varış yeri',
        'sold_to_not_in_destination' => 'Satış yapılan firma varış yerlerinde yok', 'duplicate_order_in_po' => "PO'da yinelenen sipariş numarası",
        'order_without_items' => 'Kalemi olmayan sipariş', 'orphan_row' => 'Hiçbir siparişe bağlanamayan satır', 'page_count' => 'Sayfa sayısı beklenenden farklı',
        'cell_missing' => 'Tabloda okunamayan hücre', 'item_not_in_summary' => 'Kalem özet tablosunda yok', 'unknown_doc' => 'Belge türü tanınmadı',
        'loading_date_missing' => 'Yükleme tarihi okunamadı', 'no_loadings' => 'Yükleme bloğu bulunamadı', 'no_rows' => 'Satır bulunamadı',
        'order_ref_missing' => 'Sipariş numarası okunamadı', 'ignored_line' => 'Yok sayılan satır', 'exception' => 'Ayrıştırma hatası',
    ];
    if (isset($m[$kod])) return $m[$kod];
    if (str_starts_with($kod, 'check_')) return 'Tutarlılık denetimi tutmadı';
    return null;
}

/** Yükleme sonuç özeti — satır başına [dosya, tür, sonuç, çatışma, uyarı] (siparis_yukle.php oturuma yazar). */
function sipui_yukleme_sonuc_html(array $sonuclar): string
{
    if (!$sonuclar) return '';
    $sonucMetni = function (array $r): string {
        if (($r['durum'] ?? '') === 'hata') return '<span class="sip-hata-metin">Hata: ' . h((string)($r['hata'] ?? 'işlenemedi')) . '</span>';
        if (($r['durum'] ?? '') === 'zaten') {
            $l = [];
            foreach ((array)($r['sevkiyatlar'] ?? []) as $s) $l[] = '<a href="siparis_view.php?id=' . (int)$s['id'] . '">' . h((string)$s['order_ref']) . '</a>';
            return 'Zaten yüklü' . ($l ? ' · ' . implode(', ', $l) : '');
        }
        $parca = [];
        foreach ((array)($r['sevkiyatlar'] ?? []) as $s) {
            $a = '<a href="siparis_view.php?id=' . (int)$s['id'] . '">' . h((string)$s['order_ref']) . '</a>';
            if (!empty($s['yeni'])) $parca[] = 'Yeni sevkiyat ' . $a . (in_array($r['tur'] ?? '', ['ll', 'pc'], true) ? ' <span class="sip-soluk">(taslak: PO bekleniyor)</span>' : '');
            else $parca[] = 'Eşleşti ' . $a;
        }
        $o = $parca ? implode('<br>', $parca) : 'İşlendi — sevkiyata bağlanmadı';
        foreach ((array)($r['mesajlar'] ?? []) as $m) {
            $kirmizi = str_contains((string)$m, 'içeriyor; o sevkiyata bağlandı');
            $o .= '<div class="' . ($kirmizi ? 'sip-hata-metin' : 'sip-soluk') . ' sip-sonuc-mesaj">' . h((string)$m) . '</div>';
        }
        return $o;
    };
    $o = '<section class="card sip-sonuc" id="sonuc" aria-label="Yükleme sonucu"><div class="card-head"><h2>Yükleme sonucu</h2></div>';
    $o .= '<div class="table-wrap pc-only"><table class="data-table sip-table"><thead><tr><th>Dosya</th><th>Tür</th><th>Sonuç</th><th class="num">Çatışma</th><th class="num">Uyarı</th></tr></thead><tbody>';
    foreach ($sonuclar as $r) {
        $o .= '<tr><td class="sip-kes" title="' . h((string)$r['dosya']) . '">' . h((string)$r['dosya']) . '</td><td>' . (empty($r['tur']) ? '—' : sipui_tur_rozeti((string)$r['tur']))
            . '</td><td>' . $sonucMetni($r) . '</td><td class="num">' . (int)($r['catisma'] ?? 0) . '</td><td class="num">' . (int)($r['uyari'] ?? 0) . '</td></tr>';
    }
    $o .= '</tbody></table></div><div class="card-list mobile-only">';
    foreach ($sonuclar as $r) {
        $o .= '<div class="sip-mini-kart"><div class="sip-mini-ust"><strong class="sip-kes">' . h((string)$r['dosya']) . '</strong>' . (empty($r['tur']) ? '' : sipui_tur_rozeti((string)$r['tur']))
            . '</div><div>' . $sonucMetni($r) . '</div>';
        if ((int)($r['catisma'] ?? 0) > 0 || (int)($r['uyari'] ?? 0) > 0) {
            $o .= '<div class="sip-soluk">Çatışma: ' . (int)($r['catisma'] ?? 0) . ' · Uyarı: ' . (int)($r['uyari'] ?? 0) . '</div>';
        }
        $o .= '</div>';
    }
    return $o . '</div></section>';
}

/** Yükleme alanı (sürükle-bırak etiketi + dosya listesi). $resim: sevkiyat sayfası (görsel de kabul edilir). */
function sipui_dropzone_html(string $id, bool $resim = false): string
{
    $accept = $resim ? '.pdf,.xlsx,.jpg,.jpeg,.png,.webp' : '.pdf,.xlsx';
    $ipucu = $resim
        ? "PO, Loading List, Price Confirmation PDF'leri, PKL Excel'i ya da görsel — içeriğe göre sevkiyata bağlanır."
        : "PO, Loading List, Price Confirmation PDF'leri ve PKL Excel'i — sistem içeriğe bakıp doğru sevkiyata bağlar.";
    return '<label class="sip-drop" for="' . h($id) . '" data-sip-drop><span class="sip-drop-ikon" aria-hidden="true">📥</span>'
        . '<span class="sip-drop-baslik">Dosyaları seçin ya da buraya bırakın</span>'
        . '<span class="sip-drop-ipucu">' . h($ipucu) . ' En çok ' . SIPARIS_MAX_DOSYA_ADET . ' dosya, her biri en çok ' . (int)(SIPARIS_MAX_DOSYA / 1048576) . ' MB.</span>'
        . '<input type="file" id="' . h($id) . '" name="belgeler[]" multiple accept="' . h($accept) . '" required></label>'
        . '<ul class="sip-dosya-liste" data-sip-dosya-liste aria-live="polite"></ul><p class="sip-hata-metin" data-sip-yukle-hata hidden role="alert"></p>';
}

/** Yükleme formunun veri öznitelikleri (JS ön denetimi): post_max_size − 1 MB. */
function sipui_yukleme_ozellikleri(): string
{
    $post = sipui_ini_bayt((string)ini_get('post_max_size'));
    $toplam = $post > 0 ? max(1048576, $post - 1048576) : 20 * 1048576;
    return 'data-sip-upload data-sip-max-dosya="' . SIPARIS_MAX_DOSYA . '" data-sip-max-adet="' . SIPARIS_MAX_DOSYA_ADET . '" data-sip-max-toplam="' . (int)$toplam . '"';
}

/** 404 gövdesi (sayfa çağırır, sonra kendisi exit eder). */
function sipui_yok_sayfasi(string $baslik, string $mesaj = 'Kayıt bulunamadı ya da silinmiş olabilir.'): void
{
    http_response_code(404);
    render_header($baslik);
    siparis_assets();
    echo '<div class="sip-mod"><div class="empty"><h2>' . h($baslik) . '</h2><p class="muted">' . h($mesaj) . '</p><p><a class="btn btn-primary" href="siparisler.php">← Siparişler</a></p></div></div>';
    render_footer();
}

/** Düzenlenebilir alanların gösterim grupları (siparis_view.php) — [grup başlığı => [alanlar]]. */
function sipui_alan_gruplari(): array
{
    return [
        'Sevkiyat' => ['tur_no', 'yukleme_tarihi', 'yukleme_yeri', 'bosaltma_tarihi', 'bosaltma_yerleri'],
        'Araç ve navlun' => ['nakliyeci', 'plakalar', 'navlun_no'],
        'Alıcı' => ['alici_kodu', 'alici_adi', 'sold_to'],
        'Gümrük' => ['gumruk', 'kdv_no', 'incoterm'],
    ];
}

/** Kalem (ürün) düzenleme penceresindeki alanlar. */
function sipui_kalem_alanlari(): array
{
    return ['etkin_palet', 'etkin_koli', 'pf', 'artikel_no', 'cesit', 'palet_tipi'];
}

/** Belge önizleme penceresi (masaüstü ≥1024: PDF iframe / xlsx-görsel parçası). Aşağıdaki JS (SiparisUI) doldurur. */
function sipui_onizle_modal_html(): string
{
    return '<div class="pm-overlay" id="sipOnizleModal" hidden role="dialog" aria-modal="true" aria-labelledby="sipOnizleBaslik">'
        . '<div class="pm-dialog sip-dlg--genis"><div class="pm-header"><h2 class="pm-title sip-kes" id="sipOnizleBaslik">Belge önizleme</h2>'
        . '<button type="button" class="pm-close" data-sip-kapat aria-label="Kapat">✕</button></div>'
        . '<div class="pm-body sip-onizle-govde" data-sip-onizle-govde></div>'
        . '<div class="pm-footer"><a class="btn" href="#" data-sip-onizle-sayfa>Belge sayfasını aç</a><a class="btn btn-primary" href="#" data-sip-onizle-indir>İndir</a></div>'
        . '</div></div>';
}
