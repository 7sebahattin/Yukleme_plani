<?php
// =========================================================
// config/erp_op_ui.php — ERP Faz 2 UI Yardımcıları (Stok/WMS, Araç Yükleme v2, Satın Alma, Üretim BOM/MRP)
//
// Kurallar: Include-only (404) · SIFIR SQL · Tüm dinamik değerler h() ile kaçışlanır
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

function erp_op_head_varlik(): void
{
    $v = (int)@filemtime(dirname(__DIR__) . '/assets/erp_op.css');
    echo '<link rel="stylesheet" href="assets/erp_op.css?v=' . $v . '">' . "\n";
}

function erp_op_scripts(): void
{
    $v = (int)@filemtime(dirname(__DIR__) . '/assets/erp_op.js');
    echo '<script src="assets/erp_op.js?v=' . $v . '" defer></script>' . "\n";
}

function erp_op_fmt(float $v, int $dec = 2): string
{
    return number_format($v, $dec, ',', '.');
}

function erp_op_tarih_tr(string $t): string
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $t, $m) ? ($m[3] . '.' . $m[2] . '.' . $m[1]) : $t;
}

function erp_op_ust_menu(string $aktif): string
{
    $sekmeler = [
        'wms_stok_yonetim'  => ['wms_stok_yonetim.php',  'Ürün & Malzeme Stok / WMS'],
        'yukleme_v2'        => ['yukleme_v2.php',        'Yeni Araç Yükleme (v2)'],
        'satinalma_yonetim' => ['satinalma_yonetim.php', 'Satın Alma & 3-Way Match'],
        'uretim_bom'        => ['uretim_bom.php',        'Üretim BOM, İş Emirleri & MRP'],
    ];
    $o = '<nav class="eop-sekmeler" aria-label="ERP Operasyon & Tedarik">';
    foreach ($sekmeler as $slug => [$url, $etiket]) {
        if (function_exists('muhasebe_sayfa_can') && !muhasebe_sayfa_can($slug)) continue;
        $o .= '<a class="eop-sekme" href="' . h($url) . '"' . ($slug === $aktif ? ' aria-current="page"' : '') . '>' . h($etiket) . '</a>';
    }
    return $o . '</nav>';
}

/** @param list<string> $eksik */
function erp_op_sema_uyari(array $eksik, string $hash, string $baslik): string
{
    return '<div class="eop-wrap"><div class="eop-uyari" role="alert">'
        . '<div style="font-size:1.6rem;" aria-hidden="true">&#9888;</div>'
        . '<div><h2 style="margin:0 0 6px;">' . h($baslik) . ' şeması kurulmamış</h2>'
        . '<p style="margin:0 0 10px;">Eksik tablolar: <code>' . h(implode(', ', $eksik)) . '</code>. Yönetici Şema Migrasyon panelinden ilgili kartı çalıştırın.</p>'
        . '<a class="btn btn-primary" href="migrate.php#' . h($hash) . '">Şema Migrasyon &rsaquo; ' . h($baslik) . '</a>'
        . '</div></div></div>';
}

// ── 1) ERP-11b: Seçenek A Ürün/Malzeme Stok & WMS ──────────────────────────

