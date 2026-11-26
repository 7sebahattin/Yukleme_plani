<?php
// =========================================================
// config/muhasebe_ui.php — Genel Muhasebe (TDHP), e-Dönüşüm & Mutabakat UI Yardımcıları
//
// Mimari Kurallar:
//   - Include-only (doğrudan HTTP erişimi 404)
//   - SIFIR SQL (yalnız core dizilerini HTML'e çevirir)
//   - Tüm dinamik değerler h() ile kaçışlanır
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

function muh_head_varlik(): void
{
    $v = (int)@filemtime(dirname(__DIR__) . '/assets/muhasebe.css');
    echo '<link rel="stylesheet" href="assets/muhasebe.css?v=' . $v . '">' . "\n";
}

function muh_scripts(): void
{
    $v = (int)@filemtime(dirname(__DIR__) . '/assets/muhasebe.js');
    echo '<script src="assets/muhasebe.js?v=' . $v . '" defer></script>' . "\n";
}

function muh_tutar_fmt(float $v): string
{
    return number_format($v, 2, ',', '.');
}

function muh_tarih_fmt(string $t): string
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $t, $m) ? ($m[3] . '.' . $m[2] . '.' . $m[1]) : $t;
}

function muh_ust_menu(string $aktif): string
{
    $sekmeler = [
        'muh_hesap_plani'   => ['muh_hesap_plani.php',   'TDHP Hesap Planı'],
        'muh_yevmiye'       => ['muh_yevmiye.php',       'Yevmiye Fişleri'],
        'muh_mali_tablolar' => ['muh_mali_tablolar.php', 'Mizan & Mali Tablolar'],
        'edonusum_belgeler' => ['edonusum_belgeler.php', 'e-Dönüşüm'],
        'muh_mutabakat'     => ['muh_mutabakat.php',     'Risk, BA/BS & Kur Farkı'],
    ];
    $o = '<nav class="muh-sekmeler" aria-label="Genel Muhasebe">';
    foreach ($sekmeler as $slug => [$url, $etiket]) {
        if (function_exists('muhasebe_sayfa_can') && !muhasebe_sayfa_can($slug)) {
            continue;
        }
        $curr = ($slug === $aktif) ? ' aria-current="page"' : '';
        $o .= '<a class="muh-sekme" href="' . h($url) . '"' . $curr . '>' . h($etiket) . '</a>';
    }
    return $o . '</nav>';
}

/** @param list<string> $eksik */
function muh_sema_uyari(array $eksik, string $hash, string $baslik): string
{
    $liste = implode(', ', $eksik);
    return '<div class="muh-wrap"><div class="muh-uyari" role="alert">'
        . '<div style="font-size:1.6rem;" aria-hidden="true">&#9888;</div>'
        . '<div><h2 style="margin:0 0 6px;">' . h($baslik) . ' şeması kurulmamış</h2>'
        . '<p style="margin:0 0 10px;">Eksik tablolar: <code>' . h($liste) . '</code>. Yönetici Şema Migrasyon panelinden ilgili kartı çalıştırın.</p>'
        . '<a class="btn btn-primary" href="migrate.php#' . h($hash) . '">Şema Migrasyon &rsaquo; ' . h($baslik) . '</a>'
        . '</div></div></div>';
}

// ── 1) TDHP Hesap Planı ────────────────────────────────────────────────────

function muh_hesap_plani_render(array $hesaplar, array $filtre, bool $yazabilir): string
{
    $siniflar = [
        1 => '1 · Dönen Varlıklar',
        2 => '2 · Duran Varlıklar',
        3 => '3 · Kısa Vadeli Yabancı Kaynaklar',
        4 => '4 · Uzun Vadeli Yabancı Kaynaklar',
        5 => '5 · Özkaynaklar',
        6 => '6 · Gelir Tablosu Hesapları',
        7 => '7 · Maliyet Hesapları',
    ];
    $tipler = muh_hesap_tipleri();
    $secSinif = (int)($filtre['sinif'] ?? 0);
    $ara = (string)($filtre['ara'] ?? '');
    $csrf = function_exists('csrf_token') ? csrf_token() : '';

    $o = '<div class="muh-wrap">' . muh_ust_menu('muh_hesap_plani');

    $o .= '<div class="muh-kpi-grid">';
    $aktifSay = 0; $hareketSay = 0;
    foreach ($hesaplar as $hs) {
        if ((int)$hs['aktif'] === 1) $aktifSay++;
        if ((int)$hs['hareket_gorebilir'] === 1) $hareketSay++;
    }
    $o .= '<div class="muh-kpi"><div class="muh-kpi-etiket">Listelenen Hesap</div><div class="muh-kpi-deger">' . count($hesaplar) . '</div></div>';
    $o .= '<div class="muh-kpi"><div class="muh-kpi-etiket">Aktif Hesap</div><div class="muh-kpi-deger ok">' . $aktifSay . '</div></div>';
    $o .= '<div class="muh-kpi"><div class="muh-kpi-etiket">Hareket Görebilir</div><div class="muh-kpi-deger">' . $hareketSay . '</div></div>';
    $o .= '</div>';

    if ($yazabilir) {
        $o .= '<details class="muh-kart"><summary style="cursor:pointer;font-weight:700;">+ Yeni Alt Hesap / TDHP Hesabı Ekle</summary>';
        $o .= '<form method="post" action="muh_kaydet.php" class="muh-filtre" style="margin-top:12px;">';
        $o .= '<input type="hidden" name="csrf" value="' . h($csrf) . '">';
        $o .= '<input type="hidden" name="islem" value="hesap_kaydet">';
        $o .= '<input type="hidden" name="geri" value="muh_hesap_plani.php">';
        $o .= '<div class="muh-alan"><label for="mhKod">Hesap Kodu</label><input id="mhKod" name="kod" required placeholder="örn. 120.01.001" maxlength="30"></div>';
        $o .= '<div class="muh-alan" style="flex:1;min-width:220px;"><label for="mhAd">Hesap Adı</label><input id="mhAd" name="ad" required placeholder="örn. Yurt İçi Müşteriler — ABC Ltd." maxlength="180"></div>';
        $o .= '<div class="muh-alan"><label for="mhUst">Üst Hesap Kodu</label><input id="mhUst" name="ust_kod" placeholder="örn. 120.01" maxlength="30"></div>';
        $o .= '<div class="muh-alan"><label for="mhTip">Hesap Tipi</label><select id="mhTip" name="tip">';
        foreach ($tipler as $tk => $tl) {
            $o .= '<option value="' . h($tk) . '">' . h($tl) . '</option>';
        }
        $o .= '</select></div>';
        $o .= '<div class="muh-alan"><label for="mhYon">Bakiye Yönü</label><select id="mhYon" name="bakiye_yonu"><option value="borc">Borç</option><option value="alacak">Alacak</option></select></div>';
        $o .= '<div class="muh-alan"><label><input type="checkbox" name="hareket_gorebilir" value="1" checked> Hareket Görebilir</label></div>';
        $o .= '<button type="submit" class="btn btn-primary">Hesabı Kaydet</button>';
        $o .= '</form></details>';
    }

    $o .= '<div class="muh-kart"><div class="muh-baslik-bar"><h2>Tek Düzen Hesap Planı (TDHP)</h2>';
    $o .= '<form method="get" class="muh-filtre" style="margin:0;">';
    $o .= '<div class="muh-alan"><label for="fSinif">Sınıf</label><select id="fSinif" name="sinif"><option value="">Tüm Sınıflar (1–7)</option>';
    foreach ($siniflar as $sk => $sl) {
        $o .= '<option value="' . $sk . '"' . ($secSinif === $sk ? ' selected' : '') . '>' . h($sl) . '</option>';
    }
    $o .= '</select></div>';
    $o .= '<div class="muh-alan"><label for="muhTdhpCanliAra">Kod / Ad Ara</label><input id="muhTdhpCanliAra" name="ara" value="' . h($ara) . '" placeholder="100, Kasa, KDV..."></div>';
    $o .= '<button type="submit" class="btn btn-ghost">Filtrele</button>';
    $o .= '</form></div>';

    $o .= '<div class="muh-tablo-wrap"><table class="muh-tablo" id="muhTdhpTablo"><thead><tr>'
        . '<th>Kod</th><th>Hesap Adı</th><th>Sınıf</th><th>Tip</th><th>Yön</th><th>Hareket</th><th>Durum</th>';
    if ($yazabilir) $o .= '<th>İşlem</th>';
    $o .= '</tr></thead><tbody>';

    foreach ($hesaplar as $hs) {
        $kod = (string)$hs['kod'];
        $seviye = min(3, substr_count($kod, '.') + 1);
        $aktif = (int)$hs['aktif'] === 1;
        $o .= '<tr>';
        $o .= '<td class="muh-kod muh-indent-' . $seviye . '">' . h($kod) . '</td>';
        $o .= '<td>' . ($seviye === 1 ? '<strong>' . h((string)$hs['ad']) . '</strong>' : h((string)$hs['ad'])) . '</td>';
        $o .= '<td>' . (int)$hs['sinif'] . '</td>';
        $o .= '<td>' . h($tipler[(string)$hs['tip']] ?? (string)$hs['tip']) . '</td>';
        $o .= '<td>' . h((string)$hs['bakiye_yonu'] === 'borc' ? 'Borç' : 'Alacak') . '</td>';
        $o .= '<td>' . ((int)$hs['hareket_gorebilir'] === 1 ? '<span class="muh-rozet muh-rozet--bilgi">Açık</span>' : '<span class="muh-rozet">Ana</span>') . '</td>';
        $o .= '<td>' . ($aktif ? '<span class="muh-rozet muh-rozet--ok">Aktif</span>' : '<span class="muh-rozet muh-rozet--err">Pasif</span>') . '</td>';
        if ($yazabilir) {
            $o .= '<td><form method="post" action="muh_kaydet.php" class="muh-inline-form">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                . '<input type="hidden" name="islem" value="hesap_durum">'
                . '<input type="hidden" name="id" value="' . (int)$hs['id'] . '">'
                . '<input type="hidden" name="aktif" value="' . ($aktif ? '0' : '1') . '">'
                . '<input type="hidden" name="geri" value="muh_hesap_plani.php">'
                . '<button type="submit" class="btn btn-ghost" style="padding:4px 8px;font-size:.8rem;">' . ($aktif ? 'Pasifleştir' : 'Aktifleştir') . '</button>'
                . '</form></td>';
        }
        $o .= '</tr>';
    }
    if ($hesaplar === []) {
        $o .= '<tr><td colspan="' . ($yazabilir ? 8 : 7) . '" style="text-align:center;color:var(--muh-muted);">Kayıt bulunamadı.</td></tr>';
    }
    $o .= '</tbody></table></div></div></div>';
    return $o;
}

