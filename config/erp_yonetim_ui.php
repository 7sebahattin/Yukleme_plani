<?php
// =========================================================
// config/erp_yonetim_ui.php — ERP Faz 3 UI Yardımcıları (Onay Akışları, Banka Mutabakat & İK/Bordro)
// Kurallar: Include-only (404) · SIFIR SQL · Tüm dinamik değerler h() ile kaçışlanır
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

function erp_yon_head_varlik(): void
{
    $v = (int)@filemtime(dirname(__DIR__) . '/assets/erp_yonetim.css');
    echo '<link rel="stylesheet" href="assets/erp_yonetim.css?v=' . $v . '">' . "\n";
}

function erp_yon_scripts(): void
{
    $v = (int)@filemtime(dirname(__DIR__) . '/assets/erp_yonetim.js');
    echo '<script src="assets/erp_yonetim.js?v=' . $v . '" defer></script>' . "\n";
}

function erp_yon_fmt(float $v, int $d = 2): string
{
    return number_format($v, $d, ',', '.');
}

function erp_yon_tarih_tr(string $t): string
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $t, $m) ? ($m[3] . '.' . $m[2] . '.' . $m[1]) : $t;
}

function erp_yon_ust_menu(string $aktif): string
{
    $sekmeler = [
        'onay_merkezi'  => ['onay_merkezi.php',  'Onay Akışları Merkezi'],
        'banka_yonetim' => ['banka_yonetim.php', 'Banka Mutabakat, Kredi & POS'],
        'ik_bordro'     => ['ik_bordro.php',     'İK Özlük, İzin & Bordro'],
    ];
    $o = '<nav class="ey-sekmeler" aria-label="Yönetişim, Banka ve İK">';
    foreach ($sekmeler as $slug => [$url, $ad]) {
        if (function_exists('muhasebe_sayfa_can') && !muhasebe_sayfa_can($slug)) continue;
        $o .= '<a class="ey-sekme" href="' . h($url) . '"' . ($slug === $aktif ? ' aria-current="page"' : '') . '>' . h($ad) . '</a>';
    }
    return $o . '</nav>';
}

/** @param list<string> $eksik */
function erp_yon_sema_uyari(array $eksik, string $hash, string $baslik): string
{
    return '<div class="ey-wrap"><div class="ey-uyari" role="alert">'
        . '<div style="font-size:1.6rem;" aria-hidden="true">&#9888;</div>'
        . '<div><h2 style="margin:0 0 6px;">' . h($baslik) . ' şeması kurulmamış</h2>'
        . '<p style="margin:0 0 10px;">Eksik tablolar: <code>' . h(implode(', ', $eksik)) . '</code>. Yönetici Şema Migrasyon panelinden ilgili kartı çalıştırın.</p>'
        . '<a class="btn btn-primary" href="migrate.php#' . h($hash) . '">Şema Migrasyon &rsaquo; ' . h($baslik) . '</a>'
        . '</div></div></div>';
}

// ── 1) ERP-5: Onay Akışları Merkezi ────────────────────────────────────────

