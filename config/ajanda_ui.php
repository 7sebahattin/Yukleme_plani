<?php
declare(strict_types=1);
// =========================================================
// config/ajanda_ui.php — Ajanda ay takvimi görünümü (v576). Yalnız HTML üretir; veri config/ajanda_core.php'den gelir.
//
// Kalıplar (Google/Outlook/Apple ay görünümü): Pazartesi başlangıç · bugün halkası · seçili gün çerçevesi · ay dışı günler soluk ·
// hafta sonu hafif zemin · gün hücresinde en çok 2 kayıt çipi + "+N daha" · gecikmiş/bugün/tamamlandı renk kodu ·
// üzerine gelince (ya da klavye odağında) kayıt içeriklerini gösteren baloncuk · mobilde çip yerine nokta + sayı.
//
// DEĞİŞMEZLER: her kullanıcı metni h() ile kaçışlanır; gün hücresi düz bağlantıdır (JS'siz de çalışır: ?ay=&gun= → sunucu seçili günü çizer);
// baloncuk saf CSS (:hover / :focus-visible), dokunmatikte (hover:none) gizlenir — orada gün bağlantısı alttaki gün panelini açar.
// =========================================================

/** Kayıt durum sınıfı: tamam | gecikmis | bugun | yaklasan. */
function ajanda_durum_sinifi(array $r, string $bugun): string
{
    if ((int)($r['tamam'] ?? 0) === 1) return 'tamam';
    $t = (string)$r['tarih'];
    return $t < $bugun ? 'gecikmis' : ($t === $bugun ? 'bugun' : 'yaklasan');
}

/** Takvim bağlantısı (sayfa içi). $ek: ['gun'=>…, 'gorunum'=>…] */
function ajanda_url(string $ay, ?string $gun = null, string $gorunum = 'acik', bool $capa = false): string
{
    $q = ['ay' => $ay];
    if ($gun !== null) $q['gun'] = $gun;
    if ($gorunum !== 'acik') $q['gorunum'] = $gorunum;
    return 'ajanda.php?' . http_build_query($q) . ($capa ? '#ka-secili' : '');
}

/** Tek gün hücresi. */
function ajanda_gun_html(string $gun, int $sutun, int $satir, int $satirSayisi, array $tk, array $kayitlar, string $bugun, ?string $secili, string $gorunum): string
{
    $ayNo = substr($tk['ay'], 5, 2);
    $disari = substr($gun, 5, 2) !== $ayNo;
    $n = count($kayitlar);
    $sin = ['ka-gun'];
    if ($disari) $sin[] = 'disari';
    if ($sutun >= 5) $sin[] = 'hsonu';
    if ($gun === $bugun) $sin[] = 'bugun';
    if ($gun === $secili) $sin[] = 'secili';
    if ($n > 0) $sin[] = 'dolu';
    $etiket = ajanda_tarih_etiket($gun);
    $id = 'kab-' . $gun;
    $baloncuk = $sutun >= 5 ? ' sag' : '';
    $baloncuk .= ($satirSayisi >= 5 && $satir >= 3) ? ' yukari' : '';

    $o = '<a class="' . implode(' ', $sin) . '" href="' . h(ajanda_url($tk['ay'], $gun, $gorunum, true)) . '"'
       . ' aria-label="' . h($etiket . ($n > 0 ? ', ' . $n . ' kayıt' : ', kayıt yok')) . '"'
       . ($gun === $bugun ? ' aria-current="date"' : '')
       . ($n > 0 ? ' aria-describedby="' . h($id) . '"' : '') . '>';
    $o .= '<span class="ka-gun-ust"><span class="ka-gun-no">' . (int)substr($gun, 8, 2) . '</span>'
        . ($n > 0 ? '<span class="ka-gun-say" aria-hidden="true">' . $n . '</span>' : '') . '</span>';

    if ($n > 0) {
        // Masaüstü: en çok 2 çip + "+N daha"; mobil: aynı kayıtlar nokta olarak
        $o .= '<span class="ka-cipler" aria-hidden="true">';
        foreach (array_slice($kayitlar, 0, 2) as $r) {
            $saat = (string)($r['saat'] ?? '');
            $o .= '<span class="ka-cip ' . ajanda_durum_sinifi($r, $bugun) . '">' . ($saat !== '' ? '<i>' . h($saat) . '</i> ' : '') . h((string)$r['baslik']) . '</span>';
        }
        if ($n > 2) $o .= '<span class="ka-cip-daha">+' . ($n - 2) . ' daha</span>';
        $o .= '</span><span class="ka-noktalar" aria-hidden="true">';
        foreach (array_slice($kayitlar, 0, 4) as $r) $o .= '<b class="' . ajanda_durum_sinifi($r, $bugun) . '"></b>';
        $o .= '</span>';

        // Baloncuk (saf CSS): tarih + her kayıt (saat · başlık · not)
        $o .= '<span class="ka-balon' . $baloncuk . '" id="' . h($id) . '" role="tooltip"><span class="ka-balon-baslik">' . h($etiket) . '</span>';
        foreach (array_slice($kayitlar, 0, 6) as $r) {
            $saat = (string)($r['saat'] ?? '');
            $not = trim((string)($r['not_metni'] ?? ''));
            if (mb_strlen($not, 'UTF-8') > 160) $not = mb_substr($not, 0, 160, 'UTF-8') . '…';
            $o .= '<span class="ka-balon-oge ' . ajanda_durum_sinifi($r, $bugun) . '"><span class="ka-balon-sat">'
                . ($saat !== '' ? '<b>' . h($saat) . '</b> ' : '') . h((string)$r['baslik']) . '</span>'
                . ($not !== '' ? '<span class="ka-balon-not">' . h($not) . '</span>' : '') . '</span>';
        }
        if ($n > 6) $o .= '<span class="ka-balon-daha">+' . ($n - 6) . ' kayıt daha</span>';
        $o .= '</span>';
    }
    return $o . '</a>';
}

