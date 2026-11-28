<?php
// =========================================================
// config/bi_rapor_ui.php — ERP Faz 5 BI Raporlama Merkezi & Tasarımcı UI
// Kurallar: Include-only (404) · SIFIR SQL · Tüm dinamik değerler h() ile kaçışlanır
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

function bi_head_varlik(): void
{
    $v = (int)@filemtime(dirname(__DIR__) . '/assets/bi_rapor.css');
    echo '<link rel="stylesheet" href="assets/bi_rapor.css?v=' . $v . '">' . "\n";
}

function bi_scripts(): void
{
    $v = (int)@filemtime(dirname(__DIR__) . '/assets/bi_rapor.js');
    echo '<script src="assets/bi_rapor.js?v=' . $v . '" defer></script>' . "\n";
}

function bi_fmt(float $v, int $d = 2): string
{
    return number_format($v, $d, ',', '.');
}

function bi_ust_menu(string $aktif): string
{
    $sekmeler = [
        'rapor_merkezi'   => ['rapor_merkezi.php',   'Raporlama & BI Merkezi'],
        'rapor_tanimlari' => ['rapor_tanimlari.php', 'Dinamik BI Tasarımcısı & Şablonlar'],
        'reports'         => ['reports.php',         'Eski Raporlar (Arşiv)'],
    ];
    $o = '<nav class="bi-sekmeler" aria-label="Raporlama Merkezi">';
    foreach ($sekmeler as $slug => [$url, $ad]) {
        if ($slug !== 'reports' && function_exists('muhasebe_sayfa_can') && !muhasebe_sayfa_can($slug)) continue;
        $o .= '<a class="bi-sekme" href="' . h($url) . '"' . ($slug === $aktif ? ' aria-current="page"' : '') . '>' . h($ad) . '</a>';
    }
    return $o . '</nav>';
}

/** @param list<string> $eksik */
function bi_sema_uyari(array $eksik, string $hash, string $baslik): string
{
    return '<div class="bi-wrap"><div class="bi-uyari" role="alert">'
        . '<div style="font-size:1.6rem;" aria-hidden="true">&#9888;</div>'
        . '<div><h2 style="margin:0 0 6px;">' . h($baslik) . ' şeması kurulmamış</h2>'
        . '<p style="margin:0 0 10px;">Eksik tablolar: <code>' . h(implode(', ', $eksik)) . '</code>. Yönetici Şema Migrasyon panelinden ilgili kartı çalıştırın.</p>'
        . '<a class="btn btn-primary" href="migrate.php#' . h($hash) . '">Şema Migrasyon &rsaquo; ' . h($baslik) . '</a>'
        . '</div></div></div>';
}

// ── 1) Raporlama & BI Merkezi (rapor_merkezi.php) ──────────────────────────

