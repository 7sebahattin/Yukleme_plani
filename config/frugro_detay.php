<?php
// =========================================================
// config/frugro_detay.php — Frugro sipariş DETAY sayfası yardımcıları (include-only; önek frgd_)
//
// frugro_view.php (OGL siparis_view.php'nin eşi) + frugro_eml.php + frugro_zip.php + frugro_ayarlar.php (PKL mail ayarı) kullanır.
// OGL parçaları KOPYALANMAZ, çağrılır: siparis_pkl_mail_taslak / siparis_evrak_mail_taslak / siparis_pkl_mailto /
// siparis_mail_govde_uygula / siparis_mail_adres_oku / siparis_mail_govde_temizle (config/siparis_pkl.php).
// Belgeler diske YAZILMAZ: PKL PDF (frgp_pdf) ve Invoice PDF (frgi_pdf_uret) her istekte TAZE üretilir; bu dosyadaki üreticiler
// olay / durum / audit YAZMAZ (GET uçları yan etkisizdir — "pkl_uretildi" olayı yalnız PKL sayfasındaki POST üretimde düşer).
// Alıcı adresleri repoda YOK: hks_kv 'frugro_pkl_mail_ayar' (frugro_ayarlar.php › Mail ayarları; kapı siparis.delete; audit yalnız SAYI).
// Excel (xlsx) maile EKLENMEZ — sahip kararı: Excel yalnız gelen sipariş belgesidir; ek = PKL PDF + (üretildiyse) Invoice PDF.
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

require_once __DIR__ . '/frugro_core.php';
require_once __DIR__ . '/frugro_pkl.php';
require_once __DIR__ . '/frugro_invoice.php';   // siparis_invoice.php + siparis_pkl.php (mail taslağı yardımcıları) da gelir
require_once __DIR__ . '/frugro_ui.php';        // frgui_duzenle_alanlari / frgui_para / frgui_yetki
require_once dirname(__DIR__) . '/_siparis_ortak.php';   // sipui_zaman / sipui_tarih / sipui_sayi (saf biçimleme)

const FRGD_MAIL_KV = 'frugro_pkl_mail_ayar';
/** Audit'e DEĞERİ yazılmayan alanlar (kişisel / adres verisi): yalnız "değişti" bilgisi düşer. */
const FRGD_GIZLI_ALANLAR = ['teslim_adresi', 'sofor_adi', 'sofor_telefon'];

// ── MAİL AYARI (hks_kv) ─────────────────────────────────────────────────

/** @return array{kime:list<string>,bilgi:list<string>,govde:string,evrak_govde:string} — tablo yok / bozuk JSON → boş (fail soft). */
function frgd_mail_ayar_oku(PDO $pdo): array
{
    $bos = ['kime' => [], 'bilgi' => [], 'govde' => '', 'evrak_govde' => ''];
    $j = siparis_inv_kv_oku($pdo, FRGD_MAIL_KV);
    if ($j === null) return $bos;
    return ['kime' => siparis_mail_adres_oku($j['kime'] ?? []), 'bilgi' => siparis_mail_adres_oku($j['bilgi'] ?? []),
            'govde' => siparis_mail_govde_temizle((string)($j['govde'] ?? '')), 'evrak_govde' => siparis_mail_govde_temizle((string)($j['evrak_govde'] ?? ''))];
}

/**
 * Doğrular ve yazar (adresler satır / virgül / noktalı virgül / boşluk ayrımlı). Geçersiz e-posta ve listede > 10 adres REDDEDİLİR;
 * metin > SIPARIS_MAIL_GOVDE_MAX REDDEDİLİR; boş = temizle. Audit `frugro_mail_alici_ayar` yalnız SAYI + metin-var bayrağı (adres YOK).
 * @return array{ok:bool,hata?:string}
 */
