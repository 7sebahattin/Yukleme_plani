<?php
// =========================================================
// config/frugro_ui.php — Frugro Siparişler EKRAN yardımcıları (include-only; frugro_core.php'yi tamamlar)
//
// Yetki: yeni yetki YOK. Okuma = require_siparis('read') (OGL ile aynı kapı); yazma/silme OGL sayfasının
// kapısıyla aynı kuralla (can_siparis + role_matrix_page_can('siparis','ogl',eylem)) — kapı TEK yerde: frgui_yetki().
// Para birimleri ASLA toplanmaz; tutar yalnız tek sipariş içinde (kutu × fiyat) hesaplanır.
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

/** Eylem kapısı. $eylem: read | create | edit | delete. */
function frgui_yetki(string $eylem): bool
{
    $perm = ['read' => 'read', 'create' => 'write', 'edit' => 'write', 'delete' => 'delete'][$eylem] ?? null;
    if ($perm === null || !can_siparis($perm)) return false;
    return role_matrix_page_can('siparis', 'ogl', $eylem);
}

/** Durum → CSS son eki (`sip-badge--<css>`); bilinmeyen kod "alindi" görünür. */
function frgui_durum_css(string $d): string
{
    return (string)(frg_durum_tanimlari()[$d]['css'] ?? 'alindi');
}

/** Durum rozeti: OGL ile AYNI işaretleme (`sip-badge`; ikon + metin — renge tek başına güvenilmez). Bilinmeyen kod ham yazılır. */
function frgui_durum_badge(string $d): string
{
    $t = frg_durum_tanimlari()[$d] ?? null;
    if ($t === null) return '<span class="sip-badge sip-badge--bilinmiyor">' . h($d) . '</span>';
    return '<span class="sip-badge sip-badge--' . h($t['css']) . '">' . $t['ikon'] . ' ' . h($t['etiket']) . '</span>';
}

/** Eski ad (detay sayfası): takma ad. */
function frgui_durum_rozet(string $d): string
{
    return frgui_durum_badge($d);
}

/** Çıkış tarihi bugüne göre renk (OGL v376 eşiği): geçmiş → yeşil kalın, bugün → turuncu kalın, ileri → normal. */
function frgui_tarih_html(?string $t, ?string $bugun = null): string
{
    $t = (string)$t;
    if ($t === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $t)) return '<span class="frg-soluk">—</span>';
    $t = substr($t, 0, 10);
    $bugun = $bugun ?? date('Y-m-d');
    $sinif = $t < $bugun ? 'gecmis' : ($t === $bugun ? 'bugun' : 'gelecek');
    return '<span class="frg-tar frg-tar--' . $sinif . '">' . h(date('d.m.Y', (int)strtotime($t))) . '</span>';
}

function frgui_tam($n): string
{
    return $n === null || $n === '' ? '—' : number_format((int)$n, 0, ',', '.');
}

/** Kuruş → "12.345,60". */
function frgui_para(int $kurus): string
{
    return number_format($kurus / 100, 2, ',', '.');
}

/**
 * Tutar hücresi. Fiyat yoksa "—"; para birimi atanmamışsa "GBP/EUR seçilecek" (tutar o zamana dek para birimsiz,
 * sayı gösterilmez — yanlış birimle yorumlanmasın). Para birimleri toplanmaz.
 */
function frgui_tutar_html(array $s): string
{
    $h = frg_hesap($s);
    if (!$h['fiyat_var']) return '<span class="frg-soluk">—</span>';
    if ($h['para_birimi'] === null) return '<span class="frg-pb-bekle">GBP/EUR seçilecek</span>';
    return h(frgui_para($h['tutar_kurus'])) . ' ' . h($h['para_birimi']);
}

/** Düzenle penceresindeki alanlar: ad → [tür, etiket, azami uzunluk]. TEK beyaz liste (sunucu da bunu kullanır). */
function frgui_duzenle_alanlari(): array
{
    return [
        'cikis_tarihi' => ['tarih', 'Çıkış tarihi', 0], 'varis_tarihi' => ['tarih', 'Varış tarihi', 0], 'varis_saati' => ['metin', 'Varış saati', 30],
        'ihracatci' => ['metin', 'İhracatçı', 150], 'paketlemeci' => ['metin', 'Paketlemeci', 150], 'giris_limani' => ['metin', 'Giriş limanı', 150],
        'teslim_adresi' => ['uzun', 'Teslim adresi', 1000], 'son_musteri' => ['metin', 'Son müşteri', 150], 'son_musteri_po' => ['metin', 'Müşteri PO', 100],
        'urun' => ['metin', 'Ürün', 100], 'cesit' => ['metin', 'Çeşit', 100], 'mense' => ['metin', 'Menşe', 60], 'boy' => ['metin', 'Boy', 30],
        'fruit_agirlik_notu' => ['uzun', 'Meyve ağırlık notu', 1000], 'dis_paket' => ['metin', 'Dış paket', 255], 'ic_paket' => ['metin', 'İç paket', 255],
        'kutu' => ['tam', 'Kutu', 0], 'kutu_palet' => ['tam', 'Kutu / palet', 0], 'palet' => ['tam', 'Palet', 0], 'palet_tipi' => ['metin', 'Palet tipi', 60],
        'incoterm' => ['kod', 'Incoterm', 10], 'fiyat_kutu_birim' => ['fiyat', 'Fiyat (kutu başı)', 0], 'para_birimi' => ['pb', 'Para birimi', 3],
        'parti_no' => ['metin', 'Parti no', 30], 'plaka' => ['metin', 'Plaka', 80], 'sofor_adi' => ['metin', 'Şoför', 100], 'sofor_telefon' => ['metin', 'Şoför telefonu', 40],
        'nakliyeci' => ['metin', 'Nakliyeci', 150],
    ];
}

