<?php
// =========================================================
// config/erp_planlama_ui.php — ERP Faz 4 UI Yardımcıları (CRM/Fiyat, Sabit Kıymet/Bakım, Bütçe/Nakit Akış/Aging)
// Kurallar: Include-only (404) · SIFIR SQL · Tüm dinamik değerler h() ile kaçışlanır
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

function erp_plan_head_varlik(): void
{
    $v = (int)@filemtime(dirname(__DIR__) . '/assets/erp_planlama.css');
    echo '<link rel="stylesheet" href="assets/erp_planlama.css?v=' . $v . '">' . "\n";
}

function erp_plan_scripts(): void
{
    $v = (int)@filemtime(dirname(__DIR__) . '/assets/erp_planlama.js');
    echo '<script src="assets/erp_planlama.js?v=' . $v . '" defer></script>' . "\n";
}

function erp_plan_fmt(float $v, int $d = 2): string
{
    return number_format($v, $d, ',', '.');
}

function erp_plan_tarih_tr(string $t): string
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $t, $m) ? ($m[3] . '.' . $m[2] . '.' . $m[1]) : $t;
}

function erp_plan_ust_menu(string $aktif): string
{
    $sekmeler = [
        'crm_yonetim'     => ['crm_yonetim.php',     'CRM & Fiyat Listeleri'],
        'sabit_kiymet'    => ['sabit_kiymet.php',    'Sabit Kıymet & Bakım'],
        'finans_planlama' => ['finans_planlama.php', 'Bütçe, Aging & Nakit Akış'],
    ];
    $o = '<nav class="ep-sekmeler" aria-label="CRM, Sabit Kıymet ve Finansal Planlama">';
    foreach ($sekmeler as $slug => [$url, $ad]) {
        if (function_exists('muhasebe_sayfa_can') && !muhasebe_sayfa_can($slug)) continue;
        $o .= '<a class="ep-sekme" href="' . h($url) . '"' . ($slug === $aktif ? ' aria-current="page"' : '') . '>' . h($ad) . '</a>';
    }
    return $o . '</nav>';
}

/** @param list<string> $eksik */
function erp_plan_sema_uyari(array $eksik, string $hash, string $baslik): string
{
    return '<div class="ep-wrap"><div class="ep-uyari" role="alert">'
        . '<div style="font-size:1.6rem;" aria-hidden="true">&#9888;</div>'
        . '<div><h2 style="margin:0 0 6px;">' . h($baslik) . ' şeması kurulmamış</h2>'
        . '<p style="margin:0 0 10px;">Eksik tablolar: <code>' . h(implode(', ', $eksik)) . '</code>. Yönetici Şema Migrasyon panelinden ilgili kartı çalıştırın.</p>'
        . '<a class="btn btn-primary" href="migrate.php#' . h($hash) . '">Şema Migrasyon &rsaquo; ' . h($baslik) . '</a>'
        . '</div></div></div>';
}

// ── 1) ERP-8: CRM & Fiyat Listeleri ────────────────────────────────────────