function frgd_mail_ayar_kaydet(PDO $pdo, array $girdi, int $uid): array
{
    if (!siparis_evrak_mail_kv_hazir($pdo)) return ['ok' => false, 'hata' => 'Ayar tablosu (hks_kv) yok; mail ayarları kaydedilemedi.'];
    $ayar = [];
    foreach (['kime' => 'Kime', 'bilgi' => 'Bilgi (CC)'] as $k => $etk) {
        $g = $girdi[$k] ?? '';
        $parca = is_array($g) ? array_map('strval', $g) : (preg_split('/[\s,;]+/u', (string)$g) ?: []);
        $l = [];
        foreach ($parca as $a) {
            $a = strtolower(trim($a));
            if ($a === '') continue;
            if (strlen($a) > 255 || filter_var($a, FILTER_VALIDATE_EMAIL) === false) return ['ok' => false, 'hata' => 'Geçersiz e-posta adresi (' . $etk . '): ' . siparis_kirp($a, 60)];
            $l[$a] = true;
        }
        if (count($l) > SIPARIS_EVRAK_MAIL_MAX) return ['ok' => false, 'hata' => $etk . ' için en çok ' . SIPARIS_EVRAK_MAIL_MAX . ' adres girilebilir.'];
        $ayar[$k] = array_keys($l);
    }
    foreach (['govde' => 'PKL mail metni', 'evrak_govde' => 'Evrak takımı mail metni'] as $k => $etk) {
        $ham = str_replace(["\r\n", "\r"], "\n", (string)($girdi[$k] ?? ''));
        if (mb_strlen(rtrim($ham)) > SIPARIS_MAIL_GOVDE_MAX) return ['ok' => false, 'hata' => $etk . ' en çok ' . SIPARIS_MAIL_GOVDE_MAX . ' karakter olabilir.'];
        $ayar[$k] = siparis_mail_govde_temizle($ham);
    }
    if (!siparis_inv_kv_yaz($pdo, FRGD_MAIL_KV, $ayar)) return ['ok' => false, 'hata' => 'Ayar kaydedilemedi (hks_kv tablosu yazılamadı).'];
    if (function_exists('audit_log_event')) {
        audit_log_event('frugro_mail_alici_ayar', 'frugro_siparis', null, null, ['kime_sayisi' => count($ayar['kime']), 'bilgi_sayisi' => count($ayar['bilgi']),
            'pkl_metin' => $ayar['govde'] !== '', 'evrak_metin' => $ayar['evrak_govde'] !== ''], $uid);
    }
    return ['ok' => true];
}

// ── MAİL TASLAĞI + .eml ─────────────────────────────────────────────────

/**
 * PKL ("Mail gönderildi") ya da evrak takımı taslağı. Kime/Bilgi = ayar (Order Log'da e-posta yok). Konu = PKL dosya adı uzantısız.
 * Evrak: OGL v411 kalıbı (ek yok); alıcılar PKL mailiyle AYNI ayardan (sahip kararı: ayrı evrak alıcısı yok).
 * @return array{kime:list<string>,bilgi:list<string>,konu:string,govde:string}
 */
function frgd_mail_taslak(PDO $pdo, array $s, string $imza, string $tur = 'pkl'): array
{
    $ayar = frgd_mail_ayar_oku($pdo);
    $pklAd = frgp_dosya_adi($s, 'xlsx');   // konu: "PKL-<po>-<gg.aa.yyyy>" (siparis_pkl_mail_taslak .xlsx'i atar)
    if ($tur === 'evrak') {
        $t = siparis_evrak_mail_taslak($ayar['kime'], [], $pklAd, (string)($s['po_no'] ?? ''), $imza, $ayar['evrak_govde']);
        $t['bilgi'] = array_values(array_filter($ayar['bilgi'], static fn($e) => !in_array(strtolower($e), array_map('strtolower', $t['kime']), true)));
        if (!$t['kime']) { $t['kime'] = $t['bilgi']; $t['bilgi'] = []; }
        return $t;
    }
    return siparis_pkl_mail_taslak([], $pklAd, (string)($s['po_no'] ?? ''), $imza, $ayar);
}

/** PKL PDF baytları — taze, YAN ETKİSİZ (olay/durum/audit yok). Palet satırı eksikse ya da motor yoksa null. */
function frgd_pkl_pdf(array $s, ?PDO $pdo = null): ?string
{
    if (frgp_uretime_hazir($s)) return null;
    $p = frgp_pdf(frgp_belge_verisi($s, '', $pdo !== null ? frgp_ek_oku($pdo, (int)($s['id'] ?? 0)) : null));
    return ($p !== null && strncmp($p, '%PDF', 4) === 0) ? $p : null;
}

/** Invoice PDF (üretildiyse) — taze; yoksa / hata → null. @return array{pdf:string,dosya_adi:string}|null */
function frgd_invoice_pdf(PDO $pdo, int $sid): ?array
{
    try { return frgi_pdf_uret($pdo, $sid); } catch (Throwable $e) { error_log('[frgd_invoice_pdf] ' . get_class($e)); return null; }
}