/**
 * Takvim kartı: araç çubuğu (önceki · ay başlığı · sonraki · Bugün · özet) + ızgara + gösterge.
 * @param array<string, list<array<string,mixed>>> $gunluk tarih → kayıtlar
 * @param array{gecikmis:int,bugun:int,hafta:int} $ozet
 */
function ajanda_takvim_html(array $tk, array $gunluk, string $bugun, ?string $secili, string $gorunum, array $ozet): string
{
    $bugunAy = substr($bugun, 0, 7);
    $o = '<section class="card ka-takvim-kart" aria-label="Takvim">';
    $o .= '<div class="ka-takvim-arac">';
    $o .= '<div class="ka-takvim-gezin">'
        . '<a class="ka-gezin-dugme" href="' . h(ajanda_url($tk['onceki'], null, $gorunum)) . '" aria-label="Önceki ay" title="Önceki ay">‹</a>'
        . '<h2 class="ka-ay-baslik" aria-live="polite">' . h($tk['baslik']) . '</h2>'
        . '<a class="ka-gezin-dugme" href="' . h(ajanda_url($tk['sonraki'], null, $gorunum)) . '" aria-label="Sonraki ay" title="Sonraki ay">›</a>'
        . ($tk['ay'] !== $bugunAy || $secili !== null ? '<a class="btn btn-ghost btn-sm" href="' . h(ajanda_url($bugunAy, $bugun, $gorunum, true)) . '">Bugün</a>' : '')
        . '</div>';
    $o .= '<div class="ka-ozet-serit" aria-label="Özet">'
        . '<span class="ka-ozet-cip gecikmis" title="Tarihi geçmiş, tamamlanmamış"><b>' . (int)$ozet['gecikmis'] . '</b> Gecikmiş</span>'
        . '<span class="ka-ozet-cip bugun"><b>' . (int)$ozet['bugun'] . '</b> Bugün</span>'
        . '<span class="ka-ozet-cip"><b>' . (int)$ozet['hafta'] . '</b> Önümüzdeki 7 gün</span></div>';
    $o .= '</div>';

    $o .= '<div class="ka-takvim" role="group" aria-label="' . h($tk['baslik']) . ' takvimi"><div class="ka-hafta-satir ka-hafta-baslik" aria-hidden="true">';
    foreach (AJ_GUN_KISA as $i => $g) $o .= '<span class="' . ($i >= 5 ? 'hsonu' : '') . '">' . h($g) . '</span>';
    $o .= '</div>';
    $satirSayisi = count($tk['haftalar']);
    foreach ($tk['haftalar'] as $s => $hafta) {
        $o .= '<div class="ka-hafta-satir">';
        foreach ($hafta as $c => $gun) $o .= ajanda_gun_html($gun, $c, $s, $satirSayisi, $tk, $gunluk[$gun] ?? [], $bugun, $secili, $gorunum);
        $o .= '</div>';
    }
    $o .= '</div>';

    $o .= '<div class="ka-gosterge" aria-hidden="true">'
        . '<span><i class="gecikmis"></i> Gecikmiş</span><span><i class="bugun"></i> Bugün</span>'
        . '<span><i class="yaklasan"></i> Yaklaşan</span><span><i class="tamam"></i> Tamamlandı</span>'
        . '<span class="ka-gosterge-ipucu">Güne gelince kayıtlar görünür · tıklayınca o günün paneli açılır</span></div>';
    return $o . '</section>';
}