/**
 * Elle düzenleme girdisini doğrular (beyaz liste dışı alan SESSİZCE atılır).
 * @param array<string,mixed> $girdi
 * @return array{0:array<string,mixed>,1:list<string>} [DB'ye yazılacak değerler, hata mesajları]
 */
function frgui_duzenle_dogrula(array $girdi): array
{
    $temiz = []; $hata = [];
    foreach (frgui_duzenle_alanlari() as $alan => [$tur, $etiket, $max]) {
        if (!array_key_exists($alan, $girdi)) continue;
        $v = $girdi[$alan];
        if (is_array($v) || is_object($v)) { $hata[] = "$etiket: geçersiz değer."; continue; }
        $v = is_string($v) ? trim($v) : $v;
        $bos = $v === null || $v === '';
        switch ($tur) {
            case 'tarih':
                if ($bos) { $temiz[$alan] = null; break; }
                $t = frg_tarih((string)$v);
                if ($t === null) $hata[] = "$etiket: tarih geçersiz."; else $temiz[$alan] = $t;
                break;
            case 'tam':
                if ($bos) { $temiz[$alan] = null; break; }
                if (!preg_match('/^\d{1,9}$/', (string)$v)) { $hata[] = "$etiket: tam sayı olmalı."; break; }
                $temiz[$alan] = (int)$v;
                break;
            case 'fiyat':
                if ($bos) { $temiz[$alan] = null; break; }
                $f = frg_fiyat_birim((string)$v);
                if ($f === null || $f > 99999999999) $hata[] = "$etiket: sayı olmalı (örn. 4,60)."; else $temiz[$alan] = $f;
                break;
            case 'pb':
                $v = strtoupper((string)$v);
                if ($v === '') { $temiz[$alan] = null; break; }
                if (!in_array($v, ['GBP', 'EUR'], true)) $hata[] = "$etiket: GBP veya EUR olmalı."; else $temiz[$alan] = $v;
                break;
            case 'kod':
                $v = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', (string)$v));
                $temiz[$alan] = $v === '' ? null : substr($v, 0, $max);
                break;
            default: // metin | uzun
                $m = frg_metin((string)$v);
                if ($m !== null && mb_strlen($m) > $max) { $hata[] = "$etiket: en çok $max karakter."; break; }
                $temiz[$alan] = $m;
        }
    }
    return [$temiz, $hata];
}

// =========================================================
// v515 P1 — LİSTE yardımcıları (frugro_siparis.php + _frugro_liste.php). OGL listesinin (siparisler.php) eşi; İÇERİK Frugro'dan.
// _siparis_ortak.php (sipui_*) ÖNCEDEN yüklenmiş olmalı (sayfa require eder). Sorgu YALNIZ bu dosyadaki *_haritasi / frgui_bolum_oku /
// frgui_kpi_say içinde; _frugro_liste.php partial'ı sorgu çalıştırmaz. Para birimleri toplanmaz.
// =========================================================

/** Sayfa düzeyi "sanal" durum süzgeçleri (KPI çipleri): kod → etiket + SQL. Gerçek durum kodları frg_durum_tanimlari()'nde. */
function frgui_sanal_durumlar(): array
{
    return [
        'pkl_bekleyen'  => ['etiket' => 'PKL bekleyen',      'kodlar' => ['yeni', 'hazirlik']],
        'mail_bekleyen' => ['etiket' => 'Mail bekleyen',     'kodlar' => ['invoice_hazir', 'yola_cikti']],
        'pb_eksik'      => ['etiket' => 'Para birimi eksik', 'kodlar' => null],
    ];
}