function wms_stok_render(string $sekme, array $veri, bool $yazabilir): string
{
    $csrf = function_exists('csrf_token') ? csrf_token() : '';
    $alt = [
        'urun'    => 'Ürün / Mamul Stoğu (152/153)',
        'malzeme' => 'Malzeme / Ambalaj Stoğu (150)',
        'lotlar'  => 'Lot / SKT & FIFO Takibi',
        'raflar'  => 'Depo Rafları (WMS)',
        'alarmlar'=> 'Min-Stok & SKT Alarmları',
    ];
    $stoklar = $veri['tum_stoklar'] ?? [];
    $raflar  = $veri['raflar'] ?? [];
    $alarmlar = $veri['alarmlar'] ?? ['min_stok' => [], 'skt_uyari' => []];
    $alarmTop = count($alarmlar['min_stok']) + count($alarmlar['skt_uyari']);

    $o = '<div class="eop-wrap">' . erp_op_ust_menu('wms_stok_yonetim');
    $o .= '<div class="eop-kart"><div class="eop-baslik-bar"><div class="eop-eylem-satir">';
    foreach ($alt as $k => $ad) {
        $ek = $k === 'alarmlar' && $alarmTop > 0 ? " ($alarmTop)" : '';
        $o .= '<a class="' . ($sekme === $k ? 'btn btn-primary' : 'btn btn-ghost') . '" href="wms_stok_yonetim.php?sekme=' . h($k) . '">' . h($ad . $ek) . '</a>';
    }
    $o .= '</div><span class="eop-rozet eop-rozet--bilgi">Seçenek A · Tek Çekirdek + WMS</span></div>';

    if ($sekme === 'urun' || $sekme === 'malzeme') {
        $liste = $veri['liste'] ?? [];
        if ($yazabilir) {
            $o .= '<details class="eop-kart" style="background:var(--eop-bg-soft);"><summary style="cursor:pointer;font-weight:700;">+ Stok Kartı WMS Profili &amp; Grup (Ürün / Malzeme / Dara / Min-Stok) Tanımla</summary>';
            $o .= '<form method="post" action="erp_op_kaydet.php" class="eop-filtre" style="margin-top:12px;">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                . '<input type="hidden" name="islem" value="wms_profil_kaydet">'
                . '<input type="hidden" name="geri" value="wms_stok_yonetim.php?sekme=' . h($sekme) . '">';
            $o .= '<div class="eop-alan" style="min-width:220px;"><label>Stok Kartı</label>';
            if ($stoklar !== []) {
                $o .= '<select name="stok_id" required><option value="">— Seçin —</option>';
                foreach ($stoklar as $sk) $o .= '<option value="' . (int)$sk['id'] . '">' . h((string)$sk['kod'] . ' — ' . (string)$sk['ad']) . '</option>';
                $o .= '</select>';
            } else {
                $o .= '<input type="number" name="stok_id" min="1" required placeholder="Stok ID">';
            }
            $o .= '</div>'
                . '<div class="eop-alan"><label>Grup (Seçenek A)</label><select name="stok_grubu"><option value="urun"' . ($sekme === 'urun' ? ' selected' : '') . '>Ürün / Mamul (152)</option><option value="malzeme"' . ($sekme === 'malzeme' ? ' selected' : '') . '>Malzeme / Ambalaj (150)</option><option value="hizmet">Hizmet</option></select></div>'
                . '<div class="eop-alan"><label>Değerleme</label><select name="degerleme_yontemi"><option value="fifo">FIFO / FEFO</option><option value="agirlikli_ort">Ağırlıklı Ortalama</option></select></div>'
                . '<div class="eop-alan"><label>Min Stok</label><input name="min_stok" inputmode="decimal" placeholder="100"></div>'
                . '<div class="eop-alan"><label>Birim Dara (kg)</label><input name="birim_dara_kg" inputmode="decimal" placeholder="1,20"></div>'
                . '<div class="eop-alan"><label>Kalibre / Barkod</label><input name="kalibre" placeholder="Kalibre / Barkod"></div>'
                . '<div class="eop-alan"><label>TDHP Hesap</label><input name="tdhp_hesap_kod" value="' . ($sekme === 'malzeme' ? '150' : '152') . '" maxlength="30"></div>'
                . '<button type="submit" class="btn btn-primary">Profili Kaydet</button></form></details>';
        }
        $o .= '<div class="eop-tablo-wrap"><table class="eop-tablo"><thead><tr>'
            . '<th>Kod</th><th>Stok Adı</th><th>Birim</th><th>Değerleme</th><th>TDHP</th><th class="eop-num">Birim Dara</th><th class="eop-num">Min Stok</th><th class="eop-num">WMS Kalan</th><th>Durum</th></tr></thead><tbody>';
        foreach ($liste as $s) {
            $kritik = (float)$s['min_stok'] > 0 && (float)$s['wms_kalan'] < (float)$s['min_stok'];
            $o .= '<tr><td class="eop-kod">' . h((string)$s['kod']) . '</td><td>' . h((string)$s['ad']) . '</td><td>' . h((string)$s['ana_birim']) . '</td>'
                . '<td>' . h((string)$s['degerleme_yontemi'] === 'fifo' ? 'FIFO' : 'Ağır. Ort.') . '</td>'
                . '<td class="eop-kod">' . h((string)$s['tdhp_hesap_kod']) . '</td>'
                . '<td class="eop-num">' . h(erp_op_fmt((float)$s['birim_dara_kg'], 3)) . ' kg</td>'
                . '<td class="eop-num">' . h(erp_op_fmt((float)$s['min_stok'], 2)) . '</td>'
                . '<td class="eop-num"><strong>' . h(erp_op_fmt((float)$s['wms_kalan'], 2)) . '</strong></td>'
                . '<td>' . ($kritik ? '<span class="eop-rozet eop-rozet--err">KRİTİK</span>' : '<span class="eop-rozet eop-rozet--ok">NORMAL</span>') . '</td></tr>';
        }
        if ($liste === []) $o .= '<tr><td colspan="9" style="text-align:center;color:var(--eop-muted);">Bu grupta kayıtlı stok profili yok.</td></tr>';
        $o .= '</tbody></table></div>';
    } elseif ($sekme === 'lotlar') {
        $lotlar = $veri['lotlar'] ?? [];
        if ($yazabilir) {
            $o .= '<details class="eop-kart" style="background:var(--eop-bg-soft);" open><summary style="cursor:pointer;font-weight:700;">+ Yeni Lot / Parti / SKT Girişi</summary>';
            $o .= '<form method="post" action="erp_op_kaydet.php" class="eop-filtre" style="margin-top:12px;">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                . '<input type="hidden" name="islem" value="wms_lot_giris">'
                . '<input type="hidden" name="geri" value="wms_stok_yonetim.php?sekme=lotlar">';
            $o .= '<div class="eop-alan" style="min-width:200px;"><label>Stok Kartı</label>';
            if ($stoklar !== []) {
                $o .= '<select name="stok_id" required><option value="">— Seçin —</option>';
                foreach ($stoklar as $sk) $o .= '<option value="' . (int)$sk['id'] . '">' . h((string)$sk['kod'] . ' — ' . (string)$sk['ad']) . '</option>';
                $o .= '</select>';
            } else {
                $o .= '<input type="number" name="stok_id" min="1" required placeholder="Stok ID">';
            }
            $o .= '</div>'
                . '<div class="eop-alan"><label>Lot / Parti No</label><input name="lot_no" placeholder="Otomatik veya LOT-01"></div>'
                . '<div class="eop-alan"><label>Depo</label><input name="depo" value="MERKEZ"></div>'
                . '<div class="eop-alan"><label>Raf</label><select name="raf_id"><option value="">— Opsiyonel —</option>';
            foreach ($raflar as $rf) $o .= '<option value="' . (int)$rf['id'] . '">' . h((string)$rf['depo'] . ' / ' . (string)$rf['raf_kodu']) . '</option>';
            $o .= '</select></div>'
                . '<div class="eop-alan"><label>Üretim / Giriş</label><input type="date" name="uretim_tarih" value="' . h(date('Y-m-d')) . '" required></div>'
                . '<div class="eop-alan"><label>SKT (Opsiyonel)</label><input type="date" name="skt_tarih"></div>'
                . '<div class="eop-alan"><label>Miktar</label><input name="miktar" inputmode="decimal" required placeholder="1000"></div>'
                . '<div class="eop-alan"><label>Birim Maliyet (TL)</label><input name="birim_maliyet" inputmode="decimal" placeholder="25,50"></div>'
                . '<button type="submit" class="btn btn-primary">Lot Girişini Kaydet</button></form></details>';
        }
        $o .= '<div class="eop-tablo-wrap"><table class="eop-tablo"><thead><tr>'
            . '<th>Lot No</th><th>Stok</th><th>Depo / Raf</th><th>Üretim</th><th>SKT</th><th class="eop-num">Giriş</th><th class="eop-num">Kalan</th><th class="eop-num">Birim Maliyet</th><th>Durum</th></tr></thead><tbody>';
        foreach ($lotlar as $l) {
            $o .= '<tr><td class="eop-kod">' . h((string)$l['lot_no']) . '</td>'
                . '<td>' . h(trim((string)$l['stok_kod'] . ' ' . (string)$l['stok_ad']) ?: ('Stok #' . $l['stok_id'])) . '</td>'
                . '<td>' . h((string)$l['depo'] . ($l['raf_kodu'] ? ' / ' . $l['raf_kodu'] : '')) . '</td>'
                . '<td>' . h(erp_op_tarih_tr((string)$l['uretim_tarih'])) . '</td>'
                . '<td>' . ($l['skt_tarih'] ? h(erp_op_tarih_tr((string)$l['skt_tarih'])) : '—') . '</td>'
                . '<td class="eop-num">' . h(erp_op_fmt((float)$l['giris_miktar'], 2)) . '</td>'
                . '<td class="eop-num"><strong>' . h(erp_op_fmt((float)$l['kalan_miktar'], 2)) . '</strong></td>'
                . '<td class="eop-num">' . h(erp_op_fmt((float)$l['birim_maliyet'], 4)) . ' ' . h((string)$l['para_birimi']) . '</td>'
                . '<td>' . ((string)$l['durum'] === 'aktif' ? '<span class="eop-rozet eop-rozet--ok">AKTİF</span>' : '<span class="eop-rozet">TÜKENDİ</span>') . '</td></tr>';
        }
        if ($lotlar === []) $o .= '<tr><td colspan="9" style="text-align:center;color:var(--eop-muted);">Henüz WMS lot kaydı yok.</td></tr>';
        $o .= '</tbody></table></div>';
    } elseif ($sekme === 'raflar') {
        if ($yazabilir) {
            $o .= '<form method="post" action="erp_op_kaydet.php" class="eop-filtre" style="padding:12px;background:var(--eop-bg-soft);border-radius:10px;border:1px solid var(--eop-border);">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                . '<input type="hidden" name="islem" value="wms_raf_kaydet">'
                . '<input type="hidden" name="geri" value="wms_stok_yonetim.php?sekme=raflar">'
                . '<div class="eop-alan"><label>Depo</label><input name="depo" value="MERKEZ" required></div>'
                . '<div class="eop-alan"><label>Raf Kodu</label><input name="raf_kodu" required placeholder="A-01-R1"></div>'
                . '<div class="eop-alan" style="flex:1;"><label>Açıklama / Bölge</label><input name="ad" placeholder="Soğuk Hava Oda 1 · Üst Raf"></div>'
                . '<div class="eop-alan"><label>Kapasite (kg)</label><input name="kapasite_kg" inputmode="decimal" placeholder="15000"></div>'
                . '<button type="submit" class="btn btn-primary">Raf Ekle</button></form>';
        }
        $o .= '<div class="eop-tablo-wrap"><table class="eop-tablo"><thead><tr><th>Depo</th><th>Raf Kodu</th><th>Açıklama</th><th class="eop-num">Kapasite (kg)</th><th class="eop-num">Dolu Miktar</th></tr></thead><tbody>';
        foreach ($raflar as $rf) {
            $o .= '<tr><td>' . h((string)$rf['depo']) . '</td><td class="eop-kod">' . h((string)$rf['raf_kodu']) . '</td><td>' . h((string)$rf['ad']) . '</td>'
                . '<td class="eop-num">' . h(erp_op_fmt((float)$rf['kapasite_kg'], 2)) . '</td>'
                . '<td class="eop-num"><strong>' . h(erp_op_fmt((float)($rf['dolu_miktar'] ?? 0), 2)) . '</strong></td></tr>';
        }
        if ($raflar === []) $o .= '<tr><td colspan="5" style="text-align:center;color:var(--eop-muted);">Tanımlı raf lokasyonu yok.</td></tr>';
        $o .= '</tbody></table></div>';
    } else {
        $o .= '<div class="eop-grid-2">';
        $o .= '<div><h3>Minimum Stok Altı Uyarıları (' . count($alarmlar['min_stok']) . ')</h3><div class="eop-tablo-wrap"><table class="eop-tablo"><thead><tr><th>Stok</th><th class="eop-num">Min</th><th class="eop-num">Mevcut</th><th class="eop-num">Eksik</th></tr></thead><tbody>';
        foreach ($alarmlar['min_stok'] as $a) {
            $o .= '<tr><td><strong>' . h((string)$a['kod']) . '</strong> ' . h((string)$a['ad']) . '</td><td class="eop-num">' . h(erp_op_fmt((float)$a['min_stok'], 2)) . '</td><td class="eop-num">' . h(erp_op_fmt((float)$a['wms_kalan'], 2)) . '</td><td class="eop-num" style="color:var(--eop-err);font-weight:700;">' . h(erp_op_fmt((float)$a['eksik_miktar'], 2)) . '</td></tr>';
        }
        if (empty($alarmlar['min_stok'])) $o .= '<tr><td colspan="4" style="text-align:center;color:var(--eop-muted);">Minimum stok alarmı yok.</td></tr>';
        $o .= '</tbody></table></div></div>';

        $o .= '<div><h3>Yaklaşan / Geçmiş SKT Lotları (30 Gün · ' . count($alarmlar['skt_uyari']) . ')</h3><div class="eop-tablo-wrap"><table class="eop-tablo"><thead><tr><th>Lot</th><th>Stok</th><th>SKT</th><th class="eop-num">Kalan</th></tr></thead><tbody>';
        foreach ($alarmlar['skt_uyari'] as $a) {
            $o .= '<tr><td class="eop-kod">' . h((string)$a['lot_no']) . '</td><td>' . h(trim((string)$a['stok_kod'] . ' ' . (string)$a['stok_ad']) ?: ('Stok #' . $a['stok_id'])) . '</td><td style="color:var(--eop-err);font-weight:700;">' . h(erp_op_tarih_tr((string)$a['skt_tarih'])) . '</td><td class="eop-num">' . h(erp_op_fmt((float)$a['kalan_miktar'], 2)) . '</td></tr>';
        }
        if (empty($alarmlar['skt_uyari'])) $o .= '<tr><td colspan="4" style="text-align:center;color:var(--eop-muted);">SKT uyarısı yok.</td></tr>';
        $o .= '</tbody></table></div></div></div>';
    }

    $o .= '</div></div>';
    return $o;
}

