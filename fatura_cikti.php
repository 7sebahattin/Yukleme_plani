<?php
// =========================================================
// fatura_cikti.php — Fatura modülü SALT OKUNUR çıktı ucu (YALNIZ GET; yazma YOK).
//   ?id=N [&otomatik=1]                          → A4 dikey yazdırma görünümü (fatura / irsaliye / müstahsil / sipariş şablonu)
//   ?sinif=satis|alis|irsaliye|siparis&bicim=xlsx|csv + liste süzgeçleri (ara, tur, durum, donem, bas, bit, cari, sd) → liste dışa aktarımı
// Kapı: require_login → is_admin → fatura_kapi('read') → fatura_no_store → fatura_sayfa_kapisi; belge fatura_gorunur (aktif depo).
// Dışa aktarım EK olarak require_perm('reports.export') + export_audit('fatura', 'liste', …) (excel-export kuralı). CSV `;` + BOM,
// binlik ayraçsız; para birimleri ASLA toplanmaz (XLSX'te para birimi başına özet sayfası; SUBTOTAL yok).
// Düzenleyen firma bilgisi tek kaynaktan: config/siparis_invoice.php siparis_inv_ihracatci() (OGL Invoice ile aynı).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/fatura_core.php';
require_once __DIR__ . '/config/fatura_ui.php';
require_once __DIR__ . '/config/xlsx_export.php';
require_once __DIR__ . '/config/print_helpers.php';
$auth_user = require_login();
if (!muhasebe_grup_okuma_can('fatura')) { forbidden(); }
fatura_kapi('read');
fatura_no_store();
$pdo = db();
fatura_sayfa_kapisi($pdo);

$bicim = in_array((string)($_GET['bicim'] ?? ''), ['xlsx', 'csv'], true) ? (string)$_GET['bicim'] : 'yazdir';

