<?php
// =========================================================
// cek_cikti.php — Çek-Senet SALT OKUNUR çıktı ucu (YALNIZ GET; yazma YOK).
//   ?bordro=BRD-YYYY-NNNNNN [&otomatik=1]            → A4 dikey bordro (evrak listesi + toplam + ortalama vade)
//   ?sinif=cek|senet&bicim=xlsx|csv + liste süzgeçleri (yon, ara, durum, donem, bas, bit, cari) → liste dışa aktarımı
// Kapı: require_login → is_admin → ceksenet_kapi('read') → ceksenet_no_store → ceksenet_sayfa_kapisi; kayıtlar aktif depoda görünür olmalı (core süzgeci).
// Dışa aktarım EK olarak require_perm('reports.export') + export_audit('ceksenet', 'liste', …) (excel-export kuralı). CSV `;` + BOM,
// binlik ayraçsız; para birimleri ASLA toplanmaz (XLSX'te para birimi başına özet sayfası; SUBTOTAL yok).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/ceksenet_core.php';
require_once __DIR__ . '/config/ceksenet_ui.php';
require_once __DIR__ . '/config/xlsx_export.php';
require_once __DIR__ . '/config/print_helpers.php';
$auth_user = require_login();
if (!is_admin()) { forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.'); }
ceksenet_kapi('read');
ceksenet_no_store();
$pdo = db();
ceksenet_sayfa_kapisi($pdo);

$bicim = in_array((string)($_GET['bicim'] ?? ''), ['xlsx', 'csv'], true) ? (string)$_GET['bicim'] : 'yazdir';

// ═════════════════ Liste dışa aktarımı ═════════════════
if ($bicim !== 'yazdir') {
    require_perm('reports.export');
    $sinif = (string)($_GET['sinif'] ?? '');
    if (!isset(ceksenet_siniflar()[$sinif])) { http_response_code(400); header('Content-Type: text/plain; charset=utf-8'); echo 'Geçersiz sınıf (cek / senet).'; exit; }
    $F = ceksenet_filtre();
    $satirlar = []; $pbt = [];
    for ($sf = 0; $sf < 40; $sf++) {   // en çok 20 bin evrak
        $L = ceksenet_listesi($pdo, $sinif, ceksenet_liste_suzgec($F), 500, $sf * 500);
        if ($sf === 0) $pbt = $L['ozet'];
        array_push($satirlar, ...$L['satirlar']);
        if (count($L['satirlar']) < 500) break;
    }
    export_audit('ceksenet', 'liste', $bicim, count($satirlar), ['sinif' => $sinif] + array_diff_key(ceksenet_liste_suzgec($F), ['sayfa' => 1]));
    $S = ceksenet_siniflar()[$sinif];
    $bas = ['Portföy No', $sinif === 'cek' ? 'Çek No' : 'Senet No', 'Yön', 'Banka', 'Şube', 'Hesap No', $sinif === 'cek' ? 'Keşideci' : 'Borçlu', 'Kefil', 'Cari Kodu', 'Cari', 'Düzenleme', 'Vade', 'Vadeye Gün', 'Para Birimi', 'Tutar', 'Durum', 'Bordro No'];
    $dizi = static fn(array $k): array => [(string)$k['portfoy_no'], (string)$k['seri_no'], ceksenet_yonler()[$k['yon']] ?? (string)$k['yon'], (string)$k['banka_ad'], (string)$k['sube'], (string)$k['hesap_no'], (string)$k['borclu'], (string)$k['kefil'],
        (string)$k['cari_kod'], (string)$k['cari_unvan'], (string)$k['duzenleme_tarihi'], (string)$k['vade'], in_array($k['durum'], ceksenet_acik_durumlar(), true) ? (int)$k['vade_gun'] : '', (string)$k['para_birimi'],
        (int)$k['tutar_kurus'] / 100, (string)$k['durum_etiket'], (string)$k['bordro_no']];
    $dosya = 'ceksenet_' . $sinif . '_' . $F['yon'] . '_' . date('Y-m-d');
    if ($bicim === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $dosya . '.csv"');
        $o = fopen('php://output', 'w');
        fwrite($o, "\xEF\xBB\xBF");
        $para = static fn(int $k): string => ($k < 0 ? '-' : '') . intdiv(abs($k), 100) . ',' . str_pad((string)(abs($k) % 100), 2, '0', STR_PAD_LEFT);
        fputcsv($o, $bas, ';', '"', '\\');
        foreach ($satirlar as $k) {
            $s = $dizi($k);
            $s[10] = ceksenet_tarih_tr($s[10]); $s[11] = ceksenet_tarih_tr($s[11]); $s[14] = $para((int)$k['tutar_kurus']);
            fputcsv($o, $s, ';', '"', '\\');
        }
        foreach ($pbt as $pb => $x) fputcsv($o, ['TOPLAM ' . $pb . ' (iptal hariç ' . (int)$x['adet'] . ')', '', '', '', '', '', '', '', '', '', '', '', '', $pb, $para((int)$x['kurus']), '', ''], ';', '"', '\\');
        fclose($o);
        exit;
    }
    $tip = ['Düzenleme' => 'tarih', 'Vade' => 'tarih', 'Vadeye Gün' => 'tamsayi', 'Tutar' => 'tutar'];
    $ozet = [];
    foreach ($pbt as $pb => $x) $ozet[] = [(string)$pb, (int)$x['adet'], (int)$x['kurus'] / 100];
    $aciklama = count($satirlar) . ' evrak · İptal edilenler özete dahil değildir · Para birimleri birbirine eklenmez';
    $csvUrl = 'cek_cikti.php?' . http_build_query(array_merge($_GET, ['bicim' => 'csv']));
    xlsx_indir($dosya . '.xlsx', [
        ['ad' => $S['cogul'], 'baslik' => 'Asya Fresh — ' . $S['cogul'] . ' (' . (ceksenet_yonler()[$F['yon']] ?? '') . ')', 'aciklama' => $aciklama,
         'sutunlar' => array_map(static fn(string $b): array => ['baslik' => $b, 'tip' => $tip[$b] ?? 'metin'], $bas), 'satirlar' => array_map($dizi, $satirlar)],
        ['ad' => 'Para Birimi Özeti', 'baslik' => 'Para birimi başına toplamlar (iptal hariç)', 'aciklama' => $aciklama,
         'sutunlar' => [['baslik' => 'Para Birimi'], ['baslik' => 'Adet', 'tip' => 'tamsayi'], ['baslik' => 'Toplam', 'tip' => 'tutar']], 'satirlar' => $ozet],
    ], $csvUrl);
}

// ═════════════════ Bordro yazdırma ═════════════════
$b = ceksenet_bordro_oku($pdo, (string)($_GET['bordro'] ?? ''));
if ($b === null) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Bordro bulunamadı ya da aktif deponuzda görünmüyor.'; exit; }
require_once __DIR__ . '/config/siparis_invoice.php';   // siparis_inv_ihracatci(): firma bilgisinin TEK kaynağı
render_print_page_start('Bordro ' . $b['bordro_no'], 'ceksenet', 'detail', 'portrait', ['ceksenet.css']);
echo '<div class="no-print cs-yz-arac"><button type="button" onclick="window.print()">Yazdır</button> <button type="button" onclick="window.close()">Kapat</button></div>' . "\n";
echo ceksenet_bordro_yazdir_html($b, siparis_inv_ihracatci());
render_print_page_end(($_GET['otomatik'] ?? '') === '1');
