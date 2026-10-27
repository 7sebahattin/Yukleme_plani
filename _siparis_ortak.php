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

/**
 * v376 — liste tarihi, bugüne göre renkli: geçmiş → yeşil + kalın, bugün → turuncu + kalın, henüz gelmemiş → normal metin rengi.
 * Renk yalnız görsel ipucu; durum `title` ile de yazılır. Boş / çözülemeyen → '—' (düz).
 */
function sipui_tarih_html(?string $v): string
{
    $d = $v !== null ? siparis_tarih_coz($v) : null;
    if ($d === null) return '—';
    $bugun = date('Y-m-d');
    $sinif = $d < $bugun ? 'gecmis' : ($d === $bugun ? 'bugun' : 'gelecek');
    $ipucu = ['gecmis' => 'Tarih geçti', 'bugun' => 'Bugün', 'gelecek' => 'Henüz gelmedi'][$sinif];
    return '<span class="sip-tar sip-tar--' . $sinif . '" title="' . h($ipucu) . '">' . h(sipui_tarih($v)) . '</span>';
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
 * v386 — bağlı beyanların parti no'ları: sipariş no'nun ÜSTÜNDE, 3B düğme görünümlü rozetler (her parti ayrı rozet,
 * sayı sınırı yok, tekrar eden parti no tek). Parti yoksa ''. $parti = siparis_beyan_parti_haritasi() çıktısı
 * (eski biçim 'liste' yoksa tek 'parti'). Kullanıcı verisi h() ile kaçırılır.
 */
function sipui_parti_rozet_html(array $sev, array $parti): string
{
    $sid = (int)($sev['id'] ?? 0);
    if (!isset($parti[$sid]) || (string)($parti[$sid]['parti'] ?? '') === '') return '';
    $liste = array_values(array_unique(array_filter(array_map('strval', (array)($parti[$sid]['liste'] ?? [$parti[$sid]['parti']])), fn($x) => $x !== '')));
    if (!$liste) return '';
    $ic = '';
    foreach ($liste as $p) $ic .= '<span class="sip-parti-btn" title="Bağlı beyanın parti numarası"><span class="sip-parti-et">Parti</span> ' . h($p) . '</span>';
    return '<div class="sip-parti-rozetler">' . $ic . '</div>';
}

/**
 * v402 — "Son bakışınızdan sonra değişti" işareti. Görülme kaydı YALNIZ cihazda (localStorage `asya_sip_gorulen_<uid>`, şema YOK):
 * ilk ziyarette taban zaman = sunucu "şimdi" (öncesi "yeni" sayılmaz); sevkiyat sayfası açılınca o sevkiyatın değişme zamanı "görüldü"
 * yazılır. Liste satırı/kartı: taban < değişme zamanı VE değişme zamanı > görüldü ise çerçevelenir (assets/siparis.js `gorulen()`).
 */
function sipui_gorulen_meta(int $uid, ?array $sev = null): string
{
    $a = ' data-sip-gor-uid="' . $uid . '" data-sip-gor-now="' . h(siparis_simdi()) . '"';
    if ($sev !== null) $a .= ' data-sip-gor-id="' . (int)$sev['id'] . '" data-sip-gor-upd="' . h((string)($sev['updated_at'] ?? $sev['created_at'] ?? '')) . '"';
    return '<span hidden' . $a . '></span>';
}

/** v402 — liste satırı/kartı için gizli değişme işareti (id + son değişme zamanı). */
function sipui_gorulen_satir(array $sev): string
{
    return '<span hidden data-sip-gor-id="' . (int)($sev['id'] ?? 0) . '" data-sip-gor-upd="' . h((string)($sev['degisti_at'] ?? '')) . '" data-sip-gor-yeni="' . h((string)($sev['olusturma_at'] ?? '')) . '"></span>';
}

/**
 * v424 — liste rozetinde "⚠ N uyarı/çatışma"nın üzerine gelince açılan baloncuğun metinleri: sevkiyat id → ['c' => çatışma satırları,
 * 'u' => uyarı satırları]. TEK sorgu çifti (N+1 yok); hata → boş harita (rozet yine çalışır, yalnız baloncuk açılmaz).
 * Çatışma satırı "Alan: mevcut → gelen"; değerler 60 karaktere kırpılır. Her listede en çok 6 satır + "… +N daha".
 * @param list<int> $ids @return array<int,array{c:list<string>,u:list<string>}>
 */
function sipui_uyari_haritasi(PDO $pdo, array $ids): array
{
    $out = [];
    if (!$ids) return $out;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $kes = static fn(string $s): string => mb_strlen($s) > 60 ? mb_substr($s, 0, 59) . '…' : $s;
    try {
        $q = $pdo->prepare("SELECT id, uyarilar_json FROM siparis_sevkiyatlar WHERE uyarilar_json IS NOT NULL AND uyarilar_json <> '' AND id IN ($in)");
        $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            foreach ((array)siparis_json_coz((string)$r['uyarilar_json']) as $u) {
                $m = trim((string)($u['mesaj'] ?? ''));
                if ($m !== '') $out[(int)$r['id']]['u'][] = (($u['seviye'] ?? '') === 'hata' ? '⛔ ' : '') . $m;
            }
        }
        $q = $pdo->prepare("SELECT sevkiyat_id, kalem_id, alan, mevcut_deger, gelen_deger FROM siparis_catismalar WHERE durum = 'acik' AND sevkiyat_id IN ($in) ORDER BY id DESC");
        $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $et = siparis_alan_etiketi($r['kalem_id'] !== null ? 'kalem' : 'sevkiyat', (string)$r['alan']);
            $v = static fn($x): string => ($x === null || $x === '') ? '—' : (string)$x;
            $out[(int)$r['sevkiyat_id']]['c'][] = $et . ': ' . $kes($v($r['mevcut_deger'])) . ' → ' . $kes($v($r['gelen_deger']));
        }
    } catch (Throwable $e) {
        error_log('[siparis_ui] uyari haritasi: ' . get_class($e));
        return [];
    }
    foreach ($out as $id => $g) {
        foreach (['c', 'u'] as $k) {
            $l = $g[$k] ?? [];
            if (count($l) > 6) { $fazla = count($l) - 6; $l = array_slice($l, 0, 6); $l[] = '… +' . $fazla . ' daha'; }
            $out[$id][$k] = $l;
        }
    }
    return $out;
}