/** Sanal durumun SQL koşulu (kullanıcı girdisi İÇERMEZ; kod beyaz listeden). */
function frgui_sanal_sql(string $kod): string
{
    $t = frgui_sanal_durumlar()[$kod] ?? null;
    if ($t === null) return '';
    if (is_array($t['kodlar'])) return frg_sql_in('durum', $t['kodlar'], false);
    return "fiyat_kutu_birim IS NOT NULL AND (para_birimi IS NULL OR para_birimi = '') AND " . frg_kapanmamis_sql();   // pb_eksik
}

/**
 * Liste süzgeci (ekran + yazdırma ucu ORTAK). durum: '' | acik | evrak | kapali (yalnız o bölüm) | gerçek kod | sanal kod (düz "Sonuçlar").
 * @return array<string,mixed>
 */
function frgui_filtre(array $g): array
{
    $dize = static fn(string $k, int $n): string => is_scalar($g[$k] ?? null) ? trim(mb_substr((string)$g[$k], 0, $n)) : '';
    $q = $dize('q', 80);
    $durum = $dize('durum', 30);
    $bolumler = ['acik', 'evrak', 'kapali'];
    if ($durum !== '' && !in_array($durum, $bolumler, true) && !isset(frg_durumlar()[$durum]) && !isset(frgui_sanal_durumlar()[$durum])) $durum = '';
    $tek = $durum !== '' && !in_array($durum, $bolumler, true);
    $bas = frg_tarih($dize('bas', 10)) ?? '';
    $bit = frg_tarih($dize('bit', 10)) ?? '';
    $musteri = $dize('musteri', 150);
    $liman = $dize('liman', 150);
    $ozet = [];
    if ($durum !== '') {
        $et = ['acik' => 'Açık olanlar', 'evrak' => 'Evrak takımı bekleyen', 'kapali' => 'Kapananlar'][$durum] ?? (frgui_sanal_durumlar()[$durum]['etiket'] ?? (frg_durumlar()[$durum] ?? $durum));
        $ozet[] = $et;
    }
    if ($bas !== '' || $bit !== '') $ozet[] = ($bas !== '' ? date('d.m', (int)strtotime($bas)) : '…') . '–' . ($bit !== '' ? date('d.m', (int)strtotime($bit)) : '…');
    if ($musteri !== '') $ozet[] = $musteri;
    if ($liman !== '') $ozet[] = $liman;
    return [
        'q' => $q, 'durum' => $durum, 'bas' => $bas, 'bit' => $bit, 'musteri' => $musteri, 'liman' => $liman,
        'tek' => $tek,
        'bolumAcik' => !$tek && ($durum === '' || $durum === 'acik'),
        'bolumEvrak' => !$tek && ($durum === '' || $durum === 'evrak'),
        'bolumKapali' => !$tek && ($durum === '' || $durum === 'kapali'),
        'filtreVar' => $q !== '' || $durum !== '' || $bas !== '' || $bit !== '' || $musteri !== '' || $liman !== '',
        'detayFiltre' => $durum !== '' || $bas !== '' || $bit !== '' || $musteri !== '' || $liman !== '',
        'detayOzet' => $ozet,
        'acik_page' => max(1, (int)($g['acik_page'] ?? 1)), 'evrak_page' => max(1, (int)($g['evrak_page'] ?? 1)), 'kapali_page' => max(1, (int)($g['kapali_page'] ?? 1)),
    ];
}

/** Liste adresi: mevcut GET'ten beyaz liste anahtarları + $override (null/'' = kaldır). `bolum` yalnız override'dan gelir. */
function frgui_url(array $override = [], string $sayfa = 'frugro_siparis.php', ?array $kaynak = null): string
{
    $kaynak = $kaynak ?? $_GET;
    $izin = ['q', 'durum', 'bas', 'bit', 'musteri', 'liman', 'acik_page', 'evrak_page', 'kapali_page', 'bolum'];
    $p = [];
    foreach (array_diff($izin, ['bolum']) as $k) {
        if (isset($kaynak[$k]) && is_scalar($kaynak[$k]) && trim((string)$kaynak[$k]) !== '') $p[$k] = trim((string)$kaynak[$k]);
    }
    foreach ($override as $k => $v) {
        if (!in_array($k, $izin, true)) continue;
        if ($v === null || $v === '') unset($p[$k]); else $p[$k] = (string)$v;
    }
    return $sayfa . ($p ? '?' . http_build_query($p) : '');
}

/**
 * WHERE (frg_liste_where + sanal durum). $kapsam: acik|evrak|kapali (bölüm) | tek (durum süzgeci: gerçek kod ya da sanal).
 * @return array{0:string,1:array<string,mixed>}
 */