function bi_rapor_merkezi_render(array $veri): string
{
    $kpis      = $veri['kpis'] ?? [];
    $kaynaklar = bi_veri_kaynaklari();
    $sablonlar = $veri['sablonlar'] ?? [];
    $secKaynak = (string)($veri['sec_kaynak'] ?? '');
    $secSablon = (int)($veri['sec_sablon'] ?? 0);
    $basT      = (string)($veri['bas_tarih'] ?? date('Y-m-01'));
    $bitT      = (string)($veri['bit_tarih'] ?? date('Y-m-d'));
    $ara       = (string)($veri['ara'] ?? '');
    $sonuc     = $veri['sonuc'] ?? null;

    $o = '<div class="bi-wrap">' . bi_ust_menu('rapor_merkezi');

    // Yönetici KPI Özeti
    $o .= '<div class="bi-kart"><h2>Konsolide Yönetici Özeti (' . h($basT . ' — ' . $bitT) . ')</h2><div class="bi-kpi-grid">';
    foreach ($kpis as $k) {
        $d = (string)($k['durum'] ?? 'ok');
        $o .= '<div class="bi-kpi bi-kpi--' . h($d) . '"><div class="bi-kpi-baslik">' . h((string)$k['baslik']) . '</div>'
            . '<div class="bi-kpi-deger">' . h((string)$k['deger']) . '</div>'
            . '<div class="bi-kpi-alt">' . h((string)$k['alt']) . '</div></div>';
    }
    if ($kpis === []) $o .= '<p style="color:var(--bi-muted);">Henüz kurulu ERP tablosu yok.</p>';
    $o .= '</div></div>';

    // Hızlı Rapor & Kayıtlı BI Şablon Çalıştırıcı
    $o .= '<div class="bi-kart"><h2>Modül Raporları &amp; Kayıtlı BI Şablonları</h2>'
        . '<form method="get" action="rapor_merkezi.php" class="bi-filtre">'
        . '<div class="bi-alan" style="min-width:220px;"><label>Hazır Modül Raporu</label><select name="kaynak"><option value="">— Seçin —</option>';
    foreach ($kaynaklar as $kk => $km) {
        $o .= '<option value="' . h($kk) . '"' . ($kk === $secKaynak && $secSablon === 0 ? ' selected' : '') . '>' . h((string)$km['ad']) . '</option>';
    }
    $o .= '</select></div>'
        . '<div class="bi-alan" style="min-width:200px;"><label>Veya Kayıtlı BI Şablonu</label><select name="sablon_id"><option value="0">— Şablon Yok —</option>';
    foreach ($sablonlar as $sb) {
        $o .= '<option value="' . (int)$sb['id'] . '"' . ((int)$sb['id'] === $secSablon ? ' selected' : '') . '>' . h((string)$sb['kod'] . ' — ' . (string)$sb['ad']) . '</option>';
    }
    $o .= '</select></div>'
        . '<div class="bi-alan"><label>Başlangıç</label><input type="date" name="bas_tarih" value="' . h($basT) . '"></div>'
        . '<div class="bi-alan"><label>Bitiş</label><input type="date" name="bit_tarih" value="' . h($bitT) . '"></div>'
        . '<div class="bi-alan"><label>Arama</label><input name="ara" value="' . h($ara) . '" placeholder="Metin ara..."></div>'
        . '<button type="submit" class="btn btn-primary">Raporu Çalıştır</button>';
    if ($sonuc !== null && !empty($sonuc['ok'])) {
        $csvUrl = 'rapor_merkezi.php?' . http_build_query([
            'kaynak'    => $secKaynak,
            'sablon_id' => $secSablon,
            'bas_tarih' => $basT,
            'bit_tarih' => $bitT,
            'ara'       => $ara,
            'csv'       => '1',
        ]);
        $o .= '<a class="btn btn-ghost" href="' . h($csvUrl) . '">CSV (Excel) İndir</a>'
            . '<button type="button" class="btn btn-ghost" data-bi-yazdir="1">Yazdır / PDF</button>';
    }
    $o .= '</form>';

    if ($sonuc !== null) {
        if (empty($sonuc['ok'])) {
            $o .= '<div class="bi-uyari" role="alert">' . h((string)($sonuc['hata'] ?? 'Rapor çalıştırılamadı.')) . '</div>';
        } else {
            $sutunlar  = $sonuc['sutunlar'] ?? [];
            $satirlar  = $sonuc['satirlar'] ?? [];
            $toplamlar = $sonuc['toplamlar'] ?? [];
            $o .= '<h3>' . h((string)$sonuc['baslik']) . ' (' . (int)$sonuc['kayit_sayisi'] . ' kayıt)</h3>'
                . '<div class="bi-tablo-wrap"><table class="bi-tablo"><thead><tr>';
            foreach ($sutunlar as $k => [$etiket, $sayisal]) {
                $o .= '<th' . ($sayisal ? ' class="bi-num"' : '') . '>' . h($etiket) . '</th>';
            }
            $o .= '</tr></thead><tbody>';
            foreach ($satirlar as $r) {
                $o .= '<tr>';
                foreach ($sutunlar as $k => [, $sayisal]) {
                    $val = $r[$k] ?? '';
                    $o .= $sayisal
                        ? '<td class="bi-num">' . h(bi_fmt((float)$val)) . '</td>'
                        : '<td>' . h((string)$val) . '</td>';
                }
                $o .= '</tr>';
            }
            if ($satirlar === []) {
                $o .= '<tr><td colspan="' . max(1, count($sutunlar)) . '" style="text-align:center;color:var(--bi-muted);">Filtreye uyan kayıt bulunamadı.</td></tr>';
            }
            if ($satirlar !== [] && $toplamlar !== []) {
                $o .= '<tfoot><tr>';
                $ilk = true;
                foreach ($sutunlar as $k => [, $sayisal]) {
                    if ($sayisal && isset($toplamlar[$k])) {
                        $o .= '<td class="bi-num"><strong>' . h(bi_fmt((float)$toplamlar[$k])) . '</strong></td>';
                    } elseif ($ilk) {
                        $o .= '<td><strong>TOPLAM</strong></td>';
                    } else {
                        $o .= '<td></td>';
                    }
                    $ilk = false;
                }
                $o .= '</tr></tfoot>';
            }
            $o .= '</tbody></table></div>';
        }
    }
    $o .= '</div></div>';
    return $o;
}