/**
 * ÇOK EKLİ .eml taslağı. siparis_pkl_eml() TEK ek (xlsx MIME) alır; Frugro maili PKL PDF + Invoice PDF iki ek taşır, bu yüzden aynı
 * kalıbın (X-Unsent: 1, From YOK, gövde ve ekler base64, RFC 2047/2231 adlar) ek listesi alan ince eşi burada. Başlıklara yalnız
 * temizlenmiş değerler girer (konu taslakta satır sonundan arındırılmıştır; adresler filter_var'dan geçmiştir).
 * @param list<array{ad:string,icerik:string,mime:string}> $ekler
 */
function frgd_eml(array $t, array $ekler, ?int $zaman = null): string
{
    $sinir = '=_asya_' . bin2hex(random_bytes(12));
    $kodla = static fn(string $x): string => preg_match('/[^\x20-\x7E]/', $x) ? '=?UTF-8?B?' . base64_encode($x) . '?=' : $x;
    $konu = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$t['konu']));
    $b = ['X-Unsent: 1'];
    if ($t['kime']) $b[] = 'To: ' . implode(', ', $t['kime']);
    if ($t['bilgi']) $b[] = 'Cc: ' . implode(', ', $t['bilgi']);
    $b[] = 'Subject: ' . $kodla($konu);
    $b[] = 'Date: ' . date(DATE_RFC2822, $zaman ?? time());
    $b[] = 'MIME-Version: 1.0';
    $b[] = 'Content-Type: multipart/mixed; boundary="' . $sinir . '"';
    $b[] = '';
    $b[] = 'This is a multi-part message in MIME format.';
    $b[] = '--' . $sinir;
    $b[] = 'Content-Type: text/plain; charset=UTF-8';
    $b[] = 'Content-Transfer-Encoding: base64';
    $b[] = '';
    $b[] = rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", (string)$t['govde'])), 76, "\r\n"));
    foreach ($ekler as $e) {
        $ad = (string)$e['ad'];
        $ascii = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '_', $ad), '_') ?: 'ek.pdf';
        $mime = preg_match('~^[a-z]+/[a-z0-9.+-]+$~', (string)$e['mime']) ? (string)$e['mime'] : 'application/octet-stream';
        $b[] = '--' . $sinir;
        $b[] = 'Content-Type: ' . $mime . '; name="' . $ascii . '"';
        $b[] = 'Content-Transfer-Encoding: base64';
        $b[] = 'Content-Disposition: attachment; filename="' . $ascii . '"' . ($ascii !== $ad ? "; filename*=UTF-8''" . rawurlencode($ad) : '');
        $b[] = '';
        $b[] = rtrim(chunk_split(base64_encode((string)$e['icerik']), 76, "\r\n"));
    }
    $b[] = '--' . $sinir . '--';
    $b[] = '';
    return implode("\r\n", $b);
}

// ── DETAY EKRANI VERİSİ ─────────────────────────────────────────────────

/** Sipariş bilgileri grupları (alan adları frgui_duzenle_alanlari() beyaz listesinden). @return array<string,list<string>> */
function frgd_alan_gruplari(): array
{
    return [
        'Sipariş'         => ['son_musteri_po', 'cikis_tarihi', 'varis_tarihi', 'varis_saati', 'ihracatci', 'paketlemeci', 'incoterm'],
        'Teslimat'        => ['giris_limani', 'teslim_adresi', 'son_musteri'],
        'Ürün'            => ['urun', 'cesit', 'mense', 'boy', 'fruit_agirlik_notu', 'dis_paket', 'ic_paket', 'palet_tipi'],
        'Miktar ve fiyat' => ['kutu', 'kutu_palet', 'palet', 'fiyat_kutu_birim', 'para_birimi'],
        'Sevkiyat'        => ['parti_no', 'plaka', 'nakliyeci', 'sofor_adi', 'sofor_telefon'],
    ];
}

/** Alan için ipucu (alan penceresinde). */
function frgd_alan_ipucu(string $alan): string
{
    return [
        'fiyat_kutu_birim' => 'Kutu başı fiyat; ondalık için virgül (örn. 4,60).',
        'para_birimi' => 'GBP ya da EUR. Boş bırakılırsa tutar "GBP/EUR seçilecek" görünür.',
        'parti_no' => 'Elle girilir; boşsa bağlanan gümrük beyanının parti no\'su yazılır.',
        'plaka' => 'Çekici-Dorse, örn. 34ABC123-34XYZ456',
        'sofor_telefon' => 'Elle girilen not (ör. +90 5xx xxx xx xx).',
        'teslim_adresi' => 'Order Log\'daki teslim adresi.',
        'cikis_tarihi' => 'Order Log çıkış tarihi.',
        'incoterm' => 'Yalnız harf/rakam (örn. FCA).',
    ][$alan] ?? '';
}