/** v424 — rozetin yanındaki gizli baloncuk verisi (JS metni textContent ile gösterir). Veri yoksa ''. */
function sipui_uyari_balon_html(array $r, array $harita): string
{
    $g = $harita[(int)$r['id']] ?? null;
    if (!$g || (empty($g['c']) && empty($g['u']))) return '';
    $o = '<span class="sip-balon-veri" hidden>';
    foreach (['c' => 'Çatışmalar', 'u' => 'Uyarılar'] as $k => $baslik) {
        if (empty($g[$k])) continue;
        $o .= '<span data-baslik="' . h($baslik) . '">';
        foreach ($g[$k] as $satir) $o .= '<span data-satir>' . h($satir) . '</span>';
        $o .= '</span>';
    }
    return $o . '</span>';
}

/**
 * v426 — "Ne değişti" bildirimi: listede sipariş no'nun solundaki nokta + değişiklik sayısı ve üzerine gelince açılan özet.
 * Kaynak zaman çizelgesi (siparis_olaylar): belge/mail ile gelen alan değişiklikleri, çatışmalar, yeni/silinen belge. Elle düzeltme,
 * durum, PKL/mail/evrak adımları SAYILMAZ (bunlar kullanıcının kendi işlemi); sevkiyatın oluşturulduğu ilk dakikadaki dolum da sayılmaz
 * (onu "YENİ" rozeti söyler). Hangi satırın "görülmemiş" olduğuna İSTEMCİ karar verir (görülme kaydı cihazda — v402): her satır kendi
 * zaman damgasıyla gelir, JS son bakış zamanından eskileri atar. TEK sorgu (N+1 yok); hata → boş harita.
 * @param list<int> $ids @return array<int,list<array{t:string,m:string}>> sevkiyat id → en yeni önce, en çok 20 satır
 */
function sipui_degisim_haritasi(PDO $pdo, array $ids, int $gun = 30): array
{
    $out = [];
    if (!$ids) return $out;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $turler = "'alan_guncellendi','catisma','belge_eklendi','belge_silindi','geri_getirildi'";
    try {
        $q = $pdo->prepare("SELECT o.sevkiyat_id, o.tur, o.eski, o.yeni, o.aciklama, o.created_at, s.created_at AS s_created
            FROM siparis_olaylar o JOIN siparis_sevkiyatlar s ON s.id = o.sevkiyat_id
            WHERE o.sevkiyat_id IN ($in) AND o.tur IN ($turler) AND o.created_at >= ? ORDER BY o.id DESC LIMIT 1500");
        $q->execute(array_merge($ids, [date('Y-m-d H:i:s', time() - $gun * 86400)]));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $sid = (int)$o['sevkiyat_id'];
            if (count($out[$sid] ?? []) >= 20) continue;
            $ilk = strtotime((string)$o['s_created']);
            if ($ilk !== false && strtotime((string)$o['created_at']) <= $ilk + 60) continue;   // oluşturulurken ilk dolum
            $m = sipui_degisim_satiri($o);
            if ($m !== '') $out[$sid][] = ['t' => (string)$o['created_at'], 'm' => $m];
        }
    } catch (Throwable $e) {
        error_log('[siparis_ui] degisim haritasi: ' . get_class($e));
        return [];
    }
    return $out;
}

/** v426 — tek olayı kısa Türkçe özet satırına çevirir ("Yükleme tarihi: 22.10.2026 → 23.10.2026"). Sayılmayan olay → ''. */
function sipui_degisim_satiri(array $o): string
{
    $kes = static fn(string $s): string => mb_strlen($s) > 40 ? mb_substr($s, 0, 39) . '…' : $s;
    $v = static function ($x) use ($kes): string {
        $x = trim((string)$x);
        if ($x === '') return '—';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $x, $m)) return $m[3] . '.' . $m[2] . '.' . $m[1];
        return $kes($x);
    };
    $ac = trim((string)($o['aciklama'] ?? ''));
    switch ((string)$o['tur']) {
        case 'alan_guncellendi':
            $et = trim(explode(' — ', $ac)[0]);
            if ($et === '') $et = 'Alan';
            return trim((string)($o['eski'] ?? '')) === '' ? $et . ' eklendi: ' . $v($o['yeni']) : $et . ': ' . $v($o['eski']) . ' → ' . $v($o['yeni']);
        case 'catisma':
            $et = trim(explode(':', $ac)[0]);
            return '⚠ ' . ($et !== '' ? $et : 'Alan') . ' çatışması: ' . $v($o['eski']) . ' ≠ ' . $v($o['yeni']);
        case 'belge_eklendi':
            return '📄 Yeni belge' . ($ac !== '' ? ': ' . $kes($ac) : '');
        case 'belge_silindi':
            return '🗑 Belge silindi' . (trim((string)($o['eski'] ?? '')) !== '' ? ': ' . $kes((string)$o['eski']) : '');
        case 'geri_getirildi':
            return '↺ Silinmiş sevkiyat yeni belgeyle geri geldi';
    }
    return '';
}