// ── 2) Yevmiye Fişleri, Alt Defter Oto-Mahsup & Mali Dönemler ──────────────

function muh_yevmiye_render(array $fisler, ?array $detayFis, array $hareketHesaplari, array $donemler, array $filtre, bool $yazabilir, bool $silebilir): string
{
    $csrf = function_exists('csrf_token') ? csrf_token() : '';
    $turler = muh_fis_turleri();
    $bas = (string)($filtre['bas'] ?? date('Y-01-01'));
    $bit = (string)($filtre['bit'] ?? date('Y-m-d'));
    $secTur = (string)($filtre['tur'] ?? '');

    $o = '<div class="muh-wrap">' . muh_ust_menu('muh_yevmiye');

    // Seçili fiş detay kartı
    if ($detayFis !== null) {
        $iptal = (string)$detayFis['durum'] === 'iptal';
        $o .= '<div class="muh-kart" id="muhFisDetay">';
        $o .= '<div class="muh-baslik-bar"><div><h2 style="margin:0;">Fiş Detayı: ' . h((string)$detayFis['fis_no']) . '</h2>'
            . '<div style="color:var(--muh-muted);font-size:.88rem;">Tarih: ' . h(muh_tarih_fmt((string)$detayFis['tarih']))
            . ' · Tür: ' . h($turler[(string)$detayFis['tur']] ?? (string)$detayFis['tur'])
            . ' · Durum: ' . ($iptal ? '<span class="muh-rozet muh-rozet--err">İptal</span>' : '<span class="muh-rozet muh-rozet--ok">Kesinleşti</span>')
            . '</div></div>'
            . '<a class="btn btn-ghost" href="muh_yevmiye.php">&larr; Listeye Dön</a></div>';
        if ((string)$detayFis['aciklama'] !== '') {
            $o .= '<p style="margin:0 0 12px;"><strong>Açıklama:</strong> ' . h((string)$detayFis['aciklama']) . '</p>';
        }
        $o .= '<div class="muh-tablo-wrap"><table class="muh-tablo"><thead><tr>'
            . '<th>#</th><th>Hesap Kodu</th><th>Hesap Adı</th><th>Belge No</th><th>Açıklama</th><th class="muh-num">Borç (TL)</th><th class="muh-num">Alacak (TL)</th></tr></thead><tbody>';
        foreach (($detayFis['satirlar'] ?? []) as $st) {
            $o .= '<tr><td>' . (int)($st['sira_no'] ?? 0) . '</td>'
                . '<td class="muh-kod">' . h((string)$st['hesap_kod']) . '</td>'
                . '<td>' . h((string)$st['hesap_ad']) . '</td>'
                . '<td>' . h((string)$st['belge_no']) . '</td>'
                . '<td>' . h((string)$st['aciklama']) . '</td>'
                . '<td class="muh-num">' . h(muh_tutar_fmt((float)$st['borc'])) . '</td>'
                . '<td class="muh-num">' . h(muh_tutar_fmt((float)$st['alacak'])) . '</td></tr>';
        }
        $o .= '</tbody><tfoot><tr><th colspan="5" class="muh-num">TOPLAM</th>'
            . '<th class="muh-num">' . h(muh_tutar_fmt((float)$detayFis['toplam_borc'])) . '</th>'
            . '<th class="muh-num">' . h(muh_tutar_fmt((float)$detayFis['toplam_alacak'])) . '</th></tr></tfoot></table></div>';

        if (!$iptal && $silebilir && empty($detayFis['iptal_fis_id'])) {
            $o .= '<form method="post" action="muh_kaydet.php" class="muh-filtre" style="margin-top:14px;" onsubmit="return confirm(\'Bu fiş için ters kayıt (storno) oluşturularak iptal edilecek. Devam edilsin mi?\');">';
            $o .= '<input type="hidden" name="csrf" value="' . h($csrf) . '">';
            $o .= '<input type="hidden" name="islem" value="fis_iptal">';
            $o .= '<input type="hidden" name="id" value="' . (int)$detayFis['id'] . '">';
            $o .= '<input type="hidden" name="geri" value="muh_yevmiye.php?fis_id=' . (int)$detayFis['id'] . '">';
            $o .= '<div class="muh-alan" style="flex:1;"><label for="iptNeden">Ters Fiş (Storno) İptal Gerekçesi</label><input id="iptNeden" name="neden" required placeholder="Hatalı hesap seçimi / tutar düzeltmesi..."></div>';
            $o .= '<button type="submit" class="btn btn-danger">Ters Fişle İptal Et</button>';
            $o .= '</form>';
        }
        $o .= '</div>';
    }

    // Yeni Yevmiye Fişi formu
    if ($yazabilir) {
        $secenekler = '<option value="">— Hesap Seçin —</option>';
        foreach ($hareketHesaplari as $hh) {
            $secenekler .= '<option value="' . h((string)$hh['kod']) . '">' . h((string)$hh['kod'] . ' — ' . (string)$hh['ad']) . '</option>';
        }

        $o .= '<details class="muh-kart"' . ($detayFis === null ? ' open' : '') . '><summary style="cursor:pointer;font-weight:700;">+ Yeni Yevmiye Fişi (Çift Taraflı · Borç = Alacak)</summary>';
        $o .= '<form method="post" action="muh_kaydet.php" id="muhFisForm" style="margin-top:12px;">';
        $o .= '<input type="hidden" name="csrf" value="' . h($csrf) . '">';
        $o .= '<input type="hidden" name="islem" value="fis_kaydet">';
        $o .= '<input type="hidden" name="geri" value="muh_yevmiye.php">';
        $o .= '<div class="muh-filtre">';
        $o .= '<div class="muh-alan"><label for="mfTarih">Fiş Tarihi</label><input type="date" id="mfTarih" name="tarih" required value="' . h(date('Y-m-d')) . '"></div>';
        $o .= '<div class="muh-alan"><label for="mfTur">Fiş Türü</label><select id="mfTur" name="tur">';
        foreach (['mahsup', 'tahsil', 'tediye', 'acilis'] as $tk) {
            $o .= '<option value="' . h($tk) . '">' . h($turler[$tk] ?? $tk) . '</option>';
        }
        $o .= '</select></div>';
        $o .= '<div class="muh-alan" style="flex:1;min-width:240px;"><label for="mfAck">Fiş Açıklaması</label><input id="mfAck" name="aciklama" required placeholder="Fiş özeti..." maxlength="500"></div>';
        $o .= '</div>';

        $o .= '<div class="muh-tablo-wrap"><table class="muh-tablo"><thead><tr>'
            . '<th style="min-width:240px;">TDHP Hesap</th><th>Belge No</th><th>Satır Açıklaması</th><th class="muh-num" style="width:140px;">Borç (TL)</th><th class="muh-num" style="width:140px;">Alacak (TL)</th><th style="width:52px;"></th>'
            . '</tr></thead><tbody id="muhSatirlarBody"></tbody></table></div>';

        $o .= '<template id="muhSatirTpl"><tr class="muh-fis-satir">'
            . '<td><select name="satir_hesap[]" data-alan="hesap" required style="width:100%;padding:7px;border:1px solid var(--muh-border);border-radius:6px;background:var(--muh-bg);color:var(--muh-text);">' . $secenekler . '</select></td>'
            . '<td><input type="text" name="satir_belge[]" placeholder="Belge no" maxlength="60" style="width:100%;padding:7px;border:1px solid var(--muh-border);border-radius:6px;background:var(--muh-bg);color:var(--muh-text);"></td>'
            . '<td><input type="text" name="satir_aciklama[]" placeholder="Açıklama" maxlength="250" style="width:100%;padding:7px;border:1px solid var(--muh-border);border-radius:6px;background:var(--muh-bg);color:var(--muh-text);"></td>'
            . '<td><input type="text" inputmode="decimal" name="satir_borc[]" data-alan="borc" placeholder="0,00" style="width:100%;text-align:right;padding:7px;border:1px solid var(--muh-border);border-radius:6px;background:var(--muh-bg);color:var(--muh-text);"></td>'
            . '<td><input type="text" inputmode="decimal" name="satir_alacak[]" data-alan="alacak" placeholder="0,00" style="width:100%;text-align:right;padding:7px;border:1px solid var(--muh-border);border-radius:6px;background:var(--muh-bg);color:var(--muh-text);"></td>'
            . '<td><button type="button" class="btn btn-ghost" data-satir-sil="1" aria-label="Satırı kaldır" style="padding:4px 8px;">&times;</button></td>'
            . '</tr></template>';

        $o .= '<div class="muh-denge-bar" id="muhDengeBar" data-durum="bos">'
            . '<span>Toplam Borç: <strong id="muhTopBorc">0,00 TL</strong></span>'
            . '<span>Toplam Alacak: <strong id="muhTopAlacak">0,00 TL</strong></span>'
            . '<span>Fark: <strong id="muhTopFark">0,00 TL</strong></span>'
            . '<div class="muh-eylem-satir">'
            . '<button type="button" class="btn btn-ghost" id="muhSatirEkle">+ Satır Ekle</button>'
            . '<button type="submit" class="btn btn-primary" id="muhFisKaydetBtn" disabled>Yevmiye Fişini Kaydet</button>'
            . '</div></div>';
        $o .= '</form></details>';

        // Alt Defter Oto-Mahsup + Mali Dönemler yan yana
        $o .= '<div class="muh-grid-2">';
        $o .= '<div class="muh-kart"><h3>Alt Defter Otomatik Mahsup Köprüsü</h3>'
            . '<p style="font-size:.86rem;color:var(--muh-muted);margin:0 0 10px;">Onaylı Faturalar ve aktif Kasa hareketlerini idempotent olarak TDHP yevmiye fişine aktarır (daha önce işlenen kayıtlar atlanır).</p>'
            . '<form method="post" action="muh_kaydet.php" class="muh-filtre">'
            . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
            . '<input type="hidden" name="islem" value="alt_defter_mahsup">'
            . '<input type="hidden" name="geri" value="muh_yevmiye.php">'
            . '<div class="muh-alan"><label>Başlangıç</label><input type="date" name="bas" value="' . h($bas) . '" required></div>'
            . '<div class="muh-alan"><label>Bitiş</label><input type="date" name="bit" value="' . h($bit) . '" required></div>'
            . '<button type="submit" class="btn btn-primary">Alt Defterden Mahsup Üret</button>'
            . '</form></div>';

        $o .= '<div class="muh-kart"><h3>Mali Yıl Dönemleri (Açma / Kapanış)</h3>'
            . '<div class="muh-tablo-wrap"><table class="muh-tablo"><thead><tr><th>Yıl</th><th>Durum</th><th>İşlem</th></tr></thead><tbody>';
        foreach ($donemler as $dn) {
            $kapali = (string)$dn['durum'] === 'kapali';
            $o .= '<tr><td class="muh-kod">' . (int)$dn['yil'] . '</td>'
                . '<td>' . ($kapali ? '<span class="muh-rozet muh-rozet--err">Kapalı</span>' : '<span class="muh-rozet muh-rozet--ok">Açık</span>') . '</td><td>';
            if (!$kapali) {
                $o .= '<form method="post" action="muh_kaydet.php" class="muh-inline-form" onsubmit="return confirm(\'' . (int)$dn['yil'] . ' mali yılı kapatılıp 6xx hesapları 590/591 hesabına devredilecek ve dönem kilitlenecek. Onaylıyor musunuz?\');">'
                    . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                    . '<input type="hidden" name="islem" value="donem_kapat">'
                    . '<input type="hidden" name="yil" value="' . (int)$dn['yil'] . '">'
                    . '<input type="hidden" name="geri" value="muh_yevmiye.php">'
                    . '<button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:.8rem;">Yılı Kapat</button></form>';
            } else {
                $o .= '<span style="color:var(--muh-muted);font-size:.82rem;">Kilitli</span>';
            }
            $o .= '</td></tr>';
        }
        $o .= '</tbody></table></div>';
        $o .= '<form method="post" action="muh_kaydet.php" class="muh-filtre" style="margin-top:10px;margin-bottom:0;">'
            . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
            . '<input type="hidden" name="islem" value="donem_ac">'
            . '<input type="hidden" name="geri" value="muh_yevmiye.php">'
            . '<div class="muh-alan"><label for="yeniYil">Yeni Mali Yıl Aç</label><input id="yeniYil" type="number" name="yil" min="2000" max="2099" value="' . ((int)date('Y') + 1) . '"></div>'
            . '<button type="submit" class="btn btn-ghost">Dönem Aç</button></form>';
        $o .= '</div></div>';
    }

    // Fiş Listesi
    $o .= '<div class="muh-kart"><div class="muh-baslik-bar"><h2>Yevmiye Defteri</h2>'
        . '<form method="get" class="muh-filtre" style="margin:0;">'
        . '<div class="muh-alan"><label>Başlangıç</label><input type="date" name="bas" value="' . h($bas) . '"></div>'
        . '<div class="muh-alan"><label>Bitiş</label><input type="date" name="bit" value="' . h($bit) . '"></div>'
        . '<div class="muh-alan"><label>Tür</label><select name="tur"><option value="">Tümü</option>';
    foreach ($turler as $tk => $tl) {
        $o .= '<option value="' . h($tk) . '"' . ($secTur === $tk ? ' selected' : '') . '>' . h($tl) . '</option>';
    }
    $o .= '</select></div><button type="submit" class="btn btn-ghost">Listele</button></form></div>';

    $o .= '<div class="muh-tablo-wrap"><table class="muh-tablo"><thead><tr>'
        . '<th>Fiş No</th><th>Tarih</th><th>Tür</th><th>Açıklama</th><th>Kaynak</th><th class="muh-num">Borç (TL)</th><th class="muh-num">Alacak (TL)</th><th>Durum</th></tr></thead><tbody>';
    foreach ($fisler as $f) {
        $iptal = (string)$f['durum'] === 'iptal';
        $o .= '<tr class="' . ($iptal ? 'muh-satir-iptal' : '') . '">';
        $o .= '<td class="muh-kod"><a href="muh_yevmiye.php?fis_id=' . (int)$f['id'] . '">' . h((string)$f['fis_no']) . '</a></td>';
        $o .= '<td>' . h(muh_tarih_fmt((string)$f['tarih'])) . '</td>';
        $o .= '<td>' . h($turler[(string)$f['tur']] ?? (string)$f['tur']) . '</td>';
        $o .= '<td>' . h((string)$f['aciklama']) . '</td>';
        $o .= '<td>' . ($f['kaynak_modul'] ? '<span class="muh-rozet muh-rozet--bilgi">' . h((string)$f['kaynak_modul']) . ' #' . (int)$f['kaynak_id'] . '</span>' : '—') . '</td>';
        $o .= '<td class="muh-num">' . h(muh_tutar_fmt((float)$f['toplam_borc'])) . '</td>';
        $o .= '<td class="muh-num">' . h(muh_tutar_fmt((float)$f['toplam_alacak'])) . '</td>';
        $o .= '<td>' . ($iptal ? '<span class="muh-rozet muh-rozet--err">İptal</span>' : '<span class="muh-rozet muh-rozet--ok">Kesin</span>') . '</td>';
        $o .= '</tr>';
    }
    if ($fisler === []) {
        $o .= '<tr><td colspan="8" style="text-align:center;color:var(--muh-muted);">Seçili aralıkta yevmiye fişi yok.</td></tr>';
    }
    $o .= '</tbody></table></div></div></div>';
    return $o;
}