/** Ekran metni (✎ satırı). */
function frgd_alan_goster(array $s, string $alan): string
{
    $v = $s[$alan] ?? null;
    if ($v === null || $v === '') return '';
    [$tur] = frgui_duzenle_alanlari()[$alan] ?? ['metin'];
    if ($tur === 'tarih') return sipui_tarih((string)$v);
    if ($tur === 'tam') return sipui_sayi($v);
    if ($tur === 'fiyat') return frg_fiyat_metin((int)$v) . (trim((string)($s['para_birimi'] ?? '')) !== '' ? ' ' . $s['para_birimi'] : '');
    return (string)$v;
}

/** `data-sip-alan` JSON'u (siparis.js #sipAlanModal sözleşmesi). */
function frgd_alan_veri(array $s, string $alan, int $id): array
{
    $t = frgui_duzenle_alanlari()[$alan];
    $tip = match ($t[0]) { 'tarih' => 'tarih', 'uzun' => 'notlar', default => $alan === 'plaka' ? 'plaka' : ($alan === 'sofor_telefon' ? 'telefon' : 'metin') };
    $ham = $s[$alan] ?? null;
    $deger = $ham === null ? '' : ($t[0] === 'fiyat' ? frg_fiyat_metin((int)$ham) : (string)$ham);
    return ['varlik' => 'frugro', 'id' => $id, 'alan' => $alan, 'etiket' => $t[1], 'tip' => $tip, 'deger' => $deger, 'ipucu' => frgd_alan_ipucu($alan), 'kilitli' => false];
}

/** Elle düzenlenmiş alan adları (yalnız gösterim — "Elle" rozeti). Kaynak: 'duzenlendi' olaylarındaki "alan: eski → yeni" parçaları. */
function frgd_elle_alanlar(array $olaylar): array
{
    $l = [];
    $alanlar = frgui_duzenle_alanlari();
    foreach ($olaylar as $o) {
        if ((string)($o['tur'] ?? '') !== 'duzenlendi') continue;
        if (preg_match_all('/(?:^|; |: )([a-z_]+): /', (string)$o['metin'], $m)) foreach ($m[1] as $a) if (isset($alanlar[$a])) $l[$a] = true;
    }
    return $l;
}

/** PKL kartı özeti: frgp_toplam + kalibre dağılımı. */
function frgd_pkl_ozet(array $s): array
{
    $pal = (array)($s['paletler'] ?? []);
    $t = frgp_toplam($pal);
    $kal = [];
    foreach ($pal as $p) {
        $k = trim((string)($p['kalibre'] ?? '')) ?: '—';
        $kal[$k] ??= ['palet' => 0, 'kutu' => 0];
        $kal[$k]['palet']++;
        $kal[$k]['kutu'] += (int)($p['kutu'] ?? 0);
    }
    ksort($kal, SORT_NATURAL);
    return ['var' => $pal !== [], 'toplam' => $t, 'kalibreler' => $kal, 'eksik' => frgp_uretime_hazir($s)];
}

/** Invoice kartı özeti (kayıtlı girdi + hesap). */
function frgd_invoice_ozet(PDO $pdo, array $s): array
{
    $g = frgi_girdi_oku($pdo, (int)$s['id']);
    $uretildi = !empty($g['uretildi_at']) && is_array($g['snap'] ?? null);
    $no = trim((string)($g['no'] ?? '')) ?: trim((string)($s['fatura_no'] ?? ''));
    $pb = strtoupper(trim((string)($g['para_birimi'] ?? ''))) ?: (string)($s['para_birimi'] ?? '');
    $sem = frgi_para_birimleri()[$pb]['sembol'] ?? '';
    return ['var' => $uretildi, 'taslak' => !$uretildi && $g !== [], 'no' => $no, 'para_birimi' => $pb,
            'tutar' => isset($g['toplam_kurus']) ? trim($sem . ' ' . frgui_para((int)$g['toplam_kurus'])) : '',
            'uretildi_at' => (string)($g['uretildi_at'] ?? ''), 'dosya_adi' => $uretildi ? frgi_dosya_adi((string)($g['snap']['no'] ?? ''), (string)($g['snap']['po_no'] ?? '')) : ''];
}