/**
 * v426 — satır/kart için gizli değişim verisi: JS son bakıştan eski satırları atar, kalan varsa rozeti ("●N") sipariş no'nun soluna taşır.
 * Veri yoksa ''. Metin h() ile kaçırılır; JS textContent ile basar.
 */
function sipui_degisim_html(array $sev, array $harita): string
{
    $l = $harita[(int)($sev['id'] ?? 0)] ?? [];
    if (!$l) return '';
    $o = '<span class="sip-dg-kap" hidden><span class="sip-dg" data-sip-balon tabindex="0" role="status"></span><span class="sip-balon-veri" hidden><span data-baslik="">';
    foreach ($l as $x) $o .= '<span data-satir data-t="' . h($x['t']) . '">' . h($x['m']) . '</span>';
    return $o . '</span></span></span>';
}

/** v396 — sevkiyatın güncel PKL dosya adı (oluşturulan/yüklenen); parti no'nun ÜSTÜNDE. PKL yoksa boş. */
function sipui_pkl_ad_html(array $sev, array $pkl): string
{
    $sid = (int)($sev['id'] ?? 0);
    $ad  = trim((string)($pkl[$sid]['ad'] ?? ''));
    if ($ad === '') return '';
    $bid = (int)($pkl[$sid]['belge_id'] ?? 0);
    // tire/alt çizgi sonrası <wbr>: dar sütunda ad "PKL-…-" / "AsyaDocNNN-" / "tarih.xlsx" parçalarından kırılır, tabloyu genişletmez
    $ic  = '<span class="sip-pkl-ik" aria-hidden="true">📄</span><span class="sip-pkl-metin">' . preg_replace('~([-_])~', '$1<wbr>', h($ad)) . '</span>';
    return $bid > 0
        ? '<a class="sip-pkl-ad" href="siparis_dosya.php?id=' . $bid . '&amp;dl=1" title="Oluşturulan PKL: ' . h($ad) . ' (indir)">' . $ic . '</a>'
        : '<div class="sip-pkl-ad" title="Oluşturulan PKL: ' . h($ad) . '">' . $ic . '</div>';
}

/** Tur no — YALNIZ bağlı beyan parti no'su yoksa (parti varsa rozetleri sipariş no'nun üstünde gösterilir). */
function sipui_tur_html(array $sev, array $parti, bool $satir = true): string
{
    $sid = (int)($sev['id'] ?? 0);
    if (isset($parti[$sid]) && (string)($parti[$sid]['parti'] ?? '') !== '') return '';
    $tur = trim((string)($sev['tur_no'] ?? ''));
    if ($tur === '') return '';
    $ic = '<span>Tur ' . h($tur) . '</span>';
    return $satir ? '<div class="sip-soluk nowrap sip-turparti">' . $ic . '</div>' : '<span class="sip-soluk sip-turparti"> · ' . $ic . '</span>';
}

/**
 * v394 — TEK kalem süzgeci + parçaları. Liste ürün satırı (sipui_kalem_satirlari), yazdırma kalibre sütunları + ürün adları (siparis_yazdir_kalibreler / _kalibre_palet / _urunler; v403)
 * ve WP kartı (siparis_wp_veri) AYNI kuralı buradan alır — süzgeci başka yere KOPYALAMA.
 * null = kalem listede gösterilmez: (a) hiç gönderilmeyecek (palet sayısal 0 VE etkin koli 0/boş) ya da
 * (b) metni (açıklama / ürün+kalibre / çeşit / koli içeriği) da sayısal palet/PF'si de yok.
 * palet: etkin_palet doluysa o, yoksa po_palet (ham; null olabilir) · koli: etkin_koli (ham; po_koli YEDEĞİ YOK).
 * ad/meta: liste metni (değişmedi) · urun: açıklama (yoksa ürün) · çeşit · koli içeriği — kalibre AYRI alanda.
 * @return array{palet:mixed,koli:mixed,ad:string,meta:string,urun:string,kalibre:string}|null
 */
function sipui_kalem_coz(array $k): ?array
{
    $pal = ($k['etkin_palet'] ?? null) !== null && $k['etkin_palet'] !== '' ? $k['etkin_palet'] : ($k['po_palet'] ?? null);
    $koli = $k['etkin_koli'] ?? null;
    if ($pal !== null && is_numeric($pal) && (int)$pal === 0 && ($koli === null || $koli === '' || (int)$koli === 0)) return null;
    $aciklama = trim((string)($k['aciklama'] ?? ''));
    $urun     = trim((string)($k['urun'] ?? ''));
    $kalibre  = trim((string)($k['kalibre'] ?? ''));
    $cesit    = trim((string)($k['cesit'] ?? ''));
    $icerik   = trim((string)($k['koli_icerik'] ?? ''));
    $ad = $aciklama !== '' ? $aciklama : trim($urun . ' ' . $kalibre);
    $parca = array_values(array_filter([$ad, $cesit, $icerik], fn($x) => $x !== ''));
    $meta = [];
    if ($pal !== null && is_numeric($pal)) $meta[] = 'Pal. ' . sipui_sayi($pal);
    if (($k['pf'] ?? null) !== null && is_numeric($k['pf'])) $meta[] = 'PF ' . sipui_sayi($k['pf']);
    if (!$parca && !$meta) return null;
    $urunParca = array_values(array_filter([$aciklama !== '' ? $aciklama : $urun, $cesit, $icerik], fn($x) => $x !== ''));
    return ['palet' => $pal, 'koli' => $koli, 'ad' => implode(' · ', $parca), 'meta' => implode(' · ', $meta),
            'urun' => implode(' · ', $urunParca), 'kalibre' => $kalibre];
}