// ── 2) Yeni Araç Yükleme Fişleri (v2) ──────────────────────────────────────

function yukleme_v2_render(array $fisler, ?array $detay, array $stoklar, bool $yazabilir): string
{
    $csrf = function_exists('csrf_token') ? csrf_token() : '';
    $o = '<div class="eop-wrap">' . erp_op_ust_menu('yukleme_v2');

    if ($detay !== null) {
        $kilitli = (string)$detay['durum'] !== 'taslak';
        $o .= '<div class="eop-kart"><div class="eop-baslik-bar"><div><h2 style="margin:0;">Yükleme Fişi: ' . h((string)$detay['fis_no']) . '</h2>'
            . '<div style="color:var(--eop-muted);font-size:.88rem;">Tarih: ' . h(erp_op_tarih_tr((string)$detay['tarih']))
            . ' · Plaka: <strong>' . h((string)$detay['plaka']) . '</strong> · Firma: ' . h((string)$detay['firma_unvan'])
            . ' · Durum: ' . ($kilitli ? '<span class="eop-rozet eop-rozet--ok">YÜKLENDİ (KİLİTLİ)</span>' : '<span class="eop-rozet eop-rozet--warn">TASLAK</span>')
            . '</div></div><a class="btn btn-ghost" href="yukleme_v2.php">&larr; Listeye Dön</a></div>';

        $o .= '<div class="eop-kpi-grid">'
            . '<div class="eop-kpi"><div class="eop-kpi-etiket">Toplam Palet</div><div class="eop-kpi-deger">' . (int)$detay['toplam_palet'] . '</div></div>'
            . '<div class="eop-kpi"><div class="eop-kpi-etiket">Toplam Kasa</div><div class="eop-kpi-deger">' . (int)$detay['toplam_kasa'] . '</div></div>'
            . '<div class="eop-kpi"><div class="eop-kpi-etiket">Toplam Brüt</div><div class="eop-kpi-deger">' . h(erp_op_fmt((float)$detay['toplam_brut_kg'], 2)) . ' kg</div></div>'
            . '<div class="eop-kpi"><div class="eop-kpi-etiket">Toplam Dara</div><div class="eop-kpi-deger">' . h(erp_op_fmt((float)$detay['toplam_dara_kg'], 2)) . ' kg</div></div>'
            . '<div class="eop-kpi"><div class="eop-kpi-etiket">Toplam Net</div><div class="eop-kpi-deger ok">' . h(erp_op_fmt((float)$detay['toplam_net_kg'], 2)) . ' kg</div></div></div>';

        $o .= '<div class="eop-tablo-wrap"><table class="eop-tablo"><thead><tr>'
            . '<th>#</th><th>Palet No</th><th>Ürün</th><th>Lot / Kalibre</th><th class="eop-num">Kasa</th><th class="eop-num">Brüt (kg)</th><th class="eop-num">Dara (kg)</th><th class="eop-num">Net (kg)</th></tr></thead><tbody>';
        foreach (($detay['paletler'] ?? []) as $pl) {
            $o .= '<tr><td>' . (int)$pl['sira_no'] . '</td><td class="eop-kod">' . h((string)$pl['palet_no']) . '</td><td>' . h((string)$pl['urun_ad']) . '</td>'
                . '<td>' . h(trim((string)$pl['lot_no'] . ' ' . (string)$pl['kalibre'])) . '</td>'
                . '<td class="eop-num">' . (int)$pl['kasa_adeti'] . '</td>'
                . '<td class="eop-num">' . h(erp_op_fmt((float)$pl['brut_kg'], 2)) . '</td>'
                . '<td class="eop-num">' . h(erp_op_fmt((float)$pl['dara_kg'], 2)) . '</td>'
                . '<td class="eop-num"><strong>' . h(erp_op_fmt((float)$pl['net_kg'], 2)) . '</strong></td></tr>';
        }
        $o .= '</tbody></table></div>';

        if ($yazabilir) {
            $o .= '<div class="eop-eylem-satir" style="margin-top:14px;">';
            if (!$kilitli) {
                $o .= '<form method="post" action="erp_op_kaydet.php" class="eop-inline-form">'
                    . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                    . '<input type="hidden" name="islem" value="yukleme_v2_kilitle">'
                    . '<input type="hidden" name="id" value="' . (int)$detay['id'] . '">'
                    . '<input type="hidden" name="geri" value="yukleme_v2.php?id=' . (int)$detay['id'] . '">'
                    . '<button type="submit" class="btn btn-primary">Kilitle &amp; Sevk Et (WMS FIFO Düş)</button></form>';
            } else {
                $o .= '<form method="post" action="erp_op_kaydet.php" class="eop-inline-form">'
                    . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                    . '<input type="hidden" name="islem" value="yukleme_v2_revizyon">'
                    . '<input type="hidden" name="id" value="' . (int)$detay['id'] . '">'
                    . '<input type="hidden" name="geri" value="yukleme_v2.php?id=' . (int)$detay['id'] . '">'
                    . '<input name="neden" required placeholder="Revizyon gerekçesi..." style="padding:7px 10px;border:1px solid var(--eop-border);border-radius:8px;background:var(--eop-bg);color:var(--eop-text);">'
                    . '<button type="submit" class="btn btn-ghost">Gerekçeli Revizyon Aç</button></form>';
            }
            $o .= '</div>';
        }
        $o .= '</div>';
    }

    if ($yazabilir) {
        $stokOpts = '<option value="">— Opsiyonel Stok —</option>';
        foreach ($stoklar as $sk) $stokOpts .= '<option value="' . (int)$sk['id'] . '">' . h((string)$sk['kod'] . ' — ' . (string)$sk['ad']) . '</option>';

        $o .= '<details class="eop-kart"' . ($detay === null ? ' open' : '') . '><summary style="cursor:pointer;font-weight:700;">+ Yeni Araç Yükleme Fişi (Palet Bazlı Canlı Dara &amp; Net Hesabı)</summary>';
        $o .= '<form method="post" action="erp_op_kaydet.php" style="margin-top:12px;">'
            . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
            . '<input type="hidden" name="islem" value="yukleme_v2_kaydet">'
            . '<input type="hidden" name="geri" value="yukleme_v2.php">'
            . '<div class="eop-filtre">'
            . '<div class="eop-alan"><label>Tarih</label><input type="date" name="tarih" value="' . h(date('Y-m-d')) . '" required></div>'
            . '<div class="eop-alan"><label>Depo</label><input name="depo" value="MERKEZ"></div>'
            . '<div class="eop-alan"><label>Plaka</label><input name="plaka" required placeholder="33 ABC 123"></div>'
            . '<div class="eop-alan"><label>Dorse Plaka</label><input name="dorse_plaka" placeholder="33 XYZ 99"></div>'
            . '<div class="eop-alan"><label>Şoför</label><input name="sofor_adi" placeholder="Ad Soyad"></div>'
            . '<div class="eop-alan" style="flex:1;"><label>Alıcı / Firma Ünvanı</label><input name="firma_unvan" placeholder="İhracat / Yurt içi alıcı"></div>'
            . '<div class="eop-alan"><label>Ülke</label><input name="gidecek_ulke" placeholder="Almanya / Hollanda"></div>'
            . '</div>';

        $o .= '<div class="eop-tablo-wrap"><table class="eop-tablo"><thead><tr>'
            . '<th>Palet No</th><th>Ürün Stok / Adı</th><th>Lot</th><th class="eop-num">Kasa Ad.</th><th class="eop-num">Kasa Dara</th><th class="eop-num">Palet Dara</th><th class="eop-num">Brüt (kg)</th><th class="eop-num">Dara</th><th class="eop-num">Net</th><th></th></tr></thead>'
            . '<tbody id="eopPaletBody"></tbody></table></div>';

        $o .= '<template id="eopPaletTpl"><tr class="eop-palet-satir">'
            . '<td><input name="palet_no[]" style="width:72px;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"></td>'
            . '<td><select name="urun_stok_id[]" style="width:150px;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);">' . $stokOpts . '</select> <input name="urun_ad[]" placeholder="Ürün adı" style="width:130px;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"></td>'
            . '<td><input name="lot_no[]" placeholder="LOT" style="width:90px;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"></td>'
            . '<td><input name="kasa_adeti[]" data-p="kasa" inputmode="numeric" value="80" style="width:65px;text-align:right;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"></td>'
            . '<td><input name="kasa_dara_kg[]" data-p="kdara" inputmode="decimal" value="0.60" style="width:65px;text-align:right;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"></td>'
            . '<td><input name="palet_dara_kg[]" data-p="pdara" inputmode="decimal" value="22" style="width:65px;text-align:right;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"></td>'
            . '<td><input name="brut_kg[]" data-p="brut" inputmode="decimal" placeholder="850" style="width:80px;text-align:right;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"></td>'
            . '<td class="eop-num" data-out="dara">0,00</td>'
            . '<td class="eop-num"><strong data-out="net">0,00</strong></td>'
            . '<td><button type="button" class="btn btn-ghost" data-satir-sil="1" style="padding:4px 8px;">&times;</button></td></tr></template>';

        $o .= '<div class="eop-ozet-bar">'
            . '<span>Kasa: <strong id="eopTopKasa">0</strong></span>'
            . '<span>Brüt: <strong id="eopTopBrut">0,00 kg</strong></span>'
            . '<span>Dara: <strong id="eopTopDara">0,00 kg</strong></span>'
            . '<span>Net: <strong id="eopTopNet">0,00 kg</strong></span>'
            . '<div class="eop-eylem-satir"><button type="button" class="btn btn-ghost" id="eopPaletEkle">+ Palet Ekle</button><button type="submit" class="btn btn-primary">Yükleme Fişini Kaydet</button></div>'
            . '</div></form></details>';
    }

    $o .= '<div class="eop-kart"><h2>Araç Yükleme Fişleri (v2)</h2><div class="eop-tablo-wrap"><table class="eop-tablo"><thead><tr>'
        . '<th>Fiş No</th><th>Tarih</th><th>Plaka</th><th>Firma / Ülke</th><th class="eop-num">Palet</th><th class="eop-num">Kasa</th><th class="eop-num">Net (kg)</th><th>Durum</th></tr></thead><tbody>';
    foreach ($fisler as $f) {
        $d = (string)$f['durum'];
        $o .= '<tr><td class="eop-kod"><a href="yukleme_v2.php?id=' . (int)$f['id'] . '">' . h((string)$f['fis_no']) . '</a></td>'
            . '<td>' . h(erp_op_tarih_tr((string)$f['tarih'])) . '</td>'
            . '<td class="eop-kod">' . h((string)$f['plaka']) . '</td>'
            . '<td>' . h((string)$f['firma_unvan'] . ($f['gidecek_ulke'] ? ' (' . $f['gidecek_ulke'] . ')' : '')) . '</td>'
            . '<td class="eop-num">' . (int)$f['toplam_palet'] . '</td>'
            . '<td class="eop-num">' . (int)$f['toplam_kasa'] . '</td>'
            . '<td class="eop-num"><strong>' . h(erp_op_fmt((float)$f['toplam_net_kg'], 2)) . '</strong></td>'
            . '<td>' . ($d === 'yuklendi' ? '<span class="eop-rozet eop-rozet--ok">YÜKLENDİ</span>' : '<span class="eop-rozet eop-rozet--warn">TASLAK</span>') . '</td></tr>';
    }
    if ($fisler === []) $o .= '<tr><td colspan="8" style="text-align:center;color:var(--eop-muted);">Henüz v2 araç yükleme fişi yok.</td></tr>';
    $o .= '</tbody></table></div></div></div>';
    return $o;
}