/**
 * Belgeler kartı. Order Log diske SAKLANMAZ (yalnız kaynak bilgisi, indirme yok). PKL / Invoice her indirmede taze üretilir.
 * @return list<array{tur:string,etiket:string,ad:string,meta:string,hazir:bool,url_indir:string,url_onizle:string,ikon:string}>
 */
function frgd_belgeler(array $s, array $pklOz, array $invOz, array $zaman): array
{
    $id = (int)$s['id'];
    $kaynak = ['mail' => '📧 mailden', 'yukleme' => 'Excel yükleme', 'elle' => 'elle'][(string)($s['kaynak'] ?? '')] ?? (string)($s['kaynak'] ?? '');
    $sha = (string)($s['dosya_sha'] ?? '');
    $pklHazir = $pklOz['var'] && !$pklOz['eksik'];
    return [
        ['tur' => 'order_log', 'etiket' => 'Order Log (gelen sipariş)', 'ad' => 'Order Log · ' . (string)$s['po_no'], 'ikon' => '📊', 'hazir' => true,
         'meta' => trim($kaynak . ' · ' . sipui_zaman((string)($s['created_at'] ?? '')) . ($sha !== '' ? ' · sha ' . substr($sha, 0, 10) : '')), 'url_indir' => '', 'url_onizle' => ''],
        ['tur' => 'pkl_pdf', 'etiket' => 'Packing List', 'ad' => frgp_dosya_adi($s, 'pdf'), 'ikon' => '📕', 'hazir' => $pklHazir,
         'meta' => isset($zaman['pkl']) ? 'son üretim ' . sipui_zaman($zaman['pkl']) : 'her indirmede taze üretilir',
         'url_indir' => 'frugro_pkl.php?id=' . $id . '&indir=pdf', 'url_onizle' => 'frugro_pkl.php?id=' . $id . '&indir=pdf&goster=1'],
        ['tur' => 'pkl_xlsx', 'etiket' => 'Packing List', 'ad' => frgp_dosya_adi($s, 'xlsx'), 'ikon' => '📊', 'hazir' => $pklHazir,
         'meta' => 'Excel · her indirmede taze üretilir', 'url_indir' => 'frugro_pkl.php?id=' . $id . '&indir=xlsx', 'url_onizle' => ''],
        ['tur' => 'invoice_pdf', 'etiket' => 'Invoice', 'ad' => $invOz['var'] ? $invOz['dosya_adi'] : 'Invoice (henüz üretilmedi)', 'ikon' => '🧾', 'hazir' => $invOz['var'],
         'meta' => $invOz['var'] ? 'üretildi ' . sipui_zaman($invOz['uretildi_at']) : 'Invoice ekranında hazırlanır',
         'url_indir' => 'frugro_invoice.php?id=' . $id . '&pdf=1&dl=1', 'url_onizle' => 'frugro_invoice.php?id=' . $id . '&pdf=1'],
    ];
}

/** Olay türü → [simge, başlık]. */
function frgd_olay_etiketleri(): array
{
    return [
        'olustu' => ['📥', 'Sipariş alındı'], 'guncellendi' => ['🔄', 'Order Log güncellendi'], 'fark' => ['⚠', 'Yeni dosyada fark (alanlar ezilmedi)'],
        'duzenlendi' => ['✎', 'Elle düzenlendi'], 'durum' => ['🔁', 'Durum değişti'], 'pkl_kaydedildi' => ['📦', 'PKL kaydedildi'],
        'pkl_taslak' => ['📦', 'PKL taslağı oluşturuldu'], 'pkl_uretildi' => ['📄', 'PKL üretildi'], 'invoice_uretildi' => ['🧾', 'Invoice üretildi'],
        'mail_gonderildi' => ['✅', 'Mail gönderildi'], 'evrak_gonderildi' => ['📦', 'Evrak takımı gönderildi'], 'silindi' => ['🗑', 'Silindi'],
        'beyan_baglandi' => ['🏛', 'Beyan bağlandı'], 'beyan_kaldirildi' => ['🏛', 'Beyan bağı kaldırıldı'],
    ];
}