// ═════════════════ Liste dışa aktarımı ═════════════════
if ($bicim !== 'yazdir') {
    require_perm('reports.export');
    $sinif = (string)($_GET['sinif'] ?? '');
    if (!isset(fatura_sinif_sayfalari()[$sinif])) { http_response_code(400); header('Content-Type: text/plain; charset=utf-8'); echo 'Geçersiz belge sınıfı.'; exit; }
    $F = fatura_filtre($sinif);
    $satirlar = []; $pbt = [];
    for ($sf = 0; $sf < 40; $sf++) {   // en çok 20 bin belge
        $L = fatura_listesi($pdo, $sinif, fatura_liste_suzgec($F), 500, $sf * 500);
        if ($sf === 0) $pbt = $L['toplamlar_pb'];
        array_push($satirlar, ...$L['satirlar']);
        if (count($L['satirlar']) < 500) break;
    }
    export_audit('fatura', 'liste', $bicim, count($satirlar), ['sinif' => $sinif] + fatura_filtre_sorgu($F));
    $akisAd = static fn(array $b): string => trim(strip_tags(html_entity_decode(fatura_akis_rozet((string)$b['tur'], (string)$b['akis_durum']), ENT_QUOTES, 'UTF-8')));
    $bas = ['Tarih', 'Belge No', 'Belge (resmî) No', 'Tür', 'Cari Kodu', 'Cari', 'Depo', 'Para Birimi', 'Kur', 'KDV Matrahı', 'KDV', 'Genel Toplam', 'Cari Tutarı', 'Satır', 'Durum', 'Akış'];
    $dizi = static fn(array $b): array => [(string)$b['tarih'], (string)$b['belge_no'], (string)($b['fatura_no'] ?? ''), fatura_tur_bilgi((string)$b['tur'])['etiket'] ?? (string)$b['tur'],
        (string)$b['cari_kod'], (string)$b['cari_unvan'], (string)$b['depo'], (string)$b['para_birimi'], fatura_kur_metin($b['kur'] ?? null),
        (int)$b['net_toplam_kurus'] / 100, cari_kurus($b['kdv_toplam']) / 100, (int)$b['genel_toplam_kurus'] / 100, (int)$b['cari_tutar_kurus'] / 100, (int)$b['satir_say'],
        $b['durum'] === 'iptal' ? 'İptal' : 'Geçerli', $akisAd($b)];
    $dosya = 'fatura_' . $sinif . '_' . date('Y-m-d');
    if ($bicim === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $dosya . '.csv"');
        $o = fopen('php://output', 'w');
        fwrite($o, "\xEF\xBB\xBF");
        $para = static fn(int $k): string => ($k < 0 ? '-' : '') . intdiv(abs($k), 100) . ',' . str_pad((string)(abs($k) % 100), 2, '0', STR_PAD_LEFT);
        fputcsv($o, $bas, ';', '"', '\\');
        foreach ($satirlar as $b) {
            $s = $dizi($b);
            $s[0] = fatura_tarih_tr($s[0]); $s[8] = str_replace('.', ',', (string)$s[8]);
            $s[9] = $para((int)$b['net_toplam_kurus']); $s[10] = $para(cari_kurus($b['kdv_toplam'])); $s[11] = $para((int)$b['genel_toplam_kurus']); $s[12] = $para((int)$b['cari_tutar_kurus']);
            fputcsv($o, $s, ';', '"', '\\');
        }
        foreach ($pbt as $pb => $x) fputcsv($o, ['', 'TOPLAM ' . $pb . ' (geçerli ' . (int)$x['adet'] . ')', '', '', '', '', '', $pb, '', $para((int)$x['net']), $para((int)$x['kdv']), $para((int)$x['genel']), $para((int)$x['cari']), '', '', ''], ';', '"', '\\');
        fclose($o);
        exit;
    }
    $tip = ['Tarih' => 'tarih', 'KDV Matrahı' => 'tutar', 'KDV' => 'tutar', 'Genel Toplam' => 'tutar', 'Cari Tutarı' => 'tutar', 'Satır' => 'tamsayi'];
    $ozet = [];
    foreach ($pbt as $pb => $x) $ozet[] = [(string)$pb, (int)$x['adet'], (int)$x['net'] / 100, (int)$x['kdv'] / 100, (int)$x['genel'] / 100, (int)$x['cari'] / 100];
    $M = fatura_sinif_metin($sinif);
    $aciklama = count($satirlar) . ' belge · İptal edilen belgeler özete dahil değildir · Para birimleri birbirine eklenmez';
    $csvUrl = 'fatura_cikti.php?' . http_build_query(array_merge($_GET, ['bicim' => 'csv']));
    xlsx_indir($dosya . '.xlsx', [
        ['ad' => $M['baslik'], 'baslik' => 'Asya Fresh — ' . $M['baslik'], 'aciklama' => $aciklama,
         'sutunlar' => array_map(static fn(string $b): array => ['baslik' => $b, 'tip' => $tip[$b] ?? 'metin'], $bas), 'satirlar' => array_map($dizi, $satirlar)],
        ['ad' => 'Para Birimi Özeti', 'baslik' => 'Para birimi başına toplamlar (yalnız geçerli belgeler)', 'aciklama' => $aciklama,
         'sutunlar' => [['baslik' => 'Para Birimi'], ['baslik' => 'Adet', 'tip' => 'tamsayi'], ['baslik' => 'KDV Matrahı', 'tip' => 'tutar'], ['baslik' => 'KDV', 'tip' => 'tutar'], ['baslik' => 'Genel Toplam', 'tip' => 'tutar'], ['baslik' => 'Cari Tutarı', 'tip' => 'tutar']],
         'satirlar' => $ozet],
    ], $csvUrl);
}

// ═════════════════ Yazdırma görünümü ═════════════════
$id = max(0, (int)($_GET['id'] ?? 0));
$b = $id > 0 ? fatura_oku($pdo, $id) : null;
if ($b === null || !fatura_gorunur($b)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Belge bulunamadı ya da aktif deponuzda görünmüyor.'; exit; }
require_once __DIR__ . '/config/siparis_invoice.php';   // siparis_inv_ihracatci(): düzenleyen firma bilgisinin TEK kaynağı
$firma = siparis_inv_ihracatci();
render_print_page_start((fatura_tur_bilgi((string)$b['tur'])['etiket'] ?? 'Belge') . ' ' . $b['belge_no'], 'fatura', 'detail', 'portrait', ['fatura.css']);
echo '<div class="no-print fatura-yz-arac"><button type="button" onclick="window.print()">Yazdır</button> <button type="button" onclick="window.close()">Kapat</button></div>' . "\n";
echo fatura_yazdir_html($b, $firma, fatura_kaynak_satir_bilgileri($pdo, array_filter(array_column($b['satirlar'], 'kaynak_satir_id'))));
render_print_page_end(($_GET['otomatik'] ?? '') === '1');