/**
 * Liste için ürün satırları (PO "Article specification" + palet + PF). $kalemler: siparis_kalemler satırları
 * (sira sırasıyla). Palet: etkin_palet varsa o, yoksa po_palet. Hiç gönderilmeyecek kalem (etkin palet 0 ve etkin koli 0/boş)
 * atlanır. En çok $max (varsayılan 5, v450) satır; 6. ve sonrası "+N daha". Süzgeç: sipui_kalem_coz().
 * @return array{satirlar:list<array{ad:string,meta:string}>,fazla:int}
 */
function sipui_kalem_satirlari(array $kalemler, int $max = 5): array
{
    $tum = [];
    foreach ($kalemler as $k) {
        $c = sipui_kalem_coz($k);
        if ($c === null) continue;
        $tum[] = ['ad' => $c['ad'], 'meta' => $c['meta']];
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
        'evrak_gonderildi' => ['📦', 'Evrak takımı gönderildi olarak işaretlendi'],
        'beyan_baglandi' => ['🏛', 'Beyan bağlandı'], 'beyan_kaldirildi' => ['🏛', 'Beyan bağı kaldırıldı'], 'geri_getirildi' => ['♻', 'Sevkiyat geri getirildi'],
        'silindi' => ['🗑', 'Sevkiyat silindi'], 'uyari' => ['⚠', 'Uyarı'],
        'invoice_uretildi' => ['🧾', 'Invoice üretildi'],
    ];
}

/** Olay satırı → düz metin cümle (HTML DEĞİL; çağıran h() ile kaçırır). */
function sipui_olay_cumle(array $o): string
{
    $et = sipui_olay_etiketleri()[(string)$o['tur']] ?? ['•', (string)$o['tur']];
    $dd = siparis_durumlar();
    $eski = (string)($o['eski'] ?? ''); $yeni = (string)($o['yeni'] ?? '');
    if (in_array($o['tur'], ['durum', 'mail_gonderildi', 'evrak_gonderildi'], true)) {
        $eski = $dd[$eski]['etiket'] ?? $eski; $yeni = $dd[$yeni]['etiket'] ?? $yeni;
        $s = $et[1] . ($eski !== '' || $yeni !== '' ? ': ' . ($eski !== '' ? $eski . ' → ' : '') . $yeni : '');
    } else {
        $s = $et[1];
        if (($o['aciklama'] ?? '') === '' && ($eski !== '' || $yeni !== '')) $s .= ': ' . ($eski !== '' ? $eski . ' → ' : '') . $yeni;
    }
    $ac = trim((string)($o['aciklama'] ?? ''));
    return $ac !== '' ? $s . (in_array($o['tur'], ['durum', 'mail_gonderildi', 'evrak_gonderildi'], true) ? ' · ' : ' — ') . $ac : $s;
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
        'order_ref_missing' => 'Sipariş numarası okunamadı', 'orphan_rows' => "PO'da siparişe bağlanamayan kalem satırı", 'continuation_merged' => 'Sayfa devamı önceki siparişe eklendi', 'ignored_line' => 'Yok sayılan satır', 'exception' => 'Ayrıştırma hatası',
    ];
    if (isset($m[$kod])) return $m[$kod];
    if (str_starts_with($kod, 'check_')) return 'Tutarlılık denetimi tutmadı';
    return null;
}

/**
 * Yükleme sonucu hücre metni (HTML; kullanıcı verisi h() ile kaçırılır). siparis_yukle.php oturum satırı ve
 * mailden alma (sipui_mail_sonuc_html) AYNI metni kullanır — v378'de closure'dan çıkarıldı, davranış aynı.
 */
function sipui_sonuc_metni(array $r): string
{
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
    if (!empty($r['yeniden'])) {   // v375: aynı dosya eski okuyucuyla okunmuştu
        $o = '<div class="sip-soluk">Zaten yüklüydü — yeni okuyucuyla (' . h((string)($r['eski_surum'] ?? '?')) . ' → ' . h(SIPARIS_PDF_SURUM) . ') yeniden okundu, sevkiyat değerleri güncellendi.</div>' . $o;
    }
    foreach ((array)($r['mesajlar'] ?? []) as $m) {
        $kirmizi = str_contains((string)$m, 'içeriyor; o sevkiyata bağlandı');
        $o .= '<div class="' . ($kirmizi ? 'sip-hata-metin' : 'sip-soluk') . ' sip-sonuc-mesaj">' . h((string)$m) . '</div>';
    }
    return $o;
}