// ── 3) ERP-3: Satın Alma & 3-Way Match ─────────────────────────────────────

function satinalma_render(string $sekme, array $veri, bool $yazabilir): string
{
    $csrf = function_exists('csrf_token') ? csrf_token() : '';
    $alt = [
        'talepler'   => 'Talepler & Teklif Karşılaştırma',
        'siparisler' => 'Verilen Siparişler (PO)',
        'mal_kabul'  => 'Mal Kabul / İrsaliye (GRN)',
        'eslestirme' => '3-Way Match & Tedarikçi Puanı',
    ];
    $o = '<div class="eop-wrap">' . erp_op_ust_menu('satinalma_yonetim');
    $o .= '<div class="eop-kart"><div class="eop-eylem-satir" style="margin-bottom:14px;">';
    foreach ($alt as $k => $ad) {
        $o .= '<a class="' . ($sekme === $k ? 'btn btn-primary' : 'btn btn-ghost') . '" href="satinalma_yonetim.php?sekme=' . h($k) . '">' . h($ad) . '</a>';
    }
    $o .= '</div>';

    if ($sekme === 'talepler') {
        $talepler = $veri['talepler'] ?? [];
        if ($yazabilir) {
            $o .= '<details class="eop-kart" style="background:var(--eop-bg-soft);"><summary style="cursor:pointer;font-weight:700;">+ Yeni Satın Alma Talebi Oluştur</summary>';
            $o .= '<form method="post" action="erp_op_kaydet.php" style="margin-top:12px;">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                . '<input type="hidden" name="islem" value="sa_talep_kaydet">'
                . '<input type="hidden" name="geri" value="satinalma_yonetim.php?sekme=talepler">'
                . '<div class="eop-filtre">'
                . '<div class="eop-alan"><label>Tarih</label><input type="date" name="tarih" value="' . h(date('Y-m-d')) . '" required></div>'
                . '<div class="eop-alan"><label>Termin</label><input type="date" name="termin_tarih"></div>'
                . '<div class="eop-alan"><label>Departman</label><input name="departman" placeholder="Paketleme / Üretim"></div>'
                . '<div class="eop-alan" style="flex:1;"><label>Açıklama</label><input name="aciklama" placeholder="İhtiyaç özeti"></div></div>'
                . '<div class="eop-tablo-wrap"><table class="eop-tablo"><thead><tr><th>Ürün / Malzeme Adı</th><th>Birim</th><th class="eop-num">Miktar</th><th class="eop-num">Hedef Fiyat</th><th></th></tr></thead>'
                . '<tbody id="eopTalepBody"><tr>'
                . '<td><input name="kalem_ad[]" required placeholder="Plastik Kasa 5kg" style="width:100%;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"></td>'
                . '<td><input name="kalem_birim[]" value="adet" style="width:75px;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"></td>'
                . '<td><input name="kalem_miktar[]" inputmode="decimal" required placeholder="5000" style="width:100px;text-align:right;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"></td>'
                . '<td><input name="kalem_fiyat[]" inputmode="decimal" placeholder="14,50" style="width:100px;text-align:right;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"></td>'
                . '<td><button type="button" class="btn btn-ghost" data-eop-sil="1" style="padding:4px 8px;">&times;</button></td></tr></tbody></table></div>'
                . '<template id="eopTalepTpl"><tr><td><input name="kalem_ad[]" required style="width:100%;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"></td><td><input name="kalem_birim[]" value="kg" style="width:75px;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"></td><td><input name="kalem_miktar[]" inputmode="decimal" required style="width:100px;text-align:right;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"></td><td><input name="kalem_fiyat[]" inputmode="decimal" style="width:100px;text-align:right;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"></td><td><button type="button" class="btn btn-ghost" data-eop-sil="1" style="padding:4px 8px;">&times;</button></td></tr></template>'
                . '<div class="eop-eylem-satir" style="margin-top:10px;"><button type="button" class="btn btn-ghost" data-eop-ekle="eopTalepBody" data-eop-tpl="eopTalepTpl">+ Kalem Ekle</button><button type="submit" class="btn btn-primary">Talebi Kaydet</button></div>'
                . '</form></details>';
        }
        foreach ($talepler as $t) {
            $o .= '<div class="eop-kart" style="background:var(--eop-bg-soft);">'
                . '<div class="eop-baslik-bar"><div><strong class="eop-kod">' . h((string)$t['talep_no']) . '</strong> · ' . h(erp_op_tarih_tr((string)$t['tarih'])) . ' · ' . h((string)$t['aciklama']) . '</div>'
                . '<span class="eop-rozet ' . ((string)$t['durum'] === 'siparise_donustu' ? 'eop-rozet--ok' : 'eop-rozet--bilgi') . '">' . h(strtoupper((string)$t['durum'])) . '</span></div>';
            if ($yazabilir && (string)$t['durum'] === 'acik') {
                $o .= '<form method="post" action="erp_op_kaydet.php" class="eop-filtre">'
                    . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                    . '<input type="hidden" name="islem" value="sa_teklif_ekle">'
                    . '<input type="hidden" name="talep_id" value="' . (int)$t['id'] . '">'
                    . '<input type="hidden" name="geri" value="satinalma_yonetim.php?sekme=talepler">'
                    . '<div class="eop-alan"><label>Tedarikçi</label><input name="tedarikci_ad" required placeholder="Tedarikçi A.Ş."></div>'
                    . '<div class="eop-alan"><label>Birim Fiyat</label><input name="birim_fiyat" inputmode="decimal" required placeholder="14,00"></div>'
                    . '<div class="eop-alan"><label>Teslim (Gün)</label><input type="number" name="teslim_gun" value="3"></div>'
                    . '<div class="eop-alan"><label>Vade (Gün)</label><input type="number" name="vade_gun" value="30"></div>'
                    . '<button type="submit" class="btn btn-ghost">+ Teklif Ekle</button></form>';
            }
            if (!empty($t['teklifler'])) {
                $o .= '<div class="eop-tablo-wrap"><table class="eop-tablo"><thead><tr><th>Tedarikçi</th><th class="eop-num">Birim Fiyat</th><th class="eop-num">Toplam</th><th>Teslim</th><th>Vade</th><th>Seçim</th></tr></thead><tbody>';
                foreach ($t['teklifler'] as $tk) {
                    $o .= '<tr><td><strong>' . h((string)$tk['tedarikci_ad']) . '</strong></td>'
                        . '<td class="eop-num">' . h(erp_op_fmt((float)$tk['birim_fiyat'], 4)) . '</td>'
                        . '<td class="eop-num"><strong>' . h(erp_op_fmt((float)$tk['toplam_tutar'], 2)) . ' ' . h((string)$tk['para_birimi']) . '</strong></td>'
                        . '<td>' . (int)$tk['teslim_gun'] . ' gün</td><td>' . (int)$tk['vade_gun'] . ' gün</td><td>';
                    if ((int)$tk['secildi'] === 1) {
                        $o .= '<span class="eop-rozet eop-rozet--ok">KAZANAN</span>';
                    } elseif ($yazabilir && (string)$t['durum'] === 'acik') {
                        $o .= '<form method="post" action="erp_op_kaydet.php" class="eop-inline-form">'
                            . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                            . '<input type="hidden" name="islem" value="sa_siparis_olustur">'
                            . '<input type="hidden" name="teklif_id" value="' . (int)$tk['id'] . '">'
                            . '<input type="hidden" name="geri" value="satinalma_yonetim.php?sekme=siparisler">'
                            . '<button type="submit" class="btn btn-primary" style="padding:4px 8px;font-size:.8rem;">Siparişe Dönüştür</button></form>';
                    }
                    $o .= '</td></tr>';
                }
                $o .= '</tbody></table></div>';
            }
            $o .= '</div>';
        }
        if ($talepler === []) $o .= '<p style="text-align:center;color:var(--eop-muted);">Henüz satın alma talebi yok.</p>';
    } elseif ($sekme === 'siparisler') {
        $siparisler = $veri['siparisler'] ?? [];
        $o .= '<div class="eop-tablo-wrap"><table class="eop-tablo"><thead><tr><th>Sipariş No</th><th>Tarih</th><th>Tedarikçi</th><th class="eop-num">Toplam Tutar</th><th>Durum</th><th>İşlem</th></tr></thead><tbody>';
        foreach ($siparisler as $s) {
            $o .= '<tr><td class="eop-kod">' . h((string)$s['siparis_no']) . '</td><td>' . h(erp_op_tarih_tr((string)$s['tarih'])) . '</td><td>' . h((string)$s['tedarikci_ad']) . '</td>'
                . '<td class="eop-num"><strong>' . h(erp_op_fmt((float)$s['toplam_tutar'], 2)) . ' ' . h((string)$s['para_birimi']) . '</strong></td>'
                . '<td><span class="eop-rozet eop-rozet--bilgi">' . h(strtoupper((string)$s['durum'])) . '</span></td><td>';
            if ($yazabilir && in_array((string)$s['durum'], ['acik', 'kismi_teslim'], true)) {
                $o .= '<form method="post" action="erp_op_kaydet.php" class="eop-inline-form">'
                    . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                    . '<input type="hidden" name="islem" value="sa_mal_kabul_kaydet">'
                    . '<input type="hidden" name="siparis_id" value="' . (int)$s['id'] . '">'
                    . '<input type="hidden" name="geri" value="satinalma_yonetim.php?sekme=mal_kabul">'
                    . '<input name="irsaliye_no" placeholder="İrsaliye No" style="width:120px;padding:4px 8px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);">'
                    . '<button type="submit" class="btn btn-primary" style="padding:4px 8px;font-size:.8rem;">Mal Kabul Yap</button></form>';
            }
            $o .= '</td></tr>';
        }
        if ($siparisler === []) $o .= '<tr><td colspan="6" style="text-align:center;color:var(--eop-muted);">Henüz satın alma siparişi yok.</td></tr>';
        $o .= '</tbody></table></div>';
    } elseif ($sekme === 'mal_kabul') {
        $kabuller = $veri['kabuller'] ?? [];
        $o .= '<div class="eop-tablo-wrap"><table class="eop-tablo"><thead><tr><th>Kabul No</th><th>Sipariş No</th><th>Tarih</th><th>İrsaliye No</th><th>Tedarikçi</th><th class="eop-num">Kabul Miktar</th><th>Kalite</th></tr></thead><tbody>';
        foreach ($kabuller as $k) {
            $o .= '<tr><td class="eop-kod">' . h((string)$k['kabul_no']) . '</td><td class="eop-kod">' . h((string)$k['siparis_no']) . '</td>'
                . '<td>' . h(erp_op_tarih_tr((string)$k['tarih'])) . '</td><td>' . h((string)$k['irsaliye_no']) . '</td>'
                . '<td>' . h((string)$k['tedarikci_ad']) . '</td><td class="eop-num">' . h(erp_op_fmt((float)$k['toplam_kabul_miktar'], 2)) . '</td>'
                . '<td>' . (int)$k['kalite_puani'] . '/5</td></tr>';
        }
        if ($kabuller === []) $o .= '<tr><td colspan="7" style="text-align:center;color:var(--eop-muted);">Henüz mal kabul kaydı yok.</td></tr>';
        $o .= '</tbody></table></div>';
    } else {
        $kabuller = $veri['kabuller'] ?? [];
        $eslesmeler = $veri['eslestirmeler'] ?? [];
        $perf = $veri['performans'] ?? [];
        if ($yazabilir && $kabuller !== []) {
            $o .= '<form method="post" action="erp_op_kaydet.php" class="eop-filtre" style="padding:12px;background:var(--eop-bg-soft);border-radius:10px;border:1px solid var(--eop-border);">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                . '<input type="hidden" name="islem" value="sa_uc_yonlu_eslestir">'
                . '<input type="hidden" name="geri" value="satinalma_yonetim.php?sekme=eslestirme">'
                . '<div class="eop-alan" style="min-width:240px;"><label>Sipariş + Mal Kabul (GRN)</label><select name="kabul_pair" required>';
            foreach ($kabuller as $k) {
                $o .= '<option value="' . (int)$k['siparis_id'] . ':' . (int)$k['id'] . '">' . h((string)$k['siparis_no'] . ' / ' . (string)$k['kabul_no'] . ' — ' . (string)$k['tedarikci_ad'] . ' (PO: ' . erp_op_fmt((float)$k['po_tutar'], 2) . ')') . '</option>';
            }
            $o .= '</select></div>'
                . '<div class="eop-alan"><label>Alış Fatura No</label><input name="fatura_no" required placeholder="ALF20260001"></div>'
                . '<div class="eop-alan"><label>Fatura Miktarı</label><input name="fatura_miktar" inputmode="decimal" placeholder="Opsiyonel"></div>'
                . '<div class="eop-alan"><label>Fatura Tutarı (TL)</label><input name="fatura_tutar" inputmode="decimal" required placeholder="70000,00"></div>'
                . '<div class="eop-alan"><label>Tolerans (%)</label><input name="tolerans_yuzde" inputmode="decimal" value="2"></div>'
                . '<button type="submit" class="btn btn-primary">3-Way Match Çalıştır</button></form>';
        }
        $o .= '<div class="eop-grid-2"><div><h3>3-Way Match Sonuçları</h3><div class="eop-tablo-wrap"><table class="eop-tablo"><thead><tr>'
            . '<th>PO / GRN / Fatura</th><th class="eop-num">PO Tutarı</th><th class="eop-num">Fatura</th><th class="eop-num">Sapma</th><th>Karar</th></tr></thead><tbody>';
        foreach ($eslesmeler as $e) {
            $son = (string)$e['sonuc'];
            $roz = $son === 'uyumlu' ? 'eop-rozet--ok' : ($son === 'sapma_var' ? 'eop-rozet--warn' : 'eop-rozet--err');
            $o .= '<tr><td><div class="eop-kod">' . h((string)$e['siparis_no'] . ' / ' . (string)$e['kabul_no']) . '</div><div style="font-size:.78rem;color:var(--eop-muted);">' . h((string)$e['fatura_no']) . '</div></td>'
                . '<td class="eop-num">' . h(erp_op_fmt((float)$e['po_tutar'], 2)) . '</td>'
                . '<td class="eop-num">' . h(erp_op_fmt((float)$e['fatura_tutar'], 2)) . '</td>'
                . '<td class="eop-num">%' . h(erp_op_fmt(max((float)$e['miktar_sapma_yuzde'], (float)$e['tutar_sapma_yuzde']), 2)) . '</td>'
                . '<td><span class="eop-rozet ' . $roz . '">' . h(strtoupper($son)) . '</span></td></tr>';
        }
        if ($eslesmeler === []) $o .= '<tr><td colspan="5" style="text-align:center;color:var(--eop-muted);">Henüz 3-Way Match kaydı yok.</td></tr>';
        $o .= '</tbody></table></div></div>';

        $o .= '<div><h3>Tedarikçi Teslimat &amp; Kalite Performansı</h3><div class="eop-tablo-wrap"><table class="eop-tablo"><thead><tr>'
            . '<th>Tedarikçi</th><th class="eop-num">Teslimat</th><th class="eop-num">Ort. Kalite</th><th class="eop-num">Zamanında</th></tr></thead><tbody>';
        foreach ($perf as $p) {
            $o .= '<tr><td><strong>' . h((string)$p['tedarikci_ad']) . '</strong></td><td class="eop-num">' . (int)$p['teslimat_sayisi'] . '</td>'
                . '<td class="eop-num">' . h(erp_op_fmt((float)$p['ort_kalite'], 2)) . ' / 5</td>'
                . '<td class="eop-num">%' . h(erp_op_fmt((float)$p['zamaninda_yuzde'], 1)) . '</td></tr>';
        }
        if ($perf === []) $o .= '<tr><td colspan="4" style="text-align:center;color:var(--eop-muted);">Henüz performans verisi yok.</td></tr>';
        $o .= '</tbody></table></div></div></div>';
    }
    $o .= '</div></div>';
    return $o;
}