// ── 3) Mizan, Muavin/Kebir, Gelir Tablosu, Bilanço, KDV Özeti ──────────────

function muh_mali_tablolar_render(string $sekme, array $veri, array $hareketHesaplari, array $filtre, bool $exportCan): string
{
    $bas = (string)($filtre['bas'] ?? date('Y-01-01'));
    $bit = (string)($filtre['bit'] ?? date('Y-m-d'));
    $hesap = (string)($filtre['hesap'] ?? '100');

    $altSekmeler = [
        'mizan'   => 'Genel Mizan',
        'muavin'  => 'Muavin / Kebir',
        'gelir'   => 'Gelir Tablosu',
        'bilanco' => 'Bilanço',
        'kdv'     => 'KDV Özeti',
    ];

    $o = '<div class="muh-wrap">' . muh_ust_menu('muh_mali_tablolar');

    $o .= '<div class="muh-kart"><div class="muh-baslik-bar"><div class="muh-eylem-satir">';
    foreach ($altSekmeler as $k => $ad) {
        $qs = http_build_query(['sekme' => $k, 'bas' => $bas, 'bit' => $bit, 'hesap' => $hesap]);
        $cls = ($sekme === $k) ? 'btn btn-primary' : 'btn btn-ghost';
        $o .= '<a class="' . $cls . '" href="muh_mali_tablolar.php?' . h($qs) . '">' . h($ad) . '</a>';
    }
    $o .= '</div>';
    if ($exportCan && in_array($sekme, ['mizan', 'muavin'], true)) {
        $eq = http_build_query(['sekme' => $sekme, 'bas' => $bas, 'bit' => $bit, 'hesap' => $hesap, 'export' => 'csv']);
        $o .= '<a class="btn btn-ghost muh-no-print" href="muh_mali_tablolar.php?' . h($eq) . '">CSV İndir</a>';
    }
    $o .= '</div>';

    // Filtre formu
    $o .= '<form method="get" class="muh-filtre">'
        . '<input type="hidden" name="sekme" value="' . h($sekme) . '">';
    if ($sekme !== 'bilanco') {
        $o .= '<div class="muh-alan"><label>Başlangıç</label><input type="date" name="bas" value="' . h($bas) . '"></div>';
    }
    $o .= '<div class="muh-alan"><label>' . ($sekme === 'bilanco' ? 'Bilanço Tarihi' : 'Bitiş') . '</label><input type="date" name="bit" value="' . h($bit) . '"></div>';
    if ($sekme === 'muavin') {
        $o .= '<div class="muh-alan" style="min-width:240px;"><label>Hesap Kodu</label><select name="hesap">';
        foreach ($hareketHesaplari as $hh) {
            $hk = (string)$hh['kod'];
            $o .= '<option value="' . h($hk) . '"' . ($hesap === $hk ? ' selected' : '') . '>' . h($hk . ' — ' . (string)$hh['ad']) . '</option>';
        }
        $o .= '</select></div>';
    }
    $o .= '<button type="submit" class="btn btn-primary">Raporu Getir</button></form>';

    if ($sekme === 'mizan') {
        $top = $veri['toplam'] ?? [];
        $dengeli = !empty($top['dengeli']);
        $o .= '<div class="muh-kpi-grid">'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">Toplam Borç</div><div class="muh-kpi-deger">' . h(muh_tutar_fmt((float)($top['borc'] ?? 0))) . ' TL</div></div>'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">Toplam Alacak</div><div class="muh-kpi-deger">' . h(muh_tutar_fmt((float)($top['alacak'] ?? 0))) . ' TL</div></div>'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">Borç Bakiye</div><div class="muh-kpi-deger">' . h(muh_tutar_fmt((float)($top['borc_bakiye'] ?? 0))) . ' TL</div></div>'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">Mizan Dengesi</div><div class="muh-kpi-deger ' . ($dengeli ? 'ok' : 'err') . '">' . ($dengeli ? 'Dengeli (✓)' : 'Fark Var (!)') . '</div></div></div>';
        $o .= '<div class="muh-tablo-wrap"><table class="muh-tablo"><thead><tr>'
            . '<th>Kod</th><th>Hesap Adı</th><th class="muh-num">Borç</th><th class="muh-num">Alacak</th><th class="muh-num">Borç Bakiye</th><th class="muh-num">Alacak Bakiye</th></tr></thead><tbody>';
        foreach (($veri['satirlar'] ?? []) as $s) {
            $o .= '<tr><td class="muh-kod"><a href="muh_mali_tablolar.php?sekme=muavin&amp;hesap=' . urlencode((string)$s['kod']) . '&amp;bas=' . urlencode($bas) . '&amp;bit=' . urlencode($bit) . '">' . h((string)$s['kod']) . '</a></td>'
                . '<td>' . h((string)$s['ad']) . '</td>'
                . '<td class="muh-num">' . h(muh_tutar_fmt((float)$s['borc'])) . '</td>'
                . '<td class="muh-num">' . h(muh_tutar_fmt((float)$s['alacak'])) . '</td>'
                . '<td class="muh-num">' . h(muh_tutar_fmt((float)$s['borc_bakiye'])) . '</td>'
                . '<td class="muh-num">' . h(muh_tutar_fmt((float)$s['alacak_bakiye'])) . '</td></tr>';
        }
        if (empty($veri['satirlar'])) {
            $o .= '<tr><td colspan="6" style="text-align:center;color:var(--muh-muted);">Bu aralıkta hareket görmüş hesap yok.</td></tr>';
        }
        $o .= '</tbody></table></div>';
    } elseif ($sekme === 'muavin') {
        $hareketler = $veri['hareketler'] ?? [];
        $o .= '<div class="muh-kpi-grid">'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">Hesap</div><div class="muh-kpi-deger">' . h((string)($veri['hesap_kod'] ?? $hesap)) . '</div></div>'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">Dönem Başı Devir</div><div class="muh-kpi-deger">' . h(muh_tutar_fmt((float)($veri['devir_bakiye'] ?? 0))) . ' TL</div></div>'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">Dönem Sonu Bakiye</div><div class="muh-kpi-deger ok">' . h(muh_tutar_fmt((float)($veri['son_bakiye'] ?? 0))) . ' TL</div></div></div>';
        $o .= '<div class="muh-tablo-wrap"><table class="muh-tablo"><thead><tr>'
            . '<th>Tarih</th><th>Fiş No</th><th>Belge No</th><th>Açıklama</th><th class="muh-num">Borç</th><th class="muh-num">Alacak</th><th class="muh-num">Yürüyen Bakiye</th></tr></thead><tbody>';
        foreach ($hareketler as $s) {
            $o .= '<tr><td>' . h(muh_tarih_fmt((string)$s['tarih'])) . '</td>'
                . '<td class="muh-kod"><a href="muh_yevmiye.php?fis_id=' . (int)$s['fis_id'] . '">' . h((string)$s['fis_no']) . '</a></td>'
                . '<td>' . h((string)$s['belge_no']) . '</td>'
                . '<td>' . h((string)$s['aciklama']) . '</td>'
                . '<td class="muh-num">' . h(muh_tutar_fmt((float)$s['borc'])) . '</td>'
                . '<td class="muh-num">' . h(muh_tutar_fmt((float)$s['alacak'])) . '</td>'
                . '<td class="muh-num">' . h(muh_tutar_fmt((float)$s['yuruyen_bakiye'])) . '</td></tr>';
        }
        if ($hareketler === []) {
            $o .= '<tr><td colspan="7" style="text-align:center;color:var(--muh-muted);">Seçili hesapta dönem hareketi yok.</td></tr>';
        }
        $o .= '</tbody></table></div>';
    } elseif ($sekme === 'gelir') {
        $net = (float)($veri['donem_kari'] ?? 0);
        $kal = $veri['kalemler'] ?? [];
        $satirEtiket = [
            'brut_satislar'     => ['A. Brüt Satışlar (60x)', false],
            'satis_indirimleri' => ['B. Satış İndirimleri (-) (61x)', false],
            'net_satislar'      => ['C. Net Satışlar', true],
            'satis_maliyeti'    => ['D. Satışların Maliyeti (-) (62x)', false],
            'brut_kar'          => ['BRÜT SATIŞ KÂRI / ZARARI', true],
            'faaliyet_gideri'   => ['E. Faaliyet Giderleri (-) (63x)', false],
            'faaliyet_kari'     => ['FAALİYET KÂRI / ZARARI', true],
            'diger_gelirler'    => ['F. Diğer Faaliyetlerden Olağan Gelir ve Kârlar (64x)', false],
            'diger_giderler'    => ['G. Diğer Faaliyetlerden Olağan Gider ve Zararlar (-) (65x)', false],
            'finansman_gideri'  => ['H. Finansman Giderleri (-) (66x)', false],
            'olagandisi_gelir'  => ['I. Olağandışı Gelir ve Kârlar (67x)', false],
            'olagandisi_gider'  => ['J. Olağandışı Gider ve Zararlar (-) (68x)', false],
            'donem_kari'        => ['DÖNEM NET KÂRI VEYA ZARARI', true],
        ];
        $o .= '<div class="muh-kpi-grid">'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">Net Satışlar</div><div class="muh-kpi-deger">' . h(muh_tutar_fmt((float)($veri['net_satislar'] ?? 0))) . ' TL</div></div>'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">Brüt Satış Kârı</div><div class="muh-kpi-deger">' . h(muh_tutar_fmt((float)($veri['brut_kar'] ?? 0))) . ' TL</div></div>'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">Faaliyet Kârı</div><div class="muh-kpi-deger">' . h(muh_tutar_fmt((float)($veri['faaliyet_kari'] ?? 0))) . ' TL</div></div>'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">Dönem Net Kâr / Zararı</div><div class="muh-kpi-deger ' . ($net >= 0 ? 'ok' : 'err') . '">' . h(muh_tutar_fmt($net)) . ' TL</div></div></div>';
        $o .= '<div class="muh-tablo-wrap"><table class="muh-tablo"><thead><tr><th>TDHP Gelir Tablosu Kalemi</th><th class="muh-num">Tutar (TL)</th></tr></thead><tbody>';
        foreach ($satirEtiket as $k => [$ad, $vurgulu]) {
            $val = (float)($kal[$k] ?? 0.0);
            $o .= '<tr><td>' . ($vurgulu ? '<strong>' . h($ad) . '</strong>' : h($ad)) . '</td><td class="muh-num">' . ($vurgulu ? '<strong>' . h(muh_tutar_fmt($val)) . '</strong>' : h(muh_tutar_fmt($val))) . '</td></tr>';
        }
        $o .= '</tbody></table></div>';
    } elseif ($sekme === 'bilanco') {
        $dengeli = !empty($veri['dengeli']);
        $o .= '<div class="muh-kpi-grid">'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">Aktif Toplamı (Varlıklar)</div><div class="muh-kpi-deger">' . h(muh_tutar_fmt((float)($veri['toplam_aktif'] ?? 0))) . ' TL</div></div>'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">Pasif Toplamı (Kaynaklar)</div><div class="muh-kpi-deger">' . h(muh_tutar_fmt((float)($veri['toplam_pasif'] ?? 0))) . ' TL</div></div>'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">Bilanço Dengesi</div><div class="muh-kpi-deger ' . ($dengeli ? 'ok' : 'err') . '">' . ($dengeli ? 'Aktif = Pasif (✓)' : 'Fark Var (!)') . '</div></div></div>';
        $o .= '<div class="muh-grid-2">';
        foreach (['aktif' => 'AKTİF (Varlıklar · 1xx–2xx)', 'pasif' => 'PASİF (Kaynaklar · 3xx–5xx)'] as $yon => $baslik) {
            $o .= '<div><h3>' . h($baslik) . '</h3><div class="muh-tablo-wrap"><table class="muh-tablo"><thead><tr><th>Kod</th><th>Hesap</th><th class="muh-num">Tutar (TL)</th></tr></thead><tbody>';
            foreach (($veri[$yon] ?? []) as $grp) {
                $o .= '<tr><td colspan="2" style="background:var(--muh-bg-soft);font-weight:700;">' . h((string)$grp['ad']) . '</td><td class="muh-num" style="background:var(--muh-bg-soft);font-weight:700;">' . h(muh_tutar_fmt((float)$grp['toplam'])) . '</td></tr>';
                foreach (($grp['satirlar'] ?? []) as $s) {
                    $o .= '<tr><td class="muh-kod">' . h((string)$s['kod']) . '</td><td>' . h((string)$s['ad']) . '</td><td class="muh-num">' . h(muh_tutar_fmt((float)$s['tutar'])) . '</td></tr>';
                }
            }
            $o .= '</tbody></table></div></div>';
        }
        $o .= '</div>';
    } elseif ($sekme === 'kdv') {
        $o .= '<div class="muh-kpi-grid">'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">391 Hesaplanan KDV</div><div class="muh-kpi-deger">' . h(muh_tutar_fmt((float)($veri['hesaplanan'] ?? 0))) . ' TL</div></div>'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">191 İndirilecek KDV</div><div class="muh-kpi-deger">' . h(muh_tutar_fmt((float)($veri['indirilecek'] ?? 0))) . ' TL</div></div>'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">190 Önceki Dönemden Devreden</div><div class="muh-kpi-deger">' . h(muh_tutar_fmt((float)($veri['onceki_devir'] ?? 0))) . ' TL</div></div>'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">Ödenecek KDV</div><div class="muh-kpi-deger warn">' . h(muh_tutar_fmt((float)($veri['odenecek'] ?? 0))) . ' TL</div></div>'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">Sonraki Döneme Devreden</div><div class="muh-kpi-deger ok">' . h(muh_tutar_fmt((float)($veri['devreden'] ?? 0))) . ' TL</div></div></div>';
    }

    $o .= '</div></div>';
    return $o;
}