function frgui_kume_where(array $F, string $kapsam): array
{
    $f = ['q' => $F['q'], 'musteri' => $F['musteri'], 'liman' => $F['liman'], 'bas' => $F['bas'], 'bit' => $F['bit']];
    $ek = '';
    if (in_array($kapsam, ['acik', 'evrak', 'kapali'], true)) $f['bolum'] = $kapsam;
    elseif (isset(frgui_sanal_durumlar()[(string)$F['durum']])) $ek = frgui_sanal_sql((string)$F['durum']);
    else $f['durum'] = (string)$F['durum'];
    [$w, $p] = frg_liste_where($f);
    return [$w . ($ek !== '' ? ' AND ' . $ek : ''), $p];
}

/** Bölüm sıralaması: açık/evrak/tek çıkış tarihi ARTAN (boşlar sonda); kapanan AZALAN — ekrandaki ve yazdırmadaki ile aynı. */
function frgui_siralama(string $kapsam): string
{
    return $kapsam === 'kapali' ? '(cikis_tarihi IS NULL), cikis_tarihi DESC, id DESC' : '(cikis_tarihi IS NULL), cikis_tarihi ASC, id ASC';
}

/** Liste satırı kolonları (ham_json / teslim_adresi gibi ağır alanlar YOK). */
function frgui_kolonlar(): string
{
    return 'id, po_no, cikis_tarihi, varis_tarihi, giris_limani, son_musteri, son_musteri_po, urun, cesit, boy, dis_paket, kutu, kutu_palet, palet, '
         . 'fiyat_kutu_birim, para_birimi, parti_no, plaka, sofor_adi, sofor_telefon, nakliyeci, fatura_no, durum, depo, kaynak, '
         . 'COALESCE(updated_at, created_at) AS degisti_at, created_at AS olusturma_at';
}

/**
 * Bir bölümün sayfası. $sayfa taşarsa son sayfaya çekilir.
 * @return array{rows:list<array<string,mixed>>,toplam:int,sayfa:int,sayfa_say:int}
 */
function frgui_bolum_oku(PDO $pdo, array $F, string $kapsam, int $sayfa, int $adet = FRG_SAYFA_ADEDI): array
{
    [$w, $p] = frgui_kume_where($F, $kapsam);
    $c = $pdo->prepare("SELECT COUNT(*) FROM frugro_siparisler WHERE $w");
    $c->execute($p);
    $toplam = (int)$c->fetchColumn();
    $adet = max(1, min(500, $adet));
    $sayfaSay = max(1, (int)ceil($toplam / $adet));
    $sayfa = max(1, min($sayfa, $sayfaSay));
    $st = $pdo->prepare('SELECT ' . frgui_kolonlar() . " FROM frugro_siparisler WHERE $w ORDER BY " . frgui_siralama($kapsam) . ' LIMIT ' . $adet . ' OFFSET ' . (($sayfa - 1) * $adet));
    $st->execute($p);
    return ['rows' => $st->fetchAll(PDO::FETCH_ASSOC), 'toplam' => $toplam, 'sayfa' => $sayfa, 'sayfa_say' => $sayfaSay];
}

/**
 * KPI sayaçları (yalnız depo + silinmemiş; arama/süzgeçten BAĞIMSIZ, OGL gibi). Tek sorgu.
 * @return array{acik:int,hazirlik:int,pkl:int,invoice:int,yolda:int,mail:int,evrak:int,kapali:int,pb:int}
 */
function frgui_kpi_say(PDO $pdo): array
{
    $bos = ['acik' => 0, 'hazirlik' => 0, 'pkl' => 0, 'invoice' => 0, 'yolda' => 0, 'mail' => 0, 'evrak' => 0, 'kapali' => 0, 'pb' => 0];
    [$w, $p] = frg_liste_where([]);
    $dur = static fn(string $kod): string => "durum = '" . $kod . "'";
    $say = ['acik' => frg_bolum_sql('acik'), 'hazirlik' => $dur('hazirlik'), 'pkl' => frgui_sanal_sql('pkl_bekleyen'), 'invoice' => $dur('pkl_hazir'),
            'yolda' => $dur('yola_cikti'), 'mail' => frgui_sanal_sql('mail_bekleyen'), 'evrak' => frg_bolum_sql('evrak'), 'kapali' => frg_bolum_sql('kapali'), 'pb' => frgui_sanal_sql('pb_eksik')];
    $sec = [];
    foreach ($say as $k => $sql) $sec[] = 'COALESCE(SUM(CASE WHEN ' . $sql . ' THEN 1 ELSE 0 END),0) AS ' . $k;
    try {
        $st = $pdo->prepare('SELECT ' . implode(', ', $sec) . " FROM frugro_siparisler WHERE $w");
        $st->execute($p);
        return array_map('intval', $st->fetch(PDO::FETCH_ASSOC) ?: $bos);
    } catch (Throwable $e) {
        error_log('[frugro_ui] kpi: ' . get_class($e));
        return $bos;
    }
}

