<?php
// =========================================================
// cari_ekstre.php — Cari ekstre YAZDIR görünümü + XLSX/CSV indirme. YALNIZ GET.
//   Kart ekstresi : ?id=N [&kapsam=kart] [&bas= &bit=] [&pb=] [&tur=] [&iptal=1] &bicim=yazdir|xlsx|csv
//                   (bas/bit hiç gelmezse "Bu yıl"; boş gelirse "Tümü"; pb verilmezse TÜM para birimleri, her biri ayrı bölüm/sayfa)
//   Liste dışa aktarma: ?kapsam=liste&sinif=fis|dekont + cari_fisler/dekontlar süzgeçleri &bicim=xlsx|csv
// Kapı: is_admin() tabanlı cari_kapi('read'). xlsx/csv için EK olarak require_perm('reports.export') + export_audit (excel-export kuralı).
// KG değil PARA: tutarlar 2 ondalık; para birimleri ASLA toplanmaz (pb başına ayrı bölüm/sayfa; listede toplam satırı yok).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/cari_core.php';
require_once __DIR__ . '/config/xlsx_export.php';
require_once __DIR__ . '/config/print_helpers.php';
require_once __DIR__ . '/config/cari_ui.php';
$auth_user = require_login();
if (!is_admin()) { forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.'); }
cari_kapi('read');
cari_no_store();
$pdo = db();
cari_sayfa_kapisi($pdo);

$bicim  = in_array((string)($_GET['bicim'] ?? ''), ['xlsx', 'csv'], true) ? (string)$_GET['bicim'] : 'yazdir';
$kapsam = ($_GET['kapsam'] ?? 'kart') === 'liste' ? 'liste' : 'kart';
if ($bicim === 'xlsx' || $bicim === 'csv') require_perm('reports.export');
$csvUrl = 'cari_ekstre.php?' . http_build_query(array_merge($_GET, ['bicim' => 'csv']));

// ═════════════════ Liste dışa aktarma (Fişler / Dekontlar) ═════════════════
if ($kapsam === 'liste') {
    $sinif = ($_GET['sinif'] ?? '') === 'dekont' ? 'dekont' : 'fis';
    if ($bicim === 'yazdir') { http_response_code(400); echo 'Liste yalnız xlsx/csv olarak indirilir.'; exit; }
    $F = cari_filtre_oku($_GET, $sinif);
    $satirlar = []; $ozet = [];
    for ($sf = 0; $sf < 50; $sf++) {   // en çok 50 bin satır; XLSX hücre sınırı ayrıca xlsx_indir'de
        $L = cari_hareket_listesi($pdo, $F, 1000, $sf * 1000);
        if ($sf === 0) $ozet = $L['ozet'];
        foreach ($L['satirlar'] as $h) $satirlar[] = cari_satir_norm($h);
        if (count($L['satirlar']) < 1000) break;
    }
    $raporAd = $sinif === 'fis' ? 'fis_listesi' : 'dekont_listesi';
    export_audit('cari', $raporAd, $bicim, count($satirlar), ['bas' => $F['bas'], 'bit' => $F['bit'], 'tur' => $F['tur'], 'pb' => $F['pb'], 'cari_q' => $F['cari_q'], 'durum' => $F['durum'], 'q' => $F['q'], 'sinif' => $sinif]);
    $satirDizisi = static function (array $n): array {
        $kar = $n['tur'] === 'virman' ? $n['bagli_cari_unvan'] : '';
        return [$n['tarih'], $n['belge_no'], cari_tur_kisa($n['tur']), $n['cari_unvan'], $kar, $n['odeme_sekli'], $n['evrak_no'], $n['aciklama'],
            $n['kurus'] / 100, $n['pb'], $n['durum'] === 'iptal' ? 'İptal' : 'Geçerli', $n['iptal_neden']];
    };
    $bas = ['Tarih', 'Belge No', 'Tür', 'Cari', 'Karşı Cari (virman)', 'Ödeme Şekli', 'Evrak No', 'Açıklama', 'Tutar', 'Para Birimi', 'Durum', 'İptal Nedeni'];
    $dosya = 'cari_' . $raporAd . '_' . date('Y-m-d');
    if ($bicim === 'csv') {
        $o = cari_csv_ac($dosya . '.csv');
        fputcsv($o, $bas, ';', '"', '\\');
        foreach ($satirlar as $n) {
            $s = $satirDizisi($n);
            $s[0] = cari_tarih_tr((string)$s[0]);
            $s[8] = cari_csv_para($n['kurus']);
            fputcsv($o, $s, ';', '"', '\\');
        }
        fclose($o);
        exit;
    }
    $tip = ['Tarih' => 'tarih', 'Tutar' => 'tutar'];
    $ozetSatir = [];
    foreach (cari_para_birimleri() as $pb) foreach ($ozet[$pb] ?? [] as $tur => $o) $ozetSatir[] = [$pb, cari_tur_kisa((string)$tur), $o['adet'], $o['kurus'] / 100];
    $aciklama = 'Dönem: ' . ($F['bas'] !== '' ? cari_tarih_tr($F['bas']) : '…') . ' – ' . ($F['bit'] !== '' ? cari_tarih_tr($F['bit']) : '…') . ' · ' . count($satirlar) . ' kayıt · Para birimleri birbirine eklenmez';
    xlsx_indir($dosya . '.xlsx', [
        // Tutar sütununda toplam YOK: farklı para birimleri aynı sütunda.
        ['ad' => $sinif === 'fis' ? 'Cari Fişler' : 'Cari Dekontlar', 'baslik' => 'Asya Fresh — ' . ($sinif === 'fis' ? 'Cari Fişler' : 'Cari Dekontlar'), 'aciklama' => $aciklama,
         'sutunlar' => array_map(static fn(string $b): array => ['baslik' => $b, 'tip' => $tip[$b] ?? 'metin'], $bas),
         'satirlar' => array_map($satirDizisi, $satirlar)],
        ['ad' => 'Para Birimi Özeti', 'baslik' => 'Para Birimi ve Tür Bazında Özet (yalnız geçerli kayıtlar)', 'aciklama' => $aciklama,
         'sutunlar' => [['baslik' => 'Para Birimi'], ['baslik' => 'Tür'], ['baslik' => 'Adet', 'tip' => 'tamsayi'], ['baslik' => 'Tutar', 'tip' => 'tutar']],
         'satirlar' => $ozetSatir],
    ], $csvUrl);
}

// ═════════════════ Kart ekstresi ═════════════════
$id = max(0, (int)($_GET['id'] ?? 0));
$kart = $id > 0 ? cari_kart_oku($pdo, $id) : null;
if ($kart === null) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Cari kart bulunamadı.'; exit; }

if (!array_key_exists('bas', $_GET) && !array_key_exists('bit', $_GET)) { [$bas, $bit] = cari_donem_araligi('yil'); }
else { $bas = cari_tarih_ya_bos($_GET['bas'] ?? ''); $bit = cari_tarih_ya_bos($_GET['bit'] ?? ''); }
$pbF = in_array((string)($_GET['pb'] ?? ''), cari_para_birimleri(), true) ? (string)$_GET['pb'] : '';
$turF = isset(cari_hareket_turleri()[(string)($_GET['tur'] ?? '')]) ? (string)$_GET['tur'] : '';
$iptalG = !empty($_GET['iptal']);
$veri = cari_ekstre($pdo, $id, ['bas' => $bas, 'bit' => $bit, 'pb' => $pbF, 'tur' => $turF, 'iptaller_gorunsun' => $iptalG]);
$donemMetin = ($bas !== '' ? cari_tarih_tr($bas) : 'Başlangıç') . ' – ' . ($bit !== '' ? cari_tarih_tr($bit) : 'Bugün');
$yon = static fn(int $k): string => strtoupper(cari_yon_h($k));
$satirSay = 0; foreach ($veri as $x) $satirSay += count($x['satirlar']);

if ($bicim === 'xlsx' || $bicim === 'csv') {
    export_audit('cari', 'ekstre', $bicim, $satirSay, ['id' => $id, 'bas' => $bas, 'bit' => $bit, 'pb' => $pbF, 'tur' => $turF, 'iptal' => $iptalG ? '1' : '']);
    $dosya = 'cari_ekstre_' . preg_replace('/[^A-Za-z0-9._-]/', '', (string)$kart['kod']) . '_' . date('Y-m-d');
    $baslik = ['Para Birimi', 'Tarih', 'Belge No', 'Tür', 'Evrak No', 'Ödeme Şekli', 'Açıklama', 'Borç', 'Alacak', 'Bakiye', 'Yön', 'Durum', 'İptal Nedeni'];
    if ($bicim === 'csv') {
        $o = cari_csv_ac($dosya . '.csv');
        fputcsv($o, $baslik, ';', '"', '\\');
        foreach ($veri as $pb => $x) {
            if ($bas !== '') fputcsv($o, [$pb, cari_tarih_tr($bas), '', 'Devir', '', '', 'Devir (dönem öncesi bakiye)', '', '', cari_csv_para((int)$x['devir']), $yon((int)$x['devir']), '', ''], ';', '"', '\\');
            foreach ($x['satirlar'] as $r) {
                $iptal = $r['durum'] === 'iptal';
                fputcsv($o, [$pb, cari_tarih_tr($r['tarih']), $r['belge_no'], $r['tur_etiket'], (string)$r['evrak_no'], (string)$r['odeme_sekli'], $r['aciklama'],
                    $r['borc'] ? cari_csv_para($r['borc']) : '', $r['alacak'] ? cari_csv_para($r['alacak']) : '',
                    $iptal ? '' : cari_csv_para((int)$r['yuruyen']), $iptal ? '' : $yon((int)$r['yuruyen']), $iptal ? 'İptal' : 'Geçerli', (string)$r['iptal_neden']], ';', '"', '\\');
            }
            fputcsv($o, [$pb, '', '', 'Dönem toplamı', '', '', '', cari_csv_para((int)$x['donem_borc']), cari_csv_para((int)$x['donem_alacak']), cari_csv_para((int)$x['kapanis']), $yon((int)$x['kapanis']), '', ''], ';', '"', '\\');
        }
        fclose($o);
        exit;
    }
    $sayfalar = [];
    foreach ($veri as $pb => $x) {
        $satirlar = [];
        if ($bas !== '') $satirlar[] = [$bas, '', 'Devir', '', '', 'Devir (dönem öncesi bakiye)', null, null, (int)$x['devir'] / 100, $yon((int)$x['devir']), '', ''];
        foreach ($x['satirlar'] as $r) {
            $iptal = $r['durum'] === 'iptal';
            $satirlar[] = [$r['tarih'], $r['belge_no'], $r['tur_etiket'], (string)$r['evrak_no'], (string)$r['odeme_sekli'], $r['aciklama'],
                $r['borc'] ? $r['borc'] / 100 : null, $r['alacak'] ? $r['alacak'] / 100 : null, $iptal ? null : (int)$r['yuruyen'] / 100, $iptal ? '' : $yon((int)$r['yuruyen']),
                $iptal ? 'İptal' : 'Geçerli', (string)$r['iptal_neden']];
        }
        $e = cari_bakiye_etiketi((int)$x['kapanis']);
        $sayfalar[] = [
            'ad' => 'Ekstre ' . $pb, 'baslik' => 'Cari Hesap Ekstresi — ' . $kart['unvan'] . ' (' . $kart['kod'] . ') · ' . $pb,
            'aciklama' => 'Dönem: ' . $donemMetin . ' · İptal edilen belgeler bakiyeye dahil değildir · Para birimleri ayrı sayfalarda, birbirine çevrilmez',
            // Toplam satırı (SUBTOTAL) KULLANILMAZ: iptal satırları görünürse toplama girerdi. Toplamlar bilgi bloğunda (iptalsiz, kuruş tam).
            'bilgi' => [['Devir bakiyesi', (int)$x['devir'] / 100, 'tutar'], ['Dönem borç toplamı', (int)$x['donem_borc'] / 100, 'tutar'],
                        ['Dönem alacak toplamı', (int)$x['donem_alacak'] / 100, 'tutar'], ['Kapanış bakiyesi (B=borçlu, A=alacaklı)', (int)$x['kapanis'] / 100, 'tutar'], ['Kapanış durumu', $e['metin'], 'metin']],
            'sutunlar' => [['baslik' => 'Tarih', 'tip' => 'tarih'], ['baslik' => 'Belge No'], ['baslik' => 'Tür'], ['baslik' => 'Evrak No'], ['baslik' => 'Ödeme Şekli'], ['baslik' => 'Açıklama'],
                           ['baslik' => 'Borç', 'tip' => 'tutar'], ['baslik' => 'Alacak', 'tip' => 'tutar'], ['baslik' => 'Bakiye', 'tip' => 'tutar'], ['baslik' => 'Yön'], ['baslik' => 'Durum'], ['baslik' => 'İptal Nedeni']],
            'satirlar' => $satirlar,
        ];
    }
    if (!$sayfalar) $sayfalar[] = ['ad' => 'Ekstre', 'baslik' => 'Cari Hesap Ekstresi — ' . $kart['unvan'], 'aciklama' => 'Dönem: ' . $donemMetin . ' · Hareket yok',
        'sutunlar' => [['baslik' => 'Tarih', 'tip' => 'tarih'], ['baslik' => 'Açıklama']], 'satirlar' => []];
    xlsx_indir($dosya . '.xlsx', $sayfalar, $csvUrl);
}

// ═════════════════ Yazdır görünümü (A4 dikey; otomatik print YOK) ═════════════════
$simdi = date('d.m.Y H:i');
$duzenleyen = (string)($auth_user['display_name'] ?? $auth_user['username'] ?? '');
$pbListe = $veri ? implode(', ', array_keys($veri)) : '—';
render_print_page_start('Cari Ekstre', 'cari', 'detail', 'portrait', ['cari.css']);
echo '<style>@media print { @page { @bottom-right { content: "Sayfa " counter(page) " / " counter(pages); font: 7.5pt Arial; color: #555; } } }</style>' . "\n";
?>
<div class="print-sheet">
  <header class="cari-yz-baslik">
    <div><div class="cari-yz-firma">Asya Fresh</div><h1>Cari Hesap Ekstresi</h1></div>
    <div class="cari-yz-sag">Düzenlenme: <?= h($simdi) ?><br>Düzenleyen: <?= h($duzenleyen) ?><br>Dönem: <?= h($donemMetin) ?></div>
  </header>
  <dl class="cari-yz-kunye">
    <div><dt>Ünvan</dt><dd><?= h($kart['unvan']) ?></dd></div><div><dt>Cari kodu</dt><dd><?= h($kart['kod']) ?></dd></div>
    <div><dt>Vergi dairesi / no</dt><dd><?= h(implode(' · ', array_filter([(string)($kart['vergi_dairesi'] ?? ''), (string)($kart['vergi_no'] ?? '')], static fn($v) => $v !== '')) ?: '—') ?></dd></div>
    <div><dt>Telefon</dt><dd><?= h((string)($kart['telefon'] ?? '') ?: '—') ?></dd></div>
    <div><dt>Yetkili</dt><dd><?= h((string)($kart['yetkili'] ?? '') ?: '—') ?></dd></div><div><dt>Para birimleri</dt><dd><?= h($pbListe) ?></dd></div>
  </dl>
<?php foreach ($veri as $pb => $x): $e = cari_bakiye_etiketi((int)$x['kapanis']); ?>
  <section class="cari-yz-pb-bolum">
    <div class="cari-yz-pb"><?= h(cari_pb_ad((string)$pb) . ' (' . $pb . ')') ?></div>
    <table class="print-table cari-yz-tablo"><thead><tr><th style="width:64px">Tarih</th><th style="width:104px">Belge no</th><th>Açıklama</th><th class="cari-tut" style="width:78px">Borç</th><th class="cari-tut" style="width:78px">Alacak</th><th class="cari-tut" style="width:92px">Bakiye</th></tr></thead>
    <tbody>
<?php if ($bas !== ''): ?>
      <tr class="cari-yz-devir"><td><?= h(cari_tarih_tr($bas)) ?></td><td colspan="2">Devir (dönem öncesi bakiye)</td><td class="cari-tut"></td><td class="cari-tut"></td><td class="cari-tut"><?= h(cari_tutar_bicim(abs((int)$x['devir']), (string)$pb, false) . ' ' . $yon((int)$x['devir'])) ?></td></tr>
<?php endif; foreach ($x['satirlar'] as $r): $iptal = $r['durum'] === 'iptal';
        $alt = array_filter([$r['tur_etiket'], (string)($r['evrak_no'] ?? ''), (string)($r['odeme_sekli'] ?? ''), $r['tur'] === 'virman' && $r['bagli_cari_unvan'] ? 'Karşı cari: ' . $r['bagli_cari_unvan'] : '', $iptal ? 'İPTAL: ' . (string)$r['iptal_neden'] : ''], static fn($v) => $v !== ''); ?>
      <tr class="<?= $iptal ? 'cari-yz-iptal' : '' ?>"><td><?= h(cari_tarih_tr($r['tarih'])) ?></td><td><?= h($r['belge_no']) ?></td><td><?= h($r['aciklama']) ?><br><small><?= h(implode(' · ', $alt)) ?></small></td>
        <td class="cari-tut"><?= $r['borc'] ? h(cari_tutar_bicim((int)$r['borc'], (string)$pb, false)) : '' ?></td><td class="cari-tut"><?= $r['alacak'] ? h(cari_tutar_bicim((int)$r['alacak'], (string)$pb, false)) : '' ?></td>
        <td class="cari-tut"><?= $iptal ? '—' : h(cari_tutar_bicim(abs((int)$r['yuruyen']), (string)$pb, false) . ' ' . $yon((int)$r['yuruyen'])) ?></td></tr>
<?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="3">Dönem toplamı</td><td class="cari-tut"><?= h(cari_tutar_bicim((int)$x['donem_borc'], (string)$pb, false)) ?></td><td class="cari-tut"><?= h(cari_tutar_bicim((int)$x['donem_alacak'], (string)$pb, false)) ?></td>
      <td class="cari-tut"><?= h(cari_tutar_bicim(abs((int)$x['kapanis']), (string)$pb, false) . ' ' . $yon((int)$x['kapanis'])) ?></td></tr></tfoot></table>
    <div class="cari-yz-kapanis"><span>Kapanış bakiyesi (<?= h($pb) ?>)</span><span><?= h(cari_tutar_bicim(abs((int)$x['kapanis']), (string)$pb) . ' — ' . $e['metin'] . ($e['metin'] === 'Borçlu' ? ' (cari bize borçlu)' : ($e['metin'] === 'Alacaklı' ? ' (biz cariye borçluyuz)' : ''))) ?></span></div>
  </section>
<?php endforeach; if (!$veri): ?>
  <section class="cari-yz-pb-bolum"><p>Seçilen dönemde bu cariye ait hareket bulunmuyor.</p></section>
<?php endif; ?>
  <p class="cari-yz-not">B = Borçlu (cari bize borçlu) · A = Alacaklı (biz cariye borçluyuz) · D = Denk. Para birimleri ayrı gösterilir, birbirine çevrilmez. İptal edilen belgeler bakiyeye dahil değildir.</p>
  <div class="cari-yz-alt"><span>Asya Fresh · Cari Hesap Ekstresi · <?= h($kart['unvan']) ?> (<?= h($kart['kod']) ?>)</span><span><?= h($simdi) ?></span></div>
</div>
<?php
render_print_page_end(false);