// ── 4) ERP-4: Üretim BOM, İş Emirleri, Lot-to-Lot İzlenebilirlik & MRP ─────

function uretim_bom_render(string $sekme, array $veri, bool $yazabilir): string
{
    $csrf = function_exists('csrf_token') ? csrf_token() : '';
    $alt = [
        'bom'     => 'Ürün Ağacı (BOM)',
        'emirler' => 'İş Emirleri & Fire/Randıman',
        'iz'      => 'Lot-to-Lot İzlenebilirlik',
        'mrp'     => 'MRP Malzeme İhtiyaç Planı',
    ];
    $o = '<div class="eop-wrap">' . erp_op_ust_menu('uretim_bom');
    $o .= '<div class="eop-kart"><div class="eop-eylem-satir" style="margin-bottom:14px;">';
    foreach ($alt as $k => $ad) {
        $o .= '<a class="' . ($sekme === $k ? 'btn btn-primary' : 'btn btn-ghost') . '" href="uretim_bom.php?sekme=' . h($k) . '">' . h($ad) . '</a>';
    }
    $o .= '</div>';

    $stoklar = $veri['stoklar'] ?? [];
    $stokOpts = '<option value="">— Seçin —</option>';
    foreach ($stoklar as $sk) $stokOpts .= '<option value="' . (int)$sk['id'] . '">' . h((string)$sk['kod'] . ' — ' . (string)$sk['ad']) . '</option>';

    if ($sekme === 'bom') {
        $bomlar = $veri['bomlar'] ?? [];
        if ($yazabilir) {
            $o .= '<details class="eop-kart" style="background:var(--eop-bg-soft);"><summary style="cursor:pointer;font-weight:700;">+ Yeni Üretim Reçetesi (BOM) Tanımla</summary>';
            $o .= '<form method="post" action="erp_op_kaydet.php" style="margin-top:12px;">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                . '<input type="hidden" name="islem" value="uretim_bom_kaydet">'
                . '<input type="hidden" name="geri" value="uretim_bom.php?sekme=bom">'
                . '<div class="eop-filtre">'
                . '<div class="eop-alan"><label>BOM Kodu</label><input name="kod" placeholder="BOM-NAR-5KG"></div>'
                . '<div class="eop-alan" style="flex:1;"><label>Reçete Adı</label><input name="ad" required placeholder="İhracat Nar 5kg Paketleme Reçetesi"></div>'
                . '<div class="eop-alan"><label>Mamul Stok ID</label>' . ($stoklar !== [] ? '<select name="urun_stok_id" required>' . $stokOpts . '</select>' : '<input type="number" name="urun_stok_id" min="1" required>') . '</div>'
                . '<div class="eop-alan"><label>Baz Miktar</label><input name="baz_miktar" inputmode="decimal" value="100"></div>'
                . '<div class="eop-alan"><label>Hedef Fire (%)</label><input name="hedef_fire_yuzde" inputmode="decimal" value="5"></div></div>'
                . '<div class="eop-tablo-wrap"><table class="eop-tablo"><thead><tr><th>Bileşen Stok</th><th>Ad</th><th>Tip</th><th class="eop-num">Miktar</th><th class="eop-num">Fire %</th><th></th></tr></thead>'
                . '<tbody id="eopBomBody"><tr>'
                . '<td>' . ($stoklar !== [] ? '<select name="bil_stok[]" required style="padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);">' . $stokOpts . '</select>' : '<input type="number" name="bil_stok[]" min="1" required style="width:90px;padding:6px;">') . '</td>'
                . '<td><input name="bil_ad[]" placeholder="Hammadde / Kasa" style="width:100%;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"></td>'
                . '<td><select name="bil_tip[]" style="padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"><option value="hammadde">Hammadde</option><option value="ambalaj">Ambalaj</option><option value="yardimci">Yardımcı</option></select></td>'
                . '<td><input name="bil_miktar[]" inputmode="decimal" required placeholder="105" style="width:90px;text-align:right;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"></td>'
                . '<td><input name="bil_fire[]" inputmode="decimal" value="0" style="width:70px;text-align:right;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"></td>'
                . '<td><button type="button" class="btn btn-ghost" data-eop-sil="1" style="padding:4px 8px;">&times;</button></td></tr></tbody></table></div>'
                . '<template id="eopBomTpl"><tr><td>' . ($stoklar !== [] ? '<select name="bil_stok[]" required style="padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);">' . $stokOpts . '</select>' : '<input type="number" name="bil_stok[]" min="1" required style="width:90px;padding:6px;">') . '</td><td><input name="bil_ad[]" style="width:100%;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"></td><td><select name="bil_tip[]" style="padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"><option value="hammadde">Hammadde</option><option value="ambalaj">Ambalaj</option><option value="yardimci">Yardımcı</option></select></td><td><input name="bil_miktar[]" inputmode="decimal" required style="width:90px;text-align:right;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"></td><td><input name="bil_fire[]" inputmode="decimal" value="0" style="width:70px;text-align:right;padding:6px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);"></td><td><button type="button" class="btn btn-ghost" data-eop-sil="1" style="padding:4px 8px;">&times;</button></td></tr></template>'
                . '<div class="eop-eylem-satir" style="margin-top:10px;"><button type="button" class="btn btn-ghost" data-eop-ekle="eopBomBody" data-eop-tpl="eopBomTpl">+ Bileşen Ekle</button><button type="submit" class="btn btn-primary">Reçeteyi Kaydet</button></div></form></details>';
        }
        foreach ($bomlar as $b) {
            $o .= '<div class="eop-kart" style="background:var(--eop-bg-soft);">'
                . '<div class="eop-baslik-bar"><div><strong class="eop-kod">' . h((string)$b['kod']) . '</strong> — ' . h((string)$b['ad']) . ' (Baz: ' . h(erp_op_fmt((float)$b['baz_miktar'], 2)) . ' ' . h((string)$b['birim']) . ')</div>'
                . '<span class="eop-rozet eop-rozet--bilgi">Hedef Fire: %' . h(erp_op_fmt((float)$b['hedef_fire_yuzde'], 1)) . '</span></div>'
                . '<div class="eop-tablo-wrap"><table class="eop-tablo"><thead><tr><th>Stok ID</th><th>Bileşen</th><th>Tip</th><th class="eop-num">Sarf Miktarı</th><th class="eop-num">Fire %</th></tr></thead><tbody>';
            foreach (($b['satirlar'] ?? []) as $bs) {
                $o .= '<tr><td class="eop-kod">#' . (int)$bs['bilesen_stok_id'] . '</td><td>' . h((string)$bs['bilesen_ad']) . '</td><td>' . h((string)$bs['bilesen_tip']) . '</td>'
                    . '<td class="eop-num">' . h(erp_op_fmt((float)$bs['miktar'], 3)) . ' ' . h((string)$bs['birim']) . '</td>'
                    . '<td class="eop-num">%' . h(erp_op_fmt((float)$bs['fire_yuzde'], 1)) . '</td></tr>';
            }
            $o .= '</tbody></table></div></div>';
        }
        if ($bomlar === []) $o .= '<p style="text-align:center;color:var(--eop-muted);">Henüz üretim reçetesi (BOM) tanımlanmadı.</p>';
    } elseif ($sekme === 'emirler') {
        $bomlar = $veri['bomlar'] ?? [];
        $emirler = $veri['emirler'] ?? [];
        if ($yazabilir && $bomlar !== []) {
            $o .= '<form method="post" action="erp_op_kaydet.php" class="eop-filtre" style="padding:12px;background:var(--eop-bg-soft);border-radius:10px;border:1px solid var(--eop-border);">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                . '<input type="hidden" name="islem" value="uretim_is_emri_ac">'
                . '<input type="hidden" name="geri" value="uretim_bom.php?sekme=emirler">'
                . '<div class="eop-alan" style="min-width:220px;"><label>Reçete (BOM)</label><select name="bom_id" required>';
            foreach ($bomlar as $b) $o .= '<option value="' . (int)$b['id'] . '">' . h((string)$b['kod'] . ' — ' . (string)$b['ad']) . '</option>';
            $o .= '</select></div>'
                . '<div class="eop-alan"><label>Tarih</label><input type="date" name="tarih" value="' . h(date('Y-m-d')) . '" required></div>'
                . '<div class="eop-alan"><label>Depo</label><input name="depo" value="MERKEZ"></div>'
                . '<div class="eop-alan"><label>Planlanan Miktar</label><input name="planlanan_miktar" inputmode="decimal" required placeholder="1000"></div>'
                . '<button type="submit" class="btn btn-primary">+ İş Emri Aç</button></form>';
        }
        $o .= '<div class="eop-tablo-wrap"><table class="eop-tablo"><thead><tr>'
            . '<th>Emir No / Lot</th><th>Reçete</th><th class="eop-num">Plan</th><th class="eop-num">Üretilen / Fire</th><th class="eop-num">Randıman</th><th class="eop-num">Birim Maliyet</th><th>Durum / Tamamla</th></tr></thead><tbody>';
        foreach ($emirler as $e) {
            $tamam = (string)$e['durum'] === 'tamamlandi';
            $o .= '<tr><td><div class="eop-kod">' . h((string)$e['emir_no']) . '</div><div style="font-size:.78rem;color:var(--eop-muted);">' . h((string)$e['uretim_lot_no']) . '</div></td>'
                . '<td>' . h((string)$e['bom_kod'] . ' — ' . (string)$e['bom_ad']) . '</td>'
                . '<td class="eop-num">' . h(erp_op_fmt((float)$e['planlanan_miktar'], 2)) . '</td>'
                . '<td class="eop-num">' . h(erp_op_fmt((float)$e['uretilen_miktar'], 2)) . ' / ' . h(erp_op_fmt((float)$e['fire_miktar'], 2)) . '</td>'
                . '<td class="eop-num">%' . h(erp_op_fmt((float)$e['randiman_yuzde'], 1)) . '</td>'
                . '<td class="eop-num">' . h(erp_op_fmt((float)$e['birim_maliyet'], 4)) . ' TL</td><td>';
            if ($tamam) {
                $o .= '<span class="eop-rozet eop-rozet--ok">TAMAMLANDI</span>';
            } elseif ($yazabilir) {
                $o .= '<form method="post" action="erp_op_kaydet.php" class="eop-inline-form">'
                    . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                    . '<input type="hidden" name="islem" value="uretim_is_emri_tamamla">'
                    . '<input type="hidden" name="id" value="' . (int)$e['id'] . '">'
                    . '<input type="hidden" name="geri" value="uretim_bom.php?sekme=emirler">'
                    . '<input name="uretilen_miktar" inputmode="decimal" value="' . h((string)$e['planlanan_miktar']) . '" style="width:80px;padding:4px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);" title="Üretilen">'
                    . '<input name="fire_miktar" inputmode="decimal" value="0" style="width:60px;padding:4px;border:1px solid var(--eop-border);border-radius:6px;background:var(--eop-bg);color:var(--eop-text);" title="Fire">'
                    . '<button type="submit" class="btn btn-primary" style="padding:4px 8px;font-size:.8rem;">Tamamla</button></form>';
            }
            $o .= '</td></tr>';
        }
        if ($emirler === []) $o .= '<tr><td colspan="7" style="text-align:center;color:var(--eop-muted);">Henüz üretim iş emri yok.</td></tr>';
        $o .= '</tbody></table></div>';
    } elseif ($sekme === 'iz') {
        $izler = $veri['izler'] ?? [];
        $lotAra = (string)($veri['lot_ara'] ?? '');
        $o .= '<form method="get" class="eop-filtre"><input type="hidden" name="sekme" value="iz">'
            . '<div class="eop-alan"><label>Lot No Ara (Geriye / İleriye İz)</label><input name="lot" value="' . h($lotAra) . '" placeholder="LOT- veya URT-..."></div>'
            . '<button type="submit" class="btn btn-primary">İzle</button></form>';
        $o .= '<div class="eop-tablo-wrap"><table class="eop-tablo"><thead><tr><th>İş Emri</th><th>Tarih</th><th>Kaynak Hammadde/Ambalaj Lotu</th><th class="eop-num">Tüketilen</th><th>&rarr; Üretilen Mamul Lotu</th></tr></thead><tbody>';
        foreach ($izler as $iz) {
            $o .= '<tr><td class="eop-kod">' . h((string)$iz['emir_no']) . '</td><td>' . h(erp_op_tarih_tr((string)$iz['uretim_tarih'])) . '</td>'
                . '<td class="eop-kod">' . h((string)$iz['kaynak_lot_no']) . ' (Stok #' . (int)$iz['kaynak_stok_id'] . ')</td>'
                . '<td class="eop-num">' . h(erp_op_fmt((float)$iz['tuketilen_miktar'], 2)) . '</td>'
                . '<td class="eop-kod"><span class="eop-rozet eop-rozet--ok">' . h((string)$iz['hedef_lot_no']) . '</span></td></tr>';
        }
        if ($izler === []) $o .= '<tr><td colspan="5" style="text-align:center;color:var(--eop-muted);">Lot-to-Lot izlenebilirlik kaydı yok.</td></tr>';
        $o .= '</tbody></table></div>';
    } else {
        $mrp = $veri['mrp'] ?? [];
        $o .= '<div class="eop-tablo-wrap"><table class="eop-tablo"><thead><tr>'
            . '<th>Bileşen</th><th>Tip</th><th class="eop-num">Brüt İhtiyaç</th><th class="eop-num">WMS Mevcut</th><th class="eop-num">Açık Sipariş</th><th class="eop-num">Net Eksik</th><th>Durum</th></tr></thead><tbody>';
        foreach ($mrp as $m) {
            $eksik = (float)$m['net_eksik'] > 0;
            $o .= '<tr><td><strong>' . h((string)$m['bilesen_ad']) . '</strong> (#' . (int)$m['stok_id'] . ')</td><td>' . h((string)$m['bilesen_tip']) . '</td>'
                . '<td class="eop-num">' . h(erp_op_fmt((float)$m['brut_ihtiyac'], 2)) . '</td>'
                . '<td class="eop-num">' . h(erp_op_fmt((float)$m['mevcut_stok'], 2)) . '</td>'
                . '<td class="eop-num">' . h(erp_op_fmt((float)$m['acik_siparis'], 2)) . '</td>'
                . '<td class="eop-num" style="color:' . ($eksik ? 'var(--eop-err)' : 'var(--eop-ok)') . ';font-weight:700;">' . h(erp_op_fmt((float)$m['net_eksik'], 2)) . '</td>'
                . '<td>' . ($eksik ? '<span class="eop-rozet eop-rozet--err">SİPARİŞ GEREKLİ</span>' : '<span class="eop-rozet eop-rozet--ok">YETERLİ</span>') . '</td></tr>';
        }
        if ($mrp === []) $o .= '<tr><td colspan="7" style="text-align:center;color:var(--eop-muted);">Açık iş emrine bağlı MRP ihtiyacı yok.</td></tr>';
        $o .= '</tbody></table></div>';
    }

    $o .= '</div></div>';
    return $o;
}