// ── 4) e-Dönüşüm (e-Fatura / e-Arşiv / e-İrsaliye / e-Müstahsil) ───────────

function edonusum_render(
    string $sekme,
    array $zarflar,
    array $gelenler,
    array $faturalar,
    array $filtre,
    bool $yazabilir,
    ?array $ayar = null,
    ?string $aktifSaglayici = null,
    ?array $mukellefSonuc = null,
    bool $adminMi = false
): string {
    $csrf = function_exists('csrf_token') ? csrf_token() : '';
    $turler = edonusum_belge_turleri();
    $ayar = $ayar ?? edonusum_ayar_varsayilan();
    $sagAd = $aktifSaglayici ?? edonusum_provider()->ad();
    $elogoAktif = str_starts_with($sagAd, 'elogo');

    $sagEtiket = match ($sagAd) {
        'elogo_canli' => 'e-Logo CANLI',
        'elogo_test'  => 'e-Logo TEST / DEMO',
        default       => 'Sandbox (Test)',
    };
    $sagRozetCls = match ($sagAd) {
        'elogo_canli' => 'muh-rozet--ok',
        'elogo_test'  => 'muh-rozet--warn',
        default       => 'muh-rozet--bilgi',
    };

    $durumRozet = static function (string $d): string {
        return match ($d) {
            'onaylandi', 'kabul' => '<span class="muh-rozet muh-rozet--ok">' . h(strtoupper($d)) . '</span>',
            'gonderildi', 'bekliyor' => '<span class="muh-rozet muh-rozet--warn">' . h(strtoupper($d)) . '</span>',
            'hata', 'red', 'iptal' => '<span class="muh-rozet muh-rozet--err">' . h(strtoupper($d)) . '</span>',
            default => '<span class="muh-rozet muh-rozet--bilgi">' . h(strtoupper($d)) . '</span>',
        };
    };

    $o = '<div class="muh-wrap">' . muh_ust_menu('edonusum_belgeler');
    $o .= '<div class="muh-kart"><div class="muh-baslik-bar"><div class="muh-eylem-satir">'
        . '<a class="' . ($sekme === 'giden' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="edonusum_belgeler.php?sekme=giden">Giden Zarflar (' . count($zarflar) . ')</a>'
        . '<a class="' . ($sekme === 'gelen' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="edonusum_belgeler.php?sekme=gelen">Gelen Kutusu Kabul/Red (' . count($gelenler) . ')</a>'
        . '<a class="' . ($sekme === 'ayarlar' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="edonusum_belgeler.php?sekme=ayarlar">e-Logo Ayarları &amp; Mükellef</a>'
        . '</div><span class="muh-rozet ' . $sagRozetCls . '">Sağlayıcı: ' . h($sagEtiket) . ' · UBL-TR 1.2</span></div>';

    if ($sekme === 'giden') {
        if ($yazabilir) {
            $o .= '<details class="muh-kart" style="background:var(--muh-bg-soft);"><summary style="cursor:pointer;font-weight:700;">+ Yeni UBL-TR 1.2 Zarf Taslağı Oluştur (ETTN UUIDv4)</summary>';
            $o .= '<form method="post" action="muh_kaydet.php" class="muh-filtre" style="margin-top:12px;">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                . '<input type="hidden" name="islem" value="edonusum_olustur">'
                . '<input type="hidden" name="geri" value="edonusum_belgeler.php?sekme=giden">';
            if ($faturalar !== []) {
                $o .= '<div class="muh-alan" style="min-width:240px;"><label>Mevcut Faturadan (Opsiyonel)</label><select name="fatura_id"><option value="">— Manuel Giriş —</option>';
                foreach ($faturalar as $ft) {
                    $o .= '<option value="' . (int)$ft['id'] . '">' . h((string)$ft['belge_no'] . ' · ' . (string)$ft['unvan'] . ' (' . muh_tutar_fmt((float)$ft['genel_toplam']) . ' ' . (string)$ft['para_birimi'] . ')') . '</option>';
                }
                $o .= '</select></div>';
            }
            $o .= '<div class="muh-alan"><label>Belge Türü</label><select name="belge_turu">';
            foreach ($turler as $tk => $tl) {
                $o .= '<option value="' . h($tk) . '">' . h($tl) . '</option>';
            }
            $o .= '</select></div>';
            $o .= '<div class="muh-alan"><label>Senaryo</label><select name="senaryo"><option value="TICARIFATURA">TICARIFATURA</option><option value="TEMELFATURA">TEMELFATURA</option><option value="EARSIVFATURA">EARSIVFATURA</option><option value="TEMELIRSALIYE">TEMELIRSALIYE</option></select></div>';
            $o .= '<div class="muh-alan"><label>Belge No</label><input name="belge_no" placeholder="Boş bırakılırsa seriden üretilir" maxlength="40"></div>';
            $o .= '<div class="muh-alan"><label>Tarih</label><input type="date" name="tarih" value="' . h(date('Y-m-d')) . '"></div>';
            $o .= '<div class="muh-alan"><label>Alıcı VKN / TCKN</label><input name="alici_vkn" placeholder="10 veya 11 hane" maxlength="11"></div>';
            $o .= '<div class="muh-alan" style="flex:1;min-width:200px;"><label>Alıcı Ünvan</label><input name="alici_unvan" placeholder="Alıcı firma ünvanı"></div>';
            $o .= '<div class="muh-alan"><label>KDV Hariç Tutar</label><input name="ara_toplam" inputmode="decimal" placeholder="1000,00"></div>';
            $o .= '<div class="muh-alan"><label>KDV Tutarı</label><input name="kdv_toplam" inputmode="decimal" placeholder="200,00"></div>';
            $o .= '<div class="muh-alan"><label>Para Birimi</label><select name="para_birimi"><option value="TRY">TRY</option><option value="EUR">EUR</option><option value="USD">USD</option></select></div>';
            $o .= '<button type="submit" class="btn btn-primary">UBL-TR Taslak Oluştur</button></form></details>';
        }

        $o .= '<div class="muh-tablo-wrap"><table class="muh-tablo"><thead><tr>'
            . '<th>Belge No / ETTN</th><th>Tür</th><th>Tarih</th><th>Alıcı</th><th class="muh-num">Genel Toplam</th><th>Durum</th><th>İşlemler</th></tr></thead><tbody>';
        foreach ($zarflar as $z) {
            $d = (string)$z['durum'];
            $o .= '<tr><td><div class="muh-kod">' . h((string)$z['belge_no']) . '</div><div style="font-size:.75rem;color:var(--muh-muted);">' . h((string)$z['ettn']) . '</div></td>'
                . '<td>' . h($turler[(string)$z['belge_turu']] ?? (string)$z['belge_turu']) . '</td>'
                . '<td>' . h(muh_tarih_fmt((string)$z['tarih'])) . '</td>'
                . '<td>' . h((string)$z['alici_unvan']) . '<div style="font-size:.78rem;color:var(--muh-muted);">VKN: ' . h((string)$z['alici_vkn']) . '</div></td>'
                . '<td class="muh-num">' . h(muh_tutar_fmt((float)$z['genel_toplam'])) . ' ' . h((string)$z['para_birimi']) . '</td>'
                . '<td>' . $durumRozet($d) . ($z['yanit_mesaj'] ? '<div style="font-size:.75rem;color:var(--muh-muted);">' . h((string)$z['yanit_mesaj']) . '</div>' : '') . '</td>'
                . '<td><div class="muh-eylem-satir">'
                . '<a class="btn btn-ghost" style="padding:4px 8px;font-size:.8rem;" href="edonusum_xml.php?id=' . (int)$z['id'] . '">XML</a>';
            if ($elogoAktif && in_array($d, ['gonderildi', 'onaylandi'], true)) {
                $o .= '<a class="btn btn-ghost" style="padding:4px 8px;font-size:.8rem;" target="_blank" rel="noopener" href="edonusum_xml.php?id=' . (int)$z['id'] . '&amp;format=pdf">PDF</a>';
            }
            if ($yazabilir && in_array($d, ['taslak', 'hata'], true)) {
                $o .= '<form method="post" action="muh_kaydet.php" class="muh-inline-form">'
                    . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                    . '<input type="hidden" name="islem" value="edonusum_gonder">'
                    . '<input type="hidden" name="id" value="' . (int)$z['id'] . '">'
                    . '<input type="hidden" name="geri" value="edonusum_belgeler.php?sekme=giden">'
                    . '<button type="submit" class="btn btn-primary" style="padding:4px 8px;font-size:.8rem;">Gönder</button></form>';
            }
            if ($yazabilir && $d === 'gonderildi') {
                $o .= '<form method="post" action="muh_kaydet.php" class="muh-inline-form">'
                    . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                    . '<input type="hidden" name="islem" value="edonusum_durum">'
                    . '<input type="hidden" name="id" value="' . (int)$z['id'] . '">'
                    . '<input type="hidden" name="geri" value="edonusum_belgeler.php?sekme=giden">'
                    . '<button type="submit" class="btn btn-ghost" style="padding:4px 8px;font-size:.8rem;">Durum Sorgula</button></form>';
            }
            $o .= '</div></td></tr>';
        }
        if ($zarflar === []) {
            $o .= '<tr><td colspan="7" style="text-align:center;color:var(--muh-muted);">Henüz giden e-Dönüşüm zarfı yok.</td></tr>';
        }
        $o .= '</tbody></table></div>';
    } elseif ($sekme === 'gelen') {
        if ($yazabilir) {
            $o .= '<form method="post" action="muh_kaydet.php" class="muh-filtre" style="padding:12px;background:var(--muh-bg-soft);border-radius:10px;border:1px solid var(--muh-border);margin-bottom:14px;">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                . '<input type="hidden" name="islem" value="edonusum_gelen_cek">'
                . '<input type="hidden" name="geri" value="edonusum_belgeler.php?sekme=gelen">'
                . '<div class="muh-alan"><label>Başlangıç</label><input type="date" name="bas" value="' . h(date('Y-m-01')) . '" required></div>'
                . '<div class="muh-alan"><label>Bitiş</label><input type="date" name="bit" value="' . h(date('Y-m-d')) . '" required></div>'
                . '<div class="muh-alan"><label>Belge Türü</label><select name="belge_turu"><option value="efatura">e-Fatura</option><option value="eirsaliye">e-İrsaliye</option></select></div>'
                . '<button type="submit" class="btn btn-primary">e-Logo Gelen Kutusunu Çek</button>'
                . '</form>';
        }
        $o .= '<div class="muh-tablo-wrap"><table class="muh-tablo"><thead><tr>'
            . '<th>Belge No / ETTN</th><th>Tarih</th><th>Gönderen</th><th class="muh-num">Tutar</th><th>Durum</th><th>Yanıt (Kabul / Red)</th></tr></thead><tbody>';
        foreach ($gelenler as $g) {
            $d = (string)$g['durum'];
            $o .= '<tr><td><div class="muh-kod">' . h((string)$g['belge_no']) . '</div><div style="font-size:.75rem;color:var(--muh-muted);">' . h((string)$g['ettn']) . '</div></td>'
                . '<td>' . h(muh_tarih_fmt((string)$g['tarih'])) . '</td>'
                . '<td>' . h((string)$g['gonderen_unvan']) . '<div style="font-size:.78rem;color:var(--muh-muted);">VKN: ' . h((string)$g['gonderen_vkn']) . '</div></td>'
                . '<td class="muh-num">' . h(muh_tutar_fmt((float)$g['genel_toplam'])) . ' ' . h((string)$g['para_birimi']) . '</td>'
                . '<td>' . $durumRozet($d) . ($g['yanit_neden'] ? '<div style="font-size:.78rem;color:var(--muh-muted);">' . h((string)$g['yanit_neden']) . '</div>' : '') . '</td><td><div class="muh-eylem-satir">'
                . '<a class="btn btn-ghost" style="padding:4px 8px;font-size:.8rem;" href="edonusum_xml.php?kaynak=gelen&amp;id=' . (int)$g['id'] . '">XML</a>';
            if ($elogoAktif) {
                $o .= '<a class="btn btn-ghost" style="padding:4px 8px;font-size:.8rem;" target="_blank" rel="noopener" href="edonusum_xml.php?kaynak=gelen&amp;id=' . (int)$g['id'] . '&amp;format=pdf">PDF</a>';
            }
            if ($yazabilir && $d === 'bekliyor') {
                $o .= '<form method="post" action="muh_kaydet.php" class="muh-inline-form">'
                    . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                    . '<input type="hidden" name="islem" value="edonusum_gelen_yanit">'
                    . '<input type="hidden" name="id" value="' . (int)$g['id'] . '">'
                    . '<input type="hidden" name="yanit" value="kabul">'
                    . '<input type="hidden" name="geri" value="edonusum_belgeler.php?sekme=gelen">'
                    . '<button type="submit" class="btn btn-primary" style="padding:4px 8px;font-size:.8rem;">Kabul Et</button></form>'
                    . '<form method="post" action="muh_kaydet.php" class="muh-inline-form" data-edonusum-red="1">'
                    . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                    . '<input type="hidden" name="islem" value="edonusum_gelen_yanit">'
                    . '<input type="hidden" name="id" value="' . (int)$g['id'] . '">'
                    . '<input type="hidden" name="yanit" value="red">'
                    . '<input type="hidden" name="geri" value="edonusum_belgeler.php?sekme=gelen">'
                    . '<input type="text" name="neden" placeholder="Red nedeni..." style="padding:4px 8px;font-size:.8rem;width:140px;border:1px solid var(--muh-border);border-radius:6px;background:var(--muh-bg);color:var(--muh-text);">'
                    . '<button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:.8rem;">Reddet</button></form>';
            } else {
                $o .= '<span style="color:var(--muh-muted);font-size:.82rem;">Tamamlandı</span>';
            }
            $o .= '</div></td></tr>';
        }
        if ($gelenler === []) {
            $o .= '<tr><td colspan="6" style="text-align:center;color:var(--muh-muted);">Gelen kutusunda belge yok.</td></tr>';
        }
        $o .= '</tbody></table></div>';
    } else {
        // ── 3. Sekme: e-Logo Özel Entegratör Ayarları, Bağlantı Testi & GİB Mükellef Sorgulama ──
        $ortam = (string)($ayar['ortam'] ?? 'test');
        $sag   = (string)($ayar['saglayici'] ?? 'sandbox_test');
        $sifreKayitli = !empty($ayar['sifre_kayitli']);

        $o .= '<div style="display:grid;gap:16px;">';
        // Bağlantı Durumu & Test Şeridi
        $o .= '<div class="muh-kart" style="background:var(--muh-bg-soft);margin-bottom:0;">'
            . '<div class="muh-baslik-bar"><div>'
            . '<strong>e-Logo Web Servis Bağlantısı (PostBoxService SOAP 1.1)</strong>'
            . '<div style="font-size:.82rem;color:var(--muh-muted);margin-top:4px;">'
            . 'Canlı: <code>https://pb.elogo.com.tr/PostBoxService.svc</code> · Test/Demo: <code>https://pb-demo.elogo.com.tr/PostBoxService.svc</code>'
            . '</div>';
        if (!empty($ayar['son_test_at'])) {
            $tCls = ($ayar['son_test_durum'] ?? '') === 'ok' ? 'muh-rozet--ok' : 'muh-rozet--err';
            $o .= '<div style="margin-top:8px;font-size:.82rem;">'
                . '<span class="muh-rozet ' . $tCls . '">Son Test: ' . h((string)$ayar['son_test_at']) . '</span> '
                . h((string)($ayar['son_test_mesaj'] ?? '')) . '</div>';
        }
        $o .= '</div>';
        if ($yazabilir) {
            $o .= '<form method="post" action="muh_kaydet.php" class="muh-inline-form">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                . '<input type="hidden" name="islem" value="edonusum_baglanti_test">'
                . '<button type="submit" class="btn btn-primary">Bağlantıyı Test Et (e-Logo Login)</button></form>';
        }
        $o .= '</div></div>';

        // GİB Mükellef / Posta Kutusu Sorgulama Aracı
        $o .= '<div class="muh-kart" style="margin-bottom:0;">'
            . '<h3 style="margin:0 0 10px;">GİB e-Fatura / e-İrsaliye Mükellef Sorgula</h3>'
            . '<form method="get" action="edonusum_belgeler.php" class="muh-filtre" style="margin-bottom:0;">'
            . '<input type="hidden" name="sekme" value="ayarlar">'
            . '<div class="muh-alan"><label>VKN / TCKN</label><input name="sorgu_vkn" value="' . h((string)($mukellefSonuc['vkn'] ?? '')) . '" placeholder="10 veya 11 hane" maxlength="11" required></div>'
            . '<div class="muh-alan"><label>Belge Türü</label><select name="sorgu_tur">'
            . '<option value="efatura"' . (($mukellefSonuc['tur'] ?? 'efatura') === 'efatura' ? ' selected' : '') . '>e-Fatura (EINVOICE)</option>'
            . '<option value="eirsaliye"' . (($mukellefSonuc['tur'] ?? '') === 'eirsaliye' ? ' selected' : '') . '>e-İrsaliye (DESPATCHADVICE)</option>'
            . '</select></div>'
            . '<button type="submit" class="btn btn-ghost">GİB Mükellef Sorgula</button>'
            . '</form>';
        if ($mukellefSonuc !== null) {
            if (empty($mukellefSonuc['ok'])) {
                $o .= '<div style="margin-top:10px;padding:10px 12px;border-radius:8px;border:1px solid var(--muh-err,#dc2626);font-size:.86rem;">'
                    . '<strong>Sorgu Hatası:</strong> ' . h((string)($mukellefSonuc['hata'] ?? 'Bilinmeyen hata')) . '</div>';
            } else {
                $kayitli = !empty($mukellefSonuc['kayitli']);
                $o .= '<div style="margin-top:10px;padding:10px 12px;border-radius:8px;background:var(--muh-bg-soft);border:1px solid var(--muh-border);font-size:.86rem;">'
                    . '<span class="muh-rozet ' . ($kayitli ? 'muh-rozet--ok' : 'muh-rozet--warn') . '">'
                    . ($kayitli ? 'MÜKELLEF KAYITLI' : 'MÜKELLEF DEĞİL (e-Arşiv Kesilir)') . '</span> '
                    . '<strong>' . h((string)($mukellefSonuc['unvan'] ?? '')) . '</strong>'
                    . (!empty($mukellefSonuc['posta_kutusu']) ? ' · PK: <code>' . h((string)$mukellefSonuc['posta_kutusu']) . '</code>' : '')
                    . (!empty($mukellefSonuc['gb_etiket']) ? ' · GB: <code>' . h((string)$mukellefSonuc['gb_etiket']) . '</code>' : '')
                    . ' <span style="color:var(--muh-muted);">(' . h((string)($mukellefSonuc['saglayici'] ?? '')) . ')</span></div>';
            }
        }
        $o .= '</div>';

        // Entegratör & Firma Ayar Formu (Yalnız Admin kaydeder)
        $o .= '<div class="muh-kart" style="margin-bottom:0;">'
            . '<h3 style="margin:0 0 6px;">e-Logo Web Servis &amp; Firma Başlık Ayarları</h3>'
            . '<p style="margin:0 0 12px;font-size:.83rem;color:var(--muh-muted);">'
            . 'Not: e-Logo portal şifresi değil, e-Logo destek/portal üzerinden tanımlı <strong>Web Servis Kullanıcı Adı ve Şifresi</strong> girilmelidir. Şifre veritabanında AES-256-GCM ile şifreli saklanır.'
            . '</p>';
        if (!$adminMi) {
            $o .= '<div class="muh-rozet muh-rozet--warn">Entegratör ayarlarını yalnızca yönetici (admin) değiştirebilir.</div>';
        } else {
            $o .= '<form method="post" action="muh_kaydet.php">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                . '<input type="hidden" name="islem" value="edonusum_ayar_kaydet">'
                . '<div class="muh-filtre">'
                . '<div class="muh-alan"><label>Aktif Sağlayıcı</label><select name="saglayici">'
                . '<option value="sandbox_test"' . ($sag === 'sandbox_test' ? ' selected' : '') . '>Sandbox (Test Simülasyonu)</option>'
                . '<option value="elogo"' . ($sag === 'elogo' ? ' selected' : '') . '>e-Logo Özel Entegratör (Gerçek SOAP)</option>'
                . '</select></div>'
                . '<div class="muh-alan"><label>e-Logo Ortam</label><select name="ortam">'
                . '<option value="test"' . ($ortam === 'test' ? ' selected' : '') . '>Test / Demo (pb-demo.elogo.com.tr)</option>'
                . '<option value="canli"' . ($ortam === 'canli' ? ' selected' : '') . '>Canlı (pb.elogo.com.tr)</option>'
                . '</select></div>'
                . '<div class="muh-alan" style="flex:1;min-width:240px;"><label>Özel Servis URL (Opsiyonel — boşsa ortam varsayılanı)</label>'
                . '<input name="servis_url" value="' . h((string)($ayar['servis_url'] ?? '')) . '" placeholder="https://pb.elogo.com.tr/PostBoxService.svc"></div>'
                . '</div>'
                . '<div class="muh-filtre">'
                . '<div class="muh-alan" style="min-width:200px;"><label>e-Logo Web Servis Kullanıcı Adı (genelde VKN)</label>'
                . '<input name="kullanici_adi" value="' . h((string)($ayar['kullanici_adi'] ?? '')) . '" autocomplete="username" maxlength="120"></div>'
                . '<div class="muh-alan" style="min-width:220px;"><label>Web Servis Şifresi ' . ($sifreKayitli ? '<span class="muh-rozet muh-rozet--ok">Kayıtlı</span>' : '<span class="muh-rozet muh-rozet--warn">Girilmedi</span>') . '</label>'
                . '<input type="password" name="sifre" value="" autocomplete="new-password" placeholder="' . ($sifreKayitli ? 'Değiştirmek için yeni şifre girin' : 'e-Logo web servis şifresi') . '"></div>'
                . '<div class="muh-alan"><label>İmzalama Kipi</label><select name="otomatik_imza">'
                . '<option value="0"' . (empty($ayar['otomatik_imza']) ? ' selected' : '') . '>e-Logo HSM İmzalar (SIGNED=0 — Önerilen)</option>'
                . '<option value="1"' . (!empty($ayar['otomatik_imza']) ? ' selected' : '') . '>Önceden İmzalı (SIGNED=1)</option>'
                . '</select></div>'
                . '</div>'
                . '<h4 style="margin:14px 0 8px;">Firma UBL-TR Başlık &amp; Seri Bilgileri</h4>'
                . '<div class="muh-filtre">'
                . '<div class="muh-alan"><label>Firma VKN / TCKN</label><input name="firma_vkn" value="' . h((string)($ayar['firma_vkn'] ?? '')) . '" maxlength="11"></div>'
                . '<div class="muh-alan" style="flex:1;min-width:240px;"><label>Firma Resmi Ünvanı</label><input name="firma_unvan" value="' . h((string)($ayar['firma_unvan'] ?? '')) . '" maxlength="200"></div>'
                . '<div class="muh-alan"><label>Vergi Dairesi</label><input name="firma_vergi_dairesi" value="' . h((string)($ayar['firma_vergi_dairesi'] ?? '')) . '" maxlength="120"></div>'
                . '</div>'
                . '<div class="muh-filtre">'
                . '<div class="muh-alan"><label>İl</label><input name="firma_il" value="' . h((string)($ayar['firma_il'] ?? '')) . '" maxlength="80"></div>'
                . '<div class="muh-alan"><label>İlçe</label><input name="firma_ilce" value="' . h((string)($ayar['firma_ilce'] ?? '')) . '" maxlength="80"></div>'
                . '<div class="muh-alan" style="flex:1;min-width:240px;"><label>Açık Adres</label><input name="firma_adres" value="' . h((string)($ayar['firma_adres'] ?? '')) . '" maxlength="300"></div>'
                . '</div>'
                . '<div class="muh-filtre">'
                . '<div class="muh-alan"><label>e-Fatura Seri (3 harf)</label><input name="seri_efatura" value="' . h((string)($ayar['seri_efatura'] ?? 'ASY')) . '" maxlength="3"></div>'
                . '<div class="muh-alan"><label>e-Arşiv Seri (3 harf)</label><input name="seri_earsiv" value="' . h((string)($ayar['seri_earsiv'] ?? 'ASA')) . '" maxlength="3"></div>'
                . '<div class="muh-alan"><label>e-İrsaliye Seri (3 harf)</label><input name="seri_eirsaliye" value="' . h((string)($ayar['seri_eirsaliye'] ?? 'ASI')) . '" maxlength="3"></div>'
                . '<div class="muh-alan" style="min-width:220px;"><label>GB Etiketi (Opsiyonel)</label><input name="gb_etiket" value="' . h((string)($ayar['gb_etiket'] ?? '')) . '" placeholder="urn:mail:defaultgb@..."></div>'
                . '<div class="muh-alan" style="min-width:220px;"><label>PK Etiketi (Opsiyonel)</label><input name="pk_etiket" value="' . h((string)($ayar['pk_etiket'] ?? '')) . '" placeholder="urn:mail:defaultpk@..."></div>'
                . '</div>'
                . '<details style="margin:8px 0 14px;"><summary style="cursor:pointer;font-size:.85rem;color:var(--muh-muted);">Gelişmiş: e-Logo XSLT Tasarım UUID Bilgileri (Opsiyonel)</summary>'
                . '<div class="muh-filtre" style="margin-top:8px;">'
                . '<div class="muh-alan" style="flex:1;"><label>e-Fatura XSLT UUID</label><input name="xslt_efatura_uuid" value="' . h((string)($ayar['xslt_efatura_uuid'] ?? '')) . '" maxlength="64"></div>'
                . '<div class="muh-alan" style="flex:1;"><label>e-Arşiv XSLT UUID</label><input name="xslt_earsiv_uuid" value="' . h((string)($ayar['xslt_earsiv_uuid'] ?? '')) . '" maxlength="64"></div>'
                . '<div class="muh-alan" style="flex:1;"><label>e-İrsaliye XSLT UUID</label><input name="xslt_eirsaliye_uuid" value="' . h((string)($ayar['xslt_eirsaliye_uuid'] ?? '')) . '" maxlength="64"></div>'
                . '</div></details>'
                . '<button type="submit" class="btn btn-primary">e-Logo Ayarlarını Kaydet</button>'
                . '</form>';
        }
        $o .= '</div></div>';
    }

    $o .= '</div></div>';
    return $o;
}

// ── 5) ERP-11a: Cari Risk Limiti, Form BA/BS & Döviz Kur Farkı Değerleme ──

function muh_mutabakat_render(string $sekme, array $veri, bool $yazabilir): string
{
    $csrf = function_exists('csrf_token') ? csrf_token() : '';
    $o = '<div class="muh-wrap">' . muh_ust_menu('muh_mutabakat');
    $o .= '<div class="muh-kart"><div class="muh-eylem-satir" style="margin-bottom:14px;">'
        . '<a class="' . ($sekme === 'risk' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="muh_mutabakat.php?sekme=risk">Cari Kredi / Risk Limitleri</a>'
        . '<a class="' . ($sekme === 'babs' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="muh_mutabakat.php?sekme=babs">Form BA / BS Mutabakat</a>'
        . '<a class="' . ($sekme === 'kur_farki' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="muh_mutabakat.php?sekme=kur_farki">Döviz Kur Farkı Değerleme (646/656)</a>'
        . '</div>';

    if ($sekme === 'risk') {
        $cariler = $veri['cariler'] ?? [];
        $limitler = $veri['limitler'] ?? [];
        if ($yazabilir) {
            $o .= '<form method="post" action="muh_kaydet.php" class="muh-filtre" style="padding:12px;background:var(--muh-bg-soft);border-radius:10px;border:1px solid var(--muh-border);">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                . '<input type="hidden" name="islem" value="risk_limit_kaydet">'
                . '<input type="hidden" name="geri" value="muh_mutabakat.php?sekme=risk">';
            $o .= '<div class="muh-alan" style="min-width:220px;"><label>Cari Kart</label>';
            if ($cariler !== []) {
                $o .= '<select name="cari_id" required><option value="">— Seçin —</option>';
                foreach ($cariler as $c) {
                    $o .= '<option value="' . (int)$c['id'] . '">' . h((string)$c['kod'] . ' — ' . (string)$c['unvan']) . '</option>';
                }
                $o .= '</select>';
            } else {
                $o .= '<input type="number" name="cari_id" min="1" required placeholder="Cari ID">';
            }
            $o .= '</div>';
            $o .= '<div class="muh-alan"><label>Risk Limiti (TL)</label><input name="risk_limiti" inputmode="decimal" required placeholder="100000,00"></div>';
            $o .= '<div class="muh-alan"><label>Uyarı Eşiği (%)</label><input type="number" name="uyari_yuzde" min="10" max="100" value="80"></div>';
            $o .= '<div class="muh-alan"><label><input type="checkbox" name="blokaj_aktif" value="1"> Aşımda Blokaj Aktif</label></div>';
            $o .= '<div class="muh-alan" style="flex:1;"><label>Not</label><input name="notlar" maxlength="400" placeholder="Teminat / çek limiti notu"></div>';
            $o .= '<button type="submit" class="btn btn-primary">Risk Limitini Kaydet</button></form>';
        }
        $o .= '<div class="muh-tablo-wrap"><table class="muh-tablo"><thead><tr>'
            . '<th>Cari</th><th class="muh-num">Risk Limiti</th><th class="muh-num">Mevcut Bakiye</th><th>Doluluk</th><th>Blokaj</th><th>Durum</th></tr></thead><tbody>';
        foreach ($limitler as $l) {
            $dur = (string)($l['risk_durum'] ?? 'ok');
            $oran = min(100.0, max(0.0, (float)($l['oran_yuzde'] ?? 0)));
            $roz = $dur === 'blokaj' ? 'muh-rozet--err' : ($dur === 'uyari' ? 'muh-rozet--warn' : 'muh-rozet--ok');
            $o .= '<tr><td><strong>' . h((string)($l['unvan'] ?: ('Cari #' . $l['cari_id']))) . '</strong>'
                . ($l['cari_kod'] ? ' <span class="muh-kod" style="font-size:.8rem;color:var(--muh-muted);">(' . h((string)$l['cari_kod']) . ')</span>' : '') . '</td>'
                . '<td class="muh-num">' . h(muh_tutar_fmt((float)$l['risk_limiti'])) . ' ' . h((string)$l['para_birimi']) . '</td>'
                . '<td class="muh-num">' . h(muh_tutar_fmt((float)($l['mevcut_bakiye'] ?? 0))) . ' TL</td>'
                . '<td><div class="muh-risk-bar" data-durum="' . h($dur) . '"><span style="width:' . (int)round($oran) . '%;"></span></div><span style="font-size:.78rem;">%' . h(number_format((float)($l['oran_yuzde'] ?? 0), 1, ',', '.')) . '</span></td>'
                . '<td>' . ((int)$l['blokaj_aktif'] === 1 ? '<span class="muh-rozet muh-rozet--err">Blokajlı</span>' : '<span class="muh-rozet">Yalnız Uyarı</span>') . '</td>'
                . '<td><span class="muh-rozet ' . $roz . '">' . h(strtoupper($dur)) . '</span></td></tr>';
        }
        if ($limitler === []) {
            $o .= '<tr><td colspan="6" style="text-align:center;color:var(--muh-muted);">Tanımlı cari risk limiti yok.</td></tr>';
        }
        $o .= '</tbody></table></div>';
    } elseif ($sekme === 'babs') {
        $babs = $veri['babs'] ?? [];
        $yil = (int)($babs['yil'] ?? date('Y'));
        $ay = (int)($babs['ay'] ?? date('n'));
        $esik = (float)($babs['esik'] ?? 5000.0);
        $o .= '<form method="get" class="muh-filtre"><input type="hidden" name="sekme" value="babs">'
            . '<div class="muh-alan"><label>Yıl</label><input type="number" name="yil" min="2000" max="2099" value="' . $yil . '"></div>'
            . '<div class="muh-alan"><label>Ay</label><select name="ay">';
        for ($m = 1; $m <= 12; $m++) {
            $o .= '<option value="' . $m . '"' . ($ay === $m ? ' selected' : '') . '>' . sprintf('%02d', $m) . '</option>';
        }
        $o .= '</select></div>'
            . '<div class="muh-alan"><label>Eşik Tutar (TL, KDV Hariç)</label><input name="esik" inputmode="decimal" value="' . h((string)$esik) . '"></div>'
            . '<button type="submit" class="btn btn-primary">BA / BS Hesapla</button></form>';
        $o .= '<div class="muh-grid-2">';
        foreach (['ba' => 'Form BA (Alış Faturaları)', 'bs' => 'Form BS (Satış Faturaları)'] as $k => $baslik) {
            $o .= '<div><h3>' . h($baslik) . ' — Toplam: ' . h(muh_tutar_fmt((float)($babs[$k . '_toplam'] ?? 0))) . ' TL</h3>'
                . '<div class="muh-tablo-wrap"><table class="muh-tablo"><thead><tr><th>Ünvan</th><th>VKN</th><th class="muh-num">Belge</th><th class="muh-num">KDV Hariç (TL)</th></tr></thead><tbody>';
            foreach (($babs[$k] ?? []) as $row) {
                $o .= '<tr><td>' . h((string)$row['unvan']) . '</td><td class="muh-kod">' . h((string)$row['vergi_no']) . '</td><td class="muh-num">' . (int)$row['belge_sayisi'] . '</td><td class="muh-num">' . h(muh_tutar_fmt((float)$row['tutar_tl'])) . '</td></tr>';
            }
            if (empty($babs[$k])) {
                $o .= '<tr><td colspan="4" style="text-align:center;color:var(--muh-muted);">Eşiği aşan kayıt yok.</td></tr>';
            }
            $o .= '</tbody></table></div></div>';
        }
        $o .= '</div>';
    } else {
        $onizleme = $veri['onizleme'] ?? [];
        $gecmis = $veri['gecmis'] ?? [];
        $tarih = (string)($veri['tarih'] ?? date('Y-m-d'));
        $donemKodu = substr($tarih, 0, 7);

        $o .= '<form method="get" class="muh-filtre"><input type="hidden" name="sekme" value="kur_farki">'
            . '<div class="muh-alan"><label>Değerleme Tarihi</label><input type="date" name="tarih" value="' . h($tarih) . '"></div>'
            . '<button type="submit" class="btn btn-ghost">Önizle</button></form>';

        $o .= '<div class="muh-kpi-grid">'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">646 Kambiyo Kârı</div><div class="muh-kpi-deger ok">' . h(muh_tutar_fmt((float)($onizleme['toplam_kar'] ?? 0))) . ' TL</div></div>'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">656 Kambiyo Zararı</div><div class="muh-kpi-deger err">' . h(muh_tutar_fmt((float)($onizleme['toplam_zarar'] ?? 0))) . ' TL</div></div>'
            . '<div class="muh-kpi"><div class="muh-kpi-etiket">Net Kur Farkı</div><div class="muh-kpi-deger">' . h(muh_tutar_fmt((float)($onizleme['net_fark'] ?? 0))) . ' TL</div></div></div>';

        if (!empty($onizleme['eksik_kurlar'])) {
            $o .= '<p style="color:var(--muh-err);font-weight:600;">Eksik döviz kurları: ' . h(implode(', ', $onizleme['eksik_kurlar'])) . ' — Günlük Kurlar ekranından güncelleyin.</p>';
        }
        $o .= '<div class="muh-tablo-wrap"><table class="muh-tablo"><thead><tr>'
            . '<th>Hesap</th><th>PB</th><th class="muh-num">Döviz Bakiye</th><th class="muh-num">Defter TL</th><th class="muh-num">Kur</th><th class="muh-num">Değerlenmiş TL</th><th class="muh-num">Fark (646/656)</th></tr></thead><tbody>';
        foreach (($onizleme['satirlar'] ?? []) as $s) {
            $fark = (float)$s['fark_tl'];
            $o .= '<tr><td class="muh-kod">' . h((string)$s['hesap_kod']) . '</td><td>' . h((string)$s['para_birimi']) . '</td>'
                . '<td class="muh-num">' . h(muh_tutar_fmt((float)($s['net_doviz'] ?? 0))) . '</td>'
                . '<td class="muh-num">' . h(muh_tutar_fmt((float)$s['defter_tl'])) . '</td>'
                . '<td class="muh-num">' . h(number_format((float)$s['kur'], 4, ',', '.')) . '</td>'
                . '<td class="muh-num">' . h(muh_tutar_fmt((float)$s['degerlenmis_tl'])) . '</td>'
                . '<td class="muh-num" style="color:' . ($fark >= 0 ? 'var(--muh-ok)' : 'var(--muh-err)') . ';font-weight:700;">' . h(muh_tutar_fmt($fark)) . '</td></tr>';
        }
        if (empty($onizleme['satirlar'])) {
            $o .= '<tr><td colspan="7" style="text-align:center;color:var(--muh-muted);">Kur farkı oluşan açık döviz pozisyonu yok.</td></tr>';
        }
        $o .= '</tbody></table></div>';

        if ($yazabilir && !empty($onizleme['satirlar']) && empty($onizleme['eksik_kurlar'])) {
            $o .= '<form method="post" action="muh_kaydet.php" class="muh-filtre" style="margin-top:14px;" onsubmit="return confirm(\'Dönem sonu kur farkı değerleme fişi (646/656) Genel Muhasebe yevmiyesine işlenecek. Onaylıyor musunuz?\');">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                . '<input type="hidden" name="islem" value="kur_farki_uygula">'
                . '<input type="hidden" name="tarih" value="' . h($tarih) . '">'
                . '<input type="hidden" name="geri" value="muh_mutabakat.php?sekme=kur_farki">'
                . '<div class="muh-alan"><label>Dönem Kodu (YYYY-AA)</label><input name="donem_kodu" required value="' . h($donemKodu) . '"></div>'
                . '<button type="submit" class="btn btn-primary">646/656 Yevmiye Fişini Oluştur</button></form>';
        }

        if ($gecmis !== []) {
            $o .= '<h3 style="margin-top:18px;">Geçmiş Kur Değerlemeleri</h3><div class="muh-tablo-wrap"><table class="muh-tablo"><thead><tr>'
                . '<th>Dönem</th><th>Tarih</th><th class="muh-num">646 Kâr</th><th class="muh-num">656 Zarar</th><th class="muh-num">Net</th><th>Yevmiye Fişi</th></tr></thead><tbody>';
            foreach ($gecmis as $gk) {
                $o .= '<tr><td class="muh-kod">' . h((string)$gk['donem_kodu']) . '</td>'
                    . '<td>' . h(muh_tarih_fmt((string)$gk['degerleme_tarih'])) . '</td>'
                    . '<td class="muh-num">' . h(muh_tutar_fmt((float)$gk['toplam_kar'])) . '</td>'
                    . '<td class="muh-num">' . h(muh_tutar_fmt((float)$gk['toplam_zarar'])) . '</td>'
                    . '<td class="muh-num">' . h(muh_tutar_fmt((float)$gk['net_fark'])) . '</td>'
                    . '<td>' . ($gk['muh_fis_id'] ? '<a href="muh_yevmiye.php?fis_id=' . (int)$gk['muh_fis_id'] . '">Fiş #' . (int)$gk['muh_fis_id'] . '</a>' : '—') . '</td></tr>';
            }
            $o .= '</tbody></table></div>';
        }
    }

    $o .= '</div></div>';
    return $o;
}