/** KPI çipleri. @return list<array{k:string,et:string,say:int,url:string,aktif:bool}> */
function frgui_kpi_cipler(PDO $pdo, array $F, ?array $say = null): array
{
    $k = $say ?? frgui_kpi_say($pdo);
    $sifir = ['acik_page' => null, 'evrak_page' => null, 'kapali_page' => null];
    $c = static fn(string $anahtar, string $et, int $say, string $durum): array => [
        'k' => $anahtar, 'et' => $et, 'say' => $say, 'url' => frgui_url(['durum' => $durum] + $sifir), 'aktif' => $F['durum'] === $durum];
    return [
        $c('acik', 'Açık', $k['acik'], 'acik'),
        $c('hazirlik', 'Hazırlık', $k['hazirlik'], 'hazirlik'),
        $c('pkl', 'PKL bekleyen', $k['pkl'], 'pkl_bekleyen'),
        $c('invoice', 'Invoice bekleyen', $k['invoice'], 'pkl_hazir'),
        $c('yolda', 'Yolda', $k['yolda'], 'yola_cikti'),
        $c('mail', 'Mail bekleyen', $k['mail'], 'mail_bekleyen'),
        $c('evrak', 'Evrak bekleyen', $k['evrak'], 'evrak'),
        $c('pb', 'PB eksik', $k['pb'], 'pb_eksik'),
    ];
}

/** Bölüm başlığı: OGL `$bolumBas` ile AYNI işaretleme. */
function frgui_bolum_bas(string $baslik, int $say, string $id, string $ek = '', string $ikon = '', string $not = ''): string
{
    return '<div class="sip-bolum-bas" id="' . h($id) . '">'
        . ($ikon !== '' ? '<span class="sip-bolum-ikon" aria-hidden="true">' . $ikon . '</span>' : '')
        . '<div class="sip-bolum-baslik"><h2>' . h($baslik) . '</h2><span class="sip-say">' . $say . '</span>'
        . ($not !== '' ? '<span class="muted sip-bolum-not">' . h($not) . '</span>' : '') . '</div>' . $ek . '</div>';
}

/** Yazdır / PDF / WP Resim düğmeleri (siparis_yazdir.js sözleşmesi). $url = frugro_yazdir.php?bolum=… (HTML kaçışı burada). */
function frgui_yazdir_eylem(string $url): string
{
    $u = h($url);
    return '<div class="sip-bolum-eylem no-print">'
        . '<button type="button" class="btn btn-sm" data-sip-yazdir="yazdir" data-sip-yazdir-url="' . $u . '">🖨️ Yazdır</button>'
        . '<button type="button" class="btn btn-sm" data-sip-yazdir="pdf" data-sip-yazdir-url="' . $u . '">📤 PDF Gönder</button>'
        . '<button type="button" class="btn btn-sm" data-sip-yazdir="resim" data-sip-yazdir-url="' . $u . '">🖼️ WP Resim</button></div>';
}

/** "Son bakışınızdan sonra değişti / YENİ" görülme kaydı işaretçisi: OGL meta'sı + `frg` ad alanı (siparis.js anahtarı asya_frg_gorulen_<uid>; OGL kaydına DOKUNMAZ). */
function frgui_gorulen_meta(int $uid, ?array $s = null): string
{
    $meta = $s === null ? null : ['id' => (int)$s['id'], 'updated_at' => $s['updated_at'] ?? null, 'created_at' => $s['created_at'] ?? null];
    return preg_replace('/^<span hidden/', '<span hidden data-sip-gor-ns="frg"', sipui_gorulen_meta($uid, $meta), 1);
}

/** "📥 Order Log yükle · 📧 Mailden al" kartının özet satırı. $son = frgm_son() (yoksa null). Saf. */
function frgui_yukle_ozet_html(?array $son, ?array $yarim, bool $mailVar): string
{
    $o = '<span class="sip-yukle-baslik">📥 Order Log yükle' . ($mailVar ? ' · 📧 Mailden al' : '') . '</span>';
    if ($yarim !== null) $o .= '<span class="sip-yukle-son sip-yukle-son--hata">⏸ Yarım tarama var</span>';
    if ($mailVar && $son) {
        $sy = (array)($son['sayac'] ?? []);
        $parca = ['Son sorgu'];
        if ((int)($son['bitis'] ?? 0) > 0) $parca[] = date('d.m H:i', (int)$son['bitis']);
        if (isset($sy['alindi'])) $parca[] = (int)$sy['alindi'] . ' alındı';
        $hata = (int)($sy['hata'] ?? 0);
        if ($hata > 0) $parca[] = $hata . ' hata';
        $o .= '<span class="sip-yukle-son' . ($hata > 0 ? ' sip-yukle-son--hata' : '') . '">' . h(implode(' · ', $parca)) . '</span>';
    }
    return $o . '<span class="sip-yukle-ok" aria-hidden="true">▸</span>';
}