// ── 2) Dinamik BI Rapor Tasarımcısı (rapor_tanimlari.php) ──────────────────

function bi_rapor_tanimlari_render(array $veri, bool $yazabilir): string
{
    $csrf       = function_exists('csrf_token') ? csrf_token() : '';
    $kaynaklar  = bi_veri_kaynaklari();
    $sablonlar  = $veri['sablonlar'] ?? [];
    $zamanlama  = $veri['zamanlamalar'] ?? [];
    $secKaynak  = (string)($veri['sec_kaynak'] ?? 'genel_muhasebe');
    if (!isset($kaynaklar[$secKaynak])) $secKaynak = (string)array_key_first($kaynaklar);

    $o = '<div class="bi-wrap">' . bi_ust_menu('rapor_tanimlari');

    if ($yazabilir) {
        $o .= '<div class="bi-kart"><h2>Yeni Dinamik BI Rapor Şablonu Tasarla</h2>'
            . '<form method="get" action="rapor_tanimlari.php" class="bi-filtre" style="margin-bottom:10px;">'
            . '<div class="bi-alan" style="min-width:260px;"><label>1. Veri Kaynağı Seç</label><select name="kaynak">';
        foreach ($kaynaklar as $kk => $km) {
            $o .= '<option value="' . h($kk) . '"' . ($kk === $secKaynak ? ' selected' : '') . '>' . h((string)$km['ad']) . '</option>';
        }
        $o .= '</select></div><button type="submit" class="btn btn-ghost">Sütunları Getir</button></form>';

        $sutMeta = $kaynaklar[$secKaynak]['sutunlar'];
        $o .= '<form method="post" action="bi_rapor_kaydet.php" style="padding:12px;background:var(--bi-bg-soft);border-radius:10px;border:1px solid var(--bi-border);">'
            . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="bi_sablon_kaydet">'
            . '<input type="hidden" name="veri_kaynagi" value="' . h($secKaynak) . '"><input type="hidden" name="geri" value="rapor_tanimlari.php?kaynak=' . h($secKaynak) . '">'
            . '<div class="bi-filtre">'
            . '<div class="bi-alan"><label>Şablon Kodu</label><input name="kod" placeholder="RPR-SEVK-01"></div>'
            . '<div class="bi-alan" style="flex:1;"><label>Şablon Adı</label><input name="ad" required placeholder="Aylık İhracat Yükleme Özeti"></div>'
            . '<div class="bi-alan"><label>Sıralama Sütunu</label><select name="siralama_sutun"><option value="id">Kayıt Sırası (ID)</option>';
        foreach ($sutMeta as $sk => [$sl]) $o .= '<option value="' . h($sk) . '">' . h($sl) . '</option>';
        $o .= '</select></div>'
            . '<div class="bi-alan"><label>Yön</label><select name="siralama_yon"><option value="DESC">Azalan</option><option value="ASC">Artan</option></select></div>'
            . '<div class="bi-alan"><label>Satır Limiti</label><input type="number" name="limit_satir" min="1" max="1000" value="200"></div>'
            . '</div>'
            . '<div style="margin:8px 0 12px;"><strong style="font-size:.82rem;color:var(--bi-muted);">2. Raporda Gösterilecek Sütunlar:</strong><div class="bi-chk-grid">';
        foreach ($sutMeta as $sk => [$sl]) {
            $o .= '<label class="bi-chk"><input type="checkbox" name="sutunlar[]" value="' . h($sk) . '" checked> ' . h($sl) . '</label>';
        }
        $o .= '</div></div><button type="submit" class="btn btn-primary">BI Şablonunu Kaydet</button></form></div>';
    }

    // Kayıtlı Şablonlar
    $o .= '<div class="bi-kart"><h2>Kayıtlı BI Rapor Şablonları</h2><div class="bi-tablo-wrap"><table class="bi-tablo"><thead><tr>'
        . '<th>Kod</th><th>Şablon Adı</th><th>Veri Kaynağı</th><th>Sıralama / Limit</th><th>İşlemler</th></tr></thead><tbody>';
    foreach ($sablonlar as $sb) {
        $kAd = $kaynaklar[(string)$sb['veri_kaynagi']]['ad'] ?? (string)$sb['veri_kaynagi'];
        $o .= '<tr><td class="bi-kod">' . h((string)$sb['kod']) . '</td><td><strong>' . h((string)$sb['ad']) . '</strong></td>'
            . '<td>' . h($kAd) . '</td><td class="bi-kod">' . h((string)$sb['siralama_sutun'] . ' ' . (string)$sb['siralama_yon'] . ' · ' . (int)$sb['limit_satir']) . '</td><td>'
            . '<div class="bi-eylem-satir">'
            . '<a class="btn btn-primary" style="padding:4px 8px;font-size:.8rem;" href="rapor_merkezi.php?sablon_id=' . (int)$sb['id'] . '">Çalıştır</a>'
            . '<a class="btn btn-ghost" style="padding:4px 8px;font-size:.8rem;" href="rapor_merkezi.php?sablon_id=' . (int)$sb['id'] . '&amp;csv=1">CSV</a>';
        if ($yazabilir) {
            $o .= '<form method="post" action="bi_rapor_kaydet.php" class="bi-inline-form"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="bi_sablon_sil"><input type="hidden" name="id" value="' . (int)$sb['id'] . '"><input type="hidden" name="geri" value="rapor_tanimlari.php"><button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:.8rem;">Sil</button></form>';
        }
        $o .= '</div></td></tr>';
    }
    if ($sablonlar === []) $o .= '<tr><td colspan="5" style="text-align:center;color:var(--bi-muted);">Henüz kayıtlı BI şablonu yok.</td></tr>';
    $o .= '</tbody></table></div></div>';

    // Zamanlanmış Dağıtım
    $o .= '<div class="bi-kart"><h2>Zamanlanmış Rapor Dağıtımı (E-posta / CSV)</h2>';
    if ($yazabilir && $sablonlar !== []) {
        $o .= '<form method="post" action="bi_rapor_kaydet.php" class="bi-filtre" style="padding:12px;background:var(--bi-bg-soft);border-radius:10px;border:1px solid var(--bi-border);">'
            . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="bi_zamanlama_kaydet"><input type="hidden" name="geri" value="rapor_tanimlari.php">'
            . '<div class="bi-alan"><label>Şablon</label><select name="sablon_id">';
        foreach ($sablonlar as $sb) $o .= '<option value="' . (int)$sb['id'] . '">' . h((string)$sb['kod'] . ' — ' . (string)$sb['ad']) . '</option>';
        $o .= '</select></div>'
            . '<div class="bi-alan"><label>Periyot</label><select name="periyot"><option value="gunluk">Günlük</option><option value="haftalik">Haftalık</option><option value="aylik">Aylık</option></select></div>'
            . '<div class="bi-alan" style="flex:1;"><label>Alıcı E-posta</label><input type="email" name="alici_eposta" required placeholder="yonetim@firma.com"></div>'
            . '<div class="bi-alan"><label>Format</label><select name="format"><option value="csv">CSV (Excel)</option><option value="html">HTML Özet</option></select></div>'
            . '<button type="submit" class="btn btn-primary">Zamanlama Ekle</button></form>';
    }
    $o .= '<div class="bi-tablo-wrap"><table class="bi-tablo"><thead><tr><th>Şablon</th><th>Periyot</th><th>Alıcı E-posta</th><th>Format</th></tr></thead><tbody>';
    foreach ($zamanlama as $z) {
        $o .= '<tr><td class="bi-kod">' . h((string)$z['sablon_kod'] . ' — ' . (string)$z['sablon_ad']) . '</td><td>' . h(strtoupper((string)$z['periyot'])) . '</td>'
            . '<td>' . h((string)$z['alici_eposta']) . '</td><td>' . h(strtoupper((string)$z['format'])) . '</td></tr>';
    }
    if ($zamanlama === []) $o .= '<tr><td colspan="4" style="text-align:center;color:var(--bi-muted);">Henüz zamanlanmış rapor dağıtımı yok.</td></tr>';
    $o .= '</tbody></table></div></div></div>';
    return $o;
}