/** Yükleme sonuç özeti — satır başına [dosya, tür, sonuç, çatışma, uyarı] (siparis_yukle.php oturuma yazar). */
function sipui_yukleme_sonuc_html(array $sonuclar): string
{
    if (!$sonuclar) return '';
    $sonucMetni = 'sipui_sonuc_metni';
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
function sipui_alan_gruplari(bool $sofor = false, bool $yurtici = false): array
{
    return [
        'Sevkiyat' => ['tur_no', 'yukleme_tarihi', 'yukleme_yeri', 'bosaltma_tarihi', 'bosaltma_yerleri'],
        // v377: elle girilen şoför adı + telefon plakaların altında (yalnız şema hazırsa — siparis_sofor_hazir())
        'Araç ve navlun' => $sofor ? ['nakliyeci', 'plakalar', 'sofor_adi', 'sofor_telefon', 'navlun_no'] : ['nakliyeci', 'plakalar', 'navlun_no'],
        'Alıcı' => ['alici_kodu', 'alici_adi', 'sold_to'],
        // v387: yurt içi gümrük (elle) yurt dışı gümrüğün (PO) üstünde — yalnız şema hazırsa (siparis_yurtici_gumruk_hazir())
        'Gümrük' => $yurtici ? ['yurtici_gumruk', 'gumruk', 'kdv_no', 'incoterm'] : ['gumruk', 'kdv_no', 'incoterm'],
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

// ── MAİLDEN AL (v378): düğmeler + sonuç — veri config/siparis_mail.php'den gelir; burada yalnız biçimleme ──

/** Sonuç durumu → Türkçe kısa ad (sayaç/rozet). */
function sipui_mail_durum_adi(string $durum): string
{
    return ['alindi' => 'Alındı', 'zaten' => 'Zaten vardı', 'onceden' => 'Daha önce alınmış', 'baska_tur' => 'Başka tür', 'taninmadi' => 'Tanınmadı',
            'gecersiz' => 'Geçersiz', 'silinmis' => 'Silinmiş', 'cok_buyuk' => 'Çok büyük', 'iletilmis' => 'İletilmiş', 'ek_eksik' => 'Ek eksik', 'ek_yok' => 'Sunucuda yok', 'hata' => 'Hata'][$durum] ?? $durum;
}

/**
 * Mail sonuç satırının "Sonuç" hücresi (HTML). $belgeGorunur: belge_id → bu kullanıcıya görünür mü (görünmeyene bağlantı verilmez).
 * Alındı / Zaten metni elle yüklemeyle AYNI (sipui_sonuc_metni).
 */
function sipui_mail_durum_html(array $s, array $belgeGorunur = []): string
{
    $durum = (string)($s['durum'] ?? '');
    $belge = (int)($s['belge_id'] ?? 0);
    $belgeBag = $belge > 0 && !empty($belgeGorunur[$belge]) ? '<a href="siparis_belge.php?id=' . $belge . '">Belge #' . $belge . '</a>' : ($belge > 0 ? 'Belge #' . $belge : '');
    $kopya = (int)($s['kopya'] ?? 1) > 1 ? '<div class="sip-soluk">(' . (int)$s['kopya'] . ' posta kutusunda; bir kez alındı)</div>' : '';
    switch ($durum) {
        case 'alindi':
            return '<strong class="sip-mail-ok">Alındı</strong><div>' . sipui_sonuc_metni(['durum' => 'islendi'] + $s) . '</div>' . $kopya;
        case 'zaten':
            $l = [];
            foreach ((array)($s['sevkiyatlar'] ?? []) as $sv) $l[] = '<a href="siparis_view.php?id=' . (int)$sv['id'] . '">' . h((string)$sv['order_ref']) . '</a>';
            return 'Zaten vardı (daha önce yüklenmiş)' . ($l ? ' · ' . implode(', ', $l) : '') . $kopya;
        case 'onceden':
            return 'Daha önce mailden alındı' . ($belgeBag !== '' ? ' · ' . $belgeBag : '') . $kopya;
        case 'baska_tur':
            $t = (string)($s['tur'] ?? '');
            $bu = siparis_mail_belge_turu($t);
            return 'Başka tür: ' . h(siparis_belge_tur_etiketleri()[$t] ?? $t) . ($bu !== null ? ' — «' . h(siparis_mail_turleri()[$bu]['etiket']) . ' sorgula» ile alınır.' : '.');
        case 'taninmadi':
            return 'Belge türü tanınmadı — alınmadı. Gerekirse elle yükleyin.';
        case 'gecersiz':
            $n = trim((string)($s['hata'] ?? ''));
            return 'Geçerli bir PDF değil / PDF okunamadı (bozuk ya da şifreli) — alınmadı.' . ($n !== '' ? '<div class="sip-soluk">' . h($n) . '</div>' : '');
        case 'silinmis':
            return 'Bu belge daha önce silinmiş — mailden geri getirilmedi. Gerekirse elle yükleyin.';
        case 'cok_buyuk':
            return 'Ek çok büyük (en çok ' . (int)(SIPARIS_MAX_DOSYA / 1048576) . ' MB) — posta kutusundan indirip elle yükleyin.';
        case 'iletilmis':
            return 'Ek olarak iletilmiş mail — bu sürümde açılmıyor; PDF\'i elle yükleyin.';
        case 'ek_eksik':
            return 'Mail tam okunamadı (çok büyük ya da bozuk); ek listesi eksik — posta kutusundan indirip elle yükleyin.';
        case 'ek_yok':
            return 'Ek posta sunucusunda bulunamadı (silinmiş ya da klasör yenilenmiş) — tekrar denenmez. Gerekirse elle yükleyin.';
        default:
            return '<span class="sip-hata-metin">Hata: ' . h((string)($s['hata'] ?? 'işlenemedi')) . '</span><div class="sip-soluk">Sonraki sorguda yeniden denenir.</div>';
    }
}

/**
 * Belgenin ayrıştırıcı uyarıları (parse_json.warnings, seviye warn/error) → okunur metin listesi ("Etiket — ayrıntı").
 * Sayı (uyari_sayisi) ile aynı süzgeç; belge sayfasındaki "Denetimler ve uyarılar" tablosunun metni.
 * @return list<string>
 */
function sipui_belge_uyari_metinleri(?string $parseJson, int $en = 8): array
{
    if ($parseJson === null || $parseJson === '') return [];
    $pj = json_decode($parseJson, true);
    $out = [];
    foreach ((array)($pj['warnings'] ?? []) as $w) {
        if (!is_array($w) || !in_array($w['level'] ?? '', ['warn', 'error'], true)) continue;
        $kod = (string)($w['code'] ?? '');
        $et = sipui_uyari_kodu_etiketi($kod);
        $msg = trim((string)($w['message'] ?? ''));
        $out[] = $et !== null ? ($msg !== '' ? $et . ' — ' . $msg : $et) : ($msg !== '' ? $msg : $kod);
        if (count($out) >= $en) break;
    }
    return $out;
}

/** Sonuç satırındaki uyarı sayısının altına açılır liste (sayı > 0 ve metin varsa). */
function sipui_mail_uyari_detay_html(array $s, array $uyariMetin): string
{
    $b = (int)($s['belge_id'] ?? 0);
    $n = (int)($s['uyari'] ?? 0);
    $l = $b > 0 ? ($uyariMetin[$b] ?? []) : [];
    if ($n <= 0 || !$l) return '';
    $o = '<details class="sip-ms-uyari"><summary>⚠ ' . $n . ' uyarı — göster</summary><ul>';
    foreach ($l as $m) $o .= '<li>' . h($m) . '</li>';
    return $o . '</ul><a href="siparis_belge.php?id=' . $b . '#sipBKontrol">Belgede aç</a></details>';
}

/**
 * Mail sorgusu sonuç kartı (SUNUCUDA, tek render: ilk açılış ve JS 'bitti' yanıtı aynı HTML'i kullanır).
 * Gönderen / konu / mail tarihi mail_messages'tan YALNIZ $hesapIds (izleyenin görünür hesapları) içindeki mailler için okunur;
 * görünmüyorsa "—". Kullanıcı verisinin tamamı h() ile kaçırılır.
 * @param array $son siparis_mail_son() / siparis_mail_bitir() kaydı  @param list<int> $hesapIds
 */
function sipui_mail_sonuc_html(PDO $pdo, array $son, array $hesapIds): string
{
    $turler = siparis_mail_turleri();
    $tur = (string)($son['tur'] ?? '');
    $etiket = $turler[$tur]['etiket'] ?? $tur;
    $satirlar = array_values(array_filter((array)($son['satirlar'] ?? []), 'is_array'));
    $mailIds = array_values(array_unique(array_map(static fn(array $s): int => (int)$s['m'], $satirlar)));
    $mailler = [];
    $hesapIds = array_values(array_map('intval', $hesapIds));
    if ($mailIds && $hesapIds) {
        foreach (array_chunk($mailIds, 300) as $parca) {
            $st = $pdo->prepare('SELECT id, account_id, from_name, from_addr, subject, received_at FROM mail_messages WHERE id IN (' . implode(',', array_fill(0, count($parca), '?')) . ') AND account_id IN (' . implode(',', array_fill(0, count($hesapIds), '?')) . ')');
            $st->execute(array_merge($parca, $hesapIds));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) $mailler[(int)$m['id']] = mail_gorunum_charset_duzelt($m, ['subject', 'from_name']);
        }
    }
    $belgeGorunur = [];
    $uyariMetin = [];   // belge_id → uyarı metinleri (yalnız görünür belge + uyarılı satır)
    foreach ($satirlar as $s) {
        $b = (int)($s['belge_id'] ?? 0);
        if ($b <= 0 || isset($belgeGorunur[$b])) continue;
        $q = $pdo->prepare('SELECT id, depo, deleted_at, parse_json FROM siparis_belgeler WHERE id = ?');
        $q->execute([$b]);
        $bs = $q->fetch(PDO::FETCH_ASSOC);
        $belgeGorunur[$b] = $bs && $bs['deleted_at'] === null && siparis_belge_gorunur($pdo, $bs);
        if ($belgeGorunur[$b] && (int)($s['uyari'] ?? 0) > 0) $uyariMetin[$b] = sipui_belge_uyari_metinleri((string)($bs['parse_json'] ?? ''));
    }
    $sy = (array)($son['sayac'] ?? []);
    $bitis = (int)($son['bitis'] ?? 0);
    $baslik = 'Mail sorgusu — ' . $etiket . ' · son ' . SIPARIS_MAIL_GUN . ' gün' . ($bitis > 0 ? ' · ' . date('d.m.Y H:i', $bitis) : '') . ' · ' . siparis_mail_kullanici_adi($pdo, (int)($son['uid'] ?? 0));
    $chip = static fn(string $et, int $say, string $sinif = ''): string => '<span class="sip-mail-chip' . ($sinif !== '' ? ' sip-mail-chip--' . $sinif : '') . '"><span class="sip-mail-chip-et">' . h($et) . '</span> <b>' . $say . '</b></span>';
    $o = '<section class="card sip-mail-sonuc-kart" data-sip-mail-sonuc-kart aria-label="Mail sorgusu sonucu"><div class="card-head"><h2>' . h($baslik) . '</h2></div>';
    $o .= '<div class="sip-mail-ozet">' . $chip('Bulunan mail', (int)($sy['mail'] ?? 0)) . $chip('PDF', (int)($sy['pdf'] ?? 0)) . $chip('Alındı', (int)($sy['alindi'] ?? 0), 'ok')
        . $chip('Zaten vardı', (int)($sy['zaten'] ?? 0)) . $chip('Daha önce alınmış', (int)($sy['onceden'] ?? 0)) . $chip('Atlandı', (int)($sy['atlandi'] ?? 0), ((int)($sy['atlandi'] ?? 0)) > 0 ? 'uyari' : '')
        . $chip('Hata', (int)($sy['hata'] ?? 0), ((int)($sy['hata'] ?? 0)) > 0 ? 'hata' : '') . '</div>';
    if ((int)($son['pdfsiz'] ?? 0) > 0) $o .= '<p class="sip-soluk">PDF eki olmayan ' . (int)$son['pdfsiz'] . ' mail</p>';
    foreach ((array)($son['uyarilar'] ?? []) as $u) $o .= '<p class="sip-bilgi" role="note">' . h((string)$u) . '</p>';
    if (!$satirlar) return $o . '<p class="muted">Bu pencerede işlenecek ek bulunamadı.</p></section>';

    $onceden = count(array_filter($satirlar, static fn(array $s): bool => ($s['durum'] ?? '') === 'onceden'));
    if ($onceden > 0) {
        $o .= '<p><button type="button" class="btn btn-sm" data-sip-mail-onceden aria-pressed="false">Daha önce alınmış ' . $onceden . ' satırı göster</button></p>';
    }
    $mailBilgi = static function (int $mid) use ($mailler): array {
        $m = $mailler[$mid] ?? null;
        if ($m === null) return ['ad' => '—', 'konu' => '', 'tarih' => '—', 'kisa' => '—', 'link' => false];
        $t = strtotime((string)$m['received_at']);
        return ['ad' => mail_gonderen_adi($m), 'konu' => trim((string)($m['subject'] ?? '')), 'tarih' => $t !== false ? date('d.m.Y H:i', $t) : '—', 'kisa' => $t !== false ? date('d.m H:i', $t) : '—', 'link' => true];
    };
    $sat = static fn(array $s): string => ((string)($s['durum'] ?? '') === 'onceden' ? ' sip-mail-onceden' : '');
    $o .= '<div class="table-wrap pc-only sip-mail-tablo-kap"><table class="data-table sip-table sip-mail-tablo"><thead><tr><th>Mail tarihi</th><th>Gönderen</th><th>Dosya</th><th>Tür</th><th>Sonuç</th><th class="num">Çatışma</th><th class="num">Uyarı</th></tr></thead><tbody>';
    foreach ($satirlar as $s) {
        $mi = $mailBilgi((int)$s['m']);
        $konu = $mi['konu'] !== '' ? '<div class="sip-soluk sip-kes" title="' . h($mi['konu']) . '">' . h(siparis_kirp($mi['konu'], 80)) . '</div>' : '';
        $o .= '<tr class="sip-ms-satir' . $sat($s) . '" data-durum="' . h((string)$s['durum']) . '"><td class="nowrap">' . h($mi['tarih']) . '</td><td><span class="sip-kes">' . h($mi['ad']) . '</span>' . $konu . '</td>'
            . '<td><span class="sip-kes" title="' . h((string)$s['dosya']) . '">' . h((string)$s['dosya']) . '</span>'
            . ($mi['link'] ? '<div class="sip-soluk"><a href="mail.php?m=' . (int)$s['m'] . '">Maili aç</a></div>' : '') . '</td>'
            . '<td>' . (empty($s['tur']) ? '—' : sipui_tur_rozeti((string)$s['tur'])) . '</td><td>' . sipui_mail_durum_html($s, $belgeGorunur) . sipui_mail_uyari_detay_html($s, $uyariMetin) . '</td>'
            . '<td class="num">' . (int)($s['catisma'] ?? 0) . '</td><td class="num">' . (int)($s['uyari'] ?? 0) . '</td></tr>';
    }
    $o .= '</tbody></table></div><div class="card-list mobile-only sip-mail-kartlar">';
    foreach ($satirlar as $s) {
        $mi = $mailBilgi((int)$s['m']);
        $o .= '<div class="sip-mini-kart sip-ms-satir' . $sat($s) . '" data-durum="' . h((string)$s['durum']) . '"><div class="sip-mini-ust"><strong class="sip-kes" title="' . h((string)$s['dosya']) . '">' . h((string)$s['dosya']) . '</strong>'
            . (empty($s['tur']) ? '' : sipui_tur_rozeti((string)$s['tur'])) . '</div><div class="sip-soluk">' . h($mi['kisa']) . ' · ' . h($mi['ad']) . '</div><div>' . sipui_mail_durum_html($s, $belgeGorunur) . '</div>' . sipui_mail_uyari_detay_html($s, $uyariMetin);
        if ((int)($s['catisma'] ?? 0) > 0 || (int)($s['uyari'] ?? 0) > 0) $o .= '<div class="sip-soluk">Çatışma: ' . (int)($s['catisma'] ?? 0) . ' · Uyarı: ' . (int)($s['uyari'] ?? 0) . '</div>';
        $o .= ($mi['link'] ? '<div class="sip-soluk"><a href="mail.php?m=' . (int)$s['m'] . '">Maili aç</a></div>' : '') . '</div>';
    }
    return $o . '</div></section>';
}

/**
 * "📧 Mailden al" bölümü (siparisler.php #yukle kartının üstü). $d:
 *  hazir (bool: kurulu), ayarli (tur → bool), ayar_link (bool: ayar sayfasına bağlantı verilebilir), mail_admin (bool),
 *  yarim (?array siparis_mail_yarim), son (list<array{tur,bitis,html}> yeniden eskiye).
 * Mail şeması kurulu değilse düğmeler HİÇ çizilmez; yalnız mail yöneticisine kısa not.
 */
function sipui_mail_blok_html(array $d): string
{
    if (empty($d['hazir'])) {
        return !empty($d['mail_admin']) ? '<p class="sip-soluk sip-mail-kurulmadi">📧 Mailden al: Mail Merkezi / hks_kv kurulu değil (yönetici).</p>' : '';
    }
    $turler = siparis_mail_turleri();
    $herhangiAyarsiz = false;
    $dugmeler = '';
    foreach ($turler as $t => $m) {
        $ayarli = !empty($d['ayarli'][$t]);
        if (!$ayarli) $herhangiAyarsiz = true;
        $dugmeler .= '<button type="button" class="btn sip-mail-btn sip-mail-btn--' . h($t) . '" data-sip-mail="' . h($t) . '"' . ($ayarli ? '' : ' disabled title="Bu sorgu için gönderen adresi ayarlanmadı"') . '>' . h($m['dugme']) . '</button>';
    }
    $o = '<div class="sip-mail" id="mail" data-sip-mail-kap data-sip-mail-url="siparis_mail.php" data-sip-mail-csrf="' . h(csrf_token()) . '">';
    $o .= '<div class="sip-mail-baslik"><h2>📧 Mailden al</h2><p class="sip-soluk">Son ' . SIPARIS_MAIL_GUN . ' günün mailleri taranır; belge türü içerikten anlaşılır.</p></div>';
    $o .= '<div class="sip-mail-dugmeler" role="group" aria-label="Mailden belge al">' . $dugmeler . '</div>';
    if ($herhangiAyarsiz) {
        $o .= !empty($d['ayar_link'])
            ? '<p class="sip-soluk sip-mail-not">Ayarlanmamış sorgular için <a href="siparis_ayarlar.php#mail">Ayarlar › 📧 Mail sorgu ayarları</a>.</p>'
            : '<p class="sip-soluk sip-mail-not">Mail sorgusu henüz ayarlanmadı (yönetici).</p>';
    }
    if (!empty($d['yarim']) && is_array($d['yarim'])) {
        $y = $d['yarim'];
        $o .= '<div class="sip-bilgi sip-mail-yarim" data-sip-mail-devam="' . h((string)$y['tarama_id']) . '" data-sip-mail-tur="' . h((string)$y['tur']) . '" role="note">Yarım kalan mail sorgusu ('
            . h($turler[$y['tur']]['etiket'] ?? (string)$y['tur']) . ', ' . (int)$y['i'] . '/' . (int)$y['toplam'] . ') — '
            . '<button type="button" class="btn btn-sm" data-sip-mail-devam-et>Devam et</button> <button type="button" class="btn btn-sm btn-ghost" data-sip-mail-vazgec>Vazgeç</button></div>';
    }
    $o .= '<div class="sip-mail-ilerleme" data-sip-mail-ilerleme hidden aria-live="polite"><progress max="1" value="0" aria-label="Mail sorgusu ilerlemesi"></progress><span data-sip-mail-ilerleme-metin></span>'
        . '<span class="sip-mail-ilerleme-dugme"><button type="button" class="btn btn-sm" data-sip-mail-devam-et hidden>Devam et</button> <button type="button" class="btn btn-sm btn-ghost" data-sip-mail-vazgec hidden>Vazgeç</button></span></div>';
    $o .= '<p class="sip-hata-metin" data-sip-mail-hata hidden role="alert"></p>';
    $o .= '<div class="sip-mail-sonuc" data-sip-mail-sonuc aria-live="polite">';
    foreach ((array)($d['son'] ?? []) as $i => $s) {
        if ($i === 0) $o .= (string)$s['html'];
        else $o .= '<details class="sip-mail-onceki"><summary>Önceki sorgu: ' . h($turler[$s['tur']]['etiket'] ?? (string)$s['tur']) . ($s['bitis'] > 0 ? ' · ' . h(date('d.m H:i', (int)$s['bitis'])) : '') . '</summary>' . (string)$s['html'] . '</details>';
    }
    return $o . '</div></div>';
}

/**
 * v382 — "Belge yükle" kartı (native <details>) özet satırı. Kapalıyken de son mail sorgusunu / yarım taramayı gösterir.
 * @param list<array{tur:string,bitis:int,sayac?:array}> $son yeniden eskiye (siparisler.php $mSon)  @param ?array $yarim siparis_mail_yarim()
 * Saf: DB/çıktı yok; kullanıcı verisi h() ile kaçırılır (burada yalnız sabit etiket + sayılar).
 */
function sipui_yukle_ozet_html(array $son, ?array $yarim, bool $mailVar): string
{
    $etiket = ['siparis' => 'Sipariş', 'lojistik' => 'Lojistik'];
    $o = '<span class="sip-yukle-baslik">📥 Belge yükle' . ($mailVar ? ' · 📧 Mailden al' : '') . '</span>';
    if ($yarim !== null) $o .= '<span class="sip-yukle-son sip-yukle-son--hata">⏸ Yarım tarama var</span>';
    if ($mailVar && $son) {
        $s = $son[0];
        $sy = (array)($s['sayac'] ?? []);
        $parca = ['Son sorgu: ' . ($etiket[(string)($s['tur'] ?? '')] ?? (string)($s['tur'] ?? ''))];
        if ((int)($s['bitis'] ?? 0) > 0) $parca[] = date('d.m H:i', (int)$s['bitis']);
        if (isset($sy['alindi'])) $parca[] = (int)$sy['alindi'] . ' alındı';
        $hata = (int)($sy['hata'] ?? 0);
        if ($hata > 0) $parca[] = $hata . ' hata';
        $o .= '<span class="sip-yukle-son' . ($hata > 0 ? ' sip-yukle-son--hata' : '') . '">' . h(implode(' · ', $parca)) . '</span>';
    }
    return $o . '<span class="sip-yukle-mesgul" data-sip-yukle-mesgul hidden role="status">Tarama sürüyor</span><span class="sip-yukle-ok" aria-hidden="true">▸</span>';
}