// ── Veri haritaları (listelenen TÜM siparişler için TEK sorgu; hata → boş harita, sayfa yine çalışır) ──

/** @param list<int|string> $ids @return list<int> */
function frgui_idler(array $ids): array
{
    return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
}

/**
 * PKL palet özeti: sipariş id → ['palet'=>N,'kutu'=>N,'kalibreler'=>[['kalibre'=>'14','palet'=>N,'kutu'=>N], …]] (kalibre sayısal AZALAN).
 * Tek GROUP BY sorgusu (siparis_id + kalibre). Paleti olmayan sipariş haritada YOK.
 * @param list<int|string> $ids @return array<int,array{palet:int,kutu:int,kalibreler:list<array{kalibre:string,palet:int,kutu:int}>}>
 */
function frg_palet_ozet_haritasi(PDO $pdo, array $ids): array
{
    $ids = frgui_idler($ids);
    $out = [];
    if (!$ids) return $out;
    try {
        $st = $pdo->prepare("SELECT siparis_id, COALESCE(kalibre, '') AS kalibre, COUNT(*) AS palet, COALESCE(SUM(kutu), 0) AS kutu FROM frugro_palet WHERE siparis_id IN ("
            . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY siparis_id, COALESCE(kalibre, \'\')');
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sid = (int)$r['siparis_id'];
            $out[$sid]['kalibreler'][] = ['kalibre' => trim((string)$r['kalibre']), 'palet' => (int)$r['palet'], 'kutu' => (int)$r['kutu']];
            $out[$sid]['palet'] = ($out[$sid]['palet'] ?? 0) + (int)$r['palet'];
            $out[$sid]['kutu'] = ($out[$sid]['kutu'] ?? 0) + (int)$r['kutu'];
        }
    } catch (Throwable $e) {
        error_log('[frugro_ui] palet ozet: ' . get_class($e));
        return [];
    }
    foreach ($out as $sid => $o) {
        $k = $o['kalibreler'];
        usort($k, static function (array $a, array $b): int {
            $na = is_numeric($a['kalibre']); $nb = is_numeric($b['kalibre']);
            if ($na && $nb) return (float)$b['kalibre'] <=> (float)$a['kalibre'];
            if ($na !== $nb) return $na ? -1 : 1;
            return strnatcmp($a['kalibre'], $b['kalibre']);
        });
        $out[$sid]['kalibreler'] = $k;
    }
    return $out;
}

/**
 * Siparişin OGL "kalem" biçimi (sipui_kalem_html bunu çizer): PKL palet özeti varsa KALİBRE başına bir kalem, yoksa tek kalem = siparişin kendisi (boy).
 * Satır metni "ürün kalibre · çeşit · dış paket", meta "Pal. N · PF kutu/palet".
 * @param array{palet?:int,kutu?:int,kalibreler?:list<array{kalibre:string,palet:int,kutu:int}>} $ozet @return list<array<string,mixed>>
 */
function frgui_kalemler(array $s, array $ozet = []): array
{
    $urun = trim((string)($s['urun'] ?? '')); $cesit = trim((string)($s['cesit'] ?? '')); $ic = trim((string)($s['dis_paket'] ?? ''));
    $ortak = ['aciklama' => '', 'urun' => $urun, 'cesit' => $cesit, 'koli_icerik' => $ic];
    $out = [];
    $kal = (array)($ozet['kalibreler'] ?? []);
    if ($kal) {
        foreach ($kal as $i => $k) {
            $pal = (int)$k['palet']; $kutu = (int)$k['kutu'];
            $out[] = $ortak + ['sira' => $i + 1, 'kalibre' => (string)$k['kalibre'], 'pf' => $pal > 0 ? (int)round($kutu / $pal) : null, 'po_palet' => $pal, 'etkin_palet' => $pal, 'etkin_koli' => $kutu];
        }
        return $out;
    }
    $h = frg_hesap($s);
    $kp = ($s['kutu_palet'] ?? null) !== null && $s['kutu_palet'] !== '' ? (int)$s['kutu_palet'] : null;
    return [$ortak + ['sira' => 1, 'kalibre' => trim((string)($s['boy'] ?? '')), 'pf' => $kp, 'po_palet' => $h['palet'], 'etkin_palet' => $h['palet'], 'etkin_koli' => $h['kutu']]];
}

/** "2026-01-02" → "02.01.2026" (olay metinlerindeki tarihler); başka değer olduğu gibi (≤ 40 karakter). */
function frgui_deger_goster(string $x): string
{
    $x = trim($x);
    if ($x === '' || $x === '—') return '—';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $x, $m)) return $m[3] . '.' . $m[2] . '.' . $m[1];
    return mb_strlen($x) > 40 ? mb_substr($x, 0, 39) . '…' : $x;
}