function onay_merkezi_render(string $sekme, array $veri, bool $yazabilir): string
{
    $csrf = function_exists('csrf_token') ? csrf_token() : '';
    $moduller = onay_modulleri();
    $o = '<div class="ey-wrap">' . erp_yon_ust_menu('onay_merkezi');
    $o .= '<div class="ey-kart"><div class="ey-eylem-satir" style="margin-bottom:14px;">'
        . '<a class="' . ($sekme === 'talepler' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="onay_merkezi.php?sekme=talepler">Onay Talepleri</a>'
        . '<a class="' . ($sekme === 'kurallar' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="onay_merkezi.php?sekme=kurallar">Onay Kuralları (Limit / Kademe)</a>'
        . '<a class="' . ($sekme === 'vekalet' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="onay_merkezi.php?sekme=vekalet">Vekalet / Delegasyon</a>'
        . '</div>';

    if ($sekme === 'talepler') {
        $talepler = $veri['talepler'] ?? [];
        if ($yazabilir) {
            $o .= '<details class="ey-kart" style="background:var(--ey-bg-soft);"><summary style="cursor:pointer;font-weight:700;">+ Yeni Onay Talebi Başlat</summary>';
            $o .= '<form method="post" action="erp_yonetim_kaydet.php" class="ey-filtre" style="margin-top:12px;">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
                . '<input type="hidden" name="islem" value="onay_talep_olustur">'
                . '<input type="hidden" name="geri" value="onay_merkezi.php?sekme=talepler">'
                . '<div class="ey-alan"><label>Modül</label><select name="modul">';
            foreach ($moduller as $mk => $ml) $o .= '<option value="' . h($mk) . '">' . h($ml) . '</option>';
            $o .= '</select></div>'
                . '<div class="ey-alan" style="flex:1;"><label>Talep Başlığı</label><input name="baslik" required placeholder="Örn. 75.000 TL Ambalaj Alım Onayı"></div>'
                . '<div class="ey-alan"><label>Tutar</label><input name="tutar" inputmode="decimal" value="0"></div>'
                . '<div class="ey-alan"><label>PB</label><select name="para_birimi"><option value="TRY">TRY</option><option value="EUR">EUR</option><option value="USD">USD</option></select></div>'
                . '<div class="ey-alan" style="flex:1;"><label>Açıklama</label><input name="aciklama" placeholder="Gerekçe / detay"></div>'
                . '<button type="submit" class="btn btn-primary">Talebi Başlat</button></form></details>';
        }
        $o .= '<div class="ey-tablo-wrap"><table class="ey-tablo"><thead><tr>'
            . '<th>Talep No</th><th>Modül / Başlık</th><th class="ey-num">Tutar</th><th>Kip / Adım</th><th>Durum</th><th>Karar</th></tr></thead><tbody>';
        foreach ($talepler as $t) {
            $d = (string)$t['durum'];
            $roz = $d === 'onaylandi' ? 'ey-rozet--ok' : ($d === 'reddedildi' ? 'ey-rozet--err' : 'ey-rozet--warn');
            $o .= '<tr><td class="ey-kod">' . h((string)$t['talep_no']) . '</td>'
                . '<td><div style="font-size:.78rem;color:var(--ey-muted);">' . h($moduller[(string)$t['modul']] ?? (string)$t['modul']) . '</div><strong>' . h((string)$t['baslik']) . '</strong></td>'
                . '<td class="ey-num">' . h(erp_yon_fmt((float)$t['tutar'])) . ' ' . h((string)$t['para_birimi']) . '</td>'
                . '<td>' . h(strtoupper((string)$t['kip'])) . ' (' . (int)$t['mevcut_adim'] . '/' . (int)$t['toplam_adim'] . ')</td>'
                . '<td><span class="ey-rozet ' . $roz . '">' . h(strtoupper($d)) . '</span></td><td>';
            if ($yazabilir && $d === 'bekliyor') {
                $o .= '<div class="ey-eylem-satir">'
                    . '<form method="post" action="erp_yonetim_kaydet.php" class="ey-inline-form">'
                    . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="onay_karar_ver">'
                    . '<input type="hidden" name="id" value="' . (int)$t['id'] . '"><input type="hidden" name="karar" value="onay">'
                    . '<input type="hidden" name="geri" value="onay_merkezi.php?sekme=talepler">'
                    . '<button type="submit" class="btn btn-primary" style="padding:4px 8px;font-size:.8rem;">Onayla</button></form>'
                    . '<form method="post" action="erp_yonetim_kaydet.php" class="ey-inline-form" data-onay-red="1">'
                    . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="onay_karar_ver">'
                    . '<input type="hidden" name="id" value="' . (int)$t['id'] . '"><input type="hidden" name="karar" value="red">'
                    . '<input type="hidden" name="geri" value="onay_merkezi.php?sekme=talepler">'
                    . '<input name="not" placeholder="Red nedeni..." style="width:120px;padding:4px 8px;border:1px solid var(--ey-border);border-radius:6px;background:var(--ey-bg);color:var(--ey-text);">'
                    . '<button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:.8rem;">Reddet</button></form></div>';
            } else {
                $o .= '<span style="color:var(--ey-muted);font-size:.82rem;">Tamamlandı</span>';
            }
            $o .= '</td></tr>';
        }
        if ($talepler === []) $o .= '<tr><td colspan="6" style="text-align:center;color:var(--ey-muted);">Henüz onay talebi yok.</td></tr>';
        $o .= '</tbody></table></div>';
    } elseif ($sekme === 'kurallar') {
        $kurallar = $veri['kurallar'] ?? [];
        if ($yazabilir) {
            $o .= '<form method="post" action="erp_yonetim_kaydet.php" class="ey-filtre" style="padding:12px;background:var(--ey-bg-soft);border-radius:10px;border:1px solid var(--ey-border);">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="onay_kural_kaydet"><input type="hidden" name="geri" value="onay_merkezi.php?sekme=kurallar">'
                . '<div class="ey-alan"><label>Modül</label><select name="modul">';
            foreach ($moduller as $mk => $ml) $o .= '<option value="' . h($mk) . '">' . h($ml) . '</option>';
            $o .= '</select></div>'
                . '<div class="ey-alan" style="flex:1;"><label>Kural Adı</label><input name="ad" required placeholder="50.000 TL Üstü Çift Kademeli Onay"></div>'
                . '<div class="ey-alan"><label>Min Tutar</label><input name="min_tutar" inputmode="decimal" value="0"></div>'
                . '<div class="ey-alan"><label>Maks Tutar (0=Sınırsız)</label><input name="maks_tutar" inputmode="decimal" value="0"></div>'
                . '<div class="ey-alan"><label>Kip</label><select name="kip"><option value="sirali">Sıralı</option><option value="paralel">Paralel</option></select></div>'
                . '<div class="ey-alan"><label>Onaylayıcı User ID (virgüllü)</label><input name="onaycilar" required placeholder="1, 2"></div>'
                . '<button type="submit" class="btn btn-primary">Kuralı Kaydet</button></form>';
        }
        $o .= '<div class="ey-tablo-wrap"><table class="ey-tablo"><thead><tr><th>Modül</th><th>Kural Adı</th><th class="ey-num">Min Tutar</th><th class="ey-num">Maks Tutar</th><th>Kip</th><th>Onaylayıcılar</th></tr></thead><tbody>';
        foreach ($kurallar as $k) {
            $o .= '<tr><td>' . h($moduller[(string)$k['modul']] ?? (string)$k['modul']) . '</td><td><strong>' . h((string)$k['ad']) . '</strong></td>'
                . '<td class="ey-num">' . h(erp_yon_fmt((float)$k['min_tutar'])) . '</td>'
                . '<td class="ey-num">' . ((float)$k['maks_tutar'] > 0 ? h(erp_yon_fmt((float)$k['maks_tutar'])) : 'Sınırsız') . '</td>'
                . '<td>' . h(strtoupper((string)$k['kip'])) . '</td><td class="ey-kod">' . h((string)$k['onaycilar_json']) . '</td></tr>';
        }
        if ($kurallar === []) $o .= '<tr><td colspan="6" style="text-align:center;color:var(--ey-muted);">Tanımlı onay kuralı yok.</td></tr>';
        $o .= '</tbody></table></div>';
    } else {
        $vekaletler = $veri['vekaletler'] ?? [];
        if ($yazabilir) {
            $o .= '<form method="post" action="erp_yonetim_kaydet.php" class="ey-filtre" style="padding:12px;background:var(--ey-bg-soft);border-radius:10px;border:1px solid var(--ey-border);">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="onay_vekalet_kaydet"><input type="hidden" name="geri" value="onay_merkezi.php?sekme=vekalet">'
                . '<div class="ey-alan"><label>Asil Kullanıcı ID</label><input type="number" name="asil_user_id" min="1" required></div>'
                . '<div class="ey-alan"><label>Vekil Kullanıcı ID</label><input type="number" name="vekil_user_id" min="1" required></div>'
                . '<div class="ey-alan"><label>Başlangıç</label><input type="date" name="bas_tarih" value="' . h(date('Y-m-d')) . '" required></div>'
                . '<div class="ey-alan"><label>Bitiş</label><input type="date" name="bit_tarih" value="' . h(date('Y-m-d', strtotime('+7 days'))) . '" required></div>'
                . '<div class="ey-alan" style="flex:1;"><label>Not</label><input name="notlar" placeholder="Yıllık izin vekaleti"></div>'
                . '<button type="submit" class="btn btn-primary">Vekalet Kaydet</button></form>';
        }
        $o .= '<div class="ey-tablo-wrap"><table class="ey-tablo"><thead><tr><th>Asil ID</th><th>Vekil ID</th><th>Tarih Aralığı</th><th>Not</th></tr></thead><tbody>';
        foreach ($vekaletler as $v) {
            $o .= '<tr><td class="ey-kod">#' . (int)$v['asil_user_id'] . '</td><td class="ey-kod">#' . (int)$v['vekil_user_id'] . '</td>'
                . '<td>' . h(erp_yon_tarih_tr((string)$v['bas_tarih']) . ' — ' . erp_yon_tarih_tr((string)$v['bit_tarih'])) . '</td><td>' . h((string)$v['notlar']) . '</td></tr>';
        }
        if ($vekaletler === []) $o .= '<tr><td colspan="4" style="text-align:center;color:var(--ey-muted);">Aktif vekalet kaydı yok.</td></tr>';
        $o .= '</tbody></table></div>';
    }
    $o .= '</div></div>';
    return $o;
}

// ── 2) ERP-6: Banka Mutabakat, Kredi & POS ─────────────────────────────────

function banka_yonetim_render(string $sekme, array $veri, bool $yazabilir): string
{
    $csrf = function_exists('csrf_token') ? csrf_token() : '';
    $hesaplar = $veri['hesaplar'] ?? [];
    $o = '<div class="ey-wrap">' . erp_yon_ust_menu('banka_yonetim');
    $o .= '<div class="ey-kart"><div class="ey-eylem-satir" style="margin-bottom:14px;">'
        . '<a class="' . ($sekme === 'hesaplar' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="banka_yonetim.php?sekme=hesaplar">Banka Hesapları</a>'
        . '<a class="' . ($sekme === 'ekstre' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="banka_yonetim.php?sekme=ekstre">MT940 / CSV Ekstre &amp; Mutabakat</a>'
        . '<a class="' . ($sekme === 'kredi' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="banka_yonetim.php?sekme=kredi">Kredi &amp; Taksit Planları</a>'
        . '<a class="' . ($sekme === 'pos' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="banka_yonetim.php?sekme=pos">POS Komisyon &amp; Bloke</a>'
        . '</div>';

    if ($sekme === 'hesaplar') {
        if ($yazabilir) {
            $o .= '<form method="post" action="erp_yonetim_kaydet.php" class="ey-filtre" style="padding:12px;background:var(--ey-bg-soft);border-radius:10px;border:1px solid var(--ey-border);">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="banka_hesap_kaydet"><input type="hidden" name="geri" value="banka_yonetim.php?sekme=hesaplar">'
                . '<div class="ey-alan"><label>Hesap Kodu</label><input name="kod" placeholder="GAR-TRY-01"></div>'
                . '<div class="ey-alan"><label>Banka Adı</label><input name="banka_ad" required placeholder="Garanti BBVA"></div>'
                . '<div class="ey-alan"><label>Şube</label><input name="sube_ad" placeholder="Mersin Kurumsal"></div>'
                . '<div class="ey-alan" style="flex:1;"><label>IBAN</label><input name="iban" placeholder="TR..."></div>'
                . '<div class="ey-alan"><label>PB</label><select name="para_birimi"><option value="TRY">TRY</option><option value="EUR">EUR</option><option value="USD">USD</option></select></div>'
                . '<div class="ey-alan"><label>Açılış Bakiyesi</label><input name="acilis_bakiye" inputmode="decimal" value="0"></div>'
                . '<button type="submit" class="btn btn-primary">Banka Hesabı Ekle</button></form>';
        }
        $o .= '<div class="ey-tablo-wrap"><table class="ey-tablo"><thead><tr><th>Kod</th><th>Banka / Şube</th><th>IBAN</th><th>PB</th><th>TDHP</th><th class="ey-num">Güncel Bakiye</th></tr></thead><tbody>';
        foreach ($hesaplar as $h) {
            $o .= '<tr><td class="ey-kod">' . h((string)$h['kod']) . '</td><td><strong>' . h((string)$h['banka_ad']) . '</strong> ' . h((string)$h['sube_ad']) . '</td>'
                . '<td class="ey-kod">' . h((string)$h['iban']) . '</td><td>' . h((string)$h['para_birimi']) . '</td><td class="ey-kod">' . h((string)$h['tdhp_hesap_kod']) . '</td>'
                . '<td class="ey-num"><strong>' . h(erp_yon_fmt((float)$h['guncel_bakiye'])) . '</strong></td></tr>';
        }
        if ($hesaplar === []) $o .= '<tr><td colspan="6" style="text-align:center;color:var(--ey-muted);">Tanımlı banka hesabı yok.</td></tr>';
        $o .= '</tbody></table></div>';
    } elseif ($sekme === 'ekstre') {
        $ekstreler = $veri['ekstreler'] ?? [];
        if ($yazabilir && $hesaplar !== []) {
            $o .= '<form method="post" action="erp_yonetim_kaydet.php" style="padding:12px;background:var(--ey-bg-soft);border-radius:10px;border:1px solid var(--ey-border);margin-bottom:14px;">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="banka_ekstre_ice_aktar"><input type="hidden" name="geri" value="banka_yonetim.php?sekme=ekstre">'
                . '<div class="ey-filtre"><div class="ey-alan"><label>Banka Hesabı</label><select name="hesap_id" required>';
            foreach ($hesaplar as $h) $o .= '<option value="' . (int)$h['id'] . '">' . h((string)$h['kod'] . ' — ' . (string)$h['banka_ad']) . '</option>';
            $o .= '</select></div><button type="submit" class="btn btn-primary">MT940 / CSV İçe Aktar &amp; Oto-Eşleştir</button></div>'
                . '<div class="ey-alan"><label>MT940 (:61:/:86:) veya CSV (YYYY-AA-GG;REF;AÇIKLAMA;TUTAR)</label><textarea name="ham_metin" rows="4" required placeholder="2026-05-01;REF01;Müşteri Havale;12000,00"></textarea></div></form>';
        }
        $o .= '<div class="ey-tablo-wrap"><table class="ey-tablo"><thead><tr><th>Tarih</th><th>Banka</th><th>Referans</th><th>Açıklama</th><th class="ey-num">Tutar</th><th>Mutabakat</th></tr></thead><tbody>';
        foreach ($ekstreler as $e) {
            $es = (string)$e['eslesme_durum'];
            $o .= '<tr><td>' . h(erp_yon_tarih_tr((string)$e['tarih'])) . '</td><td>' . h((string)$e['hesap_kod']) . '</td><td class="ey-kod">' . h((string)$e['referans_no']) . '</td>'
                . '<td>' . h((string)$e['aciklama']) . '</td><td class="ey-num"><strong>' . h(erp_yon_fmt((float)$e['tutar'])) . '</strong></td>'
                . '<td>' . ($es === 'eslesti' ? '<span class="ey-rozet ey-rozet--ok">EŞLEŞTİ (' . h((string)$e['eslesen_modul']) . ' #' . (int)$e['eslesen_id'] . ')</span>' : '<span class="ey-rozet ey-rozet--warn">EŞLEŞMEDİ</span>') . '</td></tr>';
        }
        if ($ekstreler === []) $o .= '<tr><td colspan="6" style="text-align:center;color:var(--ey-muted);">Henüz banka ekstresi aktarılmamış.</td></tr>';
        $o .= '</tbody></table></div>';
    } elseif ($sekme === 'kredi') {
        $krediler = $veri['krediler'] ?? [];
        if ($yazabilir && $hesaplar !== []) {
            $o .= '<form method="post" action="erp_yonetim_kaydet.php" class="ey-filtre" style="padding:12px;background:var(--ey-bg-soft);border-radius:10px;border:1px solid var(--ey-border);">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="banka_kredi_olustur"><input type="hidden" name="geri" value="banka_yonetim.php?sekme=kredi">'
                . '<div class="ey-alan"><label>Hesap</label><select name="hesap_id" required>';
            foreach ($hesaplar as $h) $o .= '<option value="' . (int)$h['id'] . '">' . h((string)$h['kod'] . ' — ' . (string)$h['banka_ad']) . '</option>';
            $o .= '</select></div>'
                . '<div class="ey-alan"><label>Sözleşme No</label><input name="sozlesme_no" placeholder="KRD-2026-01"></div>'
                . '<div class="ey-alan"><label>Anapara</label><input name="anapara" inputmode="decimal" required placeholder="500000"></div>'
                . '<div class="ey-alan"><label>Aylık Faiz (%)</label><input name="aylik_faiz_yuzde" inputmode="decimal" value="3.50"></div>'
                . '<div class="ey-alan"><label>Taksit</label><input type="number" name="taksit_sayisi" min="1" max="120" value="12"></div>'
                . '<div class="ey-alan"><label>Başlangıç</label><input type="date" name="bas_tarih" value="' . h(date('Y-m-d')) . '"></div>'
                . '<button type="submit" class="btn btn-primary">Ödeme Planı Oluştur</button></form>';
        }
        foreach ($krediler as $kr) {
            $o .= '<div class="ey-kart" style="background:var(--ey-bg-soft);"><h3>' . h((string)$kr['sozlesme_no'] . ' — ' . (string)$kr['banka_ad'] . ' (' . erp_yon_fmt((float)$kr['anapara']) . ' ' . (string)$kr['para_birimi'] . ')') . '</h3>'
                . '<div class="ey-tablo-wrap"><table class="ey-tablo"><thead><tr><th>#</th><th>Vade</th><th class="ey-num">Anapara</th><th class="ey-num">Faiz+Vergi</th><th class="ey-num">Toplam Taksit</th><th class="ey-num">Kalan</th><th>Durum</th></tr></thead><tbody>';
            foreach (($kr['taksitler'] ?? []) as $tk) {
                $od = (string)$tk['durum'] === 'odendi';
                $o .= '<tr><td>' . (int)$tk['taksit_no'] . '</td><td>' . h(erp_yon_tarih_tr((string)$tk['vade_tarih'])) . '</td>'
                    . '<td class="ey-num">' . h(erp_yon_fmt((float)$tk['anapara_tutar'])) . '</td>'
                    . '<td class="ey-num">' . h(erp_yon_fmt((float)$tk['faiz_tutar'] + (float)$tk['vergi_tutar'])) . '</td>'
                    . '<td class="ey-num"><strong>' . h(erp_yon_fmt((float)$tk['toplam_taksit'])) . '</strong></td>'
                    . '<td class="ey-num">' . h(erp_yon_fmt((float)$tk['kalan_anapara'])) . '</td><td>';
                if ($od) {
                    $o .= '<span class="ey-rozet ey-rozet--ok">ÖDENDİ</span>';
                } elseif ($yazabilir) {
                    $o .= '<form method="post" action="erp_yonetim_kaydet.php" class="ey-inline-form">'
                        . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="banka_kredi_taksit_ode">'
                        . '<input type="hidden" name="id" value="' . (int)$tk['id'] . '"><input type="hidden" name="geri" value="banka_yonetim.php?sekme=kredi">'
                        . '<button type="submit" class="btn btn-primary" style="padding:4px 8px;font-size:.8rem;">Öde (300/780)</button></form>';
                }
                $o .= '</td></tr>';
            }
            $o .= '</tbody></table></div></div>';
        }
        if ($krediler === []) $o .= '<p style="text-align:center;color:var(--ey-muted);">Kayıtlı kredi sözleşmesi yok.</p>';
    } else {
        $poslar = $veri['poslar'] ?? [];
        if ($yazabilir && $hesaplar !== []) {
            $o .= '<form method="post" action="erp_yonetim_kaydet.php" class="ey-filtre" style="padding:12px;background:var(--ey-bg-soft);border-radius:10px;border:1px solid var(--ey-border);">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="banka_pos_kaydet"><input type="hidden" name="geri" value="banka_yonetim.php?sekme=pos">'
                . '<div class="ey-alan"><label>Hesap</label><select name="hesap_id" required>';
            foreach ($hesaplar as $h) $o .= '<option value="' . (int)$h['id'] . '">' . h((string)$h['kod'] . ' — ' . (string)$h['banka_ad']) . '</option>';
            $o .= '</select></div>'
                . '<div class="ey-alan"><label>İşlem Tarihi</label><input type="date" name="islem_tarih" value="' . h(date('Y-m-d')) . '"></div>'
                . '<div class="ey-alan"><label>Valör (Gün)</label><input type="number" name="valor_gun" value="1"></div>'
                . '<div class="ey-alan"><label>Brüt Tutar</label><input name="brut_tutar" inputmode="decimal" required placeholder="10000"></div>'
                . '<div class="ey-alan"><label>Komisyon (%)</label><input name="komisyon_yuzde" inputmode="decimal" value="2.50"></div>'
                . '<button type="submit" class="btn btn-primary">POS Hareketi Ekle</button></form>';
        }
        $o .= '<div class="ey-tablo-wrap"><table class="ey-tablo"><thead><tr><th>İşlem</th><th>Valör</th><th>Banka</th><th class="ey-num">Brüt</th><th class="ey-num">Komisyon</th><th class="ey-num">Net</th><th>Bloke</th></tr></thead><tbody>';
        foreach ($poslar as $p) {
            $coz = (string)$p['durum'] === 'cozuldu';
            $o .= '<tr><td>' . h(erp_yon_tarih_tr((string)$p['islem_tarih'])) . '</td><td>' . h(erp_yon_tarih_tr((string)$p['valor_tarih'])) . '</td>'
                . '<td>' . h((string)$p['hesap_kod']) . '</td><td class="ey-num">' . h(erp_yon_fmt((float)$p['brut_tutar'])) . '</td>'
                . '<td class="ey-num">' . h(erp_yon_fmt((float)$p['komisyon_tutar'])) . ' (%' . h(erp_yon_fmt((float)$p['komisyon_yuzde'], 2)) . ')</td>'
                . '<td class="ey-num"><strong>' . h(erp_yon_fmt((float)$p['net_tutar'])) . '</strong></td><td>';
            if ($coz) {
                $o .= '<span class="ey-rozet ey-rozet--ok">ÇÖZÜLDÜ</span>';
            } elseif ($yazabilir) {
                $o .= '<form method="post" action="erp_yonetim_kaydet.php" class="ey-inline-form">'
                    . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="banka_pos_bloke_coz">'
                    . '<input type="hidden" name="id" value="' . (int)$p['id'] . '"><input type="hidden" name="geri" value="banka_yonetim.php?sekme=pos">'
                    . '<button type="submit" class="btn btn-primary" style="padding:4px 8px;font-size:.8rem;">Bloke Çöz</button></form>';
            }
            $o .= '</td></tr>';
        }
        if ($poslar === []) $o .= '<tr><td colspan="7" style="text-align:center;color:var(--ey-muted);">POS hareketi yok.</td></tr>';
        $o .= '</tbody></table></div>';
    }
    $o .= '</div></div>';
    return $o;
}

// ── 3) ERP-7: İK Özlük, İzin & Resmi Bordro ────────────────────────────────

function ik_bordro_render(string $sekme, array $veri, bool $yazabilir): string
{
    $csrf = function_exists('csrf_token') ? csrf_token() : '';
    $ozlukler = $veri['ozlukler'] ?? [];
    $o = '<div class="ey-wrap">' . erp_yon_ust_menu('ik_bordro');
    $o .= '<div class="ey-kart"><div class="ey-eylem-satir" style="margin-bottom:14px;">'
        . '<a class="' . ($sekme === 'ozluk' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="ik_bordro.php?sekme=ozluk">Personel Özlük Kartları</a>'
        . '<a class="' . ($sekme === 'izin' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="ik_bordro.php?sekme=izin">Yıllık İzin &amp; Talepler</a>'
        . '<a class="' . ($sekme === 'bordro' ? 'btn btn-primary' : 'btn btn-ghost') . '" href="ik_bordro.php?sekme=bordro">Resmi Bordro &amp; Tahakkuk</a>'
        . '</div>';

    if ($sekme === 'ozluk') {
        if ($yazabilir) {
            $o .= '<form method="post" action="erp_yonetim_kaydet.php" class="ey-filtre" style="padding:12px;background:var(--ey-bg-soft);border-radius:10px;border:1px solid var(--ey-border);">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="ik_ozluk_kaydet"><input type="hidden" name="geri" value="ik_bordro.php?sekme=ozluk">'
                . '<div class="ey-alan"><label>Sicil No</label><input name="sicil_no" placeholder="PRS-001"></div>'
                . '<div class="ey-alan" style="flex:1;"><label>Ad Soyad</label><input name="ad_soyad" required placeholder="Ad Soyad"></div>'
                . '<div class="ey-alan"><label>Departman</label><input name="departman" placeholder="İhracat / Üretim"></div>'
                . '<div class="ey-alan"><label>Ünvan</label><input name="unvan" placeholder="Uzman"></div>'
                . '<div class="ey-alan"><label>İşe Giriş</label><input type="date" name="ise_giris_tarih" value="' . h(date('Y-m-d')) . '" required></div>'
                . '<div class="ey-alan"><label>Brüt Maaş (TL)</label><input name="brut_maas" inputmode="decimal" required placeholder="45000"></div>'
                . '<div class="ey-alan"><label>IBAN</label><input name="iban" placeholder="TR..."></div>'
                . '<button type="submit" class="btn btn-primary">Özlük Kaydet</button></form>';
        }
        $o .= '<div class="ey-tablo-wrap"><table class="ey-tablo"><thead><tr><th>Sicil</th><th>Ad Soyad</th><th>Departman / Ünvan</th><th>İşe Giriş</th><th class="ey-num">Kıdem</th><th class="ey-num">Kalan İzin</th><th class="ey-num">Brüt Maaş</th></tr></thead><tbody>';
        foreach ($ozlukler as $oz) {
            $o .= '<tr><td class="ey-kod">' . h((string)$oz['sicil_no']) . '</td><td><strong>' . h((string)$oz['ad_soyad']) . '</strong></td>'
                . '<td>' . h(trim((string)$oz['departman'] . ' · ' . (string)$oz['unvan'], ' ·')) . '</td>'
                . '<td>' . h(erp_yon_tarih_tr((string)$oz['ise_giris_tarih'])) . '</td>'
                . '<td class="ey-num">' . (int)($oz['kidem_yil'] ?? 0) . ' yıl</td>'
                . '<td class="ey-num"><strong>' . (int)($oz['izin_kalan'] ?? 0) . ' gün</strong></td>'
                . '<td class="ey-num">' . h(erp_yon_fmt((float)$oz['brut_maas'])) . ' TL</td></tr>';
        }
        if ($ozlukler === []) $o .= '<tr><td colspan="7" style="text-align:center;color:var(--ey-muted);">Kayıtlı özlük kartı yok.</td></tr>';
        $o .= '</tbody></table></div>';
    } elseif ($sekme === 'izin') {
        $izinler = $veri['izinler'] ?? [];
        if ($yazabilir && $ozlukler !== []) {
            $o .= '<form method="post" action="erp_yonetim_kaydet.php" class="ey-filtre" style="padding:12px;background:var(--ey-bg-soft);border-radius:10px;border:1px solid var(--ey-border);">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="ik_izin_talep_et"><input type="hidden" name="geri" value="ik_bordro.php?sekme=izin">'
                . '<div class="ey-alan" style="min-width:200px;"><label>Personel</label><select name="ozluk_id" required>';
            foreach ($ozlukler as $oz) $o .= '<option value="' . (int)$oz['id'] . '">' . h((string)$oz['sicil_no'] . ' — ' . (string)$oz['ad_soyad']) . '</option>';
            $o .= '</select></div>'
                . '<div class="ey-alan"><label>Tür</label><select name="tur"><option value="yillik">Yıllık İzin</option><option value="mazeret">Mazeret</option><option value="rapor">Rapor</option><option value="ucretsiz">Ücretsiz</option></select></div>'
                . '<div class="ey-alan"><label>Başlangıç</label><input type="date" name="bas_tarih" value="' . h(date('Y-m-d')) . '" required></div>'
                . '<div class="ey-alan"><label>Bitiş</label><input type="date" name="bit_tarih" value="' . h(date('Y-m-d', strtotime('+2 days'))) . '" required></div>'
                . '<div class="ey-alan" style="flex:1;"><label>Açıklama</label><input name="aciklama" placeholder="Not"></div>'
                . '<button type="submit" class="btn btn-primary">İzin Talebi Aç</button></form>';
        }
        $o .= '<div class="ey-tablo-wrap"><table class="ey-tablo"><thead><tr><th>Personel</th><th>Tür</th><th>Tarih</th><th class="ey-num">Gün</th><th>Durum</th><th>İşlem</th></tr></thead><tbody>';
        foreach ($izinler as $iz) {
            $d = (string)$iz['durum'];
            $roz = $d === 'onaylandi' ? 'ey-rozet--ok' : ($d === 'reddedildi' ? 'ey-rozet--err' : 'ey-rozet--warn');
            $o .= '<tr><td><strong>' . h((string)$iz['ad_soyad']) . '</strong> (' . h((string)$iz['sicil_no']) . ')</td><td>' . h(strtoupper((string)$iz['tur'])) . '</td>'
                . '<td>' . h(erp_yon_tarih_tr((string)$iz['bas_tarih']) . ' — ' . erp_yon_tarih_tr((string)$iz['bit_tarih'])) . '</td><td class="ey-num">' . (int)$iz['gun_sayisi'] . '</td>'
                . '<td><span class="ey-rozet ' . $roz . '">' . h(strtoupper($d)) . '</span></td><td>';
            if ($yazabilir && $d === 'bekliyor') {
                $o .= '<div class="ey-eylem-satir">'
                    . '<form method="post" action="erp_yonetim_kaydet.php" class="ey-inline-form"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="ik_izin_karar"><input type="hidden" name="id" value="' . (int)$iz['id'] . '"><input type="hidden" name="karar" value="onaylandi"><input type="hidden" name="geri" value="ik_bordro.php?sekme=izin"><button type="submit" class="btn btn-primary" style="padding:4px 8px;font-size:.8rem;">Onayla</button></form>'
                    . '<form method="post" action="erp_yonetim_kaydet.php" class="ey-inline-form"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="ik_izin_karar"><input type="hidden" name="id" value="' . (int)$iz['id'] . '"><input type="hidden" name="karar" value="reddedildi"><input type="hidden" name="geri" value="ik_bordro.php?sekme=izin"><button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:.8rem;">Reddet</button></form></div>';
            }
            $o .= '</td></tr>';
        }
        if ($izinler === []) $o .= '<tr><td colspan="6" style="text-align:center;color:var(--ey-muted);">İzin talebi yok.</td></tr>';
        $o .= '</tbody></table></div>';
    } else {
        $donemler = $veri['donemler'] ?? [];
        $secSatirlar = $veri['sec_satirlar'] ?? [];
        if ($yazabilir) {
            $o .= '<form method="post" action="erp_yonetim_kaydet.php" class="ey-filtre" style="padding:12px;background:var(--ey-bg-soft);border-radius:10px;border:1px solid var(--ey-border);">'
                . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="ik_bordro_donem_uret"><input type="hidden" name="geri" value="ik_bordro.php?sekme=bordro">'
                . '<div class="ey-alan"><label>Yıl</label><input type="number" name="yil" value="' . (int)date('Y') . '" min="2000" max="2099"></div>'
                . '<div class="ey-alan"><label>Ay</label><input type="number" name="ay" value="' . (int)date('n') . '" min="1" max="12"></div>'
                . '<button type="submit" class="btn btn-primary">Resmi Bordro Üret (SGK / GV / DV)</button></form>';
        }
        $o .= '<div class="ey-tablo-wrap"><table class="ey-tablo"><thead><tr><th>Dönem</th><th class="ey-num">Toplam Brüt</th><th class="ey-num">SGK İşçi+İşveren</th><th class="ey-num">GV + DV</th><th class="ey-num">Net Ödenecek</th><th class="ey-num">İşveren Maliyeti</th><th>Muhasebe (770/335)</th></tr></thead><tbody>';
        foreach ($donemler as $d) {
            $o .= '<tr><td class="ey-kod"><a href="ik_bordro.php?sekme=bordro&amp;donem_id=' . (int)$d['id'] . '">' . h((string)$d['donem_kodu']) . '</a></td>'
                . '<td class="ey-num">' . h(erp_yon_fmt((float)$d['toplam_brut'])) . '</td>'
                . '<td class="ey-num">' . h(erp_yon_fmt((float)$d['toplam_sgk_isci'] + (float)$d['toplam_sgk_isveren'])) . '</td>'
                . '<td class="ey-num">' . h(erp_yon_fmt((float)$d['toplam_gv'] + (float)$d['toplam_dv'])) . '</td>'
                . '<td class="ey-num"><strong>' . h(erp_yon_fmt((float)$d['toplam_net'])) . '</strong></td>'
                . '<td class="ey-num">' . h(erp_yon_fmt((float)$d['toplam_maliyet'])) . '</td><td>';
            if (!empty($d['muh_fis_id'])) {
                $o .= '<a class="ey-rozet ey-rozet--ok" href="muh_yevmiye.php?fis_id=' . (int)$d['muh_fis_id'] . '">Fiş #' . (int)$d['muh_fis_id'] . '</a>';
            } elseif ($yazabilir) {
                $o .= '<form method="post" action="erp_yonetim_kaydet.php" class="ey-inline-form"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="islem" value="ik_bordro_muhasebelestir"><input type="hidden" name="id" value="' . (int)$d['id'] . '"><input type="hidden" name="geri" value="ik_bordro.php?sekme=bordro"><button type="submit" class="btn btn-primary" style="padding:4px 8px;font-size:.8rem;">Yevmiyeye Aktar</button></form>';
            }
            $o .= '</td></tr>';
        }
        if ($donemler === []) $o .= '<tr><td colspan="7" style="text-align:center;color:var(--ey-muted);">Henüz bordro dönemi üretilmedi.</td></tr>';
        $o .= '</tbody></table></div>';

        if ($secSatirlar !== []) {
            $o .= '<h3 style="margin-top:16px;">Seçili Dönem Bordro &amp; Banka Ödeme Listesi</h3><div class="ey-tablo-wrap"><table class="ey-tablo"><thead><tr>'
                . '<th>Sicil / Personel</th><th>IBAN</th><th class="ey-num">Brüt</th><th class="ey-num">SGK+İşs.</th><th class="ey-num">GV</th><th class="ey-num">DV</th><th class="ey-num">Net Ödenecek</th><th class="ey-num">İşv. Maliyet</th></tr></thead><tbody>';
            foreach ($secSatirlar as $s) {
                $o .= '<tr><td><strong>' . h((string)$s['ad_soyad']) . '</strong> (' . h((string)$s['sicil_no']) . ')</td><td class="ey-kod">' . h((string)$s['iban']) . '</td>'
                    . '<td class="ey-num">' . h(erp_yon_fmt((float)$s['brut_ucret'])) . '</td>'
                    . '<td class="ey-num">' . h(erp_yon_fmt((float)$s['sgk_isci'] + (float)$s['issizlik_isci'])) . '</td>'
                    . '<td class="ey-num">' . h(erp_yon_fmt((float)$s['gelir_vergisi'])) . '</td>'
                    . '<td class="ey-num">' . h(erp_yon_fmt((float)$s['damga_vergisi'])) . '</td>'
                    . '<td class="ey-num"><strong>' . h(erp_yon_fmt((float)$s['net_ucret'])) . '</strong></td>'
                    . '<td class="ey-num">' . h(erp_yon_fmt((float)$s['isveren_maliyeti'])) . '</td></tr>';
            }
            $o .= '</tbody></table></div>';
        }
    }
    $o .= '</div></div>';
    return $o;
}