/** Zaman çizelgesi cümlesi. Durum olayı ("eski → yeni[ · Gerekçe: …]") etiketlere çevrilir; diğerleri "Başlık — metin". */
function frgd_olay_cumle(array $o): string
{
    $tur = (string)($o['tur'] ?? '');
    $metin = trim((string)($o['metin'] ?? ''));
    $et = frgd_olay_etiketleri()[$tur][1] ?? $tur;
    if ($tur === 'durum') {
        $par = explode(' · ', $metin, 2);
        $ok = explode(' → ', $par[0], 2);
        $tan = frg_durum_tanimlari();
        if (count($ok) === 2) {
            $ad = static function (string $x) use ($tan): string { $k = frg_durum_kodu_coz($x); return $k !== '' ? $tan[$k]['etiket'] : $x; };
            return 'Durum değişti: ' . $ad($ok[0]) . ' → ' . $ad($ok[1]) . (isset($par[1]) ? ' · ' . $par[1] : '');
        }
    }
    if ($tur === 'olustu') return $metin !== '' ? $metin : $et;
    return $metin !== '' ? $et . ' — ' . $metin : $et;
}

/** İptal gerekçesi (son iptal durum olayından). */
function frgd_iptal_gerekce(array $olaylar): string
{
    foreach ($olaylar as $o) {   // en yeniden eskiye
        if ((string)$o['tur'] !== 'durum') continue;
        $p = explode(' · Gerekçe: ', (string)$o['metin'], 2);
        $ok = explode(' → ', $p[0], 2);
        if (isset($ok[1]) && frg_durum_kodu_coz($ok[1]) === 'iptal') return trim($p[1] ?? '');
    }
    return '';
}

/** Okuma uyarıları (ham_json.uyarilar) + açık "fark" olayları. @return list<array{seviye:string,mesaj:string}> */
function frgd_uyarilar(array $s, array $olaylar): array
{
    $u = [];
    $j = json_decode((string)($s['ham_json'] ?? ''), true);
    foreach ((array)(is_array($j) ? ($j['uyarilar'] ?? []) : []) as $m) if (is_scalar($m) && trim((string)$m) !== '') $u[] = ['seviye' => 'uyari', 'mesaj' => (string)$m];
    foreach ($olaylar as $o) if ((string)$o['tur'] === 'fark') $u[] = ['seviye' => 'fark', 'mesaj' => (string)$o['metin']];
    if (trim((string)($s['fiyat_kutu_birim'] ?? '')) !== '' && trim((string)($s['para_birimi'] ?? '')) === '') {
        $u[] = ['seviye' => 'uyari', 'mesaj' => 'Para birimi seçilmedi (GBP/EUR); tutar para birimsiz.'];
    }
    return $u;
}

/** Gerçek telefon bağlantısı ('' = bağlantı yok). */
function frgd_tel_href(string $tel): string
{
    $t = (string)preg_replace('~[^0-9+]~', '', $tel);
    return strlen(ltrim($t, '+')) >= 7 ? $t : '';
}

/** "⇄ Durumu değiştir" seçenekleri: [hedef => etiket]. mail/evrak kendi düğmeleriyle (onay ister); iptal kendi penceresiyle. */
function frgd_durum_secenekleri(array $s, array $adimZaman): array
{
    $d = (string)$s['durum'];
    $tan = frg_durum_tanimlari();
    $o = [];
    foreach (['hazirlik', 'yola_cikti'] as $h) if (frg_gecis_izinli($d, $h)) $o[$h] = $tan[$h]['ikon'] . ' ' . $tan[$h]['etiket'];
    $g = frg_geri_durum($s, $adimZaman);
    if ($g !== '' && isset($tan[$g])) {
        $kapali = in_array($d, frg_kapali_durumlar(), true);
        $o['geri'] = ($kapali && $d !== 'evrak_gonderildi' ? '↺ Yeniden aç → ' : '↶ Geri al → ') . $tan[$g]['ikon'] . ' ' . $tan[$g]['etiket'];
    }
    return $o;
}

/** "geri" hedefi gerekçe ister mi? (frg_durum_degistir ile aynı kural: iptal/eski kapalıyı yeniden açarken istenmez) */
function frgd_geri_gerekce_gerekli(string $d): bool
{
    return !($d === 'iptal' || $d === 'kapali');
}

/** Audit için hassas alanların değerini düşürür (alan adı kalır, değer "(değişti)"). */
function frgd_audit_maske(array $v): array
{
    foreach (FRGD_GIZLI_ALANLAR as $a) if (array_key_exists($a, $v)) $v[$a] = '(değişti)';
    return $v;
}