/**
 * Olay metnindeki "alan: eski → yeni; alan: …" listesini okunur satırlara çevirir ("Çıkış tarihi: 01.01.2026 → 02.01.2026").
 * @return list<string>
 */
function frgui_olay_alan_satirlari(string $metin): array
{
    $metin = trim((string)preg_replace('/^\(mailden\)\s*/u', '', $metin));
    $govde = (string)preg_replace('/^(Güncellendi|Yeni dosyada fark var, alanlar EZİLMEDİ):\s*/u', '', $metin);
    $alanlar = frgui_duzenle_alanlari();
    $out = [];
    foreach (explode('; ', $govde) as $parca) {
        $parca = trim($parca);
        if ($parca === '') continue;
        if (preg_match('/^([a-z_]+):\s*(.*?)\s*→\s*(.*)$/su', $parca, $m)) {
            $et = isset($alanlar[$m[1]]) ? $alanlar[$m[1]][1] : $m[1];
            $out[] = $et . ': ' . frgui_deger_goster($m[2]) . ' → ' . frgui_deger_goster($m[3]);
        } else {
            $out[] = mb_strlen($parca) > 100 ? mb_substr($parca, 0, 99) . '…' : $parca;
        }
    }
    return $out;
}

/**
 * ⚠ rozet baloncuğu verisi: sipariş id → ['c'=>fark satırları (alanlar EZİLMEDİ), 'u'=>okuma uyarıları, 'n'=>gerçek sayı]. Kaynak: son 30 günün
 * 'fark' olayları + ham_json 'uyarilar'. En çok 6 satır + "… +N daha". @param list<int|string> $ids @return array<int,array{c:list<string>,u:list<string>,n:int}>
 */
function frg_uyari_haritasi(PDO $pdo, array $ids, int $gun = 30): array
{
    $ids = frgui_idler($ids);
    $out = [];
    if (!$ids) return $out;
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $pdo->prepare("SELECT id, ham_json FROM frugro_siparisler WHERE ham_json LIKE '%\"uyarilar\"%' AND id IN ($in)");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $j = json_decode((string)$r['ham_json'], true);
            foreach ((array)($j['uyarilar'] ?? []) as $u) { $u = trim((string)$u); if ($u !== '') $out[(int)$r['id']]['u'][] = mb_strlen($u) > 140 ? mb_substr($u, 0, 139) . '…' : $u; }
        }
        $st = $pdo->prepare("SELECT siparis_id, metin FROM frugro_olaylar WHERE tur = 'fark' AND created_at >= ? AND siparis_id IN ($in) ORDER BY id DESC");
        $st->execute(array_merge([date('Y-m-d H:i:s', time() - $gun * 86400)], $ids));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) foreach (frgui_olay_alan_satirlari((string)$r['metin']) as $l) $out[(int)$r['siparis_id']]['c'][] = $l;
    } catch (Throwable $e) {
        error_log('[frugro_ui] uyari haritasi: ' . get_class($e));
        return [];
    }
    foreach ($out as $id => $g) {
        $n = count($g['c'] ?? []) + count($g['u'] ?? []);
        foreach (['c', 'u'] as $k) {
            $l = $g[$k] ?? [];
            if (count($l) > 6) { $f = count($l) - 6; $l = array_slice($l, 0, 6); $l[] = '… +' . $f . ' daha'; }
            $out[$id][$k] = $l;
        }
        $out[$id]['n'] = $n;
    }
    return $out;
}

/** ⚠ baloncuğunun gizli verisi (assets/siparis.js `[data-sip-balon]`): Frugro başlıkları. Veri yoksa ''. */
function frgui_uyari_balon_html(array $s, array $harita): string
{
    $g = $harita[(int)($s['id'] ?? 0)] ?? null;
    if (!$g || (empty($g['c']) && empty($g['u']))) return '';
    $o = '<span class="sip-balon-veri" hidden>';
    foreach (['c' => 'Yeni dosyada fark (alanlar EZİLMEDİ)', 'u' => 'Uyarılar'] as $k => $baslik) {
        if (empty($g[$k])) continue;
        $o .= '<span data-baslik="' . h($baslik) . '">';
        foreach ($g[$k] as $satir) $o .= '<span data-satir>' . h($satir) . '</span>';
        $o .= '</span>';
    }
    return $o . '</span>';
}

/**
 * "Ne değişti" haritası: sipariş id → en yeni önce [{t:zaman, m:satır}] (en çok 20). Kaynak: son 30 günün 'guncellendi' + 'fark' olayları
 * (yeni dosyadan gelen alan değişiklikleri; elle düzeltme / durum / belge adımı SAYILMAZ). @param list<int|string> $ids @return array<int,list<array{t:string,m:string}>>
 */