function crm_yonetim_render(string $sekme, array $veri, bool $yazabilir): string
{
    $csrf = function_exists('csrf_token') ? csrf_token() : '';
    $asamalar = crm_asamalari();
    $o = '<div class="ep-wrap">' . erp_plan_ust_menu('crm_yonetim');
    $o .= '<div class="ep-kart"><div class="ep-eylem-satir" style="margin-bottom:14px;">'
        . '<a class="' . ($sekme === 'pipeline' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="crm_yonetim.php?sekme=pipeline">Satış Fırsatları (Pipeline)</a>'
        . '<a class="' . ($sekme === 'fiyat' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="crm_yonetim.php?sekme=fiyat">Fiyat Listeleri &amp; Kampanya</a>'
        . '<a class="' . ($sekme === 'teklif' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="crm_yonetim.php?sekme=teklif">Teklifler &rarr; Sipariş</a>'
        . '<a class="' . ($sekme === 'claim' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="crm_yonetim.php?sekme=claim">Şikayet / Kalite Claim</a>'
        . '</div>';

    if ($sekme === 'pipeline') {
        $firsatlar = $veri['firsatlar'] ?? [];
        if ($yazabilir) {
            $o .= '<form method="post" action="erp_plan_kaydet.php" class="ep-filtre" style="padding:12px;background:var(--ep-bg-soft);border-radius:10px;border:1px solid var(--ep-border);">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="crm_firsat_kaydet"><input type="hidden" name="geri" value="crm_yonetim.php?sekme=pipeline">'
                . '<div class="ep-alan"><label>Müşteri / Aday</label><input name="musteri_ad" required placeholder="Örn. EuroFresh GmbH"></div>'
                . '<div class="ep-alan" style="flex:1;"><label>Fırsat Başlığı</label><input name="baslik" required placeholder="2026 Yaz Kiraz İhracat Programı"></div>'
                . '<div class="ep-alan"><label>Aşama</label><select name="asama">';
            foreach ($asamalar as $ak => $al) $o .= '<option value="' . h($ak) . '">' . h($al) . '</option>';
            $o .= '</select></div>'
                . '<div class="ep-alan"><label>Tahmini Tutar</label><input name="tahmini_tutar" inputmode="decimal" value="0"></div>'
                . '<div class="ep-alan"><label>PB</label><select name="para_birimi"><option value="EUR">EUR</option><option value="TRY">TRY</option><option value="USD">USD</option></select></div>'
                . '<button type="submit" class="btn btn-primary">Fırsat Ekle</button></form>';
        }
        $o .= '<div class="ep-tablo-wrap"><table class="ep-tablo"><thead><tr><th>Kod</th><th>Müşteri / Başlık</th><th>Aşama</th><th class="ep-num">Olasılık</th><th class="ep-num">Tahmini Tutar</th><th>Güncelle</th></tr></thead><tbody>';
        foreach ($firsatlar as $f) {
            $as = (string)$f['asama'];
            $roz = $as === 'kazanildi' ? 'ep-rozet--ok' : ($as === 'kaybedildi' ? 'ep-rozet--err' : 'ep-rozet--warn');
            $o .= '<tr><td class="ep-kod">' . h((string)$f['kod']) . '</td>'
                . '<td><strong>' . h((string)$f['musteri_ad']) . '</strong><div style="font-size:.82rem;color:var(--ep-muted);">' . h((string)$f['baslik']) . '</div></td>'
                . '<td><span class="ep-rozet ' . $roz . '">' . h($asamalar[$as] ?? $as) . '</span></td>'
                . '<td class="ep-num">%' . (int)$f['olasilik_yuzde'] . '</td>'
                . '<td class="ep-num"><strong>' . h(erp_plan_fmt((float)$f['tahmini_tutar'])) . ' ' . h((string)$f['para_birimi']) . '</strong></td><td>';
            if ($yazabilir) {
                $o .= '<form method="post" action="erp_plan_kaydet.php" class="ep-inline-form">'
                    . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="crm_firsat_asama">'
                    . '<input type="hidden" name="id" value="' . (int)$f['id'] . '"><input type="hidden" name="geri" value="crm_yonetim.php?sekme=pipeline">'
                    . '<select name="asama">';
                foreach ($asamalar as $ak => $al) $o .= '<option value="' . h($ak) . '"' . ($ak === $as ? ' selected' : '') . '>' . h($al) . '</option>';
                $o .= '</select><button type="submit" class="btn btn-ghost" style="padding:4px 8px;font-size:.8rem;">Kaydet</button></form>';
            }
            $o .= '</td></tr>';
        }
        if ($firsatlar === []) $o .= '<tr><td colspan="6" style="text-align:center;color:var(--ep-muted);">Henüz satış fırsatı yok.</td></tr>';
        $o .= '</tbody></table></div>';
    } elseif ($sekme === 'fiyat') {
        $listeler = $veri['fiyat_listeleri'] ?? [];
        if ($yazabilir) {
            $o .= '<form method="post" action="erp_plan_kaydet.php" class="ep-filtre" style="padding:12px;background:var(--ep-bg-soft);border-radius:10px;border:1px solid var(--ep-border);">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="crm_fiyat_listesi_kaydet"><input type="hidden" name="geri" value="crm_yonetim.php?sekme=fiyat">'
                . '<div class="ep-alan"><label>Liste Kodu</label><input name="kod" placeholder="EUR-2026-YAZ"></div>'
                . '<div class="ep-alan" style="flex:1;"><label>Liste Adı</label><input name="ad" required placeholder="Avrupa İhracat Fiyat Listesi"></div>'
                . '<div class="ep-alan"><label>Müşteri Grubu</label><input name="musteri_grubu" value="IHRACAT"></div>'
                . '<div class="ep-alan"><label>PB</label><select name="para_birimi"><option value="EUR">EUR</option><option value="TRY">TRY</option><option value="USD">USD</option></select></div>'
                . '<div class="ep-alan"><label>Varsayılan İskonto %</label><input name="varsayilan_iskonto" inputmode="decimal" value="0"></div>'
                . '<button type="submit" class="btn btn-primary">Liste Oluştur</button></form>';
            if ($listeler !== []) {
                $o .= '<form method="post" action="erp_plan_kaydet.php" class="ep-filtre" style="padding:12px;background:var(--ep-bg-soft);border-radius:10px;border:1px solid var(--ep-border);">'
                    . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="crm_fiyat_satir_ekle"><input type="hidden" name="geri" value="crm_yonetim.php?sekme=fiyat">'
                    . '<div class="ep-alan"><label>Liste</label><select name="liste_id">';
                foreach ($listeler as $fl) $o .= '<option value="' . (int)$fl['id'] . '">' . h((string)$fl['kod'] . ' — ' . (string)$fl['ad']) . '</option>';
                $o .= '</select></div>'
                    . '<div class="ep-alan" style="flex:1;"><label>Ürün Adı</label><input name="urun_ad" required placeholder="Napolyon Kiraz 28mm+"></div>'
                    . '<div class="ep-alan"><label>Birim</label><input name="birim" value="kg"></div>'
                    . '<div class="ep-alan"><label>Birim Fiyat</label><input name="birim_fiyat" inputmode="decimal" required></div>'
                    . '<div class="ep-alan"><label>Min Miktar</label><input name="min_miktar" inputmode="decimal" value="0"></div>'
                    . '<div class="ep-alan"><label>İskonto %</label><input name="iskonto_yuzde" inputmode="decimal" value="0"></div>'
                    . '<button type="submit" class="btn btn-primary">Fiyat Satırı Ekle</button></form>';
            }
        }
        foreach ($listeler as $fl) {
            $o .= '<div class="ep-kart" style="background:var(--ep-bg-soft);"><h3>' . h((string)$fl['kod'] . ' — ' . (string)$fl['ad'] . ' (' . (string)$fl['musteri_grubu'] . ' · ' . (string)$fl['para_birimi'] . ')') . '</h3>'
                . '<div class="ep-tablo-wrap"><table class="ep-tablo"><thead><tr><th>Ürün</th><th>Birim</th><th class="ep-num">Min Miktar</th><th class="ep-num">Liste Fiyat</th><th class="ep-num">İskonto %</th></tr></thead><tbody>';
            foreach (($fl['satirlar'] ?? []) as $s) {
                $o .= '<tr><td><strong>' . h((string)$s['urun_ad']) . '</strong></td><td>' . h((string)$s['birim']) . '</td>'
                    . '<td class="ep-num">' . h(erp_plan_fmt((float)$s['min_miktar'], 1)) . '</td>'
                    . '<td class="ep-num"><strong>' . h(erp_plan_fmt((float)$s['birim_fiyat'], 2)) . '</strong></td>'
                    . '<td class="ep-num">%' . h(erp_plan_fmt((float)$s['iskonto_yuzde'], 2)) . '</td></tr>';
            }
            if (($fl['satirlar'] ?? []) === []) $o .= '<tr><td colspan="5" style="text-align:center;color:var(--ep-muted);">Fiyat satırı yok.</td></tr>';
            $o .= '</tbody></table></div></div>';
        }
        if ($listeler === []) $o .= '<p style="text-align:center;color:var(--ep-muted);">Henüz fiyat listesi tanımlanmadı.</p>';
    } elseif ($sekme === 'teklif') {
        $teklifler = $veri['teklifler'] ?? [];
        $listeler = $veri['fiyat_listeleri'] ?? [];
        if ($yazabilir) {
            $o .= '<form method="post" action="erp_plan_kaydet.php" class="ep-filtre" style="padding:12px;background:var(--ep-bg-soft);border-radius:10px;border:1px solid var(--ep-border);">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="crm_teklif_kaydet"><input type="hidden" name="geri" value="crm_yonetim.php?sekme=teklif">'
                . '<div class="ep-alan"><label>Müşteri</label><input name="musteri_ad" required></div>'
                . '<div class="ep-alan"><label>Fiyat Listesi (Ops.)</label><select name="fiyat_liste_id"><option value="0">— Manuel —</option>';
            foreach ($listeler as $fl) $o .= '<option value="' . (int)$fl['id'] . '">' . h((string)$fl['kod']) . '</option>';
            $o .= '</select></div>'
                . '<div class="ep-alan" style="flex:1;"><label>Ürün Özeti</label><input name="urun_ozet" required></div>'
                . '<div class="ep-alan"><label>Miktar</label><input name="miktar" inputmode="decimal" value="1000"></div>'
                . '<div class="ep-alan"><label>Birim Fiyat</label><input name="birim_fiyat" inputmode="decimal" value="0"></div>'
                . '<div class="ep-alan"><label>İskonto %</label><input name="iskonto_yuzde" inputmode="decimal" value="0"></div>'
                . '<div class="ep-alan"><label>PB</label><select name="para_birimi"><option value="EUR">EUR</option><option value="TRY">TRY</option><option value="USD">USD</option></select></div>'
                . '<button type="submit" class="btn btn-primary">Teklif Oluştur</button></form>';
        }
        $o .= '<div class="ep-tablo-wrap"><table class="ep-tablo"><thead><tr><th>Teklif No</th><th>Müşteri / Ürün</th><th class="ep-num">Miktar</th><th class="ep-num">Genel Toplam</th><th>Durum</th><th>Dönüşüm</th></tr></thead><tbody>';
        foreach ($teklifler as $t) {
            $don = (string)$t['durum'] === 'siparise_donustu';
            $o .= '<tr><td class="ep-kod">' . h((string)$t['teklif_no']) . '</td>'
                . '<td><strong>' . h((string)$t['musteri_ad']) . '</strong> — ' . h((string)$t['urun_ozet']) . '</td>'
                . '<td class="ep-num">' . h(erp_plan_fmt((float)$t['miktar'], 1)) . '</td>'
                . '<td class="ep-num"><strong>' . h(erp_plan_fmt((float)$t['genel_toplam'])) . ' ' . h((string)$t['para_birimi']) . '</strong></td>'
                . '<td><span class="ep-rozet ' . ($don ? 'ep-rozet--ok' : 'ep-rozet--warn') . '">' . h(strtoupper((string)$t['durum'])) . '</span></td><td>';
            if ($don) {
                $o .= '<span class="ep-kod">' . h((string)$t['siparis_ref']) . '</span>';
            } elseif ($yazabilir) {
                $o .= '<form method="post" action="erp_plan_kaydet.php" class="ep-inline-form">'
                    . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="crm_teklif_siparise">'
                    . '<input type="hidden" name="id" value="' . (int)$t['id'] . '"><input type="hidden" name="geri" value="crm_yonetim.php?sekme=teklif">'
                    . '<button type="submit" class="btn btn-primary" style="padding:4px 8px;font-size:.8rem;">Siparişe Dönüştür</button></form>';
            }
            $o .= '</td></tr>';
        }
        if ($teklifler === []) $o .= '<tr><td colspan="6" style="text-align:center;color:var(--ep-muted);">Henüz satış teklifi yok.</td></tr>';
        $o .= '</tbody></table></div>';
    } else {
        $claimler = $veri['claimler'] ?? [];
        if ($yazabilir) {
            $o .= '<form method="post" action="erp_plan_kaydet.php" class="ep-filtre" style="padding:12px;background:var(--ep-bg-soft);border-radius:10px;border:1px solid var(--ep-border);">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="crm_claim_kaydet"><input type="hidden" name="geri" value="crm_yonetim.php?sekme=claim">'
                . '<div class="ep-alan"><label>Müşteri</label><input name="musteri_ad" required></div>'
                . '<div class="ep-alan"><label>Sevkiyat Ref</label><input name="sevkiyat_ref" placeholder="TC900..."></div>'
                . '<div class="ep-alan"><label>Kategori</label><select name="kategori"><option value="kalite">Kalite</option><option value="fire">Fire</option><option value="teslimat">Teslimat</option><option value="ambalaj">Ambalaj</option><option value="fatura">Fatura</option></select></div>'
                . '<div class="ep-alan"><label>Talep Tutar</label><input name="talep_tutar" inputmode="decimal" value="0"></div>'
                . '<div class="ep-alan" style="flex:1;"><label>Açıklama</label><input name="aciklama" placeholder="Kalite/claim detayı"></div>'
                . '<button type="submit" class="btn btn-primary">Claim Aç</button></form>';
        }
        $o .= '<div class="ep-tablo-wrap"><table class="ep-tablo"><thead><tr><th>Claim No</th><th>Müşteri / Ref</th><th>Kategori</th><th class="ep-num">Talep</th><th class="ep-num">Kabul</th><th>Durum</th></tr></thead><tbody>';
        foreach ($claimler as $cl) {
            $d = (string)$cl['durum'];
            $roz = $d === 'cozuldu' ? 'ep-rozet--ok' : ($d === 'reddedildi' ? 'ep-rozet--err' : 'ep-rozet--warn');
            $o .= '<tr><td class="ep-kod">' . h((string)$cl['claim_no']) . '</td>'
                . '<td><strong>' . h((string)$cl['musteri_ad']) . '</strong> ' . h((string)$cl['sevkiyat_ref']) . '</td>'
                . '<td>' . h(strtoupper((string)$cl['kategori'])) . '</td>'
                . '<td class="ep-num">' . h(erp_plan_fmt((float)$cl['talep_tutar'])) . ' ' . h((string)$cl['para_birimi']) . '</td>'
                . '<td class="ep-num">' . h(erp_plan_fmt((float)$cl['kabul_tutar'])) . '</td>'
                . '<td><span class="ep-rozet ' . $roz . '">' . h(strtoupper($d)) . '</span></td></tr>';
        }
        if ($claimler === []) $o .= '<tr><td colspan="6" style="text-align:center;color:var(--ep-muted);">Açık kalite claim kaydı yok.</td></tr>';
        $o .= '</tbody></table></div>';
    }
    $o .= '</div></div>';
    return $o;
}

// ── 2) ERP-9: Sabit Kıymet, Amortisman & Bakım ─────────────────────────────

function sabit_kiymet_render(string $sekme, array $veri, bool $yazabilir): string
{
    $csrf = function_exists('csrf_token') ? csrf_token() : '';
    $kartlar = $veri['kartlar'] ?? [];
    $o = '<div class="ep-wrap">' . erp_plan_ust_menu('sabit_kiymet');
    $o .= '<div class="ep-kart"><div class="ep-eylem-satir" style="margin-bottom:14px;">'
        . '<a class="' . ($sekme === 'kartlar' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="sabit_kiymet.php?sekme=kartlar">Demirbaş, Makine &amp; Amortisman</a>'
        . '<a class="' . ($sekme === 'bakim' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="sabit_kiymet.php?sekme=bakim">Periyodik Bakım &amp; Onarım</a>'
        . '</div>';

    if ($sekme === 'kartlar') {
        if ($yazabilir) {
            $o .= '<form method="post" action="erp_plan_kaydet.php" class="ep-filtre" style="padding:12px;background:var(--ep-bg-soft);border-radius:10px;border:1px solid var(--ep-border);">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="sk_kart_kaydet"><input type="hidden" name="geri" value="sabit_kiymet.php?sekme=kartlar">'
                . '<div class="ep-alan"><label>Kod</label><input name="kod" placeholder="MAK-001"></div>'
                . '<div class="ep-alan" style="flex:1;"><label>Sabit Kıymet Adı</label><input name="ad" required placeholder="Boyama / Paketleme Hattı"></div>'
                . '<div class="ep-alan"><label>Kategori</label><select name="kategori"><option value="makine">Makine (253)</option><option value="arac">Taşıt (254)</option><option value="demirbas">Demirbaş (255)</option></select></div>'
                . '<div class="ep-alan"><label>Alış Tarihi</label><input type="date" name="alis_tarih" value="' . h(date('Y-m-d')) . '"></div>'
                . '<div class="ep-alan"><label>Alış Bedeli</label><input name="alis_bedeli" inputmode="decimal" required placeholder="250000"></div>'
                . '<div class="ep-alan"><label>Ömür (Yıl)</label><input type="number" name="faydali_omur_yil" min="1" max="50" value="5"></div>'
                . '<div class="ep-alan"><label>Yöntem</label><select name="yontem"><option value="normal">Normal</option><option value="azalan">Azalan Bakiyeler</option></select></div>'
                . '<div class="ep-alan"><label>Zimmet</label><input name="zimmet_kisi" placeholder="Depo Şefi"></div>'
                . '<button type="submit" class="btn btn-primary">Kaydet &amp; Planla</button></form>';
        }
        foreach ($kartlar as $k) {
            $o .= '<div class="ep-kart" style="background:var(--ep-bg-soft);"><h3>' . h((string)$k['kod'] . ' — ' . (string)$k['ad'] . ' (' . erp_plan_fmt((float)$k['alis_bedeli']) . ' TL · ' . strtoupper((string)$k['yontem']) . ')') . '</h3>'
                . '<div class="ep-tablo-wrap"><table class="ep-tablo"><thead><tr><th>Yıl</th><th class="ep-num">Oran %</th><th class="ep-num">Dönem Amortisman</th><th class="ep-num">Birikmiş</th><th class="ep-num">Net Defter</th><th>Yevmiye (770/257)</th></tr></thead><tbody>';
            foreach (($k['amortismanlar'] ?? []) as $am) {
                $o .= '<tr><td class="ep-kod">' . (int)$am['yil'] . '</td><td class="ep-num">%' . h(erp_plan_fmt((float)$am['oran_yuzde'], 2)) . '</td>'
                    . '<td class="ep-num"><strong>' . h(erp_plan_fmt((float)$am['donem_tutar'])) . '</strong></td>'
                    . '<td class="ep-num">' . h(erp_plan_fmt((float)$am['birikmis_tutar'])) . '</td>'
                    . '<td class="ep-num">' . h(erp_plan_fmt((float)$am['net_defter_degeri'])) . '</td><td>';
                if (!empty($am['muh_fis_id'])) {
                    $o .= '<a class="ep-rozet ep-rozet--ok" href="muh_yevmiye.php?fis_id=' . (int)$am['muh_fis_id'] . '">Fiş #' . (int)$am['muh_fis_id'] . '</a>';
                } elseif ($yazabilir) {
                    $o .= '<form method="post" action="erp_plan_kaydet.php" class="ep-inline-form"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="sk_amortisman_muhasebelestir"><input type="hidden" name="id" value="' . (int)$am['id'] . '"><input type="hidden" name="geri" value="sabit_kiymet.php?sekme=kartlar"><button type="submit" class="btn btn-primary" style="padding:4px 8px;font-size:.8rem;">Fiş Kes</button></form>';
                }
                $o .= '</td></tr>';
            }
            $o .= '</tbody></table></div></div>';
        }
        if ($kartlar === []) $o .= '<p style="text-align:center;color:var(--ep-muted);">Kayıtlı sabit kıymet yok.</p>';
    } else {
        $bakimlar = $veri['bakimlar'] ?? [];
        if ($yazabilir && $kartlar !== []) {
            $o .= '<form method="post" action="erp_plan_kaydet.php" class="ep-filtre" style="padding:12px;background:var(--ep-bg-soft);border-radius:10px;border:1px solid var(--ep-border);">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="sk_bakim_kaydet"><input type="hidden" name="geri" value="sabit_kiymet.php?sekme=bakim">'
                . '<div class="ep-alan"><label>Sabit Kıymet</label><select name="kart_id">';
            foreach ($kartlar as $k) $o .= '<option value="' . (int)$k['id'] . '">' . h((string)$k['kod'] . ' — ' . (string)$k['ad']) . '</option>';
            $o .= '</select></div>'
                . '<div class="ep-alan"><label>Bakım Tarihi</label><input type="date" name="bakim_tarih" value="' . h(date('Y-m-d')) . '"></div>'
                . '<div class="ep-alan"><label>Sonraki Bakım</label><input type="date" name="sonraki_bakim_tarih" value="' . h(date('Y-m-d', strtotime('+6 months'))) . '"></div>'
                . '<div class="ep-alan"><label>Tür</label><select name="tur"><option value="periyodik">Periyodik</option><option value="ariza">Arıza</option><option value="muayene">Muayene</option><option value="sigorta">Sigorta</option></select></div>'
                . '<div class="ep-alan"><label>Maliyet</label><input name="maliyet" inputmode="decimal" value="0"></div>'
                . '<div class="ep-alan" style="flex:1;"><label>Servis / Not</label><input name="aciklama" placeholder="Yağ/filtre değişimi"></div>'
                . '<button type="submit" class="btn btn-primary">Bakım Ekle</button></form>';
        }
        $o .= '<div class="ep-tablo-wrap"><table class="ep-tablo"><thead><tr><th>Tarih</th><th>Sabit Kıymet</th><th>Tür</th><th>Sonraki Bakım</th><th class="ep-num">Maliyet</th><th>Açıklama</th></tr></thead><tbody>';
        foreach ($bakimlar as $b) {
            $o .= '<tr><td>' . h(erp_plan_tarih_tr((string)$b['bakim_tarih'])) . '</td><td><strong>' . h((string)$b['sk_kod']) . '</strong> ' . h((string)$b['sk_ad']) . '</td>'
                . '<td>' . h(strtoupper((string)$b['tur'])) . '</td><td>' . h(erp_plan_tarih_tr((string)($b['sonraki_bakim_tarih'] ?? ''))) . '</td>'
                . '<td class="ep-num"><strong>' . h(erp_plan_fmt((float)$b['maliyet'])) . ' TL</strong></td><td>' . h((string)$b['aciklama']) . '</td></tr>';
        }
        if ($bakimlar === []) $o .= '<tr><td colspan="6" style="text-align:center;color:var(--ep-muted);">Henüz bakım kaydı yok.</td></tr>';
        $o .= '</tbody></table></div>';
    }
    $o .= '</div></div>';
    return $o;
}

// ── 3) ERP-10: Bütçe, Vade Yaşlandırma (Aging) & Nakit Akış Projeksiyonu ──

function finans_planlama_render(string $sekme, array $veri, bool $yazabilir): string
{
    $csrf = function_exists('csrf_token') ? csrf_token() : '';
    $o = '<div class="ep-wrap">' . erp_plan_ust_menu('finans_planlama');
    $o .= '<div class="ep-kart"><div class="ep-eylem-satir" style="margin-bottom:14px;">'
        . '<a class="' . ($sekme === 'aging' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="finans_planlama.php?sekme=aging">Vade Yaşlandırma (30/60/90/120+)</a>'
        . '<a class="' . ($sekme === 'nakit' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="finans_planlama.php?sekme=nakit">Nakit Akış Projeksiyonu (90 Gün)</a>'
        . '<a class="' . ($sekme === 'butce' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="finans_planlama.php?sekme=butce">Bütçe &amp; Sapma Kontrolü</a>'
        . '</div>';

    if ($sekme === 'aging') {
        $ag = $veri['aging'] ?? ['alacak' => [], 'borc' => [], 'net_toplam' => 0.0, 'kalemler' => []];
        $al = $ag['alacak']; $bo = $ag['borc'];
        $o .= '<div class="ep-tablo-wrap"><table class="ep-tablo"><thead><tr><th>Tür</th><th class="ep-num">Güncel</th><th class="ep-num">0–30 Gün</th><th class="ep-num">31–60 Gün</th><th class="ep-num">61–90 Gün</th><th class="ep-num">91–120 Gün</th><th class="ep-num">120+ Gün</th><th class="ep-num">Toplam</th></tr></thead><tbody>'
            . '<tr><td><strong>Alacaklar (Tahsilat)</strong></td><td class="ep-num">' . h(erp_plan_fmt((float)($al['guncel'] ?? 0))) . '</td><td class="ep-num">' . h(erp_plan_fmt((float)($al['g0_30'] ?? 0))) . '</td><td class="ep-num">' . h(erp_plan_fmt((float)($al['g31_60'] ?? 0))) . '</td><td class="ep-num">' . h(erp_plan_fmt((float)($al['g61_90'] ?? 0))) . '</td><td class="ep-num">' . h(erp_plan_fmt((float)($al['g91_120'] ?? 0))) . '</td><td class="ep-num">' . h(erp_plan_fmt((float)($al['g120_plus'] ?? 0))) . '</td><td class="ep-num"><strong>' . h(erp_plan_fmt((float)($al['toplam'] ?? 0))) . '</strong></td></tr>'
            . '<tr><td><strong>Borçlar (Ödeme)</strong></td><td class="ep-num">' . h(erp_plan_fmt((float)($bo['guncel'] ?? 0))) . '</td><td class="ep-num">' . h(erp_plan_fmt((float)($bo['g0_30'] ?? 0))) . '</td><td class="ep-num">' . h(erp_plan_fmt((float)($bo['g31_60'] ?? 0))) . '</td><td class="ep-num">' . h(erp_plan_fmt((float)($bo['g61_90'] ?? 0))) . '</td><td class="ep-num">' . h(erp_plan_fmt((float)($bo['g91_120'] ?? 0))) . '</td><td class="ep-num">' . h(erp_plan_fmt((float)($bo['g120_plus'] ?? 0))) . '</td><td class="ep-num"><strong>' . h(erp_plan_fmt((float)($bo['toplam'] ?? 0))) . '</strong></td></tr>'
            . '</tbody></table></div>';
    } elseif ($sekme === 'nakit') {
        $pr = $veri['projeksiyon'] ?? ['acilis_nakit' => 0.0, 'haftalar' => [], 'en_dusuk_bakiye' => 0.0, 'negatif_risk' => false];
        if ($yazabilir) {
            $o .= '<form method="post" action="erp_plan_kaydet.php" class="ep-filtre" style="padding:12px;background:var(--ep-bg-soft);border-radius:10px;border:1px solid var(--ep-border);">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="fp_nakit_kalem_kaydet"><input type="hidden" name="geri" value="finans_planlama.php?sekme=nakit">'
                . '<div class="ep-alan"><label>Vade Tarihi</label><input type="date" name="vade_tarih" value="' . h(date('Y-m-d', strtotime('+10 days'))) . '"></div>'
                . '<div class="ep-alan"><label>Yön</label><select name="yon"><option value="giris">Nakit Girişi</option><option value="cikis">Nakit Çıkışı</option></select></div>'
                . '<div class="ep-alan"><label>Karşı Taraf</label><input name="karsi_taraf" placeholder="Müşteri / Tedarikçi"></div>'
                . '<div class="ep-alan"><label>Tutar (TL)</label><input name="tutar" inputmode="decimal" required></div>'
                . '<div class="ep-alan" style="flex:1;"><label>Açıklama</label><input name="aciklama"></div>'
                . '<button type="submit" class="btn btn-primary">Planlı Kalem Ekle</button></form>';
        }
        $riskRoz = !empty($pr['negatif_risk']) ? '<span class="ep-rozet ep-rozet--err">NEGATİF NAKİT RİSKİ</span>' : '<span class="ep-rozet ep-rozet--ok">NAKİT DENGESİ POZİTİF</span>';
        $o .= '<p><strong>Açılış Nakit:</strong> ' . h(erp_plan_fmt((float)$pr['acilis_nakit'])) . ' TL · <strong>En Düşük Tahmin:</strong> ' . h(erp_plan_fmt((float)$pr['en_dusuk_bakiye'])) . ' TL · ' . $riskRoz . '</p>';
        $o .= '<div class="ep-tablo-wrap"><table class="ep-tablo"><thead><tr><th>Hafta</th><th>Dönem</th><th class="ep-num">Giriş</th><th class="ep-num">Çıkış</th><th class="ep-num">Net</th><th class="ep-num">Kümülatif Bakiye</th></tr></thead><tbody>';
        foreach (($pr['haftalar'] ?? []) as $h) {
            $o .= '<tr><td class="ep-kod">H' . (int)$h['hafta_no'] . '</td><td>' . h(erp_plan_tarih_tr((string)$h['bas_tarih']) . ' — ' . erp_plan_tarih_tr((string)$h['bit_tarih'])) . '</td>'
                . '<td class="ep-num">' . h(erp_plan_fmt((float)$h['giris'])) . '</td>'
                . '<td class="ep-num">' . h(erp_plan_fmt((float)$h['cikis'])) . '</td>'
                . '<td class="ep-num">' . h(erp_plan_fmt((float)$h['net'])) . '</td>'
                . '<td class="ep-num"><strong>' . h(erp_plan_fmt((float)$h['kumulatif'])) . '</strong></td></tr>';
        }
        $o .= '</tbody></table></div>';
    } else {
        $butceler = $veri['butceler'] ?? [];
        if ($yazabilir) {
            $o .= '<form method="post" action="erp_plan_kaydet.php" class="ep-filtre" style="padding:12px;background:var(--ep-bg-soft);border-radius:10px;border:1px solid var(--ep-border);">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="fp_butce_kaydet"><input type="hidden" name="geri" value="finans_planlama.php?sekme=butce">'
                . '<div class="ep-alan"><label>Yıl</label><input type="number" name="yil" value="' . (int)date('Y') . '"></div>'
                . '<div class="ep-alan"><label>Ay</label><input type="number" name="ay" min="1" max="12" value="' . (int)date('n') . '"></div>'
                . '<div class="ep-alan"><label>Departman</label><input name="departman" value="URETIM"></div>'
                . '<div class="ep-alan"><label>TDHP Hesap</label><input name="hesap_kod" value="770"></div>'
                . '<div class="ep-alan"><label>Tür</label><select name="tur"><option value="gider">Gider</option><option value="gelir">Gelir</option></select></div>'
                . '<div class="ep-alan"><label>Planlanan</label><input name="planlanan_tutar" inputmode="decimal" required></div>'
                . '<button type="submit" class="btn btn-primary">Bütçe Kaydet</button></form>';
        }
        $o .= '<div class="ep-tablo-wrap"><table class="ep-tablo"><thead><tr><th>Dönem</th><th>Departman / Hesap</th><th class="ep-num">Planlanan</th><th class="ep-num">Gerçekleşen</th><th class="ep-num">Sapma</th><th>Durum</th></tr></thead><tbody>';
        foreach ($butceler as $b) {
            $asim = !empty($b['asim']);
            $o .= '<tr><td class="ep-kod">' . sprintf('%04d-%02d', (int)$b['yil'], (int)$b['ay']) . '</td>'
                . '<td><strong>' . h((string)$b['departman']) . '</strong> · <code>' . h((string)$b['hesap_kod']) . '</code></td>'
                . '<td class="ep-num">' . h(erp_plan_fmt((float)$b['planlanan_tutar'])) . '</td>'
                . '<td class="ep-num">' . h(erp_plan_fmt((float)$b['gerceklesen_etkin'])) . '</td>'
                . '<td class="ep-num"><strong>' . h(erp_plan_fmt((float)$b['sapma_tutar'])) . '</strong> (%' . h(erp_plan_fmt((float)$b['gerceklesme_yuzde'], 1)) . ')</td>'
                . '<td><span class="ep-rozet ' . ($asim ? 'ep-rozet--err' : 'ep-rozet--ok') . '">' . ($asim ? 'BÜTÇE AŞIMI' : 'UYUMLU') . '</span></td></tr>';
        }
        if ($butceler === []) $o .= '<tr><td colspan="6" style="text-align:center;color:var(--ep-muted);">Henüz bütçe kalemi tanımlanmadı.</td></tr>';
        $o .= '</tbody></table></div>';
    }
    $o .= '</div></div>';
    return $o;
}