function frg_degisim_haritasi(PDO $pdo, array $ids, int $gun = 30): array
{
    $ids = frgui_idler($ids);
    $out = [];
    if (!$ids) return $out;
    try {
        $st = $pdo->prepare("SELECT siparis_id, tur, metin, created_at FROM frugro_olaylar WHERE tur IN ('guncellendi','fark') AND created_at >= ? AND siparis_id IN ("
            . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id DESC LIMIT 1500');
        $st->execute(array_merge([date('Y-m-d H:i:s', time() - $gun * 86400)], $ids));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $sid = (int)$o['siparis_id'];
            foreach (frgui_olay_alan_satirlari((string)$o['metin']) as $l) {
                if (count($out[$sid] ?? []) >= 20) break;
                $out[$sid][] = ['t' => (string)$o['created_at'], 'm' => ($o['tur'] === 'fark' ? '⚠ ' : '') . $l];
            }
        }
    } catch (Throwable $e) {
        error_log('[frugro_ui] degisim haritasi: ' . get_class($e));
        return [];
    }
    return $out;
}

// ── Satır parçaları ─────────────────────────────────────────────────────

/** Belge rozetleri (OGL `.sip-doks` 2×2): OL (Order Log kaynaklı) · PKL (palet satırı var) · INV (fatura no) · ML (mail gönderildi). $olay = ['pkl'=>bool,'ml'=>bool]. */
function frgui_dok_rozetleri(array $s, array $olay): string
{
    $var = [
        'OL' => [in_array((string)($s['kaynak'] ?? ''), ['mail', 'yukleme'], true), 'Order Log (alıcının sipariş Excel\'i)'],
        'PKL' => [!empty($olay['pkl']), 'Packing List (palet satırları)'],
        'INV' => [trim((string)($s['fatura_no'] ?? '')) !== '', 'Invoice (fatura no)'],
        'ML' => [!empty($olay['ml']), 'PKL maili gönderildi'],
    ];
    $o = '<span class="sip-doks" role="group" aria-label="Belge durumu">';
    foreach ($var as $et => [$v, $ad]) {
        $o .= '<span class="sip-dok ' . ($v ? 'sip-dok--var' : 'sip-dok--yok') . '" title="' . h($ad . ($v ? ' — var' : ' — yok')) . '">'
            . h($et) . ' <span aria-hidden="true">' . ($v ? '✓' : '–') . '</span><span class="sip-sr">' . ($v ? ' var' : ' yok') . '</span></span>';
    }
    return $o . '</span>';
}

/** İlerleme çubuğu + "3/7": OGL `.sip-prog-kap` işaretlemesi, Frugro durum tablosundan. */
function frgui_ilerleme_html(array $s): string
{
    $p = frg_ilerleme($s);
    return '<span class="sip-prog-kap"><span class="sip-prog" role="progressbar" aria-label="Süreç ilerlemesi" aria-valuemin="0" aria-valuemax="' . (int)$p['toplam']
        . '" aria-valuenow="' . (int)$p['adim'] . '"><span style="width:' . (int)$p['yuzde'] . '%"></span></span><span class="sip-prog-txt">'
        . (int)$p['adim'] . '/' . (int)$p['toplam'] . '</span></span>';
}

/** Belge dosya adı bağlantıları (parti rozetinin ÜSTÜNDE): PKL (palet satırı varsa) ve Invoice (fatura üretildiyse). Liste render'ında ÜRETİM YAPILMAZ, yalnız bağlantı. */
function frgui_belge_ad_html(array $s, bool $pklVar, bool $invVar): string
{
    $id = (int)($s['id'] ?? 0);
    $bag = static function (string $ad, string $href, string $ipucu): string {
        $ic = '<span class="sip-pkl-ik" aria-hidden="true">📄</span><span class="sip-pkl-metin">' . preg_replace('~([-_])~', '$1<wbr>', h($ad)) . '</span>';
        return '<a class="sip-pkl-ad" href="' . h($href) . '" title="' . h($ipucu . ': ' . $ad) . '">' . $ic . '</a>';
    };
    $o = '';
    if ($pklVar && function_exists('frgp_dosya_adi')) $o .= $bag(frgp_dosya_adi($s, 'xlsx'), 'frugro_pkl.php?id=' . $id, 'Packing List');
    $no = trim((string)($s['fatura_no'] ?? ''));
    if ($invVar && $no !== '' && function_exists('frgi_dosya_adi')) $o .= $bag(frgi_dosya_adi($no, (string)($s['po_no'] ?? '')), 'frugro_invoice.php?id=' . $id . '&pdf=1&dl=1', 'Invoice PDF (indir)');
    return $o;
}

/** Çıkış altındaki soluk "Varış gg.aa" satırı. */
function frgui_varis_html(?string $t): string
{
    $t = frg_tarih($t);
    return $t === null ? '' : '<div class="sip-soluk nowrap">Varış ' . h(date('d.m', (int)strtotime($t . ' 12:00:00'))) . '</div>';
}
